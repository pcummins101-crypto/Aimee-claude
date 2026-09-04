import * as THREE from 'three';
/*
 * AVENRÀ EVO · B-ROAD — oncoming traffic.
 *
 * Cars are lofted from a smooth cross-section swept along a roof-line profile
 * (hatchback, SUV and van), split into clearcoat paint and dark reflective
 * glass that mirror an environment map generated from the sky.  Arched sills,
 * dark wheel wells, alloy wheels, lights, plates, mirrors, an interior with a
 * driver and a soft contact shadow finish them.  They keep to their own lane,
 * slow for bends and junctions, and will hit you if you are on their side.
 */
const EVO = window.EVO;
const { clamp, lerp, mod, smoothstep } = EVO;
const RT = EVO.route;

const ONCOMING_D = -1.55;          // their lane centre, in our road coordinates
const CAR_HALF_WIDTH = 0.92;

// Roof-line profiles: [normalised z (-1 tail … +1 nose), height in metres]
const VARIANTS = {
  hatch: { L: 4.3, W: 1.78, floor: 0.34, waist: 0.97, wheelR: 0.33, wheelZ: 1.32, sideGlass: [-0.86, 0.5], screen: [0.28, 0.56], rear: [-0.92, -0.74],
    top: [[-1, 1.0], [-0.92, 1.22], [-0.74, 1.45], [-0.2, 1.47], [0.28, 1.44], [0.56, 1.02], [0.62, 0.93], [0.92, 0.86], [1, 0.7]] },
  suv: { L: 4.65, W: 1.9, floor: 0.42, waist: 1.08, wheelR: 0.37, wheelZ: 1.42, sideGlass: [-0.9, 0.42], screen: [0.2, 0.5], rear: [-0.94, -0.78], rails: true,
    top: [[-1, 1.28], [-0.94, 1.62], [-0.78, 1.74], [0.2, 1.74], [0.5, 1.5], [0.56, 1.12], [0.62, 1.06], [0.92, 1.0], [1, 0.84]] },
  van: { L: 5.1, W: 1.98, floor: 0.4, waist: 1.14, wheelR: 0.35, wheelZ: 1.58, sideGlass: [0.36, 0.62], screen: [0.62, 0.76], rear: null, van: true,
    top: [[-1, 1.75], [-0.97, 2.02], [0.5, 2.06], [0.62, 1.95], [0.76, 1.36], [0.82, 1.2], [0.95, 1.1], [1, 0.94]] }
};

function profileHeight(v, z) {
  const t = v.top;
  if (z <= t[0][0]) return t[0][1];
  for (let i = 0; i < t.length - 1; i += 1) if (z <= t[i + 1][0]) {
    const u = (z - t[i][0]) / (t[i + 1][0] - t[i][0]);
    return lerp(t[i][1], t[i + 1][1], u * u * (3 - 2 * u));
  }
  return t[t.length - 1][1];
}

/* Lofted body: returns { paint, glass } geometries sharing vertex arrays. */
function loftBody(v) {
  const SECT = 40, J = 17;
  const zs = [];
  for (let i = 0; i <= SECT; i += 1) zs.push(-1 + 2 * i / SECT);
  zs.unshift(-1.03); zs.push(1.03); // end caps
  const positions = [], uvs = [];
  const halfL = v.L / 2;
  const sectionPoints = (z) => {
    const cap = Math.abs(z) > 1;
    const taper = 1 - 0.3 * Math.pow(smoothstep(0.7, 1.0, Math.abs(z)), 1.6);
    const w = cap ? v.W * 0.06 : v.W / 2 * taper;
    const top = cap ? (v.floor + profileHeight(v, Math.sign(z))) / 2 : profileHeight(v, z);
    // sills rise over the wheels to read as arches
    const zm = z * halfL;
    const arch = Math.max(Math.exp(-Math.pow((zm - v.wheelZ) / 0.55, 2)), Math.exp(-Math.pow((zm + v.wheelZ) / 0.55, 2)));
    const floor = v.floor + arch * (v.wheelR * 0.95);
    const waist = Math.min(v.waist, top - 0.06);
    const glassy = top > v.waist + 0.12;
    const shoulder = waist + 0.07;
    const pts = [];
    const side = (sgn) => [
      [sgn * w * 0.9, floor], [sgn * w * 0.995, floor + 0.16], [sgn * w, waist * 0.78], [sgn * w * 0.99, waist],
      [sgn * w * 0.94, glassy ? shoulder : lerp(waist, top, 0.35)],
      [sgn * w * 0.86, glassy ? lerp(shoulder, top, 0.45) : lerp(waist, top, 0.7)],
      [sgn * w * 0.72, glassy ? top - 0.03 : top - 0.01],
      [sgn * w * 0.38, top]
    ];
    const left = side(1), right = side(-1).reverse();
    pts.push(...left, [0, top + 0.005], ...right);
    return pts;
  };
  zs.forEach((z) => {
    const pts = sectionPoints(z);
    pts.forEach((p, j) => { positions.push(p[0], p[1], z * halfL); uvs.push(j / (J - 1), (z + 1) / 2); });
  });
  const rows = zs.length;
  const paintIdx = [], glassIdx = [], allIdx = [];
  const inRange = (z, r) => r && z >= r[0] && z <= r[1];
  for (let i = 0; i < rows - 1; i += 1) {
    const zm = (zs[i] + zs[i + 1]) / 2;
    for (let j = 0; j < J - 1; j += 1) {
      const a = i * J + j, b = a + J;
      const upper = j >= 4 && j <= 11;                 // above the shoulder line
      const sideOnly = (j >= 4 && j <= 5) || (j >= 10 && j <= 11);
      let glass = false;
      if (upper && (inRange(zm, v.screen) || inRange(zm, v.rear))) glass = true;
      if (sideOnly && inRange(zm, v.sideGlass)) glass = true;
      // winding: nose is +z and the section climbs the left (+x) side first,
      // so (a, a+1, b+1) / (a, b+1, b) face outward
      const tri = [a, a + 1, b + 1, a, b + 1, b];
      (glass ? glassIdx : paintIdx).push(...tri); allIdx.push(...tri);
    }
  }
  const full = new THREE.BufferGeometry();
  const posAttr = new THREE.Float32BufferAttribute(positions, 3), uvAttr = new THREE.Float32BufferAttribute(uvs, 2);
  full.setAttribute('position', posAttr); full.setAttribute('uv', uvAttr); full.setIndex(allIdx);
  full.computeVertexNormals();
  const make = (idx) => { const g = new THREE.BufferGeometry(); g.setAttribute('position', posAttr); g.setAttribute('normal', full.getAttribute('normal')); g.setAttribute('uv', uvAttr); g.setIndex(idx); g.computeBoundingSphere(); return g; };
  return { paint: make(paintIdx), glass: make(glassIdx) };
}

function contactShadowTexture() {
  const c = document.createElement('canvas'); c.width = 128; c.height = 64;
  const ctx = c.getContext('2d');
  const g = ctx.createRadialGradient(64, 32, 6, 64, 32, 60);
  g.addColorStop(0, 'rgba(0,0,0,0.75)'); g.addColorStop(0.55, 'rgba(0,0,0,0.45)'); g.addColorStop(1, 'rgba(0,0,0,0)');
  ctx.fillStyle = g; ctx.scale(1, 1); ctx.fillRect(0, 0, 128, 64);
  return new THREE.CanvasTexture(c);
}

const CACHE = { bodies: {}, decals: {}, alloy: null, shadow: null };

function makeCar(kind, paint, envMap) {
  const v = VARIANTS[kind];
  CACHE.bodies[kind] = CACHE.bodies[kind] || loftBody(v);
  CACHE.decals[kind] = CACHE.decals[kind] || EVO.tex.carDecal(kind);
  CACHE.alloy = CACHE.alloy || EVO.tex.alloy();
  CACHE.shadow = CACHE.shadow || contactShadowTexture();
  const body = CACHE.bodies[kind];
  const g = new THREE.Group();
  const paintMat = new THREE.MeshPhysicalMaterial({ color: paint, map: CACHE.decals[kind], metalness: 0.35, roughness: 0.32, clearcoat: 1, clearcoatRoughness: 0.06, envMap, envMapIntensity: 1.1 });
  const glassMat = new THREE.MeshPhysicalMaterial({ color: 0x0b1014, metalness: 0.1, roughness: 0.04, envMap, envMapIntensity: 1.4 });
  const trimMat = new THREE.MeshStandardMaterial({ color: 0x141517, roughness: 0.82 });
  const wellMat = new THREE.MeshStandardMaterial({ color: 0x08090a, roughness: 1 });
  const tyreMat = new THREE.MeshStandardMaterial({ color: 0x111214, roughness: 0.92 });
  const alloyMat = new THREE.MeshStandardMaterial({ map: CACHE.alloy, transparent: true, alphaTest: 0.2, metalness: 0.75, roughness: 0.3, envMap, envMapIntensity: 0.8 });
  const lampMat = new THREE.MeshPhysicalMaterial({ color: 0xdfe6ea, emissive: 0xfff2d0, emissiveIntensity: 1.3, roughness: 0.08, metalness: 0.2, envMap, envMapIntensity: 1 });
  const tailMat = new THREE.MeshPhysicalMaterial({ color: 0x9c1520, emissive: 0x7a0a14, emissiveIntensity: 0.7, roughness: 0.12, envMap });
  const plateFront = new THREE.MeshStandardMaterial({ color: 0xf4f2e6, roughness: 0.5 });
  const plateRear = new THREE.MeshStandardMaterial({ color: 0xf2d24a, roughness: 0.5 });
  const interiorMat = new THREE.MeshStandardMaterial({ color: 0x15171a, roughness: 0.95 });
  const skinMat = new THREE.MeshStandardMaterial({ color: 0xb98a6a, roughness: 0.8 });
  const add = (geo, mat, x = 0, y = 0, z = 0, rx = 0, ry = 0, rz = 0, shadow = true) => {
    const m = new THREE.Mesh(geo, mat); m.position.set(x, y, z); m.rotation.set(rx, ry, rz);
    m.castShadow = shadow; m.receiveShadow = shadow; g.add(m); return m;
  };
  add(body.paint, paintMat);
  add(body.glass, glassMat);
  const halfL = v.L / 2, halfW = v.W / 2;
  // wheels: tyre torus, alloy face both sides, dark well behind
  const tyre = new THREE.TorusGeometry(v.wheelR - 0.075, 0.08, 10, 26);
  const rim = new THREE.CircleGeometry(v.wheelR - 0.11, 24);
  const well = new THREE.BoxGeometry(0.42, v.wheelR * 1.9, v.wheelR * 2.2);
  for (const sx of [1, -1]) for (const sz of [1, -1]) {
    const x = sx * (halfW - 0.17), z = sz * v.wheelZ;
    add(tyre, tyreMat, x, v.wheelR, z, 0, Math.PI / 2, 0);
    add(rim, alloyMat, x + sx * 0.1, v.wheelR, z, 0, sx * Math.PI / 2, 0, false);
    add(new THREE.CylinderGeometry(v.wheelR - 0.1, v.wheelR - 0.1, 0.18, 18), trimMat, x, v.wheelR, z, 0, 0, Math.PI / 2, false);
    add(well, wellMat, x - sx * 0.12, v.wheelR + 0.1, z, 0, 0, 0, false);
  }
  add(new THREE.BoxGeometry(v.W * 0.86, 0.1, v.L * 0.8), wellMat, 0, v.floor - 0.02, 0, 0, 0, 0, false); // underbody
  // lights, grille, plates
  const noseY = lerp(v.floor, profileHeight(v, 0.97), 0.62);
  for (const sx of [1, -1]) {
    add(new THREE.BoxGeometry(0.46, 0.15, 0.12), lampMat, sx * (halfW * 0.62), noseY, halfL - 0.02, 0.35, sx * 0.25, 0, false);
    add(new THREE.BoxGeometry(0.3, 0.13, 0.08), tailMat, sx * (halfW * 0.7), lerp(v.floor, profileHeight(v, -0.97), 0.62), -halfL + 0.01, 0, 0, 0, false);
    add(new THREE.BoxGeometry(0.12, 0.09, 0.2), trimMat, sx * (halfW + 0.12), v.waist + 0.16, v.screen[0] * halfL - 0.1, 0, 0, 0, false); // mirrors
  }
  add(new THREE.BoxGeometry(0.62, 0.12, 0.05), trimMat, 0, noseY - 0.24, halfL - 0.03, 0.3, 0, 0, false); // grille
  add(new THREE.BoxGeometry(0.52, 0.11, 0.02), plateFront, 0, v.floor + 0.14, halfL - 0.01, 0.2, 0, 0, false);
  add(new THREE.BoxGeometry(0.52, 0.11, 0.02), plateRear, 0, v.floor + 0.2, -halfL + 0.005, 0, Math.PI, 0, false);
  if (v.rails) for (const sx of [1, -1]) add(new THREE.BoxGeometry(0.05, 0.06, v.L * 0.42), trimMat, sx * halfW * 0.66, profileHeight(v, -0.2) + 0.03, -0.15, 0, 0, 0, false);
  // interior and driver (UK: right-hand seat)
  // interior sits under the flat part of the roof only, so it never pokes through the screens
  const cabZ0 = (v.rear ? v.rear[1] : v.sideGlass[0]) * halfL, cabZ1 = v.screen[0] * halfL;
  const cabH = (Math.min(profileHeight(v, cabZ0 / halfL), profileHeight(v, cabZ1 / halfL)) - v.waist) * 0.8;
  add(new THREE.BoxGeometry(v.W * 0.78, cabH, cabZ1 - cabZ0), interiorMat, 0, v.waist + cabH * 0.5, (cabZ0 + cabZ1) / 2, 0, 0, 0, false);
  const seatZ = v.screen[0] * halfL - 0.55;
  add(new THREE.SphereGeometry(0.11, 10, 8), skinMat, -0.37, v.waist + 0.42, seatZ, 0, 0, 0, false);
  add(new THREE.BoxGeometry(0.42, 0.34, 0.24), new THREE.MeshStandardMaterial({ color: 0x2a3140, roughness: 0.9 }), -0.37, v.waist + 0.14, seatZ, 0, 0, 0, false);
  // contact shadow
  const shadow = new THREE.Mesh(new THREE.PlaneGeometry(v.L * 1.06, v.W * 1.35), new THREE.MeshBasicMaterial({ map: CACHE.shadow, transparent: true, depthWrite: false, polygonOffset: true, polygonOffsetFactor: -1 }));
  shadow.rotation.x = -Math.PI / 2; shadow.position.y = 0.02; shadow.renderOrder = 1;
  g.add(shadow);
  return g;
}

EVO.createTraffic = function createTraffic(scene, bike, opts = {}) {
  const count = opts.count ?? 5;
  const L = RT.length;
  const envMap = opts.envMap || null;
  const paints = [0xc9ccd1, 0x1f3a6e, 0x8c1a1f, 0xe9e7e0, 0x26282b, 0x4c6b3c, 0x8a8f96, 0x2f4f8f];
  const kinds = ['hatch', 'hatch', 'suv', 'van', 'hatch', 'suv', 'hatch', 'van'];
  const rnd = EVO.rng(777);
  const cars = [];
  for (let i = 0; i < count; i += 1) {
    const kind = kinds[i % kinds.length];
    const mesh = makeCar(kind, kind === 'van' && i % 2 ? 0xf2f0ea : paints[i % paints.length], envMap);
    scene.add(mesh);
    // spread them around the loop, none right on top of the rider at the start
    const s = mod(bike.s + L * (0.28 + i / count * 0.92) + rnd() * 50, L);
    cars.push({ mesh, s, v: 16 + rnd() * 4, cruise: (kind === 'van' ? 16.5 : 18) + rnd() * 5, lastRel: null });
  }

  const _p = new THREE.Vector3();
  function place(car) {
    const f = RT.frame(car.s);
    RT.point(car.s, ONCOMING_D, 0, _p);
    car.mesh.position.copy(_p);
    car.mesh.rotation.set(Math.atan2(f.ty, 1), Math.atan2(-f.tx, -f.tz), 0, 'YXZ');
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
      // keep a gap to the car ahead in their direction
      for (const other of cars) {
        if (other === car) continue;
        const gap = mod(car.s - other.s, L); // other is ahead of car (lower s) when gap is small and positive
        if (gap > 0 && gap < 26) limit = Math.min(limit, other.v * (gap / 26));
      }
      car.v = lerp(car.v, limit, 1 - Math.exp(-dt * 0.9));
      car.s = mod(car.s - car.v * dt, L);
      place(car);

      // relative position along the road from the rider (+ = ahead)
      const rel = mod(car.s - bike.s + L / 2, L) - L / 2;
      if (car.lastRel !== null && car.lastRel > 0 && rel <= 0) {
        events.passBy = { gap: Math.abs(bike.d - ONCOMING_D) - CAR_HALF_WIDTH, closing: bike.v + car.v };
      }
      car.lastRel = rel;
      if (Math.abs(rel) < 2.5 && Math.abs(bike.d - ONCOMING_D) < CAR_HALF_WIDTH + 0.35) events.collision = car;
    }
    return events;
  }

  return { cars, update };
};
