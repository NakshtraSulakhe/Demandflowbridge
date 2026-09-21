<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
ensureTaskManagementSchema();

$conn = getDbConnection();
$user = getCurrentUser() ?: [];
$userId = (int)($user['id'] ?? 0);
if (function_exists('userHasPermission') && !userHasPermission('tasks.reports')) {
    http_response_code(403);
    echo 'Access denied';
    exit;
}

$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$department = trim((string)($_GET['department'] ?? ''));
$assigneeId = (int)($_GET['assignee_id'] ?? 0);
$status = trim((string)($_GET['status'] ?? ''));

$statusOptions = ['','Not Started','Assigned','In Progress','Waiting For Input','On Hold','Under Review','Completed','Rejected','Cancelled','Overdue'];
$opsNow = function_exists('opsNowDt') ? opsNowDt() : new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
$opsTodayDate = function_exists('opsTodayDate') ? opsTodayDate() : $opsNow->format('Y-m-d');
$nowUtc = function_exists('appNowUtc') ? appNowUtc() : gmdate('Y-m-d H:i:s');

$where = ["1=1"];
$params = [];
$types = '';

if (($from !== '' || $to !== '') && function_exists('opsDateRangeUtc')) {
    $reportRange = opsDateRangeUtc($from !== '' ? $from : $to, $to !== '' ? $to : $from);
    if (!empty($reportRange['start'])) {
        $where[] = "t.created_at >= ?";
        $params[] = $reportRange['start'];
        $types .= 's';
    }
    if (!empty($reportRange['end'])) {
        $where[] = "t.created_at <= ?";
        $params[] = $reportRange['end'];
        $types .= 's';
    }
}
if ($department !== '') {
    $where[] = "t.department = ?";
    $params[] = $department;
    $types .= 's';
}
if ($assigneeId > 0) {
    $where[] = "ta.user_id = ?";
    $params[] = $assigneeId;
    $types .= 'i';
}
if ($status !== '' && in_array($status, $statusOptions, true)) {
    $where[] = "t.status = ?";
    $params[] = $status;
    $types .= 's';
}

$whereSql = implode(' AND ', $where);

$departments = [];
$resDept = $conn->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department <> '' ORDER BY department");
if ($resDept) {
    while ($r = $resDept->fetch_assoc()) { $departments[] = (string)($r['department'] ?? ''); }
}

$users = [];
$resU = $conn->query("SELECT id, full_name, department FROM users WHERE is_active = 1 AND (client_id IS NULL OR client_id = 0) AND (vendor_id IS NULL OR vendor_id = 0) ORDER BY full_name");
if ($resU) {
    while ($r = $resU->fetch_assoc()) { $users[] = $r; }
}

$export = strtolower(trim((string)($_GET['export'] ?? '')));
if (in_array($export, ['csv','xls','pdf'], true)) {
    @set_time_limit(0);
    while (ob_get_level() > 0) { @ob_end_clean(); }
    $sql = "
        SELECT t.task_code, t.title, t.department, t.priority, t.status, t.progress, t.due_at, t.created_at,
               cb.full_name AS created_by_name,
               GROUP_CONCAT(DISTINCT u.full_name ORDER BY u.full_name SEPARATOR ', ') AS assignees
        FROM tasks t
        LEFT JOIN users cb ON cb.id = t.created_by
        LEFT JOIN task_assignees ta ON ta.task_id = t.id
        LEFT JOIN users u ON u.id = ta.user_id
        WHERE $whereSql
        GROUP BY t.id
        ORDER BY t.created_at DESC
        LIMIT 5000
    ";
    $rows = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if ($types !== '') $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }

    if ($export === 'xls') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="task_report_' . $opsTodayDate . '.xls"');
        echo "<table border=1>";
        echo "<tr><th>Task Code</th><th>Title</th><th>Department</th><th>Priority</th><th>Status</th><th>Progress</th><th>Due</th><th>Created</th><th>Created By</th><th>Assignees</th></tr>";
        foreach ($rows as $r) {
            echo "<tr>"
                . "<td>" . htmlspecialchars((string)($r['task_code'] ?? '')) . "</td>"
                . "<td>" . htmlspecialchars((string)($r['title'] ?? '')) . "</td>"
                . "<td>" . htmlspecialchars((string)($r['department'] ?? '')) . "</td>"
                . "<td>" . htmlspecialchars((string)($r['priority'] ?? '')) . "</td>"
                . "<td>" . htmlspecialchars((string)($r['status'] ?? '')) . "</td>"
                . "<td>" . htmlspecialchars((string)($r['progress'] ?? '')) . "</td>"
                . "<td>" . htmlspecialchars((string)($r['due_at'] ?? '')) . "</td>"
                . "<td>" . htmlspecialchars((string)($r['created_at'] ?? '')) . "</td>"
                . "<td>" . htmlspecialchars((string)($r['created_by_name'] ?? '')) . "</td>"
                . "<td>" . htmlspecialchars((string)($r['assignees'] ?? '')) . "</td>"
                . "</tr>";
        }
        echo "</table>";
        exit;
    }

    if ($export === 'pdf') {
        $pdfEscape = function(string $s): string {
            return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
        };
        $makeStreamForPage = function(array $lines) use ($pdfEscape): string {
            $stream = '';
            $y = 770;
            foreach ($lines as $i => $line) {
                $size = $i === 0 ? 14 : 10;
                $x = 40;
                $stream .= "BT\n/F1 {$size} Tf\n1 0 0 1 {$x} {$y} Tm\n(" . $pdfEscape($line) . ") Tj\nET\n";
                $y -= ($i === 0 ? 18 : 12);
            }
            return $stream;
        };

        $filterLine = 'Filters: ' . ($from !== '' ? "From {$from} " : '') . ($to !== '' ? "To {$to} " : '') . ($department !== '' ? "Dept {$department} " : '') . ($assigneeId > 0 ? "Assignee #{$assigneeId} " : '') . ($status !== '' ? "Status {$status}" : '');
        $filterLine = trim(preg_replace('/\s+/', ' ', $filterLine));

        $pages = [];
        $cur = ["Task Report", $filterLine !== '' ? $filterLine : 'Filters: None', ''];
        $lineCount = 0;
        foreach ($rows as $r) {
            $line = (string)($r['task_code'] ?? '') . ' · ' . (string)($r['title'] ?? '');
            $line .= ' | ' . (string)($r['department'] ?? '') . ' | ' . (string)($r['priority'] ?? '') . ' | ' . (string)($r['status'] ?? '') . ' | ' . (string)($r['progress'] ?? '') . '%';
            $due = (string)($r['due_at'] ?? '');
            if ($due !== '') $line .= ' | Due: ' . $due;
            $cur[] = $line;
            $lineCount++;
            if ($lineCount >= 55) {
                $pages[] = $cur;
                $cur = ["Task Report (cont.)", $filterLine !== '' ? $filterLine : 'Filters: None', ''];
                $lineCount = 0;
            }
        }
        if (!empty($cur)) $pages[] = $cur;
        if (empty($pages)) $pages = [["Task Report", $filterLine !== '' ? $filterLine : 'Filters: None', 'No rows']];

        $objects = [];
        $addObj = function(string $body) use (&$objects) {
            $objects[] = $body;
            return count($objects);
        };

        $catalogNum = $addObj("<< /Type /Catalog /Pages 2 0 R >>");
        $pagesNum = $addObj("<< /Type /Pages /Kids [] /Count 0 >>");
        $fontNum = $addObj("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>");

        $kids = [];
        $contentObjNums = [];
        foreach ($pages as $pi => $lines) {
            $pageObjNum = $addObj('');
            $content = $makeStreamForPage($lines);
            $contentObjNum = $addObj("<< /Length " . strlen($content) . " >>\nstream\n{$content}\nendstream");
            $contentObjNums[] = $contentObjNum;
            $kids[] = $pageObjNum . " 0 R";
            $objects[$pageObjNum - 1] = "<< /Type /Page /Parent {$pagesNum} 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 {$fontNum} 0 R >> >> /Contents {$contentObjNum} 0 R >>";
        }
        $objects[$pagesNum - 1] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count " . count($kids) . " >>";

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $num = $i + 1;
            $pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= str_pad((string)$offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root {$catalogNum} 0 R >>\nstartxref\n{$xref}\n%%EOF";

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="task_report_' . $opsTodayDate . '.pdf"');
        echo $pdf;
        exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="task_report_' . $opsTodayDate . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['task_code','title','department','priority','status','progress','due_at','created_at','created_by','assignees']);
    foreach ($rows as $r) {
        fputcsv($out, [
            (string)($r['task_code'] ?? ''),
            (string)($r['title'] ?? ''),
            (string)($r['department'] ?? ''),
            (string)($r['priority'] ?? ''),
            (string)($r['status'] ?? ''),
            (string)($r['progress'] ?? ''),
            (string)($r['due_at'] ?? ''),
            (string)($r['created_at'] ?? ''),
            (string)($r['created_by_name'] ?? ''),
            (string)($r['assignees'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

$summary = [
    'total' => 0,
    'completed' => 0,
    'overdue' => 0,
    'pending' => 0,
];
$stmt = $conn->prepare("
    SELECT
        COUNT(DISTINCT t.id) AS total_cnt,
        SUM(CASE WHEN t.status = 'Completed' THEN 1 ELSE 0 END) AS completed_cnt,
        SUM(CASE WHEN t.due_at IS NOT NULL AND t.due_at < ? AND t.status NOT IN ('Completed','Cancelled','Rejected') THEN 1 ELSE 0 END) AS overdue_cnt,
        SUM(CASE WHEN t.status NOT IN ('Completed','Cancelled','Rejected') THEN 1 ELSE 0 END) AS pending_cnt
    FROM tasks t
    LEFT JOIN task_assignees ta ON ta.task_id = t.id
    WHERE $whereSql
");
if ($stmt) {
    $summaryTypes = $types . 's';
    $summaryParams = array_merge($params, [$nowUtc]);
    $stmt->bind_param($summaryTypes, ...$summaryParams);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $summary['total'] = (int)($r['total_cnt'] ?? 0);
    $summary['completed'] = (int)($r['completed_cnt'] ?? 0);
    $summary['overdue'] = (int)($r['overdue_cnt'] ?? 0);
    $summary['pending'] = (int)($r['pending_cnt'] ?? 0);
}

$byAssignee = [];
$sql = "
    SELECT
        u.id AS user_id, u.full_name,
        COUNT(DISTINCT t.id) AS total_cnt,
        SUM(CASE WHEN t.status = 'Completed' THEN 1 ELSE 0 END) AS completed_cnt,
        SUM(CASE WHEN t.due_at IS NOT NULL AND t.due_at < ? AND t.status NOT IN ('Completed','Cancelled','Rejected') THEN 1 ELSE 0 END) AS overdue_cnt
    FROM tasks t
    JOIN task_assignees ta ON ta.task_id = t.id
    JOIN users u ON u.id = ta.user_id
    WHERE $whereSql
    GROUP BY u.id
    ORDER BY overdue_cnt DESC, total_cnt DESC
    LIMIT 200
";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $assigneeTypes = $types . 's';
    $assigneeParams = array_merge($params, [$nowUtc]);
    $stmt->bind_param($assigneeTypes, ...$assigneeParams);
    $stmt->execute();
    $byAssignee = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$pageTitle = 'Task Reports';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1">Task Reports</h3>
      <div class="text-muted small">Performance and SLA reporting with export.</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars(appBasePath() . '/modules/tasks'); ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
      <a class="btn btn-outline-primary btn-sm" href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>"><i class="bi bi-file-earmark-arrow-down me-1"></i>CSV</a>
      <a class="btn btn-outline-primary btn-sm" href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'xls'])); ?>"><i class="bi bi-file-earmark-spreadsheet me-1"></i>XLS</a>
      <a class="btn btn-outline-primary btn-sm" href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'pdf'])); ?>"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <form class="row g-2 align-items-end" method="get">
        <div class="col-lg-2">
          <label class="form-label small text-muted">From</label>
          <input type="date" class="form-control form-control-sm" name="from" value="<?php echo htmlspecialchars($from); ?>">
        </div>
        <div class="col-lg-2">
          <label class="form-label small text-muted">To</label>
          <input type="date" class="form-control form-control-sm" name="to" value="<?php echo htmlspecialchars($to); ?>">
        </div>
        <div class="col-lg-3">
          <label class="form-label small text-muted">Department</label>
          <select class="form-select form-select-sm" name="department">
            <option value="">All</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?php echo htmlspecialchars($d); ?>" <?php echo $department === $d ? 'selected' : ''; ?>><?php echo htmlspecialchars($d); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-lg-3">
          <label class="form-label small text-muted">Assignee</label>
          <select class="form-select form-select-sm" name="assignee_id">
            <option value="0">All</option>
            <?php foreach ($users as $u): ?>
              <option value="<?php echo (int)$u['id']; ?>" <?php echo $assigneeId === (int)$u['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)($u['full_name'] ?? '')); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-lg-2">
          <label class="form-label small text-muted">Status</label>
          <select class="form-select form-select-sm" name="status">
            <?php foreach ($statusOptions as $s): ?>
              <option value="<?php echo htmlspecialchars($s); ?>" <?php echo $status === $s ? 'selected' : ''; ?>><?php echo $s === '' ? 'All' : htmlspecialchars($s); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-lg-12 d-flex justify-content-end">
          <button class="btn btn-outline-primary btn-sm"><i class="bi bi-filter me-1"></i>Apply</button>
        </div>
      </form>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-3">
      <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Total</div><div class="fs-4 fw-semibold"><?php echo number_format($summary['total']); ?></div></div></div>
    </div>
    <div class="col-md-3">
      <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Pending</div><div class="fs-4 fw-semibold"><?php echo number_format($summary['pending']); ?></div></div></div>
    </div>
    <div class="col-md-3">
      <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Overdue</div><div class="fs-4 fw-semibold text-danger"><?php echo number_format($summary['overdue']); ?></div></div></div>
    </div>
    <div class="col-md-3">
      <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Completed</div><div class="fs-4 fw-semibold text-success"><?php echo number_format($summary['completed']); ?></div></div></div>
    </div>
  </div>

  <div class="card border-0 shadow-sm">
    <div class="card-header bg-light fw-semibold">Employee Task Report</div>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th class="ps-3">User</th>
            <th>Total</th>
            <th>Completed</th>
            <th>Overdue</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($byAssignee)): ?>
            <tr><td colspan="4" class="text-center text-muted py-4">No data.</td></tr>
          <?php else: ?>
            <?php foreach ($byAssignee as $r): ?>
              <tr>
                <td class="ps-3 fw-semibold"><?php echo htmlspecialchars((string)($r['full_name'] ?? '')); ?></td>
                <td><?php echo number_format((int)($r['total_cnt'] ?? 0)); ?></td>
                <td class="text-success"><?php echo number_format((int)($r['completed_cnt'] ?? 0)); ?></td>
                <td class="text-danger"><?php echo number_format((int)($r['overdue_cnt'] ?? 0)); ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
