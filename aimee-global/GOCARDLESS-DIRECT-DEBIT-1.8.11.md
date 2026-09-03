# Aimee Global 1.8.11: Bacs Direct Debit membership checkout

## Why

Since 1.8.0 the UK checkout has requested a Faster Payments commercial
Variable Recurring Payment mandate with Direct Debit fallback. GoCardless
only permits commercial (non-sweeping) VRP on creditors it has enabled for
it. On a creditor without that entitlement the Billing Request create is
rejected with HTTP 422 `validation_failed: Sweeping is required for a VRP
mandate`, and 1.8.10 reported that as "The bank checkout create result is
ambiguous and will not be repeated", leaving the account's checkout intent in
`request_unknown` on every retry.

1.8.11 makes the mandate scheme configurable and defaults to Bacs Direct
Debit, which every GoCardless creditor can use.

## Configuration

`GOCARDLESS_MANDATE_SCHEME` in `wp-config.php`:

- unset or `bacs` (default): Bacs Direct Debit mandate, no spending
  constraints, no instant-payment fallback.
- `faster_payments`: the 1.8.0 commercial VRP mandate with constraints and
  Direct Debit fallback, for creditors GoCardless has enabled.

No schema change. The schema remains `2026.08.20.3`.

## What changes at checkout

- The Billing Request body for `bacs` carries `purpose_code`, the three
  immutable metadata pairs (intent, generation, terms) and a `mandate_request`
  of currency, scheme and description. `fallback_enabled`,
  `payment_context_code`, `payment_purpose_code` and `constraints` are sent
  only for `faster_payments`.
- The stored-intent check and the provider terms check are scheme-aware. For a
  Direct Debit mandate the amount and cadence are bound by the terms tuple
  every payment is verified against; there are no mandate limits to compare.
- A stored checkout intent built for a different scheme, with no Billing
  Request bound to it, is abandoned and a fresh intent starts. This releases
  accounts stuck in `request_unknown` after the VRP rejection without any
  manual reset. A Billing Request that does exist at the provider under the
  old intent stays unfulfilled and unpaid; nothing is charged from it.

## What changes after authorisation

Faster Payments settle within moments, so 1.8.0 granted access only on a
`confirmed` or `paid_out` payment. A Bacs collection takes several working
days to confirm. 1.8.11 therefore:

- grants access **provisionally** when a Direct Debit collection is created
  (`pending_submission` or `submitted`), setting the period start and end
  exactly as a confirmed payment would, so a new member is in immediately and
  a renewing member never sees a gap;
- on `confirmed` or `paid_out`, records the confirmed payment and restores
  the renewal boundary to the period end (the hourly poll moves it while a
  payment is pending);
- if a **provisionally applied** collection later fails, is cancelled or is
  charged back, ends access immediately: `subscription_status` becomes
  `past_due`, the period end is set to now and renewal is cancelled. A new
  checkout supersedes the mandate cleanly. Failures of an already-confirmed
  payment keep the 1.8.0 behaviour (renewal stops at the period end).

The Faster Payments path is unchanged when the constant selects it.

## Risk you are accepting

Provisional access means up to one billing period of access can be consumed
before a failed first collection is known. Direct Debit failures are usually
reported within three to five working days of the charge date. For the
annual plan this is the meaningful exposure; the weekly and monthly plans
bound it naturally. Watch `gocardless_last_failure_at` and `past_due`
profiles in the first weeks.

## Verification

- `tests/gocardless-direct-debit-1.8.11-regression.php`: Direct Debit intent
  payload shape, scheme-aware intent and terms matching, access statuses per
  scheme, abandonment of a stored VRP intent.
- `tests/gocardless-creditor-binding-regression.php` now pins the Faster
  Payments scheme explicitly so its VRP expectations still hold.
- Sandbox: complete a checkout, confirm the status card reads Active on
  return while the payment is `pending_submission`, then use the sandbox
  payment failure scenario and confirm access ends and the checkout reopens.
