<?php
declare(strict_types=1);
/* Print queue status (FSD §7.3): recent jobs, cancel queued ones, print again. */
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/auth.php';
require dirname(__DIR__) . '/lib/items.php';
require_once dirname(__DIR__) . '/lib/print.php';
require dirname(__DIR__) . '/lib/layout.php';

require_admin();
expire_stuck_jobs();
if (is_post()) {
    $id = (int) ($_POST['job'] ?? 0);
    if (($_POST['action'] ?? '') === 'cancel') {
        db()->prepare("UPDATE inv_print_jobs SET status = 'cancelled', finished_at = ? WHERE id = ? AND status = 'queued'")
            ->execute([now(), $id]);
        flash_set('Cancelled.');
    } elseif (($_POST['action'] ?? '') === 'cancel_all') {
        $n = db()->prepare("UPDATE inv_print_jobs SET status = 'cancelled', finished_at = ? WHERE status = 'queued'");
        $n->execute([now()]);
        flash_set('Cancelled ' . $n->rowCount() . ' queued label(s).');
    }
    redirect('admin/jobs.php');
}
$a = agent_status();
$rows = db()->query('SELECT * FROM inv_print_jobs ORDER BY id DESC LIMIT 100')->fetchAll();
page_start('Print queue', true);
flash_show();
echo '<h1>Print queue</h1><p>Printer: <strong class="st-', h($a['state']), '">', h($a['state']), '</strong>',
    $a['last_seen'] ? ' · agent last seen ' . h($a['last_seen']) : ' · the print agent has never connected',
    $a['printer'] !== '' ? ' · ' . h($a['printer']) : '', '</p>';
if ($a['queued'] > 0) {
    echo '<form method="post">', csrf_field(), '<input type="hidden" name="action" value="cancel_all">',
        '<button type="submit">Cancel all queued (', $a['queued'], ')</button></form>';
}
echo '<table class="audit"><thead><tr><th>Queued</th><th>Label</th><th>Status</th><th></th></tr></thead><tbody>';
foreach ($rows as $r) {
    $p = json_decode($r['payload'], true) ?: [];
    echo '<tr><td>', h($r['created_at']), '</td><td>', h(($p['box'] ?? '') . '/' . ($p['bin'] ?? '') . ' ' . ($p['label1'] ?? '')),
        '</td><td class="st-', h($r['status']), '">', h($r['status']), $r['error'] !== '' ? ': ' . h($r['error']) : '', '</td><td>';
    if ($r['status'] === 'queued') {
        echo '<form method="post">', csrf_field(), '<input type="hidden" name="action" value="cancel"><input type="hidden" name="job" value="',
            (int) $r['id'], '"><button type="submit">Cancel</button></form>';
    } elseif ($r['item_id'] && in_array($r['status'], ['failed', 'cancelled', 'printed'], true)) {
        echo '<form method="post" action="', h(url('admin/print.php')), '">', csrf_field(), '<input type="hidden" name="id" value="',
            (int) $r['item_id'], '"><input type="hidden" name="back" value="admin/jobs.php"><button type="submit">Print again</button></form>';
    }
    echo '</td></tr>';
}
echo '</tbody></table>';
page_end(true);
