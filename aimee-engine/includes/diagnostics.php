<?php
defined('ABSPATH') || exit;

/**
 * Bank checkout diagnostics.
 *
 * Aimee Global's GoCardless checkout reports a failed create as "ambiguous"
 * and discards the provider's own error. Every WordPress HTTP call passes
 * through http_api_debug, so the engine records GoCardless failures there:
 * status, error type, field messages and the request path. Never the token,
 * never bank details, never the request body. Shown on the engine settings
 * page, and appended to the checkout response for administrators.
 */
function aimee_engine_gc_is_provider_url($url) {
    $host = strtolower((string) wp_parse_url((string) $url, PHP_URL_HOST));
    return in_array($host, ['api.gocardless.com', 'api-sandbox.gocardless.com'], true);
}

function aimee_engine_gc_summarise_error($response, $url, $args) {
    $summary = [
        'at'      => current_time('mysql', true),
        'method'  => strtoupper((string) ($args['method'] ?? 'GET')),
        'path'    => (string) wp_parse_url((string) $url, PHP_URL_PATH),
        'status'  => 0,
        'type'    => '',
        'message' => '',
        'fields'  => [],
        'user_id' => get_current_user_id(),
    ];

    if (is_wp_error($response)) {
        $summary['type'] = 'transport';
        $summary['message'] = sanitize_text_field($response->get_error_message());
        return $summary;
    }

    $status = intval(wp_remote_retrieve_response_code($response));
    if ($status < 400) return null;
    $summary['status'] = $status;

    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    $error = is_array($data) && is_array($data['error'] ?? null) ? $data['error'] : [];
    $summary['type'] = sanitize_text_field((string) ($error['type'] ?? ('http_' . $status)));
    $summary['message'] = sanitize_text_field(mb_substr((string) ($error['message'] ?? 'GoCardless request failed.'), 0, 300));
    foreach ((array) ($error['errors'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $field = sanitize_text_field((string) ($item['field'] ?? ($item['request_pointer'] ?? '')));
        $reason = sanitize_text_field((string) ($item['reason'] ?? ''));
        $text = sanitize_text_field(mb_substr((string) ($item['message'] ?? ''), 0, 200));
        $summary['fields'][] = trim($field . ($reason !== '' ? ' [' . $reason . ']' : '') . ': ' . $text, ': ');
        if (count($summary['fields']) >= 8) break;
    }
    return $summary;
}

function aimee_engine_gc_record($summary) {
    if (!is_array($summary)) return;
    $log = get_option('aimee_engine_gc_diagnostics');
    $log = is_array($log) ? $log : [];
    array_unshift($log, $summary);
    update_option('aimee_engine_gc_diagnostics', array_slice($log, 0, 20), false);
    $GLOBALS['aimee_engine_gc_last'] = $summary;
    error_log(sprintf(
        '[Aimee Engine] gocardless %s %s -> %d %s: %s%s',
        $summary['method'],
        $summary['path'],
        $summary['status'],
        $summary['type'],
        $summary['message'],
        $summary['fields'] ? ' | ' . implode(' | ', $summary['fields']) : ''
    ));
}

add_action('http_api_debug', function ($response, $context, $class, $args, $url) {
    if (!aimee_engine_gc_is_provider_url($url)) return;
    aimee_engine_gc_record(aimee_engine_gc_summarise_error($response, $url, is_array($args) ? $args : []));
}, 10, 5);

function aimee_engine_gc_diagnostics() {
    $log = get_option('aimee_engine_gc_diagnostics');
    return is_array($log) ? $log : [];
}

/**
 * For administrators only, append the provider error captured during this
 * request to a failed checkout response so the reason is visible in chat.
 */
add_filter('rest_post_dispatch', function ($response, $server, $request) {
    if (!($response instanceof WP_REST_Response)) return $response;
    if (!is_object($request) || strpos((string) $request->get_route(), '/aimee/v1/subscription') !== 0) return $response;
    if (empty($GLOBALS['aimee_engine_gc_last']) || !current_user_can('manage_options')) return $response;
    $data = $response->get_data();
    if (!is_array($data)) return $response;
    $last = $GLOBALS['aimee_engine_gc_last'];
    $data['diagnostic'] = trim(sprintf(
        'GoCardless %s %s returned %s %s: %s%s',
        $last['method'],
        $last['path'],
        $last['status'] ?: 'no response',
        $last['type'],
        $last['message'],
        $last['fields'] ? ' (' . implode('; ', $last['fields']) . ')' : ''
    ));
    $response->set_data($data);
    return $response;
}, 20, 3);

add_action('admin_post_aimee_engine_clear_gc_diagnostics', function () {
    if (!current_user_can('manage_options')) wp_die('Not allowed.');
    check_admin_referer('aimee_engine_clear_gc');
    delete_option('aimee_engine_gc_diagnostics');
    wp_safe_redirect(add_query_arg(['page' => 'aimee-engine', 'cleared' => '1'], admin_url('options-general.php')));
    exit;
});
