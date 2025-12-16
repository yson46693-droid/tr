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
        while (($row = fgetcsv($handle, 1000, ',', '"', '\\')) !== false) {
            // التأكد من أن الصف له نفس عدد الأعمدة (ملء الأعمدة الفارغة)
            $maxColumns = count($headers);
            while (count($row) < $maxColumns) {
                $row[] = '';
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
    $headers = array_map(function($header) {
        return mb_strtolower(trim($header), 'UTF-8');
    }, $rows[0]);
    
    // البحث عن أعمدة البيانات المطلوبة
    $customerIdIndex = -1;
    $nameIndex = -1;
    $phoneIndex = -1;
    $phone2Index = -1;
    $addressIndex = -1;
    $balanceIndex = -1;
    $regionIndex = -1;
    
    // البحث عن الأعمدة بطرق مختلفة (عربي/إنجليزي)
    $customerIdVariations = ['ايدي العميل', 'id', 'customer_id', 'معرف العميل', 'رقم العميل', 'ايدي', 'معرف', 'م'];
    $nameVariations = ['اسم العميل', 'الاسم', 'name', 'customer name', 'اسم', 'اسم_العميل'];
    $phoneVariations = ['رقم الهاتف', 'الهاتف', 'phone', 'mobile', 'tel', 'رقم_الهاتف', 'رقم الهاتف (الأول)', 'تليفون', 'تلفون', 'telephone'];
    $phone2Variations = ['رقم الهاتف (الثاني)', 'الهاتف الثاني', 'phone2', 'mobile2', 'tel2', 'رقم_الهاتف_الثاني', 'رقم الهاتف الثاني'];
    $addressVariations = ['العنوان', 'address', 'location', 'عنوان'];
    $balanceVariations = ['الرصيد', 'الديون', 'balance', 'debt', 'رصيد', 'ديون', 'صافى المبلغ', 'صافي المبلغ', 'صافى', 'صافي', 'المبلغ', 'net amount'];
    $regionVariations = ['المنطقة', 'region', 'منطقة'];
    
    // قائمة لتخزين جميع أعمدة "تليفون" (لحالة وجود عمودين بنفس الاسم)
    $phoneColumns = [];
    
    foreach ($headers as $index => $header) {
        $headerLower = mb_strtolower(trim($header), 'UTF-8');
        
        // ايدي العميل
        if ($customerIdIndex === -1 && (
            in_array($headerLower, $customerIdVariations, true) || 
            $headerLower === 'م' ||
            strpos($headerLower, 'ايدي') !== false || 
            strpos($headerLower, 'id') !== false ||
            strpos($headerLower, 'معرف') !== false
        )) {
            $customerIdIndex = $index;
        }
        
        // اسم العميل
        if ($nameIndex === -1 && (
            in_array($headerLower, $nameVariations, true) || 
            strpos($headerLower, 'اسم') !== false || 
            strpos($headerLower, 'name') !== false
        )) {
            $nameIndex = $index;
        }
        
        // رقم الهاتف - جمع جميع أعمدة "تليفون" أولاً
        if (in_array($headerLower, $phoneVariations, true) || 
            strpos($headerLower, 'تليفون') !== false ||
            strpos($headerLower, 'تلفون') !== false ||
            (strpos($headerLower, 'هاتف') !== false && strpos($headerLower, 'ثاني') === false && strpos($headerLower, '2') === false) ||
            strpos($headerLower, 'phone') !== false ||
            strpos($headerLower, 'mobile') !== false ||
            strpos($headerLower, 'tel') !== false) {
            $phoneColumns[] = $index;
        }
        
        // العنوان
        if ($addressIndex === -1 && (
            in_array($headerLower, $addressVariations, true) || 
            strpos($headerLower, 'عنوان') !== false || 
            strpos($headerLower, 'address') !== false
        )) {
            $addressIndex = $index;
        }
        
        // الرصيد
        if ($balanceIndex === -1 && (
            in_array($headerLower, $balanceVariations, true) || 
            strpos($headerLower, 'رصيد') !== false || 
            strpos($headerLower, 'صافى') !== false ||
            strpos($headerLower, 'صافي') !== false ||
            strpos($headerLower, 'balance') !== false ||
            strpos($headerLower, 'debt') !== false ||
            strpos($headerLower, 'net') !== false
        )) {
            $balanceIndex = $index;
        }
        
        // المنطقة
        if ($regionIndex === -1 && (
            in_array($headerLower, $regionVariations, true) || 
            strpos($headerLower, 'منطقة') !== false || 
            strpos($headerLower, 'region') !== false
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
    
    // التحقق من أن الأعمدة المهمة موجودة
    if ($balanceIndex === -1) {
        error_log('WARNING: balanceIndex not found! Available headers: ' . implode(', ', $headers));
    }
    if ($phoneIndex === -1) {
        error_log('WARNING: phoneIndex not found! Available headers: ' . implode(', ', $headers));
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
                if ($rawPhone !== '' && strlen($rawPhone) > 0) {
                    $phone = $rawPhone;
                }
            } else {
                if ($i <= 5) {
                    error_log("WARNING: Row $i - phoneIndex is -1 or row[$phoneIndex] not set. phoneIndex=$phoneIndex, row length=" . count($row));
                }
            }
            
            // قراءة رقم الهاتف الثاني
            $phone2 = null;
            if ($phone2Index !== -1 && isset($row[$phone2Index])) {
                $rawPhone2 = trim((string)$row[$phone2Index]);
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
                // تحويل إلى نص وإزالة المسافات
                $rawBalance = trim((string)$rawBalance);
                if ($rawBalance !== '' && $rawBalance !== null) {
                    // إزالة الفواصل من الأرقام (مثل 1,000.50)
                    $rawBalance = str_replace(',', '', $rawBalance);
                    // إزالة أي مسافات إضافية
                    $rawBalance = str_replace(' ', '', $rawBalance);
                    // إزالة أي أحرف غير رقمية في البداية والنهاية (مثل $ أو ر.س)
                    $rawBalance = preg_replace('/^[^\d\-+.]*/', '', $rawBalance);
                    $rawBalance = preg_replace('/[^\d\-+.]*$/', '', $rawBalance);
                    // التحقق من أن القيمة رقمية
                    if (is_numeric($rawBalance)) {
                        $balance = (float)$rawBalance;
                    } else {
                        error_log("WARNING: Row $i - Balance value '$rawBalance' is not numeric");
                    }
                }
            } else {
                if ($i <= 5) {
                    error_log("WARNING: Row $i - balanceIndex is -1 or row[$balanceIndex] not set. balanceIndex=$balanceIndex, row length=" . count($row));
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
                        'balance' => isset($row[$balanceIndex]) ? $row[$balanceIndex] : 'NOT_SET',
                        'phone' => isset($row[$phoneIndex]) ? $row[$phoneIndex] : 'NOT_SET',
                        'phone2' => isset($row[$phone2Index]) ? $row[$phone2Index] : 'NOT_SET'
                    ],
                    'row_length' => count($row),
                    'full_row' => $row
                ];
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
                    $updateFields = ['name = ?', 'phone = ?', 'balance = ?', 'address = ?'];
                    $updateValues = [$name, $phone, $balance, $address];
                    
                    if ($hasRegionIdColumn) {
                        $updateFields[] = 'region_id = ?';
                        $updateValues[] = $regionId;
                    }
                    
                    $updateValues[] = $customerId;
                    
                    $db->execute(
                        "UPDATE customers SET " . implode(', ', $updateFields) . " WHERE id = ?",
                        $updateValues
                    );
                    error_log("✓ Customer updated: ID=$customerId, Name=$name, Balance=$balance, Phone=" . ($phone ?? 'NULL') . ", Phone2=" . ($phone2 ?? 'NULL') . ", Address=" . ($address ?? 'NULL'));
                    
                    // حذف أرقام الهواتف القديمة
                    $db->execute("DELETE FROM customer_phones WHERE customer_id = ?", [$customerId]);
                    error_log("Deleted old phones for customer $customerId");
                    
                    logAudit($currentUser['id'], 'update_customer', 'customer', $customerId, null, [
                        'name' => $name,
                        'source' => 'csv_import'
                    ]);
                } else {
                    // إدراج عميل جديد
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
                    error_log("✓ Customer inserted: ID=$customerId, Name=$name, Balance=$balance, Phone=" . ($phone ?? 'NULL') . ", Phone2=" . ($phone2 ?? 'NULL') . ", Address=" . ($address ?? 'NULL'));
                    
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
                    } else {
                        error_log("⚠ No phones to save for customer: $name (ID: $customerId) - Phone1: " . ($phone ?? 'NULL') . ", Phone2: " . ($phone2 ?? 'NULL'));
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
