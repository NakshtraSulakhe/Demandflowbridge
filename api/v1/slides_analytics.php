<?php
/**
 * DemandFlow Bridge - TV Slides & Analytics Master API
 * 
 * Provides real-time analytical feeds for the 5 TV presentation slides:
 * - Slide 1: Agent attendance KPI cards + Campaign-wise targets & leads + Late comers list.
 * - Slide 2: Agent target in points + Agent-wise generated, qualified, pending QA & remaining distribution.
 * - Slide 3: Quality team timewise heatmap (10-minute intervals across shift for each QA analyst).
 * - Slide 4: Agent generation timewise heatmap (10-minute intervals across shift for each agent).
 * - Slide 5: Comparative analytics (Agent lead generation vs QA auditing & qualification over time).
 * 
 * Query Parameters:
 * - action: 'slide1' | 'slide2' | 'slide3' | 'slide4' | 'slide5' | 'all' (default: 'all')
 * - date: 'YYYY-MM-DD' (default: today)
 * - interval: interval in minutes for heatmaps (default: 10)
 * - shift_start: 'HH:MM:SS' (default: from hr_shifts or '09:30:00')
 * - shift_end: 'HH:MM:SS' (default: from hr_shifts or '18:30:00')
 * - page: for slide 2 pagination (default: 1)
 * - limit: for slide 2 items per page (default: 10, or 'all')
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key, X-Webhook-Secret');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ini_set('display_errors', 0);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

// Ensure the daily target table exists
if (file_exists(__DIR__ . '/campaign_daily_targets.php')) {
    require_once __DIR__ . '/campaign_daily_targets.php';
}

/**
 * Generates an array of time intervals (e.g. 10-minute slots) between start and end time
 */
function generateTimeIntervals(string $startTime, string $endTime, int $intervalMinutes = 10): array {
    $intervals = [];
    $startTs = strtotime("1970-01-01 " . $startTime);
    $endTs = strtotime("1970-01-01 " . $endTime);

    if ($endTs <= $startTs) {
        $endTs = $startTs + (8 * 3600); // Fallback 8 hours
    }

    $step = max(1, $intervalMinutes) * 60;
    $idx = 0;

    for ($cur = $startTs; $cur < $endTs; $cur += $step) {
        $next = min($cur + $step, $endTs);
        $sStr = date('H:i', $cur);
        $eStr = date('H:i', $next);
        $intervals[] = [
            'slot_index' => $idx++,
            'key' => $sStr,
            'label' => "{$sStr} - {$eStr}",
            'start_time' => $sStr,
            'end_time' => $eStr,
            'start_ts_sec' => $cur,
            'end_ts_sec' => $next
        ];
    }
    return $intervals;
}

/**
 * Resolves standard shift hours from hr_shifts table or default
 */
function resolveShiftTimes(mysqli $conn, ?string $reqStart = null, ?string $reqEnd = null): array {
    $defStart = '09:30:00';
    $defEnd = '18:30:00';

    if (!empty($reqStart) && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $reqStart)) {
        $defStart = strlen($reqStart) === 5 ? $reqStart . ':00' : $reqStart;
    } else {
        $rs = $conn->query("SELECT start_time, end_time FROM hr_shifts WHERE active = 1 ORDER BY id ASC LIMIT 1");
        if ($rs && $row = $rs->fetch_assoc()) {
            if (!empty($row['start_time'])) $defStart = (string)$row['start_time'];
            if (!empty($row['end_time'])) $defEnd = (string)$row['end_time'];
        }
    }

    if (!empty($reqEnd) && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $reqEnd)) {
        $defEnd = strlen($reqEnd) === 5 ? $reqEnd . ':00' : $reqEnd;
    }

    return [$defStart, $defEnd];
}

/**
 * Helper: Find slot index for a given MySQL DATETIME/TIME string
 */
function findSlotIndex(string $datetimeStr, array $intervals): ?int {
    $timeOnly = substr($datetimeStr, 11, 5); // Extract "HH:MM"
    if (empty($timeOnly)) {
        $timeOnly = substr($datetimeStr, 0, 5);
    }
    $targetSec = strtotime("1970-01-01 " . $timeOnly . ":00");

    foreach ($intervals as $slot) {
        if ($targetSec >= $slot['start_ts_sec'] && $targetSec < $slot['end_ts_sec']) {
            return $slot['slot_index'];
        }
    }
    return null;
}

/**
 * Slide 1: Attendance KPI Cards + Live Campaign Table + Late Comers List
 */
function getSlide1Data(mysqli $conn, string $date): array {
    // 1. Fetch Agent Attendance & Late Comers
    $agentRoles = ['agent', 'operations_agent', 'email_marketing_agent', 'email_marketing_executive', 'form_filler'];
    $roleIn = "'" . implode("','", $agentRoles) . "'";

    // Total active agents
    $totalAgents = 0;
    $rsAgents = $conn->query("SELECT COUNT(*) AS c FROM users WHERE is_active = 1 AND role IN ($roleIn)");
    if ($rsAgents) {
        $totalAgents = (int)(($rsAgents->fetch_assoc() ?: [])['c'] ?? 0);
    }

    // Attendance stats for today
    $presentToday = 0;
    $absentToday = 0;
    $lateToday = 0;
    $lateComersList = [];

    $attQuery = "
        SELECT 
            u.id AS user_id,
            u.full_name,
            u.role,
            d.punch_in,
            d.punch_out,
            d.shift_start_time,
            COALESCE(d.grace_minutes, 15) AS grace_minutes,
            COALESCE(d.late_minutes, 0) AS late_minutes,
            COALESCE(d.status, 'Absent') AS status
        FROM users u
        LEFT JOIN hr_attendance_days d ON d.user_id = u.id AND d.work_date = ?
        WHERE u.is_active = 1 AND u.role IN ($roleIn)
        ORDER BY d.late_minutes DESC, u.full_name ASC
    ";

    $stmtAtt = $conn->prepare($attQuery);
    if ($stmtAtt) {
        $stmtAtt->bind_param('s', $date);
        $stmtAtt->execute();
        $resAtt = $stmtAtt->get_result();

        while ($row = $resAtt->fetch_assoc()) {
            $hasPunchedIn = !empty($row['punch_in']);
            $lateMin = (int)$row['late_minutes'];

            if ($hasPunchedIn) {
                $presentToday++;
                if ($lateMin > 0) {
                    $lateToday++;
                    $lateComersList[] = [
                        'user_id' => (int)$row['user_id'],
                        'full_name' => (string)$row['full_name'],
                        'role' => (string)$row['role'],
                        'punch_in' => (string)$row['punch_in'],
                        'shift_start_time' => (string)$row['shift_start_time'],
                        'grace_minutes' => (int)$row['grace_minutes'],
                        'late_minutes' => $lateMin,
                        'status' => (string)$row['status']
                    ];
                }
            } else {
                $absentToday++;
            }
        }
        $stmtAtt->close();
    }

    // 2. Fetch Live Campaign Table & Lead Progress
    $campQuery = "
        SELECT 
            c.id AS campaign_id,
            c.name AS campaign_name,
            d.code AS campaign_code,
            d.client_code,
            d.campaign_type,
            COALESCE(d.total_leads, 0) AS monthly_allocation,
            COALESCE(t.daily_target, 0) AS daily_target
        FROM campaigns c
        LEFT JOIN campaign_details d ON d.campaign_id = c.id
        LEFT JOIN campaign_daily_targets t ON t.campaign_id = c.id AND t.target_date = ?
        WHERE c.active = 1 AND (d.status = 'Live' OR d.status IS NULL)
        ORDER BY c.name ASC
    ";

    $campaigns = [];
    $stmtC = $conn->prepare($campQuery);
    if ($stmtC) {
        $stmtC->bind_param('s', $date);
        $stmtC->execute();
        $resC = $stmtC->get_result();

        // Lead counts grouped by campaign for this date
        $leadMap = [];
        $leadSql = "
            SELECT 
                campaign_id,
                COUNT(*) AS generated_leads,
                SUM(CASE WHEN qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified_leads,
                SUM(CASE WHEN qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS disqualified_leads,
                SUM(CASE WHEN qa_status NOT IN ('Qualified', 'Disqualified') OR qa_status IS NULL THEN 1 ELSE 0 END) AS pending_qa
            FROM leads
            WHERE DATE(created_at) = ?
            GROUP BY campaign_id
        ";
        $stmtL = $conn->prepare($leadSql);
        if ($stmtL) {
            $stmtL->bind_param('s', $date);
            $stmtL->execute();
            $resL = $stmtL->get_result();
            while ($lRow = $resL->fetch_assoc()) {
                $leadMap[(int)$lRow['campaign_id']] = $lRow;
            }
            $stmtL->close();
        }

        while ($c = $resC->fetch_assoc()) {
            $cid = (int)$c['campaign_id'];
            $l = $leadMap[$cid] ?? [
                'generated_leads' => 0,
                'qualified_leads' => 0,
                'disqualified_leads' => 0,
                'pending_qa' => 0
            ];

            $dailyTarget = (int)$c['daily_target'];
            $qualified = (int)$l['qualified_leads'];
            $generated = (int)$l['generated_leads'];
            $disqualified = (int)$l['disqualified_leads'];
            $pendingQa = (int)$l['pending_qa'];

            $remainingQualified = max(0, $dailyTarget - $qualified);
            $remainingGenerated = max(0, $dailyTarget - $generated);
            $compRate = $dailyTarget > 0 ? round(($qualified / $dailyTarget) * 100, 1) : 0.0;

            $campaigns[] = [
                'campaign_id' => $cid,
                'campaign_name' => (string)($c['campaign_name'] ?? ''),
                'campaign_code' => (string)($c['campaign_code'] ?? ''),
                'client_code' => (string)($c['client_code'] ?? ''),
                'campaign_type' => (string)($c['campaign_type'] ?? ''),
                'monthly_allocation' => (int)$c['monthly_allocation'],
                'daily_target' => $dailyTarget,
                'generated_leads' => $generated,
                'qualified_leads' => $qualified,
                'disqualified_leads' => $disqualified,
                'pending_qa' => $pendingQa,
                'remaining_leads_to_qualify' => $remainingQualified,
                'remaining_leads_to_generate' => $remainingGenerated,
                'completion_percentage' => $compRate
            ];
        }
        $stmtC->close();
    }

    return [
        'kpi_cards' => [
            'total_agents' => $totalAgents,
            'present_today' => $presentToday,
            'absent_today' => $absentToday,
            'late_today' => $lateToday
        ],
        'campaigns_count' => count($campaigns),
        'campaigns' => $campaigns,
        'late_comers_count' => count($lateComersList),
        'late_comers' => $lateComersList
    ];
}

/**
 * Slide 2: Agent Daily Target in Points & Distribution (Supports rotating pagination)
 */
function getSlide2Data(mysqli $conn, string $date, int $page = 1, $limit = 10): array {
    $yy = (int)date('Y', strtotime($date));
    $mm = (int)date('m', strtotime($date));

    $agentRoles = ['agent', 'operations_agent', 'email_marketing_agent', 'email_marketing_executive', 'form_filler'];
    $roleIn = "'" . implode("','", $agentRoles) . "'";

    // 1. Fetch all agents and their targets
    $agentQuery = "
        SELECT 
            u.id AS agent_id,
            u.full_name AS agent_name,
            u.role,
            COALESCE(pt.daily_target, 0) AS daily_target_points,
            COALESCE(pt.monthly_target, 0) AS monthly_target_points,
            COALESCE(pt.working_days, 22) AS working_days
        FROM users u
        LEFT JOIN productivity_targets pt ON pt.agent_id = u.id AND pt.year = ? AND pt.month = ?
        WHERE u.is_active = 1 AND u.role IN ($roleIn)
        ORDER BY u.full_name ASC
    ";

    $agentsList = [];
    $stmt = $conn->prepare($agentQuery);
    if ($stmt) {
        $stmt->bind_param('ii', $yy, $mm);
        $stmt->execute();
        $res = $stmt->get_result();

        // 2. Fetch today's leads by agent with campaign details for points factor
        $leadsQuery = "
            SELECT 
                l.agent_id,
                l.qa_status,
                d.campaign_type
            FROM leads l
            LEFT JOIN campaign_details d ON d.campaign_id = l.campaign_id
            WHERE DATE(l.created_at) = ?
        ";
        $stmtL = $conn->prepare($leadsQuery);
        $agentLeads = [];
        if ($stmtL) {
            $stmtL->bind_param('s', $date);
            $stmtL->execute();
            $resL = $stmtL->get_result();
            while ($lr = $resL->fetch_assoc()) {
                $aid = (int)$lr['agent_id'];
                if (!isset($agentLeads[$aid])) {
                    $agentLeads[$aid] = [
                        'generated' => 0,
                        'qualified' => 0,
                        'disqualified' => 0,
                        'pending_qa' => 0,
                        'achieved_points' => 0.0
                    ];
                }
                $agentLeads[$aid]['generated']++;

                $st = (string)($lr['qa_status'] ?? '');
                if ($st === 'Qualified') {
                    $agentLeads[$aid]['qualified']++;
                    // Calculate points factor
                    $ct = function_exists('normalizeProductivityCampaignType')
                        ? normalizeProductivityCampaignType((string)($lr['campaign_type'] ?? ''))
                        : (string)($lr['campaign_type'] ?? '');
                    $factor = function_exists('campaignTypeMqlFactor') ? campaignTypeMqlFactor($ct) : 1.0;
                    if ($factor <= 0) $factor = 1.0;
                    $agentLeads[$aid]['achieved_points'] += $factor;
                } elseif ($st === 'Disqualified') {
                    $agentLeads[$aid]['disqualified']++;
                } else {
                    $agentLeads[$aid]['pending_qa']++;
                }
            }
            $stmtL->close();
        }

        while ($a = $res->fetch_assoc()) {
            $aid = (int)$a['agent_id'];
            $st = $agentLeads[$aid] ?? [
                'generated' => 0,
                'qualified' => 0,
                'disqualified' => 0,
                'pending_qa' => 0,
                'achieved_points' => 0.0
            ];

            $dailyPointsTarget = (float)$a['daily_target_points'];
            $achievedPoints = round((float)$st['achieved_points'], 2);
            $remainingPoints = max(0.0, round($dailyPointsTarget - $achievedPoints, 2));

            $compRate = $dailyPointsTarget > 0 ? round(($achievedPoints / $dailyPointsTarget) * 100, 1) : 0.0;
            $targetMet = $dailyPointsTarget > 0 && $achievedPoints >= $dailyPointsTarget;

            $agentsList[] = [
                'agent_id' => $aid,
                'agent_name' => (string)$a['agent_name'],
                'role' => (string)$a['role'],
                'daily_target_points' => $dailyPointsTarget,
                'working_days' => (int)$a['working_days'],
                'monthly_target_points' => (float)$a['monthly_target_points'],
                'generated_leads' => (int)$st['generated'],
                'qualified_leads' => (int)$st['qualified'],
                'disqualified_leads' => (int)$st['disqualified'],
                'pending_qa' => (int)$st['pending_qa'],
                'achieved_points' => $achievedPoints,
                'remaining_points' => $remainingPoints,
                'completion_percentage' => $compRate,
                'is_target_met' => $targetMet
            ];
        }
        $stmt->close();
    }

    $totalAgents = count($agentsList);

    // Handle pagination / rotation
    $isAll = ($limit === 'all' || (int)$limit <= 0);
    $limitNum = $isAll ? $totalAgents : max(1, (int)$limit);
    $totalPages = $limitNum > 0 ? (int)ceil($totalAgents / $limitNum) : 1;
    $curPage = max(1, min($page, $totalPages));
    $offset = ($curPage - 1) * $limitNum;

    $pagedAgents = $isAll ? $agentsList : array_slice($agentsList, $offset, $limitNum);

    return [
        'total_agents' => $totalAgents,
        'page' => $curPage,
        'limit' => $isAll ? 'all' : $limitNum,
        'total_pages' => $totalPages,
        'agents' => $pagedAgents
    ];
}

/**
 * Slide 3: Quality Team Timewise Heatmap (10-minute intervals across shift)
 */
function getSlide3Data(mysqli $conn, string $date, int $intervalMinutes = 10, ?string $shiftStart = null, ?string $shiftEnd = null): array {
    [$sStart, $sEnd] = resolveShiftTimes($conn, $shiftStart, $shiftEnd);
    $intervals = generateTimeIntervals($sStart, $sEnd, $intervalMinutes);

    // Find all active QA analysts
    $qaUsers = [];
    $rs = $conn->query("
        SELECT id, full_name, email, role 
        FROM users 
        WHERE is_active = 1 AND (role LIKE 'qa%' OR role = 'quality_analyst')
        ORDER BY full_name ASC
    ");
    if ($rs) {
        while ($r = $rs->fetch_assoc()) {
            $qaUsers[(int)$r['id']] = [
                'user_id' => (int)$r['id'],
                'full_name' => (string)$r['full_name'],
                'email' => (string)$r['email'],
                'total_qualified' => 0,
                'total_audited' => 0,
                'timeline' => array_fill(0, count($intervals), ['qualified_count' => 0, 'audited_count' => 0])
            ];
        }
    }

    // Also include any user who reviewed leads today (e.g. leads audited by admin/team lead)
    $stmtExtra = $conn->prepare("
        SELECT DISTINCT u.id, u.full_name, u.email, u.role
        FROM qa_audit_logs q
        JOIN users u ON u.id = q.qa_reviewed_by
        WHERE DATE(q.reviewed_at) = ?
    ");
    if ($stmtExtra) {
        $stmtExtra->bind_param('s', $date);
        $stmtExtra->execute();
        $resExtra = $stmtExtra->get_result();
        while ($r = $resExtra->fetch_assoc()) {
            $uid = (int)$r['id'];
            if (!isset($qaUsers[$uid])) {
                $qaUsers[$uid] = [
                    'user_id' => $uid,
                    'full_name' => (string)$r['full_name'],
                    'email' => (string)$r['email'],
                    'total_qualified' => 0,
                    'total_audited' => 0,
                    'timeline' => array_fill(0, count($intervals), ['qualified_count' => 0, 'audited_count' => 0])
                ];
            }
        }
        $stmtExtra->close();
    }

    // Query QA audit logs for the selected date
    $stmtLogs = $conn->prepare("
        SELECT qa_reviewed_by, qa_status, reviewed_at
        FROM qa_audit_logs
        WHERE DATE(reviewed_at) = ? AND qa_reviewed_by IS NOT NULL
        ORDER BY reviewed_at ASC
    ");
    if ($stmtLogs) {
        $stmtLogs->bind_param('s', $date);
        $stmtLogs->execute();
        $resLogs = $stmtLogs->get_result();

        while ($log = $resLogs->fetch_assoc()) {
            $revId = (int)$log['qa_reviewed_by'];
            if (!isset($qaUsers[$revId])) continue;

            $revAt = (string)$log['reviewed_at'];
            $slotIdx = findSlotIndex($revAt, $intervals);

            $isQual = ($log['qa_status'] === 'Qualified');
            $qaUsers[$revId]['total_audited']++;
            if ($isQual) {
                $qaUsers[$revId]['total_qualified']++;
            }

            if ($slotIdx !== null && isset($qaUsers[$revId]['timeline'][$slotIdx])) {
                $qaUsers[$revId]['timeline'][$slotIdx]['audited_count']++;
                if ($isQual) {
                    $qaUsers[$revId]['timeline'][$slotIdx]['qualified_count']++;
                }
            }
        }
        $stmtLogs->close();
    }

    // Flatten timeline array with interval keys for easy rendering
    $formattedQaList = [];
    foreach ($qaUsers as $qa) {
        $slots = [];
        foreach ($intervals as $slot) {
            $idx = $slot['slot_index'];
            $stats = $qa['timeline'][$idx] ?? ['qualified_count' => 0, 'audited_count' => 0];
            $slots[] = [
                'slot_index' => $idx,
                'time_label' => $slot['label'],
                'start_time' => $slot['start_time'],
                'qualified_count' => $stats['qualified_count'],
                'audited_count' => $stats['audited_count']
            ];
        }
        $qa['intervals'] = $slots;
        unset($qa['timeline']);
        $formattedQaList[] = $qa;
    }

    return [
        'date' => $date,
        'shift_start' => $sStart,
        'shift_end' => $sEnd,
        'interval_minutes' => $intervalMinutes,
        'total_intervals' => count($intervals),
        'intervals_meta' => array_map(fn($i) => ['slot_index' => $i['slot_index'], 'label' => $i['label'], 'start_time' => $i['start_time'], 'end_time' => $i['end_time']], $intervals),
        'total_qa_analysts' => count($formattedQaList),
        'qa_heatmap' => $formattedQaList
    ];
}

/**
 * Slide 4: Agent Lead Generation Timewise Heatmap (10-minute intervals across shift)
 */
function getSlide4Data(mysqli $conn, string $date, int $intervalMinutes = 10, ?string $shiftStart = null, ?string $shiftEnd = null): array {
    [$sStart, $sEnd] = resolveShiftTimes($conn, $shiftStart, $shiftEnd);
    $intervals = generateTimeIntervals($sStart, $sEnd, $intervalMinutes);

    $agentRoles = ['agent', 'operations_agent', 'email_marketing_agent', 'email_marketing_executive', 'form_filler'];
    $roleIn = "'" . implode("','", $agentRoles) . "'";

    // Pre-populate active agents
    $agents = [];
    $rs = $conn->query("
        SELECT id, full_name, email, role 
        FROM users 
        WHERE is_active = 1 AND role IN ($roleIn)
        ORDER BY full_name ASC
    ");
    if ($rs) {
        while ($r = $rs->fetch_assoc()) {
            $agents[(int)$r['id']] = [
                'agent_id' => (int)$r['id'],
                'agent_name' => (string)$r['full_name'],
                'role' => (string)$r['role'],
                'total_generated' => 0,
                'timeline' => array_fill(0, count($intervals), 0)
            ];
        }
    }

    // Query leads created today
    $stmt = $conn->prepare("
        SELECT agent_id, agent_name, created_at
        FROM leads
        WHERE DATE(created_at) = ?
        ORDER BY created_at ASC
    ");
    if ($stmt) {
        $stmt->bind_param('s', $date);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($lead = $res->fetch_assoc()) {
            $aid = (int)$lead['agent_id'];
            if (!isset($agents[$aid])) {
                $agents[$aid] = [
                    'agent_id' => $aid,
                    'agent_name' => (string)($lead['agent_name'] ?: "Agent #{$aid}"),
                    'role' => 'agent',
                    'total_generated' => 0,
                    'timeline' => array_fill(0, count($intervals), 0)
                ];
            }

            $agents[$aid]['total_generated']++;
            $slotIdx = findSlotIndex((string)$lead['created_at'], $intervals);
            if ($slotIdx !== null && isset($agents[$aid]['timeline'][$slotIdx])) {
                $agents[$aid]['timeline'][$slotIdx]++;
            }
        }
        $stmt->close();
    }

    // Format agent rows
    $formattedAgents = [];
    foreach ($agents as $a) {
        $slots = [];
        foreach ($intervals as $slot) {
            $idx = $slot['slot_index'];
            $slots[] = [
                'slot_index' => $idx,
                'time_label' => $slot['label'],
                'start_time' => $slot['start_time'],
                'generated_count' => $a['timeline'][$idx] ?? 0
            ];
        }
        $a['intervals'] = $slots;
        unset($a['timeline']);
        $formattedAgents[] = $a;
    }

    return [
        'date' => $date,
        'shift_start' => $sStart,
        'shift_end' => $sEnd,
        'interval_minutes' => $intervalMinutes,
        'total_intervals' => count($intervals),
        'intervals_meta' => array_map(fn($i) => ['slot_index' => $i['slot_index'], 'label' => $i['label'], 'start_time' => $i['start_time'], 'end_time' => $i['end_time']], $intervals),
        'total_agents' => count($formattedAgents),
        'agent_heatmap' => $formattedAgents
    ];
}

/**
 * Slide 5: Comparative Analytics (Generation vs QA Auditing & Qualification Timeline)
 */
function getSlide5Data(mysqli $conn, string $date, int $intervalMinutes = 10, ?string $shiftStart = null, ?string $shiftEnd = null): array {
    [$sStart, $sEnd] = resolveShiftTimes($conn, $shiftStart, $shiftEnd);
    $intervals = generateTimeIntervals($sStart, $sEnd, $intervalMinutes);

    $slotsData = [];
    foreach ($intervals as $slot) {
        $slotsData[$slot['slot_index']] = [
            'slot_index' => $slot['slot_index'],
            'time_label' => $slot['label'],
            'start_time' => $slot['start_time'],
            'end_time' => $slot['end_time'],
            'leads_generated' => 0,
            'leads_audited' => 0,
            'leads_qualified' => 0,
            'leads_disqualified' => 0,
            'pending_backlog_delta' => 0,
            'cumulative_generated' => 0,
            'cumulative_audited' => 0,
            'cumulative_qualified' => 0
        ];
    }

    // 1. Leads Generated counts per interval
    $stmtL = $conn->prepare("SELECT created_at FROM leads WHERE DATE(created_at) = ?");
    if ($stmtL) {
        $stmtL->bind_param('s', $date);
        $stmtL->execute();
        $resL = $stmtL->get_result();
        while ($row = $resL->fetch_assoc()) {
            $slotIdx = findSlotIndex((string)$row['created_at'], $intervals);
            if ($slotIdx !== null && isset($slotsData[$slotIdx])) {
                $slotsData[$slotIdx]['leads_generated']++;
            }
        }
        $stmtL->close();
    }

    // 2. QA Audits & Qualification counts per interval
    $stmtQ = $conn->prepare("SELECT qa_status, reviewed_at FROM qa_audit_logs WHERE DATE(reviewed_at) = ?");
    if ($stmtQ) {
        $stmtQ->bind_param('s', $date);
        $stmtQ->execute();
        $resQ = $stmtQ->get_result();
        while ($row = $resQ->fetch_assoc()) {
            $slotIdx = findSlotIndex((string)$row['reviewed_at'], $intervals);
            if ($slotIdx !== null && isset($slotsData[$slotIdx])) {
                $slotsData[$slotIdx]['leads_audited']++;
                if ($row['qa_status'] === 'Qualified') {
                    $slotsData[$slotIdx]['leads_qualified']++;
                } elseif ($row['qa_status'] === 'Disqualified') {
                    $slotsData[$slotIdx]['leads_disqualified']++;
                }
            }
        }
        $stmtQ->close();
    }

    // 3. Compute running cumulative metrics and lag
    $totGen = 0;
    $totAud = 0;
    $totQual = 0;
    $totDisqual = 0;

    foreach ($slotsData as &$slot) {
        $totGen += $slot['leads_generated'];
        $totAud += $slot['leads_audited'];
        $totQual += $slot['leads_qualified'];
        $totDisqual += $slot['leads_disqualified'];

        $slot['cumulative_generated'] = $totGen;
        $slot['cumulative_audited'] = $totAud;
        $slot['cumulative_qualified'] = $totQual;
        $slot['pending_backlog_delta'] = $slot['leads_generated'] - $slot['leads_audited'];
    }
    unset($slot);

    $qualificationRate = $totAud > 0 ? round(($totQual / $totAud) * 100, 1) : 0.0;
    $auditRate = $totGen > 0 ? round(($totAud / $totGen) * 100, 1) : 0.0;

    return [
        'date' => $date,
        'shift_start' => $sStart,
        'shift_end' => $sEnd,
        'interval_minutes' => $intervalMinutes,
        'summary' => [
            'total_leads_generated' => $totGen,
            'total_leads_audited' => $totAud,
            'total_leads_qualified' => $totQual,
            'total_leads_disqualified' => $totDisqual,
            'pending_audit_gap' => max(0, $totGen - $totAud),
            'audit_turnaround_rate' => $auditRate,
            'qualification_approval_rate' => $qualificationRate
        ],
        'comparison_timeline' => array_values($slotsData)
    ];
}

/**
 * Live Recognition & Announcements: Top Leads Scored, Birthdays, Work Anniversaries
 */
function getRecognitionData(mysqli $conn, string $date, string $scope = 'daily'): array {
    $todayMd = substr($date, 5, 5);

    // 1. Today's Target Achievers (Calculated strictly from leads & productivity targets for $date)
    $yy = (int)date('Y', strtotime($date));
    $mm = (int)date('m', strtotime($date));

    $agentRoles = ['agent', 'operations_agent', 'email_marketing_agent', 'email_marketing_executive', 'form_filler'];
    $roleIn = "'" . implode("','", $agentRoles) . "'";

    $agentTargetQuery = "
        SELECT 
            u.id AS user_id,
            u.full_name,
            u.role,
            u.department,
            u.profile_pic,
            COALESCE(pt.daily_target, 0) AS daily_target_points
        FROM users u
        LEFT JOIN productivity_targets pt ON pt.agent_id = u.id AND pt.year = {$yy} AND pt.month = {$mm}
        WHERE u.is_active = 1 AND u.role IN ($roleIn)
    ";
    $rsAgents = $conn->query($agentTargetQuery);
    $allAgents = $rsAgents ? $rsAgents->fetch_all(MYSQLI_ASSOC) : [];

    $leadsTodayQuery = "
        SELECT 
            l.agent_id,
            l.qa_status,
            d.campaign_type
        FROM leads l
        LEFT JOIN campaign_details d ON d.campaign_id = l.campaign_id
        WHERE DATE(l.created_at) = '{$date}'
    ";
    $rsLeads = $conn->query($leadsTodayQuery);
    $agentTodayStats = [];
    if ($rsLeads) {
        while ($lr = $rsLeads->fetch_assoc()) {
            $aid = (int)$lr['agent_id'];
            if (!isset($agentTodayStats[$aid])) {
                $agentTodayStats[$aid] = [
                    'generated' => 0,
                    'qualified' => 0,
                    'achieved_points' => 0.0
                ];
            }
            $agentTodayStats[$aid]['generated']++;
            if ((string)($lr['qa_status'] ?? '') === 'Qualified') {
                $agentTodayStats[$aid]['qualified']++;
                $ct = function_exists('normalizeProductivityCampaignType')
                    ? normalizeProductivityCampaignType((string)($lr['campaign_type'] ?? ''))
                    : (string)($lr['campaign_type'] ?? '');
                $factor = function_exists('campaignTypeMqlFactor') ? campaignTypeMqlFactor($ct) : 1.0;
                if ($factor <= 0) $factor = 1.0;
                $agentTodayStats[$aid]['achieved_points'] += $factor;
            }
        }
    }

    $scoredAgents = [];
    foreach ($allAgents as $ag) {
        $aid = (int)$ag['user_id'];
        $stats = $agentTodayStats[$aid] ?? ['generated' => 0, 'qualified' => 0, 'achieved_points' => 0.0];
        $dailyTarget = (float)$ag['daily_target_points'];
        $achievedPts = round((float)$stats['achieved_points'], 2);
        $gen = (int)$stats['generated'];
        $qual = (int)$stats['qualified'];

        if ($gen > 0 || $achievedPts > 0) {
            $compRate = $dailyTarget > 0 ? round(($achievedPts / $dailyTarget) * 100, 1) : ($achievedPts > 0 ? 100.0 : 0.0);
            $targetMet = ($dailyTarget > 0 && $achievedPts >= $dailyTarget) || ($dailyTarget == 0 && $achievedPts > 0);

            $scoredAgents[] = [
                'user_id' => $aid,
                'full_name' => $ag['full_name'],
                'role' => $ag['role'],
                'department' => $ag['department'],
                'profile_pic' => $ag['profile_pic'],
                'daily_target' => $dailyTarget,
                'achieved_points' => $achievedPts,
                'generated_leads' => $gen,
                'qualified_leads' => $qual,
                'completion_percentage' => $compRate,
                'is_target_met' => $targetMet
            ];
        }
    }

    usort($scoredAgents, function ($a, $b) {
        if ($a['is_target_met'] !== $b['is_target_met']) {
            return $a['is_target_met'] ? -1 : 1;
        }
        if ($a['completion_percentage'] != $b['completion_percentage']) {
            return ($a['completion_percentage'] > $b['completion_percentage']) ? -1 : 1;
        }
        return ($a['achieved_points'] > $b['achieved_points']) ? -1 : 1;
    });

    $topScorers = array_slice($scoredAgents, 0, 6);

    // 2. Birthdays (Daily basis: only birthdays occurring today; weekly: next 7 days; monthly: this month)
    $bdayWhereScope = "AND DATE_FORMAT(COALESCE(upd.date_of_birth, u.date_of_birth), '%m-%d') = '{$todayMd}'";
    if ($scope === 'weekly') {
        $bdayWhereScope = "AND (DATE_FORMAT(COALESCE(upd.date_of_birth, u.date_of_birth), '%m-%d') BETWEEN '{$todayMd}' AND DATE_FORMAT(DATE_ADD('{$date}', INTERVAL 7 DAY), '%m-%d'))";
    } elseif ($scope === 'monthly') {
        $month = substr($date, 5, 2);
        $bdayWhereScope = "AND DATE_FORMAT(COALESCE(upd.date_of_birth, u.date_of_birth), '%m') = '{$month}'";
    }

    $bdayQuery = "
        SELECT 
            u.id AS user_id,
            u.full_name,
            u.role,
            u.department,
            u.profile_pic,
            COALESCE(upd.date_of_birth, u.date_of_birth) AS date_of_birth
        FROM users u
        LEFT JOIN user_personal_details upd ON upd.user_id = u.id
        WHERE u.is_active = 1 
          AND (upd.date_of_birth IS NOT NULL OR u.date_of_birth IS NOT NULL)
          {$bdayWhereScope}
        ORDER BY 
            (DATE_FORMAT(COALESCE(upd.date_of_birth, u.date_of_birth), '%m-%d') = '{$todayMd}') DESC,
            DATE_FORMAT(COALESCE(upd.date_of_birth, u.date_of_birth), '%m-%d') ASC
        LIMIT 6
    ";
    $rsBday = $conn->query($bdayQuery);
    $birthdays = $rsBday ? $rsBday->fetch_all(MYSQLI_ASSOC) : [];

    // 3. Work Anniversaries (Daily basis: only anniversaries occurring today; weekly: next 7 days; monthly: this month)
    $anniWhereScope = "AND DATE_FORMAT(u.date_of_joining, '%m-%d') = '{$todayMd}'";
    if ($scope === 'weekly') {
        $anniWhereScope = "AND (DATE_FORMAT(u.date_of_joining, '%m-%d') BETWEEN '{$todayMd}' AND DATE_FORMAT(DATE_ADD('{$date}', INTERVAL 7 DAY), '%m-%d'))";
    } elseif ($scope === 'monthly') {
        $month = substr($date, 5, 2);
        $anniWhereScope = "AND DATE_FORMAT(u.date_of_joining, '%m') = '{$month}'";
    }

    $anniQuery = "
        SELECT 
            u.id AS user_id,
            u.full_name,
            u.role,
            u.department,
            u.profile_pic,
            u.date_of_joining,
            TIMESTAMPDIFF(YEAR, u.date_of_joining, '{$date}') AS years_completed
        FROM users u
        WHERE u.is_active = 1 
          AND u.date_of_joining IS NOT NULL
          AND u.date_of_joining <= '{$date}'
          {$anniWhereScope}
        ORDER BY 
            (DATE_FORMAT(u.date_of_joining, '%m-%d') = '{$todayMd}') DESC,
            years_completed DESC
        LIMIT 6
    ";
    $rsAnni = $conn->query($anniQuery);
    $anniversaries = $rsAnni ? $rsAnni->fetch_all(MYSQLI_ASSOC) : [];

    // Format unified ticker items for smooth auto-scroller display
    $tickerItems = [];
    $rank = 1;
    foreach ($topScorers as $agent) {
        $qual = (int)($agent['qualified_leads'] ?? 0);
        $gen = (int)($agent['generated_leads'] ?? 0);
        $achievedPts = (float)$agent['achieved_points'];
        $targetPts = (float)$agent['daily_target'];
        $compRate = (float)$agent['completion_percentage'];
        $isMet = !empty($agent['is_target_met']);

        $medal = $rank === 1 ? '🥇' : ($rank === 2 ? '🥈' : ($rank === 3 ? '🥉' : '⭐'));
        $badgeText = $isMet
            ? "{$medal} Target Achiever ({$compRate}%)"
            : "{$medal} Rank #{$rank} Target Pace ({$compRate}%)";

        $eyebrow = $isMet ? "Today's Target Achiever" : "Today's Target Pace";
        $subtitle = $targetPts > 0
            ? "{$achievedPts} / {$targetPts} Pts Achieved • {$qual} Qualified Leads Today"
            : "{$achievedPts} Points Achieved • {$qual} Qualified Leads Today";

        $tickerItems[] = [
            'id' => 'target-achiever-' . $agent['user_id'],
            'type' => 'top_lead_scorer',
            'icon' => 'trophy',
            'badge' => $badgeText,
            'eyebrow' => $eyebrow,
            'title' => $agent['full_name'],
            'subtitle' => $subtitle,
            'message' => $isMet 
                ? "Daily quota target successfully achieved today! Outstanding execution."
                : "Leading today's productivity race with {$achievedPts} verified points.",
            'accent' => $isMet ? 'emerald' : 'amber',
            'user_id' => (int)$agent['user_id'],
            'profile_pic' => $agent['profile_pic'] ?? '',
            'score' => $achievedPts,
            'rank' => $rank,
            'completion_percentage' => $compRate,
            'is_target_met' => $isMet
        ];
        $rank++;
    }

    foreach ($birthdays as $bday) {
        $dob = $bday['date_of_birth'] ?? '';
        $isToday = substr($dob, 5, 5) === $todayMd;
        $badgeText = $isToday ? '🎂 Birthday Today!' : '🎈 Birthday Celebration';
        $tickerItems[] = [
            'id' => 'bday-' . $bday['user_id'],
            'type' => 'birthday',
            'icon' => 'cake',
            'badge' => $badgeText,
            'eyebrow' => 'Birthday Celebration',
            'title' => 'Happy Birthday, ' . $bday['full_name'] . '!',
            'subtitle' => ($bday['department'] ? $bday['department'] . ' Team' : 'Team Member'),
            'message' => 'Wishing you a joyful celebration and a year full of achievements!',
            'accent' => 'rose',
            'user_id' => (int)$bday['user_id'],
            'profile_pic' => $bday['profile_pic'] ?? '',
            'is_today' => $isToday
        ];
    }

    foreach ($anniversaries as $anni) {
        $doj = $anni['date_of_joining'] ?? '';
        $years = max(1, (int)($anni['years_completed'] ?? 1));
        $isToday = substr($doj, 5, 5) === $todayMd;
        $badgeText = $isToday ? "🎖️ {$years} Year" . ($years > 1 ? 's' : '') . " Anniversary Today!" : "🌟 {$years} Year" . ($years > 1 ? 's' : '') . " Milestone";
        $tickerItems[] = [
            'id' => 'anni-' . $anni['user_id'],
            'type' => 'anniversary',
            'icon' => 'award',
            'badge' => $badgeText,
            'eyebrow' => 'Work Anniversary',
            'title' => 'Happy Work Anniversary, ' . $anni['full_name'] . '!',
            'subtitle' => "{$years} Year" . ($years > 1 ? 's' : '') . " of Excellence & Dedication",
            'message' => "Thank you for your valuable dedication, consistency, and hard work.",
            'accent' => 'cyan',
            'user_id' => (int)$anni['user_id'],
            'profile_pic' => $anni['profile_pic'] ?? '',
            'years' => $years,
            'is_today' => $isToday
        ];
    }

    return [
        'target_date' => $date,
        'total_items' => count($tickerItems),
        'top_scorers_count' => count($topScorers),
        'birthdays_count' => count($birthdays),
        'anniversaries_count' => count($anniversaries),
        'top_scorers' => $topScorers,
        'birthdays' => $birthdays,
        'anniversaries' => $anniversaries,
        'ticker_items' => $tickerItems
    ];
}

// Controller routing
try {
    $conn = getDbConnection();

    $action = isset($_GET['action']) ? strtolower(trim((string)$_GET['action'])) : 'all';
    $todayDate = (new DateTimeImmutable('now', hrBaseTz()))->format('Y-m-d');
    $date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date'])
        ? (string)$_GET['date']
        : $todayDate;

    $interval = isset($_GET['interval']) ? max(1, (int)$_GET['interval']) : 10;
    $shiftStart = isset($_GET['shift_start']) ? trim((string)$_GET['shift_start']) : null;
    $shiftEnd = isset($_GET['shift_end']) ? trim((string)$_GET['shift_end']) : null;

    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? (strtolower($_GET['limit']) === 'all' ? 'all' : max(1, (int)$_GET['limit'])) : 10;

        $scope = isset($_GET['scope']) && in_array(strtolower(trim((string)$_GET['scope'])), ['daily', 'weekly', 'monthly'], true)
        ? strtolower(trim((string)$_GET['scope']))
        : 'daily';

    $slidersData = getRecognitionData($conn, $date, $scope);

    $response = [
        'ok' => true,
        'action' => $action,
        'query_date' => $date,
        'generated_at' => (new DateTimeImmutable('now', hrBaseTz()))->format('Y-m-d H:i:s'),
        'sliders' => $slidersData,
        'recognition' => $slidersData,
    ];

    switch ($action) {
        case 'slide1':
            $response['slide1'] = getSlide1Data($conn, $date);
            $response['slide1']['sliders'] = $slidersData;
            break;

        case 'slide2':
            $response['slide2'] = getSlide2Data($conn, $date, $page, $limit);
            $response['slide2']['sliders'] = $slidersData;
            break;

        case 'slide3':
            $response['slide3'] = getSlide3Data($conn, $date, $interval, $shiftStart, $shiftEnd);
            $response['slide3']['sliders'] = $slidersData;
            break;

        case 'slide4':
            $response['slide4'] = getSlide4Data($conn, $date, $interval, $shiftStart, $shiftEnd);
            $response['slide4']['sliders'] = $slidersData;
            break;

        case 'recognition':
            // Already present in $response['sliders'] and $response['recognition']
            break;

        case 'slide5':
            $response['slide5'] = getSlide5Data($conn, $date, $interval, $shiftStart, $shiftEnd);
            $response['slide5']['sliders'] = $slidersData;
            break;

        case 'all':
        default:
            $response['slide1'] = getSlide1Data($conn, $date);
            $response['slide1']['sliders'] = $slidersData;
            $response['slide2'] = getSlide2Data($conn, $date, $page, $limit);
            $response['slide2']['sliders'] = $slidersData;
            $response['slide3'] = getSlide3Data($conn, $date, $interval, $shiftStart, $shiftEnd);
            $response['slide3']['sliders'] = $slidersData;
            $response['slide4'] = getSlide4Data($conn, $date, $interval, $shiftStart, $shiftEnd);
            $response['slide4']['sliders'] = $slidersData;
            $response['slide5'] = getSlide5Data($conn, $date, $interval, $shiftStart, $shiftEnd);
            $response['slide5']['sliders'] = $slidersData;
            break;
    }

    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
