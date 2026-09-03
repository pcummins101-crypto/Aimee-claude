<?php
/**
 * Minimal WordPress stand-ins so the engine's pure functions can be tested
 * with plain PHP: `php tests/run.php`.
 */
define('ABSPATH', '/');
define('AIMEE_ENGINE_VERSION', 'test');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('ANTHROPIC_API_KEY', 'test-key');

$GLOBALS['aimee_test_options'] = [];
$GLOBALS['aimee_test_transients'] = [];
$GLOBALS['aimee_test_http'] = [];

function get_transient($key) { return $GLOBALS['aimee_test_transients'][$key] ?? false; }
function set_transient($key, $value, $ttl = 0) { $GLOBALS['aimee_test_transients'][$key] = $value; return true; }
function delete_transient($key) { unset($GLOBALS['aimee_test_transients'][$key]); return true; }
function trailingslashit($v) { return rtrim((string) $v, '/\\') . '/'; }

/** Minimal $wpdb: only the database clock probe is exercised here. */
class Aimee_Test_Wpdb {
    public $db_now = null;
    public function get_var($sql) { return strpos((string) $sql, 'NOW()') !== false ? $this->db_now : null; }
    public function prepare($sql) { return $sql; }
    public function get_results($sql) { return []; }
    public function get_row($sql) { return null; }
}
$GLOBALS['wpdb'] = new Aimee_Test_Wpdb();

function add_action() {}
function add_filter() {}
function register_activation_hook() {}
function register_setting() {}
function add_options_page() {}
function get_option($key, $default = false) { return $GLOBALS['aimee_test_options'][$key] ?? $default; }
function update_option($key, $value) { $GLOBALS['aimee_test_options'][$key] = $value; return true; }
function add_option($key, $value) { $GLOBALS['aimee_test_options'][$key] = $value; return true; }
function get_user_meta($user_id, $key, $single = true) { return $GLOBALS['aimee_test_user_meta'][$user_id][$key] ?? ''; }
function current_user_can() { return false; }
function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)); }
function sanitize_text_field($text) { return trim(strip_tags((string) $text)); }
function sanitize_textarea_field($text) { return trim(strip_tags((string) $text)); }
function wp_kses_post($text) { return (string) $text; }
function wp_json_encode($data) { return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
function current_time($type, $gmt = 0) { return gmdate('Y-m-d H:i:s'); }
function home_url() { return 'https://example.test'; }
function is_wp_error($thing) { return $thing instanceof WP_Error; }
function wp_remote_post($url, $args) {
    $GLOBALS['aimee_test_http'][] = ['url' => $url, 'args' => $args];
    if (!empty($GLOBALS['aimee_test_http_sequence'])) return array_shift($GLOBALS['aimee_test_http_sequence']);
    return $GLOBALS['aimee_test_http_response'] ?? ['code' => 200, 'body' => '{}'];
}
function wp_remote_retrieve_response_code($r) { return $r['code'] ?? 0; }
function wp_remote_retrieve_body($r) { return $r['body'] ?? ''; }

class WP_Error {
    public $code; public $message; public $data;
    public function __construct($code = '', $message = '', $data = null) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_message() { return $this->message; }
}

require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/cohort.php';
require_once __DIR__ . '/../includes/anthropic.php';
require_once __DIR__ . '/../includes/openrouter.php';
require_once __DIR__ . '/../includes/context.php';
require_once __DIR__ . '/../includes/router.php';
require_once __DIR__ . '/../includes/photos.php';

$GLOBALS['aimee_test_results'] = ['pass' => 0, 'fail' => 0, 'failures' => []];

function assert_true($condition, $label) {
    if ($condition) {
        $GLOBALS['aimee_test_results']['pass']++;
    } else {
        $GLOBALS['aimee_test_results']['fail']++;
        $GLOBALS['aimee_test_results']['failures'][] = $label;
        fwrite(STDERR, "FAIL: {$label}\n");
    }
}
function assert_same($expected, $actual, $label) {
    assert_true($expected === $actual, $label . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}
function test_reset() {
    $GLOBALS['aimee_test_options'] = [];
    $GLOBALS['aimee_test_http'] = [];
    $GLOBALS['aimee_test_http_response'] = null;
    $GLOBALS['aimee_test_http_sequence'] = [];
    $GLOBALS['aimee_test_transients'] = [];
    $GLOBALS['wpdb']->db_now = null;
    aimee_engine_reset_settings_cache();
}
