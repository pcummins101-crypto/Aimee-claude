# Aimee Global 1.8.10 test report

Release `1.8.10` keeps schema `2026.08.20.3` and relationship policy `2.2.1`.
Its only production-code changes are customer-facing goodwill-entitlement
state handling in the legacy chat bridge and the UK, US and shared pricing
templates.

## Defect reproduced

The regression fixture deliberately combines all of these valid server facts:

- `access_active: true`;
- `access_source: goodwill_extension`;
- a future `bonus_access_until` / `access_until`;
- `checkout_available: false`; and
- `new_subscription_required: true` plus `requires_reactivation: true`.

Version 1.8.9 used the last two billing flags to mount the expired-August
membership card despite live access. The 1.8.10 runtime regression proves that
goodwill now suppresses that card, removes the reactivation body state, keeps
unrelated chat content, updates membership elements mounted later by the app,
refreshes at the entitlement boundary, preserves genuine billing-management
access where one exists, and restores the original interface after the server
reports an ordinary state. A transition to managed billing renders the new
authoritative membership state; an expired grant restores the real prompt.

## Source audit result

`python3 tests/run-audit-suite.py` completed successfully:

- command groups: `17` passed, `0` failed;
- assertions: `5,792` passed, `0` failed;
- goodwill UI static regression: `44/44`;
- chat notice runtime regression: `46/46`;
- main static integration regression: `342/342`;
- production PHP syntax: `48/48` on PHP 8.3 and PHP 7.4.

The suite also re-ran the existing GoCardless, billing migration, relationship,
privacy/security, registration, media, Camera Roll and account-erasure
regressions. No database schema, access predicate, provider request, billing
record, SMS allowance, relationship state or safety gate is changed by this
release.

## Clean-archive checklist

1. Verify ZIP integrity and reject duplicate, absolute, parent-traversal or
   symbolic-link entries.
2. Extract to a new empty directory.
3. Confirm the archive contains one top-level `aimee-global/` directory and
   reports plugin version `1.8.10`.
4. Run `python3 tests/run-audit-suite.py` from that clean extraction.
5. Compare the archive against its sibling SHA-256 manifest before deployment.
