<?php
declare(strict_types=1);
/* Backups on LWS (FSD §7.5, §10): list + download, back up now, restore from a file. */
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/auth.php';
require dirname(__DIR__) . '/lib/items.php';
require dirname(__DIR__) . '/lib/backup.php';
require dirname(__DIR__) . '/lib/layout.php';

require_admin();

if (isset($_GET['download'])) {
    $path = backup_file((string) $_GET['download']);
    if ($path === null) {
        http_response_code(404);
        exit('Backup not found.');
    }
    $ods = str_ends_with($path, '.ods');
    header('Content-Type: ' . ($ods ? 'application/vnd.oasis.opendocument.spreadsheet' : 'application/json'));
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

$error = null;
if (is_post()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'backup_now') {
        $base = make_backup('manual');
        flash_set("Backup made: $base");
        redirect('admin/backups.php');
    } elseif ($action === 'restore') {
        try {
            $f = $_FILES['file'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 20 * 1024 * 1024) {
                throw new RuntimeException('Choose a backup file (max 20 MB).');
            }
            if (($_POST['confirm'] ?? '') !== 'REPLACE') {
                throw new RuntimeException('Type REPLACE to confirm that all current items will be replaced.');
            }
            [$items, $settings] = read_backup_items($f['tmp_name'], (string) $f['name']);
            [$n, $skipped] = restore_items($items, $settings);
            flash_set("Restored $n items" . ($skipped ? ', skipped ' . count($skipped) . ' invalid row(s)' : '')
                . '. The previous state was saved as a pre-restore backup.');
            redirect('admin/');
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
}

$s = settings();
$sets = list_backups();
page_start('Backups', true);
flash_show();
echo '<h1>Backups</h1>';
if ($error) {
    echo '<p class="error">', h($error), '</p>';
}
$last = $s['last_backup_fetched_at'];
$stale = $last === '' || strtotime($last) < time() - 8 * 86400;
echo '<p class="', $stale ? 'error' : 'note', '">Weekly backup by the Pi: ',
    $last === '' ? 'has not run yet.' : 'last fetched ' . h($last) . ($stale ? ' (more than 8 days ago!)' : '.'), '</p>';
echo '<p class="note">Kept here and on the Pi: the 10 newest sets plus the first set of every month, forever. ',
    'The Pi copy is also pulled into john-ai\'s Borg backup every night.</p>';
echo '<form method="post">', csrf_field(), '<input type="hidden" name="action" value="backup_now">',
    '<button type="submit" class="primary">Back up now</button></form>';
echo '<table class="audit"><thead><tr><th>Date</th><th>Files</th></tr></thead><tbody>';
foreach ($sets as $base => $set) {
    echo '<tr><td>', h($set['date']), str_contains($base, '_manual') ? ' (manual)' : '', str_contains($base, '_pre-restore') ? ' (before restore)' : '', '</td><td>';
    foreach (['ods' => 'spreadsheet (.ods)', 'json' => 'full (.json)'] as $ext => $label) {
        if ($set[$ext]) {
            echo '<a href="', h(url("admin/backups.php?download=$base.$ext")), '">', $label, '</a> ';
        }
    }
    echo '</td></tr>';
}
if (!$sets) {
    echo '<tr><td colspan="2">No backups yet.</td></tr>';
}
echo '</tbody></table>';
echo '<section><h2>Restore</h2><p class="note">Replaces <strong>all</strong> items with those in the file: a .json backup ',
    '(also restores the label settings) or an .ods in the old spreadsheet layout (Box, Bin, Label row 1 to 3, Keywords, Comments). ',
    'A backup of the current state is made first.</p>',
    '<form method="post" enctype="multipart/form-data" class="narrow">', csrf_field(), '<input type="hidden" name="action" value="restore">',
    '<label>Backup file<input type="file" name="file" accept=".json,.ods" required></label>',
    '<label>Type REPLACE to confirm<input name="confirm" autocomplete="off" required></label>',
    '<button type="submit" class="danger">Restore</button></form></section>';
page_end(true);
