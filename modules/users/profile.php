<?php
/**
 * Professional User Profile
 * 
 * Allows users to view and update their own profile information, 
 * including profile picture and extended fields.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(getKnownRoles());
$currentUser = getCurrentUser();
$userId = (int)($currentUser['id'] ?? 0);
ensureCsrfToken();
ensureDatabaseSchema();

$message = '';
$messageType = '';

$requestedUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$isViewMode = $requestedUserId > 0;
$isAdmin = isAdmin();
$isClientAdmin = hasRole('client_admin');
$isVendorAdmin = hasRole('vendor_admin');

$viewingUserId = $isViewMode ? $requestedUserId : $userId;
$isViewingOther = $isViewMode && $viewingUserId > 0 && $viewingUserId !== $userId;

$profileUser = null;
$profileDocs = [];
if ($viewingUserId > 0) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT
            u.*,
            p.personal_email, p.emergency_contact_number, p.date_of_birth,
            b.bank_name, b.account_number, b.account_type, b.ifsc_code, b.pan_number,
            m.full_name AS manager_name
        FROM users u
        LEFT JOIN user_personal_details p ON p.user_id = u.id
        LEFT JOIN user_bank_details b ON b.user_id = u.id
        LEFT JOIN users m ON m.id = u.reporting_manager_id
        WHERE u.id = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('i', $viewingUserId);
        $stmt->execute();
        $profileUser = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }
    if ($profileUser) {
        if ($isViewingOther) {
            $ok = false;
            if ($isAdmin) $ok = true;
            if ($isClientAdmin && (int)($currentUser['client_id'] ?? 0) > 0 && (int)($profileUser['client_id'] ?? 0) === (int)($currentUser['client_id'] ?? 0)) $ok = true;
            if ($isVendorAdmin && (int)($currentUser['vendor_id'] ?? 0) > 0 && (int)($profileUser['vendor_id'] ?? 0) === (int)($currentUser['vendor_id'] ?? 0)) $ok = true;
            if (!$ok) $profileUser = null;
        }
        if ($profileUser) {
            $stD = $conn->prepare("SELECT id, category, doc_type, file_path, original_name, file_size, mime_type, uploaded_at FROM user_documents WHERE user_id = ? ORDER BY category, doc_type, id DESC");
            if ($stD) {
                $stD->bind_param('i', $viewingUserId);
                $stD->execute();
                $profileDocs = $stD->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                $stD->close();
            }
        }
    }
}

// Handle Profile Update
if (!$isViewMode && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $message = 'Invalid request token.';
        $messageType = 'danger';
    } else {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone_number'] ?? '');
        $jobTitle = trim($_POST['job_title'] ?? '');
        $dept = trim($_POST['department'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $emergency = trim($_POST['emergency_contact'] ?? '');

        if (empty($fullName) || empty($email)) {
            $message = 'Name and Email are required.';
            $messageType = 'danger';
        } else {
            $conn = getDbConnection();
            
            // Handle Profile Picture Upload
            $profilePicPath = $currentUser['profile_pic'] ?? null;
            if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/../../uploads/profiles/';
                if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }
                
                $fileExt = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                
                if (in_array($fileExt, $allowed)) {
                    $newFileName = 'profile_' . $userId . '_' . time() . '.' . $fileExt;
                    $targetPath = $uploadDir . $newFileName;
                    
                    if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $targetPath)) {
                        // Delete old pic if exists
                        if ($profilePicPath && file_exists(__DIR__ . '/../../' . $profilePicPath)) {
                            @unlink(__DIR__ . '/../../' . $profilePicPath);
                        }
                        $profilePicPath = 'uploads/profiles/' . $newFileName;
                    }
                } else {
                    $message = 'Invalid image format. Allowed: JPG, PNG, WEBP.';
                    $messageType = 'danger';
                }
            }

            if (empty($message)) {
                $sql = "UPDATE users SET 
                        full_name = ?, email = ?, phone_number = ?, job_title = ?, 
                        department = ?, address = ?, emergency_contact = ?, profile_pic = ?
                        WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssssssssi", $fullName, $email, $phone, $jobTitle, $dept, $address, $emergency, $profilePicPath, $userId);
                
                if ($stmt->execute()) {
                    $message = 'Profile updated successfully.';
                    $messageType = 'success';
                    // Refresh session data
                    refreshUserSession($userId);
                    $currentUser = getCurrentUser();
                } else {
                    $message = 'Failed to update profile.';
                    $messageType = 'danger';
                }
            }
        }
    }
}

?>
<?php
$profileData = $profileUser ?: $currentUser;
$formatProfileDate = static function($value, string $fallback = 'Not set'): string {
    $value = trim((string)$value);
    if ($value === '') {
        return $fallback;
    }
    $ts = strtotime($value);
    return $ts ? date('d M, Y', $ts) : $value;
};
$profileText = static function($value, string $fallback = 'Not set'): string {
    $value = trim((string)$value);
    return $value !== '' ? $value : $fallback;
};
$userTypeLabel = ((int)($profileData['client_id'] ?? 0) > 0)
    ? 'Client User'
    : (((int)($profileData['vendor_id'] ?? 0) > 0) ? 'Vendor User' : 'Internal User');
$profileStatusLabel = !empty($profileData['is_active']) ? 'Active' : 'Inactive';
$profileStatusClass = !empty($profileData['is_active']) ? 'status-active' : 'status-inactive';
$profileInitial = strtoupper(substr((string)($profileData['full_name'] ?? 'U'), 0, 1));
$profileRoleLabel = getUserDisplayTitle((string)($profileData['role'] ?? ''), (string)($profileData['job_title'] ?? ''));
$profileDocCount = count($profileDocs);
$profilePageTitle = $isViewMode ? 'User Profile' : 'My Profile';
$profileAccessContext = ((int)($profileData['client_id'] ?? 0) > 0)
    ? 'Client Access Space'
    : (((int)($profileData['vendor_id'] ?? 0) > 0) ? 'Vendor Access Space' : 'Internal CRM Access');
$latestProfileDoc = null;
foreach ($profileDocs as $doc) {
    $docTime = strtotime((string)($doc['uploaded_at'] ?? '')) ?: 0;
    $latestDocTime = strtotime((string)($latestProfileDoc['uploaded_at'] ?? '')) ?: 0;
    if ($latestProfileDoc === null || $docTime >= $latestDocTime) {
        $latestProfileDoc = $doc;
    }
}
$latestProfileDocLabel = $latestProfileDoc
    ? ($formatProfileDate($latestProfileDoc['uploaded_at'] ?? '', 'Unknown')
        . ' · '
        . $profileText($latestProfileDoc['original_name'] ?? '', 'Document'))
    : 'No document activity yet';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

    <div class="container-fluid px-0 profile-workspace">
        <?php if ($isViewMode && $profileUser === null): ?>
            <div class="alert alert-danger">User not found or not allowed.</div>
        <?php else: ?>
            <div class="profile-page-header mb-4">
                <div class="profile-hero flex-grow-1">
                    <div class="profile-hero-top">
                        <div class="profile-hero-main">
                            <span class="profile-eyebrow"><i class="bi bi-person-badge"></i><?php echo htmlspecialchars($profilePageTitle); ?></span>
                            <div class="profile-title-row">
                                <div class="profile-avatar-wrap">
                                    <?php if (!empty($profileData['profile_pic'])): ?>
                                        <img
                                            src="../../<?php echo htmlspecialchars((string)$profileData['profile_pic']); ?>"
                                            class="profile-avatar"
                                            alt="<?php echo htmlspecialchars((string)($profileData['full_name'] ?? 'Profile user')); ?>"
                                            <?php echo !$isViewMode ? 'id="avatarPreview"' : ''; ?>
                                        >
                                    <?php else: ?>
                                        <div class="profile-avatar profile-avatar-fallback"><?php echo htmlspecialchars($profileInitial); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="profile-title-copy">
                                    <div class="profile-page-title"><?php echo htmlspecialchars((string)($profileData['full_name'] ?? 'User')); ?></div>
                                    <div class="profile-page-subtitle">
                                        @<?php echo htmlspecialchars((string)($profileData['username'] ?? '')); ?>
                                        · <?php echo htmlspecialchars((string)($profileData['email'] ?? '')); ?>
                                        · <?php echo htmlspecialchars($profileText($profileData['department'] ?? '', 'Department not assigned')); ?>
                                    </div>
                                    <div class="profile-chip-row mt-3">
                                        <span class="profile-chip tone-primary"><?php echo htmlspecialchars($profileText($profileRoleLabel, 'Not set')); ?></span>
                                        <span class="profile-chip tone-slate"><?php echo htmlspecialchars($userTypeLabel); ?></span>
                                        <span class="profile-chip <?php echo htmlspecialchars($profileStatusClass); ?>"><?php echo htmlspecialchars($profileStatusLabel); ?></span>
                                        <span class="profile-chip tone-info"><?php echo htmlspecialchars($profileText($profileData['job_title'] ?? '', 'Job title not set')); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="profile-page-actions">
                            <?php if ($isViewMode): ?>
                                <a class="btn btn-light border btn-sm" href="manage-users.php?search=<?php echo urlencode((string)($profileData['username'] ?? '')); ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
                                <?php if ($isAdmin || $isClientAdmin || $isVendorAdmin): ?>
                                    <a class="btn btn-primary btn-sm" href="manage-users.php?search=<?php echo urlencode((string)($profileData['username'] ?? '')); ?>"><i class="bi bi-pencil-square me-1"></i>Manage User</a>
                                <?php endif; ?>
                            <?php else: ?>
                                <a class="btn btn-light border btn-sm" href="../auth/change-password.php"><i class="bi bi-shield-lock me-1"></i>Security</a>
                                <label for="profile_pic_input" class="btn btn-primary btn-sm">
                                    <i class="bi bi-camera me-1"></i>Update Photo
                                </label>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-6 col-xl-3">
                            <div class="profile-kpi-card kpi-primary">
                                <div class="profile-kpi-label">Employee ID</div>
                                <div class="profile-kpi-value"><?php echo htmlspecialchars($profileText($profileData['employee_id'] ?? '', 'N/A')); ?></div>
                                <div class="profile-kpi-note">Unique employee reference</div>
                            </div>
                        </div>
                        <div class="col-6 col-xl-3">
                            <div class="profile-kpi-card kpi-success">
                                <div class="profile-kpi-label">Joined</div>
                                <div class="profile-kpi-value"><?php echo htmlspecialchars($formatProfileDate($profileData['date_of_joining'] ?? '', 'Not added')); ?></div>
                                <div class="profile-kpi-note">Tenure and onboarding reference</div>
                            </div>
                        </div>
                        <div class="col-6 col-xl-3">
                            <div class="profile-kpi-card kpi-info">
                                <div class="profile-kpi-label">Reporting Manager</div>
                                <div class="profile-kpi-value profile-kpi-value-sm"><?php echo htmlspecialchars($profileText($profileData['manager_name'] ?? '', 'Not assigned')); ?></div>
                                <div class="profile-kpi-note">Current reporting structure</div>
                            </div>
                        </div>
                        <div class="col-6 col-xl-3">
                            <div class="profile-kpi-card kpi-warning">
                                <div class="profile-kpi-label">Documents</div>
                                <div class="profile-kpi-value"><?php echo number_format($profileDocCount); ?></div>
                                <div class="profile-kpi-note">Profile attachments on file</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-xl-4">
                    <div class="card profile-panel">
                        <div class="card-header">Account Snapshot</div>
                        <div class="card-body">
                            <div class="profile-info-grid">
                                <div class="profile-info-item">
                                    <span class="profile-info-label">User Type</span>
                                    <span class="profile-info-value"><?php echo htmlspecialchars($userTypeLabel); ?></span>
                                </div>
                                <div class="profile-info-item">
                                    <span class="profile-info-label">Department</span>
                                    <span class="profile-info-value"><?php echo htmlspecialchars($profileText($profileData['department'] ?? '')); ?></span>
                                </div>
                                <div class="profile-info-item">
                                    <span class="profile-info-label">Job Title</span>
                                    <span class="profile-info-value"><?php echo htmlspecialchars($profileText($profileData['job_title'] ?? '')); ?></span>
                                </div>
                                <div class="profile-info-item">
                                    <span class="profile-info-label">Work Phone</span>
                                    <span class="profile-info-value"><?php echo htmlspecialchars($profileText($profileData['phone_number'] ?? '')); ?></span>
                                </div>
                                <div class="profile-info-item profile-info-item-wide">
                                    <span class="profile-info-label">Address</span>
                                    <span class="profile-info-value"><?php echo nl2br(htmlspecialchars($profileText($profileData['address'] ?? ''))); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card profile-panel mt-3">
                        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <div>Access And Activity Summary</div>
                                <div class="profile-section-note">Quick enterprise view of job title ownership, access context, and latest profile-side activity.</div>
                            </div>
                            <span class="profile-chip tone-primary"><i class="bi bi-shield-check"></i><?php echo htmlspecialchars($profileRoleLabel); ?></span>
                        </div>
                        <div class="card-body">
                            <div class="profile-summary-grid">
                                <div class="profile-summary-card">
                                    <div class="profile-summary-label">Job Title</div>
                                    <div class="profile-summary-value"><?php echo htmlspecialchars($profileRoleLabel); ?></div>
                                    <div class="profile-summary-note"><?php echo htmlspecialchars($profileText($profileData['job_title'] ?? '', 'Title not mapped')); ?></div>
                                </div>
                                <div class="profile-summary-card">
                                    <div class="profile-summary-label">Account Status</div>
                                    <div class="profile-summary-value"><?php echo htmlspecialchars($profileStatusLabel); ?></div>
                                    <div class="profile-summary-note">Employee ref: <?php echo htmlspecialchars($profileText($profileData['employee_id'] ?? '', 'N/A')); ?></div>
                                </div>
                                <div class="profile-summary-card">
                                    <div class="profile-summary-label">Access Context</div>
                                    <div class="profile-summary-value"><?php echo htmlspecialchars($userTypeLabel); ?></div>
                                    <div class="profile-summary-note"><?php echo htmlspecialchars($profileAccessContext); ?></div>
                                </div>
                                <div class="profile-summary-card">
                                    <div class="profile-summary-label">Latest Activity</div>
                                    <div class="profile-summary-value"><?php echo $profileDocCount > 0 ? htmlspecialchars($formatProfileDate($latestProfileDoc['uploaded_at'] ?? '', 'Unknown')) : 'No uploads'; ?></div>
                                    <div class="profile-summary-note"><?php echo htmlspecialchars($profileDocCount > 0 ? 'Latest document recorded' : 'Awaiting profile document upload'); ?></div>
                                </div>
                            </div>

                            <div class="profile-activity-list mt-3">
                                <div class="profile-activity-item">
                                    <span class="profile-activity-icon"><i class="bi bi-diagram-3"></i></span>
                                    <div>
                                        <div class="profile-activity-title">Reporting line</div>
                                        <div class="profile-activity-meta"><?php echo htmlspecialchars($profileText($profileData['manager_name'] ?? '', 'No reporting manager assigned')); ?></div>
                                    </div>
                                </div>
                                <div class="profile-activity-item">
                                    <span class="profile-activity-icon"><i class="bi bi-calendar-event"></i></span>
                                    <div>
                                        <div class="profile-activity-title">Joined and profile tenure</div>
                                        <div class="profile-activity-meta"><?php echo htmlspecialchars($formatProfileDate($profileData['date_of_joining'] ?? '', 'Joining date not available')); ?></div>
                                    </div>
                                </div>
                                <div class="profile-activity-item">
                                    <span class="profile-activity-icon"><i class="bi bi-folder-check"></i></span>
                                    <div>
                                        <div class="profile-activity-title">Latest document activity</div>
                                        <div class="profile-activity-meta"><?php echo htmlspecialchars($latestProfileDocLabel); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card profile-panel mt-3">
                        <div class="card-header">Contact And Personal</div>
                        <div class="card-body">
                            <div class="profile-stack-list">
                                <div class="profile-stack-item">
                                    <span class="profile-info-label">Primary Email</span>
                                    <strong><?php echo htmlspecialchars($profileText($profileData['email'] ?? '')); ?></strong>
                                </div>
                                <div class="profile-stack-item">
                                    <span class="profile-info-label">Personal Email</span>
                                    <strong><?php echo htmlspecialchars($profileText($profileData['personal_email'] ?? '')); ?></strong>
                                </div>
                                <div class="profile-stack-item">
                                    <span class="profile-info-label">Emergency Contact</span>
                                    <strong><?php echo htmlspecialchars($profileText(($profileData['emergency_contact_number'] ?? ($profileData['emergency_contact'] ?? '')))); ?></strong>
                                </div>
                                <div class="profile-stack-item">
                                    <span class="profile-info-label">Date Of Birth</span>
                                    <strong><?php echo htmlspecialchars($formatProfileDate($profileData['date_of_birth'] ?? '', 'Not shared')); ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if (!$isViewMode): ?>
                        <div class="card profile-panel mt-3">
                            <div class="card-header">Quick Actions</div>
                            <div class="card-body">
                                <div class="profile-stack-list">
                                    <a class="btn btn-light border text-start" href="../auth/change-password.php"><i class="bi bi-key me-2"></i>Update password and security settings</a>
                                    <label for="profile_pic_input" class="btn btn-light border text-start">
                                        <i class="bi bi-image me-2"></i>Refresh your profile photo
                                    </label>
                                    <div class="profile-note">
                                        Keep your work profile updated so managers, HR, and admins see the correct contact and job title details across the CRM.
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="col-xl-8">
                    <?php if ($message): ?>
                        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
                            <?php echo htmlspecialchars($message); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($isViewMode): ?>
                        <div class="card profile-panel">
                            <div class="card-header">Employment Details</div>
                            <div class="card-body">
                                <div class="profile-info-grid">
                                    <div class="profile-info-item">
                                        <span class="profile-info-label">Employee ID</span>
                                        <span class="profile-info-value"><?php echo htmlspecialchars($profileText($profileData['employee_id'] ?? '', 'Not assigned')); ?></span>
                                    </div>
                                    <div class="profile-info-item">
                                        <span class="profile-info-label">Date Of Joining</span>
                                        <span class="profile-info-value"><?php echo htmlspecialchars($formatProfileDate($profileData['date_of_joining'] ?? '', 'Not added')); ?></span>
                                    </div>
                                    <div class="profile-info-item">
                                        <span class="profile-info-label">Reporting Manager</span>
                                        <span class="profile-info-value"><?php echo htmlspecialchars($profileText($profileData['manager_name'] ?? '', 'Not assigned')); ?></span>
                                    </div>
                                    <div class="profile-info-item profile-info-item-wide">
                                        <span class="profile-info-label">Onboarding Notes</span>
                                        <span class="profile-info-value"><?php echo nl2br(htmlspecialchars($profileText($profileData['onboarding_notes'] ?? '', 'No onboarding notes available.'))); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card profile-panel mt-3">
                            <div class="card-header">Banking Summary</div>
                            <div class="card-body">
                                <div class="profile-info-grid">
                                    <div class="profile-info-item">
                                        <span class="profile-info-label">Bank Name</span>
                                        <span class="profile-info-value"><?php echo htmlspecialchars($profileText($profileData['bank_name'] ?? '')); ?></span>
                                    </div>
                                    <div class="profile-info-item">
                                        <span class="profile-info-label">Account Number</span>
                                        <span class="profile-info-value font-monospace"><?php echo htmlspecialchars(maskAccountNumber((string)($profileData['account_number'] ?? ''))); ?></span>
                                    </div>
                                    <div class="profile-info-item">
                                        <span class="profile-info-label">Account Type</span>
                                        <span class="profile-info-value"><?php echo htmlspecialchars($profileText($profileData['account_type'] ?? '')); ?></span>
                                    </div>
                                    <div class="profile-info-item">
                                        <span class="profile-info-label">IFSC</span>
                                        <span class="profile-info-value font-monospace"><?php echo htmlspecialchars($profileText($profileData['ifsc_code'] ?? '')); ?></span>
                                    </div>
                                    <div class="profile-info-item">
                                        <span class="profile-info-label">PAN</span>
                                        <span class="profile-info-value font-monospace"><?php echo htmlspecialchars($profileText($profileData['pan_number'] ?? '')); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="card profile-panel">
                            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <div>
                                    <div>Profile Details</div>
                                    <div class="profile-section-note">Update your main CRM identity, work information, and emergency contact details from one place.</div>
                                </div>
                                <a class="btn btn-light border btn-sm" href="../auth/change-password.php"><i class="bi bi-shield-check me-1"></i>Security</a>
                            </div>
                            <div class="card-body">
                                <form method="post" enctype="multipart/form-data" class="profile-form">
                                    <input type="hidden" name="action" value="update_profile">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="file" name="profile_pic" id="profile_pic_input" style="display: none;" accept=".jpg,.jpeg,.png,.webp" onchange="previewImage(this)">

                                    <div class="profile-form-section">
                                        <div class="profile-form-title">Basic Information</div>
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label small text-muted">Full Name</label>
                                                <input type="text" class="form-control" name="full_name" value="<?php echo htmlspecialchars($currentUser['full_name'] ?? ''); ?>" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small text-muted">Email</label>
                                                <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($currentUser['email'] ?? ''); ?>" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small text-muted">Phone</label>
                                                <input type="text" class="form-control" name="phone_number" value="<?php echo htmlspecialchars($currentUser['phone_number'] ?? ''); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small text-muted">Emergency Contact</label>
                                                <input type="text" class="form-control" name="emergency_contact" value="<?php echo htmlspecialchars(($currentUser['emergency_contact'] ?? ($profileData['emergency_contact_number'] ?? ''))); ?>">
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label small text-muted">Address</label>
                                                <textarea class="form-control" name="address" rows="3"><?php echo htmlspecialchars($currentUser['address'] ?? ''); ?></textarea>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="profile-form-section">
                                        <div class="profile-form-title">Work Information</div>
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label small text-muted">Job Title</label>
                                                <input type="text" class="form-control" name="job_title" value="<?php echo htmlspecialchars($currentUser['job_title'] ?? ''); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small text-muted">Department</label>
                                                <input type="text" class="form-control" name="department" value="<?php echo htmlspecialchars($currentUser['department'] ?? ''); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small text-muted">Employee ID</label>
                                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($profileText($profileData['employee_id'] ?? '', 'Assigned by admin')); ?>" disabled>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small text-muted">Reporting Manager</label>
                                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($profileText($profileData['manager_name'] ?? '', 'Assigned by admin')); ?>" disabled>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="profile-form-footer">
                                        <div class="profile-section-note">Some employment and compliance fields are managed by HR or administrators and are shown here as read-only references.</div>
                                        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2-circle me-1"></i>Save Changes</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="card profile-panel mt-3">
                        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <div>Documents</div>
                                <div class="profile-section-note">Identity, compliance, and onboarding documents linked with this user profile.</div>
                            </div>
                            <span class="profile-chip tone-slate"><?php echo number_format($profileDocCount); ?> files</span>
                        </div>
                        <div class="card-body">
                            <?php if (empty($profileDocs)): ?>
                                <div class="profile-empty-state">No documents uploaded yet.</div>
                            <?php else: ?>
                                <div class="table-responsive profile-table-wrap">
                                    <table class="table align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Category</th>
                                                <th>Type</th>
                                                <th>File</th>
                                                <th>Uploaded</th>
                                                <th class="text-end">Size</th>
                                                <th class="text-end">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($profileDocs as $d): ?>
                                                <tr>
                                                    <td class="text-muted small"><?php echo htmlspecialchars($profileText($d['category'] ?? '', 'General')); ?></td>
                                                    <td class="text-muted small"><?php echo htmlspecialchars($profileText($d['doc_type'] ?? '', 'Document')); ?></td>
                                                    <td>
                                                        <div class="profile-doc-title"><?php echo htmlspecialchars((string)($d['original_name'] ?? '')); ?></div>
                                                        <div class="profile-doc-meta"><?php echo htmlspecialchars((string)($d['mime_type'] ?? 'Unknown format')); ?></div>
                                                    </td>
                                                    <td class="text-muted small"><?php echo htmlspecialchars($formatProfileDate($d['uploaded_at'] ?? '', 'Unknown')); ?></td>
                                                    <td class="text-end text-muted small"><?php echo number_format(((int)($d['file_size'] ?? 0)) / 1024, 1); ?> KB</td>
                                                    <td class="text-end">
                                                        <a class="btn btn-sm btn-outline-primary" target="_blank" href="../../<?php echo htmlspecialchars((string)($d['file_path'] ?? '')); ?>"><i class="bi bi-download me-1"></i>Open</a>
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

            <?php if (!$isViewMode): ?>
            <script>
                let cropInputEl = null;
                let cropImg = null;
                let cropUrl = '';
                let cropBaseScale = 1;
                let cropScale = 1;
                let cropX = 0;
                let cropY = 0;
                let cropDrag = null;

                function setProfilePreviewUrl(url) {
                    const preview = document.getElementById('avatarPreview');
                    if (preview && preview.tagName === 'IMG') {
                        preview.src = url;
                    }
                }

                function clampCrop() {
                    const canvas = document.getElementById('cropCanvas');
                    if (!canvas || !cropImg) return;
                    const w = cropImg.width * cropScale;
                    const h = cropImg.height * cropScale;
                    const minX = canvas.width - w;
                    const minY = canvas.height - h;
                    if (w <= canvas.width) cropX = (canvas.width - w) / 2;
                    else cropX = Math.min(0, Math.max(minX, cropX));
                    if (h <= canvas.height) cropY = (canvas.height - h) / 2;
                    else cropY = Math.min(0, Math.max(minY, cropY));
                }

                function drawCrop() {
                    const canvas = document.getElementById('cropCanvas');
                    if (!canvas) return;
                    const ctx = canvas.getContext('2d');
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    ctx.fillStyle = '#f8f9fa';
                    ctx.fillRect(0, 0, canvas.width, canvas.height);
                    if (!cropImg) return;
                    clampCrop();
                    ctx.imageSmoothingEnabled = true;
                    ctx.imageSmoothingQuality = 'high';
                    ctx.drawImage(cropImg, cropX, cropY, cropImg.width * cropScale, cropImg.height * cropScale);
                }

                async function openCropperForInput(input) {
                    if (!input?.files?.[0]) return;
                    cropInputEl = input;
                    const file = input.files[0];
                    if (!String(file.type || '').startsWith('image/')) return;
                    if (cropUrl) URL.revokeObjectURL(cropUrl);
                    cropUrl = URL.createObjectURL(file);
                    cropImg = new Image();
                    await new Promise((res, rej) => {
                        cropImg.onload = () => res(true);
                        cropImg.onerror = () => rej(new Error('load'));
                        cropImg.src = cropUrl;
                    }).catch(() => null);
                    const canvas = document.getElementById('cropCanvas');
                    const zoom = document.getElementById('cropZoom');
                    if (!canvas || !zoom || !cropImg) return;
                    cropBaseScale = Math.max(canvas.width / cropImg.width, canvas.height / cropImg.height);
                    cropScale = cropBaseScale;
                    zoom.value = '1';
                    cropX = (canvas.width - cropImg.width * cropScale) / 2;
                    cropY = (canvas.height - cropImg.height * cropScale) / 2;
                    drawCrop();
                    new bootstrap.Modal(document.getElementById('cropperModal')).show();
                }

                function previewImage(input) {
                    openCropperForInput(input);
                }

                document.addEventListener('DOMContentLoaded', () => {
                    const canvas = document.getElementById('cropCanvas');
                    const zoom = document.getElementById('cropZoom');
                    const btnCancel = document.getElementById('cropCancel');
                    const btnOrig = document.getElementById('cropUseOriginal');
                    const btnApply = document.getElementById('cropApply');
                    const modalEl = document.getElementById('cropperModal');

                    if (zoom) {
                        zoom.addEventListener('input', () => {
                            if (!cropImg) return;
                            const prevScale = cropScale;
                            cropScale = cropBaseScale * parseFloat(zoom.value || '1');
                            const canvas = document.getElementById('cropCanvas');
                            if (!canvas) return;
                            const cx = canvas.width / 2;
                            const cy = canvas.height / 2;
                            cropX = cx - (cx - cropX) * (cropScale / prevScale);
                            cropY = cy - (cy - cropY) * (cropScale / prevScale);
                            drawCrop();
                        });
                    }

                    if (canvas) {
                        const onDown = (e) => {
                            if (!cropImg) return;
                            canvas.setPointerCapture(e.pointerId);
                            cropDrag = { x: e.clientX, y: e.clientY, ox: cropX, oy: cropY };
                        };
                        const onMove = (e) => {
                            if (!cropDrag) return;
                            cropX = cropDrag.ox + (e.clientX - cropDrag.x);
                            cropY = cropDrag.oy + (e.clientY - cropDrag.y);
                            drawCrop();
                        };
                        const onUp = () => { cropDrag = null; };
                        canvas.addEventListener('pointerdown', onDown);
                        canvas.addEventListener('pointermove', onMove);
                        canvas.addEventListener('pointerup', onUp);
                        canvas.addEventListener('pointercancel', onUp);
                        canvas.addEventListener('pointerleave', onUp);
                    }

                    if (btnCancel) {
                        btnCancel.addEventListener('click', () => {
                            if (cropInputEl) cropInputEl.value = '';
                            bootstrap.Modal.getInstance(modalEl)?.hide();
                        });
                    }

                    if (btnOrig) {
                        btnOrig.addEventListener('click', () => {
                            const f = cropInputEl?.files?.[0];
                            if (!f) return;
                            const r = new FileReader();
                            r.onload = (e) => setProfilePreviewUrl(String(e.target?.result || ''));
                            r.readAsDataURL(f);
                            bootstrap.Modal.getInstance(modalEl)?.hide();
                        });
                    }

                    if (btnApply) {
                        btnApply.addEventListener('click', async () => {
                            const input = cropInputEl;
                            if (!input?.files?.[0] || !cropImg) return;
                            const canvas = document.getElementById('cropCanvas');
                            if (!canvas) return;

                            const out = document.createElement('canvas');
                            out.width = canvas.width;
                            out.height = canvas.height;
                            const ctx = out.getContext('2d');
                            ctx.imageSmoothingEnabled = true;
                            ctx.imageSmoothingQuality = 'high';
                            ctx.fillStyle = '#ffffff';
                            ctx.fillRect(0, 0, out.width, out.height);
                            ctx.drawImage(cropImg, cropX, cropY, cropImg.width * cropScale, cropImg.height * cropScale);

                            const blob = await new Promise((res) => out.toBlob(res, 'image/jpeg', 0.92));
                            if (!blob) return;
                            const newFile = new File([blob], 'profile.jpg', { type: 'image/jpeg' });
                            const dt = new DataTransfer();
                            dt.items.add(newFile);
                            input.files = dt.files;
                            setProfilePreviewUrl(URL.createObjectURL(newFile));
                            bootstrap.Modal.getInstance(modalEl)?.hide();
                        });
                    }
                });
            </script>

            <div class="modal fade" id="cropperModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content border-0 shadow">
                        <div class="modal-header">
                            <h5 class="modal-title">Crop Profile Photo</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="d-flex justify-content-center">
                                <canvas id="cropCanvas" width="320" height="320" class="border rounded" style="touch-action:none; background:#f8f9fa;"></canvas>
                            </div>
                            <div class="mt-3">
                                <label class="form-label small text-muted mb-1">Zoom</label>
                                <input type="range" class="form-range" id="cropZoom" min="1" max="3" step="0.01" value="1">
                                <div class="small text-muted">Drag photo to position</div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light border btn-sm" id="cropCancel">Cancel</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="cropUseOriginal">Use Original</button>
                            <button type="button" class="btn btn-primary btn-sm" id="cropApply">Crop & Use</button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
