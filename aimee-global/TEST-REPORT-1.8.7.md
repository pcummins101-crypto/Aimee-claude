# Aimee Global 1.8.7 regression report

**Release date:** 21 August 2026  
**Status:** Final source and clean-archive replay verified  
**Plugin:** `1.8.7`  
**Schema:** `2026.08.20.3`  
**Relationship policy:** `2.2.0`

## Reproducible release evidence

The canonical cross-runtime command is:

```text
python3 tests/run-audit-suite.py
```

It runs the Python and executable browser regressions, the PHP-WASM suite on
PHP 8.3 and PHP 7.4, production PHP parsing, historical product regressions and
the focused authentication, privacy, catalogue, Camera Roll, controller and
checkout contracts.

Release evidence from both the source tree and a fresh extraction of the exact
release archive:

- [x] source tree: **16 command groups; 5,131 assertions passed; 0 failed**;
- [x] clean archive: **16 command groups; 5,131 assertions passed; 0 failed**;
- [x] production PHP parse: **48/48 files on PHP 8.3 and 48/48 on PHP 7.4**;
- [x] focused security/privacy: **190/190 static, 40/40 browser bridge,
  18/18 optional-privacy and 73/73 runtime checks on each PHP version**;
- [x] focused Camera Roll: **171/171 static, 68/68 browser and 89/89 runtime
  checks on each PHP version**;
- [x] public catalogue: **25/25 static and 90/90 runtime checks on each PHP
  version**;
- [x] exact legacy fixture: **52/52 records classified once** across ten
  deterministic albums, comprising 44 safe, four reviewed flirty and four
  explicit records;
- [x] archive integrity: **158 unique entries, 0 unsafe paths and 0
  symlinks**; and
- [x] no cache/build debris such as `__pycache__`, `.pyc` or `.DS_Store`.

The digest is external because embedding an archive's own digest inside that
archive would change the bytes being digested.

## 1.8.7 repair coverage

The focused regressions prove:

- new registration accepts exactly six ASCII digits and does not cast, trim or
  normalize the opaque passcode, preserving a leading zero;
- new registration rejects `123456`, `654321`, `012345` and every repeated
  single-digit code;
- the fallback and theme-owned registration forms apply matching exact-six
  controls, while all existing-account sign-in fields remain format-free;
- historical six-digit and longer-passphrase credentials continue through the
  unchanged alias-aware per-account and per-IP authentication throttle;
- registration has no privacy-acknowledgement requirement and stores no
  manufactured acknowledgement timestamp;
- neither browser interface can force open or float an acknowledgement gate,
  prevent closing settings or require acknowledgement before ordinary chat;
- the privacy notice remains visibly linked in onboarding and settings;
- fallback-native privacy controls are not duplicated by the legacy bridge;
- special-category consent remains explicit, optional, independently
  grantable and immediately revocable; and
- verified adulthood, current consent, membership, relationship maturity,
  rupture and per-item controls remain required for every dependent specialist
  or explicit path.

The complete suite also preserves controller-only application image delivery,
per-item catalogue degradation, the ten Camera Roll albums, one-shot
server-authorised photo discussion, GoCardless-only new UK checkout,
fail-closed US and SMS checkout, legacy-only Stripe runoff, schema hardening,
service grace, relationship policy, voice behavior and media-delivery truth.

## Production boundary

No database migration or password reset is required. Existing WordPress hashes
are not rewritten. After installing the release, clear PHP opcode, WordPress
object/page, proxy/CDN, optimization, service-worker and browser caches. An old
12-character registration message or an acknowledgement prompt means stale
code remains in the delivery path.

The deterministic suite cannot prove production cache state, MariaDB state,
live GoCardless credentials, creditor configuration, webhook delivery or the
real theme's current HTML. Complete both theme-owned and fallback browser
journeys in staging, then follow the production checks in
`PIN-AND-PRIVACY-ONBOARDING-1.8.7.md`, `CONFIGURATION.md` and
`GOCARDLESS-ONLY-CHECKOUT-1.8.4.md`.
