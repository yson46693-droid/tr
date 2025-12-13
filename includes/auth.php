<?php
/**
 * نظام المصادقة والتحقق من الأدوار
 * نظام إدارة الشركات المتكامل
 */

// منع الوصول المباشر
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// تحميل path_helper إذا كان متوفراً
if (!function_exists('getRelativeUrl') && file_exists(__DIR__ . '/path_helper.php')) {
    require_once __DIR__ . '/path_helper.php';
}

/**
 * الحصول على الحد الأدنى لطول كلمة المرور من الإعدادات
 *
 * @return int
 */
function getPasswordMinLength(): int
{
    static $cachedValue = null;

    if ($cachedValue !== null) {
        return $cachedValue;
    }

    if (defined('PASSWORD_MIN_LENGTH')) {
        $value = (int) PASSWORD_MIN_LENGTH;
    } else {
        $value = 8;
    }

    if ($value < 1) {
        $value = 1;
    }

    return $cachedValue = $value;
}

/**
 * التحقق من تسجيل الدخول
 */
function isLoggedIn() {
    // تسجيل بداية التحقق من الجلسة
    $startTime = microtime(true);
    $requestUri = $_SERVER['REQUEST_URI'] ?? 'unknown';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? 'unknown';
    
    // التحقق من أننا في profile.php أو attendance.php أو notifications API - منع حذف الجلسة
    $isProfilePage = false;
    $isAttendancePage = false;
    $isNotificationsAPI = false;
    
    // الطريقة 1: التحقق من الثوابت (الأكثر موثوقية)
    if (defined('PROFILE_PAGE_ACTIVE') && PROFILE_PAGE_ACTIVE === true) {
        $isProfilePage = true;
    }
    if (defined('ATTENDANCE_PAGE_ACTIVE') && ATTENDANCE_PAGE_ACTIVE === true) {
        $isAttendancePage = true;
    }
    if (defined('NOTIFICATIONS_API_ACTIVE') && NOTIFICATIONS_API_ACTIVE === true) {
        $isNotificationsAPI = true;
    }
    
    // الطريقة 2: التحقق من SCRIPT_NAME و PHP_SELF
    if (!$isProfilePage && !$isAttendancePage && !$isNotificationsAPI) {
        $currentScript = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
        if (strpos($currentScript, 'profile.php') !== false || basename($currentScript) === 'profile.php') {
            $isProfilePage = true;
        } elseif (strpos($currentScript, 'attendance.php') !== false || basename($currentScript) === 'attendance.php') {
            $isAttendancePage = true;
        } elseif (strpos($currentScript, 'notifications.php') !== false || basename($currentScript) === 'notifications.php') {
            $isNotificationsAPI = true;
        }
    }
    
    // الطريقة 3: التحقق من REQUEST_URI
    if (!$isProfilePage && !$isAttendancePage && !$isNotificationsAPI) {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($requestUri, 'profile.php') !== false) {
            $isProfilePage = true;
        } elseif (strpos($requestUri, 'attendance.php') !== false) {
            $isAttendancePage = true;
        } elseif (strpos($requestUri, 'notifications.php') !== false || strpos($requestUri, '/api/notifications') !== false) {
            $isNotificationsAPI = true;
        }
    }
    
    $isProtectedPage = $isProfilePage || $isAttendancePage || $isNotificationsAPI;
    
    // التأكد من أن الجلسة نشطة قبل أي فحص
    if (session_status() === PHP_SESSION_NONE) {
        if (!headers_sent()) {
            @session_start();
        } else {
            // تسجيل سبب الفشل
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            if (function_exists('logSessionFailure')) {
                logSessionFailure('الجلسة غير نشطة و headers تم إرسالها بالفعل', [
                    'session_status' => session_status(),
                    'headers_sent' => headers_sent(),
                    'duration_ms' => $duration,
                    'script' => $scriptName,
                    'uri' => $requestUri,
                ]);
            }
            error_log("isLoggedIn() FALSE: Session not started and headers already sent | Duration: {$duration}ms | Script: {$scriptName} | URI: {$requestUri}");
            return false;
        }
    }
    
    // التحقق من وجود الجلسة في قاعدة البيانات
    if (isset($_SESSION['user_id']) && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
        $userId = $_SESSION['user_id'];
        $sessionId = session_id();
        
        try {
            if (ensureSessionsTable()) {
                $db = db();
                $sessionRecord = $db->queryOne(
                    "SELECT * FROM sessions WHERE user_id = ? AND session_id = ? AND expires_at > NOW()",
                    [$userId, $sessionId]
                );
                
                // إذا لم توجد الجلسة في قاعدة البيانات لكن الجلسة PHP صالحة، أعد إنشاءها
                if (!$sessionRecord) {
                    // تسجيل محاولة إعادة إنشاء الجلسة
                    if (file_exists(__DIR__ . '/session_logger.php')) {
                        require_once __DIR__ . '/session_logger.php';
                        if (function_exists('logSessionInfo')) {
                            logSessionInfo('الجلسة غير موجودة في قاعدة البيانات - محاولة إعادة إنشائها', [
                                'user_id' => $userId,
                                'session_id' => substr($sessionId, 0, 20) . '...',
                            ]);
                        }
                    }
                    
                    // التحقق من أن المستخدم موجود ونشط
                    $user = $db->queryOne("SELECT * FROM users WHERE id = ? AND status = 'active'", [$userId]);
                    
                    if ($user) {
                        // إعادة إنشاء الجلسة في قاعدة البيانات
                        $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (3600 * 24 * 7);
                        $expiresAt = date('Y-m-d H:i:s', time() + $sessionLifetime);
                        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
                        
                        // حذف جميع الجلسات القديمة للمستخدم أولاً (لتجنب الجلسات المتعددة)
                        $db->execute("DELETE FROM sessions WHERE user_id = ?", [$userId]);
                        
                        // إضافة الجلسة الجديدة (واحدة فقط)
                        $db->execute(
                            "INSERT INTO sessions (user_id, session_id, ip_address, user_agent, expires_at, last_activity) 
                             VALUES (?, ?, ?, ?, ?, NOW())",
                            [$userId, $sessionId, $ipAddress, $userAgent, $expiresAt]
                        );
                        
                        // تسجيل نجاح إعادة إنشاء الجلسة
                        if (function_exists('logSessionInfo')) {
                            logSessionInfo('تم إعادة إنشاء الجلسة في قاعدة البيانات بنجاح', [
                                'user_id' => $userId,
                                'expires_at' => $expiresAt,
                            ]);
                        }
                    } else {
                        // المستخدم غير موجود أو غير نشط - الجلسة غير صالحة
                        // تسجيل الفشل
                        if (function_exists('logSessionFailure')) {
                            logSessionFailure('المستخدم غير موجود أو غير نشط في قاعدة البيانات', [
                                'user_id' => $userId,
                            ]);
                        }
                        
                        // لا نحذف الجلسة PHP أثناء معالجة POST (لتجنب مشاكل CSRF)
                        // ولا نحذفها في API calls (لتجنب مشاكل AJAX)
                        $isPostRequest = $_SERVER['REQUEST_METHOD'] === 'POST';
                        $isApiRequest = strpos($currentScript, '/api/') !== false || 
                                       (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
                        
                        // فقط نحذف الجلسة إذا لم يكن POST request وليس API request وليس في صفحة محمية
                        if (!$isPostRequest && !$isApiRequest && !$isProtectedPage) {
                            session_unset();
                            session_destroy();
                        }
                        return false;
                    }
                } else {
                    // تحديث last_activity في قاعدة البيانات كل دقيقة (لتجنب كثرة التحديثات)
                    $lastActivity = strtotime($sessionRecord['last_activity']);
                    if (time() - $lastActivity > 60) { // أكثر من دقيقة
                        $db->execute(
                            "UPDATE sessions SET last_activity = NOW() WHERE id = ?",
                            [$sessionRecord['id']]
                        );
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Error checking session in database: " . $e->getMessage());
            
            // تسجيل الخطأ
            if (file_exists(__DIR__ . '/session_logger.php')) {
                require_once __DIR__ . '/session_logger.php';
                if (function_exists('logSessionInfo')) {
                    logSessionInfo('خطأ في التحقق من الجلسة في قاعدة البيانات', [
                        'error' => $e->getMessage(),
                        'user_id' => $userId ?? 'unknown',
                    ]);
                }
            }
            
            // في حالة الخطأ، نستمر بالجلسة PHP العادية
        }
    }
    
    // التأكد من وجود $_SESSION
    if (!isset($_SESSION) || !is_array($_SESSION)) {
        // تسجيل سبب الفشل
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        if (function_exists('logSessionFailure')) {
            logSessionFailure('$_SESSION غير موجود أو ليس array', [
                'has_session' => isset($_SESSION),
                'is_array' => isset($_SESSION) && is_array($_SESSION),
                'duration_ms' => $duration,
                'script' => $scriptName,
                'uri' => $requestUri,
            ]);
        }
        error_log("isLoggedIn() FALSE: \$_SESSION not set or not array | Duration: {$duration}ms | Script: {$scriptName} | URI: {$requestUri}");
        return false;
    }
    
    // التحقق من الجلسة أولاً (قبل أي فحوصات أخرى)
    if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
        // التحقق الإضافي: التأكد من وجود user_id في الجلسة
        if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        // تسجيل سبب الفشل
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        if (function_exists('logSessionFailure')) {
            logSessionFailure('user_id غير موجود أو فارغ في الجلسة', [
                'has_user_id' => isset($_SESSION['user_id']),
                'user_id_value' => $_SESSION['user_id'] ?? null,
                'logged_in' => $_SESSION['logged_in'] ?? null,
                'duration_ms' => $duration,
                'script' => $scriptName,
                'uri' => $requestUri,
            ]);
        }
        error_log("isLoggedIn() FALSE: user_id not set or empty | logged_in: " . ($_SESSION['logged_in'] ?? 'NOT_SET') . " | Duration: {$duration}ms | Script: {$scriptName} | URI: {$requestUri}");
            
            // إذا لم يكن هناك user_id، إلغاء الجلسة فقط إذا لم نكن في profile.php أو attendance.php
            if (!$isProtectedPage) {
                session_unset();
                session_destroy();
            }
            return false;
        }
        
        // إذا كانت الجلسة صالحة، نتحقق من session cookie (لكن لا ننهي الجلسة مباشرة)
        $sessionName = session_name();
        if (session_status() === PHP_SESSION_ACTIVE) {
            // إذا لم يكن هناك session cookie، نحاول تحديثه بدلاً من إلغاء الجلسة
            if (!isset($_COOKIE[$sessionName])) {
                // محاولة تحديث cookie إذا كانت headers لم تُرسل بعد
                if (!headers_sent()) {
                    $isHttps = (
                        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                        (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443')
                    );
                    $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (3600 * 24 * 7);
                    setcookie($sessionName, session_id(), [
                        'expires' => time() + $sessionLifetime,
                        'path' => '/',
                        'domain' => '',
                        'secure' => $isHttps,
                        'httponly' => true,
                        'samesite' => $isHttps ? 'None' : 'Lax',
                    ]);
                }
                // نستمر حتى لو لم نستطع تحديث cookie (لأن الجلسة صالحة)
            } else {
                // التحقق من أن session ID في cookie يطابق session ID الحالي
                $cookieSessionId = $_COOKIE[$sessionName];
                $currentSessionId = session_id();
                
                // إذا كان session ID غير متطابق، نحاول تحديث cookie بدلاً من إلغاء الجلسة
                if ($cookieSessionId !== $currentSessionId) {
                    // محاولة تحديث cookie إذا كانت headers لم تُرسل بعد
                    if (!headers_sent()) {
                        $isHttps = (
                            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                            (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443')
                        );
                        $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (3600 * 24 * 7);
                        setcookie($sessionName, $currentSessionId, [
                            'expires' => time() + $sessionLifetime,
                            'path' => '/',
                            'domain' => '',
                            'secure' => $isHttps,
                            'httponly' => true,
                            'samesite' => $isHttps ? 'None' : 'Lax',
                        ]);
                    }
                    // نستمر حتى لو لم نستطع تحديث cookie (لأن الجلسة صالحة)
                }
            }
        }
        
        // تسجيل نجاح التحقق
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        if (function_exists('logSessionInfo')) {
            logSessionInfo('isLoggedIn() SUCCESS', [
                'duration_ms' => $duration,
                'user_id' => $_SESSION['user_id'] ?? null,
                'script' => $scriptName,
                'uri' => $requestUri,
            ]);
        }
        error_log("isLoggedIn() TRUE: User ID " . ($_SESSION['user_id'] ?? 'unknown') . " | Duration: {$duration}ms | Script: {$scriptName}");
        return true;
    }
    
    // إذا لم تكن هناك جلسة، التحقق من cookie "تذكرني"
    if (isset($_COOKIE['remember_token'])) {
        $rememberResult = checkRememberToken($_COOKIE['remember_token']);
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        if (!$rememberResult) {
            if (function_exists('logSessionFailure')) {
                logSessionFailure('فشل التحقق من remember_token', [
                    'has_remember_token' => isset($_COOKIE['remember_token']),
                    'duration_ms' => $duration,
                ]);
            }
            error_log("isLoggedIn() FALSE: Remember token check failed | Duration: {$duration}ms | Script: {$scriptName}");
        } else {
            error_log("isLoggedIn() TRUE: Remember token valid | Duration: {$duration}ms | Script: {$scriptName}");
        }
        return $rememberResult;
    }
    
    // تسجيل سبب الفشل النهائي
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    if (function_exists('logSessionFailure')) {
        logSessionFailure('لا توجد جلسة مسجل دخول ولا remember_token', [
            'has_logged_in' => isset($_SESSION['logged_in']),
            'logged_in_value' => $_SESSION['logged_in'] ?? null,
            'has_user_id' => isset($_SESSION['user_id']),
            'has_remember_token' => isset($_COOKIE['remember_token']),
            'duration_ms' => $duration,
            'script' => $scriptName,
            'uri' => $requestUri,
        ]);
    }
    error_log("isLoggedIn() FALSE: No logged_in session and no remember_token | logged_in: " . ($_SESSION['logged_in'] ?? 'NOT_SET') . " | user_id: " . ($_SESSION['user_id'] ?? 'NOT_SET') . " | Duration: {$duration}ms | Script: {$scriptName} | URI: {$requestUri}");
    
    return false;
}

/**
 * إنشاء جدول remember_tokens إذا لم يكن موجوداً
 */
function ensureRememberTokensTable() {
    $db = db();
    $tableCheck = $db->queryOne("SHOW TABLES LIKE 'remember_tokens'");
    
    if (empty($tableCheck)) {
        try {
            $db->execute("
                CREATE TABLE IF NOT EXISTS `remember_tokens` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `user_id` int(11) NOT NULL,
                  `token` varchar(64) NOT NULL,
                  `expires_at` datetime NOT NULL,
                  `ip_address` varchar(45) DEFAULT NULL,
                  `user_agent` text DEFAULT NULL,
                  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `last_used` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `user_id_token` (`user_id`, `token`),
                  KEY `token` (`token`),
                  KEY `expires_at` (`expires_at`),
                  KEY `user_id` (`user_id`),
                  CONSTRAINT `remember_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Exception $e) {
            error_log("Error creating remember_tokens table: " . $e->getMessage());
            return false;
        }
    }
    return true;
}

/**
 * إنشاء جدول sessions إذا لم يكن موجوداً
 * لتخزين جلسات تسجيل الدخول النشطة في قاعدة البيانات
 */
function ensureSessionsTable() {
    $db = db();
    $tableCheck = $db->queryOne("SHOW TABLES LIKE 'sessions'");
    
    if (empty($tableCheck)) {
        try {
            $db->execute("
                CREATE TABLE IF NOT EXISTS `sessions` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `user_id` int(11) NOT NULL,
                  `session_id` varchar(128) NOT NULL,
                  `ip_address` varchar(45) DEFAULT NULL,
                  `user_agent` text DEFAULT NULL,
                  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `expires_at` datetime NOT NULL,
                  `last_activity` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `session_id` (`session_id`),
                  KEY `user_id` (`user_id`),
                  KEY `expires_at` (`expires_at`),
                  KEY `last_activity` (`last_activity`),
                  CONSTRAINT `sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Exception $e) {
            error_log("Error creating sessions table: " . $e->getMessage());
            return false;
        }
    }
    return true;
}

/**
 * تنظيف الجلسات المنتهية الصلاحية من قاعدة البيانات
 * يمكن استدعاء هذه الدالة من cron job لتنظيف الجلسات القديمة
 * 
 * @param int $extraDays عدد الأيام الإضافية بعد انتهاء الصلاحية للحذف (افتراضي 0)
 * @return array إحصائيات عملية التنظيف
 */
function cleanupExpiredSessions($extraDays = 0) {
    try {
        if (!ensureSessionsTable()) {
            return [
                'success' => false,
                'deleted' => 0,
                'message' => 'جدول sessions غير موجود'
            ];
        }
        
        $db = db();
        
        // حذف الجلسات المنتهية الصلاحية
        $result = $db->execute(
            "DELETE FROM sessions WHERE expires_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$extraDays]
        );
        
        $deletedCount = intval($result['affected_rows'] ?? 0);
        
        return [
            'success' => true,
            'deleted' => $deletedCount,
            'message' => "تم حذف {$deletedCount} جلسة منتهية الصلاحية"
        ];
    } catch (Exception $e) {
        error_log("Error cleaning up expired sessions: " . $e->getMessage());
        return [
            'success' => false,
            'deleted' => 0,
            'message' => 'حدث خطأ أثناء تنظيف الجلسات: ' . $e->getMessage()
        ];
    }
}

/**
 * التحقق من token "تذكرني"
 */
function checkRememberToken($cookieValue) {
    try {
        // التأكد من وجود الجدول
        if (!ensureRememberTokensTable()) {
            return false;
        }
        
        $decoded = base64_decode($cookieValue);
        if (!$decoded) {
            return false;
        }
        
        $parts = explode(':', $decoded);
        if (count($parts) !== 2) {
            return false;
        }
        
        $userId = intval($parts[0]);
        $token = $parts[1];
        
        if ($userId <= 0 || empty($token)) {
            return false;
        }
        
        $db = db();
        $tokenRecord = $db->queryOne(
            "SELECT rt.*, u.* FROM remember_tokens rt
             INNER JOIN users u ON rt.user_id = u.id
             WHERE rt.user_id = ? AND rt.token = ? AND rt.expires_at > NOW() AND u.status = 'active'",
            [$userId, $token]
        );
        
        if (!$tokenRecord) {
            // حذف cookie غير صالح
            $isHttps = (
                (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443')
            );
            setcookie(
                'remember_token',
                '',
                [
                    'expires' => time() - 3600,
                    'path' => '/',
                    'domain' => '',
                    'secure' => $isHttps,
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]
            );
            return false;
        }
        
        // تحديث آخر استخدام
        $db->execute(
            "UPDATE remember_tokens SET last_used = NOW() WHERE id = ?",
            [$tokenRecord['id']]
        );
        
        // حفظ CSRF token الحالي قبل إعادة توليد الجلسة
        $currentCsrfToken = $_SESSION['csrf_token'] ?? null;
        
        // إنشاء جلسة جديدة
        if (session_status() === PHP_SESSION_ACTIVE) {
            if ($currentCsrfToken) {
                $_SESSION['csrf_token_previous'] = $currentCsrfToken;
                $_SESSION['csrf_token_previous_time'] = time();
            }
            session_regenerate_id(true);
        }
        
        // تجديد CSRF token بعد إعادة توليد الجلسة
        if (function_exists('generateCSRFToken')) {
            generateCSRFToken(true);
        }
        
        $_SESSION['user_id'] = $tokenRecord['user_id'];
        $_SESSION['username'] = $tokenRecord['username'];
        $_SESSION['role'] = $tokenRecord['role'];
        $_SESSION['logged_in'] = true;
        $_SESSION['last_activity'] = time(); // تحديث وقت آخر نشاط
        generateCSRFToken(true);
        
        // حفظ الجلسة في قاعدة البيانات
        try {
            if (ensureSessionsTable()) {
                $db = db();
                $sessionId = session_id();
                if (!empty($sessionId)) {
                    $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (3600 * 24 * 7); // افتراضياً 7 أيام
                    $expiresAt = date('Y-m-d H:i:s', time() + $sessionLifetime);
                    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255); // الحد الأقصى 255 حرف
                    
                    // حذف جميع الجلسات القديمة للمستخدم أولاً (لتجنب الجلسات المتعددة)
                    try {
                        $db->execute("DELETE FROM sessions WHERE user_id = ?", [$tokenRecord['user_id']]);
                    } catch (Exception $deleteError) {
                        error_log("Error deleting old sessions in checkRememberToken: " . $deleteError->getMessage());
                    }
                    
                    // إضافة الجلسة الجديدة (واحدة فقط)
                    try {
                        $db->execute(
                            "INSERT INTO sessions (user_id, session_id, ip_address, user_agent, expires_at, last_activity) 
                             VALUES (?, ?, ?, ?, ?, NOW())",
                            [$tokenRecord['user_id'], $sessionId, $ipAddress, $userAgent, $expiresAt]
                        );
                    } catch (Exception $insertError) {
                        error_log("Error inserting session in checkRememberToken: " . $insertError->getMessage());
                        // لا نتوقف عن تسجيل الدخول إذا فشل حفظ الجلسة في قاعدة البيانات
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Error in session database operations in checkRememberToken: " . $e->getMessage());
            // لا نتوقف عن تسجيل الدخول إذا فشل حفظ الجلسة في قاعدة البيانات
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Remember Token Check Error: " . $e->getMessage());
        return false;
    }
}

/**
 * الحصول على معلومات المستخدم الحالي
 * مع التحقق من وجود المستخدم وحالته وإلغاء تسجيل الدخول تلقائياً إذا كان محذوفاً أو غير مفعّل
 * مع استخدام Cache لتحسين الأداء
 */
function getCurrentUser() {
    $startTime = microtime(true);
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? 'unknown';
    $requestUri = $_SERVER['REQUEST_URI'] ?? 'unknown';
    
    // التأكد من أن الجلسة نشطة
    if (session_status() === PHP_SESSION_NONE) {
        if (!headers_sent()) {
            @session_start();
        } else {
            error_log("getCurrentUser() NULL: Session not started and headers sent | Script: {$scriptName}");
            return null;
        }
    }
    
    // التأكد من وجود $_SESSION
    if (!isset($_SESSION) || !is_array($_SESSION)) {
        error_log("getCurrentUser() NULL: \$_SESSION not set or not array | Script: {$scriptName}");
        return null;
    }
    
    if (!isLoggedIn()) {
        error_log("getCurrentUser() NULL: isLoggedIn() returned false | Script: {$scriptName}");
        return null;
    }
    
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) {
        error_log("getCurrentUser() NULL: user_id not set in session | Script: {$scriptName}");
        return null;
    }
    
    // تحميل نظام Cache
    if (!class_exists('Cache')) {
        $cacheFile = __DIR__ . '/cache.php';
        if (file_exists($cacheFile)) {
            require_once $cacheFile;
        }
    }
    
    // استخدام Cache لحفظ بيانات المستخدم لمدة 5 دقائق
    $cacheKey = "user_{$userId}";
    
    // إذا كان Cache متاحاً، استخدمه
    if (class_exists('Cache')) {
        $user = Cache::remember($cacheKey, function() use ($userId, $scriptName) {
            error_log("getCurrentUser() - Loading from database (cache miss) for user ID {$userId} | Script: {$scriptName}");
            return getCurrentUserFromDatabase($userId);
        }, 300); // 5 دقائق
        
        // إذا كان المستخدم null (محذوف)، احذف من Cache
        if ($user === null) {
            Cache::forget($cacheKey);
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            error_log("getCurrentUser() NULL: User not found in database (from cache) for user ID {$userId} | Duration: {$duration}ms | Script: {$scriptName}");
            return null;
        }
        
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        error_log("getCurrentUser() SUCCESS (from cache): User ID {$userId}, Role: " . ($user['role'] ?? 'NOT_SET') . " | Duration: {$duration}ms | Script: {$scriptName}");
        return $user;
    }
    
    // إذا لم يكن Cache متاحاً، استخدم الطريقة القديمة
    error_log("getCurrentUser() - Loading from database (no cache) for user ID {$userId} | Script: {$scriptName}");
    $user = getCurrentUserFromDatabase($userId);
    
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    if ($user) {
        error_log("getCurrentUser() SUCCESS: User ID {$userId}, Role: " . ($user['role'] ?? 'NOT_SET') . ", Status: " . ($user['status'] ?? 'NOT_SET') . " | Duration: {$duration}ms | Script: {$scriptName}");
    } else {
        error_log("getCurrentUser() NULL: getCurrentUserFromDatabase() returned null for user ID {$userId} | Duration: {$duration}ms | Script: {$scriptName}");
    }
    
    return $user;
}

/**
 * جلب بيانات المستخدم من قاعدة البيانات (دالة مساعدة)
 * 
 * @param int $userId معرف المستخدم
 * @return array|null بيانات المستخدم أو null
 */
function getCurrentUserFromDatabase($userId) {
    // التحقق من الملف الذي يستدعي هذه الدالة - منع حذف الجلسة في profile.php و attendance.php و notifications API
    // استخدام طرق متعددة للتحقق لضمان العمل على جميع الأجهزة (Windows, Android, iOS)
    $isProfilePage = false;
    $isAttendancePage = false;
    $isNotificationsAPI = false;
    
    // الطريقة 1: التحقق من الثوابت (الأكثر موثوقية)
    if (defined('PROFILE_PAGE_ACTIVE') && PROFILE_PAGE_ACTIVE === true) {
        $isProfilePage = true;
    }
    if (defined('ATTENDANCE_PAGE_ACTIVE') && ATTENDANCE_PAGE_ACTIVE === true) {
        $isAttendancePage = true;
    }
    if (defined('NOTIFICATIONS_API_ACTIVE') && NOTIFICATIONS_API_ACTIVE === true) {
        $isNotificationsAPI = true;
    }
    
    // الطريقة 2: التحقق من SCRIPT_NAME و PHP_SELF
    if (!$isProfilePage && !$isAttendancePage && !$isNotificationsAPI) {
        $currentScript = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
        if (strpos($currentScript, 'profile.php') !== false || basename($currentScript) === 'profile.php') {
            $isProfilePage = true;
        } elseif (strpos($currentScript, 'attendance.php') !== false || basename($currentScript) === 'attendance.php') {
            $isAttendancePage = true;
        } elseif (strpos($currentScript, 'notifications.php') !== false || basename($currentScript) === 'notifications.php') {
            $isNotificationsAPI = true;
        }
    }
    
    // الطريقة 3: استخدام debug_backtrace كبديل
    if (!$isProfilePage && !$isAttendancePage && !$isNotificationsAPI) {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
        foreach ($backtrace as $trace) {
            if (isset($trace['file'])) {
                $fileName = basename($trace['file']);
                if ($fileName === 'profile.php' || strpos($trace['file'], 'profile.php') !== false) {
                    $isProfilePage = true;
                    break;
                } elseif ($fileName === 'attendance.php' || strpos($trace['file'], 'attendance.php') !== false) {
                    $isAttendancePage = true;
                    break;
                } elseif ($fileName === 'notifications.php' || strpos($trace['file'], 'notifications.php') !== false) {
                    $isNotificationsAPI = true;
                    break;
                }
            }
        }
    }
    
    // الطريقة 4: التحقق من REQUEST_URI
    if (!$isProfilePage && !$isAttendancePage && !$isNotificationsAPI) {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($requestUri, 'profile.php') !== false) {
            $isProfilePage = true;
        } elseif (strpos($requestUri, 'attendance.php') !== false) {
            $isAttendancePage = true;
        } elseif (strpos($requestUri, 'notifications.php') !== false || strpos($requestUri, '/api/notifications') !== false) {
            $isNotificationsAPI = true;
        }
    }
    
    // تحديد إذا كان الصفحة محمية (profile أو attendance أو notifications API)
    $isProtectedPage = $isProfilePage || $isAttendancePage || $isNotificationsAPI;
    
    // جلب جميع بيانات المستخدم من قاعدة البيانات
    try {
        $db = db();
        $user = $db->queryOne("SELECT * FROM users WHERE id = ?", [$userId]);
    } catch (Exception $e) {
        error_log("getCurrentUserFromDatabase - Database error for user ID {$userId}: " . $e->getMessage());
        // في profile.php، لا نحذف الجلسة حتى لو كان هناك خطأ في قاعدة البيانات
        if ($isProfilePage) {
            return null; // نرجع null لكن لا نحذف الجلسة
        }
        return null;
    }
    
    // إذا كان المستخدم غير موجود أو محذوف من قاعدة البيانات
    if (!$user) {
        // منع حذف الجلسة إذا كان الطلب من profile.php أو attendance.php
        if ($isProtectedPage) {
            $pageName = $isProfilePage ? 'profile.php' : 'attendance.php';
            error_log("Security: User ID {$userId} not found in database - Skipping session destruction in {$pageName}");
            return null;
        }
        
        // إلغاء تسجيل الدخول تلقائياً لأسباب أمنية
        error_log("Security: User ID {$userId} not found in database - Auto logout");
        // حذف الجلسة مباشرة دون استدعاء logout() لتجنب حلقة لا نهائية
        session_unset();
        session_destroy();
        if (isset($_COOKIE['remember_token'])) {
            try {
                if (ensureRememberTokensTable()) {
                    $decoded = base64_decode($_COOKIE['remember_token']);
                    if ($decoded) {
                        $parts = explode(':', $decoded);
                        if (count($parts) === 2) {
                            $tokenUserId = intval($parts[0]);
                            $token = $parts[1];
                            $db->execute(
                                "DELETE FROM remember_tokens WHERE user_id = ? AND token = ?",
                                [$tokenUserId, $token]
                            );
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Security: Error deleting remember token: " . $e->getMessage());
            }
        }
        setcookie('remember_token', '', time() - 3600, '/');
        return null;
    }
    
    // التحقق من حالة المستخدم - إذا كان غير مفعّل
    if (isset($user['status']) && $user['status'] !== 'active') {
        // منع حذف الجلسة إذا كان الطلب من profile.php أو attendance.php
        if ($isProtectedPage) {
            $pageName = $isProfilePage ? 'profile.php' : 'attendance.php';
            error_log("Security: User ID {$userId} status is '{$user['status']}' - Skipping session destruction in {$pageName}");
            return $user; // إرجاع بيانات المستخدم حتى لو كان غير مفعّل
        }
        
        // إلغاء تسجيل الدخول تلقائياً لأسباب أمنية
        error_log("Security: User ID {$userId} status is '{$user['status']}' - Auto logout");
        // حذف الجلسة مباشرة دون استدعاء logout() لتجنب حلقة لا نهائية
        session_unset();
        session_destroy();
        if (isset($_COOKIE['remember_token'])) {
            try {
                if (ensureRememberTokensTable()) {
                    $decoded = base64_decode($_COOKIE['remember_token']);
                    if ($decoded) {
                        $parts = explode(':', $decoded);
                        if (count($parts) === 2) {
                            $tokenUserId = intval($parts[0]);
                            $token = $parts[1];
                            $db->execute(
                                "DELETE FROM remember_tokens WHERE user_id = ? AND token = ?",
                                [$tokenUserId, $token]
                            );
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Security: Error deleting remember token: " . $e->getMessage());
            }
        }
        setcookie('remember_token', '', time() - 3600, '/');
        return null;
    }
    
    // تنظيف جميع القيم المالية
    $financialFields = ['hourly_rate', 'salary', 'basic_salary', 'bonus', 'deductions', 'total_amount'];
    foreach ($financialFields as $field) {
        if (isset($user[$field])) {
            $user[$field] = cleanFinancialValue($user[$field]);
        }
    }
    
    return $user;
}

/**
 * الحصول على معلومات المستخدم حسب ID
 */
function getUserById($userId) {
    $db = db();
    return $db->queryOne("SELECT * FROM users WHERE id = ?", [$userId]);
}

/**
 * الحصول على معلومات المستخدم حسب اسم المستخدم
 */
function getUserByUsername($username) {
    $db = db();
    return $db->queryOne(
        "SELECT * FROM users WHERE username = ?",
        [$username]
    );
}

/**
 * التحقق من كلمة المرور
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * تسجيل الدخول
 */
function login($username, $password, $rememberMe = false) {
    // التحقق من حظر IP
    require_once __DIR__ . '/security.php';
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443')
    );
    if (isIPBlocked($ipAddress)) {
        logLoginAttempt($username, false, 'IP محظور');
        return ['success' => false, 'message' => 'عنوان IP محظور. يرجى الاتصال بالإدارة.'];
    }
    
    $user = getUserByUsername($username);
    
    if (!$user) {
        logLoginAttempt($username, false, 'مستخدم غير موجود');
        return ['success' => false, 'message' => 'اسم المستخدم أو كلمة المرور غير صحيحة'];
    }
    
    if ($user['status'] !== 'active') {
        logLoginAttempt($username, false, 'حساب غير مفعّل');
        return ['success' => false, 'message' => 'الحساب غير مفعّل'];
    }
    
    if (!verifyPassword($password, $user['password_hash'])) {
        logLoginAttempt($username, false, 'كلمة مرور خاطئة');
        return ['success' => false, 'message' => 'اسم المستخدم أو كلمة المرور غير صحيحة'];
    }
    
    // حفظ CSRF token الحالي قبل إعادة توليد الجلسة (للمساعدة في التحقق)
    $currentCsrfToken = $_SESSION['csrf_token'] ?? null;
    
    // تسجيل الدخول
    if (session_status() === PHP_SESSION_ACTIVE) {
        // حفظ token السابق قبل إعادة توليد الجلسة
        if ($currentCsrfToken) {
            $_SESSION['csrf_token_previous'] = $currentCsrfToken;
            $_SESSION['csrf_token_previous_time'] = time();
        }
        session_regenerate_id(true);
    }
    
    // إنشاء token جديد بعد إعادة توليد الجلسة
    generateCSRFToken(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['logged_in'] = true;
    $_SESSION['last_activity'] = time(); // تحديث وقت آخر نشاط
    
    // حفظ الجلسة في قاعدة البيانات
    try {
        if (ensureSessionsTable()) {
            $db = db();
            $sessionId = session_id();
            if (!empty($sessionId)) {
                $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (3600 * 24 * 7); // افتراضياً 7 أيام
                $expiresAt = date('Y-m-d H:i:s', time() + $sessionLifetime);
                $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255); // الحد الأقصى 255 حرف
                
                // حذف جميع الجلسات القديمة للمستخدم أولاً (لتجنب الجلسات المتعددة)
                try {
                    $db->execute("DELETE FROM sessions WHERE user_id = ?", [$user['id']]);
                } catch (Exception $deleteError) {
                    error_log("Error deleting old sessions: " . $deleteError->getMessage());
                }
                
                // إضافة الجلسة الجديدة (واحدة فقط)
                try {
                    $db->execute(
                        "INSERT INTO sessions (user_id, session_id, ip_address, user_agent, expires_at, last_activity) 
                         VALUES (?, ?, ?, ?, ?, NOW())",
                        [$user['id'], $sessionId, $ipAddress, $userAgent, $expiresAt]
                    );
                } catch (Exception $insertError) {
                    error_log("Error inserting session to database: " . $insertError->getMessage());
                    // لا نتوقف عن تسجيل الدخول إذا فشل حفظ الجلسة في قاعدة البيانات
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error in session database operations: " . $e->getMessage());
        // لا نتوقف عن تسجيل الدخول إذا فشل حفظ الجلسة في قاعدة البيانات
    }
    
    // إذا تم تفعيل "تذكرني"، إنشاء cookie
    if ($rememberMe) {
        // التأكد من وجود الجدول
        if (!ensureRememberTokensTable()) {
            // إذا فشل إنشاء الجدول، متابعة بدون "تذكرني"
            error_log("Failed to create remember_tokens table, continuing without remember me");
        } else {
            // توليد token آمن
            $token = bin2hex(random_bytes(32));
            
            // حفظ token في قاعدة البيانات
            $db = db();
            $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days')); // 30 يوم
            
            // حذف أي token موجود لنفس المستخدم
            try {
                $db->execute("DELETE FROM remember_tokens WHERE user_id = ?", [$user['id']]);
            } catch (Exception $e) {
                error_log("Error deleting remember token: " . $e->getMessage());
            }
            
            // إضافة token جديد
            try {
                $db->execute(
                    "INSERT INTO remember_tokens (user_id, token, expires_at, ip_address, user_agent) 
                     VALUES (?, ?, ?, ?, ?)",
                    [
                        $user['id'], 
                        $token, 
                        $expiresAt, 
                        $ipAddress, 
                        $_SERVER['HTTP_USER_AGENT'] ?? ''
                    ]
                );
            } catch (Exception $e) {
                error_log("Error inserting remember token: " . $e->getMessage());
                // متابعة بدون إنشاء cookie
                $rememberMe = false;
            }
            
            // إنشاء cookie آمن
            if ($rememberMe) {
                $cookieValue = base64_encode($user['id'] . ':' . $token);
                setcookie(
                    'remember_token',
                    $cookieValue,
                    [
                        'expires' => time() + (30 * 24 * 60 * 60), // 30 يوم
                        'path' => '/',
                        'domain' => '',
                        'secure' => $isHttps,
                        'httponly' => true, // منع JavaScript من الوصول
                        'samesite' => 'Lax'
                    ]
                );
            }
        }
    }
    
    // تسجيل محاولة ناجحة
    logLoginAttempt($username, true);
    
    // تسجيل سجل التدقيق
    require_once __DIR__ . '/audit_log.php';
    logAudit($user['id'], 'login', 'user', $user['id'], null, [
        'method' => 'password',
        'remember_me' => $rememberMe ? 'yes' : 'no'
    ]);
    
    return ['success' => true, 'user' => $user];
}

/**
 * تنظيف Cache للمستخدم
 * 
 * @param int|null $userId معرف المستخدم (null لحذف Cache المستخدم الحالي)
 */
function clearUserCache($userId = null) {
    if ($userId === null) {
        $userId = $_SESSION['user_id'] ?? null;
    }
    
    if ($userId) {
        // تحميل نظام Cache
        if (!class_exists('Cache')) {
            $cacheFile = __DIR__ . '/cache.php';
            if (file_exists($cacheFile)) {
                require_once $cacheFile;
            }
        }
        
        if (class_exists('Cache')) {
            Cache::forget("user_{$userId}");
        }
    }
}

/**
 * تسجيل الخروج
 */
function logout() {
    // الحصول على user_id قبل حذف الجلسة
    $userId = null;
    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id'])) {
        $userId = $_SESSION['user_id'];
    }
    
    // تنظيف Cache قبل تسجيل الخروج
    if (function_exists('clearUserCache')) {
        clearUserCache();
    }
    
    // حذف جميع الجلسات للمستخدم من قاعدة البيانات (ليس فقط الجلسة الحالية)
    if ($userId) {
        try {
            if (ensureSessionsTable()) {
                $db = db();
                // حذف جميع الجلسات للمستخدم من قاعدة البيانات
                $db->execute(
                    "DELETE FROM sessions WHERE user_id = ?",
                    [$userId]
                );
                error_log("Logout: Deleted all sessions for user_id: {$userId}");
            }
        } catch (Exception $e) {
            error_log("Logout Session Delete Error: " . $e->getMessage());
        }
    }
    
    // حذف جميع remember tokens للمستخدم من قاعدة البيانات (ليس فقط token الحالي)
    if ($userId) {
        try {
            // التأكد من وجود الجدول أولاً
            if (ensureRememberTokensTable()) {
                $db = db();
                // حذف جميع tokens للمستخدم
                $db->execute(
                    "DELETE FROM remember_tokens WHERE user_id = ?",
                    [$userId]
                );
            }
        } catch (Exception $e) {
            error_log("Logout Remember Token Delete Error: " . $e->getMessage());
        }
    }
    
    // حذف remember token من cookie أيضاً (إذا كان موجوداً)
    if (isset($_COOKIE['remember_token'])) {
        try {
            if (ensureRememberTokensTable()) {
                $db = db();
                $decoded = base64_decode($_COOKIE['remember_token']);
                if ($decoded) {
                    $parts = explode(':', $decoded);
                    if (count($parts) === 2) {
                        $tokenUserId = intval($parts[0]);
                        $token = $parts[1];
                        $db->execute(
                            "DELETE FROM remember_tokens WHERE user_id = ? AND token = ?",
                            [$tokenUserId, $token]
                        );
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Logout Remember Token Delete Error: " . $e->getMessage());
        }
    }
    
    // حذف جميع الكوكيز المتعلقة بالجلسة
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443')
    );
    
    // حذف remember_token cookie بجميع الإعدادات الممكنة
    $cookieParams = [
        ['expires' => time() - 3600, 'path' => '/', 'domain' => '', 'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax'],
        ['expires' => time() - 3600, 'path' => '/', 'domain' => null, 'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax'],
        ['expires' => time() - 3600, 'path' => '/', 'domain' => '', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax'],
    ];
    
    foreach ($cookieParams as $params) {
        @setcookie('remember_token', '', $params);
    }
    
    // تسجيل سجل التدقيق قبل حذف الجلسة
    if ($userId) {
        try {
            require_once __DIR__ . '/audit_log.php';
            if (function_exists('logAudit')) {
                logAudit($userId, 'logout', 'user', $userId, null, null);
            }
        } catch (Exception $e) {
            error_log("Logout Audit Log Error: " . $e->getMessage());
        }
    }
    
    // تنظيف جميع متغيرات الجلسة
    if (session_status() === PHP_SESSION_ACTIVE) {
        // حفظ معاملات cookie الجلسة قبل حذفها
        $sessionCookieParams = session_get_cookie_params();
        
        // حذف جميع متغيرات الجلسة
        $_SESSION = [];
        
        // حذف جميع متغيرات الجلسة يدوياً
        if (isset($_SESSION)) {
            foreach ($_SESSION as $key => $value) {
                unset($_SESSION[$key]);
            }
        }
        
        // إلغاء تسجيل جميع متغيرات الجلسة
        @session_unset();
        
        // حذف session cookie بجميع الإعدادات الممكنة
        $sessionName = session_name();
        $sessionCookieOptions = [
            ['expires' => time() - 3600, 'path' => $sessionCookieParams['path'], 'domain' => $sessionCookieParams['domain'], 'secure' => $sessionCookieParams['secure'], 'httponly' => $sessionCookieParams['httponly']],
            ['expires' => time() - 3600, 'path' => '/', 'domain' => '', 'secure' => $isHttps, 'httponly' => true],
            ['expires' => time() - 3600, 'path' => '/', 'domain' => null, 'secure' => $isHttps, 'httponly' => true],
        ];
        
        foreach ($sessionCookieOptions as $options) {
            @setcookie($sessionName, '', $options);
        }
        
        // تدمير الجلسة نهائياً
        @session_destroy();
    }
    
    // التأكد من حذف جميع الكوكيز المتعلقة
    if (isset($_COOKIE)) {
        foreach ($_COOKIE as $name => $value) {
            if (strpos($name, 'PHPSESSID') !== false || strpos($name, 'remember_token') !== false || strpos($name, session_name()) !== false) {
                @setcookie($name, '', time() - 3600, '/');
                @setcookie($name, '', time() - 3600, '/', '');
                @setcookie($name, '', time() - 3600);
            }
        }
    }
}

/**
 * التحقق من الدور
 */
function hasRole($role) {
    if (!isLoggedIn()) {
        return false;
    }

    if (is_array($role)) {
        return hasAnyRole($role);
    }

    if (!is_string($role) || $role === '') {
        return false;
    }
    
    $currentRole = $_SESSION['role'] ?? null;
    if (!is_string($currentRole) || $currentRole === '') {
        return false;
    }
    
    return strtolower($currentRole) === strtolower($role);
}

/**
 * التحقق من أي دور من الأدوار المحددة
 */
function hasAnyRole($roles) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $currentRole = $_SESSION['role'] ?? null;
    if ($currentRole === null) {
        return false;
    }
    
    $normalizedRoles = array_map(static function ($role) {
        return strtolower((string) $role);
    }, array_filter((array) $roles, static function ($role) {
        return $role !== null && $role !== '';
    }));
    
    if (empty($normalizedRoles)) {
        return false;
    }
    
    return in_array(strtolower((string) $currentRole), $normalizedRoles, true);
}

/**
 * التحقق من جميع الأدوار المحددة
 */
function hasAllRoles($roles) {
    if (!isLoggedIn()) {
        return false;
    }
    
    foreach ($roles as $role) {
        if (!hasRole($role)) {
            return false;
        }
    }
    
    return true;
}

/**
 * التحقق من الوصول - إعادة توجيه إذا لم يكن مسجلاً
 */
function requireLogin() {
    // التحقق من أننا في profile.php أو attendance.php - منع إعادة التوجيه
    $isProfilePage = false;
    $isAttendancePage = false;
    
    // الطريقة 1: التحقق من الثوابت (الأكثر موثوقية)
    if (defined('PROFILE_PAGE_ACTIVE') && PROFILE_PAGE_ACTIVE === true) {
        $isProfilePage = true;
    }
    if (defined('ATTENDANCE_PAGE_ACTIVE') && ATTENDANCE_PAGE_ACTIVE === true) {
        $isAttendancePage = true;
    }
    
    // الطريقة 2: التحقق من SCRIPT_NAME و PHP_SELF
    if (!$isProfilePage && !$isAttendancePage) {
        $currentScript = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
        if (strpos($currentScript, 'profile.php') !== false || basename($currentScript) === 'profile.php') {
            $isProfilePage = true;
        } elseif (strpos($currentScript, 'attendance.php') !== false || basename($currentScript) === 'attendance.php') {
            $isAttendancePage = true;
        }
    }
    
    // الطريقة 3: التحقق من REQUEST_URI
    if (!$isProfilePage && !$isAttendancePage) {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($requestUri, 'profile.php') !== false) {
            $isProfilePage = true;
        } elseif (strpos($requestUri, 'attendance.php') !== false) {
            $isAttendancePage = true;
        }
    }
    
    $isProtectedPage = $isProfilePage || $isAttendancePage;
    
    // التأكد من أن الجلسة نشطة قبل أي فحص
    if (session_status() === PHP_SESSION_NONE) {
        if (!headers_sent()) {
            @session_start();
        }
    }
    
    // التحقق من تسجيل الدخول
    if (isLoggedIn()) {
        if (!function_exists('logRequestUsage')) {
            $monitorPath = __DIR__ . '/request_monitor.php';
            if (file_exists($monitorPath)) {
                require_once $monitorPath;
            }
        }
        if (function_exists('logRequestUsage')) {
            logRequestUsage();
        }
        return;
    }

    // إذا لم يكن مسجل دخول، إعادة التوجيه فقط إذا لم نكن في profile.php أو attendance.php
    if (!$isProtectedPage && !isLoggedIn()) {
        // محاولة تحميل path_helper إذا لم يكن محملاً
        if (!function_exists('getRelativeUrl') && file_exists(__DIR__ . '/path_helper.php')) {
            require_once __DIR__ . '/path_helper.php';
        }
        $loginUrl = function_exists('getRelativeUrl') ? getRelativeUrl('index.php') : '/index.php';
        
        // التحقق من إرسال الـ headers أو تضمين header.php
        $headersSent = @headers_sent($file, $line);
        $headerIncluded = defined('HEADER_INCLUDED') && HEADER_INCLUDED;
        
        // إذا تم تضمين header.php أو كانت الـ headers قد أُرسلت، استخدم JavaScript redirect دائماً
        if ($headerIncluded || $headersSent) {
            echo '<script>window.location.href = "' . htmlspecialchars($loginUrl) . '";</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($loginUrl) . '"></noscript>';
            exit;
        }
        
        // محاولة إرسال header فقط إذا لم تكن الـ headers قد أُرسلت ولم يتم تضمين header.php
        if (!@headers_sent()) {
            @header('Location: ' . $loginUrl);
            if (!@headers_sent()) {
                exit;
            }
        }
        
        // إذا فشل header()، استخدم JavaScript redirect
        echo '<script>window.location.href = "' . htmlspecialchars($loginUrl) . '";</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($loginUrl) . '"></noscript>';
        exit;
    }
}

/**
 * التحقق من الدور المحدد
 * يدعم string أو array من الأدوار
 */
function requireRole($role) {
    requireLogin();
    
    // فحص أمني: التأكد من أن المستخدم موجود في قاعدة البيانات
    $currentUser = getCurrentUser();
    if (!$currentUser || !is_array($currentUser) || empty($currentUser)) {
        // المستخدم محذوف أو غير موجود - تم إلغاء تسجيل الدخول تلقائياً من getCurrentUser()
        $loginUrl = function_exists('getRelativeUrl') ? getRelativeUrl('index.php') : '/index.php';
        if (!headers_sent()) {
            header('Location: ' . $loginUrl);
            exit;
        } else {
            echo '<script>window.location.href = "' . htmlspecialchars($loginUrl) . '";</script>';
            exit;
        }
    }
    
    // إذا كان array، استخدم requireAnyRole
    if (is_array($role)) {
        return requireAnyRole($role);
    }
    
    if (!hasRole($role)) {
        $userRole = $_SESSION['role'] ?? 'accountant';
        
        // محاولة تحميل path_helper إذا لم يكن محملاً
        if (!function_exists('getDashboardUrl') && file_exists(__DIR__ . '/path_helper.php')) {
            require_once __DIR__ . '/path_helper.php';
        }
        $dashboardUrl = function_exists('getDashboardUrl') ? getDashboardUrl($userRole) : '/dashboard/' . $userRole . '.php';
        
        // تنظيف شامل للمسار لضمان عدم تكرار الخطأ
        // 1. إزالة أي بروتوكول
        $dashboardUrl = preg_replace('/^https?:\/\//', '', $dashboardUrl);
        $dashboardUrl = preg_replace('/^\/\//', '/', $dashboardUrl);
        
        // 2. التأكد من أن المسار يبدأ بـ /
        if (strpos($dashboardUrl, '/') !== 0) {
            $dashboardUrl = '/' . $dashboardUrl;
        }
        
        // 3. تنظيف المسار (إزالة // المكررة)
        $dashboardUrl = preg_replace('/\/+/', '/', $dashboardUrl);
        
        // 4. إزالة أي hostname إذا كان موجوداً
        if (preg_match('/^\/[^\/]+\.[a-z]/i', $dashboardUrl)) {
            $parts = explode('/', $dashboardUrl);
            $dashboardIndex = array_search('dashboard', $parts);
            if ($dashboardIndex !== false) {
                $dashboardUrl = '/' . implode('/', array_slice($parts, $dashboardIndex));
            } else {
                $dashboardUrl = '/dashboard/' . $userRole . '.php';
            }
        }
        
        // 5. التحقق النهائي: إذا كان المسار لا يحتوي على 'dashboard'، أضفه
        if (strpos($dashboardUrl, '/dashboard') === false) {
            $dashboardUrl = '/dashboard/' . $userRole . '.php';
        }
        
        // 6. التأكد من أن المسار لا يحتوي على http:// أو https:// مرة أخرى
        if (strpos($dashboardUrl, 'http://') === 0 || strpos($dashboardUrl, 'https://') === 0) {
            $parsed = parse_url($dashboardUrl);
            $dashboardUrl = $parsed['path'] ?? '/dashboard/' . $userRole . '.php';
        }
        
        // 7. تنظيف نهائي
        $dashboardUrl = trim($dashboardUrl);
        if (empty($dashboardUrl) || $dashboardUrl === '/') {
            $dashboardUrl = '/dashboard/' . $userRole . '.php';
        }
        
        // التحقق من إرسال الـ headers أو تضمين header.php
        $headersSent = @headers_sent($file, $line);
        $headerIncluded = defined('HEADER_INCLUDED') && HEADER_INCLUDED;
        
        // إذا تم تضمين header.php أو كانت الـ headers قد أُرسلت، استخدم JavaScript redirect دائماً
        if ($headerIncluded || $headersSent) {
            echo '<script>window.location.href = "' . htmlspecialchars($dashboardUrl) . '";</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($dashboardUrl) . '"></noscript>';
            exit;
        }
        
        // محاولة إرسال header فقط إذا لم تكن الـ headers قد أُرسلت ولم يتم تضمين header.php
        if (!@headers_sent()) {
            @header('Location: ' . $dashboardUrl);
            if (!@headers_sent()) {
                exit;
            }
        }
        
        // إذا فشل header()، استخدم JavaScript redirect
        echo '<script>window.location.href = "' . htmlspecialchars($dashboardUrl) . '";</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($dashboardUrl) . '"></noscript>';
        exit;
    }
}

/**
 * التحقق من أي دور من الأدوار المحددة
 */
function requireAnyRole($roles) {
    requireLogin();
    
    if (!hasAnyRole($roles)) {
        $userRole = $_SESSION['role'] ?? 'accountant';
        
        // محاولة تحميل path_helper إذا لم يكن محملاً
        if (!function_exists('getDashboardUrl') && file_exists(__DIR__ . '/path_helper.php')) {
            require_once __DIR__ . '/path_helper.php';
        }
        $dashboardUrl = function_exists('getDashboardUrl') ? getDashboardUrl($userRole) : '/dashboard/' . $userRole . '.php';
        
        // تنظيف شامل للمسار لضمان عدم تكرار الخطأ
        // 1. إزالة أي بروتوكول
        $dashboardUrl = preg_replace('/^https?:\/\//', '', $dashboardUrl);
        $dashboardUrl = preg_replace('/^\/\//', '/', $dashboardUrl);
        
        // 2. التأكد من أن المسار يبدأ بـ /
        if (strpos($dashboardUrl, '/') !== 0) {
            $dashboardUrl = '/' . $dashboardUrl;
        }
        
        // 3. تنظيف المسار (إزالة // المكررة)
        $dashboardUrl = preg_replace('/\/+/', '/', $dashboardUrl);
        
        // 4. إزالة أي hostname إذا كان موجوداً
        if (preg_match('/^\/[^\/]+\.[a-z]/i', $dashboardUrl)) {
            $parts = explode('/', $dashboardUrl);
            $dashboardIndex = array_search('dashboard', $parts);
            if ($dashboardIndex !== false) {
                $dashboardUrl = '/' . implode('/', array_slice($parts, $dashboardIndex));
            } else {
                $dashboardUrl = '/dashboard/' . $userRole . '.php';
            }
        }
        
        // 5. التحقق النهائي: إذا كان المسار لا يحتوي على 'dashboard'، أضفه
        if (strpos($dashboardUrl, '/dashboard') === false) {
            $dashboardUrl = '/dashboard/' . $userRole . '.php';
        }
        
        // 6. التأكد من أن المسار لا يحتوي على http:// أو https:// مرة أخرى
        if (strpos($dashboardUrl, 'http://') === 0 || strpos($dashboardUrl, 'https://') === 0) {
            $parsed = parse_url($dashboardUrl);
            $dashboardUrl = $parsed['path'] ?? '/dashboard/' . $userRole . '.php';
        }
        
        // 7. تنظيف نهائي
        $dashboardUrl = trim($dashboardUrl);
        if (empty($dashboardUrl) || $dashboardUrl === '/') {
            $dashboardUrl = '/dashboard/' . $userRole . '.php';
        }
        
        // التحقق من إرسال الـ headers أو تضمين header.php
        $headersSent = @headers_sent($file, $line);
        $headerIncluded = defined('HEADER_INCLUDED') && HEADER_INCLUDED;
        
        // إذا تم تضمين header.php أو كانت الـ headers قد أُرسلت، استخدم JavaScript redirect دائماً
        if ($headerIncluded || $headersSent) {
            echo '<script>window.location.href = "' . htmlspecialchars($dashboardUrl) . '";</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($dashboardUrl) . '"></noscript>';
            exit;
        }
        
        // محاولة إرسال header فقط إذا لم تكن الـ headers قد أُرسلت ولم يتم تضمين header.php
        if (!@headers_sent()) {
            @header('Location: ' . $dashboardUrl);
            if (!@headers_sent()) {
                exit;
            }
        }
        
        // إذا فشل header()، استخدم JavaScript redirect
        echo '<script>window.location.href = "' . htmlspecialchars($dashboardUrl) . '";</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($dashboardUrl) . '"></noscript>';
        exit;
    }
}

/**
 * إنشاء كلمة مرور مشفرة
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

/**
 * إنشاء رمز CSRF
 * محسّن لدعم إعادة توليد الجلسة - يحفظ token السابق مؤقتاً
 */
function generateCSRFToken($forceRefresh = false) {
    // التأكد من أن الجلسة نشطة
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (!headers_sent()) {
            @session_start();
        }
    }
    
    // التأكد من وجود $_SESSION
    if (!isset($_SESSION) || !is_array($_SESSION)) {
        return '';
    }
    
    // حفظ token الحالي كـ previous قبل إنشاء واحد جديد
    if ($forceRefresh && isset($_SESSION['csrf_token']) && !empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token_previous'] = $_SESSION['csrf_token'];
        // تنظيف token السابق بعد 5 دقائق (وقت كافٍ لإكمال تسجيل الدخول)
        if (!isset($_SESSION['csrf_token_previous_time'])) {
            $_SESSION['csrf_token_previous_time'] = time();
        }
    }
    
    if ($forceRefresh || !isset($_SESSION['csrf_token']) || empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    
    // تنظيف token السابق القديم (أكثر من 5 دقائق)
    if (isset($_SESSION['csrf_token_previous_time']) && 
        (time() - $_SESSION['csrf_token_previous_time']) > 300) {
        unset($_SESSION['csrf_token_previous']);
        unset($_SESSION['csrf_token_previous_time']);
    }
    
    return $_SESSION['csrf_token'] ?? '';
}

/**
 * التحقق من رمز CSRF
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

