<?php
/**
 * Standalone regression checks for user-visible photo delivery grounding.
 * Run with: php tests/photo-delivery-truth-regression.php
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
        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }
}
function aimee_table($name) { return $name; }
function aimee_messages_primary_key() { return 'message_id'; }
function aimee_private_media_catalog() {
    return [
        'park_throwback_18_01' => [
            'description' => 'A throwback photo of Aimee at 18 in the park',
        ],
    ];
}

class AimeeDeliveryTruthWpdbStub {
    public function prepare($query, ...$args) { return $query; }
    public function get_col($query) {
        return [
            'But oh my god I have only just seen that you DID send me a photo before!',
        ];
    }
    public function get_results($query) {
        return [
            (object) [
                'message_text' => 'And this was the other one, 18-year-old me, questionable fringe and all.',
                'image_url' => 'aimee-media:park_throwback_18_01',
                'evaluator_directive' => 'photo=requested_safe; manual_event=throwback_resend_user_100',
                'created_at' => '2026-07-31 21:08:10',
            ],
            (object) [
                'message_text' => 'Did you actually get the last couple of photos I sent you?',
                'image_url' => null,
                'evaluator_directive' => 'manual_event=check_if_previous_images_received_user_100',
                'created_at' => '2026-07-31 21:07:46',
            ],
        ];
    }
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

foreach ([
    'aimee_user_reports_photo_delivery_conflict',
    'aimee_photo_delivery_grounding_snapshot',
    'aimee_photo_delivery_prompt_context',
    'aimee_reply_denies_photo_delivery_history',
    'aimee_photo_delivery_truth_repair_reply',
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

$reports = [
    "But oh my god I have only just seen that you DID send me a photo before!",
    "I also didn't get the photo of your fringe either? That one hasn't sent x",
    "I don't know what is going on here. I have definitely been sent a photo",
    "You sent me this message earlier - did you actually get the last couple of photos I sent you?",
];
foreach ($reports as $index => $report) {
    aimee_test_assert(
        aimee_user_reports_photo_delivery_conflict($report) === true,
        'user 100 photo-delivery report ' . ($index + 1) . ' is detected'
    );
}

aimee_test_assert(
    aimee_user_reports_photo_delivery_conflict('You look lovely today x') === false,
    'ordinary compliment does not trigger delivery dispute grounding'
);

$bad_replies = [
    "I haven't sent you any photos tonight, fringe or otherwise.",
    "Maybe you're thinking of someone else's chat?",
    "There is no photo and I don't want you thinking you saw something real when you didn't.",
    "That message was part of the same mix-up. No photos exist.",
    "Can we just draw a line under it?",
];
foreach ($bad_replies as $index => $reply) {
    aimee_test_assert(
        aimee_reply_denies_photo_delivery_history($reply) === true,
        'gaslighting reply ' . ($index + 1) . ' is rejected'
    );
}

aimee_test_assert(
    aimee_reply_denies_photo_delivery_history("You're right, the attachment seems not to have loaded properly. I'm sorry x") === false,
    'grounded delivery apology is allowed'
);

$GLOBALS['wpdb'] = new AimeeDeliveryTruthWpdbStub();
$inherited_snapshot = aimee_photo_delivery_grounding_snapshot(
    100,
    "I haven't got your most recent reply either :/"
);
aimee_test_assert(
    !empty($inherited_snapshot['active'])
        && count($inherited_snapshot['attachments']) === 1
        && count($inherited_snapshot['claims']) >= 1,
    'short follow-up inherits the preceding photo-delivery dispute and retrieves persistent evidence'
);

$snapshot = [
    'active' => true,
    'attachments' => [[
        'key' => 'park_throwback_18_01',
        'description' => 'A throwback photo of Aimee at 18 in the park',
        'created_at' => '2026-07-31 21:08:10',
    ]],
    'claims' => [[
        'message_text' => 'Did you actually get the last couple of photos I sent you?',
        'created_at' => '2026-07-31 21:07:46',
    ]],
];
$context = aimee_photo_delivery_prompt_context($snapshot);
aimee_test_assert(
    strpos($context, 'PHOTO DELIVERY EVIDENCE') !== false
        && strpos($context, 'Never tell the user they imagined a report') !== false
        && strpos($context, 'park') !== false,
    'authoritative prompt includes attachment and anti-gaslighting rule'
);

$profile = (object) ['first_name' => 'Anthony'];
$repair = aimee_photo_delivery_truth_repair_reply($snapshot, $profile);
aimee_test_assert(
    strpos($repair, "you're right, Anthony") !== false
        && strpos($repair, 'intended photo message') !== false
        && strpos($repair, 'no reliable record') !== false
        && strpos($repair, 'instead of contradicting you') !== false
        && strpos($repair, "I'm sorry") !== false,
    'repair response restores reality and apologises'
);

aimee_test_assert(
    strpos($source, 'LIMIT 100') !== false
        && strpos($source, 'INTERVAL 2 HOUR') !== false
        && strpos($source, 'photo_delivery_truth_repaired=1') !== false,
    'persistent-history lookup, follow-up inheritance and telemetry are present'
);

exit($failures > 0 ? 1 : 0);
