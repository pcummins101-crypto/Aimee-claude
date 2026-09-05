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
    scenery: { trees: 'dense', flowers: true, blades: 1, farmsteads: true, fells: true, grass: 0xffffff, fogScale: 1 },
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
    elevationWeight: 0.8,
    terrainWeight: 0.7,
    elevation: [[1, 26, 0.9], [2, 13, 2.4], [3, 6.5, 0.6], [5, 2.8, 1.9], [8, 1.3, 3.1]],
    terrain: (x, z) => EVO.fbm(x / 640 + 5.5, z / 640 + 2.2, 3) * 46 - 23 +
      (EVO.fbm(x / 175 - 1.1, z / 175 + 3.3, 3) - 0.5) * 9,
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
    scenery: { trees: 'sparse', flowers: false, blades: 0.55, farmsteads: false, fells: true, heather: true, grass: 0xbdb389, blade: 0x9aa07e, fogScale: 0.62 },
    traffic: { oncoming: 1.15, same: 1, cruise: 1.45, hgv: 0.34 }
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
