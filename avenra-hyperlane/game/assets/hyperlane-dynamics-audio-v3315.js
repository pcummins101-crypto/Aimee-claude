/*
 * Avenrà Hyperlane 3.3.8 — rider dynamics, spatial audio and run helpers.
 *
 * This file is deliberately standalone. It does not alter the legacy game bundle;
 * it merges the following public APIs into window.AvenraNextGenV300:
 *
 * State and dynamics
 *   initializeNextGenState(target, options)
 *   resetNextGenState(target, options)
 *   configureRun(target, options)
 *   stepRiderDynamics(target, input)
 *   playerAwareSpeed(target, vehicle, desiredSpeedMph, deltaSeconds)
 *   createRiderSpringState()
 *   updateRiderSpringMetrics(springs, input)
 *
 * Audio
 *   initializeSpatialAudio(options)
 *   initSpatialAudio(options) // alias
 *   startSpatialAudioSamples(engine)
 *   resumeSpatialAudio(engine)
 *   setSpatialAudioMuted(engine, muted)
 *   updateSpatialAudio(engine, frame)
 *   updateSpatialAudioFrame(engine, target, deltaSeconds)
 *   disposeSpatialAudio(engine)
 *
 * Rider Rating
 *   createRatingAccumulator(options)
 *   sampleRating(accumulator, sample)
 *   recordRatingEvent(accumulator, type, detail)
 *   finalizeRating(accumulator)
 *   ratingCoaching(result, maximumTips)
 *   sampleRunRating(target, sample)
 *   recordRunRatingEvent(target, type, detail)
 *   getRating(target, finalize)
 *
 * Dynamic route director / Weekly Works Run
 *   SCENARIO_DECKS
 *   scenarioDeckForRoute(routeId)
 *   selectScenarioPlan(options)
 *   scenarioTelegraph(scenario, elapsedSeconds)
 *   stepDirector(target, deltaSeconds)
 *   stableHash(value)
 *   canonicalStringify(value)
 *   sha256Hex(value)
 *   weeklySpec(date, options)
 *   weeklySpecFromConfig(descriptor, options)
 *   weeklySpecHash(spec)
 */
(function installAvenraNextGenV320(root) {
  "use strict";

  var VERSION = "3.3.8";
  var MPH_TO_MPS = 0.44704;
  var STANDARD_GRAVITY = 9.80665;
  var TOP_SPEED_MPH = 132;
  var DEFAULT_MODE_SPEEDS = Object.freeze({ 1: 60, 2: 90, 3: 109 });
  var HANDLING_PROFILES = Object.freeze({ ARCADE: "arcade", ROAD: "road" });
  var WEATHER_GRIP = Object.freeze({ clear: 1, fog: 0.98, rain: 0.8, "post-rain": 0.88, storm: 0.68 });
  var ROUTE_GRIP = Object.freeze({ city: 0.98, rural: 0.94, motorway: 1 });

  var AUDIO_SAMPLE_PATHS = Object.freeze({
    hypercoreLow: "audio/hypercore-low.ogg",
    hypercoreMid: "audio/hypercore-mid.ogg",
    hypercoreHigh: "audio/hypercore-high.ogg",
    hypercoreRegen: "audio/hypercore-regen.ogg",
    hypercoreOverdrive: "audio/hypercore-overdrive.ogg",
    mechanicalHub: "audio/hypercore-mechanical-hub.ogg",
    wind: "audio/wind-loop.ogg",
    tyreDry: "audio/tyre-dry.ogg",
    tyreWet: "audio/tyre-wet.ogg",
    servicesAmbience: "audio/services-ambience.ogg",
    trafficCar: "audio/traffic-car.ogg",
    trafficVan: "audio/traffic-van.ogg",
    trafficHgv: "audio/traffic-hgv.ogg",
    trafficMotorcycle: "audio/traffic-motorcycle.ogg",
    busAirBrake: "audio/bus-air-brake.ogg",
    expansionJoint: "audio/expansion-joint.ogg",
    railArchCue: "audio/rail-arch-cue.ogg",
    hedgeFlyby: "audio/hedge-flyby.ogg"
  });
  var AUDIO_SOURCE_PROVENANCE = Object.freeze({
    kind: "original-synthetic-sound-design",
    fieldRecordings: false,
    thirdPartyRecordings: false,
    offlineCapable: true
  });

  /* The source loops deliberately retain their relative character, while
     these trims stop the low motor and HGV sound-design layers masking every
     other layer on small phone and tablet speakers. */
  var AUDIO_SAMPLE_TRIMS = Object.freeze({
    hypercoreLow: 0.52,
    hypercoreMid: 0.72,
    hypercoreHigh: 1,
    hypercoreRegen: 1,
    hypercoreOverdrive: 1,
    mechanicalHub: 0.84,
    wind: 1,
    tyreDry: 1.2,
    tyreWet: 0.92,
    servicesAmbience: 0.72,
    trafficCar: 1,
    trafficVan: 0.88,
    trafficHgv: 0.64,
    trafficMotorcycle: 1.08
  });

  var LOOP_SAMPLE_BUSES = Object.freeze({
    hypercoreLow: "bike",
    hypercoreMid: "bike",
    hypercoreHigh: "bike",
    hypercoreRegen: "bike",
    hypercoreOverdrive: "bike",
    mechanicalHub: "bike",
    wind: "road",
    tyreDry: "road",
    tyreWet: "road",
    servicesAmbience: "effects"
  });

  var TRAFFIC_VOICE_SETTINGS = Object.freeze({
    trafficCar: Object.freeze({ gain: 0.16, centreHz: 1320, passGain: 0.075, passSeconds: 0.34 }),
    trafficVan: Object.freeze({ gain: 0.17, centreHz: 980, passGain: 0.088, passSeconds: 0.4 }),
    trafficHgv: Object.freeze({ gain: 0.18, centreHz: 620, passGain: 0.105, passSeconds: 0.56 }),
    trafficMotorcycle: Object.freeze({ gain: 0.15, centreHz: 1860, passGain: 0.07, passSeconds: 0.3 })
  });

  var DEFAULT_AUDIO_PARAMETERS = Object.freeze({
    speedNormal: 0,
    load: 0,
    liftOff: 0,
    regen: 0,
    boost: 0,
    wetness: 0,
    largeVehicle: 0,
    crosswind: 0,
    tyreSlip: 0,
    mechanicalResonance: 0,
    servicesPresence: 0
  });

  function finiteNumber(value, fallback) {
    return Number.isFinite(value) ? value : fallback;
  }

  function clamp(value, minimum, maximum) {
    return Math.min(maximum, Math.max(minimum, finiteNumber(value, minimum)));
  }

  function lerp(from, to, amount) {
    return from + (to - from) * amount;
  }

  function smoothstep(edge0, edge1, value) {
    var width = edge1 - edge0;
    if (Math.abs(width) < 1e-9) return value < edge0 ? 0 : 1;
    var t = clamp((value - edge0) / width, 0, 1);
    return t * t * (3 - 2 * t);
  }

  function damp(current, target, rate, deltaSeconds) {
    return current + (target - current) * (1 - Math.exp(-Math.max(0, rate) * deltaSeconds));
  }

  function asymmetricDamp(current, target, attackRate, releaseRate, deltaSeconds) {
    return damp(current, target, target > current ? attackRate : releaseRate, deltaSeconds);
  }

  function normalizeRoute(routeId) {
    return routeId === "rural" || routeId === "motorway" ? routeId : "city";
  }

  function normalizeWeather(weather) {
    var normalized = String(weather || "clear").toLowerCase();
    if (normalized === "postrain" || normalized === "post_rain" || normalized === "after-rain" || normalized === "after rain" || normalized === "clearing") normalized = "post-rain";
    return Object.prototype.hasOwnProperty.call(WEATHER_GRIP, normalized) ? normalized : "clear";
  }

  function normalizeHandlingProfile(profile) {
    return profile === HANDLING_PROFILES.ROAD ? HANDLING_PROFILES.ROAD : HANDLING_PROFILES.ARCADE;
  }

  function modeSpeedLimit(input) {
    var explicit = finiteNumber(input && input.modeSpeedLimitMph, NaN);
    if (Number.isFinite(explicit)) return Math.max(0, explicit);
    var mode = input && input.rideMode;
    return DEFAULT_MODE_SPEEDS[mode] || DEFAULT_MODE_SPEEDS[3];
  }

  function createDynamicsState(profile) {
    return {
      handlingProfile: normalizeHandlingProfile(profile),
      brakePressure: 0,
      surfaceGrip: 1,
      steeringAuthority: 1,
      lateralDemand: 0,
      longitudinalGrip: 1,
      longitudinalG: 0,
      absLevel: 0,
      crosswind: 0,
      leanTarget: 0,
      previousSpeedMph: 0,
      targetSpeedMph: 0,
      boostActive: false
    };
  }

  function createSpringChannel() {
    return { value: 0, velocity: 0 };
  }

  function createRiderSpringState() {
    return {
      pitch: createSpringChannel(),
      headLag: createSpringChannel(),
      buffet: createSpringChannel(),
      roadResponse: createSpringChannel(),
      forkDive: createSpringChannel(),
      accelerationSquat: createSpringChannel(),
      suspensionHeave: createSpringChannel(),
      chassisRoll: createSpringChannel(),
      impactKick: createSpringChannel(),
      windYaw: createSpringChannel(),
      tyreSlip: createSpringChannel(),
      absPulse: createSpringChannel(),
      largeVehicleProximity: 0,
      tractorProximity: 0,
      metrics: {
        pitch: 0,
        headLag: 0,
        buffet: 0,
        roadResponse: 0,
        forkDive: 0,
        accelerationSquat: 0,
        suspensionHeave: 0,
        chassisRoll: 0,
        impactKick: 0,
        windYaw: 0,
        tyreSlip: 0,
        absPulse: 0,
        largeVehicleProximity: 0,
        tractorProximity: 0,
        cameraX: 0,
        cameraY: 0,
        cameraRotationDegrees: 0,
        cockpitX: 0,
        cockpitY: 0,
        cockpitRotationDegrees: 0,
        stableEyeline: true
      }
    };
  }

  function initializeNextGenState(target, options) {
    var state = target && typeof target === "object" ? target : {};
    var opts = options || {};
    var existing = state.nextGenV300 && typeof state.nextGenV300 === "object" ? state.nextGenV300 : {};
    var profile = normalizeHandlingProfile(opts.handlingProfile || existing.handlingProfile || state.handlingProfile);

    existing.handlingProfile = profile;
    existing.dynamics = Object.assign(createDynamicsState(profile), existing.dynamics || {});
    existing.dynamics.handlingProfile = profile;
    existing.springs = existing.springs || createRiderSpringState();
    existing.runSeed = (finiteNumber(opts.runSeed, finiteNumber(existing.runSeed, finiteNumber(state.trafficSeed, 1))) >>> 0) || 1;
    state.handlingProfile = profile;
    state.nextGenV300 = existing;
    return state;
  }

  function resetNextGenState(target, options) {
    var state = target && typeof target === "object" ? target : {};
    var opts = options || {};
    var previous = state.nextGenV300 || {};
    var profile = normalizeHandlingProfile(opts.handlingProfile || previous.handlingProfile || state.handlingProfile);
    var runSeed = (finiteNumber(opts.runSeed, finiteNumber(state.trafficSeed, previous.runSeed || 1)) >>> 0) || 1;

    state.handlingProfile = profile;
    state.nextGenV300 = {
      handlingProfile: profile,
      dynamics: createDynamicsState(profile),
      springs: createRiderSpringState(),
      runSeed: runSeed
    };
    state.nextGenV300.dynamics.previousSpeedMph = Math.max(0, finiteNumber(state.speed, 0));
    return state;
  }

  function configureRun(target, options) {
    var state = target && typeof target === "object" ? target : {};
    var opts = options || {};
    var suppliedWeekly = opts.weeklySpec && typeof opts.weeklySpec === "object" ? weeklySpecFromConfig(opts.weeklySpec, opts.weeklyOptions || opts) : null;
    var works = suppliedWeekly || (opts.weeklyWorks ? weeklySpec(opts.date, opts.weeklyOptions || opts) : null);
    var handlingProfile = normalizeHandlingProfile(opts.handlingProfile || works && works.handlingProfile || state.handlingProfile);
    var worksSeed = works ? finiteNumber(works.trafficSeed, finiteNumber(works.seed, 1)) : NaN;
    /* A new ride must never inherit brake, crosswind or camera-spring energy
       from the previous result/RIDE AGAIN cycle. */
    resetNextGenState(state, { handlingProfile: handlingProfile, runSeed: Number.isFinite(worksSeed) ? worksSeed : opts.runSeed });

    if (works) {
      state.routeId = works.routeId;
      state.timeOfDay = works.timeOfDay;
      state.weather = works.weather;
      state.rideMode = works.rideMode;
      state.runType = works.runType;
      state.weeklyChallengeId = works.challengeId;
      state.trafficSeed = worksSeed;
      state.trafficRngState = worksSeed;
      state.nextGenV300.runSeed = worksSeed;
    }

    var routeId = normalizeRoute(works ? works.routeId : opts.routeId || state.routeId);
    var scenarioSeed = finiteNumber(opts.scenarioSeed, works && works.scenarioSeed);
    if (!Number.isFinite(scenarioSeed)) scenarioSeed = stableHash((state.nextGenV300.runSeed || 1) + ":scenarios:" + routeId);
    var plan = Array.isArray(opts.scenarios) ? opts.scenarios.slice() : works && Array.isArray(works.scenarios) ? works.scenarios.slice() : selectScenarioPlan({
      routeId: routeId,
      seed: scenarioSeed,
      count: opts.scenarioCount == null ? 4 : opts.scenarioCount,
      durationSeconds: opts.durationSeconds || 90
    });

    state.handlingProfile = handlingProfile;
    state.nextGenV300.handlingProfile = handlingProfile;
    state.nextGenV300.dynamics.handlingProfile = handlingProfile;
    state.nextGenV300.weeklyWorks = Boolean(works);
    state.nextGenV300.weeklySpec = works;
    state.nextGenV300.scenarioPlan = plan;
    state.nextGenV300.director = {
      elapsedSeconds: 0,
      plan: plan,
      phases: {},
      telegraph: null,
      activeScenario: null,
      completedScenarioIds: [],
      emittedEvents: []
    };
    state.nextGenV300.rating = createRatingAccumulator({ routeId: routeId, handlingProfile: handlingProfile });
    return state;
  }

  function arcadeSpeedStep(speedMph, deltaSeconds, braking, boostRequested, boostLocked, limitMph) {
    var speed = Math.max(0, finiteNumber(speedMph, 0));
    var boostActive = Boolean(boostRequested && !boostLocked && !braking);
    var target = braking ? 0 : boostActive ? TOP_SPEED_MPH : Math.max(0, limitMph);
    var acceleration = speed < 62 ? 22.5 : boostActive ? 18.5 : 11.5;
    var nextSpeed = speed < target
      ? Math.min(target, speed + acceleration * deltaSeconds)
      : Math.max(target, speed - (braking ? 48 : 10) * deltaSeconds);

    return {
      speedMph: nextSpeed,
      targetSpeedMph: target,
      boostActive: boostActive,
      accelerationMphPerSecond: (nextSpeed - speed) / Math.max(0.001, deltaSeconds)
    };
  }

  function stepRiderDynamics(target, input) {
    var game = target && typeof target === "object" ? target : {};
    var frame = input || {};
    initializeNextGenState(game, frame);

    var dynamics = game.nextGenV300.dynamics;
    var profile = normalizeHandlingProfile(frame.handlingProfile || game.handlingProfile || dynamics.handlingProfile);
    var dt = clamp(finiteNumber(frame.deltaSeconds, 1 / 60), 0.001, 1 / 15);
    var speed = Math.max(0, finiteNumber(frame.speedMph, finiteNumber(game.speed, 0)));
    var braking = Boolean(frame.braking != null ? frame.braking : game.brake);
    var boostRequested = Boolean(frame.boostRequested != null ? frame.boostRequested : game.boost);
    var boostLocked = Boolean(frame.boostLocked != null ? frame.boostLocked : game.boostLocked);
    var limit = modeSpeedLimit(Object.assign({ rideMode: game.rideMode }, frame));
    var previousSpeed = finiteNumber(dynamics.previousSpeedMph, speed);

    dynamics.handlingProfile = profile;
    game.handlingProfile = profile;
    game.nextGenV300.handlingProfile = profile;

    if (profile === HANDLING_PROFILES.ARCADE) {
      var arcade = arcadeSpeedStep(speed, dt, braking, boostRequested, boostLocked, limit);
      dynamics.brakePressure = braking ? 1 : 0;
      dynamics.surfaceGrip = 1;
      dynamics.steeringAuthority = finiteNumber(frame.hazardSteeringMultiplier, 1);
      dynamics.lateralDemand = 0;
      dynamics.longitudinalGrip = 1;
      dynamics.longitudinalG = arcade.accelerationMphPerSecond * MPH_TO_MPS / STANDARD_GRAVITY;
      dynamics.absLevel = 0;
      dynamics.crosswind = 0;
      dynamics.leanTarget = clamp(finiteNumber(frame.steerInput, finiteNumber(game.steerInput, 0)) * 0.86 + finiteNumber(frame.lateralVelocity, finiteNumber(game.lateralVelocity, 0)) * 0.22, -1, 1);
      dynamics.previousSpeedMph = arcade.speedMph;
      dynamics.targetSpeedMph = arcade.targetSpeedMph;
      dynamics.boostActive = arcade.boostActive;
      return Object.assign({}, dynamics, arcade, {
        profile: profile,
        laneFollowRate: 7.4,
        gripLimited: false
      });
    }

    var route = normalizeRoute(frame.routeId || game.routeId);
    var weather = normalizeWeather(frame.weather || game.weather);
    var rawHazardGrip = frame.hazardGripMultiplier != null ? frame.hazardGripMultiplier : frame.activeHazardEffect && frame.activeHazardEffect.gripMultiplier;
    var rawHazardSteering = frame.hazardSteeringMultiplier != null ? frame.hazardSteeringMultiplier : frame.activeHazardEffect && frame.activeHazardEffect.steeringMultiplier;
    var hazardGrip = clamp(finiteNumber(rawHazardGrip, 1), 0.2, 1);
    var hazardSteering = clamp(finiteNumber(rawHazardSteering, 1), 0.2, 1);
    var surfaceGrip = clamp(WEATHER_GRIP[weather] * ROUTE_GRIP[route] * hazardGrip, 0.2, 1);
    var speedNormal = clamp(speed / TOP_SPEED_MPH, 0, 1);
    var steerInput = clamp(finiteNumber(frame.steerInput, finiteNumber(game.steerInput, 0)), -1, 1);
    var lateralVelocity = finiteNumber(frame.lateralVelocity, finiteNumber(game.lateralVelocity, 0));
    var brakePressure = damp(dynamics.brakePressure, braking ? 1 : 0, braking ? 12 : 18, dt);
    var lateralDemand = clamp(Math.abs(steerInput) * (0.22 + speedNormal * 0.68) + Math.abs(lateralVelocity) * 0.16, 0, 0.95);
    var longitudinalGrip = Math.sqrt(Math.max(0.12, 1 - lateralDemand * lateralDemand));
    var availableBrake = Math.max(18, 42 * surfaceGrip * longitudinalGrip);
    var requestedBrake = 42 * brakePressure;
    var actualBrake = Math.min(requestedBrake, availableBrake);
    var absLevel = clamp((requestedBrake - availableBrake) / 16, 0, 1);
    var boostActive = Boolean(boostRequested && !boostLocked && !braking);
    var targetSpeed = braking ? 0 : boostActive ? TOP_SPEED_MPH : limit;
    var baseAcceleration = speed < 62 ? 22.5 : boostActive ? 18.5 : 11.5;
    var actualAcceleration = Math.min(baseAcceleration, 26 * surfaceGrip * longitudinalGrip);
    var nextSpeed;

    if (speed < targetSpeed) {
      nextSpeed = Math.min(targetSpeed, speed + actualAcceleration * dt);
    } else {
      nextSpeed = Math.max(targetSpeed, speed - (braking ? actualBrake : 10) * dt);
    }
    if (braking && nextSpeed < 0.25) nextSpeed = 0;

    var seed = (finiteNumber(frame.runSeed, game.nextGenV300.runSeed) >>> 0) || 1;
    var worldDistance = finiteNumber(frame.worldDistance, finiteNumber(game.worldDistance, 0));
    var crosswindAmplitude = weather === "storm" ? 0.045 : weather === "rain" ? 0.012 : 0;
    var crosswindTarget = crosswindAmplitude * Math.pow(speedNormal, weather === "storm" ? 1.35 : 1) * Math.sin(worldDistance * (weather === "storm" ? 0.007 : 0.005) + seed * 0.001);
    var crosswind = damp(dynamics.crosswind, crosswindTarget, 2.6, dt);
    var steeringAuthority = lerp(0.82, 0.54, speedNormal) * Math.sqrt(surfaceGrip) * (1 - brakePressure * 0.28) * hazardSteering;
    var laneFollowRate = lerp(7, 4.6, speedNormal) * Math.sqrt(surfaceGrip);
    var leanTarget = clamp((steerInput * 0.82 + lateralVelocity * 0.2 + crosswind * 1.8) * steeringAuthority, -1, 1);
    var accelerationMphPerSecond = (nextSpeed - speed) / Math.max(0.001, dt);

    dynamics.brakePressure = brakePressure;
    dynamics.surfaceGrip = surfaceGrip;
    dynamics.steeringAuthority = steeringAuthority;
    dynamics.lateralDemand = lateralDemand;
    dynamics.longitudinalGrip = longitudinalGrip;
    dynamics.longitudinalG = accelerationMphPerSecond * MPH_TO_MPS / STANDARD_GRAVITY;
    dynamics.absLevel = absLevel;
    dynamics.crosswind = crosswind;
    dynamics.leanTarget = leanTarget;
    dynamics.previousSpeedMph = nextSpeed;
    dynamics.targetSpeedMph = targetSpeed;
    dynamics.boostActive = boostActive;

    return Object.assign({}, dynamics, {
      profile: profile,
      speedMph: nextSpeed,
      targetSpeedMph: targetSpeed,
      boostActive: boostActive,
      accelerationMphPerSecond: accelerationMphPerSecond,
      laneFollowRate: laneFollowRate,
      gripLimited: actualBrake + 0.001 < requestedBrake || actualAcceleration + 0.001 < baseAcceleration,
      availableBrakeMphPerSecond: availableBrake,
      requestedBrakeMphPerSecond: requestedBrake
    });
  }

  function playerAwareSpeed(target, vehicle, desiredSpeedMph, deltaSeconds) {
    var state = target && typeof target === "object" ? target : {};
    var trafficVehicle = vehicle && typeof vehicle === "object" ? vehicle : {};
    var dt = clamp(finiteNumber(deltaSeconds, 1 / 60), 0.001, 1 / 15);
    var weather = normalizeWeather(state.weather);
    var weatherFactor = weather === "fog" ? 0.86 : weather === "storm" ? 0.84 : weather === "rain" ? 0.94 : weather === "post-rain" ? 0.97 : 1;
    var targetSpeed = Math.max(0, finiteNumber(desiredSpeedMph, finiteNumber(trafficVehicle.cruiseSpeed, finiteNumber(trafficVehicle.speed, 0)))) * weatherFactor;
    var currentSpeed = Math.max(0, finiteNumber(trafficVehicle.speed, targetSpeed));
    var reaction = weather === "clear" ? "cruise" : "weather-adjusted";
    var distance = finiteNumber(trafficVehicle.distance, 999);
    var playerSpeed = Math.max(0, finiteNumber(state.speed, finiteNumber(state.speedMph, 0)));
    var playerLane = finiteNumber(state.lane, finiteNumber(state.playerLane, 0));
    var roadHalfWidth = Math.max(1, finiteNumber(state.roadHalfWidth, 3.8));
    var lateralMetres = Math.abs(finiteNumber(trafficVehicle.lane, 0) - playerLane) * roadHalfWidth;

    if (trafficVehicle.direction !== -1 && distance < -2 && distance > -78 && lateralMetres < 2.25) {
      var gap = Math.max(0, -distance - 3.2);
      var safeHeadway = weather === "fog" || weather === "storm" ? 2.35 : weather === "rain" ? 2 : weather === "post-rain" ? 1.82 : 1.65;
      var safeGap = 4 + currentSpeed * MPH_TO_MPS * safeHeadway;
      var closingCorrection = clamp((gap - safeGap) * 0.62, -18, 8);
      targetSpeed = Math.min(targetSpeed, Math.max(0, playerSpeed + closingCorrection));
      reaction = gap < safeGap ? "yielding-to-rider" : "tracking-rider";
    }

    var braking = targetSpeed < currentSpeed - 0.35;
    var responseRate = braking ? 2.7 * (0.82 + WEATHER_GRIP[weather] * 0.18) : 1.2;
    var nextSpeed = damp(currentSpeed, targetSpeed, responseRate, dt);
    return {
      speedMph: Math.max(0, nextSpeed),
      desiredSpeedMph: Math.max(0, targetSpeed),
      braking: braking,
      reaction: reaction,
      weatherFactor: weatherFactor
    };
  }

  var LARGE_VEHICLE_FACTORS = Object.freeze({
    van: 0.56, "delivery-van": 0.58, horsebox: 0.7, caravan: 0.7,
    motorhome: 0.78, bus: 0.92, coach: 0.94, tractor: 0.82,
    lorry: 1, artic: 1
  });

  function vehicleProximity(traffic, playerLane, roadHalfWidth) {
    var large = 0;
    var tractor = 0;
    var vehicles = Array.isArray(traffic) ? traffic : [];
    var lane = finiteNumber(playerLane, 0);
    var halfWidth = Math.max(1, finiteNumber(roadHalfWidth, 3.8));

    vehicles.forEach(function inspect(vehicle) {
      var resolvedKind = vehicleKindForAudio(vehicle);
      var factor = LARGE_VEHICLE_FACTORS[resolvedKind] || 0;
      var distance = finiteNumber(vehicle && vehicle.distance, 999);
      if (!factor || distance < -16 || distance > 34) return;
      var longitudinal = clamp(1 - Math.abs(distance - 5) / 29, 0, 1);
      var lateralMetres = Math.abs(finiteNumber(vehicle.lane, 0) - lane) * halfWidth;
      var lateral = clamp(1 - Math.max(0, lateralMetres - 0.7) / 3.8, 0, 1);
      var direction = vehicle.direction === -1 ? 1.12 : 1;
      var proximity = clamp(longitudinal * lateral * factor * direction, 0, 1);
      large = Math.max(large, proximity);
      if (resolvedKind === "tractor") tractor = Math.max(tractor, proximity);
    });
    return { largeVehicleProximity: large, tractorProximity: tractor };
  }

  function integrateSpring(channel, target, frequency, dampingRatio, deltaSeconds) {
    var remaining = clamp(deltaSeconds, 0.001, 1 / 15);
    while (remaining > 0) {
      var step = Math.min(remaining, 1 / 120);
      var omega = Math.PI * 2 * frequency;
      var acceleration = omega * omega * (target - channel.value) - 2 * dampingRatio * omega * channel.velocity;
      channel.velocity += acceleration * step;
      channel.value += channel.velocity * step;
      if (!Number.isFinite(channel.value) || !Number.isFinite(channel.velocity)) {
        channel.value = finiteNumber(target, 0);
        channel.velocity = 0;
      }
      remaining -= step;
    }
    return channel.value;
  }

  function updateRiderSpringMetrics(springs, input) {
    var state = springs && typeof springs === "object" ? springs : createRiderSpringState();
    state.chassisRoll = state.chassisRoll || createSpringChannel();
    state.impactKick = state.impactKick || createSpringChannel();
    var frame = input || {};
    var dt = clamp(finiteNumber(frame.deltaSeconds, 1 / 60), 0.001, 1 / 15);
    var speedNormal = clamp(finiteNumber(frame.speedMph, 0) / TOP_SPEED_MPH, 0, 1);
    var dynamics = frame.dynamics || {};
    var handlingProfile = normalizeHandlingProfile(frame.handlingProfile || dynamics.handlingProfile);
    var handlingScale = handlingProfile === HANDLING_PROFILES.ROAD ? 1 : 0.62;
    var longitudinalG = finiteNumber(frame.longitudinalG, finiteNumber(dynamics.longitudinalG, 0));
    var lateralVelocity = finiteNumber(frame.lateralVelocity, 0);
    var worldDistance = finiteNumber(frame.worldDistance, 0);
    var elapsed = finiteNumber(frame.elapsed, 0);
    var proximity = vehicleProximity(frame.traffic, frame.playerLane, frame.roadHalfWidth);
    var route = normalizeRoute(frame.routeId);
    var routeTexture = route === "rural" ? 0.72 : route === "city" ? frame.routeStage === "tunnel" ? 0.18 : 0.38 : 0.15;
    var roadWave = Math.sin(worldDistance * 1.83) * 0.58 + Math.sin(worldDistance * 4.91 + 1.7) * 0.27 + Math.sin(worldDistance * 0.47 + 0.8) * 0.15;
    var buffetWave = Math.sin(worldDistance * 2.17 + elapsed * 4.3) * 0.65 + Math.sin(worldDistance * 5.31 + 2.1) * 0.35;
    var hazardShake = clamp(finiteNumber(frame.hazardCameraShake, 0) * finiteNumber(frame.hazardStrength, 0), 0, 1.5);
    var absLevel = clamp(finiteNumber(frame.absLevel, finiteNumber(dynamics.absLevel, 0)), 0, 1);
    var crosswind = finiteNumber(frame.crosswind, finiteNumber(dynamics.crosswind, 0));
    var lateralDemand = clamp(finiteNumber(frame.lateralDemand, finiteNumber(dynamics.lateralDemand, 0)), 0, 1);
    var leanDemand = clamp(finiteNumber(frame.leanTarget, finiteNumber(dynamics.leanTarget, finiteNumber(frame.steerInput, 0))), -1, 1);
    var reducedMotion = Boolean(frame.reducedMotion);

    var motionScale = reducedMotion ? 0 : handlingScale;
    var pitchTarget = clamp(longitudinalG, -1, 0.65) * handlingScale;
    var forkDiveTarget = clamp(-longitudinalG, 0, 1);
    var squatTarget = clamp(longitudinalG, 0, 0.7);
    var headLagTarget = clamp(-lateralVelocity * 0.62 - leanDemand * 0.12, -0.78, 0.78) * handlingScale;
    var buffetTarget = buffetWave * proximity.largeVehicleProximity * Math.pow(speedNormal, 0.7) * motionScale;
    var roadTarget = (roadWave * routeTexture * Math.pow(speedNormal, 1.12) + hazardShake * Math.sin(elapsed * 89)) * motionScale;
    var heaveTarget = roadWave * routeTexture * 0.43 * Math.pow(speedNormal, 1.22) * motionScale;
    var rollTarget = clamp(-leanDemand * 0.72 - lateralVelocity * 0.08, -1, 1) * motionScale;
    var impactTarget = hazardShake * Math.sin(elapsed * 71) * motionScale;
    var windTarget = crosswind * 8 * motionScale;
    var tyreSlipTarget = lateralDemand * (1 - clamp(finiteNumber(dynamics.surfaceGrip, 1), 0, 1)) * motionScale;
    var absTarget = absLevel * Math.sin(elapsed * Math.PI * 20) * motionScale;

    var metrics = state.metrics || (state.metrics = {});
    metrics.pitch = integrateSpring(state.pitch, pitchTarget, 3.4, 0.9, dt);
    metrics.headLag = integrateSpring(state.headLag, headLagTarget, 4.4, 0.9, dt);
    metrics.buffet = integrateSpring(state.buffet, buffetTarget, 7, 0.65, dt);
    metrics.roadResponse = integrateSpring(state.roadResponse, roadTarget, 6.2, 0.72, dt);
    metrics.forkDive = integrateSpring(state.forkDive, forkDiveTarget, 3.8, 0.88, dt);
    metrics.accelerationSquat = integrateSpring(state.accelerationSquat, squatTarget, 2.9, 0.82, dt);
    metrics.suspensionHeave = integrateSpring(state.suspensionHeave, heaveTarget, 6.2, 0.72, dt);
    metrics.chassisRoll = integrateSpring(state.chassisRoll, rollTarget, 3.6, 0.92, dt);
    metrics.impactKick = integrateSpring(state.impactKick, impactTarget, 7.8, 0.74, dt);
    metrics.windYaw = integrateSpring(state.windYaw, windTarget, 2.2, 0.84, dt);
    metrics.tyreSlip = integrateSpring(state.tyreSlip, tyreSlipTarget, 5.4, 0.78, dt);
    metrics.absPulse = integrateSpring(state.absPulse, absTarget, 9, 0.7, dt);
    metrics.largeVehicleProximity = proximity.largeVehicleProximity;
    metrics.tractorProximity = proximity.tractorProximity;
    /* The rider's eye remains the visual anchor. Only a tiny residual head
       response is exposed to camera consumers; the motorcycle carries almost
       all lean, suspension, road and aerodynamic movement beneath it. */
    metrics.cameraX = clamp((metrics.headLag * 1.25 + metrics.buffet * 0.85 + metrics.windYaw * 0.12) * 0.16, -0.8, 0.8);
    metrics.cameraY = clamp((metrics.pitch * 0.48 + metrics.roadResponse * 0.18 + metrics.impactKick * 0.28) * 0.18, -0.46, 0.46);
    metrics.cameraRotationDegrees = clamp((metrics.buffet * 0.045 + metrics.headLag * 0.018 + metrics.windYaw * 0.018) * 0.18, -0.055, 0.055);
    metrics.cockpitX = clamp(metrics.headLag * 4.4 + metrics.buffet * 3.8 + metrics.windYaw * 1.65 + metrics.chassisRoll * 4.2, -15, 15);
    metrics.cockpitY = clamp(metrics.pitch * 4.2 + metrics.roadResponse * 2.25 + metrics.impactKick * 3.2 + metrics.forkDive * 9.5 - metrics.accelerationSquat * 5.4 + metrics.suspensionHeave * 4.2, -10, 16);
    metrics.cockpitRotationDegrees = clamp(metrics.chassisRoll * 1.18 + metrics.buffet * 0.18 + metrics.windYaw * 0.2 + metrics.tyreSlip * 0.52, -2.1, 2.1);
    metrics.stableEyeline = true;
    state.largeVehicleProximity = proximity.largeVehicleProximity;
    state.tractorProximity = proximity.tractorProximity;
    return metrics;
  }

  function safeDisconnect(node) {
    if (!node || typeof node.disconnect !== "function") return;
    try { node.disconnect(); } catch (error) { /* Already disconnected. */ }
  }

  function safeStop(node, when) {
    if (!node || typeof node.stop !== "function") return;
    try { node.stop(when); } catch (error) { /* Already stopped. */ }
  }

  function setAudioParam(parameter, value, now, smoothing) {
    if (!parameter) return;
    var safeValue = finiteNumber(value, 0);
    try {
      if (typeof parameter.setTargetAtTime === "function") {
        if (typeof parameter.cancelAndHoldAtTime === "function") parameter.cancelAndHoldAtTime(now);
        else if (typeof parameter.cancelScheduledValues === "function") {
          var heldValue = finiteNumber(parameter.value, safeValue);
          parameter.cancelScheduledValues(now);
          if (typeof parameter.setValueAtTime === "function") parameter.setValueAtTime(heldValue, now);
        }
        parameter.setTargetAtTime(safeValue, now, smoothing || 0.04);
      }
      else parameter.value = safeValue;
    } catch (error) {
      try { parameter.value = safeValue; } catch (ignored) { /* Audio node is shutting down. */ }
    }
  }

  function makeNoiseBuffer(context, seconds, amplitude) {
    var length = Math.max(1, Math.floor(context.sampleRate * seconds));
    var buffer = context.createBuffer(1, length, context.sampleRate);
    var data = buffer.getChannelData(0);
    var seed = 0x6d2b79f5;
    for (var index = 0; index < data.length; index += 1) {
      seed = (seed + 0x6d2b79f5) >>> 0;
      var value = seed;
      value = Math.imul(value ^ value >>> 15, value | 1);
      value ^= value + Math.imul(value ^ value >>> 7, value | 61);
      data[index] = ((((value ^ value >>> 14) >>> 0) / 4294967296) * 2 - 1) * amplitude;
    }
    return buffer;
  }

  function collectLoopCrossings(data, firstIndex, lastIndex, maximum) {
    var crossings = [];
    for (var index = firstIndex; index < lastIndex && crossings.length < maximum; index += 1) {
      var next = Math.min(data.length - 1, index + 1);
      var before = data[index];
      var after = data[next];
      if ((before <= 0 && after > 0) || (before >= 0 && after < 0)) {
        crossings.push({
          index: index,
          value: before,
          slope: after - before,
          direction: after >= before ? 1 : -1
        });
      }
    }
    return crossings;
  }

  function safeLoopPoints(buffer) {
    var duration = Math.max(0, finiteNumber(buffer && buffer.duration, 0));
    if (duration <= 0.1) return { start: 0, end: duration };
    var fallback = {
      start: Math.min(0.045, duration * 0.08),
      end: Math.max(0.05, Math.min(duration, duration - Math.min(0.055, duration * 0.08)))
    };
    if (!buffer || duration < 0.5 || typeof buffer.getChannelData !== "function") return fallback;
    try {
      var data = buffer.getChannelData(0);
      var rate = finiteNumber(buffer.sampleRate, data.length / duration);
      var startCandidates = collectLoopCrossings(data, Math.floor(rate * 0.025), Math.min(data.length - 2, Math.floor(rate * 0.2)), 96);
      var endCandidates = collectLoopCrossings(data, Math.max(1, data.length - Math.floor(rate * 0.2)), Math.max(2, data.length - Math.floor(rate * 0.025)), 96);
      var best = null;
      startCandidates.forEach(function compareStart(start) {
        endCandidates.forEach(function compareEnd(end) {
          if (start.direction !== end.direction || end.index - start.index < rate * 0.4) return;
          var slopeScale = Math.max(0.00001, Math.abs(start.slope) + Math.abs(end.slope));
          var score = Math.abs(start.value - end.value) * 6 + Math.abs(start.slope - end.slope) / slopeScale;
          if (!best || score < best.score) best = { score: score, start: start.index / rate, end: end.index / rate };
        });
      });
      if (best && best.end > best.start + 0.4) return { start: best.start, end: best.end };
    } catch (error) { /* A conservative trimmed loop remains available. */ }
    return fallback;
  }

  function configureLoopSource(engine, key, source, buffer) {
    source.buffer = buffer;
    source.loop = true;
    var points = engine.loopPoints[key] || safeLoopPoints(buffer);
    engine.loopPoints[key] = points;
    if (Number.isFinite(points.start)) source.loopStart = points.start;
    if (Number.isFinite(points.end) && points.end > points.start) source.loopEnd = points.end;
    return points;
  }

  function createLowPass(context, frequency) {
    var filter = context.createBiquadFilter();
    filter.type = "lowpass";
    filter.frequency.value = frequency;
    filter.Q.value = 0.18;
    return filter;
  }

  function createPanNode(context, pan) {
    if (typeof context.createStereoPanner === "function") {
      var panner = context.createStereoPanner();
      panner.pan.value = pan;
      return panner;
    }
    return context.createGain();
  }

  function createSampleMixGraph(context, master) {
    var buses = {};
    var filters = {};
    var sends = {};
    ["bike", "road", "traffic", "effects"].forEach(function createBus(name) {
      var bus = context.createGain();
      var filter = createLowPass(context, 15000);
      bus.gain.value = 1;
      bus.connect(filter).connect(master);
      buses[name] = bus;
      filters[name] = filter;
    });

    var reflectionInput = context.createGain();
    reflectionInput.gain.value = 1;
    ["bike", "road", "traffic"].forEach(function createSend(name) {
      var send = context.createGain();
      send.gain.value = name === "bike" ? 0.3 : name === "traffic" ? 0.22 : 0.15;
      buses[name].connect(send).connect(reflectionInput);
      sends[name] = send;
    });

    var reflectionFilter = createLowPass(context, 3600);
    var earlyDelayLeft = context.createDelay(0.18);
    var earlyDelayRight = context.createDelay(0.18);
    var tailDelay = context.createDelay(0.24);
    var earlyPanLeft = createPanNode(context, -0.42);
    var earlyPanRight = createPanNode(context, 0.46);
    var earlyGainLeft = context.createGain();
    var earlyGainRight = context.createGain();
    var tailFilter = createLowPass(context, 2450);
    var tailGain = context.createGain();
    var feedback = context.createGain();
    var wet = context.createGain();
    earlyDelayLeft.delayTime.value = 0.034;
    earlyDelayRight.delayTime.value = 0.071;
    tailDelay.delayTime.value = 0.118;
    earlyGainLeft.gain.value = 0.68;
    earlyGainRight.gain.value = 0.48;
    tailGain.gain.value = 0.42;
    feedback.gain.value = 0.12;
    wet.gain.value = 0;
    reflectionInput.connect(reflectionFilter);
    reflectionFilter.connect(earlyDelayLeft).connect(earlyPanLeft).connect(earlyGainLeft).connect(wet);
    reflectionFilter.connect(earlyDelayRight).connect(earlyPanRight).connect(earlyGainRight).connect(wet);
    reflectionFilter.connect(tailDelay).connect(tailFilter).connect(tailGain).connect(wet);
    tailFilter.connect(feedback).connect(tailDelay);
    wet.connect(master);

    return {
      buses: buses,
      filters: filters,
      sends: sends,
      reflections: {
        input: reflectionInput,
        filter: reflectionFilter,
        earlyDelayLeft: earlyDelayLeft,
        earlyDelayRight: earlyDelayRight,
        tailDelay: tailDelay,
        earlyPanLeft: earlyPanLeft,
        earlyPanRight: earlyPanRight,
        earlyGainLeft: earlyGainLeft,
        earlyGainRight: earlyGainRight,
        tailFilter: tailFilter,
        tailGain: tailGain,
        feedback: feedback,
        wet: wet
      }
    };
  }

  function chapterIdFor(state) {
    var chapter = state && (state.routeChapter || state.visualChapter || state.chapter);
    if (chapter && typeof chapter === "object") return String(chapter.id || chapter.chapterId || chapter.title || "").toLowerCase();
    return String(state && (state.chapterId || state.routeChapterId) || chapter || "").toLowerCase();
  }

  function inferAcousticZone(state) {
    var explicit = String(state.acousticZone || "").toLowerCase();
    var chapterId = chapterIdFor(state);
    if (explicit === "rail-arches" || explicit === "railway-arches" || /rail.*arch|arch.*rail/.test(chapterId)) return "rail-arches";
    if (explicit === "hedgerow" || /hedge/.test(chapterId)) return "hedgerow";
    if (explicit === "tunnel" || explicit === "underpass" || explicit === "services" || explicit === "village" || explicit === "roadworks") return explicit;
    if (explicit === "urban-canyon" || explicit === "built-up" || explicit === "motorway-built-up") return "built-up";
    if (explicit === "woodland") return "wooded";
    if (explicit === "motorway-cutting") return "cutting";
    if (explicit === "open" || explicit === "open-city" || explicit === "open-rural" || explicit === "a-road" || explicit === "motorway-open") return "open";
    if (state.inTunnel) return "tunnel";
    if (state.underpassActive) return "underpass";
    if (state.routeId === "city" && state.routeStage === "tunnel") return "tunnel";
    if (state.routeId === "rural" && state.routeStage === "tunnel") return "village";
    if (/service/.test(chapterId)) return "services";
    if (/underpass/.test(chapterId)) return "underpass";
    if (/tunnel/.test(chapterId)) return "tunnel";
    if (/wood/.test(chapterId)) return "wooded";
    if (/cutting/.test(chapterId)) return "cutting";
    if (state.routeId === "motorway") {
      var elapsed = Math.max(0, finiteNumber(state.elapsed, -99));
      if (elapsed >= 37 && elapsed <= 50) return "roadworks";
      if ([15, 34, 63, 78].some(function nearService(seconds) { return Math.abs(elapsed - seconds) <= 2.1; })) return "services";
    }
    return "open";
  }

  function acousticSnapshot(state) {
    var route = normalizeRoute(state.routeId);
    var weather = normalizeWeather(state.weather);
    var time = state.timeOfDay === "night" || state.timeOfDay === "dusk" ? state.timeOfDay : "day";
    var zone = inferAcousticZone(state);
    var chapterId = chapterIdFor(state);
    var profile = {
      route: route,
      zone: zone,
      chapterId: chapterId,
      bikeGain: 1,
      roadGain: 1,
      trafficGain: 1,
      effectsGain: 1,
      bikeCutoff: 14800,
      roadCutoff: 12800,
      trafficCutoff: 13800,
      effectsCutoff: 14800,
      windScale: 1,
      tyreScale: 1,
      reflectionWet: 0,
      reflectionCutoff: 3600,
      tailFeedback: 0.12
    };

    if (route === "city") {
      profile.roadGain = 0.94;
      profile.trafficGain = 1.04;
      profile.roadCutoff = 10800;
    } else if (route === "rural") {
      profile.bikeGain = 1.03;
      profile.roadGain = 0.9;
      profile.trafficGain = 0.94;
      profile.windScale = 1.08;
      profile.roadCutoff = 14200;
    } else {
      profile.bikeGain = 0.98;
      profile.roadGain = 1.09;
      profile.trafficGain = 1.08;
      profile.windScale = 1.12;
      profile.roadCutoff = 11800;
    }

    if (time === "night") {
      profile.roadGain *= 0.93;
      profile.trafficGain *= 0.91;
      profile.trafficCutoff *= 0.88;
    } else if (time === "dusk") {
      profile.trafficGain *= 0.97;
    }

    if (weather === "rain" || weather === "post-rain" || weather === "storm") {
      var storm = weather === "storm";
      var postRain = weather === "post-rain";
      profile.roadGain *= storm ? 1.15 : postRain ? 1.055 : 1.09;
      profile.trafficGain *= storm ? 0.94 : postRain ? 1 : 0.98;
      profile.windScale *= storm ? 1.28 : postRain ? 1.01 : 1.08;
      profile.tyreScale *= storm ? 1.18 : postRain ? 1.1 : 1.08;
      profile.bikeCutoff = storm ? 7200 : postRain ? 10800 : 9200;
      profile.trafficCutoff = storm ? 6800 : postRain ? 10100 : 8600;
      profile.reflectionCutoff = storm ? 2700 : postRain ? 3400 : 3200;
    } else if (weather === "fog") {
      profile.roadGain *= 0.86;
      profile.trafficGain *= 0.92;
      profile.roadCutoff = 6900;
      profile.trafficCutoff = 6200;
      profile.windScale *= 0.78;
    }

    if (zone === "tunnel") {
      profile.bikeGain *= 1.08;
      profile.roadGain *= 1.06;
      profile.trafficGain *= 1.03;
      profile.windScale *= 0.54;
      profile.bikeCutoff = Math.min(profile.bikeCutoff, 7600);
      profile.roadCutoff = Math.min(profile.roadCutoff, 5600);
      profile.trafficCutoff = Math.min(profile.trafficCutoff, 6300);
      profile.reflectionWet = 0.17;
      profile.reflectionCutoff = 2900;
      profile.tailFeedback = 0.155;
    } else if (zone === "rail-arches") {
      profile.bikeGain *= 1.035;
      profile.roadGain *= 1.025;
      profile.windScale *= 0.68;
      profile.reflectionWet = 0.115;
      profile.reflectionCutoff = 3250;
      profile.tailFeedback = 0.105;
    } else if (zone === "underpass") {
      profile.windScale *= 0.78;
      profile.reflectionWet = 0.085;
      profile.reflectionCutoff = 3400;
      profile.tailFeedback = 0.08;
    } else if (zone === "services") {
      profile.trafficGain *= 1.07;
      profile.roadGain *= 0.97;
      profile.effectsGain *= 1.08;
    } else if (zone === "village") {
      profile.roadGain *= 0.88;
      profile.trafficGain *= 0.95;
      profile.windScale *= 0.82;
    } else if (zone === "built-up") {
      profile.roadGain *= 0.94;
      profile.trafficGain *= 1.02;
      profile.windScale *= 0.72;
      profile.reflectionWet = Math.max(profile.reflectionWet, 0.038);
      profile.reflectionCutoff = Math.min(profile.reflectionCutoff, 4800);
      profile.tailFeedback = Math.max(profile.tailFeedback, 0.052);
    } else if (zone === "hedgerow") {
      profile.roadGain *= 0.96;
      profile.trafficGain *= 0.97;
      profile.windScale *= 0.64;
      profile.reflectionWet = Math.max(profile.reflectionWet, 0.018);
      profile.reflectionCutoff = Math.min(profile.reflectionCutoff, 5900);
      profile.tailFeedback = Math.max(profile.tailFeedback, 0.028);
    } else if (zone === "wooded") {
      profile.roadGain *= 0.92;
      profile.trafficGain *= 0.95;
      profile.windScale *= 0.74;
      profile.reflectionWet = Math.max(profile.reflectionWet, 0.026);
      profile.reflectionCutoff = Math.min(profile.reflectionCutoff, 5200);
      profile.tailFeedback = Math.max(profile.tailFeedback, 0.035);
    } else if (zone === "cutting") {
      profile.trafficGain *= 1.03;
      profile.windScale *= 0.82;
      profile.reflectionWet = Math.max(profile.reflectionWet, 0.045);
      profile.reflectionCutoff = Math.min(profile.reflectionCutoff, 4600);
      profile.tailFeedback = Math.max(profile.tailFeedback, 0.058);
    } else if (zone === "roadworks") {
      profile.roadGain *= 1.06;
      profile.trafficGain *= 0.95;
      profile.trafficCutoff = Math.min(profile.trafficCutoff, 8600);
    }
    return profile;
  }

  function updateAcousticSnapshot(engine, state, now) {
    var profile = acousticSnapshot(state);
    if (finiteNumber(engine.reflectionExcitationUntil, -1) > now) {
      profile.reflectionWet = Math.max(profile.reflectionWet, 0.145);
      profile.tailFeedback = Math.max(profile.tailFeedback, 0.115);
    }
    engine.snapshot = profile;
    setAudioParam(engine.buses.bike.gain, profile.bikeGain, now, 0.18);
    setAudioParam(engine.buses.road.gain, profile.roadGain, now, 0.2);
    setAudioParam(engine.buses.traffic.gain, profile.trafficGain, now, 0.18);
    setAudioParam(engine.buses.effects.gain, profile.effectsGain, now, 0.16);
    setAudioParam(engine.busFilters.bike.frequency, profile.bikeCutoff, now, 0.2);
    setAudioParam(engine.busFilters.road.frequency, profile.roadCutoff, now, 0.22);
    setAudioParam(engine.busFilters.traffic.frequency, profile.trafficCutoff, now, 0.2);
    setAudioParam(engine.busFilters.effects.frequency, profile.effectsCutoff, now, 0.18);
    setAudioParam(engine.reflections.filter.frequency, profile.reflectionCutoff, now, 0.22);
    setAudioParam(engine.reflections.feedback.gain, profile.tailFeedback, now, 0.28);
    setAudioParam(engine.reflections.wet.gain, profile.reflectionWet, now, profile.reflectionWet > engine.reflections.wet.gain.value ? 0.3 : 0.7);
    return profile;
  }

  function createProceduralFallback(engine) {
    var context = engine.context;
    var motor = context.createOscillator();
    var harmonic = context.createOscillator();
    var overdrive = context.createOscillator();
    var motorGain = context.createGain();
    var harmonicGain = context.createGain();
    var overdriveGain = context.createGain();
    var motorFilter = context.createBiquadFilter();

    motor.type = "sine";
    harmonic.type = "triangle";
    overdrive.type = "sine";
    motor.frequency.value = 90;
    harmonic.frequency.value = 180;
    overdrive.frequency.value = 540;
    motorGain.gain.value = 0;
    harmonicGain.gain.value = 0;
    overdriveGain.gain.value = 0;
    motorFilter.type = "lowpass";
    motorFilter.frequency.value = 2300;
    motor.connect(motorGain).connect(motorFilter);
    harmonic.connect(harmonicGain).connect(motorFilter);
    overdrive.connect(overdriveGain).connect(motorFilter);
    motorFilter.connect(engine.buses.bike);

    var noise = context.createBufferSource();
    noise.buffer = makeNoiseBuffer(context, 2, 0.45);
    noise.loop = true;
    var windFilter = context.createBiquadFilter();
    var windGain = context.createGain();
    windFilter.type = "bandpass";
    windFilter.frequency.value = 620;
    windFilter.Q.value = 0.42;
    windGain.gain.value = 0;
    noise.connect(windFilter).connect(windGain).connect(engine.buses.road);

    motor.start();
    harmonic.start();
    overdrive.start();
    noise.start();
    return {
      motor: motor,
      harmonic: harmonic,
      overdrive: overdrive,
      motorGain: motorGain,
      harmonicGain: harmonicGain,
      overdriveGain: overdriveGain,
      motorFilter: motorFilter,
      noise: noise,
      windFilter: windFilter,
      windGain: windGain
    };
  }

  function decodeAudioBuffer(context, arrayBuffer) {
    return new Promise(function decode(resolve, reject) {
      var settled = false;
      function done(buffer) {
        if (settled) return;
        settled = true;
        resolve(buffer);
      }
      function fail(error) {
        if (settled) return;
        settled = true;
        reject(error || new Error("Unable to decode audio"));
      }
      try {
        var result = context.decodeAudioData(arrayBuffer.slice(0), done, fail);
        if (result && typeof result.then === "function") result.then(done, fail);
      } catch (error) {
        fail(error);
      }
    });
  }

  function resolveAudioUrl(path, options) {
    var base = options.baseUrl || (typeof document !== "undefined" ? document.baseURI : "http://localhost/");
    var url = new URL(path, base);
    if (typeof location !== "undefined" && url.origin !== location.origin) throw new Error("Hyperlane audio samples must be same-origin");
    return url.href;
  }

  function installLoopSample(engine, key, buffer) {
    if (engine.disposed || !buffer || engine.sampleNodes[key]) return;
    var source = engine.context.createBufferSource();
    var gain = engine.context.createGain();
    var points = configureLoopSource(engine, key, source, buffer);
    gain.gain.value = 0;
    source.connect(gain);
    var busName = LOOP_SAMPLE_BUSES[key] || "road";
    gain.connect(engine.buses[busName] || engine.buses.road);
    source.start();
    engine.sampleNodes[key] = { source: source, gain: gain, loopStart: points.start, loopEnd: points.end };
  }

  function isLoopSampleKey(key) {
    return Object.prototype.hasOwnProperty.call(LOOP_SAMPLE_BUSES, key);
  }

  function vehicleKindForAudio(vehicle) {
    if (vehicle && typeof vehicle === "object") return String(vehicle.trafficFleetKind || vehicle.visualKind || vehicle.kind || "saloon");
    return String(vehicle || "saloon");
  }

  function trafficFamily(vehicleKind) {
    vehicleKind = vehicleKindForAudio(vehicleKind);
    if (vehicleKind === "horse" || vehicleKind === "cyclist") return null;
    if (vehicleKind === "lorry" || vehicleKind === "artic" || vehicleKind === "tractor" || vehicleKind === "bus" || vehicleKind === "coach") return "trafficHgv";
    if (vehicleKind === "van" || vehicleKind === "delivery-van" || vehicleKind === "motorhome" || vehicleKind === "caravan" || vehicleKind === "horsebox") return "trafficVan";
    if (vehicleKind === "motorcycle") return "trafficMotorcycle";
    return "trafficCar";
  }

  function createSpatialNode(context, options) {
    var useHrtf = options.useHrtf !== false;
    if (useHrtf && typeof context.createPanner === "function") {
      var panner = context.createPanner();
      panner.panningModel = "HRTF";
      panner.distanceModel = "inverse";
      panner.refDistance = 5;
      panner.maxDistance = 180;
      panner.rolloffFactor = 1.1;
      return { node: panner, type: "panner" };
    }
    if (typeof context.createStereoPanner === "function") return { node: context.createStereoPanner(), type: "stereo" };
    return { node: context.createGain(), type: "mono" };
  }

  function setSpatialPosition(spatial, x, z, now) {
    if (!spatial || !spatial.node) return;
    var node = spatial.node;
    if (spatial.type === "panner") {
      if (node.positionX) {
        setAudioParam(node.positionX, x, now, 0.05);
        setAudioParam(node.positionY, 0, now, 0.05);
        setAudioParam(node.positionZ, z, now, 0.05);
      } else if (typeof node.setPosition === "function") {
        node.setPosition(x, 0, z);
      }
    } else if (spatial.type === "stereo") {
      var perspective = Math.max(4, Math.abs(z) * 0.14);
      setAudioParam(node.pan, clamp(x / perspective, -1, 1), now, 0.05);
    }
  }

  function stopTrafficVoice(voice, context, immediate) {
    if (!voice) return;
    if (!voice.source) {
      voice.vehicleId = null;
      voice.family = null;
      voice.lastDistance = null;
      voice.assignedAt = 0;
      return;
    }
    var now = context.currentTime;
    var stopAt = immediate ? now : now + 0.08;
    var oldSource = voice.source;
    var oldGain = voice.gain;
    var oldFilter = voice.filter;
    var oldSpatialNode = voice.spatial && voice.spatial.node;
    if (voice.gain && voice.gain.gain) {
      try {
        voice.gain.gain.cancelScheduledValues(now);
        voice.gain.gain.setValueAtTime(Math.max(0.0001, voice.gain.gain.value), now);
        voice.gain.gain.exponentialRampToValueAtTime(0.0001, stopAt);
      } catch (error) { /* The graph may already be closing. */ }
    }
    safeStop(oldSource, stopAt + 0.01);
    var clean = function cleanStoppedTrafficVoice() {
      safeDisconnect(oldSource);
      safeDisconnect(oldFilter);
      safeDisconnect(oldGain);
      safeDisconnect(oldSpatialNode);
    };
    if (immediate || typeof root.setTimeout !== "function") clean();
    else root.setTimeout(clean, 120);
    voice.source = null;
    voice.vehicleId = null;
    voice.family = null;
    voice.usesFallback = false;
    voice.filter = null;
    voice.spatial = null;
    voice.lastDistance = null;
    voice.assignedAt = 0;
  }

  function startTrafficVoice(engine, voice, vehicle, family) {
    stopTrafficVoice(voice, engine.context, false);
    var context = engine.context;
    var source;
    var usesFallback = !engine.buffers[family];
    if (usesFallback) {
      source = context.createOscillator();
      source.type = family === "trafficHgv" ? "sawtooth" : family === "trafficMotorcycle" ? "triangle" : "sine";
      source.frequency.value = family === "trafficHgv" ? 58 : family === "trafficVan" ? 82 : family === "trafficMotorcycle" ? 168 : 116;
    } else {
      source = context.createBufferSource();
      configureLoopSource(engine, family, source, engine.buffers[family]);
    }
    var filter = createLowPass(context, 3200);
    var gain = context.createGain();
    var spatial = createSpatialNode(context, engine.options);
    gain.gain.value = 0.0001;
    source.connect(filter).connect(gain).connect(spatial.node).connect(engine.buses.traffic);
    source.start();
    voice.source = source;
    voice.filter = filter;
    voice.gain = gain;
    voice.spatial = spatial;
    voice.vehicleId = vehicle.id;
    voice.family = family;
    voice.usesFallback = usesFallback;
    voice.sampleGeneration = engine.sampleGeneration;
    voice.assignedAt = context.currentTime;
    voice.lastDistance = finiteNumber(vehicle.distance, null);
  }

  function applyTransientEnvelope(parameter, now, attackSeconds, durationSeconds, peak) {
    if (!parameter) return;
    try {
      parameter.cancelScheduledValues(now);
      parameter.setValueAtTime(0.0001, now);
      parameter.exponentialRampToValueAtTime(Math.max(0.0002, peak), now + attackSeconds);
      parameter.exponentialRampToValueAtTime(0.0001, now + durationSeconds);
    } catch (error) {
      parameter.value = 0.0001;
    }
  }

  function playNoiseTransient(engine, options) {
    if (!engine.transientBuffer || engine.disposed) return;
    var opts = options || {};
    var context = engine.context;
    var now = context.currentTime;
    var source = context.createBufferSource();
    var filter = context.createBiquadFilter();
    var gain = context.createGain();
    var spatial = createSpatialNode(context, engine.options);
    source.buffer = engine.transientBuffer;
    filter.type = opts.filterType || "bandpass";
    filter.frequency.value = finiteNumber(opts.frequency, 1200);
    filter.Q.value = finiteNumber(opts.q, 0.65);
    gain.gain.value = 0.0001;
    source.connect(filter).connect(gain).connect(spatial.node).connect(engine.buses.effects);
    setSpatialPosition(spatial, finiteNumber(opts.x, 0), finiteNumber(opts.z, 0), now);
    var duration = clamp(finiteNumber(opts.duration, 0.35), 0.12, 0.7);
    applyTransientEnvelope(gain.gain, now, Math.min(0.035, duration * 0.12), duration, finiteNumber(opts.gain, 0.06));
    source.start(now, 0, duration);
    safeStop(source, now + duration + 0.02);
    source.onended = function cleanupTransient() {
      safeDisconnect(source);
      safeDisconnect(filter);
      safeDisconnect(gain);
      safeDisconnect(spatial.node);
    };
  }

  function playSampleTransient(engine, key, options) {
    var buffer = engine.buffers[key];
    if (!buffer || engine.disposed) return false;
    var opts = options || {};
    var context = engine.context;
    var now = context.currentTime;
    var source = context.createBufferSource();
    var gain = context.createGain();
    var spatial = createSpatialNode(context, engine.options);
    var filter = null;
    var rate = clamp(finiteNumber(opts.playbackRate, 1), 0.68, 1.5);
    var bus = engine.buses[opts.bus || "effects"] || engine.buses.effects;
    source.buffer = buffer;
    source.playbackRate.value = rate;
    gain.gain.value = 0.0001;
    source.connect(gain);
    if (opts.filterFrequency) {
      filter = context.createBiquadFilter();
      filter.type = opts.filterType || "lowpass";
      filter.frequency.value = finiteNumber(opts.filterFrequency, 12000);
      filter.Q.value = finiteNumber(opts.filterQ, 0.35);
      gain.connect(filter).connect(spatial.node).connect(bus);
    } else {
      gain.connect(spatial.node).connect(bus);
    }
    setSpatialPosition(spatial, finiteNumber(opts.x, 0), finiteNumber(opts.z, -3), now);
    var naturalDuration = Math.max(0.08, finiteNumber(buffer.duration, 0.8) / rate);
    var duration = clamp(Math.min(naturalDuration, finiteNumber(opts.maxDuration, naturalDuration)), 0.08, 4);
    var attack = clamp(finiteNumber(opts.attack, 0.012), 0.003, Math.min(0.08, duration * 0.25));
    var releaseStart = Math.max(attack + 0.02, duration - clamp(finiteNumber(opts.release, 0.08), 0.025, 0.35));
    var peak = Math.max(0.0002, finiteNumber(opts.gain, 0.07));
    try {
      gain.gain.setValueAtTime(0.0001, now);
      gain.gain.exponentialRampToValueAtTime(peak, now + attack);
      gain.gain.setValueAtTime(peak, now + releaseStart);
      gain.gain.exponentialRampToValueAtTime(0.0001, now + duration);
    } catch (error) { gain.gain.value = peak; }
    var active = { source: source, gain: gain, filter: filter, spatial: spatial };
    if (!engine.activeTransients) engine.activeTransients = new Set();
    engine.activeTransients.add(active);
    source.start(now, 0, duration);
    safeStop(source, now + duration + 0.02);
    source.onended = function cleanupSampleTransient() {
      engine.activeTransients.delete(active);
      safeDisconnect(source);
      safeDisconnect(gain);
      safeDisconnect(filter);
      safeDisconnect(spatial.node);
    };
    return true;
  }

  function playAuthoredCue(engine, key, options, fallback) {
    if (playSampleTransient(engine, key, options)) return true;
    playNoiseTransient(engine, Object.assign({}, fallback || {}, options || {}));
    return false;
  }

  function crossedGrid(previousDistance, currentDistance, spacing, offset) {
    if (!Number.isFinite(previousDistance) || !Number.isFinite(currentDistance) || currentDistance <= previousDistance) return null;
    if (currentDistance - previousDistance > 220) return null;
    var before = Math.floor((previousDistance + offset) / spacing);
    var after = Math.floor((currentDistance + offset) / spacing);
    return after > before ? after : null;
  }

  function cueAllowed(engine, key, cooldownSeconds, now) {
    var previous = engine.cueHistory.get(key);
    if (Number.isFinite(previous) && now - previous < cooldownSeconds) return false;
    engine.cueHistory.set(key, now);
    return true;
  }

  function vehicleIsBraking(vehicle) {
    var brain = vehicle && vehicle.trafficBrainState || {};
    var phase = String(vehicle && (brain.phase || vehicle.manoeuvrePhase || vehicle.phase || vehicle.state || vehicle.reaction) || "").toLowerCase();
    return Boolean(vehicle && (brain.braking || vehicle.braking || vehicle.brake || vehicle.brakeLights || vehicle.stopping) || /brak|stopp|follow/.test(phase));
  }

  function explicitCueChanged(engine, state, name) {
    var token = state && (state[name + "Id"] != null ? state[name + "Id"] : state[name + "Pulse"]);
    if (token == null || token === false || token === 0) {
      engine.explicitCueTokens[name] = token;
      return false;
    }
    if (engine.explicitCueTokens[name] === token) return false;
    engine.explicitCueTokens[name] = token;
    return true;
  }

  function processRouteAudioEvents(engine, state, now) {
    engine.cueHistory = engine.cueHistory || new Map();
    engine.busBrakeStates = engine.busBrakeStates || new Map();
    engine.explicitCueTokens = engine.explicitCueTokens || {};
    engine.transientCounts = engine.transientCounts || {};
    var route = normalizeRoute(state.routeId);
    var zone = inferAcousticZone(state);
    var chapterId = chapterIdFor(state);
    var distance = finiteNumber(state.worldDistance, finiteNumber(state.distance, NaN));
    var previousDistance = engine.lastWorldDistance;
    var speed = Math.max(0, finiteNumber(state.speedMph, finiteNumber(state.speed, 0)));
    var speedNormal = clamp(speed / TOP_SPEED_MPH, 0, 1);
    var playerLane = finiteNumber(state.playerLane, finiteNumber(state.lane, 0));
    var halfWidth = Math.max(1, finiteNumber(state.roadHalfWidth, 3.8));

    var railZone = route === "city" && (zone === "rail-arches" || zone === "underpass" && /rail|arch/.test(chapterId));
    var railEntry = railZone && engine.lastAcousticZone !== zone;
    if ((railEntry || explicitCueChanged(engine, state, "railArchCue")) && cueAllowed(engine, "rail-arch", 1.6, now)) {
      playAuthoredCue(engine, "railArchCue", {
        x: 0, z: -5, gain: 0.078 + speedNormal * 0.024, playbackRate: 0.92 + speedNormal * 0.12,
        filterFrequency: 6200, release: 0.2
      }, { frequency: 1180, q: 0.58, duration: 0.68, gain: 0.065 });
      engine.reflectionExcitationUntil = now + 1.15;
      engine.transientCounts.railArch = finiteNumber(engine.transientCounts.railArch, 0) + 1;
    }

    var bridgeLike = zone === "rail-arches" || zone === "underpass" || zone === "cutting" || /bridge|overpass|viaduct|rail.*arch/.test(chapterId);
    var expansionIndex = bridgeLike && speed > 12 ? crossedGrid(previousDistance, distance, zone === "cutting" ? 112 : 24, route === "motorway" ? 17 : 7) : null;
    if ((expansionIndex != null || explicitCueChanged(engine, state, "expansionJoint")) && cueAllowed(engine, "expansion-joint", 0.24, now)) {
      playAuthoredCue(engine, "expansionJoint", {
        x: 0, z: 1.5, gain: 0.065 + speedNormal * 0.055, playbackRate: 0.9 + speedNormal * 0.18,
        filterFrequency: 5200, release: 0.08
      }, { filterType: "lowpass", frequency: 260, q: 0.42, duration: 0.28, gain: 0.09 });
      engine.transientCounts.expansionJoint = finiteNumber(engine.transientCounts.expansionJoint, 0) + 1;
    }

    var hedgeIndex = route === "rural" && (zone === "hedgerow" || zone === "wooded") && speed > 24
      ? crossedGrid(previousDistance, distance, zone === "hedgerow" ? 29 : 41, 11) : null;
    if ((hedgeIndex != null || explicitCueChanged(engine, state, "hedgeFlyby")) && cueAllowed(engine, "hedge-flyby", 0.32, now)) {
      var hedgeSide = ((hedgeIndex == null ? Math.floor(distance / 29) : hedgeIndex) & 1) ? -1 : 1;
      playAuthoredCue(engine, "hedgeFlyby", {
        x: hedgeSide * 4.6, z: -1.5, gain: 0.032 + speedNormal * 0.048,
        playbackRate: 0.82 + speedNormal * 0.34, filterFrequency: 7200, release: 0.12
      }, { frequency: 2450, q: 0.54, duration: 0.38, gain: 0.045 });
      engine.transientCounts.hedgeFlyby = finiteNumber(engine.transientCounts.hedgeFlyby, 0) + 1;
    }

    var seenBuses = new Set();
    (Array.isArray(state.traffic) ? state.traffic : []).forEach(function inspectBus(vehicle) {
      var resolvedKind = vehicleKindForAudio(vehicle);
      if (!vehicle || resolvedKind !== "bus" && resolvedKind !== "coach") return;
      var id = String(vehicle.id == null ? "bus-unknown" : vehicle.id);
      seenBuses.add(id);
      var braking = vehicleIsBraking(vehicle);
      var wasBraking = engine.busBrakeStates.get(id) === true;
      var vehicleDistance = finiteNumber(vehicle.distance, 999);
      if (braking && !wasBraking && vehicleDistance > -28 && vehicleDistance < 74 && cueAllowed(engine, "bus-air:" + id, 3.2, now)) {
        var x = (finiteNumber(vehicle.lane, 0) - playerLane) * halfWidth;
        playAuthoredCue(engine, "busAirBrake", {
          x: x, z: -vehicleDistance, gain: 0.072, playbackRate: resolvedKind === "coach" ? 0.94 : 1,
          filterFrequency: state.weather === "fog" ? 5400 : 9200, release: 0.18, maxDuration: 1.65
        }, { frequency: 3100, q: 0.44, duration: 0.92, gain: 0.07 });
        engine.transientCounts.busAirBrake = finiteNumber(engine.transientCounts.busAirBrake, 0) + 1;
      }
      engine.busBrakeStates.set(id, braking);
    });
    engine.busBrakeStates.forEach(function forgetMissingBus(value, id) {
      if (!seenBuses.has(id)) engine.busBrakeStates.delete(id);
    });
    if (engine.cueHistory.size > 192) {
      engine.cueHistory.forEach(function expireCue(time, key) {
        if (now - time > 24) engine.cueHistory.delete(key);
      });
    }

    engine.lastWorldDistance = distance;
    engine.lastAcousticZone = zone;
    engine.lastChapterId = chapterId;
  }

  function firePassByTransient(engine, vehicle, family, x) {
    var now = engine.context.currentTime;
    var vehicleId = vehicle.id;
    var lastPass = engine.passByHistory.get(vehicleId);
    if (Number.isFinite(lastPass) && now - lastPass < 12) return;
    engine.passByHistory.set(vehicleId, now);
    var settings = TRAFFIC_VOICE_SETTINGS[family] || TRAFFIC_VOICE_SETTINGS.trafficCar;
    var vehicleSpeed = Math.max(0, finiteNumber(vehicle.speed, 0));
    var playerSpeed = Math.max(0, finiteNumber(engine.lastPlayerSpeed, 0));
    var closing = vehicle.direction === -1 ? vehicleSpeed + playerSpeed : Math.abs(playerSpeed - vehicleSpeed);
    var speedScale = 0.82 + clamp(closing / 170, 0, 1) * 0.34;
    playNoiseTransient(engine, {
      x: x,
      frequency: settings.centreHz * speedScale,
      q: family === "trafficHgv" ? 0.48 : 0.7,
      gain: settings.passGain * (1 + engine.parameters.largeVehicle * 0.18),
      duration: settings.passSeconds
    });
    if (family === "trafficHgv") {
      playNoiseTransient(engine, {
        x: x,
        filterType: "lowpass",
        frequency: 310,
        q: 0.3,
        gain: 0.12,
        duration: 0.54
      });
    }
    var passWetness = Math.max(engine.parameters.wetness, finiteNumber(engine.weatherWetnessTarget, 0));
    if (passWetness > 0.22) {
      playNoiseTransient(engine, {
        x: x,
        frequency: 3900,
        q: 0.52,
        gain: (0.045 + passWetness * 0.055) * (family === "trafficHgv" ? 1.2 : 1),
        duration: family === "trafficHgv" ? 0.5 : 0.32
      });
    }
    engine.transientCounts.passBy += 1;
    if (family === "trafficHgv") engine.transientCounts.bowWave += 1;
    if (passWetness > 0.22) engine.transientCounts.spray += 1;
  }

  function updateTrafficVoices(engine, frame, now) {
    var traffic = Array.isArray(frame.traffic) ? frame.traffic : [];
    var playerLane = finiteNumber(frame.playerLane, 0);
    var halfWidth = Math.max(1, finiteNumber(frame.roadHalfWidth, 3.8));
    var playerSpeed = Math.max(0, finiteNumber(frame.speedMph, 0));
    var scored = traffic.map(function scoreVehicle(vehicle) {
      var distance = finiteNumber(vehicle && vehicle.distance, 999);
      var family = trafficFamily(vehicle);
      if (!family || distance < -55 || distance > 195) return null;
      var familyWeight = family === "trafficHgv" ? 1.3 : family === "trafficVan" ? 1.1 : 1;
      var audibility = familyWeight / (1 + Math.pow(Math.abs(distance) / 34, 2));
      if (engine.trafficVoices.some(function existing(voice) { return voice.vehicleId === vehicle.id; })) audibility *= 1.38;
      return { vehicle: vehicle, family: family, score: audibility };
    }).filter(Boolean).sort(function descending(left, right) { return right.score - left.score; });

    var scoredById = new Map(scored.map(function pair(item) { return [item.vehicle.id, item]; }));
    var candidates = [];
    var candidateIds = new Set();
    engine.trafficVoices.forEach(function retainYoungVoice(voice) {
      var item = scoredById.get(voice.vehicleId);
      if (!item || now - voice.assignedAt >= 0.38 || candidates.length >= engine.trafficVoices.length) return;
      candidates.push(item);
      candidateIds.add(item.vehicle.id);
    });
    scored.some(function fillVoicePool(item) {
      if (!candidateIds.has(item.vehicle.id)) {
        candidates.push(item);
        candidateIds.add(item.vehicle.id);
      }
      return candidates.length >= engine.trafficVoices.length;
    });

    var selectedIds = candidateIds;
    engine.trafficVoices.forEach(function releaseUnselected(voice) {
      if (voice.vehicleId != null && !selectedIds.has(voice.vehicleId)) stopTrafficVoice(voice, engine.context, false);
    });

    candidates.forEach(function updateCandidate(candidate) {
      var vehicle = candidate.vehicle;
      var voice = engine.trafficVoices.find(function byId(item) { return item.vehicleId === vehicle.id; });
      if (!voice) voice = engine.trafficVoices.find(function empty(item) { return item.vehicleId == null; });
      if (!voice) return;
      if (voice.vehicleId !== vehicle.id || voice.family !== candidate.family || voice.usesFallback && engine.buffers[candidate.family] && voice.sampleGeneration !== engine.sampleGeneration) {
        startTrafficVoice(engine, voice, vehicle, candidate.family);
      }
      var distance = finiteNumber(vehicle.distance, 0);
      var x = (finiteNumber(vehicle.lane, 0) - playerLane) * halfWidth;
      var z = -distance;
      setSpatialPosition(voice.spatial, x, z, now);
      var distanceGain = 1 / (1 + Math.pow(Math.abs(distance) / 31, 2));
      var familySettings = TRAFFIC_VOICE_SETTINGS[candidate.family] || TRAFFIC_VOICE_SETTINGS.trafficCar;
      var familyGain = familySettings.gain * (AUDIO_SAMPLE_TRIMS[candidate.family] || 1);
      var finalGain = voice.spatial.type === "panner" ? familyGain : familyGain * distanceGain;
      setAudioParam(voice.gain.gain, Math.max(0.0001, finalGain), now, 0.06);

      var nearField = smoothstep(188, 9, Math.abs(distance));
      var weatherDamping = frame.weather === "fog" ? 0.62 : frame.weather === "storm" ? 0.72 : frame.weather === "rain" ? 0.82 : frame.weather === "post-rain" ? 0.92 : 1;
      setAudioParam(voice.filter.frequency, (950 + nearField * 10800) * weatherDamping, now, 0.09);

      var vehicleSpeed = Math.max(0, finiteNumber(vehicle.speed, 0));
      var closingMps = (playerSpeed - (vehicle.direction === -1 ? -vehicleSpeed : vehicleSpeed)) * MPH_TO_MPS;
      var signedClosing = distance >= 0 ? closingMps : -closingMps;
      var doppler = clamp(343 / Math.max(285, 343 - signedClosing), 0.85, 1.18);
      var baseRate = 0.84 + clamp(vehicleSpeed / 120, 0, 1) * 0.36;
      if (voice.source.playbackRate) setAudioParam(voice.source.playbackRate, baseRate * doppler, now, 0.08);
      else if (voice.source.frequency) {
        var baseFrequency = candidate.family === "trafficHgv" ? 58 : candidate.family === "trafficVan" ? 82 : candidate.family === "trafficMotorcycle" ? 168 : 116;
        setAudioParam(voice.source.frequency, baseFrequency * baseRate * doppler, now, 0.08);
      }
      if (Number.isFinite(voice.lastDistance) && voice.lastDistance > 0 && distance <= 0 && Math.abs(x) < 9.5) {
        firePassByTransient(engine, vehicle, candidate.family, x);
      }
      voice.lastDistance = distance;
    });

    if (engine.passByHistory.size > 96) {
      engine.passByHistory.forEach(function expirePass(time, id) {
        if (now - time > 20) engine.passByHistory.delete(id);
      });
    }
  }

  function setEngineVisibility(engine, hidden) {
    if (!engine || !engine.available || engine.disposed) return;
    engine.hidden = Boolean(hidden);
    var now = engine.context.currentTime;
    setAudioParam(engine.visibilityGain.gain, engine.hidden ? 0 : 1, now, engine.hidden ? 0.035 : 0.12);
    if (engine.visibilitySuspendTimer != null && typeof root.clearTimeout === "function") {
      root.clearTimeout(engine.visibilitySuspendTimer);
      engine.visibilitySuspendTimer = null;
    }
    if (engine.hidden) {
      /* Never suspend a context supplied by the game bundle: it also owns UI
         cues. A shared context is silenced only through this engine's gain. */
      if (engine.ownsContext && engine.context.state === "running" && typeof engine.context.suspend === "function") {
        var suspendOwnedContext = function suspendOwnedContext() {
          engine.visibilitySuspendTimer = null;
          if (!engine.hidden || engine.disposed) return;
          engine.suspendedByVisibility = true;
          try { Promise.resolve(engine.context.suspend()).catch(function ignored() {}); } catch (error) { /* Ignore browser lifecycle races. */ }
        };
        if (typeof root.setTimeout === "function") engine.visibilitySuspendTimer = root.setTimeout(suspendOwnedContext, 55);
        else suspendOwnedContext();
      }
      return;
    }
    if (engine.ownsContext && engine.suspendedByVisibility && typeof engine.context.resume === "function") {
      engine.suspendedByVisibility = false;
      try { Promise.resolve(engine.context.resume()).catch(function ignored() {}); } catch (error) { /* User gesture policy may retain suspension. */ }
    }
  }

  /*
   * Sample decoding is deliberately separate from graph construction.  A cold
   * launch can therefore start with the procedural fallback and defer the
   * eighteen fetch/decode jobs until the ride has finished.  The promise is
   * retained on the engine so repeated lifecycle calls cannot start a second
   * batch for the same AudioContext.
   */
  function startSpatialAudioSamples(engine) {
    if (!engine || !engine.available || !engine.context) {
      return engine && engine.ready ? engine.ready : Promise.resolve({ loaded: [], failed: Object.keys(AUDIO_SAMPLE_PATHS), unavailable: true });
    }
    if (engine.sampleLoadPromise) return engine.sampleLoadPromise;
    if (engine.disposed) {
      engine.sampleLoadPromise = Promise.resolve({ loaded: [], failed: Object.keys(AUDIO_SAMPLE_PATHS), disposed: true });
      engine.ready = engine.sampleLoadPromise;
      return engine.sampleLoadPromise;
    }

    engine.sampleLoadingStarted = true;
    engine.samplesDeferred = false;
    var opts = engine.options || {};
    var context = engine.context;
    var loadPromises = Object.keys(AUDIO_SAMPLE_PATHS).map(function loadKey(key) {
      var url;
      try { url = resolveAudioUrl(AUDIO_SAMPLE_PATHS[key], opts); }
      catch (error) {
        engine.loadErrors[key] = error;
        return Promise.reject({ key: key, error: error });
      }
      var fetchOptions = { credentials: "same-origin", cache: "force-cache" };
      if (engine.abortController) fetchOptions.signal = engine.abortController.signal;
      return fetch(url, fetchOptions).then(function validate(response) {
        if (!response.ok) throw new Error("Audio sample returned HTTP " + response.status + ": " + AUDIO_SAMPLE_PATHS[key]);
        return response.arrayBuffer();
      }).then(function decode(arrayBuffer) {
        return decodeAudioBuffer(context, arrayBuffer);
      }).then(function retain(buffer) {
        if (engine.disposed) return null;
        engine.buffers[key] = buffer;
        engine.sampleGeneration += 1;
        if (isLoopSampleKey(key)) installLoopSample(engine, key, buffer);
        return key;
      }).catch(function remember(error) {
        engine.loadErrors[key] = error && error.error ? error.error : error;
        throw { key: key, error: engine.loadErrors[key] };
      });
    });

    engine.sampleLoadPromise = Promise.allSettled(loadPromises).then(function summarize(results) {
      return {
        loaded: results.filter(function fulfilled(result) { return result.status === "fulfilled" && result.value; }).map(function value(result) { return result.value; }),
        failed: results.filter(function rejected(result) { return result.status === "rejected"; }).map(function reason(result) { return result.reason && result.reason.key || "unknown"; })
      };
    });
    engine.ready = engine.sampleLoadPromise;
    return engine.sampleLoadPromise;
  }

  function initializeSpatialAudio(options) {
    var opts = Object.assign({
      maxTrafficVoices: 4,
      useHrtf: true,
      masterGain: 0.72,
      outerMasterGainHint: 0.22,
      compensateOuterMaster: true,
      deferSampleLoading: false
    }, options || {});
    var AudioContextClass = opts.AudioContextClass || (typeof root.AudioContext === "function" ? root.AudioContext : root.webkitAudioContext);
    if (!AudioContextClass && !opts.context) {
      return {
        available: false,
        disposed: false,
        muted: Boolean(opts.muted),
        options: opts,
        sampleLoadingStarted: false,
        sampleLoadPromise: null,
        samplesDeferred: Boolean(opts.deferSampleLoading),
        ready: Promise.resolve({ loaded: [], failed: Object.keys(AUDIO_SAMPLE_PATHS) })
      };
    }

    var context = opts.context || new AudioContextClass({ latencyHint: "interactive" });
    var ownsContext = !opts.context;
    var master = context.createGain();
    var visibilityGain = context.createGain();
    var limiter = typeof context.createDynamicsCompressor === "function" ? context.createDynamicsCompressor() : context.createGain();
    if (limiter.threshold) {
      limiter.threshold.value = -8;
      limiter.knee.value = 12;
      limiter.ratio.value = 6;
      limiter.attack.value = 0.004;
      limiter.release.value = 0.16;
    }
    var requestedMasterGain = clamp(finiteNumber(opts.masterGain, 0.72), 0, 1.4);
    var outerGainHint = clamp(finiteNumber(opts.outerMasterGainHint, 0.22), 0.08, 1);
    var calibratedMasterGain = opts.compensateOuterMaster === false ? requestedMasterGain : clamp(requestedMasterGain / Math.sqrt(outerGainHint), requestedMasterGain, 1.25);
    master.gain.value = opts.muted ? 0 : calibratedMasterGain;
    visibilityGain.gain.value = typeof document !== "undefined" && document.hidden ? 0 : 1;
    var requestedOutput = opts.outputNode && typeof opts.outputNode.connect === "function" ? opts.outputNode : opts.master;
    var outputDestination = requestedOutput && typeof requestedOutput.connect === "function" ? requestedOutput : context.destination;
    master.connect(visibilityGain).connect(limiter).connect(outputDestination);
    var mixGraph = createSampleMixGraph(context, master);
    var engine = {
      available: true,
      disposed: false,
      muted: Boolean(opts.muted),
      context: context,
      ownsContext: ownsContext,
      options: opts,
      master: master,
      calibratedMasterGain: calibratedMasterGain,
      outerGainHint: outerGainHint,
      visibilityGain: visibilityGain,
      limiter: limiter,
      outputDestination: outputDestination,
      buses: mixGraph.buses,
      busFilters: mixGraph.filters,
      busSends: mixGraph.sends,
      reflections: mixGraph.reflections,
      buffers: {},
      sampleNodes: {},
      loopPoints: {},
      sampleGeneration: 0,
      fallback: null,
      trafficVoices: [],
      parameters: Object.assign({}, DEFAULT_AUDIO_PARAMETERS),
      snapshot: acousticSnapshot({ routeId: "city", routeStage: "city", timeOfDay: "day", weather: "clear" }),
      previousSpeedMph: 0,
      lastPlayerSpeed: 0,
      weatherWetnessTarget: 0,
      transientBuffer: makeNoiseBuffer(context, 0.72, 0.42),
      transientCounts: { passBy: 0, bowWave: 0, spray: 0, busAirBrake: 0, expansionJoint: 0, railArch: 0, hedgeFlyby: 0 },
      activeTransients: new Set(),
      passByHistory: new Map(),
      cueHistory: new Map(),
      busBrakeStates: new Map(),
      explicitCueTokens: {},
      lastWorldDistance: NaN,
      lastAcousticZone: null,
      lastChapterId: null,
      reflectionExcitationUntil: -Infinity,
      abortController: typeof AbortController === "function" ? new AbortController() : null,
      lastTrafficUpdate: -Infinity,
      lastFrameTime: 0,
      lastPhase: null,
      loadErrors: {},
      sampleLoadingStarted: false,
      sampleLoadPromise: null,
      samplesDeferred: opts.deferSampleLoading === true,
      hidden: typeof document !== "undefined" && document.hidden,
      suspendedByVisibility: false,
      visibilitySuspendTimer: null,
      visibilityHandler: null
    };
    var voiceCount = Math.round(clamp(opts.maxTrafficVoices, 0, 8));
    for (var voiceIndex = 0; voiceIndex < voiceCount; voiceIndex += 1) {
      engine.trafficVoices.push({
        vehicleId: null,
        family: null,
        source: null,
        filter: null,
        gain: null,
        spatial: null,
        usesFallback: false,
        sampleGeneration: 0,
        assignedAt: 0,
        lastDistance: null
      });
    }
    engine.fallback = createProceduralFallback(engine);
    updateAcousticSnapshot(engine, { routeId: "city", routeStage: "city", timeOfDay: "day", weather: "clear" }, context.currentTime);
    if (typeof document !== "undefined" && typeof document.addEventListener === "function") {
      engine.visibilityHandler = function handleAudioVisibility() { setEngineVisibility(engine, document.hidden); };
      document.addEventListener("visibilitychange", engine.visibilityHandler);
    }

    engine.ready = opts.deferSampleLoading === true ?
      Promise.resolve({ loaded: [], failed: [], deferred: true }) :
      startSpatialAudioSamples(engine);
    return engine;
  }

  function resumeSpatialAudio(engine) {
    if (!engine || !engine.available || engine.disposed || !engine.context || typeof engine.context.resume !== "function") return Promise.resolve(false);
    if (engine.hidden) return Promise.resolve(false);
    return Promise.resolve(engine.context.resume()).then(function resumed() {
      setEngineVisibility(engine, false);
      return engine.context.state === "running";
    }, function failed() { return false; });
  }

  function setSpatialAudioMuted(engine, muted) {
    if (!engine) return Boolean(muted);
    engine.muted = Boolean(muted);
    if (engine.available && !engine.disposed && engine.master) {
      setAudioParam(engine.master.gain, engine.muted ? 0 : engine.calibratedMasterGain, engine.context.currentTime, 0.04);
    }
    return engine.muted;
  }

  function updateLoopGain(engine, key, gainValue, now, smoothing) {
    var node = engine.sampleNodes[key];
    if (node) setAudioParam(node.gain.gain, Math.max(0.0001, gainValue), now, smoothing || 0.06);
  }

  function updateLoopRate(engine, key, rateValue, now, smoothing) {
    var node = engine.sampleNodes[key];
    if (node && node.source && node.source.playbackRate) setAudioParam(node.source.playbackRate, clamp(rateValue, 0.65, 1.55), now, smoothing || 0.055);
  }

  function updateAudioParameters(engine, state, speed, braking, brakePressure, boost, wetness) {
    var dt = clamp(finiteNumber(state.deltaSeconds, 1 / 60), 1 / 240, 0.1);
    var speedNormalTarget = clamp(speed / TOP_SPEED_MPH, 0, 1);
    var derivedLongitudinalG = (speed - engine.previousSpeedMph) * MPH_TO_MPS / dt / STANDARD_GRAVITY;
    var longitudinalG = clamp(finiteNumber(state.longitudinalG, derivedLongitudinalG), -1.2, 0.8);
    var targetSpeed = Math.max(0, finiteNumber(state.targetSpeedMph, speed));
    var speedDemand = clamp((targetSpeed - speed) / 38, -1, 1);
    var loadTarget = clamp(Math.max(0, longitudinalG / 0.62, speedDemand * 0.72, boost ? 1 : 0), 0, 1);
    var liftTarget = clamp(Math.max(0, -longitudinalG / 0.72, braking ? 0 : -speedDemand * 0.7), 0, 1);
    var regenTarget = clamp(brakePressure * 0.78 + liftTarget * 0.42 + Math.max(0, -longitudinalG) * 0.28, 0, 1);
    var largeVehicle = clamp(finiteNumber(state.largeVehicleProximity, 0), 0, 1);
    var crosswind = clamp(Math.abs(finiteNumber(state.crosswind, finiteNumber(state.windYaw, 0))), 0, 1);
    var tyreSlip = clamp(Math.abs(finiteNumber(state.tyreSlip, 0)), 0, 1);
    var acousticZone = inferAcousticZone(state);
    var mechanicalTarget = clamp(smoothstep(0.035, 0.24, speedNormalTarget) * (0.58 + loadTarget * 0.34 + Math.abs(longitudinalG) * 0.08), 0, 1);
    var servicesTarget = acousticZone === "services" ? 1 : 0;
    var parameters = engine.parameters;
    parameters.speedNormal = asymmetricDamp(parameters.speedNormal, speedNormalTarget, 13, 9, dt);
    parameters.load = asymmetricDamp(parameters.load, loadTarget, 12, 5, dt);
    parameters.liftOff = asymmetricDamp(parameters.liftOff, liftTarget, 11, 5.5, dt);
    parameters.regen = asymmetricDamp(parameters.regen, regenTarget, 18, 7, dt);
    parameters.boost = asymmetricDamp(parameters.boost, boost ? 1 : 0, 15, 5, dt);
    parameters.wetness = asymmetricDamp(parameters.wetness, wetness, 4, 1.8, dt);
    parameters.largeVehicle = asymmetricDamp(parameters.largeVehicle, largeVehicle, 9, 3.5, dt);
    parameters.crosswind = asymmetricDamp(parameters.crosswind, crosswind, 5, 3, dt);
    parameters.tyreSlip = asymmetricDamp(parameters.tyreSlip, tyreSlip, 12, 6, dt);
    parameters.mechanicalResonance = asymmetricDamp(parameters.mechanicalResonance, mechanicalTarget, 8, 4.5, dt);
    parameters.servicesPresence = asymmetricDamp(parameters.servicesPresence, servicesTarget, 1.8, 0.72, dt);
    engine.previousSpeedMph = speed;
    engine.lastPlayerSpeed = speed;
    return parameters;
  }

  function equalPowerMotorBands(speedNormal) {
    var low = 1 - smoothstep(0.24, 0.54, speedNormal);
    var mid = smoothstep(0.14, 0.4, speedNormal) * (1 - smoothstep(0.62, 0.86, speedNormal));
    var high = smoothstep(0.5, 0.84, speedNormal);
    var magnitude = Math.sqrt(low * low + mid * mid + high * high);
    if (magnitude < 0.0001) return { low: 0, mid: 0, high: 0 };
    return { low: low / magnitude, mid: mid / magnitude, high: high / magnitude };
  }

  function updateSpatialAudio(engine, frame) {
    if (!engine || !engine.available || engine.disposed || !engine.context) return false;
    var state = frame || {};
    var now = engine.context.currentTime;
    if (state.phase === "countdown" && engine.lastPhase !== "countdown") {
      engine.passByHistory.clear();
      engine.cueHistory.clear();
      engine.busBrakeStates.clear();
      engine.explicitCueTokens = {};
      engine.trafficVoices.forEach(function resetRunVoice(voice) { stopTrafficVoice(voice, engine.context, false); });
      Object.assign(engine.parameters, DEFAULT_AUDIO_PARAMETERS);
      engine.previousSpeedMph = 0;
      engine.lastTrafficUpdate = -Infinity;
      engine.lastWorldDistance = NaN;
      engine.lastAcousticZone = null;
      engine.lastChapterId = null;
      engine.reflectionExcitationUntil = -Infinity;
    }
    engine.lastPhase = state.phase == null ? "riding" : state.phase;
    var riding = state.phase == null || state.phase === "riding";
    var countdown = state.phase === "countdown";
    var active = riding || countdown;
    var rideGain = riding ? 1 : countdown ? 0.38 : 0;
    var speed = Math.max(0, finiteNumber(state.speedMph, finiteNumber(state.speed, 0)));
    var speedNormal = clamp(speed / TOP_SPEED_MPH, 0, 1);
    var braking = Boolean(state.braking);
    var brakePressure = clamp(finiteNumber(state.brakePressure, braking ? 1 : 0), 0, 1);
    var boost = Boolean(state.boostActive || state.boosting);
    var weather = normalizeWeather(state.weather);
    var wetness = weather === "storm" ? 1 : weather === "rain" ? 0.82 : weather === "post-rain" ? 0.58 : 0;
    engine.weatherWetnessTarget = wetness;

    if (state.muted != null && Boolean(state.muted) !== engine.muted) setSpatialAudioMuted(engine, state.muted);
    var snapshot = updateAcousticSnapshot(engine, state, now);
    if (countdown) setAudioParam(engine.buses.traffic.gain, snapshot.trafficGain * 0.42, now, 0.14);
    var parameters = updateAudioParameters(engine, state, speed, braking, brakePressure, boost, wetness);
    speedNormal = parameters.speedNormal;
    var bands = equalPowerMotorBands(speedNormal);
    var motion = smoothstep(0.015, 0.075, speedNormal);
    var readyHum = active && motion < 0.98 ? (countdown ? 0.018 : 0.009) * (1 - motion) : 0;
    var driveEnergy = (0.17 + parameters.load * 0.075 - parameters.liftOff * 0.025) * motion * rideGain;
    var lowGain = (bands.low * driveEnergy + readyHum) * AUDIO_SAMPLE_TRIMS.hypercoreLow;
    var midGain = bands.mid * driveEnergy * AUDIO_SAMPLE_TRIMS.hypercoreMid;
    var highGain = bands.high * driveEnergy * AUDIO_SAMPLE_TRIMS.hypercoreHigh;
    var powertrainReady = Boolean(engine.sampleNodes.hypercoreLow && engine.sampleNodes.hypercoreMid && engine.sampleNodes.hypercoreHigh);
    updateLoopGain(engine, "hypercoreLow", lowGain, now, 0.045);
    updateLoopGain(engine, "hypercoreMid", midGain, now, 0.045);
    updateLoopGain(engine, "hypercoreHigh", highGain, now, 0.045);
    updateLoopGain(engine, "hypercoreRegen", parameters.regen * (0.055 + speedNormal * 0.105) * rideGain, now, 0.035);
    updateLoopGain(engine, "hypercoreOverdrive", parameters.boost * (0.075 + parameters.load * 0.085) * rideGain, now, 0.035);
    updateLoopRate(engine, "hypercoreLow", 0.88 + smoothstep(0, 0.5, speedNormal) * 0.34 + parameters.load * 0.025, now, 0.045);
    updateLoopRate(engine, "hypercoreMid", 0.84 + smoothstep(0.12, 0.82, speedNormal) * 0.48 + parameters.load * 0.03, now, 0.045);
    updateLoopRate(engine, "hypercoreHigh", 0.82 + smoothstep(0.48, 1, speedNormal) * 0.58 + parameters.load * 0.035, now, 0.042);
    updateLoopRate(engine, "hypercoreRegen", 0.9 + speedNormal * 0.32 + parameters.regen * 0.03, now, 0.05);
    updateLoopRate(engine, "hypercoreOverdrive", 0.9 + speedNormal * 0.38 + parameters.boost * 0.05, now, 0.04);
    updateLoopGain(engine, "mechanicalHub", parameters.mechanicalResonance * (0.032 + speedNormal * 0.034 + parameters.load * 0.018) * AUDIO_SAMPLE_TRIMS.mechanicalHub * rideGain, now, 0.07);
    updateLoopRate(engine, "mechanicalHub", 0.78 + speedNormal * 0.52 + parameters.load * 0.035, now, 0.075);

    var windGain = Math.pow(speedNormal, 1.9) * (0.205 + (weather === "storm" ? 0.075 : weather === "rain" ? 0.025 : weather === "post-rain" ? 0.006 : 0) + parameters.crosswind * 0.055 + parameters.largeVehicle * 0.065) * snapshot.windScale * rideGain;
    var tyreEnergy = Math.pow(speedNormal, 1.26) * snapshot.tyreScale * (1 + parameters.tyreSlip * 0.24) * rideGain;
    updateLoopGain(engine, "wind", windGain * AUDIO_SAMPLE_TRIMS.wind, now, 0.08);
    updateLoopGain(engine, "tyreDry", tyreEnergy * (1 - parameters.wetness) * 0.24 * AUDIO_SAMPLE_TRIMS.tyreDry, now, 0.08);
    updateLoopGain(engine, "tyreWet", tyreEnergy * parameters.wetness * 0.19 * AUDIO_SAMPLE_TRIMS.tyreWet, now, 0.07);
    updateLoopRate(engine, "wind", 0.86 + speedNormal * 0.3 + parameters.crosswind * 0.035, now, 0.09);
    updateLoopRate(engine, "tyreDry", 0.92 + speedNormal * 0.12, now, 0.09);
    updateLoopRate(engine, "tyreWet", 0.9 + speedNormal * 0.15, now, 0.08);
    updateLoopGain(engine, "servicesAmbience", parameters.servicesPresence * (0.026 + (1 - speedNormal) * 0.018) * AUDIO_SAMPLE_TRIMS.servicesAmbience * rideGain, now, 0.32);
    updateLoopRate(engine, "servicesAmbience", 0.96 + speedNormal * 0.035, now, 0.24);

    var fallback = engine.fallback;
    var fallbackMix = powertrainReady ? 0.025 : 1;
    var fundamental = 72 + speed * 5.4;
    setAudioParam(fallback.motor.frequency, fundamental, now, 0.055);
    setAudioParam(fallback.harmonic.frequency, fundamental * 2.02, now, 0.05);
    setAudioParam(fallback.overdrive.frequency, fundamental * 3.06, now, 0.045);
    setAudioParam(fallback.motorGain.gain, fallbackMix * (readyHum + speedNormal * (0.055 + parameters.load * 0.045)) * rideGain, now, 0.06);
    setAudioParam(fallback.harmonicGain.gain, fallbackMix * speedNormal * (0.012 + parameters.load * 0.034) * rideGain, now, 0.05);
    setAudioParam(fallback.overdriveGain.gain, fallbackMix * parameters.boost * 0.02 * rideGain, now, 0.045);
    setAudioParam(fallback.windFilter.frequency, 430 + Math.pow(speedNormal, 1.2) * 1050, now, 0.08);
    var weatherBed = weather === "storm" ? 0.016 : weather === "rain" ? 0.008 : 0;
    setAudioParam(fallback.windGain.gain, (engine.sampleNodes.wind ? weatherBed : weatherBed + 0.002 + speedNormal * speedNormal * 0.08) * rideGain, now, 0.08);

    if (riding) processRouteAudioEvents(engine, state, now);
    if (!active) {
      engine.trafficVoices.forEach(function stopVoice(voice) { stopTrafficVoice(voice, engine.context, false); });
      return true;
    }
    if (now - engine.lastTrafficUpdate >= 0.05) {
      engine.lastTrafficUpdate = now;
      updateTrafficVoices(engine, state, now);
    }
    engine.lastFrameTime = now;
    return true;
  }

  function updateSpatialAudioFrame(engine, target, deltaSeconds) {
    var state = target && typeof target === "object" ? target : {};
    var nextGen = state.nextGenV300 || {};
    var dynamics = nextGen.dynamics || {};
    var springMetrics = nextGen.springs && nextGen.springs.metrics || {};
    var roadDynamicsActive = (state.handlingProfile || nextGen.handlingProfile || dynamics.handlingProfile) === HANDLING_PROFILES.ROAD;
    var stateBraking = Boolean(state.brake != null ? state.brake : state.braking);
    var stateBoosting = Boolean(state.boost != null ? state.boost : state.boosting);
    var arcadeTargetSpeed = stateBraking ? 0 : stateBoosting ? TOP_SPEED_MPH : modeSpeedLimit(state);
    return updateSpatialAudio(engine, Object.assign({}, state, dynamics, springMetrics, {
      deltaSeconds: finiteNumber(deltaSeconds, finiteNumber(state.deltaSeconds, 1 / 60)),
      speedMph: finiteNumber(state.speed, finiteNumber(state.speedMph, 0)),
      playerLane: finiteNumber(state.lane, finiteNumber(state.playerLane, 0)),
      braking: stateBraking,
      brakePressure: roadDynamicsActive ? clamp(finiteNumber(dynamics.brakePressure, stateBraking ? 1 : 0), 0, 1) : stateBraking ? 1 : 0,
      boostActive: roadDynamicsActive ? Boolean(dynamics.boostActive) : stateBoosting,
      targetSpeedMph: roadDynamicsActive ? finiteNumber(dynamics.targetSpeedMph, arcadeTargetSpeed) : arcadeTargetSpeed,
      longitudinalG: roadDynamicsActive ? finiteNumber(dynamics.longitudinalG, NaN) : NaN,
      crosswind: roadDynamicsActive ? finiteNumber(dynamics.crosswind, finiteNumber(springMetrics.windYaw, 0)) : finiteNumber(springMetrics.windYaw, 0)
    }));
  }

  function disposeSpatialAudio(engine) {
    if (!engine || engine.disposed) return Promise.resolve();
    engine.disposed = true;
    if (engine.visibilitySuspendTimer != null && typeof root.clearTimeout === "function") {
      root.clearTimeout(engine.visibilitySuspendTimer);
      engine.visibilitySuspendTimer = null;
    }
    if (engine.visibilityHandler && typeof document !== "undefined" && typeof document.removeEventListener === "function") {
      document.removeEventListener("visibilitychange", engine.visibilityHandler);
      engine.visibilityHandler = null;
    }
    if (engine.abortController) {
      try { engine.abortController.abort(); } catch (error) { /* Ignore. */ }
    }
    if (!engine.available) return Promise.resolve();
    var now = engine.context ? engine.context.currentTime : 0;
    engine.trafficVoices.forEach(function stopVoice(voice) { stopTrafficVoice(voice, engine.context, true); });
    if (engine.activeTransients) {
      engine.activeTransients.forEach(function stopTransient(item) {
        safeStop(item.source, now);
        safeDisconnect(item.source);
        safeDisconnect(item.gain);
        safeDisconnect(item.filter);
        safeDisconnect(item.spatial && item.spatial.node);
      });
      engine.activeTransients.clear();
    }
    Object.keys(engine.sampleNodes).forEach(function stopLoop(key) {
      safeStop(engine.sampleNodes[key].source, now);
      safeDisconnect(engine.sampleNodes[key].source);
      safeDisconnect(engine.sampleNodes[key].gain);
    });
    if (engine.fallback) {
      safeStop(engine.fallback.motor, now);
      safeStop(engine.fallback.harmonic, now);
      safeStop(engine.fallback.overdrive, now);
      safeStop(engine.fallback.noise, now);
      Object.keys(engine.fallback).forEach(function disconnect(key) { safeDisconnect(engine.fallback[key]); });
    }
    Object.keys(engine.buses || {}).forEach(function disconnectBus(key) { safeDisconnect(engine.buses[key]); });
    Object.keys(engine.busFilters || {}).forEach(function disconnectFilter(key) { safeDisconnect(engine.busFilters[key]); });
    Object.keys(engine.busSends || {}).forEach(function disconnectSend(key) { safeDisconnect(engine.busSends[key]); });
    Object.keys(engine.reflections || {}).forEach(function disconnectReflection(key) { safeDisconnect(engine.reflections[key]); });
    if (engine.passByHistory) engine.passByHistory.clear();
    if (engine.cueHistory) engine.cueHistory.clear();
    if (engine.busBrakeStates) engine.busBrakeStates.clear();
    safeDisconnect(engine.master);
    safeDisconnect(engine.visibilityGain);
    safeDisconnect(engine.limiter);
    if (engine.ownsContext && engine.context && typeof engine.context.close === "function") {
      try { return Promise.resolve(engine.context.close()).catch(function ignored() {}); }
      catch (error) { return Promise.resolve(); }
    }
    return Promise.resolve();
  }

  var RATING_DIMENSIONS = Object.freeze(["pace", "precision", "awareness", "smoothness", "discipline"]);
  var RATING_WEIGHTS = Object.freeze({ pace: 0.22, precision: 0.23, awareness: 0.23, smoothness: 0.17, discipline: 0.15 });

  function emptyDimensionMap(value) {
    return { pace: value, precision: value, awareness: value, smoothness: value, discipline: value };
  }

  function createRatingAccumulator(options) {
    var opts = options || {};
    return {
      version: VERSION,
      routeId: normalizeRoute(opts.routeId),
      handlingProfile: normalizeHandlingProfile(opts.handlingProfile),
      elapsedSeconds: 0,
      sampleCount: 0,
      weightedSums: emptyDimensionMap(0),
      weightedSeconds: emptyDimensionMap(0),
      adjustments: emptyDimensionMap(0),
      eventCounts: {},
      events: [],
      previous: null,
      finalized: false,
      result: null
    };
  }

  function ratingPace(speedMph, targetMph, exempt) {
    if (exempt) return 1;
    var target = Math.max(15, finiteNumber(targetMph, 60));
    var ratio = Math.max(0, finiteNumber(speedMph, 0)) / target;
    if (ratio <= 1) return smoothstep(0.28, 0.92, ratio);
    return clamp(1 - (ratio - 1) / 0.28, 0, 1);
  }

  function sampleRating(accumulator, sample) {
    var acc = accumulator || createRatingAccumulator();
    if (acc.finalized) return acc;
    var value = sample || {};
    var dt = clamp(value.deltaSeconds, 0.001, 0.25);
    var speed = Math.max(0, finiteNumber(value.speedMph, 0));
    var limit = Math.max(15, finiteNumber(value.targetSpeedMph, finiteNumber(value.speedLimitMph, 60)));
    var laneError = Math.abs(finiteNumber(value.laneErrorNormalized, finiteNumber(value.laneError, 0)));
    var precision = value.precisionExempt || value.evasiveAction ? 1 : clamp(1 - laneError * 1.25, 0, 1);
    var hazardTtc = finiteNumber(value.hazardTimeToContact, Infinity);
    var awareness = 0.86;
    if (Number.isFinite(hazardTtc)) {
      var acknowledged = Boolean(value.hazardAcknowledged || value.braking || Math.abs(finiteNumber(value.steerInput, 0)) > 0.2);
      awareness = acknowledged ? clamp(0.72 + hazardTtc / 8, 0.72, 1) : hazardTtc > 1.5 ? 0.66 : 0.28;
    }

    var previous = acc.previous;
    var steer = finiteNumber(value.steerInput, 0);
    var brake = clamp(finiteNumber(value.brakePressure, value.braking ? 1 : 0), 0, 1);
    var steerRate = previous ? Math.abs(steer - previous.steer) / dt : 0;
    var brakeRate = previous ? Math.abs(brake - previous.brake) / dt : 0;
    var acceleration = previous ? Math.abs(speed - previous.speed) / dt : 0;
    var smoothness = clamp(1 - steerRate / 8.5 - brakeRate / 13 - Math.max(0, acceleration - 30) / 90, 0, 1);
    if (value.roughSurface || value.evasiveAction) smoothness = Math.max(smoothness, 0.82);

    var overspeed = Math.max(0, speed - Math.max(1, finiteNumber(value.speedLimitMph, limit)));
    var discipline = clamp(1 - overspeed / Math.max(12, limit * 0.25), 0, 1);
    if (value.hardShoulder || value.wrongWay || value.roadworksViolation) discipline *= 0.2;
    if (value.collision) discipline = 0;

    var dimensions = {
      pace: ratingPace(speed, limit, Boolean(value.paceExempt)),
      precision: precision,
      awareness: awareness,
      smoothness: smoothness,
      discipline: discipline
    };
    RATING_DIMENSIONS.forEach(function accumulate(dimension) {
      acc.weightedSums[dimension] += dimensions[dimension] * dt;
      acc.weightedSeconds[dimension] += dt;
    });
    acc.elapsedSeconds += dt;
    acc.sampleCount += 1;
    acc.previous = { speed: speed, steer: steer, brake: brake };
    return acc;
  }

  var RATING_EVENT_EFFECTS = Object.freeze({
    "hazard-acknowledged": { awareness: 3 },
    "early-brake": { awareness: 4, smoothness: 2 },
    "clean-pass": { pace: 2, precision: 2, discipline: 1 },
    "safe-gap": { awareness: 2, discipline: 2 },
    "returned-left": { precision: 1, discipline: 2 },
    "near-miss": { awareness: -6, discipline: -5, smoothness: -2 },
    speeding: { discipline: -6 },
    "hard-brake": { smoothness: -3 },
    "hard-shoulder": { discipline: -12, precision: -5 },
    "roadworks-violation": { discipline: -14, awareness: -6 },
    collision: { awareness: -22, discipline: -28, precision: -15, smoothness: -8 },
    "hazard-contact": { awareness: -8, precision: -5 },
    "focus-zone-clear": { awareness: 4, precision: 2 }
  });

  function recordRatingEvent(accumulator, type, detail) {
    var acc = accumulator || createRatingAccumulator();
    if (acc.finalized) return acc;
    var eventType = String(type || "unknown");
    var multiplier = clamp(finiteNumber(detail && detail.multiplier, 1), 0, 5);
    var effect = RATING_EVENT_EFFECTS[eventType] || {};
    Object.keys(effect).forEach(function adjust(dimension) {
      acc.adjustments[dimension] += effect[dimension] * multiplier;
    });
    acc.eventCounts[eventType] = (acc.eventCounts[eventType] || 0) + 1;
    acc.events.push({
      type: eventType,
      elapsed: Math.max(0, finiteNumber(detail && detail.elapsed, acc.elapsedSeconds)),
      detail: detail && typeof detail === "object" ? Object.assign({}, detail) : {}
    });
    if (acc.events.length > 120) acc.events.shift();
    return acc;
  }

  function ratingCoaching(result, maximumTips) {
    if (!result || !result.dimensions) return [];
    var limit = Math.round(clamp(maximumTips == null ? 2 : maximumTips, 1, 5));
    var messages = {
      pace: "Build speed progressively after each hazard and carry a steadier pace through clear sections.",
      precision: "Use smaller steering inputs and settle the EVO near the safest part of the lane before each pass.",
      awareness: "Read the HALO telegraph earlier; create space before the situation reaches the motorcycle.",
      smoothness: "Blend steering and regenerative braking more gradually to keep the chassis composed.",
      discipline: "Protect the run by respecting closures, safe gaps and the marked road rather than chasing near misses."
    };
    var ranked = RATING_DIMENSIONS.slice().sort(function ascending(left, right) { return result.dimensions[left] - result.dimensions[right]; });
    var tips = ranked.slice(0, limit).map(function message(dimension) { return messages[dimension]; });
    if (result.eventCounts && result.eventCounts.collision) tips[0] = "Finish the run first: respond to HALO warnings early and leave a clear escape path around every developing hazard.";
    return tips;
  }

  function finalizeRating(accumulator) {
    var acc = accumulator || createRatingAccumulator();
    if (acc.finalized && acc.result) return acc.result;
    var dimensions = {};
    RATING_DIMENSIONS.forEach(function finalizeDimension(dimension) {
      var seconds = Math.max(0.001, acc.weightedSeconds[dimension]);
      dimensions[dimension] = Math.round(clamp(acc.weightedSums[dimension] / seconds * 100 + acc.adjustments[dimension], 0, 100));
    });
    var overall = Math.round(RATING_DIMENSIONS.reduce(function total(sum, dimension) {
      return sum + dimensions[dimension] * RATING_WEIGHTS[dimension];
    }, 0));
    var grade = overall >= 92 ? "S" : overall >= 82 ? "A" : overall >= 70 ? "B" : overall >= 58 ? "C" : "D";
    var title = grade === "S" ? "WORKS STANDARD" : grade === "A" ? "EXPERT ROADCRAFT" : grade === "B" ? "CONTROLLED AND QUICK" : grade === "C" ? "DEVELOPING RIDER" : "HALO COACHING REQUIRED";
    var result = {
      version: VERSION,
      routeId: acc.routeId,
      handlingProfile: acc.handlingProfile,
      overall: overall,
      grade: grade,
      title: title,
      dimensions: dimensions,
      eventCounts: Object.assign({}, acc.eventCounts),
      sampleCount: acc.sampleCount,
      elapsedSeconds: Math.round(acc.elapsedSeconds * 100) / 100
    };
    result.coaching = ratingCoaching(result, 2);
    acc.finalized = true;
    acc.result = result;
    return result;
  }

  function sampleRunRating(target, sample) {
    var state = target && typeof target === "object" ? target : {};
    initializeNextGenState(state);
    if (!state.nextGenV300.rating) {
      state.nextGenV300.rating = createRatingAccumulator({ routeId: state.routeId, handlingProfile: state.handlingProfile });
    }
    sampleRating(state.nextGenV300.rating, Object.assign({
      speedMph: finiteNumber(state.speed, finiteNumber(state.speedMph, 0)),
      steerInput: finiteNumber(state.steerInput, 0),
      braking: Boolean(state.brake),
      brakePressure: state.nextGenV300.dynamics.brakePressure
    }, sample || {}));
    return state.nextGenV300.rating;
  }

  function recordRunRatingEvent(target, type, detail) {
    var state = target && typeof target === "object" ? target : {};
    initializeNextGenState(state);
    if (!state.nextGenV300.rating) {
      state.nextGenV300.rating = createRatingAccumulator({ routeId: state.routeId, handlingProfile: state.handlingProfile });
    }
    recordRatingEvent(state.nextGenV300.rating, type, detail);
    return state.nextGenV300.rating;
  }

  function getRating(target, shouldFinalize) {
    var state = target && typeof target === "object" ? target : {};
    initializeNextGenState(state);
    if (!state.nextGenV300.rating) {
      state.nextGenV300.rating = createRatingAccumulator({ routeId: state.routeId, handlingProfile: state.handlingProfile });
    }
    return shouldFinalize === false ? state.nextGenV300.rating : finalizeRating(state.nextGenV300.rating);
  }

  function freezeScenarioDeck(deck) {
    return Object.freeze(deck.map(function freezeScenario(scenario) {
      return Object.freeze(Object.assign({}, scenario, {
        telegraph: Object.freeze(Object.assign({}, scenario.telegraph)),
        fairness: Object.freeze(Object.assign({}, scenario.fairness))
      }));
    }));
  }

  var SCENARIO_DECKS = Object.freeze({
    city: freezeScenarioDeck([
      { id: "city-bus-pullout", kind: "pull-out", weight: 1.15, earliestAt: 8, latestAt: 72, duration: 6, telegraph: { leadSeconds: 3.8, title: "VEHICLE PULLING OUT", message: "Indicator ahead · cover the brake", cue: "amber" }, fairness: { minimumTtc: 3.2, clearEscapeLane: true } },
      { id: "city-delivery-van", kind: "door-zone", weight: 1, earliestAt: 12, latestAt: 78, duration: 7, telegraph: { leadSeconds: 4.2, title: "DELIVERY VEHICLE", message: "Narrow lane · hold a safe door gap", cue: "amber" }, fairness: { minimumTtc: 3.4, clearEscapeLane: true } },
      { id: "city-signal-change", kind: "signal-change", weight: 0.9, earliestAt: 16, latestAt: 70, duration: 6, telegraph: { leadSeconds: 4.5, title: "SIGNALS AHEAD", message: "Traffic phase changing", cue: "blue" }, fairness: { minimumTtc: 3.8, clearEscapeLane: false } },
      { id: "city-queue-wave", kind: "phantom-brake", weight: 1.2, earliestAt: 20, latestAt: 82, duration: 7, telegraph: { leadSeconds: 3.5, title: "QUEUE COMPRESSION", message: "Brake lights building ahead", cue: "red" }, fairness: { minimumTtc: 3, clearEscapeLane: true } },
      { id: "city-emergency-vehicle", kind: "emergency-vehicle", weight: 0.65, earliestAt: 28, latestAt: 76, duration: 8, telegraph: { leadSeconds: 5, title: "EMERGENCY VEHICLE", message: "Siren approaching · make space safely", cue: "blue" }, fairness: { minimumTtc: 4, clearEscapeLane: true } }
    ]),
    rural: freezeScenarioDeck([
      { id: "rural-tractor", kind: "slow-vehicle", weight: 1.2, earliestAt: 8, latestAt: 76, duration: 10, telegraph: { leadSeconds: 5, title: "SLOW VEHICLE", message: "Tractor beyond the bend", cue: "amber" }, fairness: { minimumTtc: 4, clearEscapeLane: true } },
      { id: "rural-cyclist", kind: "vulnerable-road-user", weight: 0.95, earliestAt: 14, latestAt: 72, duration: 9, telegraph: { leadSeconds: 5.5, title: "RIDER AHEAD", message: "Wait for a full passing gap", cue: "amber" }, fairness: { minimumTtc: 4.5, clearEscapeLane: true } },
      { id: "rural-blind-crest", kind: "oncoming-crest", weight: 1.1, earliestAt: 18, latestAt: 80, duration: 7, telegraph: { leadSeconds: 4.8, title: "BLIND CREST", message: "Oncoming vehicle · return left", cue: "red" }, fairness: { minimumTtc: 4.2, clearEscapeLane: true } },
      { id: "rural-livestock", kind: "livestock-warning", weight: 0.72, earliestAt: 24, latestAt: 74, duration: 9, telegraph: { leadSeconds: 5.8, title: "LIVESTOCK", message: "Animals close to the carriageway", cue: "amber" }, fairness: { minimumTtc: 4.8, clearEscapeLane: true } },
      { id: "rural-temporary-signals", kind: "temporary-signals", weight: 0.78, earliestAt: 30, latestAt: 78, duration: 10, telegraph: { leadSeconds: 6, title: "TEMPORARY SIGNALS", message: "Prepare to stop beyond the bend", cue: "red" }, fairness: { minimumTtc: 5, clearEscapeLane: false } }
    ]),
    motorway: freezeScenarioDeck([
      { id: "motorway-hgv-overtake", kind: "hgv-overtake", weight: 1.2, earliestAt: 8, latestAt: 74, duration: 10, telegraph: { leadSeconds: 4.5, title: "HGV MOVING OUT", message: "Indicator ahead · adjust your gap", cue: "amber" }, fairness: { minimumTtc: 3.8, clearEscapeLane: true } },
      { id: "motorway-slip-merge", kind: "merge", weight: 1.15, earliestAt: 12, latestAt: 80, duration: 9, telegraph: { leadSeconds: 5, title: "MERGING TRAFFIC", message: "Slip road joining from the left", cue: "blue" }, fairness: { minimumTtc: 4.2, clearEscapeLane: true } },
      { id: "motorway-congestion-wave", kind: "phantom-brake", weight: 1.1, earliestAt: 20, latestAt: 78, duration: 8, telegraph: { leadSeconds: 4.2, title: "TRAFFIC WAVE", message: "Speeds falling across all lanes", cue: "red" }, fairness: { minimumTtc: 3.6, clearEscapeLane: true } },
      { id: "motorway-breakdown", kind: "stranded-vehicle", weight: 0.8, earliestAt: 26, latestAt: 74, duration: 9, telegraph: { leadSeconds: 5.5, title: "REPORT OF OBSTRUCTION", message: "Vehicle stopped ahead", cue: "red" }, fairness: { minimumTtc: 4.5, clearEscapeLane: true } },
      { id: "motorway-variable-limit", kind: "variable-limit", weight: 0.75, earliestAt: 32, latestAt: 80, duration: 10, telegraph: { leadSeconds: 6, title: "VARIABLE SPEED LIMIT", message: "Reduce speed smoothly", cue: "amber" }, fairness: { minimumTtc: 5, clearEscapeLane: false } },
      { id: "motorway-emergency-vehicle", kind: "emergency-vehicle", weight: 0.6, earliestAt: 28, latestAt: 76, duration: 9, telegraph: { leadSeconds: 5.5, title: "EMERGENCY VEHICLE", message: "Blue lights behind · make space", cue: "blue" }, fairness: { minimumTtc: 4.5, clearEscapeLane: true } }
    ])
  });

  function scenarioDeckForRoute(routeId) {
    return SCENARIO_DECKS[normalizeRoute(routeId)];
  }

  function mulberry32(seed) {
    var state = seed >>> 0;
    return function random() {
      state = state + 0x6d2b79f5 >>> 0;
      var value = state;
      value = Math.imul(value ^ value >>> 15, value | 1);
      value ^= value + Math.imul(value ^ value >>> 7, value | 61);
      return ((value ^ value >>> 14) >>> 0) / 4294967296;
    };
  }

  function weightedScenario(available, random) {
    var total = available.reduce(function sum(value, scenario) { return value + scenario.weight; }, 0);
    var cursor = random() * total;
    for (var index = 0; index < available.length; index += 1) {
      cursor -= available[index].weight;
      if (cursor <= 0) return available[index];
    }
    return available[available.length - 1];
  }

  function selectScenarioPlan(options) {
    var opts = options || {};
    var routeId = normalizeRoute(opts.routeId);
    var seed = (finiteNumber(opts.seed, stableHash(routeId + ":scenario")) >>> 0) || 1;
    var random = mulberry32(seed);
    var desiredCount = Math.round(clamp(opts.count == null ? 4 : opts.count, 1, 6));
    var duration = Math.max(30, finiteNumber(opts.durationSeconds, 90));
    var available = scenarioDeckForRoute(routeId).slice();
    var selected = [];

    while (available.length && selected.length < desiredCount) {
      var scenario = weightedScenario(available, random);
      available.splice(available.indexOf(scenario), 1);
      var earliest = Math.max(scenario.earliestAt, 7 + selected.length * duration / (desiredCount + 1));
      var latest = Math.min(scenario.latestAt, duration - scenario.duration - 2);
      var triggerAt = latest <= earliest ? earliest : earliest + random() * (latest - earliest);
      selected.push(Object.assign({}, scenario, {
        triggerAt: Math.round(triggerAt * 10) / 10,
        telegraphAt: Math.round(Math.max(0, triggerAt - scenario.telegraph.leadSeconds) * 10) / 10,
        seed: stableHash(seed + ":" + scenario.id)
      }));
    }
    return selected.sort(function chronological(left, right) { return left.triggerAt - right.triggerAt; });
  }

  function scenarioTelegraph(scenario, elapsedSeconds) {
    if (!scenario) return { visible: false, phase: "inactive", secondsToEvent: Infinity };
    var elapsed = Math.max(0, finiteNumber(elapsedSeconds, 0));
    var trigger = finiteNumber(scenario.triggerAt, scenario.earliestAt || 0);
    var lead = Math.max(0.5, finiteNumber(scenario.telegraph && scenario.telegraph.leadSeconds, 4));
    var seconds = trigger - elapsed;
    var end = trigger + Math.max(0.5, finiteNumber(scenario.duration, 6));
    var phase = elapsed < trigger - lead ? "pending" : elapsed < trigger - 1.25 ? "warning" : elapsed < trigger ? "imminent" : elapsed <= end ? "live" : "complete";
    return {
      id: scenario.id,
      kind: scenario.kind,
      visible: phase === "warning" || phase === "imminent" || phase === "live",
      phase: phase,
      urgency: phase === "imminent" ? "high" : phase === "live" ? "active" : phase === "warning" ? "advisory" : "none",
      title: scenario.telegraph && scenario.telegraph.title || "HALO ROUTE ADVISORY",
      message: scenario.telegraph && scenario.telegraph.message || "Read the road ahead",
      cue: scenario.telegraph && scenario.telegraph.cue || "amber",
      secondsToEvent: Math.max(0, Math.round(seconds * 10) / 10),
      fairness: scenario.fairness || { minimumTtc: 3, clearEscapeLane: true }
    };
  }

  function stepDirector(target, deltaSeconds) {
    var state = target && typeof target === "object" ? target : {};
    initializeNextGenState(state);
    if (!state.nextGenV300.director) configureRun(state, { handlingProfile: state.handlingProfile });
    var director = state.nextGenV300.director;
    var dt = clamp(deltaSeconds, 0, 0.25);
    var canAdvance = !state.phase || state.phase === "riding";
    if (canAdvance) {
      director.elapsedSeconds = Number.isFinite(state.elapsed) ? Math.max(0, state.elapsed) : director.elapsedSeconds + dt;
    }

    var events = [];
    var visible = [];
    var active = null;
    director.plan.forEach(function inspectScenario(scenario) {
      var telegraph = scenarioTelegraph(scenario, director.elapsedSeconds);
      var previousPhase = director.phases[scenario.id] || "pending";
      if (telegraph.phase !== previousPhase) {
        director.phases[scenario.id] = telegraph.phase;
        if (telegraph.phase === "warning" || telegraph.phase === "imminent" || telegraph.phase === "live" || telegraph.phase === "complete") {
          events.push({ type: "scenario-" + telegraph.phase, scenario: scenario, telegraph: telegraph, elapsed: director.elapsedSeconds });
        }
        if (telegraph.phase === "complete" && director.completedScenarioIds.indexOf(scenario.id) === -1) director.completedScenarioIds.push(scenario.id);
      }
      if (telegraph.visible) visible.push({ scenario: scenario, telegraph: telegraph });
      if (telegraph.phase === "live" && !active) active = scenario;
    });
    visible.sort(function rankTelegraphs(left, right) {
      var priority = { live: 0, imminent: 1, warning: 2 };
      return priority[left.telegraph.phase] - priority[right.telegraph.phase] || left.telegraph.secondsToEvent - right.telegraph.secondsToEvent;
    });
    director.telegraph = visible.length ? visible[0].telegraph : null;
    director.activeScenario = active;
    director.emittedEvents = events;
    return {
      elapsedSeconds: director.elapsedSeconds,
      telegraph: director.telegraph,
      activeScenario: director.activeScenario,
      events: events,
      completedScenarioIds: director.completedScenarioIds.slice(),
      complete: director.completedScenarioIds.length >= director.plan.length
    };
  }

  function canonicalStringify(value) {
    if (value === null || typeof value !== "object") return JSON.stringify(value);
    if (Array.isArray(value)) return "[" + value.map(canonicalStringify).join(",") + "]";
    return "{" + Object.keys(value).sort().map(function pair(key) {
      return JSON.stringify(key) + ":" + canonicalStringify(value[key]);
    }).join(",") + "}";
  }

  function stableHash(value) {
    var text = typeof value === "string" ? value : canonicalStringify(value);
    var hash = 0x811c9dc5;
    for (var index = 0; index < text.length; index += 1) {
      var code = text.charCodeAt(index);
      hash ^= code & 0xff;
      hash = Math.imul(hash, 0x01000193);
      hash ^= code >>> 8;
      hash = Math.imul(hash, 0x01000193);
    }
    return hash >>> 0;
  }

  var SHA256_CONSTANTS = Object.freeze([
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
    0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
    0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
    0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
    0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
    0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
  ]);
  var WEEKLY_HASH_PREFIX = "avenra-hyperlane-weekly-works-v1|";
  var WEEK_MILLISECONDS = 7 * 24 * 60 * 60 * 1000;

  function utf8Bytes(value) {
    var text = String(value);
    if (typeof root.TextEncoder === "function") {
      try { return Array.prototype.slice.call(new root.TextEncoder().encode(text)); }
      catch (error) { /* The manual UTF-8 path below is deterministic. */ }
    }
    var bytes = [];
    for (var index = 0; index < text.length; index += 1) {
      var codePoint = text.charCodeAt(index);
      if (codePoint >= 0xd800 && codePoint <= 0xdbff) {
        var next = text.charCodeAt(index + 1);
        if (next >= 0xdc00 && next <= 0xdfff) {
          codePoint = 0x10000 + ((codePoint - 0xd800) << 10) + (next - 0xdc00);
          index += 1;
        } else codePoint = 0xfffd;
      } else if (codePoint >= 0xdc00 && codePoint <= 0xdfff) codePoint = 0xfffd;

      if (codePoint <= 0x7f) bytes.push(codePoint);
      else if (codePoint <= 0x7ff) {
        bytes.push(0xc0 | codePoint >>> 6, 0x80 | codePoint & 0x3f);
      } else if (codePoint <= 0xffff) {
        bytes.push(0xe0 | codePoint >>> 12, 0x80 | codePoint >>> 6 & 0x3f, 0x80 | codePoint & 0x3f);
      } else {
        bytes.push(0xf0 | codePoint >>> 18, 0x80 | codePoint >>> 12 & 0x3f, 0x80 | codePoint >>> 6 & 0x3f, 0x80 | codePoint & 0x3f);
      }
    }
    return bytes;
  }

  function rotateRight(value, bits) {
    return value >>> bits | value << 32 - bits;
  }

  function sha256Hex(value) {
    var bytes = utf8Bytes(value);
    var byteLength = bytes.length;
    var bitLengthHigh = Math.floor(byteLength / 0x20000000) >>> 0;
    var bitLengthLow = byteLength * 8 >>> 0;
    bytes.push(0x80);
    while (bytes.length % 64 !== 56) bytes.push(0);
    bytes.push(bitLengthHigh >>> 24 & 0xff, bitLengthHigh >>> 16 & 0xff, bitLengthHigh >>> 8 & 0xff, bitLengthHigh & 0xff);
    bytes.push(bitLengthLow >>> 24 & 0xff, bitLengthLow >>> 16 & 0xff, bitLengthLow >>> 8 & 0xff, bitLengthLow & 0xff);

    var h0 = 0x6a09e667;
    var h1 = 0xbb67ae85;
    var h2 = 0x3c6ef372;
    var h3 = 0xa54ff53a;
    var h4 = 0x510e527f;
    var h5 = 0x9b05688c;
    var h6 = 0x1f83d9ab;
    var h7 = 0x5be0cd19;
    var words = new Uint32Array(64);

    for (var offset = 0; offset < bytes.length; offset += 64) {
      for (var wordIndex = 0; wordIndex < 16; wordIndex += 1) {
        var byteIndex = offset + wordIndex * 4;
        words[wordIndex] = (bytes[byteIndex] << 24 | bytes[byteIndex + 1] << 16 | bytes[byteIndex + 2] << 8 | bytes[byteIndex + 3]) >>> 0;
      }
      for (var expandIndex = 16; expandIndex < 64; expandIndex += 1) {
        var expand0 = rotateRight(words[expandIndex - 15], 7) ^ rotateRight(words[expandIndex - 15], 18) ^ words[expandIndex - 15] >>> 3;
        var expand1 = rotateRight(words[expandIndex - 2], 17) ^ rotateRight(words[expandIndex - 2], 19) ^ words[expandIndex - 2] >>> 10;
        words[expandIndex] = (words[expandIndex - 16] + expand0 + words[expandIndex - 7] + expand1) >>> 0;
      }

      var a = h0;
      var b = h1;
      var c = h2;
      var d = h3;
      var e = h4;
      var f = h5;
      var g = h6;
      var h = h7;
      for (var round = 0; round < 64; round += 1) {
        var sigma1 = rotateRight(e, 6) ^ rotateRight(e, 11) ^ rotateRight(e, 25);
        var choose = e & f ^ ~e & g;
        var temporary1 = (h + sigma1 + choose + SHA256_CONSTANTS[round] + words[round]) >>> 0;
        var sigma0 = rotateRight(a, 2) ^ rotateRight(a, 13) ^ rotateRight(a, 22);
        var majority = a & b ^ a & c ^ b & c;
        var temporary2 = (sigma0 + majority) >>> 0;
        h = g;
        g = f;
        f = e;
        e = (d + temporary1) >>> 0;
        d = c;
        c = b;
        b = a;
        a = (temporary1 + temporary2) >>> 0;
      }
      h0 = (h0 + a) >>> 0;
      h1 = (h1 + b) >>> 0;
      h2 = (h2 + c) >>> 0;
      h3 = (h3 + d) >>> 0;
      h4 = (h4 + e) >>> 0;
      h5 = (h5 + f) >>> 0;
      h6 = (h6 + g) >>> 0;
      h7 = (h7 + h) >>> 0;
    }
    return [h0, h1, h2, h3, h4, h5, h6, h7].map(function hexWord(word) {
      return (word >>> 0).toString(16).padStart(8, "0");
    }).join("");
  }

  function mondayUtcForDate(date) {
    var parsed = date instanceof Date ? new Date(date.getTime()) : date != null ? new Date(date) : new Date();
    if (!Number.isFinite(parsed.getTime())) parsed = new Date();
    if (parsed.getTime() < 0) parsed = new Date(0);
    var monday = new Date(Date.UTC(parsed.getUTCFullYear(), parsed.getUTCMonth(), parsed.getUTCDate()));
    monday.setUTCDate(monday.getUTCDate() - (monday.getUTCDay() + 6) % 7);
    return monday;
  }

  function isoWeekChallengeId(monday) {
    var thursday = new Date(monday.getTime() + 3 * 24 * 60 * 60 * 1000);
    var isoYear = thursday.getUTCFullYear();
    var januaryFourth = new Date(Date.UTC(isoYear, 0, 4));
    var firstMonday = mondayUtcForDate(januaryFourth);
    var isoWeek = Math.round((monday.getTime() - firstMonday.getTime()) / WEEK_MILLISECONDS) + 1;
    return isoYear + "-W" + String(isoWeek).padStart(2, "0");
  }

  function phpIsoUtc(date) {
    return date.toISOString().replace(/\.000Z$/, "+00:00");
  }

  function enrichWeeklySpec(descriptor, options) {
    var opts = options || {};
    var spec = Object.assign({}, descriptor);
    var digest = sha256Hex(WEEKLY_HASH_PREFIX + spec.challengeId);
    var seed = Math.trunc(finiteNumber(spec.seed, parseInt(digest.substring(0, 7), 16)));
    if (seed <= 0 || seed > 0x0fffffff) seed = parseInt(digest.substring(0, 7), 16) || 1;
    spec.runType = "weekly";
    spec.rideMode = 3;
    spec.seed = seed;

    /* Compatibility aliases consumed by the static bundle and replay helpers. */
    spec.id = "works-" + spec.challengeId;
    spec.version = VERSION;
    spec.weekStartsUtc = new Date(spec.startsAt).toISOString();
    spec.weekEndsUtc = new Date(spec.endsAt).toISOString();
    spec.handlingProfile = normalizeHandlingProfile(opts.handlingProfile || spec.handlingProfile || HANDLING_PROFILES.ROAD);
    spec.trafficSeed = seed;
    spec.hazardSeed = seed;
    spec.scenarioSeed = seed;
    spec.scenarios = Array.isArray(spec.scenarios) ? spec.scenarios.slice() : selectScenarioPlan({
      routeId: spec.routeId,
      seed: seed,
      count: opts.scenarioCount == null ? 4 : opts.scenarioCount,
      durationSeconds: opts.durationSeconds || 90
    });
    spec.hash = digest;
    return spec;
  }

  function weeklySpecHash(spec) {
    var challengeId = typeof spec === "string" ? spec : spec && spec.challengeId;
    if (typeof challengeId === "string" && /^\d{4}-W\d{2}$/.test(challengeId)) return sha256Hex(WEEKLY_HASH_PREFIX + challengeId);
    var clone = Object.assign({}, spec || {});
    delete clone.hash;
    return stableHash(clone).toString(16).padStart(8, "0");
  }

  function weeklySpec(date, options) {
    var opts = options || {};
    var monday = mondayUtcForDate(date);
    var end = new Date(monday.getTime() + WEEK_MILLISECONDS);
    var challengeId = isoWeekChallengeId(monday);
    var digest = sha256Hex(WEEKLY_HASH_PREFIX + challengeId);
    var weekIndex = Math.trunc(monday.getTime() / WEEK_MILLISECONDS);
    var routes = ["city", "rural", "motorway"];
    var times = ["day", "night"];
    var weathers = ["clear", "rain"];
    var routeIndex = (weekIndex % routes.length + routes.length) % routes.length;
    var seed = parseInt(digest.substring(0, 7), 16) || 1;
    return enrichWeeklySpec({
      challengeId: challengeId,
      runType: "weekly",
      routeId: routes[routeIndex],
      timeOfDay: times[parseInt(digest.substring(7, 9), 16) % times.length],
      weather: weathers[parseInt(digest.substring(9, 11), 16) % weathers.length],
      rideMode: 3,
      seed: seed,
      startsAt: phpIsoUtc(monday),
      endsAt: phpIsoUtc(end)
    }, opts);
  }

  function weeklySpecFromConfig(descriptor, options) {
    if (!descriptor || typeof descriptor !== "object" || !/^\d{4}-W\d{2}$/.test(String(descriptor.challengeId || ""))) {
      return weeklySpec(options && options.date, options);
    }
    var spec = Object.assign({}, descriptor);
    var parsedStart = new Date(spec.startsAt);
    var parsedEnd = new Date(spec.endsAt);
    if (!Number.isFinite(parsedStart.getTime()) || !Number.isFinite(parsedEnd.getTime())) return weeklySpec(options && options.date, options);
    spec.routeId = normalizeRoute(spec.routeId);
    spec.timeOfDay = spec.timeOfDay === "night" || spec.timeOfDay === "dusk" ? "night" : "day";
    spec.weather = spec.weather === "rain" || spec.weather === "storm" || spec.weather === "post-rain" ? "rain" : "clear";
    return enrichWeeklySpec(spec, options);
  }

  var namespace = root.AvenraNextGenV300 || {};
  var dynamicsApi = {
    HANDLING_PROFILES: HANDLING_PROFILES,
    WEATHER_GRIP: WEATHER_GRIP,
    ROUTE_GRIP: ROUTE_GRIP,
    initializeNextGenState: initializeNextGenState,
    resetNextGenState: resetNextGenState,
    configureRun: configureRun,
    stepRiderDynamics: stepRiderDynamics,
    playerAwareSpeed: playerAwareSpeed,
    createRiderSpringState: createRiderSpringState,
    updateRiderSpringMetrics: updateRiderSpringMetrics
  };
  var audioApi = {
    AUDIO_SAMPLE_PATHS: AUDIO_SAMPLE_PATHS,
    AUDIO_SOURCE_PROVENANCE: AUDIO_SOURCE_PROVENANCE,
    initializeSpatialAudio: initializeSpatialAudio,
    initSpatialAudio: initializeSpatialAudio,
    startSpatialAudioSamples: startSpatialAudioSamples,
    resumeSpatialAudio: resumeSpatialAudio,
    setSpatialAudioMuted: setSpatialAudioMuted,
    updateSpatialAudio: updateSpatialAudio,
    updateSpatialAudioFrame: updateSpatialAudioFrame,
    disposeSpatialAudio: disposeSpatialAudio
  };
  var ratingApi = {
    RATING_DIMENSIONS: RATING_DIMENSIONS,
    RATING_WEIGHTS: RATING_WEIGHTS,
    createRatingAccumulator: createRatingAccumulator,
    sampleRating: sampleRating,
    recordRatingEvent: recordRatingEvent,
    finalizeRating: finalizeRating,
    ratingCoaching: ratingCoaching,
    sampleRunRating: sampleRunRating,
    recordRunRatingEvent: recordRunRatingEvent,
    getRating: getRating
  };
  var routeDirectorApi = {
    SCENARIO_DECKS: SCENARIO_DECKS,
    scenarioDeckForRoute: scenarioDeckForRoute,
    selectScenarioPlan: selectScenarioPlan,
    scenarioTelegraph: scenarioTelegraph,
    stepDirector: stepDirector
  };
  var weeklyApi = {
    stableHash: stableHash,
    canonicalStringify: canonicalStringify,
    sha256Hex: sha256Hex,
    weeklySpec: weeklySpec,
    weeklySpecFromConfig: weeklySpecFromConfig,
    weeklySpecHash: weeklySpecHash
  };

  namespace.version = VERSION;
  namespace.dynamics = Object.assign(namespace.dynamics || {}, dynamicsApi);
  namespace.audio = Object.assign(namespace.audio || {}, audioApi);
  namespace.rating = Object.assign(namespace.rating || {}, ratingApi);
  namespace.routeDirector = Object.assign(namespace.routeDirector || {}, routeDirectorApi);
  namespace.weekly = Object.assign(namespace.weekly || {}, weeklyApi);
  Object.assign(namespace, dynamicsApi, audioApi, ratingApi, routeDirectorApi, weeklyApi);
  root.AvenraNextGenV300 = namespace;
})(typeof window !== "undefined" ? window : globalThis);
