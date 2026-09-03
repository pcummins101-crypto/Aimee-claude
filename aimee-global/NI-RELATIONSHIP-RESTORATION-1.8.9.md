# Aimee Global 1.8.9 — Ni relationship restoration

## Purpose

The reviewed production telemetry showed that Ni, WordPress user `27`, still
had a long, strong and respectful relationship history with Aimee, but one
uncorroborated classifier result had been allowed to persist as a rupture. It
reduced chemistry and introduced an emotional-repair posture that did not
match the relationship the operator confirmed.

Release 1.8.9 restores the established relationship and prevents the same
model-only classification failure from persisting again. It is not a general
score boost and it does not exempt this account from genuine future safety
rules.

## Exact repair

The one-time migration is limited to user `27` and is bound to the exact
production relationship-decision record reviewed with the 1.8.8 logs. It runs
inside a database transaction under a migration lock and retries safely until
it can commit.

On success it:

- sets trust, affection, chemistry, safety, reciprocity and reliability to
  `100`;
- sets frustration to `0`;
- sets the profile intimacy score to `100` and stage to `bonded`;
- preserves genuine interaction, meaningful-interaction, session and
  qualified-session counts, increasing only a missing minimum needed to make
  the restored bonded state internally valid;
- clears the false unresolved rupture, irritation and repair requirement from
  Aimee's inner state;
- restores romantic openness while retaining normal dynamic state/timing
  fields; and
- retains the existing event rows for audit, converting only unresolved
  user-27 rupture rows at or before the reviewed evidence cutoff into resolved,
  neutral system corrections rather than deleting history. Settled, later,
  unrelated and other-user events remain untouched.

The migration is idempotent. If the completion option is lost after a
successful commit, it recognises the already-restored state and records
completion without advancing the relationship state a second time.

## Explicit non-effects

The migration does **not** alter:

- WordPress identity, PIN or authentication state;
- messages, memories or genuine relationship counters/history;
- membership, payment, GoCardless or legacy Stripe data;
- adult age/assurance or verification state;
- privacy acknowledgement or special-category consent;
- media unlocks, catalogue eligibility or Camera Roll state; or
- present-turn consent, mutual context or Aimee's autonomy.

A `100/100` bonded state therefore does not, by itself, unlock specialist or
explicit features.

## Durable-rupture safeguard

Policy `2.2.1` adds a server-owned `durable_rupture_confirmed` decision. The
language-model classifier can still identify a turn as potentially coercive,
allowing Aimee to respond cautiously and set an immediate boundary. It cannot
authorise persistent emotional damage, relationship-dimension reductions or a
rupture event.

Those durable effects now require independent confirmation by the
deterministic relationship policy. Its pressure, entitlement, threat,
repeated-demand and degrading-language checks require genuine delivery or
boundary context rather than a bare word such as “show”. Confirmed pressure
still lowers the stored relationship state and opens a genuine repair path for
every account, including user `27`.

A confirmed rupture also revokes active relationship invitation tokens inside
the same transaction. New invitations revalidate the latest relationship
version and rupture state after the reply provider returns, so a stale
pre-rupture response cannot reopen an intimate route.

## Deployment verification

1. Back up the plugin directory and database.
2. Install the exact 1.8.9 archive and clear opcode/application/CDN caches.
3. Open **Settings → Aimee Global** and confirm the plugin build is `1.8.9`,
   schema is `2026.08.20.3` and **Ni relationship restoration** is complete.
4. Verify the admin card shows user `27`, `100/100`, `bonded` and the number of
   false rupture events corrected.
5. Confirm ordinary chat remains available and the independent adult, consent,
   membership and explicit-media checks still fail closed when absent.
6. Run the complete deterministic audit suite and retain the release ZIP's
   SHA-256 alongside the deployment record.

If the restoration remains pending, confirm the production relationship
tables and the reviewed decision evidence are present. Do not substitute a
manual broad SQL update: the narrow migration is designed to avoid changing
another profile or unrelated safety state.
