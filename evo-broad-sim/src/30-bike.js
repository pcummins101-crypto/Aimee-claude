import * as THREE from 'three';
/*
 * AVENRÀ EVO · B-ROAD — rider dynamics, camera rig and input.
 *
 * The bike lives in road coordinates: distance along the loop (s), signed
 * lateral offset from the centre line (d, + = left, so the UK riding lane is
 * around +1.5 m) and speed.  Lean follows the physics of the corner the road is
 * describing plus the rider's own steering, and the camera sits at a 1.28 m
 * sports-bike eye point that dips under braking and rises on the throttle.
 */
const EVO = window.EVO;
const { clamp, lerp, smoothstep } = EVO;
const RT = EVO.route;

const G = 9.81;
const V_MAX = 33.5;          // ~75 mph, plenty for a B road
const EYE_HEIGHT = 1.28;

EVO.createBike = function createBike() {
  const bike = {
    s: RT.length * 0.075, d: 1.5, v: 0, a: 0,
    lean: 0, leanTarget: 0, steer: 0, steerSmoothed: 0,
    pitch: 0, pitchVel: 0, heave: 0, heaveVel: 0,
    offRoad: 0, rumble: 0, odometer: 0,
    input: { throttle: 0, brake: 0, steer: 0 },
    pos: new THREE.Vector3(), forward: new THREE.Vector3(), frame: null
  };

  bike.step = function step(dt) {
    const inp = bike.input;
    // Powertrain: electric torque is strongest from a standstill.
    const drive = inp.throttle * 6.8 * Math.max(0.12, 1 - Math.pow(bike.v / V_MAX, 1.6));
    const braking = inp.brake * 9.0;
    const drag = 0.0031 * bike.v * bike.v + 0.22 + bike.offRoad * 2.4;
    let a = drive - braking - drag;
    if (bike.v <= 0.01 && a < 0) a = 0;
    bike.a = a;
    bike.v = clamp(bike.v + a * dt, 0, V_MAX);

    // Steering moves the bike across the carriageway; the rate scales with
    // speed so a lane change takes a believable second or two.
    bike.steerSmoothed = lerp(bike.steerSmoothed, inp.steer, 1 - Math.exp(-dt * 9));
    const lateralRate = Math.min(3.2, 0.9 + bike.v * 0.09);
    bike.d += bike.steerSmoothed * lateralRate * dt;
    const limit = RT.LANE_HALF - 0.35;
    if (Math.abs(bike.d) > limit) {
      bike.offRoad = lerp(bike.offRoad, 1, 1 - Math.exp(-dt * 6));
      bike.d = clamp(bike.d, -RT.LANE_HALF + 0.05, RT.LANE_HALF - 0.05);
    } else bike.offRoad = lerp(bike.offRoad, 0, 1 - Math.exp(-dt * 4));
    bike.rumble = bike.offRoad * Math.min(1, bike.v / 8);

    const f = RT.frame(bike.s);
    bike.frame = f;
    // Lean: corner physics from the road curvature plus a steering lean.
    const cornerLean = Math.atan((bike.v * bike.v * f.kappa) / G);
    const steerLean = bike.steerSmoothed * THREE.MathUtils.degToRad(11) * Math.min(1, bike.v / 9);
    bike.leanTarget = clamp(cornerLean + steerLean, -0.85, 0.85);
    bike.lean = lerp(bike.lean, bike.leanTarget, 1 - Math.exp(-dt * 5.5));

    // Suspension: fork dive under braking, squat on the throttle, road buzz.
    const pitchTarget = -a * 0.0075;
    bike.pitchVel += ((pitchTarget - bike.pitch) * 60 - bike.pitchVel * 9) * dt;
    bike.pitch += bike.pitchVel * dt;
    const buzz = (EVO.noise2(bike.s * 0.7, 0.3) - 0.5) * 0.02 * Math.min(1, bike.v / 12) + bike.rumble * (EVO.noise2(bike.s * 4, 7) - 0.5) * 0.09;
    bike.heaveVel += ((buzz - bike.heave) * 140 - bike.heaveVel * 14) * dt;
    bike.heave += bike.heaveVel * dt;

    bike.s = EVO.mod(bike.s + bike.v * dt, RT.length);
    bike.odometer += bike.v * dt;
  };

  const _up = new THREE.Vector3(0, 1, 0);
  bike.applyCamera = function applyCamera(camera, portrait) {
    const f = bike.frame || RT.frame(bike.s);
    // Eye moves to the inside of the corner as the rider leans.
    const eyeD = bike.d + Math.sin(bike.lean) * 0.42;
    const eyeH = EYE_HEIGHT * Math.cos(bike.lean * 0.9) + bike.heave - Math.max(0, -bike.pitch) * 0.8;
    bike.pos.set(f.x + f.nx * eyeD, f.y + RT.crown(eyeD) + eyeH, f.z + f.nz * eyeD);
    camera.position.copy(bike.pos);
    const yaw = Math.atan2(-f.tx, -f.tz) + bike.steerSmoothed * 0.05 + bike.lean * 0.04;
    const slope = Math.atan2(f.ty, 1);
    camera.rotation.set(slope * 0.9 + bike.pitch - 0.065, yaw, bike.lean * 0.72, 'YXZ');
    const speedFov = Math.min(1, bike.v / V_MAX);
    camera.fov = (portrait ? 74 : 60) + speedFov * 5;
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
