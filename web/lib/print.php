<?php
declare(strict_types=1);
/*
 * Print queue (FSD §8.3). Each queued label stores a snapshot of its text and the label
 * layout, so editing an item after queueing can't change what gets printed.
 * The print agent on the Pi claims and prints jobs (api/agent.php).
 */

const AGENT_ONLINE_SECONDS = 30;
const JOB_TIMEOUT_SECONDS = 120;

function label_layout(): array
{
    $s = settings();
    $keys = ['font_box', 'font_bin', 'font_label1', 'font_label2', 'font_label3', 'margin_left_mm',
             'margin_right_mm', 'margin_top_mm', 'margin_bottom_mm', 'rotate', 'boxbin_side'];
    $out = [];
    foreach ($keys as $k) {
        $out[$k] = in_array($k, ['boxbin_side'], true) ? $s[$k] : (float) $s[$k];
    }
    $out['rotate'] = (int) $out['rotate'];
    return $out;
}

/**
 * Queue one label per item id. Fail-fast like the macro (FSD §7.3): if any item is
 * missing or invalid, nothing is queued. Returns [count, error-or-null].
 */
function queue_labels(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($i) => $i > 0)));
    if (!$ids) {
        return [0, 'No labels selected.'];
    }
    if (count($ids) > 100) {
        return [0, 'At most 100 labels at a time.'];
    }
    $items = [];
    $problems = [];
    foreach ($ids as $id) {
        $item = get_item($id);
        if ($item === null) {
            $problems[] = "item $id no longer exists";
            continue;
        }
        $missing = [];
        foreach (['box' => 'Box', 'bin' => 'Bin', 'label1' => 'Label row 1'] as $f => $label) {
            if (trim($item[$f]) === '') {
                $missing[] = $label;
            }
        }
        if ($missing) {
            $problems[] = $item['box'] . '/' . $item['bin'] . ' ' . $item['label1'] . ': missing ' . implode(', ', $missing);
        }
        $items[] = $item;
    }
    if ($problems) {
        return [0, 'Nothing was printed. ' . implode('; ', $problems) . '.'];
    }
    $layout = label_layout();
    $batch = bin2hex(random_bytes(8));
    $pdo = db();
    $pdo->beginTransaction();
    $st = $pdo->prepare("INSERT INTO inv_print_jobs (batch_id, item_id, payload, status, created_at) VALUES (?, ?, ?, 'queued', ?)");
    foreach ($items as $item) {
        $payload = [
            'box' => $item['box'], 'bin' => $item['bin'],
            'label1' => $item['label1'], 'label2' => $item['label2'], 'label3' => $item['label3'],
            'layout' => $layout,
        ];
        $st->execute([$batch, (int) $item['id'], json_encode($payload, JSON_UNESCAPED_UNICODE), now()]);
    }
    $pdo->commit();
    return [count($items), null];
}

/** Mark jobs that were claimed but never reported as failed (no automatic retry: no double labels). */
function expire_stuck_jobs(): void
{
    db()->prepare("UPDATE inv_print_jobs SET status = 'failed', error = 'agent timeout', finished_at = ?
                   WHERE status = 'printing' AND claimed_at < NOW() - INTERVAL " . JOB_TIMEOUT_SECONDS . ' SECOND')
        ->execute([now()]);
}

/** ['state' => online|offline|never, 'last_seen' => ..., 'printer' => ..., 'queued' => n] */
function agent_status(): array
{
    $row = db()->query('SELECT last_seen, printer_state, agent_version FROM inv_agent WHERE id = 1')->fetch();
    $queued = (int) db()->query("SELECT COUNT(*) FROM inv_print_jobs WHERE status IN ('queued', 'printing')")->fetchColumn();
    if (!$row || $row['last_seen'] === null) {
        return ['state' => 'never', 'last_seen' => null, 'printer' => '', 'queued' => $queued];
    }
    $online = strtotime($row['last_seen']) >= time() - AGENT_ONLINE_SECONDS;
    return ['state' => $online ? 'online' : 'offline', 'last_seen' => $row['last_seen'],
            'printer' => $row['printer_state'], 'queued' => $queued];
}

function queued_message(int $n): string
{
    $a = agent_status();
    $msg = "Sent $n label" . ($n === 1 ? '' : 's') . ' to the printer.';
    if ($a['state'] !== 'online') {
        $msg .= ' The printer is offline' . ($a['last_seen'] ? ' (last seen ' . $a['last_seen'] . ')' : '')
            . ': the label' . ($n === 1 ? '' : 's') . ' will print when it is back.';
    }
    return $msg;
}
