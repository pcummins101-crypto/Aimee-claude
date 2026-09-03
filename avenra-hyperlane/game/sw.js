const BUILD = "3.3.15";
const CACHE_NAME = "avenra-hyperlane-wp-3.3.15";
const BASE_URL = new URL("./", self.location.href);
const GAME_URL = new URL("index-3.3.15.html", BASE_URL).href;
// The marker deliberately does not include BUILD. A currently controlling
// worker must accept a newer trusted Hyperlane shell so it can hand control to
// that shell's versioned worker instead of pinning the browser to an old cache.
const BUILD_MARKER = '<meta name="avenra-hyperlane-build" content="';
const TRAFFIC_SPRITE_PATHS = [
  "environment/traffic-sprites-v305/traffic-saloon-000-v305.webp",
  "environment/traffic-sprites-v305/traffic-saloon-045-v305.webp",
  "environment/traffic-sprites-v305/traffic-saloon-090-v305.webp",
  "environment/traffic-sprites-v305/traffic-saloon-135-v305.webp",
  "environment/traffic-sprites-v305/traffic-saloon-180-v305.webp",
  "environment/traffic-sprites-v305/traffic-saloon-225-v305.webp",
  "environment/traffic-sprites-v305/traffic-saloon-270-v305.webp",
  "environment/traffic-sprites-v305/traffic-saloon-315-v305.webp",
  "environment/traffic-sprites-v305/traffic-suv-000-v305.webp",
  "environment/traffic-sprites-v305/traffic-suv-045-v305.webp",
  "environment/traffic-sprites-v305/traffic-suv-090-v305.webp",
  "environment/traffic-sprites-v305/traffic-suv-135-v305.webp",
  "environment/traffic-sprites-v305/traffic-suv-180-v305.webp",
  "environment/traffic-sprites-v305/traffic-suv-225-v305.webp",
  "environment/traffic-sprites-v305/traffic-suv-270-v305.webp",
  "environment/traffic-sprites-v305/traffic-suv-315-v305.webp",
  "environment/traffic-sprites-v305/traffic-van-000-v305.webp",
  "environment/traffic-sprites-v305/traffic-van-045-v305.webp",
  "environment/traffic-sprites-v305/traffic-van-090-v305.webp",
  "environment/traffic-sprites-v305/traffic-van-135-v305.webp",
  "environment/traffic-sprites-v305/traffic-van-180-v305.webp",
  "environment/traffic-sprites-v305/traffic-van-225-v305.webp",
  "environment/traffic-sprites-v305/traffic-van-270-v305.webp",
  "environment/traffic-sprites-v305/traffic-van-315-v305.webp",
  "environment/traffic-sprites-v305/traffic-motorhome-000-v305.webp",
  "environment/traffic-sprites-v305/traffic-motorhome-045-v305.webp",
  "environment/traffic-sprites-v305/traffic-motorhome-090-v305.webp",
  "environment/traffic-sprites-v305/traffic-motorhome-135-v305.webp",
  "environment/traffic-sprites-v305/traffic-motorhome-180-v305.webp",
  "environment/traffic-sprites-v305/traffic-motorhome-225-v305.webp",
  "environment/traffic-sprites-v305/traffic-motorhome-270-v305.webp",
  "environment/traffic-sprites-v305/traffic-motorhome-315-v305.webp",
  "environment/traffic-sprites-v305/traffic-lorry-000-v305.webp",
  "environment/traffic-sprites-v305/traffic-lorry-045-v305.webp",
  "environment/traffic-sprites-v305/traffic-lorry-090-v305.webp",
  "environment/traffic-sprites-v305/traffic-lorry-135-v305.webp",
  "environment/traffic-sprites-v305/traffic-lorry-180-v305.webp",
  "environment/traffic-sprites-v305/traffic-lorry-225-v305.webp",
  "environment/traffic-sprites-v305/traffic-lorry-270-v305.webp",
  "environment/traffic-sprites-v305/traffic-lorry-315-v305.webp",
];
const INSTALL_FETCH_CONCURRENCY = 3;
const GRAPHICS_FETCH_CONCURRENCY = 2;
const REQUIRED_RUNTIME_URLS = [
  "assets/hyperlane-perf-v3315.js",
  "assets/hyperlane-dynamics-audio-v3315.js",
  "assets/hyperlane-traffic-sprites-v338.js",
  "assets/hyperlane-lighting-v337.js",
  "assets/index-e80690ba-v3315.js",
].map((path) => new URL(path, BASE_URL).href);

const graphicsPackJobs = new Map();
let graphicsPackQueue = Promise.resolve();

const ENHANCED_GRAPHICS_PACK = [
  "environment/asphalt-grain.webp",
  "environment/avenra-works.webp",
  "environment/district-tree.webp",
  "environment/district-warehouse-row.webp",
  "environment/traffic-lorry-front.webp",
  "environment/traffic-saloon-front.webp",
  "environment/traffic-saloon-rear.webp",
  "environment/traffic-suv-front.webp",
  "environment/traffic-suv-rear.webp",
  "environment/traffic-van-rear.webp",
  "environment/services/toddington-v262.webp",
  "environment/services/watford-gap-v262.webp",
  "environment/services/woodall-v262.webp",
  "environment/services/woolley-edge-v262.webp",
  ...TRAFFIC_SPRITE_PATHS,
].map((path) => new URL(path, BASE_URL).href);

const ULTRA_GRAPHICS_PACK = [
  ...ENHANCED_GRAPHICS_PACK,
  "environment/rural-estate-ultra.webp",
  "environment/rural-farmstead-ultra.webp",
  "environment/motorway-logistics-ultra.webp",
  "environment/motorway-campus-ultra.webp",
  "environment/traffic-estate-front-ultra.webp",
  "environment/traffic-estate-rear-ultra.webp",
  "environment/traffic-lorry-rear-ultra.webp",
  "environment/traffic-van-front-ultra.webp",
  "environment/ultra/traffic-convertible-front.webp",
  "environment/ultra/traffic-convertible-rear.webp",
  "environment/ultra/traffic-horse-front.webp",
  "environment/ultra/traffic-horse-rear.webp",
  "environment/ultra/traffic-motorcycle-front.webp",
  "environment/ultra/traffic-motorcycle-rear.webp",
  "environment/ultra/traffic-motorhome-front.webp",
  "environment/ultra/traffic-motorhome-rear.webp",
  "environment/ultra/traffic-tractor-front.webp",
  "environment/ultra/traffic-tractor-rear.webp",
].map((path) => path.startsWith("environment/") ? new URL(path, BASE_URL).href : path);

const GRAPHICS_PACKS = {
  enhanced: ENHANCED_GRAPHICS_PACK,
  ultra: ULTRA_GRAPHICS_PACK,
};

const canCache = (response) => response.ok || response.type === "opaque";
const fetchFreshShell = (requestOrUrl) => fetch(new Request(requestOrUrl, { cache: "no-store" }));
const isHyperlaneDocument = async (response) => {
  if (!response.ok || response.redirected || new URL(response.url).origin !== self.location.origin) return false;
  const contentType = response.headers.get("content-type") || "";
  if (!contentType.includes("text/html")) return false;
  const html = await response.clone().text();
  return html.includes(BUILD_MARKER) && html.includes("Avenrà Hyperlane");
};

const cacheGraphicsAsset = async (cache, url) => {
  if (await cache.match(url)) return url;
  const response = await fetch(url);
  if (!canCache(response)) throw new Error(`Unable to cache optional graphics asset: ${url}`);
  await cache.put(url, response.clone());
  return url;
};

const cacheUrlsBounded = async (urls, concurrency, cacheOne) => {
  const results = new Array(urls.length);
  let cursor = 0;
  const workerCount = Math.max(1, Math.min(concurrency, urls.length));
  const workers = Array.from({ length: workerCount }, async () => {
    while (cursor < urls.length) {
      const index = cursor;
      cursor += 1;
      try {
        results[index] = { status: "fulfilled", value: await cacheOne(urls[index]) };
      } catch (reason) {
        results[index] = { status: "rejected", reason };
      }
    }
  });
  await Promise.all(workers);
  return results;
};

const cacheRequiredAsset = async (cache, url) => {
  const existing = await caches.match(url);
  if (existing && canCache(existing)) {
    await cache.put(url, existing.clone());
    return url;
  }
  const response = await fetch(url);
  if (!canCache(response)) throw new Error(`Unable to cache required game asset: ${url}`);
  await cache.put(url, response.clone());
  return url;
};

const warmRequiredPageDependencies = async (cache) => {
  const response = await fetchFreshShell(GAME_URL);
  if (!(await isHyperlaneDocument(response))) throw new Error("Unable to cache the Hyperlane shell");
  await cache.put(GAME_URL, response.clone());
  const html = await response.text();
  const assetUrls = [...new Set([...html.matchAll(/(?:src|href)=["']([^"']+)["']/g)]
    .map((match) => new URL(match[1], GAME_URL))
    .filter((url) => url.origin === self.location.origin && url.href.startsWith(BASE_URL.href))
    .map((url) => url.href))];
  const assetSet = new Set(assetUrls);
  const missingRuntimeUrl = REQUIRED_RUNTIME_URLS.find((url) => !assetSet.has(url));
  if (missingRuntimeUrl) throw new Error(`Hyperlane shell is missing required runtime asset: ${missingRuntimeUrl}`);
  const results = await cacheUrlsBounded(
    assetUrls,
    INSTALL_FETCH_CONCURRENCY,
    (url) => cacheRequiredAsset(cache, url),
  );
  const failed = results.find((result) => result.status === "rejected");
  if (failed) throw failed.reason;
};

const cacheGraphicsPack = async (tier, routeId) => {
  const cache = await caches.open(CACHE_NAME);
  const urls = GRAPHICS_PACKS[tier] || ULTRA_GRAPHICS_PACK;
  const results = await cacheUrlsBounded(
    urls,
    GRAPHICS_FETCH_CONCURRENCY,
    (url) => cacheGraphicsAsset(cache, url),
  );
  const failed = results.flatMap((result, index) =>
    result.status === "rejected" ? [urls[index]] : [],
  );
  return {
    type: "GRAPHICS_PACK_CACHE_RESULT",
    tier,
    packTier: tier,
    routeId,
    cached: failed.length === 0,
    failed,
  };
};

const getOrCreateGraphicsPackJob = (tier, routeId) => {
  const jobKey = `${tier}:${routeId}`;
  const existingJob = graphicsPackJobs.get(jobKey);
  if (existingJob) return existingJob;
  const job = graphicsPackQueue
    .catch(() => undefined)
    .then(() => cacheGraphicsPack(tier, routeId));
  graphicsPackQueue = job.then(() => undefined, () => undefined);
  graphicsPackJobs.set(jobKey, job);
  job.then(
    () => graphicsPackJobs.delete(jobKey),
    () => graphicsPackJobs.delete(jobKey),
  );
  return job;
};

self.addEventListener("install", (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE_NAME);
    await warmRequiredPageDependencies(cache);
    await self.skipWaiting();
  })());
});

self.addEventListener("activate", (event) => {
  event.waitUntil((async () => {
    const cacheNames = await caches.keys();
    await Promise.all(cacheNames
      .filter((cacheName) => cacheName.startsWith("avenra-hyperlane-wp-") && cacheName !== CACHE_NAME)
      .map((cacheName) => caches.delete(cacheName)));
    await self.clients.claim();
  })());
});

self.addEventListener("message", (event) => {
  const message = event.data;
  if (
    !message ||
    message.type !== "CACHE_GRAPHICS_PACK" ||
    (message.tier !== "enhanced" && message.tier !== "ultra")
  ) return;

  event.waitUntil((async () => {
    const routeId = message.routeId === "rural" || message.routeId === "motorway"
      ? message.routeId
      : "city";
    const response = await getOrCreateGraphicsPackJob(message.tier, routeId);
    const replyTarget = event.ports?.[0] ?? event.source;
    replyTarget?.postMessage?.(response);
  })());
});

self.addEventListener("fetch", (event) => {
  const request = event.request;
  if (request.method !== "GET") return;
  const url = new URL(request.url);

  if (request.mode === "navigate" && url.href.startsWith(BASE_URL.href)) {
    event.respondWith((async () => {
      const cache = await caches.open(CACHE_NAME);
      try {
        const response = await fetchFreshShell(request);
        if (await isHyperlaneDocument(response)) {
          await cache.put(request, response.clone());
          return response;
        }
        return (await cache.match(GAME_URL)) || Response.error();
      } catch {
        return (await cache.match(GAME_URL)) || Response.error();
      }
    })());
    return;
  }

  const cacheableLocalAsset = url.origin === self.location.origin && url.href.startsWith(BASE_URL.href);
  if (cacheableLocalAsset) {
    const responseTask = (async () => {
      const cache = await caches.open(CACHE_NAME);
      const cached = await cache.match(request);
      if (cached) return { response: cached, cacheWrite: Promise.resolve() };
      try {
        const response = await fetch(request);
        const cacheWrite = canCache(response)
          ? cache.put(request, response.clone())
          : Promise.resolve();
        return { response, cacheWrite };
      } catch {
        return { response: Response.error(), cacheWrite: Promise.resolve() };
      }
    })();
    event.respondWith(responseTask.then((result) => result.response));
    event.waitUntil(responseTask.then((result) => result.cacheWrite).catch(() => undefined));
  }
});
