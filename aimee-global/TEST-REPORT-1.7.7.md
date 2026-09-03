# Aimee Global 1.7.7 — live-image bridge test report

**Release:** `1.7.7`  
**Date:** 18 August 2026  
**Schema:** `2026.08.18.1`  
**Relationship policy:** `2.1.0` (unchanged)  
**Status:** Security-repaired source tree and clean archive verified

## Source-tree result

```text
AUDIT SUITE RESULT: 6 commands passed, 0 failed; 3651 assertions passed, 0 failed
```

All **44/44** production PHP files parsed under PHP **8.3** and the minimum
supported PHP **7.4** runtime.

The earlier installable archive predates the final account-erasure security
repair and is superseded. Its replacement was extracted into a clean temporary
plugin layout and reproduced **3,651/3,651**.

## New asynchronous evidence

`tests/media-materialization-async-regression.php` passed **24/24** on each PHP
runtime. It covers:

- bounded `pending|ready|unavailable|failed` status and a required positive
  pending job identity;
- server-only job telemetry and removal of `job_id` from the client result;
- the exact pending copy after profile, synthetic and jealousy review;
- a neutral pending contract with zero ledger deltas and no invitation, archive,
  memory, opinion or self-model fields;
- exact configured/authenticated owner user 112 scope;
- raster validation and delivery-bound provider-neutral resolution;
- transaction and row lock preceding immutable asset binding;
- one guarded image message and one `message_created` transition;
- already-completed replay verification after a later access lapse, with no
  duplicate bind, transition or message;
- rollback of both binding and message when insertion fails, followed by a safe
  retry;
- one honest text-only terminal-failure note and idempotent failure replay;
- closure of completion/failure callbacks for another user;
- no image/delivery ID on the interim Aimee message;
- no hidden invitation, memory, opinion, metacognition or continuity work;
- terminal delivery invalidation when interim message persistence fails; and
- no preview-image or media-cadence consumption while pending.

`tests/media-materialization-erasure-regression.php` passed **25/25** on each
runtime with the sidecar absent. It covers exact-table InnoDB readiness,
transaction-locked tombstoning, every-status future-lease barriers, a
deactivation-failed worker's late rename, bounded native-hook retries,
operator-visible reasons, stale-cron replacement, strict contained unlink,
exact-token row deletion after absence, cross-user isolation, and recovery from
output, token, unlink, row-delete, schema and database failures.

Static integration passed **311/311** and also verifies schema/provenance
columns, bootstrap order, owner opportunity labeling, async filter mode,
history-before-return byte resolution, protected private serving, persistent
account erasure and the client render/acknowledgement lifecycle.

## Preserved companion and policy evidence

The complete inherited suite was rerun. In particular:

- companion voice/jealousy/prompt boundary: **55/55** per PHP runtime;
- synthetic identity and reality integrity: **120/120** per runtime;
- cross-channel identity/romantic posture: **57/57** per runtime;
- romantic expression: **248/248** per runtime;
- profile-attribution wiring: **118/118** per runtime; and
- all relationship, media policy, cadence, delivery truth, Georgia colleague,
  billing/service-grace, SMS and historical repair regressions remained green.

The bridge does not call a relationship reducer, award score, change stage,
widen media eligibility or treat flirtation/jealousy/membership as consent.
All 1.7.6 rules against `mate`, fabricated human biography and unsafe jealousy
remain active on pending, completion and failure copy.

## Packaging acceptance

Do not reuse the superseded archive. The replacement was built only from this
reviewed tree, cleanly extracted and passed `python3 tests/run-audit-suite.py`
with **3,651/3,651**. Its SHA-256 is recorded in the companion release checksum
manifest.
