<?php
/**
 * صفحة إدارة المرتجعات - حساب المدير
 * Manager Returns Management Page
 * 
 * هذه الصفحة مختلفة عن صفحة المندوب وتحتوي على:
 * - جدول لاستقبال طلبات المرتجعات من المندوبين والموافقة عليها
 * - جدول لعرض آخر عمليات المرتجعات
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/approval_system.php';
require_once __DIR__ . '/../../includes/returns_system.php';
require_once __DIR__ . '/../../includes/path_helper.php';

requireRole(['manager', 'accountant']);

$currentUser = getCurrentUser();
$db = db();
$basePath = getBasePath();

// Pagination for pending returns
$pendingPageNum = isset($_GET['pending_p']) ? max(1, intval($_GET['pending_p'])) : 1;
$pendingPerPage = 15;
$pendingOffset = ($pendingPageNum - 1) * $pendingPerPage;

// Pagination for latest returns
$latestPageNum = isset($_GET['latest_p']) ? max(1, intval($_GET['latest_p'])) : 1;
$latestPerPage = 20;
$latestOffset = ($latestPageNum - 1) * $latestPerPage;

// Get pending return requests from delegates and local customers
$entityColumn = getApprovalsEntityColumn();

// التحقق من وجود جدول local_returns
$localReturnsTableExists = $db->queryOne("SHOW TABLES LIKE 'local_returns'");

// جلب مرتجعات المندوبين المعلقة
$pendingReturns = $db->query(
    "SELECT r.*, c.name as customer_name, c.balance as customer_balance,
            u.full_name as sales_rep_name,
            a.id as approval_id, a.created_at as request_date,
            req.full_name as requested_by_name,
            'delegate' as return_type,
            NULL as local_customer_id
     FROM returns r
     INNER JOIN approvals a ON a.type = 'return_request' AND a.{$entityColumn} = r.id
     LEFT JOIN customers c ON r.customer_id = c.id
     LEFT JOIN users u ON r.sales_rep_id = u.id
     LEFT JOIN users req ON a.requested_by = req.id
     WHERE r.status = 'pending' AND a.status = 'pending'
     ORDER BY r.created_at DESC"
);

// جلب مرتجعات العملاء المحليين المعلقة (إذا كان الجدول موجوداً)
$pendingLocalReturns = [];
if (!empty($localReturnsTableExists)) {
    $pendingLocalReturns = $db->query(
        "SELECT lr.*, lc.name as customer_name, lc.balance as customer_balance,
                NULL as sales_rep_name,
                NULL as approval_id,
                lr.created_at as request_date,
                creator.full_name as requested_by_name,
                'local' as return_type,
                lr.customer_id as local_customer_id
         FROM local_returns lr
         LEFT JOIN local_customers lc ON lr.customer_id = lc.id
         LEFT JOIN users creator ON lr.created_by = creator.id
         WHERE lr.status = 'pending'
         ORDER BY lr.created_at DESC"
    ) ?: [];
}

// دمج المرتجعات وترتيبها حسب التاريخ
$allPendingReturns = array_merge($pendingReturns ?: [], $pendingLocalReturns);
usort($allPendingReturns, function($a, $b) {
    return strtotime($b['request_date'] ?? $b['created_at']) - strtotime($a['request_date'] ?? $a['created_at']);
});

// تطبيق pagination
$totalPendingCount = count($allPendingReturns);
$totalPendingPages = ceil($totalPendingCount / $pendingPerPage);
$allPendingReturns = array_slice($allPendingReturns, $pendingOffset, $pendingPerPage);

// Get return items for each pending return
foreach ($allPendingReturns as &$return) {
    if ($return['return_type'] === 'local') {
        // مرتجعات العملاء المحليين
        $returnItemsTableExists = $db->queryOne("SHOW TABLES LIKE 'local_return_items'");
        if (!empty($returnItemsTableExists)) {
            $return['items'] = $db->query(
                "SELECT lri.*, p.name as product_name, p.unit
                 FROM local_return_items lri
                 LEFT JOIN products p ON lri.product_id = p.id
                 WHERE lri.return_id = ?
                 ORDER BY lri.id",
                [(int)$return['id']]
            ) ?: [];
        } else {
            $return['items'] = [];
        }
    } else {
        // مرتجعات المندوبين
        $return['items'] = $db->query(
            "SELECT ri.*, p.name as product_name, p.unit
             FROM return_items ri
             LEFT JOIN products p ON ri.product_id = p.id
             WHERE ri.return_id = ?
             ORDER BY ri.id",
            [(int)$return['id']]
        ) ?: [];
    }
    
    // Calculate customer debt/credit
    $balance = (float)($return['customer_balance'] ?? 0);
    $return['customer_debt'] = $balance > 0 ? $balance : 0;
    $return['customer_credit'] = $balance < 0 ? abs($balance) : 0;
}
unset($return);

// Get latest return operations (approved, rejected, completed)
// مرتجعات المندوبين
$latestReturns = $db->query(
    "SELECT r.*, c.name as customer_name, c.balance as customer_balance,
            u.full_name as sales_rep_name,
            approver.full_name as approved_by_name,
            i.invoice_number,
            'delegate' as return_type,
            NULL as local_customer_id
     FROM returns r
     LEFT JOIN customers c ON r.customer_id = c.id
     LEFT JOIN users u ON r.sales_rep_id = u.id
     LEFT JOIN users approver ON r.approved_by = approver.id
     LEFT JOIN invoices i ON r.invoice_id = i.id
     WHERE r.status IN ('approved', 'rejected', 'processed', 'completed')
     ORDER BY COALESCE(r.approved_at, r.updated_at, r.created_at) DESC"
) ?: [];

// مرتجعات العملاء المحليين
$latestLocalReturns = [];
if (!empty($localReturnsTableExists)) {
    // جلب رقم الفاتورة من local_return_items (أول فاتورة مرتبطة)
    $latestLocalReturns = $db->query(
        "SELECT lr.*, lc.name as customer_name, lc.balance as customer_balance,
                NULL as sales_rep_name,
                approver.full_name as approved_by_name,
                (SELECT li.invoice_number 
                 FROM local_return_items lri 
                 LEFT JOIN local_invoices li ON lri.invoice_id = li.id 
                 WHERE lri.return_id = lr.id 
                 LIMIT 1) as invoice_number,
                'local' as return_type,
                lr.customer_id as local_customer_id
         FROM local_returns lr
         LEFT JOIN local_customers lc ON lr.customer_id = lc.id
         LEFT JOIN users approver ON lr.approved_by = approver.id
         WHERE lr.status IN ('approved', 'rejected', 'processed', 'completed')
         ORDER BY COALESCE(lr.approved_at, lr.updated_at, lr.created_at) DESC"
    ) ?: [];
}

// دمج المرتجعات وترتيبها حسب التاريخ
$allLatestReturns = array_merge($latestReturns, $latestLocalReturns);
usort($allLatestReturns, function($a, $b) {
    $dateA = $a['approved_at'] ?? $a['updated_at'] ?? $a['created_at'];
    $dateB = $b['approved_at'] ?? $b['updated_at'] ?? $b['created_at'];
    return strtotime($dateB) - strtotime($dateA);
});

// تطبيق pagination
$totalLatestCount = count($allLatestReturns);
$totalLatestPages = ceil($totalLatestCount / $latestPerPage);
$allLatestReturns = array_slice($allLatestReturns, $latestOffset, $latestPerPage);

// Get return items for each latest return
foreach ($allLatestReturns as &$return) {
    if ($return['return_type'] === 'local') {
        // مرتجعات العملاء المحليين
        $returnItemsTableExists = $db->queryOne("SHOW TABLES LIKE 'local_return_items'");
        if (!empty($returnItemsTableExists)) {
            $return['items'] = $db->query(
                "SELECT lri.*, p.name as product_name, p.unit
                 FROM local_return_items lri
                 LEFT JOIN products p ON lri.product_id = p.id
                 WHERE lri.return_id = ?
                 ORDER BY lri.id",
                [(int)$return['id']]
            ) ?: [];
        } else {
            $return['items'] = [];
        }
    } else {
        // مرتجعات المندوبين
        $return['items'] = $db->query(
            "SELECT ri.*, p.name as product_name, p.unit
             FROM return_items ri
             LEFT JOIN products p ON ri.product_id = p.id
             WHERE ri.return_id = ?
             ORDER BY ri.id",
            [(int)$return['id']]
        ) ?: [];
    }
    
    // Calculate customer debt/credit
    $balance = (float)($return['customer_balance'] ?? 0);
    $return['customer_debt'] = $balance > 0 ? $balance : 0;
    $return['customer_credit'] = $balance < 0 ? abs($balance) : 0;
}
unset($return);

// Get statistics (including local returns)
$stats = [
    'pending' => $totalPendingCount,
    'approved_today' => 0,
    'total_amount_pending' => 0.0,
    'total_amount_approved_today' => 0.0,
];

// إحصائيات مرتجعات المندوبين
$delegateApprovedToday = (int)$db->queryOne(
    "SELECT COUNT(*) as total
     FROM returns r
     WHERE r.status = 'approved' AND DATE(r.approved_at) = CURDATE()"
)['total'] ?? 0;

$delegateAmountPending = (float)$db->queryOne(
    "SELECT COALESCE(SUM(r.refund_amount), 0) as total
     FROM returns r
     INNER JOIN approvals a ON a.type = 'return_request' AND a.{$entityColumn} = r.id
     WHERE r.status = 'pending' AND a.status = 'pending'"
)['total'] ?? 0;

$delegateAmountApprovedToday = (float)$db->queryOne(
    "SELECT COALESCE(SUM(r.refund_amount), 0) as total
     FROM returns r
     WHERE r.status = 'approved' AND DATE(r.approved_at) = CURDATE()"
)['total'] ?? 0;

$stats['approved_today'] += $delegateApprovedToday;
$stats['total_amount_pending'] += $delegateAmountPending;
$stats['total_amount_approved_today'] += $delegateAmountApprovedToday;

// إحصائيات مرتجعات العملاء المحليين
if (!empty($localReturnsTableExists)) {
    $localApprovedToday = (int)$db->queryOne(
        "SELECT COUNT(*) as total
         FROM local_returns lr
         WHERE lr.status = 'approved' AND DATE(lr.approved_at) = CURDATE()"
    )['total'] ?? 0;
    
    $localAmountPending = (float)$db->queryOne(
        "SELECT COALESCE(SUM(lr.refund_amount), 0) as total
         FROM local_returns lr
         WHERE lr.status = 'pending'"
    )['total'] ?? 0;
    
    $localAmountApprovedToday = (float)$db->queryOne(
        "SELECT COALESCE(SUM(lr.refund_amount), 0) as total
         FROM local_returns lr
         WHERE lr.status = 'approved' AND DATE(lr.approved_at) = CURDATE()"
    )['total'] ?? 0;
    
    $stats['approved_today'] += $localApprovedToday;
    $stats['total_amount_pending'] += $localAmountPending;
    $stats['total_amount_approved_today'] += $localAmountApprovedToday;
}

?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="mb-3">
                <i class="bi bi-arrow-return-left me-2"></i>إدارة المرتجعات
            </h2>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card border-warning">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">طلبات معلقة</h6>
                            <h3 class="mb-0 text-warning"><?php echo $stats['pending']; ?></h3>
                        </div>
                        <div class="text-warning" style="font-size: 2.5rem;">
                            <i class="bi bi-clock-history"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card border-primary">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">معتمدة اليوم</h6>
                            <h3 class="mb-0 text-primary"><?php echo $stats['approved_today']; ?></h3>
                        </div>
                        <div class="text-primary" style="font-size: 2.5rem;">
                            <i class="bi bi-check-circle"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card border-danger">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">مبلغ معلق</h6>
                            <h3 class="mb-0 text-danger"><?php echo number_format($stats['total_amount_pending'], 2); ?> ج.م</h3>
                        </div>
                        <div class="text-danger" style="font-size: 2.5rem;">
                            <i class="bi bi-currency-exchange"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card border-success">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">معتمد اليوم</h6>
                            <h3 class="mb-0 text-success"><?php echo number_format($stats['total_amount_approved_today'], 2); ?> ج.م</h3>
                        </div>
                        <div class="text-success" style="font-size: 2.5rem;">
                            <i class="bi bi-cash-coin"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Pending Return Requests Section -->
    <div class="row mb-4" id="pending-returns">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        طلبات المرتجعات المعلقة (<?php echo $totalPendingCount; ?>)
                    </h5>
                    <span class="badge bg-light text-dark">يتطلب مراجعة</span>
                </div>
                <div class="card-body">
                    <?php if (empty($pendingReturns)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>لا توجد طلبات مرتجعات معلقة حالياً
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 120px;">رقم المرتجع</th>
                                        <th>النوع</th>
                                        <th>العميل</th>
                                        <th>المندوب</th>
                                        <th>المبلغ</th>
                                        <th>رصيد العميل</th>
                                        <th>المنتجات</th>
                                        <th style="width: 120px;">تاريخ الطلب</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($allPendingReturns as $return): ?>
                                        <tr>
                                            <td>
                                                <strong class="text-primary"><?php echo htmlspecialchars($return['return_number']); ?></strong>
                                            </td>
                                            <td>
                                                <?php if ($return['return_type'] === 'local'): ?>
                                                    <span class="badge bg-success">
                                                        <i class="bi bi-shop me-1"></i>عميل محلي
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-primary">
                                                        <i class="bi bi-person-badge me-1"></i>مندوب
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($return['customer_name'] ?? 'غير معروف'); ?></strong>
                                                    <?php if (!empty($return['invoice_number'])): ?>
                                                        <br><small class="text-muted">فاتورة: <?php echo htmlspecialchars($return['invoice_number']); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($return['return_type'] === 'local'): ?>
                                                    <span class="badge bg-secondary">-</span>
                                                <?php else: ?>
                                                    <span class="badge bg-info">
                                                        <i class="bi bi-person me-1"></i>
                                                        <?php echo htmlspecialchars($return['sales_rep_name'] ?? 'غير معروف'); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong class="text-primary fs-5">
                                                    <?php echo number_format((float)$return['refund_amount'], 2); ?> ج.م
                                                </strong>
                                            </td>
                                            <td>
                                                <?php if ($return['customer_debt'] > 0): ?>
                                                    <span class="badge bg-danger">
                                                        <i class="bi bi-exclamation-circle me-1"></i>
                                                        دين: <?php echo number_format($return['customer_debt'], 2); ?> ج.م
                                                    </span>
                                                <?php elseif ($return['customer_credit'] > 0): ?>
                                                    <span class="badge bg-success">
                                                        <i class="bi bi-check-circle me-1"></i>
                                                        رصيد: <?php echo number_format($return['customer_credit'], 2); ?> ج.م
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">صفر</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="small">
                                                    <?php 
                                                    $itemCount = count($return['items']);
                                                    $displayedItems = array_slice($return['items'], 0, 2);
                                                    foreach ($displayedItems as $item): 
                                                    ?>
                                                        <div class="mb-1">
                                                            <span class="badge bg-light text-dark">
                                                                <?php echo htmlspecialchars($item['product_name'] ?? 'غير معروف'); ?>
                                                                (<?php echo number_format((float)$item['quantity'], 2); ?>)
                                                                <?php if (!empty($item['batch_number'])): ?>
                                                                    <br><small class="text-muted">تشغيلة: <?php echo htmlspecialchars($item['batch_number']); ?></small>
                                                                <?php endif; ?>
                                                            </span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                    <?php if ($itemCount > 2): ?>
                                                        <small class="text-muted">+ <?php echo ($itemCount - 2); ?> منتج آخر</small>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <small><?php echo date('Y-m-d H:i', strtotime($return['request_date'])); ?></small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <?php if ($totalPendingPages > 1): ?>
                            <nav aria-label="Page navigation" class="mt-3">
                                <ul class="pagination justify-content-center">
                                    <li class="page-item <?php echo $pendingPageNum <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=returns&pending_p=<?php echo $pendingPageNum - 1; ?>#pending-returns">
                                            <i class="bi bi-chevron-right"></i>
                                        </a>
                                    </li>
                                    
                                    <?php
                                    $startPage = max(1, $pendingPageNum - 2);
                                    $endPage = min($totalPendingPages, $pendingPageNum + 2);
                                    
                                    if ($startPage > 1): ?>
                                        <li class="page-item"><a class="page-link" href="?page=returns&pending_p=1#pending-returns">1</a></li>
                                        <?php if ($startPage > 2): ?>
                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                        <li class="page-item <?php echo $i == $pendingPageNum ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=returns&pending_p=<?php echo $i; ?>#pending-returns"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <?php if ($endPage < $totalPendingPages): ?>
                                        <?php if ($endPage < $totalPendingPages - 1): ?>
                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                        <?php endif; ?>
                                        <li class="page-item"><a class="page-link" href="?page=returns&pending_p=<?php echo $totalPendingPages; ?>#pending-returns"><?php echo $totalPendingPages; ?></a></li>
                                    <?php endif; ?>
                                    
                                    <li class="page-item <?php echo $pendingPageNum >= $totalPendingPages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=returns&pending_p=<?php echo $pendingPageNum + 1; ?>#pending-returns">
                                            <i class="bi bi-chevron-left"></i>
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Latest Return Operations Section -->
    <div class="row" id="latest-returns">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-clock-history me-2"></i>
                        آخر عمليات المرتجعات (<?php echo $totalLatestCount; ?>)
                    </h5>
                    <span class="badge bg-light text-dark">سجل العمليات</span>
                </div>
                <div class="card-body">
                    <?php if (empty($latestReturns)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>لا توجد عمليات مرتجعات سابقة
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 120px;">رقم المرتجع</th>
                                        <th>النوع</th>
                                        <th>العميل</th>
                                        <th>المندوب</th>
                                        <th>المبلغ</th>
                                        <th>الحالة</th>
                                        <th>المعتمد بواسطة</th>
                                        <th>المنتجات</th>
                                        <th style="width: 120px;">التاريخ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($allLatestReturns as $return): ?>
                                        <?php
                                        $statusClass = '';
                                        $statusText = '';
                                        $statusIcon = '';
                                        switch ($return['status']) {
                                            case 'approved':
                                                $statusClass = 'success';
                                                $statusText = 'معتمد';
                                                $statusIcon = 'check-circle';
                                                break;
                                            case 'rejected':
                                                $statusClass = 'danger';
                                                $statusText = 'مرفوض';
                                                $statusIcon = 'x-circle';
                                                break;
                                            case 'processed':
                                            case 'completed':
                                                $statusClass = 'info';
                                                $statusText = 'مكتمل';
                                                $statusIcon = 'check-all';
                                                break;
                                            default:
                                                $statusClass = 'secondary';
                                                $statusText = $return['status'];
                                                $statusIcon = 'question-circle';
                                        }
                                        $actionDate = $return['approved_at'] ?? $return['updated_at'] ?? $return['created_at'];
                                        ?>
                                        <tr>
                                            <td>
                                                <strong class="text-primary"><?php echo htmlspecialchars($return['return_number']); ?></strong>
                                            </td>
                                            <td>
                                                <?php if ($return['return_type'] === 'local'): ?>
                                                    <span class="badge bg-success">
                                                        <i class="bi bi-shop me-1"></i>عميل محلي
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-primary">
                                                        <i class="bi bi-person-badge me-1"></i>مندوب
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($return['customer_name'] ?? 'غير معروف'); ?></strong>
                                                    <?php if (!empty($return['invoice_number'])): ?>
                                                        <br><small class="text-muted">فاتورة: <?php echo htmlspecialchars($return['invoice_number']); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($return['return_type'] === 'local'): ?>
                                                    <span class="badge bg-secondary">-</span>
                                                <?php else: ?>
                                                    <span class="badge bg-info">
                                                        <i class="bi bi-person me-1"></i>
                                                        <?php echo htmlspecialchars($return['sales_rep_name'] ?? 'غير معروف'); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong class="text-primary">
                                                    <?php echo number_format((float)$return['refund_amount'], 2); ?> ج.م
                                                </strong>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $statusClass; ?>">
                                                    <i class="bi bi-<?php echo $statusIcon; ?> me-1"></i>
                                                    <?php echo $statusText; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (!empty($return['approved_by_name'])): ?>
                                                    <small><?php echo htmlspecialchars($return['approved_by_name']); ?></small>
                                                <?php else: ?>
                                                    <small class="text-muted">-</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="small">
                                                    <?php 
                                                    $itemCount = count($return['items']);
                                                    $displayedItems = array_slice($return['items'], 0, 2);
                                                    foreach ($displayedItems as $item): 
                                                    ?>
                                                        <div class="mb-1">
                                                            <span class="badge bg-light text-dark">
                                                                <?php echo htmlspecialchars($item['product_name'] ?? 'غير معروف'); ?>
                                                                (<?php echo number_format((float)$item['quantity'], 2); ?>)
                                                            </span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                    <?php if ($itemCount > 2): ?>
                                                        <small class="text-muted">+ <?php echo ($itemCount - 2); ?> منتج آخر</small>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <small><?php echo date('Y-m-d H:i', strtotime($actionDate)); ?></small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <?php if ($totalLatestPages > 1): ?>
                            <nav aria-label="Page navigation" class="mt-3">
                                <ul class="pagination justify-content-center">
                                    <li class="page-item <?php echo $latestPageNum <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=returns&latest_p=<?php echo $latestPageNum - 1; ?>#latest-returns">
                                            <i class="bi bi-chevron-right"></i>
                                        </a>
                                    </li>
                                    
                                    <?php
                                    $startPage = max(1, $latestPageNum - 2);
                                    $endPage = min($totalLatestPages, $latestPageNum + 2);
                                    
                                    if ($startPage > 1): ?>
                                        <li class="page-item"><a class="page-link" href="?page=returns&latest_p=1#latest-returns">1</a></li>
                                        <?php if ($startPage > 2): ?>
                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                        <li class="page-item <?php echo $i == $latestPageNum ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=returns&latest_p=<?php echo $i; ?>#latest-returns"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <?php if ($endPage < $totalLatestPages): ?>
                                        <?php if ($endPage < $totalLatestPages - 1): ?>
                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                        <?php endif; ?>
                                        <li class="page-item"><a class="page-link" href="?page=returns&latest_p=<?php echo $totalLatestPages; ?>#latest-returns"><?php echo $totalLatestPages; ?></a></li>
                                    <?php endif; ?>
                                    
                                    <li class="page-item <?php echo $latestPageNum >= $totalLatestPages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=returns&latest_p=<?php echo $latestPageNum + 1; ?>#latest-returns">
                                            <i class="bi bi-chevron-left"></i>
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<!-- Return Details Modal -->
<div class="modal fade" id="returnDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تفاصيل طلب المرتجع</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="returnDetailsContent">
                <div class="text-center">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">جاري التحميل...</span>
                    </div>
            </div>
        </div>
    </div>
</div>

<script>
const basePath = '<?php echo $basePath; ?>';

function approveReturn(returnId, event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    
    if (!confirm('هل أنت متأكد من الموافقة على طلب المرتجع؟')) {
        return;
    }
    
    const btn = event ? event.target.closest('button') : null;
    const originalHTML = btn ? btn.innerHTML : '';
    
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري المعالجة...';
    }
    
    fetch(basePath + '/api/returns.php?action=approve', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            return_id: returnId,
            notes: ''
        })
    })
    .then(response => {
        console.log('Response Status:', response.status);
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            return response.text().then(text => {
                console.error('Expected JSON but got:', contentType, text.substring(0, 500));
                throw new Error('استجابة غير صحيحة من الخادم');
            });
        }
        if (!response.ok) {
            return response.json().then(errorData => {
                throw new Error(errorData.message || 'خطأ في الطلب: ' + response.status);
            }).catch(() => {
                throw new Error('حدث خطأ في الطلب: ' + response.status);
            });
        }
        return response.json();
    })
    .then(data => {
        console.log('Response Data:', data);
        if (data.success) {
            let successMsg = '✅ تمت الموافقة على طلب المرتجع بنجاح!\n\n';
            if (data.financial_note) {
                successMsg += '📊 التفاصيل المالية:\n' + data.financial_note + '\n\n';
            }
            if (data.items_returned && data.items_returned > 0) {
                successMsg += '📦 تم إرجاع ' + data.items_returned + ' منتج(ات) إلى مخزن السيارة\n\n';
            }
            if (data.return_number) {
                successMsg += '🔢 رقم المرتجع: ' + data.return_number;
            }
            alert(successMsg);
            location.reload();
        } else {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            }
            alert('خطأ: ' + (data.message || 'حدث خطأ غير معروف'));
        }
    })
    .catch(error => {
        console.error('Error approving return:', error);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        }
        alert('خطأ: ' + (error.message || 'حدث خطأ في الاتصال بالخادم. يرجى المحاولة مرة أخرى.'));
    });
}

function rejectReturn(returnId, event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    
    const notes = prompt('يرجى إدخال سبب الرفض (اختياري):');
    if (notes === null) {
        return; // User cancelled
    }
    
    const btn = event ? event.target.closest('button') : null;
    const originalHTML = btn ? btn.innerHTML : '';
    
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري المعالجة...';
    }
    
    fetch(basePath + '/api/returns.php?action=reject', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            return_id: returnId,
            notes: notes || ''
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('تم رفض الطلب بنجاح');
            location.reload();
        } else {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            }
            alert('خطأ: ' + (data.message || 'حدث خطأ غير معروف'));
        }
    })
    .catch(error => {
        console.error('Error rejecting return:', error);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        }
        alert('حدث خطأ في الاتصال بالخادم. يرجى المحاولة مرة أخرى.');
    });
}

function viewReturnDetails(returnId, type) {
    const modal = new bootstrap.Modal(document.getElementById('returnDetailsModal'));
    const content = document.getElementById('returnDetailsContent');
    
    content.innerHTML = '<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">جاري التحميل...</span></div></div>';
    modal.show();
    
    // Fetch return details
    fetch(basePath + '/api/return_requests.php?action=get_return_details&return_id=' + returnId, {
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.return) {
            const ret = data.return;
            let html = `
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>رقم المرتجع:</strong> ${ret.return_number || '-'}
                    </div>
                    <div class="col-md-6">
                        <strong>التاريخ:</strong> ${ret.return_date || '-'}
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>العميل:</strong> ${ret.customer_name || '-'}
                    </div>
                    <div class="col-md-6">
                        <strong>المندوب:</strong> ${ret.sales_rep_name || '-'}
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>المبلغ:</strong> <span class="text-primary">${parseFloat(ret.refund_amount || 0).toFixed(2)} ج.م</span>
                    </div>
                    <div class="col-md-6">
                        <strong>الحالة:</strong> <span class="badge bg-${ret.status === 'approved' ? 'success' : ret.status === 'rejected' ? 'danger' : 'warning'}">${ret.status || '-'}</span>
                    </div>
                </div>
                <hr>
                <h6>المنتجات:</h6>
                <table class="table table-sm table-bordered">
                    <thead>
                        <tr>
                            <th>المنتج</th>
                            <th>الكمية</th>
                            <th>سعر الوحدة</th>
                            <th>الإجمالي</th>
                            <th>رقم التشغيلة</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            if (ret.items && ret.items.length > 0) {
                ret.items.forEach(item => {
                    html += `
                        <tr>
                            <td>${item.product_name || '-'}</td>
                            <td>${parseFloat(item.quantity || 0).toFixed(2)}</td>
                            <td>${parseFloat(item.unit_price || 0).toFixed(2)} ج.م</td>
                            <td>${parseFloat(item.total_price || 0).toFixed(2)} ج.م</td>
                            <td>${item.batch_number || '-'}</td>
                        </tr>
                    `;
                });
            } else {
                html += '<tr><td colspan="5" class="text-center">لا توجد منتجات</td></tr>';
            }
            
            html += `
                    </tbody>
                </table>
            `;
            
            if (ret.notes) {
                html += `<hr><strong>ملاحظات:</strong><p>${ret.notes}</p>`;
            }
            
            content.innerHTML = html;
        } else {
            content.innerHTML = '<div class="alert alert-warning">لا يمكن تحميل تفاصيل المرتجع</div>';
        }
    })
    .catch(error => {
        console.error('Error fetching return details:', error);
        content.innerHTML = '<div class="alert alert-danger">حدث خطأ أثناء تحميل التفاصيل</div>';
    });
}
</script>

