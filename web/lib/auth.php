<?php
declare(strict_types=1);
/*
 * Admin authentication (FSD §9.1, §9.2): single password (argon2id), lockouts per IP and
 * globally, server-side sessions with only a token hash stored, CSRF token per session.
 */

const SESSION_COOKIE = 'inv_session';
const SESSION_IDLE_DAYS = 365;     // sliding: every visit extends it
const LOGIN_MIN_SECONDS = 0.5;       // every login attempt takes at least this long
const IP_FREE_FAILURES = 5;          // failures before the per-IP lockout starts
const IP_MAX_LOCK_MINUTES = 60;
const GLOBAL_FAILURES_PER_HOUR = 50; // all IPs together
const GLOBAL_LOCK_MINUTES = 15;
const MIN_PASSWORD_LENGTH = 12;

function admin_exists(): bool
{
    return (bool) db()->query('SELECT COUNT(*) FROM inv_admin WHERE id = 1')->fetchColumn();
}

function set_admin_password(string $password): void
{
    $hash = password_hash($password, PASSWORD_ARGON2ID);
    db()->prepare('INSERT INTO inv_admin (id, password_hash, password_changed_at) VALUES (1, ?, ?)
                   ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), password_changed_at = VALUES(password_changed_at)')
        ->execute([$hash, now()]);
    // A password change logs out every device (§9.2).
    db()->exec('DELETE FROM inv_sessions');
}

function password_problem(string $password): ?string
{
    if (mb_strlen($password) < MIN_PASSWORD_LENGTH) {
        return 'The password must be at least ' . MIN_PASSWORD_LENGTH . ' characters.';
    }
    return null;
}

/**
 * Seconds until this IP or everyone may try again, or 0 if a login attempt is allowed now.
 * Per IP: after 5 consecutive failures, 1 min, then doubling up to 60 min.
 * Globally: 50 failures in the last hour from all IPs block logins for 15 min.
 */
function login_wait_seconds(): int
{
    $pdo = db();
    $global = $pdo->query("SELECT COUNT(*) AS n, MAX(at) AS last FROM inv_login_attempts
                           WHERE success = 0 AND at > NOW() - INTERVAL 1 HOUR")->fetch();
    if ((int) $global['n'] >= GLOBAL_FAILURES_PER_HOUR) {
        $wait = strtotime($global['last']) + GLOBAL_LOCK_MINUTES * 60 - time();
        if ($wait > 0) {
            return $wait;
        }
    }
    // consecutive failures for this IP since its last success (last 24 h)
    $st = $pdo->prepare("SELECT COUNT(*) AS n, MAX(at) AS last FROM inv_login_attempts
                         WHERE ip_hash = ? AND success = 0 AND at > NOW() - INTERVAL 1 DAY
                           AND id > COALESCE((SELECT MAX(id) FROM inv_login_attempts
                                              WHERE ip_hash = ? AND success = 1), 0)");
    $st->execute([ip_hash(), ip_hash()]);
    $ip = $st->fetch();
    $fails = (int) $ip['n'];
    if ($fails >= IP_FREE_FAILURES) {
        $minutes = min(IP_MAX_LOCK_MINUTES, 2 ** ($fails - IP_FREE_FAILURES));
        $wait = strtotime($ip['last']) + $minutes * 60 - time();
        return max(0, $wait);
    }
    return 0;
}

/** Try a password. Returns null on success (session cookie set), or an error message. */
function attempt_login(string $password): ?string
{
    $start = microtime(true);
    $wait = login_wait_seconds();
    if ($wait > 0) {
        return 'Too many failed attempts. Try again in ' . max(1, (int) ceil($wait / 60)) . ' minute(s).';
    }
    $hash = db()->query('SELECT password_hash FROM inv_admin WHERE id = 1')->fetchColumn();
    $ok = is_string($hash) && password_verify($password, $hash);
    db()->prepare('INSERT INTO inv_login_attempts (ip_hash, at, success) VALUES (?, ?, ?)')
        ->execute([ip_hash(), now(), $ok ? 1 : 0]);
    if (random_int(1, 20) === 1) {
        db()->exec('DELETE FROM inv_login_attempts WHERE at < NOW() - INTERVAL 30 DAY');
    }
    // Same minimum duration whatever the outcome: timing reveals nothing.
    $elapsed = microtime(true) - $start;
    if ($elapsed < LOGIN_MIN_SECONDS) {
        usleep((int) ((LOGIN_MIN_SECONDS - $elapsed) * 1e6));
    }
    if (!$ok) {
        error_log('inventory: failed admin login');
        return 'Wrong password.';
    }
    if (is_string($hash) && password_needs_rehash($hash, PASSWORD_ARGON2ID)) {
        db()->prepare('UPDATE inv_admin SET password_hash = ? WHERE id = 1')
            ->execute([password_hash($password, PASSWORD_ARGON2ID)]);
    }
    start_session();
    return null;
}

function start_session(): void
{
    $token = bin2hex(random_bytes(32));   // new token on every login: no session fixation
    db()->prepare('INSERT INTO inv_sessions (token_hash, csrf_token, created_at, last_seen, user_agent)
                   VALUES (?, ?, ?, ?, ?)')
        ->execute([hash('sha256', $token), bin2hex(random_bytes(32)), now(), now(),
                   mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)]);
    set_session_cookie($token, time() + SESSION_IDLE_DAYS * 86400);
}

function set_session_cookie(string $value, int $expires): void
{
    setcookie(SESSION_COOKIE, $value, [
        'expires' => $expires,
        'path' => base_path(),
        'secure' => !is_dev(),
        'httponly' => true,
        // Lax, not Strict: Strict drops the cookie when the site is opened from a link in another
        // app (mail, chat), which looked like being logged out. POSTs stay protected by the CSRF
        // token and the origin check, and Lax never sends the cookie on cross-site POSTs.
        'samesite' => 'Lax',
    ]);
}

/** The current admin session row, or null. Slides the 30-day idle window. */
function current_session(): ?array
{
    static $session = false;
    if ($session !== false) {
        return $session;
    }
    $session = null;
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    if (!is_string($token) || !preg_match('/^[0-9a-f]{64}$/', $token)) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM inv_sessions WHERE token_hash = ?
                         AND last_seen > NOW() - INTERVAL ' . SESSION_IDLE_DAYS . ' DAY');
    $st->execute([hash('sha256', $token)]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    // Refresh last_seen (and the cookie) at most once an hour.
    if (strtotime($row['last_seen']) < time() - 3600) {
        db()->prepare('UPDATE inv_sessions SET last_seen = ? WHERE token_hash = ?')->execute([now(), $row['token_hash']]);
        set_session_cookie($token, time() + SESSION_IDLE_DAYS * 86400);
    }
    if (random_int(1, 50) === 1) {
        db()->exec('DELETE FROM inv_sessions WHERE last_seen < NOW() - INTERVAL ' . SESSION_IDLE_DAYS . ' DAY');
    }
    return $session = $row;
}

/** Gate for every admin page: redirects to the login page if not logged in. */
function require_admin(): array
{
    send_security_headers(true);
    $s = current_session();
    if ($s === null) {
        redirect('admin/login.php');
    }
    if (is_post()) {
        require_same_origin();
        $sent = $_POST['csrf'] ?? '';
        if (!is_string($sent) || !hash_equals($s['csrf_token'], $sent)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit("Request refused (form expired). Go back, reload the page and try again.\n");
        }
    }
    return $s;
}

function csrf_field(): string
{
    $s = current_session();
    return '<input type="hidden" name="csrf" value="' . h($s['csrf_token'] ?? '') . '">';
}

function logout(bool $everywhere = false): void
{
    if ($everywhere) {
        db()->exec('DELETE FROM inv_sessions');
    } elseif ($s = current_session()) {
        db()->prepare('DELETE FROM inv_sessions WHERE token_hash = ?')->execute([$s['token_hash']]);
    }
    set_session_cookie('', time() - 3600);
}
