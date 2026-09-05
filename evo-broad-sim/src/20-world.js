import * as THREE from 'three';

/*
 * AVENRÀ EVO · B-ROAD — world builder.
 *
 * Turns the route plan into meshes: crowned asphalt with baked wear, grass
 * verges with a ditch and bank, far pasture, hedgerows / dry-stone walls /
 * post-and-wire fences, mature trees, three coned-off side roads, UK road
 * markings, signs, cat's eyes, telegraph poles, a physically-inspired sky and
 * a sun that casts real shadows around the rider.
 */
const EVO = window.EVO;
const { clamp, lerp, smoothstep, mod } = EVO;
const RT = EVO.route;

/* -------------------------------------------------------- geometry sink */
class GeoSink {
  constructor() { this.p = []; this.n = []; this.uv = []; this.c = []; this.idx = []; this.hasNormals = false; }
  vertex(x, y, z, u, v, r = 1, g = 1, b = 1, nx, ny, nz) {
    this.p.push(x, y, z); this.uv.push(u, v); this.c.push(r, g, b);
    if (nx !== undefined) { this.n.push(nx, ny, nz); this.hasNormals = true; }
    return this.p.length / 3 - 1;
  }
  quad(a, b, c, d) { this.idx.push(a, b, c, a, c, d); }
  tri(a, b, c) { this.idx.push(a, b, c); }
  build() {
    const g = new THREE.BufferGeometry();
    g.setAttribute('position', new THREE.Float32BufferAttribute(this.p, 3));
    g.setAttribute('uv', new THREE.Float32BufferAttribute(this.uv, 2));
    g.setAttribute('color', new THREE.Float32BufferAttribute(this.c, 3));
    g.setIndex(this.idx);
    if (this.hasNormals) g.setAttribute('normal', new THREE.Float32BufferAttribute(this.n, 3));
    else g.computeVertexNormals();
    g.computeBoundingSphere();
    return g;
  }
}

/* Strip builder: rows of vertices (arrays of {x,y,z,u,v,r,g,b}) joined in sequence. */
function stripRows(sink, rows, closed = false) {
  const ids = rows.map((row) => row.map((v) => sink.vertex(v.x, v.y, v.z, v.u, v.v, v.r ?? 1, v.g ?? 1, v.b ?? 1)));
  const cols = rows[0].length;
  for (let i = 0; i < rows.length - (closed ? 0 : 1); i += 1) {
    const a = ids[i], b = ids[(i + 1) % rows.length];
    for (let c = 0; c < cols - 1; c += 1) sink.quad(a[c], b[c], b[c + 1], a[c + 1]);
  }
}

/* Reverse each row when the cross direction lies to the right of travel, so
 * every strip keeps the same up-facing winding. */
function orientRows(rows, samples) {
  if (samples.length < 2) return rows;
  const a = samples[0], b = samples[1];
  const tx = b.x - a.x, tz = b.z - a.z;
  // (t × n)·up = tz*nx - tx*nz  (>0 when n is to the left of t)
  return (tz * a.nx - tx * a.nz) >= 0 ? rows : rows.map((r) => r.slice().reverse());
}

EVO.buildWorld = function buildWorld(renderer, quality) {
  const scene = new THREE.Scene();
  const rnd = EVO.rng(4242);
  const L = RT.length;
  const T = {
    asphalt: EVO.tex.asphalt(), wear: EVO.tex.roadWear(), grass: EVO.tex.grass(), leaf: EVO.tex.hedgeLeaf(),
    hedge: EVO.tex.hedgeBody(), stone: EVO.tex.stone(), haw: EVO.tex.leafCluster('hawthorn', 37), umbel: EVO.tex.umbel(), foxglove: EVO.tex.foxglove(),
    blade: EVO.tex.blade(), cone: EVO.tex.cone()
  };

  /* ---------------------------------------------------------- materials */
  // Materials whose shaders are patched must carry a distinct program cache
  // key, otherwise three.js shares one compiled program between materials
  // with identical parameters and the second patch is silently dropped.
  EVO.tagShader = (mat, tag) => {
    mat.userData.shaderTag = (mat.userData.shaderTag || '') + tag + ';';
    mat.customProgramCacheKey = () => mat.userData.shaderTag;
  };
  const roadMat = new THREE.MeshStandardMaterial({
    map: T.asphalt.map, normalMap: T.asphalt.normalMap, normalScale: new THREE.Vector2(0.3, 0.3),
    roughnessMap: T.asphalt.roughnessMap, roughness: 0.95, metalness: 0.01, vertexColors: true, envMapIntensity: 0.16, color:0xc6c0b5
  });
  roadMat.onBeforeCompile = (shader) => {
    shader.uniforms.wearMap = { value: T.wear };
    shader.fragmentShader = shader.fragmentShader
      .replace('#include <map_pars_fragment>', '#include <map_pars_fragment>\nuniform sampler2D wearMap;')
      .replace('#include <map_fragment>', '#include <map_fragment>\n{ vec4 wear = texture2D(wearMap, vec2(vMapUv.x, vMapUv.y * 0.155)); diffuseColor.rgb *= mix(vec3(1.0), wear.rgb, 0.56); }');
  };
  EVO.tagShader(roadMat, 'roadwear');
  const grassMat = new THREE.MeshStandardMaterial({
    map: T.grass.map, normalMap: T.grass.normalMap, normalScale: new THREE.Vector2(0.24, 0.24), roughness: 1, metalness: 0, vertexColors: true
  });
  const markMat = new THREE.MeshStandardMaterial({ color: 0xdcd9cc, roughness: 0.72, metalness: 0, polygonOffset: true, polygonOffsetFactor: -2, polygonOffsetUnits: -2 });
  const hedgeMat = new THREE.MeshStandardMaterial({ map: T.hedge.map, normalMap: T.hedge.normalMap, normalScale: new THREE.Vector2(0.85, 0.85), roughness: 0.92, metalness: 0, vertexColors: true, emissive: 0x1c2c0c, emissiveIntensity: 0.15 });
  // Foliage fill: leaves in shade still carry transmitted sky light, so add a
  // map-coloured fill term rather than a flat emissive colour.
  EVO.addFoliageFill = (mat, amount = 0.2) => {
    const prev = mat.onBeforeCompile;
    mat.onBeforeCompile = (shader, r) => {
      if (prev) prev(shader, r);
      shader.fragmentShader = shader.fragmentShader.replace('#include <emissivemap_fragment>', `#include <emissivemap_fragment>\ntotalEmissiveRadiance += diffuseColor.rgb * ${amount.toFixed(3)};`);
    };
    EVO.tagShader(mat, 'fill' + amount.toFixed(3));
  };
  EVO.addFoliageFill(hedgeMat, 0.12);
  const leafMat = new THREE.MeshStandardMaterial({ map: T.haw.map, normalMap: T.haw.normalMap, normalScale: new THREE.Vector2(0.7, 0.7), alphaTest: 0.5, side: THREE.DoubleSide, roughness: 0.86, metalness: 0, emissive: 0x1c2c0c, emissiveIntensity: 0.2 });
  EVO.addFoliageFill(leafMat, 0.16);
  const umbelMat = new THREE.MeshStandardMaterial({ map: T.umbel, alphaTest: 0.4, side: THREE.DoubleSide, roughness: 0.9, emissive: 0x222218, emissiveIntensity: 0.4 });
  const stoneMat = new THREE.MeshStandardMaterial({ map: T.stone.map, normalMap: T.stone.normalMap, normalScale: new THREE.Vector2(0.9, 0.9), roughness: 0.95, metalness: 0, vertexColors: true });
  const bladeMat = new THREE.MeshStandardMaterial({ map: T.blade, alphaTest: 0.5, side: THREE.DoubleSide, roughness: 0.95, color:0xadb5a5, emissive: 0x1a2817, emissiveIntensity: 0.12 });
  const windUniform = { value: 0 };
  EVO.windUniform = windUniform;
  bladeMat.onBeforeCompile = (shader) => {
    shader.uniforms.uTime = windUniform;
    shader.vertexShader = shader.vertexShader
      .replace('#include <common>', '#include <common>\nuniform float uTime;')
      .replace('#include <begin_vertex>', '#include <begin_vertex>\n{ float ph = instanceMatrix[3][0] * 0.37 + instanceMatrix[3][2] * 0.23; float sway = sin(uTime * 1.9 + ph) * 0.5 + sin(uTime * 3.1 + ph * 1.7) * 0.25; transformed.x += sway * 0.09 * uv.y; transformed.z += sway * 0.04 * uv.y; }');
  };
  EVO.tagShader(bladeMat, 'bladewind');
  const coneMat = new THREE.MeshStandardMaterial({ map: T.cone, roughness: 0.6, metalness: 0 });
  const postMat = new THREE.MeshStandardMaterial({ color: 0x74787c, roughness: 0.55, metalness: 0.6 });
  const woodMat = new THREE.MeshStandardMaterial({ color: 0x5f4f3d, roughness: 0.95 });
  const blackMat = new THREE.MeshStandardMaterial({ color: 0x15161a, roughness: 0.6 });
  const signBackMat = new THREE.MeshStandardMaterial({ color: 0x9a9da0, roughness: 0.5, metalness: 0.5 });
  const studMat = new THREE.MeshStandardMaterial({ color: 0xd8dde0, roughness: 0.35, metalness: 0.1 });
  const barrierMat = new THREE.MeshStandardMaterial({ color: 0xe0dcd4, roughness: 0.6 });
  const barrierRedMat = new THREE.MeshStandardMaterial({ color: 0xc2201f, roughness: 0.6 });

  /* ------------------------------------------------ cloud shadows */
  // Slow cloud shadows drift over every ground surface. Non-instanced
  // materials only (the world position is taken from modelMatrix).
  const cloudUniform = { value: 0 };
  function addCloudShadow(mat) {
    const prev = mat.onBeforeCompile;
    mat.onBeforeCompile = (shader, r) => {
      if (prev) prev(shader, r);
      shader.uniforms.uCloudTime = cloudUniform;
      shader.vertexShader = shader.vertexShader
        .replace('#include <common>', '#include <common>\nvarying vec2 vCloudPos;')
        .replace('#include <project_vertex>', '#include <project_vertex>\n{ vec4 cwp = modelMatrix * vec4(transformed, 1.0); vCloudPos = cwp.xz; }');
      shader.fragmentShader = shader.fragmentShader
        .replace('#include <common>', '#include <common>\nvarying vec2 vCloudPos; uniform float uCloudTime;\nfloat cloudHash(vec2 p){ return fract(sin(dot(p, vec2(127.1, 311.7))) * 43758.5453); }\nfloat cloudNoise(vec2 p){ vec2 i = floor(p), f = fract(p); vec2 u = f * f * (3.0 - 2.0 * f); return mix(mix(cloudHash(i), cloudHash(i + vec2(1.0, 0.0)), u.x), mix(cloudHash(i + vec2(0.0, 1.0)), cloudHash(i + vec2(1.0, 1.0)), u.x), u.y); }')
        .replace('#include <dithering_fragment>', '{ vec2 cp = vCloudPos * 0.0026 + vec2(uCloudTime * 0.011, uCloudTime * 0.0035); float cn = cloudNoise(cp) * 0.6 + cloudNoise(cp * 2.1 + 7.0) * 0.4; float csh = smoothstep(0.5, 0.74, cn); gl_FragColor.rgb *= 1.0 - csh * 0.3; }\n#include <dithering_fragment>');
    };
    EVO.tagShader(mat, 'cloud');
  }

  /* ------------------------------------------------- verge height model */
  const PROFILE = [[3.1, -0.06], [3.5, -0.12], [4.2, -0.30], [5.0, -0.06], [6.0, 0.16], [7.5, 0.30], [9.5, 0.36]];
  function profile(d) {
    if (d <= PROFILE[0][0]) return PROFILE[0][1];
    for (let i = 0; i < PROFILE.length - 1; i += 1) {
      if (d <= PROFILE[i + 1][0]) return lerp(PROFILE[i][1], PROFILE[i + 1][1], (d - PROFILE[i][0]) / (PROFILE[i + 1][0] - PROFILE[i][0]));
    }
    return PROFILE[PROFILE.length - 1][1];
  }
  const _v = new THREE.Vector3();
  const groundMeshes = [];
  // Ground height at (s, signed d). Flattens across junction mouths and under side roads.
  function groundAt(s, d) {
    const f = RT.frame(s);
    const ad = Math.abs(d);
    const x = f.x + f.nx * d, z = f.z + f.nz * d;
    const roadEdge = f.y + RT.crown(RT.LANE_HALF);
    let prof = profile(ad);
    const side = d >= 0 ? 1 : -1;
    const mouth = RT.inJunctionMouth(s, side, 11);
    if (mouth) {
      const ds = Math.abs(mod(s - mouth.s + L / 2, L) - L / 2);
      prof = lerp(-0.02 * (ad - 3), prof, smoothstep(6, 11, ds));
    }
    let h = lerp(roadEdge + prof, RT.terrainHeight(x, z), smoothstep(6, 30, ad));
    const sideInf = RT.sideInfluence(x, z);
    if (sideInf && sideInf.dist < sideInf.j.halfWidth + 5.5 && sideInf.t > -1) {
      const w = smoothstep(sideInf.j.halfWidth + 0.8, sideInf.j.halfWidth + 5.5, sideInf.dist);
      h = Math.min(h, lerp(sideInf.y - 0.12, h, w));
    }
    h = RT.villageGround(s, d, h, f);
    return { x, y: h, z, f };
  }

  /* -------------------------------------------------------------- road */
  {
    const sink = new GeoSink();
    const D = [-3.1,-2.95,-2.72,-2.57,-2.42,-2.27,-2.12,-1.97,-1.8,-1.63,-1.4,-.5,.5,1.4,1.63,1.8,1.97,2.12,2.27,2.42,2.57,2.72,2.95,3.1];
    const rows = [];
    const roadSamples = new Set();
    for (let i=0;i<RT.R.n;i++) roadSamples.add(Number((i*RT.SAMPLE).toFixed(5)));
    for (const h of [...RT.detailPlan.humps,...RT.detailPlan.strips,...RT.detailPlan.potholes,...RT.detailPlan.covers.map(c=>({...c,length:.8}))]) {
      for(let s=h.s-h.length/2-.5;s<=h.s+h.length/2+.5;s+=.1)roadSamples.add(Number(s.toFixed(5)));
    }
    for (const s of [...roadSamples].sort((a,b)=>a-b)) {
      const f = RT.frame(s);
      const tint = 0.9 + (EVO.fbm(s / 41, 0.3, 2) - 0.5) * 0.28;
      rows.push(D.map((d) => ({
        x: f.x + f.nx * d, y: f.y + RT.crown(d) + RT.surfaceAt(s,d), z: f.z + f.nz * d,
        u: (d + 3.1) / 6.2, v: s / 6.2, r: tint, g: tint, b: tint * 1.01
      })));
    }
    stripRows(sink, rows, true);
    const mesh = new THREE.Mesh(sink.build(), roadMat);mesh.name='crowned carriageway';
    mesh.receiveShadow = true;
    scene.add(mesh);
  }

  /* --------------------------------------------------- verges (ribbons) */
  const grassTint = (x, z) => {
    const n = EVO.fbm(x / 60 + 7, z / 60, 2);
    return { r: 0.86 + n * 0.28, g: 0.9 + n * 0.2, b: 0.85 + n * 0.2 };
  };
  for (const side of [1, -1]) {
    const sink = new GeoSink();
    const D = [3.02, 3.5, 4.2, 5.0, 6.0, 7.5, 9.5, 12, 16, 22, 30];
    const rows = [];
    for (let i = 0; i < RT.R.n; i += 2) {
      const s = i * RT.SAMPLE;
      // Grass darkens where it meets a hedge or wall: the cheap stand-in for
      // ambient occlusion that sells the join between ground and boundary.
      const b = RT.boundaryAt(s, side);
      const solid = (b.type === 'hedge' || b.type === 'wall') && !RT.clearance(s, side) && !RT.inJunctionMouth(s, side, 8);
      rows.push(D.map((dd) => {
        const g = groundAt(s, dd * side);
        const t = grassTint(g.x, g.z);
        if (solid) {
          const ao = 1 - 0.38 * (1 - smoothstep(0, 1.25, Math.abs(dd - RT.HEDGE_OFFSET)));
          t.r *= ao; t.g *= ao; t.b *= ao;
        }
        return { x: g.x, y: g.y, z: g.z, u: g.x / 4, v: g.z / 4, ...t };
      }));
    }
    stripRows(sink, side === 1 ? rows : rows.map((r) => r.slice().reverse()), true);
    const mesh = new THREE.Mesh(sink.build(), grassMat);mesh.name='ground';groundMeshes.push(mesh);
    mesh.receiveShadow = true;
    scene.add(mesh);
  }

  /* ---------------------------------------------------- far pasture grid */
  {
    let minX = Infinity, maxX = -Infinity, minZ = Infinity, maxZ = -Infinity;
    for (let i = 0; i < RT.R.n; i += 1) { minX = Math.min(minX, RT.R.px[i]); maxX = Math.max(maxX, RT.R.px[i]); minZ = Math.min(minZ, RT.R.pz[i]); maxZ = Math.max(maxZ, RT.R.pz[i]); }
    const pad = 720, cell = 9;
    const x0 = minX - pad, z0 = minZ - pad, nx = Math.ceil((maxX - minX + pad * 2) / cell), nz = Math.ceil((maxZ - minZ + pad * 2) / cell);
    const sink = new GeoSink();
    const ids = [];
    for (let iz = 0; iz <= nz; iz += 1) {
      const row = [];
      for (let ix = 0; ix <= nx; ix += 1) {
        const x = x0 + ix * cell, z = z0 + iz * cell;
        const y = RT.terrainHeight(x, z);
        // fields: pasture variants, hay meadow, and the odd ploughed strip
        const field = EVO.fbm(x / 240 + 9, z / 240 + 2, 2);
        let r = 0.85, g = 0.92, b = 0.8;
        if (field > 0.62) { r = 1.05; g = 0.98; b = 0.7; } // hay
        else if (field < 0.36) { r = 0.78; g = 0.85; b = 0.7; }
        const t = grassTint(x, z);
        row.push(sink.vertex(x, y, z, x / 4, z / 4, r * t.r, g * t.g, b * t.b));
      }
      ids.push(row);
    }
    for (let iz = 0; iz < nz; iz += 1) for (let ix = 0; ix < nx; ix += 1) {
      const near=RT.nearest(x0+(ix+.5)*cell,z0+(iz+.5)*cell);
      if(near&&near.dist<22)continue;
      sink.quad(ids[iz][ix], ids[iz + 1][ix], ids[iz + 1][ix + 1], ids[iz][ix + 1]);
    }
    const mesh = new THREE.Mesh(sink.build(), grassMat);mesh.name='ground';groundMeshes.push(mesh);
    mesh.receiveShadow = true;
    scene.add(mesh);

    /* distant fells: a ring of big rolling hills beyond the pasture, read
     * through the haze as the Dales skyline */
    const cx = (minX + maxX) / 2, cz = (minZ + maxZ) / 2;
    const ring = new GeoSink();
    const R0 = 1120, R1 = 5600, NA = 140, NR = 12;
    const rids = [];
    for (let ir = 0; ir <= NR; ir += 1) {
      const t = ir / NR, r = R0 * Math.pow(R1 / R0, t);
      const row = [];
      for (let ia = 0; ia <= NA; ia += 1) {
        const a = ia / NA * Math.PI * 2;
        const x = cx + Math.cos(a) * r, z = cz + Math.sin(a) * r;
        const base = RT.terrainBase(x, z) * 0.85 + 0.6;
        const hill = (EVO.fbm(x / 1700 + 9, z / 1700 - 4, 4) - 0.32) * 460 + (EVO.fbm(x / 540 + 2, z / 540 + 8, 3) - 0.5) * 140;
        const w = smoothstep(R0, R0 * 2.1, r);
        const y = lerp(base, Math.max(base - 10, hill), w);
        const shade = 0.7 + EVO.fbm(x / 320, z / 320, 2) * 0.4;
        row.push(ring.vertex(x, y, z, x / 4, z / 4, 0.84 * shade, 0.9 * shade, 0.68 * shade));
      }
      rids.push(row);
    }
    for (let ir = 0; ir < NR; ir += 1) for (let ia = 0; ia < NA; ia += 1) ring.quad(rids[ir][ia], rids[ir][ia + 1], rids[ir + 1][ia + 1], rids[ir + 1][ia]);
    const fellsMat = new THREE.MeshStandardMaterial({ map: T.grass.map, roughness: 1, vertexColors: true, side: THREE.DoubleSide });
    addCloudShadow(fellsMat);
    const fells = new THREE.Mesh(ring.build(), fellsMat);
    scene.add(fells);
  }

  /* ---------------------------------------------------------- side roads */
  const sideRoadSinks = new GeoSink();
  for (const j of RT.JUNCTIONS) {
    const rows = [];
    for (let t = -0.8; t <= j.length; t += 1) {
      const flare = Math.pow(1 - smoothstep(0, 7.5, t), 2);
      const hw = j.halfWidth + 5.5 * flare;
      const cols = [-hw, -hw * 0.5, 0, hw * 0.5, hw];
      rows.push(cols.map((e) => {
        const p = RT.sidePoint(j, Math.max(t, 0), e);
        if (t < 0) { // sit on the main carriageway edge, follow its crown
          const f = RT.frame(j.s); p.y = f.y + RT.crown(RT.LANE_HALF + t) + 0.005;
        } else p.y += 0.008;
        return { x: p.x, y: p.y, z: p.z, u: e / 6.2 + 0.5, v: t / 6.2, r: 0.95, g: 0.95, b: 0.96 };
      }));
    }
    stripRows(sideRoadSinks, rows);
  }
  {
    const mesh = new THREE.Mesh(sideRoadSinks.build(), roadMat);
    mesh.receiveShadow = true;
    scene.add(mesh);
  }

  /* ------------------------------------------------------------ markings */
  const marks = new GeoSink();
  const UP = 0.012;
  function ribbon(sA, sB, dC, width, step = 1) {
    if(RT.inVillage((sA+sB)/2,40))step=Math.min(step,.2);
    const rows = [];
    for (let s = sA; s <= sB + 1e-6; s += step) {
      const f = RT.frame(Math.min(s, sB));
      const y0 = f.y + RT.crown(dC) + RT.surfaceAt(Math.min(s,sB),dC) + UP;
      rows.push([
        { x: f.x + f.nx * (dC - width / 2), y: y0, z: f.z + f.nz * (dC - width / 2), u: 0, v: 0 },
        { x: f.x + f.nx * (dC + width / 2), y: y0, z: f.z + f.nz * (dC + width / 2), u: 1, v: 0 }
      ]);
      if (s >= sB) break;
    }
    if (rows.length > 1) stripRows(marks, rows);
  }
  function junctionDistance(s) {
    let best = Infinity;
    for (const j of RT.JUNCTIONS) best = Math.min(best, Math.abs(mod(s - j.s + L / 2, L) - L / 2));
    return best;
  }
  function bendZone(s) {
    const i = Math.floor(mod(s, L) / RT.SAMPLE) % RT.R.n;
    const k = Math.abs(RT.R.kappa[i]);
    for (const b of RT.BENDS) {
      const ds = mod(s - b.start * RT.SAMPLE + L / 2, L) - L / 2;
      const de = mod(s - b.end * RT.SAMPLE + L / 2, L) - L / 2;
      if (ds > -55 && de < 55 && b.radius < 105) return { zone: b.radius < 62 ? 'double' : 'hazard', bend: b, inside: ds >= 0 && de <= 0 };
    }
    if (k > 1 / 140) return { zone: 'hazard' };
    return { zone: 'normal' };
  }
  function zoneAt(s) {
    const bz = bendZone(s);
    if (bz.zone === 'double' && bz.inside) return 'double';
    if (bz.zone !== 'normal' || junctionDistance(s) < 60) return 'hazard';
    return 'normal';
  }
  {
    // centre line
    let s = 0;
    while (s < L) {
      const zone = zoneAt(s);
      if (zone === 'double') {
        let e = s; while (e < L && zoneAt(e) === 'double') e += 1;
        ribbon(s, e, -0.1, 0.1); ribbon(s, e, 0.1, 0.1);
        s = e;
      } else {
        const markLen = zone === 'hazard' ? 6 : 4, gap = zone === 'hazard' ? 3 : 8;
        const e = Math.min(L, s + markLen);
        if (junctionDistance((s + e) / 2) > 1) ribbon(s, e, 0, 0.1);
        s = e + gap;
      }
    }
    // edge lines on hazard stretches, broken across junction mouths
    let runStart = null;
    for (let ss = 0; ss <= L; ss += 1) {
      const wanted = ss < L && zoneAt(ss) !== 'normal';
      if (wanted && runStart === null) runStart = ss;
      if (!wanted && runStart !== null) {
        for (const side of [1, -1]) {
          let a = runStart;
          for (let q = runStart; q <= ss; q += 1) {
            const mouth = RT.inJunctionMouth(q, side, 8);
            if (mouth || q === ss) { if (q - a > 2) ribbon(a, q, side * 2.85, 0.1, 2); a = q + 1; }
          }
        }
        runStart = null;
      }
    }
    // SLOW legends before tighter bends (both directions)
    const slowTex = EVO.tex.slowLegend();
    const slowMat = new THREE.MeshStandardMaterial({ map: slowTex, transparent: true, alphaTest: 0.2, roughness: 0.7, polygonOffset: true, polygonOffsetFactor: -2, polygonOffsetUnits: -2, side: THREE.DoubleSide });
    for (const b of RT.BENDS) {
      if (b.radius > 75) continue;
      for (const [sPos, lane, flip] of [[b.start * RT.SAMPLE - 48, 1.5, false], [b.end * RT.SAMPLE + 48, -1.5, true]]) {
        const f = RT.frame(sPos);
        const g = new THREE.PlaneGeometry(1.7, 5.2);
        const m = new THREE.Mesh(g, slowMat);
        m.position.set(f.x + f.nx * lane, f.y + RT.crown(lane) + RT.surfaceAt(sPos,lane) + UP, f.z + f.nz * lane);
        m.rotation.set(-Math.PI / 2, 0, 0);
        m.rotateZ(-f.heading + (flip ? Math.PI : 0));
        m.receiveShadow = true;
        scene.add(m);
      }
    }
  }
  // give way lines and triangle on each side road
  const gwTri = EVO.tex.giveWayTriangle();
  const gwMat = new THREE.MeshStandardMaterial({ map: gwTri, transparent: true, alphaTest: 0.2, roughness: 0.7, polygonOffset: true, polygonOffsetFactor: -2, polygonOffsetUnits: -2 });
  for (const j of RT.JUNCTIONS) {
    for (const tRow of [1.6, 2.1]) {
      for (let e = -2.3; e < 2.3; e += 0.9) {
        const rows = [];
        for (const tt of [tRow, tRow + 0.2]) {
          const a = RT.sidePoint(j, tt, e), b = RT.sidePoint(j, tt, Math.min(e + 0.6, 2.3));
          rows.push([{ x: a.x, y: a.y + UP + 0.008, z: a.z, u: 0, v: 0 }, { x: b.x, y: b.y + UP + 0.008, z: b.z, u: 1, v: 0 }]);
        }
        stripRows(marks, rows);
      }
    }
    const p = RT.sidePoint(j, 6.2, j.halfWidth * 0.5 + 0.2);
    const tri = new THREE.Mesh(new THREE.PlaneGeometry(1.5, 3.2), gwMat);
    tri.position.set(p.x, p.y + UP + 0.008, p.z);
    tri.rotation.set(-Math.PI / 2, 0, 0);
    tri.rotateZ(-Math.atan2(j.dx, j.dz));
    scene.add(tri);
  }
  {
    const mesh = new THREE.Mesh(marks.build(), markMat);
    mesh.receiveShadow = true;
    scene.add(mesh);
  }
  // cat's eyes down the centre
  {
    const studs = [];
    for (let s = 4.5; s < L; s += 9) {
      if (zoneAt(s) === 'double' || junctionDistance(s) < 6) continue;
      studs.push(s);
    }
    const geo = new THREE.BoxGeometry(0.12, 0.022, 0.2);
    const im = new THREE.InstancedMesh(geo, studMat, studs.length);
    const m = new THREE.Matrix4(), q = new THREE.Quaternion(), pos = new THREE.Vector3(), sc = new THREE.Vector3(1, 1, 1);
    studs.forEach((s, k) => {
      const f = RT.frame(s);
      pos.set(f.x, f.y + RT.surfaceAt(s,0) + 0.011, f.z);
      q.setFromAxisAngle(new THREE.Vector3(0, 1, 0), f.heading);
      m.compose(pos, q, sc); im.setMatrixAt(k, m);
    });
    im.castShadow = false; scene.add(im);
  }

  /* ---------------------------------------------- boundaries and fences */
  const hedgeSink = new GeoSink(), wallSink = new GeoSink();
  const cardInstances = [], underInstances = [], flowerInstances = [], foxgloveInstances = [], postInstances = [], treePlacements = [];
  const wireSegments = [];
  const hedgeHeight = (s, H) => H * (0.81 + EVO.noise2(s / 7.5, 0.5) * 0.32 + EVO.noise2(s/1.7,4.1)*.12);

  function hedgeRun(samples, H) {
    // samples: [{x,y,z,nx,nz,s}] along the run; nx,nz points AWAY from the road.
    // Texture u is measured from the start of the run: large uv values break
    // the derivative-based tangent frame the normal map relies on.
    const rows = [], s0 = samples[0].s;
    for (const p of samples) {
      const h = hedgeHeight(p.s, H);
      const bulge = 0.5 + EVO.noise2(p.s / 3.1, 0.2) * 0.25;
      const tint = 0.85 + EVO.noise2(p.s / 11, 3.3) * 0.3;
      rows.push([
        { x: p.x - p.nx * 0.75, y: p.y - 0.15, z: p.z - p.nz * 0.75, u: (p.s - s0) / 2, v: 0, r: tint * 0.6, g: tint * 0.65, b: tint * 0.56 },
        { x: p.x - p.nx * bulge, y: p.y + h * 0.55, z: p.z - p.nz * bulge, u: (p.s - s0) / 2, v: h * 0.28, r: tint, g: tint, b: tint },
        { x: p.x - p.nx * 0.28, y: p.y + h, z: p.z - p.nz * 0.28, u: (p.s - s0) / 2, v: h * 0.5, r: tint * 1.08, g: tint * 1.1, b: tint },
        { x: p.x + p.nx * 0.28, y: p.y + h, z: p.z + p.nz * 0.28, u: (p.s - s0) / 2, v: h * 0.64, r: tint * 1.08, g: tint * 1.1, b: tint },
        { x: p.x + p.nx * 0.75, y: p.y - 0.15, z: p.z + p.nz * 0.75, u: (p.s - s0) / 2, v: h * 1.1, r: tint * 0.6, g: tint * 0.65, b: tint * 0.56 }
      ]);
    }
    if (rows.length > 1) stripRows(hedgeSink, orientRows(rows, samples));
    // foliage cards: base, mid and top rows, plus brambles and nettles at the foot
    for (let k = 0; k < samples.length - 1; k += 1) {
      const p = samples[k];
      const h = hedgeHeight(p.s, H);
      for (let row = 0; row < 3; row += 1) {
        if (rnd() < 0.12) continue;
        const y = row === 0 ? p.y + 0.15 + rnd() * 0.45 : row === 1 ? p.y + 0.55 + rnd() * Math.max(0.2, h - 0.95) : p.y + h - 0.32 + rnd() * 0.32;
        const off = row === 2 ? (rnd() - 0.5) * 0.7 : -0.45 - rnd() * 0.22;
        const yaw = Math.atan2(-p.nx, -p.nz) + (rnd() - 0.5) * 1.4;
        const size = row === 2 ? 0.9 + rnd() * 0.6 : 0.55 + rnd() * 0.5;
        cardInstances.push({ x: p.x + p.nx * off + (rnd() - 0.5) * 0.3, y, z: p.z + p.nz * off + (rnd() - 0.5) * 0.3, yaw, tilt: (rnd() - 0.5) * 0.6, size, tint: 0.85 + rnd() * 0.3 });
      }
      if (k % 2 === 0 && rnd() < 0.8) {
        const off = -0.95 - rnd() * 0.5;
        underInstances.push({ x: p.x + p.nx * off, y: p.y - 0.05 + rnd() * 0.15, z: p.z + p.nz * off, yaw: rnd() * Math.PI * 2, tilt: (rnd() - 0.5) * 0.4, size: 0.45 + rnd() * 0.4, tint: 0.55 + rnd() * 0.3 });
      }
    }
  }
  function wallRun(samples, H) {
    const rows = [], s0 = samples[0].s;
    for (const p of samples) {
      const h = H * (0.94 + EVO.noise2(p.s / 5, 0.7) * 0.12);
      const tint = 0.88 + EVO.noise2(p.s / 6, 1.1) * 0.24;
      const w = 0.3;
      // the lowest courses stay damp and mossy; the coping catches the light
      const mossy = 0.55 + EVO.noise2(p.s / 9, 2.2) * 0.35;
      const base = { r: tint * (1 - 0.34 * mossy), g: tint * (1 - 0.2 * mossy), b: tint * (1 - 0.46 * mossy) };
      const knee = { r: tint * 0.94, g: tint * 0.97, b: tint * 0.9 };
      const u = (p.s - s0) / 1.2, vTop = (h + 0.2) / 1.2;
      rows.push([
        { x: p.x - p.nx * w, y: p.y - 0.2, z: p.z - p.nz * w, u, v: 0, ...base },
        { x: p.x - p.nx * w, y: p.y + 0.3, z: p.z - p.nz * w, u, v: 0.5 / 1.2, ...knee },
        { x: p.x - p.nx * w, y: p.y + h, z: p.z - p.nz * w, u, v: vTop, r: tint * 1.04, g: tint * 1.04, b: tint * 1.02 },
        { x: p.x + p.nx * w, y: p.y + h, z: p.z + p.nz * w, u, v: vTop + 0.25, r: tint * 1.04, g: tint * 1.04, b: tint * 1.02 },
        { x: p.x + p.nx * w, y: p.y + 0.3, z: p.z + p.nz * w, u, v: vTop * 2 + 0.25 - 0.5 / 1.2, ...knee },
        { x: p.x + p.nx * w, y: p.y - 0.2, z: p.z + p.nz * w, u, v: vTop * 2 + 0.25, ...base }
      ]);
    }
    if (rows.length > 1) stripRows(wallSink, orientRows(rows, samples));
  }
  function fenceRun(samples) {
    let last = null;
    for (let k = 0; k < samples.length; k += 1) {
      const p = samples[k];
      if (k % 3 === 0) {
        postInstances.push({ x: p.x, y: p.y - 0.1, z: p.z, yaw: Math.atan2(p.nx, p.nz), h: 1.15 });
        if (last) for (const hh of [0.42, 0.75, 1.05]) wireSegments.push(last.x, last.y + hh, last.z, p.x, p.y + hh, p.z);
        last = p;
      }
    }
  }
  // main-road boundaries
  for (const side of [1, -1]) {
    let run = null, runType = null, runH = 0;
    const flush = () => {
      if (!run || run.length < 2) { run = null; return; }
      if (runType === 'hedge') hedgeRun(run, runH); else if (runType === 'wall') wallRun(run, runH); else fenceRun(run);
      run = null;
    };
    for (let s = 0; s <= L; s += 1) {
      const b = RT.boundaryAt(s, side);
      const gap = RT.clearance(s,side) || RT.inJunctionMouth(s, side, b.type === 'hedge' ? 8 : 6.5) || s >= L;
      if (gap || (run && run.boundary !== b)) {
        flush();
        if (gap) continue;
      }
      const g = groundAt(s, side * RT.HEDGE_OFFSET);
      const p = { x: g.x, y: g.y, z: g.z, nx: g.f.nx * side, nz: g.f.nz * side, s };
      if (!run) { run = [p]; run.boundary = b; runType = b.type; runH = b.height; } else run.push(p);
    }
    flush();
  }
  // side-road boundaries (hedges both sides, with returns at the mouth)
  for (const j of RT.JUNCTIONS) {
    for (const e of [1, -1]) {
      const run = [];
      for (let t = 6.5; t <= j.length + 4; t += 1) {
        const off = e * (j.halfWidth + 2.0);
        const p = RT.sidePoint(j, t, off);
        const ground = RT.terrainHeight(p.x, p.z);
        p.y = Math.max(p.y - 0.1, lerp(p.y - 0.2, ground, smoothstep(14, 46, t)));
        const lx = j.dz, lz = -j.dx;
        run.push({ x: p.x, y: p.y, z: p.z, nx: lx * e, nz: lz * e, s: 5000 + t + j.s });
      }
      hedgeRun(run, 1.7);
    }
  }
  // foxgloves and campion where the road runs through the woods
  for (let s = 3; s < L; s += 2.2 + rnd() * 3.5) {
    if (!RT.woodland(s)) continue;
    const side = rnd() < 0.5 ? 1 : -1;
    if (RT.clearance(s, side) || RT.inJunctionMouth(s, side, 12)) continue;
    const g = groundAt(s, side * (3.7 + rnd() * 1.4));
    foxgloveInstances.push({ x: g.x, y: g.y - 0.04, z: g.z, yaw: rnd() * Math.PI * 2, size: 0.9 + rnd() * 0.6, tint: 0.85 + rnd() * 0.3 });
  }
  // cow parsley and hogweed along the verges
  for (let s = 3; s < L; s += 1.6 + rnd() * 2.2) {
    const side = rnd() < 0.5 ? 1 : -1;
    if (RT.inVillage(s,10) || RT.clearance(s,side) || RT.inJunctionMouth(s, side, 12)) continue;
    const g = groundAt(s, side * (3.5 + rnd() * 1.1));
    flowerInstances.push({ x: g.x, y: g.y - 0.05, z: g.z, yaw: rnd() * Math.PI * 2, size: 0.7 + rnd() * 0.6 });
  }
  // trees: roadside and hedgerow (dense), scattered field trees and copses
  const placeTree = (x, y, z, species, scale) => {
    const near=RT.nearest(x,z);
    if(near&&RT.inVillage(near.s,30)&&near.dist<19)return;
    treePlacements.push({x,y,z,species,scale,yaw:rnd()*Math.PI*2,tint:0.85+rnd()*.3});
  };
  for (let s = 8; s < L; s += 8 + rnd() * 13) {
    const side = rnd() < 0.5 ? 1 : -1;
    if (RT.inVillage(s,20) || RT.clearance(s,side) || RT.inJunctionMouth(s, side, 36)) continue;
    const b = RT.boundaryAt(s, side);
    const inLine = b.type !== 'fence' && rnd() < 0.45;
    const d = inLine ? RT.HEDGE_OFFSET + 0.3 : RT.HEDGE_OFFSET + 1.8 + rnd() * 10;
    const g = groundAt(s, side * d);
    const r = rnd();
    const species = inLine && r < 0.5 ? 'hawthorn' : r < 0.6 ? 'oak' : r < 0.85 ? 'ash' : 'oak';
    placeTree(g.x, g.y - 0.2, g.z, species, species === 'hawthorn' ? 0.8 + rnd() * 0.6 : 0.8 + rnd() * 0.5);
  }
  // Coherent woodland belts: layered near trees and understorey, with open pasture
  // between them. Extra density is authored by place, not uniform random clutter.
  for(let s=12;s<L;s+=quality.coarse?6.5:5.0) {
    if(!RT.woodland(s))continue;
    for(const side of [1,-1]) {
      if(RT.inJunctionMouth(s,side,28)||RT.clearance(s,side))continue;
      for(const layer of [0,1]) {
        const g=groundAt(s+(rnd()-.5)*4,side*(7.2+layer*7+rnd()*5));
        const r=rnd(), species=r<.38?'oak':r<.6?'ash':r<.8?'beech':'birch';
        placeTree(g.x,g.y-.15,g.z,species,species==='birch'?.75+rnd()*.35:.83+rnd()*.43);
      }
      const g=groundAt(s,side*(5.6+rnd()*2));
      if(rnd()<.52)placeTree(g.x,g.y-.18,g.z,'hawthorn',.42+rnd()*.33);
    }
  }
  // Garden trees and an irregular shelter belt behind the houses, not inside them.
  for(let s=335;s<596;s+=9.5)for(const side of [1,-1]){
    const g=groundAt(s+(rnd()-.5)*3,side*(21+rnd()*12));
    placeTree(g.x,g.y-.10,g.z,rnd()<.6?'ash':'oak',.58+rnd()*.40);
  }
  for (let k = 0; k < 265; k += 1) {
    const s = rnd() * L, side = rnd() < 0.5 ? 1 : -1, d = 30 + rnd() * 190;
    const f = RT.frame(s);
    const x = f.x + f.nx * side * d, z = f.z + f.nz * side * d;
    const near = RT.nearest(x, z);
    if (near && near.dist < 24) continue;
    placeTree(x, RT.terrainHeight(x, z) - 0.2, z, rnd() < 0.45 ? 'oak' : rnd() < 0.6 ? 'beech' : 'ash', 0.9 + rnd() * 0.6);
  }
  for (let c = 0; c < 12; c += 1) {
    const s = rnd() * L, side = rnd() < 0.5 ? 1 : -1, d = 45 + rnd() * 160;
    const f = RT.frame(s);
    const cx = f.x + f.nx * side * d, cz = f.z + f.nz * side * d;
    const n = 4 + Math.floor(rnd() * 6);
    for (let k = 0; k < n; k += 1) {
      const x = cx + (rnd() - 0.5) * 22, z = cz + (rnd() - 0.5) * 22;
      const near = RT.nearest(x, z);
      if (near && near.dist < 24) continue;
      placeTree(x, RT.terrainHeight(x, z) - 0.2, z, rnd() < 0.6 ? 'oak' : 'ash', 0.8 + rnd() * 0.6);
    }
  }
  {
    const hedge = new THREE.Mesh(hedgeSink.build(), hedgeMat); hedge.castShadow = true; hedge.receiveShadow = true; hedge.name = 'hedge'; scene.add(hedge);
    const wall = new THREE.Mesh(wallSink.build(), stoneMat); wall.castShadow = true; wall.receiveShadow = true; wall.name = 'wall'; scene.add(wall);
    // foliage cards, brambles and verge flowers
    const cardGeo = new THREE.PlaneGeometry(1, 1);
    const m = new THREE.Matrix4(), q = new THREE.Quaternion(), e = new THREE.Euler(), pos = new THREE.Vector3(), sc = new THREE.Vector3(), col = new THREE.Color();
    const instanceCards = (list, mat, tex, upright) => {
      const im = new THREE.InstancedMesh(cardGeo, mat, Math.max(1, list.length));
      list.forEach((c, k) => {
        e.set(upright ? 0 : c.tilt, c.yaw, 0, 'YXZ'); q.setFromEuler(e); pos.set(c.x, c.y + (upright ? c.size * 0.5 : 0), c.z); sc.set(c.size, c.size, c.size);
        m.compose(pos, q, sc); im.setMatrixAt(k, m);
        const t = c.tint ?? 1; col.setRGB(t, 0.55 + t * 0.45, t * 0.95); im.setColorAt(k, col);
      });
      im.castShadow = true; im.receiveShadow = true;
      im.customDepthMaterial = new THREE.MeshDepthMaterial({ depthPacking: THREE.RGBADepthPacking, map: tex, alphaTest: 0.5 });
      scene.add(im);
      return im;
    };
    instanceCards(cardInstances, leafMat, T.haw.map, false);
    instanceCards(underInstances, leafMat, T.haw.map, false);
    const flowers = instanceCards(flowerInstances.map((f) => ({ ...f, tint: 1 })), umbelMat, T.umbel, true);
    const foxMat = new THREE.MeshStandardMaterial({ map: T.foxglove, alphaTest: 0.4, side: THREE.DoubleSide, roughness: 0.9, emissive: 0x2a1424, emissiveIntensity: 0.25 });
    const foxgloves = instanceCards(foxgloveInstances, foxMat, T.foxglove, true);
    foxgloves.castShadow = false; foxgloves.name = 'foxgloves';
    flowers.castShadow = false;
    // fence posts and wire
    const postGeo = new THREE.BoxGeometry(0.09, 1.15, 0.09); postGeo.translate(0, 0.575, 0);
    const posts = new THREE.InstancedMesh(postGeo, woodMat, Math.max(1, postInstances.length));
    postInstances.forEach((p, k) => { q.setFromAxisAngle(new THREE.Vector3(0, 1, 0), p.yaw); pos.set(p.x, p.y, p.z); sc.set(1, 1, 1); m.compose(pos, q, sc); posts.setMatrixAt(k, m); });
    posts.castShadow = true; scene.add(posts);
    if (wireSegments.length) {
      const wg = new THREE.BufferGeometry(); wg.setAttribute('position', new THREE.Float32BufferAttribute(wireSegments, 3));
      scene.add(new THREE.LineSegments(wg, new THREE.LineBasicMaterial({ color: 0x4a4d50, transparent: true, opacity: 0.55 })));
    }
    EVO.vegetation.createTreeMeshes(scene, treePlacements, quality);
  }
  function mergeGeometries(list) {
    const p = [], n = [], uv = [], idx = [];
    let base = 0;
    for (const g of list) {
      const pa = g.getAttribute('position'), na = g.getAttribute('normal'), ua = g.getAttribute('uv');
      for (let i = 0; i < pa.count; i += 1) { p.push(pa.getX(i), pa.getY(i), pa.getZ(i)); n.push(na.getX(i), na.getY(i), na.getZ(i)); uv.push(ua.getX(i), ua.getY(i)); }
      const ix = g.getIndex(); for (let i = 0; i < ix.count; i += 1) idx.push(ix.getX(i) + base);
      base += pa.count;
    }
    const out = new THREE.BufferGeometry();
    out.setAttribute('position', new THREE.Float32BufferAttribute(p, 3)); out.setAttribute('normal', new THREE.Float32BufferAttribute(n, 3)); out.setAttribute('uv', new THREE.Float32BufferAttribute(uv, 2)); out.setIndex(idx);
    return out;
  }

  /* ------------------------------------------- field boundary hedgerows */
  {
    const segs = [];
    const Y = new THREE.Vector3(0, 1, 0);
    for (let k = 0; k < 36; k += 1) {
      const s = rnd() * L, side = rnd() < 0.5 ? 1 : -1, d0 = 45 + rnd() * 130;
      const f = RT.frame(s);
      const x = f.x + f.nx * side * d0, z = f.z + f.nz * side * d0;
      const ang = rnd() * Math.PI * 2, dx = Math.cos(ang), dz = Math.sin(ang);
      const len = 80 + rnd() * 240;
      for (let t = 0; t < len; t += 4.2) {
        const px = x + dx * t, pz = z + dz * t;
        const near = RT.nearest(px, pz);
        if (near && near.dist < 48) break;
        segs.push({ x: px, y: RT.terrainHeight(px, pz) - 0.25, z: pz, yaw: -ang, h: 1.2 + EVO.noise2(t / 9, k) * 0.7 });
      }
    }
    const geo = new THREE.BoxGeometry(4.4, 1, 1.5); geo.translate(0, 0.5, 0);
    const farHedgeMat = hedgeMat.clone(); farHedgeMat.vertexColors = false; EVO.addFoliageFill(farHedgeMat, 0.12);
    const Y2 = new THREE.Vector3(0, 1, 0);
    const im = new THREE.InstancedMesh(geo, farHedgeMat, Math.max(1, segs.length));
    const m = new THREE.Matrix4(), q = new THREE.Quaternion(), pos = new THREE.Vector3(), sc = new THREE.Vector3();
    segs.forEach((p, k) => { q.setFromAxisAngle(Y, p.yaw); pos.set(p.x, p.y, p.z); sc.set(1, p.h, 1); m.compose(pos, q, sc); im.setMatrixAt(k, m); });
    im.castShadow = true; scene.add(im);
  }

  /* ------------------------------------------------ sheep and far walls */
  {
    const body = new THREE.SphereGeometry(0.44, 10, 8); body.scale(1.05, 0.72, 0.64); body.translate(0, 0.66, 0);
    const head = new THREE.SphereGeometry(0.15, 8, 6); head.scale(1.3, 0.9, 0.8); head.translate(0.5, 0.66, 0);
    const legGeo = new THREE.CylinderGeometry(0.04, 0.035, 0.46, 6);
    const legs = [[0.24, 0.17], [0.24, -0.17], [-0.24, 0.17], [-0.24, -0.17]].map(([x, z]) => { const g = legGeo.clone(); g.translate(x, 0.23, z); return g; });
    const woolGeo = body, darkGeo = mergeGeometries([head, ...legs]);
    const woolMat = new THREE.MeshStandardMaterial({ color: 0xe4dfd2, roughness: 1 });
    const darkMat = new THREE.MeshStandardMaterial({ color: 0x2b2622, roughness: 0.95 });
    const flock = [];
    for (let gI = 0; gI < 34; gI += 1) {
      const s = rnd() * L, side = rnd() < 0.5 ? 1 : -1, d = 13 + rnd() * 60;
      const f = RT.frame(s);
      const gx = f.x + f.nx * side * d, gz = f.z + f.nz * side * d;
      const n = 3 + Math.floor(rnd() * 6);
      for (let k = 0; k < n; k += 1) {
        const x = gx + (rnd() - 0.5) * 14, z = gz + (rnd() - 0.5) * 14;
        const near = RT.nearest(x, z);
        if (near && (near.dist < 9 || (RT.inVillage(near.s,32) && near.dist<20))) continue;
        const sInf = RT.sideInfluence(x, z);
        if (sInf && sInf.dist < 7) continue;
        flock.push({ x, y: RT.terrainHeight(x, z) - 0.02, z, yaw: rnd() * Math.PI * 2, sc: 0.82 + rnd() * 0.25, lying: rnd() < 0.2 });
      }
    }
    const wool = new THREE.InstancedMesh(woolGeo, woolMat, Math.max(1, flock.length));
    const dark = new THREE.InstancedMesh(darkGeo, darkMat, Math.max(1, flock.length));
    const m = new THREE.Matrix4(), q = new THREE.Quaternion(), pos = new THREE.Vector3(), sc = new THREE.Vector3(), Y = new THREE.Vector3(0, 1, 0);
    flock.forEach((p, k) => {
      q.setFromAxisAngle(Y, p.yaw); pos.set(p.x, p.y - (p.lying ? 0.2 : 0), p.z); sc.set(p.sc, p.sc * (p.lying ? 0.8 : 1), p.sc);
      m.compose(pos, q, sc); wool.setMatrixAt(k, m); dark.setMatrixAt(k, m);
    });
    wool.castShadow = true; dark.castShadow = true; scene.add(wool, dark);

    // dry-stone field walls across the pasture
    const segs = [];
    for (let k = 0; k < 24; k += 1) {
      const s = rnd() * L, side = rnd() < 0.5 ? 1 : -1, d0 = 48 + rnd() * 140;
      const f = RT.frame(s);
      const x = f.x + f.nx * side * d0, z = f.z + f.nz * side * d0;
      const ang = rnd() * Math.PI * 2, dx = Math.cos(ang), dz = Math.sin(ang);
      const len = 60 + rnd() * 220;
      for (let t = 0; t < len; t += 4.2) {
        const px = x + dx * t, pz = z + dz * t;
        const near = RT.nearest(px, pz);
        if (near && near.dist < 46) break;
        segs.push({ x: px, y: RT.terrainHeight(px, pz) - 0.25, z: pz, yaw: -ang });
      }
    }
    const wallGeo = new THREE.BoxGeometry(4.4, 1.1, 0.5); wallGeo.translate(0, 0.55, 0);
    const farWallMat = stoneMat.clone(); farWallMat.vertexColors = false;
    const walls = new THREE.InstancedMesh(wallGeo, farWallMat, Math.max(1, segs.length));
    segs.forEach((p, k) => { q.setFromAxisAngle(Y, p.yaw); pos.set(p.x, p.y, p.z); sc.set(1, 1, 1); m.compose(pos, q, sc); walls.setMatrixAt(k, m); });
    walls.castShadow = true; scene.add(walls);
  }

  /* ------------------------------------------------------- grass blades */
  {
    const count = quality.blades;
    const geo = new THREE.PlaneGeometry(0.22, 0.27); geo.translate(0, 0.135, 0);
    const im = new THREE.InstancedMesh(geo, bladeMat, count);
    const m = new THREE.Matrix4(), q = new THREE.Quaternion(), pos = new THREE.Vector3(), sc = new THREE.Vector3();
    const brnd = EVO.rng(99);
    for (let k = 0; k < count; k += 1) {
      const s = brnd() * L, side = brnd() < 0.5 ? 1 : -1;
      const d = 3.15 + Math.pow(brnd(), 1.4) * 2.4;
      const g = groundAt(s, side * d);
      const size = (RT.clearance(s,side)||RT.inJunctionMouth(s,side,10))?0:0.7 + brnd() * 0.8;
      q.setFromAxisAngle(new THREE.Vector3(0, 1, 0), brnd() * Math.PI); pos.set(g.x, g.y - 0.04, g.z); sc.set(size, size, size);
      m.compose(pos, q, sc); im.setMatrixAt(k, m);
    }
    im.name='verge grass'; im.frustumCulled = false; im.receiveShadow = true;
    scene.add(im);
  }

  /* ------------------------------------------------------------- signs */
  const signGeoCache = {};
  function signPost(x, y, z, yaw, tex, w, h, mountH, double = false) {
    const key = `${w}x${h}`;
    signGeoCache[key] = signGeoCache[key] || new THREE.PlaneGeometry(w, h);
    const g = new THREE.Group();
    const face = new THREE.Mesh(signGeoCache[key], new THREE.MeshStandardMaterial({ map: tex, roughness: 0.45, metalness: 0.05, transparent: true, alphaTest: 0.3 }));
    face.position.set(0, mountH + h / 2, 0.035);
    const back = new THREE.Mesh(signGeoCache[key], signBackMat); back.position.set(0, mountH + h / 2, 0.0); back.rotation.y = Math.PI;
    const post = new THREE.Mesh(new THREE.CylinderGeometry(0.038, 0.038, mountH + h * 0.9, 8), postMat); post.position.set(double ? -w * 0.3 : 0, (mountH + h * 0.9) / 2, -0.01);
    g.add(face, back, post);
    if (double) { const p2 = post.clone(); p2.position.x = w * 0.3; g.add(p2); }
    g.position.set(x, y, z); g.rotation.y = yaw;
    g.traverse((o) => { if (o.isMesh) { o.castShadow = true; o.receiveShadow = true; } });
    scene.add(g);
    return g;
  }
  // yaw so that the face (which looks toward +z locally) faces direction (fx, fz)
  const faceYaw = (fx, fz) => Math.atan2(fx, fz);
  function vergeSign(s, side, tex, w, h, mountH, facingForward) {
    const g = groundAt(s, side * 3.95);
    const f = g.f;
    const yaw = facingForward ? faceYaw(f.tx, f.tz) : faceYaw(-f.tx, -f.tz);
    return signPost(g.x, g.y, g.z, yaw, tex, w, h, mountH, w > 1);
  }
  const texBendL = EVO.tex.signBend(1), texBendR = EVO.tex.signBend(-1);
  const texChevL = EVO.tex.signChevron(1), texChevR = EVO.tex.signChevron(-1);
  const texNSL = EVO.tex.signNSL(), texGW = EVO.tex.signGiveWay(), texClosed = EVO.tex.signRoadClosed();
  vergeSign(26, 1, texNSL, 0.75, 0.75, 1.6, false);
  vergeSign(L - 26, -1, texNSL, 0.75, 0.75, 1.6, true);
  for (const b of RT.BENDS) {
    if (b.radius > 110) continue;
    const sA = b.start * RT.SAMPLE, sB = b.end * RT.SAMPLE;
    // our direction: warning triangle before the bend on the left verge
    vergeSign(sA - 68, 1, b.dir > 0 ? texBendL : texBendR, 0.9, 0.9, 1.5, false);
    vergeSign(sB + 68, -1, b.dir > 0 ? texBendR : texBendL, 0.9, 0.9, 1.5, true);
    if (b.radius < 70) {
      const apex = b.apex * RT.SAMPLE;
      const outside = -b.dir; // outside of a left bend is the right-hand verge
      for (const off of [-11, 0, 11]) {
        const g = groundAt(apex + off, outside * 4.6);
        const f = g.f;
        // board faces back toward approaching traffic, slightly angled into the bend
        const yaw = faceYaw(-f.tx, -f.tz);
        signPost(g.x, g.y, g.z, yaw, b.dir > 0 ? texChevL : texChevR, 1.5, 0.75, 0.75, true);
      }
    }
  }
  for (const j of RT.JUNCTIONS) {
    const texJ = EVO.tex.signJunction(j.side);
    vergeSign(j.s - 92, 1, texJ, 0.9, 0.9, 1.5, false);
    vergeSign(j.s + 92, -1, EVO.tex.signJunction(-j.side), 0.9, 0.9, 1.5, true);
    // give way sign facing side-road traffic approaching the junction
    const gw = RT.sidePoint(j, 3.4, j.halfWidth + 1.1);
    signPost(gw.x, gw.y - 0.05, gw.z, faceYaw(j.dx, j.dz), texGW, 0.75, 0.75, 1.5);
    // road closed board on a striped barrier frame, with cones across the mouth
    const board = RT.sidePoint(j, 5.2, 0);
    const bg = new THREE.Group();
    const rail = new THREE.Mesh(new THREE.BoxGeometry(2.2, 0.08, 0.08), barrierRedMat); rail.position.y = 1.0;
    const rail2 = new THREE.Mesh(new THREE.BoxGeometry(2.2, 0.08, 0.08), barrierMat); rail2.position.y = 0.55;
    for (const lx of [-1.0, 1.0]) {
      const leg = new THREE.Mesh(new THREE.BoxGeometry(0.07, 1.15, 0.07), barrierMat); leg.position.set(lx, 0.575, 0);
      const foot = new THREE.Mesh(new THREE.BoxGeometry(0.1, 0.05, 0.6), blackMat); foot.position.set(lx, 0.025, 0);
      bg.add(leg, foot);
    }
    const face = new THREE.Mesh(new THREE.PlaneGeometry(1.2, 0.45), new THREE.MeshStandardMaterial({ map: texClosed, roughness: 0.45 }));
    face.position.set(0, 1.28, 0.05);
    const faceBack = new THREE.Mesh(new THREE.PlaneGeometry(1.2, 0.45), signBackMat); faceBack.position.set(0, 1.28, 0.0); faceBack.rotation.y = Math.PI;
    const stem = new THREE.Mesh(new THREE.BoxGeometry(0.06, 0.5, 0.06), postMat); stem.position.set(0, 1.05, 0);
    bg.add(rail, rail2, face, faceBack, stem);
    bg.position.set(board.x, board.y, board.z);
    bg.rotation.y = faceYaw(-j.dx, -j.dz);
    bg.traverse((o) => { if (o.isMesh) { o.castShadow = true; o.receiveShadow = true; } });
    scene.add(bg);
    // fingerpost-style name plate on the verge at the mouth
    const np = RT.sidePoint(j, 3.0, -(j.halfWidth + 1.4));
    const plate = signPost(np.x, np.y - 0.05, np.z, faceYaw(-j.frame.tx, -j.frame.tz), EVO.tex.signNamePlate(j.name), 1.1, 0.34, 1.9);
    plate.rotation.y += j.side * 0.35;
  }
  // cones
  {
    const coneList = [];
    for (const j of RT.JUNCTIONS) for (let e = -2.25; e <= 2.26; e += 0.75) coneList.push(RT.sidePoint(j, 4.0, e + (rnd() - 0.5) * 0.12));
    const cg = new THREE.LatheGeometry([new THREE.Vector2(0.06, 0.75), new THREE.Vector2(0.18, 0.05), new THREE.Vector2(0.18, 0.0)], 14);
    const im = new THREE.InstancedMesh(cg, coneMat, coneList.length);
    const baseGeo = new THREE.BoxGeometry(0.4, 0.035, 0.4);
    const bases = new THREE.InstancedMesh(baseGeo, blackMat, coneList.length);
    const m = new THREE.Matrix4(), q = new THREE.Quaternion(), sc = new THREE.Vector3(1, 1, 1);
    coneList.forEach((p, k) => {
      q.setFromAxisAngle(new THREE.Vector3(0, 1, 0), rnd() * Math.PI); m.compose(p, q, sc); im.setMatrixAt(k, m);
      m.compose(p.clone().setY(p.y + 0.017), q, sc); bases.setMatrixAt(k, m);
    });
    im.castShadow = true; bases.castShadow = true; scene.add(im, bases);
  }
  // telegraph poles down the right-hand side
  {
    const poleGeo = new THREE.CylinderGeometry(0.1, 0.15, 7.2, 8); poleGeo.translate(0, 3.6, 0);
    const barGeo = new THREE.BoxGeometry(1.2, 0.08, 0.08); barGeo.translate(0, 6.6, 0);
    const poles = [];
    for (let s = 60; s < L - 40; s += 105 + rnd() * 30) {
      if (RT.inJunctionMouth(s, -1, 20)) continue;
      const g = groundAt(s, -(RT.HEDGE_OFFSET + 1.1));
      poles.push({ x: g.x, y: g.y - 0.3, z: g.z, yaw: g.f.heading });
    }
    const pm = new THREE.InstancedMesh(poleGeo, woodMat, poles.length), bm = new THREE.InstancedMesh(barGeo, woodMat, poles.length);
    const m = new THREE.Matrix4(), q = new THREE.Quaternion(), pos = new THREE.Vector3(), sc = new THREE.Vector3(1, 1, 1);
    const wires = [];
    poles.forEach((p, k) => {
      q.setFromAxisAngle(new THREE.Vector3(0, 1, 0), p.yaw); pos.set(p.x, p.y, p.z); m.compose(pos, q, sc); pm.setMatrixAt(k, m); bm.setMatrixAt(k, m);
      if (k > 0) {
        const a = poles[k - 1];
        for (const off of [-0.5, 0.5]) {
          const ox = Math.cos(p.yaw) * off, oz = -Math.sin(p.yaw) * off;
          // sag the wire in 6 segments
          let lx = a.x + ox, ly = a.y + 6.6, lz = a.z + oz;
          for (let t = 1; t <= 6; t += 1) {
            const u = t / 6, x = lerp(a.x, p.x, u) + ox, z = lerp(a.z, p.z, u) + oz, y = lerp(a.y, p.y, u) + 6.6 - Math.sin(u * Math.PI) * 1.1;
            wires.push(lx, ly, lz, x, y, z); lx = x; ly = y; lz = z;
          }
        }
      }
    });
    pm.castShadow = true; scene.add(pm, bm);
    const wg = new THREE.BufferGeometry(); wg.setAttribute('position', new THREE.Float32BufferAttribute(wires, 3));
    scene.add(new THREE.LineSegments(wg, new THREE.LineBasicMaterial({ color: 0x3a3d40, transparent: true, opacity: 0.6 })));
  }

  /* ------------------------------------------------------- sky and sun */
  const sunDir = new THREE.Vector3(-0.62, 0.50, 0.60).normalize();
  const skyUniforms = {
    sunDir: { value: sunDir }, uTime: { value: 0 },
    uZenith: { value: new THREE.Color(0.07, 0.20, 0.58) }, uHorizon: { value: new THREE.Color(0.50, 0.58, 0.72) },
    uGlow: { value: 0.45 }, uDisc: { value: 16 }, uCover: { value: 0 },
    uCloudDark: { value: new THREE.Color(0.38, 0.41, 0.48) }, uCloudLight: { value: new THREE.Color(0.92, 0.90, 0.87) }
  };
  const skyMat = new THREE.ShaderMaterial({
    uniforms: skyUniforms, side: THREE.BackSide, depthWrite: false, fog: false,
    vertexShader: `varying vec3 vDir; void main(){ vDir = position; gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0); gl_Position.z = gl_Position.w; }`,
    fragmentShader: `
      precision highp float;
      varying vec3 vDir; uniform vec3 sunDir; uniform float uTime;
      uniform vec3 uZenith, uHorizon, uCloudDark, uCloudLight; uniform float uGlow, uDisc, uCover;
      float hash(vec2 p){ return fract(sin(dot(p, vec2(127.1, 311.7))) * 43758.5453); }
      float noise(vec2 p){ vec2 i = floor(p), f = fract(p); vec2 u = f*f*(3.0-2.0*f);
        return mix(mix(hash(i), hash(i+vec2(1.0,0.0)), u.x), mix(hash(i+vec2(0.0,1.0)), hash(i+vec2(1.0,1.0)), u.x), u.y); }
      float fbm(vec2 p){ float a = 0.5, s = 0.0; for (int i = 0; i < 5; i++) { s += a * noise(p); p = p * 2.03 + 17.0; a *= 0.5; } return s; }
      void main(){
        vec3 d = normalize(vDir);
        float h = max(d.y, 0.0);
        float mu = max(dot(d, sunDir), 0.0);
        vec3 zenith = uZenith, horizon = uHorizon;
        vec3 sky = mix(horizon, zenith, pow(h, 0.6));
        // warm forward scatter around the sun, strongest low in the sky
        sky += vec3(0.95, 0.62, 0.32) * pow(mu, 5.0) * (1.0 - h) * uGlow;
        sky += vec3(1.0, 0.94, 0.82) * pow(mu, 48.0) * (0.35 + uGlow * 0.45);
        sky += vec3(1.0, 0.96, 0.9) * smoothstep(0.99935, 0.99975, dot(d, sunDir)) * uDisc;
        if (d.y > 0.015) {
          vec2 uv = d.xz / (d.y + 0.08) * 1.35 + vec2(uTime * 0.0035, uTime * 0.0012);
          float c = fbm(uv);
          float cov = smoothstep(0.50 - uCover, 0.70 - uCover * 0.7, c);
          float shade = fbm(uv * 1.9 + 5.0);
          // lit tops face the sun, bases sit in their own shadow
          float lit = smoothstep(0.2, 0.9, shade) * (0.55 + 0.45 * mu);
          vec3 cloud = mix(uCloudDark, uCloudLight, lit);
          cloud += vec3(0.35, 0.22, 0.12) * pow(mu, 3.0) * uGlow * 0.9;
          // thin cirrus veil above the cumulus
          float cirrus = smoothstep(0.55, 0.8, fbm(uv * 0.45 + vec2(31.0, 7.0) + uTime * 0.0006)) * smoothstep(0.1, 0.5, d.y);
          sky = mix(sky, mix(sky, uCloudLight, 0.35), cirrus * 0.5);
          float fade = smoothstep(0.015, 0.16, d.y);
          sky = mix(sky, cloud, cov * fade * 0.92);
        }
        if (d.y < 0.0) sky = mix(horizon, vec3(0.42, 0.47, 0.42), smoothstep(0.0, -0.06, d.y));
        gl_FragColor = vec4(sky, 1.0);
        #include <tonemapping_fragment>
        #include <colorspace_fragment>
      }`
  });
  const sky = new THREE.Mesh(new THREE.SphereGeometry(2600, 40, 20), skyMat);
  sky.frustumCulled = false;
  scene.add(sky);
  scene.fog = new THREE.FogExp2(0xb9c4cf, 0.0009);

  const sun = new THREE.DirectionalLight(0xfff4e5, 2.45);
  sun.castShadow = true;
  sun.shadow.mapSize.set(quality.shadow, quality.shadow);
  sun.shadow.camera.near = 1; sun.shadow.camera.far = 480;
  const ext = 62;
  sun.shadow.camera.left = -ext; sun.shadow.camera.right = ext; sun.shadow.camera.top = ext; sun.shadow.camera.bottom = -ext;
  sun.shadow.bias = -0.0006; sun.shadow.normalBias = 0.35; sun.shadow.radius = 2;
  sun.shadow.camera.updateProjectionMatrix();
  scene.add(sun); scene.add(sun.target);
  const hemi = new THREE.HemisphereLight(0xc7d0d4, 0x646754, 1.95);
  scene.add(hemi);
  const ambient = new THREE.AmbientLight(0xc9d2d0, 0.18);
  scene.add(ambient);

  /* Lighting presets. Sun direction drives the shadows and the sky's forward
   * scatter; the rest is the balance of direct, sky and fog light, and how wet
   * the tarmac reads. Everything here is a live uniform or light property, so a
   * preset can change between rides without rebuilding the world. */
  const PRESETS = {
    noon: { sun: [-0.62, 0.50, 0.60], sunColor: 0xfff4e5, sunI: 2.9, hemi: [0xbfcbd8, 0x5f6a4c, 1.95], ambient: 0.05, fog: [0xb9c4cf, 0.0009],
      zenith: [0.07, 0.20, 0.58], horizon: [0.50, 0.58, 0.72], glow: 0.45, disc: 16, cover: 0, cloud: [[0.38, 0.41, 0.48], [0.92, 0.90, 0.87]], wet: 0, radius: 2, exposure: 1.42 },
    morning: { sun: [0.58, 0.33, 0.74], sunColor: 0xffe6c4, sunI: 2.5, hemi: [0xc9d3dc, 0x66705a, 1.8], ambient: 0.06, fog: [0xc9d0d6, 0.00095],
      zenith: [0.16, 0.28, 0.54], horizon: [0.62, 0.66, 0.71], glow: 0.6, disc: 12, cover: -0.12, cloud: [[0.48, 0.50, 0.56], [0.96, 0.95, 0.92]], wet: 0.4, radius: 2.5, exposure: 1.38 },
    evening: { sun: [-0.74, 0.30, -0.60], sunColor: 0xffc27c, sunI: 2.9, hemi: [0xb3a6be, 0x6a5641, 2.2], ambient: 0.18, fog: [0xd2b89c, 0.00075],
      zenith: [0.10, 0.16, 0.42], horizon: [0.66, 0.44, 0.28], glow: 1.0, disc: 20, cover: 0.04, cloud: [[0.44, 0.32, 0.37], [1.0, 0.80, 0.60]], wet: 0.15, radius: 2, exposure: 1.5 },
    overcast: { sun: [-0.5, 0.62, 0.61], sunColor: 0xe8edf3, sunI: 0.75, hemi: [0xc3cad2, 0x6b7064, 2.4], ambient: 0.06, fog: [0xb6bcc2, 0.0017],
      zenith: [0.42, 0.46, 0.52], horizon: [0.60, 0.63, 0.66], glow: 0.08, disc: 2, cover: 0.32, cloud: [[0.44, 0.46, 0.49], [0.74, 0.75, 0.76]], wet: 0.85, radius: 7, exposure: 1.45 }
  };
  let lightingName = 'noon';
  function setLighting(name) {
    const P = PRESETS[name] || PRESETS.noon;
    lightingName = PRESETS[name] ? name : 'noon';
    sunDir.set(P.sun[0], P.sun[1], P.sun[2]).normalize();
    sun.color.set(P.sunColor); sun.intensity = P.sunI; sun.shadow.radius = P.radius;
    hemi.color.set(P.hemi[0]); hemi.groundColor.set(P.hemi[1]); hemi.intensity = P.hemi[2];
    ambient.intensity = P.ambient;
    scene.fog.color.set(P.fog[0]); scene.fog.density = P.fog[1];
    skyUniforms.uZenith.value.setRGB(...P.zenith); skyUniforms.uHorizon.value.setRGB(...P.horizon);
    skyUniforms.uGlow.value = P.glow; skyUniforms.uDisc.value = P.disc; skyUniforms.uCover.value = P.cover;
    skyUniforms.uCloudDark.value.setRGB(...P.cloud[0]); skyUniforms.uCloudLight.value.setRGB(...P.cloud[1]);
    // wet tarmac: smoother, and it mirrors the sky
    roadMat.roughness = 0.95 - P.wet * 0.5; roadMat.envMapIntensity = 0.16 + P.wet * 1.2;
    for (const m of [grassMat, hedgeMat]) m.color.setScalar(1 - P.wet * 0.12);
    return P;
  }

  const _bike = new THREE.Vector3();
  function update(time, bikePos, forward) {
    windUniform.value = time;
    cloudUniform.value = time;
    skyUniforms.uTime.value = time;
    sky.position.copy(bikePos);
    // shadow map centred ahead of the rider, where the detail is looked at
    _bike.copy(bikePos);
    if (forward) _bike.addScaledVector(forward, 30);
    sun.target.position.copy(_bike);
    sun.position.copy(_bike).addScaledVector(sunDir, 240);
  }

  for (const mat of [roadMat, grassMat, hedgeMat, stoneMat, markMat]) addCloudShadow(mat);
  return { scene, sun, sunDir, sky, update, groundAt, groundMeshes, signPost, vergeSign, textures:T, setLighting, get lightingName() { return lightingName; }, presets: PRESETS, materials: { roadMat, grassMat, hedgeMat, stoneMat, markMat } };
};
