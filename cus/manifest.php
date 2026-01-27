<?php
/**
 * Serve manifest.json with correct Content-Type
 * This file ensures the manifest is served without BOM or encoding issues
 */

// إزالة أي output buffer قديم
while (ob_get_level()) {
    ob_end_clean();
}

// التأكد من عدم وجود أي output قبل headers
if (ob_get_level() === 0) {
    ob_start();
}

// قراءة ملف manifest.json مباشرة
$manifestPath = __DIR__ . '/manifest.json';
if (!file_exists($manifestPath)) {
    ob_clean();
    http_response_code(404);
    header('Content-Type: application/manifest+json; charset=utf-8');
    echo json_encode(['error' => 'Manifest not found'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit(0);
}

$content = @file_get_contents($manifestPath);
if ($content === false) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/manifest+json; charset=utf-8');
    echo json_encode(['error' => 'Failed to read manifest'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit(0);
}

// إزالة BOM
$content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
$content = trim($content);

// التحقق من أن المحتوى JSON صحيح
$json = @json_decode($content, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/manifest+json; charset=utf-8');
    echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit(0);
}

// تصحيح المسارات في manifest.json
$scriptPath = dirname($_SERVER['SCRIPT_NAME']);
$basePath = '';
if ($scriptPath !== '/' && $scriptPath !== '' && $scriptPath !== '.') {
    $basePath = rtrim($scriptPath, '/');
}

// تحديث المسارات في JSON
if ($json && isset($json['icons'])) {
    foreach ($json['icons'] as &$icon) {
        if (isset($icon['src'])) {
            if (strpos($icon['src'], '/') !== 0 && strpos($icon['src'], 'http') !== 0) {
                $icon['src'] = $basePath . '/' . $icon['src'];
            }
        }
    }
    unset($icon);
}

// تحديث shortcuts
if (isset($json['shortcuts']) && is_array($json['shortcuts'])) {
    foreach ($json['shortcuts'] as &$shortcut) {
        if (isset($shortcut['icons']) && is_array($shortcut['icons'])) {
            foreach ($shortcut['icons'] as &$icon) {
                if (isset($icon['src']) && strpos($icon['src'], '/') !== 0 && strpos($icon['src'], 'http') !== 0) {
                    $icon['src'] = $basePath . '/' . $icon['src'];
                }
            }
            unset($icon);
        }
        if (isset($shortcut['url']) && strpos($shortcut['url'], '/') !== 0 && strpos($shortcut['url'], 'http') !== 0) {
            $shortcut['url'] = $basePath . '/' . $shortcut['url'];
        }
    }
    unset($shortcut);
}

// تحديث id و start_url و scope
if (isset($json['id'])) {
    if (strpos($json['id'], '/') !== 0) {
        $json['id'] = $basePath . '/' . $json['id'];
    }
    if (substr($json['id'], -1) !== '/') {
        $json['id'] .= '/';
    }
}
if (isset($json['start_url'])) {
    if (strpos($json['start_url'], '/') !== 0) {
        $json['start_url'] = $basePath . '/' . $json['start_url'];
    }
}
if (isset($json['scope'])) {
    if (strpos($json['scope'], '/') !== 0) {
        $json['scope'] = $basePath . '/' . $json['scope'];
    }
}

// تحويل JSON مرة أخرى
$content = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// تنظيف output buffer قبل إرسال headers
ob_clean();

// إرسال headers
if (!headers_sent()) {
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: public, max-age=604800, stale-while-revalidate=86400');
    header('X-Content-Type-Options: nosniff');
    $etag = md5($content);
    header('ETag: "' . $etag . '"');
    
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === '"' . $etag . '"') {
        http_response_code(304);
        exit(0);
    }
}

// إرسال المحتوى
echo $content;
ob_end_flush();
exit(0);