<?php
declare(strict_types=1);
/* Label settings, password change, log out everywhere (FSD §7.4). */
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/auth.php';
require dirname(__DIR__) . '/lib/layout.php';

require_admin();
const NUMERIC = [
    // name => [label, min, max]
    'font_box' => ['Box font size (pt)', 5, 24], 'font_bin' => ['Bin font size (pt)', 5, 24],
    'font_label1' => ['Label row 1 font size (pt)', 5, 16], 'font_label2' => ['Label row 2 font size (pt)', 5, 16],
    'font_label3' => ['Label row 3 font size (pt)', 5, 16],
    'margin_left_mm' => ['Left margin (mm)', 0, 15], 'margin_right_mm' => ['Right margin (mm)', 0, 15],
    'margin_top_mm' => ['Top margin (mm)', 0, 5], 'margin_bottom_mm' => ['Bottom margin (mm)', 0, 5],
];
$errors = [];
$s = settings();

if (is_post()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'layout') {
        $new = [];
        foreach (NUMERIC as $name => [$label, $min, $max]) {
            $v = str_replace(',', '.', trim((string) ($_POST[$name] ?? '')));
            if (!is_numeric($v) || (float) $v < $min || (float) $v > $max) {
                $errors[] = "$label must be a number from $min to $max.";
            } else {
                $new[$name] = rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.');
            }
        }
        $side = $_POST['boxbin_side'] ?? '';
        if (!in_array($side, ['left', 'right'], true)) {
            $errors[] = 'Box/Bin side must be left or right.';
        }
        if (!$errors) {
            foreach ($new as $k => $v) {
                save_setting($k, $v);
            }
            save_setting('boxbin_side', $side);
            flash_set('Label settings saved.');
            redirect('admin/settings.php');
        }
        $s = array_merge($s, $_POST);
    } elseif ($action === 'password') {
        $hash = db()->query('SELECT password_hash FROM inv_admin WHERE id = 1')->fetchColumn();
        $cur = (string) ($_POST['current'] ?? '');
        $pw1 = (string) ($_POST['new1'] ?? '');
        $pw2 = (string) ($_POST['new2'] ?? '');
        if (!password_verify($cur, (string) $hash)) {
            $errors[] = 'The current password is wrong.';
        } elseif ($pw1 !== $pw2) {
            $errors[] = 'The new passwords do not match.';
        } elseif ($p = password_problem($pw1)) {
            $errors[] = $p;
        } else {
            set_admin_password($pw1);   // logs out all devices, including this one
            start_session();
            flash_set('Password changed. All other devices have been logged out.');
            redirect('admin/settings.php');
        }
    } elseif ($action === 'agent_token') {
        // Shown once on this page, stored only as a hash (FSD §9.4). Replaces the old token.
        $newAgentToken = bin2hex(random_bytes(32));
        db()->prepare('INSERT INTO inv_agent (id, token_hash) VALUES (1, ?) ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash)')
            ->execute([hash('sha256', $newAgentToken)]);
    } elseif ($action === 'logout_all') {
        logout(true);
        redirect('admin/login.php');
    }
}

page_start('Settings', true);
flash_show();
echo '<h1>Settings</h1>';
if ($errors) {
    echo '<p class="error">', h(implode(' ', $errors)), '</p>';
}
echo '<section><h2>Label layout</h2><p class="note">Defaults were measured in the Phase 0 print test (Dymo 99017, 12 × 50 mm). ',
    'The left margin covers the strip the printer cannot print on.</p>',
    '<form method="post" class="grid">', csrf_field(), '<input type="hidden" name="action" value="layout">';
foreach (NUMERIC as $name => [$label, $min, $max]) {
    echo '<label>', h($label), '<input name="', $name, '" value="', h((string) $s[$name]), '" inputmode="decimal" required></label>';
}
echo '<label>Box/Bin side<select name="boxbin_side">';
foreach (['right', 'left'] as $o) {
    echo '<option', $s['boxbin_side'] === $o ? ' selected' : '', '>', $o, '</option>';
}
echo '</select></label><div class="buttons"><button type="submit" class="primary">Save layout</button></div></form></section>';

echo '<section><h2>Change password</h2><form method="post" class="narrow">', csrf_field(),
    '<input type="hidden" name="action" value="password">',
    '<label>Current password<input type="password" name="current" required autocomplete="current-password"></label>',
    '<label>New password (at least ', MIN_PASSWORD_LENGTH, ' characters)<input type="password" name="new1" required minlength="', MIN_PASSWORD_LENGTH, '" autocomplete="new-password"></label>',
    '<label>New password again<input type="password" name="new2" required minlength="', MIN_PASSWORD_LENGTH, '" autocomplete="new-password"></label>',
    '<button type="submit">Change password</button></form></section>';

$agent = db()->query('SELECT last_seen, agent_version FROM inv_agent WHERE id = 1')->fetch();
echo '<section><h2>Print agent</h2>';
if (isset($newAgentToken)) {
    echo '<p class="flash">New agent token (shown only once, copy it now into <code>/etc/inventory-agent/token</code> on the Pi):</p>',
        '<p><code class="token">', h($newAgentToken), '</code></p>';
}
echo '<p class="note">', $agent ? 'A token is set' . ($agent['last_seen'] ? '; agent ' . h($agent['agent_version']) . ' last seen ' . h($agent['last_seen']) : '; the agent has not connected yet') : 'No agent token yet',
    '. Making a new token disconnects the agent until it gets the new one.</p>',
    '<form method="post">', csrf_field(), '<input type="hidden" name="action" value="agent_token">',
    '<button type="submit">', $agent ? 'Replace agent token' : 'Create agent token', '</button></form></section>';

$n = (int) db()->query('SELECT COUNT(*) FROM inv_sessions')->fetchColumn();
$fails = (int) db()->query('SELECT COUNT(*) FROM inv_login_attempts WHERE success = 0 AND at > NOW() - INTERVAL 1 DAY')->fetchColumn();
echo '<section><h2>Sessions</h2><p>', $n, ' logged-in device', $n === 1 ? '' : 's', '. ',
    $fails, ' failed login attempt', $fails === 1 ? '' : 's', ' in the last 24 hours.</p>',
    '<form method="post">', csrf_field(), '<input type="hidden" name="action" value="logout_all">',
    '<button type="submit">Log out everywhere</button></form></section>';
echo '<p class="note">Version ', APP_VERSION, '</p>';
page_end(true);
