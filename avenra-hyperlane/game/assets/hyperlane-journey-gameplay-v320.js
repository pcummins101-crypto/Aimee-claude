/*
 * Avenra Hyperlane 3.2.0 -- distance-authored journey, traffic, safety and cockpit helpers.
 *
 * Additive by design: load after hyperlane-dynamics-audio-v300.js. This file
 * keeps the 3.0.0 namespace/object identity and wraps its public entry points.
 */
(function installAvenraJourneyGameplayV320(root) {
  "use strict";

  var namespace = root.AvenraNextGenV300;
  if (!namespace || namespace.__journeyGameplayV320Installed) return;
  if (typeof namespace.configureRun !== "function" || typeof namespace.stepDirector !== "function") return;

  var VERSION = "3.2.0";
  var MPH_TO_MPS = 0.44704;
  var TWO_PI = Math.PI * 2;
  var FLOW_LEDGER_LIMIT = 160;
  var SAFETY_LEDGER_LIMIT = 180;
  var MECHANIC_LEDGER_LIMIT = 96;
  var CHAPTER_MIN_METRES = 80;
  var CHAPTER_MAX_METRES = 120;
  var CHAPTER_WINDOW_BEHIND = 1;
  var CHAPTER_WINDOW_AHEAD = 4;
  var DEFAULT_RUN_SECONDS = 90;
  var MODE_SPEEDS_MPH = Object.freeze({ 1: 60, 2: 90, 3: 109 });
  var ROUTE_DISTANCE_FACTORS = Object.freeze({ city: 0.67, rural: 0.72, motorway: 0.78 });

  var originals = Object.freeze({
    configureRun: namespace.configureRun,
    stepDirector: namespace.stepDirector,
    playerAwareSpeed: namespace.playerAwareSpeed,
    createRatingAccumulator: namespace.createRatingAccumulator,
    sampleRunRating: namespace.sampleRunRating,
    recordRatingEvent: namespace.recordRatingEvent,
    recordRunRatingEvent: namespace.recordRunRatingEvent,
    finalizeRating: namespace.finalizeRating,
    getRating: namespace.getRating
  });

  function finiteNumber(value, fallback) {
    return Number.isFinite(value) ? value : fallback;
  }

  function clamp(value, minimum, maximum) {
    return Math.min(maximum, Math.max(minimum, finiteNumber(value, minimum)));
  }

  function damp(current, target, rate, deltaSeconds) {
    return current + (target - current) * (1 - Math.exp(-Math.max(0, rate) * Math.max(0, deltaSeconds)));
  }

  function smoothstep(edge0, edge1, value) {
    var width = edge1 - edge0;
    if (Math.abs(width) < 1e-9) return value < edge0 ? 0 : 1;
    var amount = clamp((value - edge0) / width, 0, 1);
    return amount * amount * (3 - 2 * amount);
  }

  function oneDecimal(value) {
    return Math.round(finiteNumber(value, 0) * 10) / 10;
  }

  function normalizeRoute(routeId) {
    return routeId === "rural" || routeId === "motorway" ? routeId : "city";
  }

  function stableHash(value) {
    if (typeof namespace.stableHash === "function") return namespace.stableHash(value);
    var text = String(value);
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

  function phaseFor(seed, label) {
    return (stableHash(String(seed >>> 0) + ":" + label) / 4294967296) * TWO_PI;
  }

  function routeChapterLibrary(routeId) {
    var libraries = {
      city: {
        enter: { id: "city-ring-entry", label: "Ring Road Inbound", acousticZone: "open-city", density: 0.76, oncomingBias: 0.4 },
        loops: [
          { id: "city-brick-district", label: "Brick District", acousticZone: "urban-canyon", density: 0.92, oncomingBias: 0.43 },
          { id: "city-railway-arches", label: "Railway Arches", acousticZone: "underpass", density: 0.86, oncomingBias: 0.38 },
          { id: "city-bus-corridor", label: "Bus Corridor", acousticZone: "urban-canyon", density: 1, oncomingBias: 0.46 },
          { id: "city-riverside", label: "Riverside Link", acousticZone: "open-city", density: 0.72, oncomingBias: 0.37 },
          { id: "city-retail-quarter", label: "Retail Quarter", acousticZone: "built-up", density: 0.96, oncomingBias: 0.44 },
          { id: "city-underpass", label: "City Underpass", acousticZone: "tunnel", density: 0.7, oncomingBias: 0.28 }
        ],
        exit: { id: "city-a-road-exit", label: "A-Road Outbound", acousticZone: "open-city", density: 0.68, oncomingBias: 0.38 }
      },
      rural: {
        enter: { id: "rural-market-town-entry", label: "Market Town Edge", acousticZone: "village", density: 0.72, oncomingBias: 0.67 },
        loops: [
          { id: "rural-hedge-tunnel", label: "Hedgerow Lane", acousticZone: "hedgerow", density: 0.86, oncomingBias: 0.73 },
          { id: "rural-dry-stone", label: "Dry-Stone Farmland", acousticZone: "open-rural", density: 0.82, oncomingBias: 0.72 },
          { id: "rural-village-thirty", label: "Village Thirty", acousticZone: "village", density: 0.92, oncomingBias: 0.7 },
          { id: "rural-woodland", label: "Woodland Bends", acousticZone: "woodland", density: 0.88, oncomingBias: 0.76 },
          { id: "rural-moorland", label: "Open Moor", acousticZone: "open-rural", density: 0.78, oncomingBias: 0.74 },
          { id: "rural-dual-carriageway", label: "Dual Carriageway", acousticZone: "a-road", density: 1.02, oncomingBias: 0.82 }
        ],
        exit: { id: "rural-pass-exit", label: "Pennine Climb", acousticZone: "open-rural", density: 0.76, oncomingBias: 0.74 }
      },
      motorway: {
        enter: { id: "m1-slip-entry", label: "M1 Slip Road", acousticZone: "motorway-open", density: 0.84, oncomingBias: 0 },
        loops: [
          { id: "m1-smart-motorway", label: "Smart Motorway", acousticZone: "motorway-open", density: 0.96, oncomingBias: 0 },
          { id: "m1-open-cutting", label: "Open Cutting", acousticZone: "motorway-cutting", density: 0.85, oncomingBias: 0 },
          { id: "m1-services-approach", label: "Services Approach", acousticZone: "services", density: 1.03, oncomingBias: 0 },
          { id: "m1-roadworks", label: "Managed Roadworks", acousticZone: "roadworks", density: 0.94, oncomingBias: 0 },
          { id: "m1-warehouse-belt", label: "Logistics Belt", acousticZone: "motorway-built-up", density: 1, oncomingBias: 0 },
          { id: "m1-concrete-reservation", label: "Concrete Reservation", acousticZone: "motorway-cutting", density: 0.9, oncomingBias: 0 }
        ],
        exit: { id: "m1-northbound-exit", label: "M1 Northbound", acousticZone: "motorway-open", density: 0.78, oncomingBias: 0 }
      }
    };
    return libraries[normalizeRoute(routeId)];
  }

  var VISUAL_CHAPTER_META = Object.freeze({
    city: Object.freeze({
      "district-gateway": { acousticZone: "open-city", density: 0.78, oncomingBias: 0.40, scenarioKinds: ["pull-out", "emergency-vehicle"] },
      "red-brick-corridor": { acousticZone: "urban-canyon", density: 0.96, oncomingBias: 0.44, scenarioKinds: ["door-zone", "phantom-brake"] },
      "rail-quarter": { acousticZone: "underpass", density: 0.86, oncomingBias: 0.38, scenarioKinds: ["phantom-brake", "emergency-vehicle"] },
      "civic-boulevard": { acousticZone: "urban-canyon", density: 0.94, oncomingBias: 0.46, scenarioKinds: ["signal-change", "pull-out"] },
      "warehouse-quarter": { acousticZone: "built-up", density: 0.98, oncomingBias: 0.43, scenarioKinds: ["door-zone", "phantom-brake"] },
      "expressway-approach": { acousticZone: "open-city", density: 0.78, oncomingBias: 0.37, scenarioKinds: ["phantom-brake", "emergency-vehicle"] }
    }),
    rural: Object.freeze({
      "open-dales": { acousticZone: "open-rural", density: 0.78, oncomingBias: 0.74, scenarioKinds: ["oncoming-crest", "vulnerable-road-user"] },
      "dry-stone-run": { acousticZone: "open-rural", density: 0.86, oncomingBias: 0.73, scenarioKinds: ["livestock-warning", "vulnerable-road-user"] },
      "farmstead-rise": { acousticZone: "open-rural", density: 0.84, oncomingBias: 0.72, scenarioKinds: ["slow-vehicle", "livestock-warning"] },
      "village-edge": { acousticZone: "village", density: 0.94, oncomingBias: 0.70, scenarioKinds: ["temporary-signals", "vulnerable-road-user"] },
      "wooded-cutting": { acousticZone: "woodland", density: 0.88, oncomingBias: 0.77, scenarioKinds: ["oncoming-crest", "slow-vehicle"] },
      "moorland-crossing": { acousticZone: "open-rural", density: 0.74, oncomingBias: 0.78, scenarioKinds: ["oncoming-crest", "livestock-warning"] },
      "dual-carriageway": { acousticZone: "a-road", density: 1.02, oncomingBias: 0.82, scenarioKinds: ["oncoming-crest", "slow-vehicle"] }
    }),
    motorway: Object.freeze({
      "luton-approach": { acousticZone: "motorway-open", density: 0.88, oncomingBias: 0, scenarioKinds: ["merge", "hgv-overtake"] },
      "smart-motorway": { acousticZone: "motorway-open", density: 0.96, oncomingBias: 0, scenarioKinds: ["variable-limit", "phantom-brake"] },
      "chalk-cutting": { acousticZone: "motorway-cutting", density: 0.82, oncomingBias: 0, scenarioKinds: ["stranded-vehicle", "phantom-brake"] },
      "logistics-belt": { acousticZone: "motorway-built-up", density: 1.02, oncomingBias: 0, scenarioKinds: ["hgv-overtake", "merge"] },
      "works-sector": { acousticZone: "roadworks", density: 0.98, oncomingBias: 0, scenarioKinds: ["hgv-overtake", "stranded-vehicle"] },
      "services-run": { acousticZone: "services", density: 1.03, oncomingBias: 0, scenarioKinds: ["merge", "phantom-brake"] },
      "northbound-open": { acousticZone: "motorway-open", density: 0.84, oncomingBias: 0, scenarioKinds: ["emergency-vehicle", "hgv-overtake"] }
    })
  });

  function worldApi() {
    var world = namespace.world;
    return world && typeof world === "object" ? world : namespace;
  }

  function plannedRunDistance(state, options) {
    var opts = options || {};
    var explicit = finiteNumber(opts.plannedDistanceMetres, finiteNumber(opts.routeDistanceMetres, finiteNumber(state.plannedDistanceMetres, NaN)));
    if (Number.isFinite(explicit)) return clamp(explicit, CHAPTER_MIN_METRES * 3, 5200);
    var routeId = normalizeRoute(opts.routeId || state.routeId);
    var duration = Math.max(40, finiteNumber(opts.durationSeconds, finiteNumber(state.runDurationSeconds, DEFAULT_RUN_SECONDS)));
    var rideMode = Math.round(clamp(finiteNumber(opts.rideMode, finiteNumber(state.rideMode, 3)), 1, 3));
    var referenceMph = finiteNumber(opts.referenceSpeedMph, MODE_SPEEDS_MPH[rideMode]);
    var distance = referenceMph * MPH_TO_MPS * duration * ROUTE_DISTANCE_FACTORS[routeId];
    return clamp(Math.round(distance / 10) * 10, 960, 4400);
  }

  function defaultChapterMeta(routeId, visual) {
    var library = routeChapterLibrary(routeId);
    var fallback = routeId === "motorway" ? library.loops[0] : routeId === "rural" ? library.loops[1] : library.loops[0];
    var visualId = String(visual && visual.id || "");
    var direct = VISUAL_CHAPTER_META[routeId] && VISUAL_CHAPTER_META[routeId][visualId];
    return Object.assign({}, fallback, direct || {}, {
      visualId: visualId || fallback.id,
      scenarioKinds: (direct && direct.scenarioKinds || []).slice()
    });
  }

  function copyDistanceChapter(source, visual, role, index, startMetres, endMetres, routeId, duration, plannedDistance) {
    var meta = Object.assign({}, source || {});
    var visualValue = visual && typeof visual === "object" ? visual : {};
    var start = Math.max(0, finiteNumber(visualValue.startMetres, startMetres));
    var end = Math.max(start + CHAPTER_MIN_METRES, finiteNumber(visualValue.endMetres, endMetres));
    var length = end - start;
    var startsAt = duration * start / Math.max(1, plannedDistance);
    var endsAt = duration * end / Math.max(1, plannedDistance);
    var visualId = String(visualValue.id || meta.visualId || meta.id || (routeId + "-chapter-" + index));
    return Object.freeze(Object.assign({}, visualValue, meta, {
      id: visualId + "@" + Math.round(start),
      gameplayId: meta.id || visualId,
      visualId: visualId,
      routeId: routeId,
      role: role,
      kind: role === "middle" ? "loop" : role,
      visualRole: visualValue.role || null,
      index: index,
      startMetres: oneDecimal(start),
      endMetres: oneDecimal(end),
      lengthMetres: oneDecimal(length),
      startsAt: oneDecimal(startsAt),
      endsAt: oneDecimal(endsAt),
      durationSeconds: oneDecimal(Math.max(0, endsAt - startsAt)),
      acousticZone: meta.acousticZone || "open",
      density: finiteNumber(meta.density, finiteNumber(visualValue.density, 0.85)),
      oncomingBias: finiteNumber(meta.oncomingBias, routeId === "rural" ? 0.7 : routeId === "city" ? 0.4 : 0),
      scenarioKinds: Object.freeze((meta.scenarioKinds || []).slice()),
      transitionMask: visualValue.transitionMask || null
    }));
  }

  function sampleWorldChapterPlan(state, options, plannedDistance, duration) {
    var world = worldApi();
    if (!world || typeof world.getVisualChapter !== "function") return null;
    var routeId = normalizeRoute(options.routeId || state.routeId);
    var seed = (finiteNumber(options.runSeed, finiteNumber(state.nextGenV300 && state.nextGenV300.runSeed, finiteNumber(state.trafficSeed, 1))) >>> 0) || 1;
    var sampled = [];
    var cursor = 0;
    for (var index = 0; index < 72 && cursor < plannedDistance; index += 1) {
      var visual;
      try {
        visual = world.getVisualChapter(Object.assign({}, state, {
          routeId: routeId,
          worldDistance: cursor + 0.001,
          trafficSeed: seed
        }), { routeId: routeId, worldDistance: cursor + 0.001, seed: seed, runSeed: seed });
      } catch (error) { return null; }
      var length = finiteNumber(visual && visual.lengthMetres, NaN);
      var start = finiteNumber(visual && visual.startMetres, cursor);
      var end = finiteNumber(visual && visual.endMetres, start + length);
      if (!visual || !Number.isFinite(length) || length < CHAPTER_MIN_METRES - 0.1 || length > CHAPTER_MAX_METRES + 0.1 || !(end > cursor + 1)) return null;
      sampled.push({ visual: visual, start: start, end: end });
      cursor = end;
    }
    if (sampled.length < 3) return null;
    return Object.freeze(sampled.map(function decorate(sample, index) {
      var role = index === 0 ? "entry" : index === sampled.length - 1 ? "exit" : "middle";
      return copyDistanceChapter(defaultChapterMeta(routeId, sample.visual), sample.visual, role, index, sample.start, sample.end, routeId, duration, cursor);
    }));
  }

  function fallbackChapterLengths(totalDistance, seed) {
    var count = Math.max(3, Math.round(totalDistance / 100));
    while (totalDistance / count > CHAPTER_MAX_METRES) count += 1;
    while (count > 3 && totalDistance / count < CHAPTER_MIN_METRES) count -= 1;
    var random = mulberry32(seed);
    var lengths = [];
    var remaining = totalDistance;
    for (var index = 0; index < count; index += 1) {
      var slots = count - index;
      var minimum = Math.max(CHAPTER_MIN_METRES, remaining - (slots - 1) * CHAPTER_MAX_METRES);
      var maximum = Math.min(CHAPTER_MAX_METRES, remaining - (slots - 1) * CHAPTER_MIN_METRES);
      var ideal = remaining / slots;
      var jitter = slots === 1 ? 0 : (random() - 0.5) * 18;
      var length = slots === 1 ? remaining : clamp(ideal + jitter, minimum, maximum);
      length = oneDecimal(length);
      lengths.push(length);
      remaining = oneDecimal(remaining - length);
    }
    return lengths;
  }

  function fallbackChapterPlan(state, options, plannedDistance, duration, seed) {
    var routeId = normalizeRoute(options.routeId || state.routeId);
    var library = routeChapterLibrary(routeId);
    var pool = library.loops.slice();
    var random = mulberry32(stableHash(seed + ":fallback-chapters:" + routeId));
    for (var index = pool.length - 1; index > 0; index -= 1) {
      var swap = Math.floor(random() * (index + 1));
      var temporary = pool[index];
      pool[index] = pool[swap];
      pool[swap] = temporary;
    }
    var lengths = fallbackChapterLengths(plannedDistance, stableHash(seed + ":chapter-lengths:" + routeId));
    var cursor = 0;
    return Object.freeze(lengths.map(function make(length, index) {
      var role = index === 0 ? "entry" : index === lengths.length - 1 ? "exit" : "middle";
      var source = role === "entry" ? library.enter : role === "exit" ? library.exit : pool[(index - 1) % pool.length];
      var end = cursor + length;
      var chapter = copyDistanceChapter(source, { id: source.id, title: source.label }, role, index, cursor, end, routeId, duration, plannedDistance);
      cursor = end;
      return chapter;
    }));
  }

  function buildChapterPlan(state, options) {
    var target = state && typeof state === "object" ? state : {};
    var opts = options || {};
    var routeId = normalizeRoute(opts.routeId || target.routeId);
    var nextGen = target.nextGenV300 || {};
    var runSeed = (finiteNumber(opts.runSeed, finiteNumber(nextGen.runSeed, finiteNumber(target.trafficSeed, 1))) >>> 0) || 1;
    var duration = Math.max(40, finiteNumber(opts.durationSeconds, finiteNumber(target.runDurationSeconds, DEFAULT_RUN_SECONDS)));
    var plannedDistance = plannedRunDistance(target, Object.assign({}, opts, { routeId: routeId, durationSeconds: duration }));
    var worldPlan = opts.useWorldChapters === false ? null : sampleWorldChapterPlan(target, Object.assign({}, opts, { routeId: routeId, runSeed: runSeed }), plannedDistance, duration);
    return worldPlan || fallbackChapterPlan(target, Object.assign({}, opts, { routeId: routeId }), plannedDistance, duration, runSeed);
  }

  function chapterAtDistance(chapters, worldDistance) {
    if (!Array.isArray(chapters) || !chapters.length) return null;
    var distance = Math.max(0, finiteNumber(worldDistance, 0));
    for (var index = 0; index < chapters.length; index += 1) {
      if (distance < chapters[index].endMetres || index === chapters.length - 1) return chapters[index];
    }
    return chapters[chapters.length - 1];
  }

  function chapterWindow(chapters, worldDistance, beforeCount, aheadCount) {
    if (!Array.isArray(chapters) || !chapters.length) return Object.freeze([]);
    var current = chapterAtDistance(chapters, worldDistance);
    var currentIndex = current ? current.index : 0;
    var behind = Math.max(0, Math.round(finiteNumber(beforeCount, CHAPTER_WINDOW_BEHIND)));
    var ahead = Math.max(1, Math.round(finiteNumber(aheadCount, CHAPTER_WINDOW_AHEAD)));
    var desiredCount = Math.min(chapters.length, Math.round(clamp(behind + ahead + 1, 4, 6)));
    var start = Math.max(0, currentIndex - behind);
    var end = Math.min(chapters.length, start + desiredCount);
    start = Math.max(0, end - desiredCount);
    return Object.freeze(chapters.slice(start, end).map(function mark(chapter) {
      return Object.freeze(Object.assign({}, chapter, {
        relativeIndex: chapter.index - currentIndex,
        active: chapter.index === currentIndex,
        behind: chapter.index < currentIndex,
        ahead: chapter.index > currentIndex
      }));
    }));
  }

  function worldBufferForState(state, worldDistance) {
    var world = worldApi();
    var getter = world && (world.getVisualChapterBuffer || world.getChapterWindow || world.getRollingChapterBuffer);
    if (typeof getter !== "function") return null;
    try {
      return getter.call(world, Object.assign({}, state, { worldDistance: worldDistance }), {
        worldDistance: worldDistance,
        seed: state.nextGenV300 && state.nextGenV300.runSeed,
        behind: CHAPTER_WINDOW_BEHIND,
        ahead: CHAPTER_WINDOW_AHEAD,
        count: CHAPTER_WINDOW_BEHIND + CHAPTER_WINDOW_AHEAD + 1
      });
    } catch (error) { return null; }
  }

  function chapterBufferForState(target, explicitDistance) {
    var state = target && typeof target === "object" ? target : {};
    var nextGen = state.nextGenV300 || {};
    var journey = nextGen.journeyV320 || nextGen.journeyV310 || nextGen.journey;
    if (!journey || !journey.chapters) return null;
    var distance = Number.isFinite(explicitDistance) ? Math.max(0, explicitDistance) : Math.max(0, finiteNumber(state.worldDistance, finiteNumber(journey.worldDistanceMetres, 0)));
    var rolling = chapterWindow(journey.chapters, distance, CHAPTER_WINDOW_BEHIND, CHAPTER_WINDOW_AHEAD);
    var current = rolling.filter(function active(chapter) { return chapter.active; })[0] || chapterAtDistance(journey.chapters, distance);
    var next = current && journey.chapters[current.index + 1] || null;
    var progress = current ? clamp((distance - current.startMetres) / Math.max(1, current.lengthMetres), 0, 1) : 0;
    return {
      version: VERSION,
      source: journey.chapterSource || "fallback",
      worldDistance: distance,
      currentIndex: current ? current.index : 0,
      current: current,
      next: next,
      chapters: rolling,
      rolling: rolling,
      progress: progress,
      mix: current && Number.isFinite(current.transition) ? current.transition : smoothstep(0.78, 1, progress),
      visualBuffer: worldBufferForState(state, distance)
    };
  }

  function chapterForState(target, worldDistance) {
    var buffer = chapterBufferForState(target, worldDistance);
    return buffer && buffer.current || null;
  }

  function cloneScenario(scenario) {
    return Object.assign({}, scenario, {
      telegraph: Object.assign({}, scenario && scenario.telegraph || {}),
      fairness: Object.assign({}, scenario && scenario.fairness || {}),
      limitState: scenario && scenario.limitState ? Object.assign({}, scenario.limitState) : undefined
    });
  }

  function scenarioChapterScore(routeId, scenario, chapter, preferredIndex, runSeed) {
    var kind = String(scenario && scenario.kind || "");
    var text = [chapter.visualId, chapter.gameplayId, chapter.title, chapter.label, chapter.cue, chapter.acousticZone].join(" ").toLowerCase();
    var score = chapter.role === "middle" ? 2.5 : -3;
    if ((chapter.scenarioKinds || []).indexOf(kind) >= 0) score += 18;
    var rules = {
      "pull-out": ["district", "brick", "boulevard", "bus", "village"],
      "door-zone": ["brick", "warehouse", "retail", "built-up"],
      "signal-change": ["boulevard", "civic", "retail", "urban"],
      "phantom-brake": ["smart", "cutting", "expressway", "urban", "services"],
      "emergency-vehicle": ["open", "boulevard", "northbound", "motorway"],
      "slow-vehicle": ["farm", "dales", "stone", "wood"],
      "vulnerable-road-user": ["village", "dales", "stone", "open-rural"],
      "oncoming-crest": ["moor", "wood", "dual", "dales"],
      "livestock-warning": ["farm", "moor", "dales", "rural"],
      "temporary-signals": ["village", "wood", "stone"],
      "hgv-overtake": ["logistics", "northbound", "motorway", "smart"],
      merge: ["approach", "luton", "services", "entry"],
      "stranded-vehicle": ["cutting", "works", "smart"],
      "variable-limit": ["smart", "gantry", "motorway-open"]
    };
    (rules[kind] || []).forEach(function affinity(token) { if (text.indexOf(token) >= 0) score += 3; });
    score -= Math.abs(chapter.index - preferredIndex) * 0.32;
    score += (stableHash(runSeed + ":scenario-fit:" + scenario.id + ":" + chapter.id) / 4294967296) * 0.2;
    return score;
  }

  function assignScenarioChapters(state, scenarios, chapters) {
    if (!scenarios.length || !chapters.length) return scenarios.map(function noChapter(scenario) { return { scenario: scenario, chapter: null, score: 0 }; });
    var runSeed = (finiteNumber(state.nextGenV300 && state.nextGenV300.runSeed, finiteNumber(state.trafficSeed, 1)) >>> 0) || 1;
    var available = chapters.filter(function suitable(chapter) { return chapter.role === "middle"; });
    if (!available.length) available = chapters.slice();
    var used = Object.create(null);
    return scenarios.map(function assign(scenario, scenarioIndex) {
      var preferred = (scenarioIndex + 1) * (chapters.length - 1) / (scenarios.length + 1);
      var ranked = available.map(function rank(chapter) {
        return { chapter: chapter, score: scenarioChapterScore(normalizeRoute(state.routeId), scenario, chapter, preferred, runSeed) };
      }).sort(function best(left, right) { return right.score - left.score || left.chapter.index - right.chapter.index; });
      var selected = ranked.filter(function unused(candidate) { return !used[candidate.chapter.id]; })[0] || ranked[0];
      if (selected) used[selected.chapter.id] = true;
      return { scenario: scenario, chapter: selected && selected.chapter || null, score: selected && selected.score || 0 };
    }).sort(function routeOrder(left, right) {
      return finiteNumber(left.chapter && left.chapter.index, 999) - finiteNumber(right.chapter && right.chapter.index, 999);
    });
  }

  function variableLimitForScenario(scenario, runSeed, chapter) {
    if (!scenario || scenario.kind !== "variable-limit") return scenario;
    var choices = [40, 50, 60];
    var choice = choices[stableHash(runSeed + ":variable-limit:" + scenario.id + ":" + (chapter && chapter.visualId || "open")) % choices.length];
    scenario.speedLimitMph = choice;
    scenario.postedLimitMph = choice;
    scenario.mechanicKind = "variable-limit";
    scenario.telegraph.title = choice + " MPH VARIABLE LIMIT";
    scenario.telegraph.message = choice + " MPH shown on the gantry · reduce speed smoothly";
    scenario.limitState = {
      kind: "variable-limit",
      postedLimitMph: choice,
      effectiveLimitMph: choice,
      gantrySignal: String(choice),
      enforcement: "active-limit",
      active: false,
      phase: "pending"
    };
    return scenario;
  }

  function durationBudget(durations, budget) {
    if (!durations.length) return [];
    var requestedTotal = durations.reduce(function total(sum, duration) { return sum + duration; }, 0);
    if (requestedTotal <= budget) return durations.slice();
    var minimum = Math.min(3, Math.max(1, budget / durations.length));
    var minimumTotal = minimum * durations.length;
    var extras = durations.map(function extra(duration) { return Math.max(0, duration - minimum); });
    var extraTotal = extras.reduce(function sum(total, value) { return total + value; }, 0);
    var availableExtra = Math.max(0, budget - minimumTotal);
    return durations.map(function allocate(duration, index) {
      if (!extraTotal) return minimum;
      return minimum + availableExtra * extras[index] / extraTotal;
    });
  }

  function scheduleScenarios(state, sourcePlan, options, chapters) {
    var opts = options || {};
    var runSeed = (finiteNumber(state.nextGenV300 && state.nextGenV300.runSeed, finiteNumber(state.trafficSeed, 1)) >>> 0) || 1;
    var duration = Math.max(30, finiteNumber(opts.durationSeconds, finiteNumber(state.runDurationSeconds, 90)));
    var minimumWarning = Math.max(3.5, finiteNumber(opts.minimumWarningSeconds, 4.5));
    var minimumCooldown = Math.max(2, finiteNumber(opts.scenarioCooldownSeconds, 3.5));
    var startMargin = Math.max(3, finiteNumber(opts.scenarioStartMarginSeconds, 5));
    var endMargin = Math.max(2, finiteNumber(opts.scenarioEndMarginSeconds, 3));
    var source = Array.isArray(sourcePlan) ? sourcePlan.map(cloneScenario) : [];
    var available = Math.max(1, duration - startMargin - endMargin);

    while (source.length > 1) {
      var minimumRequired = source.reduce(function total(sum, scenario) {
        return sum + Math.max(minimumWarning, finiteNumber(scenario.telegraph && scenario.telegraph.leadSeconds, minimumWarning)) + 3;
      }, 0) + minimumCooldown * (source.length - 1);
      if (minimumRequired <= available) break;
      source.pop();
    }

    var assignments = assignScenarioChapters(state, source, chapters);
    source = assignments.map(function assignedScenario(assignment) { return assignment.scenario; });

    var leads = source.map(function warning(scenario) {
      return Math.max(minimumWarning, finiteNumber(scenario.telegraph && scenario.telegraph.leadSeconds, minimumWarning));
    });
    var requestedDurations = source.map(function scenarioDuration(scenario) {
      return Math.max(3, finiteNumber(scenario.duration, 6));
    });
    var leadTotal = leads.reduce(function sum(total, value) { return total + value; }, 0);
    var durationSpace = Math.max(source.length, available - leadTotal - minimumCooldown * Math.max(0, source.length - 1));
    var scheduledDurations = durationBudget(requestedDurations, durationSpace);
    var occupied = leadTotal + scheduledDurations.reduce(function sum(total, value) { return total + value; }, 0) + minimumCooldown * Math.max(0, source.length - 1);
    var extraGap = Math.max(0, available - occupied) / Math.max(1, source.length + 1);
    var cursor = startMargin + extraGap;

    var plannedDistance = chapters.length ? chapters[chapters.length - 1].endMetres : plannedRunDistance(state, opts);
    var referenceMetresPerSecond = plannedDistance / Math.max(1, duration);
    return source.map(function schedule(scenario, index) {
      var assignment = assignments[index] || {};
      var chapter = assignment.chapter || chapterAtDistance(chapters, plannedDistance * (index + 1) / (source.length + 1));
      variableLimitForScenario(scenario, runSeed, chapter);
      var warningAt = cursor;
      var triggerAt = warningAt + leads[index];
      var endAt = triggerAt + scheduledDurations[index];
      var cooldownUntil = endAt + (index < source.length - 1 ? minimumCooldown : 0);
      var triggerMetres = chapter ? chapter.startMetres + chapter.lengthMetres * 0.58 : plannedDistance * triggerAt / duration;
      var telegraphMetres = Math.max(chapter ? chapter.startMetres : 0, triggerMetres - referenceMetresPerSecond * leads[index]);
      cursor = cooldownUntil + extraGap;
      scenario.telegraph.leadSeconds = oneDecimal(leads[index]);
      scenario.duration = oneDecimal(scheduledDurations[index]);
      scenario.telegraphAt = oneDecimal(warningAt);
      scenario.triggerAt = oneDecimal(triggerAt);
      scenario.scheduledEndAt = oneDecimal(endAt);
      scenario.cooldownUntil = oneDecimal(cooldownUntil);
      scenario.minimumWarningSeconds = oneDecimal(minimumWarning);
      scenario.minimumCooldownSeconds = oneDecimal(minimumCooldown);
      scenario.chapterId = chapter && chapter.id;
      scenario.chapterVisualId = chapter && chapter.visualId;
      scenario.chapterRole = chapter && chapter.role;
      scenario.chapterCompatibilityScore = oneDecimal(assignment.score || 0);
      scenario.telegraphMetres = oneDecimal(telegraphMetres);
      scenario.triggerMetres = oneDecimal(triggerMetres);
      scenario.scheduledEndMetres = oneDecimal(Math.min(plannedDistance, triggerMetres + referenceMetresPerSecond * scheduledDurations[index]));
      scenario.seed = stableHash(runSeed + ":scenario:" + scenario.id);
      scenario.fairness = Object.assign({
        minimumTtc: 3.4,
        clearEscapeLane: true
      }, scenario.fairness, {
        warningSeconds: scenario.telegraph.leadSeconds,
        cooldownSeconds: scenario.minimumCooldownSeconds,
        noScenarioOverlap: true
      });
      if (scenario.limitState) {
        scenario.limitState = Object.freeze(Object.assign({}, scenario.limitState, {
          telegraphAt: scenario.telegraphAt,
          startsAt: scenario.triggerAt,
          endsAt: scenario.scheduledEndAt,
          telegraphMetres: scenario.telegraphMetres,
          startsMetres: scenario.triggerMetres,
          endsMetres: scenario.scheduledEndMetres,
          chapterId: scenario.chapterId,
          chapterVisualId: scenario.chapterVisualId
        }));
      }
      return Object.freeze(scenario);
    });
  }

  function ensureJourney(state, options) {
    var nextGen = state.nextGenV300 || (state.nextGenV300 = {});
    var existing = nextGen.journeyV320 || nextGen.journeyV310 || nextGen.journey;
    if (existing && existing.version === VERSION) return existing;
    var chapters = buildChapterPlan(state, options);
    var duration = Math.max(40, finiteNumber(options && options.durationSeconds, finiteNumber(state.runDurationSeconds, DEFAULT_RUN_SECONDS)));
    var plannedDistance = chapters.length ? chapters[chapters.length - 1].endMetres : plannedRunDistance(state, options);
    var journey = {
      version: VERSION,
      routeId: normalizeRoute(state.routeId),
      runSeed: (finiteNumber(nextGen.runSeed, finiteNumber(state.trafficSeed, 1)) >>> 0) || 1,
      durationSeconds: duration,
      plannedDistanceMetres: plannedDistance,
      chapters: chapters,
      chapterSource: chapters[0] && chapters[0].visualRole ? "world" : "fallback",
      currentChapterId: chapters[0] && chapters[0].id,
      currentChapter: chapters[0] || null,
      nextChapter: chapters[1] || null,
      rollingChapters: chapterWindow(chapters, 0, CHAPTER_WINDOW_BEHIND, CHAPTER_WINDOW_AHEAD),
      acousticZone: chapters[0] && chapters[0].acousticZone || "open",
      elapsedSeconds: 0,
      worldDistanceMetres: 0,
      trafficEnvelope: null,
      originalScenarioPlan: Array.isArray(nextGen.scenarioPlan) ? nextGen.scenarioPlan.slice() : [],
      scenarioStatus: {},
      mechanicLedger: [],
      transitionLedger: [],
      camera: null
    };
    nextGen.journeyV320 = journey;
    nextGen.journeyV310 = journey;
    nextGen.journey = journey;
    return journey;
  }

  function configureRunV320(target, options) {
    var state = originals.configureRun(target, options);
    var opts = options || {};
    var nextGen = state.nextGenV300;
    /* Weekly Works may replace the menu route and seed inside the original
       configureRun. Build the journey from that resolved state, not stale UI
       options, so every rider receives the identical chapter grammar. */
    var chapterOptions = Object.assign({}, opts, {
      routeId: state.routeId,
      runSeed: nextGen.runSeed
    });
    var chapters = buildChapterPlan(state, chapterOptions);
    var sourcePlan = Array.isArray(nextGen.scenarioPlan) ? nextGen.scenarioPlan : [];
    var plan = scheduleScenarios(state, sourcePlan, opts, chapters);
    var plannedDistance = chapters.length ? chapters[chapters.length - 1].endMetres : plannedRunDistance(state, chapterOptions);
    var initialRolling = chapterWindow(chapters, 0, CHAPTER_WINDOW_BEHIND, CHAPTER_WINDOW_AHEAD);
    var journey = {
      version: VERSION,
      routeId: normalizeRoute(state.routeId),
      runSeed: (finiteNumber(nextGen.runSeed, finiteNumber(state.trafficSeed, 1)) >>> 0) || 1,
      durationSeconds: Math.max(40, finiteNumber(opts.durationSeconds, finiteNumber(state.runDurationSeconds, DEFAULT_RUN_SECONDS))),
      plannedDistanceMetres: plannedDistance,
      chapters: chapters,
      chapterSource: chapters[0] && chapters[0].visualRole ? "world" : "fallback",
      currentChapterId: chapters[0] && chapters[0].id,
      currentChapter: chapters[0] || null,
      nextChapter: chapters[1] || null,
      rollingChapters: initialRolling,
      acousticZone: chapters[0] && chapters[0].acousticZone || "open",
      elapsedSeconds: 0,
      worldDistanceMetres: 0,
      trafficEnvelope: null,
      originalScenarioPlan: sourcePlan.slice(),
      scenarioPlan: plan,
      scenarioStatus: {},
      mechanicLedger: [],
      transitionLedger: [],
      camera: null
    };
    nextGen.journeyV320 = journey;
    nextGen.journeyV310 = journey;
    nextGen.journey = journey;
    nextGen.scenarioPlan = plan;
    if (nextGen.director) {
      nextGen.director.plan = plan;
      nextGen.director.phases = {};
      nextGen.director.telegraph = null;
      nextGen.director.activeScenario = null;
      nextGen.director.completedScenarioIds = [];
      nextGen.director.emittedEvents = [];
    }
    ensureSafetyLedger(nextGen.rating);
    ensureFlowState(state);
    return state;
  }

  function trafficProfileForState(target, worldDistance) {
    var state = target && typeof target === "object" ? target : {};
    var nextGen = state.nextGenV300 || {};
    var journey = nextGen.journeyV320 || nextGen.journeyV310 || nextGen.journey;
    var routeId = normalizeRoute(journey && journey.routeId || state.routeId);
    var distance = Number.isFinite(worldDistance) ? Math.max(0, worldDistance) : Math.max(0, finiteNumber(state.worldDistance, journey && journey.worldDistanceMetres || 0));
    var elapsed = Math.max(0, finiteNumber(state.elapsed, journey && journey.elapsedSeconds || 0));
    var runSeed = (finiteNumber(journey && journey.runSeed, finiteNumber(nextGen.runSeed, finiteNumber(state.trafficSeed, 1))) >>> 0) || 1;
    var chapter = journey ? chapterAtDistance(journey.chapters, distance) : null;
    var chapterKey = chapter && chapter.id || routeId;
    var phaseA = phaseFor(runSeed, chapterKey + ":density");
    var phaseB = phaseFor(runSeed, chapterKey + ":platoon");
    var phaseC = phaseFor(runSeed, chapterKey + ":oncoming");
    var slowWave = Math.sin(elapsed * 0.083 + phaseA) * 0.62 + Math.sin(elapsed * 0.031 + phaseA * 0.47) * 0.38;
    var densityWave = 0.5 + slowWave * 0.5;
    var platoonCarrier = 0.5 + Math.sin(elapsed * 0.117 + phaseB) * 0.5;
    var platoon = smoothstep(0.48, 0.88, platoonCarrier);
    var weather = state.weather || "clear";
    var weatherFactor = weather === "storm" ? 0.78 : weather === "fog" ? 0.84 : weather === "rain" ? 0.93 : weather === "post-rain" ? 0.97 : 1;
    var routeBase = routeId === "motorway" ? 0.96 : routeId === "rural" ? 0.88 : 0.9;
    var chapterBase = finiteNumber(chapter && chapter.density, 0.85);
    var density = clamp(routeBase * chapterBase * weatherFactor * (0.82 + densityWave * 0.28 + platoon * 0.18), 0.42, 1.34);
    var oncomingBase = routeId === "rural" ? Math.max(0.67, finiteNumber(chapter && chapter.oncomingBias, 0.7)) : routeId === "city" ? finiteNumber(chapter && chapter.oncomingBias, 0.4) : 0;
    var oncomingWave = 0.5 + Math.sin(elapsed * 0.071 + phaseC) * 0.5;
    var oncomingBias = routeId === "motorway" ? 0 : clamp(oncomingBase + (routeId === "rural" ? 0.11 : 0.04) * (oncomingWave - 0.5) + platoon * (routeId === "rural" ? 0.05 : 0.02), 0, 0.88);
    var sameDirectionDensity = density * (1 - oncomingBias * (routeId === "rural" ? 0.48 : 0.26));
    var oncomingDensity = density * oncomingBias * (routeId === "rural" ? 1.18 : 0.82);
    return {
      version: VERSION,
      routeId: routeId,
      chapterId: chapterKey,
      chapterVisualId: chapter && chapter.visualId || null,
      chapterRole: chapter && chapter.role || null,
      worldDistance: distance,
      chapterProgress: chapter ? clamp((distance - chapter.startMetres) / Math.max(1, chapter.lengthMetres), 0, 1) : 0,
      elapsedSeconds: elapsed,
      density: density,
      densityWave: densityWave,
      platoonStrength: platoon,
      sameDirectionDensity: sameDirectionDensity,
      oncomingDensity: oncomingDensity,
      oncomingBias: oncomingBias,
      spawnIntervalMultiplier: clamp(1 / Math.max(0.35, density), 0.68, 1.85),
      platoonGapMultiplier: clamp(1 - platoon * 0.36, 0.58, 1),
      drawDistancePressure: clamp(0.86 + density * 0.16, 0.9, 1.08),
      acousticZone: chapter && chapter.acousticZone || "open"
    };
  }

  function mechanicScenarioId(state, feedback) {
    if (feedback.scenarioId) return String(feedback.scenarioId);
    if (feedback.eventId) {
      var eventId = String(feedback.eventId);
      var plan = state.nextGenV300 && state.nextGenV300.scenarioPlan || [];
      for (var index = 0; index < plan.length; index += 1) {
        if (plan[index].eventId === eventId || plan[index].id === eventId) return plan[index].id;
      }
    }
    var active = state.nextGenV300 && state.nextGenV300.director && state.nextGenV300.director.activeScenario;
    return active && active.id || "unassigned";
  }

  function reportDirectorMechanic(target, feedback) {
    var state = target && typeof target === "object" ? target : {};
    var journey = ensureJourney(state);
    var values = Array.isArray(feedback) ? feedback : [feedback];
    var reported = [];
    values.forEach(function report(value) {
      if (!value || typeof value !== "object") return;
      var scenarioId = mechanicScenarioId(state, value);
      var elapsed = Math.max(0, finiteNumber(value.elapsed, finiteNumber(state.elapsed, journey.elapsedSeconds)));
      var status = String(value.status || value.phase || "reported").toLowerCase();
      var previous = journey.scenarioStatus[scenarioId] || {};
      var record = Object.assign({}, previous, value, {
        scenarioId: scenarioId,
        status: status,
        reportedAt: elapsed
      });
      if (status === "spawned" && !Number.isFinite(record.spawnedAt)) record.spawnedAt = elapsed;
      if ((status === "active" || status === "started") && !Number.isFinite(record.startedAt)) record.startedAt = elapsed;
      if (status === "resolved" || status === "complete" || status === "completed") record.resolvedAt = elapsed;
      if (status === "failed" || status === "contact" || status === "cancelled" || status === "expired") record.finishedAt = elapsed;
      journey.scenarioStatus[scenarioId] = record;
      journey.mechanicLedger.push(Object.assign({}, record));
      if (journey.mechanicLedger.length > MECHANIC_LEDGER_LIMIT) journey.mechanicLedger.shift();
      reported.push(record);
      if (value.ratingEvent) recordRunRatingEventV310(state, value.ratingEvent, Object.assign({ elapsed: elapsed, scenarioId: scenarioId }, value.ratingDetail || {}));
      else if (value.safe === true && (status === "resolved" || status === "complete" || status === "completed")) recordRunRatingEventV310(state, "scenario-clear", { elapsed: elapsed, scenarioId: scenarioId });
      else if (status === "contact") recordRunRatingEventV310(state, "hazard-contact", { elapsed: elapsed, scenarioId: scenarioId });
    });
    return Array.isArray(feedback) ? reported : reported[0] || null;
  }

  function reconcileMechanics(journey, baseResult) {
    var active = [];
    var deferred = [];
    var resolved = [];
    var failed = [];
    Object.keys(journey.scenarioStatus).forEach(function classify(scenarioId) {
      var mechanic = journey.scenarioStatus[scenarioId];
      if (mechanic.status === "resolved" || mechanic.status === "complete" || mechanic.status === "completed") resolved.push(scenarioId);
      else if (mechanic.status === "deferred" || mechanic.status === "waiting") deferred.push(mechanic);
      else if (mechanic.status === "failed" || mechanic.status === "cancelled" || mechanic.status === "contact" || mechanic.status === "expired") failed.push(scenarioId);
      else active.push(mechanic);
    });
    var timedCompleted = baseResult.completedScenarioIds || [];
    var terminalIds = resolved.concat(failed);
    var missingTerminal = timedCompleted.filter(function missing(scenarioId) {
      return terminalIds.indexOf(scenarioId) < 0;
    }).map(function missingRecord(scenarioId) {
      return { scenarioId: scenarioId, status: "missing-mechanic-terminal" };
    });
    var unresolvedAfterWindow = active.concat(deferred).filter(function unresolved(mechanic) {
      return timedCompleted.indexOf(mechanic.scenarioId) >= 0;
    }).concat(missingTerminal);
    var settled = Boolean(baseResult.complete && !unresolvedAfterWindow.length);
    var successful = Boolean(settled && !failed.length);
    return {
      active: active,
      deferred: deferred,
      resolvedScenarioIds: resolved,
      failedScenarioIds: failed,
      timedCompletedScenarioIds: timedCompleted.slice(),
      missingTerminal: missingTerminal,
      unresolvedAfterWindow: unresolvedAfterWindow,
      settled: settled,
      successful: successful,
      complete: successful
    };
  }

  function scenarioPhaseAt(scenario, elapsedSeconds) {
    var elapsed = Math.max(0, finiteNumber(elapsedSeconds, 0));
    var trigger = finiteNumber(scenario && scenario.triggerAt, 0);
    var lead = Math.max(0.5, finiteNumber(scenario && scenario.telegraph && scenario.telegraph.leadSeconds, 4));
    var end = finiteNumber(scenario && scenario.scheduledEndAt, trigger + Math.max(0.5, finiteNumber(scenario && scenario.duration, 6)));
    if (elapsed < trigger - lead) return "pending";
    if (elapsed < trigger - 1.25) return "warning";
    if (elapsed < trigger) return "imminent";
    if (elapsed <= end) return "live";
    if (elapsed <= end + 2.4) return "clearing";
    return "complete";
  }

  function scheduledVariableLimitForState(target, scenarioId) {
    var state = target && typeof target === "object" ? target : {};
    var nextGen = state.nextGenV300 || {};
    var journey = nextGen.journeyV320 || nextGen.journeyV310 || nextGen.journey;
    var plan = journey && journey.scenarioPlan || nextGen.scenarioPlan || [];
    var requestedId = scenarioId == null ? "" : String(scenarioId);
    var exact = requestedId && plan.filter(function exactVariable(scenario) {
      return scenario && scenario.kind === "variable-limit" && String(scenario.id) === requestedId;
    })[0];
    if (exact) return exact;
    var elapsed = Math.max(0, finiteNumber(state.elapsed, journey && journey.elapsedSeconds || 0));
    return plan.filter(function variable(scenario) {
      return scenario && scenario.kind === "variable-limit";
    }).sort(function nearest(left, right) {
      return Math.abs(finiteNumber(left.triggerAt, 0) - elapsed) - Math.abs(finiteNumber(right.triggerAt, 0) - elapsed);
    })[0] || null;
  }

  function variableLimitStateForDirector(state, journey, baseResult) {
    var elapsed = Math.max(0, finiteNumber(baseResult && baseResult.elapsedSeconds, finiteNumber(state.elapsed, journey.elapsedSeconds)));
    var plan = journey.scenarioPlan || state.nextGenV300 && state.nextGenV300.scenarioPlan || [];
    var candidates = plan.filter(function variable(scenario) { return scenario.kind === "variable-limit"; }).map(function phase(scenario) {
      return { scenario: scenario, phase: scenarioPhaseAt(scenario, elapsed) };
    }).filter(function relevant(item) { return item.phase === "warning" || item.phase === "imminent" || item.phase === "live" || item.phase === "clearing"; });
    var selected = candidates.sort(function urgency(left, right) {
      var order = { live: 0, imminent: 1, warning: 2, clearing: 3 };
      return order[left.phase] - order[right.phase] || left.scenario.triggerAt - right.scenario.triggerAt;
    })[0];
    var mode = Math.round(clamp(finiteNumber(state.rideMode, 3), 1, 3));
    var normalLimit = MODE_SPEEDS_MPH[mode];
    if (!selected) {
      return Object.freeze({
        version: VERSION,
        kind: "variable-limit",
        phase: "inactive",
        active: false,
        postedLimitMph: null,
        effectiveLimitMph: normalLimit,
        normalLimitMph: normalLimit,
        scenarioId: null,
        chapterId: null,
        gantrySignal: null
      });
    }
    var scenario = selected.scenario;
    var posted = Math.round(clamp(finiteNumber(scenario.postedLimitMph, finiteNumber(scenario.speedLimitMph, 60)), 20, normalLimit));
    var active = selected.phase === "live";
    return Object.freeze(Object.assign({}, scenario.limitState || {}, {
      version: VERSION,
      kind: "variable-limit",
      phase: selected.phase,
      active: active,
      upcoming: selected.phase === "warning" || selected.phase === "imminent",
      clearing: selected.phase === "clearing",
      postedLimitMph: posted,
      effectiveLimitMph: active ? posted : normalLimit,
      normalLimitMph: normalLimit,
      scenarioId: scenario.id,
      chapterId: scenario.chapterId || null,
      chapterVisualId: scenario.chapterVisualId || null,
      gantrySignal: String(posted),
      startsAt: scenario.triggerAt,
      endsAt: scenario.scheduledEndAt,
      startsMetres: scenario.triggerMetres,
      endsMetres: scenario.scheduledEndMetres
    }));
  }

  function stepDirectorV320(target, deltaSeconds, mechanicFeedback) {
    var state = target && typeof target === "object" ? target : {};
    var base = originals.stepDirector(state, deltaSeconds);
    var journey = ensureJourney(state);
    if (mechanicFeedback) reportDirectorMechanic(state, mechanicFeedback);
    var dt = clamp(finiteNumber(deltaSeconds, 1 / 60), 0, 0.25);
    var elapsed = Math.max(0, finiteNumber(base.elapsedSeconds, finiteNumber(state.elapsed, journey.elapsedSeconds)));
    var distance = Math.max(0, finiteNumber(state.worldDistance, journey.worldDistanceMetres));
    var buffer = chapterBufferForState(state, distance);
    var chapter = buffer && buffer.current || chapterAtDistance(journey.chapters, distance);
    var nextChapter = buffer && buffer.next || null;
    var rollingChapters = buffer && buffer.rolling || chapterWindow(journey.chapters, distance, CHAPTER_WINDOW_BEHIND, CHAPTER_WINDOW_AHEAD);
    var previousChapterId = journey.currentChapterId;
    var previousZone = journey.acousticZone;
    journey.elapsedSeconds = elapsed;
    journey.worldDistanceMetres = distance;
    journey.currentChapterId = chapter && chapter.id;
    journey.currentChapter = chapter;
    journey.nextChapter = nextChapter;
    journey.rollingChapters = rollingChapters;
    journey.acousticZone = chapter && chapter.acousticZone || "open";
    var targetProfile = trafficProfileForState(state, distance);
    var previousProfile = journey.trafficEnvelope || targetProfile;
    journey.trafficEnvelope = Object.assign({}, targetProfile, {
      density: damp(previousProfile.density, targetProfile.density, 1.8, dt),
      platoonStrength: damp(previousProfile.platoonStrength, targetProfile.platoonStrength, 1.35, dt),
      sameDirectionDensity: damp(previousProfile.sameDirectionDensity, targetProfile.sameDirectionDensity, 1.8, dt),
      oncomingDensity: damp(previousProfile.oncomingDensity, targetProfile.oncomingDensity, 1.65, dt),
      oncomingBias: damp(previousProfile.oncomingBias, targetProfile.oncomingBias, 1.5, dt),
      spawnIntervalMultiplier: damp(previousProfile.spawnIntervalMultiplier, targetProfile.spawnIntervalMultiplier, 1.8, dt),
      platoonGapMultiplier: damp(previousProfile.platoonGapMultiplier, targetProfile.platoonGapMultiplier, 1.35, dt)
    });
    var journeyEvents = [];
    if (previousChapterId && chapter && previousChapterId !== chapter.id) {
      journeyEvents.push({ type: "chapter-enter", chapter: chapter, elapsed: elapsed, worldDistance: distance });
      journey.transitionLedger.push({ type: "chapter-enter", chapterId: chapter.id, visualId: chapter.visualId, elapsed: elapsed, worldDistance: distance });
    }
    if (previousZone && previousZone !== journey.acousticZone) {
      journeyEvents.push({ type: "acoustic-zone", from: previousZone, to: journey.acousticZone, elapsed: elapsed });
      journey.transitionLedger.push({ type: "acoustic-zone", from: previousZone, to: journey.acousticZone, elapsed: elapsed });
    }
    if (journey.transitionLedger.length > 80) journey.transitionLedger.splice(0, journey.transitionLedger.length - 80);
    var mechanics = reconcileMechanics(journey, base);
    var variableLimitState = variableLimitStateForDirector(state, journey, base);
    var telegraph = base.telegraph;
    if (telegraph && variableLimitState.scenarioId && telegraph.id === variableLimitState.scenarioId) {
      telegraph = Object.assign({}, telegraph, {
        postedLimitMph: variableLimitState.postedLimitMph,
        effectiveLimitMph: variableLimitState.effectiveLimitMph,
        limitPhase: variableLimitState.phase,
        gantrySignal: variableLimitState.gantrySignal,
        speedLimitState: variableLimitState
      });
    }
    state.routeChapter = chapter;
    state.nextRouteChapter = nextChapter;
    state.routeChapterWindow = rollingChapters;
    state.routeChapterBuffer = buffer;
    state.acousticZone = journey.acousticZone;
    state.trafficDensityEnvelope = journey.trafficEnvelope;
    state.variableLimitState = variableLimitState;
    state.activeVariableLimit = variableLimitState.active ? variableLimitState : null;
    state.speedLimitOverrideMph = variableLimitState.active ? variableLimitState.postedLimitMph : null;
    state.effectiveSpeedLimitMph = variableLimitState.effectiveLimitMph;
    state.nextGenV300.variableLimitState = variableLimitState;
    state.nextGenV300.activeVariableLimit = variableLimitState.active ? variableLimitState : null;
    return Object.assign({}, base, {
      complete: mechanics.complete,
      telegraph: telegraph,
      chapter: chapter,
      currentChapter: chapter,
      nextChapter: nextChapter,
      rollingChapters: rollingChapters,
      chapterBuffer: buffer,
      worldDistance: distance,
      acousticZone: journey.acousticZone,
      trafficProfile: Object.assign({}, journey.trafficEnvelope),
      variableLimitState: variableLimitState,
      activeSpeedLimitMph: variableLimitState.active ? variableLimitState.postedLimitMph : null,
      effectiveSpeedLimitMph: variableLimitState.effectiveLimitMph,
      journeyEvents: journeyEvents,
      mechanicFeedback: mechanics
    });
  }

  function emergencyVehicle(vehicle) {
    var kind = String(vehicle && vehicle.kind || "").toLowerCase();
    return Boolean(vehicle && (vehicle.emergency || vehicle.blueLights || vehicle.siren || kind === "emergency" || kind === "emergency-vehicle" || kind === "police" || kind === "ambulance" || kind === "fire-engine"));
  }

  function riderProxyReservation(target, vehicle, options) {
    var state = target && typeof target === "object" ? target : {};
    var traffic = vehicle && typeof vehicle === "object" ? vehicle : {};
    var opts = options || {};
    var emergencyExempt = emergencyVehicle(traffic);
    var direction = traffic.direction === -1 ? -1 : 1;
    var playerSpeed = Math.max(0, finiteNumber(state.speed, finiteNumber(state.speedMph, 0)));
    var vehicleSpeed = Math.max(0, finiteNumber(traffic.speed, finiteNumber(traffic.cruiseSpeed, 0)));
    var distance = finiteNumber(traffic.distance, 999);
    var roadHalfWidth = Math.max(1, finiteNumber(state.roadHalfWidth, 3.8));
    var riderLaneNow = finiteNumber(state.lane, finiteNumber(state.playerLane, 0));
    var riderLaneFuture = finiteNumber(state.targetLane, riderLaneNow);
    var vehicleLaneNow = finiteNumber(traffic.lane, 0);
    var vehicleLaneFuture = finiteNumber(traffic.targetLane, finiteNumber(traffic.manoeuvre && traffic.manoeuvre.targetLane, vehicleLaneNow));
    var lateralNow = Math.abs(vehicleLaneNow - riderLaneNow) * roadHalfWidth;
    var lateralFuture = Math.abs(vehicleLaneFuture - riderLaneFuture) * roadHalfWidth;
    var lanePathsCross = (vehicleLaneNow - riderLaneNow) * (vehicleLaneFuture - riderLaneFuture) <= 0;
    var sameCorridor = Math.min(lateralNow, lateralFuture) < 2.45 || lanePathsCross && Math.max(lateralNow, lateralFuture) < 5.2;
    var weather = state.weather || "clear";
    var headway = weather === "fog" || weather === "storm" ? 2.25 : weather === "rain" ? 1.85 : weather === "post-rain" ? 1.7 : 1.5;
    var horizon = clamp(finiteNumber(opts.horizonSeconds, 0.9 + Math.max(playerSpeed, vehicleSpeed) / 75), 0.9, 2.65);
    var relativeMetresPerSecond = direction === -1 ? -(vehicleSpeed + playerSpeed) * MPH_TO_MPS : (vehicleSpeed - playerSpeed) * MPH_TO_MPS;
    var closestTime = Math.abs(relativeMetresPerSecond) < 0.001 ? 0 : clamp(-distance / relativeMetresPerSecond, 0, horizon);
    var predictedDistance = distance + relativeMetresPerSecond * horizon;
    var closestDistance = distance + relativeMetresPerSecond * closestTime;
    var followingSpeed = distance >= 0 ? playerSpeed : vehicleSpeed;
    var minimumGap = 4.5 + followingSpeed * MPH_TO_MPS * headway;
    var conflict = direction !== -1 && sameCorridor && Math.min(Math.abs(distance), Math.abs(closestDistance), Math.abs(predictedDistance)) < minimumGap && distance > -92 && distance < 105;
    var yieldingSpeed = Math.max(0, playerSpeed - clamp((minimumGap - Math.abs(Math.min(distance, 0))) * 0.32 + 3, 3, 18));
    var reason = emergencyExempt ? "emergency-priority" : direction === -1 ? "opposite-carriageway" : !sameCorridor ? "separate-corridor" : conflict ? distance < 0 ? "yield-behind-rider" : "protect-rider-gap" : "clear";
    return {
      reserved: Boolean(conflict && !emergencyExempt),
      conflict: Boolean(conflict),
      emergencyExempt: emergencyExempt,
      sameCorridor: sameCorridor,
      horizonSeconds: horizon,
      closestTimeSeconds: closestTime,
      currentGapMetres: Math.abs(distance),
      predictedGapMetres: Math.abs(predictedDistance),
      closestGapMetres: Math.abs(closestDistance),
      minimumGapMetres: minimumGap,
      yieldingSpeedMph: yieldingSpeed,
      reason: reason
    };
  }

  function playerAwareSpeedV310(target, vehicle, desiredSpeedMph, deltaSeconds) {
    var reservation = riderProxyReservation(target, vehicle, { deltaSeconds: deltaSeconds });
    /* Emergency traffic keeps priority. A neutral longitudinal clone lets the
       3.0 weather response run without applying its rider-yield branch. */
    var speedVehicle = reservation.emergencyExempt ? Object.assign({}, vehicle || {}, { direction: -1, distance: 999 }) : vehicle;
    var base = originals.playerAwareSpeed(target, speedVehicle, desiredSpeedMph, deltaSeconds);
    if (reservation.emergencyExempt || !reservation.reserved || finiteNumber(vehicle && vehicle.distance, 999) >= 0) {
      return Object.assign({}, base, { reservation: reservation });
    }
    var dt = clamp(finiteNumber(deltaSeconds, 1 / 60), 0.001, 1 / 15);
    var current = Math.max(0, finiteNumber(vehicle && vehicle.speed, base.speedMph));
    var targetSpeed = Math.min(base.desiredSpeedMph, reservation.yieldingSpeedMph);
    var response = damp(current, targetSpeed, reservation.closestTimeSeconds < 0.8 ? 4.2 : 3.1, dt);
    return Object.assign({}, base, {
      speedMph: Math.min(base.speedMph, Math.max(0, response)),
      desiredSpeedMph: Math.max(0, targetSpeed),
      braking: true,
      reaction: "predictive-rider-reservation",
      reservation: reservation
    });
  }

  var SAFETY_CLASS = Object.freeze({
    collision: "critical",
    "hazard-contact": "critical",
    "near-miss": "unsafe",
    "hard-shoulder": "unsafe",
    "roadworks-violation": "unsafe",
    speeding: "unsafe",
    "hard-brake": "caution",
    "scenario-failed": "unsafe",
    "run-incomplete": "incomplete",
    abandoned: "incomplete",
    timeout: "incomplete",
    "did-not-finish": "incomplete",
    "hazard-acknowledged": "safe",
    "early-brake": "safe",
    "clean-pass": "safe",
    "safe-gap": "safe",
    "returned-left": "safe",
    "focus-zone-clear": "safe",
    "smooth-section": "safe",
    "scenario-clear": "safe",
    "run-complete": "complete"
  });

  function ensureSafetyLedger(accumulator) {
    if (!accumulator || typeof accumulator !== "object") return null;
    if (!accumulator.safetyLedger || typeof accumulator.safetyLedger !== "object") {
      accumulator.safetyLedger = {
        version: VERSION,
        events: [],
        counts: {},
        criticalCount: 0,
        unsafeCount: 0,
        cautionCount: 0,
        safeCount: 0,
        lastEvent: null
      };
    }
    if (!accumulator.completionStatus) accumulator.completionStatus = "unknown";
    return accumulator.safetyLedger;
  }

  function appendSafetyEvent(accumulator, type, detail) {
    var classification = SAFETY_CLASS[type];
    if (!classification) return;
    var ledger = ensureSafetyLedger(accumulator);
    var record = {
      sequence: ledger.events.length ? ledger.events[ledger.events.length - 1].sequence + 1 : 1,
      type: type,
      classification: classification,
      elapsed: Math.max(0, finiteNumber(detail && detail.elapsed, finiteNumber(accumulator.elapsedSeconds, 0))),
      scenarioId: detail && detail.scenarioId || null,
      vehicleId: detail && detail.vehicleId || null,
      detail: detail && typeof detail === "object" ? Object.assign({}, detail) : {}
    };
    ledger.events.push(record);
    if (ledger.events.length > SAFETY_LEDGER_LIMIT) ledger.events.shift();
    ledger.counts[type] = (ledger.counts[type] || 0) + 1;
    if (classification === "critical") ledger.criticalCount += 1;
    else if (classification === "unsafe" || classification === "incomplete") ledger.unsafeCount += 1;
    else if (classification === "caution") ledger.cautionCount += 1;
    else if (classification === "safe" || classification === "complete") ledger.safeCount += 1;
    ledger.lastEvent = record;
    if (type === "collision") accumulator.completionStatus = "collision";
    else if (classification === "incomplete" && accumulator.completionStatus !== "collision") accumulator.completionStatus = "incomplete";
    else if (classification === "complete" && accumulator.completionStatus === "unknown") accumulator.completionStatus = "complete";
  }

  function createRatingAccumulatorV310(options) {
    var accumulator = originals.createRatingAccumulator(options);
    ensureSafetyLedger(accumulator);
    return accumulator;
  }

  function recordRatingEventV310(accumulator, type, detail) {
    var acc = accumulator || createRatingAccumulatorV310();
    var wasFinalized = Boolean(acc.finalized);
    originals.recordRatingEvent(acc, type, detail);
    if (!wasFinalized) appendSafetyEvent(acc, String(type || "unknown"), detail);
    return acc;
  }

  function ensureFlowState(target) {
    var state = target && typeof target === "object" ? target : {};
    var nextGen = state.nextGenV300 || (state.nextGenV300 = {});
    if (!nextGen.flowV310) {
      nextGen.flowV310 = {
        version: VERSION,
        value: 1,
        chain: 0,
        bestChain: 0,
        score: 0,
        smoothSeconds: 0,
        lastEventAt: 0,
        previous: null,
        gates: {},
        eventCounts: {},
        ledger: []
      };
    }
    return nextGen.flowV310;
  }

  var FLOW_EFFECTS = Object.freeze({
    "clean-pass": { points: 125, value: 0.11, chain: 1, label: "CLEAN PASS" },
    "safe-gap": { points: 80, value: 0.08, chain: 1, label: "SAFE GAP" },
    "returned-left": { points: 70, value: 0.075, chain: 1, label: "RETURNED LEFT" },
    "smooth-section": { points: 55, value: 0.06, chain: 1, label: "SMOOTH" },
    "early-brake": { points: 45, value: 0.045, chain: 1, label: "EARLY READ" },
    "hazard-acknowledged": { points: 40, value: 0.04, chain: 0, label: "AWARE" },
    "near-miss": { points: 0, valueScale: 0.46, breakChain: true, label: "NEAR MISS" },
    "hazard-contact": { points: 0, valueScale: 0.18, breakChain: true, label: "CONTACT" },
    collision: { points: 0, valueScale: 0, breakChain: true, label: "COLLISION" }
  });

  function recordFlowEvent(target, type, detail) {
    var state = target && typeof target === "object" ? target : {};
    var flow = ensureFlowState(state);
    var eventType = String(type || "unknown");
    var effect = FLOW_EFFECTS[eventType];
    if (!effect) return getFlow(state);
    var elapsed = Math.max(0, finiteNumber(detail && detail.elapsed, finiteNumber(state.elapsed, flow.lastEventAt)));
    if (effect.breakChain) {
      flow.chain = 0;
      flow.value = clamp(flow.value * effect.valueScale, 0, 3);
      flow.smoothSeconds = 0;
    } else {
      flow.chain += effect.chain || 0;
      flow.bestChain = Math.max(flow.bestChain, flow.chain);
      flow.value = clamp(flow.value + finiteNumber(effect.value, 0), 0, 3);
      flow.score += Math.round(finiteNumber(effect.points, 0) * Math.max(1, flow.value) * Math.max(1, 1 + flow.chain * 0.06));
    }
    flow.lastEventAt = elapsed;
    flow.eventCounts[eventType] = (flow.eventCounts[eventType] || 0) + 1;
    flow.ledger.push({
      type: eventType,
      label: effect.label,
      elapsed: elapsed,
      value: flow.value,
      chain: flow.chain,
      score: flow.score,
      detail: detail && typeof detail === "object" ? Object.assign({}, detail) : {}
    });
    if (flow.ledger.length > FLOW_LEDGER_LIMIT) flow.ledger.shift();
    return getFlow(state);
  }

  function stepFlow(target, sample) {
    var state = target && typeof target === "object" ? target : {};
    var value = sample || {};
    var flow = ensureFlowState(state);
    var dt = clamp(finiteNumber(value.deltaSeconds, 1 / 60), 0.001, 0.25);
    var speed = Math.max(0, finiteNumber(value.speedMph, finiteNumber(state.speed, 0)));
    var steer = finiteNumber(value.steerInput, finiteNumber(state.steerInput, 0));
    var brake = clamp(finiteNumber(value.brakePressure, state.brake ? 1 : 0), 0, 1);
    var previous = flow.previous;
    var steerRate = previous ? Math.abs(steer - previous.steer) / dt : 0;
    var brakeRate = previous ? Math.abs(brake - previous.brake) / dt : 0;
    var acceleration = previous ? Math.abs(speed - previous.speed) / dt : 0;
    var smoothness = Number.isFinite(value.smoothness) ? clamp(value.smoothness, 0, 1) : clamp(1 - steerRate / 9 - brakeRate / 14 - Math.max(0, acceleration - 26) / 90, 0, 1);
    if (smoothness >= 0.82 && speed > 12 && !value.evasiveAction) flow.smoothSeconds += dt;
    else flow.smoothSeconds = Math.max(0, flow.smoothSeconds - dt * 1.8);
    if (flow.smoothSeconds >= 3) {
      recordFlowEvent(state, "smooth-section", { elapsed: finiteNumber(value.elapsed, state.elapsed), smoothness: smoothness });
      flow.smoothSeconds = 0.35;
    }
    ["cleanPass", "safeGap", "returnedLeft"].forEach(function gate(name) {
      var active = Boolean(value[name]);
      if (active && !flow.gates[name]) {
        var eventType = name === "cleanPass" ? "clean-pass" : name === "safeGap" ? "safe-gap" : "returned-left";
        recordRunRatingEventV310(state, eventType, { elapsed: finiteNumber(value.elapsed, state.elapsed) });
      }
      flow.gates[name] = active;
    });
    if (finiteNumber(state.elapsed, 0) - flow.lastEventAt > 7) flow.value = damp(flow.value, 1, 0.12, dt);
    flow.previous = { speed: speed, steer: steer, brake: brake };
    return getFlow(state);
  }

  function getFlow(target) {
    var flow = ensureFlowState(target);
    return {
      version: flow.version,
      value: flow.value,
      chain: flow.chain,
      bestChain: flow.bestChain,
      score: flow.score,
      smoothSeconds: flow.smoothSeconds,
      lastEventAt: flow.lastEventAt,
      eventCounts: Object.assign({}, flow.eventCounts),
      lastEvent: flow.ledger.length ? Object.assign({}, flow.ledger[flow.ledger.length - 1]) : null
    };
  }

  function recordRunRatingEventV310(target, type, detail) {
    var state = target && typeof target === "object" ? target : {};
    var before = state.nextGenV300 && state.nextGenV300.rating;
    var wasFinalized = Boolean(before && before.finalized);
    var accumulator = originals.recordRunRatingEvent(state, type, detail);
    ensureSafetyLedger(accumulator);
    if (!wasFinalized) appendSafetyEvent(accumulator, String(type || "unknown"), detail);
    if (FLOW_EFFECTS[String(type || "unknown")]) recordFlowEvent(state, String(type), detail);
    return accumulator;
  }

  function sampleRunRatingV310(target, sample) {
    var accumulator = originals.sampleRunRating(target, sample);
    ensureSafetyLedger(accumulator);
    if (sample && sample.flow !== false) stepFlow(target, sample);
    return accumulator;
  }

  function markRatingOutcome(target, outcome, detail) {
    var state = target && typeof target === "object" ? target : {};
    var accumulator = state.nextGenV300 && state.nextGenV300.rating ? state.nextGenV300.rating : target;
    if (!accumulator || typeof accumulator !== "object") return null;
    ensureSafetyLedger(accumulator);
    var normalized = String(outcome || "unknown").toLowerCase();
    if (normalized === "collision") appendSafetyEvent(accumulator, "collision", detail);
    else if (normalized === "complete" || normalized === "completed" || normalized === "finished") appendSafetyEvent(accumulator, "run-complete", detail);
    else if (normalized === "incomplete" || normalized === "abandoned" || normalized === "timeout") appendSafetyEvent(accumulator, normalized === "incomplete" ? "run-incomplete" : normalized, detail);
    else accumulator.completionStatus = normalized;
    return accumulator;
  }

  function cloneSafetyLedger(ledger) {
    return {
      version: ledger.version,
      events: ledger.events.map(function copy(event) { return Object.assign({}, event, { detail: Object.assign({}, event.detail) }); }),
      counts: Object.assign({}, ledger.counts),
      criticalCount: ledger.criticalCount,
      unsafeCount: ledger.unsafeCount,
      cautionCount: ledger.cautionCount,
      safeCount: ledger.safeCount,
      lastEvent: ledger.lastEvent ? Object.assign({}, ledger.lastEvent, { detail: Object.assign({}, ledger.lastEvent.detail) }) : null
    };
  }

  function applyOutcomeCaps(accumulator, sourceResult) {
    var ledger = ensureSafetyLedger(accumulator);
    var result = Object.assign({}, sourceResult, {
      dimensions: Object.assign({}, sourceResult.dimensions),
      eventCounts: Object.assign({}, sourceResult.eventCounts),
      safetyLedger: cloneSafetyLedger(ledger),
      completionStatus: accumulator.completionStatus
    });
    var collisionCount = Math.max(finiteNumber(result.eventCounts.collision, 0), finiteNumber(ledger.counts.collision, 0));
    var collision = collisionCount > 0 || accumulator.completionStatus === "collision";
    var incomplete = !collision && accumulator.completionStatus === "incomplete";
    if (collision) {
      result.overall = Math.min(49, result.overall);
      result.grade = "D";
      result.title = "RIDE NOT COMPLETED SAFELY";
      result.outcome = "collision";
      result.coaching = ["Finish the ride safely first: respond early, create space and keep a clear escape route."];
      result.outcomeCap = 49;
    } else if (incomplete) {
      result.overall = Math.min(57, result.overall);
      result.grade = "D";
      result.title = "RUN INCOMPLETE";
      result.outcome = "incomplete";
      result.coaching = ["Complete the route before chasing pace or a longer Flow chain."];
      result.outcomeCap = 57;
    } else {
      result.outcome = accumulator.completionStatus === "complete" ? "complete" : "unconfirmed";
      result.outcomeCap = 100;
    }
    result.safetySummary = {
      collisions: collisionCount,
      critical: ledger.criticalCount,
      unsafe: ledger.unsafeCount,
      caution: ledger.cautionCount,
      safe: ledger.safeCount
    };
    accumulator.result = result;
    accumulator.finalized = true;
    return result;
  }

  function finalizeRatingV310(accumulator) {
    var acc = accumulator || createRatingAccumulatorV310();
    ensureSafetyLedger(acc);
    return applyOutcomeCaps(acc, originals.finalizeRating(acc));
  }

  function getRatingV310(target, shouldFinalize) {
    var state = target && typeof target === "object" ? target : {};
    var result = originals.getRating(state, shouldFinalize);
    if (shouldFinalize === false) {
      ensureSafetyLedger(result);
      return result;
    }
    var accumulator = state.nextGenV300 && state.nextGenV300.rating;
    return applyOutcomeCaps(accumulator, result);
  }

  function setStyleProperty(element, name, value) {
    if (element && element.style && typeof element.style.setProperty === "function") element.style.setProperty(name, value);
  }

  function reducedMotionFor(state, frame) {
    if (frame.reducedMotion != null) return Boolean(frame.reducedMotion);
    if (state.reducedMotion != null) return Boolean(state.reducedMotion);
    if (root.matchMedia) {
      try { return root.matchMedia("(prefers-reduced-motion: reduce)").matches; }
      catch (error) { return false; }
    }
    return false;
  }

  function afterAnimationFrame(target, frame) {
    var state = target && typeof target === "object" ? target : {};
    var value = frame || {};
    var journey = ensureJourney(state);
    var timestamp = finiteNumber(value.timestamp, finiteNumber(value.now, NaN));
    var previousTimestamp = journey.camera && journey.camera.timestamp;
    var derivedDelta = Number.isFinite(timestamp) && Number.isFinite(previousTimestamp) ? (timestamp - previousTimestamp) / 1000 : NaN;
    var dt = clamp(finiteNumber(value.deltaSeconds, derivedDelta), 1 / 240, 0.1);
    var springs = state.nextGenV300 && state.nextGenV300.springs;
    var metrics = value.springMetrics || springs && springs.metrics || {};
    var dynamics = state.nextGenV300 && state.nextGenV300.dynamics || {};
    var reducedMotion = reducedMotionFor(state, value);
    var profile = String(state.handlingProfile || "arcade").toLowerCase();
    var profileScale = profile === "road" ? 1.04 : 0.88;
    var motionScale = reducedMotion ? 0.12 : 1;
    var lean = clamp(finiteNumber(value.lean, finiteNumber(dynamics.leanTarget, finiteNumber(state.steerInput, 0))), -1, 1);
    var lateral = finiteNumber(value.lateralVelocity, finiteNumber(state.lateralVelocity, 0));
    var speedNormal = clamp(finiteNumber(state.speed, finiteNumber(state.speedMph, 0)) / 132, 0, 1);
    var targets = {
      x: (finiteNumber(metrics.cockpitX, 0) + lean * 8.5 + lateral * 1.25) * profileScale * motionScale,
      y: finiteNumber(metrics.cockpitY, 0) * profileScale * motionScale,
      roll: (finiteNumber(metrics.cockpitRotationDegrees, 0) - lean * 1.75) * profileScale * motionScale,
      forkDive: finiteNumber(metrics.forkDive, 0) * motionScale,
      squat: finiteNumber(metrics.accelerationSquat, 0) * motionScale,
      vibration: reducedMotion ? 0 : (finiteNumber(metrics.roadResponse, 0) * 0.65 + finiteNumber(metrics.buffet, 0) * 0.35) * speedNormal
    };
    var camera = journey.camera || {
      x: targets.x,
      y: targets.y,
      roll: targets.roll,
      forkDive: targets.forkDive,
      squat: targets.squat,
      vibration: targets.vibration,
      timestamp: timestamp
    };
    camera.x = damp(camera.x, targets.x, reducedMotion ? 18 : 11, dt);
    camera.y = damp(camera.y, targets.y, reducedMotion ? 18 : 10, dt);
    camera.roll = damp(camera.roll, targets.roll, reducedMotion ? 18 : 12, dt);
    camera.forkDive = damp(camera.forkDive, targets.forkDive, 9, dt);
    camera.squat = damp(camera.squat, targets.squat, 7, dt);
    camera.vibration = damp(camera.vibration, targets.vibration, reducedMotion ? 24 : 15, dt);
    camera.timestamp = timestamp;
    camera.reducedMotion = reducedMotion;
    journey.camera = camera;
    var cockpit = value.cockpitElement || state.cockpitElement || journey.cockpitElement || null;
    if (cockpit && cockpit.isConnected === false) cockpit = null;
    if (!cockpit && root.document && typeof root.document.querySelector === "function") {
      cockpit = root.document.querySelector("[data-evo-cockpit], .evo-cockpit, .cockpit-shell, .cockpit");
      journey.cockpitElement = cockpit || null;
    }
    var transform = "translate3d(" + camera.x.toFixed(3) + "px," + camera.y.toFixed(3) + "px,0) rotate(" + camera.roll.toFixed(4) + "deg)";
    if (cockpit && cockpit.style && value.applyTransform !== false) {
      var translate = camera.x.toFixed(2) + "px " + camera.y.toFixed(2) + "px";
      var rotate = camera.roll.toFixed(3) + "deg";
      var lastStyle = journey.cockpitStyle || (journey.cockpitStyle = {});
      if (lastStyle.translate !== translate) cockpit.style.translate = lastStyle.translate = translate;
      if (lastStyle.rotate !== rotate) cockpit.style.rotate = lastStyle.rotate = rotate;
    }
    return Object.assign({}, camera, {
      transform: transform,
      stableEyeline: { x: 0, y: 0, roll: 0 },
      profile: profile
    });
  }

  var journeyApi = {
    version: VERSION,
    originals: originals,
    buildChapterPlan: buildChapterPlan,
    plannedRunDistance: plannedRunDistance,
    chapterAtDistance: chapterAtDistance,
    chapterForState: chapterForState,
    chapterBufferForState: chapterBufferForState,
    getChapterWindow: chapterBufferForState,
    getRollingChapterBuffer: chapterBufferForState,
    trafficProfileForState: trafficProfileForState,
    getScheduledVariableLimit: scheduledVariableLimitForState,
    getVariableLimitState: function getVariableLimitState(target) {
      var state = target && typeof target === "object" ? target : {};
      var journey = ensureJourney(state);
      return variableLimitStateForDirector(state, journey, {
        elapsedSeconds: finiteNumber(state.elapsed, journey.elapsedSeconds)
      });
    },
    reportDirectorMechanic: reportDirectorMechanic,
    riderProxyReservation: riderProxyReservation,
    afterAnimationFrame: afterAnimationFrame,
    recordFlowEvent: recordFlowEvent,
    stepFlow: stepFlow,
    getFlow: getFlow,
    markRatingOutcome: markRatingOutcome
  };

  namespace.configureRun = configureRunV320;
  namespace.stepDirector = stepDirectorV320;
  namespace.playerAwareSpeed = playerAwareSpeedV310;
  namespace.createRatingAccumulator = createRatingAccumulatorV310;
  namespace.sampleRunRating = sampleRunRatingV310;
  namespace.recordRatingEvent = recordRatingEventV310;
  namespace.recordRunRatingEvent = recordRunRatingEventV310;
  namespace.finalizeRating = finalizeRatingV310;
  namespace.getRating = getRatingV310;

  namespace.dynamics = Object.assign(namespace.dynamics || {}, {
    configureRun: configureRunV320,
    playerAwareSpeed: playerAwareSpeedV310,
    riderProxyReservation: riderProxyReservation,
    afterAnimationFrame: afterAnimationFrame
  });
  namespace.routeDirector = Object.assign(namespace.routeDirector || {}, {
    configureRun: configureRunV320,
    stepDirector: stepDirectorV320,
    chapterForState: chapterForState,
    chapterBufferForState: chapterBufferForState,
    getChapterWindow: chapterBufferForState,
    getRollingChapterBuffer: chapterBufferForState,
    trafficProfileForState: trafficProfileForState,
    getScheduledVariableLimit: scheduledVariableLimitForState,
    getVariableLimitState: journeyApi.getVariableLimitState,
    reportDirectorMechanic: reportDirectorMechanic
  });
  namespace.rating = Object.assign(namespace.rating || {}, {
    createRatingAccumulator: createRatingAccumulatorV310,
    sampleRunRating: sampleRunRatingV310,
    recordRatingEvent: recordRatingEventV310,
    recordRunRatingEvent: recordRunRatingEventV310,
    finalizeRating: finalizeRatingV310,
    getRating: getRatingV310,
    markRatingOutcome: markRatingOutcome
  });
  namespace.flow = Object.assign(namespace.flow || {}, {
    recordFlowEvent: recordFlowEvent,
    stepFlow: stepFlow,
    getFlow: getFlow
  });
  Object.assign(namespace, journeyApi);
  namespace.journeyGameplay = Object.assign(namespace.journeyGameplay || {}, journeyApi);
  namespace.journeyVersion = VERSION;
  namespace.__journeyGameplayV320Installed = true;
  /* Superset compatibility: integrations that only test for the earlier
     helper generation may continue without reinstalling 3.1 over 3.2. */
  namespace.__journeyGameplayV310Installed = true;
})(typeof window !== "undefined" ? window : globalThis);
