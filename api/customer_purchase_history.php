<?php
/**
 * API for Customer Purchase History
 * API endpoint for retrieving customer purchase history with batch numbers
 */

define('ACCESS_ALLOWED', true);
define('IS_API_REQUEST', true);

header('Content-Type: application/json; charset=utf-8');
ob_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/path_helper.php';
require_once __DIR__ . '/../includes/product_name_helper.php';

ob_clean();

$action = $_GET['action'] ?? $_POST['action'] ?? null;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!$action) {
    returnJson(['success' => false, 'message' => 'الإجراء غير معروف'], 400);
}

// التحقق من تسجيل الدخول
if (!isLoggedIn()) {
    returnJson(['success' => false, 'message' => 'انتهت جلسة العمل، يرجى إعادة تسجيل الدخول'], 401);
}

$currentUser = getCurrentUser();
if (!$currentUser) {
    returnJson(['success' => false, 'message' => 'انتهت جلسة العمل، يرجى إعادة تسجيل الدخول'], 401);
}

$allowedRoles = ['sales', 'manager', 'accountant'];
if (!in_array($currentUser['role'] ?? '', $allowedRoles, true)) {
    returnJson(['success' => false, 'message' => 'ليس لديك صلاحية للوصول إلى هذه الصفحة'], 403);
}

try {
    switch ($action) {
        case 'get_history':
            if ($method !== 'GET') {
                returnJson(['success' => false, 'message' => 'يجب استخدام طلب GET'], 405);
            }
            handleGetHistory();
            break;
            
        case 'search':
            if ($method !== 'GET') {
                returnJson(['success' => false, 'message' => 'يجب استخدام طلب GET'], 405);
            }
            handleSearch();
            break;
            
        default:
            returnJson(['success' => false, 'message' => 'إجراء غير مدعوم'], 400);
    }
} catch (Throwable $e) {
    error_log('customer_purchase_history API error: ' . $e->getMessage());
    returnJson(['success' => false, 'message' => 'حدث خطأ غير متوقع: ' . $e->getMessage()], 500);
}

function returnJson(array $data, int $status = 200): void
{
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Get customer purchase history
 */
function handleGetHistory(): void
{
    global $currentUser;
    
    $customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
    $customerType = isset($_GET['type']) ? trim($_GET['type']) : 'normal'; // 'normal' or 'local'
    $isLocalCustomer = ($customerType === 'local');
    
    if ($customerId <= 0) {
        returnJson(['success' => false, 'message' => 'معرف العميل غير صالح'], 422);
    }
    
    $db = db();
    
    // Verify customer exists
    if ($isLocalCustomer) {
        // عميل محلي
        $customer = $db->queryOne(
            "SELECT id, name, phone, address, balance FROM local_customers WHERE id = ?",
            [$customerId]
        );
    } else {
        // عميل عادي
        $customer = $db->queryOne(
            "SELECT id, name, phone, address, created_by, balance FROM customers WHERE id = ?",
            [$customerId]
        );
        
        // التحقق من ملكية العميل للمندوب (إذا كان المستخدم مندوب)
        if ($currentUser['role'] === 'sales') {
            $salesRepId = (int)$currentUser['id'];
            if ((int)($customer['created_by'] ?? 0) !== $salesRepId) {
                returnJson(['success' => false, 'message' => 'هذا العميل غير مرتبط بك'], 403);
            }
        }
    }
    
    if (!$customer) {
        returnJson(['success' => false, 'message' => 'العميل غير موجود'], 404);
    }
    
    // Get purchase history based on customer type
    if ($isLocalCustomer) {
        // جلب سجل المشتريات من الفواتير المحلية
        $localInvoicesTableExists = $db->queryOne("SHOW TABLES LIKE 'local_invoices'");
        if (empty($localInvoicesTableExists)) {
            returnJson([
                'success' => true,
                'customer' => [
                    'id' => (int)$customer['id'],
                    'name' => $customer['name'],
                    'phone' => $customer['phone'] ?? '',
                    'address' => $customer['address'] ?? '',
                    'balance' => (float)($customer['balance'] ?? 0)
                ],
                'purchase_history' => []
            ]);
        }
        
        // جلب سجل المشتريات من الفواتير المحلية
        $localInvoiceItemsTableExists = $db->queryOne("SHOW TABLES LIKE 'local_invoice_items'");
        if (empty($localInvoiceItemsTableExists)) {
            // إذا لم يكن هناك جدول local_invoice_items، نعيد قائمة فارغة
            $purchaseHistory = [];
        } else {
            // التحقق من وجود أعمدة batch_number و batch_id في local_invoice_items
            $hasBatchNumber = !empty($db->queryOne("SHOW COLUMNS FROM local_invoice_items LIKE 'batch_number'"));
            $hasBatchId = !empty($db->queryOne("SHOW COLUMNS FROM local_invoice_items LIKE 'batch_id'"));
            
            // بناء الاستعلام ديناميكياً - استخدام sales_batch_numbers أيضاً للعملاء المحليين
            // أولاً: محاولة جلب رقم التشغيلة من sales_batch_numbers (مثل العملاء العاديين)
            // ثانياً: إذا لم يوجد، استخدام batch_number من local_invoice_items
            
            $batchSelect = '';
            $batchJoin = '';
            
            // التحقق من وجود الجداول المطلوبة
            $hasSalesBatchNumbers = !empty($db->queryOne("SHOW TABLES LIKE 'sales_batch_numbers'"));
            $hasInvoicesTable = !empty($db->queryOne("SHOW TABLES LIKE 'invoices'"));
            $hasInvoiceItemsTable = !empty($db->queryOne("SHOW TABLES LIKE 'invoice_items'"));
            
            // ===== جلب رقم التشغيلة - الأولوية لـ batch_number المحفوظ في local_invoice_items =====
            // هذا أكثر موثوقية لأنه تم حفظه مباشرة من finished_products عند التسليم
            
            if ($hasBatchNumber && $hasBatchId) {
                // الحالة المثالية: كلا العمودين موجودان
                // نستخدم batch_number مباشرة من local_invoice_items - أبسط طريقة وأكثر موثوقية
                $batchSelect = "
                    COALESCE(
                        NULLIF(TRIM(ii.batch_number), ''),
                        CASE 
                            WHEN ii.batch_id IS NOT NULL AND ii.batch_id > 0 
                            THEN (
                                SELECT NULLIF(TRIM(fp.batch_number), '')
                                FROM finished_products fp
                                WHERE fp.id = ii.batch_id
                                LIMIT 1
                            )
                            ELSE NULL
                        END,
                        ''
                    ) as batch_numbers,
                    COALESCE(
                        CASE 
                            WHEN ii.batch_number IS NOT NULL AND TRIM(ii.batch_number) != ''
                            THEN (
                                SELECT CAST(bn.id AS CHAR)
                                FROM batch_numbers bn
                                WHERE bn.batch_number = TRIM(ii.batch_number)
                                LIMIT 1
                            )
                            ELSE NULL
                        END,
                        CASE 
                            WHEN ii.batch_id IS NOT NULL AND ii.batch_id > 0 
                            THEN CAST(ii.batch_id AS CHAR)
                            ELSE NULL
                        END,
                        ''
                    ) as batch_number_ids";
                $batchJoin = "";
            } elseif ($hasBatchNumber) {
                // فقط batch_number موجود - أبسط طريقة
                $batchSelect = "
                    COALESCE(NULLIF(TRIM(ii.batch_number), ''), '') as batch_numbers,
                    COALESCE(
                        CASE 
                            WHEN ii.batch_number IS NOT NULL AND TRIM(ii.batch_number) != ''
                            THEN (
                                SELECT CAST(bn.id AS CHAR)
                                FROM batch_numbers bn
                                WHERE bn.batch_number = TRIM(ii.batch_number)
                                LIMIT 1
                            )
                            ELSE NULL
                        END,
                        ''
                    ) as batch_number_ids";
                $batchJoin = "";
            } elseif ($hasBatchId) {
                // فقط batch_id موجود - جلب batch_number من finished_products
                $batchSelect = "
                    COALESCE(
                        CASE 
                            WHEN ii.batch_id IS NOT NULL AND ii.batch_id > 0 
                            THEN (
                                SELECT NULLIF(TRIM(fp.batch_number), '')
                                FROM finished_products fp
                                WHERE fp.id = ii.batch_id
                                LIMIT 1
                            )
                            ELSE NULL
                        END,
                        ''
                    ) as batch_numbers,
                    CASE 
                        WHEN ii.batch_id IS NOT NULL AND ii.batch_id > 0 
                        THEN CAST(ii.batch_id AS CHAR)
                        ELSE ''
                    END as batch_number_ids";
                $batchJoin = "";
            } else {
                // لا يوجد أي عمود
                $batchSelect = "'' as batch_numbers, '' as batch_number_ids";
                $batchJoin = "";
            }
            
            // ===== بناء استعلام لجلب اسم المنتج الصحيح =====
            // الأولوية: 1) description من local_invoice_items (تم حفظه بشكل صحيح عند التسليم)
            //          2) اسم من products المرتبط بـ fp.product_id
            //          3) اسم من products المرتبط بـ bn.product_id  
            //          4) fp.product_name من finished_products
            //          5) p.name من products
            $productNameSelect = '';
            if ($hasBatchId) {
                $productNameSelect = "COALESCE(
                    -- أولاً: استخدام description المحفوظ في local_invoice_items (إذا كان صالحاً)
                    CASE 
                        WHEN ii.description IS NOT NULL 
                             AND TRIM(ii.description) != '' 
                             AND ii.description NOT LIKE 'منتج رقم%'
                             AND ii.description NOT LIKE 'غير محدد%'
                        THEN TRIM(ii.description)
                        ELSE NULL
                    END,
                    -- ثانياً: جلب اسم المنتج من finished_products/products إذا كان batch_id موجوداً
                    CASE 
                        WHEN ii.batch_id IS NOT NULL AND ii.batch_id > 0 
                        THEN (
                            SELECT COALESCE(
                                -- اسم من products المرتبط بـ fp.product_id
                                NULLIF(TRIM(pr1.name), ''),
                                -- اسم من products المرتبط بـ bn.product_id
                                NULLIF(TRIM(pr2.name), ''),
                                -- اسم من finished_products
                                NULLIF(TRIM(fp.product_name), ''),
                                NULL
                            )
                            FROM finished_products fp
                            LEFT JOIN batch_numbers bn ON fp.batch_number = bn.batch_number
                            LEFT JOIN products pr1 ON fp.product_id = pr1.id
                            LEFT JOIN products pr2 ON bn.product_id = pr2.id
                            WHERE fp.id = ii.batch_id
                              AND (
                                  (pr1.name IS NOT NULL AND TRIM(pr1.name) != '' AND pr1.name NOT LIKE 'منتج رقم%')
                                  OR (pr2.name IS NOT NULL AND TRIM(pr2.name) != '' AND pr2.name NOT LIKE 'منتج رقم%')
                                  OR (fp.product_name IS NOT NULL AND TRIM(fp.product_name) != '' AND fp.product_name NOT LIKE 'منتج رقم%')
                              )
                            LIMIT 1
                        )
                        ELSE NULL
                    END,
                    -- ثالثاً: اسم من products
                    NULLIF(TRIM(p.name), ''),
                    -- رابعاً: قيمة افتراضية
                    'غير محدد'
                ) as product_name";
            } else {
                // إذا لم يكن batch_id موجوداً، نستخدم description أو اسم المنتج من products
                $productNameSelect = "COALESCE(
                    CASE 
                        WHEN ii.description IS NOT NULL 
                             AND TRIM(ii.description) != '' 
                             AND ii.description NOT LIKE 'منتج رقم%'
                             AND ii.description NOT LIKE 'غير محدد%'
                        THEN TRIM(ii.description)
                        ELSE NULL
                    END,
                    NULLIF(TRIM(p.name), ''),
                    'غير محدد'
                ) as product_name";
            }
            
            $purchaseHistory = $db->query(
                "SELECT 
                    i.id as invoice_id,
                    i.invoice_number,
                    i.date as invoice_date,
                    i.total_amount,
                    i.paid_amount,
                    i.status as invoice_status,
                    ii.id as invoice_item_id,
                    ii.product_id,
                    $productNameSelect,
                    p.unit,
                    ii.quantity,
                    ii.unit_price,
                    ii.total_price,
                    $batchSelect,
                    -- إضافة حقول إضافية للتشخيص
                    ii.batch_number as raw_batch_number,
                    ii.batch_id as raw_batch_id
                FROM local_invoices i
                INNER JOIN local_invoice_items ii ON i.id = ii.invoice_id
                LEFT JOIN products p ON ii.product_id = p.id
                $batchJoin
                WHERE i.customer_id = ?
                -- GROUP BY غير ضروري هنا لأن ii.id هو primary key
                ORDER BY i.date DESC, i.id DESC, ii.id ASC",
                [$customerId]
            ) ?: [];
            
            // تسجيل البيانات المُجلبة للتشخيص
            if (!empty($purchaseHistory)) {
                error_log("customer_purchase_history API: Found " . count($purchaseHistory) . " items for local customer_id=$customerId");
                foreach ($purchaseHistory as $idx => $item) {
                    error_log(sprintf(
                        "customer_purchase_history API: Item[%d] - invoice_item_id=%d, product_id=%d, batch_numbers=%s, raw_batch_number=%s, raw_batch_id=%s",
                        $idx,
                        $item['invoice_item_id'] ?? 0,
                        $item['product_id'] ?? 0,
                        $item['batch_numbers'] ?? 'NULL',
                        $item['raw_batch_number'] ?? 'NULL',
                        $item['raw_batch_id'] ?? 'NULL'
                    ));
                }
            } else {
                error_log("customer_purchase_history API: No items found for local customer_id=$customerId");
            }
            
            // إزالة الحقول الإضافية قبل إرسال الاستجابة
            foreach ($purchaseHistory as &$item) {
                unset($item['raw_batch_number'], $item['raw_batch_id']);
            }
            unset($item);
        }
    } else {
        // Get purchase history from invoices with batch numbers
        // استعلام محسّن يضمن جلب اسم المنتج ورقم التشغيلة بشكل صحيح
        $purchaseHistory = $db->query(
            "SELECT 
                i.id as invoice_id,
                i.invoice_number,
                i.date as invoice_date,
                i.total_amount,
                i.paid_amount,
                i.status as invoice_status,
                ii.id as invoice_item_id,
                ii.product_id,
                COALESCE(
                    (SELECT COALESCE(
                        NULLIF(TRIM(pr2.name), ''),
                        NULLIF(TRIM(fp2.product_name), ''),
                        NULL
                    )
                     FROM sales_batch_numbers sbn2
                     INNER JOIN batch_numbers bn2 ON sbn2.batch_number_id = bn2.id
                     INNER JOIN finished_products fp2 ON fp2.batch_number = bn2.batch_number
                     LEFT JOIN products pr2 ON COALESCE(fp2.product_id, bn2.product_id) = pr2.id
                     WHERE sbn2.invoice_item_id = ii.id
                       AND bn2.batch_number IS NOT NULL 
                       AND TRIM(bn2.batch_number) != ''
                       AND (
                           (pr2.name IS NOT NULL AND TRIM(pr2.name) != '' AND pr2.name NOT LIKE 'منتج رقم%')
                           OR (fp2.product_name IS NOT NULL AND TRIM(fp2.product_name) != '' AND fp2.product_name NOT LIKE 'منتج رقم%')
                       )
                     ORDER BY 
                       CASE WHEN COALESCE(fp2.product_id, bn2.product_id) = ii.product_id THEN 0 ELSE 1 END,
                       CASE WHEN fp2.product_name IS NOT NULL AND TRIM(fp2.product_name) != '' AND fp2.product_name NOT LIKE 'منتج رقم%' THEN 0 ELSE 1 END,
                       CASE WHEN pr2.name IS NOT NULL AND TRIM(pr2.name) != '' AND pr2.name NOT LIKE 'منتج رقم%' THEN 0 ELSE 1 END,
                       sbn2.id DESC,
                       fp2.id DESC 
                     LIMIT 1),
                    NULLIF(TRIM(p.name), ''),
                    CONCAT('منتج رقم ', p.id)
                ) as product_name,
                p.unit,
                ii.quantity,
                ii.unit_price,
                ii.total_price,
                COALESCE(
                    NULLIF(TRIM(GROUP_CONCAT(DISTINCT bn.batch_number ORDER BY bn.batch_number SEPARATOR ', ')), ''),
                    ''
                ) as batch_numbers,
                COALESCE(
                    NULLIF(TRIM(GROUP_CONCAT(DISTINCT bn.id ORDER BY bn.id SEPARATOR ',')), ''),
                    ''
                ) as batch_number_ids
            FROM invoices i
            INNER JOIN invoice_items ii ON i.id = ii.invoice_id
            LEFT JOIN products p ON ii.product_id = p.id
            LEFT JOIN sales_batch_numbers sbn ON ii.id = sbn.invoice_item_id
            LEFT JOIN batch_numbers bn ON sbn.batch_number_id = bn.id AND bn.batch_number IS NOT NULL AND TRIM(bn.batch_number) != ''
            WHERE i.customer_id = ?
            GROUP BY i.id, ii.id, ii.product_id, ii.quantity, ii.unit_price, ii.total_price, p.name, p.unit
            ORDER BY i.date DESC, i.id DESC, ii.id ASC",
            [$customerId]
        );
    }
    
    // Calculate already returned quantities (للعملاء المحليين والعاديين)
    
    // Calculate already returned quantities
    $returnedQuantities = [];
    
    // Check if invoice_item_id column exists
    $hasInvoiceItemId = false;
    try {
        $columnCheck = $db->queryOne("SHOW COLUMNS FROM return_items LIKE 'invoice_item_id'");
        $hasInvoiceItemId = !empty($columnCheck);
    } catch (Throwable $e) {
        $hasInvoiceItemId = false;
    }
    
    if ($hasInvoiceItemId) {
        if ($isLocalCustomer) {
            // للعملاء المحليين - جلب المرتجعات من local_returns إن وجدت
            $localReturnsTableExists = $db->queryOne("SHOW TABLES LIKE 'local_returns'");
            if (!empty($localReturnsTableExists)) {
                $localReturnItemsTableExists = $db->queryOne("SHOW TABLES LIKE 'local_return_items'");
                if (!empty($localReturnItemsTableExists)) {
                    $hasLocalInvoiceItemId = !empty($db->queryOne("SHOW COLUMNS FROM local_return_items LIKE 'invoice_item_id'"));
                    if ($hasLocalInvoiceItemId) {
                        $returnedRows = $db->query(
                            "SELECT ri.invoice_item_id, COALESCE(SUM(ri.quantity), 0) AS returned_quantity
                             FROM local_return_items ri
                             INNER JOIN local_returns r ON r.id = ri.return_id
                             WHERE r.customer_id = ?
                               AND r.status IN ('pending', 'approved', 'processed', 'completed')
                               AND ri.invoice_item_id IS NOT NULL
                             GROUP BY ri.invoice_item_id",
                            [$customerId]
                        ) ?: [];
                        
                        foreach ($returnedRows as $row) {
                            $invoiceItemId = (int)$row['invoice_item_id'];
                            $returnedQuantities[$invoiceItemId] = (float)$row['returned_quantity'];
                        }
                    }
                }
            }
        } else {
            // للعملاء العاديين - حساب الكمية المرتجعة لكل invoice_item_id
            $returnedRows = $db->query(
                "SELECT ri.invoice_item_id, COALESCE(SUM(ri.quantity), 0) AS returned_quantity
                 FROM return_items ri
                 INNER JOIN returns r ON r.id = ri.return_id
                 WHERE r.customer_id = ?
                   AND r.status IN ('pending', 'approved', 'processed', 'completed')
                   AND ri.invoice_item_id IS NOT NULL
                 GROUP BY ri.invoice_item_id",
                [$customerId]
            ) ?: [];
            
            foreach ($returnedRows as $row) {
                $invoiceItemId = (int)$row['invoice_item_id'];
                $returnedQuantities[$invoiceItemId] = (float)$row['returned_quantity'];
            }
        }
    }
    
    // Format results
    $result = [];
    foreach ($purchaseHistory as $item) {
        // تسجيل تشخيصي للتحقق من البيانات
        if (empty($item['batch_numbers']) || empty($item['product_name']) || ($item['product_name'] ?? '') === 'غير معروف') {
            $invoiceItemId = (int)$item['invoice_item_id'];
            $debugInfo = $db->queryOne(
                "SELECT 
                    ii.id as invoice_item_id,
                    ii.product_id,
                    p.name as product_name_from_products,
                    GROUP_CONCAT(DISTINCT bn.batch_number ORDER BY bn.batch_number SEPARATOR ', ') as batch_numbers_debug,
                    GROUP_CONCAT(DISTINCT fp.product_name ORDER BY fp.id DESC SEPARATOR ', ') as product_names_from_fp
                FROM invoice_items ii
                LEFT JOIN products p ON ii.product_id = p.id
                LEFT JOIN sales_batch_numbers sbn ON ii.id = sbn.invoice_item_id
                LEFT JOIN batch_numbers bn ON sbn.batch_number_id = bn.id
                LEFT JOIN finished_products fp ON fp.batch_number = bn.batch_number
                WHERE ii.id = ?
                GROUP BY ii.id",
                [$invoiceItemId]
            );
            if ($debugInfo) {
                error_log("customer_purchase_history: DEBUG for invoice_item_id=$invoiceItemId - batch_numbers_debug=" . ($debugInfo['batch_numbers_debug'] ?? 'NULL') . ", product_name_from_products=" . ($debugInfo['product_name_from_products'] ?? 'NULL') . ", product_names_from_fp=" . ($debugInfo['product_names_from_fp'] ?? 'NULL'));
            }
        }
        $invoiceItemId = (int)$item['invoice_item_id'];
        $quantity = (float)$item['quantity'];
        $batchNumberIds = [];
        $batchNumbers = [];
        
        if ($isLocalCustomer) {
            // للعملاء المحليين - جلب batch numbers من local_invoice_items
            if (!empty($item['batch_number_ids']) && $item['batch_number_ids'] !== '') {
                $batchNumberIds = array_map('intval', array_filter(explode(',', $item['batch_number_ids'])));
            } else {
                $batchNumberIds = [];
            }
            
            if (!empty($item['batch_numbers']) && $item['batch_numbers'] !== '') {
                // إذا كان batch_numbers سلسلة واحدة، نحولها إلى مصفوفة
                $batchNumbersStr = trim($item['batch_numbers']);
                if (strpos($batchNumbersStr, ',') !== false) {
                    $batchNumbers = array_filter(array_map('trim', explode(',', $batchNumbersStr)));
                } else {
                    $batchNumbers = [$batchNumbersStr];
                }
            } else {
                $batchNumbers = [];
            }
        } else {
            // للعملاء العاديين - معالجة batch_numbers و batch_number_ids
            if (!empty($item['batch_number_ids']) && trim($item['batch_number_ids']) !== '') {
                $batchNumberIdsStr = trim($item['batch_number_ids']);
                $batchNumberIds = array_filter(array_map('intval', explode(',', $batchNumberIdsStr)));
            } else {
                $batchNumberIds = [];
            }
            
            if (!empty($item['batch_numbers']) && trim($item['batch_numbers']) !== '') {
                $batchNumbersStr = trim($item['batch_numbers']);
                // تقسيم بناءً على الفاصلة مع مسافة أو بدون
                $batchNumbers = array_filter(array_map('trim', preg_split('/,\s*/', $batchNumbersStr)));
            } else {
                $batchNumbers = [];
            }
            
            // إذا كان batch_numbers فارغاً لكن batch_number_ids موجود، نحاول جلب batch_numbers من قاعدة البيانات
            if (empty($batchNumbers) && !empty($batchNumberIds)) {
                $batchNumbersFromDb = $db->query(
                    "SELECT batch_number FROM batch_numbers WHERE id IN (" . implode(',', array_map('intval', $batchNumberIds)) . ") ORDER BY batch_number"
                );
                if ($batchNumbersFromDb) {
                    $batchNumbers = array_filter(array_map(function($row) {
                        return trim($row['batch_number'] ?? '');
                    }, $batchNumbersFromDb));
                }
            }
        }
        
        // Calculate returned quantity - مجموع الكميات المرتجعة لكل invoice_item_id
        $returnedQuantity = 0.0;
        if ($hasInvoiceItemId) {
            $returnedQuantity = $returnedQuantities[$invoiceItemId] ?? 0.0;
        }
        
        $availableToReturn = max(0, $quantity - $returnedQuantity);
        
        $result[] = [
            'invoice_id' => (int)$item['invoice_id'],
            'invoice_number' => $item['invoice_number'],
            'invoice_date' => $item['invoice_date'],
            'invoice_item_id' => $invoiceItemId,
            'product_id' => (int)$item['product_id'],
            'product_name' => $item['product_name'] ?? 'غير معروف',
            'unit' => $item['unit'] ?? 'قطعة',
            'quantity' => $quantity,
            'returned_quantity' => $returnedQuantity,
            'available_to_return' => $availableToReturn,
            'unit_price' => (float)$item['unit_price'],
            'total_price' => (float)$item['total_price'],
            'batch_numbers' => $batchNumbers,
            'batch_number_ids' => array_map('intval', $batchNumberIds),
            'can_return' => $availableToReturn > 0
        ];
    }
    
    returnJson([
        'success' => true,
        'customer' => [
            'id' => (int)$customer['id'],
            'name' => $customer['name'],
            'phone' => $customer['phone'] ?? '',
            'address' => $customer['address'] ?? '',
            'balance' => (float)$customer['balance']
        ],
        'purchase_history' => $result
    ]);
}

/**
 * Search purchase history
 */
function handleSearch(): void
{
    global $currentUser;
    
    $customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
    $batchNumber = isset($_GET['batch_number']) ? trim($_GET['batch_number']) : '';
    $productName = isset($_GET['product_name']) ? trim($_GET['product_name']) : '';
    
    if ($customerId <= 0) {
        returnJson(['success' => false, 'message' => 'معرف العميل غير صالح'], 422);
    }
    
    $db = db();
    
    // Verify customer
    $customer = $db->queryOne(
        "SELECT id, name, created_by FROM customers WHERE id = ?",
        [$customerId]
    );
    
    if (!$customer) {
        returnJson(['success' => false, 'message' => 'العميل غير موجود'], 404);
    }
    
    if ($currentUser['role'] === 'sales') {
        $salesRepId = (int)$currentUser['id'];
        if ((int)($customer['created_by'] ?? 0) !== $salesRepId) {
            returnJson(['success' => false, 'message' => 'هذا العميل غير مرتبط بك'], 403);
        }
    }
    
    // Build search query
    $sql = "SELECT 
            i.id as invoice_id,
            i.invoice_number,
            i.date as invoice_date,
            ii.id as invoice_item_id,
            ii.product_id,
            COALESCE(
                (SELECT COALESCE(
                    NULLIF(TRIM(pr2.name), ''),
                    NULLIF(TRIM(fp2.product_name), ''),
                    NULL
                )
                 FROM sales_batch_numbers sbn2
                 INNER JOIN batch_numbers bn2 ON sbn2.batch_number_id = bn2.id
                 INNER JOIN finished_products fp2 ON fp2.batch_number = bn2.batch_number
                 LEFT JOIN products pr2 ON COALESCE(fp2.product_id, bn2.product_id) = pr2.id
                 WHERE sbn2.invoice_item_id = ii.id
                   AND (
                       (pr2.name IS NOT NULL AND TRIM(pr2.name) != '' AND pr2.name NOT LIKE 'منتج رقم%')
                       OR (fp2.product_name IS NOT NULL AND TRIM(fp2.product_name) != '' AND fp2.product_name NOT LIKE 'منتج رقم%')
                   )
                 ORDER BY fp2.id DESC 
                 LIMIT 1),
                NULLIF(TRIM(p.name), ''),
                CONCAT('منتج رقم ', p.id)
            ) as product_name,
            p.unit,
            ii.quantity,
            ii.unit_price,
            ii.total_price,
            GROUP_CONCAT(DISTINCT bn.batch_number ORDER BY bn.batch_number SEPARATOR ', ') as batch_numbers,
            GROUP_CONCAT(DISTINCT bn.id ORDER BY bn.id SEPARATOR ',') as batch_number_ids
        FROM invoices i
        INNER JOIN invoice_items ii ON i.id = ii.invoice_id
        LEFT JOIN products p ON ii.product_id = p.id
        LEFT JOIN sales_batch_numbers sbn ON ii.id = sbn.invoice_item_id
        LEFT JOIN batch_numbers bn ON sbn.batch_number_id = bn.id
        WHERE i.customer_id = ?";
    
    $params = [$customerId];
    
    if ($batchNumber) {
        $sql .= " AND bn.batch_number LIKE ?";
        $params[] = "%{$batchNumber}%";
    }
    
    if ($productName) {
        $sql .= " AND (p.name LIKE ? OR 
                EXISTS (SELECT 1 FROM finished_products fp 
                        INNER JOIN batch_numbers bn3 ON fp.batch_id = bn3.id
                        INNER JOIN sales_batch_numbers sbn3 ON bn3.id = sbn3.batch_number_id
                        WHERE sbn3.invoice_item_id = ii.id 
                        AND fp.product_name LIKE ?))";
        $params[] = "%{$productName}%";
        $params[] = "%{$productName}%";
    }
    
    $sql .= " GROUP BY i.id, ii.id
              ORDER BY i.date DESC, i.id DESC";
    
    $results = $db->query($sql, $params);
    
    $formatted = [];
    foreach ($results as $item) {
        $formatted[] = [
            'invoice_id' => (int)$item['invoice_id'],
            'invoice_number' => $item['invoice_number'],
            'invoice_date' => $item['invoice_date'],
            'invoice_item_id' => (int)$item['invoice_item_id'],
            'product_id' => (int)$item['product_id'],
            'product_name' => $item['product_name'] ?? 'غير معروف',
            'unit' => $item['unit'] ?? 'قطعة',
            'quantity' => (float)$item['quantity'],
            'unit_price' => (float)$item['unit_price'],
            'total_price' => (float)$item['total_price'],
            'batch_numbers' => !empty($item['batch_numbers']) ? explode(', ', $item['batch_numbers']) : [],
            'batch_number_ids' => !empty($item['batch_number_ids']) ? array_map('intval', explode(',', $item['batch_number_ids'])) : []
        ];
    }
    
    returnJson([
        'success' => true,
        'results' => $formatted
    ]);
}

