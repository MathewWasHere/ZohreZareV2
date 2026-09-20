const CACHE_NAME = 'zohrezare-v22';
const STATIC_ASSETS = [
  './',
  './index.html',
  './services.html',
  './about.html',
  './auth.html',
  './booking.html',
  './service.html',
  './panel/index.html',
  './404.html',
  './account.html',
  './panel/admin/index.html',
  './assets/css/fonts.css',
  './assets/css/base.css',
  './assets/css/components.css',
  './assets/css/pages.css',
  './assets/js/core/config.js',
  './assets/js/core/store.js',
  './assets/js/core/utils.js',
  './assets/js/core/api.js',
  './assets/js/data/services.js',
  './assets/js/data/auth.js',
  './assets/js/data/appointments.js',
  './assets/js/data/backend-bridge.js',
  './assets/js/ui/icons.js',
  './assets/js/ui/toast.js',
  './assets/js/ui/dialog.js',
  './assets/js/ui/shell.js',
  './assets/js/pages/home.js',
  './assets/js/pages/services.js',
  './assets/js/pages/service-detail.js',
  './assets/js/pages/auth.js',
  './assets/js/pages/booking.js',
  './assets/js/pages/account.js',
  './assets/js/pages/admin.js',
  './assets/js/ui/hero-orbit.js',
  './assets/img/favicon.svg',
  './assets/img/brand/logo.png',
  './assets/img/brand/icon-192.png',
  './assets/img/portrait-cutout.webp',
  './assets/img/edited_v2.webp',
  './assets/img/banner-cta.jpg',
  './assets/img/banner-cta-mobile.jpg',
  './assets/img/banner-footer.jpg'
];

// نصب service worker و کش کردن فایل‌های استاتیک
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      console.log('Opening cache and adding static assets');
      return cache.addAll(STATIC_ASSETS);
    })
  );
  self.skipWaiting();
});

// فعال‌سازی و پاک کردن کش‌های قدیمی
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cacheName) => {
          if (cacheName !== CACHE_NAME) {
            console.log('Deleting old cache:', cacheName);
            return caches.delete(cacheName);
          }
        })
      );
    })
  );
  self.clients.claim();
});

// استراتژی واکشی
//
//   • صفحه‌ها (navigation): اول شبکه، بعد کش
//     قبلاً همه‌چیز «اول کش» بود؛ نتیجه این می‌شد که بعد از هر
//     به‌روزرسانی سایت، بازدیدکننده‌ی قدیمی هنوز نسخه‌ی کش‌شده را
//     می‌دید. حالا صفحه همیشه تازه گرفته می‌شود و کش فقط پشتیبانِ
//     حالت آفلاین است.
//
//   • فایل‌های ثابت (CSS/JS/تصویر/فونت): اول کش، بعد شبکه — سریع
//     و کم‌مصرف.
self.addEventListener('fetch', (event) => {
  const request = event.request;

  // فقط درخواست‌های GET قابل کش شدن هستند
  if (request.method !== 'GET') return;

  const url = new URL(request.url);

  // فقط same-origin
  if (url.origin !== location.origin) return;

  // درخواست‌های API هرگز کش نمی‌شوند
  if (url.pathname.startsWith('/api/')) return;

  const accept = request.headers.get('accept') || '';
  const isPage = request.mode === 'navigate' || accept.includes('text/html');

  if (isPage) {
    // اول شبکه
    event.respondWith(
      fetch(request)
        .then((response) => {
          if (response && response.status === 200 && response.type === 'basic') {
            const copy = response.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
          }
          return response;
        })
        .catch(() =>
          caches.match(request).then((cached) => cached || caches.match('./index.html'))
        )
    );
    return;
  }

  // اول کش
  event.respondWith(
    caches.match(request).then((cached) => {
      if (cached) return cached;

      return fetch(request)
        .then((response) => {
          if (!response || response.status !== 200 || response.type !== 'basic') {
            return response;
          }
          const copy = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
          return response;
        })
        .catch(() => cached);
    })
  );
});

// پشتیبان‌گیری از نوتیفیکیشن push (اختیاری برای آینده)
self.addEventListener('push', (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (e) {
    data = {};
  }
  const title = data.title || 'زهره زارع';
  const options = {
    body: data.body || 'پیام جدید',
    icon: data.icon || './assets/img/brand/icon-192.png',
    badge: './assets/img/favicon.svg'
  };

  event.waitUntil(
    self.registration.showNotification(title, options)
  );
});
