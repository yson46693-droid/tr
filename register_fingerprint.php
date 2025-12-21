<?php
/**
 * صفحة تسجيل البصمة للمستخدم
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/path_helper.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

// تحميل اللغة
require_once __DIR__ . '/includes/lang/' . getCurrentLanguage() . '.php';
$lang = $translations;
$pageTitle = 'تسجيل البصمة';
include __DIR__ . '/templates/header.php';

// الحصول على بيانات المستخدم
$user = getCurrentUser();
$userRole = $user['role'] ?? 'accountant';
$dashboardUrl = getDashboardUrl($userRole);
?>
<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="card shadow-lg border-0">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0">
                        <i class="bi bi-fingerprint me-2"></i>تسجيل البصمة
                    </h4>
                </div>
                <div class="card-body p-4">
                    <!-- حالة WebAuthn -->
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <strong>حالة البصمة:</strong>
                            <span class="badge bg-<?php echo $user['webauthn_enabled'] ? 'success' : 'secondary'; ?>">
                                <?php echo $user['webauthn_enabled'] ? 'مفعّل' : 'غير مفعّل'; ?>
                            </span>
                        </div>
                        
                        <!-- قائمة البصمات المسجلة -->
                        <div id="credentialsList" class="mb-3">
                            <div class="text-center">
                                <div class="spinner-border spinner-border-sm text-primary" role="status">
                                    <span class="visually-hidden">جاري التحميل...</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- زر تسجيل بصمة جديدة -->
                    <div class="d-grid gap-2 mb-3">
                        <button type="button" class="btn btn-primary btn-lg" id="registerWebAuthnBtn">
                            <i class="bi bi-plus-circle me-2"></i>إضافة بصمة جديدة
                        </button>
                    </div>
                    
                    <!-- معلومات -->
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        <small>
                            يمكنك تسجيل بصمة أو مفتاح أمني لاستخدامه في تسجيل الدخول بسهولة وأمان.
                            <br>
                            <strong>ملاحظة:</strong> تأكد من تفعيل Face ID/Touch ID في إعدادات جهازك.
                        </small>
                    </div>
                    
                    <!-- زر العودة -->
                    <div class="mt-4 text-center">
                        <a href="<?php echo htmlspecialchars($dashboardUrl); ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-right me-2"></i>العودة إلى لوحة التحكم
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/templates/footer.php'; ?>

<!-- WebAuthn Script -->
<script src="<?php echo ASSETS_URL; ?>js/webauthn.js"></script>

<style>
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
</style>

<script>
// تحميل قائمة البصمات المسجلة
async function loadCredentials() {
    try {
        const listContainer = document.getElementById('credentialsList');
        if (!listContainer) {
            console.error('credentialsList element not found');
            return;
        }
        
        // الحصول على المسار الصحيح لـ API
        const pathParts = window.location.pathname.split('/').filter(p => p && !p.endsWith('.php'));
        let apiPath;
        
        if (pathParts.length === 0) {
            apiPath = 'api/webauthn_credentials.php';
        } else {
            apiPath = '/' + pathParts[0] + '/api/webauthn_credentials.php';
        }
        
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 8000);
        
        const response = await fetch(apiPath + '?action=list', {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json'
            },
            signal: controller.signal
        });
        
        clearTimeout(timeoutId);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            const text = await response.text();
            console.error('Non-JSON response:', text);
            throw new Error('استجابة غير صحيحة من الخادم');
        }
        
        const data = await response.json();
        
        if (!data.success) {
            listContainer.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>خطأ في تحميل البصمات: ' + (data.error || 'حدث خطأ غير معروف') + '</div>';
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
        
    } catch (error) {
        console.error('Error loading credentials:', error);
        const listContainer = document.getElementById('credentialsList');
        if (listContainer) {
            let errorMessage = 'خطأ في تحميل البصمات';
            
            if (error.name === 'AbortError' || error.message.includes('aborted')) {
                errorMessage = 'انتهت مهلة التحميل. يمكنك المحاولة مرة أخرى.';
            } else if (error.message.includes('Failed to fetch') || error.message.includes('NetworkError')) {
                errorMessage += ': ' + error.message;
            } else {
                errorMessage += ': ' + error.message;
            }
            
            listContainer.innerHTML = 
                '<div class="alert alert-warning"><i class="bi bi-info-circle me-2"></i>' + errorMessage + '</div>';
        }
    }
}

// تسجيل بصمة جديدة
async function registerNewCredential() {
    const btn = document.getElementById('registerWebAuthnBtn');
    if (!btn) {
        console.error('registerWebAuthnBtn button not found');
        return;
    }
    
    const originalHTML = btn.innerHTML;
    
    try {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري التسجيل...';
        
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
            const cardBody = document.querySelector('.card-body');
            if (cardBody) {
                cardBody.insertBefore(alertDiv, cardBody.firstChild);
                setTimeout(() => {
                    alertDiv.remove();
                }, 5000);
            }
        } else {
            alert(result.message || 'فشل تسجيل البصمة');
        }
        
    } catch (error) {
        console.error('WebAuthn Registration Error:', error);
        
        let errorMessage = error.message || 'حدث خطأ أثناء تسجيل البصمة';
        
        if (errorMessage.includes('تم إلغاء العملية') || errorMessage.includes('تأكد من:')) {
            alert(errorMessage);
        } else {
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
    }
}

// حذف بصمة
async function deleteCredential(credentialId, deviceName) {
    const deviceNameSafe = typeof deviceName === 'string' ? deviceName : 'البصمة';
    if (!confirm(`هل أنت متأكد من حذف البصمة "${deviceNameSafe}"؟\n\nسيتم حذف هذه البصمة ولن تتمكن من استخدامها في تسجيل الدخول.`)) {
        return;
    }
    
    try {
        const pathParts = window.location.pathname.split('/').filter(p => p && !p.endsWith('.php'));
        let apiPath;
        
        if (pathParts.length === 0) {
            apiPath = 'api/webauthn_credentials.php';
        } else {
            apiPath = '/' + pathParts[0] + '/api/webauthn_credentials.php';
        }
        
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
            alert('تم حذف البصمة بنجاح');
            await loadCredentials();
        } else {
            alert('خطأ: ' + (data.error || 'حدث خطأ أثناء حذف البصمة'));
        }
        
    } catch (error) {
        console.error('Error deleting credential:', error);
        alert('خطأ في الاتصال بالخادم. يرجى المحاولة مرة أخرى.');
    }
}

// تهيئة عند تحميل الصفحة
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
        console.error('simpleWebAuthn is not defined. Make sure webauthn.js is loaded.');
        if (registerBtn) {
            registerBtn.disabled = true;
            registerBtn.innerHTML = '<i class="bi bi-exclamation-triangle me-2"></i>WebAuthn غير متاح';
        }
    }
    
    // تحميل قائمة البصمات
    loadCredentials();
});
</script>

