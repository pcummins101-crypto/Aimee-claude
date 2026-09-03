<?php
defined('ABSPATH') || exit;

/**
 * Models that accept output_config.effort. Older models reject it.
 */
function aimee_engine_model_supports_effort($model) {
    return (bool) preg_match(
        '/(opus-5|opus-4-[678]|sonnet-5|sonnet-4-6|fable|mythos)/i',
        (string) $model
    );
}

/**
 * Build a Messages API request body. Thinking is never sent: Opus 5 and
 * Fable run adaptive thinking by default and the effort setting controls
 * depth; Sonnet 5 and Haiku run fast without it.
 *
 * $options: max_tokens, effort, tools (array), output_schema (array)
 */
function aimee_engine_anthropic_build_body($model, array $system_blocks, array $messages, array $options = []) {
    $body = [
        'model'      => (string) $model,
        'max_tokens' => max(64, intval($options['max_tokens'] ?? 1024)),
        'messages'   => array_values($messages),
    ];

    $system_blocks = array_values(array_filter($system_blocks, function ($block) {
        return is_array($block) && trim((string) ($block['text'] ?? '')) !== '';
    }));
    if ($system_blocks) $body['system'] = $system_blocks;

    if (!empty($options['tools']) && is_array($options['tools'])) {
        $body['tools'] = array_values($options['tools']);
        $body['tool_choice'] = ['type' => 'auto'];
    }

    $output_config = [];
    $effort = strtolower(trim((string) ($options['effort'] ?? '')));
    if ($effort !== '' && aimee_engine_model_supports_effort($model)) {
        if (in_array($effort, ['low', 'medium', 'high'], true)) {
            $output_config['effort'] = $effort;
        }
    }
    if (!empty($options['output_schema']) && is_array($options['output_schema'])) {
        $output_config['format'] = [
            'type'   => 'json_schema',
            'schema' => $options['output_schema'],
        ];
    }
    if ($output_config) $body['output_config'] = $output_config;

    return $body;
}

/**
 * Normalise a raw Messages API response into a flat result. Never throws.
 */
function aimee_engine_anthropic_normalise($http_status, $raw_body, $latency_ms = 0) {
    $result = [
        'ok'               => false,
        'status'           => intval($http_status),
        'error'            => '',
        'error_type'       => '',
        'stop_reason'      => '',
        'refusal_category' => '',
        'text'             => '',
        'tool_uses'        => [],
        'content'          => [],
        'usage'            => [],
        'model'            => '',
        'latency_ms'       => intval($latency_ms),
    ];

    $data = json_decode((string) $raw_body, true);
    if (!is_array($data)) {
        $result['error'] = 'Provider returned an unreadable response.';
        $result['error_type'] = 'invalid_json';
        return $result;
    }

    if (intval($http_status) >= 400 || (($data['type'] ?? '') === 'error')) {
        $result['error'] = (string) ($data['error']['message'] ?? 'Unknown provider error.');
        $result['error_type'] = (string) ($data['error']['type'] ?? ('http_' . intval($http_status)));
        return $result;
    }

    $result['model'] = (string) ($data['model'] ?? '');
    $result['stop_reason'] = (string) ($data['stop_reason'] ?? '');
    $result['usage'] = is_array($data['usage'] ?? null) ? $data['usage'] : [];
    $result['content'] = is_array($data['content'] ?? null) ? $data['content'] : [];

    if ($result['stop_reason'] === 'refusal') {
        $details = is_array($data['stop_details'] ?? null) ? $data['stop_details'] : [];
        $result['refusal_category'] = (string) ($details['category'] ?? 'unspecified');
        $result['ok'] = true;
        return $result;
    }

    foreach ($result['content'] as $block) {
        if (!is_array($block)) continue;
        $type = (string) ($block['type'] ?? '');
        if ($type === 'text') {
            $result['text'] .= (string) ($block['text'] ?? '');
        } elseif ($type === 'tool_use') {
            $result['tool_uses'][] = [
                'id'    => (string) ($block['id'] ?? ''),
                'name'  => (string) ($block['name'] ?? ''),
                'input' => is_array($block['input'] ?? null) ? $block['input'] : [],
            ];
        }
    }
    $result['text'] = trim($result['text']);
    $result['ok'] = true;
    return $result;
}

/**
 * Send one Messages API request. Returns the normalised shape above.
 */
function aimee_engine_anthropic_request(array $body, $timeout = 90) {
    $api_key = defined('ANTHROPIC_API_KEY') ? trim((string) ANTHROPIC_API_KEY) : '';
    if ($api_key === '') {
        $result = aimee_engine_anthropic_normalise(0, '{}');
        $result['error'] = 'Anthropic API key is missing.';
        $result['error_type'] = 'key_missing';
        return $result;
    }

    $started = microtime(true);
    $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
        'headers' => [
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ],
        'body'    => wp_json_encode($body),
        'timeout' => max(15, intval($timeout)),
    ]);
    $latency = intval((microtime(true) - $started) * 1000);

    if (is_wp_error($response)) {
        $result = aimee_engine_anthropic_normalise(0, '{}', $latency);
        $result['error'] = $response->get_error_message();
        $result['error_type'] = 'network_error';
        error_log('[Aimee Engine] anthropic network error: ' . sanitize_text_field($result['error']));
        return $result;
    }

    $status = intval(wp_remote_retrieve_response_code($response));
    $result = aimee_engine_anthropic_normalise($status, wp_remote_retrieve_body($response), $latency);
    if (!$result['ok']) {
        error_log(sprintf(
            '[Aimee Engine] anthropic error status=%d type=%s message=%s model=%s',
            $status,
            sanitize_text_field($result['error_type']),
            sanitize_text_field(mb_substr($result['error'], 0, 240)),
            sanitize_text_field((string) ($body['model'] ?? ''))
        ));
    }
    return $result;
}

/**
 * Tolerant JSON extraction for models that wrap output in prose or fences.
 */
function aimee_engine_extract_json($text) {
    $text = trim((string) $text);
    if ($text === '') return null;
    $decoded = json_decode($text, true);
    if (is_array($decoded)) return $decoded;

    if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/su', $text, $m)) {
        $decoded = json_decode($m[1], true);
        if (is_array($decoded)) return $decoded;
    }
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
        if (is_array($decoded)) return $decoded;
    }
    return null;
}

/**
 * Send several Messages API requests concurrently using the Requests library
 * WordPress ships with. Falls back to sequential calls when it is missing.
 * Returns normalised results in the same order as $bodies.
 */
function aimee_engine_anthropic_request_multiple(array $bodies, $timeout = 90) {
    $api_key = defined('ANTHROPIC_API_KEY') ? trim((string) ANTHROPIC_API_KEY) : '';
    $class = class_exists('\\WpOrg\\Requests\\Requests') ? '\\WpOrg\\Requests\\Requests'
        : (class_exists('Requests') ? 'Requests' : '');

    if ($api_key === '' || $class === '' || count($bodies) < 2) {
        $results = [];
        foreach ($bodies as $index => $body) $results[$index] = aimee_engine_anthropic_request($body, $timeout);
        return $results;
    }

    $requests = [];
    foreach ($bodies as $index => $body) {
        $requests[$index] = [
            'url'     => 'https://api.anthropic.com/v1/messages',
            'type'    => 'POST',
            'headers' => [
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'data'    => wp_json_encode($body),
            'options' => ['timeout' => max(15, intval($timeout)), 'connect_timeout' => 10],
        ];
    }

    $started = microtime(true);
    try {
        $responses = $class::request_multiple($requests, ['timeout' => max(15, intval($timeout))]);
    } catch (Exception $e) {
        $results = [];
        foreach ($bodies as $index => $body) $results[$index] = aimee_engine_anthropic_request($body, $timeout);
        return $results;
    }
    $latency = intval((microtime(true) - $started) * 1000);

    $results = [];
    foreach ($bodies as $index => $body) {
        $response = $responses[$index] ?? null;
        if (is_object($response) && isset($response->status_code) && isset($response->body)) {
            $results[$index] = aimee_engine_anthropic_normalise(intval($response->status_code), (string) $response->body, $latency);
        } else {
            $message = is_object($response) && method_exists($response, 'getMessage') ? $response->getMessage() : 'No response.';
            $results[$index] = aimee_engine_anthropic_normalise(0, '{}', $latency);
            $results[$index]['error'] = $message;
            $results[$index]['error_type'] = 'network_error';
            error_log('[Aimee Engine] anthropic parallel request failed: ' . sanitize_text_field($message));
        }
        if (!$results[$index]['ok']) {
            error_log(sprintf('[Aimee Engine] anthropic error status=%d type=%s model=%s', $results[$index]['status'], sanitize_text_field($results[$index]['error_type']), sanitize_text_field((string) ($body['model'] ?? ''))));
        }
    }
    return $results;
}
