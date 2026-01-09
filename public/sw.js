/**
 * SAM - Service Worker
 * Estrategias de caching para funcionamiento offline y rendimiento optimizado
 */

const CACHE_VERSION = 'v1.0.7';
const STATIC_CACHE = `sam-static-${CACHE_VERSION}`;
const DYNAMIC_CACHE = `sam-dynamic-${CACHE_VERSION}`;
const API_CACHE = `sam-api-${CACHE_VERSION}`;

// Archivos esenciales para funcionamiento offline
// NO incluir '/' ni páginas HTML dinámicas para evitar cachear tokens CSRF viejos
const STATIC_ASSETS = [
  '/offline.html',
  '/favicon.ico',
  '/favicon.svg',
  '/logo.png',
  '/apple-touch-icon.png',
  '/manifest.webmanifest',
];

// Rutas que siempre deben ir a la red
// Estas rutas requieren autenticación o no deben ser cacheadas
const NETWORK_ONLY_PATTERNS = [
  /\/api\//,
  /\/telescope/,
  /\/sanctum/,
  /\/login/,
  /\/logout/,
  /\/register/,
  /\/csrf-cookie/,
  /\/storage\//,  // Archivos almacenados que requieren autenticación
  /\/copilot/,    // Copilot siempre necesita datos frescos
  /hot$/,
];

// Rutas de API que pueden ser cacheadas brevemente
const API_CACHE_PATTERNS = [
  /\/api\/vehicles/,
  /\/api\/conversations/,
];

/**
 * Instalación del Service Worker
 * Pre-cachea recursos estáticos esenciales
 */
self.addEventListener('install', (event) => {
  console.log('[SW] Installing Service Worker...');
  
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then((cache) => {
        console.log('[SW] Pre-caching static assets');
        return cache.addAll(STATIC_ASSETS.filter(asset => {
          // Solo cachear archivos que existan
          return !asset.includes('offline.html');
        })).catch(err => {
          console.log('[SW] Some static assets failed to cache:', err);
        });
      })
      .then(() => {
        console.log('[SW] Service Worker installed');
        return self.skipWaiting();
      })
  );
});

/**
 * Activación del Service Worker
 * Limpia caches antiguos y elimina cualquier cache de /storage/
 */
self.addEventListener('activate', (event) => {
  console.log('[SW] Activating Service Worker...');
  
  event.waitUntil(
    caches.keys()
      .then((cacheNames) => {
        const deletePromises = [];
        
        // Eliminar caches antiguos
        cacheNames
          .filter((cacheName) => {
            return cacheName.startsWith('sam-') && 
                   !cacheName.includes(CACHE_VERSION);
          })
          .forEach((cacheName) => {
            console.log('[SW] Deleting old cache:', cacheName);
            deletePromises.push(caches.delete(cacheName));
          });
        
        // Limpiar cualquier cache de /storage/ de todos los caches
        cacheNames.forEach((cacheName) => {
          deletePromises.push(
            caches.open(cacheName).then((cache) => {
              return cache.keys().then((keys) => {
                return Promise.all(
                  keys
                    .filter((request) => {
                      const url = new URL(request.url);
                      return url.pathname.startsWith('/storage/');
                    })
                    .map((request) => {
                      console.log('[SW] Deleting cached storage file:', request.url);
                      return cache.delete(request);
                    })
                );
              });
            })
          );
        });
        
        return Promise.all(deletePromises);
      })
      .then(() => {
        console.log('[SW] Service Worker activated');
        return self.clients.claim();
      })
  );
});

/**
 * Intercepta requests y aplica estrategias de caching
 */
self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);
  
  // Solo manejar requests del mismo origen y HTTPS/localhost
  if (url.origin !== location.origin) {
    return;
  }
  
  // Ignorar requests que no sean GET
  if (request.method !== 'GET') {
    return;
  }
  
  // NO interceptar rutas que requieren autenticación o deben ir directo al servidor
  // Esto permite que las cookies y credenciales se envíen correctamente
  if (NETWORK_ONLY_PATTERNS.some(pattern => pattern.test(url.pathname))) {
    // Log para depuración (solo en desarrollo)
    if (url.pathname.startsWith('/storage/')) {
      console.log('[SW] Bypassing /storage/ request:', url.pathname);
    }
    return; // Dejar que la request pase directamente sin interceptar
  }
  
  // Stale-while-revalidate para API cacheables
  if (API_CACHE_PATTERNS.some(pattern => pattern.test(url.pathname))) {
    event.respondWith(staleWhileRevalidate(request, API_CACHE, 300)); // 5 min TTL
    return;
  }
  
  // Cache-first para assets estáticos (JS, CSS, imágenes, fuentes)
  if (isStaticAsset(url.pathname)) {
    event.respondWith(cacheFirst(request, STATIC_CACHE));
    return;
  }
  
  // Network-only para navegación (páginas HTML)
  // NO cachear HTML para evitar tokens CSRF viejos
  if (request.mode === 'navigate') {
    event.respondWith(networkOnlyWithOfflineFallback(request));
    return;
  }
  
  // Default: Network-first
  event.respondWith(networkFirst(request, DYNAMIC_CACHE));
});

/**
 * Detecta si es un asset estático
 * Excluye /storage/ porque requiere autenticación
 */
function isStaticAsset(pathname) {
  // No cachear archivos en /storage/ porque requieren autenticación
  if (pathname.startsWith('/storage/')) {
    return false;
  }
  
  return /\.(js|css|png|jpg|jpeg|gif|svg|ico|woff|woff2|ttf|eot|webp|avif)(\?.*)?$/.test(pathname) ||
         pathname.includes('/build/');
}

/**
 * Estrategia: Cache First
 * Ideal para assets que no cambian frecuentemente
 */
async function cacheFirst(request, cacheName) {
  const cachedResponse = await caches.match(request);
  
  if (cachedResponse) {
    return cachedResponse;
  }
  
  try {
    const networkResponse = await fetch(request);
    
    if (networkResponse.ok) {
      const cache = await caches.open(cacheName);
      cache.put(request, networkResponse.clone());
    }
    
    return networkResponse;
  } catch (error) {
    console.log('[SW] Cache-first failed:', error);
    return new Response('Asset not available offline', { status: 503 });
  }
}

/**
 * Estrategia: Network First
 * Ideal para contenido dinámico que debe estar actualizado
 */
async function networkFirst(request, cacheName) {
  try {
    const networkResponse = await fetch(request);
    
    if (networkResponse.ok) {
      const cache = await caches.open(cacheName);
      cache.put(request, networkResponse.clone());
    }
    
    return networkResponse;
  } catch (error) {
    console.log('[SW] Network failed, trying cache:', request.url);
    
    const cachedResponse = await caches.match(request);
    
    if (cachedResponse) {
      return cachedResponse;
    }
    
    // Si es navegación, mostrar página offline
    if (request.mode === 'navigate') {
      return caches.match('/offline.html') || 
             new Response(getOfflineHTML(), {
               headers: { 'Content-Type': 'text/html' }
             });
    }
    
    return new Response('Network error', { status: 503 });
  }
}

/**
 * Estrategia: Network Only
 * Para requests que siempre deben ir a la red
 */
async function networkOnly(request) {
  try {
    return await fetch(request);
  } catch (error) {
    console.log('[SW] Network only failed:', error);
    return new Response('Network error', { status: 503 });
  }
}

/**
 * Estrategia: Network Only con fallback a offline
 * Para navegación - no cachea HTML para evitar tokens CSRF viejos
 */
async function networkOnlyWithOfflineFallback(request) {
  try {
    return await fetch(request);
  } catch (error) {
    console.log('[SW] Navigation failed, showing offline page');
    return caches.match('/offline.html') || 
           new Response(getOfflineHTML(), {
             headers: { 'Content-Type': 'text/html' }
           });
  }
}

/**
 * Estrategia: Stale While Revalidate
 * Devuelve cache inmediatamente y actualiza en background
 */
async function staleWhileRevalidate(request, cacheName, maxAge = 300) {
  const cache = await caches.open(cacheName);
  const cachedResponse = await cache.match(request);
  
  const fetchPromise = fetch(request).then((networkResponse) => {
    if (networkResponse.ok) {
      cache.put(request, networkResponse.clone());
    }
    return networkResponse;
  }).catch(() => cachedResponse);
  
  if (cachedResponse) {
    // Verificar si el cache es muy viejo
    const cachedDate = cachedResponse.headers.get('sw-cached-at');
    if (cachedDate) {
      const age = (Date.now() - parseInt(cachedDate)) / 1000;
      if (age > maxAge) {
        return fetchPromise;
      }
    }
    return cachedResponse;
  }
  
  return fetchPromise;
}

/**
 * HTML fallback para cuando no hay conexión
 */
function getOfflineHTML() {
  return `
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sin conexión - SAM</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    
    body {
      font-family: 'Instrument Sans', system-ui, sans-serif;
      background: linear-gradient(135deg, #0a0a0a 0%, #1a1a2e 50%, #0a0a0a 100%);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fafafa;
      padding: 20px;
    }
    
    .container {
      text-align: center;
      max-width: 400px;
    }
    
    .icon {
      width: 120px;
      height: 120px;
      margin: 0 auto 24px;
      background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
      border-radius: 24px;
      display: flex;
      align-items: center;
      justify-content: center;
      animation: pulse 2s infinite;
    }
    
    .icon svg {
      width: 64px;
      height: 64px;
      stroke: white;
      fill: none;
      stroke-width: 1.5;
    }
    
    @keyframes pulse {
      0%, 100% { transform: scale(1); opacity: 1; }
      50% { transform: scale(1.05); opacity: 0.8; }
    }
    
    h1 {
      font-size: 1.75rem;
      font-weight: 600;
      margin-bottom: 12px;
    }
    
    p {
      color: #a1a1aa;
      line-height: 1.6;
      margin-bottom: 24px;
    }
    
    button {
      background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
      color: white;
      border: none;
      padding: 12px 32px;
      border-radius: 12px;
      font-size: 1rem;
      font-weight: 500;
      cursor: pointer;
      transition: transform 0.2s, box-shadow 0.2s;
    }
    
    button:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(99, 102, 241, 0.3);
    }
    
    button:active {
      transform: translateY(0);
    }
    
    .status {
      margin-top: 24px;
      font-size: 0.875rem;
      color: #71717a;
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="icon">
      <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
        <path d="M18.36 6.64a9 9 0 1 1-12.73 0" />
        <line x1="12" y1="2" x2="12" y2="12" />
      </svg>
    </div>
    <h1>Sin conexión</h1>
    <p>Parece que no tienes conexión a internet. Verifica tu conexión e intenta de nuevo.</p>
    <button onclick="location.reload()">Reintentar</button>
    <p class="status">SAM funciona mejor con conexión a internet</p>
  </div>
  <script>
    // Detectar cuando vuelve la conexión
    window.addEventListener('online', () => {
      location.reload();
    });
  </script>
</body>
</html>
  `;
}

/**
 * Manejo de mensajes desde la aplicación
 */
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
  
  if (event.data && event.data.type === 'CLEAR_CACHE') {
    event.waitUntil(
      caches.keys().then((cacheNames) => {
        return Promise.all(
          cacheNames.map((cacheName) => caches.delete(cacheName))
        );
      })
    );
  }
  
  if (event.data && event.data.type === 'GET_VERSION') {
    event.ports[0].postMessage({ version: CACHE_VERSION });
  }
});

/**
 * Manejo de notificaciones push (preparado para implementación futura)
 */
self.addEventListener('push', (event) => {
  // Verificar si hay datos y si tenemos permiso de notificaciones
  if (!event.data) return;
  
  // Solo mostrar notificación si tenemos permiso
  if (Notification.permission !== 'granted') {
    console.log('[SW] Push received but notification permission not granted');
    return;
  }
  
  let data = {};
  
  // Intentar parsear como JSON, si falla usar el texto como body
  try {
    data = event.data.json();
  } catch (e) {
    // Si no es JSON válido, usar el texto como mensaje
    const text = event.data.text();
    data = {
      title: 'SAM',
      body: text || 'Nueva notificación',
    };
  }
  
  const options = {
    body: data.body || 'Nueva notificación de SAM',
    icon: '/icons/icon-192x192.png',
    badge: '/icons/badge-72x72.png',
    vibrate: [100, 50, 100],
    data: {
      url: data.url || '/',
      dateOfArrival: Date.now(),
    },
    actions: data.actions || [
      { action: 'open', title: 'Abrir' },
      { action: 'close', title: 'Cerrar' },
    ],
  };
  
  event.waitUntil(
    self.registration.showNotification(data.title || 'SAM', options)
  );
});

/**
 * Manejo de click en notificaciones
 */
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  
  if (event.action === 'close') return;
  
  const urlToOpen = event.notification.data?.url || '/';
  
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true })
      .then((windowClients) => {
        // Buscar ventana existente
        for (const client of windowClients) {
          if (client.url.includes(location.origin) && 'focus' in client) {
            client.navigate(urlToOpen);
            return client.focus();
          }
        }
        // Abrir nueva ventana
        return clients.openWindow(urlToOpen);
      })
  );
});

/**
 * Background Sync (preparado para implementación futura)
 */
self.addEventListener('sync', (event) => {
  if (event.tag === 'sync-messages') {
    event.waitUntil(syncMessages());
  }
});

async function syncMessages() {
  // Implementación futura para sincronizar mensajes offline
  console.log('[SW] Syncing offline messages...');
}

console.log('[SW] Service Worker loaded');

