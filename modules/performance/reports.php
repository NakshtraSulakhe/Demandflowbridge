<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requirePermissionOrRole('funneltrack360.reports.page', []);
ensureDatabaseSchema();

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$canTeam = function_exists('userHasPermission') ? userHasPermission('funneltrack360.team.page') : true;
$canAdmin = function_exists('userHasPermission') ? userHasPermission('funneltrack360.admin.page') : true;

$cfg = funneltrackGetConfig();
$tz = opsBusinessTz();
$now = new DateTimeImmutable('now', $tz);
$year = (int)$now->format('Y');
$month = (int)$now->format('n');

$preset = isset($_GET['range_preset']) ? (string)$_GET['range_preset'] : 'today';
$startInput = isset($_GET['start_date']) ? (string)$_GET['start_date'] : '';
$endInput = isset($_GET['end_date']) ? (string)$_GET['end_date'] : '';
$range = funneltrackRangeFromPreset($preset, $startInput, $endInput);
$startDate = (string)($range['start'] ?? $now->format('Y-m-d'));
$endDate = (string)($range['end'] ?? $startDate);
$rangeLabel = (string)($range['label'] ?? '');
$startFieldValue = $preset === 'custom' ? $startInput : $startDate;
$endFieldValue = $preset === 'custom' ? $endInput : $endDate;
$export = isset($_GET['export']) ? strtolower(trim((string)$_GET['export'])) : '';

$conn = getDbConnection();
$visibleUsers = funneltrackGetVisiblePerformanceUsers($userId);
$visible = [];
foreach ($visibleUsers as $uid => $urow) {
    $uid = (int)$uid;
    if ($uid > 0) $visible[$uid] = true;
}

// Prepare date range for qualified leads query
$startDateStr = substr((string)$startDate, 0, 10);
$endDateStr = substr((string)$endDate, 0, 10);
$rangeUtc = function_exists('opsDateRangeUtc') ? opsDateRangeUtc($startDateStr, $endDateStr) : ['start' => $startDateStr . ' 00:00:00', 'end' => $endDateStr . ' 23:59:59'];
$rangeStartTs = (string)($rangeUtc['start'] ?? ($startDateStr . ' 00:00:00'));
$rangeEndTs = (string)($rangeUtc['end'] ?? ($endDateStr . ' 23:59:59'));

// Get qualified leads count per user
$qualifiedLeads = [];
if (!empty($visible)) {
    $ids = array_keys($visible);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $conn->prepare("
        SELECT agent_id, COUNT(*) as count 
        FROM leads 
        WHERE agent_id IN ($in) 
        AND qa_status = 'Qualified' 
        AND COALESCE(qa_updated_at, created_at) >= ?
        AND COALESCE(qa_updated_at, created_at) <= ?
        GROUP BY agent_id
    ");
    if ($stmt) {
        $params = array_merge($ids, [$rangeStartTs, $rangeEndTs]);
        mysqliBindParams($stmt, $types . 'ss', $params);
        $stmt->execute();
        $ql = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        foreach ($ql as $q) {
            $qualifiedLeads[(int)$q['agent_id']] = (int)$q['count'];
        }
    }
}

$presentDaysByUser = [];
if (!empty($visible)) {
    $ids = array_keys($visible);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $conn->prepare("
        SELECT user_id, status, COUNT(*) AS c
        FROM hr_attendance_days
        WHERE user_id IN ($in)
          AND work_date BETWEEN ? AND ?
          AND status IN ('Full Day','Present','present','Half Day','half_day')
        GROUP BY user_id, status
    ");
    if ($stmt) {
        $params = array_merge($ids, [$startDateStr, $endDateStr]);
        mysqliBindParams($stmt, $types . 'ss', $params);
        $stmt->execute();
        $aRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        foreach ($aRows as $a) {
            $uid = (int)($a['user_id'] ?? 0);
            $status = strtolower((string)($a['status'] ?? ''));
            $count = (int)($a['c'] ?? 0);
            if ($uid <= 0) continue;
            if (!isset($presentDaysByUser[$uid])) $presentDaysByUser[$uid] = 0.0;
            if ($status === 'full day' || $status === 'present') $presentDaysByUser[$uid] += $count;
            if ($status === 'half day' || $status === 'half_day') $presentDaysByUser[$uid] += ($count * 0.5);
        }
    }
}

// Get targets for the first month in range using the same method as admin.php
$targets = [];
$yy = $year;
$mm = $month;
// Check if start date is available and use that if needed
if (preg_match('/^(\d{4})-(\d{2})/', $startDateStr, $matches)) {
    $yy = (int)$matches[1];
    $mm = (int)$matches[2];
}
// Try up to 3 months to find available targets
for ($i = 0; $i < 3; $i++) {
    $tryY = $yy;
    $tryM = $mm - $i;
    while ($tryM < 1) {
        $tryM += 12;
        $tryY--;
    }
    $monthlyTargets = getMonthlyTargets($tryY, $tryM);
    if (!empty($monthlyTargets)) {
        // Reindex the array by agent_id (NOT user_id!) for quick lookup
        foreach ($monthlyTargets as $mt) {
            if (isset($mt['agent_id'])) {
                $targets[(int)$mt['agent_id']] = $mt;
            }
        }
        if (!empty($targets)) {
            break; // Found targets, stop looking
        }
    }
}

$rows = [];
if (!empty($visible)) {
    $ids = array_keys($visible);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $summaries = funneltrackComputeAgentRangeSummaries($ids, $startDate, $endDate, $cfg);
    $stmt = $conn->prepare("SELECT id, full_name, role, department FROM users WHERE id IN ($in) AND is_active = 1 ORDER BY full_name");
    if ($stmt) {
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $urows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();

        foreach ($urows as $u) {
            $uid = (int)($u['id'] ?? 0);
            if ($uid <= 0) continue;
            $sum = $summaries[$uid] ?? [];
            $t = $targets[$uid] ?? ['daily_target' => 0, 'working_days' => 0];
            $presentDays = (float)($presentDaysByUser[$uid] ?? 0.0);
            
            $rows[] = [
                'user_id' => $uid,
                'name' => (string)($u['full_name'] ?? ''),
                'department' => (string)($u['department'] ?? ''),
                'productivity_pct' => (float)($sum['productivity_pct'] ?? 0.0),
                'score' => (float)($sum['score']['total'] ?? 0.0),
                'rank_label' => (string)($sum['rank'] ?? ''),
                'range_incentive' => (int)($sum['incentives']['range_total'] ?? 0),
                'eligible' => (bool)($sum['incentives']['eligible'] ?? false),
                'daily_target' => (int)($t['daily_target'] ?? 0),
                'working_days' => (int)($t['working_days'] ?? 0),
                'qualified_leads' => (int)($qualifiedLeads[$uid] ?? 0),
                'present_days' => $presentDays,
            ];
        }
    }
}

usort($rows, fn($a, $b) => ($b['score'] <=> $a['score']) ?: ($b['productivity_pct'] <=> $a['productivity_pct']) ?: strcmp($a['name'], $b['name']));

if ($export === 'csv') {
    @set_time_limit(0);
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="performance_report_' . $startDate . '_to_' . $endDate . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Rank', 'Agent', 'Department', 'Daily Target', 'Working Days', 'Qualified Leads', 'Present Days', 'Productivity %', 'Score', 'Rank Label', 'Incentive', 'Eligible', 'Range']);
    foreach ($rows as $i => $r) {
        fputcsv($out, [
            $i + 1,
            $r['name'],
            $r['department'],
            (int)$r['daily_target'],
            (int)$r['working_days'],
            (int)$r['qualified_leads'],
            (float)$r['present_days'],
            number_format((float)$r['productivity_pct'], 2),
            number_format((float)$r['score'], 2),
            $r['rank_label'],
            (int)$r['range_incentive'],
            $r['eligible'] ? 'Yes' : 'No',
            $startDate . ' → ' . $endDate,
        ]);
    }
    fclose($out);
    exit;
}

if ($export === 'xls') {
    @set_time_limit(0);
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="performance_report_' . $startDate . '_to_' . $endDate . '.xls"');
    echo '<table border="1">';
    echo '<tr><th colspan="13">Performance Report · ' . htmlspecialchars($startDate . ' → ' . $endDate) . '</th></tr>';
    echo '<tr><th>Rank</th><th>Agent</th><th>Department</th><th>Daily Target</th><th>Working Days</th><th>Qualified Leads</th><th>Present Days</th><th>Productivity %</th><th>Score</th><th>Rank Label</th><th>Incentive</th><th>Eligible</th></tr>';
    foreach ($rows as $i => $r) {
        echo '<tr>';
        echo '<td>' . (int)($i + 1) . '</td>';
        echo '<td>' . htmlspecialchars($r['name']) . '</td>';
        echo '<td>' . htmlspecialchars($r['department']) . '</td>';
        echo '<td>' . (int)$r['daily_target'] . '</td>';
        echo '<td>' . (int)$r['working_days'] . '</td>';
        echo '<td>' . (int)$r['qualified_leads'] . '</td>';
        echo '<td>' . htmlspecialchars((string)$r['present_days']) . '</td>';
        echo '<td>' . htmlspecialchars(number_format((float)$r['productivity_pct'], 2)) . '</td>';
        echo '<td>' . htmlspecialchars(number_format((float)$r['score'], 2)) . '</td>';
        echo '<td>' . htmlspecialchars($r['rank_label']) . '</td>';
        echo '<td>' . (int)$r['range_incentive'] . '</td>';
        echo '<td>' . ($r['eligible'] ? 'Yes' : 'No') . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    exit;
}

if ($export === 'pdf') {
    @set_time_limit(0);
    while (ob_get_level() > 0) { @ob_end_clean(); }
    $pdfEscape = function(string $s): string {
        $s = str_replace("\\", "\\\\", $s);
        $s = str_replace("(", "\\(", $s);
        $s = str_replace(")", "\\)", $s);
        $s = str_replace("\r", "", $s);
        $s = str_replace("\n", "\\n", $s);
        return $s;
    };
    $drawText = function(string $text, float $x, float $y, int $size = 10) use ($pdfEscape): string {
        $t = $pdfEscape($text);
        return "BT /F1 {$size} Tf {$x} {$y} Td ({$t}) Tj ET\n";
    };

    $w = 595.28;
    $h = 841.89;
    $margin = 40;
    $y = $h - $margin;

    $content = "";
    $content .= $drawText("DemandFlow Bridge - Performance Report", $margin, $y, 14);
    $y -= 22;
    $content .= $drawText($startDate . " to " . $endDate, $margin, $y, 10);
    $y -= 14;
    $content .= $drawText("Rank  Agent  Daily Target  Working Days  Qualified Leads  Productivity%  Score  Incentive  Eligible", $margin, $y, 10);
    $y -= 14;
    $content .= "0.5 w\n";
    $content .= "{$margin} {$y} m " . ($w - $margin) . " {$y} l S\n";
    $y -= 14;

    foreach ($rows as $i => $r) {
        if ($y < $margin + 40) break;
        $line = ($i + 1) . "  " . $r['name'] . "  " . number_format((float)$r['productivity_pct'], 1) . "%  " . number_format((float)$r['score'], 1) . "  ₹" . number_format((int)$r['range_incentive']) . "  " . ($r['eligible'] ? 'Yes' : 'No');
        $content .= $drawText($line, $margin, $y, 9);
        $y -= 12;
    }

    $objects = [];
    $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$w} {$h}] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>";
    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
    $stream = $content;
    $objects[] = "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}endstream";

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $i => $obj) {
        $offsets[] = strlen($pdf);
        $n = $i + 1;
        $pdf .= "{$n} 0 obj\n{$obj}\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    foreach ($offsets as $off) {
        $pdf .= str_pad((string)$off, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="performance_report_' . $startDate . '_to_' . $endDate . '.pdf"');
    echo $pdf;
    exit;
}

$reportCount = count($rows);
$eligibleCount = 0;
$avgScore = 0.0;
$avgProductivity = 0.0;
$totalIncentive = 0;
foreach ($rows as $r) {
    if (!empty($r['eligible'])) $eligibleCount++;
    $avgScore += (float)($r['score'] ?? 0.0);
    $avgProductivity += (float)($r['productivity_pct'] ?? 0.0);
    $totalIncentive += (int)($r['range_incentive'] ?? 0);
}
if ($reportCount > 0) {
    $avgScore /= $reportCount;
    $avgProductivity /= $reportCount;
}
$rankBadgeClass = static function(int $pos): string {
    if ($pos === 1) return 'performance-rank-top1';
    if ($pos === 2) return 'performance-rank-top2';
    if ($pos === 3) return 'performance-rank-top3';
    return 'performance-rank-default';
};
$statusBadgeClass = static function(bool $eligible): string {
    return $eligible ? 'performance-status-badge performance-status-eligible' : 'performance-status-badge performance-status-pending';
};

$pageTitle = 'DemandFlow Bridge – Performance Reports';
include __DIR__ . '/../../includes/layout/app_start.php';
?>
<div class="container-fluid px-0 sales-workspace performance-workspace">
    <div class="sales-page-header sales-hero mb-3">
        <div>
            <div class="sales-page-eyebrow mb-2"><i class="bi bi-file-earmark-bar-graph"></i>Performance Reporting</div>
            <h3 class="sales-page-title">Reports</h3>
            <div class="sales-page-subtitle">Review cross-user performance outcomes, export operational snapshots, and validate targets, attendance presence, and incentive eligibility in one place.</div>
        </div>
        <div class="sales-page-actions">
            <a class="btn btn-outline-primary btn-sm" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance'); ?>"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
            <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/detail?agent_id=all&range_preset=' . urlencode((string)$preset) . '&start_date=' . urlencode((string)$startInput) . '&end_date=' . urlencode((string)$endInput)); ?>"><i class="bi bi-table me-1"></i>Daily Detail</a>
            <?php if ($canTeam): ?><a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/team'); ?>"><i class="bi bi-people me-1"></i>Team View</a><?php endif; ?>
            <?php if ($canAdmin): ?><a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/admin'); ?>"><i class="bi bi-gear me-1"></i>Admin</a><?php endif; ?>
        </div>
    </div>

    <ul class="nav nav-pills bg-light border rounded p-1 sales-pill-nav performance-nav mb-3">
        <li class="nav-item"><a class="nav-link" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance'); ?>">My Dashboard</a></li>
        <li class="nav-item"><a class="nav-link" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/detail?agent_id=all&range_preset=' . urlencode((string)$preset) . '&start_date=' . urlencode((string)$startInput) . '&end_date=' . urlencode((string)$endInput)); ?>">Daily Detail</a></li>
        <?php if ($canTeam): ?><li class="nav-item"><a class="nav-link" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/team'); ?>">Team View</a></li><?php endif; ?>
        <li class="nav-item"><a class="nav-link active" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/reports'); ?>">Reports</a></li>
        <?php if ($canAdmin): ?><li class="nav-item"><a class="nav-link" href="<?php echo htmlspecialchars(appBasePath() . '/modules/performance/admin'); ?>">Admin</a></li><?php endif; ?>
    </ul>

    <div class="row g-3 mb-3">
        <div class="col-md-6 col-xl-3"><div class="performance-callout h-100"><div class="performance-callout-title">Visible Users</div><div class="performance-callout-value"><?php echo number_format($reportCount); ?></div><div class="small text-muted mt-1"><?php echo htmlspecialchars($startDate . ' to ' . $endDate); ?></div></div></div>
        <div class="col-md-6 col-xl-3"><div class="performance-callout h-100"><div class="performance-callout-title">Eligible Users</div><div class="performance-callout-value"><?php echo number_format($eligibleCount); ?></div><div class="small text-muted mt-1"><?php echo $reportCount > 0 ? htmlspecialchars(number_format(($eligibleCount / $reportCount) * 100, 1) . '% of report rows') : 'No data in range'; ?></div></div></div>
        <div class="col-md-6 col-xl-3"><div class="performance-callout h-100"><div class="performance-callout-title">Average Score</div><div class="performance-callout-value"><?php echo number_format($avgScore, 1); ?></div><div class="small text-muted mt-1">Average across visible users</div></div></div>
        <div class="col-md-6 col-xl-3"><div class="performance-callout h-100"><div class="performance-callout-title">Incentive Total</div><div class="performance-callout-value">₹<?php echo number_format($totalIncentive); ?></div><div class="small text-muted mt-1">Range total from report output</div></div></div>
    </div>

    <div class="card border-0 shadow-sm sales-toolbar sales-panel mb-3">
        <div class="card-body">
            <div class="performance-toolbar-actions mb-2">
                <div>
                    <div class="fw-semibold">Filter And Export</div>
                    <div class="text-muted small">Adjust the analysis window, then export the same leaderboard-grade data to CSV, Excel, or PDF.</div>
                </div>
                <div class="performance-range-readout">
                    <span class="sales-chip"><i class="bi bi-calendar-range"></i><?php echo htmlspecialchars($rangeLabel); ?></span>
                    <a class="btn btn-outline-secondary btn-sm" href="?range_preset=<?php echo urlencode($preset); ?>&start_date=<?php echo urlencode($startInput); ?>&end_date=<?php echo urlencode($endInput); ?>&export=csv"><i class="bi bi-filetype-csv me-1"></i>CSV</a>
                    <a class="btn btn-outline-secondary btn-sm" href="?range_preset=<?php echo urlencode($preset); ?>&start_date=<?php echo urlencode($startInput); ?>&end_date=<?php echo urlencode($endInput); ?>&export=xls"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
                    <a class="btn btn-outline-secondary btn-sm" href="?range_preset=<?php echo urlencode($preset); ?>&start_date=<?php echo urlencode($startInput); ?>&end_date=<?php echo urlencode($endInput); ?>&export=pdf"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
                </div>
            </div>
            <form class="row g-2 align-items-end sales-filter-form" method="get">
        <div class="col-md-3">
            <label class="form-label small text-muted">Range</label>
            <select class="form-select form-select-sm" name="range_preset" onchange="this.form.submit();">
                <option value="today" <?php echo $preset==='today'?'selected':''; ?>>Today</option>
                <option value="month" <?php echo $preset==='month'?'selected':''; ?>>Month</option>
                <option value="week" <?php echo $preset==='week'?'selected':''; ?>>Week</option>
                <option value="quarter" <?php echo $preset==='quarter'?'selected':''; ?>>Quarter</option>
                <option value="year" <?php echo $preset==='year'?'selected':''; ?>>Year</option>
                <option value="custom" <?php echo $preset==='custom'?'selected':''; ?>>Custom</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted">Start</label>
            <input type="date" class="form-control form-control-sm" name="start_date" value="<?php echo htmlspecialchars($startFieldValue); ?>" <?php echo $preset==='custom'?'':'readonly'; ?>>
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted">End</label>
            <input type="date" class="form-control form-control-sm" name="end_date" value="<?php echo htmlspecialchars($endFieldValue); ?>" <?php echo $preset==='custom'?'':'readonly'; ?>>
        </div>
        <div class="col-md-3 d-flex gap-2 mt-4">
            <button class="btn btn-outline-primary btn-sm w-100" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
        </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm sales-panel">
        <div class="card-header">
            <div class="performance-table-head">
                <div>
                    <div class="performance-table-title"><i class="bi bi-table me-1"></i>Leaderboard Report</div>
                    <div class="performance-table-subtitle">The report table mirrors team view so exported and on-screen analysis use the same labels, ranking logic, and eligibility signals.</div>
                </div>
                <div class="performance-range-readout">
                    <span class="sales-chip"><i class="bi bi-people"></i><?php echo number_format($reportCount); ?> rows</span>
                </div>
            </div>
        </div>
        <div class="table-responsive sales-table-responsive performance-table-wrap">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="text-end">#</th>
                            <th>Agent</th>
                            <th>Department</th>
                            <th class="text-end">Daily Target</th>
                            <th class="text-end">Working Days</th>
                            <th class="text-end">Qualified Leads</th>
                            <th class="text-end">Present Days</th>
                            <th class="text-end">Productivity %</th>
                            <th class="text-end">Score</th>
                            <th>Rank</th>
                            <th class="text-end">Incentive</th>
                            <th>Eligibility</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="12" class="performance-empty-state">No report rows found for the selected range.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $i => $r): ?>
                                <?php $rankClass = $rankBadgeClass($i + 1); ?>
                                <tr>
                                    <td class="text-end font-monospace"><?php echo (int)($i + 1); ?></td>
                                    <td>
                                        <div class="performance-row-primary"><?php echo htmlspecialchars((string)$r['name']); ?></div>
                                        <div class="performance-row-secondary">Visible performance scope</div>
                                    </td>
                                    <td>
                                        <div class="performance-row-primary"><?php echo htmlspecialchars((string)$r['department']); ?></div>
                                        <div class="performance-row-secondary"><?php echo htmlspecialchars($startDate . ' to ' . $endDate); ?></div>
                                    </td>
                                    <td class="text-end font-monospace"><?php echo number_format((int)$r['daily_target']); ?></td>
                                    <td class="text-end font-monospace"><?php echo number_format((int)$r['working_days']); ?></td>
                                    <td class="text-end font-monospace"><?php echo number_format((int)$r['qualified_leads']); ?></td>
                                    <td class="text-end font-monospace"><?php echo number_format((int)$r['present_days']); ?></td>
                                    <td class="text-end font-monospace"><?php echo number_format((float)$r['productivity_pct'], 1); ?>%</td>
                                    <td class="text-end font-monospace"><?php echo number_format((float)$r['score'], 1); ?></td>
                                    <td><span class="performance-rank-badge <?php echo htmlspecialchars($rankClass); ?>">#<?php echo (int)($i + 1); ?> <?php echo htmlspecialchars((string)$r['rank_label']); ?></span></td>
                                    <td class="text-end font-monospace">₹<?php echo number_format((int)$r['range_incentive']); ?></td>
                                    <td><span class="<?php echo $statusBadgeClass(!empty($r['eligible'])); ?>"><?php echo !empty($r['eligible']) ? 'Eligible' : 'Not Eligible'; ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            <div class="performance-table-note">This report blends targets, qualified leads, present days, productivity percentage, final score, and incentive status so managers can audit both volume and quality together.</div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
