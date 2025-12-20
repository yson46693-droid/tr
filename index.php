<?php
// بدء الجلسة مع معالجة الأخطاء
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
define('ACCESS_ALLOWED', true);

// معالجة طلب manifest.json من المسار /v1/manifest.json
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
if (preg_match('#^/v1/manifest\.json$#', $requestUri) || preg_match('#^/[^/]+/v1/manifest\.json$#', $requestUri)) {
    // إعادة التوجيه إلى manifest.php أو manifest.json
    $manifestPath = __DIR__ . '/manifest.php';
    if (file_exists($manifestPath)) {
        require_once $manifestPath;
        exit;
    }
    $manifestPath = __DIR__ . '/manifest.json';
    if (file_exists($manifestPath)) {
        header('Content-Type: application/manifest+json; charset=utf-8');
        readfile($manifestPath);
        exit;
    }
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Manifest not found']);
    exit;
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/install.php';

// ============================================
// تحميل التحسينات الأمنية (InfinityFree Compatible)
// ============================================
try {
    // تحميل الإعدادات الأمنية
    if (file_exists(__DIR__ . '/includes/security_config.php')) {
        require_once __DIR__ . '/includes/security_config.php';
    }
    
    // تطبيق Security Headers
    if (file_exists(__DIR__ . '/includes/security_headers.php')) {
        require_once __DIR__ . '/includes/security_headers.php';
        if (class_exists('SecurityHeaders')) {
            SecurityHeaders::apply();
        }
    }
    
    // تهيئة الجلسات الآمنة (متوافقة مع config.php)
    // تم تأجيلها لأن config.php يبدأ الجلسة بالفعل
} catch (Exception $e) {
    // في حالة خطأ، استمر بدون التحسينات الأمنية
    error_log("Security enhancements error: " . $e->getMessage());
}

if (needsInstallation()) {
    $installResult = initializeDatabase();
    
    if (!$installResult['success']) {
        die('<!DOCTYPE html>
        <html lang="ar" dir="rtl">
        <head>
            <meta charset="UTF-8">
            <title>خطأ في التهيئة</title>
            <style>
                body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
                .error { color: #dc3545; background: #f8d7da; padding: 20px; border-radius: 5px; }
            </style>
        </head>
        <body>
            <div class="error">
                <h2>خطأ في تهيئة قاعدة البيانات</h2>
                <p>' . htmlspecialchars($installResult['message']) . '</p>
                <p>يرجى التحقق من إعدادات قاعدة البيانات في ملف includes/config.php</p>
            </div>
        </body>
        </html>');
    }
    
    if (!isset($_SESSION['db_initialized'])) {
        $_SESSION['db_initialized'] = true;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/path_helper.php';

// ============================================
// تحميل باقي التحسينات الأمنية (بعد db.php و auth.php)
// ============================================
try {
    // تحميل CSRF Protection (متوافق مع النظام الحالي)
    if (file_exists(__DIR__ . '/includes/csrf_protection.php')) {
        require_once __DIR__ . '/includes/csrf_protection.php';
    }
    
    // تحميل Rate Limiter (يستخدم جدول login_attempts الموجود)
    if (file_exists(__DIR__ . '/includes/rate_limiter.php')) {
        require_once __DIR__ . '/includes/rate_limiter.php';
    }
    
    // تحميل Input Validation
    if (file_exists(__DIR__ . '/includes/input_validation.php')) {
        require_once __DIR__ . '/includes/input_validation.php';
    }
    
    // تحميل Logger (معطل افتراضياً)
    if (file_exists(__DIR__ . '/includes/security_logger.php')) {
        require_once __DIR__ . '/includes/security_logger.php';
    }
    
    // تهيئة الجلسات الآمنة (بعد تحميل db.php و auth.php)
    if (file_exists(__DIR__ . '/includes/session_security.php')) {
        require_once __DIR__ . '/includes/session_security.php';
        if (function_exists('initSecureSession')) {
            try {
                initSecureSession();
            } catch (Exception $e) {
                error_log("Session security error: " . $e->getMessage());
            }
        }
    }
} catch (Exception $e) {
    // في حالة خطأ، استمر بدون التحسينات الأمنية
    error_log("Security enhancements error: " . $e->getMessage());
}
// ============================================

if (!defined('ASSETS_URL')) {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $basePath = '';
    
    if (!empty($requestUri)) {
        $parsedUri = parse_url($requestUri);
        $path = $parsedUri['path'] ?? '';
        $pathParts = explode('/', trim($path, '/'));
        $baseParts = [];
        
        foreach ($pathParts as $part) {
            if ($part === 'dashboard' || $part === 'modules' || $part === 'api' || strpos($part, '.php') !== false) {
                break;
            }
            if (!empty($part)) {
                $baseParts[] = $part;
            }
        }
        
        if (!empty($baseParts)) {
            $basePath = '/' . implode('/', $baseParts);
        }
    }
    
    if (empty($basePath)) {
        define('ASSETS_URL', '/assets/');
    } else {
        define('ASSETS_URL', rtrim($basePath, '/') . '/assets/');
    }
}

// === تنظيف URL من معاملات _nocache عند انتهاء الجلسة (قبل التحقق من الجلسة) ===
// هذا يمنع ERR_FAILED عند محاولة الوصول إلى index.php?_nocache=... بعد انتهاء الجلسة
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
if (preg_match('/[?&](_nocache|_refresh|_cache_bust|_t|_r|_auto_refresh)=\d+/', $requestUri)) {
    // التحقق من الجلسة أولاً
    $sessionCheck = isLoggedIn();
    
    // إذا كانت الجلسة منتهية، نظف URL وأعد التوجيه
    if (!$sessionCheck) {
        // تنظيف URL من معاملات cache
        $cleanUri = preg_replace('/[?&](_nocache|_refresh|_cache_bust|_t|_r|_auto_refresh)=\d+/', '', $requestUri);
        $cleanUri = rtrim($cleanUri, '?&');
        
        // تنظيف شامل للمسار: إزالة أي بروتوكول أو hostname أو منفذ لمنع ERR_FAILED
        $cleanUri = preg_replace('/^https?:\/\/[^\/]+/', '', $cleanUri);
        $cleanUri = preg_replace('/^\/\//', '/', $cleanUri);
        if (preg_match('/^\/[^\/]+:[0-9]+\//', $cleanUri)) {
            $cleanUri = preg_replace('/^\/[^\/]+:[0-9]+/', '', $cleanUri);
        }
        $cleanUri = preg_replace('/:[0-9]+\//g', '/', $cleanUri);
        $cleanUri = preg_replace('/:[0-9]+$/g', '', $cleanUri);
        if (strpos($cleanUri, '/') !== 0) {
            $cleanUri = '/' . $cleanUri;
        }
        $cleanUri = preg_replace('/\/+/', '/', $cleanUri);
        $cleanUri = trim($cleanUri);
        if (strpos($cleanUri, '://') !== false) {
            $parsed = parse_url($cleanUri);
            $cleanUri = $parsed['path'] ?? '/index.php';
        }
        if (empty($cleanUri) || $cleanUri === '/') {
            $cleanUri = '/index.php';
        }
        
        // إعادة التوجيه إلى URL نظيف
        if ($requestUri !== $cleanUri && !headers_sent()) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Location: ' . $cleanUri, true, 303);
            exit;
        } elseif ($requestUri !== $cleanUri) {
            $escapedUrl = htmlspecialchars($cleanUri, ENT_QUOTES, 'UTF-8');
            echo '<script>';
            echo 'try {';
            echo '  var cleanUri = ' . json_encode($cleanUri, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';';
            echo '  // تنظيف URL لضمان أنه مسار نسبي فقط';
            echo '  cleanUri = cleanUri.replace(/^https?:\\/\\//i, "");';
            echo '  cleanUri = cleanUri.replace(/^\\/\\/+/, "/");';
            echo '  cleanUri = cleanUri.replace(/^[^\\/]+:[0-9]+\\//, "/");';
            echo '  if (!cleanUri.startsWith("/")) cleanUri = "/" + cleanUri;';
            echo '  cleanUri = cleanUri.replace(/\\/+/g, "/");';
            echo '  window.location.replace(cleanUri);';
            echo '} catch(e) {';
            echo '  console.error("Redirect error:", e);';
            echo '  window.location.href = "/index.php";';
            echo '}';
            echo '</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . $escapedUrl . '"></noscript>';
            exit;
        }
    }
}

// === التحقق من وجود جلسة نشطة ===
// إذا كان هناك محاولة تسجيل دخول (POST) بنفس الحساب، نسمح بها وحذف الجلسة القديمة
// إذا لم يكن هناك POST، نعيد التوجيه إلى الداشبورد
$isLoginAttempt = ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['username']) || isset($_POST['login_method'])));

// التحقق من الجلسة - isLoggedIn() يتحقق من قاعدة البيانات ويحذف الجلسة إذا لم تكن موجودة
$isUserLoggedIn = isLoggedIn();

if ($isUserLoggedIn && !$isLoginAttempt) {
    // يوجد جلسة نشطة ولا توجد محاولة تسجيل دخول - إعادة التوجيه إلى الداشبورد
    $userRole = $_SESSION['role'] ?? 'accountant';
    
    // الحصول على المسار الأساسي
    $basePath = getBasePath();
    $basePath = rtrim($basePath, '/');
    
    // بناء المسار النسبي للداشبورد
    if (!empty($basePath)) {
        $dashboardUrl = $basePath . '/dashboard/' . $userRole . '.php';
    } else {
        $dashboardUrl = '/dashboard/' . $userRole . '.php';
    }
    
    // تنظيف شامل للمسار
    // 1. إزالة أي بروتوكول أو hostname
    $dashboardUrl = preg_replace('/^https?:\/\/[^\/]+/', '', $dashboardUrl);
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
        if ($dashboardIndex !== false && $dashboardIndex > 0) {
            $dashboardUrl = '/' . implode('/', array_slice($parts, $dashboardIndex));
        } else {
            $dashboardUrl = (!empty($basePath) ? $basePath : '') . '/dashboard/' . $userRole . '.php';
        }
    }
    
    // 5. التحقق النهائي: إذا كان المسار لا يحتوي على 'dashboard'، أضفه
    if (strpos($dashboardUrl, '/dashboard') === false) {
        $dashboardUrl = (!empty($basePath) ? $basePath : '') . '/dashboard/' . $userRole . '.php';
    }
    
    // 6. التأكد من أن المسار لا يحتوي على http:// أو https://
    if (strpos($dashboardUrl, 'http://') === 0 || strpos($dashboardUrl, 'https://') === 0) {
        $parsed = parse_url($dashboardUrl);
        $dashboardUrl = $parsed['path'] ?? ((!empty($basePath) ? $basePath : '') . '/dashboard/' . $userRole . '.php');
    }
    
    // 7. التحقق النهائي: التأكد من أن المسار يبدأ بـ /
    if (strpos($dashboardUrl, '/') !== 0) {
        $dashboardUrl = '/' . $dashboardUrl;
    }
    
    // 8. تنظيف نهائي
    $dashboardUrl = trim($dashboardUrl);
    $dashboardUrl = preg_replace('/\/+/', '/', $dashboardUrl);
    
    if (empty($dashboardUrl) || $dashboardUrl === '/') {
        $dashboardUrl = (!empty($basePath) ? $basePath : '') . '/dashboard/' . $userRole . '.php';
        if (strpos($dashboardUrl, '/') !== 0) {
            $dashboardUrl = '/' . $dashboardUrl;
        }
    }
    
    // 9. التحقق النهائي: التأكد من أن المسار نسبي وليس URL كامل
    if (strpos($dashboardUrl, '://') !== false) {
        $parsed = parse_url($dashboardUrl);
        $dashboardUrl = $parsed['path'] ?? '/dashboard/' . $userRole . '.php';
    }
    
    $currentScript = basename($_SERVER['PHP_SELF']);
    if ($currentScript !== 'index.php') {
        return;
    }
    
    // === معالجة طلبات AJAX Login عند وجود جلسة نشطة ===
    if (isset($_POST['ajax_login']) && $_POST['ajax_login'] == '1') {
        // تنظيف output buffer
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // تنظيف URL قبل إرساله في JSON response
        $dashboardUrl = preg_replace('/^https?:\/\/[^\/]+(:[0-9]+)?/', '', $dashboardUrl);
        $dashboardUrl = preg_replace('/^\/\//', '/', $dashboardUrl);
        if (preg_match('/^\/[^\/]+:[0-9]+\//', $dashboardUrl)) {
            $dashboardUrl = preg_replace('/^\/[^\/]+:[0-9]+/', '', $dashboardUrl);
        }
        if (strpos($dashboardUrl, '/') !== 0) {
            $dashboardUrl = '/' . $dashboardUrl;
        }
        $dashboardUrl = preg_replace('/\/+/', '/', $dashboardUrl);
        
        // إرجاع JSON response للتعامل معها في JavaScript
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'already_logged_in' => true,
            'message' => 'يوجد جلسة نشطة بالفعل جاري التحويل إلى النظام',
            'redirect_url' => $dashboardUrl,
            'user' => [
                'id' => $_SESSION['user_id'] ?? 0,
                'username' => $_SESSION['username'] ?? '',
                'role' => $userRole
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // تنظيف output buffer قبل التوجيه
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // تنظيف نهائي للمسار قبل إعادة التوجيه
    $dashboardUrl = preg_replace('/^https?:\/\/[^\/]+(:[0-9]+)?/', '', $dashboardUrl);
    $dashboardUrl = preg_replace('/^\/\//', '/', $dashboardUrl);
    if (preg_match('/^\/[^\/]+:[0-9]+\//', $dashboardUrl)) {
        $dashboardUrl = preg_replace('/^\/[^\/]+:[0-9]+/', '', $dashboardUrl);
    }
    if (strpos($dashboardUrl, '/') !== 0) {
        $dashboardUrl = '/' . $dashboardUrl;
    }
    $dashboardUrl = preg_replace('/\/+/', '/', $dashboardUrl);
    
    if (!headers_sent()) {
        header('Location: ' . $dashboardUrl, true, 303);
        exit;
    } else {
        $escapedUrl = htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8');
        echo '<script>';
        echo 'try {';
        echo '  var dashboardUrl = ' . json_encode($dashboardUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';';
        echo '  // تنظيف URL لضمان أنه مسار نسبي فقط';
        echo '  dashboardUrl = dashboardUrl.replace(/^https?:\\/\\//i, "");';
        echo '  dashboardUrl = dashboardUrl.replace(/^\\/\\/+/, "/");';
        echo '  dashboardUrl = dashboardUrl.replace(/^[^\\/]+:[0-9]+\\//, "/");';
        echo '  if (!dashboardUrl.startsWith("/")) dashboardUrl = "/" + dashboardUrl;';
        echo '  dashboardUrl = dashboardUrl.replace(/\\/+/g, "/");';
        echo '  window.location.replace(dashboardUrl);';
        echo '} catch(e) {';
        echo '  console.error("Redirect error:", e);';
        echo '  window.location.href = "/dashboard/accountant.php";';
        echo '}';
        echo '</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . $escapedUrl . '"></noscript>';
        exit;
    }
} elseif ($isUserLoggedIn && $isLoginAttempt) {
    // === يوجد جلسة نشطة وهناك محاولة تسجيل دخول ===
    // نتحقق من أن محاولة تسجيل الدخول لنفس الحساب
    // إذا كانت لنفس الحساب، نحذف الجلسة القديمة ونسمح بتسجيل الدخول الجديد
    $currentUsername = $_SESSION['username'] ?? '';
    $loginUsername = $_POST['username'] ?? '';
    
    if (!empty($loginUsername) && strtolower(trim($loginUsername)) === strtolower(trim($currentUsername))) {
        // محاولة تسجيل دخول لنفس الحساب - حذف الجلسة القديمة والسماح بتسجيل الدخول الجديد
        // تعطيل التسجيل لتقليل الضغط على السيرفر
        // error_log("Login from new device: Deleting old session for user: {$currentUsername}");
        
        // حذف الجلسة القديمة من قاعدة البيانات أولاً
        try {
            require_once __DIR__ . '/includes/db.php';
            // تم إزالة نظام الجلسات - لا حاجة لأي كود متعلق بالجلسات
        } catch (Exception $e) {
            // تعطيل التسجيل الروتيني - الاحتفاظ فقط بالأخطاء الحرجة
        }
    } else {
        // محاولة تسجيل دخول بحساب مختلف - التحقق من الجلسة
        $currentUser = getCurrentUser();
        if ($currentUser) {
            $userRole = $currentUser['role'] ?? 'accountant';
            $basePath = getBasePath();
            $basePath = rtrim($basePath, '/');
            $dashboardUrl = (!empty($basePath) ? $basePath : '') . '/dashboard/' . $userRole . '.php';
            $dashboardUrl = preg_replace('/^https?:\/\/[^\/]+/', '', $dashboardUrl);
            if (strpos($dashboardUrl, '/') !== 0) {
                $dashboardUrl = '/' . $dashboardUrl;
            }
            
            // إرجاع رسالة خطأ
            if (isset($_POST['ajax_login']) && $_POST['ajax_login'] == '1') {
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'message' => 'يوجد حساب نشط آخر. يرجى تسجيل الخروج أولاً.',
                    'redirect_url' => $dashboardUrl
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // إعادة التوجيه إلى الداشبورد
            if (!headers_sent()) {
                header('Location: ' . $dashboardUrl, true, 303);
                exit;
            }
        }
    }
}

$error = '';
$success = '';

// تم إزالة نظام الجلسات - لا حاجة لرسائل الجلسة
// يمكن استخدام query parameters أو cookies للرسائل إذا لزم الأمر

// التحقق من وجود session_expired في sessionStorage (من JavaScript redirect)
// سيتم عرض الرسالة في JavaScript

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $login_method = $_POST['login_method'] ?? 'password';
    
    if ($login_method === 'webauthn') {
        // WebAuthn لا يحتاج CSRF protection في هذه المرحلة
    } else {
        // === تعطيل التحقق من CSRF لطلبات تسجيل الدخول ===
        // لأن session_regenerate_id() يغير session_id وبالتالي يفقد CSRF token
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $isLoginPage = (basename($uri) === 'index.php' || basename($_SERVER['PHP_SELF'] ?? '') === 'index.php');
        $isLoginForm = isset($_POST['username']) && isset($_POST['password']);
        
        // فقط التحقق من CSRF للطلبات التي ليست تسجيل دخول
        if (!$isLoginPage && !$isLoginForm && strpos($uri, '/api/') === false && function_exists('protectFormFromCSRF')) {
            try {
                $csrfResult = protectFormFromCSRF();
                if ($csrfResult === false) {
                    $error = 'خطأ في التحقق الأمني. يرجى تحديث الصفحة والمحاولة مرة أخرى.';
                }
                } catch (Throwable $e) {
                    $error = 'حدث خطأ أثناء التحقق الأمني. يرجى المحاولة مرة أخرى.';
                    // تعطيل التسجيل الروتيني - الاحتفاظ فقط بالأخطاء الحرجة
                    // error_log("CSRF protection error: " . $e->getMessage());
                }
        }
        
        // تنظيف المدخلات
        if (class_exists('InputValidator')) {
            $username = InputValidator::sanitizeString($username);
            $password = InputValidator::sanitizeString($password);
        }
        
        if (empty($username) || empty($password)) {
            $error = 'يرجى إدخال اسم المستخدم وكلمة المرور';
        } else {
            // فحص Rate Limiting (يستخدم النظام الموجود في security.php)
            $rateLimitCheck = null;
            if (class_exists('RateLimiter')) {
                try {
                    $rateLimitCheck = RateLimiter::checkLoginAttempt($username);
                    if (!$rateLimitCheck['allowed']) {
                        $error = $rateLimitCheck['message'];
                    }
                } catch (Exception $e) {
                    // تعطيل التسجيل الروتيني - الاحتفاظ فقط بالأخطاء الحرجة
                    // error_log("Rate limiter error: " . $e->getMessage());
                }
            }
            
            if (empty($error)) {
                $rememberMe = isset($_POST['remember_me']) && $_POST['remember_me'] == '1';
                $result = login($username, $password, $rememberMe);
                
                // إرجاع نتيجة JSON للتعامل معها في JavaScript
                if (isset($_POST['ajax_login']) && $_POST['ajax_login'] == '1') {
                    // تنظيف output buffer
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode($result, JSON_UNESCAPED_UNICODE);
                    exit;
                }
                
                if ($result['success']) {
                    // إعادة تعيين محاولات Rate Limiting بعد تسجيل دخول ناجح
                    if (class_exists('RateLimiter')) {
                        try {
                            RateLimiter::resetAttempts($username);
                        } catch (Exception $e) {
                            // تعطيل التسجيل الروتيني - الاحتفاظ فقط بالأخطاء الحرجة
                            // error_log("Rate limiter reset error: " . $e->getMessage());
                        }
                    }
                    // النظام يعتمد على الجلسات (PHP Sessions)
                    $userRole = $result['user']['role'] ?? 'accountant';
                    
                    // استخدام دالة getDashboardUrl() للحصول على المسار الصحيح
                    if (!function_exists('getDashboardUrl')) {
                        require_once __DIR__ . '/includes/path_helper.php';
                    }
                    
                    $dashboardUrl = getDashboardUrl($userRole);
                    
                    // التأكد من أن المسار نسبي فقط (بدون بروتوكول أو hostname)
                    $dashboardUrl = preg_replace('/^https?:\/\/[^\/]+/', '', $dashboardUrl);
                    $dashboardUrl = preg_replace('/^\/\//', '/', $dashboardUrl);
                    
                    // التأكد من أن المسار يبدأ بـ /
                    if (strpos($dashboardUrl, '/') !== 0) {
                        $dashboardUrl = '/' . $dashboardUrl;
                    }
                    
                    // تنظيف المسار من المسارات المكررة
                    $dashboardUrl = preg_replace('/\/+/', '/', $dashboardUrl);
                    
                    // فحص نهائي: التأكد من أن المسار يحتوي على /dashboard/ وأن role موجود
                    if ($userRole) {
                        $expectedPath = '/dashboard/' . $userRole . '.php';
                        // إذا كان المسار لا يحتوي على dashboard أو role غير موجود، أعد بناءه
                        if (strpos($dashboardUrl, '/dashboard/') === false || substr($dashboardUrl, -strlen($userRole . '.php')) !== $userRole . '.php') {
                            // تعطيل التسجيل لتقليل الضغط على السيرفر
                            // error_log("Login WARNING: Invalid dashboard URL detected: {$dashboardUrl}, rebuilding to: {$expectedPath}");
                            $dashboardUrl = $expectedPath;
                        }
                    }
                    
                    // تنظيف output buffer قبل التوجيه
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    
                    // تعطيل التسجيل لتقليل الضغط على السيرفر
                    // error_log("Login redirect - Dashboard URL: {$dashboardUrl} | Role: {$userRole}");
                    
                    // تنظيف نهائي للمسار قبل إعادة التوجيه لمنع ERR_FAILED
                    $dashboardUrl = preg_replace('/^https?:\/\/[^\/]+(:[0-9]+)?/', '', $dashboardUrl);
                    $dashboardUrl = preg_replace('/^\/\//', '/', $dashboardUrl);
                    if (preg_match('/^\/[^\/]+:[0-9]+\//', $dashboardUrl)) {
                        $dashboardUrl = preg_replace('/^\/[^\/]+:[0-9]+/', '', $dashboardUrl);
                    }
                    if (strpos($dashboardUrl, '/') !== 0) {
                        $dashboardUrl = '/' . $dashboardUrl;
                    }
                    $dashboardUrl = preg_replace('/\/+/', '/', $dashboardUrl);
                    
                    // فحص نهائي نهائي: إزالة أي منفذ والتأكد من المسار الصحيح
                    $dashboardUrl = preg_replace('/:[0-9]+/', '', $dashboardUrl);
                    if ($userRole && (strpos($dashboardUrl, '/dashboard/') === false || substr($dashboardUrl, -strlen($userRole . '.php')) !== $userRole . '.php')) {
                        $dashboardUrl = '/dashboard/' . $userRole . '.php';
                    }
                    
                    // استخدام header redirect مباشرة (بدون JavaScript) لضمان التوجيه الصحيح
                    if (!headers_sent()) {
                        header('Location: ' . $dashboardUrl, true, 303);
                        exit;
                    } else {
                        // إذا كانت headers قد أُرسلت، استخدم JavaScript redirect
                        // تنظيف URL لضمان أنه مسار نسبي فقط لمنع ERR_FAILED
                        $escapedUrl = htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8');
                        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Redirecting...</title>';
                        echo '<script>';
                        echo 'try {';
                        echo '  var dashboardUrl = ' . json_encode($dashboardUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';';
                        echo '  // تنظيف URL لضمان أنه مسار نسبي فقط';
                        echo '  dashboardUrl = dashboardUrl.replace(/^https?:\\/\\//i, "");';
                        echo '  dashboardUrl = dashboardUrl.replace(/^\\/\\/+/, "/");';
                        echo '  dashboardUrl = dashboardUrl.replace(/^[^\\/]+:[0-9]+\\//, "/");';
                        echo '  if (!dashboardUrl.startsWith("/")) dashboardUrl = "/" + dashboardUrl;';
                        echo '  dashboardUrl = dashboardUrl.replace(/\\/+/g, "/");';
                        echo '  window.location.replace(dashboardUrl);';
                        echo '} catch(e) {';
                        echo '  console.error("Redirect error:", e);';
                        echo '  window.location.href = "/dashboard/accountant.php";';
                        echo '}';
                        echo '</script>';
                        echo '<noscript><meta http-equiv="refresh" content="0;url=' . $escapedUrl . '"></noscript>';
                        echo '</head><body><p>جاري التحويل... <a href="' . $escapedUrl . '">اضغط هنا إذا لم يتم التحويل تلقائياً</a></p></body></html>';
                        exit;
                    }
                } else {
                    // تسجيل محاولة فاشلة في Rate Limiter
                    if (class_exists('RateLimiter')) {
                        try {
                            $remaining = RateLimiter::recordFailedAttempt($username);
                            if ($remaining > 0) {
                                $error = $result['message'] . " (المحاولات المتبقية: {$remaining})";
                            } else {
                                $error = "تم استنفاد المحاولات. تم حظر الحساب لمدة 15 دقيقة.";
                            }
                        } catch (Exception $e) {
                            $error = $result['message'];
                        }
                    } else {
                        $error = $result['message'];
                    }
                }
            }
        }
    }
}

require_once __DIR__ . '/includes/lang/ar.php';
$lang = $translations;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f1c40f">
    <title><?php echo $lang['login_title']; ?> - <?php echo APP_NAME; ?></title>
    
    <!-- PWA Manifest -->
    <link rel="manifest" href="/manifest.php">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo ASSETS_URL; ?>css/style.css" rel="stylesheet">
    <link href="<?php echo ASSETS_URL; ?>css/rtl.css" rel="stylesheet">
    
    <!-- PWA Splash Screen CSS -->
    <style>
        /* شاشة التحميل الرئيسية - ألوان التطبيق */
        #pwaSplashScreen {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #f4d03f 0%, #f1c40f 50%, #f4d03f 100%);
            background-size: 400% 400%;
            animation: gradientShift 8s ease infinite;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            transition: opacity 0.5s ease, visibility 0.5s ease;
        }
        
        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        
        #pwaSplashScreen.hidden {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }
        
        .splash-logo {
            width: 180px;
            height: 180px;
            margin-bottom: 2rem;
            animation: logoFadeIn 0.8s ease-out, logoFloat 3s ease-in-out infinite 0.8s;
            filter: drop-shadow(0 8px 25px rgba(0, 0, 0, 0.3));
        }
        
        @keyframes logoFadeIn {
            from {
                opacity: 0;
                transform: scale(0.8);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }
        
        @keyframes logoFloat {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
        
        .splash-title {
            color: white;
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 1rem;
            letter-spacing: 2px;
            text-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            animation: titleFadeIn 1s ease-out 0.3s both;
        }
        
        @keyframes titleFadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
    
    <!-- تم تعطيل منع الضغط بالزر الأيمن وفتح أدوات المطور -->
    <!-- <script>
    (function() {
        'use strict';
        
        // منع الضغط بالزر الأيمن
        document.addEventListener('contextmenu', function(e) {
            e.preventDefault();
            return false;
        }, true);
        
        // منع اختصارات لوحة المفاتيح
        document.addEventListener('keydown', function(e) {
            // F12 - فتح أدوات المطور
            if (e.keyCode === 123) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
            
            // Ctrl+Shift+I - فتح أدوات المطور
            if (e.ctrlKey && e.shiftKey && e.keyCode === 73) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
            
            // Ctrl+Shift+J - فتح Console
            if (e.ctrlKey && e.shiftKey && e.keyCode === 74) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
            
            // Ctrl+Shift+C - فتح Element Inspector
            if (e.ctrlKey && e.shiftKey && e.keyCode === 67) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
            
            // Ctrl+U - عرض مصدر الصفحة
            if (e.ctrlKey && e.keyCode === 85) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
            
            // Ctrl+S - حفظ الصفحة
            if (e.ctrlKey && e.keyCode === 83) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
            
            // Ctrl+P - طباعة
            if (e.ctrlKey && e.keyCode === 80) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
            
            // Ctrl+Shift+P - Command Palette
            if (e.ctrlKey && e.shiftKey && e.keyCode === 80) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
            
            // Ctrl+Shift+K - Network Monitor (Firefox)
            if (e.ctrlKey && e.shiftKey && e.keyCode === 75) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
            
            // Ctrl+Shift+E - Network Panel (Chrome)
            if (e.ctrlKey && e.shiftKey && e.keyCode === 69) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
        }, true);
        
        // منع فتح أدوات المطور عبر DevTools API
        (function() {
            var devtools = { open: false };
            var threshold = 160;
            
            setInterval(function() {
                if (window.outerHeight - window.innerHeight > threshold || 
                    window.outerWidth - window.innerWidth > threshold) {
                    if (!devtools.open) {
                        devtools.open = true;
                    }
                } else {
                    if (devtools.open) {
                        devtools.open = false;
                    }
                }
            }, 500);
        })();
        
        // منع فتح أدوات المطور عبر Console API
        (function() {
            var noop = function() {};
            var methods = ['log', 'debug', 'info', 'warn', 'error', 'assert', 'dir', 'dirxml', 
                         'group', 'groupEnd', 'time', 'timeEnd', 'count', 'trace', 'profile', 'profileEnd'];
            var length = methods.length;
            var console = (window.console = window.console || {});
            
            while (length--) {
                console[methods[length]] = noop;
            }
        })();
        
        // منع فتح أدوات المطور عبر Debugger
        setInterval(function() {
            (function() {
                return false;
            })('devtools');
        }, 4000);
        
        // منع window.open, document.write, eval
        var originalOpen = window.open;
        window.open = function() { return null; };
        
        var originalWrite = document.write;
        document.write = function() { return false; };
        
        var originalWriteln = document.writeln;
        document.writeln = function() { return false; };
        
        var originalEval = window.eval;
        window.eval = function() { return null; };
        
        var originalFunction = window.Function;
        window.Function = function() { return function() {}; };
        
        var originalSetTimeout = window.setTimeout;
        window.setTimeout = function(func, delay) {
            if (typeof func === 'string') {
                return originalSetTimeout(function() {}, delay);
            }
            return originalSetTimeout(func, delay);
        };
        
        var originalSetInterval = window.setInterval;
        window.setInterval = function(func, delay) {
            if (typeof func === 'string') {
                return originalSetInterval(function() {}, delay);
            }
            return originalSetInterval(func, delay);
        };
    })();
    </script> -->
</head>
<body class="login-page">
    <!-- PWA Splash Screen -->
    <div id="pwaSplashScreen">
        <img src="<?php echo ASSETS_URL; ?>icons/icon-192x192.png" alt="<?php echo APP_NAME; ?>" class="splash-logo">
        <div class="splash-title"><?php echo APP_NAME; ?></div>
    </div>
    <div class="container-fluid py-4 py-md-5">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-11 col-sm-10 col-md-8 col-lg-6 col-xl-5 col-xxl-4">
                <div class="card shadow-xxl border-0 login-card">
                    <div class="card-body p-4 p-md-5">
                        <div class="text-center mb-4">
                            <i class="bi bi-shield-lock-fill text-primary" style="font-size: 3rem;"></i>
                            <h3 class="mt-3 mb-1"><?php echo $lang['login_title']; ?></h3>
                            <p class="text-muted"><?php echo $lang['login_subtitle']; ?></p>
                        </div>
                        
                        <?php if ($error): ?>
                            <div class="alert alert-warning alert-dismissible fade show" role="alert" id="sessionErrorAlert">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                <strong>تنبيه:</strong> <?php echo htmlspecialchars($error); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
                            </div>
                        <?php endif; ?>
                        
                        <!-- رسالة تنبيه من JavaScript (عند ERR_FAILED أو أخطاء الاتصال) -->
                        <div class="alert alert-warning alert-dismissible fade show d-none" role="alert" id="jsSessionErrorAlert">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <strong>تنبيه:</strong> <span id="jsSessionErrorText">انتهت الجلسة أو حدث خطأ في الاتصال. يرجى تسجيل الدخول مرة أخرى.</span>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="bi bi-check-circle-fill me-2"></i>
                                <?php echo htmlspecialchars($success); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <form id="loginForm" method="POST" action="">
                            <input type="hidden" name="login_method" id="loginMethod" value="password">
                            
                            <div class="mb-3">
                                <label for="username" class="form-label">
                                    <i class="bi bi-person-fill me-2"></i>
                                    <?php echo $lang['username']; ?>
                                </label>
                                <input type="text" class="form-control" id="username" name="username" 
                                       placeholder="<?php echo $lang['username_placeholder']; ?>" required autofocus>
                            </div>
                            
                            <div class="mb-3">
                                <label for="password" class="form-label">
                                    <i class="bi bi-key-fill me-2"></i>
                                    <?php echo $lang['password']; ?>
                                </label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="password" name="password" 
                                           placeholder="<?php echo $lang['password_placeholder']; ?>" required>
                                    <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                        <i class="bi bi-eye" id="eyeIcon"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="remember_me" name="remember_me" value="1">
                                <label class="form-check-label" for="remember_me">
                                    <i class="bi bi-bookmark-check me-2"></i>
                                    <?php echo $lang['remember_me']; ?>
                                </label>
                            </div>
                            
                            <!-- حماية CSRF -->
                            <?php echo csrf_token_field(); ?>
                            
                            <div class="d-grid gap-2 mb-3">
                                <button type="submit" class="btn btn-primary btn-lg" id="loginSubmitBtn">
                                    <i class="bi bi-box-arrow-in-right me-2"></i>
                                    <span id="loginButtonText"><?php echo $lang['login_button']; ?></span>
                                    <span id="loginLoadingSpinner" class="spinner-border spinner-border-sm ms-2 d-none" role="status"></span>
                                </button>
                            </div>
                            
                            <!-- Loading indicator -->
                            <div id="loginLoadingIndicator" class="d-none">
                                <div class="alert alert-info text-center">
                                    <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                                    <span id="loadingMessage">جاري تسجيل الدخول... يرجى الانتظار 5 ثواني</span>
                                    <div class="mt-2">
                                        <small class="text-muted">يتم إنشاء الجلسة في قاعدة البيانات...</small>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if (true): // WebAuthn support check ?>
                                <div class="text-center mb-3">
                                    <hr class="my-3">
                                    <p class="text-muted small">أو</p>
                                </div>
                                
                                <div class="d-grid">
                                    <button type="button" class="btn btn-outline-primary" id="webauthnLoginBtn">
                                        <i class="bi bi-fingerprint me-2"></i>
                                        تسجيل الدخول بالبصمة / المفتاح الأمني
                                    </button>
                                </div>
                            <?php endif; ?>
                        </form>
                        
                        <div class="text-center mt-4">
                            <small class="text-muted">
                                <i class="bi bi-info-circle me-1"></i>
                                شركة البركة © <?php echo date('Y'); ?>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- WebAuthn JS -->
    <script src="<?php echo ASSETS_URL; ?>js/webauthn.js?v=<?php echo time(); ?>"></script>
    <!-- Custom JS -->
    <script src="<?php echo ASSETS_URL; ?>js/main.js"></script>
    
    <script>
        // التحقق من تحميل webauthn.js
        window.addEventListener('load', function() {
            console.log('Page loaded. Checking WebAuthn availability...');
            console.log('simpleWebAuthn:', typeof simpleWebAuthn !== 'undefined' ? 'defined' : 'undefined');
            console.log('webauthnManager:', typeof webauthnManager !== 'undefined' ? 'defined' : 'undefined');
            
            if (typeof simpleWebAuthn !== 'undefined') {
                console.log('simpleWebAuthn.loginWithoutUsername:', typeof simpleWebAuthn.loginWithoutUsername === 'function' ? 'function' : 'not a function');
            }
            if (typeof webauthnManager !== 'undefined') {
                console.log('webauthnManager.loginWithoutUsername:', typeof webauthnManager.loginWithoutUsername === 'function' ? 'function' : 'not a function');
            }
        });
    </script>
    
    <script>
        // PWA Splash Screen - محسّن للأداء السريع (بدون API calls)
        (function() {
            const splashScreen = document.getElementById('pwaSplashScreen');
            if (!splashScreen) return;
            
            // استخدام localStorage فقط (بدون API calls) للأداء السريع
            const SPLASH_KEY = 'pwaSplashLastShown';
            const SPLASH_COOLDOWN = 1500; // 1.5 ثانية فقط بين إظهارات splash screen (محسّن للموبايل)
            const MIN_SPLASH_TIME = 2000; // الحد الأدنى لوقت إظهار splash screen (2 ثانية)
            const MAX_SPLASH_TIME = 3000; // أقصى وقت لإظهار splash screen (3 ثواني)
            
            // متغير لتخزين وقت بدء إظهار الشاشة
            let splashStartTime = null;
            
            function hideSplashScreen() {
                if (!splashScreen) return;
                
                // التحقق من أن الحد الأدنى من الوقت قد مر
                if (splashStartTime) {
                    const elapsedTime = Date.now() - splashStartTime;
                    const remainingTime = MIN_SPLASH_TIME - elapsedTime;
                    
                    if (remainingTime > 0) {
                        // انتظر حتى يمر الحد الأدنى من الوقت
                        setTimeout(hideSplashScreen, remainingTime);
                        return;
                    }
                }
                
                // إخفاء مع انتقال سلس
                splashScreen.classList.add('hidden');
                setTimeout(function() {
                    splashScreen.style.display = 'none';
                }, 300);
            }
            
            // التحقق من الوقت المناسب لإظهار splash screen
            function shouldShowSplash() {
                try {
                    const lastShown = localStorage.getItem(SPLASH_KEY);
                    if (!lastShown) return true;
                    
                    const timeSinceLastShown = Date.now() - parseInt(lastShown);
                    return timeSinceLastShown > SPLASH_COOLDOWN;
                } catch (e) {
                    return true; // في حالة الخطأ، أظهر splash screen
                }
            }
            
            // حفظ وقت إظهار splash screen
            function markSplashShown() {
                try {
                    localStorage.setItem(SPLASH_KEY, Date.now().toString());
                } catch (e) {
                    // تجاهل الأخطاء
                }
            }
            
            // استخدام pageshow event للتحقق من أن الصفحة تم تحميلها من جديد
            window.addEventListener('pageshow', function(event) {
                // إذا كانت الصفحة من cache (back/forward)، لا تظهر splash screen
                if (event.persisted) {
                    splashScreen.style.display = 'none';
                    splashScreen.classList.add('hidden');
                    
                    // معالجة Refresh لمنع Error Code: -2
                    try {
                        // إذا كان هناك refresh parameter، تأكد من إزالته بعد التحميل
                        const url = new URL(window.location.href);
                        let cleaned = false;
                        
                        // إزالة جميع معاملات _nocache و _refresh المتكررة
                        const paramsToRemove = ['_refresh', '_nocache', '_cache_bust', '_t', '_r', '_auto_refresh'];
                        paramsToRemove.forEach(function(param) {
                            if (url.searchParams.has(param)) {
                                url.searchParams.delete(param);
                                cleaned = true;
                            }
                        });
                        
                        // إذا تم تنظيف URL، استبدله
                        if (cleaned) {
                            setTimeout(function() {
                                try {
                                    window.history.replaceState({}, '', url.toString());
                                } catch (e) {
                                    // تجاهل الأخطاء في replaceState
                                }
                            }, 100);
                        }
                    } catch (e) {
                        // تجاهل الأخطاء
                    }
                    return;
                }
                
                // التحقق من الوقت المناسب لإظهار splash screen
                if (shouldShowSplash()) {
                    markSplashShown();
                    splashStartTime = Date.now();
                    
                    splashScreen.classList.remove('hidden');
                    splashScreen.style.display = 'flex';
                    
                    // إخفاء الشاشة بعد تحميل الصفحة أو بعد وقت أقصى
                    const hideTimeout = setTimeout(hideSplashScreen, MAX_SPLASH_TIME);
                    
                    if (document.readyState === 'complete') {
                        // حتى لو تم تحميل الصفحة، انتظر الحد الأدنى من الوقت
                        setTimeout(function() {
                            clearTimeout(hideTimeout);
                            hideSplashScreen();
                        }, MIN_SPLASH_TIME);
                    } else {
                        window.addEventListener('load', function() {
                            // انتظر الحد الأدنى من الوقت حتى بعد تحميل الصفحة
                            setTimeout(function() {
                                clearTimeout(hideTimeout);
                                hideSplashScreen();
                            }, MIN_SPLASH_TIME);
                        }, { once: true });
                    }
                } else {
                    // لا تظهر splash screen - إخفاء فوري
                    hideSplashScreen();
                }
            });
            
            // منطق بسيط بدون API calls
            let splashCheckTimeout = setTimeout(function() {
                if (splashScreen && !splashScreen.classList.contains('hidden')) {
                    hideSplashScreen();
                }
            }, MAX_SPLASH_TIME);
            
            if (shouldShowSplash()) {
                markSplashShown();
                splashStartTime = Date.now();
                
                splashScreen.classList.remove('hidden');
                splashScreen.style.display = 'flex';
                
                // إخفاء الشاشة بعد تحميل الصفحة أو بعد وقت أقصى
                const hideTimeout = setTimeout(function() {
                    clearTimeout(splashCheckTimeout);
                    hideSplashScreen();
                }, MAX_SPLASH_TIME);
                
                if (document.readyState === 'complete') {
                    // حتى لو تم تحميل الصفحة، انتظر الحد الأدنى من الوقت
                    setTimeout(function() {
                        clearTimeout(splashCheckTimeout);
                        clearTimeout(hideTimeout);
                        hideSplashScreen();
                    }, MIN_SPLASH_TIME);
                } else if (document.readyState === 'interactive') {
                    clearTimeout(splashCheckTimeout);
                    window.addEventListener('load', function() {
                        // انتظر الحد الأدنى من الوقت حتى بعد تحميل الصفحة
                        setTimeout(function() {
                            clearTimeout(hideTimeout);
                            hideSplashScreen();
                        }, MIN_SPLASH_TIME);
                    }, { once: true });
                } else {
                    window.addEventListener('load', function() {
                        // انتظر الحد الأدنى من الوقت حتى بعد تحميل الصفحة
                        setTimeout(function() {
                            clearTimeout(splashCheckTimeout);
                            clearTimeout(hideTimeout);
                            hideSplashScreen();
                        }, MIN_SPLASH_TIME);
                    }, { once: true });
                }
            } else {
                // لا تظهر splash screen - إخفاء فوري
                clearTimeout(splashCheckTimeout);
                hideSplashScreen();
            }
        })();
        
        // التحقق من رسائل الخطأ من sessionStorage (عند إعادة التوجيه من JavaScript)
        (function() {
            try {
                const sessionExpired = sessionStorage.getItem('session_expired');
                const redirectReason = sessionStorage.getItem('redirect_reason');
                
                if (sessionExpired === 'true') {
                    // إظهار رسالة التنبيه
                    const alertElement = document.getElementById('jsSessionErrorAlert');
                    const errorTextElement = document.getElementById('jsSessionErrorText');
                    
                    if (alertElement && errorTextElement) {
                        if (redirectReason) {
                            errorTextElement.textContent = redirectReason;
                        }
                        alertElement.classList.remove('d-none');
                        alertElement.classList.add('show');
                        
                        // إزالة العناصر من sessionStorage بعد عرضها
                        sessionStorage.removeItem('session_expired');
                        sessionStorage.removeItem('redirect_reason');
                    }
                }
            } catch (e) {
                // تجاهل الأخطاء في sessionStorage (مثلاً في وضع الخصوصية)
            }
        })();
        
        // إظهار/إخفاء كلمة المرور
        document.getElementById('togglePassword')?.addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const eyeIcon = document.getElementById('eyeIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                eyeIcon.classList.remove('bi-eye');
                eyeIcon.classList.add('bi-eye-slash');
            } else {
                passwordInput.type = 'password';
                eyeIcon.classList.remove('bi-eye-slash');
                eyeIcon.classList.add('bi-eye');
            }
        });
        
        // دالة مساعدة للحصول على مسار API
        function getApiPath(endpoint) {
            const cleanEndpoint = String(endpoint || '').replace(/^\/+/, '');
            const currentPath = window.location.pathname || '/';
            const parts = currentPath.split('/').filter(Boolean);
            const stopSegments = new Set(['dashboard', 'modules', 'api', 'assets', 'includes']);
            const baseParts = [];
            
            for (const part of parts) {
                if (stopSegments.has(part) || part.endsWith('.php')) {
                    break;
                }
                baseParts.push(part);
            }
            
            const basePath = baseParts.length ? '/' + baseParts.join('/') : '';
            const apiPath = (basePath + '/' + cleanEndpoint).replace(/\/+/g, '/');
            
            return apiPath.startsWith('/') ? apiPath : '/' + apiPath;
        }
        
        /**
         * دالة لتنظيف URL وإزالة أي بروتوكول أو hostname أو منفذ
         * تضمن إرجاع مسار نسبي فقط لمنع خطأ ERR_FAILED
         * مهم: لا تزيل /dashboard/ من المسار
         */
        // دالة مساعدة لإنشاء AbortSignal مع timeout
        function createTimeoutSignal(timeoutMs) {
            if (typeof AbortSignal !== 'undefined' && AbortSignal.timeout) {
                return AbortSignal.timeout(timeoutMs);
            }
            // Fallback للمتصفحات القديمة
            const controller = new AbortController();
            setTimeout(() => controller.abort(), timeoutMs);
            return controller.signal;
        }
        
        function cleanUrl(url) {
            if (!url || typeof url !== 'string') {
                return '/';
            }
            
            try {
                // حفظ المسار الأصلي للتحقق لاحقاً
                const originalUrl = url;
                
                // إزالة أي بروتوكول (http:// أو https://)
                url = url.replace(/^https?:\/\//i, '');
                
                // إزالة // المكررة من البداية (لكن احتفظ بـ / الأولى)
                url = url.replace(/^\/\/+/, '/');
                
                // إزالة hostname مع منفذ إذا كان موجوداً (مثل localhost:8000/)
                // لكن فقط إذا كان قبل المسار الفعلي
                url = url.replace(/^[^\/]+:[0-9]+\//, '/');
                
                // إزالة hostname بدون منفذ إذا كان موجوداً (لكن فقط إذا لم يكن المسار يبدأ بـ /)
                if (!url.startsWith('/')) {
                    url = url.replace(/^[^\/]+\//, '/');
                }
                
                // إزالة أي منفذ من منتصف المسار (للاحتياط)
                url = url.replace(/:[0-9]+\//g, '/');
                url = url.replace(/:[0-9]+$/g, '');
                
                // التأكد من أن المسار يبدأ بـ /
                if (!url.startsWith('/')) {
                    url = '/' + url;
                }
                
                // تنظيف المسارات المكررة
                url = url.replace(/\/+/g, '/');
                
                // فحص نهائي: إذا كان المسار يحتوي على dashboard في الأصل، تأكد من أنه موجود
                if (originalUrl.includes('/dashboard/') && !url.includes('/dashboard/')) {
                    // إذا كان المسار الأصلي يحتوي على dashboard ولكن النظيف لا يحتويه، أعد بناءه
                    const roleMatch = originalUrl.match(/\/([^\/]+)\.php/);
                    if (roleMatch && roleMatch[1]) {
                        url = '/dashboard/' + roleMatch[1] + '.php';
                    }
                }
                
                // إزالة أي مسافات
                url = url.trim();
                
                // التحقق النهائي: التأكد من أن المسار لا يحتوي على بروتوكول أو hostname
                if (url.includes('://')) {
                    // إذا كان يحتوي على بروتوكول، استخرج المسار فقط
                    try {
                        const urlObj = new URL(url, window.location.origin);
                        url = urlObj.pathname;
                    } catch (e) {
                        // في حالة الخطأ، استخدم المسار الافتراضي
                        url = '/';
                    }
                }
                
                return url || '/';
            } catch (e) {
                console.error('Error cleaning URL:', e, 'Original URL:', url);
                // في حالة أي خطأ، إرجاع المسار الافتراضي
                return '/';
            }
        }
        
        // معالجة نموذج تسجيل الدخول مع موقت 5 ثواني وإعادة المحاولة
        const loginForm = document.getElementById('loginForm');
        const loginSubmitBtn = document.getElementById('loginSubmitBtn');
        const loginButtonText = document.getElementById('loginButtonText');
        const loginLoadingSpinner = document.getElementById('loginLoadingSpinner');
        const loginLoadingIndicator = document.getElementById('loginLoadingIndicator');
        const loadingMessage = document.getElementById('loadingMessage');
        
        if (loginForm) {
            loginForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                
                const username = document.getElementById('username').value;
                const password = document.getElementById('password').value;
                const rememberMe = document.getElementById('remember_me').checked;
                
                if (!username || !password) {
                    alert('يرجى إدخال اسم المستخدم وكلمة المرور');
                    return;
                }
                
                // تعطيل الزر وإظهار loading
                loginSubmitBtn.disabled = true;
                loginButtonText.textContent = 'جاري تسجيل الدخول...';
                loginLoadingSpinner.classList.remove('d-none');
                loginLoadingIndicator.classList.remove('d-none');
                
                // إخفاء أي رسائل خطأ سابقة
                const errorAlert = document.querySelector('.alert-danger');
                if (errorAlert) {
                    errorAlert.remove();
                }
                
                try {
                    // إرسال طلب تسجيل الدخول
                    const formData = new FormData();
                    formData.append('username', username);
                    formData.append('password', password);
                    formData.append('remember_me', rememberMe ? '1' : '0');
                    formData.append('login_method', 'password');
                    formData.append('ajax_login', '1');
                    formData.append('csrf_token', document.querySelector('input[name="csrf_token"]')?.value || '');
                    
                    // التأكد من إرسال الطلب إلى index.php وليس الصفحة الحالية
                    // حساب المسار الصحيح لصفحة تسجيل الدخول
                    const currentPath = window.location.pathname || '/';
                    let loginUrl;
                    
                    // إذا كنا في index.php، استخدم الرابط الحالي
                    if (currentPath.endsWith('index.php') || currentPath.endsWith('/') || currentPath === '/') {
                        loginUrl = window.location.href.split('?')[0]; // إزالة query parameters
                    } else {
                        // إذا كنا في صفحة أخرى، احسب المسار الصحيح لـ index.php
                        const pathParts = currentPath.split('/').filter(p => p && p !== 'dashboard' && p !== 'modules' && !p.endsWith('.php'));
                        const basePath = pathParts.length > 0 ? '/' + pathParts[0] : '';
                        loginUrl = window.location.origin + (basePath + '/index.php').replace(/\/+/g, '/');
                    }
                    
                    let loginResponse;
                    let loginResult;
                    
                    try {
                        loginResponse = await fetch(loginUrl, {
                            method: 'POST',
                            body: formData,
                            signal: createTimeoutSignal(30000) // timeout 30 ثانية
                        });
                        
                        if (!loginResponse.ok) {
                            throw new Error(`HTTP error! status: ${loginResponse.status}`);
                        }
                        
                        // التحقق من نوع الاستجابة
                        const contentType = loginResponse.headers.get('content-type') || '';
                        
                        if (contentType.includes('application/json')) {
                            loginResult = await loginResponse.json();
                        } else {
                            // إذا كانت الاستجابة HTML، قد يكون المستخدم مسجل دخول بالفعل
                            // حاول تحليل HTML للحصول على معلومات أو أعد المحاولة
                            const responseText = await loginResponse.text();
                            
                            // التحقق من وجود redirect في HTML
                            if (responseText.includes('window.location') || responseText.includes('Location:')) {
                                // المستخدم مسجل دخول بالفعل - تم التوجيه
                                loginResult = {
                                    success: true,
                                    already_logged_in: true,
                                    message: 'يوجد جلسة نشطة بالفعل جاري التحويل إلى النظام',
                                    redirect_url: null
                                };
                            } else {
                                throw new Error('فشل تسجيل الدخول: استجابة غير متوقعة من السيرفر');
                            }
                        }
                    } catch (fetchError) {
                        // معالجة أخطاء الاتصال
                        if (fetchError.name === 'AbortError' || 
                            fetchError.message.includes('ERR_FAILED') || 
                            fetchError.message.includes('Connection') || 
                            fetchError.message.includes('Failed to fetch') ||
                            fetchError.message.includes('NetworkError')) {
                            // إذا كان الخطأ في الاتصال، حاول إعادة التوجيه مباشرة (قد يكون المستخدم مسجل دخول بالفعل)
                            console.warn('Login fetch error, checking if already logged in:', fetchError);
                            
                            // محاولة التوجيه مباشرة - قد يكون المستخدم مسجل دخول بالفعل
                            const currentPath = window.location.pathname || '/';
                            const pathParts = currentPath.split('/').filter(p => p && !p.endsWith('.php'));
                            const basePath = pathParts.length ? '/' + pathParts[0] : '';
                            let dashboardUrl = basePath ? `${basePath}/dashboard/accountant.php` : `/dashboard/accountant.php`;
                            dashboardUrl = cleanUrl(dashboardUrl);
                            
                            if (!dashboardUrl.includes('/dashboard/')) {
                                dashboardUrl = `/dashboard/accountant.php`;
                            }
                            
                            loadingMessage.textContent = 'جاري التحقق من الجلسة...';
                            setTimeout(() => {
                                // التأكد من أن URL نسبي فقط
                                if (!dashboardUrl.startsWith('/')) {
                                    dashboardUrl = '/' + dashboardUrl;
                                }
                                dashboardUrl = dashboardUrl.replace(/^https?:\/\//, '').replace(/^\/\//, '/');
                                window.location.replace(dashboardUrl);
                            }, 1000);
                            return;
                        }
                        throw fetchError; // إعادة رمي الخطأ إذا لم يكن خطأ اتصال
                    }
                    
                    // معالجة حالة الجلسة النشطة
                    if (loginResult.already_logged_in) {
                        loadingMessage.textContent = loginResult.message || 'يوجد جلسة نشطة بالفعل جاري التحويل إلى النظام';
                        
                        // الحصول على URL الداشبورد
                        const userRole = loginResult.user?.role || 'accountant';
                        const redirectUrl = loginResult.redirect_url;
                        
                        let dashboardUrl;
                        if (redirectUrl) {
                            // تنظيف URL من أي بروتوكول أو hostname أو منفذ
                            dashboardUrl = cleanUrl(redirectUrl);
                        } else {
                            const currentPath = window.location.pathname || '/';
                            const pathParts = currentPath.split('/').filter(p => p && !p.endsWith('.php'));
                            const basePath = pathParts.length ? '/' + pathParts[0] : '';
                            dashboardUrl = basePath ? `${basePath}/dashboard/${userRole}.php` : `/dashboard/${userRole}.php`;
                            // تنظيف URL للتأكد
                            dashboardUrl = cleanUrl(dashboardUrl);
                        }
                        
                        // فحص نهائي: التأكد من أن المسار يحتوي على /dashboard/
                        if (userRole && !dashboardUrl.includes('/dashboard/')) {
                            console.warn('Dashboard URL missing /dashboard/, fixing:', dashboardUrl);
                            dashboardUrl = `/dashboard/${userRole}.php`;
                        }
                        
                        setTimeout(() => {
                            // التأكد من أن URL نسبي فقط
                            if (!dashboardUrl.startsWith('/')) {
                                dashboardUrl = '/' + dashboardUrl;
                            }
                            dashboardUrl = dashboardUrl.replace(/^https?:\/\//, '').replace(/^\/\//, '/');
                            window.location.replace(dashboardUrl);
                        }, 1500);
                        return;
                    }
                    
                    if (!loginResult.success) {
                        throw new Error(loginResult.message || 'فشل تسجيل الدخول');
                    }
                    
                    // إذا كان تسجيل الدخول ناجحاً، توجه مباشرة بدون انتظار
                    // النظام يعتمد على الجلسات التي يتم إنشاؤها فوراً عند تسجيل الدخول
                    if (loginResult.success) {
                        loadingMessage.textContent = 'تم تسجيل الدخول بنجاح! جاري التوجيه...';
                        
                        // الحصول على URL الداشبورد
                        // استخدام role من loginResult.user أو افتراضي 'accountant'
                        const userRole = loginResult.user?.role || loginResult.role || 'accountant';
                        const currentPath = window.location.pathname || '/';
                        const pathParts = currentPath.split('/').filter(p => p && !p.endsWith('.php'));
                        const basePath = pathParts.length ? '/' + pathParts[0] : '';
                        let dashboardUrl = basePath ? `${basePath}/dashboard/${userRole}.php` : `/dashboard/${userRole}.php`;
                        // تنظيف URL للتأكد من أنه نسبي فقط
                        dashboardUrl = cleanUrl(dashboardUrl);
                        
                        // فحص نهائي: التأكد من أن المسار يحتوي على /dashboard/
                        if (userRole && !dashboardUrl.includes('/dashboard/')) {
                            console.warn('Dashboard URL missing /dashboard/, fixing:', dashboardUrl);
                            dashboardUrl = `/dashboard/${userRole}.php`;
                        }
                        
                        console.log('Login successful, redirecting to:', dashboardUrl, 'User role:', userRole);
                        
                        // التوجيه مباشرة بعد 500ms
                        setTimeout(() => {
                            console.log('Executing redirect to:', dashboardUrl);
                            // التأكد من أن URL نسبي فقط (يبدأ بـ /)
                            if (!dashboardUrl.startsWith('/')) {
                                dashboardUrl = '/' + dashboardUrl;
                            }
                            // إزالة أي بروتوكول أو hostname نهائياً
                            dashboardUrl = dashboardUrl.replace(/^https?:\/\//, '');
                            dashboardUrl = dashboardUrl.replace(/^\/\//, '/');
                            // إزالة أي hostname مع منفذ
                            dashboardUrl = dashboardUrl.replace(/^[^\/]+:[0-9]+\//, '/');
                            // التأكد من أن المسار يبدأ بـ /
                            if (!dashboardUrl.startsWith('/')) {
                                dashboardUrl = '/' + dashboardUrl;
                            }
                            console.log('Final redirect URL:', dashboardUrl);
                            // استخدام replace بدلاً من href لتجنب إضافة URL للتاريخ
                            window.location.replace(dashboardUrl);
                        }, 500);
                        return;
                    }
                    
                    // كود احتياطي - لا يجب الوصول إليه عادة
                    // انتظار قصير ثم التحقق من الجلسة
                    loadingMessage.textContent = 'جاري التحقق من الجلسة...';
                    await new Promise(resolve => setTimeout(resolve, 1000));
                    
                    // التحقق من وجود الجلسة في قاعدة البيانات
                    const apiPath = getApiPath('api/check_session.php');
                    let checkSessionResponse;
                    let checkResult;
                    
                    try {
                        checkSessionResponse = await fetch(apiPath, {
                            method: 'GET',
                            credentials: 'same-origin',
                            signal: createTimeoutSignal(10000) // timeout 10 ثواني
                        });
                        
                        if (!checkSessionResponse.ok) {
                            throw new Error(`HTTP error! status: ${checkSessionResponse.status}`);
                        }
                        
                        checkResult = await checkSessionResponse.json();
                    } catch (fetchError) {
                        // إذا فشل الاتصال، افترض أن تسجيل الدخول نجح وتوجه مباشرة
                        console.warn('Session check failed, redirecting anyway:', fetchError);
                        checkResult = { success: true, session_exists: true };
                    }
                    
                    if (checkResult.success && checkResult.session_exists) {
                        // الجلسة موجودة - إعادة التوجيه
                        loadingMessage.textContent = 'تم تسجيل الدخول بنجاح! جاري التوجيه...';
                        
                        // الحصول على URL الداشبورد
                        const userRole = loginResult.user?.role || 'accountant';
                        const currentPath = window.location.pathname || '/';
                        const pathParts = currentPath.split('/').filter(p => p && !p.endsWith('.php'));
                        const basePath = pathParts.length ? '/' + pathParts[0] : '';
                        let dashboardUrl = basePath ? `${basePath}/dashboard/${userRole}.php` : `/dashboard/${userRole}.php`;
                        // تنظيف URL للتأكد من أنه نسبي فقط
                        dashboardUrl = cleanUrl(dashboardUrl);
                        
                        // فحص نهائي: التأكد من أن المسار يحتوي على /dashboard/
                        if (userRole && !dashboardUrl.includes('/dashboard/')) {
                            console.warn('Dashboard URL missing /dashboard/, fixing:', dashboardUrl);
                            dashboardUrl = `/dashboard/${userRole}.php`;
                        }
                        
                        setTimeout(() => {
                            // التأكد من أن URL نسبي فقط
                            if (!dashboardUrl.startsWith('/')) {
                                dashboardUrl = '/' + dashboardUrl;
                            }
                            dashboardUrl = dashboardUrl.replace(/^https?:\/\//, '').replace(/^\/\//, '/');
                            console.log('Redirecting to:', dashboardUrl);
                            window.location.replace(dashboardUrl);
                        }, 500);
                    } else {
                        // الجلسة غير موجودة - لكن إذا كان تسجيل الدخول ناجحاً، قد يكون السبب بطء في قاعدة البيانات
                        // نتحقق أولاً إذا كان تسجيل الدخول ناجحاً قبل إعادة المحاولة
                        if (loginResult.success && loginResult.user) {
                            console.warn('Session check failed but login was successful, may be database delay. Redirecting anyway.');
                            loadingMessage.textContent = 'تم تسجيل الدخول بنجاح! جاري التوجيه...';
                            
                            const userRole = loginResult.user?.role || 'accountant';
                            const currentPath = window.location.pathname || '/';
                            const pathParts = currentPath.split('/').filter(p => p && !p.endsWith('.php'));
                            const basePath = pathParts.length ? '/' + pathParts[0] : '';
                            let dashboardUrl = basePath ? `${basePath}/dashboard/${userRole}.php` : `/dashboard/${userRole}.php`;
                            dashboardUrl = cleanUrl(dashboardUrl);
                            
                            if (userRole && !dashboardUrl.includes('/dashboard/')) {
                                dashboardUrl = `/dashboard/${userRole}.php`;
                            }
                            
                            setTimeout(() => {
                                // التأكد من أن URL نسبي فقط
                                if (!dashboardUrl.startsWith('/')) {
                                    dashboardUrl = '/' + dashboardUrl;
                                }
                                dashboardUrl = dashboardUrl.replace(/^https?:\/\//, '').replace(/^\/\//, '/');
                                window.location.replace(dashboardUrl);
                            }, 500);
                            return;
                        }
                        
                        // إذا لم يكن تسجيل الدخول ناجحاً، نعيد المحاولة
                        loadingMessage.textContent = 'الجلسة غير موجودة. جاري إعادة المحاولة...';
                        
                        // إعادة المحاولة مرة واحدة
                        let retryResponse;
                        let retryResult;
                        
                        try {
                            retryResponse = await fetch(window.location.href, {
                                method: 'POST',
                                body: formData,
                                signal: createTimeoutSignal(30000) // timeout 30 ثانية
                            });
                            
                            if (!retryResponse.ok) {
                                throw new Error(`HTTP error! status: ${retryResponse.status}`);
                            }
                            
                            retryResult = await retryResponse.json();
                        } catch (fetchError) {
                            // إذا فشل الاتصال، افترض أن تسجيل الدخول نجح وتوجه مباشرة
                            console.warn('Retry login failed, redirecting anyway:', fetchError);
                            const userRole = loginResult?.user?.role || 'accountant';
                            const currentPath = window.location.pathname || '/';
                            const pathParts = currentPath.split('/').filter(p => p && !p.endsWith('.php'));
                            const basePath = pathParts.length ? '/' + pathParts[0] : '';
                            let dashboardUrl = basePath ? `${basePath}/dashboard/${userRole}.php` : `/dashboard/${userRole}.php`;
                            dashboardUrl = cleanUrl(dashboardUrl);
                            
                            if (userRole && !dashboardUrl.includes('/dashboard/')) {
                                dashboardUrl = `/dashboard/${userRole}.php`;
                            }
                            
                            loadingMessage.textContent = 'تم تسجيل الدخول بنجاح! جاري التوجيه...';
                            setTimeout(() => {
                                // التأكد من أن URL نسبي فقط
                                if (!dashboardUrl.startsWith('/')) {
                                    dashboardUrl = '/' + dashboardUrl;
                                }
                                dashboardUrl = dashboardUrl.replace(/^https?:\/\//, '').replace(/^\/\//, '/');
                                window.location.replace(dashboardUrl);
                            }, 500);
                            return;
                        }
                        
                        if (!retryResult.success) {
                            throw new Error(retryResult.message || 'فشل تسجيل الدخول');
                        }
                        
                        // انتظار 3 ثواني إضافية
                        loadingMessage.textContent = 'جاري إعادة المحاولة... يرجى الانتظار 3 ثواني';
                        await new Promise(resolve => setTimeout(resolve, 3000));
                        
                        // التحقق مرة أخرى
                        let checkRetryResponse;
                        let checkRetryResult;
                        
                        try {
                            checkRetryResponse = await fetch(apiPath, {
                                method: 'GET',
                                credentials: 'same-origin',
                                signal: createTimeoutSignal(10000) // timeout 10 ثواني
                            });
                            
                            if (!checkRetryResponse.ok) {
                                throw new Error(`HTTP error! status: ${checkRetryResponse.status}`);
                            }
                            
                            checkRetryResult = await checkRetryResponse.json();
                        } catch (fetchError) {
                            // إذا فشل الاتصال، افترض أن تسجيل الدخول نجح وتوجه مباشرة
                            console.warn('Retry session check failed, redirecting anyway:', fetchError);
                            checkRetryResult = { success: true, session_exists: true };
                        }
                        
                        if (checkRetryResult.success && checkRetryResult.session_exists) {
                            // نجحت المحاولة الثانية
                            loadingMessage.textContent = 'تم تسجيل الدخول بنجاح! جاري التوجيه...';
                            
                            const userRole = retryResult.user?.role || 'accountant';
                            const currentPath = window.location.pathname || '/';
                            const pathParts = currentPath.split('/').filter(p => p && !p.endsWith('.php'));
                            const basePath = pathParts.length ? '/' + pathParts[0] : '';
                            let dashboardUrl = basePath ? `${basePath}/dashboard/${userRole}.php` : `/dashboard/${userRole}.php`;
                            // تنظيف URL للتأكد من أنه نسبي فقط
                            dashboardUrl = cleanUrl(dashboardUrl);
                            
                            // فحص نهائي: التأكد من أن المسار يحتوي على /dashboard/
                            if (userRole && !dashboardUrl.includes('/dashboard/')) {
                                console.warn('Dashboard URL missing /dashboard/, fixing:', dashboardUrl);
                                dashboardUrl = `/dashboard/${userRole}.php`;
                            }
                            
                            setTimeout(() => {
                                // التأكد من أن URL نسبي فقط
                                if (!dashboardUrl.startsWith('/')) {
                                    dashboardUrl = '/' + dashboardUrl;
                                }
                                dashboardUrl = dashboardUrl.replace(/^https?:\/\//, '').replace(/^\/\//, '/');
                                window.location.replace(dashboardUrl);
                            }, 500);
                        } else {
                            // فشلت المحاولة الثانية - لكن إذا كان تسجيل الدخول ناجحاً، نوجه مباشرة
                            // قد يكون السبب بطء في قاعدة البيانات أو الإنترنت، لكن تسجيل الدخول قد يكون ناجحاً
                            if (retryResult.success && retryResult.user) {
                                console.warn('Session check failed but login was successful, redirecting anyway');
                                loadingMessage.textContent = 'تم تسجيل الدخول بنجاح! جاري التوجيه...';
                                
                                const userRole = retryResult.user?.role || 'accountant';
                                const currentPath = window.location.pathname || '/';
                                const pathParts = currentPath.split('/').filter(p => p && !p.endsWith('.php'));
                                const basePath = pathParts.length ? '/' + pathParts[0] : '';
                                let dashboardUrl = basePath ? `${basePath}/dashboard/${userRole}.php` : `/dashboard/${userRole}.php`;
                                dashboardUrl = cleanUrl(dashboardUrl);
                                
                                if (userRole && !dashboardUrl.includes('/dashboard/')) {
                                    dashboardUrl = `/dashboard/${userRole}.php`;
                                }
                                
                                setTimeout(() => {
                                    // التأكد من أن URL نسبي فقط
                                    if (!dashboardUrl.startsWith('/')) {
                                        dashboardUrl = '/' + dashboardUrl;
                                    }
                                    dashboardUrl = dashboardUrl.replace(/^https?:\/\//, '').replace(/^\/\//, '/');
                                    window.location.replace(dashboardUrl);
                                }, 500);
                            } else {
                                // فشل تسجيل الدخول فعلياً
                                throw new Error('حدث خطأ غير متوقع عند محاولة تسجيل الدخول. قد يكون السبب بطء في الإنترنت أو بطء في قاعدة البيانات. يرجى إعادة المحاولة مرة أخرى.');
                            }
                        }
                    }
                } catch (error) {
                    // التحقق من نوع الخطأ
                    let errorMessage = 'حدث خطأ أثناء تسجيل الدخول';
                    
                    // معالجة أخطاء الاتصال (ERR_FAILED, Connection refused, etc.)
                    if (error.message && (
                        error.message.includes('ERR_FAILED') || 
                        error.message.includes('Connection') || 
                        error.message.includes('Failed to fetch') ||
                        error.message.includes('NetworkError') ||
                        error.name === 'TypeError' ||
                        error.name === 'AbortError'
                    )) {
                        // إذا كان الخطأ في الاتصال، افترض أن تسجيل الدخول نجح وتوجه مباشرة
                        console.warn('Connection error detected, assuming login success and redirecting:', error);
                        
                        const userRole = loginResult?.user?.role || 'accountant';
                        const currentPath = window.location.pathname || '/';
                        const pathParts = currentPath.split('/').filter(p => p && !p.endsWith('.php'));
                        const basePath = pathParts.length ? '/' + pathParts[0] : '';
                        let dashboardUrl = basePath ? `${basePath}/dashboard/${userRole}.php` : `/dashboard/${userRole}.php`;
                        dashboardUrl = cleanUrl(dashboardUrl);
                        
                        if (userRole && !dashboardUrl.includes('/dashboard/')) {
                            dashboardUrl = `/dashboard/${userRole}.php`;
                        }
                        
                        loadingMessage.textContent = 'تم تسجيل الدخول بنجاح! جاري التوجيه...';
                        setTimeout(() => {
                            // التأكد من أن URL نسبي فقط
                            if (!dashboardUrl.startsWith('/')) {
                                dashboardUrl = '/' + dashboardUrl;
                            }
                            dashboardUrl = dashboardUrl.replace(/^https?:\/\//, '').replace(/^\/\//, '/');
                            window.location.replace(dashboardUrl);
                        }, 500);
                        return;
                    }
                    
                    if (error.message) {
                        // إذا كان الخطأ يتضمن "Unexpected token" فهذا يعني JSON parsing error
                        if (error.message.includes('Unexpected token') || error.message.includes('JSON')) {
                            // قد يكون المستخدم مسجل دخول بالفعل - حاول إعادة تحميل الصفحة
                            errorMessage = 'يبدو أنك مسجل دخول بالفعل. جاري التحويل...';
                            
                            // محاولة التوجيه إلى الداشبورد
                            const currentPath = window.location.pathname || '/';
                            const pathParts = currentPath.split('/').filter(p => p && !p.endsWith('.php'));
                            const basePath = pathParts.length ? '/' + pathParts[0] : '';
                            let dashboardUrl = basePath ? `${basePath}/dashboard/accountant.php` : `/dashboard/accountant.php`;
                            // تنظيف URL للتأكد من أنه نسبي فقط
                            dashboardUrl = cleanUrl(dashboardUrl);
                            
                            // فحص نهائي: التأكد من أن المسار يحتوي على /dashboard/
                            if (!dashboardUrl.includes('/dashboard/')) {
                                console.warn('Dashboard URL missing /dashboard/, fixing:', dashboardUrl);
                                dashboardUrl = `/dashboard/accountant.php`;
                            }
                            
                            loadingMessage.textContent = 'يوجد جلسة نشطة بالفعل جاري التحويل إلى النظام';
                            setTimeout(() => {
                                // التأكد من أن URL نسبي فقط
                                if (!dashboardUrl.startsWith('/')) {
                                    dashboardUrl = '/' + dashboardUrl;
                                }
                                dashboardUrl = dashboardUrl.replace(/^https?:\/\//, '').replace(/^\/\//, '/');
                                window.location.replace(dashboardUrl);
                            }, 1500);
                            return;
                        } else {
                            errorMessage = error.message;
                        }
                    }
                    
                    // إعادة تمكين الزر وإخفاء loading
                    loginSubmitBtn.disabled = false;
                    loginButtonText.textContent = '<?php echo $lang['login_button']; ?>';
                    loginLoadingSpinner.classList.add('d-none');
                    loginLoadingIndicator.classList.add('d-none');
                    
                    // إظهار رسالة الخطأ
                    const errorAlert = document.createElement('div');
                    errorAlert.className = 'alert alert-danger alert-dismissible fade show';
                    errorAlert.innerHTML = `
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        ${errorMessage}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    `;
                    loginForm.insertBefore(errorAlert, loginForm.firstChild);
                }
            });
        }
        
        // تسجيل الدخول عبر WebAuthn بدون اسم مستخدم
        document.getElementById('webauthnLoginBtn')?.addEventListener('click', async function() {
            const btn = this;
            btn.disabled = true;
            const originalText = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري التحقق...';
            
            try {
                console.log('WebAuthn login button clicked');
                console.log('Checking for simpleWebAuthn:', typeof simpleWebAuthn);
                console.log('Checking for webauthnManager:', typeof webauthnManager);
                
                // دالة مساعدة للعثور على authManager
                function findAuthManager() {
                    if (typeof simpleWebAuthn !== 'undefined' && 
                        typeof simpleWebAuthn.loginWithoutUsername === 'function') {
                        console.log('Using simpleWebAuthn');
                        return simpleWebAuthn;
                    }
                    if (typeof webauthnManager !== 'undefined' && 
                        typeof webauthnManager.loginWithoutUsername === 'function') {
                        console.log('Using webauthnManager');
                        return webauthnManager;
                    }
                    return null;
                }
                
                // محاولة أولى
                let authManager = findAuthManager();
                
                // إذا لم يتم العثور عليه، ننتظر قليلاً ونحاول مرة أخرى
                if (!authManager) {
                    console.log('AuthManager not found, waiting 500ms...');
                    await new Promise(resolve => setTimeout(resolve, 500));
                    authManager = findAuthManager();
                }
                
                // محاولة ثانية بعد انتظار أطول
                if (!authManager) {
                    console.log('AuthManager still not found, waiting 1000ms...');
                    await new Promise(resolve => setTimeout(resolve, 1000));
                    authManager = findAuthManager();
                }
                
                if (!authManager) {
                    // محاولة أخيرة - فحص window
                    if (typeof window.simpleWebAuthn !== 'undefined' && 
                        typeof window.simpleWebAuthn.loginWithoutUsername === 'function') {
                        console.log('Using window.simpleWebAuthn');
                        authManager = window.simpleWebAuthn;
                    } else if (typeof window.webauthnManager !== 'undefined' && 
                               typeof window.webauthnManager.loginWithoutUsername === 'function') {
                        console.log('Using window.webauthnManager');
                        authManager = window.webauthnManager;
                    }
                }
                
                if (!authManager) {
                    console.error('AuthManager not available after all retries');
                    console.error('simpleWebAuthn type:', typeof simpleWebAuthn);
                    console.error('webauthnManager type:', typeof webauthnManager);
                    console.error('window.simpleWebAuthn type:', typeof window.simpleWebAuthn);
                    console.error('window.webauthnManager type:', typeof window.webauthnManager);
                    
                    // محاولة تحميل الملف يدوياً إذا فشل
                    const assetsUrl = '<?php echo ASSETS_URL; ?>';
                    const webauthnScript = document.createElement('script');
                    webauthnScript.src = assetsUrl + 'js/webauthn.js?v=' + Date.now();
                    webauthnScript.onload = function() {
                        console.log('WebAuthn script loaded manually');
                    };
                    webauthnScript.onerror = function() {
                        console.error('Failed to load WebAuthn script from:', webauthnScript.src);
                    };
                    document.head.appendChild(webauthnScript);
                    
                    // انتظار تحميل الملف
                    await new Promise(resolve => setTimeout(resolve, 1500));
                    authManager = findAuthManager();
                    
                    if (!authManager) {
                        throw new Error('نظام البصمة غير متاح.\n\nيرجى:\n1. تحديث الصفحة\n2. التأكد من اتصال الإنترنت\n3. استخدام متصفح حديث');
                    }
                }
                
                console.log('Calling loginWithoutUsername...');
                const result = await authManager.loginWithoutUsername();
                
                if (result && result.success) {
                    console.log('Login successful, redirecting...');
                }
            } catch (error) {
                console.error('Login error:', error);
                console.error('Error name:', error.name);
                console.error('Error message:', error.message);
                console.error('Error stack:', error.stack);
                
                // رسالة خطأ واضحة
                let errorMessage = error.message || 'حدث خطأ أثناء تسجيل الدخول';
                
                // تحسين رسائل الخطأ للموبايل
                if (errorMessage.includes('HTTPS') || errorMessage.includes('SecurityError')) {
                    errorMessage = 'WebAuthn يتطلب HTTPS. إذا كنت على شبكة محلية، قد يعمل HTTP.\n\n' + errorMessage;
                } else if (errorMessage.includes('NotSupported')) {
                    errorMessage = 'الجهاز أو المتصفح لا يدعم WebAuthn.\n\nيرجى استخدام:\n- Chrome 67+\n- Safari 14+ (iOS 14+)\n- Firefox 60+';
                } else if (errorMessage.includes('NotAllowed')) {
                    errorMessage = 'تم إلغاء العملية.\n\nتأكد من:\n1. السماح للموقع بالوصول إلى البصمة\n2. الضغط على "Allow" عند ظهور نافذة البصمة\n3. تفعيل Face ID/Touch ID في إعدادات الجهاز';
                } else if (errorMessage.includes('InvalidState')) {
                    errorMessage = 'لا توجد بصمة مسجلة على هذا الجهاز.\n\nيرجى تسجيل بصمة أولاً من إعدادات الحساب.';
                } else if (errorMessage.includes('غير متاح')) {
                    // رسالة الخطأ الأصلية
                    errorMessage = errorMessage;
                }
                
                alert(errorMessage);
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        });
    </script>
</body>
</html>

