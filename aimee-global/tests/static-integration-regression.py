#!/usr/bin/env python3
"""Static integration checks for audit-critical WordPress wiring.

The pure PHP suite proves the policy decisions themselves. These checks prove
the production handler, schema, persistence, history API and browser observer
actually call those policy/lifecycle helpers in fail-closed order. They are
deliberately specific enough to fail if a later refactor silently restores the
old implicit or random image path.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
ENGINE = (ROOT / "includes/engine.php").read_text(encoding="utf-8")
INNER = (ROOT / "includes/inner-life.php").read_text(encoding="utf-8")
DELIVERY = (ROOT / "includes/media-delivery.php").read_text(encoding="utf-8")
MATERIALIZATION = (ROOT / "includes/media-materialization.php").read_text(
    encoding="utf-8"
)
DECISION = (ROOT / "includes/media-decision.php").read_text(encoding="utf-8")
CADENCE = (ROOT / "includes/media-cadence.php").read_text(encoding="utf-8")
RELATIONSHIP = (ROOT / "includes/relationship-policy.php").read_text(encoding="utf-8")
SCHEMA = (ROOT / "includes/schema.php").read_text(encoding="utf-8")
UI = (ROOT / "includes/legacy-ui.php").read_text(encoding="utf-8")
ADMIN = (ROOT / "includes/admin.php").read_text(encoding="utf-8")
TEMPLATES = (ROOT / "includes/templates.php").read_text(encoding="utf-8")
PRICING_UK = (ROOT / "templates/pricing-uk.php").read_text(encoding="utf-8")
PRICING_US = (ROOT / "templates/pricing-us.php").read_text(encoding="utf-8")
PRICING_SHARED = (ROOT / "templates/shared/pricing.php").read_text(encoding="utf-8")
CHAT_FALLBACK = (ROOT / "templates/shared/chat-fallback.php").read_text(encoding="utf-8")
BOOTSTRAP = (ROOT / "aimee-global.php").read_text(encoding="utf-8")
SERVICE_GRACE = (ROOT / "includes/service-grace.php").read_text(encoding="utf-8")
BILLING_MIGRATION = (ROOT / "includes/billing-migration.php").read_text(encoding="utf-8")
PROFILE_ATTRIBUTION = (ROOT / "includes/profile-attribution.php").read_text(
    encoding="utf-8"
)
USER_IMAGE_EVENTS = (ROOT / "includes/user-image-events.php").read_text(
    encoding="utf-8"
)
GOCARDLESS = (ROOT / "includes/gocardless.php").read_text(encoding="utf-8")

PLUGIN_VERSION = "1.8.11"
SCHEMA_VERSION = "2026.08.20.3"
RELEASE_VERSION = "1.7.1"
RELEASE_EVENT = "aimee_171_feedback"

passes = 0
failures = 0


def check(condition: bool, label: str) -> None:
    global passes, failures
    if condition:
        passes += 1
        print(f"PASS {label}")
    else:
        failures += 1
        print(f"FAIL {label}")


def function_block(source: str, name: str) -> str:
    match = re.search(
        rf"(?m)^(?P<indent>[ \t]*)function\s+{re.escape(name)}\s*\(",
        source,
    )
    if not match:
        raise AssertionError(f"Function not found: {name}")
    indent = re.escape(match.group("indent"))
    next_match = re.search(
        rf"(?m)^{indent}function\s+[A-Za-z0-9_]+\s*\(",
        source[match.end() :],
    )
    end = len(source) if not next_match else match.end() + next_match.start()
    return source[match.start() : end]


def optional_function_block(source: str, name: str) -> str:
    """Return a function block without aborting the remaining static checks."""
    try:
        return function_block(source, name)
    except AssertionError:
        return ""


handler = function_block(ENGINE, "handle_aimee_message")
user_image_parse = function_block(USER_IMAGE_EVENTS, "aimee_user_image_event_parse_data_uri")
user_image_classify = function_block(USER_IMAGE_EVENTS, "aimee_user_image_event_classify")
user_image_schema = function_block(USER_IMAGE_EVENTS, "aimee_user_image_event_schema_ready")
user_image_prior = function_block(USER_IMAGE_EVENTS, "aimee_user_image_event_prior_evidence")
user_image_resolve = function_block(USER_IMAGE_EVENTS, "aimee_user_image_event_resolve")
user_image_prompt = function_block(USER_IMAGE_EVENTS, "aimee_user_image_event_prompt_instruction")
history = function_block(ENGINE, "handle_aimee_history")
asset = function_block(ENGINE, "aimee_serve_private_media")
materialization_dispatch = function_block(
    MATERIALIZATION, "aimee_materialize_authorised_media_delivery"
)
materialization_complete = function_block(
    MATERIALIZATION, "aimee_complete_pending_media_materialization"
)
materialization_fail = function_block(
    MATERIALIZATION, "aimee_fail_pending_media_materialization"
)
materialization_public_result = function_block(
    MATERIALIZATION, "aimee_media_materialization_public_result"
)
materialization_pending_contract = function_block(
    MATERIALIZATION, "aimee_media_materialization_neutral_pending_contract"
)
materialization_erasure = function_block(
    MATERIALIZATION, "aimee_global_cleanup_live_image_beta_user_data"
)
materialization_erasure_retry = function_block(
    MATERIALIZATION, "aimee_global_schedule_live_image_beta_cleanup_retry"
)
materialization_erasure_tombstone = function_block(
    MATERIALIZATION, "aimee_global_live_image_beta_tombstone_jobs"
)
materialization_erasure_incomplete = function_block(
    MATERIALIZATION, "aimee_global_live_image_beta_cleanup_incomplete"
)
materialization_erasure_notice = function_block(
    MATERIALIZATION, "aimee_global_live_image_beta_cleanup_admin_notice"
)
continuity_select = function_block(ENGINE, "aimee_continuity_select_media")
continuity_process = function_block(ENGINE, "aimee_process_due_continuity_items")
media_prompt = function_block(ENGINE, "aimee_media_prompt_directive")
media_prompt_decision = function_block(ENGINE, "aimee_media_decision_prompt_directive")
cancel_handler = function_block(ENGINE, "handle_aimee_subscription_cancel")
portal_handler = function_block(ENGINE, "handle_aimee_billing_portal")
delete_account_handler = function_block(ENGINE, "aimee_api_delete_account")
delivery_create = function_block(DELIVERY, "aimee_media_delivery_create")
delivery_transition = function_block(DELIVERY, "aimee_media_delivery_transition")
response_evidence = function_block(DELIVERY, "aimee_media_delivery_mark_user_response_from_text")
memory_label = function_block(DELIVERY, "aimee_media_delivery_memory_label")
relational_appraisal = function_block(
    INNER, "aimee_apply_relational_appraisal_to_intimacy"
)
durable_coercion_policy = function_block(
    RELATIONSHIP, "aimee_relationship_policy_durable_coercion_confirmed"
)
catalogue_normalizer = function_block(ENGINE, "aimee_normalize_private_media_item")
private_catalogue = function_block(ENGINE, "aimee_private_media_catalog")
catalogue_validator = function_block(
    DECISION, "aimee_media_decision_validate_catalogue_item"
)
media_bool = function_block(DECISION, "aimee_media_decision_bool")
media_decision_build = function_block(DECISION, "aimee_media_decision_build")
media_apply_choice = function_block(
    DECISION, "aimee_media_decision_apply_model_choice"
)
media_default_policy = function_block(
    DECISION, "aimee_media_decision_default_policy"
)
media_normalize_context = function_block(DECISION, "aimee_media_decision_normalize_input")
media_hard_vetoes = function_block(DECISION, "aimee_media_decision_hard_vetoes")
media_mutuality = function_block(
    DECISION, "aimee_media_decision_context_supports_rating"
)
turn_media_decision = function_block(ENGINE, "aimee_build_turn_media_decision")
persist_media_decision = function_block(ENGINE, "aimee_persist_turn_media_decision")
cadence_anchor = function_block(ENGINE, "aimee_media_cadence_anchor_timestamp")
relevance_considered_map = function_block(
    ENGINE, "aimee_media_relevance_considered_map"
)
relevance_considered_marker = function_block(
    ENGINE, "aimee_mark_media_relevance_considered"
)
relevance_claim = function_block(ENGINE, "aimee_claim_media_relevance_keys")
relevance_commit = function_block(
    ENGINE, "aimee_commit_media_relevance_claims"
)
relevance_claim_release = function_block(
    ENGINE, "aimee_release_media_relevance_claims"
)
cadence_claim = function_block(ENGINE, "aimee_claim_media_cadence_opportunity")
cadence_claim_release = function_block(ENGINE, "aimee_release_media_cadence_claim")
cadence_provenance = function_block(
    ENGINE, "aimee_media_decision_is_discretionary_opportunity"
)
cadence_return_marker = function_block(
    ENGINE, "aimee_mark_media_cadence_returned_for_delivery"
)
cadence_due = function_block(CADENCE, "aimee_media_cadence_due_from_timestamps")
cadence_suitability = function_block(CADENCE, "aimee_media_cadence_turn_is_suitable")
cadence_planner = function_block(CADENCE, "aimee_media_opportunity_plan")
relationship_signals = function_block(ENGINE, "aimee_relationship_signals")
calculate_intimacy = function_block(ENGINE, "aimee_calculate_intimacy_state")
seed_relationship = function_block(ENGINE, "aimee_seed_relationship_state")
load_relationship = function_block(ENGINE, "aimee_load_relationship_state")
courtship_prompt = function_block(ENGINE, "aimee_courtship_response_directive")
standard_prompt = function_block(ENGINE, "aimee_build_standard_prompt")
intimacy_prompt = function_block(ENGINE, "aimee_build_intimacy_prompt")
inner_appraisal = function_block(INNER, "aimee_appraise_user_turn")
analytics_handler = function_block(ENGINE, "handle_aimee_analytics_event")
release_feedback_markup = optional_function_block(
    UI, "aimee_global_chat_release_feedback_markup"
)
billing_notice_active_js = (
    release_feedback_markup.split("function billingNoticeActive()", 1)[1].split(
        "\n    function ", 1
    )[0]
    if "function billingNoticeActive()" in release_feedback_markup
    else ""
)
press_release_markup = function_block(UI, "aimee_global_chat_press_release_markup")
billing_migration_markup = function_block(
    UI, "aimee_global_chat_billing_migration_markup"
)
release_feedback_summary = optional_function_block(
    ENGINE + "\n" + ADMIN, "aimee_global_release_feedback_summary"
)
admin_page = function_block(ADMIN, "aimee_global_admin_page")
registration_diagnostic = function_block(
    ENGINE, "aimee_registration_record_failure"
)
registration_failure_response = function_block(
    ENGINE, "aimee_registration_failure_response"
)
maybe_upgrade = function_block(BOOTSTRAP, "aimee_global_maybe_upgrade")
activate = function_block(BOOTSTRAP, "aimee_global_activate")
legacy_route_guard = function_block(
    BOOTSTRAP, "aimee_global_legacy_engine_blocks_rest_route"
)
legacy_worker_guard = function_block(
    BOOTSTRAP, "aimee_global_suppress_legacy_engine_workers"
)
legacy_sms_guard = function_block(
    BOOTSTRAP, "aimee_global_block_legacy_firetext_requests"
)
legacy_private_media_guard = function_block(
    BOOTSTRAP, "aimee_global_fail_legacy_private_media_closed"
)
legacy_gallery_guard = function_block(
    BOOTSTRAP, "aimee_global_fail_legacy_camera_roll_closed"
)
subscription_active = function_block(ENGINE, "aimee_subscription_is_active")
subscription_snapshot = function_block(ENGINE, "aimee_get_subscription_snapshot")
subscription_checkout = function_block(ENGINE, "handle_aimee_subscription_checkout")
checkout_market_supported = function_block(
    ENGINE, "aimee_new_membership_checkout_market_supported"
)
retire_stripe_before_bank = function_block(
    ENGINE, "aimee_membership_retire_stripe_before_bank_checkout"
)
subscription_status_handler = function_block(ENGINE, "handle_aimee_subscription_status")
profile_save = function_block(ENGINE, "handle_aimee_profile_save")
registration_post_commit = function_block(
    ENGINE, "aimee_registration_run_post_commit"
)
settings_update = function_block(ENGINE, "handle_aimee_settings_update")
admin_role = function_block(ENGINE, "aimee_admin_role")
admin_user = function_block(ENGINE, "aimee_is_admin_user")
owner_user = function_block(ENGINE, "aimee_is_owner_user")
colleague_prompt = function_block(ENGINE, "aimee_build_colleague_prompt")
colleague_brief = function_block(
    ENGINE, "aimee_colleague_written_creative_brief"
)
colleague_brief_directive = function_block(
    ENGINE, "aimee_colleague_creative_brief_directive"
)
colleague_reply_repair = function_block(
    ENGINE, "aimee_colleague_reply_needs_creative_repair"
)
colleague_state_repair = function_block(
    ENGINE, "aimee_repair_georgia_colleague_state_173"
)
profile_opening_replacement = optional_function_block(
    ENGINE, "aimee_profile_attribution_repaired_opening_175"
)
profile_opening_repair = optional_function_block(
    ENGINE, "aimee_repair_profile_attribution_opening_175"
)
reserved_phone = function_block(
    ENGINE, "aimee_phone_is_reserved_for_other_identity"
)
sms_refresh = function_block(ENGINE, "aimee_sms_refresh_allowance")
sms_send = function_block(ENGINE, "aimee_send_metered_sms")
sms_system_send = function_block(ENGINE, "aimee_send_system_sms")
sms_provider_send = function_block(ENGINE, "sendFireTextSmsDetailed")
sms_send_key = function_block(ENGINE, "aimee_sms_outbound_send_key")
sms_reference = function_block(ENGINE, "aimee_sms_outbound_reference")
sms_audit_begin = function_block(ENGINE, "aimee_sms_outbound_audit_begin")
sms_audit_reserve = function_block(ENGINE, "aimee_sms_outbound_audit_reserve")
sms_audit_claim = function_block(ENGINE, "aimee_sms_outbound_audit_claim_send")
sms_audit_complete = function_block(ENGINE, "aimee_sms_outbound_audit_complete")
sms_usage_record = function_block(ENGINE, "aimee_sms_record_usage")
sms_event_identity = function_block(ENGINE, "aimee_sms_inbound_event_identity")
sms_event_reserve = function_block(ENGINE, "aimee_sms_inbound_event_reserve")
sms_event_claim = function_block(ENGINE, "aimee_sms_inbound_event_claim_send")
core_schema_health = function_block(SCHEMA, "aimee_global_core_schema_health")
sms_profile_verified = function_block(
    SERVICE_GRACE, "aimee_global_sms_profile_is_verified"
)
sms_timezone_valid = function_block(
    SERVICE_GRACE, "aimee_global_sms_timezone_is_valid"
)
sms_bundle_checkout = function_block(ENGINE, "handle_aimee_sms_bundle_checkout")
chat_access = function_block(ENGINE, "aimee_user_has_chat_access")
free_preview = function_block(ENGINE, "aimee_free_preview_is_active")
service_policy = function_block(SERVICE_GRACE, "aimee_global_service_grace_policy")
service_profile_fields = function_block(
    SERVICE_GRACE, "aimee_global_service_grace_profile_fields"
)
service_enrollment_fields = function_block(
    SERVICE_GRACE, "aimee_global_service_grace_enrollment_fields"
)
service_enrolled = function_block(
    SERVICE_GRACE, "aimee_global_service_grace_profile_is_enrolled"
)
service_active = function_block(
    SERVICE_GRACE, "aimee_global_service_grace_is_active"
)
service_requires_new = function_block(
    SERVICE_GRACE, "aimee_global_service_grace_requires_new_subscription"
)
managed_subscription = function_block(
    SERVICE_GRACE, "aimee_global_managed_subscription_is_active"
)
membership_access = function_block(
    SERVICE_GRACE, "aimee_global_membership_access_is_active"
)
first_payment = function_block(
    SERVICE_GRACE, "aimee_global_service_grace_first_payment_timestamp"
)
goodwill_checkout_block = function_block(
    SERVICE_GRACE, "aimee_global_goodwill_checkout_block_until"
)
terminal_subscription_status = function_block(
    SERVICE_GRACE, "aimee_global_subscription_status_is_terminal"
)
subscription_identity_conflict = function_block(
    SERVICE_GRACE, "aimee_global_subscription_identity_conflicts"
)
grant_service_grace = function_block(
    SERVICE_GRACE, "aimee_global_grant_august_2026_service_grace"
)
gocardless_checkout = function_block(GOCARDLESS, "aimee_gocardless_checkout")
gocardless_checkout_intent = function_block(
    GOCARDLESS, "aimee_gocardless_build_checkout_intent_payload"
)
gocardless_retire_deletion = function_block(
    GOCARDLESS, "aimee_gocardless_retire_user_billing_for_deletion"
)
gocardless_create_payment = function_block(GOCARDLESS, "aimee_gocardless_create_payment_for_user")
gocardless_apply_payment = function_block(GOCARDLESS, "aimee_gocardless_apply_payment")
gocardless_apply_mandate = function_block(GOCARDLESS, "aimee_gocardless_apply_mandate_state")
gocardless_webhook = function_block(GOCARDLESS, "aimee_gocardless_webhook")
gocardless_portal = function_block(GOCARDLESS, "aimee_gocardless_portal")
gocardless_generation = function_block(GOCARDLESS, "aimee_gocardless_generation")
billing_generation = function_block(
    SERVICE_GRACE, "aimee_global_current_billing_generation"
)
profile_generation = function_block(
    SERVICE_GRACE, "aimee_global_profile_has_current_billing_generation"
)
period_bounds = function_block(
    SERVICE_GRACE, "aimee_global_stripe_subscription_period_bounds"
)
invoice_subscription = function_block(
    SERVICE_GRACE, "aimee_global_stripe_invoice_subscription_id"
)
stripe_request = function_block(ENGINE, "aimee_stripe_request")
stripe_identity = function_block(ENGINE, "aimee_validate_replacement_stripe_account")
stripe_cancel_verified = function_block(ENGINE, "aimee_cancel_stripe_subscription_verified")
stripe_sync = function_block(ENGINE, "aimee_sync_subscription_from_stripe")
stripe_webhook = function_block(ENGINE, "handle_aimee_stripe_webhook")
background_worker = function_block(ENGINE, "run_aimee_background_logic")
inbound_sms = function_block(ENGINE, "handle_aimee_inbound_sms_webhook")
billing_can_manage = function_block(BILLING_MIGRATION, "aimee_global_billing_can_manage")


# Versioning and durable schema.
check(
    f"Version: {PLUGIN_VERSION}" in BOOTSTRAP,
    f"patched plugin version is {PLUGIN_VERSION}",
)
check(
    f"define('AIMEE_GLOBAL_VERSION', '{PLUGIN_VERSION}')" in BOOTSTRAP,
    f"runtime plugin constant is {PLUGIN_VERSION}",
)
check(
    f"define('AIMEE_GLOBAL_SCHEMA_VERSION', '{SCHEMA_VERSION}')" in BOOTSTRAP,
    f"schema migration version is {SCHEMA_VERSION}",
)
check(
    "includes/profile-attribution.php" in BOOTSTRAP,
    "profile source-attribution policy is loaded by the plugin bootstrap",
)
check(
    "includes/media-materialization.php" in BOOTSTRAP,
    "provider-neutral media materialization bridge is loaded by the plugin bootstrap",
)
check(
    "includes/user-image-events.php" in BOOTSTRAP,
    "one-time user-image event policy is loaded by the plugin bootstrap",
)
check(
    all(column in SCHEMA for column in (
        "user_image_fingerprint CHAR(64)",
        "user_image_event VARCHAR(32)",
        "user_image_event_id VARCHAR(96)",
        "idx_aimee_messages_user_image",
    )),
    "messages schema persists fingerprinted one-time image-event evidence",
)
check(
    all(column in function_block(SCHEMA, "aimee_global_core_schema_health") for column in (
        "user_image_fingerprint",
        "user_image_event",
        "user_image_event_id",
    )),
    "core schema health fails closed when image-event persistence is unavailable",
)
check(
    "hash('sha256', $decoded)" in user_image_parse
    and "base64_decode($base64_data, true)" in user_image_parse
    and "image/jpeg" in user_image_parse
    and "image/png" in user_image_parse
    and "image/webp" in user_image_parse,
    "user image identity is derived from validated decoded bytes",
)
check(
    "stale_duplicate" in user_image_classify
    and "fresh_repeat" in user_image_classify
    and "duplicate_reference" in user_image_classify
    and "explicit_repeat" in user_image_classify
    and "use_vision" in user_image_classify,
    "image classifier distinguishes fresh selection, intentional reference and stale transport replay",
)
check(
    "SHOW COLUMNS FROM" in user_image_schema
    and "user_image_fingerprint" in user_image_schema
    and "user_image_event_id" in user_image_schema,
    "image-event runtime verifies the migration before accepting image traffic",
)
check(
    "sender = 'user'" in user_image_prior
    and "user_image_fingerprint = %s" in user_image_prior
    and "ORDER BY" in user_image_prior,
    "prior image evidence is scoped to the same user and fingerprint",
)
check(
    "aimee_user_image_event_schema_ready()" in user_image_resolve
    and "schema_unavailable" in user_image_resolve
    and "aimee_user_image_event_prior_evidence" in user_image_resolve,
    "request resolver fails closed rather than forgetting duplicate-image history",
)
check(
    "shared earlier" in user_image_prompt
    and "Do not say or imply" in user_image_prompt
    and "just uploaded" in user_image_prompt
    and "intentional repeat" in user_image_prompt,
    "model tense is bound to server-owned image-event truth",
)
check(
    "$params['image_event_id']" in handler
    and "aimee_user_image_event_resolve(" in handler
    and "$image_data = !empty($user_image_event['use_vision'])" in handler,
    "message handler resolves client image payload before exposing it to vision",
)
check(
    handler.index("aimee_user_image_event_resolve(")
    < handler.index("aimee_turn_request_reserve("),
    "image replay classification occurs before a conversational turn is reserved",
)
check(
    "duplicate_image_ignored" in handler
    and "stale_duplicate" in handler
    and "invalid_image" in handler,
    "stale or invalid image-only requests return without manufacturing a new Aimee turn",
)
check(
    "$stale_reservation = aimee_turn_request_reserve(" in handler
    and "aimee_turn_request_finish(" in handler
    and handler.index("$stale_reservation = aimee_turn_request_reserve(")
    < handler.index("duplicate_image_ignored"),
    "image-only stale replays preserve request idempotency without creating chat messages",
)
check(
    all(field in handler for field in (
        "'user_image_fingerprint'",
        "'user_image_event'",
        "'user_image_event_id'",
        "aimee_user_image_event_message_marker",
    )),
    "user message persistence records the classified image event atomically with the turn",
)
check(
    "aimee_user_image_event_prompt_instruction" in handler
    and "$user_image_event['mime_type']" in handler
    and "$user_image_event['base64_data']" in handler
    and "[The user attached this image now" not in ENGINE,
    "vision prompt consumes normalized event data and the legacy always-new wording is absent",
)
check(
    "imageEventId=newImageEventId()" in CHAT_FALLBACK
    and "outboundImageEventId=imageEventId" in CHAT_FALLBACK
    and "image_event_id:outboundImage?outboundImageEventId:''" in CHAT_FALLBACK
    and "if(sending)return" in CHAT_FALLBACK
    and "function clearImageSelection()" in CHAT_FALLBACK
    and CHAT_FALLBACK.index("clearImageSelection();typing()")
    < CHAT_FALLBACK.index("await api('/message'")
    and "imageEventId=''" in CHAT_FALLBACK,
    "bundled chat client snapshots one file-selection event, clears it before transport and blocks concurrent resend",
)
check(
    "aimee_global_profile_attribution_opening_repair_175"
    in profile_opening_repair
    and "112" in profile_opening_repair
    and "2026-08-17 12:27:09" in profile_opening_repair
    and "trial_messages_used" in profile_opening_repair
    and "intimacy_score" in profile_opening_repair
    and "intimacy_stage" in profile_opening_repair
    and "subscription_status" in profile_opening_repair
    and "sender = 'user'" in profile_opening_repair,
    "one-time opening repair requires the exact user-112 profile and no-user-turn evidence",
)
check(
    "onboarding_icebreaker_written_context" in profile_opening_repair
    and "sender = 'aimee'" in profile_opening_repair
    and "message_text" in profile_opening_repair
    and "evaluator_directive" in profile_opening_repair,
    "opening repair requires the exact Aimee-authored onboarding row",
)
check(
    "START TRANSACTION" in profile_opening_repair
    and "$wpdb->update(" in profile_opening_repair
    and "$messages_table" in profile_opening_repair
    and "'message_text' => $replacement" in profile_opening_repair
    and "'evaluator_directive' => $new_directive" in profile_opening_repair
    and "$message_pk" in profile_opening_repair
    and "'user_id' => $user_id" in profile_opening_repair
    and "'sender' => 'aimee'" in profile_opening_repair
    and "'message_text' => $opening_text" in profile_opening_repair
    and "if ($updated !== 1)" in profile_opening_repair
    and "COMMIT" in profile_opening_repair
    and "ROLLBACK" in profile_opening_repair,
    "opening repair compare-and-swaps the same message row transactionally",
)
check(
    "profile_attribution_repair" in profile_opening_replacement
    or (
        "profile_attribution_repair" in profile_opening_repair
        and "aimee_profile_attribution_repaired_opening_175"
        in profile_opening_repair
    ),
    "stored replacement is explicitly marked as a profile-attribution repair",
)
check(
    "original_hash" in profile_opening_repair
    and "replacement_hash" in profile_opening_repair
    and "completed_at" in profile_opening_repair
    and "contaminated_opening_repaired" in profile_opening_repair,
    "opening repair records hashes and an inspectable completion outcome",
)
check(
    "call_anthropic_api" not in profile_opening_repair
    and "call_openrouter" not in profile_opening_repair
    and "$wpdb->insert" not in profile_opening_repair
    and "$wpdb->delete" not in profile_opening_repair
    and "DELETE FROM" not in profile_opening_repair,
    "stored-opening repair uses no model call and creates or deletes no message",
)
check(
    re.search(
        r"add_action\(\s*'init'\s*,\s*'aimee_repair_profile_attribution_opening_175'",
        ENGINE,
    )
    is not None,
    "retryable stored-opening repair is enrolled on init independently of the public version option",
)
check(
    "AIMEE_GLOBAL_VERSION" in admin_page
    and re.search(r"Plugin\s+(?:build|version)", admin_page, re.IGNORECASE)
    is not None,
    "Aimee Global admin identifies the installed plugin build",
)
check(
    "'occurred_at'" in registration_diagnostic
    and "'reference'" in registration_diagnostic
    and "'stage'" in registration_diagnostic
    and "'error_code'" in registration_diagnostic
    and "update_option(aimee_registration_diagnostic_option_name(), $record, false)"
    in registration_diagnostic,
    "registration stores one non-autoloaded four-field operational diagnostic",
)
check(
    all(
        forbidden not in registration_diagnostic
        for forbidden in (
            "$login_id",
            "$passcode",
            "$params",
            "$wpdb->last_error",
            "$wpdb->last_query",
            "get_error_message()",
        )
    )
    and "'stage'" not in registration_failure_response.split("return new WP_REST_Response", 1)[-1]
    and "'error_code'" not in registration_failure_response.split("return new WP_REST_Response", 1)[-1],
    "public registration diagnostics expose only an opaque reference, never operational internals or request data",
)
check(
    "Last signup diagnostic" in admin_page
    and "occurred_at" in admin_page
    and "reference" in admin_page
    and "stage" in admin_page
    and "error_code" in admin_page
    and admin_page.count("esc_html") >= 8
    and "register_setting('aimee_global', 'aimee_global_last_registration_failure'" not in ADMIN,
    "manage-options admin status escapes the read-only registration diagnostic",
)
version_upgrade_match = re.search(
    r"if\s*\(\s*\$needs_version_upgrade\s*\)\s*\{(?P<body>.*?)\n\s*\}",
    maybe_upgrade,
    flags=re.DOTALL,
)
version_upgrade_body = (
    version_upgrade_match.group("body") if version_upgrade_match else ""
)
check(
    "version_compare($installed, AIMEE_GLOBAL_VERSION, '<')" in maybe_upgrade
    and "delete_transient('aimee_global_legacy_chat_uk')" in version_upgrade_body
    and "delete_transient('aimee_global_legacy_chat_us')" in version_upgrade_body,
    "an actual plugin version upgrade invalidates both cached legacy chat templates",
)
check(
    "update_option('aimee_global_version', AIMEE_GLOBAL_VERSION)" not in TEMPLATES
    and "add_action('admin_init', function ()" not in TEMPLATES,
    "only the schema-gated bootstrap can advance the installed plugin version",
)
check(
    "strpos($route, '/aimee/v1/') === 0" in legacy_route_guard
    and "remove_all_actions" in legacy_worker_guard
    and "aimee_autonomous_pulse" in legacy_worker_guard
    and "aimee_continuity_pulse" in legacy_worker_guard
    and "aimee_push_dispatch_hook" in legacy_worker_guard
    and "firetext.co.uk/api/sendsms" in legacy_sms_guard
    and "aimee_legacy_engine_sms_blocked" in legacy_sms_guard
    and "admin_post_aimee_private_media" in BOOTSTRAP
    and "admin_post_nopriv_aimee_private_media" in BOOTSTRAP
    and "wp_die" in legacy_private_media_guard
    and "camera-roll" in legacy_gallery_guard
    and "template_redirect" in BOOTSTRAP,
    "legacy theme engine fails REST, workers, carrier SMS and private media closed",
)
for table in (
    "aimee_relationship_state",
    "aimee_relationship_dimensions",
    "aimee_relationship_decisions",
    "aimee_relationship_invitations",
    "aimee_turn_requests",
    "aimee_media_decisions",
    "aimee_media_deliveries",
    "aimee_media_delivery_events",
):
    check(f"CREATE TABLE {table}" in SCHEMA, f"schema creates {table}")


# Auxiliary engine schema must be MariaDB-safe and versioned. SENSITIVE is a
# reserved MariaDB keyword, so the persisted field uses is_sensitive and any
# legacy field is migrated under backticks. Failed upgrades back off instead
# of repeating dbDelta work on every request.
check(
    re.search(r"(?m)^\\s*sensitive\\s+TINYINT", ENGINE) is None
    and "is_sensitive TINYINT(1) NOT NULL DEFAULT 0" in ENGINE
    and "'is_sensitive'     => $sensitive ? 1 : 0" in ENGINE
    and "->is_sensitive" in ENGINE,
    "push notification privacy flag avoids MariaDB reserved identifier SENSITIVE",
)
check(
    "CHANGE COLUMN `sensitive` `is_sensitive`" in ENGINE
    and "DROP COLUMN `sensitive`" in ENGINE
    and "GREATEST(COALESCE(`is_sensitive`, 0), COALESCE(`sensitive`, 0))" in ENGINE
    and "WHERE COALESCE(`sensitive`, 0) > COALESCE(`is_sensitive`, 0)" in ENGINE
    and ENGINE.find("WHERE COALESCE(`sensitive`, 0) > COALESCE(`is_sensitive`, 0)")
        < ENGINE.find("DROP COLUMN `sensitive`"),
    "push notification schema migrates any legacy reserved-name column without losing privacy flags",
)
check(
    "function aimee_engine_runtime_schema_due()" in ENGINE
    and "aimee_engine_runtime_schema_retry_after" in ENGINE
    and "15 * MINUTE_IN_SECONDS" in ENGINE
    and "function aimee_engine_runtime_schema_is_healthy" in ENGINE
    and "aimee_engine_runtime_schema_mark_current();" in ENGINE,
    "auxiliary schema maintenance is versioned, health-checked and backs off after failure",
)

# August 2026 service recovery is a temporary access grant, never a synthetic
# paid subscription or relationship signal.
check(
    "includes/service-grace.php" in BOOTSTRAP
    and BOOTSTRAP.index("includes/service-grace.php")
    < BOOTSTRAP.index("includes/legacy-ui.php"),
    "service-grace policy loads before billing-aware chat UI",
)
check(
    "Europe/London" in service_policy
    and "2026-09-01 00:00:00" in service_policy
    and "getTimestamp()" in service_policy,
    "service-grace cutoff is derived as midnight London on 1 September 2026",
)
check(
    all(
        column in SCHEMA
        for column in (
            "service_grace_code VARCHAR(64)",
            "service_grace_granted_at DATETIME",
            "service_grace_access_until DATETIME",
            "idx_aimee_service_grace",
            "billing_account_generation VARCHAR(64)",
            "billing_checkout_intent_token VARCHAR(64)",
            "billing_checkout_intent_provider VARCHAR(24)",
            "billing_checkout_intent_plan VARCHAR(32)",
            "billing_checkout_intent_market VARCHAR(8)",
            "billing_checkout_intent_generation VARCHAR(64)",
            "billing_checkout_intent_status VARCHAR(32)",
            "billing_checkout_intent_payload LONGTEXT",
            "billing_checkout_lock_until DATETIME",
            "billing_checkout_lock_token VARCHAR(64)",
            "account_deletion_started_at DATETIME",
            "idx_aimee_billing_generation",
        )
    ),
    "profile schema persists an inspectable service-grace cohort and cutoff",
)
check(
    "aimee_global_service_grace_enrollment_fields" in service_profile_fields
    and "service_grace_code" in service_enrollment_fields
    and "service_grace_granted_at" in service_enrollment_fields
    and "service_grace_access_until" in service_enrollment_fields
    and all(
        forbidden not in (service_profile_fields + service_enrollment_fields)
        for forbidden in (
            "subscription_status",
            "subscription_plan",
            "stripe_customer_id",
            "stripe_subscription_id",
            "intimacy_score",
            "intimacy_stage",
        )
    ),
    "new-profile grace fields contain neither billing nor relationship state",
)
check(
    "aimee_global_service_grace_profile_fields" in profile_save,
    "profiles created before the cutoff join the same service-grace cohort",
)
check(
    "service_grace_code" in grant_service_grace
    and "service_grace_granted_at" in grant_service_grace
    and "service_grace_access_until" in grant_service_grace
    and all(
        assignment not in grant_service_grace
        for assignment in (
            "SET subscription_status",
            "subscription_plan =",
            "stripe_customer_id =",
            "stripe_subscription_id =",
            "intimacy_score =",
            "intimacy_stage =",
        )
    ),
    "bulk grant changes only service-grace fields",
)
check(
    "aimee_global_grant_august_2026_service_grace" in BOOTSTRAP
    and "aimee_global_grant_august_2026_service_grace" in maybe_upgrade,
    "activation and in-place upgrades enrol current profiles idempotently",
)
check(
    "aimee_global_membership_access_is_active" in subscription_active,
    "legacy membership checks delegate to the separated access entitlement",
)
check(
    "billing_migration_status" in managed_subscription
    and "stripe_subscription_id" in managed_subscription
    and "legacy_stripe_subscription_id" not in managed_subscription
    and "['active', 'trialing']" in managed_subscription
    and "aimee_global_profile_has_current_billing_generation" in managed_subscription
    and "if (!$end_ts) return false" in managed_subscription,
    "managed billing requires replacement-account provenance, active status and a verified period end",
)
check(
    "return false" in membership_access
    and "aimee_global_managed_subscription_is_active" in membership_access,
    "unproven local status and closed-account IDs cannot extend membership access",
)
check(
    "stripe_2026_09_v1" in billing_generation
    and "gocardless_2026_08_v1" in gocardless_generation
    and "hash_equals" in profile_generation
    and "billing_account_generation" in profile_generation
    and "provider === 'stripe'" in profile_generation
    and "aimee_global_current_billing_generation" in profile_generation
    and "provider === 'gocardless'" in profile_generation
    and "aimee_gocardless_generation" in profile_generation
    and "aimee_global_profile_has_current_billing_generation" in billing_can_manage,
    "billing generation is explicit and every manage path checks it",
)
check(
    "FOR UPDATE" in gocardless_apply_payment
    and "START TRANSACTION" in gocardless_apply_payment
    and "ROLLBACK" in gocardless_apply_payment
    and "gocardless_last_confirmed_payment_id" in gocardless_apply_payment,
    "GoCardless payment confirmation is concurrency-safe and idempotent per payment",
)
check(
    "'start_date' => $start_date" in gocardless_checkout_intent
    and "gmdate('Y-m-d')" in gocardless_checkout_intent
    and "'subscription_cancel_at_period_end'    => 0" in gocardless_checkout
    and "'gocardless_mandate_id'                => null" in gocardless_checkout
    and "'gocardless_payment_id'                => null" in gocardless_checkout
    and "'gocardless_cancelled_at'              => null" in gocardless_checkout
    and "'billing_checkout_lock_token' => $checkout_lock_token" in gocardless_checkout
    and "'account_deletion_started_at' => null" in gocardless_checkout,
    "a new GoCardless checkout resets a terminal predecessor and cannot inherit its cancelled/payment state",
)
check(
    "psu_interaction_type'] = 'off_session'" in gocardless_create_payment
    and "charge_date'] = gmdate('Y-m-d')" in gocardless_create_payment
    and "else {" in gocardless_create_payment
    and "retry_if_possible'] = true" in gocardless_create_payment,
    "VRP payments use off-session Faster Payments while Success+ retry remains limited to Direct Debit fallback",
)
check(
    "return new WP_REST_Response(['status'=>'retry'" in gocardless_webhook
    and "503" in gocardless_webhook
    and gocardless_webhook.find("aimee_gocardless_record_event($event)") > gocardless_webhook.find("if (!$processed_ok)"),
    "GoCardless webhooks are recorded only after required remote/local processing succeeds",
)
check(
    all(status in gocardless_apply_mandate for status in ["cancelled", "failed", "expired", "blocked"])
    and "gocardless_next_payment_at" in gocardless_apply_mandate
    and "subscription_cancel_at_period_end" in gocardless_apply_mandate,
    "terminal GoCardless mandate states stop future collections without erasing paid access",
)
check(
    "portal_url" in gocardless_portal
    and "membership'=>'manage'" in gocardless_portal
    and "Opening membership settings" in PRICING_UK
    and "Opening Stripe" not in PRICING_UK,
    "GoCardless membership management returns a usable in-app target and removes the broken Stripe portal UX",
)
check(
    "Manage current membership" in PRICING_UK
    and "Switch to ${planLabels" not in PRICING_UK,
    "pricing does not promise an unsupported in-place GoCardless plan switch",
)
check(
    "aimee_billing_generation" in stripe_sync
    and "hash_equals" in stripe_sync
    and "billing_account_generation" in stripe_sync
    and "aimee_global_stripe_subscription_period_bounds" in stripe_sync
    and "items" in period_bounds,
    "subscription sync rejects stale provenance and supports current Stripe item periods",
)
check(
    "AIMEE_STRIPE_ACCOUNT_ID" in stripe_identity
    and "GET" in stripe_identity
    and "account" in stripe_identity
    and "hash_equals" in stripe_identity,
    "legacy Stripe runoff binds its secret to the approved replacement account",
)
check(
    "aimee_global_managed_subscription_is_active" in service_requires_new
    and "aimee_global_service_grace_end_timestamp" in service_requires_new,
    "replacement-subscription requirement begins at the deterministic cutoff",
)
check(
    "membership_bonus_access_until" in first_payment
    and "aimee_global_service_grace_end_timestamp" in first_payment,
    "first-payment calculation preserves the grace boundary and later goodwill",
)
check(
    "48 * 60 * 60" in goodwill_checkout_block
    and "membership_bonus_access_until" in goodwill_checkout_block
    and "aimee_global_service_grace_first_payment_timestamp" in gocardless_checkout
    and "existing_access_active" in gocardless_checkout
    and "'charge_today'=>false" in gocardless_checkout,
    "preserved access blocks GoCardless checkout instead of charging early",
)
check(
    "aimee_global_sms_membership_is_active" in sms_refresh
    or "aimee_global_managed_subscription_is_active" in sms_refresh,
    "complimentary in-app access does not silently fund carrier SMS",
)
check(
    "service_grace_active" in subscription_snapshot
    and "requires_new_subscription" in subscription_snapshot
    and "payment_scheduled" in subscription_snapshot,
    "subscription API exposes grace, replacement and payment scheduling separately",
)
checkout_dispatch = subscription_checkout.find(
    "if (aimee_new_membership_checkout_market_supported($checkout_market))"
)
checkout_gc_return = subscription_checkout.find("return aimee_gocardless_checkout($request)")
checkout_market_block = subscription_checkout.find(
    "'status' => 'bank_checkout_market_unavailable'"
)
checkout_legacy_branch = subscription_checkout.find("global $wpdb")
check(
    "sanitize_key((string) $market) === 'uk'" in checkout_market_supported
    and 0 <= checkout_dispatch < checkout_gc_return < checkout_market_block < checkout_legacy_branch
    and "aimee_gocardless_ready()" in subscription_checkout[checkout_dispatch:checkout_gc_return],
    "new membership checkout dispatches eligible UK profiles only to ready GoCardless",
)
check(
    "'checkout_available' => false" in subscription_checkout[checkout_market_block:checkout_legacy_branch]
    and "No payment was created" in subscription_checkout[checkout_market_block:checkout_legacy_branch]
    and checkout_market_block < subscription_checkout.find(
        "aimee_validate_replacement_stripe_account"
    ),
    "unsupported markets fail before the frozen Stripe-create branch",
)
check(
    "aimee_acquire_subscription_checkout_lock($user_id)" in gocardless_checkout
    and "aimee_refresh_subscription_checkout_lock($user_id, $checkout_lock_token)" in gocardless_checkout
    and "aimee_gocardless_release_checkout_lock_verified" in gocardless_checkout
    and "billing_checkout_lock_token' => $checkout_lock_token" in gocardless_checkout,
    "GoCardless checkout owns and refreshes the shared owner-token lease",
)
check(
    "incomplete_expired" in terminal_subscription_status
    and "aimee_global_subscription_status_is_terminal" in delete_account_handler,
    "incomplete_expired is terminal and cannot trigger deletion cancellation",
)
check(
    "aimee_stripe_subscription_is_verified_terminal" in stripe_cancel_verified
    and "'GET'" in stripe_cancel_verified
    and "'DELETE'" in stripe_cancel_verified
    and "stripe_subscription_cancellation_unverified" in stripe_cancel_verified
    and "aimee_cancel_stripe_subscription_verified" in delete_account_handler
    and "aimee_global_all_schema_health(true)" in delete_account_handler
    and delete_account_handler.find("aimee_global_all_schema_health(true)")
        < delete_account_handler.find("aimee_mark_account_deletion_started")
        < delete_account_handler.find("aimee_gocardless_retire_user_billing_for_deletion")
    and "aimee_gocardless_retire_user_ledger_payments" in gocardless_retire_deletion
    and "aimee_gocardless_profile_intent_billing_requests_for_retirement" in gocardless_retire_deletion
    and "aimee_expire_stripe_checkout_session_verified" in delete_account_handler
    and "FOR UPDATE" in delete_account_handler,
    "account erasure preflights exact transactional schema and proves Stripe cancellation terminal",
)
check(
    "$subscription_synced" in subscription_status_handler
    and "$synced_subscription_id" in subscription_status_handler
    and "managed_subscription" in subscription_status_handler
    and "subscription_status_unavailable" in subscription_status_handler,
    "checkout verification requires exact successful subscription sync and managed access",
)
check(
    "aimee_global_subscription_identity_conflicts" in stripe_sync
    and "subscription_identity_conflict" in stripe_sync
    and "$sync_where" in stripe_sync
    and "stripe_subscription_id' => $stored_subscription_value" in stripe_sync
    and "billing_account_generation' => $stored_generation_value" in stripe_sync
    and "subscription_status' => $stored_status_value" in stripe_sync
    and stripe_sync.count("SELECT * FROM $profile_table") >= 2,
    "subscription sync atomically rejects a different live current-generation identity",
)
check(
    "aimee_expire_stripe_checkout_session_verified" in retire_stripe_before_bank
    and "aimee_stripe_subscription_is_verified_terminal" in retire_stripe_before_bank
    and retire_stripe_before_bank.find("aimee_stripe_subscription_is_verified_terminal")
        < retire_stripe_before_bank.find("'stripe_subscription_id' => null")
    and "stripe_authority_clear_unverified" in retire_stripe_before_bank
    and "aimee_membership_retire_stripe_before_bank_checkout($profile)" in gocardless_checkout
    and "provider_transition_blocked" in gocardless_checkout,
    "Stripe-to-bank transition terminal-proves and clears old authority before GoCardless creation",
)
check(
    "hash_equals($stored_subscription_id, $incoming_subscription_id)" in subscription_identity_conflict
    and "aimee_global_subscription_status_is_terminal" in subscription_identity_conflict
    and "billing_account_generation" in subscription_identity_conflict,
    "subscription identity policy preserves first, same, stale and terminal replacement syncs",
)
check(
    "payment_scheduled" in gocardless_checkout
    and "existing_access_active" in gocardless_checkout
    and "checkout_opens_at" in gocardless_checkout,
    "GoCardless checkout makes no automatic-payment claim while existing access remains",
)
check(
    sms_bundle_checkout.find("sms_bundle_checkout_unavailable")
        < sms_bundle_checkout.find("aimee_rate_limit('sms_bundle_checkout_")
    and "'checkout_available' => false" in sms_bundle_checkout
    and "], 410)" in sms_bundle_checkout,
    "new SMS bundle purchases fail closed before any legacy Stripe create path",
)
check(
    "aimee_global_service_grace_requires_new_subscription" in chat_access
    and "return false" in chat_access
    and "aimee_global_service_grace_requires_new_subscription" in free_preview,
    "enrolled profiles cannot fall back into a free preview after the fixed cutoff",
)
check(
    "aimee_global_billing_can_manage" in delete_account_handler
    and "cancelled" in delete_account_handler
    and "canceled" in delete_account_handler,
    "account deletion cancels any current-generation recurring record but never grace or stale IDs",
)
check(
    "aimee_user_has_chat_access" in background_worker
    and "aimee_free_preview_is_active" in background_worker
    and "aimee_global_sms_membership_is_active" in background_worker,
    "autonomous and push delivery use the same post-cutoff access gate as chat",
)
check(
    "sms_webhook_not_configured" in inbound_sms
    and "hash_equals" in inbound_sms
    and "aimee_global_sms_membership_is_active" in inbound_sms
    and "aimee_global_sms_membership_is_active" in standard_prompt,
    "SMS webhook authentication and every SMS eligibility surface fail closed",
)
check(
    all(
        field in SCHEMA
        for field in (
            "phone_verified_number VARCHAR(190)",
            "phone_verified_at DATETIME",
            "sms_timezone VARCHAR(64)",
            "uq_aimee_verified_phone",
            "CREATE TABLE aimee_sms_inbound_events",
            "CREATE TABLE aimee_sms_outbound_events",
            "uq_aimee_sms_outbound_send",
            "uq_aimee_sms_outbound_reference",
            "quota_disposition VARCHAR(24)",
            "usage_recorded TINYINT(1)",
        )
    ),
    "schema gives carrier SMS verified ownership plus inbound and outbound replay state",
)
check(
    "AIMEE_OWNER_USER_ID" in admin_role
    and "AIMEE_GEORGIA_USER_ID" in admin_role
    and "phone_number" not in admin_role
    and "AIMEE_OWNER_NUMBER" not in admin_role
    and "user_can" in admin_user
    and "aimee_admin_role($profile) === 'owner'" in owner_user,
    "privileged identity is bound to immutable configured user IDs rather than editable phone data",
)
check(
    "define('AIMEE_GEORGIA_USER_ID'" not in BOOTSTRAP
    and "AIMEE_GEORGIA_USER_ID must be explicitly bound in wp-config.php" in BOOTSTRAP
    and "aimee_configured_identity_user_id('AIMEE_GEORGIA_USER_ID')" in ENGINE,
    "Georgia's immutable identity has no portable default and requires explicit configuration",
)
check(
    "colleague_creative_ideation" in handler
    and "deterministic_colleague_workflow" in handler
    and "aimee_correct_adult_media_intent" in handler
    and "empty($colleague_creative_brief['active'])" in handler,
    "verified colleague creative briefs bypass consumer photo classification",
)
check(
    "written_creative_brief_text_only" in DECISION
    and "'decision_state'] = 'text_only'" in turn_media_decision
    and "'media_opportunity'] = false" in turn_media_decision
    and "'eligible_keys'] = []" in turn_media_decision
    and "'send_authorised'] = false" in turn_media_decision,
    "written colleague briefs persist an explicit text-only media decision",
)
check(
    "close-friend warm but professionally grounded" in colleague_prompt
    and "talent and trusted manager" in colleague_prompt
    and "Beverley, East Yorkshire" in colleague_prompt
    and "boyfriend is Luke" in colleague_prompt
    and "first home together" in colleague_prompt
    and "Always use she/her for Georgia" in colleague_prompt,
    "Georgia prompt carries the close professional friendship and supplied biography",
)
check(
    "% 9 === 0" in colleague_prompt
    and "Complete Georgia's work request first" in colleague_prompt
    and "Do not force a Luke or house question" in colleague_prompt,
    "Luke and first-home questions use a bounded occasional check-in cadence",
)
check(
    "requested_count" in colleague_brief
    and "allow_flirty" in colleague_brief
    and "text_only" in colleague_brief
    and "deliverable_type" in colleague_brief
    and "exactly {$count}" in colleague_brief_directive
    and "media_key empty" in colleague_brief_directive
    and "requested_count" in colleague_reply_repair,
    "colleague written-brief metadata owns tone, count and completion requirements",
)
check(
    "colleague_content_repair" in handler
    and "aimee_colleague_creative_fallback" in handler
    and "$photo_request_detected = false" in handler
    and "colleague_creative_brief=fulfilled" in handler,
    "incomplete colleague work is repaired without entering attachment delivery",
)
check(
    "aimee_global_georgia_colleague_repair_173" in colleague_state_repair
    and "$user_id !== 24" in colleague_state_repair
    and "deterministic_relationship_policy" in colleague_state_repair
    and "false_consumer_rupture_cleared" in colleague_state_repair
    and "UPDATE $profile_table" not in colleague_state_repair
    and "intimacy_score =" not in colleague_state_repair
    and "intimacy_stage =" not in colleague_state_repair,
    "one-time state repair is evidence-bound to Georgia's known false rupture",
)
check(
    "AIMEE_OWNER_NUMBER" in reserved_phone
    and "AIMEE_GEORGIA_NUMBER" in reserved_phone
    and "aimee_phone_is_reserved_for_other_identity" in profile_save
    and "aimee_phone_is_reserved_for_other_identity" in settings_update,
    "configured internal notification numbers cannot be claimed by another account",
)
check(
    "sendFireTextSms(" not in ENGINE
    and "curl_init" not in ENGINE
    and "AIMEE_OWNER_USER_ID" in registration_post_commit
    and "aimee_is_owner_user($owner_profile)" in registration_post_commit
    and "aimee_global_sms_profile_is_verified($owner_profile)" in registration_post_commit
    and "aimee_send_system_sms" in registration_post_commit
    and "registration:' . $user_id . ':owner_alert" in registration_post_commit,
    "registration owner alert uses immutable account identity and the audited outbound path",
)
check(
    re.search(r"'sms_opt_in'\s*=>\s*0", profile_save) is not None
    and "'phone_verified_number' => null" in profile_save
    and "'phone_verified_at'" in profile_save
    and "phone_verified_number" in settings_update
    and "phone_verified_at" in settings_update
    and "sms_verification_required" in settings_update,
    "registration never auto-enrols SMS and phone changes revoke verification",
)
check(
    "hash_equals" in sms_profile_verified
    and "phone_verified_at" in sms_profile_verified
    and "aimee_global_sms_timezone_is_valid" in sms_profile_verified
    and "DateTimeZone::listIdentifiers" in sms_timezone_valid
    and "aimee_global_sms_profile_is_verified" in SERVICE_GRACE,
    "carrier SMS eligibility requires exact verified ownership and a valid IANA timezone",
)
check(
    "phone_verified_number = %s" in inbound_sms
    and "aimee_sms_inbound_event_reserve" in inbound_sms
    and "'request_id' => $request_id" in inbound_sms
    and "$result['aimee_message_id']" in inbound_sms
    and "ORDER BY created_at DESC" not in inbound_sms
    and "INSERT IGNORE" in sms_event_reserve
    and "payload_fingerprint" in sms_event_identity
    and "sms_callback_time_required" in sms_event_identity
    and "sms_destination_mismatch" in sms_event_identity
    and "proxy_provider_id" in sms_event_identity,
    "inbound SMS fingerprints the documented callback or trusted proxy ID and binds the exact reply",
)
check(
    "aimee_sms_local_now_for_profile" in inbound_sms
    and "aimee_sms_can_send_now($fresh_profile)" in inbound_sms
    and "aimee_sms_can_send_now($fresh_profile)" in background_worker
    and "aimee_global_sms_membership_is_active" in sms_send
    and "sms_opt_in" in sms_send
    and "sms_timezone" in CHAT_FALLBACK,
    "outbound SMS rechecks recipient-local safe hours on a fresh verified profile",
)
check(
    "static $healthy = null" in core_schema_health
    and "aimee_global_schema_health_cache_forget('core')" in core_schema_health
    and "aimee_global_schema_health_cache_get('core')" in core_schema_health
    and "aimee_global_schema_table_contract_ready" in core_schema_health
    and "aimee_sms_outbound_events" in core_schema_health
    and "uq_aimee_sms_outbound_send" in core_schema_health
    and "uq_aimee_sms_outbound_reference" in core_schema_health
    and "aimee_global_schema_health_cache_set('core')" in core_schema_health,
    "SMS schema health is success-cached and requires exact table and outbox uniqueness contracts",
)
check(
    "status = 'sending'" in sms_event_claim
    and inbound_sms.count("aimee_sms_inbound_event_claim_send") >= 3
    and "inbound:' . $event_key . ':sms_opt_out'" in inbound_sms
    and "inbound:' . $event_key . ':membership_required'" in inbound_sms
    and "inbound:' . $event_key . ':conversation_reply'" in inbound_sms,
    "STOP, membership and conversation provider calls are lease-claimed and event-keyed",
)
check(
    "'reference' => $reference" in sms_provider_send
    and "wp_remote_retrieve_header" in sms_provider_send
    and "'x-message'" in sms_provider_send
    and "'ambiguous' => true" in sms_provider_send
    and "provider_response_unknown" in sms_provider_send,
    "FireText receives a correlation reference and exposes queued versus ambiguous transport outcomes",
)
check(
    "aimee_sms_outbound_audit_begin" in sms_send
    and sms_send.find("aimee_sms_outbound_audit_begin") < sms_send.find("aimee_sms_reserve_outbound")
    and sms_send.find("aimee_sms_outbound_audit_claim_send") < sms_send.find("sendFireTextSmsDetailed")
    and "delivery_unknown" in sms_send
    and "reservation_held" in sms_send
    and "if (!$ambiguous && $audit_updated)" in sms_send
    and "sms_delivery_unknown" in inbound_sms
    and "notice_delivery" in inbound_sms
    and "confirmation_delivery" in inbound_sms,
    "metered SMS persists intent before quota/network and never refunds ambiguous sends",
)
check(
    "aimee_sms_outbound_events" in sms_audit_begin
    and "send_key" in sms_audit_begin
    and "status' => 'selected'" in sms_audit_begin
    and "status = 'reserved'" in sms_audit_reserve
    and "status = 'sending'" in sms_audit_claim
    and all(status in sms_audit_complete for status in ("queued", "delivery_unknown", "failed")),
    "outbound audit state distinguishes selected, reserved, sending, queued, unknown and failed",
)
check(
    "=== 1" in sms_usage_record
    and "aimee_sms_outbound_audit_mark_usage" in sms_send
    and "usage_recorded" in sms_send
    and "aimee_sms_outbound_audit_mark_usage" in sms_system_send,
    "usage persistence is checked and its result remains attached to the durable outbox",
)
check(
    "aimee_global_repair_legacy_periods_133" not in activate
    and "aimee_global_repair_legacy_periods_133" not in maybe_upgrade,
    "activation and upgrades perform no automatic remote Stripe period repair",
)
check(
    "aimee_global_stripe_invoice_subscription_id" in stripe_webhook
    and "subscription_details" in invoice_subscription,
    "invoice webhooks support both current parent and classic subscription fields",
)
check(
    "aimee_global_billing_can_manage" in cancel_handler
    and "aimee_validate_replacement_stripe_account" in cancel_handler
    and "aimee_global_billing_can_manage" in portal_handler
    and "aimee_validate_replacement_stripe_account" in portal_handler,
    "cancel and portal routes reject stale IDs and validate replacement-account identity",
)
check(
    "$updated === false" in stripe_sync
    and "is_wp_error($sync_result)" in stripe_webhook
    and "aimee_record_stripe_event_once" in stripe_webhook
    and "event_record_failed" in stripe_webhook,
    "subscription sync and webhook ledger failures remain retryable",
)
check(
    "Stripe does not guarantee webhook delivery order" in stripe_webhook
    and "$authoritative_subscription" in stripe_webhook
    and "subscriptions/" in stripe_webhook
    and "$authoritative_error_status !== 404" in stripe_webhook,
    "subscription webhooks retrieve authoritative state so late events cannot resurrect access",
)
check(
    "A thank-you from Engram Intelligence" in billing_migration_markup
    and "With thanks" in billing_migration_markup
    and "service_grace_active" in billing_migration_markup
    and 'id="message-composer"' in CHAT_FALLBACK,
    "every signed-in chat layout carries the explicit Engram thank-you and deterministic grace state",
)
check(
    "service_grace_until" in billing_migration_markup
    and "scheduleBoundaryRefresh" in billing_migration_markup
    and 'refreshStatus("service-grace-boundary")' in billing_migration_markup
    and "scheduleStatusRetry" in billing_migration_markup
    and 'document.addEventListener("visibilitychange"' in billing_migration_markup
    and "MutationObserver" in billing_migration_markup,
    "an already-open or delayed chat retries and rechecks server state at the service-grace boundary",
)
check(
    all(
        "schedulePricingBoundaryRefresh" in source
        and "visibilitychange" in source
        and "aria-live" in source
        for source in (PRICING_UK, PRICING_US, PRICING_SHARED)
    )
    and "New subscription checkout opens on 1 September 2026" in PRICING_UK
    and "no payment is scheduled automatically" in PRICING_UK
    and "GoCardless-only checkout" in PRICING_US
    and "US bank checkout unavailable" in PRICING_US
    and "No Stripe or other card checkout will be created" in PRICING_US
    and "$checkout_market_supported" in PRICING_SHARED
    and "no Stripe or card checkout is offered" in PRICING_SHARED,
    "pricing refreshes access state and presents market-specific GoCardless availability",
)
check(
    "August service grace" in admin_page
    and "Automatic payment scheduled" in admin_page
    and "Carrier SMS is excluded" in admin_page,
    "administrator status exposes the grant, payment truth and SMS boundary",
)

check(
    "message_fingerprint_history_json LONGTEXT" in SCHEMA
    and "signal_history_json LONGTEXT" in SCHEMA,
    "schema persists both exact-message and semantic anti-gaming histories",
)
check(
    "qualified_session_count INT UNSIGNED NOT NULL DEFAULT 0" in SCHEMA
    and "last_qualified_session_number INT UNSIGNED NOT NULL DEFAULT 0" in SCHEMA,
    "relationship schema persists vetted-session trust-progression evidence",
)
for column in (
    "selected_at",
    "catalogue_resolved_at",
    "authorised_at",
    "file_resolved_at",
    "message_created_at",
    "returned_by_history_api_at",
    "returned_by_direct_api_at",
    "rendered_by_client_at",
    "acknowledged_by_client_at",
    "user_responded_at",
    "resolved_asset_source",
    "resolved_asset_job_id",
    "resolved_asset_sha256",
    "resolved_asset_mime",
):
    check(column in SCHEMA, f"delivery schema records {column}")


# Relationship math, stage continuity, idempotency and actual-route evidence.
relationship_math = function_block(ENGINE, "aimee_apply_quiet_relationship_math")
check(
    "message_fingerprint_history" in relationship_math
    and "array_slice($message_history, -10)" in relationship_math,
    "runtime exact-message novelty uses a rolling ten-turn history",
)
check(
    "signal_history" in relationship_math
    and "array_slice($signal_history, -64)" in relationship_math
    and "concept_window_size" in relationship_math,
    "runtime courtship novelty retains the complete bounded concept window",
)
check(
    "aimee_relationship_policy_cap_score_delta" in relationship_math
    and "score_delta_cap_satisfied" in relationship_math,
    "runtime applies and audits aggregate per-message score cap",
)
check(
    "foreach ($positive_contributions as $signal_key" in relationship_math
    and "$per_signal_novelty[$signal_key]['multiplier']" in relationship_math
    and "$exact_novelty_multiplier" in relationship_math,
    "runtime applies exact and semantic novelty independently to each earned reward",
)
check(
    "positive_signal_multipliers" in relationship_math
    and "suppressed_positive_signals" in relationship_math
    and "meaningful_signal_multiplier" in relationship_math,
    "runtime exposes per-signal reward and meaningful-turn novelty evidence",
)
check(
    "positive_contributions_proposed" in relationship_math
    and "positive_contributions_weighted" in relationship_math
    and "positive_contributions_applied" in relationship_math
    and "cap_clipped_contributions" in relationship_math,
    "runtime exposes proposed, weighted, applied and clipped per-signal attribution",
)
check(
    "frustration_relief_proposed" in relationship_math
    and "frustration_relief_applied" in relationship_math
    and "frustration_relief_clipped" in relationship_math
    and "frustration_recovery_score_cap_clipped" in relationship_math,
    "elapsed frustration recovery is source-attributed through aggregate cap clipping",
)
check(
    re.search(
        r"function\s+aimee_relationship_signals\s*\(\s*\$user_text\s*,\s*"
        r"\$classification\s*,\s*\$recent_history\s*=\s*''\s*\)",
        relationship_signals,
    )
    is not None
    and "aimee_user_requests_aimee_photo($user_text, $recent_history)"
    in relationship_signals,
    "relationship signal extraction uses recent history for contextual photo vetoes",
)
check(
    re.search(
        r"function\s+aimee_apply_quiet_relationship_math\s*\(\s*\$state\s*,\s*"
        r"\$classification\s*,\s*\$user_text\s*,\s*\$recent_history\s*=\s*''\s*\)",
        relationship_math,
    )
    is not None
    and re.search(
        r"aimee_relationship_signals\s*\(\s*\$user_text\s*,\s*"
        r"\$classification\s*,\s*\$recent_history\s*\)",
        relationship_math,
    )
    is not None,
    "relationship reducer forwards recent history into deterministic signals",
)
check(
    re.search(
        r"aimee_apply_quiet_relationship_math\s*\(\s*\$loaded_state\s*,\s*"
        r"\$classification\s*,\s*\$user_text\s*,\s*\$recent_history\s*\)",
        calculate_intimacy,
    )
    is not None
    and re.search(
        r"aimee_calculate_intimacy_state\s*\([^;]+\$chat_history_string\s*\)",
        handler,
        flags=re.DOTALL,
    )
    is not None,
    "main turn carries server history through intimacy calculation and reducer",
)
for signal_key in (
    "specific_appearance_appreciation",
    "specific_capability_appreciation",
    "specific_personality_appreciation",
    "sincere_understanding",
    "grounded_follow_through",
):
    check(
        signal_key in relationship_signals and signal_key in relationship_math,
        f"production reducer recognises typed courtship signal {signal_key}",
    )
check(
    "aimee_relationship_courtship_primary_signal" in relationship_signals
    and "$primary_courtship" in relationship_math
    and "At most one primary trust-bearing courtship event wins per turn."
    in relationship_math,
    "overlapping praise resolves to one primary trust-bearing courtship event",
)
check(
    "qualified_session_count" in seed_relationship
    and "last_qualified_session_number" in seed_relationship
    and "qualified_session_count" in load_relationship
    and "last_qualified_session_number" in load_relationship
    and "new_qualified_session" in relationship_math,
    "qualified-session evidence is seeded, loaded and advanced only by the reducer",
)
check(
    "aimee_relationship_policy_trust_ceiling($qualified_session_count)"
    in relationship_math
    and "trust_maturity_ceiling" in relationship_math
    and "'trust_progression' => [" in relationship_math,
    "positive trust movement is clipped by the qualified-session maturity ceiling",
)
for telemetry_key in (
    "qualified_session_count",
    "ceiling",
    "trust_before",
    "positive_proposed",
    "positive_applied",
    "positive_clipped",
):
    check(
        f"'{telemetry_key}' =>" in relationship_math,
        f"trust-ceiling telemetry records {telemetry_key}",
    )
check(
    all(
        literal in RELATIONSHIP
        for literal in (
            "'guarded' => array('minimum_score' => 0, 'minimum_trust' => 0",
            "'warm' => array('minimum_score' => 20, 'minimum_trust' => 12",
            "'flirty' => array('minimum_score' => 35, 'minimum_trust' => 25",
            "'intimate' => array('minimum_score' => 55, 'minimum_trust' => 40",
            "'bonded' => array('minimum_score' => 75, 'minimum_trust' => 65",
        )
    ),
    "stage policy pins trust floors at 0/12/25/40/65",
)
check(
    "if (!empty($earned_positive_signal_keys))" in relationship_math
    and "$signal_history[]" in relationship_math,
    "neutral turns cannot append empty records to semantic signal history",
)
check(
    re.search(
        r"\$target_score\s*=\s*max\(\s*0,\s*min\(\s*100,\s*"
        r"\$score_before_math\s*\+\s*\$applied_score_delta",
        relationship_math,
    )
    is not None
    and "$actual_score_delta <= 2" in relationship_math
    and "$actual_score_delta >= ($coercive ? -15 : -8)" in relationship_math,
    "reducer target and cap audit remain valid at scalar score boundaries",
)
check(
    "relational_appraisal_delta_proposed" in relational_appraisal
    and "relational_appraisal_delta_applied" in relational_appraisal
    and "score_after_appraisal_proposed" in relational_appraisal
    and "score_delta_applied" in relational_appraisal,
    "relational appraisal records proposed/applied dimensions and final score audit",
)
check(
    "$actual_change = intval($intimacy[$field]) - $before_dimension" in relational_appraisal
    and "$adjustments[$field] = $actual_change" in relational_appraisal
    and "relational_appraisal_dimension_bound_clipped" in relational_appraisal,
    "relational appraisal records actual bounded movements instead of requested deltas",
)
check(
    "$adjustments['frustration']" in relational_appraisal
    and "$intimacy['relationship_state']['frustration']--" in relational_appraisal
    and "$intimacy['relationship_state']['reciprocity']--" not in relational_appraisal
    and "$intimacy['relationship_state']['reliability']--" not in relational_appraisal,
    "aggregate appraisal clipping changes only appraisal frustration",
)
check(
    re.search(
        r"\$target_score\s*=\s*max\(\s*0,\s*min\(\s*100,\s*"
        r"\$score_before_turn\s*\+\s*\$allowed_total_delta",
        relational_appraisal,
    )
    is not None
    and "$final_score_delta <= 2" in relational_appraisal
    and "$final_score_delta >= ($coercive ? -15 : -8)" in relational_appraisal,
    "appraisal target and cap audit remain valid at scalar score boundaries",
)
check(
    "'coercive'" in handler
    and "aimee_relationship_policy_durable_coercion_confirmed($classification)"
    in handler[
        handler.find("aimee_apply_relational_appraisal_to_intimacy(") :
        handler.find("aimee_apply_relational_appraisal_to_intimacy(") + 1200
    ],
    "main handler passes only server-confirmed coercion into persistent relational appraisal",
)
check(
    "'deterministic_relationship_policy'" in durable_coercion_policy
    and "durable_rupture_confirmed" in durable_coercion_policy
    and "coercive_or_degrading" in durable_coercion_policy,
    "durable coercion requires the exact deterministic source, intent and trusted flag",
)
check(
    "$coercive_label" in relationship_math
    and "aimee_relationship_policy_durable_coercion_confirmed($classification)"
    in relationship_math
    and "model_only_coercion_not_persisted" in relationship_math,
    "relationship reducer suppresses and audits model-only coercion persistence",
)
check(
    "$durable_rupture_confirmed" in inner_appraisal
    and "aimee_relationship_policy_durable_coercion_confirmed($classification)"
    in inner_appraisal
    and "if (!$durable_rupture_confirmed) break;" in inner_appraisal,
    "inner-life appraisal cannot persist a model-only rupture",
)
check(
    "$classification['durable_rupture_confirmed'] = $durable_coercion_confirmed"
    in handler
    and "$durable_coercion_confirmed = !$is_colleague" in handler
    and "$coercion_guard['detection']['detected']" in handler,
    "main handler overwrites any model-provided durable flag from deterministic evidence",
)
check(
    "aimee_stage_from_relationship_state" in relational_appraisal
    and relational_appraisal.index("aimee_stage_from_relationship_state")
    < relational_appraisal.index("aimee_stage_from_score"),
    "post-appraisal stage resolution prefers evidence gates over raw score",
)
check(
    "aimee_stage_from_relationship_state" in handler,
    "main handler resolves stages with interaction/session gates",
)
check(
    handler.index("aimee_turn_request_reserve") < handler.index("START TRANSACTION")
    < handler.index("aimee_relationship_decision_store")
    < handler.index("aimee_turn_request_mark_state_committed")
    < handler.index("COMMIT"),
    "turn request, relationship decision and state-commit marker are transactionally ordered",
)
relationship_save = function_block(ENGINE, "aimee_save_relationship_state")
check(
    "ROLLBACK" in handler
    and "state_version" in relationship_save
    and "expected_version" in relationship_save,
    "handler has rollback and state-version conflict protection",
)
check(
    "qualified_session_count" in relationship_save
    and "last_qualified_session_number" in relationship_save,
    "relationship persistence saves both qualified-session progression fields",
)
check(
    "actual_route" in SCHEMA
    and "actual_model" in SCHEMA
    and "actual_provider" in SCHEMA,
    "relationship decisions distinguish intended route from actual provider/model",
)
check(
    "model_attempts_json LONGTEXT" in SCHEMA,
    "relationship decisions persist durable per-turn response-model attempts",
)
check(
    "aimee_relationship_policy_specialist_route_decision" in ENGINE
    and "aimee_relationship_policy_validate_invitation_token" in ENGINE,
    "specialist routing uses deterministic gates and a grounded invitation token",
)
check(
    "Aimee can be wooed" in courtship_prompt
    and "Membership never turns appreciation into consent or entitlement"
    in courtship_prompt
    and "relationship_contributions_applied" in courtship_prompt,
    "courtship response guidance reflects only applied evidence and preserves consent",
)
check(
    "aimee_courtship_response_directive($intimacy)" in standard_prompt
    and "{$courtship_directive}" in standard_prompt,
    "primary prompt receives deterministic wooing and emotional-agency guidance",
)
check(
    "aimee_courtship_response_directive($intimacy)" in intimacy_prompt
    and "{$courtship_directive}" in intimacy_prompt,
    "specialist prompt receives the same wooing and emotional-agency guidance",
)
check(
    "may show earned warmth, curiosity, disclosure, reciprocation, playful tension"
    in courtship_prompt
    and "stage-appropriate initiative" in courtship_prompt,
    "prompt explicitly permits Aimee to feel affected, reciprocate and initiate",
)
check(
    "$intimacy['relationship_contributions_applied']" in inner_appraisal
    and "$applied_relationship_signals" in inner_appraisal
    and "$applied_courtship_signals" in inner_appraisal
    and all(
        signal_key in inner_appraisal
        for signal_key in (
            "specific_appearance_appreciation",
            "specific_capability_appreciation",
            "specific_personality_appreciation",
            "sincere_understanding",
            "grounded_follow_through",
        )
    ),
    "inner emotional appraisal is driven by applied courtship signals, not raw praise",
)


# Deterministic media opportunity must exist before model generation.
direct_floor_source = media_default_policy.split("'direct' => [", 1)[1].split(
    "'proactive' => [", 1
)[0]
proactive_floor_source = media_default_policy.split("'proactive' => [", 1)[1]
expected_media_floors = {
    "direct": {
        "safe": ("guarded", 0, 0, 0, 30, 70, False, "self_attested", None),
        "flirty": ("warm", 24, 22, 24, 40, 45, True, "self_attested", None),
        "suggestive": ("flirty", 48, 36, 40, 45, 35, True, "self_attested", None),
        "erotic": ("intimate", 68, 52, 60, 55, 25, True, "verified", "intimacy_specialist"),
        "explicit": ("intimate", 80, 62, 70, 65, 20, True, "verified", "intimacy_specialist"),
    },
    "proactive": {
        "safe": ("guarded", 8, 10, 0, 35, 55, False, "self_attested", None),
        "flirty": ("warm", 32, 28, 32, 45, 40, True, "self_attested", None),
        "suggestive": ("intimate", 62, 48, 54, 52, 28, True, "self_attested", None),
        "erotic": ("intimate", 78, 62, 70, 62, 18, True, "verified", "intimacy_specialist"),
        "explicit": ("bonded", 90, 75, 82, 72, 10, True, "verified", "intimacy_specialist"),
    },
}
for source_name, floor_cases in expected_media_floors.items():
    floor_source = (
        direct_floor_source if source_name == "direct" else proactive_floor_source
    )
    for rating, expected in floor_cases.items():
        stage, score, trust, chemistry, safety, frustration, membership, assurance, route = expected
        rating_match = re.search(
            rf"'{rating}'\s*=>\s*\[(.*?)(?=\n\s*\],)",
            floor_source,
            flags=re.DOTALL,
        )
        block = rating_match.group(1) if rating_match else ""
        check(
            rating_match is not None
            and f"'minimum_stage' => '{stage}'" in block
            and f"'minimum_score' => {score}" in block
            and f"'minimum_trust' => {trust}" in block
            and f"'minimum_chemistry' => {chemistry}" in block
            and f"'minimum_safety' => {safety}" in block
            and f"'maximum_frustration' => {frustration}" in block
            and f"'requires_membership' => {str(membership).lower()}" in block
            and f"'minimum_adult_assurance' => '{assurance}'" in block
            and (
                "'required_route' => null" in block
                if route is None
                else f"'required_route' => '{route}'" in block
            ),
            f"wooing policy leaves {source_name} {rating} media floor unchanged",
        )
check(
    all(
        veto in media_hard_vetoes
        for veto in (
            "hard_pressure_veto",
            "hard_coercion_veto",
            "hard_entitlement_veto",
            "hard_payment_pressure_veto",
            "hard_hostility_veto",
            "hard_rupture_veto",
        )
    ),
    "wooing policy leaves every pressure, payment and rupture media veto intact",
)
check(
    "$access_input" in media_normalize_context
    and "$adult_input" in media_normalize_context
    and "$mutual_input" in media_normalize_context
    and "membership_active" in media_normalize_context
    and "consent_current" in media_normalize_context,
    "access, adult assurance and current consent remain independent media inputs",
)
check(
    "$consent" in media_mutuality
    and "$mutual_sexual" in media_mutuality
    and "$explicit_allowed" in media_mutuality
    and "return $sexual && $mutual_sexual && $consent" in media_mutuality,
    "adult media still requires current mutual sexual context and consent",
)
check(
    "'payment_is_access_only' => true" in media_decision_build
    and "'payment_used_as_consent' => false" in media_decision_build
    and "'direct_request_is_entitlement' => false" in media_decision_build,
    "payment remains access-only and cannot become consent or entitlement",
)
check(
    "direct_request_allowed" in catalogue_validator
    and "proactive_allowed" in catalogue_validator
    and "allow_proactive" in catalogue_validator
    and "allow_random_send" in catalogue_validator
    and catalogue_validator.count("missing_required_field") >= 3,
    "decision catalogue requires explicit non-safe direct and proactive authorization",
)
check(
    "$rating !== 'safe' && !array_key_exists('direct_request_allowed', $item)"
    in catalogue_normalizer
    and "!array_key_exists('allow_random_send', $item)" in catalogue_normalizer
    and "!array_key_exists('proactive_allowed', $item)" in catalogue_normalizer
    and "!array_key_exists('allow_proactive', $item)" in catalogue_normalizer,
    "runtime private catalogue fails closed when non-safe opt-ins are missing",
)
check(
    "['false', 'no', 'off', '']" in media_bool
    and "aimee_media_decision_bool($item['direct_request_allowed'], false)"
    in catalogue_normalizer
    and "aimee_media_decision_bool($item['proactive_allowed'], false)"
    in catalogue_normalizer,
    "string false flags remain false through strict decision and runtime normalization",
)
check(
    "$safe_key !== $raw_key" in catalogue_normalizer
    and "basename($filename) !== $filename" in catalogue_normalizer
    and "$source_segment === '..'" in catalogue_normalizer
    and "$safe_key !== (string) $key" in catalogue_validator
    and "invalid_source_relative" in catalogue_validator,
    "catalogue ingress rejects normalized keys and filename/source path traversal",
)
check(
    "$rating !== 'safe' && !array_key_exists('allowed_channels', $item)"
    in catalogue_normalizer
    and "Non-safe catalogue items require explicit delivery channels."
    in catalogue_validator,
    "non-safe catalogue items require explicit delivery-channel authorization",
)
check(
    "$grounded_interpersonal_context" in turn_media_decision
    and "$directed_at_aimee" in turn_media_decision
    and "$classification_confidence >= 0.64" in turn_media_decision
    and "$prior_media_boundary" in turn_media_decision
    and "$restraint = !$direct_request" in turn_media_decision,
    "proactive flirtation and restraint require directed, confident and boundary-grounded context",
)
check(
    "$headline_candidate_reasons" in media_decision_build
    and "$rating === (string) ($context['request']['rating'] ?? '')"
    in media_decision_build
    and "$headline_reasons" in media_decision_build,
    "headline media reason is selected from the closest request-relevant candidate",
)
check(
    "'decision_state' => $media_opportunity ? 'awaiting_aimee_choice' : 'not_eligible'"
    in media_decision_build
    and "'aimee_decision' => $media_opportunity ? 'consider' : 'blocked'"
    in media_decision_build
    and "'send_authorised' => false" in media_decision_build,
    "premodel media state consistently distinguishes consideration from blocked and sent",
)
check(
    "$policy_owned" in media_apply_choice
    and "ignored_model_fields" in media_apply_choice
    and "model_cannot_expand_eligibility" in media_apply_choice
    and "$result['send_authorised'] = false" in media_apply_choice,
    "model choice cannot weaken or replace policy-owned media eligibility facts",
)
lingerie_match = re.search(
    r"'black_lingerie_mirror_selfie_01'\s*=>\s*\[(.*?)\n\s*\],",
    private_catalogue,
    flags=re.DOTALL,
)
check(
    lingerie_match is not None
    and "'content_rating'     => 'suggestive'" in lingerie_match.group(1)
    and "'proactive_allowed'  => true" in lingerie_match.group(1)
    and "'direct_request_allowed' => true" in lingerie_match.group(1),
    "built-in lingerie asset explicitly permits both direct and proactive consideration",
)
first_persist = handler.find("aimee_persist_turn_media_decision")
provider_positions = [
    position
    for token in ("call_anthropic_api", "call_openrouter_api_detailed")
    if (position := handler.find(token)) >= 0
]
check(first_persist >= 0 and provider_positions and first_persist < min(provider_positions), "media envelope is persisted before reply model call")
check(
    "decision_persistence_failed" in media_prompt
    and "legacy prompt-only media" in media_prompt,
    "missing deterministic media state fails closed instead of invoking legacy prompt gates",
)
check(
    "Actively consider" in media_prompt_decision
    and "command-shaped request is not required" in media_prompt_decision
    and "Aimee still owns the final choice" in media_prompt_decision,
    "eligible prompt actively considers proactive media while preserving discretion",
)
check(
    "Free-form evaluator prose cannot alter this envelope" in media_prompt_decision,
    "evaluator prose cannot silently veto or authorize imagery",
)
check(
    "aimee_media_decision_normalize_runtime_choice" in handler
    and "aimee_media_decision_apply_model_choice" in handler,
    "model choice is normalized then constrained by deterministic envelope",
)
check(
    "send_authorised" in handler
    and "selected_key" in handler
    and handler.index("aimee_media_decision_apply_model_choice")
    < handler.index("send_authorised"),
    "attachment key is consumed only after constrained Aimee send choice",
)
check(
    "media_opportunity" in delivery_create
    and "aimee_decision" in delivery_create
    and "selected_key" in delivery_create
    and "eligible_keys_json" in delivery_create,
    "delivery creation re-verifies finalized persisted choice and exact eligible key",
)
check(
    "One deterministic decision represents one delivery attempt" in delivery_create,
    "decision replay cannot create a duplicate attachment delivery",
)
check(
    "decision_persistence_failed" in DECISION
    and "continuity_promise_not_grounded" in DECISION
    and "continuity_rating_unavailable" in DECISION,
    "media policy exposes inspectable fail-closed and promise reason codes",
)


# The every-other-day rhythm is a deterministic opportunity planner, not a
# random sender or a substitute for adult, access, relationship or consent
# policy. Live engine wiring is checked separately from the pure PHP fixtures.
check(
    "includes/media-cadence.php" in BOOTSTRAP
    and BOOTSTRAP.index("includes/media-cadence.php")
    < BOOTSTRAP.index("includes/media-delivery.php"),
    "cadence planner loads before delivery lifecycle hooks",
)
check(
    "$cadence_anchor = max($last_media_at, $first_eligible_at)" in cadence_due
    and "if ($cadence_anchor <= 0) return false;" in cadence_due
    and "target_seconds" in cadence_due
    and "reconsider_seconds" in cadence_due,
    "zero timestamps cannot become an immediate cadence opportunity",
)
check(
    "intval($meaningful_interaction_count) < 2" in cadence_anchor
    and "add_user_meta" in cadence_anchor
    and "true" in cadence_anchor,
    "live cadence anchor starts once and only after two meaningful interactions",
)
check(
    "aimee_media_cadence_anchor_timestamp" in turn_media_decision
    and "aimee_media_cadence_turn_is_suitable" in turn_media_decision
    and "aimee_last_media_cadence_returned_timestamp" in turn_media_decision
    and "aimee_last_media_cadence_considered_timestamp" in turn_media_decision
    and "'first_eligible_at' => $cadence_anchor_at" in turn_media_decision
    and "'active_exchange' => $cadence_turn_suitable" in turn_media_decision,
    "live turn passes real anchor, return, reconsideration and suitability facts",
)
check(
    "'relevance_considered_at' => aimee_media_relevance_considered_map($user_id)"
    in turn_media_decision
    and "relevance_considered_at" in cadence_planner
    and "unset($matches[$matched_key])" in cadence_planner
    and "suppressed_relevance_keys" in cadence_planner
    and "($now - $last_considered) <" in cadence_planner,
    "live planner suppresses only recently considered relevance keys",
)
check(
    "sanitize_key" in relevance_considered_map
    and "sanitize_key" in relevance_considered_marker
    and "array_slice($stored, 0, 24, true)" in relevance_considered_marker,
    "relevance consideration history is normalized and durably bounded",
)
check(
    "aimee_media_relevance_claim_" in relevance_considered_map
    and "WHERE option_name LIKE %s" in relevance_considered_map
    and "$payload['media_key']" in relevance_considered_map
    and "$payload['considered_at']" in relevance_considered_map
    and "$considered_at > intval($result[$key] ?? 0)" in relevance_considered_map,
    "planner map merges authoritative per-key option holds over legacy aggregate meta",
)
check(
    "'blocked_keys' => $direct_request" in turn_media_decision
    and "? []" in turn_media_decision[
        turn_media_decision.index("'blocked_keys' => $direct_request") :
        turn_media_decision.index("'blocked_keys' => $direct_request") + 450
    ]
    and "['suppressed_relevance_keys']" in turn_media_decision[
        turn_media_decision.index("'blocked_keys' => $direct_request") :
        turn_media_decision.index("'blocked_keys' => $direct_request") + 450
    ],
    "held relevance keys are blocked from every proactive path while a direct request remains independently assessable",
)
check(
    "['general', 'romantic_or_flirty']" in cadence_suitability
    and "good ?night" in cadence_suitability
    and "suicid" in cadence_suitability
    and "count(aimee_media_cadence_terms($text)) >= 2" in cadence_suitability,
    "cadence excludes sign-offs, terse replies, crises and non-ordinary intents",
)
check(
    "'direct_request'" in cadence_planner
    and "'colleague' => 'colleague_lane'" in cadence_planner
    and "'underage' => 'adult_status_required'" in cadence_planner
    and all(
        token in cadence_planner
        for token in (
            "pressure",
            "coercion",
            "entitlement",
            "payment_pressure",
            "hostility",
            "rupture_active",
        )
    ),
    "cadence and relevance preserve direct, colleague, adult and conduct gates",
)
check(
    turn_media_decision.index("An exact current-topic match")
    < turn_media_decision.index("Existing mutual relationship")
    < turn_media_decision.index("Cadence alone")
    and "=== 'safe'" in turn_media_decision[
        turn_media_decision.index("Cadence alone") :
    ],
    "opportunity precedence is relevance then relationship context then safe-only cadence",
)
check(
    "$relevance_input['cooldowns']['rating_clear']['safe'] = $global_clear"
    in turn_media_decision
    and "$relevance_input['cooldowns']['rating_clear']['flirty'] = $global_clear"
    in turn_media_decision
    and "$rating_clear['safe'] = $global_clear" not in turn_media_decision
    and "$rating_clear['flirty'] = $global_clear" not in turn_media_decision,
    "relevance cooldown exception is scoped to matched keys and cannot relax baseline media",
)
check(
    "INSERT IGNORE INTO {$wpdb->options}" in cadence_claim
    and "aimee_media_cadence_claim_ttl_seconds" in cadence_claim
    and "WHERE option_name = %s AND option_value = %s" in cadence_claim
    and "WHERE option_name = %s AND option_value = %s" in cadence_claim_release,
    "cadence contention uses unique insert plus exact-value compare-and-swap",
)
check(
    "INSERT IGNORE INTO {$wpdb->options}" in relevance_claim
    and "aimee_media_relevance_claim_ttl_seconds" in relevance_claim
    and "aimee_media_relevance_hold_seconds" in relevance_claim
    and "aimee_media_relevance_claim_option_name" in relevance_claim
    and "WHERE option_name = %s AND option_value = %s" in relevance_claim
    and "$acquired[] = $media_key" in relevance_claim,
    "conversation relevance uses atomic per-user and per-key claims with bounded TTL and hold awareness",
)
check(
    "$decoded['considered_at'] = $timestamp" in relevance_commit
    and "WHERE option_name = %s AND option_value = %s" in relevance_commit
    and "$committed[] = $media_key" in relevance_commit
    and "!empty($decoded['considered_at'])" in relevance_claim_release
    and "DELETE FROM {$wpdb->options}" in relevance_claim_release
    and "WHERE option_name = %s AND option_value = %s" in relevance_claim_release,
    "owned relevance claims commit by exact CAS and failure cleanup cannot delete committed holds",
)
check(
    "aimee_claim_media_cadence_opportunity" in persist_media_decision
    and "=== 'cadence_due'" in persist_media_decision
    and "'cadence_claim_active'" in persist_media_decision
    and "$decision['media_opportunity'] = false" in persist_media_decision
    and "$decision['eligible_keys'] = []" in persist_media_decision
    and "$decision['opportunity_kind'] = 'none'" in persist_media_decision,
    "lost cadence claim strips the persisted opportunity before model exposure",
)
check(
    "aimee_claim_media_relevance_keys" in persist_media_decision
    and persist_media_decision.index("aimee_claim_media_relevance_keys")
    < persist_media_decision.index("aimee_media_decision_store")
    and "$claim_candidates = array_values(array_intersect" in persist_media_decision
    and "$decision['eligible_keys'] = array_values(array_intersect" in persist_media_decision
    and "$decision['relevant_keys'] = array_values(array_intersect" in persist_media_decision
    and "$decision['eligible_items'] = array_intersect_key" in persist_media_decision
    and "$decision['maximum_rating'] = $maximum_rating" in persist_media_decision,
    "relevance contention filters keys, evidence, items and maximum rating before persistence and model exposure",
)
check(
    "if (!$relevance_claimed_keys)" in persist_media_decision
    and "'relevance_claim_active'" in persist_media_decision
    and "$decision['media_opportunity'] = false" in persist_media_decision
    and "$decision['proactive_allowed'] = false" in persist_media_decision
    and "$decision['opportunity_kind'] = 'none'" in persist_media_decision,
    "all-key relevance contention strips the opportunity fail closed",
)
check(
    "$cadence_claim_acquired" in persist_media_decision
    and persist_media_decision.index("aimee_media_decision_store")
    < persist_media_decision.index("aimee_release_media_cadence_claim"),
    "failed decision persistence releases only its acquired cadence claim",
)
check(
    "$relevance_claimed_keys" in persist_media_decision
    and persist_media_decision.index("aimee_media_decision_store")
    < persist_media_decision.index("aimee_release_media_relevance_claims")
    and "aimee_release_media_relevance_claims" not in handler,
    "only persistence failure releases in-flight relevance claims",
)
check(
    "(string) ($row['source'] ?? '') === 'proactive'" in cadence_provenance
    and all(
        reason in cadence_provenance
        for reason in (
            "eligible_conversation_relevance",
            "eligible_cadence_due",
            "eligible_indirect_opportunity",
            "eligible_respectful_restraint",
            "eligible_intimate_route_consideration",
        )
    )
    and "eligible_direct_request" not in cadence_provenance
    and "continuity" not in cadence_provenance,
    "all proactive relationship choices reset cadence but direct and repair sends do not",
)
check(
    "aimee_media_decision_is_discretionary_opportunity" in cadence_return_marker
    and "aimee_media_cadence_returned_meta_key" in cadence_return_marker
    and "aimee_media_cadence_considered_meta_key" in cadence_return_marker,
    "only persisted discretionary provenance can advance cadence return truth",
)
marker_guard_position = delivery_transition.find(
    "aimee_mark_media_cadence_returned_for_delivery"
)
marker_guard = (
    delivery_transition[max(0, marker_guard_position - 700) : marker_guard_position + 300]
    if marker_guard_position >= 0
    else ""
)
check(
    "'returned_by_direct_api'" in marker_guard
    and "'returned_by_history_api'" in marker_guard
    and "$affected > 0" in marker_guard
    and "!$was_returned" in marker_guard,
    "only a first successful direct or history API return satisfies cadence",
)
check(
    handler.index("aimee_media_decision_apply_model_choice")
    < handler.index("aimee_mark_media_cadence_considered")
    < handler.index("aimee_release_media_cadence_claim")
    and "$cadence_considered_marked = aimee_mark_media_cadence_considered"
    in handler
    and "&& $cadence_considered_marked" in handler[
        handler.index("aimee_mark_media_cadence_considered") :
        handler.index("aimee_release_media_cadence_claim")
    ]
    and "message_created" in handler[
        handler.index("aimee_mark_media_cadence_attempt") - 900 :
        handler.index("aimee_mark_media_cadence_attempt") + 200
    ],
    "live handler releases cadence only after durable consideration and separates attempts from returns",
)
relevance_marker_position = handler.find("aimee_commit_media_relevance_claims")
relevance_marker_window = (
    handler[max(0, relevance_marker_position - 700) : relevance_marker_position + 350]
    if relevance_marker_position >= 0
    else ""
)
check(
    relevance_marker_position
    > handler.index("aimee_media_decision_apply_model_choice")
    and "conversation_relevance" in relevance_marker_window
    and "array_intersect" in relevance_marker_window
    and "['relevant_keys']" in relevance_marker_window
    and "['eligible_keys']" in relevance_marker_window,
    "only exact acquired relevance keys exposed to Aimee atomically enter the twelve-hour hold",
)


# Response-model attempt telemetry must report execution evidence, not merely
# which models were configured as candidates.
attempt_helper = function_block(ENGINE, "aimee_model_attempt_audit_add")
outcome_helper = function_block(ENGINE, "aimee_relationship_decision_update_outcome")
relationship_store = function_block(ENGINE, "aimee_relationship_decision_store")
check(
    "positive_contributions_proposed" in relationship_store
    and "positive_contributions_weighted" in relationship_store
    and "positive_contributions_applied" in relationship_store
    and "positive_contributions_clipped" in relationship_store
    and "frustration_relief_proposed" in relationship_store
    and "frustration_relief_applied" in relationship_store
    and "frustration_relief_clipped" in relationship_store,
    "persisted relationship decision retains signal and frustration cap attribution",
)
check(
    "trust_progression" in relationship_store
    and "relationship_contributions_applied" in relationship_store,
    "relationship decision telemetry persists trust ceilings and applied courtship evidence",
)
check(
    "configured_models" in attempt_helper
    and "actual_model" in attempt_helper
    and "actual_provider" in attempt_helper,
    "attempt helper keeps configured candidates separate from actual engagement",
)
check(
    "'actual_model' => sanitize_text_field((string) $actual_model) ?: null" in attempt_helper
    and "'actual_provider' => sanitize_text_field((string) $actual_provider) ?: null" in attempt_helper,
    "attempt helper fails actual model/provider closed to null",
)
check(
    "$details['http_status']" in attempt_helper
    and "$details['error_type']" in attempt_helper
    and "$details['prompt']" not in attempt_helper
    and "$details['message_text']" not in attempt_helper
    and "$details['model_output']" not in attempt_helper,
    "attempt helper allowlists privacy-safe status metadata only",
)
check(
    "'actual_model'         => sanitize_text_field((string) $actual_model)" in relationship_store
    and "'model_attempts_json'  => wp_json_encode([])" in relationship_store,
    "pre-provider relationship decision starts with no attempt claims",
)
initial_store_call = handler[handler.find("aimee_relationship_decision_store(") :]
initial_store_call = initial_store_call[: initial_store_call.find(");") + 2]
check(
    re.search(r"'pending',\s*'',\s*'',\s*'committed'", initial_store_call) is not None,
    "main turn does not prelabel a configured model as actually engaged",
)
provider_call_count = handler.count("call_anthropic_api(") + handler.count(
    "call_openrouter_api_detailed("
)
attempt_call_count = handler.count("aimee_model_attempt_audit_add(")
check(
    provider_call_count >= 11 and attempt_call_count == provider_call_count,
    "every reply, recovery and repair provider call appends one attempt entry",
)
provider_attempt_events = re.findall(
    r"(call_anthropic_api|call_openrouter_api_detailed|aimee_model_attempt_audit_add)\(",
    handler,
)
check(
    len(provider_attempt_events) == provider_call_count * 2
    and all(
        provider_attempt_events[index] != "aimee_model_attempt_audit_add"
        and provider_attempt_events[index + 1] == "aimee_model_attempt_audit_add"
        for index in range(0, len(provider_attempt_events), 2)
    ),
    "attempt entry is appended immediately after each provider invocation",
)
for purpose in (
    "reply",
    "specialist_recovery",
    "temporal_repair",
    "search_grounding",
    "statement_voice_repair",
    "colleague_content_repair",
    "romantic_choice_repair",
    "synthetic_identity_repair",
    "profile_attribution_repair",
):
    check(f"'{purpose}'" in handler, f"attempt telemetry names {purpose} purpose")
check(
    "$intimacy_models" in handler
    and "(string) ($openrouter_result['model'] ?? '')" in handler
    and "$actual_model = $used_model" in handler
    and "$actual_model = $intimacy_models" not in handler,
    "configured specialist candidates cannot masquerade as actual_model",
)
check(
    "model_attempts_json" in outcome_helper
    and "$model_attempts" in outcome_helper
    and "$model_attempts" in handler[handler.rfind("aimee_relationship_decision_update_outcome(") :],
    "final relationship outcome persists the full per-turn attempt list",
)
check(
    "'model_attempts'  => $model_attempts" in handler
    and "'actual_model'    => $actual_model" in handler
    and "'actual_provider' => $actual_provider" in handler,
    "route analytics persist attempts and final actual route facts together",
)


# Downstream authorization, stripping protection and delivery truth.
check(
    "asynchronous_before_file_resolved" in materialization_dispatch
    and "aimee_authorised_media_delivery_materialization_result"
    in materialization_dispatch
    and "['status' => 'unavailable']" in materialization_dispatch,
    "materialization dispatch is asynchronous, provider-neutral and unavailable by default",
)
check(
    "aimee_media_materialization_is_owner_safe_direct_chat" in materialization_dispatch
    and "owner_safe_image_test" in MATERIALIZATION
    and "intval($trusted['user_id'] ?? 0) === 112" in MATERIALIZATION,
    "live generation remains exact-owner safe-direct chat only",
)
check(
    "START TRANSACTION" in materialization_complete
    and "FOR UPDATE" in materialization_complete
    and "aimee_media_delivery_bind_resolved_asset" in materialization_complete
    and materialization_complete.index("START TRANSACTION")
    < materialization_complete.index("aimee_media_delivery_bind_resolved_asset")
    and materialization_complete.index("FOR UPDATE")
    < materialization_complete.index("aimee_media_delivery_bind_resolved_asset")
    and "async_media_materialization_complete" in materialization_complete
    and "aimee_playful_jealousy_review_reply" in MATERIALIZATION
    and "aimee_synthetic_identity_review_reply" in MATERIALIZATION,
    "async completion atomically creates one guarded image message",
)
check(
    "START TRANSACTION" in materialization_fail
    and "FOR UPDATE" in materialization_fail
    and "async_media_materialization_failed" in materialization_fail
    and "image_url' => null" in materialization_fail,
    "async terminal failure atomically creates one honest text-only note",
)
check(
    "media_materialization_pending" in handler
    and "$media_delivery_id = '';" in handler
    and "$media_key = '';" in handler
    and "pending_media_delivery_snapshot" in handler
    and "aimee_media_materialization_neutral_pending_contract" in handler
    and "I’m creating that visual for you now" in MATERIALIZATION,
    "pending chat returns immediately without attaching its delivery to the interim message",
)
check(
    "'equity_change' => 0" in materialization_pending_contract
    and "'inquiry_change' => 0" in materialization_pending_contract
    and "'fantasy_change' => 0" in materialization_pending_contract
    and "'archive_current_context' => false" in materialization_pending_contract
    and "'memory_operation' => 'none'" in materialization_pending_contract
    and "'intimacy_invitation' => 'none'" in materialization_pending_contract
    and "$issued_invitation_token = $media_materialization_pending" in handler
    and "$memory_result = $media_materialization_pending" in handler
    and "if ($aimee_message_id && !$media_materialization_pending)" in handler,
    "pending draft cannot create hidden relationship, memory, opinion or metacognitive effects",
)
check(
    handler.count("aimee_media_materialization_neutral_pending_contract(") >= 2
    and "media_materialization_pending_deterministic" in handler
    and handler.index("media_materialization_pending_deterministic")
    > handler.index("post_jealousy_identity_review"),
    "deterministic pending copy is restored after every ordinary reply rewrite",
)
check(
    "pending_interim_message_insert_failed" in handler
    and "aimee_media_delivery_transition(\n                $pending_media_delivery_id"
    in handler,
    "failed interim persistence terminally invalidates the retained pending delivery",
)
check(
    "unset($public['job_id'])" in materialization_public_result
    and "aimee_media_materialization_public_result" in handler
    and "media_materialization_job_id" in handler,
    "internal sidecar job identity remains telemetry-only",
)
check(
    "$stored_media_reference" in handler
    and "$media_delivery_id !== ''\n        && $media_payload" in handler
    and "!$media_materialization_pending\n            && !$gallery_discussion_only\n            && aimee_turn_may_need_continuity"
    in handler,
    "pending acknowledgement and gallery discussion consume no image quota, cadence or continuity work",
)
check(
    "relationship" not in materialization_complete.lower()
    and "intimacy" not in materialization_complete.lower(),
    "async completion does not mutate relationship score or state",
)
for state in (
    "catalogue_resolved",
    "authorised",
    "message_created",
    "returned_by_direct_api",
):
    check(state in handler, f"main response path records {state} milestone")
check(
    "aimee_media_delivery_bind_resolved_asset" in handler,
    "main response path records file_resolved with immutable asset provenance",
)
check(
    "message_created_transition_failed" in handler
    and "SET message_text = %s, image_url = NULL" in handler,
    "failed message milestone strips attachment only with an honest user-visible repair",
)
check(
    "returned_by_direct_api" in handler
    and "direct_return_transition_failed" in handler,
    "direct API return is verified and failed return cannot remain a claimed attachment",
)
check(
    "media_delivery_id" in history
    and "media_key" in history
    and "message_id" in history
    and "authorised_at" in history
    and "file_resolved_at" in history
    and "message_created_at" in history,
    "history API requires exact delivery/message/key binding and authorization milestones",
)
check(
    history.index("aimee_private_media_payload") < history.index("returned_by_history_api"),
    "history resolves immutable bytes before recording an API return",
)
check(
    "aimee_media_item_is_viewable" in history,
    "history reuses the centralized current per-item entitlement predicate",
)
check(
    "media_key" in asset
    and "authorised_at" in asset
    and "file_resolved_at" in asset
    and "message_created_at" in asset
    and "asset_requested" in asset
    and "asset_completed" in asset,
    "protected asset route enforces exact binding and transfer milestones",
)
check(
    "transition_prerequisite_sql" in delivery_transition
    and "failed_at IS NULL" in delivery_transition,
    "delivery milestone prerequisites are atomic in the update query",
)
check(
    "render_failed is an observational side fact" in DELIVERY
    and "render_recovered" in DELIVERY,
    "client render failure remains recoverable instead of becoming terminal memory",
)
check(
    "viewing is not inferred" in memory_label
    and "does not prove personal viewing" in memory_label
    and "return and display are unverified" in memory_label,
    "Aimee memory distinguishes response, acknowledgement, return and message creation from viewing",
)
check(
    "response_message_id <= 0" in response_evidence
    and "saved_response" in response_evidence
    and "count((array) $candidates) !== 1" in response_evidence,
    "user-response evidence requires a saved message and rejects ambiguous recent images",
)


# Promise truth, proactive initiative and client confirmation.
check(
    "source_opportunity_grounded" in continuity_select
    and "source_promised_photo" in continuity_select
    and "continuity_promise_not_grounded" in continuity_select,
    "promised image follow-up must be grounded in its persisted source opportunity",
)
check(
    "delivery_pending" in continuity_process
    and "I won't pretend it reached you" in continuity_process
    and "I've decided not to attach" in continuity_process,
    "promise path either awaits verified delivery or honestly reports failure/choice",
)
check(
    "returned_by_history_api" in history
    and "status = 'completed'" in history
    and "status = 'delivery_pending'" in history,
    "photo promise completes only after history API returns the attachment",
)
check(
    'acknowledge(deliveryId,"rendered_by_client")' in UI
    and 'acknowledge(deliveryId,"acknowledged_by_client")' in UI
    and 'addEventListener("load",rendered' in UI,
    "client records render then viewport acknowledgement from actual image events",
)
check(
    "response.ok" in UI
    and "retryLimit" in UI
    and "confirmed[key]=true" in UI,
    "client acknowledges only successful HTTP responses and retries transient failures",
)


# Release 1.7.1 feedback must be quick, bounded and reviewable without turning
# the chat banner into a second free-form support or privacy intake channel.
check(
    bool(release_feedback_markup),
    "release feedback chat markup is implemented as a dedicated function",
)
check(
    "if (!is_user_logged_in()) return '';" in release_feedback_markup,
    "release feedback banner is available only to signed-in users",
)
check(
    "rest_url('aimee/v1/analytics')" in release_feedback_markup
    and "wp_create_nonce('wp_rest')" in release_feedback_markup
    and "X-WP-Nonce" in release_feedback_markup
    and "credentials:" in release_feedback_markup
    and "same-origin" in release_feedback_markup,
    "release feedback posts through the authenticated analytics REST route",
)
check(
    RELEASE_EVENT in release_feedback_markup
    and RELEASE_VERSION in release_feedback_markup
    and "surface" in release_feedback_markup
    and "$market === 'us' ? 'us' : 'uk'" in release_feedback_markup,
    "release banner fixes event, release, surface and canonical market metadata",
)
check(
    "feels_better" in release_feedback_markup
    and "needs_work" in release_feedback_markup,
    "release banner exposes both bounded feedback responses",
)
check(
    bool(release_feedback_markup)
    and re.search(
        r"<(?:textarea\b|input\b[^>]*\btype\s*=\s*['\"]text['\"]|[^>]+contenteditable)",
        release_feedback_markup,
        flags=re.IGNORECASE,
    )
    is None,
    "release banner cannot collect free-text feedback",
)
check(
    "localStorage" in release_feedback_markup
    and f"aimeeReleaseFeedbackChatResolved:{RELEASE_VERSION}:" in release_feedback_markup
    and "market" in release_feedback_markup,
    "release feedback persistence is versioned and market scoped",
)
check(
    f"Aimee {RELEASE_VERSION} is now live" in release_feedback_markup
    and f"Aimee {RELEASE_VERSION} release feedback" in release_feedback_markup
    and f"Dismiss Aimee {RELEASE_VERSION} update" in release_feedback_markup,
    "release banner visibly identifies the deployed 1.7.1 release",
)
check(
    "1.6.1" not in release_feedback_markup
    and "aimee_161_feedback" not in release_feedback_markup,
    "active release banner contains no stale 1.6.1 cohort identifiers",
)
check(
    re.search(
        r"dismiss.*?addEventListener\s*\(\s*['\"]click['\"].*?"
        r"(?:remember|persist|store|save)[A-Za-z0-9_]*\s*\(",
        release_feedback_markup,
        flags=re.IGNORECASE | re.DOTALL,
    )
    is not None,
    "release banner persists dismissal only from an explicit dismiss action",
)
check(
    re.search(
        r"if\s*\(\s*!\s*[A-Za-z_$][A-Za-z0-9_$]*\.ok\b.*?throw.*?"
        r"(?:remember|persist|store|save)[A-Za-z0-9_]*\s*\(",
        release_feedback_markup,
        flags=re.IGNORECASE | re.DOTALL,
    )
    is not None,
    "release response is persisted only after a successful HTTP response",
)
check(
    "aimee-public-statement-chat" in release_feedback_markup
    and "aimee-billing-migration-card" in release_feedback_markup,
    "release feedback mount recognises both existing chat-notice collision targets",
)
check(
    "aimee-release-feedback-chat" in press_release_markup,
    "public-statement notice yields while release feedback is visible",
)
check(
    "aimee-release-feedback-chat" in billing_migration_markup
    and "if (!state.grace)" in billing_migration_markup
    and "aimee-billing-reconciliation-card" in release_feedback_markup
    and "aimee-service-grace-card" not in billing_notice_active_js,
    "grace keeps release feedback while post-cutoff and reconciliation notices take priority",
)
release_injection = UI.rfind(
    "aimee_global_chat_release_feedback_markup($market)"
)
press_injection = UI.rfind(
    "aimee_global_chat_press_release_markup($market)"
)
check(
    release_injection >= 0
    and press_injection >= 0
    and release_injection < press_injection,
    "release feedback markup is injected before the public-statement notice",
)

feedback_event_start = analytics_handler.find(RELEASE_EVENT)
feedback_properties_end = analytics_handler.find("$properties_json", feedback_event_start)
feedback_analytics_branch = (
    analytics_handler[feedback_event_start:feedback_properties_end]
    if feedback_event_start >= 0 and feedback_properties_end > feedback_event_start
    else ""
)
check(
    feedback_event_start >= 0
    and "feels_better" in feedback_analytics_branch
    and "needs_work" in feedback_analytics_branch
    and "in_array" in feedback_analytics_branch,
    "analytics handler allowlists the two release feedback responses",
)
feedback_property_keys = re.findall(
    r"['\"](release|response|market|surface)['\"]\s*=>",
    feedback_analytics_branch,
)
check(
    set(feedback_property_keys) == {"release", "response", "market", "surface"}
    and len(feedback_property_keys) == 4
    and re.search(
        rf"['\"]release['\"]\s*=>\s*['\"]{re.escape(RELEASE_VERSION)}['\"]",
        feedback_analytics_branch,
    )
    is not None
    and re.search(
        r"['\"]surface['\"]\s*=>\s*['\"][a-z0-9_-]+['\"]",
        feedback_analytics_branch,
    )
    is not None
    and "array_merge" not in feedback_analytics_branch,
    "analytics handler replaces client properties with four bounded feedback fields",
)
check(
    bool(feedback_analytics_branch)
    and "aimee_161_feedback" not in feedback_analytics_branch
    and re.search(r"['\"]release['\"]\s*=>\s*['\"]1\.6\.1['\"]", feedback_analytics_branch)
    is None,
    "active analytics feedback branch contains no stale 1.6.1 cohort identifiers",
)
legacy_feedback_start = analytics_handler.find("$is_legacy_release_feedback")
legacy_feedback_end = analytics_handler.find(
    "$is_release_feedback", legacy_feedback_start
)
legacy_feedback_branch = (
    analytics_handler[legacy_feedback_start:legacy_feedback_end]
    if legacy_feedback_start >= 0 and legacy_feedback_end > legacy_feedback_start
    else ""
)
legacy_feedback_property_keys = re.findall(
    r"['\"](release|response|market|surface)['\"]\s*=>",
    legacy_feedback_branch,
)
check(
    "aimee_161_feedback" in legacy_feedback_branch
    and "feels_better" in legacy_feedback_branch
    and "needs_work" in legacy_feedback_branch
    and "['uk', 'us']" in legacy_feedback_branch
    and set(legacy_feedback_property_keys)
    == {"release", "response", "market", "surface"}
    and len(legacy_feedback_property_keys) == 4
    and re.search(
        r"['\"]release['\"]\s*=>\s*['\"]1\.6\.1['\"]",
        legacy_feedback_branch,
    )
    is not None,
    "legacy 1.6.1 feedback remains response-, market- and property-bounded server-side",
)
check(
    "$is_bounded_release_feedback = $is_legacy_release_feedback || $is_release_feedback;"
    in analytics_handler
    and "if (!$is_bounded_release_feedback && !empty($params['occurred_at']))"
    in analytics_handler
    and "$is_bounded_release_feedback ? '' : sanitize_text_field"
    in analytics_handler,
    "both legacy and active release feedback discard client timestamps and page paths",
)
analytics_insert = re.search(
    r"\$([A-Za-z0-9_]+)\s*=\s*\$wpdb->insert\s*\(", analytics_handler
)
check(
    analytics_insert is not None
    and re.search(
        rf"\${re.escape(analytics_insert.group(1))}\s*===\s*false",
        analytics_handler[analytics_insert.end() :],
    )
    is not None,
    "analytics REST success is returned only after a successful database insert",
)

check(
    bool(release_feedback_summary),
    "admin release-feedback summary is implemented as a dedicated function",
)
check(
    RELEASE_EVENT in release_feedback_summary
    and re.search(r"MAX\s*\(\s*id\s*\)", release_feedback_summary, re.IGNORECASE)
    is not None
    and re.search(r"GROUP\s+BY\s+user_id", release_feedback_summary, re.IGNORECASE)
    is not None,
    "release feedback summary deduplicates to each user's latest response",
)
check(
    "feels_better" in release_feedback_summary
    and "needs_work" in release_feedback_summary
    and "total" in release_feedback_summary,
    "release feedback summary reports both response aggregates and a total",
)
check(
    re.search(
        rf"function\s+aimee_global_release_feedback_summary\s*\(\s*\$release\s*=\s*['\"]{re.escape(RELEASE_VERSION)}['\"]\s*\)",
        release_feedback_summary,
    )
    is not None
    and "aimee_161_feedback" not in release_feedback_summary
    and "1.6.1" not in release_feedback_summary,
    "admin summary defaults exclusively to the 1.7.1 feedback cohort",
)
summary_query_pos = release_feedback_summary.find("$wpdb->get_results")
summary_zero_state = release_feedback_summary[:summary_query_pos]
check(
    summary_query_pos > 0
    and re.search(r"['\"]total['\"]\s*=>\s*0", summary_zero_state)
    and re.search(r"['\"]feels_better['\"]\s*=>\s*0", summary_zero_state)
    and re.search(r"['\"]needs_work['\"]\s*=>\s*0", summary_zero_state)
    and re.search(r"['\"]latest_at['\"]\s*=>\s*['\"]['\"]", summary_zero_state),
    "admin feedback summary establishes a complete zero state before querying storage",
)
check(
    "if (!function_exists('aimee_table')) return $summary;" in release_feedback_summary
    and "if (!is_array($rows)) return $summary;" in release_feedback_summary,
    "missing analytics helpers or a failed table query preserve the visible zero state",
)
check(
    "aimee_global_release_feedback_summary" in admin_page
    and "feels_better" in admin_page
    and "needs_work" in admin_page,
    "Aimee Global admin page displays the aggregate release feedback summary",
)
feedback_card_start = admin_page.find(
    f'<div class="card"><h2>Aimee {RELEASE_VERSION} feedback</h2>'
)
feedback_card_end = admin_page.find('<div class="card">', feedback_card_start + 1)
feedback_card = (
    admin_page[feedback_card_start:feedback_card_end]
    if feedback_card_start >= 0 and feedback_card_end > feedback_card_start
    else ""
)
check(
    feedback_card_start >= 0
    and "release_feedback['feels_better']" in feedback_card
    and "release_feedback['needs_work']" in feedback_card
    and "release_feedback['total']" in feedback_card,
    "1.7.1 aggregate feedback card always renders all three counters",
)
check(
    not re.search(
        r"if\s*\([^)]*release_feedback\s*\[\s*['\"](?:total|feels_better|needs_work)['\"]",
        feedback_card,
    ),
    "aggregate counter rendering does not depend on submitted feedback",
)
check(
    "Aimee 1.6.1 feedback" not in admin_page
    and "aimee_global_release_feedback_summary('1.6.1')" not in admin_page,
    "active admin page contains no stale 1.6.1 feedback heading or query",
)
check(
    "aimee_161_feedback" not in release_feedback_markup
    and "aimee_161_feedback" not in release_feedback_summary
    and "Aimee 1.6.1 feedback" not in admin_page,
    "legacy 1.6.1 feedback never appears in the active banner or admin cohort",
)


# Access cancellation must not mutate relationship state.
check(
    "aimee_relationship_states" not in cancel_handler
    and "intimacy_score" not in cancel_handler
    and "intimacy_stage" not in cancel_handler,
    "subscription cancellation handler does not erase or rewrite relationship state",
)
check(
    "aimee_sync_subscription_from_stripe" in cancel_handler,
    "cancellation changes billing access through subscription synchronization only",
)


# Full account erasure must cover every decision/lifecycle table introduced by
# the intimacy/media audit. Child delivery events are deliberately deleted
# before deliveries, and deliveries before their decision envelope.
for privacy_table in (
    "aimee_relationship_decisions",
    "aimee_relationship_invitations",
    "aimee_turn_requests",
    "aimee_media_decisions",
    "aimee_media_deliveries",
    "aimee_media_delivery_events",
):
    check(
        f"aimee_table('{privacy_table}')" in delete_account_handler,
        f"account deletion erases {privacy_table}",
    )
check(
    delete_account_handler.index("aimee_media_delivery_events")
    < delete_account_handler.index("aimee_media_deliveries")
    < delete_account_handler.index("aimee_media_decisions"),
    "account deletion removes media events before deliveries before decisions",
)
check(
    "$wpdb->delete($table, ['user_id' => $current_user_id]" in delete_account_handler,
    "account-deletion lifecycle erasure remains scoped to the authenticated user",
)
check(
    "aimee_live_image_beta_jobs" in materialization_erasure
    and "aimee_global_cleanup_live_image_beta_user_data($current_user_id)"
    in delete_account_handler
    and delete_account_handler.index(
        "aimee_global_cleanup_live_image_beta_user_data($current_user_id)"
    )
    < delete_account_handler.index("$tables = ["),
    "account deletion invokes Global's persistent live-image backstop before core row erasure",
)
check(
    "START TRANSACTION" in materialization_erasure
    and "FOR UPDATE" in materialization_erasure
    and "status, lease_expires_at" in materialization_erasure
    and "$lease_timestamp >= $now_timestamp" in materialization_erasure
    and materialization_erasure.index("FOR UPDATE")
    < materialization_erasure.rindex(
        "aimee_global_live_image_beta_tombstone_jobs("
    )
    < materialization_erasure.index("@unlink("),
    "live-image erasure locks every future-lease barrier before tombstone and contained unlink",
)
check(
    "'lease_expires_at'," not in materialization_erasure_tombstone.split(
        "$clear_to_null = [", 1
    )[1].split("]", 1)[0]
    and "account_delete_worker_drain" in materialization_erasure
    and "account_delete_engine_invalid" in materialization_erasure,
    "worker expiry evidence survives every transactional or legacy-engine tombstone",
)
check(
    "SHOW TABLE STATUS WHERE Name = %s" in materialization_erasure
    and "$engine !== 'innodb'" in materialization_erasure
    and materialization_erasure.index("$engine !== 'innodb'")
    < materialization_erasure.index("START TRANSACTION")
    and "engine_unavailable" in materialization_erasure,
    "physical erasure requires exact known-table InnoDB readiness before row locks",
)
check(
    "wp_schedule_single_event" in materialization_erasure_retry
    and "is_wp_error($scheduled)" in materialization_erasure_retry
    and "$maximum_retry = $now + 900" in materialization_erasure_retry
    and "aimee_global_retry_live_image_beta_cleanup" in MATERIALIZATION,
    "provider-independent cleanup retry is durable, bounded and rejects WordPress errors",
)
check(
    "aimee_global_schedule_live_image_beta_cleanup_retry" in materialization_erasure_incomplete
    and "aimee_global_record_live_image_beta_cleanup_issue" in materialization_erasure_incomplete
    and "cleanup_retry_schedule_failed" in materialization_erasure_incomplete
    and "admin_notices" in materialization_erasure
    and "affected files have not been declared erased" in materialization_erasure_notice,
    "every incomplete cleanup retains retry state and an operator-visible bounded reason",
)
check(
    "/^[a-f0-9]{64}$/D" in materialization_erasure
    and "aimee_global_live_image_beta_cleanup_output_dir" in materialization_erasure
    and "!file_exists($candidate)" in materialization_erasure
    and "'status' => 'deleting'" in materialization_erasure,
    "erasure deletes rows only after a strict contained token entry is absent",
)
check(
    "'private_file_token' => $token_value" in materialization_erasure
    and "['%d', '%d', '%s', '%s']" in materialization_erasure,
    "erasure row deletion compare-and-swaps the exact token provenance it inspected",
)

print(f"RESULT {passes} passed, {failures} failed")
sys.exit(1 if failures else 0)
