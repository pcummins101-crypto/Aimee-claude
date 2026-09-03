<?php
/**
 * One-time user-image event semantics for Aimee Global 1.7.8.
 *
 * A chat client can accidentally retain and resend a previous base64 payload
 * after the visible attachment has been cleared. The transport payload must
 * therefore never be treated as proof of a fresh conversational upload.
 *
 * This policy fingerprints decoded image bytes and classifies each request as:
 * fresh, fresh_repeat, explicit_repeat, duplicate_reference, stale_duplicate,
 * invalid, or schema_unavailable. Only intentional current-turn events enter
 * the vision route. Image bytes are not persisted by this module.
 *
 * Compatible with PHP 7.4.
 */

defined('ABSPATH') || exit;

/** @return string */
function aimee_user_image_event_policy_version() {
    return '1.2.0';
}

function aimee_user_image_event_limits() {
    $limits = apply_filters('aimee_user_image_event_limits', array(
        'bytes' => 10 * 1024 * 1024,
        'dimension' => 6000,
        'pixels' => 24000000,
    ));

    return array(
        'bytes' => max(1024, intval($limits['bytes'] ?? 10 * 1024 * 1024)),
        'dimension' => max(256, intval($limits['dimension'] ?? 6000)),
        'pixels' => max(65536, intval($limits['pixels'] ?? 24000000)),
    );
}

/** @param mixed $value @return string */
function aimee_user_image_event_lower($value) {
    $value = str_replace(array('’', '‘'), "'", trim((string) $value));
    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

/**
 * Normalize a client-generated identity created when a file is selected.
 *
 * @param mixed $value
 * @return string
 */
function aimee_user_image_event_normalize_id($value) {
    $value = trim((string) $value);
    if ($value === '' || strlen($value) > 96) return '';

    return preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,95}$/', $value) === 1
        ? $value
        : '';
}

/**
 * Parse and fingerprint a supported image data URI.
 *
 * @param mixed $image_data
 * @return array<string,mixed>
 */
function aimee_user_image_event_parse_data_uri($image_data) {
    $result = array(
        'valid' => false,
        'reason' => 'missing_image',
        'mime_type' => '',
        'base64_data' => '',
        'data_uri' => '',
        'fingerprint' => '',
        'decoded_bytes' => 0,
        'width' => 0,
        'height' => 0,
    );

    if (!is_string($image_data) || trim($image_data) === '') return $result;

    $limits = aimee_user_image_event_limits();
    // Bound the encoded form before decoding to avoid a request-controlled
    // memory spike. The allowance includes the data-URI prefix and padding.
    $encoded_limit = intval(ceil($limits['bytes'] * 4 / 3)) + 1024;
    if (strlen($image_data) > $encoded_limit) {
        $result['reason'] = 'image_too_large';
        return $result;
    }

    if (preg_match(
        '/\Adata:(image\/[a-zA-Z0-9.+-]+);base64,([a-zA-Z0-9+\/=\r\n\t ]+)\z/s',
        trim($image_data),
        $matches
    ) !== 1) {
        $result['reason'] = 'invalid_data_uri';
        return $result;
    }

    $mime_type = strtolower((string) $matches[1]);
    if ($mime_type === 'image/jpg') $mime_type = 'image/jpeg';
    if (!in_array(
        $mime_type,
        array('image/jpeg', 'image/png', 'image/gif', 'image/webp'),
        true
    )) {
        $result['reason'] = 'unsupported_mime_type';
        return $result;
    }

    $base64_data = preg_replace('/\s+/', '', (string) $matches[2]);
    if (!is_string($base64_data) || $base64_data === '') {
        $result['reason'] = 'empty_image_payload';
        return $result;
    }

    $decoded = base64_decode($base64_data, true);
    if (!is_string($decoded) || $decoded === '') {
        $result['reason'] = 'invalid_base64';
        return $result;
    }

    $decoded_bytes = strlen($decoded);
    if ($decoded_bytes > $limits['bytes']) {
        $result['reason'] = 'image_too_large';
        return $result;
    }

    if (!class_exists('finfo') || !function_exists('getimagesizefromstring')) {
        $result['reason'] = 'image_validation_unavailable';
        return $result;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detected_mime = strtolower((string) $finfo->buffer($decoded));
    $dimensions = @getimagesizefromstring($decoded);
    $dimension_mime = is_array($dimensions)
        ? strtolower((string) ($dimensions['mime'] ?? ''))
        : '';
    if ($detected_mime !== $mime_type || $dimension_mime !== $mime_type) {
        $result['reason'] = 'image_mime_mismatch';
        return $result;
    }

    $width = intval($dimensions[0] ?? 0);
    $height = intval($dimensions[1] ?? 0);
    if (
        $width < 1
        || $height < 1
        || $width > $limits['dimension']
        || $height > $limits['dimension']
        || ($width * $height) > $limits['pixels']
    ) {
        $result['reason'] = 'image_dimensions_invalid';
        return $result;
    }

    $result['valid'] = true;
    $result['reason'] = 'supported_image';
    $result['mime_type'] = $mime_type;
    $result['base64_data'] = $base64_data;
    $result['data_uri'] = 'data:' . $mime_type . ';base64,' . $base64_data;
    $result['fingerprint'] = hash('sha256', $decoded);
    $result['decoded_bytes'] = $decoded_bytes;
    $result['width'] = $width;
    $result['height'] = $height;
    return $result;
}

/**
 * Detect an unambiguous request to send the same underlying image again.
 *
 * @param mixed $user_text
 * @return bool
 */
function aimee_user_image_event_has_explicit_repeat_intent($user_text) {
    $text = aimee_user_image_event_lower($user_text);
    if ($text === '') return false;

    return preg_match(
        '/\b(?:(?:send|sending|sent|upload|uploading|uploaded|attach|attaching|attached|'
        . 'share|sharing|shared)\b.{0,45}\b(?:again|once more|another time|back over)|'
        . 're[- ]?(?:send|sending|sent|upload|uploading|uploaded|attach|attaching|attached)|'
        . '(?:same|that|this|previous|last)\s+(?:photo|photograph|picture|pic|selfie|image|screenshot)\b'
        . '.{0,30}\b(?:again|once more)|'
        . '(?:here|there)\s+(?:it|she|he)\s+is\s+again|one more time)\b/iu',
        $text
    ) === 1;
}

/**
 * Detect wording that intentionally refers back to visual context.
 *
 * This remains narrower than a generic pronoun detector. An ordinary message
 * containing "it" must not keep a stale client attachment alive.
 *
 * @param mixed $user_text
 * @return bool
 */
function aimee_user_image_event_has_reference_intent($user_text) {
    $text = aimee_user_image_event_lower($user_text);
    if ($text === '') return false;

    if (preg_match(
        '/\b(?:photo|photograph|picture|pic|selfie|image|screenshot|attachment|snap)\b/iu',
        $text
    ) === 1) {
        return true;
    }

    if (preg_match(
        '/\b(?:what|who|where)\s+(?:can|do)\s+you\s+'
        . '(?:see|notice|recognise|recognize|make out)\b|'
        . '\b(?:can|could)\s+you\s+'
        . '(?:look|zoom|read|identify|recognise|recognize|make out)\b|'
        . '\bhow\s+(?:do|does)\s+(?:i|we|she|he|they)\s+look\b|'
        . '\bwhat\s+(?:am|is|are)\s+(?:i|we|she|he|they)\s+'
        . '(?:wearing|holding|doing)\b/iu',
        $text
    ) === 1) {
        return true;
    }

    // Permit a concise identification only when the wording contains an
    // unmistakably visual relationship. Generic follow-ups such as "That's
    // great" or "What do you think of that?" must not revive retained bytes.
    if (preg_match(
        '/^(?:that(?:\'s| is)|this(?:\'s| is))\s+'
        . '(?:my|our|the|his|her|their)\s+.{1,90}\b'
        . '(?:beside|next to|behind|in front of|standing|sitting|'
        . 'on the (?:left|right)|in the (?:background|foreground))\b/iu',
        $text
    ) === 1) {
        return true;
    }

    return preg_match(
        '/\b(?:on|to)\s+the\s+(?:left|right)\b|'
        . '\b(?:in|at)\s+the\s+(?:background|foreground)\b/iu',
        $text
    ) === 1;
}

/**
 * Pure classifier used by the handler and standalone regressions.
 *
 * @param array<string,mixed> $parsed
 * @param mixed               $user_text
 * @param array<string,mixed> $prior
 * @param mixed               $event_id
 * @return array<string,mixed>
 */
function aimee_user_image_event_classify(
    array $parsed,
    $user_text,
    array $prior = array(),
    $event_id = ''
) {
    $event_id = aimee_user_image_event_normalize_id($event_id);
    $seen_before = !empty($prior['seen']);
    $last_event_id = aimee_user_image_event_normalize_id(
        $prior['last_event_id'] ?? ''
    );

    $base = array(
        'event' => 'invalid',
        'reason' => (string) ($parsed['reason'] ?? 'invalid_image'),
        'use_vision' => false,
        'is_fresh_upload' => false,
        'seen_before' => $seen_before,
        'event_id' => $event_id,
    );
    if (empty($parsed['valid'])) return $base;

    if (!$seen_before) {
        $base['event'] = 'fresh';
        $base['reason'] = 'first_fingerprint_observation';
        $base['use_vision'] = true;
        $base['is_fresh_upload'] = true;
        return $base;
    }

    if (aimee_user_image_event_has_explicit_repeat_intent($user_text)) {
        $base['event'] = 'explicit_repeat';
        $base['reason'] = 'user_explicitly_repeated_image';
        $base['use_vision'] = true;
        return $base;
    }

    // A fresh file-selection identity is authoritative, even when the bytes
    // match a photograph seen before. A retained composer value reuses its old
    // identity, so it cannot manufacture a new upload event.
    if (
        $event_id !== ''
        && ($last_event_id === '' || !hash_equals($last_event_id, $event_id))
    ) {
        $base['event'] = 'fresh_repeat';
        $base['reason'] = 'new_selection_event_same_image';
        $base['use_vision'] = true;
        return $base;
    }

    if (aimee_user_image_event_has_reference_intent($user_text)) {
        $base['event'] = 'duplicate_reference';
        $base['reason'] = 'user_referred_to_prior_image';
        $base['use_vision'] = true;
        return $base;
    }

    $base['event'] = 'stale_duplicate';
    $base['reason'] = 'transport_replayed_without_user_image_intent';
    return $base;
}

/**
 * Check that the messages table can persist the 1.7.8 event evidence.
 *
 * @param bool $refresh
 * @return bool
 */
function aimee_user_image_event_schema_ready($refresh = false) {
    global $wpdb;

    static $ready = null;
    if (!$refresh && $ready !== null) return $ready;
    $ready = false;
    if (!isset($wpdb) || !is_object($wpdb)) return $ready;

    $table = function_exists('aimee_table')
        ? aimee_table('aimee_messages')
        : 'aimee_messages';
    $columns = array_map('strval', (array) $wpdb->get_col(
        "SHOW COLUMNS FROM `{$table}`",
        0
    ));
    $ready = in_array('user_image_fingerprint', $columns, true)
        && in_array('user_image_event', $columns, true)
        && in_array('user_image_event_id', $columns, true);
    return $ready;
}

/**
 * Load prior evidence for this exact image fingerprint.
 *
 * @param int    $user_id
 * @param string $fingerprint
 * @return array<string,mixed>
 */
function aimee_user_image_event_prior_evidence($user_id, $fingerprint) {
    global $wpdb;

    $evidence = array(
        'seen' => false,
        'last_event_id' => '',
        'last_event' => '',
        'last_created_at' => '',
    );
    $user_id = (int) $user_id;
    $fingerprint = strtolower(trim((string) $fingerprint));
    if (
        $user_id < 1
        || preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1
        || !aimee_user_image_event_schema_ready()
    ) {
        return $evidence;
    }

    $table = function_exists('aimee_table')
        ? aimee_table('aimee_messages')
        : 'aimee_messages';
    $primary_key = function_exists('aimee_messages_primary_key')
        ? aimee_messages_primary_key()
        : 'message_id';
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT user_image_event_id, user_image_event, created_at"
        . " FROM `{$table}` WHERE user_id = %d AND sender = 'user'"
        . " AND user_image_fingerprint = %s"
        . " ORDER BY `{$primary_key}` DESC LIMIT 1",
        $user_id,
        $fingerprint
    ), ARRAY_A);
    if (!is_array($row)) return $evidence;

    $evidence['seen'] = true;
    $evidence['last_event_id'] = aimee_user_image_event_normalize_id(
        $row['user_image_event_id'] ?? ''
    );
    $evidence['last_event'] = trim((string) ($row['user_image_event'] ?? ''));
    $evidence['last_created_at'] = trim((string) ($row['created_at'] ?? ''));
    return $evidence;
}

/**
 * Resolve the complete current request image event.
 *
 * @param int   $user_id
 * @param mixed $image_data
 * @param mixed $user_text
 * @param mixed $event_id
 * @return array<string,mixed>
 */
function aimee_user_image_event_resolve(
    $user_id,
    $image_data,
    $user_text,
    $event_id = ''
) {
    $parsed = aimee_user_image_event_parse_data_uri($image_data);
    $raw_payload_present = is_string($image_data) && trim($image_data) !== '';

    if (!empty($parsed['valid']) && !aimee_user_image_event_schema_ready()) {
        return array_merge($parsed, array(
            'policy_version' => aimee_user_image_event_policy_version(),
            'event' => 'schema_unavailable',
            'reason' => 'image_event_schema_unavailable',
            'use_vision' => false,
            'is_fresh_upload' => false,
            'seen_before' => false,
            'event_id' => aimee_user_image_event_normalize_id($event_id),
            'raw_payload_present' => $raw_payload_present,
        ));
    }

    $prior = !empty($parsed['valid'])
        ? aimee_user_image_event_prior_evidence(
            (int) $user_id,
            (string) ($parsed['fingerprint'] ?? '')
        )
        : array('seen' => false, 'last_event_id' => '');
    $classification = aimee_user_image_event_classify(
        $parsed,
        $user_text,
        $prior,
        $event_id
    );

    return array_merge($parsed, $classification, array(
        'policy_version' => aimee_user_image_event_policy_version(),
        'raw_payload_present' => $raw_payload_present,
        'prior_event' => (string) ($prior['last_event'] ?? ''),
        'prior_created_at' => (string) ($prior['last_created_at'] ?? ''),
    ));
}

/**
 * Legacy-compatible marker for an intentional vision event.
 *
 * @param array<string,mixed> $event
 * @return string|null
 */
function aimee_user_image_event_message_marker(array $event) {
    switch ((string) ($event['event'] ?? 'none')) {
        case 'fresh':
            return 'Base64_Image_Received';
        case 'fresh_repeat':
        case 'explicit_repeat':
            return 'Base64_Image_Intentional_Repeat';
        case 'duplicate_reference':
            return 'Base64_Image_Prior_Reference';
        default:
            return null;
    }
}

/**
 * Model instruction whose tense matches the server-owned event truth.
 *
 * @param array<string,mixed> $event
 * @return string
 */
function aimee_user_image_event_prompt_instruction(array $event) {
    switch ((string) ($event['event'] ?? 'none')) {
        case 'fresh':
            return '[The user attached a genuinely new image in this current message. React to what is actually visible without inventing details.]';

        case 'fresh_repeat':
        case 'explicit_repeat':
            return '[The user deliberately selected or sent the same underlying image again in this current message. It is an intentional repeat, not a first-time image. React to the current request without greeting the picture as new.]';

        case 'duplicate_reference':
            return '[The client supplied an image that the user shared earlier, and the current wording refers back to it. Use it only as previously shared visual context. Do not say or imply that the user has just uploaded, sent or attached it again.]';

        default:
            return '';
    }
}
