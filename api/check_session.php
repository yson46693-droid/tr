<?php
/**
 * API للتحقق من وجود remember_token (تم إزالة نظام الجلسات بالكامل)
 * النظام يعتمد فقط على remember_token
 */

define('ACCESS_ALLOWED', true);
define('NOTIFICATIONS_API_ACTIVE', true);

error_reporting(0);
ini_set('display_errors', 0);

// إعدادات timeout محسّنة (يجب أن تكون سريعة)
@set_time_limit(10); // 10 ثواني فقط
@ini_set('max_execution_time', 10);
@ini_set('max_input_time', 5);

header('Content-Type: application/json; charset=utf-8');
// إضافة headers لمنع caching
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

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

// التحقق من تسجيل الدخول - يعتمد فقط على remember_token
// تم إزالة نظام الجلسات بالكامل
$user = null;
$userId = null;

// محاولة الحصول على المستخدم من remember_token
try {
    if (isLoggedIn()) {
        $user = getCurrentUser();
        if ($user && isset($user['id'])) {
            $userId = $user['id'];
        }
    }
} catch (Exception $e) {
    error_log("Check Session API - Error checking login: " . $e->getMessage());
}

if (!$userId) {
    echo json_encode(['success' => false, 'session_exists' => false]);
    exit;
}

// التحقق من وجود remember_token في قاعدة البيانات
try {
    if (isset($_COOKIE['remember_token']) && ensureRememberTokensTable()) {
        $db = db();
        $decoded = base64_decode($_COOKIE['remember_token']);
        if ($decoded) {
            $parts = explode(':', $decoded);
            if (count($parts) === 2) {
                $tokenUserId = intval($parts[0]);
                $token = $parts[1];
                
                if ($tokenUserId === $userId) {
                    $tokenRecord = $db->queryOne(
                        "SELECT * FROM remember_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()",
                        [$tokenUserId, $token]
                    );
                    
                    if ($tokenRecord) {
                        // تحديث last_used
                        $db->execute(
                            "UPDATE remember_tokens SET last_used = NOW() WHERE id = ?",
                            [$tokenRecord['id']]
                        );
                        
                        echo json_encode([
                            'success' => true,
                            'session_exists' => true,
                            'user_id' => $userId,
                            'method' => 'remember_token'
                        ]);
                        exit;
                    }
                }
            }
        }
    }
} catch (Exception $e) {
    error_log("Check Session API - Remember token check error: " . $e->getMessage());
}

// إذا لم نجد remember_token، نرجع false
echo json_encode([
    'success' => true,
    'session_exists' => false,
    'user_id' => $userId
]);
