# Aimee Global 1.8.1 — Romantic prose authority + hardened GoCardless billing

1.8.1 contains the GoCardless Recurring Pay by Bank integration from 1.8.0 plus the
1.7.10 romantic response hotfix and a second, higher-effort review of the payment
lifecycle before live deployment.

## Romantic response regression

A safe natural reply is no longer discarded merely because hidden romantic metadata
selected `initiate`, `reciprocate`, or another expressive reason while the visible
wording did not match the conservative explicit-flirt phrase detector. The visible
reply is authoritative and metadata is normalized to the expression actually delivered.

Hard repair/fallback remains reserved for genuinely unusable or policy-conflicting
visible content.

## GoCardless hardening found during the 1.8.0 re-audit

- A new bank checkout explicitly clears cancellation and stale GoCardless mandate/payment
  state so an old cancelled predecessor cannot suppress the first collection.
- cVRP consent constraints include a start date and retain the plan amount/frequency cap.
- Faster Payments collections are created off-session with an explicit charge date.
  Success+ retry is used only on Direct Debit fallback, not on VRP payments.
- Payment confirmation is protected with a database row lock and transaction so duplicate
  or concurrent `confirmed` / `paid_out` delivery cannot extend one membership twice.
- Webhook events are recorded as processed only after required GoCardless lookups and local
  transitions succeed. Transient failures return a retryable non-2xx response.
- Cancelled, failed, expired or blocked mandates stop future collection without erasing a
  period the customer has already paid for.
- The historical Stripe-style billing portal response has been replaced with a usable
  in-Aimee management target, and UK/shared pricing copy no longer promises unsupported
  Stripe portal actions or in-place plan switching.

Schema remains `2026.08.19.1`.
