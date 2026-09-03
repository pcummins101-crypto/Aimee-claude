#!/usr/bin/env python3
"""Static 1.8.6 regressions for Camera Roll albums, delivery and discovery."""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
checks = 0
failures: list[str] = []


def read(relative: str) -> str:
    return (ROOT / relative).read_text(encoding="utf-8")


def require(condition: bool, label: str) -> None:
    global checks
    checks += 1
    if not condition:
        failures.append(label)


def source_slice(source: str, start_marker: str, end_marker: str) -> str:
    start = source.find(start_marker)
    end = source.find(end_marker, start + len(start_marker)) if start >= 0 else -1
    return source[start:end] if start >= 0 and end > start else ""


security = read("includes/security-privacy.php")
engine = read("includes/engine.php")
legacy = read("includes/legacy-ui.php")
fallback = read("templates/shared/chat-fallback.php")
partial = read("templates/shared/gallery-albums.php")
audit_runner = read("tests/run-audit-suite.py")
native_runner = read("tests/run-native-audit-suite.py")
fixture = json.loads(read("tests/fixtures/public-media-legacy-catalog-52.json"))

album_definitions = source_slice(
    security,
    "function aimee_security_gallery_album_definitions()",
    "function aimee_security_gallery_album_key(",
)
album_classifier = source_slice(
    security,
    "function aimee_security_gallery_album_key(",
    "function aimee_security_gallery_items(",
)
gallery_items = source_slice(
    security,
    "function aimee_security_gallery_items(",
    "function aimee_security_gallery_albums(",
)
gallery_albums_start = security.find("function aimee_security_gallery_albums(")
gallery_albums = security[gallery_albums_start:] if gallery_albums_start >= 0 else ""
gallery_access = source_slice(
    security,
    "function aimee_security_require_gallery_access(",
    "function aimee_security_gallery_album_definitions()",
)
viewable = source_slice(
    engine,
    "function aimee_media_item_is_viewable(",
    "function aimee_gallery_referenced_media_context(",
)
explicit_ready = source_slice(
    engine,
    "function aimee_gallery_explicit_item_is_ready(",
    "function aimee_gallery_item_adult_assurance_is_ready(",
)
reference_context = source_slice(
    engine,
    "function aimee_gallery_referenced_media_context(",
    "function aimee_gallery_referenced_media_prompt(",
)
reference_prompt = source_slice(
    engine,
    "function aimee_gallery_referenced_media_prompt(",
    "function aimee_gallery_discussion_lock_media_decision(",
)
discussion_lock = source_slice(
    engine,
    "function aimee_gallery_discussion_lock_media_decision(",
    "function aimee_media_item_is_eligible(",
)
memory_lock = source_slice(
    engine,
    "function aimee_gallery_discussion_lock_memory_contract(",
    "function aimee_media_item_is_eligible(",
)
media_catalog = source_slice(
    engine,
    "function handle_aimee_media_catalog(",
    "function aimee_rate_limit(",
)
serve_media = source_slice(
    engine,
    "function aimee_serve_private_media(",
    "function aimee_proactive_safe_photo_window_seconds(",
)
deny_media = source_slice(
    engine,
    "function aimee_deny_private_media(",
    "function aimee_serve_private_media(",
)
voice_status = source_slice(
    engine,
    "function handle_aimee_voice_note_status(",
    "function handle_aimee_voice_note_audio(",
)
history = source_slice(
    engine,
    "function handle_aimee_history(",
    "function aimee_neutralise_internal_instruction(",
)
timeline = source_slice(
    engine,
    "function handle_aimee_timeline(",
    "function handle_aimee_continuity_status(",
)
message_handler = source_slice(
    engine,
    "function handle_aimee_message(",
    "function aimee_sms_callback_number(",
)
gallery_discovery = source_slice(
    legacy,
    "function aimee_global_chat_gallery_discovery_markup(",
    "function aimee_global_chat_mobile_onboarding_scroll_markup(",
)


# The real operator fixture drives an independent taxonomy oracle so a future
# production edit cannot make both expected and actual counts drift together.
require(isinstance(fixture, dict) and len(fixture) == 52, "operator fixture has exactly 52 records")
require(len(set(fixture)) == 52, "operator fixture keys are unique")

album_order = [
    "family",
    "friends",
    "holidays_travel",
    "nights_celebrations",
    "days_out_adventures",
    "active_wellbeing",
    "style_getting_ready",
    "everyday_moments",
    "throwbacks",
    "just_between_us",
]
labels = [
    "Family",
    "Friends",
    "Holidays & Travel",
    "Nights Out & Celebrations",
    "Days Out & Adventures",
    "Active & Wellbeing",
    "Style & Getting Ready",
    "Everyday Moments",
    "Throwbacks",
    "Just Between Us",
]
positions = [album_definitions.find(f"'{key}' =>") for key in album_order]
require(all(position >= 0 for position in positions) and positions == sorted(positions), "production album order is exact")
require(all(label in album_definitions for label in labels), "production owns all ten approved album labels")
require("$item['gallery_album']" not in album_classifier and '$item["gallery_album"]' not in album_classifier, "manifest cannot inject an arbitrary album label or assignment")
require("['flirty', 'suggestive', 'erotic', 'explicit']" in album_classifier, "private ratings have first-priority album assignment")


def matches(haystack: str, alternatives: tuple[str, ...]) -> bool:
    return any(re.search(rf"(?<![a-z0-9]){re.escape(value)}(?![a-z0-9])", haystack) for value in alternatives)


def oracle_album(key: str, item: dict[str, object]) -> str:
    rating = str(item.get("content_rating", "safe")).lower()
    if rating in {"flirty", "suggestive", "erotic", "explicit"}:
        return "just_between_us"
    tokens = [key.replace("_", " ").replace("-", " ")]
    tokens.extend(str(tag).strip().lower() for tag in item.get("tags", []) if str(tag).strip())
    haystack = "|".join(tokens)
    rules = [
        ("family", ("family", "mum", "dad", "mother", "father", "parents")),
        ("friends", ("friend", "friends", "best friend", "best friends", "sarah")),
        ("throwbacks", ("throwback",)),
        ("holidays_travel", ("holiday", "travel", "road trip", "rome", "las vegas", "scarborough")),
        ("active_wellbeing", ("gym", "workout", "yoga", "pilates", "tennis", "lido", "swimming", "nature walk", "track day", "motorcycle", "motorsport")),
        ("nights_celebrations", ("night out", "cocktail bar", "wedding", "celebration", "date night")),
        ("style_getting_ready", ("getting ready", "mirror selfie", "outfit of the day", "ootd", "rate my outfit", "fashion")),
        ("days_out_adventures", ("bookshop", "farmers market", "ikea", "picnic", "fairground", "summer fair", "pub garden", "day out", "shopping trip")),
    ]
    for album, alternatives in rules:
        if matches(haystack, alternatives):
            return album
    return "everyday_moments"


oracle_assignments = {key: oracle_album(key, item) for key, item in fixture.items()}
oracle_counts = [sum(album == expected for album in oracle_assignments.values()) for expected in album_order]
require(oracle_counts == [3, 3, 5, 7, 8, 6, 4, 7, 1, 8], "fixture oracle yields approved 3/3/5/7/8/6/4/7/1/8 counts")
require(sum(oracle_counts) == 52, "fixture oracle assigns all 52 items exactly once")
require(oracle_assignments["beverley_races_with_mum_and_sarah_01"] == "family", "family precedence survives a family/friend/event collision")
require(oracle_assignments["yates_night_out_friend_01"] == "friends", "friend precedence survives a friend/night collision")
require(oracle_assignments["evening_get_ready_club_outfit_01"] == "nights_celebrations", "night precedence survives a night/style collision")
require(oracle_assignments["black_lingerie_mirror_selfie_01"] == "just_between_us", "private precedence survives a private/style collision")

require("is_user_logged_in" in gallery_access and "profile_required" in gallery_access, "gallery page requires a signed-in profile")
require("aimee_subscription_is_active" not in gallery_access, "gallery page has no blanket membership gate")
require(gallery_items.find("aimee_media_item_is_viewable") < gallery_items.find("aimee_private_media_payload"), "gallery revalidates entitlement before resolving a URL")
require("aimee_security_gallery_album_key" in gallery_items, "gallery assigns albums only after current item authorization")
require("array_filter" in gallery_albums and "!empty($album['items'])" in gallery_albums, "gallery omits empty albums without changing order")
require("$grouped[$album_key]['items'][] = $item" in gallery_albums, "one item is appended to one known album")
require("aimee_security_gallery_items" in media_catalog and "aimee_security_gallery_albums" in media_catalog, "media-catalog API uses the shared filtered/grouped helpers")
require("'albums' => $albums" in media_catalog and "'items'  => $items" in media_catalog, "media-catalog API exposes grouped and compatibility item views")
require("'/media-catalog'" in engine and "'permission_callback' => 'aimee_rest_require_login'" in engine, "media-catalog route remains authenticated")

for template_path in (
    "templates/gallery-uk.php",
    "templates/gallery-us.php",
    "templates/gallery-vip.php",
    "templates/shared/gallery.php",
):
    template = read(template_path)
    require("aimee_security_require_gallery_access" in template, f"{template_path} enforces gallery access")
    require("aimee_security_gallery_albums" in template, f"{template_path} uses server-grouped albums")
    require("templates/shared/gallery-albums.php" in template, f"{template_path} renders the shared album partial")

for template_path in (
    "templates/gallery-uk.php",
    "templates/gallery-us.php",
    "templates/shared/gallery.php",
):
    template = read(template_path)
    require("<h1>Aimee’s Camera Roll</h1>" in template, f"{template_path} gives the gallery an obvious page title")
    require("Tap any photo to ask her about it." in template, f"{template_path} explains the gallery interaction")
    require('aria-current="page">Camera Roll</a>' in template, f"{template_path} marks Camera Roll in both navigation surfaces")
    require(template.count("Back to chat") >= 2, f"{template_path} exposes a desktop and mobile route back to chat")
    require('aria-expanded="false"' in template and 'aria-controls="mobile-menu"' in template, f"{template_path} exposes accessible mobile navigation state")

require("data-aimee-ask-photo" in partial and "Ask Aimee about this" in partial, "shared album card provides the ask action")
require("#ask-aimee-about-photo" in partial and "data-media-key" in partial, "ask action uses a generic URL fragment and data-only key")
require("data-aimee-gallery-image" in partial and 'addEventListener("error"' in partial, "gallery cards observe image delivery failures")
require("is-unavailable" in partial and "This photo is temporarily unavailable" in partial, "gallery cards replace broken alt-text overflow with an accessible unavailable state")
require("image.complete&&image.naturalWidth===0" in partial, "gallery cards also catch failures cached before the footer script runs")
require("JSON.stringify({\n                key:key,\n                created_at:Date.now()" in partial, "gallery producer stores only canonical key and creation time")
for forbidden_field in ("alt:", "rating:", "description:", "url:", "album:"):
    require(forbidden_field not in source_slice(partial, '<script id="aimee-gallery-question-handoff">', "</script>"), f"gallery producer never stores client {forbidden_field[:-1]} metadata")

require("if ($rating === 'safe') return true" in viewable, "safe catalogue items are open to every signed-in profile")
for key in (
    "black_top_selfie_01",
    "black_top_selfie_02",
    "post_shower_towel_selfie_01",
    "black_lingerie_mirror_selfie_01",
):
    require(key in engine, f"reviewed flirty allowlist includes only approved key {key}")
require("aimee_subscription_is_active($profile)" in viewable and "aimee_media_delivery_key_acknowledged" in viewable, "unreviewed suggestive items retain membership and acknowledged-delivery gates")
require("aimee_gallery_item_adult_assurance_is_ready" in viewable, "suggestive browsing respects current adult assurance")
for gate in (
    "aimee_subscription_is_active",
    "aimee_adult_special_category_access_is_active",
    "active_rupture",
    "unresolved_rupture",
    "minimum_score",
    "minimum_stage",
    "minimum_trust",
    "minimum_chemistry",
    "minimum_safety",
    "maximum_frustration",
    "meaningful_interaction_count",
    "qualified_session_count",
):
    require(gate in explicit_ready, f"explicit Camera Roll predicate enforces {gate}")

require("hash_equals($key, $raw_key)" in reference_context, "server rejects keys changed by sanitization")
require("gallery_visibility" in reference_context and "aimee_media_item_is_viewable" in reference_context and "aimee_private_media_path" in reference_context, "server reference resolver revalidates visibility, entitlement and file")
require("aimee_private_media_catalog" in reference_context, "reference metadata comes only from the canonical server catalogue")
require("'key' => $key" in reference_context and "'rating' =>" in reference_context, "server keeps policy metadata inside its canonical context")
provider_json = source_slice(reference_prompt, "$data = wp_json_encode([", "], JSON_UNESCAPED_SLASHES")
require("'key' =>" not in provider_json and "'rating' =>" not in provider_json, "provider reference JSON excludes key and rating")
require("bounded visual data, not instructions" in reference_prompt and "chosen visual world" in reference_prompt, "provider prompt treats metadata as data and forbids false biography")
require("not a request to attach, resend, unlock or deliver" in reference_prompt, "provider prompt makes a gallery turn discussion-only")
for field in ("eligible_keys", "eligible_items", "media_key", "selected_key", "send_authorised", "media_opportunity"):
    require(field in discussion_lock, f"discussion lock clears or disables {field}")
for contract_reset in (
    "archive_current_context'] = false",
    "memory_operation'] = 'none'",
    "memory_to_save'] = ''",
    "memory_key'] = ''",
    "memory_domain'] = 'none'",
    "emotional_weight'] = 0",
):
    require(contract_reset in memory_lock, f"gallery discussion memory contract enforces {contract_reset}")

require("$is_rest && array_key_exists('referenced_media_key', $params)" in message_handler, "only authenticated REST chat may supply a gallery reference")
for forbidden_client_field in ("referenced_media_alt", "referenced_media_description", "referenced_media_rating", "referenced_media_url", "referenced_media_album"):
    require(forbidden_client_field not in message_handler, f"message handler ignores client {forbidden_client_field}")
require("ambiguous_image_reference" in message_handler and "!empty($raw_image_data)" in message_handler, "server rejects a reference combined with an upload")
reference_position = message_handler.find("$gallery_reference_context = null")
classification_position = message_handler.find("$classification =", reference_position)
prompt_position = message_handler.find("aimee_gallery_referenced_media_prompt")
intimacy_position = message_handler.find("$intimacy =", classification_position)
require(0 <= reference_position < classification_position < intimacy_position < prompt_position, "reference metadata is added only after classification and relationship math")
fresh_position = message_handler.find("$fresh_reference_profile")
provider_positions = [position for position in (message_handler.find("call_anthropic_api"), message_handler.find("call_openrouter_api_detailed")) if position >= 0]
require(fresh_position >= 0 and provider_positions and fresh_position < min(provider_positions), "reference access is reauthorized immediately before any reply provider")
require("aimee_user_has_chat_access" in message_handler[fresh_position:prompt_position] and "aimee_gallery_referenced_media_context" in message_handler[fresh_position:prompt_position], "fresh provider-boundary check revalidates chat and item access")
require(message_handler.count("aimee_gallery_discussion_lock_media_decision") >= 4, "all model routes receive the discussion-only media lock")
require("if ($gallery_discussion_only)" in message_handler and "$media_key = '';" in message_handler and "$photo_request_detected = false" in message_handler, "final delivery and fallback paths cannot turn a reference into new media")
require("aimee_gallery_discussion_lock_memory_contract" in message_handler, "final model contract is stripped of gallery-derived durable memory")
continuity_position = message_handler.find("aimee_turn_may_need_continuity")
require(
    continuity_position >= 0
    and "!$gallery_discussion_only" in message_handler[max(0, continuity_position - 180):continuity_position],
    "gallery-reference turns cannot schedule continuity extraction",
)

require("aimee_media_item_is_viewable" in serve_media, "direct serving uses the centralized current predicate")
require("get_current_user_id()" in serve_media and "$profile = $user_id" in serve_media, "direct serving reloads the current signed-in profile")
require("status_header(401)" in deny_media and "admin_post_nopriv_aimee_private_media" in deny_media, "logged-out controller requests fail with the dedicated authentication response")
require("Cross-Origin-Resource-Policy: same-origin" in serve_media, "direct serving prevents authenticated image embedding by another origin")
require("aimee_media_item_is_viewable" in history, "history uses the centralized current predicate")
require("aimee_media_item_is_viewable" in timeline, "timeline uses the centralized current predicate")
require("aimee_user_has_unlocked_media" in timeline, "timeline additionally requires a user-owned historical unlock")

require("SELECT * FROM " in voice_status and "aimee_user_profiles" in voice_status, "voice poll reloads the current full profile")
require("aimee_get_subscription_snapshot($user_id, $profile)" in voice_status, "voice poll returns a subscription snapshot from the fresh profile")
for binding in ("media_key", "message_id", "authorised_at", "file_resolved_at", "message_created_at", "failed_at"):
    require(binding in voice_status, f"voice poll validates delivery {binding}")
require("aimee_media_item_is_viewable" in voice_status, "voice poll uses the same current entitlement predicate")
lock_start = voice_status.find("if ($media && (!$delivery_owned || !$current_media_access))")
return_start = voice_status.find("if (\n        (string) ($job->status ?? '') === 'ready'", lock_start)
locked_block = voice_status[lock_start:return_start]
require(lock_start >= 0 and return_start > lock_start and "$media = null" in locked_block and "$media_locked = true" in locked_block, "voice downgrade returns locked/null media")
require("aimee_media_delivery_transition" not in locked_block, "voice entitlement downgrade never mutates or fails the historical delivery")
require("returned_by_direct_api" in voice_status[return_start:], "only a ready, owned, currently viewable voice delivery records a return")
require("'media_locked'        => $media_locked" in voice_status and "'media'               => $media" in voice_status, "voice status response exposes uniform locked/null semantics")

for browser_source, label in ((legacy, "legacy bridge"), (fallback, "bundled fallback")):
    require("10 * 60 * 1000" in browser_source or "10*60*1000" in browser_source, f"{label} enforces ten-minute TTL")
    require(
        "createdAt > now + 60000" in browser_source
        or "createdAt <= now + 60000" in browser_source
        or "createdAt<=now+60000" in browser_source,
        f"{label} rejects excessive future clock skew",
    )
    require("galleryDefaultQuestion" in browser_source, f"{label} uses the generic default question")
    require("referenced_media_key" in browser_source, f"{label} adds only the key to chat message payload")
    require("clearGalleryReference(false)" in browser_source, f"{label} consumes reference before dispatch")
    require("clearGalleryReference(true)" in browser_source, f"{label} supports cancellation and conflicting input cleanup")
require("voiceNoteEndpoint" in legacy and "voiceTurnEndpoint" in legacy, "legacy bridge clears references across voice transports")
require("#voice-btn" in fallback and "clearGalleryReference(true)" in fallback, "bundled fallback clears reference before voice recording")

require("if (!is_user_logged_in()) return '';" in gallery_discovery, "legacy gallery shortcut is emitted only for a signed-in user")
require("aimee_global_route('gallery', $market)" in gallery_discovery, "legacy gallery shortcut follows the current UK or US market")
require("min-width:44px" in gallery_discovery and "min-height:44px" in gallery_discovery, "legacy gallery shortcut keeps a 44px touch target")
require('link.id="aimee-chat-gallery-link"' in gallery_discovery and "<span>Photos</span>" in gallery_discovery, "legacy chat exposes a permanent labelled Photos shortcut")
require("Open Aimee’s photo gallery" in gallery_discovery, "legacy Photos shortcut has a descriptive accessible name")
require("MutationObserver" in gallery_discovery and "window.setTimeout(mount,delay)" in gallery_discovery, "legacy shortcut remounts across asynchronous historical chat shells")
require(".app-header" in gallery_discovery, "legacy shortcut recognizes the deployed app-header chat shell")
require(
    legacy.find("$injected .= aimee_global_chat_security_bridge_markup($market)")
    < legacy.find("$injected .= aimee_global_chat_gallery_discovery_markup($market)")
    < legacy.find("$injected .= aimee_global_chat_release_feedback_markup($market)"),
    "legacy response injection always includes the gallery shortcut beside the chat bridge",
)
require("side-gallery-link" in fallback and "📸 Aimee’s photos" in fallback, "fallback desktop sidebar advertises Aimee’s photos")
require('id="aimee-chat-gallery-link"' in fallback and "chat-gallery-shortcut" in fallback and "<span>Photos</span>" in fallback, "fallback chat header keeps Photos visible when the mobile sidebar is hidden")
require("min-width:44px" in fallback and "min-height:44px" in fallback, "fallback Photos shortcut keeps a 44px touch target")

for navigation_path in (
    "templates/landing-uk.php",
    "templates/landing-us.php",
    "templates/shared/landing.php",
    "templates/pricing-uk.php",
    "templates/pricing-us.php",
    "templates/shared/pricing.php",
    "templates/faq-uk.php",
    "templates/faq-us.php",
    "templates/shared/faq.php",
):
    navigation = read(navigation_path)
    require("$gallery_url" in navigation and "Aimee’s Photos" in navigation, f"{navigation_path} visibly links to Aimee’s Photos")

for test_name in (
    "gallery-album-handoff-1.8.5-regression.py",
    "gallery-album-handoff-ui-1.8.5-regression.mjs",
):
    require(test_name in audit_runner, f"canonical runner includes {test_name}")
    require(test_name in native_runner, f"native runner includes {test_name}")
require(
    audit_runner.count("gallery-album-entitlement-handoff-1.8.5-regression.php") >= 2,
    "canonical runner replays Camera Roll runtime cases under PHP 8.3 and PHP 7.4",
)
require(
    'TESTS.glob("*.php")' in native_runner,
    "native runner auto-discovers the Camera Roll PHP runtime regression",
)

if failures:
    print("Camera Roll static regression failures:")
    for failure in failures:
        print(f"- {failure}")
    sys.exit(1)

print(f"PASS: {checks} Camera Roll static integration checks.")
