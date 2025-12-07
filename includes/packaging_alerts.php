<?php
/**
 * تنبيهات أدوات التعبئة اليومية عبر Telegram
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/simple_telegram.php';
require_once __DIR__ . '/path_helper.php';

const PACKAGING_ALERT_JOB_KEY = 'packaging_low_stock_alert';
const PACKAGING_ALERT_STATUS_SETTING_KEY = 'packaging_alert_status';
const PACKAGING_ALERT_THRESHOLD = 20;

if (!function_exists('packagingReportFileMatchesDate')) {
    function packagingReportFileMatchesDate(string $path, string $targetDate): bool
    {
        if (!is_file($path)) {
            return false;
        }
        return date('Y-m-d', (int)filemtime($path)) === $targetDate;
    }
}

/**
 * تجهيز جدول تتبع المهام اليومية
 */
function packagingAlertEnsureJobTable(): void {
    static $tableReady = false;

    if ($tableReady) {
        return;
    }

    try {
        $db = db();
        $db->execute("
            CREATE TABLE IF NOT EXISTS `system_daily_jobs` (
              `job_key` varchar(120) NOT NULL,
              `last_sent_at` datetime DEFAULT NULL,
              `last_file_path` varchar(512) DEFAULT NULL,
              `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`job_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $tableError) {
        error_log('Packaging alert table error: ' . $tableError->getMessage());
        return;
    }

    $tableReady = true;
}

/**
 * حذف ملفات التقارير القديمة والاحتفاظ بآخر تقرير فقط.
 */
function packagingAlertCleanupOldReports(string $reportsDir, string $currentFilename): void {
    $pattern = rtrim($reportsDir, '/\\') . '/packaging-low-stock-*.html';
    $files = glob($pattern) ?: [];

    foreach ($files as $file) {
        if (!is_string($file)) {
            continue;
        }
        if (basename($file) === $currentFilename) {
            continue;
        }
        @unlink($file);
    }
}

/**
 * حفظ حالة تقرير أدوات التعبئة في system_settings.
 *
 * @param array<string, mixed> $data
 */
function packagingAlertSaveStatus(array $data): void {
    try {
        $db = db();
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $db->execute(
            "INSERT INTO system_settings (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)",
            [PACKAGING_ALERT_STATUS_SETTING_KEY, $json]
        );
    } catch (Throwable $saveError) {
        error_log('Packaging alert status save error: ' . $saveError->getMessage());
    }
}

/**
 * معالجة التنبيه اليومي لأدوات التعبئة منخفضة الكمية
 */
function processDailyPackagingAlert(): void {
    // لا يتم التنفيذ في سطر الأوامر أو في حالة تعطيله صراحةً
    if (PHP_SAPI === 'cli' || defined('SKIP_PACKAGING_ALERT')) {
        return;
    }

    static $processed = false;

    if ($processed) {
        return;
    }

    $processed = true;

    packagingAlertEnsureJobTable();

    $db = db();

    $jobState = null;
    try {
        $jobState = $db->queryOne(
            "SELECT last_sent_at FROM system_daily_jobs WHERE job_key = ? LIMIT 1",
            [PACKAGING_ALERT_JOB_KEY]
        );
    } catch (Throwable $stateError) {
        error_log('Packaging alert state error: ' . $stateError->getMessage());
        return;
    }

    $today = date('Y-m-d');
    $existingData = [];
    $existingReportPath = null;
    $existingReportRelative = null;
    $existingViewerPath = null;
    $existingAccessToken = null;
    try {
        $existingRow = $db->queryOne(
            "SELECT value FROM system_settings WHERE `key` = ? LIMIT 1",
            [PACKAGING_ALERT_STATUS_SETTING_KEY]
        );
        if ($existingRow && isset($existingRow['value'])) {
            $decoded = json_decode((string)$existingRow['value'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $existingData = $decoded;
            }
        }
    } catch (Throwable $statusError) {
        error_log('Packaging alert status fetch error: ' . $statusError->getMessage());
    }

    $reportsBase = rtrim(defined('REPORTS_PRIVATE_PATH') ? REPORTS_PRIVATE_PATH : REPORTS_PATH, '/\\');

    if (!empty($existingData['report_path']) && ($existingData['date'] ?? null) === $today) {
        $candidate = $reportsBase . '/' . ltrim((string)$existingData['report_path'], '/\\');
        if (packagingReportFileMatchesDate($candidate, $today)) {
            $existingReportPath = $candidate;
            $existingReportRelative = ltrim((string)$existingData['report_path'], '/\\');
            $existingViewerPath = (string)($existingData['viewer_path'] ?? '');
            $existingAccessToken = (string)($existingData['access_token'] ?? '');
        }
    }

    if (($existingData['date'] ?? null) === $today) {
        $existingStatus = $existingData['status'] ?? null;
        if (
            in_array($existingStatus, ['completed', 'completed_no_issues'], true) &&
            $existingReportPath !== null
        ) {
            $alreadyData = $existingData;
            $alreadyData['status'] = 'already_sent';
            $alreadyData['checked_at'] = date('Y-m-d H:i:s');
            packagingAlertSaveStatus($alreadyData);
            return;
        }

        if ($existingStatus === 'running') {
            $startedAt = isset($existingData['started_at']) ? strtotime((string)$existingData['started_at']) : 0;
            if ($startedAt && (time() - $startedAt) < 600) {
                return;
            }
        }
    }

    $jobRelativePath = (string)($jobState['last_file_path'] ?? '');
    $jobReportPath = null;
    if ($jobRelativePath !== '') {
        $candidate = $reportsBase . '/' . ltrim($jobRelativePath, '/\\');
        if (packagingReportFileMatchesDate($candidate, $today)) {
            $jobReportPath = $candidate;
        }
    }

    if (!empty($jobState['last_sent_at'])) {
        $lastSentDate = substr((string)$jobState['last_sent_at'], 0, 10);
        if (
            $lastSentDate === $today &&
            ($existingReportPath !== null || $jobReportPath !== null)
        ) {
            $alreadyData = !empty($existingData) ? $existingData : [
                'date' => $today,
                'status' => 'already_sent',
            ];
            $alreadyData['status'] = 'already_sent';
            $alreadyData['checked_at'] = date('Y-m-d H:i:s');
            $alreadyData['last_sent_at'] = $jobState['last_sent_at'];
            if ($existingReportRelative !== null) {
                $alreadyData['report_path'] = $existingReportRelative;
            } elseif ($jobRelativePath !== '') {
                $alreadyData['report_path'] = $jobRelativePath;
            }
            if ($existingViewerPath !== null) {
                $alreadyData['viewer_path'] = $existingViewerPath;
            }
            if ($existingAccessToken !== null) {
                $alreadyData['access_token'] = $existingAccessToken;
            }
            packagingAlertSaveStatus($alreadyData);
            return;
        }
    }

    $statusData = [
        'date' => $today,
        'status' => 'running',
        'started_at' => date('Y-m-d H:i:s'),
    ];
    packagingAlertSaveStatus($statusData);

    try {
        $lowStockItems = $db->query(
            "SELECT name, type, quantity, unit 
             FROM packaging_materials 
             WHERE status = 'active' 
               AND quantity IS NOT NULL 
               AND quantity < ? 
             ORDER BY quantity ASC, name ASC",
            [PACKAGING_ALERT_THRESHOLD]
        );
    } catch (Throwable $queryError) {
        error_log('Packaging alert query error: ' . $queryError->getMessage());
        $statusData['status'] = 'failed';
        $statusData['error'] = 'تعذّر جلب بيانات أدوات التعبئة منخفضة الكمية.';
        $statusData['completed_at'] = date('Y-m-d H:i:s');
        packagingAlertSaveStatus($statusData);
        return;
    }

    if (empty($lowStockItems)) {
        $statusData['status'] = 'completed_no_issues';
        $statusData['completed_at'] = date('Y-m-d H:i:s');
        $statusData['counts'] = [
            'total_items' => 0,
            'by_type' => [],
        ];
        packagingAlertSaveStatus($statusData);
        return;
    }

    $totalItems = count($lowStockItems);
    $typeBreakdown = [];
    foreach ($lowStockItems as $item) {
        $typeKey = trim((string)($item['type'] ?? 'غير محدد'));
        if ($typeKey === '') {
            $typeKey = 'غير محدد';
        }
        $typeBreakdown[$typeKey] = ($typeBreakdown[$typeKey] ?? 0) + 1;
    }

    $reportFilePath = null;
    $relativePath = null;
    $viewerPath = null;
    $accessToken = null;
    $absoluteReportUrl = null;
    $absolutePrintUrl = null;

    if ($existingReportPath !== null) {
        $reportFilePath = $existingReportPath;
        $relativePath = $existingReportRelative;
        $viewerPath = $existingViewerPath;
        $accessToken = $existingAccessToken;
    }

    if ($reportFilePath === null) {
        $reportFilePath = packagingAlertGenerateReport($lowStockItems);
        if ($reportFilePath !== null) {
            $reportsBase = rtrim(defined('REPORTS_PRIVATE_PATH') ? REPORTS_PRIVATE_PATH : REPORTS_PATH, '/\\');
            if (strpos($reportFilePath, $reportsBase) === 0) {
                $relativePath = ltrim(substr($reportFilePath, strlen($reportsBase)), '/\\');
            } else {
                $relativePath = basename($reportFilePath);
            }
        }
    }

    if ($reportFilePath === null || $relativePath === null) {
        $statusData['status'] = 'failed';
        $statusData['error'] = 'فشل إنشاء ملف HTML لتقرير أدوات التعبئة.';
        $statusData['completed_at'] = date('Y-m-d H:i:s');
        packagingAlertSaveStatus($statusData);
        return;
    }

    if (empty($accessToken)) {
        try {
            $accessToken = bin2hex(random_bytes(16));
        } catch (Throwable $tokenError) {
            $accessToken = sha1($relativePath . microtime(true) . mt_rand());
            error_log('Packaging alert: random_bytes failed, fallback token used - ' . $tokenError->getMessage());
        }
    }

    if (empty($viewerPath) || strpos($viewerPath, 'reports/view.php') !== 0) {
        $viewerPath = 'reports/view.php?' . http_build_query(
            [
                'type' => 'packaging',
                'token' => $accessToken,
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );
    }

    $reportUrl = getRelativeUrl($viewerPath);
    $printUrl = $reportUrl . (strpos($reportUrl, '?') === false ? '?print=1' : '&print=1');
    $absoluteReportUrl = getAbsoluteUrl($viewerPath);
    $absolutePrintUrl = $absoluteReportUrl . (strpos($absoluteReportUrl, '?') === false ? '?print=1' : '&print=1');

    if (!isTelegramConfigured()) {
        $statusData = [
            'date' => $today,
            'status' => 'failed',
            'completed_at' => date('Y-m-d H:i:s'),
            'counts' => [
                'total_items' => $totalItems,
                'by_type' => $typeBreakdown,
            ],
            'report_path' => $relativePath,
            'viewer_path' => $viewerPath,
            'access_token' => $accessToken,
            'report_url' => $reportUrl,
            'print_url' => $printUrl,
            'absolute_report_url' => $absoluteReportUrl,
            'absolute_print_url' => $absolutePrintUrl,
            'error' => 'إعدادات Telegram غير مكتملة',
        ];
        packagingAlertSaveStatus($statusData);
        return;
    }

    $summaryLines = [];
    foreach ($typeBreakdown as $typeLabel => $count) {
        $summaryLines[] = '• ' . htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') . ': ' . intval($count);
    }
    if (empty($summaryLines)) {
        $summaryLines[] = '• لا توجد أصناف محددة حسب النوع.';
    }

    $previewItems = [];

    $message = "📦 <b>تقرير أدوات التعبئة منخفضة الكمية</b>\n";
    $message .= 'التاريخ: ' . date('Y-m-d H:i:s') . "\n";
    $message .= 'الحد الأدنى للتنبيه: أقل من ' . PACKAGING_ALERT_THRESHOLD . " قطعة\n\n";
    $message .= '<b>عدد العناصر المنخفضة:</b> ' . $totalItems . "\n";
    $message .= "<b>ملخص حسب النوع:</b>\n" . implode("\n", $summaryLines);
    $message .= "\n\n✅ التقرير محفوظ في النظام ويمكن طباعته أو حفظه من خلال الروابط التالية.";

    $buttons = [
        [
            ['text' => 'عرض التقرير', 'url' => $absoluteReportUrl],
            ['text' => 'طباعة / حفظ PDF', 'url' => $absolutePrintUrl],
        ],
    ];

    $sendResult = sendTelegramMessageWithButtons($message, $buttons);
    if (empty($sendResult['success'])) {
        $statusData = [
            'date' => $today,
            'status' => 'failed',
            'completed_at' => date('Y-m-d H:i:s'),
            'counts' => [
                'total_items' => $totalItems,
                'by_type' => $typeBreakdown,
            ],
            'preview' => $previewItems,
            'report_path' => $relativePath,
            'viewer_path' => $viewerPath,
            'access_token' => $accessToken,
            'report_url' => $reportUrl,
            'print_url' => $printUrl,
            'absolute_report_url' => $absoluteReportUrl,
            'absolute_print_url' => $absolutePrintUrl,
            'error' => 'فشل إرسال رابط التقرير إلى Telegram' . (!empty($sendResult['error']) ? ' (' . $sendResult['error'] . ')' : ''),
        ];
        packagingAlertSaveStatus($statusData);
        return;
    }

    try {
        if ($jobState) {
            $db->execute(
                "UPDATE system_daily_jobs SET last_sent_at = NOW(), last_file_path = NULL, updated_at = NOW() WHERE job_key = ?",
                [PACKAGING_ALERT_JOB_KEY]
            );
        } else {
            $db->execute(
                "INSERT INTO system_daily_jobs (job_key, last_sent_at, last_file_path) VALUES (?, NOW(), NULL)",
                [PACKAGING_ALERT_JOB_KEY]
            );
        }
    } catch (Throwable $updateError) {
        error_log('Packaging alert update error: ' . $updateError->getMessage());
    }

    if (function_exists('createNotificationForRole')) {
        try {
            createNotificationForRole(
                'manager',
                'تقرير المخازن اليومي',
                'تم إرسال تقرير أدوات التعبئة منخفضة الكمية إلى قناة Telegram.',
                'info',
                $reportUrl
            );
        } catch (Throwable $notificationError) {
            error_log('Packaging alert notification error: ' . $notificationError->getMessage());
        }
    }

    $finalData = [
        'date' => $today,
        'status' => 'completed',
        'completed_at' => date('Y-m-d H:i:s'),
        'counts' => [
            'total_items' => $totalItems,
            'by_type' => $typeBreakdown,
        ],
        'preview' => $previewItems,
        'report_path' => $relativePath,
        'viewer_path' => $viewerPath,
        'access_token' => $accessToken,
        'report_url' => $reportUrl,
        'print_url' => $printUrl,
        'absolute_report_url' => $absoluteReportUrl,
        'absolute_print_url' => $absolutePrintUrl,
        'file_deleted' => false,
    ];

    packagingAlertSaveStatus($finalData);
}

/**
 * إنشاء ملف HTML لتقرير أدوات التعبئة منخفضة الكمية.
 *
 * @param array<int, array<string, mixed>> $items
 * @return string|null
 */
function packagingAlertGenerateReport(array $items): ?string {
    $baseReportsPath = defined('REPORTS_PRIVATE_PATH')
        ? REPORTS_PRIVATE_PATH
        : (defined('REPORTS_PATH') ? REPORTS_PATH : (dirname(__DIR__) . '/reports/'));
    $reportsDir = rtrim($baseReportsPath, '/\\') . '/alerts';
    if (!is_dir($reportsDir)) {
        @mkdir($reportsDir, 0755, true);
    }
    if (!is_dir($reportsDir) || !is_writable($reportsDir)) {
        error_log('Packaging alert reports directory not writable: ' . $reportsDir);
        return null;
    }

    $filename = sprintf('packaging-low-stock-%s.html', date('Ymd-His'));
    $filePath = $reportsDir . DIRECTORY_SEPARATOR . $filename;

    $title = 'تقرير أدوات التعبئة منخفضة الكمية';
    $timestamp = date('Y-m-d H:i');
    $thresholdLine = 'الحد الأدنى للتنبيه: أقل من ' . PACKAGING_ALERT_THRESHOLD . ' قطعة';

    $totalItems = count($items);
    $typeBreakdown = [];

    foreach ($items as $item) {
        $typeKey = trim((string)($item['type'] ?? 'غير محدد'));
        if ($typeKey === '') {
            $typeKey = 'غير محدد';
        }
        $typeBreakdown[$typeKey] = ($typeBreakdown[$typeKey] ?? 0) + 1;
    }

    $typeSummary = '';
    if (!empty($typeBreakdown)) {
        foreach ($typeBreakdown as $typeLabel => $count) {
            $typeSummary .= '<li><span class="label">' . htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8')
                . '</span><span class="value">' . intval($count) . '</span></li>';
        }
    }

    $rowsHtml = '';
    foreach ($items as $item) {
        $name = htmlspecialchars(trim((string)($item['name'] ?? 'غير محدد')), ENT_QUOTES, 'UTF-8');
        $type = htmlspecialchars(trim((string)($item['type'] ?? 'غير محدد')), ENT_QUOTES, 'UTF-8');
        $unit = htmlspecialchars(trim((string)($item['unit'] ?? 'قطعة')), ENT_QUOTES, 'UTF-8');
        $quantityRaw = $item['quantity'];

        if (is_numeric($quantityRaw)) {
            $quantity = rtrim(rtrim(number_format((float)$quantityRaw, 3, '.', ''), '0'), '.');
        } else {
            $quantity = (string)$quantityRaw;
        }

        $rowsHtml .= '<tr><td>' . $name . '</td><td>' . $type . '</td><td>' . htmlspecialchars($quantity, ENT_QUOTES, 'UTF-8') . ' ' . $unit . '</td></tr>';
    }

    if ($rowsHtml === '') {
        $rowsHtml = '<tr><td colspan="3" class="empty">لا توجد عناصر منخفضة حالياً.</td></tr>';
    }

    $styles = '
        @page { margin: 18mm 15mm; }
        body { font-family: "Amiri", "Cairo", "Segoe UI", Tahoma, sans-serif; direction: rtl; text-align: right; margin:0; background:#f8fafc; color:#0f172a; }
        .report-wrapper { padding: 32px; background:#ffffff; border-radius:16px; box-shadow:0 12px 40px rgba(15,23,42,0.08); }
        .actions { display:flex; flex-direction:column; gap:8px; align-items:flex-start; margin-bottom:20px; }
        .actions button { background:#1d4ed8; color:#fff; border:none; padding:10px 18px; border-radius:10px; font-size:15px; cursor:pointer; transition:opacity 0.2s ease; }
        .actions button:hover { opacity:0.9; }
        .actions .hint { font-size:13px; color:#475569; }
        header { text-align:center; margin-bottom:24px; }
        header h1 { margin:0; font-size:26px; color:#1d4ed8; }
        header .meta { display:flex; justify-content:center; gap:16px; flex-wrap:wrap; margin-top:12px; color:#475569; font-size:14px; }
        header .meta span { background:#e2e8f0; padding:6px 14px; border-radius:999px; }
        .summary { background:#1d4ed8; color:#ffffff; padding:18px 24px; border-radius:14px; margin-bottom:28px; }
        .summary h2 { margin:0 0 12px; font-size:18px; }
        .summary ul { list-style:none; margin:0; padding:0; display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:12px; }
        .summary li { display:flex; justify-content:space-between; align-items:center; background:rgba(255,255,255,0.15); padding:12px 16px; border-radius:12px; font-size:15px; }
        .summary .label { font-weight:600; }
        .summary .value { font-size:17px; font-weight:700; }
        .threshold { margin-bottom:24px; padding:16px; background:#f1f5f9; border-radius:14px; color:#1e293b; font-size:15px; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:12px 16px; border:1px solid #e2e8f0; font-size:14px; }
        th { background:#1d4ed8; color:#ffffff; font-size:15px; }
        tr:nth-child(even) td { background:#f8fafc; }
        .empty { text-align:center; padding:36px 0; color:#64748b; font-style:italic; font-size:15px; }
        @media print { .actions { display:none !important; } body { background:#ffffff; } }
    ';

    $fontHint = '<!-- لإضافة خط عربي من Google Fonts، يمكنك استخدام الرابط التالي (أزل التعليق إذا لزم الأمر):
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&display=swap">
-->';

    $summarySection = '';
    if (!empty($typeSummary)) {
        $summarySection = '<section class="summary"><h2>ملخص حسب النوع</h2><ul>' . $typeSummary . '</ul></section>';
    }

    $body = '<div class="report-wrapper">'
        . '<div class="actions">'
        . '<button id="printReportButton" type="button">طباعة / حفظ كـ PDF</button>'
        . '<span class="hint">يمكنك أيضاً استخدام الرابط المرسل عبر Telegram لعرض التقرير وطباعته.</span>'
        . '</div>'
        . '<header><h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<div class="meta"><span>' . htmlspecialchars(COMPANY_NAME, ENT_QUOTES, 'UTF-8') . '</span>'
        . '<span>' . htmlspecialchars($timestamp, ENT_QUOTES, 'UTF-8') . '</span>'
        . '<span>عدد العناصر المنخفضة: ' . $totalItems . '</span>'
        . '</div></header>'
        . $summarySection
        . '<div class="threshold">' . htmlspecialchars($thresholdLine, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<table><thead><tr><th>اسم الأداة</th><th>النوع</th><th>الكمية الحالية</th></tr></thead><tbody>'
        . $rowsHtml . '</tbody></table></div>';

    $printScript = '<script>(function(){function triggerPrint(){window.print();}'
        . 'document.addEventListener("DOMContentLoaded",function(){var btn=document.getElementById("printReportButton");'
        . 'if(btn){btn.addEventListener("click",function(e){e.preventDefault();triggerPrint();});}'
        . 'var params=new URLSearchParams(window.location.search);'
        . 'if(params.get("print")==="1"){setTimeout(triggerPrint,700);}'
        . '});})();</script>';

    $document = '<!DOCTYPE html><html lang="ar"><head><meta charset="utf-8"><title>'
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title><meta name="viewport" content="width=device-width, initial-scale=1">'
        . $fontHint . '<style>' . $styles . '</style></head><body>' . $body . $printScript . '</body></html>';

    if (@file_put_contents($filePath, $document) === false) {
        error_log('Packaging alert: unable to write HTML report to ' . $filePath);
        return null;
    }

    packagingAlertCleanupOldReports($reportsDir, $filename);

    return $filePath;
}


