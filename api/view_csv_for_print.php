<?php
/**
 * API for Viewing CSV File as HTML for Printing
 * Converts CSV file to HTML table format for printing
 */

// ===== بداية الإعداد الحرج لضمان HTML فقط =====

// تعطيل عرض الأخطاء تماماً قبل أي شيء
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
error_reporting(0);

// تنظيف أي output موجود
while (ob_get_level() > 0) {
    @ob_end_clean();
}

// بدء output buffering جديد
ob_start();

// تعريف ثوابت الوصول
define('ACCESS_ALLOWED', true);
define('IS_API_REQUEST', true);

// ===== تحميل الملفات المطلوبة =====
try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/path_helper.php';
    
    // التحقق من تسجيل الدخول
    if (!isLoggedIn()) {
        http_response_code(401);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="ar"><head><meta charset="utf-8"><title>غير مصرح</title></head><body><h1>غير مصرح لك بالوصول</h1></body></html>';
        exit;
    }
    
    $currentUser = getCurrentUser();
    $currentRole = strtolower((string)($currentUser['role'] ?? ''));
    
    // التحقق من الصلاحيات
    $allowedRoles = ['manager', 'developer', 'accountant', 'sales'];
    if (!in_array($currentRole, $allowedRoles, true)) {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="ar"><head><meta charset="utf-8"><title>غير مصرح</title></head><body><h1>غير مصرح لك بتنفيذ هذه العملية</h1></body></html>';
        exit;
    }
    
    // التحقق من وجود مسار الملف
    $filePath = isset($_GET['file']) ? trim($_GET['file']) : '';
    if (empty($filePath)) {
        http_response_code(400);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="ar"><head><meta charset="utf-8"><title>خطأ</title></head><body><h1>مسار الملف غير محدد</h1></body></html>';
        exit;
    }
    
    // تنظيف المسار من أي محاولات للوصول إلى ملفات خارجية
    $filePath = str_replace('\\', '/', $filePath);
    $filePath = ltrim($filePath, '/');
    
    // التحقق من أن الملف داخل مجلد reports
    if (strpos($filePath, '../') !== false || strpos($filePath, '..\\') !== false) {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="ar"><head><meta charset="utf-8"><title>خطأ</title></head><body><h1>مسار الملف غير آمن</h1></body></html>';
        exit;
    }
    
    // إزالة 'reports/' من البداية إذا كان موجوداً (لأن REPORTS_PATH يحتوي على reports/)
    $filePath = preg_replace('/^reports\//', '', $filePath);
    
    // بناء المسار الكامل
    $fullPath = REPORTS_PATH . $filePath;
    $fullPath = str_replace('\\', '/', $fullPath);
    $fullPath = rtrim($fullPath, '/');
    
    // التحقق من وجود الملف
    if (!file_exists($fullPath) || !is_file($fullPath)) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="ar"><head><meta charset="utf-8"><title>خطأ</title></head><body><h1>الملف غير موجود</h1></body></html>';
        exit;
    }
    
    // التحقق من أن الملف CSV
    $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    if ($extension !== 'csv') {
        http_response_code(400);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="ar"><head><meta charset="utf-8"><title>خطأ</title></head><body><h1>الملف ليس ملف CSV</h1></body></html>';
        exit;
    }
    
    // قراءة محتوى الملف
    $csvContent = @file_get_contents($fullPath);
    if ($csvContent === false) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="ar"><head><meta charset="utf-8"><title>خطأ</title></head><body><h1>فشل في قراءة الملف</h1></body></html>';
        exit;
    }
    
    // تحويل CSV إلى HTML
    $lines = explode("\n", $csvContent);
    $rows = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) {
            continue;
        }
        // استخدام str_getcsv للتعامل مع CSV بشكل صحيح (يدعم الاقتباسات)
        $row = str_getcsv($line);
        if (!empty($row)) {
            $rows[] = $row;
        }
    }
    
    if (empty($rows)) {
        http_response_code(400);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="ar"><head><meta charset="utf-8"><title>خطأ</title></head><body><h1>الملف فارغ</h1></body></html>';
        exit;
    }
    
    // تنظيف output buffer
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    
    // إرسال headers
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    
    // إنشاء HTML للطباعة
    $title = isset($rows[0][0]) ? htmlspecialchars($rows[0][0], ENT_QUOTES, 'UTF-8') : 'تصدير العملاء';
    $companyName = isset($rows[1][0]) ? htmlspecialchars($rows[1][0], ENT_QUOTES, 'UTF-8') : COMPANY_NAME;
    $date = isset($rows[2][0]) ? htmlspecialchars($rows[2][0], ENT_QUOTES, 'UTF-8') : date('Y-m-d H:i:s');
    
    // تخطي أول 4 صفوف (العنوان، اسم الشركة، التاريخ، سطر فارغ)
    $dataRows = array_slice($rows, 4);
    
    // استخراج العناوين من أول صف بيانات
    $headers = [];
    if (!empty($dataRows)) {
        $headers = $dataRows[0];
        $dataRows = array_slice($dataRows, 1);
    }
    
    // إنشاء HTML
    $html = '<!DOCTYPE html><html lang="ar" dir="rtl"><head>';
    $html .= '<meta charset="utf-8">';
    $html .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
    $html .= '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
    $html .= '<style>
        @page { margin: 16mm 12mm; }
        *, *::before, *::after { box-sizing: border-box; }
        body { 
            font-family: "Cairo", "Amiri", "Segoe UI", sans-serif; 
            direction: rtl; 
            text-align: right; 
            color: #0f172a; 
            margin: 0; 
            background: #f8fafc; 
            padding: 20px;
        }
        .report-wrapper { 
            max-width: 1024px; 
            margin: 0 auto; 
            padding: 32px; 
            background: #ffffff; 
            border-radius: 18px; 
            box-shadow: 0 20px 60px rgba(15,23,42,0.12); 
        }
        .actions { 
            display: flex; 
            flex-direction: column; 
            gap: 8px; 
            align-items: flex-start; 
            margin-bottom: 24px; 
        }
        .actions button { 
            background: #1d4ed8; 
            color: #ffffff; 
            border: none; 
            padding: 11px 20px; 
            border-radius: 12px; 
            font-size: 15px; 
            cursor: pointer; 
            transition: opacity 0.2s ease; 
        }
        .actions button:hover { 
            opacity: 0.92; 
        }
        .actions .hint { 
            font-size: 13px; 
            color: #475569; 
        }
        .report-header { 
            margin-bottom: 24px; 
            text-align: center; 
        }
        .report-header h1 { 
            margin: 0 0 12px; 
            font-size: 26px; 
            font-weight: 700; 
            color: #0f172a; 
        }
        .report-header .meta { 
            display: flex; 
            gap: 14px; 
            justify-content: center; 
            flex-wrap: wrap; 
        }
        .report-header .meta span { 
            background: #e2e8f0; 
            padding: 7px 16px; 
            border-radius: 999px; 
            font-size: 13px; 
            color: #334155; 
        }
        .table-wrapper { 
            overflow-x: auto; 
            border-radius: 14px; 
            border: 1px solid #e2e8f0; 
            background: #ffffff; 
        }
        .report-table { 
            width: 100%; 
            border-collapse: collapse; 
            min-width: 720px; 
        }
        .report-table thead th { 
            background: #1d4ed8; 
            color: #ffffff; 
            padding: 12px 14px; 
            font-size: 14px; 
            font-weight: 600; 
            border-bottom: 1px solid rgba(255,255,255,0.2); 
            text-align: right;
        }
        .report-table tbody td { 
            padding: 12px 14px; 
            border-bottom: 1px solid #e2e8f0; 
            font-size: 13px; 
            color: #1f2937; 
            text-align: right;
        }
        .report-table tbody tr:nth-child(even) td { 
            background: #f8fafc; 
        }
        .empty { 
            padding: 32px; 
            background: #f1f5f9; 
            border: 2px dashed #cbd5f5; 
            border-radius: 16px; 
            text-align: center; 
            font-size: 15px; 
            color: #64748b; 
        }
        @media print { 
            body { 
                background: #ffffff; 
                padding: 0; 
            } 
            .report-wrapper { 
                box-shadow: none; 
                border-radius: 0; 
                padding: 0; 
            } 
            .actions { 
                display: none !important; 
            } 
            .table-wrapper { 
                overflow: visible; 
            } 
            table { 
                min-width: auto; 
            } 
        }
    </style>';
    $html .= '</head><body>';
    $html .= '<div class="report-wrapper">';
    $html .= '<div class="actions">';
    $html .= '<button id="printReportButton" type="button">طباعة / حفظ كـ PDF</button>';
    $html .= '<span class="hint">يمكنك استخدام زر الطباعة أو حفظ الصفحة كـ PDF من المتصفح.</span>';
    $html .= '</div>';
    $html .= '<header class="report-header">';
    $html .= '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
    $html .= '<div class="meta">';
    $html .= '<span>' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '</span>';
    $html .= '<span>' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . '</span>';
    $html .= '</div>';
    $html .= '</header>';
    
    // إنشاء الجدول
    if (!empty($headers) && !empty($dataRows)) {
        $html .= '<div class="table-wrapper">';
        $html .= '<table class="report-table">';
        $html .= '<thead><tr>';
        foreach ($headers as $header) {
            $html .= '<th>' . htmlspecialchars(trim($header), ENT_QUOTES, 'UTF-8') . '</th>';
        }
        $html .= '</tr></thead>';
        $html .= '<tbody>';
        foreach ($dataRows as $row) {
            $html .= '<tr>';
            foreach ($headers as $index => $header) {
                $value = isset($row[$index]) ? trim($row[$index]) : '';
                $html .= '<td>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        $html .= '</div>';
    } else {
        $html .= '<div class="empty">لا توجد بيانات متاحة لعرضها.</div>';
    }
    
    $html .= '</div>';
    
    // إضافة سكريبت الطباعة
    $shouldPrint = isset($_GET['print']) && $_GET['print'] === '1';
    $printScript = '<script>
        (function() {
            function triggerPrint() {
                window.print();
            }
            document.addEventListener("DOMContentLoaded", function() {
                var btn = document.getElementById("printReportButton");
                if (btn) {
                    btn.addEventListener("click", function(e) {
                        e.preventDefault();
                        triggerPrint();
                    });
                }
                var params = new URLSearchParams(window.location.search);
                if (params.get("print") === "1") {
                    setTimeout(triggerPrint, 600);
                }
            });
        })();
    </script>';
    
    $html .= $printScript;
    $html .= '</body></html>';
    
    echo $html;
    exit;
    
} catch (Exception $e) {
    error_log('View CSV for print error: ' . $e->getMessage());
    error_log('View CSV for print error trace: ' . $e->getTraceAsString());
    
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="ar"><head><meta charset="utf-8"><title>خطأ</title></head><body><h1>حدث خطأ أثناء عرض الملف</h1></body></html>';
    exit;
}

