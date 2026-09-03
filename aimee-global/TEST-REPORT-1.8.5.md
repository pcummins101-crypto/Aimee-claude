# Aimee Global 1.8.5 regression report

**Release date:** 20 August 2026  
**Status:** Final source and clean-archive replay verified  
**Plugin:** `1.8.5`  
**Schema:** `2026.08.20.3`  
**Relationship policy:** `2.2.0`

## Reproducible release evidence

The canonical cross-runtime command is:

```text
python3 tests/run-audit-suite.py
```

It runs the Python and browser regressions, the PHP-WASM suite on PHP 8.3 and
PHP 7.4, production PHP parsing, historical product regressions and the
focused 1.8.5 public-catalogue, Camera Roll, privacy and chat-handoff
contracts.

Release evidence from both the source tree and a fresh extraction of the exact
release archive:

- [x] source tree: **16 command groups; 5,009 assertions passed; 0 failed**;
- [x] clean archive: **16 command groups; 5,009 assertions passed; 0 failed**;
- [x] production PHP parse: **48/48 files on PHP 8.3 and 48/48 on PHP 7.4**;
- [x] focused Camera Roll: **130/130 static, 59/59 browser handoff and 89/89
  runtime on each PHP version**;
- [x] public catalogue mode: **24/24 static and 83/83 runtime on each PHP
  version**;
- [x] exact legacy fixture: **52/52 records classified once** across ten
  deterministic albums, comprising 44 safe, four reviewed flirty and four
  explicit records;
- [x] archive integrity: **154 unique entries, 0 unsafe paths and 0
  symlinks**; and
- [x] no cache/build debris such as `__pycache__`, `.pyc` or `.DS_Store`.

The digest is external because embedding an archive's own digest inside that
archive would change the bytes being digested.

## 1.8.5 catalogue and conversation coverage

The focused regressions prove:

- public mode requires the exact operator acknowledgement and resolves only
  `WP_CONTENT_DIR/aimee-private-media/catalog.json`, with containment,
  regular-file, MIME, dimensions and live-byte validation;
- public-mode media is retained in place, while the protected catalogue mode
  remains the default when the acknowledgement is absent;
- every signed-in Aimee profile can browse safe records and the four exact
  reviewed legacy flirty records, subject to each record's assurance floor;
- future unreviewed non-safe records are not widened merely by their rating;
- explicit records require current membership, verified adulthood, current
  special-category consent, relationship maturity, interaction/session floors
  and absence of active or unresolved rupture;
- gallery, catalogue API, history, timeline, voice-note status and the media
  controller share the same current predicate, so historical delivery does
  not bypass a later downgrade or consent withdrawal;
- the browser transfers only a key and timestamp, keeps the key out of the
  URL, expires it after ten minutes and consumes it once before dispatch;
- uploads, voice, cancellation and dispatch clear the reference, and an
  existing draft is preserved;
- the server rejects hidden or stale keys and mixed upload/reference requests,
  resolves all descriptions and album context from the current canonical
  catalogue, then reauthorises immediately before the reply provider;
- a catalogue-discussion turn cannot send, resend, attach or unlock media and
  cannot create durable memory from invented visual-world detail; and
- saving the required privacy acknowledgement dismisses the floating panel
  without forcing optional special-category consent.

The complete suite also preserves the 1.8.4 GoCardless-only new UK membership
checkout contract, fail-closed new US and SMS checkout, legacy-only Stripe
runoff, signed webhooks, deletion, schema hardening, service grace and the
existing relationship, voice and media-delivery invariants.

## Operational boundary

With `AIMEE_PUBLIC_MEDIA_CATALOGUE_MODE` set to `operator_approved`, every
static file under `/wp-content/aimee-private-media` can be fetched directly if
the web server permits it. That includes explicit image bytes and the public
catalogue's filenames. Application gates still control what Aimee reveals in
the product, but cannot protect a known direct URL. URL-level protection
requires moving explicit bytes behind authenticated delivery or adding
web-server/CDN rules.

Deterministic source tests cannot prove production filesystem permissions,
CDN/cache behavior, MariaDB state, live GoCardless credentials, creditor
configuration or webhook delivery. Complete the production checklist in
`PUBLIC-CATALOGUE-PRIVACY-1.8.5.md`, `CONFIGURATION.md` and
`GOCARDLESS-ONLY-CHECKOUT-1.8.4.md` after installation.
