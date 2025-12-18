<?php
/**
 * صفحة البروفايل الشخصي للمستخدم
 */

define('ACCESS_ALLOWED', true);
// تعريف ثابت لمنع حذف الجلسة في profile.php
define('PROFILE_PAGE_ACTIVE', true);

// التأكد من بدء الجلسة قبل أي شيء
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/path_helper.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit_log.php';

// التحقق من تسجيل الدخول فقط
// التأكد من أن الجلسة نشطة قبل التحقق
if (session_status() !== PHP_SESSION_ACTIVE) {
    if (!headers_sent()) {
        @session_start();
    }
}

// === تحديث/إنشاء الجلسة في قاعدة البيانات قبل requireLogin() ===
// هذا يضمن أن الجلسة موجودة في قاعدة البيانات قبل التحقق منها
// هذا مهم لجميع المستخدمين (ليس فقط المدير) لضمان عمل الجلسة بشكل صحيح
try {
    // التأكد من أن الجلسة نشطة
    if (session_status() === PHP_SESSION_NONE) {
        if (!headers_sent()) {
            @session_start();
        }
    }
    
    // التحقق من وجود بيانات الجلسة الأساسية
    if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
        $userId = $_SESSION['user_id'];
        $sessionId = session_id();
        
        if (!empty($sessionId) && !empty($userId)) {
            if (function_exists('ensureSessionsTable') && ensureSessionsTable()) {
                try {
                    $db = db();
                    $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (3600 * 24 * 7);
                    $newExpiresAt = date('Y-m-d H:i:s', time() + $sessionLifetime);
                    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
                    
                    // محاولة تحديث الجلسة الموجودة أولاً
                    $sessionUpdated = $db->execute(
                        "UPDATE sessions SET last_activity = NOW(), expires_at = ? WHERE user_id = ? AND session_id = ?",
                        [$newExpiresAt, $userId, $sessionId]
                    );
                    
                    // إذا لم توجد جلسة (لم يتم تحديث أي صف)، إنشاء واحدة جديدة
                    if (!$sessionUpdated || ($sessionUpdated['affected_rows'] ?? 0) === 0) {
                        try {
                            $db->execute(
                                "INSERT INTO sessions (user_id, session_id, ip_address, user_agent, expires_at, last_activity) 
                                 VALUES (?, ?, ?, ?, ?, NOW())
                                 ON DUPLICATE KEY UPDATE last_activity = NOW(), expires_at = ?, user_id = ?",
                                [$userId, $sessionId, $ipAddress, $userAgent, $newExpiresAt, $newExpiresAt, $userId]
                            );
                            error_log("Profile page - Created new session in database for user_id: {$userId}");
                        } catch (Exception $insertError) {
                            // إذا فشل INSERT بسبب duplicate key، جرب UPDATE مرة أخرى
                            try {
                                $db->execute(
                                    "UPDATE sessions SET last_activity = NOW(), expires_at = ?, user_id = ? WHERE session_id = ?",
                                    [$newExpiresAt, $userId, $sessionId]
                                );
                                error_log("Profile page - Updated existing session by session_id for user_id: {$userId}");
                            } catch (Exception $updateError) {
                                error_log("Profile page - Session insert/update failed: " . $insertError->getMessage() . " | Update error: " . $updateError->getMessage());
                            }
                        }
                    } else {
                        // تم تحديث الجلسة بنجاح
                        error_log("Profile page - Updated existing session for user_id: {$userId}");
                    }
                    
                    // التأكد من أن $_SESSION['logged_in'] مضبوط بشكل صحيح
                    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
                        $_SESSION['logged_in'] = true;
                    }
                    
                } catch (Exception $dbError) {
                    // خطأ في قاعدة البيانات - نسجل الخطأ لكن لا نوقف العملية
                    error_log("Profile page load - Database error while updating session: " . $dbError->getMessage());
                }
            }
        } else {
            error_log("Profile page - Missing session_id or user_id. session_id: " . (empty($sessionId) ? 'empty' : 'set') . ", user_id: " . (empty($userId) ? 'empty' : $userId));
        }
    } else {
        error_log("Profile page - Missing user_id in session. Session data: " . json_encode(array_keys($_SESSION ?? [])));
    }
} catch (Exception $e) {
    // لا نوقف العملية إذا فشل تحديث الجلسة، فقط نسجل الخطأ
    error_log("Profile page load - Error updating session in database: " . $e->getMessage());
}

requireLogin();

// تهيئة متغيرات الرسائل
$error = $_SESSION['error_message'] ?? '';
$success = $_SESSION['success_message'] ?? '';
unset($_SESSION['error_message'], $_SESSION['success_message']);

// === تحميل بيانات المستخدم - حل نهائي محسّن ===
$currentUser = null;
$user = null;
$userId = null;

// التحقق 1: من $_SESSION مباشرة
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    $userId = $_SESSION['user_id'];
}

// التحقق 2: إذا لم يكن موجوداً في $_SESSION، جرب البحث في قاعدة البيانات
if (!$userId || empty($userId)) {
    try {
        $sessionId = session_id();
        if (!empty($sessionId) && function_exists('ensureSessionsTable') && ensureSessionsTable()) {
            $db = db();
            // البحث عن الجلسة حتى لو كانت منتهية الصلاحية (لإعطاء فرصة للتجديد)
            $sessionRecord = $db->queryOne(
                "SELECT user_id, expires_at FROM sessions WHERE session_id = ? ORDER BY last_activity DESC LIMIT 1",
                [$sessionId]
            );
            if ($sessionRecord && isset($sessionRecord['user_id'])) {
                $foundUserId = $sessionRecord['user_id'];
                // التحقق من أن الجلسة لم تنتهِ أو تجديدها إذا كانت قريبة من الانتهاء
                $expiresAt = $sessionRecord['expires_at'];
                $isExpired = strtotime($expiresAt) < time();
                
                // إذا كانت الجلسة منتهية لكنها حديثة (أقل من ساعة)، نجددها
                if ($isExpired && (time() - strtotime($expiresAt)) < 3600) {
                    $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (3600 * 24 * 7);
                    $newExpiresAt = date('Y-m-d H:i:s', time() + $sessionLifetime);
                    $db->execute(
                        "UPDATE sessions SET expires_at = ?, last_activity = NOW() WHERE session_id = ?",
                        [$newExpiresAt, $sessionId]
                    );
                    $expiresAt = $newExpiresAt;
                }
                
                // إذا كانت الجلسة صالحة (أو تم تجديدها)، نستخدم user_id
                if (strtotime($expiresAt) > time()) {
                    $userId = $foundUserId;
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['logged_in'] = true;
                }
            }
        }
    } catch (Exception $e) {
        error_log("Profile page - Session lookup from database failed: " . $e->getMessage());
    }
}

// التحقق 3: إذا لم يكن موجوداً، جرب getCurrentUser()
if (!$userId || empty($userId)) {
    try {
        $currentUser = getCurrentUser();
        if ($currentUser && isset($currentUser['id']) && !empty($currentUser['id'])) {
            $userId = $currentUser['id'];
            $_SESSION['user_id'] = $userId;
            if (!isset($_SESSION['username']) || $_SESSION['username'] !== $currentUser['username']) {
                $_SESSION['username'] = $currentUser['username'];
            }
            if (!isset($_SESSION['role']) || $_SESSION['role'] !== $currentUser['role']) {
                $_SESSION['role'] = $currentUser['role'];
            }
            $_SESSION['logged_in'] = true;
        }
    } catch (Exception $e) {
        error_log("Profile page - getCurrentUser() failed: " . $e->getMessage());
    }
}

// إذا لم نجد user_id بعد كل المحاولات
if (!$userId || empty($userId)) {
    error_log("Profile page - Missing user_id after all attempts. Session data: " . json_encode(array_keys($_SESSION ?? [])));
    $error = 'تعذر تحميل بيانات المستخدم. يرجى تسجيل الدخول مرة أخرى.';
} else {
    // الآن نحاول تحميل بيانات المستخدم الكاملة
    // الطريقة 1: محاولة استخدام getCurrentUser() أولاً
    try {
        $currentUser = getCurrentUser();
        if ($currentUser && isset($currentUser['id']) && !empty($currentUser['id'])) {
            $user = $currentUser;
        }
    } catch (Exception $e) {
        error_log("Profile page - getCurrentUser() failed: " . $e->getMessage());
    }
    
    // الطريقة 2: إذا فشلت، جرب getUserById()
    if (!$user || !isset($user['id'])) {
        try {
            $user = getUserById($userId);
            if ($user && isset($user['id'])) {
                $currentUser = $user;
                // تحديث الجلسة ببيانات المستخدم
                if (!isset($_SESSION['username']) || $_SESSION['username'] !== $user['username']) {
                    $_SESSION['username'] = $user['username'];
                }
                if (!isset($_SESSION['role']) || $_SESSION['role'] !== $user['role']) {
                    $_SESSION['role'] = $user['role'];
                }
                if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
                    $_SESSION['logged_in'] = true;
                }
            }
        } catch (Exception $e) {
            error_log("Profile page - getUserById() failed: " . $e->getMessage());
        }
    }
    
    // الطريقة 3: إذا فشلت، جرب مباشرة من قاعدة البيانات
    if (!$user || !isset($user['id'])) {
        try {
            if (!isset($db)) {
                $db = db();
            }
            // لا نتحقق من status هنا لأن getUserById() و getCurrentUser() لا يتحققان منه أيضاً
            // في profile.php نريد عرض بيانات المستخدم حتى لو كان status غير active
            $userFromDb = $db->queryOne("SELECT * FROM users WHERE id = ?", [$userId]);
            if ($userFromDb && isset($userFromDb['id'])) {
                $user = $userFromDb;
                $currentUser = $userFromDb;
                // تحديث الجلسة ببيانات المستخدم
                if (!isset($_SESSION['username']) || $_SESSION['username'] !== $userFromDb['username']) {
                    $_SESSION['username'] = $userFromDb['username'];
                }
                if (!isset($_SESSION['role']) || $_SESSION['role'] !== $userFromDb['role']) {
                    $_SESSION['role'] = $userFromDb['role'];
                }
                if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
                    $_SESSION['logged_in'] = true;
                }
            } else {
                // المستخدم غير موجود في قاعدة البيانات
                error_log("Profile page - User ID {$userId} not found in database");
            }
        } catch (Exception $e) {
            error_log("Profile page - Direct database query failed: " . $e->getMessage());
        }
    }
    
    // إذا فشلت جميع المحاولات
    if (!$user || !isset($user['id']) || empty($user['id'])) {
        error_log("Profile page - Failed to load user. user_id: " . $userId . ", Session data: " . json_encode(array_keys($_SESSION ?? [])));
        // لا نضيف رسالة خطأ هنا إذا كانت موجودة بالفعل من السطر 189
        if (empty($error)) {
            $error = 'تعذر تحميل بيانات المستخدم. يرجى المحاولة مرة أخرى أو تحديث الصفحة.';
        }
    }
}

$db = db();
$passwordMinLength = getPasswordMinLength();

$profilePhotoSupported = false;
try {
    $columnCheck = $db->queryOne("SHOW COLUMNS FROM users LIKE 'profile_photo'");
    if (!empty($columnCheck)) {
        $profilePhotoSupported = true;
    }
} catch (Exception $e) {
    $profilePhotoSupported = false;
}

// === التأكد من تحميل بيانات المستخدم الكاملة ===
// إذا كان $user محمّل بالفعل من الكود السابق، لا حاجة لإعادة التحميل
// لكن نتحقق من أن البيانات محدثة
if ($user && isset($user['id']) && !empty($user['id'])) {
    // البيانات محمّلة بالفعل - لا حاجة لإعادة التحميل
    // فقط نتحقق من أن $currentUser محدث
    if (!$currentUser || !isset($currentUser['id']) || $currentUser['id'] != $user['id']) {
        $currentUser = $user;
    }
} elseif ($currentUser && isset($currentUser['id']) && !empty($currentUser['id'])) {
    // إذا كان $currentUser موجود لكن $user غير موجود، استخدم $currentUser
    $user = $currentUser;
} else {
    // محاولة أخيرة: تحميل مباشر من قاعدة البيانات
    if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
        try {
            if (!isset($db)) {
                $db = db();
            }
            $userFromDb = $db->queryOne("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);
            if ($userFromDb && isset($userFromDb['id'])) {
                $user = $userFromDb;
                $currentUser = $userFromDb;
                // تحديث الجلسة
                if (!isset($_SESSION['username']) || $_SESSION['username'] !== $userFromDb['username']) {
                    $_SESSION['username'] = $userFromDb['username'];
                }
                if (!isset($_SESSION['role']) || $_SESSION['role'] !== $userFromDb['role']) {
                    $_SESSION['role'] = $userFromDb['role'];
                }
                if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
                    $_SESSION['logged_in'] = true;
                }
            }
        } catch (Exception $e) {
            error_log("Profile page - Final user load attempt failed: " . $e->getMessage());
        }
    }
}

// معالجة تحديث البروفايل
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
        if ($action === 'update_profile') {
        // === إعادة تحميل بيانات المستخدم قبل المعالجة ===
        if (!$user || !is_array($user) || !isset($user['id']) || empty($user['id'])) {
            $userId = $_SESSION['user_id'] ?? null;
            
            if ($userId) {
                // محاولة 1: استخدام getUserById()
                try {
                    $user = getUserById($userId);
                    if ($user && isset($user['id'])) {
                        $currentUser = $user;
                    }
                } catch (Exception $e) {
                    error_log("Profile update - getUserById() failed: " . $e->getMessage());
                }
                
                // محاولة 2: تحميل مباشر من قاعدة البيانات
                if (!$user || !isset($user['id'])) {
                    try {
                        if (!isset($db)) {
                            $db = db();
                        }
                        $userFromDb = $db->queryOne("SELECT * FROM users WHERE id = ?", [$userId]);
                        if ($userFromDb && isset($userFromDb['id'])) {
                            $user = $userFromDb;
                            $currentUser = $userFromDb;
                            // تحديث الجلسة
                            if (!isset($_SESSION['username']) || $_SESSION['username'] !== $userFromDb['username']) {
                                $_SESSION['username'] = $userFromDb['username'];
                            }
                            if (!isset($_SESSION['role']) || $_SESSION['role'] !== $userFromDb['role']) {
                                $_SESSION['role'] = $userFromDb['role'];
                            }
                            if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
                                $_SESSION['logged_in'] = true;
                            }
                        }
                    } catch (Exception $e) {
                        error_log("Profile update - Direct database query failed: " . $e->getMessage());
                    }
                }
            }
        }
        
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $removePhoto = false;
        $profilePhotoData = null;

        if ($profilePhotoSupported) {
            $removePhoto = isset($_POST['remove_photo']) && $_POST['remove_photo'] === '1';

            if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                    $maxSize = 2 * 1024 * 1024;
                    if ($_FILES['profile_photo']['size'] > $maxSize) {
                        $error = 'حجم الصورة يجب ألا يتجاوز 2 ميجابايت';
                    } else {
                        $tmpPath = $_FILES['profile_photo']['tmp_name'];
                        $mimeType = '';
                        if (function_exists('mime_content_type')) {
                            $mimeType = mime_content_type($tmpPath);
                        }
                        if (!$mimeType && class_exists('finfo')) {
                            $mode = defined('FILEINFO_MIME_TYPE') ? FILEINFO_MIME_TYPE : 0;
                            $finfo = finfo_open($mode);
                            if ($finfo) {
                                $mimeType = finfo_file($finfo, $tmpPath);
                                finfo_close($finfo);
                            }
                        }
                        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                        if (!$mimeType || !in_array($mimeType, $allowedTypes, true)) {
                            $error = 'نوع الصورة غير مدعوم';
                        } else {
                            $imageData = file_get_contents($tmpPath);
                            if ($imageData === false) {
                                $error = 'تعذر قراءة الصورة';
                            } else {
                                $profilePhotoData = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
                                $removePhoto = false;
                            }
                        }
                    }
                } else {
                    $error = 'فشل رفع الصورة';
                }
            }
        }
        
        // التحقق من البيانات
        if (empty($fullName)) {
            $error = 'يجب إدخال الاسم الكامل';
        } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'البريد الإلكتروني غير صحيح';
        } elseif (!$user || !is_array($user) || !isset($user['id'])) {
            $error = 'تعذر تحميل بيانات المستخدم. يرجى المحاولة مرة أخرى.';
        } else {
            // التحقق من البريد الإلكتروني إذا تغير
            if ($user && is_array($user) && isset($user['email']) && isset($user['id']) && $email !== $user['email']) {
                $existingUser = getUserByUsername($email);
                $userId = ($currentUser && isset($currentUser['id'])) ? $currentUser['id'] : ($user && isset($user['id']) ? $user['id'] : ($_SESSION['user_id'] ?? null));
                if ($existingUser && isset($existingUser['id']) && $existingUser['id'] != $userId) {
                    $error = 'البريد الإلكتروني مستخدم بالفعل';
                }
            }
            
            // تحديث البيانات الأساسية
            if (empty($error)) {
                // الحصول على user_id من $currentUser أو $user أو session
                $userId = null;
                if ($currentUser && isset($currentUser['id'])) {
                    $userId = $currentUser['id'];
                } elseif ($user && isset($user['id'])) {
                    $userId = $user['id'];
                } elseif (isset($_SESSION['user_id'])) {
                    $userId = $_SESSION['user_id'];
                }
                
                if (!$userId) {
                    $error = 'تعذر تحديد معرف المستخدم';
                } else {
                    $updateFields = "full_name = ?, email = ?, phone = ?, updated_at = NOW()";
                    $params = [$fullName, $email, $phone];
                    if ($profilePhotoSupported && $profilePhotoData !== null) {
                        $updateFields .= ", profile_photo = ?";
                        $params[] = $profilePhotoData;
                    } elseif ($profilePhotoSupported && $removePhoto) {
                        $updateFields .= ", profile_photo = NULL";
                    }
                    $params[] = $userId;
                    $db->execute(
                        "UPDATE users SET $updateFields WHERE id = ?",
                        $params
                    );
                    
                    // تنظيف Cache للمستخدم بعد التحديث
                    if (function_exists('clearUserCache')) {
                        clearUserCache($userId);
                    }
                    
                    // تحديث كلمة المرور إذا تم إدخالها
                    if (!empty($newPassword)) {
                        if (empty($currentPassword)) {
                            $error = 'يجب إدخال كلمة المرور الحالية';
                        } elseif (!$user || !is_array($user) || !isset($user['id'])) {
                            $error = 'تعذر تحميل بيانات المستخدم. يرجى المحاولة مرة أخرى.';
                        } elseif (!isset($user['password_hash']) || !verifyPassword($currentPassword, $user['password_hash'])) {
                            $error = 'كلمة المرور الحالية غير صحيحة';
                        } elseif ($newPassword !== $confirmPassword) {
                            $error = 'كلمة المرور الجديدة غير متطابقة';
                        } elseif (strlen($newPassword) < $passwordMinLength) {
                            $error = 'كلمة المرور يجب أن تكون على الأقل ' . $passwordMinLength . ' أحرف';
                        } else {
                            $newPasswordHash = hashPassword($newPassword);
                            $db->execute(
                                "UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?",
                                [$newPasswordHash, $userId]
                            );
                            
                            logAudit($userId, 'change_password', 'user', $userId, null, null);
                            
                            // === تحديث الجلسة في قاعدة البيانات بعد تغيير كلمة المرور ===
                            try {
                                if (function_exists('ensureSessionsTable') && ensureSessionsTable()) {
                                    $sessionId = session_id();
                                    if (!empty($sessionId) && !empty($userId)) {
                                        $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (3600 * 24 * 7);
                                        $newExpiresAt = date('Y-m-d H:i:s', time() + $sessionLifetime);
                                        
                                        // محاولة تحديث الجلسة الموجودة
                                        $sessionUpdated = $db->execute(
                                            "UPDATE sessions SET last_activity = NOW(), expires_at = ? WHERE user_id = ? AND session_id = ?",
                                            [$newExpiresAt, $userId, $sessionId]
                                        );
                                        
                                        // إذا لم توجد جلسة، إنشاء واحدة جديدة
                                        if (!$sessionUpdated || ($sessionUpdated['affected_rows'] ?? 0) === 0) {
                                            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                                            $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
                                            
                                            $db->execute(
                                                "INSERT INTO sessions (user_id, session_id, ip_address, user_agent, expires_at, last_activity) 
                                                 VALUES (?, ?, ?, ?, ?, NOW())
                                                 ON DUPLICATE KEY UPDATE last_activity = NOW(), expires_at = ?",
                                                [$userId, $sessionId, $ipAddress, $userAgent, $newExpiresAt, $newExpiresAt]
                                            );
                                        }
                                    }
                                }
                            } catch (Exception $e) {
                                error_log("Profile password change - Error updating session in database: " . $e->getMessage());
                            }
                        }
                    }
                    
                    if (empty($error)) {
                        logAudit($userId, 'update_profile', 'user', $userId, null, ['full_name' => $fullName, 'email' => $email]);
                        
                    // إعادة تحميل بيانات المستخدم المحدثة من قاعدة البيانات
                    $updatedUser = getUserById($userId);
                    if ($updatedUser) {
                        $user = $updatedUser;
                        
                        // تحديث بيانات الجلسة
                        if (isset($user['username'])) {
                            $_SESSION['username'] = $user['username'];
                        }
                        if (isset($user['id'])) {
                            $_SESSION['user_id'] = $user['id'];
                        }
                        if (isset($user['role'])) {
                            $_SESSION['role'] = $user['role'];
                        }
                        $_SESSION['logged_in'] = true;
                        $_SESSION['last_activity'] = time();
                    }
                    
                    // === تحديث الجلسة في قاعدة البيانات لضمان بقائها نشطة ===
                    try {
                        if (function_exists('ensureSessionsTable') && ensureSessionsTable()) {
                            $sessionId = session_id();
                            if (!empty($sessionId) && !empty($userId)) {
                                $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : (3600 * 24 * 7);
                                $newExpiresAt = date('Y-m-d H:i:s', time() + $sessionLifetime);
                                
                                // محاولة تحديث الجلسة الموجودة
                                $sessionUpdated = $db->execute(
                                    "UPDATE sessions SET last_activity = NOW(), expires_at = ? WHERE user_id = ? AND session_id = ?",
                                    [$newExpiresAt, $userId, $sessionId]
                                );
                                
                                // إذا لم توجد جلسة، إنشاء واحدة جديدة
                                if (!$sessionUpdated || ($sessionUpdated['affected_rows'] ?? 0) === 0) {
                                    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                                    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
                                    
                                    $db->execute(
                                        "INSERT INTO sessions (user_id, session_id, ip_address, user_agent, expires_at, last_activity) 
                                         VALUES (?, ?, ?, ?, ?, NOW())
                                         ON DUPLICATE KEY UPDATE last_activity = NOW(), expires_at = ?",
                                        [$userId, $sessionId, $ipAddress, $userAgent, $newExpiresAt, $newExpiresAt]
                                    );
                                }
                            }
                        }
                    } catch (Exception $e) {
                        // لا نوقف العملية إذا فشل تحديث الجلسة، فقط نسجل الخطأ
                        error_log("Profile update - Error updating session in database: " . $e->getMessage());
                    }
                    
                    $_SESSION['success_message'] = 'تم تحديث البروفايل بنجاح';
                    
                    // Redirect لتجنب إعادة إرسال الطلب
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit;
                    }
                }
            }
        }
        if (!empty($error)) {
            $_SESSION['error_message'] = $error;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }
}

require_once __DIR__ . '/includes/lang/' . getCurrentLanguage() . '.php';
$lang = $translations;
$pageTitle = isset($lang['profile']) ? $lang['profile'] : 'الملف الشخصي';
?>
<?php include __DIR__ . '/templates/header.php'; ?>

<script>
// تعيين data attribute للجسم لمساعدة JavaScript في التعرف على profile.php
if (document.body) {
    document.body.setAttribute('data-page', 'profile');
} else {
    document.addEventListener('DOMContentLoaded', function() {
        if (document.body) {
            document.body.setAttribute('data-page', 'profile');
        }
    });
}
</script>

<!-- القائمة الجانبية يتم تضمينها تلقائياً في header.php -->
<?php
// الحصول على role من $user أو $currentUser أو session
$userRole = null;
if ($user && isset($user['role'])) {
    $userRole = $user['role'];
} elseif ($currentUser && isset($currentUser['role'])) {
    $userRole = $currentUser['role'];
} elseif (isset($_SESSION['role'])) {
    $userRole = $_SESSION['role'];
} else {
    $userRole = 'accountant'; // قيمة افتراضية
}

$dashboardUrl = getDashboardUrl($userRole);
?>
<div class="page-header mb-4 d-flex justify-content-between align-items-center">
    <h2 class="mb-0"><i class="bi bi-person-circle me-2"></i><?php echo isset($lang['profile']) ? $lang['profile'] : 'الملف الشخصي'; ?></h2>
    <a href="<?php echo htmlspecialchars($dashboardUrl); ?>" class="btn btn-back">
        <i class="bi bi-arrow-right me-2"></i><span><?php echo isset($lang['back']) ? $lang['back'] : 'رجوع'; ?></span>
    </a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show mb-4">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php
// === التحقق النهائي من وجود بيانات المستخدم قبل عرض النموذج ===
if (!$user || !is_array($user) || !isset($user['id']) || empty($user['id'])) {
    // محاولة أخيرة: تحميل مباشر من قاعدة البيانات
    if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
        try {
            if (!isset($db)) {
                $db = db();
            }
            $userFromDb = $db->queryOne("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);
            if ($userFromDb && isset($userFromDb['id'])) {
                $user = $userFromDb;
                $currentUser = $userFromDb;
                // تحديث الجلسة
                if (!isset($_SESSION['username']) || $_SESSION['username'] !== $userFromDb['username']) {
                    $_SESSION['username'] = $userFromDb['username'];
                }
                if (!isset($_SESSION['role']) || $_SESSION['role'] !== $userFromDb['role']) {
                    $_SESSION['role'] = $userFromDb['role'];
                }
                if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
                    $_SESSION['logged_in'] = true;
                }
            }
        } catch (Exception $e) {
            error_log("Profile display - Final user load failed: " . $e->getMessage());
        }
    }
    
    // إذا استمرت المشكلة بعد جميع المحاولات، عرض رسالة خطأ واحدة فقط
    if (!$user || !is_array($user) || !isset($user['id']) || empty($user['id'])) {
        // إذا كانت هناك رسالة خطأ موجودة بالفعل من الكود السابق، نستخدمها
        // وإلا نعرض رسالة خطأ نهائية
        if (empty($error)) {
            $error = 'تعذر تحميل بيانات المستخدم. يرجى <a href="' . htmlspecialchars($dashboardUrl) . '">العودة</a> والمحاولة مرة أخرى.';
        }
        // عرض رسالة الخطأ في مكان واحد فقط (في قسم عرض الرسائل)
        // لا نعرض رسالة منفصلة هنا لتجنب التكرار
    }
}
?>

<div class="row g-4">
    <!-- معلومات البروفايل -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-person me-2"></i>معلومات البروفايل</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="update_profile">
                                
                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <label for="username" class="form-label">
                                            <i class="bi bi-person-badge me-2"></i>اسم المستخدم
                                        </label>
                                        <input type="text" class="form-control" id="username" 
                                               value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" disabled>
                                        <small class="text-muted">لا يمكن تغيير اسم المستخدم</small>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label for="role" class="form-label">
                                            <i class="bi bi-shield-check me-2"></i>الدور
                                        </label>
                                        <input type="text" class="form-control" id="role" 
                                               value="<?php 
                                               $roleKey = 'role_' . ($user['role'] ?? '');
                                               echo isset($lang[$roleKey]) ? $lang[$roleKey] : (isset($user['role']) ? ucfirst($user['role']) : '-'); 
                                               ?>" disabled>
                                        <small class="text-muted">لا يمكن تغيير الدور</small>
                                    </div>
                                </div>
                    
                    <?php if ($profilePhotoSupported): ?>
                    <div class="mb-3">
                        <label class="form-label">
                            <i class="bi bi-image me-2"></i>الصورة الشخصية
                        </label>
                        <div class="d-flex align-items-center gap-3 flex-wrap">
                            <div class="d-flex align-items-center justify-content-center rounded-circle border overflow-hidden bg-secondary" style="width:80px;height:80px;">
                                <?php if (!empty($user['profile_photo'])): ?>
                                    <img src="<?php echo htmlspecialchars($user['profile_photo']); ?>" alt="Profile" style="width:100%;height:100%;object-fit:cover;">
                                <?php else: ?>
                                    <span class="text-white fw-bold" style="font-size:24px;">
                                        <?php echo htmlspecialchars(mb_substr(($user['username'] ?? ''), 0, 1)); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="flex-grow-1">
                                <input type="file" class="form-control" id="profile_photo" name="profile_photo" accept="image/*">
                                <?php if (!empty($user['profile_photo'])): ?>
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" id="remove_photo" name="remove_photo" value="1">
                                        <label class="form-check-label" for="remove_photo">إزالة الصورة الحالية</label>
                                    </div>
                                <?php endif; ?>
                                <small class="text-muted d-block mt-2">الحد الأقصى 2 ميجابايت والأنواع المسموحة JPG وPNG وGIF وWEBP</small>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                                
                                <div class="mb-3">
                                    <label for="full_name" class="form-label">
                                        <i class="bi bi-person-fill me-2"></i>الاسم الكامل <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="full_name" name="full_name" 
                                           value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="email" class="form-label">
                                        <i class="bi bi-envelope me-2"></i>البريد الإلكتروني <span class="text-danger">*</span>
                                    </label>
                                    <input type="email" class="form-control" id="email" name="email" 
                                           value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="phone" class="form-label">
                                        <i class="bi bi-telephone me-2"></i>رقم الهاتف
                                    </label>
                                    <input type="tel" class="form-control" id="phone" name="phone" 
                                           value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="status" class="form-label">
                                        <i class="bi bi-circle-fill me-2"></i>الحالة
                                    </label>
                                    <input type="text" class="form-control" id="status" 
                                           value="<?php echo isset($user['status']) && isset($lang[$user['status']]) ? $lang[$user['status']] : (isset($user['status']) ? $user['status'] : '-'); ?>" disabled>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">
                                        <i class="bi bi-calendar me-2"></i>تاريخ التسجيل
                                    </label>
                                    <input type="text" class="form-control" 
                                           value="<?php echo isset($user['created_at']) ? formatDateTime($user['created_at']) : '-'; ?>" disabled>
                                </div>
                                
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i class="bi bi-save me-2"></i><?php echo isset($lang['save']) ? $lang['save'] : 'حفظ'; ?>
                                    </button>
                                </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- تغيير كلمة المرور -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-key me-2"></i>تغيير كلمة المرور</h5>
            </div>
            <div class="card-body">
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="update_profile">
                                <input type="hidden" name="full_name" value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>">
                                <input type="hidden" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>">
                                <input type="hidden" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                                
                                <div class="mb-3">
                                    <label for="current_password" class="form-label">كلمة المرور الحالية</label>
                                    <input type="password" class="form-control" id="current_password" name="current_password" 
                                           placeholder="أدخل كلمة المرور الحالية">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="new_password" class="form-label">كلمة المرور الجديدة</label>
                                    <input type="password" class="form-control" id="new_password" name="new_password" 
                                           placeholder="أدخل كلمة المرور الجديدة" minlength="<?php echo $passwordMinLength; ?>">
                                    <small class="text-muted">يجب أن تكون على الأقل <?php echo $passwordMinLength; ?> أحرف</small>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">تأكيد كلمة المرور</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                           placeholder="أعد إدخال كلمة المرور الجديدة">
                                </div>
                                
                                <div class="d-grid mt-4">
                                    <button type="submit" class="btn btn-warning">
                                        <i class="bi bi-key-fill me-2"></i>تغيير كلمة المرور
                                    </button>
                                </div>
                            </form>
            </div>
        </div>
        
        <!-- إدارة البصمة (WebAuthn) -->
        <div class="card mt-3">
            <div class="card-header">
                <h5><i class="bi bi-fingerprint me-2"></i>إدارة البصمة</h5>
            </div>
            <div class="card-body">
                            <div class="mb-3">
                                <strong>حالة WebAuthn:</strong>
                                <span class="badge bg-<?php echo $user['webauthn_enabled'] ? 'success' : 'secondary'; ?> ms-2">
                                    <?php echo $user['webauthn_enabled'] ? 'مفعّل' : 'غير مفعّل'; ?>
                                </span>
                            </div>
                            
                            <!-- علامة تحميل (في مكان منفصل أسفل الحالة) -->
                            <div id="webauthnStatusLoader" class="mb-2" style="display: none !important; visibility: hidden !important; pointer-events: none !important; position: relative; z-index: 1; opacity: 0;">
                                <div class="d-flex align-items-center">
                                    <div class="spinner-border spinner-border-sm text-primary me-2" role="status" style="width: 1rem; height: 1rem;">
                                        <span class="visually-hidden">جاري التحميل...</span>
                                    </div>
                                    <small class="text-muted">جاري التحميل...</small>
                                </div>
                            </div>
                            
                            <!-- قائمة البصمات المسجلة -->
                            <div id="credentialsList" class="mb-3">
                                <div class="text-center">
                                    <div class="spinner-border spinner-border-sm text-primary" role="status">
                                        <span class="visually-hidden">جاري التحميل...</span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- زر إضافة بصمة جديدة -->
                            <div class="d-grid gap-2" style="position: relative; z-index: 1000;">
                                <button type="button" class="btn btn-primary" id="registerWebAuthnBtn" 
                                        style="position: relative; z-index: 1001; pointer-events: auto !important;">
                                    <i class="bi bi-plus-circle me-2"></i>إضافة بصمة جديدة
                                </button>
                            </div>
                            
                            <div class="alert alert-info mt-3 mb-0">
                                <small>
                                    <i class="bi bi-info-circle me-1"></i>
                                    يمكنك تسجيل بصمة أو مفتاح أمني لاستخدامه في تسجيل الدخول بسهولة وأمان.
                                </small>
                            </div>
            </div>
        </div>
        
        <!-- معلومات إضافية -->
        <div class="card mt-3">
            <div class="card-header">
                <h5><i class="bi bi-info-circle me-2"></i>معلومات إضافية</h5>
            </div>
            <div class="card-body">
                            <div class="mb-2">
                                <strong>آخر تحديث:</strong><br>
                                <small class="text-muted">
                                    <?php echo $user['updated_at'] ? formatDateTime($user['updated_at']) : 'لم يتم التحديث'; ?>
                                </small>
                            </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/templates/footer.php'; ?>

<!-- WebAuthn Script -->
<script src="<?php echo ASSETS_URL; ?>js/webauthn.js"></script>

<style>
/* التأكد من أن زر إضافة البصمة دائماً قابل للنقر */
#registerWebAuthnBtn {
    position: relative !important;
    z-index: 1001 !important;
    pointer-events: auto !important;
    cursor: pointer !important;
}

#registerWebAuthnBtn:disabled {
    opacity: 0.6;
    cursor: not-allowed !important;
}

/* التأكد من أن علامة التحميل لا تمنع التفاعل */
#webauthnStatusLoader {
    pointer-events: none !important;
    z-index: 1 !important;
    position: relative !important;
    display: none !important;
    visibility: hidden !important;
    opacity: 0 !important;
}

/* عند إظهار علامة التحميل بشكل مؤقت */
#webauthnStatusLoader.show {
    display: block !important;
    visibility: visible !important;
    opacity: 1 !important;
}

/* التأكد من أن div الزر لا يمنع التفاعل */
.d-grid.gap-2 {
    position: relative !important;
    z-index: 1000 !important;
}
</style>

<script>
// تحميل قائمة البصمات المسجلة
async function loadCredentials() {
    // إظهار علامة التحميل (لكن لا نمنع الضغط على الزر)
    const statusLoader = document.getElementById('webauthnStatusLoader');
    if (statusLoader) {
        statusLoader.classList.add('show');
        // التأكد من أن علامة التحميل لا تمنع الضغط على الزر
        statusLoader.style.pointerEvents = 'none';
        statusLoader.style.zIndex = '1';
    }
    
    // التأكد من أن الزر قابل للنقر حتى أثناء التحميل
    const registerBtn = document.getElementById('registerWebAuthnBtn');
    if (registerBtn) {
        registerBtn.style.pointerEvents = 'auto';
        registerBtn.style.zIndex = '1001';
        registerBtn.disabled = false; // التأكد من عدم تعطيل الزر
    }
    
    try {
        const listContainer = document.getElementById('credentialsList');
        if (!listContainer) {
            console.error('credentialsList element not found');
            if (statusLoader) {
                statusLoader.style.display = 'none';
            }
            return;
        }
        
        // الحصول على المسار الصحيح لـ API - استخدام getRelativeUrl من PHP
        let apiPath = '<?php echo getRelativeUrl("api/webauthn_credentials.php"); ?>';
        
        // التحقق من أن المسار صحيح
        if (!apiPath || apiPath === '') {
            // Fallback: حساب المسار يدوياً
            const pathParts = window.location.pathname.split('/').filter(p => p && !p.endsWith('.php'));
            if (pathParts.length === 0) {
                apiPath = 'api/webauthn_credentials.php';
            } else {
                apiPath = '/' + pathParts[0] + '/api/webauthn_credentials.php';
            }
        }
        
        console.log('Loading credentials from:', apiPath);
        
        // تحديث الجلسة قبل استدعاء API
        try {
            await refreshSession();
        } catch (refreshError) {
            console.warn('Failed to refresh session before loading credentials:', refreshError);
        }
        
        // إنشاء AbortController للتحكم في timeout
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 8000);
        
        const response = await fetch(apiPath + '?action=list', {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Cache-Control': 'no-cache'
            },
            signal: controller.signal,
            cache: 'no-store'
        });
        
        clearTimeout(timeoutId);
        
        // معالجة خاصة لخطأ 401
        if (response.status === 401) {
            // محاولة تحديث الجلسة مرة أخرى
            try {
                await refreshSession();
                // إعادة المحاولة مرة واحدة فقط
                const retryResponse = await fetch(apiPath + '?action=list', {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Cache-Control': 'no-cache'
                    },
                    cache: 'no-store'
                });
                
                if (retryResponse.status === 401) {
                    throw new Error('انتهت جلسة العمل. يرجى إعادة تحميل الصفحة.');
                }
                
                // استخدام الاستجابة من المحاولة الثانية
                if (!retryResponse.ok) {
                    throw new Error(`HTTP error! status: ${retryResponse.status}`);
                }
                
                // معالجة الاستجابة الناجحة
                const contentType = retryResponse.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    const text = await retryResponse.text();
                    console.error('Non-JSON response from credentials API:', text);
                    throw new Error('استجابة غير صحيحة من الخادم');
                }
                
                const data = await retryResponse.json();
                
                if (!data.success) {
                    listContainer.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>خطأ في تحميل البصمات: ' + (data.error || 'حدث خطأ غير معروف') + '</div>';
                    console.error('API Error:', data);
                    return;
                }
                
                if (data.credentials.length === 0) {
                    listContainer.innerHTML = `
                        <div class="alert alert-secondary mb-0">
                            <i class="bi bi-info-circle me-2"></i>
                            لا توجد بصمات مسجلة حالياً
                        </div>
                    `;
                    return;
                }
                
                let html = '<div class="list-group">';
                data.credentials.forEach(cred => {
                    const createdDate = new Date(cred.created_at).toLocaleDateString('ar-EG');
                    const lastUsed = cred.last_used ? new Date(cred.last_used).toLocaleDateString('ar-EG') : 'لم يتم الاستخدام';
                    
                    html += `
                        <div class="list-group-item d-flex justify-content-between align-items-center" data-credential-id="${cred.id}">
                            <div>
                                <div class="fw-bold">
                                    <i class="bi bi-fingerprint me-2"></i>
                                    ${cred.device_name || 'جهاز غير معروف'}
                                </div>
                                <small class="text-muted">
                                    <div>تاريخ التسجيل: ${createdDate}</div>
                                    <div>آخر استخدام: ${lastUsed}</div>
                                </small>
                            </div>
                            <button class="btn btn-sm btn-danger delete-credential-btn" data-credential-id="${cred.id}" data-device-name="${(cred.device_name || 'البصمة').replace(/"/g, '&quot;')}">
                                <i class="bi bi-trash"></i> حذف
                            </button>
                        </div>
                    `;
                });
                html += '</div>';
                
                listContainer.innerHTML = html;
                
                // إضافة event listeners لأزرار الحذف
                listContainer.querySelectorAll('.delete-credential-btn').forEach(btn => {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        const credentialId = this.getAttribute('data-credential-id');
                        const deviceName = this.getAttribute('data-device-name');
                        deleteCredential(credentialId, deviceName);
                    });
                });
                
                // إخفاء علامة التحميل بعد نجاح التحميل
                if (statusLoader) {
                    statusLoader.classList.remove('show');
                    statusLoader.style.display = 'none';
                    statusLoader.style.visibility = 'hidden';
                    statusLoader.style.pointerEvents = 'none';
                    statusLoader.style.opacity = '0';
                }
                
                return; // إنهاء الدالة بنجاح
            } catch (retryError) {
                throw new Error('انتهت جلسة العمل. يرجى إعادة تحميل الصفحة.');
            }
        }
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        // التحقق من نوع المحتوى
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            const text = await response.text();
            console.error('Non-JSON response from credentials API:', text);
            throw new Error('استجابة غير صحيحة من الخادم');
        }
        
        const data = await response.json();
        
        if (!data.success) {
            listContainer.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>خطأ في تحميل البصمات: ' + (data.error || 'حدث خطأ غير معروف') + '</div>';
            console.error('API Error:', data);
            return;
        }
        
        if (data.credentials.length === 0) {
            listContainer.innerHTML = `
                <div class="alert alert-secondary mb-0">
                    <i class="bi bi-info-circle me-2"></i>
                    لا توجد بصمات مسجلة حالياً
                </div>
            `;
            return;
        }
        
        let html = '<div class="list-group">';
        data.credentials.forEach(cred => {
            const createdDate = new Date(cred.created_at).toLocaleDateString('ar-EG');
            const lastUsed = cred.last_used ? new Date(cred.last_used).toLocaleDateString('ar-EG') : 'لم يتم الاستخدام';
            
            html += `
                <div class="list-group-item d-flex justify-content-between align-items-center" data-credential-id="${cred.id}">
                    <div>
                        <div class="fw-bold">
                            <i class="bi bi-fingerprint me-2"></i>
                            ${cred.device_name || 'جهاز غير معروف'}
                        </div>
                        <small class="text-muted">
                            <div>تاريخ التسجيل: ${createdDate}</div>
                            <div>آخر استخدام: ${lastUsed}</div>
                        </small>
                    </div>
                    <button class="btn btn-sm btn-danger delete-credential-btn" data-credential-id="${cred.id}" data-device-name="${(cred.device_name || 'البصمة').replace(/"/g, '&quot;')}">
                        <i class="bi bi-trash"></i> حذف
                    </button>
                </div>
            `;
        });
        html += '</div>';
        
        listContainer.innerHTML = html;
        
        // إضافة event listeners لأزرار الحذف
        listContainer.querySelectorAll('.delete-credential-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const credentialId = this.getAttribute('data-credential-id');
                const deviceName = this.getAttribute('data-device-name');
                deleteCredential(credentialId, deviceName);
            });
        });
        
        // إخفاء علامة التحميل بعد نجاح التحميل
        if (statusLoader) {
            statusLoader.classList.remove('show');
            statusLoader.style.display = 'none';
            statusLoader.style.visibility = 'hidden';
            statusLoader.style.pointerEvents = 'none';
            statusLoader.style.opacity = '0';
        }
        
    } catch (error) {
        console.error('Error loading credentials:', error);
        console.error('Error details:', {
            message: error.message,
            name: error.name,
            stack: error.stack,
            pathname: window.location.pathname
        });
        
        // إخفاء علامة التحميل عند حدوث خطأ (مهم جداً)
        const statusLoader = document.getElementById('webauthnStatusLoader');
        if (statusLoader) {
            statusLoader.classList.remove('show');
            statusLoader.style.display = 'none';
            statusLoader.style.visibility = 'hidden';
            statusLoader.style.pointerEvents = 'none';
            statusLoader.style.opacity = '0';
        }
        
        const listContainer = document.getElementById('credentialsList');
        if (listContainer) {
            let errorMessage = 'خطأ في تحميل البصمات';
            let showReloadButton = false;
            
            if (error.name === 'AbortError' || error.message.includes('aborted')) {
                errorMessage = 'انتهت مهلة التحميل. يمكنك المحاولة مرة أخرى أو إضافة بصمة جديدة مباشرة.';
            } else if (error.message.includes('Failed to fetch') || error.message.includes('NetworkError')) {
                errorMessage += ': ' + error.message + '<br><small>يرجى التحقق من اتصال الإنترنت أو المسار الصحيح للـ API</small>';
            } else if (error.message.includes('401') || error.message.includes('انتهت جلسة العمل') || error.message.includes('Unauthorized')) {
                errorMessage = 'انتهت جلسة العمل. يرجى إعادة تحميل الصفحة.';
                showReloadButton = true;
            } else {
                errorMessage += ': ' + error.message;
            }
            
            let errorHtml = '<div class="alert alert-warning"><i class="bi bi-info-circle me-2"></i>' + errorMessage;
            
            if (showReloadButton) {
                errorHtml += '<br><br><button class="btn btn-sm btn-primary mt-2" onclick="window.location.reload()"><i class="bi bi-arrow-clockwise me-2"></i>إعادة تحميل الصفحة</button>';
            } else {
                errorHtml += '<br><br><button class="btn btn-sm btn-primary mt-2" onclick="loadCredentials()"><i class="bi bi-arrow-clockwise me-2"></i>إعادة المحاولة</button>';
            }
            
            errorHtml += '</div>';
            
            listContainer.innerHTML = errorHtml;
        }
    }
}

// تهيئة تحميل البصمات عند تحميل الصفحة
function initWebAuthn() {
    // التأكد من إخفاء علامة التحميل فوراً
    const statusLoader = document.getElementById('webauthnStatusLoader');
    if (statusLoader) {
        statusLoader.style.display = 'none';
        statusLoader.style.visibility = 'hidden';
        statusLoader.style.pointerEvents = 'none';
    }
    
    // تحميل البصمات مباشرة
    loadCredentials();
}

// تهيئة عند تحميل الصفحة
(function() {
    // التأكد من إخفاء علامة التحميل فوراً عند تحميل الصفحة
    function hideLoaderImmediately() {
        const statusLoader = document.getElementById('webauthnStatusLoader');
        if (statusLoader) {
            statusLoader.style.display = 'none';
            statusLoader.style.visibility = 'hidden';
            statusLoader.style.pointerEvents = 'none';
            statusLoader.style.opacity = '0';
            statusLoader.classList.remove('show');
        }
    }
    
    if (document.readyState === 'loading') {
        // إخفاء فوراً
        hideLoaderImmediately();
        document.addEventListener('DOMContentLoaded', function() {
            hideLoaderImmediately();
            initWebAuthn();
        });
    } else {
        // الصفحة محملة بالفعل
        hideLoaderImmediately();
        initWebAuthn();
    }
})();

// دالة مساعدة لتحديث الجلسة قبل إرسال أي طلب API
async function refreshSession() {
    try {
        // إرسال طلب HEAD لتحديث الجلسة بدون تحميل المحتوى الكامل
        const response = await fetch(window.location.href, {
            method: 'HEAD',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Cache-Control': 'no-cache',
                'Pragma': 'no-cache'
            }
        });
        
        // إذا فشل الطلب، جرب طلب GET بسيط
        if (!response.ok) {
            const getResponse = await fetch(window.location.href + '?refresh_session=1', {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Cache-Control': 'no-cache',
                    'Pragma': 'no-cache',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            return getResponse.ok;
        }
        
        return response.ok;
    } catch (error) {
        console.warn('Failed to refresh session:', error);
        // في حالة الفشل، نرجع true لأن الجلسة قد تكون صالحة بالفعل
        return true;
    }
}

// تسجيل بصمة جديدة - نظام جديد مبسط
async function registerNewCredential() {
    const btn = document.getElementById('registerWebAuthnBtn');
    if (!btn) {
        console.error('registerWebAuthnBtn button not found');
        return;
    }
    
    const originalHTML = btn.innerHTML;
    const statusLoader = document.getElementById('webauthnStatusLoader');
    
    try {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري التسجيل...';
        
        if (statusLoader) {
            statusLoader.classList.add('show');
            statusLoader.style.display = 'block';
            statusLoader.style.visibility = 'visible';
        }
        
        // تحديث الجلسة قبل البدء
        await refreshSession();
        
        // استخدام النظام الجديد المبسط
        if (typeof simpleWebAuthn === 'undefined') {
            throw new Error('نظام WebAuthn غير محمّل. يرجى تحديث الصفحة.');
        }
        
        const result = await simpleWebAuthn.register();
        
        if (result.success) {
            // إعادة تحميل قائمة البصمات
            await loadCredentials();
            
            // إظهار رسالة نجاح
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-success alert-dismissible fade show';
            alertDiv.innerHTML = `
                <i class="bi bi-check-circle-fill me-2"></i>
                ${result.message || 'تم تسجيل البصمة بنجاح!'}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            const mainContent = document.querySelector('main');
            if (mainContent) {
                mainContent.insertBefore(alertDiv, mainContent.firstChild);
                setTimeout(() => {
                    alertDiv.remove();
                }, 5000);
            }
        } else {
            alert(result.message || 'فشل تسجيل البصمة');
        }
        
    } catch (error) {
        console.error('WebAuthn Registration Error:', error);
        
        // معالجة أفضل للأخطاء على الموبايل
        let errorMessage = error.message || 'حدث خطأ أثناء تسجيل البصمة';
        
        // معالجة خاصة لخطأ 401 (انتهت الجلسة)
        if (errorMessage.includes('انتهت جلسة العمل') || errorMessage.includes('401') || errorMessage.includes('Unauthorized')) {
            if (confirm('انتهت جلسة العمل. هل تريد إعادة تحميل الصفحة لتسجيل الدخول مرة أخرى؟')) {
                window.location.reload();
            }
            return;
        }
        
        // إذا كانت الرسالة تحتوي على نص عربي مفصل، استخدمها مباشرة
        if (errorMessage.includes('تم إلغاء العملية') || errorMessage.includes('تأكد من:')) {
            alert(errorMessage);
        } else {
            // رسالة عامة مع إرشادات للموبايل
            const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
            if (isMobile) {
                errorMessage += '\n\nملاحظة للموبايل:\n';
                errorMessage += '1. تأكد من تفعيل Face ID/Touch ID في إعدادات الجهاز\n';
                errorMessage += '2. اضغط "Allow" عند ظهور نافذة طلب الإذن\n';
                errorMessage += '3. تأكد من أن الموقع يعمل على HTTPS';
            }
            alert(errorMessage);
        }
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalHTML;
        
        if (statusLoader) {
            statusLoader.classList.remove('show');
            statusLoader.style.display = 'none';
            statusLoader.style.visibility = 'hidden';
            statusLoader.style.pointerEvents = 'none';
            statusLoader.style.opacity = '0';
        }
    }
}

// التأكد من أن الدالة متاحة في النطاق العام
if (typeof window.registerNewCredential === 'undefined') {
    window.registerNewCredential = registerNewCredential;
}

// حذف بصمة
async function deleteCredential(credentialId, deviceName) {
    const deviceNameSafe = typeof deviceName === 'string' ? deviceName : 'البصمة';
    if (!confirm(`هل أنت متأكد من حذف البصمة "${deviceNameSafe}"؟\n\nسيتم حذف هذه البصمة ولن تتمكن من استخدامها في تسجيل الدخول.`)) {
        return;
    }
    
    try {
        // الحصول على المسار الصحيح لـ API - استخدام getRelativeUrl من PHP
        let apiPath = '<?php echo getRelativeUrl("api/webauthn_credentials.php"); ?>';
        
        // التحقق من أن المسار صحيح
        if (!apiPath || apiPath === '') {
            // Fallback: حساب المسار يدوياً
            const pathParts = window.location.pathname.split('/').filter(p => p && !p.endsWith('.php'));
            if (pathParts.length === 0) {
                apiPath = 'api/webauthn_credentials.php';
            } else {
                apiPath = '/' + pathParts[0] + '/api/webauthn_credentials.php';
            }
        }
        
        console.log('Deleting credential, API path:', apiPath);
        
        const response = await fetch(apiPath, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'delete',
                credential_id: credentialId
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            // إظهار رسالة نجاح قبل refresh
            alert('تم حذف البصمة بنجاح');
            
            // عمل refresh للصفحة
            window.location.reload();
        } else {
            alert('خطأ: ' + (data.error || 'حدث خطأ أثناء حذف البصمة'));
        }
        
    } catch (error) {
        console.error('Error deleting credential:', error);
        alert('خطأ في الاتصال بالخادم. يرجى المحاولة مرة أخرى.');
    }
}

// التأكد من أن الدوال متاحة في النطاق العام
if (typeof window.loadCredentials === 'undefined') {
    window.loadCredentials = loadCredentials;
}

// تحميل القائمة عند تحميل الصفحة
document.addEventListener('DOMContentLoaded', function() {
    // إضافة event listener للزر
    const registerBtn = document.getElementById('registerWebAuthnBtn');
    if (registerBtn) {
        registerBtn.addEventListener('click', function(e) {
            e.preventDefault();
            registerNewCredential();
        });
    }
    
    // التأكد من تحميل simpleWebAuthn
    if (typeof simpleWebAuthn === 'undefined') {
        console.error('simpleWebAuthn is not defined. Make sure webauthn-new.js is loaded.');
        // إخفاء زر التسجيل إذا لم يكن WebAuthn متاح
        if (registerBtn) {
            registerBtn.disabled = true;
            registerBtn.innerHTML = '<i class="bi bi-exclamation-triangle me-2"></i>WebAuthn غير متاح';
        }
    }
    
    loadCredentials();
});
</script>


