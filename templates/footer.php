            
<?php


if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}
?>
    <!-- Footer -->
    <footer class="footer mt-auto py-3 bg-light border-top safe-area-bottom">
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-6 text-center text-md-start">
                    <small class="text-muted">
                        &copy; <?php echo date('Y'); ?> <?php echo COMPANY_NAME; ?>. <?php echo $lang['all_rights_reserved'] ?? 'جميع الحقوق محفوظة'; ?>
                    </small>
                </div>
                <div class="col-md-6 text-center text-md-end">
                    <small class="text-muted">
                        <?php
                        $appInfo = APP_NAME === COMPANY_NAME
                            ? 'v' . APP_VERSION
                            : APP_NAME . ' v' . APP_VERSION;
                        echo $appInfo;
                        ?>
                    </small>
                </div>
            </div>
        </div>
    </footer>
    
    <!-- حل شامل لحذف Cache بعد أي طلب في أي نموذج -->
    <script>
    (function() {
        'use strict';
        
        // منع تخزين الصفحة في cache عند عمل refresh
        window.addEventListener('pageshow', function(event) {
            if (event.persisted) {
                // إذا كانت الصفحة من cache، أعد تحميلها من السيرفر
                window.location.reload(true);
            }
        });
        
        // معالجة جميع النماذج لحذف cache بعد الإرسال
        function setupFormCacheBusting() {
            document.querySelectorAll('form[method="POST"]').forEach(function(form) {
                // التحقق من عدم إضافة listener مرتين
                if (form.dataset.cacheBustingSetup === 'true') {
                    return;
                }
                form.dataset.cacheBustingSetup = 'true';
                
                form.addEventListener('submit', function(e) {
                    // حفظ flag في sessionStorage أن هناك طلب جديد
                    try {
                        sessionStorage.setItem('form_submitted_' + Date.now(), 'true');
                        sessionStorage.setItem('last_form_submit_time', Date.now().toString());
                    } catch (err) {
                        // تجاهل إذا كان sessionStorage غير متاح
                    }
                    
                    // إضافة timestamp كمعامل خفي لفرض reload من السيرفر
                    const timestamp = Date.now();
                    let hasTimestampInput = false;
                    
                    // التحقق من وجود input timestamp
                    const existingInputs = form.querySelectorAll('input[type="hidden"]');
                    for (let input of existingInputs) {
                        if (input.name === '_cache_bust' || input.name === '_t' || input.name === '_nocache') {
                            input.value = timestamp;
                            hasTimestampInput = true;
                            break;
                        }
                    }
                    
                    // إضافة input timestamp إذا لم يكن موجوداً
                    if (!hasTimestampInput) {
                        const timestampInput = document.createElement('input');
                        timestampInput.type = 'hidden';
                        timestampInput.name = '_cache_bust';
                        timestampInput.value = timestamp;
                        form.appendChild(timestampInput);
                    }
                });
            });
        }
        
        // تهيئة عند تحميل الصفحة
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', setupFormCacheBusting);
        } else {
            setupFormCacheBusting();
        }
        
        // إعادة تهيئة عند إضافة نماذج ديناميكية
        if (typeof MutationObserver !== 'undefined') {
            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.addedNodes.length > 0) {
                        mutation.addedNodes.forEach(function(node) {
                            if (node.nodeType === 1) {
                                // التحقق من إضافة نماذج جديدة
                                if (node.tagName === 'FORM' && node.method === 'POST') {
                                    setupFormCacheBusting();
                                } else if (node.querySelectorAll) {
                                    const forms = node.querySelectorAll('form[method="POST"]');
                                    if (forms.length > 0) {
                                        setupFormCacheBusting();
                                    }
                                }
                            }
                        });
                    }
                });
            });
            
            observer.observe(document.body, {
                childList: true,
                subtree: true
            });
        }
        
        // عند تحميل الصفحة، التحقق من وجود طلبات حديثة
        window.addEventListener('load', function() {
            try {
                const lastSubmitTime = sessionStorage.getItem('last_form_submit_time');
                if (lastSubmitTime) {
                    const timeDiff = Date.now() - parseInt(lastSubmitTime);
                    // إذا كان الطلب منذ أقل من 30 ثانية، أزل cache parameters من URL
                    if (timeDiff < 30000) {
                        const url = new URL(window.location.href);
                        let urlChanged = false;
                        
                        ['_cache_bust', '_t', '_nocache', '_r', '_refresh', '_auto_refresh'].forEach(function(param) {
                            if (url.searchParams.has(param)) {
                                url.searchParams.delete(param);
                                urlChanged = true;
                            }
                        });
                        
                        if (urlChanged) {
                            window.history.replaceState({}, '', url.toString());
                        }
                    }
                }
            } catch (e) {
                // تجاهل
            }
        });
        
        // إضافة meta tags لمنع cache في جميع الصفحات
        if (!document.querySelector('meta[http-equiv="Cache-Control"][content*="no-cache"]')) {
            const metaCache = document.createElement('meta');
            metaCache.httpEquiv = 'Cache-Control';
            metaCache.content = 'no-cache, no-store, must-revalidate, max-age=0';
            document.head.insertBefore(metaCache, document.head.firstChild);
        }
        
        if (!document.querySelector('meta[http-equiv="Pragma"]')) {
            const metaPragma = document.createElement('meta');
            metaPragma.httpEquiv = 'Pragma';
            metaPragma.content = 'no-cache';
            document.head.appendChild(metaPragma);
        }
        
        if (!document.querySelector('meta[http-equiv="Expires"]')) {
            const metaExpires = document.createElement('meta');
            metaExpires.httpEquiv = 'Expires';
            metaExpires.content = '0';
            document.head.appendChild(metaExpires);
        }
    })();
    </script>
    
    <!-- Session Keep-Alive Script - محسّن لمنع انتهاء الجلسة -->
    <script>
    (function() {
        if (window.__sessionKeepAliveActive) {
            return; // منع التكرار
        }
        window.__sessionKeepAliveActive = true;
        
        let lastActivity = Date.now();
        const SESSION_REFRESH_INTERVAL = 5 * 60 * 1000; // 5 دقائق (تقليل الفترة لمنع الانتهاء)
        const ACTIVITY_TIMEOUT = 10 * 60 * 1000; // 10 دقائق
        
        // تتبع النشاط
        const activityEvents = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click', 'keydown'];
        let activityTimer;
        let keepAliveInterval;
        let isRefreshing = false;
        
        // حساب مسار API
        function getApiPath(endpoint) {
            const cleanEndpoint = String(endpoint || '').replace(/^\/+/, '');
            const currentPath = window.location.pathname || '/';
            const parts = currentPath.split('/').filter(Boolean);
            const stopSegments = new Set(['dashboard', 'modules', 'api', 'assets', 'includes']);
            const baseParts = [];
            
            for (const part of parts) {
                if (stopSegments.has(part) || part.endsWith('.php')) {
                    break;
                }
                baseParts.push(part);
            }
            
            const basePath = baseParts.length ? '/' + baseParts.join('/') : '';
            const apiPath = (basePath + '/' + cleanEndpoint).replace(/\/+/g, '/');
            return apiPath.startsWith('/') ? apiPath : '/' + apiPath;
        }
        
        function updateActivity() {
            lastActivity = Date.now();
            clearTimeout(activityTimer);
        }
        
        // تحديث الجلسة عبر API المخصص
        function refreshSession() {
            if (isRefreshing) {
                return; // منع الطلبات المتزامنة
            }
            
            const timeSinceActivity = Date.now() - lastActivity;
            // تحديث فقط إذا كان هناك نشاط
            if (timeSinceActivity > ACTIVITY_TIMEOUT) {
                return;
            }
            
            isRefreshing = true;
            const apiPath = getApiPath('api/session_keepalive.php');
            
            fetch(apiPath, {
                method: 'GET',
                cache: 'no-cache',
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(function(response) {
                if (!response.ok) {
                    return response.json().then(function(data) {
                        if (data && data.expired) {
                            // الجلسة انتهت - إعادة توجيه
                            const loginUrl = '/index.php';
                            if (window.location.pathname !== loginUrl) {
                                window.location.href = loginUrl;
                            }
                        }
                        throw new Error('Session refresh failed');
                    });
                }
                return response.json();
            })
            .then(function(data) {
                if (data && data.success) {
                    // تحديث ناجح
                    lastActivity = Date.now();
                }
            })
            .catch(function(error) {
                // تجاهل الأخطاء في تحديث الجلسة (لا نريد إزعاج المستخدم)
                console.log('Session keep-alive:', error.message || 'refresh skipped');
            })
            .finally(function() {
                isRefreshing = false;
            });
        }
        
        // إضافة مستمعي الأحداث للنشاط
        activityEvents.forEach(function(event) {
            document.addEventListener(event, updateActivity, { passive: true });
        });
        
        // تحديث الجلسة كل 5 دقائق
        keepAliveInterval = setInterval(function() {
            refreshSession();
        }, SESSION_REFRESH_INTERVAL);
        
        // تحديث أولي بعد تحميل الصفحة (بعد 30 ثانية)
        setTimeout(refreshSession, 30000);
        
        // تحديث قبل مغادرة الصفحة
        window.addEventListener('beforeunload', function() {
            if (keepAliveInterval) {
                clearInterval(keepAliveInterval);
            }
            // محاولة تحديث نهائي قبل المغادرة (غير متزامن)
            navigator.sendBeacon && navigator.sendBeacon(getApiPath('api/session_keepalive.php'));
        });
        
        // تحديث عند العودة للصفحة
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) {
                // تحديث فوري عند العودة
                setTimeout(refreshSession, 1000);
            }
        });
    })();
    </script>
    
    <!-- Install Banner -->
    <div class="install-banner" id="installBanner">
        <div class="d-flex align-items-center justify-content-between">
            <div class="flex-grow-1">
                <strong><i class="bi bi-download me-2"></i>تثبيت التطبيق</strong>
                <p class="mb-0 small">ثبت التطبيق للوصول السريع والاستخدام بدون إنترنت</p>
            </div>
            <button class="btn btn-light btn-sm" id="installButton">
                <i class="bi bi-plus-circle me-1"></i>تثبيت
            </button>
        </div>
        <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-2" id="dismissInstallBanner" aria-label="إغلاق"></button>
    </div>

    <div id="pwa-modal-backdrop" role="dialog" aria-modal="true" aria-hidden="true">
        <div id="pwa-modal">
            <button type="button" data-modal-close>إغلاق</button>
            <iframe src="about:blank" title="Embedded content"></iframe>
        </div>
    </div>
    
    <?php
    // استخدام نفس cache version من header.php لتحسين caching
    $cacheVersion = defined('ASSETS_VERSION') ? ASSETS_VERSION : (defined('APP_VERSION') ? APP_VERSION : '1.0.0');
    ?>
    <!-- Performance: Load jQuery with defer for better performance -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js" defer crossorigin="anonymous"></script>
    <script>
        // الانتظار حتى تحميل jQuery
        (function() {
            function initJQuery() {
                if (typeof jQuery === 'undefined' && typeof $ === 'undefined') {
                    setTimeout(initJQuery, 50);
                    return;
                }
                // التأكد من أن jQuery متاح عالمياً
                if (typeof window.jQuery === 'undefined') {
                    window.jQuery = typeof jQuery !== 'undefined' ? jQuery : (typeof $ !== 'undefined' ? $ : null);
                }
                if (typeof window.$ === 'undefined') {
                    window.$ = typeof $ !== 'undefined' ? $ : (typeof jQuery !== 'undefined' ? jQuery : null);
                }
            }
            window.addEventListener('load', function() {
                setTimeout(initJQuery, 100);
            });
        })();
    </script>
    <!-- Performance: Load Bootstrap JS with defer -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer crossorigin="anonymous"></script>
    <!-- Custom JS -->
    <?php
    // التأكد من أن ASSETS_URL صحيح
    $assetsUrl = ASSETS_URL;
    // إذا كان ASSETS_URL يبدأ بـ //، أزل /
    if (strpos($assetsUrl, '//') === 0) {
        $assetsUrl = '/' . ltrim($assetsUrl, '/');
    }
    // إذا لم يبدأ بـ /، أضفه
    if (strpos($assetsUrl, '/') !== 0) {
        $assetsUrl = '/' . $assetsUrl;
    }
    // إزالة /assets/ المكرر
    $assetsUrl = rtrim($assetsUrl, '/') . '/';
    ?>
    <?php
    // كشف الموبايل لتحسين الأداء (نفس المستخدم في header.php)
    if (!isset($isMobile)) {
        $isMobile = (bool) preg_match('/(android|iphone|ipad|ipod|blackberry|iemobile|opera mini)/i', $_SERVER['HTTP_USER_AGENT'] ?? '');
    }
    ?>
    
    <!-- Critical JS - تحميل مباشر -->
    <script src="<?php echo $assetsUrl; ?>js/main.js?v=<?php echo $cacheVersion; ?>" defer></script>
    <script src="<?php echo $assetsUrl; ?>js/sidebar.js?v=<?php echo $cacheVersion; ?>" defer></script>
    
    <!-- Medium Priority JS - تحميل مباشر -->
    <script src="<?php echo $assetsUrl; ?>js/fix-modal-interaction.js?v=<?php echo $cacheVersion; ?>" defer></script>
    <script src="<?php echo $assetsUrl; ?>js/notifications.js?v=<?php echo $cacheVersion; ?>" defer></script>
    <script src="<?php echo $assetsUrl; ?>js/image-lazy-loading.js?v=<?php echo $cacheVersion; ?>" defer></script>
    
    <!-- Low Priority JS - تحميل متأخر على الموبايل -->
    <?php if (!$isMobile): ?>
    <!-- Desktop: تحميل جميع الملفات -->
    <script src="<?php echo $assetsUrl; ?>js/tables.js?v=<?php echo $cacheVersion; ?>" defer></script>
    <script src="<?php echo $assetsUrl; ?>js/dark-mode.js?v=<?php echo $cacheVersion; ?>" defer></script>
    <script src="<?php echo $assetsUrl; ?>js/pwa-install.js?v=<?php echo $cacheVersion; ?>" defer></script>
    <script src="<?php echo $assetsUrl; ?>js/modal-link-interceptor.js?v=<?php echo $cacheVersion; ?>" defer></script>
    <script src="<?php echo $assetsUrl; ?>js/keyboard-shortcuts-global.js?v=<?php echo $cacheVersion; ?>" defer></script>
    <?php else: ?>
    <!-- Mobile: تحميل متأخر للـ JS غير الحرجة -->
    <script>
        // تحميل JS غير الحرجة بعد تحميل الصفحة على الموبايل
        window.addEventListener('load', function() {
            setTimeout(function() {
                const scripts = [
                    '<?php echo $assetsUrl; ?>js/tables.js?v=<?php echo $cacheVersion; ?>',
                    '<?php echo $assetsUrl; ?>js/dark-mode.js?v=<?php echo $cacheVersion; ?>',
                    '<?php echo $assetsUrl; ?>js/pwa-install.js?v=<?php echo $cacheVersion; ?>',
                    '<?php echo $assetsUrl; ?>js/modal-link-interceptor.js?v=<?php echo $cacheVersion; ?>'
                ];
                
                scripts.forEach(function(src) {
                    const script = document.createElement('script');
                    script.src = src;
                    script.defer = true;
                    document.body.appendChild(script);
                });
            }, 1000); // بعد ثانية من تحميل الصفحة
        });
    </script>
    <?php endif; ?>
    <script>
    // التحقق من تحميل ملفات JavaScript بشكل صحيح
    (function() {
        const scripts = document.querySelectorAll('script[src*=".js"]');
        scripts.forEach(function(script) {
            script.addEventListener('error', function() {
                console.error('Failed to load script:', script.src);
                // محاولة تحميل من مسار بديل
                const src = script.getAttribute('src');
                if (src && !src.startsWith('http')) {
                    const basePath = '<?php echo getBasePath(); ?>';
                    const fallbackSrc = (basePath ? basePath : '') + src.replace(/^\/[^\/]+/, '/assets');
                    console.warn('Trying fallback path:', fallbackSrc);
                }
            });
        });
    })();
    </script>
    
    <?php if (isset($extraScripts)): ?>
        <?php foreach ($extraScripts as $script): ?>
            <script src="<?php echo $script; ?>" defer></script>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <script>
        // تنظيف console.log في production (Best Practices)
        (function() {
            const isProduction = window.location.hostname !== 'localhost' && 
                                 window.location.hostname !== '127.0.0.1' && 
                                 !window.location.hostname.includes('.local');
            
            if (isProduction && typeof console !== 'undefined') {
                const noop = function() {};
                // الاحتفاظ بـ console.error للخطوط الحقيقية
                console.log = noop;
                console.debug = noop;
                console.info = noop;
                // console.warn و console.error تبقى كما هي للأخطاء المهمة
            }
        })();
        
        // تهيئة النظام
        document.addEventListener('DOMContentLoaded', function() {
            // تحميل العمليات الخلفية بشكل غير متزامن (بعد تحميل الصفحة)
            // هذا يحسن الأداء عن طريق تأخير العمليات الثقيلة
            setTimeout(function() {
                try {
                    // حساب مسار API
                    const currentPath = window.location.pathname;
                    const pathParts = currentPath.split('/').filter(p => p && !p.endsWith('.php'));
                    let apiPath = '/api/background-tasks.php';
                    if (pathParts.length > 0) {
                        apiPath = '/' + pathParts[0] + '/api/background-tasks.php';
                    }
                    
                    // استدعاء API للعمليات الخلفية (بدون انتظار النتيجة)
                    fetch(apiPath, {
                        method: 'GET',
                        credentials: 'same-origin',
                        cache: 'no-cache',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    }).catch(function(error) {
                        // تجاهل الأخطاء بصمت - العمليات الخلفية اختيارية
                        console.log('Background tasks skipped:', error.message);
                    });
                } catch (error) {
                    // تجاهل الأخطاء
                    console.log('Background tasks error:', error.message);
                }
            }, 2000); // بعد ثانيتين من تحميل الصفحة
            
            // إغلاق القائمة المنسدلة عند النقر على أي رابط
            const mainMenuDropdown = document.getElementById('mainMenuDropdown');
            const mainMenuDropdownMenu = document.querySelector('.main-menu-dropdown');
            
            if (mainMenuDropdown && mainMenuDropdownMenu) {
                // إغلاق القائمة عند النقر على أي رابط
                const menuLinks = mainMenuDropdownMenu.querySelectorAll('.dropdown-item');
                menuLinks.forEach(link => {
                    link.addEventListener('click', function() {
                        // إغلاق القائمة باستخدام Bootstrap
                        const dropdownInstance = bootstrap.Dropdown.getInstance(mainMenuDropdown);
                        if (dropdownInstance) {
                            dropdownInstance.hide();
                        }
                    });
                });
                
                // إغلاق القائمة عند النقر خارجها
                document.addEventListener('click', function(event) {
                    if (!mainMenuDropdown.contains(event.target) && !mainMenuDropdownMenu.contains(event.target)) {
                        const dropdownInstance = bootstrap.Dropdown.getInstance(mainMenuDropdown);
                        if (dropdownInstance && mainMenuDropdownMenu.classList.contains('show')) {
                            dropdownInstance.hide();
                        }
                    }
                });
            }
            
            // إعداد معلمات الإشعارات العالمية (محسّن للأداء)
            window.NOTIFICATION_POLL_INTERVAL = <?php echo (int) NOTIFICATION_POLL_INTERVAL; ?>;
            window.NOTIFICATION_AUTO_REFRESH_ENABLED = <?php echo NOTIFICATION_AUTO_REFRESH_ENABLED ? 'true' : 'false'; ?>;
            window.NOTIFICATION_POLL_INTERVAL = Number(window.NOTIFICATION_POLL_INTERVAL) || 30000; // 30 ثانية افتراضياً
            if (typeof loadNotifications === 'function') {
                if (!window.__notificationInitialLoadDone) {
                    loadNotifications();
                    window.__notificationInitialLoadDone = true;
                }
            }
            
            // تهيئة نظام التحقق من التحديثات
            initUpdateChecker();
        });
        
        // Register Service Worker (يتم تسجيله في header.php)
        
        // Offline Detection
        const offlineIndicator = document.getElementById('offlineIndicator');
        if (offlineIndicator) {
            window.addEventListener('online', () => {
                offlineIndicator.classList.remove('show');
            });
            
            window.addEventListener('offline', () => {
                offlineIndicator.classList.add('show');
            });
        }
        
        /**
         * نظام التحقق من التحديثات
         */
        function initUpdateChecker() {
            const STORAGE_KEY = 'app_last_version';
            const VERSION_STORAGE_KEY = 'app_display_version';
            const LAST_CHECK_KEY = 'app_last_update_check';
            const CHECK_INTERVAL = 30 * 60 * 1000; // كل 30 دقيقة
            const MIN_MANUAL_INTERVAL = 5 * 60 * 1000; // الحد الأدنى بين التحقق اليدوي
            let updateCheckInterval = null;
            let updateCheckTimeout = null;
            let isChecking = false;
            
            // حساب مسار API
            const currentPath = window.location.pathname;
            const pathParts = currentPath.split('/').filter(p => p && !p.endsWith('.php'));
            let apiPath = '/api/check_update.php';
            if (pathParts.length > 0) {
                apiPath = '/' + pathParts[0] + '/api/check_update.php';
            }
            
            /**
             * التحقق من وجود تحديثات
             */
            async function checkForUpdates() {
                if (isChecking) return;
                isChecking = true;
                
                try {
                    const response = await fetch(apiPath + '?t=' + Date.now(), {
                        method: 'GET',
                        cache: 'no-cache',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    
                    if (!response.ok) {
                        throw new Error('Failed to check for updates');
                    }
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        const currentHash = data.content_hash || data.version || data.last_modified;
                        const storedHash = localStorage.getItem(STORAGE_KEY);
                        const storedDisplay = localStorage.getItem(VERSION_STORAGE_KEY) || '';
                        const serverVersion = (data.version || '').toString().trim();
                        let displayVersion = storedDisplay || serverVersion || 'جديد';

                        if (storedHash && storedHash !== currentHash) {
                            // التحقق من عدم إظهار نفس الإشعار مؤخراً (خلال آخر ساعتين)
                            const lastNotificationKey = 'last_update_notification_' + currentHash;
                            const lastNotification = localStorage.getItem(lastNotificationKey);
                            const now = Date.now();
                            const twoHours = 2 * 60 * 60 * 1000; // ساعتين
                            
                            if (!lastNotification || (now - parseInt(lastNotification)) > twoHours) {
                                displayVersion = serverVersion || 'جديد';
                                showUpdateAvailableNotification(displayVersion);
                                localStorage.setItem(lastNotificationKey, now.toString());
                            }
                        }

                        localStorage.setItem(STORAGE_KEY, currentHash);
                        localStorage.setItem(VERSION_STORAGE_KEY, displayVersion);
                    }
                } catch (error) {
                    console.log('Update check error:', error);
                } finally {
                    try {
                        localStorage.setItem(LAST_CHECK_KEY, Date.now().toString());
                    } catch (storageError) {
                        console.log('Update check storage error:', storageError);
                    }
                    isChecking = false;
                }
            }
            
            /**
             * إظهار إشعار التحديث
             */
            function showUpdateAvailableNotification(version) {
                // التحقق من عدم وجود إشعار موجود بالفعل
                if (document.getElementById('updateNotification')) {
                    return;
                }
                
                const notification = document.createElement('div');
                notification.id = 'updateNotification';
                notification.className = 'alert alert-info alert-dismissible fade show position-fixed';
                notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; max-width: 400px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);';
                
                const displayVersion = (version || '').toString().trim() || 'جديد';
                localStorage.setItem(VERSION_STORAGE_KEY, displayVersion);
                
                notification.innerHTML = `
                    <div class="d-flex align-items-start">
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center mb-2">
                                <i class="bi bi-arrow-clockwise me-2 fs-5"></i>
                                <strong>تحديث متاح!</strong>
                            </div>
                            <p class="mb-2 small">يتوفر تحديث جديد للموقع. يرجى تحديث الصفحة للحصول على أحدث الميزات.</p>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-sm btn-primary" onclick="refreshPage()">
                                    <i class="bi bi-arrow-clockwise me-1"></i>تحديث الآن
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="dismissUpdateNotification()">
                                    لاحقاً
                                </button>
                            </div>
                        </div>
                        <button type="button" class="btn-close ms-2" onclick="dismissUpdateNotification()" aria-label="إغلاق"></button>
                    </div>
                `;
                
                document.body.appendChild(notification);
                
                // إضافة دوال عامة
                window.refreshPage = function() {
                    // إزالة cache
                    if ('caches' in window) {
                        caches.keys().then(names => {
                            names.forEach(name => {
                                caches.delete(name);
                            });
                        });
                    }
                    // تحديث الصفحة
                    window.location.reload(true);
                };
                
                window.dismissUpdateNotification = function() {
                    const notif = document.getElementById('updateNotification');
                    if (notif) {
                        notif.classList.remove('show');
                        setTimeout(() => notif.remove(), 300);
                    }
                };
                
                notification.dataset.version = version;
                
                // إزالة الإشعار تلقائياً بعد 60 ثانية
                setTimeout(() => {
                    window.dismissUpdateNotification();
                }, 60000);
            }
            
            function getLastCheckTimestamp() {
                try {
                    const raw = localStorage.getItem(LAST_CHECK_KEY);
                    const parsed = raw ? parseInt(raw, 10) : 0;
                    return Number.isFinite(parsed) ? parsed : 0;
                } catch (error) {
                    return 0;
                }
            }

            function shouldCheckNow(minInterval = CHECK_INTERVAL) {
                const lastCheck = getLastCheckTimestamp();
                if (!lastCheck) {
                    return true;
                }
                return (Date.now() - lastCheck) >= minInterval;
            }

            function scheduleBackgroundChecks() {
                if (updateCheckTimeout) {
                    clearTimeout(updateCheckTimeout);
                    updateCheckTimeout = null;
                }
                if (updateCheckInterval) {
                    clearInterval(updateCheckInterval);
                    updateCheckInterval = null;
                }

                if (shouldCheckNow()) {
                    checkForUpdates();
                    updateCheckInterval = setInterval(checkForUpdates, CHECK_INTERVAL);
                } else {
                    const lastCheck = getLastCheckTimestamp();
                    const elapsed = Date.now() - lastCheck;
                    const remaining = Math.max(CHECK_INTERVAL - elapsed, MIN_MANUAL_INTERVAL);
                    updateCheckTimeout = setTimeout(function() {
                        checkForUpdates();
                        updateCheckInterval = setInterval(checkForUpdates, CHECK_INTERVAL);
                    }, remaining);
                }
            }

            scheduleBackgroundChecks();
            
            // التحقق عند إعادة التركيز على النافذة
            window.addEventListener('focus', function() {
                if (!isChecking && shouldCheckNow(MIN_MANUAL_INTERVAL)) {
                    checkForUpdates();
                }
            });
            
            // التحقق عند الاتصال بالإنترنت
            window.addEventListener('online', function() {
                if (!isChecking && shouldCheckNow(MIN_MANUAL_INTERVAL)) {
                    setTimeout(checkForUpdates, 2000);
                }
            });
            
            // تنظيف عند إغلاق الصفحة
            window.addEventListener('beforeunload', function() {
                if (updateCheckInterval) {
                    clearInterval(updateCheckInterval);
                    updateCheckInterval = null;
                }
                if (updateCheckTimeout) {
                    clearTimeout(updateCheckTimeout);
                    updateCheckTimeout = null;
                }
            });
        }
    </script>
        
    <!-- 🚀 Performance: Prefetch and Navigation Optimization -->
    <script>
        (function() {
            'use strict';
            
            // تحسين التنقل: إضافة prefetch للروابط الشائعة عند hover
            function addPrefetchOnHover() {
                const links = document.querySelectorAll('a[href]:not([href^="#"]):not([href^="javascript:"]):not([href^="mailto:"]):not([href^="tel:"])');
                
                links.forEach(function(link) {
                    // فقط للروابط الداخلية
                    if (link.hostname === window.location.hostname || !link.hostname) {
                        let prefetchLink = null;
                        let hoverTimeout = null;
                        
                        // عند hover: prefetch بعد 100ms
                        link.addEventListener('mouseenter', function() {
                            hoverTimeout = setTimeout(function() {
                                const href = link.getAttribute('href');
                                if (href && !href.includes('#') && !document.querySelector('link[rel="prefetch"][href="' + href + '"]')) {
                                    prefetchLink = document.createElement('link');
                                    prefetchLink.rel = 'prefetch';
                                    prefetchLink.href = href;
                                    document.head.appendChild(prefetchLink);
                                }
                            }, 100);
                        });
                        
                        // إلغاء prefetch إذا تم إلغاء hover
                        link.addEventListener('mouseleave', function() {
                            if (hoverTimeout) {
                                clearTimeout(hoverTimeout);
                            }
                        });
                        
                        // عند click: إضافة preload فوري
                        link.addEventListener('click', function(e) {
                            const href = link.getAttribute('href');
                            if (href && !href.includes('#') && !link.hasAttribute('data-no-splash')) {
                                // إضافة preload للصفحة التالية
                                const preloadLink = document.createElement('link');
                                preloadLink.rel = 'preload';
                                preloadLink.as = 'document';
                                preloadLink.href = href;
                                document.head.appendChild(preloadLink);
                            }
                        }, { once: true });
                    }
                });
            }
            
            // تحسين: استخدام Intersection Observer لتحميل الصفحات مسبقاً عند اقترابها من viewport
            function addIntersectionPrefetch() {
                if (!('IntersectionObserver' in window)) {
                    return;
                }
                
                const links = document.querySelectorAll('a[href]:not([href^="#"]):not([href^="javascript:"])');
                const observer = new IntersectionObserver(function(entries) {
                    entries.forEach(function(entry) {
                        if (entry.isIntersecting) {
                            const link = entry.target;
                            const href = link.getAttribute('href');
                            
                            if (href && 
                                link.hostname === window.location.hostname && 
                                !href.includes('#') &&
                                !document.querySelector('link[rel="prefetch"][href="' + href + '"]')) {
                                
                                const prefetchLink = document.createElement('link');
                                prefetchLink.rel = 'prefetch';
                                prefetchLink.href = href;
                                document.head.appendChild(prefetchLink);
                                
                                observer.unobserve(link);
                            }
                        }
                    });
                }, {
                    rootMargin: '200px' // بدء prefetch قبل 200px من viewport
                });
                
                links.forEach(function(link) {
                    if (link.hostname === window.location.hostname || !link.hostname) {
                        observer.observe(link);
                    }
                });
            }
            
            // تحسين: استخدام requestIdleCallback لتحميل الصفحات المهمة
            function prefetchImportantPages() {
                if (!('requestIdleCallback' in window)) {
                    return;
                }
                
                requestIdleCallback(function() {
                    // الحصول على base URL من الصفحة الحالية
                    const baseUrl = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '/');
                    const currentPath = window.location.pathname;
                    let dashboardUrl = baseUrl;
                    
                    // تحديد dashboard URL بناءً على الصفحة الحالية
                    if (currentPath.includes('manager.php')) {
                        dashboardUrl = baseUrl + 'dashboard/manager.php';
                    } else if (currentPath.includes('sales.php')) {
                        dashboardUrl = baseUrl + 'dashboard/sales.php';
                    } else if (currentPath.includes('accountant.php')) {
                        dashboardUrl = baseUrl + 'dashboard/accountant.php';
                    } else if (currentPath.includes('production.php')) {
                        dashboardUrl = baseUrl + 'dashboard/production.php';
                    }
                    
                    const importantPages = [
                        dashboardUrl,
                        dashboardUrl + '?page=chat',
                        dashboardUrl + '?page=customers'
                    ];
                    
                    importantPages.forEach(function(url) {
                        if (!document.querySelector('link[rel="prefetch"][href="' + url + '"]')) {
                            const prefetchLink = document.createElement('link');
                            prefetchLink.rel = 'prefetch';
                            prefetchLink.href = url;
                            document.head.appendChild(prefetchLink);
                        }
                    });
                }, { timeout: 2000 });
            }
            
            // تشغيل التحسينات بعد تحميل الصفحة
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    addPrefetchOnHover();
                    addIntersectionPrefetch();
                    prefetchImportantPages();
                });
            } else {
                addPrefetchOnHover();
                addIntersectionPrefetch();
                prefetchImportantPages();
            }
        })();
    </script>
    
    <?php if (isset($currentUser) && ($currentUser['role'] ?? '') === 'manager'): ?>
    <script>
    /**
     * تحديث عداد الموافقات المعلقة للمديرين
     */
    (function() {
        // التحقق من أن المستخدم مدير قبل تشغيل الكود
        const currentUserRole = '<?php echo $currentUser['role'] ?? ''; ?>';
        if (currentUserRole !== 'manager') {
            // المستخدم ليس مدير - لا نحتاج لتحديث العداد
            return;
        }
        
        async function updateApprovalBadge() {
            try {
                const badge = document.getElementById('approvalBadge');
                if (!badge) {
                    return;
                }
                
                const basePath = '<?php echo getBasePath(); ?>';
                const apiPath = basePath + '/api/approvals.php';
                const response = await fetch(apiPath, {
                    credentials: 'same-origin',
                    cache: 'no-cache',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                
                // التعامل مع 403 (Forbidden) بشكل صحيح - هذا ليس خطأ في الجلسة
                if (response.status === 403) {
                    // المستخدم ليس مدير - إخفاء العداد
                    if (badge) {
                        badge.style.display = 'none';
                    }
                    return;
                }
                
                if (!response.ok) {
                    // تجاهل الأخطاء الأخرى (401, 500, etc.) - لا نريد إزعاج المستخدم
                    return;
                }
                
                // التحقق من content-type قبل parse JSON
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    console.warn('updateApprovalBadge: Expected JSON but got', contentType);
                    return;
                }
                
                const text = await response.text();
                if (!text || text.trim().startsWith('<')) {
                    console.warn('updateApprovalBadge: Received HTML instead of JSON');
                    return;
                }
                
                let data;
                try {
                    data = JSON.parse(text);
                } catch (parseError) {
                    console.warn('updateApprovalBadge: Failed to parse JSON:', parseError);
                    return;
                }
                
                if (data && data.success && typeof data.count === 'number') {
                    const count = Math.max(0, parseInt(data.count, 10));
                    if (badge) {
                        badge.textContent = count.toString();
                        if (badge.style) {
                            if (count > 0) {
                                badge.style.display = 'inline-block';
                                badge.classList.add('badge-danger', 'bg-danger');
                            } else {
                                badge.style.display = 'none';
                            }
                        }
                    }
                }
            } catch (error) {
                // تجاهل الأخطاء بصمت لتجنب إزعاج المستخدم
                if (error.name !== 'SyntaxError') {
                    console.error('Error updating approval badge:', error);
                }
            }
        }
        
        // تحديث العداد عند تحميل الصفحة
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                updateApprovalBadge();
                // تحديث العداد كل 30 ثانية
                setInterval(updateApprovalBadge, 30000);
            });
        } else {
            updateApprovalBadge();
            // تحديث العداد كل 30 ثانية
            setInterval(updateApprovalBadge, 30000);
        }
        
        // تحديث العداد عند استلام حدث
        document.addEventListener('approvalUpdated', function() {
            setTimeout(updateApprovalBadge, 1000);
        });
    })();
    </script>
    <?php endif; ?>
        </main>
    </div>
</body>
</html>

