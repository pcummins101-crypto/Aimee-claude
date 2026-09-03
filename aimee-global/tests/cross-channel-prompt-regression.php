<?php
/**
 * Focused regressions for synthetic identity and romantic posture across
 * plain-text voice, continuity and autonomous routes.
 *
 * Run with:
 * node tests/run-php-wasm.mjs tests/cross-channel-prompt-regression.php
 */

define('ABSPATH', dirname(__DIR__) . '/');
require_once dirname(__DIR__) . '/includes/romantic-expression.php';

$failures = [];
$checks = 0;

$assert = static function ($condition, $label) use (&$failures, &$checks) {
    $checks++;
    if (!$condition) $failures[] = $label;
};

function aimee_cross_channel_test_function($source, $name) {
    $tokens = token_get_all($source);
    $count = count($tokens);

    for ($index = 0; $index < $count; $index++) {
        if (!is_array($tokens[$index]) || $tokens[$index][0] !== T_FUNCTION) continue;

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
                if ($started && $depth === 0) return $output;
            }
        }
    }

    throw new RuntimeException('Function not found: ' . $name);
}

function aimee_cross_channel_test_envelope($stage, $lane = 'courtship_open', $initiative = false) {
    $postures = [
        'guarded' => 'early_courtship',
        'warm' => 'personal_interest',
        'flirty' => 'mutual_flirtation',
        'intimate' => 'romantic_closeness',
        'bonded' => 'established_bond',
    ];
    $ceilings = [
        'guarded' => 'playful_nonsexual',
        'warm' => 'flirty_nonexplicit',
        'flirty' => 'suggestive_nonexplicit',
        'intimate' => 'romantic_intimate_nonexplicit',
        'bonded' => 'romantic_intimate_nonexplicit',
    ];

    return [
        'relationship_lane' => $lane,
        'relationship_stage' => $stage,
        'relationship_posture' => $postures[$stage] ?? 'non_romantic',
        'maximum_intensity' => $ceilings[$stage] ?? 'none',
        'initiative_allowed' => $initiative,
    ];
}

$warm = aimee_romantic_expression_channel_directive(
    aimee_cross_channel_test_envelope('warm'),
    'voice_greeting'
);
$assert(strpos($warm, 'channel=voice_greeting') !== false, 'voice greeting channel is inspectable');
$assert(strpos($warm, 'clear personal interest') !== false, 'warm voice carries personal interest');
$assert(strpos($warm, 'generic friendship') !== false, 'warm voice is protected from default friend tone');
$assert(strpos($warm, 'user initiated the call') !== false, 'voice greeting does not pretend to be autonomous initiative');

$flirty = aimee_romantic_expression_channel_directive(
    aimee_cross_channel_test_envelope('flirty'),
    'continuity'
);
$assert(strpos($flirty, 'Mutual attraction is established') !== false, 'flirty posture survives continuity');
$assert(strpos($flirty, 'remembered promise or follow-up first') !== false, 'continuity purpose outranks forced flirtation');

$bonded = aimee_romantic_expression_channel_directive(
    aimee_cross_channel_test_envelope('bonded'),
    'autonomous'
);
$assert(strpos($bonded, 'partner-like') !== false, 'bonded autonomous voice remains partner-like');
$assert(strpos($bonded, 'without implying ownership') !== false, 'bonded posture preserves autonomy');
$assert(strpos($bonded, 'without manufacturing a new escalation') !== false, 'non-cleared autonomous turn cannot invent escalation');

$initiative = aimee_romantic_expression_channel_directive(
    aimee_cross_channel_test_envelope('intimate', 'courtship_open', true),
    'autonomous'
);
$assert(strpos($initiative, 'available for consideration') !== false, 'cadence-cleared autonomous initiative is a real option');
$assert(strpos($initiative, 'may use it or remain non-romantic by choice') !== false, 'Aimee retains discretion');

$platonic = aimee_romantic_expression_channel_directive(
    aimee_cross_channel_test_envelope('bonded', 'explicitly_platonic'),
    'autonomous'
);
$assert(strpos($platonic, 'do not introduce courtship') !== false, 'explicitly platonic lane remains platonic');

$paused = aimee_romantic_expression_channel_directive(
    aimee_cross_channel_test_envelope('intimate', 'courtship_paused'),
    'continuity'
);
$assert(strpos($paused, 'Repair, boundaries or eligibility take priority') !== false, 'rupture pauses cross-channel romance');

foreach ([$warm, $flirty, $bonded, $initiative, $platonic, $paused] as $directive) {
    $assert(strpos($directive, 'grants no intimate model route') !== false, 'posture never grants an intimate model route');
    $assert(strpos($directive, 'image access') !== false, 'posture never grants image access');
    $assert(strpos($directive, 'payment entitlement') !== false, 'posture never converts payment into entitlement');
}

$engine = file_get_contents(dirname(__DIR__) . '/includes/engine.php');
$assert(is_string($engine) && $engine !== '', 'engine source is readable');

$voice = aimee_cross_channel_test_function($engine, 'aimee_generate_voice_call_greeting');
$continuity = aimee_cross_channel_test_function($engine, 'aimee_generate_continuity_followup');
$autonomous = aimee_cross_channel_test_function($engine, 'run_aimee_background_logic');
$safe_photo = aimee_cross_channel_test_function($engine, 'aimee_generate_proactive_safe_photo_reply');
$suggestive_photo = aimee_cross_channel_test_function($engine, 'aimee_generate_proactive_suggestive_photo_reply');

$assert(strpos($voice, 'aimee_synthetic_identity_directive()') !== false, 'voice greeting receives shared synthetic identity');
$assert(strpos($voice, "'voice_greeting'") !== false, 'voice greeting receives cross-channel romantic posture');
$assert(strpos($voice, 'aimee_relationship_context_directive') !== false, 'voice greeting receives relationship continuity');
$assert(strpos($voice, 'aimee_synthetic_identity_review_reply') !== false, 'voice greeting is reality-reviewed before TTS');
$assert(strpos($voice, 'Sound like a real woman') === false, 'voice greeting no longer asks for human impersonation');
$assert(strpos($voice, "voice_stage === 'bonded'") !== false, 'voice fallback preserves bonded warmth');
$assert(strpos($voice, "voice_stage === 'flirty'") !== false, 'voice fallback preserves established flirtation');

$assert(strpos($continuity, 'aimee_synthetic_identity_directive()') !== false, 'continuity receives shared synthetic identity');
$assert(strpos($continuity, "'continuity'") !== false, 'continuity receives cross-channel romantic posture');
$assert(strpos($continuity, 'continuing an established relationship') !== false, 'continuity names the established relationship without human impersonation');
$assert(strpos($continuity, 'continuing a real relationship') === false, 'continuity removes ambiguous real-woman phrasing');
$assert(strpos($continuity, "['reality_mode' => 'factual']") !== false, 'continuity output is reviewed as factual unless explicitly framed');

$assert(strpos($autonomous, 'aimee_synthetic_identity_directive()') !== false, 'autonomous prompt receives shared synthetic identity');
$assert(strpos($autonomous, "'autonomous'") !== false, 'autonomous prompt receives cross-channel romantic posture');
$assert(strpos($autonomous, 'British synthetic woman who presents as 28') !== false, 'autonomous lead is synthetic rather than human-coded');
$assert(strpos($autonomous, 'current visual-world mood') !== false, 'autonomous prompt frames the digital day as visual-world mood');
$assert(strpos($autonomous, "Aimee's stable day") === false, 'autonomous prompt no longer presents a physical stable day as fact');
$assert(strpos($autonomous, 'aimee_synthetic_identity_review_reply') !== false, 'autonomous output receives reality review');
$assert(strpos($autonomous, '$last_user_text') !== false, 'autonomous review retains the last user context');
$assert(strpos($autonomous, "['reality_mode' => 'factual']") !== false, 'autonomous route cannot silently continue literal offline roleplay');
$assert(
    strpos($autonomous, 'aimee_synthetic_identity_review_reply')
        < strpos($autonomous, '$wpdb->insert($messages_table'),
    'autonomous identity review occurs before message persistence'
);

foreach ([$safe_photo, $suggestive_photo] as $photo_route) {
    $assert(strpos($photo_route, 'aimee_synthetic_identity_directive()') !== false, 'proactive media caption keeps shared synthetic identity');
    $assert(strpos($photo_route, "['reality_mode' => 'visual_world']") !== false, 'proactive media caption is explicitly visual-world framed');
}

if ($failures) {
    fwrite(STDERR, "Cross-channel prompt regression failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "PASS: {$checks} cross-channel synthetic-identity and romantic-posture checks.\n";
