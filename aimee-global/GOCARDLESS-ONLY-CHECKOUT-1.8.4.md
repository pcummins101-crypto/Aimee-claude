# GoCardless-only checkout cutover: Aimee Global 1.8.4

## Release contract

Version 1.8.4 removes Stripe as a choice for every newly created checkout.
"GoCardless-only" describes the provider policy; it does not claim that every
market or product is already supported by the current GoCardless integration.
The implemented new-payment lane is UK membership in GBP.

Schema remains `2026.08.20.3` and relationship policy remains `2.2.0`.

| Request | Result |
| --- | --- |
| New UK/GBP membership | GoCardless Billing Request and hosted authorisation flow |
| New US paid membership | Unavailable; fail closed without provider contact |
| New additional SMS bundle | Unavailable; fail closed without provider contact |
| Existing SMS balance | Remains usable under the existing allowance rules |
| Pre-cutover Stripe record | Legacy management and terminal reconciliation only |

No route may fall back to Stripe when GoCardless is unconfigured, unhealthy,
unsupported for the profile market, or temporarily unavailable.

## Provider boundaries

GoCardless owns every currently available new membership checkout. The profile
row remains the canonical market authority; client parameters and WordPress
user meta cannot convert an unsupported market into UK checkout. The exact
plan, GBP amount, billing generation and creditor remain server-owned and are
persisted before the provider request under the existing billing lease and
idempotency contract.

Stripe code remains only because deleting it would strand pre-cutover records.
The supported legacy operations are:

- subscription and session status;
- end-of-period cancellation;
- customer-portal access for an existing Stripe customer;
- verification and application of signed Stripe webhooks;
- reconciliation or expiration of a pre-cutover Checkout Session; and
- remote retirement and proof required for account deletion.

These paths are runoff controls, not a checkout option. They must not create a
Checkout Session, expose a fresh Checkout URL, change a profile back to Stripe,
or fulfil an unowned or mismatched historical session.

## Existing-access charge guard

A profile with preserved, goodwill or other service-grace access cannot begin
GoCardless checkout before that access ends. A blocked response may expose the
server-calculated time at which checkout can open, but must report that no
charge was created or scheduled. Only after the access end time may the normal
GoCardless authorisation and payment lifecycle begin.

This prevents a cutover from charging immediately for time that the customer
already has. It is independent of client display state and must remain enforced
inside the server checkout handler.

## Pre-deployment Stripe inventory

An upgrade cannot revoke Stripe-hosted URLs that were issued earlier. Complete
this inventory before putting 1.8.4 into service:

1. Export or otherwise record every non-terminal recurring-membership and SMS
   Checkout Session known to the plugin and Stripe account, plus every local
   recurring intent in `requesting` or `request_unknown` and every SMS purchase
   whose stored session ID is a `creating_...` placeholder.
2. Match each session to its local owner, product, market, generation and
   expected amount/currency. Investigate every unmatched record.
3. Reconcile sessions that already completed through the signed event and
   durable local record.
4. Explicitly expire every still-open session that must no longer be payable,
   including links that may have been sent to a customer.
5. Record the Stripe Checkout Session creation count and the remaining legacy
   subscription/session inventory as the cutover baseline.

The cutover code does not replay an ambiguous Stripe idempotent create. If a
local intent or placeholder has no bound Session ID, check its historical
idempotency key and provider logs manually. The affected bank transition or
account deletion remains fail-closed until the real external outcome is
recorded and terminal; this is intentional protection against creating a new
Stripe Checkout Session during cleanup.

Do not remove `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET` or the configured
Stripe account identity at deployment. Keep the credentials and signed webhook
active until the legacy inventory has reached its intended terminal state and
account deletion can prove remote retirement. Restrict their use operationally
and rotate or remove them only after the runoff is verified complete.

## GoCardless configuration and staging

Configure and verify:

- `GOCARDLESS_ACCESS_TOKEN`;
- `GOCARDLESS_WEBHOOK_SECRET`;
- the exact `GOCARDLESS_CREDITOR_ID` authorised for that access token; and
- `GOCARDLESS_ENVIRONMENT=sandbox` for staging, then the reviewed live value at
  production cutover.

The plugin must fail closed when creditor discovery does not prove the exact
configured creditor, the payment ledger is unhealthy, a required credential
is absent, or the profile is not in the supported UK market. Do not reuse an
older Stripe availability flag as a health fallback.

In the GoCardless sandbox, exercise first authorisation, request replay after an
uncertain response, duplicate and out-of-order events, failed-payment retry,
missed-cron recovery, mandate replacement, end-of-period cancellation and
account deletion. Confirm the durable ledger, idempotency keys, billing
generation and provider ownership remain stable throughout.

## Cutover verification

Exercise all of the following against the exact release archive:

- each signed-in UK paid-plan control creates only a GoCardless flow;
- preserved/grace access prevents early flow and payment creation, then permits
  checkout only after the server-owned expiry;
- each signed-in US paid-plan surface shows checkout unavailable and a direct
  API request fails before provider contact;
- SMS allowance and existing balance displays remain available, but no SMS
  purchase control or direct API request can create provider checkout;
- pricing, chat fallback and legacy UI surfaces expose no Stripe checkout URL;
- legacy Stripe status, cancellation, portal, webhook, reconciliation and
  deletion continue to work for records created before cutover;
- malformed, stale, mismatched and unsigned legacy Stripe events remain
  rejected; and
- the Stripe Checkout Session creation count remains unchanged throughout the
  complete staging exercise.

Review server, MariaDB, GoCardless and Stripe logs for hidden retries or provider
calls. A new Stripe Checkout Session, an early GoCardless charge, or an
unsupported-market provider request is a release blocker.

## Rollback and incident handling

Do not roll back to a build that re-enables Stripe checkout. If the GoCardless
lane must be paused, disable new paid checkout while preserving signed webhooks,
legacy reconciliation and cancellation. Reconcile any GoCardless request that
may have reached the provider before retrying or restoring service; never create
a second request merely because the first response was uncertain.

For a suspected pre-cutover Stripe URL still accepting payment, expire the exact
session at Stripe, preserve its local audit row, reconcile any event already
received and verify no successor session was created. Continue the legacy
webhook until the incident and remaining runoff inventory are terminally
resolved.
