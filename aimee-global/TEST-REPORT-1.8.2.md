# Current regression report: Aimee Global 1.8.2

**Release date:** 20 August 2026  
**Status:** Source tree verified; source tree and final clean archive verified  
**Plugin:** `1.8.2`  
**Schema:** `2026.08.20.1`  
**Relationship policy:** `2.1.0` unchanged  
**Romantic-expression policy:** `1.2.0` unchanged

## Executed release evidence

The reproducible native audit command is:

```text
python3 tests/run-native-audit-suite.py
```

Source-tree result:

- **25/25 commands passed**;
- **2224/2224 assertions passed**;
- **46/46 production PHP files parsed**; and
- **0 assertion failures**.

The static production-wiring suite passed **337/337** checks.
Three new assertions prove the push privacy column no longer uses MariaDB's
reserved `SENSITIVE` identifier, legacy-column migration preserves the privacy
bit, and auxiliary schema maintenance is versioned, health-checked and backed off
after a failure.

The romantic-expression suite remains green at **269/269** checks, including the
prose-authority regressions for `aimee_playful_interest` and
`aimee_affectionate_initiative`.

## Production log defect addressed

The observed `CREATE TABLE aimee_push_notifications` error pointed at the bare
`sensitive TINYINT(1)` field. This release renames that database field to
`is_sensitive`, updates all runtime reads/writes, migrates a legacy field if
present, and prevents failed auxiliary schema work from executing on every
request.

The separate PHP warning for `AIMEE_ELEVENLABS_VOICE_ID` cannot be removed by a
plugin update because the duplicate definition is in `wp-config.php`. The plugin
contains no `define()` for that constant.

## GoCardless branch

This build retains the hardened GoCardless Recurring Pay by Bank lifecycle from
1.8.1. The payment integration remains covered by the same native suite; this
schema repair does not loosen payment confirmation, webhook idempotency, mandate
cancellation or membership-extension controls.

## Runtime note

The executed environment used PHP `8.4.23`, Node `22.16.0` and Python `3.13.5`.
Real production verification should confirm the MariaDB error disappears after
the first successful upgrade request.
