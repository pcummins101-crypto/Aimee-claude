<?php
/**
 * Standalone regressions for one-time user-image event semantics.
 * Run with: php tests/user-image-event-regression.php
 */

define('ABSPATH', dirname(__DIR__) . '/');
function apply_filters($hook, $value) { return $value; }
require_once dirname(__DIR__) . '/includes/user-image-events.php';

$failures = array();
$checks = 0;

$assert = static function ($condition, $label) use (&$failures, &$checks) {
    $checks++;
    if (!$condition) $failures[] = $label;
};

$bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
$base64 = base64_encode($bytes);
$data_uri = 'data:image/png;base64,' . $base64;
$jpg_uri = 'data:image/jpg;base64,' . $base64;
$spaced_uri = 'data:image/png;base64,' . chunk_split($base64, 8, " \n");

$assert(aimee_user_image_event_policy_version() === '1.2.0', 'event policy exposes version 1.2.0');
$assert(aimee_user_image_event_normalize_id('event-1234') === 'event-1234', 'valid selection identity survives normalization');
$assert(aimee_user_image_event_normalize_id('short') === '', 'too-short selection identity is rejected');
$assert(aimee_user_image_event_normalize_id('bad event id') === '', 'selection identity rejects spaces');
$assert(aimee_user_image_event_normalize_id(str_repeat('a', 97)) === '', 'selection identity rejects oversized values');

$parsed = aimee_user_image_event_parse_data_uri($data_uri);
$parsed_spaced = aimee_user_image_event_parse_data_uri($spaced_uri);
$parsed_jpg = aimee_user_image_event_parse_data_uri($jpg_uri);
$assert(!empty($parsed['valid']), 'supported PNG data URI is accepted');
$assert(($parsed['mime_type'] ?? '') === 'image/png', 'PNG MIME type is retained');
$assert(($parsed['decoded_bytes'] ?? 0) === strlen($bytes), 'decoded byte count is exact');
$assert(($parsed['fingerprint'] ?? '') === hash('sha256', $bytes), 'fingerprint is SHA-256 of decoded bytes');
$assert(($parsed_spaced['fingerprint'] ?? '') === ($parsed['fingerprint'] ?? ''), 'base64 whitespace does not change image identity');
$assert(empty($parsed_jpg['valid']) && ($parsed_jpg['reason'] ?? '') === 'image_mime_mismatch', 'declared JPEG MIME cannot disguise PNG bytes');
$assert(($parsed['width'] ?? 0) === 1 && ($parsed['height'] ?? 0) === 1, 'decoded image dimensions are validated');

$unsupported = aimee_user_image_event_parse_data_uri('data:image/svg+xml;base64,' . base64_encode('<svg/>'));
$invalid_uri = aimee_user_image_event_parse_data_uri('not-a-data-uri');
$invalid_base64 = aimee_user_image_event_parse_data_uri('data:image/png;base64,%%%%');
$missing = aimee_user_image_event_parse_data_uri('');
$assert(empty($unsupported['valid']) && ($unsupported['reason'] ?? '') === 'unsupported_mime_type', 'unsupported image MIME is rejected');
$assert(empty($invalid_uri['valid']) && ($invalid_uri['reason'] ?? '') === 'invalid_data_uri', 'non-data URI is rejected');
$assert(empty($invalid_base64['valid']) && ($invalid_base64['reason'] ?? '') === 'invalid_data_uri', 'invalid base64 alphabet is rejected before decode');
$assert(empty($missing['valid']) && ($missing['reason'] ?? '') === 'missing_image', 'missing image is classified explicitly');

$assert(aimee_user_image_event_has_explicit_repeat_intent('I am sending the same photo again.'), 'same-photo resend wording is explicit repeat intent');
$assert(aimee_user_image_event_has_explicit_repeat_intent('Here it is again.'), 'short resend wording is explicit repeat intent');
$assert(!aimee_user_image_event_has_explicit_repeat_intent('What did you think of it?'), 'ordinary reference is not a deliberate resend');
$assert(aimee_user_image_event_has_reference_intent('What do you think of this picture?'), 'explicit picture wording is visual reference intent');
$assert(aimee_user_image_event_has_reference_intent("That's my wife beside me."), 'grounded identification with visible positioning refers to prior visual context');
$assert(!aimee_user_image_event_has_reference_intent("That's great, tell me more."), 'generic that follow-up does not revive retained image bytes');
$assert(!aimee_user_image_event_has_reference_intent('What do you think of that idea?'), 'generic opinion question does not become visual reference intent');
$assert(aimee_user_image_event_has_reference_intent('Can you read what is written on it?'), 'visual inspection request is reference intent');
$assert(!aimee_user_image_event_has_reference_intent('How was your day?'), 'ordinary conversation does not keep a stale image alive');
$assert(!aimee_user_image_event_has_reference_intent('I think it went well at work.'), 'generic pronoun does not become visual intent');

$fresh = aimee_user_image_event_classify(
    $parsed,
    'What do you think?',
    array('seen' => false, 'last_event_id' => ''),
    'event-1111'
);
$assert(($fresh['event'] ?? '') === 'fresh', 'unseen fingerprint is a fresh image event');
$assert(!empty($fresh['use_vision']), 'fresh image enters the vision route');
$assert(!empty($fresh['is_fresh_upload']), 'fresh image is marked as genuinely new');

$stale = aimee_user_image_event_classify(
    $parsed,
    'How was your day?',
    array('seen' => true, 'last_event_id' => 'event-1111'),
    'event-1111'
);
$assert(($stale['event'] ?? '') === 'stale_duplicate', 'same retained payload without image intent is stale');
$assert(empty($stale['use_vision']), 'stale duplicate is stripped from the vision route');
$assert(empty($stale['is_fresh_upload']), 'stale duplicate cannot claim a fresh upload');

$stale_without_id = aimee_user_image_event_classify(
    $parsed,
    'Tell me something cheeky.',
    array('seen' => true, 'last_event_id' => ''),
    ''
);
$assert(($stale_without_id['event'] ?? '') === 'stale_duplicate', 'legacy client duplicate without visual intent also fails closed');

$fresh_repeat = aimee_user_image_event_classify(
    $parsed,
    'Look at this.',
    array('seen' => true, 'last_event_id' => 'event-1111'),
    'event-2222'
);
$assert(($fresh_repeat['event'] ?? '') === 'fresh_repeat', 'new file-selection identity permits deliberate same-byte resend');
$assert(!empty($fresh_repeat['use_vision']), 'deliberate reselection enters vision');
$assert(empty($fresh_repeat['is_fresh_upload']), 'same underlying image is not mislabeled as first sight');

$explicit_repeat = aimee_user_image_event_classify(
    $parsed,
    'I am sending the same photo again so you can zoom in.',
    array('seen' => true, 'last_event_id' => 'event-1111'),
    'event-1111'
);
$assert(($explicit_repeat['event'] ?? '') === 'explicit_repeat', 'explicit resend beats stale transport classification');
$assert(!empty($explicit_repeat['use_vision']), 'explicit repeat remains available to vision');

$reference = aimee_user_image_event_classify(
    $parsed,
    "That's my brother standing beside me.",
    array('seen' => true, 'last_event_id' => 'event-1111'),
    'event-1111'
);
$assert(($reference['event'] ?? '') === 'duplicate_reference', 'intentional reference may reuse prior visual context');
$assert(!empty($reference['use_vision']), 'prior visual reference remains inspectable');

$invalid = aimee_user_image_event_classify(
    $invalid_uri,
    'Look at this.',
    array('seen' => false),
    'event-1111'
);
$assert(($invalid['event'] ?? '') === 'invalid', 'invalid image cannot manufacture a fresh event');
$assert(empty($invalid['use_vision']), 'invalid image never enters vision');

$assert(aimee_user_image_event_message_marker($fresh) === 'Base64_Image_Received', 'fresh marker preserves legacy compatibility');
$assert(aimee_user_image_event_message_marker($fresh_repeat) === 'Base64_Image_Intentional_Repeat', 'intentional repeat has distinct persisted marker');
$assert(aimee_user_image_event_message_marker($reference) === 'Base64_Image_Prior_Reference', 'prior reference has distinct persisted marker');
$assert(aimee_user_image_event_message_marker($stale) === null, 'stale duplicate persists no attachment marker');

$fresh_prompt = aimee_user_image_event_prompt_instruction($fresh);
$repeat_prompt = aimee_user_image_event_prompt_instruction($fresh_repeat);
$reference_prompt = aimee_user_image_event_prompt_instruction($reference);
$stale_prompt = aimee_user_image_event_prompt_instruction($stale);
$assert(strpos($fresh_prompt, 'genuinely new image') !== false, 'fresh prompt uses current-event truth');
$assert(strpos($repeat_prompt, 'intentional repeat') !== false, 'repeat prompt prevents first-time greeting');
$assert(strpos($repeat_prompt, 'not a first-time image') !== false, 'repeat prompt states same-image continuity');
$assert(strpos($reference_prompt, 'shared earlier') !== false, 'reference prompt identifies prior context');
$assert(strpos($reference_prompt, 'not') !== false && strpos($reference_prompt, 'just uploaded') !== false, 'reference prompt forbids false new-upload language');
$assert($stale_prompt === '', 'stale duplicate sends no image instruction to the model');

$assert(aimee_user_image_event_schema_ready(true) === false, 'standalone policy fails closed when persistence schema is unavailable');

if ($failures) {
    echo "User-image event regression failures:\n- " . implode("\n- ", $failures) . "\n";
    exit(1);
}

echo "PASS: {$checks} one-time user-image event checks.\n";
