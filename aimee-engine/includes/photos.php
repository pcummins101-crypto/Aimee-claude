<?php
defined('ABSPATH') || exit;

/**
 * Photos Aimee has already shared with this user: key => last sent (UTC).
 */
function aimee_engine_previously_sent_photos($user_id) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT image_url, MAX(created_at) AS last_sent
         FROM " . aimee_table('aimee_messages') . "
         WHERE user_id = %d AND sender = 'aimee' AND image_url LIKE %s
         GROUP BY image_url",
        intval($user_id),
        'aimee-media:%'
    ));
    $sent = [];
    foreach ((array) $rows as $row) {
        $key = sanitize_key(substr((string) $row->image_url, strlen('aimee-media:')));
        if ($key !== '') $sent[$key] = (string) $row->last_sent;
    }
    return $sent;
}

function aimee_engine_last_photo_sent_seconds_ago($user_id) {
    global $wpdb;
    $last = $wpdb->get_var($wpdb->prepare(
        "SELECT MAX(created_at) FROM " . aimee_table('aimee_messages') . "
         WHERE user_id = %d AND sender = 'aimee' AND image_url LIKE %s",
        intval($user_id),
        'aimee-media:%'
    ));
    if (!$last) return PHP_INT_MAX;
    $ts = strtotime((string) $last . ' UTC');
    return $ts ? max(0, time() - $ts) : PHP_INT_MAX;
}

/**
 * Catalogue items this user may receive this turn, according to Aimee
 * Global's entitlement and relationship policy, minus the engine's own
 * cooldown. The route name matters: explicit items require the specialist.
 */
function aimee_engine_eligible_photos($user_id, $profile, $route, array $legacy_classification, array $intimacy) {
    $cooldown = intval(aimee_engine_setting('photo_cooldown_minutes')) * 60;
    if ($cooldown > 0 && aimee_engine_last_photo_sent_seconds_ago($user_id) < $cooldown) {
        return [];
    }
    $eligible = aimee_get_eligible_private_media_catalog($user_id, $profile, $route, $legacy_classification, $intimacy);
    return is_array($eligible) ? $eligible : [];
}

function aimee_engine_catalogue_alts() {
    if (!function_exists('aimee_private_media_catalog')) return [];
    $alts = [];
    foreach ((array) aimee_private_media_catalog() as $key => $item) {
        $alts[$key] = (string) ($item['alt'] ?? '');
    }
    return $alts;
}

/**
 * Short human labels for each offered photo, shared with both routes.
 */
function aimee_engine_photo_offer_labels(array $eligible, array $sent) {
    $labels = [];
    foreach ($eligible as $key => $item) {
        $alt = trim((string) ($item['alt'] ?? $key));
        $description = trim((string) ($item['description'] ?? ''));
        $rating = sanitize_key((string) ($item['content_rating'] ?? 'safe'));
        $label = $alt;
        if ($description !== '' && $description !== $alt) $label .= '. ' . $description;
        $label .= ' [' . $rating . ']';
        if (!empty($sent[$key])) {
            $when = aimee_engine_local_datetime($sent[$key]);
            $label .= ' (already shared with him' . ($when ? ' on ' . $when->format('j M') : '') . ')';
        }
        $labels[$key] = $label;
    }
    return $labels;
}

/**
 * The send_photo tool. The enum is the whole permission model: a key that
 * is not listed cannot be sent, and a photo that is not delivered by this
 * tool was not sent, whatever the reply says.
 */
function aimee_engine_photo_tool(array $eligible, array $sent) {
    if (!$eligible) return null;
    $labels = aimee_engine_photo_offer_labels($eligible, $sent);
    $lines = [];
    foreach ($labels as $key => $label) $lines[] = $key . ': ' . $label;
    return [
        'name'        => 'send_photo',
        'description' => "Share one of Aimee's photos with him as part of this message. Use it only when a photo genuinely fits the moment or he has asked for one; never as filler, and at most once per message. The photo is delivered by the platform; after calling this, write the message that goes with it. Available now:\n" . implode("\n", $lines),
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'key' => ['type' => 'string', 'enum' => array_values(array_keys($eligible))],
            ],
            'required'             => ['key'],
            'additionalProperties' => false,
        ],
    ];
}

/**
 * The specialist has no tool use, so it marks a photo choice with a token.
 * Returns the text without the token and the key it named (or '').
 */
function aimee_engine_extract_photo_token($text) {
    $text = (string) $text;
    $key = '';
    if (preg_match('/\[\[\s*photo\s*:\s*([A-Za-z0-9_\-]+)\s*\]\]/iu', $text, $m)) {
        $key = sanitize_key($m[1]);
    }
    $clean = trim(preg_replace('/\[\[\s*photo\s*:[^\]]*\]\]/iu', '', $text));
    return ['text' => $clean, 'key' => $key];
}

/**
 * Deliver one photo through Aimee Global's decision and delivery pipeline.
 * Every access check is re-run against a fresh profile at authorisation time.
 *
 * $ctx: request_id, route, actual_model, actual_provider, intimacy, classification
 */
function aimee_engine_deliver_photo($user_id, $key, array $eligible, array $ctx) {
    global $wpdb;

    $key = sanitize_key($key);
    $user_id = intval($user_id);
    $fail = function ($reason, $delivery_id = '', $decision_id = '') {
        if ($delivery_id !== '' && function_exists('aimee_media_delivery_transition')) {
            aimee_media_delivery_transition($delivery_id, 'failed', ['error_code' => sanitize_key($reason)]);
        }
        return ['ok' => false, 'reason' => sanitize_key($reason), 'delivery_id' => $delivery_id, 'decision_id' => $decision_id, 'payload' => null, 'item' => null];
    };

    if ($key === '' || !isset($eligible[$key])) return $fail('key_not_eligible');
    $item = $eligible[$key];
    $intimacy = is_array($ctx['intimacy'] ?? null) ? $ctx['intimacy'] : [];

    $profile = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . aimee_table('aimee_user_profiles') . " WHERE user_id = %d",
        $user_id
    ));
    if (!$profile) return $fail('profile_missing');

    $adult_state = function_exists('aimee_adult_assurance_state') ? aimee_adult_assurance_state($profile) : 'unknown';
    $is_admin = aimee_is_admin_user($profile);
    $is_member = aimee_subscription_is_active($profile);
    $is_preview = function_exists('aimee_free_preview_is_active') && aimee_free_preview_is_active($profile);

    $decision = [
        'source'               => 'engine_v2',
        'policy_version'       => 'engine-v2-' . AIMEE_ENGINE_VERSION,
        'model_route'          => sanitize_key((string) ($ctx['route'] ?? 'primary')),
        'actual_model'         => (string) ($ctx['actual_model'] ?? ''),
        'actual_provider'      => (string) ($ctx['actual_provider'] ?? ''),
        'direct_request'       => false,
        'media_opportunity'    => true,
        'maximum_rating'       => sanitize_key((string) ($item['content_rating'] ?? 'safe')),
        'reason_code'          => 'model_tool_choice',
        'reason'               => 'Chosen by the conversation model from the eligible catalogue.',
        'access_state'         => $is_admin ? 'admin' : ($is_member ? 'member' : ($is_preview ? 'preview' : 'unavailable')),
        'adult_assurance'      => sanitize_key((string) $adult_state),
        'mutual_context_active'=> !empty($ctx['classification']['mutual_explicit_context']),
        'pressure_detected'    => false,
        'eligible_keys'        => array_keys($eligible),
        'relationship_snapshot'=> [
            'score' => intval($intimacy['score'] ?? 0),
            'stage' => sanitize_key((string) ($intimacy['stage'] ?? 'guarded')),
        ],
        'policy_snapshot'      => ['engine' => 'v2'],
        'aimee_decision'       => 'send',
        'media_key'            => $key,
        'media_reason_code'    => 'model_tool_choice',
        'send_authorised'      => true,
    ];
    $decision_id = aimee_media_decision_store($user_id, $decision, (string) ($ctx['request_id'] ?? ''));
    if ($decision_id === '') return $fail('decision_store_failed');

    $delivery_id = aimee_media_delivery_create($decision_id, $user_id, $key);
    if ($delivery_id === '') return $fail('delivery_create_failed', '', $decision_id);
    if (!aimee_media_delivery_transition($delivery_id, 'catalogue_resolved')) {
        return $fail('catalogue_transition_failed', $delivery_id, $decision_id);
    }

    // Fresh authorisation: membership opens the feature, adult assurance
    // gates the rating, and the preview only ever sees safe images.
    $rating = sanitize_key((string) ($item['content_rating'] ?? 'safe'));
    $media_access = $is_admin || $is_member || ($rating === 'safe' && $is_preview);
    $rating_access = $rating === 'safe' || $is_admin || $is_member;
    $adult_access = intval($profile->age ?? 0) >= 18
        && !in_array($adult_state, ['underage', 'unknown'], true)
        && (
            !in_array($rating, ['erotic', 'explicit'], true)
            || (function_exists('aimee_adult_special_category_access_is_active') && aimee_adult_special_category_access_is_active($profile))
        );
    if (!$media_access || !$rating_access || !$adult_access) {
        return $fail('authorisation_denied', $delivery_id, $decision_id);
    }
    if (!aimee_media_delivery_transition($delivery_id, 'authorised')) {
        return $fail('authorisation_transition_failed', $delivery_id, $decision_id);
    }

    if (function_exists('aimee_materialize_authorised_media_delivery')) {
        $materialised = aimee_materialize_authorised_media_delivery([
            'user_id'     => $user_id,
            'delivery_id' => $delivery_id,
            'media_key'   => $key,
            'channel'     => 'chat',
            'request_id'  => (string) ($ctx['request_id'] ?? ''),
        ]);
        $status = sanitize_key((string) ($materialised['status'] ?? 'unavailable'));
        if ($status === 'failed') return $fail('materialisation_failed', $delivery_id, $decision_id);
        if ($status === 'pending') {
            // Generated-on-demand assets need Global's interim-message flow,
            // which this engine does not replicate yet. Decline honestly.
            return $fail('materialisation_pending_unsupported', $delivery_id, $decision_id);
        }
    }

    $asset = function_exists('aimee_private_media_delivery_asset')
        ? aimee_private_media_delivery_asset($key, $delivery_id)
        : null;
    if (!is_array($asset) || empty($asset['path'])) {
        return $fail('asset_unavailable', $delivery_id, $decision_id);
    }
    if (function_exists('aimee_media_delivery_bind_resolved_asset')
        && !aimee_media_delivery_bind_resolved_asset($delivery_id, $asset)) {
        return $fail('asset_bind_failed', $delivery_id, $decision_id);
    }

    $payload = aimee_private_media_payload($key, $delivery_id);
    if (!$payload) return $fail('payload_unavailable', $delivery_id, $decision_id);

    return [
        'ok'          => true,
        'reason'      => 'delivered',
        'key'         => $key,
        'item'        => $item,
        'payload'     => $payload,
        'delivery_id' => $delivery_id,
        'decision_id' => $decision_id,
        'rating'      => $rating,
    ];
}
