/*
 * Avenra Hyperlane photographic 2.5D traffic renderer v3.3.8
 *
 * This is deliberately a billboard renderer, not a projected-mesh renderer.
 * Every painted vehicle is one complete, pre-cut photographic sprite selected
 * from an eight-direction turntable:
 *
 *   000 rear, 045 rear-right, 090 right, 135 front-right,
 *   180 front, 225 front-left, 270 left, 315 rear-left.
 *
 * The body is always drawn with one axis-aligned drawImage call and one uniform
 * scale. There is no affine warping, mesh, top plate, crossfade or procedural
 * body fallback in this file. Small calibrated effects are painted around the
 * intact photograph only: wheel motion, lamps, spray, reflections and hazards.
 * The legacy *3D API names remain because the compiled game bundle calls them.
 */
(function attachPhotographicTrafficSprites(globalScope) {
  'use strict';

  if (!globalScope) return;

  var VERSION = '3.3.8';
  var FRAME_ANGLES = Object.freeze([0, 45, 90, 135, 180, 225, 270, 315]);
  var CORE_FRAME_INDICES = Object.freeze([0, 4]);
  var DETAIL_FRAME_INDICES = Object.freeze([1, 2, 3, 5, 6, 7]);
  var SPRITE_ROOT = 'environment/traffic-sprites-v305/';
  // One degree on either side of a nominal boundary gives a two-degree total
  // Schmitt band without a visible crossfade.
  var HYSTERESIS_HALF_WIDTH_DEGREES = 1;
  var FRAME_CACHE = Object.create(null);
  var ID_VIEW_STATE = typeof Map === 'function' ? new Map() : null;
  var OBJECT_VIEW_STATE = typeof WeakMap === 'function' ? new WeakMap() : null;
  var ROUTE_PREFETCH_STATE = Object.create(null);
  var viewUseSerial = 0;

  var ROUTE_PREFETCH_KINDS = Object.freeze({
    city: Object.freeze(['saloon', 'suv', 'van', 'lorry']),
    rural: Object.freeze(['saloon', 'suv', 'van', 'motorhome', 'lorry']),
    motorway: Object.freeze(['saloon', 'suv', 'van', 'motorhome', 'lorry'])
  });

  var VEHICLE_DEFS = Object.freeze({
    saloon: Object.freeze({ width: 1.88, height: 1.52, length: 4.55, renderLength: 4.546 }),
    suv: Object.freeze({ width: 2.04, height: 1.82, length: 4.82, renderLength: 4.758 }),
    van: Object.freeze({ width: 2.28, height: 2.42, length: 5.45, renderLength: 5.894 }),
    motorhome: Object.freeze({ width: 2.38, height: 3.05, length: 6.85, renderLength: 7.425 }),
    // Collision spacing remains 10.40 m in gameplay; the photographic rigid
    // vehicle is rendered at its calibrated 8.018 m visual length.
    lorry: Object.freeze({ width: 2.48, height: 3.35, length: 10.40, renderLength: 8.018 })
  });

  function freezeFleetProfile(id, spriteKind, width, height, length, renderLength, options) {
    var settings = options || {};
    return Object.freeze({
      id: id,
      spriteKind: spriteKind,
      width: width,
      height: height,
      length: length,
      renderLength: renderLength,
      aliased: id !== spriteKind,
      sourceFidelity: id === spriteKind ? 'photographic-source' : 'photographic-family-alias',
      paintFamily: settings.paintFamily || 'mixed-uk-fleet',
      marking: settings.marking || null,
      label: settings.label || id
    });
  }

  /*
   * Five families have genuine eight-angle photographic source art. The
   * additional UK fleet entries intentionally reuse the closest intact source
   * family until dedicated turntables are supplied. They still have honest
   * physical dimensions and optional vehicle-specific markings; no source
   * photograph is stretched or pieced into a fake body.
   */
  var FLEET_PROFILES = Object.freeze({
    saloon: freezeFleetProfile('saloon', 'saloon', 1.88, 1.52, 4.55, 4.546, { label: 'Saloon' }),
    suv: freezeFleetProfile('suv', 'suv', 2.04, 1.82, 4.82, 4.758, { label: 'SUV' }),
    van: freezeFleetProfile('van', 'van', 2.28, 2.42, 5.45, 5.894, { label: 'Panel van' }),
    motorhome: freezeFleetProfile('motorhome', 'motorhome', 2.38, 3.05, 6.85, 7.425, { label: 'Motorhome' }),
    lorry: freezeFleetProfile('lorry', 'lorry', 2.48, 3.35, 10.40, 8.018, { label: 'Rigid HGV' }),
    hatchback: freezeFleetProfile('hatchback', 'saloon', 1.79, 1.47, 4.18, 4.18, { label: 'Hatchback', paintFamily: 'uk-hatchback' }),
    estate: freezeFleetProfile('estate', 'saloon', 1.88, 1.51, 4.78, 4.78, { label: 'Estate car', paintFamily: 'uk-estate' }),
    taxi: freezeFleetProfile('taxi', 'saloon', 1.91, 1.58, 4.74, 4.74, { label: 'Taxi', paintFamily: 'uk-taxi', marking: 'taxi' }),
    bus: freezeFleetProfile('bus', 'motorhome', 2.50, 3.18, 10.60, 10.60, { label: 'Local bus', paintFamily: 'uk-local-bus', marking: 'bus' }),
    coach: freezeFleetProfile('coach', 'motorhome', 2.55, 3.45, 12.20, 12.20, { label: 'Coach', paintFamily: 'uk-coach', marking: 'coach' }),
    caravan: freezeFleetProfile('caravan', 'motorhome', 2.30, 2.58, 6.75, 6.75, { label: 'Touring caravan', paintFamily: 'uk-caravan', marking: 'caravan' }),
    horsebox: freezeFleetProfile('horsebox', 'motorhome', 2.45, 3.30, 7.55, 7.55, { label: 'Horsebox', paintFamily: 'uk-horsebox', marking: 'horsebox' }),
    artic: freezeFleetProfile('artic', 'lorry', 2.55, 4.00, 16.50, 16.50, { label: 'Articulated HGV', paintFamily: 'uk-artic', marking: 'artic' }),
    'delivery-van': freezeFleetProfile('delivery-van', 'van', 2.15, 2.45, 5.62, 5.62, { label: 'Delivery van', paintFamily: 'uk-delivery', marking: 'delivery' })
  });

  var FLEET_ALIASES = Object.freeze({
    car: 'saloon',
    hatch: 'hatchback',
    'hatch-back': 'hatchback',
    wagon: 'estate',
    'estate-car': 'estate',
    cab: 'taxi',
    'black-cab': 'taxi',
    minibus: 'bus',
    'local-bus': 'bus',
    tourer: 'caravan',
    trailer: 'caravan',
    'horse-box': 'horsebox',
    hgv: 'artic',
    truck: 'artic',
    semi: 'artic',
    'semi-trailer': 'artic',
    'delivery-van': 'delivery-van',
    deliveryvan: 'delivery-van',
    'delivery van': 'delivery-van',
    courier: 'delivery-van',
    'courier-van': 'delivery-van',
    camper: 'motorhome',
    rv: 'motorhome'
  });

  /*
   * Each row is [sourceX, sourceY, contentWidth, contentHeight, imageWidth,
   * imageHeight]. The transparent safety border is intentionally excluded from
   * the body draw. Small source frames were deterministically resampled during
   * the asset build, so their original 32 px border grows proportionally; these
   * explicit final measurements preserve exact aspect and tyre contact.
   *
   * All forty measurements come from the final keyed turntable build.
   */
  var FRAME_GEOMETRY = Object.freeze({
    saloon: Object.freeze([
      Object.freeze([45, 45, 365, 289, 455, 379]),
      Object.freeze([49, 49, 618, 289, 716, 387]),
      Object.freeze([58, 58, 819, 289, 935, 405]),
      Object.freeze([55, 55, 646, 289, 756, 399]),
      Object.freeze([47, 47, 391, 288, 485, 382]),
      Object.freeze([55, 55, 607, 289, 717, 399]),
      Object.freeze([57, 57, 818, 288, 932, 402]),
      Object.freeze([50, 50, 517, 289, 617, 389])
    ]),
    suv: Object.freeze([
      Object.freeze([45, 45, 319, 289, 409, 379]),
      Object.freeze([45, 45, 541, 289, 631, 379]),
      Object.freeze([48, 48, 695, 288, 791, 384]),
      Object.freeze([42, 42, 536, 289, 620, 373]),
      Object.freeze([41, 41, 370, 289, 452, 371]),
      Object.freeze([46, 46, 541, 289, 633, 381]),
      Object.freeze([47, 47, 721, 289, 815, 383]),
      Object.freeze([44, 44, 506, 289, 594, 377])
    ]),
    van: Object.freeze([
      Object.freeze([34, 34, 236, 290, 304, 358]),
      Object.freeze([33, 33, 418, 288, 484, 354]),
      Object.freeze([36, 36, 682, 290, 754, 362]),
      Object.freeze([33, 33, 429, 291, 495, 357]),
      Object.freeze([32, 32, 279, 293, 343, 357]),
      Object.freeze([34, 34, 454, 290, 522, 358]),
      Object.freeze([36, 36, 683, 288, 755, 360]),
      Object.freeze([33, 33, 410, 288, 476, 354])
    ]),
    motorhome: Object.freeze([
      Object.freeze([36, 36, 236, 290, 308, 362]),
      Object.freeze([39, 39, 437, 289, 515, 367]),
      Object.freeze([39, 39, 699, 290, 777, 368]),
      Object.freeze([36, 36, 449, 289, 521, 361]),
      Object.freeze([36, 36, 272, 289, 344, 361]),
      Object.freeze([38, 38, 460, 289, 536, 365]),
      Object.freeze([38, 38, 671, 289, 747, 365]),
      Object.freeze([36, 36, 445, 289, 517, 361])
    ]),
    lorry: Object.freeze([
      Object.freeze([32, 32, 222, 371, 286, 435]),
      Object.freeze([32, 32, 507, 363, 571, 427]),
      Object.freeze([32, 32, 826, 344, 890, 408]),
      Object.freeze([32, 32, 528, 374, 592, 438]),
      Object.freeze([32, 32, 307, 373, 371, 437]),
      Object.freeze([32, 32, 533, 350, 597, 414]),
      Object.freeze([32, 32, 790, 339, 854, 403]),
      Object.freeze([32, 32, 536, 374, 600, 438])
    ])
  });

  function freezePoint(x, y) {
    return Object.freeze([x, y]);
  }

  function freezeLampLayout(face, first, second) {
    var anchors = face === 'none' ? Object.freeze([]) : Object.freeze([
      freezePoint(first[0], first[1]),
      freezePoint(second[0], second[1])
    ]);
    return Object.freeze({
      face: face,
      anchors: anchors,
      left: anchors.length ? anchors[0] : null,
      right: anchors.length ? anchors[1] : null,
      rearAnchor: null,
      frontAnchor: null
    });
  }

  function freezeSideLampLayout(rear, front) {
    var rearAnchor = freezePoint(rear[0], rear[1]);
    var frontAnchor = freezePoint(front[0], front[1]);
    return Object.freeze({
      face: 'side',
      anchors: Object.freeze([rearAnchor, frontAnchor]),
      left: rearAnchor,
      right: frontAnchor,
      rearAnchor: rearAnchor,
      frontAnchor: frontAnchor
    });
  }

  function makeLightLayouts(rearLeft, rearRight, rearY, frontLeft, frontRight, frontY) {
    return Object.freeze([
      freezeLampLayout('rear', [rearLeft, rearY], [rearRight, rearY]),
      // At 45° the photographed rear is on the left of the billboard; at
      // 315° it is on the right. Keeping these asymmetric prevents the old
      // floating-lamp illusion on close passes.
      freezeLampLayout('rear', [0.09, rearY], [0.27, rearY]),
      freezeSideLampLayout([0.035, rearY], [0.965, frontY]),
      freezeLampLayout('front', [0.62, frontY], [0.85, frontY]),
      freezeLampLayout('front', [frontLeft, frontY], [frontRight, frontY]),
      freezeLampLayout('front', [0.15, frontY], [0.38, frontY]),
      freezeSideLampLayout([0.965, rearY], [0.035, frontY]),
      freezeLampLayout('rear', [0.73, rearY], [0.91, rearY])
    ]);
  }

  // Normalised against each cropped photographic content rectangle. Quarter
  // lamps sit on the visible end of the complete sprite rather than floating
  // at the symmetric extremes of its long side silhouette.
  var LIGHT_LAYOUTS = Object.freeze({
    saloon: makeLightLayouts(0.136, 0.862, 0.386, 0.14, 0.86, 0.46),
    suv: makeLightLayouts(0.115, 0.865, 0.386, 0.12, 0.88, 0.43),
    van: makeLightLayouts(0.038, 0.959, 0.579, 0.17, 0.83, 0.53),
    motorhome: makeLightLayouts(0.056, 0.947, 0.659, 0.20, 0.80, 0.61),
    lorry: makeLightLayouts(0.154, 0.847, 0.679, 0.16, 0.84, 0.76)
  });

  function freezePlate(face, x, y, width) {
    return face === 'none' ? null : Object.freeze({ face: face, x: x, y: y, width: width });
  }

  function makePlateLayouts(rearY, frontY, quarterRearY, quarterFrontY) {
    return Object.freeze([
      freezePlate('rear', 0.50, rearY, 0.265),
      freezePlate('rear', 0.84, quarterRearY, 0.135),
      null,
      freezePlate('front', 0.84, quarterFrontY, 0.135),
      freezePlate('front', 0.50, frontY, 0.265),
      freezePlate('front', 0.16, quarterFrontY, 0.135),
      null,
      freezePlate('rear', 0.16, quarterRearY, 0.135)
    ]);
  }

  // The source vehicles deliberately contain empty registration recesses. UK
  // plates are painted into those recesses after the intact photographic body:
  // white facing forward, reflective yellow facing rearward.
  var PLATE_LAYOUTS = Object.freeze({
    saloon: makePlateLayouts(0.688, 0.735, 0.680, 0.748),
    suv: makePlateLayouts(0.695, 0.730, 0.688, 0.742),
    van: makePlateLayouts(0.735, 0.775, 0.730, 0.782),
    motorhome: makePlateLayouts(0.795, 0.855, 0.790, 0.852),
    lorry: makePlateLayouts(0.855, 0.865, 0.842, 0.855)
  });

  function freezeWheel(x, y, radius, phase) {
    return Object.freeze({ x: x, y: y, radius: radius, phase: phase || 0 });
  }

  function freezeWheelSet() {
    return Object.freeze(Array.prototype.slice.call(arguments));
  }

  var NO_WHEELS = Object.freeze([]);

  // Normalised tyre centres measured against the final cropped turntable
  // content. End-on photographs deliberately expose no synthetic wheel layer.
  var WHEEL_LAYOUTS = Object.freeze({
    saloon: Object.freeze([
      NO_WHEELS,
      freezeWheelSet(freezeWheel(0.20, 0.825, 0.072, 0.2), freezeWheel(0.77, 0.835, 0.080, 1.1)),
      freezeWheelSet(freezeWheel(0.21, 0.835, 0.091, 0.4), freezeWheel(0.80, 0.835, 0.093, 1.4)),
      freezeWheelSet(freezeWheel(0.24, 0.835, 0.078, 0.8), freezeWheel(0.74, 0.825, 0.086, 1.7)),
      NO_WHEELS,
      freezeWheelSet(freezeWheel(0.26, 0.825, 0.086, 0.6), freezeWheel(0.76, 0.835, 0.078, 1.5)),
      freezeWheelSet(freezeWheel(0.20, 0.835, 0.093, 0.3), freezeWheel(0.79, 0.835, 0.091, 1.3)),
      freezeWheelSet(freezeWheel(0.23, 0.835, 0.080, 0.5), freezeWheel(0.80, 0.825, 0.072, 1.4))
    ]),
    suv: Object.freeze([
      NO_WHEELS,
      freezeWheelSet(freezeWheel(0.19, 0.835, 0.078, 0.2), freezeWheel(0.77, 0.845, 0.086, 1.1)),
      freezeWheelSet(freezeWheel(0.21, 0.845, 0.098, 0.4), freezeWheel(0.81, 0.845, 0.101, 1.4)),
      freezeWheelSet(freezeWheel(0.23, 0.845, 0.084, 0.8), freezeWheel(0.74, 0.835, 0.091, 1.7)),
      NO_WHEELS,
      freezeWheelSet(freezeWheel(0.26, 0.835, 0.091, 0.6), freezeWheel(0.77, 0.845, 0.084, 1.5)),
      freezeWheelSet(freezeWheel(0.19, 0.845, 0.101, 0.3), freezeWheel(0.79, 0.845, 0.098, 1.3)),
      freezeWheelSet(freezeWheel(0.23, 0.845, 0.086, 0.5), freezeWheel(0.81, 0.835, 0.078, 1.4))
    ]),
    van: Object.freeze([
      NO_WHEELS,
      freezeWheelSet(freezeWheel(0.17, 0.855, 0.082, 0.2), freezeWheel(0.82, 0.865, 0.088, 1.1)),
      freezeWheelSet(freezeWheel(0.22, 0.865, 0.102, 0.4), freezeWheel(0.82, 0.865, 0.104, 1.4)),
      freezeWheelSet(freezeWheel(0.18, 0.865, 0.088, 0.8), freezeWheel(0.70, 0.855, 0.096, 1.7)),
      NO_WHEELS,
      freezeWheelSet(freezeWheel(0.30, 0.855, 0.096, 0.6), freezeWheel(0.82, 0.865, 0.088, 1.5)),
      freezeWheelSet(freezeWheel(0.18, 0.865, 0.104, 0.3), freezeWheel(0.76, 0.865, 0.102, 1.3)),
      freezeWheelSet(freezeWheel(0.18, 0.865, 0.088, 0.5), freezeWheel(0.82, 0.855, 0.082, 1.4))
    ]),
    motorhome: Object.freeze([
      NO_WHEELS,
      freezeWheelSet(freezeWheel(0.18, 0.865, 0.082, 0.2), freezeWheel(0.81, 0.875, 0.090, 1.1)),
      freezeWheelSet(freezeWheel(0.21, 0.875, 0.102, 0.4), freezeWheel(0.81, 0.875, 0.105, 1.4)),
      freezeWheelSet(freezeWheel(0.19, 0.875, 0.090, 0.8), freezeWheel(0.70, 0.865, 0.098, 1.7)),
      NO_WHEELS,
      freezeWheelSet(freezeWheel(0.30, 0.865, 0.098, 0.6), freezeWheel(0.81, 0.875, 0.090, 1.5)),
      freezeWheelSet(freezeWheel(0.19, 0.875, 0.105, 0.3), freezeWheel(0.79, 0.875, 0.102, 1.3)),
      freezeWheelSet(freezeWheel(0.19, 0.875, 0.090, 0.5), freezeWheel(0.82, 0.865, 0.082, 1.4))
    ]),
    lorry: Object.freeze([
      NO_WHEELS,
      freezeWheelSet(freezeWheel(0.16, 0.875, 0.072, 0.2), freezeWheel(0.67, 0.890, 0.080, 1.0), freezeWheel(0.80, 0.890, 0.080, 1.8)),
      freezeWheelSet(freezeWheel(0.16, 0.890, 0.088, 0.3), freezeWheel(0.66, 0.900, 0.090, 1.0), freezeWheel(0.78, 0.900, 0.090, 1.7), freezeWheel(0.89, 0.900, 0.088, 2.4)),
      freezeWheelSet(freezeWheel(0.18, 0.890, 0.078, 0.7), freezeWheel(0.68, 0.895, 0.084, 1.5), freezeWheel(0.81, 0.895, 0.082, 2.2)),
      NO_WHEELS,
      freezeWheelSet(freezeWheel(0.19, 0.895, 0.082, 0.6), freezeWheel(0.32, 0.895, 0.084, 1.3), freezeWheel(0.82, 0.890, 0.078, 2.1)),
      freezeWheelSet(freezeWheel(0.11, 0.900, 0.088, 0.3), freezeWheel(0.22, 0.900, 0.090, 1.0), freezeWheel(0.34, 0.900, 0.090, 1.7), freezeWheel(0.84, 0.890, 0.088, 2.4)),
      freezeWheelSet(freezeWheel(0.19, 0.890, 0.080, 0.5), freezeWheel(0.32, 0.890, 0.080, 1.3), freezeWheel(0.84, 0.875, 0.072, 2.1))
    ])
  });

  function freezeDoor(hingeX, outerX, topY, bottomY) {
    return Object.freeze({ hingeX: hingeX, outerX: outerX, topY: topY, bottomY: bottomY });
  }

  var NO_DOOR = null;
  var DOOR_LAYOUTS = Object.freeze({
    van: Object.freeze([
      freezeDoor(0.83, 1.08, 0.35, 0.84),
      freezeDoor(0.23, -0.04, 0.35, 0.83),
      freezeDoor(0.79, 1.04, 0.34, 0.84),
      freezeDoor(0.67, 0.94, 0.34, 0.83),
      NO_DOOR,
      freezeDoor(0.33, 0.06, 0.34, 0.83),
      freezeDoor(0.21, -0.04, 0.34, 0.84),
      freezeDoor(0.77, 1.04, 0.35, 0.83)
    ]),
    motorhome: Object.freeze([
      freezeDoor(0.82, 1.07, 0.30, 0.85),
      freezeDoor(0.24, -0.03, 0.30, 0.85),
      freezeDoor(0.78, 1.03, 0.29, 0.86),
      freezeDoor(0.67, 0.93, 0.29, 0.85),
      NO_DOOR,
      freezeDoor(0.33, 0.07, 0.29, 0.85),
      freezeDoor(0.22, -0.03, 0.29, 0.86),
      freezeDoor(0.76, 1.03, 0.30, 0.85)
    ])
  });

  function makeBeaconLayouts(rearY, sideY, frontY, width) {
    return Object.freeze([
      Object.freeze({ x: 0.5, y: rearY, width: width }),
      Object.freeze({ x: 0.50, y: sideY, width: width * 0.9 }),
      Object.freeze({ x: 0.50, y: sideY, width: width * 0.78 }),
      Object.freeze({ x: 0.50, y: sideY, width: width * 0.9 }),
      Object.freeze({ x: 0.5, y: frontY, width: width }),
      Object.freeze({ x: 0.50, y: sideY, width: width * 0.9 }),
      Object.freeze({ x: 0.50, y: sideY, width: width * 0.78 }),
      Object.freeze({ x: 0.50, y: sideY, width: width * 0.9 })
    ]);
  }

  var BEACON_LAYOUTS = Object.freeze({
    saloon: makeBeaconLayouts(0.12, 0.08, 0.10, 0.24),
    suv: makeBeaconLayouts(0.09, 0.06, 0.075, 0.25),
    van: makeBeaconLayouts(0.035, 0.025, 0.03, 0.23),
    motorhome: makeBeaconLayouts(0.03, 0.02, 0.025, 0.23),
    lorry: makeBeaconLayouts(0.025, 0.018, 0.022, 0.24)
  });

  function threeDigitAngle(angle) {
    return ('000' + angle).slice(-3);
  }

  function makeFrameDefinition(kind, index, geometry) {
    var angle = FRAME_ANGLES[index];
    var sourceX = geometry[0];
    var sourceY = geometry[1];
    var contentWidth = geometry[2];
    var contentHeight = geometry[3];
    var imageWidth = geometry[4];
    var imageHeight = geometry[5];
    var lights = LIGHT_LAYOUTS[kind][index];
    var wheels = WHEEL_LAYOUTS[kind][index];
    var door = DOOR_LAYOUTS[kind] ? DOOR_LAYOUTS[kind][index] : null;
    var beacon = BEACON_LAYOUTS[kind][index];
    return Object.freeze({
      bearingCenter: angle,
      angle: angle,
      code: threeDigitAngle(angle),
      url: SPRITE_ROOT + 'traffic-' + kind + '-' + threeDigitAngle(angle) + '-v305.webp',
      sourceRect: Object.freeze([sourceX, sourceY, contentWidth, contentHeight]),
      dimensions: Object.freeze([imageWidth, imageHeight]),
      contentInset: Object.freeze([
        sourceX,
        sourceY,
        imageWidth - sourceX - contentWidth,
        imageHeight - sourceY - contentHeight
      ]),
      contentAspect: contentWidth / contentHeight,
      anchor: Object.freeze([0.5, 1]),
      groundAnchor: Object.freeze({ x: 0.5, y: 1 }),
      lightAnchors: lights.anchors,
      lights: lights,
      wheelAnchors: wheels,
      wheels: wheels,
      door: door,
      beacon: beacon,
      plate: PLATE_LAYOUTS[kind][index]
    });
  }

  function makeFrames(kind) {
    var geometry = FRAME_GEOMETRY[kind];
    var frames = [];
    for (var index = 0; index < FRAME_ANGLES.length; index += 1) {
      frames.push(makeFrameDefinition(kind, index, geometry[index]));
    }
    return Object.freeze(frames);
  }

  var SPRITE_FRAMES = Object.freeze({
    saloon: makeFrames('saloon'),
    suv: makeFrames('suv'),
    van: makeFrames('van'),
    motorhome: makeFrames('motorhome'),
    lorry: makeFrames('lorry')
  });

  function makeExportedDefinition(kind) {
    var dimensions = FLEET_PROFILES[kind];
    return Object.freeze({
      id: dimensions.id,
      spriteKind: dimensions.spriteKind,
      width: dimensions.width,
      height: dimensions.height,
      length: dimensions.length,
      renderLength: dimensions.renderLength,
      aliased: dimensions.aliased,
      sourceFidelity: dimensions.sourceFidelity,
      paintFamily: dimensions.paintFamily,
      marking: dimensions.marking,
      label: dimensions.label,
      frames: SPRITE_FRAMES[dimensions.spriteKind]
    });
  }

  // Keep physical dimensions and all eight view contracts together on the
  // public API. This also gives diagnostics a single authoritative manifest.
  var EXPORTED_DEFS = (function exportedDefinitions() {
    var definitions = {};
    Object.keys(FLEET_PROFILES).forEach(function addDefinition(kind) {
      definitions[kind] = makeExportedDefinition(kind);
    });
    return Object.freeze(definitions);
  })();

  function clamp(value, minimum, maximum) {
    return Math.max(minimum, Math.min(maximum, value));
  }

  function finite(value, fallback) {
    var number = Number(value);
    return Number.isFinite(number) ? number : fallback;
  }

  function smoothstep(edge0, edge1, value) {
    if (!(edge1 > edge0)) return value >= edge1 ? 1 : 0;
    var unit = clamp((value - edge0) / (edge1 - edge0), 0, 1);
    return unit * unit * (3 - 2 * unit);
  }

  function rawVehicleKind(vehicle) {
    if (!vehicle || typeof vehicle !== 'object') return null;
    var rawKind = vehicle.visualKind;
    if (rawKind === undefined || rawKind === null || rawKind === '') rawKind = vehicle.trafficKind;
    if (rawKind === undefined || rawKind === null || rawKind === '') rawKind = vehicle.vehicleType;
    if (rawKind === undefined || rawKind === null || rawKind === '') rawKind = vehicle.kind;
    if (rawKind === undefined || rawKind === null || rawKind === '') rawKind = vehicle.type;
    if (rawKind === undefined || rawKind === null || rawKind === '') return null;
    return String(rawKind).toLowerCase().trim();
  }

  function normaliseFleetKind(vehicle) {
    var kind = rawVehicleKind(vehicle);
    if (!kind) return null;
    kind = FLEET_ALIASES[kind] || kind;
    return FLEET_PROFILES[kind] ? kind : null;
  }

  function normaliseKind(vehicle) {
    var fleetKind = normaliseFleetKind(vehicle);
    return fleetKind ? FLEET_PROFILES[fleetKind].spriteKind : null;
  }

  function fleetProfileFor(vehicle) {
    var fleetKind = normaliseFleetKind(vehicle);
    return fleetKind ? FLEET_PROFILES[fleetKind] : null;
  }

  function canDrawTrafficVehicle3D(vehicle) {
    return normaliseFleetKind(vehicle) !== null;
  }

  function textureUrl(path) {
    try {
      if (globalScope.document && globalScope.document.baseURI && typeof globalScope.URL === 'function') {
        var resolved = new globalScope.URL(path, globalScope.document.baseURI);
        return resolved.protocol === 'file:' ? resolved.pathname : resolved.href;
      }
    } catch (error) {}
    return path;
  }

  function requestFrame(frame) {
    if (!frame || typeof globalScope.Image !== 'function') return null;
    var key = frame.url;
    var entry = FRAME_CACHE[key];
    if (entry) return entry;

    var image = new globalScope.Image();
    entry = { image: image, state: 'loading', frame: frame };
    FRAME_CACHE[key] = entry;
    image.decoding = 'async';
    try { image.fetchPriority = 'low'; } catch (error) {}
    image.onload = function frameLoaded() { entry.state = 'ready'; };
    image.onerror = function frameFailed() { entry.state = 'failed'; };
    image.src = textureUrl(frame.url);
    if (image.complete && image.naturalWidth > 0 && image.naturalHeight > 0) entry.state = 'ready';
    return entry;
  }

  function isFrameReady(entry) {
    if (!entry || entry.state === 'failed') return false;
    var image = entry.image;
    if (image && image.complete && image.naturalWidth > 0 && image.naturalHeight > 0) {
      entry.state = 'ready';
    }
    if (entry.state !== 'ready') return false;
    var rect = entry.frame.sourceRect;
    return rect[0] >= 0 && rect[1] >= 0 && rect[2] > 0 && rect[3] > 0 &&
      rect[0] + rect[2] <= image.naturalWidth && rect[1] + rect[3] <= image.naturalHeight;
  }

  function preloadCoreFrames() {
    var kinds = Object.keys(SPRITE_FRAMES);
    for (var kindIndex = 0; kindIndex < kinds.length; kindIndex += 1) {
      var frames = SPRITE_FRAMES[kinds[kindIndex]];
      for (var coreIndex = 0; coreIndex < CORE_FRAME_INDICES.length; coreIndex += 1) {
        requestFrame(frames[CORE_FRAME_INDICES[coreIndex]]);
      }
    }
  }

  function normaliseRouteId(routeId) {
    var route = String(routeId || 'city').toLowerCase();
    if (route === 'm1' || route === 'highway') return 'motorway';
    return ROUTE_PREFETCH_KINDS[route] ? route : 'city';
  }

  function idleSchedule(callback) {
    if (typeof globalScope.requestIdleCallback === 'function') {
      globalScope.requestIdleCallback(callback, { timeout: 900 });
      return;
    }
    if (typeof globalScope.setTimeout === 'function') {
      globalScope.setTimeout(function deferredPrefetch() {
        callback({ timeRemaining: function generousFallbackBudget() { return 8; } });
      }, 0);
      return;
    }
    callback({ timeRemaining: function synchronousBudget() { return 50; } });
  }

  function prefetchTrafficFrames(routeId, options) {
    var route = normaliseRouteId(routeId);
    var settings = options && typeof options === 'object' ? options : {};
    var state = ROUTE_PREFETCH_STATE[route];
    if (state && !settings.force) return 0;
    if (typeof globalScope.Image !== 'function') return 0;

    var kinds = Array.isArray(settings.kinds) && settings.kinds.length ? settings.kinds : ROUTE_PREFETCH_KINDS[route];
    var queue = [];
    for (var kindIndex = 0; kindIndex < kinds.length; kindIndex += 1) {
      var kind = normaliseKind({ kind: kinds[kindIndex] });
      if (!kind) continue;
      var frames = SPRITE_FRAMES[kind];
      for (var detailIndex = 0; detailIndex < DETAIL_FRAME_INDICES.length; detailIndex += 1) {
        queue.push(frames[DETAIL_FRAME_INDICES[detailIndex]]);
      }
    }

    ROUTE_PREFETCH_STATE[route] = { status: 'scheduled', requested: queue.length, ready: 0 };
    var cursor = 0;
    function shouldContinue() {
      return typeof settings.shouldContinue !== 'function' || settings.shouldContinue();
    }
    function pump(deadline) {
      if (!shouldContinue()) {
        delete ROUTE_PREFETCH_STATE[route];
        return;
      }
      var requestedThisSlice = 0;
      while (cursor < queue.length && requestedThisSlice < 6 &&
        (requestedThisSlice < 2 || finite(deadline && deadline.timeRemaining && deadline.timeRemaining(), 0) > 3)) {
        if (!shouldContinue()) {
          delete ROUTE_PREFETCH_STATE[route];
          return;
        }
        requestFrame(queue[cursor]);
        cursor += 1;
        requestedThisSlice += 1;
      }
      ROUTE_PREFETCH_STATE[route].ready = cursor;
      if (cursor < queue.length) idleSchedule(pump);
      else ROUTE_PREFETCH_STATE[route].status = 'requested';
    }
    idleSchedule(pump);
    return queue.length;
  }

  function isPostRidePrefetchPhase(state, options) {
    state = state && typeof state === 'object' ? state : {};
    options = options && typeof options === 'object' ? options : {};
    if (options.postRide === true) return true;
    var phase = String(options.phase || state.phase || '').toLowerCase();
    return phase === 'finished' || phase === 'post-ride' || phase === 'postride';
  }

  function ensureRoutePrefetch(state, options) {
    // Core front/rear frames are still primed at script load.  The remaining
    // route pack must not compete with a cold first ride for network, decode or
    // main-thread time; it is warmed only once the result/post-ride phase draws.
    if (!isPostRidePrefetchPhase(state, options)) return 0;
    state = state && typeof state === 'object' ? state : {};
    options = options && typeof options === 'object' ? options : {};
    var route = normaliseRouteId(options.routeId || state.routeId);
    if (!ROUTE_PREFETCH_STATE[route]) prefetchTrafficFrames(route, {
      shouldContinue: function continueOnlyAfterRide() {
        return isPostRidePrefetchPhase(state, options);
      }
    });
    return ROUTE_PREFETCH_STATE[route] ? ROUTE_PREFETCH_STATE[route].requested : 0;
  }

  function circularAngleDistance(first, second) {
    var difference = Math.abs((first - second) % 360);
    return difference > 180 ? 360 - difference : difference;
  }

  function quantiseViewIndex(bearing) {
    return Math.floor((bearing + 22.5) / 45) % 8;
  }

  function pruneIdViewState() {
    if (!ID_VIEW_STATE || ID_VIEW_STATE.size <= 1536 || viewUseSerial % 256 !== 0) return;
    var oldestUseful = viewUseSerial - 4096;
    ID_VIEW_STATE.forEach(function removeStale(value, key) {
      if (!value || value.lastUsed < oldestUseful) ID_VIEW_STATE.delete(key);
    });
  }

  function viewStateFor(vehicle, kind) {
    viewUseSerial += 1;
    var hasId = vehicle.id !== undefined && vehicle.id !== null;
    var state;
    if (hasId && ID_VIEW_STATE) {
      var idKey = kind + ':' + String(vehicle.id);
      state = ID_VIEW_STATE.get(idKey);
      if (!state) {
        state = { index: null, lastUsed: viewUseSerial };
        ID_VIEW_STATE.set(idKey, state);
      }
      state.lastUsed = viewUseSerial;
      pruneIdViewState();
      return state;
    }
    if (OBJECT_VIEW_STATE) {
      state = OBJECT_VIEW_STATE.get(vehicle);
      if (!state) {
        state = { index: null, lastUsed: viewUseSerial };
        OBJECT_VIEW_STATE.set(vehicle, state);
      }
      state.lastUsed = viewUseSerial;
      return state;
    }
    return { index: null, lastUsed: viewUseSerial };
  }

  function selectStableViewIndex(vehicle, kind, bearing) {
    var state = viewStateFor(vehicle, kind);
    var nearest = quantiseViewIndex(bearing);
    if (state.index !== null) {
      var previousCentre = FRAME_ANGLES[state.index];
      if (circularAngleDistance(bearing, previousCentre) <= 22.5 + HYSTERESIS_HALF_WIDTH_DEGREES) {
        return state.index;
      }
    }
    state.index = nearest;
    return nearest;
  }

  function nearestReadyFrame(kind, bearing, selectedIndex) {
    var frames = SPRITE_FRAMES[kind];
    var selected = requestFrame(frames[selectedIndex]);
    if (isFrameReady(selected)) return { entry: selected, index: selectedIndex };

    var best = null;
    var bestDistance = Infinity;
    for (var index = 0; index < frames.length; index += 1) {
      var entry = FRAME_CACHE[frames[index].url];
      if (!isFrameReady(entry)) continue;
      var distance = circularAngleDistance(bearing, frames[index].bearingCenter);
      if (distance < bestDistance) {
        best = { entry: entry, index: index };
        bestDistance = distance;
      }
    }
    return best;
  }

  function resolveVehiclePosition(vehicle, definition, state, options) {
    var routeId = String(options.routeId || state.routeId || '').toLowerCase();
    var defaultHalfWidth = routeId === 'motorway' || routeId === 'm1' ? 5.4 : 3.8;
    var roadHalfWidth = finite(options.roadHalfWidth, finite(state.roadHalfWidth, defaultHalfWidth));
    var centreX;
    if (Number.isFinite(+vehicle.roadX)) centreX = +vehicle.roadX;
    else if (Number.isFinite(+vehicle.laneX)) centreX = +vehicle.laneX;
    else if (Number.isFinite(+vehicle.lateral)) centreX = +vehicle.lateral;
    else centreX = finite(vehicle.lane, 0) * roadHalfWidth;
    return {
      centreX: centreX,
      centreZ: finite(vehicle.distance, NaN),
      direction: finite(vehicle.direction, 1) < 0 ? -1 : 1,
      // Normal game headings stay close to straight ahead. Normalising the
      // complete circle also keeps the selector correct through junction turns,
      // spins and deterministic full-bearing diagnostics.
      yaw: (function normaliseYaw(value) {
        var yaw = finite(value, 0) % (Math.PI * 2);
        if (yaw > Math.PI) yaw -= Math.PI * 2;
        else if (yaw < -Math.PI) yaw += Math.PI * 2;
        return yaw;
      })(vehicle.heading !== undefined ? vehicle.heading : vehicle.yaw),
      height: definition.height
    };
  }

  function cameraRelativeBearing(position) {
    var sine = Math.sin(position.yaw);
    var cosine = Math.cos(position.yaw);
    var forwardX = sine;
    var forwardZ = position.direction * cosine;
    var rightX = position.direction * cosine;
    var rightZ = -sine;
    var toCameraX = -position.centreX;
    var toCameraZ = -position.centreZ;
    var rearDot = toCameraX * -forwardX + toCameraZ * -forwardZ;
    var sideDot = toCameraX * rightX + toCameraZ * rightZ;
    var bearing = Math.atan2(sideDot, rearDot) * 180 / Math.PI;
    return (bearing + 360) % 360;
  }

  function projectPoint(projector, x, y, z) {
    if (!(z > 1.02)) return null;
    var point;
    try { point = projector(x, y, z); } catch (error) { return null; }
    if (!point || !Number.isFinite(+point.x) || !Number.isFinite(+point.y)) return null;
    return { x: +point.x, y: +point.y };
  }

  function resolveProfile(api, state, options) {
    if (api && typeof api.getAtmosphericProfile === 'function') {
      try {
        var supplied = api.getAtmosphericProfile(state || options || {}, options || {});
        if (supplied && typeof supplied === 'object') return supplied;
      } catch (error) {}
    }
    var time = String(state.timeOfDay || options.timeOfDay || 'day').toLowerCase();
    var weather = String(state.weather || options.weather || 'clear').toLowerCase();
    var objectLoss = weather === 'fog' ? 0.95 : weather === 'storm' ? 0.79 : weather === 'rain' ? 0.61 : weather === 'post-rain' ? 0.44 : 0.28;
    return {
      timeOfDay: time,
      weather: weather,
      objectLoss: objectLoss,
      visibilityStart: weather === 'fog' ? 45 : weather === 'storm' ? 132 : weather === 'rain' ? 205 : weather === 'post-rain' ? 245 : 315,
      visibilityEnd: weather === 'fog' ? 225 : weather === 'storm' ? 375 : weather === 'rain' ? 455 : weather === 'post-rain' ? 480 : 510,
      hardFadeStart: 432,
      hardFadeEnd: 520,
      lightRetention: time === 'night' ? 0.62 : time === 'dusk' ? 0.40 : 0.18
    };
  }

  function distanceOpacity(api, distance, profile, emissive) {
    if (api && typeof api.distanceLodAlpha === 'function') {
      try {
        var supplied = api.distanceLodAlpha(distance, profile, { emissive: !!emissive });
        if (Number.isFinite(+supplied)) return clamp(+supplied, 0, 1);
      } catch (error) {}
    }
    var loss = clamp(finite(profile.objectLoss, 0.28), 0, 1);
    var start = finite(profile.visibilityStart, 315);
    var end = finite(profile.visibilityEnd, 510);
    var hardStart = finite(profile.hardFadeStart, 432);
    var hardEnd = finite(profile.hardFadeEnd, 520);
    var atmospheric = 1 - loss * smoothstep(start, end, distance);
    var hard = 1 - smoothstep(hardStart, hardEnd, distance);
    if (emissive) {
      var retention = clamp(finite(profile.lightRetention, 0.18), 0, 1);
      return clamp(Math.max(atmospheric * hard, hard * retention), 0, 1);
    }
    return clamp(atmospheric * hard, 0, 1);
  }

  function explicitAlpha(vehicle, options) {
    var alpha = clamp(finite(options.alpha, 1), 0, 1);
    if (Number.isFinite(+vehicle.opacity)) alpha *= clamp(+vehicle.opacity, 0, 1);
    else if (Number.isFinite(+vehicle.renderAlpha)) alpha *= clamp(+vehicle.renderAlpha, 0, 1);
    return alpha;
  }

  function profileWetness(profile, state) {
    if (Number.isFinite(+profile.wetness)) return clamp(+profile.wetness, 0, 1.4);
    var weather = lowerText(profile.weather || state.weather, 'clear');
    return weather === 'storm' ? 1 : weather === 'rain' ? 0.82 : weather === 'post-rain' ? 0.58 : weather === 'fog' ? 0.2 : 0.05;
  }

  function profileRain(profile, state) {
    if (Number.isFinite(+profile.rainIntensity)) return clamp(+profile.rainIntensity, 0, 1.8);
    var weather = lowerText(profile.weather || state.weather, 'clear');
    return weather === 'storm' ? 1.65 : weather === 'rain' ? 1 : weather === 'post-rain' ? 0.035 : 0;
  }

  function drawContactShadow(ctx, destination, opacity, weather, vehicle) {
    var rainSoftening = weather === 'rain' || weather === 'storm' ? 0.68 : weather === 'post-rain' ? 0.78 : weather === 'fog' ? 0.56 : 1;
    var speed = Math.abs(finite(vehicle && vehicle.speed, 0));
    var speedStretch = 1 + smoothstep(35, 110, speed) * 0.08;
    var outerAlpha = clamp(opacity * rainSoftening * 0.19, 0, 0.19);
    var centreX = destination.x + destination.width * 0.5;
    var centreY = destination.y + destination.height + 0.5;
    var radiusX = destination.width * 0.39 * speedStretch;
    var radiusY = clamp(destination.height * 0.055, 1, 8);

    ctx.fillStyle = 'rgba(2,5,7,' + outerAlpha + ')';
    ctx.beginPath();
    ctx.ellipse(centreX, centreY, radiusX, radiusY, 0, 0, Math.PI * 2);
    ctx.fill();

    ctx.fillStyle = 'rgba(0,2,3,' + clamp(opacity * rainSoftening * 0.16, 0, 0.16) + ')';
    ctx.beginPath();
    ctx.ellipse(centreX, centreY - radiusY * 0.15, radiusX * 0.72, Math.max(0.8, radiusY * 0.42), 0, 0, Math.PI * 2);
    ctx.fill();
  }

  function lowerText(value, fallback) {
    return String(value === undefined || value === null ? fallback : value).toLowerCase();
  }

  function numericSignal(value) {
    if (typeof value === 'string') {
      var text = lowerText(value, '');
      if (text === 'left' || text === 'nearside') return -1;
      if (text === 'right' || text === 'offside') return 1;
      if (text === 'hazard' || text === 'hazards') return 2;
    }
    var number = finite(value, 0);
    if (number > 1.5) return 2;
    return number < -0.25 ? -1 : number > 0.25 ? 1 : 0;
  }

  function resolveTurnSignal(vehicle) {
    var manoeuvre = vehicle && vehicle.manoeuvre && typeof vehicle.manoeuvre === 'object' ? vehicle.manoeuvre : {};
    var brain = vehicle && (vehicle.trafficBrainState || vehicle.trafficBrain) || {};
    var hazards = Boolean(vehicle && (vehicle.hazardLights || vehicle.hazards || vehicle.brokenDown || vehicle.stranded));
    var explicit = numericSignal(vehicle && vehicle.indicator);
    if (!explicit) explicit = numericSignal(vehicle && vehicle.turnSignal);
    if (!explicit) explicit = numericSignal(vehicle && vehicle.signal);
    if (!explicit) explicit = numericSignal(manoeuvre.indicator);
    if (!explicit) explicit = numericSignal(brain.indicator);
    if (explicit === 2) hazards = true;

    var phase = lowerText(brain.phase || manoeuvre.phase, '');
    var signallingPhase = phase === 'signal' || phase === 'reserve-gap' || phase === 'change-lane' ||
      phase === 'move-out' || phase === 'return' || phase === 'waiting' || phase === 'entering';
    var signalsPermitted = manoeuvre.signals !== false && brain.signals !== false;
    if (!explicit && signallingPhase && signalsPermitted) {
      var currentLane = finite(vehicle && vehicle.lane, finite(vehicle && vehicle.laneAnchor, 0));
      var targetLane = finite(
        vehicle && vehicle.targetLane,
        finite(manoeuvre.targetLane, finite(brain.targetLane, currentLane))
      );
      if (Math.abs(targetLane - currentLane) > 0.018) explicit = targetLane > currentLane ? 1 : -1;
      else if (phase === 'return' && Number.isFinite(+manoeuvre.startLane)) {
        explicit = +manoeuvre.startLane > currentLane ? 1 : -1;
      }
    }
    return { direction: clamp(explicit, -1, 1), hazards: hazards };
  }

  function indicatorFlashOn(state) {
    var elapsed = finite(state && state.elapsed, finite(state && state.time, 0));
    // Approximately 93 flashes per minute, inside the familiar UK road-vehicle
    // cadence and slow enough to telegraph cleanly on a small display.
    return (elapsed * 1.55 % 1) < 0.55;
  }

  function lightConditions(vehicle, state, profile) {
    var time = lowerText(profile.timeOfDay || state.timeOfDay, 'day');
    var weather = lowerText(profile.weather || state.weather, 'clear');
    var lowLight = time === 'night' || time === 'dusk' || time === 'dawn';
    var poorVisibility = weather === 'rain' || weather === 'storm' || weather === 'fog';
    var signal = resolveTurnSignal(vehicle);
    var brain = vehicle && (vehicle.trafficBrainState || vehicle.trafficBrain) || {};
    return {
      headlamps: vehicle.headlights === true || vehicle.lights === true || lowLight || poorVisibility,
      tailLamps: vehicle.tailLights === true || lowLight || poorVisibility,
      braking: vehicle.braking === true || vehicle.brake === true || brain.braking === true || brain.phase === 'brake',
      indicator: signal.direction,
      hazards: signal.hazards,
      weather: weather
    };
  }

  var LAMP_GLOW_TIERS = Object.freeze({
    smooth: Object.freeze({ head: 0.20, tail: 0.15, brake: 0.24, radiusFactor: 3.40, radiusCap: 12 }),
    enhanced: Object.freeze({ head: 0.25, tail: 0.20, brake: 0.31, radiusFactor: 4.10, radiusCap: 18 }),
    ultra: Object.freeze({ head: 0.30, tail: 0.24, brake: 0.38, radiusFactor: 4.80, radiusCap: 26 })
  });

  var LAMP_GLOW_WEATHER = Object.freeze({
    clear: Object.freeze({ radius: 1, alpha: 1 }),
    'post-rain': Object.freeze({ radius: 1.12, alpha: 0.96 }),
    rain: Object.freeze({ radius: 1.22, alpha: 0.92 }),
    storm: Object.freeze({ radius: 1.34, alpha: 0.86 }),
    fog: Object.freeze({ radius: 1.52, alpha: 0.78 })
  });

  // These values affect only a compact pocket around a real lamp anchor. They
  // are deliberately separate from tyre spray and wet-road reflections: this
  // layer represents light caught by suspended mist and nearby droplets, not a
  // second beam or a duplicate road-length light column.
  var LAMP_AIR_WEATHER = Object.freeze({
    'post-rain': Object.freeze({ radius: 1.55, alpha: 0.32, droplets: 1 }),
    rain: Object.freeze({ radius: 1.85, alpha: 0.48, droplets: 3 }),
    storm: Object.freeze({ radius: 2.08, alpha: 0.56, droplets: 4 }),
    fog: Object.freeze({ radius: 2.35, alpha: 0.44, droplets: 1 })
  });

  function normaliseGlowTier(state, options) {
    var tier = lowerText(
      options && (options.tier || options.quality || options.graphicsTier) ||
      state && (state.tier || state.quality || state.graphicsTier),
      'enhanced'
    );
    if (tier === 'smooth' || tier === 'low' || tier === 'performance') return 'smooth';
    if (tier === 'ultra' || tier === 'high' || tier === 'cinematic') return 'ultra';
    return 'enhanced';
  }

  function getLampGlowStyle(state, profile, options, lod, role, radiusX, radiusY, lampAlpha, isSide) {
    var tierName = normaliseGlowTier(state, options);

    var time = lowerText(profile && profile.timeOfDay || state && state.timeOfDay, 'day');
    var weather = lowerText(profile && profile.weather || state && state.weather, 'clear');
    var poorVisibility = weather === 'rain' || weather === 'storm' || weather === 'fog';
    var timeFactor = time === 'night' ? 1 :
      (time === 'dusk' || time === 'dawn' ? 0.68 : (poorVisibility ? 0.30 : 0));
    if (timeFactor <= 0) return null;

    var tier = LAMP_GLOW_TIERS[tierName];
    var weatherProfile = LAMP_GLOW_WEATHER[weather] || LAMP_GLOW_WEATHER.clear;
    var lodRadiusFactor = lod === 'far' ? 0.58 : lod === 'mid' ? 0.82 : 1;
    var lodAlphaFactor = lod === 'far' ? 0.70 : lod === 'mid' ? 0.88 : 1;
    var sideFactor = isSide ? 0.72 : 1;
    var roleAlpha = role === 'brake' ? tier.brake : role === 'tail' ? tier.tail : tier.head;
    if (role === 'indicator') roleAlpha *= 0.80;
    var cap = tier.radiusCap * lodRadiusFactor;
    var sourceRadius = Math.max(finite(radiusX, 0), finite(radiusY, 0));
    var radius = clamp(
      sourceRadius * tier.radiusFactor * weatherProfile.radius * sideFactor * lodRadiusFactor,
      lod === 'far' ? 1.35 : 2.25,
      cap
    );
    var alpha = clamp(lampAlpha * roleAlpha * weatherProfile.alpha * timeFactor * lodAlphaFactor, 0, 0.30);
    if (alpha <= 0.003 || radius <= 0) return null;
    return {
      effect: 'anchored-vehicle-lamp-halo',
      owner: 'photographic-traffic',
      tier: tierName,
      radius: radius,
      alpha: alpha
    };
  }

  function lampHotColour(colour) {
    var channels = String(colour || '').split(',').map(function toChannel(value) {
      return clamp(Math.round(finite(value, 0)), 0, 255);
    });
    var red = channels[0] || 0;
    var green = channels[1] || 0;
    var blue = channels[2] || 0;
    if (red > 220 && green < 90 && blue < 110) return '255,154,166';
    if (red > 225 && green > 105 && blue < 105) return '255,231,158';
    if (blue > red * 1.18 && blue > green) return '191,231,255';
    return '248,253,255';
  }

  function deterministicLampUnit(seed, index) {
    var value = (seed ^ Math.imul(index + 1, 0x9e3779b1)) >>> 0;
    value = Math.imul(value ^ value >>> 16, 0x85ebca6b) >>> 0;
    value = Math.imul(value ^ value >>> 13, 0xc2b2ae35) >>> 0;
    return ((value ^ value >>> 16) >>> 0) / 4294967295;
  }

  function drawLampAirScatter(ctx, x, y, colour, style, state, profile, options, lod, role, sourceKey) {
    if (!ctx || !style || typeof ctx.createRadialGradient !== 'function' || typeof ctx.ellipse !== 'function') return false;
    var weather = lowerText(profile && profile.weather || state && state.weather, 'clear');
    var air = LAMP_AIR_WEATHER[weather];
    if (!air) return false;

    var lodFactor = lod === 'far' ? 0.58 : lod === 'mid' ? 0.82 : 1;
    var radius = clamp(style.radius * air.radius, lod === 'far' ? 1.8 : 4, 48 * lodFactor);
    var alpha = clamp(style.alpha * air.alpha * lodFactor, 0, 0.11);
    if (alpha <= 0.003) return false;

    ctx.save();
    try {
      ctx.globalCompositeOperation = 'screen';
      var scatter = ctx.createRadialGradient(x, y, 0, x, y, radius);
      scatter.addColorStop(0, 'rgba(' + colour + ',' + alpha + ')');
      scatter.addColorStop(0.34, 'rgba(' + colour + ',' + (alpha * 0.40) + ')');
      scatter.addColorStop(0.72, 'rgba(' + colour + ',' + (alpha * 0.10) + ')');
      scatter.addColorStop(1, 'rgba(' + colour + ',0)');
      ctx.fillStyle = scatter;
      ctx.beginPath();
      ctx.ellipse(x, y, radius * 1.14, radius * 0.72, 0, 0, Math.PI * 2);
      ctx.fill();

      var tier = normaliseGlowTier(state, options);
      var tierLimit = tier === 'smooth' ? 1 : tier === 'enhanced' ? 2 : air.droplets;
      var lodLimit = lod === 'far' ? 1 : lod === 'mid' ? 2 : air.droplets;
      var dropletCount = Math.min(air.droplets, tierLimit, lodLimit);
      // Vehicle identity and the physical anchor slot keep the glint layout
      // stable while the projected lamp moves, so droplets do not random-walk
      // or flicker from frame to frame.
      var seed = registrationHash(String(sourceKey || role) + ':' + role + ':' + weather);
      var hotColour = lampHotColour(colour);
      for (var index = 0; index < dropletCount; index += 1) {
        var angle = deterministicLampUnit(seed, index * 3) * Math.PI * 2;
        var distance = radius * (0.30 + deterministicLampUnit(seed, index * 3 + 1) * 0.58);
        var dropletX = x + Math.cos(angle) * distance * 1.05;
        var dropletY = y + Math.sin(angle) * distance * 0.62;
        var dropletRadius = clamp(radius * (0.022 + deterministicLampUnit(seed, index * 3 + 2) * 0.020), 0.28, 1.05);
        var dropletAlpha = clamp(alpha * (0.72 + deterministicLampUnit(seed, index + 17) * 0.52), 0, 0.095);
        ctx.fillStyle = 'rgba(' + hotColour + ',' + dropletAlpha + ')';
        ctx.beginPath();
        ctx.ellipse(dropletX, dropletY, dropletRadius * 0.62, dropletRadius, angle * 0.16, 0, Math.PI * 2);
        ctx.fill();
      }
    } finally {
      ctx.restore();
    }
    return true;
  }

  function drawLampHalo(ctx, x, y, radiusX, radiusY, colour, lampAlpha, role, state, profile, options, lod, isSide, sourceKey) {
    if (!ctx || typeof ctx.createRadialGradient !== 'function' || typeof ctx.arc !== 'function') return false;
    var style = getLampGlowStyle(state, profile, options, lod, role, radiusX, radiusY, lampAlpha, isSide);
    if (!style) return false;
    drawLampAirScatter(ctx, x, y, colour, style, state, profile, options, lod, role, sourceKey);
    ctx.save();
    try {
      ctx.globalCompositeOperation = 'screen';
      var glow = ctx.createRadialGradient(x, y, 0, x, y, style.radius);
      glow.addColorStop(0, 'rgba(' + colour + ',' + style.alpha + ')');
      glow.addColorStop(0.30, 'rgba(' + colour + ',' + (style.alpha * 0.42) + ')');
      glow.addColorStop(1, 'rgba(' + colour + ',0)');
      ctx.fillStyle = glow;
      ctx.beginPath();
      ctx.arc(x, y, style.radius, 0, Math.PI * 2);
      ctx.fill();
    } finally {
      ctx.restore();
    }
    return true;
  }

  function drawLamp(ctx, x, y, radiusX, radiusY, colour, alpha) {
    if (alpha <= 0.003) return;
    ctx.save();
    try {
      // Preserve the saturated lens colour, then add a much smaller warm/cool
      // emissive centre. Keeping the additive core compact prevents red tail
      // lamps and amber indicators from being bleached into white discs.
      ctx.globalCompositeOperation = 'source-over';
      ctx.fillStyle = 'rgba(' + colour + ',' + clamp(alpha, 0, 1) + ')';
      ctx.beginPath();
      ctx.ellipse(x, y, radiusX, radiusY, 0, 0, Math.PI * 2);
      ctx.fill();

      ctx.globalCompositeOperation = 'screen';
      ctx.fillStyle = 'rgba(' + lampHotColour(colour) + ',' + clamp(alpha * 0.84, 0, 0.94) + ')';
      ctx.beginPath();
      ctx.ellipse(x, y, Math.max(0.24, radiusX * 0.42), Math.max(0.22, radiusY * 0.40), 0, 0, Math.PI * 2);
      ctx.fill();
    } finally {
      ctx.restore();
    }
  }

  function registrationHash(value) {
    var text = String(value === undefined || value === null ? '0' : value);
    var hash = 2166136261;
    for (var index = 0; index < text.length; index += 1) {
      hash ^= text.charCodeAt(index);
      hash = Math.imul(hash, 16777619);
    }
    return hash >>> 0;
  }

  function registrationForVehicle(vehicle) {
    var supplied = vehicle && (vehicle.registration || vehicle.registrationPlate || vehicle.plateText || vehicle.numberPlate);
    if (supplied) {
      var cleaned = String(supplied).toUpperCase().replace(/[^A-Z0-9 ]+/g, '').replace(/\s+/g, ' ').trim();
      if (cleaned) return cleaned.slice(0, 8);
    }
    var hash = registrationHash(vehicle && (vehicle.id !== undefined ? vehicle.id : rawVehicleKind(vehicle)));
    var letters = '';
    for (var index = 0; index < 3; index += 1) {
      letters += String.fromCharCode(65 + (hash >>> (index * 5)) % 26);
    }
    return 'AV26 ' + letters;
  }

  function drawRegistrationPlate(ctx, destination, frame, vehicle, opacity, lod) {
    var plate = frame.plate;
    if (!plate || lod === 'far' || destination.width < 5) return;
    var width = clamp(destination.width * plate.width, 3, destination.width * 0.31);
    var height = clamp(width / 5.05, 1, destination.height * 0.075);
    var centreX = destination.x + destination.width * plate.x;
    var centreY = destination.y + destination.height * plate.y;
    var left = centreX - width * 0.5;
    var top = centreY - height * 0.5;
    var alpha = clamp(opacity * (lod === 'near' ? 0.98 : 0.9), 0, 1);

    ctx.save();
    ctx.fillStyle = plate.face === 'rear' ? 'rgba(244,199,19,' + alpha + ')' : 'rgba(246,247,239,' + alpha + ')';
    ctx.strokeStyle = 'rgba(17,20,19,' + clamp(alpha * 0.88, 0, 0.88) + ')';
    ctx.lineWidth = clamp(height * 0.1, 0.35, 1.3);
    ctx.beginPath();
    if (typeof ctx.roundRect === 'function') ctx.roundRect(left, top, width, height, Math.max(0.5, height * 0.16));
    else ctx.rect(left, top, width, height);
    ctx.fill();
    ctx.stroke();
    if (lod === 'near' && width >= 19) {
      ctx.fillStyle = 'rgba(8,10,10,' + clamp(alpha * 0.94, 0, 0.94) + ')';
      ctx.font = '800 ' + clamp(height * 0.62, 4, 11) + 'px Arial, sans-serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText(registrationForVehicle(vehicle), centreX, centreY + height * 0.02, width * 0.92);
    }
    ctx.restore();
  }

  function drawMarkingPanel(ctx, destination, x, y, width, height, background, foreground, text, opacity) {
    var panelWidth = destination.width * width;
    var panelHeight = destination.height * height;
    if (panelWidth < 5 || panelHeight < 2) return;
    var left = destination.x + destination.width * x - panelWidth * 0.5;
    var top = destination.y + destination.height * y - panelHeight * 0.5;
    ctx.fillStyle = background.replace('{a}', String(clamp(opacity, 0, 1)));
    ctx.fillRect(left, top, panelWidth, panelHeight);
    if (panelWidth > 22 && panelHeight > 7 && text) {
      ctx.fillStyle = foreground.replace('{a}', String(clamp(opacity, 0, 1)));
      ctx.font = '800 ' + clamp(panelHeight * 0.48, 4, 12) + 'px Arial, sans-serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText(text, left + panelWidth * 0.5, top + panelHeight * 0.52, panelWidth * 0.9);
    }
  }

  function drawFleetMarkings(ctx, destination, frame, profile, opacity, lod) {
    if (!profile || !profile.marking || lod !== 'near' || destination.width < 24) return;
    var angle = frame.angle;
    var endOn = angle === 0 || angle === 180;
    var sideOn = angle === 90 || angle === 270;
    var marking = profile.marking;

    ctx.save();
    if (marking === 'taxi') {
      drawMarkingPanel(ctx, destination, 0.5, 0.045, 0.20, 0.055, 'rgba(248,249,241,{a})', 'rgba(10,14,14,{a})', 'TAXI', opacity * 0.94);
    } else if ((marking === 'bus' || marking === 'coach') && endOn) {
      var routeText = marking === 'bus' ? '42  CITY' : 'THE NORTH';
      drawMarkingPanel(ctx, destination, 0.5, angle === 180 ? 0.19 : 0.17, 0.58, 0.105, 'rgba(4,9,10,{a})', 'rgba(255,181,35,{a})', routeText, opacity * 0.92);
    } else if ((marking === 'bus' || marking === 'coach') && sideOn) {
      drawMarkingPanel(ctx, destination, 0.5, 0.48, 0.44, 0.09, 'rgba(16,38,48,{a})', 'rgba(238,245,245,{a})', marking === 'bus' ? 'LOCAL' : 'COACH', opacity * 0.82);
    } else if (marking === 'delivery' && sideOn) {
      drawMarkingPanel(ctx, destination, 0.52, 0.47, 0.46, 0.13, 'rgba(9,27,35,{a})', 'rgba(238,246,247,{a})', 'AVENRÀ DELIVERY', opacity * 0.84);
    } else if (marking === 'horsebox' && (sideOn || endOn)) {
      drawMarkingPanel(ctx, destination, 0.5, sideOn ? 0.48 : 0.62, sideOn ? 0.34 : 0.42, 0.105, 'rgba(244,207,45,{a})', 'rgba(19,24,22,{a})', 'HORSES', opacity * 0.86);
    } else if (marking === 'caravan' && sideOn) {
      ctx.fillStyle = 'rgba(35,104,151,' + clamp(opacity * 0.66, 0, 0.66) + ')';
      ctx.fillRect(destination.x + destination.width * 0.17, destination.y + destination.height * 0.63, destination.width * 0.66, Math.max(1, destination.height * 0.025));
    } else if (marking === 'artic' && angle === 0) {
      ctx.fillStyle = 'rgba(245,194,24,' + clamp(opacity * 0.82, 0, 0.82) + ')';
      ctx.fillRect(destination.x + destination.width * 0.20, destination.y + destination.height * 0.76, destination.width * 0.60, Math.max(1, destination.height * 0.018));
      ctx.fillStyle = 'rgba(219,38,48,' + clamp(opacity * 0.82, 0, 0.82) + ')';
      ctx.fillRect(destination.x + destination.width * 0.26, destination.y + destination.height * 0.785, destination.width * 0.48, Math.max(1, destination.height * 0.018));
    }
    ctx.restore();
  }

  function emergencyActive(vehicle) {
    return vehicle && (vehicle.emergency === true || vehicle.blueLights === true || vehicle.emergencyLights === true);
  }

  function drawWheelMotion(ctx, destination, frame, vehicle, state, opacity, lod) {
    var wheels = frame.wheels;
    if (lod !== 'near' || !wheels || wheels.length === 0) return;
    var speed = Math.abs(finite(vehicle.speed, 0));
    var motion = smoothstep(8, 62, speed);
    if (motion <= 0.01) return;
    var elapsed = finite(state.elapsed, finite(state.time, 0));
    var identityPhase = finite(vehicle.id, 0) * 0.731;

    ctx.save();
    ctx.globalCompositeOperation = 'multiply';
    for (var index = 0; index < wheels.length; index += 1) {
      var wheel = wheels[index];
      var x = destination.x + destination.width * wheel.x;
      var y = destination.y + destination.height * wheel.y;
      var radius = clamp(destination.height * wheel.radius, 1.35, 18);
      var phase = elapsed * speed * 0.19 + identityPhase + wheel.phase;
      ctx.save();
      ctx.translate(x, y);
      ctx.rotate(phase);
      ctx.strokeStyle = 'rgba(4,7,8,' + clamp(opacity * (0.11 + motion * 0.12), 0, 0.23) + ')';
      ctx.lineWidth = clamp(radius * 0.22, 0.7, 3.2);
      ctx.beginPath();
      ctx.ellipse(0, 0, radius, radius * 0.72, 0, 0, Math.PI * 2);
      ctx.stroke();
      ctx.globalCompositeOperation = 'screen';
      ctx.strokeStyle = 'rgba(214,224,226,' + clamp(opacity * (1 - motion * 0.48) * 0.16, 0, 0.16) + ')';
      ctx.lineWidth = clamp(radius * 0.09, 0.45, 1.4);
      ctx.beginPath();
      ctx.moveTo(-radius * 0.42, 0);
      ctx.lineTo(radius * 0.42, 0);
      ctx.moveTo(0, -radius * 0.30);
      ctx.lineTo(0, radius * 0.30);
      ctx.stroke();
      ctx.restore();
      ctx.globalCompositeOperation = 'multiply';
    }
    ctx.restore();
  }

  function drawOpenDoor(ctx, destination, frame, vehicle, opacity, lod) {
    var door = frame.door;
    if (lod !== 'near' || !door || !vehicle.doorOpen || destination.height < 42) return;
    var hingeX = destination.x + destination.width * door.hingeX;
    var outerX = destination.x + destination.width * door.outerX;
    var topY = destination.y + destination.height * door.topY;
    var bottomY = destination.y + destination.height * door.bottomY;
    var direction = outerX < hingeX ? -1 : 1;
    var depth = Math.max(3, Math.abs(outerX - hingeX));

    ctx.save();
    ctx.shadowColor = 'rgba(0,0,0,.46)';
    ctx.shadowBlur = clamp(destination.height * 0.035, 1, 8);
    var panel = ctx.createLinearGradient(hingeX, topY, outerX, topY);
    panel.addColorStop(0, 'rgba(92,105,110,' + clamp(opacity * 0.86, 0, 0.86) + ')');
    panel.addColorStop(0.28, 'rgba(25,34,38,' + clamp(opacity * 0.94, 0, 0.94) + ')');
    panel.addColorStop(1, 'rgba(6,10,12,' + clamp(opacity * 0.97, 0, 0.97) + ')');
    ctx.fillStyle = panel;
    ctx.beginPath();
    ctx.moveTo(hingeX, topY);
    ctx.lineTo(outerX, topY + destination.height * 0.045);
    ctx.lineTo(outerX + direction * depth * 0.08, bottomY);
    ctx.lineTo(hingeX, bottomY - destination.height * 0.025);
    ctx.closePath();
    ctx.fill();
    ctx.shadowBlur = 0;
    ctx.strokeStyle = 'rgba(189,204,207,' + clamp(opacity * 0.34, 0, 0.34) + ')';
    ctx.lineWidth = clamp(destination.height * 0.009, 0.7, 2.1);
    ctx.stroke();
    ctx.fillStyle = 'rgba(124,155,164,' + clamp(opacity * 0.18, 0, 0.18) + ')';
    ctx.fillRect(
      Math.min(hingeX, outerX) + depth * 0.18,
      topY + destination.height * 0.08,
      depth * 0.62,
      Math.max(1, (bottomY - topY) * 0.26)
    );
    ctx.fillStyle = 'rgba(0,2,3,' + clamp(opacity * 0.16, 0, 0.16) + ')';
    ctx.beginPath();
    ctx.ellipse(outerX, destination.y + destination.height + 1, depth * 0.48, Math.max(1, destination.height * 0.025), 0, 0, Math.PI * 2);
    ctx.fill();
    ctx.restore();
  }

  function drawEmergencyBeacon(ctx, destination, frame, vehicle, state, opacity, lod) {
    if (lod === 'far' || !emergencyActive(vehicle) || !frame.beacon) return;
    var beacon = frame.beacon;
    var elapsed = finite(state.elapsed, finite(state.time, 0));
    var pulse = Math.floor(elapsed * 5.2) % 2;
    var centreX = destination.x + destination.width * beacon.x;
    var centreY = destination.y + destination.height * beacon.y;
    var barWidth = Math.min(destination.width * beacon.width, destination.height * 0.72);
    var radiusX = clamp(barWidth * 0.13, 1.1, 8);
    var radiusY = clamp(destination.height * 0.018, 0.8, 4);

    ctx.save();
    ctx.globalCompositeOperation = 'screen';
    ctx.fillStyle = 'rgba(4,14,22,' + clamp(opacity * 0.82, 0, 0.82) + ')';
    ctx.fillRect(centreX - barWidth * 0.5, centreY - radiusY * 0.45, barWidth, radiusY * 0.9);
    var leftAlpha = opacity * (pulse ? 0.34 : 0.96);
    var rightAlpha = opacity * (pulse ? 0.96 : 0.34);
    drawLamp(ctx, centreX - barWidth * 0.28, centreY, radiusX * 2.2, radiusY * 2.3, '18,108,255', leftAlpha * 0.18);
    drawLamp(ctx, centreX + barWidth * 0.28, centreY, radiusX * 2.2, radiusY * 2.3, '63,184,255', rightAlpha * 0.18);
    drawLamp(ctx, centreX - barWidth * 0.28, centreY, radiusX, radiusY, '30,126,255', leftAlpha);
    drawLamp(ctx, centreX + barWidth * 0.28, centreY, radiusX, radiusY, '118,220,255', rightAlpha);
    ctx.restore();
  }

  function drawWetReflection(ctx, destination, frame, vehicle, state, profile, opacity, lod) {
    if (lod === 'far') return;
    var wetness = profileWetness(profile, state);
    if (wetness <= 0.12) return;
    var layout = frame.lights;
    var conditions = lightConditions(vehicle, state, profile);
    var elapsed = finite(state.elapsed, finite(state.time, 0));
    var indicatorOn = (conditions.indicator !== 0 || conditions.hazards) && indicatorFlashOn(state);
    var emergency = emergencyActive(vehicle);
    var active = conditions.headlamps || conditions.tailLamps || conditions.braking || indicatorOn || emergency;
    if (!active) return;

    var speed = Math.abs(finite(vehicle.speed, 0));
    var length = destination.height * (0.52 + wetness * 0.72) * (1 - smoothstep(75, 130, speed) * 0.18);
    var baseY = destination.y + destination.height + 1;
    var anchors = layout && layout.anchors && layout.anchors.length ? layout.anchors : [freezePoint(0.35, 0.7), freezePoint(0.65, 0.7)];
    var face = layout ? layout.face : 'rear';

    ctx.save();
    ctx.globalCompositeOperation = 'screen';
    for (var index = 0; index < anchors.length; index += 1) {
      var anchor = anchors[index];
      var x = destination.x + destination.width * anchor[0];
      var colour = face === 'front' ? '188,229,255' : face === 'side' && index === 1 ? '188,229,255' : '244,37,58';
      if (indicatorOn) colour = '255,174,39';
      var strength = clamp(opacity * wetness * (conditions.braking ? 0.13 : 0.07), 0, 0.16);
      var gradient = ctx.createLinearGradient(x, baseY, x, baseY + length);
      gradient.addColorStop(0, 'rgba(' + colour + ',' + strength + ')');
      gradient.addColorStop(0.42, 'rgba(' + colour + ',' + strength * 0.32 + ')');
      gradient.addColorStop(1, 'rgba(' + colour + ',0)');
      ctx.fillStyle = gradient;
      var halfWidth = clamp(destination.width * 0.018, 0.7, 5);
      ctx.beginPath();
      ctx.moveTo(x - halfWidth, baseY);
      ctx.lineTo(x + halfWidth, baseY);
      ctx.lineTo(x + halfWidth * 0.38, baseY + length);
      ctx.lineTo(x - halfWidth * 0.38, baseY + length);
      ctx.closePath();
      ctx.fill();
    }
    if (emergency && frame.beacon) {
      var flash = Math.floor(elapsed * 5.2) % 2;
      var beaconX = destination.x + destination.width * frame.beacon.x;
      var blueStrength = clamp(opacity * wetness * (flash ? 0.12 : 0.075), 0, 0.14);
      var blue = ctx.createLinearGradient(beaconX, baseY, beaconX, baseY + length * 1.3);
      blue.addColorStop(0, 'rgba(40,139,255,' + blueStrength + ')');
      blue.addColorStop(0.5, 'rgba(40,139,255,' + blueStrength * 0.3 + ')');
      blue.addColorStop(1, 'rgba(40,139,255,0)');
      ctx.fillStyle = blue;
      ctx.fillRect(beaconX - destination.width * 0.035, baseY, destination.width * 0.07, length * 1.3);
    }
    ctx.restore();
  }

  function drawTyreSpray(ctx, destination, vehicle, state, profile, opacity, lod) {
    if (lod === 'far') return;
    var rain = profileRain(profile, state);
    var speed = Math.abs(finite(vehicle.speed, 0));
    var strength = rain * smoothstep(32, 92, speed) * opacity;
    if (strength <= 0.025) return;
    var elapsed = finite(state.elapsed, finite(state.time, 0));
    var count = lod === 'near' ? 6 : 3;
    var identity = finite(vehicle.id, 0) * 0.173;

    ctx.save();
    ctx.globalCompositeOperation = 'screen';
    for (var index = 0; index < count; index += 1) {
      var side = index % 2 === 0 ? -1 : 1;
      var phase = (elapsed * (0.62 + index * 0.035) + identity + index * 0.19) % 1;
      var x = destination.x + destination.width * (0.5 + side * (0.24 + phase * 0.13));
      var y = destination.y + destination.height * (0.91 + phase * 0.25);
      var radiusX = clamp(destination.width * (0.025 + phase * 0.035), 1, 11);
      var radiusY = clamp(destination.height * (0.018 + phase * 0.055), 1, 12);
      var alpha = clamp(strength * (1 - phase) * (lod === 'near' ? 0.075 : 0.045), 0, 0.12);
      ctx.fillStyle = 'rgba(190,218,226,' + alpha + ')';
      ctx.beginPath();
      ctx.ellipse(x, y, radiusX, radiusY, side * 0.22, 0, Math.PI * 2);
      ctx.fill();
    }
    ctx.restore();
  }

  function drawDynamicLights(ctx, destination, frame, vehicle, state, profile, lightOpacity, lod, options) {
    var layout = frame.lights;
    if (!layout || layout.face === 'none' || layout.anchors.length !== 2) return;
    var conditions = lightConditions(vehicle, state, profile);
    // A caravan reuses the nearest complete motorhome turntable, but it is a
    // trailer: retain its legal rear lamps without inventing front headlamps.
    if (normaliseFleetKind(vehicle) === 'caravan') conditions.headlamps = false;
    var isSide = layout.face === 'side';
    var isRear = layout.face === 'rear';
    var active = isSide ? (conditions.tailLamps || conditions.braking || conditions.headlamps) :
      isRear ? (conditions.tailLamps || conditions.braking) : conditions.headlamps;
    var elapsed = finite(state.elapsed, finite(state.time, 0));
    var indicatorOn = (conditions.indicator !== 0 || conditions.hazards) && indicatorFlashOn(state);
    if (!active && !indicatorOn) return;
    var sourceKey = vehicle.id !== undefined && vehicle.id !== null ? String(vehicle.id) :
      rawVehicleKind(vehicle) + ':' + finite(vehicle.lane, finite(vehicle.laneAnchor, 0));

    var radiusX = clamp(destination.width * (lod === 'near' ? 0.026 : 0.021), 0.75, 6.5);
    var radiusY = clamp(destination.height * (lod === 'near' ? 0.018 : 0.015), 0.65, 4.2);
    var baseAlpha = lightOpacity * (lod === 'near' ? 0.86 : 0.68);
    if (isSide) {
      var rearAnchor = layout.rearAnchor;
      var frontAnchor = layout.frontAnchor;
      var rearX = destination.x + destination.width * rearAnchor[0];
      var rearY = destination.y + destination.height * rearAnchor[1];
      var frontX = destination.x + destination.width * frontAnchor[0];
      var frontY = destination.y + destination.height * frontAnchor[1];
      if (conditions.tailLamps || conditions.braking) {
        var sideRearAlpha = baseAlpha * (conditions.braking ? 1 : 0.64);
        drawLampHalo(ctx, rearX, rearY, radiusX * 0.78, radiusY * 0.9, '244,31,47', sideRearAlpha,
          conditions.braking ? 'brake' : 'tail', state, profile, options, lod, true, sourceKey + ':side-rear');
        drawLamp(ctx, rearX, rearY, radiusX * 0.78, radiusY * 0.9, '244,31,47', sideRearAlpha);
      }
      if (conditions.headlamps) {
        var sideFrontAlpha = baseAlpha * 0.88;
        drawLampHalo(ctx, frontX, frontY, radiusX * 0.82, radiusY * 0.9, '229,245,255', sideFrontAlpha,
          'head', state, profile, options, lod, true, sourceKey + ':side-front');
        drawLamp(ctx, frontX, frontY, radiusX * 0.82, radiusY * 0.9, '229,245,255', sideFrontAlpha);
      }
      if (indicatorOn) {
        drawLampHalo(ctx, rearX, rearY, radiusX * 0.62, radiusY * 0.72, '255,174,39', lightOpacity * 0.88,
          'indicator', state, profile, options, lod, true, sourceKey + ':side-rear-indicator');
        drawLampHalo(ctx, frontX, frontY, radiusX * 0.62, radiusY * 0.72, '255,174,39', lightOpacity * 0.88,
          'indicator', state, profile, options, lod, true, sourceKey + ':side-front-indicator');
        drawLamp(ctx, rearX, rearY, radiusX * 0.62, radiusY * 0.72, '255,174,39', lightOpacity * 0.88);
        drawLamp(ctx, frontX, frontY, radiusX * 0.62, radiusY * 0.72, '255,174,39', lightOpacity * 0.88);
      }
      return;
    }
    for (var index = 0; index < layout.anchors.length; index += 1) {
      var anchor = layout.anchors[index];
      var x = destination.x + destination.width * anchor[0];
      var y = destination.y + destination.height * anchor[1];
      if (active) {
        if (isRear) {
          var rearAlpha = baseAlpha * (conditions.braking ? 1 : 0.68);
          var rearHalo = drawLampHalo(ctx, x, y, radiusX, radiusY, '244,31,47', rearAlpha,
            conditions.braking ? 'brake' : 'tail', state, profile, options, lod, false,
            sourceKey + ':rear:' + index);
          drawLamp(ctx, x, y, radiusX, radiusY, '244,31,47', rearAlpha);
          if (!rearHalo && conditions.braking && lod === 'near') {
            drawLamp(ctx, x, y, radiusX * 1.65, radiusY * 1.55, '255,35,52', baseAlpha * 0.20);
          }
        } else {
          var headHalo = drawLampHalo(ctx, x, y, radiusX, radiusY, '229,245,255', baseAlpha,
            'head', state, profile, options, lod, false, sourceKey + ':front:' + index);
          drawLamp(ctx, x, y, radiusX, radiusY, '229,245,255', baseAlpha);
          if (!headHalo && lod === 'near') {
            drawLamp(ctx, x, y, radiusX * 1.75, radiusY * 1.55, '163,218,255', baseAlpha * 0.16);
          }
        }
      }
    }

    if (indicatorOn) {
      var firstIndicator = conditions.hazards ? 0 : (conditions.indicator < 0 ? 0 : 1);
      var lastIndicator = conditions.hazards ? 1 : firstIndicator;
      for (var indicatorIndex = firstIndicator; indicatorIndex <= lastIndicator; indicatorIndex += 1) {
        var indicatorAnchor = layout.anchors[indicatorIndex];
        drawLampHalo(
          ctx,
          destination.x + destination.width * indicatorAnchor[0],
          destination.y + destination.height * indicatorAnchor[1],
          radiusX * 0.78,
          radiusY * 0.82,
          '255,174,39',
          lightOpacity * 0.92,
          'indicator',
          state,
          profile,
          options,
          lod,
          false,
          sourceKey + ':indicator:' + indicatorIndex
        );
        drawLamp(
          ctx,
          destination.x + destination.width * indicatorAnchor[0],
          destination.y + destination.height * indicatorAnchor[1],
          radiusX * 0.78,
          radiusY * 0.82,
          '255,174,39',
          lightOpacity * 0.92
        );
      }
    }
  }

  function drawTrafficVehicle3D(ctx, projector, vehicle, state, options) {
    if (!ctx || typeof projector !== 'function' || !vehicle) return false;
    state = state && typeof state === 'object' ? state : {};
    options = options && typeof options === 'object' ? options : {};
    ensureRoutePrefetch(state, options);

    var fleetKind = normaliseFleetKind(vehicle);
    if (!fleetKind) return false;
    var fleetProfile = FLEET_PROFILES[fleetKind];
    var kind = fleetProfile.spriteKind;
    var definition = fleetProfile;
    var position = resolveVehiclePosition(vehicle, definition, state, options);
    var drawDistance = finite(options.drawDistance, 520);

    // A supported sprite is considered handled when it is intentionally culled
    // by the near plane, distance haze or sub-pixel LOD. This prevents the old
    // body renderer from flashing through the atmospheric fade.
    if (!Number.isFinite(position.centreZ) || position.centreZ <= 1.05 || position.centreZ > drawDistance) return true;

    var ground = projectPoint(projector, position.centreX, 0, position.centreZ);
    var roof = projectPoint(projector, position.centreX, position.height, position.centreZ);
    if (!ground || !roof) return true;
    var projectedHeight = Math.abs(ground.y - roof.y);
    if (!(projectedHeight > 0)) return true;

    var bearing = cameraRelativeBearing(position);
    var selectedIndex = selectStableViewIndex(vehicle, fleetKind, bearing);
    var chosen = nearestReadyFrame(kind, bearing, selectedIndex);
    // On the first decode only, returning false lets the compiled game's own
    // flat traffic sprite remain visible. Once any photographic frame is ready,
    // the nearest decoded angle is used until the selected one completes.
    if (!chosen) return false;

    var frame = chosen.entry.frame;
    var destinationHeight = projectedHeight;
    var destinationWidth = destinationHeight * frame.contentAspect;
    var pixelSize = Math.max(destinationWidth, destinationHeight);
    if (pixelSize < 3) return true;
    var lod = pixelSize < 12 ? 'far' : pixelSize < 48 ? 'mid' : 'near';
    var destination = {
      x: ground.x - destinationWidth * frame.groundAnchor.x,
      y: ground.y - destinationHeight * frame.groundAnchor.y,
      width: destinationWidth,
      height: destinationHeight
    };

    var api = globalScope.AvenraNextGenV300 || {};
    var profile = resolveProfile(api, state, options);
    var explicit = explicitAlpha(vehicle, options);
    var bodyOpacity = distanceOpacity(api, position.centreZ, profile, false) * explicit;
    var visibilityStart = finite(profile.visibilityStart, 315);
    var visibilityEnd = finite(profile.visibilityEnd, 510);
    var haze = smoothstep(visibilityStart * 0.72, visibilityEnd, position.centreZ) * clamp(finite(profile.objectLoss, 0.28), 0, 1);
    // The sprite mixes into the route's already colour-matched atmosphere by
    // transparency. This is inexpensive and avoids a per-body Canvas filter.
    bodyOpacity *= 1 - clamp(haze * 0.16, 0, 0.16);
    var lightOpacity = distanceOpacity(api, position.centreZ, profile, true) * explicit;
    // Emissive points survive atmospheric object fade slightly longer than the
    // photographed body. Cull only once neither contribution can be perceived.
    if (bodyOpacity <= 0.004 && lightOpacity <= 0.004) return true;
    var weather = lowerText(profile.weather || state.weather, 'clear');
    var image = chosen.entry.image;
    var source = frame.sourceRect;

    ctx.save();
    if (options.resetGlobalAlpha === true) ctx.globalAlpha = 1;
    var inheritedAlpha = ctx.globalAlpha;
    ctx.imageSmoothingEnabled = true;
    try { ctx.imageSmoothingQuality = 'high'; } catch (error) {}

    if (lod !== 'far') {
      ctx.globalAlpha = inheritedAlpha;
      drawWetReflection(ctx, destination, frame, vehicle, state, profile, lightOpacity, lod);
      drawTyreSpray(ctx, destination, vehicle, state, profile, bodyOpacity, lod);
      drawContactShadow(ctx, destination, bodyOpacity, weather, vehicle);
    }

    ctx.globalAlpha = inheritedAlpha * bodyOpacity;
    // The one and only body draw: source and destination keep the same aspect.
    ctx.drawImage(
      image,
      source[0], source[1], source[2], source[3],
      destination.x, destination.y, destination.width, destination.height
    );

    // A restrained self-multiply grades the same identity/view after dark. It
    // is not a second angle or ghost frame: clear/day remains exactly one body
    // draw, while night no longer leaves a daylight-bright vehicle in a blue-
    // black scene. This avoids live filters, clipping and pixel readback.
    var timeOfDay = lowerText(profile.timeOfDay || state.timeOfDay, 'day');
    var nightGrade = timeOfDay === 'night' ? 0.22 : (timeOfDay === 'dusk' ? 0.08 : 0);
    if (nightGrade > 0) {
      ctx.globalCompositeOperation = 'multiply';
      ctx.globalAlpha = inheritedAlpha * bodyOpacity * nightGrade;
      ctx.drawImage(
        image,
        source[0], source[1], source[2], source[3],
        destination.x, destination.y, destination.width, destination.height
      );
      ctx.globalCompositeOperation = 'source-over';
    }

    if (lod !== 'far') {
      ctx.globalAlpha = inheritedAlpha;
      drawFleetMarkings(ctx, destination, frame, fleetProfile, bodyOpacity, lod);
      drawRegistrationPlate(ctx, destination, frame, vehicle, bodyOpacity, lod);
      drawWheelMotion(ctx, destination, frame, vehicle, state, bodyOpacity, lod);
      drawOpenDoor(ctx, destination, frame, vehicle, bodyOpacity, lod);
    }
    // Far vehicles still need a pair of powered pinpricks: omitting every lamp
    // below the old 12 px body threshold made the traffic stream look unlit.
    ctx.globalAlpha = inheritedAlpha;
    drawDynamicLights(ctx, destination, frame, vehicle, state, profile, lightOpacity, lod, options);
    if (lod !== 'far') drawEmergencyBeacon(ctx, destination, frame, vehicle, state, lightOpacity, lod);
    ctx.restore();
    return true;
  }

  function drawTrafficBatch3D(ctx, projector, vehicles, state, options) {
    if (!Array.isArray(vehicles)) return 0;
    var list = options && options.sorted ? vehicles : vehicles.slice().sort(function farToNear(first, second) {
      return finite(second && second.distance, 0) - finite(first && first.distance, 0);
    });
    var handled = 0;
    for (var index = 0; index < list.length; index += 1) {
      if (drawTrafficVehicle3D(ctx, projector, list[index], state, options)) handled += 1;
    }
    return handled;
  }

  var namespace = globalScope.AvenraNextGenV300;
  if (!namespace || typeof namespace !== 'object') namespace = {};
  namespace.trafficRendererMode = '2.5d-sprites';
  namespace.spriteTrafficVersion = VERSION;
  namespace.spriteTrafficDefinitions = EXPORTED_DEFS;
  namespace.spriteTrafficFrames = SPRITE_FRAMES;
  namespace.ukTrafficFleetProfiles = FLEET_PROFILES;
  namespace.ukTrafficFleetAliases = FLEET_ALIASES;
  namespace.resolveTrafficFleetKind = normaliseFleetKind;
  namespace.resolveTrafficSpriteKind = normaliseKind;
  namespace.resolveTrafficTurnSignal = resolveTurnSignal;
  namespace.getTrafficLampGlowStyle = getLampGlowStyle;
  namespace.prefetchTrafficFrames = prefetchTrafficFrames;
  namespace.prepareTrafficRoute2p5D = prefetchTrafficFrames;
  namespace.trafficDetailPrefetchPhase = 'finished';
  namespace.canDrawTrafficVehicle3D = canDrawTrafficVehicle3D;
  namespace.drawTrafficVehicle3D = drawTrafficVehicle3D;
  namespace.drawTrafficBatch3D = drawTrafficBatch3D;
  namespace.canDrawTrafficVehicle2p5D = canDrawTrafficVehicle3D;
  namespace.drawTrafficVehicle2p5D = drawTrafficVehicle3D;
  namespace.drawTrafficBatch2p5D = drawTrafficBatch3D;
  globalScope.AvenraNextGenV300 = namespace;

  preloadCoreFrames();
})(typeof window !== 'undefined' ? window : (typeof globalThis !== 'undefined' ? globalThis : this));
