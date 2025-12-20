const CACHE_VERSION = '1.0.5'; // Complete fix: Never intercept redirects to prevent Safari errors
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

  // استثناء طلبات التقارير (PDF/HTML) - يجب أن تمر مباشرة بدون تدخل
  const isReportRequest = url.pathname.includes('/reports/') || 
                          url.pathname.includes('/generated/') ||
                          url.pathname.includes('generate_report.php') ||
                          url.searchParams.has('print') ||
                          url.searchParams.has('view') ||
                          ((url.pathname.endsWith('.pdf') || url.pathname.endsWith('.html')) && 
                           (url.pathname.includes('report') || url.pathname.includes('packaging') || url.pathname.includes('raw_materials')));

  if (isReportRequest) {
    // للتقارير: السماح بالمرور مباشرة بدون تدخل service worker
    return;
  }

  // CRITICAL FIX: السماح لجميع navigation requests بالمرور مباشرة لتجنب Error Code: -2
  // Service worker لا يجب أن يعترض navigation requests في HTTPS
  if (isNavigationRequest) {
    // السماح للطلب بالمرور مباشرة بدون intercept
    // هذا يمنع Error Code: -2 و ERR_FAILED
    return;
  }

  // للصفحات (navigation requests): استخدام Network First مع Cache Fallback
  // تم تعطيله مؤقتاً لتجنب Error Code: -2
  if (false && isNavigationRequest) {
    // CRITICAL FIX: Safari لا يسمح بـ Service Worker بتقديم redirect responses
    // الحل: التحقق من redirects أولاً وإذا كان redirect، لا نعترضه نهائياً
    // FIX: السماح للطلبات بالمرور مباشرة إذا كان هناك مشكلة في الكاش لتجنب Error Code: -2
    event.respondWith(
      (async () => {
        try {
          // التحقق من الكاش أولاً - أسرع وأكثر أماناً
          const cache = await caches.open(NAVIGATION_CACHE_NAME);
          const cachedResponse = await cache.match(event.request);
          
          if (cachedResponse && !isRedirectResponse(cachedResponse)) {
            const isValid = await isCacheValid(event.request, NAVIGATION_CACHE_TTL);
            if (isValid) {
              return cachedResponse;
            } else {
              await cache.delete(event.request);
            }
          } else if (cachedResponse && isRedirectResponse(cachedResponse)) {
            await cache.delete(event.request);
          }
          
          // التحقق من الكاش العام
          const generalCache = await caches.open(CACHE_NAME);
          const generalCached = await generalCache.match(event.request);
          if (generalCached && !isRedirectResponse(generalCached)) {
            return generalCached;
          } else if (generalCached && isRedirectResponse(generalCached)) {
            await generalCache.delete(event.request);
          }
        
        // التحقق من الشبكة - استخدام redirect: 'manual' للكشف عن redirects
        try {
          const checkResponse = await fetch(event.request.clone(), {
            redirect: 'manual',
            cache: 'no-store'
          });
          
          // CRITICAL FIX: إذا كانت redirect، لا نعترضها أبداً
          // نستخدم HTML redirect page بدلاً من response redirect مباشر
          if (isRedirectResponse(checkResponse)) {
            const redirectUrl = checkResponse.headers.get('Location');
            if (redirectUrl) {
              // إرجاع صفحة HTML تحتوي على redirect - Safari يقبل هذا
              return new Response(
                `<!DOCTYPE html><html><head><meta charset="UTF-8"><meta http-equiv="refresh" content="0;url=${encodeURI(redirectUrl)}"><script>window.location.replace(${JSON.stringify(redirectUrl)});</script></head><body><p>جاري التوجيه...</p><a href="${encodeURI(redirectUrl)}">انقر هنا إذا لم يتم التوجيه تلقائياً</a></body></html>`,
                {
                  status: 200,
                  headers: {
                    'Content-Type': 'text/html; charset=utf-8',
                    'Cache-Control': 'no-store, no-cache, must-revalidate',
                    'Pragma': 'no-cache',
                    'Expires': '0'
                  }
                }
              );
            }
          }
          
          // إذا لم تكن redirect، جلب المحتوى النهائي
          const finalResponse = await fetch(event.request, {
            redirect: 'follow',
            cache: 'no-store'
          });
          
          // التأكد مرة أخرى أنها ليست redirect (بعد اتباع redirects)
          if (isRedirectResponse(finalResponse)) {
            const redirectUrl = finalResponse.headers.get('Location');
            if (redirectUrl) {
              return new Response(
                `<!DOCTYPE html><html><head><meta charset="UTF-8"><meta http-equiv="refresh" content="0;url=${encodeURI(redirectUrl)}"><script>window.location.replace(${JSON.stringify(redirectUrl)});</script></head><body><p>جاري التوجيه...</p><a href="${encodeURI(redirectUrl)}">انقر هنا إذا لم يتم التوجيه تلقائياً</a></body></html>`,
                {
                  status: 200,
                  headers: {
                    'Content-Type': 'text/html; charset=utf-8',
                    'Cache-Control': 'no-store, no-cache, must-revalidate',
                    'Pragma': 'no-cache',
                    'Expires': '0'
                  }
                }
              );
            }
            return finalResponse;
          }
          
          // حفظ في الكاش إذا كانت ناجحة وليست redirect
          if (finalResponse && finalResponse.status === 200 && finalResponse.type === 'basic') {
            const responseClone = finalResponse.clone();
            const cachedResponse = addCacheMetadata(responseClone);
            await cache.put(event.request, cachedResponse);
            await limitCacheSize(NAVIGATION_CACHE_NAME, MAX_NAVIGATION_CACHE_ITEMS);
          }
          
          return finalResponse;
        } catch (error) {
          // في حالة الخطأ (مثل Error Code: -2)، السماح للطلب بالمرور مباشرة
          // هذا يمنع service worker من التسبب في ERR_FAILED
          console.error('Service Worker fetch error:', error);
          
          // محاولة الكاش كحل أخير
          const offlinePage = await caches.match(BASE + 'offline.html');
          if (offlinePage) {
            return offlinePage;
          }
          
          // إذا فشل كل شيء، السماح للطلب بالمرور مباشرة بدون intercept
          // هذا يمنع Error Code: -2
          return fetch(event.request).catch(() => {
            return new Response('لا يوجد اتصال بالشبكة', { 
              status: 503,
              headers: { 'Content-Type': 'text/html; charset=utf-8' }
            });
          });
        }
      })().catch(error => {
        // FIX: إذا فشل كل شيء، السماح للطلب بالمرور مباشرة
        // هذا يمنع Error Code: -2 الذي يحدث عندما يحاول service worker intercept طلب فاشل
        console.error('Service Worker navigation request failed:', error);
        return fetch(event.request);
      })
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
          
          // CRITICAL FIX: إذا كانت redirect، التحقق من نوع الطلب
          if (isRedirectResponse(response)) {
            // للملفات الثابتة، إرجاع redirect مباشرة (عادة ما تكون 301/302 للـ CDN)
            // Safari يتعامل معها بشكل جيد للطلبات غير التنقلية
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
          
          // CRITICAL FIX: إذا كانت redirect، إرجاعها مباشرة (API requests عادة لا تكون navigation)
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
        // للطلبات غير التنقلية، Safari يتعامل مع redirects بشكل جيد
        if (isRedirectResponse(response)) {
          return response;
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
