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
    uniforms: { tInput: { value: null }, uDir: { value: new THREE.Vector2(1, 0) }, uHalf: { value: 0 } }, vertexShader: VERT, depthTest: false, depthWrite: false,
    fragmentShader: `varying vec2 vUv; uniform sampler2D tInput; uniform vec2 uDir; uniform float uHalf;
      // uHalf clamps horizontal taps inside one eye of a side-by-side stereo
      // frame, so the left eye's sun cannot bloom into the right eye.
      vec2 clampUV(vec2 uv){ if (uHalf < 0.5) return uv; float eye = step(0.5, vUv.x); return vec2(clamp(uv.x, eye * 0.5 + 0.001, eye * 0.5 + 0.499), uv.y); }
      void main(){ vec3 c = vec3(0.0); float w[5]; w[0] = 0.227; w[1] = 0.195; w[2] = 0.122; w[3] = 0.054; w[4] = 0.016;
        c += texture2D(tInput, vUv).rgb * w[0];
        for (int i = 1; i < 5; i++) { vec2 o = uDir * float(i) * 1.6; c += texture2D(tInput, clampUV(vUv + o)).rgb * w[i]; c += texture2D(tInput, clampUV(vUv - o)).rgb * w[i]; }
        gl_FragColor = vec4(c, 1.0); }`
  });
  const composite = new THREE.ShaderMaterial({
    uniforms: { tScene: { value: null }, tBloom: { value: null }, uRes: { value: new THREE.Vector2(1, 1) }, uSpeed: { value: 0 }, uTime: { value: 0 }, uExposure: { value: 1.42 },
      uVR: { value: 0 }, uLens: { value: new THREE.Vector2(0.5, 0.5) }, uDist: { value: new THREE.Vector3(0.24, 0.22, 0.007) }, uEyeAspect: { value: 1 }, uComfort: { value: 0 } },
    vertexShader: VERT, depthTest: false, depthWrite: false,
    fragmentShader: `varying vec2 vUv; uniform sampler2D tScene; uniform sampler2D tBloom; uniform vec2 uRes; uniform float uSpeed; uniform float uTime; uniform float uExposure;
      uniform float uVR; uniform vec2 uLens; uniform vec3 uDist; uniform float uEyeAspect; uniform float uComfort;
      vec3 aces(vec3 x){ const float a = 2.51, b = 0.03, c = 2.43, d = 0.59, e = 0.14; return clamp((x * (a * x + b)) / (x * (c * x + d) + e), 0.0, 1.0); }
      vec3 toSRGB(vec3 c){ return mix(pow(c, vec3(1.0 / 2.4)) * 1.055 - 0.055, c * 12.92, step(c, vec3(0.0031308))); }
      // Stereo: each half of the frame is one eye. The headset lens throws a
      // pincushion, so the image is pre-warped with the opposite barrel and the
      // colour channels are pulled in by slightly different amounts to cancel
      // the lens's own fringing. Anything off the rendered eye reads black.
      vec2 eyeSample(vec2 c, float eye){ vec2 p = c / vec2(uEyeAspect, 1.0) + uLens; return vec2(clamp(p.x, 0.0, 1.0) * 0.5 + eye * 0.5, clamp(p.y, 0.0, 1.0)); }
      bool eyeOutside(vec2 c){ vec2 p = c / vec2(uEyeAspect, 1.0) + uLens; return p.x < 0.0 || p.x > 1.0 || p.y < 0.0 || p.y > 1.0; }

      void main(){
        vec2 uv = vUv; vec2 d = uv - 0.5; float r = length(d) * 1.4142;
        if (uVR > 0.5) {
          float eye = step(0.5, uv.x);
          vec2 c = (vec2(uv.x - eye * 0.5, uv.y) * vec2(2.0, 1.0) - uLens) * vec2(uEyeAspect, 1.0);
          float r2 = dot(c, c);
          float f = 1.0 + uDist.x * r2 + uDist.y * r2 * r2;
          vec2 gS = c * f, rS = c * (f * (1.0 - uDist.z)), bS = c * (f * (1.0 + uDist.z));
          if (eyeOutside(gS)) { gl_FragColor = vec4(0.0, 0.0, 0.0, 1.0); return; }
          vec2 gUV = eyeSample(gS, eye);
          vec3 col = vec3(texture2D(tScene, eyeSample(rS, eye)).r, texture2D(tScene, gUV).g, texture2D(tScene, eyeSample(bS, eye)).b);
          col += texture2D(tBloom, gUV).rgb * 0.42;
          col *= uExposure;
          col = aces(col);
          float l = dot(col, vec3(0.299, 0.587, 0.114));
          col = mix(vec3(l), col, 1.05);
          col = col * vec3(1.035, 1.0, 0.955) + vec3(0.0, 0.004, 0.014);
          float lr = clamp(sqrt(r2) * 1.7, 0.0, 1.0);
          // a soft edge always, tightening with speed and lean: tunnelling is
          // the one reliable way to keep a fast ride comfortable in a headset
          col *= 1.0 - smoothstep(0.62, 1.0, lr) * 0.5;
          col *= 1.0 - smoothstep(0.24, 0.92, lr) * uComfort;
          float gr = fract(sin(dot(floor(uv * uRes * 0.5) + fract(uTime) * 100.0, vec2(12.9898, 78.233))) * 43758.5453);
          col += (gr - 0.5) * 0.016;
          gl_FragColor = vec4(toSRGB(clamp(col, 0.0, 1.0)), 1.0);
          return;
        }
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
    end(speedNorm, time, vr) {
      // bloom
      blur.uniforms.uHalf.value = vr ? 1 : 0;
      bright.uniforms.tScene.value = sceneRT.texture; pass(bright, bloomA);
      blur.uniforms.tInput.value = bloomA.texture; blur.uniforms.uDir.value.set(1 / bloomA.width, 0); pass(blur, bloomB);
      blur.uniforms.tInput.value = bloomB.texture; blur.uniforms.uDir.value.set(0, 1 / bloomA.height); pass(blur, bloomA);
      // composite to the screen
      composite.uniforms.tScene.value = sceneRT.texture;
      composite.uniforms.tBloom.value = bloomA.texture;
      // No radial speed blur in VR: smearing the periphery is a reliable way
      // to make a headset rider ill.
      composite.uniforms.uSpeed.value = vr ? 0 : speedNorm * speedNorm;
      composite.uniforms.uTime.value = time;
      composite.uniforms.uVR.value = vr ? 1 : 0;
      if (vr) {
        composite.uniforms.uLens.value.set(vr.lensX, vr.lensY);
        composite.uniforms.uDist.value.set(vr.k1, vr.k2, vr.ca);
        composite.uniforms.uEyeAspect.value = vr.eyeAspect;
        composite.uniforms.uComfort.value = vr.comfort;
      }
      pass(composite, null);
    }
  };
};
