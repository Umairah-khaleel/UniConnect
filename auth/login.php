<?php
// auth/login.php
// Logs in by student_id + password, redirects to student or admin dashboard

session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

require_once 'db.php';

$student_id = trim($_POST['student_id'] ?? '');
$password   = trim($_POST['password']   ?? '');

if (empty($student_id) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Please fill in all fields.']);
    exit;
}

// Fetch user by student_id
$stmt = $pdo->prepare("SELECT id, student_id, full_name, email, password, role, is_active FROM users WHERE student_id = ? LIMIT 1");
$stmt->execute([$student_id]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'No account found with that Student ID.']);
    exit;
}

if (!$user['is_active']) {
    echo json_encode(['success' => false, 'message' => 'Your account has been deactivated. Contact support.']);
    exit;
}

if (!password_verify($password, $user['password'])) {
    echo json_encode(['success' => false, 'message' => 'Incorrect password. Please try again.']);
    exit;
}

// Set session
$_SESSION['user_id']     = $user['id'];
$_SESSION['student_id']  = $user['student_id'];
$_SESSION['full_name']   = $user['full_name'];
$_SESSION['email']       = $user['email'];
$_SESSION['role']        = $user['role'];

// Remember me — extend session to 30 days
if (!empty($_POST['remember'])) {
    session_set_cookie_params(86400 * 30);
    session_regenerate_id(true);
}

// Update last login
$pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);

// Role-based redirect
$redirect = ($user['role'] === 'admin') ? '../admin_dashboard.php' : '../student_dashboard.php';

echo json_encode([
    'success'  => true,
    'message'  => 'Login successful.',
    'role'     => $user['role'],
    'name'     => $user['full_name'],
    'redirect' => $redirect
]);
exit;
?>