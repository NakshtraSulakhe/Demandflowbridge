<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/recognition.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
}

$respond = static function (int $statusCode, array $payload) use ($isCli): void {
    if (!$isCli) {
        http_response_code($statusCode);
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

$readCliArgs = static function (): array {
    global $argv;
    $out = [];
    if (!isset($argv) || !is_array($argv)) {
        return $out;
    }
    foreach (array_slice($argv, 1) as $arg) {
        $arg = trim((string)$arg);
        if ($arg === '') {
            continue;
        }
        $eqPos = strpos($arg, '=');
        if ($eqPos === false) {
            $out[$arg] = '1';
            continue;
        }
        $key = trim(substr($arg, 0, $eqPos));
        if ($key === '') {
            continue;
        }
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

$expectedToken = function_exists('getRecognitionSchedulerToken') ? getRecognitionSchedulerToken(true) : null;
if ($expectedToken === null || $expectedToken === '') {
    $respond(500, [
        'ok' => false,
        'message' => 'Recognition scheduler token is not available.',
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

$summary = function_exists('dfRecognitionAutomationRun') ? dfRecognitionAutomationRun() : [];
$baseUrl = rtrim((string)(defined('APP_URL') ? APP_URL : ''), '/');
$path = '/modules/admin/recognition-automation-cron.php';
$ok = !empty($summary);

$respond($ok ? 200 : 500, [
    'ok' => $ok,
    'message' => $ok ? 'Recognition automation completed successfully.' : 'Recognition automation did not return a summary.',
    'ran_at' => (string)($summary['ran_at'] ?? ''),
    'users_scanned' => (int)($summary['users_scanned'] ?? 0),
    'cards_visible' => (int)($summary['cards_visible'] ?? 0),
    'types' => (array)($summary['types'] ?? []),
    'scheduler_url' => $baseUrl !== '' ? ($baseUrl . $path) : $path,
]);
