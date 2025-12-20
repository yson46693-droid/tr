<?php
/**
 * API للعمليات الخلفية الثقيلة
 * يتم استدعاؤها بشكل غير متزامن بعد تحميل الصفحة لتحسين الأداء
 */

define('ACCESS_ALLOWED', true);
// تعريف ثابت لتحديد أن هذا ملف background tasks
define('BACKGROUND_TASKS_ACTIVE', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// ========== 1. Rate Limiting ==========
/**
 * التحقق من Rate Limiting للمستخدم
 */
function checkBackgroundTasksRateLimit($userId) {
    if (!class_exists('Cache')) {
        require_once __DIR__ . '/../includes/cache.php';
    }
    
    $cacheKey = 'bg_tasks_rate_' . $userId;
    $lastCall = Cache::get($cacheKey);
    $currentTime = time();
    
    // الحد الأقصى: مرة واحدة كل 30 ثانية لكل مستخدم
    $minInterval = 30;
    
    if ($lastCall && ($currentTime - $lastCall) < $minInterval) {
        return false; // تم استدعاؤه مؤخراً
    }
    
    // تحديث وقت آخر استدعاء
    Cache::put($cacheKey, $currentTime, 60); // حفظ لمدة دقيقة
    return true;
}

// ========== 2. Lock Mechanism لمنع التنفيذ المتزامن ==========
/**
 * الحصول على Lock للعمليات الخلفية
 */
function acquireBackgroundTasksLock($lockKey) {
    $lockDir = sys_get_temp_dir();
    $lockFile = $lockDir . DIRECTORY_SEPARATOR . 'bg_tasks_' . md5($lockKey) . '.lock';
    
    $fp = @fopen($lockFile, 'c+');
    if (!$fp) {
        return false;
    }
    
    // محاولة الحصول على lock غير متزامن (non-blocking)
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return false; // هناك عملية أخرى قيد التنفيذ
    }
    
    // كتابة معلومات Lock
    fwrite($fp, json_encode([
        'lock_key' => $lockKey,
        'started_at' => time(),
        'pid' => getmypid()
    ]));
    fflush($fp);
    
    return $fp;
}

/**
 * إطلاق Lock
 */
function releaseBackgroundTasksLock($fp) {
    if ($fp && is_resource($fp)) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

// ========== التحقق من تسجيل الدخول مع معالجة خاصة للأخطاء ==========
$isLoggedIn = false;
$currentUser = null;

// التحقق من وجود الجلسة أولاً (دون الوصول لقاعدة البيانات)
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// محاولة التحقق من المستخدم مع معالجة الأخطاء
try {
    $isLoggedIn = isLoggedIn();
} catch (Throwable $e) {
    // في حالة خطأ في قاعدة البيانات، نعتبر المستخدم مسجل دخول إذا كانت الجلسة موجودة
    error_log('Background tasks: isLoggedIn() error (non-critical): ' . $e->getMessage());
    $isLoggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// إذا فشل التحقق من isLoggedIn، حاول التحقق من الجلسة مباشرة
if (!$isLoggedIn && isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    // محاولة جلب المستخدم مباشرة
    try {
        $db = db();
        $currentUser = $db->queryOne("SELECT * FROM users WHERE id = ? AND status = 'active'", [$_SESSION['user_id']]);
        if ($currentUser) {
            $isLoggedIn = true;
        }
    } catch (Throwable $dbError) {
        error_log('Background tasks: Direct user fetch error: ' . $dbError->getMessage());
        // في حالة timeout في قاعدة البيانات، نعتبر أن الجلسة صحيحة لكن لا يمكننا تنفيذ المهام
        // نرجع 503 Service Unavailable بدلاً من 401 لتجنب إظهار رسالة تسجيل دخول
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Retry-After: 30');
        echo json_encode([
            'success' => false, 
            'message' => 'Service temporarily unavailable',
            'retry_after' => 30
        ]);
        exit;
    }
}

if (!$isLoggedIn) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// محاولة جلب معلومات المستخدم (إذا لم يتم جلبها مسبقاً)
if (!$currentUser) {
    try {
        $currentUser = getCurrentUser();
    } catch (Throwable $e) {
        // في حالة خطأ في getCurrentUser، حاول جلب المستخدم مباشرة
        error_log('Background tasks: getCurrentUser() error (non-critical): ' . $e->getMessage());
        if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
            try {
                $db = db();
                $currentUser = $db->queryOne("SELECT * FROM users WHERE id = ? AND status = 'active'", [$_SESSION['user_id']]);
            } catch (Throwable $dbError) {
                error_log('Background tasks: Direct user fetch error (fallback): ' . $dbError->getMessage());
                // في حالة timeout في قاعدة البيانات، نعتبر أن الجلسة صحيحة لكن لا يمكننا تنفيذ المهام
                http_response_code(503);
                header('Content-Type: application/json; charset=utf-8');
                header('Retry-After: 30');
                echo json_encode([
                    'success' => false, 
                    'message' => 'Service temporarily unavailable',
                    'retry_after' => 30
                ]);
                exit;
            }
        }
    }
}

if (!$currentUser) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = (int) ($currentUser['id'] ?? 0);

// ========== 3. Rate Limiting Check ==========
if (!checkBackgroundTasksRateLimit($userId)) {
    http_response_code(429); // Too Many Requests
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'Rate limit exceeded. Please wait before trying again.',
        'retry_after' => 30
    ]);
    exit;
}

// ========== 4. Lock Check لمنع التنفيذ المتزامن ==========
$lockFile = acquireBackgroundTasksLock('user_' . $userId);
if (!$lockFile) {
    // هناك عملية أخرى قيد التنفيذ
    http_response_code(409); // Conflict
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'Another background task is already running',
        'status' => 'locked'
    ]);
    exit;
}

// تنظيف Lock عند الانتهاء
register_shutdown_function(function() use ($lockFile) {
    releaseBackgroundTasksLock($lockFile);
});

// ========== 5. تقليل Timeout ==========
// تعيين timeout أقصر للعمليات الخلفية (15 ثانية بدلاً من 30)
set_time_limit(15);
ignore_user_abort(false); // السماح بإلغاء الطلب إذا أغلقه المستخدم

header('Content-Type: application/json; charset=utf-8');

// قائمة العمليات المطلوب تنفيذها
$results = [];

// ========== ملاحظة: تم إزالة handleAttendanceRemindersForUser ==========
// لأنها يتم استدعاؤها في header.php مباشرة لتظهر الإشعارات فوراً
// لا حاجة لتكرارها هنا

// 1. معالجة تنبيهات التعبئة اليومية (مرة واحدة يومياً فقط)
if (function_exists('processDailyPackagingAlert')) {
    try {
        // استخدام Lock Mechanism + Cache للتحقق من التنفيذ
        if (!class_exists('Cache')) {
            require_once __DIR__ . '/../includes/cache.php';
        }
        
        $lockKey = 'packaging_alert_' . date('Y-m-d');
        $cacheKey = 'packaging_alert_' . date('Y-m-d');
        
        // محاولة الحصول على lock
        $taskLock = acquireBackgroundTasksLock($lockKey);
        
        if ($taskLock) {
            // التحقق من Cache
            $alreadyProcessed = Cache::get($cacheKey);
            
            if (!$alreadyProcessed) {
                processDailyPackagingAlert();
                Cache::put($cacheKey, true, 86400); // 24 ساعة
                $results['packaging_alert'] = ['success' => true];
            } else {
                $results['packaging_alert'] = ['success' => true, 'skipped' => 'already_processed'];
            }
            
            releaseBackgroundTasksLock($taskLock);
        } else {
            $results['packaging_alert'] = ['success' => false, 'error' => 'Lock acquisition failed'];
        }
    } catch (Throwable $e) {
        error_log('Background task: Packaging alert error: ' . $e->getMessage());
        $results['packaging_alert'] = ['success' => false, 'error' => $e->getMessage()];
    }
}

// 2. معالجة الانصراف التلقائي (مرة واحدة يومياً فقط)
if (function_exists('processAutoCheckoutForMissingEmployees')) {
    try {
        // استخدام Lock Mechanism + Cache
        if (!class_exists('Cache')) {
            require_once __DIR__ . '/../includes/cache.php';
        }
        
        $cacheKey = 'auto_checkout_' . date('Y-m-d');
        $lockKey = 'auto_checkout_' . date('Y-m-d');
        $taskLock = acquireBackgroundTasksLock($lockKey);
        
        if ($taskLock) {
            $alreadyProcessed = Cache::get($cacheKey);
            
            if (!$alreadyProcessed) {
                processAutoCheckoutForMissingEmployees();
                Cache::put($cacheKey, true, 86400); // 24 ساعة
                $results['auto_checkout'] = ['success' => true];
            } else {
                $results['auto_checkout'] = ['success' => true, 'skipped' => 'already_processed'];
            }
            
            releaseBackgroundTasksLock($taskLock);
        } else {
            $results['auto_checkout'] = ['success' => false, 'error' => 'Lock acquisition failed'];
        }
    } catch (Throwable $e) {
        error_log('Background task: Auto checkout error: ' . $e->getMessage());
        $results['auto_checkout'] = ['success' => false, 'error' => $e->getMessage()];
    }
}

// 3. تصفير عداد الإنذارات (مرة واحدة شهرياً فقط)
if (function_exists('resetWarningCountsForNewMonth')) {
    try {
        // استخدام Lock Mechanism + Cache
        if (!class_exists('Cache')) {
            require_once __DIR__ . '/../includes/cache.php';
        }
        
        $cacheKey = 'warning_reset_' . date('Y-m');
        $lockKey = 'warning_reset_' . date('Y-m');
        $taskLock = acquireBackgroundTasksLock($lockKey);
        
        if ($taskLock) {
            $alreadyProcessed = Cache::get($cacheKey);
            
            if (!$alreadyProcessed) {
                resetWarningCountsForNewMonth();
                Cache::put($cacheKey, true, 2592000); // 30 يوم
                $results['warning_reset'] = ['success' => true];
            } else {
                $results['warning_reset'] = ['success' => true, 'skipped' => 'already_processed'];
            }
            
            releaseBackgroundTasksLock($taskLock);
        } else {
            $results['warning_reset'] = ['success' => false, 'error' => 'Lock acquisition failed'];
        }
    } catch (Throwable $e) {
        error_log('Background task: Warning reset error: ' . $e->getMessage());
        $results['warning_reset'] = ['success' => false, 'error' => $e->getMessage()];
    }
}

// 4. إشعارات المدفوعات للمبيعات
if ($currentUser && strtolower($currentUser['role']) === 'sales') {
    if (function_exists('notifyTodayPaymentSchedules')) {
        try {
            notifyTodayPaymentSchedules($userId);
            $results['payment_notifications'] = ['success' => true];
        } catch (Throwable $e) {
            error_log('Background task: Payment notifications error: ' . $e->getMessage());
            $results['payment_notifications'] = ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

// 5. التقارير الشهرية (مرة واحدة شهرياً فقط)
if (function_exists('maybeSendMonthlyProductionDetailedReport')) {
    try {
        // استخدام Lock Mechanism + Cache
        if (!class_exists('Cache')) {
            require_once __DIR__ . '/../includes/cache.php';
        }
        
        $month = (int) date('n');
        $year = (int) date('Y');
        $cacheKey = 'production_report_' . $year . '_' . $month;
        $lockKey = 'production_report_' . $year . '_' . $month;
        $taskLock = acquireBackgroundTasksLock($lockKey);
        
        if ($taskLock) {
            $alreadyProcessed = Cache::get($cacheKey);
            
            if (!$alreadyProcessed) {
                maybeSendMonthlyProductionDetailedReport($month, $year);
                Cache::put($cacheKey, true, 2592000); // 30 يوم
                $results['production_report'] = ['success' => true];
            } else {
                $results['production_report'] = ['success' => true, 'skipped' => 'already_processed'];
            }
            
            releaseBackgroundTasksLock($taskLock);
        } else {
            $results['production_report'] = ['success' => false, 'error' => 'Lock acquisition failed'];
        }
    } catch (Throwable $e) {
        error_log('Background task: Production report error: ' . $e->getMessage());
        $results['production_report'] = ['success' => false, 'error' => $e->getMessage()];
    }
}

// إرجاع النتائج
echo json_encode([
    'success' => true,
    'results' => $results,
    'timestamp' => time()
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
