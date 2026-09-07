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
const SAME_D = 1.55;               // our lane centre: same-direction traffic
const CAR_HALF_WIDTH = 0.92;

// Roof-line profiles: [normalised z (-1 tail … +1 nose), height in metres]
const VARIANTS = {
  hatch: { L: 4.3, W: 1.78, floor: 0.34, waist: 0.97, wheelR: 0.33, wheelZ: 1.32, sideGlass: [-0.86, 0.5], screen: [0.28, 0.56], rear: [-0.92, -0.74],
    top: [[-1, 1.0], [-0.92, 1.22], [-0.74, 1.45], [-0.2, 1.47], [0.28, 1.44], [0.56, 1.02], [0.62, 0.93], [0.92, 0.86], [1, 0.7]] },
  suv: { L: 4.65, W: 1.9, floor: 0.42, waist: 1.08, wheelR: 0.37, wheelZ: 1.42, sideGlass: [-0.9, 0.42], screen: [0.2, 0.5], rear: [-0.94, -0.78], rails: true,
    top: [[-1, 1.28], [-0.94, 1.62], [-0.78, 1.74], [0.2, 1.74], [0.5, 1.5], [0.56, 1.12], [0.62, 1.06], [0.92, 1.0], [1, 0.84]] },
  van: { L: 5.1, W: 1.98, floor: 0.4, waist: 1.14, wheelR: 0.35, wheelZ: 1.58, sideGlass: [0.36, 0.62], screen: [0.62, 0.76], rear: null, van: true,
    top: [[-1, 1.75], [-0.97, 2.02], [0.5, 2.06], [0.62, 1.95], [0.76, 1.36], [0.82, 1.2], [0.95, 1.1], [1, 0.94]] },
  coach: { L: 12.0, W: 2.55, floor: 0.66, waist: 1.62, wheelR: 0.5, wheelZ: 4.2, sideGlass: [-0.84, 0.7], screen: [0.7, 0.9], rear: [-0.99, -0.92], van: true,
    top: [[-1, 2.3], [-0.95, 3.36], [0.86, 3.42], [0.94, 3.2], [1, 2.5]] }
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
  g.userData.tail = tailMat;
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

/* Merge the static parts of a vehicle by material: one draw call per material
 * instead of dozens of small meshes. Brake lights keep their own material. */
// Merge the static parts of each car by material. This avoids dozens of draw calls per parked vehicle.
function mergeGroup(group){
  group.updateMatrixWorld(true);const bins=new Map(),remove=[];
  group.traverse(o=>{if(!o.isMesh||(o.material.transparent&&!o.material.alphaTest))return;const key=o.material.uuid;
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

EVO.addParkedVillageCars = function addParkedVillageCars(world, envMap, quality={}) {
  const parked=[],UP=new THREE.Vector3(0,1,0);
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

/* An articulated lorry: a cab and a box trailer, built from panels rather than
 * lofted. It is 16 m long and does 50 mph on the flat, which is what makes an
 * overtake on a fast road a decision rather than a formality. */
const LORRY = { L: 16.2, W: 2.55, cabL: 5.6, trailerH: 4.0 };
function makeLorry(paint, envMap) {
  const g = new THREE.Group();
  CACHE.alloy = CACHE.alloy || EVO.tex.alloy();
  CACHE.shadow = CACHE.shadow || contactShadowTexture();
  const paintMat = new THREE.MeshPhysicalMaterial({ color: paint, metalness: 0.3, roughness: 0.38, clearcoat: 0.8, clearcoatRoughness: 0.12, envMap, envMapIntensity: 0.9 });
  const boxMat = new THREE.MeshStandardMaterial({ color: 0xe9e7e0, roughness: 0.72, metalness: 0.05, envMap, envMapIntensity: 0.45 });
  const glassMat = new THREE.MeshPhysicalMaterial({ color: 0x0d1317, metalness: 0.1, roughness: 0.06, envMap, envMapIntensity: 1.2 });
  const darkMat = new THREE.MeshStandardMaterial({ color: 0x1b1f22, roughness: 0.8 });
  const tyreMat = new THREE.MeshStandardMaterial({ color: 0x14161a, roughness: 0.95 });
  const alloyMat = new THREE.MeshStandardMaterial({ map: CACHE.alloy, transparent: true, alphaTest: 0.2, metalness: 0.75, roughness: 0.35, envMap, envMapIntensity: 0.7 });
  const lampMat = new THREE.MeshPhysicalMaterial({ color: 0xdfe6ea, emissive: 0xfff2d0, emissiveIntensity: 1.2, roughness: 0.1 });
  const tailMat = new THREE.MeshPhysicalMaterial({ color: 0x8e1119, emissive: 0x6d0a12, emissiveIntensity: 0.7, roughness: 0.14 });
  g.userData.tail = tailMat;
  const halfL = LORRY.L / 2, halfW = LORRY.W / 2;
  const add = (geo, mat, x, y, z, rx = 0, ry = 0) => {
    const m = new THREE.Mesh(geo, mat);
    m.position.set(x, y, z); m.rotation.set(rx, ry, 0);
    m.castShadow = true; m.receiveShadow = true; g.add(m); return m;
  };
  // cab: nose at +z
  const cabZ = halfL - LORRY.cabL / 2;
  add(new THREE.BoxGeometry(LORRY.W, 2.05, LORRY.cabL), paintMat, 0, 1.9, cabZ);
  add(new THREE.BoxGeometry(LORRY.W - 0.12, 0.95, 0.1), glassMat, 0, 2.55, cabZ + LORRY.cabL / 2 - 0.02);   // windscreen
  for (const sx of [-1, 1]) {
    add(new THREE.BoxGeometry(0.1, 0.8, 1.5), glassMat, sx * (halfW - 0.02), 2.45, cabZ + 0.3);              // door glass
    add(new THREE.BoxGeometry(0.5, 0.24, 0.16), lampMat, sx * (halfW * 0.62), 1.15, halfL - 0.04);           // headlamps
    add(new THREE.BoxGeometry(0.16, 0.5, 0.1), darkMat, sx * (halfW + 0.12), 2.7, cabZ + LORRY.cabL / 2 - 0.5); // mirror arms
  }
  add(new THREE.BoxGeometry(LORRY.W * 0.9, 0.5, 0.22), darkMat, 0, 0.72, halfL - 0.02);                      // bumper
  add(new THREE.BoxGeometry(LORRY.W - 0.2, 0.9, 0.3), paintMat, 0, 3.35, cabZ + 0.6);                        // roof deflector
  // trailer: a plain box body on a chassis
  const trZ = -halfL + (LORRY.L - LORRY.cabL) / 2 + 0.2;
  add(new THREE.BoxGeometry(LORRY.W, LORRY.trailerH - 1.1, LORRY.L - LORRY.cabL - 0.4), boxMat, 0, 1.25 + (LORRY.trailerH - 1.1) / 2, trZ);
  add(new THREE.BoxGeometry(LORRY.W - 0.06, 0.18, LORRY.L - LORRY.cabL - 0.4), darkMat, 0, 1.2, trZ);        // chassis rail
  for (const sx of [-1, 1]) add(new THREE.BoxGeometry(0.34, 0.2, 0.1), tailMat, sx * (halfW * 0.7), 1.5, -halfL + 0.03);
  add(new THREE.BoxGeometry(LORRY.W * 0.85, 0.4, 0.14), darkMat, 0, 0.85, -halfL + 0.05);                    // rear underrun bar
  // wheels: steer axle, drive pair, trailer triple
  const tyre = new THREE.CylinderGeometry(0.52, 0.52, 0.34, 16); tyre.rotateZ(Math.PI / 2);
  const hub = new THREE.CircleGeometry(0.34, 18); hub.rotateY(Math.PI / 2);
  for (const z of [halfL - 1.5, cabZ - 1.9, cabZ - 3.2, trZ - 2.2, trZ - 3.6, trZ - 5.0]) {
    for (const sx of [-1, 1]) {
      add(tyre, tyreMat, sx * (halfW - 0.2), 0.52, z);
      add(hub, alloyMat, sx * (halfW - 0.02), 0.52, z, 0, sx > 0 ? 0 : Math.PI);
    }
  }
  const shadow = new THREE.Mesh(new THREE.PlaneGeometry(LORRY.W * 1.3, LORRY.L * 1.02),
    new THREE.MeshBasicMaterial({ map: CACHE.shadow, transparent: true, depthWrite: false, polygonOffset: true, polygonOffsetFactor: -1 }));
  shadow.rotation.x = -Math.PI / 2; shadow.position.y = 0.03; g.add(shadow);
  return g;
}

EVO.createTraffic = function createTraffic(scene, bike, opts = {}) {
  if (EVO.ROUTE.traffic?.motorway && EVO.createMotorwayTraffic) return EVO.createMotorwayTraffic(scene, bike, opts, { makeCar, makeLorry, LORRY, CAR_HALF_WIDTH });
  const count = opts.count ?? 5;
  const sameCount = opts.same ?? 3;
  const L = RT.length;
  const envMap = opts.envMap || null;
  const paints = [0xc9ccd1, 0x1f3a6e, 0x8c1a1f, 0xe9e7e0, 0x26282b, 0x4c6b3c, 0x8a8f96, 0x2f4f8f];
  const kinds = ['hatch', 'hatch', 'suv', 'van', 'hatch', 'suv', 'hatch', 'van'];
  const rnd = EVO.rng(777);
  const cars = [];
  for (let i = 0; i < count; i += 1) {
    const hgvHere = (opts.hgv ?? 0) > 0 && i % 4 === 3;
    const kind = hgvHere ? 'lorry' : kinds[i % kinds.length];
    const mesh = hgvHere ? makeLorry([0x35507a, 0x8d8f93][i % 2], envMap)
      : makeCar(kind, kind === 'van' && i % 2 ? 0xf2f0ea : paints[i % paints.length], envMap);
    scene.add(mesh);
    // spread them around the loop, none right on top of the rider at the start
    const s = mod(bike.s + L * (0.28 + i / count * 0.92) + rnd() * 50, L);
    const cruise = hgvHere ? 22 + rnd() * 1.5 : ((kind === 'van' ? 16.5 : 18) + rnd() * 5) * (opts.cruise ?? 1);
    cars.push({ mesh, dir: -1, lane: ONCOMING_D, s, v: Math.min(16 + rnd() * 4, cruise), cruise, lastRel: null, braking: 0, lorry: hgvHere, half: hgvHere ? LORRY.L / 2 : 2.4 });
  }
  // Same-direction traffic: slower than the EVO wants to go, so the rider has
  // to sit behind it or pick the moment to pass. One of them is a dawdling van.
  const hgv = opts.hgv ?? 0, cruiseScale = opts.cruise ?? 1;
  const sameKinds = ['van', 'hatch', 'suv', 'hatch'];
  for (let i = 0; i < sameCount; i += 1) {
    const lorry = hgv > 0 && (i % Math.max(2, Math.round(1 / hgv)) === 0);
    const kind = lorry ? 'lorry' : sameKinds[i % sameKinds.length];
    const mesh = lorry ? makeLorry([0x9d2a2f, 0x2c4a7a, 0xe6e3da][i % 3], envMap)
      : makeCar(kind, [0xe4e1d8, 0x6b1f24, 0x3a4a5c, 0xb8b9bd][i % 4], envMap);
    scene.add(mesh);
    const s = mod(bike.s + 140 + i * (L / sameCount) + rnd() * 60, L);
    // a laden lorry sits at about 50 mph and will not be hurried
    const cruise = lorry ? 21.5 + rnd() * 1.4 : (kind === 'van' ? 11.5 + rnd() * 1.5 : 15 + rnd() * 4) * cruiseScale;
    cars.push({ mesh, dir: 1, lane: SAME_D, s, v: cruise, cruise, lastRel: null, braking: 0, lorry, half: lorry ? LORRY.L / 2 : 2.4 });
  }
  let mode = 'both';

  const _p = new THREE.Vector3();
  function place(car) {
    const f = RT.frame(car.s);
    RT.point(car.s, car.lane, 0, _p);
    car.mesh.position.copy(_p);
    const sp = Math.atan2(RT.surfaceAt(car.s - 1.3, car.lane) - RT.surfaceAt(car.s + 1.3, car.lane), 2.6);
    if (car.dir < 0) car.mesh.rotation.set(Math.atan2(f.ty, 1) - sp, Math.atan2(-f.tx, -f.tz), 0, 'YXZ');
    else car.mesh.rotation.set(-Math.atan2(f.ty, 1) + sp, Math.atan2(f.tx, f.tz), 0, 'YXZ');
  }
  cars.forEach(place);

  function active(car) { return mode === 'both' || (mode === 'oncoming' && car.dir < 0); }

  function update(dt) {
    const events = { collision: null, passBy: null, overtake: null, tailgate: 0 };
    for (const car of cars) {
      if (!active(car)) continue;
      const dir = car.dir;
      // slow for the bends, limits and humps ahead of the car in its direction
      let limit = car.cruise;
      for (let dist = 0; dist <= 90; dist += 6) {
        const f = RT.frame(car.s + dir * dist);
        const radius = 1 / Math.max(Math.abs(f.kappa), 1e-4);
        const vBend = Math.min(0.82 * EVO.cornerSpeeds(radius).safe, RT.speedLimitAt(car.s + dir * dist) / 2.23694);
        limit = Math.min(limit, Math.sqrt(vBend * vBend + 2 * 3.5 * dist));
      }
      const hump = RT.nextHump(car.s, dir);
      if (hump && hump.dist < 100) limit = Math.min(limit, Math.sqrt(5.3 * 5.3 + 2 * 2.7 * Math.max(0, hump.dist - 5)));
      // ease off approaching the junction mouths
      for (const j of RT.JUNCTIONS) { const dj = Math.abs(mod(car.s - j.s + L / 2, L) - L / 2); if (dj < 30) limit = Math.min(limit, 11); }
      // keep a gap to whatever is ahead in this direction: other cars, and the bike
      for (const other of cars) {
        if (other === car || other.dir !== dir || !active(other)) continue;
        const gap = mod((other.s - car.s) * dir, L);
        const need = 26 + other.half + car.half;
        if (gap > 0 && gap < need) limit = Math.min(limit, other.v * (gap / need));
      }
      if (dir > 0) {
        const gap = mod(bike.s - car.s, L);
        const inLane = Math.abs(bike.d - SAME_D) < CAR_HALF_WIDTH + 0.9;
        if (inLane && gap > 0 && gap < 34) {
          // follow the bike; stop short of it rather than drive through it
          limit = Math.min(limit, gap < 7 ? 0 : bike.v * ((gap - 7) / 27));
          if (gap < 14 && bike.v < car.cruise - 3) events.tailgate = Math.max(events.tailgate, 1 - gap / 14);
        }
      }
      // cars brake harder than they accelerate
      const rate = limit < car.v - 0.5 ? 2.6 : 0.9;
      car.v = lerp(car.v, limit, 1 - Math.exp(-dt * rate));
      car.braking = lerp(car.braking, limit < car.v - 0.8 ? 1 : 0, 1 - Math.exp(-dt * 8));
      if (car.mesh.userData.tail) car.mesh.userData.tail.emissiveIntensity = 0.7 + car.braking * 2.2;
      car.s = mod(car.s + dir * car.v * dt, L);
      place(car);

      // relative position along the road from the rider (+ = ahead)
      const rel = mod(car.s - bike.s + L / 2, L) - L / 2;
      const lateral = Math.abs(bike.d - car.lane);
      if (car.lastRel !== null && car.lastRel > 0 && rel <= 0) {
        if (dir < 0) events.passBy = { gap: lateral - CAR_HALF_WIDTH, closing: bike.v + car.v };
        else events.overtake = { car, gap: lateral - CAR_HALF_WIDTH, closing: bike.v - car.v };
      }
      car.lastRel = rel;
      if (dir < 0) {
        if (Math.abs(rel) < car.half && lateral < CAR_HALF_WIDTH + 0.35) events.collision = { car, reason: car.lorry ? 'COLLISION · ONCOMING LORRY' : 'COLLISION · ONCOMING CAR' };
      } else if (lateral < CAR_HALF_WIDTH + 0.35 && rel > -car.half && rel < car.half + 0.2 && bike.v > car.v + 0.5) {
        events.collision = { car, reason: car.lorry ? 'RAN INTO THE LORRY AHEAD' : 'RAN INTO THE CAR AHEAD' };
      }
    }
    return events;
  }

  /* Oncoming traffic within the next `metres` ahead of the rider, with the
   * closing time it represents: the score's judge of a safe overtake. */
  function oncomingAhead(metres = 400) {
    let best = null;
    for (const car of cars) {
      if (car.dir > 0 || !active(car)) continue;
      const rel = mod(car.s - bike.s, L);
      if (rel > metres) continue;
      const time = rel / Math.max(1, bike.v + car.v);
      if (!best || time < best.time) best = { car, distance: rel, time };
    }
    return best;
  }
  function sameAhead(metres = 60) {
    let best = null;
    for (const car of cars) {
      if (car.dir < 0 || !active(car)) continue;
      const rel = mod(car.s - bike.s, L);
      if (rel > metres) continue;
      if (!best || rel < best.distance) best = { car, distance: rel };
    }
    return best;
  }
  function setMode(next) {
    mode = next;
    for (const car of cars) { car.mesh.visible = active(car); car.lastRel = null; }
  }

  return { cars, update, setMode, oncomingAhead, sameAhead, get mode() { return mode; } };
};

/* Motorway traffic: four lanes of same-direction vehicles with UK lane
 * discipline (keep left, move out to pass, move back when clear, lorries in
 * the inside lanes at 56 mph) and a cosmetic flow on the other carriageway.
 * The road is open-ended, so vehicles are recycled around the rider. */
EVO.createMotorwayTraffic = function createMotorwayTraffic(scene, bike, opts, parts) {
  const { makeCar, makeLorry, LORRY, CAR_HALF_WIDTH } = parts;
  const L = RT.length, LANES = RT.laneCentres, LW = RT.DUAL.laneW, envMap = opts.envMap || null;
  const SB_LANES = [0, 1, 2, 3].map((k) => RT.DUAL.reserveFar - LW * (3.5 - k)); // their lane 1 first, nearest their verge
  const coarse = !!opts.coarse;
  const rnd = EVO.rng(9091);
  const cars = [];
  const paints = [0xc9ccd1, 0x1f3a6e, 0x8c1a1f, 0xe9e7e0, 0x26282b, 0x4c6b3c, 0x8a8f96, 0x2f4f8f, 0xd8d5cc, 0x5b1d24];
  const lorryPaints = [0x35507a, 0x8d8f93, 0x9d2a2f, 0x2c4a7a, 0xe6e3da, 0x2d6b3a];
  const hgvShare = opts.hgv ?? 0.3;
  function build(kind, i) {
    const lorry = kind === 'lorry';
    const mesh = lorry ? makeLorry(lorryPaints[i % lorryPaints.length], envMap)
      : makeCar(kind, kind === 'van' && i % 2 ? 0xf2f0ea : paints[i % paints.length], envMap);
    mergeGroup(mesh);
    scene.add(mesh);
    return { mesh, lorry, kind, half: lorry ? LORRY.L / 2 : kind === 'coach' ? 6.4 : kind === 'van' ? 2.55 : 2.35 };
  }
  // cruise speeds by type and lane: lorries on the limiter, cars a little over the limit in the outer lanes
  function cruiseFor(kind, lane) {
    if (kind === 'lorry') return 24.4 + rnd() * 0.9;
    if (kind === 'coach') return 26.5 + rnd() * 1.2;
    if (kind === 'van') return 27 + rnd() * 3;
    return [27.5 + rnd() * 3, 29.5 + rnd() * 3, 31.5 + rnd() * 3, 33 + rnd() * 3.5][lane];
  }
  function laneFor(kind) {
    const r = rnd();
    if (kind === 'lorry' || kind === 'coach') return r < 0.85 ? 0 : 1;
    if (kind === 'van') return r < 0.6 ? 0 : 1;
    return r < 0.38 ? 0 : r < 0.72 ? 1 : r < 0.93 ? 2 : 3;
  }
  // never drop a vehicle into a lane that is coned off where it lands
  function pickLane(kind, dir, s) {
    let lane = laneFor(kind);
    if (dir > 0) { const c = closedAt(s); if (c >= 0 && lane <= c) lane = Math.min(3, c + 1); }
    return lane;
  }
  const T = EVO.ROUTE.traffic || {};
  const count = (coarse ? T.countCoarse : T.count) ?? (coarse ? 15 : 24);
  const sbCount = (coarse ? T.sbCountCoarse : T.sbCount) ?? (coarse ? 9 : 14);
  // A closed lane is out of service from well before the taper: drivers merge
  // early, and anything still in it has to find a gap or wait for one.
  const closedAt = (s) => (RT.closedLane ? RT.closedLane(s) : -1);
  for (let i = 0; i < count; i += 1) {
    const r = rnd();
    const kind = r < hgvShare ? 'lorry' : r < hgvShare + 0.06 ? 'coach' : r < hgvShare + 0.2 ? 'van' : r < hgvShare + 0.56 ? 'hatch' : 'suv';
    const c = build(kind, i);
    const lane = laneFor(kind);
    cars.push({ ...c, dir: 1, lane, d: LANES[lane], s: 0, v: 0, cruise: cruiseFor(kind, lane), braking: 0, lastRel: null, inLaneSince: 0, changeCooldown: 0 });
  }
  for (let i = 0; i < sbCount; i += 1) {
    const r = rnd();
    const kind = r < 0.25 ? 'lorry' : r < 0.31 ? 'coach' : r < 0.42 ? 'van' : r < 0.72 ? 'hatch' : 'suv';
    const c = build(kind, i + 40);
    const lane = laneFor(kind);
    cars.push({ ...c, dir: -1, lane, d: SB_LANES[lane], s: 0, v: 0, cruise: cruiseFor(kind, lane), braking: 0, lastRel: null, inLaneSince: 0, changeCooldown: 0 });
  }
  let mode = 'both';
  function active(car) { return mode === 'both' || (mode === 'oncoming' && car.dir < 0); }

  // Seed positions in a window around the rider, spaced out per lane.
  function clearAt(car, s) {
    for (const o of cars) { if (o === car || o.dir !== car.dir || o.lane !== car.lane) continue; if (Math.abs(o.s - s) < 45 + o.half + car.half) return false; }
    return Math.abs(s - bike.s) > 30 || Math.abs(car.d - bike.d) > 2.2;
  }
  function respawn(car, ahead) {
    for (let tries = 0; tries < 12; tries += 1) {
      const s = ahead ? bike.s + 650 + rnd() * 450 : bike.s - 320 - rnd() * 160;
      if (s < 40 || s > L - 60) { if (ahead && s > L - 60) car.s = L - 60 - rnd() * 40; else car.s = Math.max(40, s); }
      else car.s = s;
      if (clearAt(car, car.s)) break;
      car.s += 60 * (ahead ? 1 : -1);
    }
    car.lane = pickLane(car.kind, car.dir, car.s); car.d = (car.dir > 0 ? LANES : SB_LANES)[car.lane];
    car.cruise = cruiseFor(car.kind, car.lane); car.v = car.cruise; car.lastRel = null; car.inLaneSince = 0;
  }
  function seed() {
    let k = 0;
    for (const car of cars) {
      for (let tries = 0; tries < 20; tries += 1) {
        const s = car.dir > 0 ? bike.s - 300 + (k / cars.length) * 1400 + rnd() * 60 : bike.s - 250 + rnd() * 1300;
        car.s = clamp(s, 40, L - 60);
        if (clearAt(car, car.s)) break;
        k += 0.3;
      }
      car.lane = pickLane(car.kind, car.dir, car.s);
      car.d = (car.dir > 0 ? LANES : SB_LANES)[car.lane];
      car.cruise = cruiseFor(car.kind, car.lane);
      car.v = car.cruise; k += 1;
      place(car);
    }
  }

  const _p = new THREE.Vector3();
  function place(car) {
    const f = RT.frame(car.s);
    RT.point(car.s, car.d, 0, _p);
    car.mesh.position.copy(_p);
    if (car.dir < 0) car.mesh.rotation.set(Math.atan2(f.ty, 1), Math.atan2(-f.tx, -f.tz), 0, 'YXZ');
    else car.mesh.rotation.set(-Math.atan2(f.ty, 1), Math.atan2(f.tx, f.tz), 0, 'YXZ');
    // a lane change leans the car into its move a touch
    car.mesh.rotation.y += (car.dTarget - car.d) * (car.dir > 0 ? -0.04 : 0.04);
  }
  for (const car of cars) car.dTarget = car.d;
  seed();

  // Nearest vehicle ahead of `car` in a lane (by lateral overlap), including the rider.
  function leaderIn(car, laneD, maxGap) {
    let best = null;
    for (const o of cars) {
      if (o === car || o.dir !== car.dir || !active(o)) continue;
      if (Math.abs(o.d - laneD) > LW * 0.6) continue;
      const gap = (o.s - car.s) * car.dir;
      if (gap > 0 && gap < maxGap && (!best || gap < best.gap)) best = { s: o.s, v: o.v, half: o.half, gap };
    }
    if (car.dir > 0 && Math.abs(bike.d - laneD) < LW * 0.6) {
      const gap = bike.s - car.s;
      if (gap > 0 && gap < maxGap && (!best || gap < best.gap)) best = { s: bike.s, v: bike.v, half: 1.1, gap, bike: true };
    }
    return best;
  }
  function followerIn(car, laneD, maxGap) {
    let best = null;
    for (const o of cars) {
      if (o === car || o.dir !== car.dir || !active(o)) continue;
      if (Math.abs(o.d - laneD) > LW * 0.6) continue;
      const gap = (car.s - o.s) * car.dir;
      if (gap > 0 && gap < maxGap && (!best || gap < best.gap)) best = { v: o.v, gap };
    }
    if (car.dir > 0 && Math.abs(bike.d - laneD) < LW * 0.6) {
      const gap = car.s - bike.s;
      if (gap > 0 && gap < maxGap && (!best || gap < best.gap)) best = { v: bike.v, gap, bike: true };
    }
    return best;
  }
  // lorries and coaches keep to the inside lanes, but a closure pushes
  // everything at least one lane out
  const maxLane = (car, s) => {
    const base = car.lorry || car.kind === 'coach' ? 1 : 3;
    const c = closedAt(s);
    return c >= 0 ? Math.max(base, c + 1) : base;
  };

  function update(dt) {
    const events = { collision: null, passBy: null, overtake: null, passedBy: null, tailgate: 0 };
    for (const car of cars) {
      if (!active(car)) continue;
      const dir = car.dir, lanes = dir > 0 ? LANES : SB_LANES;
      car.inLaneSince += dt; car.changeCooldown = Math.max(0, car.changeCooldown - dt);
      // the gantry limit applies to everyone on our side
      let limit = car.cruise;
      if (dir > 0) limit = Math.min(limit, RT.speedLimitAt(car.s) / 2.23694 + (car.lorry ? 0 : 1.5));
      // ease off before the finish so nothing piles into the end of the road
      if (dir > 0 && car.s > L - 120) limit = Math.min(limit, 8);
      // two-second gap to the leader in the lane we are in (or moving into)
      const lead = leaderIn(car, car.dTarget, 160);
      if (lead) {
        const need = car.v * 1.9 + lead.half + car.half + 5;
        if (lead.gap < need) limit = Math.min(limit, lead.gap < lead.half + car.half + 3 ? 0 : lead.v * ((lead.gap - lead.half - car.half - 3) / (need - lead.half - car.half - 3)));
        if (lead.bike && lead.gap < 16 && bike.v < car.cruise - 3) events.tailgate = Math.max(events.tailgate, 1 - lead.gap / 16);
      }
      // lane discipline: get out of a closed lane, move out to pass something
      // slower, and move back in when the inside lane is clear
      if (dir > 0 && Math.abs(car.d - car.dTarget) < 0.05) {
        const mustLeave = closedAt(car.s + 220) === car.lane;
        if (mustLeave && car.lane < 3) {
          const target = lanes[car.lane + 1];
          const ahead = leaderIn(car, target, car.v * 2.2 + 16), behind = followerIn(car, target, 70);
          const room = (!ahead || ahead.gap > 22 + car.half) && (!behind || behind.gap > 20 + car.half);
          if (room) { car.lane += 1; car.dTarget = target; car.changeCooldown = 3; car.inLaneSince = 0; }
          else limit = Math.min(limit, closedAt(car.s) === car.lane ? 5 : 14);  // hold back for a gap
        } else if (car.changeCooldown <= 0) {
          const blocked = lead && lead.v < car.cruise - 1.5 && lead.gap < car.v * 3.2 + 25;
          if (blocked && car.lane < maxLane(car, car.s)) {
            const target = lanes[car.lane + 1];
            const ahead = leaderIn(car, target, car.v * 2.5 + 20), behind = followerIn(car, target, 90);
            const okBehind = !behind || behind.gap > (behind.v - car.v) * 3.5 + 28;
            if (!ahead && okBehind) { car.lane += 1; car.dTarget = target; car.changeCooldown = 6; car.inLaneSince = 0; }
          } else if (!blocked && car.lane > 0 && car.inLaneSince > 5 && closedAt(car.s + 300) !== car.lane - 1) {
            const target = lanes[car.lane - 1];
            const ahead = leaderIn(car, target, car.v * 3.8 + 40), behind = followerIn(car, target, 45);
            if ((!ahead || ahead.v > car.v - 1) && !behind) { car.lane -= 1; car.dTarget = target; car.changeCooldown = 5; car.inLaneSince = 0; }
          }
        }
      }
      // cars brake harder than they accelerate
      const rate = limit < car.v - 0.5 ? 2.4 : 0.55;
      car.v = lerp(car.v, limit, 1 - Math.exp(-dt * rate));
      car.braking = lerp(car.braking, limit < car.v - 0.8 ? 1 : 0, 1 - Math.exp(-dt * 8));
      if (car.mesh.userData.tail) car.mesh.userData.tail.emissiveIntensity = 0.7 + car.braking * 2.2;
      car.d = lerp(car.d, car.dTarget, 1 - Math.exp(-dt * 0.9));
      if (Math.abs(car.d - car.dTarget) < 0.03) car.d = car.dTarget;
      car.s += dir * car.v * dt;
      // recycle around the rider on an open road
      const rel = car.s - bike.s;
      if (dir > 0 && (rel < -480 || rel > 1250)) respawn(car, rel < 0 && car.cruise < bike.v + 2 ? true : rel > 0 ? false : true);
      if (dir < 0 && (rel < -380 || rel > 1300)) { car.s = bike.s + 900 + rnd() * 380; car.lastRel = null; }
      if (car.s < 20) car.s = 20;
      place(car);
      car.mesh.visible = Math.abs(car.s - bike.s) < (coarse ? 650 : 900);
      if (dir < 0) { car.lastRel = rel; continue; }

      // passes, both ways, and contact
      const lateral = Math.abs(bike.d - car.d);
      const relNow = car.s - bike.s;
      if (car.lastRel !== null) {
        if (car.lastRel > 0 && relNow <= 0) events.overtake = { car, gap: lateral - CAR_HALF_WIDTH, closing: bike.v - car.v, side: bike.d < car.d ? 'right' : 'left' };
        if (car.lastRel < 0 && relNow >= 0) events.passedBy = { car, gap: lateral - CAR_HALF_WIDTH, side: car.d < bike.d ? 'right' : 'left' };
      }
      car.lastRel = relNow;
      if (lateral < CAR_HALF_WIDTH + 0.4 && relNow > -car.half - 0.4 && relNow < car.half + 0.3) {
        events.collision = { car, reason: relNow > 0 ? (car.lorry ? 'RAN INTO THE LORRY AHEAD' : 'RAN INTO THE CAR AHEAD') : 'CLIPPED BY TRAFFIC' };
      }
    }
    return events;
  }

  function sameAhead(metres = 60) {
    let best = null;
    for (const car of cars) {
      if (car.dir < 0 || !active(car)) continue;
      if (Math.abs(car.d - bike.d) > LW * 0.62) continue;
      const rel = car.s - bike.s;
      if (rel <= 0 || rel > metres) continue;
      if (!best || rel < best.distance) best = { car, distance: rel };
    }
    return best;
  }
  /* Is the lane at lateral `d` free of traffic within `ahead` metres in front
   * and `behind` metres back? Used by the score to judge lane hogging. */
  function laneClear(d, ahead = 70, behind = 25) {
    for (const car of cars) {
      if (car.dir < 0 || !active(car)) continue;
      if (Math.abs(car.d - d) > LW * 0.62) continue;
      const rel = car.s - bike.s;
      if (rel > -behind && rel < ahead) return false;
    }
    return true;
  }
  function setMode(next) {
    mode = next;
    for (const car of cars) { car.mesh.visible = active(car); car.lastRel = null; }
  }
  return { cars, update, setMode, oncomingAhead() { return null; }, sameAhead, laneClear, get mode() { return mode; } };
};
