<?php
declare(strict_types=1);
/* Delete with a confirmation step (no JavaScript needed). Restorable from History. */
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/auth.php';
require dirname(__DIR__) . '/lib/items.php';
require dirname(__DIR__) . '/lib/layout.php';

require_admin();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$item = get_item($id);
if ($item === null) {
    http_response_code(404);
    exit('Item not found.');
}
if (is_post()) {
    delete_item($id);
    flash_set('Deleted: ' . $item['box'] . '/' . $item['bin'] . ' ' . $item['label1'] . ' (can be restored under History)');
    redirect('admin/');
}
page_start('Delete', true);
echo '<h1>Delete this component?</h1><ul class="items">', item_card($item), '</ul>',
    '<form method="post">', csrf_field(), '<input type="hidden" name="id" value="', $id, '">',
    '<div class="buttons"><button type="submit" class="danger">Delete</button><a href="', h(url('admin/')), '">Cancel</a></div></form>';
page_end(true);
