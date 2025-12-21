            
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
        
        // منع تخزين الصفحة في cache عند عمل refresh - محسّن لمنع Error Code: -2
        window.addEventListener('pageshow', function(event) {
            if (event.persisted) {
                // إذا كانت الصفحة من cache، أعد تحميلها من السيرفر
                // استخدام طريقة آمنة لمنع Error Code: -2
                try {
                    // إضافة timestamp للـ URL لفرض reload من السيرفر
                    const url = new URL(window.location.href);
                    url.searchParams.set('_refresh', Date.now().toString());
                    
                    // استخدام replaceState أولاً ثم reload
                    window.history.replaceState({}, '', url.toString());
                    
                    // استخدام setTimeout لمنع مشاكل التوقيت
                    setTimeout(function() {
                        // استخدام location.href بدلاً من reload(true) لمنع Error Code: -2
                        window.location.href = url.toString();
                    }, 50);
                } catch (e) {
                    // في حالة الخطأ، استخدم reload عادي
                    console.warn('Error in pageshow handler, using fallback:', e);
                    setTimeout(function() {
                        window.location.reload();
                    }, 50);
                }
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
        const SESSION_REFRESH_INTERVAL = 15 * 60 * 1000; // 15 دقيقة (زيادة لتقليل الضغط على السيرفر)
        const ACTIVITY_TIMEOUT = 15 * 60 * 1000; // 15 دقائق (زيادة من 10 دقائق)
        
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
        let consecutiveFailures = 0;
        const MAX_CONSECUTIVE_FAILURES = 3;
        
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
            
            // إضافة timeout للطلب (5 ثواني فقط - يجب أن يكون سريعاً)
            const controller = new AbortController();
            const timeoutId = setTimeout(function() {
                controller.abort();
            }, 5000);
            
            fetch(apiPath, {
                method: 'GET',
                cache: 'no-cache',
                credentials: 'same-origin',
                signal: controller.signal,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Cache-Control': 'no-cache'
                }
            })
            .then(function(response) {
                clearTimeout(timeoutId);
                if (!response.ok) {
                    return response.json().then(function(data) {
                        if (data && data.expired) {
                            // الجلسة انتهت - إعادة توجيه مع تنظيف URL
                            consecutiveFailures = 0;
                            const loginUrl = getApiPath('index.php').split('?')[0];
                            // إزالة جميع معاملات _nocache و _refresh من URL
                            const cleanUrl = loginUrl.replace(/[?&](_nocache|_refresh|_cache_bust|_t|_r|_auto_refresh)=\d+/g, '');
                            // استخدام replace بدلاً من href لتجنب ERR_FAILED
                            if (window.location.pathname !== cleanUrl.split('?')[0]) {
                                window.location.replace(cleanUrl);
                            }
                            return;
                        }
                        throw new Error('Session refresh failed: ' + (data.message || 'Unknown error'));
                    }).catch(function() {
                        throw new Error('Session refresh failed: HTTP ' + response.status);
                    });
                }
                return response.json();
            })
            .then(function(data) {
                if (data && data.success) {
                    // تحديث ناجح - إعادة تعيين عداد الفشل
                    consecutiveFailures = 0;
                    lastActivity = Date.now();
                } else {
                    consecutiveFailures++;
                }
            })
            .catch(function(error) {
                clearTimeout(timeoutId);
                consecutiveFailures++;
                
                // إذا فشل الطلب بسبب network error أو timeout
                if (error.name === 'AbortError' || error.message.includes('Failed to fetch') || error.message.includes('NetworkError') || error.message.includes('ERR_FAILED')) {
                    // لا نسجل الخطأ في console لتقليل الضغط
                    // console.log('Session keep-alive: Network error or timeout - ' + (error.message || 'Unknown'));
                    
                    // إذا فشل 3 مرات متتالية، تحقق من حالة الجلسة
                    if (consecutiveFailures >= MAX_CONSECUTIVE_FAILURES) {
                        console.warn('Session keep-alive: Multiple consecutive failures detected. Checking session status...');
                        // محاولة تحميل صفحة بسيطة للتحقق من الاتصال
                        checkConnection();
                        
                        // إعادة تعيين العداد بعد فحص الاتصال لمنع التكرار المفرط
                        setTimeout(function() {
                            if (consecutiveFailures >= MAX_CONSECUTIVE_FAILURES) {
                                consecutiveFailures = Math.floor(MAX_CONSECUTIVE_FAILURES / 2); // تقليل العداد تدريجياً
                            }
                        }, 30000); // بعد 30 ثانية
                    }
                } else {
                    // لا نسجل الأخطاء الروتينية
                    // console.log('Session keep-alive error:', error.message || 'refresh skipped');
                }
            })
            .finally(function() {
                isRefreshing = false;
            });
        }
        
        // التحقق من الاتصال والجلسة
        function checkConnection() {
            const checkUrl = getApiPath('index.php').split('?')[0];
            const controller = new AbortController();
            const timeoutId = setTimeout(function() {
                controller.abort();
            }, 5000); // 5 ثواني timeout
            
            fetch(checkUrl, {
                method: 'HEAD',
                cache: 'no-cache',
                credentials: 'same-origin',
                signal: controller.signal
            })
            .then(function(response) {
                clearTimeout(timeoutId);
                // إذا نجح الطلب، إعادة تعيين العداد
                consecutiveFailures = 0;
            })
            .catch(function(error) {
                clearTimeout(timeoutId);
                // إذا فشل الطلب، قد تكون هناك مشكلة في الاتصال
                // لا نعيد التوجيه تلقائياً - فقط نسجل التحذير
                // الجلسة ستبقى نشطة حتى لو فشلت طلبات keep-alive
                console.warn('Connection check failed:', error.message);
                // لا نعيد التوجيه - نترك الجلسة نشطة حتى لو فشلت طلبات keep-alive
                // إعادة التوجيه ستحدث فقط عند تسجيل الخروج الفعلي أو انتهاء الجلسة في قاعدة البيانات
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
        
        // تحديث أولي بعد تحميل الصفحة (بعد 60 ثانية - تقليل الضغط على السيرفر)
        setTimeout(refreshSession, 60000);
        
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
            // Background tasks polling interval (stored globally for access by stopAllPolling)
            window.backgroundTasksInterval = null;
            
            function executeBackgroundTasks() {
                // Skip if page is hidden
                if (document.hidden) {
                    return;
                }
                
                try {
                    // Use getApiPath helper if available, otherwise construct path
                    const apiPath = typeof getApiPath === 'function' 
                        ? getApiPath('api/background-tasks.php')
                        : '/api/background-tasks.php';
                    
                    // Call background tasks API
                    fetch(apiPath, {
                        method: 'GET',
                        credentials: 'same-origin',
                        cache: 'no-cache',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(function(response) {
                        // Check for session expiration (401)
                        if (response.status === 401) {
                            // Session expired - stop all polling
                            if (window.backgroundTasksInterval) {
                                clearInterval(window.backgroundTasksInterval);
                                window.backgroundTasksInterval = null;
                            }
                            
                            // Stop all polling globally
                            if (typeof stopAllPolling === 'function') {
                                stopAllPolling();
                            }
                            
                            // Trigger session expiration handler
                            if (typeof handleSessionStatus === 'function') {
                                handleSessionStatus(401, response.url, apiPath);
                            }
                            return;
                        }
                        
                        // Parse response if successful
                        if (response.ok) {
                            return response.json().catch(function() {
                                return { success: false };
                            });
                        }
                        
                        return { success: false };
                    })
                    .then(function(data) {
                        // Check if response indicates session expired
                        if (data && data.status === 'expired') {
                            // Stop polling
                            if (window.backgroundTasksInterval) {
                                clearInterval(window.backgroundTasksInterval);
                                window.backgroundTasksInterval = null;
                            }
                            
                            // Stop all polling globally
                            if (typeof stopAllPolling === 'function') {
                                stopAllPolling();
                            }
                            
                            // Trigger session expiration handler
                            if (typeof handleSessionStatus === 'function') {
                                handleSessionStatus(401);
                            }
                        }
                    })
                    .catch(function(error) {
                        // Only log non-network errors (network errors are normal for background tasks)
                        if (error.name !== 'TypeError' && !error.message.includes('fetch')) {
                            safeLog('Background tasks error:', error.message);
                        }
                    });
                } catch (error) {
                    safeLog('Background tasks exception:', error.message);
                }
            }
            
                // Execute first time after 5 seconds
            setTimeout(function() {
                executeBackgroundTasks();
                
                // Set up polling every 5 minutes (300000ms) if session is still valid
                // This will be cleared if session expires
                window.backgroundTasksInterval = setInterval(function() {
                    executeBackgroundTasks();
                }, 300000); // 5 minutes
            }, 5000);
            
            // Stop polling when page is hidden or unloaded
            document.addEventListener('visibilitychange', function() {
                if (document.hidden && window.backgroundTasksInterval) {
                    clearInterval(window.backgroundTasksInterval);
                    window.backgroundTasksInterval = null;
                }
            });
            
            window.addEventListener('beforeunload', function() {
                if (window.backgroundTasksInterval) {
                    clearInterval(window.backgroundTasksInterval);
                    window.backgroundTasksInterval = null;
                }
            });
            
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
            // محدود للروابط المهمة فقط لتجنب الطلبات الكثيرة
            function addPrefetchOnHover() {
                // فقط الروابط المهمة (روابط التنقل الرئيسية)
                const importantSelectors = [
                    '.navbar a[href]',
                    '.nav-link[href]',
                    '.nav-item a[href]',
                    '.sidebar a[href]',
                    '[role="navigation"] a[href]'
                ];
                
                let importantLinks = [];
                importantSelectors.forEach(function(selector) {
                    const links = document.querySelectorAll(selector);
                    importantLinks = importantLinks.concat(Array.from(links));
                });
                
                // إزالة التكرارات
                importantLinks = Array.from(new Set(importantLinks));
                
                // تحديد عدد max للروابط التي يمكن عمل prefetch لها عند hover
                let hoverPrefetchCount = 0;
                const MAX_HOVER_PREFETCH = 5;
                
                importantLinks.forEach(function(link) {
                    // فقط للروابط الداخلية
                    if (link.hostname === window.location.hostname || !link.hostname) {
                        let prefetchLink = null;
                        let hoverTimeout = null;
                        
                        // عند hover: prefetch بعد 200ms (زيادة من 100ms لتقليل الطلبات)
                        link.addEventListener('mouseenter', function() {
                            // التحقق من الحد الأقصى
                            if (hoverPrefetchCount >= MAX_HOVER_PREFETCH) {
                                return;
                            }
                            
                            hoverTimeout = setTimeout(function() {
                                const href = link.getAttribute('href');
                                
                                // التحقق من أن الرابط ليس logout أو API
                                if (href && 
                                    !href.includes('#') && 
                                    !href.includes('logout') &&
                                    !href.includes('api/') &&
                                    !document.querySelector('link[rel="prefetch"][href="' + href + '"]')) {
                                    
                                    prefetchLink = document.createElement('link');
                                    prefetchLink.rel = 'prefetch';
                                    prefetchLink.href = href;
                                    document.head.appendChild(prefetchLink);
                                    
                                    hoverPrefetchCount++;
                                }
                            }, 200);
                        });
                        
                        // إلغاء prefetch إذا تم إلغاء hover
                        link.addEventListener('mouseleave', function() {
                            if (hoverTimeout) {
                                clearTimeout(hoverTimeout);
                                hoverTimeout = null;
                            }
                        });
                        
                        // عند click: إضافة preload فوري (محدود)
                        link.addEventListener('click', function(e) {
                            const href = link.getAttribute('href');
                            if (href && 
                                !href.includes('#') && 
                                !href.includes('logout') &&
                                !href.includes('api/') &&
                                !link.hasAttribute('data-no-splash')) {
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
            // محدود للروابط المهمة فقط لتجنب طلبات كثيرة
            function addIntersectionPrefetch() {
                if (!('IntersectionObserver' in window)) {
                    return;
                }
                
                // فقط الروابط المهمة (روابط التنقل الرئيسية، وليس كل الروابط)
                const importantSelectors = [
                    '.navbar a[href]',
                    '.nav-link[href]',
                    '.nav-item a[href]',
                    '.sidebar a[href]',
                    '[role="navigation"] a[href]'
                ];
                
                let importantLinks = [];
                importantSelectors.forEach(function(selector) {
                    const links = document.querySelectorAll(selector);
                    importantLinks = importantLinks.concat(Array.from(links));
                });
                
                // إزالة التكرارات
                importantLinks = Array.from(new Set(importantLinks));
                
                // تحديد عدد max للروابط التي يمكن عمل prefetch لها (لتجنب الطلبات الكثيرة)
                const MAX_PREFETCH_LINKS = 10;
                
                const observer = new IntersectionObserver(function(entries) {
                    let prefetchedCount = 0;
                    
                    entries.forEach(function(entry) {
                        if (prefetchedCount >= MAX_PREFETCH_LINKS) {
                            return; // توقف عند الوصول للحد الأقصى
                        }
                        
                        if (entry.isIntersecting) {
                            const link = entry.target;
                            const href = link.getAttribute('href');
                            
                            // فقط للروابط الداخلية والمهمة
                            if (href && 
                                (link.hostname === window.location.hostname || !link.hostname) && 
                                !href.includes('#') &&
                                !href.includes('logout') &&
                                !href.includes('api/') &&
                                !document.querySelector('link[rel="prefetch"][href="' + href + '"]')) {
                                
                                // تحقق من أن الرابط ليس صفحة حالية
                                const currentPath = window.location.pathname;
                                const linkPath = new URL(href, window.location.origin).pathname;
                                if (linkPath === currentPath) {
                                    observer.unobserve(link);
                                    return;
                                }
                                
                                const prefetchLink = document.createElement('link');
                                prefetchLink.rel = 'prefetch';
                                prefetchLink.href = href;
                                document.head.appendChild(prefetchLink);
                                
                                prefetchedCount++;
                                observer.unobserve(link);
                            }
                        }
                    });
                }, {
                    rootMargin: '150px' // تقليل من 200px إلى 150px
                });
                
                // مراقبة فقط الروابط المهمة
                importantLinks.forEach(function(link) {
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
                    let baseUrl = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '/');
                    const currentPath = window.location.pathname;
                    
                    // إزالة 'dashboard/' من baseUrl إذا كان موجوداً لمنع تكرار dashboard/dashboard
                    if (baseUrl.endsWith('/dashboard/')) {
                        baseUrl = baseUrl.replace(/\/dashboard\/$/, '/');
                    }
                    
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
                // تحديث العداد كل 2 دقيقة (120 ثانية) لتقليل الاستهلاك
                setInterval(function() {
                    if (!document.hidden) {
                        updateApprovalBadge();
                    }
                }, 120000);
            });
        } else {
            updateApprovalBadge();
            // تحديث العداد كل 2 دقيقة (120 ثانية) لتقليل الاستهلاك
            setInterval(function() {
                if (!document.hidden) {
                    updateApprovalBadge();
                }
            }, 120000);
        }
        
        // تحديث العداد عند استلام حدث
        document.addEventListener('approvalUpdated', function() {
            setTimeout(updateApprovalBadge, 1000);
        });
    })();
    </script>
    <?php endif; ?>
    
    <!-- Error Handler: منع عرض ERR_FAILED وإعادة التوجيه تلقائياً -->
    <script>
    (function() {
        'use strict';
        
        // منع التكرار
        if (window.__errorHandlerActive) {
            return;
        }
        window.__errorHandlerActive = true;
        
        // متغير لتتبع حالة التنقل
        let isNavigating = false;
        let navigationStartTime = 0;
        const NAVIGATION_TIMEOUT = 10000; // 10 ثوانٍ
        
        // تتبع النقرات على روابط الشريط الجانبي
        document.addEventListener('click', function(e) {
            const link = e.target.closest('a');
            if (link && link.href && !link.href.includes('#') && !link.href.includes('javascript:')) {
                // التحقق من أن الرابط من نفس النطاق
                try {
                    const linkUrl = new URL(link.href, window.location.origin);
                    const currentUrl = new URL(window.location.href);
                    
                    if (linkUrl.origin === currentUrl.origin && 
                        !linkUrl.pathname.includes('/api/') &&
                        (link.classList.contains('nav-link') || link.closest('.homeline-sidebar'))) {
                        // هذا رابط من الشريط الجانبي - تعيين flag التنقل
                        isNavigating = true;
                        navigationStartTime = Date.now();
                        
                        // إزالة flag بعد timeout
                        setTimeout(function() {
                            if (Date.now() - navigationStartTime >= NAVIGATION_TIMEOUT) {
                                isNavigating = false;
                            }
                        }, NAVIGATION_TIMEOUT);
                    }
                } catch (urlError) {
                    // تجاهل أخطاء URL parsing
                }
            }
        }, true);
        
        // إزالة flag عند اكتمال تحميل الصفحة
        window.addEventListener('load', function() {
            setTimeout(function() {
                isNavigating = false;
            }, 2000); // إزالة flag بعد ثانيتين من تحميل الصفحة
        });
        
        // تتبع التنقل عبر أزرار المتصفح (back/forward)
        window.addEventListener('popstate', function() {
            isNavigating = true;
            navigationStartTime = Date.now();
            setTimeout(function() {
                if (Date.now() - navigationStartTime >= NAVIGATION_TIMEOUT) {
                    isNavigating = false;
                }
            }, NAVIGATION_TIMEOUT);
        });
        
        // تتبع تغيير URL (للتنقل البرمجي)
        let urlCheckInterval = setInterval(function() {
            const currentUrl = window.location.href;
            if (window.__lastCheckedUrl && window.__lastCheckedUrl !== currentUrl) {
                // URL تغير - قد يكون تنقل
                isNavigating = true;
                navigationStartTime = Date.now();
                setTimeout(function() {
                    if (Date.now() - navigationStartTime >= NAVIGATION_TIMEOUT) {
                        isNavigating = false;
                    }
                }, NAVIGATION_TIMEOUT);
            }
            window.__lastCheckedUrl = currentUrl;
        }, 1000);
        
        // تنظيف عند إغلاق الصفحة
        window.addEventListener('beforeunload', function() {
            if (urlCheckInterval) {
                clearInterval(urlCheckInterval);
            }
        });
        
        // حساب مسار صفحة تسجيل الدخول
        function getLoginUrl() {
            const currentPath = window.location.pathname || '/';
            const pathParts = currentPath.split('/').filter(p => p && !p.endsWith('.php'));
            const basePath = pathParts.length ? '/' + pathParts[0] : '';
            return basePath ? basePath + '/index.php' : '/index.php';
        }
        
        // إعادة التوجيه إلى صفحة تسجيل الدخول
        function redirectToLogin() {
            const loginUrl = getLoginUrl();
            // استخدام replace بدلاً من href لمنع إضافة صفحة إلى history
            window.location.replace(loginUrl);
        }
        
        // التحقق من حالة الصفحة
        function checkPageStatus() {
            // إذا كان التنقل قيد التقدم، لا نتحقق من حالة الصفحة
            if (isNavigating) {
                return;
            }
            
            // التحقق من أن الصفحة تم تحميلها بشكل صحيح
            if (document.readyState === 'complete') {
                // استثناء صفحات معينة من التحقق (مثل tasks.php التي قد تستغرق وقتاً في التحميل)
                const currentUrl = window.location.href || '';
                const currentPath = window.location.pathname || '';
                
                // قائمة الصفحات المستثناة من التحقق التلقائي
                const excludedPages = [
                    'tasks.php',
                    'page=tasks',
                    'production.php?page=tasks',
                    'manager.php?page=tasks',
                    'index.php',
                    'login'
                ];
                
                // إذا كانت الصفحة الحالية في قائمة الاستثناءات، لا نتحقق منها
                const isExcluded = excludedPages.some(page => 
                    currentUrl.includes(page) || currentPath.includes(page)
                );
                
                if (isExcluded) {
                    return; // لا نتحقق من هذه الصفحة
                }
                
                // التحقق من وجود محتوى أساسي في الصفحة
                const mainContent = document.getElementById('main-content') || document.querySelector('main') || document.body;
                
                // تحسين المنطق: التحقق من وجود محتوى فعلي وليس فقط عدد العناصر
                const hasContent = mainContent && (
                    mainContent.children.length > 0 || 
                    mainContent.innerHTML.trim().length > 500 || // زيادة الحد الأدنى من 100 إلى 500
                    document.querySelector('.container-fluid') ||
                    document.querySelector('.card') ||
                    document.querySelector('table') ||
                    document.querySelector('form')
                );
                
                if (!hasContent && document.body.innerHTML.trim().length < 500) {
                    // الصفحة فارغة أو لم يتم تحميلها - إعادة التوجيه
                    // لكن فقط إذا لم يكن التنقل قيد التقدم
                    if (!isNavigating) {
                        console.warn('Page appears empty or failed to load - redirecting to login');
                        redirectToLogin();
                    }
                    return;
                }
            }
        }
        
        // معالجة أخطاء التحميل
        window.addEventListener('error', function(event) {
            // التحقق من أخطاء الشبكة أو التحميل
            if (event.target && (event.target.tagName === 'SCRIPT' || event.target.tagName === 'LINK')) {
                const src = event.target.src || event.target.href || '';
                // إذا كان الخطأ في تحميل ملف مهم (مثل main.js أو header)
                if (src.includes('.js') || src.includes('.css')) {
                    console.warn('Failed to load resource:', src);
                    // لا نعيد التوجيه فوراً - قد يكون خطأ مؤقت
                }
            }
        }, true);
        
        // معالجة أخطاء Promise غير المعالجة
        window.addEventListener('unhandledrejection', function(event) {
            const error = event.reason;
            if (error && typeof error === 'object') {
                const errorMessage = error.message || error.toString() || '';
                // التحقق من أخطاء الشبكة أو ERR_FAILED
                if (errorMessage.includes('ERR_FAILED') || 
                    errorMessage.includes('Failed to fetch') || 
                    errorMessage.includes('NetworkError') ||
                    errorMessage.includes('Load failed')) {
                    console.warn('Network error detected:', errorMessage);
                    
                    // استثناء صفحات tasks من إعادة التوجيه التلقائية
                    const currentUrl = window.location.href || '';
                    const isTasksPage = currentUrl.includes('tasks.php') || 
                                       currentUrl.includes('page=tasks') ||
                                       currentUrl.includes('production.php?page=tasks') ||
                                       currentUrl.includes('manager.php?page=tasks');
                    
                    if (!isTasksPage) {
                        // إعادة التوجيه بعد تأخير قصير (فقط للصفحات غير tasks)
                        setTimeout(redirectToLogin, 1000);
                    } else {
                        console.warn('Tasks page detected - skipping auto-redirect');
                    }
                }
            }
        });
        
        // التحقق من حالة الاتصال
        window.addEventListener('online', function() {
            // عند الاتصال بالإنترنت، تحقق من حالة الصفحة بعد تأخير أطول
            setTimeout(checkPageStatus, 5000);
        });
        
        window.addEventListener('offline', function() {
            // عند انقطاع الاتصال، لا نعيد التوجيه فوراً
            // سننتظر حتى يعود الاتصال
        });
        
        // التحقق من حالة الصفحة بعد التحميل
        // زيادة التأخير لمنح الصفحات وقتاً أطول للتحميل (خاصة tasks.php)
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                // تأخير أطول (8 ثوانٍ) لمنح الصفحات وقتاً كافياً للتحميل بعد التنقل
                setTimeout(checkPageStatus, 8000);
            });
        } else {
            setTimeout(checkPageStatus, 8000);
        }
        
        // التحقق من حالة الصفحة بعد تحميلها بالكامل
        window.addEventListener('load', function() {
            // تأخير أطول (5 ثوانٍ) لمنح الصفحات وقتاً كافياً للتحميل بعد التنقل
            setTimeout(checkPageStatus, 5000);
        });
        
        // معالجة أخطاء fetch (للطلبات AJAX)
        const originalFetch = window.fetch;
        window.fetch = function(...args) {
            return originalFetch.apply(this, args)
                .catch(function(error) {
                    // التحقق من أخطاء ERR_FAILED
                    if (error && (error.message && (
                        error.message.includes('ERR_FAILED') ||
                        error.message.includes('Failed to fetch') ||
                        error.message.includes('NetworkError')
                    ))) {
                        console.warn('Fetch error detected:', error.message);
                        // إذا كان الخطأ في طلب مهم (مثل session check)، أعد التوجيه
                        const url = args[0] || '';
                        if (typeof url === 'string' && (
                            url.includes('check_session') ||
                            url.includes('session_keepalive') ||
                            url.includes('isLoggedIn')
                        )) {
                            // استثناء صفحات tasks من إعادة التوجيه التلقائية
                            const currentUrl = window.location.href || '';
                            const isTasksPage = currentUrl.includes('tasks.php') || 
                                               currentUrl.includes('page=tasks') ||
                                               currentUrl.includes('production.php?page=tasks') ||
                                               currentUrl.includes('manager.php?page=tasks');
                            
                            if (!isTasksPage) {
                                setTimeout(redirectToLogin, 1000);
                            } else {
                                console.warn('Tasks page detected - skipping auto-redirect for fetch error');
                            }
                        }
                    }
                    throw error;
                });
        };
        
        // مراقبة تغييرات الصفحة (للتحقق من أخطاء التوجيه) - تقليل التكرار
        let lastUrl = window.location.href;
        setInterval(function() {
            // إذا كان التنقل قيد التقدم، لا نتحقق
            if (isNavigating) {
                return;
            }
            
            const currentUrl = window.location.href;
            // إذا تغيرت الصفحة إلى صفحة خطأ أو ERR_FAILED
            if (currentUrl !== lastUrl) {
                lastUrl = currentUrl;
                // التحقق من أن الصفحة الحالية ليست صفحة تسجيل الدخول
                if (!currentUrl.includes('index.php') && !currentUrl.includes('login')) {
                    // استثناء صفحات tasks من التحقق التلقائي
                    const isTasksPage = currentUrl.includes('tasks.php') || 
                                       currentUrl.includes('page=tasks') ||
                                       currentUrl.includes('production.php?page=tasks') ||
                                       currentUrl.includes('manager.php?page=tasks');
                    
                    if (!isTasksPage) {
                        // التحقق من أن الصفحة تم تحميلها بشكل صحيح (بعد تأخير أطول)
                        // زيادة التأخير لمنح الصفحة وقتاً كافياً للتحميل
                        setTimeout(checkPageStatus, 8000);
                    }
                }
            }
        }, 15000); // 15 ثانية بدلاً من 10 ثوانٍ لتقليل الاستخدام
        
        // معالجة أخطاء XMLHttpRequest (للتوافق مع الكود القديم)
        if (window.XMLHttpRequest) {
            const originalOpen = XMLHttpRequest.prototype.open;
            const originalSend = XMLHttpRequest.prototype.send;
            
            XMLHttpRequest.prototype.open = function(method, url, ...args) {
                this._url = url;
                return originalOpen.apply(this, [method, url, ...args]);
            };
            
            XMLHttpRequest.prototype.send = function(...args) {
                this.addEventListener('error', function() {
                    const url = this._url || '';
                    if (url.includes('check_session') || url.includes('session_keepalive')) {
                        // استثناء صفحات tasks من إعادة التوجيه التلقائية
                        const currentUrl = window.location.href || '';
                        const isTasksPage = currentUrl.includes('tasks.php') || 
                                           currentUrl.includes('page=tasks') ||
                                           currentUrl.includes('production.php?page=tasks') ||
                                           currentUrl.includes('manager.php?page=tasks');
                        
                        if (!isTasksPage) {
                            console.warn('XHR error for session check - redirecting to login');
                            setTimeout(redirectToLogin, 1000);
                        } else {
                            console.warn('Tasks page detected - skipping auto-redirect for XHR error');
                        }
                    }
                });
                
                return originalSend.apply(this, args);
            };
        }
    })();
    </script>
    
    <!-- Maintenance Mode Modal -->
    <div class="modal fade" id="maintenanceModeModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-labelledby="maintenanceModeModalLabel" aria-hidden="true" style="z-index: 9999;">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="maintenanceModeModalLabel">
                        <i class="bi bi-tools me-2"></i>وضع الصيانة
                    </h5>
                </div>
                <div class="modal-body text-center py-4">
                    <div class="mb-3">
                        <i class="bi bi-exclamation-triangle-fill text-warning" style="font-size: 3rem;"></i>
                    </div>
                    <h5 class="mb-3">التطبيق تحت الصيانة</h5>
                    <p class="text-muted mb-0">التطبيق تحت الصيانة في الوقت الحالي برجاء إعادة المحاولة في وقت لاحق</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Maintenance Mode Overlay -->
    <div id="maintenanceModeOverlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.7); z-index: 9998; pointer-events: all;"></div>
    
    <style>
        /* منع التفاعلات مع المحتوى عند وضع الصيانة */
        body.maintenance-mode-active {
            overflow: hidden;
            pointer-events: none;
        }
        
        body.maintenance-mode-active #maintenanceModeModal {
            pointer-events: all;
        }
        
        body.maintenance-mode-active #maintenanceModeOverlay {
            display: block !important;
        }
        
        /* إخفاء جميع العناصر التفاعلية عند وضع الصيانة */
        body.maintenance-mode-active * {
            pointer-events: none !important;
        }
        
        body.maintenance-mode-active #maintenanceModeModal,
        body.maintenance-mode-active #maintenanceModeModal * {
            pointer-events: all !important;
        }
    </style>
    
    <script>
    (function() {
        'use strict';
        
        // دالة للتحقق من وضع الصيانة
        function checkMaintenanceMode() {
            // التحقق أولاً من API (الأكثر دقة)
            fetch(getApiPath('api/check_maintenance_mode.php'))
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.maintenance_mode === 'on' && !data.is_developer) {
                        // وضع الصيانة مفعّل والمستخدم ليس مطوراً
                        showMaintenanceModal();
                    } else {
                        // وضع الصيانة معطل أو المستخدم مطور - إخفاء Modal
                        hideMaintenanceModal();
                        // تنظيف session إذا كان مطوراً
                        if (data.is_developer && data.success) {
                            // المطور لديه وصول - إزالة علامة وضع الصيانة من session
                            fetch(getApiPath('api/clear_maintenance_session.php')).catch(() => {});
                        }
                    }
                })
                .catch(error => {
                    console.warn('Error checking maintenance mode:', error);
                    // في حالة الخطأ، التحقق من session كبديل (لكن فقط إذا لم يكن مطوراً)
                    <?php 
                    $currentUser = getCurrentUser();
                    $isDev = isset($currentUser['role']) && strtolower($currentUser['role']) === 'developer';
                    if (!$isDev && session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['maintenance_mode']) && $_SESSION['maintenance_mode']): 
                    ?>
                    showMaintenanceModal();
                    <?php else: ?>
                    hideMaintenanceModal();
                    <?php endif; ?>
                });
        }
        
        // عرض Modal وضع الصيانة
        function showMaintenanceModal() {
            const modal = document.getElementById('maintenanceModeModal');
            const overlay = document.getElementById('maintenanceModeOverlay');
            const body = document.body;
            
            if (modal && overlay) {
                body.classList.add('maintenance-mode-active');
                overlay.style.display = 'block';
                
                // استخدام Bootstrap Modal إذا كان متاحاً
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    const bsModal = new bootstrap.Modal(modal, {
                        backdrop: 'static',
                        keyboard: false
                    });
                    bsModal.show();
                } else {
                    // Fallback: عرض Modal يدوياً
                    modal.style.display = 'block';
                    modal.classList.add('show');
                    modal.setAttribute('aria-hidden', 'false');
                    document.body.classList.add('modal-open');
                    const modalBackdrop = document.createElement('div');
                    modalBackdrop.className = 'modal-backdrop fade show';
                    modalBackdrop.id = 'maintenanceModalBackdrop';
                    document.body.appendChild(modalBackdrop);
                }
            }
        }
        
        // إخفاء Modal وضع الصيانة
        function hideMaintenanceModal() {
            const modal = document.getElementById('maintenanceModeModal');
            const overlay = document.getElementById('maintenanceModeOverlay');
            const body = document.body;
            
            if (modal && overlay) {
                body.classList.remove('maintenance-mode-active');
                overlay.style.display = 'none';
                
                // إغلاق Bootstrap Modal إذا كان متاحاً
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    const bsModal = bootstrap.Modal.getInstance(modal);
                    if (bsModal) {
                        bsModal.hide();
                    }
                } else {
                    // Fallback: إخفاء Modal يدوياً
                    modal.style.display = 'none';
                    modal.classList.remove('show');
                    modal.setAttribute('aria-hidden', 'true');
                    document.body.classList.remove('modal-open');
                    const backdrop = document.getElementById('maintenanceModalBackdrop');
                    if (backdrop) {
                        backdrop.remove();
                    }
                }
            }
        }
        
        // التحقق من وضع الصيانة عند تحميل الصفحة
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(checkMaintenanceMode, 500);
            });
        } else {
            setTimeout(checkMaintenanceMode, 500);
        }
        
        // التحقق بشكل دوري من وضع الصيانة (كل 30 ثانية)
        setInterval(checkMaintenanceMode, 30000);
    })();
    </script>
    
        </main>
    </div>
</body>
</html>

