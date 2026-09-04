import * as THREE from 'three';
/*
 * AVENRÀ EVO · B-ROAD — route, terrain and road plan.
 *
 * The road is a closed loop through rolling pasture, sampled every metre into
 * a Frenet frame (position, tangent, left normal, signed curvature).  All road
 * furniture, boundaries, junctions and markings are planned here from the
 * geometry so the world builder only places what the plan describes.
 */
const EVO = window.EVO;
const { clamp, lerp, smoothstep, mod } = EVO;

const LANE_HALF = 3.0;         // carriageway half width (6.0 m B road)
const ROAD_HALF = 3.1;         // asphalt mesh half width incl. broken edge
const HEDGE_OFFSET = 5.2;      // boundary line from centre
const SAMPLE = 1.0;            // metres between samples

// Loop control points (metres). Roughly 2.4 km with a mix of fast sweepers,
// a tight double bend and two blind crests.
const CONTROL = [
  [0, 0], [110, -18], [230, -6], [330, 48], [392, 150], [388, 262],
  [318, 332], [232, 348], [150, 402], [52, 428], [-58, 402], [-140, 330],
  [-160, 226], [-232, 148], [-282, 52], [-236, -46], [-130, -72], [-52, -36]
];

function terrainBase(x, z) {
  // Rolling Dales pasture: broad undulation plus finer hummocks.
  return EVO.fbm(x / 420 + 3.1, z / 420 - 1.7, 3) * 26 - 13 +
    (EVO.fbm(x / 95 - 2.2, z / 95 + 4.4, 3) - 0.5) * 5.5;
}

function buildRoute() {
  const SCALE = 1.25;
  const pts = CONTROL.map(([x, z]) => new THREE.Vector3(x * SCALE, 0, z * SCALE));
  const curve = new THREE.CatmullRomCurve3(pts, true, 'centripetal', 0.5);
  curve.arcLengthDivisions = 4000;
  const length = curve.getLength();
  const n = Math.round(length / SAMPLE);
  const px = new Float32Array(n), pz = new Float32Array(n), py = new Float32Array(n);
  for (let i = 0; i < n; i += 1) {
    const p = curve.getPointAt(i / n);
    px[i] = p.x; pz[i] = p.z;
  }
  // Elevation: periodic harmonics of the loop length so the loop closes, kept
  // gentle enough for a B road (max ~6 %), plus a touch of the terrain itself.
  for (let i = 0; i < n; i += 1) {
    const s = i * SAMPLE, w = Math.PI * 2 / length;
    py[i] = 4.2 * Math.sin(w * 2 * s + 0.4) + 2.6 * Math.sin(w * 3 * s + 2.1) + 1.4 * Math.sin(w * 7 * s + 1.0) + 0.8 * Math.sin(w * 11 * s + 2.8);
  }
  // Smooth terrain sample blended in so fields and road agree broadly.
  const tBlend = new Float32Array(n);
  for (let i = 0; i < n; i += 1) tBlend[i] = terrainBase(px[i], pz[i]);
  for (let pass = 0; pass < 3; pass += 1) {
    const copy = Float32Array.from(tBlend);
    for (let i = 0; i < n; i += 1) {
      let acc = 0, cnt = 0;
      for (let k = -40; k <= 40; k += 4) { acc += copy[mod(i + k, n)]; cnt += 1; }
      tBlend[i] = acc / cnt;
    }
  }
  for (let i = 0; i < n; i += 1) py[i] = py[i] * 0.55 + tBlend[i] * 0.85;

  const tx = new Float32Array(n), tz = new Float32Array(n), ty = new Float32Array(n);
  const nx = new Float32Array(n), nz = new Float32Array(n);
  const kappa = new Float32Array(n), heading = new Float32Array(n);
  for (let i = 0; i < n; i += 1) {
    const a = mod(i - 1, n), b = mod(i + 1, n);
    let dx = px[b] - px[a], dz = pz[b] - pz[a], dy = py[b] - py[a];
    const hl = Math.hypot(dx, dz); dx /= hl; dz /= hl; dy /= hl;
    tx[i] = dx; tz[i] = dz; ty[i] = dy;
    // left normal (y-up, right-handed): left of travel = (tz, 0, -tx)... check sign below
    nx[i] = dz; nz[i] = -dx;
    heading[i] = Math.atan2(dx, dz);
  }
  // Verify normal is to the left: cross(up, t) = ( -tz? ) up=(0,1,0), t=(tx,0,tz): up×t = (1*tz - 0*0, 0*tx - 0*tz, 0*0 - 1*tx) = (tz, 0, -tx). Left-hand side of travel is up×t. Good.
  for (let i = 0; i < n; i += 1) {
    const a = mod(i - 3, n), b = mod(i + 3, n);
    let dh = heading[b] - heading[a];
    while (dh > Math.PI) dh -= Math.PI * 2;
    while (dh < -Math.PI) dh += Math.PI * 2;
    kappa[i] = dh / (6 * SAMPLE); // >0 = turning left (heading = atan2(x, z) increases anticlockwise seen from above... define left as +)
  }
  // Heading defined as atan2(tx, tz): turning left (towards +normal = (tz,-tx)) — check sign numerically later; consumers use the sign consistently.
  // Smooth curvature a little.
  const ks = Float32Array.from(kappa);
  for (let i = 0; i < n; i += 1) { let acc = 0; for (let k = -4; k <= 4; k += 1) acc += ks[mod(i + k, n)]; kappa[i] = acc / 9; }

  return { curve, length, n, px, py, pz, tx, ty, tz, nx, nz, kappa, heading };
}

const R = buildRoute();

/* Frame at arbitrary distance s (metres, wraps). */
const _frame = { x: 0, y: 0, z: 0, tx: 0, ty: 0, tz: 0, nx: 0, nz: 0, kappa: 0, heading: 0, s: 0, i: 0 };
function frame(s, out = _frame) {
  const f = mod(s / SAMPLE, R.n);
  const i = Math.floor(f), j = (i + 1) % R.n, t = f - i;
  out.s = mod(s, R.length); out.i = i;
  out.x = lerp(R.px[i], R.px[j], t); out.y = lerp(R.py[i], R.py[j], t); out.z = lerp(R.pz[i], R.pz[j], t);
  out.tx = lerp(R.tx[i], R.tx[j], t); out.ty = lerp(R.ty[i], R.ty[j], t); out.tz = lerp(R.tz[i], R.tz[j], t);
  const hl = Math.hypot(out.tx, out.tz); out.tx /= hl; out.tz /= hl;
  out.nx = out.tz; out.nz = -out.tx;
  out.kappa = lerp(R.kappa[i], R.kappa[j], t);
  let h0 = R.heading[i], h1 = R.heading[j];
  if (h1 - h0 > Math.PI) h1 -= Math.PI * 2; else if (h0 - h1 > Math.PI) h1 += Math.PI * 2;
  out.heading = lerp(h0, h1, t);
  return out;
}

/* Position of a point at lateral offset d (+ = left) with road crown. */
function point(s, d, up = 0, out = new THREE.Vector3()) {
  const f = frame(s);
  out.set(f.x + f.nx * d, f.y + crown(d) + up, f.z + f.nz * d);
  return out;
}
function crown(d) { return -0.025 * Math.abs(d); }

/* ------------------------------------------------------------ side roads */
const JUNCTIONS = [
  { s: 700, side: -1, angle: 92, name: 'HAWES 4' },
  { s: 1550, side: 1, angle: 85, name: 'ASKRIGG 3' },
  { s: 2450, side: -1, angle: 98, name: 'BAINBRIDGE 2' }
].map((j) => {
  const f = frame(j.s);
  const a = THREE.MathUtils.degToRad(j.angle) * j.side; // rotate tangent towards side
  // rotate tangent by angle about y: left rotation for side +1
  const cos = Math.cos(a), sin = Math.sin(a);
  // rotating (tx,tz) by angle a about Y (right-handed): x' = tx cos + tz sin, z' = -tx sin + tz cos
  const dx = f.tx * cos + f.tz * sin, dz = -f.tx * sin + f.tz * cos;
  // ensure the direction points to the requested side
  const dot = dx * f.nx + dz * f.nz;
  const sx = dot * j.side > 0 ? dx : -dx, sz = dot * j.side > 0 ? dz : -dz;
  const ox = f.x + f.nx * j.side * LANE_HALF, oz = f.z + f.nz * j.side * LANE_HALF;
  return { ...j, x: ox, y: f.y + crown(LANE_HALF), z: oz, dx: sx, dz: sz, length: 46, halfWidth: 2.5, frame: { ...f } };
});
// Side road centreline point at distance t from the main road edge, offset e (left of side-road travel).
function sidePoint(j, t, e = 0, out = new THREE.Vector3()) {
  const lx = j.dz, lz = -j.dx; // left normal of side road direction
  const y = lerp(j.y, terrainBase(j.x + j.dx * t, j.z + j.dz * t) * 0.85 + 0.6, smoothstep(14, 46, t)) - 0.012 * Math.abs(e);
  out.set(j.x + j.dx * t + lx * e, y, j.z + j.dz * t + lz * e);
  return out;
}
function sideInfluence(x, z) {
  // distance to nearest side-road centreline (only within its length)
  let best = null;
  for (const j of JUNCTIONS) {
    const rx = x - j.x, rz = z - j.z;
    const t = rx * j.dx + rz * j.dz;
    if (t < -2 || t > j.length + 2) continue;
    const lx = j.dz, lz = -j.dx;
    const e = rx * lx + rz * lz;
    const dist = Math.abs(e);
    if (!best || dist < best.dist) best = { j, t: clamp(t, 0, j.length), e, dist, y: sidePoint(j, clamp(t, 0, j.length), 0).y };
  }
  return best;
}

/* -------------------------------------------------- nearest-route lookup */
const CELL = 24;
const grid = new Map();
for (let i = 0; i < R.n; i += 1) {
  const key = `${Math.floor(R.px[i] / CELL)},${Math.floor(R.pz[i] / CELL)}`;
  if (!grid.has(key)) grid.set(key, []);
  grid.get(key).push(i);
}
function nearest(x, z) {
  const cx = Math.floor(x / CELL), cz = Math.floor(z / CELL);
  let bestI = -1, bestD = Infinity;
  for (let a = -1; a <= 1; a += 1) for (let b = -1; b <= 1; b += 1) {
    const list = grid.get(`${cx + a},${cz + b}`);
    if (!list) continue;
    for (const i of list) {
      const dx = x - R.px[i], dz = z - R.pz[i], d2 = dx * dx + dz * dz;
      if (d2 < bestD) { bestD = d2; bestI = i; }
    }
  }
  if (bestI < 0) return null;
  const dx = x - R.px[bestI], dz = z - R.pz[bestI];
  const d = dx * R.nx[bestI] + dz * R.nz[bestI]; // signed lateral (+left)
  const along = dx * R.tx[bestI] + dz * R.tz[bestI];
  return { i: bestI, s: bestI * SAMPLE + along, d, dist: Math.sqrt(bestD), y: R.py[bestI] + R.ty[bestI] * along };
}

/* Terrain height with the road corridor flattened into it. */
function terrainHeight(x, z) {
  const base = terrainBase(x, z) * 0.85 + 0.6;
  const near = nearest(x, z);
  let h = base;
  if (near && near.dist < 36) {
    const w = smoothstep(6, 30, near.dist);
    h = lerp(near.y - 0.9, base, w);
  }
  const side = sideInfluence(x, z);
  if (side && side.dist < 16) {
    const w = smoothstep(3.2, 14, side.dist);
    h = Math.min(h, lerp(side.y - 0.8, h, w));
  }
  return h;
}

/* ------------------------------------------------------------ road plan */
// Bends: local maxima of |curvature| above a threshold.
function findBends() {
  const bends = [];
  const thresh = 1 / 130;
  let i = 0;
  while (i < R.n) {
    if (Math.abs(R.kappa[i]) > thresh) {
      let j = i, peak = i;
      while (j < R.n && Math.abs(R.kappa[j]) > thresh) { if (Math.abs(R.kappa[j]) > Math.abs(R.kappa[peak])) peak = j; j += 1; }
      bends.push({ start: i, end: j, apex: peak, dir: Math.sign(R.kappa[peak]), radius: 1 / Math.abs(R.kappa[peak]) });
      i = j;
    } else i += 1;
  }
  // A bend that straddles the loop seam appears twice: merge it into one.
  if (bends.length > 1 && bends[0].start === 0 && bends[bends.length - 1].end === R.n) {
    const first = bends.shift(), last = bends.pop();
    const apex = Math.abs(R.kappa[first.apex]) > Math.abs(R.kappa[last.apex]) ? first.apex + R.n : last.apex;
    bends.push({ start: last.start, end: R.n + first.end, apex, dir: Math.sign(R.kappa[apex % R.n]), radius: 1 / Math.abs(R.kappa[apex % R.n]) });
  }
  return bends;
}
const BENDS = findBends();

// Boundary sections per side: hedge / wall / fence.
function planBoundaries() {
  const rnd = EVO.rng(2024);
  const sides = {};
  for (const side of [1, -1]) {
    const list = [];
    let s = side === 1 ? 0 : 35;
    let last = 'hedge';
    while (s < R.length) {
      const len = 70 + rnd() * 190;
      let type;
      const r = rnd();
      type = r < 0.5 ? 'hedge' : r < 0.78 ? 'wall' : 'fence';
      if (type === last && rnd() < 0.6) type = type === 'hedge' ? 'wall' : 'hedge';
      list.push({ start: s, end: Math.min(R.length, s + len), type, height: type === 'hedge' ? 1.5 + rnd() * 0.8 : type === 'wall' ? 1.05 + rnd() * 0.2 : 1.1 });
      last = type; s += len;
    }
    sides[side] = list;
  }
  return sides;
}
const BOUNDARIES = planBoundaries();
function boundaryAt(s, side) {
  const ss = mod(s, R.length);
  const list = BOUNDARIES[side];
  for (const b of list) if (ss >= b.start && ss < b.end) return b;
  return list[list.length - 1];
}
function inJunctionMouth(s, side, margin = 9) {
  for (const j of JUNCTIONS) {
    if (j.side !== side) continue;
    let ds = mod(s - j.s + R.length / 2, R.length) - R.length / 2;
    if (Math.abs(ds) < margin) return j;
  }
  return null;
}

EVO.route = {
  R, LANE_HALF, ROAD_HALF, HEDGE_OFFSET, SAMPLE,
  length: R.length, frame, point, crown, terrainBase, terrainHeight, nearest,
  JUNCTIONS, sidePoint, sideInfluence, BENDS, BOUNDARIES, boundaryAt, inJunctionMouth
};
