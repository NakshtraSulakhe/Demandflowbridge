<?php
/**
 * User Registration Page
 * 
 * Allows new users to create an account as an Agent or Form Filler
 */

// Include authentication system
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

http_response_code(404);
exit;

// Check if user is already logged in
if (isLoggedIn()) {
    $user = getCurrentUser();
    header("Location: ../../");
    exit;
}

$error = '';
$success = '';

// Process registration form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? 'agent'; 
    if ($role === 'form_filler') $role = 'email_marketing_executive';
    $empId = trim($_POST['employee_id'] ?? '');
    $phone = trim($_POST['phone_number'] ?? '');

    // Validation
    if (empty($username) || empty($password) || empty($confirmPassword) || empty($fullName) || empty($email)) {
        $error = 'All fields are required';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address';
    } elseif (!in_array($role, ['agent', 'email_marketing_executive', 'form_filler'], true)) {
        $error = 'Invalid role selected';
    } else {
        $conn = getDbConnection();
        
        // Check if username already exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $error = 'Username already exists. Please choose another.';
        } else {
            // Check if email already exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $error = 'Email already registered. Please login instead.';
            } else {
                // Create user
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $isActive = 1; 
                $doj = date('Y-m-d'); // Auto-set DOJ on registration
                
                $insert = $conn->prepare("INSERT INTO users (username, password, full_name, email, role, is_active, employee_id, phone_number, date_of_joining) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $insert->bind_param("sssssisss", $username, $hashedPassword, $fullName, $email, $role, $isActive, $empId, $phone, $doj);
                
                if ($insert->execute()) {
                    $success = 'Registration successful! You can now sign in.';
                } else {
                    $error = 'A system error occurred. Please try again later.';
                }
                $insert->close();
            }
        }
        $stmt->close();
    }
}
?>
<?php $pageTitle = 'Create Account'; include __DIR__ . '/../../includes/layout/auth_start.php'; ?>

<div class="row g-0 align-items-stretch">
  <div class="col-md-5 d-flex flex-column justify-content-center align-items-center bg-light-subtle" style="padding: 48px 24px;">
    <img src="../../assets/images/logos/DemandFlow-Media-logo.png" alt="DemandFlow Bridge" class="img-fluid mb-4" style="max-height: 100px; object-fit: contain;">
    <div class="text-center px-3">
      <h5 class="fw-bold mb-2">Join DemandFlow Bridge</h5>
      <p class="text-muted small">Create your account to start managing campaigns and leads efficiently.</p>
    </div>
  </div>
  <div class="col-md-7 border-start-md" style="padding: 32px;">
    <div class="mb-4">
      <h4 class="fw-bold mb-1">Create Account</h4>
      <p class="text-muted small">Please fill in your details to register</p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2 mb-3">
            <i class="bi bi-exclamation-triangle"></i>
            <div><?php echo htmlspecialchars($error); ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success d-flex align-items-center gap-2 mb-3">
            <i class="bi bi-check-circle"></i>
            <div><?php echo htmlspecialchars($success); ?></div>
        </div>
        <a class="btn btn-primary w-100" href="login.php">Go to Login</a>
    <?php else: ?>
        <form method="post" action="register.php">
        <div class="mb-3">
            <label class="form-label small text-muted">Role</label>
            <div class="d-flex gap-2">
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="role" id="role_agent" value="agent" <?php echo (($_POST['role'] ?? 'agent') === 'agent') ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="role_agent">Agent</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="role" id="role_ff" value="email_marketing_executive" <?php echo (($_POST['role'] ?? 'agent') === 'email_marketing_executive' || ($_POST['role'] ?? '') === 'form_filler') ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="role_ff">Email Marketing Executive</label>
                </div>
            </div>
        </div>

        <div class="row g-2">
            <div class="col-md-6">
                <label class="form-label small text-muted">Full Name</label>
                <input class="form-control form-control-sm" name="full_name" required value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label small text-muted">Email</label>
                <input class="form-control form-control-sm" type="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label small text-muted">Employee ID</label>
                <input class="form-control form-control-sm" name="employee_id" value="<?php echo htmlspecialchars($_POST['employee_id'] ?? ''); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label small text-muted">Phone Number</label>
                <input class="form-control form-control-sm" name="phone_number" value="<?php echo htmlspecialchars($_POST['phone_number'] ?? ''); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label small text-muted">Username</label>
                <input class="form-control form-control-sm" name="username" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label small text-muted">Password</label>
                <input class="form-control form-control-sm" type="password" name="password" required>
            </div>
            <div class="col-md-6">
                <label class="form-label small text-muted">Confirm Password</label>
                <input class="form-control form-control-sm" type="password" name="confirm_password" required>
            </div>
        </div>

        <button type="submit" class="btn btn-primary w-100 mt-3">Create Account</button>
        <div class="text-center mt-3 small text-muted">
            Already have an account? <a href="login.php" class="text-decoration-none fw-semibold">Sign in</a>
        </div>
    </form>
<?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../../includes/layout/auth_end.php'; ?>
