/*
 * AVENRA HYPERLANE — visual helpers v3.3.6
 *
 * Dependency-free Canvas 2D helpers for the next-generation renderer.  This
 * file deliberately owns no animation loop and no game state.  Callers can
 * adopt each pass independently and the helpers return false/zero rather than
 * throwing when they receive incomplete state or an unavailable canvas API.
 */
(function attachAvenraVisualHelpers(globalScope) {
  'use strict';

  if (!globalScope) return;

  var TAU = Math.PI * 2;
  var VERSION = '3.3.6';
  var VALID_ROUTES = { city: true, rural: true, motorway: true };
  var VALID_TIMES = { day: true, dusk: true, night: true };
  var VALID_WEATHER = { clear: true, rain: true, 'post-rain': true, storm: true, fog: true };

  var WEATHER_BANDS = {
    clear: {
      visibilityStart: 315,
      visibilityEnd: 510,
      density: 0.11,
      objectLoss: 0.34,
      horizonAlpha: 0.15,
      lowerAlpha: 0.035,
      bankAlpha: 0.015,
      sprayAlpha: 0,
      hardFadeLength: 88
    },
    rain: {
      visibilityStart: 205,
      visibilityEnd: 455,
      density: 0.31,
      objectLoss: 0.61,
      horizonAlpha: 0.31,
      lowerAlpha: 0.085,
      bankAlpha: 0.11,
      sprayAlpha: 0.16,
      hardFadeLength: 112
    },
    'post-rain': {
      visibilityStart: 245,
      visibilityEnd: 480,
      density: 0.21,
      objectLoss: 0.44,
      horizonAlpha: 0.22,
      lowerAlpha: 0.062,
      bankAlpha: 0.052,
      sprayAlpha: 0.065,
      hardFadeLength: 102
    },
    storm: {
      visibilityStart: 132,
      visibilityEnd: 375,
      density: 0.51,
      objectLoss: 0.79,
      horizonAlpha: 0.46,
      lowerAlpha: 0.14,
      bankAlpha: 0.22,
      sprayAlpha: 0.28,
      hardFadeLength: 132
    },
    fog: {
      visibilityStart: 45,
      visibilityEnd: 225,
      density: 0.92,
      objectLoss: 0.95,
      horizonAlpha: 0.72,
      lowerAlpha: 0.25,
      bankAlpha: 0.48,
      sprayAlpha: 0.025,
      hardFadeLength: 154
    }
  };

  var TIME_PALETTES = {
    day: {
      horizon: [174, 194, 204],
      foreground: [111, 135, 148],
      lamp: [232, 244, 247]
    },
    dusk: {
      horizon: [203, 146, 116],
      foreground: [75, 89, 107],
      lamp: [255, 208, 145]
    },
    night: {
      horizon: [24, 39, 55],
      foreground: [5, 13, 23],
      lamp: [206, 226, 235]
    }
  };

  var WEATHER_TINTS = {
    clear: { horizon: [167, 190, 204], foreground: [102, 128, 145], amount: 0.04 },
    rain: { horizon: [126, 143, 154], foreground: [76, 94, 108], amount: 0.48 },
    'post-rain': { horizon: [181, 201, 209], foreground: [92, 120, 132], amount: 0.21 },
    storm: { horizon: [83, 98, 113], foreground: [42, 56, 70], amount: 0.67 },
    fog: { horizon: [190, 198, 201], foreground: [144, 157, 162], amount: 0.70 }
  };

  var ROUTE_TINTS = {
    city: { colour: [124, 139, 148], amount: 0.035, sightline: 0.97 },
    rural: { colour: [112, 133, 124], amount: 0.055, sightline: 0.92 },
    motorway: { colour: [122, 142, 157], amount: 0.04, sightline: 1.07 }
  };

  var VEHICLE_SPECS = {
    saloon: { roof: 0.15, shoulder: 0.34, lower: 0.89, wheelY: 0.82, wheelW: 0.16, wheelH: 0.115, pairs: 1 },
    car: { roof: 0.15, shoulder: 0.34, lower: 0.89, wheelY: 0.82, wheelW: 0.16, wheelH: 0.115, pairs: 1 },
    convertible: { roof: 0.24, shoulder: 0.38, lower: 0.89, wheelY: 0.82, wheelW: 0.16, wheelH: 0.115, pairs: 1 },
    suv: { roof: 0.095, shoulder: 0.27, lower: 0.91, wheelY: 0.82, wheelW: 0.18, wheelH: 0.13, pairs: 1 },
    van: { roof: 0.055, shoulder: 0.19, lower: 0.92, wheelY: 0.83, wheelW: 0.17, wheelH: 0.125, pairs: 1 },
    motorhome: { roof: 0.035, shoulder: 0.15, lower: 0.93, wheelY: 0.84, wheelW: 0.18, wheelH: 0.13, pairs: 1 },
    lorry: { roof: 0.025, shoulder: 0.12, lower: 0.94, wheelY: 0.85, wheelW: 0.18, wheelH: 0.135, pairs: 2 },
    hgv: { roof: 0.025, shoulder: 0.12, lower: 0.94, wheelY: 0.85, wheelW: 0.18, wheelH: 0.135, pairs: 2 },
    truck: { roof: 0.025, shoulder: 0.12, lower: 0.94, wheelY: 0.85, wheelW: 0.18, wheelH: 0.135, pairs: 2 }
  };

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

  function mixColour(a, b, amount) {
    return [
      Math.round(mix(a[0], b[0], amount)),
      Math.round(mix(a[1], b[1], amount)),
      Math.round(mix(a[2], b[2], amount))
    ];
  }

  function scaleColour(colour, amount) {
    return [
      Math.round(clamp(colour[0] * amount, 0, 255)),
      Math.round(clamp(colour[1] * amount, 0, 255)),
      Math.round(clamp(colour[2] * amount, 0, 255))
    ];
  }

  function rgba(colour, alpha) {
    return 'rgba(' + colour[0] + ',' + colour[1] + ',' + colour[2] + ',' + clamp(alpha, 0, 1) + ')';
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
    if (time === 'morning' || time === 'afternoon' || time === 'clear') time = 'day';
    return VALID_TIMES[time] ? time : 'day';
  }

  function normaliseWeather(value) {
    var weather = String(value || '').toLowerCase();
    if (weather === 'wet' || weather === 'drizzle') weather = 'rain';
    if (weather === 'mist' || weather === 'misty') weather = 'fog';
    if (weather === 'postrain' || weather === 'post_rain' || weather === 'after-rain' || weather === 'after rain' || weather === 'clearing') weather = 'post-rain';
    return VALID_WEATHER[weather] ? weather : 'clear';
  }

  function hashUnit(value) {
    var number = finite(value, 0);
    var result = Math.sin(number * 12.9898 + 78.233) * 43758.5453123;
    return result - Math.floor(result);
  }

  function stringHash(value) {
    var text = String(value || '');
    var hash = 2166136261;
    for (var index = 0; index < text.length; index += 1) {
      hash ^= text.charCodeAt(index);
      hash = Math.imul(hash, 16777619);
    }
    return hash >>> 0;
  }

  function isProfile(value) {
    return !!value && typeof value === 'object' && value.kind === 'avenra-atmosphere-v300';
  }

  /**
   * Builds a colour-matched atmospheric profile for any route, time and
   * weather combination.  It accepts either separate strings or a state-like
   * object containing routeId/timeOfDay/weather.
   */
  function getAtmosphericProfile(routeId, timeOfDay, weather, options) {
    var state = null;
    if (routeId && typeof routeId === 'object') {
      state = routeId;
      options = timeOfDay && typeof timeOfDay === 'object' ? timeOfDay : {};
      routeId = state.routeId || state.route || state.routeType || options.routeId || options.route;
      timeOfDay = state.timeOfDay || state.time || state.period || options.timeOfDay || options.time;
      weather = state.weather || state.weatherId || state.condition || options.weather || options.condition;
    }

    options = options && typeof options === 'object' ? options : {};
    var route = normaliseRoute(routeId);
    var time = normaliseTime(timeOfDay);
    var condition = normaliseWeather(weather);
    var band = WEATHER_BANDS[condition];
    var timePalette = TIME_PALETTES[time];
    var weatherTint = WEATHER_TINTS[condition];
    var routeTint = ROUTE_TINTS[route];
    var drawDistance = clamp(finite(options.drawDistance, finite(state && state.drawDistance, 520)), 140, 1400);
    var sightline = routeTint.sightline;
    var horizon = mixColour(timePalette.horizon, weatherTint.horizon, weatherTint.amount);
    var foreground = mixColour(timePalette.foreground, weatherTint.foreground, weatherTint.amount);
    horizon = mixColour(horizon, routeTint.colour, routeTint.amount);
    foreground = mixColour(foreground, routeTint.colour, routeTint.amount * 1.25);

    // Night fog remains a dark, blue-black distance fade rather than becoming
    // a bright daytime-grey veil.
    if (time === 'night' && condition === 'fog') {
      horizon = mixColour([24, 38, 51], [84, 99, 107], 0.46);
      foreground = mixColour([5, 13, 23], [52, 65, 75], 0.34);
    }

    var visibilityStart = clamp(band.visibilityStart * sightline, 18, drawDistance - 15);
    var visibilityEnd = clamp(band.visibilityEnd * sightline, visibilityStart + 20, drawDistance);
    var hardFadeStart = Math.max(visibilityStart, drawDistance - band.hardFadeLength);

    return {
      kind: 'avenra-atmosphere-v300',
      version: VERSION,
      routeId: route,
      timeOfDay: time,
      weather: condition,
      drawDistance: drawDistance,
      visibilityStart: visibilityStart,
      visibilityEnd: visibilityEnd,
      hardFadeStart: hardFadeStart,
      hardFadeEnd: drawDistance,
      density: band.density,
      objectLoss: band.objectLoss,
      horizonAlpha: band.horizonAlpha + (time === 'night' ? 0.035 : 0),
      lowerAlpha: band.lowerAlpha,
      bankAlpha: band.bankAlpha,
      sprayAlpha: band.sprayAlpha,
      wetness: condition === 'storm' ? 1 : (condition === 'rain' ? 0.82 : (condition === 'post-rain' ? 0.58 : (condition === 'fog' ? 0.2 : 0.05))),
      rainIntensity: condition === 'storm' ? 1.65 : (condition === 'rain' ? 1 : (condition === 'post-rain' ? 0.035 : 0)),
      lightRetention: time === 'night' ? 0.58 : (condition === 'storm' || condition === 'fog' ? 0.34 : 0.18),
      horizonColour: horizon,
      foregroundColour: foreground,
      lampColour: timePalette.lamp.slice(),
      clearCentre: condition === 'fog' ? 0.50 : 0.60
    };
  }

  function resolveProfile(value, options) {
    if (isProfile(value)) return value;
    if (value && typeof value === 'object') {
      return getAtmosphericProfile(Object.assign({}, options || {}, value), options || {});
    }
    options = options && typeof options === 'object' ? options : {};
    return getAtmosphericProfile(
      options.routeId || options.route || value,
      options.timeOfDay || options.time,
      options.weather,
      options
    );
  }

  /**
   * Returns a stable 0..1 LOD opacity.  Atmospheric attenuation is combined
   * with a mandatory final spawn/despawn fade, so even clear weather never
   * produces a hard pop at the draw-distance boundary.
   */
  function distanceLodAlpha(distance, profileOrState, options) {
    var metres = finite(distance, NaN);
    if (!Number.isFinite(metres) || metres < 0) return 0;
    var profile = resolveProfile(profileOrState, options);
    if (metres >= profile.hardFadeEnd) return 0;

    var atmosphericProgress = smoothstep(profile.visibilityStart, profile.visibilityEnd, metres);
    var atmosphericAlpha = 1 - profile.objectLoss * atmosphericProgress;
    var hardAlpha = 1 - smoothstep(profile.hardFadeStart, profile.hardFadeEnd, metres);
    var alpha = atmosphericAlpha * hardAlpha;

    options = options && typeof options === 'object' ? options : {};
    if (options.emissive || options.lights) {
      var retainedLight = hardAlpha * profile.lightRetention * smoothstep(30, profile.visibilityEnd, metres);
      alpha = Math.max(alpha, retainedLight);
    }
    return clamp(alpha, 0, 1);
  }

  function hasMethods(target, methods) {
    if (!target) return false;
    for (var index = 0; index < methods.length; index += 1) {
      if (typeof target[methods[index]] !== 'function') return false;
    }
    return true;
  }

  function bump(debug, key, amount) {
    if (!debug || typeof debug !== 'object' || debug.enabled === false) return;
    var increment = Number.isFinite(amount) ? amount : 1;
    try {
      debug[key] = finite(debug[key], 0) + increment;
    } catch (error) {
      // Frozen/host-owned diagnostics must never interrupt rendering.
    }
  }

  function withContext(ctx, debug, draw) {
    if (!hasMethods(ctx, ['save', 'restore'])) return false;
    try {
      ctx.save();
    } catch (error) {
      bump(debug, 'errors');
      return false;
    }
    try {
      draw();
      return true;
    } catch (error) {
      bump(debug, 'errors');
      return false;
    } finally {
      try {
        ctx.restore();
      } catch (restoreError) {
        bump(debug, 'errors');
      }
    }
  }

  /** Draws universal horizon haze plus deterministic fog/rain banks. */
  function drawDepthHaze(ctx, width, height, horizonY, profileOrState, options) {
    if (width && typeof width === 'object') {
      var hazeConfig = width;
      width = hazeConfig.width || (ctx && ctx.canvas && ctx.canvas.width);
      height = hazeConfig.height || (ctx && ctx.canvas && ctx.canvas.height);
      horizonY = hazeConfig.horizonY != null ? hazeConfig.horizonY : hazeConfig.horizon;
      profileOrState = hazeConfig.profile || hazeConfig.state || hazeConfig;
      options = Object.assign({}, hazeConfig.state || {}, hazeConfig, hazeConfig.options || {});
    }
    var viewportWidth = finite(width, 0);
    var viewportHeight = finite(height, 0);
    if (!(viewportWidth > 1 && viewportHeight > 1)) return false;
    if (!hasMethods(ctx, ['fillRect', 'createLinearGradient'])) return false;

    options = options && typeof options === 'object' ? options : {};
    var debug = options.debug;
    var profile = resolveProfile(profileOrState, options);
    var horizon = clamp(finite(horizonY, viewportHeight * 0.42), 0, viewportHeight);
    var intensity = clamp(finite(options.intensity, 1), 0, 2);
    var elapsed = finite(options.elapsed,
      finite(profileOrState && profileOrState.elapsed, finite(profileOrState && profileOrState.worldDistance, 0) * 0.012));

    bump(debug, 'hazeCalls');
    return withContext(ctx, debug, function paintHaze() {
      var upper = Math.max(0, horizon - viewportHeight * 0.24);
      var lower = Math.min(viewportHeight, horizon + viewportHeight * 0.58);
      var gradient = ctx.createLinearGradient(0, upper, 0, lower);
      gradient.addColorStop(0, rgba(profile.horizonColour, 0));
      gradient.addColorStop(0.32, rgba(profile.horizonColour, profile.horizonAlpha * 0.42 * intensity));
      gradient.addColorStop(0.48, rgba(profile.horizonColour, profile.horizonAlpha * intensity));
      gradient.addColorStop(0.67, rgba(profile.foregroundColour, profile.lowerAlpha * intensity));
      gradient.addColorStop(1, rgba(profile.foregroundColour, 0));
      ctx.fillStyle = gradient;
      ctx.fillRect(0, upper, viewportWidth, lower - upper);

      // A narrow horizontal veil conceals the far LOD transition in all
      // conditions, including clear daytime.
      var veilHeight = Math.max(3, viewportHeight * (0.018 + profile.density * 0.055));
      var veil = ctx.createLinearGradient(0, horizon - veilHeight, 0, horizon + veilHeight);
      veil.addColorStop(0, rgba(profile.horizonColour, 0));
      veil.addColorStop(0.5, rgba(profile.horizonColour, profile.horizonAlpha * 0.58 * intensity));
      veil.addColorStop(1, rgba(profile.horizonColour, 0));
      ctx.fillStyle = veil;
      ctx.fillRect(0, horizon - veilHeight, viewportWidth, veilHeight * 2);

      if (profile.bankAlpha <= 0.02 || options.banks === false) return;
      if (!hasMethods(ctx, ['beginPath', 'fill', 'ellipse', 'createRadialGradient'])) return;

      var banks = profile.weather === 'fog' ? 5 : (profile.weather === 'storm' ? 4 : 3);
      for (var index = 0; index < banks; index += 1) {
        // Keep each bank's identity stable; only its drift changes with time,
        // preventing the horizon mist from jumping at a timer boundary.
        var seed = hashUnit(index * 37.1);
        var drift = (elapsed * (3.2 + index * 0.45) + seed * viewportWidth) % (viewportWidth * 1.45);
        var centreX = drift - viewportWidth * 0.22;
        var centreY = horizon + viewportHeight * (0.012 + hashUnit(index * 91.7) * 0.09);
        var radiusX = viewportWidth * (0.18 + hashUnit(index * 17.3) * 0.18);
        var radiusY = viewportHeight * (0.022 + profile.density * 0.055 + hashUnit(index * 53.9) * 0.025);
        var bankGradient = ctx.createRadialGradient(centreX, centreY, 0, centreX, centreY, radiusX);
        bankGradient.addColorStop(0, rgba(profile.horizonColour, profile.bankAlpha * intensity * (0.74 + seed * 0.26)));
        bankGradient.addColorStop(0.62, rgba(profile.horizonColour, profile.bankAlpha * intensity * 0.38));
        bankGradient.addColorStop(1, rgba(profile.horizonColour, 0));
        ctx.beginPath();
        ctx.fillStyle = bankGradient;
        ctx.ellipse(centreX, centreY, radiusX, radiusY, 0, 0, TAU);
        ctx.fill();
      }
      bump(debug, 'hazeBanks', banks);
    });
  }

  function parseColour(value) {
    if (Array.isArray(value) && value.length >= 3) {
      return [clamp(finite(value[0], 58), 0, 255), clamp(finite(value[1], 68), 0, 255), clamp(finite(value[2], 78), 0, 255)];
    }
    var text = String(value || '').trim();
    var short = /^#([0-9a-f]{3})$/i.exec(text);
    if (short) {
      return [
        parseInt(short[1][0] + short[1][0], 16),
        parseInt(short[1][1] + short[1][1], 16),
        parseInt(short[1][2] + short[1][2], 16)
      ];
    }
    var full = /^#([0-9a-f]{6})$/i.exec(text);
    if (full) {
      return [parseInt(full[1].slice(0, 2), 16), parseInt(full[1].slice(2, 4), 16), parseInt(full[1].slice(4, 6), 16)];
    }
    var rgb = /^rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)/i.exec(text);
    if (rgb) return [clamp(Number(rgb[1]), 0, 255), clamp(Number(rgb[2]), 0, 255), clamp(Number(rgb[3]), 0, 255)];
    return [58, 68, 78];
  }

  function normaliseRect(rect) {
    if (!rect || typeof rect !== 'object') return null;
    var x;
    var y;
    var width;
    var height;
    if (Number.isFinite(finite(rect.left, NaN)) && Number.isFinite(finite(rect.top, NaN)) &&
        Number.isFinite(finite(rect.right, NaN)) && Number.isFinite(finite(rect.bottom, NaN))) {
      x = finite(rect.left, NaN);
      y = finite(rect.top, NaN);
      width = finite(rect.right, NaN) - x;
      height = finite(rect.bottom, NaN) - y;
    } else {
      width = finite(rect.width != null ? rect.width : rect.w, NaN);
      height = finite(rect.height != null ? rect.height : rect.h, NaN);
      if (Number.isFinite(finite(rect.centerX, NaN)) || Number.isFinite(finite(rect.cx, NaN))) {
        x = finite(rect.centerX != null ? rect.centerX : rect.cx, NaN) - width * 0.5;
      } else {
        x = finite(rect.x, NaN);
      }
      if (Number.isFinite(finite(rect.groundY, NaN))) {
        y = finite(rect.groundY, NaN) - height;
      } else if (Number.isFinite(finite(rect.bottomY, NaN))) {
        y = finite(rect.bottomY, NaN) - height;
      } else {
        y = finite(rect.y, NaN);
      }
    }
    if (![x, y, width, height].every(Number.isFinite) || width <= 1 || height <= 1) return null;
    return { x: x, y: y, width: width, height: height, right: x + width, bottom: y + height };
  }

  function fillPolygon(ctx, points) {
    if (!points || !points.length) return;
    ctx.beginPath();
    ctx.moveTo(points[0][0], points[0][1]);
    for (var index = 1; index < points.length; index += 1) ctx.lineTo(points[index][0], points[index][1]);
    ctx.closePath();
    ctx.fill();
  }

  function drawEllipse(ctx, x, y, radiusX, radiusY) {
    ctx.beginPath();
    if (typeof ctx.ellipse === 'function') {
      ctx.ellipse(x, y, Math.max(0.1, radiusX), Math.max(0.1, radiusY), 0, 0, TAU);
    } else {
      ctx.arc(x, y, Math.max(0.1, Math.min(radiusX, radiusY)), 0, TAU);
    }
    ctx.fill();
  }

  function normaliseKind(vehicle) {
    var kind = String(vehicle && (vehicle.kind || vehicle.type || vehicle.vehicleType) || 'saloon').toLowerCase();
    if (kind === 'estate' || kind === 'hatchback') kind = 'saloon';
    if (kind === 'semi' || kind === 'artic' || kind === 'tractor-trailer') kind = 'lorry';
    return VEHICLE_SPECS[kind] ? kind : 'saloon';
  }

  function drawVehicleShadow(ctx, rect, spec, alpha) {
    ctx.fillStyle = 'rgba(0,0,0,' + clamp(0.22 * alpha, 0, 0.38) + ')';
    drawEllipse(ctx, rect.x + rect.width * 0.5, rect.bottom + rect.height * 0.018, rect.width * 0.47, rect.height * 0.065);
    ctx.fillStyle = 'rgba(0,0,0,' + clamp(0.13 * alpha, 0, 0.24) + ')';
    drawEllipse(ctx, rect.x + rect.width * 0.5, rect.bottom - rect.height * 0.015, rect.width * 0.36, rect.height * 0.045);
  }

  function vehicleBodyPoints(rect, spec) {
    var x = rect.x;
    var y = rect.y;
    var w = rect.width;
    var h = rect.height;
    var square = spec.roof < 0.08;
    return square ? [
      [x + w * 0.14, y + h * spec.roof], [x + w * 0.86, y + h * spec.roof],
      [x + w * 0.94, y + h * spec.shoulder], [x + w * 0.96, y + h * spec.lower],
      [x + w * 0.78, y + h * 0.96], [x + w * 0.22, y + h * 0.96],
      [x + w * 0.04, y + h * spec.lower], [x + w * 0.06, y + h * spec.shoulder]
    ] : [
      [x + w * 0.19, y + h * spec.shoulder], [x + w * 0.31, y + h * spec.roof],
      [x + w * 0.69, y + h * spec.roof], [x + w * 0.81, y + h * spec.shoulder],
      [x + w * 0.96, y + h * 0.62], [x + w * 0.92, y + h * spec.lower],
      [x + w * 0.76, y + h * 0.95], [x + w * 0.24, y + h * 0.95],
      [x + w * 0.08, y + h * spec.lower], [x + w * 0.04, y + h * 0.62]
    ];
  }

  function drawVehicleBody(ctx, rect, spec, colour, yaw, alpha) {
    var x = rect.x;
    var y = rect.y;
    var w = rect.width;
    var h = rect.height;
    var body = vehicleBodyPoints(rect, spec);
    ctx.fillStyle = rgba(scaleColour(colour, 0.76), 0.96 * alpha);
    fillPolygon(ctx, body);

    var side = yaw < 0 ? -1 : 1;
    var extrusion = w * (0.035 + Math.abs(yaw) * 0.13);
    if (side > 0) {
      ctx.fillStyle = rgba(scaleColour(colour, 0.46), 0.94 * alpha);
      fillPolygon(ctx, [
        [x + w * 0.81, y + h * spec.shoulder],
        [x + w * 0.96 + extrusion, y + h * 0.43],
        [x + w * 0.94 + extrusion, y + h * spec.lower],
        [x + w * 0.76, y + h * 0.95],
        [x + w * 0.92, y + h * spec.lower]
      ]);
    } else {
      ctx.fillStyle = rgba(scaleColour(colour, 0.50), 0.94 * alpha);
      fillPolygon(ctx, [
        [x + w * 0.19, y + h * spec.shoulder],
        [x + w * 0.04 - extrusion, y + h * 0.43],
        [x + w * 0.06 - extrusion, y + h * spec.lower],
        [x + w * 0.24, y + h * 0.95],
        [x + w * 0.08, y + h * spec.lower]
      ]);
    }

    // A restrained roof/front facet makes the fallback readable as a volume;
    // a production sprite painted over this still retains its own texture.
    ctx.fillStyle = rgba(scaleColour(colour, 1.13), 0.28 * alpha);
    fillPolygon(ctx, [
      [x + w * 0.22, y + h * spec.shoulder], [x + w * 0.32, y + h * spec.roof],
      [x + w * 0.68, y + h * spec.roof], [x + w * 0.78, y + h * spec.shoulder],
      [x + w * 0.68, y + h * 0.44], [x + w * 0.32, y + h * 0.44]
    ]);
  }

  function drawVehicleWheels(ctx, rect, spec, vehicle, options, alpha, overlay) {
    if (rect.width < 7 || rect.height < 8) return;
    var x = rect.x;
    var y = rect.y;
    var w = rect.width;
    var h = rect.height;
    var elapsed = finite(options.elapsed, finite(vehicle && vehicle.elapsed, 0));
    var speed = Math.abs(finite(vehicle && vehicle.speed, finite(options.speed, 0)));
    var phase = finite(vehicle && vehicle.wheelPhase, elapsed * speed * 0.085 + hashUnit(stringHash(vehicle && vehicle.id)) * TAU);
    var quality = String(options.quality || options.tier || 'enhanced').toLowerCase();
    var smooth = quality === 'smooth' || quality === 'low';
    var wheelRadiusX = Math.max(1.2, w * spec.wheelW * 0.5);
    var wheelRadiusY = Math.max(1, h * spec.wheelH * 0.5);
    var centres = [x + w * 0.17, x + w * 0.83];
    if (spec.pairs > 1 && w > 24) centres = [x + w * 0.13, x + w * 0.23, x + w * 0.77, x + w * 0.87];

    for (var index = 0; index < centres.length; index += 1) {
      var centreX = centres[index];
      var centreY = y + h * spec.wheelY + (index % 2 ? h * 0.006 : 0);
      ctx.fillStyle = 'rgba(7,10,13,' + clamp((overlay ? 0.96 : 0.72) * alpha, 0, 1) + ')';
      drawEllipse(ctx, centreX, centreY, wheelRadiusX, wheelRadiusY);
      if (!overlay || w < (smooth ? 34 : 22) || !hasMethods(ctx, ['stroke', 'moveTo', 'lineTo'])) continue;
      ctx.fillStyle = 'rgba(104,114,120,' + clamp(0.78 * alpha, 0, 1) + ')';
      drawEllipse(ctx, centreX, centreY, wheelRadiusX * 0.52, wheelRadiusY * 0.56);
      ctx.strokeStyle = 'rgba(22,27,31,' + clamp(0.88 * alpha, 0, 1) + ')';
      ctx.lineWidth = Math.max(0.6, w * 0.011);
      var spokeCount = smooth ? 3 : 4;
      for (var spoke = 0; spoke < spokeCount; spoke += 1) {
        var angle = phase + spoke * TAU / spokeCount;
        ctx.beginPath();
        ctx.moveTo(centreX, centreY);
        ctx.lineTo(centreX + Math.cos(angle) * wheelRadiusX * 0.46, centreY + Math.sin(angle) * wheelRadiusY * 0.48);
        ctx.stroke();
      }
    }
  }

  function indicatorSides(value) {
    var indicator = String(value == null ? '' : value).toLowerCase();
    if (indicator === 'left' || indicator === '-1') return [-1];
    if (indicator === 'right' || indicator === '1') return [1];
    if (indicator === 'hazard' || indicator === 'hazards' || indicator === 'true') return [-1, 1];
    if (value === true) return [-1, 1];
    return [];
  }

  function drawVehicleOverlayPass(ctx, rect, spec, vehicle, colour, profile, options, alpha) {
    var x = rect.x;
    var y = rect.y;
    var w = rect.width;
    var h = rect.height;
    var direction = finite(vehicle && vehicle.direction, 1);
    var frontFacing = direction < 0 || vehicle && vehicle.frontFacing === true;
    var elapsed = finite(options.elapsed, finite(vehicle && vehicle.elapsed, 0));
    var closeDetail = w >= 12;

    // Lower sill and glass facet; both are intentionally translucent so an
    // existing textured sprite remains the dominant surface.
    ctx.fillStyle = rgba(scaleColour(colour, 0.50), 0.34 * alpha);
    ctx.fillRect(x + w * 0.13, y + h * 0.63, w * 0.74, Math.max(1, h * 0.055));
    if (closeDetail) {
      ctx.fillStyle = 'rgba(18,29,38,' + clamp(0.38 * alpha, 0, 0.58) + ')';
      fillPolygon(ctx, [
        [x + w * 0.27, y + h * (spec.shoulder + 0.015)], [x + w * 0.34, y + h * (spec.roof + 0.03)],
        [x + w * 0.66, y + h * (spec.roof + 0.03)], [x + w * 0.73, y + h * (spec.shoulder + 0.015)],
        [x + w * 0.64, y + h * 0.43], [x + w * 0.36, y + h * 0.43]
      ]);
    }
    if (vehicle && vehicle.doorOpen && closeDetail) {
      ctx.fillStyle = 'rgba(8,13,18,' + clamp(0.78 * alpha, 0, 0.9) + ')';
      fillPolygon(ctx, [
        [x + w * 0.76, y + h * 0.36], [x + w * 1.07, y + h * 0.46],
        [x + w * 1.02, y + h * 0.78], [x + w * 0.77, y + h * 0.69]
      ]);
      ctx.strokeStyle = 'rgba(159,181,192,' + clamp(0.5 * alpha, 0, 0.65) + ')';
      ctx.lineWidth = Math.max(0.8, w * 0.012);
      ctx.beginPath();
      ctx.moveTo(x + w * 0.76, y + h * 0.36);
      ctx.lineTo(x + w * 1.07, y + h * 0.46);
      ctx.lineTo(x + w * 1.02, y + h * 0.78);
      ctx.stroke();
    }

    var lightY = y + h * (frontFacing ? 0.58 : 0.68);
    var lightWidth = Math.max(1.1, w * (frontFacing ? 0.13 : 0.10));
    var lightHeight = Math.max(0.8, h * 0.045);
    var brake = !!(vehicle && vehicle.braking);
    var isDark = profile && profile.timeOfDay === 'night';
    var lightAlpha = clamp((frontFacing ? 0.72 : (brake ? 1 : 0.72)) * alpha, 0, 1);
    var lightColour = frontFacing ? [226, 244, 255] : [255, 42, 38];

    if ((isDark || brake || frontFacing) && w > 10) {
      ctx.shadowColor = rgba(lightColour, 0.78 * alpha);
      ctx.shadowBlur = Math.min(12, Math.max(1.5, w * 0.09));
    }
    ctx.fillStyle = rgba(lightColour, lightAlpha);
    ctx.fillRect(x + w * 0.13, lightY, lightWidth, lightHeight);
    ctx.fillRect(x + w * 0.87 - lightWidth, lightY, lightWidth, lightHeight);
    ctx.shadowBlur = 0;

    var indicators = indicatorSides(vehicle && vehicle.indicator);
    var blinkSeed = hashUnit(stringHash(vehicle && vehicle.id)) * 0.35;
    if (indicators.length && Math.floor((elapsed + blinkSeed) * 2.6) % 2 === 0) {
      ctx.fillStyle = rgba([255, 156, 27], 0.92 * alpha);
      for (var index = 0; index < indicators.length; index += 1) {
        var side = indicators[index];
        var indicatorX = side < 0 ? x + w * 0.08 : x + w * 0.88;
        ctx.fillRect(indicatorX, lightY + lightHeight * 0.2, Math.max(1, w * 0.04), Math.max(0.8, lightHeight * 0.8));
      }
    }

    if (vehicle && vehicle.emergency && closeDetail) {
      var beaconPhase = Math.floor((elapsed + blinkSeed) * 7.5) % 2;
      var beaconY = y + h * Math.max(0.08, spec.roof - 0.035);
      var beaconWidth = Math.max(1.4, w * 0.07);
      var beaconHeight = Math.max(1, h * 0.025);
      var beaconColours = beaconPhase ? [[34, 139, 255], [124, 213, 255]] : [[124, 213, 255], [34, 139, 255]];
      ctx.shadowColor = rgba(beaconColours[0], 0.92 * alpha);
      ctx.shadowBlur = Math.min(18, Math.max(3, w * 0.14));
      ctx.fillStyle = rgba(beaconColours[0], 0.96 * alpha);
      ctx.fillRect(x + w * 0.5 - beaconWidth * 1.08, beaconY, beaconWidth, beaconHeight);
      ctx.shadowColor = rgba(beaconColours[1], 0.92 * alpha);
      ctx.fillStyle = rgba(beaconColours[1], 0.96 * alpha);
      ctx.fillRect(x + w * 0.5 + beaconWidth * 0.08, beaconY, beaconWidth, beaconHeight);
      ctx.shadowBlur = 0;
    }
  }

  /**
   * Adds low-poly depth around a vehicle sprite.  Use pass:'underlay' before
   * the sprite and pass:'overlay' after it; pass:'all' produces a complete
   * dependency-free fallback vehicle.
   */
  function drawVehicleVolume(ctx, screenRect, vehicle, profileOrOptions, maybeOptions) {
    if (screenRect && typeof screenRect === 'object' && screenRect.vehicle && !vehicle) {
      var vehicleConfig = screenRect;
      screenRect = vehicleConfig.rect || vehicleConfig.screenRect || vehicleConfig;
      vehicle = vehicleConfig.vehicle;
      profileOrOptions = Object.assign({}, vehicleConfig.state || {}, vehicleConfig, vehicleConfig.options || {});
      maybeOptions = undefined;
    }
    var rect = normaliseRect(screenRect);
    if (!rect || !vehicle || typeof vehicle !== 'object') return false;
    if (!hasMethods(ctx, ['beginPath', 'moveTo', 'lineTo', 'closePath', 'fill', 'fillRect'])) return false;

    var options;
    var profile;
    if (isProfile(profileOrOptions)) {
      profile = profileOrOptions;
      options = maybeOptions && typeof maybeOptions === 'object' ? maybeOptions : {};
    } else {
      options = profileOrOptions && typeof profileOrOptions === 'object' ? profileOrOptions : {};
      profile = resolveProfile(options.profile || options.state || options, options);
    }
    var debug = options.debug;
    bump(debug, 'vehicleCalls');

    var pass = String(options.pass || 'all').toLowerCase();
    if (pass !== 'underlay' && pass !== 'overlay' && pass !== 'all') pass = 'all';
    var distance = finite(options.distance, finite(vehicle.distance, 0));
    var lodOptions = options;
    if (pass === 'overlay' && options.emissive == null && options.lights == null) {
      lodOptions = Object.assign({}, options, { emissive: true });
    }
    var explicitLodAlpha = finite(options.lodAlpha, NaN);
    var lodAlpha = Number.isFinite(explicitLodAlpha) ? clamp(explicitLodAlpha, 0, 1) : distanceLodAlpha(distance, profile, lodOptions);
    var alpha = clamp(finite(options.alpha, 1) * lodAlpha, 0, 1);
    if (alpha <= 0.004) {
      bump(debug, 'vehicleCulls');
      return false;
    }

    var kind = normaliseKind(vehicle);
    var spec = VEHICLE_SPECS[kind];
    var colour = parseColour(vehicle.colour || vehicle.color || options.colour || options.color);
    var yaw = clamp(finite(vehicle.heading, finite(vehicle.yaw, finite(options.yaw, 0))) * 1.8, -0.58, 0.58);

    var drawn = withContext(ctx, debug, function paintVehicleDepth() {
      ctx.globalAlpha *= alpha;
      if (pass === 'underlay' || pass === 'all') {
        drawVehicleShadow(ctx, rect, spec, 1);
        drawVehicleWheels(ctx, rect, spec, vehicle, options, 1, false);
        drawVehicleBody(ctx, rect, spec, colour, yaw, 1);
      }
      if (pass === 'overlay' || pass === 'all') {
        drawVehicleWheels(ctx, rect, spec, vehicle, options, 1, true);
        drawVehicleOverlayPass(ctx, rect, spec, vehicle, colour, profile, options, 1);
      }
    });
    bump(debug, drawn ? 'vehicleDraws' : 'vehicleCulls');
    return drawn;
  }

  function validPoint(point) {
    return !!point && Number.isFinite(finite(point.x, NaN)) && Number.isFinite(finite(point.y, NaN));
  }

  function projectSafe(projector, roadX, height, distance, debug) {
    try {
      var point = projector(roadX, height, distance);
      return validPoint(point) ? point : null;
    } catch (error) {
      bump(debug, 'projectorErrors');
      return null;
    }
  }

  function strokeSegment(ctx, fromX, fromY, toX, toY) {
    ctx.beginPath();
    ctx.moveTo(fromX, fromY);
    ctx.lineTo(toX, toY);
    ctx.stroke();
  }

  function lampWeatherScatter(weather) {
    if (weather === 'storm') return 1;
    if (weather === 'fog') return 0.92;
    if (weather === 'rain') return 0.82;
    if (weather === 'post-rain') return 0.46;
    return 0;
  }

  function drawLampEmission(ctx, x, y, radiusX, radiusY, pixelHeight, profile, seed) {
    if (!profile || profile.timeOfDay !== 'night') return false;
    if (!hasMethods(ctx, ['save', 'restore', 'beginPath', 'arc', 'fill', 'createRadialGradient'])) return false;

    var weather = normaliseWeather(profile.weather);
    var scatterStrength = lampWeatherScatter(weather);
    var wetRadiusFactor = scatterStrength > 0 ? (1.08 + scatterStrength * 0.22) : 1;
    var haloRadius = clamp(Math.max(radiusX * 6.4, pixelHeight * 0.19) * wetRadiusFactor, 3.5, 30);
    var haloAlpha = clamp(0.23 + scatterStrength * 0.075, 0, 0.31);
    var colour = profile.lampColour || TIME_PALETTES.night.lamp;

    return withContext(ctx, null, function paintLampEmission() {
      ctx.globalCompositeOperation = 'screen';
      var halo = ctx.createRadialGradient(x, y, 0, x, y, haloRadius);
      halo.addColorStop(0, rgba(colour, haloAlpha));
      halo.addColorStop(0.18, rgba(colour, haloAlpha * 0.62));
      halo.addColorStop(0.52, rgba(colour, haloAlpha * 0.20));
      halo.addColorStop(1, rgba(colour, 0));
      ctx.fillStyle = halo;
      ctx.beginPath();
      ctx.arc(x, y, haloRadius, 0, TAU);
      ctx.fill();

      // Wet air is source-local: a small mist pocket and a few stable spray
      // glints live around the projected lamp head, never on the road plane.
      if (scatterStrength <= 0) return;
      if (hasMethods(ctx, ['translate', 'scale'])) {
        withContext(ctx, null, function paintWetAirPocket() {
          var scatterRadius = clamp(haloRadius * (1.35 + scatterStrength * 0.22), 5, 44);
          ctx.translate(x, y + haloRadius * 0.10);
          ctx.scale(1, 0.58 + scatterStrength * 0.09);
          var scatter = ctx.createRadialGradient(0, 0, 0, 0, 0, scatterRadius);
          scatter.addColorStop(0, rgba(colour, 0.075 * scatterStrength));
          scatter.addColorStop(0.42, rgba(colour, 0.036 * scatterStrength));
          scatter.addColorStop(1, rgba(colour, 0));
          ctx.fillStyle = scatter;
          ctx.beginPath();
          ctx.arc(0, 0, scatterRadius, 0, TAU);
          ctx.fill();
        });
      }

      if (pixelHeight < 7 || (typeof ctx.ellipse !== 'function' && typeof ctx.arc !== 'function')) return;
      var dropletCount = pixelHeight > 24 ? 4 : 2;
      for (var index = 0; index < dropletCount; index += 1) {
        var dropletSeed = hashUnit(finite(seed, 0.5) * 701.9 + index * 43.17);
        var offsetSeed = hashUnit(finite(seed, 0.5) * 313.7 + index * 79.31);
        var dropletX = x + (dropletSeed - 0.5) * haloRadius * 1.35;
        var dropletY = y + (offsetSeed - 0.42) * haloRadius * 0.88;
        var dropletRadius = clamp(radiusY * (0.28 + dropletSeed * 0.28), 0.22, 0.8);
        ctx.fillStyle = rgba(colour, (0.10 + offsetSeed * 0.08) * scatterStrength);
        drawEllipse(ctx, dropletX, dropletY, dropletRadius, dropletRadius * 1.7);
      }
    });
  }

  function drawLamp(ctx, base, top, side, profile, scale) {
    var pixelHeight = Math.abs(base.y - top.y);
    if (pixelHeight < 2) return;
    ctx.strokeStyle = 'rgba(42,50,55,0.88)';
    ctx.lineWidth = clamp(pixelHeight * 0.025, 0.6, 4);
    strokeSegment(ctx, base.x, base.y, top.x, top.y);
    var arm = Math.max(2, pixelHeight * 0.11) * -side;
    strokeSegment(ctx, top.x, top.y, top.x + arm, top.y + pixelHeight * 0.018);
    var lampX = top.x + arm;
    var lampY = top.y + pixelHeight * 0.022;
    var lampRadiusX = Math.max(1, pixelHeight * 0.035);
    var lampRadiusY = Math.max(0.7, pixelHeight * 0.016);
    drawLampEmission(ctx, lampX, lampY, lampRadiusX, lampRadiusY, pixelHeight, profile, scale);

    if (profile.timeOfDay === 'night') {
      withContext(ctx, null, function paintLampCore() {
        ctx.globalCompositeOperation = 'screen';
        ctx.fillStyle = rgba(profile.lampColour, 0.98);
        drawEllipse(ctx, lampX, lampY, lampRadiusX, lampRadiusY);
        ctx.fillStyle = 'rgba(255,252,238,0.90)';
        drawEllipse(ctx, lampX, lampY, Math.max(0.45, lampRadiusX * 0.48), Math.max(0.32, lampRadiusY * 0.52));
      });
      return;
    }

    ctx.fillStyle = rgba(profile.lampColour, profile.timeOfDay === 'day' ? 0.16 : 0.78);
    drawEllipse(ctx, lampX, lampY, lampRadiusX, lampRadiusY);
  }

  function drawTree(ctx, base, top, seed, route) {
    var pixelHeight = Math.abs(base.y - top.y);
    if (pixelHeight < 2) return;
    var trunkWidth = clamp(pixelHeight * 0.08, 0.7, 9);
    ctx.strokeStyle = route === 'city' ? 'rgba(83,70,55,0.92)' : 'rgba(74,62,43,0.96)';
    ctx.lineWidth = trunkWidth;
    strokeSegment(ctx, base.x, base.y, top.x, top.y + pixelHeight * 0.32);
    var green = route === 'rural' ? [49, 78, 50] : [55, 84, 61];
    ctx.fillStyle = rgba(green, 0.93);
    drawEllipse(ctx, top.x, top.y + pixelHeight * 0.22, pixelHeight * (0.18 + seed * 0.035), pixelHeight * 0.25);
    ctx.fillStyle = rgba(scaleColour(green, 1.17), 0.46);
    drawEllipse(ctx, top.x - pixelHeight * 0.055, top.y + pixelHeight * 0.13, pixelHeight * 0.11, pixelHeight * 0.14);
  }

  function drawReflector(ctx, base, top, route) {
    var pixelHeight = Math.abs(base.y - top.y);
    if (pixelHeight < 1) return;
    ctx.strokeStyle = route === 'motorway' ? 'rgba(200,207,209,0.94)' : 'rgba(219,217,198,0.96)';
    ctx.lineWidth = clamp(pixelHeight * 0.13, 0.8, 4);
    strokeSegment(ctx, base.x, base.y, top.x, top.y);
    ctx.fillStyle = route === 'motorway' ? 'rgba(77,201,255,0.9)' : 'rgba(237,70,54,0.9)';
    drawEllipse(ctx, top.x, top.y + pixelHeight * 0.18, Math.max(0.8, pixelHeight * 0.09), Math.max(0.6, pixelHeight * 0.055));
  }

  function drawPole(ctx, base, top, side, seed) {
    var pixelHeight = Math.abs(base.y - top.y);
    if (pixelHeight < 2) return;
    ctx.strokeStyle = 'rgba(58,53,43,0.93)';
    ctx.lineWidth = clamp(pixelHeight * 0.035, 0.7, 4.5);
    strokeSegment(ctx, base.x, base.y, top.x, top.y);
    ctx.lineWidth = clamp(pixelHeight * 0.014, 0.5, 2);
    var arm = pixelHeight * (0.12 + seed * 0.035);
    strokeSegment(ctx, top.x - arm, top.y + pixelHeight * 0.03, top.x + arm, top.y + pixelHeight * 0.03);
  }

  function drawLowWall(ctx, base, top, side, route) {
    var pixelHeight = Math.abs(base.y - top.y);
    var width = Math.max(3, pixelHeight * (route === 'motorway' ? 3.2 : 2.4));
    var x = side < 0 ? base.x - width : base.x;
    ctx.fillStyle = route === 'rural' ? 'rgba(102,101,82,0.92)' : 'rgba(111,122,126,0.86)';
    ctx.fillRect(x, top.y, width, Math.max(1, base.y - top.y));
    ctx.fillStyle = 'rgba(203,207,200,0.26)';
    ctx.fillRect(x, top.y, width, Math.max(0.7, pixelHeight * 0.08));
  }

  function drawCityBuilding(ctx, projector, roadX, distance, side, seed, profile, debug) {
    var buildingWidth = 5.5 + seed * 4.5;
    var buildingHeight = 8 + hashUnit(seed * 190.2) * 10;
    var left = projectSafe(projector, roadX - buildingWidth * 0.5, 0, distance, debug);
    var right = projectSafe(projector, roadX + buildingWidth * 0.5, 0, distance, debug);
    var topLeft = projectSafe(projector, roadX - buildingWidth * 0.5, buildingHeight, distance, debug);
    var topRight = projectSafe(projector, roadX + buildingWidth * 0.5, buildingHeight, distance, debug);
    if (!left || !right || !topLeft || !topRight) return false;
    var warm = profile.timeOfDay === 'dusk';
    var face = warm ? [93, 79, 72] : [68, 78, 84];
    ctx.fillStyle = rgba(face, 0.94);
    fillPolygon(ctx, [[left.x, left.y], [right.x, right.y], [topRight.x, topRight.y], [topLeft.x, topLeft.y]]);

    var pixelWidth = Math.abs(right.x - left.x);
    var pixelHeight = Math.max(1, Math.abs(left.y - topLeft.y));
    if (pixelWidth > 12 && pixelHeight > 14) {
      var columns = clamp(Math.floor(pixelWidth / 11), 2, 7);
      var rows = clamp(Math.floor(pixelHeight / 13), 2, 7);
      var minX = Math.min(left.x, right.x);
      var minY = Math.min(topLeft.y, topRight.y);
      var windowColour = profile.timeOfDay === 'night' ? [239, 190, 108] : [128, 157, 170];
      for (var row = 0; row < rows; row += 1) {
        for (var column = 0; column < columns; column += 1) {
          if (hashUnit(seed * 1000 + row * 17 + column * 31) < 0.22) continue;
          ctx.fillStyle = rgba(windowColour, profile.timeOfDay === 'night' ? 0.62 : 0.34);
          ctx.fillRect(
            minX + (column + 0.25) * pixelWidth / columns,
            minY + (row + 0.30) * pixelHeight / (rows + 0.7),
            Math.max(1, pixelWidth / columns * 0.42),
            Math.max(1, pixelHeight / rows * 0.24)
          );
        }
      }
    }
    return true;
  }

  function drawMotorwayGantry(ctx, projector, extent, distance, profile, debug) {
    var halfSpan = Math.max(6.6, Math.abs(extent));
    var gantryHeight = 6.4;
    var leftBase = projectSafe(projector, -halfSpan, 0, distance, debug);
    var rightBase = projectSafe(projector, halfSpan, 0, distance, debug);
    var leftTop = projectSafe(projector, -halfSpan, gantryHeight, distance, debug);
    var rightTop = projectSafe(projector, halfSpan, gantryHeight, distance, debug);
    if (!leftBase || !rightBase || !leftTop || !rightTop) return false;
    var span = Math.abs(rightTop.x - leftTop.x);
    var pixelHeight = Math.max(1, Math.abs(leftBase.y - leftTop.y));
    if (span < 3 || pixelHeight < 2) return false;

    ctx.strokeStyle = 'rgba(103,116,121,0.94)';
    ctx.lineWidth = clamp(pixelHeight * 0.035, 0.65, 5);
    strokeSegment(ctx, leftBase.x, leftBase.y, leftTop.x, leftTop.y);
    strokeSegment(ctx, rightBase.x, rightBase.y, rightTop.x, rightTop.y);
    ctx.lineWidth = clamp(pixelHeight * 0.055, 0.8, 7);
    strokeSegment(ctx, leftTop.x, leftTop.y, rightTop.x, rightTop.y);
    ctx.lineWidth = clamp(pixelHeight * 0.018, 0.5, 2.5);
    strokeSegment(ctx, leftTop.x, leftTop.y + pixelHeight * 0.07, rightTop.x, rightTop.y + pixelHeight * 0.07);

    var minX = Math.min(leftTop.x, rightTop.x);
    var signWidth = span * 0.42;
    var signHeight = clamp(pixelHeight * 0.21, 1.5, 30);
    var signX = minX + span * 0.5 - signWidth * 0.5;
    var signY = (leftTop.y + rightTop.y) * 0.5 + pixelHeight * 0.06;
    ctx.fillStyle = profile.timeOfDay === 'night' ? 'rgba(12,47,68,0.96)' : 'rgba(12,83,118,0.96)';
    ctx.fillRect(signX, signY, signWidth, signHeight);
    ctx.fillStyle = 'rgba(232,244,241,0.84)';
    ctx.fillRect(signX + signWidth * 0.10, signY + signHeight * 0.29, signWidth * 0.50, Math.max(0.7, signHeight * 0.11));
    ctx.fillRect(signX + signWidth * 0.10, signY + signHeight * 0.57, signWidth * 0.31, Math.max(0.7, signHeight * 0.09));
    return true;
  }

  function drawNearProp(ctx, projector, route, type, roadX, distance, side, seed, profile, debug) {
    var heights = {
      lamp: route === 'motorway' ? 10.5 : 7.8,
      tree: route === 'rural' ? 7.2 : 5.8,
      reflector: route === 'motorway' ? 1.05 : 0.86,
      pole: 7.2,
      wall: route === 'motorway' ? 0.82 : 1.05
    };
    if (type === 'building') return drawCityBuilding(ctx, projector, roadX, distance, side, seed, profile, debug);
    if (type === 'gantry') return drawMotorwayGantry(ctx, projector, roadX, distance, profile, debug);
    var height = heights[type] || 1;
    var base = projectSafe(projector, roadX, 0, distance, debug);
    var top = projectSafe(projector, roadX, height, distance, debug);
    if (!base || !top) return false;
    if (type === 'lamp') drawLamp(ctx, base, top, side, profile, seed);
    else if (type === 'tree') drawTree(ctx, base, top, seed, route);
    else if (type === 'reflector') drawReflector(ctx, base, top, route);
    else if (type === 'pole') drawPole(ctx, base, top, side, seed);
    else drawLowWall(ctx, base, top, side, route);
    return true;
  }

  function chooseProp(route, seed) {
    if (route === 'city') {
      if (seed < 0.27) return 'building';
      if (seed < 0.53) return 'lamp';
      if (seed < 0.78) return 'tree';
      return 'reflector';
    }
    if (route === 'rural') {
      if (seed < 0.30) return 'wall';
      if (seed < 0.54) return 'reflector';
      if (seed < 0.79) return 'tree';
      return 'pole';
    }
    if (seed < 0.36) return 'reflector';
    if (seed < 0.66) return 'wall';
    if (seed < 0.88) return 'lamp';
    if (seed < 0.96) return 'pole';
    return 'gantry';
  }

  /**
   * Paints deterministic, projection-driven roadside geometry.  Props are
   * keyed to worldDistance, so they move with correct near-field parallax and
   * remain stable when frame rate changes.
   */
  function drawNearField(ctx, projector, state, options) {
    if (projector && typeof projector === 'object') {
      var nearFieldConfig = projector;
      projector = nearFieldConfig.project || nearFieldConfig.projector;
      state = nearFieldConfig.state || nearFieldConfig;
      options = Object.assign({}, nearFieldConfig.state || {}, nearFieldConfig, nearFieldConfig.options || {});
    }
    if (!hasMethods(ctx, ['beginPath', 'moveTo', 'lineTo', 'stroke', 'fill', 'fillRect'])) return 0;
    if (typeof projector !== 'function') return 0;
    state = state && typeof state === 'object' ? state : {};
    options = options && typeof options === 'object' ? options : {};
    var debug = options.debug;
    var route = normaliseRoute(state.routeId || state.route || options.routeId || options.route);
    var profile = resolveProfile(options.profile || state, options);
    var quality = String(options.quality || options.tier || state.quality || state.tier || state.graphicsQuality || 'enhanced').toLowerCase();
    var smooth = quality === 'smooth' || quality === 'low';
    var ultra = quality === 'ultra' || quality === 'cinematic' || quality === 'high';
    var spacing = finite(options.spacing, route === 'city' ? (smooth ? 28 : 21) : (smooth ? 23 : 17));
    spacing = clamp(spacing, 8, 80);
    var maxProps = clamp(finite(options.maxProps, smooth ? 18 : (ultra ? 46 : 30)), 1, 80);
    var atmosphereBudget = profile.visibilityEnd + (profile.weather === 'fog' ? 82 : (profile.weather === 'storm' ? 92 : 125));
    var maxDistance = clamp(
      finite(options.maxDistance, Math.min(profile.drawDistance - 5, smooth ? 390 : 485, atmosphereBudget)),
      30,
      profile.drawDistance
    );
    var worldDistance = finite(state.worldDistance, finite(state.distance, finite(options.worldDistance, 0)));
    var roadHalfWidth = finite(options.roadHalfWidth, route === 'motorway' ? 5.4 : 3.8);
    var density = clamp(finite(options.density, 1), 0, 2);
    if (density <= 0) return 0;

    var firstCell = Math.floor(worldDistance / spacing);
    var cellsAhead = Math.min(Math.ceil(maxDistance / spacing) + 2, Math.ceil(maxProps / Math.max(0.25, density)) + 4);
    var drawn = 0;
    bump(debug, 'nearFieldCalls');

    withContext(ctx, debug, function paintNearField() {
      // Far-to-near painter order keeps small distant props behind large close
      // ones and makes their parallax read without a depth buffer.
      for (var offset = cellsAhead; offset >= 0 && drawn < maxProps; offset -= 1) {
        var cell = firstCell + offset;
        var seed = hashUnit(cell * 13.73 + (route === 'city' ? 11 : route === 'rural' ? 29 : 47));
        if (seed > Math.min(0.97, 0.68 * density)) continue;
        var jitter = (hashUnit(cell * 31.19) - 0.5) * spacing * 0.42;
        var distance = cell * spacing + spacing * 0.5 + jitter - worldDistance;
        if (distance < 7 || distance > maxDistance) continue;
        var side = hashUnit(cell * 71.07) < 0.5 ? -1 : 1;
        var lateralBase = route === 'city' ? 3.0 : (route === 'motorway' ? 2.4 : 1.8);
        var lateral = roadHalfWidth + lateralBase + hashUnit(cell * 43.71) * (route === 'city' ? 5.2 : 2.8);
        var roadX = side * lateral;
        var type = chooseProp(route, hashUnit(cell * 97.31));
        // Close city geometry should pass with parallax rather than presenting
        // a giant facade immediately beside the rider's eye line.
        if (type === 'building' && distance < 30) type = hashUnit(cell * 151.3) < 0.5 ? 'lamp' : 'tree';
        var lod = distanceLodAlpha(distance, profile);
        if (lod <= 0.01) continue;
        var didDraw = false;
        withContext(ctx, debug, function paintOneNearProp() {
          ctx.globalAlpha *= lod;
          didDraw = drawNearProp(ctx, projector, route, type, roadX, distance, side, seed, profile, debug);
        });
        if (didDraw) drawn += 1;

        // Occasional paired elements create believable avenues, delineator
        // pairs and motorway furniture without doubling every cell.
        if (didDraw && type !== 'gantry' && drawn < maxProps && hashUnit(cell * 121.17) < (route === 'motorway' ? 0.42 : 0.20)) {
          var pairType = route === 'city' ? 'lamp' : (route === 'rural' ? 'reflector' : type);
          var pairDrawn = false;
          withContext(ctx, debug, function paintPairedNearProp() {
            ctx.globalAlpha *= lod * 0.92;
            pairDrawn = drawNearProp(ctx, projector, route, pairType, -roadX, distance + 0.6, -side, 1 - seed, profile, debug);
          });
          if (pairDrawn) drawn += 1;
        }
      }
    });
    bump(debug, 'nearFieldProps', drawn);
    return drawn;
  }

  function drawSpeedEdge(ctx, side, width, height, horizon, count, intensity, phase, clearCentre, colour, debug) {
    var edgeWidth = width * (1 - clearCentre) * 0.5;
    var innerX = side < 0 ? edgeWidth : width - edgeWidth;
    var painted = withContext(ctx, debug, function paintOneSpeedEdge() {
      ctx.beginPath();
      if (side < 0) {
        ctx.moveTo(0, horizon - height * 0.03);
        ctx.lineTo(innerX, horizon + height * 0.10);
        ctx.lineTo(innerX, height);
        ctx.lineTo(0, height);
      } else {
        ctx.moveTo(width, horizon - height * 0.03);
        ctx.lineTo(innerX, horizon + height * 0.10);
        ctx.lineTo(innerX, height);
        ctx.lineTo(width, height);
      }
      ctx.closePath();
      ctx.clip();

      var veil = ctx.createLinearGradient(side < 0 ? 0 : width, 0, side < 0 ? edgeWidth : width - edgeWidth, 0);
      veil.addColorStop(0, rgba(colour, intensity * 0.075));
      veil.addColorStop(1, rgba(colour, 0));
      ctx.fillStyle = veil;
      ctx.fillRect(side < 0 ? 0 : width - edgeWidth, horizon, edgeWidth, height - horizon);

      for (var index = 0; index < count; index += 1) {
        var seed = hashUnit(index * 43.17 + side * 103.9);
        var motion = (phase * (0.52 + seed * 0.78) + seed) % 1;
        var startY = horizon + (height - horizon) * (0.03 + motion * 0.78);
        var length = height * (0.022 + intensity * 0.10) * (0.55 + seed * 0.8);
        var edgeFraction = hashUnit(index * 67.3 + 9.1);
        var x = side < 0 ? edgeWidth * edgeFraction : width - edgeWidth * edgeFraction;
        var outward = side * -1;
        var slope = edgeWidth * (0.025 + motion * 0.075 + seed * 0.035);
        ctx.strokeStyle = rgba(colour, intensity * (0.055 + seed * 0.13));
        ctx.lineWidth = 0.5 + intensity * (0.7 + seed * 1.8);
        strokeSegment(ctx, x + outward * slope * 0.25, startY, x + outward * slope, Math.min(height, startY + length));
      }
    });
    if (painted) bump(debug, 'edgeStreaks', count);
    return painted;
  }

  /** Speed treatment clipped to road edges; the central eye line stays crisp. */
  function drawEdgeSpeed(ctx, width, height, horizonY, stateOrSpeed, options) {
    if (width && typeof width === 'object') {
      var edgeConfig = width;
      width = edgeConfig.width || (ctx && ctx.canvas && ctx.canvas.width);
      height = edgeConfig.height || (ctx && ctx.canvas && ctx.canvas.height);
      horizonY = edgeConfig.horizonY != null ? edgeConfig.horizonY : edgeConfig.horizon;
      stateOrSpeed = edgeConfig.state || edgeConfig;
      options = Object.assign({}, edgeConfig.state || {}, edgeConfig, edgeConfig.options || {});
    }
    var viewportWidth = finite(width, 0);
    var viewportHeight = finite(height, 0);
    if (!(viewportWidth > 1 && viewportHeight > 1)) return false;
    if (!hasMethods(ctx, ['beginPath', 'moveTo', 'lineTo', 'closePath', 'clip', 'stroke', 'fillRect', 'createLinearGradient'])) return false;
    options = options && typeof options === 'object' ? options : {};
    var state = stateOrSpeed && typeof stateOrSpeed === 'object' ? stateOrSpeed : {};
    var speed = finite(typeof stateOrSpeed === 'number' ? stateOrSpeed : state.speed, finite(options.speed, 0));
    if (options.reducedMotion === true || state.reducedMotion === true || speed < finite(options.threshold, 58)) return false;
    var quality = String(options.quality || options.tier || state.quality || state.tier || state.graphicsQuality || 'enhanced').toLowerCase();
    var smooth = quality === 'smooth' || quality === 'low';
    var ultra = quality === 'ultra' || quality === 'cinematic' || quality === 'high';
    var intensity = smoothstep(finite(options.threshold, 58), finite(options.fullSpeed, 132), speed);
    if (state.boosting || state.boost || options.boosting) intensity = clamp(intensity * 1.22 + 0.08, 0, 1);
    var horizon = clamp(finite(horizonY, viewportHeight * 0.43), 0, viewportHeight);
    var profile = resolveProfile(options.profile || state, options);
    var clearCentre = clamp(finite(options.clearCentre, profile.clearCentre), 0.42, 0.78);
    var elapsed = finite(options.elapsed, finite(state.elapsed, 0));
    var worldDistance = finite(options.worldDistance, finite(state.worldDistance, 0));
    var phase = elapsed * 1.7 + worldDistance * 0.013;
    var count = clamp(Math.round(finite(options.streaksPerSide, smooth ? 9 : (ultra ? 24 : 15))), 2, 36);
    var colour = profile.timeOfDay === 'night' ? [102, 139, 160] : mixColour(profile.foregroundColour, [224, 233, 233], 0.42);
    var debug = options.debug;
    bump(debug, 'edgePasses');

    return withContext(ctx, debug, function paintEdgeSpeed() {
      drawSpeedEdge(ctx, -1, viewportWidth, viewportHeight, horizon, count, intensity, phase, clearCentre, colour, debug);
      drawSpeedEdge(ctx, 1, viewportWidth, viewportHeight, horizon, count, intensity, phase, clearCentre, colour, debug);
    });
  }

  function createVisualDebugCounters(enabled) {
    return {
      enabled: enabled !== false,
      frames: 0,
      hazeCalls: 0,
      hazeBanks: 0,
      vehicleCalls: 0,
      vehicleDraws: 0,
      vehicleCulls: 0,
      nearFieldCalls: 0,
      nearFieldProps: 0,
      edgePasses: 0,
      edgeStreaks: 0,
      projectorErrors: 0,
      errors: 0
    };
  }

  function resetVisualDebugCounters(counters) {
    if (!counters || typeof counters !== 'object') return false;
    var enabled = counters.enabled !== false;
    var clean = createVisualDebugCounters(enabled);
    try {
      Object.keys(clean).forEach(function assignCounter(key) { counters[key] = clean[key]; });
      return true;
    } catch (error) {
      return false;
    }
  }

  function snapshotVisualDebugCounters(counters) {
    if (!counters || typeof counters !== 'object') return createVisualDebugCounters(false);
    var snapshot = {};
    Object.keys(createVisualDebugCounters()).forEach(function copyCounter(key) {
      snapshot[key] = key === 'enabled' ? counters.enabled !== false : finite(counters[key], 0);
    });
    return snapshot;
  }

  function beginVisualFrame(counters) {
    bump(counters, 'frames');
    return counters && counters.enabled !== false;
  }

  function drawVehicleUnderlay(ctx, screenRect, vehicle, profileOrOptions, maybeOptions) {
    var options;
    if (screenRect && typeof screenRect === 'object' && screenRect.vehicle && !vehicle) {
      return drawVehicleVolume(ctx, Object.assign({}, screenRect, { pass: 'underlay' }));
    }
    if (isProfile(profileOrOptions)) {
      options = Object.assign({}, maybeOptions || {}, { pass: 'underlay' });
      return drawVehicleVolume(ctx, screenRect, vehicle, profileOrOptions, options);
    }
    options = Object.assign({}, profileOrOptions || {}, { pass: 'underlay' });
    return drawVehicleVolume(ctx, screenRect, vehicle, options);
  }

  function drawVehicleOverlay(ctx, screenRect, vehicle, profileOrOptions, maybeOptions) {
    var options;
    if (screenRect && typeof screenRect === 'object' && screenRect.vehicle && !vehicle) {
      return drawVehicleVolume(ctx, Object.assign({}, screenRect, { pass: 'overlay', emissive: screenRect.emissive !== false }));
    }
    if (isProfile(profileOrOptions)) {
      options = Object.assign({}, maybeOptions || {}, { pass: 'overlay', emissive: !(maybeOptions && maybeOptions.emissive === false) });
      return drawVehicleVolume(ctx, screenRect, vehicle, profileOrOptions, options);
    }
    options = Object.assign({}, profileOrOptions || {}, { pass: 'overlay', emissive: !(profileOrOptions && profileOrOptions.emissive === false) });
    return drawVehicleVolume(ctx, screenRect, vehicle, options);
  }

  var namespace = globalScope.AvenraNextGenV300;
  if (!namespace || typeof namespace !== 'object') namespace = {};

  Object.assign(namespace, {
    visualVersion: VERSION,
    getAtmosphericProfile: getAtmosphericProfile,
    atmosphericProfile: getAtmosphericProfile,
    distanceLodAlpha: distanceLodAlpha,
    farLodAlpha: distanceLodAlpha,
    drawDepthHaze: drawDepthHaze,
    drawVehicleVolume: drawVehicleVolume,
    drawVehicleUnderlay: drawVehicleUnderlay,
    drawVehicleOverlay: drawVehicleOverlay,
    drawNearField: drawNearField,
    drawNearFieldProps: drawNearField,
    drawEdgeSpeed: drawEdgeSpeed,
    drawEdgeSpeedStreaks: drawEdgeSpeed,
    createVisualDebugCounters: createVisualDebugCounters,
    resetVisualDebugCounters: resetVisualDebugCounters,
    snapshotVisualDebugCounters: snapshotVisualDebugCounters,
    beginVisualFrame: beginVisualFrame
  });

  globalScope.AvenraNextGenV300 = namespace;
})(typeof window !== 'undefined' ? window : (typeof globalThis !== 'undefined' ? globalThis : this));
