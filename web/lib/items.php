<?php
declare(strict_types=1);
/* Components: validation, search, create/update/delete with audit log (FSD §5, §6, §7.2). */

const ITEM_FIELDS = [
    // name => [label, max length, required]
    'box' => ['Box', 8, true],
    'bin' => ['Bin', 8, true],
    'label1' => ['Label row 1', 60, true],
    'label2' => ['Label row 2', 60, false],
    'label3' => ['Label row 3', 60, false],
    'keywords' => ['Keywords', 255, false],
    'comments' => ['Comments', 2000, false],
];

/** Trim, collapse whitespace on single-line fields, strip control characters. */
function clean_item(array $in): array
{
    $out = [];
    foreach (array_keys(ITEM_FIELDS) as $name) {
        $v = is_string($in[$name] ?? null) ? $in[$name] : '';
        if ($name === 'comments') {
            $v = preg_replace('/[^\P{C}\n\t]/u', '', str_replace("\r\n", "\n", $v)) ?? '';
            $v = trim($v);
        } else {
            $v = preg_replace('/\p{C}/u', '', $v) ?? '';
            $v = trim(preg_replace('/\s+/u', ' ', $v) ?? '');
        }
        $out[$name] = $v;
    }
    return $out;
}

/** Returns a list of error messages (empty = valid). */
function validate_item(array $item): array
{
    $errors = [];
    foreach (ITEM_FIELDS as $name => [$label, $max, $required]) {
        if ($required && $item[$name] === '') {
            $errors[$name] = "$label is required.";
        } elseif (mb_strlen($item[$name]) > $max) {
            $errors[$name] = "$label can be at most $max characters.";
        }
    }
    return $errors;
}

function get_item(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM inv_items WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function audit(string $action, ?int $itemId, ?array $old, ?array $new): void
{
    $enc = fn (?array $a) => $a === null ? null : json_encode($a, JSON_UNESCAPED_UNICODE);
    db()->prepare('INSERT INTO inv_audit (at, action, item_id, old_json, new_json) VALUES (?, ?, ?, ?, ?)')
        ->execute([now(), $action, $itemId, $enc($old), $enc($new)]);
}

function create_item(array $item): int
{
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO inv_items (box, bin, label1, label2, label3, keywords, comments, created_at, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$item['box'], $item['bin'], $item['label1'], $item['label2'], $item['label3'],
                   $item['keywords'], $item['comments'], now(), now()]);
    $id = (int) $pdo->lastInsertId();
    audit('create', $id, null, $item);
    $pdo->commit();
    remember_box_bin($item);
    return $id;
}

function update_item(int $id, array $item): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    $old = get_item($id);
    if ($old === null) {
        $pdo->rollBack();
        return false;
    }
    $pdo->prepare('UPDATE inv_items SET box = ?, bin = ?, label1 = ?, label2 = ?, label3 = ?, keywords = ?,
                   comments = ?, updated_at = ? WHERE id = ?')
        ->execute([$item['box'], $item['bin'], $item['label1'], $item['label2'], $item['label3'],
                   $item['keywords'], $item['comments'], now(), $id]);
    audit('update', $id, array_intersect_key($old, ITEM_FIELDS), $item);
    $pdo->commit();
    remember_box_bin($item);
    return true;
}

function delete_item(int $id): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    $old = get_item($id);
    if ($old === null) {
        $pdo->rollBack();
        return false;
    }
    $pdo->prepare('DELETE FROM inv_items WHERE id = ?')->execute([$id]);
    audit('delete', $id, $old, null);
    $pdo->commit();
    return true;
}

/** Undo a delete from the audit log: recreate the item with its old id if still free. */
function restore_deleted(int $auditId): ?int
{
    $st = db()->prepare("SELECT * FROM inv_audit WHERE id = ? AND action = 'delete'");
    $st->execute([$auditId]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    $old = json_decode((string) $row['old_json'], true);
    if (!is_array($old) || get_item((int) $row['item_id']) !== null) {
        return null;
    }
    $item = clean_item($old);
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO inv_items (id, box, bin, label1, label2, label3, keywords, comments, created_at, updated_at, last_printed_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([(int) $row['item_id'], $item['box'], $item['bin'], $item['label1'], $item['label2'], $item['label3'],
                   $item['keywords'], $item['comments'], $old['created_at'] ?? now(), now(), $old['last_printed_at'] ?? null]);
    audit('restore', (int) $row['item_id'], null, $item);
    $pdo->commit();
    return (int) $row['item_id'];
}

function remember_box_bin(array $item): void
{
    save_setting('last_box', $item['box']);
    save_setting('last_bin', $item['bin']);
}

/**
 * Search (FSD §6.1). Every word must appear somewhere in the item (case- and
 * accent-insensitive through the utf8mb4_unicode_ci collation). A query like "03/02"
 * or "3-2" means box 03, bin 02; a single number also matches the box exactly and
 * those items come first. Returns [rows, total].
 */
function search_items(string $q, int $page = 1, int $perPage = PAGE_SIZE): array
{
    $q = trim(preg_replace('/\s+/u', ' ', $q) ?? '');
    $where = [];
    $params = [];
    $order = 'box + 0, box, bin + 0, bin, label1';
    $noZeros = fn (string $col) => "TRIM(LEADING '0' FROM $col)";

    if (preg_match('/^(\w{1,8})\s*[\/\-]\s*(\w{1,8})$/u', $q, $m)) {
        $where[] = $noZeros('box') . ' = ' . $noZeros('?') . ' AND ' . $noZeros('bin') . ' = ' . $noZeros('?');
        $params = [$m[1], $m[2]];
    } elseif ($q !== '') {
        $haystack = "CONCAT_WS(' ', box, bin, label1, label2, label3, keywords, comments)";
        $words = array_slice(explode(' ', $q), 0, 8);
        $wordConds = [];
        foreach ($words as $w) {
            $wordConds[] = "$haystack LIKE ?";
            $params[] = '%' . addcslashes($w, '%_\\') . '%';
        }
        $cond = implode(' AND ', $wordConds);
        if (preg_match('/^\d{1,8}$/', $q)) {
            $cond = '(' . $cond . ' OR ' . $noZeros('box') . ' = ' . $noZeros('?') . ')';
            $params[] = $q;
            $order = '(' . $noZeros('box') . ' = ' . $noZeros('?') . ') DESC, ' . $order;
        }
        $where[] = $cond;
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $orderParams = str_contains($order, '?') ? [$q] : [];

    $st = db()->prepare("SELECT COUNT(*) FROM inv_items $whereSql");
    $st->execute($params);
    $total = (int) $st->fetchColumn();

    $page = max(1, $page);
    $offset = ($page - 1) * $perPage;
    $st = db()->prepare("SELECT id, box, bin, label1, label2, label3, keywords, comments, updated_at, last_printed_at
                         FROM inv_items $whereSql ORDER BY $order LIMIT $perPage OFFSET $offset");
    $st->execute(array_merge($params, $orderParams));
    return [$st->fetchAll(), $total];
}

/** Public view of an item: everything is public (Q1), but no internal timestamps. */
function public_item(array $r): array
{
    return [
        'box' => $r['box'], 'bin' => $r['bin'],
        'label1' => $r['label1'], 'label2' => $r['label2'], 'label3' => $r['label3'],
        'keywords' => $r['keywords'], 'comments' => $r['comments'],
    ];
}
