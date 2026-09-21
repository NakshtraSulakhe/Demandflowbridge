<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['agent','operations_agent','operations_manager','operations_director']);

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$role = (string)($user['role'] ?? '');
$isTeamView = in_array($role, ['operations_manager', 'operations_director'], true);
$taskWidget = function_exists('getMyTaskWidgetCounts') ? getMyTaskWidgetCounts($userId) : ['pending' => 0, 'due_today' => 0, 'overdue' => 0];
$opsNow = function_exists('opsNowDt') ? opsNowDt() : new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
$todayRange = function_exists('opsTodayRange') ? opsTodayRange() : ['start' => $opsNow->format('Y-m-d 00:00:00'), 'end' => $opsNow->format('Y-m-d 23:59:59'), 'start_date' => $opsNow->format('Y-m-d'), 'end_date' => $opsNow->format('Y-m-d')];
$monthRange = function_exists('opsMonthRange') ? opsMonthRange() : ['start' => $opsNow->format('Y-m-01 00:00:00'), 'end' => $opsNow->format('Y-m-t 23:59:59'), 'start_date' => $opsNow->format('Y-m-01'), 'end_date' => $opsNow->format('Y-m-t')];
$todayStart = (string)($todayRange['start'] ?? '1970-01-01 00:00:00');
$todayEnd = (string)($todayRange['end'] ?? '2999-12-31 23:59:59');
$monthStart = (string)($monthRange['start'] ?? '1970-01-01 00:00:00');
$monthEnd = (string)($monthRange['end'] ?? '2999-12-31 23:59:59');

$range = function_exists('dfDashboardRange') ? dfDashboardRange($_GET, 'this_month') : null;
$preset = (string)($range['preset'] ?? 'this_month');
$rangeStart = (string)($range['start'] ?? $monthStart);
$rangeEnd = (string)($range['end'] ?? $monthEnd);
$rangeLabel = (string)($range['label'] ?? 'This Month');
$sd = (string)($range['start_date'] ?? substr($rangeStart, 0, 10));
$ed = (string)($range['end_date'] ?? substr($rangeEnd, 0, 10));
$leadCreatedExpr = function_exists('leadCreatedAtExpr') ? leadCreatedAtExpr('l') : 'l.created_at';
$leadCreatedExprBare = function_exists('leadCreatedAtExpr') ? leadCreatedAtExpr('') : 'created_at';

$conn = getDbConnection();
$agentIds = [$userId];
if ($isTeamView) {
  $agentIds = [];
  $teamIds = getUserTeamIds($userId);
  if (!empty($teamIds)) {
    $in = implode(',', array_fill(0, count($teamIds), '?'));
    $typesT = str_repeat('i', count($teamIds));
    $stmtT = $conn->prepare("
      SELECT DISTINCT tm.user_id
      FROM team_members tm
      JOIN users u ON u.id = tm.user_id
      WHERE tm.team_id IN ($in) AND u.is_active = 1 AND u.role IN ('agent','operations_agent','operations_manager','operations_director')
    ");
    if ($stmtT) {
      $stmtT->bind_param($typesT, ...$teamIds);
      $stmtT->execute();
      $rsT = $stmtT->get_result();
      while ($r = $rsT->fetch_assoc()) $agentIds[] = (int)($r['user_id'] ?? 0);
      $stmtT->close();
    }
  }
  $agentIds = array_values(array_filter(array_unique($agentIds), fn($v) => $v > 0));
  if (empty($agentIds)) $agentIds = [$userId];
}

$placeholders = implode(',', array_fill(0, count($agentIds), '?'));
$types = str_repeat('i', count($agentIds));

$fetchLeadMix = static function(?string $startTs, ?string $endTs) use ($conn, $agentIds, $types, $placeholders, $leadCreatedExpr): array {
  $reviewedExpr = "COALESCE(l.qa_updated_at, $leadCreatedExpr)";
  $pendingCond = "(l.qa_status IS NULL OR l.qa_status IN ('Pending','Reopened') OR TRIM(COALESCE(l.qa_status, '')) = '')";
  $defaults = ['leads' => 0, 'qualified' => 0, 'disqualified' => 0, 'pending' => 0];

  if ($startTs === null || $endTs === null) {
    $stmt = $conn->prepare("
      SELECT
        SUM(CASE WHEN l.qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified,
        SUM(CASE WHEN l.qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS disqualified,
        SUM(CASE WHEN $pendingCond THEN 1 ELSE 0 END) AS pending
      FROM leads l
      WHERE l.agent_id IN ($placeholders)
    ");
    if (!$stmt) return $defaults;
    $stmt->bind_param($types, ...$agentIds);
  } else {
    $stmt = $conn->prepare("
      SELECT
        SUM(CASE WHEN $reviewedExpr BETWEEN ? AND ? AND l.qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified,
        SUM(CASE WHEN $reviewedExpr BETWEEN ? AND ? AND l.qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS disqualified,
        SUM(CASE WHEN $leadCreatedExpr BETWEEN ? AND ? AND $pendingCond THEN 1 ELSE 0 END) AS pending
      FROM leads l
      WHERE l.agent_id IN ($placeholders)
        AND (
          $reviewedExpr BETWEEN ? AND ?
          OR $leadCreatedExpr BETWEEN ? AND ?
        )
    ");
    if (!$stmt) return $defaults;
    $bindTypes = 'ssssss' . $types . 'ssss';
    $bindParams = array_merge(
      [$startTs, $endTs, $startTs, $endTs, $startTs, $endTs],
      $agentIds,
      [$startTs, $endTs, $startTs, $endTs]
    );
    $stmt->bind_param($bindTypes, ...$bindParams);
  }

  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc() ?: [];
  $stmt->close();
  $mix = [
    'qualified' => (int)($row['qualified'] ?? 0),
    'disqualified' => (int)($row['disqualified'] ?? 0),
    'pending' => (int)($row['pending'] ?? 0),
  ];
  $mix['leads'] = $mix['qualified'] + $mix['disqualified'] + $mix['pending'];
  return $mix;
};

$overallStats = $fetchLeadMix(null, null);
$todayStats = $fetchLeadMix($todayStart, $todayEnd);
$monthStats = $fetchLeadMix($monthStart, $monthEnd);
$rangeStats = $fetchLeadMix($rangeStart, $rangeEnd);

$stats = [
  'total_leads' => (int)($overallStats['leads'] ?? 0),
  'pending_leads' => (int)($overallStats['pending'] ?? 0),
  'qualified_leads' => (int)($overallStats['qualified'] ?? 0),
  'disqualified_leads' => (int)($overallStats['disqualified'] ?? 0),
  'today_leads' => (int)($todayStats['leads'] ?? 0),
  'today_qualified' => (int)($todayStats['qualified'] ?? 0),
  'today_disqualified' => (int)($todayStats['disqualified'] ?? 0),
  'today_pending' => (int)($todayStats['pending'] ?? 0),
  'month_leads' => (int)($monthStats['leads'] ?? 0),
  'month_qualified' => (int)($monthStats['qualified'] ?? 0),
  'month_disqualified' => (int)($monthStats['disqualified'] ?? 0),
  'month_pending' => (int)($monthStats['pending'] ?? 0),
];

$attendanceToday = ['logged_in' => 0, 'late' => 0, 'on_time' => 0];
$todayDate = (new DateTimeImmutable('now', hrBaseTz()))->format('Y-m-d');
$attendanceRange = ['present_days' => 0.0, 'late_days' => 0, 'avg_work_minutes' => 0];
$rangeStartDateOnly = substr($rangeStart, 0, 10);
$rangeEndDateOnly = substr($rangeEnd, 0, 10);
$attendanceAggregate = hrGetAttendanceAggregate($agentIds, $todayDate, $rangeStartDateOnly, $rangeEndDateOnly);
$attendanceToday = array_merge($attendanceToday, (array)($attendanceAggregate['today'] ?? []));
$attendanceRange = array_merge($attendanceRange, (array)($attendanceAggregate['range'] ?? []));
$attendanceToday['logged_in'] = (int)($attendanceToday['logged_in'] ?? 0);
$attendanceToday['late'] = (int)($attendanceToday['late'] ?? 0);
$attendanceToday['on_time'] = (int)($attendanceToday['on_time'] ?? max(0, $attendanceToday['logged_in'] - $attendanceToday['late']));
$attendanceRange['present_days'] = (float)($attendanceRange['present_days'] ?? 0);
$attendanceRange['late_days'] = (int)($attendanceRange['late_days'] ?? 0);
$attendanceRange['avg_work_minutes'] = (int)($attendanceRange['avg_work_minutes'] ?? 0);

$campaignPerfRows = [];
$campaignPerfChart = ['labels' => [], 'qualified' => [], 'disqualified' => []];
$stmt = $conn->prepare("
  SELECT
    l.campaign_id,
    COALESCE(NULLIF(TRIM(c.name), ''), CONCAT('Campaign #', l.campaign_id)) AS campaign_name,
    SUM(CASE WHEN COALESCE(l.qa_updated_at, $leadCreatedExprBare) BETWEEN ? AND ? AND l.qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified,
    SUM(CASE WHEN COALESCE(l.qa_updated_at, $leadCreatedExprBare) BETWEEN ? AND ? AND l.qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS disqualified,
    SUM(CASE WHEN $leadCreatedExpr BETWEEN ? AND ? AND (l.qa_status = 'Pending' OR l.qa_status = 'Reopened' OR l.qa_status IS NULL OR TRIM(COALESCE(l.qa_status, '')) = '') THEN 1 ELSE 0 END) AS pending
  FROM leads l
  LEFT JOIN campaigns c ON c.id = l.campaign_id
  WHERE l.agent_id IN ($placeholders)
    AND (
      $leadCreatedExpr BETWEEN ? AND ?
      OR COALESCE(l.qa_updated_at, $leadCreatedExprBare) BETWEEN ? AND ?
    )
  GROUP BY l.campaign_id, c.name
  HAVING qualified > 0 OR disqualified > 0 OR pending > 0
  ORDER BY (qualified + disqualified + pending) DESC
  LIMIT 8
");
if ($stmt) {
  $bindTypes = 'ssssss' . $types . 'ssss';
  $bindParams = array_merge(
    [$rangeStart, $rangeEnd, $rangeStart, $rangeEnd, $rangeStart, $rangeEnd],
    $agentIds,
    [$rangeStart, $rangeEnd, $rangeStart, $rangeEnd]
  );
  $stmt->bind_param($bindTypes, ...$bindParams);
  $stmt->execute();
  $campaignPerfRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
  $stmt->close();
  foreach ($campaignPerfRows as &$row) {
    $row['qualified'] = (int)($row['qualified'] ?? 0);
    $row['disqualified'] = (int)($row['disqualified'] ?? 0);
    $row['pending'] = (int)($row['pending'] ?? 0);
    $row['total_leads'] = $row['qualified'] + $row['disqualified'] + $row['pending'];
    $row['scored'] = $row['qualified'] + $row['disqualified'];
    $row['qualification_rate'] = $row['total_leads'] > 0 ? round(($row['qualified'] / $row['total_leads']) * 100, 1) : 0.0;
    $row['dq_rate'] = $row['scored'] > 0 ? round(($row['disqualified'] / $row['scored']) * 100, 1) : 0.0;
    $campaignPerfChart['labels'][] = (string)($row['campaign_name'] ?? '');
    $campaignPerfChart['qualified'][] = $row['qualification_rate'];
    $campaignPerfChart['disqualified'][] = $row['dq_rate'];
  }
  unset($row);
}
$campaignPerfTotals = ['total_leads' => 0, 'qualified' => 0, 'disqualified' => 0, 'scored' => 0];
foreach ($campaignPerfRows as $campaignPerfRow) {
  $campaignPerfTotals['total_leads'] += (int)($campaignPerfRow['total_leads'] ?? 0);
  $campaignPerfTotals['qualified'] += (int)($campaignPerfRow['qualified'] ?? 0);
  $campaignPerfTotals['disqualified'] += (int)($campaignPerfRow['disqualified'] ?? 0);
  $campaignPerfTotals['scored'] += (int)(($campaignPerfRow['qualified'] ?? 0) + ($campaignPerfRow['disqualified'] ?? 0));
}

$y = (int)$opsNow->format('Y');
$m = (int)$opsNow->format('m');
$peerUsers = [];
$peerUsersById = [];
$performerRoleMap = array_flip(['agent','operations_agent','operations_manager','operations_director']);
if (function_exists('funneltrackGetVisiblePerformanceUsers')) {
  foreach (funneltrackGetVisiblePerformanceUsers($userId) as $visibleUser) {
    $aid = (int)($visibleUser['id'] ?? 0);
    $peerRole = strtolower((string)($visibleUser['role'] ?? ''));
    if ($aid <= 0 || !isset($performerRoleMap[$peerRole])) continue;
    $peerUsersById[$aid] = [
      'id' => $aid,
      'full_name' => (string)($visibleUser['full_name'] ?? ('User #' . $aid)),
      'role' => (string)($visibleUser['role'] ?? ''),
      'job_title' => (string)($visibleUser['job_title'] ?? ''),
    ];
  }
}
if (count($peerUsersById) < 3 && function_exists('getInternalPayrollUsers')) {
  foreach (getInternalPayrollUsers() as $payrollUser) {
    $aid = (int)($payrollUser['id'] ?? 0);
    $peerRole = strtolower((string)($payrollUser['role'] ?? ''));
    if ($aid <= 0 || !isset($performerRoleMap[$peerRole])) continue;
    if (isset($peerUsersById[$aid])) continue;
    $peerUsersById[$aid] = [
      'id' => $aid,
      'full_name' => (string)($payrollUser['full_name'] ?? ('User #' . $aid)),
      'role' => (string)($payrollUser['role'] ?? ''),
      'job_title' => (string)($payrollUser['job_title'] ?? ''),
    ];
  }
}
if (count($peerUsersById) < 3) {
  $stmtPeer = $conn->prepare("
    SELECT id, full_name, role, job_title
    FROM users
    WHERE is_active = 1
      AND role IN ('agent','operations_agent','operations_manager','operations_director')
    ORDER BY full_name ASC
    LIMIT 100
  ");
  if ($stmtPeer) {
    $stmtPeer->execute();
    $rows = $stmtPeer->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmtPeer->close();
    foreach ($rows as $row) {
      $aid = (int)($row['id'] ?? 0);
      $peerRole = strtolower((string)($row['role'] ?? ''));
      if ($aid <= 0 || !isset($performerRoleMap[$peerRole]) || isset($peerUsersById[$aid])) continue;
      $peerUsersById[$aid] = [
        'id' => $aid,
        'full_name' => (string)($row['full_name'] ?? ('User #' . $aid)),
        'role' => (string)($row['role'] ?? ''),
        'job_title' => (string)($row['job_title'] ?? ''),
      ];
    }
  }
}
$peerUsers = array_values($peerUsersById);
usort($peerUsers, static fn(array $a, array $b): int => strcasecmp((string)($a['full_name'] ?? ''), (string)($b['full_name'] ?? '')));

$perfCfg = function_exists('funneltrackGetConfig') ? funneltrackGetConfig() : [];
$perfRangeByUser = [];
$perfMonthByUser = [];
$perfMonthToDateByUser = [];
$peerPerf = [];
$monthStartDate = (string)($monthRange['start_date'] ?? $opsNow->format('Y-m-01'));
$monthToDateEnd = (string)($todayRange['end_date'] ?? $opsNow->format('Y-m-d'));
$perfPeerIds = [];
foreach ($peerUsers as $pu) {
  $aid = (int)($pu['id'] ?? 0);
  $peerRole = strtolower((string)($pu['role'] ?? ''));
  if ($aid <= 0 || !isset($performerRoleMap[$peerRole])) continue;
  $perfPeerIds[] = $aid;
}
if (!empty($perfPeerIds) && function_exists('funneltrackComputeAgentRangeSummaries')) {
  $perfRangeByUser = funneltrackComputeAgentRangeSummaries($perfPeerIds, $sd, $ed, $perfCfg);
  $perfMonthToDateByUser = funneltrackComputeAgentRangeSummaries($perfPeerIds, $monthStartDate, $monthToDateEnd, $perfCfg);
}
if (!empty($perfPeerIds) && function_exists('funneltrackComputeAgentMonthSummaries')) {
  $perfMonthByUser = funneltrackComputeAgentMonthSummaries($perfPeerIds, $y, $m, $perfCfg);
}

foreach ($peerUsers as $pu) {
  $aid = (int)($pu['id'] ?? 0);
  $peerRole = strtolower((string)($pu['role'] ?? ''));
  if ($aid <= 0 || !isset($performerRoleMap[$peerRole])) continue;

  $rangeSummary = $perfRangeByUser[$aid] ?? [];
  $monthSummary = $perfMonthByUser[$aid] ?? (function_exists('funneltrackComputeAgentMonthSummary')
    ? (funneltrackComputeAgentMonthSummary($aid, $y, $m, $perfCfg) ?: [])
    : []);
  $monthToDateSummary = $perfMonthToDateByUser[$aid] ?? [];

  $perfRangeByUser[$aid] = $rangeSummary;
  $perfMonthByUser[$aid] = $monthSummary;
  $perfMonthToDateByUser[$aid] = $monthToDateSummary;

  $rangeInc = (int)($rangeSummary['incentives']['range_total'] ?? 0);
  $monthDailyInc = (int)($monthToDateSummary['incentives']['range_total'] ?? 0);
  $monthlyBonus = (int)($monthSummary['incentives']['monthly'] ?? 0);
  $monthScore = (float)($monthSummary['score']['total'] ?? 0.0);
  $monthRank = (string)($monthSummary['rank'] ?? '');
  $monthProductivity = (float)($monthSummary['productivity_pct'] ?? 0.0);
  $rangeProductivity = (float)($rangeSummary['productivity_pct'] ?? 0.0);
  $dailyTarget = (int)($monthSummary['targets']['daily'] ?? 0);
  $monthlyTarget = (float)($monthSummary['targets']['monthly'] ?? 0.0);

  $peerPerf[] = [
    'id' => $aid,
    'name' => (string)($pu['full_name'] ?? ('Agent #' . $aid)),
    'role' => (string)($pu['role'] ?? ''),
    'job_title' => (string)($pu['job_title'] ?? ''),
    'range_incentive' => $rangeInc,
    'daily_total' => $monthDailyInc,
    'monthly_bonus' => $monthlyBonus,
    'total_incentive' => $monthDailyInc + $monthlyBonus,
    'score' => $monthScore,
    'rank' => $monthRank,
    'overall_percent' => $monthProductivity,
    'range_productivity' => $rangeProductivity,
    'earned_incentive' => ($rangeInc > 0 || $monthlyBonus > 0) ? 'Yes' : 'No',
    'has_target' => ($dailyTarget > 0) || ($monthlyTarget > 0),
  ];
}
if (empty($peerPerf) && !empty($peerUsers)) {
  foreach ($peerUsers as $pu) {
    $aid = (int)($pu['id'] ?? 0);
    if ($aid <= 0) continue;
    $peerPerf[] = [
      'id' => $aid,
      'name' => (string)($pu['full_name'] ?? ('Agent #' . $aid)),
      'role' => (string)($pu['role'] ?? ''),
      'job_title' => (string)($pu['job_title'] ?? ''),
      'range_incentive' => 0,
      'daily_total' => 0,
      'monthly_bonus' => 0,
      'total_incentive' => 0,
      'score' => 0.0,
      'rank' => '',
      'overall_percent' => 0.0,
      'range_productivity' => 0.0,
      'earned_incentive' => 'No',
      'has_target' => false,
    ];
  }
}

$myRange = $perfRangeByUser[$userId] ?? [];
$myMonth = $perfMonthByUser[$userId] ?? [];
$myMonthToDate = $perfMonthToDateByUser[$userId] ?? [];
$myInc = [
  'range_total' => (int)($myRange['incentives']['range_total'] ?? 0),
  'daily_total' => (int)($myMonthToDate['incentives']['range_total'] ?? 0),
  'monthly_bonus' => (int)($myMonth['incentives']['monthly'] ?? 0),
];
$myInc['total'] = (int)$myInc['daily_total'] + (int)$myInc['monthly_bonus'];
$myStats = [
  'overall_percent' => (float)($myMonth['productivity_pct'] ?? 0.0),
  'range_percent' => (float)($myRange['productivity_pct'] ?? 0.0),
];
$myTarget = [
  'daily_target' => (int)($myMonth['targets']['daily'] ?? 0),
];
$myDailyTarget = (int)($myTarget['daily_target'] ?? 0);

$leaderboard = array_values(array_filter($peerPerf, fn($r) => !empty($r['has_target'])));
if (count($leaderboard) < min(3, count($peerPerf))) {
  $leaderboard = $peerPerf;
}
usort($leaderboard, fn($a,$b) => (($b['score'] ?? 0.0) <=> ($a['score'] ?? 0.0)) ?: strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
$top3 = array_slice($leaderboard, 0, 3);
$bottomPool = $leaderboard;
usort($bottomPool, fn($a,$b) => (($a['score'] ?? 0.0) <=> ($b['score'] ?? 0.0)) ?: strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
$bottom3 = array_slice($bottomPool, 0, 3);
$performerScopeCount = count($leaderboard);

$teamTotals = ['daily_total' => 0, 'monthly_bonus' => 0, 'total' => 0, 'range_total' => 0, 'avg_pct' => 0.0, 'avg_range_pct' => 0.0, 'count' => 0];
foreach ($leaderboard as $r) {
  $teamTotals['daily_total'] += (int)($r['daily_total'] ?? 0);
  $teamTotals['monthly_bonus'] += (int)($r['monthly_bonus'] ?? 0);
  $teamTotals['total'] += (int)($r['total_incentive'] ?? 0);
  $teamTotals['range_total'] += (int)($r['range_incentive'] ?? 0);
  $teamTotals['avg_pct'] += (float)($r['overall_percent'] ?? 0);
  $teamTotals['avg_range_pct'] += (float)($r['range_productivity'] ?? 0);
  $teamTotals['count'] += 1;
}
if ($teamTotals['count'] > 0) {
  $teamTotals['avg_pct'] = $teamTotals['avg_pct'] / (float)$teamTotals['count'];
  $teamTotals['avg_range_pct'] = $teamTotals['avg_range_pct'] / (float)$teamTotals['count'];
}

$rankNum = 0;
$rankLabel = '—';
$rankScore = (float)($myMonth['score']['total'] ?? 0.0);
$rankTier = (string)($myMonth['rank'] ?? '');
$leaderboardCount = count($leaderboard);
foreach ($leaderboard as $idx => $leaderboardRow) {
  if ((int)($leaderboardRow['id'] ?? 0) === $userId) {
    $rankNum = $idx + 1;
    break;
  }
}
if ($rankNum > 0 && $leaderboardCount > 0) {
  $rankLabel = '#' . $rankNum . ' of ' . $leaderboardCount;
}
$rankTone = 'secondary';
if ($rankNum === 1) $rankTone = 'success';
if ($rankNum === 2) $rankTone = 'primary';
if ($rankNum === 3) $rankTone = 'info';

$formatHoursMinutes = static function(int $minutes): string {
  return number_format((int)floor($minutes / 60)) . ':' . str_pad((string)($minutes % 60), 2, '0', STR_PAD_LEFT);
};
$formatPresentDays = static function(float $days): string {
  if (abs($days - round($days)) < 0.001) return number_format((float)round($days), 0);
  return number_format($days, 1);
};
$rangeIncentiveDisplay = $isTeamView ? (int)$teamTotals['range_total'] : (int)($myInc['range_total'] ?? 0);
$rangePerformanceDisplay = $isTeamView ? (float)$teamTotals['avg_range_pct'] : (float)($myStats['range_percent'] ?? 0);
?>
<?php $pageTitle = 'Operations Dashboard'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<style>
  .ops-hero-copy {
    max-width: 920px;
  }
  .ops-header-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
  }
  .ops-header-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0.42rem 0.78rem;
    border-radius: 999px;
    border: 1px solid rgba(59, 130, 246, 0.16);
    background: rgba(59, 130, 246, 0.08);
    color: var(--app-text);
    font-size: 0.78rem;
    font-weight: 600;
  }
  .perf-person-card {
    border: 1px solid rgba(148, 163, 184, 0.18);
    border-radius: 1rem;
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    box-shadow: 0 0.35rem 1rem rgba(15, 23, 42, 0.06);
  }
  :root[data-theme="dark"] .perf-person-card,
  body[data-theme="dark"] .perf-person-card,
  [data-bs-theme="dark"] .perf-person-card {
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.03) 0%, rgba(255, 255, 255, 0.01) 100%);
    border-color: var(--app-border);
    box-shadow: 0 0.35rem 1rem rgba(0, 0, 0, 0.3);
  }
  .perf-rank-chip {
    min-width: 2.25rem;
    height: 2.25rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    font-weight: 700;
    background: rgba(59, 130, 246, 0.1);
    color: #1d4ed8;
  }
  :root[data-theme="dark"] .perf-rank-chip,
  body[data-theme="dark"] .perf-rank-chip,
  [data-bs-theme="dark"] .perf-rank-chip {
    background: rgba(0, 255, 255, 0.15);
    color: #00ffff;
  }
</style>
<div class="container-fluid px-0 operations-workspace">
  <div class="ops-page-header ops-hero mb-3">
    <div class="ops-hero-copy">
      <h3 class="mb-1">Operations Dashboard</h3>
      <div class="text-muted small"><?php echo htmlspecialchars(formatUserNameWithRole(($user['full_name'] ?? 'User'), ($user['role'] ?? ''), ($user['job_title'] ?? ''))); ?></div>
      <div class="ops-header-chips">
        <span class="ops-header-chip"><i class="bi bi-calendar3"></i><?php echo htmlspecialchars($rangeLabel); ?></span>
        <span class="ops-header-chip"><i class="bi bi-people"></i><?php echo number_format($performerScopeCount); ?> performers tracked</span>
        <span class="ops-header-chip"><i class="bi bi-layout-text-sidebar-reverse"></i><?php echo $isTeamView ? 'Team KPI view' : 'Personal KPI view'; ?></span>
      </div>
    </div>
  </div>

  <form class="row g-2 align-items-end mb-3 shared-dashboard-filter" method="get">
    <div class="col-md-3">
      <label class="form-label small text-muted">Range</label>
      <select class="form-select form-select-sm" name="range_preset" onchange="this.form.submit();">
        <option value="today" <?php echo $preset==='today'?'selected':''; ?>>Today</option>
        <option value="this_month" <?php echo $preset==='this_month'?'selected':''; ?>>This Month</option>
        <option value="overall" <?php echo $preset==='overall'?'selected':''; ?>>Overall</option>
        <option value="custom" <?php echo $preset==='custom'?'selected':''; ?>>Custom Range</option>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label small text-muted">Start</label>
      <input type="date" class="form-control form-control-sm" name="start_date" value="<?php echo htmlspecialchars($sd); ?>" <?php echo $preset==='custom'?'':'disabled'; ?>>
    </div>
    <div class="col-md-3">
      <label class="form-label small text-muted">End</label>
      <input type="date" class="form-control form-control-sm" name="end_date" value="<?php echo htmlspecialchars($ed); ?>" <?php echo $preset==='custom'?'':'disabled'; ?>>
    </div>
    <div class="col-md-3 d-flex justify-content-end">
      <button class="btn btn-primary btn-sm mt-4" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
    </div>
  </form>

  <div class="row g-3 mb-3">
    <div class="col-md-3">
      <div class="card stats-card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="stats-icon bg-primary-subtle text-primary rounded-3 p-2 me-3">
              <i class="bi bi-collection fs-4"></i>
            </div>
            <h6 class="card-subtitle text-muted mb-0">Total Leads</h6>
          </div>
          <h3 class="card-title mb-0"><?php echo number_format($stats['total_leads']); ?></h3>
          <div class="text-muted small mt-1">Qualified: <?php echo number_format($stats['qualified_leads']); ?> · Disq: <?php echo number_format($stats['disqualified_leads']); ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card stats-card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="stats-icon bg-info-subtle text-info rounded-3 p-2 me-3">
              <i class="bi bi-calendar-day fs-4"></i>
            </div>
            <h6 class="card-subtitle text-muted mb-0">Today</h6>
          </div>
          <h3 class="card-title mb-0"><?php echo number_format($stats['today_leads']); ?></h3>
          <div class="text-muted small mt-1">Qualified: <?php echo number_format($stats['today_qualified']); ?> · Disq: <?php echo number_format($stats['today_disqualified']); ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card stats-card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="stats-icon bg-secondary-subtle text-secondary rounded-3 p-2 me-3">
              <i class="bi bi-calendar3 fs-4"></i>
            </div>
            <h6 class="card-subtitle text-muted mb-0"><?php echo htmlspecialchars($rangeLabel); ?></h6>
          </div>
          <h3 class="card-title mb-0"><?php echo number_format($rangeStats['leads']); ?></h3>
          <div class="text-muted small mt-1">Qualified: <?php echo number_format($rangeStats['qualified']); ?> · Disq: <?php echo number_format($rangeStats['disqualified']); ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card stats-card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="stats-icon bg-warning-subtle text-warning rounded-3 p-2 me-3">
              <i class="bi bi-clock-history fs-4"></i>
            </div>
            <h6 class="card-subtitle text-muted mb-0">Pending QA</h6>
          </div>
          <h3 class="card-title mb-0 text-warning"><?php echo number_format($rangeStats['pending']); ?></h3>
          <div class="text-muted small mt-1">Today: <?php echo number_format($stats['today_pending']); ?> · This month: <?php echo number_format($stats['month_pending']); ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-3">
      <a class="text-decoration-none" href="<?php echo htmlspecialchars(appBasePath() . '/modules/tasks'); ?>">
        <div class="card stats-card h-100 border-0 shadow-sm">
          <div class="card-body">
            <div class="d-flex align-items-center mb-3">
              <div class="stats-icon bg-warning-subtle text-warning rounded-3 p-2 me-3">
                <i class="bi bi-kanban fs-4"></i>
              </div>
              <h6 class="card-subtitle text-muted mb-0">My Tasks</h6>
            </div>
            <h3 class="card-title mb-0"><?php echo number_format((int)$taskWidget['pending']); ?></h3>
            <div class="text-muted small mt-1">Due today: <?php echo number_format((int)$taskWidget['due_today']); ?> · Overdue: <?php echo number_format((int)$taskWidget['overdue']); ?></div>
          </div>
        </div>
      </a>
    </div>
    <div class="col-md-3">
      <a class="text-decoration-none" href="<?php echo htmlspecialchars(appBasePath() . '/modules/hr/attendance'); ?>">
        <div class="card stats-card h-100 border-0 shadow-sm">
          <div class="card-body">
            <div class="d-flex align-items-center mb-3">
              <div class="stats-icon bg-info-subtle text-info rounded-3 p-2 me-3">
                <i class="bi bi-calendar-check fs-4"></i>
              </div>
              <h6 class="card-subtitle text-muted mb-0">Attendance (Today)</h6>
            </div>
            <h3 class="card-title mb-0"><?php echo number_format((int)$attendanceToday['logged_in']); ?></h3>
            <div class="text-muted small mt-1">Late: <?php echo number_format((int)$attendanceToday['late']); ?> · On-time: <?php echo number_format((int)$attendanceToday['on_time']); ?></div>
          </div>
        </div>
      </a>
    </div>
    <div class="col-md-6">
      <div class="card stats-card h-100 border-0 shadow-sm">
        <div class="card-body d-flex align-items-center justify-content-between">
          <div>
            <div class="text-muted small">My Rank (This Month)</div>
            <div class="fw-semibold"><?php echo htmlspecialchars($rankLabel !== '' ? $rankLabel : '—'); ?></div>
            <div class="text-muted small">
              Score: <?php echo htmlspecialchars(number_format((float)$rankScore, 1)); ?> / 100<?php echo $rankTier !== '' ? (' · Tier: ' . htmlspecialchars($rankTier)) : ''; ?>
            </div>
          </div>
          <div class="df-rank-badge df-rank-<?php echo htmlspecialchars($rankTone); ?>">
            <?php echo $rankNum > 0 ? (int)$rankNum : '—'; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-3">
      <div class="card stats-card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="stats-icon bg-success-subtle text-success rounded-3 p-2 me-3">
              <i class="bi bi-check-circle fs-4"></i>
            </div>
            <h6 class="card-subtitle text-muted mb-0">Qualified</h6>
          </div>
          <h3 class="card-title mb-0 text-success"><?php echo number_format($stats['qualified_leads']); ?></h3>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card stats-card h-100 border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center mb-3">
            <div class="stats-icon bg-danger-subtle text-danger rounded-3 p-2 me-3">
              <i class="bi bi-x-circle fs-4"></i>
            </div>
            <h6 class="card-subtitle text-muted mb-0">Disqualified</h6>
          </div>
          <h3 class="card-title mb-0 text-danger"><?php echo number_format($stats['disqualified_leads']); ?></h3>
        </div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card h-100 border-0 shadow-sm ops-panel">
        <div class="card-body d-flex align-items-center justify-content-between">
          <div>
            <div class="text-muted small">Quick Action</div>
            <div class="fw-semibold">Submit a new lead</div>
            <div class="text-muted small">Capture delivery lead details for QA review.</div>
          </div>
          <a class="btn btn-primary btn-sm" href="../leads/agent.php"><i class="bi bi-plus-circle me-1"></i>Submit Lead</a>
        </div>
      </div>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3 ops-panel">
    <div class="card-header bg-light fw-semibold d-flex align-items-center justify-content-between">
      <span>Earnings Overview</span>
      <span class="text-muted small"><?php echo htmlspecialchars($opsNow->format('M Y')); ?></span>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-lg-6">
          <div class="row g-3">
            <div class="col-6">
              <div class="card border-0 shadow-sm h-100 ops-panel">
                <div class="card-body d-flex align-items-center justify-content-between">
                  <div>
                    <div class="text-muted small"><?php echo $isTeamView ? 'Team Daily Incentives' : 'My Daily Incentives'; ?></div>
                    <div class="h4 mb-0"><?php echo number_format($isTeamView ? (int)$teamTotals['daily_total'] : (int)($myInc['daily_total'] ?? 0)); ?></div>
                    <div class="text-muted small"><?php echo $isTeamView ? ('Month-to-date productivity: ' . number_format((float)$teamTotals['avg_pct'], 1) . '%') : ('Month-to-date productivity: ' . number_format((float)($myStats['overall_percent'] ?? 0), 1) . '%'); ?></div>
                  </div>
                  <div class="text-success fs-2"><i class="bi bi-cash-coin"></i></div>
                </div>
              </div>
            </div>
            <div class="col-6">
              <div class="card border-0 shadow-sm h-100 ops-panel">
                <div class="card-body d-flex align-items-center justify-content-between">
                  <div>
                    <div class="text-muted small"><?php echo $isTeamView ? 'Team Monthly Bonus' : 'My Monthly Bonus'; ?></div>
                    <div class="h4 mb-0"><?php echo number_format($isTeamView ? (int)$teamTotals['monthly_bonus'] : (int)($myInc['monthly_bonus'] ?? 0)); ?></div>
                    <div class="text-muted small"><?php echo ($isTeamView || $myDailyTarget > 0) ? 'Current month rule-based bonus' : 'No target set'; ?></div>
                  </div>
                  <div class="text-primary fs-2"><i class="bi bi-award"></i></div>
                </div>
              </div>
            </div>
            <div class="col-6">
              <div class="card border-0 shadow-sm h-100 ops-panel">
                <div class="card-body d-flex align-items-center justify-content-between">
                  <div>
                    <div class="text-muted small"><?php echo $isTeamView ? 'Team Total Incentives' : 'My Total Incentives'; ?></div>
                    <div class="h4 mb-0"><?php echo number_format($isTeamView ? (int)$teamTotals['total'] : (int)($myInc['total'] ?? 0)); ?></div>
                    <div class="text-muted small">Month-to-date daily + monthly bonus</div>
                  </div>
                  <div class="text-warning fs-2"><i class="bi bi-trophy"></i></div>
                </div>
              </div>
            </div>
            <div class="col-6">
              <div class="card border-0 shadow-sm h-100 ops-panel">
                <div class="card-body d-flex align-items-center justify-content-between">
                  <div>
                    <div class="text-muted small"><?php echo $isTeamView ? 'Team Members' : 'My Daily Target'; ?></div>
                    <div class="h4 mb-0"><?php echo $isTeamView ? number_format((int)$teamTotals['count']) : number_format((int)$myDailyTarget); ?></div>
                    <div class="text-muted small"><?php echo $isTeamView ? 'With targets' : 'Productivity target'; ?></div>
                  </div>
                  <div class="text-info fs-2"><i class="bi bi-bullseye"></i></div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-lg-6">
          <div class="row g-3">
            <div class="col-12 col-xl-6">
              <div class="card border-0 shadow-sm h-100 ops-panel">
                <div class="card-header bg-light fw-semibold"><i class="bi bi-trophy me-1"></i>Top 3 Performers (Current Month)</div>
                <div class="card-body">
                  <div class="row g-3">
                    <?php if (empty($top3)): ?>
                      <div class="col-12 text-center text-muted py-4">No target data</div>
                    <?php else: ?>
                      <?php foreach ($top3 as $idx => $r): ?>
                        <?php $podium = getPerformerPodiumMeta((int)$idx); ?>
                        <div class="col-12">
                          <div class="perf-person-card p-3">
                            <div class="d-flex align-items-start justify-content-between gap-3">
                              <div class="d-flex gap-3">
                                <div class="performer-rank-stack">
                                  <span class="performer-podium-badge <?php echo htmlspecialchars($podium['class']); ?>"><i class="bi bi-trophy-fill"></i><?php echo htmlspecialchars($podium['label']); ?></span>
                                  <span class="performer-podium-note"><?php echo htmlspecialchars($podium['place']); ?></span>
                                </div>
                                <div>
                                  <div class="fw-semibold"><?php echo htmlspecialchars((string)($r['name'] ?? '')); ?></div>
                                  <div class="small text-muted">Performance tier: <?php echo htmlspecialchars((string)(($r['rank'] ?? '') !== '' ? $r['rank'] : 'Unranked')); ?></div>
                                </div>
                              </div>
                            </div>
                            <div class="row g-2 mt-3">
                              <div class="col-4">
                                <div class="small text-muted">Productivity</div>
                                <div class="fw-semibold"><?php echo number_format((float)($r['overall_percent'] ?? 0), 1); ?>%</div>
                              </div>
                              <div class="col-4">
                                <div class="small text-muted">Earned Incentive</div>
                                <div class="fw-semibold"><?php echo htmlspecialchars((string)($r['earned_incentive'] ?? 'No')); ?></div>
                              </div>
                              <div class="col-4 text-end">
                                <div class="small text-muted">Amount</div>
                                <div class="fw-semibold">Rs. <?php echo number_format((int)($r['range_incentive'] ?? 0)); ?></div>
                              </div>
                            </div>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="card-footer bg-white">
                  <div class="fst-italic text-muted small">Current month leaders in the selected team view.</div>
                </div>
              </div>
            </div>
            <div class="col-12 col-xl-6">
              <div class="card border-0 shadow-sm h-100 ops-panel">
                <div class="card-header bg-light fw-semibold"><i class="bi bi-graph-down-arrow me-1"></i>Bottom 3 Performers (Current Month)</div>
                <div class="card-body">
                  <div class="row g-3">
                    <?php if (empty($bottom3)): ?>
                      <div class="col-12 text-center text-muted py-4">No target data</div>
                    <?php else: ?>
                      <?php foreach ($bottom3 as $idx => $r): ?>
                        <?php $podium = getPerformerPodiumMeta((int)$idx, 'bottom'); ?>
                        <div class="col-12">
                          <div class="perf-person-card p-3">
                            <div class="d-flex align-items-start justify-content-between gap-3">
                              <div class="d-flex gap-3">
                                <div class="performer-rank-stack">
                                  <span class="performer-podium-badge <?php echo htmlspecialchars($podium['class']); ?>"><i class="bi bi-trophy-fill"></i><?php echo htmlspecialchars($podium['label']); ?></span>
                                  <span class="performer-podium-note"><?php echo htmlspecialchars($podium['place']); ?></span>
                                </div>
                                <div>
                                  <div class="fw-semibold"><?php echo htmlspecialchars((string)($r['name'] ?? '')); ?></div>
                                  <div class="small text-muted">Performance tier: <?php echo htmlspecialchars((string)(($r['rank'] ?? '') !== '' ? $r['rank'] : 'Unranked')); ?></div>
                                </div>
                              </div>
                            </div>
                            <div class="row g-2 mt-3">
                              <div class="col-4">
                                <div class="small text-muted">Productivity</div>
                                <div class="fw-semibold"><?php echo number_format((float)($r['overall_percent'] ?? 0), 1); ?>%</div>
                              </div>
                              <div class="col-4">
                                <div class="small text-muted">Earned Incentive</div>
                                <div class="fw-semibold"><?php echo htmlspecialchars((string)($r['earned_incentive'] ?? 'No')); ?></div>
                              </div>
                              <div class="col-4 text-end">
                                <div class="small text-muted">Amount</div>
                                <div class="fw-semibold">Rs. <?php echo number_format((int)($r['range_incentive'] ?? 0)); ?></div>
                              </div>
                            </div>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="card-footer bg-white">
                  <div class="fst-italic text-muted small">Low performers to watch and coach.</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-4">
      <div class="card border-0 shadow-sm h-100 ops-panel">
        <div class="card-header bg-light fw-semibold">Attendance Details</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-6">
              <div class="p-3 bg-light rounded-3 h-100">
                <div class="small text-muted">Present Today</div>
                <div class="h4 mb-0"><?php echo number_format((int)$attendanceToday['logged_in']); ?></div>
              </div>
            </div>
            <div class="col-6">
              <div class="p-3 bg-light rounded-3 h-100">
                <div class="small text-muted">Late Today</div>
                <div class="h4 mb-0 text-warning"><?php echo number_format((int)$attendanceToday['late']); ?></div>
              </div>
            </div>
            <div class="col-6">
              <div class="p-3 bg-light rounded-3 h-100">
                <div class="small text-muted">Present Days</div>
                <div class="h4 mb-0"><?php echo htmlspecialchars($formatPresentDays((float)$attendanceRange['present_days'])); ?></div>
                <div class="small text-muted"><?php echo htmlspecialchars($rangeLabel); ?></div>
              </div>
            </div>
            <div class="col-6">
              <div class="p-3 bg-light rounded-3 h-100">
                <div class="small text-muted">Avg Work Hours</div>
                <div class="h4 mb-0"><?php echo htmlspecialchars($formatHoursMinutes((int)$attendanceRange['avg_work_minutes'])); ?></div>
                <div class="small text-muted">Late days: <?php echo number_format((int)$attendanceRange['late_days']); ?></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card border-0 shadow-sm h-100 ops-panel">
        <div class="card-header bg-light fw-semibold">Performance Cards</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-6">
              <div class="p-3 bg-light rounded-3 h-100">
                <div class="small text-muted">Qualification Rate</div>
                <div class="h4 mb-0 text-success">
                  <?php echo number_format($campaignPerfTotals['total_leads'] > 0 ? (($campaignPerfTotals['qualified'] / $campaignPerfTotals['total_leads']) * 100) : 0, 1); ?>%
                </div>
                <div class="small text-muted">Quality % of total generated</div>
              </div>
            </div>
            <div class="col-6">
              <div class="p-3 bg-light rounded-3 h-100">
                <div class="small text-muted">DQ Rate</div>
                <div class="h4 mb-0 text-danger">
                  <?php echo number_format($campaignPerfTotals['scored'] > 0 ? (($campaignPerfTotals['disqualified'] / $campaignPerfTotals['scored']) * 100) : 0, 1); ?>%
                </div>
                <div class="small text-muted">Disqualification % of scored leads</div>
              </div>
            </div>
            <div class="col-6">
              <div class="p-3 bg-light rounded-3 h-100">
                <div class="small text-muted"><?php echo $isTeamView ? 'Team Range Incentives' : 'My Range Incentives'; ?></div>
                <div class="h4 mb-0">Rs. <?php echo number_format($rangeIncentiveDisplay); ?></div>
                <div class="small text-muted"><?php echo htmlspecialchars($rangeLabel); ?></div>
              </div>
            </div>
            <div class="col-6">
              <div class="p-3 bg-light rounded-3 h-100">
                <div class="small text-muted"><?php echo $isTeamView ? 'Avg Range Performance' : 'Range Performance'; ?></div>
                <div class="h4 mb-0 text-primary"><?php echo number_format($rangePerformanceDisplay, 1); ?>%</div>
                <div class="small text-muted"><?php echo htmlspecialchars($rangeLabel); ?></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-light fw-semibold">Campaign Performance Mix</div>
        <div class="card-body">
          <canvas id="opsCampaignPerfChart" height="220"></canvas>
        </div>
        <div class="card-footer bg-white">
          <div class="text-muted small">Qualified and disqualified values follow QA completion in the selected range, while pending counts follow lead creation date so the campaign mix matches current performance scoring.</div>
        </div>
      </div>
    </div>
  </div>

  <div class="card border-0 shadow-sm mt-3 ops-panel">
    <div class="card-header d-flex align-items-center justify-content-between">
      <div class="fw-semibold"><?php echo $isTeamView ? 'Team Campaign Performance' : 'My Campaign Performance'; ?></div>
      <div class="text-muted small"><?php echo htmlspecialchars($rangeLabel); ?></div>
    </div>
    <div class="ops-table-shell">
    <div class="table-responsive">
      <table class="table table-sm mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>Campaign</th>
            <th class="text-end">Total</th>
            <th class="text-end">Qualified</th>
            <th class="text-end">Disqualified</th>
            <th class="text-end">Qual Rate %</th>
            <th class="text-end">DQ Rate %</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($campaignPerfRows)): ?>
            <tr><td colspan="6" class="text-center text-muted py-3">No campaign performance data found.</td></tr>
          <?php else: ?>
            <?php foreach ($campaignPerfRows as $row): ?>
              <tr>
                <td><span class="ops-cell-primary"><?php echo htmlspecialchars((string)($row['campaign_name'] ?? '')); ?></span><span class="ops-cell-secondary">Performance mix in selected range</span></td>
                <td class="text-end font-monospace"><?php echo number_format((int)($row['total_leads'] ?? 0)); ?></td>
                <td class="text-end font-monospace text-success"><?php echo number_format((int)($row['qualified'] ?? 0)); ?></td>
                <td class="text-end font-monospace text-danger"><?php echo number_format((int)($row['disqualified'] ?? 0)); ?></td>
                <td class="text-end font-monospace fw-semibold text-success"><?php echo number_format((float)($row['qualification_rate'] ?? 0), 1); ?>%</td>
                <td class="text-end font-monospace fw-semibold text-danger"><?php echo number_format((float)($row['dq_rate'] ?? 0), 1); ?>%</td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="ops-table-note">Campaign totals follow the same selected-range logic as the performance cards: qualified and disqualified counts use QA completion date, while pending counts use lead creation date.</div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(() => {
  if (!window.Chart) return;
  const perf = <?php echo json_encode($campaignPerfChart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  const el = document.getElementById('opsCampaignPerfChart');
  if (!el || !perf || !Array.isArray(perf.labels)) return;
  new Chart(el, {
    type: 'bar',
    data: {
      labels: perf.labels,
      datasets: [
        { label: 'Qualification Rate %', data: perf.qualified || [], backgroundColor: 'rgba(34, 197, 94, 0.75)' },
        { label: 'DQ Rate %', data: perf.disqualified || [], backgroundColor: 'rgba(239, 68, 68, 0.75)' }
      ]
    },
    options: {
      responsive: true,
      plugins: { legend: { position: 'bottom' } },
      scales: {
        x: { ticks: { autoSkip: false, maxRotation: 25, minRotation: 0 } },
        y: { beginAtZero: true, max: 100, ticks: { callback: function(value) { return value + '%'; } } }
      }
    }
  });
})();
</script>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
