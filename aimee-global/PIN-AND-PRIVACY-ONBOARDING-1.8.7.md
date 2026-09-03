# Aimee Global 1.8.7 — PIN and privacy onboarding

## Release outcome

Version 1.8.7 restores a simple new-registration credential contract while
preserving every existing account. A new passcode is exactly six ASCII decimal
digits. Privacy-notice acknowledgement is no longer an onboarding, chat or
settings gate, while the notice stays easy to find and optional explicit
special-category consent remains independently revocable.

This document is the current authority for registration credentials and
privacy onboarding. It supersedes the 1.8.3 requirement for a new-account
passphrase of at least 12 characters and the 1.8.5 mandatory privacy-notice
acknowledgement flow. Those older documents remain factual descriptions of
their respective releases.

## New-registration passcode contract

The registration client and server accept a new passcode only when it matches:

```text
[0-9]{6}
```

This means exactly six ASCII characters from `0` through `9`. Full-width,
Arabic-Indic or other Unicode digits are not interchangeable with ASCII digits.
Whitespace, punctuation, letters, shorter values and longer values are also
invalid.

The passcode is an opaque string, never an integer. A value such as `085274`
must reach WordPress with its first zero intact. Client-side `pattern`, length
and numeric-keyboard hints improve the form, but the server remains the
authority.

New registration rejects these explicitly predictable sequences:

- `123456`
- `654321`
- `012345`
- any one digit repeated six times, from `000000` through `999999`

The weak-code rule is not retroactive. Do not block, rewrite or force-reset an
existing account merely because its historical six-digit credential now falls
on that list.

## Existing sign-in compatibility and throttling

The sign-in field remains format-free. Registration validation must never be
copied onto login. These account types continue to authenticate against their
unchanged WordPress password hash:

- existing six-digit passcode accounts, including leading-zero and historically
  weak codes; and
- accounts created during the 1.8.3 passphrase release with a passphrase of 12
  or more characters.

The existing alias-aware throttle still runs before WordPress credential
verification. Per-account and per-IP limits, generic authentication errors and
phone-alias grouping apply regardless of whether the stored credential is a
six-digit passcode or a longer passphrase. Compatibility is not a reason to
disable, bypass or widen the throttle.

## Privacy notice and optional consent

The privacy notice remains visibly linked during onboarding and from the
authenticated chat/settings journey. A user can open it at any time, but does
not have to tick an acknowledgement checkbox to create an account, use
ordinary chat or save settings. The former floating acknowledgement gate must
not reappear.

Registration without a separate factual acknowledgement stores
`privacy_acknowledged_at` as null. Simply creating an account, signing in,
opening the notice, chatting or saving unrelated settings is not proof that the
notice was acknowledged and must not create or backfill a timestamp. Existing
historical acknowledgement data is not erased by this release.

Special-category consent is a separate choice. It must remain:

- explicit and unticked by default;
- optional for registration and ordinary chat;
- versioned when granted; and
- revocable from settings, with withdrawal effective immediately.

An opt-in records the current consent timestamp and version. Withdrawal clears
the consent timestamp and version, disables the legacy specialist toggle and
removes access from every dependent route. A null privacy-acknowledgement field
does not prevent a user making this independent optional choice and must not be
silently filled when they do so.

## Adult and explicit-feature boundary

The onboarding change does not weaken adult or explicit-content controls.
Registration remains adults-only. Specialist sensitive/adult processing and
erotic or explicit media continue to require all applicable current server-side
gates, including trusted adult assurance, current special-category consent,
membership and the established relationship, interaction, session, rupture,
catalogue and per-item policy checks.

Leaving consent unticked or withdrawing it keeps ordinary chat available but
must fail closed across every dependent specialist and explicit path. Neither
privacy-notice visibility nor a historical acknowledgement timestamp is a
substitute for current explicit consent or adult assurance.

## Unchanged release boundaries

- Database schema remains `2026.08.20.3`; no schema migration or manual version
  edit is required.
- Relationship policy remains `2.2.0`.
- The 1.8.4 GoCardless-only new-checkout policy and legacy Stripe runoff are
  unchanged.
- The 1.8.5 catalogue/Camera Roll entitlements and the 1.8.6 authenticated
  image-delivery and discoverability behaviour are unchanged.
- Existing profile, media, billing, relationship and consent data is preserved.

## Deployment and cache invalidation

1. Back up the current plugin files and database, then deploy the exact 1.8.7
   package to staging before production.
2. Confirm the plugin header and **Settings → Aimee Global** report `1.8.7`,
   schema `2026.08.20.3` and relationship policy `2.2.0`. Do not edit version or
   schema options by hand.
3. Clear PHP opcode caches or restart the relevant PHP workers. Clear WordPress
   object and page caches, reverse-proxy and CDN caches, and any optimized or
   concatenated JavaScript cache.
4. Invalidate the Aimee service worker and browser Cache Storage/app-shell
   assets, then hard-reload both a signed-out onboarding page and a signed-in
   chat. Test a second browser or private session so an old form is not mistaken
   for the deployed release.
5. Treat any registration form still requesting a 12-character passphrase, or
   any required privacy-acknowledgement checkbox/floating prompt, as stale code
   or cache and a release blocker.
6. Run the complete bundled deterministic suite against the exact packaged
   archive. Inspect PHP, WordPress and database logs during the staging journeys
   below before admitting production traffic.

## Staging and production acceptance checks

### Registration and authentication

1. Create accounts through both the theme-owned onboarding and the plugin
   fallback onboarding with a non-weak six-digit code and with a leading-zero
   code such as `085274`. Sign out and confirm each exact string signs back in.
2. Confirm new registration rejects wrong lengths, spaces, letters and Unicode
   lookalikes such as `１２３４５６` or `١٢٣٤٥٦`.
3. Confirm it rejects `123456`, `654321`, `012345` and representative repeated
   codes such as `000000` and `777777`.
4. Sign in with a pre-upgrade six-digit account, including a leading-zero or
   historically weak credential where a controlled fixture is available.
5. Sign in with a pre-existing 12-or-more-character passphrase account. Confirm
   the login input has no six-digit pattern, numeric-only or maximum-length
   constraint.
6. Exercise failed authentication by login ID and phone aliases in staging and
   verify per-account and per-IP throttling and generic errors remain effective.
   Use controlled fixtures so a production user is not locked out by the test.

### Privacy and consent

1. Complete both onboarding journeys without acknowledging the privacy notice
   and without granting special-category consent. Confirm the privacy link is
   visible, the account is created, ordinary chat works and
   `privacy_acknowledged_at`, `special_category_consent_at` and
   `special_category_consent_version` remain null.
2. Open authenticated chat and settings. Confirm the notice remains visibly
   linked, no acknowledgement prompt blocks chat or settings, and saving an
   unrelated choice does not manufacture an acknowledgement timestamp.
3. Opt in to special-category processing and confirm the current consent
   timestamp/version is recorded without creating a privacy-acknowledgement
   timestamp.
4. Withdraw special-category consent in settings. Confirm its timestamp and
   version clear, the specialist toggle is disabled and ordinary chat remains
   available.
5. Check an account with a real historical privacy-acknowledgement timestamp and
   confirm the upgrade preserves it without treating it as adult consent.

### Adult, billing and gallery regression

1. Confirm under-18 registration still fails.
2. Exercise explicit content with each required gate independently absent:
   active membership, verified adult assurance, current special-category
   consent, relationship readiness and rupture clearance. Every dependent
   surface must fail closed.
3. With a fully eligible controlled account, confirm the established explicit
   path works; then withdraw consent and confirm access disappears immediately
   from the Camera Roll, catalogue API, history, timeline, voice-note polling
   and media controller.
4. Re-run the current GoCardless sandbox and legacy Stripe-runoff checks without
   creating a new Stripe Checkout Session.
5. Confirm safe/flirty Camera Roll browsing, authenticated image delivery,
   unavailable-item degradation and **Ask Aimee about this** behave exactly as
   documented for 1.8.5 and 1.8.6.

Do not approve production solely from static or deterministic tests. The
deployed PHP runtime, cache layers, theme-owned onboarding, WordPress account
data, MariaDB state and payment/media integrations require the live checks
above.
