<?php
/**
 * ملف اختبار الاتصال بقاعدة البيانات
 * استخدم هذا الملف للتحقق من إعدادات قاعدة البيانات
 */

// تفعيل عرض الأخطاء
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<!DOCTYPE html>";
echo "<html lang='ar' dir='rtl'>";
echo "<head>";
echo "<meta charset='UTF-8'>";
echo "<title>اختبار الاتصال بقاعدة البيانات</title>";
echo "<style>
    body { font-family: Arial, sans-serif; padding: 20px; direction: rtl; }
    .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 10px 0; }
    .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 10px 0; }
    .info { background: #d1ecf1; color: #0c5460; padding: 15px; border-radius: 5px; margin: 10px 0; }
    pre { background: #f8f9fa; padding: 10px; border-radius: 3px; overflow-x: auto; }
</style>";
echo "</head>";
echo "<body>";
echo "<h1>اختبار الاتصال بقاعدة البيانات</h1>";

// قراءة إعدادات قاعدة البيانات من config.php
$configFile = __DIR__ . '/includes/config.php';
if (!file_exists($configFile)) {
    echo "<div class='error'>❌ ملف config.php غير موجود في: $configFile</div>";
    echo "</body></html>";
    exit;
}

// تحميل إعدادات قاعدة البيانات
define('ACCESS_ALLOWED', true);
require_once $configFile;

echo "<div class='info'>";
echo "<h3>إعدادات قاعدة البيانات:</h3>";
echo "<ul>";
echo "<li><strong>Host:</strong> " . htmlspecialchars(DB_HOST) . "</li>";
echo "<li><strong>Port:</strong> " . htmlspecialchars(DB_PORT) . "</li>";
echo "<li><strong>Database:</strong> " . htmlspecialchars(DB_NAME) . "</li>";
echo "<li><strong>User:</strong> " . htmlspecialchars(DB_USER) . "</li>";
echo "<li><strong>Password:</strong> " . (defined('DB_PASS') && !empty(DB_PASS) ? '***' : 'غير محدد') . "</li>";
echo "</ul>";
echo "</div>";

// اختبار الاتصال
echo "<h3>اختبار الاتصال:</h3>";

try {
    // اختبار 1: الاتصال بالخادم بدون قاعدة البيانات
    echo "<div class='info'><strong>اختبار 1:</strong> الاتصال بخادم MySQL...</div>";
    $testConnection = @new mysqli(DB_HOST, DB_USER, DB_PASS, null, DB_PORT);
    
    if ($testConnection->connect_error) {
        echo "<div class='error'>❌ فشل الاتصال بالخادم: " . htmlspecialchars($testConnection->connect_error) . "</div>";
        echo "<div class='error'>كود الخطأ: " . $testConnection->connect_errno . "</div>";
    } else {
        echo "<div class='success'>✅ نجح الاتصال بالخادم</div>";
        $testConnection->close();
    }
    
    // اختبار 2: الاتصال بقاعدة البيانات
    echo "<div class='info'><strong>اختبار 2:</strong> الاتصال بقاعدة البيانات '" . htmlspecialchars(DB_NAME) . "'...</div>";
    $dbConnection = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    
    if ($dbConnection->connect_error) {
        echo "<div class='error'>❌ فشل الاتصال بقاعدة البيانات: " . htmlspecialchars($dbConnection->connect_error) . "</div>";
        echo "<div class='error'>كود الخطأ: " . $dbConnection->connect_errno . "</div>";
        
        // اقتراحات لحل المشكلة
        echo "<div class='info'>";
        echo "<h4>اقتراحات لحل المشكلة:</h4>";
        echo "<ul>";
        if ($dbConnection->connect_errno == 1045) {
            echo "<li>اسم المستخدم أو كلمة المرور غير صحيحة</li>";
        } elseif ($dbConnection->connect_errno == 1049) {
            echo "<li>قاعدة البيانات '" . htmlspecialchars(DB_NAME) . "' غير موجودة</li>";
        } elseif ($dbConnection->connect_errno == 2002) {
            echo "<li>لا يمكن الاتصال بخادم MySQL. تحقق من أن الخادم يعمل</li>";
        }
        echo "<li>تحقق من إعدادات قاعدة البيانات في ملف includes/config.php</li>";
        echo "<li>تحقق من أن خادم MySQL يعمل</li>";
        echo "<li>تحقق من صلاحيات المستخدم</li>";
        echo "</ul>";
        echo "</div>";
    } else {
        echo "<div class='success'>✅ نجح الاتصال بقاعدة البيانات</div>";
        
        // اختبار 3: التحقق من وجود جدول users
        echo "<div class='info'><strong>اختبار 3:</strong> التحقق من وجود جدول 'users'...</div>";
        $result = $dbConnection->query("SHOW TABLES LIKE 'users'");
        
        if ($result && $result->num_rows > 0) {
            echo "<div class='success'>✅ جدول 'users' موجود</div>";
            
            // اختبار 4: جلب عدد المستخدمين
            echo "<div class='info'><strong>اختبار 4:</strong> جلب عدد المستخدمين...</div>";
            $countResult = $dbConnection->query("SELECT COUNT(*) as count FROM users");
            if ($countResult) {
                $row = $countResult->fetch_assoc();
                echo "<div class='success'>✅ عدد المستخدمين في قاعدة البيانات: " . htmlspecialchars($row['count']) . "</div>";
                $countResult->free();
            }
            
            // اختبار 5: جلب قائمة المستخدمين
            echo "<div class='info'><strong>اختبار 5:</strong> جلب قائمة المستخدمين...</div>";
            $usersResult = $dbConnection->query("SELECT id, username, role, status FROM users LIMIT 10");
            if ($usersResult) {
                echo "<table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%;'>";
                echo "<tr><th>ID</th><th>اسم المستخدم</th><th>الدور</th><th>الحالة</th></tr>";
                while ($user = $usersResult->fetch_assoc()) {
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars($user['id']) . "</td>";
                    echo "<td>" . htmlspecialchars($user['username']) . "</td>";
                    echo "<td>" . htmlspecialchars($user['role']) . "</td>";
                    echo "<td>" . htmlspecialchars($user['status']) . "</td>";
                    echo "</tr>";
                }
                echo "</table>";
                $usersResult->free();
            }
        } else {
            echo "<div class='error'>❌ جدول 'users' غير موجود</div>";
            echo "<div class='info'>يجب تشغيل ملف التثبيت لإنشاء الجداول المطلوبة</div>";
        }
        
        $dbConnection->close();
    }
    
} catch (Exception $e) {
    echo "<div class='error'>❌ حدث خطأ: " . htmlspecialchars($e->getMessage()) . "</div>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
} catch (Throwable $e) {
    echo "<div class='error'>❌ حدث خطأ فادح: " . htmlspecialchars($e->getMessage()) . "</div>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<div class='info'>";
echo "<h3>ملاحظات:</h3>";
echo "<ul>";
echo "<li>بعد التحقق من الاتصال، احذف هذا الملف (test_db_connection.php) لأسباب أمنية</li>";
echo "<li>إذا كان الاتصال ناجحاً ولكن تسجيل الدخول لا يزال يفشل، تحقق من ملف error_log</li>";
echo "</ul>";
echo "</div>";

echo "</body>";
echo "</html>";
?>

