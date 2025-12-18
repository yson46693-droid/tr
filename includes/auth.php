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
            // تعطيل التسجيل لتقليل الضغط على السيرفر
            // error_log("isLoggedIn() FALSE: Session not started and headers already sent");
            return false;
        }
    }
    
    // التأكد من وجود $_SESSION
    if (!isset($_SESSION) || !is_array($_SESSION)) {
        // تعطيل التسجيل لتقليل الضغط على السيرفر
        // error_log("isLoggedIn() FALSE: \$_SESSION not set or not array");
        return false;
    }
    
    // === التحقق الإجباري من الجلسة في قاعدة البيانات (المصدر الوحيد الموثوق) ===
    // لا يمكن الاعتماد على $_SESSION['logged_in'] فقط - يجب التحقق من قاعدة البيانات دائماً
    
    // إذا كانت الجلسة نشطة لكن user_id مفقود، نحاول استعادته من قاعدة البيانات
    if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && (!isset($_SESSION['user_id']) || empty($_SESSION['user_id']))) {
        $sessionId = session_id();
        if (!empty($sessionId)) {
            try {
                if (ensureSessionsTable()) {
                    $db = db();
                    // البحث عن الجلسة في قاعدة البيانات باستخدام session_id فقط (بدون شرط expires_at)
                    $sessionRecord = $db->queryOne(
                        "SELECT * FROM sessions WHERE session_id = ?",
                        [$sessionId]
                    );
                    
                    // إذا وُجدت الجلسة لكنها منتهية الصلاحية، نمددها
                    if ($sessionRecord && strtotime($sessionRecord['expires_at']) < time()) {
                        $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (3600 * 24 * 7);
                        $newExpiresAt = date('Y-m-d H:i:s', time() + $sessionLifetime);
                        $db->execute(
                            "UPDATE sessions SET expires_at = ?, last_activity = NOW() WHERE id = ?",
                            [$newExpiresAt, $sessionRecord['id']]
                        );
                        $sessionRecord['expires_at'] = $newExpiresAt;
                    }
                    
                    if ($sessionRecord && !empty($sessionRecord['user_id'])) {
                        // استعادة user_id من قاعدة البيانات
                        $_SESSION['user_id'] = $sessionRecord['user_id'];
                        
                        // محاولة استعادة بيانات المستخدم الأخرى من قاعدة البيانات
                        $userRecord = $db->queryOne(
                            "SELECT id, username, role FROM users WHERE id = ? AND status = 'active'",
                            [$sessionRecord['user_id']]
                        );
                        
                        if ($userRecord) {
                            $_SESSION['username'] = $userRecord['username'];
                            $_SESSION['role'] = $userRecord['role'];
                            $_SESSION['logged_in'] = true;
                            $_SESSION['last_activity'] = time();
                            
                            error_log("isLoggedIn() RESTORED: Restored missing user_id: {$sessionRecord['user_id']} from database session");
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("isLoggedIn() ERROR: Failed to restore user_id from database: " . $e->getMessage());
            }
        }
    }
    
    if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
        $userId = $_SESSION['user_id'];
        $sessionId = session_id();
        
        // تسجيل معلومات الجلسة للمساعدة في التشخيص (معطل لتقليل سجلات الأخطاء)
        // error_log("isLoggedIn() CHECK: user_id: {$userId}, session_id: " . substr($sessionId, 0, 20) . "...");
        
        // التحقق الإجباري من قاعدة البيانات - إذا لم توجد جلسة، الجلسة غير صالحة
        $sessionValidInDB = false;
        $sessionRecord = null; // تخزين سجل الجلسة خارج try block للوصول إليه لاحقاً
        try {
            if (ensureSessionsTable()) {
                $db = db();
                
                // البحث عن الجلسة في قاعدة البيانات - يجب أن تطابق session_id تماماً
                // لا نحاول "إصلاح" الجلسة لأن ذلك سيسمح للجهاز القديم بإعادة إنشاء الجلسة بعد حذفها
                
                // البحث أولاً بدون شرط expires_at للتحقق من وجود الجلسة
                $sessionRecord = $db->queryOne(
                    "SELECT * FROM sessions WHERE user_id = ? AND session_id = ?",
                    [$userId, $sessionId]
                );
                
                // إذا وُجدت الجلسة لكنها منتهية الصلاحية، نمددها دائماً (لا نهي الجلسة أبداً)
                if ($sessionRecord && strtotime($sessionRecord['expires_at']) < time()) {
                    $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (3600 * 24 * 7);
                    $newExpiresAt = date('Y-m-d H:i:s', time() + $sessionLifetime);
                    $db->execute(
                        "UPDATE sessions SET expires_at = ?, last_activity = NOW() WHERE id = ?",
                        [$newExpiresAt, $sessionRecord['id']]
                    );
                    $sessionRecord['expires_at'] = $newExpiresAt;
                    // تعطيل التسجيل لتقليل الضغط على السيرفر
                    // error_log("isLoggedIn() EXTENDED: Session expired but extended for user_id: {$userId}");
                }
                
                // البحث مرة أخرى بدون شرط expires_at - لا نهي الجلسة أبداً بناءً على الخمول
                // إذا كانت الجلسة موجودة، نعتبرها صالحة دائماً
                if ($sessionRecord) {
                    // تحديث expires_at دائماً لضمان بقاء الجلسة صالحة
                    $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (3600 * 24 * 7);
                    $newExpiresAt = date('Y-m-d H:i:s', time() + $sessionLifetime);
                    $db->execute(
                        "UPDATE sessions SET expires_at = ?, last_activity = NOW() WHERE id = ?",
                        [$newExpiresAt, $sessionRecord['id']]
                    );
                    $sessionRecord['expires_at'] = $newExpiresAt;
                }
                
                // === تحقق أمني: إذا لم توجد الجلسة بنفس session_id، الجلسة غير صالحة ===
                // لا نحاول البحث عن جلسات أخرى أو إصلاح الجلسة لأن ذلك سيسمح للجهاز القديم
                // بإعادة إنشاء الجلسة بعد حذفها عند تسجيل الدخول من جهاز جديد
                
                // إذا لم توجد الجلسة في قاعدة البيانات، الجلسة غير صالحة - حذفها فوراً
                if (!$sessionRecord) {
                    // تعطيل التسجيلات التفصيلية لتقليل الضغط على السيرفر
                    // الاحتفاظ فقط بتسجيل واحد مختصر للأخطاء الحرجة
                    // error_log("isLoggedIn() SECURITY: Session not found in database for user_id: {$userId}");
                    
                    // === حذف فوري للجلسة PHP والـ cookies ===
                    // هذا يمنع الجهاز القديم من الوصول حتى لو كان لديه session cookie
                    $_SESSION = [];
                    @session_unset();
                    @session_destroy();
                    
                    // حذف session cookie من المتصفح
                    $sessionName = session_name();
                    if (isset($_COOKIE[$sessionName])) {
                        // حذف cookie من جميع المسارات
                        setcookie($sessionName, '', time() - 3600, '/');
                        setcookie($sessionName, '', time() - 3600, '/', '');
                        unset($_COOKIE[$sessionName]);
                    }
                    
                    // تعطيل التسجيل لتقليل الضغط على السيرفر
                    // error_log("isLoggedIn() ACCESS DENIED: Old device session invalidated - user must login again");
                    return false;
                }
                
                // الجلسة موجودة في قاعدة البيانات - صالحة
                $sessionValidInDB = true;
                
                // === تحديث last_activity و expires_at في قاعدة البيانات ===
                // نحدث expires_at دائماً لضمان بقاء الجلسة صالحة - لا نهي الجلسة أبداً بناءً على الخمول
                // تحديث دائماً لضمان أن الجلسة لا تنتهي أبداً
                $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (3600 * 24 * 7);
                $currentTime = time();
                
                // تحديث expires_at دائماً إلى 7 أيام من الآن - لا نهي الجلسة أبداً
                $newExpiresAt = date('Y-m-d H:i:s', $currentTime + $sessionLifetime);
                $db->execute(
                    "UPDATE sessions SET last_activity = NOW(), expires_at = ? WHERE id = ?",
                    [$newExpiresAt, $sessionRecord['id']]
                );
            } else {
                // فشل في إنشاء جدول الجلسات - خطأ في قاعدة البيانات
                // تعطيل التسجيل الروتيني - الاحتفاظ فقط بالأخطاء الحرجة
                // error_log("isLoggedIn() ERROR: Failed to ensure sessions table exists");
                $sessionValidInDB = false;
            }
        } catch (Exception $e) {
            // تسجيل الأخطاء الحرجة فقط (أخطاء قاعدة البيانات)
            // error_log("Error checking session in database: " . $e->getMessage());
            $sessionValidInDB = false;
        }
        
        // === إذا لم تكن الجلسة صالحة في قاعدة البيانات، حذفها وإرجاع false ===
        // هذا هو التحقق الأمني الرئيسي - يجب أن تكون الجلسة موجودة في قاعدة البيانات
        if (!$sessionValidInDB) {
            // في حالة الخطأ في قاعدة البيانات أو عدم وجود الجلسة، نعتبر الجلسة غير صالحة للأمان
            $_SESSION = [];
            @session_unset();
            @session_destroy();
            
            // حذف cookies
            if (isset($_COOKIE[session_name()])) {
                setcookie(session_name(), '', time() - 3600, '/');
            }
            
            // تعطيل التسجيل لتقليل الضغط على السيرفر
            // error_log("isLoggedIn() SECURITY FAIL: Session not found in database - Access denied");
            return false;
        }
        
        // === الجلسة موجودة في قاعدة البيانات - المتابعة ===
        // التحقق من user_id (يجب أن يكون موجوداً لأننا وصلنا هنا)
        if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
            // إذا كانت الجلسة صالحة في قاعدة البيانات لكن user_id مفقود من PHP session
            // نحاول استعادته من قاعدة البيانات
            if ($sessionValidInDB && isset($sessionRecord) && !empty($sessionRecord['user_id'])) {
                // استعادة user_id من قاعدة البيانات
                $_SESSION['user_id'] = $sessionRecord['user_id'];
                
                // محاولة استعادة بيانات المستخدم الأخرى من قاعدة البيانات
                try {
                    $userRecord = $db->queryOne(
                        "SELECT id, username, role FROM users WHERE id = ? AND status = 'active'",
                        [$sessionRecord['user_id']]
                    );
                    
                    if ($userRecord) {
                        $_SESSION['username'] = $userRecord['username'];
                        $_SESSION['role'] = $userRecord['role'];
                        $_SESSION['logged_in'] = true;
                        $_SESSION['last_activity'] = time();
                        
                        // تسجيل استعادة الجلسة
                        error_log("isLoggedIn() RESTORED: Restored session data for user_id: {$sessionRecord['user_id']} from database");
                    } else {
                        // المستخدم غير موجود أو غير مفعّل - حذف الجلسة
                        $_SESSION = [];
                        @session_unset();
                        @session_destroy();
                        return false;
                    }
                } catch (Exception $e) {
                    error_log("isLoggedIn() ERROR: Failed to restore user data: " . $e->getMessage());
                    // إذا فشل استعادة البيانات، نعتبر الجلسة غير صالحة
                    if (!$isProtectedPage) {
                        session_unset();
                        session_destroy();
                    }
                    return false;
                }
            } else {
                // لا توجد جلسة صالحة في قاعدة البيانات أو لا يوجد user_id
                // تعطيل التسجيل لتقليل الضغط على السيرفر
                // error_log("isLoggedIn() FALSE: user_id not set or empty");
                
                // إذا لم يكن هناك user_id، إلغاء الجلسة فقط إذا لم نكن في profile.php أو attendance.php
                if (!$isProtectedPage) {
                    session_unset();
                    session_destroy();
                }
                return false;
            }
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
        // تسجيل نجاح التحقق (معطل لتقليل سجلات الأخطاء)
        // error_log("isLoggedIn() TRUE: User ID " . ($_SESSION['user_id'] ?? 'unknown') . " | Duration: {$duration}ms | Script: {$scriptName}");
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
            // تعطيل التسجيل لتقليل الضغط على السيرفر
            // error_log("isLoggedIn() FALSE: Remember token check failed");
        } else {
            // تسجيل نجاح التحقق (معطل لتقليل سجلات الأخطاء)
            // error_log("isLoggedIn() TRUE: Remember token valid | Duration: {$duration}ms | Script: {$scriptName}");
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
    // تعطيل التسجيل لتقليل الضغط على السيرفر
    // error_log("isLoggedIn() FALSE: No logged_in session and no remember_token");
    
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
 * تنظيف الجلسات القديمة جداً من قاعدة البيانات
 * يمكن استدعاء هذه الدالة من cron job لتنظيف الجلسات القديمة جداً فقط
 * 
 * ملاحظة: هذه الدالة تحذف فقط الجلسات التي لم يتم تحديثها لأكثر من 30 يوم
 * ولا تحذف الجلسات النشطة أو التي انتهت مؤخراً
 * 
 * @param int $days عدد الأيام - الجلسات التي لم يتم تحديثها لأكثر من هذا العدد سيتم حذفها (افتراضي 30 يوم)
 * @return array إحصائيات عملية التنظيف
 */
function cleanupExpiredSessions($days = 30) {
    try {
        if (!ensureSessionsTable()) {
            return [
                'success' => false,
                'deleted' => 0,
                'message' => 'جدول sessions غير موجود'
            ];
        }
        
        $db = db();
        
        // حذف فقط الجلسات التي لم يتم تحديثها لأكثر من 30 يوم (أو العدد المحدد)
        // هذا يضمن عدم حذف الجلسات النشطة أو التي انتهت مؤخراً
        // نستخدم last_activity بدلاً من expires_at لأن expires_at يتم تحديثه دائماً
        $result = $db->execute(
            "DELETE FROM sessions WHERE last_activity < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );
        
        $deletedCount = intval($result['affected_rows'] ?? 0);
        
        return [
            'success' => true,
            'deleted' => $deletedCount,
            'message' => "تم حذف {$deletedCount} جلسة قديمة جداً (أكثر من {$days} يوم بدون نشاط)"
        ];
    } catch (Exception $e) {
        error_log("Error cleaning up old sessions: " . $e->getMessage());
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
        
        // === إرسال session cookie الجديد فوراً بعد regenerate_id ===
        // هذا ضروري لضمان أن المتصفح يحصل على session_id الجديد
        if (!headers_sent()) {
            $isHttps = (
                (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443')
            );
            $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (3600 * 24 * 7);
            $sessionName = session_name();
            $newSessionId = session_id();
            
            // إرسال cookie الجلسة مع الإعدادات الصحيحة
            setcookie($sessionName, $newSessionId, [
                'expires' => time() + $sessionLifetime,
                'path' => '/',
                'domain' => '',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => $isHttps ? 'None' : 'Lax',
            ]);
            
            // تحديث $_COOKIE أيضاً لضمان أن الكود اللاحق يرى session_id الجديد
            $_COOKIE[$sessionName] = $newSessionId;
        }
        
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
                        // تعطيل التسجيل الروتيني - الاحتفاظ فقط بالأخطاء الحرجة
                        // error_log("Error deleting old sessions in checkRememberToken: " . $deleteError->getMessage());
                    }
                    
                    // إضافة الجلسة الجديدة (واحدة فقط)
                    try {
                        $db->execute(
                            "INSERT INTO sessions (user_id, session_id, ip_address, user_agent, expires_at, last_activity) 
                             VALUES (?, ?, ?, ?, ?, NOW())",
                            [$tokenRecord['user_id'], $sessionId, $ipAddress, $userAgent, $expiresAt]
                        );
                        
                        // التحقق من أن الجلسة تم حفظها
                        $verifySession = $db->queryOne(
                            "SELECT * FROM sessions WHERE user_id = ? AND session_id = ?",
                            [$tokenRecord['user_id'], $sessionId]
                        );
                        
                        if (!$verifySession) {
                            // إعادة المحاولة مرة واحدة
                            usleep(50000); // 0.05 ثانية
                            $db->execute(
                                "INSERT INTO sessions (user_id, session_id, ip_address, user_agent, expires_at, last_activity) 
                                 VALUES (?, ?, ?, ?, ?, NOW())
                                 ON DUPLICATE KEY UPDATE last_activity = NOW(), expires_at = ?",
                                [$tokenRecord['user_id'], $sessionId, $ipAddress, $userAgent, $expiresAt, $expiresAt]
                            );
                        }
                    } catch (Exception $insertError) {
                        // تعطيل التسجيل الروتيني - الاحتفاظ فقط بالأخطاء الحرجة
                        // error_log("Error inserting session in checkRememberToken: " . $insertError->getMessage());
                        // لا نتوقف عن تسجيل الدخول إذا فشل حفظ الجلسة في قاعدة البيانات
                    }
                }
            }
        } catch (Exception $e) {
            // تعطيل التسجيل الروتيني - الاحتفاظ فقط بالأخطاء الحرجة
            // error_log("Error in session database operations in checkRememberToken: " . $e->getMessage());
            // لا نتوقف عن تسجيل الدخول إذا فشل حفظ الجلسة في قاعدة البيانات
        }
        
        // === كتابة الجلسة فوراً لضمان حفظها ===
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
            // إعادة فتح الجلسة للاستمرار في استخدامها
            session_start();
            // التأكد من أن البيانات لا تزال موجودة بعد إعادة الفتح
            if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['user_id'])) {
                $_SESSION['user_id'] = $tokenRecord['user_id'];
                $_SESSION['username'] = $tokenRecord['username'];
                $_SESSION['role'] = $tokenRecord['role'];
                $_SESSION['logged_in'] = true;
                $_SESSION['last_activity'] = time();
            }
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
            // تعطيل التسجيل لتقليل الضغط على السيرفر
            // error_log("getCurrentUser() NULL: Session not started and headers sent");
            return null;
        }
    }
    
    // التأكد من وجود $_SESSION
    if (!isset($_SESSION) || !is_array($_SESSION)) {
        // تعطيل التسجيل لتقليل الضغط على السيرفر
        // error_log("getCurrentUser() NULL: \$_SESSION not set or not array");
        return null;
    }
    
    if (!isLoggedIn()) {
        // تعطيل التسجيل لتقليل الضغط على السيرفر
        // error_log("getCurrentUser() NULL: isLoggedIn() returned false");
        return null;
    }
    
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) {
        // إذا كان isLoggedIn() يعيد true لكن user_id مفقود، نحاول استعادته من قاعدة البيانات
        // هذا يحدث أحياناً بسبب مشاكل في الجلسة
        $sessionId = session_id();
        if (!empty($sessionId)) {
            try {
                if (ensureSessionsTable()) {
                    $db = db();
                    // البحث عن الجلسة في قاعدة البيانات باستخدام session_id فقط
                    $sessionRecord = $db->queryOne(
                        "SELECT * FROM sessions WHERE session_id = ?",
                        [$sessionId]
                    );
                    
                    // إذا وُجدت الجلسة لكنها منتهية الصلاحية، نمددها
                    if ($sessionRecord && strtotime($sessionRecord['expires_at']) < time()) {
                        $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (3600 * 24 * 7);
                        $newExpiresAt = date('Y-m-d H:i:s', time() + $sessionLifetime);
                        $db->execute(
                            "UPDATE sessions SET expires_at = ?, last_activity = NOW() WHERE id = ?",
                            [$newExpiresAt, $sessionRecord['id']]
                        );
                        $sessionRecord['expires_at'] = $newExpiresAt;
                    }
                    
                    if ($sessionRecord && !empty($sessionRecord['user_id'])) {
                        // استعادة user_id من قاعدة البيانات
                        $_SESSION['user_id'] = $sessionRecord['user_id'];
                        
                        // محاولة استعادة بيانات المستخدم الأخرى من قاعدة البيانات
                        $userRecord = $db->queryOne(
                            "SELECT id, username, role FROM users WHERE id = ? AND status = 'active'",
                            [$sessionRecord['user_id']]
                        );
                        
                        if ($userRecord) {
                            $_SESSION['username'] = $userRecord['username'];
                            $_SESSION['role'] = $userRecord['role'];
                            $_SESSION['logged_in'] = true;
                            $_SESSION['last_activity'] = time();
                            
                            error_log("getCurrentUser() RESTORED: Restored missing user_id: {$sessionRecord['user_id']} from database session");
                            $userId = $sessionRecord['user_id'];
                        } else {
                            // المستخدم غير موجود أو غير مفعّل
                            return null;
                        }
                    } else {
                        // لا توجد جلسة صالحة في قاعدة البيانات
                        return null;
                    }
                } else {
                    // فشل في إنشاء جدول الجلسات
                    return null;
                }
            } catch (Exception $e) {
                error_log("getCurrentUser() ERROR: Failed to restore user_id from database: " . $e->getMessage());
                return null;
            }
        } else {
            // لا يوجد session_id
            return null;
        }
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
            // تم تعطيل التسجيل لتقليل استهلاك الموارد
            // error_log("getCurrentUser() - Loading from database (cache miss) for user ID {$userId} | Script: {$scriptName}");
            return getCurrentUserFromDatabase($userId);
        }, 300); // 5 دقائق
        
        // إذا كان المستخدم null (محذوف)، احذف من Cache
        if ($user === null) {
            Cache::forget($cacheKey);
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            // تم تعطيل التسجيل لتقليل استهلاك الموارد
            // error_log("getCurrentUser() NULL: User not found in database (from cache) for user ID {$userId} | Duration: {$duration}ms | Script: {$scriptName}");
            return null;
        }
        
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        // تم تعطيل التسجيل لتقليل استهلاك الموارد
        // error_log("getCurrentUser() SUCCESS (from cache): User ID {$userId}, Role: " . ($user['role'] ?? 'NOT_SET') . " | Duration: {$duration}ms | Script: {$scriptName}");
        return $user;
    }
    
    // إذا لم يكن Cache متاحاً، استخدم الطريقة القديمة
    // تم تعطيل التسجيل لتقليل استهلاك الموارد
    // error_log("getCurrentUser() - Loading from database (no cache) for user ID {$userId} | Script: {$scriptName}");
    $user = getCurrentUserFromDatabase($userId);
    
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    if ($user) {
        // تم تعطيل التسجيل لتقليل استهلاك الموارد
        // error_log("getCurrentUser() SUCCESS: User ID {$userId}, Role: " . ($user['role'] ?? 'NOT_SET') . ", Status: " . ($user['status'] ?? 'NOT_SET') . " | Duration: {$duration}ms | Script: {$scriptName}");
    } else {
        // تم تعطيل التسجيل لتقليل استهلاك الموارد
        // error_log("getCurrentUser() NULL: getCurrentUserFromDatabase() returned null for user ID {$userId} | Duration: {$duration}ms | Script: {$scriptName}");
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
    // التحقق من الملف الذي يستدعي هذه الدالة - منع حذف الجلسة في profile.php و attendance.php و sales.php و notifications API
    // استخدام طرق متعددة للتحقق لضمان العمل على جميع الأجهزة (Windows, Android, iOS)
    $isProfilePage = false;
    $isAttendancePage = false;
    $isSalesPage = false;
    $isNotificationsAPI = false;
    
    // الطريقة 1: التحقق من الثوابت (الأكثر موثوقية)
    if (defined('PROFILE_PAGE_ACTIVE') && PROFILE_PAGE_ACTIVE === true) {
        $isProfilePage = true;
    }
    if (defined('ATTENDANCE_PAGE_ACTIVE') && ATTENDANCE_PAGE_ACTIVE === true) {
        $isAttendancePage = true;
    }
    if (defined('SALES_PAGE_ACTIVE') && SALES_PAGE_ACTIVE === true) {
        $isSalesPage = true;
    }
    if (defined('NOTIFICATIONS_API_ACTIVE') && NOTIFICATIONS_API_ACTIVE === true) {
        $isNotificationsAPI = true;
    }
    
    // الطريقة 2: التحقق من SCRIPT_NAME و PHP_SELF
    if (!$isProfilePage && !$isAttendancePage && !$isSalesPage && !$isNotificationsAPI) {
        $currentScript = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
        if (strpos($currentScript, 'profile.php') !== false || basename($currentScript) === 'profile.php') {
            $isProfilePage = true;
        } elseif (strpos($currentScript, 'attendance.php') !== false || basename($currentScript) === 'attendance.php') {
            $isAttendancePage = true;
        } elseif (strpos($currentScript, 'sales.php') !== false || basename($currentScript) === 'sales.php' || strpos($currentScript, 'dashboard/sales.php') !== false) {
            $isSalesPage = true;
        } elseif (strpos($currentScript, 'notifications.php') !== false || basename($currentScript) === 'notifications.php') {
            $isNotificationsAPI = true;
        }
    }
    
    // الطريقة 3: استخدام debug_backtrace كبديل
    if (!$isProfilePage && !$isAttendancePage && !$isSalesPage && !$isNotificationsAPI) {
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
                } elseif ($fileName === 'sales.php' || strpos($trace['file'], 'sales.php') !== false || strpos($trace['file'], 'dashboard/sales.php') !== false) {
                    $isSalesPage = true;
                    break;
                } elseif ($fileName === 'notifications.php' || strpos($trace['file'], 'notifications.php') !== false) {
                    $isNotificationsAPI = true;
                    break;
                }
            }
        }
    }
    
    // الطريقة 4: التحقق من REQUEST_URI
    if (!$isProfilePage && !$isAttendancePage && !$isSalesPage && !$isNotificationsAPI) {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($requestUri, 'profile.php') !== false) {
            $isProfilePage = true;
        } elseif (strpos($requestUri, 'attendance.php') !== false) {
            $isAttendancePage = true;
        } elseif (strpos($requestUri, 'sales.php') !== false || strpos($requestUri, 'dashboard/sales') !== false) {
            $isSalesPage = true;
        } elseif (strpos($requestUri, 'notifications.php') !== false || strpos($requestUri, '/api/notifications') !== false) {
            $isNotificationsAPI = true;
        }
    }
    
    // تحديد إذا كان الصفحة محمية (profile أو attendance أو sales أو notifications API)
    $isProtectedPage = $isProfilePage || $isAttendancePage || $isSalesPage || $isNotificationsAPI;
    
    // جلب جميع بيانات المستخدم من قاعدة البيانات
    try {
        $db = db();
        $user = $db->queryOne("SELECT * FROM users WHERE id = ?", [$userId]);
    } catch (Exception $e) {
        // تعطيل التسجيل الروتيني - الاحتفاظ فقط بالأخطاء الحرجة
        // error_log("getCurrentUserFromDatabase - Database error for user ID {$userId}: " . $e->getMessage());
        // في profile.php، لا نحذف الجلسة حتى لو كان هناك خطأ في قاعدة البيانات
        if ($isProfilePage) {
            return null; // نرجع null لكن لا نحذف الجلسة
        }
        return null;
    }
    
    // إذا كان المستخدم غير موجود أو محذوف من قاعدة البيانات
    if (!$user) {
        // منع حذف الجلسة إذا كان الطلب من profile.php أو attendance.php أو sales.php
        if ($isProtectedPage) {
            $pageName = $isProfilePage ? 'profile.php' : ($isAttendancePage ? 'attendance.php' : ($isSalesPage ? 'sales.php' : 'notifications.php'));
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
        // منع حذف الجلسة إذا كان الطلب من profile.php أو attendance.php أو sales.php
        if ($isProtectedPage) {
            $pageName = $isProfilePage ? 'profile.php' : ($isAttendancePage ? 'attendance.php' : ($isSalesPage ? 'sales.php' : 'notifications.php'));
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
 * التحقق من حالة وضع الصيانة
 */
function isMaintenanceMode() {
    // قراءة من constant في config.php
    return defined('MAINTENANCE_MODE') && MAINTENANCE_MODE === true;
}

/**
 * التحقق إذا كان المستخدم الحالي مطور
 */
function isDeveloper() {
    if (!isLoggedIn()) {
        return false;
    }
    
    $currentUser = getCurrentUser();
    if (!$currentUser) {
        return false;
    }
    
    return isset($currentUser['role']) && strtolower($currentUser['role']) === 'developer';
}

/**
 * التحقق من وضع الصيانة ورفض الوصول للمستخدمين العاديين
 * المطورون يستطيعون الوصول دائماً حتى في وضع الصيانة
 */
function checkMaintenanceMode() {
    // إذا كان وضع الصيانة معطلاً، السماح بالوصول
    if (!isMaintenanceMode()) {
        return ['allowed' => true];
    }
    
    // إذا كان المستخدم مطوراً، السماح بالوصول دائماً
    if (isDeveloper()) {
        return ['allowed' => true];
    }
    
    // في حالة وضع الصيانة والمستخدم ليس مطوراً، رفض الوصول
    return [
        'allowed' => false,
        'message' => 'التطبيق تحت الصيانة في الوقت الحالي برجاء إعادة المحاولة في وقت لاحق'
    ];
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
    
    // التحقق من وضع الصيانة قبل السماح بتسجيل الدخول
    // المطورون يستطيعون تسجيل الدخول دائماً حتى في وضع الصيانة
    if (isMaintenanceMode() && strtolower($user['role']) !== 'developer') {
        logLoginAttempt($username, false, 'وضع الصيانة مفعّل');
        return ['success' => false, 'message' => 'التطبيق تحت الصيانة في الوقت الحالي برجاء إعادة المحاولة في وقت لاحق'];
    }
    
    // حفظ CSRF token الحالي قبل إعادة توليد الجلسة (للمساعدة في التحقق)
    $currentCsrfToken = $_SESSION['csrf_token'] ?? null;
    
    // التأكد من أن الجلسة نشطة
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // حفظ بيانات المستخدم مباشرة في $_SESSION
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['logged_in'] = true;
    $_SESSION['last_activity'] = time();
    
    // حفظ CSRF token السابق إذا كان موجوداً
    if (isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token_previous'] = $_SESSION['csrf_token'];
        $_SESSION['csrf_token_previous_time'] = time();
    }
    
    // === إعادة توليد session_id مع الحفاظ على البيانات ===
    if (session_status() === PHP_SESSION_ACTIVE) {
        // حفظ نسخة احتياطية من البيانات قبل إعادة التوليد
        $sessionBackup = [];
        foreach ($_SESSION as $key => $value) {
            $sessionBackup[$key] = $value;
        }
        
        // إعادة توليد session_id (false = يحتفظ بالبيانات)
        session_regenerate_id(false);
        
        // التأكد من أن البيانات لا تزال موجودة
        if (empty($_SESSION) || !isset($_SESSION['user_id'])) {
            error_log("Login WARNING: Session data lost after regenerate_id - restoring from backup");
            $_SESSION = $sessionBackup;
        }
        
        // التأكد مرة أخرى من البيانات الأساسية
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['logged_in'] = true;
        $_SESSION['last_activity'] = time();
        
        // === إرسال session cookie الجديد فوراً بعد regenerate_id ===
        // هذا ضروري لضمان أن المتصفح يحصل على session_id الجديد
        if (!headers_sent()) {
            $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (3600 * 24 * 7);
            $sessionName = session_name();
            $newSessionId = session_id();
            
            // إرسال cookie الجلسة مع الإعدادات الصحيحة
            setcookie($sessionName, $newSessionId, [
                'expires' => time() + $sessionLifetime,
                'path' => '/',
                'domain' => '',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => $isHttps ? 'None' : 'Lax',
            ]);
            
            // تحديث $_COOKIE أيضاً لضمان أن الكود اللاحق يرى session_id الجديد
            $_COOKIE[$sessionName] = $newSessionId;
            
            error_log("Login: Session cookie sent after regenerate_id for user_id: {$user['id']}, session_id: " . substr($newSessionId, 0, 20) . "...");
        } else {
            error_log("Login WARNING: Headers already sent, cannot set session cookie after regenerate_id");
        }
    }
    
    // إنشاء token جديد بعد إعادة توليد الجلسة
    if (function_exists('generateCSRFToken')) {
        generateCSRFToken(true);
    }
    
    // التحقق النهائي قبل حفظ الجلسة في قاعدة البيانات
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['user_id']) || $_SESSION['user_id'] != $user['id']) {
        error_log("Login CRITICAL: Session data verification failed before saving to DB - forcing restore");
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['logged_in'] = true;
        $_SESSION['last_activity'] = time();
    }
    
    // التأكد من أن session_id موجود
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    
    // حفظ الجلسة في قاعدة البيانات - ضروري للتحقق
    // يجب الحصول على session_id بعد session_regenerate_id()
    $sessionId = session_id();
    $userId = $user['id'];
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    
    // التأكد من أن session_id موجود وصحيح
    if (empty($sessionId)) {
        error_log("Login ERROR: session_id is empty after session_regenerate_id()");
        $_SESSION = [];
        @session_unset();
        @session_destroy();
        return ['success' => false, 'message' => 'حدث خطأ أثناء إنشاء الجلسة. يرجى المحاولة مرة أخرى.'];
    }
    
    if (ensureSessionsTable()) {
        $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (3600 * 24 * 7);
        $expiresAt = date('Y-m-d H:i:s', time() + $sessionLifetime);
        
        // حفظ الجلسة بشكل متزامن - ضروري للتحقق
        try {
            $db = db();
            
            // حذف جميع الجلسات القديمة للمستخدم أولاً (لتجنب الجلسات المتعددة)
            $db->execute("DELETE FROM sessions WHERE user_id = ?", [$userId]);
            
            // إضافة الجلسة الجديدة (واحدة فقط)
            $result = $db->execute(
                "INSERT INTO sessions (user_id, session_id, ip_address, user_agent, expires_at, last_activity) 
                 VALUES (?, ?, ?, ?, ?, NOW())",
                [$userId, $sessionId, $ipAddress, $userAgent, $expiresAt]
            );
            
            // التحقق من نجاح الحفظ
            if ($result) {
                error_log("Login SUCCESS: Session saved to database for user_id: {$userId}, session_id: " . substr($sessionId, 0, 20) . "...");
                
                // التحقق من أن الجلسة تم حفظها بالفعل - مع إعادة المحاولة إذا لزم الأمر
                $maxRetries = 3;
                $verifySession = null;
                for ($retry = 0; $retry < $maxRetries; $retry++) {
                    $verifySession = $db->queryOne(
                        "SELECT * FROM sessions WHERE user_id = ? AND session_id = ?",
                        [$userId, $sessionId]
                    );
                    
                    if ($verifySession) {
                        break; // الجلسة موجودة، توقف عن إعادة المحاولة
                    }
                    
                    if ($retry < $maxRetries - 1) {
                        // انتظار قصير قبل إعادة المحاولة
                        usleep(100000); // 0.1 ثانية
                        error_log("Login: Retry {$retry} - Session not found, retrying...");
                    }
                }
                
                if (!$verifySession) {
                    error_log("Login WARNING: Session saved but verification failed after {$maxRetries} retries for user_id: {$userId}, session_id: " . substr($sessionId, 0, 20) . "...");
                    // محاولة إعادة الحفظ
                    try {
                        $db->execute(
                            "INSERT INTO sessions (user_id, session_id, ip_address, user_agent, expires_at, last_activity) 
                             VALUES (?, ?, ?, ?, ?, NOW())
                             ON DUPLICATE KEY UPDATE last_activity = NOW(), expires_at = ?",
                            [$userId, $sessionId, $ipAddress, $userAgent, $expiresAt, $expiresAt]
                        );
                        error_log("Login: Retried saving session for user_id: {$userId}");
                        
                        // التحقق مرة أخرى بعد إعادة الحفظ
                        usleep(50000); // 0.05 ثانية
                        $verifySession = $db->queryOne(
                            "SELECT * FROM sessions WHERE user_id = ? AND session_id = ?",
                            [$userId, $sessionId]
                        );
                        if ($verifySession) {
                            error_log("Login VERIFIED: Session confirmed after retry for user_id: {$userId}");
                        } else {
                            error_log("Login ERROR: Session still not found after retry for user_id: {$userId}");
                        }
                    } catch (Exception $retryError) {
                        error_log("Login ERROR: Retry failed: " . $retryError->getMessage());
                    }
                } else {
                    error_log("Login VERIFIED: Session confirmed in database for user_id: {$userId}, session_id: " . substr($sessionId, 0, 20) . "...");
                }
                
                // التأكد النهائي من وجود البيانات في $_SESSION بعد حفظ الجلسة
                if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['user_id'])) {
                    error_log("Login CRITICAL ERROR: Session data lost after saving to DB - restoring");
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['logged_in'] = true;
                    $_SESSION['last_activity'] = time();
                }
                
                // === كتابة الجلسة فوراً لضمان حفظها ===
                // هذا يضمن أن الجلسة محفوظة قبل إعادة التوجيه
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                    // إعادة فتح الجلسة للاستمرار في استخدامها
                    session_start();
                    // التأكد من أن البيانات لا تزال موجودة بعد إعادة الفتح
                    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['user_id'])) {
                        error_log("Login CRITICAL: Session data lost after write_close - restoring");
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['logged_in'] = true;
                        $_SESSION['last_activity'] = time();
                    }
                }
            } else {
                error_log("Login ERROR: Failed to save session to database for user_id: {$userId}");
                $_SESSION = [];
                @session_unset();
                @session_destroy();
                return ['success' => false, 'message' => 'حدث خطأ أثناء حفظ الجلسة. يرجى المحاولة مرة أخرى.'];
            }
        } catch (Exception $e) {
            error_log("Login ERROR: Exception saving session to database: " . $e->getMessage());
            error_log("Login ERROR: Stack trace: " . $e->getTraceAsString());
            // إذا فشل حفظ الجلسة، نعتبر تسجيل الدخول فاشلاً
            $_SESSION = [];
            @session_unset();
            @session_destroy();
            return ['success' => false, 'message' => 'حدث خطأ أثناء حفظ الجلسة. يرجى المحاولة مرة أخرى.'];
        }
    } else {
        error_log("Login ERROR: Failed to ensure sessions table exists");
        $_SESSION = [];
        @session_unset();
        @session_destroy();
        return ['success' => false, 'message' => 'حدث خطأ أثناء تهيئة الجلسة. يرجى المحاولة مرة أخرى.'];
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
 * التحقق إذا كان المستخدم مطور أو مدير (لديهم صلاحيات كاملة)
 */
function isAdminOrDeveloper() {
    if (!isLoggedIn()) {
        return false;
    }
    
    $currentRole = strtolower($_SESSION['role'] ?? '');
    return in_array($currentRole, ['manager', 'developer'], true);
}

/**
 * التحقق إذا كان المستخدم يمكنه التعديل (مدير، مطور، أو أدوار أخرى حسب السياق)
 */
function canEdit($allowedRoles = ['manager', 'developer']) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $currentRole = strtolower($_SESSION['role'] ?? '');
    
    // المطور دائماً يمكنه التعديل
    if ($currentRole === 'developer') {
        return true;
    }
    
    // التحقق من الأدوار المسموحة
    if (is_array($allowedRoles)) {
        return in_array($currentRole, $allowedRoles, true);
    }
    
    return $currentRole === strtolower($allowedRoles);
}

/**
 * التحقق إذا كان المستخدم يمكنه الحذف (مدير، مطور، أو أدوار أخرى حسب السياق)
 */
function canDelete($allowedRoles = ['manager', 'developer']) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $currentRole = strtolower($_SESSION['role'] ?? '');
    
    // المطور دائماً يمكنه الحذف
    if ($currentRole === 'developer') {
        return true;
    }
    
    // التحقق من الأدوار المسموحة
    if (is_array($allowedRoles)) {
        return in_array($currentRole, $allowedRoles, true);
    }
    
    return $currentRole === strtolower($allowedRoles);
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
    // التحقق من أننا في profile.php أو attendance.php أو sales.php - منع إعادة التوجيه
    $isProfilePage = false;
    $isAttendancePage = false;
    $isSalesPage = false;
    
    // الطريقة 1: التحقق من الثوابت (الأكثر موثوقية)
    if (defined('PROFILE_PAGE_ACTIVE') && PROFILE_PAGE_ACTIVE === true) {
        $isProfilePage = true;
    }
    if (defined('ATTENDANCE_PAGE_ACTIVE') && ATTENDANCE_PAGE_ACTIVE === true) {
        $isAttendancePage = true;
    }
    if (defined('SALES_PAGE_ACTIVE') && SALES_PAGE_ACTIVE === true) {
        $isSalesPage = true;
    }
    
    // الطريقة 2: التحقق من SCRIPT_NAME و PHP_SELF
    if (!$isProfilePage && !$isAttendancePage && !$isSalesPage) {
        $currentScript = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
        if (strpos($currentScript, 'profile.php') !== false || basename($currentScript) === 'profile.php') {
            $isProfilePage = true;
        } elseif (strpos($currentScript, 'attendance.php') !== false || basename($currentScript) === 'attendance.php') {
            $isAttendancePage = true;
        } elseif (strpos($currentScript, 'sales.php') !== false || basename($currentScript) === 'sales.php' || strpos($currentScript, 'dashboard/sales.php') !== false) {
            $isSalesPage = true;
        }
    }
    
    // الطريقة 3: التحقق من REQUEST_URI
    if (!$isProfilePage && !$isAttendancePage && !$isSalesPage) {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($requestUri, 'profile.php') !== false) {
            $isProfilePage = true;
        } elseif (strpos($requestUri, 'attendance.php') !== false) {
            $isAttendancePage = true;
        } elseif (strpos($requestUri, 'sales.php') !== false || strpos($requestUri, 'dashboard/sales') !== false) {
            $isSalesPage = true;
        }
    }
    
    $isProtectedPage = $isProfilePage || $isAttendancePage || $isSalesPage;
    
    // التأكد من أن الجلسة نشطة قبل أي فحص
    if (session_status() === PHP_SESSION_NONE) {
        if (!headers_sent()) {
            @session_start();
        }
    }
    
    // التحقق من تسجيل الدخول - المصدر الوحيد للتحقق
    // isLoggedIn() يتحقق من قاعدة البيانات أولاً
    $loginCheckResult = isLoggedIn();
    if ($loginCheckResult) {
        // التحقق من وضع الصيانة بعد التحقق من تسجيل الدخول
        $maintenanceCheck = checkMaintenanceMode();
        if (!$maintenanceCheck['allowed']) {
            // حفظ رسالة وضع الصيانة في session فقط للمستخدمين غير المطورين
            if (session_status() === PHP_SESSION_ACTIVE && !isDeveloper()) {
                $_SESSION['maintenance_mode'] = true;
                $_SESSION['maintenance_message'] = $maintenanceCheck['message'] ?? 'التطبيق تحت الصيانة في الوقت الحالي برجاء إعادة المحاولة في وقت لاحق';
            }
            
            // السماح للصفحة بالتحميل ولكن وضع علامة في session
            // سيتم عرض Modal في JavaScript بناءً على $_SESSION['maintenance_mode']
            // نتابع التنفيذ ولكن الصفحة ستقوم بعرض Modal وتمنع التفاعلات
        } else {
            // إزالة علامة وضع الصيانة إذا كان الوضع معطلاً أو إذا كان المستخدم مطوراً
            if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['maintenance_mode'])) {
                unset($_SESSION['maintenance_mode'], $_SESSION['maintenance_message']);
            }
        }
        
        // المستخدم مسجل دخول - المتابعة (سواء كان في وضع الصيانة أم لا - سيتم التعامل معه في JavaScript)
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

    // === المستخدم غير مسجل دخول - الجلسة غير موجودة في قاعدة البيانات ===
    // تسجيل محاولة الوصول غير المصرح به
    error_log("requireLogin() FAILED: User attempted to access protected page without valid session | Script: " . ($_SERVER['SCRIPT_NAME'] ?? 'unknown'));
    
    // حذف أي جلسة PHP متبقية
    $_SESSION = [];
    @session_unset();
    @session_destroy();
    
    // حذف cookies (فقط إذا لم يتم إرسال headers بعد)
    if (isset($_COOKIE[session_name()]) && !headers_sent()) {
        setcookie(session_name(), '', time() - 3600, '/');
    }

    // المستخدم غير مسجل دخول - إعادة التوجيه (إلا إذا كنا في صفحة محمية)
    if (!$isProtectedPage) {
        // محاولة تحميل path_helper إذا لم يكن محملاً
        if (!function_exists('getRelativeUrl') && file_exists(__DIR__ . '/path_helper.php')) {
            require_once __DIR__ . '/path_helper.php';
        }
        $loginUrl = function_exists('getRelativeUrl') ? getRelativeUrl('index.php') : '/index.php';
        
        // تنظيف URL من معاملات _nocache و _refresh وغيرها
        $loginUrl = preg_replace('/[?&](_nocache|_refresh|_cache_bust|_t|_r|_auto_refresh)=\d+/', '', $loginUrl);
        $loginUrl = rtrim($loginUrl, '?&');
        
        // إضافة رسالة تنبيه للمستخدم عن فشل الجلسة
        // حفظ الرسالة في session لتظهر في صفحة تسجيل الدخول
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['session_error'] = 'انتهت الجلسة. يرجى تسجيل الدخول مرة أخرى.';
            $_SESSION['session_failed'] = true;
        }
        
        // التحقق من إرسال الـ headers أو تضمين header.php
        $headersSent = @headers_sent($file, $line);
        $headerIncluded = defined('HEADER_INCLUDED') && HEADER_INCLUDED;
        
        // إذا تم تضمين header.php أو كانت الـ headers قد أُرسلت، استخدم JavaScript redirect دائماً
        if ($headerIncluded || $headersSent) {
            // استخدام replace بدلاً من href لتجنب إضافة URL للتاريخ
            echo '<script>window.location.replace("' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '");</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '"></noscript>';
            exit;
        }
        
        // محاولة إرسال header فقط إذا لم تكن الـ headers قد أُرسلت ولم يتم تضمين header.php
        if (!@headers_sent()) {
            @header('Location: ' . $loginUrl, true, 303);
            if (!@headers_sent()) {
                exit;
            }
        }
        
        // إذا فشل header()، استخدم JavaScript redirect
        echo '<script>window.location.replace("' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '");</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '"></noscript>';
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
        // 1. إزالة أي بروتوكول مع hostname ومنفذ
        $dashboardUrl = preg_replace('/^https?:\/\/[^\/]+(:[0-9]+)?/', '', $dashboardUrl);
        $dashboardUrl = preg_replace('/^\/\//', '/', $dashboardUrl);
        
        // 2. إزالة hostname مع منفذ إذا كان موجوداً (مثل localhost:8000)
        if (preg_match('/^\/[^\/]+:[0-9]+\//', $dashboardUrl)) {
            $dashboardUrl = preg_replace('/^\/[^\/]+:[0-9]+/', '', $dashboardUrl);
        }
        
        // 3. التأكد من أن المسار يبدأ بـ /
        if (strpos($dashboardUrl, '/') !== 0) {
            $dashboardUrl = '/' . $dashboardUrl;
        }
        
        // 4. تنظيف المسار (إزالة // المكررة)
        $dashboardUrl = preg_replace('/\/+/', '/', $dashboardUrl);
        
        // 5. إزالة أي hostname إذا كان موجوداً (بما في ذلك localhost:8000)
        if (preg_match('/^\/[^\/]+(\.[a-z]|:[0-9])/i', $dashboardUrl)) {
            $parts = explode('/', $dashboardUrl);
            $dashboardIndex = array_search('dashboard', $parts);
            if ($dashboardIndex !== false) {
                $dashboardUrl = '/' . implode('/', array_slice($parts, $dashboardIndex));
            } else {
                $dashboardUrl = '/dashboard/' . $userRole . '.php';
            }
        }
        
        // 6. التحقق النهائي: إذا كان المسار لا يحتوي على 'dashboard'، أضفه
        if (strpos($dashboardUrl, '/dashboard') === false) {
            $dashboardUrl = '/dashboard/' . $userRole . '.php';
        }
        
        // 7. التأكد من أن المسار لا يحتوي على http:// أو https:// مرة أخرى
        if (strpos($dashboardUrl, 'http://') === 0 || strpos($dashboardUrl, 'https://') === 0) {
            $parsed = parse_url($dashboardUrl);
            $dashboardUrl = $parsed['path'] ?? '/dashboard/' . $userRole . '.php';
        }
        
        // 8. تنظيف نهائي مع إزالة أي منفذ
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
        // 1. إزالة أي بروتوكول مع hostname ومنفذ
        $dashboardUrl = preg_replace('/^https?:\/\/[^\/]+(:[0-9]+)?/', '', $dashboardUrl);
        $dashboardUrl = preg_replace('/^\/\//', '/', $dashboardUrl);
        
        // 2. إزالة hostname مع منفذ إذا كان موجوداً (مثل localhost:8000)
        if (preg_match('/^\/[^\/]+:[0-9]+\//', $dashboardUrl)) {
            $dashboardUrl = preg_replace('/^\/[^\/]+:[0-9]+/', '', $dashboardUrl);
        }
        
        // 3. التأكد من أن المسار يبدأ بـ /
        if (strpos($dashboardUrl, '/') !== 0) {
            $dashboardUrl = '/' . $dashboardUrl;
        }
        
        // 4. تنظيف المسار (إزالة // المكررة)
        $dashboardUrl = preg_replace('/\/+/', '/', $dashboardUrl);
        
        // 5. إزالة أي hostname إذا كان موجوداً (بما في ذلك localhost:8000)
        if (preg_match('/^\/[^\/]+(\.[a-z]|:[0-9])/i', $dashboardUrl)) {
            $parts = explode('/', $dashboardUrl);
            $dashboardIndex = array_search('dashboard', $parts);
            if ($dashboardIndex !== false) {
                $dashboardUrl = '/' . implode('/', array_slice($parts, $dashboardIndex));
            } else {
                $dashboardUrl = '/dashboard/' . $userRole . '.php';
            }
        }
        
        // 6. التحقق النهائي: إذا كان المسار لا يحتوي على 'dashboard'، أضفه
        if (strpos($dashboardUrl, '/dashboard') === false) {
            $dashboardUrl = '/dashboard/' . $userRole . '.php';
        }
        
        // 7. التأكد من أن المسار لا يحتوي على http:// أو https:// مرة أخرى
        if (strpos($dashboardUrl, 'http://') === 0 || strpos($dashboardUrl, 'https://') === 0) {
            $parsed = parse_url($dashboardUrl);
            $dashboardUrl = $parsed['path'] ?? '/dashboard/' . $userRole . '.php';
        }
        
        // 8. تنظيف نهائي مع إزالة أي منفذ
        $dashboardUrl = trim($dashboardUrl);
        if (empty($dashboardUrl) || $dashboardUrl === '/') {
            $dashboardUrl = '/dashboard/' . $userRole . '.php';
        }
        
        // تنظيف شامل للمسار: إزالة أي بروتوكول أو hostname أو منفذ لمنع ERR_FAILED
        $dashboardUrl = preg_replace('/^https?:\/\/[^\/]+(:[0-9]+)?/', '', $dashboardUrl);
        $dashboardUrl = preg_replace('/^\/\//', '/', $dashboardUrl);
        if (preg_match('/^\/[^\/]+:[0-9]+\//', $dashboardUrl)) {
            $dashboardUrl = preg_replace('/^\/[^\/]+:[0-9]+/', '', $dashboardUrl);
        }
        if (strpos($dashboardUrl, '/') !== 0) {
            $dashboardUrl = '/' . $dashboardUrl;
        }
        $dashboardUrl = preg_replace('/\/+/', '/', $dashboardUrl);
        
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

