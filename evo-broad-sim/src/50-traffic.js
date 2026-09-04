import * as THREE from 'three';
/*
 * AVENRÀ EVO · B-ROAD — light oncoming traffic.
 *
 * A handful of cars circulate the loop in the opposite direction, keeping to
 * their own lane and slowing for the bends the way a sensible driver would.
 * Stray onto the wrong side of the road in front of one and you will meet it.
 */
const EVO = window.EVO;
const { clamp, lerp, mod } = EVO;
const RT = EVO.route;

const ONCOMING_D = -1.55;          // their lane centre, in our road coordinates
const CAR_HALF_WIDTH = 0.9;

function mergeParts(parts) {
  // parts: [{geo, x, y, z, rx, ry, rz}] → one BufferGeometry (position, normal, uv)
  const p = [], n = [], uv = [], idx = [];
  const m = new THREE.Matrix4(), e = new THREE.Euler(), nm = new THREE.Matrix3();
  let base = 0;
  for (const part of parts) {
    const g = part.geo.clone();
    e.set(part.rx || 0, part.ry || 0, part.rz || 0);
    m.makeRotationFromEuler(e); m.setPosition(part.x, part.y, part.z);
    g.applyMatrix4(m);
    const pa = g.getAttribute('position'), na = g.getAttribute('normal'), ua = g.getAttribute('uv');
    for (let i = 0; i < pa.count; i += 1) { p.push(pa.getX(i), pa.getY(i), pa.getZ(i)); n.push(na.getX(i), na.getY(i), na.getZ(i)); uv.push(ua.getX(i), ua.getY(i)); }
    const ix = g.getIndex(); for (let i = 0; i < ix.count; i += 1) idx.push(ix.getX(i) + base);
    base += pa.count;
  }
  const out = new THREE.BufferGeometry();
  out.setAttribute('position', new THREE.Float32BufferAttribute(p, 3)); out.setAttribute('normal', new THREE.Float32BufferAttribute(n, 3)); out.setAttribute('uv', new THREE.Float32BufferAttribute(uv, 2)); out.setIndex(idx);
  return out;
}

const CAR_GEO = {};
function carGeometries() {
  if (CAR_GEO.paint) return CAR_GEO;
  const box = (w, h, d) => new THREE.BoxGeometry(w, h, d);
  const wheel = new THREE.CylinderGeometry(0.33, 0.33, 0.24, 18);
  const hub = new THREE.CylinderGeometry(0.19, 0.19, 0.26, 12);
  const wheels = [[-0.82, 1.35], [0.82, 1.35], [-0.82, -1.35], [0.82, -1.35]];
  // local +z is the car's forward direction
  CAR_GEO.paint = mergeParts([
    { geo: box(1.78, 0.5, 4.3), x: 0, y: 0.62, z: 0 }, { geo: box(1.7, 0.16, 1.3), x: 0, y: 0.94, z: 1.35 }, { geo: box(1.62, 0.5, 1.9), x: 0, y: 1.15, z: -0.2 }]);
  CAR_GEO.glass = mergeParts([
    { geo: box(1.5, 0.52, 0.08), x: 0, y: 1.16, z: 0.86, rx: -0.55 }, { geo: box(1.5, 0.44, 0.06), x: 0, y: 1.16, z: -1.2, rx: 0.5 },
    { geo: box(0.05, 0.36, 1.5), x: -0.82, y: 1.16, z: -0.2 }, { geo: box(0.05, 0.36, 1.5), x: 0.82, y: 1.16, z: -0.2 }]);
  CAR_GEO.trim = mergeParts([
    { geo: box(1.8, 0.16, 0.5), x: 0, y: 0.33, z: 2.1 }, { geo: box(1.8, 0.16, 0.5), x: 0, y: 0.33, z: -2.1 },
    { geo: box(0.06, 0.04, 0.5), x: -0.9, y: 0.9, z: 1.0 }, { geo: box(0.06, 0.04, 0.5), x: 0.9, y: 0.9, z: 1.0 },
    ...wheels.map(([x, z]) => ({ geo: wheel, x, y: 0.33, z, rz: Math.PI / 2 }))]);
  CAR_GEO.hub = mergeParts(wheels.map(([x, z]) => ({ geo: hub, x, y: 0.33, z, rz: Math.PI / 2 })));
  CAR_GEO.lamp = mergeParts([{ geo: box(0.34, 0.16, 0.06), x: -0.62, y: 0.66, z: 2.16 }, { geo: box(0.34, 0.16, 0.06), x: 0.62, y: 0.66, z: 2.16 }, { geo: box(0.5, 0.11, 0.03), x: 0, y: 0.44, z: 2.17 }]);
  CAR_GEO.tail = mergeParts([{ geo: box(0.3, 0.14, 0.06), x: -0.66, y: 0.68, z: -2.16 }, { geo: box(0.3, 0.14, 0.06), x: 0.66, y: 0.68, z: -2.16 }]);
  return CAR_GEO;
}

function makeCar(paint) {
  const g = new THREE.Group();
  const geo = carGeometries();
  const mats = {
    paint: new THREE.MeshStandardMaterial({ color: paint, metalness: 0.55, roughness: 0.32 }),
    glass: new THREE.MeshStandardMaterial({ color: 0x1a232b, metalness: 0.2, roughness: 0.15 }),
    trim: new THREE.MeshStandardMaterial({ color: 0x141517, roughness: 0.85 }),
    hub: new THREE.MeshStandardMaterial({ color: 0xa7abb0, metalness: 0.8, roughness: 0.35 }),
    lamp: new THREE.MeshStandardMaterial({ color: 0xf6f3e6, emissive: 0xfff4d6, emissiveIntensity: 1.6, roughness: 0.3 }),
    tail: new THREE.MeshStandardMaterial({ color: 0x8a1420, emissive: 0x6a0812, emissiveIntensity: 0.8, roughness: 0.4 })
  };
  for (const key of Object.keys(mats)) {
    const m = new THREE.Mesh(geo[key], mats[key]); m.castShadow = true; m.receiveShadow = true; g.add(m);
  }
  return g;
}

EVO.createTraffic = function createTraffic(scene, bike, opts = {}) {
  const count = opts.count ?? 4;
  const L = RT.length;
  const paints = [0xb9bcc2, 0x1f3a6e, 0x8c1a1f, 0xe8e6df, 0x2a2b2e, 0x4c6b3c];
  const rnd = EVO.rng(777);
  const cars = [];
  for (let i = 0; i < count; i += 1) {
    const mesh = makeCar(paints[i % paints.length]);
    scene.add(mesh);
    // spread them around the loop, none right on top of the rider at the start
    const s = mod(bike.s + L * (0.32 + i / count * 0.9) + rnd() * 60, L);
    cars.push({ mesh, s, v: 16 + rnd() * 4, cruise: 18 + rnd() * 5, lastRel: null });
  }

  const _p = new THREE.Vector3();
  function place(car) {
    const f = RT.frame(car.s);
    RT.point(car.s, ONCOMING_D, 0, _p);
    car.mesh.position.copy(_p);
    car.mesh.rotation.set(-Math.atan2(f.ty, 1) * 0, Math.atan2(-f.tx, -f.tz), 0, 'YXZ');
  }
  cars.forEach(place);

  function update(dt) {
    const events = { collision: null, passBy: null };
    for (const car of cars) {
      // slow for the bends ahead of the car (it travels toward decreasing s)
      let limit = car.cruise;
      for (let dist = 0; dist <= 90; dist += 6) {
        const f = RT.frame(car.s - dist);
        const radius = 1 / Math.max(Math.abs(f.kappa), 1e-4);
        const vBend = 0.82 * EVO.cornerSpeeds(radius).safe;
        limit = Math.min(limit, Math.sqrt(vBend * vBend + 2 * 3.5 * dist));
      }
      // ease off approaching the junction mouths
      for (const j of RT.JUNCTIONS) { const dj = Math.abs(mod(car.s - j.s + L / 2, L) - L / 2); if (dj < 30) limit = Math.min(limit, 11); }
      car.v = lerp(car.v, limit, 1 - Math.exp(-dt * 0.9));
      car.s = mod(car.s - car.v * dt, L);
      place(car);

      // relative position along the road from the rider (+ = ahead)
      const rel = mod(car.s - bike.s + L / 2, L) - L / 2;
      if (car.lastRel !== null && car.lastRel > 0 && rel <= 0) {
        events.passBy = { gap: Math.abs(bike.d - ONCOMING_D) - CAR_HALF_WIDTH, closing: bike.v + car.v };
      }
      car.lastRel = rel;
      if (Math.abs(rel) < 2.4 && Math.abs(bike.d - ONCOMING_D) < CAR_HALF_WIDTH + 0.35) events.collision = car;
    }
    return events;
  }

  return { cars, update };
};
