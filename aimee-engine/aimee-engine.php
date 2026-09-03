<?php
/**
 * Plugin Name: Aimee Engine
 * Plugin URI: https://aimee-ai.com
 * Description: A prompt-light conversation engine for Aimee. Runs alongside Aimee Global and takes over the in-app chat turn for enrolled users only. Everything else (pages, billing, gallery, SMS, voice, memory tables) stays with Aimee Global.
 * Version: 0.2.0
 * Author: Engram Intelligence
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: aimee-global
 * Text Domain: aimee-engine
 */

defined('ABSPATH') || exit;

define('AIMEE_ENGINE_VERSION', '0.2.0');
define('AIMEE_ENGINE_FILE', __FILE__);
define('AIMEE_ENGINE_DIR', plugin_dir_path(__FILE__));
define('AIMEE_ENGINE_URL', plugin_dir_url(__FILE__));

require_once AIMEE_ENGINE_DIR . 'includes/settings.php';
require_once AIMEE_ENGINE_DIR . 'includes/cohort.php';
require_once AIMEE_ENGINE_DIR . 'includes/anthropic.php';
require_once AIMEE_ENGINE_DIR . 'includes/openrouter.php';
require_once AIMEE_ENGINE_DIR . 'includes/context.php';
require_once AIMEE_ENGINE_DIR . 'includes/router.php';
require_once AIMEE_ENGINE_DIR . 'includes/photos.php';
require_once AIMEE_ENGINE_DIR . 'includes/observer.php';
require_once AIMEE_ENGINE_DIR . 'includes/telemetry.php';
require_once AIMEE_ENGINE_DIR . 'includes/turn.php';
require_once AIMEE_ENGINE_DIR . 'includes/stream.php';
require_once AIMEE_ENGINE_DIR . 'includes/chat-page.php';
require_once AIMEE_ENGINE_DIR . 'includes/admin.php';

/**
 * Aimee Global functions this engine relies on. They are checked at request
 * time (never at load time) because Global loads its engine file lazily.
 */
function aimee_engine_required_global_functions() {
    return [
        'handle_aimee_message',
        'aimee_table',
        'aimee_messages_primary_key',
        'aimee_rate_limit',
        'aimee_turn_request_reserve',
        'aimee_turn_request_finish',
        'aimee_user_has_chat_access',
        'aimee_get_subscription_snapshot',
        'aimee_increment_preview_usage',
        'aimee_memory_context_for_turn',
        'aimee_calculate_intimacy_state',
        'aimee_save_relationship_state',
        'aimee_appraise_user_turn',
        'aimee_load_inner_state',
        'aimee_store_memory_from_contract',
        'aimee_store_opinion_from_contract',
        'aimee_get_eligible_private_media_catalog',
        'aimee_media_decision_store',
        'aimee_media_delivery_create',
        'aimee_media_delivery_transition',
        'aimee_private_media_payload',
        'aimee_user_image_event_resolve',
        'aimee_user_image_event_message_marker',
        'aimee_record_turn_timeline',
    ];
}

function aimee_engine_dependencies_missing() {
    $missing = [];
    foreach (aimee_engine_required_global_functions() as $function) {
        if (!function_exists($function)) $missing[] = $function;
    }
    return $missing;
}

/**
 * True when Aimee Global is present, the engine is switched on and the
 * Anthropic key is configured. Never true at plugin load time.
 */
function aimee_engine_ready() {
    if (!aimee_engine_setting('enabled')) return false;
    if (!defined('ANTHROPIC_API_KEY') || trim((string) ANTHROPIC_API_KEY) === '') return false;
    return aimee_engine_dependencies_missing() === [];
}

/**
 * Take over POST /aimee/v1/message for enrolled users.
 *
 * This uses rest_dispatch_request, which WordPress consults after the
 * route's permission callback has passed and which REPLACES the route
 * callback when a non-null result is returned. (rest_request_before_callbacks
 * only short-circuits on a WP_Error; a normal response from it is followed
 * by the original callback, which would run the legacy engine as well.)
 * Returning null leaves the request with the legacy handler untouched.
 */
function aimee_engine_intercept_rest($dispatch_result, $request, $route, $handler) {
    if ($dispatch_result !== null) return $dispatch_result;
    if (!is_object($request) || !is_a($request, 'WP_REST_Request')) return $dispatch_result;
    if ($request->get_method() !== 'POST') return $dispatch_result;
    if (rtrim((string) $request->get_route(), '/') !== '/aimee/v1/message') return $dispatch_result;
    if (!aimee_engine_ready()) return $dispatch_result;

    $user_id = get_current_user_id();
    if (!$user_id) return $dispatch_result;

    $decision = aimee_engine_route_decision_for_request($user_id, $request);
    if ($decision !== 'engine') return $dispatch_result;

    $result = aimee_engine_handle_message($request);
    if (is_wp_error($result)) return $result;
    return rest_ensure_response($result);
}
add_filter('rest_dispatch_request', 'aimee_engine_intercept_rest', 5, 4);

register_activation_hook(__FILE__, function () {
    $settings = get_option('aimee_engine_settings');
    if (!is_array($settings)) {
        add_option('aimee_engine_settings', aimee_engine_default_settings(), '', 'no');
    }
});

add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    if (function_exists('handle_aimee_message')) return;
    // Global loads its engine file lazily; only warn when Global itself is absent.
    if (function_exists('aimee_table')) return;
    echo '<div class="notice notice-warning"><p><strong>Aimee Engine</strong> is installed but Aimee Global is not active. The engine stays dormant until Aimee Global is activated.</p></div>';
});
