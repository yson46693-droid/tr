<?php
/**
 * API لتجديد الجلسة (Keep-Alive)
 * يمنع انتهاء الجلسة بشكل تلقائي
 */

define('ACCESS_ALLOWED', true);

// إعدادات timeout محسّنة لـ keep-alive (يجب أن تكون سريعة)
@set_time_limit(10); // 10 ثواني فقط لـ keep-alive
@ini_set('max_execution_time', 10);
@ini_set('max_input_time', 5);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
// إضافة headers لمنع caching
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// السماح بجميع الطرق
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // التحقق من تسجيل الدخول
    if (!isLoggedIn()) {
        echo json_encode([
            'success' => false,
            'expired' => true,
            'message' => 'انتهت الجلسة'
        ]);
        exit;
    }
    
    // تحديث وقت آخر نشاط والجلسة في قاعدة البيانات
    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
        $_SESSION['last_activity'] = time();
        
        // تحديث last_activity_previous أيضاً لمنع انتهاء الجلسة
        if (!isset($_SESSION['last_activity_previous'])) {
            $_SESSION['last_activity_previous'] = time();
        } else {
            // تحديث previous activity time أيضاً لضمان عدم انتهاء الجلسة
            $_SESSION['last_activity_previous'] = time();
        }
        
        // تحديث الجلسة في قاعدة البيانات (last_activity و expires_at)
        // استخدام timeout قصير لمنع تعليق الطلب
        $userId = $_SESSION['user_id'] ?? 0;
        $sessionId = session_id();
        if ($userId > 0 && !empty($sessionId)) {
            try {
                // استخدام timeout قصير للاتصال بقاعدة البيانات
                $db = db();
                $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (3600 * 24 * 7);
                $newExpiresAt = date('Y-m-d H:i:s', time() + $sessionLifetime);
                
                // تحديث سريع - استخدام UPDATE مباشر بدون معالجة معقدة
                // إضافة timeout للاستعلام لمنع التعليق
                $db->execute(
                    "UPDATE sessions SET last_activity = NOW(), expires_at = ? WHERE user_id = ? AND session_id = ? LIMIT 1",
                    [$newExpiresAt, $userId, $sessionId]
                );
            } catch (Exception $e) {
                // لا نسجل الخطأ في keep-alive لتقليل الضغط على السيرفر
                // error_log("session_keepalive: Error updating session in database: " . $e->getMessage());
            }
        }
        
        // تحديث session cookie
        if (!headers_sent() && session_id()) {
            $isHttps = (
                (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443')
            );
            
            $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (3600 * 24 * 7);
            setcookie(session_name(), session_id(), [
                'expires' => time() + $sessionLifetime,
                'path' => '/',
                'domain' => '',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => $isHttps ? 'None' : 'Lax',
            ]);
        }
    }
    
    // إرجاع الاستجابة الناجحة
    echo json_encode([
        'success' => true,
        'message' => 'تم تجديد الجلسة',
        'last_activity' => $_SESSION['last_activity'] ?? time(),
        'timestamp' => time()
    ]);
    
} catch (Exception $e) {
    error_log("Session keep-alive error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ في تجديد الجلسة'
    ]);
}
