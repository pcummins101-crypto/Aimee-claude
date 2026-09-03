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

    if (intval($http_status) === 0) {
        $result['error'] = 'No response from the provider.';
        $result['error_type'] = 'network_error';
        return $result;
    }
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

/**
 * Incremental parser for Anthropic's server-sent event stream. Feed it raw
 * chunks; it assembles the same shape aimee_engine_anthropic_normalise()
 * returns and reports text as it arrives.
 */
function aimee_engine_sse_parser_create() {
    return [
        'buffer'      => '',
        'blocks'      => [],
        'model'       => '',
        'stop_reason' => '',
        'stop_details'=> [],
        'usage'       => [],
        'error'       => '',
        'error_type'  => '',
    ];
}

/**
 * Returns the text emitted by this chunk (may be '').
 */
function aimee_engine_sse_parser_feed(array &$state, $chunk) {
    $state['buffer'] .= (string) $chunk;
    $emitted = '';
    $event = '';

    while (($pos = strpos($state['buffer'], "\n")) !== false) {
        $line = rtrim(substr($state['buffer'], 0, $pos), "\r");
        $state['buffer'] = substr($state['buffer'], $pos + 1);

        if ($line === '') { $event = ''; continue; }
        if (strpos($line, 'event:') === 0) { $event = trim(substr($line, 6)); continue; }
        if (strpos($line, 'data:') !== 0) continue;

        $data = json_decode(trim(substr($line, 5)), true);
        if (!is_array($data)) continue;
        $type = (string) ($data['type'] ?? $event);

        switch ($type) {
            case 'message_start':
                $message = is_array($data['message'] ?? null) ? $data['message'] : [];
                $state['model'] = (string) ($message['model'] ?? '');
                $state['usage'] = is_array($message['usage'] ?? null) ? $message['usage'] : [];
                break;
            case 'content_block_start':
                $index = intval($data['index'] ?? count($state['blocks']));
                $block = is_array($data['content_block'] ?? null) ? $data['content_block'] : ['type' => 'text'];
                $btype = (string) ($block['type'] ?? 'text');
                $entry = ['type' => $btype];
                if ($btype === 'text') $entry['text'] = (string) ($block['text'] ?? '');
                if ($btype === 'tool_use') { $entry['id'] = (string) ($block['id'] ?? ''); $entry['name'] = (string) ($block['name'] ?? ''); $entry['input'] = []; $entry['_json'] = ''; }
                if ($btype === 'thinking') { $entry['thinking'] = (string) ($block['thinking'] ?? ''); $entry['signature'] = (string) ($block['signature'] ?? ''); }
                if ($btype === 'redacted_thinking') { $entry['data'] = (string) ($block['data'] ?? ''); }
                $state['blocks'][$index] = $entry;
                break;
            case 'content_block_delta':
                $index = intval($data['index'] ?? 0);
                if (!isset($state['blocks'][$index])) $state['blocks'][$index] = ['type' => 'text', 'text' => ''];
                $delta = is_array($data['delta'] ?? null) ? $data['delta'] : [];
                $dtype = (string) ($delta['type'] ?? '');
                if ($dtype === 'text_delta') {
                    $text = (string) ($delta['text'] ?? '');
                    $state['blocks'][$index]['text'] = ($state['blocks'][$index]['text'] ?? '') . $text;
                    $emitted .= $text;
                } elseif ($dtype === 'input_json_delta') {
                    $state['blocks'][$index]['_json'] = ($state['blocks'][$index]['_json'] ?? '') . (string) ($delta['partial_json'] ?? '');
                } elseif ($dtype === 'thinking_delta') {
                    $state['blocks'][$index]['thinking'] = ($state['blocks'][$index]['thinking'] ?? '') . (string) ($delta['thinking'] ?? '');
                } elseif ($dtype === 'signature_delta') {
                    $state['blocks'][$index]['signature'] = (string) ($delta['signature'] ?? '');
                }
                break;
            case 'content_block_stop':
                $index = intval($data['index'] ?? 0);
                if (isset($state['blocks'][$index]) && ($state['blocks'][$index]['type'] ?? '') === 'tool_use') {
                    $decoded = json_decode((string) ($state['blocks'][$index]['_json'] ?? ''), true);
                    $state['blocks'][$index]['input'] = is_array($decoded) ? $decoded : [];
                    unset($state['blocks'][$index]['_json']);
                }
                break;
            case 'message_delta':
                $delta = is_array($data['delta'] ?? null) ? $data['delta'] : [];
                if (!empty($delta['stop_reason'])) $state['stop_reason'] = (string) $delta['stop_reason'];
                if (is_array($delta['stop_details'] ?? null)) $state['stop_details'] = $delta['stop_details'];
                if (is_array($data['usage'] ?? null)) $state['usage'] = array_merge($state['usage'], $data['usage']);
                break;
            case 'error':
                $error = is_array($data['error'] ?? null) ? $data['error'] : [];
                $state['error'] = (string) ($error['message'] ?? 'Stream error.');
                $state['error_type'] = (string) ($error['type'] ?? 'stream_error');
                break;
            default:
                break;
        }
    }
    return $emitted;
}

function aimee_engine_sse_parser_result(array $state, $http_status = 200, $latency_ms = 0) {
    $result = aimee_engine_anthropic_normalise(0, '{}', $latency_ms);
    $result['status'] = intval($http_status);
    if ($state['error'] !== '') {
        $result['error'] = $state['error'];
        $result['error_type'] = $state['error_type'];
        return $result;
    }
    if (intval($http_status) >= 400 || intval($http_status) === 0) {
        $decoded = json_decode($state['buffer'], true);
        $result['error'] = (string) ($decoded['error']['message'] ?? 'Stream failed.');
        $result['error_type'] = (string) ($decoded['error']['type'] ?? ('http_' . intval($http_status)));
        return $result;
    }
    ksort($state['blocks']);
    $result['content'] = array_values(array_map(function ($block) { unset($block['_json']); return $block; }, $state['blocks']));
    $result['model'] = $state['model'];
    $result['usage'] = $state['usage'];
    $result['stop_reason'] = $state['stop_reason'];
    $result['ok'] = true;
    if ($state['stop_reason'] === 'refusal') {
        $result['refusal_category'] = (string) ($state['stop_details']['category'] ?? 'unspecified');
        return $result;
    }
    foreach ($result['content'] as $block) {
        if (($block['type'] ?? '') === 'text') $result['text'] .= (string) ($block['text'] ?? '');
        if (($block['type'] ?? '') === 'tool_use') $result['tool_uses'][] = ['id' => $block['id'], 'name' => $block['name'], 'input' => $block['input']];
    }
    $result['text'] = trim($result['text']);
    return $result;
}

function aimee_engine_streaming_available() {
    return function_exists('curl_multi_init') && function_exists('curl_init');
}

/**
 * Stream one Messages API request while optionally running other requests
 * in the same multi handle.
 *
 * $on_text($text)                  called with each text delta of the primary
 * $on_extra($key, $result)         called when an extra finishes; return false
 *                                  to abort the primary stream immediately
 *
 * Returns ['primary' => result, 'extras' => [key => result], 'aborted' => bool].
 */
function aimee_engine_anthropic_stream(array $body, callable $on_text, array $extras = [], $on_extra = null, $timeout = 120) {
    $api_key = defined('ANTHROPIC_API_KEY') ? trim((string) ANTHROPIC_API_KEY) : '';
    $out = ['primary' => null, 'extras' => [], 'aborted' => false];

    if ($api_key === '' || !aimee_engine_streaming_available()) {
        // Degrade to the non-streaming path and deliver the whole text at once.
        $results = aimee_engine_anthropic_request_multiple(array_merge(['__primary' => $body], $extras), $timeout);
        $out['primary'] = $results['__primary'];
        foreach ($extras as $key => $unused) {
            $out['extras'][$key] = $results[$key];
            if (is_callable($on_extra) && $on_extra($key, $results[$key]) === false) $out['aborted'] = true;
        }
        if (!$out['aborted'] && !empty($out['primary']['ok']) && $out['primary']['text'] !== '') $on_text($out['primary']['text']);
        return $out;
    }

    $headers = [
        'x-api-key: ' . $api_key,
        'anthropic-version: 2023-06-01',
        'content-type: application/json',
        'accept: text/event-stream',
    ];
    $multi = curl_multi_init();
    $handles = [];
    $states = [];
    $started = microtime(true);

    $body['stream'] = true;
    $parser = aimee_engine_sse_parser_create();
    $primary = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($primary, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => wp_json_encode($body),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_TIMEOUT        => max(15, intval($timeout)),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$parser, $on_text) {
            $text = aimee_engine_sse_parser_feed($parser, $chunk);
            if ($text !== '') $on_text($text);
            return strlen($chunk);
        },
    ]);
    curl_multi_add_handle($multi, $primary);
    $handles['__primary'] = $primary;

    foreach ($extras as $key => $extra_body) {
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => wp_json_encode($extra_body),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => max(15, intval($timeout)),
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        curl_multi_add_handle($multi, $ch);
        $handles[$key] = $ch;
    }

    $primary_done = false;
    do {
        $status = curl_multi_exec($multi, $running);
        if ($status === CURLM_OK) curl_multi_select($multi, 0.2);
        while (($info = curl_multi_info_read($multi)) !== false) {
            $done = $info['handle'];
            $key = array_search($done, $handles, true);
            if ($key === false) continue;
            if ($key === '__primary') {
                $primary_done = true;
                continue;
            }
            $http = intval(curl_getinfo($done, CURLINFO_RESPONSE_CODE));
            $raw = curl_multi_getcontent($done);
            $latency = intval((microtime(true) - $started) * 1000);
            $result = $info['result'] === CURLE_OK
                ? aimee_engine_anthropic_normalise($http, $raw, $latency)
                : aimee_engine_anthropic_normalise(0, '{}', $latency);
            if ($info['result'] !== CURLE_OK) {
                $result['error'] = curl_error($done);
                $result['error_type'] = 'network_error';
            }
            $out['extras'][$key] = $result;
            curl_multi_remove_handle($multi, $done);
            curl_close($done);
            unset($handles[$key]);
            if (is_callable($on_extra) && $on_extra($key, $result) === false && !$primary_done) {
                $out['aborted'] = true;
                curl_multi_remove_handle($multi, $primary);
                curl_close($primary);
                unset($handles['__primary']);
                $running = count($handles) > 0 ? $running : 0;
            }
        }
    } while ($running > 0 && $status === CURLM_OK);

    $latency = intval((microtime(true) - $started) * 1000);
    if (isset($handles['__primary'])) {
        $http = intval(curl_getinfo($primary, CURLINFO_RESPONSE_CODE));
        $curl_error = curl_error($primary);
        curl_multi_remove_handle($multi, $primary);
        curl_close($primary);
        $out['primary'] = aimee_engine_sse_parser_result($parser, $http, $latency);
        if ($curl_error !== '' && !$out['primary']['ok']) {
            $out['primary']['error'] = $curl_error;
            $out['primary']['error_type'] = 'network_error';
        }
    } else {
        $out['primary'] = aimee_engine_anthropic_normalise(0, '{}', $latency);
        $out['primary']['error'] = 'Stream aborted.';
        $out['primary']['error_type'] = 'aborted';
    }
    foreach ($handles as $key => $ch) {
        if ($key === '__primary') continue;
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
        $out['extras'][$key] = aimee_engine_anthropic_normalise(0, '{}', $latency);
        $out['extras'][$key]['error_type'] = 'incomplete';
    }
    curl_multi_close($multi);

    if (!empty($out['primary']['ok']) === false && !empty($out['primary']['error'])) {
        error_log('[Aimee Engine] anthropic stream error type=' . sanitize_text_field($out['primary']['error_type']) . ' message=' . sanitize_text_field(mb_substr($out['primary']['error'], 0, 200)));
    }
    return $out;
}
