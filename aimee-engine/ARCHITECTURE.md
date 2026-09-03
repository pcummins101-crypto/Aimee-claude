# Aimee Engine architecture

## One turn

```
POST /aimee/v1/message
  │
  ├─ rest_dispatch_request (priority 5; runs after the route's permission check and replaces the callback)
  │    enabled? keys? user enrolled? not the colleague persona?  → otherwise null, and the legacy handler runs untouched
  │
  ├─ Gates (identical to Aimee Global): rate limit, chat access, Camera Roll reference,
  │    image event resolution, request idempotency reservation
  │
  ├─ Load history rows (last N, oldest first)
  │
  ├─ Classifier (Haiku, structured output)
  │    route: everyday | erotic | abusive | unsafe
  │    tone:  neutral | warm | flirty | vulnerable
  │    + continuation / aimee_invited / consensual / respectful
  │    Deterministic regex fallback if the call fails.
  │
  ├─ Aimee Global relationship maths
  │    classification → legacy intent → aimee_calculate_intimacy_state()
  │    aimee_appraise_user_turn() keeps inner state and relationship events moving
  │    user message stored; relationship state and profile stage saved
  │
  ├─ Context
  │    system[0]  character card            (stable, cache_control: ephemeral)
  │    system[1]  dossier                    profile projection + relevant memory + opinions + mood
  │    system[2]  RIGHT NOW facts            time, gap, who, stage, membership, SMS, photos, the moment
  │    messages   transcript from rows       timestamps only when time has passed; photos noted in words
  │               + current message          image block if he attached one
  │
  ├─ Generate
  │    erotic + policy allows + OpenRouter key → SPECIALIST
  │        optional brief (Claude, ≤80 words, non-explicit) → OpenRouter with card + facts + dossier + brief
  │        photo chosen via [[photo:KEY]] token from the specialist-eligible list
  │    otherwise → PRIMARY (Claude, send_photo tool when photos are eligible)
  │        tool_use send_photo → Global's decision + delivery pipeline → tool_result → final message
  │        stop_reason refusal → specialist if allowed, else one retry with the moment named plainly
  │    everything failed → short deterministic line, telemetry records why
  │
  ├─ Persist Aimee's message; delivery transitions message_created → returned_by_direct_api
  ├─ Preview usage, ledger, timeline, telemetry
  ├─ Observer scheduled (WP-Cron, 3 s) → Haiku reads the exchange → memory / opinion / self-observation
  └─ Same response shape as Aimee Global + engine: "v2"
```

## Why the reply model returns prose, not JSON

In Aimee Global the conversation model must fill thirty fields per reply:
memory operations, opinion, self-observation, romantic intensity, media key,
and the reply text, all in one JSON object. That contract is what makes the
voice sound like paperwork. Here the reply is plain text and streamed-ready,
and a separate cheap observer call does the bookkeeping afterwards using the
same storage functions Aimee Global already has. The memory engine and the
inner-life tables are unchanged; they are just fed from a different place.

## Why photos are a tool

The old engine asks the model for a `media_key` and then spends a great deal
of code checking whether the reply claims a photo that was not sent. With a
tool, sending is structural: the enum of the `send_photo` tool is exactly the
set of keys Aimee Global says this user may receive right now, the platform
delivers it during the turn, and the model writes its message knowing whether
delivery succeeded. Every access check is re-run against a fresh profile
inside `aimee_engine_deliver_photo()`, using Aimee Global's decision and
delivery records so history, acknowledgements and private serving all work.

## Why the classifier still exists

Claude will not write explicit sexual content. On the current models that
arrives as a normal response with `stop_reason: "refusal"` and a category, not
an error. The classifier routes the obviously explicit turns to the specialist
before Claude sees them, and a refusal from Claude is treated as a second
routing signal rather than a failure. Whether the specialist is allowed at all
is still decided by Aimee Global's relationship policy (`use_intimacy_model`),
so membership, adult assurance, stage and consent gates are unchanged.

When the specialist is not allowed (free preview, early stage), the primary
model is told plainly, as a fact about the moment, that explicit intimacy is
not on the table right now and that she stays warm and unbothered. That
sentence replaces a refusal with a tease.

## Prompt caching

Order is `tools → system → messages`. The character card is identical for
every user and every turn, so it carries the single cache breakpoint. The
dossier and facts change every turn and sit after it. Tools are only present
when photos are eligible, which does change the prefix; that is a deliberate
trade because the tool enum is the permission model.

## Coexistence

- The engine registers no routes and creates no tables.
- It writes to Aimee Global's tables with Aimee Global's own functions wherever one exists (memory, opinions, inner state, relationship state, media decisions and deliveries, timeline, turn requests).
- A user can be moved between engines at any time; both read and write the same conversation history.
- SMS, voice and the colleague persona never enter the engine.
