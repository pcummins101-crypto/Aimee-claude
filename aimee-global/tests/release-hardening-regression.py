#!/usr/bin/env python3
"""Focused static regression for the final Aimee Global 1.8.4 hardening.

The provider mocks cover individual API transitions.  This suite protects the
cross-cutting fail-closed contracts that are otherwise easy to weaken during a
refactor: runtime prerequisites, deployment-owned privileged identities,
private catalogue migration, the one shared billing mutex, legacy payment
intent reconciliation, and complete remote-authority retirement before account
erasure.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
BOOTSTRAP = (ROOT / "aimee-global.php").read_text(encoding="utf-8")
ENGINE = (ROOT / "includes/engine.php").read_text(encoding="utf-8")
GOCARDLESS = (ROOT / "includes/gocardless.php").read_text(encoding="utf-8")

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


def function_body(source: str, name: str) -> str:
    """Return a named PHP function body using balanced braces and strings."""

    match = re.search(rf"(?m)^function\s+{re.escape(name)}\s*\(", source)
    if not match:
        return ""
    brace = source.find("{", match.end())
    if brace < 0:
        return ""
    depth = 0
    quote = ""
    comment = ""
    escaped = False
    offset = brace
    while offset < len(source):
        char = source[offset]
        following = source[offset + 1] if offset + 1 < len(source) else ""
        if comment == "line":
            if char in "\r\n":
                comment = ""
            offset += 1
            continue
        if comment == "block":
            if char == "*" and following == "/":
                comment = ""
                offset += 2
            else:
                offset += 1
            continue
        if quote:
            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == quote:
                quote = ""
            offset += 1
            continue
        if char == "/" and following == "/":
            comment = "line"
            offset += 2
            continue
        if char == "/" and following == "*":
            comment = "block"
            offset += 2
            continue
        if char == "#":
            comment = "line"
            offset += 1
            continue
        if char in "'\"":
            quote = char
        elif char == "{":
            depth += 1
        elif char == "}":
            depth -= 1
            if depth == 0:
                return source[brace + 1 : offset]
        offset += 1
    return ""


runtime_missing = function_body(
    BOOTSTRAP, "aimee_global_runtime_requirements_missing"
)
runtime_ready = function_body(
    BOOTSTRAP, "aimee_global_runtime_requirements_ready"
)
configured_identity = function_body(ENGINE, "aimee_configured_identity_user_id")
admin_role = function_body(ENGINE, "aimee_admin_role")

private_dir = function_body(ENGINE, "aimee_private_media_dir")
catalog_normalizer = function_body(ENGINE, "aimee_normalize_private_media_item")
catalog_ready = function_body(
    ENGINE, "aimee_private_media_catalog_configuration_ready"
)
known_public_sources = function_body(
    ENGINE, "aimee_private_media_known_legacy_public_sources"
)
file_matches_catalog = function_body(
    ENGINE, "aimee_private_media_file_matches_item"
)
seed_catalog = function_body(ENGINE, "aimee_seed_private_media_library")
source_paths = function_body(ENGINE, "aimee_private_media_source_paths")
migrate_item = function_body(ENGINE, "aimee_private_media_migrate_item")
catalog_health = function_body(
    ENGINE, "aimee_private_media_library_is_private"
)
attachment_health = function_body(
    ENGINE, "aimee_private_media_health_attachment_candidates"
)
legacy_scan = function_body(ENGINE, "aimee_private_media_scan_directory")
voice_legacy_specs = function_body(
    ENGINE, "aimee_voice_note_legacy_directory_specs"
)
voice_ownership = function_body(
    ENGINE, "aimee_voice_note_referenced_filenames"
)
voice_migration = function_body(
    ENGINE, "aimee_voice_note_migrate_legacy_storage"
)
voice_upload_store = function_body(
    ENGINE, "aimee_voice_note_atomic_store_upload"
)
voice_tts_store = function_body(
    ENGINE, "aimee_voice_note_atomic_store_bytes"
)
voice_delete = function_body(ENGINE, "aimee_voice_note_delete_user_files")
voice_delete_family = function_body(
    ENGINE, "aimee_voice_note_delete_filename_family_from_directory"
)
voice_worker = function_body(ENGINE, "aimee_process_voice_note_job")
voice_worker_locked = function_body(
    ENGINE, "aimee_process_voice_note_job_locked"
)

acquire_lock = function_body(ENGINE, "aimee_acquire_subscription_checkout_lock")
refresh_lock = function_body(ENGINE, "aimee_refresh_subscription_checkout_lock")
lock_state = function_body(ENGINE, "aimee_subscription_checkout_lock_state")
release_lock = function_body(ENGINE, "aimee_release_subscription_checkout_lock")
mark_deletion = function_body(ENGINE, "aimee_mark_account_deletion_started")
clear_deletion = function_body(ENGINE, "aimee_clear_account_deletion_tombstone")
settings_update = function_body(ENGINE, "handle_aimee_settings_update")
sms_checkout = function_body(ENGINE, "handle_aimee_sms_bundle_checkout")
gc_payment = function_body(
    GOCARDLESS, "aimee_gocardless_create_payment_for_user"
)
delete_account = function_body(ENGINE, "aimee_api_delete_account")
gocardless_retire_deletion = function_body(
    GOCARDLESS,
    "aimee_gocardless_retire_user_billing_for_deletion",
)
gocardless_profile_update = function_body(
    GOCARDLESS,
    "aimee_gocardless_profile_update_verified",
)
membership_checkout = function_body(ENGINE, "handle_aimee_subscription_checkout")
checkout_market_supported = function_body(
    ENGINE, "aimee_new_membership_checkout_market_supported"
)
retire_stripe_before_bank = function_body(
    ENGINE, "aimee_membership_retire_stripe_before_bank_checkout"
)
gocardless_checkout = function_body(GOCARDLESS, "aimee_gocardless_checkout")
stripe_reconcile = function_body(ENGINE, "aimee_stripe_reconcile_checkout_intent")
stripe_status = function_body(ENGINE, "handle_aimee_subscription_status")
stripe_sync = function_body(ENGINE, "aimee_sync_subscription_from_stripe")
stripe_webhook = function_body(ENGINE, "handle_aimee_stripe_webhook")
stripe_expired = function_body(
    ENGINE,
    "aimee_stripe_retire_expired_checkout_session",
)
stripe_cancel = function_body(ENGINE, "handle_aimee_subscription_cancel")
stripe_portal = function_body(ENGINE, "handle_aimee_billing_portal")

sms_pending_rows = function_body(ENGINE, "aimee_sms_bundle_pending_rows")
sms_terms = function_body(ENGINE, "aimee_sms_bundle_purchase_intent_terms")
sms_idempotency = function_body(ENGINE, "aimee_sms_bundle_idempotency_key")
sms_body = function_body(ENGINE, "aimee_sms_bundle_checkout_body")
sms_matches = function_body(
    ENGINE, "aimee_sms_bundle_session_matches_purchase"
)
sms_resolve = function_body(
    ENGINE, "aimee_sms_bundle_resolve_pending_session"
)
sms_fulfil = function_body(ENGINE, "aimee_fulfill_sms_bundle_session")
sms_status = function_body(ENGINE, "handle_aimee_sms_bundle_status")
sms_retire = function_body(
    ENGINE, "aimee_sms_bundle_retire_pending_sessions_for_user"
)


# Runtime prerequisites fail closed before any feature module is loaded.
check(
    "extension_loaded('mbstring')" in runtime_missing
    and "class_exists('finfo')" in runtime_missing
    and "getimagesizefromstring" in runtime_missing,
    "runtime gate requires mbstring, fileinfo and image-byte inspection",
)
check(
    "aimee_global_runtime_requirements_missing() === []" in runtime_ready,
    "runtime readiness is true only when every prerequisite is present",
)
runtime_gate = BOOTSTRAP.find("if (!aimee_global_runtime_requirements_ready())")
first_module = BOOTSTRAP.find("require_once AIMEE_GLOBAL_DIR")
check(
    runtime_gate >= 0
    and first_module > runtime_gate
    and "return;" in BOOTSTRAP[runtime_gate:first_module],
    "incomplete runtimes return before loading routes, workers or feature code",
)


# Georgia is a deployment binding, never a privilege-bearing package default.
production_php = [ROOT / "aimee-global.php"]
production_php.extend((ROOT / "includes").glob("*.php"))
production_php.extend((ROOT / "templates").rglob("*.php"))
portable_georgia_define = re.compile(
    r"define\s*\(\s*['\"]AIMEE_GEORGIA_USER_ID['\"]", re.I
)
check(
    not any(
        portable_georgia_define.search(path.read_text(encoding="utf-8"))
        for path in production_php
    ),
    "portable PHP contains no default Georgia user ID",
)
check(
    "if (!defined($constant_name)) return 0;" in configured_identity
    and "AIMEE_GEORGIA_USER_ID" in admin_role
    and "$colleague_id > 0" in admin_role,
    "colleague privilege requires an explicit positive configured identity",
)


# Private storage remains the default; the separately tested operator-approved
# public mode may omit legacy hashes but cannot weaken supplied-hash validation.
check(
    "aimee_private_storage_prepare_dir('private-catalogue'" in private_dir
    and "WP_CONTENT_DIR" not in private_dir,
    "catalogue assets use the shared non-public storage policy",
)
check(
    "'sha256'" in catalog_normalizer
    and "$sha256 === ''" in catalog_normalizer
    and "aimee_public_media_catalogue_mode_enabled()" in catalog_normalizer
    and "preg_match('/^[a-f0-9]{64}$/'" in catalog_normalizer,
    "missing catalogue hashes are public-mode-only and supplied hashes remain exact",
)
check(
    "aimee_private_media_public_catalogue_status" in catalog_ready
    and "aimee_private_media_required_keys()" in catalog_ready
    and "empty($catalog[$key]['sha256'])" in catalog_ready,
    "catalogue readiness separates public byte health from private hash requirements",
)
check(
    "$expected_sha256" in file_matches_catalog
    and "$hash_matches" in file_matches_catalog
    and "hash_file('sha256', $path)" in file_matches_catalog
    and "aimee_public_media_catalogue_mode_enabled() && !$require_private"
    in file_matches_catalog
    and "aimee_profile_media_permissions_are_private" in file_matches_catalog,
    "catalogue byte checks allow hashless public files only outside private mode",
)
check(
    "wp_delete_file" not in known_public_sources
    and "unlink(" not in known_public_sources,
    "legacy public-file discovery is non-destructive",
)
no_assets_branch = seed_catalog.find(
    "if (!aimee_private_media_catalog_configuration_ready())"
)
migration_loop = seed_catalog.find("foreach (aimee_private_media_catalog() as $item)")
check(
    no_assets_branch >= 0
    and "aimee_private_media_known_legacy_public_sources()" in seed_catalog[
        no_assets_branch:migration_loop
    ]
    and "return false" in seed_catalog[no_assets_branch:migration_loop]
    and "'mode' => 'disabled_no_assets'" in seed_catalog[no_assets_branch:migration_loop]
    and migration_loop > no_assets_branch,
    "no-manifest installs disable an empty catalogue but block on legacy public bytes",
)
catalogue_complete_branch = seed_catalog.find(
    "aimee_private_media_migration_is_complete('catalogue')"
)
check(
    catalogue_complete_branch >= 0
    and catalogue_complete_branch < migration_loop
    and "aimee_private_media_library_is_private()" in seed_catalog[
        catalogue_complete_branch:migration_loop
    ]
    and "return $healthy" in seed_catalog[catalogue_complete_branch:migration_loop]
    and "'mode' => 'catalogue'" in seed_catalog[migration_loop:],
    "completed catalogue migration becomes health-only and records its mode",
)
check(
    "source_relative" in source_paths
    and "aimee_private_media_health_candidate" in source_paths
    and "glob(" not in source_paths
    and "$wpdb" not in source_paths,
    "migration consumes only the manifest's exact upload-relative source",
)
check(
    migrate_item.find("aimee_private_media_file_matches_item($destination")
    < migrate_item.find("aimee_private_media_delete_public_source_family")
    and "$remaining = aimee_private_media_source_paths($item)" in migrate_item
    and "!is_wp_error($remaining)" in migrate_item,
    "public catalogue bytes are deleted only after a verified private copy",
)
check(
    "disabled_no_assets" in catalog_health
    and "catalogue" in catalog_health
    and "aimee_private_media_known_legacy_public_sources" in catalog_health,
    "catalogue health recognizes both explicit modes and rejects public remnants",
)
check(
    "@scandir" in legacy_scan
    and "glob(" not in legacy_scan
    and "is_link($directory)" in legacy_scan
    and "WP_Error" in legacy_scan,
    "legacy catalogue scans fail closed on I/O errors and symbolic links",
)
check(
    "_wp_attached_file" in attachment_health
    and "$wpdb->postmeta" in attachment_health
    and "legacy_attachment_path_invalid" in attachment_health
    and "$wpdb" not in source_paths,
    "custom-depth legacy attachment metadata is health-only and never a deletion authority",
)
check(
    "'shared' => true" in voice_legacy_specs
    and "'shared' => false" in voice_legacy_specs
    and "SELECT input_file, reply_file" in voice_ownership
    and "voice_storage_reference_invalid" in voice_ownership
    and "isset($owned[$filename])" in voice_migration
    and "empty($spec['shared'])" in voice_migration,
    "voice migration moves only current-site row references and blocks unmatched site-public files",
)
check(
    all(
        marker in voice_upload_store and marker in voice_tts_store
        for marker in (".tmp-", "@chmod($temporary, 0600)", "@rename", "finally")
    )
    and "aimee_voice_note_lock_allows_private_write" in voice_upload_store
    and "aimee_voice_note_lock_allows_private_write" in voice_tts_store,
    "voice upload and TTS writes are verified private atomic commits under the deletion mutex",
)
check(
    "aimee_voice_note_referenced_filenames($user_id)" in voice_delete
    and "aimee_voice_note_delete_filename_family_from_directory" in voice_delete
    and "aimee_voice_note_storage_temp_filename_matches" in voice_delete_family
    and "aimee_voice_note_scan_directory" in voice_delete_family,
    "voice erasure deletes exact owner-row files and their crash-temporary families fail closed",
)
check(
    "aimee_acquire_subscription_checkout_lock" in voice_worker
    and "aimee_voice_note_lock_allows_private_write" in voice_worker
    and "aimee_refresh_subscription_checkout_lock" in voice_worker_locked
    and "aimee_voice_note_atomic_store_bytes" in voice_worker_locked,
    "voice workers cannot commit files after an account-deletion tombstone",
)


# One owner-token lease serializes every operation that can change billing rail
# or create/retire a charge authority.
check(
    "billing_checkout_lock_until IS NULL OR billing_checkout_lock_until < %s"
    in acquire_lock
    and "billing_checkout_lock_token = %s" in acquire_lock,
    "shared lock acquisition is an atomic expiring owner-token claim",
)
check(
    "account_deletion_started_at IS NULL" in acquire_lock
    and "account_deletion_started_at = %s" in mark_deletion
    and "billing_checkout_lock_token = %s" in mark_deletion
    and "account_deletion_started_at = NULL" in clear_deletion
    and "billing_checkout_lock_token = %s" in clear_deletion,
    "account deletion tombstone blocks successor billing and is exact-lock-owner clearable",
)
check(
    "billing_checkout_lock_token = %s" in refresh_lock
    and "billing_checkout_lock_until >= %s" in refresh_lock
    and "aimee_subscription_checkout_lock_is_current" in refresh_lock
    and "billing_checkout_lock_token = %s" in lock_state
    and "current_until >= time()" in lock_state,
    "lock refresh is owner-bound and read-after-write verified",
)
check(
    "billing_checkout_lock_token' => $lock_token" in release_lock,
    "lock release cannot clear a successor's lease",
)
check(
    "aimee_acquire_subscription_checkout_lock($user_id)" in gc_payment
    and "hash_equals($payment_lock_token" in gc_payment
    and "finally" in gc_payment
    and "aimee_gocardless_release_checkout_lock_verified($user_id, $payment_lock_token)"
    in gc_payment,
    "GoCardless initial and renewal payments hold the shared billing mutex",
)
check(
    "aimee_acquire_subscription_checkout_lock($user_id)" in settings_update
    and "hash_equals(" in settings_update
    and "$settings_lock_token" in settings_update
    and "aimee_membership_profile_has_unsettled_billing_authority" in settings_update
    and "finally" in settings_update
    and "aimee_release_subscription_checkout_lock($user_id, $settings_lock_token)"
    in settings_update,
    "market changes re-read and validate billing authority under the shared mutex",
)
check(
    sms_checkout.find("sms_bundle_checkout_unavailable")
        < sms_checkout.find("aimee_acquire_subscription_checkout_lock($user_id)")
    and "'checkout_available' => false" in sms_checkout
    and "], 410)" in sms_checkout,
    "new SMS checkout is disabled before the frozen shared-mutex creation path",
)
check(
    "aimee_acquire_subscription_checkout_lock(" in delete_account
    and "15 * MINUTE_IN_SECONDS" in delete_account
    and "hash_equals(" in delete_account
    and "aimee_refresh_subscription_checkout_lock" in delete_account
    and "finally" in delete_account
    and "aimee_release_subscription_checkout_lock(" in delete_account,
    "account deletion holds and refreshes a long shared billing lease",
)


# Pre-cutover SMS checkout state remains recoverable after response loss: its
# durable intent owns deterministic reconciliation and every later transition
# revalidates owner, immutable terms and billing generation.  The public create
# route itself is covered above as disabled.
check(
    "status = 'checkout_pending'" in sms_pending_rows
    and "ORDER BY id ASC" in sms_pending_rows
    and "$wpdb->last_error" in sms_pending_rows,
    "legacy pending SMS intents are enumerated with database errors fail closed",
)
check(
    all(
        token in sms_idempotency
        for token in (
            "$row->id",
            "$terms['billing_generation']",
            "$terms['market']",
            "$terms['currency']",
            "$terms['product_label']",
            "hash('sha256'",
        )
    )
    and "aimee_global_current_billing_generation" not in sms_idempotency
    and "time(" not in sms_idempotency
    and "wp_generate" not in sms_idempotency,
    "legacy SMS reconciliation idempotency is deterministic from durable terms",
)
check(
    all(
        token in sms_body
        for token in (
            "client_reference_id",
            "metadata[aimee_user_id]",
            "metadata[aimee_purchase_type]",
            "metadata[aimee_sms_quantity]",
            "metadata[aimee_market]",
            "metadata[aimee_currency]",
            "metadata[aimee_product_label]",
            "metadata[aimee_billing_generation]",
            "payment_intent_data[metadata][aimee_sms_quantity]",
            "payment_intent_data[metadata][aimee_market]",
            "payment_intent_data[metadata][aimee_currency]",
            "payment_intent_data[metadata][aimee_product_label]",
            "payment_intent_data[metadata][aimee_billing_generation]",
        )
    ),
    "legacy SMS provider records retain every immutable owner and purchase term",
)
check(
    all(
        token in sms_matches
        for token in (
            "aimee_stripe_checkout_session_matches_owner",
            "'payment'",
            "'sms_bundle'",
            "aimee_sms_quantity",
            "amount_total",
            "aimee_billing_generation",
            "aimee_market",
            "aimee_currency",
            "currency']",
            "aimee_product_label",
            "aimee_sms_bundle_purchase_intent_terms",
        )
    )
    and "aimee_global_current_billing_generation" not in sms_matches,
    "legacy SMS session validation binds owner, purpose and immutable terms",
)
check(
    all(
        token in sms_terms
        for token in (
            "billing_generation", "market", "currency", "product_label",
            "$quantity < 1", "$amount < 1",
        )
    )
    and "return []" in sms_terms,
    "pre-cutover SMS rows with blank immutable terms fail closed",
)
check(
    "aimee_sms_bundle_pending_placeholder" in sms_resolve
    and "legacy_stripe_sms_manual_reconciliation_required" in sms_resolve
    and "no new card checkout was created" in sms_resolve
    and "'POST'" not in sms_resolve
    and "'GET'" in sms_resolve
    and "aimee_sms_bundle_session_matches_purchase" in sms_resolve
    and "checkout/sessions/" in sms_resolve,
    "ambiguous SMS placeholders require manual reconciliation and bound sessions are read-only",
)
check(
    "FOR UPDATE" in sms_fulfil
    and "sms_bundle_purchase_untracked" in sms_fulfil
    and "aimee_sms_bundle_session_matches_purchase($session, $existing)" in sms_fulfil
    and "sms_bundle_generation_mismatch" in sms_fulfil
    and "account_deletion_started_at IS NULL" in sms_fulfil
    and "status' => 'completed'" in sms_fulfil
    and "COMMIT" in sms_fulfil,
    "pre-cutover SMS fulfilment locks the owner row and credits exactly once",
)
check(
    "WHERE user_id = %d AND stripe_checkout_session_id = %s" in sms_status
    and "aimee_sms_bundle_session_matches_purchase" in sms_status
    and "aimee_fulfill_sms_bundle_session" in sms_status,
    "legacy SMS status polling cannot claim an unowned or mismatched session",
)


# New membership creation is GoCardless-only.  The Stripe helpers below remain
# exact and owner-bound solely to reconcile and retire pre-cutover state.
checkout_dispatch = membership_checkout.find(
    "if (aimee_new_membership_checkout_market_supported($checkout_market))"
)
checkout_gc_return = membership_checkout.find("return aimee_gocardless_checkout($request)")
checkout_market_block = membership_checkout.find(
    "'status' => 'bank_checkout_market_unavailable'"
)
checkout_legacy_branch = membership_checkout.find("global $wpdb")
check(
    "sanitize_key((string) $market) === 'uk'" in checkout_market_supported
    and 0 <= checkout_dispatch < checkout_gc_return < checkout_market_block < checkout_legacy_branch
    and "aimee_gocardless_ready()" in membership_checkout[checkout_dispatch:checkout_gc_return]
    and "'checkout_available' => false" in membership_checkout[checkout_market_block:checkout_legacy_branch]
    and "No payment was created" in membership_checkout[checkout_market_block:checkout_legacy_branch],
    "new membership checkout is UK GoCardless-only and fails closed elsewhere",
)
check(
    re.search(
        r"aimee_stripe_request\s*\(\s*['\"]POST['\"]\s*,\s*"
        r"['\"]checkout/sessions['\"]\s*,",
        ENGINE,
        re.MULTILINE,
    ) is None,
    "engine contains no direct Stripe Checkout-session creation call",
)
check(
    "aimee_refresh_subscription_checkout_lock($user_id, $lock_token)" in stripe_reconcile
    and "stripe_checkout_creation_disabled" in stripe_reconcile
    and "legacy_stripe_checkout_manual_reconciliation_required" in stripe_reconcile
    and "['requesting', 'request_unknown']" in stripe_reconcile
    and "'POST'" not in stripe_reconcile,
    "pre-cutover Stripe intents are retired or held for manual reconciliation without replay",
)
check(
    "aimee_acquire_subscription_checkout_lock($user_id)" in stripe_status
    and "$requested_exact_stored_stripe_session" in stripe_status
    and "hash_equals($stored_routing_stripe_session, $requested_session_id)" in stripe_status
    and "$routing_provider === 'gocardless' && !$requested_exact_stored_stripe_session" in stripe_status
    and "stored_status_session_id" in stripe_status
    and "aimee_account_deletion_tombstone_is_active($status_profile)" in stripe_status
    and "aimee_validate_replacement_stripe_account" in stripe_status
    and "aimee_stripe_checkout_session_matches_intent" in stripe_status
    and "$status_lock_token" in stripe_status
    and "'market'                     =>" not in stripe_status,
    "legacy Stripe status polling cannot attach a caller-selected session or rewrite market",
)
check(
    "return aimee_sync_subscription_from_stripe(" in stripe_sync
    and "$sync_lock_token" in stripe_sync
    and "$exact_transition_intent" in stripe_sync
    and "$stored_provider === 'gocardless'" in stripe_sync
    and "aimee_gocardless_retire_user_billing_for_deletion" in stripe_sync
    and stripe_sync.find("aimee_global_subscription_identity_conflicts(")
        < stripe_sync.find("aimee_gocardless_retire_user_billing_for_deletion"),
    "late Stripe events are locked and cannot reclaim current bank billing",
)
check(
    "case 'checkout.session.expired':" in stripe_webhook
    and "authoritative_expired" in stripe_webhook
    and "aimee_stripe_retire_expired_checkout_session" in stripe_webhook
    and "aimee_stripe_checkout_session_matches_owner" in stripe_expired
    and "aimee_stripe_checkout_session_matches_intent" in stripe_expired
    and "billing_checkout_intent_status'] = 'retired'" in stripe_expired
    and "'stripe_checkout_session_id' => null" in stripe_expired,
    "expired legacy Checkout Sessions are authoritatively retired and unblocked",
)
check(
    "aimee_refresh_subscription_checkout_lock($user_id, $cancel_lock_token)" in stripe_cancel
    and "expected_subscription_id" in stripe_cancel
    and "cancel_at_period_end" in stripe_cancel
    and "aimee_acquire_subscription_checkout_lock($user_id)" in stripe_portal
    and "aimee_account_deletion_tombstone_is_active($profile)" in stripe_portal
    and "aimee_refresh_subscription_checkout_lock($user_id, $portal_lock_token)" in stripe_portal,
    "legacy Stripe cancellation and portal creation remain exact-owner operations",
)
check(
    "aimee_expire_stripe_checkout_session_verified" in retire_stripe_before_bank
    and "aimee_stripe_subscription_is_verified_terminal" in retire_stripe_before_bank
    and retire_stripe_before_bank.find("aimee_stripe_subscription_is_verified_terminal")
        < retire_stripe_before_bank.find("'stripe_subscription_id' => null")
    and "stripe_authority_clear_unverified" in retire_stripe_before_bank
    and "aimee_membership_retire_stripe_before_bank_checkout($profile)" in gocardless_checkout
    and "provider_transition_blocked" in gocardless_checkout,
    "Stripe authority is terminal-proved and cleared before GoCardless creation",
)
check(
    "foreach ($extra_where as $field => $expected)" in gocardless_profile_update
    and "array_key_exists($field, $update)" in gocardless_profile_update
    and "gc_profile_cas_unverified" in gocardless_profile_update,
    "GoCardless verified writes recheck lock and tombstone CAS ownership",
)


# Account deletion terminal-proves every known provider authority, then checks
# that no webhook changed identity before any private file or local row erasure.
check(
    delete_account.find("aimee_global_all_schema_health(true)")
    < delete_account.find("aimee_acquire_subscription_checkout_lock")
    < delete_account.find("aimee_sms_bundle_retire_pending_sessions_for_user"),
    "deletion verifies all schemas and locks before provider retirement",
)
check(
    "aimee_gocardless_retire_user_billing_for_deletion" in delete_account
    and all(
        helper in gocardless_retire_deletion
        for helper in (
            "aimee_gocardless_retire_user_ledger_payments",
            "aimee_gocardless_profile_intent_billing_requests_for_retirement",
            "aimee_gocardless_cancel_billing_request_or_mandate",
            "aimee_gocardless_cancel_mandate_id",
        )
    )
    and "aimee_expire_stripe_checkout_session_verified" in delete_account
    and "aimee_cancel_stripe_subscription_verified" in delete_account,
    "deletion terminal-proves pending and recurring authorities on both rails",
)
check(
    "aimee_sms_bundle_resolve_pending_session" in sms_retire
    and "aimee_fulfill_sms_bundle_session" in sms_retire
    and "aimee_expire_stripe_checkout_session_verified" in sms_retire
    and "aimee_sms_bundle_mark_pending_terminal" in sms_retire,
    "deletion retires open SMS Checkout Sessions and reconciles completed ones",
)
post_cancel_read = delete_account.find("$post_cancel_profile")
first_file_cleanup = min(
    position
    for position in (
        delete_account.find("aimee_global_cleanup_live_image_beta_user_data"),
        delete_account.find("aimee_profile_media_delete_user_files"),
        delete_account.find("aimee_voice_note_delete_user_files"),
    )
    if position >= 0
)
first_local_delete = delete_account.find("$wpdb->delete(")
check(
    post_cancel_read >= 0
    and "$billing_identity_fields" in delete_account[post_cancel_read:first_file_cleanup]
    and "aimee_sms_bundle_pending_rows" in delete_account[post_cancel_read:first_file_cleanup]
    and post_cancel_read < first_file_cleanup < first_local_delete,
    "deletion rechecks billing identities and pending SMS before erasing files or rows",
)


if failures:
    print(
        f"\nRELEASE HARDENING RESULT: {passes} checks passed, "
        f"{failures} failed"
    )
    sys.exit(1)

print(f"\nRELEASE HARDENING RESULT: {passes} checks passed, 0 failed")
