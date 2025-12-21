<?php
/**
 * API للتحقق من وجود الجلسة
 * النظام يعتمد على PHP Sessions
 */

define('ACCESS_ALLOWED', true);
define('NOTIFICATIONS_API_ACTIVE', true);

error_reporting(0);
ini_set('display_errors', 0);

// إعدادات SOAP WSDL Cache - منع خطأ open_basedir restriction
@ini_set('soap.wsdl_cache_enabled', '0'); // تعطيل WSDL caching
@ini_set('soap.wsdl_cache_ttl', '0'); // تعطيل TTL للكاش

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
    // تعطيل error_log لتقليل الضغط على السيرفر
    // error_log("Check Session API initialization error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Initialization error']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// التحقق من تسجيل الدخول - يعتمد على الجلسات
$user = null;
$userId = null;

// محاولة الحصول على المستخدم من الجلسة
try {
    if (isLoggedIn()) {
        // التحقق من API Token للحماية من الوصول الخارجي
        // ملاحظة: نتحقق فقط إذا كان هناك token في الطلب (لأن check_session قد يُستدعى بدون token من بعض الأماكن)
        $requestToken = $_POST['api_token'] ?? $_GET['api_token'] ?? null;
        if ($requestToken !== null) {
            if (!verifyAPIToken()) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Invalid API token']);
                exit;
            }
        }
        
        $user = getCurrentUser();
        if ($user && isset($user['id'])) {
            $userId = $user['id'];
        }
    }
} catch (Exception $e) {
    // تعطيل error_log لتقليل الضغط على السيرفر
    // error_log("Check Session API - Error checking login: " . $e->getMessage());
}

if ($userId) {
    echo json_encode([
        'success' => true,
        'session_exists' => true,
        'user_id' => $userId,
        'method' => 'session'
    ]);
} else {
    echo json_encode([
        'success' => true,
        'session_exists' => false
    ]);
}
