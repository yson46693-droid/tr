<?php
/**
 * API لإدارة بيانات WebAuthn (البصمة)
 */

define('ACCESS_ALLOWED', true);
// تعريف ثابت لمنع حذف الجلسة في webauthn_credentials API
define('WEBAUTHN_API_ACTIVE', true);

// التأكد من بدء الجلسة قبل أي شيء
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/path_helper.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit_log.php';

// === تحديث/إنشاء الجلسة في قاعدة البيانات قبل requireLogin() ===
// هذا يضمن أن الجلسة موجودة في قاعدة البيانات قبل التحقق منها
// هذا مهم لجميع المستخدمين (ليس فقط المدير) لضمان عمل الجلسة بشكل صحيح
try {
    // التأكد من أن الجلسة نشطة
    if (session_status() === PHP_SESSION_NONE) {
        if (!headers_sent()) {
            @session_start();
        }
    }
    
    // التحقق من وجود بيانات الجلسة الأساسية
    if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
        $userId = $_SESSION['user_id'];
        $sessionId = session_id();
        
        if (!empty($sessionId) && !empty($userId)) {
            if (function_exists('ensureSessionsTable') && ensureSessionsTable()) {
                try {
                    $db = db();
                    $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (3600 * 24 * 7);
                    $newExpiresAt = date('Y-m-d H:i:s', time() + $sessionLifetime);
                    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
                    
                    // محاولة تحديث الجلسة الموجودة أولاً
                    $sessionUpdated = $db->execute(
                        "UPDATE sessions SET last_activity = NOW(), expires_at = ? WHERE user_id = ? AND session_id = ?",
                        [$newExpiresAt, $userId, $sessionId]
                    );
                    
                    // إذا لم توجد جلسة (لم يتم تحديث أي صف)، إنشاء واحدة جديدة
                    if (!$sessionUpdated || ($sessionUpdated['affected_rows'] ?? 0) === 0) {
                        try {
                            $db->execute(
                                "INSERT INTO sessions (user_id, session_id, ip_address, user_agent, expires_at, last_activity) 
                                 VALUES (?, ?, ?, ?, ?, NOW())
                                 ON DUPLICATE KEY UPDATE last_activity = NOW(), expires_at = ?, user_id = ?",
                                [$userId, $sessionId, $ipAddress, $userAgent, $newExpiresAt, $newExpiresAt, $userId]
                            );
                            error_log("WebAuthn API - Created new session in database for user_id: {$userId}");
                        } catch (Exception $insertError) {
                            // إذا فشل INSERT بسبب duplicate key، جرب UPDATE مرة أخرى
                            try {
                                $db->execute(
                                    "UPDATE sessions SET last_activity = NOW(), expires_at = ?, user_id = ? WHERE session_id = ?",
                                    [$newExpiresAt, $userId, $sessionId]
                                );
                                error_log("WebAuthn API - Updated existing session by session_id for user_id: {$userId}");
                            } catch (Exception $updateError) {
                                error_log("WebAuthn API - Session insert/update failed: " . $insertError->getMessage() . " | Update error: " . $updateError->getMessage());
                            }
                        }
                    } else {
                        // تم تحديث الجلسة بنجاح
                        error_log("WebAuthn API - Updated existing session for user_id: {$userId}");
                    }
                    
                    // التأكد من أن $_SESSION['logged_in'] مضبوط بشكل صحيح
                    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
                        $_SESSION['logged_in'] = true;
                    }
                    
                } catch (Exception $dbError) {
                    // خطأ في قاعدة البيانات - نسجل الخطأ لكن لا نوقف العملية
                    error_log("WebAuthn API load - Database error while updating session: " . $dbError->getMessage());
                }
            }
        }
    }
} catch (Exception $e) {
    // لا نوقف العملية إذا فشل تحديث الجلسة، فقط نسجل الخطأ
    error_log("WebAuthn API load - Error updating session in database: " . $e->getMessage());
}

// التحقق من تسجيل الدخول - مع معالجة خاصة لـ API endpoints
try {
    requireLogin();
} catch (Exception $e) {
    // في حالة فشل requireLogin() في API endpoint، نعيد JSON response
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'انتهت جلسة العمل، يرجى إعادة تسجيل الدخول',
        'debug' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// التحقق مرة أخرى من أن المستخدم مسجل دخول
// ملاحظة: في API endpoints المحمية (WEBAUTHN_API_ACTIVE)، نتحقق من $_SESSION مباشرة
// بدلاً من isLoggedIn() لأن isLoggedIn() قد يعيد false حتى لو كانت الجلسة صالحة
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // محاولة أخيرة: التحقق من isLoggedIn() فقط إذا فشل التحقق من $_SESSION
    if (!isLoggedIn()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'انتهت جلسة العمل، يرجى إعادة تسجيل الدخول'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

header('Content-Type: application/json; charset=utf-8');
// CORS headers
header('Access-Control-Allow-Origin: ' . (isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'list') {
    try {
        // التحقق من أن المستخدم مسجل دخول - التحقق بعد requireLogin()
        // requireLogin() يجب أن يكون قد تحقق من الجلسة بالفعل
        // في API endpoints المحمية، نتحقق من $_SESSION مباشرة
        if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            // محاولة أخيرة: التحقق من isLoggedIn() فقط إذا فشل التحقق من $_SESSION
            if (!isLoggedIn()) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'انتهت جلسة العمل، يرجى إعادة تسجيل الدخول']);
                exit;
            }
        }
        
        // الحصول على قائمة الاعتماديات للمستخدم
        if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
            // محاولة استعادة user_id من الجلسة
            $currentUser = getCurrentUser();
            if (!$currentUser || !isset($currentUser['id'])) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'غير مصرح به - تعذر تحميل بيانات المستخدم']);
                exit;
            }
            $_SESSION['user_id'] = $currentUser['id'];
        }
        
        $userId = $_SESSION['user_id'];
        $db = db();
        
        // === تحديث الجلسة في قاعدة البيانات ===
        try {
            if (function_exists('ensureSessionsTable') && ensureSessionsTable()) {
                $sessionId = session_id();
                if (!empty($sessionId) && !empty($userId)) {
                    $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (3600 * 24 * 7);
                    $newExpiresAt = date('Y-m-d H:i:s', time() + $sessionLifetime);
                    
                    // محاولة تحديث الجلسة الموجودة
                    $db->execute(
                        "UPDATE sessions SET last_activity = NOW(), expires_at = ? WHERE user_id = ? AND session_id = ?",
                        [$newExpiresAt, $userId, $sessionId]
                    );
                }
            }
        } catch (Exception $e) {
            // لا نوقف العملية إذا فشل تحديث الجلسة
            error_log("WebAuthn credentials list - Error updating session: " . $e->getMessage());
        }
        
        if (!$db) {
            throw new Exception('فشل الاتصال بقاعدة البيانات');
        }
        
        $credentials = $db->query(
            "SELECT id, credential_id, device_name, created_at, last_used 
             FROM webauthn_credentials 
             WHERE user_id = ? 
             ORDER BY created_at DESC",
            [$userId]
        );
        
        // التأكد من أن $credentials هو array
        if (!is_array($credentials)) {
            $credentials = [];
        }
        
        echo json_encode([
            'success' => true,
            'credentials' => $credentials
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        error_log("WebAuthn Credentials List Error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'خطأ في تحميل البصمات: ' . $e->getMessage(),
            'debug' => [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
    // حذف اعتماد محدد
    $userId = $_SESSION['user_id'];
    $credentialId = $_POST['credential_id'] ?? '';
    
    if (empty($credentialId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'معرّف الاعتماد مطلوب']);
        exit;
    }
    
    $db = db();
    
    // التحقق من أن الاعتماد يخص المستخدم الحالي
    $credential = $db->queryOne(
        "SELECT id FROM webauthn_credentials WHERE id = ? AND user_id = ?",
        [$credentialId, $userId]
    );
    
    if (!$credential) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'الاعتماد غير موجود أو غير مسموح']);
        exit;
    }
    
    // حذف الاعتماد
    $db->execute(
        "DELETE FROM webauthn_credentials WHERE id = ? AND user_id = ?",
        [$credentialId, $userId]
    );
    
    // التحقق من وجود اعتماديات أخرى للمستخدم
    $remainingCount = $db->queryOne(
        "SELECT COUNT(*) as count FROM webauthn_credentials WHERE user_id = ?",
        [$userId]
    );
    
    // إذا لم يبق أي اعتماد، تحديث حالة المستخدم
    if ($remainingCount['count'] == 0) {
        $db->execute(
            "UPDATE users SET webauthn_enabled = 0, updated_at = NOW() WHERE id = ?",
            [$userId]
        );
    }
    
    // تسجيل في سجل التدقيق
    logAudit($userId, 'delete_webauthn_credential', 'webauthn_credentials', $credentialId, null, null);
    
    // === تحديث الجلسة في قاعدة البيانات ===
    try {
        if (function_exists('ensureSessionsTable') && ensureSessionsTable()) {
            $sessionId = session_id();
            if (!empty($sessionId) && !empty($userId)) {
                $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (3600 * 24 * 7);
                $newExpiresAt = date('Y-m-d H:i:s', time() + $sessionLifetime);
                
                // محاولة تحديث الجلسة الموجودة
                $db->execute(
                    "UPDATE sessions SET last_activity = NOW(), expires_at = ? WHERE user_id = ? AND session_id = ?",
                    [$newExpiresAt, $userId, $sessionId]
                );
            }
        }
    } catch (Exception $e) {
        // لا نوقف العملية إذا فشل تحديث الجلسة
        error_log("WebAuthn credentials delete - Error updating session: " . $e->getMessage());
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'تم حذف البصمة بنجاح',
        'remaining_count' => $remainingCount['count']
    ]);
    
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'إجراء غير صحيح']);
}

