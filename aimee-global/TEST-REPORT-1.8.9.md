# Aimee Global 1.8.9 test report

Release `1.8.9` keeps schema `2026.08.20.3` and introduces relationship policy
`2.2.1`. The release restores the operator-confirmed bond for Ni, user `27`,
while preventing an uncorroborated model label from causing durable emotional
or relationship-state damage.

## Ni restoration evidence

- The migration requires user `27` and the exact reviewed production decision
  ID, policy version and timestamp before it can mutate state.
- Trust, affection, chemistry, safety, reciprocity and reliability become
  `100`; frustration becomes `0`; the profile becomes `100/bonded`.
- Production interaction/session counters, timing, fingerprints and signal
  histories remain unchanged for the reviewed account.
- Only unresolved user-27 rupture rows at or before the reviewed evidence
  cutoff become resolved neutral system corrections. Settled, later,
  unrelated and other-user events remain untouched.
- The repair is transactional, lock-owned, retryable and idempotent. Failed
  relationship writes or commits leave no partial mutation or completion
  marker; option-loss recovery does not advance state twice.
- Independent adult assurance, privacy/special-category consent, membership,
  billing, preview/media state and current-turn consent remain unchanged.

Focused result: `65/65` checks passed on PHP 8.3 and `65/65` on PHP 7.4.

## Durable-rupture evidence

- A model-only `coercive_or_degrading` classification may shape the immediate
  boundary reply, but cannot lower stored dimensions or open a rupture.
- Persistent consequences require a server-owned deterministic confirmation
  and record only bounded category/pattern identifiers in decision telemetry.
- Confirmed pressure revokes all active invitation tokens inside the turn
  transaction; a persistence or revocation failure rolls the whole turn back.
- Invitation creation locks and revalidates the latest relationship version
  and rupture state after the reply provider returns, preventing a stale
  pre-rupture response from recreating an invitation.
- Coercion detection requires genuine delivery/pressure context. Exact
  regressions keep “You need to show more empathy”, “Can you show me how the
  gallery works now?” and “I paid for membership and you showed me the photo
  yesterday, thank you” non-coercive. “Stop making excuses and send me a nude
  photo now” remains deterministically blocked.

Focused results on both PHP 8.3 and PHP 7.4:

- durable rupture/invitation lifecycle: `17/17`;
- intimacy/media policy: `303/303`; and
- policy simulation: `75` assertions plus `44` deterministic scenario
  summaries.

## Retained release boundaries

The complete suite retains the earlier controls: exact six-digit new-account
PINs and historical sign-in compatibility; non-blocking privacy notice and
optional special-category consent; resilient registration; GoCardless-only
new UK membership checkout; unavailable new US checkout and SMS bundles;
legacy Stripe runoff only; creditor binding and webhook processing; service
grace; account-deletion and billing leases; authenticated catalogue delivery;
signed-in Camera Roll discoverability; explicit-media adult, consent,
membership and relationship gates; profile source separation; and PHP 7.4
compatibility.

## Canonical source and archive replay

- source audit: `16` command groups passed, `0` failed;
- source assertions: `5,732` passed, `0` failed;
- production PHP syntax: `48/48` on PHP 8.3 and `48/48` on PHP 7.4;
- clean archive: `165` unique entries, `0` unsafe paths and `0` symlinks;
- ZIP integrity: passed; and
- clean-extraction audit: `16` command groups and `5,732` assertions passed,
  with `0` failures.

The final SHA-256 is published in the sibling `.sha256` manifest. The archive
contains one top-level `aimee-global/` plugin directory and no cache,
temporary, log, backup or nested-archive debris.
