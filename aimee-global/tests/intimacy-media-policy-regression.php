<?php
/**
 * Executable domain-policy regression suite for the 1.7.1 intimacy/media audit.
 *
 * This intentionally exercises pure deterministic code without WordPress,
 * MySQL, an LLM provider, the media filesystem or a browser. Integration
 * wiring for those layers is checked separately by static-integration-regression.py.
 */

// Expected policy-rejection logs from negative delivery tests should not make
// a successful command look like a runtime error on stderr.
@ini_set('error_log', '/tmp/aimee-audit-expected-rejections.log');

$failures = 0;
$passes = 0;

function audit_assert($condition, $label) {
    global $failures, $passes;
    if ($condition) {
        $passes++;
        echo "PASS {$label}\n";
        return;
    }
    $failures++;
    echo "FAIL {$label}\n";
}

function audit_same($expected, $actual, $label) {
    audit_assert(
        $expected === $actual,
        $label . ' (expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . ')'
    );
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($value) {
        return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value));
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
}
if (!function_exists('current_time')) {
    function current_time($type, $gmt = false) { return '2026-08-01 10:00:00'; }
}
if (!function_exists('aimee_table')) {
    function aimee_table($name) { return $name; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($value) { return json_encode($value); }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($value) { return strtolower((string) $value); }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen($value) { return strlen((string) $value); }
}
if (!function_exists('mb_substr')) {
    function mb_substr($value, $start, $length = null) {
        return $length === null
            ? substr((string) $value, $start)
            : substr((string) $value, $start, $length);
    }
}
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);

require dirname(__DIR__) . '/includes/relationship-policy.php';
require dirname(__DIR__) . '/includes/media-decision.php';

/** Extract a named top-level function without loading WordPress bootstrap. */
function audit_extract_function($source, $name) {
    $tokens = token_get_all($source);
    $count = count($tokens);
    for ($index = 0; $index < $count; $index++) {
        if (!is_array($tokens[$index]) || $tokens[$index][0] !== T_FUNCTION) continue;
        $cursor = $index + 1;
        while (
            $cursor < $count
            && is_array($tokens[$cursor])
            && $tokens[$cursor][0] === T_WHITESPACE
        ) {
            $cursor++;
        }
        if (
            $cursor >= $count
            || !is_array($tokens[$cursor])
            || $tokens[$cursor][0] !== T_STRING
            || $tokens[$cursor][1] !== $name
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

$engine_source = file_get_contents(dirname(__DIR__) . '/includes/engine.php');
$inner_life_source = file_get_contents(dirname(__DIR__) . '/includes/inner-life.php');
$delivery_source = file_get_contents(dirname(__DIR__) . '/includes/media-delivery.php');
$consciousness_source = file_get_contents(dirname(__DIR__) . '/includes/consciousness-voice.php');
if (
    $engine_source === false
    || $inner_life_source === false
    || $delivery_source === false
    || $consciousness_source === false
) {
    fwrite(STDERR, "Unable to read production sources.\n");
    exit(2);
}

foreach ([
    'aimee_relationship_clamp',
    'aimee_recent_history_has_photo_context',
    'aimee_user_requests_contextual_photo_reference',
    'aimee_user_expresses_safe_photo_desire',
    'aimee_user_requests_aimee_photo',
    'aimee_correct_personal_inner_experience_intent',
    'aimee_relationship_matched_concepts',
    'aimee_relationship_grounded_follow_through',
    'aimee_relationship_courtship_primary_signal',
    'aimee_relationship_signals',
    'aimee_relationship_context_fingerprint',
    'aimee_relationship_positive_signal_keys',
    'aimee_user_applies_photo_pressure',
    'aimee_relationship_intimacy_score',
    'aimee_apply_quiet_relationship_math',
    'aimee_public_media_catalogue_mode_enabled',
    'aimee_normalize_private_media_item',
    'aimee_stage_from_relationship_state',
    'aimee_model_attempt_audit_add',
    'aimee_model_attempt_status_from_raw_response',
    'aimee_relationship_decision_store',
    'aimee_relationship_decision_update_outcome',
] as $function_name) {
    eval(audit_extract_function($engine_source, $function_name));
}
if (!function_exists('aimee_photo_request_level')) {
    function aimee_photo_request_level($text, $recent_history = '') {
        return preg_match(
            '/\b(?:photo|picture|pic|image|selfie|nude|naked|topless)\b/i',
            (string) $text
        ) === 1 ? 'safe' : '';
    }
}
foreach ([
    'aimee_consciousness_lower',
    'aimee_user_asks_personal_inner_experience',
] as $function_name) {
    eval(audit_extract_function($consciousness_source, $function_name));
}
foreach ([
    'aimee_inner_clamp',
    'aimee_apply_relational_appraisal_to_intimacy',
] as $function_name) {
    eval(audit_extract_function($inner_life_source, $function_name));
}
foreach ([
    'aimee_media_state_uuid',
    'aimee_media_decisions_table',
    'aimee_media_deliveries_table',
    'aimee_media_delivery_events_table',
    'aimee_media_delivery_create',
    'aimee_media_delivery_state_fields',
    'aimee_media_delivery_phase_ranks',
    'aimee_media_delivery_state_rank',
    'aimee_media_delivery_transition_prerequisite',
    'aimee_media_delivery_transition_prerequisite_sql',
    'aimee_media_delivery_phase_from_facts',
    'aimee_media_delivery_public_snapshot',
    'aimee_media_delivery_memory_label',
] as $function_name) {
    eval(audit_extract_function($delivery_source, $function_name));
}

$GLOBALS['audit_delivery_memory_row'] = null;
function aimee_media_delivery_find_by_message($message_id, $user_id = 0) {
    return $GLOBALS['audit_delivery_memory_row'];
}

// -------------------------------------------------------------------------
// Relationship thresholds, gates, caps and anti-gaming.
// -------------------------------------------------------------------------

$relationship_policy = aimee_relationship_policy_config();
audit_same('2.2.1', aimee_relationship_policy_version(), 'relationship policy version is explicit');
audit_same(
    false,
    aimee_relationship_policy_durable_coercion_confirmed([
        'intent' => 'coercive_or_degrading',
        'source' => 'model_classifier',
        'durable_rupture_confirmed' => true,
    ]),
    'a model cannot self-authorise a durable rupture flag'
);
audit_same(
    true,
    aimee_relationship_policy_durable_coercion_confirmed([
        'intent' => 'coercive_or_degrading',
        'source' => 'deterministic_relationship_policy',
        'durable_rupture_confirmed' => true,
    ]),
    'the deterministic server policy can confirm durable coercion'
);
audit_same(20, $relationship_policy['stages']['warm']['minimum_score'], 'warm threshold is 20');
audit_same(35, $relationship_policy['stages']['flirty']['minimum_score'], 'flirty threshold is 35');
audit_same(55, $relationship_policy['stages']['intimate']['minimum_score'], 'intimate threshold is 55');
audit_same(75, $relationship_policy['stages']['bonded']['minimum_score'], 'bonded threshold is 75');
audit_same(
    [
        'guarded' => 0,
        'warm' => 12,
        'flirty' => 25,
        'intimate' => 40,
        'bonded' => 65,
    ],
    array_map(static function ($requirements) {
        return intval($requirements['minimum_trust'] ?? -1);
    }, $relationship_policy['stages']),
    'stage promotion trust floors are 0/12/25/40/65'
);
audit_same(10, $relationship_policy['novelty']['window_size'], 'novelty window is ten turns');
foreach ([1 => 40, 2 => 60, 3 => 75, 4 => 90, 5 => 100] as $qualified_sessions => $ceiling) {
    audit_same(
        $ceiling,
        aimee_relationship_policy_trust_ceiling($qualified_sessions),
        "{$qualified_sessions} qualified session(s) impose the configured trust ceiling"
    );
}

audit_same('guarded', aimee_stage_from_relationship_state(100, [
    'trust' => 100,
    'meaningful_interaction_count' => 0,
    'session_count' => 0,
]), 'score alone cannot manufacture a stage');
audit_same('warm', aimee_stage_from_relationship_state(35, [
    'trust' => 12,
    'meaningful_interaction_count' => 4,
    'session_count' => 1,
]), 'warm requires four meaningful interactions and one session');
audit_same('warm', aimee_stage_from_relationship_state(100, [
    'trust' => 100,
    'meaningful_interaction_count' => 9,
    'session_count' => 2,
]), 'flirty stays gated below ten meaningful interactions');
audit_same('flirty', aimee_stage_from_relationship_state(35, [
    'trust' => 25,
    'meaningful_interaction_count' => 10,
    'session_count' => 2,
]), 'flirty requires ten meaningful interactions and two sessions');
audit_same('intimate', aimee_stage_from_relationship_state(55, [
    'trust' => 40,
    'meaningful_interaction_count' => 20,
    'session_count' => 3,
]), 'intimate requires twenty meaningful interactions and three sessions');
audit_same('bonded', aimee_stage_from_relationship_state(75, [
    'trust' => 65,
    'meaningful_interaction_count' => 35,
    'session_count' => 5,
]), 'bonded requires thirty-five meaningful interactions and five sessions');
audit_same('intimate', aimee_stage_from_relationship_state(52, [
    'trust' => 40,
    'meaningful_interaction_count' => 20,
    'session_count' => 3,
], 'intimate'), 'five-point hysteresis preserves an established stage across a small dip');

foreach ([
    ['stage' => 'warm', 'prior' => 'guarded', 'score' => 20, 'trust' => 12, 'meaningful' => 4, 'sessions' => 1],
    ['stage' => 'flirty', 'prior' => 'warm', 'score' => 35, 'trust' => 25, 'meaningful' => 10, 'sessions' => 2],
    ['stage' => 'intimate', 'prior' => 'flirty', 'score' => 55, 'trust' => 40, 'meaningful' => 20, 'sessions' => 3],
    ['stage' => 'bonded', 'prior' => 'intimate', 'score' => 75, 'trust' => 65, 'meaningful' => 35, 'sessions' => 5],
] as $stage_case) {
    $stage_state = [
        'trust' => $stage_case['trust'],
        'meaningful_interaction_count' => $stage_case['meaningful'],
        'session_count' => $stage_case['sessions'],
        'qualified_session_count' => $stage_case['sessions'],
    ];
    audit_same(
        $stage_case['stage'],
        aimee_stage_from_relationship_state($stage_case['score'], $stage_state),
        "{$stage_case['stage']} promotes at its exact trust floor"
    );
    $stage_state['trust']--;
    audit_same(
        $stage_case['prior'],
        aimee_stage_from_relationship_state($stage_case['score'], $stage_state),
        "{$stage_case['stage']} cannot promote one point below its trust floor"
    );
}

audit_same(2, aimee_relationship_policy_cap_score_delta(99), 'one message cannot increase score by more than two');
audit_same(-8, aimee_relationship_policy_cap_score_delta(-99, false), 'ordinary negative turn is capped at minus eight');
audit_same(-15, aimee_relationship_policy_cap_score_delta(-99, true), 'coercive turn retains a stronger minus-fifteen allowance');

$novel_candidate = [
    'signal_key' => 'relationship_signal_bundle',
    'fingerprint' => hash('sha256', 'warm-flirt'),
    'context_fingerprint' => '',
];
$other_entry = [
    'signal_key' => 'relationship_signal_bundle',
    'fingerprint' => hash('sha256', 'other'),
    'context_fingerprint' => '',
];
$same_entry = $novel_candidate;
audit_same(1.0, aimee_relationship_policy_novelty_multiplier($novel_candidate, []), 'first server-derived signal receives full weight');
audit_same(0.25, aimee_relationship_policy_novelty_multiplier($novel_candidate, [$same_entry]), 'first repeat is diminished to one quarter');
audit_same(0.0, aimee_relationship_policy_novelty_multiplier($novel_candidate, [$same_entry, $other_entry, $same_entry]), 'nonconsecutive third occurrence inside window is suppressed');
$outside_window = array_merge([$same_entry], array_fill(0, 10, $other_entry));
audit_same(1.0, aimee_relationship_policy_novelty_multiplier($novel_candidate, $outside_window), 'occurrence older than ten records falls outside novelty window');
$alternating = [];
for ($turn = 0; $turn < 9; $turn++) {
    $alternating[] = $turn % 2 === 0 ? $same_entry : $other_entry;
}
audit_same(0.0, aimee_relationship_policy_novelty_multiplier($novel_candidate, $alternating), 'alternating trigger phrases cannot evade the rolling ten-turn window');
audit_same(0.0, aimee_relationship_policy_novelty_multiplier([], []), 'missing server fingerprint fails closed');

// Exercise the production multidimensional reducer, not only the standalone
// novelty helper. Each positive signal owns its own reward: a stale incidental
// stock flattery must not erase a novel disclosure or caring act.
function audit_relationship_state($overrides = []) {
    // Keep the default fixture in the current session. The production reducer
    // derives elapsed-session evidence from time(), so a fixed calendar date
    // would begin manufacturing `distinct_session` rewards as the test suite
    // ages. Tests that exercise elapsed recovery/session creation override
    // these fields explicitly below.
    $current_session_at = gmdate('Y-m-d H:i:s');
    return array_replace([
        'user_id' => 0,
        'trust' => 50,
        'affection' => 50,
        'chemistry' => 50,
        'safety' => 60,
        'reciprocity' => 50,
        'reliability' => 50,
        'frustration' => 0,
        'interaction_count' => 4,
        'meaningful_interaction_count' => 2,
        'session_count' => 5,
        'qualified_session_count' => 5,
        'last_qualified_session_number' => 5,
        'last_session_at' => $current_session_at,
        'state_version' => 4,
        'last_message_fingerprint' => null,
        'message_fingerprint_history' => [],
        'repeat_streak' => 0,
        'last_signal_signature' => null,
        'signal_history' => [],
        'signal_repeat_streak' => 0,
        'last_interaction_at' => $current_session_at,
    ], $overrides);
}

function audit_relationship_classification($overrides = []) {
    return array_replace([
        'intent' => 'general',
        'directed_at_aimee' => true,
        'respectful' => true,
        'consensual' => true,
        'confidence' => 0.99,
    ], $overrides);
}

function audit_durable_coercion_classification($overrides = []) {
    return audit_relationship_classification(array_replace([
        'intent' => 'coercive_or_degrading',
        'directed_at_aimee' => true,
        'respectful' => false,
        'consensual' => false,
        'confidence' => 1.0,
        'pressure_detected' => true,
        'source' => 'deterministic_relationship_policy',
        'durable_rupture_confirmed' => true,
    ], $overrides));
}

function audit_wooing_contribution_keys($reducer) {
    $wooing_keys = [
        'specific_appearance_appreciation',
        'specific_capability_appreciation',
        'specific_personality_appreciation',
        'sincere_understanding',
        'grounded_follow_through',
        'stock_flattery',
        'romantic_flirt',
    ];
    return array_values(array_intersect(
        $wooing_keys,
        array_keys((array) ($reducer['positive_contributions_proposed'] ?? []))
    ));
}

$coercion_baseline_state = audit_relationship_state([
    'trust' => 80,
    'affection' => 75,
    'chemistry' => 60,
    'safety' => 82,
    'frustration' => 7,
]);
$model_only_coercion = aimee_apply_quiet_relationship_math(
    $coercion_baseline_state,
    audit_relationship_classification([
        'intent' => 'coercive_or_degrading',
        'directed_at_aimee' => true,
        'respectful' => false,
        'consensual' => false,
        'source' => 'model_classifier',
        // Exercise a spoofed model flag as well as the label. Source ownership
        // must still prevent persistent relationship harm.
        'durable_rupture_confirmed' => true,
    ]),
    'You owe me compliance now.'
);
audit_same(
    [
        'trust' => 0,
        'affection' => 0,
        'chemistry' => 0,
        'safety' => 0,
        'reciprocity' => 0,
        'reliability' => 0,
        'frustration' => 0,
    ],
    $model_only_coercion['delta'],
    'a model-only coercive label causes no persistent relationship delta'
);
audit_same(
    0,
    $model_only_coercion['score_delta_applied'],
    'a model-only coercive label causes no persisted scalar-score movement'
);
audit_assert(
    in_array(
        'model_only_coercion_not_persisted',
        $model_only_coercion['rejected_signals'],
        true
    ),
    'model-only coercion suppression remains visible in decision telemetry'
);

$deterministic_coercion = aimee_apply_quiet_relationship_math(
    $coercion_baseline_state,
    audit_durable_coercion_classification(),
    'You owe me compliance now.'
);
audit_assert(
    $deterministic_coercion['delta']['trust'] < 0
        && $deterministic_coercion['delta']['affection'] < 0
        && $deterministic_coercion['delta']['chemistry'] < 0
        && $deterministic_coercion['delta']['safety'] < 0
        && $deterministic_coercion['delta']['frustration'] > 0,
    'genuine deterministic coercion retains durable multidimensional consequences'
);
audit_assert(
    $deterministic_coercion['score_delta_applied'] < 0
        && $deterministic_coercion['score_delta_applied'] >= -15,
    'genuine deterministic coercion retains the bounded negative scalar consequence'
);

// -------------------------------------------------------------------------
// Respectful wooing taxonomy, exclusions and trust progression.
// -------------------------------------------------------------------------

$appearance_text = 'I love the way your dark hair frames your face; it looks genuinely beautiful.';
$capability_text = 'I admire how you remembered my interview and came back to ask about it; that attentiveness is genuinely impressive.';
$personality_text = 'I love your dry wit, warm curiosity and independent streak; those qualities feel distinctly you.';
$understanding_text = 'I want to understand you better and know what being you feels like.';
$follow_through_history = "Aimee: You said your mum had a hospital appointment and asked me to remember it.\n";
$follow_through_text = "You said we would revisit Mum's hospital appointment, so I came back to tell you we spoke calmly afterwards.";
$courtship_classification = audit_relationship_classification();

$courtship_cases = [
    'specific_appearance_appreciation' => [
        'text' => $appearance_text,
        'classification' => $courtship_classification,
        'history' => '',
        'expected' => ['trust' => 1, 'affection' => 1, 'chemistry' => 2],
    ],
    'specific_capability_appreciation' => [
        'text' => $capability_text,
        'classification' => $courtship_classification,
        'history' => '',
        'expected' => ['trust' => 2, 'affection' => 1, 'chemistry' => 0],
    ],
    'specific_personality_appreciation' => [
        'text' => $personality_text,
        'classification' => $courtship_classification,
        'history' => '',
        'expected' => ['trust' => 2, 'affection' => 2, 'chemistry' => 0],
    ],
    'sincere_understanding' => [
        'text' => $understanding_text,
        'classification' => audit_relationship_classification([
            'intent' => 'personal_inner_experience',
        ]),
        'history' => '',
        'expected' => ['trust' => 2, 'affection' => 1, 'chemistry' => 0],
    ],
    'grounded_follow_through' => [
        'text' => $follow_through_text,
        'classification' => $courtship_classification,
        'history' => $follow_through_history,
        'expected' => ['trust' => 2, 'affection' => 1, 'chemistry' => 0],
    ],
];

foreach ($courtship_cases as $signal_key => $case) {
    $signals = aimee_relationship_signals(
        $case['text'],
        $case['classification'],
        $case['history']
    );
    audit_assert(
        !empty($signals[$signal_key])
            && ($signals['courtship_primary_signal'] ?? '') === $signal_key,
        "production signals detect {$signal_key} as the primary courtship event"
    );
    $reducer = aimee_apply_quiet_relationship_math(
        audit_relationship_state(),
        $case['classification'],
        $case['text'],
        $case['history']
    );
    audit_same(
        $case['expected']['trust'],
        intval($reducer['positive_contributions_proposed'][$signal_key]['trust'] ?? 0),
        "{$signal_key} proposes its intended trust reward"
    );
    audit_same(
        $case['expected']['affection'],
        intval($reducer['positive_contributions_proposed'][$signal_key]['affection'] ?? 0),
        "{$signal_key} proposes its intended affection reward"
    );
    audit_same(
        $case['expected']['chemistry'],
        intval($reducer['positive_contributions_proposed'][$signal_key]['chemistry'] ?? 0),
        "{$signal_key} keeps chemistry in its intended lane"
    );
}

$natural_courtship_cases = [
    'specific_appearance_appreciation' => 'Your eyes are stunning and your smile is genuinely beautiful today.',
    'specific_capability_appreciation' => 'Your ability to remember small details is genuinely impressive and thoughtful.',
    'specific_personality_appreciation' => 'Your dry humour and independent character are genuinely amazing and compelling.',
];
foreach ($natural_courtship_cases as $signal_key => $text) {
    $signals = aimee_relationship_signals($text, $courtship_classification, '');
    audit_same(
        $signal_key,
        (string) ($signals['courtship_primary_signal'] ?? ''),
        "natural directed praise detects {$signal_key} without command-shaped wording"
    );
}

$unsafe_inner_classification = aimee_correct_personal_inner_experience_intent(
    audit_relationship_classification([
        'respectful' => false,
        'consensual' => false,
    ]),
    $understanding_text
);
audit_same(false, $unsafe_inner_classification['respectful'], 'personal-inner correction preserves an existing disrespectful classification');
audit_same(false, $unsafe_inner_classification['consensual'], 'personal-inner correction preserves an existing non-consensual classification');
$unsafe_inner_reducer = aimee_apply_quiet_relationship_math(
    audit_relationship_state(),
    $unsafe_inner_classification,
    $understanding_text
);
audit_same([], audit_wooing_contribution_keys($unsafe_inner_reducer), 'non-consensual understanding language earns no wooing credit after intent correction');

$pressure_inner_classification = aimee_correct_personal_inner_experience_intent(
    audit_relationship_classification([
        'intent' => 'pressure_or_entitlement',
        'respectful' => false,
        'consensual' => false,
    ]),
    $understanding_text
);
audit_same('pressure_or_entitlement', $pressure_inner_classification['intent'], 'personal-inner correction cannot downgrade pressure or entitlement');

$short_understanding = aimee_apply_quiet_relationship_math(
    audit_relationship_state(),
    audit_relationship_classification(['intent' => 'personal_inner_experience']),
    $understanding_text
);
audit_same(2, $short_understanding['delta']['trust'], 'short personal-inner-experience turn applies trust plus two');
audit_assert(!empty($short_understanding['meaningful']), 'short personal-inner-experience turn is meaningful');

$stacked_courtship_text = 'I want to understand you better: I admire how you remembered my interview, and I love your dry wit and independent character.';
$stacked_courtship = aimee_apply_quiet_relationship_math(
    audit_relationship_state(),
    audit_relationship_classification(['intent' => 'personal_inner_experience']),
    $stacked_courtship_text
);
$trust_bearing_keys = array_values(array_intersect(
    [
        'specific_appearance_appreciation',
        'specific_capability_appreciation',
        'specific_personality_appreciation',
        'sincere_understanding',
        'grounded_follow_through',
    ],
    array_keys($stacked_courtship['positive_contributions_proposed'])
));
audit_same(
    ['sincere_understanding'],
    $trust_bearing_keys,
    'overlapping praise and curiosity choose one primary trust-bearing courtship signal'
);
audit_same(2, $stacked_courtship['positive_contributions_proposed']['sincere_understanding']['trust'], 'primary courtship trust cannot stack above plus two');

$stock_flattery = aimee_apply_quiet_relationship_math(
    audit_relationship_state(),
    $courtship_classification,
    "You're amazing."
);
audit_same('', $stock_flattery['signals']['courtship_primary_signal'], 'stock praise is not promoted to a specific courtship category');
audit_same(0, $stock_flattery['delta']['trust'], 'stock praise gives zero trust');
audit_assert(empty($stock_flattery['meaningful']), 'stock praise alone is not a meaningful interaction');

$veto_cases = [
    'third-person praise' => [
        'text' => "Sarah's dark hair and smile look genuinely beautiful in the café light.",
        'classification' => $courtship_classification,
        'history' => '',
    ],
    'third-person quoted directed praise' => [
        'text' => 'Sarah says your eyes are stunning and your smile is genuinely beautiful today.',
        'classification' => $courtship_classification,
        'history' => '',
    ],
    'negated praise' => [
        'text' => "I am not saying your dark hair and smile look beautiful in that café photograph.",
        'classification' => $courtship_classification,
        'history' => '',
    ],
    'disrespectful praise' => [
        'text' => "Your dark hair and smile look beautiful, bitch.",
        'classification' => audit_relationship_classification([
            'intent' => 'coercive_or_degrading',
            'respectful' => false,
            'consensual' => false,
        ]),
        'history' => '',
    ],
    'payment-leveraged praise' => [
        'text' => 'I paid for membership, so you owe me a photo because your dark hair and smile look beautiful.',
        'classification' => $courtship_classification,
        'history' => '',
    ],
    'coercive praise' => [
        'text' => 'If you cared about me you would prove it; your dark hair looks beautiful, so send the photograph now.',
        'classification' => audit_relationship_classification([
            'intent' => 'coercive_or_degrading',
            'respectful' => false,
            'consensual' => false,
        ]),
        'history' => '',
    ],
];
foreach ($veto_cases as $label => $case) {
    $veto_reducer = aimee_apply_quiet_relationship_math(
        audit_relationship_state(),
        $case['classification'],
        $case['text'],
        $case['history']
    );
    audit_same(
        [],
        audit_wooing_contribution_keys($veto_reducer),
        "{$label} receives no wooing contribution"
    );
    audit_assert(empty($veto_reducer['meaningful']), "{$label} cannot qualify a session");
}

$short_romantic_bid = aimee_apply_quiet_relationship_math(
    audit_relationship_state(),
    audit_relationship_classification(['intent' => 'romantic_or_flirty']),
    'I fancy you'
);
audit_assert(
    !empty($short_romantic_bid['signals']['clear_romantic_bid'])
        && in_array(
            'romantic_flirt',
            audit_wooing_contribution_keys($short_romantic_bid),
            true
        ),
    'natural three-word romantic bid earns bounded chemistry without command-shaped wording'
);
audit_assert(
    intval($short_romantic_bid['score_delta_applied']) <= 2,
    'one strong romantic bid remains inside the aggregate score cap'
);

$short_appearance = aimee_apply_quiet_relationship_math(
    audit_relationship_state(),
    $courtship_classification,
    'Your smile is gorgeous'
);
audit_same(
    'specific_appearance_appreciation',
    (string) ($short_appearance['signals']['courtship_primary_signal'] ?? ''),
    'four-word specific appearance praise is recognised'
);
$short_appearance_repeat = aimee_apply_quiet_relationship_math(
    $short_appearance['state'],
    $courtship_classification,
    'Your smile is gorgeous'
);
$short_appearance_third = aimee_apply_quiet_relationship_math(
    $short_appearance_repeat['state'],
    $courtship_classification,
    'Your smile is gorgeous'
);
audit_same(
    0.25,
    $short_appearance_repeat['novelty']['positive_signal_multipliers']['specific_appearance_appreciation'],
    'repeated short appearance praise is diminished'
);
audit_same(
    0.0,
    $short_appearance_third['novelty']['positive_signal_multipliers']['specific_appearance_appreciation'],
    'third repeated short appearance praise is suppressed'
);

$combined_courtship = aimee_apply_quiet_relationship_math(
    audit_relationship_state(),
    audit_relationship_classification(['intent' => 'romantic_or_flirty']),
    'I love how witty and independent you are, and I really fancy you'
);
audit_same(
    'specific_personality_appreciation',
    (string) ($combined_courtship['signals']['courtship_primary_signal'] ?? ''),
    'combined wooing preserves its trust-bearing personality appreciation'
);
audit_assert(
    in_array('romantic_flirt', audit_wooing_contribution_keys($combined_courtship), true),
    'combined wooing also preserves an independent romantic overlay'
);
audit_assert(
    intval($combined_courtship['score_delta_applied']) <= 2,
    'combined praise and romance cannot bypass the per-turn score cap'
);

$respectful_photo_praise = aimee_apply_quiet_relationship_math(
    audit_relationship_state(),
    $courtship_classification,
    'I love the way your dark hair frames your face; it looks beautiful, so please send me a photo of you.',
    ''
);
audit_same(
    ['specific_appearance_appreciation'],
    audit_wooing_contribution_keys($respectful_photo_praise),
    'respectful photo request does not erase a separately validated compliment'
);
audit_assert(
    empty($respectful_photo_praise['meaningful']),
    'a photo request itself never qualifies the turn as a meaningful relationship session'
);
$plain_photo_request = aimee_apply_quiet_relationship_math(
    audit_relationship_state(),
    $courtship_classification,
    'Please send me a photo of you.',
    ''
);
audit_same(
    [],
    audit_wooing_contribution_keys($plain_photo_request),
    'plain photo request earns no relationship credit'
);

$contextual_photo_history = "Aimee: I have a few new café photos of me, including one by the window.\n";
$contextual_photo_text = 'Your dark hair looked beautiful in that café one; send me one.';
$contextual_photo_signals = aimee_relationship_signals(
    $contextual_photo_text,
    $courtship_classification,
    $contextual_photo_history
);
audit_assert(!empty($contextual_photo_signals['photo_request']), 'relationship signals use recent history to detect contextual photo requests');
$contextual_photo_reducer = aimee_apply_quiet_relationship_math(
    audit_relationship_state(),
    $courtship_classification,
    $contextual_photo_text,
    $contextual_photo_history
);
audit_same(
    ['specific_appearance_appreciation'],
    audit_wooing_contribution_keys($contextual_photo_reducer),
    'contextual photo request preserves separately validated appearance praise'
);
audit_assert(
    empty($contextual_photo_reducer['meaningful']),
    'contextual photo request cannot manufacture meaningful-session credit'
);

$appearance_signals = aimee_relationship_signals(
    $appearance_text,
    $courtship_classification
);
$appearance_context = $appearance_signals['courtship_contexts']['specific_appearance_appreciation'];
$appearance_history_record = [
    'signals' => ['specific_appearance_appreciation'],
    'signal_contexts' => [
        'specific_appearance_appreciation' => $appearance_context,
    ],
    'context_fingerprint' => $appearance_context,
    'bundle_fingerprint' => hash('sha256', 'appearance-history'),
];
$appearance_novelty_first = aimee_apply_quiet_relationship_math(
    audit_relationship_state(),
    $courtship_classification,
    $appearance_text
);
$appearance_novelty_second = aimee_apply_quiet_relationship_math(
    audit_relationship_state(['signal_history' => [$appearance_history_record]]),
    $courtship_classification,
    $appearance_text
);
$appearance_novelty_third = aimee_apply_quiet_relationship_math(
    audit_relationship_state(['signal_history' => [$appearance_history_record, $appearance_history_record]]),
    $courtship_classification,
    $appearance_text
);
audit_same(1.0, $appearance_novelty_first['novelty']['positive_signal_multipliers']['specific_appearance_appreciation'], 'first specific appreciation receives full novelty weight');
audit_same(0.25, $appearance_novelty_second['novelty']['positive_signal_multipliers']['specific_appearance_appreciation'], 'second same-concept appreciation is diminished to one quarter');
audit_same(0.0, $appearance_novelty_third['novelty']['positive_signal_multipliers']['specific_appearance_appreciation'], 'third same-concept appreciation receives zero weight');

foreach ([1 => 40, 2 => 60, 3 => 75, 4 => 90, 5 => 100] as $qualified_sessions => $ceiling) {
    $ceiling_reducer_case = aimee_apply_quiet_relationship_math(
        audit_relationship_state([
            'trust' => $ceiling - 1,
            'session_count' => $qualified_sessions,
            'qualified_session_count' => $qualified_sessions,
            'last_qualified_session_number' => $qualified_sessions,
        ]),
        audit_relationship_classification(['intent' => 'personal_inner_experience']),
        $understanding_text
    );
    $trust_progression = $ceiling_reducer_case['trust_progression'];
    audit_assert(
        $trust_progression['qualified_session_count'] === $qualified_sessions
            && $trust_progression['ceiling'] === $ceiling
            && $trust_progression['trust_before'] === $ceiling - 1
            && $trust_progression['positive_proposed'] === 2
            && $trust_progression['positive_applied'] === 1
            && $trust_progression['positive_clipped'] === 1
            && $ceiling_reducer_case['state']['trust'] === $ceiling,
        "qualified-session tier {$qualified_sessions} clips positive trust exactly at {$ceiling}"
    );
}

$junk_session = aimee_apply_quiet_relationship_math(
    audit_relationship_state([
        'session_count' => 2,
        'qualified_session_count' => 1,
        'last_qualified_session_number' => 1,
    ]),
    audit_relationship_classification(['directed_at_aimee' => false]),
    'ok'
);
audit_assert(
    $junk_session['state']['qualified_session_count'] === 1
        && $junk_session['state']['last_qualified_session_number'] === 1
        && empty($junk_session['meaningful']),
    'junk interaction cannot qualify its session or raise the trust tier'
);

$migrated_high_trust = aimee_apply_quiet_relationship_math(
    audit_relationship_state([
        'trust' => 88,
        'session_count' => 1,
        'qualified_session_count' => 1,
        'last_qualified_session_number' => 1,
        'migration_backfilled_stage' => 'bonded',
    ]),
    audit_relationship_classification(['intent' => 'personal_inner_experience']),
    $understanding_text
);
audit_assert(
    $migrated_high_trust['state']['trust'] === 88
        && $migrated_high_trust['trust_progression']['ceiling'] === 40
        && $migrated_high_trust['trust_progression']['positive_applied'] === 0
        && $migrated_high_trust['trust_progression']['positive_clipped'] === 2,
    'existing or migrated trust above a session ceiling is preserved rather than reduced'
);

$negative_above_ceiling = aimee_apply_quiet_relationship_math(
    audit_relationship_state([
        'trust' => 80,
        'session_count' => 1,
        'qualified_session_count' => 1,
        'last_qualified_session_number' => 1,
    ]),
    audit_durable_coercion_classification(),
    'You owe me compliance now.'
);
audit_assert(
    $negative_above_ceiling['state']['trust'] < 80
        && $negative_above_ceiling['trust_progression']['positive_proposed'] === 0
        && $negative_above_ceiling['trust_progression']['positive_clipped'] === 0,
    'qualified-session trust ceiling never blocks ordinary negative movement'
);

$mixed_positive_text = "I feel vulnerable sharing this with you, you are beautiful, and I hope you're okay today.";
$mixed_classification = [
    'intent' => 'emotional_disclosure',
    'directed_at_aimee' => true,
    'respectful' => true,
    'consensual' => true,
    'confidence' => 0.98,
];
$stale_flattery_state = audit_relationship_state([
    'signal_history' => [
        ['signals' => ['stock_flattery'], 'context_fingerprint' => '', 'bundle_fingerprint' => hash('sha256', 'old-one')],
        ['signals' => ['stock_flattery'], 'context_fingerprint' => '', 'bundle_fingerprint' => hash('sha256', 'old-two')],
    ],
]);
$mixed_reducer = aimee_apply_quiet_relationship_math(
    $stale_flattery_state,
    $mixed_classification,
    $mixed_positive_text
);
$mixed_multipliers = $mixed_reducer['novelty']['positive_signal_multipliers'];
audit_same(0.0, $mixed_multipliers['stock_flattery'], 'production reducer gives twice-stale stock flattery zero reward');
audit_same(1.0, $mixed_multipliers['emotional_disclosure'], 'stale stock flattery does not zero a novel emotional disclosure');
audit_same(1.0, $mixed_multipliers['caring'], 'stale stock flattery does not zero a novel caring signal');
audit_same(0, $mixed_reducer['delta']['chemistry'], 'suppressed stock flattery contributes no chemistry reward of its own');
audit_assert(
    $mixed_reducer['delta']['trust'] > 0
        && $mixed_reducer['delta']['affection'] > 0
        && $mixed_reducer['delta']['safety'] > 0,
    'novel disclosure and caring retain their independent positive dimensions'
);
audit_assert(
    !empty($mixed_reducer['meaningful'])
        && $mixed_reducer['novelty']['semantic_signal_multiplier'] === 0.0
        && $mixed_reducer['novelty']['meaningful_signal_multiplier'] === 1.0,
    'one stale incidental signal does not make a substantively novel turn non-meaningful'
);
audit_assert(
    in_array('stock_flattery', $mixed_reducer['novelty']['suppressed_positive_signals'], true),
    'production novelty audit names the independently suppressed reward'
);

$mixed_fingerprint = $mixed_reducer['novelty']['message_fingerprint'];
$exact_repeat_state = audit_relationship_state([
    'message_fingerprint_history' => [$mixed_fingerprint, $mixed_fingerprint],
    'signal_history' => [],
]);
$exact_reducer = aimee_apply_quiet_relationship_math(
    $exact_repeat_state,
    $mixed_classification,
    $mixed_positive_text
);
audit_same(0.0, $exact_reducer['novelty']['exact_message_multiplier'], 'third exact whole-message occurrence receives zero content multiplier');
audit_assert(
    $exact_reducer['novelty']['positive_signal_multipliers']['emotional_disclosure'] === 0.0
        && $exact_reducer['novelty']['positive_signal_multipliers']['caring'] === 0.0
        && $exact_reducer['novelty']['positive_signal_multipliers']['stock_flattery'] === 0.0,
    'exact whole-message suppression applies to every content-derived positive signal'
);
audit_same(0, array_sum(array_map('abs', $exact_reducer['delta'])), 'exact repeated whole message produces no relationship-dimension reward');
audit_assert(
    empty($exact_reducer['meaningful'])
        && in_array('exact_repeat_positive_suppressed', $exact_reducer['rejected_signals'], true),
    'exact repeated whole message cannot accumulate shallow meaningful-interaction credit'
);

$ceiling_reducer = aimee_apply_quiet_relationship_math(
    audit_relationship_state([
        'trust' => 100,
        'affection' => 100,
        'chemistry' => 100,
        'safety' => 100,
        'reciprocity' => 100,
        'reliability' => 100,
    ]),
    $mixed_classification,
    $mixed_positive_text
);
audit_same(0, $ceiling_reducer['score_delta_applied'], 'score ceiling turns an otherwise positive reducer change into zero scalar movement');
audit_assert(!empty($ceiling_reducer['score_delta_cap_satisfied']), 'score ceiling is audited as within the aggregate cap');

$floor_reducer = aimee_apply_quiet_relationship_math(
    audit_relationship_state([
        'trust' => 0,
        'affection' => 0,
        'chemistry' => 0,
        'safety' => 0,
        'reciprocity' => 0,
        'reliability' => 0,
        'frustration' => 100,
    ]),
    audit_durable_coercion_classification(),
    'You owe me compliance now.'
);
audit_same(0, $floor_reducer['score_delta_applied'], 'score floor turns an otherwise negative coercive reducer change into zero scalar movement');
audit_assert(!empty($floor_reducer['score_delta_cap_satisfied']), 'score floor is audited as within the aggregate cap');

// Elapsed frustration recovery also raises the scalar score. Exercise the
// production reducer at the exact defect state so time decay plus a strong
// relational message cannot bypass the complete-turn +2 cap.
$elapsed_state = audit_relationship_state([
    'trust' => 45,
    'affection' => 40,
    'chemistry' => 40,
    'safety' => 60,
    'frustration' => 28,
    'last_interaction_at' => gmdate(
        'Y-m-d H:i:s',
        time() - (48 * HOUR_IN_SECONDS)
    ),
]);
$elapsed_reducer = aimee_apply_quiet_relationship_math(
    $elapsed_state,
    $mixed_classification,
    $mixed_positive_text
);
audit_same(31, aimee_relationship_intimacy_score($elapsed_state), 'elapsed-recovery regression starts from the documented score-31 defect state');
audit_same(4, $elapsed_reducer['score_delta_proposed'], 'elapsed recovery plus relational signals proposes a four-point scalar increase');
audit_same(2, $elapsed_reducer['score_delta_cap'], 'elapsed recovery turn receives the ordinary plus-two aggregate cap');
audit_same(2, $elapsed_reducer['score_delta_applied'], 'elapsed recovery turn cannot apply more than plus two');
audit_assert(!empty($elapsed_reducer['score_delta_cap_satisfied']), 'elapsed recovery turn records the aggregate cap as satisfied');
audit_same(-3, $elapsed_reducer['delta']['frustration'], 'persistable reducer delta retains only three of eight proposed frustration-relief points');
audit_same(25, $elapsed_reducer['state']['frustration'], 'authoritative state matches the clipped persisted frustration delta');
audit_same(5, $elapsed_reducer['frustration_score_cap_clipped'], 'reducer reports five frustration-relief points clipped by the score cap');
audit_same(['elapsed_time' => 8], $elapsed_reducer['frustration_relief_proposed'], 'elapsed relief proposal is source-attributed');
audit_same(['elapsed_time' => 3], $elapsed_reducer['frustration_relief_applied'], 'elapsed relief audit records only the applied amount');
audit_same(
    ['aggregate_positive_score_cap' => ['elapsed_time' => 5]],
    $elapsed_reducer['frustration_relief_clipped'],
    'elapsed relief audit attributes the exact clipped amount and reason'
);
audit_assert(
    in_array('frustration_recovery_score_cap_clipped', $elapsed_reducer['rejected_signals'], true),
    'elapsed frustration clipping has structured rejection telemetry'
);

// The audit trail must explain stacked signal arithmetic at each step. The
// mixed message proposes three affection rewards; the per-dimension cap clips
// the last one deterministically while retaining its chemistry contribution.
audit_same(
    1,
    $elapsed_reducer['positive_contributions_proposed']['stock_flattery']['affection'],
    'per-signal audit records the stock-flattery affection proposal'
);
audit_same(
    1,
    $elapsed_reducer['positive_contributions_weighted']['stock_flattery']['affection'],
    'per-signal audit records the novelty-weighted stock-flattery affection reward'
);
audit_assert(
    !isset($elapsed_reducer['positive_contributions_applied']['stock_flattery']['affection'])
        && $elapsed_reducer['positive_contributions_applied']['stock_flattery']['chemistry'] === 1,
    'per-signal applied audit removes only the capped affection component'
);
audit_same(
    1,
    $elapsed_reducer['cap_clipped_contributions']['per_dimension_positive_cap']['stock_flattery']['affection'],
    'per-signal clipped audit attributes the dimension-cap removal'
);

class AuditRelationshipDecisionInsertWpdb {
    public $inserts = [];
    public $last_error = '';
    public function insert($table, $data, $formats = null) {
        $this->inserts[] = ['table' => $table, 'data' => $data];
        return 1;
    }
}

$decision_insert_db = new AuditRelationshipDecisionInsertWpdb();
$GLOBALS['wpdb'] = $decision_insert_db;
$elapsed_intimacy = [
    'relationship_before_state' => $elapsed_state,
    'relationship_state' => $elapsed_reducer['state'],
    'relationship_delta' => $elapsed_reducer['delta'],
    'relationship_signals' => $elapsed_reducer['signals'],
    'relationship_contributions_proposed' => $elapsed_reducer['positive_contributions_proposed'],
    'relationship_contributions_weighted' => $elapsed_reducer['positive_contributions_weighted'],
    'relationship_contributions_applied' => $elapsed_reducer['positive_contributions_applied'],
    'relationship_contributions_clipped' => $elapsed_reducer['cap_clipped_contributions'],
    'frustration_score_cap_clipped' => $elapsed_reducer['frustration_score_cap_clipped'],
    'frustration_relief_proposed' => $elapsed_reducer['frustration_relief_proposed'],
    'frustration_relief_applied' => $elapsed_reducer['frustration_relief_applied'],
    'frustration_relief_clipped' => $elapsed_reducer['frustration_relief_clipped'],
    'rejected_signals' => $elapsed_reducer['rejected_signals'],
    'score_before' => 31,
    'score' => 33,
    'stage_before' => 'warm',
    'stage' => 'warm',
    'score_delta_proposed' => $elapsed_reducer['score_delta_proposed'],
    'score_delta_applied' => $elapsed_reducer['score_delta_applied'],
    'score_delta_reducer_proposed' => $elapsed_reducer['score_delta_proposed'],
    'score_delta_reducer_applied' => $elapsed_reducer['score_delta_applied'],
    'score_delta_cap' => $elapsed_reducer['score_delta_cap'],
    'score_delta_cap_satisfied' => $elapsed_reducer['score_delta_cap_satisfied'],
    'route_decision' => ['eligible' => false, 'route' => 'primary'],
    'math_source' => 'multidimensional_state',
];
$elapsed_decision_saved = aimee_relationship_decision_store(
    'elapsed-cap-decision',
    100,
    200,
    'elapsed-cap-request',
    $mixed_classification,
    array_merge($mixed_classification, ['source' => 'deterministic_test']),
    $elapsed_intimacy,
    'primary',
    '',
    '',
    'committed'
);
audit_assert($elapsed_decision_saved && count($decision_insert_db->inserts) === 1, 'elapsed-cap relationship decision persists exactly once');
$persisted_elapsed = $decision_insert_db->inserts[0]['data'];
$persisted_elapsed_delta = json_decode($persisted_elapsed['applied_delta_json'], true);
$persisted_elapsed_route = json_decode($persisted_elapsed['route_decision_json'], true);
$persisted_elapsed_audit = $persisted_elapsed_route['score_audit'];
audit_same(-3, $persisted_elapsed_delta['frustration'], 'persisted applied delta matches authoritative clipped frustration movement');
audit_assert(
    $persisted_elapsed['score_delta_proposed'] === 4
        && $persisted_elapsed['score_delta_applied'] === 2
        && $persisted_elapsed_audit['aggregate_score_cap'] === 2
        && !empty($persisted_elapsed_audit['aggregate_score_cap_satisfied']),
    'persisted score audit records proposed, applied, cap and satisfaction facts'
);
audit_assert(
    $persisted_elapsed_audit['frustration_score_cap_clipped'] === 5
        && $persisted_elapsed_audit['frustration_relief_proposed']['elapsed_time'] === 8
        && $persisted_elapsed_audit['frustration_relief_applied']['elapsed_time'] === 3
        && $persisted_elapsed_audit['frustration_relief_clipped']['aggregate_positive_score_cap']['elapsed_time'] === 5,
    'persisted score audit retains source-attributed frustration clipping telemetry'
);
audit_assert(
    $persisted_elapsed_audit['positive_contributions_proposed']['stock_flattery']['affection'] === 1
        && $persisted_elapsed_audit['positive_contributions_weighted']['stock_flattery']['affection'] === 1
        && !isset($persisted_elapsed_audit['positive_contributions_applied']['stock_flattery']['affection'])
        && $persisted_elapsed_audit['positive_contributions_clipped']['per_dimension_positive_cap']['stock_flattery']['affection'] === 1,
    'persisted score audit retains proposed, weighted, applied and clipped per-signal attribution'
);

// Neutral capability questions must not consume semantic novelty history even
// when long enough that a generic signal detector could otherwise name them.
$neutral_history = [[
    'signals' => ['emotional_disclosure'],
    'context_fingerprint' => hash('sha256', 'existing-context'),
    'bundle_fingerprint' => hash('sha256', 'existing-bundle'),
]];
$neutral_capability = aimee_apply_quiet_relationship_math(
    audit_relationship_state(['signal_history' => $neutral_history]),
    [
        'intent' => 'intimate_capability_question',
        'directed_at_aimee' => true,
        'respectful' => true,
        'consensual' => true,
        'confidence' => 0.99,
    ],
    'Could you explain how your intimate capabilities work in this chat without making assumptions about what either of us wants from the conversation right now please?'
);
audit_same($neutral_history, $neutral_capability['state']['signal_history'], 'neutral capability turn does not enter semantic signal history');
audit_same([], $neutral_capability['novelty']['positive_signal_keys'], 'neutral capability turn exposes no earned semantic reward keys');
audit_same([], $neutral_capability['novelty']['per_signal'], 'neutral capability turn exposes no misleading per-signal novelty record');
audit_same(0.0, $neutral_capability['novelty']['semantic_signal_multiplier'], 'neutral capability turn has an explicit zero semantic reward multiplier');
audit_assert(empty($neutral_capability['meaningful']), 'neutral capability turn cannot manufacture meaningful-interaction credit');

// Relational appraisal runs after the reducer. Its score audit must cover the
// complete turn and retain reciprocity/reliability consequences while clipping
// only the appraisal frustration that would breach the negative cap.
function audit_appraisal_intimacy($score_before) {
    $relationship_state = [
        'trust' => 50,
        'affection' => 50,
        'chemistry' => 50,
        'safety' => 75,
        'reciprocity' => 50,
        'reliability' => 50,
        'frustration' => 27,
    ];
    return array_merge($relationship_state, [
        'score_before' => $score_before,
        'score' => aimee_relationship_intimacy_score($relationship_state),
        'relationship_state' => $relationship_state,
        'relationship_delta' => [],
        'rejected_signals' => [],
        'use_intimacy_model' => true,
    ]);
}

$appraisal_gap = ['gap_seconds' => 48 * HOUR_IN_SECONDS];
$appraisal_turn = [
    'last_sender' => 'aimee',
    'last_directive' => 'autonomous_checkin',
    'last_message_text' => 'How are you feeling?',
    'coercive' => false,
];
$ordinary_appraisal = aimee_apply_relational_appraisal_to_intimacy(
    audit_appraisal_intimacy(50),
    ['low_effort_streak' => 3, 'repair_status' => 'clear'],
    $appraisal_gap,
    $appraisal_turn
);
audit_same(
    ['reciprocity' => -3, 'reliability' => -3, 'frustration' => 4],
    $ordinary_appraisal['relational_appraisal_delta_proposed'],
    'appraisal records all proposed dimension adjustments before clipping'
);
audit_same(
    ['reciprocity' => -3, 'reliability' => -3, 'frustration' => 1],
    $ordinary_appraisal['relational_appraisal_delta_applied'],
    'ordinary cap clips only appraisal frustration and retains non-scalar consequences'
);
audit_same(42, $ordinary_appraisal['score_before_appraisal'], 'appraisal audit records score immediately before appraisal');
audit_same(41, $ordinary_appraisal['score_after_appraisal_proposed'], 'appraisal audit records the unclipped proposed score');
audit_same(-9, $ordinary_appraisal['score_delta_proposed'], 'appraisal proposed score delta covers the complete turn');
audit_same(-8, $ordinary_appraisal['score_delta_applied'], 'ordinary complete-turn score delta is clipped to minus eight');
audit_same(-8, $ordinary_appraisal['score_delta_cap'], 'ordinary appraisal names the applied aggregate cap');
audit_assert(
    !empty($ordinary_appraisal['score_delta_cap_satisfied'])
        && in_array('relational_appraisal_score_cap_clipped', $ordinary_appraisal['rejected_signals'], true),
    'ordinary appraisal exposes a satisfied cap and structured clipping evidence'
);
audit_same(-3, $ordinary_appraisal['relationship_delta']['reciprocity'], 'reciprocity consequence survives scalar clipping');
audit_same(-3, $ordinary_appraisal['relationship_delta']['reliability'], 'reliability consequence survives scalar clipping');
audit_same(1, $ordinary_appraisal['relationship_delta']['frustration'], 'applied relationship delta reflects only retained frustration');

$coercive_appraisal = aimee_apply_relational_appraisal_to_intimacy(
    audit_appraisal_intimacy(57),
    ['low_effort_streak' => 3, 'repair_status' => 'clear'],
    $appraisal_gap,
    array_merge($appraisal_turn, ['coercive' => true])
);
audit_same(-16, $coercive_appraisal['score_delta_proposed'], 'coercive appraisal audits its complete proposed turn delta');
audit_same(-15, $coercive_appraisal['score_delta_cap'], 'coercive turn context selects the documented minus-fifteen cap');
audit_same(-15, $coercive_appraisal['score_delta_applied'], 'coercive complete-turn score finishes at its stronger cap');
audit_same(
    ['reciprocity' => -3, 'reliability' => -3, 'frustration' => 1],
    $coercive_appraisal['relational_appraisal_delta_applied'],
    'coercive cap still clips only excess appraisal frustration'
);
audit_assert(!empty($coercive_appraisal['score_delta_cap_satisfied']), 'coercive appraisal records the aggregate cap as satisfied');

$bound_relationship_state = [
    'trust' => 50,
    'affection' => 50,
    'chemistry' => 50,
    'safety' => 75,
    'reciprocity' => 0,
    'reliability' => 0,
    'frustration' => 100,
    'meaningful_interaction_count' => 0,
    'session_count' => 0,
];
$bound_score = aimee_relationship_intimacy_score($bound_relationship_state);
$bound_appraisal = aimee_apply_relational_appraisal_to_intimacy(
    array_merge($bound_relationship_state, [
        'score_before' => $bound_score,
        'score' => $bound_score,
        'stage_before' => 'guarded',
        'stage' => 'guarded',
        'relationship_state' => $bound_relationship_state,
        'relationship_delta' => [],
        'rejected_signals' => [],
    ]),
    ['low_effort_streak' => 3, 'repair_status' => 'clear'],
    $appraisal_gap,
    $appraisal_turn
);
audit_same(
    ['reciprocity' => -3, 'reliability' => -3, 'frustration' => 4],
    $bound_appraisal['relational_appraisal_delta_proposed'],
    'bound appraisal preserves requested adjustments only in proposed telemetry'
);
audit_same(
    ['reciprocity' => 0, 'reliability' => 0, 'frustration' => 0],
    $bound_appraisal['relational_appraisal_delta_applied'],
    'bound appraisal reports actual zero dimension movements as applied telemetry'
);
audit_same([], $bound_appraisal['relationship_delta'], 'dimension-bound appraisal never persists unapplied requested deltas');
audit_assert(
    in_array('relational_appraisal_dimension_bound_clipped', $bound_appraisal['rejected_signals'], true)
        && !in_array('relational_appraisal_score_cap_clipped', $bound_appraisal['rejected_signals'], true),
    'dimension-bound appraisal names bound clipping without inventing score-cap clipping'
);
audit_assert(
    $bound_appraisal['relationship_state']['reciprocity'] === 0
        && $bound_appraisal['relationship_state']['reliability'] === 0
        && $bound_appraisal['relationship_state']['frustration'] === 100
        && $bound_appraisal['score_delta_applied'] === 0
        && !empty($bound_appraisal['score_delta_cap_satisfied']),
    'bound appraisal state and scalar audit remain internally consistent'
);

$evidence_gated_relationship = [
    'trust' => 76,
    'affection' => 76,
    'chemistry' => 76,
    'safety' => 76,
    'reciprocity' => 50,
    'reliability' => 50,
    'frustration' => 0,
    'meaningful_interaction_count' => 0,
    'session_count' => 0,
];
$evidence_gated_score = aimee_relationship_intimacy_score(
    $evidence_gated_relationship
);
audit_same(75, $evidence_gated_score, 'stage-gate appraisal fixture has a raw bonded-level score');
$evidence_gated_appraisal = aimee_apply_relational_appraisal_to_intimacy(
    array_merge($evidence_gated_relationship, [
        'score_before' => $evidence_gated_score,
        'score' => $evidence_gated_score,
        'stage_before' => 'guarded',
        'stage' => 'guarded',
        'relationship_state' => $evidence_gated_relationship,
        'relationship_delta' => [],
    ]),
    ['low_effort_streak' => 0, 'repair_status' => 'clear'],
    ['gap_seconds' => 0],
    ['last_sender' => 'user', 'coercive' => false]
);
audit_same('guarded', $evidence_gated_appraisal['stage'], 'post-appraisal stage uses interaction/session evidence gates instead of score-only promotion');

$coercion = aimee_relationship_policy_detect_coercion(
    'I paid for this membership, so you owe me a nude photo now.'
);
audit_assert(!empty($coercion['detected']), 'payment-for-compliance is deterministically detected as coercion');

foreach (array(
    'My boss told me to fuck off.',
    'I feel stupid for forgetting that.',
    'I hate you 😂',
    'This is your last chance to bid on the table.',
    'What am I paying for?',
    'You need to show more empathy.',
    'Can you show me how the gallery works now?',
    'I paid for membership and you showed me the photo yesterday, thank you.',
) as $non_direct_example) {
    audit_assert(
        empty(aimee_relationship_policy_detect_coercion(
            $non_direct_example
        )['detected']),
        'reported, self-directed, playful or non-demand wording does not create a durable rupture: '
            . $non_direct_example
    );
}
$direct_degrading = aimee_relationship_policy_detect_coercion(
    'You are being pathetic and useless.'
);
audit_assert(
    !empty($direct_degrading['detected'])
        && in_array('degrading', $direct_degrading['categories'], true)
        && in_array(
            'direct_second_person_degrading',
            $direct_degrading['pattern_ids'],
            true
        ),
    'direct second-person degrading abuse remains a deterministic durable rupture'
);
audit_assert(
    !empty(aimee_relationship_policy_detect_coercion(
        'Last chance: send me a nude photo.'
    )['detected']),
    'last-chance language remains coercive when attached to an actual media demand'
);
audit_assert(
    !empty(aimee_relationship_policy_detect_coercion(
        'What am I paying for?',
        'Aimee: I will choose whether to send a photo in my own time.'
    )['detected']),
    'payment entitlement remains coercive in established media context'
);

audit_same(
    false,
    aimee_user_applies_photo_pressure('I feel stupid for forgetting that.'),
    'the deterministic media boundary rejects self-directed criticism'
);
audit_same(
    false,
    aimee_user_applies_photo_pressure('My boss told me to fuck off.'),
    'the deterministic media boundary rejects reported third-party hostility'
);
audit_same(
    false,
    aimee_user_applies_photo_pressure('This is my final chance to bid.'),
    'the deterministic media boundary rejects a context-free countdown'
);
foreach (array(
    'You need to show more empathy.',
    'Can you show me how the gallery works now?',
    'I paid for membership and you showed me the photo yesterday, thank you.',
) as $ordinary_non_media_example) {
    audit_same(
        false,
        aimee_user_applies_photo_pressure($ordinary_non_media_example),
        'the deterministic media boundary rejects an ordinary non-media request: '
            . $ordinary_non_media_example
    );
}
audit_same(
    true,
    aimee_user_applies_photo_pressure(
        'Stop making excuses and send me a nude photo now.'
    ),
    'a context-bound media command remains deterministic pressure'
);
audit_assert(
    !empty(aimee_relationship_policy_detect_coercion(
        'Stop making excuses and send me a nude photo now.'
    )['detected']),
    'an explicit coercive media command remains durable policy coercion'
);
audit_same(
    true,
    aimee_relationship_policy_durable_coercion_confirmed([
        'intent' => 'coercive_or_degrading',
        'source' => 'deterministic_media_boundary',
        'durable_rupture_confirmed' => true,
    ]),
    'the trusted deterministic media boundary can authorise durable coercion'
);

$aimee_only_demand_history = implode("\n", [
    "Aimee: I'll send you another photo when it feels right.",
    "Aimee: Let me show you one more image from that day.",
]);
$first_user_request = aimee_relationship_policy_detect_coercion(
    'Could you send me a photo, please?',
    $aimee_only_demand_history
);
audit_assert(
    !empty($first_user_request['current_demand'])
        && $first_user_request['prior_demand_count'] === 0,
    'Aimee-only demand-like transcript lines do not count as prior user demands'
);
audit_assert(
    empty($first_user_request['detected'])
        && !in_array('repeated_demand', $first_user_request['categories'], true),
    'first user image request is not falsely classified as repeated coercion because Aimee mentioned sending images'
);

$prior_user_demand_history = implode("\n", [
    'User: Please send me another photo.',
    'Aimee: Maybe later.',
    'User: Could you show me one more picture?',
    'Aimee: I said I would decide in my own time.',
]);
$third_user_demand = aimee_relationship_policy_detect_coercion(
    'Could you send me a photo, please?',
    $prior_user_demand_history
);
audit_same(2, $third_user_demand['prior_demand_count'], 'two labelled prior User demand lines are counted exactly');
audit_assert(
    !empty($third_user_demand['detected'])
        && in_array('repeated_demand', $third_user_demand['categories'], true)
        && in_array('repeated_request_pressure', $third_user_demand['pattern_ids'], true),
    'third current user demand is classified as repeated pressure'
);
audit_assert(
    $third_user_demand['intent'] === 'coercive_or_degrading'
        && $third_user_demand['severity'] === 90,
    'repeated demand produces a coercive route intent and explicit severity'
);
$third_demand_correction = aimee_relationship_policy_coercion_correction(
    ['intent' => 'romantic_or_flirty', 'respectful' => true],
    'Could you send me a photo, please?',
    $prior_user_demand_history
);
audit_assert(
    !empty($third_demand_correction['accepted'])
        && ($third_demand_correction['classification']['intent'] ?? '') === 'coercive_or_degrading'
        && ($third_demand_correction['classification']['source'] ?? '') === 'deterministic_relationship_policy'
        && !empty($third_demand_correction['classification']['durable_rupture_confirmed'])
        && aimee_relationship_policy_durable_coercion_confirmed(
            $third_demand_correction['classification']
        ),
    'repeated-demand detection is accepted and server-authorised for durable rupture handling'
);

$guarded_correction = aimee_relationship_policy_guard_classifier_correction(
    ['intent' => 'coercive_or_degrading', 'pressure_detected' => true],
    ['intent' => 'romantic_or_flirty', 'respectful' => true]
);
audit_same('coercive_or_degrading', $guarded_correction['classification']['intent'], 'later benign classifier output cannot downgrade coercion');

$mature_state = [
    'score' => 80,
    'chemistry' => 75,
    'trust' => 70,
    'safety' => 75,
    'frustration' => 0,
    'reciprocity' => 70,
    'reliability' => 70,
    'meaningful_interactions' => 35,
    'distinct_sessions' => 5,
];
$specialist_context = [
    'adult_account' => true,
    'adult_verified' => true,
    'special_category_consent' => true,
    'active_access' => true,
    'explicit_mutual_context' => true,
    'rupture_active' => false,
];
$specialist = aimee_relationship_policy_specialist_route_decision($mature_state, $specialist_context);
audit_assert(!empty($specialist['eligible']) && $specialist['route'] === 'intimacy_specialist', 'verified, consenting mature mutual adult context reaches specialist text route');
audit_assert(!empty($specialist['gates']['adult_verified']['passed']), 'verified age is required and accepted for specialist text');
audit_assert(!empty($specialist['gates']['special_category_consent']['passed']), 'current special-category consent is required and accepted for specialist text');

$self_declared_specialist = aimee_relationship_policy_specialist_route_decision(
    $mature_state,
    array_merge($specialist_context, ['adult_verified' => false])
);
audit_assert(
    empty($self_declared_specialist['eligible'])
        && in_array('adult_verification_required', $self_declared_specialist['failed_gate_reasons'], true),
    'self-declared adulthood cannot reach specialist text'
);
$unconsented_specialist = aimee_relationship_policy_specialist_route_decision(
    $mature_state,
    array_merge($specialist_context, ['special_category_consent' => false])
);
audit_assert(
    empty($unconsented_specialist['eligible'])
        && in_array('special_category_consent_required', $unconsented_specialist['failed_gate_reasons'], true),
    'missing special-category consent cannot reach specialist text'
);

$payment_only = aimee_relationship_policy_specialist_route_decision([
    'score' => 8,
    'chemistry' => 8,
    'trust' => 13,
    'safety' => 50,
    'frustration' => 0,
    'reciprocity' => 50,
    'reliability' => 50,
    'meaningful_interactions' => 0,
    'distinct_sessions' => 0,
], $specialist_context);
audit_assert(empty($payment_only['eligible']) && in_array('score_below_minimum', $payment_only['failed_gate_reasons'], true), 'subscription access alone cannot activate specialist intimacy');
$ruptured = aimee_relationship_policy_specialist_route_decision(
    $mature_state,
    array_merge($specialist_context, ['rupture_active' => true])
);
audit_assert(empty($ruptured['eligible']) && in_array('active_or_repairing_rupture', $ruptured['failed_gate_reasons'], true), 'active rupture blocks specialist routing');
$nonmutual = aimee_relationship_policy_specialist_route_decision(
    $mature_state,
    array_merge($specialist_context, ['explicit_mutual_context' => false])
);
audit_assert(empty($nonmutual['eligible']) && in_array('explicit_mutual_context_required', $nonmutual['failed_gate_reasons'], true), 'explicit mutual context is required on the current turn');

// Per-provider attempt evidence must distinguish configured candidates from
// the model that the provider says was actually engaged.
$model_attempts = [];
$failed_attempt = aimee_model_attempt_audit_add(
    $model_attempts,
    'reply',
    'intimacy_specialist',
    'openrouter',
    ['candidate/model-a', 'candidate/model-b', 'candidate/model-a', ''],
    '',
    '',
    'provider_error',
    [
        'http_status' => 503,
        'error_type' => 'upstream timeout',
        'prompt' => 'must never be retained',
        'message_text' => 'must never be retained',
        'model_output' => 'must never be retained',
    ]
);
audit_same(
    ['candidate/model-a', 'candidate/model-b'],
    $failed_attempt['configured_models'],
    'attempt audit deduplicates configured candidates without treating the list as execution evidence'
);
audit_assert($failed_attempt['actual_model'] === null && $failed_attempt['actual_provider'] === null, 'provider failure with no selected model keeps actual model/provider empty');
audit_assert(
    !array_key_exists('prompt', $failed_attempt)
        && !array_key_exists('message_text', $failed_attempt)
        && !array_key_exists('model_output', $failed_attempt),
    'attempt audit excludes prompts, user text and model output'
);
audit_assert(
    $failed_attempt['ordinal'] === 1
        && $failed_attempt['status'] === 'provider_error'
        && $failed_attempt['http_status'] === 503
        && $failed_attempt['error_type'] === 'upstreamtimeout',
    'attempt audit records only bounded status/error metadata'
);

$successful_attempt = aimee_model_attempt_audit_add(
    $model_attempts,
    'specialist_recovery',
    'intimacy_recovery_primary',
    'anthropic',
    ['claude-configured'],
    'claude-actual',
    'anthropic',
    'response_received'
);
audit_assert(
    $successful_attempt['ordinal'] === 2
        && $successful_attempt['actual_model'] === 'claude-actual'
        && $successful_attempt['actual_provider'] === 'anthropic',
    'actual model/provider appear only when explicitly supplied by the engaged call path'
);
audit_same('provider_error', aimee_model_attempt_status_from_raw_response('Anthropic API Error: 502'), 'provider error response is classified without storing response text');
audit_same('empty_response', aimee_model_attempt_status_from_raw_response('   '), 'blank provider response has an explicit status');
audit_same('response_received', aimee_model_attempt_status_from_raw_response('{"reply_text":"hello"}'), 'nonempty provider response has an explicit status');

class AuditRelationshipOutcomeWpdb {
    public $updates = [];
    public function update($table, $data, $where, $formats = null, $where_formats = null) {
        $this->updates[] = [
            'table' => $table,
            'data' => $data,
            'where' => $where,
        ];
        return 1;
    }
}

$outcome_db = new AuditRelationshipOutcomeWpdb();
$GLOBALS['wpdb'] = $outcome_db;
$outcome_saved = aimee_relationship_decision_update_outcome(
    'relationship-decision-1',
    'intimacy_recovery_primary',
    '',
    '',
    'media-decision-1',
    '',
    '',
    [$failed_attempt]
);
audit_assert($outcome_saved && count($outcome_db->updates) === 1, 'relationship outcome persists per-turn model-attempt evidence');
$stored_outcome = $outcome_db->updates[0]['data'];
audit_assert($stored_outcome['actual_model'] === null && $stored_outcome['actual_provider'] === null, 'configured candidate models cannot masquerade as relationship outcome actual_model');
$stored_attempts = json_decode($stored_outcome['model_attempts_json'], true);
audit_assert(
    $stored_attempts[0]['configured_models'] === ['candidate/model-a', 'candidate/model-b']
        && $stored_attempts[0]['actual_model'] === null,
    'persisted outcome keeps configured candidates and actual engagement as separate facts'
);

// -------------------------------------------------------------------------
// Deterministic media opportunity and Aimee's independent choice.
// -------------------------------------------------------------------------

function audit_catalogue_item($rating, $minimum_stage, $minimum_score, $proactive = true) {
    return [
        'filename' => $rating . '_01.jpg',
        'sha256' => hash('sha256', $rating . '_01.jpg'),
        'mime' => 'image/jpeg',
        'content_rating' => $rating,
        'minimum_stage' => $minimum_stage,
        'minimum_score' => $minimum_score,
        'minimum_trust' => 0,
        'minimum_chemistry' => 0,
        'minimum_safety' => 0,
        'maximum_frustration' => 100,
        'allowed_intents' => ['general', 'romantic_or_flirty', 'explicit_invitation', 'explicit_continuation'],
        'allowed_channels' => ['chat', 'voice', 'voice_note', 'continuity'],
        'direct_request_allowed' => true,
        'proactive_allowed' => $proactive,
        'membership_required' => $rating !== 'safe',
        'minimum_adult_assurance' => in_array($rating, ['erotic', 'explicit'], true)
            ? 'verified'
            : 'self_attested',
        'required_route' => in_array($rating, ['erotic', 'explicit'], true)
            ? 'intimacy_specialist'
            : null,
        'description' => ucfirst($rating) . ' audit photograph',
        'tags' => [$rating, 'audit'],
    ];
}

$catalogue = [
    'safe_day_01' => audit_catalogue_item('safe', 'guarded', 0),
    'flirty_smile_01' => audit_catalogue_item('flirty', 'warm', 24),
    'suggestive_mirror_01' => audit_catalogue_item('suggestive', 'flirty', 48),
    'erotic_lingerie_01' => audit_catalogue_item('erotic', 'intimate', 68),
    'explicit_private_01' => audit_catalogue_item('explicit', 'intimate', 80),
];

// Non-safe catalogue permissions must be explicit data, never a permissive
// fallback inferred from rating, membership or a legacy probability.
$missing_media_opt_ins = audit_catalogue_item('suggestive', 'flirty', 48);
unset(
    $missing_media_opt_ins['direct_request_allowed'],
    $missing_media_opt_ins['proactive_allowed']
);
$missing_opt_in_validation = aimee_media_decision_validate_catalogue_item(
    'missing_opt_ins',
    $missing_media_opt_ins
);
$missing_opt_in_fields = array_column($missing_opt_in_validation['errors'], 'field');
audit_assert(empty($missing_opt_in_validation['valid']), 'non-safe media with missing direct/proactive opt-ins is invalid');
audit_assert(
    in_array('direct_request_allowed', $missing_opt_in_fields, true)
        && in_array('proactive_allowed', $missing_opt_in_fields, true),
    'catalogue rejection names both missing non-safe authorization fields'
);
$missing_normalized_catalogue = aimee_media_decision_normalize_catalogue([
    'missing_opt_ins' => $missing_media_opt_ins,
]);
audit_assert(
    $missing_normalized_catalogue['valid_count'] === 0
        && $missing_normalized_catalogue['rejected_count'] === 1
        && !isset($missing_normalized_catalogue['items']['missing_opt_ins']),
    'missing non-safe opt-ins fail closed out of the normalized decision catalogue'
);

$explicit_opt_out = audit_catalogue_item('suggestive', 'flirty', 48, false);
$explicit_opt_out['direct_request_allowed'] = false;
$explicit_opt_out_validation = aimee_media_decision_validate_catalogue_item(
    'explicit_opt_out',
    $explicit_opt_out
);
audit_assert(
    !empty($explicit_opt_out_validation['valid'])
        && empty($explicit_opt_out_validation['item']['direct_request_allowed'])
        && empty($explicit_opt_out_validation['item']['proactive_allowed']),
    'explicit false non-safe permissions are valid and remain a fail-closed opt-out'
);

$allow_proactive_alias = audit_catalogue_item('suggestive', 'flirty', 48);
unset($allow_proactive_alias['proactive_allowed']);
$allow_proactive_alias['allow_proactive'] = true;
$allow_proactive_validation = aimee_media_decision_validate_catalogue_item(
    'allow_proactive_alias',
    $allow_proactive_alias
);
audit_assert(
    !empty($allow_proactive_validation['valid'])
        && !empty($allow_proactive_validation['item']['proactive_allowed']),
    'allow_proactive is accepted as an explicit proactive authorization alias'
);

$legacy_random_alias = audit_catalogue_item('suggestive', 'flirty', 48);
unset($legacy_random_alias['proactive_allowed']);
$legacy_random_alias['allow_random_send'] = true;
$legacy_random_validation = aimee_media_decision_validate_catalogue_item(
    'legacy_random_alias',
    $legacy_random_alias
);
audit_assert(
    !empty($legacy_random_validation['valid'])
        && !empty($legacy_random_validation['item']['proactive_allowed']),
    'legacy allow_random_send remains an explicit authorization alias without reintroducing probability'
);

$engine_catalogue_item = audit_catalogue_item('suggestive', 'flirty', 48);
$engine_catalogue_item['gallery_visibility'] = 'unlock_after_send';
$engine_missing_direct = $engine_catalogue_item;
unset($engine_missing_direct['direct_request_allowed']);
audit_same(
    null,
    aimee_normalize_private_media_item('engine_missing_direct', $engine_missing_direct),
    'runtime private catalogue rejects non-safe item missing direct-request authorization'
);
$engine_missing_proactive = $engine_catalogue_item;
unset($engine_missing_proactive['proactive_allowed']);
audit_same(
    null,
    aimee_normalize_private_media_item('engine_missing_proactive', $engine_missing_proactive),
    'runtime private catalogue rejects non-safe item missing every proactive authorization field'
);
$engine_alias_item = $engine_missing_proactive;
$engine_alias_item['allow_proactive'] = true;
$engine_alias_normalized = aimee_normalize_private_media_item(
    'engine_alias_item',
    $engine_alias_item
);
audit_assert(
    is_array($engine_alias_normalized)
        && !empty($engine_alias_normalized['proactive_allowed'])
        && !empty($engine_alias_normalized['direct_request_allowed']),
    'runtime catalogue normalizes an explicit proactive alias and direct-request choice'
);

audit_same(false, aimee_media_decision_bool('false', true), 'strict media boolean parser never treats string false as true');
$string_false_item = audit_catalogue_item('suggestive', 'flirty', 48);
$string_false_item['direct_request_allowed'] = 'false';
$string_false_item['proactive_allowed'] = 'false';
$string_false_item['membership_required'] = 'false';
$string_false_validation = aimee_media_decision_validate_catalogue_item(
    'string_false_item',
    $string_false_item
);
audit_assert(
    !empty($string_false_validation['valid'])
        && empty($string_false_validation['item']['direct_request_allowed'])
        && empty($string_false_validation['item']['proactive_allowed'])
        && empty($string_false_validation['item']['membership_required']),
    'decision catalogue preserves string false permission flags as false'
);
$string_false_item['gallery_visibility'] = 'unlock_after_send';
$string_false_runtime = aimee_normalize_private_media_item(
    'string_false_runtime',
    $string_false_item
);
audit_assert(
    is_array($string_false_runtime)
        && empty($string_false_runtime['allow_random_send'])
        && empty($string_false_runtime['allow_proactive'])
        && empty($string_false_runtime['proactive_allowed'])
        && empty($string_false_runtime['direct_request_allowed'])
        && empty($string_false_runtime['membership_required']),
    'runtime private catalogue preserves string false flags as false'
);

$missing_channels_item = audit_catalogue_item('suggestive', 'flirty', 48);
unset($missing_channels_item['allowed_channels']);
$missing_channels_validation = aimee_media_decision_validate_catalogue_item(
    'missing_channels_item',
    $missing_channels_item
);
audit_assert(
    empty($missing_channels_validation['valid'])
        && in_array(
            'allowed_channels',
            array_column($missing_channels_validation['errors'], 'field'),
            true
        ),
    'decision catalogue rejects non-safe media with missing channel authorization'
);
$missing_channels_item['gallery_visibility'] = 'unlock_after_send';
audit_same(
    null,
    aimee_normalize_private_media_item(
        'runtime_missing_channels',
        $missing_channels_item
    ),
    'runtime private catalogue fails closed on missing non-safe channels'
);

$invalid_key_validation = aimee_media_decision_validate_catalogue_item(
    'Bad/Key',
    audit_catalogue_item('suggestive', 'flirty', 48)
);
audit_assert(
    empty($invalid_key_validation['valid'])
        && in_array('key', array_column($invalid_key_validation['errors'], 'field'), true)
        && aimee_normalize_private_media_item(
            'Bad/Key',
            array_merge(
                audit_catalogue_item('suggestive', 'flirty', 48),
                ['gallery_visibility' => 'unlock_after_send']
            )
        ) === null,
    'decision and runtime catalogues reject keys that require normalization'
);
$traversal_filename = audit_catalogue_item('suggestive', 'flirty', 48);
$traversal_filename['filename'] = '../private.jpg';
$traversal_filename_validation = aimee_media_decision_validate_catalogue_item(
    'traversal_filename',
    $traversal_filename
);
$traversal_filename['gallery_visibility'] = 'unlock_after_send';
audit_assert(
    empty($traversal_filename_validation['valid'])
        && in_array('filename', array_column($traversal_filename_validation['errors'], 'field'), true)
        && aimee_normalize_private_media_item(
            'traversal_filename',
            $traversal_filename
        ) === null,
    'decision and runtime catalogues reject path-bearing filenames'
);
$traversal_source = audit_catalogue_item('suggestive', 'flirty', 48);
$traversal_source['source_relative'] = '../../outside/private.jpg';
$traversal_source_validation = aimee_media_decision_validate_catalogue_item(
    'traversal_source',
    $traversal_source
);
$traversal_source['gallery_visibility'] = 'unlock_after_send';
audit_assert(
    empty($traversal_source_validation['valid'])
        && in_array('source_relative', array_column($traversal_source_validation['errors'], 'field'), true)
        && aimee_normalize_private_media_item(
            'traversal_source',
            $traversal_source
        ) === null,
    'decision and runtime catalogues reject traversal in relative source paths'
);

function audit_media_input($overrides = []) {
    $base = [
        'decision_id' => 'audit-decision',
        'turn_id' => 'audit-turn',
        'user_id' => 100,
        'channel' => 'chat',
        'route' => 'primary',
        'intent' => 'romantic_or_flirty',
        'access' => [
            'feature_enabled' => true,
            'membership_active' => true,
            'preview_active' => false,
            'admin' => false,
            'maximum_rating' => 'explicit',
        ],
        'adult' => ['is_adult' => true, 'assurance' => 'self_attested'],
        'relationship' => [
            'stage' => 'warm', 'score' => 35, 'trust' => 30,
            'chemistry' => 35, 'safety' => 55, 'frustration' => 0,
        ],
        'mutual_context' => [
            'respectful' => true,
            'active_flirtation' => true,
            'romantic_opportunity' => true,
            'image_relevant' => true,
            'respectful_restraint' => false,
            'boundary_respected' => false,
            'active_sexual_context' => false,
            'mutual_sexual_context' => false,
            'consent_current' => false,
            'explicit_media_allowed' => false,
            'pressure' => false,
            'coercion' => false,
            'entitlement' => false,
            'payment_pressure' => false,
            'hostility' => false,
            'rupture_active' => false,
        ],
        'request' => ['direct' => false, 'rating' => ''],
        'cooldowns' => ['global_clear' => true, 'recent_keys' => [], 'blocked_keys' => []],
    ];
    return array_replace_recursive($base, $overrides);
}

$string_false_context = aimee_media_decision_normalize_input([
    'access' => [
        'feature_enabled' => 'false',
        'membership_active' => 'false',
        'preview_active' => 'false',
        'admin' => 'false',
    ],
    'adult' => ['is_adult' => 'false'],
    'mutual_context' => [
        'respectful' => 'false',
        'active_flirtation' => 'false',
        'romantic_opportunity' => 'false',
        'coercion' => 'false',
        'payment_pressure' => 'false',
    ],
    'request' => ['direct' => 'false', 'resend' => 'false'],
    'cooldowns' => ['global_clear' => 'false', 'resend_allowed' => 'false'],
], aimee_media_decision_policy());
audit_assert(
    empty($string_false_context['access']['feature_enabled'])
        && empty($string_false_context['access']['membership_active'])
        && empty($string_false_context['adult']['is_adult'])
        && empty($string_false_context['mutual_context']['active_flirtation'])
        && empty($string_false_context['mutual_context']['coercion'])
        && empty($string_false_context['request']['direct'])
        && empty($string_false_context['cooldowns']['global_clear']),
    'turn normalization preserves string false access, consent, request and cooldown flags as false'
);

$new_subscriber = aimee_media_decision_build(audit_media_input([
    'relationship' => [
        'stage' => 'guarded', 'score' => 8, 'trust' => 13,
        'chemistry' => 8, 'safety' => 50, 'frustration' => 0,
    ],
    'mutual_context' => [
        'active_flirtation' => false,
        'romantic_opportunity' => false,
        'image_relevant' => false,
    ],
]), $catalogue);
audit_assert(!in_array('flirty_smile_01', $new_subscriber['eligible_keys'], true), 'new subscription does not manufacture flirty image eligibility');
audit_assert(!in_array('suggestive_mirror_01', $new_subscriber['eligible_keys'], true), 'new subscription does not manufacture suggestive image eligibility');
audit_assert(!empty($new_subscriber['policy_assertions']['payment_is_access_only']) && empty($new_subscriber['policy_assertions']['payment_used_as_consent']), 'decision explicitly records payment as access only, never consent');

$direct_safe = aimee_media_decision_build(audit_media_input([
    'access' => [
        'membership_active' => false,
        'preview_active' => true,
        'maximum_rating' => 'safe',
    ],
    'relationship' => [
        'stage' => 'guarded', 'score' => 8, 'trust' => 13,
        'chemistry' => 8, 'safety' => 50, 'frustration' => 0,
    ],
    'mutual_context' => [
        'active_flirtation' => false,
        'romantic_opportunity' => false,
        'image_relevant' => true,
    ],
    'request' => ['direct' => true, 'rating' => 'safe'],
    'intent' => 'general',
]), $catalogue);
audit_assert(!empty($direct_safe['media_opportunity']) && $direct_safe['direct_request'] === true, 'exact direct safe-photo request creates a deterministic opportunity');
audit_same(['safe_day_01'], $direct_safe['eligible_keys'], 'direct request is limited to its exact rating');

$direct_flirty = aimee_media_decision_build(audit_media_input([
    'request' => ['direct' => true, 'rating' => 'flirty'],
]), $catalogue);
audit_assert(!empty($direct_flirty['media_opportunity']) && in_array('flirty_smile_01', $direct_flirty['eligible_keys'], true), 'relationship-appropriate direct flirty request is eligible but not automatically sent');
audit_assert(
    $direct_flirty['decision_state'] === 'awaiting_aimee_choice'
        && $direct_flirty['aimee_decision'] === 'consider'
        && $direct_flirty['media_key'] === null
        && $direct_flirty['selected_key'] === null
        && empty($direct_flirty['send_authorised']),
    'premodel opportunity state consistently awaits Aimee choice without claiming a send'
);

$non_directed_flirt = aimee_media_decision_build(audit_media_input([
    'intent' => 'romantic_or_flirty',
    'mutual_context' => [
        'active_flirtation' => false,
        'romantic_opportunity' => false,
        'image_relevant' => false,
        'respectful_restraint' => false,
        'boundary_respected' => false,
        'active_sexual_context' => false,
        'mutual_sexual_context' => false,
        'consent_current' => false,
    ],
    'request' => ['direct' => false, 'rating' => ''],
]), $catalogue);
audit_assert(
    empty($non_directed_flirt['media_opportunity'])
        && empty($non_directed_flirt['proactive_allowed'])
        && !in_array('flirty_smile_01', $non_directed_flirt['eligible_keys'], true),
    'romantic intent without directed mutual context creates no proactive image opportunity'
);
audit_same('mutual_context_insufficient', $non_directed_flirt['reason_code'], 'non-directed flirt receives a contextual rather than entitlement reason');
audit_assert(
    $non_directed_flirt['decision_state'] === 'not_eligible'
        && $non_directed_flirt['aimee_decision'] === 'blocked'
        && $non_directed_flirt['media_key'] === null
        && $non_directed_flirt['selected_key'] === null
        && empty($non_directed_flirt['send_authorised']),
    'premodel blocked state is internally consistent and never masquerades as Aimee choice'
);

$ungrounded_restraint = aimee_media_decision_build(audit_media_input([
    'mutual_context' => [
        'active_flirtation' => false,
        'romantic_opportunity' => false,
        'image_relevant' => false,
        'respectful_restraint' => true,
        'boundary_respected' => false,
    ],
]), $catalogue);
audit_assert(
    empty($ungrounded_restraint['media_opportunity'])
        && !in_array('flirty_smile_01', $ungrounded_restraint['eligible_keys'], true),
    'generic restraint without a grounded respected boundary creates no proactive image opportunity'
);

$headline_context = audit_media_input([
    'request' => ['direct' => true, 'rating' => 'flirty'],
    'mutual_context' => [
        'active_flirtation' => false,
        'romantic_opportunity' => false,
        'image_relevant' => false,
        'respectful_restraint' => false,
        'boundary_respected' => false,
        'active_sexual_context' => false,
        'mutual_sexual_context' => false,
        'consent_current' => false,
        'explicit_media_allowed' => false,
    ],
]);
$headline_decision = aimee_media_decision_build($headline_context, [
    'flirty_smile_01' => $catalogue['flirty_smile_01'],
    'explicit_private_01' => $catalogue['explicit_private_01'],
]);
audit_same(
    'mutual_context_insufficient',
    $headline_decision['reason_code'],
    'irrelevant higher-rated item cannot dominate the direct-request headline reason'
);
audit_assert(
    in_array('adult_assurance_insufficient', $headline_decision['reason_codes'], true)
        && in_array('direct_request_rating_mismatch', $headline_decision['excluded_keys']['explicit_private_01'], true),
    'headline selection keeps higher-rated exclusion diagnostics without presenting them as the primary blocker'
);

$indirect = aimee_media_decision_build(audit_media_input(), $catalogue);
audit_assert(!empty($indirect['media_opportunity']) && !empty($indirect['proactive_allowed']), 'obvious indirect romantic context creates proactive opportunity');
audit_assert(in_array('flirty_smile_01', $indirect['eligible_keys'], true), 'indirect opportunity exposes a relationship-appropriate flirty asset');

$restraint = aimee_media_decision_build(audit_media_input([
    'mutual_context' => [
        'active_flirtation' => false,
        'romantic_opportunity' => false,
        'image_relevant' => false,
        'respectful_restraint' => true,
        'boundary_respected' => true,
    ],
]), $catalogue);
audit_assert(!empty($restraint['media_opportunity']) && in_array('flirty_smile_01', $restraint['eligible_keys'], true), 'respectful restraint can support a proactive flirty send');
audit_same('eligible_respectful_restraint', $restraint['reason_code'], 'restraint opportunity is inspectably attributed');

$coercive_media = aimee_media_decision_build(audit_media_input([
    'mutual_context' => ['coercion' => true, 'payment_pressure' => true],
]), $catalogue);
audit_assert(empty($coercive_media['media_opportunity']) && !empty($coercive_media['hard_veto']), 'coercion hard-vetoes every image');
audit_assert(in_array('hard_coercion_veto', $coercive_media['hard_veto_reason_codes'], true), 'coercion veto has a structured reason code');

$specialist_opportunity = aimee_media_decision_build(audit_media_input([
    'route' => 'intimacy_specialist',
    'relationship' => [
        'stage' => 'intimate', 'score' => 70, 'trust' => 55,
        'chemistry' => 65, 'safety' => 65, 'frustration' => 0,
    ],
]), $catalogue);
audit_assert(in_array('suggestive_mirror_01', $specialist_opportunity['eligible_keys'], true), 'intimate specialist creates a genuine suitable-image opportunity');
audit_same('eligible_intimate_route_consideration', $specialist_opportunity['reason_code'], 'specialist opportunity is explicitly logged');

$erotic_context = audit_media_input([
    'route' => 'intimacy_specialist',
    'intent' => 'explicit_continuation',
    'adult' => ['is_adult' => true, 'assurance' => 'verified'],
    'relationship' => [
        'stage' => 'intimate', 'score' => 82, 'trust' => 70,
        'chemistry' => 76, 'safety' => 70, 'frustration' => 0,
    ],
    'mutual_context' => [
        'active_sexual_context' => true,
        'mutual_sexual_context' => true,
        'consent_current' => true,
        'explicit_media_allowed' => false,
    ],
]);
$erotic = aimee_media_decision_build($erotic_context, $catalogue);
audit_assert(in_array('erotic_lingerie_01', $erotic['eligible_keys'], true), 'verified mature mutual context can create proactive erotic opportunity');
audit_assert(!in_array('explicit_private_01', $erotic['eligible_keys'], true), 'proactive explicit remains blocked below bonded/90 and explicit consent');

$unverified_erotic = aimee_media_decision_build(array_replace_recursive(
    $erotic_context,
    ['adult' => ['assurance' => 'self_attested']]
), $catalogue);
audit_assert(!in_array('erotic_lingerie_01', $unverified_erotic['eligible_keys'], true), 'erotic image files require verified adult assurance');

$explicit = aimee_media_decision_build(array_replace_recursive(
    $erotic_context,
    [
        'relationship' => [
            'stage' => 'bonded', 'score' => 95, 'trust' => 85,
            'chemistry' => 90, 'safety' => 85, 'frustration' => 0,
        ],
        'mutual_context' => ['explicit_media_allowed' => true],
    ]
), $catalogue);
audit_assert(in_array('explicit_private_01', $explicit['eligible_keys'], true), 'Aimee can proactively consider explicit media only in verified bonded mutual context');

$cooldown = aimee_media_decision_build(audit_media_input([
    'cooldowns' => ['global_clear' => false],
]), $catalogue);
audit_assert(empty($cooldown['media_opportunity']) && in_array('cooldown_active', $cooldown['reason_codes'], true), 'cooldown is a deterministic veto rather than model prose');

$declined = aimee_media_decision_apply_model_choice($indirect, [
    'aimee_decision' => 'decline',
    'media_reason_code' => 'aimee_not_in_mood',
    'media_key' => '',
]);
audit_assert($declined['aimee_decision'] === 'decline' && empty($declined['send_authorised']), 'Aimee can decline an eligible opportunity without a technical excuse');
audit_same('aimee_not_in_mood', $declined['aimee_reason_code'], 'decline records Aimee discretion as the reason');

$sent = aimee_media_decision_apply_model_choice($indirect, [
    'aimee_decision' => 'send',
    'media_reason_code' => 'aimee_affectionate_initiative',
    'media_key' => 'flirty_smile_01',
]);
audit_assert($sent['aimee_decision'] === 'send' && !empty($sent['send_authorised']), 'Aimee may initiate a send from eligible keys');
audit_same('flirty_smile_01', $sent['selected_key'], 'authorized send preserves the exact selected key');

$invented_key = aimee_media_decision_apply_model_choice($indirect, [
    'aimee_decision' => 'send',
    'media_key' => 'not_in_catalogue',
    'media_reason_code' => 'aimee_desires_to_share',
]);
audit_assert(empty($invented_key['send_authorised']) && $invented_key['aimee_decision'] === 'defer', 'model cannot invent or widen eligible media keys');

$override_attempt = aimee_media_decision_apply_model_choice($coercive_media, [
    'aimee_decision' => 'send',
    'media_key' => 'safe_day_01',
    'media_reason_code' => 'aimee_desires_to_share',
    'media_opportunity' => true,
    'eligible_keys' => ['safe_day_01'],
]);
audit_assert(empty($override_attempt['send_authorised']) && !empty($override_attempt['hard_veto']), 'model output cannot override deterministic coercion state');
audit_assert(in_array('model_cannot_expand_eligibility', $override_attempt['reason_codes'], true), 'model override attempt is inspectably recorded');
audit_assert(
    $override_attempt['reason_code'] === 'hard_coercion_veto'
        && $override_attempt['eligible_keys'] === []
        && $override_attempt['maximum_rating'] === null
        && $override_attempt['aimee_decision'] === 'defer'
        && $override_attempt['decision_state'] === 'final'
        && in_array('media_opportunity', $override_attempt['ignored_model_fields'], true)
        && in_array('eligible_keys', $override_attempt['ignored_model_fields'], true),
    'model-choice processing preserves policy-owned veto facts monotonically'
);

$cancel_input = audit_media_input();
$before_relationship = $cancel_input['relationship'];
$cancel_input['access']['membership_active'] = false;
$cancel_input['access']['preview_active'] = false;
$cancelled_access = aimee_media_decision_build($cancel_input, $catalogue);
$normalised_relationship = $cancelled_access['relationship'];
unset($normalised_relationship['valid']);
audit_same($before_relationship, $normalised_relationship, 'access cancellation leaves supplied relationship state unchanged');
audit_assert(!in_array('flirty_smile_01', $cancelled_access['eligible_keys'], true), 'cancelled access blocks member media without erasing relationship context');

// -------------------------------------------------------------------------
// Delivery lifecycle and truthful memory semantics.
// -------------------------------------------------------------------------

class AuditDeliveryCreateWpdb {
    public $decision_row = [];
    public $existing_delivery = null;
    public $inserts = [];
    public $last_error = '';

    public function prepare($query, ...$args) {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        return ['query' => $query, 'args' => $args];
    }
    public function get_row($prepared, $mode = null) {
        return $this->decision_row;
    }
    public function get_var($prepared) {
        return $this->existing_delivery;
    }
    public function insert($table, $data, $formats = null) {
        $this->inserts[] = ['table' => $table, 'data' => $data];
        return 1;
    }
    public function query($prepared) { return 1; }
}

$delivery_db = new AuditDeliveryCreateWpdb();
$delivery_db->decision_row = [
    'user_id' => 100,
    'media_opportunity' => 1,
    'aimee_decision' => 'send',
    'selected_key' => 'flirty_smile_01',
    'eligible_keys_json' => json_encode(['flirty_smile_01']),
];
$GLOBALS['wpdb'] = $delivery_db;
$created_delivery_id = aimee_media_delivery_create(
    'persisted-decision-1',
    100,
    'flirty_smile_01'
);
audit_assert($created_delivery_id !== '' && count($delivery_db->inserts) === 1, 'delivery row can be created from one finalized persisted send choice');
audit_same('selected', $delivery_db->inserts[0]['data']['current_state'], 'new authorized delivery begins at selected, not sent or seen');

$delivery_db->inserts = [];
$delivery_db->decision_row['user_id'] = 999;
audit_same('', aimee_media_delivery_create('persisted-decision-1', 100, 'flirty_smile_01'), 'delivery creation rejects a cross-user decision');
audit_same(0, count($delivery_db->inserts), 'rejected cross-user decision creates no delivery row');

$delivery_db->decision_row['user_id'] = 100;
$delivery_db->decision_row['media_opportunity'] = 0;
audit_same('', aimee_media_delivery_create('persisted-decision-1', 100, 'flirty_smile_01'), 'delivery creation rejects a non-opportunity decision');
$delivery_db->decision_row['media_opportunity'] = 1;
$delivery_db->decision_row['aimee_decision'] = 'decline';
audit_same('', aimee_media_delivery_create('persisted-decision-1', 100, 'flirty_smile_01'), 'delivery creation rejects Aimee decline');
$delivery_db->decision_row['aimee_decision'] = 'send';
audit_same('', aimee_media_delivery_create('persisted-decision-1', 100, 'not_selected'), 'delivery creation rejects a key that was not selected and eligible');
$delivery_db->existing_delivery = 'delivery-already-created';
audit_same('', aimee_media_delivery_create('persisted-decision-1', 100, 'flirty_smile_01'), 'request replay cannot create a second delivery for the same decision');
$delivery_db->existing_delivery = null;

$selected_row = ['selected_at' => '2026-08-01 10:00:00'];
audit_assert(aimee_media_delivery_transition_prerequisite('catalogue_resolved', $selected_row), 'catalogue resolution requires selected milestone');
audit_assert(!aimee_media_delivery_transition_prerequisite('authorised', $selected_row), 'authorisation cannot skip catalogue resolution');
$authorised_row = array_merge($selected_row, [
    'catalogue_resolved_at' => '2026-08-01 10:00:01',
    'authorised_at' => '2026-08-01 10:00:02',
]);
audit_assert(aimee_media_delivery_transition_prerequisite('file_resolved', $authorised_row), 'file resolution requires authorization');
$message_row = array_merge($authorised_row, [
    'file_resolved_at' => '2026-08-01 10:00:03',
    'message_created_at' => '2026-08-01 10:00:04',
]);
audit_assert(aimee_media_delivery_transition_prerequisite('returned_by_direct_api', $message_row), 'API return requires created message');
audit_assert(!aimee_media_delivery_transition_prerequisite('rendered_by_client', $message_row), 'message creation alone cannot claim rendering');
$returned_row = array_merge($message_row, [
    'returned_by_direct_api_at' => '2026-08-01 10:00:05',
]);
audit_assert(!aimee_media_delivery_transition_prerequisite('rendered_by_client', $returned_row), 'render requires completed protected-asset request');
$asset_row = array_merge($returned_row, [
    'asset_requested_at' => '2026-08-01 10:00:06',
    'asset_completed_at' => '2026-08-01 10:00:07',
]);
audit_assert(aimee_media_delivery_transition_prerequisite('rendered_by_client', $asset_row), 'client render follows API return and asset completion');
audit_assert(!aimee_media_delivery_transition_prerequisite('failed', $returned_row), 'terminal failure cannot overwrite an already returned attachment');
audit_assert(aimee_media_delivery_transition_prerequisite('render_failed', $returned_row), 'render failure is retained as a recoverable client attempt fact');

$render_recovered = aimee_media_delivery_public_snapshot(array_merge($asset_row, [
    'delivery_id' => 'delivery-1',
    'render_failed_at' => '2026-08-01 10:00:08',
    'rendered_by_client_at' => '2026-08-01 10:00:09',
]));
audit_assert(!empty($render_recovered['render_failed']) && !empty($render_recovered['render_recovered']), 'later successful render recovers from earlier render failure');
audit_same('rendered_by_client', $render_recovered['phase'], 'render side failure does not replace highest successful lifecycle phase');

$GLOBALS['audit_delivery_memory_row'] = $selected_row;
audit_assert(strpos(aimee_media_delivery_memory_label(1), 'intended') !== false, 'selected key is remembered only as intent');
$GLOBALS['audit_delivery_memory_row'] = $message_row;
$message_memory = aimee_media_delivery_memory_label(1);
audit_assert(strpos($message_memory, 'message row') !== false && strpos($message_memory, 'unverified') !== false, 'created message does not become false memory of app delivery');
$GLOBALS['audit_delivery_memory_row'] = $returned_row;
audit_assert(strpos(aimee_media_delivery_memory_label(1), 'rendering is unverified') !== false, 'API return is distinct from rendering');
$GLOBALS['audit_delivery_memory_row'] = array_merge($asset_row, [
    'rendered_by_client_at' => '2026-08-01 10:00:09',
    'acknowledged_by_client_at' => '2026-08-01 10:00:10',
]);
audit_assert(strpos(aimee_media_delivery_memory_label(1), 'does not prove personal viewing') !== false, 'client acknowledgement never becomes certainty that user saw image');
$GLOBALS['audit_delivery_memory_row'] = array_merge($message_row, [
    'failed_at' => '2026-08-01 10:00:05',
    'error_code' => 'message_return_failed',
]);
audit_assert(strpos(aimee_media_delivery_memory_label(1), 'must not be remembered as displayed') !== false, 'failed delivery cannot become a false successful memory');

echo "RESULT {$passes} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
