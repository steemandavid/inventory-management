<?php
declare(strict_types=1);
/*
 * API for the print agent on the Pi (FSD §8.3, §8.4, §9.4). Authenticated with the agent
 * token (X-Agent-Token header), which can only: poll/claim print jobs, report job
 * results and send a heartbeat. It cannot read sessions or change items.
 *
 *   POST ?action=poll    body: {"version": "...", "printer": "..."}  -> {"jobs": [...]}
 *   POST ?action=report  body: {"id": 1, "claim": "...", "status": "printed"|"failed", "error": "..."}
 */
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/items.php';
require_once dirname(__DIR__) . '/lib/print.php';

send_security_headers(true);
header('Content-Type: application/json; charset=utf-8');

function reply(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_post()) {
    reply(['error' => 'POST only'], 405);
}
// X-Agent-Token: PHP-FPM setups often drop the Authorization header, a custom header survives.
$token = (string) ($_SERVER['HTTP_X_AGENT_TOKEN'] ?? '');
if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
    $token = '';
}
$stored = db()->query('SELECT token_hash FROM inv_agent WHERE id = 1')->fetchColumn();
if ($token === '' || !is_string($stored) || !hash_equals($stored, hash('sha256', $token))) {
    usleep(300000);
    reply(['error' => 'unauthorized'], 401);
}
$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = [];
}
$pdo = db();

switch ($_GET['action'] ?? '') {
    case 'poll':
        $pdo->prepare('UPDATE inv_agent SET last_seen = ?, agent_version = ?, printer_state = ? WHERE id = 1')
            ->execute([now(), mb_substr((string) ($body['version'] ?? ''), 0, 32), mb_substr((string) ($body['printer'] ?? ''), 0, 255)]);
        expire_stuck_jobs();
        // Claim all queued jobs atomically with a random claim id, oldest first.
        $claim = bin2hex(random_bytes(8));
        $pdo->prepare("UPDATE inv_print_jobs SET status = 'printing', claim = ?, claimed_at = ?
                       WHERE status = 'queued' ORDER BY id LIMIT 50")->execute([$claim, now()]);
        $st = $pdo->prepare('SELECT id, batch_id, payload FROM inv_print_jobs WHERE claim = ? ORDER BY id');
        $st->execute([$claim]);
        $jobs = array_map(fn ($r) => ['id' => (int) $r['id'], 'batch' => $r['batch_id'], 'claim' => $claim,
                                      'label' => json_decode($r['payload'], true)], $st->fetchAll());
        reply(['jobs' => $jobs, 'poll_seconds' => 5]);

    case 'report':
        $id = (int) ($body['id'] ?? 0);
        $status = $body['status'] ?? '';
        if (!in_array($status, ['printed', 'failed'], true)) {
            reply(['error' => 'bad status'], 400);
        }
        $st = $pdo->prepare("UPDATE inv_print_jobs SET status = ?, error = ?, finished_at = ?
                             WHERE id = ? AND claim = ? AND status = 'printing'");
        $st->execute([$status, mb_substr((string) ($body['error'] ?? ''), 0, 255), now(), $id, (string) ($body['claim'] ?? '')]);
        if ($st->rowCount() === 0) {
            reply(['error' => 'unknown job, wrong claim, or already finished (timed out?)'], 409);
        }
        if ($status === 'printed') {
            $pdo->prepare('UPDATE inv_items i JOIN inv_print_jobs j ON j.item_id = i.id SET i.last_printed_at = ? WHERE j.id = ?')
                ->execute([now(), $id]);
        }
        reply(['ok' => true]);

    default:
        reply(['error' => 'unknown action'], 400);
}
