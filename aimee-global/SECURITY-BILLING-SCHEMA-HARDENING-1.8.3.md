# Aimee Global 1.8.3 hardening record

Release date: 20 August 2026  
Plugin: `1.8.3`  
Schema: `2026.08.20.3`  
Relationship policy: `2.2.0`

## Scope

This rebuild closes the release-blocking gaps found in the 1.8.2 GoCardless and
MariaDB package. It preserves the existing UK/US application, Stripe US lane,
relationship state, protected Aimee media catalogue and prior migrations.

## Billing invariants

- Stripe and GoCardless recurring checkout write an owner-bound intent, exact
  request payload and billing generation before the first provider POST. A
  retry reuses that payload and a stable idempotency key; an uncertain request
  remains visible until it is authoritatively reconciled.
- Stripe completion requires the exact stored Checkout Session, intent token,
  owner, plan, profile market and billing generation. A stale, duplicate or
  mismatched webhook cannot replace those values.
- UK checkout is routed to GoCardless only when the access token, webhook
  secret and payment-ledger schema are ready. US checkout remains on Stripe.
- Status, management, cancellation and deletion use the billing provider stored
  on the profile; global GoCardless configuration cannot reroute a Stripe user.
- Each completed Billing Request is accepted only for its stored owner, current
  billing-request ID, generation, plan, amount and GBP currency.
- The exact GoCardless Hosted Billing Request Flow request is saved before its
  first POST. Recovery verifies and reuses a stored provider flow or replays the
  same request with its original idempotency key; mutable profile data cannot
  change retry terms.
- The authorised terms are immutable profile fields. Renewals never re-price a
  mandate from current public plan settings.
- `aimee_gocardless_payments` owns idempotency, provider-payment identity,
  mandate/cycle/attempt uniqueness, processing leases, status and one-time
  membership-period application.
- Webhook metadata is not an ownership fallback. A payment must map through the
  stored mandate and ledger record, and duplicate or out-of-order events are
  reconciled against current provider state.
- Renewal work handles missed cron windows, bounded retries and starvation
  without collecting before the stored period boundary.
- Cancellation verifies both the provider result and local stop-renewal write.
  Account deletion refuses to erase a user while any checkout intent, Billing
  Request, flow, mandate or non-terminal ledger payment cannot be verified as
  retired or terminal.
- SMS-bundle fulfilment uses only immutable terms stored in its local pending
  row: billing generation, market, currency, product label, quantity and amount.
  A legacy pending row without those terms is refused rather than inferred from
  current settings or webhook metadata.
- The profile row is the only market authority. Market changes and all billing
  transitions serialize through the same owner-token lease.

## Deletion and concurrency invariants

- Account deletion writes `account_deletion_started_at` before any provider or
  filesystem work. Provider webhooks, renewals, consent changes and private
  media/voice workers reject that user while the tombstone is present.
- Stripe possibly-created sessions are reconciled and expired through verified
  provider reads. GoCardless retirement walks the complete durable intent,
  Billing Request, flow, mandate and payment ledger rather than only the latest
  profile pointer.
- The destructive transaction rechecks the exact live lease and tombstone
  immediately before deleting local rows. A failed attempt clears only its own
  tombstone and remains retryable; a successful attempt removes the profile.

## Database lifecycle

- Core, inner-life and engine-runtime schemas have separate exact health
  contracts covering required columns, named index order/uniqueness and InnoDB.
- Successful health checks are cached for five minutes; install/repair paths
  invalidate the cache and perform a real post-change verification.
- Installers use MariaDB advisory locks with an atomic WordPress-option fallback
  and bounded failure backoff.
- Existing `aimee_messages.id` and `message_id` primary-key deployments are
  detected and repaired without requesting a second AUTO_INCREMENT/primary key.
- The legacy push `sensitive` field is merged only after the replacement field
  exists and the copy is verified. Runtime health rejects a remaining legacy
  field.
- Billing migration is locked, checks every query/write, scopes itself to the
  intended current-generation legacy records and does not publish a false
  completion marker.
- Global plugin/schema options are finalized on `init` only after every schema
  domain and required local migration has passed.
- Schema `2026.08.20.3` adds the recurring checkout intent payload/state,
  account-deletion tombstone and immutable SMS checkout terms required by the
  contracts above.

## Authentication and privacy

- New passphrases require at least 12 characters; arbitrary strong passwords are
  accepted. Existing PIN accounts remain compatible behind the same throttle.
- The WordPress authenticate pipeline is protected by account/alias and remote-
  IP buckets. UK and US phone forms collapse to stable identities, successful
  login cannot clear the shared IP bucket, and user-facing errors are generic.
- Registration stores separate privacy acknowledgement and current-version
  explicit special-category consent timestamps.
- Specialist intimate text, erotic/explicit media selection and later private
  media viewing require verified server-side age assurance and active current-
  version special-category consent. Membership or administrator status is not
  an age-assurance substitute.
- Profile photos are byte/MIME/dimension/pixel checked, stored outside public
  roots, served only to the authenticated owner and verified absent during
  account erasure.
- Chat images have byte, signature, dimension and pixel ceilings before model
  use. Every shipped gallery uses only protected per-user catalogue payloads.
- Push delivery uses WordPress safe HTTP handling and rejects unsafe/non-HTTPS
  remote endpoints.

## Required staging

Before production, use a cloned MariaDB database and real provider test
environments. Exercise schema upgrades from both legacy message primary-key
layouts; concurrent schema and billing-migration requests; GoCardless duplicate,
late and reversed webhook sequences; interrupted Billing Request and flow
creation; retries after missed cron; Stripe US interrupted checkout and signed
webhook replay; stale/mismatched provider events; provider transition;
cancellation and deletion during workers; phone-alias throttling;
consent/version withdrawal; profile/chat image boundary files; gallery
authorization; and push DNS/redirect behavior. Confirm the exact configured
GoCardless creditor, verify schema `2026.08.20.3` from a real MariaDB upgrade,
and verify no secrets are present in the archive.

The plugin deliberately does not manufacture adult verification. Adult routes
remain closed until an external, trusted age-assurance integration writes the
server-owned verification fields.
