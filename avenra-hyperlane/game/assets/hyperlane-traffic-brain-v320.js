/*
 * Avenra Hyperlane UK Traffic Brain v3.2.0
 *
 * A low-cost behavioural layer for photographic 2.5D traffic. It preserves
 * the compiled simulation's existing vehicle physics and manoeuvre metadata,
 * while exposing one consistent state vocabulary:
 *
 *   cruise -> follow -> brake -> signal -> reserve-gap -> change-lane -> settle
 *
 * The wrapper is intentionally advisory about lane choice unless a caller
 * explicitly opts into applying that advice. Existing director manoeuvres keep
 * ownership of position; this layer supplies reliable signals, gap state,
 * variable-limit compliance and diagnostics without teleporting traffic.
 */
(function attachUkTrafficBrain(globalScope) {
  'use strict';

  if (!globalScope) return;
  var namespace = globalScope.AvenraNextGenV300;
  if (!namespace || typeof namespace !== 'object') namespace = {};
  if (namespace.__trafficBrainV320Installed) return;

  var VERSION = '3.2.0';
  var MPH_TO_MPS = 0.44704;
  var PHASES = Object.freeze(['cruise', 'follow', 'brake', 'signal', 'reserve-gap', 'change-lane', 'settle']);
  var STATE_BY_ID = typeof Map === 'function' ? new Map() : null;
  var STATE_BY_OBJECT = typeof WeakMap === 'function' ? new WeakMap() : null;
  var useSerial = 0;

  var originalPlayerAwareSpeed = typeof namespace.playerAwareSpeed === 'function' ? namespace.playerAwareSpeed : null;

  var UK_FLEET_MIX = Object.freeze({
    city: Object.freeze([
      Object.freeze({ kind: 'hatchback', weight: 24 }),
      Object.freeze({ kind: 'estate', weight: 12 }),
      Object.freeze({ kind: 'saloon', weight: 14 }),
      Object.freeze({ kind: 'suv', weight: 12 }),
      Object.freeze({ kind: 'taxi', weight: 8 }),
      Object.freeze({ kind: 'delivery-van', weight: 13 }),
      Object.freeze({ kind: 'van', weight: 8 }),
      Object.freeze({ kind: 'bus', weight: 6 }),
      Object.freeze({ kind: 'coach', weight: 3 })
    ]),
    rural: Object.freeze([
      Object.freeze({ kind: 'hatchback', weight: 19 }),
      Object.freeze({ kind: 'estate', weight: 14 }),
      Object.freeze({ kind: 'saloon', weight: 11 }),
      Object.freeze({ kind: 'suv', weight: 15 }),
      Object.freeze({ kind: 'delivery-van', weight: 10 }),
      Object.freeze({ kind: 'van', weight: 8 }),
      Object.freeze({ kind: 'caravan', weight: 7 }),
      Object.freeze({ kind: 'motorhome', weight: 5 }),
      Object.freeze({ kind: 'horsebox', weight: 6 }),
      Object.freeze({ kind: 'coach', weight: 2 }),
      Object.freeze({ kind: 'lorry', weight: 3 })
    ]),
    motorway: Object.freeze([
      Object.freeze({ kind: 'hatchback', weight: 20 }),
      Object.freeze({ kind: 'estate', weight: 12 }),
      Object.freeze({ kind: 'saloon', weight: 12 }),
      Object.freeze({ kind: 'suv', weight: 13 }),
      Object.freeze({ kind: 'delivery-van', weight: 13 }),
      Object.freeze({ kind: 'van', weight: 8 }),
      Object.freeze({ kind: 'artic', weight: 12 }),
      Object.freeze({ kind: 'lorry', weight: 4 }),
      Object.freeze({ kind: 'coach', weight: 4 }),
      Object.freeze({ kind: 'motorhome', weight: 2 })
    ])
  });

  function clamp(value, minimum, maximum) {
    return Math.max(minimum, Math.min(maximum, value));
  }

  function finite(value, fallback) {
    var number = Number(value);
    return Number.isFinite(number) ? number : fallback;
  }

  function lower(value, fallback) {
    return String(value === undefined || value === null ? fallback : value).toLowerCase();
  }

  function damp(current, target, rate, deltaSeconds) {
    return current + (target - current) * (1 - Math.exp(-Math.max(0, rate) * Math.max(0, deltaSeconds)));
  }

  function routeIdFor(target) {
    var route = lower(target && target.routeId, 'city');
    return route === 'm1' || route === 'highway' ? 'motorway' : UK_FLEET_MIX[route] ? route : 'city';
  }

  function stableHash(value) {
    var text = String(value === undefined || value === null ? '' : value);
    var hash = 2166136261;
    for (var index = 0; index < text.length; index += 1) {
      hash ^= text.charCodeAt(index);
      hash = Math.imul(hash, 16777619);
    }
    return hash >>> 0;
  }

  function chooseUkFleetKind(routeId, randomValue, options) {
    var route = routeIdFor({ routeId: routeId });
    var mix = UK_FLEET_MIX[route];
    var settings = options || {};
    var excluded = Array.isArray(settings.exclude) ? settings.exclude : [];
    var allowed = mix.filter(function permitted(entry) { return excluded.indexOf(entry.kind) < 0; });
    if (!allowed.length) allowed = mix;
    var total = allowed.reduce(function sum(value, entry) { return value + entry.weight; }, 0);
    var random = clamp(finite(randomValue, 0.5), 0, 0.999999);
    var cursor = random * total;
    for (var index = 0; index < allowed.length; index += 1) {
      cursor -= allowed[index].weight;
      if (cursor < 0) return allowed[index].kind;
    }
    return allowed[allowed.length - 1].kind;
  }

  function assignUkVisualFleet(target, vehicle) {
    if (!vehicle || typeof vehicle !== 'object') return null;
    if (vehicle.visualKind) return String(vehicle.visualKind);
    var route = routeIdFor(target);
    var base = lower(vehicle.kind || vehicle.trafficKind || vehicle.vehicleType, 'saloon');
    if (base === 'deliveryvan' || base === 'delivery van') base = 'delivery-van';
    var random = (stableHash(route + ':' + String(vehicle.id === undefined ? base : vehicle.id)) % 1000) / 1000;
    var visual = base;
    if (base === 'car' || base === 'saloon') {
      if (route === 'city' && random < 0.12) visual = 'taxi';
      else if (random < (route === 'rural' ? 0.38 : 0.48)) visual = 'hatchback';
      else if (random < (route === 'rural' ? 0.72 : 0.68)) visual = 'estate';
      else visual = 'saloon';
    } else if (base === 'van') {
      visual = random < (route === 'city' ? 0.68 : route === 'motorway' ? 0.52 : 0.43) ? 'delivery-van' : 'van';
    } else if (base === 'motorhome' || base === 'camper' || base === 'rv') {
      if (route === 'city') visual = random < 0.72 ? 'bus' : 'coach';
      else if (route === 'rural') visual = random < 0.28 ? 'caravan' : random < 0.53 ? 'horsebox' : random < 0.63 ? 'coach' : 'motorhome';
      else visual = random < 0.22 ? 'coach' : random < 0.37 ? 'caravan' : 'motorhome';
    } else if (base === 'lorry' || base === 'hgv' || base === 'truck' || base === 'semi') {
      visual = route === 'motorway' && random < 0.72 ? 'artic' : 'lorry';
    }
    try {
      vehicle.visualKind = visual;
      vehicle.trafficFleetKind = visual;
    } catch (error) {}
    return visual;
  }

  function pruneIdStates() {
    if (!STATE_BY_ID || STATE_BY_ID.size <= 1536 || useSerial % 256 !== 0) return;
    var oldestUseful = useSerial - 4096;
    STATE_BY_ID.forEach(function prune(state, key) {
      if (!state || state.lastUsed < oldestUseful) STATE_BY_ID.delete(key);
    });
  }

  function createVehicleBrainState(vehicle) {
    var lane = finite(vehicle && vehicle.lane, finite(vehicle && vehicle.laneAnchor, 0));
    return {
      version: VERSION,
      phase: 'cruise',
      previousPhase: null,
      phaseElapsedSeconds: 0,
      enteredAtSeconds: 0,
      targetLane: lane,
      indicator: 0,
      signals: true,
      braking: false,
      reason: 'clear-road',
      sequence: 0,
      lastLane: lane,
      lastUsed: useSerial,
      telegraphUntilSeconds: 0,
      reservation: null,
      following: null,
      laneAdvice: null,
      desiredSpeedMph: finite(vehicle && vehicle.speed, finite(vehicle && vehicle.cruiseSpeed, 0))
    };
  }

  function vehicleState(vehicle) {
    useSerial += 1;
    var state;
    if (vehicle && vehicle.id !== undefined && vehicle.id !== null && STATE_BY_ID) {
      var key = String(vehicle.id);
      state = STATE_BY_ID.get(key);
      if (!state) {
        state = createVehicleBrainState(vehicle);
        STATE_BY_ID.set(key, state);
      }
      state.lastUsed = useSerial;
      pruneIdStates();
      return state;
    }
    if (vehicle && STATE_BY_OBJECT) {
      state = STATE_BY_OBJECT.get(vehicle);
      if (!state) {
        state = createVehicleBrainState(vehicle);
        STATE_BY_OBJECT.set(vehicle, state);
      }
      state.lastUsed = useSerial;
      return state;
    }
    return createVehicleBrainState(vehicle);
  }

  function weatherHeadway(target) {
    var weather = lower(target && target.weather, 'clear');
    if (weather === 'fog' || weather === 'storm') return 2.25;
    if (weather === 'rain' || weather === 'post-rain') return 1.9;
    return 1.55;
  }

  function leaderFor(vehicle, context) {
    var source = context || {};
    var leader = source.leader || vehicle && (vehicle.leader || vehicle.leadVehicle) || null;
    var gap = finite(
      source.leaderGapMetres,
      finite(vehicle && vehicle.leaderGapMetres, finite(vehicle && vehicle.leadGapMetres, finite(vehicle && vehicle.followingGapMetres, NaN)))
    );
    if (!Number.isFinite(gap) && leader && vehicle) gap = Math.abs(finite(leader.distance, 0) - finite(vehicle.distance, 0));
    return leader && Number.isFinite(gap) ? { vehicle: leader, gapMetres: Math.max(0, gap) } : null;
  }

  function followingAssessment(target, vehicle, context) {
    var leader = leaderFor(vehicle, context);
    if (!leader) return null;
    var speed = Math.max(0, finite(vehicle && vehicle.speed, finite(vehicle && vehicle.cruiseSpeed, 0)));
    var minimum = 4.5 + speed * MPH_TO_MPS * weatherHeadway(target);
    var leaderSpeed = Math.max(0, finite(leader.vehicle && leader.vehicle.speed, speed));
    var closing = Math.max(0, speed - leaderSpeed);
    return {
      leaderId: leader.vehicle && leader.vehicle.id,
      gapMetres: leader.gapMetres,
      minimumGapMetres: minimum,
      closingSpeedMph: closing,
      tooClose: leader.gapMetres < minimum,
      desiredSpeedMph: Math.max(0, Math.min(speed, leaderSpeed + clamp((leader.gapMetres - minimum) * 0.34, -16, 5)))
    };
  }

  function motorwayLaneBounds(target, context) {
    var settings = context || {};
    if (Array.isArray(settings.laneValues) && settings.laneValues.length) {
      var sorted = settings.laneValues.map(function number(value) { return finite(value, 0); }).sort(function ascending(a, b) { return a - b; });
      return { left: sorted[0], right: sorted[sorted.length - 1], step: sorted.length > 1 ? Math.abs(sorted[1] - sorted[0]) : 2 / 3 };
    }
    var laneCount = Math.max(2, Math.round(finite(settings.laneCount, finite(target && target.laneCount, 3))));
    var step = finite(settings.laneStep, 2 / 3);
    var half = step * (laneCount - 1) * 0.5;
    return { left: finite(settings.leftLane, -half), right: finite(settings.rightLane, half), step: step };
  }

  function recommendUkLane(target, vehicle, context) {
    var settings = context || {};
    var route = routeIdFor(target);
    var multiLane = route === 'motorway' || settings.multiLane === true || finite(settings.laneCount, finite(target && target.laneCount, 1)) > 1;
    var current = finite(vehicle && vehicle.lane, finite(vehicle && vehicle.laneAnchor, 0));
    var direction = finite(vehicle && vehicle.direction, 1) < 0 ? -1 : 1;
    if (!multiLane || direction < 0) {
      return { action: 'hold-lane', targetLane: current, indicator: 0, reason: direction < 0 ? 'opposing-flow' : 'single-carriageway', advisory: true };
    }

    var bounds = motorwayLaneBounds(target, settings);
    var follow = settings.following || followingAssessment(target, vehicle, settings);
    var needsPass = Boolean(follow && (follow.tooClose || follow.closingSpeedMph > 5) && follow.gapMetres < 58);
    var rightClear = settings.rightLaneClear !== false && settings.overtakeGapAccepted !== false;
    var leftClear = settings.leftLaneClear !== false && settings.returnGapAccepted !== false;
    if (needsPass && current < bounds.right - bounds.step * 0.25) {
      return rightClear ? {
        action: 'overtake-right', targetLane: Math.min(bounds.right, current + bounds.step), indicator: 1,
        reason: 'slower-traffic-ahead', advisory: true
      } : {
        action: 'follow', targetLane: current, indicator: 0, reason: 'offside-gap-not-clear', advisory: true
      };
    }
    if (!needsPass && current > bounds.left + bounds.step * 0.25) {
      return leftClear ? {
        action: 'return-left', targetLane: Math.max(bounds.left, current - bounds.step), indicator: -1,
        reason: 'pass-complete', advisory: true
      } : {
        action: 'hold-lane', targetLane: current, indicator: 0, reason: 'nearside-gap-not-clear', advisory: true
      };
    }
    return { action: 'keep-left', targetLane: bounds.left, indicator: 0, reason: 'uk-lane-discipline', advisory: true };
  }

  function normaliseManoeuvrePhase(vehicle) {
    var manoeuvre = vehicle && vehicle.manoeuvre && typeof vehicle.manoeuvre === 'object' ? vehicle.manoeuvre : {};
    var phase = lower(manoeuvre.phase || vehicle && vehicle.manoeuvrePhase, '');
    if (phase === 'braking') return 'brake';
    if (phase === 'signal' || phase === 'waiting') return 'signal';
    if (phase === 'reserve' || phase === 'reserve-gap' || phase === 'gap-check') return 'reserve-gap';
    if (phase === 'move-out' || phase === 'entering' || phase === 'change-lane' || phase === 'return') return 'change-lane';
    if (phase === 'settling' || phase === 'settle' || phase === 'recovering') return 'settle';
    if (phase === 'pass') return 'cruise';
    return null;
  }

  function desiredLaneFor(vehicle, advice) {
    var manoeuvre = vehicle && vehicle.manoeuvre && typeof vehicle.manoeuvre === 'object' ? vehicle.manoeuvre : {};
    var current = finite(vehicle && vehicle.lane, finite(vehicle && vehicle.laneAnchor, 0));
    var phase = lower(manoeuvre.phase, '');
    if (phase === 'return' || phase === 'settling') return finite(vehicle && vehicle.laneAnchor, current);
    return finite(vehicle && vehicle.targetLane, finite(manoeuvre.targetLane, finite(advice && advice.targetLane, current)));
  }

  function indicatorFor(vehicle, state, targetLane) {
    var manoeuvre = vehicle && vehicle.manoeuvre && typeof vehicle.manoeuvre === 'object' ? vehicle.manoeuvre : {};
    if (manoeuvre.signals === false || state.signals === false) return 0;
    var explicit = finite(vehicle && vehicle.indicator, finite(vehicle && vehicle.turnSignal, finite(manoeuvre.indicator, 0)));
    if (Math.abs(explicit) > 0.25) return explicit > 0 ? 1 : -1;
    var current = finite(vehicle && vehicle.lane, finite(vehicle && vehicle.laneAnchor, 0));
    if (Math.abs(targetLane - current) <= 0.018) return 0;
    return targetLane > current ? 1 : -1;
  }

  function protectTelegraphWindow(vehicle, elapsedSeconds) {
    var manoeuvre = vehicle && vehicle.manoeuvre;
    if (!manoeuvre || typeof manoeuvre !== 'object' || manoeuvre.signals === false) return;
    var phase = lower(manoeuvre.phase, '');
    if (phase !== 'signal' && phase !== 'waiting') return;
    var minimum = phase === 'signal' ? 0.85 : 0.75;
    var duration = finite(manoeuvre.phaseDuration, minimum);
    if (duration < minimum) {
      try { manoeuvre.phaseDuration = minimum; } catch (error) {}
    }
    if (!Number.isFinite(+manoeuvre.telegraphedAtSeconds)) {
      try { manoeuvre.telegraphedAtSeconds = elapsedSeconds; } catch (error) {}
    }
  }

  function reservationFor(target, vehicle, baseResult) {
    if (baseResult && baseResult.reservation) return baseResult.reservation;
    if (namespace.riderProxyReservation && typeof namespace.riderProxyReservation === 'function') {
      try { return namespace.riderProxyReservation(target || {}, vehicle || {}, {}); } catch (error) {}
    }
    return null;
  }

  function transitionState(state, phase, reason, elapsedSeconds) {
    if (PHASES.indexOf(phase) < 0) phase = 'cruise';
    if (state.phase !== phase) {
      state.previousPhase = state.phase;
      state.phase = phase;
      state.phaseElapsedSeconds = 0;
      state.enteredAtSeconds = elapsedSeconds;
      state.sequence += 1;
    }
    state.reason = reason;
  }

  function stepTrafficBrain(target, vehicle, context, deltaSeconds) {
    var runtime = target && typeof target === 'object' ? target : {};
    var traffic = vehicle && typeof vehicle === 'object' ? vehicle : {};
    var settings = context && typeof context === 'object' ? context : {};
    var dt = clamp(finite(deltaSeconds, finite(settings.deltaSeconds, 1 / 60)), 0, 0.25);
    var elapsed = Math.max(0, finite(runtime.elapsed, finite(runtime.elapsedSeconds, finite(settings.elapsedSeconds, 0))));
    assignUkVisualFleet(runtime, traffic);
    var state = vehicleState(traffic);
    state.phaseElapsedSeconds += dt;
    protectTelegraphWindow(traffic, elapsed);

    var following = followingAssessment(runtime, traffic, settings);
    var speedEnvelope = settings.baseSpeedResult || {};
    var cruiseSpeed = Math.max(0, finite(traffic.cruiseSpeed, finite(traffic.speed, 0)));
    var envelopeSpeed = Math.max(0, finite(speedEnvelope.desiredSpeedMph, cruiseSpeed));
    if (!following && speedEnvelope.variableLimitMph === undefined && lower(speedEnvelope.reaction, 'cruise') === 'cruise' && envelopeSpeed < cruiseSpeed - 1) {
      following = {
        leaderId: null,
        gapMetres: null,
        minimumGapMetres: null,
        closingSpeedMph: Math.max(0, cruiseSpeed - envelopeSpeed),
        tooClose: Boolean(speedEnvelope.braking),
        desiredSpeedMph: envelopeSpeed,
        inferredFromSpeedEnvelope: true
      };
    }
    var reservation = reservationFor(runtime, traffic, settings.baseSpeedResult);
    var advice = recommendUkLane(runtime, traffic, Object.assign({}, settings, { following: following }));
    var targetLane = desiredLaneFor(traffic, advice);
    var currentLane = finite(traffic.lane, finite(traffic.laneAnchor, targetLane));
    var explicitPhase = normaliseManoeuvrePhase(traffic);
    var baseBraking = Boolean(settings.baseSpeedResult && settings.baseSpeedResult.braking || traffic.braking || traffic.brake);
    var phase = 'cruise';
    var reason = 'clear-road';

    if (explicitPhase) {
      phase = explicitPhase;
      reason = 'existing-' + lower(traffic.manoeuvre && traffic.manoeuvre.kind, 'manoeuvre');
    } else if (baseBraking || following && following.tooClose && following.closingSpeedMph > 2) {
      phase = 'brake';
      reason = following && following.tooClose ? 'leader-gap' : 'existing-brake';
    } else if (reservation && reservation.reserved) {
      phase = 'reserve-gap';
      reason = reservation.reason || 'rider-reservation';
    } else if (Math.abs(targetLane - currentLane) > 0.035 && settings.applyLaneRecommendation === true) {
      if (state.targetLane !== targetLane) {
        state.telegraphUntilSeconds = elapsed + clamp(finite(settings.minimumSignalSeconds, 1.15), 0.75, 2.5);
      }
      if (elapsed < state.telegraphUntilSeconds) {
        phase = 'signal';
        reason = advice.reason;
      } else if (settings.gapAccepted === false) {
        phase = 'reserve-gap';
        reason = 'lane-gap-not-clear';
      } else {
        phase = 'change-lane';
        reason = advice.reason;
      }
    } else if (Math.abs(currentLane - state.lastLane) > 0.03) {
      phase = 'settle';
      reason = 'lane-change-complete';
    } else if (following) {
      phase = 'follow';
      reason = 'traffic-ahead';
    }

    transitionState(state, phase, reason, elapsed);
    state.targetLane = targetLane;
    state.indicator = (phase === 'signal' || phase === 'reserve-gap' || phase === 'change-lane') ? indicatorFor(traffic, state, targetLane) : 0;
    state.signals = !(traffic.manoeuvre && traffic.manoeuvre.signals === false);
    state.braking = phase === 'brake' || Boolean(settings.baseSpeedResult && settings.baseSpeedResult.braking);
    state.reservation = reservation;
    state.following = following;
    state.laneAdvice = advice;
    state.desiredSpeedMph = Math.max(0, finite(
      settings.baseSpeedResult && settings.baseSpeedResult.desiredSpeedMph,
      following ? following.desiredSpeedMph : finite(traffic.cruiseSpeed, finite(traffic.speed, 0))
    ));
    state.lastLane = currentLane;

    var snapshot = {
      version: VERSION,
      phase: state.phase,
      previousPhase: state.previousPhase,
      phaseElapsedSeconds: state.phaseElapsedSeconds,
      enteredAtSeconds: state.enteredAtSeconds,
      targetLane: state.targetLane,
      indicator: state.indicator,
      signals: state.signals,
      braking: state.braking,
      reason: state.reason,
      sequence: state.sequence,
      reservation: state.reservation,
      following: state.following,
      laneAdvice: state.laneAdvice,
      desiredSpeedMph: state.desiredSpeedMph
    };
    try { traffic.trafficBrainState = snapshot; } catch (error) {}
    return snapshot;
  }

  function stepTrafficBrainBatch(target, vehicles, deltaSeconds, options) {
    if (!Array.isArray(vehicles)) return [];
    var settings = options || {};
    var output = [];
    for (var index = 0; index < vehicles.length; index += 1) {
      var vehicle = vehicles[index];
      var leader = null;
      var bestGap = Infinity;
      for (var otherIndex = 0; otherIndex < vehicles.length; otherIndex += 1) {
        var other = vehicles[otherIndex];
        if (!other || other === vehicle || finite(other.direction, 1) !== finite(vehicle.direction, 1)) continue;
        if (Math.abs(finite(other.lane, 0) - finite(vehicle.lane, 0)) > finite(settings.laneTolerance, 0.28)) continue;
        var gap = (finite(other.distance, 0) - finite(vehicle.distance, 0)) * (finite(vehicle.direction, 1) < 0 ? -1 : 1);
        if (gap > 0 && gap < bestGap) {
          leader = other;
          bestGap = gap;
        }
      }
      output.push(stepTrafficBrain(target, vehicle, Object.assign({}, settings, {
        leader: leader,
        leaderGapMetres: bestGap
      }), deltaSeconds));
    }
    return output;
  }

  /* Journey gameplay owns the authoritative variable-limit schedule and phase.
     Traffic Brain deliberately consumes that immutable state instead of
     maintaining a competing clock. */
  function getJourneyVariableLimitState(target) {
    var state = target && typeof target === 'object' ? target : {};
    var variable = state.activeVariableLimit || state.nextGenV300 && state.nextGenV300.activeVariableLimit ||
      state.variableLimitState || state.nextGenV300 && state.nextGenV300.variableLimitState || null;
    if (!variable && typeof namespace.getVariableLimitState === 'function') {
      try { variable = namespace.getVariableLimitState(state); } catch (error) {}
    }
    return variable || null;
  }

  function getScheduledVariableLimit(target, scenarioId) {
    if (typeof namespace.getScheduledVariableLimit === 'function') {
      try { return namespace.getScheduledVariableLimit(target, scenarioId); } catch (error) {}
    }
    if (namespace.routeDirector && typeof namespace.routeDirector.getScheduledVariableLimit === 'function') {
      try { return namespace.routeDirector.getScheduledVariableLimit(target, scenarioId); } catch (error) {}
    }
    return null;
  }

  function resolveVariableLimit(target) {
    var state = target && typeof target === 'object' ? target : {};
    var variable = getJourneyVariableLimitState(state);
    if (variable && variable.active === true) {
      return Math.max(10, finite(variable.postedLimitMph, finite(variable.effectiveLimitMph, Infinity)));
    }
    if (state.speedLimitOverrideMph !== null && state.speedLimitOverrideMph !== undefined && Number.isFinite(+state.speedLimitOverrideMph)) {
      return Math.max(10, +state.speedLimitOverrideMph);
    }
    return Infinity;
  }

  function playerAwareSpeedV320(target, vehicle, desiredSpeedMph, deltaSeconds) {
    var state = target && typeof target === 'object' ? target : {};
    var traffic = vehicle && typeof vehicle === 'object' ? vehicle : {};
    assignUkVisualFleet(state, traffic);
    var dt = clamp(finite(deltaSeconds, 1 / 60), 0.001, 0.25);
    var limit = resolveVariableLimit(state);
    var sameDirection = finite(traffic.direction, 1) >= 0;
    var requested = Math.max(0, finite(desiredSpeedMph, finite(traffic.cruiseSpeed, finite(traffic.speed, 0))));
    if (sameDirection && Number.isFinite(limit)) requested = Math.min(requested, limit);
    var base = originalPlayerAwareSpeed ? originalPlayerAwareSpeed(state, traffic, requested, dt) : {
      speedMph: damp(Math.max(0, finite(traffic.speed, requested)), requested, 2.6, dt),
      desiredSpeedMph: requested,
      braking: requested < finite(traffic.speed, requested) - 0.5,
      reaction: 'traffic-brain-fallback'
    };
    if (!base || typeof base !== 'object') base = { speedMph: requested, desiredSpeedMph: requested, braking: false };
    if (sameDirection && Number.isFinite(limit)) {
      var current = Math.max(0, finite(traffic.speed, finite(base.speedMph, requested)));
      var compliant = damp(current, Math.min(finite(base.speedMph, requested), limit), current > limit + 8 ? 2.15 : 1.45, dt);
      base = Object.assign({}, base, {
        speedMph: Math.min(finite(base.speedMph, compliant), Math.max(limit, compliant)),
        desiredSpeedMph: Math.min(finite(base.desiredSpeedMph, requested), limit),
        braking: Boolean(base.braking || current > limit + 0.5),
        variableLimitMph: limit
      });
    }
    var brain = stepTrafficBrain(state, traffic, { baseSpeedResult: base }, dt);
    if (brain.indicator !== 0) {
      try {
        traffic.indicator = brain.indicator;
        traffic.trafficBrainManagedIndicator = true;
      } catch (error) {}
    } else if (traffic.trafficBrainManagedIndicator && !(traffic.manoeuvre && traffic.manoeuvre.signals !== false)) {
      try { traffic.indicator = 0; } catch (error) {}
    }
    return Object.assign({}, base, {
      braking: Boolean(base.braking || brain.braking),
      indicator: brain.indicator,
      trafficBrain: brain
    });
  }

  function applyLaneRecommendation(vehicle, advice) {
    if (!vehicle || !advice || !Number.isFinite(+advice.targetLane)) return false;
    try {
      vehicle.targetLane = +advice.targetLane;
      if (advice.indicator) vehicle.indicator = advice.indicator;
      return true;
    } catch (error) {
      return false;
    }
  }

  var api = Object.freeze({
    version: VERSION,
    phases: PHASES,
    fleetMix: UK_FLEET_MIX,
    chooseFleetKind: chooseUkFleetKind,
    assignVisualFleet: assignUkVisualFleet,
    stepVehicle: stepTrafficBrain,
    stepBatch: stepTrafficBrainBatch,
    recommendLane: recommendUkLane,
    applyLaneRecommendation: applyLaneRecommendation,
    getVariableLimitState: getJourneyVariableLimitState,
    getScheduledVariableLimit: getScheduledVariableLimit,
    resolveVariableLimitMph: resolveVariableLimit
  });

  namespace.__trafficBrainV320Installed = true;
  namespace.trafficBrainVersion = VERSION;
  namespace.ukTrafficBrain = api;
  namespace.chooseUkTrafficFleetKind = chooseUkFleetKind;
  namespace.assignUkTrafficVisualKind = assignUkVisualFleet;
  namespace.stepTrafficBrain = stepTrafficBrain;
  namespace.stepTrafficBrainBatch = stepTrafficBrainBatch;
  namespace.recommendUkTrafficLane = recommendUkLane;
  namespace.getTrafficBrainVariableLimitState = getJourneyVariableLimitState;
  namespace.resolveTrafficVariableLimitMph = resolveVariableLimit;
  namespace.playerAwareSpeed = playerAwareSpeedV320;

  namespace.dynamics = Object.assign(namespace.dynamics || {}, {
    playerAwareSpeed: playerAwareSpeedV320,
    stepTrafficBrain: stepTrafficBrain,
    recommendUkTrafficLane: recommendUkLane
  });
  namespace.routeDirector = Object.assign(namespace.routeDirector || {}, {
    trafficVariableLimitCompliance: Object.freeze({
      getState: getJourneyVariableLimitState,
      resolveLimitMph: resolveVariableLimit
    })
  });
  globalScope.AvenraNextGenV300 = namespace;
})(typeof window !== 'undefined' ? window : (typeof globalThis !== 'undefined' ? globalThis : this));
