#!/usr/bin/env python3
"""Focused static contract checks for the 1.8.3 GoCardless hardening.

These checks deliberately complement, rather than replace, provider sandbox
tests.  They guard the fail-closed wiring and durable local state transitions
that are easy to weaken accidentally during later refactors.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
GC = (ROOT / "includes/gocardless.php").read_text(encoding="utf-8")
SCHEMA = (ROOT / "includes/schema.php").read_text(encoding="utf-8")
CONFIG = (ROOT / "CONFIGURATION.md").read_text(encoding="utf-8")
README = (ROOT / "README.md").read_text(encoding="utf-8")

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


def function_block(name: str) -> str:
    match = re.search(rf"(?m)^function\s+{re.escape(name)}\s*\(", GC)
    if not match:
        return ""
    following = re.search(r"(?m)^function\s+[A-Za-z0-9_]+\s*\(", GC[match.end() :])
    end = len(GC) if not following else match.end() + following.start()
    return GC[match.start() : end]


credentials = function_block("aimee_gocardless_credentials_ready")
schema_ready = function_block("aimee_gocardless_payments_schema_ready")
creditor_identity = function_block("aimee_gocardless_creditor_identity_ready")
ready = function_block("aimee_gocardless_ready")
idempotency_conflict = function_block("aimee_gocardless_idempotency_conflict_details")
idempotent_create = function_block("aimee_gocardless_idempotent_create")
list_collection = function_block("aimee_gocardless_list_collection")
list_intent = function_block("aimee_gocardless_list_billing_requests_for_intent")
list_mandate_payments = function_block("aimee_gocardless_list_payments_for_mandate")
release_lock = function_block("aimee_gocardless_release_checkout_lock_verified")
intent_payload = function_block("aimee_gocardless_build_checkout_intent_payload")
checkout = function_block("aimee_gocardless_checkout")
create_payment = function_block("aimee_gocardless_create_payment_for_user")
terms_match = function_block("aimee_gocardless_billing_request_terms_match")
sync = function_block("aimee_gocardless_sync_billing_request_for_user")
ledger_lookup = function_block("aimee_gocardless_ledger_for_payment")
match_payment = function_block("aimee_gocardless_payment_matches_ledger")
apply_payment = function_block("aimee_gocardless_apply_payment")
status = function_block("aimee_gocardless_subscription_status")
terminal_proof = function_block("aimee_gocardless_terminal_resource_proof")
br_mandate = function_block("aimee_gocardless_billing_request_mandate_id")
fulfilled_br = function_block("aimee_gocardless_fulfilled_billing_request_error")
cancel_br = function_block("aimee_gocardless_cancel_billing_request_id")
cancel_mandate = function_block("aimee_gocardless_cancel_mandate_id")
cancel_payment = function_block("aimee_gocardless_cancel_payment_id")
cancel_profile = function_block("aimee_gocardless_cancel_profile_mandate")
cancel_route = function_block("aimee_gocardless_cancel")
retire_ledger = function_block("aimee_gocardless_retire_user_ledger_payments")
retire_deletion = function_block("aimee_gocardless_retire_user_billing_for_deletion")
retire_intent = function_block("aimee_gocardless_profile_intent_billing_requests_for_retirement")
portal = function_block("aimee_gocardless_portal")
webhook = function_block("aimee_gocardless_webhook")
worker = function_block("aimee_gocardless_renewal_worker")
period = function_block("aimee_gocardless_plan_period")

check(
    "GOCARDLESS_ACCESS_TOKEN" in credentials
    and "GOCARDLESS_WEBHOOK_SECRET" in credentials
    and "GOCARDLESS_CREDITOR_ID" in credentials,
    "configuration readiness requires token, webhook secret and expected creditor",
)
check(
    "aimee_gocardless_credentials_ready()" in ready
    and "aimee_gocardless_payments_schema_ready($refresh)" in ready
    and "aimee_gocardless_creditor_identity_ready($refresh)" in ready,
    "full readiness gates on immutable schema and authoritative creditor identity",
)
check(
    all(binding in creditor_identity for binding in (
        "aimee_gocardless_environment()", "GOCARDLESS_CREDITOR_ID",
        "GOCARDLESS_ACCESS_TOKEN", "hash('sha256'",
    ))
    and "aimee_gc_creditor_" in creditor_identity,
    "creditor success cache is environment, expected-ID and token bound without exposing the token",
)
check(
    "creditors?limit=100" in creditor_identity
    and "['meta']['cursors']" in creditor_identity
    and "rawurlencode($after)" in creditor_identity
    and "$page < 20" in creditor_identity
    and "$seen_cursors" in creditor_identity,
    "creditor discovery validates response shape and follows bounded non-repeating pagination",
)
check(
    "hash_equals($expected_id, (string) $creditor['id'])" in creditor_identity
    and "set_transient($cache_key, $cache_value, 5 * MINUTE_IN_SECONDS)" in creditor_identity
    and creditor_identity.find("hash_equals($expected_id") < creditor_identity.find("set_transient("),
    "only an exact authoritative creditor match receives the short success cache",
)

ledger_columns = {
    "provider_payment_id", "idempotency_key", "user_id", "mandate_id",
    "billing_request_id", "plan", "amount_minor", "currency", "cycle_key",
    "attempt", "reason", "status", "claim_token", "claim_expires_at",
    "applied_at", "period_start", "period_end",
}
check(all(column in schema_ready for column in ledger_columns), "runtime ledger schema contract is complete")
check(
    "aimee_global_core_schema_health($refresh)" in schema_ready
    and "aimee_global_schema_table_contract_ready($table, $required, $indexes, true)" in schema_ready,
    "runtime readiness requires global core health and the exact InnoDB table contract",
)
check(
    all(index in schema_ready for index in (
        "PRIMARY", "uq_aimee_gc_provider_payment", "uq_aimee_gc_idempotency",
        "uq_aimee_gc_cycle_attempt", "idx_aimee_gc_user_status",
        "idx_aimee_gc_mandate_status", "idx_aimee_gc_claim_expiry",
    )),
    "runtime readiness validates every named ledger index contract",
)
check(all(column in SCHEMA for column in ledger_columns), "database schema exposes every payment-ledger field")
check(
    all(field in SCHEMA for field in (
        "gocardless_authorized_plan", "gocardless_authorized_amount_minor",
        "gocardless_authorized_currency", "gocardless_renewal_attempt",
        "gocardless_retry_after",
    )),
    "profile schema exposes immutable authorization and retry fields",
)

check("aimee_rate_limit(" in checkout, "checkout retains the shared per-user rate limit")
check(
    "aimee_acquire_subscription_checkout_lock" in checkout
    and "finally" in checkout
    and "aimee_gocardless_release_checkout_lock_verified" in checkout,
    "checkout is serialized and always releases its mutex through verification",
)
check(
    "billing_checkout_lock_token" in checkout
    and "hash_equals($checkout_lock_token" in checkout
    and "billing_checkout_lock_token" in release_lock
    and "gc_checkout_unlock_unverified" in release_lock,
    "checkout verifies both mutex acquisition and release writes",
)
check("aimee_gocardless_profile_has_open_payment" in checkout, "checkout refuses an open payment")
check(
    "aimee_gocardless_creditor_identity_ready()" in checkout
    and all("aimee_gocardless_ready()" in block for block in (
        create_payment, sync, apply_payment, cancel_mandate, cancel_payment,
        status, portal, webhook, worker,
    )),
    "checkout, management, application, webhook, renewal and transition paths require creditor binding",
)
check(
    "aimee_membership_requested_market($request)" in checkout,
    "checkout uses the same stored-market resolver as provider routing",
)
check("aimee_gocardless_cancel_mandate_id" in checkout, "checkout cancels an old mandate before replacement")
check(
    "aimee_gocardless_cancel_billing_request_id($prior_billing_request_id)" in checkout
    and checkout.find("aimee_gocardless_cancel_billing_request_id")
    < checkout.find("$created = aimee_gocardless_idempotent_create"),
    "repeat same-rail checkout proves the stored Billing Request terminal before replacement",
)
check(
    "'billing_checkout_lock_token' => $checkout_lock_token" in checkout
    and "aimee_gocardless_profile_update_verified" in checkout,
    "checkout state write is lock-owned and read-after-write verified",
)
check(
    all(term in checkout for term in (
        "gocardless_authorized_plan", "gocardless_authorized_amount_minor",
        "gocardless_authorized_currency",
    )) and "aimee_terms" in intent_payload,
    "checkout persists and sends immutable authorization terms",
)
check(
    all(field in checkout for field in (
        "billing_checkout_intent_token", "billing_checkout_intent_provider",
        "billing_checkout_intent_plan", "billing_checkout_intent_market",
        "billing_checkout_intent_generation", "billing_checkout_intent_status",
    ))
    and checkout.find("'billing_checkout_intent_status'     => $reuse_checkout_intent")
    < checkout.find("$created = aimee_gocardless_idempotent_create"),
    "checkout commits the complete durable intent before the first Billing Request POST",
)
check(
    "aimee_checkout_intent" in intent_payload
    and "implode('|', [" in intent_payload
    and "(int) $user_id" in intent_payload
    and "aimee_gocardless_list_billing_requests_for_intent" in checkout,
    "Billing Request metadata binds provider authority to intent, owner and immutable terms",
)
check(
    "$page < 20" in list_collection
    and "limit=500" in list_collection
    and "$seen_cursors" in list_collection
    and "gc_collection_page_limit" in list_collection
    and "gc_collection_cursor_repeated" in list_collection,
    "authoritative provider listing is complete, bounded and cursor-fail-closed",
)
check(
    "billing_checkout_intent_status'       => $reuse_checkout_intent && $prior_flow_id !== ''" in checkout
    and ": 'billing_request_bound'" in checkout
    and checkout.find(": 'billing_request_bound'")
    < checkout.find("'billing_request_flows' => [")
    and "billing_checkout_intent_status'       => 'flow_bound'" in checkout
    and checkout.count("aimee_gocardless_compensate_checkout_intent") >= 3,
    "Billing Request is durably bound before flow creation and every unbound flow path compensates",
)

check(
    "hash_equals($stored_id, $requested_id)" in sync
    and "aimee_gocardless_retrieve_billing_request($stored_id)" in sync,
    "sync accepts only the profile's current Billing Request",
)
check(
    "$br_status !== 'fulfilled'" in sync
    and "ready_to_fulfil" not in sync
    and "aimee_gocardless_billing_request_terms_match" in sync,
    "sync requires a fully fulfilled request with exact terms",
)
check(
    "aimee_gocardless_billing_request_mandate_id($br)" in sync
    and "mandate_request_mandate" in br_mandate
    and "$billing_request['mandate_request']['links']['mandate']" in br_mandate,
    "sync prefers the authoritative fulfilled-request mandate link with legacy fallback",
)
check(
    all(term in terms_match for term in (
        "aimee_generation", "aimee_terms", "max_amount_per_payment",
        "max_total_amount", "creation_date",
    )),
    "Billing Request validation covers generation, plan, price, currency and periodic limit",
)

check(
    "provider_payment_id=%s" in ledger_lookup
    and "idempotency_key=%s" in ledger_lookup
    and "aimee_user_id" not in ledger_lookup,
    "payment resolution uses provider/idempotency identity with no user-id metadata fallback",
)
check(
    all(term in match_payment for term in (
        "mandate_id", "amount_minor", "currency", "idempotency_key", "plan",
        "aimee_generation",
    )),
    "provider payment must match every immutable ledger term",
)
check(
    "FOR UPDATE" in apply_payment
    and "$current_authorization" in apply_payment
    and "applied_at" in apply_payment,
    "payment application locks rows, revalidates authorization and durably deduplicates",
)
check(
    "['confirmed', 'paid_out']" in apply_payment
    and "empty($locked_ledger->applied_at)" in apply_payment,
    "confirmed then paid_out cannot extend access twice",
)
check(
    "$is_current_applied_payment" in apply_payment
    and "aimee_gocardless_payment_status_is_terminal_failure($effective_status)" in apply_payment
    and "'subscription_cancel_at_period_end' => 1" in apply_payment,
    "late failure of the current applied payment records failure and stops renewal",
)
check(
    "$stored_status === 'paid_out' && $status === 'confirmed'" in apply_payment,
    "out-of-order confirmed cannot downgrade an already paid-out ledger row",
)

check(
    all(term in create_payment for term in (
        "cycle_key", "attempt", "idempotency_key", "claim_token",
        "claim_expires_at", "request_unknown",
    )),
    "payment creation persists cycle/attempt identity and an atomic work lease",
)
check(
    "((int) $latest->attempt + 1)" in create_payment
    and "aimee_gocardless_payment_status_is_terminal_failure" in create_payment,
    "terminal payment failure advances to a new persisted attempt",
)
check(
    "idempotent_creation_conflict" in idempotency_conflict
    and "conflicting_resource_id" in idempotency_conflict
    and "aimee_gocardless_request('GET'" in idempotent_create,
    "409 idempotency conflicts retrieve the authoritative existing resource",
)
check(
    "aimee_gocardless_idempotent_create(" in create_payment
    and "gc_idempotency_reconciliation_unknown" in idempotent_create
    and "request_unknown" in create_payment,
    "ambiguous payment creates retain the same ledger attempt and idempotency key",
)
check(
    checkout.count("aimee_gocardless_idempotent_create(") >= 2
    and checkout.count("aimee_gocardless_list_billing_requests_for_intent") >= 2
    and "aimee_gocardless_billing_request_matches_intent" in checkout
    and "$prior_billing_request_id" in checkout
    and "hash('sha256', $checkout_intent_token)" in checkout,
    "Billing Request and flow conflicts use the durable intent across retries and are validated",
)
check(
    "billing_checkout_intent_plan" in checkout
    and "aimee_gocardless_cancel_billing_request_id($prior_billing_request_id)" in checkout
    and "$fulfilled_mandate_id" in checkout,
    "plan changes cancel prior pending authority while same-term lost responses reuse the stored intent",
)
check(
    "gmdate('Y-m-d H:i:s', $period_end)" in apply_payment
    and "end -" not in apply_payment,
    "next collection is scheduled at the new authorized period boundary",
)
check(
    "user_id>%d" in worker and "ORDER BY user_id ASC" in worker
    and "LIMIT 100" in worker and "while (count($rows) === 100)" in worker,
    "renewal worker keyset-pages every due profile without 100-row starvation",
)
check(
    "gocardless_retry_after" in worker and "gocardless_next_payment_at<=%s" in worker,
    "renewal worker picks up missed boundaries and due retries",
)

check(
    "aimee_gocardless_event_processed" in webhook
    and "aimee_gocardless_record_event" in webhook
    and "['status'=>'retry'" in webhook,
    "webhook retries event-ledger and linked processing failures",
)
check(
    "aimee_gocardless_mandate_requires_processing" in webhook
    and "aimee_gocardless_provider_payment_is_known" in webhook
    and "elseif (!$linked)" in webhook,
    "webhook records unrelated creditor traffic without blocking Aimee events",
)
check(
    "gocardless_billing_request_id" in status
    and "aimee_gocardless_sync_billing_request_for_user($user_id, $stored_br_id)" in status
    and "get_param(" not in status,
    "status reconciles the stored Billing Request without trusting redirect parameters",
)
check(
    "aimee_gocardless_profile_update_verified" in cancel_profile
    and "gocardless_mandate_id' => $mandate_id" in cancel_profile,
    "mandate cancellation verifies its conditional local write",
)
check(
    "mandate_request_mandate" in br_mandate
    and "$billing_request['mandate_request']['links']['mandate']" in br_mandate,
    "fulfilled Billing Request exposes its authoritative mandate with legacy fallback",
)
check(
    "gc_billing_request_fulfilled" in fulfilled_br
    and "'status' => 'fulfilled'" in fulfilled_br
    and "'mandate_id' => aimee_gocardless_billing_request_mandate_id" in fulfilled_br,
    "fulfilled Billing Request surfaces the mandate instead of claiming cancellation",
)
check(
    "['cancelled']" in cancel_br
    and "aimee_gocardless_unwrap($cancelled, 'billing_requests')" in cancel_br
    and "$attempt < 2" in cancel_br
    and "gc_billing_request_cancellation_unverified" in cancel_br
    and "canceled" not in cancel_br,
    "Billing Request cancellation requires exact ID and status=cancelled proof with bounded reads",
)
check(
    "hash_equals((string) $expected_id, $resource_id)" in terminal_proof
    and "_identity_mismatch" in terminal_proof,
    "cancellation proof binds terminal state to the exact provider resource",
)
check(
    "=== 404" not in cancel_mandate
    and "gc_mandate_state_unverified" in cancel_mandate,
    "an unexplained mandate 404 never proves cancellation",
)
check(
    "aimee_gocardless_unwrap($cancelled, 'mandates')" in cancel_mandate
    and "$attempt < 2" in cancel_mandate
    and "aimee_gocardless_retrieve_mandate($mandate_id)" in cancel_mandate
    and "gc_mandate_cancellation_unverified" in cancel_mandate,
    "mandate action requires matching terminal response or bounded post-cancel retrieval",
)
check(
    "aimee_gocardless_unwrap($cancelled, 'payments')" in cancel_payment
    and "$attempt < 2" in cancel_payment
    and "gc_payment_not_cancellable" in cancel_payment
    and "gc_payment_cancellation_unverified" in cancel_payment,
    "cancellable payments require the same authoritative terminal proof",
)
check(
    "$payment_cancelled = aimee_gocardless_cancel_payment_id($payment_id)" in create_payment
    and "if (is_wp_error($payment_cancelled)) return $payment_cancelled;" in create_payment,
    "superseded payment creation propagates cancellation ambiguity",
)
check(
    "hash_equals($payment_lock_token" in create_payment
    and "empty($fresh_profile->subscription_cancel_at_period_end)" in create_payment
    and "empty($fresh_profile->gocardless_cancelled_at)" in create_payment
    and "empty($fresh_profile->account_deletion_started_at)" in create_payment
    and "'billing_checkout_lock_token'   => $payment_lock_token" in create_payment
    and "aimee_gocardless_release_checkout_lock_verified" in create_payment,
    "payment creation revalidates exact lock, cancellation and deletion authority after provider POST",
)
check(
    "aimee_acquire_subscription_checkout_lock($user_id)" in cancel_route
    and "aimee_gocardless_release_checkout_lock_verified" in cancel_route
    and cancel_route.find("'subscription_cancel_at_period_end' => 1")
    < cancel_route.find("aimee_gocardless_retire_user_ledger_payments")
    < cancel_route.find("aimee_gocardless_cancel_mandate_id"),
    "customer cancellation serializes, commits no-renew, retires payments, then cancels the mandate",
)
check(
    "SELECT * FROM {$table} WHERE user_id=%d ORDER BY id ASC" in retire_ledger
    and "aimee_gocardless_list_payments_for_mandate" in retire_ledger
    and "aimee_payment_key" in retire_ledger
    and "count($key_matches) > 1" in retire_ledger
    and "gc_unknown_payment_unresolved" in retire_ledger
    and "aimee_gocardless_payment_matches_ledger" in retire_ledger
    and "aimee_gocardless_cancel_payment_id" in retire_ledger,
    "deletion reconciliation binds exact unknown creates and terminal-proves every user ledger payment",
)
check(
    "billing_checkout_intent_status" in retire_intent
    and "request_unknown" in retire_intent
    and "aimee_gocardless_list_billing_requests_for_intent" in retire_intent
    and "count($matches) > 1" in retire_intent
    and "gc_checkout_intent_unresolved" in retire_intent,
    "intent-only Billing Requests are authoritatively discovered and ambiguity blocks erasure",
)
check(
    "aimee_gocardless_retire_user_ledger_payments" in retire_deletion
    and "aimee_gocardless_cancel_billing_request_or_mandate" in retire_deletion
    and "aimee_gocardless_cancel_mandate_id" in retire_deletion
    and retire_deletion.find("aimee_gocardless_retire_user_ledger_payments")
    < retire_deletion.find("aimee_gocardless_cancel_mandate_id"),
    "account deletion gate retires all ledger payments before every Billing Request and mandate authority",
)
check(
    "aimee_global_add_months_clamped" in period and "min($day, $last_day)" in period,
    "calendar periods use the shared clamped helper with a safe fallback",
)
check(
    "GOCARDLESS_CREDITOR_ID" in CONFIG
    and "/creditors" in CONFIG
    and "creditor ID" in README,
    "configuration and release README document the expected-creditor gate",
)

print(f"\n{passes} passed, {failures} failed")
sys.exit(1 if failures else 0)
