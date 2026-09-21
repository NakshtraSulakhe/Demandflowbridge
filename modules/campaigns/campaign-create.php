<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermissionOrRole('campaigns.create.page', []);
ensureCsrfToken();
$conn = getDbConnection();
$clients = [];
$rs = $conn->query("SELECT id, client_code, name FROM clients ORDER BY client_code, name");
if ($rs) { $clients = $rs->fetch_all(MYSQLI_ASSOC) ?: []; }
$wantsPreview = (isset($_GET['action']) && (string)$_GET['action'] === 'code_preview');
$previewClientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
if ($wantsPreview) {
    header('Content-Type: application/json; charset=utf-8');
    if ($previewClientId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Missing client_id']);
        exit;
    }
    $stmt = $conn->prepare("SELECT client_code FROM clients WHERE id = ? LIMIT 1");
    if (!$stmt) {
        echo json_encode(['ok' => false, 'error' => 'Database error']);
        exit;
    }
    $stmt->bind_param('i', $previewClientId);
    $stmt->execute();
    $clientCode = (string)($stmt->get_result()->fetch_row()[0] ?? '');
    $stmt->close();
    if ($clientCode === '') {
        echo json_encode(['ok' => false, 'error' => 'Client not found']);
        exit;
    }
    try {
        echo json_encode(['ok' => true, 'code' => generateCampaignCode($clientCode)]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Failed to generate preview']);
    }
    exit;
}
$message='';
$error='';
$formOpts = getCampaignCreateFormOptionValues();
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['action'])&&$_POST['action']==='create'){
    $csrf=$_POST['csrf_token']??'';
    if(!hash_equals($_SESSION['csrf_token'],$csrf)){
        $error='Invalid security token.';
    } else {
        try{
            $clientId = (int)($_POST['client_id'] ?? 0);
            $clientCode = null;
            if ($clientId > 0) {
                $stmt = $conn->prepare("SELECT client_code FROM clients WHERE id = ? LIMIT 1");
                $stmt->bind_param('i', $clientId);
                $stmt->execute();
                $clientCode = $stmt->get_result()->fetch_row()[0] ?? null;
                $stmt->close();
            }
            $basic=[
                'client_id'=>$clientId ?: null,
                'client_code'=>$clientCode,
                'name'=>trim($_POST['name']??''),
                'status'=>$_POST['status']??'Draft',
                'start_date'=>$_POST['start_date']??null,
                'end_date'=>$_POST['end_date']??null,
                'total_leads'=>$_POST['total_leads']??null,
                'pacing_type'=>$_POST['pacing_type']??null,
                'pacing_count'=>$_POST['pacing_count']??null,
                'cpc'=>$_POST['cpc']??null,
                'cpl'=>$_POST['cpl']??null,
                'cpl_currency'=>$_POST['cpl_currency']??null,
                'campaign_type'=>$_POST['campaign_type']??null,
                'delivery_format'=>(($_POST['delivery_format']??'')==='Other')?trim($_POST['delivery_format_other']??''):($_POST['delivery_format']??null),
                'instruction'=>$_POST['instruction']??null,
                'owner_id'=>(int)(getCurrentUser()['id'] ?? 0),
                'created_by'=>(int)(getCurrentUser()['id'] ?? 0),
            ];
            if(!$basic['client_id'] || !$basic['client_code']){ throw new RuntimeException('Client Code is required'); }
            if($basic['name']===''){ throw new RuntimeException('Campaign name is required'); }
            if (!isAdmin() && !hasRole(['director','manager_director','sales_director'])) {
                $basic['cpl'] = null;
                $basic['cpl_currency'] = null;
            }
            $criteria=[
                'targeted_country'=>isset($_POST['targeted_country'])?array_values(array_filter((array)$_POST['targeted_country'])):[],
                'job_title'=>trim($_POST['job_title']??''),
                'departments'=>isset($_POST['departments'])?array_values(array_filter((array)$_POST['departments'])):[],
                'seniority_levels'=>isset($_POST['seniority_levels'])?array_values(array_filter((array)$_POST['seniority_levels'])):[],
                'industries'=>isset($_POST['industries'])?array_values(array_filter((array)$_POST['industries'])):[],
                'employee_sizes'=>isset($_POST['employee_sizes'])?array_values(array_filter((array)$_POST['employee_sizes'])):[],
                'revenue_sizes'=>isset($_POST['revenue_sizes'])?array_values(array_filter((array)$_POST['revenue_sizes'])):[],
            ];
            $ov = trim($_POST['targeted_country_other'] ?? '');
            if($ov!==''){ $criteria['targeted_country'] = array_values(array_map(function($v) use($ov){ return $v==='Other'?$ov:$v; }, $criteria['targeted_country'])); }
            $ov = trim($_POST['departments_other'] ?? '');
            if($ov!==''){ $criteria['departments'] = array_values(array_map(function($v) use($ov){ return $v==='Other'?$ov:$v; }, $criteria['departments'])); }
            $ov = trim($_POST['seniority_levels_other'] ?? '');
            if($ov!==''){ $criteria['seniority_levels'] = array_values(array_map(function($v) use($ov){ return $v==='Other'?$ov:$v; }, $criteria['seniority_levels'])); }
            $ov = trim($_POST['industries_other'] ?? '');
            if($ov!==''){ $criteria['industries'] = array_values(array_map(function($v) use($ov){ return $v==='Other'?$ov:$v; }, $criteria['industries'])); }
            $ov = trim($_POST['employee_sizes_other'] ?? '');
            if($ov!==''){ $criteria['employee_sizes'] = array_values(array_map(function($v) use($ov){ return $v==='Other'?$ov:$v; }, $criteria['employee_sizes'])); }
            $ov = trim($_POST['revenue_sizes_other'] ?? '');
            if($ov!==''){ $criteria['revenue_sizes'] = array_values(array_map(function($v) use($ov){ return $v==='Other'?$ov:$v; }, $criteria['revenue_sizes'])); }
            $custom=[];
            if(!empty($_POST['custom_label'])&&is_array($_POST['custom_label'])){
                foreach($_POST['custom_label'] as $i=>$lbl){
                    $lbl=trim($lbl);
                    $val=trim($_POST['custom_value'][$i]??'');
                    $type=trim($_POST['custom_type'][$i]??'text');
                    $optsRaw=trim($_POST['custom_options'][$i]??'');
                    $opts = $optsRaw!=='' ? array_values(array_filter(array_map('trim', explode(',', $optsRaw)))) : [];
                    if($lbl!==''){ $custom[]=['label'=>$lbl,'type'=>$type,'value'=>$val,'options'=>$opts]; }
                }
            }
            $files=[
                'script_file'=>$_FILES['script_file']??[],
                'tal_file'=>(isset($_POST['tal_yes'])&&$_POST['tal_yes']==='Yes')?($_FILES['tal_file']??[]):['name'=>''],
                'suppression_file'=>(isset($_POST['suppression_yes'])&&$_POST['suppression_yes']==='Yes')?($_FILES['suppression_file']??[]):['name'=>''],
            ];
            $id=createCampaignWithDetails($basic,$criteria,$custom,$files);
            saveCampaignAdditionalFiles(
                $id,
                $_FILES['additional_file'] ?? [],
                $_POST['additional_title'] ?? [],
                $_POST['additional_type'] ?? [],
                $_POST['additional_description'] ?? [],
                (int)(getCurrentUser()['id'] ?? 0)
            );
            if (function_exists('appFlashSuccess')) {
                $nm = trim((string)($basic['name'] ?? ''));
                appFlashSuccess($nm !== '' ? ('Campaign created: ' . $nm) : 'Campaign created successfully.');
            }
            header('Location: list');
            exit;
        }catch(Throwable $e){
            $error=$e->getMessage();
        }
    }
}
define('INCLUDED',true);
?>
<?php $pageTitle = 'Create Campaign'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<div class="container-fluid px-0 campaign-workspace">
    <div class="campaign-page-header campaign-hero mb-3">
        <div>
            <div class="campaign-eyebrow mb-2"><i class="bi bi-plus-circle"></i>Campaign Module</div>
            <div class="campaign-page-title">Create Campaign</div>
            <div class="campaign-page-subtitle">Create campaign settings, targeting, files, and qualifier questions with the same CRM spacing and form layout used in newer modules.</div>
        </div>
        <div class="campaign-page-actions">
            <a href="list" class="btn btn-sm btn-light border"><i class="bi bi-arrow-left me-1"></i>Back</a>
        </div>
    </div>

    <div class="card border-0 shadow-sm campaign-panel campaign-form-shell">
        <div class="card-header bg-light fw-semibold">Campaign Setup</div>
        <div class="card-body">
            <?php if(!empty($error)): ?><div class="alert alert-danger border-0 shadow-sm"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
            <form method="post" enctype="multipart/form-data" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="create">
                <div class="col-md-6">
                    <label class="form-label small text-muted">Campaign Name <span class="text-danger" data-bs-toggle="tooltip" title="This field is required">*</span></label>
                    <input type="text" name="name" class="form-control form-control-sm" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">Client Code <span class="text-danger" data-bs-toggle="tooltip" title="This field is required">*</span></label>
                    <select name="client_id" class="form-select form-select-sm" required>
                        <?php if (isClientAdmin()): ?>
                            <?php 
                                $user = getCurrentUser();
                                $clientId = (int)($user['client_id'] ?? 0);
                                $stmt = $conn->prepare("SELECT id, client_code, name FROM clients WHERE id = ? LIMIT 1");
                                $stmt->bind_param('i', $clientId);
                                $stmt->execute();
                                $c = $stmt->get_result()->fetch_assoc();
                                $stmt->close();
                            ?>
                            <?php if ($c): ?>
                                <option value="<?php echo (int)$c['id']; ?>" selected><?php echo htmlspecialchars(($c['client_code'] ?? '').' – '.($c['name'] ?? '')); ?></option>
                            <?php endif; ?>
                        <?php else: ?>
                            <option value="">Select Client</option>
                            <?php foreach ($clients as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>">
                                    <?php echo htmlspecialchars(isAdmin() ? (($c['client_code'] ?? '')) : (string)($c['client_code'] ?? '')); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">Campaign Code</label>
                    <input type="text" id="codePreview" class="form-control form-control-sm" value="Select client to preview" disabled>
                    <div class="small text-muted mt-1">Format: TG-{CLIENTCODE}-{SEQ}</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <?php foreach (($formOpts['status'] ?? ['Draft','Active','Pause','Complete','Live']) as $st): ?>
                            <option value="<?php echo htmlspecialchars((string)$st); ?>"><?php echo htmlspecialchars((string)$st); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">Delivery Format</label>
                    <select name="delivery_format" class="form-select form-select-sm" id="deliveryFormatSel">
                        <option value=""> Please Select </option>
                        <?php foreach (($formOpts['delivery_format'] ?? ['Internal CRM','Client CRM','CSV','XLSX','Other']) as $opt): ?>
                            <option value="<?php echo htmlspecialchars((string)$opt); ?>"><?php echo htmlspecialchars((string)$opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" class="form-control form-control-sm mt-2" name="delivery_format_other" id="deliveryFormatOther" placeholder="Enter format" style="display:none;">
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">Start Date <span class="text-danger" data-bs-toggle="tooltip" title="This field is required">*</span></label>
                    <input type="date" name="start_date" class="form-control form-control-sm" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">End Date</label>
                    <input type="date" name="end_date" class="form-control form-control-sm">
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">Total Allocation</label>
                    <input type="number" name="total_leads" class="form-control form-control-sm" min="0">
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">Campaign Type</label>
                    <select name="campaign_type" class="form-select form-select-sm">
                        <option value=""> Please Select </option>
                        <?php foreach ([
                            'Email Marketing',
                            'Marketing Qualified Leads',
                            'HQL/Callback',
                            'BANT',
                            'Appointment Generation',
                        ] as $val): ?>
                            <option value="<?php echo htmlspecialchars($val); ?>"><?php echo htmlspecialchars($val); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">Pacing Type</label>
                    <select name="pacing_type" class="form-select form-select-sm">
                          <option value=""> Please Select </option>
                        <?php foreach (($formOpts['pacing_type'] ?? ['Daily','Weekly','Monthly']) as $opt): ?>
                            <option value="<?php echo htmlspecialchars((string)$opt); ?>"><?php echo htmlspecialchars((string)$opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">Pacing Count</label>
                    <input type="number" name="pacing_count" class="form-control form-control-sm" min="0">
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">CPC (Contacts Per Company)</label>
                    <input type="number" step="1" min="0" name="cpc" class="form-control form-control-sm" placeholder="CPC">
                </div>
                <?php if(isAdmin() || isSalesDirector() || isSalesManager()): ?>
                <div class="col-md-4">
                    <label class="form-label small text-muted">CPL</label>
                    <div class="input-group">
                        <select name="cpl_currency" class="form-select form-select-sm" style="max-width:120px">
                            <option value="USD">$ (USD)</option>
                            <option value="GBP">£ (GBP)</option>
                            <option value="EUR">€ (EUR)</option>
                            <option value="INR">₹ (INR)</option>
                        </select>
                        <input type="number" step="0.01" min="0" name="cpl" class="form-control form-control-sm" placeholder="Amount">
                    </div>
                </div>
                <?php endif; ?>
                <div class="col-12">
                    <hr class="my-2">
                    <div class="fw-semibold">Targeting</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">Geography</label>
                    <select name="targeted_country[]" class="form-select form-select-sm select2" multiple id="geoSel">
                          <option value=""> Please Select </option>
                          <?php foreach (($formOpts['targeted_country'] ?? []) as $opt): ?>
                              <option value="<?php echo htmlspecialchars((string)$opt); ?>"><?php echo htmlspecialchars((string)$opt); ?></option>
                          <?php endforeach; ?>
                    </select>
                    <input type="text" class="form-control form-control-sm mt-2" name="targeted_country_other" id="geoOther" placeholder="Enter geography" style="display:none;">
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">Job Title</label>
                    <input type="text" name="job_title" class="form-control form-control-sm">
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">Functions</label>
                    <select name="departments[]" class="form-select form-select-sm select2" multiple id="deptSel">
                          <option value=""> Please Select </option>
                          <?php foreach (($formOpts['departments'] ?? []) as $opt): ?>
                              <option value="<?php echo htmlspecialchars((string)$opt); ?>"><?php echo htmlspecialchars((string)$opt); ?></option>
                          <?php endforeach; ?>
                    </select>
                    <input type="text" class="form-control form-control-sm mt-2" name="departments_other" id="deptOther" placeholder="Enter function" style="display:none;">
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">Levels</label>
                    <select name="seniority_levels[]" class="form-select form-select-sm select2" multiple id="levelSel">
                          <?php foreach (($formOpts['seniority_levels'] ?? []) as $opt): ?>
                              <option value="<?php echo htmlspecialchars((string)$opt); ?>"><?php echo htmlspecialchars((string)$opt); ?></option>
                          <?php endforeach; ?>
                    </select>
                    <input type="text" class="form-control form-control-sm mt-2" name="seniority_levels_other" id="levelOther" placeholder="Enter level" style="display:none;">
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">Employee Size</label>
                    <select name="employee_sizes[]" class="form-select form-select-sm select2" multiple id="empSel">
                          <option value=""> Please Select </option>
                          <?php foreach (($formOpts['employee_sizes'] ?? []) as $opt): ?>
                              <option value="<?php echo htmlspecialchars((string)$opt); ?>"><?php echo htmlspecialchars((string)$opt); ?></option>
                          <?php endforeach; ?>
                    </select>
                    <input type="text" class="form-control form-control-sm mt-2" name="employee_sizes_other" id="empOther" placeholder="Enter employee size" style="display:none;">
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">Industry</label>
                    <select name="industries[]" class="form-select form-select-sm select2" multiple id="indSel">
                          <option value=""> Please Select </option>
                          <?php foreach (($formOpts['industries'] ?? []) as $opt): ?>
                              <option value="<?php echo htmlspecialchars((string)$opt); ?>"><?php echo htmlspecialchars((string)$opt); ?></option>
                          <?php endforeach; ?>
                    </select>
                    <input type="text" class="form-control form-control-sm mt-2" name="industries_other" id="indOther" placeholder="Enter industry" style="display:none;">
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">Revenue</label>
                    <select name="revenue_sizes[]" class="form-select form-select-sm select2" multiple id="revSel">
                          <option value=""> Please Select </option>
                          <?php foreach (($formOpts['revenue_sizes'] ?? []) as $opt): ?>
                              <option value="<?php echo htmlspecialchars((string)$opt); ?>"><?php echo htmlspecialchars((string)$opt); ?></option>
                          <?php endforeach; ?>
                    </select>
                    <input type="text" class="form-control form-control-sm mt-2" name="revenue_sizes_other" id="revOther" placeholder="Enter revenue" style="display:none;">
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">Script (PDF/DOC)</label>
                    <input type="file" name="script_file" class="form-control form-control-sm" accept=".pdf,.doc,.docx">
                </div>
                
                <div class="col-md-4">
                    <label class="form-label small text-muted">Suppression List</label>
                    <select name="suppression_yes" class="form-select form-select-sm" id="suppression_yes">
                        <option>No</option>
                        <option>Yes</option>
                    </select>
                </div>
                <div class="col-md-4" id="suppression_file_wrap" style="display:none;">
                    <label class="form-label small text-muted">Suppression File</label>
                    <input type="file" name="suppression_file" class="form-control form-control-sm" accept=".csv,.xls,.xlsx">
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">ABM/TAL List</label>
                    <select name="tal_yes" class="form-select form-select-sm" id="tal_yes">
                        <option>No</option>
                        <option>Yes</option>
                    </select>
                </div>
                <div class="col-md-4" id="tal_file_wrap" style="display:none;">
                    <label class="form-label small text-muted">TAL File</label>
                    <input type="file" name="tal_file" class="form-control form-control-sm" accept=".csv,.xls,.xlsx">
                </div>
                <div class="col-12">
                    <hr class="my-2">
                    <div class="fw-semibold">Additional Files</div>
                </div>
                <div class="col-12" id="additionalFiles">
                    <div class="row g-2 align-items-end mb-2 additional-file-row">
                        <div class="col-md-3">
                            <label class="form-label small text-muted">File Title</label>
                            <input type="text" class="form-control form-control-sm" name="additional_title[]" placeholder="Title">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small text-muted">File Type</label>
                            <input type="text" class="form-control form-control-sm" name="additional_type[]" placeholder="Tag (optional)">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Description</label>
                            <input type="text" class="form-control form-control-sm" name="additional_description[]" placeholder="Description (optional)">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">File Upload</label>
                            <input type="file" class="form-control form-control-sm" name="additional_file[]" accept=".pdf,.doc,.docx,.txt,.csv,.xls,.xlsx,.png,.jpg,.jpeg">
                        </div>
                        <div class="col-md-1 d-grid">
                            <button type="button" class="btn btn-outline-danger btn-sm remove-additional-file" style="display:none;"><i class="bi bi-x"></i></button>
                        </div>
                    </div>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="addAdditionalFile"><i class="bi bi-plus"></i> Add File</button>
                </div>
                <div class="col-md-12">
                    <label class="form-label small text-muted">Campaign Custom Questions / Qualifier Questions</label>
                    <div id="customFields" class="mb-2"></div>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="addField"><i class="bi bi-plus"></i> Add Question</button>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Submit</button>
                    <a href="list" class="btn btn-light border">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
document.querySelectorAll('.select2').forEach(function(el){$(el).select2({width:'100%'});});
const supSel=document.getElementById('suppression_yes');
const supWrap=document.getElementById('suppression_file_wrap');
const talSel=document.getElementById('tal_yes');
const talWrap=document.getElementById('tal_file_wrap');
function toggleWrap(sel,wrap){wrap.style.display=(sel.value==='Yes')?'block':'none';}
supSel.addEventListener('change',()=>toggleWrap(supSel,supWrap));
talSel.addEventListener('change',()=>toggleWrap(talSel,talWrap));
toggleWrap(supSel,supWrap);toggleWrap(talSel,talWrap);
document.getElementById('addAdditionalFile').addEventListener('click', function(){
  const wrap = document.getElementById('additionalFiles');
  const first = wrap.querySelector('.additional-file-row');
  const clone = first.cloneNode(true);
  clone.querySelectorAll('input').forEach(i => { if (i.type !== 'file') i.value=''; else i.value=''; });
  const btn = clone.querySelector('.remove-additional-file');
  if (btn) btn.style.display = 'block';
  wrap.insertBefore(clone, document.getElementById('addAdditionalFile'));
});
document.getElementById('additionalFiles').addEventListener('click', function(e){
  const btn = e.target.closest('.remove-additional-file');
  if (!btn) return;
  const row = btn.closest('.additional-file-row');
  if (row) row.remove();
});
document.getElementById('addField').addEventListener('click',function(){
  const row = document.createElement('div');
  row.className='row g-2 align-items-center mb-2';
  row.innerHTML = `<div class="col-md-4"><input type="text" class="form-control" name="custom_label[]" placeholder="Question (e.g. Current CRM?)"></div>
                   <div class="col-md-3">
                       <select name="custom_type[]" class="form-select">
                           <option value="text">Text / Input</option>
                           <option value="dropdown">Dropdown (Select One)</option>
                           <option value="choice">Radio (Select One)</option>
                           <option value="multichoice">Checkbox (Select Multiple)</option>
                       </select>
                   </div>
                   <div class="col-md-3"><input type="text" class="form-control" name="custom_value[]" placeholder="Default Answer (Optional)"></div>
                   <div class="col-md-2"><input type="text" class="form-control" name="custom_options[]" placeholder="Options (comma separated)"></div>
                   <div class="col-md-12 text-end"><button type="button" class="btn btn-outline-danger btn-sm">Remove Question</button></div>`;
  row.querySelector('button').addEventListener('click',()=>row.remove());
  document.getElementById('customFields').appendChild(row);
});
function handleOther(selId, inputId){
  const sel=document.getElementById(selId); const inp=document.getElementById(inputId);
  function update(){ const vals=$(sel).val()||[]; inp.style.display = Array.isArray(vals) && vals.includes('Other') ? 'block' : 'none'; }
  $(sel).on('change',update); update();
}
handleOther('geoSel','geoOther');
handleOther('deptSel','deptOther');
handleOther('levelSel','levelOther');
handleOther('empSel','empOther');
handleOther('indSel','indOther');
handleOther('revSel','revOther');
</script>
<script>
function handleOtherSimple(selId, inputId){
  const sel=document.getElementById(selId); const inp=document.getElementById(inputId);
  function update(){ inp.style.display = sel.value==='Other' ? 'block' : 'none'; }
  $(sel).on('change',update); update();
}
handleOtherSimple('deliveryFormatSel','deliveryFormatOther');
function genCode(){
  const sel = document.querySelector('select[name="client_id"]');
  const inp = document.getElementById('codePreview');
  if (!sel || !inp) return;
  const cid = sel.value;
  if (!cid) { inp.value = 'Select client to preview'; return; }
  inp.value = 'Generating...';
  fetch('create?action=code_preview&client_id=' + encodeURIComponent(cid), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json())
    .then(d => { inp.value = (d && d.ok && d.code) ? d.code : 'Auto-generated'; })
    .catch(() => { inp.value = 'Auto-generated'; });
}
genCode();
document.querySelector('select[name="client_id"]')?.addEventListener('change', genCode);
</script>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
