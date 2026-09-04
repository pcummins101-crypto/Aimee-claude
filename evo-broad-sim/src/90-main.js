import * as THREE from 'three';
/*
 * AVENRÀ EVO · B-ROAD — bootstrap and frame loop.
 */
const EVO = window.EVO;

function detectQuality() {
  const coarse = (window.matchMedia && window.matchMedia('(pointer: coarse)').matches) || navigator.maxTouchPoints > 0;
  const dpr = window.devicePixelRatio || 1;
  return coarse
    ? { coarse, pixelRatio: Math.min(dpr, 1.5), shadow: 2048, blades: 24000 }
    : { coarse, pixelRatio: Math.min(dpr, 2), shadow: 4096, blades: 44000 };
}

const statusEl = document.getElementById('status');
function setStatus(text, error = false) {
  if (!statusEl) return;
  statusEl.textContent = text;
  statusEl.classList.toggle('is-error', error);
}
window.addEventListener('error', (e) => setStatus(`Something went wrong: ${e.message || e.error || 'unknown error'}`, true));
window.addEventListener('unhandledrejection', (e) => setStatus(`Something went wrong: ${e.reason?.message || e.reason || 'unknown error'}`, true));

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
  renderer.toneMapping = THREE.ACESFilmicToneMapping;
  renderer.toneMappingExposure = 0.88;
  renderer.shadowMap.enabled = true;
  renderer.shadowMap.type = THREE.PCFSoftShadowMap;
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  renderer.info.autoReset = false;

  const world = EVO.buildWorld(renderer, quality);
  EVO.applyAnisotropy(renderer);
  const camera = new THREE.PerspectiveCamera(60, 1, 0.25, 2800);
  const bike = EVO.createBike();
  const audio = EVO.createAudio();
  const controls = EVO.createControls(canvas, bike, { onInteract: () => audio.start() });

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
    const portrait = window.innerHeight > window.innerWidth;
    bike.applyCamera(camera, portrait);
    bikePos.copy(bike.pos);
    world.update(now / 1000, bikePos);
    audio.update(bike);
    renderer.render(world.scene, camera);
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
  EVO.app = { renderer, world, camera, bike, controls, audio, quality, get cockpit() { return cockpit; } };
  return EVO.app;
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
