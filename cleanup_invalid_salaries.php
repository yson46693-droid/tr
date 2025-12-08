<?php
/**
 * سكريبت تنظيف سجلات الرواتب ذات التواريخ الخاطئة
 * يقوم بحذف أو إصلاح السجلات التي تحتوي على month = 0000-00-00 أو year = 0 أو NULL
 * 
 * تشغيل السكريبت: php cleanup_invalid_salaries.php
 * أو من المتصفح: http://yoursite.com/cleanup_invalid_salaries.php
 */

// تعريف ثابت الوصول للسماح بتشغيل السكريبت
define('ACCESS_ALLOWED', true);

// تضمين ملفات النظام
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

// للأمان: التحقق من الصلاحيات (اختياري - يمكنك إزالته للتشغيل المباشر)
// require_once __DIR__ . '/includes/auth.php';
// $currentUser = getCurrentUser();
// if (!$currentUser || $currentUser['role'] !== 'admin') {
//     die('Access denied. Admin only.');
// }

header('Content-Type: text/html; charset=utf-8');

echo "<html dir='rtl'><head><title>تنظيف سجلات الرواتب</title>";
echo "<style>
    body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
    .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
    h1 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
    .success { color: green; background: #d4edda; padding: 10px; border-radius: 4px; margin: 10px 0; }
    .warning { color: #856404; background: #fff3cd; padding: 10px; border-radius: 4px; margin: 10px 0; }
    .error { color: red; background: #f8d7da; padding: 10px; border-radius: 4px; margin: 10px 0; }
    .info { color: #0c5460; background: #d1ecf1; padding: 10px; border-radius: 4px; margin: 10px 0; }
    table { width: 100%; border-collapse: collapse; margin: 20px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: right; }
    th { background: #007bff; color: white; }
    tr:nth-child(even) { background: #f9f9f9; }
    .btn { display: inline-block; padding: 10px 20px; background: #dc3545; color: white; text-decoration: none; border-radius: 4px; margin: 10px 0; }
    .btn-success { background: #28a745; }
</style></head><body>";
echo "<div class='container'>";
echo "<h1>🧹 تنظيف سجلات الرواتب ذات التواريخ الخاطئة</h1>";

try {
    $db = db();
    
    // التحقق من نوع عمود month
    $monthColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'month'");
    $monthType = $monthColumnCheck['Type'] ?? '';
    $isMonthDate = (stripos($monthType, 'date') !== false);
    
    // التحقق من وجود عمود year
    $yearColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'year'");
    $hasYearColumn = !empty($yearColumnCheck);
    
    echo "<div class='info'>";
    echo "<strong>معلومات الجدول:</strong><br>";
    echo "نوع عمود month: " . ($isMonthDate ? 'DATE' : 'INT') . "<br>";
    echo "عمود year: " . ($hasYearColumn ? 'موجود ✅' : 'غير موجود ❌');
    echo "</div>";
    
    // 1. البحث عن السجلات ذات التواريخ الخاطئة
    $invalidRecords = [];
    
    if ($isMonthDate) {
        // إذا كان month من نوع DATE
        $invalidRecords = $db->query(
            "SELECT s.*, u.full_name, u.username 
             FROM salaries s 
             LEFT JOIN users u ON s.user_id = u.id 
             WHERE s.month IS NULL 
                OR s.month = '0000-00-00' 
                OR s.month = '1970-01-01'
                OR YEAR(s.month) < 2000 
                OR YEAR(s.month) > 2100
             ORDER BY s.id"
        );
    } else {
        // إذا كان month من نوع INT
        if ($hasYearColumn) {
            $invalidRecords = $db->query(
                "SELECT s.*, u.full_name, u.username 
                 FROM salaries s 
                 LEFT JOIN users u ON s.user_id = u.id 
                 WHERE s.month IS NULL 
                    OR s.month < 1 
                    OR s.month > 12 
                    OR s.year IS NULL 
                    OR s.year < 2000 
                    OR s.year > 2100
                    OR s.year = 0
                 ORDER BY s.id"
            );
        } else {
            $invalidRecords = $db->query(
                "SELECT s.*, u.full_name, u.username 
                 FROM salaries s 
                 LEFT JOIN users u ON s.user_id = u.id 
                 WHERE s.month IS NULL 
                    OR s.month < 1 
                    OR s.month > 12
                 ORDER BY s.id"
            );
        }
    }
    
    $invalidCount = count($invalidRecords);
    
    if ($invalidCount === 0) {
        echo "<div class='success'>✅ لا توجد سجلات رواتب ذات تواريخ خاطئة!</div>";
    } else {
        echo "<div class='warning'>⚠️ تم العثور على <strong>{$invalidCount}</strong> سجل راتب بتاريخ خاطئ</div>";
        
        // عرض السجلات
        echo "<h3>السجلات المعطوبة:</h3>";
        echo "<table>";
        echo "<tr><th>ID</th><th>الموظف</th><th>الشهر</th><th>السنة</th><th>المبلغ</th><th>الحالة</th></tr>";
        
        foreach ($invalidRecords as $record) {
            $monthValue = $record['month'] ?? 'NULL';
            $yearValue = $hasYearColumn ? ($record['year'] ?? 'NULL') : 'غير موجود';
            $userName = $record['full_name'] ?? $record['username'] ?? 'غير معروف';
            $totalAmount = number_format($record['total_amount'] ?? 0, 2);
            $status = $record['status'] ?? 'غير محدد';
            
            echo "<tr>";
            echo "<td>{$record['id']}</td>";
            echo "<td>{$userName}</td>";
            echo "<td>{$monthValue}</td>";
            echo "<td>{$yearValue}</td>";
            echo "<td>{$totalAmount}</td>";
            echo "<td>{$status}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // التنفيذ الفعلي للحذف
        if (isset($_GET['confirm']) && $_GET['confirm'] === 'yes') {
            echo "<h3>🗑️ جاري الحذف...</h3>";
            
            $deletedCount = 0;
            $errors = [];
            
            // التحقق من وجود جدول salary_payments
            $hasPaymentsTable = false;
            try {
                $tableCheck = $db->queryOne("SHOW TABLES LIKE 'salary_payments'");
                $hasPaymentsTable = !empty($tableCheck);
            } catch (Exception $e) {
                $hasPaymentsTable = false;
            }
            
            foreach ($invalidRecords as $record) {
                try {
                    // التحقق من أن السجل ليس له مدفوعات مرتبطة (فقط إذا كان الجدول موجوداً)
                    if ($hasPaymentsTable) {
                        $hasPayments = $db->queryOne(
                            "SELECT COUNT(*) as cnt FROM salary_payments WHERE salary_id = ?",
                            [$record['id']]
                        );
                        
                        if (!empty($hasPayments) && $hasPayments['cnt'] > 0) {
                            $errors[] = "السجل ID:{$record['id']} له مدفوعات مرتبطة - تم تخطيه";
                            continue;
                        }
                    }
                    
                    // حذف السجل
                    $db->execute("DELETE FROM salaries WHERE id = ?", [$record['id']]);
                    $deletedCount++;
                    
                } catch (Exception $e) {
                    $errors[] = "خطأ في حذف السجل ID:{$record['id']}: " . $e->getMessage();
                }
            }
            
            if ($deletedCount > 0) {
                echo "<div class='success'>✅ تم حذف <strong>{$deletedCount}</strong> سجل بنجاح!</div>";
            }
            
            if (!empty($errors)) {
                echo "<div class='error'>";
                echo "<strong>تنبيهات:</strong><br>";
                foreach ($errors as $err) {
                    echo "- {$err}<br>";
                }
                echo "</div>";
            }
            
        } else {
            // رابط التأكيد
            echo "<a href='?confirm=yes' class='btn' onclick='return confirm(\"هل أنت متأكد من حذف هذه السجلات؟ هذا الإجراء لا يمكن التراجع عنه!\");'>🗑️ حذف السجلات المعطوبة</a>";
            echo "<p style='color: gray; font-size: 12px;'>ملاحظة: لن يتم حذف السجلات التي لها مدفوعات مرتبطة</p>";
        }
    }
    
    // 2. إضافة عمود year إذا لم يكن موجوداً
    if (!$hasYearColumn) {
        echo "<h3>⚙️ إضافة عمود year المفقود</h3>";
        
        if (isset($_GET['add_year']) && $_GET['add_year'] === 'yes') {
            try {
                $db->execute("ALTER TABLE salaries ADD COLUMN year INT(4) DEFAULT NULL AFTER month");
                echo "<div class='success'>✅ تم إضافة عمود year بنجاح!</div>";
                
                // محاولة استخراج السنة من month إذا كان من نوع DATE
                if ($isMonthDate) {
                    $db->execute("UPDATE salaries SET year = YEAR(month) WHERE year IS NULL AND month IS NOT NULL AND month != '0000-00-00'");
                    echo "<div class='success'>✅ تم تحديث قيم year من عمود month!</div>";
                }
                
            } catch (Exception $e) {
                echo "<div class='error'>❌ خطأ: " . $e->getMessage() . "</div>";
            }
        } else {
            echo "<a href='?add_year=yes' class='btn btn-success'>➕ إضافة عمود year</a>";
        }
    }
    
    // 3. إحصائيات
    echo "<h3>📊 إحصائيات الرواتب</h3>";
    
    $totalSalaries = $db->queryOne("SELECT COUNT(*) as cnt FROM salaries");
    $uniqueUsers = $db->queryOne("SELECT COUNT(DISTINCT user_id) as cnt FROM salaries");
    
    echo "<div class='info'>";
    echo "إجمالي سجلات الرواتب: <strong>" . ($totalSalaries['cnt'] ?? 0) . "</strong><br>";
    echo "عدد الموظفين: <strong>" . ($uniqueUsers['cnt'] ?? 0) . "</strong>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='error'>❌ خطأ في الاتصال بقاعدة البيانات: " . $e->getMessage() . "</div>";
}

echo "</div></body></html>";
