# Avenrà EVO · B-Road

A first-person WebGL simulation of riding an Avenrà EVO around a quiet, traffic-free two-way British B road in the Yorkshire Dales. Realism is the priority: real lighting and cast shadows, physically-based road and verge materials, UK road language, hedgerows, dry-stone walls, post-and-wire fences, coned-off side turnings and live rear-view mirrors behind the EVO rider cockpit photograph.

## What is in the world

- **Route** – a 2.4 km closed loop sampled every metre into a Frenet frame, with rolling elevation (max 7 % gradient), ten bends between 54 m and 116 m radius, and three T-junctions.
- **Road** – 6 m carriageway with a 2.5 % crown, procedural asphalt (albedo, normal and roughness maps) with polished wheel tracks and a separate 40 m wear map of patches, cracks and tar snakes so the surface never visibly repeats.
- **Markings** – 4 m / 8 m centre line, 6 m / 3 m hazard warning lines into bends and junctions, double white lines through the blind bends, edge lines on hazard stretches broken across junction mouths, white cat's eyes, SLOW legends before tighter bends, and give-way lines and triangles on every side road.
- **Junctions** – each side road is flared at the mouth, marked, signed (junction warning triangles on the main road, Give Way facing the side road, a fingerpost name plate) and closed with seven cones and a Road Closed board on a barrier frame.
- **Boundaries** – hedgerows (extruded body plus alpha-tested foliage cards), random-coursed dry-stone walls with normal maps, and post-and-wire fences; bends carry warning triangles and chevron boards; a national speed limit sign opens the run; telegraph poles with sagging wires run along one side.
- **Landscape** – grass verges with a ditch and bank, tens of thousands of wind-swayed grass blades, far pasture with hay meadows, scattered field trees and field-boundary hedgerow lines.
- **Sky and light** – a custom sky shader (Rayleigh-style gradient, sun disc and glow, drifting fbm clouds), a warm low sun with a 4k/2k PCF shadow map that follows the rider, hemisphere ambient, ACES tone mapping and exponential fog.

## Riding

The powertrain is fitted to the EVO's figures: 0–60 mph in 3.9 s and a 109 mph terminal speed. Every metre of road carries a **safe** cornering speed (0.57 g) and a **maximum** (0.88 g) derived from its radius; the corner bar at the top of the screen plans ahead for braking and turns green, amber or red. Between safe and maximum the bike drifts towards the outside of the bend and needs holding; above the maximum it runs off into the verge and the hedge. Light oncoming traffic circulates the loop in the other lane, slowing for bends and junctions; cross the centre line into its path and you will collide.

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
| `src/00-core.js` | maths, deterministic RNG, noise, every procedural texture and sign face |
| `src/10-route.js` | loop geometry, elevation, terrain, junction plan, boundary plan, bend detection |
| `src/20-world.js` | mesh builders for road, verges, pasture, boundaries, junctions, markings, signs, sky, sun |
| `src/30-bike.js` | rider dynamics, camera rig, touch / keyboard / tilt input |
| `src/40-overlay.js` | cockpit compositing, mirrors, dash, procedural audio |
| `src/50-traffic.js` | oncoming cars: procedural model, bend-aware speed, pass-by and collision events |
| `src/90-main.js` | renderer setup, quality detection, corner-bar HUD, frame loop |

Everything except the cockpit photograph (`assets/cockpit.png`) is generated at runtime from a fixed seed, so the road is identical on every device.
