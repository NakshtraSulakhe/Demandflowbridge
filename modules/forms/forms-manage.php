<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['admin','director','manager_director','sales_director','sales_manager','operations_director','operations_manager']);
ensureCsrfToken();

$user = getCurrentUser();
$message = '';
$messageType = 'success';
$conn = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $message = 'Invalid request token.';
        $messageType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'create_form') {
                $name = trim($_POST['name'] ?? '');
                $schemaJson = $_POST['schema_json'] ?? '';
                $schema = json_decode($schemaJson, true);
                if ($name === '' || !is_array($schema)) {
                    throw new RuntimeException('Please provide a form name and valid schema.');
                }
                if (!isset($schema['fields']) || !is_array($schema['fields'])) {
                    $schema['fields'] = [];
                }
                $normKey = function(string $raw): string {
                    $s = strtolower(trim($raw));
                    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
                    $s = preg_replace('/_+/', '_', $s);
                    return trim($s, '_');
                };
                $seenKeys = [];
                foreach ($schema['fields'] as $f) {
                    if (!is_array($f) || empty($f['key']) || empty($f['label'])) {
                        throw new RuntimeException('Each field must have key and label.');
                    }
                    $k = $normKey((string)$f['key']);
                    if ($k === '') throw new RuntimeException('Each field must have key and label.');
                    if (isset($seenKeys[$k])) {
                        throw new RuntimeException('Duplicate field key detected: ' . $k);
                    }
                    $seenKeys[$k] = true;
                }
                foreach ($schema['fields'] as $f) {
                    if (!is_array($f)) continue;
                    if (empty($f['duplicate_check'])) continue;
                    $k = $normKey((string)($f['key'] ?? ''));
                    $label = trim((string)($f['label'] ?? $k));
                    $limit = (int)($f['accepted_duplicates'] ?? 1);
                    if ($limit < 1) {
                        throw new RuntimeException('Accepted duplicates must be at least 1 for field: ' . ($label !== '' ? $label : $k));
                    }
                }
                createOrReuseForm($name, $schema, (int)($user['id'] ?? 0));
                $message = 'Form saved successfully.';
                $messageType = 'success';
            } elseif ($action === 'assign_form') {
                $campaignId = (int)($_POST['campaign_id'] ?? 0);
                $formId = (int)($_POST['form_id'] ?? 0);
                if ($campaignId <= 0 || $formId <= 0) {
                    throw new RuntimeException('Please select campaign and form.');
                }
                assignFormToCampaign($campaignId, $formId, (int)($user['id'] ?? 0));
                $message = 'Form assigned to campaign successfully.';
                $messageType = 'success';
            } elseif ($action === 'copy_form') {
                $sourceFormId = (int)($_POST['source_form_id'] ?? 0);
                $targetCampaignId = (int)($_POST['target_campaign_id'] ?? 0);
                $newName = trim((string)($_POST['new_form_name'] ?? ''));
                if ($sourceFormId <= 0 || $targetCampaignId <= 0) {
                    throw new RuntimeException('Please select source form and target campaign.');
                }
                $stmt = $conn->prepare("SELECT name, schema_json FROM forms WHERE id = ? LIMIT 1");
                $stmt->bind_param('i', $sourceFormId);
                $stmt->execute();
                $src = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$src) throw new RuntimeException('Source form not found.');
                $schema = json_decode($src['schema_json'] ?? '', true);
                if (!is_array($schema)) throw new RuntimeException('Invalid source form schema.');
                $schema['meta'] = array_merge((array)($schema['meta'] ?? []), [
                    'copied_from' => $sourceFormId,
                    'copy_nonce' => bin2hex(random_bytes(8)),
                ]);
                $nameToUse = $newName !== '' ? $newName : (($src['name'] ?? 'Form').' Copy');
                $newFormId = createOrReuseForm($nameToUse, $schema, (int)($user['id'] ?? 0));
                assignFormToCampaign($targetCampaignId, $newFormId, (int)($user['id'] ?? 0));
                $message = 'Form copied and assigned successfully.';
                $messageType = 'success';
            } elseif ($action === 'save_template') {
                $templateName = trim((string)($_POST['template_name'] ?? ''));
                $formId = (int)($_POST['template_form_id'] ?? 0);
                if ($templateName === '' || $formId <= 0) throw new RuntimeException('Please provide template name and source form.');
                $stmt = $conn->prepare("SELECT schema_json FROM forms WHERE id = ? LIMIT 1");
                $stmt->bind_param('i', $formId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$row) throw new RuntimeException('Form not found.');
                $stmt = $conn->prepare("INSERT INTO form_templates (template_name, schema_json, created_by, created_at) VALUES (?,?,?,NOW())");
                $schemaJson = (string)($row['schema_json'] ?? '');
                $userId = (int)($user['id'] ?? 0);
                $stmt->bind_param('ssi', $templateName, $schemaJson, $userId);
                $stmt->execute();
                $stmt->close();
                $message = 'Template saved successfully.';
                $messageType = 'success';
            } elseif ($action === 'create_from_template') {
                $templateId = (int)($_POST['template_id'] ?? 0);
                $name = trim((string)($_POST['name'] ?? ''));
                if ($templateId <= 0 || $name === '') throw new RuntimeException('Select template and provide form name.');
                $stmt = $conn->prepare("SELECT schema_json FROM form_templates WHERE id = ? LIMIT 1");
                $stmt->bind_param('i', $templateId);
                $stmt->execute();
                $tpl = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$tpl) throw new RuntimeException('Template not found.');
                $schema = json_decode($tpl['schema_json'] ?? '', true);
                if (!is_array($schema)) throw new RuntimeException('Invalid template schema.');
                $schema['meta'] = array_merge((array)($schema['meta'] ?? []), [
                    'template_id' => $templateId,
                    'inst_nonce' => bin2hex(random_bytes(8)),
                ]);
                createOrReuseForm($name, $schema, (int)($user['id'] ?? 0));
                $message = 'Form created from template.';
                $messageType = 'success';
            } elseif ($action === 'delete_form') {
                $formId = (int)($_POST['form_id'] ?? 0);
                if ($formId <= 0) throw new RuntimeException('Invalid form.');
                $stmt = $conn->prepare("SELECT COUNT(*) FROM campaign_forms WHERE form_id = ?");
                $stmt->bind_param('i', $formId);
                $stmt->execute();
                $linked = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
                $stmt->close();
                if ($linked > 0) throw new RuntimeException('Form is linked to a campaign and cannot be deleted.');
                $stmt = $conn->prepare("SELECT COUNT(*) FROM form_submissions WHERE form_id = ?");
                $stmt->bind_param('i', $formId);
                $stmt->execute();
                $subs = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
                $stmt->close();
                if ($subs > 0) throw new RuntimeException('Form has submissions and cannot be deleted.');
                $stmt = $conn->prepare("DELETE FROM forms WHERE id = ?");
                $stmt->bind_param('i', $formId);
                $stmt->execute();
                $stmt->close();
                $message = 'Form deleted successfully.';
                $messageType = 'success';
            } elseif ($action === 'delete_template') {
                $templateId = (int)($_POST['template_id'] ?? 0);
                if ($templateId <= 0) throw new RuntimeException('Invalid template.');
                $stmt = $conn->prepare("DELETE FROM form_templates WHERE id = ?");
                $stmt->bind_param('i', $templateId);
                $stmt->execute();
                $stmt->close();
                $message = 'Template deleted successfully.';
                $messageType = 'success';
            }
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $messageType = 'danger';
        }
    }
}

$forms = [];
$rs = $conn->query("SELECT id, name, fingerprint, created_at FROM forms ORDER BY created_at DESC");
if ($rs) {
    while ($r = $rs->fetch_assoc()) $forms[] = $r;
}

$formBlocked = [];
$rsB = $conn->query("
    SELECT f.id AS form_id,
           MAX(CASE WHEN cf.campaign_id IS NULL THEN 0 ELSE 1 END) AS has_assignment,
           MAX(CASE WHEN fs.form_id IS NULL THEN 0 ELSE 1 END) AS has_submissions
    FROM forms f
    LEFT JOIN campaign_forms cf ON cf.form_id = f.id
    LEFT JOIN form_submissions fs ON fs.form_id = f.id
    GROUP BY f.id
");
if ($rsB) {
    while ($r = $rsB->fetch_assoc()) {
        $formBlocked[(int)$r['form_id']] = ((int)($r['has_assignment'] ?? 0) > 0) || ((int)($r['has_submissions'] ?? 0) > 0);
    }
}

$campaigns = getAllCampaignsBasic();
$assignments = [];
$rs2 = $conn->query("
    SELECT c.id AS campaign_id, c.name AS campaign_name, f.id AS form_id, f.name AS form_name, cf.assigned_at
    FROM campaign_forms cf
    JOIN campaigns c ON c.id = cf.campaign_id
    JOIN forms f ON f.id = cf.form_id
    ORDER BY cf.assigned_at DESC
");
if ($rs2) {
    while ($r = $rs2->fetch_assoc()) $assignments[] = $r;
}

$templates = [];
$rsT = $conn->query("SELECT id, template_name, created_at FROM form_templates ORDER BY created_at DESC");
if ($rsT) {
    while ($r = $rsT->fetch_assoc()) $templates[] = $r;
}

$selectedCampaignId = (int)($_GET['campaign_id'] ?? 0);
$leadStdOpts = getCampaignCreateFormOptionValues();
$leadCountryOptions = is_array($leadStdOpts['targeted_country'] ?? null) ? $leadStdOpts['targeted_country'] : [];
$leadEmployeeSizeOptions = is_array($leadStdOpts['employee_sizes'] ?? null) ? $leadStdOpts['employee_sizes'] : [];
$leadIndustryOptions = is_array($leadStdOpts['industries'] ?? null) ? $leadStdOpts['industries'] : [];

define('INCLUDED', true);
?>
<?php $pageTitle = 'Forms'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>

    <div class="container-fluid px-0">
        <div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-3">
            <div>
                <h2 class="h3 mb-1">Forms</h2>
                <div class="text-muted small">Create campaign forms and link them to campaigns.</div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="pill" data-bs-target="#tab-builder">
                    <i class="bi bi-plus-circle me-1"></i>New Form
                </button>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show border-0 shadow-sm" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <style>
            .forms-manage-tabs .nav-link { padding: .4rem .75rem; }
            .forms-scroll { max-height: 420px; overflow: auto; }
            .forms-scroll thead th { position: sticky; top: 0; z-index: 1; }
            .forms-table-actions .btn { width: 34px; }
        </style>

        <div class="forms-manage-tabs">
            <ul class="nav nav-pills flex-wrap gap-1 mb-3" id="formsManageTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tab-builder-btn" data-bs-toggle="pill" data-bs-target="#tab-builder" type="button" role="tab" aria-controls="tab-builder" aria-selected="true">
                        Builder
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-saved-btn" data-bs-toggle="pill" data-bs-target="#tab-saved" type="button" role="tab" aria-controls="tab-saved" aria-selected="false">
                        Saved Forms
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-campaign-btn" data-bs-toggle="pill" data-bs-target="#tab-campaign" type="button" role="tab" aria-controls="tab-campaign" aria-selected="false">
                        Assign / Copy
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-templates-btn" data-bs-toggle="pill" data-bs-target="#tab-templates" type="button" role="tab" aria-controls="tab-templates" aria-selected="false">
                        Templates
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-assignments-btn" data-bs-toggle="pill" data-bs-target="#tab-assignments" type="button" role="tab" aria-controls="tab-assignments" aria-selected="false">
                        Assignments
                    </button>
                </li>
            </ul>

            <div class="tab-content">
                <div class="tab-pane fade show active" id="tab-builder" role="tabpanel" aria-labelledby="tab-builder-btn" tabindex="0">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-light">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <div class="fw-semibold">Create Form</div>
                                <span class="badge text-bg-light border">Reuses saved form if schema matches</span>
                            </div>
                        </div>
                        <div class="card-body">
                            <form method="post" id="createForm">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="create_form">
                                <input type="hidden" name="schema_json" id="schema_json">

                                <div class="row g-3 mb-3">
                                    <div class="col-lg-8">
                                        <label class="form-label small text-muted">Form Name</label>
                                        <input class="form-control form-control-sm" name="name" placeholder="e.g. LinkedIn Lead Form" required>
                                    </div>
                                    <div class="col-lg-4 d-flex align-items-end">
                                        <button type="button" class="btn btn-outline-primary btn-sm w-100" id="addFieldBtn">
                                            <i class="bi bi-plus-circle me-1"></i>Add Field
                                        </button>
                                    </div>
                                </div>

                                <div class="border rounded p-2 mb-3 bg-light">
                                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                        <div class="fw-semibold">Standard Lead Fields</div>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="small text-muted d-none d-md-block">Toggle fields on/off, set required, and reorder</div>
                                            <button type="button" class="btn btn-sm btn-light border" data-bs-toggle="collapse" data-bs-target="#stdFieldsCollapse" aria-expanded="true" id="stdFieldsToggleBtn">
                                                <i class="bi bi-arrows-angle-contract" id="stdFieldsToggleIcon"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="collapse show" id="stdFieldsCollapse">
                                        <div id="standardFields" class="row g-2"></div>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width: 18%;">Label</th>
                                                <th style="width: 16%;">Key</th>
                                                <th style="width: 12%;">Type</th>
                                                <th style="width: 18%;">Options</th>
                                                <th style="width: 8%;">Visible</th>
                                                <th style="width: 8%;">Required</th>
                                                <th class="d-none d-xl-table-cell" style="width: 10%;">Dup Check</th>
                                                <th class="d-none d-xl-table-cell" style="width: 10%;">Dup Limit</th>
                                                <th class="text-end" style="width: 6%;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="fieldsBody"></tbody>
                                    </table>
                                </div>

                                <div class="d-flex justify-content-end gap-2 mt-3">
                                    <button type="button" class="btn btn-light btn-sm" id="resetBuilderBtn">Reset</button>
                                    <button type="submit" class="btn btn-primary btn-sm px-4">Save Form</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="tab-saved" role="tabpanel" aria-labelledby="tab-saved-btn" tabindex="0">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-light">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <div class="fw-semibold">Saved Forms</div>
                                <input id="savedFormsSearch" class="form-control form-control-sm" style="max-width:320px" placeholder="Search saved forms…">
                            </div>
                        </div>
                        <div class="card-body p-0 forms-scroll">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover align-middle mb-0" id="savedFormsTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="ps-3">Form</th>
                                            <th class="text-end pe-3">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($forms)): ?>
                                            <tr><td colspan="2" class="text-center text-muted py-4">No forms yet.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($forms as $f): ?>
                                                <?php $fid = (int)($f['id'] ?? 0); $blocked = !empty($formBlocked[$fid]); ?>
                                                <tr>
                                                    <td class="ps-3 fw-semibold"><?php echo htmlspecialchars($f['name'] ?? ''); ?></td>
                                                    <td class="text-end pe-3 forms-table-actions">
                                                        <a class="btn btn-sm btn-outline-secondary" href="form-edit.php?id=<?php echo $fid; ?>" title="Edit">
                                                            <i class="bi bi-pencil"></i>
                                                        </a>
                                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this form?');">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                            <input type="hidden" name="action" value="delete_form">
                                                            <input type="hidden" name="form_id" value="<?php echo $fid; ?>">
                                                            <button class="btn btn-sm btn-outline-danger" type="submit" <?php echo $blocked ? 'disabled' : ''; ?> title="<?php echo $blocked ? 'Linked to campaign or has submissions' : 'Delete'; ?>">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="tab-campaign" role="tabpanel" aria-labelledby="tab-campaign-btn" tabindex="0">
                    <div class="row g-3">
                        <div class="col-xl-6">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-header bg-light fw-semibold">Assign Form to Campaign</div>
                                <div class="card-body">
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action" value="assign_form">
                                        <div class="row g-3">
                                            <div class="col-12">
                                                <label class="form-label small text-muted">Campaign</label>
                                                <select class="form-select form-select-sm" name="campaign_id" required>
                                                    <option value="">Select Campaign</option>
                                                    <?php foreach ($campaigns as $c): ?>
                                                    <option value="<?php echo (int)$c['id']; ?>" <?php echo ($selectedCampaignId === (int)$c['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars(crmCampaignDisplayNameFromRow($c)); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label small text-muted">Form</label>
                                                <select class="form-select form-select-sm" name="form_id" required>
                                                    <option value="">Select Form</option>
                                                    <?php foreach ($forms as $f): ?>
                                                        <option value="<?php echo (int)$f['id']; ?>"><?php echo htmlspecialchars($f['name']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <button class="btn btn-primary btn-sm w-100">Assign</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-6">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-header bg-light fw-semibold">Copy Form to Campaign</div>
                                <div class="card-body">
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action" value="copy_form">
                                        <div class="row g-3">
                                            <div class="col-12">
                                                <label class="form-label small text-muted">Source Form</label>
                                                <select class="form-select form-select-sm" name="source_form_id" required>
                                                    <option value="">Select Form</option>
                                                    <?php foreach ($forms as $f): ?>
                                                        <option value="<?php echo (int)$f['id']; ?>"><?php echo htmlspecialchars($f['name']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label small text-muted">Target Campaign</label>
                                                <select class="form-select form-select-sm" name="target_campaign_id" required>
                                                    <option value="">Select Campaign</option>
                                                    <?php foreach ($campaigns as $c): ?>
                                                        <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars(crmCampaignDisplayNameFromRow($c)); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label small text-muted">New Form Name (optional)</label>
                                                <input class="form-control form-control-sm" name="new_form_name" placeholder="e.g. Copy for Campaign X">
                                            </div>
                                            <div class="col-12">
                                                <button class="btn btn-outline-primary btn-sm w-100">Copy & Assign</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="tab-templates" role="tabpanel" aria-labelledby="tab-templates-btn" tabindex="0">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-light">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <div class="fw-semibold">Form Templates</div>
                                <input id="templatesSearch" class="form-control form-control-sm" style="max-width:320px" placeholder="Search templates…">
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-xl-6">
                                    <div class="border rounded p-3 h-100">
                                        <div class="fw-semibold mb-2">Save Template</div>
                                        <form method="post">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="action" value="save_template">
                                            <div class="row g-2">
                                                <div class="col-12">
                                                    <label class="form-label small text-muted">Template Name</label>
                                                    <input class="form-control form-control-sm" name="template_name" required>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label small text-muted">Source Form</label>
                                                    <select class="form-select form-select-sm" name="template_form_id" required>
                                                        <option value="">Select Form</option>
                                                        <?php foreach ($forms as $f): ?>
                                                            <option value="<?php echo (int)$f['id']; ?>"><?php echo htmlspecialchars($f['name']); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-12">
                                                    <button class="btn btn-outline-secondary btn-sm w-100">Save Template</button>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                                <div class="col-xl-6">
                                    <div class="border rounded p-3 h-100">
                                        <div class="fw-semibold mb-2">Create From Template</div>
                                        <form method="post">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="action" value="create_from_template">
                                            <div class="row g-2">
                                                <div class="col-12">
                                                    <label class="form-label small text-muted">Template</label>
                                                    <select class="form-select form-select-sm" name="template_id" required>
                                                        <option value="">Select Template</option>
                                                        <?php foreach ($templates as $t): ?>
                                                            <option value="<?php echo (int)$t['id']; ?>"><?php echo htmlspecialchars($t['template_name']); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label small text-muted">New Form Name</label>
                                                    <input class="form-control form-control-sm" name="name" required>
                                                </div>
                                                <div class="col-12">
                                                    <button class="btn btn-primary btn-sm w-100">Create From Template</button>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-3">
                                <?php if (empty($templates)): ?>
                                    <div class="text-muted small">No templates yet.</div>
                                <?php else: ?>
                                    <div class="table-responsive forms-scroll">
                                        <table class="table table-sm align-middle mb-0" id="templatesTable">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Template</th>
                                                    <th class="text-end">Delete</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($templates as $t): ?>
                                                    <tr>
                                                        <td class="fw-semibold"><?php echo htmlspecialchars($t['template_name'] ?? ''); ?></td>
                                                        <td class="text-end">
                                                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this template?');">
                                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                                <input type="hidden" name="action" value="delete_template">
                                                                <input type="hidden" name="template_id" value="<?php echo (int)($t['id'] ?? 0); ?>">
                                                                <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
                                                            </form>
                                                        </td>
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

                <div class="tab-pane fade" id="tab-assignments" role="tabpanel" aria-labelledby="tab-assignments-btn" tabindex="0">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-light">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <div class="fw-semibold">Campaign Assignments</div>
                                <input id="assignmentsSearch" class="form-control form-control-sm" style="max-width:320px" placeholder="Search assignments…">
                            </div>
                        </div>
                        <div class="card-body p-0 forms-scroll">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover align-middle mb-0" id="assignmentsTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="ps-3">Campaign</th>
                                            <th>Form</th>
                                            <th class="text-end">Edit</th>
                                            <th class="pe-3 text-end">Assigned</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($assignments)): ?>
                                            <tr><td colspan="4" class="text-center text-muted py-4">No assignments yet.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($assignments as $a): ?>
                                                <tr>
                                                    <td class="ps-3 fw-semibold"><?php echo htmlspecialchars($a['campaign_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($a['form_name']); ?></td>
                                                    <td class="text-end">
                                                        <a class="btn btn-sm btn-outline-secondary" href="form-edit.php?id=<?php echo (int)$a['form_id']; ?>" title="Edit">
                                                            <i class="bi bi-pencil"></i>
                                                        </a>
                                                    </td>
                                                    <td class="pe-3 text-end small text-muted"><?php echo htmlspecialchars($a['assigned_at']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const tabsEl = document.getElementById('formsManageTabs');
            if (!tabsEl || !window.bootstrap) return;
            const storageKey = 'crm.forms.manage.tab';
            const buttons = Array.from(tabsEl.querySelectorAll('[data-bs-toggle="pill"]'));
            const byTarget = new Map(buttons.map(b => [b.getAttribute('data-bs-target'), b]));

            const hash = window.location.hash || '';
            const stored = localStorage.getItem(storageKey) || '';
            const initialTarget = (hash && byTarget.has(hash)) ? hash : (stored && byTarget.has(stored) ? stored : '');
            if (initialTarget) {
                const btn = byTarget.get(initialTarget);
                if (btn) bootstrap.Tab.getOrCreateInstance(btn).show();
            }
            buttons.forEach(btn => {
                btn.addEventListener('shown.bs.tab', (e) => {
                    const target = e.target?.getAttribute('data-bs-target') || '';
                    if (!target) return;
                    localStorage.setItem(storageKey, target);
                    if (window.history && window.history.replaceState) {
                        window.history.replaceState(null, '', target);
                    }
                });
            });
        })();

        (function () {
            function wireTableSearch(inputId, tableId) {
                const input = document.getElementById(inputId);
                const table = document.getElementById(tableId);
                if (!input || !table) return;
                const tbody = table.querySelector('tbody');
                if (!tbody) return;

                input.addEventListener('input', () => {
                    const q = (input.value || '').trim().toLowerCase();
                    const rows = Array.from(tbody.querySelectorAll('tr'));
                    rows.forEach(tr => {
                        const text = (tr.textContent || '').toLowerCase();
                        tr.style.display = (q === '' || text.includes(q)) ? '' : 'none';
                    });
                });
            }

            wireTableSearch('savedFormsSearch', 'savedFormsTable');
            wireTableSearch('templatesSearch', 'templatesTable');
            wireTableSearch('assignmentsSearch', 'assignmentsTable');
        })();
    </script>

    <script>
        const fieldsBody = document.getElementById('fieldsBody');
        const schemaInput = document.getElementById('schema_json');

        function toKey(raw) {
            return String(raw || '')
                .trim()
                .toLowerCase()
                .replace(/[\s-]+/g, '_')
                .replace(/[^a-z0-9_]/g, '')
                .replace(/_+/g, '_')
                .replace(/^_+|_+$/g, '');
        }
        const synonyms = {
            'linkedin_link': 'prospect_linkedin_link',
            'prospect_linkedin_link': 'prospect_linkedin_link',
            'company_linkedin': 'company_linkedin_link',
            'company_linkedin_link': 'company_linkedin_link',
            'company_website': 'company_website',
            'website': 'company_website',
            'domain': 'company_website',
            'software_implementation_timeline': 'software_implementation_timeline',
            'implementation_timeline': 'software_implementation_timeline',
            'when_is_your_company_planning_to_implement_new_software': 'software_implementation_timeline'
        };
        function canonicalKey(k){
            const nk = toKey(k);
            return synonyms[nk] || nk;
        }

        function rowTemplate() {
            return `
                <tr>
                    <td><input class="form-control form-control-sm" placeholder="Label" data-f="label" required></td>
                    <td><input class="form-control form-control-sm" placeholder="key_name" data-f="key" required></td>
                    <td>
                        <select class="form-select form-select-sm" data-f="type">
                            <option value="text">Text</option>
                            <option value="textarea">Textarea</option>
                            <option value="email">Email</option>
                            <option value="tel">Phone</option>
                            <option value="url">URL</option>
                            <option value="number">Number</option>
                            <option value="date">Date</option>
                            <option value="select">Select</option>
                            <option value="radio">Radio</option>
                            <option value="checkbox">Checkbox</option>
                            <option value="file_upload">File Upload</option>
                        </select>
                    </td>
                    <td><input class="form-control form-control-sm" placeholder="Option1, Option2" data-f="options"></td>
                    <td class="text-center"><input class="form-check-input" type="checkbox" data-f="visible" checked></td>
                    <td class="text-center"><input class="form-check-input" type="checkbox" data-f="required"></td>
                    <td class="text-center d-none d-xl-table-cell"><input class="form-check-input" type="checkbox" data-f="duplicate_check"></td>
                    <td class="d-none d-xl-table-cell"><input class="form-control form-control-sm" type="number" min="1" step="1" value="1" data-f="accepted_duplicates" style="display:none;" disabled></td>
                    <td class="text-end">
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-light border" data-move="up" title="Move up"><i class="bi bi-arrow-up"></i></button>
                            <button type="button" class="btn btn-light border" data-move="down" title="Move down"><i class="bi bi-arrow-down"></i></button>
                            <button type="button" class="btn btn-light border" data-remove title="Remove"><i class="bi bi-x-lg"></i></button>
                        </div>
                    </td>
                </tr>
            `;
        }

        function buildSchema() {
            const rows = Array.from(fieldsBody.querySelectorAll('tr'));
            const fields = rows.map(tr => {
                const label = tr.querySelector('[data-f="label"]').value.trim();
                const key = toKey(tr.querySelector('[data-f="key"]').value.trim());
                const type = tr.querySelector('[data-f="type"]').value;
                const visible = tr.querySelector('[data-f="visible"]').checked;
                const required = tr.querySelector('[data-f="required"]').checked;
                const duplicate_check = tr.querySelector('[data-f="duplicate_check"]').checked;
                const adEl = tr.querySelector('[data-f="accepted_duplicates"]');
                let accepted_duplicates = parseInt(adEl?.value || '1', 10);
                if (!Number.isFinite(accepted_duplicates) || accepted_duplicates < 1) accepted_duplicates = 1;
                const optRaw = tr.querySelector('[data-f="options"]').value.trim();
                const options = optRaw ? optRaw.split(',').map(s => s.trim()).filter(Boolean) : [];
                return { label, key, type, visible, required, options, duplicate_check, accepted_duplicates };
            }).filter(f => f.label && f.key);
            return { version: 1, fields };
        }

        function syncDuplicateUi(tr) {
            const chk = tr.querySelector('[data-f="duplicate_check"]');
            const inp = tr.querySelector('[data-f="accepted_duplicates"]');
            if (!chk || !inp) return;
            if (chk.checked) {
                inp.style.display = '';
                inp.disabled = false;
                const n = parseInt(inp.value || '1', 10);
                if (!Number.isFinite(n) || n < 1) inp.value = '1';
            } else {
                inp.style.display = 'none';
                inp.disabled = true;
            }
        }

        const stdLeadOptions = <?php echo json_encode([
            'country' => array_values($leadCountryOptions),
            'employee_size' => array_values($leadEmployeeSizeOptions),
            'industry' => array_values($leadIndustryOptions),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

        const standardFields = [
            { group: 'Prospect Details', label: 'First Name', key: 'first_name', type: 'text', required: true, visible: true },
            { group: 'Prospect Details', label: 'Last Name', key: 'last_name', type: 'text', required: true, visible: true },
            { group: 'Prospect Details', label: 'Job Title', key: 'job_title', type: 'text', required: false, visible: true },
            { group: 'Prospect Details', label: 'Email', key: 'email', type: 'email', required: false, visible: true },
            { group: 'Prospect Details', label: 'Phone', key: 'phone', type: 'tel', required: false, visible: true },
            { group: 'Prospect Details', label: 'LinkedIn Profile', key: 'prospect_linkedin_link', type: 'url', required: false, visible: true },
            { group: 'Company Details', label: 'Company Name', key: 'company_name', type: 'text', required: false, visible: true },
            { group: 'Company Details', label: 'Company Website', key: 'company_website', type: 'url', required: false, visible: true },
            { group: 'Company Details', label: 'Industry', key: 'industry', type: 'select', required: false, visible: true, options: stdLeadOptions.industry || [] },
            { group: 'Company Details', label: 'Employee Size', key: 'employee_size', type: 'select', required: false, visible: true, options: stdLeadOptions.employee_size || [] },
            { group: 'Company Details', label: 'Country', key: 'country', type: 'select', required: false, visible: true, options: stdLeadOptions.country || [] },
            { group: 'Company Details', label: 'Company LinkedIn', key: 'company_linkedin_link', type: 'url', required: false, visible: true },
        ];

        const standardWrap = document.getElementById('standardFields');

        function standardItemTemplate(f) {
            const id = 'std_' + f.key;
            return `
                <div class="col-md-6 col-lg-4">
                    <div class="border rounded bg-white p-2 h-100">
                        <div class="d-flex align-items-start justify-content-between gap-2">
                            <div class="form-check m-0">
                                <input class="form-check-input" type="checkbox" id="${id}" data-std-toggle="${f.key}" ${f.visible ? 'checked' : ''}>
                                <label class="form-check-label fw-semibold" for="${id}">${escapeHtml(f.label)}</label>
                            </div>
                            <div class="form-check m-0">
                                <input class="form-check-input" type="checkbox" id="${id}_req" data-std-required="${f.key}" ${f.required ? 'checked' : ''}>
                                <label class="form-check-label small text-muted" for="${id}_req">Required</label>
                            </div>
                        </div>
                        <div class="mt-2">
                            <input class="form-control form-control-sm" data-std-label="${f.key}" value="${escapeHtml(f.label)}">
                            <div class="small text-muted mt-1">${escapeHtml(f.group)} • ${escapeHtml(f.type.toUpperCase())} • Key: <span class="fw-semibold">${escapeHtml(f.key)}</span></div>
                        </div>
                    </div>
                </div>
            `;
        }

        function escapeHtml(str) {
            return String(str || '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
        }

        function findRowByKey(key) {
            const rows = Array.from(fieldsBody.querySelectorAll('tr'));
            for (const tr of rows) {
                const k = toKey(tr.querySelector('[data-f="key"]')?.value || '');
                if (k === key) return tr;
            }
            return null;
        }

        function applyStandardToTable(f) {
            let tr = findRowByKey(f.key);
            if (!tr) {
                fieldsBody.insertAdjacentHTML('beforeend', rowTemplate());
                tr = fieldsBody.lastElementChild;
                tr.setAttribute('data-system', '1');
                const keyEl = tr.querySelector('[data-f="key"]');
                keyEl.value = f.key;
                keyEl.readOnly = true;
                keyEl.classList.add('bg-light');
                tr.querySelector('[data-f="type"]').value = f.type;
                tr.querySelector('[data-f="type"]').disabled = true;
                const optEl = tr.querySelector('[data-f="options"]');
                if (optEl) {
                    if (String(f.type || '').toLowerCase() === 'select') {
                        optEl.readOnly = false;
                        optEl.classList.remove('bg-light');
                    } else {
                        optEl.readOnly = true;
                        optEl.classList.add('bg-light');
                    }
                }
                syncDuplicateUi(tr);
            }
            tr.querySelector('[data-f="label"]').value = f.label;
            tr.querySelector('[data-f="required"]').checked = !!f.required;
            tr.querySelector('[data-f="visible"]').checked = !!f.visible;
            const optRaw = Array.isArray(f.options) ? f.options.join(', ') : '';
            const optEl = tr.querySelector('[data-f="options"]');
            if (optEl) optEl.value = optRaw;
        }

        function renderStandardFields() {
            const groups = [];
            const seen = {};
            for (const f of standardFields) {
                if (!seen[f.group]) { seen[f.group] = true; groups.push(f.group); }
            }
            standardWrap.innerHTML = groups.map(g => {
                const inner = standardFields.filter(x => x.group === g).map(standardItemTemplate).join('');
                return `<div class="col-12"><div class="fw-semibold small text-muted mb-2">${escapeHtml(g)}</div><div class="row g-2">${inner}</div></div>`;
            }).join('');
            standardFields.forEach(applyStandardToTable);
            validateKeys();
        }

        function validateKeys() {
            const rows = Array.from(fieldsBody.querySelectorAll('tr'));
            const keys = {};
            const canon = {};
            let ok = true;
            rows.forEach(tr => {
                const keyEl = tr.querySelector('[data-f="key"]');
                const k = toKey(keyEl.value);
                keyEl.classList.remove('is-invalid');
                if (!k) return;
                if (keys[k]) {
                    keyEl.classList.add('is-invalid');
                    keys[k].classList.add('is-invalid');
                    ok = false;
                } else {
                    keys[k] = keyEl;
                }
                const c = canonicalKey(k);
                if (canon[c] && canon[c] !== keyEl) {
                    keyEl.classList.add('is-invalid');
                    canon[c].classList.add('is-invalid');
                    ok = false;
                } else {
                    canon[c] = keyEl;
                }
            });
            return ok;
        }

        document.getElementById('addFieldBtn').addEventListener('click', () => {
            fieldsBody.insertAdjacentHTML('beforeend', rowTemplate());
            const tr = fieldsBody.lastElementChild;
            if (tr) syncDuplicateUi(tr);
            validateKeys();
        });

        fieldsBody.addEventListener('click', (e) => {
            const moveBtn = e.target.closest('[data-move]');
            if (moveBtn) {
                const dir = moveBtn.getAttribute('data-move');
                const tr = moveBtn.closest('tr');
                if (!tr || !tr.parentElement) return;
                if (dir === 'up') {
                    const prev = tr.previousElementSibling;
                    if (prev) tr.parentElement.insertBefore(tr, prev);
                } else if (dir === 'down') {
                    const next = tr.nextElementSibling;
                    if (next) tr.parentElement.insertBefore(next, tr);
                }
                return;
            }
            const btn = e.target.closest('[data-remove]');
            if (!btn) return;
            const tr = btn.closest('tr');
            if (tr?.getAttribute('data-system') === '1') {
                const k = toKey(tr.querySelector('[data-f="key"]')?.value || '');
                const t = document.querySelector(`[data-std-toggle="${k}"]`);
                if (t) t.checked = false;
            }
            tr.remove();
            validateKeys();
        });

        fieldsBody.addEventListener('change', (e) => {
            const dupEl = e.target.closest('[data-f="duplicate_check"]');
            if (!dupEl) return;
            const tr = dupEl.closest('tr');
            if (tr) syncDuplicateUi(tr);
        });

        fieldsBody.addEventListener('input', (e) => {
            const labelEl = e.target.closest('[data-f="label"]');
            if (!labelEl) return;
            const tr = labelEl.closest('tr');
            const keyEl = tr?.querySelector('[data-f="key"]');
            if (!keyEl) return;
            if (keyEl.value.trim() !== '') return;
            keyEl.value = toKey(labelEl.value);
        });

        fieldsBody.addEventListener('blur', (e) => {
            const keyEl = e.target.closest('[data-f="key"]');
            if (!keyEl) return;
            keyEl.value = toKey(keyEl.value);
            validateKeys();
        }, true);

        document.getElementById('resetBuilderBtn').addEventListener('click', () => {
            fieldsBody.innerHTML = '';
            standardFields.forEach(f => { f.visible = true; f.required = !!f.required; });
            renderStandardFields();
        });

        document.getElementById('createForm').addEventListener('submit', (e) => {
            if (!validateKeys()) {
                e.preventDefault();
                if (window.appNotify) window.appNotify('warning', 'Duplicate field keys detected. Please make keys unique.', 'Warning');
                else alert('Duplicate field keys detected. Please make keys unique.');
                return;
            }
            const schema = buildSchema();
            schemaInput.value = JSON.stringify(schema);
        });

        standardWrap.addEventListener('input', (e) => {
            const labelEl = e.target.closest('[data-std-label]');
            if (labelEl) {
                const key = labelEl.getAttribute('data-std-label');
                const f = standardFields.find(x => x.key === key);
                if (f) {
                    f.label = labelEl.value.trim() || f.label;
                    applyStandardToTable(f);
                }
                return;
            }
            const reqEl = e.target.closest('[data-std-required]');
            if (reqEl) {
                const key = reqEl.getAttribute('data-std-required');
                const f = standardFields.find(x => x.key === key);
                if (f) {
                    f.required = !!reqEl.checked;
                    applyStandardToTable(f);
                }
                return;
            }
            const toggleEl = e.target.closest('[data-std-toggle]');
            if (toggleEl) {
                const key = toggleEl.getAttribute('data-std-toggle');
                const f = standardFields.find(x => x.key === key);
                if (f) {
                    f.visible = !!toggleEl.checked;
                    applyStandardToTable(f);
                }
                return;
            }
        });

        const stdCollapse = document.getElementById('stdFieldsCollapse');
        const stdIcon = document.getElementById('stdFieldsToggleIcon');
        const stdBtn = document.getElementById('stdFieldsToggleBtn');
        if (stdCollapse && stdIcon && stdBtn) {
            stdCollapse.addEventListener('shown.bs.collapse', () => {
                stdIcon.className = 'bi bi-arrows-angle-contract';
                stdBtn.setAttribute('aria-expanded', 'true');
            });
            stdCollapse.addEventListener('hidden.bs.collapse', () => {
                stdIcon.className = 'bi bi-arrows-angle-expand';
                stdBtn.setAttribute('aria-expanded', 'false');
            });
        }

        renderStandardFields();
    </script>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
