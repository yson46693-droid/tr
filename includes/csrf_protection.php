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
 * محسّنة لدعم إعادة توليد الجلسة (session regeneration)
 */
function verifyCSRFTokenEnhanced($token = null, $allowPreviousToken = true) {
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
    
    // تنظيف token من أي مسافات أو أحرف غير مرئية
    if ($token !== null) {
        $token = trim($token);
    }
    
    // إذا لم يكن هناك token في الطلب، فشل التحقق
    if ($token === null || $token === '') {
        error_log("CSRF: Token missing in request. POST: " . (isset($_POST['csrf_token']) ? 'exists' : 'missing') . ", GET: " . (isset($_GET['csrf_token']) ? 'exists' : 'missing'));
        return false;
    }
    
    // التحقق من وجود token في الجلسة (الحالي أو السابق)
    $currentToken = isset($_SESSION['csrf_token']) ? trim((string)$_SESSION['csrf_token']) : '';
    $previousToken = isset($_SESSION['csrf_token_previous']) ? trim((string)$_SESSION['csrf_token_previous']) : '';
    
    // إذا لم يكن هناك token في الجلسة، إنشاء واحد جديد (للمحاولة الأولى فقط)
    if (empty($currentToken)) {
        // هذا غير طبيعي - يجب أن يكون هناك token دائماً
        error_log("CSRF: No token in session, generating new one");
        if (function_exists('generateCSRFToken')) {
            generateCSRFToken(true);
            $currentToken = isset($_SESSION['csrf_token']) ? trim((string)$_SESSION['csrf_token']) : '';
        } else {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $currentToken = trim((string)$_SESSION['csrf_token']);
        }
        
        // التحقق مع token الجديد
        if (!empty($currentToken) && hash_equals($currentToken, $token)) {
            return true;
        }
        
        // حتى مع token جديد، يجب أن يطابق token الطلب
        return false;
    }
    
    // التحقق من token الحالي أولاً
    if (!empty($currentToken) && hash_equals($currentToken, $token)) {
        return true;
    }
    
    // إذا فشل التحقق مع token الحالي، جرب token السابق (مفيد عند إعادة توليد الجلسة)
    if ($allowPreviousToken && !empty($previousToken)) {
        // التحقق من أن token السابق لم ينته صلاحيته (5 دقائق)
        $previousTokenTime = isset($_SESSION['csrf_token_previous_time']) ? (int)$_SESSION['csrf_token_previous_time'] : 0;
        $tokenAge = time() - $previousTokenTime;
        
        if ($tokenAge <= 300 && hash_equals($previousToken, $token)) { // 300 ثانية = 5 دقائق
            // تم استخدام token سابق - نجح التحقق
            // نحتفظ بالـ token السابق لمدة قصيرة للسماح بالطلبات المتعددة في نفس العملية
            // سيتم حذفه تلقائياً بعد 5 دقائق أو في generateCSRFToken
            error_log("CSRF: Validated using previous token (session regeneration detected, age: {$tokenAge}s)");
            return true;
        } elseif ($tokenAge > 300) {
            // token السابق قديم جداً - حذفه
            unset($_SESSION['csrf_token_previous']);
            unset($_SESSION['csrf_token_previous_time']);
        }
    }
    
    // فشل التحقق - تسجيل معلومات للتشخيص
    $sessionTokenLength = strlen($currentToken);
    $requestTokenLength = strlen($token);
    $tokenMatches = ($currentToken === $token); // للتحقق بدون hash_equals
    
    error_log("CSRF: Token verification failed. Current token length: {$sessionTokenLength}, Request token length: {$requestTokenLength}, Direct match: " . ($tokenMatches ? 'yes' : 'no') . ", Previous token exists: " . (!empty($previousToken) ? 'yes' : 'no'));
    
    return false;
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
 * محسّنة لدعم تسجيل الدخول وإعادة توليد الجلسة
 */
function protectFormFromCSRF() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $isLoginRequest = (
            basename($uri) === 'index.php' || 
            basename($_SERVER['PHP_SELF'] ?? '') === 'index.php' ||
            (isset($_POST['username']) && isset($_POST['password']))
        );
        
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
        // للطلبات الخاصة بتسجيل الدخول، نسمح باستخدام token السابق (لإعادة توليد الجلسة)
        $allowPreviousToken = $isLoginRequest;
        $isValid = verifyCSRFTokenEnhanced(null, $allowPreviousToken);
        
        if (!$isValid) {
            // تسجيل معلومات إضافية للمساعدة في التشخيص
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $hasTokenInPost = isset($_POST['csrf_token']) && !empty($_POST['csrf_token']);
            $hasTokenInSession = isset($_SESSION['csrf_token']) && !empty($_SESSION['csrf_token']);
            $sessionId = session_id();
            
            $logMessage = sprintf(
                "CSRF Validation Failed - IP: %s, UserAgent: %s, HasTokenInPost: %s, HasTokenInSession: %s, SessionID: %s, IsLoginRequest: %s",
                $ipAddress,
                $userAgent,
                ($hasTokenInPost ? 'yes' : 'no'),
                ($hasTokenInSession ? 'yes' : 'no'),
                $sessionId,
                ($isLoginRequest ? 'yes' : 'no')
            );
            
            error_log($logMessage);
            
            http_response_code(403);
            die('خطأ في التحقق الأمني. يرجى تحديث الصفحة والمحاولة مرة أخرى.');
        }
        
        // بعد التحقق الناجح، تنظيف token السابق إذا كان موجوداً (استخدام مرة واحدة)
        if (isset($_SESSION['csrf_token_previous']) && $allowPreviousToken) {
            // نترك token السابق للسماح بمحاولات متعددة في نفس الطلب
            // سيتم حذفه في verifyCSRFTokenEnhanced عند الاستخدام
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
