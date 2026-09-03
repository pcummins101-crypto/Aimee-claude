# Aimee Global 1.8.8 test report

Release `1.8.8` keeps schema `2026.08.20.3` and changes the registration
completion boundary. A successful account no longer waits for model, email or
carrier-provider work before the REST response is returned.

## Registration evidence

- New registrations still require exactly six digits; existing accounts retain
  unrestricted historical sign-in compatibility.
- The privacy notice remains linked, but acknowledgement is not a condition of
  account creation. Optional special-category consent remains separate and
  revocable.
- Core schema health is checked before a WordPress identity is created.
- The new WordPress user and one new Aimee profile are persisted with `INSERT`;
  the registration path does not use destructive `REPLACE` semantics.
- Failure to store an optional profile photograph no longer deletes or blocks
  the account. The photograph is omitted and can be added later.
- The opening message is deterministic, local and limited to the submitted
  first name. It is persisted before the deferred worker is scheduled.
- Profile-photo interpretation, administrator email and the consent-gated owner
  SMS alert run only in a durable, deduplicated WP-Cron worker. No AI, mail or
  SMS call remains in the public registration handler.
- Operational failures receive an opaque `REG-...` reference. The read-only
  administrator diagnostic contains only timestamp, reference, allowlisted
  stage and allowlisted code; it never stores the Login ID, contact details,
  PIN, photograph, SQL text or raw exception.
- Reserved and existing-account checks retain the same non-enumerating public
  response.

Focused deterministic checks:

- registration runtime: `74/74` on PHP 8.3 and `74/74` on PHP 7.4;
- profile-attribution production wiring: `121/121` on PHP 8.3 and `121/121`
  on PHP 7.4;
- security/privacy static integration: `221/221`;
- broad static integration: `338/338`; and
- production PHP syntax: `48/48` on PHP 8.3 and `48/48` on PHP 7.4.

The focused registration fixtures cover unused identifiers, exact six-digit
PIN handling (including a leading zero), optional consent, optional-photo
storage degradation, profile-write rollback, opaque diagnostics, local success
completion, scheduler outage, scheduling deduplication, protected-file vision
enrichment, administrator email, verified owner SMS and duplicate-worker
at-most-once behaviour.

## Retained release boundaries

The following earlier controls remain in force and are included in the full
suite: GoCardless-only new UK membership checkout; unavailable new US checkout
and SMS bundles; legacy Stripe runoff only; creditor binding and webhook
processing; service grace; account-deletion and billing leases; authenticated
catalogue delivery; signed-in Camera Roll discoverability; explicit-media adult,
consent, membership and relationship gates; profile source separation; and PHP
7.4 compatibility.

## Canonical source and archive replay

- source audit: `16` command groups passed, `0` failed;
- source assertions: `5,341` passed, `0` failed;
- clean archive: `161` unique entries, `0` unsafe paths and `0`
  symlinks;
- ZIP integrity: passed; and
- clean-extraction audit: `16` command groups and `5,341` assertions passed,
  with `0` failures.

The final SHA-256 is published in the sibling `.sha256` manifest. The archive
contains one top-level `aimee-global/` plugin directory and no cache, temporary,
log, backup or nested archive debris.
