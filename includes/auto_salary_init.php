<?php
/**
 * ملف إنشاء الرواتب التلقائي
 * يتم استدعاؤه عند أول زيارة للموقع من أي حساب
 * يقوم بإنشاء الرواتب للموظفين مرة واحدة لكل شهر
 * 
 * @author System
 * @version 1.0
 */

// منع الوصول المباشر
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

/**
 * إنشاء الرواتب التلقائية للشهر الحالي
 * يتم تنفيذها مرة واحدة فقط لكل شهر
 * 
 * @return array نتيجة العملية
 */
function initializeMonthSalaries() {
    // التحقق من تسجيل الدخول
    if (!function_exists('isLoggedIn') || !isLoggedIn()) {
        return ['success' => false, 'message' => 'User not logged in', 'created' => 0];
    }
    
    // الحصول على الشهر والسنة الحاليين
    $currentMonth = (int)date('n');
    $currentYear = (int)date('Y');
    
    // التحقق الصارم من صحة الشهر والسنة
    if ($currentMonth < 1 || $currentMonth > 12) {
        error_log("auto_salary_init: Invalid current month: {$currentMonth}");
        return ['success' => false, 'message' => 'Invalid month', 'created' => 0];
    }
    
    if ($currentYear < 2000 || $currentYear > 2100) {
        error_log("auto_salary_init: Invalid current year: {$currentYear}");
        return ['success' => false, 'message' => 'Invalid year', 'created' => 0];
    }
    
    // مفتاح الجلسة لمنع التكرار
    $sessionKey = "auto_salaries_initialized_{$currentYear}_{$currentMonth}";
    
    // التحقق من أن العملية لم تتم من قبل في هذه الجلسة
    if (isset($_SESSION[$sessionKey]) && $_SESSION[$sessionKey] === true) {
        return ['success' => true, 'message' => 'Already initialized this session', 'created' => 0, 'skipped' => true];
    }
    
    try {
        $db = db();
        
        // التحقق من وجود جدول salaries
        $tableCheck = $db->queryOne("SHOW TABLES LIKE 'salaries'");
        if (empty($tableCheck)) {
            error_log("auto_salary_init: salaries table does not exist");
            return ['success' => false, 'message' => 'Salaries table not found', 'created' => 0];
        }
        
        // التحقق من وجود علامة في قاعدة البيانات لهذا الشهر
        // نستخدم جدول system_settings لتخزين حالة التهيئة
        $settingKey = "salaries_initialized_{$currentYear}_{$currentMonth}";
        
        // التحقق من وجود جدول system_settings
        $settingsTableCheck = $db->queryOne("SHOW TABLES LIKE 'system_settings'");
        if (!empty($settingsTableCheck)) {
            $existingSetting = $db->queryOne(
                "SELECT `value` FROM system_settings WHERE `key` = ?",
                [$settingKey]
            );
            
            if (!empty($existingSetting) && $existingSetting['value'] === '1') {
                // تم التهيئة من قبل لهذا الشهر
                $_SESSION[$sessionKey] = true;
                return ['success' => true, 'message' => 'Already initialized this month', 'created' => 0, 'skipped' => true];
            }
        }
        
        // التحقق من نوع عمود month
        $monthColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries WHERE Field = 'month'");
        $monthType = $monthColumnCheck['Type'] ?? '';
        $isMonthDate = stripos($monthType, 'date') !== false;
        
        // التحقق من وجود عمود year
        $yearColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'year'");
        $hasYearColumn = !empty($yearColumnCheck);
        
        // إعداد التاريخ الصحيح
        $targetDate = sprintf('%04d-%02d-01', $currentYear, $currentMonth);
        $targetYearMonth = sprintf('%04d-%02d', $currentYear, $currentMonth);
        
        error_log("auto_salary_init: Starting initialization for {$currentMonth}/{$currentYear} (targetDate: {$targetDate}, isMonthDate: " . ($isMonthDate ? 'true' : 'false') . ")");
        
        // الحصول على قائمة الموظفين النشطين (باستثناء المديرين)
        $users = $db->query(
            "SELECT id, full_name, username, hourly_rate, role 
             FROM users 
             WHERE status = 'active' 
             AND role != 'manager' 
             AND hourly_rate > 0
             ORDER BY role, full_name"
        );
        
        if (empty($users)) {
            error_log("auto_salary_init: No active users found with hourly_rate > 0");
            $_SESSION[$sessionKey] = true;
            return ['success' => true, 'message' => 'No users to process', 'created' => 0];
        }
        
        // تحميل salary_calculator إذا لم يكن محملاً
        if (!function_exists('createOrUpdateSalary')) {
            $salaryCalcPath = __DIR__ . '/salary_calculator.php';
            if (file_exists($salaryCalcPath)) {
                require_once $salaryCalcPath;
            } else {
                error_log("auto_salary_init: salary_calculator.php not found");
                return ['success' => false, 'message' => 'Salary calculator not found', 'created' => 0];
            }
        }
        
        $createdCount = 0;
        $skippedCount = 0;
        $errorCount = 0;
        $totalUsers = count($users);
        
        foreach ($users as $user) {
            $userId = intval($user['id']);
            $hourlyRate = floatval($user['hourly_rate'] ?? 0);
            $userName = $user['full_name'] ?? $user['username'] ?? "User {$userId}";
            
            // تخطي المستخدمين بدون سعر ساعة
            if ($hourlyRate <= 0) {
                $skippedCount++;
                continue;
            }
            
            // التحقق من وجود راتب للمستخدم في الشهر الحالي
            $hasSalary = false;
            
            try {
                if ($isMonthDate) {
                    // عمود month من نوع DATE
                    $existingSalary = $db->queryOne(
                        "SELECT id FROM salaries WHERE user_id = ? AND DATE_FORMAT(month, '%Y-%m') = ? AND month != '0000-00-00' AND month IS NOT NULL LIMIT 1",
                        [$userId, $targetYearMonth]
                    );
                    $hasSalary = !empty($existingSalary);
                } elseif ($hasYearColumn) {
                    // عمود month من نوع INT مع وجود عمود year
                    $existingSalary = $db->queryOne(
                        "SELECT id FROM salaries WHERE user_id = ? AND month = ? AND year = ? AND month > 0 AND year > 0 LIMIT 1",
                        [$userId, $currentMonth, $currentYear]
                    );
                    $hasSalary = !empty($existingSalary);
                } else {
                    // عمود month من نوع INT بدون عمود year
                    $existingSalary = $db->queryOne(
                        "SELECT id FROM salaries WHERE user_id = ? AND month = ? AND month > 0 LIMIT 1",
                        [$userId, $currentMonth]
                    );
                    $hasSalary = !empty($existingSalary);
                }
                
                if ($hasSalary) {
                    $skippedCount++;
                    continue;
                }
                
                // إنشاء الراتب
                $result = createOrUpdateSalary(
                    $userId,
                    $currentMonth,
                    $currentYear,
                    0, // bonus
                    0, // deductions
                    'إنشاء تلقائي عند أول زيارة للموقع'
                );
                
                if ($result['success']) {
                    $createdCount++;
                    error_log("auto_salary_init: Created salary for user {$userId} ({$userName})");
                } else {
                    $errorCount++;
                    error_log("auto_salary_init: Failed to create salary for user {$userId} ({$userName}): " . ($result['message'] ?? 'Unknown error'));
                }
                
            } catch (Exception $e) {
                $errorCount++;
                error_log("auto_salary_init: Exception for user {$userId} ({$userName}): " . $e->getMessage());
            }
        }
        
        // تسجيل ملخص العملية
        error_log("auto_salary_init: Summary - Created: {$createdCount}, Skipped: {$skippedCount}, Errors: {$errorCount}, Total: {$totalUsers}");
        
        // تسجيل علامة التهيئة في قاعدة البيانات
        if (!empty($settingsTableCheck)) {
            try {
                $db->execute(
                    "INSERT INTO system_settings (`key`, `value`, updated_at) VALUES (?, '1', NOW())
                     ON DUPLICATE KEY UPDATE `value` = '1', updated_at = NOW()",
                    [$settingKey]
                );
            } catch (Exception $e) {
                error_log("auto_salary_init: Failed to save setting: " . $e->getMessage());
            }
        }
        
        // تسجيل علامة الجلسة
        $_SESSION[$sessionKey] = true;
        
        return [
            'success' => true,
            'message' => "تم إنشاء {$createdCount} راتب للشهر الحالي",
            'created' => $createdCount,
            'skipped' => $skippedCount,
            'errors' => $errorCount,
            'total' => $totalUsers,
            'month' => $currentMonth,
            'year' => $currentYear
        ];
        
    } catch (Exception $e) {
        error_log("auto_salary_init: Critical error: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage(), 'created' => 0];
    }
}

/**
 * تنفيذ التهيئة التلقائية
 * يتم استدعاؤها تلقائياً عند تحميل الملف
 */
function runAutoSalaryInit() {
    // التحقق من أن المستخدم مسجل الدخول
    if (!function_exists('isLoggedIn')) {
        error_log("auto_salary_init: isLoggedIn function not found");
        return;
    }
    
    if (!isLoggedIn()) {
        error_log("auto_salary_init: User not logged in, skipping");
        return;
    }
    
    // التحقق من أن هذا ليس طلب AJAX أو API
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $isApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;
    
    if ($isAjax || $isApi) {
        error_log("auto_salary_init: AJAX/API request, skipping");
        return;
    }
    
    error_log("auto_salary_init: Starting auto salary initialization");
    
    // تنفيذ التهيئة
    $result = initializeMonthSalaries();
    
    // تسجيل النتيجة
    if (isset($result['created']) && $result['created'] > 0) {
        error_log("auto_salary_init: SUCCESS - Auto-created {$result['created']} salaries for month {$result['month']}/{$result['year']}");
    } elseif (isset($result['skipped']) && $result['skipped']) {
        error_log("auto_salary_init: Skipped - Already initialized or no users to process");
    } else {
        error_log("auto_salary_init: Result - " . json_encode($result));
    }
}

// تنفيذ التهيئة التلقائية عند تحميل الملف
runAutoSalaryInit();

