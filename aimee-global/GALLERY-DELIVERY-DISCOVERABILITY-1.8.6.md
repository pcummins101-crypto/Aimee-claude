# Aimee Global 1.8.6 — Camera Roll delivery and discoverability

## Fault repaired

The catalogue API could successfully load titles, descriptions and album
metadata while every card image failed. The established media directory may
contain a deny-all `.htaccess` rule written by an older Aimee release. That
rule blocks a browser's direct static request but does not prevent WordPress
from reading an exact, validated file on the local filesystem.

Version 1.8.6 sends every in-application media payload to Aimee's existing
WordPress transfer controller. A request must be signed in. The controller
reloads the profile, rechecks the current catalogue predicate, resolves only
the canonical key and validates the selected file before streaming it with
private, no-store headers. The fix does not weaken, remove or edit the server
rule and does not alter operator-owned media files.

## Access policy retained

- Safe images and the four explicitly reviewed legacy flirty records remain
  available to every signed-in Aimee profile.
- Future non-safe records do not automatically inherit that exception.
- Erotic or explicit records still require current membership, verified adult
  assurance, current special-category consent, relationship maturity,
  interaction/session floors and no active or unresolved rupture.
- The gallery API, history, timeline, voice-note polling and media controller
  continue to reuse the current per-item predicate.
- A delivery reference remains ownership-bound and never falls back to generic
  catalogue access.

## Degraded catalogue behaviour

Each selected image is an independent availability unit. A missing, invalid,
symlinked, escaped, MIME-mismatched or otherwise untrusted file is excluded,
while unrelated valid records remain available. Settings reports the exact
skipped keys. Zero valid records still makes the catalogue non-operational.

This availability change is not an entitlement change. It cannot expose an
explicit record because eligibility is evaluated separately and again at the
transfer boundary.

## User entry points

The signed-in chat header now carries a persistent, at least 44-pixel
**Photos** control in both the historical theme UI and the plugin fallback UI.
The injection is idempotent and remounts after a client-side chat redraw. The
Camera Roll identifies itself as **Aimee’s Camera Roll**, keeps a clear route
back to chat and explains that a user can tap a photo to ask about it. Public
navigation consistently labels the destination **Aimee’s Photos**.

If an image fails after the page has rendered, the card replaces broken-image
alt text with an accessible temporary-unavailability message.

## Production verification

1. Install the release and clear PHP opcode, WordPress page/object, CDN and
   service-worker caches.
2. Sign in on the real mobile journey and open **Photos** from chat.
3. Confirm at least one valid safe image request targets
   `wp-admin/admin-post.php?action=aimee_private_media` and returns an image.
4. Confirm the two known unavailable keys, if still absent, are skipped rather
   than blanking other albums.
5. Use **Ask Aimee about this** and confirm the key-only handoff returns to the
   correct market chat.
6. Test explicit media before and after every membership, assurance, consent
   and relationship-readiness transition.
7. Review Settings → Aimee Global. A partial catalogue should say
   **Available — unavailable items skipped** rather than **Not ready**.

Direct static URLs may still be publicly reachable if the web server or CDN is
configured to allow them. The application controller does not claim to reverse
that operator-accepted exposure; it simply makes application delivery reliable
without depending on it.
