#!/usr/bin/env python3
"""Static integration checks for Aimee Global security/privacy through 1.8.9."""

from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent


def read(relative: str) -> str:
    return (ROOT / relative).read_text(encoding="utf-8")


failures: list[str] = []
checks = 0


def require(condition: bool, label: str) -> None:
    global checks
    checks += 1
    if not condition:
        failures.append(label)


def function_body(source: str, name: str) -> str:
    marker = f"function {name}"
    start = source.find(marker)
    if start < 0:
        return ""
    brace = source.find("{", start)
    if brace < 0:
        return ""
    depth = 0
    quote = ""
    escaped = False
    for offset in range(brace, len(source)):
        char = source[offset]
        if quote:
            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == quote:
                quote = ""
            continue
        if char in "'\"":
            quote = char
        elif char == "{":
            depth += 1
        elif char == "}":
            depth -= 1
            if depth == 0:
                return source[brace + 1 : offset]
    return ""


bootstrap = read("aimee-global.php")
security = read("includes/security-privacy.php")
engine = read("includes/engine.php")
fallback = read("templates/shared/chat-fallback.php")
legacy = read("includes/legacy-ui.php")
image_events = read("includes/user-image-events.php")
audit_runner = read("tests/run-audit-suite.py")
native_runner = read("tests/run-native-audit-suite.py")

require("includes/security-privacy.php" in bootstrap, "bootstrap loads security/privacy module")
release_finalizer = function_body(bootstrap, "aimee_global_finalize_upgrade_if_healthy")
require("aimee_profile_media_maybe_migrate_legacy(true)" in release_finalizer and "aimee_profile_media_migration_is_complete" in release_finalizer, "release finalizer is gated on verified profile-media migration")
require(release_finalizer.find("aimee_profile_media_maybe_migrate_legacy(true)") < release_finalizer.find("update_option('aimee_global_version'"), "profile migration gate runs before the release marker")

gallery_paths = [
    "templates/gallery-uk.php",
    "templates/gallery-us.php",
    "templates/gallery-vip.php",
    "templates/shared/gallery.php",
]
for gallery_path in gallery_paths:
    gallery = read(gallery_path)
    require("aimee_security_require_gallery_access" in gallery, f"{gallery_path} self-enforces protected access")
    require("aimee_security_gallery_albums" in gallery, f"{gallery_path} uses grouped per-user protected catalogue")
    require("templates/shared/gallery-albums.php" in gallery, f"{gallery_path} renders the shared album partial")
    require("fonts.googleapis.com" not in gallery and "fonts.gstatic.com" not in gallery, f"{gallery_path} makes no third-party font request")
    for forbidden in ("WP_Query", "wp_get_attachment_url", "wp_get_attachment_image_url", "posts_per_page", "media_handle_upload", "$_FILES"):
        require(forbidden not in gallery, f"{gallery_path} does not expose attachment enumeration via {forbidden}")

gallery_access = function_body(security, "aimee_security_require_gallery_access")
gallery_items = function_body(security, "aimee_security_gallery_items")
require("is_user_logged_in" in gallery_access, "gallery helper requires authentication")
require("aimee_subscription_is_active" not in gallery_access, "gallery page access does not impose a blanket membership gate")
require("$profile" in gallery_access and "profile_required" in gallery_access, "gallery helper requires a signed-in Aimee profile or constrained administrator")
require("current_user_can('manage_options')" in gallery_access and "'age' => 0" in gallery_access, "profile-less administrator is limited to fail-closed safe catalogue checks")
require("aimee_media_item_is_viewable" in gallery_items, "gallery eligibility is rechecked per user/item")
require("aimee_private_media_payload" in gallery_items, "gallery emits protected controller payloads")

precheck = function_body(security, "aimee_auth_security_precheck")
clear_success = function_body(security, "aimee_auth_security_clear_success")
require("aimee_auth_security_is_locked" in precheck, "authenticate precheck evaluates the lock")
require("$user instanceof WP_User" not in precheck, "preauthenticated WP_User cannot bypass lock")
require("aimee_auth_security_bypass_lock" in precheck, "only explicit trusted filter can bypass lock")
require("add_filter('authenticate', 'aimee_auth_security_precheck', 2, 3)" in security, "lock precheck runs before core authenticators")
require("add_filter('authenticate', 'aimee_auth_security_precheck', PHP_INT_MAX, 3)" in security, "lock enforcement reruns after every authenticator")
require("aimee_auth_ip_" not in clear_success, "success handler never clears IP key")
require("aimee_auth_security_bucket_keys" not in clear_success, "success handler does not iterate identity plus IP buckets")
require("aimee_auth_security_identity_key" in clear_success, "success handler clears authenticated identity only")
require("0044" in security and "+44" in security and "aimee_auth_security_local_aliases" in security, "local phone canonicalization exists without engine dependency")
require("function_exists('aimee_mobile_login_candidates')" in security, "engine alias helper remains an optional enhancement")
require("aimee_auth_security_generic_message" in security and "wp_login_errors" in security, "WordPress login failures collapse to a generic response")
require("static $recorded" not in function_body(security, "aimee_auth_security_record_failure"), "batched auth calls cannot collapse multiple guesses into one failure")

require("WP_CONTENT_DIR . '/aimee-private-profile-media'" not in security, "profile media has no public wp-content default")
require("AIMEE_PROFILE_MEDIA_DIR" in security, "private media directory can be explicitly configured")
require("if (!$document_root) return '';" in security, "profile media fails closed when no safe default can be derived")
prepare_dir = function_body(security, "aimee_profile_media_prepare_dir")
generic_prepare_dir = function_body(security, "aimee_private_storage_prepare_dir")
require("aimee_private_storage_default_dir" in security, "shared private storage derives a site-scoped non-public default")
require("aimee_private_storage_prepare_dir" in prepare_dir, "profile media consumes the shared private-directory policy")
for public_root in ("ABSPATH", "upload_root", "document_root"):
    require(public_root in generic_prepare_dir, f"shared private storage rejects {public_root}")
require("'profile-user-' . intval($user_id)" in security, "profile filename is stable per user")
require("AUTH_SALT" not in security, "profile filename is independent of rotating authentication salts")
require("aimee_profile_media_prepare_dir" in function_body(security, "aimee_profile_media_delete_user_files"), "profile cleanup refuses unsafe configured storage paths")
require("aimee_profile_media_permissions_are_private($dir, true)" in generic_prepare_dir, "private directory mode is verified fail closed")
store = function_body(security, "aimee_profile_media_store")
require("$facts = aimee_profile_media_validate_bytes($bytes)" in store, "profile storage revalidates actual bytes at the write boundary")
require("aimee_profile_media_permissions_are_private($temporary, false)" in store and "aimee_profile_media_permissions_are_private($target, false)" in store, "private photo mode is verified before and after commit")
require("random_bytes(8)" in store and "catch (Exception" in store, "entropy failure becomes a recoverable profile-media error")
require("if (!@rename($temporary, $target))" in store and "wp_delete_file($temporary)" in store, "replacement failure retains old file and removes temporary file")
validator = function_body(security, "aimee_profile_media_validate_bytes")
require("new finfo(FILEINFO_MIME_TYPE)" in validator, "profile image uses magic MIME validation")
require("getimagesizefromstring" in validator, "profile image validates decoded dimensions")
require("pixels" in validator and "dimension" in validator and "bytes" in validator, "profile image enforces byte/dimension/pixel limits")
require("admin_post_nopriv_aimee_profile_photo" in security, "unauthenticated profile-photo endpoint explicitly rejects requests")
require("get_current_user_id" in function_body(security, "aimee_serve_profile_media"), "profile-photo endpoint serves current owner only")
require("Cross-Origin-Resource-Policy: same-origin" in security, "profile-photo response cannot be embedded cross-origin")

legacy_candidate = function_body(security, "aimee_profile_media_legacy_candidate")
legacy_migrate = function_body(security, "aimee_profile_media_migrate_legacy_profile")
legacy_batch = function_body(security, "aimee_profile_media_maybe_migrate_legacy")
profile_cleanup = function_body(security, "aimee_profile_media_delete_user_files")
require("baseurl" in legacy_candidate and "basedir" in legacy_candidate and "home_url" in legacy_candidate, "legacy photo mapping is limited to the current same-host uploads base")
require("aimee_user_" in legacy_candidate and "preg_quote((string) $user_id" in legacy_candidate, "legacy photo basename is bound to its exact owner")
for forbidden_part in ("user", "pass", "query", "fragment"):
    require(forbidden_part in legacy_candidate, f"legacy profile URL rejects {forbidden_part}")
require("rawurldecode($target_path) !== $target_path" in legacy_candidate and "realpath" in legacy_candidate, "legacy mapping rejects encoded paths and symlink escapes")
require("aimee_profile_media_read_validated_file" in legacy_migrate and "aimee_profile_media_store" in legacy_migrate, "legacy migration validates actual bytes before private storage")
require(legacy_migrate.find("wp_delete_file($legacy_path)") < legacy_migrate.find("$wpdb->query"), "legacy public file is deleted and checked before DB pointer commit")
require("WHERE user_id = %d AND BINARY profile_image_url = BINARY %s" in legacy_migrate, "legacy DB update condition byte-matches the exact owner and prior URL")
require("aimee_profile_media_profile_url_for_user" in legacy_migrate and "hash_equals($protected_url, $verified_url)" in legacy_migrate, "legacy DB pointer is read back and verified")
require("profile_image_url <> %s" in legacy_batch and "SELECT COUNT(*)" in legacy_batch, "migration completion requires a verified zero-row scan")
require("add_action('init', 'aimee_profile_media_maybe_migrate_legacy', 15)" in security, "profile migration runs before the priority-20 release finalizer")
require("glob($dir . DIRECTORY_SEPARATOR . $base . '.*.tmp-*')" in profile_cleanup, "account cleanup removes profile temporary crash remnants")
require("aimee_profile_media_legacy_candidate" in profile_cleanup and "wp_delete_file($legacy_path)" in profile_cleanup, "account cleanup removes recognized pending public legacy upload")
require("profile_image_url" not in fallback, "chat fallback never renders or trusts a stored legacy/public profile URL")
require("aimee_profile_media_file_for_user" in fallback and "aimee_profile_media_url" in fallback, "chat fallback renders only a validated owner-private endpoint")

consent_endpoint = function_body(security, "aimee_privacy_consent_settings")
require("/privacy-consent" in security and "permission_callback' => 'aimee_privacy_consent_route_permission" in security, "authenticated privacy-consent REST route is registered")
require("get_current_user_id" in consent_endpoint and "aimee_global_core_schema_health" in consent_endpoint, "privacy-consent update requires an authenticated owner and healthy schema")
require("current_time('mysql', true)" in consent_endpoint and "aimee_special_category_consent_version" in consent_endpoint, "explicit consent writes an exact UTC timestamp and current version")
require("special_category_consent_at'] = null" in consent_endpoint and "special_category_consent_version'] = null" in consent_endpoint and "escort_mode'] = 0" in consent_endpoint, "withdrawal clears versioned proof and revokes the legacy adult toggle")
require("privacy-consent" in fallback and "special_category_consent" in fallback, "fallback account settings expose explicit consent and withdrawal")
require("aimee_global_chat_privacy_consent_markup" in legacy and "privacy-consent" in legacy, "theme-supplied legacy UI receives authenticated privacy choice controls")

profile_save = function_body(engine, "handle_aimee_profile_save")
registration_diagnostic = function_body(engine, "aimee_registration_record_failure")
registration_schedule = function_body(engine, "aimee_registration_schedule_post_commit")
registration_worker = function_body(engine, "aimee_registration_run_post_commit")
require("preg_match('/\\A[0-9]{6}\\z/', $passcode) !== 1" in profile_save, "new registrations require exactly six ASCII digits on the server")
require("$weak_passcodes = ['123456', '654321', '012345'];" in profile_save, "new registrations reject the explicitly predictable six-digit sequences")
require("in_array($passcode, $weak_passcodes, true)" in profile_save and "preg_match('/\\A([0-9])\\1{5}\\z/', $passcode)" in profile_save, "weak-code checks use strict string comparison and reject every repeated digit")
require("intval($passcode)" not in profile_save and "(int) $passcode" not in profile_save and "wp_create_user($account_login, $passcode" in profile_save, "registration keeps the passcode as a string so a leading zero is preserved")

fallback_registration_input = re.search(r'<input\b(?=[^>]*\bname="passcode")[^>]*>', fallback)
fallback_login_input = re.search(r'<input\b(?=[^>]*\bname="aimee_pin")[^>]*>', fallback)
require(fallback_registration_input is not None, "fallback onboarding exposes a registration passcode input")
if fallback_registration_input:
    registration_tag = fallback_registration_input.group(0)
    for attribute in ('pattern="[0-9]{6}"', 'minlength="6"', 'maxlength="6"', 'inputmode="numeric"', 'data-aimee-passcode="1"'):
        require(attribute in registration_tag, f"fallback registration passcode declares {attribute}")
require("function validPasscode" in fallback and "/^[0-9]{6}$/" in fallback, "fallback JavaScript validates exactly six ASCII digits")
require("data-aimee-passphrase" not in fallback and "validPassphrase" not in fallback, "fallback no longer applies the superseded passphrase policy")
fallback_registration_form = re.search(r'<form\b(?=[^>]*\bid="join")[^>]*>', fallback)
require(fallback_registration_form is not None and 'data-aimee-registration="1"' in fallback_registration_form.group(0), "fallback marks its real registration form explicitly")
require(fallback_registration_form is not None and 'data-aimee-native-privacy-choices="1"' in fallback_registration_form.group(0), "fallback declares that its optional privacy choice is native")

require(fallback_login_input is not None, "fallback exposes the existing-account password input")
if fallback_login_input:
    login_tag = fallback_login_input.group(0)
    for forbidden_attribute in ("pattern=", "minlength=", "maxlength=", "inputmode=", "data-aimee-passcode"):
        require(forbidden_attribute not in login_tag, f"existing fallback login remains free of {forbidden_attribute} constraints")
require("sanitize_text_field(wp_unslash($_POST['aimee_pin']" not in fallback, "existing login treats the stored password as opaque")

require("data-aimee-passcode" in legacy and "function validatePasscode" in legacy and "/^[0-9]{6}$/" in legacy, "theme-supplied registration UI validates exactly six ASCII digits")
for legacy_attribute in ('input.setAttribute("pattern", "[0-9]{6}")', 'input.setAttribute("minlength", "6")', 'input.setAttribute("maxlength", "6")', 'input.setAttribute("inputmode", "numeric")'):
    require(legacy_attribute in legacy, f"theme-supplied registration passcode configures {legacy_attribute}")
harden_passwords = function_body(legacy, "hardenPasswords")
registration_form = function_body(legacy, "isRegistrationForm")
require('input.setAttribute("data-aimee-passphrase"' not in legacy and "validatePassphrase" not in legacy, "theme-supplied UI no longer applies the superseded passphrase policy")
require('input.autocomplete !== "current-password"' in harden_passwords and 'input.removeAttribute("pattern")' in harden_passwords and 'input.removeAttribute("minlength")' in harden_passwords and 'input.removeAttribute("maxlength")' in harden_passwords and 'input.removeAttribute("inputmode")' in harden_passwords, "theme-supplied existing login removes all new-registration format constraints")
require("isOnboardingNode(input)" not in harden_passwords and "isRegistrationForm(form)" in harden_passwords, "password hardening never classifies a credential from its broad onboarding container")
require('form.getAttribute("data-aimee-registration") === "1"' in registration_form, "an explicit marker identifies a registration form")
for registration_field in ('input[name="first_name"]', 'input[name="age"]', 'input[name="phone_number"]', 'input[name="passcode"],input[autocomplete="new-password"]'):
    require(registration_field in registration_form, f"unmarked registration inference requires {registration_field}")

require("$privacy_acknowledged" not in profile_save and "if (!$privacy_acknowledged)" not in profile_save, "registration has no server-side privacy-acknowledgement gate")
require("privacy_acknowledged_at" not in profile_save, "new registration omits the privacy acknowledgement column instead of inventing an event")
require("'special_category_consent_at' => $special_category_consent" in profile_save and "'special_category_consent_version' => $special_category_consent" in profile_save, "onboarding persists versioned specialist consent only when explicitly chosen")
require("aimee_profile_media_store" in profile_save and "wp_upload_bits" not in profile_save, "onboarding stores profile photo only through private helper")
require(profile_save.find("strlen($encoded) > $encoded_limit") < profile_save.find("preg_replace('/\\s+/'"), "onboarding bounds encoded photo before whitespace normalization and decode")
require("$wpdb->last_error" not in profile_save and "get_error_message()" not in profile_save, "onboarding response does not expose raw database/WordPress errors")
require("aimee_global_core_schema_health" in profile_save, "registration gates on the complete installed schema before account creation")
require("ALTER TABLE" not in profile_save and "SHOW COLUMNS" not in profile_save, "registration performs no request-time schema DDL")
require("$inserted = $wpdb->insert($table, $profile_data)" in profile_save and "$wpdb->replace" not in profile_save, "new registration inserts a profile without destructive replacement")
photo_store_failure = profile_save[
    profile_save.find("if (is_wp_error($stored_profile_image))") :
    profile_save.find("$profile_data = [")
]
require("aimee_registration_record_failure" in photo_store_failure and "profile_media_store_failed" in photo_store_failure, "optional photo-store failure records a fixed private diagnostic")
require("$profile_image_bytes = '';" in photo_store_failure and "$profile_image_facts = null;" in photo_store_failure and "return " not in photo_store_failure, "optional photo-store failure degrades locally without aborting signup")
require("register_shutdown_function" in profile_save and "$profile_creation_committed" in profile_save, "pre-profile fatal exits roll back user and private photo")
require("catch (Throwable" in profile_save, "post-commit local completion exception cannot orphan a user/media pair")
for forbidden_remote_call in (
    "call_anthropic_api",
    "wp_mail(",
    "aimee_send_system_sms(",
    "wp_remote_post(",
    "wp_remote_get(",
    "wp_remote_request(",
):
    require(forbidden_remote_call not in profile_save, f"public registration handler excludes {forbidden_remote_call} network work")
require("aimee_registration_schedule_post_commit($user_id)" in profile_save and "'post_commit_scheduled'" in profile_save, "public registration queues deferred work and reports only its scheduling result")
require("wp_next_scheduled($hook, $args)" in registration_schedule and "$args = [$user_id];" in registration_schedule, "registration scheduler deduplicates an event carrying only the immutable user ID")
require("wp_schedule_single_event" in registration_schedule and "time() + 5" in registration_schedule, "registration schedules one bounded near-term worker event")
require("add_option($state_option" in registration_worker and "if (!$claimed) return;" in registration_worker, "deferred worker durably claims an account before provider work")
require("call_anthropic_api" in registration_worker and "wp_mail(" in registration_worker and "aimee_send_system_sms(" in registration_worker, "model, mail and carrier work exists only in the deferred worker")
require("'status' => 'completed'" in registration_worker and "'status' => 'skipped'" in registration_worker and "'status' => 'failed'" in registration_worker, "deferred worker terminates in a bounded durable state")

for diagnostic_token in (
    "'schema_preflight_failed'",
    "'wp_user_create_failed'",
    "'profile_media_store_failed'",
    "'profile_insert_failed'",
    "'post_commit_completion_failed'",
    "'core_schema_unhealthy'",
    "'wordpress_user_create_failed'",
    "'profile_image_storage_failed'",
    "'profile_database_write_failed'",
):
    require(diagnostic_token in registration_diagnostic, f"registration diagnostic allowlists {diagnostic_token}")
require("'occurred_at'" in registration_diagnostic and "'reference'" in registration_diagnostic and "'stage'" in registration_diagnostic and "'error_code'" in registration_diagnostic, "registration diagnostic persists only its four approved operational fields")
for forbidden_diagnostic_detail in ("$wpdb", "$request", "last_error", "get_error_message", "getMessage()"):
    require(forbidden_diagnostic_detail not in registration_diagnostic, f"registration diagnostics exclude {forbidden_diagnostic_detail} details")
generic_creation = "We could not create an account with those details."
require(profile_save.count(generic_creation) >= 3, "reserved, duplicate and create-time collision branches stay enumeration-safe")
require('name="privacy_acknowledged"' not in fallback, "fallback UI has no privacy-acknowledgement checkbox or gate")
require('href="<?php echo esc_url($privacy_url); ?>"' in fallback and "privacy notice" in fallback.lower(), "fallback onboarding and settings keep the privacy notice visibly linked")
require('name="special_category_consent" type="checkbox" value="1" required' not in fallback, "fallback onboarding leaves specialist consent optional")
require("ui-avatars.com" not in fallback and "profile-placeholder" in fallback, "fallback UI does not disclose the user's name to an avatar service")
require("aimee-ai.com/wp-content/uploads" not in fallback and "AIMEE_GLOBAL_URL . 'assets/pwa/" in fallback, "fallback UI uses a bundled same-origin Aimee image")

send_push = function_body(engine, "aimee_send_empty_web_push")
push_endpoint = function_body(engine, "aimee_is_safe_push_endpoint")
subscribe = function_body(engine, "handle_aimee_push_subscribe")
require("wp_safe_remote_request" in send_push, "push uses WordPress safe HTTP client")
require("'reject_unsafe_urls' => true" in send_push and "'sslverify'" in send_push, "push client rejects unsafe URLs and verifies TLS")
require("aimee_is_safe_push_endpoint" in send_push and "aimee_is_safe_push_endpoint" in subscribe, "push validates endpoints on subscribe and delivery")
require("https" in push_endpoint and "443" in push_endpoint, "push endpoint requires HTTPS safe port")
require("aimee_is_public_url" in push_endpoint and "aimee_push_endpoint_host_patterns" in push_endpoint, "push endpoint requires public IP and approved browser-push origin")

require("new finfo(FILEINFO_MIME_TYPE)" in image_events, "chat image validator checks magic MIME")
require("getimagesizefromstring" in image_events and "'pixels'" in image_events, "chat image validator enforces dimensions/pixel limit")
for token in ("image_event_id", "pendingImageEventId", "lastSentImageFingerprint", "clearChatImageComposer", "MutationObserver"):
    require(token in legacy, f"legacy security bridge includes {token}")
require("delete body.image" in legacy and "duplicate_image_ignored" in legacy, "legacy bridge strips stale retained image bytes")
require("lastSentImagePayload" not in legacy and "crypto.subtle.digest" in legacy, "legacy bridge retains only an image fingerprint, never stale base64 bytes")
require("input.value = \"\"" in legacy, "legacy bridge clears file input so same-file reselection fires change")
require("body.privacy_acknowledged" not in legacy and '"privacy_acknowledged"' not in function_body(legacy, "mountConsents"), "theme-supplied onboarding does not submit or inject a privacy acknowledgement")
mount_consents = function_body(legacy, "mountConsents")
require('"special_category_consent"' in mount_consents and '"Optional sensitive-information consent"' in legacy, "theme-supplied onboarding retains optional specialist consent")
require(".aimee-required-consents,input[name=\"special_category_consent\"]" in mount_consents, "legacy consent mounting skips forms that already provide the optional choice")
require("Read the privacy notice" in legacy and "config.privacyUrl" in legacy, "theme-supplied onboarding and settings keep the privacy notice visibly linked")
require("aimee-privacy-floating" not in legacy and "mountRequiredPanel" not in legacy, "theme-supplied privacy settings never create a floating or mandatory gate")

for required_test in (
    "gocardless-1.8.3-regression.py",
    "gocardless-only-checkout-1.8.4-regression.py",
    "release-hardening-regression.py",
    "public-media-catalogue-mode-1.8.5-regression.py",
    "schema-hardening-regression.py",
    "privacy-choice-dismissal-1.8.5-regression.mjs",
):
    require(required_test in audit_runner, f"canonical audit runner includes {required_test}")
    require(required_test in native_runner, f"native audit runner includes {required_test}")
require(audit_runner.count("billing-migration-hardening-regression.php") >= 2, "billing migration regression runs under PHP 8.3 and PHP 7.4")
require(audit_runner.count("registration-runtime-1.8.8-regression.php") == 2, "canonical audit runner executes registration runtime coverage under both PHP versions")
require("AIMEE_PHP_VERSION=7.4" in audit_runner, "canonical audit runner explicitly covers the PHP 7.4 registration path")

if failures:
    print("Security/privacy static regression failures:")
    for failure in failures:
        print(f"- {failure}")
    sys.exit(1)

print(f"PASS: {checks} security/privacy static integration checks.")
