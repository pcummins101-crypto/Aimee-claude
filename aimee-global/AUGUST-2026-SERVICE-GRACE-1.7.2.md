# Aimee Global 1.7.2 — August 2026 service-grace findings and implementation

Date: 3 August 2026  
Policy ID: `august_2026_processor_recovery`  
Release wrapper: `1.7.2`  
Relationship policy: `2.1.0` (unchanged from the audited 1.7.1 patch)  
Schema: `2026.08.03.6`

## Outcome

Every existing Aimee profile, and every profile created before the fixed
cutoff, receives complimentary full in-app access through August. The grant
ends at exactly:

- `2026-09-01 00:00:00 Europe/London`;
- `2026-08-31 23:00:00 UTC`; and
- Unix timestamp `1788217200`.

At that instant, an enrolled profile without a valid subscription in the
replacement Stripe account becomes `subscription_required`. The user must
explicitly choose a plan and complete new secure checkout. No old Stripe ID is
reused, no free Stripe subscription is fabricated, and no 1 September payment
or replacement mandate is created automatically.

## Finding: access is not a paid subscription

The former Stripe account is closed. Its customer, checkout and subscription
identifiers cannot provide a manageable renewal or a valid new mandate. Writing
`subscription_status=active`, assigning everybody a monthly plan, or displaying
1 September as a processor renewal would create false billing state.

The implementation records only these campaign-owned fields:

| Field | Meaning |
|---|---|
| `service_grace_code` | Fixed campaign identity |
| `service_grace_granted_at` | UTC audit time when the profile was enrolled |
| `service_grace_access_until` | Fixed UTC entitlement cutoff |

The grant and new-profile paths do not write a plan, Stripe ID, cancellation,
preview counter, relationship score, relationship dimension, adult assurance
or consent field. Installation and later reconciliation can safely enrol a
pre-cutoff profile without extending the fixed boundary.

## Deterministic transition

```text
Pre-cutoff Aimee profile
        |
        | before 2026-09-01 00:00 Europe/London
        v
Complimentary full in-app access
  - access_source: august_2026_service_grace
  - payment_scheduled: false
  - subscription checkout paused
        |
        | exact cutoff (no cron or cached expiry)
        v
Valid replacement subscription? -- yes --> Managed membership continues
        |
        no
        v
subscription_required
  - user chooses a plan explicitly
  - new replacement-account Checkout Session
  - payment only after explicit checkout completion
  - relationship memory and stage preserved
```

Every access request evaluates the timestamp. Boundary tests prove access is
active at `1788217199` and inactive at `1788217200` regardless of host timezone.

## Access, billing and relationship are separate

The subscription snapshot exposes the distinction directly:

```json
{
  "status": "service_grace",
  "access_active": true,
  "access_source": "august_2026_service_grace",
  "access_level": "full_in_app",
  "access_until": "2026-08-31T23:00:00+00:00",
  "billing_status": "legacy_closed",
  "billing_current_period_end": null,
  "next_payment_at": null,
  "payment_scheduled": false,
  "service_grace_active": true,
  "service_grace_until": "2026-08-31T23:00:00+00:00",
  "checkout_available": false,
  "new_subscription_required_at": "2026-08-31T23:00:00+00:00"
}
```

`current_period_end` remains a compatibility alias for older clients. New code
must use `access_until` for product entitlement and
`billing_current_period_end` for verified processor state.

The grant does not:

- increase intimacy, trust, affection, chemistry or safety;
- change stage, session evidence, score thresholds or model routes;
- provide adult assurance or mutual sexual context;
- create consent, willingness or payment entitlement;
- weaken pressure, coercion, hostility or non-consent vetoes;
- override media rating, catalogue, cooldown or delivery truth; or
- grant carrier-SMS access or allowance.

Eligible in-app conversation, voice and relationship-appropriate media remain
available normally. Adult or intimate behaviour still depends on the audited
relationship, adult-assurance, consent, context and Aimee-discretion layers.

## Replacement billing integrity

During active service grace, subscription and SMS-bundle checkout return
`service_grace_active`. The response states that no payment is scheduled and
that new subscription checkout opens on 1 September. This keeps a downstream
route from contradicting the universal no-charge notice.

After the cutoff, a subscription is managed only when all of these are true:

- `billing_migration_status=complete`;
- `billing_account_generation=stripe_2026_09_v1`;
- a current replacement-account `stripe_subscription_id` exists;
- Stripe status is `active` or `trialing`; and
- the verified current period has not expired.

Legacy IDs, local `active` text without provenance, missing periods,
`past_due`, `unpaid`, `incomplete`, `incomplete_expired`, `paused`, `cancelled`
and `canceled` do not grant managed access. A later individual goodwill period
may defer a first fair payment, but it does not create a mandate. If fewer than
Stripe's minimum safe trial hours remain, checkout stays blocked until goodwill
expires instead of charging immediately.

The replacement secret must match `AIMEE_STRIPE_ACCOUNT_ID`. Checkout acquires
a per-user database mutex before reading reusable state, binds idempotency to
the user, billing generation, plan and market, and never reuses a UK session for
the US market or vice versa. A second current-generation subscription ID cannot
silently replace an already-managed one; it becomes an explicit reconciliation
conflict.

Checkout completion requires an authoritative fetch and successful sync of the
exact subscription created by that session. Webhooks retrieve current Stripe
state before applying subscription changes, support item-level billing periods
and modern invoice-parent subscription IDs, and return retryable failures when
sync or event-ledger persistence fails. Older out-of-order events therefore
cannot resurrect canceled access.

Before launch, verify there are **zero unintended active or trialing
current-generation subscriptions** for the cohort. The plugin can prevent new
checkout, but it cannot cancel, refund or undo external processor state that
already exists.

## User notice

Signed-in UK and US chat shows a dismissible card:

> **A thank-you from Engram Intelligence**  
> As a thank-you for your patience while we rebuild our payment flow, full
> in-app access is complimentary through 31 August 2026, ending at 00:00 on
> 1 September 2026 (UK time). Any subscription held in our former Stripe
> account cannot renew, and no replacement subscription or payment has been
> created automatically. From that exact boundary, anyone who wants to continue
> with member access will need to create a new subscription. Until then, keep
> enjoying Aimee on us. With thanks — Engram Intelligence.

The card offers **Thanks — got it** and **View September plans**. Pricing and
membership views use the same facts and recheck state at the exact boundary.
The compact, bounded 1.7.1 feedback prompt may coexist with the service card;
the post-cutoff billing action state retains priority over optional notices.
Status loading retries with bounded backoff and refreshes on visibility,
connectivity and page-show events; a DOM observer mounts the card when a legacy
chat composer appears late. If a profile unexpectedly reports both service
grace and a scheduled payment, the free-August copy is replaced by a
non-dismissible reconciliation warning and new checkout remains blocked.

## Identity and carrier-SMS boundary

The August grant never creates carrier-SMS eligibility. Carrier SMS requires:

- an eligible managed subscription, independently of in-app grace (internal
  WordPress administrators may bypass billing, but not verification);
- `phone_verified_number` exactly matching the normalized current profile phone;
- a server-owned `phone_verified_at` proof time;
- a valid per-recipient IANA `sms_timezone`; and
- explicit `sms_opt_in=1`.

Registration with a mobile number is neither verification nor consent and now
starts with SMS off. A phone change clears verification and opt-in. This release
does not bundle a public OTP flow, so SMS remains unavailable until a trusted
server-side verification workflow records the proof. Safe Windows use the
recipient's timezone rather than a global server clock.

Inbound FireText calls require the secret token. FireText's direct receive
callback has no provider message ID, so the plugin validates the configured
destination and exact callback time, then fingerprints the normalized source,
destination, message, time and keyword. A trusted proxy-supplied provider event
ID takes precedence when available. A durable event row and lease are reserved
before relationship scoring or model work. Retries cannot advance intimacy
twice or send the same reply twice, and the SMS uses the exact
`aimee_message_id` returned by the turn. Query-string callback tokens must be
treated as secrets, redacted from logs and rotated if exposed.

Outbound SMS has an independent durable outbox. Each logical send persists a
unique send key and FireText correlation reference before quota mutation or a
provider call, then moves through `selected`, `reserved`, `sending` and one of
`queued`, `delivery_unknown` or `failed`. FireText's `X-Message` identifier is
captured when present. Transport timeouts and ambiguous provider responses keep
their quota and are not retried automatically; only a definite rejection is
refunded. The owner signup alert no longer has a raw-cURL bypass and is sent
only through the verified, opted-in, safe-hours-aware outbox.

Owner and colleague identity uses only configured `AIMEE_OWNER_USER_ID` and
`AIMEE_GEORGIA_USER_ID`. Editable phone numbers never grant privileged access
or persona identity. Owner/Georgia phone constants are notification
destinations only and are reserved from other profiles.

## Administrator evidence

**Settings → Aimee Global** reports the fixed policy and cutoff, enrolled count,
reconciliation time, absence of automatic payment, relationship-state
separation and carrier-SMS exclusion. The aggregate 1.7.1 feedback card remains
visible and reports each user's latest bounded response without exposing user
identity or conversation content.

## Changed production paths

- `aimee-global.php`: release/schema identity, policy load and upgrade grant.
- `includes/service-grace.php`: fixed policy, access/billing separation,
  generation proof, modern Stripe normalization, goodwill/terminal rules and
  verified-SMS boundary.
- `includes/schema.php`: campaign, billing coordination, verified-phone,
  recipient-timezone, inbound replay and outbound SMS delivery state.
- `includes/engine.php`: truthful snapshots; cohort enrolment; paused,
  serialized and idempotent checkout; authoritative webhooks; immutable
  internal identity; recipient-local verified SMS; callback replay protection;
  durable provider-send auditing; and safe cancellation/deletion checks.
- `includes/billing-migration.php`: generation-aware billing management.
- `includes/legacy-ui.php` and pricing/chat templates: Engram thank-you,
  boundary refresh and post-cutoff explicit-plan UI.
- `includes/admin.php`: service-grace and feedback evidence.

## Verification and deployment gates

The exact deterministic-suite result and clean-archive replay are recorded in
`TEST-REPORT-1.7.2.md`. All production PHP must parse on PHP 7.4 and 8.3.

1. Back up the WordPress database.
2. Reconcile Stripe and prove zero unintended replacement-account
   `active`/`trialing` subscriptions for this cohort.
3. Configure and verify `AIMEE_STRIPE_ACCOUNT_ID`, replacement price IDs,
   webhook secret and immutable owner/Georgia user IDs.
4. Install 1.7.2 and verify schema `2026.08.03.6` and the enrolled-profile count.
5. Inspect an exhausted preview, former member and new August profile; each must
   have in-app access with `payment_scheduled=false`.
6. Confirm subscription and SMS-bundle checkout return `service_grace_active`.
7. Confirm an adult/relationship-ineligible profile remains blocked from adult
   media and a bonded profile keeps its previous relationship state.
8. Test the exact cutoff with a controlled clock: access must become
   `subscription_required` without changing conversation or relationship rows.
9. After the replacement flow is ready, explicitly complete one staging
   checkout and verify the exact session subscription, generation and managed
   access.
10. Race two checkout requests and replay subscription webhooks out of order;
    verify one session/subscription and authoritative final state.
11. Verify portal, cancellation and account deletion against only the approved
    replacement account.
12. Keep carrier SMS disabled until verified-phone provisioning exists; then
    test direct-callback fingerprint replay, optional proxy-ID replay and two
    profiles in different IANA timezones.
13. Smoke-test the thank-you card, dismissal, September-plan link, pricing copy
    and exact boundary refresh in real signed-in UK and US browsers.

This package changes code and database policy only. It does not deploy itself,
contact users outside the in-product UI or make a live Stripe mutation.
