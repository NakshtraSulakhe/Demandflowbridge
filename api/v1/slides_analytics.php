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
                l.form_done,
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
                        'forms_submitted' => 0,
                        'achieved_points' => 0.0
                    ];
                }
                $agentLeads[$aid]['generated']++;

                $fd = strtolower(trim((string)($lr['form_done'] ?? 'no')));
                if ($fd === 'yes' || $fd === '1' || $fd === 'true') {
                    $agentLeads[$aid]['forms_submitted']++;
                }

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
                'forms_submitted' => 0,
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
                'forms_submitted' => (int)($st['forms_submitted'] ?? 0),
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

    $formSubmissionsMeta = function_exists('getFormSubmissionsData') ? getFormSubmissionsData($conn, $date) : ['total_forms_submitted' => 0, 'by_submitter' => []];

    return [
        'total_agents' => $totalAgents,
        'page' => $curPage,
        'limit' => $isAll ? 'all' : $limitNum,
        'total_pages' => $totalPages,
        'total_forms_submitted' => $formSubmissionsMeta['total_forms_submitted'] ?? 0,
        'forms_submitted_by' => $formSubmissionsMeta['by_submitter'] ?? [],
        'agents' => $pagedAgents
    ];
}

/**
 * Slide 3: Quality Team Timewise Heatmap (10-minute intervals across shift)
 */
function getSlide3Data(mysqli $conn, string $date, int $intervalMinutes = 10, ?string $shiftStart = null, ?string $shiftEnd = null): array {
    [$sStart, $sEnd] = resolveShiftTimes($conn, $shiftStart, $shiftEnd);
    $intervals = generateTimeIntervals($sStart, $sEnd, $intervalMinutes);

    $qaUsers = [];

    // 1. Gather all actual QA reviewers who performed audits for this date from qa_audit_logs via qa_reviewed_by
    $stmtExtra = $conn->prepare("
        SELECT DISTINCT u.id, u.full_name, u.email, u.role
        FROM qa_audit_logs q
        JOIN users u ON u.id = q.qa_reviewed_by
        WHERE DATE(q.reviewed_at) = ? 
          AND q.qa_reviewed_by IS NOT NULL 
          AND q.qa_reviewed_by > 0
          AND (u.department LIKE '%Quality%' OR u.department LIKE '%QA%' OR u.role LIKE 'qa%' OR u.role = 'quality_analyst')
          AND u.id != 99 
          AND u.full_name NOT LIKE '%Sagar%'
    ");
    if ($stmtExtra) {
        $stmtExtra->bind_param('s', $date);
        $stmtExtra->execute();
        $resExtra = $stmtExtra->get_result();
        while ($r = $resExtra->fetch_assoc()) {
            $uid = (int)$r['id'];
            $qaUsers[$uid] = [
                'user_id' => $uid,
                'full_name' => (string)$r['full_name'],
                'email' => (string)$r['email'],
                'total_qualified' => 0,
                'total_audited' => 0,
                'timeline' => array_fill(0, count($intervals), ['qualified_count' => 0, 'audited_count' => 0])
            ];
        }
        $stmtExtra->close();
    }

    // 2. Also gather actual QA reviewers from leads table where qa_reviewed_by is recorded
    $stmtLeads = $conn->prepare("
        SELECT DISTINCT u.id, u.full_name, u.email, u.role
        FROM leads l
        JOIN users u ON u.id = l.qa_reviewed_by
        WHERE (DATE(l.qa_updated_at) = ? OR (l.qa_updated_at IS NULL AND DATE(l.updated_at) = ?))
          AND l.qa_reviewed_by IS NOT NULL 
          AND l.qa_reviewed_by > 0
          AND (u.department LIKE '%Quality%' OR u.department LIKE '%QA%' OR u.role LIKE 'qa%' OR u.role = 'quality_analyst')
          AND u.id != 99 
          AND u.full_name NOT LIKE '%Sagar%'
    ");
    if ($stmtLeads) {
        $stmtLeads->bind_param('ss', $date, $date);
        $stmtLeads->execute();
        $resLeads = $stmtLeads->get_result();
        while ($r = $resLeads->fetch_assoc()) {
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
        $stmtLeads->close();
    }

    // 3. Query QA audit logs for the selected date to populate intervals
    $stmtLogs = $conn->prepare("
        SELECT qa_reviewed_by, qa_status, reviewed_at
        FROM qa_audit_logs
        WHERE DATE(reviewed_at) = ? AND qa_reviewed_by IS NOT NULL AND qa_reviewed_by > 0
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

    // 4. Also check leads table for any reviews if audit logs had no records for today
    $totalAuditsFromLogs = array_sum(array_column($qaUsers, 'total_audited'));
    if ($totalAuditsFromLogs === 0 && !empty($qaUsers)) {
        $stmtLeadsLogs = $conn->prepare("
            SELECT qa_reviewed_by, qa_status, COALESCE(qa_updated_at, updated_at) AS reviewed_at
            FROM leads
            WHERE (DATE(qa_updated_at) = ? OR (qa_updated_at IS NULL AND DATE(updated_at) = ?))
              AND qa_reviewed_by IS NOT NULL AND qa_reviewed_by > 0
            ORDER BY reviewed_at ASC
        ");
        if ($stmtLeadsLogs) {
            $stmtLeadsLogs->bind_param('ss', $date, $date);
            $stmtLeadsLogs->execute();
            $resLeadsLogs = $stmtLeadsLogs->get_result();
            while ($leadRow = $resLeadsLogs->fetch_assoc()) {
                $revId = (int)$leadRow['qa_reviewed_by'];
                if (!isset($qaUsers[$revId])) continue;

                $revAt = (string)$leadRow['reviewed_at'];
                $slotIdx = findSlotIndex($revAt, $intervals);

                $isQual = ($leadRow['qa_status'] === 'Qualified');
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
            $stmtLeadsLogs->close();
        }
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

    // Filter out any analyst with 0 audits so unnecessary names are omitted
    $formattedQaList = array_values(array_filter($formattedQaList, function($qa) {
        if (($qa['total_audited'] ?? 0) <= 0) return false;
        if (($qa['user_id'] ?? 0) == 99) return false;
        if (stripos($qa['full_name'] ?? '', 'sagar') !== false) return false;
        return true;
    }));
    // Sort descending by total audited
    usort($formattedQaList, fn($a, $b) => ($b['total_audited'] ?? 0) <=> ($a['total_audited'] ?? 0));

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
 * Fetches form submission metrics: total count, who submitted them, timestamps, and interval timeline.
 * Cross-references `form_submissions`, `lead_activity`, and `leads` (form_done='Yes').
 */
function getFormSubmissionsData(mysqli $conn, string $date, ?array $intervals = null): array {
    $submissionsByLead = [];

    // 1. Check form_submissions table
    try {
        $stmtFs = $conn->prepare("
            SELECT 
                fs.id AS submission_id,
                fs.lead_id,
                l.lead_id AS lead_reference,
                fs.campaign_id,
                c.name AS campaign_name,
                fs.submitted_by,
                u.full_name AS submitter_name,
                u.role AS submitter_role,
                fs.submitted_at
            FROM form_submissions fs
            LEFT JOIN users u ON u.id = fs.submitted_by
            LEFT JOIN leads l ON l.id = fs.lead_id
            LEFT JOIN campaigns c ON c.id = fs.campaign_id
            WHERE DATE(fs.submitted_at) = ?
            ORDER BY fs.submitted_at ASC
        ");
        if ($stmtFs) {
            $stmtFs->bind_param('s', $date);
            $stmtFs->execute();
            $resFs = $stmtFs->get_result();
            while ($row = $resFs->fetch_assoc()) {
                $lid = (int)($row['lead_id'] ?? 0);
                $key = $lid > 0 ? "id_{$lid}" : "sub_{$row['submission_id']}";
                $submissionsByLead[$key] = [
                    'lead_db_id' => $lid > 0 ? $lid : null,
                    'lead_id' => (string)($row['lead_reference'] ?: ($lid > 0 ? (string)$lid : "SUB-{$row['submission_id']}")),
                    'campaign_id' => isset($row['campaign_id']) ? (int)$row['campaign_id'] : null,
                    'campaign_name' => (string)($row['campaign_name'] ?? ''),
                    'submitted_by' => (int)($row['submitted_by'] ?? 0),
                    'submitter_name' => (string)($row['submitter_name'] ?: 'System / Agent'),
                    'submitter_role' => (string)($row['submitter_role'] ?: 'agent'),
                    'form_done' => 'Yes',
                    'submitted_at' => (string)$row['submitted_at'],
                    'source' => 'form_submissions'
                ];
            }
            $stmtFs->close();
        }
    } catch (Throwable $e) {}

    // 2. Check lead_activity table for form_submission_saved or form_done updates
    try {
        $stmtLa = $conn->prepare("
            SELECT 
                la.id AS activity_id,
                la.lead_id,
                l.lead_id AS lead_reference,
                l.campaign_id,
                c.name AS campaign_name,
                la.actor_id AS submitted_by,
                u.full_name AS submitter_name,
                u.role AS submitter_role,
                la.created_at AS submitted_at,
                la.action,
                la.meta_json
            FROM lead_activity la
            LEFT JOIN users u ON u.id = la.actor_id
            LEFT JOIN leads l ON l.id = la.lead_id
            LEFT JOIN campaigns c ON c.id = l.campaign_id
            WHERE DATE(la.created_at) = ?
              AND (
                  la.action = 'form_submission_saved' 
                  OR (la.action = 'lead_updated' AND (la.meta_json LIKE '%form_done%' OR la.meta_json LIKE '%form_filled%'))
              )
            ORDER BY la.created_at ASC
        ");
        if ($stmtLa) {
            $stmtLa->bind_param('s', $date);
            $stmtLa->execute();
            $resLa = $stmtLa->get_result();
            while ($row = $resLa->fetch_assoc()) {
                $lid = (int)($row['lead_id'] ?? 0);
                $key = "id_{$lid}";
                if (!isset($submissionsByLead[$key])) {
                    $submissionsByLead[$key] = [
                        'lead_db_id' => $lid,
                        'lead_id' => (string)($row['lead_reference'] ?: (string)$lid),
                        'campaign_id' => isset($row['campaign_id']) ? (int)$row['campaign_id'] : null,
                        'campaign_name' => (string)($row['campaign_name'] ?? ''),
                        'submitted_by' => (int)($row['submitted_by'] ?? 0),
                        'submitter_name' => (string)($row['submitter_name'] ?: 'System / Agent'),
                        'submitter_role' => (string)($row['submitter_role'] ?: 'agent'),
                        'form_done' => 'Yes',
                        'submitted_at' => (string)$row['submitted_at'],
                        'source' => 'lead_activity'
                    ];
                } elseif (!empty($row['submitted_by']) && empty($submissionsByLead[$key]['submitted_by'])) {
                    $submissionsByLead[$key]['submitted_by'] = (int)$row['submitted_by'];
                    $submissionsByLead[$key]['submitter_name'] = (string)($row['submitter_name'] ?: $submissionsByLead[$key]['submitter_name']);
                    $submissionsByLead[$key]['submitter_role'] = (string)($row['submitter_role'] ?: $submissionsByLead[$key]['submitter_role']);
                }
            }
            $stmtLa->close();
        }
    } catch (Throwable $e) {}

    // 3. Check leads table for any leads marked form_done = 'Yes' today
    try {
        $stmtL = $conn->prepare("
            SELECT 
                l.id AS lead_db_id,
                l.lead_id AS lead_reference,
                l.campaign_id,
                c.name AS campaign_name,
                COALESCE(l.updated_by, l.agent_id) AS submitted_by,
                COALESCE(u.full_name, l.agent_name) AS submitter_name,
                u.role AS submitter_role,
                COALESCE(l.form_filled_time, l.updated_at, l.created_at) AS submitted_at,
                l.form_done
            FROM leads l
            LEFT JOIN users u ON u.id = COALESCE(l.updated_by, l.agent_id)
            LEFT JOIN campaigns c ON c.id = l.campaign_id
            WHERE (DATE(l.form_filled_time) = ? OR (l.form_filled_time IS NULL AND DATE(l.created_at) = ?))
              AND (l.form_done = 'Yes' OR l.form_done = '1' OR LOWER(l.form_done) = 'true')
            ORDER BY submitted_at ASC
        ");
        if ($stmtL) {
            $stmtL->bind_param('ss', $date, $date);
            $stmtL->execute();
            $resL = $stmtL->get_result();
            while ($row = $resL->fetch_assoc()) {
                $lid = (int)($row['lead_db_id'] ?? 0);
                $key = "id_{$lid}";
                if (!isset($submissionsByLead[$key])) {
                    $submissionsByLead[$key] = [
                        'lead_db_id' => $lid,
                        'lead_id' => (string)($row['lead_reference'] ?: (string)$lid),
                        'campaign_id' => isset($row['campaign_id']) ? (int)$row['campaign_id'] : null,
                        'campaign_name' => (string)($row['campaign_name'] ?? ''),
                        'submitted_by' => (int)($row['submitted_by'] ?? 0),
                        'submitter_name' => (string)($row['submitter_name'] ?: 'Agent'),
                        'submitter_role' => (string)($row['submitter_role'] ?: 'agent'),
                        'form_done' => 'Yes',
                        'submitted_at' => (string)$row['submitted_at'],
                        'source' => 'leads'
                    ];
                }
            }
            $stmtL->close();
        }
    } catch (Throwable $e) {}

    // Aggregate by submitter
    $bySubmitterMap = [];
    $allSubmissionsList = array_values($submissionsByLead);

    // Slot-based interval timeline if intervals provided
    $intervalTimeline = [];
    if (!empty($intervals)) {
        foreach ($intervals as $slot) {
            $intervalTimeline[$slot['slot_index']] = [
                'slot_index' => $slot['slot_index'],
                'label' => $slot['label'],
                'start_time' => $slot['start_time'],
                'end_time' => $slot['end_time'],
                'forms_submitted' => 0
            ];
        }
    }

    foreach ($allSubmissionsList as $sub) {
        $subId = (int)$sub['submitted_by'];
        $subName = $sub['submitter_name'];
        $subRole = $sub['submitter_role'];

        if (!isset($bySubmitterMap[$subId])) {
            $bySubmitterMap[$subId] = [
                'user_id' => $subId > 0 ? $subId : null,
                'name' => $subName,
                'role' => $subRole,
                'forms_submitted_count' => 0,
                'leads' => []
            ];
        }

        $bySubmitterMap[$subId]['forms_submitted_count']++;
        $bySubmitterMap[$subId]['leads'][] = [
            'lead_id' => $sub['lead_id'],
            'lead_db_id' => $sub['lead_db_id'],
            'campaign_id' => $sub['campaign_id'],
            'campaign_name' => $sub['campaign_name'],
            'form_done' => $sub['form_done'],
            'submitted_at' => $sub['submitted_at'],
            'source' => $sub['source']
        ];

        // Slot timeline
        if (!empty($intervals) && !empty($sub['submitted_at'])) {
            $slotIdx = findSlotIndex((string)$sub['submitted_at'], $intervals);
            if ($slotIdx !== null && isset($intervalTimeline[$slotIdx])) {
                $intervalTimeline[$slotIdx]['forms_submitted']++;
            }
        }
    }

    // Sort submitters descending by count
    $bySubmitterList = array_values($bySubmitterMap);
    usort($bySubmitterList, fn($a, $b) => ($b['forms_submitted_count'] ?? 0) <=> ($a['forms_submitted_count'] ?? 0));

    // Sort recent submissions descending by time
    usort($allSubmissionsList, function($a, $b) {
        return strcmp((string)($b['submitted_at'] ?? ''), (string)($a['submitted_at'] ?? ''));
    });

    return [
        'date' => $date,
        'total_forms_submitted' => count($allSubmissionsList),
        'total_submitters' => count($bySubmitterList),
        'by_submitter' => $bySubmitterList,
        'recent_submissions' => array_slice($allSubmissionsList, 0, 25),
        'timeline' => array_values($intervalTimeline)
    ];
}

/**
 * Ensures qa_incentive_plans table exists in MySQL
 */
function ensureQaIncentiveSchema(mysqli $conn): void {
    static $schemaChecked = false;
    if ($schemaChecked) return;

    $conn->query("
        CREATE TABLE IF NOT EXISTS `qa_incentive_plans` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(255) NOT NULL,
            `qa_user_ids` TEXT NULL COMMENT 'JSON array of user IDs or null for all',
            `client_code` VARCHAR(100) NULL COMMENT 'Specific client code or null for all',
            `campaign_ids` TEXT NULL COMMENT 'JSON array of campaign IDs or null for all',
            `apply_all_campaigns` TINYINT(1) DEFAULT 1,
            `frequency` ENUM('daily', 'monthly') DEFAULT 'daily',
            `base_target` INT NOT NULL DEFAULT 50,
            `base_reward` DECIMAL(10,2) NOT NULL DEFAULT 500.00,
            `tiers_json` TEXT NULL COMMENT 'JSON array of tier slabs [{min, max, rate_per_lead}]',
            `is_active` TINYINT(1) DEFAULT 1,
            `created_by` INT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_frequency` (`frequency`),
            INDEX `idx_client_code` (`client_code`),
            INDEX `idx_is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $schemaChecked = true;
}

/**
 * DemandFlow Bridge - Fetches QA Team Members & Their Daily/Monthly Audited Leads Breakdown
 * 
 * Used by Slides App & Admin Portal to display QA audit volume and calculate
 * incentives according to Base Target + Tier Volume Accelerators.
 */
function getQaDailyAuditsData(mysqli $conn, array $params = []): array {
    ensureQaIncentiveSchema($conn);

    $tz = function_exists('hrBaseTz') ? hrBaseTz() : new DateTimeZone('Asia/Kolkata');
    $now = new DateTimeImmutable('now', $tz);
    $defaultDate = $now->format('Y-m-d');

    // 1. Resolve Date Range & Frequency
    $frequency = strtolower(trim((string)($params['frequency'] ?? 'daily')));
    if (!in_array($frequency, ['daily', 'monthly', 'custom'], true)) {
        $frequency = 'daily';
    }

    $dateFrom = '';
    $dateTo = '';

    if (!empty($params['date_from']) || !empty($params['date_to'])) {
        $dateFrom = trim((string)($params['date_from'] ?? $defaultDate));
        $dateTo = trim((string)($params['date_to'] ?? $dateFrom));
        if ($dateTo < $dateFrom) {
            $tmp = $dateFrom;
            $dateFrom = $dateTo;
            $dateTo = $tmp;
        }
        $frequency = 'custom';
    } elseif ($frequency === 'monthly') {
        $year = isset($params['year']) ? (int)$params['year'] : (int)$now->format('Y');
        $month = isset($params['month']) ? (int)$params['month'] : (int)$now->format('n');
        $dateFrom = sprintf('%04d-%02d-01', $year, $month);
        $dateTo = (new DateTimeImmutable($dateFrom, $tz))->modify('last day of this month')->format('Y-m-d');
    } else {
        $dateReq = trim((string)($params['date'] ?? ''));
        if ($dateReq !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateReq)) {
            $dateFrom = $dateReq;
            $dateTo = $dateReq;
        } else {
            $dateFrom = $defaultDate;
            $dateTo = $defaultDate;
        }
    }

    $startDt = $dateFrom . ' 00:00:00';
    $endDt = $dateTo . ' 23:59:59';

    // 2. Filters
    $filterQaId = isset($params['qa_id']) ? (int)$params['qa_id'] : (isset($params['user_id']) ? (int)$params['user_id'] : 0);
    $filterClientCode = strtoupper(trim((string)($params['client_code'] ?? '')));
    $filterCampaignId = isset($params['campaign_id']) ? (int)$params['campaign_id'] : 0;
    $filterStatus = trim((string)($params['status'] ?? ''));
    $includeLeads = !isset($params['include_leads']) || filter_var($params['include_leads'], FILTER_VALIDATE_BOOLEAN);

    // 3. Optional Incentive Plan Configuration / Simulation parameters
    $baseTarget = isset($params['base_target']) ? max(0, (int)$params['base_target']) : null;
    $baseReward = isset($params['base_reward']) ? max(0.0, (float)$params['base_reward']) : null;
    $tiers = [];
    if (!empty($params['tiers'])) {
        $rawTiers = is_array($params['tiers']) ? $params['tiers'] : json_decode((string)$params['tiers'], true);
        if (is_array($rawTiers)) $tiers = $rawTiers;
    }

    // Check if a saved plan ID was specified
    $planId = isset($params['plan_id']) ? (int)$params['plan_id'] : 0;
    $activePlan = null;
    if ($planId > 0) {
        $stPlan = $conn->prepare("SELECT * FROM qa_incentive_plans WHERE id = ? LIMIT 1");
        if ($stPlan) {
            $stPlan->bind_param('i', $planId);
            $stPlan->execute();
            $activePlan = $stPlan->get_result()->fetch_assoc();
            $stPlan->close();
        }
    }
    if ($activePlan) {
        if ($baseTarget === null) $baseTarget = (int)($activePlan['base_target'] ?? 50);
        if ($baseReward === null) $baseReward = (float)($activePlan['base_reward'] ?? 500.0);
        if (empty($tiers) && !empty($activePlan['tiers_json'])) {
            $tj = json_decode((string)$activePlan['tiers_json'], true);
            if (is_array($tj)) $tiers = $tj;
        }
    }

    // 4. Gather QA Team Members
    $qaUsers = [];

    // Query active QA staff from users table
    $qaUsersQuery = "
        SELECT id, full_name, email, role, department, job_title, avatar_path, is_active
        FROM users
        WHERE (
            department LIKE '%Quality%' 
            OR department LIKE '%QA%' 
            OR role LIKE 'qa%' 
            OR role = 'quality_analyst'
            OR role IN ('qa', 'qa_analyst', 'qa_lead', 'qa_manager', 'quality_manager')
        )
        AND id != 99 AND full_name NOT LIKE '%Sagar%'
    ";
    if ($filterQaId > 0) {
        $qaUsersQuery .= " AND id = " . $filterQaId;
    }
    $qaUsersQuery .= " ORDER BY full_name ASC";

    $resU = $conn->query($qaUsersQuery);
    if ($resU) {
        while ($u = $resU->fetch_assoc()) {
            $uid = (int)$u['id'];
            $qaUsers[$uid] = [
                'user_id' => $uid,
                'qa_id' => $uid,
                'id' => $uid,
                'qa_name' => (string)$u['full_name'],
                'full_name' => (string)$u['full_name'],
                'name' => (string)$u['full_name'],
                'email' => (string)$u['email'],
                'role' => (string)$u['role'],
                'department' => (string)($u['department'] ?? 'Quality Assurance'),
                'job_title' => (string)($u['job_title'] ?? 'Quality Analyst'),
                'avatar' => !empty($u['avatar_path']) ? (string)$u['avatar_path'] : null,
                'is_active' => (bool)$u['is_active'],
                'total_audited' => 0,
                'unique_leads_audited' => 0,
                'total_audit_actions' => 0,
                'audited_count' => 0,
                'qualified_count' => 0,
                'qualified_leads' => 0,
                'qualified' => 0,
                'disqualified_count' => 0,
                'disqualified_leads' => 0,
                'disqualified' => 0,
                'pending_count' => 0,
                'pending' => 0,
                'rework_count' => 0,
                'other_count' => 0,
                'qualification_rate_pct' => 0.0,
                'qualification_rate' => 0.0,
                'disqualification_rate_pct' => 0.0,
                'status_breakdown' => [],
                'campaigns_breakdown' => [],
                'campaigns' => [],
                'clients_breakdown' => [],
                'clients' => [],
                'audited_leads' => [],
                'leads' => [],
                '_seen_lead_ids' => [],
                '_campaign_map' => [],
                '_client_map' => [],
            ];
        }
    }

    // Also include any users who recorded audits in qa_audit_logs during the period
    $stmtExtraQ = $conn->prepare("
        SELECT DISTINCT u.id, u.full_name, u.email, u.role, u.department, u.job_title, u.avatar_path, u.is_active
        FROM qa_audit_logs q
        JOIN users u ON u.id = q.qa_reviewed_by
        WHERE q.reviewed_at BETWEEN ? AND ?
          AND q.qa_reviewed_by IS NOT NULL AND q.qa_reviewed_by > 0
          AND u.id != 99 AND u.full_name NOT LIKE '%Sagar%'
    " . ($filterQaId > 0 ? " AND u.id = " . $filterQaId : ""));
    if ($stmtExtraQ) {
        $stmtExtraQ->bind_param('ss', $startDt, $endDt);
        $stmtExtraQ->execute();
        $resExtra = $stmtExtraQ->get_result();
        while ($u = $resExtra->fetch_assoc()) {
            $uid = (int)$u['id'];
            if (!isset($qaUsers[$uid])) {
                $qaUsers[$uid] = [
                    'user_id' => $uid,
                    'qa_id' => $uid,
                    'id' => $uid,
                    'qa_name' => (string)$u['full_name'],
                    'full_name' => (string)$u['full_name'],
                    'name' => (string)$u['full_name'],
                    'email' => (string)$u['email'],
                    'role' => (string)$u['role'],
                    'department' => (string)($u['department'] ?? 'Quality Assurance'),
                    'job_title' => (string)($u['job_title'] ?? 'Quality Analyst'),
                    'avatar' => !empty($u['avatar_path']) ? (string)$u['avatar_path'] : null,
                    'is_active' => (bool)$u['is_active'],
                    'total_audited' => 0,
                    'unique_leads_audited' => 0,
                    'total_audit_actions' => 0,
                    'audited_count' => 0,
                    'qualified_count' => 0,
                    'qualified_leads' => 0,
                    'qualified' => 0,
                    'disqualified_count' => 0,
                    'disqualified_leads' => 0,
                    'disqualified' => 0,
                    'pending_count' => 0,
                    'pending' => 0,
                    'rework_count' => 0,
                    'other_count' => 0,
                    'qualification_rate_pct' => 0.0,
                    'qualification_rate' => 0.0,
                    'disqualification_rate_pct' => 0.0,
                    'status_breakdown' => [],
                    'campaigns_breakdown' => [],
                    'campaigns' => [],
                    'clients_breakdown' => [],
                    'clients' => [],
                    'audited_leads' => [],
                    'leads' => [],
                    '_seen_lead_ids' => [],
                    '_campaign_map' => [],
                    '_client_map' => [],
                ];
            }
        }
        $stmtExtraQ->close();
    }

    // 5. Query Audit Logs
    $auditLogsSql = "
        SELECT 
            q.id AS log_id,
            q.lead_id,
            l.lead_id AS lead_code,
            q.prev_status,
            q.qa_status,
            q.qa_comment,
            q.qa_client_comment,
            q.client_delivery_status,
            q.qa_reviewed_by,
            q.reviewed_at,
            COALESCE(q.campaign_id, l.campaign_id, 0) AS campaign_id,
            COALESCE(c.name, 'Unassigned Campaign') AS campaign_name,
            COALESCE(d.code, '') AS campaign_code,
            COALESCE(NULLIF(TRIM(d.client_code), ''), NULLIF(TRIM(cl.client_code), ''), 'N/A') AS client_code,
            COALESCE(d.client_id, cl.id, 0) AS client_id,
            COALESCE(cl.name, d.client_code, 'N/A') AS client_name,
            l.agent_id,
            u_agent.full_name AS agent_name
        FROM qa_audit_logs q
        LEFT JOIN leads l ON l.id = q.lead_id
        LEFT JOIN campaigns c ON c.id = COALESCE(q.campaign_id, l.campaign_id)
        LEFT JOIN campaign_details d ON d.campaign_id = c.id
        LEFT JOIN clients cl ON cl.id = d.client_id
        LEFT JOIN users u_agent ON u_agent.id = l.agent_id
        WHERE q.reviewed_at BETWEEN ? AND ?
          AND q.qa_reviewed_by IS NOT NULL 
          AND q.qa_reviewed_by > 0
    ";
    $logParams = [$startDt, $endDt];
    $logTypes = "ss";

    if ($filterQaId > 0) {
        $auditLogsSql .= " AND q.qa_reviewed_by = ?";
        $logParams[] = $filterQaId;
        $logTypes .= "i";
    }
    if ($filterCampaignId > 0) {
        $auditLogsSql .= " AND COALESCE(q.campaign_id, l.campaign_id) = ?";
        $logParams[] = $filterCampaignId;
        $logTypes .= "i";
    }
    if ($filterClientCode !== '') {
        $auditLogsSql .= " AND (UPPER(d.client_code) = ? OR UPPER(cl.client_code) = ?)";
        $logParams[] = $filterClientCode;
        $logParams[] = $filterClientCode;
        $logTypes .= "ss";
    }
    if ($filterStatus !== '') {
        $auditLogsSql .= " AND LOWER(q.qa_status) = LOWER(?)";
        $logParams[] = $filterStatus;
        $logTypes .= "s";
    }

    $auditLogsSql .= " ORDER BY q.reviewed_at DESC, q.id DESC";

    $auditRows = [];
    $stmtLogs = $conn->prepare($auditLogsSql);
    if ($stmtLogs) {
        if (function_exists('mysqliBindParams')) {
            mysqliBindParams($stmtLogs, $logTypes, $logParams);
        } else {
            $stmtLogs->bind_param($logTypes, ...$logParams);
        }
        $stmtLogs->execute();
        $resLogs = $stmtLogs->get_result();
        while ($row = $resLogs->fetch_assoc()) {
            $auditRows[] = $row;
        }
        $stmtLogs->close();
    }

    // Fallback to leads table if audit logs had no rows for this range
    if (empty($auditRows)) {
        $leadsAuditSql = "
            SELECT 
                l.id AS log_id,
                l.id AS lead_id,
                l.lead_id AS lead_code,
                NULL AS prev_status,
                l.qa_status,
                l.qa_comment,
                l.qa_client_comment,
                l.client_delivery_status,
                l.qa_reviewed_by,
                COALESCE(l.qa_updated_at, l.updated_at) AS reviewed_at,
                COALESCE(l.campaign_id, 0) AS campaign_id,
                COALESCE(c.name, 'Unassigned Campaign') AS campaign_name,
                COALESCE(d.code, '') AS campaign_code,
                COALESCE(NULLIF(TRIM(d.client_code), ''), NULLIF(TRIM(cl.client_code), ''), 'N/A') AS client_code,
                COALESCE(d.client_id, cl.id, 0) AS client_id,
                COALESCE(cl.name, d.client_code, 'N/A') AS client_name,
                l.agent_id,
                u_agent.full_name AS agent_name
            FROM leads l
            LEFT JOIN campaigns c ON c.id = l.campaign_id
            LEFT JOIN campaign_details d ON d.campaign_id = c.id
            LEFT JOIN clients cl ON cl.id = d.client_id
            LEFT JOIN users u_agent ON u_agent.id = l.agent_id
            WHERE (l.qa_updated_at BETWEEN ? AND ? OR (l.qa_updated_at IS NULL AND l.updated_at BETWEEN ? AND ?))
              AND l.qa_reviewed_by IS NOT NULL 
              AND l.qa_reviewed_by > 0
        ";
        $leadAuditParams = [$startDt, $endDt, $startDt, $endDt];
        $leadAuditTypes = "ssss";

        if ($filterQaId > 0) {
            $leadsAuditSql .= " AND l.qa_reviewed_by = ?";
            $leadAuditParams[] = $filterQaId;
            $leadAuditTypes .= "i";
        }
        if ($filterCampaignId > 0) {
            $leadsAuditSql .= " AND l.campaign_id = ?";
            $leadAuditParams[] = $filterCampaignId;
            $leadAuditTypes .= "i";
        }
        if ($filterClientCode !== '') {
            $leadsAuditSql .= " AND (UPPER(d.client_code) = ? OR UPPER(cl.client_code) = ?)";
            $leadAuditParams[] = $filterClientCode;
            $leadAuditParams[] = $filterClientCode;
            $leadAuditTypes .= "ss";
        }
        if ($filterStatus !== '') {
            $leadsAuditSql .= " AND LOWER(l.qa_status) = LOWER(?)";
            $leadAuditParams[] = $filterStatus;
            $leadAuditTypes .= "s";
        }

        $leadsAuditSql .= " ORDER BY reviewed_at DESC, l.id DESC";

        $stmtLA = $conn->prepare($leadsAuditSql);
        if ($stmtLA) {
            if (function_exists('mysqliBindParams')) {
                mysqliBindParams($stmtLA, $leadAuditTypes, $leadAuditParams);
            } else {
                $stmtLA->bind_param($leadAuditTypes, ...$leadAuditParams);
            }
            $stmtLA->execute();
            $resLA = $stmtLA->get_result();
            while ($row = $resLA->fetch_assoc()) {
                $auditRows[] = $row;
            }
            $stmtLA->close();
        }
    }

    // 6. Aggregate Audit Data per QA Analyst
    $totalTeamAudits = 0;
    $totalTeamQualified = 0;
    $totalTeamDisqualified = 0;
    $statusTotals = [];

    foreach ($auditRows as $row) {
        $revId = (int)$row['qa_reviewed_by'];
        if ($revId <= 0) continue;

        if (!isset($qaUsers[$revId])) {
            $qaUsers[$revId] = [
                'user_id' => $revId,
                'qa_id' => $revId,
                'id' => $revId,
                'qa_name' => "QA Analyst #{$revId}",
                'full_name' => "QA Analyst #{$revId}",
                'name' => "QA Analyst #{$revId}",
                'email' => '',
                'role' => 'quality_analyst',
                'department' => 'Quality Assurance',
                'job_title' => 'Quality Analyst',
                'avatar' => null,
                'is_active' => true,
                'total_audited' => 0,
                'unique_leads_audited' => 0,
                'total_audit_actions' => 0,
                'audited_count' => 0,
                'qualified_count' => 0,
                'qualified_leads' => 0,
                'qualified' => 0,
                'disqualified_count' => 0,
                'disqualified_leads' => 0,
                'disqualified' => 0,
                'pending_count' => 0,
                'pending' => 0,
                'rework_count' => 0,
                'other_count' => 0,
                'qualification_rate_pct' => 0.0,
                'qualification_rate' => 0.0,
                'disqualification_rate_pct' => 0.0,
                'status_breakdown' => [],
                'campaigns_breakdown' => [],
                'campaigns' => [],
                'clients_breakdown' => [],
                'clients' => [],
                'audited_leads' => [],
                'leads' => [],
                '_seen_lead_ids' => [],
                '_campaign_map' => [],
                '_client_map' => [],
            ];
        }

        $rawStatus = (string)($row['qa_status'] ?? 'Pending');
        $normStatus = function_exists('normalizeQaStatus') ? normalizeQaStatus($rawStatus) : ucfirst(strtolower($rawStatus));
        if ($normStatus === '') $normStatus = 'Pending';

        $leadNumericId = (int)($row['lead_id'] ?? 0);
        $qaUsers[$revId]['total_audit_actions']++;

        $isNewUniqueLead = !isset($qaUsers[$revId]['_seen_lead_ids'][$leadNumericId]);
        if ($isNewUniqueLead) {
            $qaUsers[$revId]['_seen_lead_ids'][$leadNumericId] = true;
            $qaUsers[$revId]['unique_leads_audited']++;
            $qaUsers[$revId]['total_audited']++;
            $qaUsers[$revId]['audited_count']++;
        }

        if (!isset($qaUsers[$revId]['status_breakdown'][$normStatus])) {
            $qaUsers[$revId]['status_breakdown'][$normStatus] = 0;
        }
        $qaUsers[$revId]['status_breakdown'][$normStatus]++;

        if (!isset($statusTotals[$normStatus])) {
            $statusTotals[$normStatus] = 0;
        }
        $statusTotals[$normStatus]++;

        $statusLower = strtolower($normStatus);
        if ($statusLower === 'qualified') {
            $qaUsers[$revId]['qualified_count']++;
            $qaUsers[$revId]['qualified_leads']++;
            $qaUsers[$revId]['qualified']++;
            $totalTeamQualified++;
        } elseif ($statusLower === 'disqualified') {
            $qaUsers[$revId]['disqualified_count']++;
            $qaUsers[$revId]['disqualified_leads']++;
            $qaUsers[$revId]['disqualified']++;
            $totalTeamDisqualified++;
        } elseif (in_array($statusLower, ['pending', 'in review', 'under review'], true)) {
            $qaUsers[$revId]['pending_count']++;
            $qaUsers[$revId]['pending']++;
        } elseif (in_array($statusLower, ['rework needed', 'rework', 'correction needed'], true)) {
            $qaUsers[$revId]['rework_count']++;
        } else {
            $qaUsers[$revId]['other_count']++;
        }

        $totalTeamAudits++;

        // Campaign breakdown
        $campId = (int)$row['campaign_id'];
        $campCode = (string)($row['campaign_code'] ?? '');
        $campName = (string)($row['campaign_name'] ?? '');
        $clientCode = (string)($row['client_code'] ?? 'N/A');

        if (!isset($qaUsers[$revId]['_campaign_map'][$campId])) {
            $qaUsers[$revId]['_campaign_map'][$campId] = [
                'campaign_id' => $campId,
                'campaign_name' => $campName,
                'campaign_code' => $campCode,
                'client_code' => $clientCode,
                'total_audited' => 0,
                'qualified' => 0,
                'disqualified' => 0,
                'status_breakdown' => [],
            ];
        }
        $qaUsers[$revId]['_campaign_map'][$campId]['total_audited']++;
        if ($statusLower === 'qualified') $qaUsers[$revId]['_campaign_map'][$campId]['qualified']++;
        if ($statusLower === 'disqualified') $qaUsers[$revId]['_campaign_map'][$campId]['disqualified']++;
        if (!isset($qaUsers[$revId]['_campaign_map'][$campId]['status_breakdown'][$normStatus])) {
            $qaUsers[$revId]['_campaign_map'][$campId]['status_breakdown'][$normStatus] = 0;
        }
        $qaUsers[$revId]['_campaign_map'][$campId]['status_breakdown'][$normStatus]++;

        // Client breakdown
        if (!isset($qaUsers[$revId]['_client_map'][$clientCode])) {
            $qaUsers[$revId]['_client_map'][$clientCode] = [
                'client_code' => $clientCode,
                'client_id' => (int)($row['client_id'] ?? 0),
                'client_name' => (string)($row['client_name'] ?? $clientCode),
                'total_audited' => 0,
                'qualified' => 0,
                'disqualified' => 0,
            ];
        }
        $qaUsers[$revId]['_client_map'][$clientCode]['total_audited']++;
        if ($statusLower === 'qualified') $qaUsers[$revId]['_client_map'][$clientCode]['qualified']++;
        if ($statusLower === 'disqualified') $qaUsers[$revId]['_client_map'][$clientCode]['disqualified']++;

        // Lead detail item
        if ($includeLeads) {
            $leadItem = [
                'lead_id' => $leadNumericId,
                'lead_code' => (string)($row['lead_code'] ?: ($leadNumericId > 0 ? ('#' . $leadNumericId) : 'N/A')),
                'campaign_id' => $campId,
                'campaign_name' => $campName,
                'campaign_code' => $campCode,
                'client_code' => $clientCode,
                'agent_id' => (int)($row['agent_id'] ?? 0),
                'agent_name' => (string)($row['agent_name'] ?? 'N/A'),
                'qa_status' => $normStatus,
                'prev_status' => !empty($row['prev_status']) ? (string)$row['prev_status'] : null,
                'reviewed_at' => (string)$row['reviewed_at'],
                'qa_comment' => (string)($row['qa_comment'] ?? ''),
                'qa_client_comment' => (string)($row['qa_client_comment'] ?? ''),
                'client_delivery_status' => (string)($row['client_delivery_status'] ?? 'Pending'),
            ];
            $qaUsers[$revId]['audited_leads'][] = $leadItem;
            $qaUsers[$revId]['leads'][] = $leadItem;
        }
    }

    // 7. Calculate Rates & Incentive Breakdown for each Analyst
    $analystsList = [];
    $activeAuditorsCount = 0;
    $topPerformer = null;
    $maxAudited = -1;

    foreach ($qaUsers as $qa) {
        $tot = (int)$qa['total_audited'];
        $qual = (int)$qa['qualified_count'];
        $disqual = (int)$qa['disqualified_count'];

        $qa['qualification_rate_pct'] = $tot > 0 ? round(($qual / $tot) * 100, 1) : 0.0;
        $qa['qualification_rate'] = $qa['qualification_rate_pct'];
        $qa['disqualification_rate_pct'] = $tot > 0 ? round(($disqual / $tot) * 100, 1) : 0.0;

        $camps = array_values($qa['_campaign_map']);
        $cls = array_values($qa['_client_map']);

        $qa['campaigns_breakdown'] = $camps;
        $qa['campaigns'] = $camps;
        $qa['clients_breakdown'] = $cls;
        $qa['clients'] = $cls;

        unset($qa['_seen_lead_ids'], $qa['_campaign_map'], $qa['_client_map']);

        if ($tot > 0) {
            $activeAuditorsCount++;
            if ($tot > $maxAudited) {
                $maxAudited = $tot;
                $topPerformer = [
                    'user_id' => $qa['user_id'],
                    'qa_id' => $qa['user_id'],
                    'qa_name' => $qa['qa_name'],
                    'full_name' => $qa['qa_name'],
                    'total_audited' => $tot,
                    'qualified' => $qual,
                    'disqualified' => $disqual,
                    'qualification_rate_pct' => $qa['qualification_rate_pct']
                ];
            }
        }

        // Incentive Simulation / Calculation Engine
        if ($baseTarget !== null && $baseTarget > 0 && $baseReward !== null) {
            $isTargetMet = ($tot >= $baseTarget);
            $tierBonusTotal = 0.0;
            $tierBreakdown = [];

            if ($isTargetMet) {
                $earnedBase = (float)$baseReward;
                foreach ($tiers as $idx => $tier) {
                    $min = (int)($tier['min'] ?? ($tier['from'] ?? 0));
                    $max = isset($tier['max']) ? (int)$tier['max'] : (isset($tier['to']) ? (int)$tier['to'] : null);
                    $rate = (float)($tier['rate'] ?? ($tier['rate_per_lead'] ?? 0.0));

                    if ($tot >= $min && $rate > 0) {
                        $leadsInTier = ($max !== null) ? min($tot - $min + 1, $max - $min + 1) : ($tot - $min + 1);
                        if ($leadsInTier > 0) {
                            $tierEarned = $leadsInTier * $rate;
                            $tierBonusTotal += $tierEarned;
                            $tierBreakdown[] = [
                                'tier_index' => $idx + 1,
                                'tier_label' => ($max !== null) ? "Leads {$min}-{$max}" : "Leads {$min}+",
                                'leads_count' => $leadsInTier,
                                'rate_per_lead' => $rate,
                                'tier_bonus' => round($tierEarned, 2),
                            ];
                        }
                    }
                }
                $totalIncentive = round($earnedBase + $tierBonusTotal, 2);
            } else {
                $earnedBase = 0.0;
                $totalIncentive = 0.0;
            }

            $completionPct = $baseTarget > 0 ? round(($tot / $baseTarget) * 100, 1) : 0.0;

            $qa['incentive_calculation'] = [
                'frequency' => $frequency,
                'base_target' => $baseTarget,
                'base_reward' => $baseReward,
                'target_achieved' => $isTargetMet,
                'completion_pct' => $completionPct,
                'base_incentive_earned' => $earnedBase,
                'tier_bonus_earned' => round($tierBonusTotal, 2),
                'total_incentive_earned' => $totalIncentive,
                'tier_breakdown' => $tierBreakdown,
            ];
        }

        $analystsList[] = $qa;
    }

    // Sort descending by total audited, then name
    usort($analystsList, function($a, $b) {
        $c = ($b['total_audited'] ?? 0) <=> ($a['total_audited'] ?? 0);
        if ($c !== 0) return $c;
        return strcmp($a['qa_name'] ?? '', $b['qa_name'] ?? '');
    });

    // 8. Overall Summary Block
    $overallQualRate = $totalTeamAudits > 0 ? round(($totalTeamQualified / $totalTeamAudits) * 100, 1) : 0.0;
    $overallDisqualRate = $totalTeamAudits > 0 ? round(($totalTeamDisqualified / $totalTeamAudits) * 100, 1) : 0.0;

    $summary = [
        'frequency' => $frequency,
        'date' => $dateFrom === $dateTo ? $dateFrom : null,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'total_qa_members' => count($analystsList),
        'active_qa_auditors' => $activeAuditorsCount,
        'total_leads_audited' => $totalTeamAudits,
        'total_audit_actions' => $totalTeamAudits,
        'total_qualified' => $totalTeamQualified,
        'total_disqualified' => $totalTeamDisqualified,
        'overall_qualification_rate_pct' => $overallQualRate,
        'overall_disqualification_rate_pct' => $overallDisqualRate,
        'status_totals' => $statusTotals,
        'top_performer' => $topPerformer,
    ];

    // Fetch existing active QA incentive plans for reference
    $plansList = [];
    $resPlans = $conn->query("SELECT * FROM qa_incentive_plans WHERE is_active = 1 ORDER BY id ASC");
    if ($resPlans) {
        while ($pl = $resPlans->fetch_assoc()) {
            $pl['qa_user_ids'] = !empty($pl['qa_user_ids']) ? json_decode((string)$pl['qa_user_ids'], true) : [];
            $pl['campaign_ids'] = !empty($pl['campaign_ids']) ? json_decode((string)$pl['campaign_ids'], true) : [];
            $pl['tiers'] = !empty($pl['tiers_json']) ? json_decode((string)$pl['tiers_json'], true) : [];
            $plansList[] = $pl;
        }
    }

    return [
        'ok' => true,
        'action' => 'qa_audits',
        'frequency' => $frequency,
        'date' => $dateFrom === $dateTo ? $dateFrom : null,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'summary' => $summary,
        'total_qa_analysts' => count($analystsList),
        'qa_analysts' => $analystsList,
        'qa_users' => $analystsList,
        'plans' => $plansList,
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
            'forms_submitted' => 0,
            'pending_backlog_delta' => 0,
            'cumulative_generated' => 0,
            'cumulative_audited' => 0,
            'cumulative_qualified' => 0,
            'cumulative_forms_submitted' => 0
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

    // 3. Form Submissions counts per interval
    $formData = getFormSubmissionsData($conn, $date, $intervals);
    foreach ($formData['timeline'] as $ft) {
        $sIdx = $ft['slot_index'];
        if (isset($slotsData[$sIdx])) {
            $slotsData[$sIdx]['forms_submitted'] = (int)($ft['forms_submitted'] ?? 0);
        }
    }

    // 4. Compute running cumulative metrics and lag
    $totGen = 0;
    $totAud = 0;
    $totQual = 0;
    $totDisqual = 0;
    $totForms = 0;

    foreach ($slotsData as &$slot) {
        $totGen += $slot['leads_generated'];
        $totAud += $slot['leads_audited'];
        $totQual += $slot['leads_qualified'];
        $totDisqual += $slot['leads_disqualified'];
        $totForms += $slot['forms_submitted'];

        $slot['cumulative_generated'] = $totGen;
        $slot['cumulative_audited'] = $totAud;
        $slot['cumulative_qualified'] = $totQual;
        $slot['cumulative_forms_submitted'] = $totForms;
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
            'total_forms_submitted' => (int)($formData['total_forms_submitted'] ?? $totForms),
            'pending_audit_gap' => max(0, $totGen - $totAud),
            'audit_turnaround_rate' => $auditRate,
            'qualification_approval_rate' => $qualificationRate,
            'forms_submitted_by' => $formData['by_submitter'] ?? []
        ],
        'forms_breakdown' => $formData,
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
            l.form_done,
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
                    'forms_submitted' => 0,
                    'achieved_points' => 0.0
                ];
            }
            $agentTodayStats[$aid]['generated']++;

            $fd = strtolower(trim((string)($lr['form_done'] ?? 'no')));
            if ($fd === 'yes' || $fd === '1' || $fd === 'true') {
                $agentTodayStats[$aid]['forms_submitted']++;
            }

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
        $stats = $agentTodayStats[$aid] ?? ['generated' => 0, 'qualified' => 0, 'forms_submitted' => 0, 'achieved_points' => 0.0];
        $dailyTarget = (float)$ag['daily_target_points'];
        $achievedPts = round((float)$stats['achieved_points'], 2);
        $gen = (int)$stats['generated'];
        $qual = (int)$stats['qualified'];
        $formsSub = (int)($stats['forms_submitted'] ?? 0);

        if ($gen > 0 || $achievedPts > 0 || $formsSub > 0) {
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
                'forms_submitted' => $formsSub,
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

    $formMetaRecognition = function_exists('getFormSubmissionsData') ? getFormSubmissionsData($conn, $date) : ['total_forms_submitted' => 0];

    return [
        'target_date' => $date,
        'total_items' => count($tickerItems),
        'top_scorers_count' => count($topScorers),
        'total_forms_submitted' => (int)($formMetaRecognition['total_forms_submitted'] ?? 0),
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

    [$sStartMaster, $sEndMaster] = resolveShiftTimes($conn, $shiftStart, $shiftEnd);
    $intervalsMaster = generateTimeIntervals($sStartMaster, $sEndMaster, $interval);
    $formSubmissionsData = getFormSubmissionsData($conn, $date, $intervalsMaster);

    $slidersData = getRecognitionData($conn, $date, $scope);

    $isQaAction = in_array($action, ['qa_audits', 'qa_daily_audits', 'qa_incentives', 'qa_team', 'all'], true);
    $qaAuditsData = $isQaAction ? getQaDailyAuditsData($conn, $_GET) : null;

    $response = [
        'ok' => true,
        'action' => $action,
        'query_date' => $date,
        'generated_at' => (new DateTimeImmutable('now', hrBaseTz()))->format('Y-m-d H:i:s'),
        'form_submissions' => $formSubmissionsData,
        'sliders' => $slidersData,
        'recognition' => $slidersData,
        'qa_audits' => $qaAuditsData,
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

        case 'forms':
        case 'form_submissions':
            $response['form_submissions'] = $formSubmissionsData;
            break;

        case 'qa_audits':
        case 'qa_daily_audits':
        case 'qa_incentives':
        case 'qa_team':
            $response['qa_audits'] = $qaAuditsData ?? getQaDailyAuditsData($conn, $_GET);
            $response['data'] = $response['qa_audits'];
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
            $response['qa_audits'] = $qaAuditsData ?? getQaDailyAuditsData($conn, $_GET);
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
