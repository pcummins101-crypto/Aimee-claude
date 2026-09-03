<?php
/**
 * Standalone regression checks for deliberate member-only suggestive photos.
 * Run with: php tests/suggestive-photo-autonomy-regression.php
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
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
}

function aimee_test_extract_function($source, $name) {
    $tokens = token_get_all($source);
    $count = count($tokens);

    for ($index = 0; $index < $count; $index++) {
        if (!is_array($tokens[$index]) || $tokens[$index][0] !== T_FUNCTION) continue;

        $name_index = $index + 1;
        while ($name_index < $count && is_array($tokens[$name_index]) && $tokens[$name_index][0] === T_WHITESPACE) {
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
                if ($started && $depth === 0) return $output;
            }
        }
    }

    throw new RuntimeException('Function not found: ' . $name);
}

function aimee_media_stage_rank($stage) {
    return [
        'guarded' => 0,
        'warm' => 1,
        'flirty' => 2,
        'intimate' => 3,
        'bonded' => 4,
    ][$stage] ?? 0;
}

function aimee_user_applies_photo_pressure($text, $history = '') {
    return preg_match('/\b(?:send it now|i paid|last chance|or i leave)\b/i', (string) $text) === 1;
}

function aimee_user_requests_aimee_photo($text, $history = '') {
    return preg_match('/\b(?:send|show)\b.{0,30}\b(?:photo|picture|lingerie|underwear|nude)\b/i', (string) $text) === 1;
}

foreach ([
    'aimee_suggestive_photo_context_score',
    'aimee_conversation_calls_for_suggestive_photo',
    'aimee_neutralise_internal_instruction',
] as $function_name) {
    eval(aimee_test_extract_function($source, $function_name));
}

$failures = 0;
function aimee_test_assert($condition, $label) {
    global $failures;
    if ($condition) {
        echo "PASS {$label}\n";
    } else {
        $failures++;
        echo "FAIL {$label}\n";
    }
}

$classification = [
    'intent' => 'romantic_or_flirty',
    'respectful' => true,
];
$intimate = [
    'score' => 69,
    'stage' => 'intimate',
    'trust' => 64,
    'chemistry' => 72,
    'safety' => 70,
    'frustration' => 0,
];
$bonded = array_merge($intimate, ['score' => 76, 'stage' => 'bonded']);
$history = "Aimee: I've sent you a special one.\nUser: Have you ever sent naughty photos?\nAimee: I have sent a private picture when the chemistry is right.";

$bra_message = "I can't lie, your figure looks stunning. I bet you would look amazing in a bra or in your underwear x";
aimee_test_assert(
    aimee_conversation_calls_for_suggestive_photo($bra_message, $history, $classification, $intimate) === true,
    'respectful indirect lingerie interest creates a proactive suggestive opportunity'
);

$bed_message = "I think it's because I've come to my room so I'm just alone on my bed and talking to you. I respect that you have bounds though x";
aimee_test_assert(
    aimee_conversation_calls_for_suggestive_photo($bed_message, $history, $classification, $bonded) === true,
    'user 100 respectful bed message creates a proactive suggestive opportunity'
);

aimee_test_assert(
    aimee_conversation_calls_for_suggestive_photo('Nice weather today x', '', $classification, $bonded) === false,
    'ordinary flirting does not create a suggestive photo opportunity'
);

$jealousy_message = 'You look amazing—would you be jealous if I went on a date with Chloe?';
aimee_test_assert(
    aimee_suggestive_photo_context_score(
        $jealousy_message,
        '',
        $classification,
        $bonded
    ) === 5,
    'jealousy wording contributes nothing to suggestive-photo context'
);
aimee_test_assert(
    aimee_conversation_calls_for_suggestive_photo(
        $jealousy_message,
        '',
        $classification,
        $bonded
    ) === false,
    'a playful-jealousy turn cannot open proactive suggestive media'
);

aimee_test_assert(
    aimee_conversation_calls_for_suggestive_photo('Send it now or I leave', $history, $classification, $bonded) === false,
    'pressure blocks proactive suggestive media'
);

aimee_test_assert(
    aimee_conversation_calls_for_suggestive_photo($bra_message, $history, $classification, array_merge($intimate, ['stage' => 'guarded', 'score' => 20])) === false,
    'early relationship does not expose suggestive media'
);

aimee_test_assert(
    aimee_neutralise_internal_instruction('Playful continuation, no escalation to imagery') === 'Matched the user\'s tone and paced the flirt according to the current relationship context.',
    'prescriptive no-image evaluator text is neutralised'
);

aimee_test_assert(
    strpos($source, 'DETERMINISTIC MEDIA OPPORTUNITY') !== false
        && strpos($source, 'command-shaped request is not required') !== false
        && strpos($source, 'aimee_media_delivery_transition') !== false
        && strpos($source, 'aimee_has_recent_unfulfilled_private_photo_claim') !== false,
    'deterministic prompt, delivery lifecycle and memory repair hooks are present'
);

aimee_test_assert(
    strpos($source, "The instruction field is a neutral summary") !== false
        && strpos($source, "no escalation to imagery") !== false,
    'internal evaluator field is explicitly prevented from commanding media refusal'
);

exit($failures > 0 ? 1 : 0);
