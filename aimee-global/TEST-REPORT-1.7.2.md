# Aimee Global 1.7.2 — regression report

Date: 3 August 2026  
Tree tested: `work/aimee-global`  
Result: **6 commands passed; 1,153 assertions passed; 0 failed**

## One-command run

```bash
python3 tests/run-audit-suite.py
```

Final result:

```text
AUDIT SUITE RESULT: 6 commands passed, 0 failed; 1153 assertions passed, 0 failed
```

## Exact command groups and counts

| Command group | Result | Assertions |
|---|---:|---:|
| `intimacy-policy-simulation.py` | Pass | 70, plus 44 committed scenario-policy summaries |
| `static-integration-regression.py` | Pass | 242 |
| `chat-notice-regression.mjs` | Pass | 26 |
| PHP 8.3: intimacy/media + service grace + production syntax | Pass | 355 |
| PHP 7.4: intimacy/media + service grace + production syntax | Pass | 355 |
| Carried-forward consciousness/photo/public-statement regressions | Pass | 105 |
| **Total** | **Pass** | **1,153** |

The two PHP policy groups execute the same 263 intimacy/media assertions and 91
service-grace assertions against the current and minimum supported PHP lines.
Each group also parses all **39/39** production PHP files with `TOKEN_PARSE`.

## New service-grace coverage

The 91 pure assertions on each PHP runtime prove:

- the fixed cutoff is `1788217200`, derived from midnight Europe/London rather
  than the server's timezone;
- access is active one second before and inactive exactly at the cutoff;
- new-profile campaign fields are deterministic and contain no billing or
  relationship fields;
- a missing or post-cutoff profile receives no campaign entitlement;
- an enrolled unpaid profile receives August access without a payment claim;
- the profile requires a replacement subscription from the cutoff;
- a valid new-account subscription satisfies the requirement;
- closed-account legacy IDs and stale local `active` labels cannot satisfy it;
- canceled, past-due, unpaid, incomplete and paused states cannot masquerade as
  managed access;
- a later individual goodwill entitlement remains valid and delays the first
  fair payment date; and
- first, repeated, stale-generation and terminal subscription syncs remain
  valid while a different live current-generation ID requires reconciliation;
- every helper is observational and leaves relationship/intimacy state
  byte-for-byte unchanged.

The 242 static integration assertions include proof that:

- the policy loads before billing-aware UI code;
- the schema persists the campaign code, grant time and cutoff;
- activation, upgrade and new-profile paths enrol accounts idempotently;
- the bulk SQL update changes only service-grace fields;
- legacy access checks delegate to the separated entitlement helper;
- managed billing requires a real current-account subscription;
- the cutoff invalidates stale closed-account state;
- carrier SMS is not silently funded by the in-app grant;
- the REST snapshot separates access, billing and payment scheduling; and
- checkout distinguishes grace from a real recurring subscription and cannot
  create an August payment through the normal UI/API route;
- checkout owns a tokenised per-user mutex before reading profile or pending
  session state, re-verifies before Stripe creation and conditionally saves and
  releases only its own lease;
- a reusable pending session must match the exact plan and market; and
- subscription sync uses an atomic identity compare-and-swap so concurrent
  current-generation subscriptions cannot silently replace one another;
- owner and Georgia privilege is based on immutable configured WordPress user
  IDs rather than a mutable name, email address or phone number;
- the FireText callback has durable replay protection even when the provider
  supplies no event ID, with a documented optional trusted-proxy ID path; and
- every outbound SMS intent is durably keyed before dispatch, preserving
  at-most-once behaviour and an honest `delivery_unknown` state for ambiguous
  provider outcomes.

## Preserved relationship and media evidence

All prior 1.7.1 relationship, intimacy, model-route, proactive media,
delivery-state, release-feedback and deletion regressions remain green. This
includes:

- stage/evidence/trust floors and anti-gaming novelty;
- respectful courtship rewards and coercion/payment vetoes;
- membership never manufacturing intimacy or consent;
- adult assurance and current mutual context remaining independent;
- proactive flirty/erotic image consideration only in eligible context;
- deterministic media choice and delivery milestone truth;
- configured candidate models never masquerading as an engaged model;
- relationship state surviving billing cancellation; and
- aggregate 1.7.1 feedback remaining visible in Settings.

## Chat-notice and browser-source checks

The 26 browserless DOM assertions execute the signed-in chat notice under both
UK and US routes. They prove that the notice:

- is headed **A thank-you from Engram Intelligence** and appears in the actual
  chat UI rather than only in Settings or on the pricing page;
- explains that August access is complimentary, the former Stripe account
  cannot renew, nothing is charged or created automatically, and a user must
  explicitly create a new subscription from 1 September 2026;
- remains discoverable when the legacy composer is inserted late, retries only
  for a bounded period and refreshes on page visibility, online and history
  events;
- shares the exact Europe/London cutoff with the server and both pricing
  routes; and
- becomes a non-dismissible reconciliation warning, rather than making a false
  complimentary-access claim, if `payment_scheduled=true` is ever observed.

All six inline scripts in `includes/legacy-ui.php`, together with the UK and US
pricing scripts, were parsed with Node after WordPress PHP interpolation was
replaced by neutral literals. No syntax errors were found.

## Packaged-archive replay

`aimee-global-1.7.2-august-service-grace.zip` was extracted into a clean
temporary directory and the same one-command suite was rerun against the
packaged files. The replay passed all six command groups and all **1,153/1,153**
assertions, including **39/39** production PHP parses on both runtimes and all
**26/26** chat-notice DOM checks. The release checksum is recorded separately
in `AIMEE-1.7.2-SHA256SUMS.txt`.

## Staging gates

This workspace does not provide a live WordPress/MySQL deployment, a configured
replacement Stripe account or a signed-in browser. Before production:

1. Verify schema migration and the enrolled-profile count in Settings.
2. Confirm trial, exhausted-preview and former-member accounts all receive
   August in-app access with `payment_scheduled=false`.
3. Confirm the Engram thank-you notice in signed-in UK and US chat.
4. Confirm subscription and SMS checkout are paused during grace.
5. Verify the exact cutoff under a controlled staging clock.
6. Complete one explicit replacement-account checkout after the cutoff and
   confirm managed access uses only the new Stripe identifiers.
7. Test webhooks, cancellation, account deletion and a failed payment in the
   replacement account.
8. Confirm relationship, adult assurance, mutual-context and media-coercion
   safeguards before and after the access transition.

No live Stripe mutation, external message or production deployment was
performed by this test run.
