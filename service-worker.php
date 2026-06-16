<?php
require __DIR__ . '/app_config.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Service-Worker-Allowed: ./');

$assetVersion = rawurlencode(APP_VERSION);
$cacheName = 'app-iperal-v' . APP_VERSION;
$staticAssets = [
    './app_core.js?v=' . $assetVersion,
    './app_calendar.js?v=' . $assetVersion,
    './app_notes.js?v=' . $assetVersion,
    './app_init.js?v=' . $assetVersion,
    './sfera.css?v=' . $assetVersion,
    './i_o_data.js',
    './manifest.json',
    './img/icon-192.png',
    './img/icon-512.png',
    './img/default.png',
    './img/avatar1.png',
    './img/avatar2.png',
    './img/avatar3.png',
];
?>
const CACHE_NAME = <?php echo json_encode($cacheName); ?>;
const STATIC_ASSETS = <?php echo json_encode($staticAssets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?>;
const STATIC_ASSET_URLS = new Set(
  STATIC_ASSETS.map((asset) => new URL(asset, self.location.href).href)
);

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS))
  );
});

self.addEventListener("message", (event) => {
  if (event.data && event.data.type === "SKIP_WAITING") {
    self.skipWaiting();
  }
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((key) => key !== CACHE_NAME)
          .map((key) => caches.delete(key))
      )
    )
  );
  self.clients.claim();
});

self.addEventListener("fetch", (event) => {
  if (event.request.method !== "GET") {
    return;
  }

  const url = new URL(event.request.url);

  if (url.origin !== self.location.origin) {
    return;
  }

  if (url.pathname.includes("/connection_files/") || url.pathname.includes("/note_json/")) {
    event.respondWith(
      fetch(event.request).catch(() => new Response(JSON.stringify({ ok: false, error: "offline" }), {
        status: 503,
        headers: { "Content-Type": "application/json; charset=utf-8" }
      }))
    );
    return;
  }

  if (event.request.mode === "navigate") {
    event.respondWith(
      fetch(event.request).catch(() => caches.match("./index.php"))
    );
    return;
  }

  if (!STATIC_ASSET_URLS.has(event.request.url)) {
    event.respondWith(
      fetch(event.request).catch(() => caches.match(event.request))
    );
    return;
  }

  event.respondWith(
    fetch(event.request).then((response) => {
      const copy = response.clone();
      caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
      return response;
    }).catch(() => caches.match(event.request))
  );
});
