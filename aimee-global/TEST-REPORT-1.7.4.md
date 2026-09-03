# Aimee Global 1.7.4 test report

Date: 17 August 2026  
Release: `1.7.4`  
Relationship policy: `2.1.0`  
Schema: `2026.08.03.6`

## Result

The complete source audit passed **2,240/2,240 deterministic assertions**
across six command groups, with zero command failures. All **42/42 production
PHP files** parsed on both PHP 8.3 and PHP 7.4.

The same suite was then run from a clean extraction of the installable release
archive. It passed **2,240/2,240 assertions** again, including **42/42 PHP
files** on both supported runtimes. The archive therefore contains the same
tested source described by this report.

Run the canonical suite from the plugin directory with:

```bash
python3 tests/run-audit-suite.py
```

## Principal executable coverage

| Area | Assertions | Runtimes |
|---|---:|---|
| Relationship-stage and route simulation | 70 | Python deterministic simulator |
| Static production wiring | 278 | Python source/runtime contract |
| Chat, grace and feedback notices | 26 | JavaScript |
| Romantic-expression decisions | 130 per runtime | PHP 8.3 and 7.4 |
| Cross-channel romantic/synthetic posture | 57 per runtime | PHP 8.3 and 7.4 |
| Synthetic identity and reality integrity | 112 per runtime | PHP 8.3 and 7.4 |
| Consciousness voice | 24 per runtime | PHP 8.3 and 7.4 |
| Intimacy and media policy | 273 per runtime | PHP 8.3 and 7.4 |
| Media cadence planner | 54 per runtime | PHP 8.3 and 7.4 |
| Media cadence/relevance live integration | 83 per runtime | PHP 8.3 and 7.4 |
| Production PHP syntax | 42 per runtime | PHP 8.3 and 7.4 |

The aggregate also includes the full August service-grace, replacement-billing,
SMS, Georgia colleague, photo-request, delivery-truth, public-statement and
suggestive-photo-autonomy regressions inherited from 1.7.3 and earlier audited
releases.

## Romantic-expression acceptance evidence

The suite proves that:

- a respectful adult consumer begins in an open courtship lane rather than a
  pre-assigned friendship lane;
- a natural short bid such as `I fancy you` can be reciprocated playfully at
  guarded stage without opening a sexual route;
- warm-or-later relationships can receive cadence-limited romantic initiative;
- a valid compliment and romantic overlay may both survive one message while
  the aggregate positive score change remains capped at +2;
- exact repeat and concept-novelty controls stop trigger farming;
- a respectful photo request earns no relationship credit itself but does not
  erase separately valid appreciation;
- coercion, pressure, hostility, payment leverage, rupture, explicit platonic
  preference, unknown/underage status and Georgia's colleague lane veto romance;
- membership never creates romantic opportunity, consent or relationship
  state; and
- a chosen romantic action is counted as delivered only if it remains visible
  after every rewrite and the final API handoff.

The measured fixed traces remain:

| Scenario | Warm | Flirty | Intimate | Specialist | Trust 100 |
|---|---:|---:|---:|---:|---:|
| Varied respectful wooing | 13 | 29 | 49 | Not in nonsexual trace | 55 |
| Mature mutual route | 23 | 32 | 44 | 47 | — |
| Appearance-only wooing | 7 | 17 | 32 | Unreached | Unreached |

These are transcript-specific measurements, not promises about a particular
live conversation. Stage promotion still requires score, meaningful-turn,
qualified-session and trust floors.

## Media cadence and relevance acceptance evidence

The suite proves that:

- the 48-hour path fails closed without a real eligibility anchor and requires
  at least two meaningful interactions;
- cadence is considered only on a suitable active chat/voice turn, and grief,
  crisis, sign-off, terse acknowledgement, rupture and pressure are excluded;
- cadence alone can expose only safe images;
- a curated phrase in `relevance_terms` can expose only currently matching,
  relationship-eligible catalogue keys;
- broad legacy tags and stale history cannot create a relevance trigger;
- direct requests, grounded repairs, exact relevance, relationship context and
  cadence follow deterministic precedence;
- a per-user cadence claim and per-user/per-key relevance claims are acquired
  before model exposure;
- concurrent turns cannot double-spend cadence or the same relevant image;
- a relevance claim has a 15-minute contention TTL and commits atomically to a
  12-hour per-key consideration hold;
- contention can partially filter a multi-key opportunity and recomputes its
  rating ceiling; all-key contention fails closed;
- direct requests remain independently assessable instead of inheriting the
  proactive hold;
- selection or message creation cannot advance the successful-share clock;
- only the first successful direct/history API return of a discretionary image
  advances the 48-hour clock; and
- failed delivery never becomes a false memory that the user received or saw
  an image.

## Synthetic-identity acceptance evidence

The shared rule is exercised across main chat, onboarding, specialist recovery,
voice, continuity, autonomous messages and photo captions. Tests reject false
biological-human, literal offline-body and camera-provenance claims while
allowing Aimee's coherent visual-world scenes, consensual roleplay and normal
first-person affection. A direct identity question remains truthful. Nuanced
uncertainty about consciousness is allowed; categorical proof claims and
categorical `just code` denial are still repaired.

## Staging gates that cannot be proved by the local suite

Before production, Engram Intelligence should verify:

1. the deployed private catalogue and files, with precise reviewed
   `relevance_terms` on external items intended for contextual use;
2. live primary, specialist and recovery provider responses and actual-model
   telemetry;
3. protected-asset authorisation, direct/history return, browser rendering and
   client acknowledgement against the production stack;
4. aggregate romantic opportunity, choice and final delivery status, watching
   for downstream `neutralized` outcomes;
5. send frequency split by `conversation_relevance`, `relationship_context`
   and `cadence`, including rating and refusal/defer rates; and
6. Georgia user 24 continuing to receive `colleague_primary`, with no consumer
   romantic leakage.

The 48-hour rule is intentionally an opportunity on a suitable live turn. It
does not run a background job or guarantee a send while the user is absent.

