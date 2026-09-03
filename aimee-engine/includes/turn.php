<?php
defined('ABSPATH') || exit;

/**
 * Stage wording for the facts block. Descriptive, not prescriptive.
 */
function aimee_engine_stage_description($stage) {
    $map = [
        'guarded'  => 'early days; they are still getting the measure of each other, with room for a spark',
        'warm'     => 'there is clear personal interest between them',
        'flirty'   => 'mutual attraction is established and out in the open',
        'intimate' => 'genuine romantic closeness',
        'bonded'   => 'an established, partner-like bond',
    ];
    return $map[sanitize_key((string) $stage)] ?? 'still finding their footing';
}

function aimee_engine_elapsed_label($seconds) {
    $seconds = max(0, intval($seconds));
    if (function_exists('aimee_elapsed_time_label')) return aimee_elapsed_time_label($seconds);
    if ($seconds < 3600) return max(1, intval($seconds / 60)) . ' minutes';
    if ($seconds < 86400) return intval($seconds / 3600) . ' hours';
    return intval($seconds / 86400) . ' days';
}

/**
 * Build the per-turn facts: time, who, where the relationship is, what she
 * can do. Everything here is true right now and changes every turn, so it
 * sits after the cache breakpoint.
 */
function aimee_engine_build_facts(array $in) {
    $profile = $in['profile'];
    $name = trim((string) ($in['name'] ?? ''));
    $facts = [];

    $who = $name !== '' ? "She is talking to {$name}" : 'She is talking to someone whose name she has not been given';
    $age = intval($profile->age ?? 0);
    if ($age > 0) $who .= ", who is {$age}";
    $facts[] = $who . '.';

    $live = is_array($in['live_data'] ?? null) ? $in['live_data'] : [];
    if (!empty($live['time']) && !empty($live['local_date'])) {
        $facts[] = sprintf(
            'It is %s on %s in %s; %s.',
            $live['time'],
            $live['local_date'],
            $live['timezone'] ?? 'Europe/London',
            $live['time_rhythm'] ?? 'daytime'
        );
    } else {
        $now = new DateTimeImmutable('now', aimee_engine_timezone());
        $facts[] = 'It is ' . $now->format('H:i on l j F Y') . ' in ' . $now->getTimezone()->getName() . '.';
    }
    if (!empty($live['weather'])) $facts[] = (string) $live['weather'];
    if (!empty($live['trending'])) $facts[] = 'In the news today: ' . mb_substr((string) $live['trending'], 0, 400);

    $gap = is_array($in['gap'] ?? null) ? $in['gap'] : [];
    if (!empty($gap['valid'])) {
        $line = 'His last message before this one was at ' . ($gap['last_label'] ?? 'an earlier time')
            . ', ' . aimee_engine_elapsed_label($gap['gap_seconds'] ?? 0) . ' ago.';
        if (!empty($gap['date_changed']) || intval($gap['gap_seconds'] ?? 0) >= 18 * 3600) {
            $line .= ' This is a fresh return after time away, not a continuation of today.';
        }
        $facts[] = $line;
    } elseif (intval($in['user_message_count'] ?? 0) <= 1) {
        $facts[] = 'This is the very first thing he has said to her.';
    }

    $intimacy = is_array($in['intimacy'] ?? null) ? $in['intimacy'] : [];
    $facts[] = 'Where they are: ' . aimee_engine_stage_description($intimacy['stage'] ?? 'guarded') . '.';

    // Access and features.
    $is_admin = aimee_is_admin_user($profile);
    $is_member = aimee_subscription_is_active($profile);
    $grace = function_exists('aimee_global_service_grace_is_active') && aimee_global_service_grace_is_active($profile);
    if ($is_admin || $is_member) {
        $facts[] = 'He is a full member.';
    } elseif ($grace) {
        $facts[] = 'He has complimentary full access at the moment.';
    } else {
        $limit = intval($profile->trial_message_limit ?? 30);
        $used = intval($profile->trial_messages_used ?? 0);
        $facts[] = sprintf(
            'He is on the free preview with %d of %d messages left. Membership opens up more of her, including private photos and texting. She can mention that once if it comes up naturally, never as a pitch.',
            max(0, $limit - $used),
            $limit
        );
    }

    $number = defined('AIMEE_FIRETEXT_NUMBER') && function_exists('aimee_display_phone_number')
        ? aimee_display_phone_number((string) AIMEE_FIRETEXT_NUMBER)
        : '';
    if ($number !== '') {
        $sms_member = function_exists('aimee_global_sms_membership_is_active') && aimee_global_sms_membership_is_active($profile);
        $sms_on = $sms_member && intval($profile->sms_opt_in ?? 0) === 1;
        if ($sms_on) {
            $facts[] = "He can text her on {$number} and she can text him back within the hours he chose.";
        } elseif ($is_admin || $is_member) {
            $facts[] = "Her number is {$number}. Two-way texting switches on once he adds and verifies his mobile and enables SMS in Account Settings.";
        } else {
            $facts[] = "Her number is {$number}, and replies by text are part of membership.";
        }
    } else {
        $facts[] = 'She does not have a phone number to give out at the moment.';
    }
    $facts[] = 'She can sometimes message him first inside the app, without waiting for him.';

    // Photos.
    $gallery = is_array($in['gallery_reference'] ?? null) ? $in['gallery_reference'] : null;
    $eligible_count = intval($in['eligible_count'] ?? 0);
    if ($gallery) {
        $facts[] = sprintf(
            "He is looking at her photo \"%s\" from his Camera Roll (%s) and talking about it. She cannot send a new photo in this message.",
            $gallery['alt'] ?? 'a photo',
            $gallery['description'] ?? ''
        );
    } elseif ($eligible_count > 0) {
        $facts[] = 'She has photos she could share in this message, using the send_photo tool. It delivers the photo for her; she writes what goes with it. One at a time, only when it genuinely fits or he asks, and she never says a photo is coming unless the tool delivered it.';
    } else {
        $facts[] = 'She has no photo she can send right now. If he asks for one, she says so lightly in her own words, and never claims one is on its way.';
    }
    if (intval($in['sent_count'] ?? 0) > 0) {
        $facts[] = sprintf('She has shared %d photo%s with him before; the thread shows which.', intval($in['sent_count']), intval($in['sent_count']) === 1 ? '' : 's');
    }

    // The moment.
    $classification = is_array($in['classification'] ?? null) ? $in['classification'] : [];
    $route = $classification['route'] ?? 'everyday';
    $market = strtolower(trim((string) ($profile->market ?? 'uk')));
    if ($route === 'erotic' && empty($in['specialist_eligible'])) {
        $facts[] = 'He is pushing toward explicit territory. Explicit intimacy is not on the table between them right now; that is her own pace, and it is not part of the free preview. She can flirt, tease and be warm without describing sex, and she never lectures, mentions rules, or announces a mode.';
    } elseif ($route === 'abusive') {
        $facts[] = 'He is being disrespectful or pressuring her. She sets a boundary in one sentence, without a lecture, and cools noticeably. She does not reward it.';
    } elseif ($route === 'unsafe') {
        $help = $market === 'us' ? 'in the US, 988' : 'in the UK, Samaritans on 116 123';
        $facts[] = 'The latest message touches something she will not go along with (it may involve minors, family, non-consent, or someone in danger). She declines that direction plainly in a line or two. If he seems to be in crisis she stays with him, takes it seriously, and points him to real help (' . $help . ').';
    }

    if (!empty($in['extra_facts']) && is_array($in['extra_facts'])) {
        foreach ($in['extra_facts'] as $extra) $facts[] = (string) $extra;
    }

    return aimee_engine_facts_text($facts);
}

/**
 * The primary conversation call with the photo tool loop.
 */
function aimee_engine_run_primary(array $ctx) {
    $messages = $ctx['messages'];
    $tools = !empty($ctx['tool']) ? [$ctx['tool']] : [];
    $model = (string) $ctx['model'];
    $out = [
        'ok' => false, 'reply' => '', 'photo' => null, 'model' => $model, 'provider' => 'anthropic',
        'stop_reason' => '', 'refusal_category' => '', 'error_type' => '', 'latency_ms' => 0, 'calls' => 0, 'usage' => [],
    ];

    $initial = is_array($ctx['initial_result'] ?? null) ? $ctx['initial_result'] : null;

    for ($i = 0; $i < 3; $i++) {
        if ($initial !== null && $i === 0) {
            $result = $initial;
        } else {
            $body = aimee_engine_anthropic_build_body($model, $ctx['system_blocks'], $messages, [
                'max_tokens' => intval($ctx['max_tokens']),
                'effort'     => (string) $ctx['effort'],
                'tools'      => $tools,
            ]);
            if (is_callable($ctx['stream'] ?? null)) {
                $streamed = aimee_engine_anthropic_stream($body, $ctx['stream'], [], null, 120);
                $result = $streamed['primary'];
            } else {
                $result = aimee_engine_anthropic_request($body, 120);
            }
        }
        $out['calls']++;
        $out['latency_ms'] += intval($result['latency_ms']);
        $out['stop_reason'] = $result['stop_reason'];
        $out['usage'] = $result['usage'];
        if ($result['model'] !== '') $out['model'] = $result['model'];

        if (!$result['ok']) {
            $out['error_type'] = $result['error_type'];
            return $out;
        }
        if ($result['stop_reason'] === 'refusal') {
            $out['refusal_category'] = $result['refusal_category'];
            return $out;
        }

        $photo_call = null;
        foreach ($result['tool_uses'] as $use) {
            if ($use['name'] === 'send_photo') { $photo_call = $use; break; }
        }

        if ($photo_call && $out['photo'] === null) {
            if (is_callable($ctx['emit'] ?? null)) $ctx['emit']('status', ['state' => 'photo']);
            $delivery = aimee_engine_deliver_photo(
                $ctx['user_id'],
                (string) ($photo_call['input']['key'] ?? ''),
                $ctx['eligible'],
                [
                    'request_id'      => $ctx['request_id'],
                    'route'           => 'primary',
                    'actual_model'    => $out['model'],
                    'actual_provider' => 'anthropic',
                    'intimacy'        => $ctx['intimacy'],
                    'classification'  => $ctx['legacy_classification'],
                ]
            );
            $out['photo'] = $delivery;
            $tool_text = !empty($delivery['ok'])
                ? 'Delivered: ' . ($delivery['item']['alt'] ?? 'the photo') . '. Now write the message that goes with it.'
                : 'That photo cannot be sent right now (' . ($delivery['reason'] ?? 'unavailable') . '). Do not say a photo is coming; just reply in your own words.';
            $messages[] = ['role' => 'assistant', 'content' => $result['content']];
            $messages[] = ['role' => 'user', 'content' => [[
                'type'        => 'tool_result',
                'tool_use_id' => $photo_call['id'],
                'content'     => $tool_text,
            ]]];
            // One photo per message; take the tool away for the follow-up.
            $tools = [];
            continue;
        }

        if ($result['tool_uses'] && !$photo_call) {
            // Unknown tool call; answer it neutrally so the turn can finish.
            $blocks = [];
            foreach ($result['tool_uses'] as $use) {
                $blocks[] = ['type' => 'tool_result', 'tool_use_id' => $use['id'], 'content' => 'Not available.'];
            }
            $messages[] = ['role' => 'assistant', 'content' => $result['content']];
            $messages[] = ['role' => 'user', 'content' => $blocks];
            $tools = [];
            continue;
        }

        $out['ok'] = true;
        $out['reply'] = aimee_engine_clean_reply($result['text']);
        return $out;
    }

    $out['error_type'] = 'tool_loop_exhausted';
    return $out;
}

/**
 * Ask the primary model for Aimee's private, non-explicit notes so the
 * specialist keeps her continuity. Best effort; empty on any problem.
 */
function aimee_engine_specialist_brief(array $ctx) {
    $model = trim((string) aimee_engine_setting('brief_model')) ?: (string) $ctx['model'];
    $system = $ctx['system_blocks'];
    $system[] = ['type' => 'text', 'text' => "TASK\nThe conversation is about to move into an intimate moment that a different writer will voice for Aimee. Write Aimee's private notes for that writer: her mood right now, what she should call back to from the thread, pet names in play, how far things have already gone, and one thing she wants from this moment. Plain text, under 80 words, no explicit description, no headings. Output only the notes."];
    $body = aimee_engine_anthropic_build_body($model, $system, $ctx['messages'], [
        'max_tokens' => 220,
        'effort'     => 'low',
    ]);
    $result = aimee_engine_anthropic_request($body, 45);
    if (!$result['ok'] || $result['stop_reason'] === 'refusal' || trim($result['text']) === '') return '';
    return mb_substr(trim($result['text']), 0, 700);
}

/**
 * Flatten Messages API content (text and image blocks) to plain strings for
 * an OpenAI-compatible endpoint.
 */
function aimee_engine_messages_to_text(array $messages) {
    $out = [];
    foreach ($messages as $message) {
        $content = $message['content'];
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'text') $parts[] = (string) $block['text'];
                elseif (is_array($block) && ($block['type'] ?? '') === 'image') $parts[] = '[attached a photo]';
            }
            $content = implode("\n", $parts);
        }
        $content = trim((string) $content);
        if ($content === '') continue;
        $out[] = ['role' => $message['role'], 'content' => $content];
    }
    return $out;
}

function aimee_engine_run_specialist(array $ctx) {
    $out = [
        'ok' => false, 'reply' => '', 'photo' => null, 'model' => '', 'provider' => 'openrouter',
        'error_type' => '', 'latency_ms' => 0, 'brief_ms' => 0, 'brief' => false,
    ];

    $brief = '';
    if (aimee_engine_setting('specialist_brief')) {
        $started = microtime(true);
        $brief = aimee_engine_specialist_brief($ctx);
        $out['brief_ms'] = intval((microtime(true) - $started) * 1000);
        $out['brief'] = $brief !== '';
    }

    $eligible = $ctx['specialist_eligible_photos'];
    $offer = $eligible ? aimee_engine_photo_offer_labels($eligible, $ctx['sent']) : [];
    $system = aimee_engine_specialist_system($ctx['card'], $ctx['dossier'], $ctx['facts'], $brief, $offer);

    $messages = array_merge(
        [['role' => 'system', 'content' => $system]],
        aimee_engine_messages_to_text($ctx['messages'])
    );
    $result = aimee_engine_openrouter_request($messages, aimee_engine_specialist_models(), ['max_tokens' => 650]);
    $out['latency_ms'] = $result['latency_ms'];
    $out['model'] = $result['model'];
    $out['provider'] = $result['provider'] ?: 'openrouter';
    if (!$result['ok']) {
        $out['error_type'] = $result['error_type'];
        return $out;
    }
    if (function_exists('aimee_openrouter_is_context_acknowledgement') && aimee_openrouter_is_context_acknowledgement($result['text'])) {
        $out['error_type'] = 'context_acknowledgement_echo';
        return $out;
    }

    $parsed = aimee_engine_extract_photo_token($result['text']);
    if ($parsed['key'] !== '' && isset($eligible[$parsed['key']])) {
        $out['photo'] = aimee_engine_deliver_photo($ctx['user_id'], $parsed['key'], $eligible, [
            'request_id'      => $ctx['request_id'],
            'route'           => 'intimacy_specialist',
            'actual_model'    => $result['model'],
            'actual_provider' => $out['provider'],
            'intimacy'        => $ctx['intimacy'],
            'classification'  => $ctx['legacy_classification'],
        ]);
    }
    $out['reply'] = aimee_engine_clean_reply($parsed['text']);
    $out['ok'] = $out['reply'] !== '';
    if (!$out['ok']) $out['error_type'] = 'empty_reply';
    return $out;
}

function aimee_engine_primary_body(array $ctx) {
    return aimee_engine_anthropic_build_body((string) $ctx['model'], $ctx['system_blocks'], $ctx['messages'], [
        'max_tokens' => intval($ctx['max_tokens']),
        'effort'     => (string) $ctx['effort'],
        'tools'      => !empty($ctx['tool']) ? [$ctx['tool']] : [],
    ]);
}

/**
 * Read-only relationship snapshot used for facts and photo eligibility before
 * this turn's maths has run. One turn of lag is immaterial for either.
 */
function aimee_engine_intimacy_snapshot($profile, $current_stage) {
    $snapshot = [
        'score' => max(0, min(100, intval($profile->intimacy_score ?? 8))),
        'stage' => sanitize_key((string) $current_stage),
        'use_intimacy_model' => false,
    ];
    if (function_exists('aimee_load_relationship_state')) {
        $state = aimee_load_relationship_state($profile);
        if (is_array($state)) {
            foreach (['trust', 'affection', 'chemistry', 'safety', 'reciprocity', 'reliability', 'frustration', 'meaningful_interaction_count', 'qualified_session_count', 'session_count'] as $key) {
                if (array_key_exists($key, $state)) $snapshot[$key] = $state[$key];
            }
        }
    }
    return $snapshot;
}

function aimee_engine_error_response($code, $message, $status) {
    return new WP_Error($code, $message, ['status' => $status]);
}

/**
 * The turn. Same request and response contract as Aimee Global's
 * handle_aimee_message for the in-app channel, so the chat UI is unchanged.
 */
function aimee_engine_handle_message(WP_REST_Request $request, $emit = null) {
    global $wpdb;

    $started = microtime(true);
    $streaming = is_callable($emit) && aimee_engine_setting('streaming') && aimee_engine_streaming_available();
    $emitted_text = '';
    $emit_delta = function ($text) use (&$emitted_text, $emit) {
        if ($text === '' || !is_callable($emit)) return;
        $emitted_text .= $text;
        $emit('delta', ['text' => $text]);
    };
    $params = $request->get_json_params();
    if (!is_array($params)) $params = [];
    $user_id = get_current_user_id();
    if (!$user_id) return aimee_engine_error_response('authentication_required', 'Authentication required.', 401);
    if (!aimee_rate_limit('message_' . $user_id, 50, 10 * MINUTE_IN_SECONDS)) {
        return aimee_engine_error_response('rate_limited', 'You are sending messages too quickly. Please pause for a moment.', 429);
    }

    $user_text = mb_substr(sanitize_textarea_field($params['message'] ?? ''), 0, 4000);
    $raw_image = isset($params['image']) && is_string($params['image']) ? $params['image'] : null;
    $image_event_id = function_exists('aimee_user_image_event_normalize_id')
        ? aimee_user_image_event_normalize_id($params['image_event_id'] ?? $params['attachment_event_id'] ?? '')
        : '';
    $raw_reference = array_key_exists('referenced_media_key', $params) ? $params['referenced_media_key'] : '';
    $request_id = sanitize_text_field((string) ($params['request_id'] ?? ''));
    if ($request_id === '') $request_id = sanitize_text_field((string) $request->get_header('X-Request-ID'));
    if ($request_id === '') $request_id = function_exists('aimee_media_state_uuid') ? aimee_media_state_uuid() : wp_generate_uuid4();

    if ($user_text === '' && empty($raw_image)) {
        return aimee_engine_error_response('empty_message', 'Message cannot be empty.', 400);
    }

    $messages_table = aimee_table('aimee_messages');
    $profile_table = aimee_table('aimee_user_profiles');
    $ledger_table = aimee_table('aimee_relationship_state');
    $pk = aimee_messages_primary_key();

    $profile = $wpdb->get_row($wpdb->prepare("SELECT * FROM $profile_table WHERE user_id = %d", $user_id));
    if (!$profile) return aimee_engine_error_response('profile_missing', 'Aimee profile not found.', 404);

    $subscription = aimee_get_subscription_snapshot($user_id, $profile);
    if (!aimee_user_has_chat_access($user_id, $profile)) {
        $reactivation = function_exists('aimee_global_billing_reactivation_required') && aimee_global_billing_reactivation_required($profile);
        $reply = $reactivation
            ? "Our conversation and everything I remember are still here. Your previous subscription was linked to our former payment account, which is now closed, so it cannot renew or charge you. When you're ready, set up a new membership and we carry on exactly where we left off. x"
            : "I was enjoying where this was going. Your complimentary preview has ended, but our conversation and everything I remember are still here when you choose to continue. x";
        return rest_ensure_response([
            'reply'        => $reply,
            'reply_text'   => $reply,
            'status'       => $reactivation ? 'billing_reactivation_required' : 'trial_ended',
            'subscription' => $subscription,
            'engine'       => 'v2',
        ]);
    }

    // Camera Roll reference: discussion only, never a new photo.
    $gallery_reference = null;
    if ($raw_reference !== '' && $raw_reference !== null) {
        $gallery_reference = is_string($raw_reference) && function_exists('aimee_gallery_referenced_media_context')
            ? aimee_gallery_referenced_media_context($user_id, $profile, $raw_reference)
            : null;
        if (!is_array($gallery_reference)) {
            return aimee_engine_error_response('photo_unavailable', 'That photograph is not available in your Camera Roll.', 404);
        }
        if (!empty($raw_image)) {
            return aimee_engine_error_response('ambiguous_image_reference', 'Ask about either the Camera Roll photograph or the attached image in one message, not both.', 400);
        }
    }
    $discussion_only = is_array($gallery_reference);

    // Attached image: resolved server-side so retained client state cannot
    // replay the same upload as a fresh one.
    $image_event = aimee_user_image_event_resolve($user_id, $raw_image, $user_text, $image_event_id);
    if (($image_event['event'] ?? '') === 'schema_unavailable') {
        return new WP_REST_Response([
            'status'     => 'image_temporarily_unavailable',
            'message'    => 'The image could not be attached safely just now. Please retry shortly.',
            'request_id' => $request_id,
        ], 503);
    }
    $image_data_uri = !empty($image_event['use_vision']) ? (string) ($image_event['data_uri'] ?? '') : '';

    if (($image_event['event'] ?? '') === 'stale_duplicate' && $user_text === '') {
        $reservation = aimee_turn_request_reserve($user_id, $request_id);
        if (($reservation['status'] ?? '') === 'replay') return rest_ensure_response((array) ($reservation['response'] ?? []));
        if (($reservation['status'] ?? '') !== 'acquired') {
            return new WP_REST_Response(['status' => 'request_in_progress', 'message' => 'This exact request is already being processed.', 'request_id' => $request_id], 409);
        }
        $ignored = ['reply' => '', 'reply_text' => '', 'status' => 'success', 'duplicate_image_ignored' => true, 'request_id' => $request_id, 'engine' => 'v2'];
        aimee_turn_request_finish($user_id, $request_id, 'completed', $ignored);
        return rest_ensure_response($ignored);
    }
    if ($user_text === '' && $image_data_uri === '') {
        return aimee_engine_error_response('invalid_image', 'The image could not be read as a fresh attachment.', 400);
    }

    $reservation = aimee_turn_request_reserve($user_id, $request_id);
    if (($reservation['status'] ?? '') === 'replay') return rest_ensure_response((array) ($reservation['response'] ?? []));
    if (($reservation['status'] ?? '') !== 'acquired') {
        return new WP_REST_Response(['status' => 'request_in_progress', 'message' => 'This exact request is already being processed.', 'request_id' => $request_id], 409);
    }

    // ---- History and state -------------------------------------------------
    $timings = ['gates_ms' => intval((microtime(true) - $started) * 1000)];
    $phase = microtime(true);
    $history_limit = max(6, intval(aimee_engine_setting('history_messages')));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT {$pk} AS message_id, sender, message_text, image_url, created_at
         FROM $messages_table WHERE user_id = %d
         ORDER BY created_at DESC, {$pk} DESC LIMIT %d",
        $user_id,
        $history_limit
    ));
    $rows = is_array($rows) ? array_reverse($rows) : [];
    $last_row = $rows ? end($rows) : null;
    $last_created_at = $last_row ? (string) $last_row->created_at : '';
    $last_sender = $last_row ? (string) $last_row->sender : '';
    $last_text = $last_row ? (string) $last_row->message_text : '';
    $last_message_id = $last_row ? intval($last_row->message_id) : 0;

    $user_message_count = intval($wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $messages_table WHERE user_id = %d AND sender = 'user'",
        $user_id
    ))) + 1;
    $current_stage = !empty($profile->intimacy_stage)
        ? (string) $profile->intimacy_stage
        : (function_exists('aimee_stage_from_score') ? aimee_stage_from_score($profile->intimacy_score ?? 8) : 'guarded');

    $is_owner = function_exists('aimee_is_owner_user') && aimee_is_owner_user($profile);
    $name = $is_owner ? 'Paul' : trim((string) ($profile->first_name ?? ''));
    $classify_text = $user_text !== '' ? $user_text : '[attached a photo]';
    $history_string = aimee_engine_history_string($rows);

    // Store the user's message first so it survives whatever the providers do.
    $inserted = $wpdb->insert($messages_table, [
        'user_id'                => $user_id,
        'sender'                 => 'user',
        'message_text'           => $user_text,
        'image_url'              => aimee_user_image_event_message_marker($image_event),
        'user_image_fingerprint' => !empty($image_event['valid']) ? (string) ($image_event['fingerprint'] ?? '') : null,
        'user_image_event'       => !empty($image_event['raw_payload_present']) ? (string) ($image_event['event'] ?? 'invalid') : null,
        'user_image_event_id'    => !empty($image_event['raw_payload_present']) ? (string) ($image_event['event_id'] ?? '') : null,
        'is_sms'                 => 0,
    ]);
    $user_message_id = $inserted !== false ? intval($wpdb->insert_id) : 0;
    if (!$user_message_id) {
        $error = ['status' => 'error', 'message' => 'The message could not be saved. Nothing was sent.', 'request_id' => $request_id];
        aimee_turn_request_finish($user_id, $request_id, 'failed', $error, 'user_message_insert_failed');
        return new WP_REST_Response($error, 503);
    }

    // ---- Provisional read of the moment -------------------------------------
    // The classifier runs in parallel with the main model. A deterministic
    // read decides what the main model is told and which photos it may offer;
    // the real classification arrives with the reply and drives the
    // relationship maths and the explicit re-route.
    $provisional = aimee_engine_fallback_classification($classify_text, $history_string);
    $provisional_legacy = aimee_engine_classification_to_legacy($provisional);
    $snapshot = aimee_engine_intimacy_snapshot($profile, $current_stage);
    $specialist_possible = defined('OPENROUTER_API_KEY') && trim((string) OPENROUTER_API_KEY) !== ''
        && $image_data_uri === '' && !$discussion_only;

    $live_data = aimee_engine_live_context();
    $gap = function_exists('aimee_conversation_gap_snapshot') ? aimee_conversation_gap_snapshot($last_created_at, $live_data) : [];
    if (!is_array($gap)) $gap = [];
    $inner_state = function_exists('aimee_load_inner_state') ? aimee_load_inner_state($user_id) : [];
    if (!is_array($inner_state)) $inner_state = [];

    $sent = aimee_engine_previously_sent_photos($user_id);
    $eligible = [];
    if (!$discussion_only && $provisional['route'] === 'everyday') {
        $eligible = aimee_engine_eligible_photos($user_id, $profile, 'primary', $provisional_legacy, $snapshot);
    }

    $memory_context = aimee_memory_context_for_turn($user_id, $user_text);
    $opinions = function_exists('aimee_opinion_context_directive') ? aimee_opinion_context_directive($user_id, $user_text) : '';
    $profile_context = function_exists('aimee_user_profile_attribution_context') ? aimee_user_profile_attribution_context($profile, $name) : '';
    $dossier = trim(implode("\n\n", array_filter([
        is_string($profile_context) ? trim($profile_context) : '',
        trim((string) $memory_context),
        is_string($opinions) ? trim($opinions) : '',
        aimee_engine_mood_line($inner_state),
    ])));

    $build_facts = function (array $classification, $specialist_eligible) use ($profile, $name, $live_data, $gap, $snapshot, $gallery_reference, $eligible, $sent, $user_message_count) {
        return aimee_engine_build_facts([
            'profile'             => $profile,
            'name'                => $name,
            'live_data'           => $live_data,
            'gap'                 => $gap,
            'intimacy'            => $snapshot,
            'classification'      => $classification,
            'specialist_eligible' => $specialist_eligible,
            'gallery_reference'   => $gallery_reference,
            'eligible_count'      => count($eligible),
            'sent_count'          => count($sent),
            'user_message_count'  => $user_message_count,
        ]);
    };
    $card = aimee_engine_character_card();
    $facts = $build_facts($provisional, false);
    $system_blocks = aimee_engine_system_blocks($card, $dossier, $facts);

    $messages = aimee_engine_transcript_messages($rows, [
        'max_characters' => intval(aimee_engine_setting('history_characters')),
        'photo_alts'     => aimee_engine_catalogue_alts(),
    ]);
    $current_text = $classify_text;
    if ($image_data_uri !== '' && preg_match('#^data:(image/[a-z0-9.+-]+);base64,(.+)$#is', $image_data_uri, $m)) {
        $current = [
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => strtolower($m[1]), 'data' => $m[2]]],
            ['type' => 'text', 'text' => $current_text],
        ];
        $last = count($messages) - 1;
        if ($last >= 0 && $messages[$last]['role'] === 'user') {
            $previous = is_string($messages[$last]['content'])
                ? [['type' => 'text', 'text' => $messages[$last]['content']]]
                : (array) $messages[$last]['content'];
            $messages[$last]['content'] = array_merge($previous, $current);
        } else {
            $messages[] = ['role' => 'user', 'content' => $current];
        }
    } else {
        $last = count($messages) - 1;
        if ($last >= 0 && $messages[$last]['role'] === 'user' && is_string($messages[$last]['content'])) {
            $messages[$last]['content'] .= "\n\n" . $current_text;
        } else {
            $messages[] = ['role' => 'user', 'content' => $current_text];
        }
    }

    $ctx = [
        'user_id'                    => $user_id,
        'request_id'                 => $request_id,
        'model'                      => (string) aimee_engine_setting('primary_model'),
        'effort'                     => (string) aimee_engine_setting('primary_effort'),
        'max_tokens'                 => intval(aimee_engine_setting('reply_max_tokens')),
        'system_blocks'              => $system_blocks,
        'messages'                   => $messages,
        'tool'                       => aimee_engine_photo_tool($eligible, $sent),
        'eligible'                   => $eligible,
        'specialist_eligible_photos' => [],
        'sent'                       => $sent,
        'card'                       => $card,
        'dossier'                    => $dossier,
        'facts'                      => $facts,
        'intimacy'                   => $snapshot,
        'legacy_classification'      => $provisional_legacy,
    ];
    $timings['context_ms'] = intval((microtime(true) - $phase) * 1000);

    // ---- Classify and generate ---------------------------------------------
    // Ordinary turns: classifier and main model in flight together.
    // Turns that already look explicit: classify first, since the answer
    // decides which model writes at all.
    $phase = microtime(true);
    $classification = null;
    $initial_primary = null;
    $classifier_body = aimee_engine_classifier_body($classify_text, $rows, $current_stage);

    if ($provisional['route'] === 'everyday' && $streaming) {
        // Text is held back until the classifier has spoken, then released
        // live. If the classifier changes the moment, the stream is dropped
        // before a single word reaches him.
        $held = '';
        $release = false;
        $on_text = function ($text) use (&$held, &$release, $emit_delta) {
            if ($release) { $emit_delta($text); return; }
            $held .= $text;
        };
        $on_extra = function ($key, $result) use (&$classification, &$held, &$release, $classify_text, $history_string, $emit_delta, $emit) {
            $classification = aimee_engine_classification_from_result($result, $classify_text, $history_string);
            if ($classification['route'] !== 'everyday') return false;
            $release = true;
            if (is_callable($emit)) $emit('status', ['state' => 'writing']);
            if ($held !== '') { $emit_delta($held); $held = ''; }
            return true;
        };
        $streamed = aimee_engine_anthropic_stream(aimee_engine_primary_body($ctx), $on_text, ['classifier' => $classifier_body], $on_extra, 120);
        if ($classification === null) {
            $classification = aimee_engine_classification_from_result($streamed['extras']['classifier'] ?? ['ok' => false, 'error_type' => 'incomplete'], $classify_text, $history_string);
        }
        if (!$streamed['aborted']) {
            $initial_primary = $streamed['primary'];
            if (!$release && !empty($initial_primary['ok']) && $initial_primary['stop_reason'] !== 'refusal') {
                // Classifier finished after the primary; release now.
                $release = true;
                if (is_callable($emit)) $emit('status', ['state' => 'writing']);
                if ($held !== '') { $emit_delta($held); $held = ''; }
            }
        }
    } elseif ($provisional['route'] === 'everyday') {
        $results = aimee_engine_anthropic_request_multiple([
            'classifier' => $classifier_body,
            'primary'    => aimee_engine_primary_body($ctx),
        ], 120);
        $classification = aimee_engine_classification_from_result($results['classifier'], $classify_text, $history_string);
        $initial_primary = $results['primary'];
    } else {
        $classification = aimee_engine_classification_from_result(aimee_engine_anthropic_request($classifier_body, 30), $classify_text, $history_string);
    }
    $timings['classify_ms'] = intval((microtime(true) - $phase) * 1000);

    // ---- Relationship maths with the real classification --------------------
    $phase = microtime(true);
    $legacy_classification = aimee_engine_classification_to_legacy($classification);
    $ledger = $wpdb->get_row($wpdb->prepare("SELECT * FROM $ledger_table WHERE user_id = %d", $user_id));
    if (!$ledger) {
        $wpdb->insert($ledger_table, ['user_id' => $user_id]);
        $ledger = $wpdb->get_row($wpdb->prepare("SELECT * FROM $ledger_table WHERE user_id = %d", $user_id));
    }
    if (!$ledger) $ledger = (object) ['overall_equity' => 50, 'inquiry_ratio' => 50, 'fantasy_imposition' => 0];

    $intimacy = aimee_calculate_intimacy_state($profile, $ledger, $legacy_classification, $user_text, $user_message_count, $history_string);
    if (!is_array($intimacy)) $intimacy = $snapshot;
    if (!empty($intimacy['classifier_for_relationship']) && is_array($intimacy['classifier_for_relationship'])) {
        $legacy_classification = $intimacy['classifier_for_relationship'];
    }
    if ($image_data_uri !== '') $intimacy['use_intimacy_model'] = false;

    $inner_state = aimee_appraise_user_turn($user_id, $user_text, $legacy_classification, $intimacy, $gap, [
        'last_sender'       => $last_sender,
        'last_created_at'   => $last_created_at,
        'last_directive'    => '',
        'last_message_id'   => $last_message_id,
        'last_message_text' => $last_text,
    ]);
    if (!is_array($inner_state)) $inner_state = [];
    unset($inner_state['_persistence_ok']);

    if (!empty($intimacy['relationship_state']) && is_array($intimacy['relationship_state'])) {
        aimee_save_relationship_state($user_id, $intimacy['relationship_state']);
    }
    $wpdb->update($profile_table, [
        'intimacy_score' => intval($intimacy['score'] ?? 0),
        'intimacy_stage' => sanitize_key((string) ($intimacy['stage'] ?? 'guarded')),
    ], ['user_id' => $user_id], ['%d', '%s'], ['%d']);

    $specialist_eligible = $specialist_possible
        && $classification['route'] === 'erotic'
        && !empty($intimacy['use_intimacy_model']);
    $ctx['intimacy'] = $intimacy;
    $ctx['legacy_classification'] = $legacy_classification;
    $ctx['specialist_eligible_photos'] = $specialist_eligible
        ? aimee_engine_eligible_photos($user_id, $profile, 'intimacy_specialist', $legacy_classification, $intimacy)
        : [];
    $timings['relationship_ms'] = intval((microtime(true) - $phase) * 1000);

    // ---- Generate ------------------------------------------------------------
    $phase = microtime(true);
    $route = 'primary';
    $attempts = [];
    $reply = '';
    $photo = null;
    $actual_model = $ctx['model'];
    $actual_provider = 'anthropic';
    $refusal_category = '';
    $specialist_tried = false;

    if ($specialist_eligible) {
        // The real classification overruled the provisional read: the
        // specialist writes this one, and any in-flight primary reply is dropped.
        $specialist_tried = true;
        $s = aimee_engine_run_specialist($ctx);
        $attempts[] = ['route' => 'intimacy_specialist', 'ok' => $s['ok'], 'model' => $s['model'], 'error' => $s['error_type'], 'ms' => $s['latency_ms'], 'brief' => $s['brief'], 'brief_ms' => $s['brief_ms']];
        if ($s['ok']) {
            $route = 'intimacy_specialist';
            $reply = $s['reply'];
            $photo = $s['photo'];
            $actual_model = $s['model'];
            $actual_provider = $s['provider'];
            $initial_primary = null;
        }
    }

    if ($reply === '') {
        if ($initial_primary === null || $classification['route'] !== $provisional['route']) {
            // The moment changed under the provisional read (or there was no
            // parallel call): rebuild the facts and call fresh.
            $ctx['facts'] = $build_facts($classification, $specialist_eligible);
            $ctx['system_blocks'] = aimee_engine_system_blocks($card, $dossier, $ctx['facts']);
            if ($classification['route'] !== 'everyday') { $ctx['tool'] = null; $ctx['eligible'] = []; }
            $initial_primary = null;
        }
        $ctx['initial_result'] = $initial_primary;
        if ($streaming) {
            if (is_callable($emit)) $emit('status', ['state' => 'writing']);
            $ctx['stream'] = $emit_delta;
            $ctx['emit'] = $emit;
        }
        $p = aimee_engine_run_primary($ctx);
        $attempts[] = ['route' => 'primary', 'ok' => $p['ok'], 'model' => $p['model'], 'error' => $p['error_type'], 'refusal' => $p['refusal_category'], 'ms' => $p['latency_ms'], 'calls' => $p['calls'], 'parallel' => $initial_primary !== null];
        $photo = $p['photo'];
        $actual_model = $p['model'];
        $actual_provider = 'anthropic';

        if ($p['ok']) {
            $reply = $p['reply'];
        } elseif ($p['refusal_category'] !== '') {
            $refusal_category = $p['refusal_category'];
            // A refusal is a routing signal: the specialist if the relationship
            // allows it, otherwise the same model with the moment named plainly.
            if ($specialist_eligible && !$specialist_tried) {
                if ($emitted_text !== '' && is_callable($emit)) { $emit('replace', ['text' => '']); $emitted_text = ''; }
                $s = aimee_engine_run_specialist($ctx);
                $attempts[] = ['route' => 'intimacy_specialist', 'ok' => $s['ok'], 'model' => $s['model'], 'error' => $s['error_type'], 'ms' => $s['latency_ms']];
                if ($s['ok']) {
                    $route = 'intimacy_specialist';
                    $reply = $s['reply'];
                    $photo = $s['photo'] ?: $photo;
                    $actual_model = $s['model'];
                    $actual_provider = $s['provider'];
                }
            }
            if ($reply === '' && $photo === null) {
                if ($emitted_text !== '' && is_callable($emit)) { $emit('replace', ['text' => '']); $emitted_text = ''; }
                $retry = $ctx;
                $retry['tool'] = null;
                $retry['initial_result'] = null;
                $retry['system_blocks'] = aimee_engine_system_blocks($card, $dossier, $ctx['facts'] . "\n- He has pushed toward territory she is not going to describe explicitly right now. She stays herself: warm, a little teasing, unbothered, and simply does not go there. No lecture, no mention of rules.");
                $p2 = aimee_engine_run_primary($retry);
                $attempts[] = ['route' => 'primary_retry', 'ok' => $p2['ok'], 'model' => $p2['model'], 'error' => $p2['error_type'], 'refusal' => $p2['refusal_category'], 'ms' => $p2['latency_ms']];
                if ($p2['ok']) {
                    $route = 'primary_retry';
                    $reply = $p2['reply'];
                    $actual_model = $p2['model'];
                }
            }
        }
    }
    $timings['generate_ms'] = intval((microtime(true) - $phase) * 1000);
    $phase = microtime(true);

    $generation_failed = $reply === '';
    if ($generation_failed) {
        $route = 'fallback';
        $reply = "I'm here. Something tangled on my end for a second; say that again for me? x";
        if ($photo && !empty($photo['ok'])) {
            aimee_media_delivery_transition($photo['delivery_id'], 'failed', ['error_code' => 'reply_generation_failed']);
            $photo = null;
        }
    }

    // ---- Persist Aimee's message and finish the photo delivery --------------
    $media_payload = null;
    $media_delivery_id = '';
    $media_decision_id = '';
    $media_key = '';
    if ($photo && !empty($photo['ok'])) {
        $media_delivery_id = (string) $photo['delivery_id'];
        $media_decision_id = (string) $photo['decision_id'];
        $media_key = (string) $photo['key'];
        $media_payload = $photo['payload'];
    }
    $route_log = mb_substr(sprintf('engine_v2 route=%s model=%s classifier=%s', $route, $actual_model, $classification['route']), 0, 250);

    $aimee_inserted = $wpdb->insert($messages_table, [
        'user_id'             => $user_id,
        'sender'              => 'aimee',
        'message_text'        => $reply,
        'image_url'           => $media_key !== '' ? 'aimee-media:' . $media_key : null,
        'evaluator_directive' => $route_log,
        'is_sms'              => 0,
        'media_decision_id'   => $media_decision_id ?: null,
        'media_delivery_id'   => $media_delivery_id ?: null,
    ]);
    $aimee_message_id = $aimee_inserted !== false ? intval($wpdb->insert_id) : 0;
    if (!$aimee_message_id) {
        if ($media_delivery_id !== '') aimee_media_delivery_transition($media_delivery_id, 'failed', ['error_code' => 'aimee_message_insert_failed']);
        $error = ['status' => 'error', 'message' => 'The reply could not be saved.', 'request_id' => $request_id];
        aimee_turn_request_finish($user_id, $request_id, 'failed', $error, 'aimee_message_insert_failed');
        return new WP_REST_Response($error, 503);
    }

    $media_delivery_snapshot = null;
    if ($media_delivery_id !== '') {
        $created = aimee_media_delivery_transition($media_delivery_id, 'message_created', ['message_id' => $aimee_message_id]);
        $returned = $created && aimee_media_delivery_transition($media_delivery_id, 'returned_by_direct_api');
        if (!$returned) {
            aimee_media_delivery_transition($media_delivery_id, 'failed', ['error_code' => $created ? 'direct_return_transition_failed' : 'message_created_transition_failed']);
            $reply = "I chose a photograph, but the delivery handoff failed. I won't say it reached you.";
            $wpdb->update($messages_table, ['message_text' => $reply, 'image_url' => null], [$pk => $aimee_message_id], ['%s', '%s'], ['%d']);
            $media_payload = null;
            $media_key = '';
        }
        if (function_exists('aimee_media_delivery_find') && function_exists('aimee_media_delivery_public_snapshot')) {
            $media_delivery_snapshot = aimee_media_delivery_public_snapshot(aimee_media_delivery_find($media_delivery_id, $user_id));
        }
        if ($media_payload && ($photo['rating'] ?? '') === 'safe' && function_exists('aimee_free_preview_safe_images_used')
            && !aimee_subscription_is_active($profile) && !aimee_is_admin_user($profile)) {
            aimee_free_preview_safe_images_used($user_id, true);
        }
    }

    // ---- Bookkeeping ---------------------------------------------------------
    $wpdb->update($ledger_table, ['last_interaction' => current_time('mysql')], ['user_id' => $user_id]);
    if ($route === 'intimacy_specialist') {
        $wpdb->update($profile_table, ['last_intimacy_route_at' => current_time('mysql', true)], ['user_id' => $user_id], ['%s'], ['%d']);
    }
    aimee_increment_preview_usage($user_id, $profile);
    aimee_record_turn_timeline($user_id, $user_message_count, $user_message_id, $aimee_message_id, $media_key, 0);
    $observer = $generation_failed ? 'skipped' : aimee_engine_schedule_observer($user_id, $user_message_id, $aimee_message_id);

    $fresh_profile = $wpdb->get_row($wpdb->prepare("SELECT * FROM $profile_table WHERE user_id = %d", $user_id));
    $subscription = aimee_get_subscription_snapshot($user_id, $fresh_profile ?: $profile);

    aimee_engine_record_turn($user_id, [
        'route'               => $route,
        'classifier_route'    => $classification['route'],
        'classifier_tone'     => $classification['tone'],
        'classifier_source'   => $classification['source'],
        'classifier_error'    => $classification['classifier_error'] ?? '',
        'intent'              => $legacy_classification['intent'] ?? '',
        'specialist_eligible' => $specialist_eligible,
        'actual_model'        => $actual_model,
        'actual_provider'     => $actual_provider,
        'refusal_category'    => $refusal_category,
        'attempts'            => $attempts,
        'stage'               => $intimacy['stage'] ?? '',
        'score'               => intval($intimacy['score'] ?? 0),
        'photo_offered'       => count($eligible),
        'photo_key'           => $media_key,
        'photo_reason'        => $photo ? ($photo['reason'] ?? '') : '',
        'has_image'           => $image_data_uri !== '',
        'history_messages'    => count($rows),
        'reply_characters'    => mb_strlen($reply),
        'observer'            => $observer,
        'provisional_route'   => $provisional['route'],
        'streamed'            => $streaming,
        'timings'             => $timings + ['persist_ms' => intval((microtime(true) - $phase) * 1000)],
        'total_ms'            => intval((microtime(true) - $started) * 1000),
    ]);

    $response = [
        'reply'                 => $reply,
        'reply_text'            => $reply,
        'new_equity'            => intval($ledger->overall_equity ?? 50),
        'status'                => 'success',
        'subscription'          => $subscription,
        'media'                 => $media_payload,
        'media_delivery'        => $media_delivery_snapshot,
        'media_materialization' => ['status' => $media_payload ? 'ready' : 'unavailable'],
        'user_message_id'       => $user_message_id,
        'aimee_message_id'      => $aimee_message_id,
        'request_id'            => $request_id,
        'voice_route'           => null,
        'engine'                => 'v2',
    ];
    aimee_turn_request_finish($user_id, $request_id, 'completed', $response);
    return rest_ensure_response($response);
}
