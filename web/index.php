<?php
declare(strict_types=1);
/* Public search page (FSD §6.1). Works without JavaScript; search.js adds live results. */
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/items.php';
require __DIR__ . '/lib/layout.php';

send_security_headers();
$q = is_string($_GET['q'] ?? null) ? mb_substr($_GET['q'], 0, 100) : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
if (!rate_limit(60)) {
    http_response_code(429);
    header('Retry-After: 60');
    exit('Too many searches. Please wait a minute.');
}
[$rows, $total] = search_items($q, $page);

page_start('Search', false, ['search.js']);
echo '<form class="search" method="get" action="', h(url('')), '" role="search">',
    '<input type="search" name="q" id="q" value="', h($q), '" placeholder="Search components, or box/bin like 03/02" autofocus autocomplete="off" aria-label="Search">',
    '<button type="submit">Search</button></form>';
echo '<p class="count" id="count">', $total, ' item', $total === 1 ? '' : 's', $q !== '' ? ' found' : '', '</p>';
echo '<ul class="items" id="results" data-api="', h(url('api/search.php')), '">';
foreach ($rows as $r) {
    echo item_card($r);
}
echo '</ul>', '<div id="pager">', pager('', $q, $page, $total), '</div>';
page_end();
