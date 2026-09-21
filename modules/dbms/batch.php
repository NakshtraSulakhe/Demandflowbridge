<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requirePermissionOrRole('dbms.reports', ['admin']);
ensureDatabaseSchema();
ensureCsrfToken();

$conn = getDbConnection();
$currentUser = getCurrentUser() ?: [];
$currentRole = normalizeRole((string)($currentUser['role'] ?? ''));
$canDeleteInvalidRecords = in_array($currentRole, ['admin', 'director', 'manager_director', 'operations_director', 'operations_manager'], true);
$message = '';
$messageType = 'success';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$canDeleteInvalidRecords) {
            throw new RuntimeException('You are not allowed to delete invalid DBMS records.');
        }
        if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Invalid request token.');
        }
        $action = trim((string)($_POST['action'] ?? ''));
        if ($action === 'delete_invalid_batch_row') {
            $deleted = dbmsDeleteInvalidBatchRow((int)($_POST['batch_row_id'] ?? 0), (int)($currentUser['id'] ?? 0), 'dbms.batch');
            $message = 'Invalid DBMS row removed successfully.';
            if ((int)($deleted['batch_id'] ?? 0) > 0) {
                $id = (int)$deleted['batch_id'];
            }
        } elseif ($action === 'delete_invalid_batch_rows') {
            $rowIds = $_POST['batch_row_ids'] ?? [];
            if (!is_array($rowIds)) {
                $rowIds = [];
            }
            $rowIds = array_values(array_unique(array_filter(array_map('intval', $rowIds), static fn($value) => $value > 0)));
            if (empty($rowIds)) {
                throw new RuntimeException('Select invalid rows to delete.');
            }
            $deletedCount = 0;
            foreach ($rowIds as $rowId) {
                dbmsDeleteInvalidBatchRow($rowId, (int)($currentUser['id'] ?? 0), 'dbms.batch');
                $deletedCount++;
            }
            $message = 'Deleted ' . number_format($deletedCount) . ' invalid DBMS rows.';
        } elseif ($action === 'delete_invalid_contact') {
            $deleted = dbmsDeleteInvalidContact((int)($_POST['contact_id'] ?? 0), (int)($currentUser['id'] ?? 0), 'dbms.batch');
            $message = 'Invalid DBMS record removed successfully.';
            if ((int)($deleted['batch_id'] ?? 0) > 0) {
                $id = (int)$deleted['batch_id'];
            }
        } elseif ($action === 'delete_invalid_contacts') {
            $contactIds = $_POST['contact_ids'] ?? [];
            if (!is_array($contactIds)) {
                $contactIds = [];
            }
            $contactIds = array_values(array_unique(array_filter(array_map('intval', $contactIds), static fn($value) => $value > 0)));
            if (empty($contactIds)) {
                throw new RuntimeException('Select invalid records to delete.');
            }
            $deletedCount = 0;
            foreach ($contactIds as $contactId) {
                dbmsDeleteInvalidContact($contactId, (int)($currentUser['id'] ?? 0), 'dbms.batch');
                $deletedCount++;
            }
            $message = 'Deleted ' . number_format($deletedCount) . ' invalid DBMS records.';
        } else {
            throw new RuntimeException('Invalid action.');
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

$mode = strtolower(trim((string)($_GET['mode'] ?? 'import')));
if (!in_array($mode, ['import','workflow'], true)) $mode = 'import';

$tab = strtolower(trim((string)($_GET['tab'] ?? 'all')));
if ($mode === 'import') {
    if (!in_array($tab, ['all','valid','append','rejected','duplicate'], true)) $tab = 'all';
} else {
    if (!in_array($tab, ['all','pending_research','research_assigned','research_rework','pending_cdqa','cdqa_assigned','approved','rejected','duplicate'], true)) $tab = 'all';
}

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$pageSize = $id > 0 ? 50 : 50;
$offset = ($page - 1) * $pageSize;

$batch = null;
if ($id > 0) {
    $stmt = $conn->prepare("
        SELECT b.*, u.full_name AS uploaded_by_name
        FROM dbms_batches b
        LEFT JOIN users u ON u.id = b.uploaded_by
        WHERE b.id = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $batch = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }
}

if ($id > 0 && !$batch) {
    $id = 0;
}

$batches = [];
$recentBatches = [];
if ($id <= 0) {
    $q = trim((string)($_GET['q'] ?? ''));
    $uploaderId = (int)($_GET['uploader'] ?? 0);
    $statusFilter = trim((string)($_GET['status'] ?? ''));
    $fromDate = trim((string)($_GET['from'] ?? ''));
    $toDate = trim((string)($_GET['to'] ?? ''));

    $where = [];
    $params = [];
    $types = '';

    if ($q !== '') {
        $where[] = "b.original_filename LIKE ?";
        $params[] = '%' . $q . '%';
        $types .= 's';
    }
    if ($uploaderId > 0) {
        $where[] = "b.uploaded_by = ?";
        $params[] = $uploaderId;
        $types .= 'i';
    }
    if ($statusFilter !== '') {
        $where[] = "b.status = ?";
        $params[] = $statusFilter;
        $types .= 's';
    }
    if ($fromDate !== '') {
        $where[] = "DATE(b.uploaded_at) >= ?";
        $params[] = $fromDate;
        $types .= 's';
    }
    if ($toDate !== '') {
        $where[] = "DATE(b.uploaded_at) <= ?";
        $params[] = $toDate;
        $types .= 's';
    }

    $whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));

    $totalBatches = 0;
    $stmtCount = $conn->prepare("SELECT COUNT(*) AS c FROM dbms_batches b {$whereSql}");
    if ($stmtCount) {
        mysqliBindParams($stmtCount, $types, $params);
        $stmtCount->execute();
        $totalBatches = (int)(($stmtCount->get_result()->fetch_assoc() ?: [])['c'] ?? 0);
        $stmtCount->close();
    }

    $stmtBatches = $conn->prepare("
        SELECT b.*, u.full_name AS uploaded_by_name
        FROM dbms_batches b
        LEFT JOIN users u ON u.id = b.uploaded_by
        {$whereSql}
        ORDER BY b.uploaded_at DESC, b.id DESC
        LIMIT {$pageSize} OFFSET {$offset}
    ");
    if ($stmtBatches) {
        mysqliBindParams($stmtBatches, $types, $params);
        $stmtBatches->execute();
        $batches = $stmtBatches->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmtBatches->close();
    }

    $stmtRecent = $conn->prepare("
        SELECT b.*, u.full_name AS uploaded_by_name
        FROM dbms_batches b
        LEFT JOIN users u ON u.id = b.uploaded_by
        ORDER BY b.uploaded_at DESC, b.id DESC
        LIMIT 4
    ");
    if ($stmtRecent) {
        $stmtRecent->execute();
        $recentBatches = $stmtRecent->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmtRecent->close();
    }

    $batchIds = array_map(fn($b) => (int)($b['id'] ?? 0), $batches);
    $batchIds = array_values(array_filter($batchIds, fn($x) => $x > 0));
    $workflowCountsByBatch = [];
    if (!empty($batchIds)) {
        $in = implode(',', array_fill(0, count($batchIds), '?'));
        $typesIn = str_repeat('i', count($batchIds));
        $workflowExpr = dbmsWorkflowStatusSql('c');
        $sqlCounts = "
            SELECT c.batch_id,
                   COUNT(*) AS total_contacts,
                   SUM({$workflowExpr} = 'pending_research') AS pending_research,
                   SUM({$workflowExpr} = 'pending_cdqa') AS pending_cdqa,
                   SUM({$workflowExpr} = 'approved') AS approved,
                   SUM({$workflowExpr} = 'research_rework') AS research_rework,
                   SUM({$workflowExpr} = 'rejected') AS rejected,
                   SUM({$workflowExpr} = 'duplicate') AS duplicate
            FROM dbms_contacts c
            WHERE c.batch_id IN ({$in})
            GROUP BY c.batch_id
        ";
        $stmtW = $conn->prepare($sqlCounts);
        if ($stmtW) {
            mysqliBindParams($stmtW, $typesIn, $batchIds);
            $stmtW->execute();
            $rowsW = $stmtW->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmtW->close();
            foreach ($rowsW as $rw) {
                $workflowCountsByBatch[(int)($rw['batch_id'] ?? 0)] = $rw;
            }
        }
    }

    $uploaderOptions = [];
    $rsU = $conn->query("
        SELECT b.uploaded_by AS id, u.full_name AS name, COUNT(*) AS c
        FROM dbms_batches b
        LEFT JOIN users u ON u.id = b.uploaded_by
        GROUP BY b.uploaded_by
        ORDER BY u.full_name
    ");
    if ($rsU) $uploaderOptions = $rsU->fetch_all(MYSQLI_ASSOC) ?: [];

    $statusOptions = [];
    $rsS = $conn->query("SELECT DISTINCT COALESCE(status,'') AS status FROM dbms_batches ORDER BY status");
    if ($rsS) $statusOptions = array_values(array_filter(array_map(fn($r) => (string)($r['status'] ?? ''), $rsS->fetch_all(MYSQLI_ASSOC) ?: []), fn($x) => trim($x) !== ''));
}

$rows = [];
$totalRows = 0;
$workflowCounts = [
    'total_contacts' => 0,
    'pending_research' => 0,
    'pending_cdqa' => 0,
    'approved' => 0,
    'research_rework' => 0,
    'rejected' => 0,
    'duplicate' => 0,
    'research_assigned' => 0,
    'cdqa_assigned' => 0,
];
if ($id > 0) {
    $workflowExpr = dbmsWorkflowStatusSql('c');
    $workflowBatchWhere = "(c.batch_id = ? OR c.id IN (
        SELECT br.contact_id
        FROM dbms_batch_rows br
        WHERE br.batch_id = ?
          AND br.contact_id IS NOT NULL
    ))";
    $stmtWc = $conn->prepare("
        SELECT
          COUNT(*) AS total_contacts,
          SUM({$workflowExpr} = 'pending_research') AS pending_research,
          SUM({$workflowExpr} = 'research_assigned') AS research_assigned,
          SUM({$workflowExpr} = 'research_rework') AS research_rework,
          SUM({$workflowExpr} = 'pending_cdqa') AS pending_cdqa,
          SUM({$workflowExpr} = 'cdqa_assigned') AS cdqa_assigned,
          SUM({$workflowExpr} = 'approved') AS approved,
          SUM({$workflowExpr} = 'rejected') AS rejected,
          SUM({$workflowExpr} = 'duplicate') AS duplicate
        FROM dbms_contacts c
        WHERE {$workflowBatchWhere}
    ");
    if ($stmtWc) {
        $stmtWc->bind_param('ii', $id, $id);
        $stmtWc->execute();
        $wrow = $stmtWc->get_result()->fetch_assoc() ?: [];
        foreach ($workflowCounts as $k => $_) {
            $workflowCounts[$k] = (int)($wrow[$k] ?? 0);
        }
        $stmtWc->close();
    }

    if ($mode === 'import') {
        $where = "r.batch_id = ?";
        if ($tab !== 'all') $where .= " AND r.status = '" . $conn->real_escape_string($tab) . "'";
        $stmtC = $conn->prepare("SELECT COUNT(*) AS c FROM dbms_batch_rows r WHERE $where");
        if ($stmtC) {
            $stmtC->bind_param('i', $id);
            $stmtC->execute();
            $totalRows = (int)(($stmtC->get_result()->fetch_assoc() ?: [])['c'] ?? 0);
            $stmtC->close();
        }

        $stmtR = $conn->prepare("
            SELECT r.*, c.company_name, c.job_title, c.email, c.country, c.duplicate_of_contact_id, c.revenue_link
            FROM dbms_batch_rows r
            LEFT JOIN dbms_contacts c ON c.id = r.contact_id
            WHERE $where
            ORDER BY r.row_num ASC, r.id ASC
            LIMIT $pageSize OFFSET $offset
        ");
        if ($stmtR) {
            $stmtR->bind_param('i', $id);
            $stmtR->execute();
            $rows = $stmtR->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmtR->close();
        }
    } else {
        $where = $workflowBatchWhere;
        $params = [$id, $id];
        $types = 'ii';
        if ($tab !== 'all') {
            $where .= " AND {$workflowExpr} = ?";
            $params[] = $tab;
            $types .= 's';
        }
        $stmtC = $conn->prepare("SELECT COUNT(*) AS c FROM dbms_contacts c WHERE {$where}");
        if ($stmtC) {
            mysqliBindParams($stmtC, $types, $params);
            $stmtC->execute();
            $totalRows = (int)(($stmtC->get_result()->fetch_assoc() ?: [])['c'] ?? 0);
            $stmtC->close();
        }
        $stmtR = $conn->prepare("
            SELECT c.*,
                   {$workflowExpr} AS workflow_queue,
                   u.full_name AS assigned_to_name
            FROM dbms_contacts c
            LEFT JOIN users u ON u.id = c.assigned_to_user_id
            WHERE {$where}
            ORDER BY c.id DESC
            LIMIT {$pageSize} OFFSET {$offset}
        ");
        if ($stmtR) {
            mysqliBindParams($stmtR, $types, $params);
            $stmtR->execute();
            $rows = $stmtR->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmtR->close();
        }
    }
}

$hasBatchProcessedRows = dbmsBatchesHasColumn('processed_rows');
$hasBatchPendingCdqaRows = dbmsBatchesHasColumn('pending_cdqa_rows');
$hasBatchPendingResearchRows = dbmsBatchesHasColumn('pending_research_rows');
$hasBatchApprovedRows = dbmsBatchesHasColumn('approved_rows');
$hasBatchReworkRows = dbmsBatchesHasColumn('rework_rows');
$hasBatchStartedAt = dbmsBatchesHasColumn('started_at');
$hasBatchCompletedAt = dbmsBatchesHasColumn('completed_at');

$pageTitle = $id > 0 ? ('DBMS Batch #' . $id) : 'DBMS Batches';
include __DIR__ . '/../../includes/layout/app_start.php';

$kpi = function(string $label, string $value, string $icon, string $sub = ''): void {
    ?>
    <div class="hr-kpi h-100">
        <div class="d-flex justify-content-between align-items-start gap-2">
            <div class="hr-kpi-label"><?php echo htmlspecialchars($label); ?></div>
            <i class="bi <?php echo htmlspecialchars($icon); ?> text-muted"></i>
        </div>
        <div class="hr-kpi-value"><?php echo htmlspecialchars($value); ?></div>
        <?php if ($sub !== ''): ?>
            <div class="hr-kpi-sub"><?php echo htmlspecialchars($sub); ?></div>
        <?php endif; ?>
    </div>
    <?php
};
$formatDateTime = static function(string $value, string $fallback = 'Not available'): string {
    $ts = strtotime($value);
    return $ts ? date('d M, Y H:i', $ts) : $fallback;
};
$batchTone = static function(string $value): string {
    $value = strtolower(trim($value));
    return match ($value) {
        'approved', 'complete', 'completed', 'processed', 'valid' => 'tone-success',
        'append', 'pending_research', 'research_assigned', 'research_rework' => 'tone-warning',
        'pending_cdqa', 'cdqa_assigned' => 'tone-info',
        'duplicate' => 'tone-company',
        'rejected', 'failed', 'error' => 'tone-danger',
        default => 'tone-slate',
    };
};
$workflowBadgeClass = static function(string $queue): string {
    $queue = strtolower(trim($queue));
    return match ($queue) {
        'approved' => 'dbms-status-badge dbms-status-success',
        'pending_research', 'research_assigned', 'research_rework' => 'dbms-status-badge dbms-status-warning',
        'pending_cdqa', 'cdqa_assigned' => 'dbms-status-badge dbms-status-info',
        'rejected' => 'dbms-status-badge dbms-status-danger',
        'duplicate' => 'dbms-status-badge dbms-status-slate',
        default => 'dbms-status-badge dbms-status-slate',
    };
};
?>

<div class="container-fluid px-0 dbms-workspace dbms-batch-workspace">
  <?php if ($message !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> mb-3"><?php echo htmlspecialchars($message); ?></div>
  <?php endif; ?>
  <div class="dbms-page-header mb-3">
    <div class="dbms-hero flex-grow-1">
      <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
        <div>
          <span class="dbms-eyebrow"><i class="bi bi-box-seam"></i>DBMS Batch Review</span>
          <div class="dbms-page-title mt-2"><?php echo $id > 0 ? ('Batch #' . (int)$id . ' Detail') : 'DBMS Upload Batches'; ?></div>
          <div class="dbms-page-subtitle">
            <?php echo $id > 0
                ? 'Review import quality, workflow progress, and record-level movement for this batch from one CRM-aligned workspace.'
                : 'Review uploaded DBMS files, monitor processing outcomes, and jump into batch-level audit or workflow detail.'; ?>
          </div>
        </div>
        <div class="dbms-chip-group">
          <span class="dbms-chip tone-info">DBMS</span>
          <span class="dbms-chip tone-slate"><?php echo $id > 0 ? ('Batch #' . (int)$id) : 'Batch Registry'; ?></span>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <?php if ($id <= 0): ?>
      <div class="col-12">
        <div class="card hr-card dbms-panel">
          <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
            <div>
              <div><i class="bi bi-bar-chart me-1"></i>Upload Batches</div>
              <div class="dbms-note">Review uploaded files, filter by uploader and status, and open each batch into import audit or workflow progress.</div>
            </div>
            <?php if (userHasPermission('dbms.upload')): ?>
              <a class="btn btn-primary btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/upload"><i class="bi bi-cloud-arrow-up me-1"></i>Upload</a>
            <?php endif; ?>
          </div>
          <div class="card-body">
            <form method="get" class="row g-2 align-items-end dbms-filter-form mb-0">
              <div class="col-lg-4">
                <label class="form-label small text-muted">File Search</label>
                <input class="form-control form-control-sm" name="q" value="<?php echo htmlspecialchars($q ?? ''); ?>" placeholder="Filename contains...">
              </div>
              <div class="col-lg-3">
                <label class="form-label small text-muted">Uploader</label>
                <select class="form-select form-select-sm" name="uploader">
                  <option value="0">All</option>
                  <?php foreach (($uploaderOptions ?? []) as $u): ?>
                    <?php $uid = (int)($u['id'] ?? 0); ?>
                    <option value="<?php echo $uid; ?>" <?php echo ($uploaderId ?? 0) === $uid ? 'selected' : ''; ?>>
                      <?php echo htmlspecialchars((string)($u['name'] ?? '')); ?> (<?php echo number_format((int)($u['c'] ?? 0)); ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-lg-2">
                <label class="form-label small text-muted">Status</label>
                <select class="form-select form-select-sm" name="status">
                  <option value="">All</option>
                  <?php foreach (($statusOptions ?? []) as $st): ?>
                    <option value="<?php echo htmlspecialchars($st); ?>" <?php echo ($statusFilter ?? '') === $st ? 'selected' : ''; ?>><?php echo htmlspecialchars($st); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-lg-3">
                <label class="form-label small text-muted">Upload Date</label>
                <div class="d-flex gap-2">
                  <input class="form-control form-control-sm" type="date" name="from" value="<?php echo htmlspecialchars($fromDate ?? ''); ?>">
                  <input class="form-control form-control-sm" type="date" name="to" value="<?php echo htmlspecialchars($toDate ?? ''); ?>">
                </div>
              </div>
              <div class="col-12 d-flex gap-2 flex-wrap">
                <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-search me-1"></i>Apply</button>
                <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/batch"><i class="bi bi-x me-1"></i>Reset</a>
              </div>
            </form>
          </div>

          <div class="dbms-table-toolbar">
            <div class="dbms-table-toolbar-main">
              <span class="dbms-table-count"><?php echo number_format((int)($totalBatches ?? 0)); ?> batches</span>
              <span class="dbms-table-count"><?php echo htmlspecialchars(($statusFilter ?? '') !== '' ? ('Status · ' . (string)$statusFilter) : 'All statuses'); ?></span>
              <span class="dbms-table-count"><?php echo htmlspecialchars(($q ?? '') !== '' ? ('Search · ' . (string)$q) : 'Latest uploads'); ?></span>
            </div>
            <div class="dbms-table-toolbar-side">
              <span class="dbms-note">Each row shows upload volume, processed coverage, workflow backlog, and a direct path into the full batch review.</span>
            </div>
          </div>

          <?php if (!empty($recentBatches)): ?>
            <div class="dbms-batch-recent-section">
              <div class="dbms-table-toolbar">
                <div class="dbms-table-toolbar-main">
                  <span class="dbms-table-count">Recent Upload Batches</span>
                </div>
                <div class="dbms-table-toolbar-side">
                  <span class="dbms-note">Latest uploaded files across DBMS, available for quick review.</span>
                </div>
              </div>
              <div class="dbms-batch-recent-grid">
                <?php foreach ($recentBatches as $rb): ?>
                  <?php
                    $rbId = (int)($rb['id'] ?? 0);
                    $rbTotal = (int)($rb['total_rows'] ?? 0);
                    $rbProcessed = $hasBatchProcessedRows ? (int)($rb['processed_rows'] ?? 0) : $rbTotal;
                    if ($rbProcessed <= 0) $rbProcessed = $rbTotal;
                  ?>
                  <div class="dbms-batch-recent-card">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                      <div>
                        <div class="dbms-meta-title">Batch #<?php echo $rbId; ?></div>
                        <div class="dbms-meta-subtitle"><?php echo htmlspecialchars($formatDateTime((string)($rb['uploaded_at'] ?? ''), (string)($rb['uploaded_at'] ?? ''))); ?></div>
                      </div>
                      <span class="dbms-chip <?php echo htmlspecialchars($batchTone((string)($rb['status'] ?? ''))); ?>"><?php echo htmlspecialchars((string)($rb['status'] ?? '')); ?></span>
                    </div>
                    <div class="dbms-batch-recent-file"><?php echo htmlspecialchars((string)($rb['original_filename'] ?? '')); ?></div>
                    <div class="dbms-batch-recent-meta">
                      <span>Uploader: <?php echo htmlspecialchars((string)($rb['uploaded_by_name'] ?? 'System')); ?></span>
                      <span>Total: <?php echo number_format($rbTotal); ?></span>
                      <span>Processed: <?php echo number_format($rbProcessed); ?></span>
                    </div>
                    <div class="dbms-batch-recent-actions">
                      <a class="btn btn-outline-primary btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/batch?id=<?php echo $rbId; ?>">
                        <i class="bi bi-eye me-1"></i>Open Batch
                      </a>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>

          <div class="dbms-table-wrap">
            <?php if (empty($batches)): ?>
              <div class="analytics-empty">No batches found for the selected filters.</div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead>
                    <tr>
                      <th>ID</th>
                      <th>Uploaded</th>
                      <th>File</th>
                      <th>By</th>
                      <th class="text-end">Total</th>
                      <th class="text-end">Processed</th>
                      <th class="text-end">Pending Research</th>
                      <th class="text-end">Pending CDQA</th>
                      <th class="text-end">Approved</th>
                      <th class="text-end">Rework</th>
                      <th class="text-end">Rejected</th>
                      <th class="text-end">Duplicate</th>
                      <th>Status</th>
                      <th class="text-end">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($batches as $b): ?>
                      <?php
                        $bid = (int)($b['id'] ?? 0);
                        $wf = $workflowCountsByBatch[$bid] ?? null;

                        $totalRowsBatch = (int)($b['total_rows'] ?? 0);
                        $processedRowsBatch = $hasBatchProcessedRows ? (int)($b['processed_rows'] ?? 0) : $totalRowsBatch;
                        if ($processedRowsBatch <= 0) $processedRowsBatch = $totalRowsBatch;
                        $processedPct = $totalRowsBatch > 0 ? (int)round(($processedRowsBatch / $totalRowsBatch) * 100) : 0;

                        $pendingResearch = $wf ? (int)($wf['pending_research'] ?? 0) : ($hasBatchPendingResearchRows ? (int)($b['pending_research_rows'] ?? 0) : (int)($b['append_rows'] ?? 0));
                        $pendingCdqa = $wf ? (int)($wf['pending_cdqa'] ?? 0) : ($hasBatchPendingCdqaRows ? (int)($b['pending_cdqa_rows'] ?? 0) : (int)($b['valid_rows'] ?? 0));
                        $approved = $wf ? (int)($wf['approved'] ?? 0) : ($hasBatchApprovedRows ? (int)($b['approved_rows'] ?? 0) : 0);
                        $rework = $wf ? (int)($wf['research_rework'] ?? 0) : ($hasBatchReworkRows ? (int)($b['rework_rows'] ?? 0) : 0);
                        $rejected = $wf ? (int)($wf['rejected'] ?? 0) : (int)($b['rejected_rows'] ?? 0);
                        $duplicate = $wf ? (int)($wf['duplicate'] ?? 0) : (int)($b['duplicate_rows'] ?? 0);
                      ?>
                      <tr>
                        <td class="font-monospace">#<?php echo $bid; ?></td>
                        <td>
                          <div class="dbms-meta-title"><?php echo htmlspecialchars($formatDateTime((string)($b['uploaded_at'] ?? ''), (string)($b['uploaded_at'] ?? ''))); ?></div>
                          <div class="dbms-meta-subtitle"><?php echo htmlspecialchars((string)($b['status'] ?? 'uploaded')); ?></div>
                        </td>
                        <td class="dbms-company-cell">
                          <div class="dbms-meta-title"><?php echo htmlspecialchars((string)($b['original_filename'] ?? '')); ?></div>
                          <div class="dbms-meta-subtitle">Stored file ready for audit and workflow review.</div>
                        </td>
                        <td>
                          <div class="dbms-meta-title"><?php echo htmlspecialchars((string)($b['uploaded_by_name'] ?? 'System')); ?></div>
                          <div class="dbms-meta-subtitle">Uploader</div>
                        </td>
                        <td class="text-end"><?php echo number_format($totalRowsBatch); ?></td>
                        <td class="text-end">
                          <div class="dbms-owner-metric">
                            <span class="dbms-owner-metric-main"><?php echo number_format($processedRowsBatch); ?></span>
                            <span class="dbms-owner-metric-sub"><?php echo $processedPct; ?>% complete</span>
                          </div>
                        </td>
                        <td class="text-end"><?php echo number_format($pendingResearch); ?></td>
                        <td class="text-end"><?php echo number_format($pendingCdqa); ?></td>
                        <td class="text-end"><?php echo number_format($approved); ?></td>
                        <td class="text-end"><?php echo number_format($rework); ?></td>
                        <td class="text-end"><?php echo number_format($rejected); ?></td>
                        <td class="text-end"><?php echo number_format($duplicate); ?></td>
                        <td><span class="dbms-chip <?php echo htmlspecialchars($batchTone((string)($b['status'] ?? ''))); ?>"><?php echo htmlspecialchars((string)($b['status'] ?? '')); ?></span></td>
                        <td class="text-end">
                          <a class="btn btn-outline-primary btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/batch?id=<?php echo $bid; ?>"><i class="bi bi-eye me-1"></i>Open</a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>

              <?php
                $pages = (int)ceil(($totalBatches ?? 0) / $pageSize);
                $qs = $_GET;
              ?>
              <?php if ($pages > 1): ?>
                <div class="dbms-footer-bar">
                  <div class="dbms-note">Page <?php echo number_format($page); ?> / <?php echo number_format($pages); ?> · <?php echo number_format((int)($totalBatches ?? 0)); ?> batches</div>
                  <div class="btn-group btn-group-sm">
                    <?php $qs['page'] = max(1, $page - 1); ?>
                    <a class="btn btn-outline-secondary <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="?<?php echo htmlspecialchars(http_build_query($qs)); ?>">Prev</a>
                    <?php $qs['page'] = min($pages, $page + 1); ?>
                    <a class="btn btn-outline-secondary <?php echo $page >= $pages ? 'disabled' : ''; ?>" href="?<?php echo htmlspecialchars(http_build_query($qs)); ?>">Next</a>
                  </div>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php else: ?>
      <div class="col-12">
        <div class="dbms-page-header">
          <div class="dbms-hero flex-grow-1">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
              <div>
                <span class="dbms-eyebrow"><i class="bi bi-file-earmark-spreadsheet"></i>Batch Performance</span>
                <div class="dbms-page-title mt-2">Batch #<?php echo (int)$id; ?></div>
                <div class="dbms-page-subtitle">
                  <?php echo htmlspecialchars((string)($batch['original_filename'] ?? '')); ?>
                  · Uploaded <?php echo htmlspecialchars($formatDateTime((string)($batch['uploaded_at'] ?? ''), (string)($batch['uploaded_at'] ?? ''))); ?>
                  · By <?php echo htmlspecialchars((string)($batch['uploaded_by_name'] ?? 'System')); ?>
                </div>
              </div>
              <div class="dbms-batch-actions">
                <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/batch"><i class="bi bi-arrow-left me-1"></i>All Batches</a>
                <?php if (userHasPermission('dbms.explorer')): ?>
                  <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/explorer?batch_id=<?php echo (int)$id; ?>"><i class="bi bi-search me-1"></i>Explore Batch</a>
                <?php endif; ?>
                <?php if (userHasPermission('dbms.reports') && trim((string)($batch['stored_filename'] ?? '')) !== ''): ?>
                  <a class="btn btn-primary btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/files/download?kind=dbms_batch_file&id=<?php echo (int)$id; ?>"><i class="bi bi-download me-1"></i>Download File</a>
                <?php endif; ?>
              </div>
            </div>
            <?php
              $totalRowsBatch = (int)($batch['total_rows'] ?? 0);
              $processedRowsBatch = $hasBatchProcessedRows ? (int)($batch['processed_rows'] ?? 0) : $totalRowsBatch;
              if ($processedRowsBatch <= 0) $processedRowsBatch = $totalRowsBatch;
              $remainingRowsBatch = max(0, $totalRowsBatch - $processedRowsBatch);
              $processedPct = $totalRowsBatch > 0 ? (int)round(($processedRowsBatch / $totalRowsBatch) * 100) : 0;
            ?>
            <div class="dbms-chip-group mt-3">
              <span class="dbms-chip <?php echo htmlspecialchars($batchTone((string)($batch['status'] ?? ''))); ?>">
                Status · <?php echo htmlspecialchars((string)($batch['status'] ?? 'Unknown')); ?>
              </span>
              <span class="dbms-chip tone-success">Processed · <?php echo number_format($processedRowsBatch); ?> / <?php echo number_format($totalRowsBatch); ?></span>
              <span class="dbms-chip tone-warning">Pending Research · <?php echo number_format((int)($workflowCounts['pending_research'] ?? 0)); ?></span>
              <span class="dbms-chip tone-info">Pending CDQA · <?php echo number_format((int)($workflowCounts['pending_cdqa'] ?? 0)); ?></span>
            </div>
          </div>
        </div>
      </div>

      <div class="col-12">
        <div class="row g-2 dbms-batch-kpi-row">
              <div class="col-6 col-md-3 col-xl-2"><?php $kpi('Total Rows', number_format($totalRowsBatch), 'bi-list-ol'); ?></div>
              <div class="col-6 col-md-3 col-xl-2"><?php $kpi('Processed', number_format($processedRowsBatch), 'bi-hourglass-split', $processedPct . '%'); ?></div>
              <div class="col-6 col-md-3 col-xl-2"><?php $kpi('Remaining', number_format($remainingRowsBatch), 'bi-arrow-repeat'); ?></div>
              <div class="col-6 col-md-3 col-xl-2"><?php $kpi('Status', (string)($batch['status'] ?? ''), 'bi-activity'); ?></div>
            </div>
            <div class="row g-2 mt-2">
              <div class="col-6 col-md-3 col-xl-2"><?php $kpi('Pending Research', number_format((int)($workflowCounts['pending_research'] ?? 0)), 'bi-journal-text'); ?></div>
              <div class="col-6 col-md-3 col-xl-2"><?php $kpi('Research Assigned', number_format((int)($workflowCounts['research_assigned'] ?? 0)), 'bi-person-check'); ?></div>
              <div class="col-6 col-md-3 col-xl-2"><?php $kpi('Research Rework', number_format((int)($workflowCounts['research_rework'] ?? 0)), 'bi-arrow-counterclockwise'); ?></div>
              <div class="col-6 col-md-3 col-xl-2"><?php $kpi('Pending CDQA', number_format((int)($workflowCounts['pending_cdqa'] ?? 0)), 'bi-shield-check'); ?></div>
              <div class="col-6 col-md-3 col-xl-2"><?php $kpi('CDQA Assigned', number_format((int)($workflowCounts['cdqa_assigned'] ?? 0)), 'bi-person-badge'); ?></div>
              <div class="col-6 col-md-3 col-xl-2"><?php $kpi('Approved', number_format((int)($workflowCounts['approved'] ?? 0)), 'bi-patch-check'); ?></div>
              <div class="col-6 col-md-3 col-xl-2"><?php $kpi('Rejected', number_format((int)($workflowCounts['rejected'] ?? 0)), 'bi-x-circle'); ?></div>
              <div class="col-6 col-md-3 col-xl-2"><?php $kpi('Duplicate', number_format((int)($workflowCounts['duplicate'] ?? 0)), 'bi-files'); ?></div>
        </div>
      </div>

      <?php
        $modeText = $mode === 'workflow' ? 'Workflow Progress' : 'Import Audit';
        $modeNote = $mode === 'workflow'
            ? 'Track linked contacts, workflow queue, assigned department, owner, and activity timestamp.'
            : 'Inspect raw uploaded rows, validation result, duplicate reason, and contact linkage.';
      ?>
      <div class="col-12">
        <div class="card hr-card dbms-panel">
          <div class="card-header fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
              <div><?php echo htmlspecialchars($modeText); ?></div>
              <div class="dbms-note"><?php echo htmlspecialchars($modeNote); ?></div>
            </div>
            <span class="dbms-table-count"><?php echo number_format($totalRows); ?> rows</span>
          </div>
          <div class="dbms-table-toolbar">
            <div class="dbms-batch-tab-stack">
              <div class="dbms-batch-nav">
                <a class="dbms-batch-tab <?php echo $mode === 'import' ? 'active' : ''; ?>" href="?id=<?php echo (int)$id; ?>&mode=import&tab=<?php echo urlencode($mode === 'import' ? $tab : 'all'); ?>">
                  <i class="bi bi-journal-check"></i>Import Audit
                </a>
                <a class="dbms-batch-tab <?php echo $mode === 'workflow' ? 'active' : ''; ?>" href="?id=<?php echo (int)$id; ?>&mode=workflow&tab=<?php echo urlencode($mode === 'workflow' ? $tab : 'all'); ?>">
                  <i class="bi bi-diagram-3"></i>Workflow Progress
                </a>
              </div>
              <div class="dbms-batch-nav dbms-batch-nav-secondary">
                <?php if ($mode === 'import'): ?>
                  <a class="dbms-batch-tab <?php echo $tab === 'all' ? 'active' : ''; ?>" href="?id=<?php echo (int)$id; ?>&mode=import&tab=all">All</a>
                  <a class="dbms-batch-tab <?php echo $tab === 'valid' ? 'active' : ''; ?>" href="?id=<?php echo (int)$id; ?>&mode=import&tab=valid">Valid</a>
                  <a class="dbms-batch-tab <?php echo $tab === 'append' ? 'active' : ''; ?>" href="?id=<?php echo (int)$id; ?>&mode=import&tab=append">Append</a>
                  <a class="dbms-batch-tab <?php echo $tab === 'rejected' ? 'active' : ''; ?>" href="?id=<?php echo (int)$id; ?>&mode=import&tab=rejected">Rejected</a>
                  <a class="dbms-batch-tab <?php echo $tab === 'duplicate' ? 'active' : ''; ?>" href="?id=<?php echo (int)$id; ?>&mode=import&tab=duplicate">Duplicate</a>
                <?php else: ?>
                  <a class="dbms-batch-tab <?php echo $tab === 'all' ? 'active' : ''; ?>" href="?id=<?php echo (int)$id; ?>&mode=workflow&tab=all">All</a>
                  <a class="dbms-batch-tab <?php echo $tab === 'pending_research' ? 'active' : ''; ?>" href="?id=<?php echo (int)$id; ?>&mode=workflow&tab=pending_research">Pending Research</a>
                  <a class="dbms-batch-tab <?php echo $tab === 'research_assigned' ? 'active' : ''; ?>" href="?id=<?php echo (int)$id; ?>&mode=workflow&tab=research_assigned">Research Assigned</a>
                  <a class="dbms-batch-tab <?php echo $tab === 'research_rework' ? 'active' : ''; ?>" href="?id=<?php echo (int)$id; ?>&mode=workflow&tab=research_rework">Research Rework</a>
                  <a class="dbms-batch-tab <?php echo $tab === 'pending_cdqa' ? 'active' : ''; ?>" href="?id=<?php echo (int)$id; ?>&mode=workflow&tab=pending_cdqa">Pending CDQA</a>
                  <a class="dbms-batch-tab <?php echo $tab === 'cdqa_assigned' ? 'active' : ''; ?>" href="?id=<?php echo (int)$id; ?>&mode=workflow&tab=cdqa_assigned">CDQA Assigned</a>
                  <a class="dbms-batch-tab <?php echo $tab === 'approved' ? 'active' : ''; ?>" href="?id=<?php echo (int)$id; ?>&mode=workflow&tab=approved">Approved</a>
                  <a class="dbms-batch-tab <?php echo $tab === 'rejected' ? 'active' : ''; ?>" href="?id=<?php echo (int)$id; ?>&mode=workflow&tab=rejected">Rejected</a>
                  <a class="dbms-batch-tab <?php echo $tab === 'duplicate' ? 'active' : ''; ?>" href="?id=<?php echo (int)$id; ?>&mode=workflow&tab=duplicate">Duplicate</a>
                <?php endif; ?>
              </div>
            </div>
            <div class="dbms-table-toolbar-side">
              <span class="dbms-note">
                <?php echo $mode === 'workflow' ? 'Showing workflow-linked contacts for this batch.' : 'Showing imported rows for this batch.'; ?>
              </span>
            </div>
          </div>
        </div>
      </div>

      <div class="col-12">
        <div class="card hr-card dbms-panel">
          <div class="dbms-table-wrap">
            <?php if (empty($rows)): ?>
              <div class="analytics-empty">
                <?php echo $mode === 'workflow'
                    ? 'No workflow records matched this batch yet.'
                    : 'No import rows matched this batch yet.'; ?>
              </div>
            <?php else: ?>
              <?php $showRejectedDeleteTools = $canDeleteInvalidRecords && $tab === 'rejected'; ?>
              <?php if ($showRejectedDeleteTools): ?>
                <form method="post" id="batchInvalidDeleteForm">
                  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)($_SESSION['csrf_token'] ?? '')); ?>">
                  <input type="hidden" name="action" id="batchDeleteAction" value="">
                  <input type="hidden" name="batch_row_id" id="batchDeleteRowId" value="">
                  <input type="hidden" name="contact_id" id="batchDeleteContactId" value="">
                  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 px-3 pt-3">
                    <div class="small text-muted">Delete is enabled for invalid records only in the rejected queue.</div>
                    <div class="d-flex gap-2 flex-wrap">
                      <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleBatchInvalidRows(true)">
                        <i class="bi bi-check2-square me-1"></i>Select All
                      </button>
                      <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleBatchInvalidRows(false)">
                        <i class="bi bi-square me-1"></i>Clear
                      </button>
                      <span class="small text-muted align-self-center" id="batchSelectedCount">0 invalid records selected</span>
                      <button type="button" class="btn btn-outline-danger btn-sm" onclick="submitBatchInvalidDeleteSelected('<?php echo $mode === 'import' ? 'rows' : 'contacts'; ?>')">
                        <i class="bi bi-trash me-1"></i>Delete Selected
                        <span class="ms-1">Invalid Only</span>
                      </button>
                    </div>
                  </div>
              <?php endif; ?>
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead>
                    <?php if ($mode === 'import'): ?>
                      <tr>
                        <?php if ($showRejectedDeleteTools): ?><th style="width:44px"><input type="checkbox" class="form-check-input" id="batchChkAll"></th><?php endif; ?>
                        <th class="text-end">Row</th>
                        <th>Status</th>
                        <th>Reason</th>
                        <th class="text-end">Dup Of</th>
                        <th>Company</th>
                        <th>Job Title</th>
                        <th>Email</th>
                        <th>Revenue Link</th>
                        <th>Country</th>
                        <th class="text-end">Contact</th>
                        <th class="text-end">View</th>
                        <?php if ($canDeleteInvalidRecords && $tab === 'rejected'): ?><th class="text-end">Delete</th><?php endif; ?>
                      </tr>
                    <?php else: ?>
                      <tr>
                        <?php if ($showRejectedDeleteTools): ?><th style="width:44px"><input type="checkbox" class="form-check-input" id="batchChkAll"></th><?php endif; ?>
                        <th class="text-end">Contact</th>
                        <th>Workflow</th>
                        <th>Department</th>
                        <th>Assigned To</th>
                        <th>Company</th>
                        <th>Job Title</th>
                        <th>Email</th>
                        <th>Country</th>
                        <th class="text-end">Last Activity</th>
                        <th class="text-end">View</th>
                        <?php if ($canDeleteInvalidRecords && $tab === 'rejected'): ?><th class="text-end">Delete</th><?php endif; ?>
                      </tr>
                    <?php endif; ?>
                  </thead>
                  <tbody>
                    <?php foreach ($rows as $r): ?>
                      <?php if ($mode === 'import'): ?>
                        <tr>
                          <?php if ($showRejectedDeleteTools): ?>
                            <td><input type="checkbox" class="form-check-input batch-chk-row" name="batch_row_ids[]" value="<?php echo (int)($r['id'] ?? 0); ?>"></td>
                          <?php endif; ?>
                          <td class="text-end font-monospace"><?php echo (int)($r['row_num'] ?? 0); ?></td>
                          <td><span class="dbms-chip <?php echo htmlspecialchars($batchTone((string)($r['status'] ?? ''))); ?>"><?php echo htmlspecialchars(ucfirst((string)($r['status'] ?? ''))); ?></span></td>
                          <td class="text-muted small"><?php echo htmlspecialchars((string)($r['reason'] ?? '')); ?></td>
                          <td class="text-end"><?php echo (int)($r['duplicate_of_contact_id'] ?? 0) > 0 ? '<span class="dbms-chip tone-slate">#' . (int)($r['duplicate_of_contact_id'] ?? 0) . '</span>' : '—'; ?></td>
                          <td>
                            <div class="dbms-meta-title"><?php echo htmlspecialchars((string)($r['company_name'] ?? '')); ?></div>
                            <div class="dbms-meta-subtitle"><?php echo htmlspecialchars((string)($r['job_title'] ?? 'No job title')); ?></div>
                          </td>
                          <td class="text-muted small"><?php echo htmlspecialchars((string)($r['job_title'] ?? '')); ?></td>
                          <td class="font-monospace"><?php echo htmlspecialchars((string)($r['email'] ?? '')); ?></td>
                          <td class="text-muted small">
                            <?php $rl = trim((string)($r['revenue_link'] ?? '')); ?>
                            <?php if ($rl !== ''): ?>
                              <a href="<?php echo htmlspecialchars($rl); ?>" target="_blank" rel="noopener" class="dbms-chip tone-company text-decoration-none"><i class="bi bi-box-arrow-up-right"></i>Open</a>
                            <?php else: ?>
                              —
                            <?php endif; ?>
                          </td>
                          <td class="text-muted small"><?php echo htmlspecialchars((string)($r['country'] ?? '')); ?></td>
                          <td class="text-end font-monospace"><?php echo (int)($r['contact_id'] ?? 0); ?></td>
                          <td class="text-end">
                            <?php if ((int)($r['contact_id'] ?? 0) > 0): ?>
                              <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/view?id=<?php echo (int)($r['contact_id'] ?? 0); ?>"><i class="bi bi-eye"></i></a>
                            <?php else: ?>
                              —
                            <?php endif; ?>
                          </td>
                          <?php if ($canDeleteInvalidRecords && $tab === 'rejected'): ?>
                            <td class="text-end">
                              <button type="button" class="btn btn-outline-danger btn-sm" title="Delete invalid row only" onclick="submitBatchInvalidDeleteSingle('row', <?php echo (int)($r['id'] ?? 0); ?>)">
                                <i class="bi bi-trash"></i><span class="ms-1">Invalid Only</span>
                              </button>
                            </td>
                          <?php endif; ?>
                        </tr>
                      <?php else: ?>
                        <?php $cid = (int)($r['id'] ?? 0); ?>
                        <tr>
                          <?php if ($showRejectedDeleteTools): ?>
                            <td><input type="checkbox" class="form-check-input batch-chk-row" name="contact_ids[]" value="<?php echo $cid; ?>"></td>
                          <?php endif; ?>
                          <td class="text-end font-monospace"><?php echo $cid; ?></td>
                          <td><span class="<?php echo htmlspecialchars($workflowBadgeClass((string)($r['workflow_queue'] ?? ''))); ?>"><?php echo htmlspecialchars(dbmsWorkflowLabel((string)($r['workflow_queue'] ?? ''))); ?></span></td>
                          <td><span class="dbms-chip <?php echo htmlspecialchars($batchTone((string)($r['assigned_department'] ?? ''))); ?>"><?php echo htmlspecialchars((string)($r['assigned_department'] ?? 'Unassigned')); ?></span></td>
                          <td>
                            <div class="dbms-meta-title"><?php echo htmlspecialchars((string)($r['assigned_to_name'] ?? 'Not assigned')); ?></div>
                            <div class="dbms-meta-subtitle"><?php echo htmlspecialchars((string)($r['assigned_department'] ?? 'Queue owner pending')); ?></div>
                          </td>
                          <td>
                            <div class="dbms-meta-title"><?php echo htmlspecialchars((string)($r['company_name'] ?? '')); ?></div>
                            <div class="dbms-meta-subtitle"><?php echo htmlspecialchars((string)($r['job_title'] ?? 'No job title')); ?></div>
                          </td>
                          <td class="text-muted small"><?php echo htmlspecialchars((string)($r['job_title'] ?? '')); ?></td>
                          <td class="font-monospace"><?php echo htmlspecialchars((string)($r['email'] ?? '')); ?></td>
                          <td class="text-muted small"><?php echo htmlspecialchars((string)($r['prospect_country'] ?? ($r['country'] ?? ''))); ?></td>
                          <td class="text-end font-monospace text-muted small"><?php echo htmlspecialchars($formatDateTime((string)($r['last_activity_at'] ?? ''), 'No activity')); ?></td>
                          <td class="text-end">
                            <?php if ($cid > 0): ?>
                              <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/view?id=<?php echo $cid; ?>"><i class="bi bi-eye"></i></a>
                            <?php else: ?>
                              —
                            <?php endif; ?>
                          </td>
                          <?php if ($canDeleteInvalidRecords && $tab === 'rejected'): ?>
                            <td class="text-end">
                              <button type="button" class="btn btn-outline-danger btn-sm" title="Delete invalid record only" onclick="submitBatchInvalidDeleteSingle('contact', <?php echo $cid; ?>)">
                                <i class="bi bi-trash"></i><span class="ms-1">Invalid Only</span>
                              </button>
                            </td>
                          <?php endif; ?>
                        </tr>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <?php if ($showRejectedDeleteTools): ?>
                </form>
              <?php endif; ?>

              <?php
                $pages = (int)ceil($totalRows / $pageSize);
                $baseUrl = '?id=' . (int)$id . '&mode=' . urlencode($mode) . '&tab=' . urlencode($tab);
              ?>
              <?php if ($pages > 1): ?>
                <div class="dbms-footer-bar">
                  <div class="dbms-note">Page <?php echo number_format($page); ?> / <?php echo number_format($pages); ?> · <?php echo number_format($totalRows); ?> records</div>
                  <div class="btn-group btn-group-sm">
                    <a class="btn btn-outline-secondary <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo $baseUrl; ?>&page=<?php echo max(1, $page - 1); ?>">Prev</a>
                    <a class="btn btn-outline-secondary <?php echo $page >= $pages ? 'disabled' : ''; ?>" href="<?php echo $baseUrl; ?>&page=<?php echo min($pages, $page + 1); ?>">Next</a>
                  </div>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
  (function(){
    const batchChkAll = document.getElementById('batchChkAll');
    const batchCountLabel = document.getElementById('batchSelectedCount');
    const updateBatchSelectedCount = function(){
      if (!batchCountLabel) return;
      const count = document.querySelectorAll('.batch-chk-row:checked').length;
      batchCountLabel.textContent = count + ' invalid record' + (count === 1 ? '' : 's') + ' selected';
    };
    if (batchChkAll) {
      batchChkAll.addEventListener('change', function(){
        document.querySelectorAll('.batch-chk-row').forEach((checkbox) => {
          checkbox.checked = batchChkAll.checked;
        });
        updateBatchSelectedCount();
      });
    }
    document.querySelectorAll('.batch-chk-row').forEach((checkbox) => {
      checkbox.addEventListener('change', function(){
        if (batchChkAll) {
          const total = document.querySelectorAll('.batch-chk-row').length;
          const checked = document.querySelectorAll('.batch-chk-row:checked').length;
          batchChkAll.checked = total > 0 && total === checked;
        }
        updateBatchSelectedCount();
      });
    });
    window.toggleBatchInvalidRows = function(checked){
      const master = document.getElementById('batchChkAll');
      if (master) {
        master.checked = !!checked;
      }
      document.querySelectorAll('.batch-chk-row').forEach((checkbox) => {
        checkbox.checked = !!checked;
      });
      updateBatchSelectedCount();
    };
    window.submitBatchInvalidDeleteSelected = function(scope){
      const form = document.getElementById('batchInvalidDeleteForm');
      const actionField = document.getElementById('batchDeleteAction');
      if (!form || !actionField) return;
      const checkedRows = Array.from(document.querySelectorAll('.batch-chk-row:checked'));
      if (!checkedRows.length) {
        alert('Select invalid rows first.');
        return;
      }
      actionField.value = scope === 'rows' ? 'delete_invalid_batch_rows' : 'delete_invalid_contacts';
      const rowField = document.getElementById('batchDeleteRowId');
      const contactField = document.getElementById('batchDeleteContactId');
      if (rowField) rowField.value = '';
      if (contactField) contactField.value = '';
      if (!confirm('Delete the selected invalid DBMS records permanently?')) {
        return;
      }
      form.submit();
    };
    window.submitBatchInvalidDeleteSingle = function(scope, id){
      const form = document.getElementById('batchInvalidDeleteForm');
      const actionField = document.getElementById('batchDeleteAction');
      if (!form || !actionField || !id) return;
      const rowField = document.getElementById('batchDeleteRowId');
      const contactField = document.getElementById('batchDeleteContactId');
      if (rowField) rowField.value = '';
      if (contactField) contactField.value = '';
      actionField.value = scope === 'row' ? 'delete_invalid_batch_row' : 'delete_invalid_contact';
      if (scope === 'row' && rowField) rowField.value = String(id);
      if (scope !== 'row' && contactField) contactField.value = String(id);
      if (!confirm('Delete this invalid DBMS record permanently?')) {
        return;
      }
      form.submit();
    };
    updateBatchSelectedCount();
  })();
</script>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
