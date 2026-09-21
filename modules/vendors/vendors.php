<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin','director','manager_director']);
ensureCsrfToken();

$conn = getDbConnection();
$user = getCurrentUser();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid token.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'create_vendor_user') {
                $vendorId = (int)($_POST['vendor_id'] ?? 0);
                $username = trim((string)($_POST['username'] ?? ''));
                $fullName = trim((string)($_POST['full_name'] ?? ''));
                $email = trim((string)($_POST['email'] ?? ''));
                $role = (string)($_POST['role'] ?? 'vendor_user');
                $isActive = isset($_POST['is_active']) ? 1 : 0;
                $password = (string)($_POST['password'] ?? '');
                if ($vendorId <= 0) throw new RuntimeException('Select a vendor first.');
                if ($username === '' || $fullName === '' || $email === '') throw new RuntimeException('Username, Full Name, Email are required.');
                if (!in_array($role, ['vendor_admin','vendor_user'], true)) throw new RuntimeException('Invalid role for Vendor user.');
                if ($password === '') $password = bin2hex(random_bytes(4));
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $conn = getDbConnection();
                $chk = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                $chk->bind_param('s', $username);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) throw new RuntimeException('Username already exists.');
                $chk->close();
                $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, email, role, is_active, client_id, vendor_id) VALUES (?,?,?,?,?,?,0,?)");
                $stmt->bind_param('sssssii', $username, $hashed, $fullName, $email, $role, $isActive, $vendorId);
                if (!$stmt->execute()) throw new RuntimeException('Failed to create vendor user.');
                $stmt->close();
                $success = 'Vendor user created.';
            } elseif ($action === 'set_user_active_vendor') {
                $uid = (int)($_POST['user_id'] ?? 0);
                $vid = (int)($_POST['vendor_id'] ?? 0);
                $active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 0;
                if ($uid <= 0 || $vid <= 0) throw new RuntimeException('Invalid request.');
                $conn = getDbConnection();
                $stmt = $conn->prepare("SELECT vendor_id FROM users WHERE id = ? LIMIT 1");
                $stmt->bind_param('i', $uid);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc() ?: null;
                $stmt->close();
                if (!$row || (int)$row['vendor_id'] !== $vid) throw new RuntimeException('Not allowed.');
                $st = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ?");
                $st->bind_param('ii', $active, $uid);
                if (!$st->execute()) throw new RuntimeException('Failed to update status.');
                $st->close();
                $success = $active ? 'User activated.' : 'User deactivated.';
            } elseif ($action === 'save_vendor') {
                $vendorId = (int)($_POST['vendor_id'] ?? 0);
                $vendorCode = trim((string)($_POST['vendor_code'] ?? ''));
                $name = trim((string)($_POST['name'] ?? ''));
                $website = trim((string)($_POST['website'] ?? ''));
                $contactName = trim((string)($_POST['contact_name'] ?? ''));
                $contactEmail = trim((string)($_POST['contact_email'] ?? ''));
                $contactPhone = trim((string)($_POST['contact_phone'] ?? ''));
                $country = trim((string)($_POST['country'] ?? ''));
                $notes = trim((string)($_POST['notes'] ?? ''));
                $isActive = isset($_POST['is_active']) ? 1 : 0;

                if ($vendorCode === '' || $name === '') {
                    throw new RuntimeException('Vendor Code and Name are required.');
                }

                if ($vendorId > 0) {
                    $updatedBy = (int)($user['id'] ?? 0);
                    $stmt = $conn->prepare("UPDATE vendors SET vendor_code = ?, name = ?, website = ?, contact_name = ?, contact_email = ?, contact_phone = ?, country = ?, notes = ?, is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->bind_param('ssssssssiii', $vendorCode, $name, $website, $contactName, $contactEmail, $contactPhone, $country, $notes, $isActive, $updatedBy, $vendorId);
                    if (!$stmt->execute()) throw new RuntimeException('Failed to update vendor.');
                    $stmt->close();
                } else {
                    $createdBy = (int)($user['id'] ?? 0);
                    $stmt = $conn->prepare("INSERT INTO vendors (vendor_code, name, website, contact_name, contact_email, contact_phone, country, notes, is_active, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
                    $stmt->bind_param('ssssssssii', $vendorCode, $name, $website, $contactName, $contactEmail, $contactPhone, $country, $notes, $isActive, $createdBy);
                    if (!$stmt->execute()) throw new RuntimeException('Failed to create vendor.');
                    $stmt->close();
                }

                $success = 'Vendor saved.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$vendorViewId = (int)($_GET['vendor_id'] ?? 0);
$vendors = [];
if ($q !== '') {
    $like = '%'.$q.'%';
    $stmt = $conn->prepare("SELECT * FROM vendors WHERE vendor_code LIKE ? OR name LIKE ? OR website LIKE ? OR contact_email LIKE ? ORDER BY name");
    $stmt->bind_param('ssss', $like, $like, $like, $like);
    $stmt->execute();
    $vendors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
} else {
    $rs = $conn->query("SELECT * FROM vendors ORDER BY name");
    $vendors = $rs ? ($rs->fetch_all(MYSQLI_ASSOC) ?: []) : [];
}

$pageTitle = 'Vendors';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0">
  <div class="d-flex align-items-start justify-content-between mb-3">
    <div>
      <h3 class="mb-1">Vendors</h3>
      <div class="text-muted small">Manage vendor accounts for campaign delivery.</div>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#vendorModal" onclick="openVendorModal()">
        <i class="bi bi-plus-circle me-1"></i>Add Vendor
      </button>
      <?php if (!empty($vendors)): ?>
      <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#vendorUserModal">
        <i class="bi bi-person-plus me-1"></i>Add Vendor Login User
      </button>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <form class="row g-2 align-items-end" method="get">
        <div class="col-md-10">
          <label class="form-label">Search</label>
          <input class="form-control form-control-sm" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Vendor code, name, website, email">
        </div>
        <div class="col-md-2 d-grid">
          <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-search me-1"></i>Apply</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card border-0 shadow-sm">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Vendor</th>
            <th>Contact</th>
            <th class="text-muted">Country</th>
            <th class="text-end">Status</th>
            <th class="text-end">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($vendors)): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">No vendors found.</td></tr>
          <?php else: ?>
            <?php foreach ($vendors as $v): ?>
              <tr>
                <td>
                  <div class="fw-semibold"><?php echo htmlspecialchars((string)($v['name'] ?? '')); ?></div>
                  <div class="text-muted small"><?php echo htmlspecialchars((string)($v['vendor_code'] ?? '')); ?><?php echo !empty($v['website']) ? ' · '.htmlspecialchars((string)$v['website']) : ''; ?></div>
                  <a class="small text-decoration-none" href="vendors.php?vendor_id=<?php echo (int)$v['id']; ?>">Open</a>
                </td>
                <td class="text-muted small">
                  <?php echo htmlspecialchars((string)($v['contact_name'] ?? '')); ?>
                  <?php if (!empty($v['contact_email'])): ?><div><?php echo htmlspecialchars((string)$v['contact_email']); ?></div><?php endif; ?>
                  <?php if (!empty($v['contact_phone'])): ?><div><?php echo htmlspecialchars((string)$v['contact_phone']); ?></div><?php endif; ?>
                </td>
                <td class="text-muted small"><?php echo htmlspecialchars((string)($v['country'] ?? '')); ?></td>
                <td class="text-end">
                  <?php if (!empty($v['is_active'])): ?>
                    <span class="badge bg-success-subtle text-success border">Active</span>
                  <?php else: ?>
                    <span class="badge bg-secondary-subtle text-secondary border">Inactive</span>
                  <?php endif; ?>
                </td>
                <td class="text-end">
                  <div class="btn-group btn-group-sm">
                    <a class="btn btn-light border" href="vendor-users.php?vendor_id=<?php echo (int)$v['id']; ?>"><i class="bi bi-people"></i> Users</a>
                    <button class="btn btn-light border" type="button" data-bs-toggle="modal" data-bs-target="#vendorModal" onclick="openVendorModal(<?php echo (int)$v['id']; ?>)"><i class="bi bi-pencil"></i></button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php
    $vendorRow = null;
    $vendorUsers = [];
    if ($vendorViewId > 0) {
        $stmt = $conn->prepare("SELECT * FROM vendors WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $vendorViewId);
        $stmt->execute();
        $vendorRow = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($vendorRow) {
            $userQ = trim((string)($_GET['user_q'] ?? ''));
            $userPage = max(1, (int)($_GET['user_page'] ?? 1));
            $pageSize = 10;
            $offset = ($userPage - 1) * $pageSize;
            if ($userQ !== '') {
                $like = '%'.$userQ.'%';
                $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM users WHERE vendor_id = ? AND (full_name LIKE ? OR username LIKE ? OR email LIKE ?)");
                $stmt->bind_param('isss', $vendorViewId, $like, $like, $like);
            } else {
                $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM users WHERE vendor_id = ?");
                $stmt->bind_param('i', $vendorViewId);
            }
            $stmt->execute();
            $rowc = $stmt->get_result()->fetch_assoc() ?: ['cnt' => 0];
            $stmt->close();
            $vendorUsersTotal = (int)($rowc['cnt'] ?? 0);
            $vendorUsersPages = max(1, (int)ceil($vendorUsersTotal / $pageSize));
            if ($userQ !== '') {
                $like = '%'.$userQ.'%';
                $sqlu = "SELECT id, full_name, username, email, job_title, role, is_active, profile_pic FROM users WHERE vendor_id = ? AND (full_name LIKE ? OR username LIKE ? OR email LIKE ?) ORDER BY role, full_name LIMIT $pageSize OFFSET $offset";
                $stmt = $conn->prepare($sqlu);
                $stmt->bind_param('isss', $vendorViewId, $like, $like, $like);
            } else {
                $sqlu = "SELECT id, full_name, username, email, job_title, role, is_active, profile_pic FROM users WHERE vendor_id = ? ORDER BY role, full_name LIMIT $pageSize OFFSET $offset";
                $stmt = $conn->prepare($sqlu);
                $stmt->bind_param('i', $vendorViewId);
            }
            $stmt->execute();
            $vendorUsers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmt->close();
        }
    }
  ?>
  <div class="card border-0 shadow-sm mt-3">
    <div class="card-header">Vendor Details</div>
    <div class="card-body">
      <?php if (!$vendorRow): ?>
        <div class="text-muted">Select a vendor to view details and users.</div>
      <?php else: ?>
        <div class="d-flex align-items-start justify-content-between mb-2">
          <div>
            <div class="fw-semibold fs-5"><?php echo htmlspecialchars($vendorRow['name']); ?></div>
            <div class="text-muted small"><?php echo htmlspecialchars($vendorRow['vendor_code']); ?><?php echo !empty($vendorRow['country']) ? ' · '.htmlspecialchars($vendorRow['country']) : ''; ?></div>
          </div>
          <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#vendorModal" onclick="openVendorModal(<?php echo (int)$vendorRow['id']; ?>)"><i class="bi bi-pencil"></i> Edit</button>
        </div>
        
        <div class="row g-3 mt-2">
            <?php if (!empty($vendorRow['website'])): ?>
            <div class="col-sm-6">
                <div class="text-muted small">Website</div>
                <a href="<?php echo htmlspecialchars($vendorRow['website']); ?>" target="_blank" class="text-decoration-none fw-medium"><i class="bi bi-globe me-1"></i><?php echo htmlspecialchars($vendorRow['website']); ?></a>
            </div>
            <?php endif; ?>
            <?php if (!empty($vendorRow['notes'])): ?>
            <div class="col-12">
                <div class="text-muted small">Notes</div>
                <div class="bg-light rounded p-2 small border"><?php echo nl2br(htmlspecialchars($vendorRow['notes'])); ?></div>
            </div>
            <?php endif; ?>
        </div>
        
        <hr class="my-4">
        
        <div class="d-flex align-items-center justify-content-between mb-3" id="vendorUsers">
          <h6 class="fw-bold mb-0"><i class="bi bi-shield-lock me-2"></i>Vendor Logins</h6>
          <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#vendorUserModal"><i class="bi bi-person-plus"></i> New Login</button>
        </div>
        <form class="row g-2 mb-3" method="get">
          <input type="hidden" name="vendor_id" value="<?php echo (int)$vendorRow['id']; ?>">
          <div class="col-md-9">
            <input class="form-control form-control-sm" name="user_q" value="<?php echo htmlspecialchars($_GET['user_q'] ?? ''); ?>" placeholder="Search vendor users by name, username, email...">
          </div>
          <div class="col-md-3 d-grid">
            <button class="btn btn-outline-secondary btn-sm" type="submit"><i class="bi bi-search"></i></button>
          </div>
        </form>
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle border mb-0">
            <thead class="table-light">
              <tr>
                <th>User</th>
                <th>Job Title</th>
                <th>Status</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($vendorUsers)): ?>
                <tr><td colspan="4" class="text-center text-muted py-4">No login accounts created yet.</td></tr>
              <?php else: ?>
                <?php foreach ($vendorUsers as $uu): ?>
                  <tr>
                    <td>
                      <div class="d-flex align-items-center gap-2">
                        <?php if (!empty($uu['profile_pic'])): ?>
                          <img src="../../<?php echo htmlspecialchars($uu['profile_pic']); ?>" style="height:32px;width:32px;border-radius:50%;object-fit:cover;">
                        <?php else: ?>
                          <div class="d-inline-flex align-items-center justify-content-center bg-primary text-white" style="height:32px;width:32px;border-radius:50%;font-weight:600;"><?php echo strtoupper(substr($uu['full_name'] ?? 'U', 0, 1)); ?></div>
                        <?php endif; ?>
                        <div>
                          <div class="fw-bold"><?php echo htmlspecialchars($uu['full_name'] ?? 'User'); ?></div>
                          <div class="text-muted" style="font-size: 0.75rem;">@<?php echo htmlspecialchars($uu['username'] ?? 'username'); ?> · <?php echo htmlspecialchars($uu['email'] ?? ''); ?></div>
                        </div>
                      </div>
                    </td>
                    <td class="text-muted small"><?php echo htmlspecialchars((string)($uu['job_title'] ?? '')); ?></td>
                    <td>
                      <?php if (!empty($uu['is_active'])): ?>
                        <span class="badge bg-success-subtle text-success border border-success"><i class="bi bi-check-circle me-1"></i>Active</span>
                      <?php else: ?>
                        <span class="badge bg-secondary-subtle text-secondary border border-secondary"><i class="bi bi-x-circle me-1"></i>Inactive</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <div class="dropdown">
                            <button class="btn btn-sm btn-light border dropdown-toggle" data-bs-toggle="dropdown">Actions</button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                <li><a class="dropdown-item py-2 text-primary" href="../users/manage-users.php?search=<?php echo urlencode($uu['username']); ?>"><i class="bi bi-pencil me-2"></i>Edit User Profile</a></li>
                                <li><a class="dropdown-item py-2 text-warning" href="../auth/reset-password.php?user_id=<?php echo (int)$uu['id']; ?>"><i class="bi bi-key me-2"></i>Reset Password</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <form method="post" class="m-0 p-0 d-inline w-100">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action" value="set_user_active_vendor">
                                        <input type="hidden" name="user_id" value="<?php echo (int)$uu['id']; ?>">
                                        <input type="hidden" name="vendor_id" value="<?php echo (int)$vendorRow['id']; ?>">
                                        <input type="hidden" name="is_active" value="<?php echo !empty($uu['is_active']) ? 0 : 1; ?>">
                                        <button type="submit" class="dropdown-item py-2 <?php echo !empty($uu['is_active']) ? 'text-danger' : 'text-success'; ?>">
                                            <?php if (!empty($uu['is_active'])): ?><i class="bi bi-person-x me-2"></i>Deactivate<?php else: ?><i class="bi bi-person-check me-2"></i>Activate<?php endif; ?>
                                        </button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php if (!empty($vendorUsers)): ?>
          <nav class="mt-2">
            <ul class="pagination pagination-sm mb-0">
              <?php
                $cur = (int)($_GET['user_page'] ?? 1);
                $totalPages = isset($vendorUsersPages) ? (int)$vendorUsersPages : 1;
                $makeUrl = function($p) use ($vendorRow) {
                  $q = urlencode((string)($_GET['user_q'] ?? ''));
                  return 'vendors.php?vendor_id='.(int)$vendorRow['id'].'&user_q='.$q.'&user_page='.(int)$p;
                };
              ?>
              <li class="page-item <?php echo $cur <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo $makeUrl(max(1, $cur-1)); ?>">Prev</a></li>
              <li class="page-item disabled"><span class="page-link"><?php echo $cur; ?> / <?php echo $totalPages; ?></span></li>
              <li class="page-item <?php echo $cur >= $totalPages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo $makeUrl(min($totalPages, $cur+1)); ?>">Next</a></li>
            </ul>
          </nav>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="modal fade" id="vendorUserModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="create_vendor_user">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title"><i class="bi bi-person-plus-fill me-2"></i>Create Vendor Login</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4">
          <div class="alert alert-light border mb-4">
            <div class="small text-muted"><i class="bi bi-info-circle me-1"></i>Create an external access account for a vendor. They will only see campaigns and leads assigned to them.</div>
          </div>
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-bold small">Vendor *</label>
              <select class="form-select" name="vendor_id" required>
                <option value="">Select Vendor</option>
                <?php foreach ($vendors as $v): ?>
                  <option value="<?php echo (int)$v['id']; ?>"><?php echo htmlspecialchars(($v['name'] ?? '').' ['.($v['vendor_code'] ?? '').']'); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold small">Full Name *</label>
              <input class="form-control" name="full_name" required placeholder="e.g. Jane Doe">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold small">Username *</label>
              <input class="form-control" name="username" required placeholder="Unique ID">
            </div>
            <div class="col-md-12">
              <label class="form-label fw-bold small">Email Address *</label>
              <input class="form-control" name="email" type="email" required placeholder="jane@example.com">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold small">Role</label>
              <select class="form-select" name="role">
                <option value="vendor_admin">Vendor Admin</option>
                <option value="vendor_user" selected>Vendor User</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold small">Initial Password</label>
              <input class="form-control" name="password" type="text" placeholder="Auto-generated if blank">
            </div>
            <div class="col-12 mt-3">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="is_active" id="vendorUserActive" value="1" checked>
                <label class="form-check-label fw-bold small" for="vendorUserActive">Account Active</label>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer bg-light">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary px-4">Create Login</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="vendorModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="save_vendor">
        <input type="hidden" name="vendor_id" id="vendor_id" value="0">
        <div class="modal-header">
          <h5 class="modal-title" id="vendorModalTitle">Add Vendor</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-2">
            <div class="col-md-4">
              <label class="form-label small text-muted">Vendor Code</label>
              <input class="form-control form-control-sm" name="vendor_code" id="vendor_code" required>
            </div>
            <div class="col-md-8">
              <label class="form-label small text-muted">Vendor Name</label>
              <input class="form-control form-control-sm" name="name" id="name" required>
            </div>
            <div class="col-md-6">
              <label class="form-label small text-muted">Website</label>
              <input class="form-control form-control-sm" name="website" id="website" placeholder="https://">
            </div>
            <div class="col-md-6">
              <label class="form-label small text-muted">Country</label>
              <input class="form-control form-control-sm" name="country" id="country">
            </div>
            <div class="col-md-4">
              <label class="form-label small text-muted">Contact Name</label>
              <input class="form-control form-control-sm" name="contact_name" id="contact_name">
            </div>
            <div class="col-md-4">
              <label class="form-label small text-muted">Contact Email</label>
              <input class="form-control form-control-sm" name="contact_email" id="contact_email">
            </div>
            <div class="col-md-4">
              <label class="form-label small text-muted">Contact Phone</label>
              <input class="form-control form-control-sm" name="contact_phone" id="contact_phone">
            </div>
            <div class="col-12">
              <label class="form-label small text-muted">Notes</label>
              <textarea class="form-control form-control-sm" name="notes" id="notes" rows="3"></textarea>
            </div>
            <div class="col-12">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" checked>
                <label class="form-check-label small" for="is_active">Active</label>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
const vendorsData = <?php echo json_encode(array_map(function($v){
    return [
        'id'=>(int)($v['id'] ?? 0),
        'vendor_code'=>$v['vendor_code'] ?? '',
        'name'=>$v['name'] ?? '',
        'website'=>$v['website'] ?? '',
        'country'=>$v['country'] ?? '',
        'contact_name'=>$v['contact_name'] ?? '',
        'contact_email'=>$v['contact_email'] ?? '',
        'contact_phone'=>$v['contact_phone'] ?? '',
        'notes'=>$v['notes'] ?? '',
        'is_active'=>(int)($v['is_active'] ?? 0),
    ];
}, $vendors), JSON_UNESCAPED_UNICODE); ?>;

function openVendorModal(id){
  const mTitle = document.getElementById('vendorModalTitle');
  const vId = document.getElementById('vendor_id');
  const code = document.getElementById('vendor_code');
  const name = document.getElementById('name');
  const website = document.getElementById('website');
  const country = document.getElementById('country');
  const contactName = document.getElementById('contact_name');
  const contactEmail = document.getElementById('contact_email');
  const contactPhone = document.getElementById('contact_phone');
  const notes = document.getElementById('notes');
  const active = document.getElementById('is_active');
  const row = vendorsData.find(x => x.id === Number(id));
  if(row){
    mTitle.textContent = 'Edit Vendor';
    vId.value = row.id;
    code.value = row.vendor_code;
    name.value = row.name;
    website.value = row.website;
    country.value = row.country;
    contactName.value = row.contact_name;
    contactEmail.value = row.contact_email;
    contactPhone.value = row.contact_phone;
    notes.value = row.notes;
    active.checked = row.is_active === 1;
  }else{
    mTitle.textContent = 'Add Vendor';
    vId.value = 0;
    code.value = '';
    name.value = '';
    website.value = '';
    country.value = '';
    contactName.value = '';
    contactEmail.value = '';
    contactPhone.value = '';
    notes.value = '';
    active.checked = true;
  }
}
</script>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
