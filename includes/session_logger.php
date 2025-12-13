<?php
/**
 * Session Logger - تسجيل مفصل لأسباب انتهاء الجلسة
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

/**
 * تسجيل معلومات الجلسة في ملف log
 */
function logSessionInfo($message, $context = []) {
    try {
        $logFile = __DIR__ . '/../logs/session_debug.log';
        $logDir = dirname($logFile);
        
        // إنشاء مجلد logs إذا لم يكن موجوداً
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        
        // إذا فشل إنشاء المجلد، تجاهل التسجيل
        if (!is_dir($logDir) || !is_writable($logDir)) {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $sessionId = session_id() ?? 'NO_SESSION';
        $userId = $_SESSION['user_id'] ?? 'NO_USER';
        $loggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true ? 'YES' : 'NO';
    
    $logEntry = [
        'timestamp' => $timestamp,
        'session_id' => substr($sessionId, 0, 20) . '...',
        'user_id' => $userId,
        'logged_in' => $loggedIn,
        'message' => $message,
        'context' => $context,
        'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
        'script_name' => $_SERVER['SCRIPT_NAME'] ?? 'unknown',
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
        'session_status' => session_status() === PHP_SESSION_ACTIVE ? 'ACTIVE' : 'INACTIVE',
        'last_activity' => $_SESSION['last_activity'] ?? 'NOT_SET',
        'last_activity_previous' => $_SESSION['last_activity_previous'] ?? 'NOT_SET',
        'time_since_activity' => isset($_SESSION['last_activity_previous']) ? (time() - $_SESSION['last_activity_previous']) : 'N/A',
        'session_lifetime' => defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 'NOT_DEFINED',
        'cookie_exists' => isset($_COOKIE[session_name()]) ? 'YES' : 'NO',
        'cookie_session_id' => isset($_COOKIE[session_name()]) ? substr($_COOKIE[session_name()], 0, 20) . '...' : 'NO_COOKIE',
    ];
    
        $logLine = json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n" . str_repeat('=', 80) . "\n";
        
        @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
    } catch (Exception $e) {
        // تجاهل أخطاء التسجيل لتجنب تعطيل النظام
        error_log("Session logger error: " . $e->getMessage());
    }
}

/**
 * تسجيل سبب فشل isLoggedIn()
 */
function logSessionFailure($reason, $details = []) {
    $context = [
        'reason' => $reason,
        'details' => $details,
        'session_data' => [
            'user_id' => $_SESSION['user_id'] ?? null,
            'logged_in' => $_SESSION['logged_in'] ?? null,
            'username' => $_SESSION['username'] ?? null,
            'role' => $_SESSION['role'] ?? null,
        ],
        'cookie_data' => [
            'session_name' => session_name(),
            'session_id_in_cookie' => $_COOKIE[session_name()] ?? null,
            'current_session_id' => session_id(),
            'match' => (isset($_COOKIE[session_name()]) && $_COOKIE[session_name()] === session_id()) ? 'YES' : 'NO',
        ],
    ];
    
    logSessionInfo("SESSION FAILURE: {$reason}", $context);
}

/**
 * تسجيل سبب إرجاع 401 من API
 */
function logApi401($apiPath, $reason, $details = []) {
    $context = [
        'api_path' => $apiPath,
        'reason' => $reason,
        'details' => $details,
        'is_logged_in_result' => function_exists('isLoggedIn') ? (isLoggedIn() ? 'TRUE' : 'FALSE') : 'FUNCTION_NOT_EXISTS',
        'current_user' => function_exists('getCurrentUser') ? (getCurrentUser() ? 'EXISTS' : 'NULL') : 'FUNCTION_NOT_EXISTS',
    ];
    
    logSessionInfo("API 401 ERROR: {$apiPath} - {$reason}", $context);
}
