# Avenrà Hyperlane for WordPress

This plugin packages the complete Avenrà Hyperlane browser game as a portable WordPress installation. The game is a compiled static React application served from the plugin directory; WordPress supplies the landing page and does not need Node.js at runtime.

## Install

1. Upload `avenra-hyperlane-3.3.14.zip` from **Plugins → Add New Plugin → Upload Plugin**.
2. Activate **Avenrà Hyperlane**.
3. Open the automatically created **Avenrà Hyperlane: The Game** page.
4. Add that page to the required WordPress menu if desired.

The activation routine is idempotent. It recovers its own managed page when possible and never overwrites an unrelated page using the same slug.

## Included experience

- Three complete 90-second EVO routes: City (Intermediate), Rural (Hardest) and M1 Motorway (Easiest), all using the next-generation visual and traffic systems.
- A **Living Roads** director built from deterministic 80–120 m entrance, interchangeable middle and exit chapters, with four to six chapters streamed around the rider, concealed transitions, non-overlapping route-authentic situations and fair advance warnings.
- A **UK Traffic Brain** with keep-left/overtake-right lane advice and a cruise → follow → brake → signal → reserve gap → change lane → settle state flow. Traffic responds to the motorcycle, surrounding vehicles, road layout and conditions, while the established route director retains authority over physical lane movement.
- A five-part **Rider Rating** and post-ride debrief covering Pace, Precision, Awareness, Smoothness and Discipline.
- A **Weekly Works Run** with the same route, weather, traffic seed and Road Dynamics profile for every rider.
- **Rider Dynamics 2.0**, adding an optional deeper handling model while retaining the accessible original experience.
- Game-first **Avenrà Hyperlane: The Game** landing page combining the main website's white, slate and sky-blue brand system with premium motorsport HUD details.
- Separate PWA-install and new-window play routes; the game is not loaded inside the landing page.
- Cache-proof 16:9 game, Ride Setup and HALO screenshots.
- Three-step pre-ride builder for Route, Conditions and a final Ready review, with the Start button gated to the completed setup.
- A bounded **Preparing Your EVO** stage that draws the selected route, waits for stable visual readiness and calibrates the chosen graphics before starting a true three-second countdown.
- A new full-screen **EVO Rider** route home with clipped cinematic M1, A66 and A40 cards, UK route shields, route-specific personal bests and accessible keyboard navigation. It decorates the existing setup controls rather than duplicating their actions.
- Three ride modes with a 132 mph HyperCore Boost override.
- Four road conditions: Dry Day, Raining Day, Dry Night and Raining Night.
- One performance-refined Ultra graphics profile on every route, with an internal resolution that self-tunes to the device.
- Native-aspect photographic calibration for each route, including stored horizon, vanishing point, road-corner alignment and safe crop limits, plus separate phone, tablet and desktop portrait/landscape framing. Peripheral crop and overscan preserve road geometry; images are never stretched to fill the viewport.
- The original two-way City course through the premium Avenrà District, HALO Tunnel and Racing Expressway, now with correct kerbs, gutters, drains, double-yellow lines and active crossing, loading and bus-stop scenes.
- Continuous perspective-scaled City frontage on both sides of the road, with recycled brick, civic-green, industrial and outer-city scenery arranged deterministically around the live route while the tunnel retains its dedicated interior.
- A shared 1.28 m sports-bike rider eye point across City, Rural and Motorway in every graphics tier for a lower, more convincing first-person view.
- A Living Roads Yorkshire Dales run with distinct Ribblehead, Hawes and Buttertubs Pass geometry, terrain, verges, vegetation and natural transitions, substantially heavier oncoming traffic and two short signed 2+1 overtaking sections.
- Continuous perspective-scaled Rural scenery on both verges: low Pennine dry-stone boundaries around Ribblehead, lusher Wensleydale hedges and restrained moorland banks at Buttertubs, with widening-aware clearance through every overtaking section.
- Rural horizon composition rebalanced for the lower rider view, with crest occlusion sharing the renderer's camera geometry.
- Authentic Rural markings and surface storytelling including changing centre lines, SLOW legends, a cattle grid, farm mud, drainage and height-aware crest visibility.
- Expanded HALO Tunnel safety hardware and operational tunnel and Expressway message signs interleaved with Avenrà campaign boards.
- A compressed M1 Northbound Motorway run from Luton to Leeds with authentic motorway-blue junction, gantry and services signs, four famous service-area set pieces and the original 50, 65 and 80 mph lane bands.
- Motorway roadworks with advance HALO warning, a lane-three closure, merging AI traffic, temporary studs, a cone taper, red-X signal and works vehicle.
- A player-only hard shoulder that offers an emergency escape but deducts 650 points only when used to complete an overtake.
- UK-style cat's eyes in every Motorway graphics tier, plus a continuous perspective-scaled photographic verge of grass, fencing, hedges and trees that develops from Luton screening through Midlands woodland to Yorkshire hedgerows.
- Motorway buildings, services and other recognisable landmarks remain sparse, persistent interruptions behind the vegetation instead of replacing the roadside with alternating empty space and sudden structures.
- Persistent Toddington, Watford Gap, Woodall and Woolley Edge service areas with distinct photoreal architecture, condition-aware lighting, slip roads, lit forecourts, fuel and Avenrà Current EV details.
- Authored Ultra scenery for Rural and Motorway, plus high-detail front/rear vehicle artwork for every specialist road user.
- Fold-safe Ultra rendering that preserves the complete graphics tier while tuning only the internal world-canvas resolution when required.
- Seeded human-like traffic events: phantom braking, reckless right-lane overtakes and telegraphed side-road pull-outs.
- Physical AI traffic separation during overtakes, merges and oncoming conflicts.
- Oil spills, standing water and potholes with recoverable surface effects and two-second HALO Focus Zone warnings.
- Rail viaducts, HALO safe bays, acoustic barriers, an Avenrà Racing paddock and a cable-stayed expressway reveal.
- A larger EVO cockpit with mirrors, forearms and gloves, a stable rider eye line and motorcycle movement beneath the camera.
- Colour-matched atmospheric haze independent of the weather selection, with Clear, Rain and Night depth treatment; near-field scenery, distance-aware traffic detail and selective edge blur remain route-aware.
- High-resolution 2.5D cars, SUVs, vans, motorhomes and HGVs, each using eight matched photoreal viewing angles, grounded shadows, wheel motion, working lights, spray and distance-aware atmospheric grading. Additional UK fleet profiles cover estates, taxis, buses, coaches, caravans, horseboxes, delivery vans and articulated HGVs using the nearest intact source sprite with vehicle-specific dimensions and markings.
- EVO instrumentation, Flow scoring, near misses and local personal best.
- Route-specific suspension response, acceleration squat, braking fork dive, steering head lag, road impacts and large-vehicle buffet beneath a stable rider eye line, with a restrained route-dependent 2–4° speed FOV increase and reduced-motion support.
- Layered spatial traffic, wind, tyre and HyperCore audio made entirely from original synthetic sound design—not field recordings—with continuous powertrain pitch, pass-by effects, route acoustics and graceful browser fallbacks.
- Opt-in Season 3 route leaderboards with banked checkpoint and full-route progress. Historical scores remain preserved in their original seasons.
- HALO Emergency Assist fictional three-step operator challenge with a clear role handoff and a route back to the game.
- Offline-ready core game after the first successful load, with visual and audio assets cached on demand as they are used.
- Local Avenrà campaign art—no hot-linked game artwork is required.

## WordPress integration

The plugin creates one normal WordPress Page containing:

```text
[avenra_hyperlane]
```

As long as that shortcode and the plugin ownership marker remain, the page uses the bundled cinematic template. Remove the shortcode if the page should return to the active theme.

Other available shortcode:

```text
[avenra_hyperlane_game height="800"]
```

This renders only the isolated game frame and is useful for custom landing pages.

## Runtime structure

```text
avenra-hyperlane/
├── avenra-hyperlane.php
├── includes/
│   └── class-avenra-hyperlane-leaderboard.php
├── templates/
├── assets/
│   ├── css/landing.css
│   ├── js/landing.js
│   ├── brand/
│   └── screenshots/
├── game/
│   ├── index-3.3.14.html
│   ├── index.html
│   ├── assets/
│   │   ├── hyperlane-cockpit-v320.css
│   │   ├── hyperlane-dynamics-audio-v338.js
│   │   ├── hyperlane-home-v320.css
│   │   ├── hyperlane-home-v320.js
│   │   ├── hyperlane-journey-gameplay-v320.js
│   │   ├── hyperlane-lighting-v337.js
│   │   ├── hyperlane-traffic-brain-v320.js
│   │   ├── hyperlane-traffic-sprites-v338.js
│   │   ├── hyperlane-uk-v320.js
│   │   ├── hyperlane-visual-v336.js
│   │   ├── hyperlane-world-v3313.js
│   │   ├── index-19b0d037-v3313.css
│   │   └── index-e80690ba-v3314.js
│   ├── campaign/
│   ├── environment/
│   │   ├── cinematic/
│   │   ├── home-v320/
│   │   ├── m1-treebelt-v334.webp
│   │   ├── m1-verge-fence-v334.webp
│   │   ├── m1-yorkshire-hedgerow-v334.webp
│   │   ├── rural-buttertubs-bank-v335.webp
│   │   ├── rural-dry-stone-verge-v335.webp
│   │   └── rural-wensleydale-hedge-v335.webp
│   ├── manifest-3.3.14.webmanifest
│   ├── manifest.webmanifest
│   ├── sw-3.3.14.js
│   └── sw.js
├── licenses/
├── readme.txt
└── uninstall.php
```

The game is deliberately isolated from the WordPress theme so its canvas, keyboard input, dialogs, fullscreen view and viewport layout cannot clash with theme CSS or JavaScript.

## Offline behaviour

On HTTPS websites, the service worker is registered only within the plugin's `game/` directory. It cannot control WordPress admin, the landing page or any unrelated website route. After a successful load, the core game page and its linked launch dependencies are available offline. Visual and audio assets are cached on demand as the browser requests them; installation does not force a download of every traffic view, environment pack or audio sample. A missing optional image or audio layer does not break the core game, and the renderer retains its lighter visual or generated-audio fallback until that asset is available.

## Data and safety

- There is no analytics or player-account requirement.
- Personal bests, preferences, a random private game key and cached rankings are stored in the player's browser.
- The public leaderboard is opt-in. After explicit consent, WordPress receives a player-chosen rider tag, the selected route and the statistics captured at the furthest safely completed checkpoint. Each browser can hold one best result per route and season. WordPress stores only a one-way HMAC of the random game key, never the raw key or WordPress account identity.
- One-use run tokens, short-lived one-way request fingerprints and anonymous rate limits deter casual abuse. The JavaScript simulation remains client-authoritative, so the board is intentionally a casual community leaderboard rather than a cheat-proof competition.
- Collision details, HALO incident evidence and operator decisions are never submitted to the public leaderboard; only the last score safely banked before an impact can qualify.
- Players can remove their own public entry from the leaderboard screen. Deactivation and updates preserve entries; deleting the plugin removes its leaderboard table.
- HALO events are fictional and do not contact Emergency Assist or emergency services. The game repeats this clearly before and throughout the operator challenge.
- The game is a closed-route entertainment and training simulation and must never be used while riding or operating a vehicle.

## Intellectual property

This package contains the original Avenrà Hyperlane browser implementation and Avenrà-supplied campaign artwork. It does **not** contain the Traffic Rider Android application, XAPK contents, extracted models, sounds, textures or proprietary code.

The WordPress integration code is licensed under GPL-2.0-or-later. Third-party notices and licence texts for the compiled browser dependencies are included under `licenses/`.

## Changelog

### 3.3.15

- Reduced the ride conditions to four road states: **Dry Day, Raining Day, Dry Night and Raining Night**, chosen from a single Conditions picker. Dusk, Post-Rain, Storm and Fog are no longer selectable; saved preferences and Weekly Works seeds map onto the nearest remaining condition.
- Replaced the Graphics step with one **performance-refined Ultra** profile. Auto, Smooth, Enhanced and Cinematic are gone, and the 36 Cinematic backdrops are no longer shipped.
- Capped the Ultra internal render resolution on touch devices at 1.5x device pixels and 1.6 megapixels (previously 2x and 2.8 megapixels), allowed the resolution self-tuner to reach 60 percent, and retargeted the frame calibrator at a 60 fps budget instead of accepting 25-35 fps as healthy.
- Baked the Motorway photographic plate grading once per plate instead of applying a live Canvas filter on every frame, and gave Rural verge scenery the same cached pre-treated surfaces that Motorway and City already used.
- Added a Canvas performance shim that caps shadow-blur radii and image-smoothing quality on touch devices, removing the most expensive per-frame glow and resampling work at Ultra while keeping the effects visible.
- Moved the runtime, launch shell, manifests and offline cache to physical 3.3.15 URLs while retaining every established entry point as an upgrade bridge.

### 3.3.14

- Made the asphalt genuinely static on City, Rural and Motorway by removing the world-distance blemish, crack and decorative puddle passes, and by keeping the road surface out of route-scene camera bob.
- Retained the moving lane and edge markings, road geometry, cat's eyes, studs, hazards, vehicle shadows, road spray, droplets and source-anchored vehicle and lamp wet lighting.
- Forced launch-shell network checks to bypass the browser HTTP cache while preserving the trusted forward-upgrade hand-off and offline fallback.
- Moved the runtime, launch shell, manifests and offline cache to physical 3.3.14 URLs while retaining the proven v3.3.13 world helper and ride stylesheet plus every established entry point as an upgrade bridge.

### 3.3.13

- Made the asphalt texture screen-anchored and cache-backed on City, Rural and Motorway while retaining moving lane markings, wet sheen, puddles, reflections and road spray, sharply reducing per-frame road resampling.
- Removed every decorative edge-speed streak path, including the City's Smooth-profile bypass, while preserving genuine rain, visor droplets, vehicle spray, lamp halos and wet-air illumination.
- Added a full-resolution direct photographic source path for phone scenery, hardened City and Motorway surface-cache fallbacks, and restored a crisp Cinematic resolution floor with warm-up-aware calibration and cautious quality recovery.
- Combined duplicate traffic constraint scans, skipped invisible rain-particle updates in Clear and Fog, reduced HUD reconciliation to 10 Hz, added a coarse-pointer compositor fast path, and disabled only the full-frame Cinematic visor-refraction readback on touch/coarse phones while retaining rain, droplets and non-readback water effects.
- Moved the world, runtime, lighting and mobile ride stylesheet, launch shell, manifests and offline cache to physical 3.3.13 URLs while retaining traffic brain v3.2.0, audio and traffic sprites v3.3.8, and visual v3.3.6 as proven helpers.

### 3.3.12

- Reduced the Motorway's pretreated photographic verge surfaces from oversized 1024-pixel backing stores to a phone-appropriate 512-pixel budget, preserving the same projected scenery geometry while removing the costly per-frame downsampling that slowed the game clock.
- Made Motorway scenery preparation capability-aware and one-shot: unsupported off-screen filters now use a DOM-canvas fallback, failed attempts are memoised, and abandoned backing stores are released instead of being recreated for every visible strip.
- Added a Motorway-only lower Cinematic resolution floor and reuse of the learned route/profile scale on later runs in the same session, while retaining the established four-step simulation safety cap and leaving Rural and City calibration floors unchanged.
- Moved the Motorway world helper, runtime, launch shell, manifests and offline cache to physical 3.3.12 URLs while retaining audio and traffic v3.3.8, visual and lighting v3.3.6, and every established entry point as an upgrade bridge.

### 3.3.11

- Replaced the City's alternating empty gaps and isolated pop-up buildings with a deterministic two-sided frontage stream that recycles varied crops of brick, civic-green, industrial and outer-city scenery across an 8–220 m corridor, capped at 14 visible pieces.
- Reduced Cinematic City backdrop work by clipping all 12 photographic plates to their shared 588-row sky and skyline region and the guarded viewport, and cached treated frontage surfaces instead of reapplying live image filters every frame.
- Batched City road, wet-detail and cat's-eye geometry, capped studs at 96, removed the artificial 48-stroke edge-speed overlay and deferred duplicate legacy scenery decoding, while retaining genuine rain, spray, droplets, lighting and traffic.
- Moved the City world helper, runtime, launch shell, manifests and offline cache to physical 3.3.11 URLs while retaining audio and traffic v3.3.8, visual and lighting v3.3.6, and every established entry point as an upgrade bridge.

### 3.3.10

- Fixed the white Motorway triangles by keeping every batched cat's-eye ellipse as a disconnected canvas subpath, retaining the low draw-call count without joining adjacent studs into filled geometry.
- Isolated the Cinematic M1 photographic plates to their sky and cloud regions, so the moving perspective-scaled verge scenery now owns the grass, hedges and trees without a duplicate static tree line.
- Removed the Motorway's decorative edge-speed streak overlay, eliminating 48 unnecessary strokes per Cinematic frame while leaving genuine rain, road spray and visor droplets intact.
- Preserved the illuminated vehicle lamps, street-light halos, wet-air scatter and droplet glints, and moved the runtime, launch shell, manifest and offline cache to physical 3.3.10 URLs while retaining the proven 3.3.9 world helper and every established entry point as upgrade bridges.

### 3.3.9

- Fixed the Motorway-only quarter-speed regression by clipping continuous M1 photographs to the visible viewport and reusing a small cache of pretreated scenery surfaces instead of repeatedly filtering oversized off-screen pixels.
- Reduced route-specific draw overhead by batching Motorway studs and barriers, limiting expensive wet-night stud scatter to the useful near and middle distance, and retaining the illuminated cores, vehicle lamps, street-light halos, spray and droplet glow.
- Made a cold Cinematic M1 launch use a lightweight procedural draw tier only while its photographic scenery is decoding, then switch automatically to the full selected presentation when ready; Rural and City rendering remain unchanged.
- Moved the Motorway world helper, runtime, launch shell, manifest and offline cache to physical 3.3.9 URLs while retaining every established entry point as an upgrade bridge.

### 3.3.8

- Fixed the slow first ride by limiting service-worker installation to the core game and linked launch dependencies instead of forcing the full visual and audio library into the cache.
- Removed the automatic graphics-pack download from setup and launch, and deferred optional traffic-angle prefetching and spatial-audio sample decoding until after the active ride or until an asset is requested.
- Made pre-ride graphics calibration iterative so it can tune internal rendering work without stalling launch, while preserving the exact wall-clock 3–2–1 countdown.
- Moved the runtime, traffic and audio helpers, launch shell, manifest and offline cache to physical 3.3.8 URLs while retaining every established entry point as an upgrade bridge.

### 3.3.7

- Fixed a compiled-variable collision that could leave the Preparing Your EVO overlay permanently displayed when the scene became ready or reached its fallback deadline.
- Restored both Preparing-to-countdown and countdown-to-riding transitions, with the full three-second wall-clock countdown retained.
- Re-armed the next animation frame before rendering and moved the seven-second fallback ahead of the draw pass, so a one-frame rendering fault cannot strand the launch sequence.
- Moved the runtime, launch shell, manifest and offline cache to physical 3.3.7 URLs while retaining the proven 3.3.6 scenery and lighting helpers.

### 3.3.6

- Added a dedicated Preparing Your EVO stage that keeps the ride frozen while the selected route, scenery, traffic, weather and lighting receive stable rendered frames and the graphics profile is calibrated.
- Replaced the simulation-step countdown with a visible wall-clock countdown, so 3–2–1 remains three seconds on low-frame-rate devices and after main-thread stalls; hidden-tab time is paused.
- Added bounded readiness and accumulator hand-offs: optional visual failures fall back after seven visible seconds, while both pre-ride transitions clear stale physics time before the motorcycle can move.
- Moved the launch shell, visual helpers, runtime, manifest and offline cache to physical 3.3.6 URLs while retaining all established entry points as upgrade bridges.

### 3.3.5

- Replaced Rural's empty stretches and isolated pop-up scenery with a deterministic, continuous two-verge photographic stream that scales smoothly toward the rider.
- Added distinct low dry-stone Ribblehead boundaries, lusher Wensleydale hedgerows and open Buttertubs grass, heather and rock banks, while preserving long views, signs and the stable rider eye line.
- Kept farm buildings as rare set-back landmarks, cleared every strip beyond the widened 2+1 overtaking lanes, capped the mobile stream at 16 items and moved the launch shell, helpers, runtime, manifest and offline cache to physical 3.3.5 URLs.

### 3.3.4

- Replaced the M1's empty roadside intervals with deterministic, continuous photographic grass, fence, hedgerow and treebelt streams on both sides of the carriageway, projected and scaled smoothly as the rider approaches.
- Added distinct Luton screening, denser Midlands woodland and open Yorkshire hedgerow character while recycling a small transparent asset set with stable spacing and controlled variation.
- Kept buildings, service areas and other recognisable structures as sparse persistent landmarks behind the vegetation, and moved the launch page, helpers, runtime, manifest and offline cache to physical 3.3.4 URLs for reliable upgrades.

### 3.3.3

- Rebalanced vehicle and street-light emission for phone-scale Night, Dusk and poor-visibility scenes, with compact hot cores, readable local coronas and broader source-anchored wet-air scatter.
- Added restrained lamp-coloured spray and deterministic droplet glints around the real projected light positions, while retaining single ownership and avoiding synthetic fixtures, duplicate road columns or full-screen bloom.
- Kept far traffic lights legible, restored illumination to every physically rendered Motorway fixture, and cache-busted the visual helpers, runtime shell and offline worker for reliable upgrades.

### 3.3.2

- Restored restrained dusk, night and poor-visibility halos at the exact projected heads of street and high-mast lights and at each photographic vehicle's active lamp anchors.
- Kept every halo with its physical renderer so the glow returns without reintroducing synthetic fixtures, smeared wet-road light columns, duplicate reflections, vignette or boost starburst.
- Added physical 3.3.2 launch, manifest, runtime, worker and cache URLs while preserving prior entry points as upgrade bridges.

### 3.3.1

- Removed the duplicate lamp bloom, synthetic static fixtures, wet-road light columns, vignette and boost starburst, and gated compiled fallback reflection/spray whenever the photographic traffic renderer owns the vehicle.
- Retained and softened the genuinely additive features: a rider dipped beam on every tier, limited Rural/Motorway oncoming scatter, and condition-aware windscreen water with optional Cinematic refraction.
- Corrected graphics-tier propagation, tunnel rain handling, unlit traffic filtering, reduced-motion behaviour, effect lifecycle, viewport resizing and combined performance accounting.
- Added physical 3.3.1 launch, manifest, runtime, worker and cache URLs while preserving prior entry points as upgrade bridges.

### 3.2.0

- Replaced the long visual-sector cadence with seeded 80–120 m UK road chapters. Each route now has authored entrances, interchangeable middles and exits, while a rolling window keeps the current chapter, one behind and up to four ahead available around the rider; bends, haze, bridges and roadside masks soften the joins.
- Added calibrated photographic depth metadata for every route: native aspect, horizon, vanishing point, projected road corners, safe crop limits and independent phone, tablet and desktop portrait/landscape profiles. Backdrops retain their proportions and use peripheral cropping instead of screen-filling stretch.
- Added the **UK Traffic Brain** state flow—cruise, follow, brake, signal, reserve gap, change lane and settle—with rider-aware reservations, keep-left/overtake-right lane advice and manoeuvres telegraphed through indicators and braking. The established route director remains authoritative for physical lane movement rather than allowing a second system to teleport vehicles.
- Expanded the route-specific UK fleet and presentation with estates, taxis, local buses, coaches, caravans, horseboxes, delivery vans and articulated HGV profiles, correct white-front/yellow-rear registration plates, vehicle dimensions, fleet markings, working lights, wheel motion, shadows, spray and wet reflections. New fleet profiles reuse the nearest complete photographic turntable where no dedicated source set exists, avoiding distorted or invented body panels.
- Separated atmospheric depth from selected weather and added tuned clear, rain, Post-Rain, storm, fog, dusk and night treatments. Post-Rain is now a fifth pre-ride weather choice, combining the native-aspect clear photographic plate with wet asphalt, lifted light and restrained lingering spray.
- Tightened Rider Dynamics and the camera around a 98.5% stable eye line, with the EVO carrying lean, fork dive, acceleration squat, road impacts, wind yaw and vehicle buffet beneath the rider. The legacy FOV ramp is normalised to a restrained route-dependent 2–4° speed increase, with dedicated tablet framing and reduced-motion behaviour.
- Reworked HyperCore audio as layered, parameter-smoothed original synthetic sound design—not field recordings—covering motor bands, hub resonance, regeneration, boost, wind, dry/wet tyres and spatial traffic. Route cues include HGV bow waves, bus air brakes, spray, expansion joints, rail-arch reflections, hedgerow fly-bys and service-area ambience, with generated browser fallbacks when an optional local layer cannot load.
- Strengthened British identity through keep-left behaviour, UK registration plates and fleet markings, correct route families, motorway-blue signs, gantries, services, studs, Rural road language and City street furniture, while retaining the existing vector road hardware and photoreal service architecture.
- Rebuilt the opening Route step as the full-screen **EVO Rider · Britain. Full Charge.** home, with clipped cinematic M1 Northern Charge, A66 Pennine Run and A40 After Dark cards, live personal-best labels, a red selected state and responsive phone, tablet and short-landscape layouts. The enhancement retains the original React radio buttons, setup state and accessible keyboard flow.

### 3.1.0

- Added deterministic enter, loop and exit route chapters across City, Rural and M1, with seeded traffic waves, platoons and non-overlapping telegraphed situations.
- Added predictive rider reservations, emergency-vehicle priority, heavier Rural oncoming traffic and clean-overtake-first Flow scoring.
- Added a photographic 2.5D world streamer with aspect-aware camera framing, uniform backdrop projection, masked static ground, UK roadside parallax and route-specific scenery chapters.
- Kept vehicle bodies as complete, undistorted photographic sprites while adding wheel motion, side lamps, emergency beacons, open-door hazards, rain spray, wet reflections and route-aware prefetching.
- Added continuous original synthetic HyperCore sound-design layers for pitch and load response, lift-off and regeneration, plus condition-aware acoustic snapshots, traffic filtering, pass-bys, HGV bow waves and wet-road spray audio.
- Tied Rural and M1 signs, gantries and service areas to travelled distance, carried gantries over the rider and kept Rural/M1 middle checkpoints outdoors.
- Added safety-capped Rider Ratings, smoother per-frame EVO cockpit springs with a stable eye line and Season 3 leaderboards for the revised scoring rules.
- Preserved every established Avenrà cockpit, service-area, cinematic, Weekly Works, HALO and offline asset; no third-party game code or media is included.

### 3.0.6

- Added dedicated tablet and unfolded-foldable cockpit framing so the photographed EVO no longer obscures the road on taller landscape displays.
- Capped tablet cockpit size against the dynamic viewport height while retaining the complete bike, embedded TFT and Rider Dynamics movement.
- Left the established phone portrait and short phone landscape presentation unchanged.
- Added physical 3.0.6 launch, manifest, runtime, stylesheet, service-worker and cache URLs so browsers cannot retain the oversized tablet layout.

### 3.0.5

- Replaced the experimental projected traffic meshes with high-resolution eight-view 2.5D sprite sets for cars, SUVs, vans, motorhomes and HGVs across City, Rural and M1.
- Kept each vehicle as one complete, uniformly scaled photographic billboard, eliminating the squashed bodywork, low-resolution side planes and texture warping produced by the previous mesh projection.
- Added stable camera-relative view selection with two-degree hysteresis, compact contact shadows, condition-aware lights and distance haze without blending incompatible vehicle angles.
- Added physical 3.0.5 launch, manifest, sprite renderer, service-worker and cache URLs, with every established alias upgraded to the complete 3.0.5 shell.

### 3.0.4

- Replaced the near-field generic ring skin with an aspect-correct photographic hull: full side, upper and end cutouts now define the visible vehicle instead of revealing an opaque procedural base around their alpha.
- Calibrated separate visual lengths for all five traffic classes, including the 8.018 m rigid HGV, while leaving gameplay and collision spacing unchanged.
- Subdivided photographic side and upper surfaces into compact longitudinal cells, selected visible surfaces by camera-relative depth and removed the duplicate live wheel layer from baked-wheel photo hulls.
- Added physical 3.0.4 launch, manifest, runtime bundle, service-worker and cache URLs, with every established alias upgraded to the complete 3.0.4 shell.

### 3.0.3

- Added high-resolution photoreal side and upper-surface materials for cars, SUVs, vans, motorhomes and HGVs, UV-mapped directly onto the shared 3D mesh faces so flanks, roofs, bonnets and end panels remain one coherent vehicle.
- Perspective-mapped and longitudinally subdivided those materials across the existing road-space meshes while retaining vehicle-specific dimensions, working projected wheels, lights, shadows, depth haze and all route traffic behaviour.
- Added a physical 3.0.3 launch page, manifest, service worker and cache namespace, with every established service-worker URL retained as an in-place upgrade bridge to the complete 3.0.3 shell.

### 3.0.2

- Replaced flat car, SUV, van, motorhome and HGV billboards with perspective-projected road-space meshes across City, Rural and M1; specialist horses, tractors and motorcycles retain their dedicated artwork.
- Added high-resolution front/rear face textures, visible side and roof planes, vehicle-specific dimensions, rotating projected wheels, panel detail, attached lamps, indicators, emergency beacons, an open-door hazard and footprint shadows.
- Added far, mid and near vehicle detail levels with colour-matched atmospheric fading, while preserving player-aware behaviour, collision dimensions and route-specific traffic density.
- Added a physical 3.0.2 launch page, manifest, service worker and cache namespace, with every established service-worker URL retained as an in-place upgrade bridge to the complete 3.0.2 shell.

### 3.0.1

- Updated the EVO rider view with the new full-cockpit artwork, including the larger sports-bike body, mirrors, forearms and gloves.
- Embedded the live speed, ride-mode, battery and HyperCore display inside the photographed TFT so it follows the same lean, pitch and spring motion as the motorcycle.
- Added a clean alpha matte, softened windscreen transparency and responsive portrait/landscape framing while preserving the complete 3.0.0 route, traffic, visual and audio systems.
- Added a physical 3.0.1 launch page, manifest, service worker and cache namespace so installed games and cached WordPress pages move cleanly to the revised cockpit build.
- Preserved the 2.6.5 and 3.0.0 service-worker URLs as in-place upgrade bridges to the complete 3.0.1 offline shell.

### 3.0.0

- Introduced the **Living Roads** director and player-aware traffic across City, Rural and M1, with varied route situations and clear, fair warning cues.
- Added the five-part **Rider Rating** and post-ride debrief, plus a server-locked **Weekly Works Run** using shared route, weather, traffic and Road Dynamics conditions.
- Added **Rider Dynamics 2.0** while retaining the accessible original experience, with richer steering, suspension, pitch, road and vehicle-buffet responses.
- Rebuilt the rider view around a larger EVO cockpit, stable eye line, atmospheric depth haze, near-field motion and distance-aware 3D-style traffic.
- Added layered spatial traffic, wind, tyre and HyperCore audio using original synthetic sound design, with route-aware ambience and safe fallbacks.
- Rolled the complete next-generation experience out to City, Rural and M1 while retaining all 36 Cinematic backdrops, the four photoreal Motorway service facades and every established route set piece.
- Moved the launch page, manifest, service worker and offline cache to physical 3.0.0-specific URLs so prior browser, WordPress and CDN caches cannot reopen an earlier build.
- Included an in-place 2.6.5 service-worker upgrade bridge so existing installed PWAs move directly onto the 3.0.0 shell and cache.

### 2.6.5

- Replaced the mutable game entry, manifest and service-worker launch chain with physical 2.6.5-specific URLs so WordPress, CDN and PWA caches cannot silently reopen an earlier bundle.
- Retained compatibility entry files for existing installations, cleared common WordPress page caches during the upgrade and validated the exact 2.6.5 build marker before any game document is cached or served offline.
- Added a discreet `BUILD 2.6.5` marker to the Ride Setup screen so the active runtime can be confirmed immediately.

### 2.6.4

- Kept the four photoreal Motorway service-station facades active in Smooth, Auto, Enhanced, Ultra and Cinematic instead of substituting simple blocks on lower-end mobile devices.
- Preloaded and decoded every service facade as the Motorway run starts, and added the small facade set to the core offline cache to prevent cold-load fallbacks.
- Raised the darkest night-weather visibility floor while preserving condition-aware grading, live slip roads, forecourts and station details.

### 2.6.3

- Made Rural oncoming traffic substantially heavier while retaining fair spacing and protected overtaking opportunities.
- Added two short, clearly signed Rural 2+1 sections with a dedicated overtaking lane.
- Redesigned every City Cinematic backdrop around distant far-field architecture so nearby static buildings no longer weaken the illusion of movement.

### 2.6.2

- Replaced the four Motorway service-area facade blocks with distinct photoreal Toddington, Watford Gap, Woodall and Woolley Edge architecture while preserving the live slip roads, approach signs, EV forecourts, chargers and parked traffic.
- Added condition-aware facade grading, grounded shadows, warm dusk and night entrance lighting, and a richer illuminated charging-canopy underside for Clear, Rain, Storm and Fog.
- Kept Smooth mode and slow image loads fully playable through the existing procedural station fallback, added the new facades to the optional offline graphics pack and bumped the PWA cache.

### 2.6.1

- Kept Motorway gantries, junction boards and services signs visible through their complete approach, fading only as they naturally leave the frame at the rider's near plane.
- Extended each authored Rural oncoming wave by one second, adding a modest increase in opposing traffic while retaining the 92-metre spacing guard and long clear overtaking gaps.
- Bumped the offline PWA cache and content-hashed game bundle so the corrected sign lifecycle and Rural traffic balance replace 2.6.0 immediately.

### 2.6.0

- Extended **Cinematic · Experimental** to Motorway, City and Rural with 36 route-specific photoreal backdrops: Day, Dusk and Night across Clear, Rain, Storm and Fog.
- Tightened perspective masking and overscan so the photographed carriageway remains behind the live road through bends, elevation changes, lane shifts and the M1 roadworks taper.
- Closed the remaining backdrop bleed around overhead gantry legs and panels during the near-camera pass while preserving the live vector signs.
- Split Cinematic offline downloads into route-specific packs, so only the selected route's 12 backdrops are requested.
- Kept Cinematic assets optional: partial download failures are reported cleanly, successful files remain cached and the core offline shell still installs independently.
- Bumped the offline PWA cache so the all-route Cinematic renderer and corrected compositing replace the 2.5.0 build.

### 2.5.0

- Added **Cinematic · Experimental** as a manual Motorway M1 graphics choice using original, locally bundled photoreal atmosphere, woodland, roadside and traffic artwork.
- Preserved the live M1 traffic, motorway signs, services, roadworks, hard shoulder, hazards and scoring beneath the new visual treatment.
- Added adaptive internal resolution for Cinematic play while keeping the player's manual graphics selection active.
- Made City and Rural fall back safely to their complete Ultra presentation when a saved Cinematic preference is carried to either route.
- Added an optional offline Cinematic graphics pack with safe asset fallbacks, expanded graphics metadata and release regression coverage.

### 2.4.4

- Kept Rural sign lettering bounded and visible from the first rendered frame, eliminating blank panels and late text pop-in during approach.
- Rebuilt SLOW legends as projected physical road stencils that remain flat on the asphalt, follow road perspective and persist naturally through the near plane.
- Added sign-lifecycle, road-stencil projection and mobile Ultra performance regression coverage.
- Bumped the offline PWA cache so the corrected Rural signs and markings replace the previous game build.

### 2.4.3

- Removed the hard-coded 46 mph braking floor so full braking can bring the EVO to 0 mph on every route and in every ride mode.
- Made braking override HyperCore Boost, with normal mode-limited acceleration resuming when the brake is released.
- Added multi-frame-rate regression coverage for braking from 132 mph and for the previous below-46 mph acceleration bug.
- Bumped the offline PWA cache so the corrected speed controller replaces the previous game build.
