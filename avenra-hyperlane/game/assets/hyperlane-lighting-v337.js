/*
 * AVENRÀ HYPERLANE — restrained lighting and windscreen-water pass v3.3.7
 *
 * The established renderer pipeline remains the single owner of physical lamp
 * sprites, street/tunnel fixtures, their source-anchored halos, wet-road
 * reflections, vignette and speed streaks. This module adds only effects that
 * pipeline does not own:
 *
 *   - a soft, safety-critical rider dipped beam on every graphics tier;
 *   - restrained oncoming beam scatter on Rural and Motorway;
 *   - rain water on the rendered world, with optional Cinematic refraction.
 *
 * No gameplay state or seeded random source is changed. Both render hooks are
 * wrapped once and the module owns no animation loop, timers or DOM listeners.
 */
(function attachAvenraLightingV336(globalScope) {
  'use strict';

  if (!globalScope) return;

  var namespace = globalScope.AvenraNextGenV300;
  if (!namespace || typeof namespace !== 'object') return;
  if (namespace.__avenraLightingV337Installed === true) return;

  var VERSION = '3.3.7';
  var TAU = Math.PI * 2;
  var VALID_ROUTES = { city: true, rural: true, motorway: true };
  var VALID_TIMES = { day: true, dusk: true, night: true };
  var VALID_WEATHER = { clear: true, rain: true, storm: true, fog: true, 'post-rain': true };
  var PHONE_REFRACTION_MAX_SHORT_EDGE_V337 = 720;

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

  function rgba(colour, alpha) {
    return 'rgba(' + Math.round(colour[0]) + ',' + Math.round(colour[1]) + ',' +
      Math.round(colour[2]) + ',' + clamp(alpha, 0, 1).toFixed(3) + ')';
  }

  function hashUnit(seed, salt) {
    var value = (finite(seed, 0) * 2654435761 + finite(salt, 0) * 40503) >>> 0;
    value ^= value >>> 15;
    value = Math.imul(value, 2246822507) >>> 0;
    value ^= value >>> 13;
    return (value >>> 0) / 4294967296;
  }

  /* Reading the animated backing canvas into a second Canvas every frame can
   * force a GPU-to-CPU synchronisation on mobile Chromium.  That is much more
   * expensive than the small droplet drawings themselves and was able to
   * time-dilate every route on a high-DPR phone.  Keep Cinematic refraction on
   * wider displays, but render the same droplets, trails and highlights
   * without the full-frame readback on coarse/touch phone viewports. */
  function isCoarseTouchInputV337() {
    var navigatorValue = globalScope.navigator || {};
    if (finite(navigatorValue.maxTouchPoints, 0) > 0) return true;
    try {
      return typeof globalScope.matchMedia === 'function' &&
        globalScope.matchMedia('(pointer: coarse)').matches === true;
    } catch (error) {
      return false;
    }
  }

  function allowsVisorRefractionV337(width, height) {
    var shortEdge = Math.min(Math.max(0, finite(width, 0)), Math.max(0, finite(height, 0)));
    return !(isCoarseTouchInputV337() && shortEdge > 0 &&
      shortEdge <= PHONE_REFRACTION_MAX_SHORT_EDGE_V337);
  }

  function normaliseRoute(value) {
    var route = String(value || '').toLowerCase();
    if (route === 'm1' || route === 'highway') route = 'motorway';
    if (route === 'district' || route === 'urban') route = 'city';
    if (route === 'country' || route === 'countryside') route = 'rural';
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
    if (weather === 'postrain' || weather === 'post_rain' || weather === 'clearing') weather = 'post-rain';
    return VALID_WEATHER[weather] ? weather : 'clear';
  }

  function normaliseTier(value) {
    var tier = String(value || '').toLowerCase();
    if (tier === 'auto') return 'enhanced';
    if (tier === 'low' || tier === 'performance') return 'smooth';
    if (tier === 'high') return 'ultra';
    return tier === 'smooth' || tier === 'enhanced' || tier === 'ultra' || tier === 'cinematic' ?
      tier : 'enhanced';
  }

  /* The compiled renderer passes one worldFrame object. Older integrations
   * may still pass (projector, state, options), so both signatures are kept. */
  function unpackCall(projectorOrConfig, state, options) {
    var config = projectorOrConfig && typeof projectorOrConfig === 'object' ? projectorOrConfig : {};
    var projector = typeof projectorOrConfig === 'function' ? projectorOrConfig :
      (config.project || config.projector);
    var resolvedState = config.state && typeof config.state === 'object' ? config.state :
      (state && typeof state === 'object' ? state : config);
    var resolvedOptions = config.options && typeof config.options === 'object' ? config.options :
      (options && typeof options === 'object' ? options : {});
    var routeId = normaliseRoute(
      config.routeId || resolvedOptions.routeId || resolvedOptions.route ||
      resolvedState.routeId || resolvedState.route
    );

    return {
      projector: projector,
      state: resolvedState,
      options: resolvedOptions,
      routeId: routeId,
      routeStage: String(
        config.routeStage || resolvedOptions.routeStage || resolvedState.routeStage || ''
      ).toLowerCase(),
      width: Math.max(2, finite(config.width, finite(resolvedOptions.width, 390))),
      height: Math.max(2, finite(config.height, finite(resolvedOptions.height, 844))),
      horizon: finite(config.horizon, finite(resolvedOptions.horizon, finite(config.height, 844) * 0.43)),
      tier: normaliseTier(
        config.tier || config.graphicsTier || config.quality || resolvedOptions.tier ||
        resolvedOptions.graphicsTier || resolvedOptions.quality || resolvedState.graphicsTier ||
        resolvedState.graphicsQuality
      ),
      cinematic: !!(config.cinematic || resolvedOptions.cinematic),
      reducedMotion: !!(resolvedState.reducedMotion || resolvedOptions.reducedMotion),
      roadHalfWidth: clamp(finite(
        config.roadHalfWidth,
        finite(resolvedOptions.roadHalfWidth,
          finite(resolvedState.roadHalfWidth, routeId === 'motorway' ? 5.4 : routeId === 'city' ? 4.2 : 3.8))
      ), 2.6, 8.5),
      speedMph: clamp(finite(resolvedState.speed, finite(resolvedState.speedMph, 0)), 0, 180),
      playerLane: finite(resolvedState.lane, finite(resolvedState.playerLane, 0)),
      lean: finite(resolvedState.lean, 0),
      phase: String(resolvedState.phase || '').toLowerCase(),
      elapsed: finite(resolvedState.elapsed, 0),
      seed: finite(resolvedState.trafficSeed,
        finite(resolvedState.runSeed, finite(resolvedState.weeklySeed, 20260901))),
      traffic: Array.isArray(resolvedState.traffic) ? resolvedState.traffic : []
    };
  }

  function projectSafe(projector, roadX, height, distance) {
    try {
      var point = projector(roadX, height, distance);
      if (!point) return null;
      var x = finite(point.x, NaN);
      var y = finite(point.y, NaN);
      return Number.isFinite(x) && Number.isFinite(y) ? { x: x, y: y } : null;
    } catch (error) {
      return null;
    }
  }

  function fillPolygon(ctx, points) {
    if (!points || points.length < 3) return false;
    ctx.beginPath();
    ctx.moveTo(points[0].x, points[0].y);
    for (var index = 1; index < points.length; index += 1) {
      ctx.lineTo(points[index].x, points[index].y);
    }
    ctx.closePath();
    ctx.fill();
    return true;
  }

  var TIER_BUDGET = freeze({
    smooth: freeze({ cones: 0, droplets: 0, refraction: false, riderLayers: 1 }),
    enhanced: freeze({ cones: 2, droplets: 10, refraction: false, riderLayers: 2 }),
    ultra: freeze({ cones: 4, droplets: 20, refraction: false, riderLayers: 2 }),
    cinematic: freeze({ cones: 5, droplets: 28, refraction: true, riderLayers: 2 })
  });

  function atmosphereFor(config) {
    try {
      if (typeof namespace.getAtmosphericProfile === 'function') {
        var profile = namespace.getAtmosphericProfile(config.state, config.options);
        if (profile && typeof profile === 'object') return profile;
      }
    } catch (error) {
      // Local values below are a safe fallback.
    }
    return null;
  }

  function getLightingProfile(config) {
    var time = normaliseTime(config.state.timeOfDay || config.options.timeOfDay || config.state.time);
    var weather = normaliseWeather(config.state.weather || config.options.weather);
    var atmosphere = atmosphereFor(config);
    var tunnel = config.routeId === 'city' && config.routeStage === 'tunnel';

    var darkness = time === 'night' ? 1 : time === 'dusk' ? 0.55 : 0.04;
    if (weather === 'storm') darkness = clamp(darkness + 0.24, 0, 1);
    else if (weather === 'fog') darkness = clamp(darkness + 0.16, 0, 1);
    else if (weather === 'rain') darkness = clamp(darkness + 0.12, 0, 1);
    if (tunnel) darkness = Math.max(darkness, 0.82);

    var scatter = weather === 'fog' ? 1 : weather === 'storm' ? 0.76 :
      weather === 'rain' ? 0.54 : weather === 'post-rain' ? 0.18 : 0.08;
    var wetness = atmosphere ? finite(atmosphere.wetness, 0.05) :
      (weather === 'storm' ? 1 : weather === 'rain' ? 0.82 : weather === 'post-rain' ? 0.58 : 0.05);
    var rainIntensity = atmosphere ? finite(atmosphere.rainIntensity, 0) :
      (weather === 'storm' ? 1.65 : weather === 'rain' ? 1 : 0);

    // The base renderer suppresses outside precipitation in the tunnel. Match
    // that decision here: existing water may drain, but no new drops spawn.
    if (tunnel) {
      rainIntensity = 0;
      wetness *= 0.68;
      scatter = clamp(scatter, 0.12, 0.22);
    }

    var speedNormal = clamp(config.speedMph / 132, 0, 1);
    return freeze({
      kind: 'avenra-lighting-profile-v336',
      version: VERSION,
      routeId: config.routeId,
      timeOfDay: time,
      weather: weather,
      tunnel: tunnel,
      darkness: darkness,
      scatter: scatter,
      wetness: wetness,
      rainIntensity: rainIntensity,
      speedNormal: speedNormal,
      beamStrength: clamp(darkness * (0.18 + scatter * 0.56), 0, 0.74),
      visorStrength: clamp(rainIntensity * 0.45, 0, 0.72),
      visibilityEnd: atmosphere ? clamp(finite(atmosphere.visibilityEnd, 320), 120, 1100) : 320,
      budget: TIER_BUDGET[config.tier] || TIER_BUDGET.enhanced,
      tier: config.tier
    });
  }

  var HEADLAMP_GEOMETRY = freeze({
    saloon: freeze({ height: 0.66, reach: 32 }),
    estate: freeze({ height: 0.68, reach: 32 }),
    taxi: freeze({ height: 0.68, reach: 32 }),
    convertible: freeze({ height: 0.62, reach: 30 }),
    suv: freeze({ height: 0.82, reach: 36 }),
    van: freeze({ height: 0.86, reach: 34 }),
    deliveryvan: freeze({ height: 0.86, reach: 34 }),
    'delivery-van': freeze({ height: 0.86, reach: 34 }),
    motorhome: freeze({ height: 1.02, reach: 34 }),
    horsebox: freeze({ height: 1.05, reach: 32 }),
    lorry: freeze({ height: 1.12, reach: 38 }),
    artic: freeze({ height: 1.14, reach: 38 }),
    bus: freeze({ height: 1.04, reach: 34 }),
    coach: freeze({ height: 1.08, reach: 36 }),
    tractor: freeze({ height: 1.18, reach: 22 }),
    motorcycle: freeze({ height: 0.86, reach: 30 })
  });
  var UNLIT_KINDS = freeze({ horse: true, caravan: true });
  var HEADLAMP_COLOUR = [246, 248, 255];

  function vehicleKind(vehicle) {
    // Match the visual fleet owner: the traffic brain can deliberately render
    // a base motorhome slot as a caravan (and similar specialist profiles).
    return String(
      vehicle.visualKind || vehicle.trafficFleetKind || vehicle.trafficKind ||
      vehicle.vehicleType || vehicle.kind || vehicle.type || 'saloon'
    ).toLowerCase();
  }

  function vehicleRoadX(vehicle, config) {
    if (vehicle.roadX != null && Number.isFinite(Number(vehicle.roadX))) return Number(vehicle.roadX);
    if (vehicle.laneX != null && Number.isFinite(Number(vehicle.laneX))) return Number(vehicle.laneX);
    if (vehicle.lateral != null && Number.isFinite(Number(vehicle.lateral))) return Number(vehicle.lateral);
    return finite(vehicle.lane, 0) * config.roadHalfWidth;
  }

  function collectOncomingHeadlamps(config, profile) {
    var candidates = [];
    var maxDistance = Math.min(profile.visibilityEnd * 1.05, 210);

    for (var index = 0; index < config.traffic.length; index += 1) {
      var vehicle = config.traffic[index];
      if (!vehicle || typeof vehicle !== 'object') continue;
      var kind = vehicleKind(vehicle);
      if (UNLIT_KINDS[kind] || finite(vehicle.direction, 1) >= 0) continue;

      var distance = finite(vehicle.distance, NaN);
      if (!Number.isFinite(distance) || distance < 3 || distance > maxDistance) continue;
      var geometry = HEADLAMP_GEOMETRY[kind] || HEADLAMP_GEOMETRY.saloon;
      var reach = 1 - smoothstep(maxDistance * 0.7, maxDistance, distance);
      var near = smoothstep(3, 7, distance);
      var visibility = clamp(reach * near * clamp(finite(vehicle.opacity, 1), 0, 1), 0, 1);
      if (visibility <= 0.02) continue;

      // One feathered shaft per vehicle avoids giving a lamp pair twice the
      // intended energy while the base renderer retains the crisp lamp cores.
      candidates.push({
        roadX: vehicleRoadX(vehicle, config),
        height: geometry.height,
        distance: distance,
        reach: geometry.reach,
        intensity: visibility,
        colour: HEADLAMP_COLOUR
      });
    }

    candidates.sort(function byDistance(a, b) { return a.distance - b.distance; });
    return candidates;
  }

  function drawRiderShape(ctx, config, profile, spreadScale, alphaScale) {
    var lean = clamp(config.lean, -1, 1);
    var laneX = config.playerLane * config.roadHalfWidth;
    var kick = -1.15;
    var sweep = lean * 2.4;
    var nearZ = 7;
    var farZ = mix(44, 58, profile.speedNormal);
    var nearSpread = 1.55 * spreadScale;
    var farSpread = 3.6 * spreadScale;

    var nearLeft = projectSafe(config.projector, laneX + kick - nearSpread, 0.02, nearZ);
    var nearRight = projectSafe(config.projector, laneX + kick + nearSpread, 0.02, nearZ);
    var farLeft = projectSafe(config.projector, laneX + kick + sweep - farSpread, 0.02, farZ);
    var farRight = projectSafe(config.projector, laneX + kick + sweep + farSpread, 0.02, farZ);
    if (!nearLeft || !nearRight || !farLeft || !farRight) return 0;

    var gradient;
    try {
      gradient = ctx.createLinearGradient(
        (nearLeft.x + nearRight.x) * 0.5, (nearLeft.y + nearRight.y) * 0.5,
        (farLeft.x + farRight.x) * 0.5, (farLeft.y + farRight.y) * 0.5
      );
    } catch (error) {
      return 0;
    }

    var strength = profile.beamStrength * alphaScale;
    var tint = [255, 246, 226];
    gradient.addColorStop(0, rgba(tint, strength * 0.02));
    gradient.addColorStop(0.2, rgba(tint, strength * 0.16));
    gradient.addColorStop(0.58, rgba(tint, strength * 0.1));
    gradient.addColorStop(1, rgba(tint, 0));
    ctx.fillStyle = gradient;
    return fillPolygon(ctx, [nearLeft, nearRight, farRight, farLeft]) ? 1 : 0;
  }

  function drawRiderBeam(ctx, config, profile) {
    if (profile.beamStrength < 0.045) return 0;
    // The useful central pool is tier-invariant. Richer tiers add only a soft
    // outer feather, so Smooth never sacrifices hazard-reading visibility.
    var drawn = drawRiderShape(ctx, config, profile, 0.62, 0.58);
    if (profile.budget.riderLayers > 1) {
      var feather = profile.tier === 'cinematic' ? 0.36 : profile.tier === 'ultra' ? 0.33 : 0.3;
      drawn += drawRiderShape(ctx, config, profile, 1, feather);
    }
    return drawn;
  }

  function drawOncomingConeShape(ctx, config, profile, lamp, spreadScale, alphaScale, closeFade) {
    var poolDistance = lamp.distance - lamp.reach;
    if (poolDistance < 1.6) return 0;
    var kick = 1.8;
    var spread = 2.7 * spreadScale;
    var origin = projectSafe(config.projector, lamp.roadX, lamp.height, lamp.distance);
    var left = projectSafe(config.projector, lamp.roadX + kick - spread, 0.02, poolDistance);
    var right = projectSafe(config.projector, lamp.roadX + kick + spread, 0.02, poolDistance);
    if (!origin || !left || !right) return 0;

    var gradient;
    try {
      gradient = ctx.createLinearGradient(
        origin.x, origin.y, (left.x + right.x) * 0.5, (left.y + right.y) * 0.5
      );
    } catch (error) {
      return 0;
    }
    var strength = profile.beamStrength * lamp.intensity * alphaScale * closeFade;
    gradient.addColorStop(0, rgba(lamp.colour, strength * 0.28));
    gradient.addColorStop(0.36, rgba(lamp.colour, strength * 0.12));
    gradient.addColorStop(1, rgba(lamp.colour, 0));
    ctx.fillStyle = gradient;
    return fillPolygon(ctx, [origin, left, right]) ? 1 : 0;
  }

  function drawOncomingCone(ctx, config, profile, lamp) {
    // The projected pool passes the rider when distance approaches beam reach.
    // Fade it over several metres before that cutoff so the shaft never pops.
    var closeFade = smoothstep(lamp.reach + 1.6, lamp.reach + 8, lamp.distance);
    var strength = profile.beamStrength * lamp.intensity * closeFade;
    if (!(lamp.reach > 0) || strength < 0.04) return 0;
    var drawn = drawOncomingConeShape(ctx, config, profile, lamp, 1, 0.28, closeFade);
    drawn += drawOncomingConeShape(ctx, config, profile, lamp, 0.76, 0.34, closeFade);
    drawn += drawOncomingConeShape(ctx, config, profile, lamp, 0.5, 0.38, closeFade);
    return drawn > 0 ? 1 : 0;
  }

  var performanceGuard = {
    worldAverage: 0,
    postAverage: 0,
    average: 0,
    scale: 1,
    samples: 0,
    lastAdjustment: 0
  };

  function recordCost(kind, milliseconds, timestamp) {
    var value = clamp(finite(milliseconds, 0), 0, 50);
    if (kind === 'world') {
      performanceGuard.worldAverage = performanceGuard.worldAverage * 0.92 + value * 0.08;
    } else {
      performanceGuard.postAverage = performanceGuard.postAverage * 0.92 + value * 0.08;
      performanceGuard.samples += 1;
    }
    performanceGuard.average = performanceGuard.worldAverage + performanceGuard.postAverage;
    var now = finite(timestamp, 0);
    if (performanceGuard.samples < 30 || now - performanceGuard.lastAdjustment < 1200) return;
    if (performanceGuard.average > 3.2) {
      performanceGuard.scale = Math.max(0.3, performanceGuard.scale - 0.08);
      performanceGuard.lastAdjustment = now;
    } else if (performanceGuard.average < 1.45) {
      performanceGuard.scale = Math.min(1, performanceGuard.scale + 0.04);
      performanceGuard.lastAdjustment = now;
    }
  }

  function drawLightingPass(ctx, projectorOrConfig, state, options) {
    var config = unpackCall(projectorOrConfig, state, options);
    if (!ctx || typeof ctx.beginPath !== 'function' || typeof config.projector !== 'function') return 0;
    if (config.phase && config.phase !== 'preparing' && config.phase !== 'riding' && config.phase !== 'countdown' && config.phase !== 'impact') return 0;

    var profile = getLightingProfile(config);
    if (profile.beamStrength < 0.045) return 0;
    var drawn = 0;
    ctx.save();
    try {
      ctx.globalCompositeOperation = 'screen';

      // This never depends on the decorative cone budget: Smooth must retain
      // the same useful night-road visibility as richer tiers.
      drawn += drawRiderBeam(ctx, config, profile);

      // City already owns aligned vehicle cones in the compiled renderer.
      if (config.routeId !== 'city' && profile.budget.cones > 0) {
        var lamps = collectOncomingHeadlamps(config, profile);
        var coneLimit = Math.min(lamps.length,
          Math.max(0, Math.round(profile.budget.cones * performanceGuard.scale)));
        var coneCount = 0;
        for (var index = 0; index < lamps.length && coneCount < coneLimit; index += 1) {
          try {
            var coneDrawn = drawOncomingCone(ctx, config, profile, lamps[index]);
            drawn += coneDrawn;
            if (coneDrawn > 0) coneCount += 1;
          } catch (error) { /* skip */ }
        }
      }
    } finally {
      ctx.globalCompositeOperation = 'source-over';
      ctx.restore();
    }
    return drawn;
  }

  var visorState = {
    droplets: [],
    serial: 0,
    blurCanvas: null,
    blurCtx: null,
    width: 0,
    height: 0,
    spawnCarry: 0,
    lastPhase: '',
    sessionKey: ''
  };

  function resetVisor() {
    visorState.droplets.length = 0;
    visorState.serial = 0;
    visorState.width = 0;
    visorState.height = 0;
    visorState.spawnCarry = 0;
    visorState.sessionKey = '';
  }

  function resizeDroplets(width, height) {
    if (!(visorState.width > 0 && visorState.height > 0)) {
      visorState.width = width;
      visorState.height = height;
      return;
    }
    if (visorState.width === width && visorState.height === height) return;
    var scaleX = width / visorState.width;
    var scaleY = height / visorState.height;
    for (var index = 0; index < visorState.droplets.length; index += 1) {
      visorState.droplets[index].x *= scaleX;
      visorState.droplets[index].y *= scaleY;
    }
    visorState.width = width;
    visorState.height = height;
  }

  function spawnDroplet(seedIndex, width, height) {
    var r1 = hashUnit(seedIndex, 11);
    var r2 = hashUnit(seedIndex, 29);
    var r3 = hashUnit(seedIndex, 47);
    var r4 = hashUnit(seedIndex, 83);
    return {
      x: r1 * width,
      y: r2 * height * 0.82,
      radius: mix(1.5, 5.6, r3 * r3),
      life: 0,
      maxLife: mix(1.5, 4.8, r4),
      trail: 0,
      opacity: mix(0.42, 0.7, hashUnit(seedIndex, 101)),
      speedBias: mix(0.62, 1.45, hashUnit(seedIndex, 131))
    };
  }

  function updateVisor(profile, width, height, deltaSeconds, reducedMotion, allowSpawn) {
    resizeDroplets(width, height);
    var droplets = visorState.droplets;
    var effectiveBudget = reducedMotion ? Math.min(4, profile.budget.droplets) : profile.budget.droplets;
    var target = Math.round(effectiveBudget * profile.visorStrength * performanceGuard.scale);
    var airflow = reducedMotion ? 0 : profile.speedNormal;
    var centreX = width * 0.5;

    for (var index = droplets.length - 1; index >= 0; index -= 1) {
      var droplet = droplets[index];
      droplet.life += deltaSeconds;
      if (droplet.life >= droplet.maxLife || droplet.y < -20) {
        droplets.splice(index, 1);
        continue;
      }
      var velocity = (24 + droplet.radius * 19) * airflow * droplet.speedBias;
      var push = velocity * deltaSeconds;
      droplet.y -= push;
      droplet.x += (droplet.x - centreX) / Math.max(1, centreX) * push * 0.38;
      droplet.trail = reducedMotion ? 0 : clamp(velocity * 0.16, 0, 20);

      // Retire surplus naturally over a few frames instead of popping it.
      if (index >= target && droplets.length > target) {
        droplet.maxLife = Math.min(droplet.maxLife, droplet.life + 0.65);
      }
    }

    if (!allowSpawn || profile.rainIntensity <= 0.01 || target <= droplets.length) return;
    var deficit = target - droplets.length;
    visorState.spawnCarry += deltaSeconds * 18 * profile.visorStrength;
    var spawnBudget = Math.min(deficit, Math.floor(visorState.spawnCarry));
    if (spawnBudget <= 0) return;
    visorState.spawnCarry -= spawnBudget;
    for (var spawn = 0; spawn < spawnBudget; spawn += 1) {
      visorState.serial += 1;
      droplets.push(spawnDroplet(visorState.serial, width, height));
    }
  }

  function ensureBlurCanvas(width, height) {
    if (!globalScope.document || typeof globalScope.document.createElement !== 'function') return null;
    var targetWidth = Math.max(40, Math.round(width / 6));
    var targetHeight = Math.max(40, Math.round(height / 6));
    if (!visorState.blurCanvas) {
      try {
        visorState.blurCanvas = globalScope.document.createElement('canvas');
        visorState.blurCtx = visorState.blurCanvas.getContext('2d');
      } catch (error) {
        visorState.blurCanvas = null;
        visorState.blurCtx = null;
        return null;
      }
    }
    if (!visorState.blurCtx) return null;
    if (visorState.blurCanvas.width !== targetWidth || visorState.blurCanvas.height !== targetHeight) {
      visorState.blurCanvas.width = targetWidth;
      visorState.blurCanvas.height = targetHeight;
    }
    return visorState.blurCanvas;
  }

  function drawVisor(ctx, width, height, refraction, reducedMotion) {
    var droplets = visorState.droplets;
    if (!droplets.length) return 0;

    var blur = refraction && !reducedMotion && allowsVisorRefractionV337(width, height) ?
      ensureBlurCanvas(width, height) : null;
    if (blur && visorState.blurCtx) {
      try {
        visorState.blurCtx.clearRect(0, 0, blur.width, blur.height);
        visorState.blurCtx.drawImage(ctx.canvas, 0, 0, blur.width, blur.height);
      } catch (error) {
        blur = null;
      }
    }

    var drawn = 0;
    ctx.save();
    try {
      for (var index = 0; index < droplets.length; index += 1) {
        var droplet = droplets[index];
        var radius = droplet.radius;
        if (!(radius > 0.6)) continue;
        var fade = 1 - smoothstep(droplet.maxLife * 0.68, droplet.maxLife, droplet.life);
        var alpha = clamp(fade * droplet.opacity, 0, 0.72);
        if (alpha < 0.035) continue;

        if (droplet.trail > 1.4) {
          try {
            var trail = ctx.createLinearGradient(droplet.x, droplet.y, droplet.x, droplet.y + droplet.trail);
            trail.addColorStop(0, 'rgba(226,238,244,' + (alpha * 0.11).toFixed(3) + ')');
            trail.addColorStop(1, 'rgba(226,238,244,0)');
            ctx.fillStyle = trail;
            ctx.fillRect(droplet.x - radius * 0.32, droplet.y, radius * 0.64, droplet.trail);
          } catch (error) { /* skip */ }
        }

        if (blur) {
          ctx.save();
          try {
            ctx.beginPath();
            ctx.arc(droplet.x, droplet.y, radius, 0, TAU);
            ctx.clip();
            var sourceScaleX = blur.width / width;
            var sourceScaleY = blur.height / height;
            var sourceRadiusX = Math.max(1, radius * 1.4 * sourceScaleX);
            var sourceRadiusY = Math.max(1, radius * 1.4 * sourceScaleY);
            var desiredX = droplet.x * sourceScaleX - sourceRadiusX;
            var desiredY = droplet.y * sourceScaleY - sourceRadiusY;
            var desiredWidth = sourceRadiusX * 2;
            var desiredHeight = sourceRadiusY * 2;
            var sourceX = clamp(desiredX, 0, blur.width);
            var sourceY = clamp(desiredY, 0, blur.height);
            var sourceRight = clamp(desiredX + desiredWidth, 0, blur.width);
            var sourceBottom = clamp(desiredY + desiredHeight, 0, blur.height);
            var sourceWidth = sourceRight - sourceX;
            var sourceHeight = sourceBottom - sourceY;
            if (!(sourceWidth > 0 && sourceHeight > 0)) throw new Error('empty refraction crop');
            var destinationSize = radius * 2.8;
            var destinationX = -radius * 1.4 +
              ((sourceX - desiredX) / desiredWidth) * destinationSize;
            var destinationY = -radius * 1.4 +
              ((sourceY - desiredY) / desiredHeight) * destinationSize;
            var destinationWidth = (sourceWidth / desiredWidth) * destinationSize;
            var destinationHeight = (sourceHeight / desiredHeight) * destinationSize;
            ctx.globalAlpha = alpha * 0.72;
            ctx.translate(droplet.x, droplet.y);
            ctx.rotate(Math.PI);
            ctx.drawImage(
              blur, sourceX, sourceY, sourceWidth, sourceHeight,
              destinationX, destinationY, destinationWidth, destinationHeight
            );
          } catch (error) { /* skip */ }
          ctx.restore();
        }

        try {
          ctx.globalAlpha = 1;
          ctx.globalCompositeOperation = 'source-over';
          ctx.strokeStyle = 'rgba(12,18,24,' + (alpha * 0.24).toFixed(3) + ')';
          ctx.lineWidth = Math.max(0.55, radius * 0.14);
          ctx.beginPath();
          ctx.arc(droplet.x, droplet.y, radius * 0.94, 0, TAU);
          ctx.stroke();

          ctx.globalCompositeOperation = 'screen';
          var highlight = ctx.createRadialGradient(
            droplet.x - radius * 0.34, droplet.y - radius * 0.36, 0,
            droplet.x - radius * 0.34, droplet.y - radius * 0.36, radius * 0.72
          );
          highlight.addColorStop(0, 'rgba(255,255,255,' + (alpha * 0.4).toFixed(3) + ')');
          highlight.addColorStop(1, 'rgba(255,255,255,0)');
          ctx.fillStyle = highlight;
          ctx.beginPath();
          ctx.arc(droplet.x, droplet.y, radius, 0, TAU);
          ctx.fill();
          ctx.globalCompositeOperation = 'source-over';
        } catch (error) { /* skip */ }
        drawn += 1;
      }
    } finally {
      ctx.globalAlpha = 1;
      ctx.globalCompositeOperation = 'source-over';
      ctx.restore();
    }
    return drawn;
  }

  function runPostFrame(state, frame) {
    var value = frame && typeof frame === 'object' ? frame : {};
    var canvas = value.canvas;
    if (!canvas || typeof canvas.getContext !== 'function') return;

    var config = unpackCall({
      state: state,
      width: finite(value.width, canvas.clientWidth || canvas.width),
      height: finite(value.height, canvas.clientHeight || canvas.height),
      routeId: value.routeId || (state && state.routeId),
      routeStage: value.routeStage || (state && state.routeStage),
      tier: value.tier || value.graphicsTier || value.quality ||
        (state && (state.graphicsTier || state.graphicsQuality))
    });
    var active = config.phase === 'preparing' || config.phase === 'riding' || config.phase === 'countdown' || config.phase === 'impact';
    if (!active) {
      if (visorState.droplets.length) resetVisor();
      visorState.lastPhase = config.phase;
      return;
    }

    // Preparing a ride following impact/results is a new run even when a Weekly
    // Works seed is intentionally reused. Never carry the previous visor into
    // that new session; the normal preparing -> countdown -> riding transition is retained.
    if ((config.phase === 'preparing' || config.phase === 'countdown') && visorState.lastPhase &&
        visorState.lastPhase !== 'preparing' && visorState.lastPhase !== 'countdown' && visorState.lastPhase !== 'riding') {
      resetVisor();
    }

    var sessionKey = config.routeId + ':' + config.seed;
    if (visorState.sessionKey && visorState.sessionKey !== sessionKey) resetVisor();
    visorState.sessionKey = sessionKey;
    visorState.lastPhase = config.phase;

    var profile = getLightingProfile(config);
    var width = config.width;
    var height = config.height;
    if (!(width > 2 && height > 2)) return;

    var ctx;
    try { ctx = canvas.getContext('2d'); } catch (error) { return; }
    if (!ctx || typeof ctx.fillRect !== 'function') return;

    if (profile.budget.droplets <= 0) {
      if (visorState.droplets.length) resetVisor();
      return;
    }

    var deltaSeconds = clamp(finite(value.deltaSeconds, 1 / 60), 1 / 240, 0.1);
    var allowSpawn = (config.phase === 'preparing' || config.phase === 'riding' || config.phase === 'countdown') && !profile.tunnel;
    updateVisor(profile, width, height, deltaSeconds, config.reducedMotion, allowSpawn);
    if (!visorState.droplets.length) return;

    ctx.save();
    try {
      drawVisor(
        ctx,
        width,
        height,
        profile.budget.refraction && performanceGuard.scale > 0.62,
        config.reducedMotion
      );
    } catch (error) {
      // A visual pass must never interrupt the ride loop.
    } finally {
      ctx.restore();
    }
  }

  var LIGHTING_METADATA = freeze({
    version: VERSION,
    mode: 'deduplicated-canvas2d-lighting',
    worldPass: freeze(['rider-dipped-beam', 'rural-and-motorway-oncoming-beam-scatter']),
    postPass: freeze(['world-windscreen-water', 'cinematic-local-refraction']),
    compositing: freeze(['screen', 'source-over']),
    rendererOwned: freeze([
      'physical-lamp-cores', 'street-and-tunnel-fixtures', 'wet-road-reflections',
      'source-anchored-lamp-halos', 'vignette', 'edge-speed-streaks'
    ]),
    mutatesGameplay: false,
    consumesRunRandomness: false,
    respectsReducedMotion: true,
    phoneRefraction: freeze({
      enabled: false,
      maximumShortEdge: PHONE_REFRACTION_MAX_SHORT_EDGE_V337,
      retainedEffects: freeze(['droplets', 'trails', 'highlights', 'spray', 'lamp-glow'])
    }),
    tiers: TIER_BUDGET
  });

  var previousDrawNearField = namespace.drawNearField;
  var previousAfterAnimationFrame = namespace.afterAnimationFrame;

  function wrappedDrawNearField(ctx, projectorOrConfig, state, options) {
    var previousResult = 0;
    if (typeof previousDrawNearField === 'function') {
      try { previousResult = previousDrawNearField.apply(this, arguments); } catch (error) { previousResult = 0; }
    }
    var lightingResult = 0;
    var started = 0;
    try { started = globalScope.performance ? globalScope.performance.now() : 0; } catch (error) { started = 0; }
    try { lightingResult = drawLightingPass(ctx, projectorOrConfig, state, options); } catch (error) { lightingResult = 0; }
    try {
      if (started) recordCost('world', globalScope.performance.now() - started, globalScope.performance.now());
    } catch (error) { /* ignore */ }
    return (typeof previousResult === 'number' ? previousResult : 0) + lightingResult;
  }

  function wrappedAfterAnimationFrame(state, frame) {
    var previousResult;
    if (typeof previousAfterAnimationFrame === 'function') {
      try { previousResult = previousAfterAnimationFrame.apply(this, arguments); } catch (error) { previousResult = undefined; }
    }
    var started = 0;
    try { started = globalScope.performance ? globalScope.performance.now() : 0; } catch (error) { started = 0; }
    try { runPostFrame(state, frame); } catch (error) { /* never break the loop */ }
    try {
      if (started) recordCost('post', globalScope.performance.now() - started,
        finite(frame && frame.timestamp, globalScope.performance.now()));
    } catch (error) { /* ignore */ }
    return previousResult;
  }

  wrappedDrawNearField.__avenraLightingV337 = true;
  wrappedDrawNearField.version = VERSION;
  wrappedAfterAnimationFrame.__avenraLightingV337 = true;
  wrappedAfterAnimationFrame.version = VERSION;
  namespace.__avenraLightingV337Installed = true;

  var lightingNamespace = namespace.lighting;
  if (!lightingNamespace || typeof lightingNamespace !== 'object') lightingNamespace = {};
  Object.assign(lightingNamespace, {
    version: VERSION,
    metadata: LIGHTING_METADATA,
    getProfile: function getProfile(state, options) {
      return getLightingProfile(unpackCall({ state: state, options: options }));
    },
    draw: drawLightingPass,
    post: runPostFrame,
    allowsVisorRefraction: allowsVisorRefractionV337,
    performance: performanceGuard,
    resetVisor: resetVisor
  });

  Object.assign(namespace, {
    lightingVersion: VERSION,
    lightingMetadata: LIGHTING_METADATA,
    getLightingProfile: lightingNamespace.getProfile,
    drawLightingPass: drawLightingPass,
    drawNearField: wrappedDrawNearField,
    afterAnimationFrame: wrappedAfterAnimationFrame,
    lighting: lightingNamespace
  });

  if (namespace.world && typeof namespace.world === 'object') {
    namespace.world.lightingVersion = VERSION;
    namespace.world.lighting = lightingNamespace;
  }

  globalScope.AvenraNextGenV300 = namespace;
})(typeof window !== 'undefined' ? window : (typeof globalThis !== 'undefined' ? globalThis : this));
