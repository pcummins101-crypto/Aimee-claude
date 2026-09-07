import * as THREE from 'three';
/*
 * AVENRÀ EVO — route registry.
 *
 * Everything that makes one road different from another lives here as data:
 * geometry, width, elevation character, terrain, boundary mix, junctions and
 * the authored places along it. The route modules read the selected entry and
 * build from it, so adding a road means adding a record, not editing builders.
 *
 * The active route is fixed for the life of the page (the world is built once),
 * chosen by ?route= or the last choice made on the start screen.
 */
const EVO = window.EVO;

/* M1 helpers: the long profile, the land beside it and a way from world z to
 * distance along the road (the alignment runs steadily north, so z is enough). */
const M1 = {
  CONTROL: [[0,0], [-85.5,-234.9], [-171.0,-469.8], [-256.5,-704.8], [-342.0,-939.7], [-427.5,-1174.6], [-513.0,-1409.5], [-595.7,-1671.8], [-666.9,-1937.4], [-726.4,-2205.9], [-774.2,-2476.7], [-816.6,-2717.5], [-859.1,-2958.2], [-901.5,-3198.9], [-944.0,-3439.7], [-986.4,-3680.4], [-1028.9,-3921.1], [-1071.3,-4161.9], [-1113.8,-4402.6], [-1156.2,-4643.3], [-1213.2,-4879.4], [-1284.8,-5111.5], [-1370.7,-5338.6], [-1470.6,-5560.0], [-1584.0,-5774.7], [-1710.7,-5981.9], [-1850.0,-6180.9], [-1993.4,-6385.7], [-2136.8,-6590.4], [-2280.1,-6795.2], [-2423.5,-7000.0], [-2566.9,-7204.8], [-2710.3,-7409.6], [-2853.7,-7614.4], [-2997.1,-7819.2], [-3140.5,-8024.0], [-3283.9,-8228.7], [-3427.3,-8433.5], [-3570.7,-8638.3], [-3710.2,-8854.3], [-3841.5,-9075.4], [-3964.5,-9301.2], [-4079.0,-9531.5], [-4184.7,-9765.9], [-4281.7,-10004.1], [-4369.6,-10245.7], [-4453.6,-10476.4], [-4537.5,-10707.0], [-4621.5,-10937.7], [-4705.4,-11168.3], [-4789.4,-11399.0], [-4873.3,-11629.6], [-4957.3,-11860.3], [-5041.2,-12090.9], [-5125.2,-12321.6], [-5209.1,-12552.2], [-5293.1,-12782.9], [-5388.7,-13013.8], [-5494.4,-13240.4], [-5609.8,-13462.2], [-5734.8,-13678.7], [-5869.1,-13889.5], [-6012.5,-14094.3], [-6164.7,-14292.6], [-6325.4,-14484.2], [-6486.1,-14675.7], [-6646.8,-14867.2], [-6807.5,-15058.7], [-6968.2,-15250.2], [-7128.9,-15441.7], [-7289.6,-15633.2], [-7450.3,-15824.7], [-7611.0,-16016.2], [-7758.5,-16226.9], [-7887.1,-16449.6], [-7995.7,-16682.6], [-8083.7,-16924.3], [-8150.2,-17172.6], [-8194.9,-17425.9], [-8217.3,-17682.0], [-8240.0,-17941.1], [-8262.6,-18200.1], [-8285.3,-18459.1], [-8307.9,-18718.1], [-8330.6,-18977.1], [-8353.3,-19236.1], [-8375.9,-19495.1], [-8398.6,-19754.1], [-8421.2,-20013.1], [-8443.9,-20272.2]],
  PROFILE: [[0, 0], [2400, -13], [4500, -33], [6000, -30], [8000, -10], [9900, 12], [12000, -8], [14500, -48], [16000, -50], [17800, -60], [19000, -48], [20000, -46], [21700, -56], [22400, -58]],
  // land relative to the road: cuttings through the ridge, embankments over the valleys
  LAND: [[0, -1.5], [3600, -1.5], [4200, -4.5], [5000, -4.5], [5600, -1], [8200, -1], [8800, 6], [10700, 6], [11300, -1], [13200, -1], [13800, -3.5], [15000, -3.5], [15600, -1], [17000, -1], [17300, -6.5], [18300, -6.5], [18700, -1], [22400, -1.5]],
  interp(table, s) {
    if (s <= table[0][0]) return table[0][1];
    for (let i = 0; i < table.length - 1; i += 1) {
      const [s0, h0] = table[i], [s1, h1] = table[i + 1];
      if (s <= s1) { const t = (s - s0) / (s1 - s0); return h0 + (h1 - h0) * (t * t * (3 - 2 * t)); }
    }
    return table[table.length - 1][1];
  },
  zToS(z) {
    // the control points are 250 m apart along the road; z falls monotonically
    const C = M1.CONTROL, step = 22400 / (C.length - 1);
    if (z >= 0) return z * -1;
    for (let i = 0; i < C.length - 1; i += 1) {
      if (z <= C[i][1] && z >= C[i + 1][1]) { const t = (C[i][1] - z) / (C[i][1] - C[i + 1][1]); return (i + t) * step; }
    }
    return 22400 + (C[C.length - 1][1] - z);
  },
  plan() { return ROUTES.motorway.m1; },
  gantries() { return ROUTES.motorway.m1.gantries; },
  limitAt(s) { return ROUTES.motorway.limitAt(s); }
};

const ROUTES = {
  /* ------------------------------------------------------------ Dales B road */
  dales: {
    id: 'dales',
    name: 'Dales B-road',
    kicker: 'DETAILED SCENERY · DALES-INSPIRED B-ROAD',
    roadHint: 'slow for bends and village humps; R resets you in your lane, P pauses the ride',
    blurb: 'Wooded lanes, patterned fields and the stone-built village of Dalebeck. Road humps, rumble strips and a changing surface beneath the tyres. Keep left; side turnings are coned off.',
    scale: 1.25,
    laneHalf: 3.0,          // 6.0 m carriageway
    roadHalf: 3.1,
    hedgeOffset: 5.2,
    limit: 60,
    bendThreshold: 1 / 130,
    elevationWeight: 0.55,
    terrainWeight: 0.85,
    // harmonics of the loop length: [k, amplitude m, phase]
    elevation: [[2, 4.2, 0.4], [3, 2.6, 2.1], [7, 1.4, 1.0], [11, 0.8, 2.8]],
    terrain: (x, z) => EVO.fbm(x / 420 + 3.1, z / 420 - 1.7, 3) * 26 - 13 +
      (EVO.fbm(x / 95 - 2.2, z / 95 + 4.4, 3) - 0.5) * 5.5,
    spawns: [[180, 'Woodland approach'], [339, 'Village & humps'], [800, 'Open pasture'], [1030, 'Wooded bends']],
    control: [
      [0, 0], [110, -18], [230, -6], [330, 48], [392, 150], [388, 262],
      [318, 332], [232, 348], [150, 402], [52, 428], [-58, 402], [-140, 330],
      [-160, 226], [-232, 148], [-282, 52], [-236, -46], [-130, -72], [-52, -36]
    ],
    junctions: [
      { s: 700, side: -1, angle: 92, name: 'HAWES 4' },
      { s: 1550, side: 1, angle: 85, name: 'ASKRIGG 3' },
      { s: 2450, side: -1, angle: 98, name: 'BAINBRIDGE 2' }
    ],
    boundaries: { seed: 2024, min: 70, span: 190, mix: [['hedge', 0.5], ['wall', 0.28], ['fence', 1]] },
    // Authored places along this road. `s` is absolute metres; `t` is a fraction
    // of the loop, resolved once the geometry is built.
    places: {
      village: { name: 'DALEBECK', start: 330, end: 595, limit: 20 },
      woodland: [[95, 310], [995, 1260], [1940, 2075]],
      gates: [{ s: 236, side: 1 }, { s: 811, side: -1 }, { s: 963, side: 1 }, { s: 1390, side: -1 }, { s: 1700, side: 1 }, { s: 2128, side: -1 }],
      humps: [
        { s: 372, length: 3.8, height: 0.075, type: 'hump' },
        { s: 448, length: 7.0, height: 0.075, type: 'table' },
        { s: 530, length: 3.8, height: 0.075, type: 'hump' }
      ],
      stripAt: [301, 610],
      covers: [{ s: 357, d: 1.80 }, { s: 403, d: -1.40 }, { s: 468, d: 1.05 }, { s: 558, d: -1.65 }, { s: 873, d: 1.8 }, { s: 1890, d: -1.8 }],
      potholes: [
        { s: 214, d: 2.42, length: 1.05, width: 0.64, height: -0.030, seed: 13 },
        { s: 844, d: 2.42, length: 1.24, width: 0.68, height: -0.034, seed: 27 },
        { s: 1168, d: -2.42, length: 0.92, width: 0.60, height: -0.025, seed: 38 },
        { s: 1458, d: 1.80, length: 1.14, width: 0.70, height: -0.028, seed: 42 },
        { s: 1842, d: -2.42, length: 1.38, width: 0.72, height: -0.035, seed: 55 },
        { s: 2090, d: 2.42, length: 0.96, width: 0.62, height: -0.028, seed: 67 }
      ],
      lots: [
        { s: 361, side: -1, d: 8.65, kind: 'hatch', paint: 0xd2d1c9, dir: 1 },
        { s: 384, side: 1, d: 8.70, kind: 'suv', paint: 0x324d65, dir: -1 },
        { s: 442, side: -1, d: 8.80, kind: 'van', paint: 0xe1ded3, dir: 1 },
        { s: 504, side: 1, d: 8.65, kind: 'hatch', paint: 0x883839, dir: -1 },
        { s: 552, side: -1, d: 8.65, kind: 'hatch', paint: 0x777d7a, dir: 1 }
      ],
      grids: [], laybys: [],
      repairs: { count: 58, seed: 9031, step: 39, from: 143 }
    },
    // What the road-information panel says about where you are.
    readout(RT, s) {
      const hump = RT.nextHump(s), wood = RT.woodland(s), v = RT.inVillage(s);
      if (hump && hump.dist < 125) return { name: v ? 'DALEBECK VILLAGE' : wood ? 'WOODED B-ROAD' : 'OPEN PASTURE', note: `${hump.type === 'table' ? 'RAISED TABLE' : 'ROAD HUMP'} · ${Math.round(hump.dist)} m`, warn: true };
      if (v) return { name: 'DALEBECK VILLAGE', note: '20 mph · traffic-calmed village' };
      if (wood) return { name: 'WOODED B-ROAD', note: 'Coarse surface · shaded bends' };
      return { name: 'OPEN PASTURE', note: 'Two-way road · keep left' };
    },
    scenery: { trees: 'dense', flowers: true, blades: 1, farmsteads: true, fells: true, grass: 0xffffff, fogScale: 1, flock: 1, walls: 1 },
    traffic: { oncoming: 1, same: 1, cruise: 1, hgv: 0 }
  },

  /* --------------------------------------------------------- moorland A road */
  moor: {
    id: 'moor',
    name: 'Moorland A-road',
    kicker: 'HIGH MOOR · FAST A-ROAD',
    roadHint: 'unfenced moor, cattle grids and crosswind on the tops; R resets you in your lane, P pauses',
    blurb: 'A trans-Pennine A-road over open moor: mile-long straights, fast sweepers and a hard stop where the road drops off the edge. Unfenced in places, so watch for sheep. Lorries to pass where you can see far enough to do it.',
    scale: 1.32,
    laneHalf: 3.65,         // 7.3 m carriageway
    roadHalf: 3.78,
    hedgeOffset: 6.4,
    limit: 60,
    wind: 0.55,          // crosswind across the open tops, m/s of lateral drift
    bendThreshold: 1 / 260, // sweepers count as bends on a fast road
    // A moor road rides the contours: most of its height comes from the land
    // itself, with only a modest profile of its own on top. Give it a big
    // independent profile and it ends up on an invisible embankment.
    elevationWeight: 0.72,
    terrainWeight: 0.85,
    corridor: { inner: 18, outer: 320, reach: 400 },
    elevation: [[1, 20, 0.9], [2, 10.5, 2.4], [3, 5, 0.6], [5, 2.2, 1.9], [8, 1, 3.1]],
    // Long-wavelength relief: big swells the road can climb over without the
    // ground falling away from it within sight of the verge.
    terrain: (x, z) => EVO.fbm(x / 850 + 5.5, z / 850 + 2.2, 3) * 46 - 23 +
      (EVO.fbm(x / 420 - 1.1, z / 420 + 3.3, 3) - 0.5) * 6,
    // A big irregular ring: two long straights, fast sweepers between them and
    // one hard left where the road falls off the moor edge.
    spawns: [[0.01, 'Summit straight'], [0.2, 'Eastern flank'], [0.42, 'Off the moor edge'], [0.63, 'The clough'], [0.86, 'Western climb']],
    control: [
      // the summit straight, west to east (~950 m)
      [-772, -448], [-520, -462], [-268, -472], [-20, -470],
      // opening right-hand sweeper off the summit
      [232, -452], [452, -372], [592, -216],
      // the eastern straight, running south (~450 m)
      [648, -40], [656, 150],
      // fast entry that tightens as the road falls off the moor edge
      [634, 306], [570, 408], [462, 452],
      // S-bend through the clough
      [356, 428], [284, 348], [206, 300], [96, 300], [-16, 348],
      // fast left along the bottom
      [-148, 372], [-296, 366], [-424, 316],
      // tighter right at the beck bridge
      [-534, 232], [-576, 122],
      // long left climbing back to the summit
      [-664, 20], [-762, -118], [-800, -290]
    ],
    junctions: [
      { s: 1180, side: 1, angle: 96, name: 'GRASSHOLME 5' },
      { s: 3600, side: -1, angle: 88, name: 'HARWOOD 3' }
    ],
    // Open moor for long runs, wall where the road is enclosed, wire on the tops.
    boundaries: { seed: 5150, min: 150, span: 320, mix: [['open', 0.42], ['wall', 0.78], ['fence', 1]] },
    // Moorland furniture: cattle grids where the walls give out, laybys on the
    // straights, frost-damaged edges, and no village at all.
    places: {
      village: null,
      woodland: [],
      gates: [{ t: 0.075, side: -1 }, { t: 0.30, side: 1 }, { t: 0.52, side: -1 }, { t: 0.79, side: 1 }],
      humps: [],
      stripAt: [],
      covers: [{ t: 0.235, d: 1.9 }, { t: 0.61, d: -2.0 }],
      // cattle grids: a recessed steel deck, felt through the bars at any speed
      grids: [{ t: 0.055 }, { t: 0.335 }, { t: 0.585 }, { t: 0.86 }],
      laybys: [{ t: 0.145, side: 1, length: 42 }, { t: 0.435, side: -1, length: 36 }, { t: 0.72, side: 1, length: 44 }],
      potholes: [
        { t: 0.115, d: 2.9, length: 1.3, width: 0.72, height: -0.032, seed: 21 },
        { t: 0.28, d: -2.95, length: 1.1, width: 0.66, height: -0.028, seed: 34 },
        { t: 0.505, d: 2.85, length: 1.45, width: 0.78, height: -0.036, seed: 46 },
        { t: 0.66, d: -2.9, length: 1.2, width: 0.70, height: -0.030, seed: 58 },
        { t: 0.905, d: 2.95, length: 1.35, width: 0.74, height: -0.034, seed: 62 }
      ],
      lots: [],
      summit: { t: 0.955, name: 'HIGH GILL SUMMIT', height: 486 },
      repairs: { count: 96, seed: 4411, step: 47, from: 90 }
    },
    readout(RT, s) {
      const grid = RT.nextGrid(s), P = RT.detailPlan, L = RT.length;
      const near = (a, b) => ((a - b) % L + L) % L;
      if (grid && grid.dist < 140) return { name: 'HIGH MOOR', note: `CATTLE GRID · ${Math.round(grid.dist)} m`, warn: true };
      if (P.summit && near(P.summit.s, s) < 260) return { name: P.summit.name, note: `Summit · ${P.summit.height} ft · exposed to crosswind` };
      const layby = P.laybys.find((b) => near(b.s, s) < 200);
      if (layby) return { name: 'HIGH MOOR', note: `Layby · ${Math.round(near(layby.s, s))} m` };
      const open = RT.boundaryAt(s, 1).type === 'open' || RT.boundaryAt(s, -1).type === 'open';
      if (open) return { name: 'OPEN MOOR', note: 'Unfenced · sheep on the road', warn: true };
      return { name: 'MOOR ROAD', note: 'Two-way road · keep left' };
    },
    scenery: { trees: 'sparse', flowers: false, blades: 0.9, farmsteads: false, fells: true, heather: true, grass: 0xa89a66, blade: 0x8f9469, fogScale: 0.62, flock: 3.4, walls: 6, cloughTrees: true },
    traffic: { oncoming: 1.15, same: 1, cruise: 1.45, hgv: 0.34 }
  },

  /* ------------------------------------------------- M1 smart motorway */
  // Northbound from Toddington services to Newport Pagnell services: the
  // four-lane all-lane-running section through junctions 12, 13 and 14. An
  // open road, so the run has a start and a finish rather than laps.
  motorway: {
    id: 'motorway',
    name: 'M1 · Toddington to Newport Pagnell',
    kicker: 'SMART MOTORWAY · M1 NORTHBOUND J12–J14',
    roadHint: 'keep left unless overtaking, obey the gantry limits and the 50 through the road works, no undertaking; R resets you in lane 1, P pauses',
    scoreHint: 'pace within the limit; passing on the right chains a multiplier (+80); undertaking, hogging an outer lane, tailgating, breaking a gantry limit and crashes cost; pull in at Newport Pagnell to complete the run (+300). Your best run is kept',
    trafficLabels: ['Full motorway traffic', 'Southbound only', 'Empty motorway'],
    blurb: 'Out of Toddington services onto the four-lane M1: through the Flitwick gap, up Brogborough Hill, past the Marston Gate sheds at J13, through a mile of contraflow-free road works with lane 1 coned off, and across the Ouzel to J14, pulling in at Newport Pagnell. Variable limits on the gantries, lorries in the inside lanes and traffic that moves out to pass you. Fourteen miles, one run.',
    open: true,
    dual: { lanes: 4, laneW: 3.65, strip: 1.0, reserve: 3.6 },
    scale: 1,
    laneHalf: 7.3,          // four 3.65 m lanes
    roadHalf: 8.3,          // plus the 1 m hard strip
    hedgeOffset: 14.5,      // boundary fence from the centre of our carriageway
    limit: 70,
    bendThreshold: 1 / 1400, // a motorway curve is a sweep, not a bend
    elevationWeight: 1,
    terrainWeight: 0,
    corridor: { inner: 2.5, outer: 24, reach: 60 },
    // The long profile in metres relative to Toddington services (about 118 m
    // AOD): down through the Flit gap, up Brogborough Hill to a crest in the
    // Greensand cutting, down into the Marston Vale and across the Ouzel.
    profile: (s) => M1.interp(M1.PROFILE, s),
    elevation: [],
    // The land beside a motorway is described relative to the carriageway, so
    // undo the route layer's own terrain scaling (it applies *0.85 + 0.6) and
    // hand back exactly the height the tables ask for.
    terrain: (x, z) => {
      const s = M1.zToS(z);
      const want = M1.interp(M1.PROFILE, s) + M1.interp(M1.LAND, s) +
        (EVO.fbm(x / 900 + 2.2, z / 900 - 1.1, 3) - 0.5) * 9 + (EVO.fbm(x / 240 - 4.4, z / 240 + 3.3, 3) - 0.5) * 2.6;
      return (want - 0.6) / 0.85;
    },
    spawns: [[440, 'Toddington services', 10.55], [1400, 'Approaching J12'], [7200, 'Brogborough Hill'], [10600, 'Approaching J13'], [15600, 'Salford straight'], [18900, 'Approaching J14']],
    control: M1.CONTROL,
    junctions: [],
    boundaries: { seed: 7001, min: 900, span: 1200, mix: [['fence', 1]] },
    places: { village: null, woodland: [], gates: [], humps: [], stripAt: [], covers: [], potholes: [], grids: [], laybys: [], lots: [], repairs: { count: 0, seed: 1, step: 100, from: 0 } },
    /* Motorway plan: everything is placed by distance from Toddington services. */
    m1: {
      kmAtStart: 63.0, // driver location signs: M1 carriageway A, km from the M25 end
      exits: [
        { s: 2400, number: 12, road: 'A5120', places: ['Flitwick', 'Toddington', 'Harlington'], after: [['Milton Keynes', 11], ['Northampton', 32], ['The NORTH', null]] },
        { s: 12000, number: 13, road: 'A421', places: ['Milton Keynes (S)', 'Bedford', 'Ampthill'], extraRoad: 'A507', after: [['Northampton', 27], ['Leicester', 58], ['The NORTH', null]] },
        { s: 20000, number: 14, road: 'A509', places: ['Milton Keynes', 'Newport Pagnell', 'Olney'], after: [['Northampton', 22], ['Leicester', 52], ['The NORTH', null]] }
      ],
      services: {
        start: { name: 'Toddington', operator: 'moto', merge: [0, 380] },
        end: { name: 'Newport Pagnell', operator: 'Welcome Break', diverge: 650, nose: 300 },
        next: [['Newport Pagnell', 14]]
      },
      // Portal gantries with a signal over each lane. '' is blank, a number is a
      // variable limit, 'END' clears it (national speed limit applies).
      // '' is a blank signal, a number is a variable limit, 'END' clears it, and
      // `x` lists the lanes showing a red X (a closed lane).
      gantries: [
        { s: 900, msg: '' }, { s: 2000, msg: 'DONT DRIVE TIRED' }, { s: 3300, msg: '' }, { s: 4600, msg: 'KEEP APART 2 CHEVRONS' },
        { s: 6000, msg: '' }, { s: 7400, msg: '' }, { s: 8800, msg: 'CHECK YOUR FUEL LEVEL' },
        { s: 10400, limit: 60, msg: 'CONGESTION AFTER J13' }, { s: 11400, limit: 50, msg: 'SLOW TRAFFIC AHEAD' },
        { s: 12500, limit: 'END', msg: '' }, { s: 13000, msg: 'ROAD WORKS 2 MILES' },
        { s: 13700, limit: 60, msg: 'ROAD WORKS 1 MILE' }, { s: 14600, limit: 50, msg: 'LANE 1 CLOSED 1000 YDS' },
        { s: 15140, limit: 50, x: [0], msg: 'LANE 1 CLOSED MERGE RIGHT' },
        { s: 16000, limit: 50, x: [0], msg: 'AVERAGE SPEED CHECK' },
        { s: 16800, limit: 50, x: [0], msg: '' },
        { s: 17250, limit: 'END', msg: 'END OF ROAD WORKS' },
        { s: 18200, msg: '' }, { s: 19500, msg: 'STAY IN LANE FOR J14' }, { s: 20700, msg: '' }
      ],
      /* The lane 1 closure: a long-term smart-motorway scheme with a cone
       * taper into a barrier-separated works, a 50 limit under average speed
       * cameras, and a site compound on the verge. `lane` is the lane index
       * counting from the nearside, so 0 closes lane 1. */
      roadworks: {
        start: 15250, end: 17050, lane: 0, limit: 50,
        taperIn: 150, taperOut: 100, coneRun: 220, compound: 16150,
        advance: [13650, 14450], camera: [15200, 15950, 16700]
      },
      // overbridges other than the junction roads: minor roads, farm tracks, a footbridge
      bridges: [
        { s: 1100, kind: 'road' }, { s: 3250, kind: 'road' }, { s: 4700, kind: 'road' }, { s: 6350, kind: 'road' }, { s: 7800, kind: 'foot' },
        { s: 9200, kind: 'road' }, { s: 10650, kind: 'road' }, { s: 13400, kind: 'road' }, { s: 14900, kind: 'road' }, { s: 16350, kind: 'road' },
        { s: 17700, kind: 'road' }, { s: 18900, kind: 'foot' }, { s: 21000, kind: 'road' }
      ],
      refuges: [1600, 3900, 6100, 8300, 10100, 12900, 14550, 17550, 19100],
      pylons: [{ s: 5200, angle: 62 }, { s: 11200, angle: 84 }, { s: 15800, angle: 105 }, { s: 20200, angle: 70 }],
      woods: [[5500, 6200, -1], [7500, 10700, 1], [7900, 10300, -1], [16800, 17300, 1]],
      sheds: [{ s: 12300, side: -1 }, { s: 19000, side: -1 }],
      // farmsteads out in the fields, balancing ponds in the motorway's own
      // drainage land, and the Milton Keynes skyline off to the west near J14
      farms: [{ s: 3400, side: 1 }, { s: 6900, side: -1 }, { s: 9600, side: 1 }, { s: 14100, side: -1 }, { s: 17900, side: 1 }, { s: 21100, side: -1 }],
      ponds: [{ s: 2700, side: 1 }, { s: 8100, side: -1 }, { s: 13600, side: 1 }, { s: 18600, side: -1 }],
      pasture: [[1200, 2600, 1], [5400, 6600, -1], [9000, 10200, 1], [13200, 14200, -1], [17800, 18900, 1]],
      skyline: { s: 20600, side: -1, distance: 2400, spread: 1800 },
      noise: [[1450, 2050, 1], [18400, 19100, 1]],
      lit: [[1700, 3100], [11300, 12700], [15100, 17200], [19300, 20700]]
    },
    limitAt(s) {
      let lim = 70;
      for (const g of M1.gantries()) { if (s >= g.s && g.limit) lim = g.limit === 'END' ? 70 : g.limit; }
      // the works carry their own limit whatever the last gantry said
      const w = ROUTES.motorway.m1.roadworks;
      if (s >= w.start - w.taperIn - 300 && s <= w.end + 120) lim = Math.min(lim, w.limit);
      return lim;
    },
    readout(RT, s) {
      const M = M1.plan(), L = RT.length;
      const lim = M1.limitAt(s);
      const nextExit = M.exits.find((e) => e.s - s > -200);
      const toServices = L - M.services.end.nose - s;
      const w = M.roadworks;
      if (s < 700) return { name: 'TODDINGTON SERVICES', note: 'Merge · give way to traffic on the M1', warn: true };
      if (s >= w.start - w.taperIn && s <= w.end + 60) return { name: 'M1 ROAD WORKS', note: `Lane 1 closed · ${w.limit} average speed check`, warn: true };
      if (s >= w.start - 1800 && s < w.start - w.taperIn) return { name: 'M1 NORTHBOUND', note: `Road works · lane 1 closed in ${Math.max(0, Math.round((w.start - s) / 100) / 10)} km`, warn: true };
      if (toServices < 1650 && toServices > -50) return { name: 'M1 NORTHBOUND', note: `Newport Pagnell services · ${Math.max(0, Math.round(toServices / 100) / 10)} km · keep left`, warn: toServices < 500 };
      if (lim !== 70) return { name: 'M1 NORTHBOUND', note: `Variable limit ${lim} · smart motorway`, warn: true };
      if (nextExit && nextExit.s - s < 1700 && nextExit.s - s > -200) return { name: 'M1 NORTHBOUND', note: `Junction ${nextExit.number} · ${nextExit.road} · ${Math.max(0, Math.round((nextExit.s - s) / 100) / 10)} km` };
      const km = (M.kmAtStart + s / 1000).toFixed(1);
      return { name: 'M1 NORTHBOUND', note: `Four lanes · no hard shoulder · marker ${km}` };
    },
    scenery: { motorway: true, trees: 'belts', flowers: false, blades: 0.35, farmsteads: false, fells: false, grass: 0xf2f0e6, fogScale: 0.8, flock: 0, walls: 0 },
    traffic: { motorway: true, oncoming: 0, same: 1, cruise: 1, hgv: 0.32, count: 42, countCoarse: 24, sbCount: 28, sbCountCoarse: 16 }
  }
};

function pickRoute() {
  const q = new URLSearchParams(location.search).get('route');
  if (q && ROUTES[q]) return q;
  try { const saved = localStorage.getItem('evo.route'); if (saved && ROUTES[saved]) return saved; } catch (e) { /* private mode */ }
  return 'dales';
}

EVO.ROUTES = ROUTES;
EVO.activeRoute = pickRoute();
EVO.ROUTE = ROUTES[EVO.activeRoute];
