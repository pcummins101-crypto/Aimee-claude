import * as THREE from 'three';
/*
 * AVENRÀ EVO · B-ROAD — rain.
 *
 * Streaks live entirely in the vertex shader: each drop is a seed that falls
 * through a box wrapped around the rider, so the CPU never touches a vertex.
 * The streak is the drop's motion relative to the moving rider, which is why
 * the rain leans into you as you accelerate.
 */
const EVO = window.EVO;

EVO.createRain = function createRain(scene, quality) {
  const N = quality.coarse ? 1100 : 1700;
  const seeds = new Float32Array(N * 2 * 3), ends = new Float32Array(N * 2), sizes = new Float32Array(N * 2);
  const rnd = EVO.rng(4242);
  for (let i = 0; i < N; i += 1) {
    const sx = rnd(), sy = rnd(), sz = rnd(), sz2 = 0.6 + rnd() * 0.8;
    for (let k = 0; k < 2; k += 1) { const j = (i * 2 + k); seeds.set([sx, sy, sz], j * 3); ends[j] = k; sizes[j] = sz2; }
  }
  const geo = new THREE.BufferGeometry();
  geo.setAttribute('position', new THREE.Float32BufferAttribute(new Float32Array(N * 2 * 3), 3));
  geo.setAttribute('aSeed', new THREE.BufferAttribute(seeds, 3));
  geo.setAttribute('aEnd', new THREE.BufferAttribute(ends, 1));
  geo.setAttribute('aSize', new THREE.BufferAttribute(sizes, 1));
  const uniforms = {
    uCam: { value: new THREE.Vector3() }, uTime: { value: 0 }, uFall: { value: new THREE.Vector3(1.2, -9, 0.4) },
    uStreak: { value: new THREE.Vector3(0, 0.3, 0) }, uBox: { value: new THREE.Vector3(26, 16, 26) }, uAlpha: { value: 0 }
  };
  const mat = new THREE.ShaderMaterial({
    uniforms, transparent: true, depthWrite: false, blending: THREE.NormalBlending,
    vertexShader: `attribute vec3 aSeed; attribute float aEnd; attribute float aSize;
      uniform vec3 uCam, uFall, uStreak, uBox; uniform float uTime;
      varying float vFade;
      void main(){
        vec3 world = aSeed * uBox + uFall * uTime;
        vec3 origin = uCam - uBox * 0.5;
        vec3 p = origin + mod(world - origin, uBox);
        p += aEnd * uStreak * aSize;
        vec4 mv = modelViewMatrix * vec4(p, 1.0);
        float d = length(mv.xyz);
        vFade = (1.0 - smoothstep(4.0, 13.0, d)) * smoothstep(0.4, 1.2, d) * aEnd * 0.8 + (1.0 - aEnd) * 0.1;
        gl_Position = projectionMatrix * mv;
      }`,
    fragmentShader: `uniform float uAlpha; varying float vFade;
      void main(){ gl_FragColor = vec4(0.82, 0.86, 0.9, vFade * uAlpha); }`
  });
  const lines = new THREE.LineSegments(geo, mat);
  lines.frustumCulled = false; lines.visible = false; lines.renderOrder = 5; lines.name = 'rain';
  scene.add(lines);
  const wind = new THREE.Vector3(1.4, -8.5, 0.5);
  const vel = new THREE.Vector3();
  return {
    update(camPos, forward, speed, time, level) {
      lines.visible = level > 0.001;
      if (!lines.visible) return;
      uniforms.uCam.value.copy(camPos);
      uniforms.uTime.value = time;
      uniforms.uAlpha.value = Math.min(1, level) * 0.55;
      uniforms.uFall.value.copy(wind);
      // the streak is the drop's motion relative to the rider over an exposure
      vel.copy(forward).multiplyScalar(-speed).add(wind).multiplyScalar(0.045);
      uniforms.uStreak.value.copy(vel);
    }
  };
};
