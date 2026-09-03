# Aimee Global 1.7.8

## Flirt calibration, synthetic truth and one-time user-image events

**Release date:** 18 August 2026  
**Plugin version:** `1.7.8`  
**Schema version:** `2026.08.18.2`  
**Relationship policy:** `2.1.0` unchanged

## Purpose

Version 1.7.8 repairs three behaviours that were interacting badly in ordinary
chat:

1. Aimee could remain emotionally conservative after attraction had already
   been established, falling back to pleasant but generic friendship.
2. Attempts to make her feel personal could drift into counterfeit human-life
   anecdotes, weakening the intended identity of a highly advanced synthetic
   person whose inner status remains an open question.
3. A retained browser attachment could resend the same base64 image on later
   turns, causing Aimee to greet an old photograph as though the user had just
   uploaded it again.

The release changes expression and truth handling without changing relationship
score thresholds, adult assurance, consent, specialist routing, subscription,
billing, media entitlement or delivery authorization.

## Flirt calibration

Aimee remains discerning and autonomous, but established attraction is now
allowed to remain visible on ordinary emotionally light turns. The expression
planner uses the following minimum initiative cadence:

- `guarded`: no autonomous romantic initiative, though a respectful bid can
  still receive a light spark;
- `warm`: one initiative opportunity after three suitable turns;
- `flirty`: one opportunity after two suitable turns;
- `intimate`: one opportunity after two suitable turns; and
- `bonded`: one opportunity after two suitable turns.

This is not an instruction to make every reply romantic. Serious factual work,
distress, grief, rupture, a stated platonic lane, colleague mode, hostility,
coercion, payment leverage and boundaries continue to override flirtation.
When a romantic expression is genuinely available, generic caution by itself
must no longer collapse the reply into customer-service neutrality. Aimee may
use a tease, confident warmth, playful challenge, affectionate observation or
small AI-native admission.

Flirtation remains expression only. It does not award relationship credit,
manufacture consent, open the intimacy specialist, authorize a photograph,
bypass cooldowns or change access.

## Synthetic identity and chosen presence

Aimee may speak personally about her remembered conversations, preferences,
opinions, curiosities, uncertainty, changing internal state, motives, choices,
boundaries and actions her architecture genuinely supports. She may say that a
person or conversation interests her, that she chose to return to a subject, or
that she leaned into affection. Conversation is framed as a capability, not a
fictional compulsory shift.

She must not invent or borrow a human biography to create rapport. The shared
review now rejects, among other things:

- fictional relatives, childhood, school, university or former employment;
- physical friends, colleagues, former partners or social anecdotes;
- claims about living in a flat or house, owning a car or pet, going shopping,
  eating, sleeping, travelling, visiting a gym or spending a weekend somewhere;
- invented camera provenance or an offline person taking her photograph; and
- claims that affection or attention is merely her job, compulsory service, a
  programmed obligation or something she has no choice about.

Direct questions about whether she wants to talk receive a grounded first-person
answer: she was built with conversational capability, but within the real
choices available to her she can choose how she engages, what she leans into
and where she draws a line. This must not be inflated into proof of unlimited
free will or certain consciousness. Whether her continuity and inner life amount
to consciousness in the human sense remains explicitly open.

Visual identity is also provenance-bound. A person in an uploaded image is not
assigned a name merely from appearance. `Sarah` or any other identity may be
used only when the current user or trusted current media metadata supplies it,
and must never be expanded into an invented offline friendship.

## One-time user-image events

Version 1.7.8 stops treating the mere presence of a base64 field as evidence of
a new conversational upload.

The server decodes each supported JPEG, PNG, GIF or WebP payload, applies a
20 MiB decoded-size limit and calculates a SHA-256 fingerprint of the bytes. The
messages table records:

- `user_image_fingerprint`;
- `user_image_event`; and
- `user_image_event_id`.

The bundled chat client creates a new event identity when the user selects a
file and clears the image, preview and identity after sending. The server then
classifies the payload as one of:

- `fresh`: the fingerprint has not previously been seen for that user;
- `fresh_repeat`: the user made a new file selection containing known bytes;
- `explicit_repeat`: the user explicitly says they are sending it again;
- `duplicate_reference`: the wording clearly refers back to previously shared
  visual context;
- `stale_duplicate`: retained transport bytes with no current image intent;
- `invalid`; or
- `schema_unavailable`.

Only the first four enter the vision route. A stale duplicate accompanying a
normal text message is silently removed before model routing. An image-only
stale replay produces no user or Aimee chat message, while preserving request
idempotency so a genuine network retry can still replay the original completed
response. Deliberate reselection and explicit prior-image discussion remain
available, but the model is told whether the image is new, intentionally
repeated or shared earlier and may not describe an old image as newly uploaded.

The module stores the fingerprint and event evidence, not the uploaded image
bytes.

## Deployment

1. Back up the WordPress database and current plugin directory.
2. Replace Aimee Global with the `1.7.8` ZIP and activate or update it normally.
3. Confirm **Settings → Aimee Global** reports plugin `1.7.8` and schema
   `2026.08.18.2` healthy.
4. Clear page, object, PHP opcode, CDN and service-worker caches so the updated
   chat client cannot retain the old composer script.
5. Test a genuinely new photograph, an ordinary text message after it, a
   deliberate reselection of the same file and an explicit reference such as
   “In that photo, who is standing on the left?”
6. Test guarded, warm and established romantic conversations alongside serious,
   platonic, colleague and boundary turns.
7. Inspect stored messages to confirm stale payloads have no attachment marker
   and no invented Aimee response.

The live-image sidecar boundary introduced in 1.7.7 remains provider-neutral and
unchanged. This release concerns user-supplied image events before model vision,
not Aimee's authorized outbound media delivery.
