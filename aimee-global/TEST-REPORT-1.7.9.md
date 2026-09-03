# Aimee Global 1.7.9 test report

Release date: 19 August 2026  
Status: source tree and final clean archive verified  
Plugin: `1.7.9`  
Schema: `2026.08.18.2` unchanged  
Relationship policy: `2.1.0` unchanged  
Romantic-expression policy: `1.2.0` unchanged

## Executed release evidence

The reproducible native command is:

```text
python3 tests/run-native-audit-suite.py
```

Source-tree result:

- **25/25 commands passed**;
- **2,212/2,212 assertions passed**;
- **45/45 production PHP files parsed**; and
- **0 assertion failures**.

Final clean extracted-archive result:

- **25/25 commands passed**;
- **2,212/2,212 assertions passed**;
- **45/45 production PHP files parsed**; and
- **0 assertion failures**.

The executed environment used PHP `8.4.23`, Node `22.16.0` and Python `3.13.5`.

## 1.7.9 focused verification

### Exact romantic choice contract

`tests/romantic-expression-regression.php` passed **267/267** checks. New
coverage proves that:

- the ordinary prompt exposes the exact action, intensity and reason map;
- the provider retry receives the same current-turn map and rejected draft;
- a visible reciprocal reply with an invalid reason token is preserved while
  only its metadata is normalized;
- a readable neutral reply with absent metadata becomes a valid hold rather
  than a public fallback;
- an explicitly selected flirt that vanished from the prose requires provider
  regeneration and is not silently downgraded;
- a selected jealousy tease without a safe jealous beat requires regeneration;
- a visible flirt cannot be falsely persisted as a hold;
- a genuine friend-zone redefinition remains blocked;
- `your mate Dave` is not treated as a relationship label;
- direct `Thanks mate` and `Thanks, mate` wording remains blocked on an active
  romantic opportunity; and
- the last-resort romantic wording acknowledges the received turn without
  asking the user to repeat it.

### Production pipeline wiring

`tests/profile-attribution-wiring-regression.php` passed **120/120** checks. New
wiring assertions prove that:

- initial, provider-retry and post-attribution candidates all pass through the
  same reconciliation helper;
- post-attribution reconciliation occurs before raw reply processing and
  persistence;
- normalized and hard-fallback outcomes remain inspectable in route telemetry;
- the neutral attribution contract uses a hold reason valid during an active
  opportunity; and
- the old `That came out wrong. Give me that again` romantic fallback is absent
  from the production handler.

### Preserved production surface

The static production-wiring suite passed **327/327** checks. All inherited
relationship, intimacy, synthetic-identity, profile attribution, user-image,
media policy, media cadence, delivery, materialization, erasure, billing,
service-grace, colleague, voice and browser regressions remain green.

The full native total includes:

- **70** deterministic relationship-policy assertions, with all **44** expected
  scenario-policy summaries reproduced;
- **327** static production-wiring assertions;
- **26** rendered browser-script assertions; and
- **1,789** standalone PHP assertions across every shipped PHP regression.

## Incident-specific conclusion

The reproduced failure marker was a response-validation error, not memory loss.
Version 1.7.9 keeps safe prose when structured romantic metadata is recoverable,
uses a provider rewrite when an explicit expressive choice is missing from the
prose, and reserves the deterministic hard fallback for empty or genuinely
unsafe output.

No database migration or stored-memory rewrite is performed by this release.

## Canonical PHP-WASM runner limitation

The canonical command remains:

```text
python3 tests/run-audit-suite.py
```

It was invoked in this environment. Its policy, static and browser groups passed
**423 assertions with zero failures**, but the three PHP-WASM command groups
could not start because `/tmp/aimee-php-wasm/node_modules` was unavailable.
This is an environment bootstrap failure rather than a PHP assertion failure.

Accordingly, this report does not claim a new PHP 8.3/PHP 7.4 WASM replay. The
changed source uses PHP 7.4-compatible syntax and the package header still
requires PHP 7.4, but the evidence executed for 1.7.9 is the complete native PHP
8.4 suite above. The earlier 1.7.7 package retains its separately recorded PHP
8.3/PHP 7.4 evidence.

## Manual staging still required

Before production:

1. Exercise the same light affectionate route that previously emitted
   `romantic_choice_repair=failed; romantic_post_repair_guard=fallback`.
2. Verify no repeated public fallback occurs across several consecutive turns.
3. Inspect telemetry for normalized, provider-repaired and hard-fallback paths.
4. Verify memory retrieval and relationship continuity before and after the
   upgrade.
5. Test genuine platonic boundaries and unsafe jealousy to confirm they still
   fail closed.
6. Inspect persisted relationship decisions and message rows, not only the
   browser reply.
