<?php
/**
 * API للتحقق من وجود الجلسة في قاعدة البيانات
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

// التحقق من تسجيل الدخول - يعتمد على remember_token بدلاً من $_SESSION
// النظام يعتمد على remember_token فقط بدون جلسات PHP
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

// إذا لم نجد المستخدم من remember_token، جرب $_SESSION كبديل (للتوافق مع الأنظمة القديمة)
if (!$userId) {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $userId = $_SESSION['user_id'] ?? null;
}

if (!$userId) {
    echo json_encode(['success' => false, 'session_exists' => false]);
    exit;
}

// التحقق من وجود remember_token في قاعدة البيانات (الطريقة الأساسية)
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

// التحقق من وجود الجلسة في قاعدة البيانات (للتوافق مع الأنظمة القديمة)
try {
    if (ensureSessionsTable()) {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $sessionId = session_id();
        
        if ($sessionId) {
            $db = db();
            // البحث عن الجلسة بدون شرط expires_at - لا نهي الجلسة أبداً بناءً على الخمول
            $sessionRecord = $db->queryOne(
                "SELECT * FROM sessions WHERE user_id = ? AND session_id = ?",
                [$userId, $sessionId]
            );
            
            if ($sessionRecord) {
                // إذا وُجدت الجلسة لكنها منتهية الصلاحية، نمددها دائماً (لا نهي الجلسة أبداً)
                if (strtotime($sessionRecord['expires_at']) < time()) {
                    $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (3600 * 24 * 7);
                    $newExpiresAt = date('Y-m-d H:i:s', time() + $sessionLifetime);
                    $db->execute(
                        "UPDATE sessions SET expires_at = ?, last_activity = NOW() WHERE id = ?",
                        [$newExpiresAt, $sessionRecord['id']]
                    );
                } else {
                    // تحديث expires_at دائماً لضمان بقاء الجلسة صالحة - لا نهي الجلسة أبداً بناءً على الخمول
                    $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (3600 * 24 * 7);
                    $newExpiresAt = date('Y-m-d H:i:s', time() + $sessionLifetime);
                    $db->execute(
                        "UPDATE sessions SET expires_at = ?, last_activity = NOW() WHERE id = ? LIMIT 1",
                        [$newExpiresAt, $sessionRecord['id']]
                    );
                }
                
                echo json_encode([
                    'success' => true,
                    'session_exists' => true,
                    'user_id' => $userId,
                    'method' => 'session'
                ]);
                exit;
            }
        }
    }
} catch (Exception $e) {
    error_log("Check Session API - Session check error: " . $e->getMessage());
}

// إذا لم نجد جلسة أو remember_token، نرجع false
echo json_encode([
    'success' => true,
    'session_exists' => false,
    'user_id' => $userId
]);
