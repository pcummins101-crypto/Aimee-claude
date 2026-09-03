/*
 * AVENRA HYPERLANE — photographic 2.5D world helper v3.3.13
 *
 * Additive Canvas 2D helpers for the compiled Hyperlane renderer.  The
 * helper owns no animation loop and never mutates caller state.  Everything
 * placed in the world is derived from route, world distance and seed, so a
 * Weekly Works seed produces the same UK scenery at every frame rate.
 */
(function attachAvenraWorldHelpers(globalScope) {
  'use strict';

  if (!globalScope) return;

  var VERSION = '3.3.13';
  var CITY_CONTINUITY_V3311 = true;
  var CITY_CONTINUITY_MAX_ITEMS_V3311 = 14;
  var CITY_BACKDROP_MASK_PASSTHROUGH_V3311 = true;
  var TAU = Math.PI * 2;
  var VALID_ROUTES = { city: true, rural: true, motorway: true };
  var VALID_TIMES = { day: true, dusk: true, night: true };
  var VALID_WEATHER = { clear: true, rain: true, storm: true, fog: true, 'post-rain': true };

  var namespace = globalScope.AvenraNextGenV300;
  if (!namespace || typeof namespace !== 'object') namespace = {};
  var legacyDrawNearField = typeof namespace.drawNearFieldV300 === 'function' ?
    namespace.drawNearFieldV300 :
    (typeof namespace.drawNearField === 'function' ? namespace.drawNearField : null);
  if (!namespace.drawNearFieldV300 && legacyDrawNearField) namespace.drawNearFieldV300 = legacyDrawNearField;

  function finite(value, fallback) {
    try {
      var number = Number(value);
      return Number.isFinite(number) ? number : fallback;
    } catch (error) {
      return fallback;
    }
  }

  function clamp(value, minimum, maximum) {
    return Math.max(minimum, Math.min(maximum, value));
  }

  function mix(a, b, amount) {
    return a + (b - a) * amount;
  }

  function smoothstep(edge0, edge1, value) {
    if (!(edge1 > edge0)) return value >= edge1 ? 1 : 0;
    var unit = clamp((value - edge0) / (edge1 - edge0), 0, 1);
    return unit * unit * (3 - 2 * unit);
  }

  function rgba(colour, alpha) {
    return 'rgba(' + Math.round(colour[0]) + ',' + Math.round(colour[1]) + ',' + Math.round(colour[2]) + ',' + clamp(alpha, 0, 1) + ')';
  }

  function freeze(value) {
    try { return Object.freeze(value); } catch (error) { return value; }
  }

  function normaliseRoute(value) {
    var route = String(value || '').toLowerCase();
    if (route === 'm1' || route === 'highway') route = 'motorway';
    if (route === 'country' || route === 'countryside') route = 'rural';
    if (route === 'district' || route === 'urban') route = 'city';
    return VALID_ROUTES[route] ? route : 'city';
  }

  function normaliseTime(value) {
    var time = String(value || '').toLowerCase();
    if (time === 'evening' || time === 'sunset') time = 'dusk';
    if (time === 'morning' || time === 'afternoon') time = 'day';
    return VALID_TIMES[time] ? time : 'day';
  }

  function normaliseWeather(value) {
    var weather = String(value || '').toLowerCase();
    if (weather === 'wet' || weather === 'drizzle') weather = 'rain';
    if (weather === 'mist' || weather === 'misty') weather = 'fog';
    if (weather === 'postrain' || weather === 'post_rain' || weather === 'after-rain' || weather === 'after rain' || weather === 'clearing') weather = 'post-rain';
    return VALID_WEATHER[weather] ? weather : 'clear';
  }

  function qualityOf(state, options) {
    return String(
      options && (options.quality || options.tier) ||
      state && (state.quality || state.tier || state.graphicsQuality) ||
      'cinematic'
    ).toLowerCase();
  }

  function isCinematic(state, options) {
    if (options && options.cinematic === false) return false;
    if (options && options.cinematic === true) return true;
    var quality = qualityOf(state, options);
    return quality === 'cinematic' || quality === 'ultra' || quality === 'high';
  }

  function hashString(value) {
    var text = String(value == null ? '' : value);
    var hash = 2166136261;
    for (var index = 0; index < text.length; index += 1) {
      hash ^= text.charCodeAt(index);
      hash = Math.imul(hash, 16777619);
    }
    return hash >>> 0;
  }

  function hashUnit(value) {
    var number = finite(value, 0);
    var mixed = Math.imul((number | 0) ^ 0x9e3779b9, 0x85ebca6b);
    mixed ^= mixed >>> 13;
    mixed = Math.imul(mixed, 0xc2b2ae35);
    mixed ^= mixed >>> 16;
    return (mixed >>> 0) / 4294967296;
  }

  function seedNumber(value) {
    if (Number.isFinite(Number(value))) return Number(value) >>> 0;
    return hashString(value || 'avenra-weekly-works');
  }

  function stateRoute(state, options) {
    return normaliseRoute(
      state && (state.routeId || state.route || state.routeType) ||
      options && (options.routeId || options.route)
    );
  }

  function stateDistance(state, options) {
    return Math.max(0, finite(
      state && (state.worldDistance != null ? state.worldDistance : state.distance),
      finite(options && options.worldDistance, 0)
    ));
  }

  function stateSeed(state, options) {
    return seedNumber(
      options && (options.seed != null ? options.seed : options.runSeed != null ? options.runSeed : options.routeSeed) != null ?
        (options.seed != null ? options.seed : options.runSeed != null ? options.runSeed : options.routeSeed) :
        state && (state.weeklySeed != null ? state.weeklySeed :
          state.nextGenV300 && state.nextGenV300.runSeed != null ? state.nextGenV300.runSeed :
          state.trafficSeed != null ? state.trafficSeed :
          state.routeSeed != null ? state.routeSeed : state.seed)
    );
  }

  function stateElapsed(state, options) {
    return Math.max(0, finite(
      state && (state.elapsed != null ? state.elapsed : state.runTime),
      finite(options && (options.elapsed != null ? options.elapsed : options.runTime), 0)
    ));
  }

  function isActualTunnel(state, options) {
    var route = stateRoute(state, options);
    var stage = String(
      state && (state.routeStage || state.stage) ||
      options && (options.routeStage || options.stage) ||
      ''
    ).toLowerCase();
    return route === 'city' && stage === 'tunnel';
  }

  function gcd(a, b) {
    var left = Math.abs(a | 0);
    var right = Math.abs(b | 0);
    while (right) {
      var remainder = left % right;
      left = right;
      right = remainder;
    }
    return left || 1;
  }

  function makeChapter(id, title, cue, assets, furniture, density) {
    var frozenAssets = {};
    var assetPool = [];
    var seenAssets = Object.create(null);
    ['far', 'mid', 'near'].forEach(function freezeBand(band) {
      frozenAssets[band] = freeze((assets[band] || []).slice());
      for (var index = 0; index < frozenAssets[band].length; index += 1) {
        var assetId = frozenAssets[band][index];
        if (seenAssets[assetId]) continue;
        seenAssets[assetId] = true;
        assetPool.push(assetId);
      }
    });
    return freeze({
      id: id,
      title: title,
      cue: cue,
      assets: freeze(frozenAssets),
      assetPool: freeze(assetPool),
      furniture: freeze((furniture || []).slice()),
      density: finite(density, 1)
    });
  }

  // Average values are retained under the v3.1 public property name for
  // callers which display a nominal chapter length.  Actual chapter lengths
  // are deterministic values inside these authored 80–120 metre ranges.
  var CHAPTER_LENGTHS = freeze({ city: 94, rural: 102, motorway: 110 });
  var CHAPTER_LENGTH_RANGES = freeze({
    city: freeze({ minimum: 80, maximum: 106 }),
    rural: freeze({ minimum: 88, maximum: 116 }),
    motorway: freeze({ minimum: 96, maximum: 120 })
  });

  var RAW_VISUAL_CHAPTERS = freeze({
    city: freeze([
      makeChapter('district-gateway', 'District Gateway', 'Brick, trees and a broad UK approach', {
        far: ['city-warehouse', 'avenra-works'], mid: ['city-warehouse', 'district-tree'], near: ['district-tree', 'city-warehouse']
      }, ['lamp', 'railing', 'bollard'], 0.92),
      makeChapter('red-brick-corridor', 'Red Brick Corridor', 'Dense warehouse frontage and street rhythm', {
        far: ['city-warehouse'], mid: ['city-warehouse', 'avenra-works'], near: ['city-warehouse', 'district-tree']
      }, ['lamp', 'railing'], 1.08),
      makeChapter('rail-quarter', 'Rail Quarter', 'Industrial edges and restrained roadside detail', {
        far: ['avenra-works', 'city-warehouse'], mid: ['city-warehouse'], near: ['district-tree', 'city-warehouse']
      }, ['railing', 'lamp', 'bollard'], 0.96),
      makeChapter('civic-boulevard', 'Civic Boulevard', 'More air, regular lamps and planted verges', {
        far: ['city-warehouse', 'avenra-works'], mid: ['district-tree', 'city-warehouse'], near: ['district-tree']
      }, ['lamp', 'railing'], 0.86),
      makeChapter('bus-corridor', 'Bus Corridor', 'Regular lamps, railings and a broad urban carriageway', {
        far: ['city-warehouse', 'avenra-works'], mid: ['city-warehouse', 'district-tree'], near: ['district-tree', 'city-warehouse']
      }, ['lamp', 'railing', 'bollard'], 1.00),
      makeChapter('riverside', 'Riverside', 'Lower frontage and an open, cooler horizon', {
        far: ['avenra-works'], mid: ['district-tree', 'city-warehouse'], near: ['district-tree']
      }, ['lamp', 'railing'], 0.78),
      makeChapter('retail-quarter', 'Retail Quarter', 'Set-back commercial frontage and denser street furniture', {
        far: ['city-warehouse', 'avenra-works'], mid: ['city-warehouse'], near: ['city-warehouse', 'district-tree']
      }, ['lamp', 'bollard', 'railing'], 1.04),
      makeChapter('city-underpass', 'City Underpass', 'A short shadowed transition into the expressway approach', {
        far: ['avenra-works'], mid: ['city-warehouse'], near: ['district-tree']
      }, ['lamp', 'railing'], 0.74),
      makeChapter('warehouse-quarter', 'Warehouse Quarter', 'Long low industrial silhouettes', {
        far: ['city-warehouse', 'avenra-works', 'motorway-campus'], mid: ['city-warehouse', 'avenra-works', 'motorway-logistics'], near: ['city-warehouse', 'motorway-logistics']
      }, ['lamp', 'bollard'], 1.02),
      makeChapter('expressway-approach', 'Expressway Approach', 'Open sightlines and motorway-scale furniture', {
        far: ['avenra-works', 'city-warehouse', 'motorway-campus'], mid: ['city-warehouse', 'district-tree', 'motorway-logistics'], near: ['district-tree', 'motorway-logistics']
      }, ['high-mast', 'railing', 'bollard'], 0.76)
    ]),
    rural: freeze([
      makeChapter('open-dales', 'Open Dales', 'Long views, sparse farms and stone markers', {
        far: ['rural-estate', 'rural-farmstead'], mid: ['rural-farmstead'], near: ['district-tree']
      }, ['rural-post', 'utility-pole'], 0.72),
      makeChapter('dry-stone-run', 'Dry Stone Run', 'Roadside rhythm and compact farm groups', {
        far: ['rural-estate'], mid: ['rural-farmstead', 'district-tree'], near: ['district-tree']
      }, ['stone-marker', 'rural-post', 'utility-pole'], 0.92),
      makeChapter('hedge-tunnel', 'Hedge Tunnel', 'Close hedges compress the view before open farmland', {
        far: ['rural-farmstead'], mid: ['district-tree'], near: ['district-tree', 'district-tree']
      }, ['rural-post'], 1.10),
      makeChapter('farmstead-rise', 'Farmstead Rise', 'Farm buildings set back from the carriageway', {
        far: ['rural-farmstead', 'rural-estate'], mid: ['rural-estate', 'rural-farmstead'], near: ['district-tree']
      }, ['utility-pole', 'rural-post'], 0.86),
      makeChapter('village-edge', 'Village Edge', 'Estate silhouettes and tighter roadside detail', {
        far: ['rural-estate'], mid: ['rural-estate', 'district-tree'], near: ['district-tree']
      }, ['rural-post', 'utility-pole'], 1.02),
      makeChapter('stone-bridge', 'Stone Bridge', 'A compact bridge transition with dry-stone approaches', {
        far: ['rural-estate', 'rural-farmstead'], mid: ['rural-farmstead'], near: ['district-tree']
      }, ['stone-marker', 'rural-post'], 0.82),
      makeChapter('village-restriction', 'Village Restriction', 'A tighter thirty-limit approach through a settled edge', {
        far: ['rural-estate'], mid: ['rural-estate', 'district-tree'], near: ['district-tree']
      }, ['rural-post', 'utility-pole'], 1.06),
      makeChapter('wooded-cutting', 'Wooded Cutting', 'Trees close the view without becoming a static wall', {
        far: ['rural-farmstead'], mid: ['district-tree', 'district-tree'], near: ['district-tree']
      }, ['rural-post'], 1.12),
      makeChapter('moorland-crossing', 'Moorland Crossing', 'Sparse structures and an open horizon', {
        far: ['rural-farmstead'], mid: ['rural-estate'], near: ['district-tree']
      }, ['stone-marker', 'rural-post'], 0.62),
      makeChapter('dual-carriageway', 'Dales Dual Carriageway', 'A short, telegraphed overtaking section', {
        far: ['rural-estate', 'rural-farmstead'], mid: ['district-tree', 'rural-estate'], near: ['district-tree']
      }, ['high-mast', 'rural-post', 'railing'], 0.82)
    ]),
    motorway: freeze([
      makeChapter('luton-approach', 'Luton Approach', 'Tree belts and logistics edges', {
        far: ['motorway-campus', 'motorway-logistics'], mid: ['motorway-logistics', 'district-tree'], near: ['district-tree']
      }, ['high-mast', 'motorway-post', 'railing'], 0.90),
      makeChapter('smart-motorway', 'Smart Motorway', 'Regular furniture and open forward visibility', {
        far: ['motorway-campus'], mid: ['motorway-logistics'], near: ['district-tree']
      }, ['high-mast', 'motorway-post'], 0.76),
      makeChapter('chalk-cutting', 'Chalk Cutting', 'A restrained low-density roadside sequence', {
        far: ['avenra-works'], mid: ['district-tree'], near: ['district-tree']
      }, ['motorway-post', 'railing'], 0.68),
      makeChapter('m1-overbridge', 'M1 Overbridge', 'A short bridge transition conceals the next motorway module', {
        far: ['motorway-campus'], mid: ['motorway-logistics', 'district-tree'], near: ['district-tree']
      }, ['high-mast', 'motorway-post', 'railing'], 0.76),
      makeChapter('logistics-belt', 'Logistics Belt', 'Large UK distribution buildings beyond the verge', {
        far: ['motorway-campus', 'motorway-logistics', 'avenra-works'], mid: ['motorway-logistics', 'motorway-campus'], near: ['district-tree']
      }, ['high-mast', 'motorway-post', 'railing'], 1.08),
      makeChapter('works-sector', 'Avenra Works Sector', 'Industrial landmarks and denser furniture', {
        far: ['avenra-works', 'motorway-campus'], mid: ['avenra-works', 'motorway-logistics'], near: ['district-tree']
      }, ['high-mast', 'motorway-post', 'railing'], 1.00),
      makeChapter('services-run', 'Services Run', 'A spatially layered motorway service approach', {
        far: ['motorway-campus', 'service-watford'], mid: ['service-toddington', 'service-watford', 'service-woodall', 'service-woolley'], near: ['service-toddington', 'service-woodall', 'service-woolley']
      }, ['high-mast', 'motorway-post', 'railing'], 0.88),
      makeChapter('northbound-open', 'Northbound Open', 'Long-distance flow with sparse landmarks', {
        far: ['motorway-logistics', 'motorway-campus'], mid: ['district-tree', 'motorway-campus'], near: ['district-tree']
      }, ['motorway-post', 'high-mast'], 0.70)
    ])
  });

  // Authoring data is deliberately separate from the photographic cut-outs:
  // changing a transition never changes the deterministic scenery stream.
  // The first and last chapter in each cycle are natural route bookends; the
  // intervening chapters are shuffled middle modules.
  var CHAPTER_AUTHORING = freeze({
    city: freeze({
      'district-gateway': freeze({ role: 'entry', entranceCue: 'outer-ring-road', exitCue: 'brick-district', transitionMask: freeze({ type: 'bend', side: -1, strength: 0.54, blendMetres: 18 }) }),
      'red-brick-corridor': freeze({ role: 'middle', entranceCue: 'brick-district', exitCue: 'railway-arches', transitionMask: freeze({ type: 'underpass', side: 0, strength: 0.70, blendMetres: 21 }) }),
      'rail-quarter': freeze({ role: 'middle', entranceCue: 'railway-arches', exitCue: 'bus-corridor', transitionMask: freeze({ type: 'bridge', side: 0, strength: 0.66, blendMetres: 20 }) }),
      'civic-boulevard': freeze({ role: 'middle', entranceCue: 'bus-corridor', exitCue: 'riverside', transitionMask: freeze({ type: 'fog', side: 1, strength: 0.38, blendMetres: 17 }) }),
      'bus-corridor': freeze({ role: 'middle', entranceCue: 'bus-corridor', exitCue: 'railway-arches', transitionMask: freeze({ type: 'bend', side: -1, strength: 0.46, blendMetres: 18 }) }),
      'riverside': freeze({ role: 'middle', entranceCue: 'riverside', exitCue: 'retail-quarter', transitionMask: freeze({ type: 'fog', side: 1, strength: 0.34, blendMetres: 18 }) }),
      'retail-quarter': freeze({ role: 'middle', entranceCue: 'retail-quarter', exitCue: 'underpass', transitionMask: freeze({ type: 'bend', side: 1, strength: 0.50, blendMetres: 19 }) }),
      'city-underpass': freeze({ role: 'middle', entranceCue: 'underpass', exitCue: 'expressway', transitionMask: freeze({ type: 'underpass', side: 0, strength: 0.74, blendMetres: 23 }) }),
      'warehouse-quarter': freeze({ role: 'middle', entranceCue: 'retail-quarter', exitCue: 'underpass', transitionMask: freeze({ type: 'bend', side: 1, strength: 0.58, blendMetres: 18 }) }),
      'expressway-approach': freeze({ role: 'exit', entranceCue: 'underpass', exitCue: 'a-road-exit', transitionMask: freeze({ type: 'overbridge', side: 0, strength: 0.62, blendMetres: 22 }) })
    }),
    rural: freeze({
      'open-dales': freeze({ role: 'entry', entranceCue: 'market-town-edge', exitCue: 'hedge-tunnel', transitionMask: freeze({ type: 'bend', side: -1, strength: 0.48, blendMetres: 18 }) }),
      'dry-stone-run': freeze({ role: 'middle', entranceCue: 'hedge-tunnel', exitCue: 'open-farmland', transitionMask: freeze({ type: 'bend', side: 1, strength: 0.52, blendMetres: 19 }) }),
      'hedge-tunnel': freeze({ role: 'middle', entranceCue: 'hedge-tunnel', exitCue: 'open-farmland', transitionMask: freeze({ type: 'bend', side: -1, strength: 0.66, blendMetres: 20 }) }),
      'farmstead-rise': freeze({ role: 'middle', entranceCue: 'open-farmland', exitCue: 'stone-bridge', transitionMask: freeze({ type: 'fog', side: 0, strength: 0.34, blendMetres: 17 }) }),
      'village-edge': freeze({ role: 'middle', entranceCue: 'stone-bridge', exitCue: 'village-restriction', transitionMask: freeze({ type: 'bridge', side: 0, strength: 0.64, blendMetres: 20 }) }),
      'stone-bridge': freeze({ role: 'middle', entranceCue: 'stone-bridge', exitCue: 'village-restriction', transitionMask: freeze({ type: 'bridge', side: 0, strength: 0.70, blendMetres: 21 }) }),
      'village-restriction': freeze({ role: 'middle', entranceCue: 'village-restriction', exitCue: 'woodland', transitionMask: freeze({ type: 'bend', side: 1, strength: 0.48, blendMetres: 18 }) }),
      'wooded-cutting': freeze({ role: 'middle', entranceCue: 'village-restriction', exitCue: 'woodland', transitionMask: freeze({ type: 'bend', side: -1, strength: 0.62, blendMetres: 20 }) }),
      'moorland-crossing': freeze({ role: 'middle', entranceCue: 'woodland', exitCue: 'moorland', transitionMask: freeze({ type: 'fog', side: 1, strength: 0.42, blendMetres: 18 }) }),
      'dual-carriageway': freeze({ role: 'exit', entranceCue: 'dual-carriageway', exitCue: 'pennine-run', transitionMask: freeze({ type: 'overbridge', side: 0, strength: 0.56, blendMetres: 22 }) })
    }),
    motorway: freeze({
      'luton-approach': freeze({ role: 'entry', entranceCue: 'junction-merge', exitCue: 'cutting', transitionMask: freeze({ type: 'overbridge', side: 0, strength: 0.58, blendMetres: 23 }) }),
      'smart-motorway': freeze({ role: 'middle', entranceCue: 'smart-gantries', exitCue: 'open-cutting', transitionMask: freeze({ type: 'overbridge', side: 0, strength: 0.54, blendMetres: 22 }) }),
      'chalk-cutting': freeze({ role: 'middle', entranceCue: 'cutting', exitCue: 'overbridge', transitionMask: freeze({ type: 'bend', side: 1, strength: 0.48, blendMetres: 21 }) }),
      'm1-overbridge': freeze({ role: 'middle', entranceCue: 'overbridge', exitCue: 'logistics-belt', transitionMask: freeze({ type: 'overbridge', side: 0, strength: 0.68, blendMetres: 24 }) }),
      'logistics-belt': freeze({ role: 'middle', entranceCue: 'warehouse-belt', exitCue: 'smart-motorway', transitionMask: freeze({ type: 'fog', side: -1, strength: 0.30, blendMetres: 20 }) }),
      'works-sector': freeze({ role: 'middle', entranceCue: 'roadworks', exitCue: 'warehouse-belt', transitionMask: freeze({ type: 'underpass', side: 0, strength: 0.68, blendMetres: 24 }) }),
      'services-run': freeze({ role: 'middle', entranceCue: 'services-approach', exitCue: 'open-countryside', transitionMask: freeze({ type: 'overbridge', side: 0, strength: 0.58, blendMetres: 23 }) }),
      'northbound-open': freeze({ role: 'exit', entranceCue: 'open-countryside', exitCue: 'northbound-exit', transitionMask: freeze({ type: 'fog', side: 0, strength: 0.28, blendMetres: 20 }) })
    })
  });

  var CHAPTER_ACOUSTIC_ZONES = freeze({
    'district-gateway': 'built-up',
    'red-brick-corridor': 'built-up',
    'rail-quarter': 'railway-arches',
    'civic-boulevard': 'built-up',
    'bus-corridor': 'built-up',
    'riverside': 'open',
    'retail-quarter': 'built-up',
    'city-underpass': 'underpass',
    'warehouse-quarter': 'built-up',
    'expressway-approach': 'underpass',
    'open-dales': 'hedgerow',
    'dry-stone-run': 'hedgerow',
    'hedge-tunnel': 'hedgerow',
    'farmstead-rise': 'rural-open',
    'village-edge': 'built-up',
    'stone-bridge': 'bridge',
    'village-restriction': 'built-up',
    'wooded-cutting': 'woodland',
    'moorland-crossing': 'rural-open',
    'dual-carriageway': 'bridge',
    'luton-approach': 'motorway-cutting',
    'smart-motorway': 'open-motorway',
    'chalk-cutting': 'motorway-cutting',
    'm1-overbridge': 'bridge',
    'logistics-belt': 'built-up',
    'works-sector': 'roadworks',
    'services-run': 'services',
    'northbound-open': 'open-motorway'
  });

  function decorateChapter(route, chapter) {
    var authored = CHAPTER_AUTHORING[route] && CHAPTER_AUTHORING[route][chapter.id] || {};
    return freeze(Object.assign({}, chapter, {
      role: authored.role || 'middle',
      entranceCue: authored.entranceCue || chapter.id,
      exitCue: authored.exitCue || chapter.id,
      acousticZone: CHAPTER_ACOUSTIC_ZONES[chapter.id] || 'open',
      transitionMask: authored.transitionMask || freeze({ type: 'fog', side: 0, strength: 0.35, blendMetres: 18 })
    }));
  }

  var VISUAL_CHAPTERS = freeze({
    city: freeze(RAW_VISUAL_CHAPTERS.city.map(function authorCity(chapter) { return decorateChapter('city', chapter); })),
    rural: freeze(RAW_VISUAL_CHAPTERS.rural.map(function authorRural(chapter) { return decorateChapter('rural', chapter); })),
    motorway: freeze(RAW_VISUAL_CHAPTERS.motorway.map(function authorMotorway(chapter) { return decorateChapter('motorway', chapter); }))
  });

  var ASSETS = freeze({
    'district-tree': freeze({ id: 'district-tree', url: 'environment/district-tree.webp', width: 628, height: 960, physicalHeight: 9.2, minDistance: 1.2 }),
    'city-warehouse': freeze({ id: 'city-warehouse', url: 'environment/district-warehouse-row.webp', width: 1600, height: 864, physicalHeight: 13.5, minDistance: 1.15 }),
    'avenra-works': freeze({ id: 'avenra-works', url: 'environment/avenra-works.webp', width: 1600, height: 532, physicalHeight: 12.2, minDistance: 1.15 }),
    'rural-estate': freeze({ id: 'rural-estate', url: 'environment/rural-estate-ultra.webp', width: 1200, height: 380, physicalHeight: 9.4, minDistance: 1.15 }),
    'rural-farmstead': freeze({ id: 'rural-farmstead', url: 'environment/rural-farmstead-ultra.webp', width: 1151, height: 380, physicalHeight: 8.8, minDistance: 1.15 }),
    'motorway-campus': freeze({ id: 'motorway-campus', url: 'environment/motorway-campus-ultra.webp', width: 1305, height: 360, physicalHeight: 11.5, minDistance: 1.15 }),
    'motorway-logistics': freeze({ id: 'motorway-logistics', url: 'environment/motorway-logistics-ultra.webp', width: 1356, height: 360, physicalHeight: 12.4, minDistance: 1.15 }),
    'service-toddington': freeze({ id: 'service-toddington', url: 'environment/services/toddington-v262.webp', width: 1280, height: 320, physicalHeight: 9.8, minDistance: 1.15 }),
    'service-watford': freeze({ id: 'service-watford', url: 'environment/services/watford-gap-v262.webp', width: 1280, height: 320, physicalHeight: 9.8, minDistance: 1.15 }),
    'service-woodall': freeze({ id: 'service-woodall', url: 'environment/services/woodall-v262.webp', width: 1280, height: 320, physicalHeight: 9.8, minDistance: 1.15 }),
    'service-woolley': freeze({ id: 'service-woolley', url: 'environment/services/woolley-edge-v262.webp', width: 1280, height: 320, physicalHeight: 9.8, minDistance: 1.15 }),
    'm1-treebelt': freeze({ id: 'm1-treebelt', url: 'environment/m1-treebelt-v334.webp', width: 1280, height: 702, physicalWidth: 36, physicalHeight: 10.8, minDistance: 1.15 }),
    'm1-verge-fence': freeze({ id: 'm1-verge-fence', url: 'environment/m1-verge-fence-v334.webp', width: 1536, height: 512, physicalWidth: 34, physicalHeight: 5.8, minDistance: 1.15 }),
    'm1-yorkshire-hedgerow': freeze({ id: 'm1-yorkshire-hedgerow', url: 'environment/m1-yorkshire-hedgerow-v334.webp', width: 1536, height: 512, physicalWidth: 36, physicalHeight: 6.8, minDistance: 1.15 }),
    'rural-dry-stone-verge': freeze({ id: 'rural-dry-stone-verge', url: 'environment/rural-dry-stone-verge-v335.webp', width: 1536, height: 165, physicalWidth: 14.2, physicalHeight: 1.52, minDistance: 1.15 }),
    'rural-wensleydale-hedge': freeze({ id: 'rural-wensleydale-hedge', url: 'environment/rural-wensleydale-hedge-v335.webp', width: 1536, height: 374, physicalWidth: 14.0, physicalHeight: 3.40, minDistance: 1.15 }),
    'rural-buttertubs-bank': freeze({ id: 'rural-buttertubs-bank', url: 'environment/rural-buttertubs-bank-v335.webp', width: 1536, height: 181, physicalWidth: 13.8, physicalHeight: 1.63, minDistance: 1.15 })
  });

  // City frontage is a deterministic two-sided stream.  These source images
  // are already part of the world manifest; no second Image cache or duplicate
  // asset objects are introduced for the denser v3.3.11 composition.
  var CITY_CONTINUITY_ASSET_IDS_V3311 = freeze([
    'city-warehouse',
    'avenra-works',
    'district-tree',
    'motorway-campus',
    'motorway-logistics'
  ]);

  var CITY_LANDMARK_ASSET_IDS_V3311 = freeze([
    'city-warehouse',
    'avenra-works',
    'motorway-campus',
    'motorway-logistics'
  ]);

  var CITY_LANDMARK_ASSET_SET_V3311 = (function buildCityLandmarkAssetSetV3311() {
    var set = Object.create(null);
    CITY_LANDMARK_ASSET_IDS_V3311.forEach(function addCityLandmarkV3311(assetId) { set[assetId] = true; });
    return set;
  })();

  var RURAL_CONTINUITY_ASSET_IDS = freeze([
    'rural-dry-stone-verge',
    'rural-wensleydale-hedge',
    'rural-buttertubs-bank'
  ]);

  var RURAL_LANDMARK_ASSET_IDS = freeze([
    'rural-estate',
    'rural-farmstead'
  ]);

  var RURAL_LANDMARK_ASSET_SET = (function buildRuralLandmarkAssetSet() {
    var set = Object.create(null);
    RURAL_LANDMARK_ASSET_IDS.forEach(function addRuralLandmark(assetId) { set[assetId] = true; });
    return set;
  })();

  // The Rural route contains two authored 2+1 overtaking sections.  The
  // photographic verge stream follows the same widening envelope so a wall
  // or hedge never drifts across the added lane while it approaches.
  var RURAL_DUAL_SECTIONS_V335 = freeze([
    freeze({ opensAt: 589.5, closesAt: 940.5, taperMetres: 62.4 }),
    freeze({ opensAt: 1642.5, closesAt: 2071.5, taperMetres: 62.4 })
  ]);

  var MOTORWAY_CONTINUITY_ASSET_IDS = freeze([
    'm1-treebelt',
    'm1-verge-fence',
    'm1-yorkshire-hedgerow'
  ]);

  var MOTORWAY_LANDMARK_ASSET_IDS = freeze([
    'motorway-campus',
    'motorway-logistics',
    'avenra-works',
    'service-toddington',
    'service-watford',
    'service-woodall',
    'service-woolley'
  ]);

  var MOTORWAY_LANDMARK_ASSET_SET = (function buildMotorwayLandmarkAssetSet() {
    var set = Object.create(null);
    MOTORWAY_LANDMARK_ASSET_IDS.forEach(function addMotorwayLandmark(assetId) { set[assetId] = true; });
    return set;
  })();

  var BAND_CONFIG = freeze({
    far: freeze({ minDistance: 250.01, maxDistance: 400, spacing: 58, maxPhotos: 7, density: 0.74, scale: 1, verge: 6 }),
    mid: freeze({ minDistance: 70.01, maxDistance: 250, spacing: 64, maxPhotos: 8, density: 0.74, scale: 1, verge: 6 }),
    near: freeze({ minDistance: 1.15, maxDistance: 70, spacing: 64, maxPhotos: 7, density: 0.74, scale: 1, verge: 6 })
  });

  var BACKDROP_BASE = freeze({
    city: freeze({
      nativeWidth: 1536, nativeHeight: 1024, sourceHorizon: 0.488, vanishingX: 0.500,
      sourceVersion: 'v263', safeCropLeft: 0.12, safeCropRight: 0.88, maskFeather: 0.105,
      sourceRoadCorners: freeze({
        vanishing: freeze({ x: 0.500, y: 0.488 }),
        farLeft: freeze({ x: 0.475, y: 0.515 }), farRight: freeze({ x: 0.525, y: 0.515 }),
        nearLeft: freeze({ x: 0.075, y: 0.995 }), nearRight: freeze({ x: 0.925, y: 0.995 })
      })
    }),
    rural: freeze({
      nativeWidth: 1536, nativeHeight: 1024, sourceHorizon: 0.415, vanishingX: 0.495,
      sourceVersion: 'v260', safeCropLeft: 0.08, safeCropRight: 0.92, maskFeather: 0.125,
      sourceRoadCorners: freeze({
        vanishing: freeze({ x: 0.495, y: 0.415 }),
        farLeft: freeze({ x: 0.472, y: 0.445 }), farRight: freeze({ x: 0.518, y: 0.445 }),
        nearLeft: freeze({ x: 0.000, y: 0.716 }), nearRight: freeze({ x: 1.000, y: 0.716 })
      })
    }),
    motorway: freeze({
      nativeWidth: 1536, nativeHeight: 1024, sourceHorizon: 0.593, vanishingX: 0.424,
      sourceVersion: 'v260', safeCropLeft: 0.06, safeCropRight: 0.94, maskFeather: 0.095,
      sourceRoadCorners: freeze({
        vanishing: freeze({ x: 0.424, y: 0.593 }),
        farLeft: freeze({ x: 0.382, y: 0.622 }), farRight: freeze({ x: 0.475, y: 0.622 }),
        nearLeft: freeze({ x: 0.000, y: 0.915 }), nearRight: freeze({ x: 1.000, y: 0.915 })
      })
    })
  });

  // Per-route framing is authored rather than inferred from one phone-shaped
  // viewport.  This prevents tablet layouts from revealing an unrelated road
  // edge or stretching the panoramic plate to compensate.
  var VIEWPORT_CALIBRATION = freeze({
    city: freeze({
      'phone-portrait': freeze({ horizonRatio: 0.430, roadWidthTarget: 0.74, overscan: 1.020, vanishingX: 0.500 }),
      'phone-landscape': freeze({ horizonRatio: 0.424, roadWidthTarget: 0.66, overscan: 1.012, vanishingX: 0.500 }),
      'tablet-portrait': freeze({ horizonRatio: 0.428, roadWidthTarget: 0.68, overscan: 1.018, vanishingX: 0.500 }),
      'tablet-landscape': freeze({ horizonRatio: 0.436, roadWidthTarget: 0.60, overscan: 1.010, vanishingX: 0.500 }),
      'desktop-landscape': freeze({ horizonRatio: 0.432, roadWidthTarget: 0.58, overscan: 1.008, vanishingX: 0.500 })
    }),
    rural: freeze({
      'phone-portrait': freeze({ horizonRatio: 0.415, roadWidthTarget: 0.76, overscan: 1.012, vanishingX: 0.500 }),
      'phone-landscape': freeze({ horizonRatio: 0.410, roadWidthTarget: 0.68, overscan: 1.006, vanishingX: 0.500 }),
      'tablet-portrait': freeze({ horizonRatio: 0.414, roadWidthTarget: 0.70, overscan: 1.010, vanishingX: 0.500 }),
      'tablet-landscape': freeze({ horizonRatio: 0.420, roadWidthTarget: 0.62, overscan: 1.004, vanishingX: 0.500 }),
      'desktop-landscape': freeze({ horizonRatio: 0.417, roadWidthTarget: 0.60, overscan: 1.004, vanishingX: 0.500 })
    }),
    motorway: freeze({
      'phone-portrait': freeze({ horizonRatio: 0.425, roadWidthTarget: 0.72, overscan: 1.014, vanishingX: 0.500 }),
      'phone-landscape': freeze({ horizonRatio: 0.421, roadWidthTarget: 0.64, overscan: 1.006, vanishingX: 0.500 }),
      'tablet-portrait': freeze({ horizonRatio: 0.424, roadWidthTarget: 0.67, overscan: 1.010, vanishingX: 0.500 }),
      'tablet-landscape': freeze({ horizonRatio: 0.431, roadWidthTarget: 0.59, overscan: 1.004, vanishingX: 0.500 }),
      'desktop-landscape': freeze({ horizonRatio: 0.428, roadWidthTarget: 0.57, overscan: 1.004, vanishingX: 0.500 })
    })
  });

  var FALLBACK_ATMOSPHERE = freeze({
    day: freeze({ horizonColour: [174, 194, 204], foregroundColour: [91, 112, 108], lampColour: [232, 244, 247] }),
    dusk: freeze({ horizonColour: [190, 142, 118], foregroundColour: [68, 77, 83], lampColour: [255, 208, 145] }),
    night: freeze({ horizonColour: [24, 39, 55], foregroundColour: [7, 15, 24], lampColour: [206, 226, 235] })
  });

  var CHAPTER_TIMELINE_CACHE = typeof Map === 'function' ? new Map() : null;
  var CHAPTER_TIMELINE_ORDER = [];
  var CHAPTER_TIMELINE_LIMIT = 18;

  function chapterLengthSettings(route, options) {
    var range = CHAPTER_LENGTH_RANGES[route];
    if (Number.isFinite(finite(options && options.chapterLength, NaN))) {
      var fixed = clamp(Math.round(finite(options.chapterLength, CHAPTER_LENGTHS[route])), 80, 120);
      return { minimum: fixed, maximum: fixed, key: fixed + ':' + fixed };
    }
    var minimum = clamp(Math.round(finite(options && options.minimumChapterLength, range.minimum)), 80, 120);
    var maximum = clamp(Math.round(finite(options && options.maximumChapterLength, range.maximum)), minimum, 120);
    return { minimum: minimum, maximum: maximum, key: minimum + ':' + maximum };
  }

  function chapterCatalogIndex(route, seed, chapterNumber) {
    var catalog = VISUAL_CHAPTERS[route];
    var count = catalog.length;
    var position = ((chapterNumber % count) + count) % count;
    if (position === 0) return 0;
    if (position === count - 1) return count - 1;
    var cycle = Math.floor(chapterNumber / count);
    var middleCount = Math.max(1, count - 2);
    var rotation = Math.floor(hashUnit(seed + cycle * 104729 + 17) * middleCount) % middleCount;
    var step = 1 + Math.floor(hashUnit(seed + cycle * 130363 + 31) * middleCount);
    while (gcd(step, middleCount) !== 1) step += 1;
    if (step > middleCount) step = 1;
    return 1 + ((rotation + (position - 1) * step) % middleCount);
  }

  function deterministicChapterLength(route, seed, chapterNumber, settings) {
    if (settings.minimum === settings.maximum) return settings.minimum;
    var span = settings.maximum - settings.minimum;
    var value = hashUnit(seed + chapterNumber * 2654435761 + (route === 'city' ? 1103 : route === 'rural' ? 2909 : 4703));
    return settings.minimum + Math.round(value * span);
  }

  function makeChapterBase(route, seed, chapterNumber, startMetres, settings, options) {
    var catalog = VISUAL_CHAPTERS[route];
    var catalogIndex = chapterCatalogIndex(route, seed, chapterNumber);
    var chapter = catalog[catalogIndex];
    var length = deterministicChapterLength(route, seed, chapterNumber, settings);
    var authoredBlend = finite(chapter.transitionMask && chapter.transitionMask.blendMetres, 18);
    var blendMetres = clamp(finite(options && options.blendMetres, authoredBlend), 12, Math.min(28, length * 0.28));
    return freeze({
      routeId: route,
      seed: seed,
      chapterNumber: chapterNumber,
      cycle: Math.floor(chapterNumber / catalog.length),
      cyclePosition: ((chapterNumber % catalog.length) + catalog.length) % catalog.length,
      catalogIndex: catalogIndex,
      id: chapter.id,
      title: chapter.title,
      cue: chapter.cue,
      role: chapter.role,
      entranceCue: chapter.entranceCue,
      exitCue: chapter.exitCue,
      acousticZone: chapter.acousticZone,
      transitionMask: chapter.transitionMask,
      density: chapter.density,
      assets: chapter.assets,
      assetPool: chapter.assetPool,
      furniture: chapter.furniture,
      startMetres: startMetres,
      endMetres: startMetres + length,
      lengthMetres: length,
      blendMetres: blendMetres
    });
  }

  function timelineKey(route, seed, settings) {
    return route + ':' + seed + ':' + settings.key;
  }

  function chapterTimeline(route, seed, settings, options) {
    var key = timelineKey(route, seed, settings);
    var timeline = CHAPTER_TIMELINE_CACHE && CHAPTER_TIMELINE_CACHE.get(key);
    if (timeline) return timeline;
    timeline = { routeId: route, seed: seed, settings: settings, chapters: [], starts: [0] };
    if (CHAPTER_TIMELINE_CACHE) {
      CHAPTER_TIMELINE_CACHE.set(key, timeline);
      CHAPTER_TIMELINE_ORDER.push(key);
      while (CHAPTER_TIMELINE_ORDER.length > CHAPTER_TIMELINE_LIMIT) {
        CHAPTER_TIMELINE_CACHE.delete(CHAPTER_TIMELINE_ORDER.shift());
      }
    }
    return timeline;
  }

  function ensureTimelineChapter(timeline, chapterNumber, options) {
    while (timeline.chapters.length <= chapterNumber) {
      var number = timeline.chapters.length;
      var start = timeline.starts[number];
      var chapter = makeChapterBase(timeline.routeId, timeline.seed, number, start, timeline.settings, options);
      timeline.chapters.push(chapter);
      timeline.starts.push(chapter.endMetres);
    }
    return timeline.chapters[chapterNumber];
  }

  function findChapterBase(route, seed, distance, options) {
    var settings = chapterLengthSettings(route, options);
    var timeline = chapterTimeline(route, seed, settings, options);
    var estimate = Math.max(0, Math.floor(distance / Math.max(80, settings.minimum)));
    ensureTimelineChapter(timeline, estimate, options);
    while (timeline.starts[timeline.starts.length - 1] <= distance) {
      ensureTimelineChapter(timeline, timeline.chapters.length, options);
    }
    var low = 0;
    var high = timeline.chapters.length - 1;
    while (low <= high) {
      var middle = (low + high) >> 1;
      var candidate = timeline.chapters[middle];
      if (distance < candidate.startMetres) high = middle - 1;
      else if (distance >= candidate.endMetres) low = middle + 1;
      else return { timeline: timeline, chapter: candidate };
    }
    return { timeline: timeline, chapter: ensureTimelineChapter(timeline, Math.max(0, low), options) };
  }

  function chapterBaseAtNumber(route, seed, chapterNumber, options) {
    chapterNumber = Math.max(0, Math.floor(finite(chapterNumber, 0)));
    var settings = chapterLengthSettings(route, options);
    var timeline = chapterTimeline(route, seed, settings, options);
    return ensureTimelineChapter(timeline, chapterNumber, options);
  }

  function materialiseChapter(base, riderDistance, nextBase, outdoor) {
    var localDistance = riderDistance - base.startMetres;
    var transition = smoothstep(base.lengthMetres - base.blendMetres, base.lengthMetres, localDistance);
    return freeze(Object.assign({}, base, {
      kind: 'avenra-visual-chapter-v320',
      version: VERSION,
      localMetres: localDistance,
      progress: clamp(localDistance / base.lengthMetres, 0, 1),
      transition: transition,
      transitionVisible: transition > 0.001,
      authoredExitCue: base.exitCue,
      exitCue: nextBase && nextBase.entranceCue || base.exitCue,
      nextId: nextBase && nextBase.id || base.id,
      nextTitle: nextBase && nextBase.title || base.title,
      nextRole: nextBase && nextBase.role || base.role,
      nextEntranceCue: nextBase && nextBase.entranceCue || base.exitCue,
      outdoor: outdoor !== false
    }));
  }

  function parseChapterArguments(routeOrState, worldDistance, seed, options) {
    var state;
    if (routeOrState && typeof routeOrState === 'object') {
      state = routeOrState;
      options = worldDistance && typeof worldDistance === 'object' ? worldDistance : (options || {});
    } else {
      state = { routeId: routeOrState, worldDistance: worldDistance, seed: seed };
      options = options || {};
    }
    return { state: state || {}, options: options && typeof options === 'object' ? options : {} };
  }

  function getVisualChapter(routeOrState, worldDistance, seed, options) {
    var parsed = parseChapterArguments(routeOrState, worldDistance, seed, options);
    var state = parsed.state;
    options = parsed.options;
    var route = stateRoute(state, options);
    var distance = stateDistance(state, options);
    var resolvedSeed = stateSeed(state, options);
    var located = findChapterBase(route, resolvedSeed, distance, options);
    var base = located.chapter;
    var nextBase = ensureTimelineChapter(located.timeline, base.chapterNumber + 1, options);
    return materialiseChapter(base, distance, nextBase, !isActualTunnel(state, options));
  }

  function getVisualChapterBuffer(routeOrState, worldDistance, seed, options) {
    var parsed = parseChapterArguments(routeOrState, worldDistance, seed, options);
    var state = parsed.state;
    options = parsed.options;
    var route = stateRoute(state, options);
    var distance = stateDistance(state, options);
    var resolvedSeed = stateSeed(state, options);
    var current = getVisualChapter(state, options);
    var countHint = finite(options.chapterBufferCount,
      finite(options.chapterCount,
        finite(options.count,
          Number.isFinite(finite(options.behind, NaN)) || Number.isFinite(finite(options.ahead, NaN)) ? finite(options.behind, 0) + finite(options.ahead, 4) + 1 : NaN)));
    var requestedCount = Number.isFinite(countHint) ? clamp(Math.round(countHint), 4, 6) : 6;
    // The road only moves forwards: keep the current module plus three to five
    // ahead.  A separate previous reference supports a symmetric transition
    // veil without retaining already-passed scenery in the rolling buffer.
    var firstNumber = current.chapterNumber;
    var lastNumber = firstNumber + requestedCount - 1;
    var chapters = [];
    for (var chapterNumber = firstNumber; chapterNumber <= lastNumber; chapterNumber += 1) {
      var base = chapterBaseAtNumber(route, resolvedSeed, chapterNumber, options);
      var nextBase = chapterBaseAtNumber(route, resolvedSeed, chapterNumber + 1, options);
      var chapter = materialiseChapter(base, distance, nextBase, !isActualTunnel(state, options));
      var relative = chapterNumber - current.chapterNumber;
      chapters.push(freeze(Object.assign({}, chapter, {
        relativeIndex: relative,
        behind: relative < 0,
        ahead: relative > 0,
        active: relative === 0 || relative === 1,
        blendWeight: relative === 0 ? 1 - current.transition : (relative === 1 ? current.transition : 0)
      })));
    }
    var next = chapters.find(function findNext(chapter) { return chapter.relativeIndex === 1; }) ||
      materialiseChapter(chapterBaseAtNumber(route, resolvedSeed, current.chapterNumber + 1, options), distance, chapterBaseAtNumber(route, resolvedSeed, current.chapterNumber + 2, options), !isActualTunnel(state, options));
    var previous = current.chapterNumber > 0 ? materialiseChapter(
      chapterBaseAtNumber(route, resolvedSeed, current.chapterNumber - 1, options),
      distance,
      chapterBaseAtNumber(route, resolvedSeed, current.chapterNumber, options),
      !isActualTunnel(state, options)
    ) : null;
    return freeze({
      kind: 'avenra-visual-chapter-buffer-v320',
      version: VERSION,
      routeId: route,
      seed: resolvedSeed,
      worldDistance: distance,
      currentIndex: 0,
      current: current,
      next: next,
      previous: previous,
      mix: current.transition,
      count: chapters.length,
      chapters: freeze(chapters)
    });
  }

  function getVisualChapterBlend(routeOrState, worldDistance, seed, options) {
    var parsed = parseChapterArguments(routeOrState, worldDistance, seed, options);
    var buffer = getVisualChapterBuffer(parsed.state, parsed.options);
    return freeze({
      kind: 'avenra-visual-chapter-blend-v320',
      version: VERSION,
      current: buffer.current,
      next: buffer.next,
      mix: buffer.mix,
      transitionMetres: buffer.current.blendMetres,
      transitionMask: buffer.current.transitionMask,
      buffer: buffer
    });
  }

  function routeHorizon(route) {
    return route === 'rural' ? 0.415 : (route === 'motorway' ? 0.425 : 0.43);
  }

  function viewportCalibration(route, deviceClass, orientation) {
    var routeProfiles = VIEWPORT_CALIBRATION[route] || VIEWPORT_CALIBRATION.city;
    return routeProfiles[deviceClass] || routeProfiles['tablet-' + orientation] || routeProfiles['phone-' + orientation] || routeProfiles['phone-portrait'];
  }

  function getCameraProfile(widthOrConfig, height, routeOrState, options) {
    var width = widthOrConfig;
    var state = routeOrState && typeof routeOrState === 'object' ? routeOrState : { routeId: routeOrState };
    if (widthOrConfig && typeof widthOrConfig === 'object') {
      var config = widthOrConfig;
      width = config.width;
      height = config.height;
      state = config.state || config;
      options = Object.assign({}, config.options || {}, config);
    }
    options = options && typeof options === 'object' ? options : {};
    width = Math.max(2, finite(width, 390));
    height = Math.max(2, finite(height, 844));
    var route = stateRoute(state, options);
    var aspect = width / height;
    var shortSide = Math.min(width, height);
    var longSide = Math.max(width, height);
    var tablet = shortSide >= 560 && longSide >= 720;
    var orientation = aspect >= 1 ? 'landscape' : 'portrait';
    var deviceClass = tablet ? 'tablet-' + orientation : (shortSide >= 720 ? 'desktop-' + orientation : 'phone-' + orientation);
    var calibration = viewportCalibration(route, deviceClass, orientation);
    var speed = clamp(finite(state && state.speed, 0), 0, 180);
    var referenceSpeed = clamp(finite(options.referenceSpeedMph, 132), 80, 180);
    var speedNormal = clamp(speed / referenceSpeed, 0, 1);
    var boostActive = !!(state && state.boost && !state.boostLocked && !state.brake);
    var requestedVerticalFov = finite(options.verticalFov, NaN);
    var defaultBaseFov = orientation === 'portrait' ? 66 : 61;
    // The compiled v3.1 renderer passes a legacy FOV which already contains a
    // 7° speed ramp and a 2° boost step.  Remove that ramp before applying the
    // restrained v3.2 2–4° lens change; explicit baseVerticalFov wins.
    var inferredBaseFov = Number.isFinite(requestedVerticalFov) ?
      requestedVerticalFov - speedNormal * 7 - (boostActive ? 2 : 0) : defaultBaseFov;
    var baseVerticalFov = clamp(finite(options.baseVerticalFov, inferredBaseFov), 48, 68);
    var maximumSpeedFov = clamp(finite(options.maximumSpeedFovIncrease, route === 'motorway' ? 4 : (route === 'rural' ? 2.6 : 3.2)), 2, 4);
    var speedFovIncrease = clamp(smoothstep(0.04, 1, speedNormal) * maximumSpeedFov + (boostActive ? 0.35 : 0), 0, maximumSpeedFov);
    var verticalFov = clamp(baseVerticalFov + speedFovIncrease, 48, 72);
    var focalLength = height / (2 * Math.tan(verticalFov * Math.PI / 360));
    var derivedHorizontalFov = 2 * Math.atan((width * 0.5) / focalLength) * 180 / Math.PI;
    var horizontalFov = clamp(finite(options.horizontalFov, derivedHorizontalFov), 42, 112);
    var horizonRatio = clamp(finite(options.horizonRatio, calibration.horizonRatio), 0.37, 0.47);
    var roadWidthTarget = clamp(finite(options.roadWidthTarget, calibration.roadWidthTarget), 0.48, 0.82);
    var horizonY = height * horizonRatio;
    var targetVanishingX = width * clamp(finite(options.projectedVanishingXRatio, calibration.vanishingX), 0.35, 0.65);
    var nearRoadY = height * 1.015;
    var targetRoadCorners = freeze({
      vanishing: freeze({ x: targetVanishingX, y: horizonY }),
      farLeft: freeze({ x: targetVanishingX - width * roadWidthTarget * 0.034, y: horizonY + height * 0.035 }),
      farRight: freeze({ x: targetVanishingX + width * roadWidthTarget * 0.034, y: horizonY + height * 0.035 }),
      nearLeft: freeze({ x: targetVanishingX - width * roadWidthTarget * 0.5, y: nearRoadY }),
      nearRight: freeze({ x: targetVanishingX + width * roadWidthTarget * 0.5, y: nearRoadY })
    });
    return freeze({
      kind: 'avenra-camera-profile-v320',
      version: VERSION,
      routeId: route,
      width: width,
      height: height,
      aspect: aspect,
      deviceClass: deviceClass,
      tablet: tablet,
      orientation: orientation,
      calibrationKey: deviceClass,
      calibration: calibration,
      baseVerticalFovDegrees: baseVerticalFov,
      requestedLegacyVerticalFovDegrees: Number.isFinite(requestedVerticalFov) ? requestedVerticalFov : null,
      speedFovIncreaseDegrees: speedFovIncrease,
      maximumSpeedFovIncreaseDegrees: maximumSpeedFov,
      verticalFov: verticalFov,
      horizontalFov: horizontalFov,
      focalLength: focalLength,
      focalPixels: focalLength,
      verticalFovDegrees: verticalFov,
      horizontalFovDegrees: horizontalFov,
      horizonRatio: horizonRatio,
      horizonY: horizonY,
      roadWidthTarget: roadWidthTarget,
      targetVanishingX: targetVanishingX,
      targetRoadCorners: targetRoadCorners,
      horizonBobRatio: 0.00035 + speedNormal * 0.00045,
      bobRatio: 0.0012 + speedNormal * 0.0013,
      eyeHeightMetres: clamp(finite(options.baseEyeHeightMetres, 1.56), 1.25, 1.9),
      nearPlaneMetres: clamp(finite(options.baseNearPlaneMetres, route === 'city' ? 1.08 : 1.15), 0.75, 2),
      bikeMotionShare: 0.92,
      eyeLineStability: 0.985,
      overscanScale: calibration.overscan,
      cockpitWidthRatio: tablet && orientation === 'landscape' ? 1.02 : (orientation === 'portrait' ? 1.28 : 1.12),
      viewportClass: deviceClass
    });
  }

  function projectNormalisedPoint(point, destination) {
    return freeze({
      x: destination.x + point.x * destination.width,
      y: destination.y + point.y * destination.height
    });
  }

  function projectRoadCorners(corners, destination) {
    return freeze({
      vanishing: projectNormalisedPoint(corners.vanishing, destination),
      farLeft: projectNormalisedPoint(corners.farLeft, destination),
      farRight: projectNormalisedPoint(corners.farRight, destination),
      nearLeft: projectNormalisedPoint(corners.nearLeft, destination),
      nearRight: projectNormalisedPoint(corners.nearRight, destination)
    });
  }

  function getBackdropMetadata(routeOrState, width, height, options) {
    var state = routeOrState && typeof routeOrState === 'object' ? routeOrState : { routeId: routeOrState };
    if (width && typeof width === 'object') {
      options = width;
      width = options.width;
      height = options.height;
    }
    options = options && typeof options === 'object' ? options : {};
    width = Math.max(2, finite(width, options.width || 390));
    height = Math.max(2, finite(height, options.height || 844));
    var route = stateRoute(state, options);
    var time = normaliseTime(state.timeOfDay || state.time || options.timeOfDay || options.time);
    var weather = normaliseWeather(state.weather || state.weatherId || options.weather);
    var base = BACKDROP_BASE[route];
    var camera = getCameraProfile(width, height, state, options);
    var sourceWidth = base.nativeWidth;
    var sourceHeight = base.nativeHeight;
    var targetHorizonY = finite(options.horizonY, camera.horizonY);
    var projectedVanishingX = clamp(finite(options.projectedVanishingX, camera.targetVanishingX), width * 0.35, width * 0.65);
    var projectedVanishingRatio = projectedVanishingX / width;
    var maximumSafeVisibleWidth = Math.min(
      (base.vanishingX - base.safeCropLeft) / Math.max(0.01, projectedVanishingRatio),
      (base.safeCropRight - base.vanishingX) / Math.max(0.01, 1 - projectedVanishingRatio)
    );
    maximumSafeVisibleWidth = clamp(maximumSafeVisibleWidth, 0.28, 1);
    var scaleForWidth = width * camera.overscanScale / sourceWidth;
    var scaleForHorizon = targetHorizonY / (sourceHeight * base.sourceHorizon);
    var scaleForSafeCrop = width / (sourceWidth * maximumSafeVisibleWidth);
    var scale = Math.max(scaleForWidth, scaleForHorizon, scaleForSafeCrop);
    var destinationWidth = sourceWidth * scale;
    var destinationHeight = sourceHeight * scale;
    var visibleSourceWidthRatio = clamp(width / destinationWidth, 0.01, 1);
    var safeSpan = base.safeCropRight - base.safeCropLeft;
    var idealVisibleLeft = base.vanishingX - projectedVanishingX / destinationWidth;
    var minimumVisibleLeft = visibleSourceWidthRatio <= safeSpan ? base.safeCropLeft : 0;
    var maximumVisibleLeft = visibleSourceWidthRatio <= safeSpan ? base.safeCropRight - visibleSourceWidthRatio : 1 - visibleSourceWidthRatio;
    var visibleSourceLeft = clamp(idealVisibleLeft, minimumVisibleLeft, Math.max(minimumVisibleLeft, maximumVisibleLeft));
    var destinationX = -visibleSourceLeft * destinationWidth;
    var destinationY = targetHorizonY - destinationHeight * base.sourceHorizon;
    var maskStart = clamp(targetHorizonY + height * 0.012, 0, height);
    var maskSolid = clamp(maskStart + height * base.maskFeather, maskStart + 2, height);
    var version = base.sourceVersion;
    var assetWeather = weather === 'post-rain' ? 'clear' : weather;
    var destination = freeze({ x: destinationX, y: destinationY, width: destinationWidth, height: destinationHeight });
    var projectedRoadCorners = projectRoadCorners(base.sourceRoadCorners, destination);
    var targetRoadCorners = camera.targetRoadCorners;
    var nearRoadWidth = projectedRoadCorners.nearRight.x - projectedRoadCorners.nearLeft.x;
    var targetNearRoadWidth = targetRoadCorners.nearRight.x - targetRoadCorners.nearLeft.x;
    var roadAlignmentError = freeze({
      vanishingX: projectedRoadCorners.vanishing.x - targetRoadCorners.vanishing.x,
      vanishingY: projectedRoadCorners.vanishing.y - targetRoadCorners.vanishing.y,
      nearWidth: nearRoadWidth - targetNearRoadWidth,
      nearCentreX: (projectedRoadCorners.nearLeft.x + projectedRoadCorners.nearRight.x - targetRoadCorners.nearLeft.x - targetRoadCorners.nearRight.x) * 0.5
    });
    return freeze({
      kind: 'avenra-backdrop-metadata-v320',
      version: VERSION,
      routeId: route,
      timeOfDay: time,
      weather: weather,
      assetWeather: assetWeather,
      postRainTreatment: weather === 'post-rain' ? freeze({ wetSurface: 0.42, lingeringSpray: 0.20, skyLift: 0.08 }) : null,
      url: 'environment/cinematic/' + route + '-' + time + '-' + assetWeather + '-' + version + '.webp',
      sourceWidth: sourceWidth,
      sourceHeight: sourceHeight,
      nativeAspectRatio: sourceWidth / sourceHeight,
      sourceHorizon: base.sourceHorizon,
      sourceVanishingX: base.vanishingX,
      safeCropLeft: base.safeCropLeft,
      safeCropRight: base.safeCropRight,
      viewportAspect: width / height,
      viewportClass: camera.viewportClass,
      orientation: camera.orientation,
      calibration: camera.calibration,
      plateParallax: 0.006,
      scaleMode: 'native-aspect-safe-crop',
      uniformScale: scale,
      stretched: false,
      source: freeze({ x: 0, y: 0, width: sourceWidth, height: sourceHeight }),
      visibleSourceCrop: (function visibleCrop() {
        var cropY = Math.max(0, -destinationY / scale);
        return freeze({
          x: visibleSourceLeft * sourceWidth,
          y: cropY,
          width: visibleSourceWidthRatio * sourceWidth,
          height: Math.min(sourceHeight - cropY, height / scale)
        });
      })(),
      destination: destination,
      destinationHorizon: targetHorizonY,
      destinationVanishingX: projectedVanishingX,
      sourceRoadCorners: base.sourceRoadCorners,
      destinationRoadCorners: projectedRoadCorners,
      targetRoadCorners: targetRoadCorners,
      roadAlignment: freeze({ enforcedBy: 'uniform-plate-plus-road-mask', errorPixels: roadAlignmentError }),
      maskStartY: maskStart,
      maskEndY: maskSolid,
      roadMask: freeze({
        startY: maskStart,
        solidY: maskSolid,
        bottomY: height,
        corners: freeze([
          targetRoadCorners.farLeft,
          targetRoadCorners.farRight,
          targetRoadCorners.nearRight,
          targetRoadCorners.nearLeft
        ])
      }),
      farOnly: true
    });
  }

  function fallbackProfile(state, options) {
    var time = normaliseTime(state && (state.timeOfDay || state.time) || options && options.timeOfDay);
    var weather = normaliseWeather(state && state.weather || options && options.weather);
    var palette = FALLBACK_ATMOSPHERE[time];
    var postRain = weather === 'post-rain';
    return {
      routeId: stateRoute(state, options), timeOfDay: time, weather: weather,
      horizonColour: palette.horizonColour, foregroundColour: palette.foregroundColour,
      lampColour: palette.lampColour,
      visibilityStart: weather === 'fog' ? 45 : (postRain ? 235 : 260),
      visibilityEnd: weather === 'fog' ? 225 : (postRain ? 485 : 510),
      objectLoss: weather === 'fog' ? 0.95 : (postRain ? 0.42 : 0.34),
      wetness: postRain ? 0.42 : (weather === 'rain' ? 0.82 : weather === 'storm' ? 1 : 0),
      lingeringSpray: postRain ? 0.20 : 0,
      drawDistance: 520
    };
  }

  function resolveProfile(state, options) {
    if (options && options.profile) return options.profile;
    var requestedWeather = normaliseWeather(state && state.weather || options && options.weather);
    if (typeof namespace.getAtmosphericProfile === 'function') {
      try {
        var resolved = namespace.getAtmosphericProfile(state || {}, options || {});
        if (requestedWeather !== 'post-rain') return resolved;
        var postRainFallback = fallbackProfile(state, options);
        return Object.assign({}, resolved || {}, postRainFallback, {
          horizonColour: resolved && resolved.horizonColour || postRainFallback.horizonColour,
          foregroundColour: resolved && resolved.foregroundColour || postRainFallback.foregroundColour,
          lampColour: resolved && resolved.lampColour || postRainFallback.lampColour
        });
      } catch (error) {}
    }
    return fallbackProfile(state, options);
  }

  function distanceAlpha(distance, profile, options) {
    if (typeof namespace.distanceLodAlpha === 'function') {
      try { return namespace.distanceLodAlpha(distance, profile, options || {}); } catch (error) {}
    }
    if (distance < 0 || distance >= finite(profile.drawDistance, 520)) return 0;
    var loss = smoothstep(finite(profile.visibilityStart, 260), finite(profile.visibilityEnd, 510), distance);
    var edge = 1 - smoothstep(finite(profile.drawDistance, 520) - 80, finite(profile.drawDistance, 520), distance);
    return clamp((1 - finite(profile.objectLoss, 0.34) * loss) * edge, 0, 1);
  }

  function maskCinematicPlate(ctx, widthOrConfig, height, horizonY, state, options) {
    var width = widthOrConfig;
    if (widthOrConfig && typeof widthOrConfig === 'object') {
      var config = widthOrConfig;
      width = config.width || (ctx && ctx.canvas && ctx.canvas.width);
      height = config.height || (ctx && ctx.canvas && ctx.canvas.height);
      horizonY = config.horizonY != null ? config.horizonY : config.horizon;
      state = config.state || config;
      options = Object.assign({}, config.state || {}, config, config.options || {});
    }
    state = state && typeof state === 'object' ? state : {};
    options = options && typeof options === 'object' ? options : {};
    if (!isCinematic(state, options) || isActualTunnel(state, options) || !ctx || typeof ctx.createLinearGradient !== 'function' || typeof ctx.fillRect !== 'function') return false;
    width = Math.max(2, finite(width, ctx.canvas && ctx.canvas.width));
    height = Math.max(2, finite(height, ctx.canvas && ctx.canvas.height));
    if (!(width > 1 && height > 1)) return false;
    var routeId = stateRoute(state, options);
    // v3.3.11 prepares the City plate as a sky/skyline layer before this hook.
    // Painting the former full-width foreground veil here would cover that
    // feathered lower skyline and needlessly compute/fill its mask.
    if (routeId === 'city' && CITY_BACKDROP_MASK_PASSTHROUGH_V3311) return false;
    var metadata = options.backdropMetadata || getBackdropMetadata(state, width, height, Object.assign({}, options, { horizonY: horizonY }));
    var profile = resolveProfile(state, options);
    var resolvedHorizon = finite(horizonY, height * routeHorizon(routeId));
    var cityIsolation = routeId === 'city';
    var startY = cityIsolation
      ? clamp(resolvedHorizon - height * 0.10, 0, height)
      : clamp(finite(metadata.roadMask && metadata.roadMask.startY, resolvedHorizon), 0, height);
    var solidY = cityIsolation
      ? clamp(resolvedHorizon + height * 0.045, startY + 1, height)
      : clamp(finite(metadata.roadMask && metadata.roadMask.solidY, startY + height * 0.10), startY + 1, height);
    var gradient = ctx.createLinearGradient(0, startY, 0, solidY);
    gradient.addColorStop(0, rgba(profile.horizonColour, 0));
    gradient.addColorStop(cityIsolation ? 0.46 : 0.30, rgba(profile.horizonColour, cityIsolation ? 0.34 : 0.74));
    gradient.addColorStop(cityIsolation ? 0.82 : 0.72, rgba(profile.foregroundColour, cityIsolation ? 0.995 : 0.98));
    gradient.addColorStop(1, rgba(profile.foregroundColour, 1));
    ctx.save();
    if (!cityIsolation && metadata.roadMask && metadata.roadMask.corners && metadata.roadMask.corners.length === 4 && typeof ctx.clip === 'function') {
      // Keep the native-aspect photographic verges while replacing only the
      // source road with the projected procedural carriageway.  The polygon
      // is authored from the same road corners returned to the renderer.
      var corners = metadata.roadMask.corners;
      ctx.beginPath();
      ctx.moveTo(corners[0].x, corners[0].y);
      ctx.lineTo(corners[1].x, corners[1].y);
      ctx.lineTo(corners[2].x, corners[2].y);
      ctx.lineTo(corners[3].x, corners[3].y);
      ctx.closePath();
      ctx.clip();
      ctx.fillStyle = gradient;
      ctx.fillRect(0, startY, width, Math.max(1, solidY - startY));
      ctx.fillStyle = rgba(profile.foregroundColour, 1);
      ctx.fillRect(0, solidY, width, Math.max(1, height - solidY));
    } else {
      ctx.fillStyle = gradient;
      ctx.fillRect(0, startY, width, Math.max(1, solidY - startY));
      ctx.fillStyle = rgba(profile.foregroundColour, 1);
      ctx.fillRect(0, solidY, width, Math.max(1, height - solidY));
    }
    ctx.restore();
    if (!cityIsolation) {
      ctx.save();
      // Retain photographic peripheral detail at distance, then gently hand
      // the close verge to moving cut-outs and furniture.  This preserves
      // texture quality without leaving a static foreground on wide tablets.
      var peripheralFade = ctx.createLinearGradient(0, solidY, 0, height);
      peripheralFade.addColorStop(0, rgba(profile.foregroundColour, 0));
      peripheralFade.addColorStop(0.48, rgba(profile.foregroundColour, 0.08));
      peripheralFade.addColorStop(1, rgba(profile.foregroundColour, 0.52));
      ctx.fillStyle = peripheralFade;
      ctx.fillRect(0, solidY, width, Math.max(1, height - solidY));
      var horizonVeil = ctx.createLinearGradient(0, startY - height * 0.025, 0, solidY);
      horizonVeil.addColorStop(0, rgba(profile.horizonColour, 0));
      horizonVeil.addColorStop(0.58, rgba(profile.horizonColour, 0.12));
      horizonVeil.addColorStop(1, rgba(profile.horizonColour, 0));
      ctx.fillStyle = horizonVeil;
      ctx.fillRect(0, Math.max(0, startY - height * 0.025), width, Math.max(1, solidY - startY + height * 0.025));
      ctx.restore();
    }
    return true;
  }

  var IMAGE_CACHE = Object.create(null);

  function resolveAssetUrl(path) {
    try {
      if (globalScope.document && globalScope.document.baseURI && typeof globalScope.URL === 'function') {
        var url = new globalScope.URL(path, globalScope.document.baseURI);
        return url.protocol === 'file:' ? url.pathname : url.href;
      }
    } catch (error) {}
    return path;
  }

  function imageEntry(asset) {
    var existing = IMAGE_CACHE[asset.url];
    if (existing) return existing;
    if (typeof globalScope.Image !== 'function') return null;
    var image = new globalScope.Image();
    var entry = { image: image, status: 'loading', url: asset.url };
    IMAGE_CACHE[asset.url] = entry;
    function markDecoded() {
      if (entry.status === 'ready' || entry.status === 'decoding') return;
      if (typeof image.decode !== 'function') {
        entry.status = 'ready';
        return;
      }
      entry.status = 'decoding';
      try {
        image.decode().then(function decoded() {
          entry.status = 'ready';
        }, function decodeFailed() {
          entry.status = finite(image.naturalWidth, 0) > 0 ? 'ready' : 'error';
        });
      } catch (error) {
        entry.status = finite(image.naturalWidth, 0) > 0 ? 'ready' : 'error';
      }
    }
    image.onload = markDecoded;
    image.onerror = function markFailed() { entry.status = 'error'; };
    try { image.decoding = 'async'; } catch (error) {}
    image.src = resolveAssetUrl(asset.url);
    if (image.complete && finite(image.naturalWidth, 0) > 0) markDecoded();
    return entry;
  }

  function getPhotoWorldReadiness(state, options) {
    state = state && typeof state === 'object' ? state : {};
    options = options && typeof options === 'object' ? options : {};
    if (!isCinematic(state, options) || isActualTunnel(state, options)) return freeze({ ready: false, ratio: 0, total: 0, loaded: 0, failed: 0 });
    var route = stateRoute(state, options);
    var blend = getVisualChapterBlend(state, options);
    var buffer = blend.buffer || getVisualChapterBuffer(state, options);
    var ids = [];
    var activeIds = [];
    var seen = Object.create(null);
    var activeSeen = Object.create(null);
    [blend.current, blend.next].forEach(function collectActive(chapter) {
      (chapter && chapter.assetPool || []).forEach(function collectAsset(assetId) {
        if (!activeSeen[assetId]) { activeSeen[assetId] = true; activeIds.push(assetId); }
      });
    });
    (buffer.chapters || [blend.current, blend.next]).forEach(function collectBuffer(chapter) {
      (chapter && chapter.assetPool || []).forEach(function collectAsset(assetId) {
        if (!seen[assetId]) { seen[assetId] = true; ids.push(assetId); }
      });
    });
    if (route === 'motorway') {
      MOTORWAY_CONTINUITY_ASSET_IDS.concat(MOTORWAY_LANDMARK_ASSET_IDS).forEach(function collectMotorwayAsset(assetId) {
        if (!activeSeen[assetId]) { activeSeen[assetId] = true; activeIds.push(assetId); }
        if (!seen[assetId]) { seen[assetId] = true; ids.push(assetId); }
      });
    }
    if (route === 'rural') {
      RURAL_CONTINUITY_ASSET_IDS.concat(RURAL_LANDMARK_ASSET_IDS).forEach(function collectRuralAsset(assetId) {
        if (!activeSeen[assetId]) { activeSeen[assetId] = true; activeIds.push(assetId); }
        if (!seen[assetId]) { seen[assetId] = true; ids.push(assetId); }
      });
    }
    var loaded = 0;
    var failed = 0;
    var activeLoaded = 0;
    var activeFailed = 0;
    ids.forEach(function preload(assetId) {
      var asset = ASSETS[assetId];
      var entry = asset && imageEntry(asset);
      if (entry && entry.status === 'ready') loaded += 1;
      else if (!entry || entry.status === 'error') failed += 1;
    });
    activeIds.forEach(function inspectActive(assetId) {
      var asset = ASSETS[assetId];
      var entry = asset && imageEntry(asset);
      if (entry && entry.status === 'ready') activeLoaded += 1;
      else if (!entry || entry.status === 'error') activeFailed += 1;
    });
    var ready = activeIds.length > 0 && activeLoaded === activeIds.length && activeFailed === 0;
    return freeze({
      ready: ready,
      ratio: activeIds.length ? activeLoaded / activeIds.length : 0,
      total: activeIds.length,
      loaded: activeLoaded,
      failed: activeFailed,
      bufferTotal: ids.length,
      bufferLoaded: loaded,
      bufferFailed: failed,
      bufferRatio: ids.length ? loaded / ids.length : 0
    });
  }

  function isPhotoWorldReady(state, options) {
    return getPhotoWorldReadiness(state, options).ready;
  }

  function resolvePhotoWorldReady(state, options) {
    // Runtime readiness is tri-state: an explicit false means the backdrop or
    // chapter pack is not ready this frame and must never be recomputed into
    // true by this helper.  Only an absent property delegates to the cache.
    if (options && Object.prototype.hasOwnProperty.call(options, 'photoWorldReady')) {
      return options.photoWorldReady === true;
    }
    return isPhotoWorldReady(state, options);
  }

  function roadHalfWidth(route, options) {
    return finite(options && options.roadHalfWidth, route === 'motorway' ? 5.4 : (route === 'city' ? 4.2 : 3.8));
  }

  function bandConfig(band, options) {
    var base = BAND_CONFIG[band] || BAND_CONFIG.mid;
    var overrides = options && options.band && options.band[band] || {};
    return {
      minDistance: clamp(finite(overrides.minDistance, base.minDistance), 1.05, 1000),
      maxDistance: clamp(finite(overrides.maxDistance, base.maxDistance), 8, 1400),
      spacing: clamp(finite(overrides.spacing, base.spacing), 12, 220),
      maxPhotos: clamp(Math.round(finite(overrides.maxPhotos, base.maxPhotos)), 0, 24),
      density: clamp(finite(overrides.density, base.density), 0, 1.4),
      scale: clamp(finite(overrides.scale, base.scale), 0.45, 1.8),
      verge: clamp(finite(overrides.verge, base.verge), 1, 30)
    };
  }

  function cityContinuityFamilyV3311(chapterId) {
    if (chapterId === 'red-brick-corridor' || chapterId === 'rail-quarter') return 'brick-corridor';
    if (chapterId === 'civic-boulevard' || chapterId === 'bus-corridor' || chapterId === 'riverside') return 'civic-green';
    if (chapterId === 'city-underpass' || chapterId === 'warehouse-quarter' || chapterId === 'expressway-approach') return 'outer-city';
    return 'district-mixed';
  }

  function cityContinuityAssetIdV3311(family, chapterId, side, cell, seed) {
    var choice = hashUnit(cell * 40507 + seed + (side < 0 ? 503 : 1009));
    var sequence = Math.abs(cell * 2 + (side > 0 ? 1 : 0));
    if (family === 'brick-corridor') {
      if (sequence % 6 === 2) return 'district-tree';
      return choice < 0.82 ? 'city-warehouse' : 'avenra-works';
    }
    if (family === 'civic-green') {
      if (sequence % 3 !== 0 || choice < 0.34) return 'district-tree';
      return choice < 0.78 ? 'city-warehouse' : 'avenra-works';
    }
    if (family === 'outer-city') {
      if (chapterId === 'city-underpass') {
        return sequence % 4 === 1 ? 'district-tree' : (choice < 0.68 ? 'city-warehouse' : 'avenra-works');
      }
      if (choice < 0.30) return 'motorway-logistics';
      if (choice < 0.56) return 'motorway-campus';
      if (choice < 0.76) return 'avenra-works';
      if (choice < 0.92) return 'city-warehouse';
      return 'district-tree';
    }
    if (sequence % 4 === 1) return 'district-tree';
    return choice < 0.68 ? 'city-warehouse' : 'avenra-works';
  }

  function cityContinuityHeightV3311(assetId, family, side, cell, seed) {
    var baseHeight = assetId === 'district-tree' ? 6.5 :
      assetId === 'city-warehouse' ? 10.4 :
        assetId === 'avenra-works' ? 8.6 : 8.8;
    if (family === 'civic-green' && assetId !== 'district-tree') baseHeight *= 0.88;
    if (family === 'outer-city' && assetId !== 'district-tree') baseHeight *= 1.05;
    var variation = 0.92 + hashUnit(cell * 65537 + seed + (side < 0 ? 1201 : 2411)) * 0.16;
    return baseHeight * variation;
  }

  function cityContinuityCropV3311(asset, assetId, side, cell, seed) {
    if (assetId === 'district-tree') return freeze({ x: 0, y: 0, width: asset.width, height: asset.height });
    var minimum = assetId === 'city-warehouse' ? 0.42 :
      assetId === 'avenra-works' ? 0.48 : 0.58;
    var range = assetId === 'city-warehouse' ? 0.18 :
      assetId === 'avenra-works' ? 0.20 : 0.20;
    var fraction = minimum + hashUnit(cell * 130363 + seed + (side < 0 ? 3011 : 6029)) * range;
    var sourceWidth = asset.width * fraction;
    var sourceX = (asset.width - sourceWidth) * hashUnit(cell * 16908799 + seed + (side < 0 ? 4001 : 8009));
    return freeze({ x: sourceX, y: 0, width: sourceWidth, height: asset.height });
  }

  function buildCityContinuityItemsV3311(state, route, worldDistance, seed, band, options) {
    if (!CITY_CONTINUITY_V3311 || route !== 'city' || band !== 'mid' ||
        !isCinematic(state, options) || isActualTunnel(state, options) ||
        options && options.scenery === false) return [];
    var halfRoad = roadHalfWidth(route, options);
    var minimumDistance = 8;
    var maximumDistance = 220;
    var maximumItems = clamp(Math.round(finite(
      options && options.maxCityContinuity,
      CITY_CONTINUITY_MAX_ITEMS_V3311
    )), 0, CITY_CONTINUITY_MAX_ITEMS_V3311);
    var items = [];

    [-1, 1].forEach(function buildCitySideV3311(side) {
      var spacing = side < 0 ? 34 : 38;
      var offset = side < 0 ? 0.18 : 0.62;
      var firstCell = Math.floor((worldDistance + minimumDistance) / spacing - offset) - 1;
      var lastCell = Math.ceil((worldDistance + maximumDistance) / spacing - offset) + 1;
      for (var cell = lastCell; cell >= firstCell && items.length < maximumItems; cell -= 1) {
        var placementSeed = hashUnit(cell * 73856093 + seed + (side < 0 ? 32003 : 64007));
        var jitter = (placementSeed - 0.5) * spacing * 0.10;
        var absoluteDistance = (cell + offset) * spacing + jitter;
        var distance = absoluteDistance - worldDistance;
        if (distance < minimumDistance || distance > maximumDistance) continue;
        var chapter = getVisualChapter({
          routeId: route,
          worldDistance: Math.max(0, absoluteDistance),
          seed: seed
        }, options);
        var family = cityContinuityFamilyV3311(chapter.id);
        var assetId = cityContinuityAssetIdV3311(family, chapter.id, side, cell, seed);
        var asset = ASSETS[assetId];
        if (!asset) continue;
        var sourceRect = cityContinuityCropV3311(asset, assetId, side, cell, seed);
        var physicalHeight = cityContinuityHeightV3311(assetId, family, side, cell, seed);
        var physicalWidth = physicalHeight * (sourceRect.width / sourceRect.height);
        var clearanceMetres = 1.85 + hashUnit(cell * 104729 + seed + (side < 0 ? 5003 : 10007)) * 0.55;
        var roadEdgeX = side * halfRoad;
        var roadX = roadEdgeX + side * (clearanceMetres + physicalWidth * 0.5);
        var coverageHalfLength = spacing * 0.60;
        var nearAlpha = smoothstep(minimumDistance, 14, distance);
        var farAlpha = 1 - smoothstep(190, maximumDistance, distance);
        items.push(freeze({
          id: 'scenery:city:continuity:' + side + ':' + cell,
          kind: 'scenery-strip', stream: 'city-continuity', family: family,
          continuity: true, pass: 'mid', band: 'mid', routeId: route,
          chapterId: chapter.id, side: side, cell: cell, seed: placementSeed,
          assetId: assetId, asset: asset, sourceRect: sourceRect,
          absoluteDistance: absoluteDistance, distance: distance,
          coverageStart: absoluteDistance - coverageHalfLength,
          coverageEnd: absoluteDistance + coverageHalfLength,
          coverageStartMetres: absoluteDistance - coverageHalfLength,
          coverageEndMetres: absoluteDistance + coverageHalfLength,
          roadX: roadX, roadEdgeX: roadEdgeX, clearanceMetres: clearanceMetres,
          physicalWidth: physicalWidth, physicalHeight: physicalHeight,
          edgeAlpha: clamp(nearAlpha * farAlpha, 0, 1), mirrorSafe: false
        }));
      }
    });
    return items;
  }

  function motorwayContinuityFamily(state, options) {
    var stage = String(
      state && (state.routeStage || state.stage) ||
      options && (options.routeStage || options.stage) ||
      ''
    ).toLowerCase();
    if (stage === 'city') return 'luton-screening';
    if (stage === 'tunnel') return 'midlands-woodland';
    if (stage === 'expressway') return 'yorkshire-hedgerow';
    var elapsed = stateElapsed(state, options);
    if (elapsed >= 62) return 'yorkshire-hedgerow';
    if (elapsed >= 31) return 'midlands-woodland';
    return 'luton-screening';
  }

  function motorwayContinuityAssetId(family, side, cell, seed) {
    var familySalt = family === 'luton-screening' ? 811 : (family === 'midlands-woodland' ? 1627 : 3253);
    var choice = hashUnit(cell * 40507 + seed + familySalt + (side < 0 ? 37 : 79));
    if (family === 'luton-screening') {
      if (side < 0) return choice < 0.56 ? 'm1-treebelt' : 'm1-verge-fence';
      return choice < 0.82 ? 'm1-verge-fence' : 'm1-yorkshire-hedgerow';
    }
    if (family === 'midlands-woodland') {
      if (side < 0) return choice < 0.70 ? 'm1-treebelt' : 'm1-yorkshire-hedgerow';
      if (choice < 0.58) return 'm1-yorkshire-hedgerow';
      return choice < 0.90 ? 'm1-verge-fence' : 'm1-treebelt';
    }
    if (side < 0) return choice < 0.72 ? 'm1-yorkshire-hedgerow' : 'm1-treebelt';
    return choice < 0.90 ? 'm1-yorkshire-hedgerow' : 'm1-verge-fence';
  }

  function motorwayContinuityHeight(assetId, side, cell, seed) {
    var variation = 0.92 + hashUnit(cell * 65537 + seed + (side < 0 ? 101 : 211)) * 0.16;
    var baseHeight = assetId === 'm1-treebelt' ? (side < 0 ? 10.8 : 8.4) :
      assetId === 'm1-verge-fence' ? (side < 0 ? 5.8 : 4.8) :
      (side < 0 ? 7.0 : 5.7);
    return baseHeight * variation;
  }

  function buildMotorwayContinuityItems(state, route, worldDistance, seed, band, options) {
    if (route !== 'motorway' || band !== 'mid' || !isCinematic(state, options) || options && options.scenery === false) return [];
    var family = motorwayContinuityFamily(state, options);
    var halfRoad = roadHalfWidth(route, options);
    var minimumDistance = 8;
    var maximumDistance = 235;
    var maximumItems = clamp(Math.round(finite(options && options.maxMotorwayContinuity, 18)), 0, 18);
    var items = [];

    [-1, 1].forEach(function buildSide(side) {
      var spacing = side < 0 ? 27 : 34;
      var offset = side < 0 ? 0.22 : 0.64;
      var firstCell = Math.floor((worldDistance + minimumDistance) / spacing - offset) - 1;
      var lastCell = Math.ceil((worldDistance + maximumDistance) / spacing - offset) + 1;
      for (var cell = lastCell; cell >= firstCell && items.length < maximumItems; cell -= 1) {
        var placementSeed = hashUnit(cell * 73856093 + seed + (side < 0 ? 47017 : 96013));
        var jitter = (placementSeed - 0.5) * spacing * 0.10;
        var absoluteDistance = (cell + offset) * spacing + jitter;
        var distance = absoluteDistance - worldDistance;
        if (distance < minimumDistance || distance > maximumDistance) continue;
        var assetId = motorwayContinuityAssetId(family, side, cell, seed);
        var asset = ASSETS[assetId];
        if (!asset) continue;
        var physicalHeight = motorwayContinuityHeight(assetId, side, cell, seed);
        var cropFraction = 0.80 + hashUnit(cell * 130363 + seed + (side < 0 ? 17 : 43)) * 0.16;
        var sourceWidth = asset.width * cropFraction;
        var sourceX = (asset.width - sourceWidth) * hashUnit(cell * 16908799 + seed + (side < 0 ? 29 : 61));
        var sourceRect = freeze({ x: sourceX, y: 0, width: sourceWidth, height: asset.height });
        var physicalWidth = physicalHeight * (sourceRect.width / sourceRect.height);
        var verge = side < 0 ? 2.55 : 1.35;
        // Hold the inner edge near the verge without stretching the source
        // photograph. Placement width is stable across regional asset swaps;
        // rendered width retains each cut-out's natural pixel aspect.
        var placementHalfWidth = (side < 0 ? 11.3 : 9.8) *
          (0.96 + hashUnit(cell * 104729 + seed + (side < 0 ? 13 : 31)) * 0.08);
        var roadX = side * (halfRoad + verge + placementHalfWidth);
        var coverageHalfLength = spacing * 0.59;
        var nearAlpha = smoothstep(minimumDistance, 14, distance);
        var farAlpha = 1 - smoothstep(205, maximumDistance, distance);
        items.push(freeze({
          id: 'scenery:motorway:continuity:' + side + ':' + cell,
          kind: 'scenery-strip', stream: 'motorway-continuity', family: family,
          continuity: true, pass: 'mid', band: 'mid', routeId: route,
          side: side, cell: cell, seed: placementSeed,
          assetId: assetId, asset: asset, sourceRect: sourceRect,
          absoluteDistance: absoluteDistance, distance: distance,
          coverageStart: absoluteDistance - coverageHalfLength,
          coverageEnd: absoluteDistance + coverageHalfLength,
          coverageStartMetres: absoluteDistance - coverageHalfLength,
          coverageEndMetres: absoluteDistance + coverageHalfLength,
          roadX: roadX, physicalWidth: physicalWidth, physicalHeight: physicalHeight,
          edgeAlpha: clamp(nearAlpha * farAlpha, 0, 1), mirrorSafe: false
        }));
      }
    });
    return items;
  }

  function ruralContinuityFamily(state, options) {
    var stage = String(
      state && (state.routeStage || state.stage) ||
      options && (options.routeStage || options.stage) ||
      ''
    ).toLowerCase();
    if (stage === 'city') return 'ribblehead-verges';
    if (stage === 'tunnel') return 'wensleydale-hedgerows';
    if (stage === 'expressway') return 'buttertubs-banks';
    var elapsed = stateElapsed(state, options);
    if (elapsed >= 62) return 'buttertubs-banks';
    if (elapsed >= 31) return 'wensleydale-hedgerows';
    return 'ribblehead-verges';
  }

  function ruralDualStrengthAt(absoluteDistance) {
    var strength = 0;
    RURAL_DUAL_SECTIONS_V335.forEach(function inspectSection(section) {
      var sectionStrength = 0;
      if (absoluteDistance >= section.opensAt && absoluteDistance <= section.closesAt) {
        sectionStrength = 1;
      } else if (absoluteDistance >= section.opensAt - section.taperMetres && absoluteDistance < section.opensAt) {
        sectionStrength = smoothstep(section.opensAt - section.taperMetres, section.opensAt, absoluteDistance);
      } else if (absoluteDistance > section.closesAt && absoluteDistance <= section.closesAt + section.taperMetres) {
        sectionStrength = 1 - smoothstep(section.closesAt, section.closesAt + section.taperMetres, absoluteDistance);
      }
      strength = Math.max(strength, sectionStrength);
    });
    return strength;
  }

  function ruralRoadEdgesAt(absoluteDistance) {
    var dualStrength = ruralDualStrengthAt(absoluteDistance);
    return {
      left: -mix(3.8, 5.65, dualStrength),
      right: mix(3.8, 4.05, dualStrength),
      dualStrength: dualStrength
    };
  }

  function ruralContinuityAssetId(family, chapterId, side, cell, seed) {
    var sequence = Math.abs((cell + (side < 0 ? 0 : 1)) % 3);
    var variation = hashUnit(cell * 40507 + seed + (side < 0 ? 191 : 383));

    // Chapter identity is sampled at each strip's absolute world position.
    // It adds believable micro-scenes without reseeding the moving geometry.
    if (chapterId === 'dual-carriageway' || chapterId === 'moorland-crossing') {
      return sequence === 1 ? 'rural-dry-stone-verge' : 'rural-buttertubs-bank';
    }
    if (chapterId === 'dry-stone-run' || chapterId === 'stone-bridge') {
      return sequence === 2 && family === 'wensleydale-hedgerows' ?
        'rural-wensleydale-hedge' : 'rural-dry-stone-verge';
    }

    if (family === 'ribblehead-verges') {
      if (chapterId === 'hedge-tunnel' && sequence === 1) return 'rural-wensleydale-hedge';
      return sequence === 1 || variation < 0.18 ? 'rural-buttertubs-bank' : 'rural-dry-stone-verge';
    }
    if (family === 'wensleydale-hedgerows') {
      if (chapterId === 'open-dales' || chapterId === 'farmstead-rise') {
        return sequence === 0 ? 'rural-dry-stone-verge' : 'rural-wensleydale-hedge';
      }
      return sequence === 2 ? 'rural-dry-stone-verge' : 'rural-wensleydale-hedge';
    }
    return sequence === 1 ? 'rural-dry-stone-verge' : 'rural-buttertubs-bank';
  }

  function ruralContinuityHeight(assetId, side, cell, seed) {
    var asset = ASSETS[assetId];
    var variation = 0.93 + hashUnit(cell * 65537 + seed + (side < 0 ? 431 : 863)) * 0.14;
    var sideScale = side < 0 ? 1 : 0.94;
    return finite(asset && asset.physicalHeight, 1.6) * variation * sideScale;
  }

  function buildRuralContinuityItems(state, route, worldDistance, seed, band, options) {
    if (route !== 'rural' || band !== 'mid' || !isCinematic(state, options) || options && options.scenery === false) return [];
    var family = ruralContinuityFamily(state, options);
    var minimumDistance = 9;
    var maximumDistance = 225;
    var maximumItems = clamp(Math.round(finite(options && options.maxRuralContinuity, 16)), 0, 16);
    var items = [];

    [-1, 1].forEach(function buildSide(side) {
      var spacing = side < 0 ? 30 : 38;
      var offset = side < 0 ? 0.18 : 0.62;
      var firstCell = Math.floor((worldDistance + minimumDistance) / spacing - offset) - 1;
      var lastCell = Math.ceil((worldDistance + maximumDistance) / spacing - offset) + 1;
      for (var cell = lastCell; cell >= firstCell && items.length < maximumItems; cell -= 1) {
        var placementSeed = hashUnit(cell * 73856093 + seed + (side < 0 ? 21401 : 42821));
        var jitter = (placementSeed - 0.5) * spacing * 0.10;
        var absoluteDistance = (cell + offset) * spacing + jitter;
        var distance = absoluteDistance - worldDistance;
        if (distance < minimumDistance || distance > maximumDistance) continue;
        var chapter = getVisualChapter({
          routeId: route,
          worldDistance: Math.max(0, absoluteDistance),
          seed: seed
        }, options);
        var assetId = ruralContinuityAssetId(family, chapter.id, side, cell, seed);
        var asset = ASSETS[assetId];
        if (!asset) continue;
        var physicalHeight = ruralContinuityHeight(assetId, side, cell, seed);
        var cropFraction = 0.82 + hashUnit(cell * 130363 + seed + (side < 0 ? 71 : 137)) * 0.14;
        var sourceWidth = asset.width * cropFraction;
        var sourceX = (asset.width - sourceWidth) * hashUnit(cell * 16908799 + seed + (side < 0 ? 149 : 293));
        var sourceRect = freeze({ x: sourceX, y: 0, width: sourceWidth, height: asset.height });
        var physicalWidth = physicalHeight * (sourceRect.width / sourceRect.height);
        var roadEdges = ruralRoadEdgesAt(absoluteDistance);

        // Placement geometry is deliberately independent of regional asset
        // choice.  A family change can restyle a cell but never makes it jump.
        var placementHalfWidth = 7.05 *
          (0.97 + hashUnit(cell * 104729 + seed + (side < 0 ? 157 : 313)) * 0.06);
        var clearVerge = side < 0 ? 2.35 : 1.95;
        var roadEdgeX = side < 0 ? roadEdges.left : roadEdges.right;
        var roadX = roadEdgeX + side * (clearVerge + placementHalfWidth);
        var coverageHalfLength = spacing * 0.62;
        var nearAlpha = smoothstep(minimumDistance, 16, distance);
        var farAlpha = 1 - smoothstep(190, maximumDistance, distance);
        items.push(freeze({
          id: 'scenery:rural:continuity:' + side + ':' + cell,
          kind: 'scenery-strip', stream: 'rural-continuity', family: family,
          continuity: true, pass: 'mid', band: 'mid', routeId: route,
          chapterId: chapter.id, side: side, cell: cell, seed: placementSeed,
          assetId: assetId, asset: asset, sourceRect: sourceRect,
          absoluteDistance: absoluteDistance, distance: distance,
          coverageStart: absoluteDistance - coverageHalfLength,
          coverageEnd: absoluteDistance + coverageHalfLength,
          coverageStartMetres: absoluteDistance - coverageHalfLength,
          coverageEndMetres: absoluteDistance + coverageHalfLength,
          roadX: roadX, roadEdgeX: roadEdgeX,
          dualStrength: roadEdges.dualStrength,
          physicalWidth: physicalWidth, physicalHeight: physicalHeight,
          edgeAlpha: clamp(nearAlpha * farAlpha, 0, 1), mirrorSafe: false
        }));
      }
    });
    return items;
  }

  function buildPhotoItems(state, route, worldDistance, seed, band, options) {
    var config = bandConfig(band, options);
    if (route === 'motorway' && band === 'far') {
      config.maxDistance = clamp(finite(options && options.motorwayLandmarkDistance, 500), 400, 520);
    }
    // One route-wide stream crosses the far/mid/near boundaries unchanged.
    // The pass only selects a distance interval; it never reseeds or rescales
    // the landmark, which prevents identity pops at 70 m and 250 m.
    var streamSpacing = clamp(finite(
      options && options.photoSpacing,
      route === 'motorway' ? 168 : (route === 'rural' ? 184 : 188)
    ), 36, 220);
    var minCell = Math.floor((worldDistance + config.minDistance) / streamSpacing) - 1;
    var maxCell = Math.ceil((worldDistance + config.maxDistance) / streamSpacing) + 1;
    var routeSalt = route === 'city' ? 1103 : (route === 'rural' ? 2909 : 4703);
    var items = [];
    for (var cell = maxCell; cell >= minCell && items.length < config.maxPhotos; cell -= 1) {
      var baseHash = hashUnit(cell * 73856093 + seed + routeSalt);
      var jitter = (hashUnit(cell * 19349663 + seed) - 0.5) * streamSpacing * 0.54;
      var absoluteDistance = cell * streamSpacing + streamSpacing * 0.5 + jitter;
      var distance = absoluteDistance - worldDistance;
      if (distance < config.minDistance || distance > config.maxDistance) continue;
      var chapter = getVisualChapter({ routeId: route, worldDistance: Math.max(0, absoluteDistance), seed: seed }, options);
      var nextChapter = chapterBaseAtNumber(route, seed, chapter.chapterNumber + 1, options);
      var incoming = materialiseChapter(nextChapter, absoluteDistance, chapterBaseAtNumber(route, seed, chapter.chapterNumber + 2, options), true);
      var chapterMix = chapter.transition;
      var requestedDensity = options && options.worldDensity != null ? options.worldDensity :
        (options && options.density != null ? finite(options.density, 1) * (route === 'motorway' ? 0.72 : 0.74) : 0.74);
      var probability = clamp(finite(requestedDensity, 0.74) * mix(chapter.density, incoming.density, chapterMix), 0, 0.96);
      if (route === 'rural') {
        probability *= 0.58;
        if (ruralContinuityFamily(state, options) === 'buttertubs-banks') probability = 0;
      }
      if (baseHash > probability) continue;
      var assetChapter = hashUnit(cell * 2246822519 + seed) < chapterMix ? incoming : chapter;
      var pool = assetChapter.assetPool;
      if (route === 'city') {
        pool = pool.filter(function keepCityLandmarkV3311(candidateId) { return !!CITY_LANDMARK_ASSET_SET_V3311[candidateId]; });
      }
      if (route === 'motorway') {
        pool = pool.filter(function keepMotorwayLandmark(candidateId) { return !!MOTORWAY_LANDMARK_ASSET_SET[candidateId]; });
      }
      if (route === 'rural') {
        pool = pool.filter(function keepRuralLandmark(candidateId) { return !!RURAL_LANDMARK_ASSET_SET[candidateId]; });
      }
      if (!pool || !pool.length) continue;
      var assetId = route === 'rural' ?
        RURAL_LANDMARK_ASSET_IDS[Math.floor(hashUnit(seed + 991) * RURAL_LANDMARK_ASSET_IDS.length) % RURAL_LANDMARK_ASSET_IDS.length] :
        pool[Math.floor(hashUnit(cell * 83492791 + seed) * pool.length) % pool.length];
      var asset = ASSETS[assetId];
      if (!asset || distance < asset.minDistance) continue;
      var scale = 0.86 + hashUnit(cell * 2654435761 + seed) * 0.30;
      var physicalHeight = asset.physicalHeight * scale;
      var aspect = asset.width / asset.height;
      var physicalWidth = physicalHeight * aspect;
      var side = hashUnit(cell * 97531 + seed) < 0.5 ? -1 : 1;
      var baseVerge = route === 'motorway' ? 7.2 : (route === 'city' ? 5.4 : 8.4);
      var verge = baseVerge + hashUnit(cell * 314159 + seed) * (route === 'city' ? 5.2 : 3.6);
      var lateral = roadHalfWidth(route, options) + verge + physicalWidth * 0.5;
      items.push(freeze({
        id: 'photo:' + route + ':' + cell,
        kind: 'photo', stream: route === 'motorway' ? 'landmark' : (route === 'rural' ? 'rural-landmark' : 'city-landmark'), band: band, routeId: route, chapterId: assetChapter.id,
        sourceChapterId: chapter.id, nextChapterId: incoming.id, chapterMix: chapterMix,
        transitionMask: chapter.transitionMask,
        assetId: assetId, asset: asset, distance: distance, absoluteDistance: absoluteDistance,
        roadX: side * lateral, side: side, physicalHeight: physicalHeight,
        physicalWidth: physicalWidth, seed: baseHash,
        edgeAlpha: (band === 'far' ? 1 - smoothstep(config.maxDistance - 60, config.maxDistance, distance) : 1) *
          (band === 'near' ? smoothstep(config.minDistance, config.minDistance + 1.6, distance) : 1)
      }));
    }
    return items;
  }

  function buildFurnitureItems(route, worldDistance, seed, band, options) {
    if (band !== 'near' || options && options.furniture === false) return [];
    var maxDistance = clamp(finite(options && options.furnitureDistance, 145), 35, 240);
    var spacing = route === 'motorway' ? 22 : (route === 'rural' ? 29 : 34);
    var minCell = Math.floor((worldDistance + 1.15) / spacing) - 1;
    var maxCell = Math.ceil((worldDistance + maxDistance) / spacing) + 1;
    var maxItems = clamp(Math.round(finite(options && options.maxFurniture, route === 'motorway' ? 18 : 13)), 0, 30);
    var salt = route === 'city' ? 701 : (route === 'rural' ? 1709 : 2711);
    var items = [];
    for (var cell = maxCell; cell >= minCell && items.length < maxItems; cell -= 1) {
      var jitter = (hashUnit(cell * 49999 + seed + salt) - 0.5) * spacing * 0.20;
      var absoluteDistance = cell * spacing + spacing * 0.5 + jitter;
      var distance = absoluteDistance - worldDistance;
      if (distance < 1.15 || distance > maxDistance) continue;
      var chapter = getVisualChapter({ routeId: route, worldDistance: Math.max(0, absoluteDistance), seed: seed }, options);
      var nextChapter = materialiseChapter(chapterBaseAtNumber(route, seed, chapter.chapterNumber + 1, options), absoluteDistance, chapterBaseAtNumber(route, seed, chapter.chapterNumber + 2, options), true);
      var chapterMix = chapter.transition;
      var furnitureChapter = hashUnit(cell * 2246822519 + seed + 47) < chapterMix ? nextChapter : chapter;
      var pool = furnitureChapter.furniture;
      if (!pool.length) continue;
      var chance = route === 'motorway' ? 0.84 : 0.68;
      if (hashUnit(cell * 104729 + seed) > chance) continue;
      var kind = pool[Math.floor(hashUnit(cell * 130363 + seed) * pool.length) % pool.length];
      var paired = kind === 'motorway-post' || kind === 'rural-post' || kind === 'bollard';
      var side = hashUnit(cell * 65537 + seed) < 0.5 ? -1 : 1;
      var lateral = roadHalfWidth(route, options) + (kind === 'high-mast' ? 4.7 : kind === 'utility-pole' ? 3.8 : 2.0);
      items.push(freeze({
        id: 'furniture:' + route + ':' + cell + ':' + side, kind: 'furniture', furniture: kind,
        band: band, routeId: route, chapterId: furnitureChapter.id,
        sourceChapterId: chapter.id, nextChapterId: nextChapter.id, chapterMix: chapterMix,
        transitionMask: chapter.transitionMask, distance: distance,
        absoluteDistance: absoluteDistance, roadX: side * lateral, side: side,
        seed: hashUnit(cell * 524287 + seed),
        edgeAlpha: 1 - smoothstep(maxDistance - 30, maxDistance, distance)
      }));
      if (paired && items.length < maxItems) {
        items.push(freeze({
          id: 'furniture:' + route + ':' + cell + ':' + -side, kind: 'furniture', furniture: kind,
          band: band, routeId: route, chapterId: furnitureChapter.id,
          sourceChapterId: chapter.id, nextChapterId: nextChapter.id, chapterMix: chapterMix,
          transitionMask: chapter.transitionMask, distance: distance + 0.4,
          absoluteDistance: absoluteDistance + 0.4, roadX: -side * lateral, side: -side,
          seed: hashUnit(cell * 524287 + seed + 19),
          edgeAlpha: 1 - smoothstep(maxDistance - 30, maxDistance, distance + 0.4)
        }));
      }
    }
    return items;
  }

  function getVisualLayerPlan(stateOrRoute, options) {
    var state = stateOrRoute && typeof stateOrRoute === 'object' ? stateOrRoute : { routeId: stateOrRoute };
    options = options && typeof options === 'object' ? options : {};
    var route = stateRoute(state, options);
    var worldDistance = stateDistance(state, options);
    var seed = stateSeed(state, options);
    var band = String(options.band || options.layer || options.pass || 'mid').toLowerCase();
    if (!BAND_CONFIG[band]) band = 'mid';
    var items = buildPhotoItems(state, route, worldDistance, seed, band, options)
      .concat(buildCityContinuityItemsV3311(state, route, worldDistance, seed, band, options))
      .concat(buildMotorwayContinuityItems(state, route, worldDistance, seed, band, options))
      .concat(buildRuralContinuityItems(state, route, worldDistance, seed, band, options))
      .concat(buildFurnitureItems(route, worldDistance, seed, band, options));
    items.sort(function farToNear(first, second) {
      if (second.distance !== first.distance) return second.distance - first.distance;
      return first.id < second.id ? -1 : (first.id > second.id ? 1 : 0);
    });
    var chapterBlend = getVisualChapterBlend(state, options);
    return freeze({
      kind: 'avenra-layer-plan-v320', version: VERSION, routeId: route, band: band,
      worldDistance: worldDistance, seed: seed, chapter: chapterBlend.current,
      chapterBlend: chapterBlend, chapterBuffer: chapterBlend.buffer,
      items: freeze(items)
    });
  }

  function projectSafe(projector, roadX, height, distance) {
    try {
      var point = projector(roadX, height, distance);
      if (!point || !Number.isFinite(finite(point.x, NaN)) || !Number.isFinite(finite(point.y, NaN))) return null;
      return point;
    } catch (error) {
      return null;
    }
  }

  function photoFilter(profile, distance) {
    var visibility = smoothstep(finite(profile.visibilityStart, 260) * 0.72, finite(profile.visibilityEnd, 510), distance);
    var brightness = profile.timeOfDay === 'night' ? 0.57 : (profile.timeOfDay === 'dusk' ? 0.84 : 1);
    var saturation = profile.weather === 'fog' ? 0.62 : (profile.weather === 'rain' || profile.weather === 'storm' ? 0.78 : profile.weather === 'post-rain' ? 1.02 : 0.96);
    var contrast = profile.weather === 'fog' ? 0.82 : 0.96;
    if (profile.weather === 'post-rain') {
      brightness *= 1.025;
      contrast = 1.01;
    }
    brightness = mix(brightness, profile.timeOfDay === 'night' ? 0.48 : 0.92, visibility * 0.28);
    return 'brightness(' + brightness.toFixed(3) + ') saturate(' + saturation.toFixed(3) + ') contrast(' + contrast.toFixed(3) + ')';
  }

  // On portrait phones the decoded HTMLImage is both the highest-detail
  // source and the cheapest steady-state draw source.  Drawing a treated
  // Canvas back into the animation Canvas forced Chrome/Android to resample
  // the same large transparent surface for every repeated strip.  Keep a
  // lightweight wrapper around the shared decoded Image instead: there is no
  // intermediate allocation, no downsample and no live animation-frame
  // filter.  Wider viewports retain the condition-treated surface path.
  var PHONE_DIRECT_SOURCE_V3313 = true;
  var PHONE_DIRECT_SOURCE_MAX_WIDTH_V3313 = 720;
  var PHONE_DIRECT_SOURCE_CACHE_LIMIT_V3313 = 32;
  var PHONE_DIRECT_SOURCE_CACHE_V3313 = typeof Map === 'function' ? new Map() : null;
  var PHONE_DIRECT_SOURCE_ORDER_V3313 = [];
  var PHONE_DIRECT_SOURCE_CREATIONS_V3313 = 0;
  var PHONE_DIRECT_SOURCE_HITS_V3313 = 0;

  function isPhoneDirectSourceItemV3313(item, viewport) {
    if (!PHONE_DIRECT_SOURCE_V3313 || !item) return false;
    var viewportWidth = finite(viewport && viewport.width, 0);
    if (!(viewportWidth > 0 && viewportWidth <= PHONE_DIRECT_SOURCE_MAX_WIDTH_V3313)) return false;
    if (item.routeId === 'city') {
      return item.stream === 'city-continuity' || item.stream === 'city-landmark';
    }
    if (item.routeId === 'motorway' || item.routeId === 'rural') {
      return item.stream === 'motorway-continuity' || item.stream === 'rural-continuity' ||
        item.stream === 'landmark' || item.kind === 'photo';
    }
    return false;
  }

  function phoneDirectSourceKeyV3313(item) {
    return [item.routeId, item.assetId || item.asset && item.asset.url || item.id || '', 'phone-direct-source'].join('|');
  }

  function touchPhoneDirectSourceV3313(key) {
    var position = PHONE_DIRECT_SOURCE_ORDER_V3313.indexOf(key);
    if (position >= 0) PHONE_DIRECT_SOURCE_ORDER_V3313.splice(position, 1);
    PHONE_DIRECT_SOURCE_ORDER_V3313.push(key);
  }

  function getPhoneDirectSourceV3313(item, entry, sourceWidth, sourceHeight, viewport) {
    if (!isPhoneDirectSourceItemV3313(item, viewport) || !entry || !entry.image ||
        !PHONE_DIRECT_SOURCE_CACHE_V3313) return null;
    var key = phoneDirectSourceKeyV3313(item);
    var cached = PHONE_DIRECT_SOURCE_CACHE_V3313.get(key);
    if (cached && cached.image === entry.image) {
      PHONE_DIRECT_SOURCE_HITS_V3313 += 1;
      touchPhoneDirectSourceV3313(key);
      return cached;
    }
    var value = freeze({
      image: entry.image,
      scaleX: 1,
      scaleY: 1,
      width: sourceWidth,
      height: sourceHeight,
      ownsBackingStore: false,
      filtered: false,
      directSource: true,
      fallback: 'phone-direct-source',
      backend: 'html-image',
      routeId: item.routeId,
      assetId: item.assetId || ''
    });
    PHONE_DIRECT_SOURCE_CACHE_V3313.set(key, value);
    touchPhoneDirectSourceV3313(key);
    PHONE_DIRECT_SOURCE_CREATIONS_V3313 += 1;
    while (PHONE_DIRECT_SOURCE_ORDER_V3313.length > PHONE_DIRECT_SOURCE_CACHE_LIMIT_V3313) {
      var oldest = PHONE_DIRECT_SOURCE_ORDER_V3313.shift();
      PHONE_DIRECT_SOURCE_CACHE_V3313.delete(oldest);
    }
    return value;
  }

  function getPhoneDirectSourceStatsV3313() {
    var cityRecords = 0;
    var motorwayRecords = 0;
    if (PHONE_DIRECT_SOURCE_CACHE_V3313) {
      PHONE_DIRECT_SOURCE_CACHE_V3313.forEach(function countDirectSource(record) {
        if (record && record.routeId === 'city') cityRecords += 1;
        if (record && record.routeId === 'motorway') motorwayRecords += 1;
      });
    }
    return freeze({
      version: VERSION,
      enabled: PHONE_DIRECT_SOURCE_V3313,
      maximumWidth: PHONE_DIRECT_SOURCE_MAX_WIDTH_V3313,
      backend: 'html-image',
      fallback: 'phone-direct-source',
      liveFilters: false,
      intermediateCanvases: false,
      size: PHONE_DIRECT_SOURCE_CACHE_V3313 ? PHONE_DIRECT_SOURCE_CACHE_V3313.size : 0,
      limit: PHONE_DIRECT_SOURCE_CACHE_LIMIT_V3313,
      creations: PHONE_DIRECT_SOURCE_CREATIONS_V3313,
      hits: PHONE_DIRECT_SOURCE_HITS_V3313,
      cityRecords: cityRecords,
      motorwayRecords: motorwayRecords
    });
  }

  // M1 continuity reuses three large transparent photographs many times in
  // one frame. Reapplying Canvas2D filter to every crop made mobile browsers
  // repeatedly filter and resample the same texture. Bake one condition-
  // treated, mobile-sized surface per asset; placement and crop geometry stay
  // identical, with the original draw path retained as a safe fallback.
  var MOTORWAY_SCENERY_SURFACE_CACHE_V339 = typeof Map === 'function' ? new Map() : null;
  var MOTORWAY_SCENERY_SURFACE_ORDER_V339 = [];
  var MOTORWAY_SCENERY_SURFACE_LIMIT_V339 = 6;
  var MOTORWAY_SCENERY_VIEWPORT_CLIP_V339 = true;
  var MOTORWAY_SCENERY_PHONE_SURFACE_WIDTH_V3312 = 512;
  var MOTORWAY_SCENERY_LIVE_FILTERS_V3312 = false;
  var MOTORWAY_SCENERY_SURFACE_BACKEND_V3312 = null;
  var MOTORWAY_SCENERY_SURFACE_FALLBACKS_V3312 = 0;
  var MOTORWAY_SCENERY_SURFACE_FAILURES_V3312 = 0;

  function motorwayScenerySurfaceWidthV339(viewportWidth, sourceWidth) {
    // A 512px source still samples the nearest portrait-phone strip at about
    // one source pixel per backing-store pixel, while quartering the largest
    // treated M1 texture.  The old 1024px phone surface dominated drawMidField
    // on high-DPR Android devices even after placement clipping.
    var maximumWidth = viewportWidth <= 720 ? MOTORWAY_SCENERY_PHONE_SURFACE_WIDTH_V3312 :
      (viewportWidth <= 1100 ? 1024 : sourceWidth);
    return Math.max(1, Math.min(sourceWidth, maximumWidth));
  }

  function motorwayScenerySurfaceKeyV339(item, profile, width) {
    return [item && item.routeId || '', item && item.assetId || '', photoFilter(profile, 128), width].join('|');
  }

  function releaseMotorwaySceneryBackingStoreV3312(value) {
    if (!value || !value.ownsBackingStore || !value.image) return;
    try {
      value.image.width = 1;
      value.image.height = 1;
    } catch (error) {}
  }

  function touchMotorwayScenerySurfaceV3312(key) {
    var position = MOTORWAY_SCENERY_SURFACE_ORDER_V339.indexOf(key);
    if (position >= 0) MOTORWAY_SCENERY_SURFACE_ORDER_V339.splice(position, 1);
    MOTORWAY_SCENERY_SURFACE_ORDER_V339.push(key);
  }

  function storeMotorwayScenerySurfaceV339(key, value) {
    if (!MOTORWAY_SCENERY_SURFACE_CACHE_V339) return;
    MOTORWAY_SCENERY_SURFACE_CACHE_V339.set(key, value);
    touchMotorwayScenerySurfaceV3312(key);
    while (MOTORWAY_SCENERY_SURFACE_ORDER_V339.length > MOTORWAY_SCENERY_SURFACE_LIMIT_V339) {
      var oldest = MOTORWAY_SCENERY_SURFACE_ORDER_V339.shift();
      var evicted = MOTORWAY_SCENERY_SURFACE_CACHE_V339.get(oldest);
      MOTORWAY_SCENERY_SURFACE_CACHE_V339.delete(oldest);
      // Releasing the backing store keeps two complete condition sets within
      // a predictable mobile memory budget.
      releaseMotorwaySceneryBackingStoreV3312(evicted);
    }
  }

  function createMotorwaySceneryCanvasCandidateV3312(backend, width, height) {
    var canvas = null;
    try {
      if (backend === 'offscreen' && typeof globalScope.OffscreenCanvas === 'function') {
        canvas = new globalScope.OffscreenCanvas(width, height);
      } else if (backend === 'dom' && globalScope.document &&
          typeof globalScope.document.createElement === 'function') {
        canvas = globalScope.document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
      }
    } catch (error) {
      canvas = null;
    }
    if (!canvas || typeof canvas.getContext !== 'function') return null;
    var context = null;
    try { context = canvas.getContext('2d', { alpha: true }); } catch (error) { context = null; }
    if (!context || typeof context.drawImage !== 'function') {
      releaseMotorwaySceneryBackingStoreV3312({ image: canvas, ownsBackingStore: true });
      return null;
    }
    return {
      image: canvas,
      context: context,
      backend: backend,
      supportsFilter: 'filter' in context
    };
  }

  function createMotorwayScenerySurfaceTargetV3312(width, height) {
    if (MOTORWAY_SCENERY_SURFACE_BACKEND_V3312 === 'none') return null;

    // Reuse the backend decision after the first asset.  In particular, an
    // Android implementation whose OffscreenCanvas lacks `filter` must not be
    // reprobed once per visible strip and frame.
    if (MOTORWAY_SCENERY_SURFACE_BACKEND_V3312) {
      var preferredParts = MOTORWAY_SCENERY_SURFACE_BACKEND_V3312.split('-');
      var preferred = createMotorwaySceneryCanvasCandidateV3312(preferredParts[0], width, height);
      var needsFilter = preferredParts[1] === 'filtered';
      if (preferred && (!needsFilter || preferred.supportsFilter)) return preferred;
      if (preferred) releaseMotorwaySceneryBackingStoreV3312({ image: preferred.image, ownsBackingStore: true });
      MOTORWAY_SCENERY_SURFACE_BACKEND_V3312 = null;
    }

    var basicCandidate = null;
    var backends = ['offscreen', 'dom'];
    for (var index = 0; index < backends.length; index += 1) {
      var candidate = createMotorwaySceneryCanvasCandidateV3312(backends[index], width, height);
      if (!candidate) continue;
      if (candidate.supportsFilter) {
        if (basicCandidate) {
          // Do not retain the abandoned, potentially multi-megabyte probe.
          releaseMotorwaySceneryBackingStoreV3312({ image: basicCandidate.image, ownsBackingStore: true });
        }
        MOTORWAY_SCENERY_SURFACE_BACKEND_V3312 = candidate.backend + '-filtered';
        return candidate;
      }
      if (!basicCandidate) basicCandidate = candidate;
      else releaseMotorwaySceneryBackingStoreV3312({ image: candidate.image, ownsBackingStore: true });
    }

    if (basicCandidate) {
      MOTORWAY_SCENERY_SURFACE_BACKEND_V3312 = basicCandidate.backend + '-basic';
      return basicCandidate;
    }
    MOTORWAY_SCENERY_SURFACE_BACKEND_V3312 = 'none';
    return null;
  }

  function motorwayScenerySourceFallbackV3312(entry, sourceWidth, sourceHeight, reason) {
    MOTORWAY_SCENERY_SURFACE_FALLBACKS_V3312 += 1;
    return freeze({
      image: entry.image,
      scaleX: 1,
      scaleY: 1,
      width: sourceWidth,
      height: sourceHeight,
      ownsBackingStore: false,
      filtered: false,
      fallback: reason || 'source'
    });
  }

  function getMotorwayScenerySurfaceV339(item, entry, profile, viewport) {
    // 3.3.15: Rural verge strips previously fell through to a live per-frame
    // Canvas filter.  They now share the Motorway treated-surface cache.
    if (!item || !entry || !entry.image || !MOTORWAY_SCENERY_SURFACE_CACHE_V339 ||
        !((item.routeId === 'motorway' && item.stream === 'motorway-continuity') ||
          (item.routeId === 'rural' && item.stream === 'rural-continuity'))) return null;
    var sourceWidth = Math.max(1, Math.round(finite(item.asset && item.asset.width, entry.image.naturalWidth)));
    var sourceHeight = Math.max(1, Math.round(finite(item.asset && item.asset.height, entry.image.naturalHeight)));
    if (!(sourceWidth > 0 && sourceHeight > 0)) return null;
    var directSource = getPhoneDirectSourceV3313(item, entry, sourceWidth, sourceHeight, viewport);
    if (directSource) return directSource;
    var width = motorwayScenerySurfaceWidthV339(finite(viewport && viewport.width, 390), sourceWidth);
    var key = motorwayScenerySurfaceKeyV339(item, profile, width);
    var cached = MOTORWAY_SCENERY_SURFACE_CACHE_V339.get(key);
    if (cached) {
      touchMotorwayScenerySurfaceV3312(key);
      return cached;
    }
    var scale = width / sourceWidth;
    var height = Math.max(1, Math.round(sourceHeight * scale));
    var target = createMotorwayScenerySurfaceTargetV3312(width, height);
    if (!target) {
      MOTORWAY_SCENERY_SURFACE_FAILURES_V3312 += 1;
      var sourceFallback = motorwayScenerySourceFallbackV3312(entry, sourceWidth, sourceHeight, 'no-canvas');
      storeMotorwayScenerySurfaceV339(key, sourceFallback);
      return sourceFallback;
    }
    var canvas = target.image;
    var surfaceContext = target.context;
    var filtered = target.supportsFilter;
    try {
      surfaceContext.clearRect(0, 0, width, height);
      if (filtered) surfaceContext.filter = photoFilter(profile, 128);
      surfaceContext.imageSmoothingEnabled = true;
      try { surfaceContext.imageSmoothingQuality = 'medium'; } catch (error) {}
      surfaceContext.drawImage(entry.image, 0, 0, sourceWidth, sourceHeight, 0, 0, width, height);
      if (filtered) surfaceContext.filter = 'none';
    } catch (error) {
      MOTORWAY_SCENERY_SURFACE_FAILURES_V3312 += 1;
      releaseMotorwaySceneryBackingStoreV3312({ image: canvas, ownsBackingStore: true });
      var drawFallback = motorwayScenerySourceFallbackV3312(entry, sourceWidth, sourceHeight, 'draw-failed');
      storeMotorwayScenerySurfaceV339(key, drawFallback);
      return drawFallback;
    }
    var value = freeze({
      image: canvas,
      scaleX: width / sourceWidth,
      scaleY: height / sourceHeight,
      width: width,
      height: height,
      ownsBackingStore: true,
      filtered: filtered,
      fallback: filtered ? null : 'unfiltered-resized',
      backend: target.backend
    });
    if (!filtered) MOTORWAY_SCENERY_SURFACE_FALLBACKS_V3312 += 1;
    storeMotorwayScenerySurfaceV339(key, value);
    return value;
  }

  function getMotorwayScenerySurfaceCacheStatsV3312() {
    var fallbacks = 0;
    if (MOTORWAY_SCENERY_SURFACE_CACHE_V339) {
      MOTORWAY_SCENERY_SURFACE_CACHE_V339.forEach(function countFallback(value) {
        if (value && value.fallback) fallbacks += 1;
      });
    }
    return freeze({
      version: VERSION,
      size: MOTORWAY_SCENERY_SURFACE_CACHE_V339 ? MOTORWAY_SCENERY_SURFACE_CACHE_V339.size : 0,
      limit: MOTORWAY_SCENERY_SURFACE_LIMIT_V339,
      phoneWidth: MOTORWAY_SCENERY_PHONE_SURFACE_WIDTH_V3312,
      backend: MOTORWAY_SCENERY_SURFACE_BACKEND_V3312,
      liveFilters: MOTORWAY_SCENERY_LIVE_FILTERS_V3312,
      cachedFallbacks: fallbacks,
      fallbackCreations: MOTORWAY_SCENERY_SURFACE_FALLBACKS_V3312,
      failures: MOTORWAY_SCENERY_SURFACE_FAILURES_V3312
    });
  }

  // City reuses the same few transparent photographic frontages many times.
  // Treat each source once for the active light/weather condition and share
  // that surface between continuity strips and sparse landmark draws.  The
  // source Image remains the single IMAGE_CACHE entry used by readiness.
  var CITY_SCENERY_SURFACE_CACHE_V3311 = typeof Map === 'function' ? new Map() : null;
  var CITY_SCENERY_SURFACE_ORDER_V3313 = [];
  var CITY_SCENERY_SURFACE_LIMIT_V3311 = 6;
  var CITY_SCENERY_PHONE_SURFACE_WIDTH_V3311 = 768;
  var CITY_SCENERY_LIVE_FILTERS_V3311 = false;
  var CITY_SCENERY_SURFACE_BACKEND_V3313 = null;
  var CITY_SCENERY_SURFACE_FALLBACKS_V3313 = 0;
  var CITY_SCENERY_SURFACE_FAILURES_V3313 = 0;

  function cityScenerySurfaceWidthV3311(viewportWidth, sourceWidth) {
    var maximumWidth = viewportWidth <= 720 ? CITY_SCENERY_PHONE_SURFACE_WIDTH_V3311 :
      (viewportWidth <= 1100 ? 1024 : sourceWidth);
    return Math.max(1, Math.min(sourceWidth, maximumWidth));
  }

  function cityScenerySurfaceKeyV3311(item, profile, width) {
    return [item && item.assetId || '', photoFilter(profile, 128), width].join('|');
  }

  function releaseCitySceneryBackingStoreV3313(value) {
    if (!value || !value.ownsBackingStore || !value.image) return;
    try {
      value.image.width = 1;
      value.image.height = 1;
    } catch (error) {}
  }

  function touchCityScenerySurfaceV3313(key) {
    var position = CITY_SCENERY_SURFACE_ORDER_V3313.indexOf(key);
    if (position >= 0) CITY_SCENERY_SURFACE_ORDER_V3313.splice(position, 1);
    CITY_SCENERY_SURFACE_ORDER_V3313.push(key);
  }

  function storeCityScenerySurfaceV3311(key, value) {
    if (!CITY_SCENERY_SURFACE_CACHE_V3311) return;
    CITY_SCENERY_SURFACE_CACHE_V3311.set(key, value);
    touchCityScenerySurfaceV3313(key);
    while (CITY_SCENERY_SURFACE_ORDER_V3313.length > CITY_SCENERY_SURFACE_LIMIT_V3311) {
      var oldest = CITY_SCENERY_SURFACE_ORDER_V3313.shift();
      var evicted = CITY_SCENERY_SURFACE_CACHE_V3311.get(oldest);
      CITY_SCENERY_SURFACE_CACHE_V3311.delete(oldest);
      releaseCitySceneryBackingStoreV3313(evicted);
    }
  }

  function createCitySceneryCanvasCandidateV3313(backend, width, height) {
    var canvas = null;
    try {
      if (backend === 'offscreen' && typeof globalScope.OffscreenCanvas === 'function') {
        canvas = new globalScope.OffscreenCanvas(width, height);
      } else if (backend === 'dom' && globalScope.document &&
          typeof globalScope.document.createElement === 'function') {
        canvas = globalScope.document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
      }
    } catch (error) {
      canvas = null;
    }
    if (!canvas || typeof canvas.getContext !== 'function') return null;
    var context = null;
    try { context = canvas.getContext('2d', { alpha: true }); } catch (error) { context = null; }
    if (!context || typeof context.drawImage !== 'function') {
      releaseCitySceneryBackingStoreV3313({ image: canvas, ownsBackingStore: true });
      return null;
    }
    return {
      image: canvas,
      context: context,
      backend: backend,
      supportsFilter: 'filter' in context
    };
  }

  function createCityScenerySurfaceTargetV3313(width, height) {
    if (CITY_SCENERY_SURFACE_BACKEND_V3313 === 'none') return null;

    // Android often exposes OffscreenCanvas without Canvas2D filter support.
    // Remember the working capability split after one probe so City strips
    // cannot allocate and abandon a large surface on every frame.
    if (CITY_SCENERY_SURFACE_BACKEND_V3313) {
      var preferredParts = CITY_SCENERY_SURFACE_BACKEND_V3313.split('-');
      var preferred = createCitySceneryCanvasCandidateV3313(preferredParts[0], width, height);
      var needsFilter = preferredParts[1] === 'filtered';
      if (preferred && (!needsFilter || preferred.supportsFilter)) return preferred;
      if (preferred) releaseCitySceneryBackingStoreV3313({ image: preferred.image, ownsBackingStore: true });
      CITY_SCENERY_SURFACE_BACKEND_V3313 = null;
    }

    var basicCandidate = null;
    var backends = ['offscreen', 'dom'];
    for (var index = 0; index < backends.length; index += 1) {
      var candidate = createCitySceneryCanvasCandidateV3313(backends[index], width, height);
      if (!candidate) continue;
      if (candidate.supportsFilter) {
        if (basicCandidate) {
          releaseCitySceneryBackingStoreV3313({ image: basicCandidate.image, ownsBackingStore: true });
        }
        CITY_SCENERY_SURFACE_BACKEND_V3313 = candidate.backend + '-filtered';
        return candidate;
      }
      if (!basicCandidate) basicCandidate = candidate;
      else releaseCitySceneryBackingStoreV3313({ image: candidate.image, ownsBackingStore: true });
    }

    if (basicCandidate) {
      CITY_SCENERY_SURFACE_BACKEND_V3313 = basicCandidate.backend + '-basic';
      return basicCandidate;
    }
    CITY_SCENERY_SURFACE_BACKEND_V3313 = 'none';
    return null;
  }

  function cityScenerySourceFallbackV3313(entry, sourceWidth, sourceHeight, reason) {
    CITY_SCENERY_SURFACE_FALLBACKS_V3313 += 1;
    return freeze({
      image: entry.image,
      scaleX: 1,
      scaleY: 1,
      width: sourceWidth,
      height: sourceHeight,
      ownsBackingStore: false,
      filtered: false,
      fallback: reason || 'source'
    });
  }

  function getCityScenerySurfaceV3311(item, entry, profile, viewport) {
    if (!item || item.routeId !== 'city' ||
        (item.stream !== 'city-continuity' && item.stream !== 'city-landmark') ||
        !entry || !entry.image || !CITY_SCENERY_SURFACE_CACHE_V3311) return null;
    var sourceWidth = Math.max(1, Math.round(finite(item.asset && item.asset.width, entry.image.naturalWidth)));
    var sourceHeight = Math.max(1, Math.round(finite(item.asset && item.asset.height, entry.image.naturalHeight)));
    if (!(sourceWidth > 0 && sourceHeight > 0)) return null;
    var directSource = getPhoneDirectSourceV3313(item, entry, sourceWidth, sourceHeight, viewport);
    if (directSource) return directSource;
    var width = cityScenerySurfaceWidthV3311(finite(viewport && viewport.width, 390), sourceWidth);
    var key = cityScenerySurfaceKeyV3311(item, profile, width);
    var cached = CITY_SCENERY_SURFACE_CACHE_V3311.get(key);
    if (cached) {
      touchCityScenerySurfaceV3313(key);
      return cached;
    }
    var scale = width / sourceWidth;
    var height = Math.max(1, Math.round(sourceHeight * scale));
    var target = createCityScenerySurfaceTargetV3313(width, height);
    if (!target) {
      CITY_SCENERY_SURFACE_FAILURES_V3313 += 1;
      var sourceFallback = cityScenerySourceFallbackV3313(entry, sourceWidth, sourceHeight, 'no-canvas');
      storeCityScenerySurfaceV3311(key, sourceFallback);
      return sourceFallback;
    }
    var canvas = target.image;
    var surfaceContext = target.context;
    var filtered = target.supportsFilter;
    try {
      surfaceContext.clearRect(0, 0, width, height);
      if (filtered) surfaceContext.filter = photoFilter(profile, 128);
      surfaceContext.imageSmoothingEnabled = true;
      try { surfaceContext.imageSmoothingQuality = 'medium'; } catch (error) {}
      surfaceContext.drawImage(entry.image, 0, 0, sourceWidth, sourceHeight, 0, 0, width, height);
      if (filtered) surfaceContext.filter = 'none';
    } catch (error) {
      CITY_SCENERY_SURFACE_FAILURES_V3313 += 1;
      releaseCitySceneryBackingStoreV3313({ image: canvas, ownsBackingStore: true });
      var drawFallback = cityScenerySourceFallbackV3313(entry, sourceWidth, sourceHeight, 'draw-failed');
      storeCityScenerySurfaceV3311(key, drawFallback);
      return drawFallback;
    }
    var value = freeze({
      image: canvas,
      scaleX: width / sourceWidth,
      scaleY: height / sourceHeight,
      width: width,
      height: height,
      ownsBackingStore: true,
      filtered: filtered,
      fallback: filtered ? null : 'unfiltered-resized',
      backend: target.backend
    });
    if (!filtered) CITY_SCENERY_SURFACE_FALLBACKS_V3313 += 1;
    storeCityScenerySurfaceV3311(key, value);
    return value;
  }

  function getCityScenerySurfaceCacheStatsV3311() {
    var fallbacks = 0;
    if (CITY_SCENERY_SURFACE_CACHE_V3311) {
      CITY_SCENERY_SURFACE_CACHE_V3311.forEach(function countFallback(value) {
        if (value && value.fallback) fallbacks += 1;
      });
    }
    return freeze({
      version: VERSION,
      size: CITY_SCENERY_SURFACE_CACHE_V3311 ? CITY_SCENERY_SURFACE_CACHE_V3311.size : 0,
      limit: CITY_SCENERY_SURFACE_LIMIT_V3311,
      phoneWidth: CITY_SCENERY_PHONE_SURFACE_WIDTH_V3311,
      backend: CITY_SCENERY_SURFACE_BACKEND_V3313,
      liveFilters: CITY_SCENERY_LIVE_FILTERS_V3311,
      cachedFallbacks: fallbacks,
      fallbackCreations: CITY_SCENERY_SURFACE_FALLBACKS_V3313,
      failures: CITY_SCENERY_SURFACE_FAILURES_V3313
    });
  }

  function clipImageRectToViewportV339(sourceRect, x, y, width, height, viewport) {
    if (!(width > 0 && height > 0)) return null;
    var left = Math.max(-4, x);
    var top = Math.max(-4, y);
    var right = Math.min(viewport.width + 4, x + width);
    var bottom = Math.min(viewport.height + 4, y + height);
    if (!(right > left && bottom > top)) return null;
    var leftRatio = (left - x) / width;
    var topRatio = (top - y) / height;
    var widthRatio = (right - left) / width;
    var heightRatio = (bottom - top) / height;
    return {
      source: {
        x: sourceRect.x + sourceRect.width * leftRatio,
        y: sourceRect.y + sourceRect.height * topRatio,
        width: sourceRect.width * widthRatio,
        height: sourceRect.height * heightRatio
      },
      destination: { x: left, y: top, width: right - left, height: bottom - top }
    };
  }

  function drawPhotoItem(ctx, projector, item, profile, viewport, options) {
    var entry = imageEntry(item.asset);
    if (!entry || entry.status !== 'ready') return false;
    var base = projectSafe(projector, item.roadX, 0, item.distance);
    var top = projectSafe(projector, item.roadX, item.physicalHeight, item.distance);
    if (!base || !top) return false;
    var destinationHeight = Math.abs(base.y - top.y);
    if (destinationHeight < 1.2) return false;
    var destinationWidth = destinationHeight * (item.asset.width / item.asset.height);
    var x = base.x - destinationWidth * 0.5;
    var y = base.y - destinationHeight;
    if (x > viewport.width + 4 || x + destinationWidth < -4 || y > viewport.height + 4 || y + destinationHeight < -4) return false;
    var alpha = distanceAlpha(item.distance, profile, options) * clamp(finite(item.edgeAlpha, 1), 0, 1);
    if (alpha <= 0.004) return false;
    var photoClipV339 = clipImageRectToViewportV339(
      { x: 0, y: 0, width: item.asset.width, height: item.asset.height },
      x, y, destinationWidth, destinationHeight, viewport
    );
    if (!photoClipV339) return false;
    var directPhotoSourceV3313 = getPhoneDirectSourceV3313(
      item,
      entry,
      Math.max(1, Math.round(finite(item.asset && item.asset.width, entry.image.naturalWidth))),
      Math.max(1, Math.round(finite(item.asset && item.asset.height, entry.image.naturalHeight))),
      viewport
    );
    var citySurfaceV3311 = directPhotoSourceV3313 || getCityScenerySurfaceV3311(item, entry, profile, viewport);
    var drawPhotoSourceV3311 = citySurfaceV3311 ? citySurfaceV3311.image : entry.image;
    var photoSourceScaleXV3311 = citySurfaceV3311 ? citySurfaceV3311.scaleX : 1;
    var photoSourceScaleYV3311 = citySurfaceV3311 ? citySurfaceV3311.scaleY : 1;
    ctx.save();
    ctx.globalAlpha *= alpha;
    if (item.band !== 'far' && typeof ctx.ellipse === 'function') {
      ctx.fillStyle = 'rgba(0,0,0,' + (profile.weather === 'rain' ? 0.14 : 0.20) + ')';
      ctx.beginPath();
      ctx.ellipse(base.x, base.y + destinationHeight * 0.012, destinationWidth * 0.40, Math.max(1, destinationHeight * 0.035), 0, 0, TAU);
      ctx.fill();
    }
    if (!citySurfaceV3311 && (item.routeId !== 'city' || CITY_SCENERY_LIVE_FILTERS_V3311) && 'filter' in ctx) {
      ctx.filter = photoFilter(profile, item.distance);
    }
    ctx.imageSmoothingEnabled = true;
    try { ctx.imageSmoothingQuality = item.routeId === 'city' ? 'medium' : 'high'; } catch (error) {}
    ctx.drawImage(
      drawPhotoSourceV3311,
      photoClipV339.source.x * photoSourceScaleXV3311,
      photoClipV339.source.y * photoSourceScaleYV3311,
      photoClipV339.source.width * photoSourceScaleXV3311,
      photoClipV339.source.height * photoSourceScaleYV3311,
      photoClipV339.destination.x,
      photoClipV339.destination.y,
      photoClipV339.destination.width,
      photoClipV339.destination.height
    );
    ctx.restore();
    return true;
  }

  function drawSceneryStrip(ctx, projector, item, profile, viewport, options) {
    var entry = imageEntry(item.asset);
    if (!entry || entry.status !== 'ready') return false;
    var base = projectSafe(projector, item.roadX, 0, item.distance);
    var top = projectSafe(projector, item.roadX, item.physicalHeight, item.distance);
    var left = projectSafe(projector, item.roadX - item.physicalWidth * 0.5, 0, item.distance);
    var right = projectSafe(projector, item.roadX + item.physicalWidth * 0.5, 0, item.distance);
    if (!base || !top || !left || !right) return false;
    var destinationHeight = Math.abs(base.y - top.y);
    var destinationWidth = Math.abs(right.x - left.x);
    if (destinationHeight < 1 || destinationWidth < 1) return false;

    // A close strip should sweep past the rider, not expand into a single
    // full-screen texture.  The uniform cap preserves its authored aspect.
    var capScale = Math.min(
      1,
      viewport.width * 2.65 / destinationWidth,
      viewport.height * 0.68 / destinationHeight
    );
    destinationWidth *= capScale;
    destinationHeight *= capScale;
    var x = base.x - destinationWidth * 0.5;
    var y = base.y - destinationHeight;
    var alpha = distanceAlpha(item.distance, profile, options) * clamp(finite(item.edgeAlpha, 1), 0, 1);
    if (alpha <= 0.004) return false;

    // Tall M1 strips often extend well beyond a portrait phone. Canvas still
    // rasterised those invisible pixels because the scenery path lacked the
    // viewport clipping already used by photographic landmarks. Intersecting
    // source and destination rectangles is visually identical for these
    // pointwise colour filters while removing the off-screen resampling work.
    var clipLeftV339 = Math.max(-4, x);
    var clipTopV339 = Math.max(-4, y);
    var clipRightV339 = Math.min(viewport.width + 4, x + destinationWidth);
    var clipBottomV339 = Math.min(viewport.height + 4, y + destinationHeight);
    if (!(clipRightV339 > clipLeftV339 && clipBottomV339 > clipTopV339)) return false;
    var sourceLeftRatioV339 = (clipLeftV339 - x) / destinationWidth;
    var sourceTopRatioV339 = (clipTopV339 - y) / destinationHeight;
    var sourceWidthRatioV339 = (clipRightV339 - clipLeftV339) / destinationWidth;
    var sourceHeightRatioV339 = (clipBottomV339 - clipTopV339) / destinationHeight;
    var baseSourceRectV339 = item.sourceRect || {
      x: 0,
      y: 0,
      width: item.asset.width,
      height: item.asset.height
    };
    var clippedSourceRectV339 = {
      x: baseSourceRectV339.x + baseSourceRectV339.width * sourceLeftRatioV339,
      y: baseSourceRectV339.y + baseSourceRectV339.height * sourceTopRatioV339,
      width: baseSourceRectV339.width * sourceWidthRatioV339,
      height: baseSourceRectV339.height * sourceHeightRatioV339
    };
    x = clipLeftV339;
    y = clipTopV339;
    destinationWidth = clipRightV339 - clipLeftV339;
    destinationHeight = clipBottomV339 - clipTopV339;

    var motorwaySurfaceV339 = getMotorwayScenerySurfaceV339(item, entry, profile, viewport);
    var citySurfaceV3311 = getCityScenerySurfaceV3311(item, entry, profile, viewport);
    var treatedSurfaceV339 = motorwaySurfaceV339 || citySurfaceV3311;
    var drawSourceV339 = treatedSurfaceV339 ? treatedSurfaceV339.image : entry.image;
    ctx.save();
    ctx.globalAlpha *= alpha;
    if (!treatedSurfaceV339 && (item.routeId !== 'city' || CITY_SCENERY_LIVE_FILTERS_V3311) && 'filter' in ctx) {
      ctx.filter = photoFilter(profile, Math.min(item.distance, 205));
    }
    ctx.imageSmoothingEnabled = true;
    try { ctx.imageSmoothingQuality = 'medium'; } catch (error) {}
    var sourceScaleXV339 = treatedSurfaceV339 ? treatedSurfaceV339.scaleX : 1;
    var sourceScaleYV339 = treatedSurfaceV339 ? treatedSurfaceV339.scaleY : 1;
    ctx.drawImage(
      drawSourceV339,
      clippedSourceRectV339.x * sourceScaleXV339,
      clippedSourceRectV339.y * sourceScaleYV339,
      clippedSourceRectV339.width * sourceScaleXV339,
      clippedSourceRectV339.height * sourceScaleYV339,
      x, y, destinationWidth, destinationHeight
    );
    ctx.restore();
    return true;
  }

  function furnitureHeight(kind) {
    if (kind === 'lamp') return 7.8;
    if (kind === 'high-mast') return 11.2;
    if (kind === 'utility-pole') return 7.4;
    if (kind === 'railing') return 1.05;
    if (kind === 'stone-marker') return 0.72;
    return 1.0;
  }

  function line(ctx, x1, y1, x2, y2) {
    ctx.beginPath();
    ctx.moveTo(x1, y1);
    ctx.lineTo(x2, y2);
    ctx.stroke();
  }

  var FIXTURE_GLOW_TIERS = freeze({
    smooth: freeze({ alpha: 0.160, radiusFactor: 4.0, radiusMinimum: 4, radiusCap: 12, scatterFactor: 1.70, scatterCap: 20, glints: 3 }),
    enhanced: freeze({ alpha: 0.210, radiusFactor: 4.8, radiusMinimum: 5, radiusCap: 18, scatterFactor: 2.00, scatterCap: 30, glints: 3 }),
    ultra: freeze({ alpha: 0.270, radiusFactor: 5.7, radiusMinimum: 6, radiusCap: 25, scatterFactor: 2.15, scatterCap: 44, glints: 4 }),
    cinematic: freeze({ alpha: 0.320, radiusFactor: 6.4, radiusMinimum: 7, radiusCap: 32, scatterFactor: 2.30, scatterCap: 58, glints: 5 })
  });

  var FIXTURE_GLOW_WEATHER = freeze({
    clear: freeze({ radius: 1, alpha: 1, scatter: 0, scatterAspect: 0.58 }),
    'post-rain': freeze({ radius: 1.08, alpha: 0.98, scatter: 0.50, scatterAspect: 0.58 }),
    rain: freeze({ radius: 1.16, alpha: 1.02, scatter: 0.75, scatterAspect: 0.64 }),
    storm: freeze({ radius: 1.22, alpha: 1.00, scatter: 0.86, scatterAspect: 0.72 }),
    fog: freeze({ radius: 1.32, alpha: 0.94, scatter: 1.00, scatterAspect: 0.82 })
  });

  var MOTORWAY_FIXTURE_STRENGTH = freeze({
    'services-run': 1,
    'luton-approach': 0.86,
    'logistics-belt': 0.80,
    'works-sector': 0.80,
    'm1-overbridge': 0.72,
    'smart-motorway': 0.65,
    'northbound-open': 0.50
  });

  function normaliseFixtureGlowTier(state, options) {
    if (options && options.cinematic === true) return 'cinematic';
    var tier = qualityOf(state, options);
    if (tier === 'smooth' || tier === 'low' || tier === 'performance') return 'smooth';
    if (tier === 'ultra' || tier === 'high') return 'ultra';
    if (tier === 'cinematic') return 'cinematic';
    return 'enhanced';
  }

  function fixtureChapterStrength(item) {
    if (!item) return 0;
    if (item.routeId === 'city') return item.furniture === 'high-mast' ? 0.82 : 1;
    if (item.routeId === 'rural') return item.chapterId === 'dual-carriageway' && item.furniture === 'high-mast' ? 0.65 : 0;
    if (item.routeId === 'motorway') {
      return Object.prototype.hasOwnProperty.call(MOTORWAY_FIXTURE_STRENGTH, item.chapterId) ?
        MOTORWAY_FIXTURE_STRENGTH[item.chapterId] : 0.5;
    }
    return 0;
  }

  function getFixtureLampGlowStyle(item, profile, options, lampHalfWidth) {
    var time = normaliseTime(profile && profile.timeOfDay);
    var weather = normaliseWeather(profile && profile.weather);
    var poorVisibility = weather === 'rain' || weather === 'storm' || weather === 'fog';
    var timeFactor = time === 'night' ? 1 : (time === 'dusk' ? 0.72 : (poorVisibility ? 0.32 : 0));
    var chapterStrength = fixtureChapterStrength(item);
    // A route-authored fixture is a physical source. Chapter strength may vary
    // its energy, but must not silently turn an instantiated Motorway/City
    // lamp off at night. A zero still excludes non-lighting Rural sections.
    if (chapterStrength <= 0) return null;
    var effectiveStrength = Math.max(chapterStrength, time === 'night' ? 0.52 : 0);
    if (timeFactor <= 0 || effectiveStrength <= 0) return null;

    var tierName = normaliseFixtureGlowTier(profile, options);
    var tier = FIXTURE_GLOW_TIERS[tierName];
    var weatherProfile = FIXTURE_GLOW_WEATHER[weather] || FIXTURE_GLOW_WEATHER.clear;
    var radius = clamp(
      lampHalfWidth * tier.radiusFactor * weatherProfile.radius * (0.86 + effectiveStrength * 0.14),
      tier.radiusMinimum,
      tier.radiusCap
    );
    var alpha = clamp(tier.alpha * weatherProfile.alpha * timeFactor * (0.68 + effectiveStrength * 0.32), 0, 0.36);
    if (alpha <= 0.003) return null;
    var scatterRadiusX = clamp(radius * tier.scatterFactor, radius, tier.scatterCap);
    return {
      effect: 'anchored-fixture-lamp-halo',
      owner: 'photographic-world',
      tier: tierName,
      radius: radius,
      alpha: alpha,
      coreAlpha: clamp(0.68 + timeFactor * 0.26, 0, 0.96),
      hotAlpha: clamp(0.74 + timeFactor * 0.24, 0, 0.98),
      scatterRadiusX: scatterRadiusX,
      scatterRadiusY: scatterRadiusX * weatherProfile.scatterAspect,
      scatterAlpha: clamp(alpha * weatherProfile.scatter * 0.40, 0, 0.14),
      glints: weatherProfile.scatter > 0 ? tier.glints : 0
    };
  }

  function drawFixtureLampHalo(ctx, x, y, item, profile, options, lampHalfWidth, resolvedStyle) {
    if (!ctx || typeof ctx.createRadialGradient !== 'function' || typeof ctx.arc !== 'function') return false;
    var style = resolvedStyle || getFixtureLampGlowStyle(item, profile, options, lampHalfWidth);
    if (!style) return false;
    var colour = profile.lampColour || [232, 244, 247];
    ctx.save();
    try {
      ctx.globalCompositeOperation = 'screen';
      var glow = ctx.createRadialGradient(x, y, 0, x, y, style.radius);
      glow.addColorStop(0, rgba(colour, style.alpha));
      glow.addColorStop(0.14, rgba(colour, style.alpha * 0.88));
      glow.addColorStop(0.38, rgba(colour, style.alpha * 0.38));
      glow.addColorStop(1, rgba(colour, 0));
      ctx.fillStyle = glow;
      ctx.beginPath();
      ctx.arc(x, y, style.radius, 0, TAU);
      ctx.fill();
    } finally {
      ctx.restore();
    }
    return true;
  }

  function drawFixtureWetAir(ctx, x, y, item, profile, style) {
    if (!ctx || !style || style.scatterAlpha <= 0.002 || style.glints < 1 ||
        typeof ctx.createRadialGradient !== 'function' || typeof ctx.arc !== 'function') return false;
    var colour = profile.lampColour || [232, 244, 247];
    var radiusX = style.scatterRadiusX;
    var radiusY = style.scatterRadiusY;
    var lobeY = y + radiusY * 0.10;
    var canStretch = typeof ctx.translate === 'function' && typeof ctx.scale === 'function';
    ctx.save();
    try {
      ctx.globalCompositeOperation = 'screen';
      if (canStretch) {
        ctx.translate(x, lobeY);
        ctx.scale(1, radiusY / radiusX);
      }
      var gradientX = canStretch ? 0 : x;
      var gradientY = canStretch ? 0 : lobeY;
      var wetAir = ctx.createRadialGradient(gradientX, gradientY, 0, gradientX, gradientY, radiusX);
      wetAir.addColorStop(0, rgba(colour, style.scatterAlpha));
      wetAir.addColorStop(0.34, rgba(colour, style.scatterAlpha * 0.54));
      wetAir.addColorStop(0.72, rgba(colour, style.scatterAlpha * 0.15));
      wetAir.addColorStop(1, rgba(colour, 0));
      ctx.fillStyle = wetAir;
      ctx.beginPath();
      ctx.arc(gradientX, gradientY, radiusX, 0, TAU);
      ctx.fill();
    } finally {
      ctx.restore();
    }

    // Glints share the same deterministic furniture seed, so the illuminated
    // droplets remain attached to this lamp rather than shimmering randomly.
    var seed = (hashString(item && item.id || 'avenra-fixture') ^ Math.floor(finite(item && item.seed, 0.5) * 4294967295)) >>> 0;
    ctx.save();
    try {
      ctx.globalCompositeOperation = 'screen';
      for (var index = 0; index < style.glints; index += 1) {
        var horizontal = hashUnit(seed + index * 92821 + 17);
        var vertical = hashUnit(seed + index * 68917 + 53);
        var energy = hashUnit(seed + index * 31337 + 89);
        var glintX = x + (horizontal * 2 - 1) * radiusX * 0.76;
        var glintY = lobeY + (vertical * 1.55 - 0.48) * radiusY;
        var glintRadius = clamp(0.34 + energy * 0.36 + style.radius * 0.012, 0.34, 1.15);
        ctx.fillStyle = rgba(colour, style.scatterAlpha * (0.72 + energy * 0.74));
        ctx.beginPath();
        ctx.arc(glintX, glintY, glintRadius, 0, TAU);
        ctx.fill();
      }
    } finally {
      ctx.restore();
    }
    return true;
  }

  function drawFixtureLampCore(ctx, x, y, width, height, colour, style) {
    if (!ctx || !style || typeof ctx.fillRect !== 'function') return false;
    ctx.save();
    try {
      ctx.globalCompositeOperation = 'screen';
      ctx.fillStyle = rgba(colour, style.coreAlpha);
      ctx.fillRect(x - width * 0.5, y - height * 0.5, width, height);
      ctx.globalCompositeOperation = 'lighter';
      var hotWidth = Math.max(0.72, width * 0.46);
      var hotHeight = Math.max(0.44, height * 0.58);
      ctx.fillStyle = rgba([249, 253, 255], style.hotAlpha);
      ctx.fillRect(x - hotWidth * 0.5, y - hotHeight * 0.5, hotWidth, hotHeight);
    } finally {
      ctx.restore();
    }
    return true;
  }

  function drawFurnitureItem(ctx, projector, item, profile, options) {
    var kind = item.furniture;
    var height = furnitureHeight(kind);
    var base = projectSafe(projector, item.roadX, 0, item.distance);
    var top = projectSafe(projector, item.roadX, height, item.distance);
    if (!base || !top) return false;
    var pixelHeight = Math.abs(base.y - top.y);
    if (pixelHeight < 0.7) return false;
    var alpha = distanceAlpha(item.distance, profile, options) * clamp(finite(item.edgeAlpha, 1), 0, 1);
    if (alpha <= 0.004) return false;
    ctx.save();
    ctx.globalAlpha *= alpha;
    ctx.lineCap = 'round';
    if (kind === 'lamp' || kind === 'high-mast') {
      ctx.strokeStyle = profile.timeOfDay === 'night' ? 'rgba(55,64,69,0.96)' : 'rgba(61,69,72,0.90)';
      ctx.lineWidth = clamp(pixelHeight * 0.022, 0.65, 4.2);
      line(ctx, base.x, base.y, top.x, top.y);
      var arm = Math.max(2, pixelHeight * (kind === 'high-mast' ? 0.08 : 0.13)) * -item.side;
      line(ctx, top.x, top.y, top.x + arm, top.y + pixelHeight * 0.018);
      var lampWidth = Math.max(1.2, pixelHeight * 0.06);
      var lampHeight = Math.max(0.7, pixelHeight * 0.015);
      var lampX = top.x + arm;
      var lampY = top.y + lampHeight * 0.5;
      var lampColour = profile.lampColour || [232, 244, 247];
      var lampStyle = getFixtureLampGlowStyle(item, profile, options, lampWidth * 0.5);
      drawFixtureWetAir(ctx, lampX, lampY, item, profile, lampStyle);
      drawFixtureLampHalo(ctx, lampX, lampY, item, profile, options, lampWidth * 0.5, lampStyle);
      ctx.fillStyle = rgba(lampColour, profile.timeOfDay === 'day' ? 0.20 : 0.62);
      ctx.fillRect(top.x + arm - lampWidth * 0.5, top.y, lampWidth, lampHeight);
      drawFixtureLampCore(ctx, lampX, lampY, lampWidth, lampHeight, lampColour, lampStyle);
    } else if (kind === 'utility-pole') {
      ctx.strokeStyle = 'rgba(65,56,45,0.94)';
      ctx.lineWidth = clamp(pixelHeight * 0.038, 0.7, 4.5);
      line(ctx, base.x, base.y, top.x, top.y);
      ctx.lineWidth = clamp(pixelHeight * 0.014, 0.45, 2);
      var cross = pixelHeight * 0.13;
      line(ctx, top.x - cross, top.y + pixelHeight * 0.035, top.x + cross, top.y + pixelHeight * 0.035);
    } else if (kind === 'railing') {
      var otherBase = projectSafe(projector, item.roadX, 0, item.distance + 11);
      var otherTop = projectSafe(projector, item.roadX, height, item.distance + 11);
      if (!otherBase || !otherTop) { ctx.restore(); return false; }
      ctx.strokeStyle = 'rgba(45,53,57,0.82)';
      ctx.lineWidth = clamp(pixelHeight * 0.055, 0.55, 3);
      line(ctx, top.x, top.y, otherTop.x, otherTop.y);
      line(ctx, mix(top.x, base.x, 0.45), mix(top.y, base.y, 0.45), mix(otherTop.x, otherBase.x, 0.45), mix(otherTop.y, otherBase.y, 0.45));
      line(ctx, base.x, base.y, top.x, top.y);
      line(ctx, otherBase.x, otherBase.y, otherTop.x, otherTop.y);
    } else {
      var isMotorway = kind === 'motorway-post';
      var isStone = kind === 'stone-marker';
      ctx.strokeStyle = isStone ? 'rgba(125,123,103,0.94)' : 'rgba(224,224,211,0.96)';
      ctx.lineWidth = clamp(pixelHeight * (isStone ? 0.24 : 0.14), 0.8, 5);
      line(ctx, base.x, base.y, top.x, top.y);
      if (!isStone) {
        ctx.fillStyle = isMotorway ? 'rgba(88,190,229,0.92)' : 'rgba(214,55,46,0.92)';
        ctx.fillRect(top.x - Math.max(0.7, pixelHeight * 0.07), top.y + pixelHeight * 0.14, Math.max(1.3, pixelHeight * 0.14), Math.max(0.8, pixelHeight * 0.075));
      }
    }
    ctx.restore();
    return true;
  }

  function drawTransitionConcealment(ctx, state, options) {
    state = state && typeof state === 'object' ? state : {};
    options = options && typeof options === 'object' ? options : {};
    if (!ctx || typeof ctx.createLinearGradient !== 'function' || isActualTunnel(state, options)) return false;
    var buffer = options.chapterBuffer && options.chapterBuffer.kind === 'avenra-visual-chapter-buffer-v320' ?
      options.chapterBuffer : getVisualChapterBuffer(state, options);
    var current = buffer.current;
    var exitAmount = current.transition;
    var previous = buffer.previous || null;
    for (var index = 0; index < buffer.chapters.length; index += 1) {
      if (buffer.chapters[index].relativeIndex === -1) previous = buffer.chapters[index];
    }
    var entryBlend = previous ? previous.blendMetres : current.blendMetres;
    var entryAmount = previous && current.localMetres < entryBlend ? 1 - smoothstep(0, entryBlend, current.localMetres) : 0;
    var amount = Math.max(exitAmount, entryAmount);
    if (amount <= 0.002) return false;
    var mask = exitAmount >= entryAmount ? current.transitionMask : previous.transitionMask;
    if (!mask) return false;
    var width = Math.max(2, finite(options.width, ctx.canvas && ctx.canvas.width));
    var height = Math.max(2, finite(options.height, ctx.canvas && ctx.canvas.height));
    var horizonY = clamp(finite(options.horizon, getCameraProfile(width, height, state, options).horizonY), 0, height);
    var profile = resolveProfile(state, options);
    var strength = clamp(finite(mask.strength, 0.45) * amount, 0, 0.82);
    var side = finite(mask.side, 0);
    ctx.save();
    if (mask.type === 'bend') {
      var edgeX = side < 0 ? 0 : width;
      var innerX = side < 0 ? width * 0.64 : width * 0.36;
      var bendGradient = ctx.createLinearGradient(edgeX, 0, innerX, 0);
      bendGradient.addColorStop(0, rgba(profile.foregroundColour, strength * 0.82));
      bendGradient.addColorStop(0.58, rgba(profile.foregroundColour, strength * 0.28));
      bendGradient.addColorStop(1, rgba(profile.horizonColour, 0));
      ctx.fillStyle = bendGradient;
      ctx.fillRect(0, horizonY * 0.72, width, height - horizonY * 0.72);
    } else if (mask.type === 'bridge' || mask.type === 'overbridge' || mask.type === 'underpass') {
      var bridgeTop = Math.max(0, horizonY - height * (mask.type === 'underpass' ? 0.105 : 0.055));
      var bridgeBottom = Math.min(height, horizonY + height * (mask.type === 'underpass' ? 0.115 : 0.045));
      var bridgeGradient = ctx.createLinearGradient(0, bridgeTop, 0, bridgeBottom);
      bridgeGradient.addColorStop(0, 'rgba(13,19,22,0)');
      bridgeGradient.addColorStop(0.42, 'rgba(18,24,26,' + (strength * 0.66).toFixed(3) + ')');
      bridgeGradient.addColorStop(0.64, 'rgba(24,30,31,' + (strength * 0.48).toFixed(3) + ')');
      bridgeGradient.addColorStop(1, rgba(profile.foregroundColour, 0));
      ctx.fillStyle = bridgeGradient;
      ctx.fillRect(0, bridgeTop, width, Math.max(1, bridgeBottom - bridgeTop));
      if (mask.type === 'underpass') {
        ctx.fillStyle = 'rgba(5,9,12,' + (strength * 0.22).toFixed(3) + ')';
        ctx.fillRect(0, 0, width, bridgeTop + 1);
      }
    } else {
      var fogGradient = ctx.createRadialGradient(width * (0.5 + side * 0.12), horizonY, 0, width * 0.5, horizonY, Math.max(width, height) * 0.62);
      fogGradient.addColorStop(0, rgba(profile.horizonColour, strength * 0.86));
      fogGradient.addColorStop(0.46, rgba(profile.horizonColour, strength * 0.40));
      fogGradient.addColorStop(1, rgba(profile.horizonColour, 0));
      ctx.fillStyle = fogGradient;
      ctx.fillRect(0, Math.max(0, horizonY - height * 0.22), width, height * 0.62);
    }
    ctx.restore();
    return true;
  }

  function drawWorldLayer(ctx, projector, state, options) {
    if (projector && typeof projector === 'object') {
      var config = projector;
      projector = config.project || config.projector;
      state = config.state || config;
      options = Object.assign({}, config.state || {}, config, config.options || {});
    }
    state = state && typeof state === 'object' ? state : {};
    options = options && typeof options === 'object' ? options : {};
    var photoReady = resolvePhotoWorldReady(state, options);
    if (!ctx || typeof ctx.drawImage !== 'function' || typeof projector !== 'function' || !isCinematic(state, options) || isActualTunnel(state, options) || !photoReady) return 0;
    var plan = options.plan && (options.plan.kind === 'avenra-layer-plan-v320' || options.plan.kind === 'avenra-layer-plan-v310') ? options.plan : getVisualLayerPlan(state, options);
    var profile = resolveProfile(state, options);
    var viewport = {
      width: Math.max(2, finite(options.width, ctx.canvas && ctx.canvas.width)),
      height: Math.max(2, finite(options.height, ctx.canvas && ctx.canvas.height))
    };
    var drawn = 0;
    for (var index = 0; index < plan.items.length; index += 1) {
      var item = plan.items[index];
      var didDraw = item.kind === 'photo' ?
        drawPhotoItem(ctx, projector, item, profile, viewport, options) :
        (item.kind === 'scenery-strip' ?
          drawSceneryStrip(ctx, projector, item, profile, viewport, options) :
          drawFurnitureItem(ctx, projector, item, profile, options));
      if (didDraw) drawn += 1;
    }
    if (plan.band === 'far' && drawTransitionConcealment(ctx, state, Object.assign({}, options, { chapterBuffer: plan.chapterBuffer }))) drawn += 1;
    return drawn;
  }

  function drawFarField(ctx, projector, state, options) {
    if (projector && typeof projector === 'object') {
      return drawWorldLayer(ctx, Object.assign({}, projector, { band: 'far' }));
    }
    return drawWorldLayer(ctx, projector, state, Object.assign({}, options || {}, { band: 'far' }));
  }

  function drawMidField(ctx, projector, state, options) {
    if (projector && typeof projector === 'object') {
      return drawWorldLayer(ctx, Object.assign({}, projector, { band: 'mid' }));
    }
    return drawWorldLayer(ctx, projector, state, Object.assign({}, options || {}, { band: 'mid' }));
  }

  function drawNearLayer(ctx, projector, state, options) {
    if (projector && typeof projector === 'object') {
      return drawWorldLayer(ctx, Object.assign({}, projector, { band: 'near' }));
    }
    return drawWorldLayer(ctx, projector, state, Object.assign({}, options || {}, { band: 'near' }));
  }

  function wrappedDrawNearField(ctx, projector, state, options) {
    var extractedState = state;
    var extractedOptions = options;
    if (projector && typeof projector === 'object') {
      extractedState = projector.state || projector;
      extractedOptions = Object.assign({}, projector.state || {}, projector, projector.options || {});
    }
    var photoReady = resolvePhotoWorldReady(extractedState || {}, extractedOptions || {});
    if (!isCinematic(extractedState || {}, extractedOptions || {}) || !photoReady) {
      return legacyDrawNearField ? legacyDrawNearField.apply(null, arguments) : 0;
    }
    // The compiled hook calls drawNearLayer separately after traffic.
    // Returning zero here suppresses v300's rectangle/blob scenery without
    // painting the photographic layer twice.
    return 0;
  }

  var SIGN_FACE_CACHE = typeof Map === 'function' ? new Map() : null;
  var SIGN_FACE_FALLBACK = Object.create(null);
  var SIGN_FACE_ORDER = [];
  var SIGN_FACE_LIMIT = 48;

  function stableSign(sign) {
    sign = sign && typeof sign === 'object' ? sign : { title: sign };
    var routePatch = sign.routePatch || sign.route || sign.routeNumber || '';
    if (!routePatch && Array.isArray(sign.routePatches)) routePatch = sign.routePatches.join(' · ');
    return {
      style: String(sign.style || sign.kind || 'motorway').toLowerCase(),
      title: String(sign.title || sign.destination || sign.heading || ''),
      subtitle: String(sign.subtitle || sign.secondary || sign.subheading || ''),
      routePatch: String(routePatch),
      distance: String(sign.distanceLabel || sign.distanceText || ''),
      arrow: String(sign.arrow || 'ahead').toLowerCase(),
      lanes: clamp(Math.round(finite(sign.lanes, 0)), 0, 6)
    };
  }

  function makeSignFaceKey(sign, options) {
    var value = stableSign(sign);
    var width = clamp(Math.round(finite(options && options.width, 1024)), 256, 2048);
    var height = clamp(Math.round(finite(options && options.height, 256)), 96, 768);
    return [value.style, value.title, value.subtitle, value.routePatch, value.distance, value.arrow, value.lanes, width, height].join('|');
  }

  function cachedSign(key) {
    return SIGN_FACE_CACHE ? SIGN_FACE_CACHE.get(key) : SIGN_FACE_FALLBACK[key];
  }

  function storeSign(key, value) {
    if (SIGN_FACE_CACHE) SIGN_FACE_CACHE.set(key, value);
    else SIGN_FACE_FALLBACK[key] = value;
    SIGN_FACE_ORDER.push(key);
    while (SIGN_FACE_ORDER.length > SIGN_FACE_LIMIT) {
      var oldest = SIGN_FACE_ORDER.shift();
      if (SIGN_FACE_CACHE) SIGN_FACE_CACHE.delete(oldest);
      else delete SIGN_FACE_FALLBACK[oldest];
    }
  }

  function createCanvas(width, height) {
    try {
      if (typeof globalScope.OffscreenCanvas === 'function') return new globalScope.OffscreenCanvas(width, height);
      if (globalScope.document && typeof globalScope.document.createElement === 'function') {
        var canvas = globalScope.document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        return canvas;
      }
    } catch (error) {}
    return null;
  }

  function signColours(style) {
    if (style === 'primary') return { board: '#08743f', border: '#ffffff', text: '#ffffff' };
    if (style === 'local') return { board: '#f4f1e6', border: '#111820', text: '#111820' };
    if (style === 'warning') return { board: '#f5c400', border: '#161a1d', text: '#161a1d' };
    return { board: '#086999', border: '#ffffff', text: '#ffffff' };
  }

  function fitText(ctx, text, maximumWidth, startSize, minimumSize) {
    var size = startSize;
    do {
      ctx.font = '700 ' + Math.round(size) + 'px Arial, sans-serif';
      if (!ctx.measureText || ctx.measureText(text).width <= maximumWidth) return size;
      size -= 2;
    } while (size >= minimumSize);
    return minimumSize;
  }

  function paintArrow(ctx, centreX, top, size, direction, colour) {
    ctx.save();
    ctx.translate(centreX, top + size * 0.5);
    if (direction === 'left') ctx.rotate(-Math.PI / 2);
    else if (direction === 'right') ctx.rotate(Math.PI / 2);
    ctx.strokeStyle = colour;
    ctx.fillStyle = colour;
    ctx.lineWidth = Math.max(4, size * 0.10);
    ctx.beginPath();
    ctx.moveTo(0, size * 0.36);
    ctx.lineTo(0, -size * 0.30);
    ctx.stroke();
    ctx.beginPath();
    ctx.moveTo(-size * 0.22, -size * 0.08);
    ctx.lineTo(0, -size * 0.38);
    ctx.lineTo(size * 0.22, -size * 0.08);
    ctx.closePath();
    ctx.fill();
    ctx.restore();
  }

  function getCachedSignFace(sign, options) {
    if (sign && typeof sign === 'object' && sign.sign) {
      options = Object.assign({}, sign.options || {}, sign, options || {});
      sign = sign.sign;
    }
    options = options && typeof options === 'object' ? options : {};
    var key = makeSignFaceKey(sign, options);
    var found = cachedSign(key);
    if (found) return found;
    var width = clamp(Math.round(finite(options.width, 1024)), 256, 2048);
    var height = clamp(Math.round(finite(options.height, 256)), 96, 768);
    var canvas = createCanvas(width, height);
    if (!canvas || typeof canvas.getContext !== 'function') return null;
    var ctx = canvas.getContext('2d');
    if (!ctx) return null;
    var value = stableSign(sign);
    var colours = signColours(value.style);
    var border = Math.max(5, Math.round(height * 0.035));
    ctx.fillStyle = colours.board;
    ctx.fillRect(0, 0, width, height);
    ctx.strokeStyle = colours.border;
    ctx.lineWidth = border;
    ctx.strokeRect(border * 0.7, border * 0.7, width - border * 1.4, height - border * 1.4);
    var patchWidth = value.routePatch ? Math.min(width * 0.22, Math.max(height * 0.66, value.routePatch.length * height * 0.24)) : 0;
    var left = border * 2 + patchWidth;
    if (value.routePatch) {
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(border * 2, height * 0.18, patchWidth - border, height * 0.64);
      ctx.fillStyle = '#101820';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      fitText(ctx, value.routePatch, patchWidth - border * 2, height * 0.30, 20);
      ctx.fillText(value.routePatch, border * 2 + (patchWidth - border) * 0.5, height * 0.50);
    }
    ctx.fillStyle = colours.text;
    ctx.textAlign = 'left';
    ctx.textBaseline = 'alphabetic';
    var textWidth = width - left - height * 0.75;
    var titleSize = fitText(ctx, value.title || 'The North', textWidth, height * 0.31, 24);
    ctx.font = '700 ' + Math.round(titleSize) + 'px Arial, sans-serif';
    ctx.fillText(value.title || 'The North', left, height * 0.48);
    if (value.subtitle || value.distance) {
      var secondary = [value.subtitle, value.distance].filter(Boolean).join('  ·  ');
      var subtitleSize = fitText(ctx, secondary, textWidth, height * 0.19, 18);
      ctx.font = '500 ' + Math.round(subtitleSize) + 'px Arial, sans-serif';
      ctx.fillText(secondary, left, height * 0.74);
    }
    paintArrow(ctx, width - height * 0.37, height * 0.17, height * 0.62, value.arrow, colours.text);
    storeSign(key, canvas);
    return canvas;
  }

  function normaliseRect(rect) {
    if (!rect || typeof rect !== 'object') return null;
    var width = finite(rect.width != null ? rect.width : rect.w, NaN);
    var height = finite(rect.height != null ? rect.height : rect.h, NaN);
    var x = finite(rect.x != null ? rect.x : rect.left, NaN);
    var y = finite(rect.y != null ? rect.y : rect.top, NaN);
    if (![x, y, width, height].every(Number.isFinite) || width <= 0 || height <= 0) return null;
    return { x: x, y: y, width: width, height: height };
  }

  function drawCachedSignFace(ctx, sign, rect, options) {
    if (sign && typeof sign === 'object' && sign.rect && (!rect || !Number.isFinite(finite(rect.width, NaN)))) {
      var config = sign;
      rect = config.rect;
      sign = config.sign || config;
      options = Object.assign({}, config.options || {}, config, options || {});
    }
    var destination = normaliseRect(rect);
    if (!ctx || typeof ctx.drawImage !== 'function' || !destination) return false;
    var face = getCachedSignFace(sign, options);
    if (!face) return false;
    ctx.save();
    ctx.imageSmoothingEnabled = true;
    try { ctx.imageSmoothingQuality = 'high'; } catch (error) {}
    ctx.drawImage(face, destination.x, destination.y, destination.width, destination.height);
    ctx.restore();
    return true;
  }

  function clearSignFaceCache() {
    if (SIGN_FACE_CACHE) SIGN_FACE_CACHE.clear();
    Object.keys(SIGN_FACE_FALLBACK).forEach(function removeKey(key) { delete SIGN_FACE_FALLBACK[key]; });
    SIGN_FACE_ORDER.length = 0;
    return true;
  }

  function getSignFaceCacheStats() {
    return freeze({ size: SIGN_FACE_CACHE ? SIGN_FACE_CACHE.size : Object.keys(SIGN_FACE_FALLBACK).length, limit: SIGN_FACE_LIMIT });
  }

  function getPortalCarryGeometry(config) {
    config = config && typeof config === 'object' ? config : {};
    var width = Math.max(2, finite(config.width, 390));
    var height = Math.max(2, finite(config.height, 844));
    var distance = finite(config.relativeDistance != null ? config.relativeDistance : config.distance, NaN);
    var nearPlane = finite(config.nearPlane, 1.15);
    var behind = Math.max(4, finite(config.carryBehind, 14));
    if (!Number.isFinite(distance) || distance > nearPlane || distance < -behind) return null;
    var progress = clamp((nearPlane - distance) / (nearPlane + behind), 0, 1);
    var inset = mix(width * 0.075, -width * 0.16, progress);
    var crossbarY = mix(height * 0.025, -height * 0.42, progress);
    var alpha = 1 - smoothstep(0.74, 1, progress);
    var signWidth = mix(width * 0.52, width * 0.78, progress);
    var signHeight = signWidth * 0.25;
    return freeze({
      progress: progress, alpha: alpha,
      leftX: inset, rightX: width - inset,
      crossbarY: crossbarY, legBottomY: height * 1.08,
      signRect: freeze({ x: width * 0.5 - signWidth * 0.5, y: crossbarY + height * 0.012, width: signWidth, height: signHeight })
    });
  }

  function drawPortalCarry(ctx, portalOrConfig, state, options) {
    var config = portalOrConfig && typeof portalOrConfig === 'object' ? portalOrConfig : { relativeDistance: portalOrConfig };
    options = options && typeof options === 'object' ? options : {};
    var width = finite(config.width, ctx && ctx.canvas && ctx.canvas.width);
    var height = finite(config.height, ctx && ctx.canvas && ctx.canvas.height);
    var geometry = getPortalCarryGeometry(Object.assign({}, config, { width: width, height: height }));
    if (!geometry || !ctx || typeof ctx.beginPath !== 'function') return false;
    ctx.save();
    ctx.globalAlpha *= geometry.alpha;
    ctx.strokeStyle = config.frameColour || 'rgba(105,116,121,0.96)';
    ctx.lineCap = 'square';
    ctx.lineWidth = clamp(width * 0.012, 4, 16);
    line(ctx, geometry.leftX, geometry.legBottomY, geometry.leftX, geometry.crossbarY);
    line(ctx, geometry.rightX, geometry.legBottomY, geometry.rightX, geometry.crossbarY);
    ctx.lineWidth = clamp(width * 0.016, 5, 20);
    line(ctx, geometry.leftX, geometry.crossbarY, geometry.rightX, geometry.crossbarY);
    if (config.sign && geometry.signRect.y + geometry.signRect.height > 0 && geometry.signRect.y < height) {
      drawCachedSignFace(ctx, config.sign, geometry.signRect, config.signOptions || options);
    }
    ctx.restore();
    return true;
  }

  Object.assign(namespace, {
    worldVersion: VERSION,
    worldRendererMode: 'photographic-2.5d',
    visualChapters: VISUAL_CHAPTERS,
    visualChapterLengths: CHAPTER_LENGTHS,
    visualChapterLengthRanges: CHAPTER_LENGTH_RANGES,
    visualChapterAuthoring: CHAPTER_AUTHORING,
    visualChapterAcousticZones: CHAPTER_ACOUSTIC_ZONES,
    worldAssetManifest: ASSETS,
    cityContinuityVersion: VERSION,
    cityContinuityAssetIds: CITY_CONTINUITY_ASSET_IDS_V3311,
    cityContinuityMaxItems: CITY_CONTINUITY_MAX_ITEMS_V3311,
    cityScenerySurfaceLimit: CITY_SCENERY_SURFACE_LIMIT_V3311,
    citySceneryPhoneSurfaceWidth: CITY_SCENERY_PHONE_SURFACE_WIDTH_V3311,
    citySceneryLiveFilters: CITY_SCENERY_LIVE_FILTERS_V3311,
    cityBackdropMaskPassthrough: CITY_BACKDROP_MASK_PASSTHROUGH_V3311,
    getCityScenerySurfaceCacheStats: getCityScenerySurfaceCacheStatsV3311,
    phoneDirectSource: PHONE_DIRECT_SOURCE_V3313,
    phoneDirectSourceMaxWidth: PHONE_DIRECT_SOURCE_MAX_WIDTH_V3313,
    getPhoneDirectSourceStats: getPhoneDirectSourceStatsV3313,
    motorwayScenerySurfaceLimit: MOTORWAY_SCENERY_SURFACE_LIMIT_V339,
    motorwaySceneryPhoneSurfaceWidth: MOTORWAY_SCENERY_PHONE_SURFACE_WIDTH_V3312,
    motorwaySceneryLiveFilters: MOTORWAY_SCENERY_LIVE_FILTERS_V3312,
    getMotorwayScenerySurfaceCacheStats: getMotorwayScenerySurfaceCacheStatsV3312,
    backdropProfiles: BACKDROP_BASE,
    viewportCalibrations: VIEWPORT_CALIBRATION,
    normaliseWeather: normaliseWeather,
    normalizeWeather: normaliseWeather,
    isActualTunnel: isActualTunnel,
    getVisualChapter: getVisualChapter,
    getVisualChapterBlend: getVisualChapterBlend,
    getVisualChapterBuffer: getVisualChapterBuffer,
    getChapterWindow: getVisualChapterBuffer,
    getRollingChapterBuffer: getVisualChapterBuffer,
    getVisualLayerPlan: getVisualLayerPlan,
    getCameraProfile: getCameraProfile,
    getBackdropMetadata: getBackdropMetadata,
    maskCinematicPlate: maskCinematicPlate,
    drawTransitionConcealment: drawTransitionConcealment,
    getPhotoWorldReadiness: getPhotoWorldReadiness,
    isPhotoWorldReady: isPhotoWorldReady,
    drawWorldLayer: drawWorldLayer,
    drawFarField: drawFarField,
    drawMidField: drawMidField,
    drawNearLayer: drawNearLayer,
    drawNearField: wrappedDrawNearField,
    drawPhotographicNearField: drawNearLayer,
    getFixtureLampGlowStyle: getFixtureLampGlowStyle,
    makeSignFaceKey: makeSignFaceKey,
    getCachedSignFace: getCachedSignFace,
    getSignSurface: getCachedSignFace,
    getSignFace: getCachedSignFace,
    drawCachedSignFace: drawCachedSignFace,
    drawSignFace: drawCachedSignFace,
    clearSignFaceCache: clearSignFaceCache,
    getSignFaceCacheStats: getSignFaceCacheStats,
    getPortalCarryGeometry: getPortalCarryGeometry,
    drawPortalCarry: drawPortalCarry
  });

  var worldNamespace = namespace.world;
  if (!worldNamespace || typeof worldNamespace !== 'object') worldNamespace = {};
  Object.assign(worldNamespace, {
    version: VERSION,
    mode: 'photographic-2.5d',
    chapters: VISUAL_CHAPTERS,
    chapterLengths: CHAPTER_LENGTHS,
    chapterLengthRanges: CHAPTER_LENGTH_RANGES,
    chapterAuthoring: CHAPTER_AUTHORING,
    chapterAcousticZones: CHAPTER_ACOUSTIC_ZONES,
    assets: ASSETS,
    cityContinuityVersion: VERSION,
    cityContinuityAssetIds: CITY_CONTINUITY_ASSET_IDS_V3311,
    cityContinuityMaxItems: CITY_CONTINUITY_MAX_ITEMS_V3311,
    cityScenerySurfaceLimit: CITY_SCENERY_SURFACE_LIMIT_V3311,
    citySceneryPhoneSurfaceWidth: CITY_SCENERY_PHONE_SURFACE_WIDTH_V3311,
    citySceneryLiveFilters: CITY_SCENERY_LIVE_FILTERS_V3311,
    cityBackdropMaskPassthrough: CITY_BACKDROP_MASK_PASSTHROUGH_V3311,
    getCityScenerySurfaceCacheStats: getCityScenerySurfaceCacheStatsV3311,
    phoneDirectSource: PHONE_DIRECT_SOURCE_V3313,
    phoneDirectSourceMaxWidth: PHONE_DIRECT_SOURCE_MAX_WIDTH_V3313,
    getPhoneDirectSourceStats: getPhoneDirectSourceStatsV3313,
    motorwayScenerySurfaceLimit: MOTORWAY_SCENERY_SURFACE_LIMIT_V339,
    motorwaySceneryPhoneSurfaceWidth: MOTORWAY_SCENERY_PHONE_SURFACE_WIDTH_V3312,
    motorwaySceneryLiveFilters: MOTORWAY_SCENERY_LIVE_FILTERS_V3312,
    getMotorwayScenerySurfaceCacheStats: getMotorwayScenerySurfaceCacheStatsV3312,
    backdropProfiles: BACKDROP_BASE,
    viewportCalibrations: VIEWPORT_CALIBRATION,
    normaliseWeather: normaliseWeather,
    normalizeWeather: normaliseWeather,
    isActualTunnel: isActualTunnel,
    getVisualChapter: getVisualChapter,
    getVisualChapterBlend: getVisualChapterBlend,
    getVisualChapterBuffer: getVisualChapterBuffer,
    getChapterWindow: getVisualChapterBuffer,
    getRollingChapterBuffer: getVisualChapterBuffer,
    getVisualLayerPlan: getVisualLayerPlan,
    getCameraProfile: getCameraProfile,
    getBackdropMetadata: getBackdropMetadata,
    maskCinematicPlate: maskCinematicPlate,
    drawTransitionConcealment: drawTransitionConcealment,
    getPhotoWorldReadiness: getPhotoWorldReadiness,
    isPhotoWorldReady: isPhotoWorldReady,
    drawWorldLayer: drawWorldLayer,
    drawFarField: drawFarField,
    drawMidField: drawMidField,
    drawNearLayer: drawNearLayer,
    drawNearField: wrappedDrawNearField,
    makeSignFaceKey: makeSignFaceKey,
    getCachedSignFace: getCachedSignFace,
    getSignSurface: getCachedSignFace,
    drawCachedSignFace: drawCachedSignFace,
    clearSignFaceCache: clearSignFaceCache,
    getSignFaceCacheStats: getSignFaceCacheStats,
    getPortalCarryGeometry: getPortalCarryGeometry,
    drawPortalCarry: drawPortalCarry
  });
  namespace.world = worldNamespace;

  globalScope.AvenraNextGenV300 = namespace;
})(typeof window !== 'undefined' ? window : (typeof globalThis !== 'undefined' ? globalThis : this));
