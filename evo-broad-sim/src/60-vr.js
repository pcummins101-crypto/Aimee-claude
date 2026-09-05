import * as THREE from 'three';
/*
 * AVENRÀ EVO · B-ROAD — phone-in-holder VR.
 *
 * An optional mode, layered on top of the ordinary ride: the flat touch and
 * desktop version is untouched and remains the default. Entering VR splits the
 * frame into two eyes rendered side by side into the existing HDR target, and
 * the post composite pre-warps each half with a barrel distortion that cancels
 * the headset lens's pincushion (see 45-post.js).
 *
 * Chrome on Android no longer offers a WebXR immersive-vr session for a plain
 * phone in a holder — Daydream is gone and WebVR was removed — so the stereo
 * rig, head tracking and lens correction are all done here, the way Cardboard
 * did it. Head orientation comes from deviceorientation (3DoF).
 *
 * Comfort is the governing constraint. Rolling the view with the bike's lean is
 * the fastest route to making someone ill, so the horizon stays nearly level
 * while the bike leans underneath the rider, the radial speed blur is off, and
 * a vignette tightens with speed and cornering load.
 */
const EVO = window.EVO;
const { clamp, lerp } = EVO;

const EYE_HEIGHT = 1.28;
const POS_ROLL = 0.6; // how much of the bike's lean physically swings the head

/* Tunables. Lens geometry varies wildly between holders, so every optical
 * figure is adjustable from the start screen and remembered per device. */
const DEFAULTS = {
  ipd: 63,        // interpupillary distance, mm
  fov: 92,        // rendered vertical field of view per eye, degrees
  k1: 24,         // barrel distortion, x100
  k2: 22,         // barrel distortion (quartic), x100
  ca: 7,          // chromatic aberration correction, x1000
  lensX: 50,      // lens centre across each eye, % of the half-frame
  lensY: 50,      // lens centre up each eye, %
  roll: 25,       // share of the bike's lean applied to the view, %
  steerRoll: 26,  // head roll for full steering lock, degrees
  dead: 4,        // head roll deadzone, degrees
  scale: 100,     // render scale, %
  invert: 0       // flip the head-lean steering direction
};

function loadSettings() {
  try { return { ...DEFAULTS, ...JSON.parse(localStorage.getItem('evo.vr') || '{}') }; }
  catch (e) { return { ...DEFAULTS }; }
}
EVO.vrSettings = loadSettings();
EVO.saveVrSettings = () => { try { localStorage.setItem('evo.vr', JSON.stringify(EVO.vrSettings)); } catch (e) { /* private mode */ } };

/* ------------------------------------------------------- head tracking */
function createHeadTracker() {
  const zee = new THREE.Vector3(0, 0, 1);
  const euler = new THREE.Euler();
  const q0 = new THREE.Quaternion();
  const q1 = new THREE.Quaternion(-Math.sqrt(0.5), 0, 0, Math.sqrt(0.5)); // -90 deg about X
  const device = new THREE.Quaternion();
  const refInv = new THREE.Quaternion();
  let have = false, enabled = false, needRef = true;

  const onOrient = (e) => {
    if (!enabled || e.alpha == null) return;
    const a = THREE.MathUtils.degToRad(e.alpha);
    const b = THREE.MathUtils.degToRad(e.beta);
    const g = THREE.MathUtils.degToRad(e.gamma);
    const o = THREE.MathUtils.degToRad((screen.orientation && screen.orientation.angle) || window.orientation || 0);
    euler.set(b, a, -g, 'YXZ');
    device.setFromEuler(euler);
    device.multiply(q1);
    device.multiply(q0.setFromAxisAngle(zee, -o));
    have = true;
    if (needRef) { refInv.copy(device).invert(); needRef = false; }
  };
  window.addEventListener('deviceorientation', onOrient);

  return {
    get tracking() { return have && enabled; },
    async enable() {
      try {
        const D = window.DeviceOrientationEvent;
        if (D && typeof D.requestPermission === 'function') {
          if (await D.requestPermission() !== 'granted') return false;
        }
      } catch (e) { return false; }
      enabled = true; needRef = true;
      return true;
    },
    disable() { enabled = false; },
    recentre() { needRef = true; },
    /* head orientation relative to the recentred forward pose */
    read(out) { if (!have || !enabled) { out.identity(); return false; } out.copy(refInv).multiply(device); return true; }
  };
}

/* ------------------------------------------------------------ gamepad */
const padValue = (pad, i) => {
  const b = pad.buttons[i];
  if (!b) return 0;
  return typeof b.value === 'number' ? b.value : (b.pressed ? 1 : 0);
};
function activePad() {
  const pads = navigator.getGamepads ? navigator.getGamepads() : [];
  for (const p of pads) if (p && p.connected) return p;
  return null;
}

/* -------------------------------------------------------- 3D cockpit */
/* The flat cockpit is a photograph, which has no parallax and reads as a
 * sticker on the eyes in stereo. In VR the rider gets real geometry instead,
 * rigidly attached to the bike: a stable near reference is the single biggest
 * comfort win available, and it is what makes a cockpit view rideable. */
EVO.createVRCockpit = function createVRCockpit(scene, envMap) {
  const group = new THREE.Group();
  group.name = 'vr-cockpit';
  const buckets = {};
  const add = (key, geo, mat4) => { if (mat4) geo.applyMatrix4(mat4); (buckets[key] = buckets[key] || []).push(geo); };
  const M = (x, y, z, rx = 0, ry = 0, rz = 0) => new THREE.Matrix4().compose(
    new THREE.Vector3(x, y, z),
    new THREE.Quaternion().setFromEuler(new THREE.Euler(rx, ry, rz, 'YXZ')),
    new THREE.Vector3(1, 1, 1));
  const A = new THREE.Vector3(), B = new THREE.Vector3(), UP = new THREE.Vector3(0, 1, 0);
  const tube = (key, ax, ay, az, bx, by, bz, r, seg = 8) => {
    A.set(ax, ay, az); B.set(bx, by, bz);
    const dir = B.clone().sub(A), len = dir.length();
    const q = new THREE.Quaternion().setFromUnitVectors(UP, dir.normalize());
    add(key, new THREE.CylinderGeometry(r, r, len, seg, 1),
      new THREE.Matrix4().compose(A.clone().add(B).multiplyScalar(0.5), q, new THREE.Vector3(1, 1, 1)));
  };

  /* Bars and controls. Forward is -Z, +X is the rider's right, and the eye sits
   * at (0, 1.28, 0), so every part is placed by where a rider actually sees it:
   * the screen just under the sightline, mirrors up and out at the edges of
   * vision, dash about 25 degrees down, bars and tank at the bottom. */
  for (const side of [-1, 1]) {
    tube('alloy', side * 0.105, 0.985, -0.55, side * 0.205, 0.968, -0.535, 0.011);        // clip-on
    add('rubber', new THREE.CylinderGeometry(0.018, 0.017, 0.115, 10), M(side * 0.265, 0.966, -0.528, 0, 0, Math.PI / 2));
    add('alloy', new THREE.CylinderGeometry(0.020, 0.020, 0.021, 10), M(side * 0.328, 0.966, -0.528, 0, 0, Math.PI / 2)); // bar end
    add('black', new THREE.BoxGeometry(0.044, 0.046, 0.056), M(side * 0.198, 0.968, -0.535));                             // switchgear
    add('alloy', new THREE.BoxGeometry(0.10, 0.010, 0.018), M(side * 0.268, 0.980, -0.585, 0, side * 0.32, 0));           // lever
    add('alloy', new THREE.CylinderGeometry(0.026, 0.026, 0.05, 8), M(side * 0.093, 1.00, -0.55));                        // fork top
    tube('black', side * 0.175, 1.00, -0.565, side * 0.315, 1.135, -0.545, 0.010);                                        // mirror stalk
    const face = M(side * 0.325, 1.148, -0.542, -0.10, side * 0.42, 0);
    const shell = new THREE.SphereGeometry(1, 12, 8); shell.scale(0.072, 0.046, 0.022);
    add('black', shell, face.clone());
    const glass = new THREE.CircleGeometry(1, 20); glass.scale(0.062, 0.038, 1);
    add('mirror', glass, face.clone().multiply(M(0, 0, 0.023)));
  }
  add('alloy', new THREE.BoxGeometry(0.19, 0.022, 0.08), M(0, 0.985, -0.55));              // top yoke
  add('alloy', new THREE.CylinderGeometry(0.021, 0.021, 0.02, 10), M(0, 0.998, -0.55));    // stem nut
  add('black', new THREE.BoxGeometry(0.04, 0.05, 0.065), M(0.165, 0.995, -0.565));         // master cylinder

  /* dash pod, fairing nose and front wheel */
  add('black', new THREE.BoxGeometry(0.20, 0.09, 0.05), M(0, 1.008, -0.607, -0.55));
  const nose = new THREE.SphereGeometry(1, 14, 10); nose.scale(0.135, 0.105, 0.24);
  add('paint', nose, M(0, 0.945, -0.86));
  const guard = new THREE.CylinderGeometry(0.30, 0.30, 0.11, 16, 1, true, Math.PI * 0.18, Math.PI * 0.64);
  add('paintDS', guard, M(0, 0.44, -0.88, 0, 0, Math.PI / 2));
  const wheel = new THREE.TorusGeometry(0.28, 0.065, 8, 20);
  add('rubber', wheel, M(0, 0.36, -0.88, 0, Math.PI / 2, 0));

  /* tank and seat, at the very bottom of the field of view */
  const tank = new THREE.SphereGeometry(1, 18, 12); tank.scale(0.175, 0.105, 0.29);
  add('paint', tank, M(0, 0.845, -0.30));
  add('paint', new THREE.BoxGeometry(0.17, 0.09, 0.18), M(0, 0.90, -0.50));
  const seat = new THREE.SphereGeometry(1, 14, 10); seat.scale(0.13, 0.06, 0.19);
  add('black', seat, M(0, 0.815, 0.16));

  const T = EVO.tex;
  const mats = {
    paint: new THREE.MeshPhysicalMaterial({ color: 0x1b3f9c, metalness: 0.4, roughness: 0.28, clearcoat: 1, clearcoatRoughness: 0.06, envMap, envMapIntensity: 1.1 }),
    paintDS: new THREE.MeshPhysicalMaterial({ color: 0x1b3f9c, metalness: 0.4, roughness: 0.3, clearcoat: 1, clearcoatRoughness: 0.08, envMap, envMapIntensity: 1.1, side: THREE.DoubleSide }),
    black: new THREE.MeshStandardMaterial({ color: 0x1b2027, roughness: 0.5, metalness: 0.18, envMap, envMapIntensity: 0.7 }),
    alloy: new THREE.MeshStandardMaterial({ map: T.alloy ? T.alloy() : null, color: 0xb9bec4, roughness: 0.3, metalness: 0.9, envMap, envMapIntensity: 1.0 }),
    rubber: new THREE.MeshStandardMaterial({ color: 0x14151a, roughness: 0.95, metalness: 0 }),
    mirror: new THREE.MeshStandardMaterial({ color: 0x7f8c99, roughness: 0.1, metalness: 1, envMap, envMapIntensity: 1.15, side: THREE.DoubleSide })
  };
  for (const key of Object.keys(buckets)) {
    const mesh = new THREE.Mesh(EVO.mergeGeometries(buckets[key]), mats[key]);
    mesh.castShadow = key !== 'mirror';
    mesh.receiveShadow = true;
    mesh.frustumCulled = false;
    group.add(mesh);
  }

  /* the TFT, unlit like a real screen, and the smoked fly screen over it */
  const dashPanel = EVO.createDash();
  const dash = new THREE.Mesh(new THREE.PlaneGeometry(0.165, 0.066),
    new THREE.MeshBasicMaterial({ map: dashPanel.texture, transparent: true }));
  dash.position.set(0, 1.0226, -0.583); dash.rotation.x = -0.55; dash.frustumCulled = false;
  group.add(dash);
  const screen = new THREE.Mesh(new THREE.PlaneGeometry(0.28, 0.20),
    new THREE.MeshPhysicalMaterial({ color: 0x121a22, metalness: 0, roughness: 0.05, transparent: true, opacity: 0.32, side: THREE.DoubleSide, envMap, envMapIntensity: 1.2 }));
  screen.position.set(0, 1.135, -0.735); screen.rotation.x = -1.05; screen.renderOrder = 2; screen.frustumCulled = false;
  group.add(screen);

  scene.add(group);
  let frame = 0;
  return {
    group,
    update(bike) { frame += 1; if (frame % 3 === 0) dashPanel.draw(bike, bike.odometer / 1609.344, true); },
    dispose() { scene.remove(group); }
  };
};

/* ------------------------------------------------------------- the rig */
EVO.createVR = function createVR(renderer, world, bike, post, quality) {
  const S = EVO.vrSettings;
  const RT = EVO.route;
  const head = createHeadTracker();
  // One ArrayCamera with two sub-cameras: three.js applies each sub-camera's
  // viewport itself, so both eyes are drawn in a single traversal with a single
  // shadow pass. (Setting the viewport by hand around renderer.render does not
  // survive into the render, which silently draws both eyes full width.)
  const eyes = [new THREE.PerspectiveCamera(92, 1, 0.12, 2800), new THREE.PerspectiveCamera(92, 1, 0.12, 2800)];
  eyes[0].viewport = new THREE.Vector4(0, 0, 1, 1);
  eyes[1].viewport = new THREE.Vector4(0, 0, 1, 1);
  const stereo = new THREE.ArrayCamera(eyes);
  stereo.near = 0.12; stereo.far = 2800;
  const buffer = new THREE.Vector2();
  const bodyEuler = new THREE.Euler(0, 0, 0, 'YXZ');
  const bodyQuat = new THREE.Quaternion(), posQuat = new THREE.Quaternion(), viewQuat = new THREE.Quaternion();
  const headRel = new THREE.Quaternion();
  const bodyPos = new THREE.Vector3(), eyePos = new THREE.Vector3(), localEye = new THREE.Vector3();
  const right = new THREE.Vector3(), rvec = new THREE.Vector3(), uvec = new THREE.Vector3();
  const size = new THREE.Vector2();
  const frameObj = {};

  let active = false, cockpit = null, wakeLock = null, comfort = 0, headRoll = 0;
  let padRecentre = false, basePixelRatio = renderer.getPixelRatio();
  const supported = !!post;

  const hudEl = document.getElementById('hud');

  function place(dt) {
    const f = bike.frame || RT.frame(bike.s, frameObj);
    const ad = Math.abs(bike.d);
    const groundDrop = ad > RT.LANE_HALF ? -0.1 - (ad - RT.LANE_HALF) * 0.12 : 0;
    bodyPos.set(f.x + f.nx * bike.d, f.y + RT.crown(Math.min(ad, RT.LANE_HALF)) + groundDrop + bike.heave, f.z + f.nz * bike.d);
    const yaw = Math.atan2(-f.tx, -f.tz) + bike.steerSmoothed * 0.05;
    const pitch = Math.atan2(f.ty, 1) * 0.9 + bike.pitch;

    // the bike leans fully; the head follows only part of the way, and the
    // view barely at all — a rider's head stays far more upright than the bike
    bodyEuler.set(pitch, yaw, bike.lean, 'YXZ'); bodyQuat.setFromEuler(bodyEuler);
    bodyEuler.set(pitch, yaw, bike.lean * POS_ROLL, 'YXZ'); posQuat.setFromEuler(bodyEuler);
    bodyEuler.set(pitch, yaw, bike.lean * (S.roll / 100), 'YXZ'); viewQuat.setFromEuler(bodyEuler);

    if (cockpit) { cockpit.group.position.copy(bodyPos); cockpit.group.quaternion.copy(bodyQuat); }

    localEye.set(0, EYE_HEIGHT, 0).applyQuaternion(posQuat);
    eyePos.copy(bodyPos).add(localEye);

    viewQuat.multiply(headRel);
    right.set(1, 0, 0).applyQuaternion(viewQuat);
    const halfIpd = (S.ipd / 1000) / 2;
    renderer.getDrawingBufferSize(buffer);
    const bw = Math.max(2, Math.floor(buffer.x / 2)), bh = Math.max(2, Math.floor(buffer.y));
    const aspect = Math.max(0.2, bw / bh);
    eyes.forEach((cam, i) => {
      const sgn = i === 0 ? -1 : 1;
      cam.position.copy(eyePos).addScaledVector(right, sgn * halfIpd);
      cam.quaternion.copy(viewQuat);
      if (cam.fov !== S.fov || cam.aspect !== aspect) { cam.fov = S.fov; cam.aspect = aspect; cam.updateProjectionMatrix(); }
      cam.viewport.set(i * bw, 0, bw, bh);
      cam.updateMatrixWorld(true);
      cam.matrixWorldInverse.copy(cam.matrixWorld).invert();
    });
    // the enclosing camera drives culling and the shadow pass, so give it a
    // frustum wide enough to contain both eyes
    stereo.position.copy(eyePos);
    stereo.quaternion.copy(viewQuat);
    const wide = Math.max(0.2, (bw * 2) / bh);
    if (stereo.fov !== S.fov + 4 || stereo.aspect !== wide) { stereo.fov = S.fov + 4; stereo.aspect = wide; stereo.updateProjectionMatrix(); }
    stereo.updateMatrixWorld(true);

    // the rest of the world (fog focus, audio, traffic) tracks the rider
    bike.pos.copy(eyePos);
    bike.forward.set(f.tx, f.ty, f.tz).normalize();

    // comfort tunnel: opens up when cruising straight, closes under speed and lean
    const load = Math.min(1, Math.abs(bike.lean) / 0.5) * 0.6 + Math.min(1, bike.v / EVO.V_MAX) * 0.4;
    comfort = lerp(comfort, clamp(load, 0, 1) * 0.55, 1 - Math.exp(-dt * 2.5));
  }

  /* Head roll, in degrees, and the steering it asks for. Also used by the
   * pre-flight test on the start screen so the direction can be checked with
   * the phone in your hands, before it goes into the headset. */
  function sampleHead() {
    head.read(headRel);
    rvec.set(1, 0, 0).applyQuaternion(headRel);
    uvec.set(0, 1, 0).applyQuaternion(headRel);
    headRoll = Math.atan2(rvec.y, uvec.y);
    const deg = THREE.MathUtils.radToDeg(headRoll);
    let steer = 0;
    if (Math.abs(deg) > S.dead) steer = clamp((deg - Math.sign(deg) * S.dead) / Math.max(1, S.steerRoll - S.dead), -1, 1);
    if (S.invert) steer = -steer;
    return { tracking: head.tracking, roll: deg, steer };
  }

  function input() {
    // read the head first: steering comes from it, and the physics step is next
    const h = sampleHead();
    const inp = bike.input;
    let steer = 0, throttle = 0, brake = 0;
    const pad = activePad();
    if (pad) {
      throttle = Math.max(padValue(pad, 7), padValue(pad, 5));
      brake = Math.max(padValue(pad, 6), padValue(pad, 4));
      const ax = pad.axes && pad.axes.length ? pad.axes[0] : 0;
      if (Math.abs(ax) > 0.15) steer = -clamp((ax - Math.sign(ax) * 0.15) / 0.85, -1, 1);
      const rc = padValue(pad, 0) > 0.5;
      if (rc && !padRecentre) head.recentre();
      padRecentre = rc;
      if (padValue(pad, 9) > 0.5) exit();
    }
    // roll your head into the corner, as you would lean a bike
    if (!steer && h.tracking) steer = h.steer;
    inp.throttle = clamp(inp.throttle + throttle, 0, 1);
    inp.brake = clamp(inp.brake + brake, 0, 1);
    inp.steer = clamp(inp.steer + steer, -1, 1);
  }

  async function enter() {
    if (!supported) return { ok: false, reason: 'This device cannot render the HDR frame the lens correction needs.' };
    if (!await head.enable()) return { ok: false, reason: 'Motion sensors were refused. VR needs head tracking; check the site is on https.' };
    try { await document.documentElement.requestFullscreen?.(); } catch (e) { /* not available in this host */ }
    try { await screen.orientation?.lock?.('landscape'); } catch (e) { /* not available in this host */ }
    try { wakeLock = await navigator.wakeLock?.request('screen'); } catch (e) { wakeLock = null; }
    if (!cockpit) cockpit = EVO.createVRCockpit(world.scene, EVO.envMap);
    cockpit.group.visible = true;
    if (hudEl) hudEl.style.display = 'none';
    basePixelRatio = renderer.getPixelRatio();
    renderer.setPixelRatio(Math.min(basePixelRatio, (window.devicePixelRatio || 1)) * (S.scale / 100));
    renderer.setSize(window.innerWidth, window.innerHeight, false);
    head.recentre();
    active = true;
    return { ok: true };
  }

  function exit() {
    if (!active) return;
    active = false;
    head.disable();
    if (cockpit) cockpit.group.visible = false;
    if (hudEl) hudEl.style.display = '';
    renderer.setPixelRatio(basePixelRatio);
    renderer.setSize(window.innerWidth, window.innerHeight, false);
    try { wakeLock?.release(); } catch (e) { /* already gone */ }
    wakeLock = null;
    try { if (document.fullscreenElement) document.exitFullscreen?.(); } catch (e) { /* nothing to exit */ }
    document.dispatchEvent(new CustomEvent('evo-vr-exit'));
  }

  function place2(dt) {
    renderer.getSize(size);
    place(dt);
    cockpit?.update(bike);
  }

  const params = { lensX: 0.5, lensY: 0.5, k1: 0.24, k2: 0.22, ca: 0.007, eyeAspect: 1, comfort: 0 };
  function render(time) {
    post.begin();
    renderer.render(world.scene, stereo);
    renderer.setViewport(0, 0, size.x, size.y);
    params.lensX = S.lensX / 100; params.lensY = S.lensY / 100;
    params.k1 = S.k1 / 100; params.k2 = S.k2 / 100; params.ca = S.ca / 1000;
    params.eyeAspect = Math.max(0.2, (size.x / 2) / Math.max(1, size.y));
    params.comfort = comfort;
    post.end(0, time, params);
  }

  window.addEventListener('keydown', (e) => {
    if (!active) return;
    const k = e.key.toLowerCase();
    if (k === 'escape') exit();
    if (k === 'r') head.recentre();
  });
  document.addEventListener('fullscreenchange', () => { if (active && !document.fullscreenElement) exit(); });

  return {
    get supported() { return supported; },
    get active() { return active; },
    get tracking() { return head.tracking; },
    enter, exit, input, place: place2, render,
    enableTracking: () => head.enable(),
    sampleHead,
    recentre: () => head.recentre(),
    eyes, stereo,
    settings: S
  };
};
