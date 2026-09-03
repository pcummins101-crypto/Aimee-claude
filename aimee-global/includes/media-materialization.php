<?php
/**
 * Provider-neutral, delivery-bound media materialization boundary.
 *
 * Relationship/media policy and Aimee's persisted choice must already have
 * authorised an immutable catalogue key. A trusted sidecar may then reserve or
 * render a private derivative for that one delivery, but it cannot add a key,
 * raise a rating, change ownership or publish the derivative to the gallery.
 */

defined('ABSPATH') || exit;

function aimee_media_materialization_rating_rank($rating) {
    if (function_exists('aimee_media_decision_rating_rank')) {
        return aimee_media_decision_rating_rank($rating);
    }

    $ranks = [
        'safe' => 0,
        'flirty' => 1,
        'suggestive' => 2,
        'erotic' => 3,
        'explicit' => 4,
    ];
    $rating = sanitize_key((string) $rating);
    return array_key_exists($rating, $ranks) ? $ranks[$rating] : -1;
}

function aimee_media_materialization_max_bytes() {
    $configured = defined('AIMEE_MEDIA_MATERIALIZATION_MAX_BYTES')
        ? intval(AIMEE_MEDIA_MATERIALIZATION_MAX_BYTES)
        : 20 * 1024 * 1024;

    return max(1024 * 1024, min(40 * 1024 * 1024, $configured));
}

/**
 * Pure owner-beta predicate. It may label an existing safe opportunity but it
 * must never manufacture eligibility or alter the immutable key set.
 */
function aimee_owner_safe_image_test_candidate(array $context) {
    $raw_eligible_keys = (array) ($context['eligible_keys'] ?? []);
    $raw_eligible_ratings = (array) ($context['eligible_ratings'] ?? []);
    foreach ($raw_eligible_keys as $raw_key) {
        if (
            !is_scalar($raw_key)
            || (string) $raw_key === ''
            || (string) $raw_key !== sanitize_key((string) $raw_key)
        ) {
            return false;
        }
    }
    foreach ($raw_eligible_ratings as $raw_rating) {
        if (!is_scalar($raw_rating) || (string) $raw_rating !== 'safe') {
            return false;
        }
    }

    $eligible_keys = array_values(array_filter(array_map(
        'sanitize_key',
        $raw_eligible_keys
    )));
    $eligible_ratings = array_values(array_filter(array_map(
        'sanitize_key',
        $raw_eligible_ratings
    )));

    return intval($context['user_id'] ?? 0) === 112
        && intval($context['configured_owner_id'] ?? 0) === 112
        && intval($context['authenticated_user_id'] ?? 0) === 112
        && !empty($context['profile_is_owner'])
        && sanitize_key((string) ($context['channel'] ?? '')) === 'chat'
        && !empty($context['direct_request'])
        && empty($context['resend'])
        && sanitize_key((string) (
            $context['requested_rating'] ?? ''
        )) === 'safe'
        && !empty($context['media_opportunity'])
        && !empty($eligible_keys)
        && !empty($eligible_ratings)
        && count($eligible_keys) === count($raw_eligible_keys)
        && count($eligible_ratings) === count($raw_eligible_ratings)
        && count($eligible_keys) === count(array_unique($eligible_keys))
        && count($eligible_keys) === count($eligible_ratings)
        && count(array_unique($eligible_ratings)) === 1
        && reset($eligible_ratings) === 'safe'
        && sanitize_key((string) (
            $context['maximum_rating'] ?? ''
        )) === 'safe'
        && !empty($context['access_available'])
        && !empty($context['adult_confirmed'])
        && !empty($context['respectful'])
        && !empty($context['cooldown_clear'])
        && empty($context['hard_veto'])
        && empty($context['pressure_detected']);
}

/** Detect an actual supported raster image; extensions are not authority. */
function aimee_media_materialization_image_facts($path) {
    $path = (string) $path;
    if (
        $path === ''
        || !is_file($path)
        || !is_readable($path)
        || filesize($path) <= 0
        || filesize($path) > aimee_media_materialization_max_bytes()
        || !function_exists('getimagesize')
    ) {
        return null;
    }

    $facts = @getimagesize($path);
    if (!is_array($facts)) return null;

    $width = intval($facts[0] ?? 0);
    $height = intval($facts[1] ?? 0);
    $mime = strtolower(trim((string) ($facts['mime'] ?? '')));
    if (
        $width < 256
        || $height < 256
        || $width > 4096
        || $height > 4096
        || !in_array(
            $mime,
            ['image/png', 'image/jpeg', 'image/gif', 'image/webp'],
            true
        )
    ) {
        return null;
    }

    return [
        'mime' => $mime,
        'width' => $width,
        'height' => $height,
        'bytes' => intval(filesize($path)),
    ];
}

/**
 * Validate a sidecar candidate against immutable delivery and catalogue facts.
 * Returning null leaves an unbound delivery unresolved.
 */
function aimee_media_materialization_validate_asset(
    $candidate,
    array $delivery,
    array $catalogue_item
) {
    if (!is_array($candidate)) return null;

    $delivery_id = sanitize_text_field((string) ($delivery['delivery_id'] ?? ''));
    $media_key = sanitize_key((string) ($delivery['media_key'] ?? ''));
    $user_id = intval($delivery['user_id'] ?? 0);
    if (
        $delivery_id === ''
        || $media_key === ''
        || $user_id <= 0
        || sanitize_text_field((string) ($candidate['delivery_id'] ?? '')) !== $delivery_id
        || sanitize_key((string) ($candidate['media_key'] ?? '')) !== $media_key
        || intval($candidate['user_id'] ?? 0) !== $user_id
    ) {
        return null;
    }

    $private_root = realpath(aimee_private_media_dir());
    $candidate_path = realpath((string) ($candidate['path'] ?? ''));
    if (
        !$private_root
        || !$candidate_path
        || strpos(
            $candidate_path,
            $private_root . DIRECTORY_SEPARATOR
        ) !== 0
        || !is_file($candidate_path)
        || !is_readable($candidate_path)
    ) {
        return null;
    }

    $facts = aimee_media_materialization_image_facts($candidate_path);
    $declared_mime = strtolower(trim((string) ($candidate['mime'] ?? '')));
    if (
        !$facts
        || $declared_mime === ''
        || $declared_mime !== $facts['mime']
        || !in_array(
            $facts['mime'],
            ['image/png', 'image/jpeg', 'image/webp'],
            true
        )
    ) {
        return null;
    }

    $job_id = intval($candidate['job_id'] ?? 0);
    $declared_sha256 = strtolower(trim((string) (
        $candidate['sha256'] ?? ''
    )));
    $actual_sha256 = $job_id > 0
        && preg_match('/^[a-f0-9]{64}$/', $declared_sha256)
        ? hash_file('sha256', $candidate_path)
        : false;
    if (
        $job_id <= 0
        || !is_string($actual_sha256)
        || !hash_equals($declared_sha256, strtolower($actual_sha256))
    ) {
        return null;
    }

    $catalogue_rating = sanitize_key((string) (
        $catalogue_item['content_rating'] ?? ''
    ));
    $candidate_rating = sanitize_key((string) (
        $candidate['content_rating'] ?? ''
    ));
    $catalogue_rank = aimee_media_materialization_rating_rank(
        $catalogue_rating
    );
    $candidate_rank = aimee_media_materialization_rating_rank(
        $candidate_rating
    );
    if (
        $catalogue_rank < 0
        || $candidate_rank < 0
        || $candidate_rank > $catalogue_rank
    ) {
        return null;
    }

    return [
        'path' => $candidate_path,
        'mime' => $facts['mime'],
        'alt' => sanitize_text_field((string) (
            $candidate['alt']
            ?? $catalogue_item['alt']
            ?? 'A private visual created by Aimee'
        )),
        'content_rating' => $catalogue_rating,
        'source' => 'delivery_materialization',
        'delivery_id' => $delivery_id,
        'media_key' => $media_key,
        'user_id' => $user_id,
        'width' => $facts['width'],
        'height' => $facts['height'],
        'bytes' => $facts['bytes'],
        'job_id' => $job_id,
        'sha256' => $declared_sha256,
    ];
}

/**
 * Resolve the normal static catalogue asset or a validated derivative bound to
 * one authorised delivery. A delivery-less gallery lookup never calls sidecar
 * code. Once bytes are bound, a later resolver cannot substitute new pixels.
 */
function aimee_private_media_delivery_asset($key, $delivery_id = '') {
    $key = sanitize_key((string) $key);
    $delivery_id = sanitize_text_field((string) $delivery_id);
    $catalogue = function_exists('aimee_private_media_catalog')
        ? aimee_private_media_catalog()
        : [];
    $item = $catalogue[$key] ?? null;
    if (!is_array($item) || !function_exists('aimee_private_media_static_path')) {
        return null;
    }

    $static_path = aimee_private_media_static_path($key);
    $static_facts = $static_path
        ? aimee_media_materialization_image_facts($static_path)
        : null;
    $static_sha256 = $static_facts ? hash_file('sha256', $static_path) : false;
    $fallback = $static_path && $static_facts && is_string($static_sha256) ? [
        'path' => $static_path,
        'mime' => $static_facts['mime'],
        'alt' => sanitize_text_field((string) (
            $item['alt'] ?? 'A private visual from Aimee'
        )),
        'content_rating' => sanitize_key((string) (
            $item['content_rating'] ?? 'safe'
        )),
        'source' => 'catalogue',
        'delivery_id' => $delivery_id,
        'media_key' => $key,
        'user_id' => 0,
        'width' => $static_facts['width'],
        'height' => $static_facts['height'],
        'bytes' => $static_facts['bytes'],
        'job_id' => 0,
        'sha256' => strtolower($static_sha256),
    ] : null;

    if ($delivery_id === '' || !function_exists('aimee_media_delivery_find')) {
        return $fallback;
    }

    $delivery = aimee_media_delivery_find($delivery_id);
    if (
        !is_array($delivery)
        || sanitize_key((string) ($delivery['media_key'] ?? '')) !== $key
        || empty($delivery['catalogue_resolved_at'])
        || empty($delivery['authorised_at'])
        || !empty($delivery['failed_at'])
    ) {
        return $fallback;
    }

    if (is_array($fallback)) {
        $fallback['user_id'] = intval($delivery['user_id'] ?? 0);
    }

    $bound_source = sanitize_key((string) (
        $delivery['resolved_asset_source'] ?? ''
    ));
    $bound_job_id = intval($delivery['resolved_asset_job_id'] ?? 0);
    $bound_sha256 = strtolower(trim((string) (
        $delivery['resolved_asset_sha256'] ?? ''
    )));
    $bound_mime = strtolower(trim((string) (
        $delivery['resolved_asset_mime'] ?? ''
    )));
    $has_binding = $bound_source !== ''
        || $bound_job_id > 0
        || $bound_sha256 !== ''
        || $bound_mime !== '';

    if ($has_binding && $bound_source === 'catalogue') {
        return is_array($fallback)
            && $bound_job_id === 0
            && preg_match('/^[a-f0-9]{64}$/', $bound_sha256)
            && hash_equals($bound_sha256, (string) $fallback['sha256'])
            && hash_equals($bound_mime, (string) $fallback['mime'])
                ? $fallback
                : null;
    }

    if ($has_binding && $bound_source !== 'delivery_materialization') {
        return null;
    }

    $filter_context = [
        'delivery_id' => $delivery_id,
        'decision_id' => sanitize_text_field((string) (
            $delivery['decision_id'] ?? ''
        )),
        'user_id' => intval($delivery['user_id'] ?? 0),
        'media_key' => $key,
        'catalogue_item' => $item,
        'fallback_asset' => $fallback,
    ];
    $candidate = apply_filters(
        'aimee_private_media_delivery_asset',
        $fallback,
        $key,
        $delivery_id,
        $filter_context
    );

    if ($candidate === $fallback) {
        return $has_binding ? null : $fallback;
    }

    $validated = aimee_media_materialization_validate_asset(
        $candidate,
        $delivery,
        $item
    );
    if (!$validated) return $has_binding ? null : $fallback;

    if ($has_binding) {
        $matches_binding = $bound_job_id > 0
            && intval($validated['job_id'] ?? 0) === $bound_job_id
            && preg_match('/^[a-f0-9]{64}$/', $bound_sha256)
            && hash_equals($bound_sha256, (string) ($validated['sha256'] ?? ''))
            && hash_equals($bound_mime, (string) ($validated['mime'] ?? ''));
        return $matches_binding ? $validated : null;
    }

    return $validated;
}

/**
 * Reconstruct provider-facing gates from persisted rows and a freshly loaded
 * profile. Request booleans never become generation authority.
 */
function aimee_media_materialization_authorised_context(
    $delivery_id,
    $user_id = 0,
    $media_key = '',
    $allow_file_resolved = false,
    $allow_message_created = false
) {
    global $wpdb;

    if (
        !function_exists('aimee_media_delivery_find')
        || !function_exists('aimee_media_decisions_table')
        || !function_exists('aimee_table')
        || !function_exists('aimee_private_media_catalog')
    ) {
        return null;
    }

    $delivery_id = sanitize_text_field((string) $delivery_id);
    $user_id = intval($user_id);
    $media_key = sanitize_key((string) $media_key);
    if ($delivery_id === '') return null;

    $delivery = aimee_media_delivery_find($delivery_id, $user_id);
    if (!is_array($delivery)) return null;
    $user_id = intval($delivery['user_id'] ?? 0);
    $stored_key = sanitize_key((string) ($delivery['media_key'] ?? ''));
    if (
        $user_id <= 0
        || $stored_key === ''
        || ($media_key !== '' && $stored_key !== $media_key)
        || empty($delivery['catalogue_resolved_at'])
        || empty($delivery['authorised_at'])
        || (!$allow_file_resolved && !empty($delivery['file_resolved_at']))
        || (!$allow_message_created && !empty($delivery['message_created_at']))
        || !empty($delivery['failed_at'])
    ) {
        return null;
    }

    $decision_id = sanitize_text_field((string) (
        $delivery['decision_id'] ?? ''
    ));
    $decision = $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . aimee_media_decisions_table()
        . ' WHERE decision_id = %s AND user_id = %d LIMIT 1',
        $decision_id,
        $user_id
    ), ARRAY_A);
    if (!is_array($decision)) return null;

    $eligible_keys = json_decode(
        (string) ($decision['eligible_keys_json'] ?? ''),
        true
    );
    $eligible_keys = is_array($eligible_keys)
        ? array_values(array_filter(array_map('sanitize_key', $eligible_keys)))
        : [];
    $aimee_decision = sanitize_key((string) (
        $decision['aimee_decision'] ?? ''
    ));
    if (
        intval($decision['media_opportunity'] ?? 0) !== 1
        || $aimee_decision !== 'send'
        || sanitize_key((string) ($decision['selected_key'] ?? '')) !== $stored_key
        || !in_array($stored_key, $eligible_keys, true)
    ) {
        return null;
    }

    $catalogue = aimee_private_media_catalog();
    $item = $catalogue[$stored_key] ?? null;
    if (!is_array($item)) return null;
    $rating = sanitize_key((string) ($item['content_rating'] ?? ''));
    if (aimee_media_materialization_rating_rank($rating) < 0) return null;

    $profile = $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . aimee_table('aimee_user_profiles')
        . ' WHERE user_id = %d LIMIT 1',
        $user_id
    ));
    if (!$profile) return null;

    $is_admin = function_exists('aimee_is_admin_user')
        && aimee_is_admin_user($profile);
    $is_member = function_exists('aimee_subscription_is_active')
        && aimee_subscription_is_active($profile);
    $is_preview = function_exists('aimee_free_preview_is_active')
        && aimee_free_preview_is_active($profile);
    $access_available = $is_admin
        || $is_member
        || ($rating === 'safe' && $is_preview);
    $rating_access = $rating === 'safe' || $is_admin || $is_member;
    $adult_status = function_exists('aimee_adult_assurance_state')
        ? sanitize_key((string) aimee_adult_assurance_state($profile))
        : (intval($profile->age ?? 0) >= 18 ? 'self_declared' : 'underage');
    $adult_confirmed = intval($profile->age ?? 0) >= 18
        && !in_array($adult_status, ['underage', 'unknown', 'none'], true)
        && (
            !in_array($rating, ['erotic', 'explicit'], true)
            || $adult_status === 'verified'
        );

    $policy_snapshot = json_decode(
        (string) ($decision['policy_snapshot_json'] ?? ''),
        true
    );
    $hard_veto_reason_codes = is_array($policy_snapshot)
        ? array_values(array_filter(array_map(
            'sanitize_key',
            (array) ($policy_snapshot['hard_veto_reason_codes'] ?? [])
        )))
        : [];
    $pressure_detected = !empty($decision['pressure_detected']);
    $hard_veto = $pressure_detected || !empty($hard_veto_reason_codes);
    $cooldown_clear = !empty($decision['cooldown_clear']);
    if (
        !$access_available
        || !$rating_access
        || !$adult_confirmed
        || $hard_veto
        || !$cooldown_clear
    ) {
        return null;
    }

    if (function_exists('aimee_load_inner_state')) {
        $inner_state = (array) aimee_load_inner_state($user_id, false);
        if (
            !empty($inner_state['unresolved_rupture'])
            || in_array(
                sanitize_key((string) ($inner_state['repair_status'] ?? 'clear')),
                ['ruptured', 'repairing'],
                true
            )
        ) {
            return null;
        }
    }

    return [
        'user_id' => $user_id,
        'decision_id' => $decision_id,
        'delivery_id' => $delivery_id,
        'media_key' => $stored_key,
        'catalogue_item' => $item,
        'maximum_rating' => $rating,
        'route' => sanitize_key((string) (
            $decision['model_route'] ?? 'primary'
        )),
        'opportunity_kind' => sanitize_key((string) (
            $decision['reason_code'] ?? $decision['source'] ?? 'none'
        )),
        'decision_reason_code' => sanitize_key((string) (
            $decision['reason_code'] ?? 'not_evaluated'
        )),
        'requested_rating' => sanitize_key((string) (
            $decision['requested_rating'] ?? ''
        )),
        'direct_request' => !empty($decision['direct_request']),
        'proactive_allowed' => !empty($decision['proactive_allowed']),
        'aimee_decision' => $aimee_decision,
        'access_available' => true,
        'adult_status' => $adult_status,
        'adult_confirmed' => true,
        'cooldown_clear' => true,
        'pressure_detected' => false,
        'hard_veto' => false,
        'hard_veto_reason_codes' => [],
        'profile' => $profile,
    ];
}

function aimee_private_media_path($key, $delivery_id = '') {
    $asset = aimee_private_media_delivery_asset($key, $delivery_id);
    return is_array($asset) && !empty($asset['path'])
        ? (string) $asset['path']
        : null;
}

/** Exact release scope for the first asynchronous live-image lane. */
function aimee_media_materialization_is_owner_safe_direct_chat(
    array $trusted,
    $channel = 'chat'
) {
    $configured_owner = function_exists('aimee_configured_identity_user_id')
        ? aimee_configured_identity_user_id('AIMEE_OWNER_USER_ID')
        : (defined('AIMEE_OWNER_USER_ID') ? intval(AIMEE_OWNER_USER_ID) : 0);

    return intval($trusted['user_id'] ?? 0) === 112
        && intval($configured_owner) === 112
        && sanitize_key((string) $channel) === 'chat'
        && sanitize_key((string) (
            $trusted['decision_reason_code'] ?? ''
        )) === 'owner_safe_image_test'
        && sanitize_key((string) (
            $trusted['maximum_rating'] ?? ''
        )) === 'safe'
        && sanitize_key((string) (
            $trusted['requested_rating'] ?? ''
        )) === 'safe'
        && !empty($trusted['direct_request'])
        && empty($trusted['proactive_allowed'])
        && !empty($trusted['access_available'])
        && !empty($trusted['adult_confirmed'])
        && !empty($trusted['cooldown_clear'])
        && empty($trusted['pressure_detected'])
        && empty($trusted['hard_veto']);
}

/** Keep only public, bounded result metadata returned by a sidecar. */
function aimee_media_materialization_sanitize_result($result) {
    if (!is_array($result)) return ['status' => 'unavailable'];

    $status = sanitize_key((string) ($result['status'] ?? 'unavailable'));
    if (!in_array($status, ['pending', 'ready', 'unavailable', 'failed'], true)) {
        $status = 'unavailable';
    }

    $sanitized = ['status' => $status];
    $job_id = intval($result['job_id'] ?? 0);
    if ($job_id > 0) $sanitized['job_id'] = $job_id;

    foreach (['model', 'provider'] as $field) {
        $value = mb_substr(
            sanitize_text_field((string) ($result[$field] ?? '')),
            0,
            80
        );
        if ($value !== '') $sanitized[$field] = $value;
    }

    $reason_code = mb_substr(
        sanitize_key((string) ($result['reason_code'] ?? '')),
        0,
        100
    );
    if ($reason_code !== '') $sanitized['reason_code'] = $reason_code;

    if ($status === 'pending' && $job_id <= 0) {
        return [
            'status' => 'unavailable',
            'reason_code' => 'invalid_pending_result',
        ];
    }

    return $sanitized;
}

/** Client responses never expose a provider's internal asynchronous job ID. */
function aimee_media_materialization_public_result($result) {
    $public = aimee_media_materialization_sanitize_result($result);
    unset($public['job_id']);

    return $public;
}

/**
 * Ask a sidecar to reserve asynchronous work only after persisted
 * authorisation. The filter cannot affect eligibility or the selected key.
 */
function aimee_materialize_authorised_media_delivery(array $context) {
    $delivery_id = sanitize_text_field((string) (
        $context['delivery_id'] ?? ''
    ));
    $user_id = intval($context['user_id'] ?? 0);
    $media_key = sanitize_key((string) ($context['media_key'] ?? ''));
    $channel = sanitize_key((string) ($context['channel'] ?? 'chat'));
    if ($delivery_id === '' || $user_id <= 0 || $media_key === '') {
        return ['status' => 'unavailable'];
    }
    if (
        !function_exists('aimee_media_materialization_schema_ready')
        || !aimee_media_materialization_schema_ready()
    ) {
        return [
            'status' => 'unavailable',
            'reason_code' => 'materialization_schema_unavailable',
        ];
    }

    $trusted = aimee_media_materialization_authorised_context(
        $delivery_id,
        $user_id,
        $media_key
    );
    if (
        !is_array($trusted)
        || !aimee_media_materialization_is_owner_safe_direct_chat(
            $trusted,
            $channel
        )
    ) {
        return ['status' => 'unavailable'];
    }

    $trusted = array_merge($trusted, [
        'channel' => 'chat',
        'request_id' => mb_substr(
            sanitize_text_field((string) ($context['request_id'] ?? '')),
            0,
            100
        ),
        'execution_mode' => 'asynchronous_before_file_resolved',
    ]);
    // A profile row was needed for the fresh server-side checks, but the
    // sidecar receives only the bounded policy envelope below.
    unset($trusted['profile']);

    $result = apply_filters(
        'aimee_authorised_media_delivery_materialization_result',
        ['status' => 'unavailable'],
        $trusted
    );

    return aimee_media_materialization_sanitize_result($result);
}

function aimee_media_materialization_guarded_message(
    $message,
    $profile,
    $visual_world = false,
    $fallback_override = '',
    $force_fallback_after_reviews = false
) {
    $fallback_override = sanitize_textarea_field((string) $fallback_override);
    $fallback = $fallback_override !== ''
        ? $fallback_override
        : ($visual_world
            ? 'There — I created this visual-world portrait for you. x'
            : "I couldn't finish creating that visual, so I won't pretend it appeared. x");
    $message = sanitize_textarea_field((string) $message);
    if ($message === '') $message = $fallback;

    $profile_source = function_exists('aimee_user_profile_attribution_source')
        ? aimee_user_profile_attribution_source($profile)
        : [];
    $canonical_name = function_exists('aimee_is_owner_user')
        && aimee_is_owner_user($profile)
            ? 'Paul'
            : trim((string) ($profile->first_name ?? 'the current user'));
    if ($canonical_name === '') $canonical_name = 'the current user';

    if (function_exists('aimee_profile_attribution_review_reply')) {
        $profile_review = aimee_profile_attribution_review_reply(
            $message,
            $profile_source,
            $canonical_name,
            function_exists('aimee_profile_attribution_aimee_context')
                ? aimee_profile_attribution_aimee_context(
                    $visual_world ? 'visual_world' : 'factual'
                )
                : []
        );
        if (!empty($profile_review['blocked'])) $message = $fallback;
    }

    if (function_exists('aimee_synthetic_identity_review_reply')) {
        $identity_review = aimee_synthetic_identity_review_reply(
            $message,
            '',
            ['intent' => 'general', 'source' => 'deterministic'],
            ['reality_mode' => $visual_world ? 'visual_world' : 'factual']
        );
        if (!empty($identity_review['repaired'])) {
            $message = trim((string) ($identity_review['reply'] ?? ''));
            if ($message === '') $message = $fallback;
        }
    }

    if (function_exists('aimee_playful_jealousy_review_reply')) {
        $jealousy_review = aimee_playful_jealousy_review_reply(
            $message,
            [],
            'async_materialization'
        );
        if (!empty($jealousy_review['repaired'])) {
            $message = trim((string) ($jealousy_review['reply'] ?? ''));
            if ($message === '') $message = $fallback;
        }
    }

    // Jealousy repair is allowed to replace prose, so synthetic identity gets
    // one explicit final pass over the exact message that will be persisted.
    if (function_exists('aimee_synthetic_identity_review_reply')) {
        $final_identity_review = aimee_synthetic_identity_review_reply(
            $message,
            '',
            ['intent' => 'general', 'source' => 'deterministic'],
            ['reality_mode' => $visual_world ? 'visual_world' : 'factual']
        );
        if (!empty($final_identity_review['repaired'])) $message = $fallback;
    }

    if ($force_fallback_after_reviews) $message = $fallback;

    return sanitize_textarea_field($message);
}

/** Exact interim copy, reviewed but immune to unrelated reply rewrites. */
function aimee_media_materialization_pending_message($profile = null) {
    $copy = "I’m creating that visual for you now — give me a moment and it’ll appear here when it’s ready. x";

    return aimee_media_materialization_guarded_message(
        $copy,
        $profile,
        true,
        $copy,
        true
    );
}

/**
 * Remove every model-authored persistence or relationship side effect from an
 * interim pending turn. The user's normal server-side appraisal has already
 * happened; invisible draft prose must not create memory, opinion or tokens.
 */
function aimee_media_materialization_neutral_pending_contract(
    array $contract,
    $profile = null
) {
    return array_merge($contract, [
        'reply_text' => aimee_media_materialization_pending_message($profile),
        'instruction' =>
            'Deterministic asynchronous image-materialization acknowledgement.',
        'equity_change' => 0,
        'inquiry_change' => 0,
        'fantasy_change' => 0,
        'archive_current_context' => false,
        'memory_operation' => 'none',
        'memory_to_save' => null,
        'memory_key' => null,
        'memory_domain' => null,
        'emotional_weight' => 0,
        'opinion_topic' => null,
        'opinion_stance' => null,
        'opinion_reason' => null,
        'opinion_strength' => 0,
        'intimacy_invitation' => 'none',
        'self_observation' => null,
        'active_goal' => null,
        'candidate_tendency' => null,
        'chosen_action' => null,
        'choice_reason' => null,
        'inhibited_tendency' => null,
    ]);
}

/**
 * Finish one pending derivative. The generated asset must already be exposed
 * by the exact delivery-bound resolver. A transaction serializes message
 * insertion so retries cannot create duplicate Aimee messages.
 */
function aimee_complete_pending_media_materialization($delivery_id) {
    global $wpdb;

    $delivery_id = sanitize_text_field((string) $delivery_id);
    if ($delivery_id === '' || !function_exists('aimee_media_delivery_find')) {
        return false;
    }
    if (
        function_exists('aimee_media_materialization_schema_ready')
        && !aimee_media_materialization_schema_ready()
    ) {
        return false;
    }

    $delivery = aimee_media_delivery_find($delivery_id);
    if (!is_array($delivery) || !empty($delivery['failed_at'])) return false;

    $messages_table = aimee_table('aimee_messages');
    $message_pk = function_exists('aimee_messages_primary_key')
        ? aimee_messages_primary_key()
        : 'message_id';
    if (!in_array($message_pk, ['id', 'message_id'], true)) return false;

    if ($wpdb->query('START TRANSACTION') === false) return false;
    $locked = $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . aimee_media_deliveries_table()
        . ' WHERE delivery_id = %s FOR UPDATE',
        $delivery_id
    ), ARRAY_A);
    if (!is_array($locked) || !empty($locked['failed_at'])) {
        $wpdb->query('ROLLBACK');
        return false;
    }
    $user_id = intval($locked['user_id'] ?? 0);
    $media_key = sanitize_key((string) ($locked['media_key'] ?? ''));

    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT `{$message_pk}` AS message_id, sender, image_url"
        . " FROM {$messages_table} WHERE media_delivery_id = %s"
        . " ORDER BY `{$message_pk}` ASC LIMIT 1",
        $delivery_id
    ), ARRAY_A);
    if (
        !empty($locked['message_created_at'])
        && intval($locked['message_id'] ?? 0) > 0
    ) {
        $completed_binding_valid =
            sanitize_key((string) (
                $locked['resolved_asset_source'] ?? ''
            )) === 'delivery_materialization'
            && intval($locked['resolved_asset_job_id'] ?? 0) > 0
            && preg_match('/^[a-f0-9]{64}$/', strtolower((string) (
                $locked['resolved_asset_sha256'] ?? ''
            )))
            && in_array(strtolower((string) (
                $locked['resolved_asset_mime'] ?? ''
            )), ['image/png', 'image/jpeg', 'image/webp'], true);
        $completed_message_valid = is_array($existing)
            && intval($existing['message_id'] ?? 0)
                === intval($locked['message_id'] ?? 0)
            && (string) ($existing['sender'] ?? '') === 'aimee'
            && (string) ($existing['image_url'] ?? '')
                === 'aimee-media:' . $media_key;
        if (!$completed_binding_valid || !$completed_message_valid) {
            $wpdb->query('ROLLBACK');
            return false;
        }

        return $wpdb->query('COMMIT') !== false;
    }
    if (
        !empty($locked['message_created_at'])
        || intval($locked['message_id'] ?? 0) > 0
    ) {
        $wpdb->query('ROLLBACK');
        return false;
    }

    // Fresh policy/profile gates are mandatory before creating anything new.
    // A fully committed replay is handled above using only exact durable facts,
    // so a later access change cannot strand a sidecar hand-off acknowledgement.
    $trusted = aimee_media_materialization_authorised_context(
        $delivery_id,
        $user_id,
        $media_key,
        true
    );
    if (
        !is_array($trusted)
        || !aimee_media_materialization_is_owner_safe_direct_chat(
            $trusted,
            'chat'
        )
    ) {
        $wpdb->query('ROLLBACK');
        return false;
    }

    if (
        function_exists('aimee_private_media_key_is_recently_sent')
        && aimee_private_media_key_is_recently_sent($user_id, $media_key, '')
    ) {
        $wpdb->query('ROLLBACK');
        return false;
    }

    // Resolve and bind under the same delivery-row lock as message insertion.
    // A rolled-back message therefore cannot leave a durable file_resolved fact.
    $asset = aimee_private_media_delivery_asset($media_key, $delivery_id);
    if (
        !is_array($asset)
        || sanitize_key((string) ($asset['source'] ?? ''))
            !== 'delivery_materialization'
        || empty($asset['path'])
    ) {
        $wpdb->query('ROLLBACK');
        return false;
    }
    if (!aimee_media_delivery_bind_resolved_asset($delivery_id, $asset)) {
        $wpdb->query('ROLLBACK');
        return false;
    }
    $locked = aimee_media_delivery_find($delivery_id);
    if (
        !is_array($locked)
        || empty($locked['file_resolved_at'])
        || sanitize_key((string) ($locked['resolved_asset_source'] ?? ''))
            !== 'delivery_materialization'
        || intval($locked['resolved_asset_job_id'] ?? 0)
            !== intval($asset['job_id'] ?? 0)
        || !hash_equals(
            strtolower((string) ($locked['resolved_asset_sha256'] ?? '')),
            strtolower((string) ($asset['sha256'] ?? ''))
        )
        || !hash_equals(
            strtolower((string) ($locked['resolved_asset_mime'] ?? '')),
            strtolower((string) ($asset['mime'] ?? ''))
        )
    ) {
        $wpdb->query('ROLLBACK');
        return false;
    }

    $message_id = 0;
    $created_now = false;
    if (
        is_array($existing)
        && (string) ($existing['sender'] ?? '') === 'aimee'
        && (string) ($existing['image_url'] ?? '')
            === 'aimee-media:' . $media_key
    ) {
        $message_id = intval($existing['message_id'] ?? 0);
    } elseif (is_array($existing)) {
        $wpdb->query('ROLLBACK');
        return false;
    } else {
        $message = aimee_media_materialization_guarded_message(
            'There — I created this visual-world portrait for you. x',
            $trusted['profile'] ?? null,
            true
        );
        $inserted = $wpdb->insert($messages_table, [
            'user_id' => $user_id,
            'sender' => 'aimee',
            'message_text' => $message,
            'image_url' => 'aimee-media:' . $media_key,
            'evaluator_directive' => 'async_media_materialization_complete',
            'is_sms' => 0,
            'media_decision_id' => sanitize_text_field((string) (
                $locked['decision_id'] ?? ''
            )),
            'media_delivery_id' => $delivery_id,
        ]);
        $message_id = intval($wpdb->insert_id);
        if ($inserted === false || $message_id <= 0) {
            $wpdb->query('ROLLBACK');
            return false;
        }
        $created_now = true;
    }

    if (!aimee_media_delivery_transition(
        $delivery_id,
        'message_created',
        ['message_id' => $message_id]
    )) {
        $wpdb->query('ROLLBACK');
        return false;
    }

    if ($wpdb->query('COMMIT') === false) {
        $wpdb->query('ROLLBACK');
        return false;
    }

    if (
        $created_now
        && function_exists('aimee_free_preview_safe_images_used')
        && function_exists('aimee_subscription_is_active')
        && function_exists('aimee_is_admin_user')
        && !aimee_subscription_is_active($trusted['profile'] ?? null)
        && !aimee_is_admin_user($trusted['profile'] ?? null)
    ) {
        aimee_free_preview_safe_images_used($user_id, true);
    }

    return true;
}

/**
 * Terminally fail one pending delivery and add exactly one honest text-only
 * note. Error details are reduced to a bounded token before persistence.
 */
function aimee_fail_pending_media_materialization(
    $delivery_id,
    $safe_error_code
) {
    global $wpdb;

    $delivery_id = sanitize_text_field((string) $delivery_id);
    $error_code = mb_substr(
        sanitize_key((string) $safe_error_code),
        0,
        100
    );
    if ($error_code === '') $error_code = 'materialization_failed';
    if ($delivery_id === '' || !function_exists('aimee_media_delivery_find')) {
        return false;
    }

    $delivery = aimee_media_delivery_find($delivery_id);
    if (!is_array($delivery)) return false;
    $user_id = intval($delivery['user_id'] ?? 0);
    $media_key = sanitize_key((string) ($delivery['media_key'] ?? ''));
    $decision = $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . aimee_media_decisions_table()
        . ' WHERE decision_id = %s AND user_id = %d LIMIT 1',
        sanitize_text_field((string) ($delivery['decision_id'] ?? '')),
        $user_id
    ), ARRAY_A);
    $configured_owner = function_exists('aimee_configured_identity_user_id')
        ? aimee_configured_identity_user_id('AIMEE_OWNER_USER_ID')
        : (defined('AIMEE_OWNER_USER_ID') ? intval(AIMEE_OWNER_USER_ID) : 0);
    $eligible_keys = is_array($decision)
        ? json_decode((string) ($decision['eligible_keys_json'] ?? ''), true)
        : [];
    $eligible_keys = is_array($eligible_keys)
        ? array_values(array_filter(array_map('sanitize_key', $eligible_keys)))
        : [];
    $catalogue = function_exists('aimee_private_media_catalog')
        ? aimee_private_media_catalog()
        : [];
    $catalogue_item = $catalogue[$media_key] ?? null;
    if (
        $user_id !== 112
        || intval($configured_owner) !== 112
        || !is_array($decision)
        || !is_array($catalogue_item)
        || sanitize_key((string) (
            $catalogue_item['content_rating'] ?? ''
        )) !== 'safe'
        || intval($decision['media_opportunity'] ?? 0) !== 1
        || sanitize_key((string) ($decision['aimee_decision'] ?? '')) !== 'send'
        || sanitize_key((string) ($decision['reason_code'] ?? ''))
            !== 'owner_safe_image_test'
        || empty($decision['direct_request'])
        || !empty($decision['proactive_allowed'])
        || empty($decision['cooldown_clear'])
        || !empty($decision['pressure_detected'])
        || sanitize_key((string) ($decision['requested_rating'] ?? '')) !== 'safe'
        || sanitize_key((string) ($decision['selected_key'] ?? '')) !== $media_key
        || !in_array($media_key, $eligible_keys, true)
        || !empty($delivery['file_resolved_at'])
        || !empty($delivery['message_created_at'])
        || !empty($delivery['returned_by_direct_api_at'])
        || !empty($delivery['returned_by_history_api_at'])
    ) {
        return false;
    }

    $messages_table = aimee_table('aimee_messages');
    $message_pk = function_exists('aimee_messages_primary_key')
        ? aimee_messages_primary_key()
        : 'message_id';
    if (!in_array($message_pk, ['id', 'message_id'], true)) return false;

    if ($wpdb->query('START TRANSACTION') === false) return false;
    $locked = $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . aimee_media_deliveries_table()
        . ' WHERE delivery_id = %s FOR UPDATE',
        $delivery_id
    ), ARRAY_A);
    if (
        !is_array($locked)
        || !empty($locked['file_resolved_at'])
        || !empty($locked['message_created_at'])
        || !empty($locked['returned_by_direct_api_at'])
        || !empty($locked['returned_by_history_api_at'])
    ) {
        $wpdb->query('ROLLBACK');
        return false;
    }

    $existing_note = intval($wpdb->get_var($wpdb->prepare(
        "SELECT `{$message_pk}` FROM {$messages_table}"
        . ' WHERE media_delivery_id = %s AND sender = %s'
        . ' AND evaluator_directive = %s LIMIT 1',
        $delivery_id,
        'aimee',
        'async_media_materialization_failed'
    )));
    if ($existing_note > 0 && !empty($locked['failed_at'])) {
        $wpdb->query('COMMIT');
        return true;
    }

    if (
        empty($locked['failed_at'])
        && !aimee_media_delivery_transition(
            $delivery_id,
            'failed',
            ['error_code' => $error_code]
        )
    ) {
        $wpdb->query('ROLLBACK');
        return false;
    }

    if ($existing_note <= 0) {
        $profile = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . aimee_table('aimee_user_profiles')
            . ' WHERE user_id = %d LIMIT 1',
            $user_id
        ));
        $message = aimee_media_materialization_guarded_message(
            "I couldn't finish creating that visual, so I won't pretend it appeared. x",
            $profile,
            false
        );
        if ($wpdb->insert($messages_table, [
            'user_id' => $user_id,
            'sender' => 'aimee',
            'message_text' => $message,
            'image_url' => null,
            'evaluator_directive' => 'async_media_materialization_failed',
            'is_sms' => 0,
            'media_decision_id' => sanitize_text_field((string) (
                $locked['decision_id'] ?? ''
            )),
            'media_delivery_id' => $delivery_id,
        ]) === false) {
            $wpdb->query('ROLLBACK');
            return false;
        }
    }

    if ($wpdb->query('COMMIT') === false) {
        $wpdb->query('ROLLBACK');
        return false;
    }

    return true;
}

/** Compare canonical local paths without allowing the root itself. */
function aimee_global_live_image_beta_path_is_strictly_within($path, $root) {
    $path = rtrim(str_replace('\\', '/', (string) $path), '/');
    $root = rtrim(str_replace('\\', '/', (string) $root), '/');
    if ($path === '' || $root === '') return false;

    if (DIRECTORY_SEPARATOR === '\\') {
        $path = strtolower($path);
        $root = strtolower($root);
    }

    return $path !== $root && strpos($path, $root . '/') === 0;
}

/**
 * Resolve only the known beta output directory beneath Aimee's configured
 * private-media root. Canonical equality rejects relative, traversal and
 * symlink aliases before any token-derived filename is constructed.
 */
function aimee_global_live_image_beta_cleanup_output_dir(
    $configured_output = null,
    $configured_private_root = null
) {
    if ($configured_output === null) {
        $configured_output = defined('AIMEE_LIVE_IMAGE_BETA_OUTPUT_DIR')
            ? (string) AIMEE_LIVE_IMAGE_BETA_OUTPUT_DIR
            : '';
    }
    if ($configured_private_root === null) {
        $configured_private_root = defined('AIMEE_PRIVATE_MEDIA_DIR')
            ? (string) AIMEE_PRIVATE_MEDIA_DIR
            : '';
    }

    $configured_output = rtrim(trim((string) $configured_output), '/\\');
    $configured_private_root = rtrim(
        trim((string) $configured_private_root),
        '/\\'
    );
    if (
        $configured_output === ''
        || $configured_private_root === ''
        || strpos($configured_output, "\0") !== false
        || strpos($configured_private_root, "\0") !== false
        || strpos($configured_output, '://') !== false
        || strpos($configured_private_root, '://') !== false
        || is_link($configured_output)
        || is_link($configured_private_root)
    ) {
        return null;
    }

    $output = realpath($configured_output);
    $private_root = realpath($configured_private_root);
    if (
        !is_string($output)
        || !is_string($private_root)
        || !is_dir($output)
        || !is_dir($private_root)
        || !is_readable($output)
        || !is_writable($output)
    ) {
        return null;
    }

    $configured_output_canonical = rtrim(str_replace(
        '\\',
        '/',
        $configured_output
    ), '/');
    $configured_root_canonical = rtrim(str_replace(
        '\\',
        '/',
        $configured_private_root
    ), '/');
    $output_canonical = rtrim(str_replace('\\', '/', $output), '/');
    $root_canonical = rtrim(str_replace('\\', '/', $private_root), '/');
    if (DIRECTORY_SEPARATOR === '\\') {
        $configured_output_canonical = strtolower($configured_output_canonical);
        $configured_root_canonical = strtolower($configured_root_canonical);
        $output_canonical = strtolower($output_canonical);
        $root_canonical = strtolower($root_canonical);
    }
    if (
        $configured_output_canonical !== $output_canonical
        || $configured_root_canonical !== $root_canonical
        || !aimee_global_live_image_beta_path_is_strictly_within(
            $output,
            $private_root
        )
    ) {
        return null;
    }

    return ['path' => $output, 'private_root' => $private_root];
}

/** Reduce cleanup diagnostics to one bounded, non-sensitive operator code. */
function aimee_global_live_image_beta_cleanup_safe_reason($error_code) {
    $allowed = [
        'account_delete_worker_drain',
        'account_delete_retry_failed',
        'account_delete_engine_invalid',
        'account_delete_state_invalid',
        'account_delete_token_invalid',
        'account_delete_output_invalid',
        'account_delete_path_invalid',
        'account_delete_unlink_failed',
        'account_delete_row_delete_failed',
        'account_delete_cleanup_incomplete',
        'cleanup_database_unavailable',
        'cleanup_table_probe_failed',
        'cleanup_schema_unavailable',
        'cleanup_engine_unavailable',
        'cleanup_tombstone_failed',
        'cleanup_row_read_failed',
        'cleanup_verification_failed',
        'cleanup_retry_schedule_failed',
    ];
    $error_code = sanitize_key((string) $error_code);
    if (!in_array($error_code, $allowed, true)) {
        $error_code = 'account_delete_cleanup_incomplete';
    }

    return $error_code;
}

/** Persist one bounded reason while retaining the row needed for safe retry. */
function aimee_global_live_image_beta_mark_cleanup_retained(
    $table,
    $row_id,
    $user_id,
    $error_code
) {
    global $wpdb;

    $error_code =
        aimee_global_live_image_beta_cleanup_safe_reason($error_code);

    return $wpdb->query($wpdb->prepare(
        "UPDATE `{$table}` SET error_code = %s, updated_at = %s"
        . " WHERE id = %d AND user_id = %d AND status = 'deleting'",
        $error_code,
        current_time('mysql', true),
        intval($row_id),
        intval($user_id)
    )) !== false;
}

/** Parse one UTC MySQL timestamp without allowing an invalid lease forever. */
function aimee_global_live_image_beta_cleanup_timestamp($value) {
    $value = trim((string) $value);
    if ($value === '') return 0;

    $timestamp = strtotime($value . ' UTC');
    return $timestamp === false ? 0 : intval($timestamp);
}

/**
 * Arrange a bounded retry while an already-claimed worker drains. WordPress
 * persists cron events independently of the optional beta plugin, so this
 * remains effective after the account itself has been removed.
 */
function aimee_global_schedule_live_image_beta_cleanup_retry(
    $user_id,
    $lease_expires_at = ''
) {
    $user_id = intval($user_id);
    if (
        $user_id < 1
        || !function_exists('wp_schedule_single_event')
    ) {
        return false;
    }

    $hook = 'aimee_global_retry_live_image_beta_cleanup';
    $args = [$user_id];
    $now = aimee_global_live_image_beta_cleanup_timestamp(
        function_exists('current_time')
            ? current_time('mysql', true)
            : gmdate('Y-m-d H:i:s')
    );
    if ($now < 1) $now = time();
    $lease_timestamp =
        aimee_global_live_image_beta_cleanup_timestamp($lease_expires_at);
    $minimum_retry = $now + 60;
    $maximum_retry = $now + 900;
    if (function_exists('wp_next_scheduled')) {
        $existing = wp_next_scheduled($hook, $args);
        if (function_exists('is_wp_error') && is_wp_error($existing)) {
            return false;
        }
        if (
            $existing !== false
            && intval($existing) > $now
            && intval($existing) <= $maximum_retry
        ) {
            return true;
        }
    }

    $retry_at = $lease_timestamp > 0
        ? $lease_timestamp + 5
        : $maximum_retry;
    $retry_at = max($minimum_retry, min($maximum_retry, $retry_at));

    $scheduled = wp_schedule_single_event($retry_at, $hook, $args);
    if (
        $scheduled !== false
        && !(function_exists('is_wp_error') && is_wp_error($scheduled))
    ) {
        return true;
    }
    if (function_exists('wp_next_scheduled')) {
        $raced_event = wp_next_scheduled($hook, $args);
        return !(function_exists('is_wp_error') && is_wp_error($raced_event))
            && $raced_event !== false
            && intval($raced_event) > $now
            && intval($raced_event) <= $maximum_retry;
    }

    return false;
}

/** Persist a bounded operator-visible issue independently of the beta table. */
function aimee_global_record_live_image_beta_cleanup_issue(
    $user_id,
    $reason_code,
    $retry_scheduled
) {
    $user_id = intval($user_id);
    if ($user_id < 1 || !function_exists('update_option')) return false;

    $option_name = 'aimee_global_live_image_cleanup_issues';
    $issues = function_exists('get_option')
        ? get_option($option_name, [])
        : [];
    $issues = is_array($issues) ? $issues : [];
    foreach ($issues as $issue_key => $issue) {
        if (!is_array($issue)) unset($issues[$issue_key]);
    }
    $key = (string) $user_id;
    $previous = isset($issues[$key]) && is_array($issues[$key])
        ? $issues[$key]
        : [];
    $issues[$key] = [
        'user_id' => $user_id,
        'reason_code' =>
            aimee_global_live_image_beta_cleanup_safe_reason($reason_code),
        'retry_scheduled' => $retry_scheduled ? 1 : 0,
        'attempts' => min(9999, intval($previous['attempts'] ?? 0) + 1),
        'updated_at' => function_exists('current_time')
            ? current_time('mysql', true)
            : gmdate('Y-m-d H:i:s'),
    ];

    if (count($issues) > 50) {
        uasort($issues, function ($left, $right) {
            return strcmp(
                (string) ($right['updated_at'] ?? ''),
                (string) ($left['updated_at'] ?? '')
            );
        });
        $issues = array_slice($issues, 0, 50, true);
    }

    return update_option($option_name, $issues, false) !== false;
}

/** Remove operator provenance once the exact user's cleanup is complete. */
function aimee_global_clear_live_image_beta_cleanup_issue($user_id) {
    $user_id = intval($user_id);
    if (
        $user_id < 1
        || !function_exists('get_option')
        || !function_exists('update_option')
    ) {
        return false;
    }

    $option_name = 'aimee_global_live_image_cleanup_issues';
    $issues = get_option($option_name, []);
    if (!is_array($issues) || !isset($issues[(string) $user_id])) {
        return true;
    }
    unset($issues[(string) $user_id]);
    if (!$issues && function_exists('delete_option')) {
        return delete_option($option_name) !== false;
    }

    return update_option($option_name, $issues, false) !== false;
}

/** Finish any recoverable incomplete path with durable retry and provenance. */
function aimee_global_live_image_beta_cleanup_incomplete(
    array $result,
    $user_id,
    $reason_code,
    $retry_not_before = ''
) {
    $reason_code =
        aimee_global_live_image_beta_cleanup_safe_reason($reason_code);
    $retry_scheduled =
        aimee_global_schedule_live_image_beta_cleanup_retry(
            $user_id,
            $retry_not_before
        );
    $operator_reason = $retry_scheduled
        ? $reason_code
        : 'cleanup_retry_schedule_failed';
    $operator_recorded = aimee_global_record_live_image_beta_cleanup_issue(
        $user_id,
        $reason_code,
        $retry_scheduled
    );
    if (function_exists('do_action')) {
        do_action('aimee_global_live_image_cleanup_retained', [
            'user_id' => intval($user_id),
            'reason_code' => $reason_code,
            'status_reason_code' => $operator_reason,
            'retry_scheduled' => $retry_scheduled ? 1 : 0,
            'operator_recorded' => $operator_recorded ? 1 : 0,
        ]);
    }
    if ((!$operator_recorded || !$retry_scheduled) && function_exists('error_log')) {
        error_log(
            'Aimee live-image cleanup retained for user '
            . intval($user_id) . ': ' . $reason_code
            . ($retry_scheduled ? '' : ' (retry scheduling failed)')
        );
    }

    $result['complete'] = false;
    $result['retry_scheduled'] = $retry_scheduled;
    $result['reason_code'] = $operator_reason;
    return $result;
}

/** Show bounded retained-cleanup provenance to WordPress operators. */
function aimee_global_live_image_beta_cleanup_admin_notice() {
    if (
        !function_exists('current_user_can')
        || !current_user_can('manage_options')
        || !function_exists('get_option')
    ) {
        return;
    }
    $issues = get_option('aimee_global_live_image_cleanup_issues', []);
    if (!is_array($issues) || !$issues) return;

    $summaries = [];
    foreach (array_slice($issues, 0, 5, true) as $issue) {
        if (!is_array($issue)) continue;
        $summaries[] = 'user ' . intval($issue['user_id'] ?? 0)
            . ': ' . aimee_global_live_image_beta_cleanup_safe_reason(
                $issue['reason_code'] ?? ''
            )
            . (!empty($issue['retry_scheduled'])
                ? ' (retry scheduled)'
                : ' (retry scheduling failed)');
    }
    if (!$summaries) return;

    $message = 'Aimee generated-media account cleanup is retained: '
        . implode('; ', $summaries)
        . '. The affected files have not been declared erased.';
    echo '<div class="notice notice-warning"><p>'
        . (function_exists('esc_html') ? esc_html($message) : $message)
        . '</p></div>';
}

/** Cron callback kept separate so the deletion hooks can retain their result. */
function aimee_global_retry_live_image_beta_cleanup($user_id) {
    return aimee_global_cleanup_live_image_beta_user_data($user_id);
}

/** Tombstone jobs without ever clearing their worker-expiry evidence. */
function aimee_global_live_image_beta_tombstone_jobs(
    $table,
    array $columns,
    $user_id,
    $error_code = 'account_deletion_pending'
) {
    global $wpdb;

    $assignments = [
        'status = %s',
        'active_user_id = NULL',
        'updated_at = %s',
        'outcome = %s',
        'error_code = %s',
    ];
    $arguments = [
        'deleting',
        current_time('mysql', true),
        'deleting',
        sanitize_key((string) $error_code),
    ];
    $clear_to_empty = [
        'lease_token',
        'pending_exposure_token',
        'failure_notify_lease_token',
        'handoff_lease_token',
    ];
    $clear_to_null = [
        'next_poll_at',
        'pending_exposure_expires_at',
        'failure_notify_lease_expires_at',
        'handoff_lease_expires_at',
    ];
    foreach ($clear_to_empty as $column) {
        if (in_array($column, $columns, true)) {
            $assignments[] = "`{$column}` = ''";
        }
    }
    foreach ($clear_to_null as $column) {
        if (in_array($column, $columns, true)) {
            $assignments[] = "`{$column}` = NULL";
        }
    }
    if (in_array('global_slot', $columns, true)) {
        $assignments[] = '`global_slot` = 0';
    }
    $arguments[] = intval($user_id);

    return $wpdb->query($wpdb->prepare(
        "UPDATE `{$table}` SET " . implode(', ', $assignments)
        . ' WHERE user_id = %d',
        $arguments
    ));
}

/**
 * Persistent account-erasure backstop for the known Live Image Beta job table.
 * It remains available when the sidecar is deactivated or deleted.
 */
function aimee_global_cleanup_live_image_beta_user_data($user_id) {
    global $wpdb;

    $result = [
        'complete' => false,
        'status' => 'unavailable',
        'rows_seen' => 0,
        'rows_deleted' => 0,
        'rows_retained' => 0,
        'reason_code' => '',
        'retry_scheduled' => false,
    ];
    $user_id = intval($user_id);
    if ($user_id < 1) {
        $result['reason_code'] = 'invalid_cleanup_context';
        return $result;
    }
    if (!isset($wpdb) || !is_object($wpdb)) {
        return aimee_global_live_image_beta_cleanup_incomplete(
            $result,
            $user_id,
            'cleanup_database_unavailable'
        );
    }

    $prefix = isset($wpdb->prefix) ? (string) $wpdb->prefix : '';
    $table = $prefix . 'aimee_live_image_beta_jobs';
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return aimee_global_live_image_beta_cleanup_incomplete(
            $result,
            $user_id,
            'cleanup_database_unavailable'
        );
    }
    if (
        !method_exists($wpdb, 'prepare')
        || !method_exists($wpdb, 'esc_like')
        || !method_exists($wpdb, 'get_var')
        || !method_exists($wpdb, 'get_col')
        || !method_exists($wpdb, 'get_row')
        || !method_exists($wpdb, 'get_results')
        || !method_exists($wpdb, 'query')
        || !method_exists($wpdb, 'delete')
    ) {
        return aimee_global_live_image_beta_cleanup_incomplete(
            $result,
            $user_id,
            'cleanup_database_unavailable'
        );
    }

    $present_table = $wpdb->get_var($wpdb->prepare(
        'SHOW TABLES LIKE %s',
        $wpdb->esc_like($table)
    ));
    if ((string) $present_table !== $table) {
        if (!empty($wpdb->last_error)) {
            return aimee_global_live_image_beta_cleanup_incomplete(
                $result,
                $user_id,
                'cleanup_table_probe_failed'
            );
        }
        $result['complete'] = true;
        $result['status'] = 'absent';
        aimee_global_clear_live_image_beta_cleanup_issue($user_id);
        return $result;
    }

    $columns = array_values(array_map('strval', (array) $wpdb->get_col(
        "SHOW COLUMNS FROM `{$table}`",
        0
    )));
    $required = [
        'id',
        'user_id',
        'active_user_id',
        'status',
        'private_file_token',
        'updated_at',
        'outcome',
        'error_code',
        'lease_expires_at',
    ];
    if (array_diff($required, $columns)) {
        $result['status'] = 'schema_unavailable';
        return aimee_global_live_image_beta_cleanup_incomplete(
            $result,
            $user_id,
            'cleanup_schema_unavailable'
        );
    }

    // Physical cleanup depends on row locks and transactional tombstoning.
    // Never assume those semantics for a legacy/non-transactional table.
    $table_status = $wpdb->get_row($wpdb->prepare(
        'SHOW TABLE STATUS WHERE Name = %s',
        $table
    ), ARRAY_A);
    $engine = is_array($table_status)
        ? strtolower(trim((string) (
            $table_status['Engine'] ?? $table_status['ENGINE'] ?? ''
        )))
        : '';
    $engine_table = is_array($table_status)
        ? (string) ($table_status['Name'] ?? $table_status['NAME'] ?? '')
        : '';
    if ($engine_table !== $table || $engine !== 'innodb') {
        // A single status-CAS-breaking UPDATE is still safe on a legacy table,
        // but no file or provenance row may be removed without InnoDB locks.
        $engine_tombstone =
            aimee_global_live_image_beta_tombstone_jobs(
                $table,
                $columns,
                $user_id,
                'account_delete_engine_invalid'
            );
        $result['status'] = 'engine_unavailable';
        return aimee_global_live_image_beta_cleanup_incomplete(
            $result,
            $user_id,
            $engine_tombstone === false
                ? 'cleanup_tombstone_failed'
                : 'cleanup_engine_unavailable'
        );
    }

    // Lock and snapshot the pre-tombstone worker state, then tombstone in the
    // same transaction. This closes the token-persist/rename race: a worker
    // already between those steps is retained until its original lease drains,
    // while a worker not yet past its DB barrier cannot cross the tombstone.
    if ($wpdb->query('START TRANSACTION') === false) {
        aimee_global_live_image_beta_tombstone_jobs(
            $table,
            $columns,
            $user_id
        );
        $result['status'] = 'tombstone_failed';
        return aimee_global_live_image_beta_cleanup_incomplete(
            $result,
            $user_id,
            'cleanup_tombstone_failed'
        );
    }
    $pre_tombstone_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, status, lease_expires_at FROM `{$table}`"
        . ' WHERE user_id = %d ORDER BY id ASC FOR UPDATE',
        $user_id
    ), ARRAY_A);
    if (!is_array($pre_tombstone_rows)) {
        $wpdb->query('ROLLBACK');
        aimee_global_live_image_beta_tombstone_jobs(
            $table,
            $columns,
            $user_id
        );
        $result['status'] = 'tombstone_failed';
        return aimee_global_live_image_beta_cleanup_incomplete(
            $result,
            $user_id,
            'cleanup_tombstone_failed'
        );
    }

    $now_timestamp = aimee_global_live_image_beta_cleanup_timestamp(
        function_exists('current_time')
            ? current_time('mysql', true)
            : gmdate('Y-m-d H:i:s')
    );
    if ($now_timestamp < 1) $now_timestamp = time();
    $draining_rows = [];
    foreach ($pre_tombstone_rows as $pre_tombstone_row) {
        $lease_timestamp =
            aimee_global_live_image_beta_cleanup_timestamp(
                $pre_tombstone_row['lease_expires_at'] ?? ''
            );
        // Beta deactivation may terminalize the status while a token-persisted
        // worker can still complete its filesystem rename. Future preserved
        // lease evidence wins regardless of that terminal status label.
        if ($lease_timestamp >= $now_timestamp) {
            $draining_rows[intval($pre_tombstone_row['id'] ?? 0)] =
                (string) ($pre_tombstone_row['lease_expires_at'] ?? '');
        }
    }

    // Tombstone every row before inspecting paths. Every worker commit uses a
    // status compare-and-swap, so `deleting` stops late work from becoming ready.
    $tombstoned = aimee_global_live_image_beta_tombstone_jobs(
        $table,
        $columns,
        $user_id
    );
    if ($tombstoned === false) {
        $wpdb->query('ROLLBACK');
        aimee_global_live_image_beta_tombstone_jobs(
            $table,
            $columns,
            $user_id
        );
        $result['status'] = 'tombstone_failed';
        return aimee_global_live_image_beta_cleanup_incomplete(
            $result,
            $user_id,
            'cleanup_tombstone_failed'
        );
    }
    if ($wpdb->query('COMMIT') === false) {
        $wpdb->query('ROLLBACK');
        aimee_global_live_image_beta_tombstone_jobs(
            $table,
            $columns,
            $user_id
        );
        $result['status'] = 'tombstone_failed';
        return aimee_global_live_image_beta_cleanup_incomplete(
            $result,
            $user_id,
            'cleanup_tombstone_failed'
        );
    }

    $result['status'] = 'deleting';
    $last_id = 0;
    $batch_size = 100;
    $maximum_batches = 50;
    $output_checked = false;
    $output = null;
    $retained_reason = '';
    $retry_not_before = '';

    for ($batch = 0; $batch < $maximum_batches; $batch++) {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, status, lease_expires_at, private_file_token"
            . " FROM `{$table}`"
            . ' WHERE user_id = %d AND id > %d'
            . ' ORDER BY id ASC LIMIT ' . $batch_size,
            $user_id,
            $last_id
        ), ARRAY_A);
        if (!is_array($rows)) {
            $result['reason_code'] = 'cleanup_row_read_failed';
            $retained_reason = 'cleanup_row_read_failed';
            break;
        }
        if (!$rows) break;

        $progressed = false;
        foreach ($rows as $row) {
            $row_id = intval($row['id'] ?? 0);
            if ($row_id <= $last_id) continue;
            $last_id = $row_id;
            $progressed = true;
            $result['rows_seen']++;
            $row_status = sanitize_key((string) ($row['status'] ?? ''));
            if ($row_status !== 'deleting') {
                aimee_global_live_image_beta_mark_cleanup_retained(
                    $table,
                    $row_id,
                    $user_id,
                    'account_delete_state_invalid'
                );
                if ($retained_reason === '') {
                    $retained_reason = 'account_delete_state_invalid';
                }
                continue;
            }
            if (isset($draining_rows[$row_id])) {
                $lease_expires_at = (string) $draining_rows[$row_id];
                aimee_global_live_image_beta_mark_cleanup_retained(
                    $table,
                    $row_id,
                    $user_id,
                    'account_delete_worker_drain'
                );
                if ($retained_reason === '') {
                    $retained_reason = 'account_delete_worker_drain';
                }
                if (
                    $retry_not_before === ''
                    || aimee_global_live_image_beta_cleanup_timestamp(
                        $lease_expires_at
                    ) > aimee_global_live_image_beta_cleanup_timestamp(
                        $retry_not_before
                    )
                ) {
                    $retry_not_before = $lease_expires_at;
                }
                continue;
            }
            $token_value = array_key_exists('private_file_token', $row)
                ? $row['private_file_token']
                : null;
            $token = (string) ($token_value ?? '');
            $entry_absent = $token === '';
            $retained_code = '';

            if ($token !== '') {
                if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
                    $retained_code = 'account_delete_token_invalid';
                } else {
                    if (!$output_checked) {
                        $output =
                            aimee_global_live_image_beta_cleanup_output_dir();
                        $output_checked = true;
                    }
                    if (!is_array($output) || empty($output['path'])) {
                        $retained_code = 'account_delete_output_invalid';
                    } else {
                        $candidate = $output['path'] . DIRECTORY_SEPARATOR
                            . $token . '.png';
                        if (!aimee_global_live_image_beta_path_is_strictly_within(
                            $candidate,
                            $output['path']
                        )) {
                            $retained_code = 'account_delete_path_invalid';
                        } elseif (is_link($candidate)) {
                            if (!@unlink($candidate) && is_link($candidate)) {
                                $retained_code =
                                    'account_delete_unlink_failed';
                            }
                        } elseif (file_exists($candidate)) {
                            $resolved = realpath($candidate);
                            if (
                                !is_string($resolved)
                                || !aimee_global_live_image_beta_path_is_strictly_within(
                                    $resolved,
                                    $output['path']
                                )
                                || !is_file($resolved)
                                || (!@unlink($resolved) && file_exists($resolved))
                            ) {
                                $retained_code =
                                    'account_delete_unlink_failed';
                            }
                        }
                        $entry_absent = !file_exists($candidate)
                            && !is_link($candidate);
                        if (!$entry_absent && $retained_code === '') {
                            $retained_code = 'account_delete_unlink_failed';
                        }
                    }
                }
            }

            if ($retained_code !== '' || !$entry_absent) {
                aimee_global_live_image_beta_mark_cleanup_retained(
                    $table,
                    $row_id,
                    $user_id,
                    $retained_code ?: 'account_delete_path_invalid'
                );
                if ($retained_reason === '') {
                    $retained_reason = $retained_code
                        ?: 'account_delete_path_invalid';
                }
                continue;
            }

            // The exact contained entry is proven absent before row erasure.
            $deleted = $wpdb->delete(
                $table,
                [
                    'id' => $row_id,
                    'user_id' => $user_id,
                    'status' => 'deleting',
                    'private_file_token' => $token_value,
                ],
                ['%d', '%d', '%s', '%s']
            );
            if ($deleted === false) {
                aimee_global_live_image_beta_mark_cleanup_retained(
                    $table,
                    $row_id,
                    $user_id,
                    'account_delete_row_delete_failed'
                );
                if ($retained_reason === '') {
                    $retained_reason = 'account_delete_row_delete_failed';
                }
            } elseif (intval($deleted) > 0) {
                $result['rows_deleted']++;
            } else {
                $retained_reason = $retained_reason
                    ?: 'account_delete_row_delete_failed';
            }
        }
        if (!$progressed || count($rows) < $batch_size) break;
    }

    $remaining = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$table}` WHERE user_id = %d",
        $user_id
    ));
    if (!is_numeric($remaining)) {
        return aimee_global_live_image_beta_cleanup_incomplete(
            $result,
            $user_id,
            'cleanup_verification_failed',
            $retry_not_before
        );
    }
    $result['rows_retained'] = max(0, intval($remaining));
    $result['complete'] = $result['rows_retained'] === 0;
    $result['status'] = $result['complete'] ? 'complete' : 'incomplete';
    if (!$result['complete']) {
        return aimee_global_live_image_beta_cleanup_incomplete(
            $result,
            $user_id,
            $retained_reason ?: 'account_delete_cleanup_incomplete',
            $retry_not_before
        );
    }

    aimee_global_clear_live_image_beta_cleanup_issue($user_id);

    return $result;
}

// WordPress account deletion can occur outside Aimee's REST endpoint. Keep the
// erasure backstop registered even if the optional beta plugin is inactive.
if (function_exists('add_action')) {
    add_action(
        'delete_user',
        'aimee_global_cleanup_live_image_beta_user_data',
        8,
        1
    );
    add_action(
        'wpmu_delete_user',
        'aimee_global_cleanup_live_image_beta_user_data',
        8,
        1
    );
    add_action(
        'aimee_global_retry_live_image_beta_cleanup',
        'aimee_global_retry_live_image_beta_cleanup',
        10,
        1
    );
    add_action(
        'admin_notices',
        'aimee_global_live_image_beta_cleanup_admin_notice',
        10,
        0
    );
}
