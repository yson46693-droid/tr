<?php
error_reporting(0);
ini_set('display_errors', 0);

// بدء output buffering
if (!ob_get_level()) {
    ob_start();
}

define('ACCESS_ALLOWED', true);

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

try {
    require_once __DIR__ . '/includes/config.php';
    
    if (file_exists(__DIR__ . '/includes/path_helper.php')) {
        require_once __DIR__ . '/includes/path_helper.php';
    }
    
    require_once __DIR__ . '/includes/auth.php';
    
    if (function_exists('logout')) {
        try {
            logout();
        } catch (Exception $e) {
            error_log("Logout Function Error: " . $e->getMessage());
        }
    }
} catch (Exception $e) {
    error_log("Logout Page Error: " . $e->getMessage());
}

// حذف الجلسة و remember tokens من قاعدة البيانات
if (isset($_SESSION['user_id'])) {
    try {
        require_once __DIR__ . '/includes/db.php';
        require_once __DIR__ . '/includes/auth.php';
        $db = db();
        $userId = $_SESSION['user_id'];
        $sessionId = session_id();
        
        // حذف الجلسة من قاعدة البيانات
        if ($sessionId && ensureSessionsTable()) {
            try {
                $db->execute("DELETE FROM sessions WHERE user_id = ? AND session_id = ?", [$userId, $sessionId]);
            } catch (Exception $e) {
                error_log("Logout: Error deleting session from database: " . $e->getMessage());
            }
        }
        
        // التحقق من وجود جدول remember_tokens وحذف جميع tokens للمستخدم
        if (ensureRememberTokensTable()) {
            try {
                $db->execute("DELETE FROM remember_tokens WHERE user_id = ?", [$userId]);
            } catch (Exception $e) {
                error_log("Logout: Error deleting remember tokens from database: " . $e->getMessage());
            }
        }
    } catch (Exception $e) {
        error_log("Logout: Error in database cleanup: " . $e->getMessage());
    }
}

// حذف remember_token cookie بجميع الإعدادات الممكنة
$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443')
);

if (isset($_COOKIE['remember_token'])) {
    $cookieOptions = [
        ['expires' => time() - 3600, 'path' => '/', 'domain' => '', 'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax'],
        ['expires' => time() - 3600, 'path' => '/', 'domain' => null, 'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax'],
        ['expires' => time() - 3600, 'path' => '/', 'domain' => '', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax'],
    ];
    
    foreach ($cookieOptions as $options) {
        @setcookie('remember_token', '', $options);
    }
}

// إنهاء الجلسة بشكل نهائي
if (session_status() === PHP_SESSION_ACTIVE) {
    $cookieParams = session_get_cookie_params();
    $sessionName = session_name();
    
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
    $sessionCookieOptions = [
        ['expires' => time() - 3600, 'path' => $cookieParams['path'], 'domain' => $cookieParams['domain'], 'secure' => $cookieParams['secure'], 'httponly' => $cookieParams['httponly']],
        ['expires' => time() - 3600, 'path' => '/', 'domain' => '', 'secure' => $isHttps, 'httponly' => true],
        ['expires' => time() - 3600, 'path' => '/', 'domain' => null, 'secure' => $isHttps, 'httponly' => true],
        ['expires' => time() - 3600, 'path' => $cookieParams['path'], 'domain' => '', 'secure' => $isHttps, 'httponly' => true],
    ];
    
    foreach ($sessionCookieOptions as $options) {
        @setcookie($sessionName, '', $options);
    }
    
    // تدمير الجلسة نهائياً
    @session_destroy();
}

// حذف جميع الكوكيز المتعلقة بالجلسة
if (isset($_COOKIE)) {
    foreach ($_COOKIE as $name => $value) {
        if (strpos($name, 'PHPSESSID') !== false || 
            strpos($name, 'remember_token') !== false || 
            strpos($name, session_name()) !== false ||
            strpos($name, 'session') !== false) {
            // حذف cookie بجميع الإعدادات الممكنة
            @setcookie($name, '', time() - 3600, '/');
            @setcookie($name, '', time() - 3600, '/', '');
            @setcookie($name, '', time() - 3600, '/', null);
            @setcookie($name, '', time() - 3600);
        }
    }
}

while (ob_get_level()) {
    @ob_end_clean();
}

$redirectUrl = '/index.php';

try {
    if (function_exists('getRelativeUrl')) {
        $tempUrl = getRelativeUrl('index.php');
        if (!empty($tempUrl)) {
            $redirectUrl = $tempUrl;
        }
    } else {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $scriptDir = dirname($scriptName);
        
        // تنظيف المسار
        $scriptDir = str_replace('\\', '/', $scriptDir);
        $scriptDir = rtrim($scriptDir, '/');
        
        if ($scriptDir && $scriptDir !== '/' && $scriptDir !== '.') {
            $redirectUrl = $scriptDir . '/index.php';
        } else {
            $redirectUrl = '/index.php';
        }
    }
    
    $redirectUrl = str_replace('//', '/', $redirectUrl);
    if (empty($redirectUrl) || $redirectUrl === '/') {
        $redirectUrl = '/index.php';
    }
    
    if (strpos($redirectUrl, '/') !== 0) {
        $redirectUrl = '/' . $redirectUrl;
    }
    
} catch (Exception $e) {
    error_log("Logout Redirect URL Error: " . $e->getMessage());
    $redirectUrl = '/index.php';
}

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الخروج</title>
    <script>
        (function() {
            var redirectUrl = <?php echo json_encode($redirectUrl, JSON_UNESCAPED_UNICODE); ?>;
            
            try {
                if (typeof window !== 'undefined' && window.location && window.location.replace) {
                    window.location.replace(redirectUrl);
                    return;
                }
            } catch(e) {}
            
            try {
                if (typeof window !== 'undefined' && window.location && window.location.href) {
                    window.location.href = redirectUrl;
                    return;
                }
            } catch(e) {}
            
            if (typeof document !== 'undefined') {
                if (document.readyState === 'complete' || document.readyState === 'interactive') {
                    setTimeout(function() {
                        if (window.location) {
                            window.location = redirectUrl;
                        }
                    }, 100);
                } else {
                    document.addEventListener('DOMContentLoaded', function() {
                        setTimeout(function() {
                            if (window.location) {
                                window.location = redirectUrl;
                            }
                        }, 100);
                    });
                }
            }
        })();
    </script>
    <meta http-equiv="refresh" content="0;url=<?php echo htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body style="font-family: Arial, sans-serif; text-align: center; padding: 50px; direction: rtl; background: #f5f5f5; margin: 0;">
    <div style="max-width: 400px; margin: 100px auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
        <h2 style="color: #333; margin-bottom: 20px;">تسجيل الخروج</h2>
        <p style="color: #666; margin-bottom: 20px;">جاري تسجيل الخروج...</p>
        <div style="margin: 20px 0;">
            <div class="spinner-border text-primary" role="status" style="display: inline-block; width: 2rem; height: 2rem; border: 0.25em solid currentColor; border-right-color: transparent; border-radius: 50%; animation: spinner-border 0.75s linear infinite;"></div>
        </div>
        <p style="margin-top: 20px;">
            <a href="<?php echo htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8'); ?>" 
               style="color:rgb(255, 0, 0); text-decoration: none; font-weight: bold; display: inline-block; margin-top: 10px;">
                اضغط هنا إذا لم يتم إعادة التوجيه تلقائياً
            </a>
        </p>
    </div>
    <style>
        @keyframes spinner-border {
            to { transform: rotate(360deg); }
        }
    </style>
    <script>
        window.addEventListener('load', function() {
            setTimeout(function() {
                var redirectUrl = <?php echo json_encode($redirectUrl, JSON_UNESCAPED_UNICODE); ?>;
                if (window.location && window.location.pathname !== redirectUrl) {
                    window.location.href = redirectUrl;
                }
            }, 500);
        });
    </script>
</body>
</html>
<?php
exit;

