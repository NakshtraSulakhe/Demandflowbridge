<?php
/**
 * Professional User Management
 * 
 * Provides an advanced interface for managing users with extended profile fields,
 * role-based filtering, and quick actions.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$currentUser = getCurrentUser();
requirePermissionOrRole('users.internal.manage', ['admin']);
ensureCsrfToken();
ensureDatabaseSchema();

$message = '';
$messageType = '';

$isAdminUser = isAdmin();
$isClientAdmin = hasRole('client_admin');
$isVendorAdmin = hasRole('vendor_admin');
$currentClientId = (int)($currentUser['client_id'] ?? 0);
$currentVendorId = (int)($currentUser['vendor_id'] ?? 0);

$conn = getDbConnection();
$managersList = [];
if ($isAdminUser) {
    $rs = $conn->query("SELECT id, full_name, role FROM users WHERE (client_id IS NULL OR client_id = 0) AND (vendor_id IS NULL OR vendor_id = 0) ORDER BY full_name");
    $managersList = $rs ? ($rs->fetch_all(MYSQLI_ASSOC) ?: []) : [];
}
$clientsList = [];
$vendorsList = [];
if ($isAdminUser) {
    $rs = $conn->query("SELECT id, client_code, name FROM clients ORDER BY name");
    $clientsList = $rs ? ($rs->fetch_all(MYSQLI_ASSOC) ?: []) : [];
    $rs2 = $conn->query("SELECT id, vendor_code, name FROM vendors ORDER BY name");
    $vendorsList = $rs2 ? ($rs2->fetch_all(MYSQLI_ASSOC) ?: []) : [];
} elseif ($isClientAdmin && $currentClientId > 0) {
    $stmt = $conn->prepare("SELECT id, client_code, name FROM clients WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $currentClientId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) $clientsList = [$row];
} elseif ($isVendorAdmin && $currentVendorId > 0) {
    $stmt = $conn->prepare("SELECT id, vendor_code, name FROM vendors WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $currentVendorId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) $vendorsList = [$row];
}

// Handle Create/Edit/Delete via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $message = 'Invalid request token.';
        $messageType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'save_user') {
            $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
            $username = trim($_POST['username'] ?? '');
            $fullName = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $role = normalizeRole((string)($_POST['role'] ?? ''));
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $empIdRaw = trim((string)($_POST['employee_id'] ?? ''));
            $empId = ($empIdRaw === '') ? null : $empIdRaw;
            $doj = trim((string)($_POST['date_of_joining'] ?? ''));
            $dojVal = $doj !== '' ? $doj : null;
            if ($dojVal !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dojVal)) {
                $message = 'Invalid date of joining.';
                $messageType = 'danger';
            }
            $jobTitle = trim($_POST['job_title'] ?? '');
            $phone = trim($_POST['phone_number'] ?? '');
            $dept = trim($_POST['department'] ?? '');
            $reportingManagerId = (int)($_POST['reporting_manager_id'] ?? 0);
            if ($reportingManagerId <= 0) $reportingManagerId = null;
            $onboardingNotes = trim((string)($_POST['onboarding_notes'] ?? ''));
            $userType = trim((string)($_POST['user_type'] ?? 'internal'));
            $clientId = (int)($_POST['client_id'] ?? 0);
            $vendorId = (int)($_POST['vendor_id'] ?? 0);
            if (!in_array($userType, ['internal','client','vendor'], true)) $userType = 'internal';

            $personalEmail = trim((string)($_POST['personal_email'] ?? ''));
            $emergencyContact = trim((string)($_POST['emergency_contact_number'] ?? ''));
            $dob = trim((string)($_POST['date_of_birth'] ?? ''));

            $bankName = trim((string)($_POST['bank_name'] ?? ''));
            $accountNumber = trim((string)($_POST['account_number'] ?? ''));
            $accountType = trim((string)($_POST['account_type'] ?? ''));
            $ifsc = strtoupper(trim((string)($_POST['ifsc_code'] ?? '')));
            $pan = strtoupper(trim((string)($_POST['pan_number'] ?? '')));

            if ($messageType === 'danger') {
                // keep message
            } elseif (empty($username) || empty($fullName) || empty($email) || empty($role)) {
                $message = 'Required fields are missing.';
                $messageType = 'danger';
            } else {
                $conn = getDbConnection();
                $clientRoles = ['client_admin','client_sdr'];
                $vendorRoles = ['vendor_admin','vendor_user'];
                $internalRoles = [
                    'admin',
                    'director','manager_director','operations_director','operations_manager','operations_agent',
                    'email_marketing_director','email_marketing_manager','email_marketing_agent',
                    'qa_director','qa_manager','qa_agent',
                    'sales_director','sales_manager','sdr'
                ];
                $customRolesCfg = function_exists('getCustomRolesConfig') ? getCustomRolesConfig() : [];
                $customInternalRoleKeys = [];
                $customClientRoleKeys = [];
                $customVendorRoleKeys = [];
                foreach ($customRolesCfg as $rk => $rv) {
                    if (!is_array($rv)) continue;
                    $scope = (string)($rv['scope'] ?? 'internal');
                    $rk = normalizeRole((string)$rk);
                    if ($rk === '') continue;
                    if ($scope === 'client') $customClientRoleKeys[$rk] = true;
                    elseif ($scope === 'vendor') $customVendorRoleKeys[$rk] = true;
                    else $customInternalRoleKeys[$rk] = true;
                }
                foreach (array_keys($customInternalRoleKeys) as $rk) {
                    if (!in_array($rk, $internalRoles, true)) $internalRoles[] = $rk;
                }
                foreach (array_keys($customClientRoleKeys) as $rk) {
                    if (!in_array($rk, $clientRoles, true)) $clientRoles[] = $rk;
                }
                foreach (array_keys($customVendorRoleKeys) as $rk) {
                    if (!in_array($rk, $vendorRoles, true)) $vendorRoles[] = $rk;
                }

                if ($userId > 0 && (!$isAdminUser)) {
                    $stmtScope = $conn->prepare("SELECT client_id, vendor_id FROM users WHERE id = ? LIMIT 1");
                    $stmtScope->bind_param('i', $userId);
                    $stmtScope->execute();
                    $ex = $stmtScope->get_result()->fetch_assoc() ?: null;
                    $stmtScope->close();
                    if (!$ex) {
                        $message = 'User not found.';
                        $messageType = 'danger';
                        $action = '';
                    } else {
                        if ($isClientAdmin && (int)($ex['client_id'] ?? 0) !== $currentClientId) {
                            $message = 'Not allowed.';
                            $messageType = 'danger';
                            $action = '';
                        }
                        if ($isVendorAdmin && (int)($ex['vendor_id'] ?? 0) !== $currentVendorId) {
                            $message = 'Not allowed.';
                            $messageType = 'danger';
                            $action = '';
                        }
                    }
                }

                if ($action === 'save_user') {
                    if ($isClientAdmin) {
                        $userType = 'client';
                        $clientId = $currentClientId;
                        $vendorId = 0;
                    } elseif ($isVendorAdmin) {
                        $userType = 'vendor';
                        $vendorId = $currentVendorId;
                        $clientId = 0;
                    }

                    if ($userType === 'client') {
                        if ($clientId <= 0) {
                            $message = 'Client is required.';
                            $messageType = 'danger';
                            $action = '';
                        } elseif (!in_array($role, $clientRoles, true)) {
                            $message = 'Invalid role for Client user.';
                            $messageType = 'danger';
                            $action = '';
                        }
                        $vendorId = 0;
                    } elseif ($userType === 'vendor') {
                        if ($vendorId <= 0) {
                            $message = 'Vendor is required.';
                            $messageType = 'danger';
                            $action = '';
                        } elseif (!in_array($role, $vendorRoles, true)) {
                            $message = 'Invalid role for Vendor user.';
                            $messageType = 'danger';
                            $action = '';
                        }
                        $clientId = 0;
                    } else {
                        if (!in_array($role, $internalRoles, true)) {
                            $message = 'Invalid role for Internal user.';
                            $messageType = 'danger';
                            $action = '';
                        }
                        $clientId = 0;
                        $vendorId = 0;
                    }
                }
                
                if ($messageType !== 'danger') {
                    $check = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                    $check->bind_param("si", $username, $userId);
                    $check->execute();
                    if ($check->get_result()->num_rows > 0) {
                        $message = 'Username already exists.';
                        $messageType = 'danger';
                    } else {
                        $check2 = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                        $check2->bind_param("si", $email, $userId);
                        $check2->execute();
                        if ($check2->get_result()->num_rows > 0) {
                            $message = 'Email already exists.';
                            $messageType = 'danger';
                        }
                        $check2->close();
                    }
                    $check->close();

                    if ($messageType !== 'danger' && $empId !== null) {
                        $check3 = $conn->prepare("SELECT id FROM users WHERE employee_id = ? AND id != ?");
                        $check3->bind_param("si", $empId, $userId);
                        $check3->execute();
                        if ($check3->get_result()->num_rows > 0) {
                            $message = 'Employee ID already exists.';
                            $messageType = 'danger';
                        }
                        $check3->close();
                    }
                    if ($messageType !== 'danger') {
                        $uploadError = '';
                        $newProfilePic = null;
                        $hasUpload = isset($_FILES['profile_pic']) && isset($_FILES['profile_pic']['error']) && $_FILES['profile_pic']['error'] !== UPLOAD_ERR_NO_FILE;
                        if ($hasUpload) {
                            if ($_FILES['profile_pic']['error'] !== UPLOAD_ERR_OK) {
                                $uploadError = 'Profile image upload failed.';
                            } else {
                                $maxBytes = 2 * 1024 * 1024;
                                if ((int)($_FILES['profile_pic']['size'] ?? 0) > $maxBytes) {
                                    $uploadError = 'Profile image is too large (max 2MB).';
                                } else {
                                    $fileExt = strtolower(pathinfo((string)($_FILES['profile_pic']['name'] ?? ''), PATHINFO_EXTENSION));
                                    $allowed = ['jpg','jpeg','png','webp'];
                                    if (!in_array($fileExt, $allowed, true)) {
                                        $uploadError = 'Invalid image format. Allowed: JPG, PNG, WEBP.';
                                    } else {
                                        $uploadDir = __DIR__ . '/../../uploads/profiles/';
                                        if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }
                                        $newProfilePic = ['ext' => $fileExt, 'tmp' => (string)($_FILES['profile_pic']['tmp_name'] ?? '')];
                                    }
                                }
                            }
                        }
                        if ($uploadError !== '') {
                            $message = $uploadError;
                            $messageType = 'danger';
                        } else {
                        if ($personalEmail !== '' && !filter_var($personalEmail, FILTER_VALIDATE_EMAIL)) {
                            $message = 'Invalid personal email.';
                            $messageType = 'danger';
                        } elseif ($dob !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
                            $message = 'Invalid date of birth.';
                            $messageType = 'danger';
                        } elseif ($dob !== '') {
                            $age = (int)floor((time() - strtotime($dob)) / 31557600);
                            if ($age < 16) {
                                $message = 'Date of birth is not valid.';
                                $messageType = 'danger';
                            }
                        }
                        if ($messageType !== 'danger') {
                            if ($accountType !== '' && !in_array($accountType, ['Saving','Salary','Current'], true)) {
                                $message = 'Invalid account type.';
                                $messageType = 'danger';
                            } elseif ($ifsc !== '' && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
                                $message = 'Invalid IFSC code.';
                                $messageType = 'danger';
                            } elseif ($pan !== '' && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $pan)) {
                                $message = 'Invalid PAN number.';
                                $messageType = 'danger';
                            } elseif ($accountNumber !== '' && !preg_match('/^[0-9]{6,20}$/', $accountNumber)) {
                                $message = 'Invalid account number.';
                                $messageType = 'danger';
                            }
                        }
                        if ($messageType !== 'danger') {
                        if ($userId > 0) {
                            // Update
                            $sql = "UPDATE users SET 
                                    username = ?, full_name = ?, email = ?, role = ?, is_active = ?,
                                    employee_id = ?, date_of_joining = ?, job_title = ?, phone_number = ?, department = ?, client_id = ?, vendor_id = ?,
                                    reporting_manager_id = ?, onboarding_notes = ?
                                    WHERE id = ?";
                            $stmt = $conn->prepare($sql);
                            if (!$stmt) {
                                $message = 'Database error.';
                                $messageType = 'danger';
                            } else {
                                $types = "ssss" . "i" . str_repeat("s", 5) . str_repeat("i", 3) . "si";
                                $stmt->bind_param($types, $username, $fullName, $email, $role, $isActive, $empId, $dojVal, $jobTitle, $phone, $dept, $clientId, $vendorId, $reportingManagerId, $onboardingNotes, $userId);
                                if ($stmt->execute()) {
                                $saveDocs = function(int $uid) use ($conn, $currentUser, &$message, &$messageType): void {
                                    $maxBytes = 8 * 1024 * 1024;
                                    $allowedExt = ['pdf','jpg','jpeg','png'];
                                    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
                                    $docMap = [
                                        ['category' => 'Past Company', 'doc_type' => 'Offer Letter', 'key' => 'offer_letter_files'],
                                        ['category' => 'Past Company', 'doc_type' => 'Relieving Letter', 'key' => 'relieving_letter_files'],
                                        ['category' => 'Past Company', 'doc_type' => 'Experience Letter', 'key' => 'experience_letter_files'],
                                        ['category' => 'Salary Proof', 'doc_type' => 'Salary Slips', 'key' => 'salary_slip_files'],
                                        ['category' => 'Salary Proof', 'doc_type' => 'Bank Statements', 'key' => 'bank_statement_files'],
                                        ['category' => 'Education', 'doc_type' => 'Certificates / Degrees', 'key' => 'education_files'],
                                        ['category' => 'Identity Proof', 'doc_type' => 'Aadhaar Card', 'key' => 'aadhaar_files'],
                                        ['category' => 'Identity Proof', 'doc_type' => 'PAN Card', 'key' => 'pan_card_files'],
                                    ];
                                    foreach ($docMap as $d) {
                                        $key = (string)$d['key'];
                                        if (!isset($_FILES[$key]) || !is_array($_FILES[$key]['name'] ?? null)) continue;
                                        $names = $_FILES[$key]['name'];
                                        $tmps = $_FILES[$key]['tmp_name'];
                                        $errs = $_FILES[$key]['error'];
                                        $sizes = $_FILES[$key]['size'];
                                        $count = count($names);
                                        for ($i = 0; $i < $count; $i++) {
                                            $err = (int)($errs[$i] ?? UPLOAD_ERR_NO_FILE);
                                            if ($err === UPLOAD_ERR_NO_FILE) continue;
                                            if ($err !== UPLOAD_ERR_OK) { $message = 'Document upload failed.'; $messageType = 'danger'; return; }
                                            $size = (int)($sizes[$i] ?? 0);
                                            if ($size <= 0 || $size > $maxBytes) { $message = 'Invalid document size (max 8MB).'; $messageType = 'danger'; return; }
                                            $orig = (string)($names[$i] ?? '');
                                            $tmp = (string)($tmps[$i] ?? '');
                                            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                                            if (!in_array($ext, $allowedExt, true)) { $message = 'Invalid document type. Allowed: PDF, JPG, PNG.'; $messageType = 'danger'; return; }
                                            $mime = $finfo ? (string)finfo_file($finfo, $tmp) : null;
                                            if ($mime !== null && !in_array($mime, ['application/pdf','image/jpeg','image/png'], true)) { $message = 'Invalid document file.'; $messageType = 'danger'; return; }
                                            $baseDir = __DIR__ . '/../../uploads/user_documents/user_' . $uid . '/';
                                            $sub = preg_replace('/[^a-z0-9_]+/i', '_', strtolower((string)($d['doc_type'] ?? 'doc')));
                                            $dir = $baseDir . $sub . '/';
                                            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
                                            $rand = bin2hex(random_bytes(16));
                                            $fileName = $rand . '.' . $ext;
                                            $target = $dir . $fileName;
                                            if (!move_uploaded_file($tmp, $target)) { $message = 'Failed to store document.'; $messageType = 'danger'; return; }
                                            $rel = 'uploads/user_documents/user_' . $uid . '/' . $sub . '/' . $fileName;
                                            $ins = $conn->prepare("INSERT INTO user_documents (user_id, category, doc_type, file_path, original_name, mime_type, file_size, uploaded_by) VALUES (?,?,?,?,?,?,?,?)");
                                            if ($ins) {
                                                $category = (string)($d['category'] ?? '');
                                                $docType = (string)($d['doc_type'] ?? '');
                                                $uploadedBy = (int)($currentUser['id'] ?? 0);
                                                $ins->bind_param('isssssii', $uid, $category, $docType, $rel, $orig, $mime, $size, $uploadedBy);
                                                $ins->execute();
                                                $ins->close();
                                            }
                                        }
                                    }
                                    if ($finfo) finfo_close($finfo);
                                };
                                if ($userType === 'internal') {
                                    $stmtP = $conn->prepare("INSERT INTO user_personal_details (user_id, personal_email, emergency_contact_number, date_of_birth) VALUES (?,?,?,?)
                                        ON DUPLICATE KEY UPDATE personal_email = VALUES(personal_email), emergency_contact_number = VALUES(emergency_contact_number), date_of_birth = VALUES(date_of_birth), updated_at = NOW()");
                                    if ($stmtP) {
                                        $dobVal = ($dob !== '') ? $dob : null;
                                        $stmtP->bind_param('isss', $userId, $personalEmail, $emergencyContact, $dobVal);
                                        $stmtP->execute();
                                        $stmtP->close();
                                    }
                                    $stmtB = $conn->prepare("INSERT INTO user_bank_details (user_id, bank_name, account_number, account_type, ifsc_code, pan_number) VALUES (?,?,?,?,?,?)
                                        ON DUPLICATE KEY UPDATE bank_name = VALUES(bank_name), account_number = VALUES(account_number), account_type = VALUES(account_type), ifsc_code = VALUES(ifsc_code), pan_number = VALUES(pan_number), updated_at = NOW()");
                                    if ($stmtB) {
                                        $stmtB->bind_param('isssss', $userId, $bankName, $accountNumber, $accountType, $ifsc, $pan);
                                        $stmtB->execute();
                                        $stmtB->close();
                                    }
                                }
                                $saveDocs($userId);
                                if ($newProfilePic) {
                                    $currentPic = null;
                                    $st = $conn->prepare("SELECT profile_pic FROM users WHERE id = ? LIMIT 1");
                                    $st->bind_param('i', $userId);
                                    $st->execute();
                                    $currentPic = ($st->get_result()->fetch_assoc()['profile_pic'] ?? null);
                                    $st->close();

                                    $newFileName = 'profile_' . $userId . '_' . time() . '.' . $newProfilePic['ext'];
                                    $targetPath = __DIR__ . '/../../uploads/profiles/' . $newFileName;
                                    if (move_uploaded_file($newProfilePic['tmp'], $targetPath)) {
                                        if ($currentPic && file_exists(__DIR__ . '/../../' . $currentPic)) { @unlink(__DIR__ . '/../../' . $currentPic); }
                                        $rel = 'uploads/profiles/' . $newFileName;
                                        $st2 = $conn->prepare("UPDATE users SET profile_pic = ? WHERE id = ?");
                                        $st2->bind_param('si', $rel, $userId);
                                        $st2->execute();
                                        $st2->close();
                                    }
                                }
                                $message = 'User updated successfully.';
                                $messageType = 'success';
                                if ((int)($currentUser['id'] ?? 0) === $userId) {
                                    refreshUserSession($userId);
                                    $currentUser = getCurrentUser();
                                }
                                } else {
                                    $message = 'Unable to save changes.';
                                    $messageType = 'danger';
                                }
                                $stmt->close();
                            }
                        } else {
                            // Create
                            $password = $_POST['password'] ?? '123456'; // Default password if not provided
                            $hashed = password_hash($password, PASSWORD_DEFAULT);
                            $sql = "INSERT INTO users 
                                    (username, password, full_name, email, role, is_active, employee_id, date_of_joining, job_title, phone_number, department, client_id, vendor_id, reporting_manager_id, onboarding_notes)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                            $stmt = $conn->prepare($sql);
                            if (!$stmt) {
                                $message = 'Database error.';
                                $messageType = 'danger';
                            } else {
                                $stmt->bind_param("sssssisssssiiis", $username, $hashed, $fullName, $email, $role, $isActive, $empId, $dojVal, $jobTitle, $phone, $dept, $clientId, $vendorId, $reportingManagerId, $onboardingNotes);
                                if ($stmt->execute()) {
                                $newId = (int)$conn->insert_id;
                                $saveDocs = function(int $uid) use ($conn, $currentUser, &$message, &$messageType): void {
                                    $maxBytes = 8 * 1024 * 1024;
                                    $allowedExt = ['pdf','jpg','jpeg','png'];
                                    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
                                    $docMap = [
                                        ['category' => 'Past Company', 'doc_type' => 'Offer Letter', 'key' => 'offer_letter_files'],
                                        ['category' => 'Past Company', 'doc_type' => 'Relieving Letter', 'key' => 'relieving_letter_files'],
                                        ['category' => 'Past Company', 'doc_type' => 'Experience Letter', 'key' => 'experience_letter_files'],
                                        ['category' => 'Salary Proof', 'doc_type' => 'Salary Slips', 'key' => 'salary_slip_files'],
                                        ['category' => 'Salary Proof', 'doc_type' => 'Bank Statements', 'key' => 'bank_statement_files'],
                                        ['category' => 'Education', 'doc_type' => 'Certificates / Degrees', 'key' => 'education_files'],
                                        ['category' => 'Identity Proof', 'doc_type' => 'Aadhaar Card', 'key' => 'aadhaar_files'],
                                        ['category' => 'Identity Proof', 'doc_type' => 'PAN Card', 'key' => 'pan_card_files'],
                                    ];
                                    foreach ($docMap as $d) {
                                        $key = (string)$d['key'];
                                        if (!isset($_FILES[$key]) || !is_array($_FILES[$key]['name'] ?? null)) continue;
                                        $names = $_FILES[$key]['name'];
                                        $tmps = $_FILES[$key]['tmp_name'];
                                        $errs = $_FILES[$key]['error'];
                                        $sizes = $_FILES[$key]['size'];
                                        $count = count($names);
                                        for ($i = 0; $i < $count; $i++) {
                                            $err = (int)($errs[$i] ?? UPLOAD_ERR_NO_FILE);
                                            if ($err === UPLOAD_ERR_NO_FILE) continue;
                                            if ($err !== UPLOAD_ERR_OK) { $message = 'Document upload failed.'; $messageType = 'danger'; return; }
                                            $size = (int)($sizes[$i] ?? 0);
                                            if ($size <= 0 || $size > $maxBytes) { $message = 'Invalid document size (max 8MB).'; $messageType = 'danger'; return; }
                                            $orig = (string)($names[$i] ?? '');
                                            $tmp = (string)($tmps[$i] ?? '');
                                            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                                            if (!in_array($ext, $allowedExt, true)) { $message = 'Invalid document type. Allowed: PDF, JPG, PNG.'; $messageType = 'danger'; return; }
                                            $mime = $finfo ? (string)finfo_file($finfo, $tmp) : null;
                                            if ($mime !== null && !in_array($mime, ['application/pdf','image/jpeg','image/png'], true)) { $message = 'Invalid document file.'; $messageType = 'danger'; return; }
                                            $baseDir = __DIR__ . '/../../uploads/user_documents/user_' . $uid . '/';
                                            $sub = preg_replace('/[^a-z0-9_]+/i', '_', strtolower((string)($d['doc_type'] ?? 'doc')));
                                            $dir = $baseDir . $sub . '/';
                                            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
                                            $rand = bin2hex(random_bytes(16));
                                            $fileName = $rand . '.' . $ext;
                                            $target = $dir . $fileName;
                                            if (!move_uploaded_file($tmp, $target)) { $message = 'Failed to store document.'; $messageType = 'danger'; return; }
                                            $rel = 'uploads/user_documents/user_' . $uid . '/' . $sub . '/' . $fileName;
                                            $ins = $conn->prepare("INSERT INTO user_documents (user_id, category, doc_type, file_path, original_name, mime_type, file_size, uploaded_by) VALUES (?,?,?,?,?,?,?,?)");
                                            if ($ins) {
                                                $category = (string)($d['category'] ?? '');
                                                $docType = (string)($d['doc_type'] ?? '');
                                                $uploadedBy = (int)($currentUser['id'] ?? 0);
                                                $ins->bind_param('isssssii', $uid, $category, $docType, $rel, $orig, $mime, $size, $uploadedBy);
                                                $ins->execute();
                                                $ins->close();
                                            }
                                        }
                                    }
                                    if ($finfo) finfo_close($finfo);
                                };
                                if ($newId > 0 && $empIdRaw === '' && $userType === 'internal') {
                                    $autoEmp = 'EMP' . str_pad((string)$newId, 5, '0', STR_PAD_LEFT);
                                    $stEmp = $conn->prepare("UPDATE users SET employee_id = ? WHERE id = ?");
                                    if ($stEmp) {
                                        $stEmp->bind_param('si', $autoEmp, $newId);
                                        $stEmp->execute();
                                        $stEmp->close();
                                    }
                                }
                                if ($newId > 0 && $userType === 'internal') {
                                    $stmtP = $conn->prepare("INSERT INTO user_personal_details (user_id, personal_email, emergency_contact_number, date_of_birth) VALUES (?,?,?,?)
                                        ON DUPLICATE KEY UPDATE personal_email = VALUES(personal_email), emergency_contact_number = VALUES(emergency_contact_number), date_of_birth = VALUES(date_of_birth), updated_at = NOW()");
                                    if ($stmtP) {
                                        $dobVal = ($dob !== '') ? $dob : null;
                                        $stmtP->bind_param('isss', $newId, $personalEmail, $emergencyContact, $dobVal);
                                        $stmtP->execute();
                                        $stmtP->close();
                                    }
                                    $stmtB = $conn->prepare("INSERT INTO user_bank_details (user_id, bank_name, account_number, account_type, ifsc_code, pan_number) VALUES (?,?,?,?,?,?)
                                        ON DUPLICATE KEY UPDATE bank_name = VALUES(bank_name), account_number = VALUES(account_number), account_type = VALUES(account_type), ifsc_code = VALUES(ifsc_code), pan_number = VALUES(pan_number), updated_at = NOW()");
                                    if ($stmtB) {
                                        $stmtB->bind_param('isssss', $newId, $bankName, $accountNumber, $accountType, $ifsc, $pan);
                                        $stmtB->execute();
                                        $stmtB->close();
                                    }
                                }
                                if ($newId > 0) $saveDocs($newId);
                                if ($newId > 0 && $newProfilePic) {
                                    $newFileName = 'profile_' . $newId . '_' . time() . '.' . $newProfilePic['ext'];
                                    $targetPath = __DIR__ . '/../../uploads/profiles/' . $newFileName;
                                    if (move_uploaded_file($newProfilePic['tmp'], $targetPath)) {
                                        $rel = 'uploads/profiles/' . $newFileName;
                                        $st2 = $conn->prepare("UPDATE users SET profile_pic = ? WHERE id = ?");
                                        $st2->bind_param('si', $rel, $newId);
                                        $st2->execute();
                                        $st2->close();
                                    }
                                }
                                $message = 'User created successfully.';
                                $messageType = 'success';
                                } else {
                                    $message = 'Unable to create user.';
                                    $messageType = 'danger';
                                }
                                $stmt->close();
                            }
                        }
                        }
                    }
                        }
                }
            }
        } elseif ($action === 'delete') {
            $delId = (int)($_POST['user_id'] ?? 0);
            if ($delId === (int)$currentUser['id']) {
                $message = 'You cannot delete yourself.';
                $messageType = 'danger';
            } else {
                $conn = getDbConnection();
                if (!$isAdminUser) {
                    $stmtScope = $conn->prepare("SELECT client_id, vendor_id FROM users WHERE id = ? LIMIT 1");
                    $stmtScope->bind_param('i', $delId);
                    $stmtScope->execute();
                    $ex = $stmtScope->get_result()->fetch_assoc() ?: null;
                    $stmtScope->close();
                    if (!$ex) {
                        $message = 'User not found.';
                        $messageType = 'danger';
                    } elseif ($isClientAdmin && (int)($ex['client_id'] ?? 0) !== $currentClientId) {
                        $message = 'Not allowed.';
                        $messageType = 'danger';
                    } elseif ($isVendorAdmin && (int)($ex['vendor_id'] ?? 0) !== $currentVendorId) {
                        $message = 'Not allowed.';
                        $messageType = 'danger';
                    }
                }
                if ($messageType !== 'danger') {
                $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                $stmt->bind_param("i", $delId);
                if ($stmt->execute()) {
                    $message = 'User deleted successfully.';
                    $messageType = 'success';
                }
                }
            }
        } elseif ($action === 'delete_doc') {
            $docId = (int)($_POST['doc_id'] ?? 0);
            if ($docId > 0) {
                $conn = getDbConnection();
                $st = $conn->prepare("SELECT file_path FROM user_documents WHERE id = ?");
                if ($st) {
                    $st->bind_param('i', $docId);
                    $st->execute();
                    $res = $st->get_result()->fetch_assoc();
                    if ($res && !empty($res['file_path'])) {
                        $p = __DIR__ . '/../../' . $res['file_path'];
                        if (file_exists($p)) @unlink($p);
                    }
                    $st->close();
                    
                    $del = $conn->prepare("DELETE FROM user_documents WHERE id = ?");
                    if ($del) {
                        $del->bind_param('i', $docId);
                        $del->execute();
                        $del->close();
                        $message = 'Document deleted successfully.';
                        $messageType = 'success';
                    }
                }
            }
        }
    }
}

$view = $_GET['view'] ?? 'cards';
if (!in_array($view, ['cards','list'], true)) $view = 'cards';

$userTypeTab = $_GET['user_type'] ?? 'internal';
if (!in_array($userTypeTab, ['internal','external'], true)) $userTypeTab = 'internal';

$search = trim((string)($_GET['search'] ?? ''));
$filterRole = normalizeRole(trim((string)($_GET['role'] ?? '')));
$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterDept = trim((string)($_GET['dept'] ?? ''));
$filterHasPic = trim((string)($_GET['has_pic'] ?? ''));

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 12;
$offset = ($page - 1) * $limit;

// Fetch users with entity visibility and filters
$conn = getDbConnection();

// Get unique roles and depts for dropdowns
$customRolesCfg = function_exists('getCustomRolesConfig') ? getCustomRolesConfig() : [];
$customInternalRoleKeys = [];
$customClientRoleKeys = [];
$customVendorRoleKeys = [];
foreach ($customRolesCfg as $rk => $rv) {
    if (!is_array($rv)) continue;
    $scope = (string)($rv['scope'] ?? 'internal');
    $rk = normalizeRole((string)$rk);
    if ($rk === '') continue;
    if ($scope === 'client') $customClientRoleKeys[$rk] = true;
    elseif ($scope === 'vendor') $customVendorRoleKeys[$rk] = true;
    else $customInternalRoleKeys[$rk] = true;
}
$roleOptions = [];
if ($userTypeTab === 'internal') {
    $roleOptions = [
        'admin',
        'director',
        'manager_director',
        'operations_director',
        'operations_manager',
        'operations_agent',
        'email_marketing_director',
        'email_marketing_manager',
        'email_marketing_agent',
        'qa_director',
        'qa_manager',
        'qa_agent',
        'sales_director',
        'sales_manager',
        'sdr',
    ];
    foreach (array_keys($customInternalRoleKeys) as $rk) {
        if (!in_array($rk, $roleOptions, true)) $roleOptions[] = $rk;
    }
} else {
    $roleOptions = [
        'client_admin',
        'client_sdr',
        'vendor_admin',
        'vendor_user',
    ];
    foreach (array_keys($customClientRoleKeys) as $rk) {
        if (!in_array($rk, $roleOptions, true)) $roleOptions[] = $rk;
    }
    foreach (array_keys($customVendorRoleKeys) as $rk) {
        if (!in_array($rk, $roleOptions, true)) $roleOptions[] = $rk;
    }
}

$allDeptsResult = $conn->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != ''");
$deptOptions = [];
if ($allDeptsResult) {
    while ($r = $allDeptsResult->fetch_assoc()) { $deptOptions[] = $r['department']; }
}
sort($deptOptions);

$where = ["1=1"];
$params = [];
$types = "";

if ($isAdminUser) {
    // Admin sees all
} elseif ($isClientAdmin && $currentClientId > 0) {
    $where[] = "u.client_id = ?";
    $params[] = $currentClientId;
    $types .= "i";
} elseif ($isVendorAdmin && $currentVendorId > 0) {
    $where[] = "u.vendor_id = ?";
    $params[] = $currentVendorId;
    $types .= "i";
}

if ($userTypeTab === 'internal') {
    $where[] = "(u.client_id IS NULL OR u.client_id = 0) AND (u.vendor_id IS NULL OR u.vendor_id = 0)";
} else {
    $where[] = "(u.client_id > 0 OR u.vendor_id > 0)";
}

if ($search !== '') {
    $where[] = "(u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.employee_id LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= "ssss";
}
if ($filterRole !== '') {
    $roleList = getRoleAliases($filterRole);
    if (count($roleList) === 1) {
        $where[] = "u.role = ?";
        $params[] = $roleList[0];
        $types .= "s";
    } else {
        $in = implode(',', array_fill(0, count($roleList), '?'));
        $where[] = "u.role IN ($in)";
        foreach ($roleList as $rr) { $params[] = $rr; $types .= "s"; }
    }
}
if ($filterStatus !== '') {
    $where[] = "u.is_active = ?";
    $params[] = (int)$filterStatus;
    $types .= "i";
}
if ($filterDept !== '') {
    $where[] = "u.department = ?";
    $params[] = $filterDept;
    $types .= "s";
}
if ($filterHasPic === '1') {
    $where[] = "(u.profile_pic IS NOT NULL AND u.profile_pic != '')";
}

$whereClause = implode(" AND ", $where);
$exportMode = trim((string)($_GET['export'] ?? ''));

$getTableColumns = static function(string $table) use ($conn): array {
    $table = strtolower(trim($table));
    if ($table === '') {
        return [];
    }
    $cols = [];
    $stmtCols = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION");
    if ($stmtCols) {
        $stmtCols->bind_param('s', $table);
        $stmtCols->execute();
        $rsCols = $stmtCols->get_result();
        while ($row = $rsCols->fetch_assoc()) {
            $col = trim((string)($row['COLUMN_NAME'] ?? ''));
            if ($col !== '') {
                $cols[] = $col;
            }
        }
        $stmtCols->close();
    }
    return $cols;
};

if ($exportMode === 'csv') {
    $userColumns = $getTableColumns('users');
    $personalColumns = $getTableColumns('user_personal_details');
    $bankColumns = $getTableColumns('user_bank_details');
    $clientColumns = $getTableColumns('clients');
    $vendorColumns = $getTableColumns('vendors');
    $documentColumns = $getTableColumns('user_documents');
    $sensitiveUserColumns = [
        'password',
        'password_hash',
        'remember_token',
        'reset_token',
        'reset_token_hash',
        'reset_token_expires_at',
        'verification_token',
        'activation_token',
        'otp_secret',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'mfa_secret',
        'auth_secret',
        'session_token',
    ];
    $exportSelects = [];
    $exportHeaders = [];
    $addExportColumn = static function(string $expression, string $alias) use (&$exportSelects, &$exportHeaders): void {
        $exportSelects[] = $expression . ' AS `' . $alias . '`';
        $exportHeaders[] = $alias;
    };

    foreach ($userColumns as $column) {
        if (in_array(strtolower($column), $sensitiveUserColumns, true)) {
            continue;
        }
        $addExportColumn('u.`' . $column . '`', $column);
    }

    $addExportColumn("CASE WHEN (u.client_id IS NOT NULL AND u.client_id > 0) THEN 'Client' WHEN (u.vendor_id IS NOT NULL AND u.vendor_id > 0) THEN 'Vendor' ELSE 'Internal' END", 'user_scope');
    $addExportColumn("CASE WHEN u.is_active = 1 THEN 'Active' ELSE 'Inactive' END", 'account_status_label');
    $addExportColumn('m.`full_name`', 'reporting_manager_name');

    if (in_array('client_code', $clientColumns, true)) {
        $addExportColumn('c.`client_code`', 'client_code');
    }
    if (in_array('name', $clientColumns, true)) {
        $addExportColumn('c.`name`', 'client_name');
    }
    if (in_array('vendor_code', $vendorColumns, true)) {
        $addExportColumn('v.`vendor_code`', 'vendor_code');
    }
    if (in_array('name', $vendorColumns, true)) {
        $addExportColumn('v.`name`', 'vendor_name');
    }

    $personalFieldMap = [
        'personal_email' => 'personal_email',
        'emergency_contact_number' => 'emergency_contact_number',
        'date_of_birth' => 'date_of_birth',
        'created_at' => 'personal_details_created_at',
        'updated_at' => 'personal_details_updated_at',
    ];
    foreach ($personalFieldMap as $column => $alias) {
        if (in_array($column, $personalColumns, true)) {
            $addExportColumn('p.`' . $column . '`', $alias);
        }
    }

    $bankFieldMap = [
        'bank_name' => 'bank_name',
        'account_number' => 'account_number',
        'account_type' => 'account_type',
        'ifsc_code' => 'ifsc_code',
        'pan_number' => 'pan_number',
        'created_at' => 'bank_details_created_at',
        'updated_at' => 'bank_details_updated_at',
    ];
    foreach ($bankFieldMap as $column => $alias) {
        if (in_array($column, $bankColumns, true)) {
            $addExportColumn('b.`' . $column . '`', $alias);
        }
    }

    if (!empty($documentColumns)) {
        $addExportColumn('(SELECT COUNT(*) FROM user_documents ud WHERE ud.user_id = u.id)', 'documents_count');
    }

    $exportSql = "
        SELECT " . implode(",\n               ", $exportSelects) . "
        FROM users u
        LEFT JOIN users m ON m.id = u.reporting_manager_id
        " . (!empty($clientColumns) ? "LEFT JOIN clients c ON c.id = u.client_id\n" : '') . "
        " . (!empty($vendorColumns) ? "LEFT JOIN vendors v ON v.id = u.vendor_id\n" : '') . "
        " . (!empty($personalColumns) ? "LEFT JOIN user_personal_details p ON p.user_id = u.id\n" : '') . "
        " . (!empty($bankColumns) ? "LEFT JOIN user_bank_details b ON b.user_id = u.id\n" : '') . "
        WHERE $whereClause
        ORDER BY u.role, u.full_name
    ";
    $exportStmt = $conn->prepare($exportSql);
    if ($types) {
        $exportStmt->bind_param($types, ...$params);
    }
    $exportStmt->execute();
    $exportRows = $exportStmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $exportStmt->close();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="users_export_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $exportHeaders);
    foreach ($exportRows as $row) {
        $csvRow = [];
        foreach ($exportHeaders as $headerName) {
            $csvRow[] = $row[$headerName] ?? '';
        }
        fputcsv($out, $csvRow);
    }
    fclose($out);
    exit;
}

// Get total count
$countSql = "SELECT COUNT(*) as cnt FROM users u WHERE $whereClause";
$countStmt = $conn->prepare($countSql);
if ($types) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalUsers = (int)(($countStmt->get_result()->fetch_assoc() ?: [])['cnt'] ?? 0);
$countStmt->close();

$totalPages = max(1, (int)ceil($totalUsers / $limit));

$sql = "
    SELECT u.*,
           p.personal_email, p.emergency_contact_number, p.date_of_birth,
           b.bank_name, b.account_number, b.account_type, b.ifsc_code, b.pan_number
    FROM users u
    LEFT JOIN user_personal_details p ON p.user_id = u.id
    LEFT JOIN user_bank_details b ON b.user_id = u.id
    WHERE $whereClause
    ORDER BY u.role, u.full_name
    LIMIT $limit OFFSET $offset
";
$stmt = $conn->prepare($sql);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

// Fetch docs for these users to display in modal
$userDocs = [];
if (!empty($users)) {
    $uids = array_map(function($u) { return (int)$u['id']; }, $users);
    $in = implode(',', $uids);
    $docsResult = $conn->query("SELECT id, user_id, category, doc_type, file_path, original_name, file_size FROM user_documents WHERE user_id IN ($in) ORDER BY category, doc_type");
    if ($docsResult) {
        while ($d = $docsResult->fetch_assoc()) {
            $userDocs[(int)$d['user_id']][] = $d;
        }
    }
}
foreach ($users as &$uu) {
    $uu['documents'] = $userDocs[(int)$uu['id']] ?? [];
    $uu['role'] = normalizeRole((string)($uu['role'] ?? ''));
}
unset($uu);

// Build query string helper
function buildUrl(array $changes): string {
    $params = $_GET;
    foreach ($changes as $k => $v) {
        if ($v === null || $v === '') unset($params[$k]);
        else $params[$k] = $v;
    }
    return 'manage-users.php?' . http_build_query($params);
}

function roleLabel(string $role): string {
    return getRoleLabelFull($role);
}

function roleBadgeClass(string $role): string {
    return getRoleBadgeClass($role);
}

function userTypeFromRow(array $u): string {
    if ((int)($u['client_id'] ?? 0) > 0) return 'client';
    if ((int)($u['vendor_id'] ?? 0) > 0) return 'vendor';
    return 'internal';
}

function userTypeLabelFromRow(array $u): string {
    $t = userTypeFromRow($u);
    if ($t === 'client') return 'Client';
    if ($t === 'vendor') return 'Vendor';
    return 'Internal';
}

?>
<?php $pageTitle = 'User Management'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>

    <div class="container-fluid px-0 users-workspace">
        <div class="d-flex justify-content-between align-items-center mb-4 users-page-header">
            <div>
                <h2 class="h3 mb-1">User Management</h2>
                <p class="text-muted small mb-0">Manage system users, job titles, access profiles, and onboarding information.</p>
            </div>
            <div class="d-flex align-items-center gap-2 users-page-actions">
                <a class="btn btn-outline-success shadow-sm" href="<?php echo htmlspecialchars(buildUrl(['export' => 'csv', 'page' => null])); ?>">
                    <i class="bi bi-download me-2"></i>Export Users
                </a>
                <div class="btn-group btn-group-sm" role="group" aria-label="View toggle">
                    <a class="btn btn-outline-primary <?php echo $view === 'list' ? 'active' : ''; ?>" href="manage-users.php?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['view' => 'list']))); ?>">List</a>
                    <a class="btn btn-outline-primary <?php echo $view === 'cards' ? 'active' : ''; ?>" href="manage-users.php?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['view' => 'cards']))); ?>">Cards</a>
                </div>
                <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#userModal" onclick="resetUserForm()">
                    <i class="bi bi-person-plus-fill me-2"></i>Add New User
                </button>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-2 align-items-end users-filter-grid">
                    <div class="col-lg-4">
                        <label class="form-label small text-muted">Search</label>
                        <input class="form-control form-control-sm" id="userFilterSearch" placeholder="Name / username / email / employee id">
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label small text-muted">Access Role</label>
                        <select class="form-select form-select-sm" id="userFilterRole">
                            <option value="">All</option>
                            <?php foreach ($roleOptions as $r): ?>
                                <option value="<?php echo htmlspecialchars($r); ?>"><?php echo htmlspecialchars(roleLabel($r)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-2">
                        <label class="form-label small text-muted">Status</label>
                        <select class="form-select form-select-sm" id="userFilterStatus">
                            <option value="">All</option>
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label small text-muted">Department</label>
                        <select class="form-select form-select-sm" id="userFilterDept">
                            <option value="">All</option>
                            <?php foreach ($deptOptions as $d): ?>
                                <option value="<?php echo htmlspecialchars($d); ?>"><?php echo htmlspecialchars($d); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 d-flex justify-content-between align-items-center mt-2">
                        <div class="d-flex gap-2">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="userFilterHasPic">
                                <label class="form-check-label small text-muted" for="userFilterHasPic">Has profile photo</label>
                            </div>
                        </div>
                        <button class="btn btn-outline-secondary btn-sm" type="button" id="userFilterClear"><i class="bi bi-x-circle"></i> Clear</button>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($view === 'list'): ?>
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th class="serial-col ps-3">#</th>
                                <th>Photo</th>
                                <th>User</th>
                                <?php if ($userTypeTab === 'external'): ?><th>Type</th><?php endif; ?>
                                <th>Job Title</th>
                                <th>Status</th>
                                <?php if ($userTypeTab === 'internal'): ?>
                                    <th>Employee ID</th>
                                    <th>Department</th>
                                    <th>Joining</th>
                                <?php endif; ?>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $i => $u): ?>
                                        <?php $role = (string)($u['role'] ?? 'agent'); ?>
                                        <?php $type = userTypeFromRow($u); ?>
                                        <tr>
                                            <td class="serial-col ps-3 text-muted small"><?php echo $offset + $i + 1; ?></td>
                                            <td style="width:56px;">
                                                <?php if (!empty($u['profile_pic'])): ?>
                                                    <img src="../../<?php echo htmlspecialchars((string)$u['profile_pic']); ?>" style="width:32px;height:32px;border-radius:999px;object-fit:cover;">
                                                <?php else: ?>
                                                    <div style="width:32px;height:32px;border-radius:999px;background:#eef2ff;color:#334155;display:flex;align-items:center;justify-content:center;font-weight:700;">
                                                        <?php echo strtoupper(substr((string)($u['full_name'] ?? 'U'), 0, 1)); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars((string)($u['full_name'] ?? 'User')); ?></div>
                                                <div class="text-muted small">@<?php echo htmlspecialchars((string)($u['username'] ?? 'username')); ?> · <?php echo htmlspecialchars((string)($u['email'] ?? '')); ?></div>
                                            </td>
                                            <?php if ($userTypeTab === 'external'): ?>
                                                <td><span class="badge bg-secondary-subtle text-secondary border"><?php echo htmlspecialchars(userTypeLabelFromRow($u)); ?></span></td>
                                            <?php endif; ?>
                                            <td><span class="text-muted small"><?php echo htmlspecialchars((string)($u['job_title'] ?? 'N/A')); ?></span></td>
                                            <td>
                                                <?php if (!empty($u['is_active'])): ?>
                                                    <span class="badge bg-success-subtle text-success border border-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <?php if ($userTypeTab === 'internal'): ?>
                                                <td><?php echo htmlspecialchars((string)($u['employee_id'] ?? '')); ?></td>
                                                <td><?php echo htmlspecialchars((string)($u['department'] ?? '')); ?></td>
                                                <td><?php echo (!empty($u['date_of_joining'])) ? date('M d, Y', strtotime((string)$u['date_of_joining'])) : ''; ?></td>
                                            <?php endif; ?>
                                            <td class="text-end pe-3">
                                                <div class="btn-group btn-group-sm users-row-actions" role="group">
                                                    <a class="btn btn-light border" href="profile.php?user_id=<?php echo (int)$u['id']; ?>" title="View"><i class="bi bi-eye"></i></a>
                                                    <button class="btn btn-light border" type="button" onclick='editUser(<?php echo json_encode($u); ?>)' title="Edit"><i class="bi bi-pencil"></i></button>
                                                    <a class="btn btn-light border" href="../auth/reset-password.php?user_id=<?php echo (int)$u['id']; ?>" title="Reset Password"><i class="bi bi-key"></i></a>
                                                    <?php if (((int)($u['id'] ?? 0)) != ((int)($currentUser['id'] ?? -1))): ?>
                                                        <button class="btn btn-light border text-danger" type="button" onclick="confirmDelete(<?php echo (int)$u['id']; ?>, '<?php echo addslashes((string)($u['full_name'] ?? '')); ?>')" title="Delete"><i class="bi bi-trash"></i></button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($users as $u): ?>
                    <?php $role = (string)($u['role'] ?? 'agent'); ?>
                    <?php $type = userTypeFromRow($u); ?>
                    <div class="col-xl-4 col-lg-6">
                        <div class="card user-card h-100 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex align-items-start justify-content-between mb-3">
                                    <div class="d-flex align-items-center gap-3">
                                        <?php if (!empty($u['profile_pic'])): ?>
                                            <img src="../../<?php echo htmlspecialchars((string)$u['profile_pic']); ?>" class="user-avatar-lg">
                                        <?php else: ?>
                                            <div class="user-avatar-lg"><?php echo strtoupper(substr((string)($u['full_name'] ?? 'U'), 0, 1)); ?></div>
                                        <?php endif; ?>
                                        <div>
                                            <h5 class="mb-0 fw-bold"><?php echo htmlspecialchars((string)($u['full_name'] ?? 'User')); ?></h5>
                                            <span class="text-muted small">@<?php echo htmlspecialchars((string)($u['username'] ?? 'username')); ?></span>
                                            <div class="mt-1">
                                                <?php if ($userTypeTab === 'external'): ?>
                                                    <span class="badge bg-secondary-subtle text-secondary border status-badge"><?php echo htmlspecialchars(userTypeLabelFromRow($u)); ?></span>
                                                <?php endif; ?>
                                                <span class="badge bg-primary-subtle text-primary border status-badge"><?php echo htmlspecialchars((string)($u['job_title'] ?? 'N/A')); ?></span>
                                                <?php if (!empty($u['is_active'])): ?>
                                                    <span class="badge bg-success-subtle text-success border border-success status-badge">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary status-badge">Inactive</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="d-flex gap-1 flex-wrap justify-content-end users-row-actions">
                                        <a class="btn btn-sm btn-light border" href="profile.php?user_id=<?php echo (int)$u['id']; ?>" title="View"><i class="bi bi-eye"></i></a>
                                        <button class="btn btn-sm btn-light border" type="button" onclick='editUser(<?php echo json_encode($u); ?>)' title="Edit"><i class="bi bi-pencil"></i></button>
                                        <a class="btn btn-sm btn-light border" href="../auth/reset-password.php?user_id=<?php echo (int)$u['id']; ?>" title="Reset Password"><i class="bi bi-key"></i></a>
                                        <?php if (((int)($u['id'] ?? 0)) != ((int)($currentUser['id'] ?? -1))): ?>
                                            <button class="btn btn-sm btn-light border text-danger" type="button" onclick="confirmDelete(<?php echo (int)$u['id']; ?>, '<?php echo addslashes((string)($u['full_name'] ?? '')); ?>')" title="Delete"><i class="bi bi-trash"></i></button>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="row g-3 mt-2">
                                    <?php if ($userTypeTab === 'internal'): ?>
                                        <div class="col-6">
                                            <div class="field-label">Employee ID</div>
                                            <div class="field-value"><?php echo htmlspecialchars((string)($u['employee_id'] ?? 'N/A')); ?></div>
                                        </div>
                                        <div class="col-6">
                                            <div class="field-label">Joining Date</div>
                                            <div class="field-value"><?php echo (!empty($u['date_of_joining'])) ? date('M d, Y', strtotime((string)$u['date_of_joining'])) : 'N/A'; ?></div>
                                        </div>
                                        <div class="col-6">
                                            <div class="field-label">Job Title</div>
                                            <div class="field-value"><?php echo htmlspecialchars((string)($u['job_title'] ?? 'N/A')); ?></div>
                                        </div>
                                        <div class="col-6">
                                            <div class="field-label">Department</div>
                                            <div class="field-value"><?php echo htmlspecialchars((string)($u['department'] ?? 'N/A')); ?></div>
                                        </div>
                                    <?php endif; ?>
                                    <div class="col-12">
                                        <div class="field-label">Email & Phone</div>
                                        <div class="field-value d-flex align-items-center gap-2">
                                            <a href="mailto:<?php echo htmlspecialchars((string)($u['email'] ?? '')); ?>" class="text-decoration-none text-truncate" style="max-width:180px;"><i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars((string)($u['email'] ?? '')); ?></a>
                                            <?php if (!empty($u['phone_number'])): ?>
                                                <span class="text-muted">·</span>
                                                <a href="tel:<?php echo htmlspecialchars((string)$u['phone_number']); ?>" class="text-decoration-none text-muted"><i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars((string)$u['phone_number']); ?></a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($totalUsers > 0): ?>
            <div class="d-flex justify-content-between align-items-center mt-3">
                <?php
                    $startNum = $totalUsers > 0 ? ($offset + 1) : 0;
                    $endNum = min($offset + count($users), $totalUsers);
                ?>
                <div class="text-muted small">Showing <?php echo number_format($startNum); ?>–<?php echo number_format($endNum); ?> of <?php echo number_format($totalUsers); ?></div>
                <?php if ($totalPages > 1): ?>
                    <nav aria-label="Users pagination">
                        <ul class="pagination pagination-sm mb-0">
                            <?php $prevPage = max(1, $page - 1); $nextPage = min($totalPages, $page + 1); ?>
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars(buildUrl(['page' => $prevPage])); ?>">Prev</a>
                            </li>
                            <?php
                                $window = 2;
                                $candidates = array_merge([1, $totalPages], range(max(1, $page - $window), min($totalPages, $page + $window)));
                                $pagesList = array_values(array_unique($candidates));
                                sort($pagesList);
                                $last = 0;
                                foreach ($pagesList as $p) {
                                    if ($last && $p > $last + 1) {
                                        echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                                    }
                                    $isActive = ($p === $page);
                                    echo '<li class="page-item ' . ($isActive ? 'active' : '') . '">';
                                    echo '<a class="page-link" href="' . htmlspecialchars(buildUrl(['page' => $p])) . '">' . (int)$p . '</a>';
                                    echo '</li>';
                                    $last = $p;
                                }
                            ?>
                            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars(buildUrl(['page' => $nextPage])); ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- User Add/Edit Modal -->
    <div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <form id="userForm" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_user">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="user_id" id="userId">
                    
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="modalTitle">Add New User</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
                            <div class="fw-semibold">Employee Onboarding</div>
                            <div class="text-muted small">Complete the sections to create a full employee profile.</div>
                        </div>
                        <div class="progress mb-3" style="height: 8px;">
                            <div class="progress-bar" role="progressbar" style="width: 20%;" id="onboardProgress"></div>
                        </div>
                        <ul class="nav nav-pills nav-fill gap-2 mb-3" id="onboardTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="tab-basic" data-bs-toggle="pill" data-bs-target="#pane-basic" type="button" role="tab" aria-controls="pane-basic" aria-selected="true">Basic</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="tab-personal" data-bs-toggle="pill" data-bs-target="#pane-personal" type="button" role="tab" aria-controls="pane-personal" aria-selected="false">Personal</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="tab-employment" data-bs-toggle="pill" data-bs-target="#pane-employment" type="button" role="tab" aria-controls="pane-employment" aria-selected="false">Employment</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="tab-bank" data-bs-toggle="pill" data-bs-target="#pane-bank" type="button" role="tab" aria-controls="pane-bank" aria-selected="false">Bank</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="tab-docs" data-bs-toggle="pill" data-bs-target="#pane-docs" type="button" role="tab" aria-controls="pane-docs" aria-selected="false">Documents</button>
                            </li>
                        </ul>
                        <div class="tab-content" id="onboardTabContent">
                            <div class="tab-pane fade show active" id="pane-basic" role="tabpanel" aria-labelledby="tab-basic" tabindex="0">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <div class="d-flex align-items-center gap-3">
                                            <div id="userAvatarBox">
                                                <div class="user-avatar-lg">U</div>
                                            </div>
                                            <div>
                                                <label for="userProfilePic" class="btn btn-light border btn-sm">
                                                    <i class="bi bi-camera me-1"></i>Upload Profile Photo
                                                </label>
                                                <input type="file" name="profile_pic" id="userProfilePic" accept=".jpg,.jpeg,.png,.webp" style="display:none" onchange="previewUserPic(this)">
                                                <div class="text-muted small mt-1">JPG, PNG, WEBP (max 2MB)</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">Full Name *</label>
                                        <input type="text" class="form-control" name="full_name" id="fullName" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">Username *</label>
                                        <input type="text" class="form-control" name="username" id="userName" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">Email *</label>
                                        <input type="email" class="form-control" name="email" id="userEmail" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">Phone Number</label>
                                        <input type="text" class="form-control" name="phone_number" id="userPhone" inputmode="tel">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small">User Type *</label>
                                        <select class="form-select form-select-sm" name="user_type" id="userType" required <?php echo ($isClientAdmin || $isVendorAdmin) ? 'disabled' : ''; ?>>
                                            <option value="internal">Internal</option>
                                            <option value="client">Client</option>
                                            <option value="vendor">Vendor</option>
                                        </select>
                                        <?php if ($isClientAdmin): ?>
                                            <input type="hidden" name="user_type" value="client">
                                        <?php elseif ($isVendorAdmin): ?>
                                            <input type="hidden" name="user_type" value="vendor">
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label fw-bold small">Access Role *</label>
                                        <select class="form-select form-select-sm" name="role" id="userRole" required>
                                            <optgroup label="System">
                                                <option value="admin" data-scope="internal">System – Admin</option>
                                            </optgroup>
                                            <optgroup label="Management">
                                                <option value="director" data-scope="internal">Management – Director</option>
                                                <option value="manager_director" data-scope="internal">Management – Manager Director</option>
                                            </optgroup>
                                            <optgroup label="Operations">
                                                <option value="operations_director" data-scope="internal">Operations – Director</option>
                                                <option value="operations_manager" data-scope="internal">Operations – Manager</option>
                                                <option value="operations_agent" data-scope="internal">Operations – Agent</option>
                                            </optgroup>
                                            <optgroup label="Email Marketing">
                                                <option value="email_marketing_director" data-scope="internal">Email Marketing – Director</option>
                                                <option value="email_marketing_manager" data-scope="internal">Email Marketing – Manager</option>
                                                <option value="email_marketing_agent" data-scope="internal">Email Marketing – Agent</option>
                                            </optgroup>
                                            <optgroup label="Quality Assurance">
                                                <option value="qa_director" data-scope="internal">Quality Assurance – Director</option>
                                                <option value="qa_manager" data-scope="internal">Quality Assurance – Manager</option>
                                                <option value="qa_agent" data-scope="internal">Quality Assurance – Agent</option>
                                            </optgroup>
                                            <optgroup label="Sales">
                                                <option value="sales_director" data-scope="internal">Sales – Director</option>
                                                <option value="sales_manager" data-scope="internal">Sales – Manager</option>
                                                <option value="sdr" data-scope="internal">Sales – SDR</option>
                                            </optgroup>
                                            <optgroup label="Client">
                                                <option value="client_admin" data-scope="client">Client – Admin</option>
                                                <option value="client_sdr" data-scope="client">Client – SDR</option>
                                            </optgroup>
                                            <optgroup label="Vendor">
                                                <option value="vendor_admin" data-scope="vendor">Vendor – Admin</option>
                                                <option value="vendor_user" data-scope="vendor">Vendor – User</option>
                                            </optgroup>
                                            <?php
                                                $customRolesCfg = function_exists('getCustomRolesConfig') ? getCustomRolesConfig() : [];
                                                $customInternal = [];
                                                $customClient = [];
                                                $customVendor = [];
                                                foreach ($customRolesCfg as $rk => $rv) {
                                                    if (!is_array($rv)) continue;
                                                    $scope = (string)($rv['scope'] ?? 'internal');
                                                    $rk = normalizeRole((string)$rk);
                                                    $lbl = trim((string)($rv['label'] ?? ''));
                                                    if ($rk === '' || $lbl === '') continue;
                                                    if (in_array($rk, ['admin','director','manager_director','operations_director','operations_manager','operations_agent','email_marketing_director','email_marketing_manager','email_marketing_agent','qa_director','qa_manager','qa_agent','sales_director','sales_manager','sdr','client_admin','client_sdr','vendor_admin','vendor_user'], true)) continue;
                                                    if ($scope === 'client') $customClient[$rk] = $lbl;
                                                    elseif ($scope === 'vendor') $customVendor[$rk] = $lbl;
                                                    else $customInternal[$rk] = $lbl;
                                                }
                                                if (!empty($customInternal)) {
                                                    asort($customInternal);
                                                    echo '<optgroup label="Custom (Internal)">';
                                                    foreach ($customInternal as $rk => $lbl) {
                                                        echo '<option value="' . htmlspecialchars($rk) . '" data-scope="internal">' . htmlspecialchars($lbl) . '</option>';
                                                    }
                                                    echo '</optgroup>';
                                                }
                                                if (!empty($customClient)) {
                                                    asort($customClient);
                                                    echo '<optgroup label="Custom (Client)">';
                                                    foreach ($customClient as $rk => $lbl) {
                                                        echo '<option value="' . htmlspecialchars($rk) . '" data-scope="client">' . htmlspecialchars($lbl) . '</option>';
                                                    }
                                                    echo '</optgroup>';
                                                }
                                                if (!empty($customVendor)) {
                                                    asort($customVendor);
                                                    echo '<optgroup label="Custom (Vendor)">';
                                                    foreach ($customVendor as $rk => $lbl) {
                                                        echo '<option value="' . htmlspecialchars($rk) . '" data-scope="vendor">' . htmlspecialchars($lbl) . '</option>';
                                                    }
                                                    echo '</optgroup>';
                                                }
                                            ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4" id="clientGroup" style="display:none;">
                                        <label class="form-label fw-bold small">Client *</label>
                                        <select class="form-select" name="client_id" id="userClientId" <?php echo $isClientAdmin ? 'disabled' : ''; ?>>
                                            <option value="">Select Client</option>
                                            <?php foreach ($clientsList as $c): ?>
                                                <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars(($c['name'] ?? '').' ['.($c['client_code'] ?? '').']'); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if ($isClientAdmin): ?>
                                            <input type="hidden" name="client_id" value="<?php echo (int)$currentClientId; ?>">
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-4" id="vendorGroup" style="display:none;">
                                        <label class="form-label fw-bold small">Vendor *</label>
                                        <select class="form-select" name="vendor_id" id="userVendorId" <?php echo $isVendorAdmin ? 'disabled' : ''; ?>>
                                            <option value="">Select Vendor</option>
                                            <?php foreach ($vendorsList as $v): ?>
                                                <option value="<?php echo (int)$v['id']; ?>"><?php echo htmlspecialchars(($v['name'] ?? '').' ['.($v['vendor_code'] ?? '').']'); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if ($isVendorAdmin): ?>
                                            <input type="hidden" name="vendor_id" value="<?php echo (int)$currentVendorId; ?>">
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="tab-pane fade" id="pane-personal" role="tabpanel" aria-labelledby="tab-personal" tabindex="0">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">Personal Email</label>
                                        <input type="email" class="form-control" name="personal_email" id="userPersonalEmail">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">Emergency Contact Number</label>
                                        <input type="text" class="form-control" name="emergency_contact_number" id="userEmergencyPhone" inputmode="tel">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small">Date of Birth</label>
                                        <input type="date" class="form-control" name="date_of_birth" id="userDob">
                                    </div>
                                </div>
                            </div>

                            <div class="tab-pane fade" id="pane-employment" role="tabpanel" aria-labelledby="tab-employment" tabindex="0">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small">Employee ID</label>
                                        <input type="text" class="form-control" name="employee_id" id="userEmpId" placeholder="Auto-generated if empty">
                                        <div class="small mt-1 d-none" id="userEmpIdFeedback"></div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small">Joining Date</label>
                                        <input type="date" class="form-control" name="date_of_joining" id="userDoj">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">Job Title</label>
                                        <input type="text" class="form-control" name="job_title" id="userJobTitle">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">Department</label>
                                        <input type="text" class="form-control" name="department" id="userDept">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">Reporting Manager</label>
                                        <select class="form-select" name="reporting_manager_id" id="userManagerId">
                                            <option value="">Select</option>
                                            <?php foreach ($managersList as $m): ?>
                                                <option value="<?php echo (int)($m['id'] ?? 0); ?>"><?php echo htmlspecialchars((string)($m['full_name'] ?? '')); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div id="passwordField" class="col-md-6">
                                        <label class="form-label fw-bold small">Initial Password *</label>
                                        <input type="password" class="form-control" name="password" id="userPassword" placeholder="Default: 123456">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-bold small">Notes</label>
                                        <textarea class="form-control" name="onboarding_notes" id="userNotes" rows="3"></textarea>
                                    </div>
                                    <div class="col-12">
                                        <div class="form-check form-switch mt-2">
                                            <input class="form-check-input" type="checkbox" name="is_active" id="userIsActive" value="1" checked>
                                            <label class="form-check-label fw-bold small" for="userIsActive">Account Active</label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="tab-pane fade" id="pane-bank" role="tabpanel" aria-labelledby="tab-bank" tabindex="0">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">Bank Name</label>
                                        <input type="text" class="form-control" name="bank_name" id="userBankName">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">Account Number</label>
                                        <input type="text" class="form-control" name="account_number" id="userAccountNumber" inputmode="numeric">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small">Account Type</label>
                                        <select class="form-select" name="account_type" id="userAccountType">
                                            <option value="">Select</option>
                                            <option value="Saving">Saving</option>
                                            <option value="Salary">Salary</option>
                                            <option value="Current">Current</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small">IFSC Code</label>
                                        <input type="text" class="form-control font-monospace" name="ifsc_code" id="userIfsc" maxlength="11">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small">PAN Number</label>
                                        <input type="text" class="form-control font-monospace" name="pan_number" id="userPan" maxlength="10">
                                    </div>
                                </div>
                            </div>

                            <div class="tab-pane fade" id="pane-docs" role="tabpanel" aria-labelledby="tab-docs" tabindex="0">
                                <div class="row g-3">
                                    <div class="col-12" id="existingDocsContainer" style="display:none;">
                                        <h6 class="fw-bold mb-2 border-bottom pb-2">Uploaded Documents</h6>
                                        <div class="table-responsive mb-3">
                                            <table class="table table-sm table-bordered align-middle">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Category</th>
                                                        <th>Type</th>
                                                        <th>File Name</th>
                                                        <th>Size</th>
                                                        <th class="text-end">Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="existingDocsList"></tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <h6 class="fw-bold mb-2 border-bottom pb-2">Upload New Documents</h6>
                                        <div class="alert alert-light border mb-3">
                                            <div class="small text-muted">Allowed: PDF, JPG, PNG. Multiple files supported. Uploading new files will add to existing ones.</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">Past Company – Offer Letter</label>
                                        <input type="file" class="form-control" name="offer_letter_files[]" id="docOffer" multiple accept=".pdf,.jpg,.jpeg,.png">
                                        <div class="text-muted small mt-1" id="docOfferList"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">Past Company – Relieving Letter</label>
                                        <input type="file" class="form-control" name="relieving_letter_files[]" id="docRelieving" multiple accept=".pdf,.jpg,.jpeg,.png">
                                        <div class="text-muted small mt-1" id="docRelievingList"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">Past Company – Experience Letter</label>
                                        <input type="file" class="form-control" name="experience_letter_files[]" id="docExperience" multiple accept=".pdf,.jpg,.jpeg,.png">
                                        <div class="text-muted small mt-1" id="docExperienceList"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">Salary Proof – Salary Slips</label>
                                        <input type="file" class="form-control" name="salary_slip_files[]" id="docSalarySlips" multiple accept=".pdf,.jpg,.jpeg,.png">
                                        <div class="text-muted small mt-1" id="docSalarySlipsList"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">Salary Proof – Bank Statements</label>
                                        <input type="file" class="form-control" name="bank_statement_files[]" id="docBankStatements" multiple accept=".pdf,.jpg,.jpeg,.png">
                                        <div class="text-muted small mt-1" id="docBankStatementsList"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">Education – Certificates / Degrees</label>
                                        <input type="file" class="form-control" name="education_files[]" id="docEducation" multiple accept=".pdf,.jpg,.jpeg,.png">
                                        <div class="text-muted small mt-1" id="docEducationList"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">Identity Proof – Aadhaar Card</label>
                                        <input type="file" class="form-control" name="aadhaar_files[]" id="docAadhaar" multiple accept=".pdf,.jpg,.jpeg,.png">
                                        <div class="text-muted small mt-1" id="docAadhaarList"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">Identity Proof – PAN Card</label>
                                        <input type="file" class="form-control" name="pan_card_files[]" id="docPanCard" multiple accept=".pdf,.jpg,.jpeg,.png">
                                        <div class="text-muted small mt-1" id="docPanCardList"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary px-4">Save User Account</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <form method="post">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="user_id" id="deleteUserId">
                    <div class="modal-body text-center p-4">
                        <i class="bi bi-exclamation-octagon text-danger display-4 mb-3 d-block"></i>
                        <h4 class="fw-bold">Delete User?</h4>
                        <p class="text-muted">Are you sure you want to delete <span id="deleteUserName" class="fw-bold text-dark"></span>? This action cannot be undone.</p>
                        <div class="d-flex gap-2 justify-content-center mt-4">
                            <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger px-4">Delete Permanently</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

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

    <script>
        const existingUsers = <?php echo json_encode(array_map(static function(array $u): array {
            return [
                'id' => (int)($u['id'] ?? 0),
                'employee_id' => trim((string)($u['employee_id'] ?? '')),
            ];
        }, $users), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

        function normalizeEmployeeId(value) {
            return String(value || '').trim().toLowerCase();
        }

        function validateEmployeeIdField() {
            const input = document.getElementById('userEmpId');
            const feedback = document.getElementById('userEmpIdFeedback');
            const userId = parseInt(document.getElementById('userId')?.value || '0', 10);
            if (!input || !feedback) return true;
            const raw = String(input.value || '').trim();
            if (raw === '') {
                feedback.className = 'small mt-1 d-none';
                feedback.textContent = '';
                input.classList.remove('is-invalid');
                return true;
            }
            const key = normalizeEmployeeId(raw);
            const duplicate = existingUsers.some((u) => normalizeEmployeeId(u.employee_id) === key && parseInt(u.id || 0, 10) !== userId);
            if (duplicate) {
                input.classList.add('is-invalid');
                feedback.className = 'small mt-1 text-danger';
                feedback.textContent = 'Employee ID already exists for another user.';
                return false;
            }
            input.classList.remove('is-invalid');
            feedback.className = 'small mt-1 text-success';
            feedback.textContent = 'Employee ID is available.';
            return true;
        }

        function setOnboardingProgress() {
            const tabs = Array.from(document.querySelectorAll('#onboardTabs .nav-link'));
            const activeIdx = Math.max(0, tabs.findIndex(t => t.classList.contains('active')));
            const pct = Math.round(((activeIdx + 1) / Math.max(1, tabs.length)) * 100);
            const bar = document.getElementById('onboardProgress');
            if (bar) bar.style.width = pct + '%';
        }

        function goToOnboardTab(tabId) {
            const btn = document.getElementById(tabId);
            if (!btn || btn.disabled) return;
            const t = new bootstrap.Tab(btn);
            t.show();
            setOnboardingProgress();
        }

        function showFileList(inputId, listId) {
            const input = document.getElementById(inputId);
            const box = document.getElementById(listId);
            if (!input || !box) return;
            const files = Array.from(input.files || []);
            if (files.length === 0) { box.textContent = ''; return; }
            box.textContent = files.map(f => f.name).join(', ');
        }

        function resetUserForm() {
            document.getElementById('userForm').reset();
            document.getElementById('userId').value = '';
            document.getElementById('modalTitle').textContent = 'Add New User';
            document.getElementById('passwordField').style.display = 'block';
            document.getElementById('userIsActive').checked = true;
            document.getElementById('userAvatarBox').innerHTML = '<div class="user-avatar-lg">U</div>';
            const pe = document.getElementById('userPersonalEmail'); if (pe) pe.value = '';
            const ep = document.getElementById('userEmergencyPhone'); if (ep) ep.value = '';
            const dob = document.getElementById('userDob'); if (dob) dob.value = '';
            const bn = document.getElementById('userBankName'); if (bn) bn.value = '';
            const an = document.getElementById('userAccountNumber'); if (an) an.value = '';
            const at = document.getElementById('userAccountType'); if (at) at.value = '';
            const ifsc = document.getElementById('userIfsc'); if (ifsc) ifsc.value = '';
            const pan = document.getElementById('userPan'); if (pan) pan.value = '';
            const mid = document.getElementById('userManagerId'); if (mid) mid.value = '';
            const notes = document.getElementById('userNotes'); if (notes) notes.value = '';

            ['docOffer','docRelieving','docExperience','docSalarySlips','docBankStatements','docEducation','docAadhaar','docPanCard'].forEach(id => {
                const i = document.getElementById(id);
                if (i) i.value = '';
            });
            ['docOfferList','docRelievingList','docExperienceList','docSalarySlipsList','docBankStatementsList','docEducationList','docAadhaarList','docPanCardList'].forEach(id => {
                const b = document.getElementById(id);
                if (b) b.textContent = '';
            });

            const t = document.getElementById('userType');
            if (t) {
                <?php if ($isClientAdmin): ?>
                t.value = 'client';
                <?php elseif ($isVendorAdmin): ?>
                t.value = 'vendor';
                <?php else: ?>
                t.value = 'internal';
                <?php endif; ?>
            }
            applyUserTypeRules();
            const roleSel = document.getElementById('userRole');
            if (roleSel) {
                const type = t ? t.value : 'internal';
                if (type === 'client') roleSel.value = 'client_sdr';
                else if (type === 'vendor') roleSel.value = 'vendor_user';
                else roleSel.value = 'operations_agent';
            }
            validateEmployeeIdField();
            goToOnboardTab('tab-basic');
        }

        let cropInputEl = null;
        let cropPreviewTarget = null;
        let cropImg = null;
        let cropUrl = '';
        let cropBaseScale = 1;
        let cropScale = 1;
        let cropX = 0;
        let cropY = 0;
        let cropDrag = null;

        function setAvatarPreviewUrl(url) {
            if (!cropPreviewTarget) return;
            cropPreviewTarget.innerHTML = '<img src="' + url + '" class="user-avatar-lg" style="object-fit:cover;">';
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

        async function openCropperForInput(input, previewTarget) {
            if (!input?.files?.[0]) return;
            cropInputEl = input;
            cropPreviewTarget = previewTarget;
            const file = input.files[0];
            if (!String(file.type || '').startsWith('image/')) {
                const r = new FileReader();
                r.onload = (e) => setAvatarPreviewUrl(String(e.target?.result || ''));
                r.readAsDataURL(file);
                return;
            }
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

        function previewUserPic(input) {
            const box = document.getElementById('userAvatarBox');
            openCropperForInput(input, box);
        }

        document.addEventListener('DOMContentLoaded', () => {
            const userForm = document.getElementById('userForm');
            const userEmpId = document.getElementById('userEmpId');
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
                    r.onload = (e) => setAvatarPreviewUrl(String(e.target?.result || ''));
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
                    setAvatarPreviewUrl(URL.createObjectURL(newFile));
                    bootstrap.Modal.getInstance(modalEl)?.hide();
                });
            }

            if (userEmpId) {
                userEmpId.addEventListener('input', validateEmployeeIdField);
                userEmpId.addEventListener('blur', validateEmployeeIdField);
            }
            if (userForm) {
                userForm.addEventListener('submit', (event) => {
                    if (!validateEmployeeIdField()) {
                        event.preventDefault();
                        event.stopPropagation();
                    }
                });
            }
        });

        function editUser(u) {
            resetUserForm();
            document.getElementById('userId').value = u.id;
            document.getElementById('modalTitle').textContent = 'Edit User Profile';
            document.getElementById('passwordField').style.display = 'none';
            
            document.getElementById('fullName').value = u.full_name || '';
            document.getElementById('userName').value = u.username || '';
            document.getElementById('userEmail').value = u.email || '';
            document.getElementById('userPhone').value = u.phone_number || '';
            document.getElementById('userRole').value = u.role || 'operations_agent';
            document.getElementById('userEmpId').value = u.employee_id || '';
            document.getElementById('userDoj').value = u.date_of_joining || '';
            document.getElementById('userJobTitle').value = u.job_title || '';
            document.getElementById('userDept').value = u.department || '';
            document.getElementById('userIsActive').checked = (parseInt(u.is_active) === 1);
            const pe = document.getElementById('userPersonalEmail'); if (pe) pe.value = u.personal_email || '';
            const ep = document.getElementById('userEmergencyPhone'); if (ep) ep.value = u.emergency_contact_number || '';
            const dob = document.getElementById('userDob'); if (dob) dob.value = u.date_of_birth || '';
            const bn = document.getElementById('userBankName'); if (bn) bn.value = u.bank_name || '';
            const an = document.getElementById('userAccountNumber'); if (an) an.value = u.account_number || '';
            const at = document.getElementById('userAccountType'); if (at) at.value = u.account_type || '';
            const ifsc = document.getElementById('userIfsc'); if (ifsc) ifsc.value = u.ifsc_code || '';
            const pan = document.getElementById('userPan'); if (pan) pan.value = u.pan_number || '';
            const mid = document.getElementById('userManagerId'); if (mid) mid.value = u.reporting_manager_id || '';
            const notes = document.getElementById('userNotes'); if (notes) notes.value = u.onboarding_notes || '';

            const t = document.getElementById('userType');
            if (t) {
                if (parseInt(u.client_id || 0) > 0) t.value = 'client';
                else if (parseInt(u.vendor_id || 0) > 0) t.value = 'vendor';
                else t.value = 'internal';
            }
            const csel = document.getElementById('userClientId');
            if (csel) csel.value = (u.client_id && parseInt(u.client_id) > 0) ? String(u.client_id) : '';
            const vsel = document.getElementById('userVendorId');
            if (vsel) vsel.value = (u.vendor_id && parseInt(u.vendor_id) > 0) ? String(u.vendor_id) : '';
            applyUserTypeRules();
            if (u.profile_pic) {
                document.getElementById('userAvatarBox').innerHTML = '<img src="../../' + u.profile_pic + '" class="user-avatar-lg" style="object-fit:cover;">';
            } else {
                const initial = (u.full_name || 'U').substring(0,1).toUpperCase();
                document.getElementById('userAvatarBox').innerHTML = '<div class="user-avatar-lg">' + initial + '</div>';
            }

            const docsContainer = document.getElementById('existingDocsContainer');
            const docsList = document.getElementById('existingDocsList');
            if (docsContainer && docsList) {
                if (u.documents && u.documents.length > 0) {
                    docsContainer.style.display = '';
                    let html = '';
                    u.documents.forEach(d => {
                        const kb = (d.file_size / 1024).toFixed(1) + ' KB';
                        html += `<tr>
                            <td>${d.category}</td>
                            <td>${d.doc_type}</td>
                            <td>${d.original_name}</td>
                            <td>${kb}</td>
                            <td class="text-end">
                                <a href="../../${d.file_path}" target="_blank" class="btn btn-sm btn-outline-primary" title="View/Download"><i class="bi bi-download"></i></a>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteDocument(${d.id})" title="Delete"><i class="bi bi-trash"></i></button>
                            </td>
                        </tr>`;
                    });
                    docsList.innerHTML = html;
                } else {
                    docsContainer.style.display = 'none';
                    docsList.innerHTML = '';
                }
            }
            
            validateEmployeeIdField();
            new bootstrap.Modal(document.getElementById('userModal')).show();
            goToOnboardTab('tab-basic');
        }

        function deleteDocument(docId) {
            if (confirm('Are you sure you want to delete this document?')) {
                const form = document.createElement('form');
                form.method = 'post';
                form.style.display = 'none';
                
                const act = document.createElement('input');
                act.name = 'action';
                act.value = 'delete_doc';
                form.appendChild(act);
                
                const tok = document.createElement('input');
                tok.name = 'csrf_token';
                tok.value = '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>';
                form.appendChild(tok);
                
                const did = document.createElement('input');
                did.name = 'doc_id';
                did.value = docId;
                form.appendChild(did);
                
                document.body.appendChild(form);
                form.submit();
            }
        }

        function confirmDelete(id, name) {
            document.getElementById('deleteUserId').value = id;
            document.getElementById('deleteUserName').textContent = name;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }

        function applyUserTypeRules() {
            const t = document.getElementById('userType');
            const type = t ? t.value : 'internal';
            const cg = document.getElementById('clientGroup');
            const vg = document.getElementById('vendorGroup');
            if (cg) cg.style.display = (type === 'client') ? '' : 'none';
            if (vg) vg.style.display = (type === 'vendor') ? '' : 'none';

            const roleSel = document.getElementById('userRole');
            if (roleSel) {
                let anySelected = false;
                Array.from(roleSel.options).forEach(o => {
                    const scope = o.getAttribute('data-scope') || 'internal';
                    const ok = scope === type;
                    o.hidden = !ok;
                    o.disabled = !ok;
                    if (o.selected && ok) anySelected = true;
                });
                if (!anySelected) {
                    const firstOk = Array.from(roleSel.options).find(o => !o.disabled);
                    if (firstOk) roleSel.value = firstOk.value;
                }
            }

            const extraTabs = ['tab-personal','tab-employment','tab-bank','tab-docs'];
            extraTabs.forEach(id => {
                const btn = document.getElementById(id);
                if (!btn) return;
                const enabled = (type === 'internal');
                btn.disabled = !enabled;
                btn.classList.toggle('disabled', !enabled);
            });
            if (type !== 'internal') goToOnboardTab('tab-basic');

            <?php if ($isClientAdmin): ?>
            if (t) t.value = 'client';
            <?php elseif ($isVendorAdmin): ?>
            if (t) t.value = 'vendor';
            <?php endif; ?>
        }

        document.addEventListener('DOMContentLoaded', () => {
            const qs = new URLSearchParams(window.location.search);
            const applyFilters = (resetPage) => {
                const s = (document.getElementById('userFilterSearch')?.value || '').trim();
                const r = (document.getElementById('userFilterRole')?.value || '').trim();
                const st = (document.getElementById('userFilterStatus')?.value || '').trim();
                const d = (document.getElementById('userFilterDept')?.value || '').trim();
                const hp = document.getElementById('userFilterHasPic')?.checked ? '1' : '';

                if (s !== '') qs.set('search', s); else qs.delete('search');
                if (r !== '') qs.set('role', r); else qs.delete('role');
                if (st !== '') qs.set('status', st); else qs.delete('status');
                if (d !== '') qs.set('dept', d); else qs.delete('dept');
                if (hp !== '') qs.set('has_pic', hp); else qs.delete('has_pic');
                if (resetPage) qs.delete('page');
                window.location.search = qs.toString();
            };

            const setInitial = () => {
                const s = document.getElementById('userFilterSearch');
                const r = document.getElementById('userFilterRole');
                const st = document.getElementById('userFilterStatus');
                const d = document.getElementById('userFilterDept');
                const hp = document.getElementById('userFilterHasPic');
                if (s) s.value = <?php echo json_encode($search); ?>;
                if (r) r.value = <?php echo json_encode($filterRole); ?>;
                if (st) st.value = <?php echo json_encode($filterStatus); ?>;
                if (d) d.value = <?php echo json_encode($filterDept); ?>;
                if (hp) hp.checked = <?php echo $filterHasPic === '1' ? 'true' : 'false'; ?>;
            };

            const t = document.getElementById('userType');
            if (t) t.addEventListener('change', applyUserTypeRules);
            applyUserTypeRules();
            setOnboardingProgress();
            document.querySelectorAll('#onboardTabs [data-bs-toggle="pill"]').forEach(btn => {
                btn.addEventListener('shown.bs.tab', setOnboardingProgress);
            });
            [
                ['docOffer','docOfferList'],
                ['docRelieving','docRelievingList'],
                ['docExperience','docExperienceList'],
                ['docSalarySlips','docSalarySlipsList'],
                ['docBankStatements','docBankStatementsList'],
                ['docEducation','docEducationList'],
                ['docAadhaar','docAadhaarList'],
                ['docPanCard','docPanCardList'],
            ].forEach(([i,l]) => {
                const el = document.getElementById(i);
                if (el) el.addEventListener('change', () => showFileList(i, l));
            });

            setInitial();
            const deb = (fn, ms) => {
                let to = null;
                return (...args) => {
                    if (to) clearTimeout(to);
                    to = setTimeout(() => fn(...args), ms);
                };
            };
            const s = document.getElementById('userFilterSearch');
            if (s) {
                s.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        applyFilters(true);
                    }
                });
                s.addEventListener('input', deb(() => applyFilters(true), 500));
            }
            const r = document.getElementById('userFilterRole');
            if (r) r.addEventListener('change', () => applyFilters(true));
            const st = document.getElementById('userFilterStatus');
            if (st) st.addEventListener('change', () => applyFilters(true));
            const d = document.getElementById('userFilterDept');
            if (d) d.addEventListener('change', () => applyFilters(true));
            const hp = document.getElementById('userFilterHasPic');
            if (hp) hp.addEventListener('change', () => applyFilters(true));
            const clr = document.getElementById('userFilterClear');
            if (clr) clr.addEventListener('click', () => {
                ['search','role','status','dept','has_pic','page'].forEach(k => qs.delete(k));
                window.location.search = qs.toString();
            });
        });
    </script>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
