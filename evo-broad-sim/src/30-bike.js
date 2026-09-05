import * as THREE from 'three';

/*
 * AVENRÀ EVO · B-ROAD — rider dynamics, corner limits, camera rig and input.
 *
 * The bike lives in road coordinates: distance along the loop (s), signed
 * lateral offset from the centre line (d, + = left, so the UK riding lane is
 * around +1.5 m) and speed.  The powertrain is fitted to the EVO's figures
 * (0-60 mph in 3.9 s, 109 mph top speed).  Every metre of road has a safe and
 * a maximum cornering speed derived from its radius; ride between them and the
 * bike drifts wide unless you hold it, ride above the maximum and it runs off.
 */
const EVO = window.EVO;
const { clamp, lerp, smoothstep, mod } = EVO;
const RT = EVO.route;

const G = 9.81;
const V_MAX = 48.7;                 // 109 mph terminal speed
const DRAG_C2 = 0.0024, DRAG_C0 = 0.15;
const POWER_A0 = 8.25;              // peak acceleration from rest, m/s² (fitted: 0-60 mph in 3.90 s)
const POWER_P = 1.2;                // torque fall-off curve
const POWER_K = 1 - (DRAG_C2 * V_MAX * V_MAX + DRAG_C0) / POWER_A0; // pins terminal speed to V_MAX
const EYE_HEIGHT = 1.28;
const A_LAT_SAFE = 5.6;             // ~0.57 g: comfortable road pace
const A_LAT_MAX = 8.6;              // ~0.88 g: the tyres' limit on dry tarmac
const DECEL_PLAN = 7.2;             // braking the planner assumes, m/s²
const LOOKAHEAD = 170, LOOK_STEP = 4;
const LANE_EDGE = 2.7, VERGE_EDGE = 3.6, CRASH_EDGE = 4.5;

EVO.V_MAX = V_MAX;
EVO.grip = 1; // scaled down by the weather; 1 is dry tarmac
EVO.cornerSpeeds = (radius) => ({ safe: Math.min(V_MAX, Math.sqrt(A_LAT_SAFE * EVO.grip * radius)), max: Math.min(V_MAX, Math.sqrt(A_LAT_MAX * EVO.grip * radius)) });

EVO.createBike = function createBike() {
  const bike = {
    s: RT.length * 0.075, d: 1.5, v: 0, a: 0,
    lean: 0, leanTarget: 0, steer: 0, steerSmoothed: 0,
    pitch: 0, pitchVel: 0, heave: 0, heaveVel: 0,
    offRoad: 0, rumble: 0, odometer: 0, drift: 0, surfaceRoughness:0.2, surfaceImpact:0, motionScale:1,
    crashTimer: 0, crashes: 0, notice: null,
    corner: { safe: V_MAX, max: V_MAX, ratio: 0, status: 'safe', bendDist: Infinity, bendDir: 0, bendRadius: Infinity, bendSafe: V_MAX, bendMax: V_MAX, latAcc: 0 },
    input: { throttle: 0, brake: 0, steer: 0 },
    pos: new THREE.Vector3(), forward: new THREE.Vector3(), frame: null
  };

  bike.say = function say(text, seconds = 2.4, tone = 'info') {
    bike.notice = { text, until: bike.elapsed + seconds, tone };
  };
  bike.elapsed = 0;

  bike.crash = function crash(reason) {
    if (bike.crashTimer > 0) return;
    bike.crashTimer = 2.6;
    bike.crashes += 1;
    bike.v = Math.min(bike.v, 4);
    bike.say(reason, 2.6, 'crash');
  };

  /* Corner planner: the highest speed you can carry now and still slow to
   * every bend ahead with the planning deceleration, plus the next bend's own
   * apex speeds for the label. frame() hands back a shared scratch object, so
   * the planner and the bike keep private copies. */
  const scratch = {};
  bike.frameObj = {};
  function planCorner() {
    let allowedSafe = V_MAX, allowedMax = V_MAX;
    let bendDist = Infinity, bendDir = 0, bendRadius = Infinity, bendK = 0, inBend = false, bendDone = false;
    for (let dist = 0; dist <= LOOKAHEAD; dist += LOOK_STEP) {
      const f = RT.frame(bike.s + dist, scratch);
      const k = Math.abs(f.kappa);
      const radius = 1 / Math.max(k, 1e-4);
      const lim = EVO.cornerSpeeds(radius);
      const brake = 2 * DECEL_PLAN * dist;
      allowedSafe = Math.min(allowedSafe, Math.sqrt(lim.safe * lim.safe + brake));
      allowedMax = Math.min(allowedMax, Math.sqrt(lim.max * lim.max + brake * 1.15));
      // the first bend ahead: remember its tightest point
      if (!bendDone) {
        if (k > 1 / 150) { inBend = true; if (k > bendK) { bendK = k; bendDist = dist; bendDir = Math.sign(f.kappa); bendRadius = radius; } }
        else if (inBend) bendDone = true;
      }
    }
    const c = bike.corner;
    c.safe = allowedSafe; c.max = allowedMax;
    const apex = EVO.cornerSpeeds(bendRadius);
    c.bendSafe = apex.safe; c.bendMax = apex.max;
    c.ratio = bike.v / Math.max(1, allowedMax);
    c.status = bike.v <= allowedSafe + 0.3 ? 'safe' : bike.v <= allowedMax ? 'limit' : 'over';
    c.bendDist = bendDist; c.bendDir = bendDir; c.bendRadius = bendRadius;
  }

  bike.step = function step(dt) {
    bike.elapsed += dt;
    const inp = bike.input;
    const crashed = bike.crashTimer > 0;
    if (crashed) bike.crashTimer -= dt;
    const throttle = crashed ? 0 : inp.throttle;
    const brake = crashed ? 1 : inp.brake;

    // Powertrain: EV torque is strongest from a standstill and tails off so the
    // terminal speed lands on V_MAX.
    const drive = throttle * POWER_A0 * (1 - POWER_K * Math.pow(bike.v / V_MAX, POWER_P));
    const braking = brake * 9.2;
    const drag = DRAG_C2 * bike.v * bike.v + DRAG_C0 + bike.offRoad * 3.2;
    const gradient=RT.frame(bike.s,scratch).ty;
    let a = drive - braking - drag - G * gradient;
    if (bike.v <= 0.01 && a < 0) a = 0;
    bike.a = a;
    bike.v = clamp(bike.v + a * dt, 0, V_MAX);

    planCorner();
    const f = RT.frame(bike.s, bike.frameObj);
    bike.frame = f;

    // Steering moves the bike across the carriageway; the rate scales with
    // speed so a lane change takes a believable second or two.
    bike.steerSmoothed = lerp(bike.steerSmoothed, crashed ? 0 : inp.steer, 1 - Math.exp(-dt * 9));
    const lateralRate = Math.min(3.2, 0.9 + bike.v * 0.09) * Math.min(1,bike.v/1.5);
    bike.d += bike.steerSmoothed * lateralRate * dt;

    // Cornering physics: above the safe lateral acceleration the bike wants to
    // run wide; above the tyres' limit it will.
    const kappa = f.kappa;
    const latAcc = bike.v * bike.v * Math.abs(kappa);
    bike.corner.latAcc = latAcc;
    let drift = 0;
    const gripSafe = A_LAT_SAFE * EVO.grip, gripMax = A_LAT_MAX * EVO.grip;
    if (latAcc > gripSafe) {
      const over = (latAcc - gripSafe) / (gripMax - gripSafe);
      drift = over < 1 ? over * over * 0.45 : 0.45 + (over - 1) * 5.5;
      bike.d -= Math.sign(kappa) * drift * dt; // outside of the bend
      if (over >= 1 && !crashed && !(bike.notice && bike.notice.tone === 'over')) bike.say('TOO FAST · RUNNING WIDE', 1.2, 'over');
    }
    bike.drift = lerp(bike.drift, drift, 1 - Math.exp(-dt * 6));

    // Road edge, verge and the hedge line.
    const ad = Math.abs(bike.d);
    if (ad > LANE_EDGE) {
      bike.offRoad = lerp(bike.offRoad, ad > VERGE_EDGE ? 1.6 : 1, 1 - Math.exp(-dt * 6));
    } else bike.offRoad = lerp(bike.offRoad, 0, 1 - Math.exp(-dt * 4));
    if (ad >= CRASH_EDGE && !crashed) bike.crash(bike.d > 0 ? 'OFF THE ROAD · INTO THE HEDGE' : 'OFF THE ROAD · WRONG SIDE');
    bike.d = clamp(bike.d, -CRASH_EDGE, CRASH_EDGE);
    if (crashed && bike.crashTimer <= 0) { bike.d = 1.5; bike.offRoad = 0; bike.drift = 0; }
    bike.rumble = clamp(bike.offRoad * Math.min(1, bike.v / 8), 0, 1);

    // Lean: corner physics from the road curvature plus a steering lean.
    const cornerLean = Math.atan((bike.v * bike.v * kappa) / G);
    const steerLean = bike.steerSmoothed * THREE.MathUtils.degToRad(11) * Math.min(1, bike.v / 9);
    bike.leanTarget = clamp(cornerLean + steerLean, -0.9, 0.9);
    bike.lean = lerp(bike.lean, bike.leanTarget, 1 - Math.exp(-dt * 5.5));

    // Wheelbase-separated road inputs: both wheels follow the same geometry.
    // A damped visual chassis response, not a validated motorcycle dynamics model.
    const frontSurface=RT.surfaceAt(bike.s+.74,bike.d), rearSurface=RT.surfaceAt(bike.s-.74,bike.d);
    const roadHeave=(frontSurface+rearSurface)*.5;
    const roadPitch=Math.atan2(frontSurface-rearSurface,1.48);
    bike.surfaceRoughness=RT.roughnessAt(bike.s,bike.d);
    bike.surfaceImpact=lerp(bike.surfaceImpact,Math.abs(frontSurface-rearSurface)*10*Math.min(1,bike.v/7),1-Math.exp(-dt*18));
    bike.rumble=clamp(Math.max(bike.rumble,(bike.surfaceRoughness-.45)*Math.min(1,bike.v/8),bike.surfaceImpact*.35),0,1);
    const pitchTarget = a * 0.0048 + roadPitch * .65;
    bike.pitchVel += ((pitchTarget - bike.pitch) * 60 - bike.pitchVel * 9) * dt;
    bike.pitch += bike.pitchVel * dt;
    const crashShake = crashed ? (EVO.noise2(bike.elapsed * 40, 1) - 0.5) * 0.12 * Math.min(1, bike.crashTimer) : 0;
    const buzz = ((EVO.noise2(bike.s * 0.7, 0.3) - 0.5) * (0.007+bike.surfaceRoughness*.024) * Math.min(1, bike.v / 12) + bike.rumble * (EVO.noise2(bike.s * 4, 7) - 0.5) * 0.035 + crashShake)*bike.motionScale;
    bike.heaveVel += ((roadHeave + buzz - bike.heave) * 180 - bike.heaveVel * 19) * dt;
    bike.heave += bike.heaveVel * dt;

    bike.s = mod(bike.s + bike.v * dt, RT.length);
    bike.odometer += bike.v * dt;
    if (bike.notice && bike.elapsed > bike.notice.until) bike.notice = null;
  };

  bike.applyCamera = function applyCamera(camera, portrait) {
    const f = RT.frame(bike.s, bike.frameObj); bike.frame=f;
    // Eye moves to the inside of the corner as the rider leans.
    const eyeD = bike.d + Math.sin(bike.lean) * 0.42;
    const eyeH = EYE_HEIGHT * Math.cos(bike.lean * 0.9) + bike.heave - Math.max(0, -bike.pitch) * 0.8;
    const ad = Math.abs(eyeD);
    const groundDrop = ad > RT.LANE_HALF ? -0.1 - (ad - RT.LANE_HALF) * 0.12 : 0; // verge and ditch
    bike.pos.set(f.x + f.nx * eyeD, f.y + RT.crown(Math.min(ad, RT.LANE_HALF)) + groundDrop + eyeH, f.z + f.nz * eyeD);
    camera.position.copy(bike.pos);
    const yaw = Math.atan2(-f.tx, -f.tz) + bike.steerSmoothed * 0.05 + bike.lean * 0.04;
    const slope = Math.atan2(f.ty, 1);
    camera.rotation.set(slope * 0.9 + bike.pitch*bike.motionScale - 0.050, yaw, bike.lean * 0.66*bike.motionScale, 'YXZ');
    const speedFov = Math.min(1, bike.v / V_MAX);
    camera.fov = (portrait ? 74 : 60) + speedFov * 6;
    camera.updateProjectionMatrix();
    bike.forward.set(f.tx, f.ty, f.tz).normalize();
  };

  bike.mph = () => Math.round(bike.v * 2.23694);
  return bike;
};

/* ---------------------------------------------------------------- input */
EVO.createControls = function createControls(element, bike, opts = {}) {
  const keys = new Set();
  const pointers = new Map();
  let tilt = 0, tiltEnabled = false, tiltBase = null;
  const state = { touchSteer: 0, throttleTouch: 0, brakeTouch: 0 };

  const role = (x, y) => {
    const w = element.clientWidth, h = element.clientHeight;
    if (y > h * 0.55) return x > w * 0.5 ? 'throttle' : 'brake';
    return 'steer';
  };
  element.addEventListener('pointerdown', (e) => {
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    element.setPointerCapture?.(e.pointerId);
    const r = role(e.clientX, e.clientY);
    pointers.set(e.pointerId, { role: r, x0: e.clientX, x: e.clientX });
    if (r === 'throttle') state.throttleTouch = 1; else if (r === 'brake') state.brakeTouch = 1;
    opts.onInteract?.();
    e.preventDefault();
  }, { passive: false });
  element.addEventListener('pointermove', (e) => {
    const p = pointers.get(e.pointerId);
    if (!p) return;
    p.x = e.clientX;
    if (p.role === 'steer') state.touchSteer = clamp((p.x - p.x0) / (element.clientWidth * 0.16), -1, 1);
  });
  const release = (e) => {
    const p = pointers.get(e.pointerId);
    if (!p) return;
    pointers.delete(e.pointerId);
    if (p.role === 'steer') state.touchSteer = 0;
    if (p.role === 'throttle') state.throttleTouch = 0;
    if (p.role === 'brake') state.brakeTouch = 0;
  };
  element.addEventListener('pointerup', release);
  element.addEventListener('pointercancel', release);
  element.addEventListener('lostpointercapture', release);
  window.addEventListener('keydown', (e) => { keys.add(e.key.toLowerCase()); if ([' ', 'arrowup', 'arrowdown', 'arrowleft', 'arrowright'].includes(e.key.toLowerCase())) e.preventDefault(); opts.onInteract?.(); });
  window.addEventListener('keyup', (e) => keys.delete(e.key.toLowerCase()));
  window.addEventListener('blur', () => keys.clear());

  const orientation = (e) => {
    if (!tiltEnabled) return;
    const angle = ((screen.orientation?.angle ?? window.orientation ?? 0) % 360 + 360) % 360;
    // landscape: beta carries the roll; portrait: gamma
    let raw = angle === 90 ? e.beta : angle === 270 ? -e.beta : angle === 180 ? -e.gamma : e.gamma;
    if (raw == null) return;
    if (tiltBase == null) tiltBase = raw;
    const rel = raw - tiltBase;
    tilt = clamp((rel - Math.sign(rel) * 1.5) / 16, -1, 1);
    if (Math.abs(rel) < 1.5) tilt = 0;
  };
  window.addEventListener('deviceorientation', orientation);

  return {
    async enableTilt() {
      try {
        const D = window.DeviceOrientationEvent;
        if (D && typeof D.requestPermission === 'function') {
          const res = await D.requestPermission();
          if (res !== 'granted') return false;
        }
        tiltEnabled = true; tiltBase = null;
        return true;
      } catch (err) { return false; }
    },
    get tiltEnabled() { return tiltEnabled; },
    update() {
      const inp = bike.input;
      const kThrottle = keys.has('arrowup') || keys.has('w') ? 1 : 0;
      const kBrake = keys.has('arrowdown') || keys.has('s') || keys.has(' ') ? 1 : 0;
      const kSteer = (keys.has('arrowleft') || keys.has('a') ? 1 : 0) - (keys.has('arrowright') || keys.has('d') ? 1 : 0);
      inp.throttle = clamp(kThrottle + state.throttleTouch, 0, 1);
      inp.brake = clamp(kBrake + state.brakeTouch, 0, 1);
      // steer: keyboard/touch is +1 = left. Tilting the phone left (roll left) = steer left.
      let steer = kSteer + state.touchSteer * -1;
      if (tiltEnabled && Math.abs(steer) < 0.05) steer = -tilt;
      inp.steer = clamp(steer, -1, 1);
    }
  };
};
