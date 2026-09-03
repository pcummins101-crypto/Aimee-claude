<?php
defined('ABSPATH') || exit;

/**
 * Streaming chat endpoint. Same request body as /aimee/v1/message; the
 * response is a server-sent event stream:
 *
 *   event: status   data: {"state":"thinking"|"writing"|"photo"}
 *   event: delta    data: {"text":"..."}          appended to the reply
 *   event: replace  data: {"text":"..."}          the reply so far is replaced
 *   event: done     data: {...same JSON as /message...}
 *   event: error    data: {"message":"...","status":"..."}
 */
add_action('rest_api_init', function () {
    register_rest_route('aimee-engine/v1', '/stream', [
        'methods'             => 'POST',
        'callback'            => 'aimee_engine_stream_endpoint',
        'permission_callback' => function () {
            return is_user_logged_in();
        },
    ]);
});

function aimee_engine_sse_write($event, array $data) {
    echo 'event: ' . sanitize_key($event) . "\n";
    echo 'data: ' . wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    if (function_exists('ob_get_level')) {
        while (ob_get_level() > 0) { @ob_end_flush(); }
    }
    @flush();
}

function aimee_engine_stream_endpoint(WP_REST_Request $request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('authentication_required', 'Authentication required.', ['status' => 401]);
    if (!aimee_engine_ready() || aimee_engine_route_decision_for_request($user_id, $request) !== 'engine') {
        // Not enrolled: answer with the legacy handler as a single JSON body
        // so a stale client still gets a reply.
        $result = handle_aimee_message($request);
        return $result;
    }

    // Take over the response. WordPress has queued JSON headers; replace them.
    if (function_exists('set_time_limit')) @set_time_limit(180);
    ignore_user_abort(true);
    nocache_headers();
    header('Content-Type: text/event-stream; charset=utf-8', true);
    header('Cache-Control: no-cache, no-transform', true);
    header('X-Accel-Buffering: no', true);
    header('Connection: keep-alive', true);
    if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');
    while (ob_get_level() > 0) { @ob_end_flush(); }
    // A little padding defeats proxies that hold the first bytes.
    echo ':' . str_repeat(' ', 2048) . "\n\n";
    @flush();

    aimee_engine_sse_write('status', ['state' => 'thinking']);

    $result = aimee_engine_handle_message($request, 'aimee_engine_sse_write');

    if (is_wp_error($result)) {
        $data = $result->get_error_data();
        aimee_engine_sse_write('error', [
            'status'  => $result->get_error_code(),
            'message' => $result->get_error_message(),
            'code'    => intval(is_array($data) ? ($data['status'] ?? 500) : 500),
        ]);
    } else {
        $payload = $result instanceof WP_REST_Response ? $result->get_data() : (array) $result;
        $http = $result instanceof WP_REST_Response ? intval($result->get_status()) : 200;
        if ($http >= 400) {
            aimee_engine_sse_write('error', ['status' => (string) ($payload['status'] ?? 'error'), 'message' => (string) ($payload['message'] ?? 'Request failed.'), 'code' => $http]);
        } else {
            aimee_engine_sse_write('done', is_array($payload) ? $payload : []);
        }
    }
    exit;
}
