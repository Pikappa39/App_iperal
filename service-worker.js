const CACHE_NAME = "app-iperal-v5";
const STATIC_ASSETS = [
  "./script.js?v=5",
  "./sfera.css?v=5",
  "./i_o_data.js",
  "./manifest.json",
  "./img/icon-192.png",
  "./img/icon-512.png",
  "./img/default.png",
  "./img/avatar1.png",
  "./img/avatar2.png",
  "./img/avatar3.png"
];
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

  if (event.request.mode === "navigate") {
    event.respondWith(
      fetch(event.request)
    );
    return;
  }

  if (!STATIC_ASSET_URLS.has(event.request.url)) {
    event.respondWith(fetch(event.request));
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
