# Aimee Global 1.8.3 regression report

**Release date:** 20 August 2026  
**Status:** Final release archive verified  
**Plugin:** `1.8.3`  
**Schema:** `2026.08.20.3`  
**Relationship policy:** `2.2.0`

## Reproducible release evidence

The canonical cross-runtime command is:

```text
python3 tests/run-audit-suite.py
```

It runs the Python and browser regressions, the PHP-WASM suite on PHP 8.3 and
PHP 7.4, legacy frozen product regressions, production PHP parsing, and the
focused 1.8.3 schema, privacy, GoCardless and release-hardening checks.

Release evidence from the source tree and a clean archive extraction:

- [x] source tree: **11 command groups; 4,403 assertions passed; 0 failed**;
- [x] production PHP parse: **47/47 files on PHP 8.3 and 47/47 on PHP 7.4**;
- [x] clean archive: **11 command groups; 4,403 assertions passed; 0 failed**;
- [x] archive integrity: **140 entries, 0 unsafe paths, 0 symlinks**; and
- [x] final archive SHA-256 published with the deliverable.

## 1.8.3 hardening coverage

The included regressions cover:

- exact MariaDB column/index/InnoDB contracts, bounded installer locks,
  retryable partial upgrades and safe legacy message primary-key layouts;
- recurring Stripe intent-before-POST state, stable idempotency, exact stored
  session/intent/plan/market/generation completion and stale webhook rejection;
- GoCardless creditor binding, durable Billing Request and flow replay,
  immutable per-payment terms, webhook/provider reconciliation, cancellation,
  renewal retry and complete account-deletion retirement;
- immutable SMS-bundle generation, market, currency and product terms, with
  legacy incomplete pending rows refused rather than reconstructed;
- the single billing/market/deletion lease and durable account-deletion
  tombstone across webhooks, renewals, consent, media and voice workers;
- canonical profile-market locking with no WordPress user-meta market authority;
- private profile, catalogue and voice storage outside public roots, exact
  manifest/hash migration, fail-closed enumeration and verified erasure;
- runtime mbstring/fileinfo/image-function requirements, explicit privileged
  user IDs, alias-aware authentication throttling, consent/version withdrawal
  and server-owned adult assurance; and
- preserved romantic-expression, relationship, media, voice, image-event,
  service-grace, colleague, synthetic-identity and historical product behavior.

Focused provider-binding and billing-migration harnesses can also be replayed
directly with:

```text
node tests/run-php-wasm.mjs \
  tests/gocardless-creditor-binding-regression.php \
  tests/billing-migration-hardening-regression.php
```

## Limits of source evidence

Static and deterministic tests cannot prove production credentials, network
behavior, web-server path boundaries, a live MariaDB migration, provider-side
idempotency or webhook delivery. Before production, complete the deployment
matrix in `CONFIGURATION.md` with a cloned MariaDB database, GoCardless sandbox,
Stripe test mode, signed provider webhooks and the target host's private-storage
permissions. Confirm the exact configured owner and Georgia IDs, and verify that
private catalogue media is either manifest-provisioned or deliberately disabled.
