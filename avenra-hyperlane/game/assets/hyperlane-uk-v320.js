/*
 * AVENRÀ HYPERLANE — UK road-language overlay v3.2.0
 *
 * Lightweight, deterministic Canvas 2D additions for the photographic 2.5D
 * renderer.  This file is intentionally additive: it wraps drawNearField
 * after hyperlane-world-v320.js has attached the active API and leaves the
 * compiled road, signs, gantries, services and traffic renderer untouched.
 */
(function attachAvenraUkRoadLanguage(globalScope) {
  'use strict';

  if (!globalScope) return;

  var namespace = globalScope.AvenraNextGenV300;
  if (!namespace || typeof namespace !== 'object') return;

  var VERSION = '3.2.0';
  var TAU = Math.PI * 2;
  var VALID_ROUTES = { city: true, rural: true, motorway: true };
  var SERVICE_SECONDS = [15, 34, 63, 78];
  var FEATURE_LIMITS = {
    city: 205,
    rural: 210,
    motorway: 225
  };

  function finite(value, fallback) {
    var number;
    try { number = Number(value); } catch (error) { return fallback; }
    return Number.isFinite(number) ? number : fallback;
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

  function freeze(value) {
    try { return Object.freeze(value); } catch (error) { return value; }
  }

  function normaliseRoute(value) {
    var route = String(value || '').toLowerCase();
    if (route === 'm1' || route === 'highway') route = 'motorway';
    if (route === 'district' || route === 'urban') route = 'city';
    if (route === 'country' || route === 'countryside') route = 'rural';
    return VALID_ROUTES[route] ? route : 'city';
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

  function stateSeed(state, options) {
    var value = options && (options.seed != null ? options.seed : options.runSeed);
    if (value == null && state) {
      value = state.weeklySeed != null ? state.weeklySeed :
        state.nextGenV300 && state.nextGenV300.runSeed != null ? state.nextGenV300.runSeed :
        state.trafficSeed != null ? state.trafficSeed : state.routeSeed;
    }
    if (Number.isFinite(Number(value))) return Number(value) >>> 0;
    return hashString(value || 'avenra-uk-road-language');
  }

  function unpackCall(projectorOrConfig, state, options) {
    var projector = projectorOrConfig;
    var config = null;
    if (projectorOrConfig && typeof projectorOrConfig === 'object') {
      config = projectorOrConfig;
      projector = config.project || config.projector;
      state = config.state || state || config;
      options = Object.assign({}, state || {}, config, config.options || {}, options || {});
    }
    state = state && typeof state === 'object' ? state : {};
    options = options && typeof options === 'object' ? options : {};
    return {
      projector: projector,
      state: state,
      options: options,
      routeId: normaliseRoute(options.routeId || options.route || state.routeId || state.route),
      worldDistance: Math.max(0, finite(
        state.worldDistance != null ? state.worldDistance : state.distance,
        finite(options.worldDistance, 0)
      )),
      seed: stateSeed(state, options),
      roadHalfWidth: clamp(finite(
        options.roadHalfWidth,
        normaliseRoute(options.routeId || state.routeId) === 'motorway' ? 5.4 :
          normaliseRoute(options.routeId || state.routeId) === 'city' ? 4.2 : 3.8
      ), 2.6, 8.5),
      tier: String(options.tier || options.quality || state.graphicsQuality || 'cinematic').toLowerCase()
    };
  }

  function isTunnel(state, routeId) {
    var stage = String(state && (state.routeStage || state.stage) || '').toLowerCase();
    return stage === 'tunnel' && routeId === 'city';
  }

  function isCityExpressway(state) {
    return String(state && (state.routeStage || state.stage) || '').toLowerCase() === 'expressway';
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

  function polygonPoints(projector, points) {
    var projected = [];
    for (var index = 0; index < points.length; index += 1) {
      var point = points[index];
      var screen = projectSafe(projector, point.x, finite(point.h, 0.015), point.z);
      if (!screen) return null;
      projected.push(screen);
    }
    return projected;
  }

  function fillPolygon(ctx, points) {
    if (!points || points.length < 3) return false;
    ctx.beginPath();
    ctx.moveTo(points[0].x, points[0].y);
    for (var index = 1; index < points.length; index += 1) ctx.lineTo(points[index].x, points[index].y);
    ctx.closePath();
    ctx.fill();
    return true;
  }

  function strokeLine(ctx, first, second) {
    if (!first || !second) return false;
    ctx.beginPath();
    ctx.moveTo(first.x, first.y);
    ctx.lineTo(second.x, second.y);
    ctx.stroke();
    return true;
  }

  function featureAlpha(distance, state, options) {
    var weather = String(state && state.weather || options && options.weather || 'clear').toLowerCase();
    var time = String(state && state.timeOfDay || options && options.timeOfDay || 'day').toLowerCase();
    var far = weather === 'fog' ? 92 : weather === 'storm' ? 126 : weather === 'rain' ? 166 : 225;
    if (time === 'night') far *= 0.88;
    var nearFade = smoothstep(1.2, 4.2, distance);
    var farFade = 1 - smoothstep(far * 0.67, far, distance);
    return clamp(nearFade * farFade, 0, 1);
  }

  function markingAlpha(state) {
    var weather = String(state && state.weather || 'clear').toLowerCase();
    var time = String(state && state.timeOfDay || 'day').toLowerCase();
    var alpha = weather === 'storm' ? 0.66 : weather === 'rain' ? 0.73 : weather === 'fog' ? 0.71 : 0.86;
    if (time === 'night') alpha *= 0.88;
    return alpha;
  }

  function rgba(rgb, alpha) {
    return 'rgba(' + rgb[0] + ',' + rgb[1] + ',' + rgb[2] + ',' + clamp(alpha, 0, 1).toFixed(3) + ')';
  }

  function groundSegment(ctx, projector, x1, z1, x2, z2, width, colour) {
    if (!(z1 > 1.05 || z2 > 1.05)) return false;
    var dx = x2 - x1;
    var dz = z2 - z1;
    var length = Math.sqrt(dx * dx + dz * dz);
    if (length < 0.001) return false;
    var offsetX = -dz / length * width * 0.5;
    var offsetZ = dx / length * width * 0.5;
    var points = polygonPoints(projector, [
      { x: x1 + offsetX, z: z1 + offsetZ },
      { x: x2 + offsetX, z: z2 + offsetZ },
      { x: x2 - offsetX, z: z2 - offsetZ },
      { x: x1 - offsetX, z: z1 - offsetZ }
    ]);
    if (!points) return false;
    ctx.fillStyle = colour;
    return fillPolygon(ctx, points);
  }

  function chapterAt(config, absoluteDistance) {
    var getter = namespace.getVisualChapter || namespace.world && namespace.world.getVisualChapter;
    if (typeof getter === 'function') {
      try {
        return getter(Object.assign({}, config.state, {
          routeId: config.routeId,
          worldDistance: Math.max(0, absoluteDistance),
          seed: config.seed
        }), config.options);
      } catch (error) {}
    }
    return config.state.routeChapter || config.state.nextGenV300 && config.state.nextGenV300.routeChapter || null;
  }

  function chapterId(chapter) {
    return String(chapter && (chapter.id || chapter.chapterId || chapter.title) || '').toLowerCase();
  }

  function builtUpCityChapter(chapter) {
    var id = chapterId(chapter);
    if (!id) return true;
    return !/(tunnel|underpass|expressway|dual-carriageway|motorway|open-country)/.test(id);
  }

  function serviceChapter(chapter) {
    return /service/.test(chapterId(chapter));
  }

  function mergeChapter(chapter) {
    return /(merge|junction|slip|approach|gateway|service)/.test(chapterId(chapter));
  }

  function anchorSeries(worldDistance, maximumDistance, interval, seed, salt) {
    var phase = hashUnit(seed + salt) * interval;
    var firstCell = Math.floor((worldDistance + 2 - phase) / interval) - 1;
    var lastCell = Math.ceil((worldDistance + maximumDistance - phase) / interval) + 1;
    var anchors = [];
    for (var cell = firstCell; cell <= lastCell; cell += 1) {
      var absolute = cell * interval + phase;
      var relative = absolute - worldDistance;
      if (absolute >= 0 && relative >= -38 && relative <= maximumDistance + 38) anchors.push(absolute);
    }
    return anchors;
  }

  function collectVisibleChapters(config, maximumDistance) {
    var found = Object.create(null);
    var chapters = [];
    for (var absolute = Math.max(0, config.worldDistance - 20); absolute <= config.worldDistance + maximumDistance + 30; absolute += 34) {
      var chapter = chapterAt(config, absolute);
      if (!chapter) continue;
      var start = finite(chapter.startMetres, NaN);
      var end = finite(chapter.endMetres, NaN);
      var key = chapterId(chapter) + ':' + (Number.isFinite(start) ? start.toFixed(1) : Math.floor(absolute / 34));
      if (found[key]) continue;
      found[key] = true;
      chapters.push({ chapter: chapter, startMetres: start, endMetres: end });
    }
    return chapters;
  }

  function activeMergeState(state) {
    var activeKind = String(state && state.trafficDirector && state.trafficDirector.activeKind || '').toLowerCase();
    var cue = state && (state.directorCue || state.nextGenV300 && state.nextGenV300.director && state.nextGenV300.director.telegraph);
    var cueText = String(cue && (cue.kind || cue.id || cue.title || cue.message) || '').toLowerCase();
    var scenario = state && state.nextGenV300 && state.nextGenV300.director && state.nextGenV300.director.activeScenario;
    var scenarioText = String(scenario && (scenario.kind || scenario.id) || '').toLowerCase();
    return /merge/.test(activeKind) || /merge/.test(cueText) || /merge/.test(scenarioText);
  }

  function resolveVariableLimit(state) {
    state = state && typeof state === 'object' ? state : {};
    var source = state.variableLimitMph != null ? state.variableLimitMph :
      state.nextGenV300 && state.nextGenV300.variableLimitV320;
    if (source == null || source === false) return null;
    var value = source;
    var active = true;
    var anchor = NaN;
    if (source && typeof source === 'object') {
      active = source.active !== false && source.visible !== false;
      value = source.limitMph != null ? source.limitMph :
        source.mph != null ? source.mph :
        source.limit != null ? source.limit : source.value;
      anchor = finite(
        source.anchorWorldDistance != null ? source.anchorWorldDistance :
          source.worldDistance != null ? source.worldDistance : source.atMetres,
        NaN
      );
    }
    var mph = Math.round(finite(value, NaN) / 10) * 10;
    if (!active || !Number.isFinite(mph) || mph < 20 || mph > 80) return null;
    return { mph: mph, anchorWorldDistance: anchor };
  }

  function addUniqueFeature(features, feature, tolerance) {
    tolerance = finite(tolerance, 2);
    for (var index = 0; index < features.length; index += 1) {
      var existing = features[index];
      if (existing.type === feature.type && Math.abs(existing.absoluteDistance - feature.absoluteDistance) < tolerance) return false;
    }
    features.push(feature);
    return true;
  }

  function getUKRoadLanguagePlan(stateOrConfig, options) {
    var config;
    if (stateOrConfig && typeof stateOrConfig === 'object' && (stateOrConfig.project || stateOrConfig.projector || stateOrConfig.state)) {
      config = unpackCall(stateOrConfig, stateOrConfig.state, options);
    } else {
      config = unpackCall(null, stateOrConfig, options);
    }
    var maximumDistance = FEATURE_LIMITS[config.routeId];
    var features = [];
    var state = config.state;
    var currentChapter = chapterAt(config, config.worldDistance + 8);

    if (isTunnel(state, config.routeId) || finite(config.options.density, 1) <= 0) {
      return freeze({
        kind: 'avenra-uk-road-language-plan-v320', version: VERSION,
        routeId: config.routeId, worldDistance: config.worldDistance, seed: config.seed,
        chapterId: chapterId(currentChapter), skipped: true, features: freeze([])
      });
    }

    if (config.routeId === 'city' && !isCityExpressway(state)) {
      features.push({
        type: 'double-yellow-restriction',
        absoluteDistance: config.worldDistance + 3,
        relativeDistance: 3,
        lengthMetres: maximumDistance - 3
      });

      var boxAnchors = anchorSeries(config.worldDistance, maximumDistance, 382, config.seed, 3201);
      for (var boxIndex = 0; boxIndex < boxAnchors.length; boxIndex += 1) {
        var boxAbsolute = boxAnchors[boxIndex];
        if (!builtUpCityChapter(chapterAt(config, boxAbsolute))) continue;
        var boxRelative = boxAbsolute - config.worldDistance;
        if (boxRelative < -12 || boxRelative > maximumDistance + 12) continue;
        addUniqueFeature(features, {
          type: 'box-junction', absoluteDistance: boxAbsolute,
          relativeDistance: boxRelative, widthMetres: Math.min(7.25, config.roadHalfWidth * 1.76), lengthMetres: 15.5
        });
      }

      var zebraAnchors = anchorSeries(config.worldDistance, maximumDistance, 337, config.seed, 7213);
      for (var zebraIndex = 0; zebraIndex < zebraAnchors.length; zebraIndex += 1) {
        var zebraAbsolute = zebraAnchors[zebraIndex];
        if (!builtUpCityChapter(chapterAt(config, zebraAbsolute))) continue;
        var tooClose = features.some(function nearBox(feature) {
          return feature.type === 'box-junction' && Math.abs(feature.absoluteDistance - zebraAbsolute) < 52;
        });
        if (tooClose) continue;
        var zebraRelative = zebraAbsolute - config.worldDistance;
        if (zebraRelative < -26 || zebraRelative > maximumDistance + 26) continue;
        addUniqueFeature(features, {
          type: 'zebra-crossing', absoluteDistance: zebraAbsolute,
          relativeDistance: zebraRelative, widthMetres: config.roadHalfWidth * 1.90, controlledZoneMetres: 29
        });
      }
    }

    if (config.routeId === 'rural' || config.routeId === 'motorway') {
      features.push({
        type: 'reflective-post-run',
        absoluteDistance: config.worldDistance + 3,
        relativeDistance: 3,
        lengthMetres: maximumDistance - 3,
        spacingMetres: config.routeId === 'motorway' ? 72 : 58
      });
    }

    if (config.routeId === 'motorway') {
      var visibleChapters = collectVisibleChapters(config, maximumDistance);
      for (var chapterIndex = 0; chapterIndex < visibleChapters.length; chapterIndex += 1) {
        var entry = visibleChapters[chapterIndex];
        if (mergeChapter(entry.chapter) && Number.isFinite(entry.startMetres)) {
          var studStart = entry.startMetres + 5;
          var studRelative = studStart - config.worldDistance;
          if (studRelative > -55 && studRelative < maximumDistance + 12) {
            addUniqueFeature(features, {
              type: 'green-slip-studs', absoluteDistance: studStart,
              relativeDistance: studRelative, lengthMetres: 54,
              chapterId: chapterId(entry.chapter), source: 'chapter-boundary'
            }, 24);
          }
        }
        if (serviceChapter(entry.chapter) && Number.isFinite(entry.startMetres) && Number.isFinite(entry.endMetres)) {
          var serviceLength = Math.max(1, entry.endMetres - entry.startMetres);
          var parkingInterval = clamp(serviceLength, 96, 168);
          var firstParking = entry.startMetres + Math.min(34, serviceLength * 0.36);
          for (var parkingAbsolute = firstParking; parkingAbsolute < entry.endMetres; parkingAbsolute += parkingInterval) {
            var parkingRelative = parkingAbsolute - config.worldDistance;
            if (parkingRelative > -34 && parkingRelative < maximumDistance + 18) {
              addUniqueFeature(features, {
                type: 'service-hgv-parking', absoluteDistance: parkingAbsolute,
                relativeDistance: parkingRelative, chapterId: chapterId(entry.chapter), source: 'visual-chapter'
              }, 52);
            }
          }
        }
      }

      if (activeMergeState(state)) {
        var mergePhase = hashUnit(config.seed + 9109) * 42;
        var mergeAnchor = Math.ceil((config.worldDistance + 38 - mergePhase) / 220) * 220 + mergePhase;
        addUniqueFeature(features, {
          type: 'green-slip-studs', absoluteDistance: mergeAnchor,
          relativeDistance: mergeAnchor - config.worldDistance,
          lengthMetres: 60, chapterId: chapterId(currentChapter), source: 'active-merge'
        }, 28);
      }

      var elapsed = finite(state.elapsed, NaN);
      if (Number.isFinite(elapsed)) {
        for (var serviceIndex = 0; serviceIndex < SERVICE_SECONDS.length; serviceIndex += 1) {
          var relative = (SERVICE_SECONDS[serviceIndex] - elapsed) * 41 + 48;
          if (relative < -30 || relative > maximumDistance + 16) continue;
          addUniqueFeature(features, {
            type: 'service-hgv-parking',
            absoluteDistance: config.worldDistance + relative,
            relativeDistance: relative,
            source: 'route-service-approach'
          }, 58);
        }
      }

      var variableLimit = resolveVariableLimit(state);
      if (variableLimit) {
        var limitAnchor = variableLimit.anchorWorldDistance;
        if (!Number.isFinite(limitAnchor)) {
          var gantryPhase = hashUnit(config.seed + 14419) * 64;
          limitAnchor = Math.ceil((config.worldDistance + 52 - gantryPhase) / 250) * 250 + gantryPhase;
        }
        var gantryRelative = limitAnchor - config.worldDistance;
        if (gantryRelative > 9 && gantryRelative < maximumDistance + 14) {
          features.push({
            type: 'variable-speed-limit', absoluteDistance: limitAnchor,
            relativeDistance: gantryRelative, mph: variableLimit.mph
          });
        }
      }
    }

    features.sort(function farToNear(first, second) {
      return second.relativeDistance - first.relativeDistance;
    });

    return freeze({
      kind: 'avenra-uk-road-language-plan-v320', version: VERSION,
      routeId: config.routeId, worldDistance: config.worldDistance, seed: config.seed,
      chapterId: chapterId(currentChapter), skipped: false,
      features: freeze(features.map(freeze))
    });
  }

  function drawDoubleYellow(ctx, config, feature) {
    var near = Math.max(2.2, feature.relativeDistance);
    var far = Math.min(FEATURE_LIMITS.city, feature.relativeDistance + feature.lengthMetres);
    var chunk = config.tier === 'smooth' ? 11 : 7.5;
    var lines = [
      -config.roadHalfWidth + 0.16,
      -config.roadHalfWidth + 0.34,
      config.roadHalfWidth - 0.34,
      config.roadHalfWidth - 0.16
    ];
    var baseAlpha = markingAlpha(config.state);
    var drawn = 0;
    for (var z = near; z < far; z += chunk) {
      var z2 = Math.min(far, z + chunk + 0.18);
      var absoluteMiddle = config.worldDistance + (z + z2) * 0.5;
      if (!builtUpCityChapter(chapterAt(config, absoluteMiddle))) continue;
      var alpha = featureAlpha((z + z2) * 0.5, config.state, config.options) * baseAlpha;
      if (alpha < 0.008) continue;
      var colour = rgba([239, 190, 50], alpha);
      for (var lineIndex = 0; lineIndex < lines.length; lineIndex += 1) {
        if (groundSegment(ctx, config.projector, lines[lineIndex], z, lines[lineIndex], z2, 0.078, colour)) drawn += 1;
      }
    }
    return drawn;
  }

  function drawBoxJunction(ctx, config, feature) {
    var centre = feature.relativeDistance;
    var halfWidth = feature.widthMetres * 0.5;
    var halfLength = feature.lengthMetres * 0.5;
    var xMin = -halfWidth;
    var xMax = halfWidth;
    var zMin = centre - halfLength;
    var zMax = centre + halfLength;
    if (zMax < 1.15 || zMin > FEATURE_LIMITS.city) return 0;
    var alpha = featureAlpha(Math.max(2, centre), config.state, config.options) * markingAlpha(config.state) * 0.98;
    if (alpha < 0.008) return 0;
    var colour = rgba([244, 191, 39], alpha);
    var drawn = 0;
    if (groundSegment(ctx, config.projector, xMin, zMin, xMax, zMin, 0.115, colour)) drawn += 1;
    if (groundSegment(ctx, config.projector, xMax, zMin, xMax, zMax, 0.115, colour)) drawn += 1;
    if (groundSegment(ctx, config.projector, xMax, zMax, xMin, zMax, 0.115, colour)) drawn += 1;
    if (groundSegment(ctx, config.projector, xMin, zMax, xMin, zMin, 0.115, colour)) drawn += 1;

    function drawDiagonalFamily(slope) {
      var cMinimum = zMin - Math.max(slope * xMin, slope * xMax) - 1;
      var cMaximum = zMax - Math.min(slope * xMin, slope * xMax) + 1;
      for (var intercept = cMinimum; intercept <= cMaximum; intercept += 3.9) {
        var points = [];
        var zAtLeft = slope * xMin + intercept;
        var zAtRight = slope * xMax + intercept;
        var xAtNear = (zMin - intercept) / slope;
        var xAtFar = (zMax - intercept) / slope;
        if (zAtLeft >= zMin && zAtLeft <= zMax) points.push({ x: xMin, z: zAtLeft });
        if (zAtRight >= zMin && zAtRight <= zMax) points.push({ x: xMax, z: zAtRight });
        if (xAtNear >= xMin && xAtNear <= xMax) points.push({ x: xAtNear, z: zMin });
        if (xAtFar >= xMin && xAtFar <= xMax) points.push({ x: xAtFar, z: zMax });
        if (points.length < 2) continue;
        var first = points[0];
        var second = points[1];
        if (groundSegment(ctx, config.projector, first.x, first.z, second.x, second.z, 0.105, colour)) drawn += 1;
      }
    }

    drawDiagonalFamily(1);
    drawDiagonalFamily(-1);
    return drawn;
  }

  function drawGroundPolyline(ctx, config, points, width, colour) {
    var drawn = 0;
    for (var index = 0; index < points.length - 1; index += 1) {
      var first = points[index];
      var second = points[index + 1];
      var middle = (first.z + second.z) * 0.5;
      var alpha = featureAlpha(middle, config.state, config.options);
      if (alpha <= 0.006) continue;
      if (groundSegment(ctx, config.projector, first.x, first.z, second.x, second.z, width, rgba(colour, alpha * markingAlpha(config.state)))) drawn += 1;
    }
    return drawn;
  }

  function drawVerticalQuad(ctx, projector, roadX, distance, fromHeight, toHeight, width, colour) {
    var points = polygonPoints(projector, [
      { x: roadX - width * 0.5, h: fromHeight, z: distance },
      { x: roadX - width * 0.5, h: toHeight, z: distance },
      { x: roadX + width * 0.5, h: toHeight, z: distance },
      { x: roadX + width * 0.5, h: fromHeight, z: distance }
    ]);
    if (!points) return false;
    ctx.fillStyle = colour;
    return fillPolygon(ctx, points);
  }

  function drawBelishaBeacon(ctx, config, roadX, distance, side, alpha) {
    var drawn = 0;
    var stripeHeight = 0.34;
    for (var height = 0; height < 2.38; height += stripeHeight) {
      var stripeTop = Math.min(2.38, height + stripeHeight + 0.015);
      var black = Math.floor(height / stripeHeight) % 2 === 0;
      if (drawVerticalQuad(
        ctx, config.projector, roadX, distance, height, stripeTop, 0.115,
        black ? rgba([18, 20, 20], alpha * 0.96) : rgba([238, 239, 226], alpha * 0.96)
      )) drawn += 1;
    }
    var globe = projectSafe(config.projector, roadX, 2.58, distance);
    var globeLower = projectSafe(config.projector, roadX, 2.38, distance);
    if (globe && globeLower) {
      var radius = clamp(Math.abs(globeLower.y - globe.y) * 0.66, 0.75, 17);
      var time = finite(config.state.elapsed, 0);
      var pulse = 0.82 + 0.18 * Math.sin(time * TAU * 1.45 + (side > 0 ? Math.PI : 0));
      if (String(config.state.timeOfDay || 'day') !== 'day' && radius > 1.2 && typeof ctx.createRadialGradient === 'function') {
        var halo = ctx.createRadialGradient(globe.x, globe.y, radius * 0.18, globe.x, globe.y, radius * 3.2);
        halo.addColorStop(0, rgba([255, 171, 41], alpha * 0.38 * pulse));
        halo.addColorStop(1, 'rgba(255,151,31,0)');
        ctx.fillStyle = halo;
        ctx.beginPath();
        ctx.arc(globe.x, globe.y, radius * 3.2, 0, TAU);
        ctx.fill();
      }
      ctx.fillStyle = rgba([255, 154, 27], alpha * pulse);
      ctx.strokeStyle = rgba([53, 36, 19], alpha * 0.92);
      ctx.lineWidth = clamp(radius * 0.16, 0.5, 2.2);
      ctx.beginPath();
      ctx.arc(globe.x, globe.y, radius, 0, TAU);
      ctx.fill();
      ctx.stroke();
      drawn += 1;
    }
    return drawn;
  }

  function drawZebraCrossing(ctx, config, feature) {
    var centre = feature.relativeDistance;
    if (centre < -28 || centre > FEATURE_LIMITS.city + 25) return 0;
    var roadWidth = feature.widthMetres;
    var xMin = -roadWidth * 0.5;
    var xMax = roadWidth * 0.5;
    var drawn = 0;
    var baseAlpha = markingAlpha(config.state);

    var zigZones = [
      [centre - feature.controlledZoneMetres, centre - 4.1],
      [centre + 4.1, centre + feature.controlledZoneMetres * 0.78]
    ];
    for (var zoneIndex = 0; zoneIndex < zigZones.length; zoneIndex += 1) {
      var zone = zigZones[zoneIndex];
      for (var sideIndex = -1; sideIndex <= 1; sideIndex += 2) {
        var zigPoints = [];
        var step = 3.15;
        var count = Math.ceil((zone[1] - zone[0]) / step);
        for (var pointIndex = 0; pointIndex <= count; pointIndex += 1) {
          var amount = pointIndex / Math.max(1, count);
          var z = mix(zone[0], zone[1], amount);
          var x = sideIndex * (config.roadHalfWidth - 0.47) + (pointIndex % 2 === 0 ? -1 : 1) * 0.30 * sideIndex;
          zigPoints.push({ x: x, z: z });
        }
        drawn += drawGroundPolyline(ctx, config, zigPoints, 0.095, [242, 242, 232]);
      }
    }

    var crossingStart = centre - 2.3;
    for (var stripe = 0; stripe < 7; stripe += 1) {
      var z1 = crossingStart + stripe * 0.68;
      var z2 = z1 + 0.42;
      var alpha = featureAlpha((z1 + z2) * 0.5, config.state, config.options) * baseAlpha * 1.08;
      if (alpha <= 0.006) continue;
      if (groundSegment(ctx, config.projector, xMin, (z1 + z2) * 0.5, xMax, (z1 + z2) * 0.5, z2 - z1, rgba([242, 243, 237], alpha))) drawn += 1;
    }

    var beaconAlpha = featureAlpha(Math.max(2, centre), config.state, config.options);
    if (beaconAlpha > 0.006) {
      drawn += drawBelishaBeacon(ctx, config, -config.roadHalfWidth - 0.95, centre, -1, beaconAlpha);
      drawn += drawBelishaBeacon(ctx, config, config.roadHalfWidth + 0.95, centre, 1, beaconAlpha);
    }
    return drawn;
  }

  function drawGreenStudRun(ctx, config, feature) {
    var start = feature.relativeDistance;
    var end = start + feature.lengthMetres;
    var drawn = 0;
    for (var z = start; z <= end; z += 4.2) {
      if (z < 1.3 || z > FEATURE_LIMITS.motorway) continue;
      var progress = clamp((z - start) / Math.max(1, feature.lengthMetres), 0, 1);
      var x = -config.roadHalfWidth - 0.05 - smoothstep(0.08, 0.95, progress) * 1.42;
      var alpha = featureAlpha(z, config.state, config.options) * 0.95;
      if (alpha <= 0.006) continue;
      var points = polygonPoints(config.projector, [
        { x: x - 0.075, z: z - 0.13 },
        { x: x + 0.075, z: z - 0.13 },
        { x: x + 0.075, z: z + 0.13 },
        { x: x - 0.075, z: z + 0.13 }
      ]);
      if (!points) continue;
      ctx.fillStyle = rgba([68, 225, 141], alpha);
      fillPolygon(ctx, points);
      if (String(config.state.timeOfDay || 'day') !== 'day') {
        var centre = projectSafe(config.projector, x, 0.03, z);
        if (centre && typeof ctx.arc === 'function') {
          var radius = clamp(finite(centre.scale, 1) * 0.025, 0.45, 3.4);
          ctx.fillStyle = rgba([77, 244, 156], alpha * 0.19);
          ctx.beginPath();
          ctx.arc(centre.x, centre.y, radius * 2.2, 0, TAU);
          ctx.fill();
        }
      }
      drawn += 1;
    }
    return drawn;
  }

  function drawReflectivePost(ctx, config, roadX, distance, side, alpha) {
    var height = config.routeId === 'motorway' ? 1.08 : 0.98;
    var width = config.routeId === 'motorway' ? 0.20 : 0.18;
    var drawn = 0;
    if (drawVerticalQuad(ctx, config.projector, roadX, distance, 0, height, width, rgba([236, 238, 226], alpha * 0.96))) drawn += 1;
    if (drawVerticalQuad(ctx, config.projector, roadX, distance, height * 0.56, height * 0.86, width * 1.12, rgba([20, 24, 24], alpha * 0.98))) drawn += 1;
    var reflectorColour = side < 0 ? [231, 239, 230] : [236, 211, 98];
    if (drawVerticalQuad(ctx, config.projector, roadX, distance, height * 0.66, height * 0.76, width * 0.72, rgba(reflectorColour, alpha))) drawn += 1;
    return drawn;
  }

  function drawReflectivePostRun(ctx, config, feature) {
    var spacing = feature.spacingMetres;
    var phase = hashUnit(config.seed + (config.routeId === 'motorway' ? 18181 : 17231)) * spacing;
    var firstCell = Math.floor((config.worldDistance + 2 - phase) / spacing) - 1;
    var lastCell = Math.ceil((config.worldDistance + feature.lengthMetres - phase) / spacing) + 1;
    var maximumPairs = config.tier === 'smooth' ? 5 : 7;
    var pairs = 0;
    var drawn = 0;
    for (var cell = lastCell; cell >= firstCell && pairs < maximumPairs; cell -= 1) {
      var absolute = cell * spacing + phase;
      var distance = absolute - config.worldDistance;
      if (distance < 3 || distance > FEATURE_LIMITS[config.routeId]) continue;
      var alpha = featureAlpha(distance, config.state, config.options);
      if (alpha <= 0.006) continue;
      var offset = config.routeId === 'motorway' ? 1.32 : 1.02;
      drawn += drawReflectivePost(ctx, config, -config.roadHalfWidth - offset, distance, -1, alpha);
      drawn += drawReflectivePost(ctx, config, config.roadHalfWidth + offset, distance + 0.34, 1, alpha * 0.96);
      pairs += 1;
    }
    return drawn;
  }

  function drawParkedHgv(ctx, config, roadX, rearDistance, index, alpha) {
    var width = 2.5;
    var trailerLength = 12.2;
    var height = 3.85;
    var xLeft = roadX - width * 0.5;
    var xRight = roadX + width * 0.5;
    var zRear = rearDistance;
    var zFront = rearDistance + trailerLength;
    var rear = polygonPoints(config.projector, [
      { x: xLeft, h: 0.24, z: zRear },
      { x: xLeft, h: height, z: zRear },
      { x: xRight, h: height, z: zRear },
      { x: xRight, h: 0.24, z: zRear }
    ]);
    var side = polygonPoints(config.projector, [
      { x: xRight, h: 0.24, z: zRear },
      { x: xRight, h: height, z: zRear },
      { x: xRight, h: height, z: zFront },
      { x: xRight, h: 0.24, z: zFront }
    ]);
    var roof = polygonPoints(config.projector, [
      { x: xLeft, h: height, z: zRear },
      { x: xLeft, h: height, z: zFront },
      { x: xRight, h: height, z: zFront },
      { x: xRight, h: height, z: zRear }
    ]);
    if (!rear || !side || !roof) return 0;
    var time = String(config.state.timeOfDay || 'day');
    var body = time === 'day' ? (index % 2 === 0 ? [184, 188, 184] : [69, 79, 82]) : [31, 39, 42];
    ctx.fillStyle = rgba([26, 31, 32], alpha * 0.96);
    fillPolygon(ctx, side);
    ctx.fillStyle = rgba([205, 207, 198], alpha * (time === 'day' ? 0.88 : 0.48));
    fillPolygon(ctx, roof);
    ctx.fillStyle = rgba(body, alpha * 0.98);
    fillPolygon(ctx, rear);

    var rearWidth = Math.abs(rear[3].x - rear[0].x);
    var rearHeight = Math.abs(rear[0].y - rear[1].y);
    if (rearWidth > 4 && rearHeight > 4) {
      ctx.strokeStyle = rgba([36, 43, 43], alpha * 0.76);
      ctx.lineWidth = clamp(rearWidth * 0.025, 0.45, 2.2);
      strokeLine(ctx, rear[1], rear[0]);
      strokeLine(ctx, rear[2], rear[3]);
      strokeLine(ctx, { x: (rear[1].x + rear[2].x) * 0.5, y: rear[1].y }, { x: (rear[0].x + rear[3].x) * 0.5, y: rear[0].y });
      var plateWidth = clamp(rearWidth * 0.24, 1.2, 16);
      var plateHeight = clamp(rearHeight * 0.055, 0.7, 4);
      ctx.fillStyle = rgba([244, 205, 48], alpha);
      ctx.fillRect((rear[0].x + rear[3].x) * 0.5 - plateWidth * 0.5, mix(rear[1].y, rear[0].y, 0.80), plateWidth, plateHeight);
      ctx.fillStyle = rgba([215, 42, 43], alpha * 0.94);
      var lightRadius = clamp(rearWidth * 0.026, 0.55, 3.2);
      [mix(rear[0].x, rear[3].x, 0.12), mix(rear[0].x, rear[3].x, 0.88)].forEach(function tailLight(x) {
        ctx.beginPath();
        ctx.arc(x, mix(rear[1].y, rear[0].y, 0.73), lightRadius, 0, TAU);
        ctx.fill();
      });
    }
    return 1;
  }

  function drawServiceHgvParking(ctx, config, feature) {
    var centre = feature.relativeDistance;
    if (centre < -36 || centre > FEATURE_LIMITS.motorway + 18) return 0;
    var near = Math.max(2.1, centre - 7);
    var far = centre + 48;
    var xInner = -config.roadHalfWidth - 3.0;
    var xOuter = -config.roadHalfWidth - 16.8;
    var alpha = featureAlpha(Math.max(3, centre), config.state, config.options);
    if (alpha <= 0.006) return 0;
    var apron = polygonPoints(config.projector, [
      { x: xInner, z: near }, { x: xOuter, z: near + 3 },
      { x: xOuter - 1.4, z: far }, { x: xInner - 1.0, z: far }
    ]);
    var drawn = 0;
    if (apron) {
      ctx.fillStyle = rgba([35, 41, 42], alpha * (String(config.state.timeOfDay || 'day') === 'day' ? 0.92 : 0.98));
      fillPolygon(ctx, apron);
      drawn += 1;
    }
    var bayColour = rgba([224, 226, 211], alpha * 0.66);
    var hgvCount = config.tier === 'smooth' ? 2 : 3;
    for (var bay = 0; bay < hgvCount; bay += 1) {
      var bayX = -config.roadHalfWidth - 6.0 - bay * 3.35;
      var bayRear = centre + bay * 2.6;
      groundSegment(ctx, config.projector, bayX - 1.55, bayRear - 1.0, bayX - 1.55, bayRear + 16.5, 0.075, bayColour);
      groundSegment(ctx, config.projector, bayX + 1.55, bayRear - 1.0, bayX + 1.55, bayRear + 16.5, 0.075, bayColour);
      groundSegment(ctx, config.projector, bayX - 1.55, bayRear - 1.0, bayX + 1.55, bayRear - 1.0, 0.075, bayColour);
      drawn += drawParkedHgv(ctx, config, bayX, bayRear, bay, alpha * featureAlpha(Math.max(3, bayRear), config.state, config.options));
    }
    return drawn;
  }

  function drawVariableSpeedLimit(ctx, config, feature) {
    var distance = feature.relativeDistance;
    var alpha = featureAlpha(distance, config.state, config.options);
    if (distance < 7 || alpha <= 0.006) return 0;
    var halfSpan = config.roadHalfWidth + 1.1;
    var gantryHeight = 6.15;
    var leftBase = projectSafe(config.projector, -halfSpan, 0, distance);
    var rightBase = projectSafe(config.projector, halfSpan, 0, distance);
    var leftTop = projectSafe(config.projector, -halfSpan, gantryHeight, distance);
    var rightTop = projectSafe(config.projector, halfSpan, gantryHeight, distance);
    if (!leftBase || !rightBase || !leftTop || !rightTop) return 0;
    var scaleWidth = clamp(Math.abs(rightTop.x - leftTop.x) * 0.014, 0.7, 7.5);
    ctx.strokeStyle = rgba([118, 129, 130], alpha * 0.94);
    ctx.lineCap = 'square';
    ctx.lineWidth = scaleWidth;
    strokeLine(ctx, leftBase, leftTop);
    strokeLine(ctx, rightBase, rightTop);
    ctx.lineWidth = scaleWidth * 1.22;
    strokeLine(ctx, leftTop, rightTop);

    var laneCentres = [-config.roadHalfWidth * 0.66, 0, config.roadHalfWidth * 0.66];
    var time = String(config.state.timeOfDay || 'day');
    for (var laneIndex = 0; laneIndex < laneCentres.length; laneIndex += 1) {
      var centre = projectSafe(config.projector, laneCentres[laneIndex], gantryHeight - 0.72, distance);
      var upper = projectSafe(config.projector, laneCentres[laneIndex], gantryHeight - 0.08, distance);
      if (!centre || !upper) continue;
      var radius = clamp(Math.abs(centre.y - upper.y), 1.4, 34);
      ctx.fillStyle = rgba([9, 12, 13], alpha * 0.98);
      ctx.beginPath();
      ctx.arc(centre.x, centre.y, radius * 1.18, 0, TAU);
      ctx.fill();
      ctx.fillStyle = rgba([244, 244, 234], alpha);
      ctx.beginPath();
      ctx.arc(centre.x, centre.y, radius, 0, TAU);
      ctx.fill();
      ctx.strokeStyle = rgba([216, 35, 43], alpha);
      ctx.lineWidth = clamp(radius * 0.20, 0.7, 6);
      ctx.beginPath();
      ctx.arc(centre.x, centre.y, radius * 0.88, 0, TAU);
      ctx.stroke();
      if (radius > 5.2) {
        ctx.fillStyle = rgba([12, 15, 15], alpha);
        ctx.font = '800 ' + clamp(radius * 0.96, 6, 30) + 'px Arial, sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(String(feature.mph), centre.x, centre.y + radius * 0.04);
      }
      if (time !== 'day' && radius > 2 && typeof ctx.createRadialGradient === 'function') {
        var glow = ctx.createRadialGradient(centre.x, centre.y, radius * 0.5, centre.x, centre.y, radius * 2.2);
        glow.addColorStop(0, rgba([255, 53, 58], alpha * 0.10));
        glow.addColorStop(1, 'rgba(255,45,52,0)');
        ctx.fillStyle = glow;
        ctx.beginPath();
        ctx.arc(centre.x, centre.y, radius * 2.2, 0, TAU);
        ctx.fill();
      }
    }
    return 1;
  }

  function drawUKRoadLanguage(ctx, projectorOrConfig, state, options) {
    var config = unpackCall(projectorOrConfig, state, options);
    if (!ctx || typeof ctx.beginPath !== 'function' || typeof config.projector !== 'function') return 0;
    var plan = config.options.ukRoadLanguagePlan && config.options.ukRoadLanguagePlan.kind === 'avenra-uk-road-language-plan-v320' ?
      config.options.ukRoadLanguagePlan : getUKRoadLanguagePlan(Object.assign({}, config.options, { state: config.state, project: config.projector }));
    if (!plan || plan.skipped) return 0;
    var drawn = 0;
    ctx.save();
    try {
      ctx.globalCompositeOperation = 'source-over';
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      for (var index = 0; index < plan.features.length; index += 1) {
        var feature = plan.features[index];
        try {
          if (feature.type === 'double-yellow-restriction') drawn += drawDoubleYellow(ctx, config, feature);
          else if (feature.type === 'box-junction') drawn += drawBoxJunction(ctx, config, feature);
          else if (feature.type === 'zebra-crossing') drawn += drawZebraCrossing(ctx, config, feature);
          else if (feature.type === 'green-slip-studs') drawn += drawGreenStudRun(ctx, config, feature);
          else if (feature.type === 'reflective-post-run') drawn += drawReflectivePostRun(ctx, config, feature);
          else if (feature.type === 'service-hgv-parking') drawn += drawServiceHgvParking(ctx, config, feature);
          else if (feature.type === 'variable-speed-limit') drawn += drawVariableSpeedLimit(ctx, config, feature);
        } catch (featureError) {
          // A malformed optional feature must never interrupt the ride loop.
        }
      }
    } finally {
      ctx.restore();
    }
    return drawn;
  }

  var FEATURE_METADATA = freeze({
    version: VERSION,
    mode: 'projected-photographic-2.5d',
    deterministicBy: freeze(['routeId', 'worldDistance', 'runSeed', 'visualChapter']),
    routes: freeze({
      city: freeze(['double-yellow-restriction', 'box-junction', 'zebra-crossing', 'belisha-beacons']),
      rural: freeze(['reflective-post-run']),
      motorway: freeze(['green-slip-studs', 'reflective-post-run', 'service-hgv-parking', 'variable-speed-limit'])
    }),
    roadPlaneFeatures: freeze(['double-yellow-restriction', 'box-junction', 'zebra-crossing', 'green-slip-studs', 'service-parking-bays']),
    deliberatelyNotDuplicated: freeze(['SLOW markings', 'red studs', 'white studs', 'amber studs', 'direction signs', 'standard gantries', 'service buildings']),
    maximumDrawDistanceMetres: freeze({ city: FEATURE_LIMITS.city, rural: FEATURE_LIMITS.rural, motorway: FEATURE_LIMITS.motorway })
  });

  var previousDrawNearField = namespace.drawNearField;
  var drawNearFieldAlreadyWrapped = !!(
    previousDrawNearField && previousDrawNearField.__avenraUkRoadLanguageV320
  );

  function wrappedDrawNearField(ctx, projectorOrConfig, state, options) {
    var previousResult = 0;
    if (typeof previousDrawNearField === 'function') {
      try { previousResult = previousDrawNearField.apply(this, arguments); } catch (legacyError) { previousResult = 0; }
    }
    var ukResult = 0;
    try { ukResult = drawUKRoadLanguage(ctx, projectorOrConfig, state, options); } catch (ukError) { ukResult = 0; }
    return (typeof previousResult === 'number' ? previousResult : 0) + ukResult;
  }

  wrappedDrawNearField.__avenraUkRoadLanguageV320 = true;
  wrappedDrawNearField.version = VERSION;

  Object.assign(namespace, {
    ukRoadLanguageVersion: VERSION,
    ukRoadLanguageMetadata: FEATURE_METADATA,
    getUKRoadLanguagePlan: getUKRoadLanguagePlan,
    getUKFeatureAlpha: featureAlpha,
    resolveVariableLimitV320: resolveVariableLimit,
    projectUKRoadPolygon: polygonPoints,
    drawUKRoadLanguage: drawUKRoadLanguage,
    drawNearField: drawNearFieldAlreadyWrapped ? previousDrawNearField : wrappedDrawNearField
  });

  var ukNamespace = namespace.ukRoadLanguage;
  if (!ukNamespace || typeof ukNamespace !== 'object') ukNamespace = {};
  Object.assign(ukNamespace, {
    version: VERSION,
    metadata: FEATURE_METADATA,
    getPlan: getUKRoadLanguagePlan,
    featureAlpha: featureAlpha,
    resolveVariableLimit: resolveVariableLimit,
    projectPolygon: polygonPoints,
    draw: drawUKRoadLanguage
  });
  namespace.ukRoadLanguage = ukNamespace;

  if (namespace.world && typeof namespace.world === 'object') {
    namespace.world.ukRoadLanguageVersion = VERSION;
    namespace.world.ukRoadLanguage = ukNamespace;
  }

  globalScope.AvenraNextGenV300 = namespace;
})(typeof window !== 'undefined' ? window : (typeof globalThis !== 'undefined' ? globalThis : this));
