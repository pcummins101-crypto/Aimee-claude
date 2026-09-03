<?php
defined('ABSPATH') || exit;

/**
 * Why a private photograph failed to serve.
 *
 * Aimee Global answers every refusal from admin-post.php with one sentence
 * and records nothing, so a broken image gives no reason. These hooks run
 * before Global's handler, evaluate the same predicates it is about to
 * evaluate, and record the outcome. They change no behaviour: Global still
 * decides, and no image is served or withheld because of this file.
 */
function aimee_engine_media_record($entry) {
    $log = get_option('aimee_engine_media_diagnostics');
    $log = is_array($log) ? $log : [];
    array_unshift($log, $entry);
    update_option('aimee_engine_media_diagnostics', array_slice($log, 0, 25), false);
}

function aimee_engine_media_diagnostics() {
    $log = get_option('aimee_engine_media_diagnostics');
    return is_array($log) ? $log : [];
}

/**
 * Signed out at the media controller. Almost always a cookie that did not
 * travel with the image request (different host or scheme from the chat
 * page, or a stale login), not an entitlement problem.
 */
function aimee_engine_media_diagnose_nopriv() {
    aimee_engine_media_record([
        'at'       => current_time('mysql', true),
        'user_id'  => 0,
        'key'      => sanitize_key($_GET['key'] ?? ''),
        'delivery' => sanitize_text_field((string) ($_GET['delivery_id'] ?? '')) !== '' ? 'yes' : 'no',
        'outcome'  => 'not_signed_in',
        'reason'   => 'The image request arrived without a signed-in session. Check that the chat page and admin-post.php share one host and scheme.',
        'facts'    => [],
    ]);
}
add_action('admin_post_nopriv_aimee_private_media', 'aimee_engine_media_diagnose_nopriv', 1);

function aimee_engine_media_diagnose() {
    if (!function_exists('aimee_private_media_catalog')) return;

    global $wpdb;
    $user_id = get_current_user_id();
    $key = sanitize_key($_GET['key'] ?? '');
    $delivery_id = sanitize_text_field((string) ($_GET['delivery_id'] ?? ''));

    $profile = $user_id ? $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . aimee_table('aimee_user_profiles') . ' WHERE user_id = %d',
        $user_id
    )) : null;

    $catalog = aimee_private_media_catalog();
    $item = $catalog[$key] ?? null;
    $static_path = function_exists('aimee_private_media_static_path') ? aimee_private_media_static_path($key) : null;
    $resolved_path = function_exists('aimee_private_media_path') ? aimee_private_media_path($key) : null;
    $viewable = $profile && is_array($item) && function_exists('aimee_media_item_is_viewable')
        ? (bool) aimee_media_item_is_viewable($user_id, $key, $profile)
        : false;

    $facts = [
        'in_catalogue'   => is_array($item) ? 'yes' : 'NO',
        'rating'         => is_array($item) ? sanitize_key((string) ($item['content_rating'] ?? '')) : '',
        'file_on_disk'   => $static_path ? 'yes' : 'NO',
        'asset_resolves' => $resolved_path ? 'yes' : 'NO',
        'viewable'       => $viewable ? 'yes' : 'NO',
        'profile'        => $profile ? 'yes' : 'NO',
        'member'         => $profile && function_exists('aimee_subscription_is_active') && aimee_subscription_is_active($profile) ? 'yes' : 'no',
        'catalogue_mode' => function_exists('aimee_public_media_catalogue_mode_enabled') && aimee_public_media_catalogue_mode_enabled() ? 'public' : 'private',
    ];

    $outcome = 'served';
    $reason = '';

    if ($delivery_id !== '') {
        $delivery = function_exists('aimee_media_delivery_find') ? aimee_media_delivery_find($delivery_id, $user_id) : null;
        if (!is_array($delivery)) {
            $outcome = 'refused';
            $reason = 'No delivery record for this account and delivery id.';
        } else {
            $missing = [];
            if ((string) ($delivery['media_key'] ?? '') !== $key) $missing[] = 'media_key mismatch';
            foreach (['authorised_at', 'file_resolved_at', 'message_created_at'] as $field) {
                if (empty($delivery[$field])) $missing[] = $field;
            }
            if (intval($delivery['message_id'] ?? 0) <= 0) $missing[] = 'message_id';
            if (!empty($delivery['failed_at'])) $missing[] = 'failed_at is set (' . sanitize_key((string) ($delivery['error_code'] ?? '')) . ')';
            $facts['delivery_state'] = sanitize_key((string) ($delivery['current_state'] ?? ''));
            $facts['returned'] = !empty($delivery['returned_by_direct_api_at']) || !empty($delivery['returned_by_history_api_at']) ? 'yes' : 'no';
            $facts['asset_requested'] = !empty($delivery['asset_requested_at']) ? 'yes' : 'no';
            $facts['asset_source'] = sanitize_key((string) ($delivery['resolved_asset_source'] ?? ''));

            if ($missing) {
                $outcome = 'refused';
                $reason = 'Delivery record incomplete: ' . implode(', ', $missing) . '.';
            } elseif (!$viewable) {
                $outcome = 'refused';
                $reason = 'The delivery is valid but the catalogue item is not currently viewable by this account'
                    . ($static_path ? '.' : ' because its file could not be resolved on disk (check the private catalogue directory, file permissions and the sha256 in catalog.json).');
            } elseif (!$resolved_path) {
                $outcome = 'not_found';
                $reason = 'Authorised, but the bound asset could not be resolved to a file.';
            }
        }
    } else {
        if (!$viewable) {
            $outcome = 'refused';
            $reason = !is_array($item)
                ? 'The key is not in the catalogue.'
                : (!$static_path
                    ? 'The catalogue file could not be resolved on disk (private catalogue directory, file permissions, or a sha256 that does not match catalog.json).'
                    : 'The item is in the catalogue and on disk, but this account may not view it at its rating.');
        }
    }

    if ($outcome === 'served') return;

    aimee_engine_media_record([
        'at'       => current_time('mysql', true),
        'user_id'  => $user_id,
        'key'      => $key,
        'delivery' => $delivery_id !== '' ? 'yes' : 'no',
        'outcome'  => $outcome,
        'reason'   => $reason,
        'facts'    => $facts,
    ]);
}
add_action('admin_post_aimee_private_media', 'aimee_engine_media_diagnose', 1);

add_action('admin_post_aimee_engine_clear_media_diagnostics', function () {
    if (!current_user_can('manage_options')) wp_die('Not allowed.');
    check_admin_referer('aimee_engine_clear_media');
    delete_option('aimee_engine_media_diagnostics');
    wp_safe_redirect(add_query_arg(['page' => 'aimee-engine', 'cleared' => 'media'], admin_url('options-general.php')));
    exit;
});

/**
 * Aimee's portrait for the chat header.
 *
 * The header needs one picture of Aimee, the same image the public landing
 * page already shows. Serving it through Global's delivery-bound controller
 * ties a decorative avatar to the photograph entitlement pipeline, so the
 * engine serves it directly from the same file on disk, to signed-in
 * profiles only. It is never a catalogue send and never touches a delivery
 * record, so nothing here can widen what Aimee may share in conversation.
 */
function aimee_engine_portrait_key() {
    if (defined('AIMEE_PROFILE_MEDIA_KEYS')) {
        $keys = array_values(array_filter(array_map('sanitize_key', (array) AIMEE_PROFILE_MEDIA_KEYS)));
        if ($keys) return $keys[0];
    }
    return 'portrait';
}

/**
 * Resolve the portrait to a readable file: the private catalogue copy first,
 * then the uploads original the landing page uses.
 */
function aimee_engine_portrait_file($refresh = false) {
    // Resolving through Global hashes the file, so keep the answer briefly.
    $cache_key = 'aimee_engine_portrait_path';
    if (!$refresh) {
        $cached = get_transient($cache_key);
        if (is_string($cached)) return $cached !== '' && is_readable($cached) ? $cached : '';
    }
    $path = aimee_engine_portrait_file_resolve();
    set_transient($cache_key, $path, HOUR_IN_SECONDS);
    return $path;
}

function aimee_engine_portrait_file_resolve() {
    $key = aimee_engine_portrait_key();
    if (function_exists('aimee_private_media_static_path')) {
        $path = aimee_private_media_static_path($key);
        if ($path && is_readable($path)) return $path;
    }
    if (!function_exists('aimee_private_media_catalog')) return '';

    $catalog = aimee_private_media_catalog();
    $item = $catalog[$key] ?? null;
    if (!is_array($item)) return '';
    $relative = ltrim(str_replace('\\', '/', (string) ($item['source_relative'] ?? '')), '/');
    if ($relative === '' || strpos($relative, '..') !== false) return '';
    $uploads = wp_upload_dir();
    if (empty($uploads['basedir'])) return '';
    $candidate = trailingslashit($uploads['basedir']) . $relative;
    $real = realpath($candidate);
    $base = realpath($uploads['basedir']);
    if (!$real || !$base || strpos($real, $base) !== 0 || is_link($candidate) || !is_readable($real)) return '';
    return $real;
}

function aimee_engine_serve_portrait() {
    if (!is_user_logged_in()) {
        status_header(401);
        nocache_headers();
        exit('Sign-in required.');
    }
    $path = aimee_engine_portrait_file();
    if ($path === '') $path = aimee_engine_portrait_file(true);
    $facts = $path ? @getimagesize($path) : false;
    $mime = is_array($facts) ? strtolower((string) ($facts['mime'] ?? '')) : '';
    if (!$path || !in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
        status_header(404);
        nocache_headers();
        exit('Portrait not available.');
    }

    while (ob_get_level()) ob_end_clean();
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="aimee.' . ($mime === 'image/png' ? 'png' : 'jpg') . '"');
    readfile($path);
    exit;
}
add_action('admin_post_aimee_engine_portrait', 'aimee_engine_serve_portrait');
add_action('admin_post_nopriv_aimee_engine_portrait', function () {
    status_header(401);
    nocache_headers();
    exit('Sign-in required.');
});

function aimee_engine_portrait_endpoint_url() {
    return add_query_arg(['action' => 'aimee_engine_portrait'], admin_url('admin-post.php'));
}
