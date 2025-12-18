<?php
/**
 * Security Enhancement: Session Management (InfinityFree Compatible)
 * تأمين الجلسات - متوافق مع InfinityFree ومدمج مع النظام الحالي
 * 
 * هذا الملف يحسّن إدارة الجلسات دون تعارض مع config.php
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/**
 * تهيئة الجلسة الآمنة - متوافقة مع النظام الحالي
 * لا تبدأ الجلسة إذا كانت بدأت بالفعل في config.php
 */
function initSecureSession() {
    // إذا كانت الجلسة بدأت بالفعل، فقط تحديث الإعدادات
    if (session_status() === PHP_SESSION_ACTIVE) {
        // تحديث آخر نشاط
        if (!isset($_SESSION['last_activity'])) {
            $_SESSION['last_activity'] = time();
        }
        
        // استخدام SESSION_LIFETIME بدلاً من SESSION_TIMEOUT (7 أيام بدلاً من 30 دقيقة)
        // مع هامش أمان إضافي (24 ساعة) لمنع انتهاء الجلسة نهائياً
        $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (3600 * 24 * 7); // 7 أيام افتراضياً
        $timeout = $sessionLifetime + (3600 * 24); // هامش أمان: 24 ساعة إضافية
        
        // التحقق من انتهاء صلاحية الجلسة فقط إذا كان المستخدم مسجل دخول
        // وإذا كان هناك وقت آخر نشاط سابق محفوظ
        if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
            // التحقق من أننا في profile.php أو attendance.php أو sales.php - منع حذف الجلسة
            $isProfilePage = defined('PROFILE_PAGE_ACTIVE') && PROFILE_PAGE_ACTIVE === true;
            $isAttendancePage = defined('ATTENDANCE_PAGE_ACTIVE') && ATTENDANCE_PAGE_ACTIVE === true;
            $isSalesPage = defined('SALES_PAGE_ACTIVE') && SALES_PAGE_ACTIVE === true;
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
            
            // التحقق من طلب keep-alive API - عدم حذف الجلسة أبداً في هذا الحالة
            $isKeepAliveRequest = false;
            $currentScript = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
            $requestUri = $_SERVER['REQUEST_URI'] ?? '';
            if (strpos($currentScript, 'session_keepalive.php') !== false || 
                strpos($requestUri, 'session_keepalive.php') !== false ||
                (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' && strpos($requestUri, 'keepalive') !== false)) {
                $isKeepAliveRequest = true;
            }
            
            // تعطيل فحص timeout - لا نهي الجلسة أبداً بناءً على الخمول
            // تحديث آخر نشاط دائماً
            $_SESSION['last_activity'] = time();
            if (!$isKeepAliveRequest) {
                $_SESSION['last_activity_previous'] = time();
            } elseif (!isset($_SESSION['last_activity_previous'])) {
                $_SESSION['last_activity_previous'] = time();
            }
            
            // تحديث session cookie دائماً لضمان بقاء الجلسة صالحة
            if (!headers_sent() && session_id()) {
                $isHttps = (
                    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                    (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443')
                );
                setcookie(session_name(), session_id(), [
                    'expires' => time() + $sessionLifetime,
                    'path' => '/',
                    'domain' => '',
                    'secure' => $isHttps,
                    'httponly' => true,
                    'samesite' => $isHttps ? 'None' : 'Lax',
                ]);
            }
        } else {
            // المستخدم غير مسجل دخول - فقط تحديث آخر نشاط
            $_SESSION['last_activity'] = time();
        }
        
        return;
    }
    
    // محاولة إنشاء مجلد sessions داخل tmp (اختياري)
    $sessionPath = __DIR__ . '/../tmp/sessions';
    if (!is_dir($sessionPath)) {
        @mkdir($sessionPath, 0750, true);
    }
    
    // تعيين مسار الجلسات إذا كان قابل للكتابة (اختياري)
    if (is_dir($sessionPath) && is_writable($sessionPath)) {
        @session_save_path($sessionPath);
    }
    
    // إعدادات آمنة للكوكيز (متوافقة مع config.php)
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443')
    );
    
    // استخدام نفس الإعدادات من config.php إذا كانت موجودة
    if (defined('SESSION_LIFETIME')) {
        $lifetime = SESSION_LIFETIME;
    } else {
        $lifetime = 0; // session cookie
    }
    
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    session_start();
    
    // تجديد معرف الجلسة للجلسات الجديدة فقط
    if (!isset($_SESSION['initiated'])) {
        // حفظ CSRF token الحالي قبل إعادة توليد الجلسة (إن وجد)
        $currentCsrfToken = $_SESSION['csrf_token'] ?? null;
        if ($currentCsrfToken) {
            $_SESSION['csrf_token_previous'] = $currentCsrfToken;
            $_SESSION['csrf_token_previous_time'] = time();
        }
        
        session_regenerate_id(true);
        $_SESSION['initiated'] = true;
        $_SESSION['created_at'] = time();
        $_SESSION['last_activity'] = time();
        
        // تجديد CSRF token بعد إعادة توليد الجلسة
        if (function_exists('generateCSRFToken')) {
            generateCSRFToken(true);
        }
    } else {
        // تحديث آخر نشاط
        $_SESSION['last_activity'] = time();
    }
    
        // التحقق من انتهاء صلاحية الجلسة (فقط للمستخدمين المسجلين)
        // استخدام SESSION_LIFETIME بدلاً من SESSION_TIMEOUT
        if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
            $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (3600 * 24 * 7); // 7 أيام
            // زيادة هامش الأمان إلى 24 ساعة لمنع انتهاء الجلسة بشكل مفاجئ
            $timeout = $sessionLifetime + (3600 * 24); // هامش أمان: 24 ساعة إضافية
            
            // التحقق من أننا في profile.php أو sales.php - منع حذف الجلسة في هذه الصفحات
            $isProfilePage = defined('PROFILE_PAGE_ACTIVE') && PROFILE_PAGE_ACTIVE === true;
            $isSalesPage = defined('SALES_PAGE_ACTIVE') && SALES_PAGE_ACTIVE === true;
            if (!$isProfilePage && !$isSalesPage) {
                $currentScript = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
                if (strpos($currentScript, 'profile.php') !== false || basename($currentScript) === 'profile.php') {
                    $isProfilePage = true;
                } elseif (strpos($currentScript, 'sales.php') !== false || basename($currentScript) === 'sales.php' || strpos($currentScript, 'dashboard/sales.php') !== false) {
                    $isSalesPage = true;
                }
            }
            if (!$isProfilePage && !$isSalesPage) {
                $requestUri = $_SERVER['REQUEST_URI'] ?? '';
                if (strpos($requestUri, 'profile.php') !== false) {
                    $isProfilePage = true;
                } elseif (strpos($requestUri, 'sales.php') !== false || strpos($requestUri, 'dashboard/sales') !== false) {
                    $isSalesPage = true;
                }
            }
            
            // التحقق من طلب keep-alive API - عدم حذف الجلسة أبداً في هذا الحالة
            $isKeepAliveRequest = false;
            $currentScript = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
            $requestUri = $_SERVER['REQUEST_URI'] ?? '';
            if (strpos($currentScript, 'session_keepalive.php') !== false || 
                strpos($requestUri, 'session_keepalive.php') !== false ||
                (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' && strpos($requestUri, 'keepalive') !== false)) {
                $isKeepAliveRequest = true;
            }
            
            // تعطيل فحص timeout - لا نهي الجلسة أبداً بناءً على الخمول
            // تحديث آخر نشاط دائماً
            $_SESSION['last_activity'] = time();
            if (!$isKeepAliveRequest) {
                $_SESSION['last_activity_previous'] = time();
            } elseif (!isset($_SESSION['last_activity_previous'])) {
                $_SESSION['last_activity_previous'] = time();
            }
            
            // تحديث session cookie دائماً لضمان بقاء الجلسة صالحة
            if (!headers_sent() && session_id()) {
                $isHttps = (
                    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                    (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443')
                );
                setcookie(session_name(), session_id(), [
                    'expires' => time() + $sessionLifetime,
                    'path' => '/',
                    'domain' => '',
                    'secure' => $isHttps,
                    'httponly' => true,
                    'samesite' => $isHttps ? 'None' : 'Lax',
                ]);
            }
        } else {
            // المستخدم غير مسجل دخول - فقط تحديث آخر نشاط
            $_SESSION['last_activity'] = time();
        }
}

/**
 * تجديد معرف الجلسة بعد تسجيل الدخول
 * متوافق مع النظام الحالي
 * محسّن لحفظ CSRF token أثناء إعادة توليد الجلسة
 */
function regenerateSessionAfterLogin() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        // حفظ CSRF token الحالي قبل إعادة توليد الجلسة
        $currentCsrfToken = $_SESSION['csrf_token'] ?? null;
        if ($currentCsrfToken) {
            $_SESSION['csrf_token_previous'] = $currentCsrfToken;
            $_SESSION['csrf_token_previous_time'] = time();
        }
        
        session_regenerate_id(true);
        $_SESSION['regenerated_at'] = time();
        $_SESSION['last_activity'] = time();
        
        // تجديد CSRF token بعد إعادة توليد الجلسة
        if (function_exists('generateCSRFToken')) {
            generateCSRFToken(true);
        }
    }
}

/**
 * تنظيف الجلسات القديمة (اختياري - لتوفير المساحة)
 */
function cleanupOldSessions() {
    $sessionPath = __DIR__ . '/../tmp/sessions';
    if (!is_dir($sessionPath)) {
        return;
    }
    
    $files = glob($sessionPath . '/sess_*');
    $now = time();
    $cleaned = 0;
    
    foreach ($files as $file) {
        // حذف الجلسات الأقدم من ساعة
        if (filemtime($file) < ($now - 3600)) {
            @unlink($file);
            $cleaned++;
        }
    }
    
    return $cleaned;
}
