# Avenrà EVO · B-Road

A first-person WebGL simulation of riding an Avenrà EVO around quiet British roads, with light traffic in both directions and a score to beat. Realism is the priority: real lighting and cast shadows, physically-based road and verge materials, UK road language, and live rear-view mirrors behind the EVO rider cockpit photograph.

## Routes

**ROUTE** on the start screen picks the road. Everything that makes one road different from another — geometry, carriageway width, elevation character, terrain, boundary mix, junctions, authored places, traffic mix and scenery rules — is a record in `src/05-routes.js`, so adding a road means adding a record rather than editing builders. The world is built once, so changing route reloads the page; the choice and the best lap are remembered per road.

| | Dales B-road | Moorland A-road | M1 motorway |
| --- | --- | --- | --- |
| Length | 2.4 km loop | 5.4 km loop | 22 km, Toddington services to Newport Pagnell services |
| Carriageway | 6.0 m two-way | 7.3 m two-way | two carriageways of four 3.65 m lanes, no hard shoulder |
| Bends | 10, tightest 54 m | 10, tightest 70 m | sweeps only |
| Longest straight | 35 m | 932 m | 3 km |
| Climb | 11 m | 70 m, to a 486 ft summit | 70 m of fall and rise: the Flit gap, Brogborough Hill, the Marston Vale, the Ouzel |
| Character | hedgerows, walls, woods, the village of Dalebeck with humps and a 20 limit | open moor, dry-stone walls, long unfenced runs, cattle grids, laybys, a snow gate | smart motorway: gantries with variable limits, refuge areas, three roundabout interchanges, two service areas, and a mile of road works with lane 1 coned off |
| Ground | pasture, verge flowers, dense hedgerow trees | heather, rush, bracken, gorse and gritstone over peat, with trees only in the sheltered gills | standing crops in an arable patchwork, rough verge grass, planted tree belts, farmsteads, balancing ponds, livestock, pylon lines and distribution sheds |
| Traffic | cars and vans | cars, vans and 16 m articulated lorries at 50 mph | forty-two vehicles northbound — cars, vans, coaches and lorries with UK lane discipline — and twenty-eight southbound |

The moor carries a deep layer of ground cover: heather in flower where the fbm says it should be, rush tussocks in the wetter hollows, bracken on the sheltered slopes, gorse and loose gritstone, all instanced and distance-culled so the count runs to tens of thousands. Above it sit grouse butts stepping up the hill, sheepfolds, isolated field barns, becks running under stone parapets, and a network of enclosure-era field walls in parallel families with cross walls between them, which is what makes upland England read as a patchwork rather than a set of random lines. Trees appear only where the ground falls away into a gill, because that is the only place they survive.

The **moorland A-road** is the fast one. Two long straights let the EVO reach its 109 mph terminal speed, and the tightest bend is a 44 mph corner at the end of the longest of them, so it needs 137 m of braking from flat out. The corner planner's horizon is not fixed: it looks as far ahead as the bike could need to brake from its current speed plus about three seconds of reaction, because a horizon that suits a B road is far too short on a road where you cover it in under a second. Long unfenced stretches mean sheep graze up to the verge and a crosswind pushes the bike across its lane on the exposed tops. Overtaking is the main event: a lorry takes real commitment to pass, and the sightlines are long enough that the decision is about closing speed rather than blind faith.

### The M1

The **motorway** is one run rather than a lap: out of Toddington services onto the four-lane M1 northbound, past J12 (A5120 Flitwick and Toddington), up Brogborough Hill through the Greensand cutting to J13 (A421 Milton Keynes and Bedford, with the Marston Gate sheds beside it), down across the Marston Vale and the Ouzel to J14 (A509 Milton Keynes and Newport Pagnell), and off at Newport Pagnell services. Every junction is a roundabout interchange carried over the motorway on two decks, with diverge and merge tapers, hatched noses and the slips climbing to it; the junction is signed at a mile, half a mile and the nose, with countdown markers and a confirmatory sign after it. The road is a smart motorway: lane lines and red, amber, white and green studs; a concrete step barrier down the reserve and steel barrier along the verge; portal gantries every mile or so with a signal over each lane and a message sign (a 60 and then a 50 come up on the approach to J13, and the score judges you against them); driver location signs every 500 m; emergency refuge areas with SOS cabinets; lighting through the junctions; overbridges and a footbridge in between. Both service areas are built, with car parks (and parked cars), fuel canopies, amenity buildings, lorry parks and totems.

**The road works.** A mile and an eighth between J13 and J14 is a long-term smart-motorway scheme with lane 1 closed. It is signed the way a real one is: black-on-yellow road works warnings at a mile and half a mile, the limit stepping 70 → 60 → 50, a lane-closure diagram, and average speed check signs under cameras on their own poles. The gantry at the taper shows a red X over lane 1 and 50 over the other three, and the closed lane is the standard layout — a cone taper down to the lane line, a run of cones, then steel barrier for the length of the works and cones again at the far end, with an impact protection vehicle carrying chevrons and an arrow board at the head of the taper. Behind it the closed lane is planed back to a ridged black base with excavators, rollers, tippers, dumpers, stacked cones, barrier sections and aggregate standing on it, and a fenced compound of cabins, welfare units, spoil heaps and lighting towers is levelled into the verge. Traffic merges out of lane 1 well before the taper — holding back for a gap if it has to — and everything slows to the 50, so the works bunch the flow up the way they do on a Friday afternoon. Stray into the coned lane yourself and you hit the barrier.

The road is open-ended, so the route system runs it as a start-to-finish alignment with an authored long profile, and the world is built in 400 m chunks that switch on by distance, which is why 22 km costs no more per frame than the 2.4 km loop. The fields either side carry tens of thousands of instanced crop cards tinted to match the crop the ground beneath them is carrying, so a wheat field grows wheat and a rape field grows rape, with rough grass over the verges, planted belts of oak, ash, birch and hawthorn thickening and thinning along the corridor, farmsteads with barns and grain silos, balancing ponds in the motorway's drainage land, sheep and cattle in the pasture, marker posts every hundred metres and the Milton Keynes skyline across the fields at J14. Traffic is recycled around the rider: lorries sit in the inside lanes on the limiter, cars run a little over the limit in the outer lanes, everything moves out to pass what is slower and moves back when the inside lane is clear, and whatever you are sitting behind or holding up reacts to you. Scoring is by the Highway Code rather than the apex: pace within the limit, passes on the right chain a multiplier, and undertaking, hogging an outer lane with the inside lane clear, tailgating, breaking a gantry limit and leaving the carriageway all cost. Pulling in at Newport Pagnell completes the run and pays a bonus; the best run is kept.

## What is in the world

- **Route** – a closed loop sampled every metre into a Frenet frame, with rolling elevation (max 7 % gradient), ten bends between 54 m and 116 m radius, and three T-junctions.
- **Road** – 6 m carriageway with a 2.5 % crown, procedural asphalt (albedo, normal and roughness maps) with polished wheel tracks and a separate 40 m wear map of patches, cracks and tar snakes so the surface never visibly repeats.
- **Markings** – 4 m / 8 m centre line, 6 m / 3 m hazard warning lines into bends and junctions, double white lines through the blind bends, edge lines on hazard stretches broken across junction mouths, white cat's eyes, SLOW legends before tighter bends, and give-way lines and triangles on every side road.
- **Junctions** – each side road is flared at the mouth, marked, signed (junction warning triangles on the main road, Give Way facing the side road, a fingerpost name plate) and closed with seven cones and a Road Closed board on a barrier frame.
- **Boundaries** – hedgerows (normal-mapped hawthorn body with three rows of leaf-cluster cards, brambles at the foot and cow parsley in the verge), random-coursed dry-stone walls with normal maps, and post-and-wire fences; bends carry warning triangles and chevron boards; a national speed limit sign opens the run; telegraph poles with sagging wires run along one side.
- **Landscape** – grass verges with a ditch and bank, tens of thousands of wind-swayed grass blades, far pasture with hay meadows, and around 400 volumetric trees (oak, ash and hawthorn built from a trunk, branches and leaf-cluster cards with canopy-sphere normals) along the road, in the hedge lines, scattered through the fields and in copses, plus field-boundary hedgerow lines.
- **Landscape, continued** – a ring of distant fells beyond the pasture read through the haze, flocks of sheep in the fields, dry-stone field walls running across the hillsides, and slow cloud shadows drifting over every ground surface.
- **Sky and light** – a custom sky shader (Rayleigh-style gradient, sun disc and glow, drifting fbm clouds), a warm low sun with a 4k/2k PCF shadow map focused ahead of the rider, a strong sky-light hemisphere so shaded walls and hedges read like a bright British day, transmitted-light fill on foliage, and exponential haze.
- **Photographic pipeline** – the scene renders in linear HDR into a multisampled half-float target; a quarter-resolution bloom lifts the sun, sky and headlamps, then one composite pass applies speed-scaled edge blur, slight chromatic aberration, ACES tone mapping, a colour grade, vignette and film grain. The road carries a sky reflection from the same environment map the cars use, and tree canopies sway in the wind. If the device cannot render to float targets the frame is drawn directly with the renderer's own ACES curve (`?nopost=1` forces this).

## Light

**LIGHT** on the start screen picks the day. Every value is a live light property, sky uniform or material setting, so the look changes between rides without rebuilding the world; the environment map that the cars, windows, puddles and tarmac reflect is regenerated from the new sky.

- **Bright afternoon** – high sun, blue sky with shaded cumulus and a cirrus veil, crisp shadows.
- **Misty morning** – low sun ahead, thin haze, a wet sheen on the road, beams down the lane.
- **Golden evening** – long warm shadows from a low sun behind you, orange horizon, mauve sky.
- **Overcast, wet road** – soft light from a grey sky, very soft shadows, the tarmac mirroring the sky.
- **Rain** – a dark sky, heavy haze, standing water on the road, rain streaking past (the streaks lean into you as you accelerate, since they are the drops' motion relative to the rider), droplets creeping down the visor and refracting the view, rain hiss and tyre spray in the audio, and a quarter less grip: the safe and maximum corner speeds, the corner bar and the planner all drop accordingly.

Effects that ride on top of the scene, all in the single post-process pass: HDR bloom (thresholded so only the sun disc and headlamps bloom), drifting cloud shadows, a gentle film-style grade (contrast, saturation, warm highlights, cool lifted shadows), vignette and grain. Shaded surfaces are handled in the geometry rather than the shader: the verge darkens where it meets a hedge or wall, hedges sit on a dark foot, and the lowest courses of every wall stay damp and mossy while the coping catches the light.

## Scenery

On top of the Detailed Scenery Edition's village and road detail: **beech and silver birch** join the oak, ash and hawthorn (birch with papery white bark, dark lenticels and branch scars), **foxgloves** stand along the verges through the woods, the deeper potholes hold **standing water** that mirrors the sky, a red **K6 telephone box** stands by the bus shelter, and three **farmsteads** with barns and yard walls sit out in the fields the way the Dales are actually dotted with them.

## Traffic

**TRAFFIC** on the start screen: both directions, oncoming only, or an empty road. Oncoming cars circulate in the other lane as before. Same-direction traffic runs in your lane, slower than the EVO wants to go (a dawdling van among them), slowing for bends, the village limit, humps and junctions, and following whatever is ahead — including you: catch one up and it holds a gap behind you, brake lights on, rather than driving through you. Run into the back of one and you crash. Passing it means committing to the oncoming lane, and the score judges the moment you chose.

## Score

A lap is the unit of play; the best lap is kept on the device and shown in the HUD with the current lap's score, time and multiplier.

- **Pace** – points per second scale with speed while the corner bar is green; amber pays less, red nothing.
- **Bends** – exit a bend having carried between 72 % and 100 % of its safe speed for a **clean bend**, which lengthens a chain; each clean bend or overtake in the chain raises the multiplier by a quarter, to ×3. Running wide breaks it and pays a token; a cautious bend pays a little and leaves the chain alone.
- **Overtakes** – a **clean overtake** is +150 × multiplier. Passing with oncoming traffic closer than about three seconds, on or into a blind bend, at a junction or in the village is an **unsafe overtake** at −200 and breaks the chain. Squeezing past too close pays a fraction; a close call with an oncoming car costs.
- **The village** – over the 20 limit costs while it lasts; a hump or raised table taken gently pays, hit hard costs.
- **The verge and crashes** – time on the verge costs; a crash costs 250 and resets the chain.

## Riding

The powertrain is fitted to the EVO's figures: 0–60 mph in 3.9 s and a 109 mph terminal speed. Every metre of road carries a **safe** cornering speed (0.57 g) and a **maximum** (0.88 g) derived from its radius; the corner bar at the top of the screen plans ahead for braking and turns green, amber or red. Between safe and maximum the bike drifts towards the outside of the bend and needs holding; above the maximum it runs off into the verge and the hedge. Oncoming traffic (six cars on a phone, seven on desktop: hatchbacks, SUVs and vans lofted from smooth cross-sections, with clearcoat paint and glass that reflect an environment map generated from the sky) circulates the loop in the other lane, keeping its distance, slowing for bends and junctions; cross the centre line into its path and you will collide.

- **Steer**: drag the upper half of the screen, tilt the phone (Enable Tilt Steering on the start screen), or ← → / A D.
- **Throttle**: hold the bottom-right of the screen, or ↑ / W.
- **Brake**: hold the bottom-left of the screen, or ↓ / S / Space.

Lean follows the corner physics (`atan(v²κ/g)`) plus a steering lean; the eye point sits 1.28 m up, moves to the inside of the corner and dips under braking. Leaving the carriageway rumbles and scrubs speed. The dash is live, and both mirrors show a real rear render.

## Running

Open `index.html` from any static server (it uses an import map pointing at the vendored Three.js in `vendor/`). Add `?stats=1` for a frame-rate / draw-call readout.

```
python3 -m http.server 8080
# then http://localhost:8080/index.html
```

## Building the single-file version

```
node build.mjs
```

writes `dist/index.html` (Three.js embedded, all modules inlined, cockpit photograph embedded, works offline) and `dist/artifact.html` (the same page without the document wrapper, for hosting as a claude.ai artifact).

## Layout

| File | Role |
| --- | --- |
| `src/05-routes.js` | the route registry: geometry, widths, terrain, boundaries, places, traffic and scenery per road |
| `src/00-core.js` | maths, deterministic RNG, noise, every procedural texture and sign face |
| `src/10-route.js` | loop geometry, elevation, terrain, junction plan, boundary plan, bend detection |
| `src/15-road-detail.js` | the selected route's places: village, gates, humps, cattle grids, laybys, potholes, repairs, surface height and roughness |
| `src/20-world.js` | mesh builders for road, verges, pasture, boundaries, junctions, markings, signs, sky, sun; lighting presets |
| `src/25-vegetation.js` | volumetric tree geometry (five species, two variants each) and instancing with per-tree tint |
| `src/29-motorway.js` | the M1: chunked dual carriageway, markings and studs, slips and roundabout interchanges, gantries, signs, refuges, services, fields, hedges, pylons and sheds |
| `src/27-realism-world.js` | cottages, village furniture, farm gates, barns, parked cars, puddles, phone box, farmsteads, cattle grids, laybys, summit board and snow gate, batching and distance culling |
| `src/28-rain.js` | rain streaks, computed entirely in the vertex shader around the rider |
| `src/30-bike.js` | rider dynamics, camera rig, touch / keyboard / tilt input |
| `src/35-score.js` | scoring: pace, bends and the chain multiplier, overtake judgement, village rules, laps and the saved best |
| `src/40-overlay.js` | cockpit compositing, mirrors, dash, procedural audio |
| `src/45-post.js` | HDR post-processing: bloom, visor droplets, tone mapping, grade, vignette, grain |
| `src/50-traffic.js` | traffic in both directions: lofted car bodies and panelled artics, PBR materials with sky reflections, bend-aware speed, following and brake lights, pass-by, overtake and collision events |
| `src/90-main.js` | renderer setup, ride options, quality detection, corner-bar HUD, road info, pause, frame loop |

Everything except the cockpit photograph (`assets/cockpit.png`) is generated at runtime from a fixed seed, so the road is identical on every device.
