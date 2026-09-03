# Aimee Global 1.8.6 regression report

**Release date:** 21 August 2026  
**Status:** Final source and clean-archive replay verified  
**Plugin:** `1.8.6`  
**Schema:** `2026.08.20.3`  
**Relationship policy:** `2.2.0`

## Reproducible release evidence

The canonical cross-runtime command is:

```text
python3 tests/run-audit-suite.py
```

It runs the Python and browser regressions, the PHP-WASM suite on PHP 8.3 and
PHP 7.4, production PHP parsing, historical product regressions and the
focused public-catalogue, Camera Roll, controller-delivery and discoverability
contracts.

Release evidence from both the source tree and a fresh extraction of the exact
release archive:

- [x] source tree: **16 command groups; 5,075 assertions passed; 0 failed**;
- [x] clean archive: **16 command groups; 5,075 assertions passed; 0 failed**;
- [x] production PHP parse: **48/48 files on PHP 8.3 and 48/48 on PHP 7.4**;
- [x] focused Camera Roll: **171/171 static, 68/68 browser and 89/89 runtime
  checks on each PHP version**;
- [x] public catalogue: **25/25 static and 90/90 runtime checks on each PHP
  version**;
- [x] exact legacy fixture: **52/52 records classified once** across ten
  deterministic albums, comprising 44 safe, four reviewed flirty and four
  explicit records;
- [x] archive integrity: **156 unique entries, 0 unsafe paths and 0
  symlinks**; and
- [x] no cache/build debris such as `__pycache__`, `.pyc` or `.DS_Store`.

The digest is external because embedding an archive's own digest inside that
archive would change the bytes being digested.

## 1.8.6 repair coverage

The focused regressions prove:

- canonical application image URLs are controller-only in every storage mode;
- public catalogue storage never causes an application payload to emit a
  direct `/wp-content/aimee-private-media/...` URL;
- logged-out media requests receive the dedicated 401 response, while every
  signed-in request reloads the current profile and current per-item access;
- controller responses are private, non-cacheable, MIME-bounded, `nosniff` and
  same-origin resource protected;
- one invalid, missing, symlinked, escaped, MIME-mismatched or hash-mismatched
  record is skipped without hiding unrelated valid images;
- zero valid records still leaves the catalogue non-operational;
- degraded status uniquely counts invalid, missing and required-key failures;
- safe and reviewed flirty browsing remains available to signed-in profiles;
- explicit records still require current membership, verified adulthood,
  special-category consent, relationship maturity, interaction/session floors
  and absence of active or unresolved rupture;
- the legacy and fallback chats expose one market-correct, signed-in-only,
  touch-sized and accessible **Photos** entry, including the canonical
  `.app-header` and client-side remounts;
- public navigation identifies **Aimee’s Photos**, while the Camera Roll has a
  current-page link and a clear route back to chat; and
- an image that fails before or after the gallery script loads becomes an
  accessible temporary-unavailability state rather than broken alt text.

The complete suite also preserves the key-only, one-shot gallery discussion
handoff; current explicit-media entitlement across history, timeline, voice
and transfer consumers; GoCardless-only new UK checkout; fail-closed US and SMS
checkout; legacy-only Stripe runoff; schema hardening; service grace; privacy
choice lifecycle; and existing relationship, voice and media-delivery policy.

## Production boundary

The repaired application does not need static HTTP access to
`/wp-content/aimee-private-media`. Keep the historical directory deny rule in
place. If the web server or CDN is later configured to expose those files
directly, explicit bytes can bypass WordPress relationship and consent checks;
that operator-accepted exposure cannot be repaired in application code.

The current production manifest reports zero SHA-256 values. File-size, MIME,
image-dimension, basename, root-containment and symlink checks remain enforced,
but reviewed hashes would additionally detect same-name byte substitution.

Deterministic tests cannot prove production cache state, filesystem contents,
MariaDB state, live GoCardless credentials, creditor configuration or webhook
delivery. Complete the production checks in
`GALLERY-DELIVERY-DISCOVERABILITY-1.8.6.md`, `CONFIGURATION.md` and
`GOCARDLESS-ONLY-CHECKOUT-1.8.4.md` after installation.
