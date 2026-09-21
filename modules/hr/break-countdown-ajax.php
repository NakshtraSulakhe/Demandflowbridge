<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json');

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);

if ($userId <= 0) {
    echo json_encode(['success' => false, 'break' => null]);
    exit;
}

maybeStartDueAutomatedBreakForUser($userId);
$upcomingBreak = getUpcomingBreakForUser($userId);

if ($upcomingBreak) {
    $now = new DateTime('now', hrDisplayTz());
    $currentTime = $now->format('H:i:s');
    $status = $upcomingBreak['status'] ?? 'upcoming';
    
    if ($status === 'active') {
        // User is currently on break - show remaining time
        $countdown = $upcomingBreak['countdown'] ?? '00:00';
        $remainingSeconds = (int)($upcomingBreak['remaining_seconds'] ?? 0);
        $durationMinutes = (int)($upcomingBreak['duration_minutes'] ?? 15);
        $elapsedSeconds = ($durationMinutes * 60) - $remainingSeconds;
        $progress = $elapsedSeconds > 0 ? min(100, round(($elapsedSeconds / ($durationMinutes * 60)) * 100)) : 0;
        
        echo json_encode([
            'success' => true,
            'break' => [
                'type' => $upcomingBreak['type'],
                'label' => $upcomingBreak['label'],
                'scheduled_time' => $upcomingBreak['scheduled_time'] ?? $currentTime,
                'start_time' => $upcomingBreak['start_time'] ?? null,
                'countdown' => $countdown,
                'duration_minutes' => $durationMinutes,
                'status' => 'active',
                'remaining_seconds' => $remainingSeconds,
                'progress_percent' => $progress,
            ]
        ]);
    } else {
        // Upcoming break - show countdown to start
        $scheduledTime = $upcomingBreak['scheduled_time'];
        $scheduledDateTime = new DateTime($upcomingBreak['work_date'] . ' ' . $scheduledTime, hrDisplayTz());
        $diff = $scheduledDateTime->getTimestamp() - $now->getTimestamp();
        
        $countdown = null;
        if ($diff > 0) {
            $hours = floor($diff / 3600);
            $minutes = floor(($diff % 3600) / 60);
            $seconds = $diff % 60;
            $countdown = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
        }
        
        echo json_encode([
            'success' => true,
            'break' => [
                'type' => $upcomingBreak['type'],
                'label' => $upcomingBreak['label'],
                'scheduled_time' => $scheduledTime,
                'start_time' => null,
                'countdown' => $countdown,
                'duration_minutes' => $upcomingBreak['duration_minutes'],
                'status' => 'upcoming',
            ]
        ]);
    }
} else {
    echo json_encode(['success' => true, 'break' => null]);
}
