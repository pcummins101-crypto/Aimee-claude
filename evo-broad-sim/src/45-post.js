import * as THREE from 'three';
/*
 * AVENRÀ EVO · B-ROAD — photographic post-processing.
 *
 * The world renders in linear HDR into a multisampled half-float target.
 * A quarter-resolution bloom lifts the sun, sky and headlamps, then a single
 * composite pass applies speed-scaled edge blur, slight chromatic aberration,
 * ACES tone mapping, a gentle colour grade, vignette and film grain before
 * encoding to sRGB.  If the platform cannot render to float targets the
 * pipeline reports unavailable and the frame is drawn directly instead.
 */
const EVO = window.EVO;

const QUAD = new THREE.PlaneGeometry(2, 2);
const ORTHO = new THREE.OrthographicCamera(-1, 1, 1, -1, 0, 1);
const VERT = 'varying vec2 vUv; void main(){ vUv = uv; gl_Position = vec4(position.xy, 0.0, 1.0); }';

EVO.createPost = function createPost(renderer, quality) {
  if (!renderer.capabilities.isWebGL2) return null;
  const gl = renderer.getContext();
  const floatOk = !!gl.getExtension('EXT_color_buffer_float') || !!gl.getExtension('EXT_color_buffer_half_float');
  if (!floatOk) return null;

  const samples = quality.coarse ? 2 : 4;
  const sceneRT = new THREE.WebGLRenderTarget(4, 4, { type: THREE.HalfFloatType, samples, depthBuffer: true });
  const bloomA = new THREE.WebGLRenderTarget(4, 4, { type: THREE.HalfFloatType, depthBuffer: false });
  const bloomB = new THREE.WebGLRenderTarget(4, 4, { type: THREE.HalfFloatType, depthBuffer: false });
  for (const rt of [sceneRT, bloomA, bloomB]) { rt.texture.minFilter = THREE.LinearFilter; rt.texture.magFilter = THREE.LinearFilter; }

  const bright = new THREE.ShaderMaterial({
    uniforms: { tScene: { value: null } }, vertexShader: VERT, depthTest: false, depthWrite: false,
    fragmentShader: `varying vec2 vUv; uniform sampler2D tScene;
      void main(){ vec3 c = texture2D(tScene, vUv).rgb; float l = dot(c, vec3(0.2126, 0.7152, 0.0722));
        gl_FragColor = vec4(c * smoothstep(0.85, 1.6, l), 1.0); }`
  });
  const blur = new THREE.ShaderMaterial({
    uniforms: { tInput: { value: null }, uDir: { value: new THREE.Vector2(1, 0) } }, vertexShader: VERT, depthTest: false, depthWrite: false,
    fragmentShader: `varying vec2 vUv; uniform sampler2D tInput; uniform vec2 uDir;
      void main(){ vec3 c = vec3(0.0); float w[5]; w[0] = 0.227; w[1] = 0.195; w[2] = 0.122; w[3] = 0.054; w[4] = 0.016;
        c += texture2D(tInput, vUv).rgb * w[0];
        for (int i = 1; i < 5; i++) { vec2 o = uDir * float(i) * 1.6; c += texture2D(tInput, vUv + o).rgb * w[i]; c += texture2D(tInput, vUv - o).rgb * w[i]; }
        gl_FragColor = vec4(c, 1.0); }`
  });
  const composite = new THREE.ShaderMaterial({
    uniforms: { tScene: { value: null }, tBloom: { value: null }, uRes: { value: new THREE.Vector2(1, 1) }, uSpeed: { value: 0 }, uTime: { value: 0 }, uExposure: { value: 1.42 } },
    vertexShader: VERT, depthTest: false, depthWrite: false,
    fragmentShader: `varying vec2 vUv; uniform sampler2D tScene; uniform sampler2D tBloom; uniform vec2 uRes; uniform float uSpeed; uniform float uTime; uniform float uExposure;
      vec3 aces(vec3 x){ const float a = 2.51, b = 0.03, c = 2.43, d = 0.59, e = 0.14; return clamp((x * (a * x + b)) / (x * (c * x + d) + e), 0.0, 1.0); }
      vec3 toSRGB(vec3 c){ return mix(pow(c, vec3(1.0 / 2.4)) * 1.055 - 0.055, c * 12.92, step(c, vec3(0.0031308))); }
      void main(){
        vec2 uv = vUv; vec2 d = uv - 0.5; float r = length(d) * 1.4142;
        // speed-scaled radial blur, strongest at the edges, none in the centre
        float blur = uSpeed * (0.008 + r * r * 0.075);
        vec2 ca = d * r * 0.0012;
        vec3 col = vec3(0.0); float wsum = 0.0;
        for (int i = 0; i < 5; i++) {
          float t = float(i) / 4.0 - 0.5; vec2 o = d * t * blur; float w = 1.0 - abs(t) * 0.5;
          col += vec3(texture2D(tScene, uv + o + ca).r, texture2D(tScene, uv + o).g, texture2D(tScene, uv + o - ca).b) * w; wsum += w;
        }
        col /= wsum;
        col += texture2D(tBloom, uv).rgb * 0.42;
        col *= uExposure;
        col = aces(col);
        // grade: a touch more saturation, warm highlights, cool lifted shadows
        float l = dot(col, vec3(0.299, 0.587, 0.114));
        col = mix(vec3(l), col, 1.05);
        col = col * vec3(1.035, 1.0, 0.955) + vec3(0.0, 0.004, 0.014);
        col = mix(col, smoothstep(0.0, 1.0, col), 0.06);
        col *= 1.0 - smoothstep(0.55, 1.05, r) * 0.26;
        float g = fract(sin(dot(floor(uv * uRes * 0.5) + fract(uTime) * 100.0, vec2(12.9898, 78.233))) * 43758.5453);
        col += (g - 0.5) * 0.022;
        gl_FragColor = vec4(toSRGB(clamp(col, 0.0, 1.0)), 1.0); }`
  });
  const quadMesh = new THREE.Mesh(QUAD, composite);
  const quadScene = new THREE.Scene(); quadScene.add(quadMesh);
  quadMesh.frustumCulled = false;

  const size = new THREE.Vector2();
  function resize() {
    renderer.getDrawingBufferSize(size);
    const w = Math.max(4, Math.floor(size.x)), h = Math.max(4, Math.floor(size.y));
    if (sceneRT.width !== w || sceneRT.height !== h) {
      sceneRT.setSize(w, h);
      bloomA.setSize(Math.max(2, w >> 2), Math.max(2, h >> 2));
      bloomB.setSize(Math.max(2, w >> 2), Math.max(2, h >> 2));
    }
    composite.uniforms.uRes.value.set(w, h);
  }

  function pass(material, target) {
    quadMesh.material = material;
    renderer.setRenderTarget(target);
    renderer.render(quadScene, ORTHO);
  }

  return {
    sceneRT,
    resize,
    begin() { resize(); renderer.setRenderTarget(sceneRT); renderer.clear(); },
    end(speedNorm, time) {
      // bloom
      bright.uniforms.tScene.value = sceneRT.texture; pass(bright, bloomA);
      blur.uniforms.tInput.value = bloomA.texture; blur.uniforms.uDir.value.set(1 / bloomA.width, 0); pass(blur, bloomB);
      blur.uniforms.tInput.value = bloomB.texture; blur.uniforms.uDir.value.set(0, 1 / bloomA.height); pass(blur, bloomA);
      // composite to the screen
      composite.uniforms.tScene.value = sceneRT.texture;
      composite.uniforms.tBloom.value = bloomA.texture;
      composite.uniforms.uSpeed.value = speedNorm * speedNorm;
      composite.uniforms.uTime.value = time;
      pass(composite, null);
    }
  };
};
