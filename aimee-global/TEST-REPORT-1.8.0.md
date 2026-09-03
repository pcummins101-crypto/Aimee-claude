# Aimee Global 1.8.0 — GoCardless Recurring Pay by Bank

## Result

- Native audit: 25/25 commands passed
- Assertions: 2,212/2,212 passed
- Production PHP syntax: 46/46 files parsed
- No live GoCardless API call was made during packaging because credentials are intentionally not embedded in the plugin.

## Integration

- GoCardless Billing Requests API
- Hosted Billing Request Flow
- UK Faster Payments Commercial VRP mandate
- Direct Debit fallback enabled
- Server-side first and renewal payment creation
- Webhook HMAC verification and event deduplication
- Paid access extended only after verified payment confirmation
- Cancellation stops future mandate collection while preserving the paid-through access period
- Hourly renewal worker
