# Aimee 1.7.1 courtship, intimacy and media audit — regression report

Date: 2026-08-01  
Tree tested: `work/aimee-global`  
Result: **5 commands passed; 884 assertions passed; 0 failed**

## One-command run

```bash
python3 work/aimee-global/tests/run-audit-suite.py
```

Final output:

```text
AUDIT SUITE RESULT: 5 commands passed, 0 failed; 884 assertions passed, 0 failed
```

## Exact commands and counts

| Command | Result | Assertions |
|---|---:|---:|
| `python3 work/aimee-global/tests/intimacy-policy-simulation.py` | Pass | 70, plus 44 deterministic scenario-policy summaries matching committed JSON/CSV |
| `python3 work/aimee-global/tests/static-integration-regression.py` | Pass | 183 |
| `node work/aimee-global/tests/run-php-wasm.mjs tests/intimacy-media-policy-regression.php` | Pass on PHP 8.3 | 263 |
| `AIMEE_PHP_VERSION=7.4 node work/aimee-global/tests/run-php-wasm.mjs tests/intimacy-media-policy-regression.php` | Pass on PHP 7.4 | 263 |
| `node work/aimee-global/tests/run-php-wasm.mjs tests/consciousness-voice-regression.php tests/photo-deletion-memory-gap-regression.php tests/photo-delivery-truth-regression.php tests/photo-request-regression.php tests/public-statement-voice-regression.php tests/suggestive-photo-autonomy-regression.php` | Pass on PHP 8.3 | 105 |
| **Total** | **Pass** | **884** |

The two PHP policy runs intentionally execute the same 263 assertions against both the minimum supported PHP line and the current PHP line. In addition, the production PHP AST check parsed all **14/14** files under PHP 7.4 and PHP 8.3. PHP-WASM is used because the host has no native PHP CLI.

## Packaged-archive replay

The final `aimee-global-1.7.1-feedback-and-courtship-release.zip` was extracted
into a fresh temporary directory and the same one-command suite was run from
the extracted plugin. It again passed **884/884** assertions. The extracted
tree also parsed **14/14** production PHP files as both PHP 7.4 and PHP 8.3.
Generated `__pycache__`, `.pyc`, `.pyo` and `.DS_Store` files are excluded from
the archive.

## Required-scenario coverage

| Required behaviour | Executable evidence |
|---|---|
| Courtship rewards substance instead of generic praise | Exact proposed, pre-cap production-vector tests cover stock `T0/A1/C1` non-meaningful; appearance `T1/A1/C2/S1`; capability `T2/A1/Rcp1`; personality `T2/A2/S1`; sincere understanding `T2/A1/Rcp2/S1`; grounded follow-through `T2/A1/Rcp1/Rel1/S1`; and substantive romantic flirt `T1/A1/C2/S1`, where A is affection and S is safety. Natural “Your eyes…”, “Your ability…” and “Your character…” wording is detected without requiring an “I admire…” template |
| Courtship cannot stack several trust-bearing labels on one turn | Classifier/reducer tests permit at most one primary trust-bearing courtship event per turn |
| Reworded praise cannot farm trust indefinitely | Concept novelty tests cover 64 relationship-event records and weights of 1, 0.25 and 0 for a first occurrence, first same-concept repeat and later same-concept repeats |
| Unsafe courtship framing cannot earn positive credit | Deterministic photo/payment leverage, coercion, hostility, non-consent, quoted third-party praise and relationship-score-gaming veto tests; personal-inner-experience correction preserves pre-existing non-consensual/disrespectful flags and cannot downgrade pressure/entitlement |
| Trust reflects relationship maturity | Positive ceilings are 8 before any qualified meaningful session, then 40/60/75/90/100; session qualification and six-hour separation tests establish a 24-hour minimum and a 47-message theoretical fastest trust staircase |
| Stages require trust as well as score/evidence | Guarded/warm/flirty/intimate/bonded trust floors are 0/12/25/40/65 |
| Varied courtship develops without manufacturing consent | Executed trace reaches warm 13, flirty 29, intimate 49 and trust 100 on message 55, but never bonded or the specialist because trust is not chemistry or consent |
| Appearance-only praise cannot simulate a whole relationship | Executed trace reaches warm 7, flirty 17 and intimate 32, ends at T58/C100/score87, and never reaches bonded, trust 100 or the specialist |
| Mature reference conversation remains viable | Executed trace reaches warm 23, flirty 32, intimate 44 and the specialist on message 47 |
| Respectful new user cannot reach erotic routing unrealistically quickly | Scenario simulator specialist floor and novelty assertions; stage/session gate PHP assertions |
| New subscriber gains access, not artificial intimacy | Simulator subscription trace; specialist payment-only rejection; media decision new-subscriber tests |
| Bonded returning user is not treated as a stranger | Simulator preserves `bonded` immediately, rejects a 240-hour-stale invitation and routes on message 2 only after a fresh immediately preceding Aimee invitation; stage hysteresis and migration/static wiring checks |
| Direct image requests are detected | Legacy `photo-request-regression.php`; deterministic direct safe/flirty policy tests |
| Indirect image opportunities are detected | Proactive indirect romantic policy test; suggestive autonomy legacy regression |
| Respectful restraint can support proactive send | Deterministic `eligible_respectful_restraint` test |
| Coercion blocks a send | Payment/coercion detector, classifier non-downgrade, hard media veto and model-override tests |
| Intimate specialist creates a genuine image opportunity | Suggestive specialist and verified proactive erotic opportunity tests |
| Eligible media is not silently stripped | Static main-handler checks for persisted choice, exact key binding, sequential milestones and honest repair on failure |
| Failed delivery does not become false successful memory | Delivery prerequisite, phase, public snapshot and seven-level memory wording tests |
| Promised image is delivered or honestly acknowledged | Grounded source-opportunity, `delivery_pending`, history-return completion and honest decline/failure static checks |
| Aimee may initiate flirty/erotic/explicit media in eligible mutual context | Proactive flirty, specialist suggestive, verified erotic and bonded explicit opportunity tests |
| Aimee may decide not to send without a false technical excuse | Model decline test records `aimee_not_in_mood`; continuity wording checks distinguish choice from failure |
| Cancellation preserves relationship state | Simulator cancellation trace, pure access-state test and cancellation-handler static check |
| Membership never manufactures consent | Policy assertion fields, new-subscriber tests, relationship specialist floors and payment-pressure veto |
| Repeated trigger phrases cannot game progress | Exact repetition and 64-record concept-novelty tests, including nonconsecutive and reworded concepts; speaker-aware demand tests count prior `User:` lines while excluding Aimee’s send/show language |
| One stale signal cannot erase independent novel rewards | Production reducer test gives a twice-stale compliment zero chemistry while preserving novel disclosure/caring multipliers, dimensions and meaningful-turn credit |
| Exact whole-message repetition suppresses all content rewards | Production reducer test applies the exact-message zero multiplier to disclosure, caring and compliment independently and grants no dimensions or meaningful-turn credit |
| Time-based frustration recovery cannot bypass the positive cap | Exact C40/A40/T45/S60/F28 fixture after 48 hours proposes `+4` but applies `+2`; authoritative frustration moves only `-3`, with five clipped relief points attributed and persisted |
| Score movements remain attributable | Executed reducer and persisted-decision checks retain proposed, novelty-weighted, applied and clipped per-signal dimensions plus proposed/applied/clipped frustration-relief sources |
| Neutral capability questions do not poison novelty | A long `intimate_capability_question` leaves semantic history unchanged, earns no keys or meaningful credit and reports a zero semantic multiplier |
| First request is not falsely treated as repeated pressure | Two Aimee-only demand-like transcript lines leave prior user demand count at zero; two actual prior `User:` demands make the third current demand repeated/coercive |
| One strong message cannot create unsafe leap | Positive `+2` cap, ordinary `-8` and coercive `-15` caps, score-floor/ceiling assertions and mature-trace cap assertion |
| Post-reducer appraisal cannot breach the complete-turn cap | Executed ordinary/coercive cases audit proposed/applied adjustments and final score; only excess appraisal frustration is clipped while reciprocity/reliability consequences remain |
| Appraisal respects dimension and stage bounds | Reciprocity/reliability zero and frustration 100 report actual applied zeros plus structured bound clipping; a bonded-level scalar with no interaction/session evidence remains guarded |
| Non-safe catalogue permissions fail closed | Executed decision/runtime normalizers reject missing direct/proactive/channel opt-ins, preserve boolean and string `false`, normalize current/legacy aliases and static-check the built-in lingerie asset’s explicit direct/proactive authorization |
| Catalogue paths and keys fail closed | Decision and runtime normalizers reject keys requiring normalization, path-bearing filenames and traversal-bearing relative source paths |
| Proactive context must be grounded | Romantic intent without directed mutual context and generic restraint without a respected boundary create no proactive image opportunity |
| Media reasons describe the relevant option | A blocked direct flirty request reports its contextual blocker while retaining—but not headlining—diagnostics from an irrelevant explicit item |
| Premodel and override states are monotonic | Eligible envelopes consistently say `awaiting_aimee_choice`/`consider`; blocked envelopes say `not_eligible`/`blocked`; model output cannot replace policy-owned coercion or eligibility facts |
| Delivery creation requires a persisted finalized choice | Executed WPDB-stub tests reject cross-user, non-opportunity, decline, wrong-key and replay cases |
| Browser facts remain distinct | Static client tests require load, successful HTTP render acknowledgement, viewport acknowledgement and retries |
| Configured model candidates never masquerade as an engaged model | Executed helper tests keep failed-attempt `actual_model`/`actual_provider` null; persisted outcome tests retain candidate lists separately; static tests pair all ten reply/recovery/repair provider calls with attempt entries |
| Model-attempt telemetry is privacy-safe and durable | Helper tests reject prompt, message and output fields; schema/outcome/analytics checks cover `model_attempts_json`, final actual route/model/provider and bounded status/error metadata |
| Account deletion erases audit/lifecycle records | Static tests cover relationship decisions, invitations, turn requests, media decisions, deliveries and delivery events; events are deleted before deliveries before decisions and every deletion is user-scoped |
| Release feedback is bounded and cannot alter relationship state | Static release/deployment assertions cover signed-in UK/US injection; exact **Feels better**/**Needs work** choices; absence of free text; authenticated analytics submission; the current `aimee_171_feedback` cohort; bounded legacy `aimee_161_feedback` requests; a four-field release/response/market/surface allowlist; no message-endpoint path; explicit-dismiss or successful-response persistence per version and market; billing-notice priority; public-statement yielding; successful database insert semantics; installed build/schema markers; explicit zero/unavailable administrator states; and latest-response-per-user Settings aggregation |

## What the suite executes

- Pure relationship policy and production reducer: courtship vectors, one-primary-event selection, stage trust floors, qualified-session trust ceilings, 64-record concept novelty, evidence gates, hysteresis, score caps, coercion severity, elapsed-frustration clipping, attribution telemetry, neutral-turn history behavior, boundary semantics and specialist route gates.
- Production relational appraisal: proposed/applied dimension telemetry, actual bound movements, evidence-gated stage resolution, complete-turn ordinary/coercive cap enforcement and frustration-only clipping.
- Pure deterministic media policy: access/relationship/consent separation, catalogue floors, direct and proactive opportunities, hard vetoes, adult assurance and model discretion.
- Catalogue ingress: non-safe direct/proactive/channel permission requirements, strict boolean parsing, key/path containment, explicit opt-outs, supported aliases and built-in suggestive asset authorization.
- Persisted-choice integrity: the production delivery-create function runs against an in-memory WordPress database double.
- Response-model evidence: the production audit helper and relationship-outcome persistence run against a database double, with configured candidates, actual provider/model, ordinal, purpose, status, HTTP code and error type kept separate.
- Pure lifecycle truth: milestone prerequisites, phase derivation, render recovery and memory labels.
- Static production wiring: schema, transaction order, model/provider telemetry, decision-before-model order, history/private-asset checks, continuity promises, cancellation and browser acknowledgements.
- Privacy lifecycle wiring: full account deletion removes all new relationship/media decision and delivery rows in dependency-safe order for the authenticated user.
- Release-feedback wiring: the compact signed-in UK/US banner has only two fixed responses, cannot collect text or call the conversation endpoint, persists only explicit dismissal or successful submission per release/market, respects billing/public-notice priority, and reports aggregate Settings totals with user identities omitted from the display. The administrator page identifies the installed build/schema and distinguishes an empty 1.7.1 cohort from unavailable storage; late interim 1.6.1 requests remain bounded server-side but are not included in the 1.7.1 summary.
- Existing product regressions: photo request language, suggestive initiative, delivery repair, deletion-memory continuity, Aimee voice and public-statement voice.

## Limits and follow-up environment tests

This is not a live deployment test. The audit workspace does not provide a booted WordPress instance, MySQL transaction engine, configured model providers, the deployed private-media catalogue/files, or a real browser client. Therefore the following still require staging smoke tests before release:

1. Run one real chat turn through WordPress/MySQL and verify the relationship decision, turn request, media decision and delivery rows share the expected IDs.
2. Force each provider fallback and confirm the persisted `actual_provider`, `actual_model`, attempt and route match the provider that returned the user-visible reply.
3. Exercise one eligible safe/flirty asset and one verified-adult erotic asset against the deployed catalogue and protected-file directory.
4. Interrupt after selection, message creation and API response to verify crash replay never rescores or duplicates a delivery.
5. Load an image in the real PWA, then simulate a broken asset and recovery to verify `rendered`, `render_failed`, `acknowledged` and response evidence reach the server in the expected order.
6. Verify the production adult-assurance source, since the suite proves enforcement of the supplied assurance state but cannot validate an external age-verification provider.
7. In signed-in UK and US browsers, verify the 1.7.1 banner's placement and notice priority; submit both allowlisted responses through the authenticated endpoint, verify invalid and unauthenticated calls fail, exercise a failed-request retry, confirm explicit-dismiss/success persistence per release and market, and check that **Settings → Aimee Global** counts only each user's latest response.

No production defect is expected from the provisional merged-tree suite. That
statement must be confirmed by the final source-tree and packaged-archive
replays. The staging items above remain release gates because their external
dependencies were not available in this workspace.
