# Aimee Global 1.7.9: romantic repair resilience

Release date: 19 August 2026  
Plugin release: `1.7.9`  
Schema: `2026.08.18.2` unchanged  
Relationship policy: `2.1.0` unchanged  
Romantic-expression policy: `1.2.0` unchanged

## Why this release exists

A production conversation returned the public fallback:

> That came out wrong. Give me that again, I want to answer you properly. x

The stored evaluator directive showed:

```text
romantic_choice_repair=failed; romantic_post_repair_guard=fallback
```

The surrounding conversation still contained coherent autobiographical and
relationship continuity. The incident was therefore not memory loss, a reset or
a damaged friendship state. Aimee had reached the reply pipeline, but a later
romantic route-integrity check rejected the structured choice attached to the
reply and replaced the complete visible answer.

The same failure path remained present in 1.7.7 and 1.7.8. Rolling back to 1.7.4
removed the symptom because the problematic post-repair guard was introduced
with the 1.7.5 source-attribution work.

## Root cause

The failure required several small defects to line up:

1. The model received the allowed romantic actions and maximum intensity, but
   was told only to return a "matching reason code". The exact action-to-reason
   map was not present in the prompt.
2. The provider repair prompt repeated that same omission, so a second model
   call could make precisely the same metadata mistake.
3. The 1.7.5 post-attribution guard treated an invalid reason or intensity token
   as proof that the whole conversational reply was unusable. It discarded safe
   prose instead of repairing the metadata.
4. The neutral source-attribution contract used
   `romantic_reason_code=no_romantic_opportunity`. That token is correct when no
   opportunity exists, but invalid for a discretionary hold during an active
   romantic opportunity. A downstream attribution repair could therefore
   manufacture a fresh romantic-contract failure.
5. The old direct-friend-label detector matched any occurrence of `mate`,
   `buddy` or `pal`. A harmless third-party phrase such as `your mate Dave`
   could be mistaken for Aimee redefining the relationship.

This was a response-validation regression. It did not alter, erase or reset
stored memories, relationship history, score, stage, trust, invitations or
media state.

## What 1.7.9 changes

### Exact model choice map

The ordinary romantic-expression prompt and the provider repair prompt now
include the exact combinations available on the current turn, for example:

```text
reciprocate(
  intensity=playful_nonsexual;
  reason=aimee_mutual_spark|aimee_playful_interest
)
hold(
  intensity=none;
  reason=aimee_prefers_more_context|aimee_prefers_friendlier_tone|aimee_not_feeling_romantic
)
```

The map is rendered from the server-owned opportunity envelope. The model
cannot add an action, raise the ceiling or invent a reason token.

### Deterministic reconciliation before regeneration

`aimee_romantic_expression_reconcile_model_contract()` now compares the
structured choice with the actual visible reply.

- A valid choice whose prose matches is accepted unchanged.
- Safe visible romantic prose with missing or malformed metadata is retained
  and mapped to the most conservative valid action, intensity and reason.
- Readable neutral prose with absent or non-expressive metadata becomes a valid
  discretionary hold rather than a public error.
- A model that explicitly selected `reciprocate`, `initiate` or
  `tease_jealousy` but failed to express that choice still receives a provider
  rewrite. The fix does not silently downgrade a chosen flirt into a hold.
- A jealousy choice must contain a safe, perceptible jealous beat. It cannot be
  relabelled from unrelated flirtation.
- Empty prose, a genuine friend-zone redefinition or unsafe jealousy remains
  ineligible for local normalization and follows the bounded repair path.

This separates metadata repair from prose repair. A malformed code no longer
sends a perfectly serviceable sentence through the trapdoor.

### Resilient post-attribution guard

After synthetic-identity or profile-attribution regeneration, the final guard
uses the same deterministic reconciliation rather than applying the raw choice
and immediately discarding the reply.

Safe prose survives downstream metadata drift. Only an empty or genuinely
unusable draft reaches the hard fallback. That fallback now says:

```text
I'm here. My wording tangled itself, but I heard you properly. Carry on. x
```

It does not ask the user to repeat a message the platform already received.
The old `That came out wrong. Give me that again` wording is absent from the
romantic repair path.

### Contract-valid neutral repair

`aimee_profile_attribution_neutral_contract()` now uses
`aimee_prefers_more_context` for its hold reason. That value is valid during an
active opportunity and harmless when no opportunity exists, where the
server-owned choice application still normalizes to
`no_romantic_opportunity`.

### More precise friend-label detection

Actual redefinitions remain blocked, including:

- `we are just friends`;
- `I only see you as a friend`;
- `you are my mate`;
- direct address such as `Thanks mate` or `Thanks, mate`.

Third-party references such as `your mate Dave` no longer trigger the guard.

## Telemetry

The route directive can now distinguish each outcome:

- `romantic_choice_normalized=normalized_visible_reciprocate`;
- `romantic_choice_normalized=normalized_hold`;
- `romantic_choice_repair=provider`;
- `romantic_choice_repair=hard_fallback`;
- `romantic_post_repair_guard=normalized_*`; and
- `romantic_post_repair_guard=hard_fallback`.

A hard fallback also records the bounded failure reason. These values contain no
conversation text or private profile data.

## Deliberately unchanged

This release does not change:

- relationship score, stage thresholds or trust ceilings;
- the romantic opportunity matrix or expression intensity ceilings;
- specialist model eligibility;
- adult, consent, pressure, rupture, colleague or payment gates;
- media eligibility, media cadence, image generation or delivery;
- memory storage, retrieval or consolidation;
- user-image event semantics from 1.7.8;
- the provider-neutral live-image bridge from 1.7.7; or
- database schema.

## Staging checks

Before production:

1. Upgrade from 1.7.4 and confirm the plugin reports `1.7.9` while schema remains
   `2026.08.18.2`.
2. Send several light affectionate messages at guarded, warm and flirty stages.
   Confirm safe replies appear normally and no repeated resend request occurs.
3. Inspect evaluator directives for `romantic_choice_normalized`,
   `romantic_choice_repair` and `romantic_post_repair_guard` outcomes.
4. Force a malformed reason token in staging and confirm the visible reply is
   preserved while metadata is corrected.
5. Force an expressive action with neutral prose and confirm one provider repair
   is attempted rather than silently converting the choice to hold.
6. Force an empty draft and a genuine friend-zone draft. Confirm the hard
   fallback is bounded, side-effect-free and does not ask the user to repeat.
7. Test `your mate Dave`, `Thanks mate`, `we are just friends` and unsafe
   possessive jealousy separately.
8. Verify existing memory, relationship, invitation, media and billing records
   are unchanged across the update.
9. Run `python3 tests/run-native-audit-suite.py` and review
   `TEST-REPORT-1.7.9.md`.

Real-provider staging remains necessary. Deterministic tests prove the local
contract and repair behaviour, but cannot guarantee how every future provider
response will be phrased.
