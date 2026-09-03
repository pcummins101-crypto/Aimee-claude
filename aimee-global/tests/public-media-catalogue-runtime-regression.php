<?php
/**
 * Executable regression for the operator-approved legacy public catalogue.
 *
 * This uses the live 52-entry manifest shape supplied for the 1.8.6 repair,
 * creates same-MIME image fixtures under the exact public root, and evaluates
 * the named production functions without loading WordPress.
 */

$passes = 0;
$failures = 0;

function public_media_assert($condition, $label) {
    global $passes, $failures;
    if ($condition) {
        $passes++;
        echo "PASS {$label}\n";
        return;
    }
    $failures++;
    echo "FAIL {$label}\n";
}

function public_media_same($expected, $actual, $label) {
    public_media_assert(
        $expected === $actual,
        $label . ' (expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . ')'
    );
}

/** Extract a named top-level function without loading the plugin bootstrap. */
function public_media_extract_function($source, $name) {
    $tokens = token_get_all($source);
    $count = count($tokens);
    for ($index = 0; $index < $count; $index++) {
        if (!is_array($tokens[$index]) || $tokens[$index][0] !== T_FUNCTION) continue;
        $cursor = $index + 1;
        while (
            $cursor < $count
            && is_array($tokens[$cursor])
            && $tokens[$cursor][0] === T_WHITESPACE
        ) {
            $cursor++;
        }
        if (
            $cursor >= $count
            || !is_array($tokens[$cursor])
            || $tokens[$cursor][0] !== T_STRING
            || $tokens[$cursor][1] !== $name
        ) {
            continue;
        }

        $output = '';
        $depth = 0;
        $started = false;
        for ($cursor = $index; $cursor < $count; $cursor++) {
            $token = $tokens[$cursor];
            $text = is_array($token) ? $token[1] : $token;
            $output .= $text;
            if ($text === '{') {
                $depth++;
                $started = true;
            } elseif ($text === '}') {
                $depth--;
                if ($started && $depth === 0) return $output;
            }
        }
    }
    throw new RuntimeException('Function not found: ' . $name);
}

function public_media_remove_tree($root) {
    if (!is_dir($root)) return;
    $entries = scandir($root);
    if (!is_array($entries)) return;
    foreach (array_diff($entries, ['.', '..']) as $entry) {
        $path = $root . DIRECTORY_SEPARATOR . $entry;
        if (is_link($path) || is_file($path)) {
            @unlink($path);
        } elseif (is_dir($path)) {
            public_media_remove_tree($path);
        }
    }
    @rmdir($root);
}

$fixture_path = __DIR__ . '/fixtures/public-media-legacy-catalog-52.json';
$fixture_bytes = file_get_contents($fixture_path);
$fixture = is_string($fixture_bytes) ? json_decode($fixture_bytes, true) : null;
if (!is_array($fixture)) {
    echo "Unable to read the 52-entry public catalogue fixture.\n";
    exit(2);
}

$test_root = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR . 'aimee-public-catalogue-audit-'
    . str_replace('.', '-', uniqid('', true));
$content_root = $test_root . DIRECTORY_SEPARATOR . 'wp-content';
$catalogue_root = $content_root . DIRECTORY_SEPARATOR . 'aimee-private-media';
$private_storage_root = $test_root . DIRECTORY_SEPARATOR . 'protected-media';
if (!mkdir($catalogue_root, 0700, true) && !is_dir($catalogue_root)) {
    echo "Unable to create the public catalogue fixture root.\n";
    exit(2);
}
if (!mkdir($private_storage_root, 0700, true) && !is_dir($private_storage_root)) {
    echo "Unable to create the protected media fixture root.\n";
    exit(2);
}
register_shutdown_function('public_media_remove_tree', $test_root);

define('WP_CONTENT_DIR', $content_root);
define('AIMEE_PRIVATE_MEDIA_DIR', $private_storage_root);
define('AIMEE_PUBLIC_MEDIA_CATALOGUE_MODE', 'operator_approved');
if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);

if (!function_exists('sanitize_key')) {
    function sanitize_key($value) {
        return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value));
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value) {
        return trim(strip_tags((string) $value));
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen($value) { return strlen((string) $value); }
}
if (!function_exists('untrailingslashit')) {
    function untrailingslashit($value) {
        return rtrim((string) $value, "/\\");
    }
}
if (!function_exists('wp_normalize_path')) {
    function wp_normalize_path($value) {
        return str_replace('\\', '/', (string) $value);
    }
}
if (!function_exists('aimee_profile_media_path_is_within')) {
    function aimee_profile_media_path_is_within($candidate, $root) {
        $candidate = rtrim(wp_normalize_path((string) $candidate), '/');
        $root = rtrim(wp_normalize_path((string) $root), '/');
        return $root !== ''
            && ($candidate === $root || strpos($candidate, $root . '/') === 0);
    }
}
if (!function_exists('aimee_media_decision_bool')) {
    function aimee_media_decision_bool($value, $default = false) {
        if (is_bool($value)) return $value;
        if (is_int($value) || is_float($value)) return $value !== 0;
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['false', 'no', 'off', ''], true)) return false;
            if (in_array($normalized, ['true', 'yes', 'on', '1'], true)) return true;
        }
        return (bool) $default;
    }
}
if (!function_exists('content_url')) {
    function content_url($relative = '') {
        return 'https://example.test/wp-content/' . ltrim((string) $relative, '/');
    }
}
if (!function_exists('admin_url')) {
    function admin_url($relative = '') {
        return 'https://example.test/wp-admin/' . ltrim((string) $relative, '/');
    }
}
if (!function_exists('add_query_arg')) {
    function add_query_arg($args, $url) {
        return (string) $url . '?' . http_build_query((array) $args);
    }
}
if (!function_exists('current_time')) {
    function current_time($type, $gmt = false) {
        return gmdate('Y-m-d H:i:s');
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($value) { return false; }
}

$GLOBALS['public_media_private_prepare_calls'] = 0;
$GLOBALS['public_media_private_permission_calls'] = 0;
$GLOBALS['public_media_migrate_calls'] = 0;
$GLOBALS['public_media_delete_calls'] = 0;
function aimee_private_storage_prepare_dir($purpose, $configured = '') {
    $GLOBALS['public_media_private_prepare_calls']++;
    return (string) $configured;
}
function aimee_profile_media_permissions_are_private($path, $directory = false) {
    $GLOBALS['public_media_private_permission_calls']++;
    return false;
}
function aimee_private_media_migrate_item($item) {
    $GLOBALS['public_media_migrate_calls']++;
    return false;
}
function wp_delete_file($path) {
    $GLOBALS['public_media_delete_calls']++;
    return false;
}
$GLOBALS['public_media_migration_record'] = [];
$GLOBALS['public_media_update_calls'] = 0;
function aimee_private_media_migration_record() {
    return $GLOBALS['public_media_migration_record'];
}
function aimee_private_media_migration_option_name() {
    return 'aimee_private_media_migration_complete';
}
function aimee_private_media_migration_is_complete($mode = '') {
    $record = $GLOBALS['public_media_migration_record'];
    return !empty($record['completed_at'])
        && ($mode === '' || ($record['mode'] ?? '') === $mode);
}
function update_option($name, $value, $autoload = null) {
    $GLOBALS['public_media_update_calls']++;
    $GLOBALS['public_media_migration_record'] = $value;
    return true;
}
function aimee_private_media_record_health_failure($code) { return false; }
function aimee_private_media_clear_health_failure() { return true; }
function aimee_private_media_delivery_asset($key, $delivery_id = '') {
    $path = aimee_private_media_static_path($key);
    return $path ? ['path' => $path] : null;
}

$engine_source = file_get_contents(dirname(__DIR__) . '/includes/engine.php');
if ($engine_source === false) {
    echo "Unable to read the production engine.\n";
    exit(2);
}

foreach ([
    'aimee_public_media_catalogue_mode_enabled',
    'aimee_public_media_catalogue_dir',
    'aimee_private_media_dir',
    'aimee_private_media_catalog_path',
    'aimee_normalize_private_media_item',
    'aimee_private_media_public_legacy_item',
    'aimee_private_media_catalog',
    'aimee_private_media_builtin_filenames',
    'aimee_private_media_required_keys',
    'aimee_private_media_file_matches_item',
    'aimee_private_media_public_asset_path',
    'aimee_private_media_public_catalogue_status',
    'aimee_private_media_catalog_configuration_ready',
    'aimee_private_media_public_validation_interval',
    'aimee_private_media_public_validation_is_fresh',
    'aimee_seed_private_media_library',
    'aimee_repair_private_media_asset',
    'aimee_private_media_static_path',
    'aimee_private_media_controller_url',
    'aimee_private_media_url',
    'aimee_private_media_payload',
] as $function_name) {
    eval(public_media_extract_function($engine_source, $function_name));
}

$png = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAQAAAAEACAIAAADTED8xAAAB/0lEQVR42u3TQREAMAjAsDFJaEITenmjgURC7xqV/eCqLwEGAAOAAcAAYAAwABgADAAGAAOAAcAAYAAwABgADAAGAAOAAcAAYAAwABgADAAGAAOAAcAAYAAwABgADAAGAAOAAcAAYAAwABgADAAGAAOAAcAAYAAwABgADAAGAAOAATAAGAAMAAYAA4ABwABgADAAGAAMAAYAA4ABwABgADAAGAAMAAYAA4ABwABgADAAGAAMAAYAA4ABwABgADAAGAAMAAYAA4ABwABgADAAGAAMAAYAA4ABwABgADAAGAAMAAbAAGAAMAAYAAwABgADgAHAAGAAMAAYAAwABgADgAHAAGAAMAAYAAwABgADgAHAAGAAMAAYAAwABgADgAHAAGAAMAAYAAwABgADgAHAAGAAMAAYAAwABgADgAHAAGAAMAAGAAOAAcAAYAAwABgADAAGAAOAAcAAYAAwABgADAAGAAOAAcAAYAAwABgADAAGAAOAAcAAYAAwABgADAAGAAOAAcAAYAAwABgADAAGAAOAAcAAYAAwABgADAAGAAOAAcAAGAAMAAYAA4ABwABgADAAGAAMAAYAA4ABwABgADAAGAAMAAYAA4ABwABgADAAGAAMAAYAA4ABwABgADAAGAAMAAYAA4ABwABgADAAGAAMAAYAA4ABwABgADAAbAOMfQMs0XJgzAAAAABJRU5ErkJggg==',
    true
);
$jpeg = base64_decode(
    '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAEAAQADASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFgEBAQEAAAAAAAAAAAAAAAAAAAQG/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAwDAQACEQMRAD8AiwGlXgAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAP/9k=',
    true
);
if (!is_string($png) || !is_string($jpeg)) {
    echo "Unable to decode image fixtures.\n";
    exit(2);
}

file_put_contents($catalogue_root . DIRECTORY_SEPARATOR . 'catalog.json', $fixture_bytes);
foreach ($fixture as $item) {
    $bytes = strtolower((string) ($item['mime'] ?? '')) === 'image/jpeg'
        ? $jpeg
        : $png;
    file_put_contents(
        $catalogue_root . DIRECTORY_SEPARATOR . basename((string) $item['filename']),
        $bytes
    );
}

public_media_same(52, count($fixture), 'live legacy fixture contains all 52 catalogue entries');
public_media_same(
    52,
    count(array_unique(array_keys($fixture))),
    'live legacy fixture contains 52 unique catalogue keys'
);
public_media_same(
    ['explicit' => 4, 'suggestive' => 4],
    array_replace(
        ['explicit' => 0, 'suggestive' => 0],
        array_count_values(array_map(static function ($item) {
            return (string) ($item['content_rating'] ?? '');
        }, array_filter($fixture, static function ($item) {
            return in_array(
                (string) ($item['content_rating'] ?? ''),
                ['explicit', 'suggestive'],
                true
            );
        })))
    ),
    'fixture exercises all eight non-safe legacy records'
);
public_media_same(
    52,
    count(array_filter($fixture, static function ($item) {
        return !array_key_exists('sha256', $item)
            && !array_key_exists('direct_request_allowed', $item)
            && !array_key_exists('allowed_channels', $item);
    })),
    'all live legacy records exercise missing hash and modern policy compatibility'
);
public_media_assert(aimee_public_media_catalogue_mode_enabled(), 'exact operator sentinel activates public mode');
public_media_same(realpath($catalogue_root), aimee_public_media_catalogue_dir(), 'public root resolves to the exact wp-content child');
public_media_same(
    realpath($private_storage_root),
    aimee_private_media_dir(),
    'baseline private resolver remains bound to protected storage in public mode'
);
public_media_same(1, $GLOBALS['public_media_private_prepare_calls'], 'the explicit private resolver prepares protected storage once');
$GLOBALS['public_media_private_prepare_calls'] = 0;
public_media_same(
    realpath($catalogue_root . DIRECTORY_SEPARATOR . 'catalog.json'),
    aimee_private_media_catalog_path(),
    'manifest resolver accepts only the in-root catalog.json'
);

$catalogue = aimee_private_media_catalog();
public_media_same(52, count($catalogue), 'all 52 legacy records normalize through production catalogue code');
public_media_same(
    0,
    count(array_filter($catalogue, static function ($item) {
        return (string) ($item['sha256'] ?? '') !== '';
    })),
    'omitted legacy hashes remain explicit as zero declared hashes'
);
foreach ($catalogue as $key => $item) {
    $rating = (string) ($item['content_rating'] ?? '');
    if (in_array($rating, ['suggestive', 'erotic', 'explicit'], true)) {
        public_media_assert(!empty($item['direct_request_allowed']), "{$key} remains direct-request eligible");
        public_media_assert(empty($item['proactive_allowed']), "{$key} defaults proactive sending off");
        public_media_assert(!empty($item['membership_required']), "{$key} remains membership gated");
    }
    if (in_array($rating, ['erotic', 'explicit'], true)) {
        public_media_same('verified', $item['minimum_adult_assurance'], "{$key} remains verified-adult gated");
    }
}

$explicit_false = $fixture['black_lingerie_mirror_selfie_01'];
$explicit_false['direct_request_allowed'] = false;
$explicit_false['proactive_allowed'] = false;
$explicit_false['allowed_channels'] = ['chat'];
$explicit_false = aimee_normalize_private_media_item(
    'black_lingerie_mirror_selfie_01',
    aimee_private_media_public_legacy_item($explicit_false)
);
public_media_assert(
    is_array($explicit_false)
        && $explicit_false['direct_request_allowed'] === false
        && $explicit_false['proactive_allowed'] === false
        && $explicit_false['allowed_channels'] === ['chat'],
    'explicit false and explicit channel choices override compatibility defaults'
);

$status = aimee_private_media_public_catalogue_status();
public_media_assert(!empty($status['healthy']), 'the complete public catalogue reports healthy');
public_media_assert(!empty($status['operational']), 'the complete public catalogue is operational');
public_media_assert(empty($status['degraded']), 'the complete public catalogue is not degraded');
public_media_same(52, $status['manifest_entries'], 'status reports 52 manifest records');
public_media_same(52, $status['files_ready'], 'status byte-validates all 52 files');
public_media_same(0, $status['hashes_declared'], 'status accurately reports no declared hashes');
public_media_assert(aimee_private_media_catalog_configuration_ready(), 'public readiness does not require absent legacy hashes');
public_media_same(
    realpath($catalogue_root . DIRECTORY_SEPARATOR . $fixture['portrait']['filename']),
    aimee_private_media_static_path('portrait'),
    'selected public bytes are validated independently of a startup record'
);
public_media_same(
    'https://example.test/wp-admin/admin-post.php?action=aimee_private_media&key=portrait',
    aimee_private_media_url('portrait'),
    'canonical media URL uses the authenticated controller before startup'
);
public_media_assert(
    aimee_repair_private_media_asset('portrait', $catalogue['portrait']),
    'public repair live-validates the selected bytes without a startup record'
);
$GLOBALS['public_media_migration_record'] = [];
$GLOBALS['public_media_update_calls'] = 0;
public_media_assert(
    aimee_seed_private_media_library(),
    'first public startup completes authoritative catalogue validation'
);
public_media_same(1, $GLOBALS['public_media_update_calls'], 'first public startup writes one health record');
$first_completed_at = $GLOBALS['public_media_migration_record']['completed_at'] ?? '';
public_media_same(
    realpath($catalogue_root . DIRECTORY_SEPARATOR . $fixture['portrait']['filename']),
    aimee_private_media_static_path('portrait'),
    'static delivery resolves the validated file in place'
);
public_media_same(
    'https://example.test/wp-admin/admin-post.php?action=aimee_private_media&key=portrait',
    aimee_private_media_url('portrait'),
    'operator mode still returns only the authenticated controller URL'
);
public_media_assert(
    strpos(aimee_private_media_url('portrait', 'delivery-123'), '/wp-admin/admin-post.php?') !== false
        && strpos(aimee_private_media_url('portrait', 'delivery-123'), 'delivery_id=delivery-123') !== false
        && strpos(aimee_private_media_url('portrait', 'delivery-123'), '/aimee-private-media/') === false,
    'a delivery-bound item retains the protected controller URL'
);
public_media_same(
    'https://example.test/wp-admin/admin-post.php?action=aimee_private_media&key=portrait',
    aimee_private_media_controller_url('portrait'),
    'controller helper builds the authenticated application transfer URL without a delivery id'
);
$portrait_payload = aimee_private_media_payload('portrait');
public_media_assert(
    is_array($portrait_payload)
        && ($portrait_payload['url'] ?? '')
            === 'https://example.test/wp-admin/admin-post.php?action=aimee_private_media&key=portrait'
        && strpos((string) ($portrait_payload['url'] ?? ''), '/aimee-private-media/') === false,
    'in-app payload never depends on direct access to the public catalogue directory'
);
$bound_payload = aimee_private_media_payload('portrait', 'delivery-123');
public_media_assert(
    is_array($bound_payload)
        && strpos((string) ($bound_payload['url'] ?? ''), 'delivery_id=delivery-123') !== false
        && ($bound_payload['delivery_id'] ?? '') === 'delivery-123',
    'delivery-bound in-app payload retains its controller delivery reference'
);
public_media_assert(
    aimee_repair_private_media_asset('portrait', $catalogue['portrait']),
    'repair in public mode is a read-only in-place validation'
);
public_media_same(0, $GLOBALS['public_media_private_prepare_calls'], 'public mode never prepares private storage');
public_media_same(0, $GLOBALS['public_media_private_permission_calls'], 'public mode never applies owner-only permission checks');
public_media_same(0, $GLOBALS['public_media_migrate_calls'], 'public mode never invokes migration copying');
public_media_same(0, $GLOBALS['public_media_delete_calls'], 'public mode never invokes public-file deletion');

$portrait_path = $catalogue_root . DIRECTORY_SEPARATOR . $fixture['portrait']['filename'];
$catalog_path = $catalogue_root . DIRECTORY_SEPARATOR . 'catalog.json';

$wrong_hash = $fixture;
$wrong_hash['portrait']['sha256'] = str_repeat('0', 64);
file_put_contents($catalog_path, json_encode($wrong_hash));
public_media_assert(
    empty(aimee_private_media_public_catalogue_status(true)['healthy']),
    'a supplied but mismatched 64-hex digest fails closed'
);

$invalid_hash = $fixture;
$invalid_hash['portrait']['sha256'] = 'not-a-sha256';
file_put_contents($catalog_path, json_encode($invalid_hash));
public_media_assert(
    empty(aimee_private_media_public_catalogue_status(true)['healthy']),
    'an invalid declared digest fails normalization closed'
);

$correct_hash = $fixture;
$correct_hash['portrait']['sha256'] = hash_file('sha256', $portrait_path);
file_put_contents($catalog_path, json_encode($correct_hash));
$correct_hash_status = aimee_private_media_public_catalogue_status(true);
public_media_assert(
    !empty($correct_hash_status['healthy'])
        && $correct_hash_status['hashes_declared'] === 1,
    'a correct optional digest remains enforced and counted'
);

$traversal = $fixture;
$traversal['portrait']['filename'] = '../outside.png';
file_put_contents($catalog_path, json_encode($traversal));
public_media_assert(
    empty(aimee_private_media_public_catalogue_status(true)['healthy']),
    'a path-bearing manifest filename fails closed'
);

$wrong_extension = $fixture;
$wrong_extension['portrait']['filename'] = 'aimee-portrait.jpg';
file_put_contents($catalog_path, json_encode($wrong_extension));
public_media_assert(
    empty(aimee_private_media_public_catalogue_status(true)['healthy']),
    'a filename extension that disagrees with the declared MIME fails closed'
);

file_put_contents($catalog_path, $fixture_bytes);
file_put_contents($portrait_path, $jpeg);
public_media_assert(
    empty(aimee_private_media_public_catalogue_status(true)['healthy']),
    'an image whose magic MIME disagrees with the manifest fails closed'
);
file_put_contents($portrait_path, $png);

@unlink($portrait_path);
$missing_file_status = aimee_private_media_public_catalogue_status(true);
public_media_assert(
    empty($missing_file_status['healthy'])
        && !empty($missing_file_status['operational'])
        && !empty($missing_file_status['degraded'])
        && $missing_file_status['files_ready'] === 51,
    'one missing declared file degrades rather than disabling the valid catalogue'
);
public_media_assert(
    aimee_private_media_static_path('portrait') === null
        && is_string(aimee_private_media_static_path('night_out'))
        && aimee_private_media_catalog_configuration_ready(),
    'a missing selected file fails closed while an unrelated valid image remains available'
);
$degraded_payload = aimee_private_media_payload('night_out');
public_media_assert(
    is_array($degraded_payload)
        && strpos((string) ($degraded_payload['url'] ?? ''), '/wp-admin/admin-post.php?') !== false,
    'the degraded catalogue still returns controller payloads for valid images'
);
file_put_contents($portrait_path, $png);

$symlink_tested = false;
$symlink_target = $catalogue_root . DIRECTORY_SEPARATOR . 'symlink-target.png';
file_put_contents($symlink_target, $png);
@unlink($portrait_path);
if (function_exists('symlink') && @symlink($symlink_target, $portrait_path)) {
    $symlink_tested = true;
    public_media_assert(
        empty(aimee_private_media_public_catalogue_status(true)['healthy']),
        'a symlinked catalogue image fails closed'
    );
    @unlink($portrait_path);
}
if (!$symlink_tested) {
    public_media_assert(
        strpos(public_media_extract_function($engine_source, 'aimee_private_media_public_asset_path'), 'is_link($candidate)') !== false,
        'symlink rejection remains explicit when the runtime cannot create links'
    );
}
file_put_contents($portrait_path, $png);
@unlink($symlink_target);

$missing_required = $fixture;
unset($missing_required['portrait']);
file_put_contents($catalog_path, json_encode($missing_required));
$missing_required_catalogue = aimee_private_media_catalog();
public_media_assert(
    count($missing_required_catalogue) === 51
        && !isset($missing_required_catalogue['portrait'])
        && empty(aimee_private_media_public_catalogue_status(true)['healthy'])
        && !empty(aimee_private_media_public_catalogue_status(true)['operational'])
        && is_string(aimee_private_media_static_path('night_out')),
    'a missing required manifest key is skipped without restoring fallback data or hiding valid images'
);

file_put_contents($catalog_path, '{invalid json');
public_media_assert(
    aimee_private_media_catalog() === []
        && empty(aimee_private_media_public_catalogue_status(true)['healthy']),
    'invalid public JSON yields no usable fallback catalogue'
);

@unlink($catalog_path);
public_media_assert(
    aimee_private_media_catalog() === []
        && aimee_private_media_static_path('portrait') === null
        && aimee_private_media_url('portrait')
            === 'https://example.test/wp-admin/admin-post.php?action=aimee_private_media&key=portrait'
        && aimee_private_media_payload('portrait') === null,
    'missing public manifest yields no catalogue, static asset or application payload while its inert route remains canonical'
);
file_put_contents($catalog_path, $fixture_bytes);

$fresh_record = [
    'mode' => 'operator_approved_public_catalogue',
    'completed_at' => gmdate('Y-m-d H:i:s'),
    'last_validated_at' => gmdate('Y-m-d H:i:s'),
    'catalog_path' => wp_normalize_path(realpath($catalog_path)),
    'catalog_sha256' => hash_file('sha256', $catalog_path),
];
public_media_assert(
    aimee_private_media_public_validation_is_fresh($fresh_record),
    'an exact recent manifest validation record is reusable'
);
$stale_record = $fresh_record;
$stale_record['last_validated_at'] = gmdate(
    'Y-m-d H:i:s',
    time() - aimee_private_media_public_validation_interval() - 1
);
public_media_assert(
    !aimee_private_media_public_validation_is_fresh($stale_record),
    'a validation record older than the bounded interval is stale'
);
$future_record = $fresh_record;
$future_record['last_validated_at'] = gmdate('Y-m-d H:i:s', time() + 301);
public_media_assert(
    !aimee_private_media_public_validation_is_fresh($future_record),
    'an implausibly future-dated validation record is rejected'
);
$wrong_path_record = $fresh_record;
$wrong_path_record['catalog_path'] .= '.other';
public_media_assert(
    !aimee_private_media_public_validation_is_fresh($wrong_path_record),
    'a validation record for another manifest path is rejected'
);
$wrong_digest_record = $fresh_record;
$wrong_digest_record['catalog_sha256'] = str_repeat('0', 64);
public_media_assert(
    !aimee_private_media_public_validation_is_fresh($wrong_digest_record),
    'a validation record for different manifest bytes is rejected'
);

public_media_assert(
    aimee_seed_private_media_library(),
    'fresh public startup reuses the exact validation record'
);
public_media_same(1, $GLOBALS['public_media_update_calls'], 'fresh startup performs no option rewrite');
$GLOBALS['public_media_migration_record']['last_validated_at'] = gmdate(
    'Y-m-d H:i:s',
    time() - aimee_private_media_public_validation_interval() - 1
);
public_media_assert(
    aimee_seed_private_media_library(),
    'stale public startup performs a fresh authoritative scan'
);
public_media_same(2, $GLOBALS['public_media_update_calls'], 'stale startup writes exactly one refreshed record');
public_media_same(
    $first_completed_at,
    $GLOBALS['public_media_migration_record']['completed_at'] ?? '',
    'refresh preserves the original public-catalogue completion timestamp'
);

public_media_same(0, $GLOBALS['public_media_private_prepare_calls'], 'negative cases still never prepare private storage');
public_media_same(0, $GLOBALS['public_media_private_permission_calls'], 'negative cases still never request private permissions');
public_media_same(0, $GLOBALS['public_media_migrate_calls'], 'negative cases still never migrate media');
public_media_same(0, $GLOBALS['public_media_delete_calls'], 'negative cases still never delete public media');

echo "\nPUBLIC MEDIA RUNTIME RESULT: {$passes} checks passed, {$failures} failed\n";
exit($failures ? 1 : 0);
