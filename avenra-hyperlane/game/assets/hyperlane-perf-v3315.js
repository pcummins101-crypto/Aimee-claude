/*
 * AVENRÀ HYPERLANE — Canvas performance shim v3.3.15
 *
 * The Ultra renderer issues a large number of Canvas 2D calls per frame that
 * are cheap on a desktop GPU but expensive on Android Chrome:
 *
 *   - shadowBlur: every shape drawn with a shadow is rasterised into a
 *     temporary layer and Gaussian-blurred. The cost grows with the blur
 *     radius, and Ultra requests radii of 12-20 backing-store pixels for lamp
 *     glows, gantry lights, cat's eyes and brake lights on dozens of shapes.
 *   - imageSmoothingQuality "high": bicubic resampling for every scaled
 *     drawImage, including the photographic traffic sprites and verges.
 *
 * This shim loads before the renderer and clamps both at the context level
 * so every drawing module (compiled bundle and add-on helpers alike) benefits
 * without per-call changes. Glows are retained but kept small on touch
 * devices; desktop keeps larger radii. Nothing here reads game state and
 * nothing is disabled outright, so the Ultra look is preserved.
 */
(function attachAvenraPerfShim(globalScope) {
  'use strict';
  if (!globalScope || globalScope.AvenraPerfV3315) return;

  var coarse = false;
  try {
    coarse = (typeof globalScope.matchMedia === 'function' && globalScope.matchMedia('(pointer: coarse)').matches) ||
      (globalScope.navigator && globalScope.navigator.maxTouchPoints > 0);
  } catch (error) { coarse = false; }

  var settings = {
    version: '3.3.15',
    coarse: coarse,
    maxShadowBlur: coarse ? 5 : 14,
    smoothingQualityCap: coarse ? 'medium' : 'high',
    patched: []
  };

  function clampShadowBlur(proto, name) {
    var descriptor = null;
    try { descriptor = Object.getOwnPropertyDescriptor(proto, 'shadowBlur'); } catch (error) { descriptor = null; }
    if (!descriptor || typeof descriptor.set !== 'function' || typeof descriptor.get !== 'function') return;
    var originalSet = descriptor.set;
    var originalGet = descriptor.get;
    try {
      Object.defineProperty(proto, 'shadowBlur', {
        configurable: true,
        enumerable: descriptor.enumerable,
        get: function getShadowBlur() { return originalGet.call(this); },
        set: function setShadowBlur(value) {
          var blur = Number(value);
          if (!Number.isFinite(blur) || blur < 0) blur = 0;
          if (blur > settings.maxShadowBlur) blur = settings.maxShadowBlur;
          originalSet.call(this, blur);
        }
      });
      settings.patched.push(name + '.shadowBlur');
    } catch (error) { /* leave the native accessor in place */ }
  }

  function clampSmoothingQuality(proto, name) {
    if (settings.smoothingQualityCap === 'high') return;
    var descriptor = null;
    try { descriptor = Object.getOwnPropertyDescriptor(proto, 'imageSmoothingQuality'); } catch (error) { descriptor = null; }
    if (!descriptor || typeof descriptor.set !== 'function' || typeof descriptor.get !== 'function') return;
    var originalSet = descriptor.set;
    var originalGet = descriptor.get;
    try {
      Object.defineProperty(proto, 'imageSmoothingQuality', {
        configurable: true,
        enumerable: descriptor.enumerable,
        get: function getSmoothingQuality() { return originalGet.call(this); },
        set: function setSmoothingQuality(value) {
          originalSet.call(this, value === 'high' ? settings.smoothingQualityCap : value);
        }
      });
      settings.patched.push(name + '.imageSmoothingQuality');
    } catch (error) { /* leave the native accessor in place */ }
  }

  var targets = [
    ['CanvasRenderingContext2D', globalScope.CanvasRenderingContext2D],
    ['OffscreenCanvasRenderingContext2D', globalScope.OffscreenCanvasRenderingContext2D]
  ];
  for (var index = 0; index < targets.length; index += 1) {
    var ctor = targets[index][1];
    if (!ctor || !ctor.prototype) continue;
    clampShadowBlur(ctor.prototype, targets[index][0]);
    clampSmoothingQuality(ctor.prototype, targets[index][0]);
  }

  globalScope.AvenraPerfV3315 = Object.freeze(settings);
})(typeof window !== 'undefined' ? window : (typeof globalThis !== 'undefined' ? globalThis : this));
