/*
 * AVENRÀ HYPERLANE — UK road language extension v3.3.0
 *
 * Adds the British road furniture and carriageway markings that the 3.2.0
 * overlay did not carry.  Strictly additive: it wraps drawNearField after
 * hyperlane-uk-v320.js has attached its own wrapper, and leaves the compiled
 * road, signs, gantries, services and traffic renderer untouched.
 *
 * Everything is deterministic from route, world distance and run seed, so a
 * Weekly Works seed lays out identical furniture at every frame rate.  No
 * gameplay state is read for anything other than position, and none is
 * mutated: these are markings and signs, not obstacles.
 *
 * New to this module:
 *
 *   Motorway  countdown markers, driver location signs, marker posts,
 *             emergency refuge areas, average-speed camera pairs,
 *             merge chevron hatching
 *   City      crossing zig-zags, bus lane, mini roundabout,
 *             advanced stop line, anti-skid junction approach, Gatso camera
 *   Rural     centre hatching / ghost island, passing places,
 *             national speed limit derestriction, level crossing,
 *             worn edge of carriageway
 *
 * Deliberately NOT duplicated, because the compiled renderer or the v3.2.0
 * overlay already owns them: SLOW legends, cat's eyes, red/white/amber studs,
 * direction signs, standard gantries, service buildings, variable speed
 * limits, double yellows, box junctions, zebra crossings, Belisha beacons,
 * green slip studs, reflective posts, cattle grids, Buttertubs chevrons.
 */
(function attachAvenraUkLanguageV330(globalScope) {
  'use strict';

  if (!globalScope) return;

  var namespace = globalScope.AvenraNextGenV300;
  if (!namespace || typeof namespace !== 'object') return;

  var VERSION = '3.3.0';
  var TAU = Math.PI * 2;
  var VALID_ROUTES = { city: true, rural: true, motorway: true };

  var FEATURE_LIMITS = { city: 205, rural: 210, motorway: 235 };

  /* ------------------------------------------------------------------ *
   * Helpers, matched to the existing modules so numeric behaviour agrees.
   * ------------------------------------------------------------------ */

  function finite(value, fallback) {
    var number;
    try { number = Number(value); } catch (error) { return fallback; }
    return Number.isFinite(number) ? number : fallback;
  }

  function clamp(value, minimum, maximum) {
    return Math.max(minimum, Math.min(maximum, value));
  }

  function mix(a, b, amount) { return a + (b - a) * amount; }

  function smoothstep(edge0, edge1, value) {
    if (!(edge1 > edge0)) return value >= edge1 ? 1 : 0;
    var unit = clamp((value - edge0) / (edge1 - edge0), 0, 1);
    return unit * unit * (3 - 2 * unit);
  }

  function freeze(value) {
    try { return Object.freeze(value); } catch (error) { return value; }
  }

  function hashUnit(seed, salt) {
    var value = (finite(seed, 0) * 2654435761 + finite(salt, 0) * 40503) >>> 0;
    value ^= value >>> 15;
    value = Math.imul(value, 2246822507) >>> 0;
    value ^= value >>> 13;
    return (value >>> 0) / 4294967296;
  }

  function rgba(colour, alpha) {
    return 'rgba(' + Math.round(colour[0]) + ',' + Math.round(colour[1]) + ',' +
      Math.round(colour[2]) + ',' + clamp(alpha, 0, 1).toFixed(3) + ')';
  }

  function normaliseRoute(value) {
    var route = String(value || '').toLowerCase();
    if (route === 'm1' || route === 'highway') route = 'motorway';
    if (route === 'district' || route === 'urban') route = 'city';
    if (route === 'country' || route === 'countryside') route = 'rural';
    return VALID_ROUTES[route] ? route : 'city';
  }

  function normaliseTier(value) {
    var tier = String(value || '').toLowerCase();
    if (tier === 'auto') return 'enhanced';
    if (tier === 'low' || tier === 'performance') return 'smooth';
    if (tier === 'high') return 'ultra';
    return tier === 'smooth' || tier === 'enhanced' || tier === 'ultra' || tier === 'cinematic' ?
      tier : 'enhanced';
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
    var routeId = normaliseRoute(options.routeId || options.route || state.routeId || state.route);
    return {
      projector: projector,
      state: state,
      options: options,
      routeId: routeId,
      routeStage: String(state.routeStage || options.routeStage || '').toLowerCase(),
      tier: normaliseTier(options.tier || options.quality || state.graphicsQuality),
      density: finite(options.density, 1),
      worldDistance: Math.max(0, finite(
        state.worldDistance != null ? state.worldDistance : state.distance,
        finite(options.worldDistance, 0)
      )),
      elapsed: finite(state.elapsed, 0),
      seed: finite(state.trafficSeed, finite(state.runSeed, finite(state.weeklySeed, 20260901))),
      roadHalfWidth: clamp(finite(
        options.roadHalfWidth,
        finite(state.roadHalfWidth, routeId === 'motorway' ? 5.4 : routeId === 'city' ? 4.2 : 3.8)
      ), 2.6, 8.5),
      timeOfDay: String(state.timeOfDay || options.timeOfDay || 'day').toLowerCase(),
      weather: String(state.weather || options.weather || 'clear').toLowerCase()
    };
  }

  function isTunnel(config) {
    return config.routeId === 'city' && config.routeStage === 'tunnel';
  }

  /* ------------------------------------------------------------------ *
   * Projection and drawing primitives.
   * ------------------------------------------------------------------ */

  function projectSafe(projector, roadX, height, distance) {
    try {
      var point = projector(roadX, height, distance);
      if (!point) return null;
      var x = finite(point.x, NaN);
      var y = finite(point.y, NaN);
      if (!Number.isFinite(x) || !Number.isFinite(y)) return null;
      return { x: x, y: y };
    } catch (error) {
      return null;
    }
  }

  function polygonPoints(projector, points) {
    var projected = [];
    for (var index = 0; index < points.length; index += 1) {
      var point = points[index];
      var screen = projectSafe(projector, point.x, finite(point.h, 0.014), point.z);
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

  function strokePolygon(ctx, points, close) {
    if (!points || points.length < 2) return false;
    ctx.beginPath();
    ctx.moveTo(points[0].x, points[0].y);
    for (var index = 1; index < points.length; index += 1) ctx.lineTo(points[index].x, points[index].y);
    if (close) ctx.closePath();
    ctx.stroke();
    return true;
  }

  /** A flat rectangle painted on the carriageway between two distances. */
  function roadPatch(ctx, config, xLeft, xRight, zNear, zFar, colour, alpha) {
    if (!(zNear > 1.1) && !(zFar > 1.1)) return 0;
    var points = polygonPoints(config.projector, [
      { x: xLeft, z: zFar }, { x: xRight, z: zFar },
      { x: xRight, z: zNear }, { x: xLeft, z: zNear }
    ]);
    if (!points) return 0;
    ctx.fillStyle = rgba(colour, alpha);
    return fillPolygon(ctx, points) ? 1 : 0;
  }

  /** A vertical panel standing at the roadside, with an optional post. */
  function roadsidePanel(ctx, config, roadX, distance, halfWidth, baseHeight, topHeight, faceColour, alpha, postHeight) {
    var face = polygonPoints(config.projector, [
      { x: roadX - halfWidth, h: topHeight, z: distance },
      { x: roadX + halfWidth, h: topHeight, z: distance },
      { x: roadX + halfWidth, h: baseHeight, z: distance },
      { x: roadX - halfWidth, h: baseHeight, z: distance }
    ]);
    if (!face) return null;
    if (postHeight > 0) {
      var post = polygonPoints(config.projector, [
        { x: roadX - 0.045, h: baseHeight, z: distance },
        { x: roadX + 0.045, h: baseHeight, z: distance },
        { x: roadX + 0.045, h: 0, z: distance },
        { x: roadX - 0.045, h: 0, z: distance }
      ]);
      if (post) {
        ctx.fillStyle = rgba([132, 138, 142], alpha * 0.85);
        fillPolygon(ctx, post);
      }
    }
    ctx.fillStyle = rgba(faceColour, alpha);
    fillPolygon(ctx, face);
    return face;
  }

  /**
   * Sign lettering, measured against the sign's projected width so distant
   * text stays inside the panel instead of escaping it.  Below the
   * legibility floor nothing is drawn at all rather than drawing mush.
   */
  function panelText(ctx, face, text, colour, alpha, fill) {
    if (!face || face.length < 4) return 0;
    var width = Math.abs(face[1].x - face[0].x);
    var height = Math.abs(face[0].y - face[3].y);
    if (!(width > 13) || !(height > 6)) return 0;
    var size = Math.min(height * 0.62, width / Math.max(1, text.length) * 1.55);
    if (size < 5.5) return 0;
    ctx.save();
    try {
      ctx.font = '700 ' + size.toFixed(1) + 'px Arial, Helvetica, sans-serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      var measured = ctx.measureText(text).width;
      if (measured > width * 0.9) {
        size *= (width * 0.9) / measured;
        if (size < 5.5) return 0;
        ctx.font = '700 ' + size.toFixed(1) + 'px Arial, Helvetica, sans-serif';
      }
      var centreX = (face[0].x + face[1].x) * 0.5;
      var centreY = (face[0].y + face[3].y) * 0.5;
      ctx.fillStyle = rgba(colour, alpha);
      ctx.fillText(text, centreX, centreY);
    } finally {
      ctx.restore();
    }
    return 1;
  }

  /* ------------------------------------------------------------------ *
   * Visibility.
   * ------------------------------------------------------------------ */

  function featureAlpha(distance, config) {
    var weather = config.weather;
    var far = weather === 'fog' ? 92 : weather === 'storm' ? 126 :
      weather === 'rain' ? 166 : weather === 'post-rain' ? 190 : 225;
    if (config.timeOfDay === 'night') far *= 0.88;
    var nearFade = smoothstep(1.2, 4.2, distance);
    var farFade = 1 - smoothstep(far * 0.67, far, distance);
    return clamp(nearFade * farFade, 0, 1);
  }

  /** Paint wears and dulls in the wet; retroreflective signs do not. */
  function markingAlpha(config) {
    var weather = config.weather;
    var alpha = weather === 'storm' ? 0.66 : weather === 'rain' ? 0.73 :
      weather === 'fog' ? 0.71 : weather === 'post-rain' ? 0.78 : 0.86;
    if (config.timeOfDay === 'night') alpha *= 0.9;
    return alpha;
  }

  function signAlpha(config) {
    // Retroreflective sheeting actually reads brighter at night in a beam.
    return config.timeOfDay === 'night' ? 0.98 : 0.94;
  }

  function anchorSeries(worldDistance, maximumDistance, interval, seed, salt) {
    var phase = hashUnit(seed, salt) * interval;
    var firstCell = Math.floor((worldDistance + 2 - phase) / interval) - 1;
    var lastCell = Math.ceil((worldDistance + maximumDistance - phase) / interval) + 1;
    var anchors = [];
    for (var cell = firstCell; cell <= lastCell; cell += 1) {
      var absolute = cell * interval + phase;
      var relative = absolute - worldDistance;
      if (absolute >= 0 && relative >= -34 && relative <= maximumDistance + 34) {
        anchors.push({ absolute: absolute, relative: relative, cell: cell });
      }
    }
    return anchors;
  }

  var MARKING_WHITE = [238, 242, 244];
  var MARKING_YELLOW = [226, 188, 62];
  var SIGN_BLUE = [22, 62, 126];
  var SIGN_WHITE = [240, 244, 246];
  var SIGN_BLACK = [24, 26, 28];
  var SIGN_RED = [186, 32, 40];
  var CAMERA_YELLOW = [232, 186, 30];
  var SURFACE_RED = [126, 52, 44];
  var SURFACE_ORANGE = [158, 84, 38];
  var POST_GREY = [138, 144, 148];

  /* ------------------------------------------------------------------ *
   * MOTORWAY
   * ------------------------------------------------------------------ */

  /**
   * Countdown markers: the 300, 200 and 100 yard bars before an exit.
   * Three bars, then two, then one — blue panels with white diagonals.
   */
  function drawCountdownMarker(ctx, config, feature) {
    var alpha = featureAlpha(feature.distance, config) * signAlpha(config);
    if (alpha < 0.06) return 0;
    var edge = config.roadHalfWidth + 2.3;
    var face = roadsidePanel(ctx, config, edge, feature.distance, 0.52, 0.95, 1.85, SIGN_BLUE, alpha, 1);
    if (!face) return 0;

    // White diagonal bars, one per hundred yards remaining.
    var width = Math.abs(face[1].x - face[0].x);
    var height = Math.abs(face[0].y - face[3].y);
    if (width < 4 || height < 4) return 1;
    ctx.save();
    try {
      ctx.beginPath();
      ctx.moveTo(face[0].x, face[0].y);
      ctx.lineTo(face[1].x, face[1].y);
      ctx.lineTo(face[2].x, face[2].y);
      ctx.lineTo(face[3].x, face[3].y);
      ctx.closePath();
      ctx.clip();
      ctx.strokeStyle = rgba(SIGN_WHITE, alpha);
      ctx.lineWidth = Math.max(1, width * 0.16);
      ctx.lineCap = 'butt';
      var left = Math.min(face[0].x, face[3].x);
      var top = Math.min(face[0].y, face[1].y);
      for (var bar = 0; bar < feature.bars; bar += 1) {
        var slot = (bar + 0.5) / feature.bars;
        var x = left + width * slot;
        ctx.beginPath();
        ctx.moveTo(x - width * 0.1, top + height * 0.86);
        ctx.lineTo(x + width * 0.1, top + height * 0.14);
        ctx.stroke();
      }
    } finally {
      ctx.restore();
    }
    return 1;
  }

  /**
   * Driver location sign: the small blue plate carrying the road number,
   * carriageway letter and distance in kilometres.  A hundred metres of
   * British motorway is unmistakable because of these.
   */
  function drawDriverLocationSign(ctx, config, feature) {
    var alpha = featureAlpha(feature.distance, config) * signAlpha(config);
    if (alpha < 0.06) return 0;
    var edge = -(config.roadHalfWidth + 1.9);
    var face = roadsidePanel(ctx, config, edge, feature.distance, 0.34, 0.62, 1.4, SIGN_BLUE, alpha, 1);
    if (!face) return 0;
    var width = Math.abs(face[1].x - face[0].x);
    if (width > 15) {
      var top = { x: face[0].x, y: face[0].y };
      var third = Math.abs(face[0].y - face[3].y) / 3;
      // Three stacked lines: road, carriageway, kilometres.
      panelText(ctx, [
        { x: face[0].x, y: top.y }, { x: face[1].x, y: top.y },
        { x: face[1].x, y: top.y + third }, { x: face[0].x, y: top.y + third }
      ], 'M1', SIGN_WHITE, alpha);
      panelText(ctx, [
        { x: face[0].x, y: top.y + third }, { x: face[1].x, y: top.y + third },
        { x: face[1].x, y: top.y + third * 2 }, { x: face[0].x, y: top.y + third * 2 }
      ], feature.carriageway, SIGN_WHITE, alpha);
      panelText(ctx, [
        { x: face[0].x, y: top.y + third * 2 }, { x: face[1].x, y: top.y + third * 2 },
        { x: face[1].x, y: top.y + third * 3 }, { x: face[0].x, y: top.y + third * 3 }
      ], feature.kilometres, SIGN_WHITE, alpha);
    }
    return 1;
  }

  /** Marker post: the small reflective post with an arrow to the nearest phone. */
  function drawMarkerPost(ctx, config, feature) {
    var alpha = featureAlpha(feature.distance, config) * markingAlpha(config);
    if (alpha < 0.05) return 0;
    var edge = (config.roadHalfWidth + 1.35) * feature.side;
    var post = polygonPoints(config.projector, [
      { x: edge - 0.06, h: 1.05, z: feature.distance },
      { x: edge + 0.06, h: 1.05, z: feature.distance },
      { x: edge + 0.06, h: 0, z: feature.distance },
      { x: edge - 0.06, h: 0, z: feature.distance }
    ]);
    if (!post) return 0;
    ctx.fillStyle = rgba(POST_GREY, alpha * 0.8);
    fillPolygon(ctx, post);
    var plate = polygonPoints(config.projector, [
      { x: edge - 0.1, h: 1.02, z: feature.distance },
      { x: edge + 0.1, h: 1.02, z: feature.distance },
      { x: edge + 0.1, h: 0.82, z: feature.distance },
      { x: edge - 0.1, h: 0.82, z: feature.distance }
    ]);
    if (plate) {
      ctx.fillStyle = rgba(SIGN_BLUE, alpha);
      fillPolygon(ctx, plate);
    }
    return 1;
  }

  /**
   * Emergency refuge area: orange surfacing, white bounding line and an SOS
   * marker.  Smart motorway kit, and instantly readable as British.
   */
  function drawEmergencyRefuge(ctx, config, feature) {
    var alpha = featureAlpha(feature.distance, config) * markingAlpha(config);
    if (alpha < 0.06) return 0;
    var inner = config.roadHalfWidth + 0.15;
    var outer = config.roadHalfWidth + 3.3;
    var zNear = feature.distance;
    var zFar = feature.distance + feature.length;
    var drawn = 0;

    drawn += roadPatch(ctx, config, inner, outer, zNear, zFar, SURFACE_ORANGE, alpha * 0.72);

    // Tapered entry and exit rather than a rectangle dropped on the verge.
    var entry = polygonPoints(config.projector, [
      { x: inner, z: zNear - 16 }, { x: outer, z: zNear },
      { x: outer, z: zNear + 2 }, { x: inner, z: zNear - 14 }
    ]);
    if (entry) {
      ctx.fillStyle = rgba(MARKING_WHITE, alpha * 0.7);
      fillPolygon(ctx, entry);
      drawn += 1;
    }
    var exit = polygonPoints(config.projector, [
      { x: outer, z: zFar }, { x: inner, z: zFar + 16 },
      { x: inner, z: zFar + 14 }, { x: outer, z: zFar - 2 }
    ]);
    if (exit) {
      ctx.fillStyle = rgba(MARKING_WHITE, alpha * 0.7);
      fillPolygon(ctx, exit);
      drawn += 1;
    }

    var face = roadsidePanel(ctx, config, outer + 0.9, zNear + feature.length * 0.5, 0.6, 1.1, 2.2, SIGN_BLUE, alpha, 1);
    if (face) {
      panelText(ctx, face, 'SOS', SIGN_WHITE, alpha);
      drawn += 1;
    }
    return drawn;
  }

  /**
   * Average-speed camera: the yellow housing on a gantry leg or its own
   * column, in pairs.  Drawn without any effect on scoring.
   */
  function drawSpeedCamera(ctx, config, feature) {
    var alpha = featureAlpha(feature.distance, config) * signAlpha(config);
    if (alpha < 0.06) return 0;
    var edge = (config.roadHalfWidth + feature.offset) * feature.side;
    var height = feature.height;

    var column = polygonPoints(config.projector, [
      { x: edge - 0.08, h: height, z: feature.distance },
      { x: edge + 0.08, h: height, z: feature.distance },
      { x: edge + 0.08, h: 0, z: feature.distance },
      { x: edge - 0.08, h: 0, z: feature.distance }
    ]);
    if (!column) return 0;
    ctx.fillStyle = rgba(POST_GREY, alpha * 0.86);
    fillPolygon(ctx, column);

    var housing = polygonPoints(config.projector, [
      { x: edge - 0.34, h: height + 0.44, z: feature.distance },
      { x: edge + 0.34, h: height + 0.44, z: feature.distance },
      { x: edge + 0.34, h: height - 0.06, z: feature.distance },
      { x: edge - 0.34, h: height - 0.06, z: feature.distance }
    ]);
    if (housing) {
      ctx.fillStyle = rgba(CAMERA_YELLOW, alpha);
      fillPolygon(ctx, housing);
      var lens = polygonPoints(config.projector, [
        { x: edge - 0.12, h: height + 0.3, z: feature.distance - 0.05 },
        { x: edge + 0.12, h: height + 0.3, z: feature.distance - 0.05 },
        { x: edge + 0.12, h: height + 0.08, z: feature.distance - 0.05 },
        { x: edge - 0.12, h: height + 0.08, z: feature.distance - 0.05 }
      ]);
      if (lens) {
        ctx.fillStyle = rgba([28, 30, 34], alpha);
        fillPolygon(ctx, lens);
      }
    }
    return 1;
  }

  /**
   * Chevron hatching in the nose of a merge, bounded by a solid white line.
   * Traffic may cross it, but it should look like somewhere you do not go.
   */
  function drawMergeChevrons(ctx, config, feature) {
    var alpha = featureAlpha(feature.distance, config) * markingAlpha(config);
    if (alpha < 0.06) return 0;
    var side = feature.side;
    var innerX = config.roadHalfWidth * side * 0.98;
    var drawn = 0;

    ctx.save();
    try {
      ctx.strokeStyle = rgba(MARKING_WHITE, alpha * 0.9);
      ctx.lineWidth = Math.max(1, 2.4 * clamp(30 / Math.max(6, feature.distance), 0.2, 3));
      ctx.lineCap = 'round';
      var count = 6;
      for (var index = 0; index < count; index += 1) {
        var unit = index / count;
        var z = feature.distance + feature.length * unit;
        var spread = mix(0.35, 2.5, unit);
        var chevron = polygonPoints(config.projector, [
          { x: innerX - spread * side, z: z },
          { x: innerX, z: z + 2.6 },
          { x: innerX - spread * side, z: z + 5.2 }
        ]);
        if (!chevron) continue;
        strokePolygon(ctx, chevron, false);
        drawn += 1;
      }
      // The solid edge line that bounds the hatched area.
      var bound = polygonPoints(config.projector, [
        { x: innerX, z: feature.distance },
        { x: innerX, z: feature.distance + feature.length }
      ]);
      if (bound) {
        ctx.lineWidth = Math.max(1, 3 * clamp(30 / Math.max(6, feature.distance), 0.2, 3));
        strokePolygon(ctx, bound, false);
        drawn += 1;
      }
    } finally {
      ctx.restore();
    }
    return drawn;
  }

  /* ------------------------------------------------------------------ *
   * CITY
   * ------------------------------------------------------------------ */

  /**
   * The zig-zag approach markings either side of a pedestrian crossing.
   * These are the reason a British crossing is recognisable at a glance.
   */
  function drawCrossingZigZags(ctx, config, feature) {
    var alpha = featureAlpha(feature.distance, config) * markingAlpha(config);
    if (alpha < 0.06) return 0;
    var halfWidth = config.roadHalfWidth;
    var drawn = 0;

    ctx.save();
    try {
      ctx.strokeStyle = rgba(MARKING_WHITE, alpha * 0.88);
      ctx.lineCap = 'butt';
      ctx.lineWidth = Math.max(1, 2.2 * clamp(28 / Math.max(6, feature.distance), 0.2, 3));

      // Eight zig-zag segments on the approach and four beyond, both sides.
      for (var side = -1; side <= 1; side += 2) {
        var edgeX = side * (halfWidth - 0.4);
        var innerX = side * (halfWidth - 1.35);
        var points = [];
        for (var step = 0; step <= 11; step += 1) {
          var z = feature.distance - 18 + step * 2.6;
          points.push({ x: step % 2 === 0 ? edgeX : innerX, z: z });
        }
        var projected = polygonPoints(config.projector, points);
        if (!projected) continue;
        strokePolygon(ctx, projected, false);
        drawn += 1;
      }
    } finally {
      ctx.restore();
    }
    return drawn;
  }

  /** Bus lane: solid bound line, red-tinted surface and a BUS LANE legend. */
  function drawBusLane(ctx, config, feature) {
    var alpha = featureAlpha(feature.distance, config) * markingAlpha(config);
    if (alpha < 0.06) return 0;
    var outer = -(config.roadHalfWidth - 0.25);
    var inner = -(config.roadHalfWidth - 3.4);
    var zNear = feature.distance;
    var zFar = feature.distance + feature.length;
    var drawn = 0;

    drawn += roadPatch(ctx, config, outer, inner, zNear, zFar, SURFACE_RED, alpha * 0.4);

    ctx.save();
    try {
      ctx.strokeStyle = rgba(MARKING_WHITE, alpha * 0.9);
      ctx.lineWidth = Math.max(1, 3.4 * clamp(28 / Math.max(6, feature.distance), 0.2, 3));
      var bound = polygonPoints(config.projector, [
        { x: inner, z: zNear }, { x: inner, z: zFar }
      ]);
      if (bound) { strokePolygon(ctx, bound, false); drawn += 1; }
    } finally {
      ctx.restore();
    }

    // Legend on the carriageway, only where it would actually be legible.
    if (feature.distance > 14 && feature.distance < 68) {
      var legend = polygonPoints(config.projector, [
        { x: outer + 0.5, z: zNear + 10 }, { x: inner - 0.5, z: zNear + 10 },
        { x: inner - 0.5, z: zNear + 3 }, { x: outer + 0.5, z: zNear + 3 }
      ]);
      if (legend) {
        panelText(ctx, legend, 'BUS LANE', MARKING_WHITE, alpha * 0.85);
        drawn += 1;
      }
    }
    return drawn;
  }

  /** Mini roundabout: the painted dome with three approach arrows. */
  function drawMiniRoundabout(ctx, config, feature) {
    var alpha = featureAlpha(feature.distance, config) * markingAlpha(config);
    if (alpha < 0.06) return 0;
    var centre = projectSafe(config.projector, 0, 0.014, feature.distance);
    var edge = projectSafe(config.projector, 2.1, 0.014, feature.distance);
    if (!centre || !edge) return 0;
    var radius = Math.abs(edge.x - centre.x);
    if (!(radius > 1.4)) return 0;

    ctx.save();
    try {
      ctx.fillStyle = rgba(MARKING_WHITE, alpha * 0.82);
      ctx.beginPath();
      ctx.ellipse(centre.x, centre.y, radius, radius * 0.3, 0, 0, TAU);
      ctx.fill();
      // Give-way triangles on the approach.
      ctx.fillStyle = rgba(MARKING_WHITE, alpha * 0.7);
      for (var index = 0; index < 5; index += 1) {
        var offsetX = mix(-config.roadHalfWidth * 0.7, config.roadHalfWidth * 0.7, index / 4);
        var triangle = polygonPoints(config.projector, [
          { x: offsetX - 0.28, z: feature.distance - 7 },
          { x: offsetX + 0.28, z: feature.distance - 7 },
          { x: offsetX, z: feature.distance - 5.6 }
        ]);
        if (triangle) fillPolygon(ctx, triangle);
      }
    } catch (error) {
      return 0;
    } finally {
      ctx.restore();
    }
    return 1;
  }

  /** Advanced stop line: the cycle box ahead of the stop line at signals. */
  function drawAdvancedStopLine(ctx, config, feature) {
    var alpha = featureAlpha(feature.distance, config) * markingAlpha(config);
    if (alpha < 0.06) return 0;
    var left = -(config.roadHalfWidth - 0.5);
    var right = -0.2;
    var drawn = 0;

    drawn += roadPatch(ctx, config, left, right, feature.distance, feature.distance + 5, SURFACE_RED, alpha * 0.42);

    ctx.save();
    try {
      ctx.strokeStyle = rgba(MARKING_WHITE, alpha * 0.92);
      ctx.lineWidth = Math.max(1.5, 5 * clamp(28 / Math.max(6, feature.distance), 0.2, 3));
      var stopLine = polygonPoints(config.projector, [
        { x: left, z: feature.distance }, { x: right, z: feature.distance }
      ]);
      if (stopLine) { strokePolygon(ctx, stopLine, false); drawn += 1; }
      var advanced = polygonPoints(config.projector, [
        { x: left, z: feature.distance + 5 }, { x: right, z: feature.distance + 5 }
      ]);
      if (advanced) { strokePolygon(ctx, advanced, false); drawn += 1; }
    } finally {
      ctx.restore();
    }
    return drawn;
  }

  /** High-friction red surfacing on a junction approach. */
  function drawAntiSkid(ctx, config, feature) {
    var alpha = featureAlpha(feature.distance, config) * markingAlpha(config);
    if (alpha < 0.05) return 0;
    return roadPatch(
      ctx, config,
      -(config.roadHalfWidth - 0.4), -0.15,
      feature.distance, feature.distance + feature.length,
      SURFACE_RED, alpha * 0.34
    );
  }

  /** Gatso: the yellow box camera on its own column, with road-side lines. */
  function drawGatso(ctx, config, feature) {
    var alpha = featureAlpha(feature.distance, config) * signAlpha(config);
    if (alpha < 0.06) return 0;
    var drawn = drawSpeedCamera(ctx, config, {
      distance: feature.distance, side: feature.side, offset: 1.3, height: 2.9
    });
    // Calibration lines painted across the lane behind the camera.
    if (feature.distance > 8 && feature.distance < 80) {
      ctx.save();
      try {
        ctx.strokeStyle = rgba(MARKING_WHITE, alpha * 0.5);
        ctx.lineWidth = Math.max(1, 1.8 * clamp(28 / Math.max(6, feature.distance), 0.2, 3));
        for (var index = 0; index < 5; index += 1) {
          var line = polygonPoints(config.projector, [
            { x: -(config.roadHalfWidth - 0.5), z: feature.distance - 12 + index * 2.2 },
            { x: -0.4, z: feature.distance - 12 + index * 2.2 }
          ]);
          if (line) { strokePolygon(ctx, line, false); drawn += 1; }
        }
      } finally {
        ctx.restore();
      }
    }
    return drawn;
  }

  /* ------------------------------------------------------------------ *
   * RURAL
   * ------------------------------------------------------------------ */

  /**
   * Centre hatching, widening into a ghost island.  On a Dales A-road this
   * is what separates the fast bits from the bits where you behave.
   */
  function drawCentreHatching(ctx, config, feature) {
    var alpha = featureAlpha(feature.distance, config) * markingAlpha(config);
    if (alpha < 0.06) return 0;
    var drawn = 0;

    ctx.save();
    try {
      ctx.strokeStyle = rgba(MARKING_WHITE, alpha * 0.82);
      ctx.lineWidth = Math.max(1, 2 * clamp(28 / Math.max(6, feature.distance), 0.2, 3));
      ctx.lineCap = 'round';

      var steps = 7;
      for (var index = 0; index < steps; index += 1) {
        var unit = index / steps;
        // Widen to the middle, then taper back: a ghost island profile.
        var half = mix(0.18, feature.maximumHalfWidth, Math.sin(unit * Math.PI));
        var z = feature.distance + feature.length * unit;
        var stripe = polygonPoints(config.projector, [
          { x: -half, z: z }, { x: half, z: z + 2.8 }
        ]);
        if (stripe) { strokePolygon(ctx, stripe, false); drawn += 1; }
      }

      // Bounding lines either side of the hatched area.
      for (var side = -1; side <= 1; side += 2) {
        var bound = [];
        for (var step = 0; step <= 7; step += 1) {
          var u = step / 7;
          bound.push({
            x: side * mix(0.18, feature.maximumHalfWidth, Math.sin(u * Math.PI)),
            z: feature.distance + feature.length * u
          });
        }
        var projected = polygonPoints(config.projector, bound);
        if (projected) { strokePolygon(ctx, projected, false); drawn += 1; }
      }
    } finally {
      ctx.restore();
    }
    return drawn;
  }

  /** Passing place: a short widening with the square white sign. */
  function drawPassingPlace(ctx, config, feature) {
    var alpha = featureAlpha(feature.distance, config) * markingAlpha(config);
    if (alpha < 0.06) return 0;
    var side = feature.side;
    var inner = config.roadHalfWidth * side;
    var outer = (config.roadHalfWidth + 2.4) * side;
    var drawn = roadPatch(
      ctx, config,
      Math.min(inner, outer), Math.max(inner, outer),
      feature.distance, feature.distance + 11,
      [78, 74, 68], alpha * 0.55
    );
    var face = roadsidePanel(
      ctx, config, outer + side * 0.7, feature.distance + 5,
      0.36, 1.1, 1.82, SIGN_WHITE, alpha * signAlpha(config), 1
    );
    if (face) {
      panelText(ctx, face, 'P', SIGN_BLACK, alpha);
      drawn += 1;
    }
    return drawn;
  }

  /** National speed limit: white disc with a black diagonal. */
  function drawNationalSpeedLimit(ctx, config, feature) {
    var alpha = featureAlpha(feature.distance, config) * signAlpha(config);
    if (alpha < 0.06) return 0;
    var edge = (config.roadHalfWidth + 1.5) * feature.side;
    var centre = projectSafe(config.projector, edge, 1.85, feature.distance);
    var rim = projectSafe(config.projector, edge + 0.32, 1.85, feature.distance);
    if (!centre || !rim) return 0;
    var radius = Math.abs(rim.x - centre.x);
    if (!(radius > 1.6)) return 0;

    var post = polygonPoints(config.projector, [
      { x: edge - 0.05, h: 1.6, z: feature.distance },
      { x: edge + 0.05, h: 1.6, z: feature.distance },
      { x: edge + 0.05, h: 0, z: feature.distance },
      { x: edge - 0.05, h: 0, z: feature.distance }
    ]);
    if (post) {
      ctx.fillStyle = rgba(POST_GREY, alpha * 0.85);
      fillPolygon(ctx, post);
    }

    ctx.save();
    try {
      ctx.fillStyle = rgba(SIGN_WHITE, alpha);
      ctx.beginPath();
      ctx.arc(centre.x, centre.y, radius, 0, TAU);
      ctx.fill();
      ctx.strokeStyle = rgba(SIGN_BLACK, alpha * 0.9);
      ctx.lineWidth = Math.max(1, radius * 0.17);
      ctx.beginPath();
      ctx.arc(centre.x, centre.y, radius * 0.86, 0, TAU);
      ctx.stroke();
      ctx.beginPath();
      ctx.moveTo(centre.x - radius * 0.58, centre.y + radius * 0.58);
      ctx.lineTo(centre.x + radius * 0.58, centre.y - radius * 0.58);
      ctx.stroke();
    } catch (error) {
      return 0;
    } finally {
      ctx.restore();
    }
    return 1;
  }

  /** Level crossing: stop line, warning box and the flanking posts. */
  function drawLevelCrossing(ctx, config, feature) {
    var alpha = featureAlpha(feature.distance, config) * markingAlpha(config);
    if (alpha < 0.06) return 0;
    var half = config.roadHalfWidth;
    var drawn = 0;

    ctx.save();
    try {
      ctx.strokeStyle = rgba(MARKING_WHITE, alpha * 0.9);
      ctx.lineWidth = Math.max(1.4, 4.4 * clamp(28 / Math.max(6, feature.distance), 0.2, 3));
      var stop = polygonPoints(config.projector, [
        { x: -half + 0.3, z: feature.distance }, { x: -0.2, z: feature.distance }
      ]);
      if (stop) { strokePolygon(ctx, stop, false); drawn += 1; }
    } finally {
      ctx.restore();
    }

    // The rails themselves, a couple of metres beyond the stop line.
    drawn += roadPatch(ctx, config, -half, half, feature.distance + 3.4, feature.distance + 3.9, [64, 58, 52], alpha * 0.7);
    drawn += roadPatch(ctx, config, -half, half, feature.distance + 5.0, feature.distance + 5.5, [64, 58, 52], alpha * 0.7);

    for (var side = -1; side <= 1; side += 2) {
      var face = roadsidePanel(
        ctx, config, (half + 1.1) * side, feature.distance - 1,
        0.42, 1.3, 2.15, SIGN_WHITE, alpha * signAlpha(config), 1
      );
      if (face) {
        panelText(ctx, face, 'X', SIGN_RED, alpha);
        drawn += 1;
      }
    }
    return drawn;
  }

  /** Worn, broken-up edge of carriageway with grass encroachment. */
  function drawWornEdge(ctx, config, feature) {
    var alpha = featureAlpha(feature.distance, config) * markingAlpha(config);
    if (alpha < 0.05) return 0;
    var side = feature.side;
    var drawn = 0;
    for (var index = 0; index < 5; index += 1) {
      var z = feature.distance + index * 3.4;
      var bite = mix(0.12, 0.55, hashUnit(feature.cell, index * 37));
      var patch = polygonPoints(config.projector, [
        { x: config.roadHalfWidth * side, z: z },
        { x: (config.roadHalfWidth - bite) * side, z: z },
        { x: (config.roadHalfWidth - bite * 0.5) * side, z: z + 3 },
        { x: config.roadHalfWidth * side, z: z + 3 }
      ]);
      if (!patch) continue;
      ctx.fillStyle = rgba([58, 62, 44], alpha * 0.45);
      fillPolygon(ctx, patch);
      drawn += 1;
    }
    return drawn;
  }

  /* ------------------------------------------------------------------ *
   * Planning.
   * ------------------------------------------------------------------ */

  function addFeature(features, feature, tolerance) {
    tolerance = finite(tolerance, 6);
    for (var index = 0; index < features.length; index += 1) {
      var existing = features[index];
      if (existing.type === feature.type && Math.abs(existing.absolute - feature.absolute) < tolerance) return false;
    }
    features.push(feature);
    return true;
  }

  function planMotorway(config, features, maximumDistance) {
    var seed = config.seed;

    // Junctions roughly every 900 m.  Countdown markers sit up to 300 yards
    // (274 m) BEFORE the junction, so we have to look past the draw limit for
    // junctions whose markers are already in view.
    var junctionLookahead = maximumDistance + 320;
    var junctions = anchorSeries(config.worldDistance, junctionLookahead, 900, seed, 5);
    for (var j = 0; j < junctions.length; j += 1) {
      var junction = junctions[j];
      for (var bar = 3; bar >= 1; bar -= 1) {
        var offset = bar * 91.44; // 100 yards, in metres.
        var relative = junction.relative - offset;
        if (relative < -20 || relative > maximumDistance) continue;
        addFeature(features, {
          type: 'countdown-marker', absolute: junction.absolute - offset,
          distance: relative, bars: bar
        }, 12);
      }
      // Chevron hatching in the nose where the slip road leaves.
      if (junction.relative > -20 && junction.relative < maximumDistance - 30) {
        addFeature(features, {
          type: 'merge-chevrons', absolute: junction.absolute,
          distance: junction.relative, length: 34, side: -1
        }, 20);
      }
    }

    // Driver location signs every 500 m, marker posts every 100 m.
    var locations = anchorSeries(config.worldDistance, maximumDistance, 500, seed, 11);
    for (var l = 0; l < locations.length; l += 1) {
      if (locations[l].relative < 0 || locations[l].relative > maximumDistance) continue;
      addFeature(features, {
        type: 'driver-location-sign', absolute: locations[l].absolute,
        distance: locations[l].relative, carriageway: 'B',
        kilometres: (140 + locations[l].absolute / 1000).toFixed(1)
      }, 40);
    }

    var posts = anchorSeries(config.worldDistance, maximumDistance, 100, seed, 17);
    for (var p = 0; p < posts.length; p += 1) {
      if (posts[p].relative < 0 || posts[p].relative > maximumDistance) continue;
      addFeature(features, {
        type: 'marker-post', absolute: posts[p].absolute,
        distance: posts[p].relative, side: 1
      }, 20);
      addFeature(features, {
        type: 'marker-post', absolute: posts[p].absolute + 0.5,
        distance: posts[p].relative, side: -1
      }, 20);
    }

    // Emergency refuge areas roughly every 1500 m.
    var refuges = anchorSeries(config.worldDistance, maximumDistance, 1500, seed, 23);
    for (var r = 0; r < refuges.length; r += 1) {
      if (refuges[r].relative < -20 || refuges[r].relative > maximumDistance) continue;
      addFeature(features, {
        type: 'emergency-refuge', absolute: refuges[r].absolute,
        distance: refuges[r].relative, length: 30
      }, 60);
    }

    // Average-speed camera pairs, sparsely.
    var cameras = anchorSeries(config.worldDistance, maximumDistance, 1200, seed, 29);
    for (var c = 0; c < cameras.length; c += 1) {
      if (cameras[c].relative < 0 || cameras[c].relative > maximumDistance) continue;
      if (hashUnit(cameras[c].cell, 91) > 0.55) continue;
      addFeature(features, {
        type: 'speed-camera', absolute: cameras[c].absolute,
        distance: cameras[c].relative, side: -1, offset: 2.6, height: 5.4
      }, 40);
      addFeature(features, {
        type: 'speed-camera', absolute: cameras[c].absolute + 1,
        distance: cameras[c].relative, side: 1, offset: 2.6, height: 5.4
      }, 40);
    }
  }

  function planCity(config, features, maximumDistance) {
    var seed = config.seed;
    var expressway = config.routeStage === 'expressway';

    // Signal-controlled junctions every ~180 m through the District.
    var junctions = anchorSeries(config.worldDistance, maximumDistance, expressway ? 320 : 180, seed, 31);
    for (var j = 0; j < junctions.length; j += 1) {
      var junction = junctions[j];
      if (junction.relative < -12 || junction.relative > maximumDistance) continue;

      if (!expressway) {
        addFeature(features, {
          type: 'anti-skid', absolute: junction.absolute - 22,
          distance: junction.relative - 22, length: 20
        }, 30);
        addFeature(features, {
          type: 'advanced-stop-line', absolute: junction.absolute,
          distance: junction.relative
        }, 30);
      }
    }

    // Crossings: zig-zags pair with the existing zebra placement rhythm.
    var crossings = anchorSeries(config.worldDistance, maximumDistance, 240, seed, 37);
    for (var c = 0; c < crossings.length; c += 1) {
      if (crossings[c].relative < 0 || crossings[c].relative > maximumDistance) continue;
      if (expressway) continue;
      addFeature(features, {
        type: 'crossing-zigzags', absolute: crossings[c].absolute,
        distance: crossings[c].relative
      }, 40);
    }

    // Bus lane sections on the nearside.
    var lanes = anchorSeries(config.worldDistance, maximumDistance, 420, seed, 41);
    for (var b = 0; b < lanes.length; b += 1) {
      if (lanes[b].relative < -40 || lanes[b].relative > maximumDistance) continue;
      if (expressway || hashUnit(lanes[b].cell, 97) > 0.6) continue;
      addFeature(features, {
        type: 'bus-lane', absolute: lanes[b].absolute,
        distance: lanes[b].relative, length: 95
      }, 80);
    }

    // A mini roundabout now and then, District only.
    var roundabouts = anchorSeries(config.worldDistance, maximumDistance, 760, seed, 43);
    for (var m = 0; m < roundabouts.length; m += 1) {
      if (roundabouts[m].relative < 4 || roundabouts[m].relative > maximumDistance) continue;
      if (expressway || hashUnit(roundabouts[m].cell, 101) > 0.5) continue;
      addFeature(features, {
        type: 'mini-roundabout', absolute: roundabouts[m].absolute,
        distance: roundabouts[m].relative
      }, 60);
    }

    // Gatso.
    var gatsos = anchorSeries(config.worldDistance, maximumDistance, 640, seed, 47);
    for (var g = 0; g < gatsos.length; g += 1) {
      if (gatsos[g].relative < 0 || gatsos[g].relative > maximumDistance) continue;
      if (hashUnit(gatsos[g].cell, 103) > 0.45) continue;
      addFeature(features, {
        type: 'gatso', absolute: gatsos[g].absolute,
        distance: gatsos[g].relative, side: -1
      }, 60);
    }
  }

  function planRural(config, features, maximumDistance) {
    var seed = config.seed;

    // Centre hatching where the road opens out.
    var hatching = anchorSeries(config.worldDistance, maximumDistance, 540, seed, 53);
    for (var h = 0; h < hatching.length; h += 1) {
      if (hatching[h].relative < -30 || hatching[h].relative > maximumDistance) continue;
      if (hashUnit(hatching[h].cell, 107) > 0.62) continue;
      addFeature(features, {
        type: 'centre-hatching', absolute: hatching[h].absolute,
        distance: hatching[h].relative, length: 46,
        maximumHalfWidth: mix(0.5, 1.15, hashUnit(hatching[h].cell, 109))
      }, 70);
    }

    // Passing places on the narrow sections.
    var passing = anchorSeries(config.worldDistance, maximumDistance, 320, seed, 59);
    for (var p = 0; p < passing.length; p += 1) {
      if (passing[p].relative < -10 || passing[p].relative > maximumDistance) continue;
      if (hashUnit(passing[p].cell, 113) > 0.5) continue;
      addFeature(features, {
        type: 'passing-place', absolute: passing[p].absolute,
        distance: passing[p].relative,
        side: hashUnit(passing[p].cell, 127) < 0.5 ? -1 : 1
      }, 50);
    }

    // Derestriction signs where a village ends.
    var limits = anchorSeries(config.worldDistance, maximumDistance, 880, seed, 61);
    for (var n = 0; n < limits.length; n += 1) {
      if (limits[n].relative < 0 || limits[n].relative > maximumDistance) continue;
      addFeature(features, {
        type: 'national-speed-limit', absolute: limits[n].absolute,
        distance: limits[n].relative, side: -1
      }, 60);
    }

    // A level crossing, rarely.
    var crossings = anchorSeries(config.worldDistance, maximumDistance, 1900, seed, 67);
    for (var c = 0; c < crossings.length; c += 1) {
      if (crossings[c].relative < 0 || crossings[c].relative > maximumDistance) continue;
      if (hashUnit(crossings[c].cell, 131) > 0.5) continue;
      addFeature(features, {
        type: 'level-crossing', absolute: crossings[c].absolute,
        distance: crossings[c].relative
      }, 80);
    }

    // Worn edges, frequently — this is a Dales road, not a new bypass.
    var edges = anchorSeries(config.worldDistance, maximumDistance, 120, seed, 71);
    for (var e = 0; e < edges.length; e += 1) {
      if (edges[e].relative < 0 || edges[e].relative > maximumDistance) continue;
      addFeature(features, {
        type: 'worn-edge', absolute: edges[e].absolute,
        distance: edges[e].relative, cell: edges[e].cell,
        side: hashUnit(edges[e].cell, 137) < 0.5 ? -1 : 1
      }, 30);
    }
  }

  function getExtendedPlan(stateOrConfig, options) {
    var config;
    if (stateOrConfig && typeof stateOrConfig === 'object' &&
      (stateOrConfig.project || stateOrConfig.projector || stateOrConfig.state)) {
      config = unpackCall(stateOrConfig, stateOrConfig.state, options);
    } else {
      config = unpackCall(null, stateOrConfig, options);
    }

    var maximumDistance = FEATURE_LIMITS[config.routeId];
    var skipped = isTunnel(config) || config.density <= 0 || config.tier === 'smooth';
    var features = [];

    if (!skipped) {
      try {
        if (config.routeId === 'motorway') planMotorway(config, features, maximumDistance);
        else if (config.routeId === 'city') planCity(config, features, maximumDistance);
        else planRural(config, features, maximumDistance);
      } catch (error) {
        features = [];
      }
      // Furthest first, so nearer markings paint over distant ones.
      features.sort(function byDistance(a, b) { return b.distance - a.distance; });
    }

    return freeze({
      kind: 'avenra-uk-language-plan-v330',
      version: VERSION,
      routeId: config.routeId,
      routeStage: config.routeStage,
      worldDistance: config.worldDistance,
      seed: config.seed,
      tier: config.tier,
      skipped: skipped,
      features: freeze(features)
    });
  }

  /* ------------------------------------------------------------------ *
   * Drawing entry point.
   * ------------------------------------------------------------------ */

  var TIER_FEATURE_CAP = { smooth: 0, enhanced: 12, ultra: 22, cinematic: 30 };

  var DRAW_TABLE = {
    'countdown-marker': drawCountdownMarker,
    'driver-location-sign': drawDriverLocationSign,
    'marker-post': drawMarkerPost,
    'emergency-refuge': drawEmergencyRefuge,
    'speed-camera': drawSpeedCamera,
    'merge-chevrons': drawMergeChevrons,
    'crossing-zigzags': drawCrossingZigZags,
    'bus-lane': drawBusLane,
    'mini-roundabout': drawMiniRoundabout,
    'advanced-stop-line': drawAdvancedStopLine,
    'anti-skid': drawAntiSkid,
    'gatso': drawGatso,
    'centre-hatching': drawCentreHatching,
    'passing-place': drawPassingPlace,
    'national-speed-limit': drawNationalSpeedLimit,
    'level-crossing': drawLevelCrossing,
    'worn-edge': drawWornEdge
  };

  function drawExtendedRoadLanguage(ctx, projectorOrConfig, state, options) {
    var config = unpackCall(projectorOrConfig, state, options);
    if (!ctx || typeof ctx.beginPath !== 'function' || typeof config.projector !== 'function') return 0;

    var plan = config.options.ukLanguagePlanV330 &&
      config.options.ukLanguagePlanV330.kind === 'avenra-uk-language-plan-v330' ?
      config.options.ukLanguagePlanV330 :
      getExtendedPlan(Object.assign({}, config.options, { state: config.state, project: config.projector }));
    if (!plan || plan.skipped || !plan.features.length) return 0;

    var cap = TIER_FEATURE_CAP[config.tier] || TIER_FEATURE_CAP.enhanced;
    var drawn = 0;
    var painted = 0;

    ctx.save();
    try {
      ctx.globalCompositeOperation = 'source-over';
      ctx.lineJoin = 'round';
      for (var index = 0; index < plan.features.length && painted < cap; index += 1) {
        var feature = plan.features[index];
        var draw = DRAW_TABLE[feature.type];
        if (!draw) continue;
        try {
          var result = draw(ctx, config, feature);
          drawn += result;
          if (result > 0) painted += 1;
        } catch (featureError) {
          // A malformed optional feature must never interrupt the ride loop.
        }
      }
    } finally {
      ctx.globalCompositeOperation = 'source-over';
      ctx.restore();
    }
    return drawn;
  }

  /* ------------------------------------------------------------------ *
   * Metadata and wiring.
   * ------------------------------------------------------------------ */

  var FEATURE_METADATA = freeze({
    version: VERSION,
    mode: 'projected-photographic-2.5d',
    deterministicBy: freeze(['routeId', 'worldDistance', 'runSeed']),
    mutatesGameplay: false,
    consumesRunRandomness: false,
    routes: freeze({
      motorway: freeze([
        'countdown-marker', 'driver-location-sign', 'marker-post',
        'emergency-refuge', 'speed-camera', 'merge-chevrons'
      ]),
      city: freeze([
        'crossing-zigzags', 'bus-lane', 'mini-roundabout',
        'advanced-stop-line', 'anti-skid', 'gatso'
      ]),
      rural: freeze([
        'centre-hatching', 'passing-place', 'national-speed-limit',
        'level-crossing', 'worn-edge'
      ])
    }),
    roadPlaneFeatures: freeze([
      'crossing-zigzags', 'bus-lane', 'mini-roundabout', 'advanced-stop-line',
      'anti-skid', 'centre-hatching', 'worn-edge', 'merge-chevrons',
      'emergency-refuge', 'level-crossing'
    ]),
    deliberatelyNotDuplicated: freeze([
      'SLOW legends', 'cat\'s eyes', 'red/white/amber studs', 'direction signs',
      'standard gantries', 'service buildings', 'variable speed limits',
      'double yellows', 'box junctions', 'zebra crossings', 'Belisha beacons',
      'green slip studs', 'reflective posts', 'cattle grids', 'Buttertubs chevrons'
    ]),
    tierCaps: freeze(TIER_FEATURE_CAP),
    maximumDrawDistanceMetres: freeze(FEATURE_LIMITS)
  });

  // The install guard lives on the namespace rather than on the function.
  // Another module may wrap drawNearField after us, so the outermost function
  // is not a reliable place to record that we are already in the chain.
  var previousDrawNearField = namespace.drawNearField;
  var alreadyWrapped = namespace.__avenraUkLanguageV330Installed === true;

  function wrappedDrawNearField(ctx, projectorOrConfig, state, options) {
    var previousResult = 0;
    if (typeof previousDrawNearField === 'function') {
      try { previousResult = previousDrawNearField.apply(this, arguments); } catch (error) { previousResult = 0; }
    }
    var extendedResult = 0;
    try { extendedResult = drawExtendedRoadLanguage(ctx, projectorOrConfig, state, options); } catch (error) { extendedResult = 0; }
    return (typeof previousResult === 'number' ? previousResult : 0) + extendedResult;
  }

  wrappedDrawNearField.__avenraUkLanguageV330 = true;
  wrappedDrawNearField.version = VERSION;

  namespace.__avenraUkLanguageV330Installed = true;

  Object.assign(namespace, {
    ukLanguageVersion: VERSION,
    ukLanguageMetadata: FEATURE_METADATA,
    getUKLanguagePlanV330: getExtendedPlan,
    drawUKLanguageV330: drawExtendedRoadLanguage,
    drawNearField: alreadyWrapped ? previousDrawNearField : wrappedDrawNearField
  });

  var languageNamespace = namespace.ukLanguage;
  if (!languageNamespace || typeof languageNamespace !== 'object') languageNamespace = {};
  Object.assign(languageNamespace, {
    version: VERSION,
    metadata: FEATURE_METADATA,
    getPlan: getExtendedPlan,
    draw: drawExtendedRoadLanguage
  });
  namespace.ukLanguage = languageNamespace;

  if (namespace.world && typeof namespace.world === 'object') {
    namespace.world.ukLanguageVersion = VERSION;
    namespace.world.ukLanguage = languageNamespace;
  }

  globalScope.AvenraNextGenV300 = namespace;
})(typeof window !== 'undefined' ? window : (typeof globalThis !== 'undefined' ? globalThis : this));
