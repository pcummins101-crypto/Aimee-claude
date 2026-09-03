<?php
defined('ABSPATH') || exit;

/**
 * Database primitives shared by the core, cognitive and legacy-engine schema
 * installers. Custom table names are fixed deployment identifiers, but every
 * identifier is still reduced to the SQL-safe subset before interpolation.
 */
function aimee_global_schema_identifier($identifier) {
    return preg_replace('/[^a-zA-Z0-9_]/', '', (string) $identifier);
}

function aimee_global_schema_table_exists($table) {
    global $wpdb;
    $table = aimee_global_schema_identifier($table);
    if ($table === '') return false;

    return (string) $wpdb->get_var($wpdb->prepare(
        'SHOW TABLES LIKE %s',
        $wpdb->esc_like($table)
    )) === $table;
}

function aimee_global_schema_table_is_innodb($table) {
    global $wpdb;
    $table = aimee_global_schema_identifier($table);
    if ($table === '') return false;

    $row = $wpdb->get_row($wpdb->prepare(
        'SHOW TABLE STATUS LIKE %s',
        $wpdb->esc_like($table)
    ));
    return $row
        && (string) ($row->Name ?? '') === $table
        && strcasecmp((string) ($row->Engine ?? ''), 'InnoDB') === 0;
}

function aimee_global_schema_ensure_innodb(array $tables) {
    global $wpdb;
    $healthy = true;

    foreach (array_values(array_unique($tables)) as $table) {
        $table = aimee_global_schema_identifier($table);
        if ($table === '' || !aimee_global_schema_table_exists($table)) {
            $healthy = false;
            continue;
        }
        if (aimee_global_schema_table_is_innodb($table)) continue;

        if ($wpdb->query("ALTER TABLE `{$table}` ENGINE=InnoDB") === false) {
            $healthy = false;
        }
    }

    return $healthy;
}

function aimee_global_schema_index_ready($table, $index_name, array $expected) {
    global $wpdb;
    $table = aimee_global_schema_identifier($table);
    $index_name = $index_name === 'PRIMARY'
        ? 'PRIMARY'
        : aimee_global_schema_identifier($index_name);
    if ($table === '' || $index_name === '') return false;

    $rows = (array) $wpdb->get_results($wpdb->prepare(
        "SHOW INDEX FROM `{$table}` WHERE Key_name = %s",
        $index_name
    ));
    if (!$rows) return false;

    $columns = [];
    $sub_parts = [];
    $unique = null;
    $index_type = null;
    foreach ($rows as $row) {
        $sequence = intval($row->Seq_in_index ?? 0);
        if ($sequence < 1) return false;
        $columns[$sequence] = (string) ($row->Column_name ?? '');
        $sub_parts[$sequence] = isset($row->Sub_part) ? intval($row->Sub_part) : null;
        $row_unique = intval($row->Non_unique ?? 1) === 0;
        if ($unique !== null && $unique !== $row_unique) return false;
        $unique = $row_unique;
        $row_index_type = strtoupper((string) ($row->Index_type ?? ''));
        if ($index_type !== null && $index_type !== $row_index_type) return false;
        $index_type = $row_index_type;
    }
    ksort($columns);
    ksort($sub_parts);

    if (array_values($columns) !== array_values((array) ($expected['columns'] ?? []))) {
        return false;
    }
    if ($unique !== !empty($expected['unique'])) return false;

    $expected_sub_parts = array_key_exists('sub_parts', $expected)
        ? array_map(
            static function ($value) { return $value === null ? null : intval($value); },
            array_values((array) $expected['sub_parts'])
        )
        : array_fill(0, count($columns), null);
    if (array_values($sub_parts) !== $expected_sub_parts) return false;

    $expected_index_type = strtoupper((string) ($expected['index_type'] ?? 'BTREE'));
    if ($index_type !== $expected_index_type) return false;

    return true;
}

function aimee_global_schema_table_contract_ready($table, array $columns, array $indexes = [], $require_innodb = true) {
    global $wpdb;
    $table = aimee_global_schema_identifier($table);
    if ($table === '' || !aimee_global_schema_table_exists($table)) return false;
    if ($require_innodb && !aimee_global_schema_table_is_innodb($table)) return false;

    $present = array_map('strval', (array) $wpdb->get_col(
        "SHOW COLUMNS FROM `{$table}`",
        0
    ));
    if (array_diff($columns, $present)) return false;

    foreach ($indexes as $name => $contract) {
        if (!aimee_global_schema_index_ready($table, $name, $contract)) return false;
    }
    return true;
}

/**
 * Cache only a successful health result, and bind it to the deployed schema
 * target. This keeps the normal request path cheap while ensuring a new build,
 * an explicit repair, or the five-minute expiry performs real MariaDB checks.
 */
function aimee_global_schema_health_cache_key($domain) {
    return 'aimee_schema_health_' . sanitize_key((string) $domain);
}

function aimee_global_schema_health_cache_get($domain) {
    if (!function_exists('get_transient')) return false;

    $cached = get_transient(aimee_global_schema_health_cache_key($domain));
    $target = defined('AIMEE_GLOBAL_SCHEMA_VERSION')
        ? (string) AIMEE_GLOBAL_SCHEMA_VERSION
        : '';
    return is_array($cached)
        && !empty($cached['healthy'])
        && hash_equals($target, (string) ($cached['schema_version'] ?? ''));
}

function aimee_global_schema_health_cache_set($domain) {
    if (!function_exists('set_transient')) return;

    set_transient(
        aimee_global_schema_health_cache_key($domain),
        [
            'healthy' => 1,
            'schema_version' => defined('AIMEE_GLOBAL_SCHEMA_VERSION')
                ? (string) AIMEE_GLOBAL_SCHEMA_VERSION
                : '',
        ],
        5 * MINUTE_IN_SECONDS
    );
}

function aimee_global_schema_health_cache_forget($domain) {
    if (function_exists('delete_transient')) {
        delete_transient(aimee_global_schema_health_cache_key($domain));
    }
}

/** MariaDB advisory lock with an atomic option fallback for unusual hosts. */
function aimee_global_schema_claim_lock($purpose, $ttl = 300) {
    global $wpdb;
    $purpose = sanitize_key((string) $purpose);
    $database_name = defined('DB_NAME') ? (string) DB_NAME : 'wordpress';
    $lock_name = 'aimee_' . substr(hash('sha256', $database_name . '|' . $purpose), 0, 48);
    $claimed = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock_name));
    if ((string) $claimed === '1') return 'db:' . $lock_name;
    if ((string) $claimed === '0') return '';

    $option = 'aimee_schema_lock_' . substr(hash('sha256', $purpose), 0, 24);
    $token = function_exists('wp_generate_uuid4')
        ? wp_generate_uuid4()
        : hash('sha256', uniqid($purpose, true));
    $payload = ['token' => $token, 'expires_at' => time() + max(30, intval($ttl))];
    if (add_option($option, $payload, '', false)) return 'option:' . $option . ':' . $token;

    $existing = get_option($option, []);
    if (is_array($existing) && intval($existing['expires_at'] ?? 0) < time()) {
        delete_option($option);
        if (add_option($option, $payload, '', false)) return 'option:' . $option . ':' . $token;
    }
    return '';
}

function aimee_global_schema_release_lock($claim) {
    global $wpdb;
    $claim = (string) $claim;
    if (strpos($claim, 'db:') === 0) {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', substr($claim, 3)));
        return;
    }
    if (strpos($claim, 'option:') !== 0) return;

    $parts = explode(':', $claim, 3);
    if (count($parts) !== 3) return;
    $existing = get_option($parts[1], []);
    if (is_array($existing) && hash_equals((string) ($existing['token'] ?? ''), $parts[2])) {
        delete_option($parts[1]);
    }
}

function aimee_global_core_schema_backoff_active() {
    $target = defined('AIMEE_GLOBAL_SCHEMA_VERSION') ? (string) AIMEE_GLOBAL_SCHEMA_VERSION : '';
    return (string) get_option('aimee_global_core_schema_retry_version', '') === $target
        && intval(get_option('aimee_global_core_schema_retry_after', 0)) > time();
}

function aimee_global_core_schema_record_failure() {
    update_option(
        'aimee_global_core_schema_retry_version',
        defined('AIMEE_GLOBAL_SCHEMA_VERSION') ? (string) AIMEE_GLOBAL_SCHEMA_VERSION : '',
        false
    );
    update_option('aimee_global_core_schema_retry_after', time() + (5 * MINUTE_IN_SECONDS), false);
}

function aimee_global_core_schema_clear_failure() {
    delete_option('aimee_global_core_schema_retry_version');
    delete_option('aimee_global_core_schema_retry_after');
}

/**
 * Existing deployments can legitimately use `id` as the messages primary key.
 * Feed dbDelta the primary key which actually exists so it never attempts to
 * add a second AUTO_INCREMENT column or a second primary key.
 */
function aimee_global_messages_schema_primary_key() {
    global $wpdb;
    $table = 'aimee_messages';
    if (!aimee_global_schema_table_exists($table)) return 'message_id';

    $primary = (array) $wpdb->get_results(
        "SHOW INDEX FROM `{$table}` WHERE Key_name = 'PRIMARY'"
    );
    if (count($primary) === 1) {
        $primary_field = (string) ($primary[0]->Column_name ?? '');
        if (in_array($primary_field, ['id', 'message_id'], true)) {
            return $primary_field;
        }
    }
    if ($primary) return '';

    $rows = (array) $wpdb->get_results("SHOW COLUMNS FROM `{$table}`");
    $known = [];
    foreach ($rows as $row) {
        $field = (string) ($row->Field ?? '');
        if (in_array($field, ['id', 'message_id'], true)) {
            $known[$field] = $row;
        }
    }

    // If only one historical key name exists, keep that name and let dbDelta
    // repair its PRIMARY/AUTO_INCREMENT attributes in place. Never introduce a
    // second AUTO_INCREMENT candidate into an existing messages table.
    if (isset($known['id']) && !isset($known['message_id'])) return 'id';
    if (isset($known['message_id']) && !isset($known['id'])) return 'message_id';

    // Two unkeyed candidates are ambiguous: fail closed instead of guessing
    // which sequence runtime foreign references point at.
    if (isset($known['id']) && isset($known['message_id'])) return '';
    return 'message_id';
}

function aimee_global_messages_schema_primary_key_ready() {
    global $wpdb;
    if (!aimee_global_schema_table_exists('aimee_messages')) return false;

    $primary = (array) $wpdb->get_results(
        "SHOW INDEX FROM `aimee_messages` WHERE Key_name = 'PRIMARY'"
    );
    if (count($primary) !== 1) return false;
    $field = (string) ($primary[0]->Column_name ?? '');
    if (!in_array($field, ['id', 'message_id'], true)) return false;

    $column = $wpdb->get_row($wpdb->prepare(
        "SHOW COLUMNS FROM `aimee_messages` LIKE %s",
        $wpdb->esc_like($field)
    ));
    return $column
        && (string) ($column->Field ?? '') === $field
        && stripos((string) ($column->Extra ?? ''), 'auto_increment') !== false;
}

function aimee_global_install_core_schema() {
    global $wpdb;
    if (aimee_global_core_schema_health(true)) {
        aimee_global_core_schema_clear_failure();
        return true;
    }
    if (aimee_global_core_schema_backoff_active()) return false;

    $schema_lock = aimee_global_schema_claim_lock('core_schema_' . (
        defined('AIMEE_GLOBAL_SCHEMA_VERSION') ? AIMEE_GLOBAL_SCHEMA_VERSION : 'unknown'
    ));
    if ($schema_lock === '') return false;

    // Another request may have completed the upgrade while this request waited.
    if (aimee_global_core_schema_health(true)) {
        aimee_global_core_schema_clear_failure();
        aimee_global_schema_release_lock($schema_lock);
        return true;
    }

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $cc = $wpdb->get_charset_collate();
    $messages_primary_key = aimee_global_messages_schema_primary_key();
    if (!in_array($messages_primary_key, ['id', 'message_id'], true)) {
        aimee_global_core_schema_record_failure();
        aimee_global_schema_release_lock($schema_lock);
        return false;
    }

    dbDelta("CREATE TABLE aimee_user_profiles (
        user_id BIGINT UNSIGNED NOT NULL,
        first_name VARCHAR(100) NULL,
        age INT NOT NULL DEFAULT 0,
        hobbies MEDIUMTEXT NULL,
        looking_for MEDIUMTEXT NULL,
        appearance_notes MEDIUMTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        profile_image_url VARCHAR(500) NULL,
        phone_number VARCHAR(190) NULL,
        phone_verified_number VARCHAR(190) NULL,
        phone_verified_at DATETIME NULL,
        market VARCHAR(8) NOT NULL DEFAULT 'uk',
        sms_opt_in TINYINT(1) NOT NULL DEFAULT 0,
        sms_timezone VARCHAR(64) NULL,
        sms_safe_start_hour INT NOT NULL DEFAULT 9,
        sms_safe_end_hour INT NOT NULL DEFAULT 17,
        sms_override TINYINT(1) NOT NULL DEFAULT 0,
        wallet_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        active_topup_tier INT NOT NULL DEFAULT 0,
        escort_mode TINYINT(1) NOT NULL DEFAULT 0,
        subscription_status VARCHAR(24) NOT NULL DEFAULT 'trial',
        subscription_plan VARCHAR(24) NULL,
        stripe_customer_id VARCHAR(255) NULL,
        stripe_subscription_id VARCHAR(255) NULL,
        stripe_checkout_session_id VARCHAR(255) NULL,
        billing_provider VARCHAR(32) NULL,
        gocardless_customer_id VARCHAR(255) NULL,
        gocardless_mandate_id VARCHAR(255) NULL,
        gocardless_mandate_scheme VARCHAR(32) NULL,
        gocardless_billing_request_id VARCHAR(255) NULL,
        gocardless_billing_request_flow_id VARCHAR(255) NULL,
        gocardless_payment_id VARCHAR(255) NULL,
        gocardless_payment_status VARCHAR(32) NULL,
        gocardless_last_confirmed_payment_id VARCHAR(255) NULL,
        gocardless_last_payment_at DATETIME NULL,
        gocardless_last_failure_at DATETIME NULL,
        gocardless_next_payment_at DATETIME NULL,
        gocardless_cancelled_at DATETIME NULL,
        gocardless_authorized_plan VARCHAR(24) NULL,
        gocardless_authorized_amount_minor INT UNSIGNED NULL,
        gocardless_authorized_currency CHAR(3) NULL,
        gocardless_renewal_attempt INT UNSIGNED NOT NULL DEFAULT 0,
        gocardless_retry_after DATETIME NULL,
        legacy_stripe_customer_id VARCHAR(255) NULL,
        legacy_stripe_subscription_id VARCHAR(255) NULL,
        legacy_stripe_checkout_session_id VARCHAR(255) NULL,
        legacy_original_period_end DATETIME NULL,
        legacy_membership_end DATETIME NULL,
        billing_migration_status VARCHAR(40) NOT NULL DEFAULT 'none',
        billing_migration_started_at DATETIME NULL,
        billing_migration_completed_at DATETIME NULL,
        billing_account_generation VARCHAR(64) NULL,
        billing_checkout_intent_token VARCHAR(64) NULL,
        billing_checkout_intent_provider VARCHAR(24) NULL,
        billing_checkout_intent_plan VARCHAR(32) NULL,
        billing_checkout_intent_market VARCHAR(8) NULL,
        billing_checkout_intent_generation VARCHAR(64) NULL,
        billing_checkout_intent_status VARCHAR(32) NULL,
        billing_checkout_intent_payload LONGTEXT NULL,
        billing_checkout_lock_until DATETIME NULL,
        billing_checkout_lock_token VARCHAR(64) NULL,
        account_deletion_started_at DATETIME NULL,
        subscription_current_period_start DATETIME NULL,
        subscription_current_period_end DATETIME NULL,
        subscription_cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
        membership_started_at DATETIME NULL,
        membership_bonus_access_until DATETIME NULL,
        service_grace_code VARCHAR(64) NULL,
        service_grace_granted_at DATETIME NULL,
        service_grace_access_until DATETIME NULL,
        trial_message_limit INT NOT NULL DEFAULT 30,
        trial_messages_used INT NOT NULL DEFAULT 0,
        intimacy_score INT NOT NULL DEFAULT 8,
        intimacy_stage VARCHAR(24) NOT NULL DEFAULT 'guarded',
        last_intimacy_route_at DATETIME NULL,
        adult_assurance_status VARCHAR(24) NOT NULL DEFAULT 'self_declared',
        adult_verified_at DATETIME NULL,
        privacy_acknowledged_at DATETIME NULL,
        special_category_consent_at DATETIME NULL,
        special_category_consent_version VARCHAR(64) NULL,
        PRIMARY KEY (user_id),
        UNIQUE KEY uq_aimee_verified_phone (phone_verified_number),
        UNIQUE KEY uq_aimee_stripe_subscription (stripe_subscription_id),
        UNIQUE KEY uq_aimee_gocardless_mandate (gocardless_mandate_id),
        KEY idx_aimee_subscription (subscription_status, subscription_current_period_end),
        KEY idx_aimee_billing_migration (billing_migration_status, legacy_membership_end),
        KEY idx_aimee_billing_generation (billing_account_generation, subscription_status),
        KEY idx_aimee_gocardless_renewal (billing_provider, billing_account_generation, subscription_cancel_at_period_end, gocardless_retry_after, gocardless_next_payment_at),
        KEY idx_aimee_service_grace (service_grace_code, service_grace_access_until),
        KEY idx_aimee_market (market)
    ) ENGINE=InnoDB $cc;");

    dbDelta("CREATE TABLE aimee_gocardless_events (
        event_id VARCHAR(255) NOT NULL,
        resource_type VARCHAR(64) NOT NULL,
        action VARCHAR(64) NOT NULL,
        processed_at DATETIME NOT NULL,
        PRIMARY KEY (event_id),
        KEY idx_gc_resource_action (resource_type, action)
    ) ENGINE=InnoDB $cc;");

    dbDelta("CREATE TABLE aimee_gocardless_payments (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        provider_payment_id VARCHAR(255) NULL,
        idempotency_key VARCHAR(255) NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        mandate_id VARCHAR(255) NOT NULL,
        billing_request_id VARCHAR(255) NULL,
        plan VARCHAR(24) NOT NULL,
        amount_minor INT UNSIGNED NOT NULL,
        currency CHAR(3) NOT NULL,
        cycle_key VARCHAR(64) NOT NULL,
        attempt INT UNSIGNED NOT NULL DEFAULT 1,
        reason VARCHAR(32) NOT NULL,
        status VARCHAR(32) NOT NULL,
        claim_token VARCHAR(64) NULL,
        claim_expires_at DATETIME NULL,
        applied_at DATETIME NULL,
        period_start DATETIME NULL,
        period_end DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_aimee_gc_provider_payment (provider_payment_id),
        UNIQUE KEY uq_aimee_gc_idempotency (idempotency_key),
        UNIQUE KEY uq_aimee_gc_cycle_attempt (user_id, mandate_id, cycle_key, attempt),
        KEY idx_aimee_gc_user_status (user_id, status),
        KEY idx_aimee_gc_mandate_status (mandate_id, status),
        KEY idx_aimee_gc_claim_expiry (claim_expires_at)
    ) ENGINE=InnoDB $cc;");

    dbDelta("CREATE TABLE aimee_messages (
        {$messages_primary_key} BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        sender VARCHAR(50) NOT NULL,
        message_text LONGTEXT NULL,
        image_url VARCHAR(500) NULL,
        user_image_fingerprint CHAR(64) NULL,
        user_image_event VARCHAR(32) NULL,
        user_image_event_id VARCHAR(96) NULL,
        evaluator_directive TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        is_sms TINYINT(1) NOT NULL DEFAULT 0,
        voice_note_token VARCHAR(64) NULL,
        voice_note_duration_ms INT NULL,
        voice_note_mime VARCHAR(80) NULL,
        media_decision_id CHAR(36) NULL,
        media_delivery_id CHAR(36) NULL,
        PRIMARY KEY ({$messages_primary_key}),
        KEY idx_aimee_messages_user_created (user_id, created_at),
        KEY idx_aimee_messages_user_sender_created (user_id, sender, created_at),
        KEY idx_aimee_messages_user_image (user_id, user_image_fingerprint, created_at),
        KEY idx_aimee_messages_media_decision (media_decision_id),
        KEY idx_aimee_messages_media_delivery (media_delivery_id)
    ) ENGINE=InnoDB $cc;");

    dbDelta("CREATE TABLE aimee_long_term_memory (
        memory_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        memory_fact MEDIUMTEXT NOT NULL,
        memory_key VARCHAR(190) NULL DEFAULT NULL,
        memory_domain VARCHAR(50) NOT NULL DEFAULT 'user_fact',
        emotional_weight INT NOT NULL DEFAULT 0,
        consolidation_status VARCHAR(20) NOT NULL DEFAULT 'consolidated',
        confidence DECIMAL(4,3) NOT NULL DEFAULT 0.850,
        source_message_id BIGINT UNSIGNED NULL DEFAULT NULL,
        supersedes_memory_id BIGINT UNSIGNED NULL DEFAULT NULL,
        valid_until DATETIME NULL DEFAULT NULL,
        last_recalled_at DATETIME NULL DEFAULT NULL,
        recall_count INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL,
        PRIMARY KEY (memory_id),
        KEY idx_aimee_memory_domain (user_id, memory_domain, consolidation_status, created_at),
        KEY idx_aimee_memory_key (user_id, memory_key)
    ) ENGINE=InnoDB $cc;");

    dbDelta("CREATE TABLE aimee_relationship_state (
        user_id BIGINT UNSIGNED NOT NULL,
        overall_equity INT NOT NULL DEFAULT 50,
        inquiry_ratio INT NOT NULL DEFAULT 50,
        fantasy_imposition INT NOT NULL DEFAULT 0,
        last_interaction DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id)
    ) ENGINE=InnoDB $cc;");

    dbDelta("CREATE TABLE aimee_relationship_dimensions (
        user_id BIGINT UNSIGNED NOT NULL,
        trust SMALLINT UNSIGNED NOT NULL DEFAULT 20,
        affection SMALLINT UNSIGNED NOT NULL DEFAULT 20,
        chemistry SMALLINT UNSIGNED NOT NULL DEFAULT 8,
        safety SMALLINT UNSIGNED NOT NULL DEFAULT 50,
        reciprocity SMALLINT UNSIGNED NOT NULL DEFAULT 50,
        reliability SMALLINT UNSIGNED NOT NULL DEFAULT 50,
        frustration SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        interaction_count INT UNSIGNED NOT NULL DEFAULT 0,
        meaningful_interaction_count INT UNSIGNED NOT NULL DEFAULT 0,
        session_count INT UNSIGNED NOT NULL DEFAULT 0,
        qualified_session_count INT UNSIGNED NOT NULL DEFAULT 0,
        last_qualified_session_number INT UNSIGNED NOT NULL DEFAULT 0,
        last_session_at DATETIME NULL,
        state_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
        last_message_fingerprint CHAR(64) NULL,
        message_fingerprint_history_json LONGTEXT NULL,
        repeat_streak INT UNSIGNED NOT NULL DEFAULT 0,
        last_signal_signature CHAR(64) NULL,
        signal_history_json LONGTEXT NULL,
        signal_repeat_streak INT UNSIGNED NOT NULL DEFAULT 0,
        last_interaction_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id)
    ) ENGINE=InnoDB $cc;");

    dbDelta("CREATE TABLE aimee_relationship_decisions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        decision_id CHAR(36) NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        request_id VARCHAR(80) NULL,
        user_message_id BIGINT UNSIGNED NULL,
        policy_version VARCHAR(24) NOT NULL,
        math_source VARCHAR(32) NOT NULL,
        classifier_source VARCHAR(64) NULL,
        classifier_json LONGTEXT NULL,
        state_before_json LONGTEXT NOT NULL,
        matched_signals_json LONGTEXT NOT NULL,
        applied_delta_json LONGTEXT NOT NULL,
        rejected_signals_json LONGTEXT NULL,
        state_after_json LONGTEXT NOT NULL,
        score_before SMALLINT UNSIGNED NOT NULL,
        score_after SMALLINT UNSIGNED NOT NULL,
        stage_before VARCHAR(24) NOT NULL,
        stage_after VARCHAR(24) NOT NULL,
        route_decision_json LONGTEXT NULL,
        actual_route VARCHAR(48) NULL,
        actual_model VARCHAR(190) NULL,
        actual_provider VARCHAR(80) NULL,
        model_attempts_json LONGTEXT NULL,
        state_commit_status VARCHAR(24) NOT NULL DEFAULT 'unknown',
        media_decision_id CHAR(36) NULL,
        consumed_invitation_token_id CHAR(36) NULL,
        issued_invitation_token_id CHAR(36) NULL,
        score_delta_proposed SMALLINT NOT NULL DEFAULT 0,
        score_delta_applied SMALLINT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_aimee_relationship_decision (decision_id),
        KEY idx_aimee_relationship_user_created (user_id, created_at),
        KEY idx_aimee_relationship_message (user_message_id),
        KEY idx_aimee_relationship_media_decision (media_decision_id),
        KEY idx_aimee_relationship_request (user_id, request_id)
    ) ENGINE=InnoDB $cc;");

    dbDelta("CREATE TABLE aimee_turn_requests (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        request_id VARCHAR(80) NOT NULL,
        user_message_id BIGINT UNSIGNED NULL,
        relationship_decision_id CHAR(36) NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'processing',
        response_json LONGTEXT NULL,
        error_code VARCHAR(80) NULL,
        reserved_at DATETIME NOT NULL,
        state_committed_at DATETIME NULL,
        completed_at DATETIME NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_aimee_turn_request (user_id, request_id),
        KEY idx_aimee_turn_request_status (status, updated_at),
        KEY idx_aimee_turn_request_message (user_message_id),
        KEY idx_aimee_turn_request_decision (relationship_decision_id)
    ) ENGINE=InnoDB $cc;");

    dbDelta("CREATE TABLE aimee_relationship_invitations (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        token_id CHAR(36) NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        conversation_id VARCHAR(80) NOT NULL,
        issued_by VARCHAR(24) NOT NULL DEFAULT 'aimee',
        invitation_type VARCHAR(24) NOT NULL,
        max_rating VARCHAR(24) NOT NULL,
        source_message_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'active',
        issued_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        consumed_at DATETIME NULL,
        consumed_by_request_id VARCHAR(80) NULL,
        revoked_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_aimee_relationship_invitation (token_id),
        KEY idx_aimee_invitation_active (user_id, status, expires_at),
        KEY idx_aimee_invitation_source (source_message_id)
    ) ENGINE=InnoDB $cc;");

    dbDelta("CREATE TABLE aimee_media_decisions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        decision_id CHAR(36) NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        request_id VARCHAR(80) NULL,
        turn_id VARCHAR(80) NULL,
        source VARCHAR(24) NOT NULL,
        policy_version VARCHAR(24) NOT NULL,
        model_route VARCHAR(48) NOT NULL,
        actual_model VARCHAR(190) NULL,
        actual_provider VARCHAR(80) NULL,
        model_attempt INT UNSIGNED NOT NULL DEFAULT 1,
        direct_request TINYINT(1) NOT NULL DEFAULT 0,
        requested_rating VARCHAR(24) NULL,
        media_opportunity TINYINT(1) NOT NULL DEFAULT 0,
        maximum_rating VARCHAR(24) NOT NULL DEFAULT 'none',
        reason_code VARCHAR(80) NOT NULL,
        reason_text VARCHAR(255) NULL,
        proactive_allowed TINYINT(1) NOT NULL DEFAULT 0,
        cooldown_clear TINYINT(1) NOT NULL DEFAULT 0,
        access_state VARCHAR(32) NOT NULL,
        adult_assurance VARCHAR(32) NOT NULL,
        mutual_context TINYINT(1) NOT NULL DEFAULT 0,
        pressure_detected TINYINT(1) NOT NULL DEFAULT 0,
        eligible_keys_json LONGTEXT NOT NULL,
        excluded_keys_json LONGTEXT NULL,
        relationship_snapshot_json LONGTEXT NOT NULL,
        policy_snapshot_json LONGTEXT NULL,
        aimee_decision VARCHAR(24) NOT NULL DEFAULT 'consider',
        selected_key VARCHAR(190) NULL,
        decision_reason_code VARCHAR(80) NULL,
        user_message_id BIGINT UNSIGNED NULL,
        aimee_message_id BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_aimee_media_decision (decision_id),
        KEY idx_aimee_media_user_created (user_id, created_at),
        KEY idx_aimee_media_messages (user_message_id, aimee_message_id),
        KEY idx_aimee_media_turn (turn_id),
        KEY idx_aimee_media_request (user_id, request_id)
    ) ENGINE=InnoDB $cc;");

    dbDelta("CREATE TABLE aimee_media_deliveries (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        delivery_id CHAR(36) NOT NULL,
        decision_id CHAR(36) NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        message_id BIGINT UNSIGNED NULL,
        media_key VARCHAR(190) NOT NULL,
        current_state VARCHAR(40) NOT NULL DEFAULT 'selected',
        selected_at DATETIME NULL,
        catalogue_resolved_at DATETIME NULL,
        authorised_at DATETIME NULL,
        file_resolved_at DATETIME NULL,
        resolved_asset_source VARCHAR(40) NULL,
        resolved_asset_job_id BIGINT UNSIGNED NULL,
        resolved_asset_sha256 CHAR(64) NULL,
        resolved_asset_mime VARCHAR(80) NULL,
        message_created_at DATETIME NULL,
        returned_by_direct_api_at DATETIME NULL,
        returned_by_history_api_at DATETIME NULL,
        asset_requested_at DATETIME NULL,
        asset_completed_at DATETIME NULL,
        rendered_by_client_at DATETIME NULL,
        acknowledged_by_client_at DATETIME NULL,
        user_responded_at DATETIME NULL,
        user_response_message_id BIGINT UNSIGNED NULL,
        user_response_evidence VARCHAR(100) NULL,
        render_failed_at DATETIME NULL,
        failed_at DATETIME NULL,
        error_code VARCHAR(100) NULL,
        attempt INT UNSIGNED NOT NULL DEFAULT 1,
        client_instance_id VARCHAR(100) NULL,
        client_version VARCHAR(40) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_aimee_media_delivery (delivery_id),
        UNIQUE KEY uq_aimee_delivery_decision_attempt (decision_id, attempt),
        KEY idx_aimee_delivery_message (message_id),
        KEY idx_aimee_delivery_response_message (user_response_message_id),
        KEY idx_aimee_delivery_user_state (user_id, current_state)
    ) ENGINE=InnoDB $cc;");

    dbDelta("CREATE TABLE aimee_media_delivery_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        delivery_id CHAR(36) NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        state VARCHAR(40) NOT NULL,
        error_code VARCHAR(100) NULL,
        details_json LONGTEXT NULL,
        occurred_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_aimee_delivery_event (delivery_id, state),
        KEY idx_aimee_delivery_events_user (user_id, occurred_at)
    ) ENGINE=InnoDB $cc;");

    dbDelta("CREATE TABLE aimee_sms_usage (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        message_id BIGINT UNSIGNED NULL,
        source VARCHAR(64) NOT NULL,
        allowance_bucket VARCHAR(24) NOT NULL,
        segments INT NOT NULL DEFAULT 1,
        sent_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY user_sent (user_id, sent_at),
        KEY allowance_bucket (allowance_bucket)
    ) ENGINE=InnoDB $cc;");

    dbDelta("CREATE TABLE aimee_sms_outbound_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        send_key CHAR(64) NOT NULL,
        provider_reference VARCHAR(32) NOT NULL,
        provider_message_id VARCHAR(190) NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        message_id BIGINT UNSIGNED NULL,
        source VARCHAR(64) NOT NULL,
        destination_hash CHAR(64) NOT NULL,
        intent_hash CHAR(64) NOT NULL,
        message_hash CHAR(64) NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'selected',
        allowance_bucket VARCHAR(24) NULL,
        allowance_period_start DATETIME NULL,
        allowance_period_end DATETIME NULL,
        quota_disposition VARCHAR(24) NOT NULL DEFAULT 'none',
        segments INT NOT NULL DEFAULT 1,
        provider_code VARCHAR(32) NULL,
        provider_detail TEXT NULL,
        usage_recorded TINYINT(1) NOT NULL DEFAULT 0,
        audit_error VARCHAR(100) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        sending_at DATETIME NULL,
        queued_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_aimee_sms_outbound_send (send_key),
        UNIQUE KEY uq_aimee_sms_outbound_reference (provider_reference),
        KEY idx_aimee_sms_outbound_user (user_id, created_at),
        KEY idx_aimee_sms_outbound_status (status, updated_at)
    ) ENGINE=InnoDB $cc;");

    dbDelta("CREATE TABLE aimee_sms_inbound_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_key CHAR(64) NOT NULL,
        event_ref VARCHAR(190) NOT NULL,
        dedupe_basis VARCHAR(32) NOT NULL,
        source_number VARCHAR(32) NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        request_id VARCHAR(80) NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'processing',
        lease_token VARCHAR(64) NOT NULL,
        attempt_count INT UNSIGNED NOT NULL DEFAULT 1,
        response_json LONGTEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        completed_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_aimee_sms_inbound_event (event_key),
        KEY idx_aimee_sms_inbound_user (user_id, created_at),
        KEY idx_aimee_sms_inbound_status (status, updated_at)
    ) ENGINE=InnoDB $cc;");

    $core_tables = [
        'aimee_user_profiles',
        'aimee_gocardless_events',
        'aimee_gocardless_payments',
        'aimee_messages',
        'aimee_long_term_memory',
        'aimee_relationship_state',
        'aimee_relationship_dimensions',
        'aimee_relationship_decisions',
        'aimee_turn_requests',
        'aimee_relationship_invitations',
        'aimee_media_decisions',
        'aimee_media_deliveries',
        'aimee_media_delivery_events',
        'aimee_sms_usage',
        'aimee_sms_outbound_events',
        'aimee_sms_inbound_events',
    ];
    aimee_global_schema_ensure_innodb($core_tables);

    $healthy = aimee_global_core_schema_health(true);
    if ($healthy) {
        aimee_global_core_schema_clear_failure();
    } else {
        aimee_global_core_schema_record_failure();
    }
    aimee_global_schema_release_lock($schema_lock);
    return $healthy;
}

/**
 * Verify the immutable media-asset binding required by delivery
 * materialization. A partial dbDelta must fail closed before a sidecar can
 * reserve work that core cannot later bind to one exact delivery.
 */
function aimee_media_materialization_schema_ready($refresh = false) {
    global $wpdb;

    static $ready = null;
    if (!$refresh && $ready !== null) return $ready;

    $ready = false;
    if (!isset($wpdb) || !is_object($wpdb)) return $ready;

    $table = 'aimee_media_deliveries';
    if (
        $wpdb->get_var($wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $wpdb->esc_like($table)
        )) !== $table
    ) {
        return $ready;
    }

    $required = [
        'delivery_id',
        'decision_id',
        'user_id',
        'media_key',
        'current_state',
        'authorised_at',
        'file_resolved_at',
        'message_created_at',
        'failed_at',
        'resolved_asset_source',
        'resolved_asset_job_id',
        'resolved_asset_sha256',
        'resolved_asset_mime',
    ];
    $present = array_map('strval', (array) $wpdb->get_col(
        "SHOW COLUMNS FROM `$table`",
        0
    ));
    if (array_diff($required, $present)) return $ready;

    $delivery_unique = false;
    $indexes = array_values((array) $wpdb->get_results(
        "SHOW INDEX FROM `$table` WHERE Key_name = 'uq_aimee_media_delivery'"
    ));
    if (count($indexes) === 1) {
        $index = $indexes[0];
        $delivery_unique = (string) ($index->Column_name ?? '') === 'delivery_id'
            && intval($index->Seq_in_index ?? 0) === 1
            && intval($index->Non_unique ?? 1) === 0;
    }

    $ready = $delivery_unique;
    return $ready;
}

/** Complete core contract; no release option may advance unless every item passes. */
function aimee_global_core_schema_health($refresh = false) {
    static $healthy = null;
    if ($refresh) {
        $healthy = null;
        aimee_global_schema_health_cache_forget('core');
    } elseif ($healthy !== null) {
        return $healthy;
    } elseif (aimee_global_schema_health_cache_get('core')) {
        $healthy = true;
        return $healthy;
    }
    $healthy = false;

    $c = static function ($columns) {
        return preg_split('/\s+/', trim($columns));
    };
    $pk = static function ($column) {
        return ['PRIMARY' => ['unique' => true, 'columns' => [$column]]];
    };
    $contracts = [
        'aimee_user_profiles' => [
            'columns' => $c('user_id first_name age hobbies looking_for appearance_notes created_at profile_image_url phone_number phone_verified_number phone_verified_at market sms_opt_in sms_timezone sms_safe_start_hour sms_safe_end_hour sms_override wallet_balance active_topup_tier escort_mode subscription_status subscription_plan stripe_customer_id stripe_subscription_id stripe_checkout_session_id billing_provider gocardless_customer_id gocardless_mandate_id gocardless_mandate_scheme gocardless_billing_request_id gocardless_billing_request_flow_id gocardless_payment_id gocardless_payment_status gocardless_last_confirmed_payment_id gocardless_last_payment_at gocardless_last_failure_at gocardless_next_payment_at gocardless_cancelled_at gocardless_authorized_plan gocardless_authorized_amount_minor gocardless_authorized_currency gocardless_renewal_attempt gocardless_retry_after legacy_stripe_customer_id legacy_stripe_subscription_id legacy_stripe_checkout_session_id legacy_original_period_end legacy_membership_end billing_migration_status billing_migration_started_at billing_migration_completed_at billing_account_generation billing_checkout_intent_token billing_checkout_intent_provider billing_checkout_intent_plan billing_checkout_intent_market billing_checkout_intent_generation billing_checkout_intent_status billing_checkout_intent_payload billing_checkout_lock_until billing_checkout_lock_token account_deletion_started_at subscription_current_period_start subscription_current_period_end subscription_cancel_at_period_end membership_started_at membership_bonus_access_until service_grace_code service_grace_granted_at service_grace_access_until trial_message_limit trial_messages_used intimacy_score intimacy_stage last_intimacy_route_at adult_assurance_status adult_verified_at privacy_acknowledged_at special_category_consent_at special_category_consent_version'),
            'indexes' => $pk('user_id') + [
                'uq_aimee_verified_phone' => ['unique' => true, 'columns' => ['phone_verified_number']],
                'uq_aimee_stripe_subscription' => ['unique' => true, 'columns' => ['stripe_subscription_id']],
                'uq_aimee_gocardless_mandate' => ['unique' => true, 'columns' => ['gocardless_mandate_id']],
                'idx_aimee_subscription' => ['unique' => false, 'columns' => ['subscription_status', 'subscription_current_period_end']],
                'idx_aimee_billing_migration' => ['unique' => false, 'columns' => ['billing_migration_status', 'legacy_membership_end']],
                'idx_aimee_billing_generation' => ['unique' => false, 'columns' => ['billing_account_generation', 'subscription_status']],
                'idx_aimee_gocardless_renewal' => ['unique' => false, 'columns' => ['billing_provider', 'billing_account_generation', 'subscription_cancel_at_period_end', 'gocardless_retry_after', 'gocardless_next_payment_at']],
                'idx_aimee_service_grace' => ['unique' => false, 'columns' => ['service_grace_code', 'service_grace_access_until']],
                'idx_aimee_market' => ['unique' => false, 'columns' => ['market']],
            ],
        ],
        'aimee_gocardless_events' => [
            'columns' => $c('event_id resource_type action processed_at'),
            'indexes' => $pk('event_id') + [
                'idx_gc_resource_action' => ['unique' => false, 'columns' => ['resource_type', 'action']],
            ],
        ],
        'aimee_gocardless_payments' => [
            'columns' => $c('id provider_payment_id idempotency_key user_id mandate_id billing_request_id plan amount_minor currency cycle_key attempt reason status claim_token claim_expires_at applied_at period_start period_end created_at updated_at'),
            'indexes' => $pk('id') + [
                'uq_aimee_gc_provider_payment' => ['unique' => true, 'columns' => ['provider_payment_id']],
                'uq_aimee_gc_idempotency' => ['unique' => true, 'columns' => ['idempotency_key']],
                'uq_aimee_gc_cycle_attempt' => ['unique' => true, 'columns' => ['user_id', 'mandate_id', 'cycle_key', 'attempt']],
                'idx_aimee_gc_user_status' => ['unique' => false, 'columns' => ['user_id', 'status']],
                'idx_aimee_gc_mandate_status' => ['unique' => false, 'columns' => ['mandate_id', 'status']],
                'idx_aimee_gc_claim_expiry' => ['unique' => false, 'columns' => ['claim_expires_at']],
            ],
        ],
        'aimee_messages' => [
            'columns' => $c('user_id sender message_text image_url user_image_fingerprint user_image_event user_image_event_id evaluator_directive created_at is_sms voice_note_token voice_note_duration_ms voice_note_mime media_decision_id media_delivery_id'),
            'indexes' => [
                'idx_aimee_messages_user_created' => ['unique' => false, 'columns' => ['user_id', 'created_at']],
                'idx_aimee_messages_user_sender_created' => ['unique' => false, 'columns' => ['user_id', 'sender', 'created_at']],
                'idx_aimee_messages_user_image' => ['unique' => false, 'columns' => ['user_id', 'user_image_fingerprint', 'created_at']],
                'idx_aimee_messages_media_decision' => ['unique' => false, 'columns' => ['media_decision_id']],
                'idx_aimee_messages_media_delivery' => ['unique' => false, 'columns' => ['media_delivery_id']],
            ],
        ],
        'aimee_long_term_memory' => [
            'columns' => $c('memory_id user_id memory_fact memory_key memory_domain emotional_weight consolidation_status confidence source_message_id supersedes_memory_id valid_until last_recalled_at recall_count created_at updated_at'),
            'indexes' => $pk('memory_id') + [
                'idx_aimee_memory_domain' => ['unique' => false, 'columns' => ['user_id', 'memory_domain', 'consolidation_status', 'created_at']],
                'idx_aimee_memory_key' => ['unique' => false, 'columns' => ['user_id', 'memory_key']],
            ],
        ],
        'aimee_relationship_state' => [
            'columns' => $c('user_id overall_equity inquiry_ratio fantasy_imposition last_interaction'),
            'indexes' => $pk('user_id'),
        ],
        'aimee_relationship_dimensions' => [
            'columns' => $c('user_id trust affection chemistry safety reciprocity reliability frustration interaction_count meaningful_interaction_count session_count qualified_session_count last_qualified_session_number last_session_at state_version last_message_fingerprint message_fingerprint_history_json repeat_streak last_signal_signature signal_history_json signal_repeat_streak last_interaction_at created_at updated_at'),
            'indexes' => $pk('user_id'),
        ],
        'aimee_relationship_decisions' => [
            'columns' => $c('id decision_id user_id request_id user_message_id policy_version math_source classifier_source classifier_json state_before_json matched_signals_json applied_delta_json rejected_signals_json state_after_json score_before score_after stage_before stage_after route_decision_json actual_route actual_model actual_provider model_attempts_json state_commit_status media_decision_id consumed_invitation_token_id issued_invitation_token_id score_delta_proposed score_delta_applied created_at updated_at'),
            'indexes' => $pk('id') + [
                'uq_aimee_relationship_decision' => ['unique' => true, 'columns' => ['decision_id']],
                'idx_aimee_relationship_user_created' => ['unique' => false, 'columns' => ['user_id', 'created_at']],
                'idx_aimee_relationship_message' => ['unique' => false, 'columns' => ['user_message_id']],
                'idx_aimee_relationship_media_decision' => ['unique' => false, 'columns' => ['media_decision_id']],
                'idx_aimee_relationship_request' => ['unique' => false, 'columns' => ['user_id', 'request_id']],
            ],
        ],
        'aimee_turn_requests' => [
            'columns' => $c('id user_id request_id user_message_id relationship_decision_id status response_json error_code reserved_at state_committed_at completed_at updated_at'),
            'indexes' => $pk('id') + [
                'uq_aimee_turn_request' => ['unique' => true, 'columns' => ['user_id', 'request_id']],
                'idx_aimee_turn_request_status' => ['unique' => false, 'columns' => ['status', 'updated_at']],
                'idx_aimee_turn_request_message' => ['unique' => false, 'columns' => ['user_message_id']],
                'idx_aimee_turn_request_decision' => ['unique' => false, 'columns' => ['relationship_decision_id']],
            ],
        ],
        'aimee_relationship_invitations' => [
            'columns' => $c('id token_id user_id conversation_id issued_by invitation_type max_rating source_message_id status issued_at expires_at consumed_at consumed_by_request_id revoked_at'),
            'indexes' => $pk('id') + [
                'uq_aimee_relationship_invitation' => ['unique' => true, 'columns' => ['token_id']],
                'idx_aimee_invitation_active' => ['unique' => false, 'columns' => ['user_id', 'status', 'expires_at']],
                'idx_aimee_invitation_source' => ['unique' => false, 'columns' => ['source_message_id']],
            ],
        ],
        'aimee_media_decisions' => [
            'columns' => $c('id decision_id user_id request_id turn_id source policy_version model_route actual_model actual_provider model_attempt direct_request requested_rating media_opportunity maximum_rating reason_code reason_text proactive_allowed cooldown_clear access_state adult_assurance mutual_context pressure_detected eligible_keys_json excluded_keys_json relationship_snapshot_json policy_snapshot_json aimee_decision selected_key decision_reason_code user_message_id aimee_message_id created_at updated_at'),
            'indexes' => $pk('id') + [
                'uq_aimee_media_decision' => ['unique' => true, 'columns' => ['decision_id']],
                'idx_aimee_media_user_created' => ['unique' => false, 'columns' => ['user_id', 'created_at']],
                'idx_aimee_media_messages' => ['unique' => false, 'columns' => ['user_message_id', 'aimee_message_id']],
                'idx_aimee_media_turn' => ['unique' => false, 'columns' => ['turn_id']],
                'idx_aimee_media_request' => ['unique' => false, 'columns' => ['user_id', 'request_id']],
            ],
        ],
        'aimee_media_deliveries' => [
            'columns' => $c('id delivery_id decision_id user_id message_id media_key current_state selected_at catalogue_resolved_at authorised_at file_resolved_at resolved_asset_source resolved_asset_job_id resolved_asset_sha256 resolved_asset_mime message_created_at returned_by_direct_api_at returned_by_history_api_at asset_requested_at asset_completed_at rendered_by_client_at acknowledged_by_client_at user_responded_at user_response_message_id user_response_evidence render_failed_at failed_at error_code attempt client_instance_id client_version created_at updated_at'),
            'indexes' => $pk('id') + [
                'uq_aimee_media_delivery' => ['unique' => true, 'columns' => ['delivery_id']],
                'uq_aimee_delivery_decision_attempt' => ['unique' => true, 'columns' => ['decision_id', 'attempt']],
                'idx_aimee_delivery_message' => ['unique' => false, 'columns' => ['message_id']],
                'idx_aimee_delivery_response_message' => ['unique' => false, 'columns' => ['user_response_message_id']],
                'idx_aimee_delivery_user_state' => ['unique' => false, 'columns' => ['user_id', 'current_state']],
            ],
        ],
        'aimee_media_delivery_events' => [
            'columns' => $c('id delivery_id user_id state error_code details_json occurred_at'),
            'indexes' => $pk('id') + [
                'uq_aimee_delivery_event' => ['unique' => true, 'columns' => ['delivery_id', 'state']],
                'idx_aimee_delivery_events_user' => ['unique' => false, 'columns' => ['user_id', 'occurred_at']],
            ],
        ],
        'aimee_sms_usage' => [
            'columns' => $c('id user_id message_id source allowance_bucket segments sent_at'),
            'indexes' => $pk('id') + [
                'user_sent' => ['unique' => false, 'columns' => ['user_id', 'sent_at']],
                'allowance_bucket' => ['unique' => false, 'columns' => ['allowance_bucket']],
            ],
        ],
        'aimee_sms_outbound_events' => [
            'columns' => $c('id send_key provider_reference provider_message_id user_id message_id source destination_hash intent_hash message_hash status allowance_bucket allowance_period_start allowance_period_end quota_disposition segments provider_code provider_detail usage_recorded audit_error created_at updated_at sending_at queued_at'),
            'indexes' => $pk('id') + [
                'uq_aimee_sms_outbound_send' => ['unique' => true, 'columns' => ['send_key']],
                'uq_aimee_sms_outbound_reference' => ['unique' => true, 'columns' => ['provider_reference']],
                'idx_aimee_sms_outbound_user' => ['unique' => false, 'columns' => ['user_id', 'created_at']],
                'idx_aimee_sms_outbound_status' => ['unique' => false, 'columns' => ['status', 'updated_at']],
            ],
        ],
        'aimee_sms_inbound_events' => [
            'columns' => $c('id event_key event_ref dedupe_basis source_number user_id request_id status lease_token attempt_count response_json created_at updated_at completed_at'),
            'indexes' => $pk('id') + [
                'uq_aimee_sms_inbound_event' => ['unique' => true, 'columns' => ['event_key']],
                'idx_aimee_sms_inbound_user' => ['unique' => false, 'columns' => ['user_id', 'created_at']],
                'idx_aimee_sms_inbound_status' => ['unique' => false, 'columns' => ['status', 'updated_at']],
            ],
        ],
    ];

    foreach ($contracts as $table => $contract) {
        if (!aimee_global_schema_table_contract_ready(
            $table,
            $contract['columns'],
            $contract['indexes'],
            true
        )) {
            return $healthy;
        }
    }
    if (!aimee_global_messages_schema_primary_key_ready()) return $healthy;

    $healthy = aimee_media_materialization_schema_ready($refresh);
    if ($healthy) aimee_global_schema_health_cache_set('core');
    return $healthy;
}
