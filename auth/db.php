<?php
// auth/db.php — UniConnect Database Connection

define('DB_HOST', 'localhost');
define('DB_NAME', 'uniconnect');
define('DB_USER', 'root');    // ← change to your MySQL username
define('DB_PASS', '');        // ← change to your MySQL password

try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER, DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}
?>
