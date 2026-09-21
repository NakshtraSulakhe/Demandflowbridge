<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['admin']);
ensureCsrfToken();

$conn = getDbConnection();

$version = null;
$rs = $conn->query("SELECT VERSION() AS v");
if ($rs) { $version = (string)(($rs->fetch_assoc()['v'] ?? '') ?: ''); }

$sqlMode = null;
$rs = $conn->query("SELECT @@sql_mode AS m");
if ($rs) { $sqlMode = (string)(($rs->fetch_assoc()['m'] ?? '') ?: ''); }

$checks = [];
$checks['can_select_leads'] = (bool)$conn->query("SELECT 1 FROM leads LIMIT 1");
$checks['leads_error'] = $checks['can_select_leads'] ? '' : (string)($conn->error ?? '');

$columns = [];
$rs = $conn->query("SHOW COLUMNS FROM leads");
if ($rs) {
    while ($r = $rs->fetch_assoc()) $columns[] = $r;
}

$agentInsertSql = "
    INSERT INTO leads (
        lead_id, campaign_id, campaign_name, client_id, agent_id, agent_name,
        first_name, last_name, job_title, email, company_domain,
        contact_phone, industry, company_name, company_size, country,
        lead_comment, recording_path, ip_address,
        created_by, updated_by, created_at_utc, updated_at_utc, created_at_source, updated_at_source, timezone_migrated, vendor_id
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
";
$agentInsertPrepareOk = false;
$agentInsertPrepareErr = '';
$stmt = $conn->prepare($agentInsertSql);
if ($stmt) {
    $agentInsertPrepareOk = true;
    $stmt->close();
} else {
    $agentInsertPrepareErr = (string)($conn->error ?? 'unknown');
}

$campaignTables = [];
$rs = $conn->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'leads\\_%' ORDER BY TABLE_NAME");
if ($rs) {
    while ($r = $rs->fetch_assoc()) {
        $tn = (string)($r['TABLE_NAME'] ?? '');
        if ($tn !== '') $campaignTables[] = $tn;
    }
}

$tableExists = function(string $table) use ($conn): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: ['c' => 0];
    $stmt->close();
    return ((int)($row['c'] ?? 0)) > 0;
};

$scalar = function(string $sql) use ($conn) {
    $rs = $conn->query($sql);
    if (!$rs) return null;
    $row = $rs->fetch_row();
    return $row ? ($row[0] ?? null) : null;
};

$integrity = [];
$integrity['orphan_lead_activity'] = $tableExists('lead_activity') ? (int)($scalar("SELECT COUNT(*) FROM lead_activity la LEFT JOIN leads l ON l.id = la.lead_id WHERE l.id IS NULL") ?? 0) : null;
$integrity['orphan_lead_files'] = $tableExists('lead_files') ? (int)($scalar("SELECT COUNT(*) FROM lead_files lf LEFT JOIN leads l ON l.id = lf.lead_id WHERE l.id IS NULL") ?? 0) : null;
$integrity['orphan_qa_logs'] = $tableExists('qa_audit_logs') ? (int)($scalar("SELECT COUNT(*) FROM qa_audit_logs q LEFT JOIN leads l ON l.id = q.lead_id WHERE l.id IS NULL") ?? 0) : null;
$integrity['broken_ops_assignments'] = $tableExists('operations_campaign_assignments') ? (int)($scalar("SELECT COUNT(*) FROM operations_campaign_assignments a LEFT JOIN campaigns c ON c.id = a.campaign_id WHERE c.id IS NULL") ?? 0) : null;
$integrity['broken_qa_assignments'] = $tableExists('qa_campaign_assignments') ? (int)($scalar("SELECT COUNT(*) FROM qa_campaign_assignments a LEFT JOIN campaigns c ON c.id = a.campaign_id WHERE c.id IS NULL") ?? 0) : null;
$integrity['broken_campaign_user_assignments'] = $tableExists('campaign_user_assignments') ? (int)($scalar("SELECT COUNT(*) FROM campaign_user_assignments a LEFT JOIN campaigns c ON c.id = a.campaign_id WHERE c.id IS NULL") ?? 0) : null;
$integrity['orphan_task_assignees'] = $tableExists('task_assignees') ? (int)($scalar("SELECT COUNT(*) FROM task_assignees ta LEFT JOIN tasks t ON t.id = ta.task_id WHERE t.id IS NULL") ?? 0) : null;
$integrity['orphan_task_files'] = $tableExists('task_files') ? (int)($scalar("SELECT COUNT(*) FROM task_files tf LEFT JOIN tasks t ON t.id = tf.task_id WHERE t.id IS NULL") ?? 0) : null;
$integrity['orphan_task_comments'] = $tableExists('task_comments') ? (int)($scalar("SELECT COUNT(*) FROM task_comments tc LEFT JOIN tasks t ON t.id = tc.task_id WHERE t.id IS NULL") ?? 0) : null;

$dupes = [];
$dupes['campaign_codes'] = [];
if ($tableExists('campaign_details')) {
    $rs = $conn->query("SELECT code, COUNT(*) AS cnt FROM campaign_details WHERE code IS NOT NULL AND TRIM(code) <> '' GROUP BY code HAVING cnt > 1 ORDER BY cnt DESC LIMIT 50");
    if ($rs) $dupes['campaign_codes'] = $rs->fetch_all(MYSQLI_ASSOC) ?: [];
}
$dupes['users_username'] = [];
if ($tableExists('users')) {
    $rs = $conn->query("SELECT username, COUNT(*) AS cnt FROM users WHERE username IS NOT NULL AND TRIM(username) <> '' GROUP BY username HAVING cnt > 1 ORDER BY cnt DESC LIMIT 50");
    if ($rs) $dupes['users_username'] = $rs->fetch_all(MYSQLI_ASSOC) ?: [];
}
$dupes['users_email'] = [];
if ($tableExists('users')) {
    $rs = $conn->query("SELECT email, COUNT(*) AS cnt FROM users WHERE email IS NOT NULL AND TRIM(email) <> '' GROUP BY email HAVING cnt > 1 ORDER BY cnt DESC LIMIT 50");
    if ($rs) $dupes['users_email'] = $rs->fetch_all(MYSQLI_ASSOC) ?: [];
}
$dupes['users_employee_id'] = [];
if ($tableExists('users')) {
    $rs = $conn->query("SELECT employee_id, COUNT(*) AS cnt FROM users WHERE employee_id IS NOT NULL AND TRIM(employee_id) <> '' GROUP BY employee_id HAVING cnt > 1 ORDER BY cnt DESC LIMIT 50");
    if ($rs) $dupes['users_employee_id'] = $rs->fetch_all(MYSQLI_ASSOC) ?: [];
}
$dupes['leads_email_campaign'] = [];
if ($tableExists('leads')) {
    $rs = $conn->query("SELECT campaign_id, email, COUNT(*) AS cnt FROM leads WHERE email IS NOT NULL AND TRIM(email) <> '' GROUP BY campaign_id, email HAVING cnt > 1 ORDER BY cnt DESC LIMIT 50");
    if ($rs) $dupes['leads_email_campaign'] = $rs->fetch_all(MYSQLI_ASSOC) ?: [];
}

?>
<?php $pageTitle = 'DB Diagnostics'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <div class="h3 mb-1">DB Diagnostics</div>
            <div class="text-muted small">Admin-only SQL prepare troubleshooting</div>
        </div>
        <a class="btn btn-light border" href="javascript:history.back()"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-light fw-semibold">Server</div>
                <div class="card-body">
                    <div class="d-flex justify-content-between"><span class="text-muted">DB Version</span><span class="fw-semibold"><?php echo htmlspecialchars($version ?: '—'); ?></span></div>
                    <div class="mt-2">
                        <div class="text-muted small">SQL Mode</div>
                        <div class="small"><?php echo htmlspecialchars($sqlMode ?: '—'); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-light fw-semibold">Prepare Checks</div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted">Agent lead insert prepare</span>
                        <?php if ($agentInsertPrepareOk): ?>
                            <span class="badge bg-success-subtle text-success border">OK</span>
                        <?php else: ?>
                            <span class="badge bg-danger-subtle text-danger border">FAILED</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!$agentInsertPrepareOk): ?>
                        <div class="alert alert-danger border-0 mb-0">
                            <div class="fw-semibold mb-1">MySQL Error</div>
                            <div class="small"><?php echo htmlspecialchars($agentInsertPrepareErr); ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if (!$checks['can_select_leads']): ?>
                        <div class="alert alert-warning border-0 mt-2 mb-0">
                            <div class="fw-semibold mb-1">Leads table select failed</div>
                            <div class="small"><?php echo htmlspecialchars($checks['leads_error']); ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-light fw-semibold">Data Integrity Checks</div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">Check</th>
                                    <th>Broken Rows</th>
                                    <th class="pe-3">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                    $rows = [
                                        ['Orphan Lead Activity (lead_activity.lead_id missing)', $integrity['orphan_lead_activity']],
                                        ['Orphan Lead Files (lead_files.lead_id missing)', $integrity['orphan_lead_files']],
                                        ['Orphan QA Logs (qa_audit_logs.lead_id missing)', $integrity['orphan_qa_logs']],
                                        ['Broken Ops Assignments (operations_campaign_assignments.campaign_id missing)', $integrity['broken_ops_assignments']],
                                        ['Broken QA Assignments (qa_campaign_assignments.campaign_id missing)', $integrity['broken_qa_assignments']],
                                        ['Broken Campaign Assignments (campaign_user_assignments.campaign_id missing)', $integrity['broken_campaign_user_assignments']],
                                        ['Orphan Task Assignees (task_assignees.task_id missing)', $integrity['orphan_task_assignees']],
                                        ['Orphan Task Files (task_files.task_id missing)', $integrity['orphan_task_files']],
                                        ['Orphan Task Comments (task_comments.task_id missing)', $integrity['orphan_task_comments']],
                                    ];
                                ?>
                                <?php foreach ($rows as $r): ?>
                                    <?php
                                        $cnt = $r[1];
                                        $cntText = ($cnt === null) ? 'N/A' : number_format((int)$cnt);
                                        $ok = ($cnt === null) ? null : ((int)$cnt === 0);
                                    ?>
                                    <tr>
                                        <td class="ps-3"><?php echo htmlspecialchars($r[0]); ?></td>
                                        <td class="fw-semibold"><?php echo htmlspecialchars($cntText); ?></td>
                                        <td class="pe-3">
                                            <?php if ($ok === null): ?>
                                                <span class="badge bg-light text-dark border">Skipped</span>
                                            <?php elseif ($ok): ?>
                                                <span class="badge bg-success-subtle text-success border">OK</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger-subtle text-danger border">Fix Needed</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-light fw-semibold">Duplicate Checks</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-lg-6">
                            <div class="fw-semibold mb-2">Campaign Codes</div>
                            <?php if (empty($dupes['campaign_codes'])): ?>
                                <div class="text-muted small">No duplicates found.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <thead class="table-light"><tr><th class="ps-3">Code</th><th class="pe-3">Count</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($dupes['campaign_codes'] as $d): ?>
                                                <tr><td class="ps-3 fw-semibold"><?php echo htmlspecialchars((string)($d['code'] ?? '')); ?></td><td class="pe-3"><?php echo number_format((int)($d['cnt'] ?? 0)); ?></td></tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-lg-6">
                            <div class="fw-semibold mb-2">Users (Username)</div>
                            <?php if (empty($dupes['users_username'])): ?>
                                <div class="text-muted small">No duplicates found.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <thead class="table-light"><tr><th class="ps-3">Username</th><th class="pe-3">Count</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($dupes['users_username'] as $d): ?>
                                                <tr><td class="ps-3 fw-semibold"><?php echo htmlspecialchars((string)($d['username'] ?? '')); ?></td><td class="pe-3"><?php echo number_format((int)($d['cnt'] ?? 0)); ?></td></tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-lg-6">
                            <div class="fw-semibold mb-2">Users (Email)</div>
                            <?php if (empty($dupes['users_email'])): ?>
                                <div class="text-muted small">No duplicates found.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <thead class="table-light"><tr><th class="ps-3">Email</th><th class="pe-3">Count</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($dupes['users_email'] as $d): ?>
                                                <tr><td class="ps-3 fw-semibold"><?php echo htmlspecialchars((string)($d['email'] ?? '')); ?></td><td class="pe-3"><?php echo number_format((int)($d['cnt'] ?? 0)); ?></td></tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-lg-6">
                            <div class="fw-semibold mb-2">Users (Employee ID)</div>
                            <?php if (empty($dupes['users_employee_id'])): ?>
                                <div class="text-muted small">No duplicates found.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <thead class="table-light"><tr><th class="ps-3">Employee ID</th><th class="pe-3">Count</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($dupes['users_employee_id'] as $d): ?>
                                                <tr><td class="ps-3 fw-semibold"><?php echo htmlspecialchars((string)($d['employee_id'] ?? '')); ?></td><td class="pe-3"><?php echo number_format((int)($d['cnt'] ?? 0)); ?></td></tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <div class="fw-semibold mb-2">Leads Duplicates (campaign_id + email)</div>
                            <?php if (empty($dupes['leads_email_campaign'])): ?>
                                <div class="text-muted small">No duplicates found.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <thead class="table-light"><tr><th class="ps-3">Campaign</th><th>Email</th><th class="pe-3">Count</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($dupes['leads_email_campaign'] as $d): ?>
                                                <tr>
                                                    <td class="ps-3 fw-semibold"><?php echo (int)($d['campaign_id'] ?? 0); ?></td>
                                                    <td class="text-muted"><?php echo htmlspecialchars((string)($d['email'] ?? '')); ?></td>
                                                    <td class="pe-3"><?php echo number_format((int)($d['cnt'] ?? 0)); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-light fw-semibold">Leads Columns</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">Field</th>
                                    <th>Type</th>
                                    <th>Null</th>
                                    <th>Key</th>
                                    <th>Default</th>
                                    <th class="pe-3">Extra</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($columns as $c): ?>
                                    <tr>
                                        <td class="ps-3 fw-semibold"><?php echo htmlspecialchars((string)($c['Field'] ?? '')); ?></td>
                                        <td class="text-muted small"><?php echo htmlspecialchars((string)($c['Type'] ?? '')); ?></td>
                                        <td class="text-muted small"><?php echo htmlspecialchars((string)($c['Null'] ?? '')); ?></td>
                                        <td class="text-muted small"><?php echo htmlspecialchars((string)($c['Key'] ?? '')); ?></td>
                                        <td class="text-muted small"><?php echo htmlspecialchars((string)($c['Default'] ?? '')); ?></td>
                                        <td class="text-muted small pe-3"><?php echo htmlspecialchars((string)($c['Extra'] ?? '')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($columns)): ?>
                                    <tr><td colspan="6" class="text-center text-muted py-4">Unable to read leads columns.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-light fw-semibold">Campaign Lead Tables</div>
                <div class="card-body">
                    <?php if (empty($campaignTables)): ?>
                        <div class="text-muted">No leads_* tables found.</div>
                    <?php else: ?>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($campaignTables as $t): ?>
                                <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($t); ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
