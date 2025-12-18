<?php
/**
 * إعدادات النظام العامة
 * نظام إدارة الشركات المتكامل
 */
//th
// منع الوصول المباشر
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_USER', 'co_db');
define('DB_PASS', 'Osama7444');
define('DB_NAME', 'albarakah');

// إعدادات المنطقة الزمنية - مصر/القاهرة
date_default_timezone_set('Africa/Cairo');

// إعدادات اللغة
if (!defined('DEFAULT_LANGUAGE')) {
    define('DEFAULT_LANGUAGE', 'ar');
}
if (!defined('SUPPORTED_LANGUAGES')) {
    define('SUPPORTED_LANGUAGES', ['ar', 'en']);
}

// إعدادات العملة
if (!defined('CURRENCY')) {
    define('CURRENCY', 'جنيه');
}
if (!defined('CURRENCY_SYMBOL')) {
    // تنظيف رمز العملة من أي آثار لـ 262145
    $currencySymbol = 'ج.م';
    $currencySymbol = str_replace('262145', '', $currencySymbol);
    $currencySymbol = preg_replace('/262145\s*/', '', $currencySymbol);
    $currencySymbol = preg_replace('/\s*262145/', '', $currencySymbol);
    $currencySymbol = trim($currencySymbol);
    // إذا أصبح فارغاً بعد التنظيف، استخدم القيمة الافتراضية
    if (empty($currencySymbol)) {
        $currencySymbol = 'ج.م';
    }
    define('CURRENCY_SYMBOL', $currencySymbol);
}
if (!defined('CURRENCY_CODE')) {
    define('CURRENCY_CODE', 'EGP');
}

// إعدادات التاريخ والوقت
define('DATE_FORMAT', 'd/m/Y');
define('TIME_FORMAT', 'g:i A'); // نظام 12 ساعة صباحاً ومساءً
define('DATETIME_FORMAT', 'd/m/Y g:i A');

// إعدادات الجلسة - 7 أيام (604800 ثانية)
define('SESSION_LIFETIME', 3600 * 24 * 7); // 7 أيام

// إعدادات timeout لمنع توقف الخادم
// زيادة timeout للطلبات الطويلة (مثل keep-alive)
if (!ini_get('max_execution_time') || ini_get('max_execution_time') < 60) {
    @ini_set('max_execution_time', 60); // 60 ثانية للطلبات العادية
}
if (!ini_get('max_input_time') || ini_get('max_input_time') < 60) {
    @ini_set('max_input_time', 60); // 60 ثانية لمعالجة المدخلات
}
// إعدادات timeout للاتصال بقاعدة البيانات
// تقليل timeout لتجنب التعليق
if (!ini_get('default_socket_timeout') || ini_get('default_socket_timeout') > 10) {
    @ini_set('default_socket_timeout', 5); // 5 ثواني للاتصال بقاعدة البيانات (مخفض)
}

// إعدادات الجلسة - يجب تعيينها قبل بدء الجلسة
// التحقق من حالة الجلسة قبل محاولة تغيير الإعدادات
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
    ini_set('session.cookie_lifetime', SESSION_LIFETIME);
    // إعدادات إضافية لتحسين استقرار الجلسات
    ini_set('session.gc_probability', 1);
    ini_set('session.gc_divisor', 1000); // تنظيف الجلسات القديمة بنسبة 0.1%
}

$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443')
);

// تحسين إعدادات session cookie للعمل بشكل أفضل على الهواتف
$sessionCookieOptions = [
    'lifetime' => SESSION_LIFETIME,
    'path' => '/',
    'domain' => '',
    'secure' => $isHttps,
    'httponly' => true,
    // استخدام 'None' على HTTPS للسماح بالعمل على جميع المتصفحات، أو 'Lax' كبديل آمن
    'samesite' => $isHttps ? 'None' : 'Lax',
];

// التأكد من أن الجلسة نشطة بشكل صحيح
if (session_status() === PHP_SESSION_NONE) {
    // الجلسة لم تبدأ بعد - بدء الجلسة مع الإعدادات الصحيحة
    session_set_cookie_params($sessionCookieOptions);
    @session_start();
} elseif (session_status() === PHP_SESSION_ACTIVE) {
    // الجلسة نشطة بالفعل - تحديث الإعدادات فقط
    // تحديث إعدادات الكوكي الحالية إن كانت الجلسة قد بدأت بالفعل قبل تضمين الملف
    // تحديث تلقائي لوقت انتهاء الجلسة عند كل طلب نشط
    if (!headers_sent() && session_id()) {
        // تحديث وقت انتهاء الجلسة عند كل طلب نشط (إذا كان المستخدم مسجل دخول)
        if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
            // تحديث وقت آخر نشاط
            $_SESSION['last_activity'] = time();
            
            // تحديث الكوكي بوقت انتهاء جديد
            setcookie(session_name(), session_id(), [
                'expires' => time() + SESSION_LIFETIME,
                'path' => '/',
                'domain' => '',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => $isHttps ? 'None' : 'Lax',
            ]);
        } else {
            // للمستخدمين غير المسجلين، تحديث عادي
            setcookie(session_name(), session_id(), [
                'expires' => time() + SESSION_LIFETIME,
                'path' => '/',
                'domain' => '',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }
}

// تحميل session logger إذا كان متوفراً
if (file_exists(__DIR__ . '/session_logger.php')) {
    require_once __DIR__ . '/session_logger.php';
}

// التحقق الأمني المبسط: فقط تحديث الجلسة دون إلغاء الجلسة بشكل مفرط
// الفحوصات الأمنية الصارمة تتم في auth.php عند الحاجة
// ملاحظة: تحديث الـ cookie يتم في الكود أعلاه (lines 85-111)، لا حاجة لتحديثه هنا مرة أخرى
if (session_status() === PHP_SESSION_ACTIVE) {
    // إذا كان المستخدم مسجل دخول، تحديث وقت آخر نشاط
    if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
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
            } elseif (strpos($currentScript, 'sales.php') !== false || basename($currentScript) === 'sales.php' || strpos($currentScript, 'dashboard/sales.php') !== false || strpos($currentScript, 'modules/sales') !== false) {
                $isSalesPage = true;
            }
        }
        if (!$isProfilePage && !$isAttendancePage && !$isSalesPage) {
            $requestUri = $_SERVER['REQUEST_URI'] ?? '';
            if (strpos($requestUri, 'profile.php') !== false) {
                $isProfilePage = true;
            } elseif (strpos($requestUri, 'attendance.php') !== false) {
                $isAttendancePage = true;
            } elseif (strpos($requestUri, 'sales.php') !== false || strpos($requestUri, 'dashboard/sales') !== false || strpos($requestUri, 'modules/sales') !== false) {
                $isSalesPage = true;
            }
        }
        
        // التحقق من الدور في الجلسة - حماية شاملة للمندوبين
        $isSalesUser = false;
        if (isset($_SESSION['role']) && strtolower($_SESSION['role']) === 'sales') {
            $isSalesUser = true;
        }
        
        $isProtectedPage = $isProfilePage || $isAttendancePage || $isSalesPage || $isSalesUser;
        
        // التحقق من طلب keep-alive API - عدم حذف الجلسة أبداً في هذا الحالة
        $isKeepAliveRequest = false;
        $currentScript = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($currentScript, 'session_keepalive.php') !== false || 
            strpos($requestUri, 'session_keepalive.php') !== false ||
            (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' && strpos($requestUri, 'keepalive') !== false)) {
            $isKeepAliveRequest = true;
        }
        
        // تحديث وقت آخر نشاط
        $_SESSION['last_activity'] = time();
        
        // تحديث last_activity_previous أيضاً عند كل طلب نشط (إلا إذا كان keep-alive request)
        // هذا يمنع حذف الجلسة بشكل خاطئ عندما يكون المستخدم نشطاً
        if (!$isKeepAliveRequest) {
            $_SESSION['last_activity_previous'] = time();
        } elseif (!isset($_SESSION['last_activity_previous'])) {
            // إذا كان keep-alive request ولم يكن هناك previous، نضبطه لأول مرة
            $_SESSION['last_activity_previous'] = time();
        }
        
        // === تعطيل حذف الجلسة بناءً على last_activity_previous ===
        // هذا المنطق يعتمد على $_SESSION وليس على قاعدة البيانات
        // التحقق من صحة الجلسة يتم في isLoggedIn() بناءً على expires_at في قاعدة البيانات فقط
        // لذلك لا نحتاج لحذف الجلسة هنا لأن isLoggedIn() سيتحقق من expires_at في قاعدة البيانات
        
        // تحديث last_activity_previous لأغراض أخرى فقط (مثل الإحصائيات)
        // لكن لا نستخدمه لحذف الجلسة - التحقق من الجلسة يتم في isLoggedIn() فقط
        if (!isset($_SESSION['last_activity_previous'])) {
            $_SESSION['last_activity_previous'] = time();
        } elseif (!$isKeepAliveRequest) {
            // تحديث last_activity_previous عند كل طلب نشط (باستثناء keep-alive)
            $_SESSION['last_activity_previous'] = time();
        }
    }
}

// إعدادات الأمان
define('PASSWORD_MIN_LENGTH', 6);
define('CSRF_TOKEN_NAME', 'csrf_token');
define('REQUEST_USAGE_MONITOR_ENABLED', true);
define('REQUEST_USAGE_THRESHOLD_PER_USER', 4000); // الحد اليومي لكل مستخدم قبل إنشاء تنبيه
define('REQUEST_USAGE_THRESHOLD_PER_IP', 30000);    // الحد اليومي لكل عنوان IP قبل إنشاء تنبيه
define('REQUEST_USAGE_ALERT_WINDOW_MINUTES', 1440); // فترة المراقبة بالدقائق (افتراضياً يوم كامل)

// إعدادات المسارات
define('BASE_PATH', dirname(__DIR__));
define('UPLOAD_PATH', BASE_PATH . '/uploads/');
define('REPORTS_PATH', BASE_PATH . '/reports/');

$privateStorageBase = dirname(BASE_PATH) . '/storage';
if (!defined('PRIVATE_STORAGE_PATH')) {
    define('PRIVATE_STORAGE_PATH', $privateStorageBase);
}
if (!defined('REPORTS_PRIVATE_PATH')) {
    define('REPORTS_PRIVATE_PATH', PRIVATE_STORAGE_PATH . '/reports');
}

/**
 * ضمان وجود مجلد خاص للتخزين وإنشائه تلقائياً إذا لم يكن موجوداً.
 *
 * @param string $directory
 * @return void
 */
function ensurePrivateDirectory(string $directory): void
{
    if (is_dir($directory)) {
        return;
    }

    $parent = dirname($directory);
    if (!is_dir($parent) && $parent !== $directory) {
        ensurePrivateDirectory($parent);
    }

    if (!@mkdir($directory, 0755, true) && !is_dir($directory)) {
        error_log('Failed to create directory: ' . $directory);
    }
}

ensurePrivateDirectory(PRIVATE_STORAGE_PATH);
ensurePrivateDirectory(REPORTS_PRIVATE_PATH);

$logsDirectory = PRIVATE_STORAGE_PATH . '/logs';
ensurePrivateDirectory($logsDirectory);

$defaultErrorLog = $logsDirectory . '/php-errors.log';
if (is_dir($logsDirectory) && is_writable($logsDirectory)) {
    if (!file_exists($defaultErrorLog)) {
        @touch($defaultErrorLog);
    }

    if (is_writable($defaultErrorLog)) {
        ini_set('log_errors', '1');
        ini_set('error_log', $defaultErrorLog);
        if (!defined('APP_ERROR_LOG')) {
            define('APP_ERROR_LOG', $defaultErrorLog);
        }
    } else {
        error_log('Error log file is not writable: ' . $defaultErrorLog);
    }
} else {
    error_log('Logs directory is not writable: ' . $logsDirectory);
}
define('ASSETS_PATH', dirname(__DIR__) . '/assets/');

// إعدادات تكامل aPDF.io - يمكن تخزين المفتاح في متغير بيئة APDF_IO_API_KEY لأمان أفضل
define('APDF_IO_ENDPOINT', 'https://api.apdf.io/v1/pdf/html');
define('APDF_IO_API_KEY', getenv('APDF_IO_API_KEY') ?: 'UQFfHN7tBIgv0Zjy1nelyZWMJC93m3NMXCWfWe9246a95eed');

// تحديد ASSETS_URL بناءً على موقع الملف
// استخدام REQUEST_URI للحصول على المسار الكامل (يعمل بشكل أفضل على الموبايل)
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';

// استخراج base path من REQUEST_URI أو SCRIPT_NAME
$basePath = '';

// محاولة 1: من REQUEST_URI (أفضل للموبايل)
if (!empty($requestUri)) {
    $parsedUri = parse_url($requestUri);
    $path = $parsedUri['path'] ?? '';
    $path = str_replace('\\', '/', $path);
    
    // إزالة /dashboard و /modules و API من المسار
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

// محاولة 2: من SCRIPT_NAME إذا فشلت المحاولة الأولى
if (empty($basePath)) {
    $scriptDir = dirname($scriptName);
    
    // إزالة /dashboard أو /modules من المسار
    if (strpos($scriptDir, '/dashboard') !== false) {
        $scriptDir = dirname($scriptDir);
    }
    if (strpos($scriptDir, '/modules') !== false) {
        $scriptDir = dirname(dirname($scriptDir));
    }
    
    // تنظيف المسار
    $scriptDir = str_replace('\\', '/', $scriptDir);
    $scriptDir = trim($scriptDir, '/');
    
    if (!empty($scriptDir) && $scriptDir !== '.' && $scriptDir !== '/') {
        $basePath = '/' . $scriptDir;
    }
}

// تحديد ASSETS_URL النهائي
if (empty($basePath)) {
    define('ASSETS_URL', '/assets/');
} else {
    define('ASSETS_URL', rtrim($basePath, '/') . '/assets/');
}

// إعدادات التطبيق
define('APP_NAME', 'شركة البركة');
define('APP_VERSION', '1.0.0');
// إصدار الملفات الثابتة (Assets) - يتم تحديثه يدوياً عند تغيير CSS/JS
if (!defined('ASSETS_VERSION')) {
    define('ASSETS_VERSION', '1.0.0');
}
define('COMPANY_NAME', 'شركة البركة');

// إعدادات وضع الصيانة
// تغيير هذا إلى true لتفعيل وضع الصيانة
if (!defined('MAINTENANCE_MODE')) {
    define('MAINTENANCE_MODE', true);
}

// إعدادات التقارير
define('REPORTS_AUTO_DELETE', true); // حذف التقارير بعد الإرسال
define('REPORTS_RETENTION_HOURS', 24); // الاحتفاظ بالتقارير لمدة 24 ساعة

// إعدادات الإشعارات
if (!defined('NOTIFICATIONS_ENABLED')) {
    define('NOTIFICATIONS_ENABLED', true);
}
if (!defined('BROWSER_NOTIFICATIONS_ENABLED')) {
    define('BROWSER_NOTIFICATIONS_ENABLED', true);
}
if (!defined('NOTIFICATION_POLL_INTERVAL')) {
    define('NOTIFICATION_POLL_INTERVAL', 30000); // 30 ثانية - محسّن للأداء
}
if (!defined('NOTIFICATION_AUTO_REFRESH_ENABLED')) {
    define('NOTIFICATION_AUTO_REFRESH_ENABLED', true);
}

// إعدادات Telegram Bot
// للحصول على Bot Token: تحدث مع @BotFather في Telegram
// للحصول على Chat ID: أرسل رسالة للبوت ثم افتح: https://api.telegram.org/bot<YOUR_BOT_TOKEN>/getUpdates
define('TELEGRAM_BOT_TOKEN', '6286098014:AAGr6q-6mvUHYIa3elUkssoijFhY7OXBrew'); // ضع توكن البوت هنا
define('TELEGRAM_CHAT_ID', '-1003293835035'); // ضع معرف المحادثة هنا (يمكن أن يكون رقم أو -100... للمجموعات)

// إعدادات WebAuthn
define('WEBAUTHN_RP_NAME', 'نظام الإدارة المتكاملة');
define('WEBAUTHN_RP_ID', isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost');

// التحقق من HTTPS - إجبار استخدام HTTPS في الإنتاج
$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443') ||
    (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
    (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
);

// استخدام HTTPS دائماً في الإنتاج (على الاستضافة)
// فقط في localhost يمكن استخدام HTTP
$isLocalhost = (
    (isset($_SERVER['HTTP_HOST']) && ($_SERVER['HTTP_HOST'] === 'localhost' || strpos($_SERVER['HTTP_HOST'], 'localhost:') === 0 || $_SERVER['HTTP_HOST'] === '127.0.0.1' || strpos($_SERVER['HTTP_HOST'], '127.0.0.1:') === 0))
);

$webauthnProtocol = ($isHttps || !$isLocalhost) ? 'https' : 'http';
define('WEBAUTHN_ORIGIN', $webauthnProtocol . '://' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost'));

// إعدادات التصميم
define('PRIMARY_COLOR', '#1e3a5f');
define('SECONDARY_COLOR', '#2c5282');
define('ACCENT_COLOR', '#3498db');

// تمكين عرض الأخطاء في وضع التطوير (يجب تعطيله في الإنتاج)
error_reporting(E_ALL);
ini_set('display_errors', 1);
// TODO: تعطيل عرض الأخطاء عند الانتقال للإنتاج
// error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
// ini_set('display_errors', 0);
// ini_set('log_errors', 1);

// إعدادات UTF-8
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

/**
 * دالة مساعدة لمسح الكاش بعد العمليات بشكل فوري
 * يمكن استدعاؤها من أي مكان لضمان ظهور النتائج بشكل لحظي
 * يتم المسح فوراً بدون انتظار لضمان التحديث الفوري
 */
function clearCache() {
    if (file_exists(__DIR__ . '/cache.php')) {
        require_once __DIR__ . '/cache.php';
        if (class_exists('Cache')) {
            try {
                // مسح الكاش فوراً بشكل متزامن
                Cache::flush();
            } catch (Exception $e) {
                error_log("Cache flush error: " . $e->getMessage());
            } catch (Throwable $e) {
                error_log("Cache flush error (Throwable): " . $e->getMessage());
            }
        }
    }
}

// دالة مساعدة للحصول على اللغة الحالية
function getCurrentLanguage() {
    return $_SESSION['language'] ?? DEFAULT_LANGUAGE;
}

// دالة مساعدة للحصول على رمز العملة بعد تنظيفه من 262145
function getCurrencySymbol() {
    $symbol = defined('CURRENCY_SYMBOL') ? CURRENCY_SYMBOL : 'ج.م';
    // تنظيف رمز العملة من 262145
    $symbol = str_replace('262145', '', $symbol);
    $symbol = preg_replace('/262145\s*/', '', $symbol);
    $symbol = preg_replace('/\s*262145/', '', $symbol);
    $symbol = trim($symbol);
    // إذا أصبح فارغاً بعد التنظيف، استخدم القيمة الافتراضية
    if (empty($symbol)) {
        $symbol = 'ج.م';
    }
    return $symbol;
}

// دالة مساعدة لتنسيق الأرقام
function formatCurrency($amount, $allowNegative = true) {
    // تنظيف القيمة باستخدام cleanFinancialValue
    // السماح بالقيم السالبة افتراضياً لأنها تستخدم للرصيد الدائن للعملاء
    $amount = cleanFinancialValue($amount, $allowNegative);
    
    // استخدام getCurrencySymbol للحصول على رمز العملة المنظف
    $currencySymbol = function_exists('getCurrencySymbol') ? getCurrencySymbol() : (defined('CURRENCY_SYMBOL') ? CURRENCY_SYMBOL : 'ج.م');
    
    $formatted = number_format($amount, 2, '.', ',') . ' ' . $currencySymbol;
    
    // حذف أي آثار لـ 262145 من النص النهائي (حماية إضافية)
    $formatted = str_replace('262145', '', $formatted);
    $formatted = str_replace('262,145', '', $formatted);
    $formatted = preg_replace('/\s+/', ' ', $formatted);
    
    return trim($formatted);
}

/**
 * دالة لتنظيف القيم المالية وضمان صحتها
 * Validate and clean financial values
 * @param mixed $value القيمة المراد تنظيفها
 * @param bool $allowNegative السماح بالقيم السالبة (للرصيد الدائن)
 */
function cleanFinancialValue($value, $allowNegative = false) {
    // إذا كانت القيمة null أو فارغة، إرجاع 0
    if ($value === null || $value === '' || $value === false) {
        return 0;
    }
    
    // تحويل إلى نص أولاً
    $valueStr = (string)$value;

    // إزالة آثار الرقم الافتراضي القديم 262145 إن وُجدت بأي شكل
    $valueStr = str_replace('262145', '', $valueStr);
    $valueStr = preg_replace('/262145\s*/', '', $valueStr);
    $valueStr = preg_replace('/\s*262145/', '', $valueStr);

    // إزالة أي أحرف غير رقمية (باستثناء النقطة والعلامة السالبة)
    $valueStr = preg_replace('/[^0-9.\-]/', '', trim($valueStr));
    
    // إذا أصبح النص فارغاً بعد التنظيف، إرجاع 0
    if (empty($valueStr) || $valueStr === '-') {
        return 0;
    }
    
    // تحويل إلى رقم
    $value = floatval($valueStr);
    
    // التحقق من القيم غير المنطقية
    if (is_nan($value) || is_infinite($value)) {
        return 0;
    }
    
    // التحقق من القيم الكبيرة جداً أو السالبة
    if ($allowNegative) {
        // إذا كان مسموحاً بالقيم السالبة (للرصيد الدائن)، فقط التحقق من الحد الأقصى
        // القيم المقبولة: من -1000000 إلى 1000000
        if ($value > 1000000 || $value < -1000000) {
            return 0;
        }
    } else {
        // القيم المقبولة: من 0 إلى 10000 جنيه/ساعة (للأجور والمدفوعات)
        if ($value > 10000 || $value < 0) {
            return 0;
        }
    }
    
    // تقريب إلى منزلتين عشريتين
    return round($value, 2);
}

// دالة مساعدة لتنسيق التاريخ
function formatDate($date, $format = DATE_FORMAT) {
    if (empty($date)) return '';
    $timestamp = is_numeric($date) ? $date : strtotime($date);
    return date($format, $timestamp);
}

// دالة مساعدة لتنسيق الوقت
function formatTime($time, $format = TIME_FORMAT) {
    if (empty($time)) return '';
    $timestamp = is_numeric($time) ? $time : strtotime($time);
    return date($format, $timestamp);
}

// دالة مساعدة لتنسيق التاريخ والوقت
function formatDateTime($datetime, $format = DATETIME_FORMAT) {
    if (empty($datetime)) return '';
    $timestamp = is_numeric($datetime) ? $datetime : strtotime($datetime);
    return date($format, $timestamp);
}

// دالة مساعدة لتنسيق الساعات من الصيغة العشرية إلى ساعات ودقائق
// مثال: 2.30 ساعة → "2 ساعة و 30 دقيقة"
function formatHours($decimalHours) {
    if (empty($decimalHours) || $decimalHours == 0) {
        return '0 ساعة';
    }
    
    $decimalHours = floatval($decimalHours);
    
    // استخراج الساعات الكاملة
    $hours = floor($decimalHours);
    
    // استخراج الدقائق من الجزء العشري
    $decimalPart = $decimalHours - $hours;
    $minutes = round($decimalPart * 60);
    
    // إذا كانت الدقائق 60، أضف ساعة واحدة
    if ($minutes >= 60) {
        $hours += 1;
        $minutes = 0;
    }
    
    // بناء النص
    $parts = [];
    if ($hours > 0) {
        $parts[] = $hours . ' ساعة';
    }
    if ($minutes > 0) {
        $parts[] = $minutes . ' دقيقة';
    }
    
    if (empty($parts)) {
        return '0 ساعة';
    }
    
    return implode(' و ', $parts);
}

// دالة مساعدة للحصول على الاتجاه (RTL/LTR)
function getDirection() {
    return getCurrentLanguage() === 'ar' ? 'rtl' : 'ltr';
}

// دالة مساعدة للحصول على الاتجاه المعاكس في CSS
function getTextAlign() {
    return getCurrentLanguage() === 'ar' ? 'right' : 'left';
}

/**
 * منع تكرار الطلبات عند refresh
 * يستخدم Post-Redirect-Get (PRG) pattern
 * 
 * @param string|null $successMessage رسالة النجاح (اختياري)
 * @param array $redirectParams معاملات إعادة التوجيه
 * @param string|null $redirectUrl URL لإعادة التوجيه (اختياري)
 * @param string|null $role دور المستخدم لإعادة التوجيه (للاستخدام مع getDashboardUrl)
 * @param string|null $errorMessage رسالة الخطأ (اختياري)
 */
function preventDuplicateSubmission($successMessage = null, $redirectParams = [], $redirectUrl = null, $role = null, $errorMessage = null) {
    // إذا كانت هناك رسالة نجاح، حفظها في session
    if ($successMessage !== null && $successMessage !== '') {
        $_SESSION['success_message'] = $successMessage;
        error_log("preventDuplicateSubmission: Saved success message to session: " . $successMessage);
    }
    
    // إذا كانت هناك رسالة خطأ، حفظها في session
    if ($errorMessage !== null && $errorMessage !== '') {
        $_SESSION['error_message'] = $errorMessage;
        error_log("preventDuplicateSubmission: Saved error message to session: " . $errorMessage);
    }
    
    // بناء URL إعادة التوجيه
    if ($redirectUrl === null) {
        // إذا كان هناك role و page في redirectParams، استخدم getDashboardUrl
        if ($role !== null && isset($redirectParams['page'])) {
            require_once __DIR__ . '/path_helper.php';
            $page = $redirectParams['page'];
            unset($redirectParams['page']);
            
            $baseUrl = getDashboardUrl($role);
            if (!empty($redirectParams)) {
                $queryString = http_build_query($redirectParams);
                $redirectUrl = $baseUrl . '?page=' . urlencode($page) . '&' . $queryString;
            } else {
                $redirectUrl = $baseUrl . '?page=' . urlencode($page);
            }
        } else {
            // استخدام URL الحالي بدون POST parameters
            $currentUrl = $_SERVER['REQUEST_URI'];
            $urlParts = parse_url($currentUrl);
            $path = $urlParts['path'] ?? '';
            
            // إضافة GET parameters إذا كانت موجودة
            if (!empty($redirectParams)) {
                $queryString = http_build_query($redirectParams);
                $redirectUrl = $path . '?' . $queryString;
            } else {
                // إزالة query string من URL الحالي
                $redirectUrl = $path;
            }
        }
    }
    
    // إضافة cache-busting parameters لضمان تحديث الصفحة بعد إنشاء/تحديث البيانات
    // هذا يمنع المتصفح من عرض نسخة قديمة من الصفحة
    $separator = (strpos($redirectUrl, '?') !== false) ? '&' : '?';
    $timestamp = time();
    
    // إذا كان _v موجود في redirectParams، استخدمه، وإلا أضف _t و _v جديدين
    if (isset($redirectParams['_v'])) {
        $redirectUrl .= $separator . '_v=' . $redirectParams['_v'] . '&_t=' . $redirectParams['_v'];
    } else {
        $redirectUrl .= $separator . '_v=' . $timestamp . '&_t=' . $timestamp;
    }
    
    // تنظيف شامل: إزالة أي بروتوكول أو hostname أو منفذ من redirectUrl
    // 1. إزالة أي بروتوكول كامل مع hostname ومنفذ
    $redirectUrl = preg_replace('/^https?:\/\/[^\/]+(:[0-9]+)?/', '', $redirectUrl);
    $redirectUrl = preg_replace('/^\/\//', '/', $redirectUrl);
    
    // 2. إزالة أي hostname مع منفذ إذا كان موجوداً
    if (preg_match('/^\/[^\/]+:[0-9]+\//', $redirectUrl)) {
        $redirectUrl = preg_replace('/^\/[^\/]+:[0-9]+/', '', $redirectUrl);
    }
    
    // 3. التأكد من أن المسار نسبي فقط (يبدأ بـ /)
    if (!preg_match('/^https?:\/\//i', $redirectUrl)) {
        // استخدام substr بدلاً من str_starts_with للتوافق مع PHP < 8.0
        if (substr($redirectUrl, 0, 1) !== '/') {
            $redirectUrl = '/' . ltrim($redirectUrl, '/');
        }
    } else {
        // إذا كان لا يزال يحتوي على بروتوكول، استخراج المسار فقط
        $parsed = parse_url($redirectUrl);
        $redirectUrl = $parsed['path'] ?? '/';
        if (!empty($parsed['query'])) {
            $redirectUrl .= '?' . $parsed['query'];
        }
    }
    
    // 4. إزالة أي منفذ من المسار (للاحتياط)
    $redirectUrl = preg_replace('/:[0-9]+\//', '/', $redirectUrl);
    $redirectUrl = preg_replace('/:[0-9]+$/', '', $redirectUrl);
    
    // 5. تنظيف نهائي
    $redirectUrl = preg_replace('/\/+/', '/', $redirectUrl);
    
    // إضافة headers لمنع caching عند إعادة التوجيه
    header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // التحقق من أن headers لم يتم إرسالها بعد
    if (headers_sent($file, $line)) {
        // إذا تم إرسال headers بالفعل، استخدم JavaScript redirect مع force reload
        echo '<script>';
        echo 'window.location.replace(' . json_encode($redirectUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ');';
        echo '</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8') . '"></noscript>';
        exit;
    }
    
    // إعادة التوجيه مع force reload
    header('Location: ' . $redirectUrl, true, 303); // 303 See Other يمنع caching
    exit;
}

/**
 * التحقق من وجود رسالة نجاح في session وعرضها
 * يجب استدعاؤها في بداية الصفحة بعد معالجة POST
 * 
 * @return string|null رسالة النجاح أو null
 */
function getSuccessMessage() {
    // استخدام request ID لمنع قراءة الرسالة مرتين في نفس الطلب
    $requestId = $_SERVER['REQUEST_TIME_FLOAT'] . '_' . (session_id() ?: 'nosession');
    
    if (isset($_SESSION['success_message_read_request_id']) && 
        $_SESSION['success_message_read_request_id'] === $requestId) {
        // تم قراءة الرسالة بالفعل في هذا الطلب
        return null;
    }
    
    if (isset($_SESSION['success_message'])) {
        $message = $_SESSION['success_message'];
        unset($_SESSION['success_message']);
        $_SESSION['success_message_read_request_id'] = $requestId; // وضع flag أن الرسالة تم قراءتها
        return $message;
    }
    return null;
}

/**
 * التحقق من وجود رسالة خطأ في session وعرضها
 * يجب استدعاؤها في بداية الصفحة بعد معالجة POST
 * 
 * @return string|null رسالة الخطأ أو null
 */
function getErrorMessage() {
    // استخدام request ID لمنع قراءة الرسالة مرتين في نفس الطلب
    $requestId = $_SERVER['REQUEST_TIME_FLOAT'] . '_' . (session_id() ?: 'nosession');
    
    if (isset($_SESSION['error_message_read_request_id']) && 
        $_SESSION['error_message_read_request_id'] === $requestId) {
        // تم قراءة الرسالة بالفعل في هذا الطلب
        return null;
    }
    
    if (isset($_SESSION['error_message'])) {
        $message = $_SESSION['error_message'];
        unset($_SESSION['error_message']);
        $_SESSION['error_message_read_request_id'] = $requestId; // وضع flag أن الرسالة تم قراءتها
        return $message;
    }
    return null;
}

/**
 * دالة مساعدة لتطبيق PRG pattern على الطلبات POST
 * تقرأ الرسائل من session وتعرضها
 * 
 * @param string|null $defaultError متغير لرسالة الخطأ الافتراضي
 * @param string|null $defaultSuccess متغير لرسالة النجاح الافتراضي
 * @return void
 */
function applyPRGPattern(&$defaultError = null, &$defaultSuccess = null) {
    $sessionSuccess = getSuccessMessage();
    $sessionError = getErrorMessage();
    
    if ($sessionSuccess !== null) {
        $defaultSuccess = $sessionSuccess;
    }
    
    if ($sessionError !== null) {
        $defaultError = $sessionError;
    }
}


if (!defined('ENABLE_DAILY_LOW_STOCK_REPORT')) {
    define('ENABLE_DAILY_LOW_STOCK_REPORT', true);
}
if (!defined('ENABLE_DAILY_PACKAGING_ALERT')) {
    define('ENABLE_DAILY_PACKAGING_ALERT', true);
}
if (!defined('ENABLE_DAILY_CONSUMPTION_REPORT')) {
    define('ENABLE_DAILY_CONSUMPTION_REPORT', false);
}
if (!defined('ENABLE_PAGE_LOADER')) {
    define('ENABLE_PAGE_LOADER', true);
}
if (!defined('ENABLE_DAILY_BACKUP_DELIVERY')) {
    define('ENABLE_DAILY_BACKUP_DELIVERY', true);
}
if (!defined('ENABLE_DAILY_ATTENDANCE_PHOTOS_CLEANUP')) {
    define('ENABLE_DAILY_ATTENDANCE_PHOTOS_CLEANUP', true);
}

# وظيفة مساعده لجدولة المهام اليومية بفاصل زمني
if (ENABLE_DAILY_LOW_STOCK_REPORT) {
    require_once __DIR__ . '/daily_low_stock_report.php';
    triggerDailyLowStockReport();
}

if (ENABLE_DAILY_PACKAGING_ALERT) {
    require_once __DIR__ . '/packaging_alerts.php';
    processDailyPackagingAlert();
}

if (ENABLE_DAILY_CONSUMPTION_REPORT) {
    require_once __DIR__ . '/daily_consumption_sender.php';
    triggerDailyConsumptionReport();
}

if (ENABLE_DAILY_BACKUP_DELIVERY) {
    require_once __DIR__ . '/daily_backup_sender.php';
    triggerDailyBackupDelivery();
}

/**
 * تشغيل تنظيف صور الحضور والانصراف تلقائياً مرة واحدة يومياً
 * يتم تشغيله مع أول زائر لأي صفحة من صفحات الموقع
 */
if (ENABLE_DAILY_ATTENDANCE_PHOTOS_CLEANUP) {
    // ملف العلم لتتبع آخر مرة تم فيها التنظيف
    $cleanupFlagFile = PRIVATE_STORAGE_PATH . '/logs/attendance_photos_cleanup_last_run.txt';
    $today = date('Y-m-d');
    $shouldRun = false;
    
    // التحقق من آخر مرة تم فيها التنظيف
    if (file_exists($cleanupFlagFile)) {
        $lastRunDate = trim(@file_get_contents($cleanupFlagFile));
        if ($lastRunDate !== $today) {
            $shouldRun = true;
        }
    } else {
        // إذا لم يكن الملف موجوداً، قم بالتنظيف
        $shouldRun = true;
    }
    
    // تشغيل التنظيف إذا لزم الأمر
    if ($shouldRun) {
        try {
            // حفظ تاريخ اليوم في ملف العلم أولاً لمنع التشغيل المتكرر
            $logsDir = dirname($cleanupFlagFile);
            if (!is_dir($logsDir)) {
                @mkdir($logsDir, 0755, true);
            }
            @file_put_contents($cleanupFlagFile, $today, LOCK_EX);
            
            // تحميل الملفات المطلوبة
            if (!function_exists('cleanupOldAttendancePhotos')) {
                require_once __DIR__ . '/attendance.php';
            }
            if (!function_exists('db')) {
                require_once __DIR__ . '/db.php';
            }
            
            // تشغيل التنظيف (30 يوم كافتراضي)
            if (function_exists('cleanupOldAttendancePhotos')) {
                $stats = cleanupOldAttendancePhotos(30);
                
                // تسجيل النتائج
                $message = sprintf(
                    "Attendance photos cleanup (automatic daily): %d files deleted, %d folders deleted, %.2f MB freed, %d errors",
                    $stats['deleted_files'],
                    $stats['deleted_folders'],
                    $stats['total_size_freed'] / (1024 * 1024),
                    $stats['errors']
                );
                error_log($message);
            }
        } catch (Exception $e) {
            error_log('Daily attendance photos cleanup error: ' . $e->getMessage());
            // في حالة الخطأ، احذف ملف العلم للسماح بإعادة المحاولة لاحقاً
            if (file_exists($cleanupFlagFile)) {
                @unlink($cleanupFlagFile);
            }
        } catch (Throwable $e) {
            error_log('Daily attendance photos cleanup error: ' . $e->getMessage());
            // في حالة الخطأ، احذف ملف العلم للسماح بإعادة المحاولة لاحقاً
            if (file_exists($cleanupFlagFile)) {
                @unlink($cleanupFlagFile);
            }
        }
    }
}

/**
 * تشغيل إنشاء الرواتب التلقائي مرة واحدة كل يوم
 * يتم فحص قاعدة البيانات أولاً للتأكد من عدم التنفيذ اليوم
 * إذا لم يتم التنفيذ، ينفذ العملية ثم يسجل التاريخ
 */
if (function_exists('isLoggedIn') && isLoggedIn()) {
    try {
        if (!function_exists('runAutoSalaryInit')) {
            require_once __DIR__ . '/auto_salary_init.php';
        }
        if (function_exists('runAutoSalaryInit')) {
            runAutoSalaryInit();
        }
    } catch (Exception $e) {
        error_log('Auto salary init error in config.php: ' . $e->getMessage());
    } catch (Throwable $e) {
        error_log('Auto salary init error in config.php: ' . $e->getMessage());
    }
}


