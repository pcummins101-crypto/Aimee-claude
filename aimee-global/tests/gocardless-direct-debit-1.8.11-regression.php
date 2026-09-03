<?php
/** Executable 1.8.11 Bacs Direct Debit checkout regression. Default scheme, no VRP constant. */

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
    if ($condition) { $passes++; echo "PASS {$label}\n"; } else { $failures++; echo "FAIL {$label}\n"; }
}

gc_check(aimee_gocardless_mandate_scheme() === 'bacs', 'default mandate scheme is Bacs Direct Debit');

$payload = aimee_gocardless_build_checkout_intent_payload(42, 'intent-dd', 'monthly', 1999, 'GBP', ['amount_pence' => 1999, 'label' => 'Aimee Monthly']);
$br = $payload['billing_requests'];
gc_check(($br['mandate_request']['scheme'] ?? '') === 'bacs', 'Direct Debit payload requests the bacs scheme');
gc_check(!isset($br['mandate_request']['constraints']) && !isset($br['fallback_enabled']) && !isset($br['payment_context_code']) && !isset($br['payment_purpose_code']), 'Direct Debit payload carries no VRP constraints, fallback or VRP purpose codes');
gc_check(($br['purpose_code'] ?? '') === 'retail' && ($br['mandate_request']['currency'] ?? '') === 'GBP' && ($br['mandate_request']['description'] ?? '') === 'Aimee Monthly membership', 'Direct Debit payload keeps purpose code, currency and description');
gc_check(count($br['metadata'] ?? []) === 3 && ($br['metadata']['aimee_checkout_intent'] ?? '') === 'intent-dd' && ($br['metadata']['aimee_terms'] ?? '') === '42|monthly|1999|GBP', 'immutable metadata is unchanged for Direct Debit');

gc_check(aimee_gocardless_checkout_intent_payload_matches($payload, 42, 'intent-dd', 'monthly', 1999, 'GBP') === true, 'Direct Debit intent payload matches its durable intent');
gc_check(aimee_gocardless_checkout_intent_payload_matches($payload, 42, 'intent-dd', 'monthly', 1899, 'GBP') === false, 'intent match still rejects a changed amount');
$vrp = $payload; $vrp['billing_requests']['mandate_request']['scheme'] = 'faster_payments';
gc_check(aimee_gocardless_checkout_intent_payload_matches($vrp, 42, 'intent-dd', 'monthly', 1999, 'GBP') === false, 'a stored VRP payload does not match under the Direct Debit scheme');

$profile = (object) ['user_id' => 42, 'gocardless_authorized_plan' => 'monthly', 'gocardless_authorized_amount_minor' => 1999, 'gocardless_authorized_currency' => 'GBP'];
$provider_br = ['id' => 'BR_DD', 'metadata' => $br['metadata'], 'mandate_request' => ['currency' => 'GBP', 'scheme' => 'bacs']];
gc_check(aimee_gocardless_billing_request_terms_match($provider_br, $profile) === true, 'provider Direct Debit Billing Request matches terms without constraints');
$provider_vrp = ['id' => 'BR_VRP', 'metadata' => $br['metadata'], 'mandate_request' => ['currency' => 'GBP', 'scheme' => 'faster_payments']];
gc_check(aimee_gocardless_billing_request_terms_match($provider_vrp, $profile) === false, 'a VRP Billing Request without constraints still fails the terms check');
$wrong_currency = $provider_br; $wrong_currency['mandate_request']['currency'] = 'USD';
gc_check(aimee_gocardless_billing_request_terms_match($wrong_currency, $profile) === false, 'Direct Debit terms check still verifies currency');

// Access-granting statuses per scheme.
gc_check(aimee_gocardless_payment_grants_access('pending_submission', 'bacs') && aimee_gocardless_payment_grants_access('submitted', 'bacs') && aimee_gocardless_payment_grants_access('confirmed', 'bacs') && aimee_gocardless_payment_grants_access('paid_out', 'bacs'), 'Direct Debit grants access from creation onward');
gc_check(!aimee_gocardless_payment_grants_access('pending_submission', 'faster_payments') && !aimee_gocardless_payment_grants_access('submitted', 'faster_payments') && aimee_gocardless_payment_grants_access('confirmed', 'faster_payments'), 'Faster Payments grants access only on confirmation');
gc_check(!aimee_gocardless_payment_grants_access('failed', 'bacs') && !aimee_gocardless_payment_grants_access('cancelled', 'bacs') && !aimee_gocardless_payment_grants_access('customer_approval_denied', 'bacs'), 'terminal failures never grant access');
gc_check(aimee_gocardless_payment_status_is_provisional('pending_submission') && aimee_gocardless_payment_status_is_provisional('submitted') && !aimee_gocardless_payment_status_is_provisional('confirmed'), 'provisional statuses identified');

// A stored VRP intent with no Billing Request is abandoned; a bound one is not.
$stuck = (object) ['gocardless_billing_request_id' => '', 'billing_checkout_intent_payload' => json_encode($vrp)];
gc_check(aimee_gocardless_stored_intent_scheme_abandoned($stuck, 'bacs') === true, 'stuck VRP intent without a Billing Request is abandoned under Direct Debit');
$bound = (object) ['gocardless_billing_request_id' => 'BR_X', 'billing_checkout_intent_payload' => json_encode($vrp)];
gc_check(aimee_gocardless_stored_intent_scheme_abandoned($bound, 'bacs') === false, 'a bound Billing Request is never abandoned');
$same = (object) ['gocardless_billing_request_id' => '', 'billing_checkout_intent_payload' => json_encode($payload)];
gc_check(aimee_gocardless_stored_intent_scheme_abandoned($same, 'bacs') === false, 'a Direct Debit intent is reused, not abandoned');
gc_check(aimee_gocardless_stored_intent_scheme_abandoned((object) ['gocardless_billing_request_id' => '', 'billing_checkout_intent_payload' => 'not json'], 'bacs') === false, 'unreadable stored payload is left to the existing reconciliation rules');

echo "GoCardless Direct Debit 1.8.11 result: {$passes} passed, {$failures} failed.\n";
exit($failures ? 1 : 0);
