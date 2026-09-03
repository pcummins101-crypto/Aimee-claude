# Aimee Engine configuration

All settings live under **Settings → Aimee Engine** (option `aimee_engine_settings`).
API keys are read from the same `wp-config.php` constants Aimee Global uses.

## Rollout

| Setting | Default | Meaning |
|---|---|---|
| Enable engine | on | Master switch. Off means every request goes to Aimee Global. |
| Who is enrolled | allowlist | `allowlist`: only listed IDs and per-user opt-ins. `all`: everyone except per-user opt-outs. |
| Allowlist user IDs | 121 | Comma-separated WordPress user IDs. |
| Serve the engine chat page | on | Enrolled users get the engine's own chat page. Off keeps the theme app and swaps only the reply engine. |
| Stream replies | on | Word-by-word replies over server-sent events. Needs PHP cURL; degrades automatically. |
| Per-user override | follow rollout | On each user's WordPress profile screen (administrators only): *Always use Aimee Engine*, *Always use Aimee Global*, or follow the rollout. Stored as user meta `aimee_engine_v2`. |

An administrator can force one request with the HTTP header `X-Aimee-Engine: legacy` or `X-Aimee-Engine: engine`.

The colleague persona (Georgia) always stays on Aimee Global.

## Models

| Setting | Default | Notes |
|---|---|---|
| Primary conversation model | `claude-opus-5` | Any Messages API model ID. |
| Primary effort | `low` | Sent only to models that support `output_config.effort`. |
| Classifier model | `claude-haiku-4-5` | Structured output; ~300 output tokens. |
| Observer model | `claude-haiku-4-5` | Runs after the turn. |
| Brief model | empty (primary) | Writes the specialist's private notes. |
| Explicit specialist models | empty (inherit) | Comma-separated OpenRouter IDs tried in order. Empty inherits `AIMEE_INTIMACY_MODEL` / `AIMEE_INTIMACY_FALLBACK_MODELS` from Aimee Global. |
| Brief the specialist | on | One extra fast Claude call before an explicit turn. |

## Conversation

| Setting | Default | Notes |
|---|---|---|
| History messages | 60 | Rows sent as the transcript. |
| History character cap | 60,000 | Oldest turns dropped beyond this. |
| Reply max tokens | 1024 | Hard ceiling per reply. |
| Photo cooldown (minutes) | 20 | No photo offered again within this window. 0 disables. |
| Observer mode | async | `async` (WP-Cron), `inline` (before the reply returns; use for testing), `off`. |
| Character card override | empty | Replaces the built-in card. Write facts, not rules. |
| Keep recent turn telemetry | off | Last 40 turns on the settings page. Never message text. |

## Keys (wp-config.php)

- `ANTHROPIC_API_KEY` required. Without it the engine stays dormant and Aimee Global answers.
- `OPENROUTER_API_KEY` optional. Without it explicit turns stay on the primary model and are steered rather than written.

## Telemetry

- `aimee_analytics_events`, `event_name = engine_v2_turn`: route, classifier outcome, models, attempts, refusal category, stage, photo key, and `timings` (gates, context, classify, relationship, generate, persist in ms).
- `aimee_analytics_events`, `event_name = engine_v2_observer`: what the observer wrote.
- `aimee_messages.evaluator_directive` on Aimee's rows: `engine_v2 route=… model=… classifier=…`.

## Known limits in 0.1.0

- Generated-on-demand photos (media sidecar `pending` status) are declined for this turn rather than delivered later.
- Replies are not streamed; the chat UI expects a single JSON response.
- Web search is not wired in. The facts block carries the day's headlines and weather from Aimee Global's live context.
