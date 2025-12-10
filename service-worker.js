const CACHE_VERSION = '1.0.4'; // Fixed redirect handling to prevent service worker errors
const CACHE_NAME = 'company-management-v' + CACHE_VERSION;
const NAVIGATION_CACHE_NAME = 'navigation-cache-v' + CACHE_VERSION;
const BASE = '/v1/';

// تقليل الملفات الأساسية للكاش لتسريع التحميل
const CORE_CACHE = [
  BASE + 'offline.html',
  BASE + 'assets/icons/icon-192x192.png',
  BASE + 'assets/icons/icon-96x96.png'
];

const MAX_DYNAMIC_CACHE_ITEMS = 50; // زيادة حجم الكاش للصفحات
const MAX_NAVIGATION_CACHE_ITEMS = 20; // كاش خاص للصفحات الشائعة
const NAVIGATION_CACHE_TTL = 5 * 60 * 1000; // 5 دقائق للصفحات

// Install event - Cache essential files (non-blocking for faster startup)
self.addEventListener('install', event => {
  // استخدام skipWaiting فوراً لتسريع التفعيل
  self.skipWaiting();
  
  // تحميل الملفات الأساسية في الخلفية بدون انتظار
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => {
        // تحميل الملفات بشكل متوازي لتسريع العملية
        return Promise.allSettled(
          CORE_CACHE.map(url => 
            cache.add(url).catch(() => {}) // تجاهل الأخطاء الفردية
          )
        );
      })
      .catch(() => {}) // تجاهل الأخطاء العامة
  );
});

// Helper function to check if response is a redirect
function isRedirectResponse(response) {
  if (!response) return false;
  // Check status code (300-399 are redirects)
  if (response.status >= 300 && response.status < 400) return true;
  // Check response type (opaqueredirect is a redirect)
  if (response.type === 'opaqueredirect') return true;
  return false;
}

// Helper to clean redirect responses from cache
async function cleanRedirectsFromCache(cacheName) {
  try {
    const cache = await caches.open(cacheName);
    const keys = await cache.keys();
    const deletePromises = [];
    
    for (const key of keys) {
      const response = await cache.match(key);
      if (response && isRedirectResponse(response)) {
        deletePromises.push(cache.delete(key));
      }
    }
    
    await Promise.all(deletePromises);
  } catch (e) {
    // تجاهل الأخطاء
  }
}

// Activate event - Clean old caches and remove redirects
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys.map(key => {
          if (key !== CACHE_NAME && key !== NAVIGATION_CACHE_NAME) {
            return caches.delete(key);
          }
        })
      )
    ).then(async () => {
      // CRITICAL FIX: تنظيف أي redirects موجودة في الكاش
      await cleanRedirectsFromCache(CACHE_NAME);
      await cleanRedirectsFromCache(NAVIGATION_CACHE_NAME);
      self.clients.claim();
    })
  );
});

// Helper to limit cache size
async function limitCacheSize(cacheName, maxItems) {
  const cache = await caches.open(cacheName);
  const keys = await cache.keys();
  if (keys.length > maxItems) {
    // حذف أقدم عنصر
    await cache.delete(keys[0]);
    await limitCacheSize(cacheName, maxItems);
  }
}

// Helper to check if cache entry is still valid
async function isCacheValid(request, maxAge) {
  try {
    const cache = await caches.open(NAVIGATION_CACHE_NAME);
    const cachedResponse = await cache.match(request);
    if (!cachedResponse) return false;
    
    const cachedDate = cachedResponse.headers.get('sw-cached-date');
    if (!cachedDate) return false;
    
    const age = Date.now() - parseInt(cachedDate);
    return age < maxAge;
  } catch (e) {
    return false;
  }
}

// Helper to add cache metadata
function addCacheMetadata(response) {
  const headers = new Headers(response.headers);
  headers.set('sw-cached-date', Date.now().toString());
  return new Response(response.body, {
    status: response.status,
    statusText: response.statusText,
    headers: headers
  });
}

// Fetch event - smart caching with error and CSP handling
self.addEventListener('fetch', event => {
  if (event.request.method !== 'GET') return;

  const url = new URL(event.request.url);

  // Block requests to infinityfree errors domain
  if (url.hostname.includes('infinityfree') || url.hostname.includes('errors.infinityfree.net')) {
    event.respondWith(new Response('', { status: 200 }));
    return;
  }

  // Skip service worker interception for external domains (CDN, APIs) to avoid CSP issues
  // Allow external requests to pass through without service worker handling
  const externalDomains = [
    'code.jquery.com',
    'api.qrserver.com',
    'cdn.jsdelivr.net',
    'fonts.googleapis.com',
    'fonts.gstatic.com'
  ];
  
  const isExternalDomain = externalDomains.some(domain => url.hostname.includes(domain));
  
  if (isExternalDomain) {
    // For external domains, don't intercept - let browser handle directly
    // This prevents CSP violations from service worker fetch attempts
    return;
  }

  // تحديد نوع الطلب
  const isNavigationRequest = event.request.mode === 'navigate' || 
                               event.request.destination === 'document' ||
                               event.request.headers.get('accept')?.includes('text/html');

  // للصفحات (navigation requests): استخدام Network First مع Cache Fallback
  if (isNavigationRequest) {
    event.respondWith(
      (async () => {
        // محاولة جلب من الشبكة أولاً
        try {
          const networkResponse = await fetch(event.request, {
            redirect: 'follow',
            cache: 'no-store' // عدم استخدام كاش المتصفح
          });
          
          // CRITICAL FIX: إذا كانت الاستجابة redirect، لا نعترضها - ندع المتصفح يتعامل معها
          if (isRedirectResponse(networkResponse)) {
            // لا نستخدم event.respondWith() للـ redirects - ندع المتصفح يتعامل معها
            // لكن بما أننا استخدمنا event.respondWith() بالفعل، يجب إرجاع الاستجابة
            // لكن الأفضل هو عدم الاعتراض من الأساس
            return networkResponse;
          }
          
          // إذا كانت الاستجابة ناجحة وليست redirect
          if (networkResponse && networkResponse.status === 200 && networkResponse.type === 'basic') {
            // حفظ في كاش التنقل
            const responseClone = networkResponse.clone();
            const cache = await caches.open(NAVIGATION_CACHE_NAME);
            const cachedResponse = addCacheMetadata(responseClone);
            await cache.put(event.request, cachedResponse);
            await limitCacheSize(NAVIGATION_CACHE_NAME, MAX_NAVIGATION_CACHE_ITEMS);
            
            return networkResponse;
          }
        } catch (error) {
          // في حالة خطأ الشبكة، محاولة الكاش
        }
        
        // محاولة جلب من الكاش
        const cache = await caches.open(NAVIGATION_CACHE_NAME);
        const cachedResponse = await cache.match(event.request);
        
        if (cachedResponse) {
          // CRITICAL FIX: التحقق من أن الكاش ليس redirect
          if (isRedirectResponse(cachedResponse)) {
            // حذف redirect من الكاش
            await cache.delete(event.request);
          } else {
            // التحقق من صلاحية الكاش
            const isValid = await isCacheValid(event.request, NAVIGATION_CACHE_TTL);
            if (isValid) {
              return cachedResponse;
            } else {
              // حذف الكاش القديم
              await cache.delete(event.request);
            }
          }
        }
        
        // إذا لم يكن في الكاش، محاولة جلب من الكاش العام
        const generalCache = await caches.open(CACHE_NAME);
        const generalCached = await generalCache.match(event.request);
        if (generalCached) {
          // CRITICAL FIX: التحقق من أن الكاش ليس redirect
          if (isRedirectResponse(generalCached)) {
            await generalCache.delete(event.request);
          } else {
            return generalCached;
          }
        }
        
        // إذا فشل كل شيء، إرجاع offline page
        return caches.match(BASE + 'offline.html') || new Response('', { status: 503 });
      })()
    );
    return;
  }
  
  // تخطي PHP files مع query parameters (ديناميكية)
  const skipInterception = 
    (url.pathname.endsWith('.php') && url.search.length > 0) ||
    url.pathname === '/' || 
    url.pathname === BASE || 
    url.pathname === BASE.slice(0, -1);

  if (skipInterception) {
    return;
  }

  // استراتيجية محسّنة للأداء: Cache First للملفات الثابتة، Network First للباقي
  event.respondWith(
    (async () => {
      // للملفات الثابتة: Cache First (أسرع)
      const isStaticAsset = /\.(css|js|png|jpg|jpeg|svg|gif|woff2?|ico|webp)$/i.test(url.pathname);
      
      if (isStaticAsset) {
        // محاولة الكاش أولاً (أسرع)
        const cachedResponse = await caches.match(event.request);
        if (cachedResponse) {
          // CRITICAL FIX: التحقق من أن الكاش ليس redirect
          if (isRedirectResponse(cachedResponse)) {
            // حذف redirect من الكاش
            const cache = await caches.open(CACHE_NAME);
            await cache.delete(event.request);
          } else {
            // جلب من الشبكة في الخلفية لتحديث الكاش (Stale-While-Revalidate)
            fetch(event.request, {
              redirect: 'follow',
              cache: 'reload'
            }).then(response => {
              // CRITICAL FIX: لا نحفظ redirects في الكاش
              if (response && !isRedirectResponse(response) && response.status === 200 && response.type === 'basic') {
                const responseClone = response.clone();
                caches.open(CACHE_NAME).then(cache => {
                  cache.put(event.request, responseClone);
                  limitCacheSize(CACHE_NAME, MAX_DYNAMIC_CACHE_ITEMS);
                });
              }
            }).catch(() => {}); // تجاهل الأخطاء في التحديث
            
            return cachedResponse; // إرجاع من الكاش فوراً
          }
        }
        
        // إذا لم يكن في الكاش، جلب من الشبكة وتخزينه
        try {
          const response = await fetch(event.request, {
            redirect: 'follow',
            cache: 'reload'
          });
          
          // CRITICAL FIX: إذا كانت redirect، إرجاعها مباشرة بدون حفظ في الكاش
          if (isRedirectResponse(response)) {
            return response;
          }
          
          if (response && response.status === 200 && response.type === 'basic') {
            const responseClone = response.clone();
            const cache = await caches.open(CACHE_NAME);
            await cache.put(event.request, responseClone);
            await limitCacheSize(CACHE_NAME, MAX_DYNAMIC_CACHE_ITEMS);
          }
          
          return response;
        } catch (error) {
          // في حالة الخطأ، إرجاع offline page
          return caches.match(BASE + 'offline.html') || new Response('', { status: 503 });
        }
      }
      
      // للـ API requests: Network First مع Cache Fallback
      const isApiRequest = url.pathname.includes('/api/');
      
      if (isApiRequest) {
        try {
          const response = await fetch(event.request, {
            redirect: 'follow',
            cache: 'no-store',
            credentials: 'same-origin'
          });
          
          // CRITICAL FIX: إذا كانت redirect، إرجاعها مباشرة
          if (isRedirectResponse(response)) {
            return response;
          }
          
          // التحقق من أن الاستجابة صالحة
          if (!response.ok && response.status >= 500) {
            throw new Error('Server error');
          }
          
          // لا نحفظ API responses في الكاش (ديناميكية)
          return response;
        } catch (error) {
          // في حالة الخطأ، إرجاع response خطأ JSON بدلاً من offline.html
          // لكن فقط إذا كان الطلب فعلاً فشل (network error)
          // إذا كان هناك خطأ آخر، نتركه يمر للكود الأصلي
          if (error.message && (error.message.includes('Failed to fetch') || error.message.includes('NetworkError'))) {
            return new Response(JSON.stringify({
              success: false,
              error: 'لا يوجد اتصال بالشبكة',
              message: 'لا يوجد اتصال بالإنترنت. يرجى التحقق من الاتصال والمحاولة مرة أخرى.'
            }), {
              status: 503,
              statusText: 'Service Unavailable',
              headers: { 
                'Content-Type': 'application/json; charset=utf-8',
                'Cache-Control': 'no-store'
              }
            });
          }
          // إذا كان خطأ آخر، نعيده للكود الأصلي
          throw error;
        }
      }
      
      // للباقي: Network First مع Cache Fallback
      try {
        const response = await fetch(event.request, {
          redirect: 'follow'
        });
        
        // CRITICAL FIX: إذا كانت redirect، إرجاعها مباشرة بدون حفظ في الكاش
        if (isRedirectResponse(response)) {
          return response; // إرجاع إعادة التوجيه كما هي - لا نحفظها في الكاش
        }
        
        // حفظ في الكاش إذا كانت ناجحة وليست redirect
        if (response && response.status === 200 && response.type === 'basic') {
          const responseClone = response.clone();
          const cache = await caches.open(CACHE_NAME);
          await cache.put(event.request, responseClone);
          await limitCacheSize(CACHE_NAME, MAX_DYNAMIC_CACHE_ITEMS);
        }
        
        return response;
      } catch (error) {
        // في حالة الخطأ، محاولة جلب من الكاش
        const cachedResponse = await caches.match(event.request);
        if (cachedResponse) {
          // CRITICAL FIX: التحقق من أن الكاش ليس redirect
          if (isRedirectResponse(cachedResponse)) {
            // حذف redirect من الكاش
            const cache = await caches.open(CACHE_NAME);
            await cache.delete(event.request);
            // إرجاع offline page بدلاً من ذلك
            return caches.match(BASE + 'offline.html') || new Response('', { status: 503 });
          }
          return cachedResponse;
        }
        
        // إذا لم يكن في الكاش، إرجاع offline page
        return caches.match(BASE + 'offline.html') || new Response('', { status: 503 });
      }
    })()
  );
});

// Background sync
self.addEventListener('sync', event => {
  if (event.tag === 'sync-data') {
    event.waitUntil(Promise.resolve());
  }
});

// Push notifications
self.addEventListener('push', event => {
  const options = {
    body: event.data ? event.data.text() : 'إشعار جديد',
    icon: BASE + 'assets/icons/icon-192x192.png',
    badge: BASE + 'assets/icons/icon-96x96.png',
    vibrate: [200, 100, 200],
    tag: 'notification',
    requireInteraction: true
  };

  event.waitUntil(
    self.registration.showNotification('نظام الإدارة الخاص بشركة البركة', options)
  );
});

// Notification click
self.addEventListener('notificationclick', event => {
  event.notification.close();
  event.waitUntil(clients.openWindow(BASE));
});
