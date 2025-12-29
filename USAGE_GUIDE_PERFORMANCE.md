# دليل استخدام تحسينات الأداء

## نظرة عامة

تم تطبيق تحسينات شاملة لتحسين سرعة الموقع على المتصفح العادي. هذا الدليل يوضح كيفية استخدام هذه التحسينات.

## 1. Service Worker

### التفعيل التلقائي
Service Worker يتم تسجيله تلقائياً عند تحميل الصفحة. لا حاجة لإجراءات إضافية.

### التحقق من التفعيل
```javascript
// في Console المتصفح
navigator.serviceWorker.getRegistrations().then(regs => {
    console.log('Service Workers:', regs.length);
});
```

### تحديث Service Worker
عند تحديث `CACHE_VERSION` في `service-worker.js`، سيتم تحديث الكاش تلقائياً.

## 2. Local Storage Cache

### الاستخدام الأساسي

#### حفظ بيانات
```javascript
// حفظ بيانات بسيطة
LocalStorageCache.save('user_preferences', {
    theme: 'dark',
    language: 'ar'
}, 3600000); // صلاحية ساعة

// حفظ بيانات API response
LocalStorageCache.cacheApi('/api/user-data', userData, 1800000); // 30 دقيقة
```

#### جلب بيانات
```javascript
// جلب بيانات محفوظة
const preferences = LocalStorageCache.get('user_preferences');
if (preferences) {
    console.log('User preferences:', preferences);
}

// جلب API response من الكاش
const cachedData = await LocalStorageCache.getCachedApi('/api/user-data');
if (cachedData) {
    console.log('Cached data:', cachedData);
}
```

#### استخدام cachedFetch
```javascript
// استخدام cachedFetch بدلاً من fetch العادي
const response = await LocalStorageCache.cachedFetch('/api/notifications', {
    method: 'GET',
    headers: {
        'Content-Type': 'application/json'
    }
}, 60000); // صلاحية دقيقة واحدة

const data = await response.json();
```

### مثال: تحسين تحميل الإشعارات
```javascript
// في notifications.js
async function loadNotifications() {
    const apiPath = getApiPath('api/notifications.php');
    
    // محاولة جلب من الكاش أولاً
    const cached = await LocalStorageCache.getCachedApi(apiPath + '?action=list');
    if (cached) {
        // استخدام البيانات المخزنة
        updateNotificationList(cached.data);
        
        // جلب البيانات الجديدة في الخلفية
        LocalStorageCache.cachedFetch(apiPath + '?action=list', {
            method: 'GET'
        }, 30000).then(response => {
            if (response.ok) {
                return response.json();
            }
        }).then(data => {
            if (data && data.success) {
                updateNotificationList(data.data);
            }
        });
        
        return;
    }
    
    // إذا لم تكن هناك بيانات مخزنة، جلب من الشبكة
    const response = await fetch(apiPath + '?action=list');
    const data = await response.json();
    
    if (data && data.success) {
        LocalStorageCache.cacheApi(apiPath + '?action=list', data.data, 30000);
        updateNotificationList(data.data);
    }
}
```

## 3. Lazy Loading

### للصور
```html
<!-- استخدام data-src بدلاً من src -->
<img data-src="path/to/image.jpg" alt="Description" class="lazy-image">

<!-- أو استخدام loading="lazy" natively -->
<img src="path/to/image.jpg" alt="Description" loading="lazy">
```

### للـ iframes
```html
<iframe data-src="https://example.com" width="100%" height="400"></iframe>
```

### للـ scripts
```html
<script data-src="path/to/script.js" defer></script>
```

### للـ CSS
```html
<link rel="stylesheet" data-href="path/to/styles.css">
```

### في JavaScript الديناميكي
```javascript
// عند إضافة صور ديناميكياً
function addImage(src) {
    const img = document.createElement('img');
    img.dataset.src = src; // استخدام data-src
    img.alt = 'Description';
    img.className = 'lazy-image';
    document.body.appendChild(img);
    
    // LazyLoading سيكتشفها تلقائياً
}
```

## 4. تحسين تحميل الخطوط

### Preconnect (تم تطبيقه تلقائياً)
```html
<!-- في header.php - تم تطبيقه بالفعل -->
<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
```

### Font Display Swap (تم تطبيقه تلقائياً)
```css
/* في header.php - تم تطبيقه بالفعل */
@font-face {
    font-family: 'Cairo';
    font-display: swap;
}
```

## 5. تحسين تحميل CSS/JS

### CSS غير المتزامن (تم تطبيقه تلقائياً)
- CSS الحرجة: تحميل مباشر
- CSS غير الحرجة: تحميل غير متزامن على الموبايل

### JavaScript مع defer (تم تطبيقه تلقائياً)
- جميع ملفات JS تستخدم `defer`
- JS غير الحرجة: تحميل بعد `load` event على الموبايل

## أمثلة عملية

### مثال 1: تحسين تحميل البيانات
```javascript
// قبل التحسين
async function loadUserData() {
    const response = await fetch('/api/user-data');
    const data = await response.json();
    return data;
}

// بعد التحسين
async function loadUserData() {
    // محاولة جلب من الكاش
    const cached = await LocalStorageCache.getCachedApi('/api/user-data');
    if (cached) {
        // إرجاع البيانات المخزنة فوراً
        return cached;
    }
    
    // جلب من الشبكة
    const response = await LocalStorageCache.cachedFetch('/api/user-data', {
        method: 'GET'
    }, 300000); // صلاحية 5 دقائق
    
    const data = await response.json();
    return data;
}
```

### مثال 2: تحسين تحميل الصور
```html
<!-- قبل التحسين -->
<img src="large-image.jpg" alt="Large Image">

<!-- بعد التحسين -->
<img data-src="large-image.jpg" alt="Large Image" loading="lazy">
```

### مثال 3: تحسين تحميل المحتوى الديناميكي
```javascript
// تحميل محتوى عند الحاجة فقط
function loadDynamicContent() {
    const container = document.getElementById('dynamic-content');
    
    // إنشاء iframe مع lazy loading
    const iframe = document.createElement('iframe');
    iframe.dataset.src = '/dynamic-content.php';
    iframe.width = '100%';
    iframe.height = '400';
    iframe.style.border = 'none';
    
    container.appendChild(iframe);
    // LazyLoading سيكتشفه تلقائياً
}
```

## أفضل الممارسات

### 1. استخدام الكاش بحكمة
- **استخدم الكاش للبيانات التي لا تتغير كثيراً**: مثل إعدادات المستخدم، البيانات الثابتة
- **لا تستخدم الكاش للبيانات الحساسة**: مثل معلومات الدفع، البيانات الشخصية الحساسة
- **حدد صلاحية مناسبة**: حسب طبيعة البيانات

### 2. Lazy Loading
- **استخدم lazy loading للصور الكبيرة**: خاصة الصور التي ليست في viewport الأولي
- **استخدم lazy loading للـ iframes**: خاصة المحتوى الخارجي
- **تجنب lazy loading للمحتوى الحرجة**: مثل الصور في hero section

### 3. تحسين الخطوط
- **استخدم font-display: swap**: لجميع الخطوط المخصصة
- **أضف fallback fonts**: في CSS
- **استخدم preconnect**: للخطوط من CDN

### 4. تحميل الموارد
- **استخدم defer للـ JS**: لتجنب blocking
- **استخدم async للـ CSS غير الحرجة**: على الموبايل
- **استخدم preload**: للموارد الحرجة فقط

## استكشاف الأخطاء

### Service Worker لا يعمل
```javascript
// التحقق من التسجيل
navigator.serviceWorker.getRegistrations().then(regs => {
    if (regs.length === 0) {
        console.warn('Service Worker not registered');
    }
});

// التحقق من الأخطاء
navigator.serviceWorker.addEventListener('error', event => {
    console.error('Service Worker error:', event);
});
```

### Local Storage Cache لا يعمل
```javascript
// التحقق من التهيئة
if (!window.LocalStorageCache) {
    console.error('LocalStorageCache not loaded');
}

// التحقق من IndexedDB
if (!('indexedDB' in window)) {
    console.warn('IndexedDB not supported, using localStorage only');
}
```

### Lazy Loading لا يعمل
```javascript
// التحقق من Intersection Observer
if (!('IntersectionObserver' in window)) {
    console.warn('IntersectionObserver not supported');
}

// تهيئة يدوية
if (window.LazyLoading) {
    window.LazyLoading.init();
}
```

## المراجع
- [Service Worker API](https://developer.mozilla.org/en-US/docs/Web/API/Service_Worker_API)
- [IndexedDB API](https://developer.mozilla.org/en-US/docs/Web/API/IndexedDB_API)
- [Intersection Observer API](https://developer.mozilla.org/en-US/docs/Web/API/Intersection_Observer_API)
- [Web Performance](https://web.dev/performance/)

