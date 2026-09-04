import * as THREE from 'three';
/*
 * AVENRÀ EVO · B-ROAD — volumetric trees.
 *
 * Each tree is a trunk with a few branches and a canopy of leaf-cluster cards
 * scattered through an ellipsoid.  The cards carry "spherical" normals (the
 * direction from the canopy centre), so the whole crown shades like a solid
 * volume under the sun instead of a set of flat cut-outs.  Three species with
 * two seeded variants each, instanced with per-tree colour variation.
 */
const EVO = window.EVO;
const { lerp } = EVO;

const SPECIES = {
  oak: { trunkH: 3.0, trunkR: 0.28, canopy: 5.7, rx: 3.7, ry: 3.0, clusters: 34, card: [2.0, 2.9], branches: 5, leaf: 'oak', seed: 11, tint: [0.92, 1.05, 0.9] },
  ash: { trunkH: 4.3, trunkR: 0.21, canopy: 7.6, rx: 2.8, ry: 3.8, clusters: 30, card: [1.7, 2.5], branches: 4, leaf: 'ash', seed: 23, tint: [1.0, 1.05, 0.92] },
  hawthorn: { trunkH: 1.3, trunkR: 0.13, canopy: 3.0, rx: 2.3, ry: 1.9, clusters: 24, card: [1.2, 1.8], branches: 4, leaf: 'hawthorn', seed: 37, tint: [0.95, 1.0, 0.95] }
};

class Sink {
  constructor() { this.p = []; this.n = []; this.uv = []; this.c = []; this.idx = []; }
  addGeometry(g, matrix) {
    const gg = g.clone(); if (matrix) gg.applyMatrix4(matrix);
    const pa = gg.getAttribute('position'), na = gg.getAttribute('normal'), ua = gg.getAttribute('uv');
    const base = this.p.length / 3;
    for (let i = 0; i < pa.count; i += 1) { this.p.push(pa.getX(i), pa.getY(i), pa.getZ(i)); this.n.push(na.getX(i), na.getY(i), na.getZ(i)); this.uv.push(ua.getX(i), ua.getY(i)); }
    const ix = gg.getIndex(); for (let i = 0; i < ix.count; i += 1) this.idx.push(ix.getX(i) + base);
  }
  quad(corners, normal, shade = 1) {
    const base = this.p.length / 3;
    for (let i = 0; i < 4; i += 1) { this.p.push(corners[i].x, corners[i].y, corners[i].z); this.n.push(normal.x, normal.y, normal.z); this.c.push(shade, shade, shade); }
    this.uv.push(0, 0, 1, 0, 1, 1, 0, 1);
    this.idx.push(base, base + 1, base + 2, base, base + 2, base + 3);
  }
  build() {
    const g = new THREE.BufferGeometry();
    g.setAttribute('position', new THREE.Float32BufferAttribute(this.p, 3));
    g.setAttribute('normal', new THREE.Float32BufferAttribute(this.n, 3));
    g.setAttribute('uv', new THREE.Float32BufferAttribute(this.uv, 2));
    if (this.c.length === this.p.length) g.setAttribute('color', new THREE.Float32BufferAttribute(this.c, 3));
    g.setIndex(this.idx); g.computeBoundingSphere();
    return g;
  }
}

function buildTree(spec, seed) {
  const rnd = EVO.rng(seed);
  const wood = new Sink(), leaves = new Sink();
  const m = new THREE.Matrix4(), q = new THREE.Quaternion(), up = new THREE.Vector3(0, 1, 0);
  // trunk, sunk a little into the ground and reaching up into the crown
  const trunkLen = spec.trunkH + spec.ry * 0.9;
  const trunk = new THREE.CylinderGeometry(spec.trunkR * 0.5, spec.trunkR, trunkLen, 9, 1);
  trunk.translate(0, trunkLen / 2 - 0.25, 0);
  wood.addGeometry(trunk);
  // branches from the trunk top outward and upward
  for (let b = 0; b < spec.branches; b += 1) {
    const az = (b / spec.branches) * Math.PI * 2 + rnd() * 0.8, el = 0.45 + rnd() * 0.6;
    const dir = new THREE.Vector3(Math.cos(az) * Math.cos(el), Math.sin(el), Math.sin(az) * Math.cos(el));
    const len = spec.rx * (0.7 + rnd() * 0.45);
    const br = new THREE.CylinderGeometry(0.035, spec.trunkR * 0.42, len, 6, 1);
    br.translate(0, len / 2, 0);
    q.setFromUnitVectors(up, dir);
    m.compose(new THREE.Vector3(0, spec.trunkH + 0.2 + rnd() * 1.0, 0), q, new THREE.Vector3(1, 1, 1));
    wood.addGeometry(br, m);
  }
  // canopy cards
  const centre = new THREE.Vector3(0, spec.canopy, 0);
  const e = new THREE.Euler(), c = new THREE.Vector3(), nrm = new THREE.Vector3();
  const corners = [new THREE.Vector3(), new THREE.Vector3(), new THREE.Vector3(), new THREE.Vector3()];
  for (let k = 0; k < spec.clusters; k += 1) {
    const th = rnd() * Math.PI * 2, ph = Math.acos(1 - 2 * rnd()) * 0.92; // slight bias upward
    const u = 0.35 + 0.65 * Math.sqrt(rnd());
    c.set(Math.cos(th) * Math.sin(ph) * spec.rx * u, Math.cos(ph) * spec.ry * u, Math.sin(th) * Math.sin(ph) * spec.rx * u).add(centre);
    const size = lerp(spec.card[0], spec.card[1], rnd());
    e.set((rnd() - 0.5) * 1.2, rnd() * Math.PI * 2, (rnd() - 0.5) * 0.8, 'YXZ');
    m.makeRotationFromEuler(e);
    const hx = size / 2, hy = size * 0.42;
    corners[0].set(-hx, -hy, 0); corners[1].set(hx, -hy, 0); corners[2].set(hx, hy, 0); corners[3].set(-hx, hy, 0);
    for (const v of corners) v.applyMatrix4(m).add(c);
    nrm.copy(c).sub(centre); nrm.x /= spec.rx; nrm.y /= spec.ry; nrm.z /= spec.rx; nrm.y += 0.35; nrm.normalize();
    // inner clusters sit in the crown's own shade
    leaves.quad(corners, nrm, 0.55 + 0.45 * u);
  }
  return { wood: wood.build(), leaves: leaves.build(), height: spec.canopy + spec.ry };
}

EVO.vegetation = {
  SPECIES,
  /* placements: [{x, y, z, species, scale, yaw, tint?}] */
  createTreeMeshes(scene, placements, opts = {}) {
    const bark = EVO.tex.bark();
    const barkMat = new THREE.MeshStandardMaterial({ map: bark.map, normalMap: bark.normalMap, normalScale: new THREE.Vector2(0.9, 0.9), roughness: 0.95 });
    const groups = new Map();
    for (const p of placements) {
      const variant = (Math.abs(Math.round(p.x * 7 + p.z * 13)) % 2);
      const key = `${p.species}:${variant}`;
      if (!groups.has(key)) groups.set(key, []);
      groups.get(key).push(p);
    }
    const leafTex = {};
    const meshes = [];
    const m = new THREE.Matrix4(), q = new THREE.Quaternion(), pos = new THREE.Vector3(), sc = new THREE.Vector3(), Y = new THREE.Vector3(0, 1, 0), col = new THREE.Color();
    for (const [key, list] of groups) {
      const [species, variant] = key.split(':');
      const spec = SPECIES[species];
      const geo = buildTree(spec, spec.seed * 7 + Number(variant) * 101);
      leafTex[species] = leafTex[species] || EVO.tex.leafCluster(spec.leaf, spec.seed);
      const leafMat = new THREE.MeshStandardMaterial({
        map: leafTex[species].map, normalMap: leafTex[species].normalMap, normalScale: new THREE.Vector2(0.55, 0.55),
        alphaTest: 0.5, side: THREE.DoubleSide, roughness: 0.85, metalness: 0, emissive: 0x16240a, emissiveIntensity: 0.15, vertexColors: true
      });
      // gentle canopy sway
      leafMat.onBeforeCompile = (shader) => {
        shader.uniforms.uTime = EVO.windUniform || { value: 0 };
        shader.fragmentShader = shader.fragmentShader.replace('#include <emissivemap_fragment>', '#include <emissivemap_fragment>\ntotalEmissiveRadiance += diffuseColor.rgb * 0.18;');
        shader.vertexShader = shader.vertexShader
          .replace('#include <common>', '#include <common>\nuniform float uTime;')
          .replace('#include <begin_vertex>', '#include <begin_vertex>\n{ float ph = instanceMatrix[3][0] * 0.13 + instanceMatrix[3][2] * 0.17; float sw = sin(uTime * 0.9 + ph + position.y * 0.35) * 0.5 + sin(uTime * 1.7 + ph * 1.3) * 0.25; float amt = smoothstep(1.5, 4.0, position.y) * 0.07; transformed.x += sw * amt; transformed.z += sw * amt * 0.6; }');
      };
      EVO.tagShader(leafMat, 'treesway');
      const wood = new THREE.InstancedMesh(geo.wood, barkMat, list.length);
      const leaves = new THREE.InstancedMesh(geo.leaves, leafMat, list.length);
      list.forEach((p, k) => {
        q.setFromAxisAngle(Y, p.yaw); pos.set(p.x, p.y, p.z); sc.set(p.scale, p.scale, p.scale);
        m.compose(pos, q, sc); wood.setMatrixAt(k, m); leaves.setMatrixAt(k, m);
        const t = p.tint ?? 1;
        col.setRGB(spec.tint[0] * t, spec.tint[1] * (0.9 + (t - 1) * 0.5 + 0.1), spec.tint[2] * (2 - t) * 0.5 + 0.5);
        leaves.setColorAt(k, col);
      });
      wood.castShadow = true; wood.receiveShadow = true;
      leaves.castShadow = true; leaves.receiveShadow = false;
      leaves.customDepthMaterial = new THREE.MeshDepthMaterial({ depthPacking: THREE.RGBADepthPacking, map: leafTex[species].map, alphaTest: 0.5 });
      scene.add(wood, leaves);
      meshes.push(wood, leaves);
    }
    return meshes;
  }
};
