<?php
defined('ABSPATH') || exit;

/**
 * Pure cohort evaluation. Returns 'engine' or 'legacy'.
 *
 * $mode        allowlist | all
 * $allowlist   array of user IDs
 * $user_flag   '' (unset), '1' (force engine) or '0' (force legacy) from user meta
 */
function aimee_engine_evaluate_cohort($user_id, $mode, array $allowlist, $user_flag) {
    $user_id = intval($user_id);
    $user_flag = (string) $user_flag;
    if ($user_id <= 0) return 'legacy';
    if ($user_flag === '0') return 'legacy';
    if ($user_flag === '1') return 'engine';
    if ($mode === 'all') return 'engine';
    return in_array($user_id, array_map('intval', $allowlist), true) ? 'engine' : 'legacy';
}

function aimee_engine_user_flag($user_id) {
    $flag = get_user_meta(intval($user_id), 'aimee_engine_v2', true);
    return in_array((string) $flag, ['0', '1'], true) ? (string) $flag : '';
}

function aimee_engine_allowlist() {
    $raw = (string) aimee_engine_setting('allowlist');
    return array_values(array_filter(array_map('intval', preg_split('/[\s,]+/', $raw))));
}

/**
 * Decide the engine for one request. The colleague persona (Georgia) always
 * stays on the legacy engine because it has its own prompt family. An
 * administrator can force either engine for a single request with the
 * X-Aimee-Engine header, which makes side-by-side comparison easy.
 */
function aimee_engine_route_decision_for_request($user_id, $request) {
    global $wpdb;

    $override = '';
    if (is_object($request) && method_exists($request, 'get_header')) {
        $override = strtolower(trim((string) $request->get_header('X-Aimee-Engine')));
    }
    if ($override !== '' && current_user_can('manage_options')) {
        if ($override === 'legacy') return 'legacy';
        if (in_array($override, ['engine', 'v2', 'new'], true)) return 'engine';
    }

    if (function_exists('aimee_is_colleague_user')) {
        $profile = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . aimee_table('aimee_user_profiles') . " WHERE user_id = %d",
            intval($user_id)
        ));
        if ($profile && aimee_is_colleague_user($profile)) return 'legacy';
    }

    return aimee_engine_evaluate_cohort(
        $user_id,
        (string) aimee_engine_setting('cohort_mode'),
        aimee_engine_allowlist(),
        aimee_engine_user_flag($user_id)
    );
}
