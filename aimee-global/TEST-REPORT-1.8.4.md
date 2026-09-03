# Aimee Global 1.8.4 regression report

**Release date:** 20 August 2026  
**Status:** Final source and clean-archive replay verified  
**Plugin:** `1.8.4`  
**Schema:** `2026.08.20.3`  
**Relationship policy:** `2.2.0`

## Reproducible release evidence

The canonical cross-runtime command is:

```text
python3 tests/run-audit-suite.py
```

It runs the Python and browser regressions, the PHP-WASM suite on PHP 8.3 and
PHP 7.4, production PHP parsing, historical product regressions, and the
focused 1.8.4 GoCardless-only checkout contract.

Release evidence from both the source tree and a fresh extraction of the exact
release archive:

- [x] source tree: **12 command groups; 4,425 assertions passed; 0 failed**;
- [x] production PHP parse: **47/47 files on PHP 8.3 and 47/47 on PHP 7.4**;
- [x] clean archive: **12 command groups; 4,425 assertions passed; 0 failed**;
- [x] archive integrity: **143 unique entries, 0 unsafe paths, 0 symlinks**;
- [x] no cache/build debris such as `__pycache__`, `.pyc` or `.DS_Store`; and
- [x] final archive SHA-256 published in the sibling `.sha256` manifest and
  with the delivered archive.

The digest is intentionally external: embedding an archive's own digest inside
that archive would change the bytes being digested.

## 1.8.4 checkout coverage

The focused cutover regression proves:

- all production PHP contains **zero direct Stripe Checkout Session creation
  calls**;
- authenticated UK/GBP membership checkout dispatches only to a configured,
  creditor-bound GoCardless flow;
- US paid membership and new SMS-bundle requests fail closed before provider
  creation, with no Stripe fallback;
- pricing, landing, FAQ, shared chat and legacy global entry points expose no
  actionable US/Stripe checkout path;
- preserved, goodwill and service-grace access is reflected consistently in
  both the subscription snapshot and the GoCardless server handler, including
  the server-owned checkout opening time;
- ambiguous pre-cutover recurring intents and `creating_...` SMS placeholders
  are never replayed and instead require operator reconciliation;
- caller input cannot route a GoCardless-owned profile into Stripe status
  handling unless it exactly matches a Stripe Session already bound locally;
  and
- legacy Stripe status, cancellation, portal, signed webhook, fulfilment,
  expiration, provider-transition and account-deletion controls remain exact
  and owner-bound for pre-cutover records.

The full suite also preserves the 1.8.3 schema, privacy, authentication,
private-media, adult-assurance, consent, relationship, SMS-delivery, deletion,
GoCardless ledger/idempotency and MariaDB failure/retry contracts.

## Limits of deterministic evidence

Source tests cannot prove production credentials, a live creditor's enabled
schemes, provider network behavior, webhook delivery, a real MariaDB upgrade,
or previously issued Stripe-hosted URLs. Before production, complete the
cutover inventory and sandbox matrix in `CONFIGURATION.md` and
`GOCARDLESS-ONLY-CHECKOUT-1.8.4.md`. Keep the Stripe webhook and credentials
available for controlled legacy runoff, manually reconcile ambiguous old
intents without replay, expire every unwanted open Stripe Session, and verify
that Stripe's Checkout Session creation count remains flat.
