<?php
declare(strict_types=1);
/* JSON search for the live results on the public page. Read-only, rate-limited. */
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/items.php';

send_security_headers();
header('Content-Type: application/json; charset=utf-8');
if (!rate_limit(60)) {
    http_response_code(429);
    header('Retry-After: 60');
    echo json_encode(['error' => 'Too many searches. Please wait a minute.']);
    exit;
}
$q = is_string($_GET['q'] ?? null) ? mb_substr($_GET['q'], 0, 100) : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
[$rows, $total] = search_items($q, $page);
echo json_encode([
    'total' => $total,
    'page' => $page,
    'pages' => (int) ceil($total / PAGE_SIZE),
    'items' => array_map('public_item', $rows),
], JSON_UNESCAPED_UNICODE);
