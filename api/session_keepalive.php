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
    
    // تم إزالة نظام الجلسات - تحديث remember_token في قاعدة البيانات فقط
    $user = getUserFromToken();
    if ($user && isset($user['id'])) {
        try {
            $db = db();
            if (isset($_COOKIE['remember_token']) && !empty($_COOKIE['remember_token'])) {
                $cookieValue = $_COOKIE['remember_token'];
                $decoded = base64_decode($cookieValue, true);
                if ($decoded) {
                    $parts = explode(':', $decoded);
                    if (count($parts) === 2) {
                        $userId = intval($parts[0]);
                        $token = trim($parts[1]);
                        
                        // تحديث last_used في remember_tokens
                        if (ensureRememberTokensTable()) {
                            $db->execute(
                                "UPDATE remember_tokens SET last_used = NOW() WHERE user_id = ? AND token = ?",
                                [$userId, $token]
                            );
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // لا نسجل الخطأ في keep-alive لتقليل الضغط على السيرفر
        }
    }
    
    // إرجاع الاستجابة الناجحة
    echo json_encode([
        'success' => true,
        'message' => 'تم تجديد الجلسة',
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
