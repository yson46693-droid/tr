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
        
        // تسجيل في error_log أولاً (fallback) - دائماً
        $errorLogMessage = "SESSION DEBUG [{$timestamp}]: {$message}";
        if (!empty($context)) {
            $errorLogMessage .= " | Context: " . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        $errorLogMessage .= " | User ID: {$userId} | Logged In: {$loggedIn} | Session ID: " . substr($sessionId, 0, 20) . '...';
        error_log($errorLogMessage);
        
        // محاولة التسجيل في الملف
        $logFile = __DIR__ . '/../logs/session_debug.log';
        $logDir = dirname($logFile);
        
        // إنشاء مجلد logs إذا لم يكن موجوداً
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        
        // محاولة الكتابة في الملف
        if (is_dir($logDir)) {
            // محاولة الكتابة مباشرة
            $result = @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
            
            // إذا فشلت الكتابة، حاول بدون LOCK_EX
            if ($result === false) {
                @file_put_contents($logFile, $logLine, FILE_APPEND);
            }
            
            // إذا فشلت مرة أخرى، حاول في مجلد tmp
            if ($result === false) {
                $tmpLogFile = sys_get_temp_dir() . '/session_debug_' . date('Y-m-d') . '.log';
                @file_put_contents($tmpLogFile, $logLine, FILE_APPEND);
                error_log("Session log written to temp file: {$tmpLogFile}");
            }
        } else {
            // إذا لم نستطع إنشاء المجلد، استخدم temp directory
            $tmpLogFile = sys_get_temp_dir() . '/session_debug_' . date('Y-m-d') . '.log';
            @file_put_contents($tmpLogFile, $logLine, FILE_APPEND);
            error_log("Session log written to temp file (logs dir not writable): {$tmpLogFile}");
        }
    } catch (Exception $e) {
        // تسجيل الخطأ في error_log
        error_log("Session logger error: " . $e->getMessage() . " - Message: {$message}");
        error_log("Session logger stack trace: " . $e->getTraceAsString());
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
