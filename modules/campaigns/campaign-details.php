<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermissionOrRole('campaigns.details.page', []);
ensureCsrfToken();
ensureDatabaseSchema();
ensureCampaignDetailsColumns();
$id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['campaign_id'] ?? 0);
$isAjaxRequest = (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest');
$wantsJson = $isAjaxRequest || (strtolower((string)($_GET['format'] ?? '')) === 'json');
if($id<=0){
    if ($wantsJson) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'Invalid ID']); }
    else { http_response_code(400); echo 'Invalid ID'; }
    exit;
}
$row = getCampaignDetailsById($id);
if(!$row){
    if ($wantsJson) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'Not found']); }
    else { http_response_code(404); echo 'Not found'; }
    exit;
}

$canManageCampaigns = isAdmin() || isSalesDirector() || isSalesManager() || hasRole(['director','manager_director','operations_director']);
if (!$canManageCampaigns) {
    if (isSDR()) {
        $assigned = getAssignedCampaignIdsForUser((int)(getCurrentUser()['id'] ?? 0));
        if (!isset($assigned[(int)$row['campaign_id']])) {
            if ($wantsJson) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'Not allowed']); }
            else { http_response_code(403); echo 'Not allowed'; }
            exit;
        }
    } elseif (isQA()) {
        $u = getCurrentUser();
        $visible = getQaVisibleCampaignIdsForUser((int)($u['id'] ?? 0), (string)($u['role'] ?? ''));
        if ($visible !== null && !isset($visible[(int)$row['campaign_id']])) {
            if ($wantsJson) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'Not allowed']); }
            else { http_response_code(403); echo 'Not allowed'; }
            exit;
        }
    } elseif (hasRole(['agent','operations_agent','operations_manager','operations_director','director','manager_director','email_marketing_director','email_marketing_manager','email_marketing_agent','email_marketing_executive','form_filler'])) {
    } else {
        if (($row['status'] ?? '') !== 'Live') {
            if ($wantsJson) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'Not allowed']); }
            else { http_response_code(403); echo 'Not allowed'; }
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($wantsJson) header('Content-Type: application/json');
    $csrf = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        if ($wantsJson) { echo json_encode(['ok' => false, 'error' => 'Invalid token']); exit; }
        http_response_code(403); echo 'Invalid token'; exit;
    }
    $campaignId = (int)($row['campaign_id'] ?? $id);
    $u = getCurrentUser();
    $uid = (int)($u['id'] ?? 0);
    $canManageNotes = isAdmin() || hasRole(['director','manager_director','sales_director','sales_manager','operations_director']);
    $notifyAll = function(string $type, string $title, string $message, string $dedupKey) use ($campaignId, $uid): void {
        $link = appBasePath() . '/modules/campaigns/view?id=' . (int)$campaignId;
        notifyCampaignUsers((int)$campaignId, $type, $title, $message, $link, [
            'importance' => 'high',
            'show_toast' => true,
            'dedup_key' => $dedupKey,
            'dedup_window_min' => 10,
            'triggered_by_user_id' => (int)$uid,
            'visibility_scope' => 'campaign',
        ]);
    };
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'add_note') {
        if (!$canManageNotes) { echo json_encode(['ok'=>false,'error'=>'Not allowed']); exit; }
        $txt = trim((string)($_POST['note_text'] ?? ''));
        if ($txt === '') { echo json_encode(['ok'=>false,'error'=>'Enter note']); exit; }
        $att = null;
        if (isset($_FILES['attachment']) && (int)($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $att = saveCampaignNoteAttachment($_FILES['attachment'], $campaignId);
        }
        $ap = $att['path'] ?? null;
        $an = $att['name'] ?? null;
        $note = addCampaignNote($campaignId, $uid, $txt, $ap, $an);
        if (!$note) { echo json_encode(['ok'=>false,'error'=>'Failed']); exit; }
        $who = trim((string)($u['full_name'] ?? $u['username'] ?? $u['name'] ?? ''));
        $campName = trim((string)($row['campaign_name'] ?? $row['name'] ?? 'Campaign'));
        $snippet = trim(preg_replace('/\s+/', ' ', $txt));
        if (strlen($snippet) > 140) $snippet = substr($snippet, 0, 137) . '...';
        $notifyAll('campaign.note_added', 'Campaign note added', $campName . ($who !== '' ? (' · By ' . $who) : '') . ($snippet !== '' ? (' · ' . $snippet) : ''), 'camp_note_add:' . $campaignId . ':' . (int)($note['id'] ?? 0));
        if ($wantsJson) { echo json_encode(['ok'=>true,'note'=>$note]); exit; }
        header('Location: view?id='.(int)$campaignId.'#notes'); exit;
    } elseif ($action === 'edit_note') {
        if (!$canManageNotes) { echo json_encode(['ok'=>false,'error'=>'Not allowed']); exit; }
        $nid = (int)($_POST['note_id'] ?? 0);
        $txt = isset($_POST['note_text']) ? trim((string)$_POST['note_text']) : '';
        if ($nid<=0) { echo json_encode(['ok'=>false,'error'=>'Invalid']); exit; }
        if ($txt==='') {
            $cur = getCampaignNoteById($nid);
            $txt = (string)($cur['note_text'] ?? '');
        }
        $att = null;
        $remove = false;
        if (isset($_POST['remove_attachment']) && $_POST['remove_attachment'] === '1') {
            $remove = true;
        } elseif (isset($_FILES['attachment']) && (int)($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $att = saveCampaignNoteAttachment($_FILES['attachment'], $campaignId);
        }
        $ap = $att['path'] ?? null;
        $an = $att['name'] ?? null;
        $ok = updateCampaignNote($nid, $txt, $uid, $ap, $an, $remove);
        if (!$ok) { echo json_encode(['ok'=>false,'error'=>'Failed']); exit; }
        $note = getCampaignNoteById($nid);
        $who = trim((string)($u['full_name'] ?? $u['username'] ?? $u['name'] ?? ''));
        $campName = trim((string)($row['campaign_name'] ?? $row['name'] ?? 'Campaign'));
        $notifyAll('campaign.note_updated', 'Campaign note updated', $campName . ($who !== '' ? (' · By ' . $who) : ''), 'camp_note_edit:' . $campaignId . ':' . $nid);
        if ($wantsJson) { echo json_encode(['ok'=>true,'note'=>$note]); exit; }
        header('Location: view?id='.(int)$campaignId.'#notes'); exit;
    } elseif ($action === 'delete_note') {
        if (!$canManageNotes) { echo json_encode(['ok'=>false,'error'=>'Not allowed']); exit; }
        $nid = (int)($_POST['note_id'] ?? 0);
        if ($nid<=0) { echo json_encode(['ok'=>false,'error'=>'Invalid']); exit; }
        $existing = getCampaignNoteById($nid);
        $ok = deleteCampaignNote($nid);
        if ($ok && $existing && !empty($existing['attachment_path'])) {
            $base = realpath(__DIR__.'/../../');
            if ($base === false) { $base = __DIR__.'/../../'; }
            $rel = str_replace(['\\'], '/', (string)$existing['attachment_path']);
            $abs = $base.'/'.ltrim($rel, '/');
            if (is_file($abs)) { @unlink($abs); }
        }
        if ($ok) {
            $who = trim((string)($u['full_name'] ?? $u['username'] ?? $u['name'] ?? ''));
            $campName = trim((string)($row['campaign_name'] ?? $row['name'] ?? 'Campaign'));
            $notifyAll('campaign.note_deleted', 'Campaign note deleted', $campName . ($who !== '' ? (' · By ' . $who) : ''), 'camp_note_del:' . $campaignId . ':' . $nid);
        }
        if ($wantsJson) { echo json_encode(['ok'=>$ok]); exit; }
        header('Location: view?id='.(int)$campaignId.'#notes'); exit;
    } elseif ($action === 'list_notes') {
        $notes = getCampaignNotes($campaignId);
        echo json_encode(['ok'=>true,'notes'=>$notes]); exit;
    }
    if ($wantsJson) { echo json_encode(['ok'=>false,'error'=>'Unknown']); exit; }
    header('Location: view?id='.(int)$campaignId); exit;
}

if ($wantsJson) {
    header('Content-Type: application/json');
    $canSeeCpl = isAdmin() || hasRole(['director','manager_director','sales_director']);
    if (!$canSeeCpl) {
        unset($row['cpl'], $row['cpl_currency']);
    }
    echo json_encode(['ok'=>true,'data'=>$row]);
    exit;
}

$campaignId = (int)($row['campaign_id'] ?? $id);
$conn = getDbConnection();

$notes = getCampaignNotes($campaignId);
$canManageNotes = isAdmin() || hasRole(['director','manager_director','sales_director','sales_manager','operations_director']);
$canSeeCpl = isAdmin() || hasRole(['director','manager_director','sales_director']);

$stats = getCampaignLeadTableStats($campaignId, null, null, true);
$allocation = (int)($row['total_leads'] ?? 0);
$delivered = (int)($stats['client_delivered'] ?? 0);
$pendingAlloc = max(0, $allocation - $delivered);
$pct = $allocation > 0 ? round((min($delivered, $allocation) / $allocation) * 100) : 0;

$additional = [];
$rs = $conn->query("SELECT file_title AS file_name, file_path, created_at AS uploaded_at FROM campaign_additional_files WHERE campaign_id = ".(int)$campaignId." ORDER BY created_at DESC");
if ($rs) $additional = $rs->fetch_all(MYSQLI_ASSOC) ?: [];

$form = getFormForCampaign($campaignId);
$schema = $form ? (json_decode((string)($form['schema_json'] ?? ''), true) ?: []) : [];
$fields = (array)($schema['fields'] ?? []);

$clientName = '';
$clientCode = (string)($row['client_code'] ?? '');
$clientId = (int)($row['client_id'] ?? 0);
if ($clientId > 0) {
    $stmt = $conn->prepare("SELECT client_code, name FROM clients WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $clientId);
        $stmt->execute();
        $c = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        if ($clientCode === '' && !empty($c['client_code'])) $clientCode = (string)$c['client_code'];
        $clientName = (string)($c['name'] ?? '');
    }
}

$statusUpdatedByName = '';
$subid = (int)($row['status_updated_by'] ?? 0);
if ($subid > 0) {
    $stmt = $conn->prepare("SELECT full_name, username FROM users WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $subid);
        $stmt->execute();
        $u = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($u) $statusUpdatedByName = $u['full_name'] ?: $u['username'];
    }
}

$pageTitle = 'Campaign Details';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<style>
  .dfb-page-header { background: linear-gradient(180deg, rgba(37,99,235,0.10), rgba(37,99,235,0.00)); border-radius: 16px; }
  .dfb-kv { display:flex; flex-direction:column; gap:2px; }
  .dfb-kv .k { font-size: .75rem; color: #6b7280; }
  .dfb-kv .v { font-weight: 600; color: #111827; }
  .dfb-kv .v .text-muted { font-weight: 500; }
  .dfb-pill { background: #fff; border: 1px solid rgba(17,24,39,0.12); border-radius: 999px; padding: .25rem .6rem; font-size: .75rem; color: #111827; white-space: nowrap; }
  .dfb-sticky-nav { position: sticky; top: 0; z-index: 1020; background: rgba(248,250,252,0.92); backdrop-filter: blur(8px); border-bottom: 1px solid rgba(17,24,39,0.08); }
  .dfb-sticky-nav .nav-pills .nav-link { padding: .35rem .65rem; font-size: .85rem; border-radius: 999px; }
  .dfb-card-title { letter-spacing: .02em; text-transform: uppercase; font-size: .75rem; font-weight: 700; color:#374151; }
  .dfb-muted { color:#6b7280; }
  .dfb-compact-table td, .dfb-compact-table th { padding-top: .55rem; padding-bottom: .55rem; }
  .dfb-empty { border: 1px dashed rgba(17,24,39,0.18); border-radius: 12px; padding: 14px; background: rgba(249,250,251,0.7); }
</style>

<div class="container-fluid px-0 campaign-workspace">
  <?php
    $s = (string)($row['status'] ?? '');
    $m = ['Live'=>'bg-success-subtle text-success','Active'=>'bg-primary-subtle text-primary','Pause'=>'bg-warning-subtle text-warning','Draft'=>'bg-secondary-subtle text-secondary','Complete'=>'bg-dark-subtle text-dark'];
    $cls = $m[$s] ?? 'bg-secondary-subtle text-secondary';
    $campTitle = (string)($row['campaign_name'] ?? $row['name'] ?? 'Campaign');
  ?>

  <div class="dfb-page-header campaign-hero p-3 p-md-4 border-0 mb-3">
    <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
      <div class="flex-grow-1">
        <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
          <h3 class="mb-0"><?php echo htmlspecialchars($campTitle); ?></h3>
          <div class="d-flex flex-column align-items-start">
            <span class="badge border <?php echo htmlspecialchars($cls); ?>"><?php echo htmlspecialchars($s !== '' ? $s : '—'); ?></span>
            <?php if ($s === 'Pause' && !empty($statusUpdatedByName)): ?>
              <span class="x-small text-muted mt-1" style="font-size: 0.65rem; line-height: 1;">by <?php echo htmlspecialchars($statusUpdatedByName); ?></span>
            <?php endif; ?>
          </div>
          <?php if (trim((string)($row['code'] ?? '')) !== ''): ?>
            <span class="dfb-pill"><?php echo htmlspecialchars((string)($row['code'] ?? '')); ?></span>
          <?php endif; ?>
          <?php if (trim($clientCode) !== ''): ?>
            <span class="dfb-pill"><?php echo htmlspecialchars(trim($clientCode)); ?></span>
          <?php endif; ?>
        </div>

        <div class="row g-2 g-md-3">
          <div class="col-6 col-lg-2">
            <div class="dfb-kv">
              <div class="k">Allocation</div>
              <div class="v"><?php echo (int)$allocation; ?></div>
            </div>
          </div>
          <div class="col-6 col-lg-2">
            <div class="dfb-kv">
              <div class="k">Delivered</div>
              <div class="v"><?php echo (int)$delivered; ?></div>
            </div>
          </div>
          <div class="col-6 col-lg-2">
            <div class="dfb-kv">
              <div class="k">Remaining</div>
              <div class="v"><?php echo (int)$pendingAlloc; ?></div>
            </div>
          </div>
          <div class="col-6 col-lg-2">
            <div class="dfb-kv">
              <div class="k">Pending QA</div>
              <div class="v"><?php echo (int)($stats['pending_qa'] ?? 0); ?></div>
            </div>
          </div>
          <div class="col-6 col-lg-2">
            <div class="dfb-kv">
              <div class="k">Qualified</div>
              <div class="v text-success"><?php echo (int)($stats['approved'] ?? 0); ?></div>
            </div>
          </div>
          <div class="col-6 col-lg-2">
            <div class="dfb-kv">
              <div class="k">Disqualified</div>
              <div class="v text-danger"><?php echo (int)($stats['rejected'] ?? 0); ?></div>
            </div>
          </div>
        </div>
      </div>

      <div class="d-flex gap-2 flex-wrap align-items-start">
        <a class="btn btn-outline-secondary btn-sm" href="list"><i class="bi bi-arrow-left me-1"></i>Back</a>
        <?php if ($canManageCampaigns): ?>
          <a class="btn btn-outline-success btn-sm" href="leads?campaign_id=<?php echo (int)$campaignId; ?>"><i class="bi bi-list-ul me-1"></i>Leads</a>
          <a class="btn btn-outline-primary btn-sm" href="edit?id=<?php echo (int)$campaignId; ?>"><i class="bi bi-pencil me-1"></i>Edit</a>
        <?php endif; ?>
        <a class="btn btn-primary btn-sm" href="../leads/entry?campaign_id=<?php echo (int)$campaignId; ?>"><i class="bi bi-plus-lg me-1"></i>Submit Lead</a>
      </div>
    </div>

    <div class="mt-3">
      <div class="progress" style="height: 10px;">
        <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo (int)$pct; ?>%;" aria-valuenow="<?php echo (int)$pct; ?>" aria-valuemin="0" aria-valuemax="100"></div>
      </div>
      <div class="d-flex justify-content-between dfb-muted small mt-2">
        <span><?php echo (int)$delivered; ?> / <?php echo (int)$allocation; ?> delivered</span>
        <span><?php echo (int)$pct; ?>%</span>
      </div>
    </div>
  </div>

  <div class="dfb-sticky-nav mb-3">
    <div class="py-2">
      <ul class="nav nav-pills gap-2 flex-nowrap overflow-auto" id="campNav">
        <li class="nav-item"><a class="nav-link active" href="#overview">Overview</a></li>
        <li class="nav-item"><a class="nav-link" href="#targeting">Targeting</a></li>
        <li class="nav-item"><a class="nav-link" href="#questions">Questions</a></li>
        <li class="nav-item"><a class="nav-link" href="#files">Files</a></li>
        <li class="nav-item"><a class="nav-link" href="#form">Form</a></li>
        <li class="nav-item"><a class="nav-link" href="#notes">Notes</a></li>
      </ul>
    </div>
  </div>

  <?php
    $v = function($val, string $dash = '—'): string {
      $s = trim((string)$val);
      return $s !== '' ? htmlspecialchars($s) : '<span class="text-muted">'.$dash.'</span>';
    };
    $d = function($val): string {
      $s = trim((string)$val);
      if ($s === '') return '<span class="text-muted">—</span>';
      $ts = strtotime($s);
      if (!$ts) return htmlspecialchars($s);
      return htmlspecialchars(date('Y-m-d', $ts));
    };
    $chips = function($arr): string {
      if (!is_array($arr)) return '<span class="text-muted">—</span>';
      $arr = array_values(array_filter(array_map(fn($v) => trim((string)$v), $arr), fn($v) => $v !== ''));
      if (empty($arr)) return '<span class="text-muted">—</span>';
      $html = '<div class="d-flex flex-wrap gap-1">';
      foreach ($arr as $v) {
        $html .= '<span class="badge rounded-pill bg-light text-dark border">'.htmlspecialchars($v).'</span>';
      }
      $html .= '</div>';
      return $html;
    };
    $paths = function(string $path) use ($v): string {
      $p = trim($path);
      if ($p === '') return '<span class="text-muted">—</span>';
      $href = '';
      if (preg_match('/^(uploads\/|assets\/)/i', $p)) $href = '../../' . ltrim($p, '/');
      if ($href !== '') {
        return '<a class="text-decoration-none" href="'.htmlspecialchars($href).'" target="_blank" rel="noopener" title="'.htmlspecialchars($p).'"><span class="d-inline-block text-truncate" style="max-width: 320px;">'.htmlspecialchars($p).'</span></a>';
      }
      return '<span class="d-inline-block text-truncate text-muted" style="max-width: 320px;" title="'.htmlspecialchars($p).'">'.htmlspecialchars($p).'</span>';
    };
    $campaignIdForFiles = (int)($row['campaign_id'] ?? $id);
    $downloadBtn = function(string $path, string $text = 'Download') use ($campaignIdForFiles): string {
      $p = trim($path);
      if ($p === '') return '<span class="text-muted">—</span>';
      if (!preg_match('/^(uploads\/|assets\/)/i', $p)) return '<span class="text-muted">—</span>';
      $name = basename(str_replace('\\', '/', $p));
      if (!campaignUploadExistsOnDisk($p)) {
        return '<span class="badge bg-warning-subtle text-warning border" title="File missing on server — re-upload from campaign edit.">Missing file</span>'
          .'<span class="text-muted small text-truncate ms-1" style="max-width: 180px;" title="'.htmlspecialchars($name).'">'.htmlspecialchars($name).'</span>';
      }
      $href = campaignFileDownloadUrl($campaignIdForFiles, $p);
      return '<div class="d-inline-flex align-items-center gap-2">'
        .'<a class="btn btn-sm btn-light border" href="'.htmlspecialchars($href).'"><i class="bi bi-download me-1"></i>'.htmlspecialchars($text).'</a>'
        .'<span class="text-muted small text-truncate" style="max-width: 220px;" title="'.htmlspecialchars($name).'">'.htmlspecialchars($name).'</span>'
        .'</div>';
    };
  ?>

  <div class="row g-3 mb-3" id="overview">
    <div class="col-lg-8">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-light d-flex align-items-center justify-content-between">
          <span class="dfb-card-title"><i class="bi bi-info-circle me-2"></i>Overview</span>
          <span class="text-muted small">Settings and schedule</span>
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-4">
              <div class="text-muted small">Delivery Format</div>
              <div class="fw-semibold"><?php echo $v($row['delivery_format'] ?? null); ?></div>
            </div>
            <div class="col-md-4">
              <div class="text-muted small">Campaign Type</div>
              <div class="fw-semibold"><?php echo $v($row['campaign_type'] ?? null); ?></div>
            </div>
            <div class="col-md-4">
              <div class="text-muted small">Allocation</div>
              <div class="fw-semibold"><?php echo (int)$allocation; ?> leads</div>
            </div>

            <div class="col-md-4">
              <div class="text-muted small">Start Date</div>
              <div class="fw-semibold"><?php echo $d($row['start_date'] ?? null); ?></div>
            </div>
            <div class="col-md-4">
              <div class="text-muted small">End Date</div>
              <div class="fw-semibold"><?php echo $d($row['end_date'] ?? null); ?></div>
            </div>
            <div class="col-md-4">
              <div class="text-muted small">CPC</div>
              <div class="fw-semibold"><?php echo $v($row['cpc'] ?? null); ?></div>
            </div>
            <?php if ($canSeeCpl): ?>
              <div class="col-md-4">
                <div class="text-muted small">CPL</div>
                <div class="fw-semibold">
                  <?php
                    $cplVal = trim((string)($row['cpl'] ?? ''));
                    $cplCur = trim((string)($row['cpl_currency'] ?? ''));
                    echo $cplVal !== '' ? htmlspecialchars(($cplCur !== '' ? ($cplCur . ' ') : '') . $cplVal) : '<span class="text-muted">—</span>';
                  ?>
                </div>
              </div>
            <?php endif; ?>

            <div class="col-md-4">
              <div class="text-muted small">Pacing Type</div>
              <div class="fw-semibold"><?php echo $v($row['pacing_type'] ?? null); ?></div>
            </div>
            <div class="col-md-4">
              <div class="text-muted small">Pacing Count</div>
              <div class="fw-semibold"><?php echo $v($row['pacing_count'] ?? null); ?></div>
            </div>
            <div class="col-md-4">
              <div class="text-muted small">Pending Allocation</div>
              <div class="fw-semibold"><?php echo (int)$pendingAlloc; ?></div>
            </div>

            <?php $instr = trim((string)($row['instruction'] ?? $row['notes'] ?? '')); ?>
            <?php if ($instr !== ''): ?>
              <div class="col-12">
                <div class="accordion" id="campaignInstruction">
                  <div class="accordion-item">
                    <h2 class="accordion-header" id="headingInstr">
                      <button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse" data-bs-target="#collapseInstr" aria-expanded="false" aria-controls="collapseInstr">
                        <span class="fw-semibold"><i class="bi bi-card-text me-1"></i>Instructions</span>
                        <span class="ms-2 text-muted small text-truncate" style="max-width: 55vw;"><?php echo htmlspecialchars(substr($instr, 0, 120)); ?><?php echo strlen($instr) > 120 ? '…' : ''; ?></span>
                      </button>
                    </h2>
                    <div id="collapseInstr" class="accordion-collapse collapse" aria-labelledby="headingInstr" data-bs-parent="#campaignInstruction">
                      <div class="accordion-body py-2">
                        <div class="small"><?php echo nl2br(htmlspecialchars($instr)); ?></div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-light d-flex align-items-center justify-content-between">
          <span class="dfb-card-title"><i class="bi bi-graph-up-arrow me-2"></i>Progress</span>
          <span class="text-muted small"><?php echo (int)$pct; ?>%</span>
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-6">
              <div class="text-muted small">Delivered</div>
              <div class="h5 mb-0"><?php echo (int)$delivered; ?></div>
            </div>
            <div class="col-6">
              <div class="text-muted small">Pending QA</div>
              <div class="h5 mb-0"><?php echo (int)($stats['pending_qa'] ?? 0); ?></div>
            </div>
            <div class="col-6">
              <div class="text-muted small">Qualified</div>
              <div class="h6 mb-0 text-success"><?php echo (int)($stats['approved'] ?? 0); ?></div>
            </div>
            <div class="col-6">
              <div class="text-muted small">Disqualified</div>
              <div class="h6 mb-0 text-danger"><?php echo (int)($stats['rejected'] ?? 0); ?></div>
            </div>
            <div class="col-12">
              <div class="progress" style="height: 14px;">
                <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo (int)$pct; ?>%;" aria-valuenow="<?php echo (int)$pct; ?>" aria-valuemin="0" aria-valuemax="100"></div>
              </div>
              <div class="d-flex justify-content-between text-muted small mt-2">
                <span><?php echo (int)$delivered; ?> / <?php echo (int)$allocation; ?> delivered</span>
                <span><?php echo (int)$pendingAlloc; ?> remaining</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3" id="targeting">
    <div class="col-12">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-light d-flex align-items-center justify-content-between">
          <span class="dfb-card-title"><i class="bi bi-bullseye me-2"></i>Targeting</span>
          <span class="text-muted small">Audience filters and criteria</span>
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-lg-6">
              <div class="text-muted small mb-1">Geography</div>
              <div class="border rounded-3 p-2 bg-light-subtle overflow-auto" style="max-height: 120px;"><?php echo $chips($row['targeted_country'] ?? []); ?></div>
            </div>
            <div class="col-lg-6">
              <div class="text-muted small mb-1">Job Title</div>
              <div class="fw-semibold"><?php echo $v($row['job_title'] ?? null); ?></div>
            </div>
            <div class="col-lg-6">
              <div class="text-muted small mb-1">Functions</div>
              <div class="border rounded-3 p-2 bg-light-subtle overflow-auto" style="max-height: 120px;"><?php echo $chips($row['departments'] ?? []); ?></div>
            </div>
            <div class="col-lg-6">
              <div class="text-muted small mb-1">Levels</div>
              <div class="border rounded-3 p-2 bg-light-subtle overflow-auto" style="max-height: 120px;"><?php echo $chips($row['seniority_levels'] ?? []); ?></div>
            </div>
            <div class="col-lg-6">
              <div class="text-muted small mb-1">Industries</div>
              <div class="border rounded-3 p-2 bg-light-subtle overflow-auto" style="max-height: 120px;"><?php echo $chips($row['industries'] ?? []); ?></div>
            </div>
            <div class="col-lg-3">
              <div class="text-muted small mb-1">Employee Size</div>
              <div class="border rounded-3 p-2 bg-light-subtle overflow-auto" style="max-height: 120px;"><?php echo $chips($row['employee_sizes'] ?? []); ?></div>
            </div>
            <div class="col-lg-3">
              <div class="text-muted small mb-1">Revenue</div>
              <div class="border rounded-3 p-2 bg-light-subtle overflow-auto" style="max-height: 120px;"><?php echo $chips($row['revenue_sizes'] ?? []); ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3" id="questions">
    <div class="col-12">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-light d-flex align-items-center justify-content-between">
          <span class="dfb-card-title"><i class="bi bi-question-circle me-2"></i>Qualifier Questions</span>
          <span class="text-muted small"><?php echo is_array($row['custom_fields_json'] ?? null) ? (int)count($row['custom_fields_json']) : 0; ?> items</span>
        </div>
        <div class="card-body">
          <?php $cfs = is_array($row['custom_fields_json'] ?? null) ? $row['custom_fields_json'] : []; ?>
          <?php if (empty($cfs)): ?>
            <div class="dfb-empty d-flex align-items-start gap-3">
              <div class="text-primary"><i class="bi bi-info-circle" style="font-size: 1.25rem;"></i></div>
              <div>
                <div class="fw-semibold">No qualifier questions</div>
                <div class="text-muted small">Add custom questions if you want to enforce extra qualification criteria during lead capture.</div>
              </div>
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm table-bordered mb-0 dfb-compact-table align-middle">
                <thead class="table-light">
                  <tr>
                    <th style="width: 40%;">Question</th>
                    <th style="width: 15%;">Type</th>
                    <th style="width: 20%;">Default/Answer</th>
                    <th style="width: 25%;">Options</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($cfs as $cf): ?>
                    <?php 
                      $lbl = (string)($cf['label'] ?? ''); 
                      $val = (string)($cf['value'] ?? ''); 
                      $type = (string)($cf['type'] ?? 'text');
                      $opts = isset($cf['options']) && is_array($cf['options']) ? implode(', ', $cf['options']) : '';
                      
                      $typeLabel = [
                        'text' => 'Text / Input',
                        'dropdown' => 'Dropdown',
                        'choice' => 'Radio',
                        'multichoice' => 'Checkbox'
                      ][$type] ?? $type;
                    ?>
                    <tr>
                      <td class="fw-semibold"><?php echo htmlspecialchars($lbl); ?></td>
                      <td><span class="badge bg-info-subtle text-info border"><?php echo htmlspecialchars($typeLabel); ?></span></td>
                      <td class="text-muted"><?php echo $val !== '' ? nl2br(htmlspecialchars($val)) : '—'; ?></td>
                      <td class="small text-muted"><?php echo $opts !== '' ? htmlspecialchars($opts) : '—'; ?></td>
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

  <div class="row g-3" id="files">
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-light d-flex align-items-center justify-content-between">
          <span class="dfb-card-title"><i class="bi bi-folder2-open me-2"></i>Campaign Files</span>
          <span class="text-muted small"><?php echo (int)(count($additional) + 3); ?> items</span>
        </div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle dfb-compact-table">
              <thead class="table-light">
                <tr>
                  <th>Name</th>
                  <th>File</th>
                  <th class="text-nowrap">Uploaded</th>
                </tr>
              </thead>
              <tbody>
                <?php
                  $baseFiles = [
                    ['Script', (string)($row['script_path'] ?? '')],
                    ['TAL', (string)($row['tal_path'] ?? '')],
                    ['Suppression', (string)($row['suppression_path'] ?? '')],
                  ];
                ?>
                <?php foreach ($baseFiles as $bf): ?>
                  <tr>
                    <td class="fw-semibold"><?php echo htmlspecialchars($bf[0]); ?></td>
                    <td class="small"><?php echo $downloadBtn((string)$bf[1]); ?></td>
                    <td class="text-muted small">—</td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!empty($additional)): ?>
                  <?php foreach ($additional as $f): ?>
                    <tr>
                      <td class="fw-semibold"><?php echo htmlspecialchars((string)($f['file_name'] ?? 'File')); ?></td>
                      <td class="small"><?php echo $downloadBtn((string)($f['file_path'] ?? ''), 'Download'); ?></td>
                      <td class="text-muted small"><?php echo $d((string)($f['uploaded_at'] ?? '')); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
    <div class="col-lg-6" id="form">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-light d-flex align-items-center justify-content-between">
          <span class="dfb-card-title"><i class="bi bi-ui-checks me-2"></i>Assigned Form</span>
          <span class="text-muted small"><?php echo $form ? ((int)count(array_values(array_filter($fields, fn($f) => !(is_array($f) && array_key_exists('visible', $f) && empty($f['visible'])))))) . ' fields' : '—'; ?></span>
        </div>
        <div class="card-body">
          <?php if (!$form): ?>
            <div class="dfb-empty d-flex align-items-start gap-3">
              <div class="text-primary"><i class="bi bi-ui-checks" style="font-size: 1.25rem;"></i></div>
              <div>
                <div class="fw-semibold">No form assigned</div>
                <div class="text-muted small">Assign a lead form to control which fields appear in Lead Entry and Bulk Upload.</div>
              </div>
            </div>
          <?php else: ?>
            <div class="d-flex align-items-center justify-content-between mb-2">
              <div class="fw-semibold"><?php echo htmlspecialchars((string)($form['form_name'] ?? 'Form')); ?></div>
              <?php $canManageForms = isAdmin() || hasRole(['director','manager_director','operations_director']); ?>
              <?php if ($canManageForms): ?>
                <a class="btn btn-sm btn-outline-warning" href="../forms/forms-manage.php?campaign_id=<?php echo (int)$campaignId; ?>"><i class="bi bi-ui-checks me-1"></i>Manage</a>
              <?php endif; ?>
            </div>
            <div class="text-muted small mb-3">This campaign uses the selected form for lead capture.</div>

            <?php
              $visibleFields = array_values(array_filter($fields, fn($f) => !(is_array($f) && array_key_exists('visible', $f) && empty($f['visible']))));
              $fieldBadges = [];
              foreach ($visibleFields as $ff) {
                if (!is_array($ff)) continue;
                $lbl = trim((string)($ff['label'] ?? $ff['key'] ?? ''));
                if ($lbl !== '') $fieldBadges[] = $lbl;
              }
              $fieldBadges = array_values(array_unique($fieldBadges));
              $preview = array_slice($fieldBadges, 0, 10);
              $rest = array_slice($fieldBadges, 10);
            ?>

            <?php if (empty($fieldBadges)): ?>
              <div class="dfb-empty">
                <div class="fw-semibold">No fields in this form</div>
                <div class="text-muted small">Add fields in Forms to capture lead data for this campaign.</div>
              </div>
            <?php else: ?>
              <div class="d-flex flex-wrap gap-1 mb-2">
                <?php foreach ($preview as $b): ?>
                  <span class="badge rounded-pill bg-light text-dark border"><?php echo htmlspecialchars($b); ?></span>
                <?php endforeach; ?>
                <?php if (!empty($rest)): ?>
                  <button class="btn btn-sm btn-light border" type="button" data-bs-toggle="collapse" data-bs-target="#allFormFields" aria-expanded="false" aria-controls="allFormFields">
                    +<?php echo (int)count($rest); ?> more
                  </button>
                <?php endif; ?>
              </div>
              <?php if (!empty($rest)): ?>
                <div class="collapse" id="allFormFields">
                  <div class="d-flex flex-wrap gap-1">
                    <?php foreach ($rest as $b): ?>
                      <span class="badge rounded-pill bg-light text-dark border"><?php echo htmlspecialchars($b); ?></span>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <div class="row g-3 mb-3" id="notes">
    <div class="col-12">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-light d-flex align-items-center justify-content-between">
          <span class="dfb-card-title"><i class="bi bi-journal-text me-2"></i>Notes</span>
          <span class="text-muted small"><?php echo (int)count($notes); ?> items</span>
        </div>
        <div class="card-body">
          <?php if ($canManageNotes): ?>
          <form class="row g-2 align-items-end p-3 border rounded-3 bg-light" id="campaignNoteForm" method="post" action="view?id=<?php echo (int)$campaignId; ?>#notes" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
            <input type="hidden" name="campaign_id" value="<?php echo (int)$campaignId; ?>">
            <input type="hidden" name="action" value="add_note">
            <div class="col-12 col-md-9">
              <label class="form-label mb-1">Add Note</label>
              <textarea class="form-control" name="note_text" id="note_text" rows="2" placeholder="Type an update for this campaign..."></textarea>
              <div class="form-text">Visible internally. Keep updates short and actionable.</div>
            </div>
            <div class="col-12 col-md-3">
              <label class="form-label mb-1">Attachment (optional)</label>
              <input type="file" class="form-control" name="attachment" id="note_attachment" accept=".pdf,.doc,.docx,.txt,.csv,.xls,.xlsx,.png,.jpg,.jpeg,.gif">
            </div>
            <div class="col-12 d-flex justify-content-end">
              <label class="form-label">&nbsp;</label>
              <button type="submit" class="btn btn-primary btn-sm px-3"><i class="bi bi-plus-lg me-1"></i>Add</button>
            </div>
          </form>
          <hr>
          <?php endif; ?>
          <div id="campaignNotesList">
            <?php if (empty($notes)): ?>
              <div class="text-muted">No notes yet.</div>
            <?php else: ?>
              <div class="list-group">
                <?php foreach ($notes as $n): ?>
                  <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-start">
                      <div class="flex-grow-1">
                        <div class="small text-muted mb-1">
                          <span>By <?php echo htmlspecialchars($n['author_name'] ?? ''); ?></span>
                          <span class="ms-2">Last updated by <?php echo htmlspecialchars($n['updated_by_name'] ?? ($n['author_name'] ?? '')); ?></span>
                          <span class="ms-2"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime((string)($n['updated_at'] ?? $n['created_at'] ?? '')))); ?></span>
                        </div>
                        <div class="fw-semibold" data-note-id="<?php echo (int)$n['id']; ?>" data-note-text="<?php echo htmlspecialchars($n['note_text'] ?? ''); ?>"><?php echo nl2br(htmlspecialchars($n['note_text'] ?? '')); ?></div>
                        <?php if (!empty($n['attachment_path'])): ?>
                          <div class="mt-2">
                            <a class="btn btn-sm btn-outline-secondary" href="<?php echo '../../'.ltrim((string)$n['attachment_path'],'/'); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($n['attachment_name'] ?? 'Attachment'); ?></a>
                          </div>
                        <?php endif; ?>
                      </div>
                      <?php if ($canManageNotes): ?>
                      <div class="ms-2 text-nowrap">
                        <button class="btn btn-sm btn-outline-secondary" data-action="edit-note" data-note-id="<?php echo (int)$n['id']; ?>"><i class="bi bi-pencil"></i></button>
                        <button class="btn btn-sm btn-outline-secondary" data-action="attach-file" data-note-id="<?php echo (int)$n['id']; ?>"><i class="bi bi-paperclip"></i></button>
                        <button class="btn btn-sm btn-outline-danger" data-action="delete-note" data-note-id="<?php echo (int)$n['id']; ?>"><i class="bi bi-trash"></i></button>
                      </div>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

<script>
(function(){
  const nav = document.getElementById('campNav');
  if (nav) {
    const links = Array.from(nav.querySelectorAll('a.nav-link'));
    const sections = links
      .map(a => (a.getAttribute('href') || '').trim())
      .filter(h => h.startsWith('#'))
      .map(h => document.querySelector(h))
      .filter(Boolean);

    const setActive = (id) => {
      links.forEach(a => {
        const h = (a.getAttribute('href') || '').trim();
        a.classList.toggle('active', h === ('#' + id));
      });
    };

    const io = new IntersectionObserver((entries) => {
      const visible = entries
        .filter(e => e.isIntersecting)
        .sort((a,b) => (b.intersectionRatio - a.intersectionRatio))[0];
      if (visible && visible.target && visible.target.id) setActive(visible.target.id);
    }, { root: null, threshold: [0.15, 0.35, 0.55], rootMargin: '-20% 0px -65% 0px' });

    sections.forEach(s => io.observe(s));

    nav.addEventListener('click', (e) => {
      const a = e.target.closest('a.nav-link');
      if (!a) return;
      const href = (a.getAttribute('href') || '').trim();
      if (!href.startsWith('#')) return;
      const el = document.querySelector(href);
      if (!el) return;
      e.preventDefault();
      const top = el.getBoundingClientRect().top + window.scrollY - 72;
      window.scrollTo({ top, behavior: 'smooth' });
      history.replaceState(null, '', href);
    });
  }

  const campaignId = <?php echo (int)$campaignId; ?>;
  const token = '<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>';
  const apiUrl = <?php echo json_encode(appBasePath() . '/modules/campaigns/view'); ?>;
  const notify = (type, message, title) => {
    if (window.appNotify) return window.appNotify(type, message, title || '');
    alert(String(message || ''));
  };
  const form = document.getElementById('campaignNoteForm');
  if (form) {
    form.addEventListener('submit', function(ev){
      ev.preventDefault();
      const ta = document.getElementById('note_text');
      const txt = (ta && ta.value) ? ta.value.trim() : '';
      if (txt === '') return;
      const fd = new FormData();
      fd.append('csrf_token', token);
      fd.append('action', 'add_note');
      fd.append('note_text', txt);
      const att = document.getElementById('note_attachment');
      if (att && att.files && att.files[0]) { fd.append('attachment', att.files[0]); }
      fetch(apiUrl+'?id='+encodeURIComponent(campaignId), { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: fd })
      .then(r=>r.json()).then(d=>{
        if (d && d.ok && d.note) {
          ta.value = '';
          if (att) att.value = '';
          appendNote(d.note);
          notify('success', 'Note added', 'Success');
        } else { notify('danger', (d && d.error) ? d.error : 'Failed to add note', 'Error'); }
      }).catch(()=>{ notify('danger', 'Network error', 'Error'); });
    });
  }
  function appendNote(n){
    const list = document.getElementById('campaignNotesList');
    if (!list) return;
    const placeholder = Array.from(list.children).find(el => el.classList && el.classList.contains('text-muted'));
    if (placeholder) placeholder.remove();
    const wrap = list.querySelector('.list-group') || (function(){
      const lg = document.createElement('div'); lg.className='list-group'; list.appendChild(lg); return lg;
    })();
    const item = document.createElement('div');
    item.className='list-group-item';
    const dt = n.updated_at || n.created_at || '';
    const when = dt ? new Date(dt.replace(' ', 'T')).toLocaleString() : '';
    item.innerHTML = '<div class=\"d-flex justify-content-between align-items-start\">'
      + '<div class=\"flex-grow-1\">'
      + '<div class=\"small text-muted mb-1\"><span>By '+escapeHtml(n.author_name||'')+'</span><span class=\"ms-2\">Last updated by '+escapeHtml(n.updated_by_name||n.author_name||'')+'</span><span class=\"ms-2\">'+escapeHtml(when)+'</span></div>'
      + '<div class=\"fw-semibold\" data-note-id=\"'+String(n.id)+'\" data-note-text=\"'+escapeHtml(n.note_text||'')+'\">'+escapeHtml(n.note_text||'').replace(/\\n/g,'<br>')+'</div>'
      + (n.attachment_path ? '<div class=\"mt-2\"><a class=\"btn btn-sm btn-outline-secondary\" href=\"'+'<?php echo '../../'; ?>'+String(n.attachment_path).replace(/^\\//,'')+'\" target=\"_blank\" rel=\"noopener\">'+escapeHtml(n.attachment_name||'Attachment')+'</a></div>' : '')
      + '</div>'
      + (<?php echo $canManageNotes ? 'true' : 'false'; ?> ? '<div class=\"ms-2 text-nowrap\">'
        + '<button class=\"btn btn-sm btn-outline-secondary\" data-action=\"edit-note\" data-note-id=\"'+String(n.id)+'\"><i class=\"bi bi-pencil\"></i></button>'
        + '<button class=\"btn btn-sm btn-outline-secondary\" data-action=\"attach-file\" data-note-id=\"'+String(n.id)+'\"><i class=\"bi bi-paperclip\"></i></button>'
        + '<button class=\"btn btn-sm btn-outline-danger\" data-action=\"delete-note\" data-note-id=\"'+String(n.id)+'\"><i class=\"bi bi-trash\"></i></button>'
        + '</div>' : '')
      + '</div>';
    wrap.prepend(item);
  }
  document.addEventListener('click', function(ev){
    const btn = ev.target.closest('[data-action=\"edit-note\"],[data-action=\"delete-note\"],[data-action=\"attach-file\"]');
    if (!btn) return;
    const act = btn.getAttribute('data-action');
    const nid = btn.getAttribute('data-note-id');
    if (act === 'edit-note') {
      const el = document.querySelector('[data-note-id=\"'+CSS.escape(String(nid))+'\"]');
      const current = el ? el.getAttribute('data-note-text') || '' : '';
      const next = prompt('Edit note', current || '');
      if (next == null) return;
      const txt = String(next).trim();
      if (txt === '') return;
      const fd = new FormData();
      fd.append('csrf_token', token);
      fd.append('action', 'edit_note');
      fd.append('note_id', nid);
      fd.append('note_text', txt);
      fetch(apiUrl+'?id='+encodeURIComponent(campaignId), { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: fd })
      .then(r=>r.json()).then(d=>{
        if (d && d.ok && d.note) {
          if (el) { el.innerHTML = escapeHtml(d.note.note_text||'').replace(/\\n/g,'<br>'); el.setAttribute('data-note-text', d.note.note_text||''); }
          notify('success', 'Note updated', 'Success');
        } else { notify('danger', (d && d.error) ? d.error : 'Failed to update note', 'Error'); }
      }).catch(()=>{ notify('danger', 'Network error', 'Error'); });
    } else if (act === 'attach-file') {
      const fi = document.createElement('input');
      fi.type = 'file';
      fi.accept = '.pdf,.doc,.docx,.txt,.csv,.xls,.xlsx,.png,.jpg,.jpeg,.gif';
      fi.style.display = 'none';
      document.body.appendChild(fi);
      fi.addEventListener('change', function(){
        const f = fi.files && fi.files[0] ? fi.files[0] : null;
        if (!f) { document.body.removeChild(fi); return; }
        const fd = new FormData();
        fd.append('csrf_token', token);
        fd.append('action', 'edit_note');
        fd.append('note_id', nid);
        fd.append('note_text', '');
        fd.append('attachment', f);
        fetch(apiUrl+'?id='+encodeURIComponent(campaignId), { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: fd })
        .then(r=>r.json()).then(d=>{
          if (d && d.ok && d.note) {
            const item = btn.closest('.list-group-item');
            if (item) {
              const attWrap = item.querySelector('.mt-2 a.btn.btn-sm.btn-outline-secondary');
              const href = '../../'+String(d.note.attachment_path||'').replace(/^\//,'');
              const title = d.note.attachment_name || 'Attachment';
              if (attWrap) {
                attWrap.href = href;
                attWrap.textContent = title;
              } else {
                const div = document.createElement('div');
                div.className = 'mt-2';
                const a = document.createElement('a');
                a.className = 'btn btn-sm btn-outline-secondary';
                a.href = href;
                a.target = '_blank';
                a.rel = 'noopener';
                a.textContent = title;
                div.appendChild(a);
                const content = item.querySelector('.flex-grow-1');
                if (content) content.appendChild(div);
              }
            }
            notify('success', 'Attachment updated', 'Success');
          } else { notify('danger', (d && d.error) ? d.error : 'Failed to attach file', 'Error'); }
        }).catch(()=>{ notify('danger', 'Network error', 'Error'); }).finally(()=>{ document.body.removeChild(fi); });
      });
      fi.click();
    } else if (act === 'delete-note') {
      if (!confirm('Delete this note?')) return;
      const fd = new FormData();
      fd.append('csrf_token', token);
      fd.append('action', 'delete_note');
      fd.append('note_id', nid);
      fetch(apiUrl+'?id='+encodeURIComponent(campaignId), { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: fd })
      .then(r=>r.json()).then(d=>{
        if (d && d.ok) {
          const el = document.querySelector('[data-note-id=\"'+CSS.escape(String(nid))+'\"]');
          if (el) {
            const item = el.closest('.list-group-item'); if (item) item.remove();
          }
          const list = document.getElementById('campaignNotesList');
          if (list && !list.querySelector('.list-group-item')) list.innerHTML = '<div class=\"text-muted\">No notes yet.</div>';
          notify('success', 'Note deleted', 'Success');
        } else { notify('danger', (d && d.error) ? d.error : 'Failed to delete note', 'Error'); }
      }).catch(()=>{ notify('danger', 'Network error', 'Error'); });
    }
  });
  function escapeHtml(str){
    if (str == null) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\"/g,'&quot;').replace(/'/g,'&#39;');
  }

})();
</script>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
