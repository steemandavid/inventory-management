<?php
declare(strict_types=1);
/*
 * Shared setup for every request: error handling, config, database, security headers,
 * escaping, CSRF and small helpers. See FSD_Web_Inventory.md chapter 9.
 */

// LWS shows PHP errors to visitors by default (verba found full paths and stack traces).
// Errors go to the server log; visitors get one sentence.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Europe/Brussels');

const APP_VERSION = '0.2.0';
const PAGE_SIZE = 50;

set_exception_handler(function (Throwable $e): void {
    error_log('inventory: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo "Something went wrong on the server. Please try again later.\n";
});

function conf(): array
{
    static $conf = null;
    if ($conf === null) {
        $path = dirname(__DIR__) . '/config.php';
        if (!is_file($path)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            exit("The inventory is not configured yet.\n");
        }
        $conf = require $path;
        $conf += ['dev' => false, 'base_path' => '/inventory/', 'ip_header' => ''];
    }
    return $conf;
}

function is_dev(): bool
{
    return (bool) conf()['dev'];
}

function base_path(): string
{
    return conf()['base_path'];
}

/** One non-persistent connection per request: the hosting allows 8 at a time, shared with verba. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = conf();
        try {
            $pdo = new PDO(
                "mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4",
                $c['db_user'],
                $c['db_password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_PERSISTENT => false,
                ]
            );
            $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
        } catch (PDOException $e) {
            error_log('inventory: database connection failed: ' . $e->getCode());
            http_response_code(503);
            header('Retry-After: 5');
            header('Content-Type: text/plain; charset=utf-8');
            exit("The inventory is busy. Please try again in a few seconds.\n");
        }
    }
    return $pdo;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

/** HTML escape. */
function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return base_path() . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path), true, 303);
    exit;
}

/**
 * Security headers for every response (§9.3). CSP allows only our own files:
 * no inline scripts, no third parties.
 */
function send_security_headers(bool $private = false): void
{
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
        . "font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    header('X-Robots-Tag: noindex, nofollow');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if (!is_dev()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
    if ($private) {
        header('Cache-Control: no-store');
    } else {
        header('Cache-Control: no-cache');
    }
}

/** Visitor IP. On LWS a proxy may sit in front; config 'ip_header' names the header to trust. */
function client_ip(): string
{
    $hdr = conf()['ip_header'];
    if ($hdr !== '' && !empty($_SERVER[$hdr])) {
        $first = trim(explode(',', (string) $_SERVER[$hdr])[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** IPs are never stored as-is: peppered hash (privacy, §9.1). */
function ip_hash(): string
{
    return hash_hmac('sha256', client_ip(), (string) conf()['pepper']);
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/**
 * Reject cross-site POSTs: the Origin (or Referer) must be our own host.
 * Defence in depth next to SameSite=Strict cookies and CSRF tokens.
 */
function require_same_origin(): void
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '' && !empty($_SERVER['HTTP_REFERER'])) {
        $p = parse_url((string) $_SERVER['HTTP_REFERER']);
        $origin = ($p['scheme'] ?? '') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    }
    $p = parse_url($origin);
    $originHost = ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    if ($originHost === '' || !hash_equals(strtolower($host), strtolower($originHost))) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Request refused (cross-site).\n");
    }
}

/** Settings with defaults (label layout from Phase 0, §11.5). */
function settings(): array
{
    static $s = null;
    if ($s === null) {
        $s = default_settings();
        foreach (db()->query('SELECT name, value FROM inv_settings') as $row) {
            if (array_key_exists($row['name'], $s)) {
                $s[$row['name']] = $row['value'];
            }
        }
    }
    return $s;
}

function default_settings(): array
{
    return [
        'font_box' => '12', 'font_bin' => '12',
        'font_label1' => '10', 'font_label2' => '8', 'font_label3' => '6',
        'margin_left_mm' => '6.5', 'margin_right_mm' => '2.0',
        'margin_top_mm' => '1.2', 'margin_bottom_mm' => '2.0',
        'rotate' => '270', 'boxbin_side' => 'right',
        'last_box' => '', 'last_bin' => '',
    ];
}

function save_setting(string $name, string $value): void
{
    db()->prepare('INSERT INTO inv_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')
        ->execute([$name, $value]);
}

/**
 * Simple fixed-window rate limit per IP (public search, §6.2). Returns true if allowed.
 * Old windows are cleaned up occasionally.
 */
function rate_limit(int $perMinute): bool
{
    $window = date('Y-m-d H:i:00');
    $pdo = db();
    $pdo->prepare('INSERT INTO inv_rate (ip_hash, window_start, hits) VALUES (?, ?, 1)
                   ON DUPLICATE KEY UPDATE hits = hits + 1')->execute([ip_hash(), $window]);
    $st = $pdo->prepare('SELECT hits FROM inv_rate WHERE ip_hash = ? AND window_start = ?');
    $st->execute([ip_hash(), $window]);
    if (random_int(1, 50) === 1) {
        $pdo->exec('DELETE FROM inv_rate WHERE window_start < NOW() - INTERVAL 10 MINUTE');
    }
    return (int) $st->fetchColumn() <= $perMinute;
}
