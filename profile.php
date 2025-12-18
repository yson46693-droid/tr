<?php
/**
 * صفحة البروفايل الشخصي للمستخدم
 */

define('ACCESS_ALLOWED', true);
// تعريف ثابت للصفحة المحمية (profile.php)
define('PROFILE_PAGE_ACTIVE', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/path_helper.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit_log.php';

// التحقق من تسجيل الدخول - يعتمد على remember_token فقط
// تم إزالة نظام الجلسات بالكامل
// استخدام requireLogin() للتحقق من تسجيل الدخول (يتعامل مع التوجيه تلقائياً)

// التحقق من تسجيل الدخول - يعتمد على remember_token فقط
requireLogin();

// ========================================
// الحل 4: التحقق من صلاحيات الملفات
// ========================================
// التأكد من أن الدوال المطلوبة موجودة
if (!function_exists('requireLogin')) {
    die('خطأ: دالة requireLogin غير موجودة');
}

if (!function_exists('getUserFromToken')) {
    die('خطأ: دالة getUserFromToken غير موجودة');
}

// تهيئة متغيرات الرسائل
// تم إزالة نظام الجلسات - يمكن استخدام query parameters للرسائل إذا لزم الأمر
$error = $_GET['error'] ?? '';
$success = $_GET['success'] ?? '';

// ========================================
// الحل 1: إضافة تسجيل تفصيلي للأخطاء (Debugging)
// ========================================
error_log("=== Profile.php Debug Info ===");
error_log("Cookie exists: " . (isset($_COOKIE['remember_token']) ? 'YES' : 'NO'));
$debugUser = getUserFromToken();
error_log("User from getUserFromToken: " . ($debugUser ? json_encode($debugUser) : 'NULL'));
error_log("User ID: " . (isset($debugUser['id']) ? $debugUser['id'] : 'NULL'));

// تسجيل تفاصيل الـ cookie إذا كان موجوداً
if (isset($_COOKIE['remember_token'])) {
    error_log("Remember token cookie value (first 20 chars): " . substr($_COOKIE['remember_token'], 0, 20));
    $decoded = base64_decode($_COOKIE['remember_token'], true);
    if ($decoded) {
        $parts = explode(':', $decoded);
        error_log("Token parts count: " . count($parts));
        if (count($parts) === 2) {
            error_log("User ID from token: " . intval($parts[0]));
        }
    }
}

// === تحميل بيانات المستخدم ===
// requireLogin() نجح بالفعل، لذلك يجب أن يكون هناك token صالح
// نستخدم getUserFromToken() مباشرة - نفس الدالة التي يستخدمها requireLogin()
$user = null;
$currentUser = null;
$userId = null;

// المحاولة 1: استخدام getUserFromToken() - نفس الدالة المستخدمة في requireLogin()
$user = getUserFromToken();
if ($user && isset($user['id']) && !empty($user['id'])) {
    $currentUser = $user;
    $userId = $user['id'];
    error_log("Profile.php - Successfully loaded user from getUserFromToken()");
} else {
    // إذا فشلت getUserFromToken()، نحاول getCurrentUser() كبديل
    error_log("Profile.php - getUserFromToken() failed, trying getCurrentUser()...");
    $user = getCurrentUser();
    if ($user && isset($user['id']) && !empty($user['id'])) {
        $currentUser = $user;
        $userId = $user['id'];
        error_log("Profile.php - Successfully loaded user from getCurrentUser()");
    } else {
        // إذا فشلت كل المحاولات، نحاول تحميل المستخدم مباشرة من remember_token
        error_log("Profile.php - Both getUserFromToken() and getCurrentUser() failed, trying direct token load...");
        
        if (isset($_COOKIE['remember_token']) && !empty($_COOKIE['remember_token'])) {
            try {
                if (ensureRememberTokensTable()) {
                    $db = db();
                    $decoded = base64_decode($_COOKIE['remember_token'], true);
                    if ($decoded !== false && !empty($decoded)) {
                        $parts = explode(':', $decoded);
                        if (count($parts) === 2) {
                            $tokenUserId = intval($parts[0]);
                            $token = trim($parts[1]);
                            
                            if ($tokenUserId > 0 && !empty($token)) {
                                $tokenRecord = $db->queryOne(
                                    "SELECT rt.*, u.* FROM remember_tokens rt
                                     INNER JOIN users u ON rt.user_id = u.id
                                     WHERE rt.user_id = ? AND rt.token = ? AND rt.expires_at > NOW() AND u.status = 'active'",
                                    [$tokenUserId, $token]
                                );
                                
                                if ($tokenRecord) {
                                    $user = [
                                        'id' => $tokenRecord['user_id'],
                                        'username' => $tokenRecord['username'],
                                        'email' => $tokenRecord['email'] ?? null,
                                        'full_name' => $tokenRecord['full_name'] ?? null,
                                        'role' => $tokenRecord['role'],
                                        'status' => $tokenRecord['status'],
                                        'phone' => $tokenRecord['phone'] ?? null,
                                        'created_at' => $tokenRecord['created_at'] ?? null,
                                        'updated_at' => $tokenRecord['updated_at'] ?? null,
                                        'profile_photo' => $tokenRecord['profile_photo'] ?? null,
                                        'webauthn_enabled' => $tokenRecord['webauthn_enabled'] ?? false,
                                    ];
                                    $currentUser = $user;
                                    $userId = $user['id'];
                                    error_log("Profile.php - Successfully loaded user from direct token query");
                                } else {
                                    error_log("Profile.php - Token record not found in database for user_id: {$tokenUserId}");
                                }
                            } else {
                                error_log("Profile.php - Invalid tokenUserId ({$tokenUserId}) or empty token");
                            }
                        } else {
                            error_log("Profile.php - Invalid token format (parts count: " . count($parts) . ")");
                        }
                    } else {
                        error_log("Profile.php - Failed to decode remember_token cookie");
                    }
                } else {
                    error_log("Profile.php - ensureRememberTokensTable() failed");
                }
            } catch (Exception $e) {
                error_log("Profile.php - Exception during direct token load: " . $e->getMessage());
            }
        } else {
            error_log("Profile.php - No remember_token cookie found");
        }
    }
}

// إذا فشل تحميل المستخدم بعد كل المحاولات
if (!$user || !isset($user['id']) || empty($user['id'])) {
    error_log("Profile.php - CRITICAL: All attempts to load user data failed. requireLogin() succeeded but user data cannot be loaded.");
    $error = 'تعذر تحميل بيانات المستخدم. يرجى <a href="' . $_SERVER['PHP_SELF'] . '">إعادة تحميل الصفحة</a> أو <a href="logout.php">تسجيل الخروج</a> والدخول مرة أخرى.';
}

// ========================================
// الحل 6: فحص حالة قاعدة البيانات
// ========================================
try {
    $db = db();
    
    // التحقق من وجود الجدول
    $tableExists = $db->queryOne("SHOW TABLES LIKE 'remember_tokens'");
    if (!$tableExists) {
        error_log("Profile.php - CRITICAL: remember_tokens table does not exist!");
        die('خطأ في النظام: جدول الجلسات غير موجود');
    }
    
    // التحقق من عدد السجلات النشطة
    $activeTokens = $db->queryOne(
        "SELECT COUNT(*) as count FROM remember_tokens WHERE expires_at > NOW()"
    );
    error_log("Profile.php - Active tokens in database: " . ($activeTokens['count'] ?? 0));
    
} catch (Exception $e) {
    error_log("Profile.php - Database check failed: " . $e->getMessage());
}

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

// معالجة تحديث البروفايل
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
        if ($action === 'update_profile') {
        // إعادة تحميل بيانات المستخدم قبل المعالجة
        if (!$user || !is_array($user) || !isset($user['id']) || empty($user['id'])) {
            $user = getCurrentUser();
            $currentUser = $user;
            $userId = $user['id'] ?? null;
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
                $userId = ($currentUser && isset($currentUser['id'])) ? $currentUser['id'] : ($user && isset($user['id']) ? $user['id'] : null);
                
                // إذا لم نجد userId، حاول الحصول من remember_token
                if (!$userId) {
                    $tokenUser = getUserFromToken();
                    $userId = $tokenUser['id'] ?? null;
                }
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
                } else {
                    // محاولة الحصول على userId من remember_token
                    $tokenUser = getUserFromToken();
                    $userId = $tokenUser['id'] ?? null;
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
                        }
                    }
                    
                    if (empty($error)) {
                        logAudit($userId, 'update_profile', 'user', $userId, null, ['full_name' => $fullName, 'email' => $email]);
                        
                    // إعادة تحميل بيانات المستخدم المحدثة
                    $user = getCurrentUser();
                    $currentUser = $user;
                    
                    // تم إزالة نظام الجلسات - استخدام query parameter للرسالة
                    $successMessage = urlencode('تم تحديث البروفايل بنجاح');
                    $redirectUrl = $_SERVER['PHP_SELF'] . '?success=' . $successMessage;
                    
                    // Redirect لتجنب إعادة إرسال الطلب
                    header('Location: ' . $redirectUrl);
                    exit;
                    }
                }
            }
        }
        if (!empty($error)) {
            // تم إزالة نظام الجلسات - استخدام query parameter للرسالة
            $errorMessage = urlencode($error);
            $redirectUrl = $_SERVER['PHP_SELF'] . '?error=' . $errorMessage;
            header('Location: ' . $redirectUrl);
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
// الحصول على role من $user أو $currentUser
$userRole = null;
if ($user && isset($user['role'])) {
    $userRole = $user['role'];
} elseif ($currentUser && isset($currentUser['role'])) {
    $userRole = $currentUser['role'];
} else {
    // محاولة الحصول من remember_token
    $tokenUser = getUserFromToken();
    $userRole = $tokenUser['role'] ?? 'accountant';
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

<?php
// ========================================
// الحل 3: إضافة زر للتحقق اليدوي
// ========================================
// في حالة فشل تحميل البيانات
if (!empty($error) && (!$user || !isset($user['id']))): ?>
<div class="alert alert-danger mb-4">
    <h4><i class="bi bi-exclamation-triangle me-2"></i>مشكلة في تحميل البيانات</h4>
    <p><?php echo $error; ?></p>
    <div class="mt-3">
        <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-primary me-2">
            <i class="bi bi-arrow-clockwise me-2"></i>إعادة المحاولة
        </a>
        <a href="logout.php" class="btn btn-secondary">
            <i class="bi bi-box-arrow-right me-2"></i>تسجيل الخروج والدخول مرة أخرى
        </a>
    </div>
</div>

<!-- إيقاف عرض بقية الصفحة -->
<?php 
include __DIR__ . '/templates/footer.php';
exit;
?>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php
// التحقق من وجود بيانات المستخدم
if (!$user || !is_array($user) || !isset($user['id']) || empty($user['id'])) {
    if (empty($error)) {
        // إذا لم يتم تحميل المستخدم، نعرض رسالة خطأ
        $error = 'تعذر تحميل بيانات المستخدم. يرجى <a href="' . htmlspecialchars($dashboardUrl) . '">العودة</a> والمحاولة مرة أخرى.';
    }
    // لا نعرض الصفحة إذا لم يتم تحميل المستخدم
    // سيتم إيقاف الصفحة في الكود أعلاه (السطر 415-434)
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
        
        if (!response.ok) {
            if (response.status === 401) {
                throw new Error('انتهت جلسة العمل. يرجى إعادة تحميل الصفحة.');
            }
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
            credentials: 'same-origin',
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


