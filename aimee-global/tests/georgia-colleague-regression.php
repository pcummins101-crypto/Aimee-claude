<?php
/**
 * Standalone regressions for Georgia's authenticated colleague workflow.
 *
 * Written social/photo ideation is professional copy work. It must not be
 * confused with a request to attach one of Aimee's private media files.
 *
 * Run with:
 *   node tests/run-php-wasm.mjs tests/georgia-colleague-regression.php
 */

$engine_path = dirname(__DIR__) . '/includes/engine.php';
$relationship_policy_path = dirname(__DIR__) . '/includes/relationship-policy.php';
$engine_source = file_get_contents($engine_path);
$relationship_policy_source = file_get_contents($relationship_policy_path);

if ($engine_source === false || $relationship_policy_source === false) {
    fwrite(STDERR, "Unable to read Georgia regression sources.\n");
    exit(1);
}

if (!function_exists('mb_strtolower')) {
    function mb_strtolower($value) {
        return strtolower((string) $value);
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr($value, $start, $length = null) {
        return $length === null
            ? substr((string) $value, $start)
            : substr((string) $value, $start, $length);
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen($value) {
        return strlen((string) $value);
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($value) {
        return preg_replace(
            '/[^a-z0-9_\-]/',
            '',
            strtolower((string) $value)
        );
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value) {
        return trim(strip_tags((string) $value));
    }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($value) {
        return trim(strip_tags((string) $value));
    }
}

function aimee_georgia_test_extract_function($source, $name) {
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

foreach (array(
    'aimee_configured_identity_user_id',
    'aimee_profile_user_id',
    'aimee_admin_role',
    'aimee_is_colleague_user',
    'aimee_repair_georgia_colleague_state_173',
    'aimee_colleague_written_creative_brief',
    'aimee_colleague_creative_brief_directive',
    'aimee_colleague_reply_needs_creative_repair',
    'aimee_colleague_creative_fallback',
) as $helper_name) {
    eval(aimee_georgia_test_extract_function($engine_source, $helper_name));
}

foreach (array(
    'aimee_relationship_policy_bool',
    'aimee_relationship_policy_lower',
    'aimee_relationship_policy_direct_degrading_pattern_id',
    'aimee_relationship_policy_detect_coercion',
) as $helper_name) {
    eval(aimee_georgia_test_extract_function(
        $relationship_policy_source,
        $helper_name
    ));
}

$failures = 0;

function aimee_georgia_test_assert($condition, $label) {
    global $failures;

    if ($condition) {
        echo "PASS {$label}\n";
        return;
    }

    $failures++;
    echo "FAIL {$label}\n";
}

function aimee_georgia_test_numbered_item_count($text) {
    $matches = array();
    $count = preg_match_all(
        '/(?:^|\s)(\d{1,2})\s*[\)\.:\-]\s+/u',
        (string) $text,
        $matches
    );

    return $count === false ? 0 : (int) $count;
}

function aimee_georgia_test_brief($text, $history = '') {
    $brief = aimee_colleague_written_creative_brief($text, $history);

    aimee_georgia_test_assert(
        is_array($brief),
        'creative brief helper returns inspectable metadata'
    );

    return is_array($brief) ? $brief : array();
}

if (!defined('AIMEE_GEORGIA_USER_ID')) {
    define('AIMEE_GEORGIA_USER_ID', 24);
}

if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return array_key_exists($name, $GLOBALS['aimee_georgia_test_options'])
            ? $GLOBALS['aimee_georgia_test_options'][$name]
            : $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null) {
        $GLOBALS['aimee_georgia_test_options'][$name] = $value;
        return true;
    }
}
if (!function_exists('current_time')) {
    function current_time($type, $gmt = false) {
        return '2026-08-05 12:00:00';
    }
}
if (!function_exists('aimee_table')) {
    function aimee_table($name) {
        return (string) $name;
    }
}
if (!function_exists('aimee_load_inner_state')) {
    function aimee_load_inner_state($user_id, $create = true) {
        return $GLOBALS['aimee_georgia_test_inner_state'];
    }
}
if (!function_exists('aimee_save_inner_state')) {
    function aimee_save_inner_state($user_id, array $state) {
        $GLOBALS['aimee_georgia_test_saved_states'][] = array(
            'user_id' => (int) $user_id,
            'state' => $state,
        );
        $GLOBALS['aimee_georgia_test_inner_state'] = $state;
        return true;
    }
}

class Aimee_Georgia_Test_Wpdb {
    public $profile;
    public $known_false_reply_count = 0;

    public function __construct() {
        $this->profile = (object) array(
            'user_id' => 24,
            'intimacy_score' => 4,
            'intimacy_stage' => 'guarded',
        );
    }

    public function prepare($query) {
        return array(
            'query' => (string) $query,
            'args' => array_slice(func_get_args(), 1),
        );
    }

    public function get_row($prepared) {
        return $this->profile;
    }

    public function get_var($prepared) {
        return $this->known_false_reply_count;
    }
}

aimee_georgia_test_assert(
    aimee_is_colleague_user((object) array('user_id' => 24)),
    'immutable user 24 resolves to the colleague role'
);
aimee_georgia_test_assert(
    !aimee_is_colleague_user((object) array('user_id' => 23)),
    'an adjacent account does not inherit Georgia colleague mode'
);
aimee_georgia_test_assert(
    !aimee_is_colleague_user((object) array(
        'user_id' => 25,
        'first_name' => 'Georgia',
        'phone_number' => '24',
    )),
    'name and editable profile data cannot confer Georgia identity'
);

$GLOBALS['aimee_georgia_test_options'] = array();
$GLOBALS['aimee_georgia_test_saved_states'] = array();
$GLOBALS['aimee_georgia_test_inner_state'] = array(
    'valence' => -17,
    'social_appetite' => 30,
    'curiosity' => 58,
    'irritation' => 38,
    'vulnerability' => 5,
    'playfulness' => 12,
    'romantic_openness' => 0,
    'unresolved_rupture' => 'Pressure, entitlement or degrading treatment has not yet been repaired.',
    'repair_status' => 'ruptured',
);
$wpdb = new Aimee_Georgia_Test_Wpdb();
$wpdb->known_false_reply_count = 1;
aimee_repair_georgia_colleague_state_173();

$repaired_state = $GLOBALS['aimee_georgia_test_inner_state'];
$repair_summary = $GLOBALS['aimee_georgia_test_options'][
    'aimee_global_georgia_colleague_repair_173'
] ?? array();
aimee_georgia_test_assert(
    count($GLOBALS['aimee_georgia_test_saved_states']) === 1,
    'known user-24 false rupture is repaired exactly once'
);
aimee_georgia_test_assert(
    ($repaired_state['unresolved_rupture'] ?? null) === ''
        && ($repaired_state['repair_status'] ?? '') === 'clear',
    'repair clears only the known false consumer rupture markers'
);
aimee_georgia_test_assert(
    (int) ($repaired_state['irritation'] ?? -1) === 0
        && (int) ($repaired_state['romantic_openness'] ?? -1) === 0,
    'repair restores professional warmth without manufacturing romance'
);
aimee_georgia_test_assert(
    ($repair_summary['action'] ?? '') === 'false_consumer_rupture_cleared',
    'repair records an inspectable completion summary'
);
aimee_georgia_test_assert(
    (int) $wpdb->profile->intimacy_score === 4
        && (string) $wpdb->profile->intimacy_stage === 'guarded',
    'repair preserves Georgia consumer score and stage as non-authoritative history'
);
aimee_repair_georgia_colleague_state_173();
aimee_georgia_test_assert(
    count($GLOBALS['aimee_georgia_test_saved_states']) === 1,
    'completed repair is idempotent'
);

$GLOBALS['aimee_georgia_test_options'] = array();
$GLOBALS['aimee_georgia_test_saved_states'] = array();
$GLOBALS['aimee_georgia_test_inner_state'] = array(
    'unresolved_rupture' => '',
    'repair_status' => 'clear',
);
$wpdb = new Aimee_Georgia_Test_Wpdb();
aimee_repair_georgia_colleague_state_173();
$no_action_summary = $GLOBALS['aimee_georgia_test_options'][
    'aimee_global_georgia_colleague_repair_173'
] ?? array();
aimee_georgia_test_assert(
    count($GLOBALS['aimee_georgia_test_saved_states']) === 0,
    'clean colleague state is not rewritten'
);
aimee_georgia_test_assert(
    ($no_action_summary['action'] ?? '') === 'no_false_state_found',
    'no-evidence path closes with a non-mutating audit result'
);

$exact_request = 'Hi Aimee, are was wondering if you’d be able to send me 10 post idea for your social media. Try and not do ones what you’ve done before. Think of activities you like, oufits you enjoy and things you like to do. Maybe add a bit of flirt. One of them being ladies day';
$exact_brief = aimee_georgia_test_brief($exact_request);

aimee_georgia_test_assert(
    !empty($exact_brief['active']),
    'supplied Georgia message is recognised as written creative work'
);
aimee_georgia_test_assert(
    (int) ($exact_brief['requested_count'] ?? 0) === 10,
    'supplied Georgia message retains its requested count of ten'
);
aimee_georgia_test_assert(
    !empty($exact_brief['allow_flirty']),
    'supplied Georgia message permits flirty written concepts'
);
aimee_georgia_test_assert(
    !empty($exact_brief['text_only']),
    'supplied Georgia message is explicitly text-only'
);
aimee_georgia_test_assert(
    ($exact_brief['deliverable_type'] ?? '') === 'ideas',
    'supplied Georgia request retains its written-ideas deliverable type'
);

$written_cases = array(
    array(
        'Please give me 10 safe photo ideas for Aimee on Instagram.',
        '',
        10,
        false,
        'safe photo ideas are written work',
    ),
    array(
        'Write five flirty photo descriptions for next week’s social posts.',
        '',
        5,
        true,
        'flirty descriptions are written work',
    ),
    array(
        'Draft 12 flirty but non-explicit shoot concepts in Aimee’s voice.',
        '',
        12,
        true,
        'non-explicit flirty shoot concepts are written work',
    ),
    array(
        'More please, think of activities you like indoors and outdoors.',
        "User: Give me social media photo ideas.\nAimee: Here are the first three concepts.",
        0,
        false,
        'short continuation inherits recent creative-brief context',
    ),
);

foreach ($written_cases as $case) {
    $brief = aimee_georgia_test_brief($case[0], $case[1]);
    aimee_georgia_test_assert(!empty($brief['active']), $case[4]);
    aimee_georgia_test_assert(
        !empty($brief['text_only']),
        $case[4] . ' remains text-only'
    );

    if ($case[2] > 0) {
        aimee_georgia_test_assert(
            (int) ($brief['requested_count'] ?? 0) === $case[2],
            $case[4] . ' preserves its requested count'
        );
    }

    if ($case[3]) {
        aimee_georgia_test_assert(
            !empty($brief['allow_flirty']),
            $case[4] . ' preserves its permitted tone'
        );
    }
}

$caption_brief = aimee_georgia_test_brief(
    'Write ten cheeky Instagram captions for Aimee.'
);
aimee_georgia_test_assert(
    !empty($caption_brief['active'])
        && ($caption_brief['deliverable_type'] ?? '') === 'captions',
    'Instagram caption work retains a caption deliverable type'
);
$caption_fallback = aimee_colleague_creative_fallback($caption_brief);
aimee_georgia_test_assert(
    strpos(mb_strtolower($caption_fallback), 'caption set') !== false
        && aimee_georgia_test_numbered_item_count($caption_fallback) === 10,
    'provider failure falls back to a complete caption set rather than generic refusal'
);
aimee_georgia_test_assert(
    strpos(
        mb_strtolower(aimee_colleague_creative_brief_directive($caption_brief)),
        'captions'
    ) !== false,
    'provider directive preserves the requested caption deliverable type'
);

$flirty_continuation = aimee_georgia_test_brief(
    'More please.',
    'User: Give me ten flirty social photo ideas.\nAimee: 1. Rooftop drinks.'
);
aimee_georgia_test_assert(
    !empty($flirty_continuation['active'])
        && !empty($flirty_continuation['allow_flirty'])
        && ($flirty_continuation['deliverable_type'] ?? '') === 'ideas',
    'creative continuation inherits the established deliverable and brand-appropriate flirty tone'
);

$actual_attachment_cases = array(
    'Send me a photo.',
    'Show me a flirty selfie.',
    'Send me the black lingerie photo.',
    'Resend the last image.',
    'Attach a safe image to this message.',
    'Give me a flirty photo.',
);

foreach ($actual_attachment_cases as $attachment_request) {
    $brief = aimee_georgia_test_brief($attachment_request);
    aimee_georgia_test_assert(
        empty($brief['active']),
        'actual attachment is not creative ideation: '
            . json_encode($attachment_request)
    );
}

$directive = aimee_colleague_creative_brief_directive($exact_brief);
$directive_lower = mb_strtolower((string) $directive);
aimee_georgia_test_assert(
    strpos($directive_lower, 'text-only') !== false
        || strpos($directive_lower, 'written') !== false,
    'creative directive explicitly frames the task as written work'
);
aimee_georgia_test_assert(
    strpos($directive_lower, '10') !== false,
    'creative directive includes the requested item count'
);
aimee_georgia_test_assert(
    strpos($directive_lower, 'media_key') !== false,
    'creative directive keeps the attachment contract empty'
);
aimee_georgia_test_assert(
    strpos($directive_lower, 'full') !== false
        || strpos($directive_lower, 'complete') !== false
        || strpos($directive_lower, 'exactly') !== false,
    'creative directive requires a complete answer'
);

$complete_ten = "1) Ladies Day styling.\n2) Seaside walk.\n3) Garden lunch.\n4) Bookshop browse.\n5) Rooftop drinks.\n6) Baking afternoon.\n7) Yoga morning.\n8) Farmers market.\n9) Summer fair.\n10) Picnic with Sarah.";
$incomplete_four = "1) Ladies Day styling.\n2) Seaside walk.\n3) Garden lunch.\n4) Bookshop browse.";
$boundary_refusal = 'No. Pressure, countdowns or threatening to leave will not get you one.';

aimee_georgia_test_assert(
    aimee_colleague_reply_needs_creative_repair(
        $complete_ten,
        $exact_brief
    ) === false,
    'complete ten-item creative reply does not need repair'
);
aimee_georgia_test_assert(
    aimee_colleague_reply_needs_creative_repair(
        $incomplete_four,
        $exact_brief
    ) === true,
    'partial creative list is detected before delivery'
);
aimee_georgia_test_assert(
    aimee_colleague_reply_needs_creative_repair(
        $boundary_refusal,
        $exact_brief
    ) === true,
    'stock relationship boundary cannot replace a colleague work brief'
);
aimee_georgia_test_assert(
    aimee_colleague_reply_needs_creative_repair(
        'A short ordinary answer.',
        array('active' => false, 'requested_count' => 10)
    ) === false,
    'ordinary non-creative replies are outside the repair helper'
);

$fallback_one = aimee_colleague_creative_fallback($exact_brief);
$fallback_two = aimee_colleague_creative_fallback($exact_brief);
$fallback_lower = mb_strtolower((string) $fallback_one);

aimee_georgia_test_assert(
    is_string($fallback_one) && trim($fallback_one) !== '',
    'creative fallback always returns visible prose'
);
aimee_georgia_test_assert(
    $fallback_one === $fallback_two,
    'creative fallback is deterministic for auditability'
);
aimee_georgia_test_assert(
    aimee_georgia_test_numbered_item_count($fallback_one) === 10,
    'creative fallback supplies all ten requested items'
);
aimee_georgia_test_assert(
    strpos($fallback_lower, 'pressure') === false
        && strpos($fallback_lower, 'membership') === false
        && strpos($fallback_lower, 'not sending') === false,
    'creative fallback never invents a relationship or payment refusal'
);

$creative_coercion = aimee_relationship_policy_detect_coercion(
    $exact_request,
    '',
    array(
        'prior_demand_count' => 4,
        'boundary_active' => true,
    )
);
aimee_georgia_test_assert(
    empty($creative_coercion['detected'])
        && empty($creative_coercion['current_demand']),
    'send me post ideas is not a media demand even beside old boundary state'
);

$caption_coercion = aimee_relationship_policy_detect_coercion(
    'Send me ten caption ideas for the summer campaign.',
    '',
    array(
        'prior_demand_count' => 5,
        'boundary_active' => true,
    )
);
aimee_georgia_test_assert(
    empty($caption_coercion['detected'])
        && empty($caption_coercion['current_demand']),
    'send me caption ideas is not a relationship demand'
);

$real_media_pressure = aimee_relationship_policy_detect_coercion(
    'Send me the lingerie photo now.',
    '',
    array(
        'prior_demand_count' => 2,
        'boundary_active' => true,
    )
);
aimee_georgia_test_assert(
    !empty($real_media_pressure['detected'])
        && !empty($real_media_pressure['current_demand']),
    'narrowing preserves coercion handling for a real repeated media demand'
);

echo "\n" . ($failures === 0
    ? 'All Georgia colleague regression checks passed.'
    : "{$failures} Georgia colleague regression check(s) failed.") . "\n";

exit($failures === 0 ? 0 : 1);
