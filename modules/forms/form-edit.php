<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['admin','director','manager_director','sales_director','sales_manager','operations_director']);
ensureCsrfToken();

$conn = getDbConnection();
$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: forms-manage'); exit; }

$stmt = $conn->prepare("SELECT id, name, schema_json FROM forms WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$formRow = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$formRow) { header('Location: forms-manage'); exit; }

$originalSchema = json_decode($formRow['schema_json'] ?? '', true);
if (!is_array($originalSchema)) $originalSchema = ['fields' => []];

$assignedCampaigns = [];
$stmt = $conn->prepare("
    SELECT c.id AS campaign_id, c.name AS campaign_name, d.status
    FROM campaign_forms cf
    JOIN campaigns c ON c.id = cf.campaign_id
    JOIN campaign_details d ON d.campaign_id = c.id
    WHERE cf.form_id = ?
    ORDER BY cf.assigned_at DESC
");
$stmt->bind_param('i', $id);
$stmt->execute();
$assignedCampaigns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

$isLiveLocked = false;
foreach ($assignedCampaigns as $c) {
    if (($c['status'] ?? '') === 'Live') { $isLiveLocked = true; break; }
}

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = 'Invalid request token.';
        $messageType = 'danger';
    } else {
        try {
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'create_editable_copy') {
                $schema = is_array($originalSchema) ? $originalSchema : ['fields' => []];
                $schema['meta'] = array_merge((array)($schema['meta'] ?? []), [
                    'copied_from' => $id,
                    'copy_nonce' => bin2hex(random_bytes(8)),
                ]);
                $newName = trim((string)($_POST['new_name'] ?? ''));
                if ($newName === '') $newName = (($formRow['name'] ?? 'Form') . ' Copy');
                $newId = createOrReuseForm($newName, $schema, $userId);
                header('Location: form-edit?id=' . $newId);
                exit;
            }

            $name = trim((string)($_POST['name'] ?? ''));
            $schemaJson = (string)($_POST['schema_json'] ?? '');
            $schema = json_decode($schemaJson, true);
            if ($name === '' || !is_array($schema)) throw new RuntimeException('Please provide a form name and valid schema.');
            if (!isset($schema['fields']) || !is_array($schema['fields'])) $schema['fields'] = [];
            $normKey = function(string $raw): string {
                $s = strtolower(trim($raw));
                $s = preg_replace('/[^a-z0-9]+/', '_', $s);
                $s = preg_replace('/_+/', '_', $s);
                return trim($s, '_');
            };
            $seenKeys = [];
            foreach ($schema['fields'] as $f) {
                if (!is_array($f)) continue;
                $k = $normKey((string)($f['key'] ?? ''));
                $lbl = trim((string)($f['label'] ?? ''));
                if ($k === '' || $lbl === '') throw new RuntimeException('Each field must have key and label.');
                if (isset($seenKeys[$k])) throw new RuntimeException('Duplicate field key detected: ' . $k);
                $seenKeys[$k] = true;
                    if (!empty($f['duplicate_check'])) {
                        $limit = (int)($f['accepted_duplicates'] ?? 1);
                        if ($limit < 1) throw new RuntimeException('Accepted duplicates must be at least 1 for field: ' . $lbl);
                    }
            }

            $normalize = function($s) {
                $fields = (array)($s['fields'] ?? []);
                $out = [];
                foreach ($fields as $f) {
                    if (!is_array($f)) continue;
                    $key = (string)($f['key'] ?? '');
                    if ($key === '') continue;
                    $out[$key] = [
                        'key' => $key,
                        'type' => (string)($f['type'] ?? 'text'),
                        'visible' => (bool)($f['visible'] ?? true),
                        'required' => (bool)($f['required'] ?? false),
                        'options' => array_values(array_filter((array)($f['options'] ?? []), fn($v) => trim((string)$v) !== '')),
                        'label' => (string)($f['label'] ?? $key),
                    ];
                }
                ksort($out);
                return $out;
            };

            if ($isLiveLocked) {
                $old = $normalize($originalSchema);
                $new = $normalize($schema);
                if (array_keys($old) !== array_keys($new)) throw new RuntimeException('Campaign is Live. Form structure cannot change.');
                foreach ($old as $k => $of) {
                    $nf = $new[$k];
                    if ($of['type'] !== $nf['type']) throw new RuntimeException('Campaign is Live. Field types cannot change.');
                    if ((bool)$of['visible'] !== (bool)$nf['visible']) throw new RuntimeException('Campaign is Live. Visibility cannot change.');
                    if ((bool)$of['required'] !== (bool)$nf['required']) throw new RuntimeException('Campaign is Live. Required flags cannot change.');
                    if ($of['options'] !== $nf['options']) throw new RuntimeException('Campaign is Live. Field options cannot change.');
                }
            }

            $fingerprint = hash('sha256', json_encode($schema, JSON_UNESCAPED_UNICODE));
            $stmt = $conn->prepare("UPDATE forms SET name = ?, schema_json = ?, fingerprint = ?, created_by = COALESCE(created_by, ?), created_at = COALESCE(created_at, NOW()) WHERE id = ?");
            $stmt->bind_param('sssii', $name, $schemaJson, $fingerprint, $userId, $id);
            if (!$stmt->execute()) throw new RuntimeException('Failed to update form.');
            $stmt->close();

            $formRow['name'] = $name;
            $formRow['schema_json'] = $schemaJson;
            $originalSchema = $schema;
            $message = 'Form updated successfully.';
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $messageType = 'danger';
        }
    }
}

define('INCLUDED', true);
?>
<?php $pageTitle = 'Edit Form'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-1">Edit Form</h3>
      <div class="text-muted small"><?php echo htmlspecialchars($formRow['name'] ?? ''); ?></div>
    </div>
    <a class="btn btn-outline-primary btn-sm" href="forms-manage.php">Back</a>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
  <?php endif; ?>

  <?php if (!empty($assignedCampaigns)): ?>
    <div class="card mb-3 border-0 shadow-sm">
      <div class="card-header fw-bold">Assigned Campaigns</div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th class="ps-3">Campaign</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($assignedCampaigns as $c): ?>
                <tr>
                  <td class="ps-3 fw-semibold"><?php echo htmlspecialchars($c['campaign_name'] ?? ''); ?></td>
                  <td><span class="badge bg-secondary"><?php echo htmlspecialchars($c['status'] ?? ''); ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php if ($isLiveLocked): ?>
        <div class="card-footer text-muted small">Campaign is Live: only field labels can be changed.</div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($isLiveLocked): ?>
    <div class="alert alert-warning">
      <div class="fw-semibold mb-1">This form is locked because it is assigned to a Live campaign.</div>
      <div class="small">To change Key / Type / Options / Required, create an editable copy and assign it to the campaign.</div>
      <form method="post" class="mt-2">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="create_editable_copy">
        <div class="row g-2 align-items-end">
          <div class="col-md-8">
            <label class="form-label small text-muted">New Form Name</label>
            <input class="form-control form-control-sm" name="new_name" value="<?php echo htmlspecialchars(($formRow['name'] ?? 'Form').' Copy'); ?>">
          </div>
          <div class="col-md-4 d-grid">
            <button class="btn btn-outline-primary btn-sm" type="submit">Create Editable Copy</button>
          </div>
        </div>
      </form>
    </div>
  <?php endif; ?>

  <div class="card border-0 shadow-sm">
    <div class="card-header fw-bold">Edit Form</div>
    <div class="card-body">
      <form method="post" id="editForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <div class="row g-3">
          <div class="col-md-8">
            <label class="form-label small text-muted">Form Name</label>
            <input class="form-control form-control-sm" name="name" value="<?php echo htmlspecialchars($formRow['name'] ?? ''); ?>" required>
          </div>
          <div class="col-md-4 d-flex align-items-end">
            <button type="button" class="btn btn-outline-primary btn-sm w-100" id="addFieldBtn" <?php echo $isLiveLocked ? 'disabled' : ''; ?>>
              <i class="bi bi-plus-circle me-1"></i>Add Field
            </button>
          </div>
          <input type="hidden" name="schema_json" id="schema_json">
          <div class="col-12">
            <div class="table-responsive">
              <table class="table table-sm align-middle field-table mb-0">
                <thead class="bg-light">
                  <tr>
                    <th style="width: 18%;">Label</th>
                    <th style="width: 16%;">Key</th>
                    <th style="width: 12%;">Type</th>
                    <th style="width: 18%;">Options</th>
                    <th style="width: 8%;">Visible</th>
                    <th style="width: 8%;">Required</th>
                    <th style="width: 10%;">Duplicate Check</th>
                    <th style="width: 10%;">Accepted Duplicates</th>
                    <th class="text-end" style="width: 10%;">Actions</th>
                  </tr>
                </thead>
                <tbody id="fieldsBody"></tbody>
              </table>
            </div>
            <div class="text-muted small mt-2">
              Keys auto-generate from labels (spaces become _). File upload type is file_upload.
            </div>
          </div>
          <div class="col-12 d-flex justify-content-end">
            <button type="button" class="btn btn-light btn-sm me-2" id="resetBuilderBtn" <?php echo $isLiveLocked ? 'disabled' : ''; ?>>Reset</button>
            <button class="btn btn-primary btn-sm" type="submit">Save</button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
const fieldsBody = document.getElementById('fieldsBody');
const schemaInput = document.getElementById('schema_json');
const isLiveLocked = <?php echo $isLiveLocked ? 'true' : 'false'; ?>;
const initialFields = <?php echo json_encode((array)($originalSchema['fields'] ?? []), JSON_UNESCAPED_UNICODE); ?>;

function toKey(raw) {
  return String(raw || '')
    .trim()
    .toLowerCase()
    .replace(/[\s-]+/g, '_')
    .replace(/[^a-z0-9_]/g, '')
    .replace(/_+/g, '_')
    .replace(/^_+|_+$/g, '');
}

function rowTemplate(v) {
  const label = v?.label ?? '';
  const key = v?.key ?? '';
  const type = v?.type ?? 'text';
  const visible = (v && Object.prototype.hasOwnProperty.call(v, 'visible')) ? !!v.visible : true;
  const required = !!v?.required;
  const duplicate_check = !!v?.duplicate_check;
  const accepted_duplicates = (v && Object.prototype.hasOwnProperty.call(v, 'accepted_duplicates')) ? parseInt(v.accepted_duplicates || '1', 10) : 1;
  const options = Array.isArray(v?.options) ? v.options.join(', ') : '';
  const disableStruct = isLiveLocked ? 'disabled' : '';
  const disableActions = isLiveLocked ? 'disabled' : '';
  return `
    <tr>
      <td><input class="form-control form-control-sm" placeholder="Label" data-f="label" value="${escapeHtml(label)}" required></td>
      <td><input class="form-control form-control-sm" placeholder="key_name" data-f="key" value="${escapeHtml(key)}" ${disableStruct} required></td>
      <td>
        <select class="form-select form-select-sm" data-f="type" ${disableStruct}>
          ${['text','textarea','email','tel','url','number','date','select','radio','checkbox','file_upload'].map(t => `<option value="${t}" ${String(type)===t?'selected':''}>${t}</option>`).join('')}
        </select>
      </td>
      <td><input class="form-control form-control-sm" placeholder="Option1, Option2" data-f="options" value="${escapeHtml(options)}" ${disableStruct}></td>
      <td class="text-center"><input class="form-check-input" type="checkbox" data-f="visible" ${visible?'checked':''} ${disableStruct}></td>
      <td class="text-center"><input class="form-check-input" type="checkbox" data-f="required" ${required?'checked':''} ${disableStruct}></td>
      <td class="text-center"><input class="form-check-input" type="checkbox" data-f="duplicate_check" ${duplicate_check?'checked':''}></td>
      <td><input class="form-control form-control-sm" type="number" min="1" step="1" value="${Number.isFinite(accepted_duplicates) && accepted_duplicates >= 1 ? accepted_duplicates : 1}" data-f="accepted_duplicates" style="${duplicate_check ? '' : 'display:none;'}" ${duplicate_check ? '' : 'disabled'}></td>
      <td class="text-end">
        <div class="btn-group btn-group-sm" role="group">
          <button type="button" class="btn btn-light border" data-move="up" title="Move up" ${disableActions}><i class="bi bi-arrow-up"></i></button>
          <button type="button" class="btn btn-light border" data-move="down" title="Move down" ${disableActions}><i class="bi bi-arrow-down"></i></button>
          <button type="button" class="btn btn-light border" data-remove title="Remove" ${disableActions}><i class="bi bi-x-lg"></i></button>
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

function syncSchemaJson() {
  const schema = buildSchema();
  schemaInput.value = JSON.stringify(schema);
}

function escapeHtml(s) {
  const d = document.createElement('div');
  d.textContent = String(s ?? '');
  return d.innerHTML;
}

function validateKeys() {
  const rows = Array.from(fieldsBody.querySelectorAll('tr'));
  const keys = {};
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
  });
  return ok;
}

function addRow(v) {
  fieldsBody.insertAdjacentHTML('beforeend', rowTemplate(v));
  const tr = fieldsBody.lastElementChild;
  if (tr) syncDuplicateUi(tr);
  validateKeys();
}

document.getElementById('addFieldBtn')?.addEventListener('click', () => {
  if (isLiveLocked) return;
  addRow({});
});

document.getElementById('resetBuilderBtn')?.addEventListener('click', () => {
  if (isLiveLocked) return;
  fieldsBody.innerHTML = '';
});

fieldsBody.addEventListener('click', (e) => {
  const moveBtn = e.target.closest('[data-move]');
  if (moveBtn) {
    if (isLiveLocked) return;
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
  if (!btn || isLiveLocked) return;
  btn.closest('tr')?.remove();
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

initialFields.forEach(f => addRow(f));
if (fieldsBody.children.length === 0) addRow({});

document.getElementById('editForm')?.addEventListener('submit', (e) => {
  if (!validateKeys()) {
    e.preventDefault();
    if (window.appNotify) window.appNotify('warning', 'Duplicate field keys detected. Please make keys unique.', 'Warning');
    else alert('Duplicate field keys detected. Please make keys unique.');
    return;
  }
  syncSchemaJson();
});
</script>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
