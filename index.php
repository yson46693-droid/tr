<?php
session_start();
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

if (isLoggedIn()) {
    // تحميل ملف إنشاء الرواتب التلقائي (يتم تنفيذه تلقائياً عند تحميل الملف)
    if (file_exists(__DIR__ . '/includes/auto_salary_init.php')) {
        require_once __DIR__ . '/includes/auto_salary_init.php';
    }
    
    $userRole = $_SESSION['role'] ?? 'accountant';
    $dashboardUrl = getDashboardUrl($userRole);
    
    // 1. إزالة أي بروتوكول
    $dashboardUrl = preg_replace('/^https?:\/\//', '', $dashboardUrl);
    $dashboardUrl = preg_replace('/^\/\//', '/', $dashboardUrl);
    
    if (strpos($dashboardUrl, '/') !== 0) {
        $dashboardUrl = '/' . $dashboardUrl;
    }
    
    $dashboardUrl = preg_replace('/\/+/', '/', $dashboardUrl);
    
    if (preg_match('/^\/[^\/]+\.[a-z]/i', $dashboardUrl)) {
        $parts = explode('/', $dashboardUrl);
        $dashboardIndex = array_search('dashboard', $parts);
        if ($dashboardIndex !== false) {
            $dashboardUrl = '/' . implode('/', array_slice($parts, $dashboardIndex));
        } else {
            $dashboardUrl = '/dashboard/' . $userRole . '.php';
        }
    }
    
    if (strpos($dashboardUrl, '/dashboard') === false) {
        $dashboardUrl = '/dashboard/' . $userRole . '.php';
    }
    
    if (strpos($dashboardUrl, 'http://') === 0 || strpos($dashboardUrl, 'https://') === 0) {
        $parsed = parse_url($dashboardUrl);
        $dashboardUrl = $parsed['path'] ?? '/dashboard/' . $userRole . '.php';
    }
    
    $dashboardUrl = trim($dashboardUrl);
    if (empty($dashboardUrl) || $dashboardUrl === '/') {
        $dashboardUrl = '/dashboard/' . $userRole . '.php';
    }
    
    $currentScript = basename($_SERVER['PHP_SELF']);
    if ($currentScript !== 'index.php') {
        return;
    }
    
    if (!headers_sent()) {
        header('Location: ' . $dashboardUrl);
        exit;
    } else {
        echo '<script>window.location.href = "' . htmlspecialchars($dashboardUrl) . '";</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($dashboardUrl) . '"></noscript>';
        exit;
    }
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $login_method = $_POST['login_method'] ?? 'password';
    
    if ($login_method === 'webauthn') {
        // WebAuthn لا يحتاج CSRF protection في هذه المرحلة
    } else {
        // التحقق من CSRF (متوافق مع النظام الحالي)
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($uri, '/api/') === false && function_exists('protectFormFromCSRF')) {
            try {
                protectFormFromCSRF();
            } catch (Exception $e) {
                error_log("CSRF protection error: " . $e->getMessage());
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
                    error_log("Rate limiter error: " . $e->getMessage());
                }
            }
            
            if (empty($error)) {
                $rememberMe = isset($_POST['remember_me']) && $_POST['remember_me'] == '1';
                $result = login($username, $password, $rememberMe);
                
                if ($result['success']) {
                    // إعادة تعيين محاولات Rate Limiting بعد تسجيل دخول ناجح
                    if (class_exists('RateLimiter')) {
                        try {
                            RateLimiter::resetAttempts($username);
                        } catch (Exception $e) {
                            error_log("Rate limiter reset error: " . $e->getMessage());
                        }
                    }
                    // تجديد معرف الجلسة
                    if (function_exists('regenerateSessionAfterLogin')) {
                        regenerateSessionAfterLogin();
                    }
                    
                    $userRole = $result['user']['role'] ?? 'accountant';
                    $dashboardUrl = getDashboardUrl($userRole);
                    
                    $dashboardUrl = preg_replace('/^https?:\/\//', '', $dashboardUrl);
                    $dashboardUrl = preg_replace('/^\/\//', '/', $dashboardUrl);
                    
                    if (strpos($dashboardUrl, '/') !== 0) {
                        $dashboardUrl = '/' . $dashboardUrl;
                    }
                    
                    $dashboardUrl = preg_replace('/\/+/', '/', $dashboardUrl);
                    
                    if (preg_match('/^\/[^\/]+\.[a-z]/i', $dashboardUrl)) {
                        $parts = explode('/', $dashboardUrl);
                        $dashboardIndex = array_search('dashboard', $parts);
                        if ($dashboardIndex !== false) {
                            $dashboardUrl = '/' . implode('/', array_slice($parts, $dashboardIndex));
                        } else {
                            $dashboardUrl = '/dashboard/' . $userRole . '.php';
                        }
                    }
                    
                    if (strpos($dashboardUrl, '/dashboard') === false) {
                        $dashboardUrl = '/dashboard/' . $userRole . '.php';
                    }
                    
                    if (strpos($dashboardUrl, 'http://') === 0 || strpos($dashboardUrl, 'https://') === 0) {
                        $parsed = parse_url($dashboardUrl);
                        $dashboardUrl = $parsed['path'] ?? '/dashboard/' . $userRole . '.php';
                    }
                    
                    $dashboardUrl = trim($dashboardUrl);
                    if (empty($dashboardUrl) || $dashboardUrl === '/') {
                        $dashboardUrl = '/dashboard/' . $userRole . '.php';
                    }
                    
                    if (!headers_sent()) {
                        header('Location: ' . $dashboardUrl);
                        exit;
                    } else {
                        echo '<script>window.location.href = "' . htmlspecialchars($dashboardUrl) . '";</script>';
                        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($dashboardUrl) . '"></noscript>';
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
    
    <!-- منع الضغط بالزر الأيمن وفتح أدوات المطور -->
    <script>
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
    </script>
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
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                <?php echo htmlspecialchars($error); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
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
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="bi bi-box-arrow-in-right me-2"></i>
                                    <?php echo $lang['login_button']; ?>
                                </button>
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

