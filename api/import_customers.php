<?php
/**
 * API لاستيراد العملاء من ملف CSV
 */

if (!defined('ACCESS_ALLOWED')) {
    define('ACCESS_ALLOWED', true);
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit_log.php';

header('Content-Type: application/json; charset=utf-8');

// التحقق من الصلاحيات
requireRole(['manager', 'accountant']);

$currentUser = getCurrentUser();
$db = db();

// التأكد من وجود جدول customer_phones
try {
    $customerPhonesTable = $db->queryOne("SHOW TABLES LIKE 'customer_phones'");
    if (empty($customerPhonesTable)) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `customer_phones` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `customer_id` int(11) NOT NULL,
                `phone` varchar(20) NOT NULL,
                `is_primary` tinyint(1) DEFAULT 0,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `customer_id` (`customer_id`),
                CONSTRAINT `customer_phones_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        error_log('Table customer_phones created successfully in import API');
    }
} catch (Exception $e) {
    error_log('Error creating customer_phones table in import API: ' . $e->getMessage());
    // لا نوقف العملية، قد يكون الجدول موجوداً بالفعل
}

// التحقق من نوع الطلب
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'طريقة الطلب غير مدعومة'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// التحقق من وجود الملف
if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
    $errorMessage = 'لم يتم رفع الملف بنجاح';
    if (isset($_FILES['excel_file']['error'])) {
        switch ($_FILES['excel_file']['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $errorMessage = 'حجم الملف كبير جداً';
                break;
            case UPLOAD_ERR_PARTIAL:
                $errorMessage = 'تم رفع الملف جزئياً فقط';
                break;
            case UPLOAD_ERR_NO_FILE:
                $errorMessage = 'لم يتم اختيار ملف';
                break;
        }
    }
    
    echo json_encode([
        'success' => false,
        'message' => $errorMessage
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$file = $_FILES['excel_file'];
$fileName = $file['name'];
$fileTmpPath = $file['tmp_name'];
$fileSize = $file['size'];

// التحقق من نوع الملف (CSV أو Excel)
$allowedExtensions = ['csv', 'xlsx', 'xls'];
$fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

if (!in_array($fileExtension, $allowedExtensions, true)) {
    echo json_encode([
        'success' => false,
        'message' => 'نوع الملف غير مدعوم. يرجى رفع ملف CSV أو Excel (.csv, .xlsx أو .xls)'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// التحقق من حجم الملف (10 ميجابايت)
if ($fileSize > 10 * 1024 * 1024) {
    echo json_encode([
        'success' => false,
        'message' => 'حجم الملف كبير جداً. الحد الأقصى 10 ميجابايت'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $rows = [];
    
    // قراءة الملف حسب نوعه
    if ($fileExtension === 'csv') {
        // قراءة ملف CSV مباشرة
        $handle = fopen($fileTmpPath, 'r');
        if ($handle === false) {
            throw new Exception('لا يمكن فتح الملف');
        }
        
        // قراءة الملف مع دعم UTF-8 BOM
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
        
        // قراءة البيانات
        $maxColumns = 0;
        while (($row = fgetcsv($handle, 1000, ',', '"', '\\')) !== false) {
            // تحديث العدد الأقصى للأعمدة
            if (count($row) > $maxColumns) {
                $maxColumns = count($row);
            }
            
            // تنظيف البيانات من BOM ومسافات إضافية
            $row = array_map(function($cell) {
                if ($cell === null) {
                    return '';
                }
                $cell = trim($cell);
                // إزالة BOM إذا كان موجوداً
                if (substr($cell, 0, 3) === "\xEF\xBB\xBF") {
                    $cell = substr($cell, 3);
                }
                return $cell;
            }, $row);
            
            // التأكد من أن الصف له نفس عدد الأعمدة (ملء الأعمدة الفارغة)
            while (count($row) < $maxColumns) {
                $row[] = '';
            }
            
            // تخطي الصفوف الفارغة تماماً
            $isEmpty = true;
            foreach ($row as $cell) {
                if (trim($cell) !== '') {
                    $isEmpty = false;
                    break;
                }
            }
            if (!$isEmpty) {
                $rows[] = $row;
            }
        }
        fclose($handle);
    } else {
        // محاولة قراءة Excel كـ CSV (إذا كان يمكن تحويله)
        // أو استخدام مكتبة إذا كانت متوفرة
        $usePhpSpreadsheet = false;
        
        // التحقق من وجود PhpSpreadsheet (اختياري)
        if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
            require_once __DIR__ . '/../vendor/autoload.php';
            $usePhpSpreadsheet = class_exists('PhpOffice\PhpSpreadsheet\IOFactory');
        } elseif (class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
            $usePhpSpreadsheet = true;
        }
        
        if ($usePhpSpreadsheet) {
            try {
                $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($fileTmpPath);
                $reader->setReadDataOnly(true);
                $spreadsheet = $reader->load($fileTmpPath);
                $worksheet = $spreadsheet->getActiveSheet();
                $rows = $worksheet->toArray();
            } catch (Exception $spreadsheetError) {
                error_log('PhpSpreadsheet error: ' . $spreadsheetError->getMessage());
                echo json_encode([
                    'success' => false,
                    'message' => 'خطأ في قراءة ملف Excel. يرجى تصدير الملف كـ CSV:\n\n' .
                                'في Excel:\n' .
                                '1. ملف > حفظ باسم\n' .
                                '2. اختر: CSV UTF-8 (مفصول بفواصل)\n' .
                                '3. احفظ وارفعه مرة أخرى'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else {
            // محاولة قراءة Excel كـ CSV كحل بديل (للملفات البسيطة)
            // ملاحظة: هذه الطريقة محدودة وقد لا تعمل مع جميع ملفات Excel
            try {
                $handle = @fopen($fileTmpPath, 'r');
                if ($handle === false) {
                    throw new Exception('لا يمكن فتح الملف');
                }
                
                // محاولة قراءة كـ CSV
                $lineCount = 0;
                while (($row = fgetcsv($handle, 1000, ',', '"', '\\')) !== false && $lineCount < 1000) {
                    $row = array_map(function($cell) {
                        return trim($cell);
                    }, $row);
                    // تخطي الصفوف الفارغة تماماً
                    $isEmpty = true;
                    foreach ($row as $cell) {
                        if (trim($cell) !== '') {
                            $isEmpty = false;
                            break;
                        }
                    }
                    if (!$isEmpty) {
                        $rows[] = $row;
                    }
                    $lineCount++;
                }
                fclose($handle);
                
                // إذا لم نحصل على بيانات، أعط رسالة خطأ واضحة
                if (empty($rows) || count($rows) < 2) {
                    throw new Exception('لا يمكن قراءة ملف Excel مباشرة');
                }
            } catch (Exception $csvReadError) {
                error_log('Excel CSV fallback error: ' . $csvReadError->getMessage());
                echo json_encode([
                    'success' => false,
                    'message' => 'لا يمكن قراءة ملف Excel مباشرة. يرجى تصدير الملف كـ CSV من Excel:\n\n' .
                                'الخطوات:\n' .
                                '1. في Excel: ملف > حفظ باسم\n' .
                                '2. اختر نوع الملف: CSV UTF-8 (مفصول بفواصل)\n' .
                                '3. احفظ الملف وارفعه مرة أخرى\n\n' .
                                'ملاحظة: يُفضل استخدام ملف CSV مباشرة لتجنب مشاكل التنسيق'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }
    
    if (empty($rows) || count($rows) < 2) {
        echo json_encode([
            'success' => false,
            'message' => 'الملف فارغ أو لا يحتوي على بيانات'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // قراءة رؤوس الأعمدة من الصف الأول
    $originalHeaders = $rows[0]; // حفظ النسخة الأصلية للتسجيل
    $headers = array_map(function($header) {
        // تنظيف من BOM
        if (substr($header, 0, 3) === "\xEF\xBB\xBF") {
            $header = substr($header, 3);
        }
        return mb_strtolower(trim($header), 'UTF-8');
    }, $rows[0]);
    
    // تسجيل رؤوس الأعمدة الأصلية
    error_log('=== ORIGINAL CSV HEADERS ===');
    error_log(json_encode($originalHeaders, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    error_log('=== NORMALIZED HEADERS ===');
    error_log(json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    
    // البحث عن أعمدة البيانات المطلوبة
    $customerIdIndex = -1;
    $nameIndex = -1;
    $phoneIndex = -1;
    $phone2Index = -1;
    $addressIndex = -1;
    $balanceIndex = -1;
    $regionIndex = -1;
    
    // البحث عن الأعمدة بطرق مختلفة (عربي/إنجليزي)
    $customerIdVariations = ['ايدي العميل', 'id', 'customer_id', 'معرف العميل', 'رقم العميل', 'ايدي', 'معرف', 'م', 'معرف العميل', 'رقم العميل'];
    $nameVariations = ['اسم العميل', 'الاسم', 'name', 'customer name', 'اسم', 'اسم_العميل', 'اسم العميل', 'الاسم الكامل'];
    $phoneVariations = ['رقم الهاتف', 'الهاتف', 'phone', 'mobile', 'tel', 'رقم_الهاتف', 'رقم الهاتف (الأول)', 'تليفون', 'تلفون', 'telephone', 'رقم التليفون', 'رقم التلفون', 'هاتف', 'موبايل'];
    $phone2Variations = ['رقم الهاتف (الثاني)', 'الهاتف الثاني', 'phone2', 'mobile2', 'tel2', 'رقم_الهاتف_الثاني', 'رقم الهاتف الثاني', 'تليفون 2', 'تلفون 2', 'هاتف 2', 'هاتف ثاني'];
    $addressVariations = ['العنوان', 'address', 'location', 'عنوان', 'العنوان الكامل', 'عنوان العميل'];
    $balanceVariations = ['الرصيد', 'الديون', 'balance', 'debt', 'رصيد', 'ديون', 'صافى المبلغ', 'صافي المبلغ', 'صافى', 'صافي', 'المبلغ', 'net amount', 'رصيد العميل', 'رصيد العميل', 'الرصيد الحالي', 'المبلغ المستحق', 'المستحق', 'الديون المستحقة'];
    $regionVariations = ['المنطقة', 'region', 'منطقة', 'المنطقة', 'منطقة العميل'];
    
    // قائمة لتخزين جميع أعمدة "تليفون" (لحالة وجود عمودين بنفس الاسم)
    $phoneColumns = [];
    
    foreach ($headers as $index => $header) {
        // تنظيف اسم العمود من BOM ومسافات إضافية
        $header = trim($header);
        if (substr($header, 0, 3) === "\xEF\xBB\xBF") {
            $header = substr($header, 3);
        }
        // إزالة المسافات الزائدة والأحرف الخاصة
        $header = preg_replace('/\s+/', ' ', $header);
        $headerLower = mb_strtolower(trim($header), 'UTF-8');
        
        // ايدي العميل
        if ($customerIdIndex === -1 && (
            in_array($headerLower, $customerIdVariations, true) || 
            $headerLower === 'م' ||
            mb_strpos($headerLower, 'ايدي') !== false || 
            mb_strpos($headerLower, 'id') !== false ||
            mb_strpos($headerLower, 'معرف') !== false
        )) {
            $customerIdIndex = $index;
        }
        
        // اسم العميل
        if ($nameIndex === -1 && (
            in_array($headerLower, $nameVariations, true) || 
            mb_strpos($headerLower, 'اسم') !== false || 
            mb_strpos($headerLower, 'name') !== false
        )) {
            $nameIndex = $index;
        }
        
        // رقم الهاتف - جمع جميع أعمدة "تليفون" أولاً
        if (in_array($headerLower, $phoneVariations, true) || 
            mb_strpos($headerLower, 'تليفون') !== false ||
            mb_strpos($headerLower, 'تلفون') !== false ||
            (mb_strpos($headerLower, 'هاتف') !== false && mb_strpos($headerLower, 'ثاني') === false && mb_strpos($headerLower, '2') === false) ||
            mb_strpos($headerLower, 'phone') !== false ||
            mb_strpos($headerLower, 'mobile') !== false ||
            mb_strpos($headerLower, 'tel') !== false) {
            $phoneColumns[] = $index;
        }
        
        // العنوان
        if ($addressIndex === -1 && (
            in_array($headerLower, $addressVariations, true) || 
            mb_strpos($headerLower, 'عنوان') !== false || 
            mb_strpos($headerLower, 'address') !== false
        )) {
            $addressIndex = $index;
        }
        
        // الرصيد - تحسين البحث ليشمل المزيد من الاختلافات
        if ($balanceIndex === -1 && (
            in_array($headerLower, $balanceVariations, true) || 
            mb_strpos($headerLower, 'رصيد') !== false || 
            mb_strpos($headerLower, 'صافى') !== false ||
            mb_strpos($headerLower, 'صافي') !== false ||
            mb_strpos($headerLower, 'balance') !== false ||
            mb_strpos($headerLower, 'debt') !== false ||
            mb_strpos($headerLower, 'net') !== false ||
            mb_strpos($headerLower, 'مبلغ') !== false ||
            mb_strpos($headerLower, 'مستحق') !== false ||
            mb_strpos($headerLower, 'ديون') !== false
        )) {
            $balanceIndex = $index;
        }
        
        // المنطقة
        if ($regionIndex === -1 && (
            in_array($headerLower, $regionVariations, true) || 
            mb_strpos($headerLower, 'منطقة') !== false || 
            mb_strpos($headerLower, 'region') !== false
        )) {
            $regionIndex = $index;
        }
    }
    
    // معالجة أعمدة الهواتف - إذا كان هناك عمودان "تليفون"، الأول هو phoneIndex والثاني هو phone2Index
    if (!empty($phoneColumns)) {
        if (count($phoneColumns) >= 1) {
            $phoneIndex = $phoneColumns[0];
        }
        if (count($phoneColumns) >= 2) {
            $phone2Index = $phoneColumns[1];
        } elseif ($phoneIndex !== -1) {
            // إذا كان هناك عمود واحد فقط، نبحث عن عمود آخر بهاتف ثاني
            foreach ($headers as $index => $header) {
                $headerLower = mb_strtolower(trim($header), 'UTF-8');
                if ($index !== $phoneIndex && (
                    in_array($headerLower, $phone2Variations, true) || 
                    (strpos($headerLower, 'هاتف') !== false && (strpos($headerLower, 'ثاني') !== false || strpos($headerLower, '2') !== false)) ||
                    strpos($headerLower, 'phone2') !== false ||
                    strpos($headerLower, 'mobile2') !== false
                )) {
                    $phone2Index = $index;
                    break;
                }
            }
        }
    }
    
    // التحقق من وجود عمود الاسم (مطلوب)
    if ($nameIndex === -1) {
        echo json_encode([
            'success' => false,
            'message' => 'لم يتم العثور على عمود "اسم العميل" في الملف. يرجى التأكد من وجود هذا العمود في الصف الأول. الأعمدة المدعومة: ايدي العميل (اختياري - للتحديث)، اسم العميل (مطلوب)، رقم الهاتف، رقم الهاتف (الثاني)، الرصيد، العنوان، المنطقة'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // تسجيل الأعمدة التي تم العثور عليها (للتشخيص)
    $foundColumns = [
        'customerIdIndex' => $customerIdIndex,
        'nameIndex' => $nameIndex,
        'phoneIndex' => $phoneIndex,
        'phone2Index' => $phone2Index,
        'addressIndex' => $addressIndex,
        'balanceIndex' => $balanceIndex,
        'regionIndex' => $regionIndex,
        'phoneColumns' => $phoneColumns
    ];
    error_log('=== IMPORT COLUMNS FOUND ===');
    error_log(json_encode($foundColumns, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    error_log('Headers (all): ' . json_encode($headers, JSON_UNESCAPED_UNICODE));
    
    // تسجيل تفصيلي لكل عمود
    foreach ($headers as $idx => $hdr) {
        error_log("Column $idx: '$hdr' (original: '{$originalHeaders[$idx]}')");
    }
    
    // التحقق من أن الأعمدة المهمة موجودة
    if ($balanceIndex === -1) {
        error_log('WARNING: balanceIndex not found! Available headers: ' . implode(' | ', $headers));
        error_log('WARNING: Original headers: ' . implode(' | ', $originalHeaders));
        // محاولة البحث بطريقة أكثر مرونة
        foreach ($headers as $idx => $hdr) {
            if (preg_match('/رصيد|مبلغ|balance|debt|مستحق|ديون/i', $hdr)) {
                $balanceIndex = $idx;
                error_log("FOUND balance column by flexible search at index $idx: '$hdr'");
                break;
            }
        }
    }
    if ($phoneIndex === -1) {
        error_log('WARNING: phoneIndex not found! Available headers: ' . implode(' | ', $headers));
        error_log('WARNING: Original headers: ' . implode(' | ', $originalHeaders));
        // محاولة البحث بطريقة أكثر مرونة
        foreach ($headers as $idx => $hdr) {
            if (preg_match('/هاتف|تليفون|تلفون|phone|mobile|tel/i', $hdr) && 
                !preg_match('/ثاني|2|second/i', $hdr)) {
                $phoneIndex = $idx;
                error_log("FOUND phone column by flexible search at index $idx: '$hdr'");
                break;
            }
        }
    }
    
    // معالجة البيانات
    $imported = 0;
    $skipped = 0;
    $errors = [];
    
    // التحقق من وجود أعمدة في جدول customers
    $hasLatitudeColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'latitude'"));
    $hasLongitudeColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'longitude'"));
    $hasLocationCapturedAtColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'location_captured_at'"));
    $hasRegionIdColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'region_id'"));
    
    // بدء المعاملة
    $db->beginTransaction();
    
    try {
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            
            // تخطي الصفوف الفارغة
            if (empty($row[$nameIndex]) || trim($row[$nameIndex]) === '') {
                continue;
            }
            
            // قراءة البيانات مع التعامل مع الأعمدة الفارغة
            // قراءة ايدي العميل
            $customerIdFromFile = null;
            if ($customerIdIndex !== -1 && isset($row[$customerIdIndex])) {
                $rawValue = trim($row[$customerIdIndex]);
                if ($rawValue !== '' && is_numeric($rawValue)) {
                    $customerIdFromFile = (int)$rawValue;
                }
            }
            
            // قراءة اسم العميل
            $name = '';
            if (isset($row[$nameIndex])) {
                $name = trim($row[$nameIndex]);
            }
            
            // قراءة رقم الهاتف الأول
            $phone = null;
            if ($phoneIndex !== -1 && isset($row[$phoneIndex])) {
                $rawPhone = trim((string)$row[$phoneIndex]);
                // تسجيل القيمة الأصلية
                if ($i <= 5) {
                    error_log("Row $i - Raw phone value from CSV: '" . var_export($rawPhone, true) . "' (type: " . gettype($row[$phoneIndex]) . ")");
                }
                // إزالة أي مسافات أو أحرف غير ضرورية
                $rawPhone = str_replace([' ', '-', '_', '(', ')', '.', '/'], '', $rawPhone);
                // إزالة BOM إذا كان موجوداً
                if (substr($rawPhone, 0, 3) === "\xEF\xBB\xBF") {
                    $rawPhone = substr($rawPhone, 3);
                }
                if ($rawPhone !== '' && strlen($rawPhone) > 0) {
                    $phone = $rawPhone;
                    if ($i <= 5) {
                        error_log("Row $i - Cleaned phone: '$phone'");
                    }
                }
            } else {
                if ($i <= 5) {
                    error_log("WARNING: Row $i - phoneIndex is -1 or row[$phoneIndex] not set. phoneIndex=$phoneIndex, row length=" . count($row));
                    if ($phoneIndex !== -1) {
                        error_log("  - Row[$phoneIndex] exists: " . (isset($row[$phoneIndex]) ? 'YES' : 'NO'));
                        error_log("  - Row[$phoneIndex] value: " . (isset($row[$phoneIndex]) ? var_export($row[$phoneIndex], true) : 'NOT_SET'));
                    }
                }
            }
            
            // قراءة رقم الهاتف الثاني
            $phone2 = null;
            if ($phone2Index !== -1 && isset($row[$phone2Index])) {
                $rawPhone2 = trim((string)$row[$phone2Index]);
                // إزالة أي مسافات أو أحرف غير ضرورية
                $rawPhone2 = str_replace([' ', '-', '_', '(', ')', '.', '/'], '', $rawPhone2);
                // إزالة BOM إذا كان موجوداً
                if (substr($rawPhone2, 0, 3) === "\xEF\xBB\xBF") {
                    $rawPhone2 = substr($rawPhone2, 3);
                }
                if ($rawPhone2 !== '' && strlen($rawPhone2) > 0) {
                    $phone2 = $rawPhone2;
                }
            } else {
                if ($i <= 5 && $phone2Index !== -1) {
                    error_log("WARNING: Row $i - phone2Index exists but row[$phone2Index] is empty. phone2Index=$phone2Index");
                }
            }
            
            // قراءة العنوان
            $address = null;
            if ($addressIndex !== -1 && isset($row[$addressIndex])) {
                $rawAddress = trim($row[$addressIndex]);
                if ($rawAddress !== '') {
                    $address = $rawAddress;
                }
            }
            
            // قراءة الرصيد
            $balance = 0.0;
            if ($balanceIndex !== -1 && isset($row[$balanceIndex])) {
                $rawBalance = $row[$balanceIndex];
                // تسجيل القيمة الأصلية
                if ($i <= 5) {
                    error_log("Row $i - Raw balance value from CSV: '" . var_export($rawBalance, true) . "' (type: " . gettype($rawBalance) . ")");
                }
                // تحويل إلى نص وإزالة المسافات
                $rawBalance = trim((string)$rawBalance);
                // إزالة BOM إذا كان موجوداً
                if (substr($rawBalance, 0, 3) === "\xEF\xBB\xBF") {
                    $rawBalance = substr($rawBalance, 3);
                }
                if ($rawBalance !== '' && $rawBalance !== null) {
                    // إزالة الفواصل من الأرقام (مثل 1,000.50 أو 1.000,50)
                    $rawBalance = str_replace(',', '', $rawBalance);
                    // إزالة أي مسافات إضافية
                    $rawBalance = str_replace(' ', '', $rawBalance);
                    // إزالة أي أحرف غير رقمية في البداية والنهاية (مثل $ أو ر.س أو ج.م)
                    $rawBalance = preg_replace('/^[^\d\-+.]*/u', '', $rawBalance);
                    $rawBalance = preg_replace('/[^\d\-+.]*$/u', '', $rawBalance);
                    // إزالة الأحرف العربية الشائعة (ر.س، ج.م، د.إ، إلخ)
                    $rawBalance = preg_replace('/[رجددإأآا]\.?[سمعإ]/u', '', $rawBalance);
                    // التحقق من أن القيمة رقمية
                    if (is_numeric($rawBalance)) {
                        $balance = (float)$rawBalance;
                        if ($i <= 5) {
                            error_log("Row $i - Parsed balance: $balance");
                        }
                    } else {
                        // محاولة إزالة المزيد من الأحرف غير الرقمية
                        $cleanedBalance = preg_replace('/[^\d.\-+]/', '', $rawBalance);
                        if (is_numeric($cleanedBalance)) {
                            $balance = (float)$cleanedBalance;
                            if ($i <= 5) {
                                error_log("Row $i - Parsed balance (after cleaning): $balance");
                            }
                        } else {
                            error_log("WARNING: Row $i - Balance value '$rawBalance' (cleaned: '$cleanedBalance') is not numeric");
                        }
                    }
                } else {
                    if ($i <= 5) {
                        error_log("Row $i - Balance value is empty or null after trimming");
                    }
                }
            } else {
                if ($i <= 5) {
                    error_log("WARNING: Row $i - balanceIndex is -1 or row[$balanceIndex] not set. balanceIndex=$balanceIndex, row length=" . count($row));
                    if ($balanceIndex !== -1) {
                        error_log("  - Row[$balanceIndex] exists: " . (isset($row[$balanceIndex]) ? 'YES' : 'NO'));
                        error_log("  - Row[$balanceIndex] value: " . (isset($row[$balanceIndex]) ? var_export($row[$balanceIndex], true) : 'NOT_SET'));
                    }
                }
            }
            
            // قراءة المنطقة
            $regionName = null;
            if ($regionIndex !== -1 && isset($row[$regionIndex])) {
                $rawRegion = trim($row[$regionIndex]);
                if ($rawRegion !== '') {
                    $regionName = $rawRegion;
                }
            }
            
            // تسجيل البيانات المقروءة (للتشخيص - يمكن إزالتها لاحقاً)
            if ($i <= 10) { // تسجيل أول 10 صفوف
                $rowData = [
                    'row' => $i,
                    'name' => $name,
                    'phone' => $phone ?? 'NULL',
                    'phone2' => $phone2 ?? 'NULL',
                    'balance' => $balance,
                    'address' => $address ?? 'NULL',
                    'region' => $regionName ?? 'NULL',
                    'indices' => [
                        'phoneIndex' => $phoneIndex,
                        'phone2Index' => $phone2Index,
                        'balanceIndex' => $balanceIndex,
                        'addressIndex' => $addressIndex
                    ],
                    'raw_values' => [
                        'balance' => ($balanceIndex !== -1 && isset($row[$balanceIndex])) ? $row[$balanceIndex] : 'NOT_SET',
                        'phone' => ($phoneIndex !== -1 && isset($row[$phoneIndex])) ? $row[$phoneIndex] : 'NOT_SET',
                        'phone2' => ($phone2Index !== -1 && isset($row[$phone2Index])) ? $row[$phone2Index] : 'NOT_SET'
                    ],
                    'row_length' => count($row),
                    'all_row_values' => $row,
                    'headers_mapping' => []
                ];
                
                // إضافة م mapping بين الأعمدة والقيم
                foreach ($originalHeaders as $colIdx => $colName) {
                    $rowData['headers_mapping'][$colName] = isset($row[$colIdx]) ? $row[$colIdx] : '';
                }
                
                error_log("=== ROW $i DATA ===");
                error_log(json_encode($rowData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
            
            // البحث عن region_id إذا كان اسم المنطقة موجوداً
            $regionId = null;
            if ($hasRegionIdColumn && $regionName && $regionName !== '') {
                $region = $db->queryOne("SELECT id FROM regions WHERE name = ?", [$regionName]);
                if ($region) {
                    $regionId = $region['id'];
                }
            }
            
            $customerId = null;
            $isUpdate = false;
            
            // إذا كان هناك ايدي عميل في الملف، حاول التحديث
            if ($customerIdFromFile && $customerIdFromFile > 0) {
                $existingCustomer = $db->queryOne("SELECT id FROM customers WHERE id = ?", [$customerIdFromFile]);
                if ($existingCustomer) {
                    $customerId = $customerIdFromFile;
                    $isUpdate = true;
                }
            }
            
            // إذا لم يكن هناك تحديث، تحقق من التكرار
            if (!$isUpdate) {
                $duplicateCheck = "SELECT id FROM customers WHERE name = ?";
                $duplicateParams = [$name];
                
                if ($phone) {
                    $duplicateCheck .= " AND (phone = ? OR phone IS NULL)";
                    $duplicateParams[] = $phone;
                }
                
                $existing = $db->queryOne($duplicateCheck, $duplicateParams);
                
                if ($existing) {
                    $skipped++;
                    continue;
                }
            }
            
            try {
                if ($isUpdate) {
                    // تحديث العميل الموجود
                    $updateFields = ['name = ?'];
                    $updateValues = [$name];
                    
                    // تحديث رقم الهاتف إذا كان موجوداً
                    if ($phone !== null) {
                        $updateFields[] = 'phone = ?';
                        $updateValues[] = $phone;
                    }
                    
                    // تحديث الرصيد فقط إذا كان موجوداً في الملف
                    if ($balanceIndex !== -1) {
                        $updateFields[] = 'balance = ?';
                        $updateValues[] = $balance;
                    }
                    
                    // تحديث العنوان إذا كان موجوداً
                    if ($address !== null) {
                        $updateFields[] = 'address = ?';
                        $updateValues[] = $address;
                    }
                    
                    if ($hasRegionIdColumn && $regionId !== null) {
                        $updateFields[] = 'region_id = ?';
                        $updateValues[] = $regionId;
                    }
                    
                    $updateValues[] = $customerId;
                    
                    // تسجيل البيانات قبل التحديث
                    if ($i <= 5) {
                        error_log("Row $i - About to UPDATE customer ID=$customerId:");
                        error_log("  - Update fields: " . implode(', ', $updateFields));
                        error_log("  - Update values: " . json_encode($updateValues, JSON_UNESCAPED_UNICODE));
                    }
                    
                    $db->execute(
                        "UPDATE customers SET " . implode(', ', $updateFields) . " WHERE id = ?",
                        $updateValues
                    );
                    error_log("✓ Customer updated: ID=$customerId, Name=$name, Balance=" . ($balanceIndex !== -1 ? $balance : 'NOT_UPDATED') . " (from index $balanceIndex), Phone=" . ($phone ?? 'NULL') . " (from index $phoneIndex), Phone2=" . ($phone2 ?? 'NULL') . " (from index $phone2Index), Address=" . ($address ?? 'NULL'));
                    
                    // التحقق من البيانات المحفوظة
                    $savedCustomer = $db->queryOne("SELECT name, phone, balance, address FROM customers WHERE id = ?", [$customerId]);
                    if ($savedCustomer) {
                        error_log("✓ Verified saved data - Name: {$savedCustomer['name']}, Phone: {$savedCustomer['phone']}, Balance: {$savedCustomer['balance']}, Address: {$savedCustomer['address']}");
                    }
                    
                    // حذف أرقام الهواتف القديمة
                    $db->execute("DELETE FROM customer_phones WHERE customer_id = ?", [$customerId]);
                    error_log("Deleted old phones for customer $customerId");
                    
                    logAudit($currentUser['id'], 'update_customer', 'customer', $customerId, null, [
                        'name' => $name,
                        'source' => 'csv_import'
                    ]);
                } else {
                    // إدراج عميل جديد
                    // تسجيل البيانات قبل الحفظ
                    if ($i <= 5) {
                        error_log("Row $i - About to INSERT customer:");
                        error_log("  - Name: '$name'");
                        error_log("  - Phone: " . ($phone ?? 'NULL') . " (from index $phoneIndex)");
                        error_log("  - Balance: $balance (from index $balanceIndex)");
                        error_log("  - Address: " . ($address ?? 'NULL'));
                    }
                    
                    $customerColumns = ['name', 'phone', 'balance', 'address', 'status', 'created_by', 'rep_id', 'created_from_pos', 'created_by_admin'];
                    $customerValues = [
                        $name,
                        $phone,
                        $balance,
                        $address,
                        'active',
                        $currentUser['id'],
                        null,
                        0,
                        1
                    ];
                    $customerPlaceholders = ['?', '?', '?', '?', '?', '?', '?', '?', '?'];
                    
                    // إضافة region_id إذا كان موجوداً
                    if ($hasRegionIdColumn && $regionId !== null) {
                        $customerColumns[] = 'region_id';
                        $customerValues[] = $regionId;
                        $customerPlaceholders[] = '?';
                    }
                    
                    $db->execute(
                        "INSERT INTO customers (" . implode(', ', $customerColumns) . ") 
                         VALUES (" . implode(', ', $customerPlaceholders) . ")",
                        $customerValues
                    );
                    
                    $customerId = $db->getLastInsertId();
                    error_log("✓ Customer inserted: ID=$customerId, Name=$name, Balance=$balance (from index $balanceIndex), Phone=" . ($phone ?? 'NULL') . " (from index $phoneIndex), Phone2=" . ($phone2 ?? 'NULL') . " (from index $phone2Index), Address=" . ($address ?? 'NULL'));
                    
                    // التحقق من البيانات المحفوظة
                    $savedCustomer = $db->queryOne("SELECT name, phone, balance, address FROM customers WHERE id = ?", [$customerId]);
                    if ($savedCustomer) {
                        error_log("✓ Verified saved data - Name: {$savedCustomer['name']}, Phone: {$savedCustomer['phone']}, Balance: {$savedCustomer['balance']}, Address: {$savedCustomer['address']}");
                    }
                    
                    logAudit($currentUser['id'], 'import_customer', 'customer', $customerId, null, [
                        'name' => $name,
                        'source' => 'csv_import'
                    ]);
                }
                
                // حفظ أرقام الهواتف في جدول customer_phones
                try {
                    $phonesToSave = [];
                    if ($phone !== null && trim($phone) !== '') {
                        $phonesToSave[] = trim($phone);
                    }
                    if ($phone2 !== null && trim($phone2) !== '') {
                        $phonesToSave[] = trim($phone2);
                    }
                    
                    error_log("Customer $customerId ($name) - Phones to save: " . json_encode($phonesToSave, JSON_UNESCAPED_UNICODE));
                    error_log("  - Phone1 (index $phoneIndex): " . ($phone ?? 'NULL'));
                    error_log("  - Phone2 (index $phone2Index): " . ($phone2 ?? 'NULL'));
                    
                    if (!empty($phonesToSave)) {
                        // حذف أرقام الهواتف القديمة أولاً (في حالة التحديث)
                        if ($isUpdate) {
                            $db->execute("DELETE FROM customer_phones WHERE customer_id = ?", [$customerId]);
                            error_log("Deleted old phones for customer $customerId");
                        }
                        
                        $firstPhone = true;
                        $savedCount = 0;
                        foreach ($phonesToSave as $phoneNumber) {
                            $phoneNumber = trim($phoneNumber);
                            if (!empty($phoneNumber) && strlen($phoneNumber) > 0) {
                                try {
                                    // التحقق من عدم وجود الرقم مسبقاً
                                    $existingPhone = $db->queryOne(
                                        "SELECT id FROM customer_phones WHERE customer_id = ? AND phone = ?",
                                        [$customerId, $phoneNumber]
                                    );
                                    
                                    if (!$existingPhone) {
                                        $result = $db->execute(
                                            "INSERT INTO customer_phones (customer_id, phone, is_primary) VALUES (?, ?, ?)",
                                            [$customerId, $phoneNumber, $firstPhone ? 1 : 0]
                                        );
                                        $savedCount++;
                                        $phoneId = $db->getLastInsertId();
                                        error_log("✓ Phone saved: ID=$phoneId, Customer ID=$customerId ($name), Phone=$phoneNumber, Primary=" . ($firstPhone ? '1' : '0'));
                                    } else {
                                        error_log("⚠ Phone already exists: Customer ID $customerId, Phone: $phoneNumber");
                                    }
                                    $firstPhone = false;
                                } catch (Exception $phoneInsertError) {
                                    // تسجيل الخطأ ولكن لا نوقف العملية
                                    error_log('✗ Error inserting phone number "' . $phoneNumber . '" for customer ' . $customerId . ': ' . $phoneInsertError->getMessage());
                                }
                            }
                        }
                        error_log("Total phones saved for customer $customerId: $savedCount");
                        
                        // التحقق من الأرقام المحفوظة
                        $savedPhones = $db->query("SELECT phone, is_primary FROM customer_phones WHERE customer_id = ?", [$customerId]);
                        error_log("✓ Verified saved phones for customer $customerId: " . json_encode($savedPhones, JSON_UNESCAPED_UNICODE));
                    } else {
                        error_log("⚠ No phones to save for customer: $name (ID: $customerId) - Phone1: " . ($phone ?? 'NULL') . " (index: $phoneIndex), Phone2: " . ($phone2 ?? 'NULL') . " (index: $phone2Index)");
                    }
                } catch (Exception $phonesError) {
                    // تسجيل الخطأ ولكن لا نوقف العملية
                    error_log('✗ Error saving phone numbers for customer ' . $customerId . ': ' . $phonesError->getMessage());
                    error_log('Stack trace: ' . $phonesError->getTraceAsString());
                }
                
                $imported++;
            } catch (Exception $insertError) {
                $errors[] = "سطر " . ($i + 1) . ": " . $insertError->getMessage();
            }
        }
        
        // تأكيد المعاملة
        $db->commit();
        
        // مسح الكاش
        if (class_exists('Cache')) {
            Cache::flush();
        }
        
        $message = "تم استيراد {$imported} عميل بنجاح";
        if ($skipped > 0) {
            $message .= " وتم تخطي {$skipped} عميل (مكرر)";
        }
        if (!empty($errors)) {
            $message .= ". حدثت أخطاء في " . count($errors) . " سطر";
        }
        
        echo json_encode([
            'success' => true,
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
            'message' => $message
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log('Transaction rollback error: ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
        throw $e;
    }
    
} catch (Exception $e) {
    error_log('Import customers error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    // التأكد من عدم وجود output قبل JSON
    if (ob_get_level() > 0) {
        ob_clean();
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ أثناء استيراد البيانات: ' . $e->getMessage(),
        'error_details' => 'يرجى التحقق من ملف error_log لمزيد من التفاصيل'
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    error_log('Import customers fatal error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    // التأكد من عدم وجود output قبل JSON
    if (ob_get_level() > 0) {
        ob_clean();
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ فادح أثناء استيراد البيانات. يرجى التحقق من ملف error_log'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
