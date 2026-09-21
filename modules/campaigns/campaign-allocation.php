<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermissionOrRole('campaigns.allocation.page', []);
requireRole(['admin', 'operations_director', 'operations_manager']);

$user = getCurrentUser();
$conn = getDbConnection();

$message = '';
$messageType = 'success';

// Handle AJAX requests for fetching/saving assignments
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    $campaignId = (int)($_GET['campaign_id'] ?? 0);

    if ($action === 'get_assignments' && $campaignId > 0) {
        // Get individual assignments
        $individual = [];
        $stmt = $conn->prepare("SELECT user_id FROM operations_campaign_assignments WHERE campaign_id = ?");
        $stmt->bind_param('i', $campaignId);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) $individual[] = (int)$r['user_id'];
        $stmt->close();

        // Get team assignments
        $teams = [];
        $stmt = $conn->prepare("SELECT team_id FROM team_campaigns WHERE campaign_id = ?");
        $stmt->bind_param('i', $campaignId);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) $teams[] = (int)$r['team_id'];
        $stmt->close();

        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'individual' => $individual, 'teams' => $teams]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = 'Invalid token.';
        $messageType = 'danger';
    } else {
        $campaignId = (int)($_POST['campaign_id'] ?? 0);
        if ($campaignId <= 0) {
            $message = 'Invalid campaign selected.';
            $messageType = 'danger';
        } else {
            $selectedTeams = $_POST['team_ids'] ?? [];
            $selectedUsers = $_POST['user_ids'] ?? [];

            $conn->begin_transaction();
            try {
                // 1. Update Team Assignments
                $stmt = $conn->prepare("DELETE FROM team_campaigns WHERE campaign_id = ?");
                $stmt->bind_param('i', $campaignId);
                $stmt->execute();
                $stmt->close();

                if (!empty($selectedTeams)) {
                    $stmt = $conn->prepare("INSERT IGNORE INTO team_campaigns (team_id, campaign_id, assigned_by, assigned_at) VALUES (?, ?, ?, NOW())");
                    foreach ($selectedTeams as $tid) {
                        $tid = (int)$tid;
                        $stmt->bind_param('iii', $tid, $campaignId, $user['id']);
                        $stmt->execute();
                    }
                    $stmt->close();
                }

                // 2. Update Individual Assignments
                $stmt = $conn->prepare("DELETE FROM operations_campaign_assignments WHERE campaign_id = ?");
                $stmt->bind_param('i', $campaignId);
                $stmt->execute();
                $stmt->close();

                if (!empty($selectedUsers)) {
                    $stmt = $conn->prepare("INSERT IGNORE INTO operations_campaign_assignments (campaign_id, user_id, assigned_by, assigned_at) VALUES (?, ?, ?, NOW())");
                    foreach ($selectedUsers as $uid) {
                        $uid = (int)$uid;
                        $stmt->bind_param('iii', $campaignId, $uid, $user['id']);
                        $stmt->execute();
                    }
                    $stmt->close();
                }

                $conn->commit();
                $message = 'Campaign allocation updated successfully.';
                $messageType = 'success';
            } catch (Exception $e) {
                $conn->rollback();
                $message = 'Error: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }
}

// Data for the page
$campaigns = getAllCampaignsBasic();
$allTeams = [];
$rs = $conn->query("SELECT id, team_name FROM teams ORDER BY team_name ASC");
if ($rs) $allTeams = $rs->fetch_all(MYSQLI_ASSOC);

$allUsers = [];
$rs = $conn->query("SELECT id, full_name, role, job_title FROM users WHERE is_active = 1 AND role NOT LIKE 'client_%' AND role NOT LIKE 'vendor_%' ORDER BY full_name ASC");
if ($rs) $allUsers = $rs->fetch_all(MYSQLI_ASSOC);

?>

<?php $pageTitle = 'Campaign Allocation'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">

<style>
    .form-label { font-weight: 500; color: var(--app-muted); margin-bottom: 0.25rem; }
    .form-control-sm, .form-select-sm { border-radius: 0.375rem; border-color: var(--app-border); padding: 0.4rem 0.6rem; }
    .form-control-sm:focus, .form-select-sm:focus { border-color: rgba(0,255,255,.35); box-shadow: 0 0 0 2px rgba(0,255,255,.12); }
    .card { background: var(--app-surface); border: 1px solid var(--app-border); box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.12), 0 1px 2px 0 rgba(0, 0, 0, 0.08); border-radius: 0.75rem; overflow: hidden; margin-bottom: 1.5rem; }
    .card-header { background-color: var(--app-surface); border-bottom: 1px solid var(--app-border); padding: 1.25rem 1.5rem; }
    .card-header .card-title { margin-bottom: 0; color: var(--app-text); font-weight: 600; font-size: 1.1rem; }
    .card-body { padding: 1.5rem; color: var(--app-text); }
    .btn-primary { background-color: #2563eb; border-color: #2563eb; font-weight: 500; padding: 0.5rem 1.25rem; border-radius: 0.5rem; transition: all 0.2s; }
    .btn-primary:hover { background-color: #1d4ed8; border-color: #1d4ed8; transform: translateY(-1px); }
    
    .campaign-row.table-active { background-color: rgba(37, 99, 235, 0.08) !important; border-left: 4px solid #2563eb; }
    :root[data-theme="dark"] .campaign-row.table-active, body[data-theme="dark"] .campaign-row.table-active, [data-bs-theme="dark"] .campaign-row.table-active { background-color: rgba(0, 255, 255, 0.06) !important; border-left-color: rgba(0, 255, 255, 0.55); }

    .list-group-item-action { cursor: pointer; transition: all 0.2s; border: 1px solid var(--app-border); margin-bottom: 0.5rem; border-radius: 0.5rem !important; background: var(--app-surface); color: var(--app-text); }
    .list-group-item-action:hover { background-color: rgba(255,255,255,0.04); border-color: rgba(0,255,255,.22); }

    .nav-pills .nav-link { font-weight: 500; color: var(--app-muted); border-radius: 0.5rem; padding: 0.6rem 1rem; }
    .nav-pills .nav-link.active { background-color: #2563eb; color: #fff; }
    #campaignTable_wrapper .dataTables_filter { margin-bottom: 1.25rem; }
    .sticky-panel { position: sticky; top: 5.5rem; z-index: 10; }
    .badge-live { background-color: rgba(34,197,94,.16); color: #16a34a; border: 1px solid rgba(34,197,94,.35); }
    .badge-other { background-color: rgba(148,163,184,.14); color: var(--app-text); border: 1px solid var(--app-border); }

    :root[data-theme="dark"] .badge-live, body[data-theme="dark"] .badge-live, [data-bs-theme="dark"] .badge-live { color: #86efac; }
    :root[data-theme="dark"] .badge-other, body[data-theme="dark"] .badge-other, [data-bs-theme="dark"] .badge-other { background-color: rgba(255,255,255,.06); }

    :root[data-theme="dark"] .bg-light, body[data-theme="dark"] .bg-light, [data-bs-theme="dark"] .bg-light { background-color: rgba(255,255,255,.04) !important; }
    :root[data-theme="dark"] .bg-white, body[data-theme="dark"] .bg-white, [data-bs-theme="dark"] .bg-white { background-color: rgba(255,255,255,.03) !important; }
</style>

<div class="container-fluid px-0 campaign-workspace">
    <div class="campaign-page-header campaign-hero mb-4">
        <div>
            <div class="campaign-eyebrow mb-2"><i class="bi bi-diagram-3"></i>Campaign Module</div>
            <h1 class="campaign-page-title mb-1">Campaign Allocation</h1>
            <p class="campaign-page-subtitle mb-0">Efficiently distribute campaigns across teams and individuals with the same CRM-aligned spacing and card treatment used across the refreshed campaign pages.</p>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
            <i class="bi <?php echo $messageType === 'success' ? 'bi-check-circle' : 'bi-exclamation-triangle'; ?> me-2"></i>
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Left: Campaign List -->
        <div class="col-lg-5">
            <div class="card campaign-panel">
                <div class="card-header">
                    <h5 class="card-title"><i class="bi bi-list-task me-2 text-primary"></i>Campaign List</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="campaignTable" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Code</th>
                                    <th class="text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($campaigns as $c): ?>
                                    <?php $campaignLabel = crmCampaignDisplayNameFromRow($c); ?>
                                    <tr class="campaign-row" data-id="<?php echo $c['id']; ?>" data-name="<?php echo htmlspecialchars($campaignLabel); ?>" data-code="<?php echo htmlspecialchars((string)($c['client_code'] ?? '')); ?>" style="cursor: pointer;">
                                        <td class="fw-semibold"><?php echo htmlspecialchars($campaignLabel); ?></td>
                                        <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($c['code']); ?></span></td>
                                        <td class="text-center">
                                            <?php if ($c['status'] === 'Live' || $c['status'] === 'Active'): ?>
                                                <span class="badge badge-live rounded-pill px-3">Live</span>
                                            <?php elseif ($c['status'] === 'Pause'): ?>
                                                <span class="badge badge-other rounded-pill px-3"><?php echo htmlspecialchars($c['status']); ?></span>
                                                <?php if (!empty($c['status_updated_by_name'])): ?>
                                                    <div class="x-small text-muted mt-1" style="font-size: 0.65rem;">by <?php echo htmlspecialchars($c['status_updated_by_name']); ?></div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="badge badge-other rounded-pill px-3"><?php echo htmlspecialchars($c['status']); ?></span>
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

        <!-- Right: Assignment Management -->
        <div class="col-lg-7">
            <div class="sticky-panel">
                <div id="noCampaignSelected" class="card campaign-panel p-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="bg-light rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 46px; height: 46px;">
                            <i class="bi bi-cursor text-primary"></i>
                        </div>
                        <div>
                            <div class="fw-semibold">Select a Campaign</div>
                            <div class="text-muted small">Choose a campaign from the list to allocate teams/users.</div>
                        </div>
                    </div>
                </div>

                <div id="assignmentPanel" class="card campaign-panel" style="display: none;">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="card-title" id="selectedCampaignName">Campaign Assignments</h5>
                            <span class="text-muted small" id="selectedCampaignCodeDisplay"></span>
                        </div>
                        <div class="badge bg-light text-primary border px-3 py-2 rounded-pill small">
                            <i class="bi bi-shield-check me-1"></i>Allocation Mode
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <form id="assignmentForm" method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="campaign_id" id="formCampaignId">

                            <div class="px-4 pt-4">
                                <ul class="nav nav-pills nav-fill bg-light p-1 rounded-3 mb-4" id="assignmentTabs" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active py-2" id="teams-tab" data-bs-toggle="pill" data-bs-target="#teams-content" type="button" role="tab">
                                            <i class="bi bi-people-fill me-2"></i>Teams
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link py-2" id="users-tab" data-bs-toggle="pill" data-bs-target="#users-content" type="button" role="tab">
                                            <i class="bi bi-person-fill me-2"></i>Individuals
                                        </button>
                                    </li>
                                </ul>
                            </div>

                            <div class="tab-content px-4" id="assignmentTabContent">
                                <!-- Teams Tab -->
                                <div class="tab-pane fade show active" id="teams-content" role="tabpanel">
                                    <div class="input-group input-group-sm mb-3">
                                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                                        <input type="text" class="form-control border-start-0" id="teamSearch" placeholder="Filter teams...">
                                    </div>
                                    <div class="list-group border-0 rounded shadow-none overflow-auto mb-4" style="max-height: 450px;" id="teamList">
                                        <?php foreach ($allTeams as $t): ?>
                                            <label class="list-group-item list-group-item-action d-flex align-items-center py-2 px-3 border rounded mb-2">
                                                <div class="form-check m-0">
                                                    <input class="form-check-input team-checkbox" type="checkbox" name="team_ids[]" value="<?php echo $t['id']; ?>" id="team_<?php echo $t['id']; ?>">
                                                </div>
                                                <div class="ms-3">
                                                    <span class="fw-semibold d-block"><?php echo htmlspecialchars($t['team_name']); ?></span>
                                                    <span class="text-muted x-small">Assign all members of this team</span>
                                                </div>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <!-- Users Tab -->
                                <div class="tab-pane fade" id="users-content" role="tabpanel">
                                    <div class="input-group input-group-sm mb-3">
                                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                                        <input type="text" class="form-control border-start-0" id="userSearch" placeholder="Filter individuals...">
                                    </div>
                                    <div class="list-group border-0 rounded shadow-none overflow-auto mb-4" style="max-height: 450px;" id="userList">
                                        <?php foreach ($allUsers as $u): ?>
                                            <label class="list-group-item list-group-item-action d-flex align-items-center py-2 px-3 border rounded mb-2">
                                                <div class="form-check m-0">
                                                    <input class="form-check-input user-checkbox" type="checkbox" name="user_ids[]" value="<?php echo $u['id']; ?>" id="user_<?php echo $u['id']; ?>">
                                                </div>
                                                <div class="ms-3">
                                                    <span class="fw-semibold d-block"><?php echo htmlspecialchars($u['full_name']); ?></span>
                                                    <span class="text-muted x-small"><?php echo htmlspecialchars($u['role']); ?> <?php echo !empty($u['job_title']) ? ' · ' . htmlspecialchars($u['job_title']) : ''; ?></span>
                                                </div>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="card-footer bg-white border-top-0 p-4">
                                <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                                    <div class="d-flex align-items-center">
                                        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                                            <span class="fw-bold h5 mb-0" id="selectionCount">0</span>
                                        </div>
                                        <div>
                                            <span class="text-dark fw-bold d-block small">Resources Selected</span>
                                            <span class="text-muted x-small">Click 'Apply' to update</span>
                                        </div>
                                    </div>
                                    <button type="submit" class="btn btn-primary px-4 py-2 fw-bold shadow-sm">
                                        <i class="bi bi-cloud-arrow-up-fill me-2"></i>Apply Allocation
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- jQuery and DataTables JS -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable
    const table = $('#campaignTable').DataTable({
        pageLength: 10,
        lengthMenu: [10, 25, 50],
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search campaigns...",
            paginate: {
                previous: '<i class="bi bi-chevron-left"></i>',
                next: '<i class="bi bi-chevron-right"></i>'
            }
        },
        dom: '<"d-flex justify-content-between align-items-center mb-3"f>rt<"d-flex justify-content-between align-items-center mt-3"ip>'
    });

    const assignmentPanel = $('#assignmentPanel');
    const noCampaignSelected = $('#noCampaignSelected');
    const selectedCampaignName = $('#selectedCampaignName');
    const selectedCampaignCodeDisplay = $('#selectedCampaignCodeDisplay');
    const formCampaignId = $('#formCampaignId');
    const teamCheckboxes = $('.team-checkbox');
    const userCheckboxes = $('.user-checkbox');
    const selectionCount = $('#selectionCount');

    function updateSelectionCount() {
        const count = $('.team-checkbox:checked, .user-checkbox:checked').length;
        selectionCount.text(count);
    }

    async function loadAssignments(campaignId, campaignName, campaignCode) {
        // Show loading state (optional)
        assignmentPanel.css('opacity', '0.5');
        
        // Reset checkboxes
        teamCheckboxes.prop('checked', false);
        userCheckboxes.prop('checked', false);
        
        // Update labels
        selectedCampaignName.text(campaignName);
        selectedCampaignCodeDisplay.text(campaignCode ? ('Client Code: ' + campaignCode) : '');
        formCampaignId.val(campaignId);
        
        try {
            const res = await fetch(`${window.location.pathname}?action=get_assignments&campaign_id=${campaignId}`);
            const data = await res.json();
            
            if (data.ok) {
                data.teams.forEach(tid => {
                    $(`.team-checkbox[value="${tid}"]`).prop('checked', true);
                });
                data.individual.forEach(uid => {
                    $(`.user-checkbox[value="${uid}"]`).prop('checked', true);
                });
                
                noCampaignSelected.hide();
                assignmentPanel.show().css('opacity', '1');
                updateSelectionCount();
            }
        } catch (e) {
            console.error('Failed to load assignments', e);
            if (window.appNotify) window.appNotify('danger', 'Error loading campaign assignments.', 'Error');
            else alert('Error loading campaign assignments.');
        }
    }

    // Row click handler (delegated for DataTables)
    $('#campaignTable tbody').on('click', 'tr.campaign-row', function() {
        $('.campaign-row').removeClass('table-active');
        $(this).addClass('table-active');
        
        const id = $(this).data('id');
        const name = $(this).data('name');
        const code = $(this).data('code');
        loadAssignments(id, name, code);
    });

    const preselectId = new URLSearchParams(window.location.search).get('campaign_id');
    if (preselectId) {
        const nodes = $(table.rows().nodes());
        const row = nodes.filter(function() {
            return String($(this).data('id')) === String(preselectId);
        }).first();
        if (row.length) row.trigger('click');
    }

    // Filtering logic
    $('#teamSearch').on('input', function() {
        const q = $(this).val().toLowerCase();
        $('#teamList label').each(function() {
            const text = $(this).text().toLowerCase();
            $(this).toggle(text.includes(q));
        });
    });

    $('#userSearch').on('input', function() {
        const q = $(this).val().toLowerCase();
        $('#userList label').each(function() {
            const text = $(this).text().toLowerCase();
            $(this).toggle(text.includes(q));
        });
    });

    // Checkbox change handler
    $('.team-checkbox, .user-checkbox').on('change', updateSelectionCount);
});
</script>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
