<?php
/**
 * Execute the exact production registration handler against a deterministic
 * WordPress-like runtime. This guards behaviour that static source checks
 * cannot prove: the final credential, rollback, optional-photo degradation,
 * bounded deferred work, and privacy-safe operational diagnostics.
 *
 * Run with:
 *   node tests/run-php-wasm.mjs tests/registration-runtime-1.8.8-regression.php
 */

define('ABSPATH', '/aimee/');
define('MINUTE_IN_SECONDS', 60);
ini_set('log_errors', '1');
ini_set('error_log', '/tmp/aimee-registration-runtime.log');

if (!is_dir(ABSPATH . 'wp-admin/includes')) {
    mkdir(ABSPATH . 'wp-admin/includes', 0755, true);
}
if (!is_file(ABSPATH . 'wp-admin/includes/user.php')) {
    file_put_contents(ABSPATH . 'wp-admin/includes/user.php', "<?php\n");
}

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
    public function get_error_codes() { return $this->code === '' ? [] : [$this->code]; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}

class WP_REST_Request {
    private $params;
    public function __construct(array $params = []) { $this->params = $params; }
    public function get_json_params() { return $this->params; }
}

class WP_REST_Response {
    private $data;
    private $status;
    public function __construct($data = null, $status = 200) {
        $this->data = $data;
        $this->status = intval($status);
    }
    public function get_data() { return $this->data; }
    public function get_status() { return $this->status; }
}

class Aimee_Registration_Test_Wpdb {
    public $last_error = '';
    public $last_errno = 0;
    public $profiles = [];
    public $messages = [];
    public $fail_profile_insert = false;
    public $fail_message_insert = false;

    public function prepare($query, ...$args) {
        return ['query' => (string) $query, 'args' => $args];
    }

    public function get_row($prepared) {
        $args = is_array($prepared) ? ($prepared['args'] ?? []) : [];
        $user_id = intval($args[0] ?? 0);
        return $this->profiles[$user_id] ?? null;
    }

    public function replace($table, array $data) {
        $GLOBALS['aimee_registration_trace'][] = 'profile_replace';
        throw new RuntimeException('Registration must INSERT, never REPLACE, a new profile.');
    }

    public function insert($table, array $data) {
        if ((string) $table === 'aimee_user_profiles') {
            $GLOBALS['aimee_registration_trace'][] = 'profile_insert';
            if ($this->fail_profile_insert) {
                $this->last_errno = 1364;
                $this->last_error = "Field 'first_name' doesn't have a default value";
                return false;
            }
            $user_id = intval($data['user_id'] ?? 0);
            if ($user_id < 1) return false;
            $this->profiles[$user_id] = (object) $data;
            return 1;
        }
        $GLOBALS['aimee_registration_trace'][] = 'message_insert';
        if ($this->fail_message_insert) return false;
        $this->messages[] = $data;
        return 1;
    }

    public function update($table, array $data, array $where, array $formats = [], array $where_formats = []) {
        $user_id = intval($where['user_id'] ?? 0);
        if (!isset($this->profiles[$user_id])) return 0;
        foreach ($data as $key => $value) $this->profiles[$user_id]->{$key} = $value;
        return 1;
    }
}

$GLOBALS['wpdb'] = new Aimee_Registration_Test_Wpdb();
$GLOBALS['aimee_registration_users'] = [];
$GLOBALS['aimee_registration_next_user_id'] = 100;
$GLOBALS['aimee_registration_options'] = [];
$GLOBALS['aimee_registration_trace'] = [];
$GLOBALS['aimee_registration_scenario'] = [];
$GLOBALS['aimee_registration_scheduled_events'] = [];
$GLOBALS['aimee_registration_mail_calls'] = [];
$GLOBALS['aimee_registration_sms_calls'] = [];

function is_wp_error($value) { return $value instanceof WP_Error; }
function rest_ensure_response($value) {
    return $value instanceof WP_REST_Response ? $value : new WP_REST_Response($value, 200);
}
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_textarea_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_email($value) { return strtolower(trim((string) $value)); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function sanitize_user($value, $strict = false) {
    return preg_replace($strict ? '/[^A-Za-z0-9 _\.\-@]/' : '/[^A-Za-z0-9 _\.\-@]/', '', (string) $value);
}
function esc_url_raw($value) { return (string) $value; }
function is_email($value) { return filter_var((string) $value, FILTER_VALIDATE_EMAIL) !== false; }
function absint($value) { return abs(intval($value)); }
function wp_json_encode($value, $flags = 0, $depth = 512) { return json_encode($value, $flags, $depth); }
function wp_generate_uuid4() { return '12345678-1234-4abc-8def-123456789abc'; }
function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false) {
    return substr('Temporary-Strong-Credential-9z!Q7vK2m', 0, max(1, intval($length)));
}
function wp_hash_password($password) { return 'test-hash:' . hash('sha256', (string) $password); }
function wp_check_password($password, $hash, $user_id = '') {
    if (!empty($GLOBALS['aimee_registration_scenario']['force_password_check_failure'])) return false;
    return hash_equals(wp_hash_password((string) $password), (string) $hash);
}
function current_time($type, $gmt = false) { return '2026-08-21 20:00:00'; }
function get_option($key, $default = false) {
    if (
        $key === 'admin_email'
        && !empty($GLOBALS['aimee_registration_scenario']['throw_after_message_insert'])
    ) {
        throw new RuntimeException('Simulated post-message notification preparation failure.');
    }
    return $GLOBALS['aimee_registration_options'][$key] ?? $default;
}
function update_option($key, $value, $autoload = null) {
    $GLOBALS['aimee_registration_options'][$key] = $value;
    return true;
}
function delete_option($key) { unset($GLOBALS['aimee_registration_options'][$key]); return true; }
function add_option($key, $value = '', $deprecated = '', $autoload = 'yes') {
    if (array_key_exists($key, $GLOBALS['aimee_registration_options'])) return false;
    $GLOBALS['aimee_registration_options'][$key] = $value;
    $GLOBALS['aimee_registration_trace'][] = 'option_claim:' . (string) $key;
    return true;
}
function wp_next_scheduled($hook, $args = []) {
    foreach ($GLOBALS['aimee_registration_scheduled_events'] as $event) {
        if (
            hash_equals((string) ($event['hook'] ?? ''), (string) $hook)
            && ($event['args'] ?? []) === $args
        ) {
            return intval($event['timestamp'] ?? 1);
        }
    }
    return false;
}
function wp_schedule_single_event($timestamp, $hook, $args = [], $wp_error = false) {
    $GLOBALS['aimee_registration_trace'][] = 'schedule:' . (string) $hook;
    if (!empty($GLOBALS['aimee_registration_scenario']['schedule_failure'])) {
        return $wp_error
            ? new WP_Error('schedule_failed', 'Simulated scheduling failure.')
            : false;
    }
    $GLOBALS['aimee_registration_scheduled_events'][] = [
        'timestamp' => intval($timestamp),
        'hook' => (string) $hook,
        'args' => array_values((array) $args),
    ];
    return true;
}

function aimee_rate_limit($bucket, $limit, $window_seconds) { return true; }
function aimee_global_core_schema_health($refresh = false) { return true; }
function aimee_table($name) { return $name; }
function aimee_global_market($market = null) { return $market === 'us' ? 'us' : 'uk'; }
function aimee_profile_attribution_limit_text($value, $limit) {
    return function_exists('mb_substr')
        ? mb_substr((string) $value, 0, intval($limit), 'UTF-8')
        : substr((string) $value, 0, intval($limit));
}
function aimee_security_boolean_is_true($value) {
    return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true);
}
function aimee_format_mobile($number, $market = null) {
    $digits = preg_replace('/[^0-9]/', '', (string) $number);
    if (preg_match('/^07[0-9]{9}$/', $digits)) return '44' . substr($digits, 1);
    if (preg_match('/^447[0-9]{9}$/', $digits)) return $digits;
    return false;
}
function aimee_phone_is_reserved_for_other_identity($number, $user_id = 0) {
    return !empty($GLOBALS['aimee_registration_scenario']['reserved_identifier']);
}
function aimee_mobile_login_candidates($login, $market = null) {
    $normalised = aimee_format_mobile($login, $market);
    return array_values(array_unique(array_filter([
        sanitize_user((string) $login, true),
        preg_replace('/[^0-9]/', '', (string) $login),
        $normalised,
    ])));
}
function username_exists($login) {
    if (!empty($GLOBALS['aimee_registration_scenario']['existing_identifier'])) return 77;
    foreach ($GLOBALS['aimee_registration_users'] as $user_id => $user) {
        if (hash_equals((string) $user['login'], (string) $login)) return intval($user_id);
    }
    return false;
}
function email_exists($email) { return false; }

function aimee_registration_test_create_user($login, $password, $email = '') {
    $GLOBALS['aimee_registration_trace'][] = 'user_create:' . strlen((string) $password);
    $scenario = $GLOBALS['aimee_registration_scenario'];
    if (!empty($scenario['force_user_create_error'])) {
        $error_code = (string) ($scenario['user_create_error_code'] ?? 'db_insert_error');
        return new WP_Error($error_code, 'Simulated user-table failure.');
    }
    if (
        !empty($scenario['require_strong_creation_password'])
        && preg_match('/\A[0-9]{6}\z/', (string) $password) === 1
    ) {
        return new WP_Error('password_policy_rejected', 'Creation requires a temporary strong password.');
    }
    $user_id = ++$GLOBALS['aimee_registration_next_user_id'];
    $GLOBALS['aimee_registration_users'][$user_id] = [
        'ID' => $user_id,
        'login' => (string) $login,
        'email' => (string) $email,
        'user_pass' => wp_hash_password((string) $password),
    ];
    return $user_id;
}
function wp_create_user($login, $password, $email = '') {
    return aimee_registration_test_create_user($login, $password, $email);
}
function wp_insert_user($userdata) {
    if (is_object($userdata)) $userdata = get_object_vars($userdata);
    return aimee_registration_test_create_user(
        $userdata['user_login'] ?? '',
        $userdata['user_pass'] ?? '',
        $userdata['user_email'] ?? ''
    );
}
function wp_set_password($password, $user_id) {
    $user_id = intval($user_id);
    $GLOBALS['aimee_registration_trace'][] = 'password_finalize:' . $user_id;
    if (!isset($GLOBALS['aimee_registration_users'][$user_id])) return;
    if (!empty($GLOBALS['aimee_registration_scenario']['password_set_noop'])) return;
    $GLOBALS['aimee_registration_users'][$user_id]['user_pass'] = wp_hash_password((string) $password);
}
function get_userdata($user_id) {
    $user = $GLOBALS['aimee_registration_users'][intval($user_id)] ?? null;
    return $user ? (object) $user : false;
}
function get_user_by($field, $value) {
    if ($field === 'id' || $field === 'ID') return get_userdata(intval($value));
    foreach ($GLOBALS['aimee_registration_users'] as $user) {
        if ($field === 'login' && hash_equals((string) $user['login'], (string) $value)) return (object) $user;
    }
    return false;
}
function wp_delete_user($user_id) {
    $GLOBALS['aimee_registration_trace'][] = 'user_delete:' . intval($user_id);
    unset($GLOBALS['aimee_registration_users'][intval($user_id)]);
    return true;
}

function aimee_profile_media_limits() { return ['bytes' => 8 * 1024 * 1024]; }
function aimee_profile_media_validate_bytes($bytes) {
    return ['mime' => 'image/jpeg', 'extension' => 'jpg', 'width' => 640, 'height' => 480, 'bytes' => strlen((string) $bytes)];
}
function aimee_profile_media_store($user_id, $bytes, $validated = null) {
    $GLOBALS['aimee_registration_trace'][] = 'profile_media_store';
    if (!empty($GLOBALS['aimee_registration_scenario']['profile_media_store_failure'])) {
        return new WP_Error('profile_media_write_failed', 'Simulated optional profile image failure.');
    }
    return ['url' => 'https://example.test/aimee-profile-media/' . intval($user_id) . '/profile.jpg'];
}
function aimee_profile_media_delete_user_files($user_id) {
    $GLOBALS['aimee_registration_trace'][] = 'profile_media_delete:' . intval($user_id);
}
function aimee_profile_media_url_is_protected($url) {
    return strpos((string) $url, 'https://example.test/aimee-profile-media/') === 0;
}
function aimee_profile_media_file_for_user($user_id) {
    return ['path' => '/aimee-private/profile-' . intval($user_id) . '.jpg'];
}
function aimee_profile_media_read_validated_file($path) {
    return [
        'bytes' => 'validated-private-image-bytes',
        'facts' => ['mime' => 'image/jpeg'],
    ];
}
function aimee_special_category_consent_version() { return 'special-category-v1'; }
function aimee_free_preview_limit() { return 30; }
function aimee_global_service_grace_profile_fields($now_ts = null) {
    return [
        'service_grace_code' => 'august_2026_processor_recovery',
        'service_grace_granted_at' => '2026-08-21 20:00:00',
        'service_grace_access_until' => '2026-08-31 23:00:00',
    ];
}
function aimee_global_sms_timezone_is_valid($timezone) { return $timezone === 'Europe/London'; }
function wp_set_current_user($user_id) { $GLOBALS['aimee_registration_trace'][] = 'current_user:' . intval($user_id); }
function wp_set_auth_cookie($user_id, $remember = false, $secure = '') { $GLOBALS['aimee_registration_trace'][] = 'auth_cookie:' . intval($user_id); }
function is_ssl() { return true; }
function aimee_account_deletion_tombstone_is_active($profile) { return false; }

function aimee_profile_attribution_directive($source, $name) {
    if (!empty($GLOBALS['aimee_registration_scenario']['throw_post_commit_enrichment'])) {
        throw new RuntimeException('Simulated post-commit enrichment failure containing private prose.');
    }
    return 'Profile source for ' . (string) $name . '.';
}
function aimee_synthetic_identity_directive() { return 'Aimee is synthetic.'; }
function aimee_primary_model() { return 'test-primary'; }
function aimee_vision_model() { return 'test-vision'; }
function aimee_model_options($route) { return []; }
function call_anthropic_api(...$args) {
    $GLOBALS['aimee_registration_trace'][] = (($args[2] ?? '') === 'test-vision')
        ? 'anthropic:vision'
        : 'anthropic:conversation';
    return 'Hi Alice, lovely to meet you. What always makes you smile? x';
}
function aimee_synthetic_identity_review_reply($reply, $message = '', $options = [], $context = []) {
    return ['reply' => (string) $reply, 'repaired' => false, 'requires_regeneration' => false];
}
function aimee_profile_attribution_review_reply($reply, $source, $name, $context = []) { return ['blocked' => false]; }
function aimee_profile_attribution_aimee_context($mode) { return []; }
function aimee_openrouter_is_context_acknowledgement($reply) { return false; }
function aimee_constrain_chat_reply($reply, $route) { return (string) $reply; }
function get_option_registration_admin_email() { return 'admin@example.test'; }
function wp_mail(...$args) {
    $GLOBALS['aimee_registration_trace'][] = 'mail';
    $GLOBALS['aimee_registration_mail_calls'][] = $args;
    return true;
}
function aimee_configured_identity_user_id($constant_name) {
    return !empty($GLOBALS['aimee_registration_scenario']['owner_sms_enabled']) ? 7 : 0;
}
function aimee_internal_identity_number($constant_name) {
    return !empty($GLOBALS['aimee_registration_scenario']['owner_sms_enabled'])
        ? '447700900999'
        : '';
}
function aimee_profile_market($profile) { return (string) ($profile->market ?? 'uk'); }
function aimee_is_owner_user($profile) {
    return !empty($GLOBALS['aimee_registration_scenario']['owner_sms_enabled'])
        && intval($profile->user_id ?? 0) === 7;
}
function aimee_global_sms_profile_is_verified($profile) { return true; }
function aimee_sms_can_send_now($profile) { return true; }
function aimee_send_system_sms(...$args) {
    $GLOBALS['aimee_registration_trace'][] = 'sms';
    $GLOBALS['aimee_registration_sms_calls'][] = $args;
    return true;
}
function aimee_get_subscription_snapshot($user_id, $profile = null) { return ['status' => 'trial']; }
function get_option_admin_fallback($key) { return $key === 'admin_email' ? 'admin@example.test' : false; }

/*
 * get_option() above backs diagnostic state. Give unrelated callers their
 * normal fallback without hiding a deliberately stored falsey option value.
 */
$GLOBALS['aimee_registration_options']['admin_email'] = 'admin@example.test';

function aimee_registration_test_extract_function($source, $name) {
    $tokens = token_get_all($source);
    $count = count($tokens);
    for ($index = 0; $index < $count; $index++) {
        if (!is_array($tokens[$index]) || $tokens[$index][0] !== T_FUNCTION) continue;
        $name_index = $index + 1;
        while (
            $name_index < $count
            && is_array($tokens[$name_index])
            && $tokens[$name_index][0] === T_WHITESPACE
        ) {
            $name_index++;
        }
        if (
            $name_index >= $count
            || !is_array($tokens[$name_index])
            || $tokens[$name_index][0] !== T_STRING
            || $tokens[$name_index][1] !== $name
        ) continue;

        $output = '';
        $depth = 0;
        $started = false;
        for ($cursor = $index; $cursor < $count; $cursor++) {
            $token = $tokens[$cursor];
            $text = is_array($token) ? $token[1] : $token;
            $output .= $text;
            if ($text === '{') { $depth++; $started = true; }
            elseif ($text === '}') {
                $depth--;
                if ($started && $depth === 0) return $output;
            }
        }
    }
    throw new RuntimeException('Function not found: ' . $name);
}

function aimee_registration_test_response_data($response) {
    if ($response instanceof WP_REST_Response) return (array) $response->get_data();
    return (array) $response;
}
function aimee_registration_test_response_status($response) {
    return $response instanceof WP_REST_Response ? $response->get_status() : 200;
}
function aimee_registration_test_reset(array $scenario = []) {
    global $wpdb;
    $wpdb = new Aimee_Registration_Test_Wpdb();
    $wpdb->fail_profile_insert = !empty($scenario['profile_insert_failure']);
    $wpdb->fail_message_insert = !empty($scenario['message_insert_failure']);
    $GLOBALS['aimee_registration_users'] = [];
    $GLOBALS['aimee_registration_next_user_id'] = 100;
    $GLOBALS['aimee_registration_options'] = ['admin_email' => 'admin@example.test'];
    $GLOBALS['aimee_registration_trace'] = [];
    $GLOBALS['aimee_registration_scenario'] = $scenario;
    $GLOBALS['aimee_registration_scheduled_events'] = [];
    $GLOBALS['aimee_registration_mail_calls'] = [];
    $GLOBALS['aimee_registration_sms_calls'] = [];

    if (!empty($scenario['owner_sms_enabled'])) {
        $wpdb->profiles[7] = (object) [
            'user_id' => 7,
            'phone_number' => '447700900999',
            'market' => 'uk',
            'sms_opt_in' => 1,
        ];
    }
}
function aimee_registration_test_request(array $overrides = [], array $remove = []) {
    $params = array_merge([
        'market' => 'uk',
        'phone_number' => '07700 900123',
        'passcode' => '012346',
        'first_name' => 'Alice',
        'age' => 31,
        'hobbies' => '',
        'looking_for' => '',
        'sms_timezone' => 'Europe/London',
        'special_category_consent' => false,
    ], $overrides);
    foreach ($remove as $key) unset($params[$key]);
    return new WP_REST_Request($params);
}

$root = dirname(__DIR__);
$engine = file_get_contents($root . '/includes/engine.php');
if (!is_string($engine) || $engine === '') {
    echo "Unable to read production engine for registration runtime tests.\n";
    exit(1);
}

/* Evaluate every registration helper in production source order. */
preg_match_all('/function\s+(aimee_registration_[a-zA-Z0-9_]+)\s*\(/', $engine, $helper_matches, PREG_OFFSET_CAPTURE);
$helpers = [];
foreach ($helper_matches[1] ?? [] as $match) {
    $helpers[(string) $match[0]] = intval($match[1]);
}
asort($helpers);
foreach (array_keys($helpers) as $helper) {
    if (!function_exists($helper)) eval(aimee_registration_test_extract_function($engine, $helper));
}
eval(aimee_registration_test_extract_function($engine, 'handle_aimee_profile_save'));

$failures = [];
$checks = 0;
$assert = static function ($condition, $label) use (&$failures, &$checks) {
    $checks++;
    if (!$condition) $failures[] = $label;
};
$last_diagnostic = static function () {
    $option_name = function_exists('aimee_registration_diagnostic_option_name')
        ? aimee_registration_diagnostic_option_name()
        : 'aimee_global_last_registration_failure';
    return (array) get_option($option_name, []);
};
$diagnostic_has_exact_shape = static function (array $record) {
    $keys = array_keys($record);
    sort($keys);
    return $keys === ['error_code', 'occurred_at', 'reference', 'stage'];
};
$diagnostic_excludes_request_data = static function (array $record) {
    $encoded = json_encode($record);
    foreach (['07700', '447700', '012346', 'Alice', 'first_name', 'phone_number', 'passcode', "doesn't have a default"] as $forbidden) {
        if (stripos((string) $encoded, $forbidden) !== false) return false;
    }
    return true;
};
$remote_trace = static function () {
    return array_values(array_filter(
        $GLOBALS['aimee_registration_trace'],
        static function ($entry) {
            return strpos((string) $entry, 'anthropic:') === 0
                || $entry === 'mail'
                || $entry === 'sms';
        }
    ));
};

/* Fresh registration: no acknowledgement field and explicit optional false. */
aimee_registration_test_reset();
$fresh_request = aimee_registration_test_request([], ['privacy_acknowledged']);
$fresh_params = $fresh_request->get_json_params();
$fresh = handle_aimee_profile_save($fresh_request);
$fresh_data = aimee_registration_test_response_data($fresh);
$fresh_user_id = intval($fresh_data['user_id'] ?? 0);
$fresh_user = $GLOBALS['aimee_registration_users'][$fresh_user_id] ?? [];
$fresh_profile = $GLOBALS['wpdb']->profiles[$fresh_user_id] ?? null;
$assert(!array_key_exists('privacy_acknowledged', $fresh_params), 'fresh request contains no privacy acknowledgement key');
$assert(($fresh_data['status'] ?? '') === 'success' && aimee_registration_test_response_status($fresh) === 200, 'fresh no-acknowledgement registration succeeds');
$assert($fresh_user_id > 0 && $fresh_profile !== null, 'fresh registration durably creates WordPress user and Aimee profile');
$assert(wp_check_password('012346', $fresh_user['user_pass'] ?? '', $fresh_user_id), 'leading-zero six-digit passcode is the final authenticating credential');
$assert(!property_exists($fresh_profile, 'privacy_acknowledged_at'), 'registration omits the privacy acknowledgement column instead of manufacturing an event');
$assert(isset($fresh_profile->special_category_consent_at) === false || $fresh_profile->special_category_consent_at === null, 'optional sensitive-information consent remains false');
$assert(isset($fresh_profile->special_category_consent_version) === false || $fresh_profile->special_category_consent_version === null, 'no sensitive-consent version is stored when optional consent is false');
$assert(in_array('profile_insert', $GLOBALS['aimee_registration_trace'], true) && !in_array('profile_replace', $GLOBALS['aimee_registration_trace'], true), 'fresh registration inserts a new profile without destructive replacement');
$assert($remote_trace() === [], 'public registration performs no model, mail or carrier call');
$assert(!empty($fresh_data['post_commit_scheduled']), 'fresh registration reports successful deferred-work scheduling');
$assert(count($GLOBALS['aimee_registration_scheduled_events']) === 1, 'fresh registration schedules exactly one post-commit event');
$fresh_event = $GLOBALS['aimee_registration_scheduled_events'][0] ?? [];
$assert(($fresh_event['hook'] ?? '') === aimee_registration_post_commit_hook(), 'post-commit event uses the versioned registration hook');
$assert(($fresh_event['args'] ?? []) === [$fresh_user_id], 'post-commit event carries only the immutable user ID');
$assert(aimee_registration_schedule_post_commit($fresh_user_id) === true && count($GLOBALS['aimee_registration_scheduled_events']) === 1, 're-scheduling the same account is idempotent');

/*
 * Do not bypass a host password policy with a speculative temporary secret.
 * Surface a referenced operational failure while recording only its safe code.
 */
aimee_registration_test_reset(['require_strong_creation_password' => true]);
$policy_failure = handle_aimee_profile_save(aimee_registration_test_request([
    'phone_number' => '07700 900124',
]));
$policy_failure_data = aimee_registration_test_response_data($policy_failure);
$policy_diagnostic = $last_diagnostic();
$assert(($policy_failure_data['status'] ?? '') === 'error' && (string) ($policy_failure_data['reference'] ?? '') !== '', 'host password-policy rejection returns a referenced operational error');
$assert(($policy_diagnostic['stage'] ?? '') === 'wp_user_create_failed', 'host password-policy rejection records the user-creation stage');
$assert(($policy_diagnostic['error_code'] ?? '') === 'wordpress_user_create_failed', 'host password-policy rejection maps to the fixed safe user-creation code');
$assert($diagnostic_has_exact_shape($policy_diagnostic), 'host password-policy diagnostic has exactly the four approved fields');
$assert($GLOBALS['aimee_registration_users'] === [], 'host password-policy rejection creates no temporary identity');

/* Optional image storage may degrade, but must not destroy the new account. */
aimee_registration_test_reset(['profile_media_store_failure' => true]);
$photo = handle_aimee_profile_save(aimee_registration_test_request([
    'phone_number' => '07700 900125',
    'image' => 'data:image/jpeg;base64,' . base64_encode('valid-test-image-bytes'),
]));
$photo_data = aimee_registration_test_response_data($photo);
$photo_user_id = intval($photo_data['user_id'] ?? 0);
$photo_profile = $GLOBALS['wpdb']->profiles[$photo_user_id] ?? null;
$photo_diagnostic = $last_diagnostic();
$assert(($photo_data['status'] ?? '') === 'success' && $photo_user_id > 0, 'optional profile-media store failure does not block signup');
$assert($photo_profile !== null && (string) ($photo_profile->profile_image_url ?? '') === '', 'degraded optional-photo signup persists without an image URL');
$assert(isset($GLOBALS['aimee_registration_users'][$photo_user_id]), 'optional-photo failure retains the WordPress identity');
$assert(($photo_diagnostic['stage'] ?? '') === 'profile_media_store_failed', 'optional-photo failure records its private diagnostic stage');
$assert(($photo_diagnostic['error_code'] ?? '') === 'profile_image_storage_failed', 'optional-photo diagnostic maps to the fixed safe media-storage code');
$assert($diagnostic_has_exact_shape($photo_diagnostic), 'optional-photo diagnostic has exactly the four approved fields');
$assert($diagnostic_excludes_request_data($photo_diagnostic), 'optional-photo diagnostic excludes credentials, identifiers, names, images and error prose');
$assert($remote_trace() === [], 'failed optional photo triggers no inline model, mail or carrier call');
$assert(count($GLOBALS['aimee_registration_scheduled_events']) === 1, 'optional-photo degradation still schedules deferred work once');

/* A local opener failure is post-commit and can never reverse registration. */
aimee_registration_test_reset(['message_insert_failure' => true]);
$recovered = handle_aimee_profile_save(aimee_registration_test_request([
    'phone_number' => '07700 900126',
]));
$recovered_data = aimee_registration_test_response_data($recovered);
$recovered_user_id = intval($recovered_data['user_id'] ?? 0);
$recovered_diagnostic = $last_diagnostic();
$assert(($recovered_data['status'] ?? '') === 'success' && !empty($recovered_data['onboarding_recovered']), 'local opener persistence failure recovers as a successful signup');
$assert($recovered_user_id > 0 && isset($GLOBALS['aimee_registration_users'][$recovered_user_id]), 'post-commit recovery retains the WordPress identity');
$assert(isset($GLOBALS['wpdb']->profiles[$recovered_user_id]), 'post-commit recovery retains the durable Aimee profile');
$assert(wp_check_password('012346', $GLOBALS['aimee_registration_users'][$recovered_user_id]['user_pass'] ?? '', $recovered_user_id), 'post-commit recovery retains the chosen leading-zero PIN');
$assert(($recovered_diagnostic['stage'] ?? '') === 'post_commit_completion_failed' && ($recovered_diagnostic['error_code'] ?? '') === 'post_commit_completion_failed', 'post-commit recovery records only its fixed diagnostic reason');
$assert($diagnostic_has_exact_shape($recovered_diagnostic) && $diagnostic_excludes_request_data($recovered_diagnostic), 'post-commit recovery diagnostic has the exact private-safe shape');
$assert(count($GLOBALS['aimee_registration_scheduled_events']) === 1, 'post-commit recovery still schedules its worker exactly once');
$assert($remote_trace() === [], 'post-commit recovery remains free of inline model, mail and carrier work');

/* A scheduler outage is optional and still returns the durable account. */
aimee_registration_test_reset(['schedule_failure' => true]);
$unscheduled = handle_aimee_profile_save(aimee_registration_test_request([
    'phone_number' => '07700 900127',
]));
$unscheduled_data = aimee_registration_test_response_data($unscheduled);
$unscheduled_user_id = intval($unscheduled_data['user_id'] ?? 0);
$assert(($unscheduled_data['status'] ?? '') === 'success' && $unscheduled_user_id > 0, 'scheduler failure never turns a durable signup into an error');
$assert(empty($unscheduled_data['post_commit_scheduled']), 'scheduler failure is reported as an optional false flag');
$assert(isset($GLOBALS['wpdb']->profiles[$unscheduled_user_id]) && isset($GLOBALS['aimee_registration_users'][$unscheduled_user_id]), 'scheduler failure retains both account records');
$assert($remote_trace() === [], 'scheduler failure does not fall back to inline external work');

/* Deferred network work is claimed once and never runs in the REST handler. */
aimee_registration_test_reset(['owner_sms_enabled' => true]);
$deferred_signup = handle_aimee_profile_save(aimee_registration_test_request([
    'phone_number' => '07700 900128',
    'image' => 'data:image/jpeg;base64,' . base64_encode('valid-test-image-bytes'),
]));
$deferred_data = aimee_registration_test_response_data($deferred_signup);
$deferred_user_id = intval($deferred_data['user_id'] ?? 0);
$assert(($deferred_data['status'] ?? '') === 'success' && $deferred_user_id > 0, 'photo registration commits before deferred provider work');
$assert($remote_trace() === [], 'photo registration returns before vision, mail and SMS execute');
$assert(count($GLOBALS['aimee_registration_scheduled_events']) === 1 && ($GLOBALS['aimee_registration_scheduled_events'][0]['args'] ?? []) === [$deferred_user_id], 'photo registration queues one minimal worker payload');

aimee_registration_run_post_commit($deferred_user_id);
$worker_state_name = aimee_registration_post_commit_state_option_name($deferred_user_id);
$worker_state = (array) get_option($worker_state_name, []);
$assert(count(array_keys($GLOBALS['aimee_registration_trace'], 'anthropic:vision', true)) === 1, 'worker performs optional vision enrichment once');
$assert(count($GLOBALS['aimee_registration_mail_calls']) === 1, 'worker sends the administrator mail once');
$assert(count($GLOBALS['aimee_registration_sms_calls']) === 1, 'worker sends the consent-gated owner alert once');
$assert(($worker_state['status'] ?? '') === 'completed', 'worker records durable completion after bounded work');

$trace_after_first_worker = $GLOBALS['aimee_registration_trace'];
$mail_after_first_worker = $GLOBALS['aimee_registration_mail_calls'];
$sms_after_first_worker = $GLOBALS['aimee_registration_sms_calls'];
aimee_registration_run_post_commit($deferred_user_id);
$assert($GLOBALS['aimee_registration_trace'] === $trace_after_first_worker, 'duplicate worker delivery exits before repeating any work');
$assert($GLOBALS['aimee_registration_mail_calls'] === $mail_after_first_worker, 'duplicate worker delivery cannot resend administrator mail');
$assert($GLOBALS['aimee_registration_sms_calls'] === $sms_after_first_worker, 'duplicate worker delivery cannot resend carrier SMS');

/* A worker whose profile has gone away is bounded and marked skipped. */
aimee_registration_run_post_commit(999);
$missing_worker_state = (array) get_option(aimee_registration_post_commit_state_option_name(999), []);
$assert(($missing_worker_state['status'] ?? '') === 'skipped', 'worker marks a missing profile as skipped');
$assert($GLOBALS['aimee_registration_mail_calls'] === $mail_after_first_worker && $GLOBALS['aimee_registration_sms_calls'] === $sms_after_first_worker, 'missing-profile worker performs no notification work');

/* Reserved/existing identifiers stay enumeration-safe and unrecorded. */
foreach ([
    'reserved_identifier' => 'reserved_identifier',
    'existing_identifier' => 'existing_identifier',
] as $scenario_key => $expected_stage) {
    aimee_registration_test_reset([$scenario_key => true]);
    $response = handle_aimee_profile_save(aimee_registration_test_request());
    $data = aimee_registration_test_response_data($response);
    $assert(aimee_registration_test_response_status($response) === 400 && ($data['status'] ?? '') === 'error', $expected_stage . ' fails registration');
    $assert(!isset($data['reference']) || (string) $data['reference'] === '', $expected_stage . ' exposes no public diagnostic reference');
    $assert($last_diagnostic() === [], $expected_stage . ' leaves no operational diagnostic containing identifier state');
}

/* A create-time existence race remains indistinguishable from preflight. */
aimee_registration_test_reset([
    'force_user_create_error' => true,
    'user_create_error_code' => 'existing_user_login',
]);
$race = handle_aimee_profile_save(aimee_registration_test_request());
$race_data = aimee_registration_test_response_data($race);
$assert(aimee_registration_test_response_status($race) === 400 && ($race_data['status'] ?? '') === 'error', 'create-time existing-login race uses the enumeration-safe error');
$assert(!isset($race_data['reference']) && $last_diagnostic() === [], 'create-time existing-login race exposes and stores no operational reference');

/* Operational failures expose a reference, retain safe facts, and roll back. */
aimee_registration_test_reset(['force_user_create_error' => true]);
$user_failure = handle_aimee_profile_save(aimee_registration_test_request());
$user_failure_data = aimee_registration_test_response_data($user_failure);
$user_failure_diagnostic = $last_diagnostic();
$assert(aimee_registration_test_response_status($user_failure) >= 400 && ($user_failure_data['status'] ?? '') === 'error', 'WordPress user-creation failure returns an error');
$assert((string) ($user_failure_data['reference'] ?? '') !== '', 'WordPress user-creation failure returns an operational reference');
$assert(($user_failure_diagnostic['stage'] ?? '') === 'wp_user_create_failed', 'WordPress user-creation diagnostic records its stage');
$assert(($user_failure_diagnostic['error_code'] ?? '') === 'wordpress_user_create_failed', 'WordPress user-creation diagnostic uses the fixed safe error code');
$assert($diagnostic_has_exact_shape($user_failure_diagnostic), 'WordPress user-creation diagnostic has exactly the four approved fields');
$assert($diagnostic_excludes_request_data($user_failure_diagnostic), 'WordPress user-creation diagnostic excludes credentials and account details');
$assert(hash_equals((string) ($user_failure_diagnostic['reference'] ?? ''), (string) ($user_failure_data['reference'] ?? '')), 'WordPress user-creation response reference matches the private diagnostic');

aimee_registration_test_reset(['profile_insert_failure' => true]);
$profile_failure = handle_aimee_profile_save(aimee_registration_test_request());
$profile_failure_data = aimee_registration_test_response_data($profile_failure);
$profile_diagnostic = $last_diagnostic();
$assert(($profile_failure_data['status'] ?? '') === 'error' && (string) ($profile_failure_data['reference'] ?? '') !== '', 'profile insert failure returns a referenced operational error');
$assert(($profile_diagnostic['stage'] ?? '') === 'profile_insert_failed', 'profile insert diagnostic records its stage');
$assert(($profile_diagnostic['error_code'] ?? '') === 'profile_database_write_failed', 'profile insert diagnostic uses a safe generic database-write code');
$assert($diagnostic_has_exact_shape($profile_diagnostic), 'profile insert diagnostic has exactly the four approved fields');
$assert($diagnostic_excludes_request_data($profile_diagnostic), 'profile insert diagnostic excludes SQL text, credentials and account details');
$assert(hash_equals((string) ($profile_diagnostic['reference'] ?? ''), (string) ($profile_failure_data['reference'] ?? '')), 'profile insert response reference matches the private diagnostic');
$assert($GLOBALS['aimee_registration_users'] === [], 'profile insert failure rolls back the WordPress identity');

if ($failures) {
    foreach ($failures as $failure) echo "FAIL {$failure}\n";
    echo 'FAIL: ' . count($failures) . " of {$checks} registration runtime assertions failed\n";
    exit(1);
}

echo "PASS: {$checks} registration runtime assertions\n";
