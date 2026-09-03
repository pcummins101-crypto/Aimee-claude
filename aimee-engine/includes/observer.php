<?php
defined('ABSPATH') || exit;

/**
 * The observer is the bookkeeping half of the old JSON contract, moved out
 * of the conversation. After a turn it reads the exchange and writes memory,
 * opinions and a self-observation into Aimee Global's existing tables.
 */
function aimee_engine_observer_schema() {
    return [
        'type' => 'object',
        'properties' => [
            'memories' => [
                'type'  => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'operation'        => ['type' => 'string', 'enum' => ['upsert', 'forget']],
                        'key'              => ['type' => 'string'],
                        'fact'             => ['type' => 'string'],
                        'domain'           => ['type' => 'string', 'enum' => ['user_fact', 'life_event', 'user_preference', 'current_context']],
                        'emotional_weight' => ['type' => 'integer'],
                    ],
                    'required' => ['operation', 'key', 'fact', 'domain', 'emotional_weight'],
                    'additionalProperties' => false,
                ],
            ],
            'opinion' => [
                'type' => 'object',
                'properties' => [
                    'topic'    => ['type' => 'string'],
                    'stance'   => ['type' => 'string'],
                    'reason'   => ['type' => 'string'],
                    'strength' => ['type' => 'integer'],
                ],
                'required' => ['topic', 'stance', 'reason', 'strength'],
                'additionalProperties' => false,
            ],
            'self_observation' => ['type' => 'string'],
        ],
        'required' => ['memories', 'opinion', 'self_observation'],
        'additionalProperties' => false,
    ];
}

function aimee_engine_observer_prompt(array $existing_keys) {
    $keys = $existing_keys ? implode(', ', array_slice($existing_keys, 0, 60)) : '(none yet)';
    return "You keep the private memory of Aimee, an AI companion, about one person she talks to. You will see recent context and the newest exchange. Decide what, if anything, should be written down so Aimee remembers it next time.\n\n"
        . "Record only durable, useful things: facts about him (name, work, family, pets, places), preferences, life events, plans and dates, things he asked her to remember, and the current thread of what is going on in his life (domain current_context, short-lived). Do not record small talk, do not record the sexual content of an exchange, and never record passwords, card numbers or codes.\n\n"
        . "Prefer updating an existing key over creating a near-duplicate. Keys are short snake_case topics like job, dog_name, mum_health. Existing keys: {$keys}.\n"
        . "emotional_weight is 0-10 (10 = deeply important to him). Return at most four memories; an empty list is the normal outcome for an ordinary exchange.\n\n"
        . "opinion: only if Aimee formed or voiced a genuine opinion of her own in this exchange (strength 0-100). Otherwise leave topic empty.\n"
        . "self_observation: one sentence in Aimee's own voice about how she was in this exchange, or empty.";
}

function aimee_engine_existing_memory_keys($user_id) {
    global $wpdb;
    $keys = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT memory_key FROM " . aimee_table('aimee_long_term_memory') . "
         WHERE user_id = %d AND memory_key IS NOT NULL AND memory_key <> ''
           AND (valid_until IS NULL OR valid_until > UTC_TIMESTAMP())
         ORDER BY COALESCE(updated_at, created_at) DESC
         LIMIT 60",
        intval($user_id)
    ));
    return array_values(array_filter(array_map('strval', (array) $keys)));
}

function aimee_engine_schedule_observer($user_id, $user_message_id, $aimee_message_id) {
    $mode = (string) aimee_engine_setting('observer_mode');
    if ($mode === 'off') return 'off';
    $args = [intval($user_id), intval($user_message_id), intval($aimee_message_id)];
    if ($mode === 'inline') {
        aimee_engine_observe_turn(...$args);
        return 'inline';
    }
    if (!wp_next_scheduled('aimee_engine_observe_turn', $args)) {
        wp_schedule_single_event(time() + 3, 'aimee_engine_observe_turn', $args);
    }
    return 'async';
}
add_action('aimee_engine_observe_turn', 'aimee_engine_observe_turn', 10, 3);

/**
 * Apply an observation to Aimee Global's stores. Pure with respect to the
 * model: it takes the decoded JSON and returns what was written.
 */
function aimee_engine_apply_observation($user_id, array $data, $user_message_id = 0) {
    $summary = ['memories' => 0, 'forgotten' => 0, 'opinion' => false, 'self_observation' => false];
    $memories = is_array($data['memories'] ?? null) ? array_slice($data['memories'], 0, 4) : [];

    foreach ($memories as $memory) {
        if (!is_array($memory)) continue;
        $operation = sanitize_key((string) ($memory['operation'] ?? 'upsert'));
        $fact = trim((string) ($memory['fact'] ?? ''));
        if ($operation === 'upsert' && $fact === '') continue;
        if (!function_exists('aimee_store_memory_from_contract')) continue;
        $result = aimee_store_memory_from_contract($user_id, [
            'memory_operation' => $operation === 'forget' ? 'forget' : 'upsert',
            'memory_key'       => (string) ($memory['key'] ?? ''),
            'memory_to_save'   => $fact,
            'memory_domain'    => (string) ($memory['domain'] ?? 'user_fact'),
            'emotional_weight' => intval($memory['emotional_weight'] ?? 0),
        ], intval($user_message_id));
        if (!empty($result['stored'])) {
            if (($result['reason'] ?? '') === 'forgotten') $summary['forgotten']++;
            else $summary['memories']++;
        }
    }

    $opinion = is_array($data['opinion'] ?? null) ? $data['opinion'] : [];
    if (trim((string) ($opinion['topic'] ?? '')) !== '' && function_exists('aimee_store_opinion_from_contract')) {
        $summary['opinion'] = (bool) aimee_store_opinion_from_contract($user_id, [
            'opinion_topic'    => (string) ($opinion['topic'] ?? ''),
            'opinion_stance'   => (string) ($opinion['stance'] ?? ''),
            'opinion_reason'   => (string) ($opinion['reason'] ?? ''),
            'opinion_strength' => intval($opinion['strength'] ?? 0),
        ]);
    }

    $self = trim((string) ($data['self_observation'] ?? ''));
    if ($self !== '' && function_exists('aimee_load_inner_state') && function_exists('aimee_save_inner_state')) {
        $state = aimee_load_inner_state($user_id);
        if (is_array($state)) {
            $state['self_observation'] = mb_substr(sanitize_text_field($self), 0, 255);
            $summary['self_observation'] = (bool) aimee_save_inner_state($user_id, $state);
        }
    }

    return $summary;
}

function aimee_engine_observe_turn($user_id, $user_message_id, $aimee_message_id) {
    global $wpdb;
    $user_id = intval($user_id);
    if (!$user_id || !function_exists('aimee_table')) return;

    $table = aimee_table('aimee_messages');
    $pk = aimee_messages_primary_key();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT {$pk} AS message_id, sender, message_text, image_url, created_at
         FROM {$table}
         WHERE user_id = %d AND {$pk} <= %d
         ORDER BY created_at DESC, {$pk} DESC
         LIMIT 10",
        $user_id,
        intval($aimee_message_id)
    ));
    if (!$rows) return;
    $rows = array_reverse($rows);

    $context_lines = [];
    $exchange_lines = [];
    foreach ($rows as $row) {
        $who = $row->sender === 'aimee' ? 'Aimee' : 'Him';
        $line = $who . ': ' . trim((string) $row->message_text);
        if (intval($row->message_id) === intval($user_message_id) || intval($row->message_id) === intval($aimee_message_id)) {
            $exchange_lines[] = $line;
        } else {
            $context_lines[] = $line;
        }
    }
    if (!$exchange_lines) return;

    $content = "Earlier context:\n" . ($context_lines ? implode("\n", $context_lines) : '(none)')
        . "\n\nNewest exchange:\n" . implode("\n", $exchange_lines);

    $body = aimee_engine_anthropic_build_body(
        (string) aimee_engine_setting('observer_model'),
        [['type' => 'text', 'text' => aimee_engine_observer_prompt(aimee_engine_existing_memory_keys($user_id))]],
        [['role' => 'user', 'content' => $content]],
        ['max_tokens' => 800, 'output_schema' => aimee_engine_observer_schema()]
    );
    $result = aimee_engine_anthropic_request($body, 45);
    if (!$result['ok'] || $result['stop_reason'] === 'refusal') {
        aimee_engine_record_event($user_id, 'engine_v2_observer', [
            'ok' => false,
            'error_type' => $result['error_type'] ?: $result['stop_reason'],
        ]);
        return;
    }
    $data = aimee_engine_extract_json($result['text']);
    if (!is_array($data)) return;

    $summary = aimee_engine_apply_observation($user_id, $data, $user_message_id);
    $summary['ok'] = true;
    $summary['model'] = $result['model'];
    $summary['latency_ms'] = $result['latency_ms'];
    aimee_engine_record_event($user_id, 'engine_v2_observer', $summary);
}
