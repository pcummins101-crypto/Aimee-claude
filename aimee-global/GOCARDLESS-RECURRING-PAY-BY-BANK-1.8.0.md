# GoCardless Recurring Pay by Bank integration — 1.8.0

- Replaces live membership checkout with GoCardless Billing Requests and Hosted Billing Request Flows when `GOCARDLESS_ACCESS_TOKEN` is configured.
- UK checkout requests a Faster Payments Commercial VRP mandate with Direct Debit fallback.
- Uses one customer bank authorisation, then server-side off-session renewal payments.
- Payment confirmation extends membership; browser return alone never grants paid access.
- Signed webhooks are verified with `GOCARDLESS_WEBHOOK_SECRET` and deduplicated by GoCardless event ID.
- Cancellation cancels the mandate while preserving already-paid access until the local period end.
- Failed renewal attempts do not immediately remove already-paid access.
- Hourly renewal worker creates the next payment shortly before the current period expires.
- Existing closed-account Stripe identifiers remain archived for historical migration purposes; no new Stripe checkout is created while GoCardless is configured.
