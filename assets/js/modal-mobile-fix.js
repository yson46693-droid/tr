/**
 * Modal Mobile Fix - Dynamic Height Adjustment
 * إصلاح النماذج على الهواتف المحمولة - ضبط الارتفاع الديناميكي
 */

(function() {
    'use strict';
    
    // التحقق من أننا على هاتف محمول
    function isMobile() {
        return window.innerWidth <= 768;
    }
    
    /**
     * حساب الارتفاع الفعلي المتاح للشاشة
     */
    function getAvailableHeight() {
        return window.innerHeight;
    }
    
    /**
     * ضبط ارتفاع النموذج الديناميكي
     */
    function adjustModalHeight(modalElement) {
        if (!modalElement || !isMobile()) return;
        
        const modalDialog = modalElement.querySelector('.modal-dialog');
        const modalContent = modalElement.querySelector('.modal-content');
        const modalHeader = modalElement.querySelector('.modal-header');
        const modalBody = modalElement.querySelector('.modal-body');
        const modalFooter = modalElement.querySelector('.modal-footer');
        
        if (!modalDialog || !modalContent) return;
        
        // حساب الارتفاع الفعلي المتاح
        const availableHeight = getAvailableHeight();
        const margin = 16; // 0.5rem = 8px * 2 = 16px
        const maxHeight = availableHeight - margin;
        
        // تعيين الارتفاع الأقصى للنموذج
        modalDialog.style.maxHeight = maxHeight + 'px';
        modalContent.style.maxHeight = maxHeight + 'px';
        
        // حساب ارتفاع الرأس والتذييل
        const headerHeight = modalHeader ? modalHeader.offsetHeight : 0;
        const footerHeight = modalFooter ? modalFooter.offsetHeight : 0;
        
        // حساب ارتفاع الجسم المتاح
        const bodyMaxHeight = maxHeight - headerHeight - footerHeight;
        
        // تعيين max-height للجسم
        if (modalBody) {
            // إزالة أي max-height محدد مسبقاً (inline styles)
            if (modalBody.style.maxHeight) {
                modalBody.style.maxHeight = '';
            }
            
            // تعيين max-height الجديد
            modalBody.style.maxHeight = bodyMaxHeight + 'px';
            
            // التأكد من أن overflow-y معطى
            if (!modalBody.style.overflowY) {
                modalBody.style.overflowY = 'auto';
            }
            
            // إضافة padding-bottom إضافي إذا كان المحتوى أكبر من المساحة المتاحة
            const contentHeight = modalBody.scrollHeight;
            if (contentHeight > bodyMaxHeight - 40) {
                const safeAreaBottom = parseInt(getComputedStyle(document.documentElement)
                    .getPropertyValue('env(safe-area-inset-bottom)')) || 0;
                modalBody.style.paddingBottom = (30 + safeAreaBottom) + 'px';
            }
        }
        
        // التأكد من أن التذييل مرئي دائماً
        if (modalFooter) {
            const safeAreaBottom = parseInt(getComputedStyle(document.documentElement)
                .getPropertyValue('env(safe-area-inset-bottom)')) || 0;
            modalFooter.style.paddingBottom = (16 + safeAreaBottom) + 'px';
            modalFooter.style.position = 'relative';
            modalFooter.style.zIndex = '10';
            modalFooter.style.backgroundColor = getComputedStyle(modalContent).backgroundColor || '#fff';
        }
    }
    
    /**
     * ضبط جميع النماذج المفتوحة
     */
    function adjustAllOpenModals() {
        if (!isMobile()) return;
        
        const openModals = document.querySelectorAll('.modal.show, .modal.showing');
        openModals.forEach(function(modal) {
            adjustModalHeight(modal);
        });
    }
    
    /**
     * تهيئة مراقب للنماذج
     */
    function initModalObserver() {
        if (!isMobile()) return;
        
        // استخدام Bootstrap Modal Events
        document.addEventListener('show.bs.modal', function(event) {
            const modal = event.target;
            if (modal && modal.classList.contains('modal')) {
                const modalBody = modal.querySelector('.modal-body');
                if (modalBody && modalBody.style.maxHeight) {
                    // إزالة max-height من inline style قبل فتح النموذج
                    modalBody.style.maxHeight = '';
                }
            }
        });
        
        document.addEventListener('shown.bs.modal', function(event) {
            const modal = event.target;
            if (modal && modal.classList.contains('modal')) {
                // انتظر قليلاً للسماح بعرض النموذج بشكل كامل
                setTimeout(function() {
                    adjustModalHeight(modal);
                }, 50);
                
                // إعادة ضبط الارتفاع بعد تحميل المحتوى الديناميكي
                setTimeout(function() {
                    adjustModalHeight(modal);
                }, 300);
            }
        });
        
        // إعادة ضبط عند تغيير حجم النافذة
        let resizeTimeout;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(function() {
                if (isMobile()) {
                    adjustAllOpenModals();
                }
            }, 100);
        });
        
        // إعادة ضبط عند تغيير اتجاه الشاشة
        window.addEventListener('orientationchange', function() {
            setTimeout(function() {
                if (isMobile()) {
                    adjustAllOpenModals();
                }
            }, 200);
        });
    }
    
    /**
     * مراقبة التغييرات في محتوى النموذج
     */
    function observeModalContent() {
        if (!isMobile()) return;
        
        // استخدام MutationObserver لمراقبة التغييرات في modal-body
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'childList' || mutation.type === 'attributes') {
                    const modal = mutation.target.closest('.modal');
                    if (modal && (modal.classList.contains('show') || modal.classList.contains('showing'))) {
                        setTimeout(function() {
                            adjustModalHeight(modal);
                        }, 100);
                    }
                }
            });
        });
        
        // مراقبة جميع النماذج
        document.querySelectorAll('.modal').forEach(function(modal) {
            const modalBody = modal.querySelector('.modal-body');
            if (modalBody) {
                observer.observe(modalBody, {
                    childList: true,
                    subtree: true,
                    attributes: true,
                    attributeFilter: ['style', 'class']
                });
            }
        });
    }
    
    /**
     * إزالة الأنماط المضمنة من النماذج عند التحميل
     */
    function removeInlineMaxHeightStyles() {
        if (!isMobile()) return;
        
        // البحث عن جميع modal-body التي تحتوي على max-height في inline style
        document.querySelectorAll('.modal-body[style*="max-height"]').forEach(function(modalBody) {
            // حفظ overflow-y إذا كان موجوداً
            const overflowY = modalBody.style.overflowY || 'auto';
            
            // إزالة max-height من inline style (سيتم تعيينه ديناميكياً لاحقاً)
            modalBody.style.maxHeight = '';
            
            // التأكد من وجود overflow-y
            modalBody.style.overflowY = overflowY;
        });
    }
    
    /**
     * تهيئة عند تحميل الصفحة
     */
    function init() {
        if (!isMobile()) return;
        
        // إزالة الأنماط المضمنة
        removeInlineMaxHeightStyles();
        
        // تهيئة مراقب النماذج
        initModalObserver();
        
        // مراقبة التغييرات في المحتوى
        observeModalContent();
        
        // ضبط النماذج المفتوحة حالياً (في حالة إعادة تحميل الصفحة)
        const initModals = function() {
            removeInlineMaxHeightStyles();
            setTimeout(function() {
                adjustAllOpenModals();
            }, 500);
        };
        
        // إذا كانت الصفحة محملة بالفعل
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initModals);
        } else {
            initModals();
        }
    }
    
    // تهيئة عند تحميل الصفحة
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
    // تصدير الدوال للاستخدام الخارجي إذا لزم الأمر
    window.ModalMobileFix = {
        adjustModalHeight: adjustModalHeight,
        adjustAllOpenModals: adjustAllOpenModals
    };
})();

