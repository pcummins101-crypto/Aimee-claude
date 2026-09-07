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
  const { roadMat, grassMat, hedgeMat, markMat, leafMat, postMat, woodMat, blackMat, signBackMat, studMat, coneMat, barrierMat, barrierRedMat } = materials;
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
  const cabinLike = mat('pale render', 0xdcdad2, { roughness: 0.82 });
  const cattleMat = mat('cattle', 0xffffff, { roughness: 0.9 });
  const gravelMatFarm = mat('silage bale', 0xd8d6cf, { roughness: 0.7 });
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
  /* ------------------------------------------------- the lane closure */
  // A long-term scheme with lane 1 coned off: the cones taper in from the
  // nearside edge to the lane line, run along it for the length of the works
  // and taper back out. Everything left of that line is site, not carriageway.
  const RW = M.roadworks;
  const RW_LINE = LANE_HALF - (RW.lane + 1) * LANE_W;
  function coneLine(s) {
    if (s < RW.start || s > RW.end) return null;
    if (s < RW.start + RW.taperIn) return lerp(ROAD_HALF, RW_LINE, ease((s - RW.start) / RW.taperIn));
    if (s > RW.end - RW.taperOut) return lerp(RW_LINE, ROAD_HALF, ease((s - RW.end + RW.taperOut) / RW.taperOut));
    return RW_LINE;
  }
  const inWorks = (s) => s >= RW.start && s <= RW.end;
  const extent = { left: ROAD_HALF, right: D.rightEdge };
  function roadExtent(s) {
    const c = coneLine(s);
    extent.left = c === null ? ROAD_HALF + auxWidth(s) : c - 0.25;
    return extent;
  }
  RT.roadExtent = roadExtent;
  // Which lane traffic must be out of, and from how far back. Drivers merge
  // well before the taper, so the closure reads as closed 500 m early.
  RT.closedLane = (s) => (s >= RW.start - 500 && s <= RW.end ? RW.lane : -1);
  RT.roadworks = RW;
  // where to put the rider back after a spill: never inside the coned lane
  RT.resetLane = (s) => { const c = coneLine(s); return c === null ? RT.homeLane : c - LANE_W / 2; };
  RT.auxWidth = auxWidth;
  RT.laneCentres = LANES;
  RT.runEnd = L - 40;
  RT.edgeReason = (s, d) => (d < 0 ? 'INTO THE CENTRAL BARRIER'
    : inWorks(s) ? 'INTO THE ROAD WORKS · LANE 1 IS CLOSED'
    : exits.some((e) => s > e.s && s < e.s + 500) ? 'LEFT THE M1 · TOOK THE EXIT'
    : 'OFF THE CARRIAGEWAY · INTO THE BARRIER');
  RT.finishNose = endDiverge.nose;

  /* ------------------------------------------------------ ground */
  // The services car parks are flat slabs beside the road; everything else is
  // the verge stepping down off the hard strip and blending into the land.
  const parks = [
    { s0: 20, s1: 340, d0: 40, d1: 166, y: null, services: true, name: SV.start.name },
    { s0: L - 120, s1: L + 250, d0: 40, d1: 166, y: null, services: true, name: SV.end.name },
    // the works compound is cut into the verge as a level hardstanding
    { s0: RW.compound - 78, s1: RW.compound + 78, d0: ROAD_HALF + 14, d1: ROAD_HALF + 48, terrain: true, blend: 26, y: null, name: 'compound' }
  ];
  for (const p of parks) {
    const f = fr((p.s0 + p.s1) / 2);
    // a works platform is levelled where it stands; a services car park is
    // built up off the carriageway
    if (p.terrain) { const c = P((p.s0 + p.s1) / 2, (p.d0 + p.d1) / 2); p.y = land(c.x, c.z) + 0.25; }
    else p.y = f.y + RT.crown(ROAD_HALF) + 0.35;
  }
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
        const b = park.blend ?? 8;
        const inside = smoothstep(0, b, Math.min(s - park.s0 + 12, park.s1 + 12 - s)) * smoothstep(0, b, Math.min(d - park.d0 + 8, park.d1 + 8 - d));
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

  /* Instanced props (cones, crops, posts, livestock) grouped into the same
   * road chunks as everything else, so one switch culls the lot. */
  function instanceProps(name, geo, material, items, range = 900, shadow = true) {
    const bins = new Map();
    for (const it of items) { const k = Math.floor(it.s / CHUNK); if (!bins.has(k)) bins.set(k, []); bins.get(k).push(it); }
    const made = [];
    for (const [k, list] of bins) {
      const im = new THREE.InstancedMesh(geo, material, list.length);
      list.forEach((it, i) => {
        _v.set(it.x, it.y, it.z); _q.setFromAxisAngle(UP, it.yaw || 0);
        _sc.set(it.sx ?? it.sc ?? 1, it.sy ?? it.sc ?? 1, it.sz ?? it.sc ?? 1);
        _m.compose(_v, _q, _sc); im.setMatrixAt(i, _m);
        if (it.color) im.setColorAt(i, it.color);
      });
      im.name = name; im.castShadow = shadow; im.receiveShadow = true;
      im.userData.s = (k + 0.5) * CHUNK; im.userData.range = range;
      im.userData.partitioned = true;   // already binned by road distance
      im.computeBoundingSphere();
      scene.add(im); chunked.push(im); made.push(im);
    }
    return made;
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
        rows.push(ds.map((d, i) => {
          const y = height(s, d, f, i);
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
  // which crop this patch of ground carries: the strip between two hedge lines
  // and the band away from the road pick an entry out of the palette
  function fieldIndex(s, d) {
    const side = d > 0 ? 1 : -1, ad = Math.abs(d);
    let k = 0;
    for (const h of hedgeLines) { if (h.side === side && h.s <= s) k += 1; }
    const band = ad < 165 ? 0 : ad < 330 ? 1 : 2;
    return (k * 3 + band * 2 + (side > 0 ? 0 : 4)) % fieldPalette.length;
  }
  function fieldTint(s, d) {
    const c = fieldPalette[fieldIndex(s, d)];
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
    // A coarse plain beyond the fields so the horizon is land, not sky. Its
    // height follows the carriageway rather than the terrain function: that
    // function maps a world point back onto the alignment, which is meaningless
    // a kilometre out to the side and throws the plain about wildly.
    const far = [560, 900, 1400, 2200];
    for (const side of [1, -1]) {
      const cols = far.map((o) => side * o);
      const rowsCols = side > 0 ? cols : cols.slice().reverse();
      ribbon(surfaces, fieldMat, -1200, L + 1500, rowsCols,
        (s, d, f) => f.y - 2.5 - Math.abs(d) * 0.004 + (EVO.fbm(d / 700 + 3, s / 700 - 2, 2) - 0.5) * 9,
        { step: 100, range: 5000, uv: (s, d) => [s / 3, d / 3], tint: (s, d) => { const t = fieldTint(s, d); return [t[0] * 0.8, t[1] * 0.82, t[2] * 0.78]; } });
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
      if (!park.services) continue;
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
    ribbon(furniture, concreteMat, -450, L + 750, prof.map(([w]) => RES + w),
      (s, d, f, i) => f.y + RT.crown(RES) + prof[i][1],
      { step: 6, range: 1500, uv: (s, d) => [s / 2, d] });
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
    // more hedgerow and boundary trees than a bare motorway corridor needs:
    // the M1 through Bedfordshire runs between planted belts nearly all the way
    for (const side of [1, -1]) for (let s = -500; s < L + 700; s += 7) {
      if (exits.some((e) => Math.abs(e.ring - s) < 150)) continue;
      // belts thicken and thin along the road rather than marching in step
      const density = EVO.noise2(s / 260, side * 3.1);
      if (rnd() > 0.15 + density * 0.85) continue;
      const edge = fenceD(s, side) + side * (3 + rnd() * 18);
      if (side > 0 && parkAt(s, edge, 20)) continue;
      const g = groundAt(s + (rnd() - 0.5) * 11, edge), r = rnd();
      const species = r < 0.3 ? 'hawthorn' : r < 0.55 ? 'birch' : r < 0.78 ? 'ash' : 'oak';
      treePlacements.push({ x: g.x, y: g.y - 0.2, z: g.z, species, scale: (species === 'hawthorn' ? 0.5 : 0.72) + rnd() * 0.55, yaw: rnd() * Math.PI * 2, tint: 0.85 + rnd() * 0.3 });
    }
    EVO.vegetation.createTreeMeshes(scene, treePlacements, quality);
    /* ------------------------------------------------ crops and cover */
    // The fields are drilled, not bare: instanced cross-cards tinted by the
    // crop the field ground already carries, so a wheat field grows wheat.
    const cropTex = (() => {
      const S = 256, c = document.createElement('canvas'); c.width = S; c.height = S;
      const ctx = c.getContext('2d'); ctx.clearRect(0, 0, S, S);
      const r = EVO.rng(771);
      ctx.lineCap = 'round';
      for (let k = 0; k < 54; k += 1) {
        const x = 18 + r() * (S - 36), h = S * (0.5 + r() * 0.46), lean = (r() - 0.5) * 30;
        const v = 196 + Math.floor(r() * 52);
        ctx.strokeStyle = `rgba(${v},${v},${v - 26},0.96)`; ctx.lineWidth = 2.2 + r() * 1.8;
        ctx.beginPath(); ctx.moveTo(x, S); ctx.quadraticCurveTo(x + lean * 0.4, S - h * 0.55, x + lean, S - h); ctx.stroke();
        if (r() < 0.7) { // the ear at the top of the stem
          ctx.strokeStyle = 'rgba(255,253,238,0.96)'; ctx.lineWidth = 4.5 + r() * 3.5;
          ctx.beginPath(); ctx.moveTo(x + lean, S - h + 4); ctx.lineTo(x + lean * 1.18, S - h - S * 0.085); ctx.stroke();
        }
      }
      const t = new THREE.CanvasTexture(c); t.colorSpace = THREE.SRGBColorSpace;
      t.anisotropy = Math.min(4, renderer.capabilities.getMaxAnisotropy()); return t;
    })();
    function crossCard(w, h) {
      const g = new THREE.BufferGeometry(), p = [], uv = [], nn = [], ix = [];
      for (const a of [0, Math.PI / 2]) {
        const cx = Math.cos(a) * w / 2, cz = Math.sin(a) * w / 2, b = p.length / 3;
        p.push(-cx, 0, -cz, cx, 0, cz, cx, h, cz, -cx, h, -cz);
        uv.push(0, 0, 1, 0, 1, 1, 0, 1);
        for (let i = 0; i < 4; i += 1) nn.push(0, 1, 0);   // lit like the ground it grows out of
        ix.push(b, b + 1, b + 2, b, b + 2, b + 3);
      }
      g.setAttribute('position', new THREE.Float32BufferAttribute(p, 3));
      g.setAttribute('uv', new THREE.Float32BufferAttribute(uv, 2));
      g.setAttribute('normal', new THREE.Float32BufferAttribute(nn, 3));
      g.setIndex(ix); g.computeBoundingSphere();
      return g;
    }
    const cropMat = new THREE.MeshStandardMaterial({ name: 'crop', map: cropTex, alphaTest: 0.42, side: THREE.DoubleSide, roughness: 0.95, metalness: 0 });
    EVO.addFoliageFill(cropMat, 0.2);
    const cropGeo = crossCard(3.2, 1.15), tuftGeo = crossCard(0.85, 0.42);
    const PLOUGH = 3;   // the ploughed-clay entry in the palette grows nothing yet
    const crops = [], tufts = [], col = new THREE.Color();
    const cropStep = coarse ? 9 : 5, cropPer = coarse ? 3 : 5;
    for (const side of [1, -1]) for (let s = -400; s < L + 600; s += cropStep) {
      const f = fr(s), edge = side > 0 ? ROAD_HALF + 19 : SB1 - 19;
      for (let k = 0; k < cropPer; k += 1) {
        // near the road only: a field 200 m out reads as colour, not as stalks
        const d = edge + side * Math.pow(rnd(), 0.8) * 68;
        if (side > 0 && parkAt(s, d, 25)) continue;
        if (fieldIndex(s, d) === PLOUGH) continue;
        if (exits.some((e) => Math.abs(e.ring - s) < 210 && Math.abs(d - RES) < 150)) continue;
        const x = f.x + f.nx * d + (rnd() - 0.5) * 3, z = f.z + f.nz * d + (rnd() - 0.5) * 3;
        const t = fieldTint(s, d);
        crops.push({ x, y: land(x, z) - 0.1, z, yaw: rnd() * Math.PI, s, sc: 0.8 + rnd() * 0.55, color: col.clone().setRGB(t[0] * 0.92, t[1] * 0.92, t[2] * 0.9) });
      }
      // rough grass on the verge itself, right up to the hard strip
      for (let k = 0; k < (coarse ? 2 : 4); k += 1) {
        const d = (side > 0 ? ROAD_HALF + auxWidth(s) : SB1) + side * (1.6 + rnd() * 16);
        if (inWorks(s) && side > 0) continue;
        if (side > 0 && parkAt(s, d, 10)) continue;
        const g = groundAt(s + (rnd() - 0.5) * 3, d);
        const v = 0.62 + rnd() * 0.3;
        tufts.push({ x: g.x, y: g.y - 0.05, z: g.z, yaw: rnd() * Math.PI, s, sc: 0.7 + rnd() * 0.7, color: col.clone().setRGB(v * 0.5, v * 0.72, v * 0.3) });
      }
    }
    instanceProps('crops', cropGeo, cropMat, crops, coarse ? 420 : 620, false);
    instanceProps('verge grass', tuftGeo, cropMat, tufts, coarse ? 150 : 230, false);

    /* --------------------------------------------------- verge furniture */
    // the little white marker posts every 100 m, reflector facing the traffic
    const mpGeo = new THREE.BoxGeometry(0.14, 1.0, 0.06).translate(0, 0.5, 0);
    const mpRefl = new THREE.BoxGeometry(0.1, 0.16, 0.02).translate(0, 0.8, 0.04);
    const posts = [], refls = [];
    for (let s = 100; s < L - 60; s += 100) {
      const d = ROAD_HALF + auxWidth(s) + 2.0;
      if (parkAt(s, d, 8) || M.gantries.some((g) => Math.abs(g.s - s) < 12)) continue;
      const g = groundAt(s, d);
      posts.push({ x: g.x, y: g.y, z: g.z, yaw: g.f.heading, s });
      refls.push({ x: g.x, y: g.y, z: g.z, yaw: g.f.heading + Math.PI, s });
    }
    instanceProps('marker posts', mpGeo, cabinLike, posts, 420, false);
    instanceProps('marker reflectors', mpRefl, barrierRedMat, refls, 380, false);

    /* -------------------------------------------------------- farmsteads */
    // a farmhouse, a big open barn, a grain store and silos round a yard
    const brickMat = mat('farm brick', 0x9d7a63, { roughness: 0.95 });
    const slateMat = mat('farm roof', 0x4b4e52, { roughness: 0.9 });
    const barnMat = mat('barn cladding', 0x53584f, { roughness: 0.85, metalness: 0.2 });
    const siloMat = mat('grain silo', 0xb9bcbb, { roughness: 0.5, metalness: 0.45 });
    const yardMat = mat('farm yard', 0x8b8880, { roughness: 0.98 });
    // a pitched roof as two tilted panels, sitting on the walls where they stand
    function gableRoof(batch, material, w, dp, g0, yaw, base, s, range) {
      const pitch = 0.42, panel = w / 2 / Math.cos(pitch);
      for (const sx of [-1, 1]) {
        const geo = new THREE.BoxGeometry(panel, 0.22, dp).rotateZ(sx * pitch).translate(sx * w / 4, w / 4 * Math.tan(pitch), 0);
        putAt(batch, material, geo, g0.x, g0.y + base, g0.z, yaw, s, range);
      }
    }
    for (const fm of M.farms) {
      const side = fm.side, base = side > 0 ? ROAD_HALF + 108 : SB1 - 108;
      const yaw = rnd() * 0.6 - 0.3;
      const at = (ds, dd) => groundAt(fm.s + ds, base + side * dd);
      const yard = at(0, 0);
      ribbon(surfaces, yardMat, fm.s - 46, fm.s + 46, [base + side * -34, base, base + side * 34].sort((a, b) => (side > 0 ? a - b : b - a)),
        (s, d) => groundAt(s, d).y + 0.04, { step: 12, range: 2600, uv: (s, d) => [s / 5, d / 5] });
      // farmhouse
      const h = at(-30, -14);
      putAt(structures, brickMat, new THREE.BoxGeometry(9, 5.4, 11).translate(0, 2.7, 0), h.x, h.y, h.z, h.f.heading + yaw, fm.s, 2600);
      gableRoof(structures, slateMat, 9.6, 11.4, h, h.f.heading + yaw, 5.4, fm.s, 2600);
      // the big barn and a lower open-fronted shed
      const b1 = at(6, 12);
      putAt(structures, barnMat, new THREE.BoxGeometry(17, 7.2, 34).translate(0, 3.6, 0), b1.x, b1.y, b1.z, b1.f.heading + yaw, fm.s, 2800);
      gableRoof(structures, barnMat, 17.6, 34.4, b1, b1.f.heading + yaw, 7.2, fm.s, 2800);
      const b2 = at(26, -8);
      putAt(structures, barnMat, new THREE.BoxGeometry(12, 5.2, 22).translate(0, 2.6, 0), b2.x, b2.y, b2.z, b2.f.heading + yaw, fm.s, 2600);
      gableRoof(structures, barnMat, 12.5, 22.4, b2, b2.f.heading + yaw, 5.2, fm.s, 2600);
      // silos, a slurry ring and a stack of bales
      for (let k = 0; k < 3; k += 1) {
        const sg = at(-8 + k * 6, 28);
        putAt(structures, siloMat, new THREE.CylinderGeometry(2.1, 2.1, 11, 14).translate(0, 5.5, 0), sg.x, sg.y, sg.z, 0, fm.s, 2800);
        putAt(structures, siloMat, new THREE.ConeGeometry(2.3, 2.2, 14).translate(0, 12, 0), sg.x, sg.y, sg.z, 0, fm.s, 2800);
      }
      const sl = at(34, 22);
      putAt(structures, siloMat, new THREE.CylinderGeometry(9, 9, 3, 20).translate(0, 1.5, 0), sl.x, sl.y, sl.z, 0, fm.s, 2600);
      for (let k = 0; k < 12; k += 1) {
        const bg = at(-40 + (k % 6) * 2.6, 20 + Math.floor(k / 6) * 2.6);
        putAt(structures, gravelMatFarm, new THREE.CylinderGeometry(1.2, 1.2, 2.4, 12).rotateZ(Math.PI / 2), bg.x, bg.y + 1.2, bg.z, bg.f.heading, fm.s, 2200);
      }
    }

    /* ---------------------------------------------- balancing ponds */
    // the motorway's own drainage: a fenced pond in a bunded bowl with reeds
    const waterMat = mat('balancing pond', 0x33474b, { roughness: 0.1, metalness: 0.15 });
    for (const pd of M.ponds) {
      const side = pd.side, base = side > 0 ? ROAD_HALF + 34 : SB1 - 34;
      const g0 = groundAt(pd.s, base);
      const disc = new THREE.CircleGeometry(19, 28).rotateX(-Math.PI / 2);
      putAt(structures, waterMat, disc, g0.x, g0.y - 1.0, g0.z, 0, pd.s, 2000);
      const bund = new THREE.RingGeometry(19, 27, 30).rotateX(-Math.PI / 2);
      putAt(structures, bankMat, bund, g0.x, g0.y - 0.55, g0.z, 0, pd.s, 2000);
      const reeds = [];
      for (let k = 0; k < 90; k += 1) {
        const a = rnd() * Math.PI * 2, r = 16 + rnd() * 4;
        const x = g0.x + Math.cos(a) * r, z = g0.z + Math.sin(a) * r;
        reeds.push({ x, y: g0.y - 0.9, z, yaw: rnd() * Math.PI, s: pd.s, sc: 1.1 + rnd() * 0.9, color: col.clone().setRGB(0.34, 0.44, 0.2) });
      }
      instanceProps('pond reeds', cropGeo, cropMat, reeds, 900, false);
    }

    /* ------------------------------------------------------- livestock */
    // sheep and cattle in the pasture fields, well back from the fence
    const woolGeo = new THREE.SphereGeometry(0.45, 8, 6); woolGeo.scale(1.05, 0.72, 0.64); woolGeo.translate(0, 0.66, 0);
    const cowGeo = new THREE.BoxGeometry(0.85, 0.95, 2.1).translate(0, 0.85, 0);
    const flock = [], herd = [];
    for (const [a, b, side] of (M.pasture || [])) {
      const cattle = ((a / 100) | 0) % 2 === 0;
      for (let k = 0; k < 46; k += 1) {
        const s = a + rnd() * (b - a);
        const d = (side > 0 ? ROAD_HALF + 40 : SB1 - 40) + side * rnd() * 110;
        const f = fr(s), x = f.x + f.nx * d, z = f.z + f.nz * d;
        const item = { x, y: land(x, z) - 0.05, z, yaw: rnd() * Math.PI * 2, s, sc: 0.85 + rnd() * 0.3 };
        if (cattle) { item.color = col.clone().setRGB(rnd() < 0.5 ? 0.06 : 0.3, 0.05, 0.04); herd.push(item); } else flock.push(item);
      }
    }
    instanceProps('sheep', woolGeo, cabinLike, flock, 1200);
    instanceProps('cattle', cowGeo, cattleMat, herd, 1200);

    /* ------------------------------------------------- distant skyline */
    // Milton Keynes reads as a low block of towers across the fields at J14
    if (M.skyline) {
      const sk = M.skyline, town = mat('distant town', 0x8e97a2, { roughness: 0.9 });
      for (let k = 0; k < 34; k += 1) {
        const s = sk.s + (rnd() - 0.5) * sk.spread;
        const d = sk.side * (sk.distance + rnd() * 900) + (sk.side < 0 ? SB1 : 0);
        const f = fr(s), x = f.x + f.nx * d, z = f.z + f.nz * d;
        const w = 30 + rnd() * 60, hh = 14 + Math.pow(rnd(), 2) * 46;
        putAt(structures, town, new THREE.BoxGeometry(w, hh, w * (0.6 + rnd() * 0.8)).translate(0, hh / 2, 0), x, land(x, z), z, rnd(), sk.s, 6000);
      }
    }

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
    // a red X closes the lane below it
    if (aspect === 'X') { ctx.strokeStyle = '#ff2a2a'; ctx.lineWidth = 26; ctx.lineCap = 'round'; for (const [x0, x1] of [[-70, 70], [70, -70]]) { ctx.beginPath(); ctx.moveTo(W / 2 + x0, H / 2 - 70); ctx.lineTo(W / 2 + x1, H / 2 + 70); ctx.stroke(); } return; }
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
      // a closed lane shows a red X; the rest show the limit, or nothing
      const aspectFor = (i) => (g.x && g.x.includes(i) ? 'X' : g.limit ? String(g.limit) : '');
      const sigMat = (a) => signMaterial(signalTexture(a), true);
      LANES.forEach((d, i) => {
        const pp = P(s, d, beamH + 0.45);
        putAt(furniture, signalMat, sigGeo, pp.x, pp.y, pp.z, f.heading, s, 1500);
        const face = new THREE.Mesh(faceGeo(1.3, 1.1), sigMat(aspectFor(i))); face.position.set(pp.x - f.tx * 0.24, pp.y + 0.62, pp.z - f.tz * 0.24); face.rotation.y = Math.atan2(-f.tx, -f.tz);
        face.userData.s = s; face.userData.range = 1500; scene.add(face); chunked.push(face);
      });
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
  /* ---------------------------------------------------- the road works */
  // A mile of long-term smart-motorway works with lane 1 coned off: the
  // advance signs and gantry red X, a cone taper into steel barrier, the
  // closed lane planed down to its base with plant standing on it, a site
  // compound on the verge, and a 50 limit under average speed cameras.
  {
    const YELLOW = '#f5c518', WORKS_INK = '#141414';
    // black-on-yellow is the language of temporary signing in the UK
    const worksAheadTex = signTexture('rw:ahead', 512, 512, (ctx, W, H) => {
      const m = W * 0.04;
      ctx.lineJoin = 'round';
      ctx.fillStyle = '#d0021b'; ctx.beginPath(); ctx.moveTo(W / 2, m); ctx.lineTo(W - m, H - m); ctx.lineTo(m, H - m); ctx.closePath(); ctx.fill();
      ctx.fillStyle = YELLOW; ctx.beginPath(); ctx.moveTo(W / 2, m + 44); ctx.lineTo(W - m - 38, H - m - 24); ctx.lineTo(m + 38, H - m - 24); ctx.closePath(); ctx.fill();
      // the digging workman: a heap, a bent figure and a shovel
      ctx.fillStyle = WORKS_INK;
      ctx.beginPath(); ctx.moveTo(150, 390); ctx.quadraticCurveTo(215, 320, 300, 388); ctx.closePath(); ctx.fill();
      ctx.beginPath(); ctx.arc(268, 205, 27, 0, Math.PI * 2); ctx.fill();
      ctx.lineCap = 'round'; ctx.strokeStyle = WORKS_INK;
      ctx.lineWidth = 30; ctx.beginPath(); ctx.moveTo(268, 236); ctx.lineTo(300, 330); ctx.stroke();
      ctx.lineWidth = 17; ctx.beginPath(); ctx.moveTo(300, 330); ctx.lineTo(322, 388); ctx.stroke();
      ctx.beginPath(); ctx.moveTo(300, 330); ctx.lineTo(255, 386); ctx.stroke();
      ctx.lineWidth = 15; ctx.beginPath(); ctx.moveTo(285, 262); ctx.lineTo(196, 322); ctx.stroke();
      ctx.beginPath(); ctx.moveTo(285, 262); ctx.lineTo(232, 300); ctx.stroke();
      ctx.lineWidth = 13; ctx.beginPath(); ctx.moveTo(360, 236); ctx.lineTo(186, 336); ctx.stroke();
      ctx.beginPath(); ctx.moveTo(172, 318); ctx.lineTo(150, 356); ctx.lineTo(200, 356); ctx.closePath(); ctx.fill();
    });
    const tempLimitTex = (n) => signTexture(`rw:lim${n}`, 320, 320, (ctx, W, H) => {
      ctx.fillStyle = '#f3f3ef'; ctx.beginPath(); ctx.arc(W / 2, H / 2, 150, 0, Math.PI * 2); ctx.fill();
      ctx.strokeStyle = '#d0021b'; ctx.lineWidth = 40; ctx.beginPath(); ctx.arc(W / 2, H / 2, 130, 0, Math.PI * 2); ctx.stroke();
      ctx.fillStyle = '#141414'; ctx.font = font(146); ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.fillText(String(n), W / 2, H / 2 + 6);
    });
    const avgSpeedTex = signTexture('rw:avg', 640, 400, (ctx, W, H) => {
      ctx.fillStyle = YELLOW; ctx.fillRect(0, 0, W, H);
      ctx.strokeStyle = WORKS_INK; ctx.lineWidth = 8; ctx.strokeRect(10, 10, W - 20, H - 20);
      ctx.fillStyle = WORKS_INK;
      // a gantry-mounted camera in silhouette
      ctx.fillRect(150, 100, 200, 76); ctx.fillRect(350, 122, 44, 34);
      ctx.beginPath(); ctx.arc(430, 138, 40, 0, Math.PI * 2); ctx.fill();
      ctx.fillRect(236, 176, 26, 46); ctx.fillRect(196, 218, 108, 20);
      ctx.font = font(52); ctx.textAlign = 'center';
      ctx.fillText('AVERAGE', W / 2, 306); ctx.fillText('SPEED CHECK', W / 2, 364);
    });
    const platesTex = (text) => signTexture(`rw:plate${text}`, 512, 190, (ctx, W, H) => {
      ctx.fillStyle = YELLOW; ctx.fillRect(0, 0, W, H);
      ctx.strokeStyle = WORKS_INK; ctx.lineWidth = 8; ctx.strokeRect(10, 10, W - 20, H - 20);
      ctx.fillStyle = WORKS_INK; ctx.font = font(96); ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.fillText(text, W / 2, H / 2 + 4);
    });
    // the lane-closure diagram: four lanes with the nearside one struck out
    const closureTex = signTexture('rw:closure', 512, 560, (ctx, W, H) => {
      ctx.fillStyle = YELLOW; ctx.fillRect(0, 0, W, H);
      ctx.strokeStyle = WORKS_INK; ctx.lineWidth = 8; ctx.strokeRect(10, 10, W - 20, H - 20);
      ctx.strokeStyle = WORKS_INK; ctx.lineWidth = 20; ctx.lineCap = 'butt';
      for (let k = 0; k < 4; k += 1) {
        const x = 118 + k * 92;
        ctx.beginPath(); ctx.moveTo(x, 470); ctx.lineTo(k === 3 ? x : x, 150); ctx.stroke();
      }
      ctx.lineWidth = 26; ctx.strokeStyle = '#d0021b';
      ctx.beginPath(); ctx.moveTo(78, 190); ctx.lineTo(158, 300); ctx.stroke();
      ctx.beginPath(); ctx.moveTo(158, 190); ctx.lineTo(78, 300); ctx.stroke();
      ctx.fillStyle = WORKS_INK; ctx.font = font(58); ctx.textAlign = 'center';
      ctx.fillText('MERGE RIGHT', W / 2, 528);
    });
    const chevronTex = signTexture('rw:chev', 768, 384, (ctx, W, H) => {
      ctx.fillStyle = '#f3f3ef'; ctx.fillRect(0, 0, W, H);
      ctx.fillStyle = '#d0021b';
      for (let x = -H; x < W; x += 132) { ctx.beginPath(); ctx.moveTo(x, H); ctx.lineTo(x + 66, H); ctx.lineTo(x + 66 + H, 0); ctx.lineTo(x + H, 0); ctx.closePath(); ctx.fill(); }
      ctx.strokeStyle = '#141414'; ctx.lineWidth = 10; ctx.strokeRect(5, 5, W - 10, H - 10);
    });
    const arrowBoardTex = signTexture('rw:arrow', 640, 384, (ctx, W, H) => {
      ctx.fillStyle = '#0a0b0c'; ctx.fillRect(0, 0, W, H);
      // a lamp-matrix arrow telling you to keep right
      ctx.fillStyle = '#ffb61c';
      const dot = (x, y) => { ctx.beginPath(); ctx.arc(x, y, 15, 0, Math.PI * 2); ctx.fill(); };
      for (let k = 0; k < 8; k += 1) dot(120 + k * 46, H / 2);
      for (let k = 1; k <= 4; k += 1) { dot(486 - k * 42, H / 2 - k * 34); dot(486 - k * 42, H / 2 + k * 34); }
      dot(486, H / 2);
    });
    const endWorksTex = signTexture('rw:end', 512, 260, (ctx, W, H) => {
      ctx.fillStyle = YELLOW; ctx.fillRect(0, 0, W, H);
      ctx.strokeStyle = WORKS_INK; ctx.lineWidth = 8; ctx.strokeRect(10, 10, W - 20, H - 20);
      ctx.fillStyle = WORKS_INK; ctx.font = font(74); ctx.textAlign = 'center';
      ctx.fillText('END OF', W / 2, 110); ctx.fillText('ROAD WORKS', W / 2, 196);
    });

    /* -------------------------------------------------- works materials */
    const plantYellow = mat('plant yellow', 0xe8a91b, { roughness: 0.62, metalness: 0.2 });
    const plantOrange = mat('plant orange', 0xd4661d, { roughness: 0.66, metalness: 0.15 });
    const plantDark = mat('plant chassis', 0x2b2e31, { roughness: 0.8 });
    const cabinMat = mat('site cabin', 0xe4e6e3, { roughness: 0.78 });
    const cabinTrim = mat('cabin trim', 0x2f5d3f, { roughness: 0.72 });
    const gravelMat = mat('aggregate', 0x8d8579, { roughness: 1 });
    const meshMat = mat('site fencing', 0x9aa0a4, { roughness: 0.6, metalness: 0.5, transparent: true, opacity: 0.62 });
    const cameraMat = mat('speed camera', 0xd8b21c, { roughness: 0.5, metalness: 0.25 });
    // the closed lane is planed back to a ridged black base
    const planedTex = (() => {
      const c = document.createElement('canvas'); c.width = 256; c.height = 256;
      const ctx = c.getContext('2d'), img = ctx.createImageData(256, 256), d = img.data;
      for (let y = 0; y < 256; y += 1) for (let x = 0; x < 256; x += 1) {
        const groove = 0.5 + 0.5 * Math.sin(x * 0.78 + EVO.noise2(x / 30, y / 90) * 1.4);
        const n = EVO.fbm(x / 22 + 5, y / 22 - 3, 3);
        const v = 0.2 + groove * 0.1 + (n - 0.5) * 0.12;
        const i = (y * 256 + x) * 4; d[i] = v * 255; d[i + 1] = v * 248; d[i + 2] = v * 240; d[i + 3] = 255;
      }
      ctx.putImageData(img, 0, 0);
      const t = new THREE.CanvasTexture(c); t.colorSpace = THREE.SRGBColorSpace; t.wrapS = t.wrapT = THREE.RepeatWrapping;
      t.anisotropy = Math.min(8, renderer.capabilities.getMaxAnisotropy()); return t;
    })();
    const planedMat = mat('planed surface', 0x9a968f, { map: planedTex, roughness: 0.99 });
    addCloudShadow(planedMat);

    /* ------------------------------------------------- the coned line */
    const coneGeo = new THREE.LatheGeometry([new THREE.Vector2(0.065, 0.92), new THREE.Vector2(0.125, 0.48), new THREE.Vector2(0.23, 0.06), new THREE.Vector2(0.255, 0)], 12);
    const coneFoot = new THREE.BoxGeometry(0.55, 0.05, 0.55).translate(0, 0.025, 0);
    const coneList = [], footList = [];
    const barrierFrom = RW.start + RW.taperIn + RW.coneRun, barrierTo = RW.end - RW.taperOut - RW.coneRun;
    for (let s = RW.start; s <= RW.end;) {
      const tapering = s < RW.start + RW.taperIn || s > RW.end - RW.taperOut;
      const c = coneLine(s);
      if (c !== null && !(s > barrierFrom && s < barrierTo)) {
        const p = P(s, c, 0), f = fr(s);
        coneList.push({ x: p.x, y: p.y, z: p.z, yaw: f.heading, s });
        footList.push({ x: p.x, y: p.y, z: p.z, yaw: f.heading, s });
      }
      s += tapering ? 5 : 9;                              // closer through the taper
    }
    instanceProps('works cones', coneGeo, coneMat, coneList, 700);
    instanceProps('cone feet', coneFoot, plantDark, footList, 500, false);
    // steel barrier down the middle of the works, with a reflector every few metres
    {
      const prof = [[-0.24, 0], [-0.24, 0.1], [-0.13, 0.34], [-0.09, 0.95], [0.09, 0.95], [0.13, 0.34], [0.24, 0.1], [0.24, 0]];
      ribbon(furniture, steelMat, barrierFrom, barrierTo, prof.map(([w]) => RW_LINE + w),
        (s, d, f, i) => f.y + RT.crown(RW_LINE) + prof[i][1],
        { step: 5, range: 1200, uv: (s, d) => [s / 2, d] });
      const reflector = new THREE.BoxGeometry(0.06, 0.12, 0.1).translate(0, 0.06, 0);
      const refl = [];
      for (let s = barrierFrom; s < barrierTo; s += 9) { const p = P(s, RW_LINE - 0.1, 0.86), f = fr(s); refl.push({ x: p.x, y: p.y, z: p.z, yaw: f.heading, s }); }
      instanceProps('barrier reflectors', reflector, barrierRedMat, refl, 500, false);
    }
    /* ------------------------------------------- the closed lane itself */
    // planed surface from the taper's end to the run out, with the old
    // carriageway showing through where the works have not reached
    ribbon(surfaces, planedMat, RW.start + RW.taperIn * 0.6, RW.end - RW.taperOut * 0.6,
      () => [RW_LINE + 0.12, (RW_LINE + ROAD_HALF) / 2, ROAD_HALF],
      (s, d, f) => f.y + RT.crown(d) - 0.045,
      { step: 6, range: 1400, uv: (s, d) => [(d - RW_LINE) / 2.2, s / 2.2], tint: (s) => { const t = 0.86 + (EVO.fbm(s / 23, 7, 2) - 0.5) * 0.3; return [t, t, t]; } });

    /* ----------------------------------------------------- plant on site */
    const box = (w, h, dp) => new THREE.BoxGeometry(w, h, dp).translate(0, h / 2, 0);
    const worksRnd = EVO.rng(4820);
    // one tracked excavator, drawn once and dropped along the works
    function excavator(s, d, yaw) {
      for (const sx of [-1, 1]) put(structures, plantDark, box(0.8, 0.78, 4.4), s, d + sx * 1.05, 0, yaw, 900);
      put(structures, plantDark, box(2.7, 0.34, 3.4), s, d, 0.78, yaw, 900);
      put(structures, plantYellow, box(2.5, 1.5, 3.1), s, d, 1.1, yaw, 900);
      put(structures, plantDark, box(2.4, 0.95, 0.8), s - Math.cos(yaw) * 0, d, 1.35, yaw, 900);
      put(structures, plantYellow, box(1.15, 1.65, 1.35), s, d + 0.6, 2.55, yaw, 900);
      put(structures, blackMat, box(1.0, 1.0, 0.12), s, d + 0.6, 3.0, yaw, 700);
      const boom = box(0.46, 4.6, 0.5); boom.rotateX(-1.05); boom.translate(0, 2.2, 1.4);
      put(structures, plantYellow, boom, s, d - 0.55, 1.5, yaw, 900);
      const dipper = box(0.4, 3.2, 0.42); dipper.rotateX(0.75); dipper.translate(0, 1.1, 4.1);
      put(structures, plantYellow, dipper, s, d - 0.55, 3.0, yaw, 900);
      put(structures, plantDark, box(0.9, 0.8, 1.1), s, d - 0.55, 0.15, yaw, 700);
    }
    function dumper(s, d, yaw) {
      for (const sx of [-1, 1]) for (const dz of [-1.5, 1.5]) put(structures, plantDark, new THREE.CylinderGeometry(0.55, 0.55, 0.42, 12).rotateZ(Math.PI / 2).translate(0, 0.55, dz), s, d + sx * 1.1, 0, yaw, 800);
      put(structures, plantDark, box(2.1, 0.5, 4.4), s, d, 0.5, yaw, 800);
      put(structures, plantOrange, box(2.3, 1.0, 2.4), s, d, 1.0, yaw, 800);
      put(structures, plantDark, box(1.5, 1.1, 1.2), s, d, 1.0, yaw, 800);
      put(structures, gravelMat, box(2.0, 0.5, 2.0), s, d, 1.9, yaw, 800);
    }
    function roller(s, d, yaw) {
      for (const dz of [-1.5, 1.5]) put(structures, plantDark, new THREE.CylinderGeometry(0.72, 0.72, 1.9, 16).rotateZ(Math.PI / 2).translate(0, 0.72, dz), s, d, 0, yaw, 800);
      put(structures, plantYellow, box(1.7, 0.9, 3.2), s, d, 0.75, yaw, 800);
      put(structures, plantDark, box(1.2, 1.3, 1.2), s, d, 1.65, yaw, 800);
      put(structures, plantYellow, box(1.6, 0.12, 1.6), s, d, 2.95, yaw, 800);
      for (const sx of [-1, 1]) for (const dz of [-0.6, 0.6]) put(structures, plantDark, box(0.09, 1.05, 0.09), s + dz, d + sx * 0.7, 1.9, yaw, 700);
    }
    function tipper(s, d, yaw) {
      for (const sx of [-1, 1]) for (const dz of [-2.6, 1.3, 2.2]) put(structures, plantDark, new THREE.CylinderGeometry(0.52, 0.52, 0.34, 12).rotateZ(Math.PI / 2).translate(0, 0.52, dz), s, d + sx * 1.1, 0, yaw, 900);
      put(structures, plantDark, box(2.4, 0.45, 7.4), s, d, 0.7, yaw, 900);
      put(structures, plantOrange, box(2.45, 1.9, 2.5), s + 2.2, d, 1.05, yaw, 900);
      put(structures, blackMat, box(2.2, 0.85, 0.12), s + 3.4, d, 2.05, yaw, 700);
      put(structures, plantYellow, box(2.45, 1.5, 4.4), s - 1.4, d, 1.1, yaw, 900);
    }
    // the works run: plant, materials and a compound, all standing in lane 1
    const plantAt = [
      [RW.start + RW.taperIn + 90, 'chevron'], [RW.start + RW.taperIn + 260, 'tipper'],
      [RW.start + RW.taperIn + 430, 'excavator'], [RW.start + RW.taperIn + 620, 'roller'],
      [RW.compound - 120, 'dumper'], [RW.compound + 210, 'excavator'],
      [RW.compound + 430, 'tipper'], [RW.end - RW.taperOut - 300, 'dumper'],
      [RW.end - RW.taperOut - 140, 'roller']
    ];
    const laneD = (RW_LINE + ROAD_HALF) / 2;
    for (const [s, kind] of plantAt) {
      const f = fr(s), yaw = (worksRnd() - 0.5) * 0.16;
      if (kind === 'excavator') excavator(s, laneD + (worksRnd() - 0.5) * 0.8, yaw);
      else if (kind === 'dumper') dumper(s, laneD + (worksRnd() - 0.5) * 0.8, yaw);
      else if (kind === 'roller') roller(s, laneD + (worksRnd() - 0.5) * 0.8, yaw);
      else if (kind === 'tipper') tipper(s, laneD, yaw);
      else if (kind === 'chevron') {
        // the impact protection vehicle at the head of the taper, chevrons out
        tipper(s, laneD, 0);
        const board = new THREE.Mesh(faceGeo(2.4, 1.2), signMaterial(chevronTex));
        const pb = P(s - 3.6, laneD, 2.6); board.position.set(pb.x, pb.y, pb.z); board.rotation.y = Math.atan2(-f.tx, -f.tz);
        board.userData.s = s; board.userData.range = 900; scene.add(board); chunked.push(board);
        const arrow = new THREE.Mesh(faceGeo(2.0, 1.2), signMaterial(arrowBoardTex, true));
        const pa = P(s - 3.7, laneD, 4.0); arrow.position.set(pa.x, pa.y, pa.z); arrow.rotation.y = Math.atan2(-f.tx, -f.tz);
        arrow.userData.s = s; arrow.userData.range = 1100; scene.add(arrow); chunked.push(arrow);
        put(structures, plantDark, box(2.3, 1.7, 0.14), s - 3.5, laneD, 1.6, 0, 900);
      }
    }
    // stacked cones, barrier sections and aggregate down the closed lane
    const stackCone = new THREE.ConeGeometry(0.3, 1.6, 10).translate(0, 0.8, 0);
    const pileGeo = new THREE.ConeGeometry(2.4, 1.7, 12).translate(0, 0.85, 0);
    const stacks = [], piles = [];
    for (let s = RW.start + RW.taperIn + 40; s < RW.end - RW.taperOut - 40; s += 47) {
      const r = worksRnd();
      const d = laneD + (worksRnd() - 0.5) * 1.6;
      if (r < 0.32) { const p = P(s, d, 0), f = fr(s); stacks.push({ x: p.x, y: p.y, z: p.z, yaw: f.heading, s }); }
      else if (r < 0.5) { const p = P(s, d, 0); piles.push({ x: p.x, y: p.y, z: p.z, yaw: worksRnd() * 3, s, sc: 0.6 + worksRnd() * 0.6 }); }
      else if (r < 0.68) put(structures, steelMat, box(0.8, 0.9, 3.6), s, d, 0, 0, 800);
      else if (r < 0.8) put(structures, plantOrange, box(1.2, 1.1, 1.2), s, d, 0, worksRnd(), 700);
    }
    instanceProps('cone stacks', stackCone, coneMat, stacks, 700);
    instanceProps('aggregate piles', pileGeo, gravelMat, piles, 900);

    /* ------------------------------------------------- the site compound */
    {
      const cs = RW.compound, cd = ROAD_HALF + 24;
      // a hardstanding of crushed stone with cabins, plant and a fenced edge
      const pad = [15, 20, 26, 33, 40, 47].map((o) => ROAD_HALF + o);
      ribbon(surfaces, gravelMat, cs - 74, cs + 74, pad, (s, d) => groundAt(s, d).y + 0.05,
        { step: 6, range: 1600, uv: (s, d) => [s / 4, d / 4] });
      const cabinAt = (s, d, w, h, dp, trim) => {
        const gg = groundAt(s, d);
        putAt(structures, cabinMat, box(w, h, dp), gg.x, gg.y + 0.08, gg.z, gg.f.heading, s, 1400);
        putAt(structures, trim ? cabinTrim : blackMat, box(w + 0.06, 0.7, dp * 0.92), gg.x, gg.y + 0.08 + h * 0.5, gg.z, gg.f.heading, s, 1200);
        putAt(structures, cabinTrim, box(w + 0.3, 0.22, dp + 0.3), gg.x, gg.y + 0.08 + h, gg.z, gg.f.heading, s, 1400);
      };
      cabinAt(cs - 34, cd - 5, 3.0, 2.9, 7.2, true);
      cabinAt(cs - 34, cd + 3, 3.0, 2.9, 7.2, false);
      cabinAt(cs - 18, cd - 5, 3.0, 2.9, 9.6, false);
      cabinAt(cs + 2, cd + 2, 3.0, 2.9, 6.0, true);
      // welfare unit, fuel bowser, a generator and a stack of pipes
      const gg = groundAt(cs + 22, cd - 4);
      putAt(structures, plantYellow, box(2.2, 2.0, 3.2), gg.x, gg.y + 0.06, gg.z, gg.f.heading, cs, 1200);
      const bw = groundAt(cs + 30, cd + 3);
      putAt(structures, plantDark, box(1.6, 1.4, 2.4), bw.x, bw.y + 0.06, bw.z, bw.f.heading, cs, 1000);
      for (let k = 0; k < 8; k += 1) {
        const pg = groundAt(cs + 40 + (k % 4) * 1.1, cd + 8 + Math.floor(k / 4) * 1.1);
        putAt(structures, plantDark, new THREE.CylinderGeometry(0.5, 0.5, 6, 12).rotateZ(Math.PI / 2), pg.x, pg.y + 0.6, pg.z, pg.f.heading, cs, 1000);
      }
      // spoil heaps and aggregate bays
      for (let k = 0; k < 5; k += 1) {
        const pg = groundAt(cs - 58 + k * 13, cd + 9 + (k % 2) * 7);
        putAt(structures, gravelMat, pileGeo, pg.x, pg.y + 0.05, pg.z, worksRnd() * 3, cs, 1400, 1.3 + worksRnd() * 0.7);
      }
      // Heras fencing round the compound, and a lighting tower on each corner
      const panel = new THREE.BoxGeometry(0.06, 2.0, 3.4).translate(0, 1.0, 0);
      const fencePts = [], backD = cd + 20;
      for (let t = -74; t <= 74; t += 3.5) fencePts.push([cs + t, backD, true]);
      for (let o = 0; o <= 32; o += 3.5) { fencePts.push([cs - 74, backD - o, false]); fencePts.push([cs + 74, backD - o, false]); }
      const panels = [];
      for (const [ps, pd, along] of fencePts) {
        const pg = groundAt(ps, pd);
        panels.push({ x: pg.x, y: pg.y + 0.04, z: pg.z, yaw: pg.f.heading + (along ? Math.PI / 2 : 0), s: ps });
      }
      instanceProps('site fencing', panel, meshMat, panels, 1100, false);
      for (const [ls, ld] of [[cs - 64, cd - 8], [cs + 62, cd - 8], [cs - 24, cd + 16], [cs + 34, cd + 16]]) {
        const lg = groundAt(ls, ld);
        putAt(structures, plantYellow, box(1.6, 0.5, 2.2), lg.x, lg.y + 0.06, lg.z, lg.f.heading, cs, 1400);
        putAt(structures, steelMat, new THREE.CylinderGeometry(0.11, 0.16, 8.5, 8).translate(0, 4.25, 0), lg.x, lg.y + 0.5, lg.z, lg.f.heading, cs, 1600);
        putAt(structures, blackMat, box(1.9, 0.34, 0.5), lg.x, lg.y + 8.6, lg.z, lg.f.heading, cs, 1600);
      }
    }

    /* --------------------------------------------------------- signing */
    // advance warning, the limit stepping down, average speed cameras
    RW.advance.forEach((s, k) => {
      vergeSign(s, 1, worksAheadTex, 1.5, 1.5, 1.9, false, 4.2);
      vergeSign(s + 26, 1, platesTex(k === 0 ? '1 mile' : '800 yds'), 1.6, 0.6, 1.5, false, 4.2);
    });
    vergeSign(RW.start - 340, 1, closureTex, 1.9, 2.1, 1.7, false, 4.4);
    for (const s of RW.camera) vergeSign(s, 1, avgSpeedTex, 1.7, 1.06, 2.0, false, 4.0);
    // temporary limit repeaters through the works, on both the verge and the reserve
    for (let s = RW.start - 260; s < RW.end - 40; s += 320) {
      vergeSign(s, 1, tempLimitTex(RW.limit), 0.86, 0.86, 1.5, false, 3.4);
      const f = fr(s), pr = P(s, RES + 0.5, 0);
      const g = signPost(pr.x, pr.y, pr.z, Math.atan2(-f.tx, -f.tz), tempLimitTex(RW.limit), 0.86, 0.86, 1.2, false);
      g.userData.s = s; g.userData.range = 900; chunked.push(g);
    }
    vergeSign(RW.end + 70, 1, endWorksTex, 1.6, 0.82, 1.7, false, 3.6);
    vergeSign(RW.end + 96, 1, nslTexture(), 0.86, 0.86, 1.7, false, 3.6);
    // the cameras themselves, on their own gantry-height poles over the works
    for (const s of RW.camera) {
      const f = fr(s), left = ROAD_HALF + 2.2;
      const gp = groundAt(s, left);
      putAt(furniture, gantryMat, new THREE.CylinderGeometry(0.16, 0.2, 8.2, 10).translate(0, 4.1, 0), gp.x, gp.y, gp.z, f.heading, s, 1500);
      const armGeo = new THREE.BoxGeometry(6.4, 0.22, 0.22);
      const pa = P(s, left - 3.2, 8.0);
      putAt(furniture, gantryMat, armGeo, pa.x, pa.y, pa.z, f.heading, s, 1500);
      for (const d of [LANES[1], LANES[3]]) {
        const pc = P(s, d, 7.6);
        putAt(furniture, cameraMat, box(0.45, 0.4, 0.95), pc.x, pc.y, pc.z, f.heading, s, 1500);
        putAt(furniture, blackMat, box(0.3, 0.3, 0.12), pc.x, pc.y + 0.2, pc.z, f.heading, s, 1200);
      }
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
