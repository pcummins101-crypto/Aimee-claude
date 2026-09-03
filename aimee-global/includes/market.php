<?php
defined('ABSPATH') || exit;

function aimee_global_set_market($market) {
    $market = sanitize_key((string) $market) === 'us' ? 'us' : 'uk';
    $GLOBALS['aimee_global_market'] = $market;
    if (!headers_sent()) {
        setcookie('aimee_market', $market, [
            'expires' => time() + YEAR_IN_SECONDS,
            'path' => COOKIEPATH ?: '/',
            'domain' => COOKIE_DOMAIN ?: '',
            'secure' => is_ssl(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }
    return $market;
}

function aimee_global_profile_market_for_user($user_id) {
    global $wpdb;
    $user_id = (int) $user_id;
    if (
        $user_id < 1
        || !isset($wpdb)
        || !function_exists('aimee_table')
    ) return '';
    $stored = $wpdb->get_var($wpdb->prepare(
        'SELECT market FROM ' . aimee_table('aimee_user_profiles') . ' WHERE user_id = %d',
        $user_id
    ));
    $stored = sanitize_key((string) $stored);
    return in_array($stored, ['uk', 'us'], true) ? $stored : '';
}

function aimee_global_market($explicit = null) {
    if ($explicit !== null && $explicit !== '') return sanitize_key((string)$explicit) === 'us' ? 'us' : 'uk';
    if (!empty($GLOBALS['aimee_global_market'])) return $GLOBALS['aimee_global_market'] === 'us' ? 'us' : 'uk';
    if (!empty($_REQUEST['market'])) return sanitize_key(wp_unslash($_REQUEST['market'])) === 'us' ? 'us' : 'uk';
    $path = wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (preg_match('~/(usa|chat-us|pricing-us|faq-us|technology-us|privacy-us|camera-roll-us)(/|$)~i', (string)$path)) return 'us';
    if (is_user_logged_in()) {
        $stored = aimee_global_profile_market_for_user(get_current_user_id());
        if ($stored !== '') return $stored;
    }
    if (!empty($_COOKIE['aimee_market']) && sanitize_key(wp_unslash($_COOKIE['aimee_market'])) === 'us') return 'us';
    return get_option('aimee_global_default_market', 'uk') === 'us' ? 'us' : 'uk';
}

function aimee_global_market_config($market = null) {
    $market = aimee_global_market($market);
    $is_us = $market === 'us';
    return [
        'market' => $market,
        'country' => $is_us ? 'US' : 'GB',
        'currency' => $is_us ? 'USD' : 'GBP',
        'currency_lower' => $is_us ? 'usd' : 'gbp',
        'symbol' => $is_us ? '$' : '£',
        'locale' => $is_us ? 'en-US' : 'en-GB',
        'weekly_minor' => max(100, (int)get_option('aimee_global_' . $market . '_weekly_minor', 699)),
        'monthly_minor' => max(100, (int)get_option('aimee_global_' . $market . '_monthly_minor', 1999)),
        'annual_minor' => max(100, (int)get_option('aimee_global_' . $market . '_annual_minor', 14900)),
        'sms_minor' => max(100, (int)get_option('aimee_global_' . $market . '_sms_minor', 599)),
        // Aimee uses the same genuine UK +44 number in both markets. US users
        // may text it, but their carrier may price the message as international.
        'mobile_available' => true,
        'sms_origin_country' => 'GB',
        'sms_number_prefix' => '+44',
        'sms_international_notice' => $is_us
            ? 'Aimee texts from a UK +44 number. Your mobile provider may treat messages to or from this number as international and charge them outside any included SMS allowance.'
            : '',
        'emergency' => $is_us ? '911' : '999 or 112',
        'crisis' => $is_us ? 'Call or text 988' : 'Samaritans: 116 123',
    ];
}

function aimee_global_money($minor, $market = null) {
    $c = aimee_global_market_config($market);
    return $c['symbol'] . number_format(((int)$minor)/100, 2, '.', ',');
}

function aimee_global_route($key, $market = null) {
    $market = aimee_global_market($market);
    $routes = [
        'uk' => ['home'=>'/home/','chat'=>'/chat/','pricing'=>'/pricing/','faq'=>'/faq/','technology'=>'/technology/','privacy'=>'/privacy/','gallery'=>'/camera-roll/','governance'=>'/governance/'],
        'us' => ['home'=>'/usa/','chat'=>'/chat-us/','pricing'=>'/pricing-us/','faq'=>'/faq-us/','technology'=>'/technology-us/','privacy'=>'/privacy-us/','gallery'=>'/camera-roll-us/','governance'=>'/governance/'],
    ];
    return home_url($routes[$market][$key] ?? $routes[$market]['home']);
}

function aimee_global_market_label($market = null) { return aimee_global_market($market) === 'us' ? 'United States' : 'United Kingdom'; }

function aimee_global_persona_rule() {
    return 'Aimee remains British in every market. Use natural British English, British spelling and her established British cultural identity. Never Americanise her vocabulary merely because the customer is in the United States.';
}
