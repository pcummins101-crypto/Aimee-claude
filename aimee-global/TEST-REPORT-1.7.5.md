# Aimee Global 1.7.5 — profile-source attribution test report

Date: 17 August 2026  
Release: `1.7.5`  
Relationship policy: `2.1.0`  
Schema: `2026.08.03.6`

## Result

The complete source-tree audit passed **3,157/3,157 deterministic assertions**
across six command groups, with zero command or assertion failures. All
**43/43 production PHP files** parsed on both PHP 8.3 and PHP 7.4.

The installable ZIP was extracted into a new temporary directory and passed
the same canonical suite: **3,157/3,157**, with zero command or assertion
failures. This establishes source/archive parity for the packaged release.

Run the source suite from the plugin directory with:

```bash
python3 tests/run-audit-suite.py
```

Observed source result:

```text
AUDIT SUITE RESULT: 6 commands passed, 0 failed; 3157 assertions passed, 0 failed
```

## Principal executable coverage

| Area | Checks | Runtimes |
|---|---:|---|
| Relationship-stage and route simulation | 70 | Python deterministic simulator |
| Static production wiring | 287 | Python source/runtime contract |
| Chat, grace and feedback notices | 26 | JavaScript |
| Profile-source attribution policy | 287 per runtime | PHP 8.3 and PHP 7.4 |
| Attribution production wiring | 114 per runtime | PHP 8.3 and PHP 7.4 |
| Evidence-bound stored-opening repair | 53 per runtime | PHP 8.3 and PHP 7.4 |
| Romantic-expression decisions | 130 per runtime | PHP 8.3 and PHP 7.4 |
| Cross-channel romantic/synthetic posture | 57 per runtime | PHP 8.3 and PHP 7.4 |
| Synthetic identity and reality integrity | 112 per runtime | PHP 8.3 and PHP 7.4 |
| Consciousness voice | 24 per runtime | PHP 8.3 and PHP 7.4 |
| Intimacy and media policy | 273 per runtime | PHP 8.3 and PHP 7.4 |
| Media cadence planner | 54 per runtime | PHP 8.3 and PHP 7.4 |
| Media cadence/relevance live integration | 83 per runtime | PHP 8.3 and PHP 7.4 |
| August service-grace policy | 91 per runtime | PHP 8.3 and PHP 7.4 |
| Georgia colleague workflow | 67 per runtime | PHP 8.3 and PHP 7.4 |
| Production PHP syntax | 43 files per runtime | PHP 8.3 and PHP 7.4 |
| Frozen photo/public-statement/autonomy checks | 82 | PHP 8.3 |

The aggregate runner counts each executable assertion according to its emitted
test protocol; the table also records parsed-file coverage. It should not be
re-summed as a replacement for the runner's canonical total.

## Confirmed incident regression

The supplied evidence for user `112` is reproduced as a test fixture without
contact or payment data. It establishes Paul, age 43, a first-person profile
statement that he runs the electric-motorcycle company Avenrà, the stated
intent “Get to know you and see where we go”, and the generated opening:

```text
Hiya Paul, I'm Aimee 👋 I spend my days elbow-deep in electric motorbike plans
for my company Avenrà, so anything on two wheels gets me properly excited...
```

The focused suite proves that the observed opening is rejected as an Aimee
employment/company and interest appropriation, while user-attributed reactions
and questions remain valid. Avenrà and Avenra normalise to the same evidence
anchor.

The policy coverage also exercises:

- copied user name, age, appearance, company/employment, partner/family,
  home/location, possessions, history and interests;
- prompt-injection text embedded in a profile;
- quotation, reported user speech, negation and counterfactual statements that
  must not become false positives;
- independent user-focused reactions and questions;
- Aimee's canonical presented age and authorised visual-world appearance;
- independently trusted shared-interest context;
- terse employment and possession paraphrases; and
- cross-account protection, so facts from one user's source cannot become
  Aimee's biography when speaking to another authenticated user.

## Prompt, reviewer and persistence acceptance evidence

The source and wiring regressions prove that:

- the bootstrap loads `includes/profile-attribution.php` before the engine;
- generation uses an allowlisted profile projection rather than a whole
  database row, excluding phone, Stripe, subscription, role and score fields;
- hobbies, stated intent and appearance notes are bounded server-side, with
  matching registration-form limits;
- the prompt serialises profile content as untrusted JSON whose subject is the
  authenticated `current_user`, and commands inside it cannot direct Aimee;
- the canonical authenticated display name is passed explicitly, preventing an
  owner/colleague label from leaking through another account's profile;
- Aimee-authored legacy history is reviewed against the current profile before
  it enters a model prompt; contaminated rows are omitted from that derived
  transcript without altering stored or user-authored history;
- first-person Aimee claims are compared with inspectable profile evidence by
  category and clause;
- a contaminated candidate is rejected whole and emits a bounded deterministic
  repair directive rather than being partially spliced;
- main chat checks reply, instruction, memory, opinion, self-observation, goal
  and metacognitive fields before trusting the structured object;
- one repair attempt stays on the original primary or intimacy-specialist
  route and is recorded with purpose `profile_attribution_repair`;
- repeated failure creates a neutral contract without carrying rejected
  memory, opinion, romantic action or media selection;
- the final visible reply is checked again after media caption, delivery-truth,
  self-control and reply-length processing and before message or memory writes;
  and
- a final fallback can preserve an already authorised attachment only with a
  catalogue-grounded caption.

## Cross-channel acceptance evidence

The integration checks prove that the same source boundary reaches:

- onboarding, including initial review, one provider regeneration and a final
  pre-insert review;
- primary and intimacy-specialist chat;
- safe and suggestive photo-caption generation;
- relationship-stage-aware voice-call greetings;
- continuity analysis, where a contaminated first-person timeline story is
  skipped;
- proactive continuity follow-ups, where a bad reply uses grounded text and
  defers its model-selected media; and
- autonomous messages, where a bad unsolicited draft is suppressed and
  rescheduled instead of stored or delivered.

Tests also verify that provider retry telemetry, fallbacks and later output
guards cannot persist the rejected content through an alternate structured
field.

## Evidence-bound user-112 data repair

The 53-check repair regression runs the production migration against a fake
WordPress database boundary on both supported PHP runtimes. It proves that the
repair requires:

- immutable user ID `112`, Paul, age 43 and the supplied creation timestamp;
- the supplied Avenrà electric-motorcycle-company profile evidence and intent;
- zero trial use and zero user-authored conversation turns;
- the first Aimee message with the written-context onboarding directive; and
- deterministic confirmation of the `my company`/Avenrà attribution error.

On a match, the repair starts a transaction and compare-and-swaps the exact
existing Aimee row. It changes only `message_text` and `evaluator_directive`,
retains the same message ID, adds `profile_attribution_repair=1.7.5`, commits,
and records the message ID plus hashes of the original and replacement. The
replacement itself passes the deterministic reviewer.

The suite also proves that the migration:

- creates no second message and deletes no history;
- makes no provider call;
- leaves relationship score/stage, subscription, service-grace, profile and
  trial state unchanged;
- rolls back if the conditional update does not affect exactly one row;
- records an evidence-mismatch no-op instead of widening its target; and
- is idempotent after a completed repair.

## Preserved 1.7.4 and earlier coverage

All inherited 1.7.4 romantic-expression, synthetic-identity, media cadence,
catalogue relevance, atomic opportunity, delivery-truth and intimacy-route
regressions remain green. In particular, profile attribution does not lower
courtship warmth, disable respectful romantic initiative, change score or
stage floors, alter the intimacy-specialist gates, or make an eligible image
unavailable merely because the user did not command one.

Georgia/user `24` remains on the immutable `colleague_primary` professional
lane with complete written safe/flirty creative output and no manufactured
consumer intimacy. The August service-grace, closed-account Stripe quarantine,
replacement-subscription requirement, SMS boundary and payment-as-access-only
rules also remain green. Relationship, adult assurance, consent, pressure,
catalogue, cooldown and delivery-state controls remain independent.

## Legacy-state limitation

The new guard prevents current model output from adopting facts found in the
current user's allowlisted profile. It also excludes a deterministically
contaminated Aimee-authored history row from new prompt transcripts while
leaving the stored row auditable. It is not a bulk historical-data migration.

The confirmed user-112 opening occurred before any user-authored message, so
the same-row history repair addresses its known source and there is no supplied
evidence that it propagated into memory, opinions or timeline state. The code
therefore does not delete those stores speculatively.

If a different account shows historical contamination, Engram Intelligence
should first identify the exact originating message and every downstream row
with reliable account, time and provenance evidence. Any cleanup should then be
scoped, compare-and-swap guarded, idempotent and separately tested. Broad
deletion of memories, opinions or timeline moments would risk destroying valid
relationship continuity and is expressly outside this release.

## Remaining deployment gates

Before production:

1. Confirm the plugin reports `1.7.5` and the schema remains
   `2026.08.03.6`.
2. Back up the live database and inspect
   `aimee_global_profile_attribution_opening_repair_175` before and after the
   first `init` request.
3. Verify the user-112 message ID/time and all profile, relationship, trial,
   service-grace and billing values remain unchanged except for the expected
   same-row text/directive update.
4. Treat an evidence-mismatch no-op as a stop for manual inspection; do not
   weaken the predicate to force the migration.
5. Exercise onboarding and every covered channel using work, family, home,
   appearance, possession, interest, accented-name, quotation, negation and
   prompt-injection profiles.
6. Force one same-route provider repair and one repeated failure. Confirm
   telemetry and the absence of rejected content in visible text, history,
   memory, opinions, timeline and self-model fields.
7. Smoke-test 1.7.4 romance and relevant/proactive images, Georgia's written
   creative workflow, and the 1.7.2 service-grace/replacement-billing boundary.
8. Monitor attribution flags, provider-repair rate and final-fallback rate
   after deployment; investigate a rising fallback rate without disabling the
   source boundary.

The source-tree run did not mutate a production account, send an external
message or deploy the plugin.
