import * as THREE from 'three';
/*
 * AVENRÀ EVO · B-ROAD — cockpit overlay, live mirrors, dash and audio.
 *
 * The rider photograph is composited as a screen-space quad after the world
 * render.  Both mirrors show a genuine rear view rendered into one small
 * render target (left mirror samples the left half, right the right half, both
 * flipped as a real mirror would be), and the TFT dash is a canvas texture.
 */
const EVO = window.EVO;
const { clamp, lerp } = EVO;

// Mirror glass and dash rectangles as fractions of the cockpit photograph.
const MIRROR_L = { x0: 0.028, y0: 0.362, x1: 0.170, y1: 0.462 };
const MIRROR_R = { x0: 0.830, y0: 0.362, x1: 0.972, y1: 0.462 };
const DASH = { x0: 0.400, y0: 0.556, x1: 0.596, y1: 0.640 };
const COCKPIT_ASPECT = 1398 / 1125;

function mirrorMask() {
  const c = document.createElement('canvas'); c.width = 256; c.height = 128;
  const ctx = c.getContext('2d');
  ctx.clearRect(0, 0, 256, 128);
  ctx.fillStyle = '#fff';
  ctx.beginPath();
  ctx.moveTo(14, 64); ctx.quadraticCurveTo(20, 10, 90, 8); ctx.lineTo(214, 14); ctx.quadraticCurveTo(250, 20, 244, 64);
  ctx.quadraticCurveTo(236, 112, 176, 118); ctx.lineTo(60, 122); ctx.quadraticCurveTo(18, 116, 14, 64); ctx.closePath(); ctx.fill();
  const t = new THREE.CanvasTexture(c); t.colorSpace = THREE.NoColorSpace; return t;
}
function vignette() {
  const c = document.createElement('canvas'); c.width = 256; c.height = 256;
  const ctx = c.getContext('2d');
  const g = ctx.createRadialGradient(128, 128, 60, 128, 128, 180);
  g.addColorStop(0, 'rgba(0,0,0,0)'); g.addColorStop(1, 'rgba(0,0,0,0.55)');
  ctx.fillStyle = g; ctx.fillRect(0, 0, 256, 256);
  const t = new THREE.CanvasTexture(c); return t;
}

EVO.createCockpit = function createCockpit(renderer, world, cockpitTexture) {
  const scene = new THREE.Scene();
  const cam = new THREE.OrthographicCamera(-1, 1, 1, -1, -10, 10);
  const group = new THREE.Group();
  scene.add(group);

  const unit = new THREE.PlaneGeometry(1, 1);
  const cockpitMat = new THREE.MeshBasicMaterial({ map: cockpitTexture, transparent: true, depthTest: false, depthWrite: false });
  const cockpit = new THREE.Mesh(unit, cockpitMat);
  cockpit.renderOrder = 2;
  group.add(cockpit);

  // rear-view render target shared by both mirrors
  const rt = new THREE.WebGLRenderTarget(512, 224, { depthBuffer: true, type: THREE.HalfFloatType });
  const rearCam = new THREE.PerspectiveCamera(46, 512 / 224, 0.5, 900);
  const mirrorMat = (u0, u1) => {
    const m = new THREE.ShaderMaterial({
      transparent: true, depthTest: false, depthWrite: false,
      uniforms: { view: { value: rt.texture }, mask: { value: mirrorMask() }, u0: { value: u0 }, u1: { value: u1 } },
      vertexShader: 'varying vec2 vUv; void main(){ vUv = uv; gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0); }',
      fragmentShader: `varying vec2 vUv; uniform sampler2D view; uniform sampler2D mask; uniform float u0; uniform float u1;
        vec3 aces(vec3 x){ const float a = 2.51, b = 0.03, c = 2.43, d = 0.59, e = 0.14; return clamp((x * (a * x + b)) / (x * (c * x + d) + e), 0.0, 1.0); }
        vec3 toSRGB(vec3 c){ return mix(pow(c, vec3(1.0 / 2.4)) * 1.055 - 0.055, c * 12.92, step(c, vec3(0.0031308))); }
        void main(){ float a = texture2D(mask, vUv).r; if (a < 0.5) discard;
          // horizontally flipped like a real mirror, with a slight convex squeeze
          float u = mix(u1, u0, vUv.x);
          vec3 c = texture2D(view, vec2(u, vUv.y * 0.92 + 0.04)).rgb;
          c = aces(c * 1.1); c = c * 0.86 + vec3(0.015, 0.02, 0.025);
          float edge = smoothstep(0.0, 0.18, vUv.x) * smoothstep(1.0, 0.82, vUv.x);
          c *= 0.7 + 0.3 * edge;
          gl_FragColor = vec4(toSRGB(c), 1.0); }`
    });
    m.toneMapped = false;
    return m;
  };
  const mirrorL = new THREE.Mesh(unit, mirrorMat(0.0, 0.48)); mirrorL.renderOrder = 3;
  const mirrorR = new THREE.Mesh(unit, mirrorMat(0.52, 1.0)); mirrorR.renderOrder = 3;
  group.add(mirrorL, mirrorR);

  // dash
  const dashCanvas = document.createElement('canvas'); dashCanvas.width = 512; dashCanvas.height = 208;
  const dctx = dashCanvas.getContext('2d');
  const dashTex = new THREE.CanvasTexture(dashCanvas); dashTex.colorSpace = THREE.SRGBColorSpace;
  const dash = new THREE.Mesh(unit, new THREE.MeshBasicMaterial({ map: dashTex, transparent: true, depthTest: false, depthWrite: false }));
  dash.renderOrder = 3;
  group.add(dash);


  let W = 1, H = 1, portrait = false;
  function layout(vw, vh) {
    portrait = vh > vw;
    cam.left = -vw / 2; cam.right = vw / 2; cam.top = vh / 2; cam.bottom = -vh / 2; cam.updateProjectionMatrix();
    // A rider looks over the screen: the windscreen top sits ~9 degrees below
    // the eye line, so the cockpit occupies the lower third of the view.
    H = portrait ? vh * 0.5 : vh * 0.76;
    W = H * COCKPIT_ASPECT;
    if (portrait && W < vw * 1.1) { W = vw * 1.1; H = W / COCKPIT_ASPECT; }
    const bottom = -vh / 2 - H * 0.06; // let the tank run off the bottom edge
    cockpit.scale.set(W, H, 1);
    cockpit.position.set(0, bottom + H / 2, 0);
    const place = (mesh, r) => {
      const cx = (r.x0 + r.x1) / 2, cy = (r.y0 + r.y1) / 2;
      mesh.scale.set((r.x1 - r.x0) * W, (r.y1 - r.y0) * H, 1);
      mesh.position.set((cx - 0.5) * W, bottom + H - cy * H, 0);
    };
    place(mirrorL, MIRROR_L); place(mirrorR, MIRROR_R); place(dash, DASH);
  }

  let frame = 0;
  function drawDash(bike, odometerMi) {
    const c = dctx, w = dashCanvas.width, h = dashCanvas.height;
    c.clearRect(0, 0, w, h);
    c.fillStyle = 'rgba(4,8,12,0.55)'; c.fillRect(0, 0, w, h);
    c.textBaseline = 'alphabetic';
    c.fillStyle = '#e9f3f7'; c.font = '700 118px "Helvetica Neue", Arial, sans-serif'; c.textAlign = 'right';
    c.fillText(String(bike.mph()), 300, 126);
    c.fillStyle = '#8fc4d2'; c.font = '700 30px Arial, sans-serif'; c.textAlign = 'left';
    c.fillText('MPH', 314, 124);
    c.fillStyle = '#6a8b95'; c.font = '700 22px Arial, sans-serif';
    c.fillText('EVO', 24, 42); c.fillText('B6270', 24, 176);
    c.textAlign = 'right';
    c.fillText(`${odometerMi.toFixed(1)} MI`, 488, 176);
    // power bar: throttle in cyan, regen/brake in amber
    const p = bike.input.throttle, b = bike.input.brake;
    c.fillStyle = '#12303a'; c.fillRect(330, 44, 158, 10);
    c.fillStyle = '#4fd3ec'; c.fillRect(330, 44, 158 * p, 10);
    c.fillStyle = '#f2a33a'; c.fillRect(330 + 158 * (1 - b), 44, 158 * b, 10);
    c.fillStyle = '#6a8b95'; c.font = '700 16px Arial, sans-serif'; c.textAlign = 'left';
    c.fillText('HYPERCORE', 330, 36);
    c.textAlign = 'right'; c.fillText('BAT 91%', 488, 36);
    // corner-speed lamp: green safe, amber on the limit, red too fast
    const st = bike.corner.status;
    c.fillStyle = st === 'safe' ? '#3ddc84' : st === 'limit' ? '#ffb02e' : '#ff3b4a';
    c.beginPath(); c.arc(330, 96, 9, 0, Math.PI * 2); c.fill();
    c.fillStyle = '#6a8b95'; c.font = '700 16px Arial, sans-serif'; c.textAlign = 'left';
    c.fillText(`CORNER ${Math.round(bike.corner.max * 2.23694)}`, 348, 102);
    dashTex.needsUpdate = true;
  }

  const _fwd = new THREE.Vector3(), _up = new THREE.Vector3(0, 1, 0), _target = new THREE.Vector3();
  function render(camera, bike, time) {
    frame += 1;
    // rear view every other frame
    if (frame % 2 === 0) {
      rearCam.position.copy(camera.position).addScaledVector(bike.forward, -0.9);
      rearCam.position.y -= 0.18;
      _target.copy(rearCam.position).addScaledVector(bike.forward, -50);
      _target.y += 1.2;
      rearCam.up.set(0, 1, 0).applyAxisAngle(bike.forward, -bike.lean * 0.5);
      rearCam.lookAt(_target);
      renderer.setRenderTarget(rt);
      renderer.render(world.scene, rearCam);
      renderer.setRenderTarget(null);
    }
    if (frame % 3 === 0) drawDash(bike, bike.odometer / 1609.344);
    // bike rolls a little more than the rider's head; nudge with suspension
    group.rotation.z = bike.lean * 0.3;
    group.position.set(bike.lean * 22 + bike.steerSmoothed * 4, -bike.pitch * 260 + bike.heave * 380, 0);
    renderer.autoClear = false;
    renderer.clearDepth();
    renderer.render(scene, cam);
    renderer.autoClear = true;
  }

  return { layout, render, get portrait() { return portrait; } };
};

/* ---------------------------------------------------------------- audio */
EVO.createAudio = function createAudio() {
  let ctx = null, nodes = null;
  function start() {
    if (ctx) { ctx.resume(); return; }
    const AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) return;
    ctx = new AC();
    const master = ctx.createGain(); master.gain.value = 0.7; master.connect(ctx.destination);
    // motor whine: two tones with a soft lowpass
    const whine = ctx.createOscillator(); whine.type = 'sawtooth'; whine.frequency.value = 80;
    const whine2 = ctx.createOscillator(); whine2.type = 'sine'; whine2.frequency.value = 160;
    const whineGain = ctx.createGain(); whineGain.gain.value = 0;
    const whineLp = ctx.createBiquadFilter(); whineLp.type = 'lowpass'; whineLp.frequency.value = 1400; whineLp.Q.value = 0.8;
    whine.connect(whineLp); whine2.connect(whineLp); whineLp.connect(whineGain).connect(master);
    // noise bed for wind and tyres
    const buf = ctx.createBuffer(1, ctx.sampleRate * 2, ctx.sampleRate);
    const data = buf.getChannelData(0);
    let b0 = 0, b1 = 0, b2 = 0;
    for (let i = 0; i < data.length; i += 1) { const w = Math.random() * 2 - 1; b0 = 0.99765 * b0 + w * 0.099; b1 = 0.963 * b1 + w * 0.2965; b2 = 0.57 * b2 + w * 1.0526; data[i] = (b0 + b1 + b2 + w * 0.1848) * 0.11; }
    const noise = ctx.createBufferSource(); noise.buffer = buf; noise.loop = true;
    const wind = ctx.createBiquadFilter(); wind.type = 'bandpass'; wind.frequency.value = 500; wind.Q.value = 0.5;
    const windGain = ctx.createGain(); windGain.gain.value = 0;
    const tyre = ctx.createBiquadFilter(); tyre.type = 'lowpass'; tyre.frequency.value = 320; tyre.Q.value = 0.7;
    const tyreGain = ctx.createGain(); tyreGain.gain.value = 0;
    const rumble = ctx.createBiquadFilter(); rumble.type = 'lowpass'; rumble.frequency.value = 120;
    const rumbleGain = ctx.createGain(); rumbleGain.gain.value = 0;
    noise.connect(wind).connect(windGain).connect(master);
    noise.connect(tyre).connect(tyreGain).connect(master);
    noise.connect(rumble).connect(rumbleGain).connect(master);
    // birdsong-ish ambience: gentle high filtered noise
    const amb = ctx.createBiquadFilter(); amb.type = 'bandpass'; amb.frequency.value = 3200; amb.Q.value = 4;
    const ambGain = ctx.createGain(); ambGain.gain.value = 0.012;
    noise.connect(amb).connect(ambGain).connect(master);
    whine.start(); whine2.start(); noise.start();
    nodes = { master, whine, whine2, whineGain, whineLp, wind, windGain, tyre, tyreGain, rumbleGain, noiseBuffer: buf };
  }
  function update(bike) {
    if (!ctx || !nodes) return;
    const t = ctx.currentTime, v = bike.v, n = clamp(v / 33.5, 0, 1);
    const f = 70 + v * 19;
    nodes.whine.frequency.setTargetAtTime(f, t, 0.05);
    nodes.whine2.frequency.setTargetAtTime(f * 2.51, t, 0.05);
    nodes.whineLp.frequency.setTargetAtTime(600 + n * 2600 + bike.input.throttle * 900, t, 0.08);
    nodes.whineGain.gain.setTargetAtTime(0.01 + n * 0.05 + bike.input.throttle * 0.04 * Math.min(1, v / 4 + 0.2), t, 0.06);
    nodes.wind.frequency.setTargetAtTime(380 + n * n * 1400, t, 0.1);
    nodes.windGain.gain.setTargetAtTime(n * n * 0.85, t, 0.1);
    nodes.tyreGain.gain.setTargetAtTime(n * 0.35, t, 0.1);
    nodes.rumbleGain.gain.setTargetAtTime(bike.rumble * 0.9, t, 0.05);
  }
  function setMuted(m) { if (nodes) nodes.master.gain.setTargetAtTime(m ? 0 : 0.7, ctx.currentTime, 0.05); }
  // Oncoming car pass-by: a short noise swell with a falling Doppler sweep.
  function passBy(closingSpeed = 30, gap = 2) {
    if (!ctx || !nodes) return;
    const t = ctx.currentTime;
    const src = ctx.createBufferSource(); src.buffer = nodes.noiseBuffer; src.loop = true;
    const bp = ctx.createBiquadFilter(); bp.type = 'bandpass'; bp.Q.value = 1.1;
    const g = ctx.createGain(); g.gain.value = 0;
    src.connect(bp).connect(g).connect(nodes.master);
    const loud = clamp(0.35 + closingSpeed / 60, 0.3, 0.9) * clamp(1.6 - gap * 0.25, 0.4, 1);
    bp.frequency.setValueAtTime(1500, t); bp.frequency.exponentialRampToValueAtTime(380, t + 0.75);
    g.gain.linearRampToValueAtTime(loud, t + 0.12); g.gain.exponentialRampToValueAtTime(0.001, t + 0.9);
    src.start(t); src.stop(t + 1);
  }
  function thump() {
    if (!ctx || !nodes) return;
    const t = ctx.currentTime;
    const src = ctx.createBufferSource(); src.buffer = nodes.noiseBuffer;
    const lp = ctx.createBiquadFilter(); lp.type = 'lowpass'; lp.frequency.value = 160;
    const g = ctx.createGain(); g.gain.setValueAtTime(1.2, t); g.gain.exponentialRampToValueAtTime(0.001, t + 0.6);
    src.connect(lp).connect(g).connect(nodes.master); src.start(t); src.stop(t + 0.7);
  }
  return { start, update, setMuted, passBy, thump };
};
