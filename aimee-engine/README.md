# Aimee Engine 0.1.2

0.1.2 cuts turn latency: the classifier now runs in parallel with the main model call instead of before it, headlines and weather are cached for fifteen minutes instead of fetched on every turn, and the settings page shows a per-phase timing breakdown for recent turns.

0.1.1 fixes a double-processing bug: 0.1.0 hooked `rest_request_before_callbacks`, which does not stop the original route callback, so Aimee Global answered every message a second time. The engine now hooks `rest_dispatch_request`, which replaces the callback.

A prompt-light conversation engine for Aimee that runs **alongside** Aimee Global.
It takes over one thing, the in-app chat turn (`POST /aimee/v1/message`), and only
for users who are enrolled. Landing pages, pricing, gallery, billing, SMS, voice,
the PWA and every database table stay exactly as Aimee Global left them.

The idea: modern Claude models already do tone matching, warmth, kisses, pet
names, British voice and emotional continuity natively. Aimee Global's 23,000
character system prompt and 30-field JSON reply contract were built to coax
that out of older models, and they now get in the way. This engine gives Claude
a short character card written as facts, the memory dossier, and a handful of
facts about right now, then lets her be.

## What stays, what changes

| Kept from Aimee Global | Owned by Aimee Engine |
|---|---|
| All tables and schema (`aimee_messages`, `aimee_long_term_memory`, relationship state, media decisions and deliveries, analytics) | The turn pipeline for enrolled users |
| Memory engine (`aimee_memory_context_for_turn`, `aimee_store_memory_from_contract`, REM sleep consolidation) | A post-turn observer that writes memory, opinions and self-observation instead of the reply model doing it |
| Relationship maths, stage progression, inner-life appraisal | Nothing changes here; the engine calls the same functions with a compatible classification |
| Media entitlement, decision and delivery pipeline, private serving, history | Photos become a **tool** (`send_photo`) on the primary model, so a photo that was not delivered by the tool was not sent |
| Explicit specialist configuration (`AIMEE_INTIMACY_MODEL`, OpenRouter key) | A four-way classifier, refusal re-routing, and an optional Claude-written brief so the specialist keeps her continuity |
| Access gates: preview limits, membership, billing reactivation, adult assurance, request idempotency, rate limits | Same checks, same response shapes, plus an `engine: "v2"` marker |

Not handled by the engine (they keep using Aimee Global): SMS turns, voice turns
and voice notes, the colleague persona, proactive and continuity messages, and
photos that must be generated on demand by the media sidecar (the engine
declines those honestly instead of sending an interim message).

## Install

1. Upload the `aimee-engine` folder to `wp-content/plugins/` next to `aimee-global`.
2. Activate **Aimee Engine**. Aimee Global must stay active.
3. `ANTHROPIC_API_KEY` and `OPENROUTER_API_KEY` in `wp-config.php` are shared with Aimee Global; nothing new is required.
4. Go to **Settings → Aimee Engine**, tick *Enable engine*, and either add tester user IDs to the allowlist or set individual users to *Always use Aimee Engine* on their WordPress profile screen.

Nobody else is affected until you switch the rollout to *Everyone*.

## Testing side by side

- The response carries `"engine": "v2"` when the new engine answered.
- Each Aimee message row stores `engine_v2 route=… model=…` in `evaluator_directive`.
- Turn telemetry goes to `aimee_analytics_events` as `engine_v2_turn` (routes, models, timings, refusal categories; never message text). Tick *Keep recent turn telemetry* to see the last 40 turns on the settings page.
- An administrator can force either engine for one request with the header `X-Aimee-Engine: legacy` or `X-Aimee-Engine: engine`.
- Set *Observer mode* to *inline* while testing so memory writes happen before the reply returns.

Run the unit tests with plain PHP (no WordPress needed):

```
php tests/run.php
```

## Models

| Role | Default | Why |
|---|---|---|
| Primary conversation | `claude-opus-5`, effort low | Best character and emotional range; low effort keeps it conversational and fast |
| Classifier | `claude-haiku-4-5` | Four outcomes, structured output, milliseconds |
| Observer | `claude-haiku-4-5` | Memory bookkeeping after the turn |
| Brief | primary model | Short private notes for the specialist |
| Explicit specialist | inherited from Aimee Global | Text-only OpenRouter model with automatic fallback |

See `ARCHITECTURE.md` for the turn pipeline and `CONFIGURATION.md` for every setting.
