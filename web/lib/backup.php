<?php
declare(strict_types=1);
/*
 * Backups (FSD §10): a backup set is inventory_YYYY-MM-DD_HHMM.ods (human readable, same
 * columns as the old spreadsheet + Config sheet), .json (exact restore in the app) and .sql
 * (MariaDB dump of the inv_* tables, restorable with the mysql client). Sets are kept
 * in _backups/ (blocked by .htaccess) with retention: the 10 newest sets, plus the first
 * set of every calendar month forever.
 */

const BACKUP_SCHEMA = 1;
const BACKUP_KEEP_RECENT = 10;
const BACKUP_TABLES = ['inv_items', 'inv_settings', 'inv_audit', 'inv_print_jobs'];
const BACKUP_EXTS = ['ods', 'json', 'sql'];
const ODS_COLUMNS = ['box' => 'Box', 'bin' => 'Bin', 'label1' => 'Label row 1', 'label2' => 'Label row 2',
                     'label3' => 'Label row 3', 'keywords' => 'Keywords', 'comments' => 'Comments'];

function backup_dir(): string
{
    return dirname(__DIR__) . '/_backups';
}

/** Full data export as an array (everything except credentials, sessions and rate data). */
function backup_data(): array
{
    $data = ['schema' => BACKUP_SCHEMA, 'app_version' => APP_VERSION, 'created_at' => date('c'), 'tables' => []];
    foreach (BACKUP_TABLES as $t) {
        $data['tables'][$t] = db()->query("SELECT * FROM $t ORDER BY 1")->fetchAll();
    }
    return $data;
}

function ods_cell(string $v): string
{
    if ($v === '') {
        return '<table:table-cell/>';
    }
    $paras = array_map(fn ($line) => '<text:p>' . htmlspecialchars($line, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</text:p>',
                       explode("\n", $v));
    return '<table:table-cell office:value-type="string" calcext:value-type="string">' . implode('', $paras) . '</table:table-cell>';
}

function ods_sheet(string $name, array $header, array $rows): string
{
    $x = '<table:table table:name="' . htmlspecialchars($name, ENT_XML1) . '">'
        . '<table:table-column table:number-columns-repeated="' . count($header) . '"/>'
        . '<table:table-row>' . implode('', array_map('ods_cell', $header)) . '</table:table-row>';
    foreach ($rows as $r) {
        $x .= '<table:table-row>' . implode('', array_map(fn ($v) => ods_cell((string) $v), $r)) . '</table:table-row>';
    }
    return $x . '</table:table>';
}

/** Write an .ods with an Inventory sheet (old spreadsheet columns) and a Config sheet. */
function write_ods(string $path, array $data): void
{
    $items = array_map(fn ($it) => array_map(fn ($k) => $it[$k] ?? '', array_keys(ODS_COLUMNS)), $data['tables']['inv_items']);
    $settings = array_map(fn ($s) => [$s['name'], $s['value']], $data['tables']['inv_settings']);
    $content = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
        . ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
        . ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
        . ' xmlns:calcext="urn:org:documentfoundation:names:experimental:calc:xmlns:calcext:1.0"'
        . ' office:version="1.3"><office:body><office:spreadsheet>'
        . ods_sheet('Inventory', array_values(ODS_COLUMNS), $items)
        . ods_sheet('Config', ['Setting', 'Value'], $settings)
        . '</office:spreadsheet></office:body></office:document-content>';
    $manifest = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.3">'
        . '<manifest:file-entry manifest:full-path="/" manifest:media-type="application/vnd.oasis.opendocument.spreadsheet"/>'
        . '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>'
        . '</manifest:manifest>';
    $tmp = $path . '.tmp';
    $z = new ZipArchive();
    if ($z->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('cannot create ' . basename($path));
    }
    // ODS rule: "mimetype" first and uncompressed.
    $z->addFromString('mimetype', 'application/vnd.oasis.opendocument.spreadsheet');
    $z->setCompressionName('mimetype', ZipArchive::CM_STORE);
    $z->addFromString('content.xml', $content);
    $z->addFromString('META-INF/manifest.xml', $manifest);
    if (!$z->close()) {
        throw new RuntimeException('cannot write ' . basename($path));
    }
    if (!rename($tmp, $path)) {
        throw new RuntimeException('cannot write ' . basename($path));
    }
}

function sql_value(mixed $v): string
{
    return $v === null ? 'NULL' : db()->quote((string) $v);
}

/**
 * MariaDB dump of all inv_* tables (FSD §10.1). BACKUP_TABLES get DROP + CREATE + data. The
 * other inv_* tables (admin password, sessions, agent token, rate data) only get
 * CREATE TABLE IF NOT EXISTS: no credentials in backups, and loading the dump into an existing
 * installation keeps its password and agent token. Never touches verba's tables.
 */
function write_sql(string $path, array $data): void
{
    $pdo = db();
    $out = "-- Inventory management database backup\n"
        . '-- created ' . $data['created_at'] . ', app ' . APP_VERSION . "\n"
        . "-- Restore: mysql --default-character-set=utf8mb4 <database> < this-file.sql\n"
        . "-- Data of inv_admin, inv_sessions, inv_login_attempts, inv_rate and inv_agent is not included.\n\n"
        . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
    $tables = $pdo->query("SHOW TABLES LIKE 'inv\\_%'")->fetchAll(PDO::FETCH_COLUMN);
    sort($tables);
    foreach ($tables as $t) {
        if (!preg_match('/^inv_[a-z_]+$/', $t)) {
            continue;
        }
        $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
        if (!in_array($t, BACKUP_TABLES, true)) {
            $out .= preg_replace('/^CREATE TABLE/', 'CREATE TABLE IF NOT EXISTS', $create) . ";\n\n";
            continue;
        }
        $out .= "DROP TABLE IF EXISTS `$t`;\n$create;\n";
        $rows = $data['tables'][$t];
        foreach (array_chunk($rows, 100) as $chunk) {
            $cols = '`' . implode('`, `', array_keys($chunk[0])) . '`';
            $vals = array_map(fn ($r) => '(' . implode(', ', array_map('sql_value', array_values($r))) . ')', $chunk);
            $out .= "INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $vals) . ";\n";
        }
        $out .= "\n";
    }
    $out .= "SET FOREIGN_KEY_CHECKS = 1;\n-- end of backup\n";
    if (file_put_contents("$path.tmp", $out) !== strlen($out) || !rename("$path.tmp", $path)) {
        throw new RuntimeException('cannot write ' . basename($path));
    }
}

/** Make a backup set now. Returns its base name (inventory_YYYY-MM-DD_HHMM[_suffix]). */
function make_backup(string $suffix = ''): string
{
    $dir = backup_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $data = backup_data();
    $base = 'inventory_' . date('Y-m-d_Hi') . ($suffix !== '' ? '_' . preg_replace('/[^a-z0-9-]/', '', $suffix) : '');
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents("$dir/$base.json.tmp", $json) !== strlen($json)
        || !rename("$dir/$base.json.tmp", "$dir/$base.json")) {
        throw new RuntimeException("cannot write $base.json");
    }
    write_ods("$dir/$base.ods", $data);
    write_sql("$dir/$base.sql", $data);
    save_setting('last_backup_at', now());
    apply_retention();
    return $base;
}

/** Existing backup sets, newest first: [base => ['ods' => bool, 'json' => bool, 'sql' => bool, 'date' => 'Y-m-d H:i']]. */
function list_backups(): array
{
    $sets = [];
    foreach (glob(backup_dir() . '/inventory_*.{ods,json,sql}', GLOB_BRACE) ?: [] as $f) {
        if (preg_match('/^(inventory_(\d{4}-\d{2}-\d{2})_(\d{2})(\d{2})(?:_[a-z0-9-]+)?)\.(ods|json|sql)$/', basename($f), $m)) {
            $sets[$m[1]] ??= ['ods' => false, 'json' => false, 'sql' => false, 'date' => "$m[2] $m[3]:$m[4]"];
            $sets[$m[1]][$m[5]] = true;
        }
    }
    uasort($sets, fn ($a, $b) => strcmp($b['date'], $a['date']));
    return $sets;
}

/**
 * Retention rule (same as the Pi): keep the newest BACKUP_KEEP_RECENT sets, and the first
 * set of every calendar month forever. $dates: base => 'Y-m-d H:i'. Returns bases to keep.
 */
function backups_to_keep(array $dates): array
{
    arsort($dates);                                    // newest first
    $keep = array_slice(array_keys($dates), 0, BACKUP_KEEP_RECENT);
    $firstOfMonth = [];
    foreach ($dates as $base => $d) {                  // newest first, so the last one seen wins
        $firstOfMonth[substr($d, 0, 7)] = $base;
    }
    return array_values(array_unique(array_merge($keep, array_values($firstOfMonth))));
}

function apply_retention(): void
{
    $sets = list_backups();
    $keep = backups_to_keep(array_map(fn ($s) => $s['date'], $sets));
    foreach (array_keys($sets) as $base) {
        if (!in_array($base, $keep, true)) {
            foreach (BACKUP_EXTS as $ext) {
                @unlink(backup_dir() . "/$base.$ext");
            }
        }
    }
}

/** Path of a backup file if the name is valid and it exists, else null (no path traversal). */
function backup_file(string $name): ?string
{
    if (!preg_match('/^inventory_\d{4}-\d{2}-\d{2}_\d{4}(?:_[a-z0-9-]+)?\.(ods|json|sql)$/', $name)) {
        return null;
    }
    $path = backup_dir() . '/' . $name;
    return is_file($path) ? $path : null;
}

/** Read items from an uploaded backup (.json from this app, or .ods in the old spreadsheet layout). */
function read_backup_items(string $path, string $originalName): array
{
    if (str_ends_with(strtolower($originalName), '.json')) {
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || ($data['schema'] ?? null) !== BACKUP_SCHEMA || !isset($data['tables']['inv_items'])) {
            throw new RuntimeException('This is not a backup .json from this inventory.');
        }
        return [$data['tables']['inv_items'], $data['tables']['inv_settings'] ?? []];
    }
    if (str_ends_with(strtolower($originalName), '.ods')) {
        return [read_ods_items($path), []];
    }
    throw new RuntimeException('Upload a .json or .ods backup file.');
}

/** First sheet of an .ods with columns Box, Bin, Label row 1..3, Keywords, Comments. */
function read_ods_items(string $path): array
{
    $z = new ZipArchive();
    if ($z->open($path) !== true || ($xml = $z->getFromName('content.xml')) === false) {
        throw new RuntimeException('This .ods file cannot be read.');
    }
    $doc = new DOMDocument();
    if (!@$doc->loadXML($xml, LIBXML_NONET)) {
        throw new RuntimeException('This .ods file is damaged.');
    }
    $xp = new DOMXPath($doc);
    $xp->registerNamespace('table', 'urn:oasis:names:tc:opendocument:xmlns:table:1.0');
    $xp->registerNamespace('text', 'urn:oasis:names:tc:opendocument:xmlns:text:1.0');
    $sheet = $xp->query('//table:table')->item(0);
    $rows = [];
    foreach ($xp->query('.//table:table-row', $sheet) as $row) {
        $vals = [];
        foreach ($xp->query('table:table-cell|table:covered-table-cell', $row) as $cell) {
            $rep = min(50, max(1, (int) ($cell->getAttribute('table:number-columns-repeated') ?: 1)));
            $txt = implode("\n", array_map(fn ($p) => $p->textContent, iterator_to_array($xp->query('text:p', $cell))));
            for ($i = 0; $i < $rep; $i++) {
                $vals[] = $txt;
            }
        }
        $rows[] = array_slice(array_pad($vals, 7, ''), 0, 7);
    }
    $head = array_map('strtolower', array_map('trim', $rows[0] ?? []));
    if (array_slice($head, 0, 3) !== ['box', 'bin', 'label row 1']) {
        throw new RuntimeException('The first sheet must start with the columns Box, Bin, Label row 1.');
    }
    $items = [];
    foreach (array_slice($rows, 1) as $r) {
        if (implode('', $r) === '') {
            continue;
        }
        $items[] = array_combine(array_keys(ODS_COLUMNS), $r);
    }
    return $items;
}

/**
 * Replace all items (and, from a .json, the settings) with those from a backup.
 * A backup of the current state is made first. Returns [count, skipped-messages].
 */
function restore_items(array $items, array $settings): array
{
    $clean = [];
    $skipped = [];
    foreach ($items as $i => $row) {
        $item = clean_item(is_array($row) ? $row : []);
        if ($errors = validate_item($item)) {
            $skipped[] = 'row ' . ($i + 1) . ': ' . implode(' ', $errors);
            continue;
        }
        $clean[] = [$item, $row];
    }
    if (!$clean) {
        throw new RuntimeException('The file contains no valid items; nothing was changed.');
    }
    make_backup('pre-restore');
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM inv_items');
    $st = $pdo->prepare('INSERT INTO inv_items (id, box, bin, label1, label2, label3, keywords, comments, created_at, updated_at, last_printed_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($clean as [$item, $row]) {
        $st->execute([isset($row['id']) ? (int) $row['id'] : null, $item['box'], $item['bin'], $item['label1'], $item['label2'],
                      $item['label3'], $item['keywords'], $item['comments'], $row['created_at'] ?? now(), $row['updated_at'] ?? now(),
                      $row['last_printed_at'] ?? null]);
    }
    $allowed = array_keys(default_settings());
    foreach ($settings as $s) {
        if (in_array($s['name'] ?? '', $allowed, true)) {
            save_setting($s['name'], (string) $s['value']);
        }
    }
    audit('restore-all', null, null, ['items' => count($clean), 'skipped' => count($skipped)]);
    $pdo->commit();
    return [count($clean), $skipped];
}
