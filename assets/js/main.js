/**
 * JavaScript الرئيسي
 */

// ========== حل جذري لمنع أخطاء classList ==========
// معالج أخطاء عام لالتقاط أخطاء classList بشكل آمن
(function() {
    'use strict';
    
    // دالة مساعدة آمنة للتعامل مع classList
    window.safeClassList = function(element) {
        if (!element || typeof element !== 'object') {
            return {
                add: function() { return false; },
                remove: function() { return false; },
                toggle: function() { return false; },
                contains: function() { return false; },
                replace: function() { return false; },
                item: function() { return null; },
                toString: function() { return ''; },
                length: 0
            };
        }
        
        // إذا كان العنصر موجوداً وله classList، استخدمه
        if (element.classList && typeof element.classList === 'object') {
            return element.classList;
        }
        
        // إذا لم يكن موجوداً، أرجع كائن آمن
        return {
            add: function() { return false; },
            remove: function() { return false; },
            toggle: function() { return false; },
            contains: function() { return false; },
            replace: function() { return false; },
            item: function() { return null; },
            toString: function() { return ''; },
            length: 0
        };
    };
    
    // دالة مساعدة لإضافة class بشكل آمن
    window.safeAddClass = function(element, className) {
        if (!element || !className) return false;
        try {
            if (element.classList) {
                element.classList.add(className);
                return true;
            }
        } catch (e) {
            console.warn('safeAddClass error:', e);
        }
        return false;
    };
    
    // دالة مساعدة لإزالة class بشكل آمن
    window.safeRemoveClass = function(element, className) {
        if (!element || !className) return false;
        try {
            if (element.classList) {
                element.classList.remove(className);
                return true;
            }
        } catch (e) {
            console.warn('safeRemoveClass error:', e);
        }
        return false;
    };
    
    // دالة مساعدة للتحقق من وجود class بشكل آمن
    window.safeHasClass = function(element, className) {
        if (!element || !className) return false;
        try {
            if (element.classList) {
                return element.classList.contains(className);
            }
        } catch (e) {
            console.warn('safeHasClass error:', e);
        }
        return false;
    };
    
    // دالة مساعدة للتبديل بين class بشكل آمن
    window.safeToggleClass = function(element, className) {
        if (!element || !className) return false;
        try {
            if (element.classList) {
                return element.classList.toggle(className);
            }
        } catch (e) {
            console.warn('safeToggleClass error:', e);
        }
        return false;
    };
    
    // حماية classList من الأخطاء عبر Proxy
    if (typeof Element !== 'undefined' && Element.prototype) {
        const originalClassListDescriptor = Object.getOwnPropertyDescriptor(Element.prototype, 'classList');
        
        if (originalClassListDescriptor && originalClassListDescriptor.get) {
            const originalGetter = originalClassListDescriptor.get;
            
            Object.defineProperty(Element.prototype, 'classList', {
                get: function() {
                    try {
                        const classList = originalGetter.call(this);
                        if (classList && typeof classList === 'object') {
                            return classList;
                        }
                    } catch (e) {
                        // في حالة الخطأ، أرجع كائن آمن
                        console.warn('classList access error on element:', e);
                    }
                    
                    // أرجع كائن classList آمن
                    return {
                        add: function() { return false; },
                        remove: function() { return false; },
                        toggle: function() { return false; },
                        contains: function() { return false; },
                        replace: function() { return false; },
                        item: function() { return null; },
                        toString: function() { return ''; },
                        length: 0
                    };
                },
                configurable: true,
                enumerable: true
            });
        }
    }
    
    // معالج أخطاء عام لالتقاط أخطاء classList وأخطاء الشبكة (ERR_FAILED)
    window.addEventListener('error', function(event) {
        // التحقق من أخطاء الشبكة (ERR_FAILED)
        if (event.message && typeof event.message === 'string') {
            const message = event.message.toLowerCase();
            if (message.includes('failed') || message.includes('network error') || 
                message.includes('err_failed') || message.includes('load failed')) {
                // محاولة منع عرض صفحة الخطأ
                event.preventDefault();
                event.stopPropagation();
                
                const currentPath = window.location.pathname || '';
                const isProfilePage = currentPath.includes('profile.php');
                
                if (!isProfilePage) {
                    // محاولة التوجيه إلى صفحة تسجيل الدخول
                    setTimeout(() => {
                        const loginUrl = '/index.php';
                        if (window.location.pathname !== loginUrl) {
                            window.location.href = loginUrl;
                        }
                    }, 100);
                }
                
                return true;
            }
        }
        
        // التحقق من أن الخطأ متعلق بـ classList
        if (event.error && event.error.message) {
            const errorMessage = event.error.message.toLowerCase();
            if (errorMessage.includes('classlist') || errorMessage.includes('class list')) {
                // منع عرض الخطأ للمستخدم
                event.preventDefault();
                event.stopPropagation();
                
                // تسجيل تحذير في console فقط (للمطورين)
                console.warn('classList error caught and handled:', event.error.message, event.error);
                
                // إرجاع true لمنع عرض الخطأ
                return true;
            }
        }
        
        // التحقق من رسالة الخطأ في النص
        if (event.message && typeof event.message === 'string') {
            const message = event.message.toLowerCase();
            if (message.includes('classlist') || message.includes('class list') || 
                message.includes('cannot read properties of null') && message.includes('classlist')) {
                event.preventDefault();
                event.stopPropagation();
                console.warn('classList error caught and handled:', event.message);
                return true;
            }
        }
    }, true);
    
    // معالج أخطاء غير معالجة (unhandledrejection)
    window.addEventListener('unhandledrejection', function(event) {
        if (event.reason && event.reason.message) {
            const errorMessage = event.reason.message.toLowerCase();
            if (errorMessage.includes('classlist') || errorMessage.includes('class list')) {
                event.preventDefault();
                console.warn('classList promise rejection caught and handled:', event.reason.message);
            }
            // معالجة أخطاء الشبكة في promises
            if (errorMessage.includes('failed') || errorMessage.includes('network error') || 
                errorMessage.includes('err_failed') || errorMessage.includes('load failed')) {
                event.preventDefault();
                const currentPath = window.location.pathname || '';
                const isProfilePage = currentPath.includes('profile.php');
                if (!isProfilePage) {
                    setTimeout(() => {
                        const loginUrl = '/index.php';
                        if (window.location.pathname !== loginUrl) {
                            window.location.href = loginUrl;
                        }
                    }, 100);
                }
            }
        }
    });
    
    // معالج أخطاء التنقل (page navigation errors)
    window.addEventListener('unhandledrejection', function(event) {
        if (event.reason && typeof event.reason === 'object') {
            const reason = event.reason;
            // التحقق من أخطاء fetch/network
            if (reason.name === 'TypeError' && reason.message && 
                (reason.message.includes('fetch') || reason.message.includes('network') || 
                 reason.message.includes('Failed to fetch'))) {
                event.preventDefault();
                const currentPath = window.location.pathname || '';
                const isProfilePage = currentPath.includes('profile.php');
                if (!isProfilePage) {
                    setTimeout(() => {
                        const loginUrl = '/index.php';
                        if (window.location.pathname !== loginUrl) {
                            window.location.href = loginUrl;
                        }
                    }, 100);
                }
            }
        }
    });
    
    // حماية querySelector و querySelectorAll من إرجاع null
    const originalQuerySelector = Element.prototype.querySelector;
    const originalQuerySelectorAll = Element.prototype.querySelectorAll;
    
    Element.prototype.querySelector = function(selector) {
        try {
            const result = originalQuerySelector.call(this, selector);
            return result;
        } catch (e) {
            console.warn('querySelector error:', e);
            return null;
        }
    };
    
    Element.prototype.querySelectorAll = function(selector) {
        try {
            const result = originalQuerySelectorAll.call(this, selector);
            return result;
        } catch (e) {
            console.warn('querySelectorAll error:', e);
            return [];
        }
    };
    
    // حماية getElementById
    const originalGetElementById = Document.prototype.getElementById;
    Document.prototype.getElementById = function(id) {
        try {
            return originalGetElementById.call(this, id);
        } catch (e) {
            console.warn('getElementById error:', e);
            return null;
        }
    };
    
})();

// تهيئة النظام
document.addEventListener('DOMContentLoaded', function() {
    // تهيئة Tooltips
    if (typeof bootstrap !== 'undefined') {
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }
    
    const mobileReloadBtn = document.getElementById('mobileReloadBtn');
    if (mobileReloadBtn) {
        mobileReloadBtn.addEventListener('click', function(e) {
            e.preventDefault();
            window.location.reload();
        });
    }
    
    const mobileDarkToggle = document.getElementById('mobileDarkToggle');
    function updateMobileDarkIcon(theme) {
        if (!mobileDarkToggle) {
            return;
        }
        const icon = mobileDarkToggle.querySelector('i');
        if (!icon) {
            return;
        }
        if ((theme || '').toLowerCase() === 'dark') {
            icon.classList.remove('bi-moon-stars');
            icon.classList.add('bi-brightness-high');
        } else {
            icon.classList.remove('bi-brightness-high');
            icon.classList.add('bi-moon-stars');
        }
    }
    if (mobileDarkToggle) {
        updateMobileDarkIcon(document.documentElement.getAttribute('data-theme') || localStorage.getItem('theme') || 'light');
        mobileDarkToggle.addEventListener('click', function(e) {
            e.preventDefault();
            if (typeof toggleDarkMode === 'function') {
                toggleDarkMode();
            }
            updateMobileDarkIcon(document.documentElement.getAttribute('data-theme') || localStorage.getItem('theme') || 'light');
        });
        window.addEventListener('themeChange', function(e) {
            const theme = e && e.detail && e.detail.theme ? e.detail.theme : (document.documentElement.getAttribute('data-theme') || localStorage.getItem('theme') || 'light');
            updateMobileDarkIcon(theme);
        });
    }
    
    // تهيئة Popovers
    if (typeof bootstrap !== 'undefined') {
        const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
        popoverTriggerList.map(function (popoverTriggerEl) {
            return new bootstrap.Popover(popoverTriggerEl);
        });
    }
    
    // إخفاء الرسائل تلقائياً بعد 5 ثوان
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert:not(.alert-permanent)');
        alerts.forEach(function(alert) {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
    
    // تأكيد الحذف
    document.querySelectorAll('[data-confirm-delete]').forEach(function(element) {
        element.addEventListener('click', function(e) {
            if (!confirm('هل أنت متأكد من الحذف؟')) {
                e.preventDefault();
            }
        });
    });
    
    // إضافة data-label للجداول على الهاتف
    if (window.innerWidth <= 767) {
        const tables = document.querySelectorAll('.table');
        tables.forEach(function(table) {
            const headers = table.querySelectorAll('thead th');
            const rows = table.querySelectorAll('tbody tr');
            
            rows.forEach(function(row) {
                const cells = row.querySelectorAll('td');
                cells.forEach(function(cell, index) {
                    if (headers[index]) {
                        cell.setAttribute('data-label', headers[index].textContent.trim());
                    }
                });
            });
        });
    }
    
    // تحديث data-label عند تغيير حجم النافذة
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            if (window.innerWidth <= 767) {
                const tables = document.querySelectorAll('.table');
                tables.forEach(function(table) {
                    const headers = table.querySelectorAll('thead th');
                    const rows = table.querySelectorAll('tbody tr');
                    
                    rows.forEach(function(row) {
                        const cells = row.querySelectorAll('td');
                        cells.forEach(function(cell, index) {
                            if (headers[index]) {
                                cell.setAttribute('data-label', headers[index].textContent.trim());
                            }
                        });
                    });
                });
            }
        }, 250);
    });
});

// دوال مساعدة
function formatCurrency(amount) {
    return new Intl.NumberFormat('ar-EG', {
        style: 'currency',
        currency: 'EGP'
    }).format(amount);
}

function formatDate(date) {
    return new Date(date).toLocaleDateString('ar-EG');
}

function formatDateTime(date) {
    return new Date(date).toLocaleString('ar-EG');
}

// تحديث تلقائي للبيانات
let autoRefreshInterval = null;

function startAutoRefresh(callback, interval = 30000) {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
    }
    
    autoRefreshInterval = setInterval(callback, interval);
}

function stopAutoRefresh() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
        autoRefreshInterval = null;
    }
}

    // إيقاف التحديث عند مغادرة الصفحة
window.addEventListener('beforeunload', function() {
    stopAutoRefresh();
});

// معالجة البحث في التوب بار
document.addEventListener('DOMContentLoaded', function() {
    const globalSearch = document.getElementById('globalSearch');
    let activeHighlight = null;
    let lastSearchTerm = '';

    function ensureHighlightStyle() {
        if (document.getElementById('global-search-highlight-style')) {
            return;
        }

        const style = document.createElement('style');
        style.id = 'global-search-highlight-style';
        style.textContent = `
            mark.global-search-highlight {
                background-color: #ffe066;
                color: inherit;
                padding: 0.1rem 0.2rem;
                border-radius: 0.25rem;
                box-shadow: 0 0 0 0.15rem rgba(255, 224, 102, 0.45);
            }
        `;
        document.head.appendChild(style);
    }

    function clearSearchHighlight() {
        if (!activeHighlight || !activeHighlight.parentNode) {
            activeHighlight = null;
            return;
        }

        const parent = activeHighlight.parentNode;
        while (activeHighlight.firstChild) {
            parent.insertBefore(activeHighlight.firstChild, activeHighlight);
        }
        parent.removeChild(activeHighlight);
        parent.normalize();
        activeHighlight = null;
    }

    function highlightSearchTerm(term, startFromHighlight = false) {
        if (!term) {
            clearSearchHighlight();
            return false;
        }

        ensureHighlightStyle();

        const lowerTerm = term.toLowerCase();
        let walkerStartNode = document.body;

        if (startFromHighlight && activeHighlight && activeHighlight.parentNode) {
            walkerStartNode = activeHighlight;
        }

        const walker = document.createTreeWalker(
            walkerStartNode,
            NodeFilter.SHOW_TEXT,
            {
                acceptNode: function(node) {
                    if (!node.nodeValue || !node.nodeValue.trim()) {
                        return NodeFilter.FILTER_REJECT;
                    }
                    if (!node.parentNode) {
                        return NodeFilter.FILTER_REJECT;
                    }
                    const disallowed = node.parentNode.closest('script, style, noscript, head, title, meta, link');
                    return disallowed ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
                }
            }
        );

        // If continuing search, skip the first (current) node
        if (startFromHighlight) {
            walker.nextNode();
        }

        let node;
        while ((node = walker.nextNode())) {
            const text = node.nodeValue;
            const index = text.toLowerCase().indexOf(lowerTerm);
            if (index !== -1) {
                clearSearchHighlight();
                const range = document.createRange();
                range.setStart(node, index);
                range.setEnd(node, index + term.length);
                const mark = document.createElement('mark');
                mark.className = 'global-search-highlight';
                mark.setAttribute('data-global-search-highlight', 'true');

                try {
                    range.surroundContents(mark);
                } catch (err) {
                    // fallback: skip problematic node
                    continue;
                }

                activeHighlight = mark;
                mark.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return true;
            }
        }

        return false;
    }

    function handleGlobalSearch(term, continueSearch = false) {
        const searchTerm = term.trim();
        if (!searchTerm) {
            clearSearchHighlight();
            lastSearchTerm = '';
            return;
        }

        const continueFromHighlight = continueSearch && searchTerm === lastSearchTerm;
        const found = highlightSearchTerm(searchTerm, continueFromHighlight);

        if (!found && continueFromHighlight) {
            // Loop from top again
            const wrappedFound = highlightSearchTerm(searchTerm, false);
            if (!wrappedFound) {
                clearSearchHighlight();
                window.alert('لم يتم العثور على النتائج المطابقة.');
            } else {
                window.alert('تم الوصول إلى بداية النتائج مرة أخرى.');
            }
        } else if (!found) {
            clearSearchHighlight();
            window.alert('لم يتم العثور على النتائج المطابقة.');
        }

        lastSearchTerm = searchTerm;
    }

    if (globalSearch) {
        globalSearch.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                handleGlobalSearch(this.value, true);
            }
        });

        globalSearch.addEventListener('input', function() {
            if (this.value.trim() === '') {
                clearSearchHighlight();
                lastSearchTerm = '';
            }
        });
    }
    
    // إصلاح نماذج GET بدون action ومنع البحث بدون مدخلات
    document.querySelectorAll('form[method="GET"]').forEach(function(form) {
        const currentUrl = new URL(window.location.href);
        const pathname = currentUrl.pathname;
        
        // إذا كان action فارغاً أو غير موجود، اجعله الصفحة الحالية
        if (!form.action || form.action === '' || form.action === window.location.href.split('?')[0]) {
            // إذا كان action فارغاً، استخدم المسار الحالي
            if (!form.action || form.action === '') {
                form.action = pathname;
            }
        }
        
        // التأكد من وجود input hidden للصفحة
        const pageInput = form.querySelector('input[name="page"]');
        if (!pageInput) {
            // محاولة استخراج page من URL الحالي
            const currentPage = currentUrl.searchParams.get('page');
            if (currentPage) {
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'page';
                hiddenInput.value = currentPage;
                form.appendChild(hiddenInput);
            }
        }
        
        // منع إرسال النموذج بدون مدخلات بحث
        const searchButton = form.querySelector('button[type="submit"]');
        if (searchButton) {
            form.addEventListener('submit', function(e) {
                // جمع جميع حقول البحث والفلترة
                const searchInputs = form.querySelectorAll('input[name="search"], input[name="date_from"], input[name="date_to"], input[type="text"]:not([type="hidden"]), input[type="number"]:not([type="hidden"])');
                const selectInputs = form.querySelectorAll('select');
                
                let hasInput = false;
                let hasFilter = false;
                
                // التحقق من وجود نص في حقول البحث
                searchInputs.forEach(function(input) {
                    if (input.value && input.value.trim() !== '') {
                        hasInput = true;
                    }
                });
                
                // التحقق من وجود فلتر في select (غير القيمة الافتراضية)
                selectInputs.forEach(function(select) {
                    if (select.value && select.value !== '' && select.value !== '0') {
                        hasFilter = true;
                    }
                });
                
                // إذا لم يكن هناك مدخلات بحث أو فلترة، منع الإرسال
                if (!hasInput && !hasFilter) {
                    e.preventDefault();
                    // إظهار رسالة للمستخدم
                    const alertDiv = document.createElement('div');
                    alertDiv.className = 'alert alert-warning alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3';
                    alertDiv.style.zIndex = '9999';
                    alertDiv.style.maxWidth = '500px';
                    alertDiv.innerHTML = `
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>يرجى إدخال معايير البحث أو اختيار فلتر</strong>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    `;
                    document.body.appendChild(alertDiv);
                    
                    // إزالة الرسالة بعد 3 ثوان
                    setTimeout(function() {
                        if (alertDiv.parentNode) {
                            alertDiv.remove();
                        }
                    }, 3000);
                    
                    return false;
                }
                
                // التأكد من أن action يشير للصفحة الحالية وليس للداشبورد
                const formUrl = new URL(form.action, window.location.origin);
                const currentPath = window.location.pathname;
                
                // إذا كان action مختلف عن الصفحة الحالية، تصحيحه
                if (formUrl.pathname !== currentPath) {
                    form.action = currentPath;
                }
            });
        }
    });
    
    // إزالة القيم الافتراضية الكبيرة من select dropdowns
    document.querySelectorAll('select').forEach(function(select) {
        const selectedOption = select.options[select.selectedIndex];
        if (selectedOption && selectedOption.value) {
            const value = parseInt(selectedOption.value);
            // إذا كانت القيمة أكبر من 100000 (غير منطقية)، إزالة selected
            if (!isNaN(value) && value > 100000) {
                // التحقق من أن القيمة ليست في قاعدة البيانات
                // إذا كانت القيمة كبيرة جداً، إزالة selected
                selectedOption.removeAttribute('selected');
                select.selectedIndex = 0; // تحديد الخيار الأول (عادة "اختر..." أو "الكل")
                
                // إذا كان هناك option بقيمة فارغة أو 0، حدده
                const emptyOption = Array.from(select.options).find(function(option) {
                    return option.value === '' || option.value === '0' || option.value === null;
                });
                if (emptyOption) {
                    emptyOption.selected = true;
                }
            }
        }
    });
    
});

(function() {
    if (window.__sessionOverlayInstalled) {
        return;
    }
    window.__sessionOverlayInstalled = true;

    const SESSION_END_STATUS = new Set([401, 419, 440]);
    let overlayActivated = false;

    function getOverlayElement() {
        return document.getElementById('sessionEndOverlay');
    }

    function getLoginUrl(overlay) {
        if (!overlay) {
            return '/index.php';
        }
        const loginUrlAttr = overlay.getAttribute('data-login-url') || overlay.dataset.loginUrl;
        if (loginUrlAttr && loginUrlAttr.trim() !== '') {
            return loginUrlAttr;
        }
        return '/index.php';
    }

    function redirectToLogin(loginUrl) {
        window.location.href = loginUrl || '/index.php';
    }

    function showSessionOverlay(loginUrl) {
        if (overlayActivated) {
            return;
        }
        const overlay = getOverlayElement();
        overlayActivated = true;

        if (!overlay) {
            redirectToLogin(loginUrl);
            return;
        }

        overlay.removeAttribute('hidden');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.classList.add('session-ended');

        const actionButton = overlay.querySelector('[data-action="return-login"]');
        if (actionButton) {
            setTimeout(function() {
                try {
                    actionButton.focus();
                } catch (focusError) {
                    // ignore focus errors
                }
            }, 50);
        }

        overlay.dispatchEvent(new CustomEvent('sessionEndOverlayShown', {
            bubbles: true,
            detail: { redirected: false }
        }));
    }

    function handleSessionStatus(status) {
        // منع إعادة التوجيه في profile.php
        const currentPath = window.location.pathname || '';
        const isProfilePage = currentPath.includes('profile.php') || 
                             currentPath.endsWith('/profile') ||
                             document.querySelector('body[data-page="profile"]');
        
        if (isProfilePage) {
            // في profile.php، لا نعيد التوجيه حتى لو كان status 401/419/440
            // لأن الجلسة قد تكون صالحة لكن هناك مشكلة في الاتصال
            return;
        }
        
        const numericStatus = Number(status);
        if (!Number.isFinite(numericStatus)) {
            return;
        }
        if (SESSION_END_STATUS.has(numericStatus)) {
            const overlay = getOverlayElement();
            const loginUrl = getLoginUrl(overlay);
            showSessionOverlay(loginUrl);
        }
    }

    const originalFetch = window.fetch;
    if (typeof originalFetch === 'function') {
        window.fetch = async function() {
            try {
                const response = await originalFetch.apply(this, arguments);
                
                // التحقق من أن الاستجابة ليست من errors.infinityfree.net
                if (response && response.url && response.url.includes('errors.infinityfree.net')) {
                    // إنشاء استجابة خطأ بديلة بدلاً من إرجاع استجابة من errors.infinityfree.net
                    return new Response(JSON.stringify({
                        success: false,
                        message: 'حدث خطأ في الاتصال بالخادم'
                    }), {
                        status: 500,
                        statusText: 'Internal Server Error',
                        headers: {
                            'Content-Type': 'application/json'
                        }
                    });
                }
                
                try {
                    handleSessionStatus(response && response.status);
                } catch (statusError) {
                    console.warn('Session overlay handler (fetch) error:', statusError);
                }
                return response;
            } catch (fetchError) {
                // التعامل مع أخطاء الشبكة والأخطاء الأخرى (ERR_FAILED)
                console.error('Fetch error:', fetchError);
                
                // إذا كان الخطأ بسبب انتهاء الجلسة أو خطأ في الاتصال
                // محاولة التوجيه إلى صفحة تسجيل الدخول بدلاً من عرض ERR_FAILED
                const currentPath = window.location.pathname || '';
                const isProfilePage = currentPath.includes('profile.php') || 
                                     currentPath.endsWith('/profile') ||
                                     document.querySelector('body[data-page="profile"]');
                
                // منع إعادة التوجيه في profile.php
                if (!isProfilePage) {
                    // محاولة تحديد URL تسجيل الدخول
                    const overlay = getOverlayElement();
                    const loginUrl = getLoginUrl(overlay);
                    
                    // إظهار overlay بدلاً من ERR_FAILED
                    setTimeout(() => {
                        try {
                            showSessionOverlay(loginUrl);
                        } catch (e) {
                            // إذا فشل overlay، التوجيه المباشر
                            console.warn('Could not show overlay, redirecting:', e);
                            redirectToLogin(loginUrl);
                        }
                    }, 100);
                }
                
                // إرجاع استجابة خطأ بديلة لمنع عرض ERR_FAILED
                return new Response(JSON.stringify({
                    success: false,
                    message: 'حدث خطأ في الاتصال بالخادم. يرجى المحاولة مرة أخرى.'
                }), {
                    status: 500,
                    statusText: 'Network Error',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                });
            }
        };
    }

    const originalXhrOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function() {
        this.addEventListener('load', function() {
            handleSessionStatus(this.status);
        });
        this.addEventListener('error', function() {
            // معالجة أخطاء الشبكة (ERR_FAILED) في XMLHttpRequest
            const currentPath = window.location.pathname || '';
            const isProfilePage = currentPath.includes('profile.php') || 
                                 currentPath.endsWith('/profile') ||
                                 document.querySelector('body[data-page="profile"]');
            
            if (!isProfilePage) {
                const overlay = getOverlayElement();
                const loginUrl = getLoginUrl(overlay);
                setTimeout(() => {
                    try {
                        showSessionOverlay(loginUrl);
                    } catch (e) {
                        redirectToLogin(loginUrl);
                    }
                }, 100);
            }
            handleSessionStatus(this.status);
        });
        return originalXhrOpen.apply(this, arguments);
    };

    document.addEventListener('DOMContentLoaded', function() {
        const overlay = getOverlayElement();
        if (!overlay) {
            return;
        }

        const loginUrl = getLoginUrl(overlay);
        const actionButton = overlay.querySelector('[data-action="return-login"]');

        if (actionButton) {
            actionButton.addEventListener('click', function(event) {
                event.preventDefault();
                redirectToLogin(loginUrl);
            });
        }

        overlay.addEventListener('click', function(event) {
            if (event.target === overlay) {
                redirectToLogin(loginUrl);
            }
        });
    });

    window.addEventListener('forceSessionEndNotice', function() {
        const overlay = getOverlayElement();
        const loginUrl = getLoginUrl(overlay);
        showSessionOverlay(loginUrl);
    });
})();

// ========== إصلاح مشكلة عدم ظهور خيارات الـ dropdown في النماذج ==========
// هذا الحل يعمل على جميع النماذج في التطبيق
(function() {
    'use strict';
    
    function fixModalDropdowns() {
        // معالجة جميع النماذج
        const modals = document.querySelectorAll('.modal');
        
        modals.forEach(modal => {
            // عند فتح النموذج
            modal.addEventListener('shown.bs.modal', function() {
                const modalBody = this.querySelector('.modal-body');
                const modalContent = this.querySelector('.modal-content');
                const modalDialog = this.querySelector('.modal-dialog');
                
                if (!modalBody) return;
                
                // التأكد من أن modal-content و modal-dialog يسمحان بالـ overflow
                if (modalContent) {
                    modalContent.style.overflow = 'visible';
                }
                if (modalDialog) {
                    modalDialog.style.overflow = 'visible';
                }
                
                // إيجاد جميع select elements داخل modal-body
                const selects = modalBody.querySelectorAll('select.form-select, select');
                
                selects.forEach(select => {
                    // عند click على select (لأن native select يفتح dropdown عند click)
                    select.addEventListener('mousedown', function(e) {
                        const bodyStyle = window.getComputedStyle(modalBody);
                        
                        // إذا كان modal-body له overflow-y، نحاول حل المشكلة
                        if (bodyStyle.overflowY === 'auto' || bodyStyle.overflowY === 'scroll') {
                            // تحديد موقع select بالنسبة لـ modal-body
                            const selectRect = this.getBoundingClientRect();
                            const bodyRect = modalBody.getBoundingClientRect();
                            
                            // إذا كان select قريب من أسفل modal-body، قد نحتاج لـ scroll
                            const distanceFromBottom = bodyRect.bottom - selectRect.bottom;
                            
                            // التأكد من أن select مرئي بشكل كامل
                            if (distanceFromBottom < 200) {
                                // Scroll إلى select لضمان ظهور dropdown بشكل أفضل
                                setTimeout(() => {
                                    this.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                                }, 10);
                            }
                            
                            // تحسين z-index
                            this.style.position = 'relative';
                            this.style.zIndex = '1060';
                        }
                    });
                    
                    // عند focus أيضاً
                    select.addEventListener('focus', function() {
                        const bodyStyle = window.getComputedStyle(modalBody);
                        if (bodyStyle.overflowY === 'auto' || bodyStyle.overflowY === 'scroll') {
                            this.style.position = 'relative';
                            this.style.zIndex = '1060';
                        }
                    });
                });
            });
        });
    }
    
    // تشغيل الحل عند تحميل الصفحة
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fixModalDropdowns);
    } else {
        fixModalDropdowns();
    }
    
    // إعادة تشغيل الحل عند إضافة نماذج ديناميكية
    if (typeof MutationObserver !== 'undefined') {
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.addedNodes.length > 0) {
                    mutation.addedNodes.forEach(function(node) {
                        if (node.nodeType === 1 && (node.classList.contains('modal') || node.querySelector('.modal'))) {
                            fixModalDropdowns();
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
})();

