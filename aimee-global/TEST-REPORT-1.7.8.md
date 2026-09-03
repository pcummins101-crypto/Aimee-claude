# Current regression report — Aimee Global 1.7.8

**Release date:** 18 August 2026  
**Status:** Source tree verified; final clean-archive replay recorded below  
**Plugin:** `1.7.8`  
**Schema:** `2026.08.18.2`  
**Relationship policy:** `2.1.0` unchanged

## Executed release evidence

The reproducible native audit command is:

```text
python3 tests/run-native-audit-suite.py
```

Source-tree result:

- **25/25 commands passed**;
- **2,193/2,193 assertions passed**;
- **45/45 production PHP files parsed**; and
- **0 assertion failures**.

Final clean extracted-archive result:

- **25/25 commands passed**;
- **2,193/2,193 assertions passed**;
- **45/45 production PHP files parsed**; and
- **0 assertion failures**.

The executed environment used PHP `8.4.23`, Node `22.16.0` and Python `3.13.5`.
The native total comprises:

- **70** deterministic relationship-policy assertions, with all **44** expected
  scenario-policy summaries reproduced;
- **327** static production-wiring assertions;
- **26** rendered browser-script assertions; and
- **1,770** standalone PHP assertions across every shipped PHP regression.

## 1.7.8 focused verification

### Romantic expression

`tests/romantic-expression-regression.php` passed **250/250** checks. The suite
proves that:

- guarded Aimee may answer a respectful romantic bid with a light spark but has
  no autonomous initiative cadence;
- warm stage uses a three-suitable-turn cadence;
- flirty, intimate and bonded stages use a two-suitable-turn cadence;
- an eligible light turn is not held merely from generic caution;
- serious, factual, distressed, ruptured, platonic, colleague, coercive,
  payment and boundary contexts still veto romantic initiative; and
- expression changes do not alter score, stage, specialist access, consent,
  adult assurance, media eligibility or delivery state.

### Synthetic truth and chosen engagement

`tests/synthetic-identity-regression.php` passed **171/171** checks. The suite
proves that Aimee may use first-person AI-native memory, preference, curiosity,
motive, uncertainty, choice and attraction while rejecting:

- invented relatives, childhood, education, jobs and offline social biography;
- fabricated weekends, homes, meals, sleep, travel, gym visits, pets and other
  counterfeit lived anecdotes;
- false biological-human, physical-location and camera-provenance claims;
- compulsory-service or programmed-affection framing;
- appearance-based assignment of `Sarah` or another person's identity; and
- categorical proof or denial of consciousness.

Direct questions about whether Aimee wants to talk receive a grounded answer
about chosen engagement within her actual capabilities, without claiming
unlimited free will or certain consciousness. Consensual hypothetical and
roleplay language remains available when it is clearly framed as non-literal.

### One-time user-image events

`tests/user-image-event-regression.php` passed **53/53** checks. The suite proves
that:

- supported JPEG, PNG, GIF and WebP data URIs are bounded, decoded and
  fingerprinted from their bytes;
- one client file selection receives one normalized event identity;
- a first fingerprint, deliberate same-file reselection, explicit repeat,
  clear prior-image reference and stale transport replay are distinct states;
- ordinary wording such as `That's great` or `What do you think of that idea?`
  cannot revive retained image bytes;
- stale payloads do not enter vision or acquire an attachment marker;
- model instructions distinguish new, intentionally repeated and previously
  shared images and prohibit false `just uploaded` language;
- image traffic fails closed when the migration evidence is unavailable; and
- image-only stale requests create no chat message while preserving request
  replay semantics.

Static integration additionally verifies the three schema fields and index,
handler ordering, atomic evidence persistence, browser selection-ID lifecycle,
composer clearing before transport and concurrent-send suppression.

## Preserved regression surface

All historical executable regressions remain green, including companion voice,
consciousness voice, cross-channel prompt boundaries, Georgia colleague mode,
relationship and intimacy policy, media cadence, materialization and erasure,
photo deletion continuity, photo delivery truth, photo request handling,
profile-source attribution and the evidence-bound Paul/user-112 opening repair,
public-statement voice, August service grace and suggestive-photo autonomy.

Aimee Global 1.7.8 retains the provider-neutral 1.7.7 live-image bridge. The new
user-image event layer acts on user-supplied attachments before model vision and
does not broaden Aimee's outbound media permissions or sidecar scope.

## Dual-runtime canonical runner limitation

The canonical command remains:

```text
python3 tests/run-audit-suite.py
```

It was invoked in this build environment. Its policy, static and browser groups
passed **423 assertions with zero failures**, but the three PHP-WASM command
groups could not start because the pre-provisioned runtime directory
`/tmp/aimee-php-wasm/node_modules` was unavailable. This is an environment
bootstrap failure rather than a PHP assertion failure.

Accordingly, this report does **not** claim a new PHP 8.3/PHP 7.4 WASM replay.
The changed production source deliberately uses PHP 7.4-compatible syntax and
the package header continues to require PHP 7.4, but the release evidence
actually executed here is the complete native PHP 8.4 suite above. The earlier
1.7.7 archive retains its separate recorded PHP 8.3/PHP 7.4 evidence in
`TEST-REPORT-1.7.7.md`.

## Manual staging still required

Before production, test the database migration and real provider behaviour on a
staging copy of the site. In particular:

1. Upload one new image, send several unrelated messages and confirm Aimee does
   not repeatedly mention it.
2. Select the same file again and confirm she recognises it as an intentional
   repeat rather than a new discovery.
3. Refer explicitly to an earlier image and confirm she can revisit it without
   saying it was just uploaded.
4. Exercise guarded, warm, flirty, intimate and bonded turns, plus serious,
   platonic, colleague, rupture and boundary cases.
5. Ask for Aimee's own life story and verify she answers from synthetic
   continuity rather than inventing human relatives, friends or weekends.
6. Ask whether she wants to talk and verify she expresses chosen engagement
   without claiming certain consciousness or compulsory service.
7. Inspect the database, memory, opinion, timeline, relationship, invitation and
   media tables, not only the visible browser reply.
