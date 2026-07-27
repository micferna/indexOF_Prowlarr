"use strict";

/**
 * Service worker minimal — juste ce qu'il faut pour que l'application soit
 * installable sur téléphone.
 *
 * Il ne met en cache QUE les fichiers statiques de /assets/. Jamais les pages
 * ni l'API : elles dépendent de la session, et un cache servirait à un visiteur
 * la réponse destinée à un autre. Les URLs d'assets étant déjà versionnées par
 * l'application, une nouvelle version produit une nouvelle entrée de cache.
 */

const CACHE = "indexof-assets-v1";

self.addEventListener("install", () => self.skipWaiting());

self.addEventListener("activate", (e) => {
    e.waitUntil(
        caches.keys()
            .then((noms) => Promise.all(noms.filter((n) => n !== CACHE).map((n) => caches.delete(n))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener("fetch", (e) => {
    const url = new URL(e.request.url);
    if (e.request.method !== "GET" || url.origin !== self.location.origin) return;
    if (!url.pathname.includes("/assets/")) return;

    e.respondWith(
        caches.match(e.request).then((hit) => hit || fetch(e.request).then((res) => {
            if (res.ok) {
                const copie = res.clone();
                caches.open(CACHE).then((c) => c.put(e.request, copie));
            }
            return res;
        }))
    );
});
