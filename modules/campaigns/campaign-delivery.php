<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermissionOrRole('campaigns.files.page', []);
ensureCsrfToken();
$canManageCampaigns = isAdmin() || isSalesDirector() || isSalesManager() || hasRole(['director','manager_director','operations_director']);
$uid = getCurrentUser()['id'];
$cid = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : 0;
$msg = '';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_delivery') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) { $err = 'Invalid token'; } else {
        if (!$canManageCampaigns) { $err = 'Not allowed'; } else {
            $cid = (int)($_POST['campaign_id'] ?? 0);
            $format = ($_POST['delivery_format'] ?? '') === 'Other' ? trim($_POST['delivery_format_other'] ?? '') : ($_POST['delivery_format'] ?? null);
            $notes = trim($_POST['notes'] ?? '');
            if ($cid <= 0) { $err = 'Select a campaign'; } else {
                $ok = saveCampaignDelivery($cid, $format, $notes, $_FILES['file'] ?? [], $uid);
                if ($ok) { $msg = 'Delivery file uploaded'; } else { $err = 'Upload failed'; }
            }
        }
    }
}
$active = getActiveCampaignsBasic();
$files = $cid > 0 ? getDeliveryFilesByCampaign($cid) : [];
define('INCLUDED', true);
?>
<?php $pageTitle = 'Campaign Delivery'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0 campaign-workspace">
  <div class="campaign-page-header campaign-hero mb-3">
    <div>
      <div class="campaign-eyebrow mb-2"><i class="bi bi-upload"></i>Campaign Module</div>
      <div class="campaign-page-title">Delivery Files</div>
      <div class="campaign-page-subtitle">Upload and track campaign delivery files with the same CRM-aligned spacing, cards, and table layout used across the newer modules.</div>
    </div>
  </div>
  <?php if(!empty($msg)): ?><div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
  <?php if(!empty($err)): ?><div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
  <form method="get" class="row g-3 mb-3 campaign-filter-form">
    <div class="col-md-6">
      <label class="form-label">Active Campaign</label>
      <select name="campaign_id" class="form-select" onchange="this.form.submit()">
        <option value="0">Select</option>
        <?php foreach($active as $c): ?>
          <option value="<?php echo (int)$c['id']; ?>" <?php echo ($cid===(int)$c['id'])?'selected':''; ?>><?php echo htmlspecialchars(crmCampaignDisplayNameFromRow($c)); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>

  <?php if($cid>0 && $canManageCampaigns): ?>
  <div class="card mb-4 campaign-panel campaign-form-shell">
    <div class="card-header">Attach Delivery File</div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="upload_delivery">
        <input type="hidden" name="campaign_id" value="<?php echo (int)$cid; ?>">
        <div class="col-md-4">
          <label class="form-label">Delivery Format</label>
          <select name="delivery_format" class="form-select" id="deliveryFormatSel">
            <option value=""> Please Select </option>
            <option>CSV</option>
            <option>XLS</option>
            <option>XLSX</option>
            <option>ZIP</option>
            <option>PDF</option>
            <option>JSON</option>
            <option>Other</option>
          </select>
          <input type="text" class="form-control mt-2" name="delivery_format_other" id="deliveryFormatOther" placeholder="Enter format" style="display:none;">
        </div>
        <div class="col-md-5">
          <label class="form-label">Notes</label>
          <textarea name="notes" class="form-control" rows="2"></textarea>
        </div>
        <div class="col-md-3">
          <label class="form-label">File</label>
          <input type="file" name="file" class="form-control" accept=".csv,.xls,.xlsx,.zip,.pdf,.json" required>
        </div>
        <div class="col-md-12">
          <button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> Upload</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <div class="card campaign-panel">
    <div class="card-header">History</div>
    <div class="card-body">
      <div class="table-responsive campaign-table-wrap">
        <table class="table table-striped table-hover">
          <thead>
            <tr>
              <th>File</th>
              <th>Format</th>
              <th>Notes</th>
              <th>Uploaded By</th>
              <th>Timestamp</th>
              <th>Download</th>
            </tr>
          </thead>
          <tbody>
            <?php if(empty($files)): ?>
              <tr><td colspan="6" class="text-muted text-center">No files</td></tr>
            <?php else: foreach($files as $f): ?>
              <tr>
                <td><?php echo htmlspecialchars($f['file_name'] ?? basename((string)($f['file_path'] ?? ''))); ?></td>
                <td><?php echo htmlspecialchars($f['format'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($f['notes'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($f['full_name'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($f['created_at'] ?? ''); ?></td>
                <?php
                  $rawPath = (string)($f['file_path'] ?? '');
                  $fileId = (int)($f['id'] ?? 0);
                  $onDisk = $rawPath !== '' && campaignUploadExistsOnDisk($rawPath);
                  $downloadUrl = $fileId > 0 ? campaignFileDownloadUrl($cid, '', $fileId) : '';
                ?>
                <td>
                  <?php if ($downloadUrl !== '' && $onDisk): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?php echo htmlspecialchars($downloadUrl); ?>"><i class="bi bi-download"></i></a>
                  <?php elseif ($rawPath !== ''): ?>
                    <span class="badge bg-warning-subtle text-warning border" title="File record exists but the file is missing on the server. Re-upload from campaign edit.">Missing file</span>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script>
const sel=document.getElementById('deliveryFormatSel');
const other=document.getElementById('deliveryFormatOther');
if(sel&&other){ sel.addEventListener('change',()=>{ other.style.display = sel.value==='Other' ? 'block' : 'none'; }); }
</script>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
