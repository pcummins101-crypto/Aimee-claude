#!/usr/bin/env python3
"""Source regressions for the MariaDB/schema and billing hardening contract."""

from __future__ import annotations

import re
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SCHEMA = (ROOT / "includes/schema.php").read_text(encoding="utf-8")
INNER = (ROOT / "includes/inner-life.php").read_text(encoding="utf-8")
ENGINE = (ROOT / "includes/engine.php").read_text(encoding="utf-8")
BILLING = (ROOT / "includes/billing-migration.php").read_text(encoding="utf-8")

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
    match = re.search(rf"(?m)^function\s+{re.escape(name)}\s*\(", source)
    if not match:
        return ""
    next_match = re.search(
        r"(?m)^function\s+[A-Za-z0-9_]+\s*\(", source[match.end() :]
    )
    end = len(source) if not next_match else match.end() + next_match.start()
    return source[match.start() : end]


def create_table_blocks(source: str, collation_variable: str) -> list[str]:
    return re.findall(
        rf'dbDelta\("CREATE TABLE .*?\) ENGINE=InnoDB \${collation_variable};"\);',
        source,
        flags=re.S,
    )


core_install = function_block(SCHEMA, "aimee_global_install_core_schema")
core_health = function_block(SCHEMA, "aimee_global_core_schema_health")
message_key = function_block(SCHEMA, "aimee_global_messages_schema_primary_key")
inner_install = function_block(INNER, "aimee_global_install_inner_life_schema")
inner_health = function_block(INNER, "aimee_global_inner_life_schema_health")
runtime_due = function_block(ENGINE, "aimee_engine_runtime_schema_due")
runtime_health = function_block(ENGINE, "aimee_engine_runtime_schema_is_healthy")
runtime_failure = function_block(ENGINE, "aimee_engine_runtime_schema_mark_failed")
billing_migration = function_block(
    BILLING, "aimee_global_migrate_legacy_stripe_profiles"
)

profile_fields = [
    "gocardless_authorized_plan",
    "gocardless_authorized_amount_minor",
    "gocardless_authorized_currency",
    "gocardless_renewal_attempt",
    "gocardless_retry_after",
    "billing_checkout_intent_token",
    "billing_checkout_intent_provider",
    "billing_checkout_intent_plan",
    "billing_checkout_intent_market",
    "billing_checkout_intent_generation",
    "billing_checkout_intent_status",
    "billing_checkout_intent_payload",
    "account_deletion_started_at",
    "privacy_acknowledged_at",
    "special_category_consent_at",
    "special_category_consent_version",
]
check(
    all(field in core_install and field in core_health and field in runtime_health for field in profile_fields),
    "profile billing snapshots and consent fields are installed and health-checked",
)

ledger_columns = [
    "id", "provider_payment_id", "idempotency_key", "user_id", "mandate_id",
    "billing_request_id", "plan", "amount_minor", "currency", "cycle_key",
    "attempt", "reason", "status", "claim_token", "claim_expires_at",
    "applied_at", "period_start", "period_end", "created_at", "updated_at",
]
ledger_ddl_match = re.search(
    r"CREATE TABLE aimee_gocardless_payments \((.*?)\) ENGINE=InnoDB \$cc;",
    core_install,
    flags=re.S,
)
ledger_ddl = ledger_ddl_match.group(1) if ledger_ddl_match else ""
check(
    bool(ledger_ddl) and all(re.search(rf"(?m)^\s*{column}\s+", ledger_ddl) for column in ledger_columns),
    "GoCardless payment ledger has the complete column contract",
)
ledger_indexes = {
    "uq_aimee_gc_provider_payment": "provider_payment_id",
    "uq_aimee_gc_idempotency": "idempotency_key",
    "uq_aimee_gc_cycle_attempt": "user_id, mandate_id, cycle_key, attempt",
    "idx_aimee_gc_user_status": "user_id, status",
    "idx_aimee_gc_mandate_status": "mandate_id, status",
    "idx_aimee_gc_claim_expiry": "claim_expires_at",
}
check(
    all(name in ledger_ddl and columns in ledger_ddl for name, columns in ledger_indexes.items())
    and all(name in core_health for name in ledger_indexes),
    "GoCardless ledger unique and lookup indexes are exact health requirements",
)

critical_core_tables = [
    "aimee_user_profiles", "aimee_gocardless_events", "aimee_gocardless_payments",
    "aimee_messages", "aimee_long_term_memory", "aimee_relationship_state",
    "aimee_relationship_dimensions", "aimee_relationship_decisions",
    "aimee_turn_requests", "aimee_relationship_invitations", "aimee_media_decisions",
    "aimee_media_deliveries", "aimee_media_delivery_events", "aimee_sms_usage",
    "aimee_sms_outbound_events", "aimee_sms_inbound_events",
]
check(
    all(table in core_health for table in critical_core_tables)
    and "aimee_global_schema_index_ready" in SCHEMA
    and "Seq_in_index" in SCHEMA
    and "Non_unique" in SCHEMA
    and "Sub_part" in SCHEMA
    and "Index_type" in SCHEMA,
    "core health covers every critical table and exact ordered index shape",
)

core_ddl = create_table_blocks(core_install, "cc")
inner_ddl = create_table_blocks(inner_install, "cc")
runtime_ddl = create_table_blocks(ENGINE[ENGINE.find("add_action('init', function()") :], "charset_collate")
check(
    len(core_ddl) == 16 and len(inner_ddl) == 5 and len(runtime_ddl) == 11,
    "all core, cognitive and auxiliary CREATE TABLE statements declare InnoDB",
)
check(
    "SHOW TABLE STATUS LIKE %s" in SCHEMA
    and "ALTER TABLE `{$table}` ENGINE=InnoDB" in SCHEMA
    and "true" in core_health
    and "true" in inner_health
    and "true" in runtime_health,
    "transaction-dependent table health requires and repairs InnoDB",
)

check(
    "{$messages_primary_key} BIGINT UNSIGNED NOT NULL AUTO_INCREMENT" in core_install
    and "PRIMARY KEY ({$messages_primary_key})" in core_install
    and "if ($primary) return '';" in message_key
    and "isset($known['id']) && isset($known['message_id'])" in message_key
    and "aimee_global_messages_schema_primary_key_ready" in core_health,
    "legacy messages id/message_id migration never requests two auto-increment primary keys",
)

check(
    all(table in inner_health for table in [
        "aimee_inner_state", "aimee_relationship_events", "aimee_world_state",
        "aimee_opinions", "aimee_metacognitive_events",
    ])
    and "aimee_global_schema_claim_lock" in inner_install
    and "aimee_global_inner_life_schema_record_failure" in inner_install
    and "return $healthy;" in inner_install,
    "inner-life installer is locked, retryable and returns comprehensive health",
)

runtime_tables = [
    "aimee_user_profiles", "aimee_messages", "aimee_analytics_events",
    "aimee_stripe_events", "aimee_sms_usage", "aimee_sms_outbound_events",
    "aimee_sms_inbound_events", "aimee_sms_bundle_purchases",
    "aimee_continuity_items", "aimee_relationship_timeline",
    "aimee_push_subscriptions", "aimee_push_notifications",
]
check(
    all(table in runtime_health for table in runtime_tables)
    and "aimee_voice_note_table()" in runtime_health,
    "auxiliary runtime health covers every table and voice-note structure it creates",
)
sms_intent_columns = [
    "billing_generation", "market", "currency", "product_label",
]
sms_ddl = next(
    (block for block in runtime_ddl if "aimee_sms_bundle_purchases" in block),
    "",
)
check(
    bool(sms_ddl)
    and all(column in sms_ddl and column in runtime_health for column in sms_intent_columns)
    and "billing_generation VARCHAR(64) NOT NULL DEFAULT ''" in sms_ddl
    and "market VARCHAR(8) NOT NULL DEFAULT ''" in sms_ddl
    and "currency CHAR(3) NOT NULL DEFAULT ''" in sms_ddl
    and "product_label VARCHAR(190) NOT NULL DEFAULT ''" in sms_ddl,
    "SMS purchases persist and health-check exact immutable provider-create terms",
)
check(
    "aimee_engine_runtime_schema_is_healthy();" in runtime_due
    and "aimee_engine_runtime_schema_is_healthy(true)" in runtime_due
    and "aimee_global_schema_claim_lock" in runtime_due
    and "aimee_engine_runtime_schema_retry_after" in runtime_due,
    "runtime repair uses bounded health checks plus forced post-lock verification and backoff",
)

sensitive_update = ENGINE.find("SET `is_sensitive` = GREATEST")
sensitive_verify = ENGINE.find("WHERE COALESCE(`sensitive`, 0) > COALESCE(`is_sensitive`, 0)")
sensitive_drop = ENGINE.find("DROP COLUMN `sensitive`")
check(
    -1 < sensitive_update < sensitive_verify < sensitive_drop
    and "$merged === false" in ENGINE[sensitive_update:sensitive_drop]
    and "$remaining === null" in ENGINE[sensitive_update:sensitive_drop]
    and "!in_array('sensitive', $notification_columns, true)" in runtime_health,
    "legacy sensitive data is verified merged before drop and absence is a health requirement",
)

check(
    all(helper in SCHEMA + INNER + ENGINE for helper in [
        "aimee_global_schema_health_cache_get", "aimee_global_schema_health_cache_set",
        "aimee_global_schema_health_cache_forget",
    ])
    and "5 * MINUTE_IN_SECONDS" in SCHEMA
    and "if ($refresh)" in core_health
    and "if ($refresh)" in inner_health
    and "if ($refresh)" in runtime_health
    and "aimee_global_schema_health_cache_forget('engine_runtime')" in runtime_failure,
    "success-only five-minute health caches are version-bound and invalidated for repairs/failures",
)

lock_at = billing_migration.find("aimee_global_schema_claim_lock")
start_at = billing_migration.find("START TRANSACTION")
select_at = billing_migration.find("SELECT user_id")
for_update_at = billing_migration.find("FOR UPDATE")
commit_at = billing_migration.find("COMMIT")
complete_at = billing_migration.find("'completed_at'", commit_at)
check(
    -1 < lock_at < start_at < select_at < for_update_at < commit_at < complete_at
    and "if ($wpdb->query('START TRANSACTION') === false)" in billing_migration
    and "if ($wpdb->query('COMMIT') === false)" in billing_migration
    and billing_migration.count("ROLLBACK") >= 3,
    "billing migration atomically claims, locks rows, checks transaction boundaries and marks only after commit",
)
check(
    "aimee_global_core_schema_health(true)" in billing_migration
    and "aimee_global_closed_stripe_generation" in billing_migration
    and "$current_stripe_generation" in billing_migration
    and "$current_gocardless_generation" in billing_migration
    and "$provider === 'stripe'" in billing_migration
    and "$provider === 'gocardless'" in billing_migration
    and "hash_equals($current_stripe_generation, $generation)" in billing_migration
    and "hash_equals($current_gocardless_generation, $generation)" in billing_migration
    and "manual_review_user_ids" in billing_migration
    and "$updated !== 1" in billing_migration
    and "billing_account_generation = %s" in billing_migration,
    "billing archive preserves provider-matched current generations and fails closed for ambiguous or partial rows",
)
check(
    "Profile table was not present" not in BILLING
    and "completed_at" not in BILLING[
        BILLING.find("aimee_period_repair_schema_unavailable") :
        BILLING.find("aimee_period_repair_schema_unavailable") + 400
    ]
    and "legacy_original_period_end = ''" not in BILLING
    and "membership_started_at <> ''" not in BILLING,
    "missing schema and invalid DATETIME comparisons remain retryable rather than falsely complete",
)

print(f"\nSCHEMA HARDENING RESULT: {passes} passed, {failures} failed")
sys.exit(1 if failures else 0)
