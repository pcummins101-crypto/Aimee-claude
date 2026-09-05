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
function loftBody(v, parked=false) {
  const SECT = 40, J = 17;
  const zs = [];
  for (let i = 0; i <= SECT; i += 1) zs.push(-1 + 2 * i / SECT);
  if(!parked){zs.unshift(-1.03); zs.push(1.03);} // moving traffic retains its original mesh
  const positions = [], uvs = [];
  const halfL = v.L / 2;
  const sectionPoints = (z) => {
    const cap = Math.abs(z) > 1;
    const taper = 1 - (parked?.065:.3) * Math.pow(smoothstep(0.7,1.0,Math.abs(z)),1.6);
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
  if(parked){
    for(const row of [0,rows-1]){
      const capBase=positions.length/3,dir=row===0?-1:1,zz=zs[row]*halfL;
      positions.push(0,(v.floor+profileHeight(v,zs[row]))*.5,zz);uvs.push(.5,.5);
      for(let j=0;j<J;j++){const off=(row*J+j)*3;positions.push(positions[off],positions[off+1],positions[off+2]);uvs.push(j/(J-1),.5);}
      for(let j=0;j<J;j++){const a=capBase+1+j,b=capBase+1+(j+1)%J,tri=dir>0?[capBase,a,b]:[capBase,b,a];paintIdx.push(...tri);allIdx.push(...tri);}
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

function makeCar(kind, paint, envMap, parked=false) {
  const v = VARIANTS[kind];
  const bodyKey=parked?'parked:'+kind:kind;
  CACHE.bodies[bodyKey]=CACHE.bodies[bodyKey]||loftBody(v,parked);
  CACHE.decals[kind] = CACHE.decals[kind] || EVO.tex.carDecal(kind);
  CACHE.alloy = CACHE.alloy || EVO.tex.alloy();
  CACHE.shadow = CACHE.shadow || contactShadowTexture();
  const body = CACHE.bodies[bodyKey];
  const g = new THREE.Group();
  const paintMat = new THREE.MeshPhysicalMaterial({ color:paint,map:parked?null:CACHE.decals[kind],metalness:parked?.08:.35,roughness:parked?.5:.32,clearcoat:parked?.18:1,clearcoatRoughness:parked?.28:.06,envMap,envMapIntensity:parked?.34:1.1 });
  const glassMat = new THREE.MeshPhysicalMaterial({ color:parked?0x222c2c:0x0b1014,metalness:.1,roughness:parked?.19:.04,envMap,envMapIntensity:parked?.52:1.4 });
  const trimMat = new THREE.MeshStandardMaterial({ color: 0x141517, roughness: 0.82 });
  const wellMat = new THREE.MeshStandardMaterial({ color: 0x08090a, roughness: 1 });
  const tyreMat = new THREE.MeshStandardMaterial({ color: 0x111214, roughness: 0.92 });
  const alloyMat = new THREE.MeshStandardMaterial({ map: CACHE.alloy, transparent: true, alphaTest: 0.2, metalness: 0.75, roughness: 0.3, envMap, envMapIntensity: 0.8 });
  const lampMat = new THREE.MeshPhysicalMaterial({ color: 0xdfe6ea, emissive: 0xfff2d0, emissiveIntensity: parked?0:1.3, roughness: 0.08, metalness: 0.2, envMap, envMapIntensity: 1 });
  const tailMat = new THREE.MeshPhysicalMaterial({ color: 0x9c1520, emissive: 0x7a0a14, emissiveIntensity: parked?0:.7, roughness: 0.12, envMap });
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
    add(new THREE.BoxGeometry(parked?.38:.46,parked?.12:.15,parked?.045:.12),lampMat,sx*(halfW*.62),noseY,halfL+(parked?.014:-.02),parked?0:.35,sx*(parked?.03:.25), 0, false);
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
  if(!parked)add(new THREE.SphereGeometry(0.11, 10, 8), skinMat, -0.37, v.waist + 0.42, seatZ, 0, 0, 0, false);
  if(!parked)add(new THREE.BoxGeometry(0.42, 0.34, 0.24), new THREE.MeshStandardMaterial({ color: 0x2a3140, roughness: 0.9 }), -0.37, v.waist + 0.14, seatZ, 0, 0, 0, false);
  // contact shadow
  const shadow = new THREE.Mesh(new THREE.PlaneGeometry(v.W * 1.25, v.L * 1.08), new THREE.MeshBasicMaterial({ map: CACHE.shadow, transparent: true, depthWrite: false, polygonOffset: true, polygonOffsetFactor: -1 }));
  shadow.rotation.x = -Math.PI / 2; shadow.position.y = 0.02; shadow.renderOrder = 1;
  g.add(shadow);
  return g;
}

EVO.addParkedVillageCars = function addParkedVillageCars(world, envMap, quality={}) {
  const parked=[],UP=new THREE.Vector3(0,1,0);
  // Merge the static parts of each car by material. This avoids dozens of draw calls per parked vehicle.
  function mergeGroup(group){
    group.updateMatrixWorld(true);const bins=new Map(),remove=[];
    group.traverse(o=>{if(!o.isMesh||o.material.transparent)return;const key=o.material.uuid;
      if(!bins.has(key))bins.set(key,{mat:o.material,parts:[],shadow:false});const b=bins.get(key);const g=o.geometry.clone().applyMatrix4(o.matrixWorld);b.parts.push(g);b.shadow=b.shadow||o.castShadow;remove.push(o);
    });
    remove.forEach(o=>o.removeFromParent());
    for(const b of bins.values()){
      const p=[],n=[],uv=[],ix=[];let offset=0;
      for(const g of b.parts){const pa=g.attributes.position,na=g.attributes.normal,ua=g.attributes.uv;
        for(let i=0;i<pa.count;i++){p.push(pa.getX(i),pa.getY(i),pa.getZ(i));n.push(na.getX(i),na.getY(i),na.getZ(i));uv.push(ua?ua.getX(i):0,ua?ua.getY(i):0);}
        const ind=g.index;if(ind)for(let i=0;i<ind.count;i++)ix.push(ind.getX(i)+offset);else for(let i=0;i<pa.count;i++)ix.push(i+offset);offset+=pa.count;g.dispose();
      }
      const geo=new THREE.BufferGeometry();geo.setAttribute('position',new THREE.Float32BufferAttribute(p,3));geo.setAttribute('normal',new THREE.Float32BufferAttribute(n,3));geo.setAttribute('uv',new THREE.Float32BufferAttribute(uv,2));geo.setIndex(ix);geo.computeBoundingSphere();
      const m=new THREE.Mesh(geo,b.mat);m.castShadow=b.shadow;m.receiveShadow=true;group.add(m);
    }
  }
  RT.detailPlan.lots.forEach((pc,i)=>{
    const mesh=makeCar(pc.kind,pc.paint,envMap,true);mergeGroup(mesh);
    const f={...RT.frame(pc.s)},p=world.groundAt(pc.s,pc.side*pc.d);
    mesh.name='parked vehicle '+(i+1);mesh.position.set(p.x,p.y+.075,p.z);mesh.rotation.set(-Math.atan(f.ty),f.heading+(pc.dir<0?Math.PI:0),0,'YXZ');
    if(pc.dir<0)mesh.rotation.x*=-1;
    world.scene.add(mesh);parked.push(mesh);
  });
  const previous=world.update;let last=-1;
  world.update=function(t,pos,forward){previous(t,pos,forward);if(t-last>.22){
    for(const car of parked){const d=Math.hypot(car.position.x-pos.x,car.position.z-pos.z);car.visible=d<(quality.coarse?145:210);if(car.visible)car.traverse(m=>{if(m.isMesh&&!m.material.transparent)m.castShadow=d<75;});}last=t;
  }};
  world.parkedCars=parked;
};

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
    const sp=Math.atan2(RT.surfaceAt(car.s-1.3,ONCOMING_D)-RT.surfaceAt(car.s+1.3,ONCOMING_D),2.6);
    car.mesh.rotation.set(Math.atan2(f.ty, 1)-sp, Math.atan2(-f.tx, -f.tz), 0, 'YXZ');
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
        const vBend = Math.min(0.82 * EVO.cornerSpeeds(radius).safe, RT.speedLimitAt(car.s-dist)/2.23694);
        limit = Math.min(limit, Math.sqrt(vBend * vBend + 2 * 3.5 * dist));
      }
      const hump=RT.nextHump(car.s,-1);
      if(hump&&hump.dist<100)limit=Math.min(limit,Math.sqrt(5.3*5.3+2*2.7*Math.max(0,hump.dist-5)));
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
