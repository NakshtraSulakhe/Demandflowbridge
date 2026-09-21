<?php
require_once __DIR__ . '/../../includes/functions.php';

// This cron script should be run every minute to check for and start automated breaks
// Add to crontab: * * * * * php /path/to/modules/hr/break-automation-cron.php

function processAutomatedBreaks(): void {
    logBreakAutomation('INFO', 'Cron Started');
    
    $activeUsers = getActiveBreakAutomationUsers();
    if (empty($activeUsers)) {
        logBreakAutomation('INFO', 'No active users found');
        return;
    }
    
    logBreakAutomation('INFO', 'Found active users', ['count' => count($activeUsers)]);
    
    $now = new DateTime('now', hrDisplayTz());
    $currentTime = $now->format('H:i:s');
    $currentMinute = $now->format('H:i');
    $workDate = $now->format('Y-m-d');
    
    logBreakAutomation('INFO', 'Current time', ['time' => $currentTime, 'date' => $workDate]);
    
    foreach ($activeUsers as $user) {
        $userId = (int)($user['user_id'] ?? 0);
        if ($userId <= 0) continue;
        
        logBreakAutomation('INFO', 'Processing user', ['user_id' => $userId]);
        
        // Validate if user can start automated break
        $validation = canStartAutomatedBreak($userId, $workDate);
        if (!$validation['allowed']) {
            logBreakAutomation('INFO', 'Validation failed', ['user_id' => $userId, 'reason' => $validation['reason']]);
            continue;
        }
        
        logBreakAutomation('INFO', 'Validation passed', ['user_id' => $userId]);
        
        $breaks = [
            ['type' => 'team_break_1', 'time' => $user['team_break_1_time'] ?? null, 'duration' => (int)($user['team_break_1_duration'] ?? 15), 'state' => 'Break1'],
            ['type' => 'dinner', 'time' => $user['dinner_time'] ?? null, 'duration' => (int)($user['dinner_duration'] ?? 30), 'state' => 'Lunch'],
            ['type' => 'team_break_2', 'time' => $user['team_break_2_time'] ?? null, 'duration' => (int)($user['team_break_2_duration'] ?? 15), 'state' => 'Break2'],
        ];
        
        foreach ($breaks as $break) {
            if ($break['time'] === null) {
                logBreakAutomation('INFO', 'Break time not set', ['user_id' => $userId, 'break_type' => $break['type']]);
                continue;
            }
            
            logBreakAutomation('INFO', 'Checking break', ['user_id' => $userId, 'break_type' => $break['type'], 'scheduled_time' => $break['time']]);
            
            $scheduledRaw = trim((string)$break['time']);
            if ($scheduledRaw === '') {
                continue;
            }
            $scheduledMinute = substr($scheduledRaw, 0, 5);
            if (!preg_match('/^\d{2}:\d{2}$/', $scheduledMinute)) {
                logBreakAutomation('WARNING', 'Invalid scheduled break time', ['user_id' => $userId, 'break_type' => $break['type'], 'scheduled_time' => $scheduledRaw]);
                continue;
            }
            $scheduledAt = DateTime::createFromFormat('Y-m-d H:i:s', $workDate . ' ' . $scheduledMinute . ':00', hrDisplayTz());
            if (!$scheduledAt) {
                continue;
            }
            $windowEnd = (clone $scheduledAt)->modify('+5 minutes');
            
            // Start once within a short grace window so slightly delayed cron runs do not skip the break.
            if ($now >= $scheduledAt && $now <= $windowEnd) {
                logBreakAutomation('INFO', 'Break time matched', ['user_id' => $userId, 'break_type' => $break['type']]);
                
                // Check if session already exists for today
                $session = getTodayBreakSession($userId, $break['type'], $workDate);
                
                if (!$session) {
                    logBreakAutomation('INFO', 'Creating new session', ['user_id' => $userId, 'break_type' => $break['type']]);
                    // Create new session
                    createBreakSession($userId, $break['type'], $break['time'], $workDate);
                    $session = getTodayBreakSession($userId, $break['type'], $workDate);
                }
                
                if ($session && $session['status'] === 'scheduled') {
                    logBreakAutomation('INFO', 'Starting break', ['user_id' => $userId, 'break_type' => $break['type'], 'state' => $break['state']]);
                    
                    // Start the break using startAttendanceState
                    $result = startAttendanceState($userId, $break['state'], $now->format('Y-m-d H:i:s'));
                    
                    if ($result['ok']) {
                        logBreakAutomation('INFO', 'Break started successfully', ['user_id' => $userId, 'break_type' => $break['type']]);
                        // Update session status to started
                        updateBreakSessionStatus((int)($session['id'] ?? 0), 'started', $now->format('Y-m-d H:i:s'), null, (int)$break['duration']);
                    } else {
                        logBreakAutomation('ERROR', 'Failed to start break', ['user_id' => $userId, 'break_type' => $break['type'], 'error' => $result['error'] ?? 'Unknown error']);
                        updateBreakSessionStatus((int)($session['id'] ?? 0), 'failed');
                    }
                } elseif ($session && $session['status'] === 'started') {
                    logBreakAutomation('INFO', 'Break already started', ['user_id' => $userId, 'break_type' => $break['type']]);
                }
            } elseif ($currentMinute === $scheduledMinute) {
                logBreakAutomation('INFO', 'Break minute matched outside grace window', ['user_id' => $userId, 'break_type' => $break['type']]);
            }
        }
    }
    
    logBreakAutomation('INFO', 'Cron Completed');
}

// Run the process
try {
    processAutomatedBreaks();
} catch (Exception $e) {
    logBreakAutomation('ERROR', 'Cron exception', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
}
