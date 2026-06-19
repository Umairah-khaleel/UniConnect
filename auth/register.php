<?php
// auth/register.php
// Registers a new student — role defaults to 'student'
// Table: users (id, student_id, full_name, email, password, role, is_active, created_at, last_login)

session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

require_once 'db.php';

$student_id = trim($_POST['student_id']       ?? '');
$full_name  = trim($_POST['full_name']         ?? '');
$email      = trim($_POST['email']             ?? '');
$password   = $_POST['password']               ?? '';
$confirm    = $_POST['confirm_password']        ?? '';

// ── Validation ──
if (strlen($student_id) < 2) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid Student ID.']);
    exit;
}
if (strlen($full_name) < 2) {
    echo json_encode(['success' => false, 'message' => 'Please enter your full name.']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}
if (strlen($password) < 8) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
    exit;
}
if ($password !== $confirm) {
    echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
    exit;
}

// Check duplicate student_id
$stmt = $pdo->prepare("SELECT id FROM users WHERE student_id = ? LIMIT 1");
$stmt->execute([$student_id]);
if ($stmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'This Student ID is already registered.']);
    exit;
}

// Check duplicate email
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'An account with this email already exists.']);
    exit;
}

// Hash & insert — role defaults to 'student'
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
$pdo->prepare("
    INSERT INTO users (student_id, full_name, email, password, role, is_active, created_at)
    VALUES (?, ?, ?, ?, 'student', 1, NOW())
")->execute([$student_id, $full_name, $email, $hash]);

echo json_encode([
    'success' => true,
    'message' => 'Account created successfully! You can now sign in.'
]);
exit;
?> 