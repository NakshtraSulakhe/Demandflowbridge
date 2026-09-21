<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requirePermissionOrRole('campaigns.details.page', []);

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$role = (string)($user['role'] ?? '');
$visible = getScopedVisibleCampaignIdsForUser($userId, $role);
$campaigns = $visible === null ? getAllCampaignsBasic() : getCampaignsBasicByIds(array_keys($visible), false);
$campaignId = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : 0;
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? max(10, (int)$_GET['per_page']) : 25;
$perPage = min(500, $perPage);
$offset = ($page - 1) * $perPage;

$rows = [];
$total = 0;
$campaignName = '';
$campaignCode = '';
$campaignClientCode = '';

if ($visible !== null && $campaignId > 0 && !isset($visible[$campaignId])) {
    $campaignId = 0;
}

if ($campaignId > 0) {
    $campRow = getCampaignById($campaignId) ?: null;
    $campaignName = $campRow ? (string)($campRow['name'] ?? '') : '';
    $campaignCode = getCampaignCode($campaignId) ?? '';
    $campaignClientCode = getCampaignClientCode($campaignId) ?? '';
    $filters = ['campaign_id' => $campaignId];
    if ($visible !== null) $filters['campaign_ids'] = array_keys($visible);
    if ($q !== '') $filters['search'] = $q;
    $data = getLeads($filters, $perPage, $page);
    $rows = $data['leads'] ?? [];
    $total = (int)($data['total'] ?? 0);
}

$totalPages = $perPage > 0 ? max(1, (int)ceil($total / $perPage)) : 1;
$activeFilterCount = count(array_filter([
    $campaignId > 0 ? 1 : 0,
    $q !== '' ? 1 : 0,
]));
?>
<?php $pageTitle = 'Campaign Leads'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0 campaign-workspace">
    <div class="campaign-page-header campaign-hero mb-3">
        <div>
            <div class="campaign-eyebrow mb-2"><i class="bi bi-collection"></i>Campaign Module</div>
            <div class="campaign-page-title">Campaign Leads</div>
            <div class="campaign-page-subtitle"><?php echo htmlspecialchars($campaignName !== '' ? crmCampaignDisplayName($campaignName, $campaignClientCode, $campaignCode) : 'Select a campaign to review campaign-specific leads.'); ?></div>
        </div>
        <div class="campaign-page-actions">
            <a class="btn btn-light border" href="list"><i class="bi bi-arrow-left me-1"></i>Back</a>
        </div>
    </div>

    <div class="card border-0 shadow-sm campaign-panel campaign-form-shell mb-3">
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end campaign-filter-form">
                <div class="col-md-5">
                    <label class="form-label small text-muted">Campaign</label>
                    <select class="form-select form-select-sm" name="campaign_id" required>
                        <option value="">Select Campaign</option>
                        <?php foreach ($campaigns as $c): ?>
                            <option value="<?php echo (int)$c['id']; ?>" <?php echo ((int)$campaignId === (int)$c['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(crmCampaignDisplayNameFromRow($c)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">Search</label>
                    <input class="form-control form-control-sm" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Name, email, company, lead code">
                </div>
                <div class="col-md-1">
                    <label class="form-label small text-muted">Rows</label>
                    <select class="form-select form-select-sm" name="per_page">
                        <?php foreach ([25,50,100,500] as $n): ?>
                            <option value="<?php echo $n; ?>" <?php echo ($perPage == $n) ? 'selected' : ''; ?>><?php echo $n; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-grid">
                    <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($campaignId <= 0): ?>
        <div class="alert alert-info border-0 shadow-sm">Select a campaign to view its leads.</div>
    <?php else: ?>
        <div class="card border-0 shadow-sm campaign-panel">
            <div class="campaign-table-toolbar">
                <div class="campaign-table-toolbar-main">
                    <span class="campaign-table-count"><i class="bi bi-table"></i> Showing <?php echo number_format(count($rows)); ?> of <?php echo number_format($total); ?> leads</span>
                    <div>
                        <div class="campaign-table-title">Lead Results</div>
                        <div class="campaign-table-subtitle">Campaign-level lead results stay visible above the table with the current filters applied.</div>
                    </div>
                </div>
                <div class="campaign-table-toolbar-side">
                    <?php if ($activeFilterCount > 0): ?>
                        <span class="campaign-filter-pill"><i class="bi bi-funnel"></i><?php echo $activeFilterCount; ?> filters active</span>
                    <?php endif; ?>
                    <span class="campaign-filter-pill"><i class="bi bi-layout-text-window-reverse"></i><?php echo (int)$perPage; ?> rows per page</span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive campaign-table-wrap">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">SR No.</th>
                                <th>Date</th>
                                <th>Lead</th>
                                <th>Email</th>
                                <th>Company</th>
                                <th>QA</th>
                                <th class="text-end pe-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">No leads found.</td></tr>
                            <?php else: ?>
                                <?php $serialStart = (($page - 1) * $perPage) + 1; $i = 0; ?>
                                <?php foreach ($rows as $r): $i++; ?>
                                    <?php
                                        $qa = (string)($r['qa_status'] ?? 'Pending');
                                        $qaClass = 'bg-warning-subtle text-warning';
                                        if ($qa === 'Qualified' || $qa === 'Rectified') $qaClass = 'bg-success-subtle text-success';
                                        if ($qa === 'Disqualified') $qaClass = 'bg-danger-subtle text-danger';
                                        if ($qa === 'Duplicate') $qaClass = 'bg-dark-subtle text-dark';
                                        $nm = trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''));
                                    ?>
                                    <tr>
                                        <td class="ps-3 text-muted small"><?php echo $serialStart + $i - 1; ?></td>
                                        <td class="text-muted small"><?php echo !empty($r['created_at']) ? htmlspecialchars(date('Y-m-d H:i', strtotime((string)$r['created_at']))) : '—'; ?></td>
                                        <td class="fw-semibold"><?php echo htmlspecialchars($nm !== '' ? $nm : ((string)($r['lead_id'] ?? (string)($r['id'] ?? '')))); ?></td>
                                        <td class="text-muted small"><?php echo htmlspecialchars((string)($r['email'] ?? '')); ?></td>
                                        <td class="text-muted small"><?php echo htmlspecialchars((string)($r['company_name'] ?? '')); ?></td>
                                        <td><span class="badge <?php echo $qaClass; ?> border"><?php echo htmlspecialchars($qa); ?></span></td>
                                        <td class="text-end pe-3">
                                            <div class="btn-group btn-group-sm">
                                                <?php
                                                    $recCount = (int)($r['recording_count'] ?? 0);
                                                    if ($recCount === 0 && !empty($r['recording_path'])) $recCount = 1;
                                                ?>
                                                <?php if ($recCount > 0): ?>
                                                    <button type="button" class="btn btn-light border position-relative" data-bs-toggle="modal" data-bs-target="#recordingModal" data-lead-name="<?php echo htmlspecialchars($nm); ?>" data-lead-id="<?php echo (int)$r['id']; ?>" title="Play Recordings">
                                                        <i class="bi bi-play-fill"></i>
                                                        <?php if ($recCount > 1): ?>
                                                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.6rem; padding: 0.2rem 0.4rem;">
                                                                <?php echo $recCount; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </button>
                                                <?php endif; ?>
                                                <a class="btn btn-outline-primary" href="../leads/lead-details.php?id=<?php echo (int)($r['id'] ?? 0); ?>" title="View Details"><i class="bi bi-eye"></i></a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($totalPages > 1): ?>
                <div class="card-footer bg-white">
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <?php
                                $qs = $_GET;
                                $mk = function(int $p) use ($qs) {
                                    $qs['page'] = $p;
                                    return 'leads?' . http_build_query($qs);
                                };
                            ?>
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars($mk(max(1, $page-1))); ?>">Prev</a>
                            </li>
                            <li class="page-item disabled"><span class="page-link"><?php echo $page; ?> / <?php echo $totalPages; ?></span></li>
                            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars($mk(min($totalPages, $page+1))); ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>

<!-- Recording Player Modal -->
<div class="modal fade" id="recordingModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title">Lead Recordings</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body py-3">
        <h6 id="recLeadName" class="mb-3 fw-bold text-primary px-3"></h6>
        <div id="recModalList" class="px-3">
          <div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div></div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const recordingModal = document.getElementById('recordingModal');
    if (recordingModal) {
        recordingModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const name = button.getAttribute('data-lead-name');
            const leadId = button.getAttribute('data-lead-id');
            document.getElementById('recLeadName').textContent = name;
            
            const list = document.getElementById('recModalList');
            list.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div></div>';
            
            fetch('../leads/details?id=' + encodeURIComponent(leadId) + '&format=json&only_recordings=1', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => {
                if (!r.ok) throw new Error('Network response was not ok');
                return r.json();
            })
            .then(data => {
                if (data.ok && Array.isArray(data.recordings)) {
                    const recs = data.recordings;
                    if (recs.length > 0) {
                        list.innerHTML = recs.map((r, i) => `
                            <div class="mb-3 p-2 border rounded bg-light">
                                <div class="d-flex justify-content-between x-small mb-1 px-1">
                                    <span class="fw-bold">Recording #${i+1}</span>
                                    <a href="${r.dl || r.url}" download class="text-primary text-decoration-none">Download</a>
                                </div>
                                <audio controls class="w-100" style="height: 30px;" src="${r.url}"></audio>
                            </div>
                        `).join('');
                    } else {
                        list.innerHTML = '<div class="text-muted small text-center py-3">No recordings found.</div>';
                    }
                } else {
                    list.innerHTML = '<div class="text-danger small text-center py-3">Error loading recordings.</div>';
                }
            })
            .catch((err) => {
                list.innerHTML = '<div class="text-danger small text-center py-3">Failed to fetch data: ' + err.message + '</div>';
            });
        });
        recordingModal.addEventListener('hidden.bs.modal', function() {
            const audios = document.getElementById('recModalList').querySelectorAll('audio');
            audios.forEach(a => { a.pause(); a.src = ''; });
        });
    }
});
</script>
