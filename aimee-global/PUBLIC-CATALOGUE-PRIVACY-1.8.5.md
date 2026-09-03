# Aimee Global 1.8.5: public catalogue, Camera Roll and privacy repair

## Outcome

Version 1.8.5 adds an explicit compatibility mode for the site's existing
static Aimee catalogue at:

```text
/wp-content/aimee-private-media/catalog.json
/wp-content/aimee-private-media/<catalogue image files>
```

It also rebuilds the signed-in Camera Roll into natural album groups, opens
ordinary and specifically reviewed flirty items to every signed-in Aimee
profile, keeps explicit items behind current adult and relationship gates, and
adds a server-grounded **Ask Aimee about this** action to every visible item.

The signed-in privacy-choice panel is repaired so a confirmed privacy
acknowledgement dismisses the panel and remains dismissed. Explicit consent to
special-category processing remains a separate, optional choice.

The database schema remains `2026.08.20.3`. GoCardless remains the only source
of new UK membership checkout; new US membership and SMS-bundle checkout remain
unavailable, and Stripe remains legacy runoff only.

## Enabling the fixed public catalogue

Place this exact declaration in `wp-config.php` before WordPress loads the
plugin:

```php
define('AIMEE_PUBLIC_MEDIA_CATALOGUE_MODE', 'operator_approved');
```

The value is an exact, case-sensitive acknowledgement. Boolean `true`, a
different string, an arbitrary directory, and an arbitrary URL do not enable
the mode. The plugin resolves only the fixed directory immediately beneath the
real WordPress content root. That root must be a readable real directory; the
catalogue and selected images must be readable regular files. None may be a
symbolic link or escape the fixed root.

When enabled, the plugin:

- reads only `catalog.json` in that fixed directory;
- retains the files in place and performs no private-catalogue copy, migration
  or public-source deletion;
- accepts the established legacy catalogue records without requiring the newer
  SHA-256 manifest fields;
- applies conservative compatibility defaults to newer policy fields omitted
  by an old record;
- requires the filename extension to match the declared image MIME, then
  validates the selected file's size, actual MIME and decodable image
  dimensions before it can be used; and
- emits the direct WordPress content URL for an unbound/legacy catalogue item.
  Delivery-bound in-app images continue through Aimee's authenticated transfer
  endpoint so materialized-asset selection, transfer, render and unlock
  milestones are recorded correctly.

If the sentinel is absent, the 1.8.3+ outside-web-root private catalogue and
hash-manifest rules remain unchanged. If the sentinel is present but the fixed
directory, catalogue or file validation fails, catalogue media fails closed;
the plugin does not silently fall back to a different public path.

For a legacy record that omits newer policy fields, compatibility is explicit:
`direct_request_allowed` defaults to true, `allowed_channels` defaults to chat,
voice, voice note and continuity, and proactive sending defaults to the old
`allow_random_send` value for safe items but false for every non-safe item.
The normalized record continues to require membership for non-safe delivery.
Minimum adult assurance defaults to `verified` for erotic/explicit items,
`self_attested` for other non-safe items and `none` for safe items. Any field
already present—including an explicit false value—is preserved. The Camera
Roll's separate browse policy below deliberately opens only four reviewed
legacy flirty records; it does not widen attachment delivery or future
non-safe records by rating alone.

To avoid scanning all catalogue files on every ordinary WordPress request, a
successful full validation may be reused for up to 15 minutes only while the
fixed catalogue path and catalogue SHA-256 remain unchanged. Every image is
still validated from its live bytes when selected. Upgrade finalization and the
administrator health view perform a current full catalogue check, and a changed
or missing catalogue forces immediate revalidation.

## Camera Roll albums and per-item access

The Camera Roll requires a signed-in WordPress user with an Aimee profile; it
does not require an active membership merely to browse. The server filters the
catalogue before rendering it or returning the catalogue API:

- every `safe` item is visible to any signed-in Aimee profile;
- only the four operator-reviewed legacy flirty records
  `black_top_selfie_01`, `black_top_selfie_02`,
  `post_shower_towel_selfie_01` and
  `black_lingerie_mirror_selfie_01` are generally browseable, and each still
  requires its declared adult-assurance floor;
- a future flirty/suggestive record is not opened merely because it carries
  that rating; until reviewed it retains active-membership, assurance and
  acknowledged-delivery requirements; and
- erotic/explicit records require a current active membership, verified adult
  assurance, current special-category consent, a valid durable relationship
  state, no active or unresolved rupture, the record's declared policy floors,
  at least an intimate stage, sufficient trust/chemistry/safety, low
  frustration, at least 28 meaningful interactions and at least three
  qualified sessions.

The same current per-item predicate is applied by the gallery, catalogue API,
conversation history, relationship timeline, voice-note polling and media
transfer controller. Withdrawing consent, losing adult assurance, membership
lapse or relationship demotion therefore removes an explicit item from every
application surface; an old sent/unlocked or timeline row is not a permanent
adult-content bypass.

Visible items are placed deterministically into **Family**, **Friends**,
**Holidays & Travel**, **Nights Out & Celebrations**, **Days Out &
Adventures**, **Active & Wellbeing**, **Style & Getting Ready**, **Everyday
Moments**, **Throwbacks** and **Just Between Us**. Album headings and text are
plugin-owned; manifest text cannot inject a new group or heading.

## Asking Aimee about a photograph

Each visible card has an **Ask Aimee about this** action. The browser stores
only the catalogue key and creation time in same-tab session storage for up to
ten minutes; the key is not placed in the URL, referrer or browser history.
The chat composer shows a cancellable generic context chip and preserves an
existing draft. Selecting an upload, starting voice, cancelling or dispatching
the question consumes the handoff, so it cannot silently carry into another
turn.

The REST handler ignores client descriptions and resolves the key again from
the current canonical catalogue and current account policy. It rejects a
simultaneous uploaded image, rechecks profile/chat/item access immediately
before the reply provider, supplies only bounded server-owned description and
tags, and locks that turn to text discussion. A gallery reference cannot send,
resend, unlock or attach media.

Aimee may use visible composition, styling and atmosphere and may add small,
low-stakes texture when it is clearly framed as interpretation, imagination or
her chosen visual world. She must not turn catalogue copy into a literal
offline human biography, named-person claim, exact event/date/location,
sensitive fact, user memory or relationship evidence.

## Public exposure is intentional in this mode

Anything under `/wp-content/aimee-private-media` may be reachable without
sign-in through a static URL. This includes files classified as explicit if
they remain in that public directory, and the public `catalog.json` may reveal
their filenames. Aimee still applies membership, relationship,
adult-assurance, consent, channel and unlock policy before selecting or showing
an item inside the application. Those application checks cannot stop somebody
from requesting a known static file URL directly, nor can account deletion
revoke a copied or cached public image. True URL-level protection for explicit
bytes requires moving them behind authenticated delivery or a web-server/CDN
rule; that is incompatible with making the whole directory publicly readable.

Public URLs may also appear in browser, CDN, proxy and web-server logs. For a
legacy record with no SHA-256, the plugin can reject the wrong MIME, malformed
image, unsafe dimensions or path, but cannot distinguish one valid same-MIME
image from another valid replacement. Add reviewed hashes to records later if
byte-level integrity detection is required.

Enable this mode only when that exposure is accepted and the web server does
not contain an old deny rule that contradicts the desired public access. CDN
and browser caches may retain public bytes after a file is changed or removed.

## Privacy-choice behaviour

The floating panel is an acknowledgement prompt, not a compulsory request for
special-category consent. Its corrected lifecycle is:

1. Fetch the authoritative saved choices from the authenticated REST endpoint.
2. If the current privacy notice is already acknowledged, do not leave the
   prompt mounted.
3. On save, persist privacy acknowledgement and special-category consent as two
   independent booleans.
4. Remove the prompt only after the server confirms
   `privacy_acknowledged=true`.
5. If acknowledgement is not selected or the request fails, retain the prompt
   and show an accessible status message.

Declining or withdrawing special-category consent is a supported state. It
does not block ordinary signed-in chat. The current server-side gates continue
to deny specialist adult processing and any erotic/explicit path requiring
that consent. The choice remains available in profile/settings surfaces.

New registration likewise requires the privacy acknowledgement but does not
force special-category consent. When consent is not given, its timestamp and
version are stored as null rather than manufacturing consent.

## Deployment checks

1. Back up the plugin and database, then replace the plugin with the packaged
   1.8.5 archive.
2. Add the exact public-mode sentinel above. Do not define
   `AIMEE_PRIVATE_MEDIA_DIR` as the public directory.
3. Confirm the server can read
   `WP_CONTENT_DIR/aimee-private-media/catalog.json` and the referenced files.
4. Clear PHP opcode, WordPress object/page, CDN and service-worker caches.
5. In **Settings → Aimee Global**, confirm build `1.8.5`, schema
   `2026.08.20.3`, public catalogue mode active, and the required catalogue key
   and file found.
6. Test safe and all four reviewed flirty images with a signed-in profile that
   has no active membership. Confirm a future/unreviewed non-safe item remains
   closed.
7. Test every explicit item through an eligible account and through accounts
   missing membership, verification, current consent, relationship maturity or
   rupture clearance. Confirm gallery, catalogue API, history, timeline,
   voice-note polling and media controller agree.
8. Use **Ask Aimee about this** from both the theme-owned chat and plugin
   fallback chat. Confirm cancel/upload/voice clears the reference, the key is
   absent from the URL, and one dispatch cannot attach or resend a photo.
9. With special-category consent left unticked, save the privacy
   acknowledgement. Confirm the panel disappears immediately, stays absent
   after reload, and ordinary chat works.
10. Open Settings, opt in to special-category processing, save, then withdraw
   and save again. Confirm ordinary chat remains available while specialist
   processing becomes unavailable.
11. Re-run the GoCardless signed-webhook and UK checkout staging checks from
   1.8.4. Confirm no new Stripe Checkout Session is created.

The bundled deterministic audit suite verifies source behaviour but cannot
prove the production web server's public URL, CDN rules, filesystem contents,
MariaDB state or live payment-provider configuration.
