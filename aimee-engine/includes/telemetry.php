<?php
defined('ABSPATH') || exit;

/**
 * Route telemetry without message content, in Aimee Global's analytics table
 * so the two engines can be compared in one place.
 */
function aimee_engine_record_event($user_id, $event_name, array $props, $page_path = 'app') {
    global $wpdb;
    if (!function_exists('aimee_table')) return;
    $now = current_time('mysql', true);
    $wpdb->insert(aimee_table('aimee_analytics_events'), [
        'user_id'     => intval($user_id),
        'event_name'  => sanitize_key($event_name),
        'properties'  => wp_json_encode($props),
        'page_path'   => sanitize_text_field($page_path),
        'occurred_at' => $now,
        'created_at'  => $now,
    ]);
}

function aimee_engine_record_turn($user_id, array $props) {
    $props['engine_version'] = AIMEE_ENGINE_VERSION;
    aimee_engine_record_event($user_id, 'engine_v2_turn', $props);

    if (aimee_engine_setting('debug_log')) {
        $recent = get_option('aimee_engine_recent_turns');
        $recent = is_array($recent) ? $recent : [];
        array_unshift($recent, array_merge(['user_id' => intval($user_id), 'at' => current_time('mysql', true)], $props));
        update_option('aimee_engine_recent_turns', array_slice($recent, 0, 40), false);
    }
}

function aimee_engine_recent_turns() {
    $recent = get_option('aimee_engine_recent_turns');
    return is_array($recent) ? $recent : [];
}
