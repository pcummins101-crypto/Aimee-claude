# Aimee Global 1.8.10 — temporary goodwill-access UI correction

## Purpose

The server intentionally separates access entitlement from billing state. A
customer may have temporary full in-app access while still needing a new
membership later. In 1.8.9, the injected legacy chat UI read the future billing
flag first and displayed “Your complimentary August access has ended” even
though the server had granted access and chat was working.

Version 1.8.10 gives the authoritative pair below precedence in customer-facing
UI:

- `access_active: true`
- `access_source: goodwill_extension`

The billing flags are not erased or rewritten. They become actionable again
only after the goodwill entitlement is no longer active.

## Customer experience while the grant is active

- Chat continues normally and the expired-August membership card is removed.
- The membership label reports **Temporary access active**.
- Settings and pricing describe temporary **full in-app** access and use
  `bonus_access_until`, falling back to `access_until`, for the exact localized
  expiry date and time.
- Plan controls remain unavailable and cannot start a provider request.
- Genuine billing-management controls remain available when the server reports
  an existing manageable billing record.
- Copy states that the access grant did not create a subscription or schedule a
  payment. It makes no promise about unrelated pre-existing billing records.
- Open pages periodically refresh status, apply the state to membership UI that
  mounts after the initial response, and restore the ordinary managed interface
  or real membership prompt when the server reports the corresponding state.

The grant covers in-app access only. It does not create or replenish carrier
SMS allowance.

## Deployment

1. Install the 1.8.10 archive over the existing Aimee Global plugin.
2. Confirm **Settings → Aimee Global** reports build `1.8.10` and schema
   `2026.08.20.3`.
3. Clear PHP opcode, WordPress object/page, reverse-proxy/CDN and service-worker
   caches.
4. Fully reload an affected customer's chat.
5. Verify `/wp-json/aimee/v1/subscription-status` returns active goodwill access
   and that the expired-August card is absent.

No new database migration or payment-provider action is part of this release.
