<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/auth.php';
require dirname(__DIR__) . '/lib/layout.php';

if (!is_post()) {
    redirect('admin/');
}
require_admin();
logout(($_POST['everywhere'] ?? '') === '1');
redirect('');
