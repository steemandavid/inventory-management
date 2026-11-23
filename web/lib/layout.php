<?php
declare(strict_types=1);
/* Page frame shared by public and admin pages. */

require_once __DIR__ . '/print.php';

function page_start(string $title, bool $admin = false, array $scripts = []): void
{
    flash_take();   // must happen before any output (it clears a cookie)
    header('Content-Type: text/html; charset=utf-8');
    $css = url('assets/style.css') . '?v=' . APP_VERSION;
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">',
        '<meta name="viewport" content="width=device-width, initial-scale=1">',
        '<meta name="robots" content="noindex, nofollow">',
        '<title>', h($title), ' · Kelder inventory</title>',
        '<link rel="stylesheet" href="', h($css), '">',
        '<link rel="icon" href="', h(url('assets/icon.svg')), '" type="image/svg+xml">';
    $scripts[] = 'common.js';
    foreach ($scripts as $s) {
        echo '<script src="', h(url('assets/' . $s) . '?v=' . APP_VERSION), '" defer></script>';
    }
    echo '</head><body id="top"', $admin ? ' class="admin"' : '', '><header class="top"><div class="wrap">',
        '<a class="brand" href="', h(url($admin ? 'admin/' : '')), '">Kelder inventory', $admin ? ' <span class="tag">admin</span>' : '', '</a>';
    if ($admin) {
        echo '<nav><a href="', h(url('admin/edit.php')), '">Add</a>',
            '<a href="', h(url('admin/audit.php')), '">History</a>',
            '<a href="', h(url('admin/backups.php')), '">Backups</a>',
            '<a href="', h(url('admin/settings.php')), '">Settings</a>',
            '<a href="', h(url('')), '">Public view</a></nav>';
        $a = agent_status();
        $label = ['online' => 'Printer online', 'offline' => 'Printer offline', 'never' => 'Printer not connected'][$a['state']];
        echo '<a class="printer st-', h($a['state']), '" href="', h(url('admin/jobs.php')), '" title="Print queue">',
            h($label), $a['queued'] ? ' · ' . $a['queued'] . ' queued' : '', '</a>';
    } else {
        echo '<nav><a href="', h(url('admin/')), '">Admin</a></nav>';
    }
    echo '</div></header><main class="wrap">';
}

function page_end(bool $admin = false): void
{
    echo '</main><footer class="wrap foot">';
    if ($admin) {
        echo '<form method="post" action="', h(url('admin/logout.php')), '" class="inline">', csrf_field(),
            '<button class="linklike" type="submit">Log out</button></form>';
    }
    echo '</footer><a href="#top" class="totop" id="totop" aria-label="Back to top" title="Back to top">↑</a></body></html>';
}

function flash_set(string $msg): void
{
    setcookie('inv_flash', $msg, ['expires' => time() + 60, 'path' => base_path(), 'secure' => !is_dev(),
        'httponly' => true, 'samesite' => 'Strict']);
}

/** Read the one-time flash message and clear its cookie. Called by page_start(). */
function flash_take(): ?string
{
    static $msg = false;
    if ($msg === false) {
        $msg = $_COOKIE['inv_flash'] ?? null;
        $msg = is_string($msg) && $msg !== '' ? mb_substr($msg, 0, 200) : null;
        if ($msg !== null && !headers_sent()) {
            setcookie('inv_flash', '', ['expires' => time() - 3600, 'path' => base_path(), 'secure' => !is_dev(),
                'httponly' => true, 'samesite' => 'Strict']);
        }
    }
    return $msg;
}

function flash_show(): void
{
    $msg = flash_take();
    if ($msg !== null) {
        echo '<p class="flash">', h($msg), '</p>';
    }
}

/** One search result card. $adminLinks adds edit/copy/delete and a select checkbox. */
function item_card(array $r, bool $adminLinks = false): string
{
    $out = '<li class="item">';
    if ($adminLinks) {
        $out .= '<label class="pick"><input type="checkbox" name="ids[]" value="' . (int) $r['id'] . '" form="bulk"></label>';
    }
    $out .= '<div class="loc"><span class="box">' . h($r['box']) . '</span><span class="bin">' . h($r['bin']) . '</span></div>'
        . '<div class="txt"><div class="l1">' . h($r['label1']) . '</div>';
    foreach (['label2', 'label3'] as $f) {
        if ($r[$f] !== '') {
            $out .= '<div class="l2">' . h($r[$f]) . '</div>';
        }
    }
    if ($r['keywords'] !== '') {
        $out .= '<div class="meta">' . h($r['keywords']) . '</div>';
    }
    if ($r['comments'] !== '') {
        $out .= '<div class="meta comments">' . nl2br(h($r['comments'])) . '</div>';
    }
    if ($adminLinks) {
        $id = (int) $r['id'];
        $back = 'admin/' . (isset($_GET['q']) && is_string($_GET['q']) ? '?' . http_build_query(['q' => $_GET['q']]) : '');
        $out .= '<div class="actions"><a href="' . h(url('admin/edit.php?id=' . $id)) . '">Edit</a>'
            . '<a href="' . h(url('admin/edit.php?copy=' . $id)) . '">Duplicate</a>'
            . '<a href="' . h(url('admin/delete.php?id=' . $id)) . '">Delete</a>'
            . '<form method="post" action="' . h(url('admin/print.php')) . '" class="inline">' . csrf_field()
            . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="back" value="' . h($back) . '">'
            . '<button type="submit" class="linklike">Print label</button></form>'
            . (!empty($r['pending']) ? '<span class="pending">in print queue</span>'
                : ($r['last_printed_at'] ? '<span class="printed">printed ' . h(date('d-m H:i', strtotime($r['last_printed_at']))) . '</span>'
                : '<span class="never">never printed</span>')) . '</div>';
    }
    return $out . '</div></li>';
}

function pager(string $base, string $q, int $page, int $total): string
{
    $pages = (int) ceil($total / PAGE_SIZE);
    if ($pages <= 1) {
        return '';
    }
    $link = fn (int $p, string $text) => '<a href="' . h(url($base) . '?' . http_build_query(['q' => $q, 'page' => $p])) . '">' . $text . '</a>';
    $out = '<nav class="pager">';
    if ($page > 1) {
        $out .= $link($page - 1, '← Previous');
    }
    $out .= '<span>Page ' . $page . ' of ' . $pages . '</span>';
    if ($page < $pages) {
        $out .= $link($page + 1, 'Next →');
    }
    return $out . '</nav>';
}
