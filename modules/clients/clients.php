<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin','director','manager_director','sales_director','sales_manager']);
ensureCsrfToken();

$conn = getDbConnection();
$user = getCurrentUser();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid token.';
    } else {
        $action = $_POST['action'] ?? '';
        if (function_exists('isAjaxRequest') && isAjaxRequest() && in_array((string)$action, ['get_client_users','delete_client_user','set_user_active'], true)) {
            header('Content-Type: application/json; charset=utf-8');
            try {
                $clientId = (int)($_POST['client_id'] ?? 0);
                if ($clientId <= 0) throw new RuntimeException('Invalid client');

                if ($action === 'get_client_users') {
                    $stmt = $conn->prepare("SELECT id, full_name, username, email, job_title, role, is_active, profile_pic FROM users WHERE client_id = ? ORDER BY role, full_name");
                    if (!$stmt) throw new RuntimeException('Database error');
                    $stmt->bind_param('i', $clientId);
                    $stmt->execute();
                    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                    $stmt->close();
                    echo json_encode(['ok' => true, 'users' => $users], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    exit;
                }

                if ($action === 'set_user_active') {
                    $uid = (int)($_POST['user_id'] ?? 0);
                    $active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 0;
                    if ($uid <= 0) throw new RuntimeException('Invalid user');
                    $stmt = $conn->prepare("SELECT client_id FROM users WHERE id = ? LIMIT 1");
                    if (!$stmt) throw new RuntimeException('Database error');
                    $stmt->bind_param('i', $uid);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc() ?: null;
                    $stmt->close();
                    if (!$row || (int)$row['client_id'] !== $clientId) throw new RuntimeException('Not allowed');
                    $st = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ?");
                    if (!$st) throw new RuntimeException('Database error');
                    $st->bind_param('ii', $active, $uid);
                    if (!$st->execute()) throw new RuntimeException('Failed to update status');
                    $st->close();
                    echo json_encode(['ok' => true]);
                    exit;
                }

                if ($action === 'delete_client_user') {
                    $uid = (int)($_POST['user_id'] ?? 0);
                    if ($uid <= 0) throw new RuntimeException('Invalid user');
                    $stmt = $conn->prepare("SELECT id, client_id FROM users WHERE id = ? LIMIT 1");
                    if (!$stmt) throw new RuntimeException('Database error');
                    $stmt->bind_param('i', $uid);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc() ?: null;
                    $stmt->close();
                    if (!$row || (int)$row['client_id'] !== $clientId) throw new RuntimeException('Not allowed');
                    @$conn->query("DELETE FROM client_sdr_map WHERE client_id = " . (int)$clientId . " AND sdr_user_id = " . (int)$uid);
                    @$conn->query("DELETE FROM campaign_user_assignments WHERE user_id = " . (int)$uid);
                    $del = $conn->prepare("DELETE FROM users WHERE id = ? AND client_id = ? LIMIT 1");
                    if (!$del) throw new RuntimeException('Database error');
                    $del->bind_param('ii', $uid, $clientId);
                    if (!$del->execute()) throw new RuntimeException('Failed to delete user');
                    $del->close();
                    echo json_encode(['ok' => true]);
                    exit;
                }
            } catch (Throwable $e) {
                echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        try {
            if ($action === 'set_user_active') {
                $uid = (int)($_POST['user_id'] ?? 0);
                $cid = (int)($_POST['client_id'] ?? 0);
                $active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 0;
                if ($uid <= 0 || $cid <= 0) throw new RuntimeException('Invalid request.');
                $conn = getDbConnection();
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
            }
            if ($action === 'create_client_user') {
                $clientId = (int)($_POST['client_id'] ?? 0);
                $username = trim((string)($_POST['username'] ?? ''));
                $fullName = trim((string)($_POST['full_name'] ?? ''));
                $email = trim((string)($_POST['email'] ?? ''));
                $role = (string)($_POST['role'] ?? 'client_sdr');
                $isActive = isset($_POST['is_active']) ? 1 : 0;
                $password = (string)($_POST['password'] ?? '');
                if ($clientId <= 0) throw new RuntimeException('Select a client first.');
                if ($username === '' || $fullName === '' || $email === '') throw new RuntimeException('Username, Full Name, Email are required.');
                if (!in_array($role, ['client_admin','client_sdr'], true)) throw new RuntimeException('Invalid role for Client user.');
                if ($password === '') $password = bin2hex(random_bytes(4));
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $conn = getDbConnection();
                $chk = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                $chk->bind_param('s', $username);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) throw new RuntimeException('Username already exists.');
                $chk->close();
                $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, email, role, is_active, client_id, vendor_id) VALUES (?,?,?,?,?,?,?,0)");
                $stmt->bind_param('sssssis', $username, $hashed, $fullName, $email, $role, $isActive, $clientId);
                if (!$stmt->execute()) throw new RuntimeException('Failed to create client user.');
                $newUserId = (int)$conn->insert_id;
                $stmt->close();
                if ($newUserId > 0 && $role === 'client_sdr' && function_exists('syncClientUserCampaignAssignments')) {
                    syncClientUserCampaignAssignments($newUserId, $clientId, (int)($user['id'] ?? 0));
                }
                $success = 'Client user created.';
            } elseif ($action === 'save_client') {
                $clientId = (int)($_POST['client_id'] ?? 0);
                $clientCode = trim((string)($_POST['client_code'] ?? ''));
                $name = trim((string)($_POST['name'] ?? ''));
                $website = trim((string)($_POST['website'] ?? ''));
                $industry = trim((string)($_POST['industry'] ?? ''));
                $country = trim((string)($_POST['country'] ?? ''));
                $notes = trim((string)($_POST['notes'] ?? ''));
                $tagsRaw = trim((string)($_POST['tags'] ?? ''));

                if ($clientCode === '' || $name === '') {
                    throw new RuntimeException('Client Code and Name are required.');
                }

                $websiteDomain = extractDomain($website);

                if ($clientId > 0) {
                    $stmt = $conn->prepare("UPDATE clients SET client_code = ?, name = ?, website = ?, website_domain = ?, industry = ?, country = ?, notes = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->bind_param('sssssssi', $clientCode, $name, $website, $websiteDomain, $industry, $country, $notes, $clientId);
                    if (!$stmt->execute()) throw new RuntimeException('Failed to update client.');
                    $stmt->close();
                } else {
                    $createdBy = (int)($user['id'] ?? 0);
                    $stmt = $conn->prepare("INSERT INTO clients (client_code, name, website, website_domain, industry, country, notes, created_by) VALUES (?,?,?,?,?,?,?,?)");
                    $stmt->bind_param('sssssssi', $clientCode, $name, $website, $websiteDomain, $industry, $country, $notes, $createdBy);
                    if (!$stmt->execute()) throw new RuntimeException('Failed to create client.');
                    $clientId = (int)$conn->insert_id;
                    $stmt->close();
                }

                $tags = array_values(array_filter(array_map(function($t) { return trim($t); }, preg_split('/[,\\n]+/', $tagsRaw) ?: [])));
                $tagIds = [];
                foreach ($tags as $t) {
                    if ($t === '') continue;
                    $ins = $conn->prepare("INSERT IGNORE INTO tags (name) VALUES (?)");
                    $ins->bind_param('s', $t);
                    $ins->execute();
                    $ins->close();

                    $sel = $conn->prepare("SELECT id FROM tags WHERE name = ? LIMIT 1");
                    $sel->bind_param('s', $t);
                    $sel->execute();
                    $row = $sel->get_result()->fetch_assoc();
                    $sel->close();
                    if ($row) $tagIds[] = (int)$row['id'];
                }

                $conn->query("DELETE FROM client_tags WHERE client_id = ".(int)$clientId);
                if (!empty($tagIds)) {
                    $link = $conn->prepare("INSERT IGNORE INTO client_tags (client_id, tag_id) VALUES (?, ?)");
                    foreach ($tagIds as $tid) {
                        $link->bind_param('ii', $clientId, $tid);
                        $link->execute();
                    }
                    $link->close();
                }

                $success = 'Client saved.';
            } elseif ($action === 'add_contact') {
                $clientId = (int)($_POST['client_id'] ?? 0);
                $name = trim((string)($_POST['contact_name'] ?? ''));
                $email = trim((string)($_POST['contact_email'] ?? ''));
                $phone = trim((string)($_POST['contact_phone'] ?? ''));
                $title = trim((string)($_POST['contact_title'] ?? ''));
                if ($clientId <= 0 || $name === '') throw new RuntimeException('Client and contact name are required.');
                $stmt = $conn->prepare("INSERT INTO client_contacts (client_id, name, email, phone, title) VALUES (?,?,?,?,?)");
                $stmt->bind_param('issss', $clientId, $name, $email, $phone, $title);
                if (!$stmt->execute()) throw new RuntimeException('Failed to add contact.');
                $stmt->close();
                $success = 'Contact added.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$clientViewId = (int)($_GET['client_id'] ?? 0);

$whereSql = '';
$params = [];
$types = '';
if ($q !== '') {
    $whereSql = "WHERE c.client_code LIKE ? OR c.name LIKE ? OR c.website LIKE ? OR c.industry LIKE ?";
    $like = '%'.$q.'%';
    $params = [$like, $like, $like, $like];
    $types = 'ssss';
}

$sql = "SELECT c.*,
        (SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ', ')
         FROM client_tags ct JOIN tags t ON t.id = ct.tag_id
         WHERE ct.client_id = c.id) AS tags,
        (SELECT COUNT(*) FROM client_contacts cc WHERE cc.client_id = c.id) AS contacts_count,
        sco.owner_id, sco.manager_id, sco.assigned_at, sco.assigned_by,
        ou.full_name AS owner_name, ou.role AS owner_role, ou.job_title AS owner_job_title,
        mu.full_name AS manager_name, mu.role AS manager_role, mu.job_title AS manager_job_title,
        abu.full_name AS assigned_by_name,
        (SELECT COUNT(*) FROM campaign_details d WHERE d.client_id = c.id) AS campaigns_total,
        (SELECT COUNT(*) FROM campaign_details d WHERE d.client_id = c.id AND d.status = 'Live') AS campaigns_live
        FROM clients c
        LEFT JOIN sales_client_ownership sco ON sco.client_id = c.id
        LEFT JOIN users ou ON ou.id = sco.owner_id
        LEFT JOIN users mu ON mu.id = sco.manager_id
        LEFT JOIN users abu ON abu.id = sco.assigned_by
        $whereSql
        ORDER BY c.created_at DESC";
$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$clients = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

$clientRow = null;
$contacts = [];
$clientUsers = [];
if ($clientViewId > 0) {
    $stmt = $conn->prepare("SELECT * FROM clients WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $clientViewId);
    $stmt->execute();
    $clientRow = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if ($clientRow) {
        $stmt = $conn->prepare("SELECT * FROM client_contacts WHERE client_id = ? ORDER BY id DESC");
        $stmt->bind_param('i', $clientViewId);
        $stmt->execute();
        $contacts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();

        $stmt = $conn->prepare("SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ', ') AS tags
            FROM client_tags ct JOIN tags t ON t.id = ct.tag_id WHERE ct.client_id = ?");
        $stmt->bind_param('i', $clientViewId);
        $stmt->execute();
        $trow = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $clientRow['tags'] = $trow['tags'] ?? '';

        $userQ = trim((string)($_GET['user_q'] ?? ''));
        $userPage = max(1, (int)($_GET['user_page'] ?? 1));
        $pageSize = 10;
        $offset = ($userPage - 1) * $pageSize;
        if ($userQ !== '') {
            $like = '%'.$userQ.'%';
            $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM users WHERE client_id = ? AND (full_name LIKE ? OR username LIKE ? OR email LIKE ?)");
            $stmt->bind_param('isss', $clientViewId, $like, $like, $like);
        } else {
            $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM users WHERE client_id = ?");
            $stmt->bind_param('i', $clientViewId);
        }
        $stmt->execute();
        $rowc = $stmt->get_result()->fetch_assoc() ?: ['cnt' => 0];
        $stmt->close();
        $clientUsersTotal = (int)($rowc['cnt'] ?? 0);
        $clientUsersPages = max(1, (int)ceil($clientUsersTotal / $pageSize));
        if ($userQ !== '') {
            $like = '%'.$userQ.'%';
            $sqlu = "SELECT id, full_name, username, email, job_title, role, is_active, profile_pic FROM users WHERE client_id = ? AND (full_name LIKE ? OR username LIKE ? OR email LIKE ?) ORDER BY role, full_name LIMIT $pageSize OFFSET $offset";
            $stmt = $conn->prepare($sqlu);
            $stmt->bind_param('isss', $clientViewId, $like, $like, $like);
        } else {
            $sqlu = "SELECT id, full_name, username, email, job_title, role, is_active, profile_pic FROM users WHERE client_id = ? ORDER BY role, full_name LIMIT $pageSize OFFSET $offset";
            $stmt = $conn->prepare($sqlu);
            $stmt->bind_param('i', $clientViewId);
        }
        $stmt->execute();
        $clientUsers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
}
?>

<?php $pageTitle = 'Clients'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="mb-0">Clients</h3>
    <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#clientModal" onclick="openClientModal()">
      <i class="bi bi-plus-circle me-1"></i>Add Client
    </button>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
  <?php endif; ?>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <form class="row g-2 align-items-end" method="get">
        <div class="col-md-9">
          <label class="form-label">Search</label>
          <input class="form-control form-control-sm" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Client code, name, website, industry">
        </div>
        <div class="col-md-3 d-grid">
          <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-search me-1"></i>Search</button>
        </div>
      </form>
      <div class="text-muted small mt-2">Total: <?php echo number_format(count($clients)); ?></div>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Client</th>
            <th>Owner</th>
            <th>Manager</th>
            <th class="text-end">Campaigns</th>
            <th class="text-end">Live</th>
            <th>Assigned</th>
            <th class="text-end">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($clients)): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">No clients found.</td></tr>
          <?php else: ?>
            <?php foreach ($clients as $c): ?>
              <?php
                $cid = (int)($c['id'] ?? 0);
                $assignedAt = (string)($c['assigned_at'] ?? '');
                $assignedBy = trim((string)($c['assigned_by_name'] ?? ''));
              ?>
              <tr>
                <td>
                  <div class="fw-semibold"><?php echo htmlspecialchars((string)($c['name'] ?? '')); ?></div>
                  <div class="text-muted small">
                    <?php echo htmlspecialchars((string)($c['client_code'] ?? '')); ?>
                    <?php echo !empty($c['industry']) ? ' · '.htmlspecialchars((string)$c['industry']) : ''; ?>
                    <?php echo !empty($c['contacts_count']) ? ' · '.number_format((int)$c['contacts_count']).' contacts' : ''; ?>
                  </div>
                </td>
                <td class="text-muted small">
                  <?php echo !empty($c['owner_name']) ? htmlspecialchars(formatUserNameWithRole((string)$c['owner_name'], (string)($c['owner_role'] ?? ''), (string)($c['owner_job_title'] ?? ''))) : '<span class="text-muted">—</span>'; ?>
                </td>
                <td class="text-muted small">
                  <?php echo !empty($c['manager_name']) ? htmlspecialchars(formatUserNameWithRole((string)$c['manager_name'], (string)($c['manager_role'] ?? ''), (string)($c['manager_job_title'] ?? ''))) : '<span class="text-muted">—</span>'; ?>
                </td>
                <td class="text-end font-monospace"><?php echo number_format((int)($c['campaigns_total'] ?? 0)); ?></td>
                <td class="text-end font-monospace"><?php echo number_format((int)($c['campaigns_live'] ?? 0)); ?></td>
                <td class="text-muted small">
                  <?php if ($assignedAt): ?>
                    <div><?php echo htmlspecialchars(date('d M Y, H:i', strtotime($assignedAt))); ?></div>
                    <?php if ($assignedBy !== ''): ?><div class="text-muted">by <?php echo htmlspecialchars($assignedBy); ?></div><?php endif; ?>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
                <td class="text-end">
                  <div class="d-inline-flex align-items-center gap-1">
                    <button type="button" class="btn btn-sm btn-light border" title="Open" onclick="openClientInfoModal(<?php echo $cid; ?>)"><i class="bi bi-eye"></i></button>
                    <button type="button" class="btn btn-sm btn-light border" data-bs-toggle="modal" data-bs-target="#clientModal" onclick="openClientModal(<?php echo $cid; ?>)" data-bs-toggle="tooltip" title="Edit"><i class="bi bi-pencil"></i></button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">Client Details</div>
    <div class="card-body">
      <?php if (!$clientRow): ?>
        <div class="text-muted">Use the Open action to preview client information in a popup.</div>
      <?php else: ?>
            <div class="d-flex align-items-start justify-content-between">
              <div>
                <div class="fw-semibold fs-5"><?php echo htmlspecialchars($clientRow['name']); ?></div>
                <div class="text-muted small"><?php echo htmlspecialchars($clientRow['client_code']); ?><?php echo !empty($clientRow['industry']) ? ' · '.htmlspecialchars($clientRow['industry']) : ''; ?></div>
              </div>
              <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#clientModal" onclick="openClientModal(<?php echo (int)$clientRow['id']; ?>)"><i class="bi bi-pencil"></i> Edit</button>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#clientUserModal"><i class="bi bi-person-plus"></i> Add Login</button>
              </div>
            </div>
            
            <div class="row g-3 mt-3">
              <?php if (!empty($clientRow['website'])): ?>
                <div class="col-sm-6">
                  <div class="text-muted small">Website</div>
                  <a href="<?php echo htmlspecialchars($clientRow['website']); ?>" target="_blank" class="text-decoration-none fw-medium"><i class="bi bi-globe me-1"></i><?php echo htmlspecialchars($clientRow['website']); ?></a>
                </div>
              <?php endif; ?>
              <?php if (!empty($clientRow['tags'])): ?>
                <div class="col-sm-6">
                  <div class="text-muted small mb-1">Tags</div>
                  <?php foreach(explode(',', $clientRow['tags']) as $tag): ?>
                    <span class="badge bg-light text-dark border"><?php echo htmlspecialchars(trim($tag)); ?></span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <?php if (!empty($clientRow['notes'])): ?>
                <div class="col-12">
                  <div class="text-muted small">Notes</div>
                  <div class="bg-light rounded p-2 small border"><?php echo nl2br(htmlspecialchars($clientRow['notes'])); ?></div>
                </div>
              <?php endif; ?>
            </div>

            <hr class="my-4">

            <div class="d-flex align-items-center justify-content-between mb-3">
              <h6 class="fw-bold mb-0"><i class="bi bi-person-lines-fill me-2"></i>Contacts</h6>
            </div>
            <div class="table-responsive mb-4">
              <table class="table table-sm table-hover align-middle border mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Title</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($contacts)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">No contacts added yet.</td></tr>
                  <?php else: ?>
                    <?php foreach ($contacts as $cc): ?>
                      <tr>
                        <td class="fw-medium"><?php echo htmlspecialchars($cc['name']); ?></td>
                        <td><a href="mailto:<?php echo htmlspecialchars($cc['email'] ?? ''); ?>" class="text-decoration-none text-muted"><?php echo htmlspecialchars($cc['email'] ?? ''); ?></a></td>
                        <td><a href="tel:<?php echo htmlspecialchars($cc['phone'] ?? ''); ?>" class="text-decoration-none text-muted"><?php echo htmlspecialchars($cc['phone'] ?? ''); ?></a></td>
                        <td class="text-muted"><?php echo htmlspecialchars($cc['title'] ?? ''); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

            <form method="post" class="row g-2 align-items-end p-3 bg-light rounded border mb-4">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
              <input type="hidden" name="action" value="add_contact">
              <input type="hidden" name="client_id" value="<?php echo (int)$clientRow['id']; ?>">
              <div class="col-md-3">
                <label class="form-label small fw-bold mb-1">Contact Name *</label>
                <input class="form-control form-control-sm" name="contact_name" placeholder="Name" required>
              </div>
              <div class="col-md-3">
                <label class="form-label small fw-bold mb-1">Email</label>
                <input class="form-control form-control-sm" type="email" name="contact_email" placeholder="Email">
              </div>
              <div class="col-md-2">
                <label class="form-label small fw-bold mb-1">Phone</label>
                <input class="form-control form-control-sm" name="contact_phone" placeholder="Phone">
              </div>
              <div class="col-md-2">
                <label class="form-label small fw-bold mb-1">Job Title</label>
                <input class="form-control form-control-sm" name="contact_title" placeholder="Title">
              </div>
              <div class="col-md-2 d-grid">
                <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-plus-circle me-1"></i>Add</button>
              </div>
            </form>

            <div class="d-flex align-items-center justify-content-between mb-3">
              <h6 class="fw-bold mb-0"><i class="bi bi-shield-lock me-2"></i>Client Logins</h6>
              <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#clientUserModal"><i class="bi bi-person-plus"></i> New Login</button>
            </div>
            <form class="row g-2 mb-3" method="get">
              <input type="hidden" name="client_id" value="<?php echo (int)$clientRow['id']; ?>">
              <div class="col-md-9">
                <input class="form-control form-control-sm" name="user_q" value="<?php echo htmlspecialchars($_GET['user_q'] ?? ''); ?>" placeholder="Search client users by name, username, email...">
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
                  <?php if (empty($clientUsers)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">No login accounts created yet.</td></tr>
                  <?php else: ?>
                    <?php foreach ($clientUsers as $uu): ?>
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
                                            <input type="hidden" name="action" value="set_user_active">
                                            <input type="hidden" name="user_id" value="<?php echo (int)$uu['id']; ?>">
                                            <input type="hidden" name="client_id" value="<?php echo (int)$clientRow['id']; ?>">
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
            <?php if (!empty($clientUsers)): ?>
              <nav class="mt-2">
                <ul class="pagination pagination-sm mb-0">
                  <?php
                    $cur = (int)($_GET['user_page'] ?? 1);
                    $totalPages = isset($clientUsersPages) ? (int)$clientUsersPages : 1;
                    $makeUrl = function($p) use ($clientRow) {
                      $q = urlencode((string)($_GET['user_q'] ?? ''));
                      return 'clients.php?client_id='.(int)$clientRow['id'].'&user_q='.$q.'&user_page='.(int)$p;
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

<div class="modal fade" id="clientInfoModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 shadow">
      <div class="modal-header">
        <div>
          <div class="h5 mb-0" id="cInfoName">Client</div>
          <div class="text-muted small" id="cInfoMeta">—</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body pt-3">
        <div class="row g-3">
          <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-header bg-light fw-semibold">Client Information</div>
              <div class="card-body">
                <div class="row g-3">
                  <div class="col-12">
                    <div class="text-muted small">Client Code</div>
                    <div class="fw-semibold" id="cInfoCode">—</div>
                  </div>
                  <div class="col-12">
                    <div class="text-muted small">Website</div>
                    <div class="fw-semibold" id="cInfoWebsite">—</div>
                  </div>
                  <div class="col-md-6">
                    <div class="text-muted small">Industry</div>
                    <div class="fw-semibold" id="cInfoIndustry">—</div>
                  </div>
                  <div class="col-md-6">
                    <div class="text-muted small">HQ Location</div>
                    <div class="fw-semibold" id="cInfoCountry">—</div>
                  </div>
                  <div class="col-12">
                    <div class="text-muted small">Notes</div>
                    <div class="bg-light rounded border p-2 small" id="cInfoNotes">—</div>
                  </div>
                  <div class="col-12">
                    <div class="text-muted small mb-1">Tags</div>
                    <div id="cInfoTags" class="d-flex flex-wrap gap-1"></div>
                  </div>
                  <div class="col-12">
                    <div class="d-flex align-items-center justify-content-between">
                      <div class="fw-semibold">Client Users</div>
                      <button type="button" class="btn btn-sm btn-outline-primary" id="cInfoNewLoginBtn"><i class="bi bi-person-plus me-1"></i>New Login</button>
                    </div>
                    <div class="table-responsive mt-2">
                      <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                          <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                          </tr>
                        </thead>
                        <tbody id="cInfoUsersTbody">
                          <tr><td colspan="4" class="text-center text-muted small py-3">Loading…</td></tr>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-header bg-light fw-semibold">Assignment & Metrics</div>
              <div class="card-body">
                <div class="row g-3">
                  <div class="col-md-6">
                    <div class="text-muted small">Assigned SDR</div>
                    <div class="fw-semibold" id="cInfoOwner">—</div>
                  </div>
                  <div class="col-md-6">
                    <div class="text-muted small">Assigned Manager</div>
                    <div class="fw-semibold" id="cInfoManager">—</div>
                  </div>
                  <div class="col-md-4">
                    <div class="text-muted small">Campaign Count</div>
                    <div class="fw-semibold" id="cInfoCampaigns">—</div>
                  </div>
                  <div class="col-md-4">
                    <div class="text-muted small">Live</div>
                    <div class="fw-semibold" id="cInfoLive">—</div>
                  </div>
                  <div class="col-md-4">
                    <div class="text-muted small">Contacts</div>
                    <div class="fw-semibold" id="cInfoContacts">—</div>
                  </div>
                  <div class="col-12">
                    <div class="text-muted small">Assigned</div>
                    <div class="small" id="cInfoAssigned">—</div>
                  </div>
                  <div class="col-12">
                    <div class="d-flex flex-wrap gap-2">
                      <a class="btn btn-outline-primary btn-sm" id="cInfoViewProfile" href="#"><i class="bi bi-box-arrow-up-right me-1"></i>View Full Profile</a>
                      <a class="btn btn-outline-secondary btn-sm" id="cInfoViewCampaigns" href="#"><i class="bi bi-megaphone me-1"></i>View Campaigns</a>
                      <a class="btn btn-outline-secondary btn-sm" id="cInfoViewLeads" href="#"><i class="bi bi-list-ul me-1"></i>View Leads</a>
                      <button type="button" class="btn btn-outline-secondary btn-sm" id="cInfoEditBtn" data-bs-toggle="modal" data-bs-target="#clientModal"><i class="bi bi-pencil me-1"></i>Edit</button>
                      <button type="button" class="btn btn-outline-secondary btn-sm" id="cInfoAddLoginBtn" data-bs-toggle="modal" data-bs-target="#clientUserModal"><i class="bi bi-person-plus me-1"></i>Add Login</button>
                    </div>
                  </div>
                </div>
              </div>
              <div class="modal-footer bg-white" style="position: sticky; bottom: 0; z-index: 2;">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="clientModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="save_client">
        <input type="hidden" name="client_id" id="client_id" value="0">
        <div class="modal-header">
          <h5 class="modal-title" id="clientModalTitle">Add Client</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row g-2">
            <div class="col-md-4">
              <label class="form-label small text-muted">Client Code</label>
              <input class="form-control form-control-sm" name="client_code" id="client_code" required>
            </div>
            <div class="col-md-8">
              <label class="form-label small text-muted">Client Name</label>
              <input class="form-control form-control-sm" name="name" id="name" required>
            </div>
            <div class="col-md-6">
              <label class="form-label small text-muted">Website</label>
              <input class="form-control form-control-sm" name="website" id="website" placeholder="https://">
            </div>
            <div class="col-md-6">
              <label class="form-label small text-muted">Industry</label>
              <input class="form-control form-control-sm" name="industry" id="industry">
            </div>
            <div class="col-md-6">
              <label class="form-label small text-muted">Country</label>
              <input class="form-control form-control-sm" name="country" id="country">
            </div>
            <div class="col-12">
              <label class="form-label small text-muted">Tags</label>
              <input class="form-control form-control-sm" name="tags" id="tags" placeholder="Comma-separated tags">
            </div>
            <div class="col-12">
              <label class="form-label small text-muted">Notes</label>
              <textarea class="form-control form-control-sm" name="notes" id="notes" rows="3"></textarea>
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

<div class="modal fade" id="clientUserModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="create_client_user">
        <input type="hidden" name="client_id" id="client_user_client_id" value="0">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title"><i class="bi bi-person-plus-fill me-2"></i>Create Client Login</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4">
            <div class="alert alert-light border mb-4">
              <div class="small text-muted"><i class="bi bi-info-circle me-1"></i>Create an external access account for <strong id="clientUserClientName">Client</strong>. They will only see data related to their own account.</div>
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label fw-bold small">Full Name *</label>
                <input class="form-control" name="full_name" required placeholder="e.g. John Doe">
              </div>
              <div class="col-md-6">
                <label class="form-label fw-bold small">Username *</label>
                <input class="form-control" name="username" required placeholder="Unique ID">
              </div>
              <div class="col-md-12">
                <label class="form-label fw-bold small">Email Address *</label>
                <input class="form-control" name="email" type="email" required placeholder="john@example.com">
              </div>
              <div class="col-md-6">
                <label class="form-label fw-bold small">Role</label>
                <select class="form-select" name="role">
                  <option value="client_admin">Client Admin (Full Access)</option>
                  <option value="client_sdr" selected>Client SDR (Limited Access)</option>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-bold small">Initial Password</label>
                <input class="form-control" name="password" type="text" placeholder="Auto-generated if blank">
              </div>
              <div class="col-12 mt-3">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" name="is_active" id="clientUserActive" value="1" checked>
                  <label class="form-check-label fw-bold small" for="clientUserActive">Account Active</label>
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

<script>
const clientsData = <?php echo json_encode(array_map(function($c){
    return [
        'id'=>(int)$c['id'],
        'client_code'=>$c['client_code'] ?? '',
        'name'=>$c['name'] ?? '',
        'website'=>$c['website'] ?? '',
        'industry'=>$c['industry'] ?? '',
        'country'=>$c['country'] ?? '',
        'notes'=>$c['notes'] ?? '',
        'tags'=>$c['tags'] ?? '',
        'owner_name'=>$c['owner_name'] ?? '',
        'owner_role'=>$c['owner_role'] ?? '',
        'manager_name'=>$c['manager_name'] ?? '',
        'manager_role'=>$c['manager_role'] ?? '',
        'campaigns_total'=>(int)($c['campaigns_total'] ?? 0),
        'campaigns_live'=>(int)($c['campaigns_live'] ?? 0),
        'contacts_count'=>(int)($c['contacts_count'] ?? 0),
        'assigned_at'=>$c['assigned_at'] ?? '',
        'assigned_by_name'=>$c['assigned_by_name'] ?? '',
    ];
}, $clients), JSON_UNESCAPED_UNICODE); ?>;

function openClientModal(id){
  const mTitle = document.getElementById('clientModalTitle');
  const cId = document.getElementById('client_id');
  const code = document.getElementById('client_code');
  const name = document.getElementById('name');
  const website = document.getElementById('website');
  const industry = document.getElementById('industry');
  const country = document.getElementById('country');
  const notes = document.getElementById('notes');
  const tags = document.getElementById('tags');
  const row = clientsData.find(x => x.id === Number(id));
  if(row){
    mTitle.textContent = 'Edit Client';
    cId.value = row.id;
    code.value = row.client_code;
    name.value = row.name;
    website.value = row.website;
    industry.value = row.industry;
    country.value = row.country;
    notes.value = row.notes;
    tags.value = row.tags;
  }else{
    mTitle.textContent = 'Add Client';
    cId.value = 0;
    code.value = '';
    name.value = '';
    website.value = '';
    industry.value = '';
    country.value = '';
    notes.value = '';
    tags.value = '';
  }
}

function openClientInfoModal(id){
  const row = clientsData.find(x => x.id === Number(id));
  if (!row) return;

  const name = String(row.name || 'Client');
  const code = String(row.client_code || '');
  document.getElementById('cInfoName').textContent = name;
  document.getElementById('cInfoMeta').textContent = code !== '' ? code : '—';
  document.getElementById('cInfoCode').textContent = code !== '' ? code : '—';

  const website = String(row.website || '');
  if (website) {
    const safe = website.match(/^https?:\/\//i) ? website : ('https://' + website);
    document.getElementById('cInfoWebsite').innerHTML = '<a class="text-decoration-none" target="_blank" rel="noopener noreferrer" href="'+safe.replace(/"/g,'&quot;')+'"><i class="bi bi-globe me-1"></i>'+escapeHtml(website)+'</a>';
  } else {
    document.getElementById('cInfoWebsite').textContent = '—';
  }
  document.getElementById('cInfoIndustry').textContent = String(row.industry || '—');
  document.getElementById('cInfoCountry').textContent = String(row.country || '—');
  document.getElementById('cInfoNotes').textContent = String(row.notes || '—');

  const tagsWrap = document.getElementById('cInfoTags');
  tagsWrap.innerHTML = '';
  const tags = String(row.tags || '').split(',').map(t => t.trim()).filter(Boolean);
  if (tags.length) {
    tags.forEach(t => {
      const s = document.createElement('span');
      s.className = 'badge bg-light text-dark border';
      s.textContent = t;
      tagsWrap.appendChild(s);
    });
  } else {
    tagsWrap.innerHTML = '<span class="text-muted small">—</span>';
  }

  document.getElementById('cInfoOwner').textContent = row.owner_name ? String(row.owner_name) : '—';
  document.getElementById('cInfoManager').textContent = row.manager_name ? String(row.manager_name) : '—';
  document.getElementById('cInfoCampaigns').textContent = String(row.campaigns_total ?? 0);
  document.getElementById('cInfoLive').textContent = String(row.campaigns_live ?? 0);
  document.getElementById('cInfoContacts').textContent = String(row.contacts_count ?? 0);
  const assigned = row.assigned_at ? (String(row.assigned_at) + (row.assigned_by_name ? (' by ' + String(row.assigned_by_name)) : '')) : '—';
  document.getElementById('cInfoAssigned').textContent = assigned;

  document.getElementById('cInfoViewProfile').href = 'clients.php?client_id=' + encodeURIComponent(String(row.id));
  document.getElementById('cInfoViewCampaigns').href = 'client-campaigns.php?client_id=' + encodeURIComponent(String(row.id));
  document.getElementById('cInfoViewLeads').href = 'client-leads.php?client_id=' + encodeURIComponent(String(row.id));

  document.getElementById('cInfoEditBtn').onclick = function() { openClientModal(row.id); };
  const addLoginBtn = document.getElementById('cInfoAddLoginBtn');
  addLoginBtn.onclick = function() { openClientLoginModal(row.id, name); };
  document.getElementById('cInfoNewLoginBtn').onclick = function() { openClientLoginModal(row.id, name); };

  loadClientUsers(row.id);

  const modalEl = document.getElementById('clientInfoModal');
  const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
  modal.show();
}

function openClientLoginModal(clientId, clientName) {
  const m1 = bootstrap.Modal.getOrCreateInstance(document.getElementById('clientInfoModal'));
  if (m1) m1.hide();
  const cidInput = document.getElementById('client_user_client_id');
  if (cidInput) cidInput.value = String(clientId || 0);
  const n = document.getElementById('clientUserClientName');
  if (n) n.textContent = clientName || 'Client';
  const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('clientUserModal'));
  modal.show();
}

function loadClientUsers(clientId) {
  const tbody = document.getElementById('cInfoUsersTbody');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted small py-3">Loading…</td></tr>';
  const fd = new FormData();
  fd.append('csrf_token', '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>');
  fd.append('action', 'get_client_users');
  fd.append('client_id', String(clientId));
  fetch('clients.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd, credentials: 'same-origin' })
    .then(r => r.json())
    .then(d => {
      if (!d || !d.ok) throw new Error(d?.error || 'Failed to load');
      const users = Array.isArray(d.users) ? d.users : [];
      if (!users.length) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted small py-3">No users</td></tr>';
        return;
      }
      tbody.innerHTML = '';
      users.forEach(u => {
        const uid = Number(u.id || 0);
        const role = String(u.role || '');
        const nm = String(u.full_name || u.username || '');
        const active = Number(u.is_active || 0) === 1;
        const tr = document.createElement('tr');
        tr.innerHTML =
          '<td class="fw-semibold">' + escapeHtml(nm) + '<div class="text-muted small">' + escapeHtml(String(u.email || '')) + '</div></td>' +
          '<td class="text-muted small">' + escapeHtml(role) + '</td>' +
          '<td>' + (active ? '<span class="badge bg-success-subtle text-success border">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary border">Inactive</span>') + '</td>' +
          '<td class="text-end">' +
            '<div class="btn-group btn-group-sm">' +
              '<a class="btn btn-light border" href="../auth/reset-password.php?user_id=' + encodeURIComponent(String(uid)) + '" target="_blank"><i class="bi bi-key"></i></a>' +
              '<button class="btn btn-light border" type="button" data-toggle-active="' + (active ? '0' : '1') + '" data-user-id="' + String(uid) + '"><i class="bi bi-person-check"></i></button>' +
              '<button class="btn btn-light border text-danger" type="button" data-delete-user="1" data-user-id="' + String(uid) + '"><i class="bi bi-trash"></i></button>' +
            '</div>' +
          '</td>';
        tbody.appendChild(tr);
      });
    })
    .catch(e => {
      tbody.innerHTML = '<tr><td colspan="4" class="text-center text-danger small py-3">' + escapeHtml(String(e?.message || 'Failed')) + '</td></tr>';
    });
}

document.addEventListener('click', function(e) {
  const btn = e.target.closest('button[data-delete-user],button[data-toggle-active]');
  if (!btn) return;
  const tbody = document.getElementById('cInfoUsersTbody');
  const cid = document.getElementById('client_user_client_id')?.value || '';
  const uid = btn.getAttribute('data-user-id') || '';
  if (!cid || !uid) return;

  const isDelete = btn.hasAttribute('data-delete-user');
  const fd = new FormData();
  fd.append('csrf_token', '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>');
  fd.append('client_id', String(cid));
  fd.append('user_id', String(uid));
  if (isDelete) {
    if (!confirm('Delete this client user?')) return;
    fd.append('action', 'delete_client_user');
  } else {
    fd.append('action', 'set_user_active');
    fd.append('is_active', btn.getAttribute('data-toggle-active') === '1' ? '1' : '0');
  }

  fetch('clients.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd, credentials: 'same-origin' })
    .then(r => r.json())
    .then(d => {
      if (!d || !d.ok) throw new Error(d?.error || 'Failed');
      loadClientUsers(Number(cid));
    })
    .catch(err => {
      if (tbody) {
        const tr = document.createElement('tr');
        tr.innerHTML = '<td colspan="4" class="text-danger small py-2">' + escapeHtml(String(err?.message || 'Failed')) + '</td>';
        tbody.prepend(tr);
      }
    });
});

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, function(m) {
    return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]);
  });
}
</script>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
