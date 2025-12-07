<?php
/**
 * CSRF Protection (InfinityFree Compatible)
 * حماية CSRF - مدمجة مع النظام الحالي
 * 
 * هذا الملف يحسّن حماية CSRF ويتكامل مع generateCSRFToken() الموجود في auth.php
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/**
 * تحسين دالة التحقق من CSRF - متوافقة مع النظام الحالي
 * تستخدم نفس النظام الموجود في auth.php
 */
function verifyCSRFTokenEnhanced($token = null) {
    // التأكد من أن الجلسة نشطة
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    
    if (!isset($_SESSION)) {
        return false;
    }
    
    // إذا لم يتم تمرير token، احصل عليه من POST أو GET
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? null;
    }
    
    // إذا لم يكن هناك token في الطلب، فشل التحقق
    if ($token === null || $token === '') {
        error_log("CSRF: Token missing in request. POST: " . (isset($_POST['csrf_token']) ? 'exists' : 'missing') . ", GET: " . (isset($_GET['csrf_token']) ? 'exists' : 'missing'));
        return false;
    }
    
    // إذا لم يكن هناك token في الجلسة، إنشاء واحد جديد (للمحاولة الأولى)
    if (!isset($_SESSION['csrf_token']) || empty($_SESSION['csrf_token'])) {
        error_log("CSRF: No token in session, generating new one");
        if (function_exists('generateCSRFToken')) {
            generateCSRFToken(true);
        } else {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        // في المحاولة الأولى، نسمح بالمرور فقط إذا كان token فارغاً (للمستخدمين الجدد)
        // لكن إذا كان هناك token في الطلب، يجب أن يطابق token الجلسة الجديد
        if ($token !== null && $token !== '') {
            // إذا كان هناك token في الطلب، يجب أن يطابق token الجلسة الجديد
            return hash_equals($_SESSION['csrf_token'], $token);
        }
        return true;
    }
    
    // استخدام الدالة الموجودة في auth.php إذا كانت متاحة
    if (function_exists('verifyCSRFToken')) {
        $result = verifyCSRFToken($token);
        if (!$result) {
            error_log("CSRF: Token verification failed. Session token exists: " . (isset($_SESSION['csrf_token']) ? 'yes' : 'no'));
        }
        return $result;
    }
    
    // Fallback: التحقق المباشر
    $result = hash_equals($_SESSION['csrf_token'], $token);
    if (!$result) {
        error_log("CSRF: Token mismatch. Session token length: " . strlen($_SESSION['csrf_token'] ?? '') . ", Request token length: " . strlen($token ?? ''));
    }
    return $result;
}

/**
 * الحصول على CSRF Token - متوافق مع النظام الحالي
 */
function getCSRFToken() {
    // التأكد من أن الجلسة نشطة
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (!headers_sent()) {
            session_start();
        } else {
            return '';
        }
    }
    
    if (!isset($_SESSION)) {
        return '';
    }
    
    // استخدام الدالة الموجودة في auth.php إذا كانت متاحة
    if (function_exists('generateCSRFToken')) {
        return generateCSRFToken();
    }
    
    // Fallback: إنشاء token جديد
    if (!isset($_SESSION['csrf_token']) || empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    
    return $_SESSION['csrf_token'];
}

/**
 * إنشاء حقل CSRF Token للنماذج
 */
function csrf_token_field() {
    $token = getCSRFToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * حماية النموذج من CSRF - متوافقة مع النظام الحالي
 */
function protectFormFromCSRF() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        
        // استثناءات (APIs و WebAuthn)
        if (strpos($uri, '/api/') !== false || 
            strpos($uri, '/webauthn/') !== false ||
            (isset($_POST['login_method']) && $_POST['login_method'] === 'webauthn')) {
            return true;
        }
        
        // التحقق من أن الجلسة نشطة
        if (session_status() !== PHP_SESSION_ACTIVE) {
            error_log("CSRF: Session not active");
            // محاولة بدء الجلسة
            if (!headers_sent()) {
                session_start();
            }
        }
        
        // التأكد من وجود CSRF token في الجلسة قبل التحقق
        if (!isset($_SESSION['csrf_token']) || empty($_SESSION['csrf_token'])) {
            // إنشاء token جديد إذا لم يكن موجوداً
            if (function_exists('generateCSRFToken')) {
                generateCSRFToken(true);
            } else {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }
            error_log("CSRF: Generated new token for session");
        }
        
        // التحقق من CSRF Token
        $isValid = verifyCSRFTokenEnhanced();
        
        if (!$isValid) {
            // تسجيل معلومات إضافية للمساعدة في التشخيص
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $hasTokenInPost = isset($_POST['csrf_token']) && !empty($_POST['csrf_token']);
            $hasTokenInSession = isset($_SESSION['csrf_token']) && !empty($_SESSION['csrf_token']);
            
            error_log("CSRF Validation Failed - IP: {$ipAddress}, UserAgent: {$userAgent}, HasTokenInPost: " . ($hasTokenInPost ? 'yes' : 'no') . ", HasTokenInSession: " . ($hasTokenInSession ? 'yes' : 'no'));
            
            http_response_code(403);
            die('خطأ في التحقق الأمني. يرجى تحديث الصفحة والمحاولة مرة أخرى.');
        }
    }
    
    return true;
}

/**
 * دالة مساعدة للحصول على CSRF Token (للاستخدام في JavaScript)
 */
function csrf_token() {
    return getCSRFToken();
}
