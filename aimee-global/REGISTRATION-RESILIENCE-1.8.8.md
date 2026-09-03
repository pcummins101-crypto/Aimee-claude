# Aimee Global 1.8.8 — registration resilience

## Why this release exists

The registration endpoint previously made remote vision and conversation
requests, then sent administrator email and an owner SMS, after the account and
profile had already been committed but before returning success to the browser.
A slow provider or upstream timeout could therefore make a real, usable account
look as though it had failed. Retrying with the same Login ID then produced the
deliberately generic existing-account response.

Version 1.8.8 separates account creation from optional post-commit work. The
browser receives a result after local validation, WordPress user creation,
private-photo storage when available, profile persistence, authentication and
one deterministic local opening message. Remote enrichment and operator
notifications run later through a deduplicated WordPress worker.

## Registration contract

- A new PIN remains exactly six ASCII digits and is passed to WordPress as the
  exact opaque string selected by the user, including a leading zero.
- The weak-code exclusions introduced in 1.8.7 remain in force for new
  accounts; historical credentials remain unrestricted at sign-in.
- No privacy-notice acknowledgement is required or manufactured. The omitted
  profile column uses its database default of `NULL`.
- Special-category consent remains optional, explicit and revocable.
- A valid optional photograph is stored only through the existing private
  media boundary. If that store fails, registration continues without the
  photo and its bytes are discarded before any vision task is scheduled.
- A new profile uses `INSERT`. A uniqueness or storage failure cannot invoke
  MySQL `REPLACE` semantics against another row.
- The request performs no remote AI, mail or carrier-SMS call after committing
  the account.

## Diagnostics and privacy boundary

Only operational failures create a diagnostic. The stored record contains
exactly four fields: UTC occurrence time, opaque `REG-...` reference,
allowlisted stage and allowlisted error category. It contains no request data,
identifier, PIN, name, user ID, IP address, image, exception text, SQL or raw
database/WordPress error.

Expected validation, throttling, reserved-number and existing-identifier
responses do not create a diagnostic. This preserves account-enumeration
resistance and prevents unauthenticated traffic from erasing the useful last
operational record. Administrators can inspect the record under **Settings →
Aimee Global → System status**.

## Deployment checks

1. Back up the plugin directory and database, install the exact 1.8.8 archive,
   and confirm the plugin and admin page both report `1.8.8` with schema
   `2026.08.20.3`.
2. Clear PHP opcode, WordPress object/page, optimisation, reverse-proxy/CDN,
   service-worker and browser caches.
3. In a private browser session, register with a genuinely unused generated ID,
   no photo and a non-predictable six-digit PIN. Confirm the request returns
   promptly, signs the user in and creates exactly one opening message.
4. Repeat with a valid photo. Confirm normal success. Then, in staging only,
   simulate private-photo storage failure and confirm registration still
   succeeds without submitting bytes to vision.
5. In staging, simulate WordPress-user and profile-insert failures. Confirm each
   returns an opaque reference, rolls back any incomplete identity and writes
   only the four approved diagnostic fields.
6. Confirm existing/reserved Login IDs retain the generic response and leave
   the last operational diagnostic unchanged.
7. Run `python3 tests/run-audit-suite.py` against the deployed source and again
   against a clean extraction of the release archive.

If live signup still fails, report only the `REG-...` reference plus the
stage/code from Aimee Global settings. Do not share the Login ID or PIN.
