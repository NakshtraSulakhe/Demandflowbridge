<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
}

$respond = function (int $statusCode, array $payload) use ($isCli): void {
    if (!$isCli) {
        http_response_code($statusCode);
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

$readCliArgs = function (): array {
    global $argv;
    $out = [];
    if (!isset($argv) || !is_array($argv)) return $out;
    foreach (array_slice($argv, 1) as $arg) {
        $arg = trim((string)$arg);
        if ($arg === '') continue;
        $eqPos = strpos($arg, '=');
        if ($eqPos === false) {
            $out[$arg] = '1';
            continue;
        }
        $key = trim(substr($arg, 0, $eqPos));
        if ($key === '') continue;
        $out[$key] = trim(substr($arg, $eqPos + 1));
    }
    return $out;
};

$input = $isCli ? $readCliArgs() : array_merge($_GET ?? [], $_POST ?? []);
$token = trim((string)($input['token'] ?? ''));
if ($token === '') {
    $token = trim((string)($_SERVER['HTTP_X_CRON_TOKEN'] ?? ''));
}
if ($token === '') {
    $auth = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
        $token = trim((string)($m[1] ?? ''));
    }
}

$expectedToken = getHrAttendanceSchedulerToken(true);
if ($expectedToken === null || $expectedToken === '') {
    $respond(500, [
        'ok' => false,
        'message' => 'Scheduler token is not available.',
    ]);
}

if ($token === '' || !hash_equals($expectedToken, $token)) {
    $respond(401, [
        'ok' => false,
        'message' => 'Invalid scheduler token.',
    ]);
}

if (!$isCli) {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'POST'], true)) {
        $respond(405, [
            'ok' => false,
            'message' => 'Method not allowed.',
        ]);
    }
}

$runAt = trim((string)($input['at'] ?? ''));
$result = hrRunAttendanceAutoCloseScheduler($runAt !== '' ? $runAt : null);
$baseUrl = rtrim((string)(defined('APP_URL') ? APP_URL : ''), '/');
$path = '/modules/hr/attendance-auto-close-cron.php';
$statusCode = !empty($result['ok']) ? 200 : 500;

$respond($statusCode, [
    'ok' => !empty($result['ok']),
    'message' => (string)($result['message'] ?? ''),
    'run_at' => (string)($result['run_at'] ?? ''),
    'open_days' => (int)($result['open_days'] ?? 0),
    'closed_days' => (int)($result['closed_days'] ?? 0),
    'skipped_days' => (int)($result['skipped_days'] ?? 0),
    'scheduler_url' => $baseUrl !== '' ? ($baseUrl . $path) : $path,
]);
