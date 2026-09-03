# Beta audit for Aimee Engine 0.2.0

What was reviewed before packaging this beta, what was changed, and what could
not be verified from here. Read this before testing as user 121.

## Scope of the beta

- Only enrolled users see anything different. The plugin ships enabled with
  the allowlist set to user `121`. Every other account keeps Aimee Global's
  theme app and legacy engine exactly as today.
- For an enrolled user the plugin swaps two things: the chat page (streaming
  replies, mobile menu, membership panel) and the reply engine. Sign-in,
  onboarding, pricing, gallery, privacy, SMS, voice calls, push and billing
  endpoints are all still Aimee Global's.
- An administrator can switch a single request back with the header
  `X-Aimee-Engine: legacy`, or move any user between engines on their
  WordPress profile screen.

## Chat surface

Reviewed: Aimee Global's bundled chat template (`templates/shared/chat-fallback.php`),
the injected chat helpers in `includes/legacy-ui.php` (privacy choices, security
bridge, gallery discovery, release feedback, public statement, billing migration,
media delivery acknowledgements) and the history, message, settings and
media-catalog endpoints.

Findings and what the engine page does about them:

| Finding | In Aimee Global | In Aimee Engine's page |
|---|---|---|
| Replies arrive as one block after the whole turn | By design of the REST contract | Streamed word by word over server-sent events, with a typing state in the header |
| On phones the sidebar is hidden, so Membership, Settings and Sign out are unreachable in the bundled template | Only affects the bundled fallback; the theme app has its own menu | Header menu opens a bottom sheet with all of them |
| History loads once; a message Aimee sends on her own (continuity, autonomous) does not appear until reload | The theme app is patched to refresh; the bundled fallback is not | Refreshes every 10 s while idle, on focus and on tab return, without disturbing scroll |
| Text-only send failures show "Select the photo again to retry" | Copy bug in the bundled fallback | Accurate toast, and the conversation is re-synced from the server |
| After returning from GoCardless the page never verifies the new membership; the paywall could stay open until a reload | Bundled fallback | Detects the `membership=success` return, calls `/subscription-status` with retries while GoCardless is still confirming, shows the result and closes the modal |
| Membership modal shows plans only, never the current state | Bundled fallback | Status card: preview replies left, active plan and renewal date, cancelled renewal, past due, reconnect required, checkout-opens date |
| No way to manage or cancel billing from chat | Bundled fallback | Manage billing and Cancel renewal appear when Global reports `can_manage_billing` |
| Enter always sends, awkward on phones | Bundled fallback | Enter sends on desktop; on touch devices Enter is a new line and the button sends |
| No day separators or times on bubbles | Bundled fallback | Day separators and a small time on each bubble |

The engine page keeps Global's injected helpers that do real work: media
delivery acknowledgements (images carry `data-delivery-id`), gallery discovery,
release feedback, the public statement notice and the billing migration UI
(the page uses the element ids that script looks for). The security bridge is
not injected because its two jobs, image event ids and Camera Roll references,
are done natively by the page and it would otherwise mount a duplicate chip.

## Membership and payments

**Update for Aimee Global 1.8.11.** The first sandbox checkout from the engine page failed at the provider with `Sweeping is required for a VRP mandate`: the creditor is not enabled for commercial VRP. Aimee Global 1.8.11 switches the mandate scheme to Bacs Direct Debit by default and grants access provisionally while the first collection clears. See `aimee-global/GOCARDLESS-DIRECT-DEBIT-1.8.11.md`. The engine page copy now explains Direct Debit timing.


Reviewed in code, not executed: `/subscription-checkout`, `/subscription-status`,
`/subscription-cancel`, `/billing-portal`, the GoCardless billing request flow,
the return redirect and the subscription snapshot.

- New membership checkout is GoCardless and UK only by policy in Aimee Global
  1.8.10; US profiles get a plain "unavailable" state. The engine page reflects
  that rather than showing dead buttons.
- Checkout creates a GoCardless Billing Request Flow and returns
  `checkout_url`; the flow redirects back to the chat page with
  `membership=success&provider=gocardless&billing_request=…` or
  `membership=cancelled`. The status endpoint deliberately ignores the query
  and reconciles the Billing Request stored against the signed-in user, which
  is the right design. The engine page follows it.
- Goodwill, service-grace and legacy-Stripe reactivation states are surfaced
  by Global's snapshot (`checkout_available`, `checkout_opens_at`,
  `requires_reactivation`, `can_manage_billing`) and the page renders each.
- **No billing code was changed.** The engine calls Global's endpoints exactly
  as the bundled template does. Global's own GoCardless regression suites
  (`tests/gocardless-*.py`, `tests/gocardless-creditor-binding-regression.php`)
  remain the authority for payment correctness.

Not verified here, and needed before opening the beta beyond user 121:

1. A GoCardless sandbox run from the engine page: choose a plan, authorise,
   return, confirm the status card flips to Active and the paywall closes.
2. Cancel renewal and Manage billing on an account with a managed
   subscription.
3. A legacy Stripe account in `migration_required` state, to confirm the
   billing migration UI mounts on the new page.

## Streaming transport

- Endpoint: `POST /wp-json/aimee-engine/v1/stream`, cookie auth plus the REST
  nonce, enrolled users only (others get the legacy JSON reply).
- Uses PHP cURL multi so the classifier runs alongside the streamed reply.
  Text is held until the classifier answers, then released live; if the
  classifier changes the moment the stream is dropped before any word is shown.
- The response sets `X-Accel-Buffering: no` and disables PHP output buffering.
  If the host's proxy still buffers, replies will arrive in one piece rather
  than word by word. That is the first thing to check on the live server: the
  header status should read "typing…" and text should appear progressively.
- Without cURL the engine falls back to the non-streamed path automatically.

## Reply engine

Unchanged from 0.1.2 apart from streaming: character card as facts, Global's
memory dossier, parallel classifier, refusal re-routing, specialist via
OpenRouter, photos as a tool, post-turn observer into Global's tables.

## Known limits in this beta

- Generated-on-demand photos (media sidecar `pending`) are declined for the
  turn rather than delivered later.
- Voice calls (`/voice/turn`) and SMS replies use Aimee Global's engine and
  prompts; only in-app chat is on the new engine.
- The theme app's extra screens (home, edit profile in-app, gallery in-app)
  are not reproduced; the engine page links to Global's gallery and privacy
  pages instead.
