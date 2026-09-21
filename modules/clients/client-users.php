<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin','client_admin','sales_manager','sales_director']);
ensureCsrfToken();

$user = getCurrentUser();
$isAdmin = isAdmin();
$isClientAdmin = hasRole('client_admin');
$isClientUser = isClient() && !$isAdmin;
$currentClientId = (int)($user['client_id'] ?? 0);

$conn = getDbConnection();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid token.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'create_client_user') {
                $clientId = (int)($_POST['client_id'] ?? 0);
                $username = trim((string)($_POST['username'] ?? ''));
                $fullName = trim((string)($_POST['full_name'] ?? ''));
                $email = trim((string)($_POST['email'] ?? ''));
                $role = (string)($_POST['role'] ?? 'client_sdr');
                $isActive = isset($_POST['is_active']) ? 1 : 0;
                $password = (string)($_POST['password'] ?? '');
                if ($isClientUser) $clientId = $currentClientId;
                if ($clientId <= 0) throw new RuntimeException('Select a client first.');
                if ($username === '' || $fullName === '' || $email === '') throw new RuntimeException('Username, Full Name, Email are required.');
                $custom = getCustomRolesConfig();
                $allowed = ['client_admin','client_sdr'];
                foreach ($custom as $rk => $rv) {
                    if (!is_array($rv)) continue;
                    if ((string)($rv['scope'] ?? '') !== 'client') continue;
                    $rk = normalizeRole((string)$rk);
                    if ($rk !== '' && !in_array($rk, $allowed, true)) $allowed[] = $rk;
                }
                $role = normalizeRole($role);
                if (!in_array($role, $allowed, true)) throw new RuntimeException('Invalid role for Client user.');
                if ($password === '') $password = bin2hex(random_bytes(4));
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $chk = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                $chk->bind_param('s', $username);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) throw new RuntimeException('Username already exists.');
                $chk->close();
                $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, email, role, is_active, client_id, vendor_id) VALUES (?,?,?,?,?,?,?,0)");
                $stmt->bind_param('sssssii', $username, $hashed, $fullName, $email, $role, $isActive, $clientId);
                if (!$stmt->execute()) throw new RuntimeException('Failed to create client user.');
                $newUserId = (int)$conn->insert_id;
                $stmt->close();
                if ($newUserId > 0 && $role === 'client_sdr' && function_exists('syncClientUserCampaignAssignments')) {
                    syncClientUserCampaignAssignments($newUserId, $clientId, (int)($user['id'] ?? 0));
                }
                $success = 'Client user created.';
            } elseif ($action === 'set_user_active_client') {
                $uid = (int)($_POST['user_id'] ?? 0);
                $cid = (int)($_POST['client_id'] ?? 0);
                $active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 0;
                if ($isClientUser) $cid = $currentClientId;
                if ($uid <= 0 || $cid <= 0) throw new RuntimeException('Invalid request.');
                $stmt = $conn->prepare("SELECT client_id FROM users WHERE id = ? LIMIT 1");
                $stmt->bind_param('i', $uid);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc() ?: null;
                $stmt->close();
                if (!$row || (int)$row['client_id'] !== $cid) throw new RuntimeException('Not allowed.');
                $st = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ?");
                $st->bind_param('ii', $active, $uid);
                if (!$st->execute()) throw new RuntimeException('Failed to update status.');
                $st->close();
                $success = $active ? 'User activated.' : 'User deactivated.';
            } elseif ($action === 'list_client_user_campaigns') {
                header('Content-Type: application/json');
                $cid = (int)($_POST['client_id'] ?? 0);
                $uid = (int)($_POST['user_id'] ?? 0);
                if ($isClientUser) $cid = $currentClientId;
                if ($cid <= 0 || $uid <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid']); exit; }
                $stmt = $conn->prepare("SELECT role FROM users WHERE id = ? AND client_id = ? LIMIT 1");
                $userRole = '';
                if ($stmt) {
                    $stmt->bind_param('ii', $uid, $cid);
                    $stmt->execute();
                    $userRole = (string)(($stmt->get_result()->fetch_assoc() ?: [])['role'] ?? '');
                    $stmt->close();
                }
                if ($userRole === 'client_sdr' && function_exists('syncClientUserCampaignAssignments')) {
                    syncClientUserCampaignAssignments($uid, $cid, (int)($user['id'] ?? 0));
                }
                $campaigns = [];
                $clientCampaignIds = getClientCampaignIdsForClient($cid);
                if (!empty($clientCampaignIds)) {
                    $in = implode(',', array_fill(0, count($clientCampaignIds), '?'));
                    $stmt = $conn->prepare("SELECT c.id, c.name, d.code FROM campaigns c JOIN campaign_details d ON d.campaign_id = c.id WHERE c.id IN ($in) ORDER BY c.name");
                    if ($stmt) {
                        mysqliBindParams($stmt, str_repeat('i', count($clientCampaignIds)), $clientCampaignIds);
                        $stmt->execute();
                        $campaigns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                        $stmt->close();
                    }
                }
                $stmt = $conn->prepare("SELECT campaign_id FROM campaign_user_assignments WHERE user_id = ?");
                $stmt->bind_param('i', $uid);
                $stmt->execute();
                $assigned = [];
                $rs = $stmt->get_result();
                while ($r = $rs->fetch_assoc()) $assigned[] = (int)$r['campaign_id'];
                $stmt->close();
                echo json_encode(['ok'=>true,'campaigns'=>$campaigns,'assigned'=>$assigned]); exit;
            } elseif ($action === 'save_client_user_campaigns') {
                header('Content-Type: application/json');
                $cid = (int)($_POST['client_id'] ?? 0);
                $uid = (int)($_POST['user_id'] ?? 0);
                $campaignIds = isset($_POST['campaign_ids']) ? (array)$_POST['campaign_ids'] : [];
                $campaignIds = array_values(array_unique(array_map('intval', $campaignIds)));
                if ($isClientUser) $cid = $currentClientId;
                if ($cid <= 0 || $uid <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid']); exit; }
                ensureLeadsTrackingColumns();
                $allowed = [];
                foreach (getClientCampaignIdsForClient($cid) as $allowedCampaignId) {
                    $allowed[(int)$allowedCampaignId] = true;
                }
                $campaignIds = array_values(array_filter($campaignIds, function($x) use ($allowed){ return isset($allowed[(int)$x]); }));
                $current = [];
                $stmt = $conn->prepare("SELECT campaign_id FROM campaign_user_assignments WHERE user_id = ?");
                $stmt->bind_param('i', $uid);
                $stmt->execute();
                $rs = $stmt->get_result();
                while ($r = $rs->fetch_assoc()) $current[(int)$r['campaign_id']] = true;
                $stmt->close();
                foreach ($campaignIds as $x) {
                    if (!isset($current[$x])) {
                        $rm = $conn->prepare("
                            DELETE a
                            FROM campaign_user_assignments a
                            JOIN users u ON u.id = a.user_id
                            WHERE a.campaign_id = ? AND u.client_id = ? AND u.role = 'client_sdr' AND a.user_id <> ?
                        ");
                        if ($rm) {
                            $rm->bind_param('iii', $x, $cid, $uid);
                            $rm->execute();
                            $rm->close();
                        }
                        $ins = $conn->prepare("INSERT IGNORE INTO campaign_user_assignments (campaign_id, user_id, assigned_by) VALUES (?,?,?)");
                        $ins->bind_param('iii', $x, $uid, $user['id']);
                        $ins->execute();
                        $ins->close();

                        $upd = $conn->prepare("
                            UPDATE leads l
                            SET l.assigned_to_user = ?, l.updated_by = ?
                            WHERE l.campaign_id = ?
                        ");
                        if ($upd) {
                            $assignerId = (int)($user['id'] ?? 0);
                            $upd->bind_param('iii', $uid, $assignerId, $x);
                            $upd->execute();
                            $upd->close();
                        }
                    }
                }
                foreach ($current as $x => $_) {
                    if (!in_array($x, $campaignIds, true)) {
                        $del = $conn->prepare("DELETE FROM campaign_user_assignments WHERE campaign_id = ? AND user_id = ?");
                        $del->bind_param('ii', $x, $uid);
                        $del->execute();
                        $del->close();
                    }
                }
                echo json_encode(['ok'=>true]); exit;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$clientId = (int)($_GET['client_id'] ?? 0);
if ($isClientUser) $clientId = $currentClientId;
$clientRow = null;
if ($clientId > 0) {
    $stmt = $conn->prepare("SELECT * FROM clients WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $clientId);
    $stmt->execute();
    $clientRow = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

$q = trim((string)($_GET['q'] ?? ''));
$roleFilter = trim((string)($_GET['role'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$sort = trim((string)($_GET['sort'] ?? 'name'));
$dir = strtolower((string)($_GET['dir'] ?? 'asc'));
$pageSize = (int)($_GET['ps'] ?? 12);
$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = in_array($pageSize, [12,24,48], true) ? $pageSize : 12;
$offset = ($page - 1) * $pageSize;

$allowedRoles = ['client_admin','client_sdr'];
$custom = getCustomRolesConfig();
foreach ($custom as $rk => $rv) {
    if (!is_array($rv)) continue;
    if ((string)($rv['scope'] ?? '') !== 'client') continue;
    $rk = normalizeRole((string)$rk);
    if ($rk !== '' && !in_array($rk, $allowedRoles, true)) $allowedRoles[] = $rk;
}
$allowedSort = ['name','role','status','email'];
if (!in_array($roleFilter, $allowedRoles, true)) $roleFilter = '';
if (!in_array($sort, $allowedSort, true)) $sort = 'name';
$dirSql = ($dir === 'desc') ? 'DESC' : 'ASC';
$orderSql = ($sort === 'role') ? "role $dirSql, full_name ASC"
          : (($sort === 'status') ? "is_active $dirSql, full_name ASC"
          : (($sort === 'email') ? "email $dirSql, full_name ASC"
          : "full_name $dirSql"));

$users = [];
$total = 0;
if ($clientRow) {
    $params = [];
    $types = '';
    $where = "WHERE client_id = ?";
    $params[] = $clientId; $types .= 'i';
    if ($q !== '') {
        $where .= " AND (full_name LIKE ? OR username LIKE ? OR email LIKE ?)";
        $like = '%'.$q.'%';
        $params[] = $like; $types .= 's';
        $params[] = $like; $types .= 's';
        $params[] = $like; $types .= 's';
    }
    if ($roleFilter !== '') {
        $where .= " AND role = ?";
        $params[] = $roleFilter; $types .= 's';
    }
    if ($statusFilter !== '') {
        $where .= " AND is_active = ?";
        $params[] = ($statusFilter === 'active') ? 1 : 0; $types .= 'i';
    }
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM users $where");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rowc = $stmt->get_result()->fetch_assoc() ?: ['cnt' => 0];
    $stmt->close();
    $total = (int)($rowc['cnt'] ?? 0);
    $pages = max(1, (int)ceil($total / $pageSize));

    $sql = "SELECT id, full_name, username, email, job_title, role, is_active, profile_pic FROM users $where ORDER BY $orderSql LIMIT $pageSize OFFSET $offset";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$pageTitle = 'Client Users';
include __DIR__ . '/../../includes/layout/app_start.php';
?>
<div class="container-fluid px-0">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-1">Client Users</h3>
      <div class="text-muted small">Manage users for a client account.</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="clients.php"><i class="bi bi-arrow-left me-1"></i>Back</a>
      <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#createUserModal"><i class="bi bi-person-plus me-1"></i>Add User</button>
    </div>
  </div>

  <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <?php if (!$clientRow): ?>
        <div class="alert alert-warning">Select a client to view users.</div>
      <?php else: ?>
        <div class="d-flex align-items-start justify-content-between mb-3">
          <div>
            <div class="fw-semibold fs-5"><?php echo htmlspecialchars($clientRow['name']); ?></div>
            <div class="text-muted small"><?php echo htmlspecialchars($clientRow['client_code']); ?><?php echo !empty($clientRow['industry']) ? ' · '.htmlspecialchars($clientRow['industry']) : ''; ?></div>
          </div>
          <?php if (!empty($clientRow['website'])): ?>
            <div><a class="small text-decoration-none" href="<?php echo htmlspecialchars($clientRow['website']); ?>" target="_blank"><i class="bi bi-globe me-1"></i>Website</a></div>
          <?php endif; ?>
        </div>
        <form class="row g-2 align-items-end" method="get">
          <input type="hidden" name="client_id" value="<?php echo (int)$clientId; ?>">
          <div class="col-lg-4">
            <label class="form-label small text-muted">Search</label>
            <input class="form-control form-control-sm" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Name, username, email">
          </div>
          <div class="col-lg-3">
            <label class="form-label small text-muted">Role</label>
            <select class="form-select form-select-sm" name="role">
              <option value="">All</option>
              <option value="client_admin" <?php echo $roleFilter==='client_admin'?'selected':''; ?>>Client Admin</option>
              <option value="client_sdr" <?php echo $roleFilter==='client_sdr'?'selected':''; ?>>Client SDR</option>
              <?php
                $customClient = [];
                $custom = getCustomRolesConfig();
                foreach ($custom as $rk => $rv) {
                    if (!is_array($rv)) continue;
                    if ((string)($rv['scope'] ?? '') !== 'client') continue;
                    $rk = normalizeRole((string)$rk);
                    $lbl = trim((string)($rv['label'] ?? ''));
                    if ($rk !== '' && $lbl !== '') $customClient[$rk] = $lbl;
                }
                if (!empty($customClient)) {
                    asort($customClient);
                    foreach ($customClient as $rk => $lbl) {
                        echo '<option value="' . htmlspecialchars($rk) . '" ' . ($roleFilter === $rk ? 'selected' : '') . '>' . htmlspecialchars($lbl) . '</option>';
                    }
                }
              ?>
            </select>
          </div>
          <div class="col-lg-2">
            <label class="form-label small text-muted">Status</label>
            <select class="form-select form-select-sm" name="status">
              <option value="">All</option>
              <option value="active" <?php echo $statusFilter==='active'?'selected':''; ?>>Active</option>
              <option value="inactive" <?php echo $statusFilter==='inactive'?'selected':''; ?>>Inactive</option>
            </select>
          </div>
          <div class="col-lg-2">
            <label class="form-label small text-muted">Sort</label>
            <select class="form-select form-select-sm" name="sort">
              <option value="name" <?php echo $sort==='name'?'selected':''; ?>>Name</option>
              <option value="role" <?php echo $sort==='role'?'selected':''; ?>>Role</option>
              <option value="status" <?php echo $sort==='status'?'selected':''; ?>>Status</option>
              <option value="email" <?php echo $sort==='email'?'selected':''; ?>>Email</option>
            </select>
          </div>
          <div class="col-lg-1">
            <label class="form-label small text-muted">Dir</label>
            <select class="form-select form-select-sm" name="dir">
              <option value="asc" <?php echo $dir==='asc'?'selected':''; ?>>Asc</option>
              <option value="desc" <?php echo $dir==='desc'?'selected':''; ?>>Desc</option>
            </select>
          </div>
          <div class="col-lg-1">
            <label class="form-label small text-muted">Page Size</label>
            <select class="form-select form-select-sm" name="ps">
              <option value="12" <?php echo $pageSize===12?'selected':''; ?>>12</option>
              <option value="24" <?php echo $pageSize===24?'selected':''; ?>>24</option>
              <option value="48" <?php echo $pageSize===48?'selected':''; ?>>48</option>
            </select>
          </div>
          <div class="col-lg-1 d-grid">
            <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-search me-1"></i>Apply</button>
          </div>
        </form>
        <div class="table-responsive mt-3">
          <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>
                  <a class="text-decoration-none" href="<?php echo 'client-users.php?client_id='.(int)$clientId.'&q='.urlencode($q).'&role='.urlencode($roleFilter).'&status='.urlencode($statusFilter).'&sort=name&dir='.($sort==='name'&&$dir==='asc'?'desc':'asc').'&ps='.$pageSize.'&page=1'; ?>">User</a>
                </th>
                <th>
                  <a class="text-decoration-none" href="<?php echo 'client-users.php?client_id='.(int)$clientId.'&q='.urlencode($q).'&role='.urlencode($roleFilter).'&status='.urlencode($statusFilter).'&sort=role&dir='.($sort==='role'&&$dir==='asc'?'desc':'asc').'&ps='.$pageSize.'&page=1'; ?>">Job Title</a>
                </th>
                <th>
                  <a class="text-decoration-none" href="<?php echo 'client-users.php?client_id='.(int)$clientId.'&q='.urlencode($q).'&role='.urlencode($roleFilter).'&status='.urlencode($statusFilter).'&sort=status&dir='.($sort==='status'&&$dir==='asc'?'desc':'asc').'&ps='.$pageSize.'&page=1'; ?>">Status</a>
                </th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($users)): ?>
                <tr><td colspan="4" class="text-center text-muted py-4">No users found.</td></tr>
              <?php else: ?>
                <?php foreach ($users as $u): ?>
                  <tr>
                    <td>
                      <div class="d-flex align-items-center gap-2">
                        <?php if (!empty($u['profile_pic'])): ?>
                          <img src="../../<?php echo htmlspecialchars($u['profile_pic']); ?>" style="height:28px;width:28px;border-radius:50%;object-fit:cover;">
                        <?php else: ?>
                          <div class="d-inline-flex align-items-center justify-content-center" style="height:28px;width:28px;border-radius:50%;background:#eef2ff;color:#4338ca;font-weight:600;"><?php echo strtoupper(substr($u['full_name'] ?? 'U', 0, 1)); ?></div>
                        <?php endif; ?>
                        <div>
                          <div class="fw-semibold"><?php echo htmlspecialchars($u['full_name'] ?? 'User'); ?></div>
                          <div class="text-muted small">@<?php echo htmlspecialchars($u['username'] ?? 'username'); ?> · <?php echo htmlspecialchars($u['email'] ?? ''); ?></div>
                        </div>
                      </div>
                    </td>
                    <td class="text-muted small"><?php echo htmlspecialchars((string)($u['job_title'] ?? '')); ?></td>
                    <td>
                      <?php if (!empty($u['is_active'])): ?>
                        <span class="badge bg-success-subtle text-success border border-success">Active</span>
                      <?php else: ?>
                        <span class="badge bg-secondary-subtle text-secondary border border-secondary">Inactive</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end">
                      <div class="d-flex justify-content-end gap-1">
                        <button class="btn btn-outline-primary btn-xs px-2" data-action="assign-campaigns" data-user-id="<?php echo (int)$u['id']; ?>" title="Assign Campaigns">
                          <i class="bi bi-collection"></i> <span class="d-none d-xl-inline">Assign</span>
                        </button>
                        <a class="btn btn-outline-secondary btn-xs px-2" href="../auth/reset-password.php?user_id=<?php echo (int)$u['id']; ?>" title="Reset Password">
                          <i class="bi bi-key"></i> <span class="d-none d-xl-inline">Reset</span>
                        </a>
                        <form method="post" class="d-inline">
                          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                          <input type="hidden" name="action" value="set_user_active_client">
                          <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                          <input type="hidden" name="client_id" value="<?php echo (int)$clientId; ?>">
                          <input type="hidden" name="is_active" value="<?php echo !empty($u['is_active']) ? 0 : 1; ?>">
                          <button class="btn btn-<?php echo !empty($u['is_active']) ? 'outline-danger' : 'outline-success'; ?> btn-xs px-2" type="submit" title="<?php echo !empty($u['is_active']) ? 'Deactivate' : 'Activate'; ?> User">
                            <?php if (!empty($u['is_active'])): ?>
                              <i class="bi bi-person-x"></i> <span class="d-none d-xl-inline">Deactivate</span>
                            <?php else: ?>
                              <i class="bi bi-person-check"></i> <span class="d-none d-xl-inline">Activate</span>
                            <?php endif; ?>
                          </button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php if (!empty($users)): ?>
          <nav class="mt-2">
            <ul class="pagination pagination-sm mb-0">
              <?php
                $cur = (int)$page;
                $totalPages = (int)$pages;
                $buildUrl = function($p) use ($clientId, $q, $roleFilter, $statusFilter, $sort) {
                  return 'client-users.php?client_id='.$clientId.'&q='.urlencode($q).'&role='.urlencode($roleFilter).'&status='.urlencode($statusFilter).'&sort='.urlencode($sort).'&page='.(int)$p;
                };
              ?>
              <li class="page-item <?php echo $cur <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo $buildUrl(max(1, $cur-1)); ?>">Prev</a></li>
              <li class="page-item disabled"><span class="page-link"><?php echo $cur; ?> / <?php echo $totalPages; ?></span></li>
              <li class="page-item <?php echo $cur >= $totalPages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo $buildUrl(min($totalPages, $cur+1)); ?>">Next</a></li>
            </ul>
          </nav>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="modal fade" id="createUserModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="create_client_user">
        <input type="hidden" name="client_id" value="<?php echo (int)$clientId; ?>">
        <div class="modal-header">
          <h5 class="modal-title">Create Client User</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label small text-muted">Username</label>
              <input class="form-control form-control-sm" name="username" required>
            </div>
            <div class="col-md-6">
              <label class="form-label small text-muted">Full Name</label>
              <input class="form-control form-control-sm" name="full_name" required>
            </div>
            <div class="col-md-8">
              <label class="form-label small text-muted">Email</label>
              <input class="form-control form-control-sm" type="email" name="email" required>
            </div>
            <div class="col-md-4">
              <label class="form-label small text-muted">Role</label>
              <select class="form-select form-select-sm" name="role">
                <option value="client_admin">Client Admin</option>
                <option value="client_sdr" selected>Client SDR</option>
                <?php
                  if (!empty($customClient)) {
                      foreach ($customClient as $rk => $lbl) {
                          echo '<option value="' . htmlspecialchars($rk) . '">' . htmlspecialchars($lbl) . '</option>';
                      }
                  }
                ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small text-muted">Password</label>
              <input class="form-control form-control-sm" name="password" type="text" placeholder="Auto if blank">
            </div>
            <div class="col-md-6 d-flex align-items-end">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_active" id="cuActive" checked>
                <label class="form-check-label small" for="cuActive">Active</label>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Create</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="assignCampaignsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Assign Campaigns</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="assignCampaignsForm" class="row g-2">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
          <input type="hidden" name="action" value="save_client_user_campaigns">
          <input type="hidden" name="client_id" value="<?php echo (int)$clientId; ?>">
          <input type="hidden" name="user_id" id="assign_user_id" value="0">
          <div id="assignCampaignsList" class="col-12"></div>
          <div class="col-12 d-flex justify-content-end">
            <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-check2-circle me-1"></i>Save</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
<script>
(function(){
  const token = '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>';
  const clientId = <?php echo (int)$clientId; ?>;
  const pagePath = (window.location.pathname || '').replace(/\/$/, '');
  document.addEventListener('click', function(ev){
    const btn = ev.target.closest('[data-action="assign-campaigns"]');
    if (!btn) return;
    const uid = btn.getAttribute('data-user-id');
    const mEl = document.getElementById('assignCampaignsModal');
    if (!uid || !mEl) return;
    document.getElementById('assign_user_id').value = uid;
    const wrap = document.getElementById('assignCampaignsList');
    wrap.innerHTML = '<div class="text-muted small">Loading campaigns…</div>';
    const fd = new FormData();
    fd.append('csrf_token', token);
    fd.append('action', 'list_client_user_campaigns');
    fd.append('client_id', String(clientId));
    fd.append('user_id', String(uid));
    fetch(pagePath + '?client_id='+clientId, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, credentials:'same-origin', body: fd })
    .then(r=>r.json()).then(d=>{
      if (d && d.ok) {
        const cs = Array.isArray(d.campaigns) ? d.campaigns : [];
        const assigned = {};
        (Array.isArray(d.assigned)?d.assigned:[]).forEach(id=>assigned[String(id)] = true);
        if (cs.length) {
          wrap.innerHTML = '<div class="row g-2"></div>';
          const row = wrap.firstChild;
          cs.forEach(c=>{
            const col = document.createElement('div'); col.className='col-md-6';
            col.innerHTML = '<div class="form-check"><input class="form-check-input" type="checkbox" name="campaign_ids[]" value="'+String(c.id)+'" id="c_'+String(c.id)+'" '+(assigned[String(c.id)]?'checked':'')+'>'
              + '<label class="form-check-label" for="c_'+String(c.id)+'">'+eh(c.name||'')+' <span class="text-muted small">['+eh(c.code||'')+']</span></label></div>';
            row.appendChild(col);
          });
          const modal = new bootstrap.Modal(mEl);
          modal.show();
        } else {
          wrap.innerHTML = '<div class="alert alert-warning mb-0">No campaigns available for this client.</div>';
          const modal = new bootstrap.Modal(mEl);
          modal.show();
        }
      } else {
        wrap.innerHTML = '<div class="text-danger small">'+eh(d?.error||'Failed to load')+'</div>';
      }
    }).catch(()=>{ wrap.innerHTML = '<div class="text-danger small">Failed to load</div>'; });
  });
  document.getElementById('assignCampaignsForm')?.addEventListener('submit', function(ev){
    ev.preventDefault();
    const fd = new FormData(ev.currentTarget);
    fetch(pagePath + '?client_id='+clientId, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, credentials:'same-origin', body: fd })
    .then(r=>r.json()).then(d=>{
      if (d && d.ok) {
        document.querySelector('#assignCampaignsModal .btn-close')?.click();
        if (window.appNotify) window.appNotify('success', 'Assignments saved', 'Success');
      } else {
        if (window.appNotify) window.appNotify('danger', d?.error || 'Failed', 'Error');
        else alert(d?.error || 'Failed');
      }
    }).catch(()=>{ if (window.appNotify) window.appNotify('danger', 'Network error', 'Error'); });
  });
  function eh(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\"/g,'&quot;').replace(/\\'/g,'&#39;');}
})();
</script>
