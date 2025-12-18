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
 * التحقق من تسجيل الدخول - يعتمد على remember_token فقط بدون جلسات
 * تم إزالة الاعتماد على $_SESSION تماماً
 */
function isLoggedIn() {
    // التحقق من remember_token فقط - لا نستخدم $_SESSION أبداً
    if (!isset($_COOKIE['remember_token']) || empty($_COOKIE['remember_token'])) {
        return false;
    }
    
    $cookieValue = $_COOKIE['remember_token'];
    if (empty($cookieValue)) {
        return false;
    }
    
    return checkRememberToken($cookieValue, false); // false = لا ننشئ جلسة
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

// === تم إزالة نظام الجلسات (sessions) بالكامل ===
// النظام يعتمد فقط على remember_token
// لا حاجة لجدول sessions أو أي كود متعلق بالجلسات

/**
 * دالة فارغة للتوافق مع الملفات القديمة
 * تم إزالة نظام الجلسات - ترجع false دائماً
 */
function ensureSessionsTable() {
    return false; // تم إزالة نظام الجلسات
}

/**
 * دالة فارغة للتوافق مع الملفات القديمة
 * تم إزالة نظام الجلسات - ترجع array فارغ
 */
function cleanupExpiredSessions($days = 30) {
    return [
        'success' => false,
        'deleted' => 0,
        'message' => 'تم إزالة نظام الجلسات'
    ];
}

/**
 * التحقق من token "تذكرني"
 */
function checkRememberToken($cookieValue, $createSession = true) {
    try {
        // التأكد من وجود الجدول
        if (!ensureRememberTokensTable()) {
            error_log("checkRememberToken: ensureRememberTokensTable() failed");
            return false;
        }
        
        if (empty($cookieValue)) {
            error_log("checkRememberToken: cookieValue is empty");
            return false;
        }
        
        $decoded = base64_decode($cookieValue, true); // strict mode
        if ($decoded === false || empty($decoded)) {
            error_log("checkRememberToken: base64_decode failed for cookie value");
            return false;
        }
        
        $parts = explode(':', $decoded);
        if (count($parts) !== 2) {
            error_log("checkRememberToken: Invalid token format (parts count: " . count($parts) . ")");
            return false;
        }
        
        $userId = intval($parts[0]);
        $token = trim($parts[1]);
        
        if ($userId <= 0 || empty($token)) {
            error_log("checkRememberToken: Invalid userId ({$userId}) or empty token");
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
            error_log("checkRememberToken: Token not found or expired for user_id: {$userId}");
            
            // التحقق من أننا في صفحة محمية (مثل profile.php)
            // لا نحذف cookie في الصفحات المحمية لأن token قد يكون موجوداً لكن هناك تأخير في قاعدة البيانات
            $isProtectedPage = (
                (defined('PROFILE_PAGE_ACTIVE') && PROFILE_PAGE_ACTIVE === true) ||
                (defined('ATTENDANCE_PAGE_ACTIVE') && ATTENDANCE_PAGE_ACTIVE === true) ||
                (defined('SALES_PAGE_ACTIVE') && SALES_PAGE_ACTIVE === true) ||
                (defined('NOTIFICATIONS_API_ACTIVE') && NOTIFICATIONS_API_ACTIVE === true) ||
                (defined('WEBAUTHN_API_ACTIVE') && WEBAUTHN_API_ACTIVE === true)
            );
            
            // إذا لم نكن في صفحة محمية، احذف cookie غير صالح
            if (!$isProtectedPage) {
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
            } else {
                error_log("checkRememberToken: Token not found but keeping cookie for protected page");
            }
            
            return false;
        }
        
        // تحديث آخر استخدام
        try {
            $db->execute(
                "UPDATE remember_tokens SET last_used = NOW() WHERE id = ?",
                [$tokenRecord['id']]
            );
        } catch (Exception $e) {
            // لا نعتبر هذا خطأ حرج - فقط تسجيل
            error_log("checkRememberToken: Failed to update last_used: " . $e->getMessage());
        }
        
        // === لا نستخدم $_SESSION - تم إزالة كل كود الجلسات ===
        // في النظام الجديد، نرجع true دائماً بدون إنشاء جلسة
        // ignore createSession parameter - we never create sessions
        
        return true;
    } catch (Exception $e) {
        error_log("Remember Token Check Error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
        return false;
    } catch (Throwable $e) {
        error_log("Remember Token Check Throwable: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
        return false;
    }
}

/**
 * الحصول على معلومات المستخدم الحالي
 * مع التحقق من وجود المستخدم وحالته وإلغاء تسجيل الدخول تلقائياً إذا كان محذوفاً أو غير مفعّل
 * مع استخدام Cache لتحسين الأداء
 */
/**
 * الحصول على بيانات المستخدم من remember_token مباشرة
 */
function getUserFromToken() {
    if (!isset($_COOKIE['remember_token']) || empty($_COOKIE['remember_token'])) {
        return null;
    }
    
    try {
        if (!ensureRememberTokensTable()) {
            error_log("getUserFromToken() ERROR: ensureRememberTokensTable() failed");
            return null;
        }
        
        $cookieValue = $_COOKIE['remember_token'];
        $decoded = base64_decode($cookieValue, true); // strict mode
        if ($decoded === false || empty($decoded)) {
            error_log("getUserFromToken() ERROR: base64_decode failed");
            return null;
        }
        
        $parts = explode(':', $decoded);
        if (count($parts) !== 2) {
            error_log("getUserFromToken() ERROR: Invalid token format (parts: " . count($parts) . ")");
            return null;
        }
        
        $userId = intval($parts[0]);
        $token = trim($parts[1]);
        
        if ($userId <= 0 || empty($token)) {
            error_log("getUserFromToken() ERROR: Invalid userId ({$userId}) or empty token");
            return null;
        }
        
        $db = db();
        $tokenRecord = $db->queryOne(
            "SELECT rt.*, u.* FROM remember_tokens rt
             INNER JOIN users u ON rt.user_id = u.id
             WHERE rt.user_id = ? AND rt.token = ? AND rt.expires_at > NOW() AND u.status = 'active'",
            [$userId, $token]
        );
        
        if (!$tokenRecord) {
            error_log("getUserFromToken() ERROR: Token not found or expired for user_id: {$userId}");
            return null;
        }
        
        // تحديث last_used
        try {
            $db->execute(
                "UPDATE remember_tokens SET last_used = NOW() WHERE id = ?",
                [$tokenRecord['id']]
            );
        } catch (Exception $e) {
            // لا نعتبر هذا خطأ حرج
            error_log("getUserFromToken() WARNING: Failed to update last_used: " . $e->getMessage());
        }
        
        // إرجاع بيانات المستخدم
        return [
            'id' => $tokenRecord['user_id'],
            'username' => $tokenRecord['username'],
            'email' => $tokenRecord['email'] ?? null,
            'full_name' => $tokenRecord['full_name'] ?? null,
            'role' => $tokenRecord['role'],
            'status' => $tokenRecord['status'],
            'phone' => $tokenRecord['phone'] ?? null,
            'created_at' => $tokenRecord['created_at'] ?? null,
            'updated_at' => $tokenRecord['updated_at'] ?? null,
            'profile_photo' => $tokenRecord['profile_photo'] ?? null,
            'webauthn_enabled' => $tokenRecord['webauthn_enabled'] ?? false,
        ];
    } catch (Exception $e) {
        error_log("getUserFromToken() ERROR: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
        return null;
    } catch (Throwable $e) {
        error_log("getUserFromToken() THROWABLE: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
        return null;
    }
}

/**
 * الحصول على معلومات المستخدم الحالي - يعتمد على remember_token فقط بدون جلسات
 * تم إزالة الاعتماد على $_SESSION تماماً
 */
function getCurrentUser() {
    // الحصول على بيانات المستخدم من remember_token مباشرة
    // لا نعتمد على isLoggedIn() لأنه قد يفشل في بعض الحالات
    $user = getUserFromToken();
    if (!$user) {
        return null;
    }
    
    // التحقق من أن المستخدم مفعّل
    if (isset($user['status']) && $user['status'] !== 'active') {
        return null;
    }
    
    return $user;
}

/**
 * جلب بيانات المستخدم من قاعدة البيانات (دالة مساعدة)
 * @param int $userId معرف المستخدم
 * @return array|null بيانات المستخدم أو null
 */
function getCurrentUserFromDatabase($userId) {
    // استخدام طرق متعددة للتحقق لضمان العمل على جميع الأجهزة (Windows, Android, iOS)
    $isProfilePage = false;
    $isAttendancePage = false;
    $isSalesPage = false;
    $isNotificationsAPI = false;
    $isWebAuthnAPI = false;
    
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
    if (defined('WEBAUTHN_API_ACTIVE') && WEBAUTHN_API_ACTIVE === true) {
        $isWebAuthnAPI = true;
    }
    
    // الطريقة 2: التحقق من SCRIPT_NAME و PHP_SELF
    if (!$isProfilePage && !$isAttendancePage && !$isSalesPage && !$isNotificationsAPI && !$isWebAuthnAPI) {
        $currentScript = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
        if (strpos($currentScript, 'profile.php') !== false || basename($currentScript) === 'profile.php') {
            $isProfilePage = true;
        } elseif (strpos($currentScript, 'attendance.php') !== false || basename($currentScript) === 'attendance.php') {
            $isAttendancePage = true;
        } elseif (strpos($currentScript, 'sales.php') !== false || basename($currentScript) === 'sales.php' || strpos($currentScript, 'dashboard/sales.php') !== false) {
            $isSalesPage = true;
        } elseif (strpos($currentScript, 'notifications.php') !== false || basename($currentScript) === 'notifications.php') {
            $isNotificationsAPI = true;
        } elseif (strpos($currentScript, 'webauthn_credentials.php') !== false || basename($currentScript) === 'webauthn_credentials.php') {
            $isWebAuthnAPI = true;
        }
    }
    
    // الطريقة 3: استخدام debug_backtrace كبديل
    if (!$isProfilePage && !$isAttendancePage && !$isSalesPage && !$isNotificationsAPI && !$isWebAuthnAPI) {
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
                } elseif ($fileName === 'webauthn_credentials.php' || strpos($trace['file'], 'webauthn_credentials.php') !== false) {
                    $isWebAuthnAPI = true;
                    break;
                }
            }
        }
    }
    
    // الطريقة 4: التحقق من REQUEST_URI
    if (!$isProfilePage && !$isAttendancePage && !$isSalesPage && !$isNotificationsAPI && !$isWebAuthnAPI) {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($requestUri, 'profile.php') !== false) {
            $isProfilePage = true;
        } elseif (strpos($requestUri, 'attendance.php') !== false) {
            $isAttendancePage = true;
        } elseif (strpos($requestUri, 'sales.php') !== false || strpos($requestUri, 'dashboard/sales') !== false || strpos($requestUri, 'modules/sales') !== false) {
            $isSalesPage = true;
        } elseif (strpos($requestUri, 'notifications.php') !== false || strpos($requestUri, '/api/notifications') !== false) {
            $isNotificationsAPI = true;
        } elseif (strpos($requestUri, 'webauthn_credentials.php') !== false || strpos($requestUri, '/api/webauthn_credentials') !== false) {
            $isWebAuthnAPI = true;
        }
    }
    
    // الطريقة 5: التحقق من الدور في الجلسة - حماية شاملة للمندوبين
    $isSalesUser = false;
    if (isset($_SESSION['role']) && strtolower($_SESSION['role']) === 'sales') {
        $isSalesUser = true;
    }
    
    // تحديد إذا كان الصفحة محمية (profile أو attendance أو sales أو notifications API أو webauthn API أو مستخدم مندوب)
    $isProtectedPage = $isProfilePage || $isAttendancePage || $isSalesPage || $isNotificationsAPI || $isWebAuthnAPI || $isSalesUser;
    
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
        // منع حذف الجلسة إذا كان الطلب من profile.php أو attendance.php أو sales.php أو إذا كان المستخدم مندوب
        if ($isProtectedPage) {
            $pageName = $isProfilePage ? 'profile.php' : ($isAttendancePage ? 'attendance.php' : ($isSalesPage ? 'sales.php' : ($isSalesUser ? 'sales user' : 'notifications.php')));
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
        // منع حذف الجلسة إذا كان الطلب من profile.php أو attendance.php أو sales.php أو إذا كان المستخدم مندوب
        if ($isProtectedPage) {
            $pageName = $isProfilePage ? 'profile.php' : ($isAttendancePage ? 'attendance.php' : ($isSalesPage ? 'sales.php' : ($isSalesUser ? 'sales user' : 'notifications.php')));
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
/**
 * تسجيل الدخول - يعتمد على remember_token فقط بدون جلسات
 * @param string $username اسم المستخدم
 * @param string $password كلمة المرور
 * @param bool $rememberMe تفعيل "تذكرني"
 * @return array نتيجة تسجيل الدخول
 */
function login($username, $password, $rememberMe = true) { // جعل rememberMe افتراضياً true
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
    
    // === لا نستخدم $_SESSION - نعتمد على remember_token فقط ===
    // تم إزالة كل كود الجلسات - الاعتماد على remember_token فقط
    
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    
    // === إنشاء remember_token دائماً (مطلوب للنظام بدون جلسات) ===
    // في النظام الجديد، remember_token مطلوب دائماً لأنه الطريقة الوحيدة للمصادقة
    // يجب إنشاؤه دائماً بغض النظر عن قيمة $rememberMe
    // التأكد من وجود الجدول
    if (!ensureRememberTokensTable()) {
        // إذا فشل إنشاء الجدول، هذا خطأ حرج
        error_log("CRITICAL: Failed to create remember_tokens table - login will fail without it");
        return ['success' => false, 'message' => 'خطأ في النظام. يرجى المحاولة مرة أخرى.'];
    }
    
    // توليد token آمن
    $token = bin2hex(random_bytes(32));
    
    // حفظ token في قاعدة البيانات
    $db = db();
    // تحديد مدة الصلاحية بناءً على rememberMe
    $expiresDays = $rememberMe ? 30 : 1; // 30 يوم إذا كان rememberMe، يوم واحد إذا لم يكن
    $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiresDays} days"));
    
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
        error_log("CRITICAL: Error inserting remember token: " . $e->getMessage());
        return ['success' => false, 'message' => 'خطأ في حفظ جلسة العمل. يرجى المحاولة مرة أخرى.'];
    }
    
    // إنشاء cookie آمن - دائماً مطلوب
    $cookieValue = base64_encode($user['id'] . ':' . $token);
    
    // تحديد domain بناءً على البيئة
    $cookieDomain = '';
    // في الإنتاج، يمكن تحديد domain إذا لزم الأمر
    // $cookieDomain = '.albarakah.info'; // للسماح بالوصول من جميع subdomains
    
    $cookieSet = setcookie(
        'remember_token',
        $cookieValue,
        [
            'expires' => time() + ($expiresDays * 24 * 60 * 60),
            'path' => '/',
            'domain' => $cookieDomain,
            'secure' => $isHttps,
            'httponly' => true, // منع JavaScript من الوصول
            'samesite' => 'Lax'
        ]
    );
    
    if (!$cookieSet) {
        error_log("WARNING: Failed to set remember_token cookie for user_id: " . $user['id']);
    } else {
        // تسجيل نجاح إنشاء cookie (فقط في حالة التطوير)
        // error_log("SUCCESS: remember_token cookie set for user_id: " . $user['id']);
    }
    
    // التأكد من أن cookie موجود في $_COOKIE للطلبات اللاحقة في نفس الطلب
    $_COOKIE['remember_token'] = $cookieValue;
    
    // محاولة إضافة cookie مرة أخرى بدون secure إذا كان HTTP (للتوافق)
    if (!$isHttps) {
        @setcookie(
            'remember_token',
            $cookieValue,
            [
                'expires' => time() + ($expiresDays * 24 * 60 * 60),
                'path' => '/',
                'domain' => $cookieDomain,
                'secure' => false,
                'httponly' => true,
                'samesite' => 'Lax'
            ]
        );
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
 * @param int|null $userId معرف المستخدم (null لحذف Cache المستخدم الحالي من remember_token)
 */
function clearUserCache($userId = null) {
    if ($userId === null) {
        // الحصول على user_id من remember_token بدلاً من $_SESSION
        $user = getUserFromToken();
        $userId = $user['id'] ?? null;
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
/**
 * تسجيل الخروج - يعتمد على remember_token فقط بدون جلسات
 */
function logout() {
    // الحصول على user_id من remember_token قبل حذفه
    $userId = null;
    $user = getUserFromToken();
    if ($user && isset($user['id'])) {
        $userId = $user['id'];
    }
    
    // تنظيف Cache قبل تسجيل الخروج
    if (function_exists('clearUserCache')) {
        clearUserCache($userId);
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
    
    // تسجيل سجل التدقيق قبل حذف token
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
    
    // === لا نستخدم $_SESSION - تم إزالة كل كود الجلسات ===
}

/**
 * التحقق من الدور - يعتمد على remember_token فقط
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
    
    $user = getUserFromToken();
    if (!$user || !isset($user['role'])) {
        return false;
    }
    
    $currentRole = $user['role'];
    if (!is_string($currentRole) || $currentRole === '') {
        return false;
    }
    
    return strtolower($currentRole) === strtolower($role);
}

/**
 * التحقق إذا كان المستخدم مطور أو مدير (لديهم صلاحيات كاملة) - يعتمد على remember_token فقط
 */
function isAdminOrDeveloper() {
    if (!isLoggedIn()) {
        return false;
    }
    
    $user = getUserFromToken();
    if (!$user || !isset($user['role'])) {
        return false;
    }
    
    $currentRole = strtolower($user['role']);
    return in_array($currentRole, ['manager', 'developer'], true);
}

/**
 * التحقق إذا كان المستخدم يمكنه التعديل - يعتمد على remember_token فقط
 */
function canEdit($allowedRoles = ['manager', 'developer']) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $user = getUserFromToken();
    if (!$user || !isset($user['role'])) {
        return false;
    }
    
    $currentRole = strtolower($user['role']);
    
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
 * التحقق إذا كان المستخدم يمكنه الحذف - يعتمد على remember_token فقط
 */
function canDelete($allowedRoles = ['manager', 'developer']) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $user = getUserFromToken();
    if (!$user || !isset($user['role'])) {
        return false;
    }
    
    $currentRole = strtolower($user['role']);
    
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
 * التحقق من أي دور من الأدوار المحددة - يعتمد على remember_token فقط
 */
function hasAnyRole($roles) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $user = getUserFromToken();
    if (!$user || !isset($user['role'])) {
        return false;
    }
    
    $currentRole = $user['role'];
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
    // التحقق من أننا في profile.php أو attendance.php أو sales.php أو APIs - منع إعادة التوجيه
    $isProfilePage = false;
    $isAttendancePage = false;
    $isSalesPage = false;
    $isNotificationsAPI = false;
    $isWebAuthnAPI = false;
    
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
    if (defined('WEBAUTHN_API_ACTIVE') && WEBAUTHN_API_ACTIVE === true) {
        $isWebAuthnAPI = true;
    }
    
    // الطريقة 2: التحقق من SCRIPT_NAME و PHP_SELF
    if (!$isProfilePage && !$isAttendancePage && !$isSalesPage && !$isNotificationsAPI && !$isWebAuthnAPI) {
        $currentScript = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
        if (strpos($currentScript, 'profile.php') !== false || basename($currentScript) === 'profile.php') {
            $isProfilePage = true;
        } elseif (strpos($currentScript, 'attendance.php') !== false || basename($currentScript) === 'attendance.php') {
            $isAttendancePage = true;
        } elseif (strpos($currentScript, 'sales.php') !== false || basename($currentScript) === 'sales.php' || strpos($currentScript, 'dashboard/sales.php') !== false || strpos($currentScript, 'modules/sales') !== false) {
            $isSalesPage = true;
        } elseif (strpos($currentScript, 'notifications.php') !== false || basename($currentScript) === 'notifications.php') {
            $isNotificationsAPI = true;
        } elseif (strpos($currentScript, 'webauthn_credentials.php') !== false || basename($currentScript) === 'webauthn_credentials.php') {
            $isWebAuthnAPI = true;
        }
    }
    
    // الطريقة 3: التحقق من REQUEST_URI
    if (!$isProfilePage && !$isAttendancePage && !$isSalesPage && !$isNotificationsAPI && !$isWebAuthnAPI) {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($requestUri, 'profile.php') !== false) {
            $isProfilePage = true;
        } elseif (strpos($requestUri, 'attendance.php') !== false) {
            $isAttendancePage = true;
        } elseif (strpos($requestUri, 'sales.php') !== false || strpos($requestUri, 'dashboard/sales') !== false || strpos($requestUri, 'modules/sales') !== false) {
            $isSalesPage = true;
        } elseif (strpos($requestUri, 'notifications.php') !== false || strpos($requestUri, '/api/notifications') !== false) {
            $isNotificationsAPI = true;
        } elseif (strpos($requestUri, 'webauthn_credentials.php') !== false || strpos($requestUri, '/api/webauthn_credentials') !== false) {
            $isWebAuthnAPI = true;
        }
    }
    
    // الطريقة 4: التحقق من الدور من remember_token - حماية شاملة للمندوبين
    $isSalesUser = false;
    $user = getUserFromToken();
    if ($user && isset($user['role']) && strtolower($user['role']) === 'sales') {
        $isSalesUser = true;
    }
    
    $isProtectedPage = $isProfilePage || $isAttendancePage || $isSalesPage || $isNotificationsAPI || $isWebAuthnAPI || $isSalesUser;
    
    // === لا نستخدم $_SESSION - الاعتماد على remember_token فقط ===
    
    // التحقق من تسجيل الدخول - يعتمد على remember_token فقط
    try {
        $loginCheckResult = isLoggedIn();
        
        // إذا فشل التحقق وكانت الصفحة محمية (مثل profile.php)، حاول مرة أخرى
        // قد يكون هناك تأخير بسيط في قاعدة البيانات
        if (!$loginCheckResult && $isProtectedPage && isset($_COOKIE['remember_token']) && !empty($_COOKIE['remember_token'])) {
            // انتظار قصير جداً (50ms) ثم إعادة المحاولة
            usleep(50000);
            $loginCheckResult = isLoggedIn();
            
            // إذا استمر الفشل، حاول الحصول على المستخدم مباشرة من token
            // هذا مهم جداً للصفحات المحمية مثل profile.php
            if (!$loginCheckResult) {
                $userFromToken = getUserFromToken();
                if ($userFromToken && isset($userFromToken['id']) && !empty($userFromToken['id'])) {
                    // إذا وجدنا المستخدم من token، نعتبره مسجل دخول
                    $loginCheckResult = true;
                    error_log("requireLogin() - Retry successful for protected page using getUserFromToken()");
                }
            }
        }
        
        // للصفحات المحمية (مثل profile.php)، إذا كان هناك remember_token، نسمح بالوصول
        // حتى لو فشل isLoggedIn() - لأن getUserFromToken() قد يعمل
        if (!$loginCheckResult && $isProtectedPage && isset($_COOKIE['remember_token']) && !empty($_COOKIE['remember_token'])) {
            $userFromToken = getUserFromToken();
            if ($userFromToken && isset($userFromToken['id']) && !empty($userFromToken['id'])) {
                $loginCheckResult = true;
                error_log("requireLogin() - Allowing access to protected page based on getUserFromToken()");
            }
        }
    } catch (Throwable $e) {
        error_log("requireLogin() ERROR: Failed to check login status: " . $e->getMessage());
        
        // للصفحات المحمية، حاول getUserFromToken() كحل أخير
        if ($isProtectedPage && isset($_COOKIE['remember_token']) && !empty($_COOKIE['remember_token'])) {
            try {
                $userFromToken = getUserFromToken();
                if ($userFromToken && isset($userFromToken['id']) && !empty($userFromToken['id'])) {
                    $loginCheckResult = true;
                    error_log("requireLogin() - Exception occurred but getUserFromToken() succeeded for protected page");
                } else {
                    $loginCheckResult = false;
                }
            } catch (Throwable $e2) {
                error_log("requireLogin() ERROR: getUserFromToken() also failed: " . $e2->getMessage());
                $loginCheckResult = false;
            }
        } else {
            $loginCheckResult = false;
        }
    }
    
    if ($loginCheckResult) {
        // التحقق من وضع الصيانة بعد التحقق من تسجيل الدخول
        $maintenanceCheck = checkMaintenanceMode();
        if (!$maintenanceCheck['allowed']) {
            // تم إزالة نظام الجلسات - يمكن استخدام cookies أو JavaScript للتعامل مع وضع الصيانة
            // السماح للصفحة بالتحميل - سيتم التعامل مع وضع الصيانة في JavaScript
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
    error_log("requireLogin() FAILED: User attempted to access protected page without valid session | Script: " . ($_SERVER['SCRIPT_NAME'] ?? 'unknown') . " | IsProtectedPage: " . ($isProtectedPage ? 'true' : 'false'));
    
    // في الصفحات المحمية (API endpoints)، نعيد JSON response بدلاً من حذف الجلسة
    if ($isProtectedPage && ($isNotificationsAPI || $isWebAuthnAPI)) {
        // API endpoint - إرجاع JSON response
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'انتهت جلسة العمل، يرجى إعادة تسجيل الدخول'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // تم إزالة نظام الجلسات - لا حاجة لحذف الجلسات

    // المستخدم غير مسجل دخول - إعادة التوجيه (إلا إذا كنا في صفحة محمية)
    // ملاحظة: للصفحات المحمية مثل profile.php، إذا فشل التحقق تماماً، يجب إعادة التوجيه
    // لكن إذا نجح getUserFromToken() في requireLogin()، نسمح بالوصول
    if (!$isProtectedPage) {
        // محاولة تحميل path_helper إذا لم يكن محملاً
        if (!function_exists('getRelativeUrl') && file_exists(__DIR__ . '/path_helper.php')) {
            require_once __DIR__ . '/path_helper.php';
        }
        $loginUrl = function_exists('getRelativeUrl') ? getRelativeUrl('index.php') : '/index.php';
        
        // تنظيف URL من معاملات _nocache و _refresh وغيرها
        $loginUrl = preg_replace('/[?&](_nocache|_refresh|_cache_bust|_t|_r|_auto_refresh)=\d+/', '', $loginUrl);
        $loginUrl = rtrim($loginUrl, '?&');
        
        // تم إزالة نظام الجلسات - لا حاجة لحفظ رسائل في session
        
        // تنظيف output buffer قبل إعادة التوجيه
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        
        // التأكد من تنظيف URL من أي بروتوكول أو hostname
        $loginUrl = preg_replace('/^https?:\/\/[^\/]+(:[0-9]+)?/', '', $loginUrl);
        $loginUrl = preg_replace('/^\/\//', '/', $loginUrl);
        if (strpos($loginUrl, '/') !== 0) {
            $loginUrl = '/' . $loginUrl;
        }
        $loginUrl = preg_replace('/\/+/', '/', $loginUrl);
        
        // التحقق من إرسال الـ headers أو تضمين header.php
        $headersSent = @headers_sent($file, $line);
        $headerIncluded = defined('HEADER_INCLUDED') && HEADER_INCLUDED;
        
        // إذا تم تضمين header.php أو كانت الـ headers قد أُرسلت، استخدم JavaScript redirect دائماً
        if ($headerIncluded || $headersSent) {
            // استخدام replace بدلاً من href لتجنب إضافة URL للتاريخ
            // التأكد من أن loginUrl مسار نسبي فقط لمنع ERR_FAILED
            $safeLoginUrl = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>إعادة التوجيه...</title>';
            echo '<script>';
            echo 'try {';
            echo '  var loginUrl = ' . json_encode($loginUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';';
            echo '  // تنظيف URL لضمان أنه مسار نسبي فقط';
            echo '  loginUrl = loginUrl.replace(/^https?:\\/\\//i, "");';
            echo '  loginUrl = loginUrl.replace(/^\\/\\/+/, "/");';
            echo '  loginUrl = loginUrl.replace(/^[^\\/]+:[0-9]+\\//, "/");';
            echo '  if (!loginUrl.startsWith("/")) loginUrl = "/" + loginUrl;';
            echo '  loginUrl = loginUrl.replace(/\\/+/g, "/");';
            echo '  window.location.replace(loginUrl);';
            echo '} catch(e) {';
            echo '  console.error("Redirect error:", e);';
            echo '  window.location.href = "/index.php";';
            echo '}';
            echo '</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '"></noscript>';
            echo '</head><body><p>جاري التحويل إلى صفحة تسجيل الدخول...</p></body></html>';
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
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>إعادة التوجيه...</title>';
        echo '<script>';
        echo 'try {';
        echo '  var loginUrl = ' . json_encode($loginUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';';
        echo '  // تنظيف URL لضمان أنه مسار نسبي فقط';
        echo '  loginUrl = loginUrl.replace(/^https?:\\/\\//i, "");';
        echo '  loginUrl = loginUrl.replace(/^\\/\\/+/, "/");';
        echo '  loginUrl = loginUrl.replace(/^[^\\/]+:[0-9]+\\//, "/");';
        echo '  if (!loginUrl.startsWith("/")) loginUrl = "/" + loginUrl;';
        echo '  loginUrl = loginUrl.replace(/\\/+/g, "/");';
        echo '  window.location.replace(loginUrl);';
        echo '} catch(e) {';
        echo '  console.error("Redirect error:", e);';
        echo '  window.location.href = "/index.php";';
        echo '}';
        echo '</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '"></noscript>';
        echo '</head><body><p>جاري التحويل إلى صفحة تسجيل الدخول...</p></body></html>';
        exit;
    }
}

/**
 * التحقق من الدور المحدد
 * يدعم string أو array من الأدوار
 */
function requireRole($role) {
    // معالجة الأخطاء: إذا فشل requireLogin()، نعيد التوجيه
    try {
        requireLogin();
    } catch (Throwable $e) {
        // إذا حدث خطأ في requireLogin() (مثلاً فشل الاتصال بقاعدة البيانات)
        // نعيد التوجيه مباشرة إلى تسجيل الدخول
        error_log("requireRole() ERROR: Failed in requireLogin(): " . $e->getMessage());
        
        $loginUrl = function_exists('getRelativeUrl') ? getRelativeUrl('index.php') : '/index.php';
        $loginUrl = preg_replace('/^https?:\/\/[^\/]+(:[0-9]+)?/', '', $loginUrl);
        $loginUrl = preg_replace('/^\/\//', '/', $loginUrl);
        if (strpos($loginUrl, '/') !== 0) {
            $loginUrl = '/' . $loginUrl;
        }
        $loginUrl = preg_replace('/\/+/', '/', $loginUrl);
        
        // تم إزالة نظام الجلسات - لا حاجة لحفظ رسائل في session
        
        // تنظيف output buffer
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        
        if (!@headers_sent()) {
            @header('Location: ' . $loginUrl, true, 303);
            exit;
        } else {
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>إعادة التوجيه...</title>';
            echo '<script>window.location.replace("' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '");</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '"></noscript>';
            echo '</head><body><p>جاري التحويل إلى صفحة تسجيل الدخول...</p></body></html>';
            exit;
        }
    }
    
    // فحص أمني: التأكد من أن المستخدم موجود في قاعدة البيانات
    try {
        $currentUser = getCurrentUser();
    } catch (Throwable $e) {
        // إذا فشل getCurrentUser()، نعيد التوجيه
        error_log("requireRole() ERROR: Failed to get current user: " . $e->getMessage());
        $currentUser = null;
    }
    
    if (!$currentUser || !is_array($currentUser) || empty($currentUser)) {
        // المستخدم محذوف أو غير موجود - تم إلغاء تسجيل الدخول تلقائياً من getCurrentUser()
        $loginUrl = function_exists('getRelativeUrl') ? getRelativeUrl('index.php') : '/index.php';
        $loginUrl = preg_replace('/^https?:\/\/[^\/]+(:[0-9]+)?/', '', $loginUrl);
        $loginUrl = preg_replace('/^\/\//', '/', $loginUrl);
        if (strpos($loginUrl, '/') !== 0) {
            $loginUrl = '/' . $loginUrl;
        }
        $loginUrl = preg_replace('/\/+/', '/', $loginUrl);
        
        // تم إزالة نظام الجلسات - لا حاجة لحفظ رسائل في session
        
        // تنظيف output buffer
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        
        if (!headers_sent()) {
            header('Location: ' . $loginUrl, true, 303);
            exit;
        } else {
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>إعادة التوجيه...</title>';
            echo '<script>window.location.replace("' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '");</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '"></noscript>';
            echo '</head><body><p>جاري التحويل إلى صفحة تسجيل الدخول...</p></body></html>';
            exit;
        }
    }
    
    // إذا كان array، استخدم requireAnyRole
    if (is_array($role)) {
        return requireAnyRole($role);
    }
    
    if (!hasRole($role)) {
        $user = getUserFromToken();
        $userRole = $user['role'] ?? 'accountant';
        
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
        $user = getUserFromToken();
        $userRole = $user['role'] ?? 'accountant';
        
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

