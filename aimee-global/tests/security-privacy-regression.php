<?php
/** Standalone executable regressions for security/privacy behavior through 1.8.9. */

define('ABSPATH', '/aimee/public/wordpress/');
define('MINUTE_IN_SECONDS', 60);
$_SERVER['DOCUMENT_ROOT'] = '/aimee/public';
$_SERVER['REMOTE_ADDR'] = '198.51.100.24';
if (!is_dir($_SERVER['DOCUMENT_ROOT'])) mkdir($_SERVER['DOCUMENT_ROOT'], 0755, true);

class WP_Error {
    private $code;
    private $message;
    private $data;
    public function __construct($code = '', $message = '', $data = null) {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }
    public function get_error_codes() { return $this->code === '' ? [] : [$this->code]; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
class WP_User {
    public $ID;
    public function __construct($id) { $this->ID = intval($id); }
}
class WP_REST_Request {
    private $method;
    private $params;
    public function __construct($method, array $params = []) {
        $this->method = $method;
        $this->params = $params;
    }
    public function get_method() { return $this->method; }
    public function get_json_params() { return $this->params; }
}

class Aimee_Test_Wpdb {
    public $profiles = [];
    public $before_update = null;
    public function prepare($query, ...$args) {
        return ['query' => (string) $query, 'args' => $args];
    }
    public function get_row($prepared) {
        $args = is_array($prepared) ? ($prepared['args'] ?? []) : [];
        $user_id = intval($args[0] ?? 0);
        return $this->profiles[$user_id] ?? null;
    }
    public function get_results($prepared) {
        $args = is_array($prepared) ? ($prepared['args'] ?? []) : [];
        $cursor = intval($args[0] ?? 0);
        $protected = (string) ($args[1] ?? '');
        $limit = intval($args[2] ?? 20);
        $rows = [];
        ksort($this->profiles);
        foreach ($this->profiles as $user_id => $profile) {
            $url = (string) ($profile->profile_image_url ?? '');
            if (intval($user_id) <= $cursor || $url === '' || $url === $protected) continue;
            $rows[] = (object) ['user_id' => intval($user_id), 'profile_image_url' => $url];
            if (count($rows) >= $limit) break;
        }
        return $rows;
    }
    public function get_var($prepared) {
        $query = is_array($prepared) ? (string) ($prepared['query'] ?? '') : (string) $prepared;
        $args = is_array($prepared) ? ($prepared['args'] ?? []) : [];
        if (stripos($query, 'COUNT(*)') !== false) {
            $protected = (string) ($args[0] ?? '');
            $count = 0;
            foreach ($this->profiles as $profile) {
                $url = (string) ($profile->profile_image_url ?? '');
                if ($url !== '' && $url !== $protected) $count++;
            }
            return $count;
        }
        $user_id = intval($args[0] ?? 0);
        return isset($this->profiles[$user_id])
            ? ($this->profiles[$user_id]->profile_image_url ?? null)
            : null;
    }
    public function query($prepared) {
        $query = is_array($prepared) ? (string) ($prepared['query'] ?? '') : (string) $prepared;
        $args = is_array($prepared) ? ($prepared['args'] ?? []) : [];
        if (stripos($query, 'UPDATE') !== 0) return false;
        $protected_url = (string) ($args[0] ?? '');
        $user_id = intval($args[1] ?? 0);
        $legacy_url = (string) ($args[2] ?? '');
        return $this->update(
            'aimee_user_profiles',
            ['profile_image_url' => $protected_url],
            ['user_id' => $user_id, 'profile_image_url' => $legacy_url]
        );
    }
    public function update($table, array $data, array $where, array $formats = [], array $where_formats = []) {
        if (is_callable($this->before_update)) {
            call_user_func($this->before_update, $this, $table, $data, $where);
            $this->before_update = null;
        }
        $user_id = intval($where['user_id'] ?? 0);
        if (!isset($this->profiles[$user_id])) return 0;
        foreach ($where as $key => $expected) {
            $actual = $key === 'user_id' ? $user_id : ($this->profiles[$user_id]->{$key} ?? null);
            if ((string) $actual !== (string) $expected) return 0;
        }
        foreach ($data as $key => $value) $this->profiles[$user_id]->{$key} = $value;
        return 1;
    }
}

$GLOBALS['aimee_test_transients'] = [];
$GLOBALS['aimee_test_deleted_transients'] = [];
$GLOBALS['aimee_test_users'] = [];
$GLOBALS['aimee_test_bypass_lock'] = false;
$GLOBALS['aimee_test_filters'] = [];
$GLOBALS['aimee_test_options'] = [];
$GLOBALS['aimee_test_current_user_id'] = 0;
$GLOBALS['wpdb'] = new Aimee_Test_Wpdb();

function __($value, $domain = '') { return $value; }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_html__($value, $domain = '') { return esc_html($value); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_email($value) { return strtolower(trim((string) $value)); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function wp_unslash($value) { return $value; }
function is_email($value) { return filter_var($value, FILTER_VALIDATE_EMAIL) !== false; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function add_filter($hook, $callback, $priority = 10, $args = 1) {
    $GLOBALS['aimee_test_filters'][] = [$hook, $callback, $priority, $args];
    return true;
}
function add_action($hook, $callback, $priority = 10, $args = 1) { return true; }
function do_action($hook, ...$args) { return null; }
function apply_filters($hook, $value) {
    if ($hook === 'aimee_auth_security_bypass_lock') {
        return !empty($GLOBALS['aimee_test_bypass_lock']);
    }
    return $value;
}
function get_user_by($field, $value) {
    return $GLOBALS['aimee_test_users'][(string) $value] ?? false;
}
function get_transient($key) { return $GLOBALS['aimee_test_transients'][$key] ?? false; }
function set_transient($key, $value, $ttl) {
    $GLOBALS['aimee_test_transients'][$key] = $value;
    return true;
}
function delete_transient($key) {
    $GLOBALS['aimee_test_deleted_transients'][] = $key;
    unset($GLOBALS['aimee_test_transients'][$key]);
    return true;
}
function wp_normalize_path($path) { return str_replace('\\', '/', (string) $path); }
function untrailingslashit($path) { return rtrim((string) $path, '/\\'); }
function wp_upload_dir($time = null, $create = true) {
    return [
        'basedir' => ABSPATH . 'wp-content/uploads',
        'baseurl' => 'https://example.test/wp-content/uploads',
    ];
}
function wp_mkdir_p($path) {
    return is_dir($path) || mkdir($path, 0700, true);
}
function wp_delete_file($path) { if (is_file($path)) unlink($path); }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . ltrim($path, '/'); }
function home_url($path = '') { return 'https://example.test/' . ltrim($path, '/'); }
function add_query_arg($key, $value = null, $url = '') {
    $args = is_array($key) ? $key : [$key => $value];
    return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($args);
}
function get_option($key, $default = false) { return $GLOBALS['aimee_test_options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['aimee_test_options'][$key] = $value; return true; }
function current_time($type, $gmt = false) { return '2026-08-20 16:30:00'; }
function get_current_user_id() { return intval($GLOBALS['aimee_test_current_user_id']); }
function rest_ensure_response($value) { return $value; }
function aimee_table($name) { return $name; }
function aimee_global_core_schema_health($refresh = false) { return true; }

require_once dirname(__DIR__) . '/includes/security-privacy.php';

$failures = [];
$checks = 0;
$assert = static function ($condition, $label) use (&$failures, &$checks) {
    $checks++;
    if (!$condition) $failures[] = $label;
};

$version = aimee_special_category_consent_version();
$assert(aimee_special_category_consent_is_active([
    'special_category_consent_at' => '2026-08-20 12:00:00',
    'special_category_consent_version' => $version,
]), 'current timestamped special-category consent is active');
$assert(!aimee_special_category_consent_is_active([
    'special_category_consent_at' => '2026-08-20 12:00:00',
    'special_category_consent_version' => 'old-version',
]), 'old consent version fails closed');
$assert(!aimee_special_category_consent_is_active([
    'special_category_consent_at' => '',
    'special_category_consent_version' => $version,
]), 'missing consent timestamp fails closed');
$assert(!aimee_special_category_consent_is_active([
    'special_category_consent_at' => 'yes',
    'special_category_consent_version' => $version,
]), 'malformed consent timestamp fails closed');
$uk_forms = ['+447700900123', '00447700900123', '447700900123', '07700900123'];
$uk_keys = array_map('aimee_auth_security_normalized_identifier', $uk_forms);
$assert(count(array_unique($uk_keys)) === 1, 'UK +44/0044/44/0 aliases share one fallback throttle identity');
$us_forms = ['2025550198', '12025550198', '+12025550198'];
$us_keys = array_map('aimee_auth_security_normalized_identifier', $us_forms);
$assert(count(array_unique($us_keys)) === 1, 'US national/+1 aliases share one fallback throttle identity');
$auth_priorities = [];
foreach ($GLOBALS['aimee_test_filters'] as $filter) {
    if ($filter[0] === 'authenticate' && $filter[1] === 'aimee_auth_security_precheck') {
        $auth_priorities[] = $filter[2];
    }
}
$assert(in_array(2, $auth_priorities, true), 'lock precheck is registered before authenticators');
$assert(in_array(PHP_INT_MAX, $auth_priorities, true), 'lock enforcement is registered after every authenticator');

$GLOBALS['aimee_test_transients'] = [];
$legacy_candidate = new WP_User(7);
foreach (['123456', 'correct horse battery staple', 'legacy-password-!@#', '01234567'] as $existing_password) {
    $assert(
        aimee_auth_security_precheck($legacy_candidate, '07700900123', $existing_password) === $legacy_candidate,
        'existing login accepts its stored credential without applying the new-registration format'
    );
}
aimee_auth_security_record_failure('07700900123');
$failure_identity_key = 'aimee_auth_' . aimee_auth_security_identity_key('07700900123');
$assert(intval($GLOBALS['aimee_test_transients'][$failure_identity_key]['failures'] ?? 0) === 1, 'first authentication failure is counted');
aimee_auth_security_record_failure('07700900123');
$assert(intval($GLOBALS['aimee_test_transients'][$failure_identity_key]['failures'] ?? 0) === 2, 'second failure in the same HTTP request is also counted');

$identity_key = 'aimee_auth_' . aimee_auth_security_identity_key('07700900123');
$ip_key = 'aimee_auth_ip_' . hash('sha256', $_SERVER['REMOTE_ADDR']);
$locked = ['failures' => 8, 'started_at' => time(), 'locked_until' => time() + 600];
$GLOBALS['aimee_test_transients'][$identity_key] = $locked;
$GLOBALS['aimee_test_transients'][$ip_key] = $locked;
$preauthenticated = new WP_User(42);
$blocked = aimee_auth_security_precheck($preauthenticated, '07700900123', 'correct password');
$assert(is_wp_error($blocked), 'an active lock overrides an already-authenticated WP_User candidate');
$GLOBALS['aimee_test_bypass_lock'] = true;
$assert(
    aimee_auth_security_precheck($preauthenticated, '07700900123', 'correct password') === $preauthenticated,
    'explicit trusted lock-bypass filter remains available'
);
$GLOBALS['aimee_test_bypass_lock'] = false;

$user = new WP_User(42);
$GLOBALS['aimee_test_users']['07700900123'] = $user;
$identity_key = 'aimee_auth_' . aimee_auth_security_identity_key('07700900123');
$GLOBALS['aimee_test_transients'][$identity_key] = $locked;
$GLOBALS['aimee_test_transients'][$ip_key] = $locked;
$GLOBALS['aimee_test_deleted_transients'] = [];
aimee_auth_security_clear_success('07700900123', $user);
$assert(!isset($GLOBALS['aimee_test_transients'][$identity_key]), 'successful login clears its identity bucket');
$assert(isset($GLOBALS['aimee_test_transients'][$ip_key]), 'successful login never clears the shared IP bucket');
$assert(
    !in_array($ip_key, $GLOBALS['aimee_test_deleted_transients'], true),
    'IP bucket is not even requested for deletion on success'
);

$assert(aimee_profile_media_basename(42) === 'profile-user-42', 'private profile filename is deterministic and salt-independent');
$private_dir = wp_normalize_path(aimee_profile_media_dir());
$assert(!aimee_profile_media_path_is_within($private_dir, ABSPATH), 'default profile media directory is outside ABSPATH');
$assert(!aimee_profile_media_path_is_within($private_dir, $_SERVER['DOCUMENT_ROOT']), 'default profile media directory is outside document root');
$assert(aimee_profile_media_path_is_within(ABSPATH . 'wp-content/uploads/a.jpg', ABSPATH), 'public-root containment recognizes nested files');
$assert(aimee_profile_media_path_is_within('/anything/public.jpg', '/'), 'filesystem-root document root contains every absolute path');

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
$facts = aimee_profile_media_validate_bytes($png);
$assert(!is_wp_error($facts), 'valid PNG passes magic-MIME and dimension validation');
$assert(is_array($facts) && ($facts['mime'] ?? '') === 'image/png', 'profile validator reports actual PNG MIME');
$stored = is_array($facts)
    ? aimee_profile_media_store(42, $png, $facts)
    : new WP_Error('test_validation_failed', 'Validation failed before storage.');
$storage_detail = is_wp_error($stored) ? ': ' . $stored->get_error_message() : '';
$assert(!is_wp_error($stored), 'validated profile image stores in private media directory' . $storage_detail);
$stored_path = is_array($stored) ? (string) ($stored['path'] ?? '') : '';
$stored_url = is_array($stored) ? (string) ($stored['url'] ?? '') : '';
$assert(basename($stored_path) === 'profile-user-42.png', 'stored filename is stable per user');
$assert($stored_path !== '' && aimee_profile_media_permissions_are_private($stored_path, false), 'stored profile image is owner-only on POSIX');
$assert(aimee_profile_media_permissions_are_private(aimee_profile_media_dir(), true), 'private profile directory excludes group/other access on POSIX');
$assert(strpos($stored_url, 'action=aimee_profile_photo') !== false, 'profile exposes only authenticated controller URL');
$assert(strpos($stored_url, 'profile-user-42') === false, 'profile URL does not expose the private filename');
$assert(aimee_profile_media_delete_user_files(42), 'deterministic account cleanup removes private profile media');
$assert($stored_path !== '' && !is_file($stored_path), 'private profile file is absent after cleanup');

$uploads = wp_upload_dir(null, false);
$legacy_directory = $uploads['basedir'] . '/2026/08';
if (!is_dir($legacy_directory)) mkdir($legacy_directory, 0700, true);
$legacy_url = static function ($user_id, $timestamp = '1755700000') {
    return 'https://example.test/wp-content/uploads/2026/08/aimee_user_'
        . intval($user_id) . '_' . $timestamp . '.png';
};
$legacy_path = static function ($user_id, $timestamp = '1755700000') use ($legacy_directory) {
    return $legacy_directory . '/aimee_user_' . intval($user_id) . '_' . $timestamp . '.png';
};

$candidate = aimee_profile_media_legacy_candidate(43, $legacy_url(43));
$assert(!is_wp_error($candidate), 'owner-bound current-origin legacy upload URL is recognized');
$assert(
    !is_wp_error(aimee_profile_media_legacy_candidate(43, str_replace('https://', 'http://', $legacy_url(43)))),
    'same-host legacy HTTP URL remains migratable after a site HTTPS upgrade'
);
$assert(
    is_wp_error(aimee_profile_media_legacy_candidate(44, $legacy_url(43))),
    'legacy URL cannot be claimed by a different profile owner'
);
$assert(
    is_wp_error(aimee_profile_media_legacy_candidate(43, 'https://evil.example/wp-content/uploads/2026/08/aimee_user_43_1755700000.png')),
    'cross-origin legacy profile URL is rejected'
);
$assert(
    is_wp_error(aimee_profile_media_legacy_candidate(43, $legacy_url(43) . '?download=1')),
    'legacy profile URL with a query string is rejected'
);
$assert(
    is_wp_error(aimee_profile_media_legacy_candidate(43, 'https://example.test/wp-content/uploads/2026/%2e%2e/aimee_user_43_1755700000.png')),
    'encoded legacy upload traversal is rejected'
);
$mismatched_url = 'https://example.test/wp-content/uploads/2026/08/aimee_user_52_1755700005.jpg';
$mismatched_path = $legacy_directory . '/aimee_user_52_1755700005.jpg';
file_put_contents($mismatched_path, $png);
$GLOBALS['wpdb']->profiles[52] = (object) ['user_id' => 52, 'profile_image_url' => $mismatched_url];
$assert(
    is_wp_error(aimee_profile_media_migrate_legacy_profile($GLOBALS['wpdb']->profiles[52])),
    'legacy extension and magic MIME mismatch fails closed before private storage'
);
$assert(is_file($mismatched_path), 'rejected legacy MIME mismatch is never destructively deleted');
wp_delete_file($mismatched_path);
unset($GLOBALS['wpdb']->profiles[52]);

file_put_contents($legacy_path(43), $png);
$GLOBALS['wpdb']->profiles[43] = (object) [
    'user_id' => 43,
    'profile_image_url' => $legacy_url(43),
    'privacy_acknowledged_at' => null,
    'special_category_consent_at' => null,
    'special_category_consent_version' => null,
];
$migrated = aimee_profile_media_migrate_legacy_profile($GLOBALS['wpdb']->profiles[43]);
$assert($migrated === true, 'recognized public profile photo migrates successfully');
$assert(!is_file($legacy_path(43)), 'migration deletes and verifies the public legacy file');
$assert(
    $GLOBALS['wpdb']->profiles[43]->profile_image_url === aimee_profile_media_url(),
    'migration conditionally commits only the authenticated profile URL'
);
$assert(aimee_profile_media_file_for_user(43) !== null, 'migrated private owner file is revalidated');
$assert(aimee_profile_media_delete_user_files(43), 'migrated private file remains deterministically erasable');

// Simulate a crash after the private commit: retry succeeds from the verified
// private file even though the source upload is already absent.
$stored_retry = aimee_profile_media_store(44, $png);
$GLOBALS['wpdb']->profiles[44] = (object) [
    'user_id' => 44,
    'profile_image_url' => $legacy_url(44, '1755700001'),
];
$retry = aimee_profile_media_migrate_legacy_profile($GLOBALS['wpdb']->profiles[44]);
$assert(!is_wp_error($stored_retry) && $retry === true, 'migration retry recovers after private commit and public deletion');
$assert($GLOBALS['wpdb']->profiles[44]->profile_image_url === aimee_profile_media_url(), 'retry verifies the exact protected DB URL');
$assert(aimee_profile_media_delete_user_files(44), 'retry private media cleanup succeeds');

// A concurrent URL change must never be overwritten by the conditional write.
file_put_contents($legacy_path(45, '1755700002'), $png);
$GLOBALS['wpdb']->profiles[45] = (object) [
    'user_id' => 45,
    'profile_image_url' => $legacy_url(45, '1755700002'),
];
$GLOBALS['wpdb']->before_update = static function ($wpdb) {
    $wpdb->profiles[45]->profile_image_url = 'https://example.test/not-the-legacy-row.png';
};
$raced = aimee_profile_media_migrate_legacy_profile($GLOBALS['wpdb']->profiles[45]);
$assert(is_wp_error($raced), 'conditional DB race fails migration closed');
$assert(
    $GLOBALS['wpdb']->profiles[45]->profile_image_url === 'https://example.test/not-the-legacy-row.png',
    'conditional migration never overwrites a concurrently changed URL'
);
$GLOBALS['wpdb']->profiles[45]->profile_image_url = aimee_profile_media_url();
$assert(aimee_profile_media_delete_user_files(45), 'race-test private file cleanup succeeds');

// Pending account deletion discovers the owner-bound public file independently
// of the migration cursor and verifies both public and private remnants absent.
file_put_contents($legacy_path(46, '1755700003'), $png);
$GLOBALS['wpdb']->profiles[46] = (object) [
    'user_id' => 46,
    'profile_image_url' => $legacy_url(46, '1755700003'),
];
$assert(aimee_profile_media_delete_user_files(46), 'account cleanup deletes a recognized pending legacy upload');
$assert(!is_file($legacy_path(46, '1755700003')), 'pending public upload is absent after account cleanup');
$GLOBALS['wpdb']->profiles[46]->profile_image_url = '';

$private_media_dir = aimee_profile_media_prepare_dir();
$temporary_remnant = $private_media_dir . '/profile-user-47.png.tmp-crash-remnant';
file_put_contents($temporary_remnant, $png);
chmod($temporary_remnant, 0600);
$assert(aimee_profile_media_delete_user_files(47), 'account cleanup removes contained private temporary crash remnants');
$assert(!is_file($temporary_remnant), 'temporary profile crash remnant is verified absent');

$GLOBALS['wpdb']->profiles[48] = (object) [
    'user_id' => 48,
    'profile_image_url' => 'https://example.test/wp-content/uploads/2026/08/unrelated.png',
];
$assert(!aimee_profile_media_delete_user_files(48), 'unrecognized public profile URL blocks destructive account cleanup');

// Batch migration is a release-marker gate: it completes only after a fresh
// verified count reaches zero, and an unrecognized URL remains retryable.
unset($GLOBALS['wpdb']->profiles[48]);
file_put_contents($legacy_path(49, '1755700004'), $png);
$GLOBALS['wpdb']->profiles[49] = (object) [
    'user_id' => 49,
    'profile_image_url' => $legacy_url(49, '1755700004'),
];
$GLOBALS['aimee_test_options'] = [];
$assert(aimee_profile_media_maybe_migrate_legacy(true), 'bounded batch migration completes after verified public-file removal');
$assert(aimee_profile_media_migration_is_complete(), 'batch completion marker is written only after zero remaining rows');
$assert(aimee_profile_media_delete_user_files(49), 'batch-migrated private file cleanup succeeds');

$GLOBALS['aimee_test_options'] = [];
$GLOBALS['wpdb']->profiles[50] = (object) [
    'user_id' => 50,
    'profile_image_url' => 'https://example.test/wp-content/uploads/2026/08/not-owner-bound.png',
];
$assert(!aimee_profile_media_maybe_migrate_legacy(true), 'unrecognized profile URL keeps release migration pending');
$pending_status = get_option(aimee_profile_media_migration_option_name(), []);
$assert(empty($pending_status['completed_at']), 'fail-closed migration never writes a false completion marker');
unset($GLOBALS['wpdb']->profiles[50]);

// Existing NULL profiles gain consent only through this authenticated explicit
// endpoint; withdrawal clears the versioned proof and the legacy adult toggle.
$unauthenticated_consent = aimee_privacy_consent_settings(new WP_REST_Request('POST', [
    'special_category_consent' => true,
]));
$assert(is_wp_error($unauthenticated_consent), 'privacy choices cannot be changed without an authenticated owner');
$GLOBALS['aimee_test_current_user_id'] = 51;
$GLOBALS['wpdb']->profiles[51] = (object) [
    'user_id' => 51,
    'profile_image_url' => '',
    'privacy_acknowledged_at' => null,
    'special_category_consent_at' => null,
    'special_category_consent_version' => null,
    'escort_mode' => 1,
];
$assert(
    $GLOBALS['wpdb']->profiles[51]->privacy_acknowledged_at === null
        && $GLOBALS['wpdb']->profiles[51]->special_category_consent_at === null,
    'existing NULL consent state is not implicitly backfilled'
);
$invalid_consent = aimee_privacy_consent_settings(new WP_REST_Request('POST', [
    'special_category_consent' => 'maybe',
]));
$assert(is_wp_error($invalid_consent), 'ambiguous consent input is rejected rather than coerced');
$consent_saved = aimee_privacy_consent_settings(new WP_REST_Request('POST', [
    'special_category_consent' => true,
]));
$assert(!is_wp_error($consent_saved), 'authenticated user can grant optional special-category consent without a privacy acknowledgement');
$assert(
    $GLOBALS['wpdb']->profiles[51]->privacy_acknowledged_at === null,
    'saving optional consent does not invent or require a privacy acknowledgement'
);
$assert(
    $GLOBALS['wpdb']->profiles[51]->special_category_consent_version === aimee_special_category_consent_version(),
    'special-category consent writes the exact current version'
);
$withdrawn = aimee_privacy_consent_settings(new WP_REST_Request('POST', [
    'special_category_consent' => false,
]));
$assert(!is_wp_error($withdrawn), 'authenticated user can withdraw special-category consent');
$assert(
    $GLOBALS['wpdb']->profiles[51]->special_category_consent_at === null
        && $GLOBALS['wpdb']->profiles[51]->special_category_consent_version === null,
    'withdrawal clears timestamp and version immediately'
);
$assert(intval($GLOBALS['wpdb']->profiles[51]->escort_mode) === 0, 'withdrawal revokes the legacy adult specialist toggle');
$assert(!aimee_special_category_consent_is_active($GLOBALS['wpdb']->profiles[51]), 'withdrawal immediately fails every versioned adult-consent gate');
$GLOBALS['aimee_test_current_user_id'] = 0;

if ($failures) {
    echo "Security/privacy regression failures:\n- " . implode("\n- ", $failures) . "\n";
    exit(1);
}

echo "PASS: {$checks} security/privacy runtime checks.\n";
