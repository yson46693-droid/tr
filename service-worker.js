const CACHE_VERSION = '1.0.2'; // Updated for mobile performance
const CACHE_NAME = 'company-management-v' + CACHE_VERSION;
const BASE = '/v1/';

// تقليل الملفات الأساسية للكاش لتسريع التحميل
const CORE_CACHE = [
  BASE + 'offline.html',
  BASE + 'assets/icons/icon-192x192.png',
  BASE + 'assets/icons/icon-96x96.png'
];

const MAX_DYNAMIC_CACHE_ITEMS = 30; // تقليل حجم الكاش للأداء الأفضل

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

// Activate event - Clean old caches
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys.map(key => {
          if (key !== CACHE_NAME) return caches.delete(key);
        })
      )
    ).then(() => self.clients.claim())
  );
});

// Helper to limit cache size
async function limitCacheSize(cacheName, maxItems) {
  const cache = await caches.open(cacheName);
  const keys = await cache.keys();
  if (keys.length > maxItems) {
    await cache.delete(keys[0]);
    await limitCacheSize(cacheName, maxItems);
  }
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

  // CRITICAL FIX: Don't intercept navigation requests or requests that might result in redirects
  // This prevents "Response served by service worker has redirections" error
  // Navigation requests (document requests) often involve redirects and must be handled by browser
  const isNavigationRequest = event.request.mode === 'navigate' || 
                               event.request.destination === 'document' ||
                               event.request.headers.get('accept')?.includes('text/html');

  // Skip interception for PHP files, root paths, and dynamic URLs that might redirect
  const skipInterception = 
    isNavigationRequest ||
    url.pathname.endsWith('.php') || 
    url.pathname === '/' || 
    url.pathname === BASE || 
    url.pathname === BASE.slice(0, -1) ||
    (!url.pathname.includes('.') && !url.pathname.endsWith('/')) ||
    url.search.length > 0; // Skip URLs with query parameters (often dynamic/redirects)

  if (skipInterception) {
    // Let browser handle these requests directly - no service worker interception
    // This prevents redirect errors completely
    return;
  }

  // استراتيجية محسّنة للأداء: Cache First للملفات الثابتة، Network First للباقي
  event.respondWith(
    (async () => {
      // للملفات الثابتة: Cache First (أسرع)
      const isStaticAsset = /\.(css|js|png|jpg|jpeg|svg|gif|woff2?|ico|webp)$/i.test(url.pathname);
      
      if (isStaticAsset) {
        const cachedResponse = await caches.match(event.request);
        if (cachedResponse) {
          // إرجاع من الكاش فوراً (أسرع)
          return cachedResponse;
        }
        
        // إذا لم يكن في الكاش، جلب من الشبكة وتخزينه
        try {
          const response = await fetch(event.request, {
            redirect: 'follow',
            cache: 'reload' // التأكد من الحصول على أحدث نسخة
          });
          
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
      
      // للباقي: Network First (للحصول على أحدث البيانات)
      try {
        const response = await fetch(event.request, {
          redirect: 'follow'
        });
        
        // CRITICAL FIX: Never cache redirect responses
        if (response && response.status >= 300 && response.status < 400) {
          return response; // إرجاع إعادة التوجيه كما هي
        }
        
        return response;
      } catch (error) {
        // في حالة الخطأ، محاولة جلب من الكاش
        const cachedResponse = await caches.match(event.request);
        if (cachedResponse) {
          // CRITICAL FIX: Check if cached response is a redirect and don't serve it
          if (cachedResponse.status >= 300 && cachedResponse.status < 400) {
            // Delete the cached redirect
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
