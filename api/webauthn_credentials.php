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

// === التحقق من تسجيل الدخول - مع معالجة محسّنة ===
// استخدام isLoggedIn() أولاً لأنه الأكثر موثوقية
$isAuthenticated = false;
$userId = null;

// التأكد من أن الثابت معرّف قبل استدعاء isLoggedIn()
if (!defined('WEBAUTHN_API_ACTIVE')) {
    define('WEBAUTHN_API_ACTIVE', true);
}

// التحقق 1: استخدام isLoggedIn() - الأكثر موثوقية
try {
    // تحديث الجلسة في قاعدة البيانات قبل التحقق
    $sessionId = session_id();
    if (!empty($sessionId) && isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
        try {
            if (function_exists('ensureSessionsTable') && ensureSessionsTable()) {
                $db = db();
                $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (3600 * 24 * 7);
                $newExpiresAt = date('Y-m-d H:i:s', time() + $sessionLifetime);
                
                // تحديث الجلسة في قاعدة البيانات
                $db->execute(
                    "UPDATE sessions SET last_activity = NOW(), expires_at = ? WHERE user_id = ? AND session_id = ?",
                    [$newExpiresAt, $_SESSION['user_id'], $sessionId]
                );
            }
        } catch (Exception $updateError) {
            error_log("WebAuthn API - Failed to update session before check: " . $updateError->getMessage());
        }
    }
    
    if (isLoggedIn()) {
        // إذا نجح isLoggedIn()، نحاول الحصول على user_id
        if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
            $userId = $_SESSION['user_id'];
            $isAuthenticated = true;
        } else {
            // إذا كان isLoggedIn() نجح لكن user_id غير موجود في $_SESSION، نحاول استعادته
            $sessionId = session_id();
            if (!empty($sessionId) && function_exists('ensureSessionsTable') && ensureSessionsTable()) {
                $db = db();
                $sessionRecord = $db->queryOne(
                    "SELECT user_id FROM sessions WHERE session_id = ? ORDER BY last_activity DESC LIMIT 1",
                    [$sessionId]
                );
                if ($sessionRecord && isset($sessionRecord['user_id'])) {
                    $userId = $sessionRecord['user_id'];
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['logged_in'] = true;
                    $isAuthenticated = true;
                }
            }
        }
    }
} catch (Exception $e) {
    error_log("WebAuthn API - isLoggedIn() check failed: " . $e->getMessage());
}

// التحقق 2: إذا فشل isLoggedIn()، جرب getCurrentUser()
if (!$isAuthenticated) {
    try {
        $currentUser = getCurrentUser();
        if ($currentUser && isset($currentUser['id']) && !empty($currentUser['id'])) {
            $userId = $currentUser['id'];
            // تحديث $_SESSION ببيانات المستخدم المستعادة
            $_SESSION['user_id'] = $userId;
            if (!isset($_SESSION['username']) || $_SESSION['username'] !== $currentUser['username']) {
                $_SESSION['username'] = $currentUser['username'];
            }
            if (!isset($_SESSION['role']) || $_SESSION['role'] !== $currentUser['role']) {
                $_SESSION['role'] = $currentUser['role'];
            }
            $_SESSION['logged_in'] = true;
            $isAuthenticated = true;
        }
    } catch (Exception $e) {
        error_log("WebAuthn API - getCurrentUser() failed: " . $e->getMessage());
    }
}

// التحقق 3: إذا فشل، جرب البحث عن الجلسة في قاعدة البيانات مباشرة
if (!$isAuthenticated) {
    try {
        $sessionId = session_id();
        if (!empty($sessionId) && function_exists('ensureSessionsTable') && ensureSessionsTable()) {
            $db = db();
            // البحث عن الجلسة حتى لو كانت منتهية الصلاحية (لإعطاء فرصة للتجديد)
            $sessionRecord = $db->queryOne(
                "SELECT user_id, expires_at FROM sessions WHERE session_id = ? ORDER BY last_activity DESC LIMIT 1",
                [$sessionId]
            );
            if ($sessionRecord && isset($sessionRecord['user_id'])) {
                $foundUserId = $sessionRecord['user_id'];
                // التحقق من أن الجلسة لم تنتهِ أو تجديدها إذا كانت قريبة من الانتهاء
                $expiresAt = $sessionRecord['expires_at'];
                $isExpired = strtotime($expiresAt) < time();
                
                // إذا كانت الجلسة منتهية لكنها حديثة (أقل من ساعة)، نجددها
                if ($isExpired && (time() - strtotime($expiresAt)) < 3600) {
                    $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (3600 * 24 * 7);
                    $newExpiresAt = date('Y-m-d H:i:s', time() + $sessionLifetime);
                    $db->execute(
                        "UPDATE sessions SET expires_at = ?, last_activity = NOW() WHERE session_id = ?",
                        [$newExpiresAt, $sessionId]
                    );
                    $expiresAt = $newExpiresAt;
                }
                
                // إذا كانت الجلسة صالحة (أو تم تجديدها)، نتحقق من المستخدم
                if (strtotime($expiresAt) > time()) {
                    $userFromDb = $db->queryOne("SELECT * FROM users WHERE id = ? AND status = 'active'", [$foundUserId]);
                    if ($userFromDb && isset($userFromDb['id'])) {
                        $userId = $userFromDb['id'];
                        // تحديث الجلسة ببيانات المستخدم
                        $_SESSION['user_id'] = $userId;
                        $_SESSION['username'] = $userFromDb['username'];
                        $_SESSION['role'] = $userFromDb['role'];
                        $_SESSION['logged_in'] = true;
                        $isAuthenticated = true;
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log("WebAuthn API - Session lookup from database failed: " . $e->getMessage());
    }
}

// التحقق 4: محاولة أخيرة - تحميل مباشر من قاعدة البيانات باستخدام user_id من الجلسة
if (!$isAuthenticated && isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    try {
        $db = db();
        $userFromDb = $db->queryOne("SELECT * FROM users WHERE id = ? AND status = 'active'", [$_SESSION['user_id']]);
        if ($userFromDb && isset($userFromDb['id'])) {
            $userId = $userFromDb['id'];
            // تحديث الجلسة ببيانات المستخدم
            $_SESSION['user_id'] = $userId;
            $_SESSION['username'] = $userFromDb['username'];
            $_SESSION['role'] = $userFromDb['role'];
            $_SESSION['logged_in'] = true;
            $isAuthenticated = true;
        }
    } catch (Exception $e) {
        error_log("WebAuthn API - Direct database query failed: " . $e->getMessage());
    }
}

// محاولة أخيرة: التحقق من الجلسة مباشرة من قاعدة البيانات قبل إرجاع 401
if (!$isAuthenticated || !$userId) {
    try {
        $sessionId = session_id();
        if (!empty($sessionId) && function_exists('ensureSessionsTable') && ensureSessionsTable()) {
            $db = db();
            $sessionRecord = $db->queryOne(
                "SELECT user_id, expires_at FROM sessions WHERE session_id = ? ORDER BY last_activity DESC LIMIT 1",
                [$sessionId]
            );
            if ($sessionRecord && isset($sessionRecord['user_id'])) {
                $expiresAt = $sessionRecord['expires_at'];
                // إذا كانت الجلسة منتهية لكنها حديثة (أقل من ساعة)، نجددها
                if (strtotime($expiresAt) < time() && (time() - strtotime($expiresAt)) < 3600) {
                    $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (3600 * 24 * 7);
                    $newExpiresAt = date('Y-m-d H:i:s', time() + $sessionLifetime);
                    $db->execute(
                        "UPDATE sessions SET expires_at = ?, last_activity = NOW() WHERE session_id = ?",
                        [$newExpiresAt, $sessionId]
                    );
                    $expiresAt = $newExpiresAt;
                }
                
                if (strtotime($expiresAt) > time()) {
                    $userFromDb = $db->queryOne("SELECT * FROM users WHERE id = ? AND status = 'active'", [$sessionRecord['user_id']]);
                    if ($userFromDb && isset($userFromDb['id'])) {
                        $userId = $userFromDb['id'];
                        $_SESSION['user_id'] = $userId;
                        $_SESSION['username'] = $userFromDb['username'];
                        $_SESSION['role'] = $userFromDb['role'];
                        $_SESSION['logged_in'] = true;
                        $isAuthenticated = true;
                        error_log("WebAuthn API - Last attempt succeeded: Restored session for user_id: {$userId}");
                    }
                }
            }
        }
    } catch (Exception $lastAttemptError) {
        error_log("WebAuthn API - Last attempt to restore session failed: " . $lastAttemptError->getMessage());
    }
}

// إذا فشلت جميع محاولات التحقق
if (!$isAuthenticated || !$userId) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'انتهت جلسة العمل، يرجى إعادة تسجيل الدخول',
        'debug' => [
            'session_id' => session_id(),
            'session_user_id' => $_SESSION['user_id'] ?? 'not set',
            'session_logged_in' => $_SESSION['logged_in'] ?? 'not set',
            'isLoggedIn_result' => isLoggedIn() ? 'true' : 'false'
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
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
        // === استخدام user_id من التحقق الأولي ===
        // $userId تم تعيينه بالفعل في التحقق الأولي أعلاه
        // إذا لم يكن موجوداً، هذا يعني أن التحقق فشل بالفعل وتم إرجاع 401
        if (!$userId || empty($userId)) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'error' => 'غير مصرح به - تعذر تحميل بيانات المستخدم',
                'debug' => [
                    'session_user_id' => $_SESSION['user_id'] ?? 'not set',
                    'session_logged_in' => $_SESSION['logged_in'] ?? 'not set',
                    'session_id' => session_id()
                ]
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // === تحديث الجلسة في قاعدة البيانات ===
        try {
            if (!isset($db)) {
                $db = db();
            }
            
            if ($db && function_exists('ensureSessionsTable') && ensureSessionsTable()) {
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
        
        // التأكد من وجود اتصال بقاعدة البيانات
        if (!isset($db)) {
            $db = db();
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
    // $userId تم تعيينه بالفعل في التحقق الأولي أعلاه
    if (!$userId || empty($userId)) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'غير مصرح به - تعذر تحميل بيانات المستخدم'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
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

