<?php
/**
 * Inspectable media decisions and append-only delivery milestones.
 *
 * A selected catalogue key is an intention, not proof that the attachment was
 * returned, rendered or seen. These helpers deliberately keep those facts
 * separate and make every transition idempotent.
 */

defined('ABSPATH') || exit;

function aimee_media_state_uuid() {
    if (function_exists('wp_generate_uuid4')) {
        return wp_generate_uuid4();
    }

    $bytes = function_exists('random_bytes')
        ? random_bytes(16)
        : openssl_random_pseudo_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);

    return substr($hex, 0, 8) . '-'
        . substr($hex, 8, 4) . '-'
        . substr($hex, 12, 4) . '-'
        . substr($hex, 16, 4) . '-'
        . substr($hex, 20, 12);
}

function aimee_media_decisions_table() {
    return function_exists('aimee_table')
        ? aimee_table('aimee_media_decisions')
        : 'aimee_media_decisions';
}

function aimee_media_deliveries_table() {
    return function_exists('aimee_table')
        ? aimee_table('aimee_media_deliveries')
        : 'aimee_media_deliveries';
}

function aimee_media_delivery_events_table() {
    return function_exists('aimee_table')
        ? aimee_table('aimee_media_delivery_events')
        : 'aimee_media_delivery_events';
}

/**
 * Persist the deterministic opportunity before a reply model is called.
 */
function aimee_media_decision_store($user_id, array $decision, $request_id = '') {
    global $wpdb;

    $decision_id = sanitize_text_field((string) ($decision['decision_id'] ?? ''));
    if ($decision_id === '') {
        $decision_id = aimee_media_state_uuid();
        $decision['decision_id'] = $decision_id;
    }
    $request_id = sanitize_text_field((string) $request_id);
    $turn_id = sanitize_text_field((string) ($decision['turn_id'] ?? ''));
    if ($turn_id === '') $turn_id = $request_id;

    $now = current_time('mysql', true);
    $eligible_keys = array_values(array_filter(array_map(
        'sanitize_key',
        (array) ($decision['eligible_keys'] ?? [])
    )));

    $inserted = $wpdb->insert(
        aimee_media_decisions_table(),
        [
            'decision_id'               => $decision_id,
            'user_id'                   => intval($user_id),
            'request_id'                => $request_id,
            'turn_id'                   => $turn_id,
            'source'                    => sanitize_key((string) ($decision['source'] ?? 'none')),
            'policy_version'            => sanitize_text_field((string) ($decision['policy_version'] ?? 'unknown')),
            'model_route'               => sanitize_key((string) ($decision['model_route'] ?? 'primary')),
            'actual_model'              => sanitize_text_field((string) ($decision['actual_model'] ?? '')) ?: null,
            'actual_provider'           => sanitize_text_field((string) ($decision['actual_provider'] ?? '')) ?: null,
            'model_attempt'             => max(1, intval($decision['model_attempt'] ?? 1)),
            'direct_request'            => !empty($decision['direct_request']) ? 1 : 0,
            'requested_rating'          => sanitize_key((string) ($decision['requested_rating'] ?? '')) ?: null,
            'media_opportunity'         => !empty($decision['media_opportunity']) ? 1 : 0,
            'maximum_rating'            => sanitize_key((string) ($decision['maximum_rating'] ?? 'none')),
            'reason_code'               => sanitize_key((string) ($decision['reason_code'] ?? 'not_evaluated')),
            'reason_text'               => sanitize_text_field((string) ($decision['reason'] ?? '')),
            'proactive_allowed'         => !empty($decision['proactive_allowed']) ? 1 : 0,
            'cooldown_clear'            => !empty($decision['cooldown_clear']) ? 1 : 0,
            'access_state'              => sanitize_key((string) ($decision['access_state'] ?? 'unavailable')),
            'adult_assurance'           => sanitize_key((string) ($decision['adult_assurance'] ?? 'unknown')),
            'mutual_context'            => !empty($decision['mutual_context_active']) ? 1 : 0,
            'pressure_detected'         => !empty($decision['pressure_detected']) ? 1 : 0,
            'eligible_keys_json'        => wp_json_encode($eligible_keys),
            'excluded_keys_json'        => wp_json_encode((array) ($decision['excluded_keys'] ?? [])),
            'relationship_snapshot_json'=> wp_json_encode((array) ($decision['relationship_snapshot'] ?? [])),
            'policy_snapshot_json'      => wp_json_encode((array) ($decision['policy_snapshot'] ?? [])),
            'aimee_decision'            => sanitize_key((string) ($decision['aimee_decision'] ?? 'consider')),
            'selected_key'              => sanitize_key((string) (
                $decision['media_key']
                ?? $decision['selected_key']
                ?? ''
            )) ?: null,
            'decision_reason_code'      => sanitize_key((string) (
                $decision['media_reason_code']
                ?? $decision['aimee_reason_code']
                ?? $decision['decision_reason_code']
                ?? ''
            )) ?: null,
            'created_at'                => $now,
            'updated_at'                => $now,
        ],
        [
            '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d',
            '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s',
            '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
            '%s',
        ]
    );

    if ($inserted === false) {
        error_log('Aimee media decision persistence failed: ' . (string) $wpdb->last_error);
        return '';
    }

    return $decision_id;
}

/**
 * Canonical model-facing media choice contract.
 *
 * Runtime reply schemas use media_key and media_reason_code. The selected_key,
 * reason_code and aimee_reason_code aliases are accepted only so older callers
 * and persisted fixtures continue to reconcile safely during rollout.
 */
function aimee_media_decision_runtime_choice_contract() {
    $policy = function_exists('aimee_media_decision_default_policy')
        ? aimee_media_decision_default_policy()
        : [];

    return [
        'aimee_decision'   => ['send', 'decline', 'defer'],
        'media_key'        => 'one immutable eligible key when sending; otherwise empty',
        'media_reason_code'=> (array) ($policy['model_reason_codes'] ?? []),
        'compatibility_aliases' => [
            'selected_key'      => 'media_key',
            'reason_code'       => 'media_reason_code',
            'aimee_reason_code' => 'media_reason_code',
        ],
    ];
}

/**
 * Normalize current and legacy model-choice field names without granting the
 * model any authority over deterministic eligibility.
 */
function aimee_media_decision_normalize_runtime_choice($choice) {
    $choice = is_array($choice)
        ? $choice
        : ['aimee_decision' => $choice];

    $conflicts = [];
    $compatibility_fields = [];
    $has_canonical_key = array_key_exists('media_key', $choice);
    $canonical_key = sanitize_key((string) ($choice['media_key'] ?? ''));
    $legacy_key = sanitize_key((string) ($choice['selected_key'] ?? ''));
    if (array_key_exists('selected_key', $choice)) {
        $compatibility_fields[] = 'selected_key';
    }
    if (
        $has_canonical_key
        && array_key_exists('selected_key', $choice)
        && $canonical_key !== $legacy_key
    ) {
        $conflicts[] = 'conflicting_media_key_alias';
    }
    $media_key = $has_canonical_key ? $canonical_key : $legacy_key;

    $has_canonical_reason = array_key_exists('media_reason_code', $choice);
    $canonical_reason = sanitize_key((string) ($choice['media_reason_code'] ?? ''));
    $aimee_reason_alias = sanitize_key((string) (
        $choice['aimee_reason_code'] ?? ''
    ));
    $reason_alias = sanitize_key((string) ($choice['reason_code'] ?? ''));
    if (array_key_exists('aimee_reason_code', $choice)) {
        $compatibility_fields[] = 'aimee_reason_code';
    }
    if (array_key_exists('reason_code', $choice)) {
        $compatibility_fields[] = 'reason_code';
    }
    if (
        $aimee_reason_alias !== ''
        && $reason_alias !== ''
        && $aimee_reason_alias !== $reason_alias
    ) {
        $conflicts[] = 'conflicting_legacy_reason_aliases';
    }
    $legacy_reason = $aimee_reason_alias !== ''
        ? $aimee_reason_alias
        : $reason_alias;
    if (
        $has_canonical_reason
        && (
            array_key_exists('aimee_reason_code', $choice)
            || array_key_exists('reason_code', $choice)
        )
        && $canonical_reason !== $legacy_reason
    ) {
        $conflicts[] = 'conflicting_media_reason_alias';
    }
    $reason_code = $has_canonical_reason ? $canonical_reason : $legacy_reason;

    $has_canonical_action = array_key_exists('aimee_decision', $choice);
    $canonical_action = sanitize_key((string) ($choice['aimee_decision'] ?? ''));
    $legacy_action = sanitize_key((string) ($choice['decision'] ?? ''));
    if (array_key_exists('decision', $choice)) {
        $compatibility_fields[] = 'decision';
    }
    if (
        $has_canonical_action
        && array_key_exists('decision', $choice)
        && $canonical_action !== $legacy_action
    ) {
        $conflicts[] = 'conflicting_media_action_alias';
    }
    $action = $has_canonical_action ? $canonical_action : $legacy_action;
    if (!in_array($action, ['send', 'decline', 'defer'], true)) {
        $conflicts[] = 'invalid_or_missing_media_action';
        $action = 'defer';
    }
    if ($action === 'send' && $media_key === '') {
        $conflicts[] = 'send_without_media_key';
        $action = 'defer';
    }
    if ($action !== 'send' && $media_key !== '') {
        $conflicts[] = 'media_key_without_send_action';
        $media_key = '';
    }
    if ($conflicts) {
        $action = 'defer';
        $media_key = '';
        $reason_code = 'model_choice_invalid';
    }

    return [
        'aimee_decision'   => $action,
        'media_key'        => $media_key,
        'media_reason_code'=> $reason_code,
        'contract_valid'   => !$conflicts,
        'adapter_version'  => 'aimee.media-choice-adapter/1',
        'contract_conflicts' => array_values(array_unique($conflicts)),
        'compatibility_fields_used' => array_values(array_unique(
            $compatibility_fields
        )),
    ];
}

/**
 * Record Aimee's actual choice without changing the deterministic envelope.
 */
function aimee_media_decision_record_choice(
    $decision_id,
    $choice,
    $selected_key = '',
    $reason_code = '',
    $user_message_id = 0,
    $aimee_message_id = 0
) {
    global $wpdb;

    if (is_array($choice)) {
        $normalized_choice = aimee_media_decision_normalize_runtime_choice(
            $choice
        );
        $choice = $normalized_choice['aimee_decision'];
        $selected_key = $normalized_choice['media_key'];
        $reason_code = $normalized_choice['media_reason_code'];
    }

    $decision_id = sanitize_text_field((string) $decision_id);
    if ($decision_id === '') return false;

    $choice = sanitize_key((string) $choice);
    $selected_key = sanitize_key((string) $selected_key);
    $reason_code = sanitize_key((string) $reason_code);
    if (!in_array($choice, ['send', 'decline', 'defer'], true)) {
        $choice = 'defer';
        $selected_key = '';
        $reason_code = $reason_code ?: 'invalid_model_choice';
    }
    if ($choice === 'send' && $selected_key === '') {
        $choice = 'defer';
        $reason_code = 'model_choice_invalid';
    }
    if ($choice !== 'send') $selected_key = '';

    $existing = $wpdb->get_row($wpdb->prepare(
        'SELECT aimee_decision, selected_key, decision_reason_code'
        . ' FROM ' . aimee_media_decisions_table()
        . ' WHERE decision_id = %s LIMIT 1',
        $decision_id
    ), ARRAY_A);
    if (!is_array($existing)) return false;

    $existing_choice = sanitize_key((string) (
        $existing['aimee_decision'] ?? 'consider'
    ));
    $finalized = in_array(
        $existing_choice,
        ['send', 'decline', 'defer'],
        true
    );
    if ($finalized) {
        $existing_key = sanitize_key((string) ($existing['selected_key'] ?? ''));
        $existing_reason = sanitize_key((string) (
            $existing['decision_reason_code'] ?? ''
        ));
        if (
            $existing_choice !== $choice
            || $existing_key !== $selected_key
            || (
                $existing_reason !== ''
                && $reason_code !== ''
                && $existing_reason !== $reason_code
            )
        ) {
            return false;
        }
    }

    $data = ['updated_at' => current_time('mysql', true)];
    $formats = ['%s'];
    if (!$finalized) {
        $data = [
            'aimee_decision'       => $choice,
            'selected_key'         => $selected_key ?: null,
            'decision_reason_code' => $reason_code ?: null,
            'updated_at'           => current_time('mysql', true),
        ];
        $formats = ['%s', '%s', '%s', '%s'];
    }

    if ($user_message_id) {
        $data['user_message_id'] = intval($user_message_id);
        $formats[] = '%d';
    }
    if ($aimee_message_id) {
        $data['aimee_message_id'] = intval($aimee_message_id);
        $formats[] = '%d';
    }

    return $wpdb->update(
        aimee_media_decisions_table(),
        $data,
        ['decision_id' => $decision_id],
        $formats,
        ['%s']
    ) !== false;
}

/**
 * Attach the actually engaged provider/model to the pre-model decision row.
 * The engine should call this after each provider attempt resolves.
 */
function aimee_media_decision_record_model_attempt(
    $decision_id,
    $actual_model,
    $actual_provider,
    $attempt = 1
) {
    global $wpdb;

    $decision_id = sanitize_text_field((string) $decision_id);
    $actual_model = sanitize_text_field((string) $actual_model);
    $actual_provider = sanitize_text_field((string) $actual_provider);
    if ($decision_id === '' || $actual_model === '') return false;

    return $wpdb->update(
        aimee_media_decisions_table(),
        [
            'actual_model'    => $actual_model,
            'actual_provider' => $actual_provider ?: null,
            'model_attempt'   => max(1, intval($attempt)),
            'updated_at'      => current_time('mysql', true),
        ],
        ['decision_id' => $decision_id],
        ['%s', '%s', '%d', '%s'],
        ['%s']
    ) !== false;
}

function aimee_media_delivery_state_fields() {
    return [
        'selected'                => 'selected_at',
        'catalogue_resolved'      => 'catalogue_resolved_at',
        'authorised'              => 'authorised_at',
        'file_resolved'           => 'file_resolved_at',
        'message_created'         => 'message_created_at',
        'returned_by_direct_api'  => 'returned_by_direct_api_at',
        'returned_by_history_api' => 'returned_by_history_api_at',
        'asset_requested'         => 'asset_requested_at',
        'asset_completed'         => 'asset_completed_at',
        'rendered_by_client'      => 'rendered_by_client_at',
        'acknowledged_by_client'  => 'acknowledged_by_client_at',
        'user_responded'          => 'user_responded_at',
        'render_failed'           => 'render_failed_at',
        'failed'                  => 'failed_at',
    ];
}

function aimee_media_delivery_phase_ranks() {
    return [
        // render_failed is an observational side fact, not a lifecycle phase.
        // Keeping it at zero lets a later successful render recover naturally.
        'render_failed' => 0,
        'selected' => 10,
        'catalogue_resolved' => 20,
        'authorised' => 30,
        'file_resolved' => 40,
        'message_created' => 50,
        'returned_by_direct_api' => 60,
        'returned_by_history_api' => 60,
        'asset_requested' => 65,
        'asset_completed' => 70,
        'rendered_by_client' => 80,
        'acknowledged_by_client' => 90,
        'user_responded' => 100,
        'failed' => 110,
    ];
}

function aimee_media_delivery_state_rank($state) {
    $ranks = aimee_media_delivery_phase_ranks();

    return intval($ranks[sanitize_key((string) $state)] ?? 0);
}

/**
 * Advance the denormalized current_state atomically. Timestamp columns remain
 * the source of truth; this field is only a convenient monotonic summary.
 */
function aimee_media_delivery_advance_current_state($delivery_id, $state) {
    global $wpdb;

    $delivery_id = sanitize_text_field((string) $delivery_id);
    $state = sanitize_key((string) $state);
    $new_rank = aimee_media_delivery_state_rank($state);
    if ($delivery_id === '' || $new_rank <= 0) return true;

    $lower_states = [];
    foreach (aimee_media_delivery_phase_ranks() as $candidate => $rank) {
        if (intval($rank) < $new_rank) $lower_states[] = $candidate;
    }
    if (!$lower_states) return true;

    $placeholders = implode(', ', array_fill(0, count($lower_states), '%s'));
    $args = array_merge([$state, $delivery_id], $lower_states);
    $sql = 'UPDATE ' . aimee_media_deliveries_table()
        . ' SET current_state = %s WHERE delivery_id = %s'
        . " AND (current_state IN ({$placeholders})"
        . " OR current_state IS NULL OR current_state = '')";

    return $wpdb->query($wpdb->prepare($sql, $args)) !== false;
}

function aimee_media_delivery_transition_prerequisite($state, $row) {
    $row = is_array($row) ? $row : [];
    $returned = !empty($row['returned_by_direct_api_at'])
        || !empty($row['returned_by_history_api_at']);

    switch ($state) {
        case 'catalogue_resolved':
            return !empty($row['selected_at']);
        case 'authorised':
            return !empty($row['catalogue_resolved_at']);
        case 'file_resolved':
            return !empty($row['authorised_at']);
        case 'message_created':
            return !empty($row['file_resolved_at']);
        case 'returned_by_direct_api':
        case 'returned_by_history_api':
            return !empty($row['message_created_at']);
        case 'asset_requested':
            return !empty($row['message_created_at']) && $returned;
        case 'asset_completed':
            return !empty($row['asset_requested_at']);
        case 'rendered_by_client':
            return $returned && !empty($row['asset_completed_at']);
        case 'render_failed':
            return $returned;
        case 'acknowledged_by_client':
            return !empty($row['rendered_by_client_at']);
        case 'user_responded':
            return $returned;
        case 'failed':
            return !$returned
                && empty($row['rendered_by_client_at'])
                && empty($row['acknowledged_by_client_at'])
                && empty($row['user_responded_at']);
        case 'selected':
            return true;
    }

    return false;
}

/**
 * SQL equivalent of the lifecycle prerequisites. These predicates are applied
 * in the same statement that records the milestone, closing the TOCTOU window
 * left by the descriptive PHP pre-check above.
 */
function aimee_media_delivery_transition_prerequisite_sql($state) {
    $returned = '(returned_by_direct_api_at IS NOT NULL'
        . ' OR returned_by_history_api_at IS NOT NULL)';

    switch ($state) {
        case 'catalogue_resolved':
            return 'selected_at IS NOT NULL';
        case 'authorised':
            return 'catalogue_resolved_at IS NOT NULL';
        case 'file_resolved':
            return 'authorised_at IS NOT NULL';
        case 'message_created':
            return 'file_resolved_at IS NOT NULL';
        case 'returned_by_direct_api':
        case 'returned_by_history_api':
            return 'message_created_at IS NOT NULL';
        case 'asset_requested':
            return 'message_created_at IS NOT NULL AND ' . $returned;
        case 'asset_completed':
            return 'asset_requested_at IS NOT NULL';
        case 'rendered_by_client':
            return $returned . ' AND asset_completed_at IS NOT NULL';
        case 'render_failed':
        case 'user_responded':
            return $returned;
        case 'acknowledged_by_client':
            return 'rendered_by_client_at IS NOT NULL';
        case 'failed':
            return 'returned_by_direct_api_at IS NULL'
                . ' AND returned_by_history_api_at IS NULL'
                . ' AND rendered_by_client_at IS NULL'
                . ' AND acknowledged_by_client_at IS NULL'
                . ' AND user_responded_at IS NULL';
        case 'selected':
            return '1 = 1';
    }

    return '1 = 0';
}

function aimee_media_delivery_create($decision_id, $user_id, $media_key) {
    global $wpdb;

    $decision_id = sanitize_text_field((string) $decision_id);
    $user_id = intval($user_id);
    $media_key = sanitize_key((string) $media_key);
    if ($decision_id === '' || $user_id <= 0 || $media_key === '') return '';

    $decision = $wpdb->get_row($wpdb->prepare(
        'SELECT user_id, media_opportunity, aimee_decision, selected_key,'
        . ' eligible_keys_json FROM ' . aimee_media_decisions_table()
        . ' WHERE decision_id = %s LIMIT 1',
        $decision_id
    ), ARRAY_A);
    if (!is_array($decision)) {
        error_log('Aimee media delivery rejected: decision record missing.');
        return '';
    }

    $eligible_keys = json_decode(
        (string) ($decision['eligible_keys_json'] ?? ''),
        true
    );
    $eligible_keys = is_array($eligible_keys)
        ? array_values(array_filter(array_map('sanitize_key', $eligible_keys)))
        : [];
    $decision_authorises_delivery = intval($decision['user_id'] ?? 0) === $user_id
        && intval($decision['media_opportunity'] ?? 0) === 1
        && sanitize_key((string) ($decision['aimee_decision'] ?? '')) === 'send'
        && sanitize_key((string) ($decision['selected_key'] ?? '')) === $media_key
        && in_array($media_key, $eligible_keys, true);
    if (!$decision_authorises_delivery) {
        error_log('Aimee media delivery rejected: finalized decision did not authorize the exact key.');
        return '';
    }

    // One deterministic decision represents one delivery attempt. A request
    // replay must use the existing turn response rather than create a second
    // attachment row for the same choice.
    $existing_delivery = $wpdb->get_var($wpdb->prepare(
        'SELECT delivery_id FROM ' . aimee_media_deliveries_table()
        . ' WHERE decision_id = %s AND user_id = %d AND media_key = %s'
        . ' ORDER BY attempt ASC, id ASC LIMIT 1',
        $decision_id,
        $user_id,
        $media_key
    ));
    if (is_string($existing_delivery) && $existing_delivery !== '') {
        return '';
    }

    $delivery_id = aimee_media_state_uuid();
    $now = current_time('mysql', true);
    $inserted = $wpdb->insert(
        aimee_media_deliveries_table(),
        [
            'delivery_id'  => $delivery_id,
            'decision_id'  => $decision_id,
            'user_id'      => $user_id,
            'media_key'    => $media_key,
            'current_state'=> 'selected',
            'selected_at'  => $now,
            'created_at'   => $now,
            'updated_at'   => $now,
        ],
        ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s']
    );

    if ($inserted === false) {
        error_log('Aimee media delivery creation failed: ' . (string) $wpdb->last_error);
        return '';
    }

    $wpdb->query($wpdb->prepare(
        'INSERT IGNORE INTO ' . aimee_media_delivery_events_table()
        . ' (delivery_id, user_id, state, occurred_at) VALUES (%s, %d, %s, %s)',
        $delivery_id,
        $user_id,
        'selected',
        $now
    ));

    return $delivery_id;
}

/**
 * Atomically bind the exact bytes selected for one authorised delivery while
 * recording file_resolved. The tuple is immutable: an idempotent replay must
 * present the same source, job, hash and MIME.
 */
function aimee_media_delivery_bind_resolved_asset($delivery_id, array $asset) {
    global $wpdb;

    $delivery_id = sanitize_text_field((string) $delivery_id);
    $source = sanitize_key((string) ($asset['source'] ?? ''));
    $job_id = intval($asset['job_id'] ?? 0);
    $sha256 = strtolower(trim((string) ($asset['sha256'] ?? '')));
    $mime = strtolower(trim((string) ($asset['mime'] ?? '')));
    if (
        $delivery_id === ''
        || !in_array($source, ['catalogue', 'delivery_materialization'], true)
        || !preg_match('/^[a-f0-9]{64}$/', $sha256)
        || !in_array(
            $mime,
            $source === 'catalogue'
                ? ['image/png', 'image/jpeg', 'image/gif', 'image/webp']
                : ['image/png', 'image/jpeg', 'image/webp'],
            true
        )
        || ($source === 'catalogue' && $job_id !== 0)
        || ($source === 'delivery_materialization' && $job_id <= 0)
    ) {
        return false;
    }

    $row = aimee_media_delivery_find($delivery_id);
    if (!is_array($row)) return false;

    $existing_source = sanitize_key((string) (
        $row['resolved_asset_source'] ?? ''
    ));
    $existing_job_id = intval($row['resolved_asset_job_id'] ?? 0);
    $existing_sha256 = strtolower(trim((string) (
        $row['resolved_asset_sha256'] ?? ''
    )));
    $existing_mime = strtolower(trim((string) (
        $row['resolved_asset_mime'] ?? ''
    )));
    if (!empty($row['file_resolved_at'])) {
        return $existing_source === $source
            && $existing_job_id === $job_id
            && hash_equals($existing_sha256, $sha256)
            && hash_equals($existing_mime, $mime);
    }
    if (
        empty($row['authorised_at'])
        || !empty($row['failed_at'])
        || $existing_source !== ''
        || $existing_job_id !== 0
        || $existing_sha256 !== ''
        || $existing_mime !== ''
    ) {
        return false;
    }

    $now = current_time('mysql', true);
    $affected = $wpdb->query($wpdb->prepare(
        'UPDATE ' . aimee_media_deliveries_table()
        . ' SET resolved_asset_source = %s, resolved_asset_job_id = %d,'
        . ' resolved_asset_sha256 = %s, resolved_asset_mime = %s,'
        . ' file_resolved_at = %s, updated_at = %s'
        . ' WHERE delivery_id = %s AND authorised_at IS NOT NULL'
        . ' AND failed_at IS NULL AND file_resolved_at IS NULL'
        . ' AND resolved_asset_source IS NULL'
        . ' AND resolved_asset_job_id IS NULL'
        . ' AND resolved_asset_sha256 IS NULL'
        . ' AND resolved_asset_mime IS NULL',
        $source,
        $job_id,
        $sha256,
        $mime,
        $now,
        $now,
        $delivery_id
    ));
    if ($affected === false) return false;

    if ((int) $affected === 0) {
        $fresh = aimee_media_delivery_find($delivery_id);
        return is_array($fresh)
            && !empty($fresh['file_resolved_at'])
            && sanitize_key((string) (
                $fresh['resolved_asset_source'] ?? ''
            )) === $source
            && intval($fresh['resolved_asset_job_id'] ?? 0) === $job_id
            && hash_equals(strtolower((string) (
                $fresh['resolved_asset_sha256'] ?? ''
            )), $sha256)
            && hash_equals(strtolower((string) (
                $fresh['resolved_asset_mime'] ?? ''
            )), $mime);
    }

    $advanced = aimee_media_delivery_advance_current_state(
        $delivery_id,
        'file_resolved'
    );
    if ($advanced) {
        $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO ' . aimee_media_delivery_events_table()
            . ' (delivery_id, user_id, state, details_json, occurred_at)'
            . ' VALUES (%s, %d, %s, %s, %s)',
            $delivery_id,
            intval($row['user_id'] ?? 0),
            'file_resolved',
            wp_json_encode([
                'asset_source' => $source,
                'asset_job_id' => $job_id,
                'asset_sha256' => $sha256,
                'asset_mime' => $mime,
            ]),
            $now
        ));
    }

    return $advanced;
}

/**
 * Idempotently add a delivery fact. Failures never imply a later success.
 */
function aimee_media_delivery_transition($delivery_id, $state, array $details = []) {
    global $wpdb;

    $delivery_id = sanitize_text_field((string) $delivery_id);
    $state = sanitize_key((string) $state);
    $error_code = sanitize_key((string) ($details['error_code'] ?? ''));

    // File resolution is a compound immutable fact. Never record its timestamp
    // without the exact provenance that history and private serving must later
    // resolve to the same bytes.
    if ($state === 'file_resolved') {
        $asset = is_array($details['asset'] ?? null)
            ? $details['asset']
            : $details;
        return aimee_media_delivery_bind_resolved_asset(
            $delivery_id,
            $asset
        );
    }

    // A failed browser/asset attempt is recoverable evidence, not a terminal
    // delivery failure. Existing engine callers may still use the older fatal
    // state name for this specific error during a staged rollout.
    if (
        $state === 'failed'
        && in_array($error_code, ['asset_stream_failed', 'client_image_error'], true)
    ) {
        $state = 'render_failed';
    }

    $fields = aimee_media_delivery_state_fields();
    if ($delivery_id === '' || !isset($fields[$state])) return false;

    $row = $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . aimee_media_deliveries_table() . ' WHERE delivery_id = %s LIMIT 1',
        $delivery_id
    ), ARRAY_A);
    if (!is_array($row)) return false;
    $was_returned = !empty($row['returned_by_direct_api_at'])
        || !empty($row['returned_by_history_api_at']);
    if (!empty($row['failed_at']) && $state !== 'failed') return false;
    if (!aimee_media_delivery_transition_prerequisite($state, $row)) {
        error_log(sprintf(
            'Aimee media delivery transition rejected: delivery=%s state=%s prerequisite_missing=1',
            $delivery_id,
            $state
        ));
        return false;
    }

    $now = current_time('mysql', true);
    $timestamp_field = $fields[$state];
    $set_sql = ["`{$timestamp_field}` = COALESCE(`{$timestamp_field}`, %s)"];
    $set_args = [$now];
    $change_sql = ["`{$timestamp_field}` IS NULL"];

    // Render-attempt errors live in the event record and render_failed_at.
    // error_code on the delivery row is reserved for terminal execution loss.
    if ($state === 'failed' && $error_code !== '') {
        $set_sql[] = 'error_code = COALESCE(error_code, %s)';
        $set_args[] = $error_code;
        $change_sql[] = 'error_code IS NULL';
    }

    $message_id = intval($details['message_id'] ?? 0);
    if ($state === 'message_created' && $message_id > 0) {
        if (!empty($row['message_id']) && intval($row['message_id']) !== $message_id) {
            return false;
        }
        $set_sql[] = 'message_id = COALESCE(message_id, %d)';
        $set_args[] = $message_id;
        $change_sql[] = 'message_id IS NULL';
    }

    $client_instance_id = sanitize_text_field((string) (
        $details['client_instance_id'] ?? ''
    ));
    if ($client_instance_id !== '') {
        $set_sql[] = 'client_instance_id = COALESCE(client_instance_id, %s)';
        $set_args[] = $client_instance_id;
        $change_sql[] = 'client_instance_id IS NULL';
    }

    $client_version = sanitize_text_field((string) (
        $details['client_version'] ?? ''
    ));
    if ($client_version !== '') {
        $set_sql[] = 'client_version = COALESCE(client_version, %s)';
        $set_args[] = $client_version;
        $change_sql[] = 'client_version IS NULL';
    }

    $response_message_id = intval(
        $details['response_message_id']
        ?? $details['user_response_message_id']
        ?? 0
    );
    if (
        $state === 'user_responded'
        && $response_message_id > 0
        && array_key_exists('user_response_message_id', $row)
    ) {
        if (
            !empty($row['user_response_message_id'])
            && intval($row['user_response_message_id']) !== $response_message_id
        ) {
            return false;
        }
        $set_sql[] = 'user_response_message_id = COALESCE(user_response_message_id, %d)';
        $set_args[] = $response_message_id;
        $change_sql[] = 'user_response_message_id IS NULL';
    }

    $response_evidence = sanitize_key((string) (
        $details['response_evidence']
        ?? $details['user_response_evidence']
        ?? ''
    ));
    if (
        $state === 'user_responded'
        && $response_evidence !== ''
        && array_key_exists('user_response_evidence', $row)
    ) {
        $set_sql[] = 'user_response_evidence = COALESCE(user_response_evidence, %s)';
        $set_args[] = $response_evidence;
        $change_sql[] = 'user_response_evidence IS NULL';
    }

    $set_sql[] = 'updated_at = %s';
    $set_args[] = $now;
    $where_sql = 'delivery_id = %s AND '
        . aimee_media_delivery_transition_prerequisite_sql($state);
    $set_args[] = $delivery_id;
    if ($state !== 'failed') $where_sql .= ' AND failed_at IS NULL';
    $where_sql .= ' AND (' . implode(' OR ', $change_sql) . ')';

    $affected = $wpdb->query($wpdb->prepare(
        'UPDATE ' . aimee_media_deliveries_table()
        . ' SET ' . implode(', ', $set_sql)
        . ' WHERE ' . $where_sql,
        $set_args
    ));
    if ($affected === false) return false;

    // A zero-row update is a successful idempotent replay only when the fact
    // already exists. Otherwise an atomic prerequisite or terminal guard won.
    if ($affected === 0) {
        $fresh = aimee_media_delivery_find($delivery_id);
        if (!is_array($fresh) || empty($fresh[$timestamp_field])) return false;
        if (
            $message_id > 0
            && !empty($fresh['message_id'])
            && intval($fresh['message_id']) !== $message_id
        ) {
            return false;
        }
    }

    $updated = aimee_media_delivery_advance_current_state(
        $delivery_id,
        $state
    );

    if ($updated) {
        $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO ' . aimee_media_delivery_events_table()
            . ' (delivery_id, user_id, state, error_code, details_json, occurred_at)'
            . ' VALUES (%s, %d, %s, %s, %s, %s)',
            $delivery_id,
            intval($row['user_id'] ?? 0),
            $state,
            sanitize_key((string) ($details['error_code'] ?? '')) ?: null,
            wp_json_encode($details),
            $now
        ));

        if (
            $affected > 0
            && !$was_returned
            && in_array(
                $state,
                ['returned_by_direct_api', 'returned_by_history_api'],
                true
            )
            && function_exists('aimee_mark_media_cadence_returned_for_delivery')
        ) {
            aimee_mark_media_cadence_returned_for_delivery(
                (string) ($row['decision_id'] ?? ''),
                intval($row['user_id'] ?? 0),
                strtotime($now . ' UTC') ?: time()
            );
        }
    }

    return $updated;
}

function aimee_media_delivery_find_by_message($message_id, $user_id = 0) {
    global $wpdb;

    $where = 'message_id = %d';
    $args = [intval($message_id)];
    if ($user_id) {
        $where .= ' AND user_id = %d';
        $args[] = intval($user_id);
    }

    return $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . aimee_media_deliveries_table()
        . " WHERE {$where} ORDER BY id DESC LIMIT 1",
        $args
    ), ARRAY_A);
}

function aimee_media_delivery_find($delivery_id, $user_id = 0) {
    global $wpdb;

    $where = 'delivery_id = %s';
    $args = [sanitize_text_field((string) $delivery_id)];
    if ($user_id) {
        $where .= ' AND user_id = %d';
        $args[] = intval($user_id);
    }

    return $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . aimee_media_deliveries_table()
        . " WHERE {$where} LIMIT 1",
        $args
    ), ARRAY_A);
}

function aimee_media_delivery_key_acknowledged($user_id, $media_key) {
    global $wpdb;

    return intval($wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . aimee_media_deliveries_table()
        . ' WHERE user_id = %d AND media_key = %s'
        . ' AND acknowledged_by_client_at IS NOT NULL',
        intval($user_id),
        sanitize_key((string) $media_key)
    ))) > 0;
}

/**
 * Mark a response only from an already-saved user message whose words
 * explicitly identify a photograph. Generic acknowledgements such as "got
 * it" are never treated as evidence of a response to media.
 *
 * A future reply-to UI may pass the exact delivery ID. Until then, the latest
 * returned delivery preceding the saved response is the cautious fallback.
 */
function aimee_media_delivery_mark_user_response_from_text(
    $user_id,
    $text,
    $response_message_id = 0,
    $target_delivery_id = ''
) {
    global $wpdb;

    $user_id = intval($user_id);
    $response_message_id = intval($response_message_id);
    if (!$user_id || $response_message_id <= 0) return false;

    $message_pk = function_exists('aimee_messages_primary_key')
        ? aimee_messages_primary_key()
        : 'message_id';
    if (!in_array($message_pk, ['id', 'message_id'], true)) return false;

    $messages_table = function_exists('aimee_table')
        ? aimee_table('aimee_messages')
        : 'aimee_messages';
    $saved_response = $wpdb->get_row($wpdb->prepare(
        "SELECT message_text, sender, created_at FROM {$messages_table}"
        . " WHERE `{$message_pk}` = %d AND user_id = %d LIMIT 1",
        $response_message_id,
        $user_id
    ), ARRAY_A);
    if (
        !is_array($saved_response)
        || (string) ($saved_response['sender'] ?? '') !== 'user'
    ) {
        return false;
    }

    // The stored message, not a caller-supplied variant, is the evidence.
    $stored_text = trim((string) ($saved_response['message_text'] ?? ''));
    if ($stored_text === '') return false;
    $text = str_replace(
        ['’', '‘'],
        "'",
        mb_strtolower($stored_text)
    );
    $target_delivery_id = sanitize_text_field((string) $target_delivery_id);
    $structured_target = $target_delivery_id !== '';

    $explicit_media_reference = preg_match(
        '/\b(?:photos?|photographs?|pictures?|pics?|images?|selfies?|'
        . 'attachments?|snaps?|snapshots?)\b/u',
        $text
    ) === 1;

    // Without a structured reply target, require wording that grounds the
    // media in this exchange rather than any photograph in the user's life.
    $conversation_media_reference = preg_match(
        '/\b(?:(?:your|the|that|this|last|latest|recent) '
        . '(?:photo|photograph|picture|pic|image|selfie|attachment|snap|snapshot)|'
        . '(?:photo|photograph|picture|pic|image|selfie|attachment|snap|snapshot) '
        . '(?:you sent|you shared|from you|came through|arrived|loaded))\b/u',
        $text
    ) === 1;
    $self_owned_media = preg_match(
        '/\b(?:photo|photograph|picture|pic|image|selfie|snap|snapshot) '
        . '(?:that )?(?:i|we) (?:took|made|uploaded|sent|shared)\b/u',
        $text
    ) === 1;
    if (
        (!$structured_target && (!$explicit_media_reference || !$conversation_media_reference))
        || $self_owned_media
    ) {
        return false;
    }

    $visual_reaction = preg_match(
        '/\b(?:love|like|lovely|beautiful|gorgeous|stunning|sexy|cute|wow|'
        . 'thanks|thank you|looks?|looked)\b/u',
        $text
    ) === 1;
    $delivery_status = preg_match(
        '/\b(?:received|arrived|came through|loaded|showing|displayed|'
        . 'i (?:can|could) see|i saw|'
        . '(?:can(?:not|\'t)|could(?: not|n\'t)|did(?: not|n\'t)) '
        . '(?:see|open|load|view|get)|'
        . 'not (?:showing|loading|displaying)|'
        . 'got (?:the|your|that|this) (?:photo|photograph|picture|pic|image|'
        . 'selfie|attachment|snap|snapshot))\b/u',
        $text
    ) === 1;
    if (!$visual_reaction && !$delivery_status) return false;

    $negative_delivery_status = preg_match(
        '/\b(?:(?:can(?:not|\'t)|could(?: not|n\'t)|did(?: not|n\'t)) '
        . '(?:see|open|load|view|get)|not (?:showing|loading|displaying))\b/u',
        $text
    ) === 1;

    $response_created_at = sanitize_text_field((string) (
        $saved_response['created_at'] ?? ''
    ));
    $delivery = $structured_target
        ? aimee_media_delivery_find($target_delivery_id, $user_id)
        : null;

    if (is_array($delivery)) {
        $returned = !empty($delivery['returned_by_direct_api_at'])
            || !empty($delivery['returned_by_history_api_at']);
        if (
            !$returned
            || empty($delivery['message_created_at'])
            || empty($delivery['message_id'])
            || intval($delivery['message_id']) >= $response_message_id
        ) {
            return false;
        }
        if (
            $response_created_at !== ''
            && strtotime((string) $delivery['message_created_at'] . ' UTC')
                > strtotime($response_created_at . ' UTC')
        ) {
            return false;
        }
    } else {
        if ($structured_target) return false;
        if ($response_created_at === '') return false;
        $response_timestamp = strtotime($response_created_at . ' UTC');
        if (!$response_timestamp) return false;
        $recent_cutoff = gmdate(
            'Y-m-d H:i:s',
            $response_timestamp - (6 * 3600)
        );

        $candidates = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . aimee_media_deliveries_table()
            . ' WHERE user_id = %d AND message_created_at IS NOT NULL'
            . ' AND message_id IS NOT NULL AND message_id < %d'
            . ' AND user_responded_at IS NULL'
            . ' AND (returned_by_direct_api_at IS NOT NULL'
            . ' OR returned_by_history_api_at IS NOT NULL)'
            . ' AND message_created_at BETWEEN %s AND %s'
            . ' ORDER BY message_created_at DESC, id DESC LIMIT 2',
            $user_id,
            $response_message_id,
            $recent_cutoff,
            $response_created_at
        ), ARRAY_A);

        // Free text cannot safely identify one of several recent photos.
        // A structured reply target is required in that case.
        if (count((array) $candidates) !== 1) return false;
        $delivery = $candidates[0];
    }
    if (!is_array($delivery) || empty($delivery['delivery_id'])) return false;

    if ($structured_target) {
        $evidence = 'structured_media_reply';
    } elseif ($negative_delivery_status) {
        $evidence = 'explicit_media_delivery_failure';
    } elseif ($delivery_status) {
        $evidence = 'explicit_media_delivery_status';
    } else {
        $evidence = 'explicit_media_reaction';
    }

    return aimee_media_delivery_transition(
        (string) $delivery['delivery_id'],
        'user_responded',
        [
            'response_message_id' => $response_message_id,
            'response_evidence'   => $evidence,
        ]
    );
}

/**
 * Derive the public lifecycle phase from milestone facts rather than trusting
 * the denormalized current_state string.
 */
function aimee_media_delivery_phase_from_facts($delivery) {
    if (!is_array($delivery)) return 'unknown';

    $ordered = [
        'failed'                  => 'failed_at',
        'user_responded'          => 'user_responded_at',
        'acknowledged_by_client'  => 'acknowledged_by_client_at',
        'rendered_by_client'      => 'rendered_by_client_at',
        'asset_completed'         => 'asset_completed_at',
        'asset_requested'         => 'asset_requested_at',
        'returned_by_direct_api'  => 'returned_by_direct_api_at',
        'returned_by_history_api' => 'returned_by_history_api_at',
        'message_created'         => 'message_created_at',
        'file_resolved'           => 'file_resolved_at',
        'authorised'              => 'authorised_at',
        'catalogue_resolved'      => 'catalogue_resolved_at',
        'selected'                => 'selected_at',
    ];
    foreach ($ordered as $phase => $field) {
        if (!empty($delivery[$field])) return $phase;
    }

    return 'unknown';
}

function aimee_media_delivery_public_snapshot($delivery) {
    if (!is_array($delivery)) return null;

    $render_failed = !empty($delivery['render_failed_at']);
    $rendered = !empty($delivery['rendered_by_client_at']);
    $failed = !empty($delivery['failed_at']);
    $phase = aimee_media_delivery_phase_from_facts($delivery);

    return [
        'delivery_id'               => (string) ($delivery['delivery_id'] ?? ''),
        'state'                     => $phase,
        'phase'                     => $phase,
        'selected'                  => !empty($delivery['selected_at']),
        'catalogue_resolved'        => !empty($delivery['catalogue_resolved_at']),
        'authorised'                => !empty($delivery['authorised_at']),
        'file_resolved'             => !empty($delivery['file_resolved_at']),
        'asset_source'              => in_array(
            sanitize_key((string) (
                $delivery['resolved_asset_source'] ?? ''
            )),
            ['catalogue', 'delivery_materialization'],
            true
        ) ? sanitize_key((string) $delivery['resolved_asset_source']) : '',
        'asset_binding_present'     => !empty($delivery['resolved_asset_sha256'])
            && !empty($delivery['resolved_asset_mime']),
        'message_created'           => !empty($delivery['message_created_at']),
        'returned_by_direct_api'    => !empty($delivery['returned_by_direct_api_at']),
        'returned_by_history_api'   => !empty($delivery['returned_by_history_api_at']),
        'asset_requested'           => !empty($delivery['asset_requested_at']),
        'asset_completed'           => !empty($delivery['asset_completed_at']),
        'rendered_by_client'        => $rendered,
        'acknowledged_by_client'    => !empty($delivery['acknowledged_by_client_at']),
        'user_responded'            => !empty($delivery['user_responded_at']),
        'user_response_message_id'  => intval($delivery['user_response_message_id'] ?? 0) ?: null,
        'user_response_evidence'    => sanitize_key((string) ($delivery['user_response_evidence'] ?? '')),
        'render_failed'             => $render_failed,
        'render_recovered'          => $render_failed && $rendered,
        'failed'                    => $failed,
        'error_code'                => $failed
            ? sanitize_key((string) ($delivery['error_code'] ?? ''))
            : '',
    ];
}

/**
 * Grounded wording for model memory; never turns a DB key into "you saw it".
 */
function aimee_media_delivery_memory_label($message_id, $user_id = 0) {
    $delivery = aimee_media_delivery_find_by_message($message_id, $user_id);
    if (!is_array($delivery)) {
        return 'Photo attachment recorded in message history; client display is unverified.';
    }
    if (!empty($delivery['failed_at'])) {
        return 'Aimee intended to send a photo, but delivery execution failed; it must not be remembered as displayed.';
    }
    if (!empty($delivery['user_responded_at'])) {
        return 'The server returned a photo attachment and a saved user message explicitly responded to that photo delivery; viewing is not inferred.';
    }
    if (!empty($delivery['acknowledged_by_client_at'])) {
        return 'The user app acknowledged the photo attachment; this does not prove personal viewing.';
    }
    if (!empty($delivery['rendered_by_client_at'])) {
        return !empty($delivery['render_failed_at'])
            ? 'The user app reported both a failed render attempt and a successful render; at least one attempt recovered.'
            : 'The user app reported rendering the photo attachment.';
    }
    if (!empty($delivery['render_failed_at'])) {
        return 'The user app reported that the photo failed to render; this is a recoverable attempt failure, not proof of terminal delivery loss.';
    }
    if (!empty($delivery['returned_by_history_api_at']) || !empty($delivery['returned_by_direct_api_at'])) {
        return 'The server returned the photo attachment to the user app; rendering is unverified.';
    }
    if (!empty($delivery['message_created_at'])) {
        return 'A message row with the photo attachment was created; return and display are unverified.';
    }
    if (!empty($delivery['file_resolved_at'])) {
        return 'Aimee selected and resolved the photo file, but message delivery is unverified.';
    }

    return 'Aimee intended to send a photo, but delivery was not established.';
}

/**
 * Authenticated client acknowledgement endpoint.
 */
function handle_aimee_media_delivery_ack(WP_REST_Request $request) {
    $user_id = get_current_user_id();
    if (!$user_id) {
        return new WP_REST_Response(['status' => 'error', 'message' => 'Authentication required.'], 401);
    }

    $params = $request->get_json_params();
    $delivery_id = sanitize_text_field((string) ($params['delivery_id'] ?? ''));
    $state = sanitize_key((string) ($params['state'] ?? ''));
    $allowed = ['rendered_by_client', 'acknowledged_by_client', 'render_failed'];

    if ($delivery_id === '' || !in_array($state, $allowed, true)) {
        return new WP_REST_Response(['status' => 'error', 'message' => 'Invalid delivery acknowledgement.'], 400);
    }

    $delivery = aimee_media_delivery_find($delivery_id, $user_id);
    if (!is_array($delivery)) {
        return new WP_REST_Response(['status' => 'error', 'message' => 'Delivery not found.'], 404);
    }

    $details = [
        'client_instance_id' => sanitize_text_field((string) ($params['client_instance_id'] ?? '')),
        'client_version'     => sanitize_text_field((string) ($params['client_version'] ?? AIMEE_GLOBAL_VERSION)),
    ];
    if ($state === 'render_failed') {
        $details['error_code'] = sanitize_key((string) ($params['error_code'] ?? 'client_image_error'));
    }

    if (!aimee_media_delivery_transition($delivery_id, $state, $details)) {
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'Delivery acknowledgement was out of sequence.',
        ], 409);
    }

    $fresh_delivery = aimee_media_delivery_find($delivery_id, $user_id);

    return rest_ensure_response([
        'status'      => 'success',
        'delivery_id' => $delivery_id,
        'state'       => $state,
        'delivery'    => aimee_media_delivery_public_snapshot($fresh_delivery),
    ]);
}
