# Provider-neutral asynchronous live-image bridge — 1.7.7

**Release:** Aimee Global 1.7.7  
**Date:** 18 August 2026  
**Schema:** `2026.08.18.1`  
**Relationship policy:** `2.1.0` (unchanged)

## Purpose

This release restores the useful delivery and security boundary from the
recovered image beta without reverting any 1.7.6 companion behavior or
embedding a provider implementation in Global. The bridge answers one narrow
question: after normal policy and Aimee's persisted choice have authorised an
exact safe catalogue key, may a trusted sidecar asynchronously create a private
derivative for that exact delivery?

The answer cannot change eligibility. Global remains authoritative for user,
key, content ceiling, access, adult assurance, directness, cooldown, pressure,
rupture and delivery lifecycle. The sidecar may reserve or render work only.

## Release scope

Live materialization is enabled only when all of these persisted/fresh facts
hold:

- authenticated and configured owner are both WordPress user `112`;
- the channel is ordinary main chat, not voice or SMS;
- the existing opportunity reason is `owner_safe_image_test`;
- the request is direct, non-proactive and safe;
- the chosen key is in the persisted eligible-key set and Aimee's persisted
  decision is `send`;
- current technical access and adult assurance remain valid;
- cooldown remains clear; and
- no pressure, hard veto or unresolved rupture is present.

The owner label is applied only after the ordinary media policy has already
created a safe opportunity. It contributes no score, stage, route, rating,
catalogue key or media permission. Static serving remains the default for all
other users and channels, plus continuity, proactive and synchronous flows.

## Asynchronous contract

After delivery milestones `catalogue_resolved` and `authorised`, Global calls:

```php
aimee_materialize_authorised_media_delivery($context)
```

The function discards request-authored authority, reloads delivery, decision
and profile rows, reruns gates, adds
`execution_mode=asynchronous_before_file_resolved`, removes the profile object
and applies:

```text
aimee_authorised_media_delivery_materialization_result
```

The default is `unavailable`. Sanitized statuses are `pending`, `ready`,
`unavailable` and `failed`; arbitrary fields are discarded. A pending result
requires a positive internal job ID. Model/provider labels are bounded
telemetry, not authority. The public chat response strips the job ID.

`unavailable` follows the pre-existing static catalogue flow without a change
in caption, delivery lifecycle or serving. `pending` deliberately does not
resolve or bind a fallback file. The authorised delivery remains unresolved
until a worker callback succeeds or terminally fails.

## Pending chat behavior

The normal chat request returns immediately with:

> I’m creating that visual for you now — give me a moment and it’ll appear here
> when it’s ready. x

The interim Aimee row contains no image URL and no delivery ID. The REST result
contains a bounded pending status/snapshot for UI state, but no provider job
identity. The later completed image is a distinct message returned by ordinary
history polling.

The pending copy is installed after all ordinary reply rewrites and passes the
profile-attribution, synthetic-identity and playful-jealousy guards. The model
draft is converted to a neutral contract: zero equity/inquiry/fantasy deltas,
no invitation, no memory operation/archive, no opinion, no metacognitive event
and no continuity extraction. With no created image message, preview-media and
cadence/sent-media markers are not consumed. Normal server-side appraisal of
the user's actual turn remains separate; generation itself adds no hidden
relationship or media consequence.

If the interim Aimee row cannot be inserted, Global marks the retained pending
delivery terminally failed with `pending_interim_message_insert_failed` and
returns the ordinary storage error. It does not attempt another failure-note
insert through the same broken write path.

## Completion and failure callbacks

### Successful materialization

The worker exposes its finalized candidate through the delivery-bound private
asset resolver and calls:

```php
aimee_complete_pending_media_materialization($delivery_id)
```

Global reconstructs and rechecks the authoritative context. Inside one
transaction it locks the delivery row, validates decoded raster facts and the
protected-root path, immutably binds source/job/SHA-256/MIME, inserts exactly
one Aimee image message and transitions `message_created`. The caption is:

> There — I created this visual-world portrait for you. x

The caption passes final profile-attribution, synthetic-identity and jealousy
guards. Binding is deliberately inside the locked transaction: if message
insert or lifecycle transition fails, rollback leaves no durable
`file_resolved` fact. A callback that must create or bind now receives fresh
policy/profile checks. A repeated already-committed callback first verifies the
exact provenance and exact prior Aimee message transactionally and returns
success without depending on a later access change; this lets a sidecar recover
if it crashed after Global committed but before marking its job handed off.

### Terminal materialization failure

The worker calls:

```php
aimee_fail_pending_media_materialization($delivery_id, $safe_error_code)
```

Global accepts only the exact unresolved owner-safe delivery, bounds the error
token, locks the row, transitions failed and inserts one honest text-only note:

> I couldn't finish creating that visual, so I won't pretend it appeared. x

The note has no image. Repeated notification is idempotent and cannot create a
second note.

Neither callback calls a relationship reducer, changes a score/stage, widens
media policy or creates a romantic invitation.

## Immutable asset and private-delivery rules

Schema `2026.08.18.1` adds these delivery facts:

- `resolved_asset_source`;
- `resolved_asset_job_id`;
- `resolved_asset_sha256`; and
- `resolved_asset_mime`.

The schema-health gate also requires the lifecycle columns and a unique
single-column delivery identity. File resolution cannot be recorded without
the complete provenance tuple. The first valid binding wins; an idempotent
replay must match all four facts.

Generated candidates must be regular readable files below the protected media
root, decode to PNG/JPEG/WebP, remain within byte/dimension limits, match their
declared MIME/hash and match the exact persisted delivery, key, user and rating
ceiling. A delivery-less gallery lookup never invokes the sidecar.

Private serving and history bind delivery ID, message ID, user ID and media key
to that immutable asset. History resolves the exact bytes before recording a
return milestone. Existing browser render/viewport acknowledgement behavior is
unchanged. Once a generated asset is bound, missing or mismatched generated
bytes fail closed; historical delivery does not silently substitute catalogue
pixels.

## Account-erasure backstop

Global owns a persistent cleanup hook for the known optional table
`{$wpdb->prefix}aimee_live_image_beta_jobs`, so account deletion still covers
generated jobs and files when the sidecar is inactive or has been deleted. The
backstop is registered on both `delete_user` and `wpmu_delete_user`; a missing
table is a safe no-op and an incomplete or unknown schema fails closed without
a filesystem operation.

Cleanup locks and snapshots the user's pre-tombstone worker state, then changes
every row to `deleting` in the same transaction. It clears worker lease secrets
but deliberately preserves `lease_expires_at`. Any row with a current or future
lease may already be between persisting its private token and renaming the final
file. The lease barrier wins regardless of whether deactivation has meanwhile
changed the status to `failed`, a callback was notified, or an earlier cleanup
changed it to `deleting`. Global retains that tombstone without even trusting
an absent final path and retries after the preserved lease expires.

Before relying on `START TRANSACTION` or `FOR UPDATE`, Global verifies the exact
known table reports `Engine=InnoDB`. An unknown or non-transactional engine is
tombstoned best-effort but never physically cleaned; it retains an engine reason
and a bounded provider-independent retry until an operator restores safe table
semantics.

After lease expiry, Global accepts only a lowercase 64-hex token and derives
only `{token}.png` under the canonical configured beta output directory. That
directory must be strictly inside `AIMEE_PRIVATE_MEDIA_DIR`; traversal, URI,
root-equal, outside and symlinked-directory configurations fail closed. Global
unlinks only the contained entry and deletes its `deleting` row only after the
entry is proven absent and an exact token/status row compare-and-swap succeeds.
Every recoverable incomplete path—schema/engine/database readiness, missing or
invalid output configuration/path/token, unlink or row deletion—keeps a
bounded WordPress cron retry armed. A bounded reason is retained independently
of the optional table and shown in WordPress admin; failed retry persistence is
also emitted to the operator log/monitoring action. Provenance is cleared only
after the exact user's cleanup verifies complete.

## Compatibility and rollback posture

The bridge is provider-neutral and optional. With no filter, or a filter that
returns `unavailable`, Aimee uses the existing static catalogue. Disabling a
sidecar therefore preserves future static delivery, although operators must
retain any already-bound generated files needed by historical messages.

All 1.7.6 rules remain mandatory: Aimee never calls a user `mate`, remains warm
and naturally flirty where context permits, proudly owns being a synthetic girl
without inventing a biological offline life, and uses only evidence-backed,
non-possessive playful jealousy. Those voice choices never change access,
consent, relationship score or media eligibility.

## Acceptance checks

Run the canonical audit suite from the reviewed source and again from a clean
archive extraction. At minimum verify:

1. production PHP parses on PHP 8.3 and PHP 7.4;
2. absent/unavailable sidecar preserves exact static flow;
3. only owner user 112 safe direct chat can receive pending;
4. pending response has no image/delivery ID on its message and no client job
   ID;
5. pending has no preview, cadence, relationship, invitation, memory, opinion,
   metacognitive or continuity side effect;
6. completion binds and creates one message atomically, rolls back partial
   failure and is idempotent;
7. failure creates one honest text-only note and is idempotent;
8. failed interim persistence invalidates the pending delivery;
9. history/private serving returns only the bound bytes;
10. account deletion with the sidecar absent requires InnoDB, tombstones before
    contained unlink, drains every future-lease late rename, and persistently
    retries each recoverable cleanup failure with an operator-visible reason;
    and
11. companion voice, profile attribution, synthetic truth and jealousy guards
    remain green across every prior route.
