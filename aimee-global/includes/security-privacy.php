<?php
/**
 * Security and privacy controls introduced in Aimee Global 1.8.3.
 *
 * This module is intentionally loaded before the theme-owned legacy UI. It
 * provides authentication throttling, consent-version truth, private profile
 * media and self-enforcing gallery helpers without changing legacy accounts.
 *
 * Compatible with PHP 7.4.
 */

defined('ABSPATH') || exit;

function aimee_special_category_consent_version() {
    $version = defined('AIMEE_SPECIAL_CATEGORY_CONSENT_VERSION')
        ? sanitize_text_field((string) AIMEE_SPECIAL_CATEGORY_CONSENT_VERSION)
        : '2026-08-20.1';

    return preg_match('/^[A-Za-z0-9._-]{1,64}$/', $version) === 1
        ? $version
        : '2026-08-20.1';
}

function aimee_security_valid_stored_timestamp($value) {
    $value = trim((string) $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $value) !== 1) {
        return false;
    }
    return checkdate(intval(substr($value, 5, 2)), intval(substr($value, 8, 2)), intval(substr($value, 0, 4)))
        && intval(substr($value, 11, 2)) <= 23
        && intval(substr($value, 14, 2)) <= 59
        && intval(substr($value, 17, 2)) <= 59;
}

function aimee_special_category_consent_is_active($profile) {
    if (!is_object($profile) && !is_array($profile)) return false;

    $timestamp = is_object($profile)
        ? (string) ($profile->special_category_consent_at ?? '')
        : (string) ($profile['special_category_consent_at'] ?? '');
    $version = is_object($profile)
        ? (string) ($profile->special_category_consent_version ?? '')
        : (string) ($profile['special_category_consent_version'] ?? '');

    return aimee_security_valid_stored_timestamp($timestamp)
        && $version !== ''
        && hash_equals(aimee_special_category_consent_version(), $version);
}

function aimee_privacy_acknowledgement_is_active($profile) {
    if (!is_object($profile) && !is_array($profile)) return false;
    $timestamp = is_object($profile)
        ? (string) ($profile->privacy_acknowledged_at ?? '')
        : (string) ($profile['privacy_acknowledged_at'] ?? '');
    return aimee_security_valid_stored_timestamp($timestamp);
}

function aimee_security_boolean_is_true($value) {
    return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true);
}

function aimee_security_parse_boolean_input($value, &$valid) {
    if (in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true)) {
        $valid = true;
        return true;
    }
    if (in_array($value, [false, 0, '0', 'false', 'no', 'off', ''], true)) {
        $valid = true;
        return false;
    }
    $valid = false;
    return false;
}

function aimee_privacy_consent_route_permission() {
    return get_current_user_id() > 0;
}

function aimee_privacy_consent_profile($user_id) {
    global $wpdb;
    if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_row')) return null;
    $table = function_exists('aimee_table') ? aimee_table('aimee_user_profiles') : 'aimee_user_profiles';
    return $wpdb->get_row($wpdb->prepare(
        "SELECT user_id, privacy_acknowledged_at, special_category_consent_at,
                special_category_consent_version, account_deletion_started_at
         FROM `{$table}` WHERE user_id = %d LIMIT 1",
        intval($user_id)
    ));
}

/** Authenticated read/update endpoint for explicit, revocable privacy choices. */
function aimee_privacy_consent_settings(WP_REST_Request $request) {
    global $wpdb;
    $user_id = get_current_user_id();
    if ($user_id < 1) {
        return new WP_Error('consent_authentication_required', 'Authentication required.', ['status' => 401]);
    }
    if (
        !function_exists('aimee_global_core_schema_health')
        || !aimee_global_core_schema_health(true)
    ) {
        return new WP_Error('consent_settings_unavailable', 'Privacy settings are temporarily unavailable.', ['status' => 503]);
    }
    $profile = aimee_privacy_consent_profile($user_id);
    if (!$profile) {
        return new WP_Error('consent_profile_unavailable', 'Privacy settings are temporarily unavailable.', ['status' => 503]);
    }
    if (trim((string) ($profile->account_deletion_started_at ?? '')) !== '') {
        return new WP_Error('account_deletion_pending', 'Privacy settings cannot change during account deletion.', ['status' => 409]);
    }

    if (strtoupper((string) $request->get_method()) === 'GET') {
        return rest_ensure_response([
            'privacy_acknowledged' => aimee_privacy_acknowledgement_is_active($profile),
            'special_category_consent' => aimee_special_category_consent_is_active($profile),
            'special_category_consent_version' => aimee_special_category_consent_version(),
        ]);
    }

    $params = $request->get_json_params();
    if (!is_array($params)) $params = [];
    if (!array_key_exists('privacy_acknowledged', $params) && !array_key_exists('special_category_consent', $params)) {
        return new WP_Error('consent_choice_required', 'Choose the privacy settings you want to save.', ['status' => 400]);
    }

    $update = [];
    $formats = [];
    $now = current_time('mysql', true);
    if (array_key_exists('privacy_acknowledged', $params)) {
        $valid = false;
        $acknowledged = aimee_security_parse_boolean_input($params['privacy_acknowledged'], $valid);
        if (!$valid) {
            return new WP_Error('consent_choice_invalid', 'The privacy setting was invalid.', ['status' => 400]);
        }
        // An acknowledgement records that a notice was read; it is not erased
        // by an unchecked client control. Existing NULL users must explicitly
        // tick and submit before a timestamp is ever written.
        if ($acknowledged && !aimee_privacy_acknowledgement_is_active($profile)) {
            $update['privacy_acknowledged_at'] = $now;
            $formats[] = '%s';
        }
    }

    if (array_key_exists('special_category_consent', $params)) {
        $valid = false;
        $consented = aimee_security_parse_boolean_input($params['special_category_consent'], $valid);
        if (!$valid) {
            return new WP_Error('consent_choice_invalid', 'The sensitive-information setting was invalid.', ['status' => 400]);
        }
        if ($consented) {
            if (!aimee_special_category_consent_is_active($profile)) {
                $update['special_category_consent_at'] = $now;
                $formats[] = '%s';
                $update['special_category_consent_version'] = aimee_special_category_consent_version();
                $formats[] = '%s';
            }
        } else {
            // Withdrawal is immediate and revokes the legacy specialist toggle
            // as well as clearing the timestamp/version used by every adult gate.
            $update['special_category_consent_at'] = null;
            $formats[] = '%s';
            $update['special_category_consent_version'] = null;
            $formats[] = '%s';
            $update['escort_mode'] = 0;
            $formats[] = '%d';
        }
    }

    if ($update) {
        $table = function_exists('aimee_table') ? aimee_table('aimee_user_profiles') : 'aimee_user_profiles';
        $updated = $wpdb->update(
            $table,
            $update,
            ['user_id' => $user_id, 'account_deletion_started_at' => null],
            $formats,
            ['%d', null]
        );
        if ($updated === false) {
            return new WP_Error('consent_update_failed', 'Privacy settings could not be saved.', ['status' => 503]);
        }
    }
    $verified = aimee_privacy_consent_profile($user_id);
    if (!$verified) {
        return new WP_Error('consent_verify_failed', 'Privacy settings could not be verified.', ['status' => 503]);
    }
    if (trim((string) ($verified->account_deletion_started_at ?? '')) !== '') {
        return new WP_Error('account_deletion_pending', 'Privacy settings were not changed during account deletion.', ['status' => 409]);
    }
    if (array_key_exists('privacy_acknowledged', $params) && aimee_security_boolean_is_true($params['privacy_acknowledged'])) {
        if (!aimee_privacy_acknowledgement_is_active($verified)) {
            return new WP_Error('consent_verify_failed', 'Privacy settings could not be verified.', ['status' => 503]);
        }
    }
    if (array_key_exists('special_category_consent', $params)) {
        $expected = aimee_security_boolean_is_true($params['special_category_consent']);
        if (aimee_special_category_consent_is_active($verified) !== $expected) {
            return new WP_Error('consent_verify_failed', 'Privacy settings could not be verified.', ['status' => 503]);
        }
        do_action('aimee_special_category_consent_changed', $user_id, $expected, $verified);
    }
    return rest_ensure_response([
        'status' => 'saved',
        'privacy_acknowledged' => aimee_privacy_acknowledgement_is_active($verified),
        'special_category_consent' => aimee_special_category_consent_is_active($verified),
        'special_category_consent_version' => aimee_special_category_consent_version(),
    ]);
}

function aimee_register_privacy_consent_route() {
    register_rest_route('aimee/v1', '/privacy-consent', [
        [
            'methods' => 'GET',
            'callback' => 'aimee_privacy_consent_settings',
            'permission_callback' => 'aimee_privacy_consent_route_permission',
        ],
        [
            'methods' => 'POST',
            'callback' => 'aimee_privacy_consent_settings',
            'permission_callback' => 'aimee_privacy_consent_route_permission',
        ],
    ]);
}
add_action('rest_api_init', 'aimee_register_privacy_consent_route');

function aimee_security_character_length($value) {
    if (!is_string($value)) return 0;
    if (function_exists('mb_strlen')) return mb_strlen($value, 'UTF-8');
    $count = preg_match_all('/./us', $value, $characters);
    // REST JSON is valid UTF-8, but retain a deterministic byte fallback for
    // legacy direct callers without ever normalising the opaque password.
    return $count === false ? strlen($value) : intval($count);
}

/**
 * Authentication throttling
 *
 * Existing six-digit PIN accounts remain valid. The throttle applies before
 * WordPress checks either a legacy PIN or a new passphrase and groups known
 * phone aliases under the same immutable user ID.
 */
function aimee_auth_security_generic_message() {
    return __('Those details did not match an account. Please wait and try again.', 'aimee-global');
}

function aimee_auth_security_remote_ip() {
    $ip = isset($_SERVER['REMOTE_ADDR'])
        ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
        : 'unknown';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
}

function aimee_auth_security_local_aliases($username) {
    $username = trim((string) $username);
    $digits = preg_replace('/\D+/', '', $username);
    if (!is_string($digits) || $digits === '') return [$username];

    if (strpos($digits, '0044') === 0) $digits = substr($digits, 2);
    $aliases = [$username, $digits, '+' . $digits];
    if (strpos($digits, '44') === 0 && strlen($digits) === 12) {
        $aliases[] = '0' . substr($digits, 2);
        $aliases[] = '+44' . substr($digits, 2);
    } elseif (strpos($digits, '0') === 0 && strlen($digits) === 11) {
        $aliases[] = '44' . substr($digits, 1);
        $aliases[] = '+44' . substr($digits, 1);
        $digits = '44' . substr($digits, 1);
    } elseif (strlen($digits) === 10) {
        $aliases[] = '1' . $digits;
        $aliases[] = '+1' . $digits;
        $digits = '1' . $digits;
    }

    return array_values(array_unique(array_filter($aliases)));
}

function aimee_auth_security_normalized_identifier($username) {
    $username = trim((string) $username);
    $digits = preg_replace('/\D+/', '', $username);
    if (is_string($digits) && strpos($digits, '0044') === 0) {
        $digits = substr($digits, 2);
    }
    if (is_string($digits) && strpos($digits, '0') === 0 && strlen($digits) === 11) {
        return '+44' . substr($digits, 1);
    }
    if (is_string($digits) && strpos($digits, '44') === 0 && strlen($digits) === 12) {
        return '+' . $digits;
    }
    if (is_string($digits) && strlen($digits) === 10) {
        return '+1' . $digits;
    }
    if (is_string($digits) && strpos($digits, '1') === 0 && strlen($digits) === 11) {
        return '+' . $digits;
    }
    return function_exists('mb_strtolower')
        ? mb_strtolower($username, 'UTF-8')
        : strtolower($username);
}

function aimee_auth_security_identity_key($username) {
    $username = trim((string) $username);
    $normalized = aimee_auth_security_normalized_identifier($username);

    $candidates = aimee_auth_security_local_aliases($username);
    if (function_exists('aimee_mobile_login_candidates')) {
        $candidates = array_merge(
            $candidates,
            (array) aimee_mobile_login_candidates($username, null)
        );
    }

    foreach (array_values(array_unique(array_filter($candidates))) as $candidate) {
        $user = get_user_by('login', (string) $candidate);
        if (!$user && is_email($candidate)) {
            $user = get_user_by('email', sanitize_email($candidate));
        }
        if ($user instanceof WP_User) {
            return 'user_' . intval($user->ID);
        }
    }

    return 'login_' . hash('sha256', $normalized);
}

function aimee_auth_security_bucket_keys($username) {
    return [
        'aimee_auth_' . aimee_auth_security_identity_key($username),
        'aimee_auth_ip_' . hash('sha256', aimee_auth_security_remote_ip()),
    ];
}

function aimee_auth_security_limits() {
    $limits = apply_filters('aimee_auth_security_limits', [
        'failures'       => 8,
        'window_seconds' => 15 * MINUTE_IN_SECONDS,
        'lock_seconds'   => 30 * MINUTE_IN_SECONDS,
    ]);

    return [
        'failures'       => max(3, intval($limits['failures'] ?? 8)),
        'window_seconds' => max(MINUTE_IN_SECONDS, intval($limits['window_seconds'] ?? 15 * MINUTE_IN_SECONDS)),
        'lock_seconds'   => max(MINUTE_IN_SECONDS, intval($limits['lock_seconds'] ?? 30 * MINUTE_IN_SECONDS)),
    ];
}

function aimee_auth_security_state($key) {
    $state = get_transient($key);
    return is_array($state) ? $state : [
        'failures' => 0,
        'started_at' => time(),
        'locked_until' => 0,
    ];
}

function aimee_auth_security_is_locked($username) {
    $now = time();
    foreach (aimee_auth_security_bucket_keys($username) as $key) {
        $state = aimee_auth_security_state($key);
        if (intval($state['locked_until'] ?? 0) > $now) return true;
    }
    return false;
}

function aimee_auth_security_precheck($user, $username, $password) {
    if ($username === '' || $password === '') return $user;
    if (!aimee_auth_security_is_locked($username)) return $user;
    if (apply_filters('aimee_auth_security_bypass_lock', false, $user, $username)) {
        return $user;
    }

    return new WP_Error('authentication_failed', aimee_auth_security_generic_message());
}
add_filter('authenticate', 'aimee_auth_security_precheck', 2, 3);
// Core username/email filters and the legacy phone-alias filter can replace an
// earlier WP_Error with a WP_User. Reapply the identical lock decision last so
// no later authenticator can revive a locked request.
add_filter('authenticate', 'aimee_auth_security_precheck', PHP_INT_MAX, 3);

function aimee_auth_security_record_failure($username, $error = null) {
    // Count each completed WordPress authentication failure. The bundled UI
    // performs exactly one wp_signon call, while XML-RPC or other batch callers
    // must not gain extra guesses merely by sharing one HTTP request.
    $limits = aimee_auth_security_limits();
    $now = time();
    foreach (aimee_auth_security_bucket_keys($username) as $key) {
        $state = aimee_auth_security_state($key);
        if (($now - intval($state['started_at'] ?? 0)) >= $limits['window_seconds']) {
            $state = ['failures' => 0, 'started_at' => $now, 'locked_until' => 0];
        }
        $state['failures'] = intval($state['failures'] ?? 0) + 1;
        if ($state['failures'] >= $limits['failures']) {
            $state['locked_until'] = $now + $limits['lock_seconds'];
        }
        set_transient(
            $key,
            $state,
            max($limits['window_seconds'], $limits['lock_seconds'])
        );
    }
}
add_action('wp_login_failed', 'aimee_auth_security_record_failure', 10, 2);

function aimee_auth_security_clear_success($username, $user) {
    // A successful login proves only this identity. Never clear the shared IP
    // bucket: an attacker must not be able to reset it using their own account.
    delete_transient(
        'aimee_auth_' . aimee_auth_security_identity_key($username)
    );
    if ($user instanceof WP_User) {
        delete_transient('aimee_auth_user_' . intval($user->ID));
    }
}
add_action('wp_login', 'aimee_auth_security_clear_success', 10, 2);

function aimee_auth_security_login_error_text($message) {
    return esc_html(aimee_auth_security_generic_message());
}
add_filter('login_errors', 'aimee_auth_security_login_error_text', PHP_INT_MAX);

function aimee_auth_security_collapse_wp_errors($errors, $redirect_to = '') {
    if (!is_wp_error($errors)) return $errors;
    $auth_codes = [
        'invalid_username',
        'invalid_email',
        'incorrect_password',
        'authentication_failed',
        'empty_username',
        'empty_password',
    ];
    if (!array_intersect($auth_codes, $errors->get_error_codes())) return $errors;
    return new WP_Error('authentication_failed', aimee_auth_security_generic_message());
}
add_filter('wp_login_errors', 'aimee_auth_security_collapse_wp_errors', PHP_INT_MAX, 2);

/** Private, site-scoped storage shared by media subsystems. */
function aimee_private_storage_default_dir($purpose) {
    $purpose = sanitize_key((string) $purpose);
    if ($purpose === '') return '';
    $document_root = isset($_SERVER['DOCUMENT_ROOT'])
        ? realpath((string) $_SERVER['DOCUMENT_ROOT'])
        : false;
    // Without a resolved server document root there is no reliable way to
    // prove a derived sibling is non-public. Operators in that environment
    // must provide an explicit subsystem directory to the prepare helper.
    if (!$document_root) return '';
    $private_parent = dirname($document_root);
    $site_suffix = substr(hash('sha256', wp_normalize_path(ABSPATH)), 0, 12);
    return untrailingslashit(
        $private_parent . DIRECTORY_SEPARATOR
        . 'aimee-private-' . $purpose . '-' . $site_suffix
    );
}

function aimee_profile_media_path_is_within($path, $root) {
    $path = wp_normalize_path((string) $path);
    $root = wp_normalize_path((string) $root);
    if (DIRECTORY_SEPARATOR === '\\') {
        $path = strtolower($path);
        $root = strtolower($root);
    }
    if ($root === '/') return strpos($path, '/') === 0;
    $path = untrailingslashit($path);
    $root = untrailingslashit($root);
    if ($path === '' || $root === '') return false;
    return $path === $root || strpos($path . '/', $root . '/') === 0;
}

function aimee_profile_media_permissions_are_private($path, $directory = false) {
    // PHP's POSIX mode bits do not model Windows ACLs. On Windows the private
    // directory must be ACL-restricted by the operator; it still remains
    // subject to every outside-document-root check in this module.
    if (DIRECTORY_SEPARATOR === '\\') return true;
    $permissions = @fileperms($path);
    if ($permissions === false) return false;
    $mode = $permissions & 0777;
    if (($mode & 0077) !== 0) return false;
    return $directory
        ? ($mode & 0700) === 0700
        : ($mode & 0600) === 0600;
}

/**
 * Return an absolute, resolved 0700 directory outside all known public roots.
 *
 * `$configured_dir` may be an operator-provided absolute path. When it is
 * empty, a site-scoped sibling of the resolved DOCUMENT_ROOT is derived. A
 * safe directory path is returned on success; WP_Error is returned otherwise.
 */
function aimee_private_storage_prepare_dir($purpose, $configured_dir = '') {
    $purpose = sanitize_key((string) $purpose);
    if ($purpose === '') {
        return new WP_Error('private_storage_purpose_invalid', 'Private storage purpose was invalid.');
    }
    $dir = trim((string) $configured_dir);
    if ($dir === '') $dir = aimee_private_storage_default_dir($purpose);
    $uploads = wp_upload_dir(null, false);
    $upload_root = !empty($uploads['basedir'])
        ? wp_normalize_path((string) $uploads['basedir'])
        : '';
    $resolved_upload_root = $upload_root !== ''
        ? (realpath($upload_root) ?: $upload_root)
        : '';
    $wordpress_root = realpath(ABSPATH) ?: ABSPATH;
    $raw_normalized = wp_normalize_path($dir);
    $normalized = untrailingslashit($raw_normalized);
    $document_root = isset($_SERVER['DOCUMENT_ROOT'])
        ? (realpath((string) $_SERVER['DOCUMENT_ROOT']) ?: (string) $_SERVER['DOCUMENT_ROOT'])
        : '';
    if (
        $raw_normalized === '/'
        || preg_match('~^[A-Za-z]:/?$~', $raw_normalized) === 1
        || !preg_match('~^(?:[A-Za-z]:/|/)~', $normalized)
        || aimee_profile_media_path_is_within($normalized, ABSPATH)
        || ($upload_root !== '' && aimee_profile_media_path_is_within($normalized, $upload_root))
        || ($document_root !== '' && aimee_profile_media_path_is_within($normalized, $document_root))
    ) {
        return new WP_Error('private_storage_public_path', 'Private storage must be outside every public document root.');
    }
    if (is_link($dir)) {
        return new WP_Error('private_storage_symlink_rejected', 'Private storage cannot be a symbolic-link path.');
    }
    $directory_existed = is_dir($dir);
    if (!$directory_existed && !wp_mkdir_p($dir)) {
        return new WP_Error('private_storage_directory_failed', 'Private storage is unavailable.');
    }

    $resolved = realpath($dir);
    if (
        !$resolved
        || aimee_profile_media_path_is_within($resolved, $wordpress_root)
        || ($resolved_upload_root !== '' && aimee_profile_media_path_is_within($resolved, $resolved_upload_root))
        || ($document_root !== '' && aimee_profile_media_path_is_within($resolved, $document_root))
    ) {
        return new WP_Error('private_storage_public_path', 'Private storage resolved inside a public document root.');
    }
    $dir = $resolved;
    if (DIRECTORY_SEPARATOR !== '\\') {
        // Never chmod an operator-selected pre-existing path: a typo such as
        // `/srv` must not let a web process mutate a broad system directory.
        // Only the exact directory created by this call may be tightened.
        if (!$directory_existed && !@chmod($dir, 0700)) {
            return new WP_Error('private_storage_permissions_failed', 'Private storage directory permissions could not be enforced.');
        }
        if (!aimee_profile_media_permissions_are_private($dir, true)) {
            return new WP_Error('private_storage_permissions_failed', 'Private storage must already be owner-only (0700).');
        }
    }
    return $dir;
}

/** Private onboarding profile media. */
function aimee_profile_media_dir() {
    if (defined('AIMEE_PROFILE_MEDIA_DIR') && trim((string) AIMEE_PROFILE_MEDIA_DIR) !== '') {
        return untrailingslashit((string) AIMEE_PROFILE_MEDIA_DIR);
    }
    return aimee_private_storage_default_dir('profile-media');
}

function aimee_profile_media_prepare_dir() {
    $configured = defined('AIMEE_PROFILE_MEDIA_DIR')
        ? trim((string) AIMEE_PROFILE_MEDIA_DIR)
        : '';
    $dir = aimee_private_storage_prepare_dir('profile-media', $configured);
    if (is_wp_error($dir)) return $dir;
    $guards = [
        'index.php' => "<?php\nhttp_response_code(404);\nexit;\n",
        '.htaccess' => "Require all denied\nDeny from all\n",
        'web.config' => "<?xml version=\"1.0\"?><configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></security></system.webServer></configuration>",
    ];
    foreach ($guards as $name => $contents) {
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        if (!file_exists($path)) @file_put_contents($path, $contents, LOCK_EX);
    }
    return $dir;
}

function aimee_profile_media_limits() {
    $limits = apply_filters('aimee_profile_media_limits', [
        'bytes' => 8 * 1024 * 1024,
        'dimension' => 6000,
        'pixels' => 24000000,
    ]);
    return [
        'bytes' => max(1024, intval($limits['bytes'] ?? 8 * 1024 * 1024)),
        'dimension' => max(256, intval($limits['dimension'] ?? 6000)),
        'pixels' => max(65536, intval($limits['pixels'] ?? 24000000)),
    ];
}

function aimee_profile_media_validate_bytes($bytes) {
    if (!is_string($bytes) || $bytes === '') {
        return new WP_Error('profile_image_invalid', 'The selected profile image was not valid.');
    }
    $limits = aimee_profile_media_limits();
    if (strlen($bytes) > $limits['bytes']) {
        return new WP_Error('profile_image_too_large', 'The selected profile image was too large.');
    }
    if (!class_exists('finfo') || !function_exists('getimagesizefromstring')) {
        return new WP_Error('profile_image_validation_unavailable', 'Profile image validation is unavailable.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = strtolower((string) $finfo->buffer($bytes));
    $size = @getimagesizefromstring($bytes);
    $size_mime = is_array($size) ? strtolower((string) ($size['mime'] ?? '')) : '';
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime]) || $size_mime !== $mime) {
        return new WP_Error('profile_image_type_mismatch', 'The selected profile image type was not valid.');
    }

    $width = intval($size[0] ?? 0);
    $height = intval($size[1] ?? 0);
    if (
        $width < 1
        || $height < 1
        || $width > $limits['dimension']
        || $height > $limits['dimension']
        || ($width * $height) > $limits['pixels']
    ) {
        return new WP_Error('profile_image_dimensions_invalid', 'The selected profile image dimensions were not accepted.');
    }

    return [
        'mime' => $mime,
        'extension' => $allowed[$mime],
        'width' => $width,
        'height' => $height,
        'bytes' => strlen($bytes),
    ];
}

function aimee_profile_media_basename($user_id) {
    return 'profile-user-' . intval($user_id);
}

function aimee_profile_media_url() {
    return add_query_arg('action', 'aimee_profile_photo', admin_url('admin-post.php'));
}

function aimee_profile_media_url_is_protected($url) {
    $url = (string) $url;
    $protected = (string) aimee_profile_media_url();
    return $url !== '' && hash_equals($protected, $url);
}

/** Read and validate the exact bytes of a private or legacy image file. */
function aimee_profile_media_read_validated_file($path) {
    $path = (string) $path;
    $limits = aimee_profile_media_limits();
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return new WP_Error('profile_image_file_missing', 'The profile image file was unavailable.');
    }
    clearstatcache(true, $path);
    $size = @filesize($path);
    if ($size === false || $size < 1 || $size > $limits['bytes']) {
        return new WP_Error('profile_image_file_size_invalid', 'The profile image file size was invalid.');
    }
    $bytes = @file_get_contents($path);
    if (!is_string($bytes) || strlen($bytes) !== intval($size)) {
        return new WP_Error('profile_image_file_read_failed', 'The profile image file could not be read safely.');
    }
    $facts = aimee_profile_media_validate_bytes($bytes);
    if (is_wp_error($facts)) return $facts;
    return ['bytes' => $bytes, 'facts' => $facts];
}

function aimee_profile_media_url_effective_port(array $parts) {
    if (isset($parts['port'])) return intval($parts['port']);
    return strtolower((string) ($parts['scheme'] ?? '')) === 'https' ? 443 : 80;
}

/**
 * Strictly map a 1.8.2 owner-bound upload URL to a local upload file.
 *
 * Returns an array containing `path`, `url` and `extension`, or WP_Error. It
 * accepts only the current uploads host/path (including legacy HTTP after an
 * HTTPS upgrade) and the exact historical
 * `aimee_user_{owner}_{timestamp}.{extension}` basename. Query strings,
 * fragments, credentials, encoded path components and symlink escapes fail
 * closed.
 */
function aimee_profile_media_legacy_candidate($user_id, $url) {
    $user_id = intval($user_id);
    $url = trim((string) $url);
    if ($user_id < 1 || $url === '') {
        return new WP_Error('legacy_profile_media_unrecognized', 'Legacy profile media was not recognized.');
    }

    $uploads = wp_upload_dir(null, false);
    $base_url = is_array($uploads) ? trim((string) ($uploads['baseurl'] ?? '')) : '';
    $base_dir = is_array($uploads) ? trim((string) ($uploads['basedir'] ?? '')) : '';
    $target = @parse_url($url);
    $base = @parse_url($base_url);
    $home = @parse_url(home_url('/'));
    if (!is_array($target) || !is_array($base) || !is_array($home) || $base_dir === '') {
        return new WP_Error('legacy_profile_media_unrecognized', 'Legacy profile media was not recognized.');
    }
    foreach (['user', 'pass', 'query', 'fragment'] as $forbidden) {
        if (array_key_exists($forbidden, $target)) {
            return new WP_Error('legacy_profile_media_unrecognized', 'Legacy profile media was not recognized.');
        }
    }

    $target_scheme = strtolower((string) ($target['scheme'] ?? ''));
    $base_scheme = strtolower((string) ($base['scheme'] ?? ''));
    $target_host = strtolower(rtrim((string) ($target['host'] ?? ''), '.'));
    $base_host = strtolower(rtrim((string) ($base['host'] ?? ''), '.'));
    $home_host = strtolower(rtrim((string) ($home['host'] ?? ''), '.'));
    if (
        !in_array($target_scheme, ['http', 'https'], true)
        || !in_array($base_scheme, ['http', 'https'], true)
        || $target_host === ''
        || $target_host !== $base_host
        || $target_host !== $home_host
        || (
            isset($target['port'])
            && aimee_profile_media_url_effective_port($target) !== aimee_profile_media_url_effective_port($base)
        )
    ) {
        return new WP_Error('legacy_profile_media_unrecognized', 'Legacy profile media was not recognized.');
    }

    $target_path = (string) ($target['path'] ?? '');
    $base_path = rtrim((string) ($base['path'] ?? ''), '/');
    if ($target_path === '' || rawurldecode($target_path) !== $target_path) {
        return new WP_Error('legacy_profile_media_unrecognized', 'Legacy profile media was not recognized.');
    }
    $prefix = $base_path . '/';
    if (strpos($target_path, $prefix) !== 0) {
        return new WP_Error('legacy_profile_media_unrecognized', 'Legacy profile media was not recognized.');
    }
    $relative = substr($target_path, strlen($prefix));
    $segments = explode('/', $relative);
    if (!$segments) {
        return new WP_Error('legacy_profile_media_unrecognized', 'Legacy profile media was not recognized.');
    }
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..' || !preg_match('/^[A-Za-z0-9._-]+$/', $segment)) {
            return new WP_Error('legacy_profile_media_unrecognized', 'Legacy profile media was not recognized.');
        }
    }
    $basename = end($segments);
    $matches = [];
    if (!preg_match(
        '/^aimee_user_' . preg_quote((string) $user_id, '/') . '_[0-9]{9,12}\.(jpg|png|gif|webp)$/',
        (string) $basename,
        $matches
    )) {
        return new WP_Error('legacy_profile_media_unrecognized', 'Legacy profile media was not recognized.');
    }

    $resolved_base = realpath($base_dir);
    if (!$resolved_base || !is_dir($resolved_base)) {
        return new WP_Error('legacy_profile_media_unavailable', 'Legacy profile media storage was unavailable.');
    }
    $candidate_dir = realpath($resolved_base . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, array_slice($segments, 0, -1)));
    if (!$candidate_dir || !aimee_profile_media_path_is_within($candidate_dir, $resolved_base)) {
        return new WP_Error('legacy_profile_media_unrecognized', 'Legacy profile media was not recognized.');
    }
    $candidate = $candidate_dir . DIRECTORY_SEPARATOR . $basename;
    if (is_file($candidate) || is_link($candidate)) {
        $resolved_candidate = realpath($candidate);
        if (!$resolved_candidate || !aimee_profile_media_path_is_within($resolved_candidate, $resolved_base)) {
            return new WP_Error('legacy_profile_media_unrecognized', 'Legacy profile media was not recognized.');
        }
        $candidate = $resolved_candidate;
    }

    return [
        'path' => $candidate,
        'url' => $url,
        'extension' => (string) ($matches[1] ?? ''),
    ];
}

function aimee_profile_media_profile_url_for_user($user_id) {
    global $wpdb;
    if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
        return new WP_Error('profile_media_database_unavailable', 'Profile media state could not be verified.');
    }
    $table = function_exists('aimee_table') ? aimee_table('aimee_user_profiles') : 'aimee_user_profiles';
    $value = $wpdb->get_var($wpdb->prepare(
        "SELECT profile_image_url FROM `{$table}` WHERE user_id = %d LIMIT 1",
        intval($user_id)
    ));
    if (!empty($wpdb->last_error)) {
        return new WP_Error('profile_media_database_read_failed', 'Profile media state could not be verified.');
    }
    return $value;
}

function aimee_profile_media_delete_user_files($user_id) {
    $user_id = intval($user_id);
    if ($user_id < 1) return false;

    // Account deletion also removes a recognized pre-1.8.3 public upload when
    // the one-time migration has not yet committed its conditional DB update.
    $stored_url = aimee_profile_media_profile_url_for_user($user_id);
    if (is_wp_error($stored_url)) return false;
    if (is_string($stored_url) && $stored_url !== '' && !aimee_profile_media_url_is_protected($stored_url)) {
        $legacy = aimee_profile_media_legacy_candidate($user_id, $stored_url);
        if (is_wp_error($legacy)) return false;
        $legacy_path = (string) $legacy['path'];
        if (is_file($legacy_path) || is_link($legacy_path)) wp_delete_file($legacy_path);
        clearstatcache(true, $legacy_path);
        if (is_file($legacy_path) || is_link($legacy_path)) return false;
    }

    $dir = aimee_profile_media_prepare_dir();
    if (is_wp_error($dir)) return false;
    $base = aimee_profile_media_basename($user_id);
    $complete = true;
    foreach (['jpg', 'png', 'gif', 'webp'] as $extension) {
        $path = $dir . DIRECTORY_SEPARATOR . $base . '.' . $extension;
        if (is_file($path) || is_link($path)) wp_delete_file($path);
        if (is_file($path) || is_link($path)) $complete = false;
    }
    $temporary_files = glob($dir . DIRECTORY_SEPARATOR . $base . '.*.tmp-*');
    if ($temporary_files === false) return false;
    foreach ($temporary_files as $temporary_file) {
        if (!aimee_profile_media_path_is_within($temporary_file, $dir)) {
            $complete = false;
            continue;
        }
        if (is_file($temporary_file) || is_link($temporary_file)) wp_delete_file($temporary_file);
        clearstatcache(true, $temporary_file);
        if (is_file($temporary_file) || is_link($temporary_file)) $complete = false;
    }
    return $complete;
}
add_action('delete_user', 'aimee_profile_media_delete_user_files', 5, 1);

function aimee_profile_media_store($user_id, $bytes, $validated = null) {
    $user_id = intval($user_id);
    if ($user_id < 1) return new WP_Error('profile_image_user_invalid', 'The profile image owner was invalid.');
    // The optional third argument is retained for call compatibility, but the
    // write boundary always derives facts again from the actual bytes. A stale
    // or forged caller-supplied MIME/extension array can never choose storage.
    $facts = aimee_profile_media_validate_bytes($bytes);
    if (is_wp_error($facts)) return $facts;

    $dir = aimee_profile_media_prepare_dir();
    if (is_wp_error($dir)) return $dir;
    $target = $dir . DIRECTORY_SEPARATOR . aimee_profile_media_basename($user_id)
        . '.' . sanitize_key((string) $facts['extension']);
    try {
        $temporary_suffix = bin2hex(random_bytes(8));
    } catch (Exception $exception) {
        return new WP_Error('profile_image_entropy_failed', 'Private profile image storage is unavailable.');
    }
    $temporary = $target . '.tmp-' . $temporary_suffix;
    $written = @file_put_contents($temporary, $bytes, LOCK_EX);
    if ($written !== strlen($bytes)) {
        if (is_file($temporary)) wp_delete_file($temporary);
        return new WP_Error('profile_image_write_failed', 'The profile image could not be stored privately.');
    }
    if (
        DIRECTORY_SEPARATOR !== '\\'
        && (!@chmod($temporary, 0600) || !aimee_profile_media_permissions_are_private($temporary, false))
    ) {
        wp_delete_file($temporary);
        return new WP_Error('profile_image_permissions_failed', 'Private profile image permissions could not be enforced.');
    }
    // POSIX rename atomically replaces an existing target. On platforms such
    // as Windows where replacement rename may be refused, retain the previous
    // image, remove the temporary candidate and fail closed.
    if (!@rename($temporary, $target)) {
        wp_delete_file($temporary);
        return new WP_Error('profile_image_commit_failed', 'The profile image could not be committed privately.');
    }
    if (!aimee_profile_media_permissions_are_private($target, false)) {
        wp_delete_file($target);
        return new WP_Error('profile_image_permissions_failed', 'Private profile image permissions could not be verified.');
    }

    foreach (['jpg', 'png', 'gif', 'webp'] as $extension) {
        $old = $dir . DIRECTORY_SEPARATOR . aimee_profile_media_basename($user_id) . '.' . $extension;
        if ($old !== $target && (is_file($old) || is_link($old))) {
            wp_delete_file($old);
            clearstatcache(true, $old);
            if (is_file($old) || is_link($old)) {
                wp_delete_file($target);
                return new WP_Error('profile_image_old_file_cleanup_failed', 'The previous private profile image could not be replaced safely.');
            }
        }
    }

    return [
        'path' => $target,
        'url' => aimee_profile_media_url(),
        'mime' => (string) $facts['mime'],
        'width' => intval($facts['width']),
        'height' => intval($facts['height']),
        'bytes' => intval($facts['bytes']),
    ];
}

function aimee_profile_media_file_for_user($user_id) {
    $user_id = intval($user_id);
    if ($user_id < 1) return null;
    $prepared = aimee_profile_media_prepare_dir();
    if (is_wp_error($prepared)) return null;
    $dir = realpath($prepared);
    if (!$dir) return null;
    $base = aimee_profile_media_basename($user_id);
    foreach (['jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'] as $extension => $mime) {
        $candidate = realpath($dir . DIRECTORY_SEPARATOR . $base . '.' . $extension);
        if (
            $candidate
            && $candidate !== $dir
            && aimee_profile_media_path_is_within($candidate, $dir)
            && is_file($candidate)
            && is_readable($candidate)
            && aimee_profile_media_permissions_are_private($candidate, false)
        ) {
            $validated = aimee_profile_media_read_validated_file($candidate);
            if (is_wp_error($validated)) continue;
            $facts = $validated['facts'];
            if (($facts['extension'] ?? '') !== $extension || ($facts['mime'] ?? '') !== $mime) continue;
            return [
                'path' => $candidate,
                'mime' => $mime,
                'extension' => $extension,
                'width' => intval($facts['width'] ?? 0),
                'height' => intval($facts['height'] ?? 0),
                'bytes' => intval($facts['bytes'] ?? 0),
            ];
        }
    }
    return null;
}

function aimee_profile_media_migration_option_name() {
    return 'aimee_profile_media_migration_183';
}

function aimee_profile_media_migration_is_complete() {
    $status = get_option(aimee_profile_media_migration_option_name(), null);
    return is_array($status) && !empty($status['completed_at']);
}

/** Migrate one exact legacy profile row; true or a generic WP_Error. */
function aimee_profile_media_migrate_legacy_profile($profile) {
    global $wpdb;
    $user_id = intval(is_array($profile) ? ($profile['user_id'] ?? 0) : ($profile->user_id ?? 0));
    $legacy_url = (string) (is_array($profile) ? ($profile['profile_image_url'] ?? '') : ($profile->profile_image_url ?? ''));
    if ($user_id < 1 || $legacy_url === '' || aimee_profile_media_url_is_protected($legacy_url)) {
        return new WP_Error('legacy_profile_media_row_invalid', 'Legacy profile media row was invalid.');
    }
    $legacy = aimee_profile_media_legacy_candidate($user_id, $legacy_url);
    if (is_wp_error($legacy)) return $legacy;

    // A prior attempt may have committed the private file before stopping on
    // public-file deletion or the conditional DB update. Revalidate and reuse
    // that exact owner file so every failure point is safely retryable.
    $private = aimee_profile_media_file_for_user($user_id);
    if (!$private) {
        $source = aimee_profile_media_read_validated_file($legacy['path']);
        if (is_wp_error($source)) return $source;
        if (($source['facts']['extension'] ?? '') !== ($legacy['extension'] ?? '')) {
            return new WP_Error('legacy_profile_media_type_mismatch', 'Legacy profile media type did not match its filename.');
        }
        $stored = aimee_profile_media_store($user_id, $source['bytes'], $source['facts']);
        if (is_wp_error($stored)) return $stored;
        $private = aimee_profile_media_file_for_user($user_id);
        if (!$private) {
            return new WP_Error('legacy_profile_media_private_verify_failed', 'Private profile media could not be verified.');
        }
    }

    // A database pointer is never changed until the source upload is absent.
    $legacy_path = (string) $legacy['path'];
    if (is_file($legacy_path) || is_link($legacy_path)) wp_delete_file($legacy_path);
    clearstatcache(true, $legacy_path);
    if (is_file($legacy_path) || is_link($legacy_path)) {
        return new WP_Error('legacy_profile_media_public_delete_failed', 'Legacy public profile media could not be removed.');
    }

    if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'query')) {
        return new WP_Error('legacy_profile_media_database_unavailable', 'Profile media migration database was unavailable.');
    }
    $table = function_exists('aimee_table') ? aimee_table('aimee_user_profiles') : 'aimee_user_profiles';
    $protected_url = aimee_profile_media_url();
    // BINARY makes the compare byte-exact even when the URL column uses a
    // case-insensitive collation; a concurrent case-only change cannot be lost.
    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE `{$table}` SET profile_image_url = %s
         WHERE user_id = %d AND BINARY profile_image_url = BINARY %s",
        $protected_url,
        $user_id,
        $legacy_url
    ));
    if ($updated === false) {
        return new WP_Error('legacy_profile_media_database_update_failed', 'Profile media migration could not be committed.');
    }
    $verified_url = aimee_profile_media_profile_url_for_user($user_id);
    if (!is_string($verified_url) || !hash_equals($protected_url, $verified_url)) {
        return new WP_Error('legacy_profile_media_database_verify_failed', 'Profile media migration could not be verified.');
    }
    if (!aimee_profile_media_file_for_user($user_id)) {
        return new WP_Error('legacy_profile_media_private_verify_failed', 'Private profile media could not be verified.');
    }
    return true;
}

function aimee_profile_media_migration_status_write(array $status) {
    update_option(aimee_profile_media_migration_option_name(), $status, false);
    return get_option(aimee_profile_media_migration_option_name(), null) === $status;
}

/**
 * Bounded, idempotent and retryable migration for public 1.8.2 profile photos.
 *
 * Returns true only after a verified scan finds no nonempty, non-protected
 * profile_image_url. Unrecognized URLs remain pending rather than being copied,
 * deleted or silently blessed.
 */
function aimee_profile_media_maybe_migrate_legacy($force = false) {
    global $wpdb;
    if (aimee_profile_media_migration_is_complete()) return true;
    $status = get_option(aimee_profile_media_migration_option_name(), []);
    if (!is_array($status)) $status = [];
    if (
        !$force
        && intval($status['next_attempt_at'] ?? 0) > time()
    ) return false;
    if (
        !isset($wpdb)
        || !is_object($wpdb)
        || !method_exists($wpdb, 'get_results')
        || !function_exists('aimee_table')
        || !function_exists('aimee_global_core_schema_health')
        || !aimee_global_core_schema_health(true)
    ) {
        $status['state'] = 'pending';
        $status['last_error'] = 'schema_unavailable';
        $status['next_attempt_at'] = time() + MINUTE_IN_SECONDS;
        aimee_profile_media_migration_status_write($status);
        return false;
    }

    $lock_key = 'aimee_profile_media_migration_183_lock';
    if (get_transient($lock_key)) return false;
    set_transient($lock_key, 1, 5 * MINUTE_IN_SECONDS);
    try {
        $table = aimee_table('aimee_user_profiles');
        $protected_url = aimee_profile_media_url();
        $cursor = max(0, intval($status['cursor'] ?? 0));
        $limit = 20;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, profile_image_url FROM `{$table}`
             WHERE user_id > %d
               AND profile_image_url IS NOT NULL
               AND profile_image_url <> ''
               AND profile_image_url <> %s
             ORDER BY user_id ASC LIMIT %d",
            $cursor,
            $protected_url,
            $limit
        ));
        if (!is_array($rows)) {
            $status['state'] = 'pending';
            $status['last_error'] = 'scan_failed';
            $status['next_attempt_at'] = time() + MINUTE_IN_SECONDS;
            aimee_profile_media_migration_status_write($status);
            return false;
        }

        $failures = [];
        $last_user_id = $cursor;
        foreach ($rows as $row) {
            $last_user_id = max($last_user_id, intval($row->user_id ?? 0));
            $result = aimee_profile_media_migrate_legacy_profile($row);
            if (is_wp_error($result)) {
                $codes = $result->get_error_codes();
                $failures[] = [
                    'user_id' => intval($row->user_id ?? 0),
                    'code' => sanitize_key((string) ($codes[0] ?? 'migration_failed')),
                ];
            }
        }

        $remaining = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE profile_image_url IS NOT NULL
               AND profile_image_url <> ''
               AND profile_image_url <> %s",
            $protected_url
        ));
        if (!is_numeric($remaining)) {
            $status['state'] = 'pending';
            $status['last_error'] = 'verification_scan_failed';
            $status['next_attempt_at'] = time() + MINUTE_IN_SECONDS;
            aimee_profile_media_migration_status_write($status);
            return false;
        }
        if (intval($remaining) === 0) {
            $completion_written = aimee_profile_media_migration_status_write([
                'state' => 'complete',
                'completed_at' => function_exists('current_time')
                    ? current_time('mysql', true)
                    : gmdate('Y-m-d H:i:s'),
                'migrated_version' => '1.8.3',
            ]);
            return $completion_written && aimee_profile_media_migration_is_complete();
        }

        $attempts = max(0, intval($status['attempts'] ?? 0)) + 1;
        $status = [
            'state' => 'pending',
            'cursor' => count($rows) >= $limit ? $last_user_id : 0,
            'attempts' => $attempts,
            'remaining' => intval($remaining),
            'failures' => array_slice($failures, 0, 20),
            'last_error' => $failures ? 'record_migration_failed' : 'batch_pending',
            'next_attempt_at' => time() + min(3600, MINUTE_IN_SECONDS * (1 << min(6, $attempts - 1))),
        ];
        aimee_profile_media_migration_status_write($status);
        return false;
    } catch (Throwable $throwable) {
        $status['state'] = 'pending';
        $status['last_error'] = 'unexpected_failure';
        $status['next_attempt_at'] = time() + MINUTE_IN_SECONDS;
        aimee_profile_media_migration_status_write($status);
        return false;
    } finally {
        delete_transient($lock_key);
    }
}
add_action('init', 'aimee_profile_media_maybe_migrate_legacy', 15);

function aimee_serve_profile_media() {
    $user_id = get_current_user_id();
    if (!$user_id) {
        status_header(401);
        nocache_headers();
        exit('Authentication required.');
    }
    $file = aimee_profile_media_file_for_user($user_id);
    if (!$file) {
        status_header(404);
        nocache_headers();
        exit('Profile image not found.');
    }

    while (ob_get_level()) ob_end_clean();
    header('Content-Type: ' . $file['mime']);
    header('Content-Length: ' . filesize($file['path']));
    header('Content-Disposition: inline; filename="profile-image.' . $file['extension'] . '"');
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Cross-Origin-Resource-Policy: same-origin');
    readfile($file['path']);
    exit;
}
add_action('admin_post_aimee_profile_photo', 'aimee_serve_profile_media');
add_action('admin_post_nopriv_aimee_profile_photo', function () {
    status_header(401);
    nocache_headers();
    exit('Authentication required.');
});

/** Self-enforcing gallery helpers used directly by every shipped template. */
function aimee_security_require_gallery_access($market = 'uk') {
    global $wpdb;
    $market = $market === 'us' ? 'us' : 'uk';
    $chat_url = function_exists('aimee_global_route')
        ? aimee_global_route('chat', $market)
        : home_url($market === 'us' ? '/chat-us/' : '/chat/');

    if (!is_user_logged_in()) {
        wp_safe_redirect(add_query_arg('sign_in_required', 'gallery', $chat_url));
        exit;
    }
    if (
        !function_exists('aimee_table')
        || !function_exists('aimee_is_admin_user')
        || !function_exists('aimee_media_item_is_viewable')
    ) {
        wp_die(
            esc_html__('The private gallery is temporarily unavailable.', 'aimee-global'),
            esc_html__('Aimee gallery unavailable', 'aimee-global'),
            ['response' => 503]
        );
    }

    $user_id = get_current_user_id();
    $profile = $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . aimee_table('aimee_user_profiles') . ' WHERE user_id = %d LIMIT 1',
        $user_id
    ));
    // A WordPress administrator without an Aimee profile may inspect only the
    // safe catalogue subset. A synthetic age of zero deliberately keeps all
    // adult item checks closed while user_can() still proves administrator.
    if (!$profile && current_user_can('manage_options')) {
        $profile = (object) ['user_id' => $user_id, 'age' => 0];
    }
    if (!$profile && !current_user_can('manage_options')) {
        wp_safe_redirect(add_query_arg('profile_required', 'gallery', $chat_url));
        exit;
    }
    nocache_headers();
    return ['user_id' => $user_id, 'profile' => $profile];
}

/** Plugin-owned album labels. Manifest text is never rendered as a heading. */
function aimee_security_gallery_album_definitions() {
    return [
        'family' => [
            'label' => __('Family', 'aimee-global'),
            'description' => __('Family-style portraits from Aimee\'s chosen visual world.', 'aimee-global'),
        ],
        'friends' => [
            'label' => __('Friends', 'aimee-global'),
            'description' => __('Visual-world scenes with friends, from nights out to quiet picnics.', 'aimee-global'),
        ],
        'holidays_travel' => [
            'label' => __('Holidays & Travel', 'aimee-global'),
            'description' => __('Travel settings and holiday-style scenes chosen to start a conversation.', 'aimee-global'),
        ],
        'nights_celebrations' => [
            'label' => __('Nights Out & Celebrations', 'aimee-global'),
            'description' => __('Dressed-up visual scenes, celebrations and softly lit evenings.', 'aimee-global'),
        ],
        'days_out_adventures' => [
            'label' => __('Days Out & Adventures', 'aimee-global'),
            'description' => __('Visual-world outings with plenty in the frame to talk about.', 'aimee-global'),
        ],
        'active_wellbeing' => [
            'label' => __('Active & Wellbeing', 'aimee-global'),
            'description' => __('Active, outdoor and wellbeing scenes in Aimee\'s visual world.', 'aimee-global'),
        ],
        'style_getting_ready' => [
            'label' => __('Style & Getting Ready', 'aimee-global'),
            'description' => __('Outfits, mirror checks and moments when an honest opinion is welcome.', 'aimee-global'),
        ],
        'everyday_moments' => [
            'label' => __('Everyday Moments', 'aimee-global'),
            'description' => __('Coffee, home-style scenes and the small details between bigger moments.', 'aimee-global'),
        ],
        'throwbacks' => [
            'label' => __('Throwbacks', 'aimee-global'),
            'description' => __('Throwback styling, questionable choices and a little visual nostalgia.', 'aimee-global'),
        ],
        'just_between_us' => [
            'label' => __('Just Between Us', 'aimee-global'),
            'description' => __('A more private side of Aimee, shown only when the account and relationship are ready.', 'aimee-global'),
        ],
    ];
}

/**
 * Assign one already-authorised catalogue item to one deterministic album.
 *
 * Private ratings always win. The remaining first-match order prevents a
 * family race-day photo becoming a generic event and a friend night out
 * becoming a generic nightlife image. Future manifest fields cannot inject
 * their own labels into the page.
 */
function aimee_security_gallery_album_key($key, $item) {
    $definitions = aimee_security_gallery_album_definitions();
    $rating = sanitize_key((string) ($item['content_rating'] ?? 'safe'));
    if (in_array($rating, ['flirty', 'suggestive', 'erotic', 'explicit'], true)) {
        return 'just_between_us';
    }

    $tokens = [str_replace(['_', '-'], ' ', sanitize_key((string) $key))];
    foreach ((array) ($item['tags'] ?? []) as $tag) {
        $tag = mb_strtolower(trim((string) $tag));
        if ($tag !== '') $tokens[] = $tag;
    }
    $haystack = '|' . implode('|', $tokens) . '|';
    $matches = static function ($pattern) use ($haystack) {
        return preg_match($pattern, $haystack) === 1;
    };

    if ($matches('/\b(?:family|mum|dad|mother|father|parents)\b/u')) return 'family';
    if ($matches('/\b(?:friend|friends|best friend|best friends|sarah)\b/u')) return 'friends';
    if ($matches('/\bthrowback\b/u')) return 'throwbacks';
    if ($matches('/\b(?:holiday|travel|road trip|rome|las vegas|scarborough)\b/u')) return 'holidays_travel';
    if ($matches('/\b(?:gym|workout|yoga|pilates|tennis|lido|swimming|nature walk|track day|motorcycle|motorsport)\b/u')) return 'active_wellbeing';
    if ($matches('/\b(?:night out|cocktail bar|wedding|celebration|date night)\b/u')) return 'nights_celebrations';
    if ($matches('/\b(?:getting ready|mirror selfie|outfit of the day|ootd|rate my outfit|fashion)\b/u')) return 'style_getting_ready';
    if ($matches('/\b(?:bookshop|farmers market|ikea|picnic|fairground|summer fair|pub garden|day out|shopping trip)\b/u')) return 'days_out_adventures';

    return isset($definitions['everyday_moments'])
        ? 'everyday_moments'
        : array_key_first($definitions);
}

function aimee_security_gallery_items($user_id, $profile) {
    if (
        !function_exists('aimee_private_media_catalog')
        || !function_exists('aimee_media_item_is_viewable')
        || !function_exists('aimee_private_media_payload')
    ) {
        return [];
    }
    $items = [];
    foreach ((array) aimee_private_media_catalog() as $key => $item) {
        if (!is_array($item) || ($item['gallery_visibility'] ?? 'hidden') === 'hidden') continue;
        if (!aimee_media_item_is_viewable($user_id, $key, $profile)) continue;
        $payload = aimee_private_media_payload($key);
        if (!is_array($payload) || empty($payload['url'])) continue;
        $items[] = [
            'key' => sanitize_key((string) $key),
            'url' => esc_url_raw((string) $payload['url']),
            'alt' => sanitize_text_field((string) ($payload['alt'] ?? 'A private Aimee photograph')),
            'rating' => sanitize_key((string) ($item['content_rating'] ?? 'safe')),
            'album_key' => aimee_security_gallery_album_key($key, $item),
        ];
    }
    return $items;
}

/** Group only the items that survived the server-side per-user access check. */
function aimee_security_gallery_albums($user_id, $profile, $items = null) {
    $definitions = aimee_security_gallery_album_definitions();
    $grouped = [];
    foreach ($definitions as $key => $definition) {
        $grouped[$key] = [
            'key' => $key,
            'label' => sanitize_text_field((string) ($definition['label'] ?? '')),
            'description' => sanitize_text_field((string) ($definition['description'] ?? '')),
            'items' => [],
        ];
    }

    $items = is_array($items)
        ? $items
        : aimee_security_gallery_items($user_id, $profile);
    foreach ($items as $item) {
        $album_key = sanitize_key((string) ($item['album_key'] ?? 'everyday_moments'));
        if (!isset($grouped[$album_key])) $album_key = 'everyday_moments';
        if (isset($grouped[$album_key])) $grouped[$album_key]['items'][] = $item;
    }

    return array_values(array_filter($grouped, static function ($album) {
        return !empty($album['items']);
    }));
}
