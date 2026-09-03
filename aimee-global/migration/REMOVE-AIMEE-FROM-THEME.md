# Theme-engine removal and 1.8.8 payment cutover

## Active deployment checklist

1. Back up the website files and database, then clone the live site to staging.
2. Before changing code, inventory every open Stripe membership or SMS
   Checkout Session issued by a pre-cutover build. Reconcile completed sessions
   and explicitly expire every session that must no longer be payable; deploying
   the plugin does not revoke an already-issued Stripe-hosted URL.
3. Install Aimee Global 1.8.8 on staging. Allow the plugin upgrade to complete;
   do not edit installed-version or schema options manually.
4. In the active theme's `functions.php`, remove the Aimee block beginning with
   `Aimee AI Backend Engine` and continuing to the end of the Aimee/PWA code.
   Keep ordinary Twenty Twenty-Five functions and unrelated site code.
5. Activate or reactivate Aimee Global and confirm **Plugin engine ready** under
   **Settings → Aimee Global**.
6. Keep the canonical Aimee app page-template file in the active theme. The
   plugin uses it as the visual source for the established UK chat UI.
7. Confirm the settings page reports plugin build `1.8.8`, schema
   `2026.08.20.3`, and this checkout policy:
   - new UK/GBP memberships: GoCardless only;
   - new US paid memberships: unavailable;
   - new SMS bundles: unavailable, while existing balances remain usable; and
   - Stripe: legacy runoff only, never a new-checkout fallback.
8. Configure the staging GoCardless account with
   `GOCARDLESS_ACCESS_TOKEN`, `GOCARDLESS_WEBHOOK_SECRET`, the exact matching
   `GOCARDLESS_CREDITOR_ID`, and `GOCARDLESS_ENVIRONMENT=sandbox`. Configure the
   GoCardless webhook endpoint as
   `/wp-json/aimee/v1/gocardless-webhook` using the same webhook secret.
9. Test `/chat/` and `/chat-us/` in separate private sessions, including a new
   account and a legacy member. In GoCardless sandbox, exercise UK
   authorisation, signed webhook processing, duplicate/out-of-order events,
   payment failure and retry, cancellation, and account deletion. Confirm US
   paid checkout and new SMS-bundle checkout fail closed before provider
   contact.
10. If preserved, goodwill or service-grace access is active, confirm UK
    checkout creates no Billing Request, hosted flow or payment before the
    server-provided checkout-open time. Then test the replacement GoCardless
    membership journey after that time in staging.
11. Keep the legacy Stripe credentials, configured account identity and signed
    webhook reachable only while pre-cutover records still require status,
    cancellation, portal access, reconciliation or deletion. Record the Stripe
    Checkout Session creation count at cutover and confirm it remains flat.
12. Clear PHP opcode, WordPress object/page, CDN and service-worker caches. Move
    to production only after schema health, member/access dates, GoCardless
    webhook reconciliation, legacy Stripe runoff and the zero-new-Stripe-
    checkout check all pass against the exact packaged archive.

The plugin delays loading its engine until after the theme loads, preventing a
redeclaration fatal while the old theme block is being removed. If a theme
engine is still detected, bundled Aimee REST routes, autonomous workers,
private-media admin-post and Camera Roll paths, and WordPress-routed FireText
sends are blocked. This is a migration safeguard, not a supported dual-engine
mode; remove the theme-owned Aimee engine before relying on the plugin.

## Historical Stripe instructions

Earlier versions of this guide instructed operators to install 1.7.3, verify
schema `2026.08.03.6`, confirm a replacement Stripe account and complete a new
Stripe Checkout. Those instructions are historical and must not be followed
for 1.8.8.

Stripe identifiers from the former account remain only in legacy audit fields
and must never be restored to active billing fields. Retained Stripe code is
limited to safely managing and closing payment records created before the
GoCardless-only cutover. Remove Stripe credentials and its webhook only after
every legacy subscription, session and ambiguous pre-cutover intent has been
terminally reconciled under the retention policy.
