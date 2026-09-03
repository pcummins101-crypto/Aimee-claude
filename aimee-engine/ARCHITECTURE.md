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
  ├─ User message stored
  │
  ├─ Provisional read (deterministic regex) → what the main model is told, which photos it may offer
  │
  ├─ Context
  │    system[0]  character card            (stable, cache_control: ephemeral)
  │    system[1]  dossier                    profile projection + relevant memory + opinions + mood
  │    system[2]  RIGHT NOW facts            time, gap, who, stage, membership, SMS, photos, the moment
  │    messages   transcript from rows       timestamps only when time has passed; photos noted in words
  │               + current message          image block if he attached one
  │
  ├─ Classifier and main model IN PARALLEL (ordinary turns)
  │    classifier (Haiku, structured output): everyday | erotic | abusive | unsafe, + tone and flags
  │    primary (Claude, send_photo tool when photos are eligible)
  │    Turns that already look explicit run the classifier first, since it decides who writes.
  │
  ├─ Aimee Global relationship maths with the real classification
  │    classification → legacy intent → aimee_calculate_intimacy_state()
  │    aimee_appraise_user_turn() keeps inner state and relationship events moving
  │    relationship state and profile stage saved
  │
  ├─ Resolve
  │    erotic + policy allows + OpenRouter key → SPECIALIST (in-flight primary reply dropped)
  │        optional brief (Claude, ≤80 words, non-explicit) → OpenRouter with card + facts + dossier + brief
  │        photo chosen via [[photo:KEY]] token from the specialist-eligible list
  │    classification changed the moment (abusive, unsafe, erotic-but-not-allowed) → facts rebuilt, primary called fresh
  │    otherwise → the parallel primary reply is used as-is
  │        tool_use send_photo → Global's decision + delivery pipeline → tool_result → final message
  │        stop_reason refusal → specialist if allowed, else one retry with the moment named plainly
  │    everything failed → short deterministic line, telemetry records why
  │
  ├─ Persist Aimee's message; delivery transitions message_created → returned_by_direct_api
  ├─ Preview usage, ledger, timeline, telemetry
  ├─ Observer scheduled (WP-Cron, 3 s) → Haiku reads the exchange → memory / opinion / self-observation
  └─ Same response shape as Aimee Global + engine: "v2"
```

## Streaming

`POST /aimee-engine/v1/stream` runs the same turn with an emitter. The primary
call is made with `stream: true` through cURL multi, with the classifier in
the same multi handle. Text deltas are held until the classifier answers; if
the route is still `everyday` they are released and the rest streams live,
otherwise the primary handle is dropped and the turn continues on the
sequential path (specialist, or a fresh primary call with the moment named).
A refusal after text has been shown sends `replace` so the client clears the
bubble before the re-routed reply. `done` carries the same JSON as `/message`.

## The engine chat page

`template_include` at priority 100 swaps Aimee Global's chat template for
`templates/chat.php` when the signed-in user is enrolled. The page is the
engine's own (streaming client, membership panel, mobile menu) and keeps
Global's injected helpers that do real work: media delivery acknowledgements,
gallery discovery, release feedback, the public statement notice and the
billing migration UI.

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

## Latency

One ordinary turn costs one round trip of the slowest call (the main model) plus database work. The classifier rides alongside it, headlines and weather are cached for fifteen minutes, and Aimee Global's helpers in the path make no network calls. Telemetry records `timings` per turn (gates, context, classify, relationship, generate, persist) and the settings page shows the breakdown.

## Looking things up

The primary call declares Anthropic's server-side `web_search` and `web_fetch` tools next to `send_photo`. Search and fetch run on Anthropic's side; their results come back as content blocks in the same reply, so Aimee can check a score or a fact mid-sentence. When streaming, a `server_tool_use` block switches the header to "looking that up…" or "reading…" and the first text block switches it back to "typing…". A long server loop can end with `stop_reason: pause_turn`; the runner resends the assistant turn unchanged and the server resumes. Aimee Global's Brave search is not used.

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
