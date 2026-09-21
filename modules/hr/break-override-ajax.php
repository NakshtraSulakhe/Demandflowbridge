<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json');
ensureCsrfToken();

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);

if ($userId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid user']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = (string)($input['action'] ?? '');
$csrf = (string)($input['csrf_token'] ?? '');

if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrf)) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit;
}

if (!in_array($action, ['resume_working', 'end_break'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

$now = new DateTime('now', hrBaseTz());
$workDate = $now->format('Y-m-d');
$nowStr = $now->format('Y-m-d H:i:s');

// Get current attendance day
$day = getOpenAttendanceDayForUser($userId, $nowStr);
if (!$day) {
    echo json_encode(['success' => false, 'message' => 'No active attendance record']);
    exit;
}

$dayId = (int)($day['id'] ?? 0);
if ($dayId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid attendance record']);
    exit;
}

// Get current state
$openState = getOpenAttendanceState($dayId);
if (!$openState) {
    echo json_encode(['success' => false, 'message' => 'No active state to end']);
    exit;
}

$currentState = (string)($openState['state'] ?? '');
$breakTypeMap = [
    'Break1' => 'team_break_1',
    'Break2' => 'team_break_2',
    'Lunch' => 'dinner',
];

if (!in_array($currentState, ['Break1', 'Break2', 'Lunch'], true)) {
    echo json_encode(['success' => false, 'message' => 'Not currently on a break']);
    exit;
}

$breakType = $breakTypeMap[$currentState] ?? null;
if (!$breakType) {
    echo json_encode(['success' => false, 'message' => 'Invalid break type']);
    exit;
}

// End current break state
$ended = endAttendanceStateByDayId($dayId, $nowStr);
if (!$ended) {
    logBreakAutomation('ERROR', 'Failed to end break state in override', ['user_id' => $userId, 'break_type' => $breakType]);
    echo json_encode(['success' => false, 'message' => 'Failed to end current state']);
    exit;
}

// Update break session
$session = getTodayBreakSession($userId, $breakType, $workDate);
if ($session) {
    $startTime = (string)($openState['start_at'] ?? '');
    $startDt = hrParseBase($startTime);
    $endDt = hrParseBase($nowStr);
    
    if ($startDt && $endDt) {
        $durationMinutes = (int)round(($endDt->getTimestamp() - $startDt->getTimestamp()) / 60);
        updateBreakSessionStatus((int)($session['id'] ?? 0), 'completed', $startTime, $nowStr, $durationMinutes);
    }
}

if ($action === 'resume_working') {
    // Start Working state
    $result = startAttendanceState($userId, 'Working', $nowStr);
    if (!$result['ok']) {
        logBreakAutomation('ERROR', 'Failed to start Working state in override', ['user_id' => $userId, 'error' => $result['error'] ?? 'Unknown']);
        echo json_encode(['success' => false, 'message' => 'Failed to resume working: ' . ($result['error'] ?? 'Unknown error')]);
        exit;
    }
    logBreakAutomation('INFO', 'Manual override - Resume Working', ['user_id' => $userId, 'break_type' => $breakType]);
} else {
    // end_break - just end the break, don't auto-start Working
    logBreakAutomation('INFO', 'Manual override - End Break', ['user_id' => $userId, 'break_type' => $breakType]);
}

echo json_encode(['success' => true, 'message' => 'Break ended successfully']);
