<?php
/**
 * تقرير تفصيلي لحركات خزنة الشركة
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/path_helper.php';
require_once __DIR__ . '/includes/approval_system.php';

requireRole(['manager', 'accountant']);

$currentUser = getCurrentUser();
$db = db();

// الحصول على الفترة من GET
$dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : date('Y-m-01');
$dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : date('Y-m-d');
$includePending = isset($_GET['include_pending']) && $_GET['include_pending'] == '1';
$groupByType = isset($_GET['group_by_type']) && $_GET['group_by_type'] == '1';

// التحقق من صحة التواريخ
if (!strtotime($dateFrom) || !strtotime($dateTo)) {
    die('تواريخ غير صحيحة');
}

if (strtotime($dateFrom) > strtotime($dateTo)) {
    die('تاريخ البداية يجب أن يكون قبل تاريخ النهاية');
}

// حساب ملخص الخزنة للفترة
$statusFilter = $includePending ? "('approved', 'pending')" : "('approved')";

// إصلاح SQL injection - استخدام prepared statements
$statusPlaceholders = $includePending ? "?, ?" : "?";
$statusParams = $includePending ? ['approved', 'pending'] : ['approved'];

// جلب جميع الحركات المالية من financial_transactions
$financialQuery = "
    SELECT 
        id,
        type,
        amount,
        description,
        reference_number,
        status,
        created_at,
        created_by,
        approved_by,
        approved_at,
        'financial_transactions' as source_table
    FROM financial_transactions
    WHERE DATE(created_at) BETWEEN ? AND ?
    AND status IN ({$statusPlaceholders})
    ORDER BY created_at ASC
";
$financialParams = array_merge([$dateFrom, $dateTo], $statusParams);
$financialTransactions = $db->query($financialQuery, $financialParams) ?: [];

// جلب جميع الحركات من accountant_transactions
$accountantQuery = "
    SELECT 
        id,
        CASE 
            WHEN transaction_type = 'collection_from_sales_rep' THEN 'income'
            WHEN transaction_type = 'expense' THEN 'expense'
            WHEN transaction_type = 'income' THEN 'income'
            WHEN transaction_type = 'transfer' THEN 'transfer'
            WHEN transaction_type = 'payment' THEN 'payment'
            ELSE 'other'
        END as type,
        amount,
        description,
        reference_number,
        status,
        created_at,
        created_by,
        approved_by,
        approved_at,
        transaction_type,
        'accountant_transactions' as source_table
    FROM accountant_transactions
    WHERE DATE(created_at) BETWEEN ? AND ?
    AND status IN ({$statusPlaceholders})
    ORDER BY created_at ASC
";
$accountantParams = array_merge([$dateFrom, $dateTo], $statusParams);
$accountantTransactions = $db->query($accountantQuery, $accountantParams) ?: [];

// دمج الحركات
$allTransactions = [];
foreach ($financialTransactions as $trans) {
    $trans['transaction_type'] = null;
    $allTransactions[] = $trans;
}
foreach ($accountantTransactions as $trans) {
    $allTransactions[] = $trans;
}

// ترتيب حسب التاريخ
usort($allTransactions, function($a, $b) {
    return strtotime($a['created_at']) - strtotime($b['created_at']);
});

// حساب الإجماليات
$totalIncome = 0.0;
$totalExpense = 0.0;
$totalPayment = 0.0;
$totalSalaryAdjustments = 0.0;
$totalCustomerSettlements = 0.0;
$totalCollections = 0.0;

$transactionsByType = [
    'income' => [],
    'expense' => [],
    'payment' => [],
    'transfer' => [],
    'other' => []
];

foreach ($allTransactions as $trans) {
    $type = $trans['type'] ?? 'other';
    $amount = (float)($trans['amount'] ?? 0);
    
    if (!isset($transactionsByType[$type])) {
        $transactionsByType[$type] = [];
    }
    $transactionsByType[$type][] = $trans;
    
    // حساب الإجماليات
    if ($type === 'income') {
        $totalIncome += $amount;
        // التحقق من نوع الإيراد
        if (isset($trans['transaction_type']) && $trans['transaction_type'] === 'collection_from_sales_rep') {
            $totalCollections += $amount;
        }
    } elseif ($type === 'expense') {
        $totalExpense += $amount;
        // التحقق من نوع المصروف
        $description = strtolower($trans['description'] ?? '');
        if (strpos($description, 'تسوية راتب') !== false) {
            $totalSalaryAdjustments += $amount;
        } elseif (strpos($description, 'تسوية رصيد دائن لعميل') !== false || strpos($description, 'تسوية رصيد دائن ل') !== false) {
            $totalCustomerSettlements += $amount;
        }
    } elseif ($type === 'payment') {
        $totalPayment += $amount;
    }
}

$netBalance = $totalIncome - $totalExpense - $totalPayment;

// جلب أسماء المستخدمين
$userIds = [];
foreach ($allTransactions as $trans) {
    if (!empty($trans['created_by'])) $userIds[] = $trans['created_by'];
    if (!empty($trans['approved_by'])) $userIds[] = $trans['approved_by'];
}
$userIds = array_unique($userIds);

$users = [];
if (!empty($userIds)) {
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $usersResult = $db->query("SELECT id, full_name, username FROM users WHERE id IN ($placeholders)", $userIds) ?: [];
    foreach ($usersResult as $user) {
        $users[$user['id']] = $user;
    }
}

// دالة مساعدة للحصول على اسم المستخدم
function getUserName($userId, $users) {
    if (empty($userId) || !isset($users[$userId])) {
        return '-';
    }
    return htmlspecialchars($users[$userId]['full_name'] ?? $users[$userId]['username'] ?? '-', ENT_QUOTES, 'UTF-8');
}

// دالة لتنسيق التاريخ
function formatReportDate($date) {
    return date('Y/m/d', strtotime($date));
}

// دالة لتنسيق التاريخ والوقت
function formatReportDateTime($datetime) {
    return date('Y/m/d H:i', strtotime($datetime));
}

$typeLabels = [
    'income' => 'إيراد',
    'expense' => 'مصروف',
    'payment' => 'دفعة',
    'transfer' => 'تحويل',
    'other' => 'أخرى'
];

$statusLabels = [
    'pending' => 'معلق',
    'approved' => 'معتمد',
    'rejected' => 'مرفوض'
];

$statusColors = [
    'pending' => '#f59e0b',
    'approved' => '#10b981',
    'rejected' => '#ef4444'
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تقرير تفصيلي - خزنة الشركة</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700;900&display=swap');
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Cairo', 'Segoe UI', 'Tajawal', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 25%, #f093fb 50%, #4facfe 75%, #00f2fe 100%);
            background-size: 400% 400%;
            animation: gradientShift 15s ease infinite;
            padding: 20px;
            color: #1a1a1a;
            line-height: 1.6;
            min-height: 100vh;
        }
        
        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        
        .report-container {
            max-width: 1400px;
            margin: 0 auto;
            background: #ffffff;
            padding: 60px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3), 0 0 0 1px rgba(255,255,255,0.1);
            border-radius: 24px;
            position: relative;
            overflow: hidden;
        }
        
        .report-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #667eea, #764ba2, #f093fb, #4facfe, #00f2fe);
            background-size: 200% 100%;
            animation: gradientShift 3s ease infinite;
        }
        
        .report-header {
            text-align: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 50px 40px;
            border-radius: 20px;
            margin-bottom: 50px;
            box-shadow: 0 15px 35px rgba(102, 126, 234, 0.4);
            position: relative;
            overflow: hidden;
        }
        
        .report-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }
        
        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .report-header h1 {
            font-size: 42px;
            font-weight: 900;
            margin-bottom: 20px;
            text-shadow: 0 4px 8px rgba(0,0,0,0.3);
            letter-spacing: 1px;
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }
        
        .report-header h1::before {
            content: '💰';
            font-size: 48px;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));
        }
        
        .report-header .period {
            font-size: 20px;
            opacity: 0.95;
            margin-top: 15px;
            font-weight: 600;
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .report-header .meta-info {
            margin-top: 20px;
            font-size: 15px;
            opacity: 0.9;
            padding-top: 20px;
            border-top: 2px solid rgba(255,255,255,0.3);
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .summary-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 45px;
            border-radius: 20px;
            margin-bottom: 50px;
            box-shadow: 0 15px 35px rgba(102, 126, 234, 0.35);
            position: relative;
            overflow: hidden;
        }
        
        .summary-section::after {
            content: '';
            position: absolute;
            top: -100px;
            right: -100px;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            border-radius: 50%;
        }
        
        .summary-section h2 {
            font-size: 28px;
            font-weight: 900;
            margin-bottom: 30px;
            text-align: center;
            position: relative;
            z-index: 1;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-top: 25px;
            position: relative;
            z-index: 1;
        }
        
        .summary-item {
            background: rgba(255,255,255,0.25);
            padding: 25px;
            border-radius: 16px;
            backdrop-filter: blur(15px);
            border: 2px solid rgba(255,255,255,0.4);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .summary-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s;
        }
        
        .summary-item:hover::before {
            left: 100%;
        }
        
        .summary-item:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            border-color: rgba(255,255,255,0.6);
        }
        
        .summary-item-label {
            font-size: 14px;
            opacity: 0.95;
            margin-bottom: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .summary-item-label::before {
            content: '▸';
            font-size: 12px;
        }
        
        .summary-item-value {
            font-size: 32px;
            font-weight: 900;
            line-height: 1.2;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .section-title {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 22px 30px;
            border-radius: 14px;
            margin: 50px 0 25px 0;
            font-size: 22px;
            font-weight: 900;
            box-shadow: 0 8px 20px rgba(16, 185, 129, 0.3);
            display: flex;
            align-items: center;
            gap: 12px;
            position: relative;
            overflow: hidden;
        }
        
        .section-title::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            animation: shimmer 3s infinite;
        }
        
        @keyframes shimmer {
            0% { left: -100%; }
            100% { left: 100%; }
        }
        
        .transactions-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-bottom: 50px;
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            border: 1px solid #e9ecef;
        }
        
        .transactions-table thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .transactions-table th {
            padding: 18px 16px;
            text-align: right;
            font-weight: 700;
            font-size: 15px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            border-bottom: 3px solid rgba(255,255,255,0.3);
            white-space: nowrap;
            position: relative;
        }
        
        .transactions-table th::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: rgba(255,255,255,0.5);
        }
        
        .transactions-table th:nth-child(1) {
            width: 60px;
        }
        
        .transactions-table th:nth-child(2) {
            width: 160px;
        }
        
        .transactions-table th:nth-child(3) {
            width: 130px;
        }
        
        .transactions-table th:nth-child(4) {
            min-width: 220px;
        }
        
        .transactions-table th:nth-child(5) {
            width: 160px;
        }
        
        .transactions-table th:nth-child(6) {
            width: 110px;
        }
        
        .transactions-table th:nth-child(7),
        .transactions-table th:nth-child(8) {
            width: 130px;
        }
        
        .transactions-table th:first-child {
            border-top-right-radius: 16px;
        }
        
        .transactions-table th:last-child {
            border-top-left-radius: 16px;
        }
        
        .transactions-table td {
            padding: 16px;
            border-bottom: 1px solid #f1f3f5;
            font-size: 14px;
            vertical-align: middle;
            word-wrap: break-word;
            transition: all 0.2s ease;
        }
        
        .transactions-table td:nth-child(4) {
            max-width: 300px;
            word-break: break-word;
        }
        
        .transactions-table tbody tr {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }
        
        .transactions-table tbody tr::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: transparent;
            transition: all 0.3s ease;
        }
        
        .transactions-table tbody tr:nth-child(even) {
            background: #fafbfc;
        }
        
        .transactions-table tbody tr:nth-child(odd) {
            background: #ffffff;
        }
        
        .transactions-table tbody tr:hover {
            background: linear-gradient(90deg, #f0f4ff 0%, #ffffff 100%) !important;
            transform: translateX(-3px);
            box-shadow: -4px 0 12px rgba(102, 126, 234, 0.2);
        }
        
        .transactions-table tbody tr:hover::before {
            background: linear-gradient(180deg, #667eea, #764ba2);
        }
        
        .transactions-table tbody tr:last-child td {
            border-bottom: none;
        }
        
        .transactions-table tbody tr td:first-child {
            font-weight: 700;
            color: #667eea;
            font-size: 15px;
        }
        
        .transactions-table tbody tr.total-row {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            font-weight: 900;
            font-size: 16px;
            border-top: 3px solid #667eea;
        }
        
        .transactions-table tbody tr.total-row:hover {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            transform: none;
            box-shadow: none;
        }
        
        .transactions-table tbody tr.total-row::before {
            display: none;
        }
        
        .type-badge {
            display: inline-flex;
            align-items: center;
            padding: 8px 16px;
            border-radius: 25px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.5px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .type-badge::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255,255,255,0.3);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }
        
        .type-badge:hover::before {
            width: 300px;
            height: 300px;
        }
        
        .type-income {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }
        
        .type-expense {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }
        
        .type-payment {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }
        
        .type-transfer {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
        }
        
        .type-other {
            background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
            color: white;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 7px 14px;
            border-radius: 25px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.5px;
            box-shadow: 0 3px 6px rgba(0,0,0,0.2);
            position: relative;
        }
        
        .status-badge::before {
            content: '●';
            margin-left: 5px;
            font-size: 8px;
        }
        
        .amount {
            font-weight: 800;
            font-size: 16px;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .amount::before {
            content: '';
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: currentColor;
            opacity: 0.6;
        }
        
        .amount-income {
            color: #10b981;
        }
        
        .amount-expense {
            color: #ef4444;
        }
        
        .amount-payment {
            color: #f59e0b;
        }
        
        .amount-transfer {
            color: #3b82f6;
        }
        
        .footer {
            margin-top: 60px;
            padding: 30px;
            border-top: 4px solid #667eea;
            text-align: center;
            color: #6b7280;
            font-size: 14px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        
        .footer p {
            margin: 8px 0;
            font-weight: 600;
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
            }
            
            .report-container {
                box-shadow: none;
                padding: 20px;
                border-radius: 0;
            }
            
            .report-container::before {
                display: none;
            }
            
            .no-print {
                display: none;
            }
            
            .transactions-table {
                page-break-inside: auto;
            }
            
            .transactions-table tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
            
            .summary-section {
                page-break-inside: avoid;
            }
            
            .summary-section::after {
                display: none;
            }
        }
        
        .print-button {
            position: fixed;
            bottom: 30px;
            left: 30px;
            z-index: 1000;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 16px 32px;
            border-radius: 14px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 700;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.5);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            gap: 10px;
            font-family: 'Cairo', sans-serif;
        }
        
        .print-button:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 12px 35px rgba(102, 126, 234, 0.6);
        }
        
        .print-button:active {
            transform: translateY(-1px) scale(1.02);
        }
        
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: #6b7280;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 16px;
            margin: 40px 0;
        }
        
        .empty-state .icon {
            font-size: 100px;
            margin-bottom: 30px;
            opacity: 0.4;
            filter: grayscale(0.3);
        }
        
        .empty-state h3 {
            font-size: 28px;
            margin-bottom: 15px;
            color: #374151;
            font-weight: 900;
        }
        
        .empty-state p {
            font-size: 18px;
            color: #6b7280;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <button class="print-button no-print" onclick="window.print()" title="طباعة التقرير">
        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="currentColor" viewBox="0 0 16 16" style="margin-left: 8px;">
            <path d="M5 1a2 2 0 0 0-2 2v2H2a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1v1a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-1h1a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1V3a2 2 0 0 0-2-2H5zM4 3a1 1 0 0 1 1-1h6a1 1 0 0 1 1v2H4V3zm1 5a2 2 0 0 0-2 2v1H2a1 1 0 0 1-1-1V7a1 1 0 0 1 1h12a1 1 0 0 1 1v3a1 1 0 0 1-1 1h-1v-1a2 2 0 0 0-2-2H5zm7 2v3a1 1 0 0 1-1 1H5a1 1 0 0 1-1v-3a1 1 0 0 1 1h6a1 1 0 0 1 1z"/>
        </svg>
        طباعة التقرير
    </button>
    
    <div class="report-container">
        <div class="report-header">
            <h1>تقرير تفصيلي - خزنة الشركة</h1>
            <div class="period">
                <span>📅</span> الفترة: من <strong><?php echo formatReportDate($dateFrom); ?></strong> إلى <strong><?php echo formatReportDate($dateTo); ?></strong>
            </div>
            <div class="meta-info">
                <span>🕐</span> تاريخ الإنشاء: <strong><?php echo date('Y/m/d H:i'); ?></strong>
                <span style="margin: 0 10px;">|</span>
                <span>👤</span> أنشأه: <strong><?php echo htmlspecialchars($currentUser['full_name'] ?? $currentUser['username'] ?? 'غير معروف', ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
        </div>
        
        <div class="summary-section">
            <h2>📊 ملخص الحركات المالية</h2>
            <div class="summary-grid">
                <div class="summary-item">
                    <div class="summary-item-label">💰 إجمالي الإيرادات</div>
                    <div class="summary-item-value"><?php echo formatCurrency($totalIncome); ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-item-label">💸 إجمالي المصروفات</div>
                    <div class="summary-item-value"><?php echo formatCurrency($totalExpense); ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-item-label">💳 إجمالي المدفوعات</div>
                    <div class="summary-item-value"><?php echo formatCurrency($totalPayment); ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-item-label">⚖️ صافي الرصيد</div>
                    <div class="summary-item-value" style="color: <?php echo $netBalance >= 0 ? '#10b981' : '#ef4444'; ?>">
                        <?php echo formatCurrency($netBalance); ?>
                    </div>
                </div>
                <?php if ($totalCollections > 0): ?>
                <div class="summary-item">
                    <div class="summary-item-label">📥 التحصيلات من المندوبين</div>
                    <div class="summary-item-value"><?php echo formatCurrency($totalCollections); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($totalSalaryAdjustments > 0): ?>
                <div class="summary-item">
                    <div class="summary-item-label">💼 تسويات المرتبات</div>
                    <div class="summary-item-value"><?php echo formatCurrency($totalSalaryAdjustments); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($totalCustomerSettlements > 0): ?>
                <div class="summary-item">
                    <div class="summary-item-label">🤝 تسويات أرصدة العملاء</div>
                    <div class="summary-item-value"><?php echo formatCurrency($totalCustomerSettlements); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if (empty($allTransactions)): ?>
            <div class="empty-state">
                <div class="icon">📊</div>
                <h3>لا توجد حركات مالية</h3>
                <p>لا توجد حركات مالية في الفترة المحددة (من <?php echo formatReportDate($dateFrom); ?> إلى <?php echo formatReportDate($dateTo); ?>)</p>
            </div>
        <?php elseif ($groupByType): ?>
            <?php foreach ($transactionsByType as $type => $transactions): ?>
                <?php if (!empty($transactions)): ?>
                    <div class="section-title">
                        <span>📋</span>
                        <span><?php echo htmlspecialchars($typeLabels[$type] ?? $type, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span style="background: rgba(255,255,255,0.25); padding: 6px 14px; border-radius: 25px; font-size: 14px; margin-right: 10px; font-weight: 700; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                            <?php echo count($transactions); ?> حركة
                        </span>
                    </div>
                    <table class="transactions-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>التاريخ</th>
                                <th>المبلغ</th>
                                <th>الوصف</th>
                                <th>الرقم المرجعي</th>
                                <th>الحالة</th>
                                <th>أنشأه</th>
                                <th>اعتمده</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $typeTotal = 0.0;
                            foreach ($transactions as $index => $trans): 
                                $typeTotal += (float)($trans['amount'] ?? 0);
                            ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td>
                                        <span style="font-weight: 600; color: #374151;">
                                            <?php echo formatReportDateTime($trans['created_at']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="amount amount-<?php echo $type; ?>">
                                            <?php echo ($type === 'income' ? '+' : '-'); ?><?php echo formatCurrency($trans['amount']); ?>
                                        </span>
                                    </td>
                                    <td style="line-height: 1.6;">
                                        <span style="display: inline-block; padding: 4px 0;">
                                            <?php echo htmlspecialchars($trans['description'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                    <td>
                                <?php if (!empty($trans['reference_number'])): ?>
                                    <span style="font-family: 'Courier New', monospace; font-size: 12px; color: #667eea; background: linear-gradient(135deg, #f0f4ff 0%, #e9ecff 100%); padding: 6px 12px; border-radius: 8px; font-weight: 600; border: 1px solid rgba(102, 126, 234, 0.2); display: inline-block;">
                                        <?php echo htmlspecialchars($trans['reference_number'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #9ca3af; font-style: italic;">-</span>
                                <?php endif; ?>
                            </td>
                                    <td>
                                        <span class="status-badge" style="background: <?php echo $statusColors[$trans['status']] ?? '#6b7280'; ?>; color: white;">
                                            <?php echo htmlspecialchars($statusLabels[$trans['status']] ?? $trans['status'], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="font-weight: 600; color: #4b5563;">
                                            <?php echo getUserName($trans['created_by'], $users); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="font-weight: 600; color: #4b5563;">
                                            <?php echo getUserName($trans['approved_by'], $users); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="total-row">
                                <td colspan="2" style="font-size: 15px;">
                                    <strong>الإجمالي</strong>
                                </td>
                                <td>
                                    <span class="amount amount-<?php echo $type; ?>" style="font-size: 16px;">
                                        <?php echo ($type === 'income' ? '+' : '-'); ?><?php echo formatCurrency($typeTotal); ?>
                                    </span>
                                </td>
                                <td colspan="5"></td>
                            </tr>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="section-title">
                <span>📋</span>
                <span>جميع الحركات المالية</span>
                <span style="background: rgba(255,255,255,0.25); padding: 6px 14px; border-radius: 25px; font-size: 14px; margin-right: 10px; font-weight: 700; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <?php echo count($allTransactions); ?> حركة
                </span>
            </div>
            <table class="transactions-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>التاريخ</th>
                        <th>النوع</th>
                        <th>المبلغ</th>
                        <th>الوصف</th>
                        <th>الرقم المرجعي</th>
                        <th>الحالة</th>
                        <th>أنشأه</th>
                        <th>اعتمده</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allTransactions as $index => $trans): ?>
                        <?php $type = $trans['type'] ?? 'other'; ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td>
                                <span style="font-weight: 600; color: #374151;">
                                    <?php echo formatReportDateTime($trans['created_at']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="type-badge type-<?php echo $type; ?>">
                                    <?php echo htmlspecialchars($typeLabels[$type] ?? $type, ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td>
                                <span class="amount amount-<?php echo $type; ?>">
                                    <?php echo ($type === 'income' ? '+' : '-'); ?><?php echo formatCurrency($trans['amount']); ?>
                                </span>
                            </td>
                            <td style="max-width: 300px; word-wrap: break-word; line-height: 1.6;">
                                <span style="display: inline-block; padding: 4px 0;">
                                    <?php echo htmlspecialchars($trans['description'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($trans['reference_number'])): ?>
                                    <span style="font-family: 'Courier New', monospace; font-size: 12px; color: #667eea; background: linear-gradient(135deg, #f0f4ff 0%, #e9ecff 100%); padding: 6px 12px; border-radius: 8px; font-weight: 600; border: 1px solid rgba(102, 126, 234, 0.2); display: inline-block;">
                                        <?php echo htmlspecialchars($trans['reference_number'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #9ca3af; font-style: italic;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge" style="background: <?php echo $statusColors[$trans['status']] ?? '#6b7280'; ?>; color: white;">
                                    <?php echo htmlspecialchars($statusLabels[$trans['status']] ?? $trans['status'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td>
                                <span style="font-weight: 600; color: #4b5563;">
                                    <?php echo getUserName($trans['created_by'], $users); ?>
                                </span>
                            </td>
                            <td>
                                <span style="font-weight: 600; color: #4b5563;">
                                    <?php echo getUserName($trans['approved_by'], $users); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        
        <div class="footer">
            <p style="font-size: 15px; margin-bottom: 10px;">✨ تم إنشاء هذا التقرير تلقائياً من نظام إدارة خزنة الشركة</p>
            <p style="font-size: 13px; opacity: 0.8;">© <?php echo date('Y'); ?> - جميع الحقوق محفوظة</p>
        </div>
    </div>
    
    <script>
        // طباعة تلقائية عند فتح الصفحة (اختياري)
        // window.onload = function() {
        //     window.print();
        // }
    </script>
</body>
</html>
