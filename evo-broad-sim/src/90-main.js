import * as THREE from 'three';
/*
 * AVENRÀ EVO · B-ROAD — bootstrap and frame loop.
 */
const EVO = window.EVO;

function detectQuality() {
  const coarse = (window.matchMedia && window.matchMedia('(pointer: coarse)').matches) || navigator.maxTouchPoints > 0;
  const dpr = window.devicePixelRatio || 1;
  return coarse
    ? { coarse, pixelRatio: Math.min(dpr, 1.5), shadow: 2048, blades: 36000 }
    : { coarse, pixelRatio: Math.min(dpr, 2), shadow: 4096, blades: 64000 };
}

const statusEl = document.getElementById('status');
function setStatus(text, error = false) {
  if (!statusEl) return;
  statusEl.textContent = text;
  statusEl.classList.toggle('is-error', error);
}
window.addEventListener('error', (e) => setStatus(`Something went wrong: ${e.message || e.error || 'unknown error'}`, true));
window.addEventListener('unhandledrejection', (e) => setStatus(`Something went wrong: ${e.reason?.message || e.reason || 'unknown error'}`, true));

/* Corner-speed bar and notices. Green: under the safe speed for the road
 * ahead. Amber: between safe and the tyres' limit; the bike drifts wide and
 * needs holding. Red: over the limit; it will run off. */
EVO.createHud = function createHud(bike) {
  const bar = document.getElementById('corner-fill'), marker = document.getElementById('corner-marker');
  const safeZone = document.getElementById('corner-safe'), limitZone = document.getElementById('corner-limit');
  const text = document.getElementById('corner-text'), limits = document.getElementById('corner-limits');
  const notice = document.getElementById('notice');
  const mph = (v) => Math.round(v * 2.23694);
  let lastText = '', lastLimits = '', lastStatus = '', lastNotice = '';
  return {
    update() {
      const c = bike.corner;
      const scale = c.max * 1.25; // bar spans 0 .. 125 % of the maximum
      const x = EVO.clamp(bike.v / scale, 0, 1);
      bar.style.width = `${(x * 100).toFixed(1)}%`;
      marker.style.left = `${(x * 100).toFixed(1)}%`;
      safeZone.style.width = `${(c.safe / scale * 100).toFixed(1)}%`;
      limitZone.style.left = `${(c.safe / scale * 100).toFixed(1)}%`;
      limitZone.style.width = `${((c.max - c.safe) / scale * 100).toFixed(1)}%`;
      if (c.status !== lastStatus) { bar.dataset.status = c.status; lastStatus = c.status; }
      const where = c.bendDist < 8 ? 'IN BEND' : `BEND ${Math.round(c.bendDist)} M`;
      const t = c.bendDist < 170 ? `${c.bendDir > 0 ? 'LEFT' : 'RIGHT'} ${where} · SAFE ${mph(c.bendSafe)} · MAX ${mph(c.bendMax)}` : 'OPEN ROAD';
      if (t !== lastText) { text.textContent = t; lastText = t; }
      const l = c.max >= EVO.V_MAX - 0.1 ? 'NO LIMIT AHEAD' : `BRAKE POINT ${mph(c.safe)}–${mph(c.max)}`;
      if (l !== lastLimits) { limits.textContent = l; lastLimits = l; }
      const n = bike.notice ? bike.notice.text : '';
      if (n !== lastNotice) { notice.textContent = n; notice.hidden = !n; notice.dataset.tone = bike.notice?.tone || ''; lastNotice = n; }
    }
  };
};

function boot() {
  // The start panel must work before anything heavy runs, so bind it first
  // and build the world on the next frame while the status line explains.
  const start = document.getElementById('start');
  const rideBtn = document.getElementById('ride');
  const tiltBtn = document.getElementById('tilt');
  let app = null, ridePending = false;
  const beginRide = async () => {
    if (!app) { ridePending = true; return; }
    app.audio.start();
    start.classList.add('is-hidden');
    setTimeout(() => { start.hidden = true; }, 600); // drop the blurred panel entirely once faded
    if (app.quality.coarse) {
      try { await document.documentElement.requestFullscreen?.(); } catch (e) { /* not available in this host */ }
      try { await screen.orientation?.lock?.('landscape'); } catch (e) { /* not available in this host */ }
    }
  };
  rideBtn.addEventListener('click', beginRide);
  tiltBtn.addEventListener('click', async () => {
    if (!app) return;
    const ok = await app.controls.enableTilt();
    tiltBtn.textContent = ok ? 'TILT STEERING ON' : 'TILT UNAVAILABLE';
    tiltBtn.disabled = ok;
  });
  document.getElementById('mute').addEventListener('click', (e) => {
    const on = e.currentTarget.getAttribute('aria-pressed') === 'true';
    e.currentTarget.setAttribute('aria-pressed', on ? 'false' : 'true');
    app?.audio.setMuted(!on);
  });
  setStatus('Building the road, hedgerows and sky…');
  setTimeout(() => {
    try {
      app = buildApp();
      rideBtn.disabled = false; rideBtn.textContent = 'RIDE';
      setStatus('');
      if (ridePending) beginRide();
    } catch (e) {
      setStatus(`Could not start the ride: ${e?.message || e}`, true);
      rideBtn.textContent = 'UNAVAILABLE';
      console.error(e);
    }
  }, 60);
}

function buildApp() {
  const canvas = document.getElementById('view');
  const quality = detectQuality();
  const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, powerPreference: 'high-performance', alpha: false });
  renderer.setPixelRatio(quality.pixelRatio);
  // Tone mapping happens in the post-process composite (linear HDR scene);
  // fall back to the renderer's ACES if float targets are unavailable.
  const post = /nopost=1/.test(location.search) ? null : EVO.createPost(renderer, quality);
  renderer.toneMapping = post ? THREE.NoToneMapping : THREE.ACESFilmicToneMapping;
  renderer.toneMappingExposure = 0.88;
  renderer.shadowMap.enabled = true;
  renderer.shadowMap.type = THREE.PCFSoftShadowMap;
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  renderer.info.autoReset = false;

  const timings = {}; let tPhase = performance.now();
  const lap = (name) => { timings[name] = Math.round(performance.now() - tPhase); tPhase = performance.now(); };
  const world = EVO.buildWorld(renderer, quality);
  lap('world');
  EVO.applyAnisotropy(renderer);
  // environment map from the sky, for car paint and glass reflections
  try {
    const pm = new THREE.PMREMGenerator(renderer);
    const skyScene = new THREE.Scene(); skyScene.add(world.sky.clone());
    EVO.envMap = pm.fromScene(skyScene, 0.04, 1, 4000).texture;
    pm.dispose();
  } catch (e) { EVO.envMap = null; console.warn('environment map unavailable', e); }
  if (EVO.envMap) { world.materials.roadMat.envMap = EVO.envMap; world.materials.roadMat.needsUpdate = true; }
  lap('envmap');
  const camera = new THREE.PerspectiveCamera(60, 1, 0.25, 2800);
  const bike = EVO.createBike();
  const audio = EVO.createAudio();
  const controls = EVO.createControls(canvas, bike, { onInteract: () => audio.start() });
  const traffic = EVO.createTraffic(world.scene, bike, { count: quality.coarse ? 6 : 7, envMap: EVO.envMap });
  const hud = EVO.createHud(bike);
  lap('traffic');
  EVO.timings = timings;

  let cockpit = null;
  const cockpitUrl = window.EVO_COCKPIT_URL || './assets/cockpit.png';
  new THREE.TextureLoader().load(cockpitUrl, (tex) => {
    tex.colorSpace = THREE.SRGBColorSpace;
    tex.anisotropy = Math.min(8, renderer.capabilities.getMaxAnisotropy());
    cockpit = EVO.createCockpit(renderer, world, tex);
    resize();
  });

  function resize() {
    const w = window.innerWidth, h = window.innerHeight;
    renderer.setSize(w, h, false);
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
    cockpit?.layout(w, h);
    post?.resize();
  }
  window.addEventListener('resize', resize);
  resize();

  const statsEl = document.getElementById('stats');
  const showStats = new URLSearchParams(location.search).has('stats');
  if (showStats) statsEl.hidden = false;

  const STEP = 1 / 120;
  let last = performance.now(), acc = 0, frames = 0, fpsTime = 0;
  const bikePos = new THREE.Vector3();
  function loop(now) {
    requestAnimationFrame(loop);
    const dt = Math.min(0.05, (now - last) / 1000); last = now;
    renderer.info.reset();
    controls.update();
    acc += dt;
    let steps = 0;
    while (acc >= STEP && steps < 8) { bike.step(STEP); acc -= STEP; steps += 1; }
    const events = traffic.update(dt);
    if (events.collision && bike.crashTimer <= 0) { bike.crash('COLLISION · ONCOMING CAR'); audio.thump(); }
    if (events.passBy) audio.passBy(events.passBy.closing, events.passBy.gap);
    hud.update();
    const portrait = window.innerHeight > window.innerWidth;
    bike.applyCamera(camera, portrait);
    bikePos.copy(bike.pos);
    world.update(now / 1000, bikePos, bike.forward);
    audio.update(bike);
    if (post) { post.begin(); renderer.render(world.scene, camera); post.end(Math.min(1, bike.v / EVO.V_MAX), now / 1000); }
    else renderer.render(world.scene, camera);
    if (cockpit) cockpit.render(camera, bike, now / 1000);
    if (showStats) {
      frames += 1; fpsTime += dt;
      if (fpsTime >= 0.5) {
        const info = renderer.info;
        statsEl.textContent = `${Math.round(frames / fpsTime)} fps · ${info.render.calls} calls · ${(info.render.triangles / 1000).toFixed(0)}k tris · ${bike.mph()} mph · s ${bike.s.toFixed(0)} m`;
        frames = 0; fpsTime = 0;
      }
    }
  }
  requestAnimationFrame(loop);
  EVO.app = { renderer, world, camera, bike, controls, audio, quality, traffic, post, get cockpit() { return cockpit; } };
  return EVO.app;
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
