<?php
defined('ABSPATH') || exit;

/**
 * GoCardless Recurring Pay by Bank / cVRP integration for Aimee memberships.
 *
 * Billing Requests establish an immutable authorisation. Every attempted
 * collection is first recorded in aimee_gocardless_payments, and only a
 * provider payment that matches that ledger row and the current authorisation
 * can alter access.
 */

function aimee_gocardless_credentials_ready() {
    return defined('GOCARDLESS_ACCESS_TOKEN')
        && trim((string) GOCARDLESS_ACCESS_TOKEN) !== ''
        && defined('GOCARDLESS_WEBHOOK_SECRET')
        && trim((string) GOCARDLESS_WEBHOOK_SECRET) !== ''
        && defined('GOCARDLESS_CREDITOR_ID')
        && trim((string) GOCARDLESS_CREDITOR_ID) !== '';
}

function aimee_gocardless_enabled() {
    return aimee_gocardless_credentials_ready();
}

function aimee_gocardless_environment() {
    $env = defined('GOCARDLESS_ENVIRONMENT') ? strtolower(trim((string) GOCARDLESS_ENVIRONMENT)) : 'live';
    return $env === 'sandbox' ? 'sandbox' : 'live';
}

function aimee_gocardless_base_url() {
    return aimee_gocardless_environment() === 'sandbox'
        ? 'https://api-sandbox.gocardless.com/'
        : 'https://api.gocardless.com/';
}

function aimee_gocardless_generation() {
    return 'gocardless_2026_08_v1';
}

/**
 * Mandate scheme requested at checkout. 1.8.11 defaults to Bacs Direct
 * Debit, which every GoCardless creditor can use. `faster_payments` restores
 * the 1.8.0 commercial VRP mandate for creditors GoCardless has enabled for
 * non-sweeping VRP.
 */
function aimee_gocardless_mandate_scheme() {
    $scheme = defined('GOCARDLESS_MANDATE_SCHEME') ? sanitize_key((string) GOCARDLESS_MANDATE_SCHEME) : 'bacs';
    return $scheme === 'faster_payments' ? 'faster_payments' : 'bacs';
}

/**
 * Which provider payment statuses open or extend membership access.
 *
 * Faster Payments settle within moments, so access follows confirmation.
 * A Bacs Direct Debit takes several working days to confirm, so access is
 * granted provisionally when the collection is created and revoked if that
 * collection later fails.
 */
function aimee_gocardless_payment_grants_access($status, $scheme) {
    $status = sanitize_key((string) $status);
    $scheme = sanitize_key((string) $scheme) === 'faster_payments' ? 'faster_payments' : 'bacs';
    if (in_array($status, ['confirmed', 'paid_out'], true)) return true;
    return $scheme === 'bacs' && in_array($status, ['pending_submission', 'submitted'], true);
}

function aimee_gocardless_payment_status_is_provisional($status) {
    return in_array(sanitize_key((string) $status), ['pending_submission', 'submitted'], true);
}

/**
 * A stored checkout intent built for a different mandate scheme, with no
 * Billing Request bound to it, cannot be resumed: the provider rejected or
 * never saw it. The next checkout starts a fresh intent.
 */
function aimee_gocardless_stored_intent_scheme_abandoned($profile, $scheme) {
    if (!$profile || !empty($profile->gocardless_billing_request_id)) return false;
    $payload = json_decode((string) ($profile->billing_checkout_intent_payload ?? ''), true);
    if (!is_array($payload)) return false;
    $stored = sanitize_key((string) ($payload['billing_requests']['mandate_request']['scheme'] ?? ''));
    return $stored !== '' && $stored !== sanitize_key((string) $scheme);
}

function aimee_gocardless_payments_table() {
    return aimee_table('aimee_gocardless_payments');
}

function aimee_gocardless_payments_schema_ready($refresh = false) {
    static $ready = null;
    if ($refresh) $ready = null;
    if (!function_exists('aimee_global_core_schema_health')
        || !function_exists('aimee_global_schema_table_contract_ready')
        || !aimee_global_core_schema_health($refresh)) {
        $ready = false;
        return false;
    }
    if ($ready !== null) return $ready;

    $table = aimee_gocardless_payments_table();
    $required = [
        'id', 'provider_payment_id', 'idempotency_key', 'user_id',
        'mandate_id', 'billing_request_id', 'plan', 'amount_minor',
        'currency', 'cycle_key', 'attempt', 'reason', 'status',
        'claim_token', 'claim_expires_at', 'applied_at', 'period_start',
        'period_end', 'created_at', 'updated_at',
    ];
    $indexes = [
        'PRIMARY' => ['unique' => true, 'columns' => ['id']],
        'uq_aimee_gc_provider_payment' => ['unique' => true, 'columns' => ['provider_payment_id']],
        'uq_aimee_gc_idempotency' => ['unique' => true, 'columns' => ['idempotency_key']],
        'uq_aimee_gc_cycle_attempt' => [
            'unique' => true,
            'columns' => ['user_id', 'mandate_id', 'cycle_key', 'attempt'],
        ],
        'idx_aimee_gc_user_status' => ['unique' => false, 'columns' => ['user_id', 'status']],
        'idx_aimee_gc_mandate_status' => ['unique' => false, 'columns' => ['mandate_id', 'status']],
        'idx_aimee_gc_claim_expiry' => ['unique' => false, 'columns' => ['claim_expires_at']],
    ];

    // The shared contract helper validates exact named index columns,
    // uniqueness, prefix lengths and BTREE type, and requires InnoDB. Core
    // health above also prevents billing while any transaction dependency is
    // degraded. Core health does not call this provider helper, so no cycle is
    // introduced.
    $ready = aimee_global_schema_table_contract_ready($table, $required, $indexes, true);
    return $ready;
}

function aimee_gocardless_creditor_identity_ready($refresh = false) {
    static $verified = [];
    if (!aimee_gocardless_credentials_ready()) return false;

    $expected_id = trim((string) GOCARDLESS_CREDITOR_ID);
    $binding = hash('sha256', implode('|', [
        aimee_gocardless_environment(),
        $expected_id,
        trim((string) GOCARDLESS_ACCESS_TOKEN),
    ]));
    $cache_key = 'aimee_gc_creditor_' . $binding;
    $cache_value = hash('sha256', 'verified|' . $binding);
    if ($refresh) {
        unset($verified[$binding]);
        delete_transient($cache_key);
    } elseif (!empty($verified[$binding])
        && hash_equals($cache_value, (string) $verified[$binding])) {
        return true;
    } else {
        $cached = get_transient($cache_key);
        if (is_string($cached) && hash_equals($cache_value, $cached)) {
            $verified[$binding] = $cache_value;
            return true;
        }
    }

    $after = '';
    $seen_cursors = [];
    for ($page = 0; $page < 20; $page++) {
        $path = 'creditors?limit=100';
        if ($after !== '') $path .= '&after=' . rawurlencode($after);
        $response = aimee_gocardless_request('GET', $path);
        if (is_wp_error($response)
            || !array_key_exists('creditors', (array) $response)
            || !is_array($response['creditors'])) {
            return false;
        }

        foreach ($response['creditors'] as $creditor) {
            if (!is_array($creditor) || empty($creditor['id'])) return false;
            if (hash_equals($expected_id, (string) $creditor['id'])) {
                $verified[$binding] = $cache_value;
                set_transient($cache_key, $cache_value, 5 * MINUTE_IN_SECONDS);
                return true;
            }
        }

        $cursors = $response['meta']['cursors'] ?? null;
        if (!is_array($cursors) || !array_key_exists('after', $cursors)) return false;
        $next = trim((string) ($cursors['after'] ?? ''));
        if ($next === '') return false;
        if (isset($seen_cursors[$next])) return false;
        $seen_cursors[$next] = true;
        $after = $next;
    }
    return false;
}

function aimee_gocardless_ready($refresh = false) {
    return aimee_gocardless_credentials_ready()
        && aimee_gocardless_payments_schema_ready($refresh)
        && aimee_gocardless_creditor_identity_ready($refresh);
}

function aimee_gocardless_schema_error() {
    return new WP_Error(
        'gocardless_schema_unavailable',
        'Bank billing is temporarily unavailable while its payment ledger is repaired.'
    );
}

function aimee_gocardless_readiness_error() {
    return new WP_Error(
        'gocardless_not_ready',
        'Bank billing is unavailable because its configured creditor identity could not be verified.'
    );
}

function aimee_gocardless_request($method, $path, array $body = [], $idempotency_key = '') {
    if (!aimee_gocardless_credentials_ready()) {
        return new WP_Error('gocardless_incomplete_configuration', 'GoCardless credentials are incomplete.');
    }

    $headers = [
        'Authorization'      => 'Bearer ' . trim((string) GOCARDLESS_ACCESS_TOKEN),
        'GoCardless-Version' => '2015-07-06',
        'Accept'             => 'application/json',
    ];
    $idempotency_key = trim((string) $idempotency_key);
    if ($idempotency_key !== '') $headers['Idempotency-Key'] = substr($idempotency_key, 0, 255);

    $args = [
        'method'  => strtoupper($method),
        'headers' => $headers,
        'timeout' => 30,
    ];
    if (!empty($body)) {
        $args['headers']['Content-Type'] = 'application/json';
        $args['body'] = wp_json_encode($body);
    }

    $response = wp_remote_request(aimee_gocardless_base_url() . ltrim($path, '/'), $args);
    if (is_wp_error($response)) return $response;

    $status = (int) wp_remote_retrieve_response_code($response);
    $raw = (string) wp_remote_retrieve_body($response);
    $data = $raw !== '' ? json_decode($raw, true) : [];
    if ($status < 200 || $status >= 300) {
        $message = 'GoCardless request failed.';
        if (is_array($data)) {
            if (!empty($data['error']['message'])) $message = (string) $data['error']['message'];
            elseif (!empty($data['message'])) $message = (string) $data['message'];
        }
        return new WP_Error('gocardless_api_error', $message, ['status' => $status, 'response' => $data]);
    }
    return is_array($data) ? $data : [];
}

function aimee_gocardless_unwrap($response, $key) {
    return is_array($response) && isset($response[$key]) && is_array($response[$key]) ? $response[$key] : [];
}

function aimee_gocardless_plan_period($plan_key, $from_ts) {
    $from_ts = max(1, (int) $from_ts);
    try {
        $date = (new DateTimeImmutable('@' . $from_ts))->setTimezone(new DateTimeZone('UTC'));
        if ($plan_key === 'weekly') return $date->modify('+1 week')->getTimestamp();

        $months = $plan_key === 'annual' ? 12 : 1;
        if (function_exists('aimee_global_add_months_clamped')) {
            return aimee_global_add_months_clamped($date, $months)->getTimestamp();
        }

        $year = (int) $date->format('Y');
        $month = (int) $date->format('n');
        $day = (int) $date->format('j');
        $absolute_month = (($year * 12) + ($month - 1)) + $months;
        $target_year = intdiv($absolute_month, 12);
        $target_month = ($absolute_month % 12) + 1;
        $last_day = (int) $date->setDate($target_year, $target_month, 1)->format('t');
        return $date->setDate($target_year, $target_month, min($day, $last_day))->getTimestamp();
    } catch (Exception $e) {
        if ($plan_key === 'weekly') return $from_ts + WEEK_IN_SECONDS;
        if ($plan_key === 'annual') return $from_ts + YEAR_IN_SECONDS;
        return $from_ts + 30 * DAY_IN_SECONDS;
    }
}

function aimee_gocardless_plan_limit($plan_key, array $plan) {
    return [
        'period' => $plan_key === 'weekly' ? 'week' : ($plan_key === 'annual' ? 'year' : 'month'),
        'amount' => max(1, (int) ($plan['amount_pence'] ?? 0)),
    ];
}

function aimee_gocardless_value_matches($actual, $expected) {
    if ($expected === null) return $actual === null;
    if (is_int($expected) || is_bool($expected)) return (int) $actual === (int) $expected;
    return (string) $actual === (string) $expected;
}

function aimee_gocardless_row_matches_updates($row, array $update) {
    if (!$row) return false;
    foreach ($update as $field => $expected) {
        $actual = property_exists($row, $field) ? $row->{$field} : null;
        if (!aimee_gocardless_value_matches($actual, $expected)) return false;
    }
    return true;
}

function aimee_gocardless_profile_update_verified($user_id, array $update, array $extra_where = []) {
    global $wpdb;
    $user_id = (int) $user_id;
    if ($user_id < 1) return new WP_Error('gc_invalid_user', 'The billing profile owner is invalid.');
    $table = aimee_table('aimee_user_profiles');
    $where = array_merge(['user_id' => $user_id], $extra_where);
    $result = $wpdb->update($table, $update, $where);
    if ($result === false) return new WP_Error('gc_profile_write_failed', 'The local billing profile could not be updated.');

    $fresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE user_id=%d", $user_id));
    if (!$fresh) return new WP_Error('gc_profile_write_unverified', 'The local billing profile update could not be verified.');
    foreach ($update as $field => $expected) {
        $actual = property_exists($fresh, $field) ? $fresh->{$field} : null;
        if (!aimee_gocardless_value_matches($actual, $expected)) {
            return new WP_Error('gc_profile_write_unverified', 'The local billing profile update could not be verified.');
        }
    }
    foreach ($extra_where as $field => $expected) {
        // When a CAS field is also being changed, a no-op result cannot prove
        // that this request owned the old value. A changed row is sufficient
        // for that overlapping field; every non-overlapping owner/lock field
        // must still match on the verification read.
        if (array_key_exists($field, $update)) {
            if ($result === 0) {
                return new WP_Error('gc_profile_cas_unverified', 'The local billing transition owner could not be verified.');
            }
            continue;
        }
        $actual = property_exists($fresh, $field) ? $fresh->{$field} : null;
        if (!aimee_gocardless_value_matches($actual, $expected)) {
            return new WP_Error('gc_profile_cas_unverified', 'The local billing transition owner could not be verified.');
        }
    }
    return $fresh;
}

function aimee_gocardless_ledger_update_verified($ledger_id, array $update, array $extra_where = []) {
    global $wpdb;
    $table = aimee_gocardless_payments_table();
    $where = array_merge(['id' => (int) $ledger_id], $extra_where);
    $result = $wpdb->update($table, $update, $where);
    if ($result === false) return new WP_Error('gc_ledger_write_failed', 'The payment ledger could not be updated.');

    $fresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", (int) $ledger_id));
    if (!$fresh) return new WP_Error('gc_ledger_write_unverified', 'The payment ledger update could not be verified.');
    foreach ($update as $field => $expected) {
        $actual = property_exists($fresh, $field) ? $fresh->{$field} : null;
        if (!aimee_gocardless_value_matches($actual, $expected)) {
            return new WP_Error('gc_ledger_write_unverified', 'The payment ledger update could not be verified.');
        }
    }
    foreach ($extra_where as $field => $expected) {
        if (array_key_exists($field, $update)) {
            if ($result === 0) {
                return new WP_Error('gc_ledger_cas_unverified', 'The payment-ledger transition owner could not be verified.');
            }
            continue;
        }
        $actual = property_exists($fresh, $field) ? $fresh->{$field} : null;
        if (!aimee_gocardless_value_matches($actual, $expected)) {
            return new WP_Error('gc_ledger_cas_unverified', 'The payment-ledger transition owner could not be verified.');
        }
    }
    return $fresh;
}

function aimee_gocardless_retrieve_billing_request($id) {
    $r = aimee_gocardless_request('GET', 'billing_requests/' . rawurlencode($id));
    return is_wp_error($r) ? $r : aimee_gocardless_unwrap($r, 'billing_requests');
}

function aimee_gocardless_retrieve_billing_request_flow($id) {
    $r = aimee_gocardless_request('GET', 'billing_request_flows/' . rawurlencode($id));
    return is_wp_error($r) ? $r : aimee_gocardless_unwrap($r, 'billing_request_flows');
}

function aimee_gocardless_retrieve_mandate($id) {
    $r = aimee_gocardless_request('GET', 'mandates/' . rawurlencode($id));
    return is_wp_error($r) ? $r : aimee_gocardless_unwrap($r, 'mandates');
}

function aimee_gocardless_retrieve_payment($id) {
    $r = aimee_gocardless_request('GET', 'payments/' . rawurlencode($id));
    return is_wp_error($r) ? $r : aimee_gocardless_unwrap($r, 'payments');
}

/**
 * Read every resource in a GoCardless collection before treating absence or
 * uniqueness as authoritative. A partial list is never safe for billing
 * reconciliation, so malformed cursors, repeated cursors and the page bound
 * all fail closed.
 */
function aimee_gocardless_list_collection($path, $response_key) {
    $path = ltrim((string) $path, '/');
    $response_key = sanitize_key((string) $response_key);
    if ($path === '' || !in_array($response_key, ['billing_requests', 'payments'], true)) {
        return new WP_Error('gc_invalid_collection', 'The GoCardless collection is invalid.');
    }

    $resources = [];
    $seen_ids = [];
    $seen_cursors = [];
    $after = '';
    for ($page = 0; $page < 20; $page++) {
        $page_path = $path . (strpos($path, '?') === false ? '?' : '&') . 'limit=500';
        if ($after !== '') $page_path .= '&after=' . rawurlencode($after);
        $response = aimee_gocardless_request('GET', $page_path);
        if (is_wp_error($response)
            || !array_key_exists($response_key, (array) $response)
            || !is_array($response[$response_key])) {
            return new WP_Error('gc_collection_read_failed', 'The complete GoCardless collection could not be read.');
        }

        foreach ($response[$response_key] as $resource) {
            if (!is_array($resource) || empty($resource['id'])) {
                return new WP_Error('gc_collection_invalid', 'The GoCardless collection contained an invalid resource.');
            }
            $resource_id = sanitize_text_field((string) $resource['id']);
            if ($resource_id === '' || isset($seen_ids[$resource_id])) {
                return new WP_Error('gc_collection_ambiguous', 'The GoCardless collection contained a duplicate resource identity.');
            }
            $seen_ids[$resource_id] = true;
            $resources[] = $resource;
        }

        $cursors = $response['meta']['cursors'] ?? null;
        if (!is_array($cursors) || !array_key_exists('after', $cursors)) {
            return new WP_Error('gc_collection_cursor_invalid', 'The GoCardless collection cursor could not be verified.');
        }
        $next = trim((string) ($cursors['after'] ?? ''));
        if ($next === '') return $resources;
        if (isset($seen_cursors[$next])) {
            return new WP_Error('gc_collection_cursor_repeated', 'The GoCardless collection cursor repeated unexpectedly.');
        }
        $seen_cursors[$next] = true;
        $after = $next;
    }

    return new WP_Error('gc_collection_page_limit', 'The complete GoCardless collection exceeded the safe reconciliation bound.');
}

function aimee_gocardless_list_payments_for_mandate($mandate_id) {
    $mandate_id = sanitize_text_field((string) $mandate_id);
    if ($mandate_id === '') return new WP_Error('gc_invalid_mandate', 'The bank mandate is invalid.');
    $payments = aimee_gocardless_list_collection(
        'payments?mandate=' . rawurlencode($mandate_id),
        'payments'
    );
    if (is_wp_error($payments)) return $payments;
    foreach ($payments as $payment) {
        if (!hash_equals($mandate_id, (string) ($payment['links']['mandate'] ?? ''))) {
            return new WP_Error('gc_payment_mandate_mismatch', 'The provider returned a payment for a different mandate.');
        }
    }
    return $payments;
}

function aimee_gocardless_list_billing_requests_for_intent($intent_token) {
    $intent_token = trim((string) $intent_token);
    if ($intent_token === '') return new WP_Error('gc_checkout_intent_missing', 'The bank checkout intent is missing.');
    $billing_requests = aimee_gocardless_list_collection('billing_requests', 'billing_requests');
    if (is_wp_error($billing_requests)) return $billing_requests;

    $matches = [];
    foreach ($billing_requests as $billing_request) {
        $metadata = is_array($billing_request['metadata'] ?? null) ? $billing_request['metadata'] : [];
        if (hash_equals($intent_token, (string) ($metadata['aimee_checkout_intent'] ?? ''))) {
            $matches[] = $billing_request;
        }
    }
    return $matches;
}

function aimee_gocardless_api_error_status($error) {
    if (!is_wp_error($error)) return 0;
    $data = $error->get_error_data();
    return is_array($data) ? (int) ($data['status'] ?? 0) : 0;
}

function aimee_gocardless_idempotency_conflict_details($error) {
    $details = ['is_conflict' => false, 'resource_id' => ''];
    if (!is_wp_error($error) || aimee_gocardless_api_error_status($error) !== 409) return $details;
    $data = $error->get_error_data();
    $payload = is_array($data) && is_array($data['response'] ?? null) ? $data['response'] : [];
    $stack = [$payload];
    while ($stack) {
        $node = array_pop($stack);
        foreach ((array) $node as $key => $value) {
            if (is_array($value)) {
                $stack[] = $value;
                continue;
            }
            $key = sanitize_key((string) $key);
            if (in_array($key, ['reason', 'code', 'type'], true)
                && sanitize_key((string) $value) === 'idempotent_creation_conflict') {
                $details['is_conflict'] = true;
            }
            if ($key === 'conflicting_resource_id' && trim((string) $value) !== '') {
                $details['resource_id'] = sanitize_text_field((string) $value);
            }
        }
    }
    return $details;
}

function aimee_gocardless_idempotent_create($resource_path, $response_key, array $body, $idempotency_key) {
    $allowed = [
        'billing_requests'      => 'billing_requests',
        'billing_request_flows' => 'billing_request_flows',
        'payments'              => 'payments',
    ];
    $resource_path = sanitize_key((string) $resource_path);
    $response_key = sanitize_key((string) $response_key);
    if (!isset($allowed[$resource_path]) || !hash_equals($allowed[$resource_path], $response_key)) {
        return new WP_Error('gc_invalid_create_resource', 'The GoCardless create resource is invalid.');
    }

    $created = aimee_gocardless_request('POST', $resource_path, $body, (string) $idempotency_key);
    if (!is_wp_error($created)) return $created;

    $conflict = aimee_gocardless_idempotency_conflict_details($created);
    if (empty($conflict['is_conflict'])) return $created;
    if (empty($conflict['resource_id'])) {
        // An idempotency conflict without its authoritative resource link is
        // ambiguous. Callers must retain and retry the same local attempt/key.
        return new WP_Error(
            'gc_idempotency_reconciliation_unknown',
            'The existing GoCardless resource could not yet be reconciled.'
        );
    }

    $resource_id = sanitize_text_field((string) $conflict['resource_id']);
    $existing = aimee_gocardless_request('GET', $resource_path . '/' . rawurlencode($resource_id));
    if (is_wp_error($existing)) {
        return new WP_Error(
            'gc_idempotency_reconciliation_unknown',
            'The existing GoCardless resource could not yet be retrieved.'
        );
    }
    $resource = aimee_gocardless_unwrap($existing, $response_key);
    if (empty($resource['id']) || !hash_equals($resource_id, (string) $resource['id'])) {
        return new WP_Error(
            'gc_idempotency_reconciliation_unknown',
            'The existing GoCardless resource identity could not be verified.'
        );
    }
    $existing['_aimee_idempotency_reconciled'] = true;
    return $existing;
}

function aimee_gocardless_terminal_resource_proof($resource, $expected_id, array $terminal_statuses, $resource_name) {
    if (!is_array($resource) || empty($resource['id'])) return false;
    $resource_id = sanitize_text_field((string) $resource['id']);
    if (!hash_equals((string) $expected_id, $resource_id)) {
        return new WP_Error(
            'gc_' . sanitize_key((string) $resource_name) . '_identity_mismatch',
            'The provider returned a different resource while cancellation was being verified.'
        );
    }
    return in_array(sanitize_key((string) ($resource['status'] ?? '')), $terminal_statuses, true);
}

function aimee_gocardless_billing_request_mandate_id($billing_request) {
    if (!is_array($billing_request)) return '';
    return sanitize_text_field((string) (
        $billing_request['links']['mandate_request_mandate']
        ?? $billing_request['mandate_request']['links']['mandate']
        ?? ''
    ));
}

function aimee_gocardless_fulfilled_billing_request_error($billing_request, $billing_request_id) {
    if (!is_array($billing_request)
        || sanitize_key((string) ($billing_request['status'] ?? '')) !== 'fulfilled') {
        return false;
    }
    $resource_id = sanitize_text_field((string) ($billing_request['id'] ?? ''));
    if ($resource_id === '' || !hash_equals((string) $billing_request_id, $resource_id)) {
        return new WP_Error(
            'gc_billing_request_identity_mismatch',
            'The fulfilled bank authorisation request identity could not be verified.'
        );
    }
    return new WP_Error(
        'gc_billing_request_fulfilled',
        'The bank authorisation request is already fulfilled and its mandate must be cancelled.',
        [
            'status' => 'fulfilled',
            'billing_request_id' => $resource_id,
            'mandate_id' => aimee_gocardless_billing_request_mandate_id($billing_request),
        ]
    );
}

function aimee_gocardless_cancel_billing_request_id($billing_request_id) {
    $billing_request_id = sanitize_text_field((string) $billing_request_id);
    if ($billing_request_id === '') return true;
    if (!aimee_gocardless_ready()) return aimee_gocardless_readiness_error();
    $terminal = ['cancelled'];

    $billing_request = aimee_gocardless_retrieve_billing_request($billing_request_id);
    if (is_wp_error($billing_request)) {
        return new WP_Error(
            'gc_billing_request_state_unverified',
            'The bank authorisation request state could not be verified.'
        );
    }
    $proof = aimee_gocardless_terminal_resource_proof(
        $billing_request,
        $billing_request_id,
        $terminal,
        'billing_request'
    );
    if (is_wp_error($proof) || $proof) return $proof;
    $fulfilled = aimee_gocardless_fulfilled_billing_request_error($billing_request, $billing_request_id);
    if (is_wp_error($fulfilled)) return $fulfilled;

    $cancelled = aimee_gocardless_request(
        'POST',
        'billing_requests/' . rawurlencode($billing_request_id) . '/actions/cancel'
    );
    $cancelled_request = is_wp_error($cancelled)
        ? []
        : aimee_gocardless_unwrap($cancelled, 'billing_requests');
    $proof = aimee_gocardless_terminal_resource_proof(
        $cancelled_request,
        $billing_request_id,
        $terminal,
        'billing_request'
    );
    if (is_wp_error($proof) || $proof) return $proof;
    $fulfilled = aimee_gocardless_fulfilled_billing_request_error($cancelled_request, $billing_request_id);
    if (is_wp_error($fulfilled)) return $fulfilled;

    for ($attempt = 0; $attempt < 2; $attempt++) {
        $verified = aimee_gocardless_retrieve_billing_request($billing_request_id);
        if (is_wp_error($verified)) continue;
        $proof = aimee_gocardless_terminal_resource_proof(
            $verified,
            $billing_request_id,
            $terminal,
            'billing_request'
        );
        if (is_wp_error($proof) || $proof) return $proof;
        $fulfilled = aimee_gocardless_fulfilled_billing_request_error($verified, $billing_request_id);
        if (is_wp_error($fulfilled)) return $fulfilled;
    }
    return new WP_Error(
        'gc_billing_request_cancellation_unverified',
        'GoCardless did not confirm that the bank authorisation request is cancelled.'
    );
}

function aimee_gocardless_cancel_mandate_id($mandate_id) {
    $mandate_id = sanitize_text_field((string) $mandate_id);
    if ($mandate_id === '') return true;
    if (!aimee_gocardless_ready()) return aimee_gocardless_readiness_error();
    $terminal = ['cancelled', 'canceled', 'failed', 'expired', 'blocked'];

    $mandate = aimee_gocardless_retrieve_mandate($mandate_id);
    if (is_wp_error($mandate)) {
        // A missing resource is not proof that the mandate is unable to
        // collect. Account deletion must retain its owner until GoCardless
        // provides an authoritative terminal state.
        return new WP_Error('gc_mandate_state_unverified', 'The bank mandate state could not be verified.');
    }
    $proof = aimee_gocardless_terminal_resource_proof($mandate, $mandate_id, $terminal, 'mandate');
    if (is_wp_error($proof) || $proof) return $proof;

    $cancelled = aimee_gocardless_request('POST', 'mandates/' . rawurlencode($mandate_id) . '/actions/cancel');

    // GoCardless singular-resource responses use the plural `mandates`
    // envelope. Accept the action response only when its matching resource is
    // already terminal; otherwise perform two bounded authoritative reads.
    $proof = aimee_gocardless_terminal_resource_proof(
        is_wp_error($cancelled) ? [] : aimee_gocardless_unwrap($cancelled, 'mandates'),
        $mandate_id,
        $terminal,
        'mandate'
    );
    if (is_wp_error($proof) || $proof) return $proof;

    for ($attempt = 0; $attempt < 2; $attempt++) {
        $verified = aimee_gocardless_retrieve_mandate($mandate_id);
        if (is_wp_error($verified)) continue;
        $proof = aimee_gocardless_terminal_resource_proof($verified, $mandate_id, $terminal, 'mandate');
        if (is_wp_error($proof) || $proof) return $proof;
    }
    return new WP_Error(
        'gc_mandate_cancellation_unverified',
        'GoCardless did not confirm that the bank mandate is terminal.'
    );
}

function aimee_gocardless_cancel_payment_id($payment_id) {
    $payment_id = sanitize_text_field((string) $payment_id);
    if ($payment_id === '') return true;
    if (!aimee_gocardless_ready()) return aimee_gocardless_readiness_error();
    // Confirmed/paid-out payments can no longer be cancelled or initiate a
    // future collection themselves. Once the owning mandate is separately
    // terminal-proved, they are safe terminal history for account erasure.
    $terminal = [
        'cancelled', 'canceled', 'failed', 'charged_back',
        'customer_approval_denied', 'expired', 'confirmed', 'paid_out',
    ];
    $payment = aimee_gocardless_retrieve_payment($payment_id);
    if (is_wp_error($payment)) {
        return new WP_Error('gc_payment_state_unverified', 'The bank payment state could not be verified.');
    }
    $proof = aimee_gocardless_terminal_resource_proof($payment, $payment_id, $terminal, 'payment');
    if (is_wp_error($proof) || $proof) return $proof;
    $status = sanitize_key((string) ($payment['status'] ?? ''));
    if (!in_array($status, ['pending_customer_approval', 'pending_submission', 'submitted'], true)) {
        return new WP_Error('gc_payment_not_cancellable', 'The bank payment is no longer cancellable.');
    }

    $cancelled = aimee_gocardless_request('POST', 'payments/' . rawurlencode($payment_id) . '/actions/cancel');
    $proof = aimee_gocardless_terminal_resource_proof(
        is_wp_error($cancelled) ? [] : aimee_gocardless_unwrap($cancelled, 'payments'),
        $payment_id,
        $terminal,
        'payment'
    );
    if (is_wp_error($proof) || $proof) return $proof;

    for ($attempt = 0; $attempt < 2; $attempt++) {
        $verified = aimee_gocardless_retrieve_payment($payment_id);
        if (is_wp_error($verified)) continue;
        $proof = aimee_gocardless_terminal_resource_proof($verified, $payment_id, $terminal, 'payment');
        if (is_wp_error($proof) || $proof) return $proof;
    }
    return new WP_Error(
        'gc_payment_cancellation_unverified',
        'GoCardless did not confirm that the bank payment is terminal.'
    );
}

function aimee_gocardless_cancel_profile_mandate($profile) {
    if (!$profile || empty($profile->user_id)) return new WP_Error('gc_profile_missing', 'The bank billing profile was not found.');
    $user_id = (int) $profile->user_id;
    $mandate_id = sanitize_text_field((string) ($profile->gocardless_mandate_id ?? ''));
    if ($mandate_id === '') return true;

    $cancelled = aimee_gocardless_cancel_mandate_id($mandate_id);
    if (is_wp_error($cancelled)) return $cancelled;
    $now = current_time('mysql', true);
    $updated = aimee_gocardless_profile_update_verified($user_id, [
        'subscription_cancel_at_period_end' => 1,
        'gocardless_cancelled_at'            => $now,
        'gocardless_next_payment_at'         => null,
        'gocardless_retry_after'             => null,
    ], ['gocardless_mandate_id' => $mandate_id]);
    return is_wp_error($updated) ? $updated : true;
}

function aimee_gocardless_cancel_billing_request_or_mandate($billing_request_id) {
    $billing_request_id = sanitize_text_field((string) $billing_request_id);
    if ($billing_request_id === '') return true;
    $cancelled = aimee_gocardless_cancel_billing_request_id($billing_request_id);
    if (!is_wp_error($cancelled)) return true;
    if ($cancelled->get_error_code() !== 'gc_billing_request_fulfilled') return $cancelled;

    $data = $cancelled->get_error_data();
    $mandate_id = is_array($data)
        ? sanitize_text_field((string) ($data['mandate_id'] ?? ''))
        : '';
    if ($mandate_id === '') {
        return new WP_Error(
            'gc_fulfilled_request_mandate_missing',
            'The fulfilled bank authorisation did not expose the mandate that must be cancelled.'
        );
    }
    return aimee_gocardless_cancel_mandate_id($mandate_id);
}

function aimee_gocardless_payment_status_is_terminal_for_retirement($status) {
    return in_array(sanitize_key((string) $status), [
        'cancelled', 'canceled', 'failed', 'charged_back',
        'customer_approval_denied', 'expired', 'confirmed', 'paid_out',
    ], true);
}

/**
 * Reconcile and terminal-prove every GoCardless ledger payment owned by a
 * user. This intentionally treats a zero-match unknown create as ambiguous:
 * a timed-out create can still materialise, so account erasure must be
 * retried rather than discarding its only durable idempotency identity.
 */
function aimee_gocardless_retire_user_ledger_payments($user_id) {
    global $wpdb;
    $user_id = (int) $user_id;
    if ($user_id < 1) return new WP_Error('gc_invalid_user', 'The billing profile owner is invalid.');
    if (!aimee_gocardless_ready()) return aimee_gocardless_readiness_error();

    $table = aimee_gocardless_payments_table();
    $wpdb->last_error = '';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table} WHERE user_id=%d ORDER BY id ASC",
        $user_id
    ));
    if (!empty($wpdb->last_error) || !is_array($rows)) {
        return new WP_Error('gc_ledger_read_failed', 'The complete bank-payment ledger could not be read.');
    }
    if (!$rows) return true;

    $rows_by_mandate = [];
    foreach ($rows as $row) {
        $mandate_id = sanitize_text_field((string) ($row->mandate_id ?? ''));
        $idempotency_key = trim((string) ($row->idempotency_key ?? ''));
        if ($mandate_id === '' || $idempotency_key === '') {
            return new WP_Error('gc_ledger_identity_incomplete', 'A bank-payment ledger identity is incomplete.');
        }
        if (!isset($rows_by_mandate[$mandate_id])) $rows_by_mandate[$mandate_id] = [];
        $rows_by_mandate[$mandate_id][] = $row;
    }

    foreach ($rows_by_mandate as $mandate_id => $mandate_rows) {
        $payments = aimee_gocardless_list_payments_for_mandate($mandate_id);
        if (is_wp_error($payments)) return $payments;

        $payments_by_id = [];
        $payments_by_key = [];
        foreach ($payments as $payment) {
            $payment_id = sanitize_text_field((string) ($payment['id'] ?? ''));
            $payments_by_id[$payment_id] = $payment;
            $metadata = is_array($payment['metadata'] ?? null) ? $payment['metadata'] : [];
            $payment_key = trim((string) ($metadata['aimee_payment_key'] ?? ''));
            if ($payment_key !== '') {
                if (!isset($payments_by_key[$payment_key])) $payments_by_key[$payment_key] = [];
                $payments_by_key[$payment_key][] = $payment;
            }
        }

        $claimed_remote_ids = [];
        foreach ($mandate_rows as $row) {
            $payment_id = sanitize_text_field((string) ($row->provider_payment_id ?? ''));
            $payment_key = (string) $row->idempotency_key;
            $payment = null;

            if ($payment_id !== '') {
                if (!isset($payments_by_id[$payment_id])) {
                    return new WP_Error(
                        'gc_bound_payment_not_listed',
                        'A bound bank payment was absent from the authoritative mandate collection.'
                    );
                }
                $payment = $payments_by_id[$payment_id];
                $key_matches = $payments_by_key[$payment_key] ?? [];
                if (count($key_matches) !== 1
                    || !hash_equals($payment_id, (string) ($key_matches[0]['id'] ?? ''))) {
                    return new WP_Error('gc_payment_binding_ambiguous', 'A bank payment identity is ambiguous.');
                }
            } else {
                $key_matches = $payments_by_key[$payment_key] ?? [];
                if (count($key_matches) > 1) {
                    return new WP_Error('gc_payment_binding_ambiguous', 'More than one bank payment matched an idempotency identity.');
                }
                if (count($key_matches) === 1) {
                    $payment = $key_matches[0];
                    $payment_id = sanitize_text_field((string) ($payment['id'] ?? ''));
                    if (!aimee_gocardless_payment_matches_ledger($payment, $row)) {
                        return new WP_Error('gc_payment_terms_mismatch', 'The discovered bank payment did not match its immutable ledger terms.');
                    }
                    $bound = aimee_gocardless_ledger_update_verified((int) $row->id, [
                        'provider_payment_id' => $payment_id,
                        'updated_at' => current_time('mysql', true),
                    ], [
                        'user_id' => $user_id,
                        'idempotency_key' => $payment_key,
                        'provider_payment_id' => null,
                    ]);
                    if (is_wp_error($bound)) return $bound;
                    $row = $bound;
                } elseif (in_array(sanitize_key((string) $row->status), ['creating', 'request_unknown'], true)) {
                    return new WP_Error(
                        'gc_unknown_payment_unresolved',
                        'A timed-out bank payment create still has no authoritative provider binding.'
                    );
                } elseif (!aimee_gocardless_payment_status_is_terminal_failure($row->status)) {
                    return new WP_Error('gc_unbound_payment_nonterminal', 'An unbound bank payment is not terminal.');
                } else {
                    // Definitive local creation failures have no provider
                    // resource and therefore no future collection to retire.
                    continue;
                }
            }

            if (!$payment || !aimee_gocardless_payment_matches_ledger($payment, $row)) {
                return new WP_Error('gc_payment_terms_mismatch', 'The bank payment did not match its immutable ledger terms.');
            }
            if (isset($claimed_remote_ids[$payment_id])) {
                return new WP_Error('gc_payment_binding_ambiguous', 'A provider payment was claimed by more than one ledger row.');
            }
            $claimed_remote_ids[$payment_id] = true;

            $provider_status = sanitize_key((string) ($payment['status'] ?? ''));
            if (!aimee_gocardless_payment_status_is_terminal_for_retirement($provider_status)) {
                $cancelled = aimee_gocardless_cancel_payment_id($payment_id);
                if (is_wp_error($cancelled)) return $cancelled;
                $payment = aimee_gocardless_retrieve_payment($payment_id);
                if (is_wp_error($payment)
                    || !aimee_gocardless_payment_matches_ledger($payment, $row)
                    || !aimee_gocardless_payment_status_is_terminal_for_retirement($payment['status'] ?? '')) {
                    return new WP_Error('gc_payment_retirement_unverified', 'The bank payment was not verified as terminal.');
                }
                $provider_status = sanitize_key((string) $payment['status']);
            }

            $saved = aimee_gocardless_ledger_update_verified((int) $row->id, [
                'status' => $provider_status,
                'claim_token' => null,
                'claim_expires_at' => null,
                'updated_at' => current_time('mysql', true),
            ], [
                'user_id' => $user_id,
                'provider_payment_id' => $payment_id,
                'idempotency_key' => $payment_key,
            ]);
            if (is_wp_error($saved)) return $saved;
        }

        // A nonterminal provider payment on this exact Aimee mandate without
        // a local ledger owner is unsafe to forget, even if its metadata is
        // malformed or belongs to an interrupted older build.
        foreach ($payments as $payment) {
            $payment_id = sanitize_text_field((string) ($payment['id'] ?? ''));
            if (!isset($claimed_remote_ids[$payment_id])
                && !aimee_gocardless_payment_status_is_terminal_for_retirement($payment['status'] ?? '')) {
                return new WP_Error('gc_unlinked_payment_nonterminal', 'An unlinked bank payment is not terminal.');
            }
        }
    }
    return true;
}

function aimee_gocardless_find_user_for_mandate($mandate_id) {
    global $wpdb;
    $mandate_id = sanitize_text_field((string) $mandate_id);
    if ($mandate_id === '') return 0;
    $wpdb->last_error = '';
    $user_id = $wpdb->get_var($wpdb->prepare(
        'SELECT user_id FROM ' . aimee_table('aimee_user_profiles') . ' WHERE gocardless_mandate_id=%s LIMIT 1',
        $mandate_id
    ));
    if (!empty($wpdb->last_error)) return new WP_Error('gc_profile_lookup_failed', 'The mandate owner could not be checked.');
    return (int) $user_id;
}

function aimee_gocardless_find_user_for_billing_request($billing_request_id) {
    global $wpdb;
    $billing_request_id = sanitize_text_field((string) $billing_request_id);
    if ($billing_request_id === '') return 0;
    $wpdb->last_error = '';
    $user_id = $wpdb->get_var($wpdb->prepare(
        'SELECT user_id FROM ' . aimee_table('aimee_user_profiles') . ' WHERE gocardless_billing_request_id=%s LIMIT 1',
        $billing_request_id
    ));
    if (!empty($wpdb->last_error)) return new WP_Error('gc_profile_lookup_failed', 'The billing-request owner could not be checked.');
    return (int) $user_id;
}

function aimee_gocardless_profile_has_open_payment($profile) {
    if (!$profile || empty($profile->user_id) || empty($profile->gocardless_mandate_id)) return false;
    global $wpdb;
    $table = aimee_gocardless_payments_table();
    $wpdb->last_error = '';
    $found = $wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM {$table}
         WHERE user_id=%d AND mandate_id=%s AND applied_at IS NULL
           AND status NOT IN ('failed','cancelled','canceled','charged_back','customer_approval_denied','expired','creation_failed')
         LIMIT 1",
        (int) $profile->user_id,
        (string) $profile->gocardless_mandate_id
    ));
    if (!empty($wpdb->last_error)) return new WP_Error('gc_ledger_read_failed', 'The open-payment ledger could not be checked.');
    return (bool) $found;
}

function aimee_gocardless_release_checkout_lock_verified($user_id, $lock_token) {
    global $wpdb;
    $released = aimee_release_subscription_checkout_lock((int) $user_id, (string) $lock_token);
    if ($released === false) {
        return new WP_Error('gc_checkout_unlock_failed', 'The secure checkout lock could not be released.');
    }
    $wpdb->last_error = '';
    $current_token = $wpdb->get_var($wpdb->prepare(
        'SELECT billing_checkout_lock_token FROM ' . aimee_table('aimee_user_profiles') . ' WHERE user_id=%d',
        (int) $user_id
    ));
    if (!empty($wpdb->last_error)) {
        return new WP_Error('gc_checkout_unlock_unverified', 'The secure checkout lock release could not be verified.');
    }
    if (trim((string) $current_token) !== ''
        && hash_equals((string) $lock_token, (string) $current_token)) {
        return new WP_Error('gc_checkout_unlock_unverified', 'The secure checkout lock remains active.');
    }
    return true;
}

function aimee_gocardless_compensate_checkout_intent($user_id, $lock_token, $intent_token, $billing_request_id) {
    $retired = aimee_gocardless_cancel_billing_request_or_mandate($billing_request_id);
    if (is_wp_error($retired)) return $retired;
    $saved = aimee_gocardless_profile_update_verified((int) $user_id, [
        'billing_checkout_intent_status' => 'retired',
    ], [
        'billing_checkout_lock_token' => (string) $lock_token,
        'billing_checkout_intent_token' => (string) $intent_token,
        'gocardless_billing_request_id' => (string) $billing_request_id,
    ]);
    return is_wp_error($saved) ? $saved : true;
}

function aimee_gocardless_build_checkout_intent_payload(
    $user_id,
    $intent_token,
    $plan_key,
    $amount_minor,
    $currency,
    array $plan,
    $start_date = ''
) {
    $start_date = preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string) $start_date)
        ? (string) $start_date
        : gmdate('Y-m-d');
    $limit = aimee_gocardless_plan_limit($plan_key, $plan);
    $metadata = [
        'aimee_checkout_intent' => (string) $intent_token,
        'aimee_generation'      => aimee_gocardless_generation(),
        'aimee_terms'           => implode('|', [
            (int) $user_id,
            sanitize_key((string) $plan_key),
            (int) $amount_minor,
            strtoupper((string) $currency),
        ]),
    ];

    if (aimee_gocardless_mandate_scheme() === 'bacs') {
        // A Direct Debit mandate carries no spending constraints and no
        // instant-payment fallback; the amount and cadence live in the
        // immutable terms metadata that every payment is checked against.
        return [
            'billing_requests' => [
                'purpose_code' => defined('GOCARDLESS_PURPOSE_CODE') ? (string) GOCARDLESS_PURPOSE_CODE : 'retail',
                'metadata'     => $metadata,
                'mandate_request' => [
                    'currency'    => strtoupper((string) $currency),
                    'scheme'      => 'bacs',
                    'description' => (string) ($plan['label'] ?? 'Aimee') . ' membership',
                ],
            ],
        ];
    }

    return [
        'billing_requests' => [
            'fallback_enabled'     => true,
            'purpose_code'         => defined('GOCARDLESS_PURPOSE_CODE') ? (string) GOCARDLESS_PURPOSE_CODE : 'retail',
            'payment_context_code' => defined('GOCARDLESS_PAYMENT_CONTEXT_CODE') ? (string) GOCARDLESS_PAYMENT_CONTEXT_CODE : 'billing_goods_and_services_in_advance',
            'payment_purpose_code' => defined('GOCARDLESS_PAYMENT_PURPOSE_CODE') ? (string) GOCARDLESS_PAYMENT_PURPOSE_CODE : 'subscription',
            'metadata' => $metadata,
            'mandate_request' => [
                'currency' => strtoupper((string) $currency),
                'scheme' => 'faster_payments',
                'description' => (string) ($plan['label'] ?? 'Aimee') . ' membership',
                'constraints' => [
                    'start_date' => $start_date,
                    'max_amount_per_payment' => (int) $limit['amount'],
                    'periodic_limits' => [[
                        'period' => (string) $limit['period'],
                        'max_total_amount' => (int) $limit['amount'],
                        'alignment' => 'creation_date',
                    ]],
                ],
            ],
        ],
    ];
}

function aimee_gocardless_checkout_intent_payload_matches(
    $payload,
    $user_id,
    $intent_token,
    $plan_key,
    $amount_minor,
    $currency
) {
    if (!is_array($payload) || !is_array($payload['billing_requests'] ?? null)) return false;
    $request = $payload['billing_requests'];
    $profile = (object) [
        'user_id' => (int) $user_id,
        'gocardless_authorized_plan' => sanitize_key((string) $plan_key),
        'gocardless_authorized_amount_minor' => (int) $amount_minor,
        'gocardless_authorized_currency' => strtoupper((string) $currency),
    ];
    $metadata = is_array($request['metadata'] ?? null) ? $request['metadata'] : [];
    $mandate_request = is_array($request['mandate_request'] ?? null) ? $request['mandate_request'] : [];
    $scheme = aimee_gocardless_mandate_scheme();
    if (!hash_equals((string) $intent_token, (string) ($metadata['aimee_checkout_intent'] ?? ''))) return false;
    if (!hash_equals($scheme, sanitize_key((string) ($mandate_request['scheme'] ?? '')))) return false;
    if ($scheme === 'bacs') {
        return trim((string) ($request['purpose_code'] ?? '')) !== ''
            && hash_equals(strtoupper((string) $currency), strtoupper((string) ($mandate_request['currency'] ?? '')))
            && aimee_gocardless_billing_request_terms_match($request, $profile);
    }
    return !empty($request['fallback_enabled'])
        && trim((string) ($request['purpose_code'] ?? '')) !== ''
        && trim((string) ($request['payment_context_code'] ?? '')) !== ''
        && trim((string) ($request['payment_purpose_code'] ?? '')) !== ''
        && preg_match(
            '/^\d{4}-\d{2}-\d{2}$/D',
            (string) ($mandate_request['constraints']['start_date'] ?? '')
        ) === 1
        && aimee_gocardless_billing_request_terms_match($request, $profile);
}

function aimee_gocardless_checkout(WP_REST_Request $request) {
    global $wpdb;
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_REST_Response(['status'=>'error','message'=>'Authentication required.'], 401);
    if (!aimee_gocardless_credentials_ready()) {
        return new WP_REST_Response(['status'=>'billing_configuration_error','message'=>'Bank payments are not fully configured.'], 503);
    }
    if (!aimee_gocardless_payments_schema_ready()) {
        return new WP_REST_Response(['status'=>'billing_schema_error','message'=>aimee_gocardless_schema_error()->get_error_message()], 503);
    }
    if (!aimee_gocardless_creditor_identity_ready()) {
        return new WP_REST_Response(['status'=>'billing_configuration_error','message'=>'The configured bank creditor identity could not be verified.'], 503);
    }
    if (!function_exists('aimee_rate_limit') || !aimee_rate_limit('subscription_checkout_' . $user_id, 8, 10 * MINUTE_IN_SECONDS)) {
        return new WP_REST_Response(['status'=>'error','message'=>'Too many checkout attempts. Please wait a few minutes and try again.'], 429);
    }
    if (!function_exists('aimee_acquire_subscription_checkout_lock') || !function_exists('aimee_release_subscription_checkout_lock')) {
        return new WP_REST_Response(['status'=>'billing_configuration_error','message'=>'Secure checkout locking is unavailable.'], 503);
    }

    $params = $request->get_json_params();
    $plan_key = sanitize_key($params['plan'] ?? '');
    $market = function_exists('aimee_membership_requested_market')
        ? aimee_membership_requested_market($request)
        : (function_exists('aimee_global_market') ? aimee_global_market($params['market'] ?? null) : 'uk');
    if ($market !== 'uk') {
        return new WP_REST_Response(['status'=>'error','message'=>'Recurring Pay by Bank is currently available for UK memberships only.'], 400);
    }
    $plans = aimee_membership_plans('uk');
    if (!isset($plans[$plan_key])) return new WP_REST_Response(['status'=>'error','message'=>'That membership option is unavailable.'], 400);
    $plan = $plans[$plan_key];
    $amount_minor = max(1, (int) $plan['amount_pence']);
    $currency = 'GBP';

    $checkout_lock_token = aimee_acquire_subscription_checkout_lock($user_id);
    if ($checkout_lock_token === '') {
        return new WP_REST_Response(['status'=>'checkout_in_progress','message'=>'A secure checkout is already being prepared.'], 409);
    }

    try {
        $profile_table = aimee_table('aimee_user_profiles');
        $profile = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$profile_table} WHERE user_id=%d", $user_id));
        if (!$profile) return new WP_REST_Response(['status'=>'error','message'=>'Aimee profile not found.'], 404);
        if (!hash_equals($checkout_lock_token, (string) ($profile->billing_checkout_lock_token ?? ''))) {
            return new WP_REST_Response(['status'=>'billing_state_error','message'=>'The secure checkout lock could not be verified.'], 503);
        }
        if (
            !empty($profile->account_deletion_started_at)
            || !hash_equals('uk', sanitize_key((string) ($profile->market ?? '')))
        ) {
            return new WP_REST_Response([
                'status'=>'billing_state_changed',
                'message'=>'The account market or deletion state changed before bank checkout was locked.',
            ], 409);
        }
        if (
            !function_exists('aimee_refresh_subscription_checkout_lock')
            || !aimee_refresh_subscription_checkout_lock($user_id, $checkout_lock_token)
        ) {
            return new WP_REST_Response([
                'status'=>'billing_lock_lost',
                'message'=>'The secure bank-checkout lease expired before provider reconciliation.',
            ], 503);
        }

        if (function_exists('aimee_global_service_grace_checkout_is_paused') && aimee_global_service_grace_checkout_is_paused($profile)) {
            return new WP_REST_Response([
                'status'=>'service_grace_active',
                'message'=>'August access is complimentary. New membership checkout opens on 1 September 2026.',
                'subscription'=>aimee_get_subscription_snapshot($user_id, $profile),
            ], 409);
        }
        if (function_exists('aimee_global_managed_subscription_is_active') && aimee_global_managed_subscription_is_active($profile)) {
            return rest_ensure_response(['status'=>'already_active','subscription'=>aimee_get_subscription_snapshot($user_id, $profile)]);
        }
        $fair_first_payment_at = function_exists('aimee_global_service_grace_first_payment_timestamp')
            ? (int) aimee_global_service_grace_first_payment_timestamp($profile)
            : 0;
        if ($fair_first_payment_at > time()) {
            return new WP_REST_Response([
                'status'=>'existing_access_active',
                'message'=>'Your existing access is still active. To avoid charging you early, GoCardless checkout opens when that access ends.',
                'checkout_opens_at'=>gmdate('c', $fair_first_payment_at),
                'charge_today'=>false,
                'payment_scheduled'=>false,
                'provider'=>'gocardless',
                'subscription'=>aimee_get_subscription_snapshot($user_id, $profile),
            ], 409);
        }
        $open_payment = aimee_gocardless_profile_has_open_payment($profile);
        if (is_wp_error($open_payment)) {
            return new WP_REST_Response(['status'=>'billing_schema_error','message'=>$open_payment->get_error_message()], 503);
        }
        if ($open_payment) {
            return new WP_REST_Response([
                'status'=>'payment_in_progress',
                'message'=>'A bank payment is still being confirmed. Please wait before starting another checkout.',
            ], 409);
        }

        $has_stripe_checkout_intent = sanitize_key((string) ($profile->billing_checkout_intent_provider ?? '')) === 'stripe'
            && in_array(
                sanitize_key((string) ($profile->billing_checkout_intent_status ?? '')),
                ['prepared', 'requesting', 'request_unknown', 'session_bound'],
                true
            );
        if (
            !empty($profile->stripe_checkout_session_id)
            || !empty($profile->stripe_subscription_id)
            || !empty($profile->stripe_customer_id)
            || $has_stripe_checkout_intent
        ) {
            if (!function_exists('aimee_membership_retire_stripe_before_bank_checkout')) {
                return new WP_REST_Response(['status'=>'billing_configuration_error','message'=>'Existing card billing could not be safely managed.'], 503);
            }
            $stripe_retired = aimee_membership_retire_stripe_before_bank_checkout($profile);
            if (is_wp_error($stripe_retired)) {
                return new WP_REST_Response(['status'=>'provider_transition_blocked','message'=>'Existing card billing is not verified as terminal. No bank checkout was created.'], 409);
            }
            $profile = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$profile_table} WHERE user_id=%d", $user_id));
            if (!$profile || !hash_equals($checkout_lock_token, (string) ($profile->billing_checkout_lock_token ?? ''))) {
                return new WP_REST_Response(['status'=>'billing_state_error','message'=>'The card-to-bank transition could not be verified under the checkout lock.'], 503);
            }
        }

        if (!empty($profile->account_deletion_started_at)) {
            return new WP_REST_Response([
                'status'=>'account_deletion_in_progress',
                'message'=>'A bank checkout cannot start while account deletion is in progress.',
            ], 409);
        }

        $stored_intent_token = trim((string) ($profile->billing_checkout_intent_token ?? ''));
        $stored_intent_status = sanitize_key((string) ($profile->billing_checkout_intent_status ?? ''));
        $stored_intent_exact = $stored_intent_token !== ''
            && hash_equals('gocardless', sanitize_key((string) ($profile->billing_checkout_intent_provider ?? '')))
            && hash_equals($plan_key, sanitize_key((string) ($profile->billing_checkout_intent_plan ?? '')))
            && hash_equals('uk', sanitize_key((string) ($profile->billing_checkout_intent_market ?? '')))
            && hash_equals(aimee_gocardless_generation(), (string) ($profile->billing_checkout_intent_generation ?? ''));
        $stored_intent_abandoned = aimee_gocardless_stored_intent_scheme_abandoned(
            $profile,
            aimee_gocardless_mandate_scheme()
        );
        if (!$stored_intent_exact
            && !$stored_intent_abandoned
            && in_array($stored_intent_status, ['requesting', 'request_unknown'], true)
            && empty($profile->gocardless_billing_request_id)) {
            return new WP_REST_Response([
                'status'=>'billing_reconciliation_required',
                'message'=>'A previous bank checkout request is still being reconciled. Please retry later.',
            ], 409);
        }
        $reuse_checkout_intent = $stored_intent_exact
            && !$stored_intent_abandoned
            && in_array($stored_intent_status, [
                'prepared', 'requesting', 'request_unknown',
                'billing_request_bound', 'flow_bound',
            ], true);
        $checkout_intent_token = $reuse_checkout_intent
            ? $stored_intent_token
            : wp_generate_uuid4();
        if ($reuse_checkout_intent) {
            $billing_request_payload = json_decode(
                (string) ($profile->billing_checkout_intent_payload ?? ''),
                true
            );
            if (!aimee_gocardless_checkout_intent_payload_matches(
                $billing_request_payload,
                $user_id,
                $checkout_intent_token,
                $plan_key,
                $amount_minor,
                $currency
            )) {
                return new WP_REST_Response([
                    'status'=>'billing_reconciliation_required',
                    'message'=>'The stored bank checkout payload does not match its durable intent.',
                ], 409);
            }
        } else {
            $billing_request_payload = aimee_gocardless_build_checkout_intent_payload(
                $user_id,
                $checkout_intent_token,
                $plan_key,
                $amount_minor,
                $currency,
                $plan
            );
        }
        $intent_payload_json = wp_json_encode($billing_request_payload);
        if (!is_string($intent_payload_json) || $intent_payload_json === '') {
            return new WP_REST_Response([
                'status'=>'billing_state_error',
                'message'=>'The durable bank checkout payload could not be encoded.',
            ], 503);
        }
        $intent_saved = aimee_gocardless_profile_update_verified($user_id, [
            'billing_checkout_intent_token'      => $checkout_intent_token,
            'billing_checkout_intent_provider'   => 'gocardless',
            'billing_checkout_intent_plan'       => $plan_key,
            'billing_checkout_intent_market'     => 'uk',
            'billing_checkout_intent_generation' => aimee_gocardless_generation(),
            'billing_checkout_intent_status'     => $reuse_checkout_intent
                ? $stored_intent_status
                : 'prepared',
            'billing_checkout_intent_payload'    => $intent_payload_json,
            'gocardless_authorized_plan'         => $plan_key,
            'gocardless_authorized_amount_minor' => $amount_minor,
            'gocardless_authorized_currency'     => $currency,
            'market'                             => 'uk',
        ], [
            'billing_checkout_lock_token' => $checkout_lock_token,
            'account_deletion_started_at' => null,
        ]);
        if (is_wp_error($intent_saved)
            || !hash_equals($checkout_lock_token, (string) ($intent_saved->billing_checkout_lock_token ?? ''))
            || !hash_equals($checkout_intent_token, (string) ($intent_saved->billing_checkout_intent_token ?? ''))) {
            return new WP_REST_Response([
                'status'=>'billing_state_error',
                'message'=>'The durable bank checkout intent could not be verified.',
            ], 503);
        }
        $profile = $intent_saved;

        $prior_billing_request_id = sanitize_text_field((string) ($profile->gocardless_billing_request_id ?? ''));
        $prior_flow_id = sanitize_text_field((string) ($profile->gocardless_billing_request_flow_id ?? ''));
        $mandates_to_cancel = [];
        $stored_mandate_id = sanitize_text_field((string) ($profile->gocardless_mandate_id ?? ''));
        if (!$reuse_checkout_intent && $stored_mandate_id !== '') $mandates_to_cancel[] = $stored_mandate_id;
        if (!$reuse_checkout_intent && $prior_billing_request_id !== '') {
            $request_cancelled = aimee_gocardless_cancel_billing_request_id($prior_billing_request_id);
            if (is_wp_error($request_cancelled)) {
                if ($request_cancelled->get_error_code() !== 'gc_billing_request_fulfilled') {
                    return new WP_REST_Response(['status'=>'error','message'=>'The previous bank authorisation request could not be safely superseded.'], 502);
                }
                $fulfilled_data = $request_cancelled->get_error_data();
                $fulfilled_mandate_id = is_array($fulfilled_data)
                    ? sanitize_text_field((string) ($fulfilled_data['mandate_id'] ?? ''))
                    : '';
                if ($fulfilled_mandate_id !== '') $mandates_to_cancel[] = $fulfilled_mandate_id;
                if (!$mandates_to_cancel) {
                    return new WP_REST_Response(['status'=>'error','message'=>'The fulfilled bank authorisation did not expose a mandate that could be cancelled.'], 502);
                }
            }
        }
        foreach (array_values(array_unique($mandates_to_cancel)) as $old_mandate_id) {
            $old_cancelled = aimee_gocardless_cancel_mandate_id($old_mandate_id);
            if (is_wp_error($old_cancelled)) {
                return new WP_REST_Response(['status'=>'error','message'=>'The previous bank authorisation could not be safely superseded.'], 502);
            }
        }

        $expected_br_profile = (object) [
            'user_id'                             => $user_id,
            'gocardless_authorized_plan'          => $plan_key,
            'gocardless_authorized_amount_minor'  => $amount_minor,
            'gocardless_authorized_currency'      => $currency,
        ];

        // The durable intent exists before the first provider POST. Resolve
        // the complete provider collection by its exact metadata before and
        // after a create so a timeout can never spawn a second authority or
        // leave an unowned Billing Request behind.
        $intent_matches = aimee_gocardless_list_billing_requests_for_intent($checkout_intent_token);
        if (is_wp_error($intent_matches) || count($intent_matches) > 1) {
            return new WP_REST_Response([
                'status'=>'billing_reconciliation_required',
                'message'=>'The bank checkout intent could not be uniquely reconciled.',
            ], 502);
        }
        $br = count($intent_matches) === 1 ? $intent_matches[0] : [];
        if ($br && $prior_billing_request_id !== ''
            && $reuse_checkout_intent
            && !hash_equals($prior_billing_request_id, (string) ($br['id'] ?? ''))) {
            return new WP_REST_Response([
                'status'=>'billing_reconciliation_required',
                'message'=>'The stored and provider bank-authorisation identities disagree.',
            ], 409);
        }

        if (!$br) {
            $requesting = aimee_gocardless_profile_update_verified($user_id, [
                'billing_checkout_intent_status' => 'requesting',
            ], [
                'billing_checkout_lock_token' => $checkout_lock_token,
                'billing_checkout_intent_token' => $checkout_intent_token,
                'account_deletion_started_at' => null,
            ]);
            if (is_wp_error($requesting)) {
                return new WP_REST_Response(['status'=>'billing_state_error','message'=>$requesting->get_error_message()], 503);
            }
            if (!aimee_refresh_subscription_checkout_lock($user_id, $checkout_lock_token)) {
                return new WP_REST_Response([
                    'status'=>'billing_lock_lost',
                    'message'=>'The secure bank-checkout lease expired before authorisation creation.',
                ], 503);
            }
            $idem = 'aimee-gc-br-' . hash('sha256', $checkout_intent_token);
            $created = aimee_gocardless_idempotent_create(
                'billing_requests',
                'billing_requests',
                $billing_request_payload,
                $idem
            );
            $created_br = is_wp_error($created) ? [] : aimee_gocardless_unwrap($created, 'billing_requests');

            $intent_matches = aimee_gocardless_list_billing_requests_for_intent($checkout_intent_token);
            if (is_wp_error($intent_matches) || count($intent_matches) !== 1) {
                aimee_gocardless_profile_update_verified($user_id, [
                    'billing_checkout_intent_status' => 'request_unknown',
                ], [
                    'billing_checkout_lock_token' => $checkout_lock_token,
                    'billing_checkout_intent_token' => $checkout_intent_token,
                ]);
                return new WP_REST_Response([
                    'status'=>'billing_reconciliation_required',
                    'message'=>'The bank checkout create result is ambiguous and will not be repeated.',
                ], 502);
            }
            $br = $intent_matches[0];
            if (!empty($created_br['id'])
                && !hash_equals((string) $created_br['id'], (string) ($br['id'] ?? ''))) {
                return new WP_REST_Response([
                    'status'=>'billing_reconciliation_required',
                    'message'=>'The bank checkout create returned a conflicting resource identity.',
                ], 502);
            }
        }

        if (!aimee_gocardless_billing_request_matches_intent(
            $br,
            $expected_br_profile,
            $checkout_intent_token
        )) {
            if (!empty($br['id'])) {
                aimee_gocardless_cancel_billing_request_or_mandate($br['id']);
            }
            return new WP_REST_Response(['status'=>'billing_terms_error','message'=>'The bank authorisation response did not match this checkout.'], 502);
        }

        $br_id = sanitize_text_field((string) $br['id']);
        $br_saved = aimee_gocardless_profile_update_verified($user_id, [
            'billing_provider'                    => 'gocardless',
            'billing_account_generation'          => aimee_gocardless_generation(),
            'billing_migration_status'             => 'checkout_pending',
            'subscription_plan'                    => $plan_key,
            'subscription_cancel_at_period_end'    => 0,
            'gocardless_customer_id'               => null,
            'gocardless_mandate_id'                => null,
            'gocardless_mandate_scheme'            => null,
            'gocardless_payment_id'                => null,
            'gocardless_payment_status'            => null,
            'gocardless_next_payment_at'           => null,
            'gocardless_cancelled_at'              => null,
            'gocardless_billing_request_id'        => $br_id,
            'gocardless_billing_request_flow_id'   => $reuse_checkout_intent && $prior_flow_id !== ''
                ? $prior_flow_id
                : null,
            'gocardless_authorized_plan'           => $plan_key,
            'gocardless_authorized_amount_minor'   => $amount_minor,
            'gocardless_authorized_currency'       => $currency,
            'gocardless_renewal_attempt'            => 0,
            'gocardless_retry_after'                => null,
            'billing_checkout_intent_status'       => $reuse_checkout_intent && $prior_flow_id !== ''
                ? 'flow_bound'
                : 'billing_request_bound',
            'market'                               => 'uk',
        ], [
            'billing_checkout_lock_token' => $checkout_lock_token,
            'billing_checkout_intent_token' => $checkout_intent_token,
            'billing_checkout_intent_provider' => 'gocardless',
            'billing_checkout_intent_generation' => aimee_gocardless_generation(),
            'account_deletion_started_at' => null,
        ]);
        if (is_wp_error($br_saved)) {
            $compensated = aimee_gocardless_cancel_billing_request_or_mandate($br_id);
            return new WP_REST_Response([
                'status'=>'billing_state_error',
                'message'=>is_wp_error($compensated)
                    ? 'The bank authorisation could not be bound or safely retired.'
                    : 'The bank authorisation was retired because its local owner could not be saved.',
            ], 503);
        }
        $profile = $br_saved;

        if ($reuse_checkout_intent && $prior_flow_id !== '') {
            $stored_flow = aimee_gocardless_retrieve_billing_request_flow($prior_flow_id);
            if (
                is_wp_error($stored_flow)
                || !hash_equals($prior_flow_id, (string) ($stored_flow['id'] ?? ''))
                || !hash_equals($br_id, (string) ($stored_flow['links']['billing_request'] ?? ''))
                || empty($stored_flow['authorisation_url'])
            ) {
                return new WP_REST_Response([
                    'status'=>'billing_reconciliation_required',
                    'message'=>'The stored hosted bank flow could not be authoritatively verified.',
                ], 502);
            }
            return rest_ensure_response([
                'status'=>'success',
                'checkout_url'=>esc_url_raw($stored_flow['authorisation_url']),
                'billing_request_id'=>$br_id,
                'provider'=>'gocardless',
                'charge_today'=>true,
                'reused'=>true,
            ]);
        }

        $chat_url = function_exists('aimee_global_route') ? aimee_global_route('chat', 'uk') : home_url('/chat/');
        $stored_intent_payload = json_decode(
            (string) ($profile->billing_checkout_intent_payload ?? ''),
            true
        );
        if (!is_array($stored_intent_payload)) {
            return new WP_REST_Response([
                'status'=>'billing_reconciliation_required',
                'message'=>'The bank checkout payload could not be re-read before hosted-flow creation.',
            ], 503);
        }
        $flow_body = is_array($stored_intent_payload['aimee_flow_body'] ?? null)
            ? $stored_intent_payload['aimee_flow_body']
            : [
                'billing_request_flows' => [
                    'redirect_uri' => add_query_arg(['membership'=>'success','provider'=>'gocardless','billing_request'=>$br_id], $chat_url),
                    'exit_uri' => add_query_arg(['membership'=>'cancelled','provider'=>'gocardless'], $chat_url),
                    'links' => ['billing_request' => $br_id],
                ],
            ];
        $flow_terms = is_array($flow_body['billing_request_flows'] ?? null)
            ? $flow_body['billing_request_flows']
            : [];
        if (
            !hash_equals($br_id, (string) ($flow_terms['links']['billing_request'] ?? ''))
            || empty($flow_terms['redirect_uri'])
            || empty($flow_terms['exit_uri'])
        ) return new WP_REST_Response([
            'status'=>'billing_reconciliation_required',
            'message'=>'The immutable hosted-flow terms do not match the bank authorisation.',
        ], 409);
        $stored_intent_payload['aimee_flow_body'] = $flow_body;
        $flow_payload_json = wp_json_encode($stored_intent_payload);
        if (!is_string($flow_payload_json) || $flow_payload_json === '') {
            return new WP_REST_Response(['status'=>'billing_state_error','message'=>'The hosted-flow intent could not be encoded.'], 503);
        }
        $flow_intent_saved = aimee_gocardless_profile_update_verified($user_id, [
            'billing_checkout_intent_payload' => $flow_payload_json,
        ], [
            'billing_checkout_lock_token' => $checkout_lock_token,
            'billing_checkout_intent_token' => $checkout_intent_token,
            'gocardless_billing_request_id' => $br_id,
            'account_deletion_started_at' => null,
        ]);
        if (is_wp_error($flow_intent_saved)) {
            return new WP_REST_Response(['status'=>'billing_state_error','message'=>$flow_intent_saved->get_error_message()], 503);
        }
        if (!aimee_refresh_subscription_checkout_lock($user_id, $checkout_lock_token)) {
            return new WP_REST_Response([
                'status'=>'billing_lock_lost',
                'message'=>'The secure bank-checkout lease expired before hosted-flow creation.',
            ], 503);
        }
        $flow_response = aimee_gocardless_idempotent_create(
            'billing_request_flows',
            'billing_request_flows',
            $flow_body,
            'aimee-gc-flow-' . $br['id']
        );
        if (is_wp_error($flow_response)) {
            $compensated = aimee_gocardless_compensate_checkout_intent(
                $user_id,
                $checkout_lock_token,
                $checkout_intent_token,
                $br_id
            );
            return new WP_REST_Response([
                'status'=>'error',
                'message'=>is_wp_error($compensated)
                    ? 'The hosted bank flow failed and its authorisation could not be safely retired.'
                    : $flow_response->get_error_message(),
            ], 502);
        }
        $flow = aimee_gocardless_unwrap($flow_response, 'billing_request_flows');
        if (empty($flow['id']) || empty($flow['authorisation_url'])
            || !hash_equals($br_id, (string) ($flow['links']['billing_request'] ?? ''))) {
            $compensated = aimee_gocardless_compensate_checkout_intent(
                $user_id,
                $checkout_lock_token,
                $checkout_intent_token,
                $br_id
            );
            if (is_wp_error($compensated)) {
                return new WP_REST_Response([
                    'status'=>'billing_reconciliation_required',
                    'message'=>'The hosted bank flow was invalid and its authorisation could not be safely retired.',
                ], 502);
            }
            return new WP_REST_Response(['status'=>'error','message'=>'The secure bank authorisation page could not be created.'], 502);
        }

        $saved = aimee_gocardless_profile_update_verified($user_id, [
            'billing_provider'                     => 'gocardless',
            'billing_account_generation'           => aimee_gocardless_generation(),
            'billing_migration_status'              => 'checkout_pending',
            'subscription_plan'                     => $plan_key,
            'subscription_cancel_at_period_end'=>0,
            'gocardless_customer_id'                => null,
            'gocardless_mandate_id'=>null,
            'gocardless_mandate_scheme'             => null,
            'gocardless_payment_id'=>null,
            'gocardless_payment_status'             => null,
            'gocardless_next_payment_at'            => null,
            'gocardless_cancelled_at'               => null,
            'gocardless_billing_request_id'         => sanitize_text_field($br['id']),
            'gocardless_billing_request_flow_id'    => sanitize_text_field($flow['id']),
            'gocardless_authorized_plan'            => $plan_key,
            'gocardless_authorized_amount_minor'    => $amount_minor,
            'gocardless_authorized_currency'        => $currency,
            'gocardless_renewal_attempt'             => 0,
            'gocardless_retry_after'                => null,
            'billing_checkout_intent_status'       => 'flow_bound',
            'market'                                => 'uk',
        ], [
            'billing_checkout_lock_token' => $checkout_lock_token,
            'billing_checkout_intent_token' => $checkout_intent_token,
            'billing_checkout_intent_provider' => 'gocardless',
            'billing_checkout_intent_generation' => aimee_gocardless_generation(),
            'gocardless_billing_request_id' => $br_id,
            'account_deletion_started_at' => null,
        ]);
        if (is_wp_error($saved)) {
            $compensated = aimee_gocardless_compensate_checkout_intent(
                $user_id,
                $checkout_lock_token,
                $checkout_intent_token,
                $br_id
            );
            return new WP_REST_Response([
                'status'=>'billing_state_error',
                'message'=>is_wp_error($compensated)
                    ? 'The hosted bank flow could not be saved or safely retired.'
                    : $saved->get_error_message(),
            ], 503);
        }

        return rest_ensure_response([
            'status'=>'success',
            'checkout_url'=>esc_url_raw($flow['authorisation_url']),
            'billing_request_id'=>sanitize_text_field($br['id']),
            'provider'=>'gocardless',
            'charge_today'=>true,
        ]);
    } finally {
        $released = aimee_gocardless_release_checkout_lock_verified($user_id, $checkout_lock_token);
        if (is_wp_error($released)) {
            return new WP_REST_Response(['status'=>'billing_state_error','message'=>$released->get_error_message()], 503);
        }
    }
}

function aimee_gocardless_retry_delay($attempt) {
    $attempt = max(1, (int) $attempt);
    return min(DAY_IN_SECONDS, HOUR_IN_SECONDS * (2 ** min(5, $attempt - 1)));
}

function aimee_gocardless_payment_cycle($profile, $reason) {
    $reason = sanitize_key((string) $reason);
    $billing_request_id = (string) ($profile->gocardless_billing_request_id ?? '');
    if ($reason === 'initial' || sanitize_key((string) ($profile->billing_migration_status ?? '')) !== 'complete') {
        return 'initial:' . substr(hash('sha256', $billing_request_id), 0, 32);
    }
    $boundary = strtotime((string) ($profile->subscription_current_period_end ?? '') . ' UTC');
    return $boundary ? 'renewal:' . gmdate('YmdHis', $boundary) : '';
}

function aimee_gocardless_payment_status_is_terminal_failure($status) {
    return in_array(sanitize_key((string) $status), [
        'failed', 'cancelled', 'canceled', 'charged_back',
        'customer_approval_denied', 'expired', 'creation_failed',
    ], true);
}

function aimee_gocardless_schedule_retry($profile, $attempt) {
    $retry_ts = time() + aimee_gocardless_retry_delay($attempt);
    $retry_at = gmdate('Y-m-d H:i:s', $retry_ts);
    return aimee_gocardless_profile_update_verified((int) $profile->user_id, [
        'gocardless_last_failure_at'  => current_time('mysql', true),
        'gocardless_renewal_attempt'  => max(1, (int) $attempt),
        'gocardless_retry_after'      => $retry_at,
        'gocardless_next_payment_at' => $retry_at,
    ], [
        'gocardless_mandate_id'         => (string) $profile->gocardless_mandate_id,
        'gocardless_billing_request_id' => (string) $profile->gocardless_billing_request_id,
    ]);
}

function aimee_gocardless_create_payment_for_user($user_id, $reason = 'renewal') {
    global $wpdb;
    if (!aimee_gocardless_ready()) return aimee_gocardless_readiness_error();
    $user_id = (int) $user_id;
    $reason = sanitize_key((string) $reason) === 'initial' ? 'initial' : 'renewal';
    $profile_table = aimee_table('aimee_user_profiles');
    $ledger_table = aimee_gocardless_payments_table();

    if (
        !function_exists('aimee_acquire_subscription_checkout_lock')
        || !function_exists('aimee_release_subscription_checkout_lock')
    ) return new WP_Error('gc_payment_lock_unavailable', 'Secure billing locking is unavailable.');
    $payment_lock_token = aimee_acquire_subscription_checkout_lock($user_id);
    if ($payment_lock_token === '') {
        return new WP_Error('gc_billing_operation_in_progress', 'Another secure billing operation is in progress.');
    }

    try {

    if ($wpdb->query('START TRANSACTION') === false) return new WP_Error('gc_transaction_failed', 'The payment claim could not be started.');
    $profile = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$profile_table} WHERE user_id=%d FOR UPDATE", $user_id));
    if (!$profile || empty($profile->gocardless_mandate_id) || empty($profile->gocardless_billing_request_id)) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('gc_missing_authorization', 'No current bank authorisation is available.');
    }
    if (!empty($profile->subscription_cancel_at_period_end)) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('gc_cancelled', 'This membership is set not to renew.');
    }
    if (!empty($profile->gocardless_cancelled_at)
        || !empty($profile->account_deletion_started_at)) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('gc_cancelled', 'This bank authorisation cannot create another payment.');
    }
    if (!hash_equals($payment_lock_token, (string) ($profile->billing_checkout_lock_token ?? ''))) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('gc_payment_lock_lost', 'The secure billing operation lock could not be verified.');
    }

    $plan_key = sanitize_key((string) ($profile->gocardless_authorized_plan ?? ''));
    $amount_minor = (int) ($profile->gocardless_authorized_amount_minor ?? 0);
    $currency = strtoupper(trim((string) ($profile->gocardless_authorized_currency ?? '')));
    if (!in_array($plan_key, ['weekly', 'monthly', 'annual'], true) || $amount_minor < 1 || $currency !== 'GBP') {
        $wpdb->query('ROLLBACK');
        return new WP_Error('gc_invalid_authorized_terms', 'The authorised bank-payment terms are incomplete.');
    }
    if (!hash_equals(aimee_gocardless_generation(), (string) ($profile->billing_account_generation ?? ''))
        || sanitize_key((string) ($profile->billing_provider ?? '')) !== 'gocardless') {
        $wpdb->query('ROLLBACK');
        return new WP_Error('gc_stale_authorization', 'The bank authorisation is not current.');
    }

    $reason = sanitize_key((string) ($profile->billing_migration_status ?? '')) === 'complete' ? $reason : 'initial';
    $cycle = aimee_gocardless_payment_cycle($profile, $reason);
    if ($cycle === '') {
        $wpdb->query('ROLLBACK');
        return new WP_Error('gc_missing_cycle', 'The next billing boundary is unavailable.');
    }

    $latest = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$ledger_table}
         WHERE user_id=%d AND mandate_id=%s AND cycle_key=%s
         ORDER BY attempt DESC LIMIT 1 FOR UPDATE",
        $user_id,
        (string) $profile->gocardless_mandate_id,
        $cycle
    ));
    $claim_token = wp_generate_password(40, false, false);
    $claim_expires_at = gmdate('Y-m-d H:i:s', time() + 5 * MINUTE_IN_SECONDS);
    $ledger = null;

    if ($latest && !aimee_gocardless_payment_status_is_terminal_failure($latest->status)) {
        if (!empty($latest->provider_payment_id) || !empty($latest->applied_at)) {
            if (!empty($latest->provider_payment_id)
                && (empty($profile->gocardless_payment_id)
                    || hash_equals((string) $profile->gocardless_payment_id, (string) $latest->provider_payment_id))) {
                $repaired = $wpdb->update($profile_table, [
                    'gocardless_payment_id'       => (string) $latest->provider_payment_id,
                    'gocardless_payment_status'   => sanitize_key((string) $latest->status),
                    'gocardless_next_payment_at'  => !empty($latest->applied_at)
                        ? ($profile->gocardless_next_payment_at ?? null)
                        : gmdate('Y-m-d H:i:s', time() + HOUR_IN_SECONDS),
                    'gocardless_retry_after'      => null,
                    'gocardless_renewal_attempt'  => (int) $latest->attempt,
                ], ['user_id' => $user_id]);
                if ($repaired === false) {
                    $wpdb->query('ROLLBACK');
                    return new WP_Error('gc_profile_write_failed', 'The existing provider payment could not be restored to the profile.');
                }
            }
            if ($wpdb->query('COMMIT') === false) return new WP_Error('gc_claim_commit_failed', 'The existing payment state could not be committed.');
            $verified_profile = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$profile_table} WHERE user_id=%d", $user_id));
            $repair_expected = !empty($latest->provider_payment_id)
                && (empty($profile->gocardless_payment_id)
                    || hash_equals((string) $profile->gocardless_payment_id, (string) $latest->provider_payment_id));
            if ($repair_expected && (!$verified_profile
                || !hash_equals((string) $latest->provider_payment_id, (string) ($verified_profile->gocardless_payment_id ?? '')))) {
                return new WP_Error('gc_profile_write_unverified', 'The existing provider payment could not be verified on the profile.');
            }
            return [
                'id' => (string) $latest->provider_payment_id,
                'status' => sanitize_key((string) $latest->status),
                '_aimee_existing' => true,
            ];
        }
        $claim_expiry = strtotime((string) $latest->claim_expires_at . ' UTC');
        if ($claim_expiry && $claim_expiry > time()) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('gc_payment_in_progress', 'A bank payment is already being prepared.');
        }
        $reclaimed = $wpdb->update($ledger_table, [
            'status'           => 'creating',
            'claim_token'      => $claim_token,
            'claim_expires_at' => $claim_expires_at,
            'updated_at'       => current_time('mysql', true),
        ], ['id' => (int) $latest->id]);
        if ($reclaimed === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('gc_claim_failed', 'The existing payment claim could not be recovered.');
        }
        $ledger = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$ledger_table} WHERE id=%d", (int) $latest->id));
    } elseif ($latest) {
        $retry_after = strtotime((string) ($profile->gocardless_retry_after ?? '') . ' UTC');
        if ($retry_after && $retry_after > time()) {
            $wpdb->query('COMMIT');
            return ['_aimee_deferred' => true, 'retry_at' => gmdate('c', $retry_after)];
        }
    }

    if (!$ledger) {
        $attempt = $latest ? ((int) $latest->attempt + 1) : 1;
        $idempotency_key = 'aimee-gc-pay-' . hash('sha256', implode('|', [
            $user_id,
            (string) $profile->gocardless_mandate_id,
            (string) $profile->gocardless_billing_request_id,
            $plan_key,
            $amount_minor,
            $currency,
            $cycle,
            $attempt,
        ]));
        $now = current_time('mysql', true);
        $inserted = $wpdb->insert($ledger_table, [
            'provider_payment_id' => null,
            'idempotency_key'     => $idempotency_key,
            'user_id'             => $user_id,
            'mandate_id'          => (string) $profile->gocardless_mandate_id,
            'billing_request_id'  => (string) $profile->gocardless_billing_request_id,
            'plan'                => $plan_key,
            'amount_minor'        => $amount_minor,
            'currency'            => $currency,
            'cycle_key'           => $cycle,
            'attempt'             => $attempt,
            'reason'              => $reason,
            'status'              => 'creating',
            'claim_token'         => $claim_token,
            'claim_expires_at'    => $claim_expires_at,
            'applied_at'          => null,
            'period_start'        => null,
            'period_end'          => null,
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);
        if ($inserted !== 1) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('gc_ledger_claim_failed', 'The payment attempt could not be recorded.');
        }
        $ledger = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$ledger_table} WHERE id=%d", (int) $wpdb->insert_id));
    }

    if (!$ledger || !hash_equals($claim_token, (string) ($ledger->claim_token ?? ''))) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('gc_claim_unverified', 'The payment attempt claim could not be verified.');
    }
    if ($wpdb->query('COMMIT') === false) return new WP_Error('gc_claim_commit_failed', 'The payment attempt claim could not be committed.');

    $mandate = aimee_gocardless_retrieve_mandate((string) $ledger->mandate_id);
    if (is_wp_error($mandate)) {
        $ledger_failure = aimee_gocardless_ledger_update_verified((int) $ledger->id, [
            'status' => 'request_unknown',
            'claim_token' => null,
            'claim_expires_at' => $claim_expires_at,
            'updated_at' => current_time('mysql', true),
        ]);
        $profile_failure = aimee_gocardless_schedule_retry($profile, (int) $ledger->attempt);
        if (is_wp_error($ledger_failure)) return $ledger_failure;
        if (is_wp_error($profile_failure)) return $profile_failure;
        return $mandate;
    }
    $mandate_status = sanitize_key((string) ($mandate['status'] ?? ''));
    if (!in_array($mandate_status, ['active', 'submitted', 'pending_submission'], true)) {
        $failure = aimee_gocardless_ledger_update_verified((int) $ledger->id, [
            'status' => 'creation_failed',
            'claim_token' => null,
            'claim_expires_at' => null,
            'updated_at' => current_time('mysql', true),
        ]);
        $retry = is_wp_error($failure) ? $failure : aimee_gocardless_schedule_retry($profile, (int) $ledger->attempt);
        if (is_wp_error($retry) && $retry->get_error_code() !== 'gc_mandate_not_active') return $retry;
        return new WP_Error('gc_mandate_not_active', 'The bank mandate is not active yet.');
    }
    $scheme = sanitize_key((string) ($mandate['scheme'] ?? 'faster_payments'));

    $plans = aimee_membership_plans('uk');
    $label = isset($plans[$ledger->plan]['label']) ? $plans[$ledger->plan]['label'] : 'Aimee';
    $payment_body = [
        'payments' => [
            'amount' => (int) $ledger->amount_minor,
            'currency' => (string) $ledger->currency,
            'description' => $label . ' membership',
            'metadata' => [
                'aimee_payment_key' => (string) $ledger->idempotency_key,
                'aimee_plan' => (string) $ledger->plan,
                'aimee_generation' => aimee_gocardless_generation(),
            ],
            'links' => ['mandate' => (string) $ledger->mandate_id],
        ],
    ];
    if ($scheme === 'faster_payments') {
        $payment_body['payments']['psu_interaction_type'] = 'off_session';
        $payment_body['payments']['charge_date'] = gmdate('Y-m-d');
    } else {
        $payment_body['payments']['retry_if_possible'] = true;
    }

    $created = aimee_gocardless_idempotent_create(
        'payments',
        'payments',
        $payment_body,
        (string) $ledger->idempotency_key
    );
    if (is_wp_error($created)) {
        $api_status = aimee_gocardless_api_error_status($created);
        $definitive = $api_status >= 400 && $api_status < 500;
        $ledger_failure = aimee_gocardless_ledger_update_verified((int) $ledger->id, [
            'status' => $definitive ? 'creation_failed' : 'request_unknown',
            'claim_token' => null,
            'claim_expires_at' => $definitive ? null : $claim_expires_at,
            'updated_at' => current_time('mysql', true),
        ]);
        $profile_failure = aimee_gocardless_schedule_retry($profile, (int) $ledger->attempt);
        if (is_wp_error($ledger_failure)) return $ledger_failure;
        if (is_wp_error($profile_failure)) return $profile_failure;
        return $created;
    }
    $payment = aimee_gocardless_unwrap($created, 'payments');
    if (empty($payment['id']) || !aimee_gocardless_payment_matches_ledger($payment, $ledger)) {
        // Do not advance to another attempt/key after an ambiguous or
        // mismatched create response. The same ledger claim is reconciled on
        // its next retry, preventing a response-loss duplicate charge.
        $ledger_unknown = aimee_gocardless_ledger_update_verified((int) $ledger->id, [
            'status' => 'request_unknown',
            'claim_token' => null,
            'claim_expires_at' => $claim_expires_at,
            'updated_at' => current_time('mysql', true),
        ]);
        $profile_unknown = aimee_gocardless_schedule_retry($profile, (int) $ledger->attempt);
        if (is_wp_error($ledger_unknown)) return $ledger_unknown;
        if (is_wp_error($profile_unknown)) return $profile_unknown;
        return new WP_Error(
            'gc_payment_reconciliation_unknown',
            'The bank payment response could not yet be matched to its immutable attempt.'
        );
    }
    $payment_id = sanitize_text_field((string) $payment['id']);
    $payment_status = sanitize_key((string) ($payment['status'] ?? 'pending_submission'));

    $ledger_saved = aimee_gocardless_ledger_update_verified((int) $ledger->id, [
        'provider_payment_id' => $payment_id,
        'status' => $payment_status,
        'claim_token' => null,
        'claim_expires_at' => null,
        'updated_at' => current_time('mysql', true),
    ]);
    if (is_wp_error($ledger_saved)) return $ledger_saved;

    $fresh_profile = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$profile_table} WHERE user_id=%d", $user_id));
    $authorization_current = $fresh_profile
        && hash_equals((string) $ledger->mandate_id, (string) ($fresh_profile->gocardless_mandate_id ?? ''))
        && hash_equals((string) $ledger->billing_request_id, (string) ($fresh_profile->gocardless_billing_request_id ?? ''))
        && hash_equals((string) $ledger->plan, (string) ($fresh_profile->gocardless_authorized_plan ?? ''))
        && (int) $ledger->amount_minor === (int) ($fresh_profile->gocardless_authorized_amount_minor ?? 0)
        && hash_equals((string) $ledger->currency, (string) ($fresh_profile->gocardless_authorized_currency ?? ''))
        && hash_equals($payment_lock_token, (string) ($fresh_profile->billing_checkout_lock_token ?? ''))
        && empty($fresh_profile->subscription_cancel_at_period_end)
        && empty($fresh_profile->gocardless_cancelled_at)
        && empty($fresh_profile->account_deletion_started_at);
    if (!$authorization_current) {
        $payment_cancelled = aimee_gocardless_cancel_payment_id($payment_id);
        if (is_wp_error($payment_cancelled)) return $payment_cancelled;
        return new WP_Error('gc_authorization_superseded', 'The payment authorisation changed while the payment was being created.');
    }

    $profile_saved = aimee_gocardless_profile_update_verified($user_id, [
        'gocardless_payment_id'       => $payment_id,
        'gocardless_payment_status'   => $payment_status,
        'gocardless_mandate_scheme'   => $scheme,
        // Until a terminal webhook arrives, the worker periodically retrieves
        // this same immutable provider payment instead of creating another.
        'gocardless_next_payment_at'  => gmdate('Y-m-d H:i:s', time() + HOUR_IN_SECONDS),
        'gocardless_retry_after'      => null,
        'gocardless_renewal_attempt'  => (int) $ledger->attempt,
    ], [
        'gocardless_mandate_id'         => (string) $ledger->mandate_id,
        'gocardless_billing_request_id' => (string) $ledger->billing_request_id,
        'billing_checkout_lock_token'   => $payment_lock_token,
        'subscription_cancel_at_period_end' => 0,
        'gocardless_cancelled_at'       => null,
        'account_deletion_started_at'   => null,
    ]);
    if (is_wp_error($profile_saved)) {
        $payment_cancelled = aimee_gocardless_cancel_payment_id($payment_id);
        if (is_wp_error($payment_cancelled)) return $payment_cancelled;
        return new WP_Error(
            'gc_authorization_superseded',
            'The payment was retired because its authorisation changed before the local binding was saved.'
        );
    }

    if (aimee_gocardless_payment_status_is_terminal_failure($payment_status)) {
        $retry = aimee_gocardless_schedule_retry($profile_saved, (int) $ledger->attempt);
        return is_wp_error($retry) ? $retry : new WP_Error('gc_payment_failed', 'The bank payment was not accepted.');
    }
    return $payment;
    } finally {
        $released = aimee_gocardless_release_checkout_lock_verified($user_id, $payment_lock_token);
        if (is_wp_error($released)) return $released;
    }
}

function aimee_gocardless_billing_request_terms_match($br, $profile) {
    $meta = is_array($br['metadata'] ?? null) ? $br['metadata'] : [];
    $plan = sanitize_key((string) ($profile->gocardless_authorized_plan ?? ''));
    $amount = (int) ($profile->gocardless_authorized_amount_minor ?? 0);
    $currency = strtoupper((string) ($profile->gocardless_authorized_currency ?? ''));
    if (!hash_equals(aimee_gocardless_generation(), (string) ($meta['aimee_generation'] ?? ''))) return false;
    $terms = explode('|', (string) ($meta['aimee_terms'] ?? ''));
    // 1.8.3 embeds the user in the immutable terms tuple to reserve the third
    // and final GoCardless metadata pair for the durable checkout intent.
    // Accept the immediately preceding representation while existing hosted
    // authorisations are being reconciled during upgrade.
    if (count($terms) === 4) {
        if ((int) $terms[0] !== (int) $profile->user_id) return false;
        array_shift($terms);
    } elseif (count($terms) === 3) {
        if ((int) ($meta['aimee_user_id'] ?? 0) !== (int) $profile->user_id) return false;
    } else {
        return false;
    }
    if (!hash_equals($plan, sanitize_key((string) $terms[0]))) return false;
    if ($amount !== (int) $terms[1]) return false;
    if (!hash_equals($currency, strtoupper((string) $terms[2]))) return false;

    $mandate_request = is_array($br['mandate_request'] ?? null) ? $br['mandate_request'] : [];
    if (!hash_equals($currency, strtoupper((string) ($mandate_request['currency'] ?? '')))) return false;
    if (sanitize_key((string) ($mandate_request['scheme'] ?? '')) === 'bacs') {
        // Direct Debit: the amount and cadence are bound by the immutable
        // terms tuple verified above; the mandate itself carries no limits.
        return true;
    }
    $constraints = is_array($mandate_request['constraints'] ?? null) ? $mandate_request['constraints'] : [];
    if ((int) ($constraints['max_amount_per_payment'] ?? 0) !== $amount) return false;
    $periodic_limits = is_array($constraints['periodic_limits'] ?? null) ? $constraints['periodic_limits'] : [];
    $expected_period = $plan === 'weekly' ? 'week' : ($plan === 'annual' ? 'year' : 'month');
    foreach ($periodic_limits as $limit) {
        if (is_array($limit)
            && (int) ($limit['max_total_amount'] ?? 0) === $amount
            && hash_equals($expected_period, (string) ($limit['period'] ?? ''))
            && hash_equals('creation_date', (string) ($limit['alignment'] ?? ''))) return true;
    }
    return false;
}

function aimee_gocardless_billing_request_matches_intent($billing_request, $profile, $intent_token) {
    if (!is_array($billing_request) || empty($billing_request['id']) || !$profile) return false;
    $metadata = is_array($billing_request['metadata'] ?? null) ? $billing_request['metadata'] : [];
    return trim((string) $intent_token) !== ''
        && hash_equals((string) $intent_token, (string) ($metadata['aimee_checkout_intent'] ?? ''))
        && aimee_gocardless_billing_request_terms_match($billing_request, $profile);
}

function aimee_gocardless_profile_intent_billing_requests_for_retirement($profile) {
    if (!$profile || empty($profile->user_id)) {
        return new WP_Error('gc_profile_missing', 'The bank billing profile was not found.');
    }
    $stored_id = sanitize_text_field((string) ($profile->gocardless_billing_request_id ?? ''));
    $intent_token = trim((string) ($profile->billing_checkout_intent_token ?? ''));
    $intent_provider = sanitize_key((string) ($profile->billing_checkout_intent_provider ?? ''));
    $intent_status = sanitize_key((string) ($profile->billing_checkout_intent_status ?? ''));

    // Legacy profiles predate durable intent metadata but still expose their
    // exact Billing Request identity.
    if ($intent_token === '') return $stored_id === '' ? [] : [$stored_id];
    if ($intent_provider !== 'gocardless') {
        return $stored_id === '' ? [] : [$stored_id];
    }
    if ($intent_status === 'prepared' && $stored_id === '') {
        // `prepared` is committed before the code records `requesting` and
        // before the provider POST. It is therefore a durable no-POST proof.
        return [];
    }

    $matches = aimee_gocardless_list_billing_requests_for_intent($intent_token);
    if (is_wp_error($matches)) return $matches;
    if (count($matches) > 1) {
        return new WP_Error('gc_checkout_intent_ambiguous', 'More than one Billing Request matched the checkout intent.');
    }
    if (count($matches) === 0) {
        if ($stored_id !== '' || in_array($intent_status, ['requesting', 'request_unknown'], true)) {
            return new WP_Error(
                'gc_checkout_intent_unresolved',
                'A provider-possible bank checkout intent has no authoritative resource binding yet.'
            );
        }
        return [];
    }

    $billing_request = $matches[0];
    $matched_id = sanitize_text_field((string) ($billing_request['id'] ?? ''));
    if ($stored_id !== '' && !hash_equals($stored_id, $matched_id)) {
        return new WP_Error('gc_checkout_intent_identity_mismatch', 'The stored Billing Request differs from its checkout intent.');
    }
    if (!aimee_gocardless_billing_request_matches_intent($billing_request, $profile, $intent_token)) {
        return new WP_Error('gc_checkout_intent_terms_mismatch', 'The Billing Request did not match its durable checkout intent.');
    }
    return [$matched_id];
}

/**
 * Complete GoCardless retirement gate for account deletion. The caller must
 * retain all local rows unless this returns true.
 */
function aimee_gocardless_retire_user_billing_for_deletion($user_id, $lock_token = '') {
    global $wpdb;
    $user_id = (int) $user_id;
    $lock_token = trim((string) $lock_token);
    if ($user_id < 1) return new WP_Error('gc_invalid_user', 'The billing profile owner is invalid.');

    $profile_table = aimee_table('aimee_user_profiles');
    $ledger_table = aimee_gocardless_payments_table();
    $wpdb->last_error = '';
    $profile = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$profile_table} WHERE user_id=%d",
        $user_id
    ));
    if (!empty($wpdb->last_error) || !$profile) {
        return new WP_Error('gc_profile_read_failed', 'The bank billing profile could not be read.');
    }
    if ($lock_token !== ''
        && !hash_equals($lock_token, (string) ($profile->billing_checkout_lock_token ?? ''))) {
        return new WP_Error('gc_deletion_lock_lost', 'The account-deletion billing lock was lost.');
    }

    $wpdb->last_error = '';
    $ledger_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$ledger_table} WHERE user_id=%d ORDER BY id ASC",
        $user_id
    ));
    if (!empty($wpdb->last_error) || !is_array($ledger_rows)) {
        return new WP_Error('gc_ledger_read_failed', 'The complete bank-payment ledger could not be read.');
    }

    $billing_request_ids = [];
    $mandate_ids = [];
    foreach ($ledger_rows as $ledger_row) {
        $ledger_br = sanitize_text_field((string) ($ledger_row->billing_request_id ?? ''));
        $ledger_mandate = sanitize_text_field((string) ($ledger_row->mandate_id ?? ''));
        if ($ledger_br !== '') $billing_request_ids[$ledger_br] = true;
        if ($ledger_mandate !== '') $mandate_ids[$ledger_mandate] = true;
    }
    $profile_br = sanitize_text_field((string) ($profile->gocardless_billing_request_id ?? ''));
    $profile_mandate = sanitize_text_field((string) ($profile->gocardless_mandate_id ?? ''));
    if ($profile_br !== '') $billing_request_ids[$profile_br] = true;
    if ($profile_mandate !== '') $mandate_ids[$profile_mandate] = true;

    $intent_possible = trim((string) ($profile->billing_checkout_intent_token ?? '')) !== ''
        && sanitize_key((string) ($profile->billing_checkout_intent_provider ?? '')) === 'gocardless';
    if (!$ledger_rows && !$billing_request_ids && !$mandate_ids && !$intent_possible) return true;
    if (!aimee_gocardless_ready()) return aimee_gocardless_readiness_error();

    $intent_requests = aimee_gocardless_profile_intent_billing_requests_for_retirement($profile);
    if (is_wp_error($intent_requests)) return $intent_requests;
    foreach ($intent_requests as $intent_request_id) {
        $billing_request_ids[sanitize_text_field((string) $intent_request_id)] = true;
    }

    $payments_retired = aimee_gocardless_retire_user_ledger_payments($user_id);
    if (is_wp_error($payments_retired)) return $payments_retired;

    foreach (array_keys($billing_request_ids) as $billing_request_id) {
        $request_retired = aimee_gocardless_cancel_billing_request_or_mandate($billing_request_id);
        if (is_wp_error($request_retired)) return $request_retired;
    }
    foreach (array_keys($mandate_ids) as $mandate_id) {
        $mandate_retired = aimee_gocardless_cancel_mandate_id($mandate_id);
        if (is_wp_error($mandate_retired)) return $mandate_retired;
    }

    $profile_update = [
        'subscription_cancel_at_period_end' => 1,
        'gocardless_next_payment_at' => null,
        'gocardless_retry_after' => null,
        'gocardless_cancelled_at' => current_time('mysql', true),
    ];
    if ($intent_possible) $profile_update['billing_checkout_intent_status'] = 'retired';
    $where = [];
    if ($lock_token !== '') $where['billing_checkout_lock_token'] = $lock_token;
    $saved = aimee_gocardless_profile_update_verified($user_id, $profile_update, $where);
    return is_wp_error($saved) ? $saved : true;
}

function aimee_gocardless_sync_billing_request_for_user($user_id, $billing_request_id = '') {
    global $wpdb;
    if (!aimee_gocardless_ready()) return aimee_gocardless_readiness_error();
    $profile_table = aimee_table('aimee_user_profiles');
    $profile = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$profile_table} WHERE user_id=%d", (int) $user_id));
    if (!$profile) return new WP_Error('gc_profile_missing', 'The bank billing profile was not found.');

    $stored_id = sanitize_text_field((string) ($profile->gocardless_billing_request_id ?? ''));
    $requested_id = sanitize_text_field((string) $billing_request_id);
    if ($stored_id === '') return new WP_Error('gc_missing_billing_request', 'No current bank authorisation request was found.');
    if ($requested_id !== '' && !hash_equals($stored_id, $requested_id)) {
        return new WP_Error('gc_stale_billing_request', 'That bank authorisation has been superseded.');
    }

    $br = aimee_gocardless_retrieve_billing_request($stored_id);
    if (is_wp_error($br)) return $br;
    $br_status = sanitize_key((string) ($br['status'] ?? ''));
    if ($br_status !== 'fulfilled') {
        return new WP_Error('gc_billing_request_not_ready', 'The bank authorisation is not fulfilled yet.');
    }
    if (!aimee_gocardless_billing_request_terms_match($br, $profile)) {
        return new WP_Error('gc_billing_terms_mismatch', 'The bank authorisation terms do not match this checkout.');
    }
    if (!hash_equals(aimee_gocardless_generation(), (string) ($profile->billing_account_generation ?? ''))
        || !hash_equals((string) $profile->subscription_plan, (string) $profile->gocardless_authorized_plan)
        || sanitize_key((string) ($profile->billing_provider ?? '')) !== 'gocardless') {
        return new WP_Error('gc_profile_terms_mismatch', 'The local bank authorisation is no longer current.');
    }

    $mandate_id = aimee_gocardless_billing_request_mandate_id($br);
    if ($mandate_id === '') return new WP_Error('gc_mandate_not_ready', 'The bank mandate is not available yet.');
    $mandate = aimee_gocardless_retrieve_mandate($mandate_id);
    if (is_wp_error($mandate)) return $mandate;
    $mandate_status = sanitize_key((string) ($mandate['status'] ?? ''));
    if (!in_array($mandate_status, ['active', 'submitted', 'pending_submission'], true)) {
        return new WP_Error('gc_mandate_not_active', 'The bank mandate is not active yet.');
    }
    $scheme = sanitize_key((string) ($mandate['scheme'] ?? ''));
    $customer_id = sanitize_text_field((string) ($mandate['links']['customer'] ?? ''));

    $already_complete = sanitize_key((string) ($profile->billing_migration_status ?? '')) === 'complete';
    $saved = aimee_gocardless_profile_update_verified((int) $user_id, [
        'billing_provider'                  => 'gocardless',
        'billing_account_generation'        => aimee_gocardless_generation(),
        'billing_migration_status'           => $already_complete ? 'complete' : 'mandate_active',
        'gocardless_mandate_id'              => $mandate_id,
        'gocardless_customer_id'             => $customer_id ?: null,
        'gocardless_mandate_scheme'          => $scheme ?: null,
        'subscription_plan'                  => (string) $profile->gocardless_authorized_plan,
    ], [
        'gocardless_billing_request_id' => $stored_id,
        'account_deletion_started_at' => null,
    ]);
    if (is_wp_error($saved)) return $saved;

    if ($already_complete) return $br;

    $payment = aimee_gocardless_create_payment_for_user((int) $user_id, 'initial');
    if (is_wp_error($payment)) return $payment;
    return $br;
}

function aimee_gocardless_ledger_for_payment($payment) {
    global $wpdb;
    $table = aimee_gocardless_payments_table();
    $payment_id = sanitize_text_field((string) ($payment['id'] ?? ''));
    if ($payment_id === '') return new WP_Error('gc_invalid_payment', 'The provider payment is invalid.');
    $wpdb->last_error = '';
    $ledger = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE provider_payment_id=%s LIMIT 1", $payment_id));
    if (!empty($wpdb->last_error)) return new WP_Error('gc_ledger_read_failed', 'The provider payment ledger could not be read.');
    if ($ledger) return $ledger;

    $meta = is_array($payment['metadata'] ?? null) ? $payment['metadata'] : [];
    $idempotency_key = sanitize_text_field((string) ($meta['aimee_payment_key'] ?? ''));
    if ($idempotency_key !== '') {
        $wpdb->last_error = '';
        $ledger = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE idempotency_key=%s LIMIT 1", $idempotency_key));
        if (!empty($wpdb->last_error)) return new WP_Error('gc_ledger_read_failed', 'The idempotent payment ledger could not be read.');
        if ($ledger) {
            if (!empty($ledger->provider_payment_id)
                && !hash_equals((string) $ledger->provider_payment_id, $payment_id)) {
                return new WP_Error('gc_payment_identity_mismatch', 'The idempotency key is already bound to another provider payment.');
            }
            return aimee_gocardless_ledger_update_verified((int) $ledger->id, [
                'provider_payment_id' => $payment_id,
                'updated_at' => current_time('mysql', true),
            ]);
        }
    }

    $mandate_id = sanitize_text_field((string) ($payment['links']['mandate'] ?? ''));
    $mandate_owner = aimee_gocardless_find_user_for_mandate($mandate_id);
    if (is_wp_error($mandate_owner)) return $mandate_owner;
    $looks_aimee_owned = ($idempotency_key !== '' && strpos($idempotency_key, 'aimee-gc-pay-') === 0)
        || hash_equals(aimee_gocardless_generation(), (string) ($meta['aimee_generation'] ?? ''))
        || $mandate_owner > 0;
    return $looks_aimee_owned
        ? new WP_Error('gc_unlinked_payment', 'An Aimee bank payment has no immutable local ledger row.')
        : 0;
}

function aimee_gocardless_payment_matches_ledger($payment, $ledger) {
    $meta = is_array($payment['metadata'] ?? null) ? $payment['metadata'] : [];
    return hash_equals((string) $ledger->mandate_id, (string) ($payment['links']['mandate'] ?? ''))
        && (int) $ledger->amount_minor === (int) ($payment['amount'] ?? 0)
        && hash_equals(strtoupper((string) $ledger->currency), strtoupper((string) ($payment['currency'] ?? '')))
        && hash_equals((string) $ledger->idempotency_key, (string) ($meta['aimee_payment_key'] ?? ''))
        && hash_equals((string) $ledger->plan, sanitize_key((string) ($meta['aimee_plan'] ?? '')))
        && hash_equals(aimee_gocardless_generation(), (string) ($meta['aimee_generation'] ?? ''));
}

function aimee_gocardless_apply_payment($payment) {
    global $wpdb;
    if (!is_array($payment) || empty($payment['id'])) return new WP_Error('gc_invalid_payment', 'The provider payment is invalid.');
    if (!aimee_gocardless_ready()) return aimee_gocardless_readiness_error();
    $ledger = aimee_gocardless_ledger_for_payment($payment);
    if (is_wp_error($ledger) || $ledger === 0) return $ledger;
    if (!aimee_gocardless_payment_matches_ledger($payment, $ledger)) {
        return new WP_Error('gc_payment_terms_mismatch', 'The provider payment does not match its immutable ledger row.');
    }

    $ledger_table = aimee_gocardless_payments_table();
    $profile_table = aimee_table('aimee_user_profiles');
    $status = sanitize_key((string) ($payment['status'] ?? ''));
    $payment_id = sanitize_text_field((string) $payment['id']);
    if ($wpdb->query('START TRANSACTION') === false) return new WP_Error('gc_transaction_failed', 'The payment could not be applied safely.');
    $locked_ledger = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$ledger_table} WHERE id=%d FOR UPDATE", (int) $ledger->id));
    $profile = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$profile_table} WHERE user_id=%d FOR UPDATE", (int) $ledger->user_id));
    if (!$locked_ledger || !$profile) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('gc_payment_owner_missing', 'The payment owner could not be locked.');
    }

    $current_authorization = hash_equals((string) $locked_ledger->mandate_id, (string) ($profile->gocardless_mandate_id ?? ''))
        && hash_equals((string) $locked_ledger->billing_request_id, (string) ($profile->gocardless_billing_request_id ?? ''))
        && hash_equals((string) $locked_ledger->plan, (string) ($profile->gocardless_authorized_plan ?? ''))
        && (int) $locked_ledger->amount_minor === (int) ($profile->gocardless_authorized_amount_minor ?? 0)
        && hash_equals((string) $locked_ledger->currency, (string) ($profile->gocardless_authorized_currency ?? ''))
        && hash_equals(aimee_gocardless_generation(), (string) ($profile->billing_account_generation ?? ''))
        && sanitize_key((string) ($profile->billing_provider ?? '')) === 'gocardless'
        && empty($profile->account_deletion_started_at);

    if (!empty($locked_ledger->applied_at)) {
        $stored_status = sanitize_key((string) $locked_ledger->status);
        $effective_status = $status;
        if (aimee_gocardless_payment_status_is_terminal_failure($stored_status)
            && !aimee_gocardless_payment_status_is_terminal_failure($status)) {
            $effective_status = $stored_status;
        } elseif ($stored_status === 'paid_out' && $status === 'confirmed') {
            $effective_status = 'paid_out';
        }
        $applied_ledger_update = [
            'status' => $effective_status,
            'updated_at' => current_time('mysql', true),
        ];
        $applied_profile_update = [];
        $is_current_applied_payment = empty($profile->gocardless_payment_id)
            || hash_equals((string) $profile->gocardless_payment_id, $payment_id);
        if ($current_authorization && $is_current_applied_payment
            && in_array($effective_status, ['confirmed', 'paid_out'], true)) {
            $applied_profile_update = [
                'gocardless_payment_id'                => $payment_id,
                'gocardless_payment_status'            => $effective_status,
                'gocardless_last_confirmed_payment_id' => $payment_id,
            ];
            // A provisionally applied collection has now settled. Restore the
            // renewal boundary the hourly poll moved while it was pending.
            if (empty($profile->subscription_cancel_at_period_end) && !empty($locked_ledger->period_end)) {
                $applied_profile_update['gocardless_next_payment_at'] = (string) $locked_ledger->period_end;
                $applied_profile_update['gocardless_retry_after'] = null;
            }
        }
        if ($current_authorization && $is_current_applied_payment
            && aimee_gocardless_payment_status_is_terminal_failure($effective_status)) {
            $applied_profile_update += [
                'gocardless_payment_id'              => $payment_id,
                'gocardless_payment_status'          => $effective_status,
                'subscription_cancel_at_period_end' => 1,
                'gocardless_last_failure_at'         => current_time('mysql', true),
                'gocardless_next_payment_at'         => null,
                'gocardless_retry_after'             => null,
            ];
            if (aimee_gocardless_payment_status_is_provisional($stored_status)) {
                // The Direct Debit that opened this period never settled.
                // Provisional access ends now rather than at the period end;
                // a new checkout supersedes the mandate cleanly.
                $applied_profile_update['subscription_status'] = 'past_due';
                $applied_profile_update['subscription_current_period_end'] = current_time('mysql', true);
            }
        }
        $ledger_write = $wpdb->update($ledger_table, $applied_ledger_update, ['id' => (int) $locked_ledger->id]);
        $profile_write = $applied_profile_update
            ? $wpdb->update($profile_table, $applied_profile_update, ['user_id' => (int) $locked_ledger->user_id])
            : 0;
        if ($ledger_write === false || $profile_write === false || $wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('gc_ledger_write_failed', 'The applied payment state could not be updated.');
        }
        $check_ledger = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$ledger_table} WHERE id=%d", (int) $locked_ledger->id));
        $check_profile = $applied_profile_update
            ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$profile_table} WHERE user_id=%d", (int) $locked_ledger->user_id))
            : null;
        if (!aimee_gocardless_row_matches_updates($check_ledger, $applied_ledger_update)
            || ($applied_profile_update && !aimee_gocardless_row_matches_updates($check_profile, $applied_profile_update))) {
            return new WP_Error('gc_ledger_write_unverified', 'The applied payment state could not be verified.');
        }
        return (int) $locked_ledger->user_id;
    }

    if (!$current_authorization) {
        if (aimee_gocardless_payment_status_is_terminal_failure($status)) {
            $superseded_update = [
                'status' => $status,
                'updated_at' => current_time('mysql', true),
            ];
            $ledger_write = $wpdb->update($ledger_table, $superseded_update, ['id' => (int) $locked_ledger->id]);
            if ($ledger_write === false || $wpdb->query('COMMIT') === false) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('gc_ledger_write_failed', 'The superseded payment state could not be recorded.');
            }
            $check = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$ledger_table} WHERE id=%d", (int) $locked_ledger->id));
            if (!aimee_gocardless_row_matches_updates($check, $superseded_update)) {
                return new WP_Error('gc_ledger_write_unverified', 'The superseded payment state could not be verified.');
            }
            return (int) $locked_ledger->user_id;
        }
        $wpdb->query('ROLLBACK');
        return new WP_Error('gc_payment_authorization_mismatch', 'The payment is not linked to the current bank authorisation.');
    }

    $ledger_update = [
        'status' => $status,
        'updated_at' => current_time('mysql', true),
    ];
    $profile_update = [];
    $is_current_payment = hash_equals((string) ($profile->gocardless_payment_id ?? ''), $payment_id)
        || empty($profile->gocardless_payment_id);
    if ($is_current_payment) {
        $profile_update['gocardless_payment_id'] = $payment_id;
        $profile_update['gocardless_payment_status'] = $status;
    }

    $mandate_scheme = sanitize_key((string) ($profile->gocardless_mandate_scheme ?? '')) ?: aimee_gocardless_mandate_scheme();
    if (aimee_gocardless_payment_grants_access($status, $mandate_scheme)) {
        $now = time();
        $existing_end = strtotime((string) ($profile->subscription_current_period_end ?? '') . ' UTC') ?: 0;
        $period_start = max($now, $existing_end);
        $period_end = aimee_gocardless_plan_period((string) $locked_ledger->plan, $period_start);
        $applied_at = current_time('mysql', true);
        $ledger_update += [
            'applied_at' => $applied_at,
            'period_start' => gmdate('Y-m-d H:i:s', $period_start),
            'period_end' => gmdate('Y-m-d H:i:s', $period_end),
        ];
        $profile_update += [
            'subscription_status'                    => 'active',
            'subscription_current_period_start'      => gmdate('Y-m-d H:i:s', $period_start),
            'subscription_current_period_end'        => gmdate('Y-m-d H:i:s', $period_end),
            'membership_started_at'                  => !empty($profile->membership_started_at) ? $profile->membership_started_at : $applied_at,
            'billing_migration_status'               => 'complete',
            'billing_migration_completed_at'         => $applied_at,
            'billing_account_generation'             => aimee_gocardless_generation(),
            'billing_provider'                       => 'gocardless',
            'gocardless_last_confirmed_payment_id'   => $payment_id,
            'gocardless_last_payment_at'             => $applied_at,
            'gocardless_retry_after'                 => null,
            'gocardless_renewal_attempt'              => 0,
            // A cVRP renewal is created only once the next authorised period begins.
            'gocardless_next_payment_at'             => empty($profile->subscription_cancel_at_period_end)
                ? gmdate('Y-m-d H:i:s', $period_end)
                : null,
        ];
    } elseif (aimee_gocardless_payment_status_is_terminal_failure($status) && $is_current_payment) {
        $profile_update['gocardless_last_failure_at'] = current_time('mysql', true);
        if ($status === 'charged_back') {
            $profile_update['subscription_cancel_at_period_end'] = 1;
            $profile_update['gocardless_next_payment_at'] = null;
            $profile_update['gocardless_retry_after'] = null;
        } else {
            $retry_at = gmdate('Y-m-d H:i:s', time() + aimee_gocardless_retry_delay((int) $locked_ledger->attempt));
            $profile_update['gocardless_renewal_attempt'] = (int) $locked_ledger->attempt;
            $profile_update['gocardless_retry_after'] = $retry_at;
            $profile_update['gocardless_next_payment_at'] = $retry_at;
        }
    }

    $ledger_result = $wpdb->update($ledger_table, $ledger_update, ['id' => (int) $locked_ledger->id]);
    if ($ledger_result === false) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('gc_ledger_write_failed', 'The payment ledger could not be updated.');
    }
    if ($profile_update) {
        $profile_result = $wpdb->update($profile_table, $profile_update, ['user_id' => (int) $locked_ledger->user_id]);
        if ($profile_result === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('gc_profile_write_failed', 'The membership state could not be updated.');
        }
    }
    if ($wpdb->query('COMMIT') === false) return new WP_Error('gc_commit_failed', 'The payment state could not be committed.');

    $verified_ledger = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$ledger_table} WHERE id=%d", (int) $locked_ledger->id));
    $verified_profile = $profile_update
        ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$profile_table} WHERE user_id=%d", (int) $locked_ledger->user_id))
        : null;
    if (!aimee_gocardless_row_matches_updates($verified_ledger, $ledger_update)
        || ($profile_update && !aimee_gocardless_row_matches_updates($verified_profile, $profile_update))) {
        return new WP_Error('gc_ledger_write_unverified', 'The payment ledger update could not be verified.');
    }
    if (aimee_gocardless_payment_grants_access($status, $mandate_scheme) && empty($verified_ledger->applied_at)) {
        return new WP_Error('gc_payment_not_applied', 'The confirmed payment was not durably applied.');
    }
    return (int) $locked_ledger->user_id;
}

function aimee_gocardless_mandate_requires_processing($mandate_id) {
    $owner = aimee_gocardless_find_user_for_mandate($mandate_id);
    if (is_wp_error($owner) || $owner > 0) return $owner ?: true;
    global $wpdb;
    $table = aimee_gocardless_payments_table();
    $wpdb->last_error = '';
    $open = $wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM {$table}
         WHERE mandate_id=%s AND applied_at IS NULL
           AND status NOT IN ('failed','cancelled','canceled','charged_back','customer_approval_denied','expired','creation_failed')
         LIMIT 1",
        sanitize_text_field((string) $mandate_id)
    ));
    if (!empty($wpdb->last_error)) return new WP_Error('gc_ledger_read_failed', 'The mandate ledger could not be checked.');
    return (bool) $open;
}

function aimee_gocardless_apply_mandate_state($mandate) {
    if (!is_array($mandate) || empty($mandate['id'])) return new WP_Error('gc_invalid_mandate', 'The provider mandate is invalid.');
    $mandate_id = sanitize_text_field((string) $mandate['id']);
    $user_id = aimee_gocardless_find_user_for_mandate($mandate_id);
    if (is_wp_error($user_id)) return $user_id;
    if (!$user_id) return 0;

    $status = sanitize_key((string) ($mandate['status'] ?? ''));
    $scheme = sanitize_key((string) ($mandate['scheme'] ?? ''));
    $update = [];
    if ($scheme !== '') $update['gocardless_mandate_scheme'] = $scheme;
    if (in_array($status, ['cancelled', 'canceled', 'failed', 'expired', 'blocked'], true)) {
        $update['subscription_cancel_at_period_end'] = 1;
        $update['gocardless_next_payment_at'] = null;
        $update['gocardless_retry_after'] = null;
        $update['gocardless_cancelled_at'] = current_time('mysql', true);
    }
    if (!$update) return $user_id;
    $saved = aimee_gocardless_profile_update_verified($user_id, $update, ['gocardless_mandate_id' => $mandate_id]);
    return is_wp_error($saved) ? $saved : $user_id;
}

function aimee_gocardless_subscription_status(WP_REST_Request $request) {
    global $wpdb;
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_REST_Response(['status'=>'error','message'=>'Authentication required.'], 401);
    if (!aimee_gocardless_ready()) {
        return new WP_REST_Response(['status'=>'billing_configuration_error','message'=>'Bank billing is not fully ready.'], 503);
    }
    $profile_table = aimee_table('aimee_user_profiles');
    $profile = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$profile_table} WHERE user_id=%d", $user_id));
    if (!$profile) return new WP_REST_Response(['status'=>'error','message'=>'Aimee profile not found.'], 404);

    // Never trust a redirect query to select an authorisation. Always reconcile
    // the exact Billing Request currently stored under the signed-in user.
    $stored_br_id = sanitize_text_field((string) ($profile->gocardless_billing_request_id ?? ''));
    $sync_error = null;
    if ($stored_br_id !== '' && sanitize_key((string) ($profile->billing_provider ?? '')) === 'gocardless') {
        $sync = aimee_gocardless_sync_billing_request_for_user($user_id, $stored_br_id);
        if (is_wp_error($sync)) $sync_error = $sync;
    }
    $pending_codes = ['gc_billing_request_not_ready', 'gc_mandate_not_ready', 'gc_mandate_not_active', 'gc_payment_in_progress'];
    if (is_wp_error($sync_error) && !in_array($sync_error->get_error_code(), $pending_codes, true)) {
        return new WP_REST_Response(['status'=>'billing_sync_error','message'=>$sync_error->get_error_message()], 503);
    }

    $profile = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$profile_table} WHERE user_id=%d", $user_id));
    if ($profile && !empty($profile->gocardless_payment_id)) {
        $payment = aimee_gocardless_retrieve_payment($profile->gocardless_payment_id);
        if (is_wp_error($payment)) return new WP_REST_Response(['status'=>'billing_sync_error','message'=>$payment->get_error_message()], 503);
        $applied = aimee_gocardless_apply_payment($payment);
        if (is_wp_error($applied)) return new WP_REST_Response(['status'=>'billing_sync_error','message'=>$applied->get_error_message()], 503);
    }

    $verified = false;
    $profile = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$profile_table} WHERE user_id=%d", $user_id));
    if ($profile && !empty($profile->gocardless_billing_request_id) && !empty($profile->gocardless_mandate_id)) {
        $verified = (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM ' . aimee_gocardless_payments_table() . ' WHERE user_id=%d AND billing_request_id=%s AND mandate_id=%s AND applied_at IS NOT NULL LIMIT 1',
            $user_id,
            (string) $profile->gocardless_billing_request_id,
            (string) $profile->gocardless_mandate_id
        ));
    }
    return rest_ensure_response([
        'status'=>'success',
        'verified'=>$verified,
        'pending'=>is_wp_error($sync_error),
        'subscription'=>aimee_get_subscription_snapshot($user_id),
        'provider'=>'gocardless',
    ]);
}

function aimee_gocardless_cancel(WP_REST_Request $request) {
    global $wpdb;
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_REST_Response(['status'=>'error','message'=>'Authentication required.'], 401);
    if (!aimee_gocardless_ready()) {
        return new WP_REST_Response(['status'=>'billing_configuration_error','message'=>'Bank billing is not fully ready.'], 503);
    }
    if (!function_exists('aimee_acquire_subscription_checkout_lock')
        || !function_exists('aimee_release_subscription_checkout_lock')) {
        return new WP_REST_Response(['status'=>'billing_configuration_error','message'=>'Secure billing locking is unavailable.'], 503);
    }
    $cancel_lock_token = aimee_acquire_subscription_checkout_lock($user_id);
    if ($cancel_lock_token === '') {
        return new WP_REST_Response([
            'status'=>'billing_operation_in_progress',
            'message'=>'Another secure billing operation is in progress. Please retry cancellation.',
        ], 409);
    }

    try {
        $profile = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . aimee_table('aimee_user_profiles') . ' WHERE user_id=%d',
            $user_id
        ));
        if (!$profile || empty($profile->gocardless_mandate_id)) {
            return new WP_REST_Response(['status'=>'error','message'=>'No recurring bank mandate was found.'], 404);
        }
        if (!hash_equals($cancel_lock_token, (string) ($profile->billing_checkout_lock_token ?? ''))
            || !empty($profile->account_deletion_started_at)) {
            return new WP_REST_Response([
                'status'=>'billing_state_error',
                'message'=>'The bank cancellation lock or account state could not be verified.',
            ], 503);
        }
        $mandate_id = sanitize_text_field((string) $profile->gocardless_mandate_id);

        // Establish the durable no-renew state before any provider round trip.
        // A renewal worker must observe this flag if this request later fails.
        $stopped = aimee_gocardless_profile_update_verified($user_id, [
            'subscription_cancel_at_period_end' => 1,
            'gocardless_next_payment_at' => null,
            'gocardless_retry_after' => null,
        ], [
            'billing_checkout_lock_token' => $cancel_lock_token,
            'gocardless_mandate_id' => $mandate_id,
            'account_deletion_started_at' => null,
        ]);
        if (is_wp_error($stopped)) {
            return new WP_REST_Response(['status'=>'billing_state_error','message'=>$stopped->get_error_message()], 503);
        }

        $payments_retired = aimee_gocardless_retire_user_ledger_payments($user_id);
        if (is_wp_error($payments_retired)) {
            return new WP_REST_Response([
                'status'=>'billing_reconciliation_required',
                'message'=>'An open bank payment could not be verified as terminal. Renewal remains disabled.',
            ], 502);
        }
        $mandate_retired = aimee_gocardless_cancel_mandate_id($mandate_id);
        if (is_wp_error($mandate_retired)) {
            return new WP_REST_Response(['status'=>'error','message'=>$mandate_retired->get_error_message()], 502);
        }
        $saved = aimee_gocardless_profile_update_verified($user_id, [
            'gocardless_cancelled_at' => current_time('mysql', true),
            'gocardless_next_payment_at' => null,
            'gocardless_retry_after' => null,
        ], [
            'billing_checkout_lock_token' => $cancel_lock_token,
            'gocardless_mandate_id' => $mandate_id,
            'subscription_cancel_at_period_end' => 1,
            'account_deletion_started_at' => null,
        ]);
        if (is_wp_error($saved)) {
            return new WP_REST_Response(['status'=>'billing_state_error','message'=>$saved->get_error_message()], 503);
        }
        return rest_ensure_response(['status'=>'success','subscription'=>aimee_get_subscription_snapshot($user_id, $saved)]);
    } finally {
        $released = aimee_gocardless_release_checkout_lock_verified($user_id, $cancel_lock_token);
        if (is_wp_error($released)) {
            return new WP_REST_Response(['status'=>'billing_state_error','message'=>$released->get_error_message()], 503);
        }
    }
}

function aimee_gocardless_portal(WP_REST_Request $request) {
    if (!aimee_gocardless_ready()) {
        return new WP_REST_Response(['status'=>'billing_configuration_error','message'=>'Bank billing is not fully ready.'], 503);
    }
    $chat_url = function_exists('aimee_global_route') ? aimee_global_route('chat', 'uk') : home_url('/chat/');
    return rest_ensure_response([
        'status'=>'managed_in_aimee',
        'message'=>'Your bank membership is managed directly in Aimee. You can review it or cancel future renewals from your Aimee account.',
        'portal_url'=>esc_url_raw(add_query_arg(['membership'=>'manage','provider'=>'gocardless'], $chat_url)),
        'provider'=>'gocardless',
    ]);
}

function aimee_gocardless_verify_webhook_signature($payload, $signature) {
    $secret = defined('GOCARDLESS_WEBHOOK_SECRET') ? trim((string) GOCARDLESS_WEBHOOK_SECRET) : '';
    if ($secret === '' || trim((string) $signature) === '') return false;
    return hash_equals(hash_hmac('sha256', (string) $payload, $secret), trim((string) $signature));
}

function aimee_gocardless_event_processed($event_id) {
    global $wpdb;
    $wpdb->last_error = '';
    $found = $wpdb->get_var($wpdb->prepare(
        'SELECT 1 FROM ' . aimee_table('aimee_gocardless_events') . ' WHERE event_id=%s LIMIT 1',
        sanitize_text_field((string) $event_id)
    ));
    if (!empty($wpdb->last_error)) return new WP_Error('gc_event_ledger_read_failed', 'The webhook event ledger could not be read.');
    return (bool) $found;
}

function aimee_gocardless_record_event($event) {
    global $wpdb;
    $event_id = sanitize_text_field((string) ($event['id'] ?? ''));
    $inserted = $wpdb->query($wpdb->prepare(
        'INSERT IGNORE INTO ' . aimee_table('aimee_gocardless_events') . ' (event_id, resource_type, action, processed_at) VALUES (%s,%s,%s,%s)',
        $event_id,
        sanitize_key((string) ($event['resource_type'] ?? '')),
        sanitize_key((string) ($event['action'] ?? '')),
        current_time('mysql', true)
    ));
    if ($inserted === false) return new WP_Error('gc_event_ledger_write_failed', 'The webhook event could not be recorded.');
    if ($inserted === 0) {
        $processed = aimee_gocardless_event_processed($event_id);
        if (is_wp_error($processed) || !$processed) return new WP_Error('gc_event_ledger_write_unverified', 'The webhook event record could not be verified.');
    }
    return true;
}

function aimee_gocardless_provider_payment_is_known($payment_id) {
    global $wpdb;
    $wpdb->last_error = '';
    $known = $wpdb->get_var($wpdb->prepare(
        'SELECT 1 FROM ' . aimee_gocardless_payments_table() . ' WHERE provider_payment_id=%s LIMIT 1',
        sanitize_text_field((string) $payment_id)
    ));
    if (!empty($wpdb->last_error)) return new WP_Error('gc_ledger_read_failed', 'The payment event ledger could not be checked.');
    return (bool) $known;
}

function aimee_gocardless_has_unbound_payment_claims() {
    global $wpdb;
    $wpdb->last_error = '';
    $known = $wpdb->get_var(
        'SELECT 1 FROM ' . aimee_gocardless_payments_table()
        . " WHERE provider_payment_id IS NULL AND status IN ('creating','request_unknown') LIMIT 1"
    );
    if (!empty($wpdb->last_error)) return new WP_Error('gc_ledger_read_failed', 'The unbound payment ledger could not be checked.');
    return (bool) $known;
}

function aimee_gocardless_webhook(WP_REST_Request $request) {
    $payload = $request->get_body();
    $signature = $request->get_header('Webhook-Signature');
    if (!aimee_gocardless_verify_webhook_signature($payload, $signature)) {
        return new WP_REST_Response(['status'=>'invalid_signature'], 498);
    }
    if (!aimee_gocardless_ready()) return new WP_REST_Response(['status'=>'billing_not_ready'], 503);
    $decoded = json_decode($payload, true);
    if (!is_array($decoded)) return new WP_REST_Response(['status'=>'invalid_payload'], 400);
    $events = is_array($decoded['events'] ?? null) ? $decoded['events'] : [];

    foreach ($events as $event) {
        if (!is_array($event) || empty($event['id'])) continue;
        $processed = aimee_gocardless_event_processed($event['id']);
        if (is_wp_error($processed)) return new WP_REST_Response(['status'=>'retry','event_id'=>sanitize_text_field((string) $event['id'])], 503);
        if ($processed) continue;

        $type = sanitize_key((string) ($event['resource_type'] ?? ''));
        $links = is_array($event['links'] ?? null) ? $event['links'] : [];
        $result = true;

        if ($type === 'billing_requests' && !empty($links['billing_request'])) {
            $uid = aimee_gocardless_find_user_for_billing_request($links['billing_request']);
            if (is_wp_error($uid)) $result = $uid;
            elseif ($uid) $result = aimee_gocardless_sync_billing_request_for_user($uid, $links['billing_request']);
            // A request absent from current Aimee state is unrelated creditor
            // traffic or a deliberately superseded checkout, so it is recorded.
        } elseif ($type === 'mandates' && !empty($links['mandate'])) {
            $linked = aimee_gocardless_mandate_requires_processing($links['mandate']);
            if (is_wp_error($linked)) {
                $result = $linked;
            } elseif (!$linked) {
                $result = true;
            } else {
                $mandate = aimee_gocardless_retrieve_mandate($links['mandate']);
                $result = is_wp_error($mandate) ? $mandate : aimee_gocardless_apply_mandate_state($mandate);
            }
            if (!is_wp_error($result) && $result && !empty($linked)) {
                $status = sanitize_key((string) ($mandate['status'] ?? ''));
                if (in_array($status, ['active', 'submitted', 'pending_submission'], true)) {
                    global $wpdb;
                    $profile = $wpdb->get_row($wpdb->prepare(
                        'SELECT * FROM ' . aimee_table('aimee_user_profiles') . ' WHERE user_id=%d',
                        (int) $result
                    ));
                    if ($profile && sanitize_key((string) ($profile->billing_migration_status ?? '')) !== 'complete'
                        && empty($profile->subscription_cancel_at_period_end)) {
                        $result = aimee_gocardless_create_payment_for_user((int) $result, 'initial');
                    }
                }
            }
        } elseif ($type === 'payments' && !empty($links['payment'])) {
            if (!empty($links['mandate'])) {
                $linked = aimee_gocardless_mandate_requires_processing($links['mandate']);
            } else {
                $linked = aimee_gocardless_provider_payment_is_known($links['payment']);
                if (!is_wp_error($linked) && !$linked) $linked = aimee_gocardless_has_unbound_payment_claims();
            }
            if (is_wp_error($linked)) {
                $result = $linked;
            } elseif (!$linked) {
                $result = true;
            } else {
                $payment = aimee_gocardless_retrieve_payment($links['payment']);
                $result = is_wp_error($payment) ? $payment : aimee_gocardless_apply_payment($payment);
            }
        }

        if (is_wp_error($result)) {
            return new WP_REST_Response(['status'=>'retry','event_id'=>sanitize_text_field((string) $event['id'])], 503);
        }
        $recorded = aimee_gocardless_record_event($event);
        if (is_wp_error($recorded)) {
            return new WP_REST_Response(['status'=>'retry','event_id'=>sanitize_text_field((string) $event['id'])], 503);
        }
    }
    return new WP_REST_Response(null, 204);
}

function aimee_gocardless_renewal_worker() {
    if (!aimee_gocardless_ready()) return ['processed'=>0, 'errors'=>['billing_not_ready']];
    global $wpdb;
    $profile_table = aimee_table('aimee_user_profiles');
    $last_user_id = 0;
    $processed = 0;
    $errors = [];

    do {
        $now = current_time('mysql', true);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id FROM {$profile_table}
             WHERE user_id>%d
               AND billing_provider='gocardless'
               AND billing_account_generation=%s
               AND subscription_cancel_at_period_end=0
               AND gocardless_mandate_id IS NOT NULL AND gocardless_mandate_id<>''
               AND gocardless_billing_request_id IS NOT NULL AND gocardless_billing_request_id<>''
               AND ((gocardless_next_payment_at IS NOT NULL AND gocardless_next_payment_at<=%s)
                    OR (gocardless_retry_after IS NOT NULL AND gocardless_retry_after<=%s))
             ORDER BY user_id ASC
             LIMIT 100",
            $last_user_id,
            aimee_gocardless_generation(),
            $now,
            $now
        ));
        if (!is_array($rows) || !$rows) break;
        foreach ($rows as $row) {
            $last_user_id = max($last_user_id, (int) $row->user_id);
            $result = aimee_gocardless_create_payment_for_user((int) $row->user_id, 'renewal');
            $processed++;
            if (is_wp_error($result)) {
                $errors[(int) $row->user_id] = $result->get_error_code();
                continue;
            }
            if (!is_array($result) || empty($result['id'])) continue;

            $payment = !empty($result['_aimee_existing'])
                ? aimee_gocardless_retrieve_payment($result['id'])
                : $result;
            if (is_wp_error($payment)) {
                $errors[(int) $row->user_id] = $payment->get_error_code();
                continue;
            }
            $applied = aimee_gocardless_apply_payment($payment);
            if (is_wp_error($applied)) {
                $errors[(int) $row->user_id] = $applied->get_error_code();
                continue;
            }
            $payment_status = sanitize_key((string) ($payment['status'] ?? ''));
            if (!in_array($payment_status, ['confirmed', 'paid_out'], true)
                && !aimee_gocardless_payment_status_is_terminal_failure($payment_status)) {
                $rescheduled = aimee_gocardless_profile_update_verified((int) $row->user_id, [
                    'gocardless_next_payment_at' => gmdate('Y-m-d H:i:s', time() + HOUR_IN_SECONDS),
                ], ['gocardless_payment_id' => sanitize_text_field((string) $payment['id'])]);
                if (is_wp_error($rescheduled)) $errors[(int) $row->user_id] = $rescheduled->get_error_code();
            }
        }
    } while (count($rows) === 100);

    return ['processed'=>$processed, 'errors'=>$errors];
}

add_action('aimee_gocardless_renewal_hook', 'aimee_gocardless_renewal_worker');
add_filter('cron_schedules', function($schedules) {
    if (!isset($schedules['aimee_hourly'])) $schedules['aimee_hourly'] = ['interval'=>HOUR_IN_SECONDS,'display'=>'Aimee hourly'];
    return $schedules;
});
add_action('init', function() {
    if (!wp_next_scheduled('aimee_gocardless_renewal_hook')) {
        wp_schedule_event(time() + 300, 'aimee_hourly', 'aimee_gocardless_renewal_hook');
    }
});
