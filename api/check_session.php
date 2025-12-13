<?php
/**
 * API للتحقق من وجود الجلسة في قاعدة البيانات
 */

define('ACCESS_ALLOWED', true);
define('NOTIFICATIONS_API_ACTIVE', true);

error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/auth.php';
} catch (Exception $e) {
    error_log("Check Session API initialization error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Initialization error']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// التحقق من تسجيل الدخول
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

$userId = $_SESSION['user_id'] ?? null;
$sessionId = session_id();

if (!$userId || !$sessionId) {
    echo json_encode(['success' => false, 'session_exists' => false]);
    exit;
}

// التحقق من وجود الجلسة في قاعدة البيانات
try {
    if (ensureSessionsTable()) {
        $db = db();
        $sessionRecord = $db->queryOne(
            "SELECT * FROM sessions WHERE user_id = ? AND session_id = ? AND expires_at > NOW()",
            [$userId, $sessionId]
        );
        
        if ($sessionRecord) {
            echo json_encode([
                'success' => true,
                'session_exists' => true,
                'user_id' => $userId
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'session_exists' => false,
                'user_id' => $userId
            ]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Sessions table not available']);
    }
} catch (Exception $e) {
    error_log("Check Session API error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
