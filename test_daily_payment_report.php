<?php
/**
 * ملف تجربة لإرسال التقرير اليومي عبر Telegram
 * Test file for Daily Payment Schedules Telegram Report
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/payment_schedules.php';

header('Content-Type: text/html; charset=utf-8');

echo "<!DOCTYPE html>
<html dir='rtl' lang='ar'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>تجربة إرسال التقرير اليومي - جداول التحصيل</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #007bff;
            padding-bottom: 10px;
        }
        .info {
            background: #e7f3ff;
            border-right: 4px solid #007bff;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }
        .success {
            background: #d4edda;
            border-right: 4px solid #28a745;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
            color: #155724;
        }
        .error {
            background: #f8d7da;
            border-right: 4px solid #dc3545;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
            color: #721c24;
        }
        .warning {
            background: #fff3cd;
            border-right: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
            color: #856404;
        }
        pre {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            direction: ltr;
            text-align: left;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin: 10px 5px;
        }
        .btn:hover {
            background: #0056b3;
        }
        .btn-success {
            background: #28a745;
        }
        .btn-success:hover {
            background: #218838;
        }
        .btn-danger {
            background: #dc3545;
        }
        .btn-danger:hover {
            background: #c82333;
        }
    </style>
</head>
<body>
    <div class='container'>
        <h1>🧪 تجربة إرسال التقرير اليومي - جداول التحصيل</h1>";

// التحقق من إعدادات Telegram
require_once __DIR__ . '/includes/simple_telegram.php';

if (!isTelegramConfigured()) {
    echo "<div class='error'>
            <strong>❌ خطأ:</strong> إعدادات Telegram غير مكتملة<br>
            يرجى التحقق من إعدادات TELEGRAM_BOT_TOKEN و TELEGRAM_CHAT_ID
          </div>";
    echo "</div></body></html>";
    exit;
}

echo "<div class='info'>
        <strong>ℹ️ معلومات:</strong><br>
        • سيتم إرسال تقرير يومي واحد عبر Telegram<br>
        • إذا تم إرسال التقرير اليوم، سيتم تخطي الإرسال<br>
        • يمكنك إعادة المحاولة بعد حذف السجل من جدول system_daily_jobs
      </div>";

// معالجة طلب إعادة تعيين
if (isset($_GET['reset']) && $_GET['reset'] === '1') {
    try {
        $db = db();
        $db->execute(
            "DELETE FROM system_daily_jobs WHERE job_key = 'daily_local_payment_schedules_report'"
        );
        echo "<div class='success'>
                <strong>✅ تم:</strong> تم حذف السجل السابق. يمكنك الآن إعادة المحاولة.
              </div>";
    } catch (Exception $e) {
        echo "<div class='error'>
                <strong>❌ خطأ:</strong> فشل حذف السجل: " . htmlspecialchars($e->getMessage()) . "
              </div>";
    }
}

// التحقق من حالة الإرسال السابق
try {
    $db = db();
    $lastSent = $db->queryOne(
        "SELECT last_sent_at FROM system_daily_jobs WHERE job_key = 'daily_local_payment_schedules_report'"
    );
    
    if ($lastSent && !empty($lastSent['last_sent_at'])) {
        $lastSentDate = date('Y-m-d', strtotime($lastSent['last_sent_at']));
        $today = date('Y-m-d');
        
        if ($lastSentDate === $today) {
            echo "<div class='warning'>
                    <strong>⚠️ تحذير:</strong> تم إرسال التقرير اليوم في: " . htmlspecialchars($lastSent['last_sent_at']) . "<br>
                    لإعادة الإرسال، اضغط على زر 'إعادة تعيين' أدناه
                  </div>";
            echo "<a href='?reset=1' class='btn btn-danger'>🔄 إعادة تعيين السجل</a>";
        } else {
            echo "<div class='info'>
                    <strong>ℹ️ معلومات:</strong> آخر إرسال كان في: " . htmlspecialchars($lastSent['last_sent_at']) . "<br>
                    يمكنك إرسال تقرير جديد اليوم
                  </div>";
        }
    } else {
        echo "<div class='info'>
                <strong>ℹ️ معلومات:</strong> لم يتم إرسال تقرير من قبل أو تم حذف السجل
              </div>";
    }
} catch (Exception $e) {
    echo "<div class='warning'>
            <strong>⚠️ تحذير:</strong> لا يمكن التحقق من حالة الإرسال السابق: " . htmlspecialchars($e->getMessage()) . "
          </div>";
}

// معالجة طلب الإرسال
if (isset($_GET['send']) && $_GET['send'] === '1') {
    echo "<div class='info'>
            <strong>🔄 جاري الإرسال...</strong>
          </div>";
    
    try {
        // استدعاء الدالة
        $result = sendDailyLocalPaymentSchedulesTelegramReport();
        
        if ($result) {
            echo "<div class='success'>
                    <strong>✅ نجح:</strong> تم إرسال التقرير اليومي عبر Telegram بنجاح!<br>
                    تحقق من رسائل Telegram الخاصة بك.
                  </div>";
        } else {
            echo "<div class='warning'>
                    <strong>⚠️ لم يتم الإرسال:</strong> إما أن التقرير تم إرساله اليوم بالفعل، أو لا توجد جداول معلقة/متأخرة، أو حدث خطأ في الإرسال.<br>
                    تحقق من ملفات الـ log لمزيد من التفاصيل.
                  </div>";
        }
    } catch (Exception $e) {
        echo "<div class='error'>
                <strong>❌ خطأ:</strong> " . htmlspecialchars($e->getMessage()) . "<br>
                <pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>
              </div>";
    }
} else {
    // عرض معلومات عن الجداول الموجودة
    try {
        $db = db();
        $stats = $db->queryOne("
            SELECT 
                COUNT(CASE WHEN ps.status = 'pending' THEN 1 END) as pending_count,
                COUNT(CASE WHEN ps.status = 'overdue' THEN 1 END) as overdue_count,
                COALESCE(SUM(CASE WHEN ps.status = 'pending' THEN ps.amount END), 0) as pending_amount,
                COALESCE(SUM(CASE WHEN ps.status = 'overdue' THEN ps.amount END), 0) as overdue_amount,
                COUNT(*) as total_count
            FROM payment_schedules ps
            INNER JOIN local_customers lc ON ps.customer_id = lc.id
            WHERE lc.status = 'active' 
              AND ps.sales_rep_id IS NULL
              AND ps.status IN ('pending', 'overdue')
        ");
        
        if ($stats && ($stats['total_count'] ?? 0) > 0) {
            echo "<div class='info'>
                    <strong>📊 إحصائيات الجداول:</strong><br>
                    • إجمالي الجداول المعلقة/المتأخرة: <strong>" . number_format($stats['total_count']) . "</strong><br>
                    • معلقة: <strong>" . number_format($stats['pending_count']) . "</strong> (" . formatCurrency($stats['pending_amount']) . ")<br>
                    • متأخرة: <strong>" . number_format($stats['overdue_count']) . "</strong> (" . formatCurrency($stats['overdue_amount']) . ")
                  </div>";
        } else {
            echo "<div class='warning'>
                    <strong>⚠️ تحذير:</strong> لا توجد جداول معلقة أو متأخرة للعملاء المحليين حالياً
                  </div>";
        }
    } catch (Exception $e) {
        echo "<div class='error'>
                <strong>❌ خطأ:</strong> لا يمكن جلب إحصائيات الجداول: " . htmlspecialchars($e->getMessage()) . "
              </div>";
    }
    
    echo "<div style='margin-top: 30px;'>
            <a href='?send=1' class='btn btn-success'>📤 إرسال التقرير الآن</a>
            <a href='?' class='btn'>🔄 تحديث الصفحة</a>
          </div>";
}

echo "<div class='info' style='margin-top: 30px;'>
        <strong>💡 ملاحظات:</strong><br>
        • هذا الملف للتجربة فقط - يمكن حذفه بعد التأكد من عمل النظام<br>
        • التقرير الفعلي يتم إرساله تلقائياً عبر cron job (cron/payment_reminders.php)<br>
        • التقرير يتم إرساله مرة واحدة فقط يومياً
      </div>";

echo "</div>
</body>
</html>";