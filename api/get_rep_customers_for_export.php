<?php
/**
 * API for Getting Sales Rep Customers for Export
 * Returns detailed customer data for a specific sales rep
 */

// ===== بداية الإعداد الحرج لضمان JSON فقط =====

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

// دالة الإرجاع JSON
function returnJsonResponse(array $data, int $status = 200): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    }
    
    $json = @json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $json = '{"success":false,"message":"خطأ في تنسيق البيانات"}';
    }
    
    echo $json;
    exit;
}

// ===== تحميل الملفات المطلوبة =====
try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/path_helper.php';
    
    // التحقق من تسجيل الدخول
    if (!isLoggedIn()) {
        returnJsonResponse([
            'success' => false,
            'message' => 'غير مصرح لك بالوصول'
        ], 401);
    }
    
    $currentUser = getCurrentUser();
    $currentRole = strtolower((string)($currentUser['role'] ?? ''));
    
    // التحقق من الصلاحيات
    $allowedRoles = ['manager', 'developer', 'accountant', 'sales'];
    if (!in_array($currentRole, $allowedRoles, true)) {
        returnJsonResponse([
            'success' => false,
            'message' => 'غير مصرح لك بتنفيذ هذه العملية'
        ], 403);
    }
    
    // التحقق من نوع الطلب
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        returnJsonResponse([
            'success' => false,
            'message' => 'طريقة الطلب غير صحيحة'
        ], 405);
    }
    
    $db = db();
    
    // جلب معرف المندوب
    $repId = isset($_GET['rep_id']) ? (int)$_GET['rep_id'] : 0;
    
    // إذا كان المستخدم مندوب، يمكنه فقط جلب عملائه
    if ($currentRole === 'sales') {
        $repId = (int)($currentUser['id'] ?? 0);
    }
    
    if ($repId <= 0) {
        returnJsonResponse([
            'success' => false,
            'message' => 'معرف المندوب غير صحيح'
        ], 400);
    }
    
    // التحقق من وجود المندوب
    $rep = $db->queryOne(
        "SELECT id, full_name, username FROM users WHERE id = ? AND role = 'sales' LIMIT 1",
        [$repId]
    );
    
    if (!$rep) {
        returnJsonResponse([
            'success' => false,
            'message' => 'المندوب المطلوب غير موجود'
        ], 404);
    }
    
    // معاملات pagination - زيادة عدد العملاء في الصفحة الواحدة لتسريع التحميل
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $perPage = 20; // 20 عميل في كل صفحة (زيادة من 3 لتسريع التحميل)
    $offset = ($page - 1) * $perPage;
    
    // جلب إجمالي عدد عملاء المندوب المدينين فقط
    $totalCountResult = $db->queryOne(
        "SELECT COUNT(*) as total
         FROM customers c
         WHERE c.created_by = ? AND c.status = 'active' AND (c.balance IS NOT NULL AND c.balance > 0)",
        [$repId]
    );
    $totalCount = (int)($totalCountResult['total'] ?? 0);
    $totalPages = ceil($totalCount / $perPage);
    
    // جلب عملاء المندوب مع pagination - استعلام محسّن للأداء
    // فحص أمني: العملاء يظهرون فقط للمندوب الذي أنشأهم (created_by)
    // وليس بناءً على rep_id - هذا يضمن عدم ظهور عملاء المندوب القديم للمندوب الجديد
    // فلترة: عرض العملاء أصحاب الرصيد المدين فقط (balance > 0)
    // تحسين: استخدام index على created_by و balance لتسريع الاستعلام
    $customers = $db->query(
        "SELECT c.id, c.name, c.phone, c.address, c.balance, c.created_by, r.name as region_name
         FROM customers c
         LEFT JOIN regions r ON c.region_id = r.id
         WHERE c.created_by = ? AND c.status = 'active' AND c.balance > 0
         ORDER BY c.name ASC
         LIMIT ? OFFSET ?",
        [$repId, $perPage, $offset]
    );
    
    // التأكد من أن المصفوفة فارغة إذا لم يكن هناك عملاء
    if (empty($customers) || !is_array($customers)) {
        $customers = [];
    }
    
    // التحقق من صحة البيانات وإزالة أي بيانات غير صحيحة
    $validCustomers = [];
    foreach ($customers as $customer) {
        $customerId = (int)($customer['id'] ?? 0);
        $createdBy = (int)($customer['created_by'] ?? 0);
        
        // التأكد من أن العميل ينتمي فعلياً للمندوب المطلوب وأن له معرف واسم صالحين
        if ($customerId > 0 && $createdBy === $repId && !empty(trim($customer['name'] ?? ''))) {
            $validCustomers[] = $customer;
        }
    }
    $customers = $validCustomers;
    
    // جلب جميع أرقام الهواتف دفعة واحدة لتسريع العملية
    $customerIds = array_map(function($c) { return (int)($c['id'] ?? 0); }, $customers);
    $customerIds = array_filter($customerIds, function($id) { return $id > 0; });
    $allPhones = [];
    
    if (!empty($customerIds)) {
        try {
            $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
            $phonesData = $db->query(
                "SELECT customer_id, phone FROM customer_phones WHERE customer_id IN ($placeholders) AND is_primary = 0 ORDER BY customer_id, id ASC",
                $customerIds
            );
            
            foreach ($phonesData as $phoneRow) {
                $cid = (int)($phoneRow['customer_id'] ?? 0);
                $phone = trim($phoneRow['phone'] ?? '');
                if ($cid > 0 && !empty($phone)) {
                    if (!isset($allPhones[$cid])) {
                        $allPhones[$cid] = [];
                    }
                    $allPhones[$cid][] = $phone;
                }
            }
        } catch (Exception $e) {
            error_log('Error fetching customer phones batch: ' . $e->getMessage());
        }
    }
    
    // بناء النتيجة
    $result = [];
    foreach ($customers as $customer) {
        $customerId = (int)($customer['id'] ?? 0);
        $balance = isset($customer['balance']) ? (float)$customer['balance'] : 0.0;
        $customerName = trim($customer['name'] ?? '');
        
        // التأكد من أن البيانات صحيحة قبل الإضافة
        // فلترة: عرض العملاء أصحاب الرصيد المدين فقط (balance > 0)
        if (!empty($customerName) && $customerId > 0 && $balance > 0) {
            $result[] = [
                'id' => $customerId,
                'name' => $customerName,
                'phone' => trim($customer['phone'] ?? ''),
                'alternative_phones' => $allPhones[$customerId] ?? [],
                'balance' => $balance,
                'balance_formatted' => number_format(abs($balance), 2) . ' ج.م',
                'address' => trim($customer['address'] ?? ''),
                'region_name' => trim($customer['region_name'] ?? ''),
            ];
        }
    }
    
    // فلترة النتيجة النهائية للتأكد من عدم وجود بيانات غير صالحة
    // فلترة: عرض العملاء أصحاب الرصيد المدين فقط (balance > 0)
    $result = array_filter($result, function($customer) {
        // التأكد من وجود معرف واسم صالحين ورصيد مدين
        return isset($customer['id']) && 
               (int)$customer['id'] > 0 && 
               !empty(trim($customer['name'] ?? '')) &&
               isset($customer['balance']) &&
               (float)$customer['balance'] > 0;
    });
    
    // إعادة ترقيم المصفوفة
    $result = array_values($result);
    
    returnJsonResponse([
        'success' => true,
        'rep' => [
            'id' => (int)$rep['id'],
            'name' => $rep['full_name'] ?? $rep['username'] ?? '',
        ],
        'customers' => $result,
        'total' => $totalCount,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $totalPages,
        'has_more' => $page < $totalPages
    ]);
    
} catch (Exception $e) {
    error_log('Get rep customers API error: ' . $e->getMessage());
    returnJsonResponse([
        'success' => false,
        'message' => 'حدث خطأ أثناء جلب بيانات العملاء'
    ], 500);
}

