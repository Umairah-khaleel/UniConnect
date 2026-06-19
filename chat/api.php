<?php
// chat/api.php
// Handles: action=fetch, action=send, action=cleanup

session_start();
header('Content-Type: application/json');

// Must be logged in as student
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

require_once '../auth/db.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── Auto-delete expired messages on every request ──
$pdo->exec("DELETE FROM chat_messages WHERE expires_at < NOW()");

// ════════════════════════════════
//  FETCH messages (GET)
//  ?action=fetch&since=MESSAGE_ID
// ════════════════════════════════
if ($action === 'fetch') {
    $since = intval($_GET['since'] ?? 0);
    $stmt  = $pdo->prepare("
        SELECT id, user_id, student_id, full_name, message, created_at
        FROM chat_messages
        WHERE id > ? AND expires_at > NOW()
        ORDER BY created_at ASC
        LIMIT 100
    ");
    $stmt->execute([$since]);
    $rows = $stmt->fetchAll();

    // Format times
    foreach ($rows as &$r) {
        $r['time_label'] = date('h:i A', strtotime($r['created_at']));
        $r['is_me']      = ($r['user_id'] == $_SESSION['user_id']);
        // scrub any HTML
        $r['message']    = htmlspecialchars($r['message'], ENT_QUOTES, 'UTF-8');
        $r['full_name']  = htmlspecialchars($r['full_name'], ENT_QUOTES, 'UTF-8');
    }
    unset($r);

    echo json_encode(['success' => true, 'messages' => $rows]);
    exit;
}

// ════════════════════════════════
//  SEND a message (POST)
// ════════════════════════════════
if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = trim($_POST['message'] ?? '');

    if (empty($raw)) {
        echo json_encode(['success' => false, 'message' => 'Message cannot be empty.']);
        exit;
    }

    // Length limit
    if (strlen($raw) > 1000) {
        echo json_encode(['success' => false, 'message' => 'Message too long (max 1000 characters).']);
        exit;
    }

    // Block URLs / file links (education-only)
    if (preg_match('/(https?:\/\/|www\.|\.jpg|\.jpeg|\.png|\.gif|\.mp4|\.webp|data:image)/i', $raw)) {
        echo json_encode(['success' => false, 'message' => 'Links and media are not allowed in this chatroom.']);
        exit;
    }

    // Rate limiting — max 5 messages per 10 seconds per user
    $rateStmt = $pdo->prepare("
        SELECT COUNT(*) FROM chat_messages
        WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 10 SECOND)
    ");
    $rateStmt->execute([$_SESSION['user_id']]);
    if ($rateStmt->fetchColumn() >= 5) {
        echo json_encode(['success' => false, 'message' => 'Slow down — you are sending messages too fast.']);
        exit;
    }

    // Insert
    $stmt = $pdo->prepare("
        INSERT INTO chat_messages (user_id, student_id, full_name, message, created_at, expires_at)
        VALUES (?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR))
    ");
    $stmt->execute([
        $_SESSION['user_id'],
        $_SESSION['student_id'],
        $_SESSION['full_name'],
        $raw
    ]);

    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
    exit;
}

// ════════════════════════════════
//  COUNT online (rough estimate)
// ════════════════════════════════
if ($action === 'count') {
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT user_id) as cnt
        FROM chat_messages
        WHERE created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    ");
    echo json_encode(['success' => true, 'count' => $stmt->fetchColumn()]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
exit;
?>