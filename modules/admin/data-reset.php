<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['admin','director','manager_director','operations_director','operations_manager']);
ensureCsrfToken();

$conn = getDbConnection();
$message = '';
$messageType = 'success';

$count = function(string $sql) use ($conn): int {
    $rs = $conn->query($sql);
    if (!$rs) return 0;
    $row = $rs->fetch_row();
    return (int)($row[0] ?? 0);
};

$counts = [
    'campaigns' => $count("SELECT COUNT(*) FROM campaigns"),
    'forms' => $count("SELECT COUNT(*) FROM forms"),
    'form_templates' => $count("SELECT COUNT(*) FROM form_templates"),
    'leads' => $count("SELECT COUNT(*) FROM leads"),
    'form_submissions' => $count("SELECT COUNT(*) FROM form_submissions"),
    'lead_activity' => $count("SELECT COUNT(*) FROM lead_activity"),
    'lead_files' => $count("SELECT COUNT(*) FROM lead_files"),
    'qa_audit_logs' => $count("SELECT COUNT(*) FROM qa_audit_logs"),
];

$campaignLeadTables = [];
$rs = $conn->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'leads\\_%' ORDER BY TABLE_NAME");
if ($rs) {
    while ($r = $rs->fetch_assoc()) {
        $tn = (string)($r['TABLE_NAME'] ?? '');
        if ($tn !== '' && $tn !== 'leads') $campaignLeadTables[] = $tn;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $message = 'Invalid token.';
        $messageType = 'danger';
    } else {
        $confirm = strtoupper(trim((string)($_POST['confirm_text'] ?? '')));
        if ($confirm !== 'DELETE') {
            $message = 'Type DELETE to confirm.';
            $messageType = 'danger';
        } else {
            $doLeads = !empty($_POST['reset_leads']);
            $doCampaigns = !empty($_POST['reset_campaigns']);
            $doForms = !empty($_POST['reset_forms']);
            $dropCampaignTables = !empty($_POST['drop_campaign_lead_tables']);

            if (!$doLeads && !$doCampaigns && !$doForms && !$dropCampaignTables) {
                $message = 'No option selected.';
                $messageType = 'danger';
            } else {
                $conn->begin_transaction();
                try {
                    if ($doLeads) {
                        $conn->query("DELETE FROM qa_audit_logs");
                        $conn->query("DELETE FROM lead_activity");
                        $conn->query("DELETE FROM lead_files");
                        $conn->query("DELETE FROM form_submissions");
                        $conn->query("DELETE FROM leads");
                    } elseif ($doForms) {
                        $conn->query("DELETE FROM form_submissions");
                    }

                    if ($doCampaigns) {
                        $conn->query("DELETE FROM operations_campaign_assignments");
                        $conn->query("DELETE FROM team_campaigns");
                        $conn->query("DELETE FROM qa_campaign_assignments");
                        $conn->query("DELETE FROM campaign_user_assignments");
                        $conn->query("DELETE FROM vendor_campaign_map");
                        $conn->query("DELETE FROM campaign_notes");
                        $conn->query("DELETE FROM campaign_additional_files");
                        $conn->query("DELETE FROM campaign_delivery_files");
                        $conn->query("DELETE FROM campaign_forms");
                        $conn->query("DELETE FROM campaign_details");
                        $conn->query("DELETE FROM campaigns");
                    }

                    if ($doForms) {
                        $conn->query("DELETE FROM campaign_forms");
                        $conn->query("DELETE FROM forms");
                        $conn->query("DELETE FROM form_templates");
                    }

                    if ($dropCampaignTables) {
                        $rs2 = $conn->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'leads\\_%'");
                        if ($rs2) {
                            while ($r = $rs2->fetch_assoc()) {
                                $tn = (string)($r['TABLE_NAME'] ?? '');
                                if ($tn === '' || $tn === 'leads') continue;
                                if (!preg_match('/^[A-Za-z0-9_]+$/', $tn)) continue;
                                $conn->query("DROP TABLE IF EXISTS `$tn`");
                            }
                        }
                    }

                    $conn->commit();
                    $message = 'Reset completed.';
                    $messageType = 'success';
                } catch (Throwable $e) {
                    $conn->rollback();
                    $message = 'Reset failed.';
                    $messageType = 'danger';
                }
            }
        }
    }
}

?>
<?php $pageTitle = 'Data Reset'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <div class="h3 mb-1">Data Reset</div>
            <div class="text-muted small">Deletes campaigns/forms/leads data for clean testing.</div>
        </div>
        <a class="btn btn-light border" href="javascript:history.back()"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> border-0 shadow-sm"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-light fw-semibold">Current Counts</div>
                <div class="card-body">
                    <div class="d-flex justify-content-between"><span class="text-muted">Campaigns</span><span class="fw-semibold"><?php echo number_format((int)$counts['campaigns']); ?></span></div>
                    <div class="d-flex justify-content-between mt-1"><span class="text-muted">Forms</span><span class="fw-semibold"><?php echo number_format((int)$counts['forms']); ?></span></div>
                    <div class="d-flex justify-content-between mt-1"><span class="text-muted">Form Templates</span><span class="fw-semibold"><?php echo number_format((int)$counts['form_templates']); ?></span></div>
                    <div class="d-flex justify-content-between mt-1"><span class="text-muted">Leads</span><span class="fw-semibold"><?php echo number_format((int)$counts['leads']); ?></span></div>
                    <div class="d-flex justify-content-between mt-1"><span class="text-muted">Form Submissions</span><span class="fw-semibold"><?php echo number_format((int)$counts['form_submissions']); ?></span></div>
                    <div class="d-flex justify-content-between mt-1"><span class="text-muted">Lead Activity</span><span class="fw-semibold"><?php echo number_format((int)$counts['lead_activity']); ?></span></div>
                    <div class="d-flex justify-content-between mt-1"><span class="text-muted">Lead Files</span><span class="fw-semibold"><?php echo number_format((int)$counts['lead_files']); ?></span></div>
                    <div class="d-flex justify-content-between mt-1"><span class="text-muted">QA Audit Logs</span><span class="fw-semibold"><?php echo number_format((int)$counts['qa_audit_logs']); ?></span></div>
                    <div class="mt-2">
                        <div class="text-muted small">Campaign Lead Tables</div>
                        <div class="fw-semibold"><?php echo number_format(count($campaignLeadTables)); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-light fw-semibold">Reset Options</div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="resetCampaigns" name="reset_campaigns" value="1">
                            <label class="form-check-label" for="resetCampaigns">Delete all campaigns + assignments + files/notes</label>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="resetForms" name="reset_forms" value="1">
                            <label class="form-check-label" for="resetForms">Delete all forms + templates + campaign form mapping</label>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="resetLeads" name="reset_leads" value="1">
                            <label class="form-check-label" for="resetLeads">Delete all leads + submissions + activity + QA logs</label>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="dropCampaignLeadTables" name="drop_campaign_lead_tables" value="1" checked>
                            <label class="form-check-label" for="dropCampaignLeadTables">Drop all campaign lead tables (leads_*)</label>
                        </div>

                        <div class="mb-2">
                            <label class="form-label small text-muted">Type DELETE to confirm</label>
                            <input class="form-control" name="confirm_text" placeholder="DELETE" required>
                        </div>

                        <div class="d-flex justify-content-end">
                            <button class="btn btn-danger" type="submit"><i class="bi bi-trash3 me-1"></i>Reset Data</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
