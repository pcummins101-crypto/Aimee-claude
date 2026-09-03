<?php
defined('ABSPATH') || exit;

/**
 * Serve Aimee Engine's own chat page to enrolled, signed-in users. Aimee
 * Global resolves its chat page template at priority 99; this runs after it
 * and swaps in the engine template only when the user is enrolled. Everyone
 * else, and every signed-out visitor, keeps Aimee Global's page untouched.
 */
function aimee_engine_template_include($template) {
    if (!aimee_engine_setting('chat_page') || !is_user_logged_in()) return $template;

    // Either Aimee Global's chat template was resolved, or this is one of its
    // managed chat pages by slug (the theme's own app template may be assigned).
    $base = basename((string) $template);
    $global_template = defined('AIMEE_GLOBAL_DIR')
        && in_array($base, ['chat-uk.php', 'chat-us.php'], true)
        && strpos(wp_normalize_path((string) $template), wp_normalize_path(AIMEE_GLOBAL_DIR)) === 0;
    $chat_page = function_exists('is_page') && is_page(['chat', 'chat-us']);
    if (!$global_template && !$chat_page) return $template;

    if (!aimee_engine_ready()) return $template;
    if (aimee_engine_route_decision_for_request(get_current_user_id(), null) !== 'engine') return $template;

    $market = ($base === 'chat-us.php' || (function_exists('is_page') && is_page('chat-us'))) ? 'us' : 'uk';
    $GLOBALS['aimee_engine_chat_market'] = $market;
    return AIMEE_ENGINE_DIR . 'templates/chat.php';
}
add_filter('template_include', 'aimee_engine_template_include', 100);

/**
 * Everything the template needs, gathered in one place.
 */
function aimee_engine_chat_page_data($market) {
    global $wpdb;

    $market = $market === 'us' ? 'us' : 'uk';
    if (function_exists('aimee_global_set_market')) aimee_global_set_market($market);
    $config = function_exists('aimee_global_market_config') ? aimee_global_market_config($market) : ['locale' => 'en-GB', 'symbol' => '£'];
    $uid = get_current_user_id();
    $profile = $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . aimee_table('aimee_user_profiles') . ' WHERE user_id = %d',
        $uid
    ));
    $first = $profile && $profile->first_name ? (string) $profile->first_name : wp_get_current_user()->display_name;
    $photo = function_exists('aimee_profile_media_file_for_user') && aimee_profile_media_file_for_user($uid) && function_exists('aimee_profile_media_url')
        ? aimee_profile_media_url()
        : '';
    $phone = $profile ? (string) ($profile->phone_number ?? '') : '';
    if ($market === 'us' && preg_match('/^1[0-9]{10}$/', $phone)) $phone = '+' . $phone;
    elseif ($market === 'uk' && preg_match('/^44[0-9]{10}$/', $phone)) $phone = '+' . $phone;

    $route = function ($key) use ($market) {
        return function_exists('aimee_global_route') ? aimee_global_route($key, $market) : home_url('/');
    };
    $plans = function_exists('aimee_membership_plans') ? aimee_membership_plans($market) : [];
    $subscription = function_exists('aimee_get_subscription_snapshot') ? aimee_get_subscription_snapshot($uid, $profile) : [];

    return [
        'market'          => $market,
        'is_us'           => $market === 'us',
        'locale'          => (string) ($config['locale'] ?? 'en-GB'),
        'symbol'          => (string) ($config['symbol'] ?? '£'),
        'uid'             => $uid,
        'nonce'           => wp_create_nonce('wp_rest'),
        'profile'         => $profile,
        'first'           => $first,
        'photo'           => $photo,
        'portrait'        => aimee_engine_portrait_url($uid, $profile),
        'phone'           => $phone,
        'sms_opt_in'      => $profile ? intval($profile->sms_opt_in ?? 0) : 0,
        'sms_override'    => $profile ? intval($profile->sms_override ?? 0) : 0,
        'safe_start'      => $profile ? intval($profile->sms_safe_start_hour ?? 9) : 9,
        'safe_end'        => $profile ? intval($profile->sms_safe_end_hour ?? 17) : 17,
        'sms_verified'    => $profile && function_exists('aimee_global_sms_profile_is_verified') && aimee_global_sms_profile_is_verified($profile),
        'special_consent' => $profile && function_exists('aimee_special_category_consent_is_active') && aimee_special_category_consent_is_active($profile),
        'plans'           => $plans,
        'checkout_supported' => $market === 'uk',
        'subscription'    => is_array($subscription) ? $subscription : [],
        'urls'            => [
            'home'    => $route('home'),
            'chat'    => $route('chat'),
            'gallery' => $route('gallery'),
            'privacy' => $route('privacy'),
            'pricing' => $route('pricing'),
            'logout'  => wp_logout_url($route('chat')),
            'rest'    => rest_url('aimee/v1'),
            'stream'  => rest_url('aimee-engine/v1/stream'),
        ],
    ];
}

/**
 * Aimee Global's injectable chat helpers (privacy choices, media delivery
 * acknowledgements, gallery discovery, release feedback, public statement
 * notice, billing migration). Each is optional and guarded.
 */
function aimee_engine_chat_page_injections($market) {
    $html = '';
    foreach ([
        'aimee_global_chat_gallery_discovery_markup',
        'aimee_global_chat_release_feedback_markup',
        'aimee_global_chat_press_release_markup',
        'aimee_global_chat_billing_migration_markup',
    ] as $function) {
        if (function_exists($function)) $html .= (string) call_user_func($function, $market);
    }
    if (function_exists('aimee_global_media_delivery_markup')) $html .= (string) aimee_global_media_delivery_markup();
    return $html;
}

function aimee_engine_money($minor, $symbol) {
    return $symbol . number_format(intval($minor) / 100, 2, '.', ',');
}

/**
 * Aimee's own portrait for the chat header. Aimee Global's bundled template
 * showed the PWA app icon here (the "A" tile). The real portrait is the
 * catalogue's profile asset, which every signed-in profile may view, so it
 * is served through Global's private media controller. Falls back to the
 * public uploads copy the landing page uses, then to the app icon.
 */
function aimee_engine_portrait_url($user_id, $profile) {
    if (defined('AIMEE_PORTRAIT_URL') && trim((string) AIMEE_PORTRAIT_URL) !== '') return (string) AIMEE_PORTRAIT_URL;

    $key = 'portrait';
    if (defined('AIMEE_PROFILE_MEDIA_KEYS')) {
        $keys = array_values(array_filter(array_map('sanitize_key', (array) AIMEE_PROFILE_MEDIA_KEYS)));
        if ($keys) $key = $keys[0];
    }
    if (function_exists('aimee_media_item_is_viewable') && function_exists('aimee_private_media_url')
        && $profile && aimee_media_item_is_viewable($user_id, $key, $profile)) {
        $url = aimee_private_media_url($key);
        if ($url !== '') return $url;
    }
    if (function_exists('aimee_private_media_catalog')) {
        $catalog = aimee_private_media_catalog();
        $relative = ltrim(str_replace('\\', '/', (string) ($catalog[$key]['source_relative'] ?? '')), '/');
        if ($relative !== '' && strpos($relative, '..') === false) {
            $uploads = wp_upload_dir();
            if (!empty($uploads['basedir']) && is_readable(trailingslashit($uploads['basedir']) . $relative)) {
                return trailingslashit($uploads['baseurl']) . $relative;
            }
        }
    }
    return defined('AIMEE_GLOBAL_URL') ? AIMEE_GLOBAL_URL . 'assets/pwa/aimee-icon-512.png' : '';
}
