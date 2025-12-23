/**
 * Customer Export to Excel
 * Handles customer selection modal, Excel generation, print, download, and share functionality
 */

(function() {
    'use strict';
    
    // متغيرات عامة
    let selectedCustomers = [];
    let collectionAmounts = {};
    let currentSection = 'company'; // 'company' or 'delegates'
    let generatedFileUrl = null;
    
    /**
     * تهيئة المودال عند تحميل الصفحة
     */
    function initExportModal() {
        const exportModal = document.getElementById('customerExportModal');
        if (!exportModal) {
            return;
        }
        
        // إعادة تعيين عند فتح المودال
        exportModal.addEventListener('show.bs.modal', function() {
            resetExportModal();
            
            // تحديد القسم الحالي من data attribute
            const currentSection = exportModal.getAttribute('data-section') || '';
            
            // التحقق من وجود hidden input للمندوب (إذا كان المستخدم مندوب)
            const repSelect = document.getElementById('exportRepSelect');
            const isSalesUser = repSelect && repSelect.tagName === 'INPUT' && repSelect.value && repSelect.value !== '';
            
            // إذا كان المستخدم مندوب (sales user)، يجب جلب عملائه من API وليس عملاء الشركة
            if (isSalesUser) {
                const repId = parseInt(repSelect.value, 10);
                if (repId > 0) {
                    loadCustomersByRep(repId);
                    return;
                }
            }
            
            // إذا كان قسم عملاء الشركة (وليس مندوب)، جلب عملاء الشركة مباشرة
            if (currentSection === 'company') {
                loadCompanyCustomers();
                return;
            }
            
            // إذا كان قسم العملاء المحليين، جلب العملاء المحليين مباشرة
            if (currentSection === 'local') {
                loadLocalCustomers();
                return;
            }
            
            // إذا كان هناك اختيار مندوب، انتظر اختياره
            if (repSelect && repSelect.tagName === 'SELECT') {
                // إظهار رسالة اختيار المندوب
                showSelectRepMessage();
            } else {
                // إذا كان هناك معرف مندوب في hidden input (لحالات أخرى)
                const repId = repSelect ? repSelect.value : null;
                if (repId && repId !== '') {
                    loadCustomersByRep(parseInt(repId, 10));
                }
            }
        });
        
        // ربط حدث اختيار المندوب
        const repSelect = document.getElementById('exportRepSelect');
        if (repSelect && repSelect.tagName === 'SELECT') {
            repSelect.addEventListener('change', function() {
                const repId = parseInt(this.value, 10);
                if (repId > 0) {
                    loadCustomersByRep(repId);
                } else {
                    showSelectRepMessage();
                }
            });
        }
        
        // ربط أحداث الأزرار
        const generateBtn = document.getElementById('generateExcelBtn');
        const printBtn = document.getElementById('printExcelBtn');
        const downloadBtn = document.getElementById('downloadExcelBtn');
        const shareBtn = document.getElementById('shareExcelBtn');
        const selectAllBtn = document.getElementById('selectAllCustomers');
        const deselectAllBtn = document.getElementById('deselectAllCustomers');
        
        if (generateBtn) {
            generateBtn.addEventListener('click', handleGenerateExcel);
        }
        
        if (printBtn) {
            printBtn.addEventListener('click', handlePrintExcel);
        }
        
        if (downloadBtn) {
            downloadBtn.addEventListener('click', handleDownloadExcel);
        }
        
        if (shareBtn) {
            shareBtn.addEventListener('click', handleShareExcel);
        }
        
        if (selectAllBtn) {
            selectAllBtn.addEventListener('click', function() {
                selectAllCustomers(true);
            });
        }
        
        if (deselectAllBtn) {
            deselectAllBtn.addEventListener('click', function() {
                selectAllCustomers(false);
            });
        }
    }
    
    /**
     * إعادة تعيين المودال
     */
    function resetExportModal() {
        selectedCustomers = [];
        collectionAmounts = {};
        generatedFileUrl = null;
        
        const customersList = document.getElementById('exportCustomersList');
        if (customersList) {
            customersList.innerHTML = '';
        }
        
        const customersSection = document.getElementById('customersSection');
        if (customersSection) {
            customersSection.style.display = 'none';
        }
        
        const selectRepMessage = document.getElementById('selectRepMessage');
        if (selectRepMessage) {
            selectRepMessage.style.display = 'block';
        }
        
        // إخفاء أزرار الطباعة والتحميل والمشاركة
        const actionButtons = document.getElementById('exportActionButtons');
        if (actionButtons) {
            actionButtons.style.display = 'none';
        }
        
        // إظهار زر التوليد
        const generateBtn = document.getElementById('generateExcelBtn');
        if (generateBtn) {
            generateBtn.disabled = true;
            generateBtn.innerHTML = '<i class="bi bi-file-earmark-excel me-2"></i>توليد ملف Excel';
        }
        
        // إعادة تعيين اختيار المندوب
        const repSelect = document.getElementById('exportRepSelect');
        if (repSelect && repSelect.tagName === 'SELECT') {
            repSelect.value = '';
        }
    }
    
    /**
     * إظهار رسالة اختيار المندوب
     */
    function showSelectRepMessage() {
        const customersSection = document.getElementById('customersSection');
        const selectRepMessage = document.getElementById('selectRepMessage');
        
        if (customersSection) {
            customersSection.style.display = 'none';
        }
        
        if (selectRepMessage) {
            selectRepMessage.style.display = 'block';
            selectRepMessage.innerHTML = '<i class="bi bi-info-circle me-2"></i>يرجى اختيار المندوب أولاً لعرض عملائه';
        }
        
        const generateBtn = document.getElementById('generateExcelBtn');
        if (generateBtn) {
            generateBtn.disabled = true;
        }
    }
    
    /**
     * جلب العملاء المحليين عبر API
     */
    async function loadLocalCustomers() {
        const customersList = document.getElementById('exportCustomersList');
        const customersSection = document.getElementById('customersSection');
        const selectRepMessage = document.getElementById('selectRepMessage');
        
        if (!customersList) {
            return;
        }
        
        // إظهار رسالة التحميل
        if (selectRepMessage) {
            selectRepMessage.style.display = 'block';
            selectRepMessage.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري تحميل العملاء المحليين...';
        }
        
        if (customersSection) {
            customersSection.style.display = 'none';
        }
        
        try {
            const response = await fetch('../api/get_local_customers_for_export.php', {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.message || 'فشل في جلب العملاء المحليين');
            }
            
            // إخفاء رسالة التحميل
            if (selectRepMessage) {
                selectRepMessage.style.display = 'none';
            }
            
            // عرض قائمة العملاء
            displayCustomersList(result.customers || []);
            
            if (customersSection) {
                customersSection.style.display = 'block';
            }
            
        } catch (error) {
            console.error('Load local customers error:', error);
            if (selectRepMessage) {
                selectRepMessage.style.display = 'block';
                selectRepMessage.innerHTML = '<div class="alert alert-danger">حدث خطأ أثناء جلب العملاء المحليين: ' + escapeHtml(error.message) + '</div>';
            }
        }
    }
    
    /**
     * جلب عملاء الشركة عبر API
     */
    async function loadCompanyCustomers() {
        const customersList = document.getElementById('exportCustomersList');
        const customersSection = document.getElementById('customersSection');
        const selectRepMessage = document.getElementById('selectRepMessage');
        
        if (!customersList) {
            return;
        }
        
        // إظهار رسالة التحميل
        if (selectRepMessage) {
            selectRepMessage.style.display = 'block';
            selectRepMessage.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري تحميل عملاء الشركة...';
        }
        
        if (customersSection) {
            customersSection.style.display = 'none';
        }
        
        try {
            const response = await fetch('../api/get_company_customers_for_export.php', {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.message || 'فشل في جلب عملاء الشركة');
            }
            
            // إخفاء رسالة التحميل
            if (selectRepMessage) {
                selectRepMessage.style.display = 'none';
            }
            
            // عرض قائمة العملاء
            displayCustomersList(result.customers || []);
            
            if (customersSection) {
                customersSection.style.display = 'block';
            }
            
        } catch (error) {
            console.error('Load company customers error:', error);
            if (selectRepMessage) {
                selectRepMessage.style.display = 'block';
                selectRepMessage.innerHTML = '<div class="alert alert-danger">حدث خطأ أثناء جلب عملاء الشركة: ' + escapeHtml(error.message) + '</div>';
            }
        }
    }
    
    /**
     * جلب عملاء المندوب عبر API
     */
    async function loadCustomersByRep(repId) {
        const customersList = document.getElementById('exportCustomersList');
        const customersSection = document.getElementById('customersSection');
        const selectRepMessage = document.getElementById('selectRepMessage');
        
        if (!customersList || !repId || repId <= 0) {
            return;
        }
        
        // إظهار رسالة التحميل
        if (selectRepMessage) {
            selectRepMessage.style.display = 'block';
            selectRepMessage.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري تحميل عملاء المندوب...';
        }
        
        if (customersSection) {
            customersSection.style.display = 'none';
        }
        
        try {
            const response = await fetch(`../api/get_rep_customers_for_export.php?rep_id=${repId}`, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.message || 'فشل في جلب عملاء المندوب');
            }
            
            // التحقق من صحة البيانات القادمة من API
            const customers = result.customers || [];
            if (!Array.isArray(customers)) {
                console.warn('API returned invalid customers data:', customers);
                displayCustomersList([]);
                return;
            }
            
            // فلترة إضافية للتأكد من صحة البيانات
            const validCustomers = customers.filter(function(customer) {
                return customer && 
                       typeof customer === 'object' && 
                       customer.id && 
                       parseInt(customer.id, 10) > 0 &&
                       customer.name && 
                       typeof customer.name === 'string' && 
                       customer.name.trim() !== '';
            });
            
            // إخفاء رسالة التحميل
            if (selectRepMessage) {
                selectRepMessage.style.display = 'none';
            }
            
            // عرض قائمة العملاء المفلترة
            displayCustomersList(validCustomers);
            
            if (customersSection) {
                customersSection.style.display = 'block';
            }
            
        } catch (error) {
            console.error('Load customers error:', error);
            if (selectRepMessage) {
                selectRepMessage.style.display = 'block';
                selectRepMessage.innerHTML = '<div class="alert alert-danger">حدث خطأ أثناء جلب عملاء المندوب: ' + escapeHtml(error.message) + '</div>';
            }
        }
    }
    
    /**
     * عرض قائمة العملاء في الجدول
     */
    function displayCustomersList(customers) {
        const customersList = document.getElementById('exportCustomersList');
        if (!customersList) {
            return;
        }
        
        if (!customers || customers.length === 0) {
            customersList.innerHTML = '<div class="alert alert-warning">لا توجد عملاء متاحة للتصدير</div>';
            
            const generateBtn = document.getElementById('generateExcelBtn');
            if (generateBtn) {
                generateBtn.disabled = true;
            }
            return;
        }
        
        // إنشاء جدول العملاء
        let html = '<table class="table table-sm table-hover mb-0">';
        html += '<thead class="table-light"><tr>';
        html += '<th style="width: 40px;"><input type="checkbox" id="exportSelectAllCheckbox" class="form-check-input"></th>';
        html += '<th>اسم العميل</th>';
        html += '<th>رقم الهاتف</th>';
        html += '<th>الرصيد</th>';
        html += '<th>المبلغ المراد تحصيله</th>';
        html += '</tr></thead>';
        html += '<tbody>';
        
        customers.forEach(function(customer) {
            const customerId = customer.id;
            const customerName = escapeHtml(customer.name || '');
            const phone = escapeHtml(customer.phone || '-');
            const balance = customer.balance_formatted || '0.00 ج.م';
            
            html += '<tr data-customer-id="' + customerId + '">';
            html += '<td><input type="checkbox" class="form-check-input customer-export-checkbox" value="' + customerId + '"></td>';
            html += '<td><strong>' + customerName + '</strong></td>';
            html += '<td>' + phone + '</td>';
            html += '<td>' + balance + '</td>';
            html += '<td><input type="number" step="0.01" min="0" class="form-control form-control-sm collection-amount-input" data-customer-id="' + customerId + '" placeholder="مبلغ اختياري"></td>';
            html += '</tr>';
        });
        
        html += '</tbody></table>';
        customersList.innerHTML = html;
        
        // ربط أحداث checkbox
        const checkboxes = customersList.querySelectorAll('.customer-export-checkbox');
        checkboxes.forEach(function(checkbox) {
            checkbox.addEventListener('change', function() {
                updateSelectedCustomers();
            });
        });
        
        // ربط حدث select all
        const selectAllCheckbox = document.getElementById('exportSelectAllCheckbox');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function() {
                const checked = this.checked;
                checkboxes.forEach(function(cb) {
                    cb.checked = checked;
                });
                updateSelectedCustomers();
            });
        }
        
        // ربط أحداث مبالغ التحصيل
        const amountInputs = customersList.querySelectorAll('.collection-amount-input');
        amountInputs.forEach(function(input) {
            input.addEventListener('input', function() {
                const customerId = parseInt(this.getAttribute('data-customer-id'), 10);
                const value = parseFloat(this.value);
                if (value > 0) {
                    collectionAmounts[customerId] = value;
                } else {
                    delete collectionAmounts[customerId];
                }
            });
        });
    }
    
    /**
     * جلب قائمة العملاء من الجدول الحالي (legacy - للاستخدام مع العملاء المباشرين)
     */
    function loadCustomersList() {
        const customersList = document.getElementById('exportCustomersList');
        if (!customersList) {
            return;
        }
        
        // البحث عن جدول العملاء
        const customersTable = document.querySelector('.customers-table-container table tbody, .dashboard-table-wrapper table tbody');
        if (!customersTable) {
            customersList.innerHTML = '<div class="alert alert-warning">لا توجد عملاء متاحة للتصدير</div>';
            return;
        }
        
        const rows = customersTable.querySelectorAll('tr');
        if (rows.length === 0) {
            customersList.innerHTML = '<div class="alert alert-warning">لا توجد عملاء متاحة للتصدير</div>';
            return;
        }
        
        // إنشاء قائمة العملاء
        let html = '<div class="table-responsive"><table class="table table-sm table-hover">';
        html += '<thead><tr>';
        html += '<th style="width: 40px;"><input type="checkbox" id="exportSelectAllCheckbox" class="form-check-input"></th>';
        html += '<th>اسم العميل</th>';
        html += '<th>رقم الهاتف</th>';
        html += '<th>الرصيد</th>';
        html += '<th>المبلغ المراد تحصيله</th>';
        html += '</tr></thead>';
        html += '<tbody>';
        
        rows.forEach(function(row) {
            // محاولة استخراج بيانات العميل من الصف
            const cells = row.querySelectorAll('td');
            if (cells.length === 0) {
                return;
            }
            
            // البحث عن معرف العميل من data attributes في الصف أو من أي عنصر داخل الصف
            let customerId = null;
            
            // أولاً: محاولة الحصول من الصف مباشرة
            const rowCustomerId = row.getAttribute('data-customer-id');
            if (rowCustomerId) {
                customerId = parseInt(rowCustomerId, 10);
            }
            
            // ثانياً: البحث في أي عنصر داخل الصف يحتوي على data-customer-id
            if (!customerId || customerId <= 0) {
                const customerIdElement = row.querySelector('[data-customer-id]');
                if (customerIdElement) {
                    customerId = parseInt(customerIdElement.getAttribute('data-customer-id'), 10);
                }
            }
            
            // ثالثاً: البحث في أزرار الإجراءات (عادة في آخر خلية)
            if (!customerId || customerId <= 0 && cells.length > 0) {
                const lastCell = cells[cells.length - 1];
                const actionBtn = lastCell ? lastCell.querySelector('[data-customer-id]') : null;
                if (actionBtn) {
                    customerId = parseInt(actionBtn.getAttribute('data-customer-id'), 10);
                }
            }
            
            if (!customerId || customerId <= 0) {
                return;
            }
            
            // استخراج اسم العميل (عادة من الخلية الأولى)
            const nameCell = cells[0];
            const customerName = nameCell ? nameCell.textContent.trim() : '';
            
            // استخراج رقم الهاتف (عادة من الخلية الثانية)
            const phoneCell = cells[1];
            let phoneNumber = '';
            if (phoneCell) {
                const phoneLink = phoneCell.querySelector('a[href^="tel:"]');
                if (phoneLink) {
                    phoneNumber = phoneLink.getAttribute('href').replace('tel:', '');
                } else {
                    phoneNumber = phoneCell.textContent.trim();
                }
            }
            
            // استخراج الرصيد (عادة من الخلية الثالثة)
            const balanceCell = cells[2];
            let balance = '';
            if (balanceCell) {
                balance = balanceCell.textContent.trim();
            }
            
            html += '<tr data-customer-id="' + customerId + '">';
            html += '<td><input type="checkbox" class="form-check-input customer-export-checkbox" value="' + customerId + '"></td>';
            html += '<td><strong>' + escapeHtml(customerName) + '</strong></td>';
            html += '<td>' + escapeHtml(phoneNumber) + '</td>';
            html += '<td>' + escapeHtml(balance) + '</td>';
            html += '<td><input type="number" step="0.01" min="0" class="form-control form-control-sm collection-amount-input" data-customer-id="' + customerId + '" placeholder="مبلغ اختياري"></td>';
            html += '</tr>';
        });
        
        html += '</tbody></table></div>';
        customersList.innerHTML = html;
        
        // ربط أحداث checkbox
        const checkboxes = customersList.querySelectorAll('.customer-export-checkbox');
        checkboxes.forEach(function(checkbox) {
            checkbox.addEventListener('change', function() {
                updateSelectedCustomers();
            });
        });
        
        // ربط حدث select all
        const selectAllCheckbox = document.getElementById('exportSelectAllCheckbox');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function() {
                const checked = this.checked;
                checkboxes.forEach(function(cb) {
                    cb.checked = checked;
                });
                updateSelectedCustomers();
            });
        }
        
        // ربط أحداث مبالغ التحصيل
        const amountInputs = customersList.querySelectorAll('.collection-amount-input');
        amountInputs.forEach(function(input) {
            input.addEventListener('input', function() {
                const customerId = parseInt(this.getAttribute('data-customer-id'), 10);
                const value = parseFloat(this.value);
                if (value > 0) {
                    collectionAmounts[customerId] = value;
                } else {
                    delete collectionAmounts[customerId];
                }
            });
        });
    }
    
    /**
     * تحديث قائمة العملاء المحددين
     */
    function updateSelectedCustomers() {
        const checkboxes = document.querySelectorAll('.customer-export-checkbox:checked');
        selectedCustomers = Array.from(checkboxes).map(function(cb) {
            return parseInt(cb.value, 10);
        });
        
        // تحديث حالة زر التوليد
        const generateBtn = document.getElementById('generateExcelBtn');
        if (generateBtn) {
            generateBtn.disabled = selectedCustomers.length === 0;
        }
    }
    
    /**
     * تحديد/إلغاء تحديد جميع العملاء
     */
    function selectAllCustomers(select) {
        const checkboxes = document.querySelectorAll('.customer-export-checkbox');
        const selectAllCheckbox = document.getElementById('exportSelectAllCheckbox');
        
        checkboxes.forEach(function(cb) {
            cb.checked = select;
        });
        
        if (selectAllCheckbox) {
            selectAllCheckbox.checked = select;
        }
        
        updateSelectedCustomers();
    }
    
    /**
     * معالجة توليد ملف Excel
     */
    async function handleGenerateExcel() {
        if (selectedCustomers.length === 0) {
            alert('يرجى تحديد عملاء للتصدير');
            return;
        }
        
        const generateBtn = document.getElementById('generateExcelBtn');
        if (generateBtn) {
            generateBtn.disabled = true;
            generateBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري التوليد...';
        }
        
        try {
            // تحديد القسم الحالي من المودال
            const exportModal = document.getElementById('customerExportModal');
            currentSection = exportModal ? (exportModal.getAttribute('data-section') || 'company') : 'company';
            
            // جلب معرف المندوب (فقط إذا كان قسم عملاء المندوبين)
            const repSelect = document.getElementById('exportRepSelect');
            const repId = (currentSection === 'delegates' && repSelect) ? parseInt(repSelect.value, 10) : null;
            
            // إذا كان قسم العملاء المحليين، لا حاجة لمعرف مندوب
            if (currentSection === 'local') {
                // العملاء المحليين لا يحتاجون مندوب
            }
            
            // تحضير البيانات
            const payload = {
                customer_ids: selectedCustomers,
                collection_amounts: collectionAmounts,
                section: currentSection,
                rep_id: repId
            };
            
            // إرسال الطلب
            const response = await fetch('../api/export_customers_excel.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(payload)
            });
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.message || 'فشل في توليد ملف Excel');
            }
            
            // حفظ رابط الملف
            generatedFileUrl = result.file_url;
            
            // إظهار أزرار الإجراءات
            const actionButtons = document.getElementById('exportActionButtons');
            if (actionButtons) {
                actionButtons.style.display = 'block';
            }
            
            // إعادة تعيين زر التوليد
            if (generateBtn) {
                generateBtn.disabled = false;
                generateBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>تم التوليد بنجاح';
                generateBtn.classList.add('btn-success');
                generateBtn.classList.remove('btn-primary');
            }
            
            // إظهار رسالة نجاح
            showAlert('success', 'تم توليد ملف Excel بنجاح');
            
        } catch (error) {
            console.error('Export error:', error);
            alert('حدث خطأ أثناء توليد ملف Excel: ' + error.message);
            
            if (generateBtn) {
                generateBtn.disabled = false;
                generateBtn.innerHTML = '<i class="bi bi-file-earmark-excel me-2"></i>توليد ملف Excel';
            }
        }
    }
    
    /**
     * معالجة طباعة الملف
     */
    function handlePrintExcel() {
        if (!generatedFileUrl) {
            alert('لم يتم توليد ملف Excel بعد');
            return;
        }
        
        // فتح الملف في نافذة جديدة للطباعة
        const printWindow = window.open(generatedFileUrl, '_blank');
        if (printWindow) {
            printWindow.onload = function() {
                setTimeout(function() {
                    printWindow.print();
                }, 500);
            };
        } else {
            alert('تعذر فتح نافذة الطباعة. يرجى التحقق من إعدادات المتصفح');
        }
    }
    
    /**
     * معالجة تحميل الملف
     */
    function handleDownloadExcel() {
        if (!generatedFileUrl) {
            alert('لم يتم توليد ملف Excel بعد');
            return;
        }
        
        // إنشاء رابط تحميل
        const link = document.createElement('a');
        link.href = generatedFileUrl;
        link.download = 'customers_export_' + new Date().getTime() + '.csv';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
    
    /**
     * معالجة مشاركة الملف
     */
    async function handleShareExcel() {
        if (!generatedFileUrl) {
            alert('لم يتم توليد ملف Excel بعد');
            return;
        }
        
        // محاولة استخدام Web Share API
        if (navigator.share) {
            try {
                // تحميل الملف كـ blob
                const response = await fetch(generatedFileUrl);
                const blob = await response.blob();
                const file = new File([blob], 'customers_export.csv', { type: 'text/csv' });
                
                await navigator.share({
                    title: 'تصدير العملاء',
                    text: 'ملف تصدير العملاء المحددين',
                    files: [file]
                });
            } catch (error) {
                if (error.name !== 'AbortError') {
                    console.error('Share error:', error);
                    // Fallback: نسخ الرابط
                    copyLinkToClipboard();
                }
            }
        } else {
            // Fallback: نسخ الرابط
            copyLinkToClipboard();
        }
    }
    
    /**
     * نسخ الرابط إلى الحافظة
     */
    function copyLinkToClipboard() {
        if (!generatedFileUrl) {
            return;
        }
        
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(generatedFileUrl).then(function() {
                showAlert('success', 'تم نسخ رابط الملف إلى الحافظة');
            }).catch(function() {
                fallbackCopyToClipboard(generatedFileUrl);
            });
        } else {
            fallbackCopyToClipboard(generatedFileUrl);
        }
    }
    
    /**
     * طريقة بديلة لنسخ الرابط
     */
    function fallbackCopyToClipboard(text) {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.opacity = '0';
        document.body.appendChild(textArea);
        textArea.select();
        try {
            document.execCommand('copy');
            showAlert('success', 'تم نسخ رابط الملف إلى الحافظة');
        } catch (err) {
            showAlert('warning', 'تعذر نسخ الرابط تلقائياً. يرجى نسخه يدوياً: ' + text);
        }
        document.body.removeChild(textArea);
    }
    
    /**
     * إظهار تنبيه
     */
    function showAlert(type, message) {
        // البحث عن container للتنبيهات
        const alertContainer = document.querySelector('.customer-export-alerts') || document.body;
        
        const alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-' + type + ' alert-dismissible fade show';
        alertDiv.setAttribute('role', 'alert');
        alertDiv.innerHTML = message + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        
        alertContainer.appendChild(alertDiv);
        
        // إزالة التنبيه تلقائياً بعد 5 ثوان
        setTimeout(function() {
            if (alertDiv.parentNode) {
                alertDiv.remove();
            }
        }, 5000);
    }
    
    /**
     * تهريب HTML
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // تهيئة عند تحميل الصفحة
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initExportModal);
    } else {
        initExportModal();
    }
    
    // تصدير الدوال للاستخدام الخارجي
    window.customerExport = {
        openModal: function() {
            const modal = document.getElementById('customerExportModal');
            if (modal && typeof bootstrap !== 'undefined') {
                const bsModal = new bootstrap.Modal(modal);
                bsModal.show();
            }
        }
    };
    
})();

