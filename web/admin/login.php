<?php
declare(strict_types=1);
/* Admin login (FSD §7.1, §9.1). */
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/auth.php';
require dirname(__DIR__) . '/lib/layout.php';

send_security_headers(true);
if (!admin_exists()) {
    http_response_code(503); exit("The inventory is not set up yet.");
}
if (current_session()) {
    redirect('admin/');
}
$error = null;
if (is_post()) {
    require_same_origin();
    $pw = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $error = attempt_login($pw);
    if ($error === null) {
        redirect('admin/');
    }
    http_response_code(401);
} elseif (($wait = login_wait_seconds()) > 0) {
    $error = 'Too many failed attempts. Try again in ' . max(1, (int) ceil($wait / 60)) . ' minute(s).';
}
page_start('Log in');
echo '<h1>Admin login</h1>';
if ($error) {
    echo '<p class="error">', h($error), '</p>';
}
echo '<form method="post" class="narrow"><label>Password<input type="password" name="password" required autofocus autocomplete="current-password"></label>',
    '<button type="submit">Log in</button></form>';
page_end();
