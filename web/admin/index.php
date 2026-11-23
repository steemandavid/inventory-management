<?php
declare(strict_types=1);
/* Admin overview: search with edit/duplicate/delete, select for printing (FSD §7). */
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/auth.php';
require dirname(__DIR__) . '/lib/items.php';
require dirname(__DIR__) . '/lib/layout.php';

require_admin();
$q = is_string($_GET['q'] ?? null) ? mb_substr($_GET['q'], 0, 100) : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
[$rows, $total] = search_items($q, $page);

page_start('Admin', true);
flash_show();
echo '<form class="search" method="get" role="search"><input type="search" name="q" value="', h($q),
    '" placeholder="Search" autocomplete="off" aria-label="Search"><button type="submit">Search</button>',
    '<a class="button primary" href="', h(url('admin/edit.php')), '">+ Add</a></form>';
echo '<p class="count">', $total, ' item', $total === 1 ? '' : 's', '</p>';
echo '<form id="bulk" method="post" action="', h(url('admin/print.php')), '">', csrf_field(),
    '<input type="hidden" name="back" value="', h('admin/' . ($q !== '' ? '?' . http_build_query(['q' => $q]) : '')), '">',
    '<button type="submit">Print selected labels</button></form>';
echo '<ul class="items">';
foreach ($rows as $r) {
    echo item_card($r, true);
}
echo '</ul>', pager('admin/', $q, $page, $total);
page_end(true);
