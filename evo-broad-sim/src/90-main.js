import * as THREE from 'three';

/*
 * AVENRÀ EVO · B-ROAD — bootstrap and frame loop.
 */
const EVO = window.EVO;

function detectQuality() {
  const coarse = (window.matchMedia && window.matchMedia('(pointer: coarse)').matches) || navigator.maxTouchPoints > 0;
  const dpr = window.devicePixelRatio || 1;
  const mode=document.getElementById('quality')?.value || 'auto';
  const balanced=mode==='balanced'||(mode==='auto'&&coarse);
  return {coarse,pixelRatio:Math.min(dpr,balanced?1.25:1.75),shadow:balanced?1536:3072,blades:balanced?42000:76000,mode,balanced};
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
EVO.createHud = function createHud(bike, score) {
  const bar = document.getElementById('corner-fill'), marker = document.getElementById('corner-marker');
  const safeZone = document.getElementById('corner-safe'), limitZone = document.getElementById('corner-limit');
  const text = document.getElementById('corner-text'), limits = document.getElementById('corner-limits');
  const notice = document.getElementById('notice');
  const mph = (v) => Math.round(v * 2.23694);
  let lastText = '', lastLimits = '', lastStatus = '', lastNotice = '', lastScore = '', lastMeta = '', lastPop = '';
  const scoreEl = document.getElementById('score-value'), scoreMeta = document.getElementById('score-meta'), popEl = document.getElementById('score-pop');
  return {
    update() {
      const region=document.getElementById('place-name'),surface=document.getElementById('surface-info'),limit=document.getElementById('limit-sign');
      const v=EVO.route.inVillage(bike.s),hump=EVO.route.nextHump(bike.s),wood=EVO.route.woodland(bike.s);
      region.textContent=v?'DALEBECK VILLAGE':wood?'WOODED B-ROAD':'OPEN PASTURE';
      limit.textContent=String(EVO.route.speedLimitAt(bike.s));
      const approaching=hump&&hump.dist<125;
      surface.textContent=approaching?`${hump.type==='table'?'RAISED TABLE':'ROAD HUMP'} · ${Math.round(hump.dist)} m`:v?'20 mph · traffic-calmed village':wood?'Coarse surface · shaded bends':'Two-way road · keep left';
      surface.classList.toggle('warn',approaching);
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
      const l = c.max >= EVO.V_MAX - 0.1 ? 'BEND GUIDANCE' : `BRAKE POINT ${mph(c.safe)}–${mph(c.max)}`;
      if (l !== lastLimits) { limits.textContent = l; lastLimits = l; }
      const n = bike.notice ? bike.notice.text : '';
      if (n !== lastNotice) { notice.textContent = n; notice.hidden = !n; notice.dataset.tone = bike.notice?.tone || ''; lastNotice = n; }
      if (score) {
        const st = score.state;
        const sc = Math.max(0, Math.round(st.score)).toLocaleString();
        if (sc !== lastScore) { scoreEl.textContent = sc; lastScore = sc; }
        const mm = Math.floor(st.lapTime / 60), ss = Math.floor(st.lapTime % 60);
        const meta = `LAP ${st.lap} · ${mm}:${String(ss).padStart(2, '0')}${st.mult > 1 ? ` · ×${st.mult.toFixed(2).replace(/\.?0+$/, '')}` : ''} · BEST ${Math.round(st.best).toLocaleString()}`;
        if (meta !== lastMeta) { scoreMeta.textContent = meta; lastMeta = meta; }
        const p = st.pop;
        const key = p ? `${p.text}|${p.until}` : '';
        if (key !== lastPop) {
          lastPop = key;
          if (p) {
            popEl.textContent = p.points ? `${p.points > 0 ? '+' : '−'}${Math.abs(p.points).toLocaleString()}  ${p.text}` : p.text;
            popEl.dataset.tone = p.tone; popEl.hidden = false;
            popEl.style.animation = 'none'; void popEl.offsetWidth; popEl.style.animation = '';
          } else popEl.hidden = true;
        }
      }
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
    const wanted = new URLSearchParams(location.search).get('light') || document.getElementById('light')?.value || 'noon';
    if (wanted !== app.world.lightingName) app.setLighting(wanted);
    app.bike.s=Number(document.getElementById('spawn').value)||180;
    app.bike.d=1.5;app.bike.v=0;app.bike.pitch=app.bike.pitchVel=app.bike.heave=app.bike.heaveVel=0;
    app.bike.motionScale=document.getElementById('comfort').checked?.45:1;
    const trafficMode=document.getElementById('traffic')?.value||'both';
    app.trafficEnabled=trafficMode!=='none';
    app.traffic.setMode(trafficMode==='none'?'both':trafficMode);
    for(const car of app.traffic.cars){car.mesh.visible=app.trafficEnabled&&car.mesh.visible;car.lastRel=null;}
    app.score.reset();
    const mode=document.getElementById('quality').value;
    const balanced=mode==='balanced'||(mode==='auto'&&app.quality.coarse);
    app.renderer.setPixelRatio(Math.min(window.devicePixelRatio||1,balanced?1.25:1.75));
    app.world.sun.shadow.mapSize.set(balanced?1536:3072,balanced?1536:3072);
    if(app.world.sun.shadow.map){app.world.sun.shadow.map.dispose();app.world.sun.shadow.map=null;}
    window.dispatchEvent(new Event('resize'));
    app.running=true;app.paused=false;
    app.audio.start();
    start.classList.add('is-hidden');
    setTimeout(() => { start.hidden = true; }, 600); // drop the blurred panel entirely once faded
    if (app.quality.coarse) {
      try { await document.documentElement.requestFullscreen?.(); } catch (e) { /* not available in this host */ }
      // Portrait and landscape both remain available.
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
  const togglePause=()=>{if(!app||!app.running)return;app.paused=!app.paused;document.getElementById('paused').hidden=!app.paused;app.audio.setMuted(app.paused||document.getElementById('mute').getAttribute('aria-pressed')==='true');};
  document.getElementById('pause').addEventListener('click',togglePause);
  window.addEventListener('keydown',e=>{
    if(e.code==='KeyP'&&!e.repeat)togglePause();
    if(e.code==='KeyR'&&app?.running){const b=app.bike;b.d=1.5;b.v=0;b.lean=b.leanTarget=b.pitch=b.pitchVel=b.heave=b.heaveVel=0;b.offRoad=b.rumble=b.crashTimer=0;b.notice=null;}
  });
  setStatus('Building detailed cottages, hedgerows and road surfaces…');
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
  renderer.shadowMap.autoUpdate=false; // one sun map per rendered frame, not again for the mirrors
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
  // The sky changes with the lighting preset, so the reflections must follow:
  // rebuild the environment map and hand it to everything that reflects.
  function refreshEnvMap() {
    try {
      const pm = new THREE.PMREMGenerator(renderer);
      const skyScene = new THREE.Scene(); skyScene.add(world.sky.clone());
      const next = pm.fromScene(skyScene, 0.04, 1, 4000).texture;
      pm.dispose();
      const old = EVO.envMap; EVO.envMap = next;
      world.scene.traverse((o) => {
        const mats = Array.isArray(o.material) ? o.material : (o.material ? [o.material] : []);
        for (const m of mats) if (m.envMap) m.envMap = next;
      });
      if (world.materials.detail) for (const m of Object.values(world.materials.detail)) if (m.envMap) m.envMap = next;
      old?.dispose();
    } catch (e) { console.warn('environment map refresh failed', e); }
  }
  lap('envmap');
  const camera = new THREE.PerspectiveCamera(60, 1, 0.25, 2800);
  const bike = EVO.createBike();
  const audio = EVO.createAudio();
  const controls = EVO.createControls(canvas, bike, { onInteract: () => audio.start() });
  const traffic = EVO.createTraffic(world.scene, bike, { count: quality.coarse ? 6 : 7, same: quality.coarse ? 2 : 3, envMap: EVO.envMap });
  const score = EVO.createScore(bike, traffic);
  const rain = EVO.createRain(world.scene, quality);
  EVO.addParkedVillageCars?.(world, EVO.envMap, quality);
  if(EVO.envMap&&world.materials.detail){for(const mat of Object.values(world.materials.detail)){if(mat.name.includes('reflections')){mat.envMap=EVO.envMap;mat.envMapIntensity=mat.name.includes('puddle')?1.3:.28;mat.needsUpdate=true;}}}
  const hud = EVO.createHud(bike, score);
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
    const rawDt=Math.max(0,(now-last)/1000);last=now;
    const active=EVO.app?.running&&!EVO.app?.paused&&!document.hidden;
    const dt=active?Math.min(.25,rawDt):0;
    if(!active)acc=0;
    renderer.info.reset();
    controls.update();
    acc += dt;
    let steps = 0;
    while (acc >= STEP && steps < 32) {
      bike.step(STEP);
      let events = null;
      if(EVO.app?.trafficEnabled){
        events=traffic.update(STEP);
        if(events.collision&&bike.crashTimer<=0){bike.crash(events.collision.reason);audio.thump();}
        if(events.passBy)audio.passBy(events.passBy.closing,events.passBy.gap);
        if(events.overtake)audio.passBy(Math.max(6, events.overtake.closing * 2), events.overtake.gap + 0.6);
      }
      score.update(STEP, events);
      acc-=STEP;steps++;
    }
    hud.update();
    const portrait = window.innerHeight > window.innerWidth;
    bike.applyCamera(camera, portrait);
    bikePos.copy(bike.pos);
    world.update(now / 1000, bikePos, bike.forward);
    rain.update(camera.position, bike.forward, bike.v, now / 1000, world.rain);
    audio.update(bike);
    renderer.shadowMap.needsUpdate=true;
    if (post) { post.begin(); renderer.render(world.scene, camera); post.end(Math.min(1, bike.v / EVO.V_MAX), now / 1000); }
    else renderer.render(world.scene, camera);
    if (cockpit) cockpit.render(camera, bike, now / 1000);
    if (showStats) {
      frames += 1; fpsTime += rawDt;
      if (fpsTime >= 0.5) {
        const info = renderer.info;
        statsEl.textContent = `${Math.round(frames / fpsTime)} fps · ${info.render.calls} calls · ${(info.render.triangles / 1000).toFixed(0)}k tris · ${bike.mph()} mph · s ${bike.s.toFixed(0)} m`;
        frames = 0; fpsTime = 0;
      }
    }
  }
  requestAnimationFrame(loop);
  function setLighting(name) {
    const P = world.setLighting(name);
    post?.setExposure(P.exposure);
    post?.setRain(P.rain || 0);
    audio.setRain(P.rain || 0);
    refreshEnvMap();
    return P;
  }
  EVO.app = { running:false,paused:false,trafficEnabled:true,renderer, world, camera, bike, controls, audio, quality, traffic, score, rain, post, setLighting, get cockpit() { return cockpit; } };
  return EVO.app;
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
