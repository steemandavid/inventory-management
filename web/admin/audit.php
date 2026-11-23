<?php
declare(strict_types=1);
/* Change history (inv_audit) with restore for deleted items. */
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/auth.php';
require dirname(__DIR__) . '/lib/items.php';
require dirname(__DIR__) . '/lib/layout.php';

require_admin();
if (is_post() && ($_POST['action'] ?? '') === 'restore') {
    $newId = restore_deleted((int) ($_POST['audit_id'] ?? 0));
    flash_set($newId ? 'Item restored.' : 'This item cannot be restored (already restored?).');
    redirect('admin/audit.php');
}
$rows = db()->query('SELECT * FROM inv_audit ORDER BY id DESC LIMIT 200')->fetchAll();
page_start('History', true);
flash_show();
echo '<h1>History</h1><p class="note">The last 200 changes.</p><table class="audit"><thead><tr><th>When</th><th>What</th><th>Item</th><th></th></tr></thead><tbody>';
foreach ($rows as $r) {
    $data = json_decode((string) ($r['new_json'] ?? $r['old_json'] ?? ''), true) ?: [];
    $old = json_decode((string) ($r['old_json'] ?? ''), true) ?: [];
    $desc = ($data['box'] ?? $old['box'] ?? '') . '/' . ($data['bin'] ?? $old['bin'] ?? '') . ' ' . ($data['label1'] ?? $old['label1'] ?? '');
    $changes = '';
    if ($r['action'] === 'update') {
        $diff = [];
        foreach (ITEM_FIELDS as $f => [$label]) {
            if (($old[$f] ?? '') !== ($data[$f] ?? '')) {
                $diff[] = $label . ': "' . ($old[$f] ?? '') . '" → "' . ($data[$f] ?? '') . '"';
            }
        }
        $changes = '<div class="meta">' . h(implode('; ', $diff)) . '</div>';
    }
    echo '<tr><td>', h($r['at']), '</td><td>', h($r['action']), '</td><td>', h($desc), $changes, '</td><td>';
    if ($r['action'] === 'delete') {
        echo '<form method="post">', csrf_field(), '<input type="hidden" name="action" value="restore">',
            '<input type="hidden" name="audit_id" value="', (int) $r['id'], '"><button type="submit">Restore</button></form>';
    }
    echo '</td></tr>';
}
echo '</tbody></table>';
page_end(true);
