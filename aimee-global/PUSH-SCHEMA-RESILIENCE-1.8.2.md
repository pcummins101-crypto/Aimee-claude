# Aimee Global 1.8.2 — MariaDB push-schema resilience

## Why this release exists

Production logs showed MariaDB rejecting `aimee_push_notifications` during
`dbDelta()` because the table used an unquoted column named `sensitive`.
`SENSITIVE` is a MariaDB reserved word, so the table definition failed and the
legacy engine retried the same schema work on later requests.

## Fix

- The persisted push privacy flag is now `is_sensitive`.
- Runtime reads and writes use `is_sensitive`; user-facing `sensitive_preview`
  preference naming is unchanged.
- If a prior environment somehow created the legacy `sensitive` column, the
  upgrader renames it safely. If an interrupted upgrade leaves both names, the
  privacy bit is merged before the reserved legacy column is dropped.
- Auxiliary legacy-engine schema maintenance now has its own durable version.
- A failed auxiliary schema upgrade backs off for 15 minutes instead of rerunning
  expensive `SHOW`, `ALTER` and `dbDelta()` work on every request.
- The auxiliary schema version is only marked current after both push tables exist
  and the notification table contains `is_sensitive` with no legacy `sensitive`
  column remaining.

## ElevenLabs warning

The production warning `Constant AIMEE_ELEVENLABS_VOICE_ID already defined` is
not emitted by this plugin. Aimee Global only reads that constant. The duplicate
`define()` is in `wp-config.php` and must be removed there (or the duplicate
definition guarded with `if (!defined(...))`).

Schema version: `2026.08.20.1`.
