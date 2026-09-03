<?php
/** Executable provider-binding and Billing Request cancellation regression. */

define('ABSPATH', __DIR__);
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);
define('YEAR_IN_SECONDS', 31536000);
define('GOCARDLESS_ACCESS_TOKEN', 'sandbox-token-that-must-never-be-a-cache-key');
define('GOCARDLESS_WEBHOOK_SECRET', 'webhook-secret');
define('GOCARDLESS_CREDITOR_ID', 'CR_EXPECTED');
define('GOCARDLESS_ENVIRONMENT', 'sandbox');
// This suite exercises the 1.8.0 Faster Payments VRP intent shape explicitly.
define('GOCARDLESS_MANDATE_SCHEME', 'faster_payments');

class WP_Error {
    private $code;
    private $message;
    private $data;

    public function __construct($code = '', $message = '', $data = null) {
        $this->code = (string) $code;
        $this->message = (string) $message;
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
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function wp_json_encode($value) { return json_encode($value); }
function add_action() { return true; }
function add_filter() { return true; }
function aimee_table($name) { return (string) $name; }
function aimee_global_core_schema_health() { return true; }
function aimee_global_schema_table_contract_ready() { return true; }

$GLOBALS['gc_transients'] = [];
$GLOBALS['gc_http_queue'] = [];
$GLOBALS['gc_http_calls'] = [];

function get_transient($key) {
    return $GLOBALS['gc_transients'][$key]['value'] ?? false;
}
function set_transient($key, $value, $ttl) {
    $GLOBALS['gc_transients'][$key] = ['value' => $value, 'ttl' => $ttl];
    return true;
}
function delete_transient($key) {
    unset($GLOBALS['gc_transients'][$key]);
    return true;
}
function gc_queue_response($code, array $body) {
    $GLOBALS['gc_http_queue'][] = ['code' => (int) $code, 'body' => json_encode($body)];
}
function gc_queue_error($code) {
    $GLOBALS['gc_http_queue'][] = new WP_Error((string) $code, 'Mock transport failure.');
}
function wp_remote_request($url, $args) {
    $GLOBALS['gc_http_calls'][] = ['url' => (string) $url, 'method' => (string) ($args['method'] ?? '')];
    if (!$GLOBALS['gc_http_queue']) return new WP_Error('unexpected_http', 'No mock response was queued.');
    return array_shift($GLOBALS['gc_http_queue']);
}
function wp_remote_retrieve_response_code($response) { return (int) ($response['code'] ?? 0); }
function wp_remote_retrieve_body($response) { return (string) ($response['body'] ?? ''); }

$source = is_file('/aimee/includes/gocardless.php')
    ? '/aimee/includes/gocardless.php'
    : dirname(__DIR__) . '/includes/gocardless.php';
require $source;

$passes = 0;
$failures = 0;
function gc_check($condition, $label) {
    global $passes, $failures;
    if ($condition) {
        $passes++;
        echo "PASS {$label}\n";
    } else {
        $failures++;
        echo "FAIL {$label}\n";
    }
}

gc_queue_response(200, [
    'creditors' => [['id' => 'CR_OTHER']],
    'meta' => ['cursors' => ['after' => 'page-two']],
]);
gc_queue_response(200, [
    'creditors' => [['id' => 'CR_EXPECTED']],
    'meta' => ['cursors' => ['after' => null]],
]);
$verified = aimee_gocardless_creditor_identity_ready(true);
gc_check($verified === true, 'expected creditor is found on a later authoritative page');
gc_check(
    count($GLOBALS['gc_http_calls']) === 2
    && strpos($GLOBALS['gc_http_calls'][0]['url'], '/creditors?limit=100') !== false
    && strpos($GLOBALS['gc_http_calls'][1]['url'], 'after=page-two') !== false,
    'creditor pagination follows the provider cursor'
);
$cache_keys = array_keys($GLOBALS['gc_transients']);
gc_check(
    count($cache_keys) === 1
    && strpos($cache_keys[0], GOCARDLESS_ACCESS_TOKEN) === false
    && reset($GLOBALS['gc_transients'])['ttl'] === 5 * MINUTE_IN_SECONDS,
    'success cache is short and does not expose the access token'
);
$calls_before_cache = count($GLOBALS['gc_http_calls']);
gc_check(
    aimee_gocardless_creditor_identity_ready() === true
    && count($GLOBALS['gc_http_calls']) === $calls_before_cache,
    'verified creditor uses its binding-specific success cache'
);

gc_queue_response(200, ['billing_requests' => ['id' => 'BR_PENDING', 'status' => 'pending']]);
gc_queue_response(200, ['billing_requests' => ['id' => 'BR_PENDING', 'status' => 'cancelled']]);
gc_check(
    aimee_gocardless_cancel_billing_request_id('BR_PENDING') === true,
    'matching cancelled action response proves Billing Request cancellation'
);

gc_queue_response(200, ['billing_requests' => [
    'id' => 'BR_FULFILLED',
    'status' => 'fulfilled',
    'links' => ['mandate_request_mandate' => 'MD_LINKED'],
]]);
$fulfilled = aimee_gocardless_cancel_billing_request_id('BR_FULFILLED');
$fulfilled_data = is_wp_error($fulfilled) ? $fulfilled->get_error_data() : null;
gc_check(
    is_wp_error($fulfilled)
    && $fulfilled->get_error_code() === 'gc_billing_request_fulfilled'
    && is_array($fulfilled_data)
    && ($fulfilled_data['mandate_id'] ?? '') === 'MD_LINKED',
    'fulfilled Billing Request surfaces its mandate and is never reported cancelled'
);

gc_queue_response(404, ['error' => ['message' => 'Not found']]);
$missing = aimee_gocardless_cancel_billing_request_id('BR_UNKNOWN');
gc_check(
    is_wp_error($missing) && $missing->get_error_code() === 'gc_billing_request_state_unverified',
    'unexplained Billing Request 404 fails closed'
);

gc_queue_response(200, ['billing_requests' => ['id' => 'BR_ACTION_RACE', 'status' => 'pending']]);
gc_queue_error('response_lost');
gc_queue_response(200, ['billing_requests' => [
    'id' => 'BR_ACTION_RACE',
    'status' => 'fulfilled',
    'links' => ['mandate_request_mandate' => 'MD_ACTION_RACE'],
]]);
$action_race = aimee_gocardless_cancel_billing_request_id('BR_ACTION_RACE');
$action_race_data = is_wp_error($action_race) ? $action_race->get_error_data() : null;
gc_check(
    is_wp_error($action_race)
    && $action_race->get_error_code() === 'gc_billing_request_fulfilled'
    && is_array($action_race_data)
    && ($action_race_data['mandate_id'] ?? '') === 'MD_ACTION_RACE',
    'lost cancel response is post-read and a fulfilment race surfaces its mandate'
);

gc_queue_response(200, ['billing_requests' => ['id' => 'BR_RACE', 'status' => 'pending']]);
gc_queue_response(200, ['billing_requests' => ['id' => 'BR_RACE', 'status' => 'pending']]);
gc_queue_response(200, ['billing_requests' => ['id' => 'BR_RACE', 'status' => 'pending']]);
gc_queue_response(200, ['billing_requests' => ['id' => 'BR_RACE', 'status' => 'ready_to_fulfil']]);
$ambiguous = aimee_gocardless_cancel_billing_request_id('BR_RACE');
gc_check(
    is_wp_error($ambiguous)
    && $ambiguous->get_error_code() === 'gc_billing_request_cancellation_unverified',
    'nonterminal action and bounded post-reads never prove cancellation'
);

$calls_before_intent_list = count($GLOBALS['gc_http_calls']);
gc_queue_response(200, [
    'billing_requests' => [[
        'id' => 'BR_INTENT',
        'metadata' => ['aimee_checkout_intent' => 'intent-exact'],
    ]],
    'meta' => ['cursors' => ['after' => 'intent-page-two']],
]);
gc_queue_response(200, [
    'billing_requests' => [[
        'id' => 'BR_OTHER',
        'metadata' => ['aimee_checkout_intent' => 'other-intent'],
    ]],
    'meta' => ['cursors' => ['after' => null]],
]);
$intent_matches = aimee_gocardless_list_billing_requests_for_intent('intent-exact');
gc_check(
    is_array($intent_matches)
    && count($intent_matches) === 1
    && ($intent_matches[0]['id'] ?? '') === 'BR_INTENT'
    && strpos($GLOBALS['gc_http_calls'][$calls_before_intent_list]['url'], 'limit=500') !== false
    && strpos($GLOBALS['gc_http_calls'][$calls_before_intent_list + 1]['url'], 'after=intent-page-two') !== false,
    'checkout intent discovery exhausts cursor pagination before proving one exact match'
);

gc_queue_response(200, [
    'billing_requests' => [
        ['id' => 'BR_DUPLICATE'],
        ['id' => 'BR_DUPLICATE'],
    ],
    'meta' => ['cursors' => ['after' => null]],
]);
$duplicate_collection = aimee_gocardless_list_collection('billing_requests', 'billing_requests');
gc_check(
    is_wp_error($duplicate_collection)
    && $duplicate_collection->get_error_code() === 'gc_collection_ambiguous',
    'authoritative collection rejects duplicate provider identities'
);

gc_queue_response(200, [
    'payments' => [[
        'id' => 'PM_WRONG_MANDATE',
        'links' => ['mandate' => 'MD_OTHER'],
    ]],
    'meta' => ['cursors' => ['after' => null]],
]);
$wrong_mandate_collection = aimee_gocardless_list_payments_for_mandate('MD_EXPECTED');
gc_check(
    is_wp_error($wrong_mandate_collection)
    && $wrong_mandate_collection->get_error_code() === 'gc_payment_mandate_mismatch',
    'mandate-filtered payment discovery verifies every returned mandate link'
);

$intent_payload = aimee_gocardless_build_checkout_intent_payload(
    42,
    'intent-persisted',
    'monthly',
    1299,
    'GBP',
    ['amount_pence' => 1299, 'label' => 'Monthly'],
    '2026-08-20'
);
gc_check(
    aimee_gocardless_checkout_intent_payload_matches(
        $intent_payload,
        42,
        'intent-persisted',
        'monthly',
        1299,
        'GBP'
    )
    && ($intent_payload['billing_requests']['mandate_request']['constraints']['start_date'] ?? '') === '2026-08-20'
    && count($intent_payload['billing_requests']['metadata'] ?? []) === 3,
    'persisted Billing Request body binds intent, owner, terms and exact start date within metadata limits'
);

gc_queue_response(200, [
    'creditors' => [['id' => 'CR_WRONG']],
    'meta' => ['cursors' => ['after' => null]],
]);
gc_check(
    aimee_gocardless_creditor_identity_ready(true) === false
    && !$GLOBALS['gc_transients'],
    'wrong creditor fails closed and receives no success cache'
);

echo "GoCardless creditor/cancellation result: {$passes} passed, {$failures} failed.\n";
exit($failures ? 1 : 0);
