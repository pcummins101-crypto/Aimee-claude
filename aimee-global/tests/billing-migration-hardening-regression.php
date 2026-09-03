<?php
/** Executable failure/retry regression for the closed Stripe generation migration. */

define('ABSPATH', '/aimee/');
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);

$GLOBALS['aimee_test_options'] = [];
$GLOBALS['aimee_test_core_health'] = true;
$GLOBALS['aimee_test_lock_claims'] = 0;
$GLOBALS['aimee_test_lock_releases'] = 0;

class WP_Error {
    private $code;
    private $message;
    private $data;
    public function __construct($code, $message, $data = null) {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}

function is_wp_error($value) { return $value instanceof WP_Error; }
function sanitize_key($value) {
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value));
}
function sanitize_text_field($value) { return trim((string) $value); }
function current_time($type, $gmt = false) { return '2026-08-20 12:00:00'; }
function apply_filters($name, $value) { return $value; }
function get_option($name, $default = false) {
    return array_key_exists($name, $GLOBALS['aimee_test_options'])
        ? $GLOBALS['aimee_test_options'][$name]
        : $default;
}
function update_option($name, $value, $autoload = null) {
    $GLOBALS['aimee_test_options'][$name] = $value;
    return true;
}
function aimee_global_core_schema_health($refresh = false) {
    return $GLOBALS['aimee_test_core_health'];
}
function aimee_global_schema_claim_lock($purpose, $ttl = 300) {
    $GLOBALS['aimee_test_lock_claims']++;
    return 'db:test';
}
function aimee_global_schema_release_lock($claim) {
    $GLOBALS['aimee_test_lock_releases']++;
}
function aimee_global_current_billing_generation() { return 'stripe_2026_09_v1'; }
function aimee_gocardless_generation() { return 'gocardless_2026_08_v1'; }

class Aimee_Billing_Test_WPDB {
    public $last_error = '';
    public $profiles = [];
    public $select_failure = false;
    public $start_failure = false;
    public $update_failure = false;
    public $commit_failure = false;
    public $queries = [];
    public $prepared_arguments = [];

    public function prepare($sql, ...$arguments) {
        $this->prepared_arguments[] = $arguments;
        return $sql;
    }

    public function get_results($sql) {
        $this->queries[] = trim($sql);
        if ($this->select_failure) {
            $this->last_error = 'simulated SELECT failure';
            return null;
        }
        return $this->profiles;
    }

    public function query($sql) {
        $sql = trim($sql);
        $this->queries[] = $sql;
        if ($sql === 'START TRANSACTION') return $this->start_failure ? false : 1;
        if ($sql === 'COMMIT') return $this->commit_failure ? false : 1;
        if ($sql === 'ROLLBACK') return 1;
        if (strpos($sql, 'UPDATE `aimee_user_profiles`') === 0) {
            return $this->update_failure ? false : 1;
        }
        return 1;
    }
}

require '/aimee/includes/billing-migration.php';

$passes = 0;
$failures = 0;
function aimee_test_check($condition, $label) {
    global $passes, $failures;
    if ($condition) {
        $passes++;
        echo "PASS {$label}\n";
    } else {
        $failures++;
        echo "FAIL {$label}\n";
    }
}

function aimee_test_reset() {
    global $wpdb;
    $GLOBALS['aimee_test_options'] = [];
    $GLOBALS['aimee_test_core_health'] = true;
    $GLOBALS['aimee_test_lock_claims'] = 0;
    $GLOBALS['aimee_test_lock_releases'] = 0;
    $wpdb = new Aimee_Billing_Test_WPDB();
    return $wpdb;
}

function aimee_test_profile(array $overrides = []) {
    return (object) array_merge([
        'user_id' => 42,
        'subscription_status' => 'active',
        'subscription_plan' => 'monthly',
        'membership_started_at' => '2026-01-15 00:00:00',
        'stripe_customer_id' => 'cus_closed',
        'stripe_subscription_id' => 'sub_closed',
        'stripe_checkout_session_id' => 'cs_closed',
        'subscription_current_period_end' => '2026-09-15 00:00:00',
        'billing_provider' => 'stripe',
        'billing_account_generation' => null,
        'billing_migration_status' => 'none',
    ], $overrides);
}

$wpdb = aimee_test_reset();
$GLOBALS['aimee_test_core_health'] = false;
$result = aimee_global_migrate_legacy_stripe_profiles();
$summary = get_option(aimee_global_billing_migration_option_name(), []);
aimee_test_check(
    is_wp_error($result)
    && $result->get_error_code() === 'aimee_billing_migration_schema_unavailable'
    && empty($summary['completed_at'])
    && !in_array('START TRANSACTION', $wpdb->queries, true),
    'missing or non-InnoDB schema remains retryable without touching billing rows'
);

$wpdb = aimee_test_reset();
$wpdb->select_failure = true;
$result = aimee_global_migrate_legacy_stripe_profiles();
aimee_test_check(
    is_wp_error($result)
    && $result->get_error_code() === 'aimee_billing_migration_select_failed'
    && in_array('ROLLBACK', $wpdb->queries, true)
    && empty(get_option(aimee_global_billing_migration_option_name(), [])['completed_at']),
    'failed locked SELECT rolls back and cannot write a completion marker'
);

$wpdb = aimee_test_reset();
$wpdb->profiles = [aimee_test_profile()];
$wpdb->update_failure = true;
$result = aimee_global_migrate_legacy_stripe_profiles();
aimee_test_check(
    is_wp_error($result)
    && $result->get_error_code() === 'aimee_billing_migration_failed'
    && in_array('ROLLBACK', $wpdb->queries, true)
    && !in_array('COMMIT', $wpdb->queries, true),
    'a failed archive update aborts the complete transaction'
);

$wpdb = aimee_test_reset();
$wpdb->profiles = [aimee_test_profile([
    'billing_provider' => 'gocardless',
    'billing_account_generation' => 'unknown_generation',
])];
$result = aimee_global_migrate_legacy_stripe_profiles();
$summary = get_option(aimee_global_billing_migration_option_name(), []);
aimee_test_check(
    is_wp_error($result)
    && $result->get_error_code() === 'aimee_billing_migration_manual_review'
    && $summary['manual_review_user_ids'] === [42]
    && empty($summary['completed_at'])
    && count(array_filter($wpdb->queries, function ($query) {
        return strpos($query, 'UPDATE `aimee_user_profiles`') === 0;
    })) === 0,
    'ambiguous billing provenance is untouched and blocks completion'
);

$wpdb = aimee_test_reset();
$wpdb->profiles = [aimee_test_profile([
    'billing_provider' => 'gocardless',
    'billing_account_generation' => 'gocardless_2026_08_v1',
])];
$result = aimee_global_migrate_legacy_stripe_profiles();
aimee_test_check(
    !is_wp_error($result)
    && (int) $result['current_generation_profiles'] === 1
    && (int) $result['archived_profiles'] === 0
    && !empty($result['completed_at']),
    'current replacement generation is preserved and does not block completion'
);

$wpdb = aimee_test_reset();
$wpdb->profiles = [aimee_test_profile([
    'billing_provider' => 'stripe',
    'billing_account_generation' => 'stripe_2026_09_v1',
])];
$result = aimee_global_migrate_legacy_stripe_profiles();
aimee_test_check(
    !is_wp_error($result)
    && (int) $result['current_generation_profiles'] === 1
    && (int) $result['archived_profiles'] === 0
    && !empty($result['completed_at']),
    'current Stripe generation is preserved and does not block completion'
);

$wpdb = aimee_test_reset();
$wpdb->profiles = [aimee_test_profile([
    'billing_provider' => 'gocardless',
    'billing_account_generation' => 'stripe_2026_09_v1',
])];
$result = aimee_global_migrate_legacy_stripe_profiles();
aimee_test_check(
    is_wp_error($result)
    && $result->get_error_code() === 'aimee_billing_migration_manual_review'
    && (get_option(aimee_global_billing_migration_option_name(), [])['manual_review_user_ids'] ?? []) === [42],
    'a generation belonging to another provider cannot be treated as current'
);

$wpdb = aimee_test_reset();
$wpdb->profiles = [aimee_test_profile()];
$result = aimee_global_migrate_legacy_stripe_profiles();
$queries = implode("\n", $wpdb->queries);
aimee_test_check(
    !is_wp_error($result)
    && (int) $result['archived_profiles'] === 1
    && (int) $result['reactivation_profiles'] === 1
    && $result['closed_generation'] === 'legacy_stripe_closed_2026_08'
    && strpos($queries, 'FOR UPDATE') !== false
    && strpos($queries, 'billing_account_generation = %s') !== false
    && strpos($queries, 'stripe_subscription_id = NULL') !== false,
    'demonstrable legacy generation is row-locked, archived and stamped atomically'
);

aimee_test_check(
    $GLOBALS['aimee_test_lock_claims'] === $GLOBALS['aimee_test_lock_releases'],
    'the advisory migration claim is released on the successful path'
);

echo "\nBilling migration hardening regression: {$passes} passed, {$failures} failed.\n";
exit($failures ? 1 : 0);
