import * as THREE from 'three';
/*
 * AVENRÀ EVO — coherent road surface and authored places.
 *
 * The surface is one function of (s, d) shared by the geometry, the paint, the
 * tyres and the traffic, so what you see, what you feel and what the cars do
 * all agree. Every feature along the road comes from the selected route's
 * `places` record: a village and humps on the B road, cattle grids and laybys
 * on the moor.
 */
const E = window.EVO, R = E.route;
const { clamp, mod, smoothstep } = E;
const L = R.length;
const ROUTE = E.ROUTE;
const P = ROUTE.places;

// `t` in a place record is a fraction of the loop; `s` is absolute metres.
const at = (p) => (p.t !== undefined ? p.t * L : p.s);
const resolve = (list) => (list || []).map((p) => ({ ...p, s: mod(at(p), L) }));

const village = P.village;
const woodlands = P.woodland || [];
const gates = resolve(P.gates);
const humps = resolve(P.humps);
const covers = resolve(P.covers);
const potholes = resolve(P.potholes).map((p) => ({ ...p, type: 'pothole' }));
const grids = resolve(P.grids).map((g) => ({ ...g, type: 'grid', length: 3.4, height: -0.016 }));
const laybys = resolve(P.laybys);
const lots = resolve(P.lots);
const summit = P.summit ? { ...P.summit, s: mod(at(P.summit), L) } : null;

const strips = [];
for (const s0 of (P.stripAt || [])) for (let i = 0; i < 6; i += 1) strips.push({ s: s0 + i * 1.35, length: 0.34, height: 0.004, type: 'strip' });

const rep = P.repairs;
const rnd = E.rng(rep.seed), repairs = [];
for (let i = 0; i < rep.count; i += 1) {
  const s = mod(rep.from + i * rep.step + rnd() * 15, L);
  if (humps.some((h) => Math.abs(s - h.s) < 9)) continue;
  repairs.push({ s, d: (rnd() < 0.5 ? 1 : -1) * (0.6 + rnd() * 1.75 * (R.LANE_HALF / 3)), length: 0.9 + rnd() * 4.3, width: 0.4 + rnd() * 0.85, seed: i + 80 });
}

const ds = (a, b) => mod(a - b + L / 2, L) - L / 2;
const potholeRadius = (p, s, d) => {
  const a = ds(s, p.s) / (p.length * 0.5), b = (d - p.d) / (p.width * 0.5);
  const angle = Math.atan2(b, a), edge = 0.91 + 0.045 * Math.sin(angle * 5 + p.seed) + 0.04 * Math.cos(angle * 7 - p.seed);
  return Math.hypot(a, b) / edge;
};
R.potholeRadius = potholeRadius;

const inVillage = (s, margin = 0) => {
  if (!village) return false;
  s = mod(s, L);
  return s >= village.start - margin && s <= village.end + margin;
};
const woodland = (s) => { s = mod(s, L); return woodlands.some(([a, b]) => s > a && s < b); };
const clearance = (s, side) => inVillage(s, 8) ||
  gates.some((g) => g.side === side && Math.abs(ds(s, g.s)) < 3.15) ||
  laybys.some((b) => b.side === side && Math.abs(ds(s, b.s)) < b.length / 2 + 4);

// Small longitudinal bins avoid scanning every feature for both wheels each physics step.
const binSize = 16, binCount = Math.ceil(L / binSize), bins = Array.from({ length: binCount }, () => []);
const surfaces = [...humps, ...strips, ...potholes, ...grids, ...covers.map((c) => ({ ...c, type: 'cover', length: 0.66, width: 0.65, height: -0.012 }))];
for (const f of surfaces) {
  const keys = new Set();
  for (let s = f.s - f.length / 2 - 1; s <= f.s + f.length / 2 + binSize + 1; s += binSize) keys.add(Math.floor(mod(s, L) / binSize));
  for (const k of keys) bins[k].push(f);
}
function surfaceAt(s, d) {
  if (Math.abs(d) > R.LANE_HALF + 0.1) return 0;
  let h = 0;
  for (const f of bins[Math.floor(mod(s, L) / binSize)]) {
    const t = ds(s, f.s), a = Math.abs(t), half = f.length / 2;
    if (a >= half) continue;
    if (f.type === 'pothole') {
      const q = potholeRadius(f, s, d); if (q < 1) h += f.height * (1 - smoothstep(0.32, 1, q));
    } else if (f.type === 'cover') {
      const q = Math.abs(d - f.d) / (f.width / 2); if (q >= 1) continue;
      h += f.height * (1 - smoothstep(0.62, 1, q)) * (1 - smoothstep(0.22, half, a));
    } else if (f.type === 'grid') {
      // the deck sits a little below the road, with the bars felt through it
      const lateral = 1 - smoothstep(R.LANE_HALF - 0.25, R.LANE_HALF + 0.05, Math.abs(d));
      h += (f.height + Math.sin(t * 22) * 0.004) * lateral * (1 - smoothstep(half - 0.35, half, a));
    } else {
      const lateral = 1 - smoothstep(R.LANE_HALF - 0.23, R.LANE_HALF - 0.02, Math.abs(d));
      const shape = f.type === 'table' ? 1 - smoothstep(half - 1.2, half, a) : 0.5 + 0.5 * Math.cos(Math.PI * t / half);
      h += f.height * shape * lateral;
    }
  }
  return h;
}
function roughnessAt(s, d) {
  let q = woodland(s) ? 0.32 : inVillage(s) ? 0.11 : 0.18;
  const ss = mod(s, L);
  // Texture character is spatial, not tied to frame rate or elapsed wall-clock time.
  q += 0.17 * E.noise2(ss * 0.11, d * 1.3 + 7);
  for (const f of bins[Math.floor(ss / binSize)]) {
    if (Math.abs(ds(ss, f.s)) >= f.length / 2) continue;
    if (f.type === 'strip') q += 0.9;
    if (f.type === 'grid') q += 1.1;
    if (f.type === 'pothole' && potholeRadius(f, s, d) < 1) q += 0.72;
    if (f.type === 'cover' && Math.abs(d - f.d) < f.width / 2) q += 0.58;
  }
  return clamp(q, 0, 1.6);
}
R.surfaceAt = surfaceAt;
R.roughnessAt = roughnessAt;
R.inVillage = inVillage;
R.woodland = woodland;
R.clearance = clearance;
R.detailPlan = { village, gates, humps, strips, repairs, covers, potholes, lots, grids, laybys, summit };
R.speedLimitAt = (s) => (inVillage(s) ? village.limit : ROUTE.limit); // fictional signed road, not a real surveyed route
R.nextHump = (s, direction = 1) => {
  let best = null;
  for (const h of humps) { const dist = mod((h.s - s) * direction, L); if (!best || dist < best.dist) best = { ...h, dist }; }
  return best;
};
R.nextGrid = (s, direction = 1) => {
  let best = null;
  for (const g of grids) { const dist = mod((g.s - s) * direction, L); if (!best || dist < best.dist) best = { ...g, dist }; }
  return best;
};
// A consistent surface function is shared by geometry, paint, tyres and traffic.
const basePoint = R.point;
R.point = function (s, d, up = 0, out) { const p = basePoint(s, d, up, out); p.y += surfaceAt(s, d); return p; };
R.villageGround = (s, d, h, frame) => {
  if (!village || !inVillage(s, 24) || Math.abs(d) < R.LANE_HALF + 0.02 || Math.abs(d) > 40) return h;
  const longitudinal = smoothstep(village.start - 24, village.start + 5, s) * (1 - smoothstep(village.end - 5, village.end + 24, s));
  const lateral = 1 - smoothstep(26, 40, Math.abs(d));
  return E.lerp(h, frame.y + R.crown(3) + 0.055, longitudinal * lateral);
};
const baseTerrain = R.terrainHeight;
R.terrainHeight = function (x, z) {
  const h = baseTerrain(x, z), near = R.nearest(x, z);
  return near ? R.villageGround(mod(near.s, L), near.d, h, { y: near.y }) : h;
};
// Fix the sub-metre loop seam: sampled route distance and wrap length must agree.
R.sampleDistance = R.length / R.R.n;
E.VERSION = '0.4.0-routes';
