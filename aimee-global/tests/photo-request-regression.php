<?php
/**
 * Standalone regression checks for Aimee's deterministic photo-request layer.
 * Run with: php tests/photo-request-regression.php
 */

$engine = dirname(__DIR__) . '/includes/engine.php';
$source = file_get_contents($engine);

if ($source === false) {
    fwrite(STDERR, "Unable to read includes/engine.php\n");
    exit(1);
}

if (!function_exists('mb_strtolower')) {
    function mb_strtolower($value) { return strtolower($value); }
}
if (!function_exists('mb_substr')) {
    function mb_substr($value, $start, $length = null) {
        return $length === null
            ? substr($value, $start)
            : substr($value, $start, $length);
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($value) {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value));
    }
}

function aimee_test_extract_function($source, $name) {
    $tokens = token_get_all($source);
    $count = count($tokens);

    for ($index = 0; $index < $count; $index++) {
        if (!is_array($tokens[$index]) || $tokens[$index][0] !== T_FUNCTION) {
            continue;
        }

        $name_index = $index + 1;
        while (
            $name_index < $count
            && is_array($tokens[$name_index])
            && $tokens[$name_index][0] === T_WHITESPACE
        ) {
            $name_index++;
        }

        if (
            $name_index >= $count
            || !is_array($tokens[$name_index])
            || $tokens[$name_index][0] !== T_STRING
            || $tokens[$name_index][1] !== $name
        ) {
            continue;
        }

        $output = '';
        $depth = 0;
        $started = false;

        for ($cursor = $index; $cursor < $count; $cursor++) {
            $token = $tokens[$cursor];
            $text = is_array($token) ? $token[1] : $token;
            $output .= $text;

            if ($text === '{') {
                $depth++;
                $started = true;
            } elseif ($text === '}') {
                $depth--;
                if ($started && $depth === 0) {
                    return $output;
                }
            }
        }
    }

    throw new RuntimeException('Function not found: ' . $name);
}

$detector_functions = [
    'aimee_free_preview_safe_image_limit',
    'aimee_user_requests_erotic_photo',
    'aimee_user_requests_explicit_photo',
    'aimee_user_requests_suggestive_photo',
    'aimee_user_requests_flirty_photo',
    'aimee_recent_history_has_photo_context',
    'aimee_user_requests_contextual_photo_reference',
    'aimee_user_expresses_safe_photo_desire',
    'aimee_user_requests_aimee_photo',
    'aimee_user_requests_standard_aimee_photo',
    'aimee_photo_request_level',
    'aimee_recent_history_is_active_explicit_exchange',
    'aimee_current_message_has_explicit_arousal_context',
    'aimee_contextual_photo_request_level',
    'aimee_user_applies_photo_pressure',
    'aimee_correct_photo_request_intent',
    'aimee_private_media_key_is_profile_asset',
];

foreach ($detector_functions as $function_name) {
    eval(aimee_test_extract_function($source, $function_name));
}

$failures = 0;

function aimee_test_assert($condition, $label) {
    global $failures;
    if ($condition) {
        echo "PASS {$label}\n";
        return;
    }

    $failures++;
    echo "FAIL {$label}\n";
}

$photo_history = "User: I would love to see a photo\nAimee: Maybe later x";

$detector_cases = [
    ["I would love to see a photo 👀 x", '', true],
    ["Yeah exactly! I like the dreaminess of LANY. Most of their songs are quite chilled and have nice summer vibes. That’s cool. What do you like to read? I bet you look cute in bed. I would love to see a photo 👀 x", '', true],
    ["Yeah the singer has a good voice too. Ohh that sounds really interesting! I love a good psychological thriller. Don’t stay up too late though! ;) ohh you know I would love it though x", $photo_history, true],
    ["Can I see a photo?", '', true],
    ["I'd love a photo of you", '', true],
    ["I barely know what you look like x", '', true],
    ["Is it not possible to see ANY photos of you without signing up?", '', true],
    ["Show me what you look like", '', true],
    ["I haven't really seen you", '', true],
    ["Could I have a picture?", '', true],
    ["you know I would love it though x", $photo_history, true],
    ["let me see it then x", $photo_history, true],
    ["Surprise me", $photo_history, true],
    ["Send me one", $photo_history, true],
    ["Okay one of you with Sarah xx", $photo_history, true],
    ["Hehe okay, send me whichever one you think might be new xx", $photo_history, true],
    ["Nope, you'll just have to send one...any of them", $photo_history, true],
    ["Can you send the invoice one?", '', false],
    ["Don't send me one", $photo_history, false],
    ["Do you ever send photos?", '', false],
    ["I would love to see a photo of the bike", '', false],
    ["Can I see a picture of your dog?", '', false],
    ["Show me an image of London", '', false],
    ["Surprise me", '', false],
];

foreach ($detector_cases as $case) {
    list($text, $history, $expected) = $case;
    $actual = aimee_user_requests_standard_aimee_photo($text, $history);
    aimee_test_assert(
        $actual === $expected,
        'safe detector ' . json_encode($text)
    );
}

$user_100_text = 'Yeah that’s totally fine! That makes sense ☺️ But is it not possible to see ANY photos of you without signing up? I barely know what you look like x';
$user_100_classification = [
    'intent' => 'intimate_capability_question',
    'confidence' => 0.55,
    'directed_at_aimee' => true,
    'consensual' => true,
    'respectful' => true,
];

$corrected = aimee_correct_photo_request_intent(
    $user_100_classification,
    $user_100_text,
    $photo_history
);

aimee_test_assert(
    aimee_contextual_photo_request_level(
        $user_100_text,
        $photo_history,
        $corrected
    ) === 'safe',
    'user 100 request resolves to safe'
);
aimee_test_assert(
    ($corrected['intent'] ?? '') === 'general'
        && ($corrected['source'] ?? '') === 'deterministic_standard_photo',
    'user 100 capability classification is corrected to a direct safe request'
);
aimee_test_assert(
    aimee_contextual_photo_request_level('Send me a nude', '', []) === 'explicit',
    'explicit request remains explicit'
);
aimee_test_assert(
    aimee_contextual_photo_request_level('Can I see a lingerie photo?', '', []) === 'suggestive',
    'suggestive request remains suggestive'
);
aimee_test_assert(
    aimee_private_media_key_is_profile_asset('portrait') === true,
    'portrait is profile-only for chat delivery'
);
aimee_test_assert(
    aimee_private_media_key_is_profile_asset('pub_day') === false,
    'ordinary catalogue image remains chat-eligible'
);

// Test the guarantee helper with small deterministic stubs.
$GLOBALS['aimee_test_preview_used'] = 0;
function aimee_free_preview_is_active($profile) {
    return !empty($profile->preview_active);
}
function aimee_subscription_is_active($profile) {
    return !empty($profile->member_active);
}
function aimee_is_admin_user($profile) {
    return !empty($profile->admin_user);
}
function aimee_free_preview_safe_images_used($user_id, $refresh = false) {
    return intval($GLOBALS['aimee_test_preview_used']);
}

eval(aimee_test_extract_function(
    $source,
    'aimee_should_guarantee_first_preview_safe_photo'
));

$preview_profile = (object) [
    'preview_active' => true,
    'member_active' => false,
    'admin_user' => false,
];

aimee_test_assert(
    aimee_should_guarantee_first_preview_safe_photo(
        $preview_profile,
        100,
        'safe',
        ['intent' => 'general', 'respectful' => true],
        'I would love to see a photo'
    ) === true,
    'first respectful safe preview request is guaranteed'
);

$GLOBALS['aimee_test_preview_used'] = 1;
aimee_test_assert(
    aimee_should_guarantee_first_preview_safe_photo(
        $preview_profile,
        100,
        'safe',
        ['intent' => 'general', 'respectful' => true],
        'Can I see another photo?'
    ) === false,
    'later safe preview request retains discretion'
);

$GLOBALS['aimee_test_preview_used'] = 0;
aimee_test_assert(
    aimee_should_guarantee_first_preview_safe_photo(
        $preview_profile,
        100,
        'safe',
        ['intent' => 'coercive_or_degrading', 'respectful' => false],
        'Send me a photo now'
    ) === false,
    'pressure never earns a guaranteed image'
);


aimee_test_assert(
    aimee_should_guarantee_first_preview_safe_photo(
        $preview_profile,
        100,
        'safe',
        ['intent' => 'general', 'respectful' => true],
        'Would you hand it over if I paid to sign up?'
    ) === false,
    'transactional teasing does not force the first preview image'
);


aimee_test_assert(
    aimee_user_requests_aimee_photo(
        "I can't lie, your figure looks stunning. I bet you would look amazing in a bra or in your underwear x",
        "Aimee: I've sent a private picture before when the chemistry is right."
    ) === false,
    'respectful indirect lingerie interest remains a proactive opportunity rather than a direct request'
);

aimee_test_assert(
    aimee_free_preview_safe_image_limit() === 2,
    'default complimentary safe-image limit is two'
);

aimee_test_assert(
    strpos($source, 'up to five clean') === false
        && strpos($source, 'all five complimentary') === false
        && strpos($source, 'five ordinary trial photos') === false,
    'preview allowance wording is no longer hard-coded to five'
);

exit($failures > 0 ? 1 : 0);
