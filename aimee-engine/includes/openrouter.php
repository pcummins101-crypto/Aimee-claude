<?php
defined('ABSPATH') || exit;

/**
 * The explicit-content specialist models, tried in order. The engine setting
 * wins; otherwise Aimee Global's configured list is inherited so both engines
 * share one operational choice.
 */
function aimee_engine_specialist_models() {
    $configured = trim((string) aimee_engine_setting('specialist_models'));
    if ($configured !== '') {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', $configured)))));
    }
    if (function_exists('aimee_openrouter_intimacy_models')) {
        return aimee_openrouter_intimacy_models();
    }
    return ['sao10k/l3.1-70b-hanami-x1', 'sao10k/l3.3-euryale-70b'];
}

/**
 * One OpenAI-compatible chat completion via OpenRouter with model fallback.
 * $messages is a plain [{role, content}] array with the system message first.
 */
function aimee_engine_openrouter_request(array $messages, array $models, array $options = []) {
    $api_key = defined('OPENROUTER_API_KEY') ? trim((string) OPENROUTER_API_KEY) : '';
    $result = [
        'ok' => false, 'text' => '', 'model' => '', 'provider' => '',
        'status' => 0, 'error' => '', 'error_type' => '', 'latency_ms' => 0,
    ];
    if ($api_key === '') {
        $result['error'] = 'OpenRouter key missing.';
        $result['error_type'] = 'key_missing';
        return $result;
    }
    $models = array_values(array_unique(array_filter(array_map('trim', $models))));
    if (!$models) {
        $result['error'] = 'No specialist model configured.';
        $result['error_type'] = 'model_missing';
        return $result;
    }

    $body = [
        'models'      => $models,
        'messages'    => array_values($messages),
        'max_tokens'  => max(64, intval($options['max_tokens'] ?? 650)),
        'temperature' => floatval($options['temperature'] ?? 0.82),
        'top_p'       => floatval($options['top_p'] ?? 0.9),
    ];

    $started = microtime(true);
    $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
            'HTTP-Referer'  => home_url(),
            'X-Title'       => 'Aimee Engine',
        ],
        'body'        => wp_json_encode($body),
        'timeout'     => 60,
        'redirection' => 2,
    ]);
    $result['latency_ms'] = intval((microtime(true) - $started) * 1000);

    if (is_wp_error($response)) {
        $result['error'] = $response->get_error_message();
        $result['error_type'] = 'network_error';
        error_log('[Aimee Engine] openrouter network error: ' . sanitize_text_field($result['error']));
        return $result;
    }

    $status = intval(wp_remote_retrieve_response_code($response));
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    $result['status'] = $status;
    $result['model'] = (string) ($data['model'] ?? '');
    $result['provider'] = (string) ($data['provider'] ?? '');
    $content = $data['choices'][0]['message']['content'] ?? '';

    if ($status >= 200 && $status < 300 && is_string($content) && trim($content) !== '') {
        $result['ok'] = true;
        $result['text'] = trim($content);
        return $result;
    }

    $error = is_array($data) ? ($data['error'] ?? []) : [];
    $result['error'] = is_array($error) ? (string) ($error['message'] ?? 'No text returned.') : 'No text returned.';
    $result['error_type'] = sanitize_key((string) (
        (is_array($error) ? ($error['metadata']['error_type'] ?? $error['type'] ?? '') : '')
        ?: ($status ? 'http_' . $status : 'invalid_response')
    ));
    error_log(sprintf(
        '[Aimee Engine] openrouter status=%d type=%s message=%s',
        $status,
        $result['error_type'],
        sanitize_text_field(mb_substr($result['error'], 0, 240))
    ));
    return $result;
}
