<?php
require __DIR__ . '/app_config.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Service-Worker-Allowed: ./');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$assetVersion = rawurlencode(APP_VERSION);
$cacheName = 'app-iperal-v' . APP_VERSION;
$staticAssets = [
    './app_core.js?v=' . $assetVersion,
    './app_init.js?v=' . $assetVersion,
    './sfera.css?v=' . $assetVersion,
    './manifest.php?v=' . $assetVersion,
    './img/icon-192.png?v=' . $assetVersion,
    './img/default.webp?v=' . $assetVersion,
];
?>
const CACHE_NAME = <?php echo json_encode($cacheName); ?>;
const APP_VERSION = <?php echo json_encode(APP_VERSION); ?>;
const STATIC_ASSETS = <?php echo json_encode($staticAssets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?>;
const STATIC_ASSET_URLS = new Set(
  STATIC_ASSETS.map((asset) => new URL(asset, self.location.href).href)
);

function offlineJsonResponse() {
  return new Response(JSON.stringify({ ok: false, error: "offline" }), {
    status: 503,
    headers: { "Content-Type": "application/json; charset=utf-8" }
  });
}

function offlinePageResponse() {
  return new Response("Offline", {
    status: 503,
    headers: { "Content-Type": "text/plain; charset=utf-8" }
  });
}

async function notifyOpenClients(payload) {
  const clients = await self.clients.matchAll({
    type: "window",
    includeUncontrolled: true,
  });
  await Promise.all(clients.map((client) => client.postMessage({
    type: "APP_PUSH_RECEIVED",
    payload,
  })));
}

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS))
  );
});

self.addEventListener("message", (event) => {
  if (event.data && event.data.type === "SKIP_WAITING") {
    self.skipWaiting();
  }
  if (event.data && event.data.type === "CLEAR_APP_CACHE") {
    event.waitUntil(
      caches.keys().then((keys) => Promise.all(keys.map((key) => caches.delete(key))))
    );
  }
});

self.addEventListener("push", (event) => {
  let payload = {};

  try {
    payload = event.data ? event.data.json() : {};
  } catch (error) {
    payload = {
      title: "App Iperal",
      body: event.data ? event.data.text() : "",
    };
  }

  event.waitUntil((async () => {
    // Una subscription può restare nel browser dopo un logout. Prima di
    // mostrare qualunque push, il server verifica sessione e proprietario.
    const subscription = await self.registration.pushManager.getSubscription();
    if (!subscription) return;

    try {
      const response = await fetch("./connection_files/push_delivery_allowed.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin",
        cache: "no-store",
        body: JSON.stringify({
          endpoint: subscription.endpoint,
          recipient_cf: payload.recipient_cf || "",
        }),
      });
      const verification = await response.json();
      if (!response.ok || !verification.ok || !verification.allowed) return;
    } catch (error) {
      // Senza una verifica positiva non mostriamo dati di altri account.
      return;
    }

    await notifyOpenClients(payload);

    const title = payload.title || "App Iperal";
    const options = {
      body: payload.body || "Hai una nuova notifica",
      icon: "./img/icon-192.png",
      badge: "./img/icon-192.png",
      tag: payload.tag || payload.type || undefined,
      data: {
        url: payload.url || "./index.php",
        payload,
      },
    };

    await self.registration.showNotification(title, options);
  })());
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();

  const targetPath = (event.notification && event.notification.data && event.notification.data.url) || "./index.php";
  const targetUrl = new URL(targetPath, self.location.href).href;

  event.waitUntil((async () => {
    const allClients = await self.clients.matchAll({
      type: "window",
      includeUncontrolled: true,
    });

    for (const client of allClients) {
      if ("focus" in client) {
        client.navigate(targetUrl);
        return client.focus();
      }
    }

    if (self.clients.openWindow) {
      return self.clients.openWindow(targetUrl);
    }

    return undefined;
  })());
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

  if (url.pathname.endsWith("/__app_sw_status__")) {
    event.respondWith(
      new Response(JSON.stringify({ ok: true, version: APP_VERSION, cache: CACHE_NAME }), {
        headers: { "Content-Type": "application/json; charset=utf-8" }
      })
    );
    return;
  }

  if (url.pathname.includes("/connection_files/") || url.pathname.includes("/note_json/")) {
    event.respondWith(
      fetch(event.request).catch(() => offlineJsonResponse())
    );
    return;
  }

  if (event.request.mode === "navigate") {
    event.respondWith(
      fetch(event.request).catch(() =>
        caches.match("./index.php").then((response) => response || offlinePageResponse())
      )
    );
    return;
  }

  if (!STATIC_ASSET_URLS.has(event.request.url)) {
    event.respondWith(
      fetch(event.request).catch(() =>
        caches.match(event.request).then((response) => response || offlinePageResponse())
      )
    );
    return;
  }

  event.respondWith(
    fetch(event.request).then((response) => {
      // Un errore momentaneo del server non deve finire nella cache offline.
      if (response.ok) {
        const copy = response.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
      }
      return response;
    }).catch(() =>
      caches.match(event.request).then((response) => response || offlinePageResponse())
    )
  );
});
