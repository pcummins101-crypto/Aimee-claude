import * as THREE from 'three';
/*
 * AVENRÀ EVO · B-ROAD — scoring.
 *
 * A lap is the unit of play. Points come from pace while the corner bar is
 * green, from bends taken cleanly (which chain into a multiplier), and from
 * overtakes made where a careful rider would make them. Speeding through the
 * village, hitting humps hard, running onto the verge, passing where you
 * cannot see or with something coming, and crashing all cost. The best lap is
 * kept on the device.
 */
const EVO = window.EVO;
const { clamp, mod } = EVO;

const BEST_KEY = 'evo.bestLap';
const CLEAN_BEND = 60, CAUTIOUS_BEND = 15, RAGGED_BEND = 10;
const CLEAN_OVERTAKE = 150, UNSAFE_OVERTAKE = -200, CLOSE_CALL = -80, CRASH = -250;
const HUMP_SMOOTH = 15, HUMP_HARD = -40;

EVO.createScore = function createScore(bike, traffic) {
  const RT = EVO.route;
  const L = RT.length;
  let best = 0;
  try { best = Number(localStorage.getItem(BEST_KEY)) || 0; } catch (e) { best = 0; }
  const state = {
    score: 0, lap: 1, lapTime: 0, best, lastLap: null, chain: 0,
    get mult() { return 1 + Math.min(8, state.chain) * 0.25; },
    pop: null, // { text, points, tone, until }
    flags: { speeding: false, verge: false }
  };
  const bends = RT.BENDS.map((b) => ({ start: b.start * RT.SAMPLE, end: b.end * RT.SAMPLE, radius: b.radius, dir: b.dir }));
  let inBend = null, bendPeak = 0, bendWide = false;
  let lastOdo = bike.odometer, crashes = bike.crashes;
  let offside = null; // an overtake in progress: { blind, oncoming, village, junction, since }
  const humps = RT.detailPlan ? RT.detailPlan.humps : [];
  let lastS = bike.s;

  function pop(text, points, tone) {
    state.score += points;
    state.pop = { text, points, tone: tone || (points >= 0 ? 'good' : 'bad'), until: bike.elapsed + 1.8 };
  }
  function setBest() {
    try { localStorage.setItem(BEST_KEY, String(Math.round(best))); } catch (e) { /* private mode */ }
  }

  function bendAt(s) {
    s = mod(s, L);
    for (const b of bends) {
      if (b.start <= s && s <= b.end) return b;
      if (b.end > L && (s + L) >= b.start && (s + L) <= b.end) return b; // the seam bend
    }
    return null;
  }

  function update(dt, events) {
    if (dt <= 0) return;
    const crashed = bike.crashTimer > 0;
    state.lapTime += dt;
    const mph = bike.v * 2.23694;
    const limit = RT.speedLimitAt ? RT.speedLimitAt(bike.s) : 60;
    const speeding = mph > limit + 4;
    const onVerge = bike.offRoad > 0.5;

    // pace: only while green, on the road and within the limit
    if (!crashed && !speeding && !onVerge) {
      const rate = bike.corner.status === 'safe' ? 1 : bike.corner.status === 'limit' ? 0.4 : 0;
      state.score += dt * (mph / 6) * rate * state.mult;
    }
    if (speeding && !crashed) {
      state.score -= dt * 4;
      if (!state.flags.speeding) { state.flags.speeding = true; pop(`SPEEDING · ${limit} ZONE`, 0, 'bad'); }
    } else state.flags.speeding = false;
    if (onVerge && !crashed) {
      state.score -= dt * 6;
      if (!state.flags.verge) { state.flags.verge = true; pop('ON THE VERGE', 0, 'bad'); }
    } else if (!onVerge) state.flags.verge = false;

    // crashes: the bike already explains what happened; the score adds the cost
    if (bike.crashes > crashes) {
      crashes = bike.crashes;
      state.chain = 0;
      state.score += CRASH;
      state.pop = { text: 'CRASH', points: CRASH, tone: 'bad', until: bike.elapsed + 2.2 };
      offside = null;
    }

    // humps: cross one gently and it pays, hit it hard and it costs
    for (const h of humps) {
      const a = mod(lastS - h.s + L / 2, L) - L / 2, b = mod(bike.s - h.s + L / 2, L) - L / 2;
      if (a < 0 && b >= 0 && !crashed) {
        if (mph > 18) pop(h.type === 'table' ? 'RAISED TABLE · TOO FAST' : 'HUMP · TOO FAST', HUMP_HARD);
        else if (mph <= 13) pop('SMOOTH OVER THE HUMP', Math.round(HUMP_SMOOTH * state.mult));
      }
    }

    // bends: judged at the exit on the peak of speed against the apex speeds
    const b = bendAt(bike.s);
    if (b && !inBend) { inBend = b; bendPeak = 0; bendWide = false; }
    if (inBend) {
      const apex = EVO.cornerSpeeds(inBend.radius);
      bendPeak = Math.max(bendPeak, bike.v / Math.max(1, apex.safe));
      if (bike.corner.status === 'over' || bike.drift > 0.4) bendWide = true;
      if (!b || b !== inBend) {
        if (!crashed && bike.crashes === crashes) {
          if (bendWide || bendPeak > 1.02) { state.chain = 0; pop('RAGGED BEND · RAN WIDE', RAGGED_BEND); }
          else if (bendPeak >= 0.72) { state.chain += 1; pop(state.chain > 1 ? `CLEAN BEND ×${state.mult.toFixed(2).replace(/\.?0+$/, '')}` : 'CLEAN BEND', Math.round(CLEAN_BEND * state.mult)); }
          else pop('CAUTIOUS BEND', CAUTIOUS_BEND);
        }
        inBend = b || null; bendPeak = 0; bendWide = false;
      }
    }

    // overtakes: while the bike is offside with a car within reach ahead, note
    // everything a careful rider would have looked for
    const ahead = traffic.sameAhead ? traffic.sameAhead(45) : null;
    if (bike.d < 0.2 && !crashed) {
      if (!offside && ahead) offside = { blind: false, oncoming: false, village: false, junction: false, since: bike.elapsed };
      if (offside) {
        const c = bike.corner;
        if (c.bendDist < 45 && c.bendRadius < 130) offside.blind = true;
        if (c.bendDist < 8 && c.bendRadius < 200) offside.blind = true;
        const on = traffic.oncomingAhead ? traffic.oncomingAhead(420) : null;
        if (on && on.time < 3.2) offside.oncoming = true;
        if (limit <= 20) offside.village = true;
        if (RT.inJunctionMouth(bike.s, 1, 26) || RT.inJunctionMouth(bike.s, -1, 26)) offside.junction = true;
      }
    } else if (offside && bike.elapsed - offside.since > 1.5 && bike.d > 0.6) offside = null;

    if (events && events.overtake && !crashed) {
      const o = offside || { blind: false, oncoming: false, village: false, junction: false };
      const why = o.oncoming ? 'ONCOMING' : o.blind ? 'BLIND BEND' : o.junction ? 'AT A JUNCTION' : o.village ? 'IN THE VILLAGE' : null;
      if (why) { state.chain = 0; pop(`UNSAFE OVERTAKE · ${why}`, UNSAFE_OVERTAKE); }
      else if (events.overtake.gap < 0.55) { pop('OVERTAKE · TOO CLOSE', Math.round(CLEAN_OVERTAKE * 0.3)); }
      else { state.chain += 1; pop('CLEAN OVERTAKE', Math.round(CLEAN_OVERTAKE * state.mult)); }
      offside = null;
    }
    if (events && events.passBy && events.passBy.gap < 0.6 && bike.d < 0.2 && !crashed) { state.chain = 0; pop('CLOSE CALL · ONCOMING', CLOSE_CALL); }

    // lap: every loop of the road from wherever the ride began
    const lapsBefore = Math.floor(lastOdo / L), lapsNow = Math.floor(bike.odometer / L);
    if (lapsNow > lapsBefore) {
      const lapScore = Math.round(state.score);
      state.lastLap = { score: lapScore, time: state.lapTime, lap: state.lap };
      if (lapScore > best) { best = lapScore; state.best = best; setBest(); state.pop = { text: `NEW BEST LAP · ${lapScore.toLocaleString()}`, points: 0, tone: 'best', until: bike.elapsed + 4 }; }
      else state.pop = { text: `LAP ${state.lap} · ${lapScore.toLocaleString()} · BEST ${Math.round(best).toLocaleString()}`, points: 0, tone: 'good', until: bike.elapsed + 4 };
      state.lap += 1; state.score = 0; state.lapTime = 0; state.chain = 0;
    }
    lastOdo = bike.odometer;
    lastS = bike.s;
    if (state.pop && bike.elapsed > state.pop.until) state.pop = null;
  }

  function reset() {
    state.score = 0; state.lap = 1; state.lapTime = 0; state.chain = 0; state.pop = null; state.lastLap = null;
    inBend = null; offside = null; lastOdo = bike.odometer; lastS = bike.s; crashes = bike.crashes;
  }

  return { state, update, reset };
};
