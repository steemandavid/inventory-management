<?php
declare(strict_types=1);
/*
 * One-time setup: creates the inv_* tables, sets the admin password and imports
 * setup-seed.json if present (then deletes it). Once an admin password exists this
 * page only says so, and it can (and should) be deleted from the server.
 */
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/items.php';
require __DIR__ . '/lib/layout.php';

send_security_headers(true);
// Only someone with the setup token from config.php may use this page (it is public
// until an admin password exists).
$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$expected = (string) (conf()['setup_token'] ?? '');
if ($expected === '' || strlen($expected) < 32 || !hash_equals($expected, $token)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Not found.\n");
}
$pdo = db();
foreach (array_filter(array_map('trim', explode(';', (string) file_get_contents(__DIR__ . '/sql/schema.sql')))) as $sql) {
    if (preg_match('/^\s*(--[^\n]*\n\s*)*CREATE/i', $sql)) {
        $pdo->exec($sql);
    }
}
if (admin_exists()) {
    page_start('Setup');
    echo '<h1>Setup is already done</h1><p>An admin password exists. Delete <code>setup.php</code> from the server.</p>',
        '<p><a href="', h(url('admin/')), '">Go to admin</a></p>';
    page_end();
    exit;
}
$error = null;
if (is_post()) {
    require_same_origin();
    $pw1 = (string) ($_POST['new1'] ?? '');
    $pw2 = (string) ($_POST['new2'] ?? '');
    if ($pw1 !== $pw2) {
        $error = 'The passwords do not match.';
    } elseif ($p = password_problem($pw1)) {
        $error = $p;
    } else {
        $pdo->beginTransaction();
        // Re-check inside the transaction so two simultaneous setups can't both win.
        if ((int) $pdo->query('SELECT COUNT(*) FROM inv_admin FOR UPDATE')->fetchColumn() > 0) {
            $pdo->rollBack();
            redirect('admin/login.php');
        }
        $pdo->prepare('INSERT INTO inv_admin (id, password_hash, password_changed_at) VALUES (1, ?, ?)')
            ->execute([password_hash($pw1, PASSWORD_ARGON2ID), now()]);
        $pdo->commit();
        $imported = 0;
        $seed = __DIR__ . '/setup-seed.json';
        if (is_file($seed) && (int) $pdo->query('SELECT COUNT(*) FROM inv_items')->fetchColumn() === 0) {
            foreach (json_decode((string) file_get_contents($seed), true) ?: [] as $row) {
                $item = clean_item($row);
                if (!validate_item($item)) {
                    create_item($item);
                    $imported++;
                }
            }
            @unlink($seed);
        }
        start_session();
        flash_set('Setup complete.' . ($imported ? " Imported $imported items." : '') . ' Now delete setup.php from the server.');
        redirect('admin/');
    }
}
page_start('Setup');
echo '<h1>Set the admin password</h1><p>This page works once. Choose a strong password (at least ', MIN_PASSWORD_LENGTH,
    ' characters) and store it in your password manager.</p>';
if ($error) {
    echo '<p class="error">', h($error), '</p>';
}
echo '<form method="post" class="narrow"><input type="hidden" name="token" value="', h($token), '"><label>Password<input type="password" name="new1" required minlength="', MIN_PASSWORD_LENGTH,
    '" autocomplete="new-password" autofocus></label><label>Password again<input type="password" name="new2" required minlength="',
    MIN_PASSWORD_LENGTH, '" autocomplete="new-password"></label><button type="submit">Save</button></form>';
page_end();
