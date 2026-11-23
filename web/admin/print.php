<?php
declare(strict_types=1);
/* Queue labels for printing: one item (Print label link) or the selected items. */
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/auth.php';
require dirname(__DIR__) . '/lib/items.php';
require_once dirname(__DIR__) . '/lib/print.php';
require dirname(__DIR__) . '/lib/layout.php';

if (!is_post()) {
    redirect('admin/');
}
require_admin();
$ids = $_POST['ids'] ?? [];
if (!is_array($ids)) {
    $ids = [];
}
if (isset($_POST['id'])) {
    $ids[] = $_POST['id'];
}
[$n, $error] = queue_labels($ids);
flash_set($error ?? queued_message($n));
// back to where the user came from (same search), only paths inside the admin
$back = (string) ($_POST['back'] ?? '');
redirect(preg_match('#^admin/[\w./?=&%+-]*$#', $back) ? $back : 'admin/');
