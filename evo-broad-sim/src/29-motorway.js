import * as THREE from 'three';
/*
 * AVENRÀ EVO — M1 smart motorway world.
 *
 * Builds the dual carriageway route: two carriageways with a concrete step
 * barrier between them, lane lines and studs, diverge and merge tapers with
 * hatched noses, roundabout interchanges on overbridges, portal gantries with
 * lane signals and message signs, advance direction signs, driver location
 * signs, emergency refuge areas, two service areas, and the flat arable
 * Bedfordshire and Buckinghamshire farmland either side. Everything static is
 * batched into 400 m chunks along the road and switched on by distance, so a
 * 22 km road costs no more per frame than a 2 km loop.
 */
const EVO = window.EVO;
const { clamp, lerp, smoothstep } = EVO;
const RT = EVO.route;

EVO.buildMotorway = function buildMotorway({ scene, renderer, quality, T, rnd, L, GeoSink, stripRows, addCloudShadow, materials }) {
  const M = EVO.ROUTE.m1, D = RT.DUAL, ROAD_HALF = RT.ROAD_HALF, LANE_HALF = RT.LANE_HALF, LANE_W = D.laneW;
  const { roadMat, grassMat, hedgeMat, markMat, leafMat, postMat, woodMat, blackMat, signBackMat, studMat } = materials;
  const coarse = quality.coarse;
  const CHUNK = 400;
  const UP = new THREE.Vector3(0, 1, 0);
  const SB0 = D.reserveFar, SB1 = D.farEdge;          // southbound carriageway edges (+d = left)
  const RES = (D.rightEdge + D.reserveFar) / 2;        // centre of the reserve
  const LANES = [LANE_W * 1.5, LANE_W * 0.5, -LANE_W * 0.5, -LANE_W * 1.5]; // lane centres, lane 1 first
  const SB_LANES = LANES.map((d) => SB0 - LANE_W * 0.5 - (LANE_W * 1.5 - d)); // mirrored, their lane 1 nearest their verge

  /* ---------------------------------------------------------- materials */
  const mat = (name, color, extra = {}) => { const m = new THREE.MeshStandardMaterial({ name, color, roughness: 0.9, metalness: 0, ...extra }); return m; };
  const concreteMat = mat('concrete barrier', 0xa9a79f, { roughness: 0.92 });
  const steelMat = mat('steel barrier', 0xa2a6ab, { roughness: 0.42, metalness: 0.65 });
  const gantryMat = mat('gantry steel', 0x676b70, { roughness: 0.55, metalness: 0.5 });
  const signalMat = mat('signal housing', 0x121417, { roughness: 0.6 });
  const eraMat = mat('refuge surface', 0xc86f2a, { roughness: 0.96 });
  const reserveMat = mat('reserve surface', 0x4e4d4a, { roughness: 0.97 });
  const slabMat = mat('car park', 0x7e7d79, { roughness: 0.95 });
  const timberMat = mat('noise fence', 0x6e5a43, { roughness: 0.95 });
  const shedMat = mat('warehouse cladding', 0xdadcdb, { roughness: 0.6, metalness: 0.15 });
  const shedBandMat = mat('warehouse band', 0x1f4f8f, { roughness: 0.6, metalness: 0.15 });
  const glassMat = mat('services glazing', 0x1a2a33, { roughness: 0.15, metalness: 0.4 });
  const renderMat = mat('services render', 0xd6d0c2, { roughness: 0.85 });
  const canopyMat = mat('fuel canopy', 0xe8e9ea, { roughness: 0.5, metalness: 0.2, emissive: 0x9a9a96, emissiveIntensity: 0.35 });
  const lampMat = mat('lighting column', 0x8d9296, { roughness: 0.5, metalness: 0.6 });
  const pylonMat = mat('pylon steel', 0x6f7377, { roughness: 0.6, metalness: 0.5 });
  const parapetMat = mat('bridge parapet', 0xa7a9a6, { roughness: 0.85, side: THREE.DoubleSide });
  const deckMat = mat('bridge deck', 0x8f8d88, { roughness: 0.9 });
  const bankMat = new THREE.MeshStandardMaterial({ name: 'earth bank', map: T.grass.map, normalMap: T.grass.normalMap, normalScale: new THREE.Vector2(0.2, 0.2), roughness: 1, vertexColors: true });
  // Arable fields: a neutral drilled-soil tile so the vertex colour carries the crop
  const fieldTex = (() => {
    const c = document.createElement('canvas'); c.width = 512; c.height = 512;
    const ctx = c.getContext('2d'), img = ctx.createImageData(512, 512), d = img.data;
    for (let y = 0; y < 512; y += 1) for (let x = 0; x < 512; x += 1) {
      const rows = 0.5 + 0.5 * Math.sin(x / 512 * Math.PI * 2 * 14 + EVO.noise2(x / 40, y / 40) * 2);
      const n = EVO.fbm(x / 64 + 3, y / 64 + 1, 3);
      const v = 0.56 + (n - 0.5) * 0.28 + (rows - 0.5) * 0.12;
      const i = (y * 512 + x) * 4; d[i] = v * 255; d[i + 1] = v * 250; d[i + 2] = v * 236; d[i + 3] = 255;
    }
    ctx.putImageData(img, 0, 0);
    const t = new THREE.CanvasTexture(c); t.colorSpace = THREE.SRGBColorSpace; t.wrapS = t.wrapT = THREE.RepeatWrapping; t.anisotropy = Math.min(8, renderer.capabilities.getMaxAnisotropy()); return t;
  })();
  const fieldMat = new THREE.MeshStandardMaterial({ name: 'arable field', map: fieldTex, roughness: 1, vertexColors: true });
  for (const m of [concreteMat, reserveMat, slabMat, eraMat, bankMat, fieldMat]) addCloudShadow(m);

  /* ------------------------------------------------- frame helpers */
  // Frame that carries on straight beyond either end, so the services and
  // the carriageway can run past the start and the finish.
  const _f = {};
  function fr(s, out = _f) {
    if (s >= 0 && s <= L) return RT.frame(s, out);
    const s0 = s < 0 ? 0 : L;
    const f = RT.frame(s0, out), ds = s - s0;
    f.x += f.tx * ds; f.z += f.tz * ds; f.y += f.ty * ds; f.s = s; f.kappa = 0;
    return f;
  }
  function P(s, d, up = 0, out = new THREE.Vector3()) { const f = fr(s); return out.set(f.x + f.nx * d, f.y + RT.crown(d) + up, f.z + f.nz * d); }
  const land = (x, z) => RT.terrainBase(x, z) * 0.85 + 0.6;

  /* ------------------------------------------- plan: lane extensions */
  // Where the left edge of the carriageway moves out: merge and diverge
  // auxiliary lanes (with their tapers) and emergency refuge areas.
  const exits = M.exits.map((e) => ({ ...e, ring: e.s + 450 }));
  const SV = M.services;
  const startMerge = { nose: 360, aux: [430, 640], taper: [640, 720] };
  const endDiverge = { taper: [L - 720, L - 620], aux: [L - 620, L - 330], nose: L - 330 };
  const ease = (t) => { t = clamp(t, 0, 1); return t * t * (3 - 2 * t); };
  const AUX_W = 4.5, ERA_W = 4.6, ERA_L = 100;
  const SLIP_D = ROAD_HALF + 2.3;   // centre of a slip lane while it runs alongside
  const SLIP_HALF = 2.3;
  // the services slips: where their centre line sits while the rider can still be on them
  const startSlipD = (s) => (s < startMerge.nose ? lerp(30, SLIP_D + 4, ease((s - 120) / (startMerge.nose - 120))) : lerp(SLIP_D + 4, SLIP_D, ease((s - startMerge.nose) / (startMerge.aux[0] - startMerge.nose))));
  const endSlipD = (s) => (s < endDiverge.nose + 80 ? lerp(SLIP_D, SLIP_D + 4, ease((s - endDiverge.nose) / 80)) : lerp(SLIP_D + 4, 30, ease((s - endDiverge.nose - 80) / (L + 40 - endDiverge.nose - 80))));
  const extensions = []; // { a, b, w, kind, taperIn, taperOut, widthAt? }
  extensions.push({ a: 120, b: startMerge.taper[1], w: AUX_W, kind: 'merge', taperIn: 0, taperOut: 80, widthAt: (s) => (s < startMerge.aux[0] ? startSlipD(s) + SLIP_HALF + 0.6 - ROAD_HALF : AUX_W) });
  for (const e of exits) {
    // the diverge keeps its width through the hatched nose, then the slip is its own road
    extensions.push({ a: e.s - 350, b: e.s + 110, w: AUX_W, kind: 'diverge', taperIn: 100, taperOut: 0, widthAt: (s) => (s < e.s ? AUX_W : AUX_W + 5.5 * (s - e.s) / 110) });
    extensions.push({ a: e.s + 950, b: e.s + 1280, w: AUX_W, kind: 'merge', taperIn: 0, taperOut: 80 });
  }
  extensions.push({ a: endDiverge.taper[0], b: L + 40, w: AUX_W, kind: 'diverge', taperIn: 100, taperOut: 0, widthAt: (s) => (s < endDiverge.nose ? AUX_W : endSlipD(s) + SLIP_HALF + 0.6 - ROAD_HALF) });
  for (const s of M.refuges) extensions.push({ a: s - ERA_L / 2 - 30, b: s + ERA_L / 2 + 30, w: ERA_W, kind: 'refuge', taperIn: 30, taperOut: 30 });
  function auxWidth(s) {
    let w = 0;
    for (const x of extensions) {
      if (s < x.a || s > x.b) continue;
      let k = 1;
      if (x.taperIn && s < x.a + x.taperIn) k = (s - x.a) / x.taperIn;
      if (x.taperOut && s > x.b - x.taperOut) k = (x.b - s) / x.taperOut;
      w = Math.max(w, (x.widthAt ? x.widthAt(s) : x.w) * clamp(k, 0, 1));
    }
    return w;
  }
  const extent = { left: ROAD_HALF, right: D.rightEdge };
  function roadExtent(s) { extent.left = ROAD_HALF + auxWidth(s); return extent; }
  RT.roadExtent = roadExtent;
  RT.auxWidth = auxWidth;
  RT.laneCentres = LANES;
  RT.runEnd = L - 40;
  RT.edgeReason = (s, d) => (d < 0 ? 'INTO THE CENTRAL BARRIER' : exits.some((e) => s > e.s && s < e.s + 500) ? 'LEFT THE M1 · TOOK THE EXIT' : 'OFF THE CARRIAGEWAY · INTO THE BARRIER');
  RT.finishNose = endDiverge.nose;

  /* ------------------------------------------------------ ground */
  // The services car parks are flat slabs beside the road; everything else is
  // the verge stepping down off the hard strip and blending into the land.
  const parks = [
    { s0: 20, s1: 340, d0: 40, d1: 166, y: null, name: SV.start.name },
    { s0: L - 120, s1: L + 250, d0: 40, d1: 166, y: null, name: SV.end.name }
  ];
  for (const p of parks) { const f = fr((p.s0 + p.s1) / 2); p.y = f.y + RT.crown(ROAD_HALF) + 0.35; }
  function parkAt(s, d, margin = 0) { for (const p of parks) if (s >= p.s0 - 12 - margin && s <= p.s1 + 12 + margin && d >= p.d0 - 8 - margin && d <= p.d1 + 8 + margin) return p; return null; }
  const gf = {};
  function groundAt(s, d) {
    const f = fr(s, gf);
    const x = f.x + f.nx * d, z = f.z + f.nz * d;
    const left = ROAD_HALF + auxWidth(s);
    let y;
    if (d <= left && d >= SB1) y = f.y + RT.crown(d);
    else {
      const dd = d > 0 ? d - left : SB1 - d;
      const edgeY = f.y + RT.crown(d > 0 ? left : SB1);
      const verge = edgeY - 0.1 - 0.35 * smoothstep(0, 2.2, dd);
      const base = land(x, z);
      y = lerp(verge, base, smoothstep(3, 24, dd));
      const park = d > 0 ? parkAt(s, d) : null;
      if (park) {
        const inside = smoothstep(0, 8, Math.min(s - park.s0 + 12, park.s1 + 12 - s)) * smoothstep(0, 8, Math.min(d - park.d0 + 8, park.d1 + 8 - d));
        y = lerp(y, park.y, inside);
      }
    }
    return { x, y, z, f };
  }
  const groundMeshes = [];
  const VERGE_D = [ROAD_HALF, ROAD_HALF + 1.2, ROAD_HALF + 3, ROAD_HALF + 6, ROAD_HALF + 10, ROAD_HALF + 15, ROAD_HALF + 21];

  /* ------------------------------------------------- chunked batching */
  // One mesh per material per 400 m of road. Meshes carry the road distance of
  // their chunk and a viewing range; the update switches them by distance.
  const chunked = [];
  class Batch {
    constructor() { this.groups = new Map(); }
    key(material, s) { return `${material.uuid}:${Math.floor(s / CHUNK)}`; }
    group(material, s, range) {
      const k = this.key(material, s);
      if (!this.groups.has(k)) this.groups.set(k, { material, chunk: Math.floor(s / CHUNK), range, p: [], n: [], uv: [], c: [], ix: [], count: 0, hasN: true, hasC: false });
      const g = this.groups.get(k); g.range = Math.max(g.range, range);
      return g;
    }
    // rows: arrays of {x,y,z,u,v,r,g,b}
    strip(material, s, rows, range = 1800) {
      const g = this.group(material, s, range);
      const cols = rows[0].length, base = g.count;
      for (const row of rows) for (const v of row) { g.p.push(v.x, v.y, v.z); g.n.push(0, 1, 0); g.uv.push(v.u ?? 0, v.v ?? 0); g.c.push(v.r ?? 1, v.g ?? 1, v.b ?? 1); if (v.r !== undefined) g.hasC = true; }
      g.hasN = false;
      for (let i = 0; i < rows.length - 1; i += 1) for (let c = 0; c < cols - 1; c += 1) {
        const a = base + i * cols + c, b = base + (i + 1) * cols + c;
        g.ix.push(a, b, b + 1, a, b + 1, a + 1);
      }
      g.count += rows.length * cols;
    }
    geometry(material, s, geo, matrix, range = 1200, color) {
      const g = this.group(material, s, range);
      const pa = geo.attributes.position, na = geo.attributes.normal, ua = geo.attributes.uv;
      const nm = new THREE.Matrix3().getNormalMatrix(matrix), p = new THREE.Vector3(), n = new THREE.Vector3();
      for (let i = 0; i < pa.count; i += 1) {
        p.fromBufferAttribute(pa, i).applyMatrix4(matrix); n.fromBufferAttribute(na, i).applyMatrix3(nm).normalize();
        g.p.push(p.x, p.y, p.z); g.n.push(n.x, n.y, n.z); g.uv.push(ua ? ua.getX(i) : 0, ua ? ua.getY(i) : 0);
        if (color) { g.c.push(color.r, color.g, color.b); g.hasC = true; } else g.c.push(1, 1, 1);
      }
      const idx = geo.index;
      if (idx) for (let i = 0; i < idx.count; i += 1) g.ix.push(g.count + idx.getX(i)); else for (let i = 0; i < pa.count; i += 1) g.ix.push(g.count + i);
      g.count += pa.count;
    }
    finish(shadow = true) {
      const out = [];
      for (const g of this.groups.values()) {
        if (!g.count) continue;
        const geo = new THREE.BufferGeometry();
        geo.setAttribute('position', new THREE.Float32BufferAttribute(g.p, 3));
        geo.setAttribute('uv', new THREE.Float32BufferAttribute(g.uv, 2));
        if (g.hasC) geo.setAttribute('color', new THREE.Float32BufferAttribute(g.c, 3));
        geo.setIndex(g.ix);
        if (g.hasN) geo.setAttribute('normal', new THREE.Float32BufferAttribute(g.n, 3)); else geo.computeVertexNormals();
        geo.computeBoundingSphere();
        const m = new THREE.Mesh(geo, g.material);
        m.name = `m1 ${g.material.name} ${g.chunk}`; m.userData.s = (g.chunk + 0.5) * CHUNK; m.userData.range = g.range;
        m.castShadow = shadow && g.material.userData.noShadow !== true; m.receiveShadow = true;
        scene.add(m); chunked.push(m); out.push(m);
      }
      this.groups.clear();
      return out;
    }
  }
  const surfaces = new Batch(), furniture = new Batch(), structures = new Batch();
  const _m = new THREE.Matrix4(), _q = new THREE.Quaternion(), _v = new THREE.Vector3(), _sc = new THREE.Vector3();
  // place a geometry at road coordinates (s, d, up) turned to the road heading (+ extra yaw)
  function put(batch, material, geo, s, d, up = 0, yaw = 0, range = 1200, scale = 1, color) {
    const f = fr(s);
    _v.set(f.x + f.nx * d, f.y + RT.crown(clamp(d, SB1, ROAD_HALF + 12)) + up, f.z + f.nz * d);
    _q.setFromAxisAngle(UP, f.heading + yaw); _sc.setScalar(scale);
    _m.compose(_v, _q, _sc);
    batch.geometry(material, s, geo, _m, range, color);
  }
  function putAt(batch, material, geo, x, y, z, yaw, s, range = 1200, scale = 1, color) {
    _v.set(x, y, z); _q.setFromAxisAngle(UP, yaw); _sc.setScalar(scale); _m.compose(_v, _q, _sc);
    batch.geometry(material, s, geo, _m, range, color);
  }

  /* A ribbon in road coordinates from sA to sB: `cols(s)` gives the lateral
   * offsets, `height(s, d, f)` the surface height, split into chunks. */
  function ribbon(batch, material, sA, sB, cols, height, opts = {}) {
    const step = opts.step ?? 4, range = opts.range ?? 1800, uv = opts.uv, tint = opts.tint;
    const c0 = Math.floor(sA / CHUNK), c1 = Math.floor((sB - 1e-6) / CHUNK);
    for (let c = c0; c <= c1; c += 1) {
      const a = Math.max(sA, c * CHUNK), b = Math.min(sB, (c + 1) * CHUNK);
      if (b - a < 0.05) continue;
      const rows = [], n = Math.max(1, Math.ceil((b - a) / step));
      for (let i = 0; i <= n; i += 1) {
        const s = lerp(a, b, i / n), f = fr(s), ds = typeof cols === 'function' ? cols(s) : cols;
        rows.push(ds.map((d) => {
          const y = height(s, d, f);
          const v = { x: f.x + f.nx * d, y, z: f.z + f.nz * d };
          if (uv) { const t = uv(s, d); v.u = t[0]; v.v = t[1]; } else { v.u = d / 4; v.v = s / 4; }
          if (tint) { const t = tint(s, d, v); v.r = t[0]; v.g = t[1]; v.b = t[2]; }
          return v;
        }));
      }
      batch.strip(material, (a + b) / 2, rows, range);
    }
  }
  const roadY = (s, d, f) => f.y + RT.crown(d);

  /* ------------------------------------------------------- carriageways */
  // motorway asphalt is a paler, more worn grey than a country lane
  const roadTint = (s) => { const t = 1.18 + (EVO.fbm(s / 41, 0.3, 2) - 0.5) * 0.24; return [t, t, t * 1.02]; };
  {
    // northbound: hard strip, four lanes, hard strip
    const cols = [-ROAD_HALF, -LANE_HALF, -LANE_W * 0.5, 0, LANE_W * 0.5, LANE_HALF, ROAD_HALF];
    ribbon(surfaces, roadMat, -450, L + 750, cols, roadY, { step: 5, range: 2200, uv: (s, d) => [(d + ROAD_HALF) / (LANE_W * 2), s / 6.2], tint: roadTint });
    // southbound
    const sb = [SB1, SB1 + 1, SB1 + 1 + LANE_W, SB0 - 1 - LANE_W, SB0 - 1, SB0];
    ribbon(surfaces, roadMat, -450, L + 750, sb, roadY, { step: 5, range: 2200, uv: (s, d) => [(SB0 - d) / (LANE_W * 2), s / 6.2], tint: roadTint });
    // the reserve: paved, darker, with the concrete step barrier down the middle
    ribbon(surfaces, reserveMat, -450, L + 750, [SB0, RES - 0.28, RES + 0.28, D.rightEdge], roadY, { step: 8, range: 1800 });
    // auxiliary lanes and refuges: extra asphalt beyond the left hard strip
    for (const x of extensions) {
      const m = x.kind === 'refuge' ? eraMat : roadMat;
      const a = x.widthAt && x.kind === 'merge' ? startMerge.nose : x.a, b = x.widthAt && x.kind === 'diverge' ? Math.min(x.b, x.b === L + 40 ? endDiverge.nose : x.b - 110) : x.b;
      ribbon(surfaces, m, a, b, (s) => { const w = Math.max(0.06, Math.min(AUX_W, auxWidth(s))); return [ROAD_HALF - 0.02, ROAD_HALF + w * 0.5, ROAD_HALF + w]; }, roadY,
        { step: 2, range: 1800, uv: (s, d) => [(d - ROAD_HALF) / (LANE_W * 2) + 0.5, s / 6.2], tint: x.kind === 'refuge' ? null : roadTint });
    }
  }

  /* ------------------------------------------------------ verges and land */
  // Fields: a patchwork of crops divided by hedges that run away from the
  // road, each field a different crop; the verge itself is mown grass.
  const fieldSeed = EVO.rng(313);
  const hedgeLines = []; // { s, side, len, skew }
  for (const side of [1, -1]) for (let s = -600; s < L + 800; s += 170 + fieldSeed() * 220) hedgeLines.push({ s, side, len: 260 + fieldSeed() * 360, skew: (fieldSeed() - 0.5) * 0.5 });
  // pasture, ripening wheat, young cereal, ploughed clay, barley stubble, grass ley, oilseed rape (on a neutral soil tile)
  const fieldPalette = [[0.62, 0.9, 0.42], [1.45, 1.2, 0.55], [0.55, 0.85, 0.4], [0.6, 0.45, 0.32], [1.3, 1.15, 0.7], [0.7, 0.95, 0.5], [1.4, 1.35, 0.3]];
  function fieldTint(s, d) {
    const side = d > 0 ? 1 : -1, ad = Math.abs(d);
    // which strip between two hedge lines, and which band away from the road
    let k = 0;
    for (const h of hedgeLines) { if (h.side === side && h.s <= s) k += 1; }
    const band = ad < 165 ? 0 : ad < 330 ? 1 : 2;
    const c = fieldPalette[(k * 3 + band * 2 + (side > 0 ? 0 : 4)) % fieldPalette.length];
    const n = EVO.fbm(s / 90 + 3, d / 90 - 2, 2) - 0.5;
    return [c[0] + n * 0.16, c[1] + n * 0.14, c[2] + n * 0.1];
  }
  const vergeTint = (s, d) => { const n = EVO.fbm(s / 30 + 1, d / 30, 2) - 0.5; return [0.9 + n * 0.2, 0.96 + n * 0.16, 0.8 + n * 0.14]; };
  {
    // the verge (mown grass) to the boundary, then the fields beyond it
    const vergeOut = [0, 1.2, 3, 6, 10, 15, 18], fieldOut = [18, 24, 32, 42, 56, 74, 100, 140, 200, 280, 400, 560];
    for (const side of [1, -1]) {
      const edgeAt = (s) => (side > 0 ? ROAD_HALF + auxWidth(s) : SB1);
      const vCols = (s) => { const e = edgeAt(s); const c = vergeOut.map((o) => e + side * o); return side > 0 ? c : c.reverse(); };
      const fCols = (s) => { const e = edgeAt(s); const c = fieldOut.map((o) => e + side * o); return side > 0 ? c : c.reverse(); };
      const uv = (s, d) => { const g = groundAt(s, d); return [g.x / 4, g.z / 4]; };
      ribbon(surfaces, grassMat, -600, L + 800, vCols, (s, d) => groundAt(s, d).y, { step: 8, range: 2400, uv, tint: (s, d) => vergeTint(s, d) });
      ribbon(surfaces, fieldMat, -600, L + 800, fCols, (s, d) => groundAt(s, d).y, { step: 10, range: 2400, uv: (s, d) => { const g = groundAt(s, d); return [g.x / 3, g.z / 3]; }, tint: (s, d) => fieldTint(s, d) });
    }
    // a coarse plain beyond the fields so the horizon is land, not sky
    const far = [560, 800, 1100, 1500, 2100, 3000];
    for (const side of [1, -1]) {
      const cols = far.map((o) => side * o);
      const rowsCols = side > 0 ? cols : cols.slice().reverse();
      ribbon(surfaces, fieldMat, -1200, L + 1500, rowsCols, (s, d, f) => { const x = f.x + f.nx * d, z = f.z + f.nz * d; return land(x, z) - 1.5; },
        { step: 120, range: 4500, uv: (s, d) => [s / 3, d / 3], tint: (s, d) => { const f = fieldTint(s, d); return [f[0] * 0.8, f[1] * 0.82, f[2] * 0.78]; } });
    }
  }
  for (const m of surfaces.finish(false)) if (m.material === grassMat || m.material === fieldMat) groundMeshes.push(m);

  /* ------------------------------------------------------- markings */
  // Lane lines 2 m / 7 m, solid 200 mm edge lines with the raised rib beside
  // the hard strip, 1 m / 1 m lane-drop lines along the diverges, chevron
  // hatching at every nose, and studs: white between lanes, red on the left
  // edge, amber beside the reserve, green along the slips.
  const MARK_UP = 0.012;
  const markY = (s, d, f) => f.y + RT.crown(d) + MARK_UP;
  function line(sA, sB, d, w, step = 8, up = 0) { ribbon(surfaces, markMat, sA, sB, [d - w / 2, d + w / 2], (s, dd, f) => markY(s, dd, f) + up, { step, range: 1500 }); }
  function dashed(sA, sB, d, w, dash, gap) { for (let s = sA; s < sB; s += dash + gap) line(s, Math.min(sB, s + dash), d, w, 8); }
  {
    const s0 = -450, s1 = L + 750;
    // lane lines both carriageways
    for (const d of [LANE_W, 0, -LANE_W]) dashed(s0, s1, d, 0.15, 2, 7);
    for (const d of [SB0 - LANE_W, SB0 - LANE_W * 2, SB0 - LANE_W * 3]) dashed(s0, s1, d, 0.15, 2, 7);
    // edge lines: solid beside the reserve, and along the left where the
    // carriageway keeps its normal width; the lane-drop line takes over
    // wherever an auxiliary lane opens
    line(s0, s1, -LANE_HALF, 0.2, 12); line(s0, s1, SB0 - LANE_W * 4, 0.2, 12);
    line(s0, s1, SB0 - 0.1, 0.2, 12);
    let runA = s0;
    for (let s = s0; s <= s1; s += 2) {
      const aux = auxWidth(s) > 0.3;
      if (aux || s >= s1) { if (s - runA > 2) line(runA, s, LANE_HALF, 0.2, 12); runA = s + 2; }
      if (aux) { while (s <= s1 && auxWidth(s) > 0.3) s += 2; runA = s; }
    }
    for (const x of extensions) {
      if (x.kind === 'refuge') { line(x.a, x.b, LANE_HALF, 0.2, 6); continue; }
      const a = x.widthAt && x.kind === 'merge' ? startMerge.nose : x.a, b = x.widthAt && x.kind === 'diverge' ? Math.min(x.b, x.b === L + 40 ? endDiverge.nose : x.b - 110) : x.b;
      dashed(a, b, LANE_HALF, 0.2, 1, 1);
      // outer edge line of the auxiliary lane
      ribbon(surfaces, markMat, a + (x.taperIn || 0), b - (x.taperOut || 0), (s) => [ROAD_HALF + Math.min(AUX_W, auxWidth(s)) - 1.1, ROAD_HALF + Math.min(AUX_W, auxWidth(s)) - 0.9], markY, { step: 6, range: 1500 });
    }
    // studs
    const studGeo = new THREE.BoxGeometry(0.12, 0.024, 0.2);
    const studs = [];
    const studColours = { white: new THREE.Color(0xe8ecee), red: new THREE.Color(0xd3261f), amber: new THREE.Color(0xe7a01c), green: new THREE.Color(0x27a344) };
    for (let s = s0 + 4.5; s < s1; s += 9) {
      for (const d of [LANE_W, 0, -LANE_W, SB0 - LANE_W, SB0 - LANE_W * 2, SB0 - LANE_W * 3]) studs.push([s, d, 'white']);
      studs.push([s, -LANE_HALF - 0.25, 'amber'], [s, SB0 + 0.25, 'amber']);
      studs.push([s, auxWidth(s) > 0.3 ? LANE_HALF : LANE_HALF + 0.25, auxWidth(s) > 0.3 ? 'green' : 'red']);
      studs.push([s, SB1 + 0.75, 'red']);
    }
    const im = new THREE.InstancedMesh(studGeo, studMat, studs.length);
    studs.forEach(([s, d, c], k) => {
      const f = fr(s); _v.set(f.x + f.nx * d, f.y + RT.crown(d) + 0.011, f.z + f.nz * d); _q.setFromAxisAngle(UP, f.heading); _sc.setScalar(1);
      _m.compose(_v, _q, _sc); im.setMatrixAt(k, _m); im.setColorAt(k, studColours[c]);
    });
    im.name = 'road studs'; im.userData.detailDistance = coarse ? 140 : 190; im.castShadow = false; scene.add(im);
  }
  // Chevron hatching in the nose between a diverging or merging slip and the
  // main line: diagonal 0.3 m bars inside a triangle that opens to 5 m.
  function hatchNose(sNose, len, opening, dir) {
    // dir +1: nose at sNose opening towards greater s (a diverge); -1: closing (a merge)
    const bars = Math.floor(len / 2.0);
    for (let k = 1; k < bars; k += 1) {
      const t = k / bars, s = sNose + dir * t * len, w = opening * t;
      if (w < 0.9) continue;
      // a 45 degree stripe 0.3 m wide: two rows, the second shifted along by the width it spans
      const f0 = fr(s), f1 = fr(s + dir * (w - 0.3));
      const rows = [[{ d: ROAD_HALF + 0.05, f: f0, s }, { d: ROAD_HALF + 0.35, f: f0, s }], [{ d: ROAD_HALF + w - 0.25, f: f1, s: s + dir * (w - 0.3) }, { d: ROAD_HALF + w + 0.05, f: f1, s: s + dir * (w - 0.3) }]]
        .map((row) => row.map((v) => ({ x: v.f.x + v.f.nx * v.d, y: markY(v.s, v.d, v.f), z: v.f.z + v.f.nz * v.d, u: 0, v: 0 })));
      surfaces.strip(markMat, s, dir > 0 ? rows : rows.map((r) => r.slice().reverse()), 900);
    }
    // solid outline of the nose
    ribbon(surfaces, markMat, sNose, sNose + dir * len, (s) => { const w = opening * Math.abs(s - sNose) / len; return [ROAD_HALF - 0.05 + w, ROAD_HALF + 0.15 + w]; }, markY, { step: 3, range: 900 });
  }

  /* ---------------------------------------------------- slip roads */
  // A slip is its own ribbon: a centre line described in road coordinates
  // (lateral offset and height above the carriageway as functions of s).
  const slipRuns = []; // { a, b, dAt, width }: where slips lie, so fences and hedges keep clear
  function slipEdge(s, side) {
    let best = 0;
    for (const r of slipRuns) { if (s < r.a || s > r.b) continue; const d = r.dAt(s); if (Math.sign(d) === side || (side > 0 && d > 0)) best = Math.max(best, Math.abs(d) + r.width / 2 + 3.5); }
    return best;
  }
  const fenceD = (s, side) => (side > 0 ? Math.max(RT.HEDGE_OFFSET + auxWidth(s), slipEdge(s, 1) + 2) : Math.min(SB1 - (RT.HEDGE_OFFSET - ROAD_HALF), -slipEdge(s, -1) - 2));
  function slip(sA, sB, dAt, hAt, width = 4.6, opts = {}) {
    slipRuns.push({ a: sA, b: sB, dAt, width });
    const cols = (s) => { const c = dAt(s); return [c - width / 2 - 1.0, c - width / 2, c, c + width / 2, c + width / 2 + 1.0]; };
    const height = (s, d, f) => { const c = dAt(s); const edge = Math.abs(d - c) > width / 2 + 0.01 ? -0.12 : 0; return f.y + RT.crown(clamp(d, SB1, ROAD_HALF)) + hAt(s) + edge; };
    ribbon(surfaces, roadMat, sA, sB, cols, height, { step: 4, range: 1800, uv: (s, d) => [(d - dAt(s)) / (LANE_W * 2) + 0.5, s / 6.2], tint: roadTint });
    // edge lines
    for (const side of [-1, 1]) ribbon(surfaces, markMat, sA, sB, (s) => [dAt(s) + side * (width / 2 - 0.25), dAt(s) + side * (width / 2 - 0.1)], (s, d, f) => height(s, d, f) + MARK_UP, { step: 6, range: 1200 });
    // the bank the slip climbs on: grass shoulders falling to the ground
    if (opts.bank) {
      for (const side of [-1, 1]) {
        const bankCols = (s) => { const c = dAt(s) + side * (width / 2 + 1.0); const h = hAt(s); const run = Math.max(0.5, h * 2.2); return side > 0 ? [c, c + run * 0.5, c + run, c + run + 3] : [c + run * -1 - 3, c - run, c - run * 0.5, c]; };
        ribbon(surfaces, bankMat, sA, sB, bankCols, (s, d, f) => {
          const c = dAt(s) + side * (width / 2 + 1.0), h = hAt(s), run = Math.max(0.5, h * 2.2);
          const t = clamp(Math.abs(d - c) / run, 0, 1);
          const top = f.y + RT.crown(clamp(d, SB1, ROAD_HALF)) + h - 0.12;
          return lerp(top, groundAt(s, d).y - 0.02, t * t * (3 - 2 * t));
        }, { step: 5, range: 1500, uv: (s, d) => [s / 4, d / 4], tint: (s, d) => vergeTint(s, d) });
      }
    }
  }

  /* ------------------------------------------ roundabout interchanges */
  // J12, J13 and J14 are roundabouts carried over the motorway on two decks,
  // with the slips climbing to them. The ring sits 8 m above the reserve and
  // the motorway runs through the open middle of it.
  const RING_R = 60, RING_H = 8.0, RING_W = 11, JOIN = Math.sqrt(RING_R * RING_R - (RING_R - 10) * (RING_R - 10));
  function interchange(e) {
    const sc = e.ring, cx = RES;
    const deckTop = (s) => fr(s).y + RT.crown(RES) + RING_H;
    const noseD = ROAD_HALF + 2.3, sbNose = SB1 - 2.3, mergeNose = e.s + 950;
    const joinN = cx + (RING_R - 10), joinS = cx - (RING_R - 10);
    // northbound exit slip: nose to the ring, climbing; entry slip back down to the merge
    slip(e.s, sc - JOIN, (s) => lerp(noseD, joinN, ease((s - e.s) / (sc - JOIN - e.s))), (s) => RING_H * ease((s - e.s - 60) / (sc - JOIN - e.s - 60)), 4.6, { bank: true });
    slip(sc + JOIN, mergeNose, (s) => lerp(joinN, noseD, ease((s - sc - JOIN) / (mergeNose - sc - JOIN))), (s) => RING_H * (1 - ease((s - sc - JOIN) / (mergeNose - sc - JOIN - 60))), 4.6, { bank: true });
    hatchNose(e.s, 110, 5.5, 1);
    hatchNose(mergeNose, 110, 5.5, -1);
    // southbound slips, mirrored on the far side
    slip(sc + JOIN, mergeNose, (s) => lerp(joinS, sbNose, ease((s - sc - JOIN) / (mergeNose - sc - JOIN))), (s) => RING_H * (1 - ease((s - sc - JOIN) / (mergeNose - sc - JOIN - 60))), 4.6, { bank: true });
    slip(e.s, sc - JOIN, (s) => lerp(sbNose, joinS, ease((s - e.s) / (sc - JOIN - e.s))), (s) => RING_H * ease((s - e.s - 60) / (sc - JOIN - e.s - 60)), 4.6, { bank: true });
    // the ring: road surface, a broken line round the middle, parapets
    const ringPoint = (a, r, up = 0) => { const s = sc + Math.cos(a) * r, d = cx + Math.sin(a) * r; const f = fr(s); return new THREE.Vector3(f.x + f.nx * d, deckTop(sc) + up, f.z + f.nz * d); };
    const N = 96;
    const rows = [];
    for (let i = 0; i <= N; i += 1) {
      const a = i / N * Math.PI * 2;
      rows.push([RING_R + RING_W / 2, RING_R, RING_R - RING_W / 2].map((r) => { const p = ringPoint(a, r, 0.02); return { x: p.x, y: p.y, z: p.z, u: a * RING_R / 6.2, v: (r - RING_R) / 12 + 0.5, r: 0.94, g: 0.94, b: 0.95 }; }));
    }
    structures.strip(roadMat, sc, rows, 2000);
    for (let i = 0; i < N; i += 2) { const r2 = [i, i + 1].map((k) => { const a = k / N * Math.PI * 2; return [RING_R - 0.06, RING_R + 0.06].map((r) => { const p = ringPoint(a, r, 0.035); return { x: p.x, y: p.y, z: p.z }; }); }); structures.strip(markMat, sc, r2, 1200); }
    for (const side of [-1, 1]) {
      const par = [];
      for (let i = 0; i <= N; i += 1) {
        const a = i / N * Math.PI * 2, r = RING_R + side * (RING_W / 2 + 0.25);
        const top = ringPoint(a, r, 1.1), foot = ringPoint(a, r, 0), back = ringPoint(a, r + side * 0.3, 0.9);
        par.push([{ x: foot.x, y: foot.y, z: foot.z, u: a * 8, v: 0 }, { x: top.x, y: top.y, z: top.z, u: a * 8, v: 1 }, { x: back.x, y: back.y, z: back.z, u: a * 8, v: 1.2 }]);
      }
      structures.strip(parapetMat, sc, side > 0 ? par : par.map((r) => r.slice().reverse()), 1800);
    }
    // banks outside and inside the ring wherever it is over land rather than a deck
    const overRoad = (a) => { const d = cx + Math.sin(a) * RING_R; return d > SB1 - 4 && d < ROAD_HALF + auxWidth(sc + Math.cos(a) * RING_R) + 4; };
    for (const inner of [false, true]) {
      const r0 = inner ? RING_R - RING_W / 2 - 0.6 : RING_R + RING_W / 2 + 0.6;
      // columns run inward (decreasing radius) so the strip faces up
      const steps = inner ? [0, -7, -14, -20] : [26, 18, 9, 0];
      let run = [];
      const flush = () => { if (run.length > 1) structures.strip(bankMat, sc, run, 1800); run = []; };
      for (let i = 0; i <= N; i += 1) {
        const a = i / N * Math.PI * 2;
        if (overRoad(a)) { flush(); continue; }
        run.push(steps.map((o, k) => {
          const r = r0 + o, p = ringPoint(a, r), s = sc + Math.cos(a) * r, d = cx + Math.sin(a) * r;
          const g = groundAt(s, d).y, t = k / (steps.length - 1);
          const y = lerp(p.y - 0.3, g - 0.05, ease(t));
          return { x: p.x, y, z: p.z, u: p.x / 4, v: p.z / 4, r: 0.86, g: 0.9, b: 0.72 };
        }));
      }
      flush();
    }
    // decks where the ring crosses the two carriageways, on piers
    const deckGeo = new THREE.BoxGeometry(1, 1.3, 1);
    for (const sign of [-1, 1]) {
      const sDeck = sc + sign * 57.5;
      const left = ROAD_HALF + auxWidth(sDeck) + 5, right = SB1 - 5, span = left - right;
      _v.copy(P(sDeck, (left + right) / 2, RING_H - 0.66)); _q.setFromAxisAngle(UP, fr(sDeck).heading); _sc.set(span, 1, RING_W + 1.5);
      _m.compose(_v, _q, _sc); structures.geometry(deckMat, sDeck, deckGeo, _m, 2000);
      for (const d of [RES, left, right]) {
        const g = d === RES ? fr(sDeck).y + RT.crown(RES) : groundAt(sDeck, d).y;
        const h = deckTop(sDeck) - 1.3 - g;
        const pier = new THREE.CylinderGeometry(0.85, 0.95, h, 12); pier.translate(0, h / 2, 0);
        const pp = P(sDeck, d);
        putAt(structures, concreteMat, pier, pp.x, g, pp.z, 0, sDeck, 1800);
      }
    }
    // the A-road continuing away from the ring on both sides, down to ground level
    for (const side of [-1, 1]) {
      const d0 = cx + side * RING_R, len = 420, f = fr(sc);
      const rowsA = [], bank = [];
      for (let i = 0; i <= 42; i += 1) {
        const t = i / 42, d = d0 + side * (t * len), g = groundAt(sc, d).y;
        const y = lerp(deckTop(sc), g + 0.25, ease((t * len - 20) / (len - 20)));
        rowsA.push([-5.5, 0, 5.5].map((w) => ({ x: f.x + f.nx * d + f.tx * w, y, z: f.z + f.nz * d + f.tz * w, u: w / 6.2 + 0.5, v: t * len / 6.2, r: 0.94, g: 0.94, b: 0.95 })));
        const run = Math.max(0.5, (y - g) * 2.0) + 1.5;
        bank.push([-6.5 - run, -6.5, 6.5, 6.5 + run].map((w, k) => ({ x: f.x + f.nx * d + f.tx * w, y: (k === 0 || k === 3) ? g - 0.05 : y - 0.15, z: f.z + f.nz * d + f.tz * w, u: d / 4, v: w / 4, r: 0.86, g: 0.9, b: 0.72 })));
      }
      structures.strip(roadMat, sc, side > 0 ? rowsA.map((r) => r.slice().reverse()) : rowsA, 2000);
      structures.strip(bankMat, sc, side > 0 ? bank.map((r) => r.slice().reverse()) : bank, 1800);
    }
  }
  for (const e of exits) interchange(e);
  // services noses
  hatchNose(startMerge.nose, 100, 5, -1);
  hatchNose(endDiverge.nose, 100, 5, 1);
  for (const e of exits) interchange(e);
  // services noses
  hatchNose(startMerge.nose, 100, 5, -1);
  hatchNose(endDiverge.nose, 100, 5, 1);

  /* ------------------------------------------------------ services */
  // Slip roads between the car parks and the carriageway, then the buildings.
  const totems = [];
  {
    const st = startMerge, en = endDiverge;
    // Toddington: the exit slip comes down off the car park and runs alongside until the nose
    slip(120, st.aux[0] + 2, startSlipD, () => 0, 4.6, { bank: false });
    // Newport Pagnell: the slip leaves the nose and climbs gently to the car park
    slip(en.nose - 2, L + 40, endSlipD, () => 0, 4.6, { bank: false });
    for (const park of parks) {
      const sMid = (park.s0 + park.s1) / 2, f = fr(sMid);
      const slabGeo = new THREE.BoxGeometry(park.d1 - park.d0, 0.3, park.s1 - park.s0);
      put(structures, slabMat, slabGeo, sMid, (park.d0 + park.d1) / 2, park.y - fr(sMid).y - RT.crown((park.d0 + park.d1) / 2) - 0.1, 0, 2200);
      // parking bays: rows of bays along the road, marked with white lines, some of them taken
      const bayLine = new THREE.BoxGeometry(0.1, 0.02, 5.2);
      const kinds = ['hatch', 'suv', 'van', 'hatch', 'hatch', 'suv'], paints = [0xd2d1c9, 0x324d65, 0xe1ded3, 0x883839, 0x777d7a, 0x1f3a6e, 0xc9ccd1, 0x4c6b3c];
      let bayIndex = 0;
      for (let row = 0; row < 4; row += 1) {
        const sRow = park.s0 + 40 + row * 48;
        for (let d = park.d0 + 22; d < park.d1 - 44; d += 2.6) {
          put(structures, markMat, bayLine, sRow, d, park.y - fr(sRow).y - RT.crown(d) + 0.06, 0, 500);
          put(structures, markMat, bayLine, sRow + 10.6, d, park.y - fr(sRow + 10.6).y - RT.crown(d) + 0.06, 0, 500);
          bayIndex += 1;
          if (bayIndex % 3 === 1 && RT.detailPlan) RT.detailPlan.lots.push({ s: sRow + (bayIndex % 2 ? 2.7 : 7.9), side: 1, d: d + 1.3, kind: kinds[bayIndex % kinds.length], paint: paints[bayIndex % paints.length], dir: bayIndex % 2 ? 1 : -1 });
        }
      }
      // amenity building: rendered box with a full-height glazed front facing the car park
      const bs = sMid + 20, bd = park.d1 - 32, bw = 34, bl = 78, bh = 7.5;
      const up = park.y - fr(bs).y - RT.crown(bd);
      put(structures, renderMat, new THREE.BoxGeometry(bw, bh, bl).translate(0, bh / 2, 0), bs, bd, up, 0, 2200);
      put(structures, glassMat, new THREE.BoxGeometry(0.4, 4.2, bl - 6).translate(0, 2.6, 0), bs, bd - bw / 2 - 0.1, up, 0, 1800);
      put(structures, canopyMat, new THREE.BoxGeometry(6, 0.4, bl - 2).translate(0, 4.9, 0), bs, bd - bw / 2 - 2.6, up, 0, 1800);
      put(structures, deckMat, new THREE.BoxGeometry(bw + 1, 0.5, bl + 1).translate(0, bh + 0.2, 0), bs, bd, up, 0, 2200);
      // fuel station: canopy on columns, pumps, a kiosk
      const fs = park.s0 + 60, fd = park.d0 + 14, fup = park.y - fr(fs).y - RT.crown(fd);
      put(structures, canopyMat, new THREE.BoxGeometry(22, 0.9, 34).translate(0, 5.6, 0), fs, fd, fup, 0, 2000);
      for (const [dx, dz] of [[-8, -12], [8, -12], [-8, 12], [8, 12], [-8, 0], [8, 0]]) put(structures, steelMat, new THREE.CylinderGeometry(0.22, 0.22, 5.2, 8).translate(0, 2.6, 0), fs + dz, fd + dx, fup, 0, 1400);
      for (const dz of [-9, -3, 3, 9]) put(structures, signalMat, new THREE.BoxGeometry(1.0, 1.7, 0.6).translate(0, 0.85, 0), fs + dz, fd, fup, 0, 900);
      put(structures, renderMat, new THREE.BoxGeometry(10, 4, 12).translate(0, 2, 0), fs, fd + 18, fup, 0, 1800);
      // totem sign and a few lorries in the lorry park
      put(structures, gantryMat, new THREE.BoxGeometry(0.5, 9, 0.5).translate(0, 4.5, 0), park.s0 + 10, park.d0 + 4, park.y - fr(park.s0 + 10).y - RT.crown(park.d0 + 4), 0, 2600);
      totems.push({ s: park.s0 + 10, d: park.d0 + 4, y: park.y, name: park.name });
      for (let k = 0; k < 4; k += 1) {
        const ls = park.s1 - 30 - k * 20, ld = park.d1 - 8;
        put(structures, canopyMat, new THREE.BoxGeometry(2.55, 2.9, 13.6).translate(0, 2.55, 0), ls, ld, park.y - fr(ls).y - RT.crown(ld), 0, 1400);
        put(structures, signalMat, new THREE.BoxGeometry(2.4, 1.1, 13.6).translate(0, 0.55, 0), ls, ld, park.y - fr(ls).y - RT.crown(ld), 0, 1400);
      }
    }
  }

  /* --------------------------------------------- barriers and fences */
  {
    // concrete step barrier down the reserve: a continuous extrusion
    const prof = [[-0.27, 0], [-0.27, 0.08], [-0.17, 0.25], [-0.11, 0.9], [0.11, 0.9], [0.17, 0.25], [0.27, 0.08], [0.27, 0]];
    ribbon(furniture, concreteMat, -450, L + 750, prof.map(([w]) => RES + w), (s, d, f) => {
      const k = prof.findIndex(([w]) => Math.abs(RES + w - d) < 1e-6);
      return f.y + RT.crown(RES) + prof[k][1];
    }, { step: 6, range: 1500, uv: (s, d) => [s / 2, d] });
    // steel safety barrier along the left verge: W-beam on posts, kept back from the strip
    const beamGeo = new THREE.BoxGeometry(0.08, 0.31, 4.0).translate(0, 0.62, 0);
    const postGeo = new THREE.BoxGeometry(0.1, 0.75, 0.06).translate(0, 0.375, 0);
    const wantBarrier = (s) => { const n = EVO.noise2(s / 900, 4.4); return M.bridges.some((b) => Math.abs(b.s - s) < 140) || exits.some((e) => Math.abs(e.ring - s) < 260) || n > 0.42; };
    for (let s = -300; s < L + 600; s += 4) {
      const d = ROAD_HALF + auxWidth(s) + 1.2;
      if (!wantBarrier(s) || parkAt(s, d + 10)) continue;
      const g = groundAt(s, d), f = g.f;
      putAt(furniture, steelMat, beamGeo, g.x, g.y, g.z, f.heading, s, 900);
      if (s % 8 < 4) putAt(furniture, gantryMat, postGeo, g.x, g.y, g.z, f.heading, s, 700);
    }
    // boundary fence both sides with a hedge beyond it
    const fpost = new THREE.BoxGeometry(0.09, 1.2, 0.09).translate(0, 0.6, 0);
    const hedgeGeo = new THREE.BoxGeometry(1.6, 1.3, 4.5).translate(0, 0.65, 0);
    const wires = [];
    for (const side of [1, -1]) {
      let last = null;
      for (let s = -600; s < L + 800; s += 4.5) {
        const d = fenceD(s, side);
        const nearRing = exits.some((e) => Math.abs(e.ring - s) < 180) || (side > 0 && parkAt(s, d + 12));
        if (nearRing) { last = null; continue; }
        const g = groundAt(s, d);
        putAt(furniture, woodMat, fpost, g.x, g.y - 0.05, g.z, g.f.heading, s, 420);
        if (last) for (const h of [0.45, 0.8, 1.1]) wires.push(last.x, last.y + h, last.z, g.x, g.y + h, g.z);
        last = g;
        const hg = groundAt(s, d + side * 1.9);
        const tint = 0.85 + EVO.noise2(s / 9, side) * 0.3, hh = 0.8 + EVO.noise2(s / 23, side + 4) * 0.7;
        if (EVO.noise2(s / 60, side + 9) > 0.9) { last = null; continue; } // a gate or a gap in the hedge
        _v.set(hg.x, hg.y - 0.2, hg.z); _q.setFromAxisAngle(UP, hg.f.heading); _sc.set(1, hh, 1); _m.compose(_v, _q, _sc);
        furniture.geometry(hedgeMat, s, hedgeGeo, _m, 900, new THREE.Color(tint, tint * 1.02, tint * 0.9));
      }
    }
    const wg = new THREE.BufferGeometry(); wg.setAttribute('position', new THREE.Float32BufferAttribute(wires, 3));
    const wireMesh = new THREE.LineSegments(wg, new THREE.LineBasicMaterial({ color: 0x4a4d50, transparent: true, opacity: 0.5 })); wireMesh.name = 'fence wire'; scene.add(wireMesh);
    // field hedges running away from the road, with hedgerow trees along them
    const treePlacements = [];
    for (const h of hedgeLines) {
      const f = fr(h.s);
      const edge = fenceD(h.s, h.side) + h.side * 3;
      for (let t = 0; t < h.len; t += 4.5) {
        const d = edge + h.side * t, ss = h.s + t * h.skew;
        const g = groundAt(ss, d);
        if (exits.some((e) => Math.abs(e.ring - ss) < 200 && Math.abs(d - RES) < 120)) continue;
        if (h.side > 0 && parkAt(ss, d)) continue;
        const yaw = Math.atan2(f.nx * h.side + f.tx * h.skew, f.nz * h.side + f.tz * h.skew);
        const tint = 0.85 + EVO.noise2(t / 9, h.s) * 0.3;
        putAt(furniture, hedgeMat, hedgeGeo, g.x, g.y - 0.2, g.z, yaw, ss, 1200, 1, new THREE.Color(tint, tint * 1.02, tint * 0.9));
        if (t > 8 && rnd() < 0.07) treePlacements.push({ x: g.x, y: g.y - 0.2, z: g.z, species: rnd() < 0.6 ? 'oak' : 'ash', scale: 0.85 + rnd() * 0.5, yaw: rnd() * Math.PI * 2, tint: 0.85 + rnd() * 0.3 });
      }
    }
    // tree belts along the boundary, dense through the woods, sparse elsewhere
    const woodAt = (s, side) => M.woods.some(([a, b, sd]) => s >= a && s <= b && sd === side);
    for (const side of [1, -1]) for (let s = -500; s < L + 700; s += 3.5) {
      const inWood = woodAt(s, side);
      if (!inWood && rnd() > 0.06) continue;
      if (exits.some((e) => Math.abs(e.ring - s) < 150)) continue;
      const edge = fenceD(s, side) + side * 3;
      const layers = inWood ? 3 : 1;
      for (let k = 0; k < layers; k += 1) {
        const d = edge + side * (1.5 + k * 9 + rnd() * 7), ss = s + (rnd() - 0.5) * 3;
        if (side > 0 && parkAt(ss, d)) continue;
        const g = groundAt(ss, d), r = rnd();
        const species = r < 0.35 ? 'oak' : r < 0.55 ? 'ash' : r < 0.75 ? 'birch' : r < 0.9 ? 'beech' : 'hawthorn';
        treePlacements.push({ x: g.x, y: g.y - 0.2, z: g.z, species, scale: (species === 'hawthorn' ? 0.55 : 0.8) + rnd() * 0.5, yaw: rnd() * Math.PI * 2, tint: 0.85 + rnd() * 0.3 });
      }
    }
    // copses and lone trees out in the fields
    for (let k = 0; k < 420; k += 1) {
      const s = rnd() * (L + 800) - 400, side = rnd() < 0.5 ? 1 : -1, d = side * (60 + rnd() * 460) + (side < 0 ? SB1 : 0);
      if (exits.some((e) => Math.abs(e.ring - s) < 200 && Math.abs(d - RES) < 140)) continue;
      if (side > 0 && parkAt(s, d, 30)) continue;
      const g = groundAt(s, d), n = rnd() < 0.3 ? 3 + Math.floor(rnd() * 5) : 1;
      for (let j = 0; j < n; j += 1) {
        const gx = g.x + (rnd() - 0.5) * 24, gz = g.z + (rnd() - 0.5) * 24;
        treePlacements.push({ x: gx, y: RT.terrainHeight(gx, gz) - 0.2, z: gz, species: rnd() < 0.6 ? 'oak' : 'ash', scale: 0.9 + rnd() * 0.6, yaw: rnd() * Math.PI * 2, tint: 0.85 + rnd() * 0.3 });
      }
    }
    EVO.vegetation.createTreeMeshes(scene, treePlacements, quality);
    // noise fences: tall timber panels close behind the barrier
    for (const [a, b, side] of M.noise) {
      const d = side > 0 ? ROAD_HALF + 4.2 : SB1 - 4.2;
      const panel = new THREE.BoxGeometry(0.12, 3.2, 3.0).translate(0, 1.6, 0);
      for (let s = a; s < b; s += 3) { if (auxWidth(s) > 0.1) continue; const g = groundAt(s, d); putAt(furniture, timberMat, panel, g.x, g.y - 0.1, g.z, g.f.heading, s, 1200); }
    }
  }

  /* ------------------------------------------------- signs and gantries */
  const texCache = {};
  function signTexture(key, w, h, draw) {
    if (texCache[key]) return texCache[key];
    const c = document.createElement('canvas'); c.width = w; c.height = h;
    const ctx = c.getContext('2d'); draw(ctx, w, h);
    const t = new THREE.CanvasTexture(c); t.colorSpace = THREE.SRGBColorSpace; t.anisotropy = Math.min(8, renderer.capabilities.getMaxAnisotropy());
    texCache[key] = t; return t;
  }
  const BLUE = '#0c4d9f', GREEN = '#0c7a3c';
  const font = (px, bold = true) => `${bold ? 'bold ' : ''}${px}px "Helvetica Neue", Arial, Helvetica, sans-serif`;
  function drawArrowSlant(ctx, x, y, size, up = true) {
    ctx.save(); ctx.translate(x, y); ctx.rotate(-Math.PI / 4); ctx.fillStyle = '#fff';
    ctx.fillRect(-size * 0.1, -size * 0.55, size * 0.2, size * 1.0);
    ctx.beginPath(); ctx.moveTo(0, -size * 0.85); ctx.lineTo(size * 0.32, -size * 0.45); ctx.lineTo(-size * 0.32, -size * 0.45); ctx.closePath(); ctx.fill(); ctx.restore();
  }
  function routePatch(ctx, x, y, text, px) {
    ctx.font = font(px); const w = ctx.measureText(text).width + px * 0.5;
    ctx.fillStyle = GREEN; ctx.fillRect(x, y - px * 0.85, w, px * 1.1);
    ctx.fillStyle = '#f2d24a'; ctx.textAlign = 'left'; ctx.fillText(text, x + px * 0.25, y);
    return w;
  }
  function junctionPanel(ctx, x, y, number, px) {
    ctx.fillStyle = '#111'; ctx.fillRect(x, y, px * 1.9, px * 1.25);
    ctx.fillStyle = '#fff'; ctx.font = font(px * 0.9); ctx.textAlign = 'center'; ctx.fillText(String(number), x + px * 0.95, y + px * 0.95);
  }
  const adsTexture = (e, distance) => signTexture(`ads${e.number}:${distance}`, 1024, 768, (ctx, W, H) => {
    ctx.fillStyle = BLUE; ctx.fillRect(0, 0, W, H); ctx.strokeStyle = '#fff'; ctx.lineWidth = 8; ctx.strokeRect(14, 14, W - 28, H - 28);
    junctionPanel(ctx, 38, 38, e.number, 64);
    const px = 88; let y = 210;
    ctx.fillStyle = '#fff'; ctx.textAlign = 'left';
    ctx.font = font(px); e.places.forEach((p) => { ctx.fillStyle = '#fff'; ctx.font = font(px); ctx.fillText(p, 60, y); y += px * 1.25; });
    let x = 60; x += routePatch(ctx, x, y, e.road, 76) + 20; if (e.extraRoad) routePatch(ctx, x, y, e.extraRoad, 76);
    ctx.fillStyle = '#fff'; ctx.font = font(84); ctx.textAlign = 'right';
    if (distance) ctx.fillText(distance, W - 60, H - 60); else drawArrowSlant(ctx, W - 130, H - 150, 150);
  });
  const confirmTexture = (e) => signTexture(`confirm${e.number}`, 1024, 720, (ctx, W, H) => {
    ctx.fillStyle = BLUE; ctx.fillRect(0, 0, W, H); ctx.strokeStyle = '#fff'; ctx.lineWidth = 8; ctx.strokeRect(14, 14, W - 28, H - 28);
    ctx.fillStyle = '#fff'; ctx.font = font(96); ctx.textAlign = 'left'; ctx.fillText('M1', 60, 150);
    let y = 300;
    for (const [place, miles] of e.after) { ctx.font = font(92); ctx.textAlign = 'left'; ctx.fillText(place, 60, y); if (miles != null) { ctx.textAlign = 'right'; ctx.fillText(String(miles), W - 60, y); } y += 130; }
  });
  const servicesTexture = (name, distance, operator) => signTexture(`svc:${name}:${distance}`, 1024, 640, (ctx, W, H) => {
    ctx.fillStyle = BLUE; ctx.fillRect(0, 0, W, H); ctx.strokeStyle = '#fff'; ctx.lineWidth = 8; ctx.strokeRect(14, 14, W - 28, H - 28);
    ctx.fillStyle = '#fff'; ctx.font = font(96); ctx.textAlign = 'left'; ctx.fillText('Services', 60, 150);
    ctx.font = font(66, false); ctx.fillText(name, 60, 250);
    if (operator) { ctx.font = font(48); ctx.fillText(operator, 60, 320); }
    // pictograms: fuel, cup, fork and knife, bed, information
    const icons = ['⛽', '☕', '🍴', '🛏', 'i'];
    icons.forEach((ic, k) => { ctx.fillStyle = '#fff'; ctx.fillRect(60 + k * 170, 380, 140, 140); ctx.fillStyle = BLUE; ctx.font = font(84); ctx.textAlign = 'center'; ctx.fillText(ic, 130 + k * 170, 480); });
    ctx.fillStyle = '#fff'; ctx.font = font(84); ctx.textAlign = 'right';
    if (distance) ctx.fillText(distance, W - 60, 150); else drawArrowSlant(ctx, W - 130, 240, 150);
  });
  const nextServicesTexture = () => signTexture('nextsvc', 1024, 400, (ctx, W, H) => {
    ctx.fillStyle = BLUE; ctx.fillRect(0, 0, W, H); ctx.strokeStyle = '#fff'; ctx.lineWidth = 8; ctx.strokeRect(14, 14, W - 28, H - 28);
    ctx.fillStyle = '#fff'; ctx.font = font(82); ctx.textAlign = 'left'; ctx.fillText('Services', 60, 130);
    SV.next.forEach(([n, mi], k) => { ctx.font = font(74); ctx.textAlign = 'left'; ctx.fillText(n, 60, 250 + k * 90); ctx.textAlign = 'right'; ctx.fillText(`${mi} m`, W - 60, 250 + k * 90); });
  });
  const countdownTexture = (bars) => signTexture(`cd${bars}`, 256, 384, (ctx, W, H) => {
    ctx.fillStyle = BLUE; ctx.fillRect(0, 0, W, H); ctx.strokeStyle = '#fff'; ctx.lineWidth = 6; ctx.strokeRect(8, 8, W - 16, H - 16);
    ctx.fillStyle = '#fff'; for (let k = 0; k < bars; k += 1) { ctx.save(); ctx.translate(W / 2, H * 0.28 + k * H * 0.22); ctx.rotate(-0.55); ctx.fillRect(-100, -14, 200, 28); ctx.restore(); }
  });
  const dlsTexture = (km) => signTexture(`dls${km}`, 256, 384, (ctx, W, H) => {
    ctx.fillStyle = BLUE; ctx.fillRect(0, 0, W, H); ctx.strokeStyle = '#fff'; ctx.lineWidth = 6; ctx.strokeRect(8, 8, W - 16, H - 16);
    ctx.fillStyle = '#fff'; ctx.textAlign = 'center'; ctx.font = font(78); ctx.fillText('M1', W / 2, 105); ctx.fillText('A', W / 2, 215); ctx.font = font(70); ctx.fillText(km, W / 2, 330);
  });
  const eraTexture = () => signTexture('era', 512, 640, (ctx, W, H) => {
    ctx.fillStyle = BLUE; ctx.fillRect(0, 0, W, H); ctx.strokeStyle = '#fff'; ctx.lineWidth = 8; ctx.strokeRect(12, 12, W - 24, H - 24);
    ctx.fillStyle = '#e8781c'; ctx.fillRect(60, 60, W - 120, 300);
    ctx.fillStyle = '#fff'; ctx.font = font(120); ctx.textAlign = 'center'; ctx.fillText('SOS', W / 2, 260);
    ctx.font = font(58); ctx.fillText('Emergency', W / 2, 460); ctx.fillText('area', W / 2, 540);
  });
  const nslTexture = () => EVO.tex.signNSL();
  const signalTexture = (aspect) => signTexture(`sig:${aspect}`, 256, 256, (ctx, W, H) => {
    ctx.fillStyle = '#0a0b0c'; ctx.fillRect(0, 0, W, H);
    if (!aspect) return;
    if (aspect === 'END') { ctx.strokeStyle = '#f4f4f0'; ctx.lineWidth = 12; ctx.beginPath(); ctx.arc(W / 2, H / 2, 94, 0, Math.PI * 2); ctx.stroke(); ctx.lineWidth = 16; ctx.beginPath(); ctx.moveTo(W / 2 + 62, H / 2 - 62); ctx.lineTo(W / 2 - 62, H / 2 + 62); ctx.stroke(); return; }
    ctx.strokeStyle = '#ff2a2a'; ctx.lineWidth = 16; ctx.beginPath(); ctx.arc(W / 2, H / 2, 100, 0, Math.PI * 2); ctx.stroke();
    ctx.fillStyle = '#f7f7f2'; ctx.font = font(120); ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillText(String(aspect), W / 2, H / 2 + 4);
  });
  const ms4Texture = (msg) => signTexture(`ms4:${msg}`, 1024, 384, (ctx, W, H) => {
    ctx.fillStyle = '#0a0b0c'; ctx.fillRect(0, 0, W, H);
    if (!msg) return;
    const words = msg.split(' '), lines = []; let cur = '';
    for (const w of words) { if ((cur + ' ' + w).trim().length > 16) { lines.push(cur.trim()); cur = w; } else cur += ' ' + w; }
    if (cur.trim()) lines.push(cur.trim());
    ctx.fillStyle = '#ffb61c'; ctx.font = font(96); ctx.textAlign = 'center';
    lines.slice(0, 3).forEach((l, k) => ctx.fillText(l, W / 2, 130 + k * 118));
  });

  const signMats = {};
  const signMaterial = (tex, emissive = false) => {
    if (!signMats[tex.uuid]) signMats[tex.uuid] = new THREE.MeshStandardMaterial({ name: 'sign face', map: tex, roughness: 0.5, metalness: 0.05, emissive: emissive ? 0xffffff : 0x000000, emissiveMap: emissive ? tex : null, emissiveIntensity: emissive ? 1.3 : 0 });
    return signMats[tex.uuid];
  };
  const faceGeoCache = {};
  const faceGeo = (w, h) => { const k = `${w}x${h}`; faceGeoCache[k] = faceGeoCache[k] || new THREE.PlaneGeometry(w, h); return faceGeoCache[k]; };
  // A verge sign: face on posts, back plate, at road distance s and offset d,
  // facing back down the road towards the rider.
  function signPost(x, y, z, yaw, tex, w, h, mountH, double = false) {
    const g = new THREE.Group();
    const face = new THREE.Mesh(faceGeo(w, h), signMaterial(tex)); face.position.set(0, mountH + h / 2, 0.035);
    const back = new THREE.Mesh(faceGeo(w, h), signBackMat); back.position.set(0, mountH + h / 2, 0); back.rotation.y = Math.PI;
    const post = new THREE.Mesh(new THREE.CylinderGeometry(0.05, 0.05, mountH + h * 0.9, 8), postMat); post.position.set(double ? -w * 0.3 : 0, (mountH + h * 0.9) / 2, -0.01);
    g.add(face, back, post);
    if (double) { const p2 = post.clone(); p2.position.x = w * 0.3; g.add(p2); }
    g.position.set(x, y, z); g.rotation.y = yaw;
    g.traverse((o) => { if (o.isMesh) { o.castShadow = true; o.receiveShadow = true; } });
    scene.add(g);
    return g;
  }
  function vergeSign(s, side, tex, w, h, mountH, facingForward, offset) {
    const d = side > 0 ? ROAD_HALF + auxWidth(s) + (offset ?? 3.2) : SB1 - (offset ?? 3.2);
    const g = groundAt(s, d), f = g.f;
    const yaw = facingForward ? Math.atan2(f.tx, f.tz) : Math.atan2(-f.tx, -f.tz);
    const sg = signPost(g.x, g.y, g.z, yaw, tex, w, h, mountH, w > 1.2);
    sg.userData.s = s; sg.userData.range = 1300; chunked.push(sg);
    return sg;
  }
  {
    // advance direction signs at a mile and half a mile, the sign at the nose,
    // countdown markers, and a confirmatory sign after each junction
    for (const e of exits) {
      vergeSign(e.s - 1609, 1, adsTexture(e, '1 m'), 5.2, 3.9, 1.9, false);
      vergeSign(e.s - 805, 1, adsTexture(e, '½ m'), 5.2, 3.9, 1.9, false);
      vergeSign(e.s - 30, 1, adsTexture(e, null), 5.2, 3.9, 1.9, false, 5.2);
      [3, 2, 1].forEach((bars, k) => vergeSign(e.s - 270 + k * 90, 1, countdownTexture(bars), 0.9, 1.35, 1.6, false));
      vergeSign(e.s + 1150, 1, confirmTexture(e), 4.6, 3.2, 1.9, false);
    }
    // services signs: the next services after Toddington, then Newport Pagnell at a mile, half a mile and the nose
    vergeSign(760, 1, nextServicesTexture(), 4.2, 1.7, 1.9, false);
    vergeSign(endDiverge.nose - 1609, 1, servicesTexture(SV.end.name, '1 m', SV.end.operator), 4.6, 2.9, 1.9, false);
    vergeSign(endDiverge.nose - 805, 1, servicesTexture(SV.end.name, '½ m', SV.end.operator), 4.6, 2.9, 1.9, false);
    vergeSign(endDiverge.nose - 30, 1, servicesTexture(SV.end.name, null, SV.end.operator), 4.6, 2.9, 1.9, false, 5.2);
    [3, 2, 1].forEach((bars, k) => vergeSign(endDiverge.nose - 270 + k * 90, 1, countdownTexture(bars), 0.9, 1.35, 1.6, false));
    // driver location signs every 500 m, emergency area signs, national limit at the start
    for (let s = 500; s < L - 300; s += 500) vergeSign(s, 1, dlsTexture((M.kmAtStart + s / 1000).toFixed(1)), 0.62, 0.93, 1.3, false, 1.6);
    for (const s of M.refuges) { vergeSign(s - 300, 1, eraTexture(), 1.1, 1.4, 1.6, false); vergeSign(s - ERA_L / 2, 1, eraTexture(), 1.1, 1.4, 1.6, false, ERA_W + 1.6); }
    vergeSign(startMerge.taper[1] + 40, 1, nslTexture(), 0.9, 0.9, 1.8, false);
    // services totems: a tall blue board by each car park entrance
    for (const t of totems) {
      const tex = signTexture(`totem:${t.name}`, 256, 768, (ctx, W, H) => {
        ctx.fillStyle = BLUE; ctx.fillRect(0, 0, W, H); ctx.strokeStyle = '#fff'; ctx.lineWidth = 6; ctx.strokeRect(8, 8, W - 16, H - 16);
        ctx.fillStyle = '#fff'; ctx.textAlign = 'center'; ctx.font = font(44); ctx.fillText('SERVICES', W / 2, 90);
        ctx.font = font(34, false); ctx.fillText(t.name, W / 2, 150);
        ['FUEL', 'FOOD', 'COFFEE', 'SHOP', 'PARKING'].forEach((w, k) => { ctx.fillStyle = k % 2 ? '#e8e8e2' : '#fff'; ctx.font = font(40); ctx.fillText(w, W / 2, 260 + k * 96); });
      });
      const f = fr(t.s), yaw = Math.atan2(-f.tx, -f.tz);
      const g = signPost(f.x + f.nx * t.d, t.y, f.z + f.nz * t.d, yaw, tex, 2.4, 7.2, 1.6, true);
      g.userData.s = t.s; g.userData.range = 2600; chunked.push(g);
    }
  }
  // Emergency refuge areas: the orange surface is laid; add the SOS cabinet
  {
    const cab = new THREE.BoxGeometry(0.7, 1.5, 0.5).translate(0, 0.75, 0);
    for (const s of M.refuges) { const g = groundAt(s + 20, ROAD_HALF + ERA_W + 1.4); putAt(furniture, eraMat, cab, g.x, g.y, g.z, g.f.heading, s, 700); }
  }
  // Portal gantries: a column each side of the carriageway and one in the
  // reserve, a box beam across, a signal over every lane, a walkway, and the
  // message sign where there is a message. The southbound side carries blank signals.
  {
    const colGeo = new THREE.CylinderGeometry(0.32, 0.36, 7.2, 12).translate(0, 3.6, 0);
    const baseGeo = new THREE.BoxGeometry(1.4, 0.5, 1.4).translate(0, 0.25, 0);
    const sigGeo = new THREE.BoxGeometry(1.5, 1.25, 0.45).translate(0, 0.62, 0);
    const railGeo = new THREE.BoxGeometry(1, 0.05, 0.05);
    for (const g of M.gantries) {
      const s = g.s, f = fr(s);
      const left = ROAD_HALF + auxWidth(s) + 1.6, right = SB1 - 1.6;
      for (const d of [left, RES, right]) {
        const y = d === RES ? f.y + RT.crown(RES) : groundAt(s, d).y;
        const pp = P(s, d);
        putAt(furniture, gantryMat, colGeo, pp.x, y, pp.z, f.heading, s, 1800);
        putAt(furniture, concreteMat, baseGeo, pp.x, y, pp.z, f.heading, s, 900);
      }
      const beamH = 6.9;
      const beam = new THREE.BoxGeometry(left - right + 0.8, 0.75, 0.75);
      _v.copy(P(s, (left + right) / 2, beamH)); _q.setFromAxisAngle(UP, f.heading); _sc.setScalar(1); _m.compose(_v, _q, _sc);
      furniture.geometry(gantryMat, s, beam, _m, 2000);
      // walkway rails behind the beam
      for (const h of [0.55, 1.1]) { const r = new THREE.BoxGeometry(left - right + 0.8, 0.05, 0.05); _v.copy(P(s, (left + right) / 2, beamH + 0.4 + h)); _v.addScaledVector(new THREE.Vector3(f.tx, 0, f.tz), 0.7); _m.compose(_v, _q, _sc); furniture.geometry(gantryMat, s, r, _m, 1200); }
      // signals over our lanes, facing back at the rider; blank ones over the other carriageway
      const aspect = g.limit ? String(g.limit) : '';
      const sigMat = (a) => signMaterial(signalTexture(a), true);
      for (const d of LANES) {
        const pp = P(s, d, beamH + 0.45);
        putAt(furniture, signalMat, sigGeo, pp.x, pp.y, pp.z, f.heading, s, 1500);
        const face = new THREE.Mesh(faceGeo(1.3, 1.1), sigMat(aspect)); face.position.set(pp.x - f.tx * 0.24, pp.y + 0.62, pp.z - f.tz * 0.24); face.rotation.y = Math.atan2(-f.tx, -f.tz);
        face.userData.s = s; face.userData.range = 1500; scene.add(face); chunked.push(face);
      }
      for (const d of SB_LANES) { const pp = P(s, d, beamH + 0.45); putAt(furniture, signalMat, sigGeo, pp.x, pp.y, pp.z, f.heading, s, 1500); }
      if (g.msg) {
        const pp = P(s, (LANES[1] + LANES[2]) / 2, beamH + 1.95);
        putAt(furniture, signalMat, new THREE.BoxGeometry(4.2, 1.7, 0.4).translate(0, 0.85, 0), pp.x, pp.y, pp.z, f.heading, s, 1600);
        const face = new THREE.Mesh(faceGeo(4.0, 1.5), signMaterial(ms4Texture(g.msg), true)); face.position.set(pp.x - f.tx * 0.22, pp.y + 0.85, pp.z - f.tz * 0.22); face.rotation.y = Math.atan2(-f.tx, -f.tz);
        face.userData.s = s; face.userData.range = 1600; scene.add(face); chunked.push(face);
      }
    }
  }
  // Lighting columns in the reserve through the lit sections
  {
    const col = new THREE.CylinderGeometry(0.09, 0.16, 12, 8).translate(0, 6, 0);
    const arm = new THREE.BoxGeometry(0.12, 0.12, 2.6).translate(0, 12, 1.2);
    const lantern = new THREE.BoxGeometry(0.3, 0.2, 0.7).translate(0, 12, 2.3);
    for (const [a, b] of M.lit) for (let s = a; s < b; s += 40) {
      const f = fr(s), pp = P(s, RES, 0.9);
      for (const flip of [0, Math.PI]) { putAt(furniture, lampMat, arm, pp.x, pp.y, pp.z, f.heading + flip, s, 1400); putAt(furniture, lampMat, lantern, pp.x, pp.y, pp.z, f.heading + flip, s, 1400); }
      putAt(furniture, lampMat, col, pp.x, pp.y, pp.z, f.heading, s, 1600);
    }
  }
  /* --------------------------------------------------- overbridges */
  // Minor roads and a footbridge or two cross on two-span decks: a pier in
  // the reserve, abutments on the verges, banks carrying the road up to them.
  {
    const deckGeo = new THREE.BoxGeometry(1, 1, 1);
    for (const b of M.bridges) {
      const s = b.s, f = fr(s), foot = b.kind === 'foot';
      const width = foot ? 3.2 : 8.5, clear = foot ? 5.7 : 5.4, thick = foot ? 0.5 : 1.2;
      const left = ROAD_HALF + auxWidth(s) + 6, right = SB1 - 6;
      const top = f.y + RT.crown(RES) + clear + thick;
      _v.copy(P(s, (left + right) / 2, 0)); _v.y = top - thick / 2; _q.setFromAxisAngle(UP, f.heading); _sc.set(left - right, thick, width);
      _m.compose(_v, _q, _sc); structures.geometry(deckMat, s, deckGeo, _m, 2200);
      // parapets along both edges of the deck
      for (const side of [-1, 1]) { _v.copy(P(s, (left + right) / 2, 0)); _v.y = top + (foot ? 0.7 : 0.55); _v.addScaledVector(new THREE.Vector3(f.tx, 0, f.tz), side * (width / 2 - 0.15)); _sc.set(left - right, foot ? 1.4 : 1.1, 0.25); _m.compose(_v, _q, _sc); structures.geometry(parapetMat, s, deckGeo, _m, 1800); }
      // pier and abutments
      for (const d of [RES, left, right]) {
        const g = d === RES ? f.y + RT.crown(RES) : groundAt(s, d).y;
        const h = top - thick - g;
        const pier = d === RES ? new THREE.BoxGeometry(0.9, h, width - 1).translate(0, h / 2, 0) : new THREE.BoxGeometry(1.4, h, width + 1).translate(0, h / 2, 0);
        const pp = P(s, d); putAt(structures, concreteMat, pier, pp.x, g, pp.z, f.heading, s, 1800);
      }
      // approach banks with the road on top, falling to the ground over 90 m each side
      for (const side of [-1, 1]) {
        const d0 = side > 0 ? left : right, len = foot ? 40 : 110;
        const rows = [], bank = [];
        for (let i = 0; i <= 22; i += 1) {
          const t = i / 22, d = d0 + side * t * len, g = groundAt(s, d).y;
          const y = foot ? lerp(top, g + 0.2, ease(t)) : lerp(top, g + 0.2, ease((t * len - 6) / (len - 6)));
          const hw = width / 2;
          rows.push([-hw, 0, hw].map((w) => ({ x: f.x + f.nx * d + f.tx * w, y: y + 0.02, z: f.z + f.nz * d + f.tz * w, u: w / 6.2 + 0.5, v: t * len / 6.2, r: 0.94, g: 0.94, b: 0.95 })));
          const run = Math.max(0.6, (y - g) * 2.0) + 1.2;
          bank.push([-hw - run, -hw - 0.4, hw + 0.4, hw + run].map((w, k) => ({ x: f.x + f.nx * d + f.tx * w, y: (k === 0 || k === 3) ? g - 0.05 : y - 0.12, z: f.z + f.nz * d + f.tz * w, u: d / 4, v: w / 4, r: 0.86, g: 0.9, b: 0.72 })));
        }
        structures.strip(roadMat, s, side > 0 ? rows.map((r) => r.slice().reverse()) : rows, 2000);
        structures.strip(bankMat, s, side > 0 ? bank.map((r) => r.slice().reverse()) : bank, 1800);
      }
    }
  }

  /* ---------------------------------------------- pylons and sheds */
  {
    // 400 kV lattice towers striding across the fields: four legs, a body, three crossarms, wires
    const leg = new THREE.BoxGeometry(0.28, 1, 0.28);
    const armGeo = new THREE.BoxGeometry(1, 0.5, 0.5);
    const wires = [];
    for (const py of M.pylons) {
      const f = fr(py.s), ang = THREE.MathUtils.degToRad(py.angle);
      const dirX = f.tx * Math.cos(ang) + f.nx * Math.sin(ang), dirZ = f.tz * Math.cos(ang) + f.nz * Math.sin(ang);
      const prev = [];
      for (let k = -4; k <= 4; k += 1) {
        const dist = k * 330 + (k >= 0 ? 90 : -90);
        const x = f.x + dirX * dist, z = f.z + dirZ * dist;
        const yg = RT.terrainHeight(x, z) - 0.2, H = 46;
        const yaw = Math.atan2(dirX, dirZ);
        for (const [lx, lz] of [[-3.2, -3.2], [3.2, -3.2], [-3.2, 3.2], [3.2, 3.2]]) {
          _v.set(x + Math.cos(yaw) * lx + Math.sin(yaw) * lz, yg, z - Math.sin(yaw) * lx + Math.cos(yaw) * lz); _q.setFromAxisAngle(UP, yaw);
          _sc.set(1, H, 1); _m.compose(_v.setY(yg + H / 2), _q, _sc); structures.geometry(pylonMat, py.s, leg, _m, 3200);
        }
        const arms = [[H * 0.62, 11], [H * 0.78, 13], [H * 0.93, 10]];
        for (const [h, w] of arms) { _v.set(x, yg + h, z); _sc.set(w * 2, 1, 1); _q.setFromAxisAngle(UP, yaw + Math.PI / 2); _m.compose(_v, _q, _sc); structures.geometry(pylonMat, py.s, armGeo, _m, 3200); }
        _v.set(x, yg + H + 1.5, z); _sc.set(1.2, 3, 1.2); _q.setFromAxisAngle(UP, yaw); _m.compose(_v, _q, _sc); structures.geometry(pylonMat, py.s, leg, _m, 3200);
        const ends = arms.flatMap(([h, w]) => [-1, 1].map((sd) => [x + Math.cos(yaw + Math.PI / 2) * w * sd, yg + h - 2.2, z - Math.sin(yaw + Math.PI / 2) * w * sd]));
        if (prev.length) ends.forEach((e, j) => { const a = prev[j]; for (let t = 1; t <= 8; t += 1) { const u0 = (t - 1) / 8, u1 = t / 8; wires.push(lerp(a[0], e[0], u0), lerp(a[1], e[1], u0) - Math.sin(u0 * Math.PI) * 9, lerp(a[2], e[2], u0), lerp(a[0], e[0], u1), lerp(a[1], e[1], u1) - Math.sin(u1 * Math.PI) * 9, lerp(a[2], e[2], u1)); } });
        prev.length = 0; prev.push(...ends);
      }
    }
    const wg = new THREE.BufferGeometry(); wg.setAttribute('position', new THREE.Float32BufferAttribute(wires, 3));
    const wm = new THREE.LineSegments(wg, new THREE.LineBasicMaterial({ color: 0x2f3236, transparent: true, opacity: 0.55 })); wm.name = 'pylon wires'; scene.add(wm);
    // distribution sheds beside the junctions: long cladded boxes with a blue band and dock doors
    const box = new THREE.BoxGeometry(1, 1, 1);
    for (const sh of M.sheds) {
      for (let k = 0; k < 3; k += 1) {
        const s = sh.s + k * 260 + rnd() * 40, side = sh.side, d = side > 0 ? 140 + rnd() * 60 : SB1 - 130 - rnd() * 60;
        const len = 170 + rnd() * 90, wid = 90 + rnd() * 50, h = 13 + rnd() * 4;
        const g = groundAt(s, d), f = g.f;
        _q.setFromAxisAngle(UP, f.heading);
        _v.set(g.x, g.y + h / 2, g.z); _sc.set(wid, h, len); _m.compose(_v, _q, _sc); structures.geometry(shedMat, s, box, _m, 3500);
        _v.set(g.x, g.y + h - 1.6, g.z); _sc.set(wid + 0.3, 1.4, len + 0.3); _m.compose(_v, _q, _sc); structures.geometry(shedBandMat, s, box, _m, 3500);
        _v.set(g.x, g.y + 0.5, g.z); _sc.set(wid + 40, 0.3, len + 30); _m.compose(_v, _q, _sc); structures.geometry(slabMat, s, box, _m, 3500);
      }
    }
  }
  surfaces.finish(false);
  furniture.finish(true);
  structures.finish(true);

  /* --------------------------------------------------------- update */
  // Chunk visibility by distance along the road, refreshed a few times a second.
  let lastCull = -1, lastS = 0;
  function update(time, pos, forward) {
    if (time - lastCull < 0.2) return;
    lastCull = time;
    const near = RT.nearest(pos.x, pos.z);
    const s = near ? near.s : lastS; lastS = s;
    for (const o of chunked) { const range = o.userData.range || 1200; o.visible = Math.abs(o.userData.s - s) < range; }
  }
  return { groundAt, groundMeshes, VERGE_D, signPost, vergeSign, update };
};
