import * as THREE from 'three';
/*
 * AVENRÀ EVO · B-ROAD — core utilities and procedural textures.
 *
 * Everything visual in this simulation except the cockpit photograph is
 * generated at runtime: asphalt, grass, hedge foliage, dry-stone walls, trees,
 * sky and every road sign.  Each generator returns ready-to-use Three.js
 * textures so the world builder never touches a canvas directly.
 */
const EVO = (window.EVO = window.EVO || {});
EVO.THREE = THREE;
EVO.VERSION = '0.1.0';

/* ------------------------------------------------------------------ maths */
const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
const lerp = (a, b, t) => a + (b - a) * t;
const smoothstep = (a, b, x) => { const t = clamp((x - a) / (b - a), 0, 1); return t * t * (3 - 2 * t); };
const mod = (a, n) => ((a % n) + n) % n;
EVO.clamp = clamp; EVO.lerp = lerp; EVO.smoothstep = smoothstep; EVO.mod = mod;

// Deterministic PRNG (mulberry32) so the road is identical on every device.
/* Merge indexed position/normal/uv geometries into one buffer. Used wherever a
 * pile of small primitives should cost a single draw call. */
EVO.mergeGeometries = function mergeGeometries(list) {
  const p = [], n = [], uv = [], idx = [];
  let base = 0;
  for (const g of list) {
    const pa = g.getAttribute('position'), na = g.getAttribute('normal'), ua = g.getAttribute('uv');
    for (let i = 0; i < pa.count; i += 1) { p.push(pa.getX(i), pa.getY(i), pa.getZ(i)); n.push(na.getX(i), na.getY(i), na.getZ(i)); uv.push(ua.getX(i), ua.getY(i)); }
    const ix = g.getIndex(); for (let i = 0; i < ix.count; i += 1) idx.push(ix.getX(i) + base);
    base += pa.count;
  }
  const out = new THREE.BufferGeometry();
  out.setAttribute('position', new THREE.Float32BufferAttribute(p, 3));
  out.setAttribute('normal', new THREE.Float32BufferAttribute(n, 3));
  out.setAttribute('uv', new THREE.Float32BufferAttribute(uv, 2));
  out.setIndex(idx); out.computeBoundingSphere();
  return out;
};

EVO.rng = (seed) => {
  let s = (seed >>> 0) || 1;
  return () => {
    s = (s + 0x6d2b79f5) | 0;
    let t = Math.imul(s ^ (s >>> 15), 1 | s);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
};

function hash2(x, y) {
  const h = Math.sin(x * 127.1 + y * 311.7) * 43758.5453;
  return h - Math.floor(h);
}
EVO.noise2 = (x, y) => {
  const xi = Math.floor(x), yi = Math.floor(y), xf = x - xi, yf = y - yi;
  const u = xf * xf * (3 - 2 * xf), v = yf * yf * (3 - 2 * yf);
  return lerp(lerp(hash2(xi, yi), hash2(xi + 1, yi), u), lerp(hash2(xi, yi + 1), hash2(xi + 1, yi + 1), u), v);
};
EVO.fbm = (x, y, octaves = 4, lacunarity = 2, gain = 0.5) => {
  let a = 0.5, f = 1, sum = 0, norm = 0;
  for (let i = 0; i < octaves; i += 1) {
    sum += a * EVO.noise2(x * f + i * 17.3, y * f - i * 9.1);
    norm += a; a *= gain; f *= lacunarity;
  }
  return sum / norm;
};

/* --------------------------------------------------------------- canvases */
function canvas(w, h) {
  const c = document.createElement('canvas');
  c.width = w; c.height = h;
  return c;
}
function texture(c, { srgb = true, repeat = true, aniso = true } = {}) {
  const t = new THREE.CanvasTexture(c);
  if (srgb) t.colorSpace = THREE.SRGBColorSpace;
  if (repeat) { t.wrapS = THREE.RepeatWrapping; t.wrapT = THREE.RepeatWrapping; }
  t.generateMipmaps = true;
  t.minFilter = THREE.LinearMipmapLinearFilter;
  t.magFilter = THREE.LinearFilter;
  t.userData.aniso = aniso;
  EVO.textures.push(t);
  return t;
}
EVO.textures = [];
EVO.applyAnisotropy = (renderer) => {
  const max = renderer.capabilities.getMaxAnisotropy();
  EVO.textures.forEach((t) => { if (t.userData.aniso) t.anisotropy = Math.min(8, max); });
};

// Normal map from a greyscale height canvas (tileable).
function normalFromHeight(heightData, w, h, strength) {
  const c = canvas(w, h);
  const ctx = c.getContext('2d');
  const out = ctx.createImageData(w, h);
  const d = out.data;
  for (let y = 0; y < h; y += 1) {
    for (let x = 0; x < w; x += 1) {
      const l = heightData[y * w + ((x - 1 + w) % w)], r = heightData[y * w + ((x + 1) % w)];
      const u = heightData[((y - 1 + h) % h) * w + x], b = heightData[((y + 1) % h) * w + x];
      let nx = (l - r) * strength, ny = (u - b) * strength, nz = 1;
      const len = Math.hypot(nx, ny, nz); nx /= len; ny /= len; nz /= len;
      const i = (y * w + x) * 4;
      d[i] = (nx * 0.5 + 0.5) * 255; d[i + 1] = (ny * 0.5 + 0.5) * 255; d[i + 2] = (nz * 0.5 + 0.5) * 255; d[i + 3] = 255;
    }
  }
  ctx.putImageData(out, 0, 0);
  return c;
}

EVO.tex = {};

/* Asphalt: 6.2 m wide road tile that repeats every 6.2 m along the road.
 * Wheel tracks are polished darker, the crown and edges carry loose chippings,
 * and the outer 0.35 m breaks up into grass-bitten edge. */
EVO.tex.asphalt = function asphalt() {
  const S = 1024;
  const c = canvas(S, S), ctx = c.getContext('2d');
  const img = ctx.createImageData(S, S), d = img.data;
  const height = new Float32Array(S * S);
  const rough = new Uint8ClampedArray(S * S);
  const rnd = EVO.rng(101);
  for (let y = 0; y < S; y += 1) {
    for (let x = 0; x < S; x += 1) {
      const u = x / S; // across: 0 = left edge, 1 = right edge
      const across = Math.abs(u - 0.5) * 2; // 0 centre, 1 edge
      // tileable noise: use periodic coordinates
      const px = x / S * 64, py = y / S * 64;
      const grain = EVO.noise2(px * 4, py * 4) * 0.55 + EVO.noise2(px * 9 + 3, py * 9) * 0.3 + EVO.noise2(px * 19, py * 19 + 7) * 0.15;
      const mottle = EVO.fbm(px * 0.35 + 11, py * 0.35, 3);
      // wheel tracks at |d| ≈ 1.05 m and 2.45 m from centre (both lanes)
      const track = Math.max(
        Math.exp(-Math.pow((across - 0.34) / 0.09, 2)),
        Math.exp(-Math.pow((across - 0.79) / 0.08, 2)));
      let base = 0.30 + grain * 0.22 + (mottle - 0.5) * 0.12;
      base -= track * 0.07; // polished tracks are darker and smoother
      base += smoothstep(0.86, 1.0, across) * (EVO.noise2(px * 3, py * 3) - 0.3) * 0.25; // loose broken edge
      // occasional chipping highlights
      const chip = rnd() < 0.012 ? 0.25 * rnd() : 0;
      const v = clamp(base + chip, 0, 1);
      const tint = (mottle - 0.5) * 0.06;
      const i = (y * S + x) * 4;
      d[i] = (v + tint * 0.4) * 255; d[i + 1] = (v + tint * 0.2) * 255; d[i + 2] = (v - tint * 0.3 + 0.01) * 255; d[i + 3] = 255;
      height[y * S + x] = grain * (1 - track * 0.55) + chip * 2;
      rough[y * S + x] = clamp(0.86 - track * 0.22 + (grain - 0.5) * 0.12, 0, 1) * 255;
    }
  }
  ctx.putImageData(img, 0, 0);
  // painted repairs and worn patches ride on the separate wear map below
  const rc = canvas(S, S), rctx = rc.getContext('2d');
  const rimg = rctx.createImageData(S, S);
  for (let i = 0; i < S * S; i += 1) { rimg.data[i * 4] = rough[i]; rimg.data[i * 4 + 1] = rough[i]; rimg.data[i * 4 + 2] = rough[i]; rimg.data[i * 4 + 3] = 255; }
  rctx.putImageData(rimg, 0, 0);
  return {
    map: texture(c),
    normalMap: texture(normalFromHeight(height, S, S, 1.6), { srgb: false }),
    roughnessMap: texture(rc, { srgb: false })
  };
};

/* Large-scale wear map: repeats every ~40 m along the road. Carries patch
 * repairs, crack networks and subtle brightness drift so the 6.2 m asphalt
 * tile never reads as a repeat. */
EVO.tex.roadWear = function roadWear() {
  const W = 256, H = 1024;
  const c = canvas(W, H), ctx = c.getContext('2d');
  ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, W, H);
  const img = ctx.getImageData(0, 0, W, H), d = img.data;
  for (let y = 0; y < H; y += 1) {
    for (let x = 0; x < W; x += 1) {
      const n = EVO.fbm(x / W * 6 + 3, y / H * 24, 3);
      const v = 0.86 + (n - 0.5) * 0.28;
      const i = (y * W + x) * 4; d[i] = d[i + 1] = d[i + 2] = v * 255;
    }
  }
  ctx.putImageData(img, 0, 0);
  const rnd = EVO.rng(77);
  // patch repairs: darker rectangles with soft edges
  for (let k = 0; k < 7; k += 1) {
    const w = 40 + rnd() * 90, h = 60 + rnd() * 200, x = rnd() * (W - w), y = rnd() * (H - h);
    ctx.fillStyle = `rgba(20,22,24,${0.16 + rnd() * 0.16})`;
    ctx.fillRect(x, y, w, h);
    ctx.strokeStyle = 'rgba(10,10,12,0.35)'; ctx.lineWidth = 2; ctx.strokeRect(x + 1, y + 1, w - 2, h - 2);
  }
  // crack networks
  ctx.strokeStyle = 'rgba(8,8,10,0.55)'; ctx.lineWidth = 1.2;
  for (let k = 0; k < 26; k += 1) {
    let x = rnd() * W, y = rnd() * H;
    ctx.beginPath(); ctx.moveTo(x, y);
    for (let j = 0; j < 12; j += 1) { x += (rnd() - 0.5) * 22; y += (rnd() - 0.4) * 22; ctx.lineTo(x, y); }
    ctx.stroke();
  }
  // tar snake sealant along a couple of cracks (glossy dark)
  ctx.strokeStyle = 'rgba(6,6,8,0.9)'; ctx.lineWidth = 3;
  for (let k = 0; k < 4; k += 1) {
    let x = rnd() * W, y = rnd() * H;
    ctx.beginPath(); ctx.moveTo(x, y);
    for (let j = 0; j < 8; j += 1) { x += (rnd() - 0.5) * 30; y += (rnd() - 0.2) * 40; ctx.lineTo(x, y); }
    ctx.stroke();
  }
  return texture(c, { srgb: false });
};

/* Grass: 4 m tile of meadow turf with blade streaks, clover and bare soil. */
EVO.tex.grass = function grass() {
  const S = 512;
  const c = canvas(S, S), ctx = c.getContext('2d');
  const img = ctx.createImageData(S, S), d = img.data;
  const height = new Float32Array(S * S);
  for (let y = 0; y < S; y += 1) {
    for (let x = 0; x < S; x += 1) {
      const px = x / S * 32, py = y / S * 32;
      const clump = EVO.fbm(px * 0.9, py * 0.9, 3);
      const fine = EVO.noise2(px * 6, py * 6) * 0.5 + EVO.noise2(px * 14 + 5, py * 3 + 2) * 0.5;
      const streak = EVO.noise2(px * 3.5, py * 28); // elongated blades
      const soil = smoothstep(0.72, 0.9, EVO.fbm(px * 0.5 + 40, py * 0.5, 2)) * 0.7;
      let r = 0.26 + clump * 0.20 + fine * 0.08;
      let g = 0.34 + clump * 0.22 + fine * 0.10 + streak * 0.07;
      let b = 0.12 + clump * 0.08 + fine * 0.04;
      // dry straw tips
      const straw = smoothstep(0.7, 0.95, streak) * 0.5;
      r = lerp(r, 0.62, straw); g = lerp(g, 0.56, straw); b = lerp(b, 0.28, straw);
      // bare soil
      r = lerp(r, 0.36, soil); g = lerp(g, 0.28, soil); b = lerp(b, 0.18, soil);
      const i = (y * S + x) * 4;
      d[i] = r * 255; d[i + 1] = g * 255; d[i + 2] = b * 255; d[i + 3] = 255;
      height[y * S + x] = fine * 0.6 + streak * 0.4;
    }
  }
  ctx.putImageData(img, 0, 0);
  return { map: texture(c), normalMap: texture(normalFromHeight(height, S, S, 1.2), { srgb: false }) };
};

/* Foliage card for hedgerows: a cluster of hawthorn-style leaves on alpha. */
EVO.tex.hedgeLeaf = function hedgeLeaf() {
  const S = 256;
  const c = canvas(S, S), ctx = c.getContext('2d');
  ctx.clearRect(0, 0, S, S);
  const rnd = EVO.rng(9);
  const leaf = (x, y, r, rot, shade) => {
    ctx.save(); ctx.translate(x, y); ctx.rotate(rot);
    const g = ctx.createRadialGradient(-r * 0.2, -r * 0.3, r * 0.1, 0, 0, r);
    g.addColorStop(0, `rgb(${80 + shade * 90},${125 + shade * 90},${34 + shade * 40})`);
    g.addColorStop(1, `rgb(${42 + shade * 40},${84 + shade * 50},${22 + shade * 20})`);
    ctx.fillStyle = g;
    ctx.beginPath();
    ctx.moveTo(0, -r);
    ctx.bezierCurveTo(r * 0.9, -r * 0.6, r * 0.9, r * 0.5, 0, r);
    ctx.bezierCurveTo(-r * 0.9, r * 0.5, -r * 0.9, -r * 0.6, 0, -r);
    ctx.fill();
    ctx.strokeStyle = 'rgba(20,45,12,0.5)'; ctx.lineWidth = 1; ctx.beginPath(); ctx.moveTo(0, -r * 0.9); ctx.lineTo(0, r * 0.85); ctx.stroke();
    ctx.restore();
  };
  // dense in the middle, sparse at the rim (soft silhouette)
  for (let k = 0; k < 520; k += 1) {
    const a = rnd() * Math.PI * 2, rad = Math.pow(rnd(), 0.55) * S * 0.47;
    const x = S / 2 + Math.cos(a) * rad, y = S / 2 + Math.sin(a) * rad;
    const r = 7 + rnd() * 9;
    leaf(x, y, r, rnd() * Math.PI * 2, rnd() * (1 - rad / (S * 0.5)) * 0.8 + rnd() * 0.2);
  }
  // a few twigs
  ctx.strokeStyle = 'rgba(45,32,18,0.85)'; ctx.lineWidth = 2;
  for (let k = 0; k < 6; k += 1) {
    ctx.beginPath(); ctx.moveTo(S / 2 + (rnd() - 0.5) * 30, S / 2 + (rnd() - 0.5) * 30);
    ctx.lineTo(rnd() * S, rnd() * S); ctx.stroke();
  }
  return texture(c, { repeat: false });
};

/* Solid hedge body: dense leaf mass used on the extruded hedge volume. */
EVO.tex.hedgeBody = function hedgeBody() {
  const S = 512;
  const c = canvas(S, S), ctx = c.getContext('2d');
  ctx.fillStyle = '#24421a'; ctx.fillRect(0, 0, S, S);
  const rnd = EVO.rng(21);
  for (let k = 0; k < 6500; k += 1) {
    const x = rnd() * S, y = rnd() * S, r = 3 + rnd() * 7, sh = rnd();
    ctx.fillStyle = `rgba(${50 + sh * 95},${98 + sh * 105},${26 + sh * 40},0.85)`;
    ctx.beginPath(); ctx.ellipse(x, y, r, r * 0.55, rnd() * Math.PI, 0, Math.PI * 2); ctx.fill();
  }
  // wrap-safe: draw shifted copies for tiling continuity
  const c2 = canvas(S, S), ctx2 = c2.getContext('2d');
  ctx2.drawImage(c, 0, 0); ctx2.globalAlpha = 0.5;
  ctx2.drawImage(c, S / 2, 0); ctx2.drawImage(c, -S / 2, 0); ctx2.drawImage(c, 0, S / 2); ctx2.drawImage(c, 0, -S / 2);
  return texture(c2);
};

/* Dry-stone wall: 1.5 m x 1.2 m tile of random-coursed gritstone with
 * through-stones, pinning chips and lichen. */
EVO.tex.stone = function stone() {
  const W = 512, H = 400;
  const c = canvas(W, H), ctx = c.getContext('2d');
  ctx.fillStyle = '#4a4640'; ctx.fillRect(0, 0, W, H);
  const height = new Float32Array(W * H).fill(0.15);
  const rnd = EVO.rng(33);
  const stones = [];
  let y = 0;
  while (y < H) {
    const rowH = 28 + rnd() * 26;
    let x = -rnd() * 30;
    while (x < W) {
      const w = 26 + rnd() * 62;
      const h = rowH * (0.8 + rnd() * 0.35);
      stones.push({ x: x + 1.5, y: y + 1.5, w: w - 3, h: Math.min(h, H - y) - 3, shade: 0.35 + rnd() * 0.45, warm: rnd() });
      x += w;
    }
    y += rowH;
  }
  stones.forEach((s) => {
    if (s.w < 6 || s.h < 6) return;
    const v = s.shade, warm = s.warm;
    const g = ctx.createLinearGradient(s.x, s.y, s.x + s.w * 0.3, s.y + s.h);
    g.addColorStop(0, `rgb(${118 * v + 52 + warm * 14},${108 * v + 48 + warm * 6},${94 * v + 42})`);
    g.addColorStop(1, `rgb(${84 * v + 34 + warm * 10},${78 * v + 32 + warm * 4},${68 * v + 28})`);
    ctx.fillStyle = g;
    ctx.beginPath();
    // irregular outline: jittered rounded rectangle
    const j = () => (rnd() - 0.5) * 3;
    ctx.moveTo(s.x + 3 + j(), s.y + j());
    ctx.lineTo(s.x + s.w - 3 + j(), s.y + j());
    ctx.quadraticCurveTo(s.x + s.w + j(), s.y + j(), s.x + s.w + j(), s.y + 3 + j());
    ctx.lineTo(s.x + s.w + j(), s.y + s.h - 3 + j());
    ctx.quadraticCurveTo(s.x + s.w + j(), s.y + s.h + j(), s.x + s.w - 3 + j(), s.y + s.h + j());
    ctx.lineTo(s.x + 3 + j(), s.y + s.h + j());
    ctx.quadraticCurveTo(s.x + j(), s.y + s.h + j(), s.x + j(), s.y + s.h - 3 + j());
    ctx.lineTo(s.x + j(), s.y + 3 + j());
    ctx.quadraticCurveTo(s.x + j(), s.y + j(), s.x + 3 + j(), s.y + j());
    ctx.closePath(); ctx.fill();
    // surface grain
    for (let k = 0; k < 14; k += 1) {
      ctx.fillStyle = rnd() < 0.5 ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.08)';
      ctx.beginPath(); ctx.ellipse(s.x + rnd() * s.w, s.y + rnd() * s.h, 2 + rnd() * 6, 1 + rnd() * 3, rnd() * Math.PI, 0, Math.PI * 2); ctx.fill();
    }
    // lichen and moss
    for (let k = 0; k < 3; k += 1) {
      if (rnd() < 0.55) continue;
      ctx.fillStyle = rnd() < 0.6 ? 'rgba(170,175,110,0.28)' : 'rgba(70,110,50,0.3)';
      ctx.beginPath(); ctx.ellipse(s.x + rnd() * s.w, s.y + rnd() * s.h, 3 + rnd() * 9, 2 + rnd() * 6, 0, 0, Math.PI * 2); ctx.fill();
    }
    for (let yy = Math.max(0, Math.floor(s.y)); yy < Math.min(H, s.y + s.h); yy += 1) {
      for (let xx = Math.max(0, Math.floor(s.x)); xx < Math.min(W, s.x + s.w); xx += 1) {
        const ex = Math.min(xx - s.x, s.x + s.w - xx), ey = Math.min(yy - s.y, s.y + s.h - yy);
        const edge = smoothstep(0, 4, Math.min(ex, ey));
        height[yy * W + xx] = 0.45 + 0.55 * edge * (0.75 + EVO.noise2(xx * 0.25, yy * 0.25) * 0.5);
      }
    }
  });
  // pinning chips in the joints
  for (let k = 0; k < 90; k += 1) {
    ctx.fillStyle = `rgba(${60 + rnd() * 50},${55 + rnd() * 45},${50 + rnd() * 40},0.9)`;
    ctx.beginPath(); ctx.ellipse(rnd() * W, rnd() * H, 2 + rnd() * 4, 1.5 + rnd() * 2.5, rnd() * Math.PI, 0, Math.PI * 2); ctx.fill();
  }
  return { map: texture(c), normalMap: texture(normalFromHeight(height, W, H, 2.4), { srgb: false }) };
};

/* Tree billboard: broadleaf canopy with trunk, 1:1.4 aspect. */
EVO.tex.tree = function tree(seed = 5) {
  const W = 512, H = 720;
  const c = canvas(W, H), ctx = c.getContext('2d');
  ctx.clearRect(0, 0, W, H);
  const rnd = EVO.rng(seed);
  // trunk
  ctx.strokeStyle = '#4a3b2a'; ctx.lineCap = 'round';
  ctx.lineWidth = 34; ctx.beginPath(); ctx.moveTo(W / 2, H); ctx.lineTo(W / 2 + 10, H * 0.55); ctx.stroke();
  ctx.lineWidth = 14;
  for (let k = 0; k < 6; k += 1) {
    ctx.beginPath(); ctx.moveTo(W / 2 + 6, H * (0.58 + rnd() * 0.1));
    ctx.lineTo(W / 2 + (rnd() - 0.5) * W * 0.7, H * (0.2 + rnd() * 0.3)); ctx.stroke();
  }
  // canopy clusters
  const clusters = 26;
  for (let k = 0; k < clusters; k += 1) {
    const cx = W / 2 + (rnd() - 0.5) * W * 0.78, cy = H * 0.36 + (rnd() - 0.5) * H * 0.5;
    const r = 55 + rnd() * 70;
    const lit = clamp(1 - (cy / H) * 1.2 + rnd() * 0.3, 0, 1);
    for (let j = 0; j < 90; j += 1) {
      const a = rnd() * Math.PI * 2, rr = Math.sqrt(rnd()) * r;
      const x = cx + Math.cos(a) * rr, y = cy + Math.sin(a) * rr * 0.85;
      const s = 0.4 + lit * 0.6 * (1 - rr / r);
      ctx.fillStyle = `rgba(${30 + s * 90},${60 + s * 110},${18 + s * 34},0.92)`;
      ctx.beginPath(); ctx.ellipse(x, y, 6 + rnd() * 8, 4 + rnd() * 6, rnd() * Math.PI, 0, Math.PI * 2); ctx.fill();
    }
  }
  return texture(c, { repeat: false });
};

/* Single grass blade card for near-verge instancing. */
EVO.tex.blade = function blade() {
  const W = 64, H = 128;
  const c = canvas(W, H), ctx = c.getContext('2d');
  ctx.clearRect(0, 0, W, H);
  const rnd = EVO.rng(3);
  for (let k = 0; k < 5; k += 1) {
    const x0 = 12 + rnd() * 40, w = 5 + rnd() * 6, lean = (rnd() - 0.5) * 30;
    const g = ctx.createLinearGradient(0, H, 0, 0);
    g.addColorStop(0, '#3f6a22'); g.addColorStop(0.65, '#7aa23a'); g.addColorStop(1, '#c2c96a');
    ctx.fillStyle = g;
    ctx.beginPath(); ctx.moveTo(x0 - w, H); ctx.quadraticCurveTo(x0 + lean * 0.5, H * 0.5, x0 + lean, 6); ctx.quadraticCurveTo(x0 + lean * 0.5 + 2, H * 0.5, x0 + w, H); ctx.fill();
  }
  return texture(c, { repeat: false });
};

/* Traffic cone wrap: orange with a white reflective sleeve (UK 750 mm cone). */
EVO.tex.cone = function cone() {
  const c = canvas(64, 256), ctx = c.getContext('2d');
  ctx.fillStyle = '#f2621a'; ctx.fillRect(0, 0, 64, 256);
  ctx.fillStyle = '#e9e9e4'; ctx.fillRect(0, 70, 64, 62);
  ctx.fillStyle = 'rgba(0,0,0,0.12)'; ctx.fillRect(0, 128, 64, 6);
  const g = ctx.createLinearGradient(0, 0, 64, 0);
  g.addColorStop(0, 'rgba(0,0,0,0.28)'); g.addColorStop(0.35, 'rgba(255,255,255,0.08)'); g.addColorStop(1, 'rgba(0,0,0,0.3)');
  ctx.fillStyle = g; ctx.fillRect(0, 0, 64, 256);
  return texture(c);
};

/* ----------------------------------------------------------------- signs */
function signCanvas(w, h) { const c = canvas(w, h); return [c, c.getContext('2d')]; }
function triangleFace(ctx, S, draw) {
  // warning triangle: red border, white face
  const m = S * 0.04;
  ctx.clearRect(0, 0, S, S);
  ctx.lineJoin = 'round';
  ctx.fillStyle = '#d0021b'; ctx.beginPath();
  ctx.moveTo(S / 2, m); ctx.lineTo(S - m, S - m); ctx.lineTo(m, S - m); ctx.closePath(); ctx.fill();
  const b = S * 0.085;
  ctx.fillStyle = '#f3f3ef'; ctx.beginPath();
  ctx.moveTo(S / 2, m + b * 1.5); ctx.lineTo(S - m - b * 1.3, S - m - b * 0.75); ctx.lineTo(m + b * 1.3, S - m - b * 0.75); ctx.closePath(); ctx.fill();
  ctx.fillStyle = '#111'; ctx.strokeStyle = '#111';
  draw(ctx, S);
}
EVO.tex.signBend = function signBend(dir) { // dir +1 left, -1 right
  const [c, ctx] = signCanvas(256, 256);
  triangleFace(ctx, 256, (g, S) => {
    g.save(); g.translate(S / 2, S * 0.62); g.scale(dir, 1);
    g.lineWidth = 16; g.lineCap = 'butt';
    g.beginPath(); g.moveTo(-6, 58); g.lineTo(-6, 10); g.quadraticCurveTo(-6, -22, -34, -22); g.stroke();
    g.beginPath(); g.moveTo(-34, -40); g.lineTo(-60, -22); g.lineTo(-34, -4); g.closePath(); g.fill();
    g.restore();
  });
  return texture(c, { repeat: false });
};
EVO.tex.signJunction = function signJunction(side) { // side +1 road joins from left, -1 from right
  const [c, ctx] = signCanvas(256, 256);
  triangleFace(ctx, 256, (g, S) => {
    g.lineWidth = 15;
    g.beginPath(); g.moveTo(S / 2, S * 0.85); g.lineTo(S / 2, S * 0.33); g.stroke();
    g.lineWidth = 11;
    g.beginPath(); g.moveTo(S / 2, S * 0.6); g.lineTo(S / 2 - side * 44, S * 0.6); g.stroke();
  });
  return texture(c, { repeat: false });
};
EVO.tex.signChevron = function signChevron(dir) {
  const [c, ctx] = signCanvas(512, 256);
  ctx.fillStyle = '#f3f3ef'; ctx.fillRect(0, 0, 512, 256);
  ctx.strokeStyle = '#111'; ctx.lineWidth = 6; ctx.strokeRect(3, 3, 506, 250);
  ctx.fillStyle = '#111';
  for (let k = 0; k < 3; k += 1) {
    const x = 100 + k * 156;
    ctx.save(); ctx.translate(x, 128); ctx.scale(dir, 1);
    ctx.beginPath(); ctx.moveTo(38, -96); ctx.lineTo(-30, 0); ctx.lineTo(38, 96); ctx.lineTo(-2, 96); ctx.lineTo(-70, 0); ctx.lineTo(-2, -96); ctx.closePath(); ctx.fill();
    ctx.restore();
  }
  return texture(c, { repeat: false });
};
EVO.tex.signNSL = function signNSL() {
  const [c, ctx] = signCanvas(256, 256);
  ctx.clearRect(0, 0, 256, 256);
  ctx.fillStyle = '#f3f3ef'; ctx.beginPath(); ctx.arc(128, 128, 122, 0, Math.PI * 2); ctx.fill();
  ctx.strokeStyle = '#111'; ctx.lineWidth = 3; ctx.beginPath(); ctx.arc(128, 128, 121, 0, Math.PI * 2); ctx.stroke();
  ctx.strokeStyle = '#111'; ctx.lineWidth = 28; ctx.beginPath(); ctx.moveTo(210, 46); ctx.lineTo(46, 210); ctx.stroke();
  return texture(c, { repeat: false });
};
EVO.tex.signGiveWay = function signGiveWay() {
  const [c, ctx] = signCanvas(256, 256);
  ctx.clearRect(0, 0, 256, 256);
  ctx.lineJoin = 'round';
  ctx.fillStyle = '#d0021b'; ctx.beginPath(); ctx.moveTo(8, 20); ctx.lineTo(248, 20); ctx.lineTo(128, 240); ctx.closePath(); ctx.fill();
  ctx.fillStyle = '#f3f3ef'; ctx.beginPath(); ctx.moveTo(36, 36); ctx.lineTo(220, 36); ctx.lineTo(128, 200); ctx.closePath(); ctx.fill();
  ctx.fillStyle = '#111'; ctx.font = 'bold 30px Arial, Helvetica, sans-serif'; ctx.textAlign = 'center';
  ctx.fillText('GIVE', 128, 78); ctx.fillText('WAY', 128, 112);
  return texture(c, { repeat: false });
};
EVO.tex.signRoadClosed = function signRoadClosed() {
  const [c, ctx] = signCanvas(512, 192);
  ctx.fillStyle = '#d0021b'; ctx.fillRect(0, 0, 512, 192);
  ctx.fillStyle = '#f3f3ef'; ctx.fillRect(14, 14, 484, 164);
  ctx.fillStyle = '#111'; ctx.font = 'bold 96px Arial, Helvetica, sans-serif'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
  ctx.fillText('ROAD CLOSED', 256, 100);
  return texture(c, { repeat: false });
};
EVO.tex.signNamePlate = function signNamePlate(text) {
  const [c, ctx] = signCanvas(512, 160);
  ctx.fillStyle = '#f3f3ef'; ctx.fillRect(0, 0, 512, 160);
  ctx.strokeStyle = '#111'; ctx.lineWidth = 6; ctx.strokeRect(3, 3, 506, 154);
  ctx.fillStyle = '#111'; ctx.font = 'bold 62px Arial, Helvetica, sans-serif'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
  ctx.fillText(text, 256, 84);
  return texture(c, { repeat: false });
};
EVO.tex.slowLegend = function slowLegend() {
  const [c, ctx] = signCanvas(256, 512);
  ctx.clearRect(0, 0, 256, 512);
  ctx.fillStyle = '#ece9df'; ctx.font = 'bold 118px Arial, Helvetica, sans-serif'; ctx.textAlign = 'center';
  ctx.save(); ctx.translate(128, 0); ctx.scale(1.0, 3.6);
  ctx.fillText('SLOW', 0, 118);
  ctx.restore();
  return texture(c, { repeat: false });
};
EVO.tex.giveWayTriangle = function giveWayTriangle() {
  const [c, ctx] = signCanvas(128, 256);
  ctx.clearRect(0, 0, 128, 256);
  ctx.fillStyle = '#ece9df'; ctx.beginPath(); ctx.moveTo(8, 8); ctx.lineTo(120, 8); ctx.lineTo(64, 250); ctx.closePath(); ctx.fill();
  ctx.clearRect(0, 0, 0, 0);
  ctx.globalCompositeOperation = 'destination-out';
  ctx.beginPath(); ctx.moveTo(36, 34); ctx.lineTo(92, 34); ctx.lineTo(64, 170); ctx.closePath(); ctx.fill();
  return texture(c, { repeat: false });
};

/* ======================================================================
 * Vegetation and vehicle detail textures (scenery upgrade)
 * ====================================================================== */

// Species leaf outline drawn at the origin, pointing +y, length ~1.
function leafPath(ctx, species, r) {
  ctx.beginPath();
  if (species === 'oak') {
    // lobed oak leaf: radius varies around the outline
    for (let i = 0; i <= 40; i += 1) {
      const t = i / 40 * Math.PI * 2;
      const rr = r * (0.55 + 0.45 * Math.abs(Math.sin(t))) * (0.82 + 0.18 * Math.abs(Math.sin(t * 3.5)));
      const x = Math.sin(t) * rr * 0.62, y = -Math.cos(t) * rr;
      if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
    }
  } else if (species === 'hawthorn') {
    // small three-lobed leaf
    ctx.moveTo(0, -r);
    ctx.quadraticCurveTo(r * 0.55, -r * 0.7, r * 0.3, -r * 0.3);
    ctx.quadraticCurveTo(r * 0.7, -r * 0.1, r * 0.25, r * 0.4);
    ctx.quadraticCurveTo(r * 0.1, r * 0.7, 0, r);
    ctx.quadraticCurveTo(-r * 0.1, r * 0.7, -r * 0.25, r * 0.4);
    ctx.quadraticCurveTo(-r * 0.7, -r * 0.1, -r * 0.3, -r * 0.3);
    ctx.quadraticCurveTo(-r * 0.55, -r * 0.7, 0, -r);
  } else if (species === 'ash') {
    // narrow pointed leaflet
    ctx.moveTo(0, -r);
    ctx.bezierCurveTo(r * 0.42, -r * 0.6, r * 0.42, r * 0.5, 0, r);
    ctx.bezierCurveTo(-r * 0.42, r * 0.5, -r * 0.42, -r * 0.6, 0, -r);
  } else {
    // beech / generic oval
    ctx.moveTo(0, -r);
    ctx.bezierCurveTo(r * 0.7, -r * 0.6, r * 0.7, r * 0.6, 0, r);
    ctx.bezierCurveTo(-r * 0.7, r * 0.6, -r * 0.7, -r * 0.6, 0, -r);
  }
  ctx.closePath();
}

/* Leaf cluster card: a dense clump of individually shaded leaves with a
 * matching normal map, used for tree canopies and hedge foliage. */
EVO.tex.leafCluster = function leafCluster(species = 'oak', seed = 11) {
  const S = 512;
  const c = canvas(S, S), ctx = c.getContext('2d');
  const nc = canvas(S, S), nctx = nc.getContext('2d');
  ctx.clearRect(0, 0, S, S); nctx.clearRect(0, 0, S, S);
  const rnd = EVO.rng(seed);
  const base = { oak: [62, 96, 34], hawthorn: [58, 100, 38], ash: [78, 116, 46], beech: [70, 118, 40] }[species] || [64, 100, 36];
  const size = { oak: 22, hawthorn: 11, ash: 17, beech: 20 }[species] || 18;
  const count = { oak: 240, hawthorn: 620, ash: 380, beech: 260 }[species] || 260;
  // twigs first (behind the leaves)
  nctx.fillStyle = 'rgb(128,128,255)';
  ctx.strokeStyle = 'rgba(58,44,30,0.9)'; ctx.lineWidth = 3; ctx.lineCap = 'round';
  for (let k = 0; k < 9; k += 1) {
    const a = rnd() * Math.PI * 2;
    ctx.beginPath(); ctx.moveTo(S / 2 + (rnd() - 0.5) * 40, S / 2 + (rnd() - 0.5) * 40);
    ctx.quadraticCurveTo(S / 2 + Math.cos(a) * S * 0.25, S / 2 + Math.sin(a) * S * 0.25 + 20, S / 2 + Math.cos(a) * S * 0.46, S / 2 + Math.sin(a) * S * 0.46);
    ctx.stroke();
    nctx.strokeStyle = 'rgb(128,128,255)'; nctx.lineWidth = 3; nctx.beginPath(); nctx.moveTo(S / 2, S / 2); nctx.lineTo(S / 2 + Math.cos(a) * S * 0.46, S / 2 + Math.sin(a) * S * 0.46); nctx.stroke();
  }
  // leaves sorted back to front so the front ones are brightest
  const leaves = [];
  for (let k = 0; k < count; k += 1) {
    const a = rnd() * Math.PI * 2, rad = Math.pow(rnd(), 0.6) * S * 0.46;
    leaves.push({ x: S / 2 + Math.cos(a) * rad, y: S / 2 + Math.sin(a) * rad * 0.94, depth: rnd(), rot: rnd() * Math.PI * 2, r: size * (0.7 + rnd() * 0.6), edge: rad / (S * 0.46) });
  }
  leaves.sort((p, q) => p.depth - q.depth);
  for (const L of leaves) {
    const top = 1 - L.y / S; // leaves higher on the card catch more sun
    const shade = 0.42 + 0.58 * L.depth * (0.75 + 0.25 * top);
    const warm = rnd() * 0.25;
    const rC = base[0] * shade * (1 + warm * 0.9), gC = base[1] * shade * (1 + warm * 0.35), bC = base[2] * shade * (1 - warm * 0.3);
    ctx.save(); ctx.translate(L.x, L.y); ctx.rotate(L.rot);
    const g = ctx.createLinearGradient(-L.r * 0.5, 0, L.r * 0.5, 0);
    g.addColorStop(0, `rgb(${rC * 0.78},${gC * 0.82},${bC * 0.8})`);
    g.addColorStop(0.5, `rgb(${rC},${gC},${bC})`);
    g.addColorStop(1, `rgb(${rC * 1.12},${gC * 1.1},${bC * 0.95})`);
    ctx.fillStyle = g;
    leafPath(ctx, species, L.r); ctx.fill();
    // midrib
    ctx.strokeStyle = `rgba(${rC * 1.3},${gC * 1.25},${bC * 1.1},0.55)`; ctx.lineWidth = Math.max(1, L.r * 0.06);
    ctx.beginPath(); ctx.moveTo(0, -L.r * 0.85); ctx.lineTo(0, L.r * 0.8); ctx.stroke();
    ctx.restore();
    // normal: each leaf tilts a little differently
    const nx = (rnd() - 0.5) * 0.9, ny = (rnd() - 0.5) * 0.9, nz = Math.sqrt(Math.max(0.2, 1 - nx * nx - ny * ny));
    nctx.save(); nctx.translate(L.x, L.y); nctx.rotate(L.rot);
    nctx.fillStyle = `rgb(${(nx * 0.5 + 0.5) * 255},${(ny * 0.5 + 0.5) * 255},${(nz * 0.5 + 0.5) * 255})`;
    leafPath(nctx, species, L.r); nctx.fill();
    nctx.restore();
  }
  return { map: texture(c, { repeat: false }), normalMap: texture(nc, { repeat: false, srgb: false }) };
};

/* Bark: vertical fissured trunk texture, 1 m x 2 m tile. */
EVO.tex.bark = function bark() {
  const W = 256, H = 512;
  const c = canvas(W, H), ctx = c.getContext('2d');
  const img = ctx.createImageData(W, H), d = img.data;
  const height = new Float32Array(W * H);
  for (let y = 0; y < H; y += 1) {
    for (let x = 0; x < W; x += 1) {
      const px = x / W * 16, py = y / H * 32;
      const ridge = EVO.noise2(px * 1.6, py * 0.25) * 0.6 + EVO.noise2(px * 4 + 3, py * 0.8) * 0.3 + EVO.noise2(px * 12, py * 3) * 0.1;
      const fissure = smoothstep(0.35, 0.5, ridge);
      const v = 0.22 + fissure * 0.3 + EVO.noise2(px * 30, py * 30) * 0.08;
      const i = (y * W + x) * 4;
      d[i] = v * 255 * 1.05; d[i + 1] = v * 255 * 0.86; d[i + 2] = v * 255 * 0.68; d[i + 3] = 255;
      height[y * W + x] = ridge;
    }
  }
  ctx.putImageData(img, 0, 0);
  return { map: texture(c), normalMap: texture(normalFromHeight(height, W, H, 3), { srgb: false }) };
};

/* Hedge body: dense hawthorn mass with a bumpy normal map, 2 m tile. */
EVO.tex.hedgeBody = function hedgeBody() {
  const S = 1024;
  const c = canvas(S, S), ctx = c.getContext('2d');
  const nc = canvas(S, S), nctx = nc.getContext('2d');
  ctx.fillStyle = '#1b3212'; ctx.fillRect(0, 0, S, S);
  nctx.fillStyle = 'rgb(128,128,255)'; nctx.fillRect(0, 0, S, S);
  const rnd = EVO.rng(21);
  const blobs = [];
  for (let k = 0; k < 900; k += 1) blobs.push({ x: rnd() * S, y: rnd() * S, r: 18 + rnd() * 46, depth: rnd() });
  blobs.sort((a, b) => a.depth - b.depth);
  // leaf clumps: darker deep, brighter proud; each clump gets a dome normal
  for (const b of blobs) {
    const shade = 0.45 + b.depth * 0.6;
    for (let k = 0; k < 26; k += 1) {
      const a = rnd() * Math.PI * 2, rr = Math.sqrt(rnd()) * b.r;
      const x = b.x + Math.cos(a) * rr, y = b.y + Math.sin(a) * rr;
      const s = shade * (0.85 + rnd() * 0.3);
      ctx.fillStyle = `rgb(${52 * s + 10},${102 * s + 12},${34 * s + 6})`;
      ctx.save(); ctx.translate(x % S, y % S); ctx.rotate(rnd() * Math.PI * 2);
      leafPath(ctx, 'hawthorn', 5 + rnd() * 5); ctx.fill(); ctx.restore();
      const nx = (x - b.x) / b.r * 0.7, ny = (y - b.y) / b.r * 0.7;
      nctx.fillStyle = `rgb(${(nx * 0.5 + 0.5) * 255},${(-ny * 0.5 + 0.5) * 255},${(Math.sqrt(Math.max(0.15, 1 - nx * nx - ny * ny)) * 0.5 + 0.5) * 255})`;
      nctx.save(); nctx.translate(x % S, y % S); nctx.rotate(rnd() * Math.PI * 2);
      leafPath(nctx, 'hawthorn', 5 + rnd() * 5); nctx.fill(); nctx.restore();
    }
  }
  return { map: texture(c), normalMap: texture(nc, { srgb: false }) };
};

/* Cow parsley / hogweed card for the verges: white umbels on green stems. */
EVO.tex.umbel = function umbel() {
  const S = 256;
  const c = canvas(S, S), ctx = c.getContext('2d');
  ctx.clearRect(0, 0, S, S);
  const rnd = EVO.rng(61);
  for (let k = 0; k < 5; k += 1) {
    const x0 = 40 + rnd() * 176, top = 30 + rnd() * 60;
    ctx.strokeStyle = 'rgba(96,128,52,0.95)'; ctx.lineWidth = 2.2;
    ctx.beginPath(); ctx.moveTo(x0 + (rnd() - 0.5) * 30, S); ctx.quadraticCurveTo(x0, S * 0.6, x0, top + 20); ctx.stroke();
    // umbel: spokes then florets
    for (let s = 0; s < 9; s += 1) {
      const a = -Math.PI * (0.15 + 0.7 * s / 8), len = 18 + rnd() * 10;
      const ex = x0 + Math.cos(a) * len, ey = top + 20 + Math.sin(a) * len * 0.55;
      ctx.strokeStyle = 'rgba(110,140,60,0.9)'; ctx.lineWidth = 1.2; ctx.beginPath(); ctx.moveTo(x0, top + 20); ctx.lineTo(ex, ey); ctx.stroke();
      for (let f = 0; f < 7; f += 1) {
        ctx.fillStyle = rnd() < 0.8 ? 'rgba(246,246,236,0.95)' : 'rgba(228,232,210,0.9)';
        ctx.beginPath(); ctx.arc(ex + (rnd() - 0.5) * 9, ey + (rnd() - 0.5) * 6, 1.6 + rnd() * 1.3, 0, Math.PI * 2); ctx.fill();
      }
    }
    // a few fern-like leaves low down
    ctx.strokeStyle = 'rgba(84,124,48,0.9)'; ctx.lineWidth = 1.5;
    for (let l = 0; l < 4; l += 1) { const y = S * 0.62 + rnd() * S * 0.3; ctx.beginPath(); ctx.moveTo(x0, y); ctx.lineTo(x0 + (rnd() - 0.5) * 60, y - 10 - rnd() * 16); ctx.stroke(); }
  }
  return texture(c, { repeat: false });
};

/* Alloy wheel face: five twin spokes, hub cap, tyre sidewall ring. */
EVO.tex.alloy = function alloy() {
  const S = 256;
  const c = canvas(S, S), ctx = c.getContext('2d');
  ctx.clearRect(0, 0, S, S);
  const cx = S / 2, cy = S / 2;
  ctx.fillStyle = '#17181a'; ctx.beginPath(); ctx.arc(cx, cy, 126, 0, Math.PI * 2); ctx.fill(); // tyre sidewall
  ctx.fillStyle = '#2a2c30'; ctx.beginPath(); ctx.arc(cx, cy, 88, 0, Math.PI * 2); ctx.fill(); // rim well
  ctx.fillStyle = '#c9ccd1';
  for (let s = 0; s < 5; s += 1) {
    ctx.save(); ctx.translate(cx, cy); ctx.rotate(s / 5 * Math.PI * 2);
    ctx.beginPath(); ctx.moveTo(-9, 0); ctx.lineTo(-13, -84); ctx.lineTo(13, -84); ctx.lineTo(9, 0); ctx.closePath(); ctx.fill();
    ctx.restore();
  }
  ctx.lineWidth = 8; ctx.strokeStyle = '#b8bcc2'; ctx.beginPath(); ctx.arc(cx, cy, 84, 0, Math.PI * 2); ctx.stroke(); // rim lip
  ctx.fillStyle = '#9a9ea4'; ctx.beginPath(); ctx.arc(cx, cy, 20, 0, Math.PI * 2); ctx.fill();
  ctx.fillStyle = '#2b2f36'; ctx.beginPath(); ctx.arc(cx, cy, 10, 0, Math.PI * 2); ctx.fill();
  return texture(c, { repeat: false });
};

/* Car body decal: white with panel gaps, handles and fuel flap. u wraps around
 * the body section (0 = left sill, 0.5 = roof centre, 1 = right sill), v runs
 * from the tail (0) to the nose (1). Multiplied with the paint colour. */
EVO.tex.carDecal = function carDecal(kind = 'hatch') {
  const S = 512;
  const c = canvas(S, S), ctx = c.getContext('2d');
  ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, S, S);
  const doors = kind === 'van' ? [0.22, 0.5, 0.72] : kind === 'suv' ? [0.24, 0.5, 0.74] : [0.26, 0.5, 0.73];
  ctx.strokeStyle = 'rgba(0,0,0,0.55)'; ctx.lineWidth = 2;
  for (const v of doors) {
    const y = (1 - v) * S; // v=1 is the nose
    for (const [u0, u1] of [[0.04, 0.4], [0.6, 0.96]]) { ctx.beginPath(); ctx.moveTo(u0 * S, y); ctx.lineTo(u1 * S, y); ctx.stroke(); }
  }
  // handles
  ctx.fillStyle = 'rgba(0,0,0,0.35)';
  for (const v of [doors[1] - 0.03, doors[2] - 0.03]) for (const u of [0.2, 0.8]) ctx.fillRect(u * S - 4, (1 - v) * S - 16, 8, 14);
  // fuel flap
  ctx.strokeStyle = 'rgba(0,0,0,0.4)'; ctx.strokeRect(0.19 * S, (1 - 0.14) * S - 12, 16, 16);
  // black plastic sills and bumpers
  ctx.fillStyle = 'rgba(0,0,0,0.82)';
  ctx.fillRect(0, 0, 0.055 * S, S); ctx.fillRect(0.945 * S, 0, 0.055 * S, S);
  ctx.fillRect(0, 0, S, 0.045 * S); ctx.fillRect(0, 0.955 * S, S, 0.045 * S);
  // shut lines around bonnet and boot
  ctx.strokeStyle = 'rgba(0,0,0,0.5)'; ctx.beginPath(); ctx.moveTo(0.42 * S, (1 - 0.86) * S); ctx.lineTo(0.42 * S, (1 - 0.99) * S); ctx.moveTo(0.58 * S, (1 - 0.86) * S); ctx.lineTo(0.58 * S, (1 - 0.99) * S); ctx.stroke();
  return texture(c, { repeat: false });
};
