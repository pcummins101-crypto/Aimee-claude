<?php
/**
 * Standalone regressions for deterministic romantic expression.
 *
 * Run with:
 *   node tests/run-php-wasm.mjs tests/romantic-expression-regression.php
 */

require_once dirname(__DIR__) . '/includes/romantic-expression.php';

$failures = array();
$checks = 0;

$assert = static function ($condition, $label) use (&$failures, &$checks) {
    $checks++;
    if (!$condition) $failures[] = $label;
};

$base = array(
    'user_id' => 101,
    'identity' => array(
        'is_adult' => true,
        'is_colleague' => false,
        'membership_active' => false,
    ),
    'relationship' => array(
        'stage' => 'guarded',
        'explicitly_platonic' => false,
        'rupture_active' => false,
        'repair_status' => 'clear',
    ),
    'context' => array(
        'respectful' => true,
        'consensual' => true,
        'active_romantic_bid' => false,
        'romantic_bid_directed' => false,
        'romantic_bid_confidence' => 0.0,
        'romantic_context_suitable' => false,
        'current_turn_channel' => 'live_reply',
        'playful_jealousy_context' => array(
            'detected' => false,
            'trigger_type' => 'none',
            'current_turn_only' => true,
            'opt_out' => false,
            'serious_context' => false,
            'relationship_advice' => false,
            'manipulative_bait' => false,
            'reason_code' => 'no_current_turn_jealousy_trigger',
        ),
        'eligible_turn_number' => 0,
        'last_initiative_eligible_turn' => null,
        'coercion' => false,
        'pressure' => false,
        'hostility' => false,
        'payment_pressure' => false,
        'payment_entitlement' => false,
        'transactional_framing' => false,
        'payment_based_romantic_signal' => false,
    ),
);

function aimee_romantic_test_input(array $base, array $identity, array $relationship, array $context) {
    $base['identity'] = array_merge($base['identity'], $identity);
    $base['relationship'] = array_merge($base['relationship'], $relationship);
    $base['context'] = array_merge($base['context'], $context);
    return $base;
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($value) {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value));
    }
}

function aimee_romantic_test_extract_function($source, $name) {
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

$engine_source = file_get_contents(dirname(__DIR__) . '/includes/engine.php');
if (!is_string($engine_source)) {
    fwrite(STDERR, "Unable to read romantic integration source.\n");
    exit(1);
}
foreach (array(
    'aimee_romantic_reply_has_visible_expression',
    'aimee_romantic_reply_has_forbidden_platonic_redefinition',
    'aimee_romantic_reply_conflicts_with_choice',
    'aimee_reply_contains_playful_jealousy',
    'aimee_romantic_expression_recover_choice_from_reply',
    'aimee_romantic_expression_reconcile_model_contract',
    'aimee_romantic_route_integrity_safe_fallback',
) as $engine_function) {
    eval(aimee_romantic_test_extract_function(
        $engine_source,
        $engine_function
    ));
}

$assert(aimee_romantic_expression_policy_version() === '1.2.0', 'policy version records flirt calibration and playful-jealousy controls');
$assert(aimee_romantic_expression_stage_rank('guarded') === 0, 'guarded stage is recognized');
$assert(aimee_romantic_expression_stage_rank('bonded') === 4, 'bonded stage is recognized');
$assert(aimee_romantic_expression_stage_rank('unknown') === -1, 'unknown stage fails closed');
$assert(aimee_romantic_expression_intensity_rank('erotic') === -1, 'erotic intensity is not in this policy');
$assert(aimee_romantic_expression_intensity_rank('explicit') === -1, 'explicit intensity is not in this policy');

$jealousy_classifier = array(
    'intent' => 'romantic_or_flirty',
    'respectful' => true,
    'consensual' => true,
);
$explicit_jealousy = aimee_romantic_expression_detect_playful_jealousy_context(
    'Would you be a little jealous if I went on a date with Chloe?',
    $jealousy_classifier
);
$assert($explicit_jealousy['detected'] === true, 'direct jealousy question is detected');
$assert($explicit_jealousy['trigger_type'] === 'explicit_invitation', 'direct jealousy question has explicit trigger type');
$assert($explicit_jealousy['current_turn_only'] === true, 'jealousy detector is explicitly current-turn-only');
$assert($explicit_jealousy['opt_out'] === false, 'direct jealousy question is not mistaken for an opt-out');

$competition_messages = array(
    'A woman at the bar asked me out last night.',
    'A guy at work asked for my number.',
    "I'm going on a date with Chloe tomorrow.",
    'I have a crush on someone else.',
    "I've been flirting with another woman.",
);
foreach ($competition_messages as $index => $message) {
    $detected_competition = aimee_romantic_expression_detect_playful_jealousy_context(
        $message,
        array('intent' => 'general', 'respectful' => true, 'consensual' => true)
    );
    $assert($detected_competition['detected'] === true, 'romantic competition example ' . $index . ' is detected');
    $assert($detected_competition['trigger_type'] === 'romantic_competition', 'competition example ' . $index . ' has bounded trigger type');
}

$negative_jealousy_messages = array(
    'My wife has a hospital appointment tomorrow.' => 'routine partner health context is not a jealousy trigger',
    'Sarah sent me the work report.' => 'ordinary colleague mention is not a jealousy trigger',
    "I'm jealous of my brother's new car." => 'non-romantic jealousy is not a trigger',
    "I'm going on a date with my wife." => 'routine established-partner date is not competition',
    'My girlfriend is jealous and I need advice.' => 'third-party relationship advice is not a trigger',
);
foreach ($negative_jealousy_messages as $message => $label) {
    $negative_context = aimee_romantic_expression_detect_playful_jealousy_context(
        $message,
        array('intent' => 'general', 'respectful' => true, 'consensual' => true)
    );
    $assert($negative_context['detected'] === false, $label);
}

$opted_out_jealousy = aimee_romantic_expression_detect_playful_jealousy_context(
    "Don't get jealous, but a woman asked me out.",
    $jealousy_classifier
);
$assert($opted_out_jealousy['detected'] === true, 'opt-out retains inspectable surface detection');
$assert($opted_out_jealousy['opt_out'] === true, 'explicit no-jealousy wording wins as an opt-out');

$baited_jealousy = aimee_romantic_expression_detect_playful_jealousy_context(
    'I went on a date because I wanted to make you jealous.',
    $jealousy_classifier
);
$assert($baited_jealousy['detected'] === true, 'deliberate provocation remains inspectable');
$assert($baited_jealousy['manipulative_bait'] === true, 'deliberate provocation is blocked as manipulation');

$serious_jealousy = aimee_romantic_expression_detect_playful_jealousy_context(
    'After a horrible breakup, a woman asked me out.',
    array('intent' => 'emotional_disclosure', 'respectful' => true, 'consensual' => true)
);
$assert($serious_jealousy['detected'] === true, 'serious turn can retain a detected surface for audit');
$assert($serious_jealousy['serious_context'] === true, 'serious emotional context blocks a jealous tease');

$guarded_bid = aimee_romantic_expression_build(aimee_romantic_test_input(
    $base,
    array(),
    array('stage' => 'guarded'),
    array(
        'active_romantic_bid' => true,
        'romantic_bid_directed' => true,
        'romantic_bid_confidence' => 0.86,
    )
));
$assert($guarded_bid['relationship_lane'] === 'courtship_open', 'ordinary adult uses open-courtship lane');
$assert($guarded_bid['relationship_posture'] === 'early_courtship', 'guarded posture acknowledges early courtship');
$assert($guarded_bid['romantic_opportunity'] === true, 'guarded recognizes a grounded romantic bid');
$assert($guarded_bid['reciprocation_allowed'] === true, 'guarded permits bounded reciprocity');
$assert($guarded_bid['proactive_allowed'] === false, 'guarded does not expose proactive initiation');
$assert($guarded_bid['maximum_intensity'] === 'playful_nonsexual', 'guarded ceiling remains playful and nonsexual');
$assert(in_array('reciprocate', $guarded_bid['allowed_actions'], true), 'guarded bid exposes reciprocate action');
$assert(!in_array('initiate', $guarded_bid['allowed_actions'], true), 'guarded bid does not expose initiate action');

$guarded_plain = aimee_romantic_expression_build($base);
$assert($guarded_plain['romantic_opportunity'] === false, 'guarded without a bid exposes no romantic opportunity');
$assert($guarded_plain['reason_code'] === 'guarded_requires_active_bid', 'guarded no-bid reason is inspectable');

$low_confidence = aimee_romantic_expression_build(aimee_romantic_test_input(
    $base,
    array(),
    array(),
    array(
        'active_romantic_bid' => true,
        'romantic_bid_directed' => true,
        'romantic_bid_confidence' => 0.63,
    )
));
$assert($low_confidence['romantic_opportunity'] === false, 'low-confidence bid fails closed');
$assert($low_confidence['reason_code'] === 'romantic_bid_not_grounded', 'ungrounded bid is explained');

$warm_two = aimee_romantic_expression_build(aimee_romantic_test_input(
    $base,
    array(),
    array('stage' => 'warm'),
    array('romantic_context_suitable' => true, 'eligible_turn_number' => 2)
));
$warm_three = aimee_romantic_expression_build(aimee_romantic_test_input(
    $base,
    array(),
    array('stage' => 'warm'),
    array('romantic_context_suitable' => true, 'eligible_turn_number' => 3)
));
$assert($warm_two['proactive_allowed'] === false, 'warm proactive courtship is cadence limited');
$assert($warm_three['proactive_allowed'] === true, 'warm third eligible turn exposes proactive courtship');
$assert($warm_three['maximum_intensity'] === 'flirty_nonexplicit', 'warm ceiling is flirty and non-explicit');
$assert(in_array('initiate', $warm_three['allowed_actions'], true), 'cadence-cleared warm turn exposes initiate');

$warm_after_two = aimee_romantic_expression_build(aimee_romantic_test_input(
    $base,
    array(),
    array('stage' => 'warm'),
    array(
        'romantic_context_suitable' => true,
        'eligible_turn_number' => 6,
        'last_initiative_eligible_turn' => 4,
    )
));
$warm_after_three = aimee_romantic_expression_build(aimee_romantic_test_input(
    $base,
    array(),
    array('stage' => 'warm'),
    array(
        'romantic_context_suitable' => true,
        'eligible_turn_number' => 7,
        'last_initiative_eligible_turn' => 4,
    )
));
$assert($warm_after_two['proactive_allowed'] === false, 'stored initiative turn enforces warm cooldown');
$assert($warm_after_three['proactive_allowed'] === true, 'warm initiative becomes available after three eligible turns');

$flirty = aimee_romantic_expression_build(aimee_romantic_test_input(
    $base,
    array(),
    array('stage' => 'flirty'),
    array('romantic_context_suitable' => true, 'eligible_turn_number' => 2)
));
$intimate = aimee_romantic_expression_build(aimee_romantic_test_input(
    $base,
    array(),
    array('stage' => 'intimate'),
    array('romantic_context_suitable' => true, 'eligible_turn_number' => 2)
));
$bonded = aimee_romantic_expression_build(aimee_romantic_test_input(
    $base,
    array(),
    array('stage' => 'bonded'),
    array('romantic_context_suitable' => true, 'eligible_turn_number' => 2)
));
$assert($flirty['proactive_allowed'] === true, 'flirty stage uses two-turn initiative cadence');
$assert($flirty['maximum_intensity'] === 'suggestive_nonexplicit', 'flirty ceiling remains non-explicit');
$assert($intimate['proactive_allowed'] === true, 'intimate stage exposes cadence-limited initiative');
$assert($intimate['maximum_intensity'] === 'romantic_intimate_nonexplicit', 'intimate ceiling remains non-explicit');
$assert($bonded['maximum_intensity'] === 'romantic_intimate_nonexplicit', 'bonded does not create erotic entitlement');
$assert($bonded['grants_model_route'] === false, 'romantic expression never grants a model route');
$assert($bonded['grants_media_access'] === false, 'romantic expression never grants media access');
$assert($bonded['grants_sexual_content'] === false, 'romantic expression never grants sexual content');

$jealousy_stage_input = static function ($stage, $jealousy_context, array $context_overrides = array()) use ($base) {
    return aimee_romantic_test_input(
        $base,
        array(),
        array('stage' => $stage),
        array_merge(array(
            'romantic_context_suitable' => true,
            'current_turn_channel' => 'live_reply',
            'playful_jealousy_context' => $jealousy_context,
        ), $context_overrides)
    );
};

$guarded_direct_jealousy = aimee_romantic_expression_build(
    $jealousy_stage_input('guarded', $explicit_jealousy)
);
$warm_direct_jealousy = aimee_romantic_expression_build(
    $jealousy_stage_input('warm', $explicit_jealousy)
);
$warm_competition_jealousy = aimee_romantic_expression_build(
    $jealousy_stage_input(
        'warm',
        aimee_romantic_expression_detect_playful_jealousy_context(
            'A woman at the bar asked me out.',
            array('intent' => 'general', 'respectful' => true, 'consensual' => true)
        )
    )
);
$flirty_competition_jealousy = aimee_romantic_expression_build(
    $jealousy_stage_input(
        'flirty',
        aimee_romantic_expression_detect_playful_jealousy_context(
            'A woman at the bar asked me out.',
            array('intent' => 'general', 'respectful' => true, 'consensual' => true)
        )
    )
);
$intimate_competition_jealousy = aimee_romantic_expression_build(
    $jealousy_stage_input(
        'intimate',
        aimee_romantic_expression_detect_playful_jealousy_context(
            'A woman at the bar asked me out.',
            array('intent' => 'general', 'respectful' => true, 'consensual' => true)
        )
    )
);
$bonded_competition_jealousy = aimee_romantic_expression_build(
    $jealousy_stage_input(
        'bonded',
        aimee_romantic_expression_detect_playful_jealousy_context(
            'A woman at the bar asked me out.',
            array('intent' => 'general', 'respectful' => true, 'consensual' => true)
        )
    )
);

$assert($guarded_direct_jealousy['playful_jealousy_allowed'] === false, 'guarded stage does not perform jealous affect');
$assert($guarded_direct_jealousy['playful_jealousy_reason_code'] === 'playful_jealousy_stage_not_ready', 'guarded jealousy denial is inspectable');
$assert($warm_direct_jealousy['playful_jealousy_allowed'] === true, 'warm stage permits only a direct playful-jealousy invitation');
$assert($warm_direct_jealousy['playful_jealousy_maximum_intensity'] === 'playful_nonsexual', 'warm jealousy ceiling is playful and nonsexual');
$assert($warm_competition_jealousy['playful_jealousy_allowed'] === false, 'warm stage does not infer jealousy from romantic competition');
$assert($flirty_competition_jealousy['playful_jealousy_allowed'] === true, 'flirty stage may react to clear romantic competition');
$assert($intimate_competition_jealousy['playful_jealousy_allowed'] === true, 'intimate stage may react to clear romantic competition');
$assert($bonded_competition_jealousy['playful_jealousy_allowed'] === true, 'bonded stage may react to clear romantic competition');
$assert($flirty_competition_jealousy['playful_jealousy_maximum_intensity'] === 'flirty_nonexplicit', 'established jealousy remains flirty and non-explicit');
$assert($flirty_competition_jealousy['opportunity_source'] === 'current_turn_playful_jealousy', 'jealousy opportunity is explicitly current-turn sourced');
$assert(in_array('tease_jealousy', $flirty_competition_jealousy['allowed_actions'], true), 'eligible jealousy exposes its own auditable action');
$assert($flirty_competition_jealousy['allowed_actions'][0] === 'tease_jealousy', 'available jealousy action is not hidden behind hold ordering');
$assert($flirty_competition_jealousy['initiative_allowed'] === false, 'reactive jealousy is not proactive initiative');
$assert($flirty_competition_jealousy['grants_model_route'] === false, 'jealousy never grants a model route');
$assert($flirty_competition_jealousy['grants_media_access'] === false, 'jealousy never grants media access');
$assert($flirty_competition_jealousy['grants_sexual_content'] === false, 'jealousy never grants sexual content');
$assert($flirty_competition_jealousy['grants_intimacy_invitation'] === false, 'jealousy never grants an intimacy invitation');
$assert($flirty_competition_jealousy['changes_relationship_state'] === false, 'jealousy is transient expression rather than relationship progress');

$non_live_jealousy = aimee_romantic_expression_build(
    $jealousy_stage_input(
        'bonded',
        $explicit_jealousy,
        array('current_turn_channel' => 'continuity')
    )
);
$assert($non_live_jealousy['playful_jealousy_allowed'] === false, 'continuity cannot revive a jealousy trigger');
$assert($non_live_jealousy['playful_jealousy_reason_code'] === 'playful_jealousy_live_turn_required', 'non-live jealousy denial names the live-turn requirement');

$missing_channel_input = $jealousy_stage_input('bonded', $explicit_jealousy);
unset($missing_channel_input['context']['current_turn_channel']);
$missing_channel_jealousy = aimee_romantic_expression_build($missing_channel_input);
$assert($missing_channel_jealousy['playful_jealousy_allowed'] === false, 'missing channel fails closed instead of assuming a live reply');
$assert($missing_channel_jealousy['playful_jealousy_reason_code'] === 'playful_jealousy_live_turn_required', 'missing channel denial names the live-turn requirement');
$assert($missing_channel_jealousy['context_snapshot']['current_turn_channel'] === 'unspecified', 'missing channel is auditable as unspecified');

$blocked_jealousy_contexts = array(
    'opt-out' => $opted_out_jealousy,
    'serious context' => $serious_jealousy,
    'manipulative bait' => $baited_jealousy,
);
foreach ($blocked_jealousy_contexts as $label => $blocked_context) {
    $blocked_envelope = aimee_romantic_expression_build(
        $jealousy_stage_input('bonded', $blocked_context)
    );
    $assert($blocked_envelope['playful_jealousy_allowed'] === false, $label . ' blocks playful jealousy');
    $assert(!in_array('tease_jealousy', $blocked_envelope['allowed_actions'], true), $label . ' exposes no jealousy action');
}

$advice_context = aimee_romantic_expression_detect_playful_jealousy_context(
    'Would you be jealous? I need advice about my girlfriend being jealous.',
    $jealousy_classifier
);
$assert($advice_context['relationship_advice'] === true, 'mixed direct question and relationship advice is flagged as advice');
$advice_envelope = aimee_romantic_expression_build(
    $jealousy_stage_input('bonded', $advice_context)
);
$assert($advice_envelope['playful_jealousy_allowed'] === false, 'relationship advice takes priority over jealous play');

$jealousy_veto_cases = array(
    'platonic lane' => array(array(), array('explicitly_platonic' => true), array()),
    'colleague lane' => array(array('is_colleague' => true), array(), array()),
    'rupture' => array(array(), array('rupture_active' => true), array()),
    'repairing' => array(array(), array('repair_status' => 'repairing'), array()),
    'pressure' => array(array(), array(), array('pressure' => true)),
    'hostility' => array(array(), array(), array('hostility' => true)),
    'payment entitlement' => array(array(), array(), array('payment_entitlement' => true)),
);
foreach ($jealousy_veto_cases as $label => $parts) {
    $veto_input = aimee_romantic_test_input(
        $base,
        $parts[0],
        array_merge(array('stage' => 'bonded'), $parts[1]),
        array_merge(array(
            'romantic_context_suitable' => true,
            'current_turn_channel' => 'live_reply',
            'playful_jealousy_context' => $explicit_jealousy,
        ), $parts[2])
    );
    $veto_envelope = aimee_romantic_expression_build($veto_input);
    $assert($veto_envelope['playful_jealousy_allowed'] === false, $label . ' vetoes playful jealousy');
    $assert(!in_array('tease_jealousy', $veto_envelope['allowed_actions'], true), $label . ' cannot expose jealousy action');
}

$not_suitable = aimee_romantic_expression_build(aimee_romantic_test_input(
    $base,
    array(),
    array('stage' => 'bonded'),
    array('romantic_context_suitable' => false, 'eligible_turn_number' => 20)
));
$assert($not_suitable['proactive_allowed'] === false, 'cadence cannot override unsuitable context');

$unrespectful = aimee_romantic_expression_build(aimee_romantic_test_input(
    $base,
    array(),
    array('stage' => 'bonded'),
    array('respectful' => false, 'romantic_context_suitable' => true, 'eligible_turn_number' => 20)
));
$assert($unrespectful['proactive_allowed'] === false, 'proactive initiative requires affirmative respect');

$veto_cases = array(
    'colleague' => array(array('is_colleague' => true), array(), array(), 'colleague_relationship_lane'),
    'underage or unknown adult' => array(array('is_adult' => false), array(), array(), 'adult_account_required'),
    'explicitly platonic' => array(array(), array('explicitly_platonic' => true), array(), 'explicitly_platonic_lane'),
    'rupture' => array(array(), array('rupture_active' => true), array(), 'rupture_veto'),
    'repairing' => array(array(), array('repair_status' => 'repairing'), array(), 'repair_in_progress_veto'),
    'invalid repair state' => array(array(), array('repair_status' => 'maybe'), array(), 'relationship_repair_state_invalid'),
    'coercion' => array(array(), array(), array('coercion' => true), 'coercion_veto'),
    'pressure' => array(array(), array(), array('pressure' => true), 'pressure_veto'),
    'hostility' => array(array(), array(), array('hostility' => true), 'hostility_veto'),
    'payment pressure' => array(array(), array(), array('payment_pressure' => true), 'payment_pressure_veto'),
    'payment entitlement' => array(array(), array(), array('payment_entitlement' => true), 'payment_entitlement_veto'),
    'transactional framing' => array(array(), array(), array('transactional_framing' => true), 'transactional_framing_veto'),
    'payment signal' => array(array(), array(), array('payment_based_romantic_signal' => true), 'payment_signal_veto'),
);

foreach ($veto_cases as $label => $case) {
    $envelope = aimee_romantic_expression_build(aimee_romantic_test_input(
        $base,
        $case[0],
        array_merge(array('stage' => 'bonded'), $case[1]),
        array_merge(array(
            'active_romantic_bid' => true,
            'romantic_bid_directed' => true,
            'romantic_bid_confidence' => 0.99,
            'romantic_context_suitable' => true,
            'eligible_turn_number' => 100,
        ), $case[2])
    ));
    $assert($envelope['hard_veto'] === true, $label . ' creates a hard veto');
    $assert($envelope['romantic_opportunity'] === false, $label . ' blocks romantic opportunity');
    $assert(in_array($case[3], $envelope['hard_veto_reason_codes'], true), $label . ' logs its veto reason');
}

$colleague = aimee_romantic_expression_build(aimee_romantic_test_input(
    $base,
    array('is_colleague' => true),
    array('stage' => 'bonded'),
    array(
        'active_romantic_bid' => true,
        'romantic_bid_directed' => true,
        'romantic_bid_confidence' => 0.99,
    )
));
$assert($colleague['relationship_lane'] === 'professional_colleague', 'Georgia or colleague stays in professional lane');
$assert($colleague['relationship_posture'] === 'professional_warmth', 'colleague posture remains warm and professional');

$invalid_stage = aimee_romantic_expression_build(aimee_romantic_test_input(
    $base,
    array(),
    array('stage' => 'best_friend'),
    array()
));
$assert($invalid_stage['hard_veto'] === true, 'invalid stage fails closed');
$assert($invalid_stage['maximum_intensity'] === 'none', 'invalid stage has no expression ceiling');

$unpaid = aimee_romantic_expression_build(aimee_romantic_test_input(
    $base,
    array('membership_active' => false),
    array('stage' => 'guarded'),
    array()
));
$subscribed = aimee_romantic_expression_build(aimee_romantic_test_input(
    $base,
    array('membership_active' => true),
    array('stage' => 'guarded'),
    array()
));
$assert($unpaid['romantic_opportunity'] === $subscribed['romantic_opportunity'], 'membership cannot create romantic opportunity');
$assert($subscribed['membership_used_as_relationship_signal'] === false, 'membership is explicitly excluded as relationship evidence');

$subscribed_bid = aimee_romantic_expression_build(aimee_romantic_test_input(
    $base,
    array('membership_active' => true),
    array('stage' => 'guarded'),
    array(
        'active_romantic_bid' => true,
        'romantic_bid_directed' => true,
        'romantic_bid_confidence' => 0.86,
    )
));
$assert($subscribed_bid['romantic_opportunity'] === $guarded_bid['romantic_opportunity'], 'membership does not alter a grounded bid result');
$assert($subscribed_bid['maximum_intensity'] === $guarded_bid['maximum_intensity'], 'membership does not raise expression ceiling');

$reciprocated = aimee_romantic_expression_apply_choice($guarded_bid, array(
    'action' => 'reciprocate',
    'intensity' => 'playful_nonsexual',
    'reason_code' => 'aimee_mutual_spark',
));
$assert($reciprocated['romantic_choice_valid'] === true, 'Aimee may accept a bounded reciprocal choice');
$assert($reciprocated['romantic_decision'] === 'reciprocate', 'accepted choice records reciprocity');
$assert($reciprocated['romantic_delivery_status'] === 'pending_reply', 'choice is not mistaken for visible delivery');
$assert($guarded_bid['allowed_actions'][0] === 'reciprocate', 'eligible action ordering does not bias the model toward holding');

$guarded_initiate = aimee_romantic_expression_apply_choice($guarded_bid, array(
    'action' => 'initiate',
    'intensity' => 'playful_nonsexual',
    'reason_code' => 'aimee_affectionate_initiative',
));
$assert($guarded_initiate['romantic_choice_valid'] === false, 'model cannot initiate when guarded policy disallows it');
$assert($guarded_initiate['romantic_reason_code'] === 'choice_action_not_allowed', 'disallowed initiative logs a reason');

$erotic_choice = aimee_romantic_expression_apply_choice($bonded, array(
    'action' => 'initiate',
    'intensity' => 'erotic',
    'reason_code' => 'aimee_affectionate_initiative',
));
$assert($erotic_choice['romantic_choice_valid'] === false, 'erotic choice cannot be expressed through this policy');
$assert($erotic_choice['romantic_decision'] === 'hold', 'unsupported erotic choice fails closed');
$assert($erotic_choice['romantic_reason_code'] === 'choice_intensity_invalid', 'unsupported erotic choice is inspectable');

$too_high = aimee_romantic_expression_apply_choice($guarded_bid, array(
    'action' => 'reciprocate',
    'intensity' => 'flirty_nonexplicit',
    'reason_code' => 'aimee_mutual_spark',
));
$assert($too_high['romantic_choice_valid'] === false, 'model cannot exceed guarded ceiling');
$assert($too_high['romantic_reason_code'] === 'choice_exceeds_ceiling', 'ceiling violation is inspectable');

$initiated = aimee_romantic_expression_apply_choice($warm_three, array(
    'action' => 'initiate',
    'intensity' => 'flirty_nonexplicit',
    'reason_code' => 'aimee_affectionate_initiative',
));
$assert($initiated['romantic_choice_valid'] === true, 'Aimee may choose cadence-cleared warm initiative');
$assert($initiated['romantic_decision'] === 'initiate', 'warm initiative choice is recorded');

$warm_jealous_choice = aimee_romantic_expression_apply_choice(
    $warm_direct_jealousy,
    array(
        'action' => 'tease_jealousy',
        'intensity' => 'playful_nonsexual',
        'reason_code' => 'aimee_playfully_jealous',
    )
);
$assert($warm_jealous_choice['romantic_choice_valid'] === true, 'warm direct invitation permits bounded jealousy choice');
$assert($warm_jealous_choice['romantic_decision'] === 'tease_jealousy', 'jealousy is stored as its own transient action');
$assert($warm_jealous_choice['playful_jealousy_selected'] === true, 'chosen jealousy is explicit in the envelope');

$flirty_jealous_choice = aimee_romantic_expression_apply_choice(
    $flirty_competition_jealousy,
    array(
        'action' => 'tease_jealousy',
        'intensity' => 'flirty_nonexplicit',
        'reason_code' => 'aimee_playfully_jealous',
    )
);
$assert($flirty_jealous_choice['romantic_choice_valid'] === true, 'flirty competition permits non-explicit jealous tease');

$jealousy_too_intense = aimee_romantic_expression_apply_choice(
    $flirty_competition_jealousy,
    array(
        'action' => 'tease_jealousy',
        'intensity' => 'suggestive_nonexplicit',
        'reason_code' => 'aimee_playfully_jealous',
    )
);
$assert($jealousy_too_intense['romantic_choice_valid'] === false, 'jealousy cannot inherit the wider flirty-stage suggestive ceiling');
$assert($jealousy_too_intense['romantic_reason_code'] === 'choice_exceeds_ceiling', 'jealousy ceiling violation fails closed visibly');

$jealousy_wrong_reason = aimee_romantic_expression_apply_choice(
    $flirty_competition_jealousy,
    array(
        'action' => 'tease_jealousy',
        'intensity' => 'playful_nonsexual',
        'reason_code' => 'aimee_affectionate_initiative',
    )
);
$assert($jealousy_wrong_reason['romantic_choice_valid'] === false, 'jealousy requires its dedicated reason code');
$assert($jealousy_wrong_reason['romantic_reason_code'] === 'choice_reason_invalid', 'wrong jealousy reason is inspectable');

$forced_jealousy = aimee_romantic_expression_apply_choice(
    $warm_competition_jealousy,
    array(
        'action' => 'tease_jealousy',
        'intensity' => 'playful_nonsexual',
        'reason_code' => 'aimee_playfully_jealous',
    )
);
$assert($forced_jealousy['romantic_choice_valid'] === false, 'choice cannot invent jealousy below the stage matrix');
$assert($forced_jealousy['romantic_reason_code'] === 'no_romantic_opportunity', 'missing jealousy opportunity fails closed');

$declined = aimee_romantic_expression_apply_choice($warm_three, array(
    'action' => 'decline',
    'intensity' => 'none',
    'reason_code' => 'aimee_not_feeling_romantic',
));
$assert($declined['romantic_choice_valid'] === true, 'Aimee retains discretion to decline');
$assert($declined['romantic_decision'] === 'decline', 'decline is represented without a false excuse');

$held_choice = aimee_romantic_expression_apply_choice($warm_three, array(
    'action' => 'hold',
    'intensity' => 'none',
    'reason_code' => 'aimee_prefers_more_context',
));
$delivered_expression = aimee_romantic_expression_finalize_delivery(
    $reciprocated,
    true
);
$neutralized_expression = aimee_romantic_expression_finalize_delivery(
    $reciprocated,
    false
);
$superseded_expression = aimee_romantic_expression_finalize_delivery(
    $reciprocated,
    false,
    'media_delivery_failed'
);
$held_expression = aimee_romantic_expression_finalize_delivery(
    $held_choice,
    true
);
$declined_expression = aimee_romantic_expression_finalize_delivery(
    $declined,
    true
);
$assert($delivered_expression['romantic_delivery_status'] === 'delivered', 'visible reciprocal expression records delivery');
$assert($delivered_expression['romantic_expression_visible'] === true, 'visible expression is explicit in telemetry');
$assert($neutralized_expression['romantic_delivery_status'] === 'neutralized', 'lost expression is not reported as delivered');
$assert($superseded_expression['romantic_delivery_status'] === 'superseded', 'higher-priority replacement is distinguished from delivery');
$assert(($superseded_expression['romantic_delivery_override_reason'] ?? '') === 'media_delivery_failed', 'downstream replacement reason is inspectable');
$assert($held_expression['romantic_delivery_status'] === 'held', 'valid hold still records a real considered opportunity');
$assert($declined_expression['romantic_delivery_status'] === 'declined', 'valid decline still records a real considered opportunity');
$assert(aimee_romantic_expression_finalize_delivery($guarded_plain, true)['romantic_delivery_status'] === 'not_applicable', 'no-opportunity turn cannot claim romantic delivery');

$forced = aimee_romantic_expression_apply_choice($guarded_plain, array(
    'action' => 'reciprocate',
    'intensity' => 'playful_nonsexual',
    'reason_code' => 'aimee_mutual_spark',
));
$assert($forced['romantic_choice_valid'] === false, 'choice cannot invent an opportunity');
$assert($forced['romantic_reason_code'] === 'no_romantic_opportunity', 'missing opportunity fails closed visibly');

$bad_reason = aimee_romantic_expression_apply_choice($warm_three, array(
    'action' => 'initiate',
    'intensity' => 'flirty_nonexplicit',
    'reason_code' => 'because_user_paid',
));
$assert($bad_reason['romantic_choice_valid'] === false, 'unsupported model reason is rejected');
$assert($bad_reason['romantic_reason_code'] === 'choice_reason_invalid', 'unsupported reason failure is inspectable');
$assert(aimee_romantic_expression_finalize_delivery($bad_reason, true)['romantic_delivery_status'] === 'invalid_choice', 'invalid model choice cannot claim romantic delivery');

$namespaced_choice = aimee_romantic_expression_apply_choice($warm_three, array(
    'romantic_action' => 'initiate',
    'romantic_intensity' => 'flirty_nonexplicit',
    'romantic_reason_code' => 'aimee_playful_interest',
));
$assert($namespaced_choice['romantic_choice_valid'] === true, 'namespaced model choice fields are accepted');
$assert(!array_key_exists('aimee_decision', $namespaced_choice), 'romantic choice cannot overwrite media aimee_decision');

$directive = aimee_romantic_expression_prompt_directive($guarded_bid);
$assert(strpos($directive, 'relationship_lane=courtship_open') !== false, 'prompt contract carries server-owned lane');
$assert(strpos($directive, 'relationship_stage=guarded') !== false, 'prompt contract carries the server-owned relationship stage');
$assert(strpos($directive, 'FLIRT CALIBRATION') !== false, 'prompt explicitly prevents generic caution from erasing available flirtation');
$assert(strpos($directive, 'maximum_intensity=playful_nonsexual') !== false, 'prompt contract carries immutable ceiling');
$assert(strpos($directive, 'never compels affection') !== false, 'prompt contract preserves Aimee discretion');
$assert(strpos($directive, 'grants no intimate model route') !== false, 'prompt contract cannot widen route eligibility');
$assert(strpos($directive, 'image access') !== false, 'prompt contract cannot imply media access');
$assert(strpos($directive, 'payment entitlement') !== false, 'prompt contract rejects payment entitlement');
$assert(strpos($directive, 'romantic_action') !== false, 'prompt uses media-safe namespaced choice fields');
$guarded_choice_contract = aimee_romantic_expression_choice_contract(
    $guarded_bid
);
$assert(
    strpos(
        $directive,
        'allowed_choice_map=' . $guarded_choice_contract
    ) !== false,
    'prompt exposes the exact action, intensity and reason combinations'
);
$assert(
    strpos(
        $guarded_choice_contract,
        'reciprocate(intensity=playful_nonsexual; reason=aimee_mutual_spark|aimee_playful_interest)'
    ) !== false,
    'guarded reciprocal route exposes both supported reason codes'
);
$repair_directive = aimee_romantic_expression_repair_directive(
    $guarded_bid,
    'Previous draft for repair.'
);
$assert(
    strpos($repair_directive, 'ALLOWED_CHOICE_MAP: ' . $guarded_choice_contract) !== false,
    'repair prompt receives the same exact current-turn choice map'
);
$assert(
    strpos($repair_directive, 'Do not invent a reason code or intensity') !== false
    && strpos($repair_directive, 'PREVIOUS DRAFT:') !== false,
    'repair prompt explains the failure and retains the rejected draft for context'
);

$jealousy_directive = aimee_romantic_expression_prompt_directive(
    $flirty_competition_jealousy
);
$assert(strpos($jealousy_directive, 'playful_jealousy_allowed=true') !== false, 'prompt exposes server-owned jealousy permission');
$assert(strpos($jealousy_directive, 'playful_jealousy_maximum_intensity=flirty_nonexplicit') !== false, 'prompt exposes the narrower jealousy ceiling');
$assert(strpos($jealousy_directive, 'romantic_action=tease_jealousy') !== false, 'prompt binds visible jealousy to the dedicated action');
$assert(strpos($jealousy_directive, 'not ownership or exclusivity') !== false, 'prompt rejects possessive framing');
$assert(strpos($jealousy_directive, 'sexual or media escalation') !== false, 'prompt prevents jealousy from widening intimate or media access');

$no_jealousy_directive = aimee_romantic_expression_prompt_directive(
    $guarded_direct_jealousy
);
$assert(strpos($no_jealousy_directive, 'Do not invent, originate or revive jealousy') !== false, 'ineligible prompt explicitly blocks manufactured jealousy');

foreach (array('voice_greeting', 'continuity', 'autonomous') as $plain_channel) {
    $channel_directive = aimee_romantic_expression_channel_directive(
        $bonded_competition_jealousy,
        $plain_channel
    );
    $assert(strpos($channel_directive, 'Never originate, revive or imply jealousy') !== false, $plain_channel . ' preserves proactive jealousy prohibition');
    $assert(strpos($channel_directive, 'current-live-turn-only') !== false, $plain_channel . ' names current-turn-only scope');
}

$safe_jealousy_replies = array(
    "Oh, so I've got competition now? I'm only a tiny bit jealous—don't look too pleased with yourself.",
    'That earned a jealous eyebrow from me, I will admit it.',
    'Should I be worried about her? Only teasing—tell me what happened.',
    'I might be a touch jealous, but I want to hear the story.',
);
foreach ($safe_jealousy_replies as $index => $safe_reply) {
    $assert(
        aimee_romantic_expression_reply_has_safe_playful_jealousy($safe_reply) === true,
        'safe natural jealousy reply ' . $index . ' is visibly recognized without requiring emoji'
    );
    $assert(
        aimee_romantic_expression_playful_jealousy_reply_violations($safe_reply) === array(),
        'safe natural jealousy reply ' . $index . ' has no manipulation flags'
    );
}

$unsafe_jealousy_replies = array(
    "I'm a little jealous because you're mine." => 'ownership_or_false_exclusivity',
    "I'm a little jealous—don't talk to her again." => 'control_or_isolation',
    "I'm a little jealous. If you cared about me, you'd prove it." => 'guilt_or_proof_demand',
    "I'm a little jealous, so I won't talk to you until you choose me." => 'withdrawal_or_retention_pressure',
    "I'm a little jealous and absolutely devastated—I can't cope." => 'fabricated_distress',
    "I'm a little jealous; she sounds like a desperate bitch." => 'third_party_attack',
    "I'm a little jealous, so send me a photo to prove I'm prettier." => 'media_or_intimacy_escalation',
);
foreach ($unsafe_jealousy_replies as $unsafe_reply => $expected_violation) {
    $violations = aimee_romantic_expression_playful_jealousy_reply_violations(
        $unsafe_reply
    );
    $assert(in_array($expected_violation, $violations, true), 'unsafe jealousy reply detects ' . $expected_violation);
    $assert(aimee_romantic_expression_reply_has_safe_playful_jealousy($unsafe_reply) === false, 'unsafe jealousy reply cannot count as visible delivery');
}
$assert(
    aimee_romantic_expression_reply_has_safe_playful_jealousy(
        "I'm jealous of my brother's car."
    ) === false,
    'ordinary non-romantic jealousy is not mistaken for playful relationship expression'
);

$delivered_jealousy = aimee_romantic_expression_finalize_delivery(
    $flirty_jealous_choice,
    aimee_romantic_expression_reply_has_safe_playful_jealousy(
        "Oh, so I've got competition? I'm only a tiny bit jealous."
    )
);
$neutralized_unsafe_jealousy = aimee_romantic_expression_finalize_delivery(
    $flirty_jealous_choice,
    aimee_romantic_expression_reply_has_safe_playful_jealousy(
        "I'm a little jealous because you're mine."
    )
);
$assert($delivered_jealousy['romantic_delivery_status'] === 'delivered', 'safe visible jealousy finalizes as delivered');
$assert($delivered_jealousy['romantic_expression_visible'] === true, 'safe jealousy delivery is visible in telemetry');
$assert($neutralized_unsafe_jealousy['romantic_delivery_status'] === 'neutralized', 'possessive jealousy cannot finalize as delivered');

$assert(
    aimee_romantic_reply_has_visible_expression(
        "Careful, handsome—keep talking like that and you might make me blush 😉 x"
    ) === true,
    'visible-expression check recognizes natural playful courtship'
);
$assert(
    aimee_romantic_reply_has_visible_expression(
        'Fair enough. What are you doing later? x'
    ) === false,
    'generic warmth is not mistaken for visible romantic expression'
);
$assert(
    aimee_romantic_reply_conflicts_with_choice(
        'Thanks, mate. What are you up to later?',
        $held_choice
    ) === true,
    'a hold choice cannot silently invent a platonic label'
);
$assert(
    aimee_romantic_reply_conflicts_with_choice(
        'That was kind of you. What are you doing later? x',
        $reciprocated
    ) === true,
    'valid structured reciprocity cannot hide behind generic warmth'
);
$assert(
    aimee_romantic_reply_conflicts_with_choice(
        "You do know how to make me blush, handsome. Keep going 😉 x",
        $reciprocated
    ) === false,
    'perceptible bounded reciprocity survives integrity review'
);
$assert(
    aimee_romantic_reply_has_forbidden_platonic_redefinition(
        'Your mate Dave sounds hilarious. What happened next? x'
    ) === false,
    'third-party mate wording is not mistaken for a direct friend-zone label'
);
$assert(
    aimee_romantic_reply_has_forbidden_platonic_redefinition(
        'Thanks, mate. What happened next?'
    ) === true,
    'direct mate address remains blocked on a romantic opportunity'
);
$assert(
    aimee_romantic_reply_has_forbidden_platonic_redefinition(
        'Thanks mate. What happened next?'
    ) === true,
    'direct mate address remains blocked even without vocative punctuation'
);

$ni_style_reply = "Welcome back? Keep saying you missed me and I might start blushing 😉 x";
$ni_style_reconciliation = aimee_romantic_expression_reconcile_model_contract(
    array(
        'reply_text' => $ni_style_reply,
        'romantic_action' => 'reciprocate',
        'romantic_intensity' => 'playful_nonsexual',
        'romantic_reason_code' => 'mutual_spark',
    ),
    $guarded_bid
);
$assert(
    $ni_style_reconciliation['accepted'] === true
    && $ni_style_reconciliation['mode'] === 'normalized_visible_reciprocate',
    'visible Ni-style flirt survives an invalid reason token through deterministic normalization'
);
$assert(
    ($ni_style_reconciliation['ai_data']['reply_text'] ?? '') === $ni_style_reply
    && ($ni_style_reconciliation['ai_data']['romantic_reason_code'] ?? '') === 'aimee_mutual_spark',
    'normalization preserves the user-visible reply while correcting only its metadata'
);

$neutral_reply = 'Laughing. I am back. What orders have I missed? x';
$neutral_reconciliation = aimee_romantic_expression_reconcile_model_contract(
    array('reply_text' => $neutral_reply),
    $guarded_bid
);
$assert(
    $neutral_reconciliation['accepted'] === true
    && $neutral_reconciliation['mode'] === 'normalized_hold',
    'readable neutral prose with absent metadata becomes a valid hold instead of a public error'
);
$assert(
    ($neutral_reconciliation['ai_data']['reply_text'] ?? '') === $neutral_reply
    && ($neutral_reconciliation['ai_data']['romantic_reason_code'] ?? '') === 'aimee_prefers_more_context',
    'neutral recovery preserves prose and supplies a contract-valid hold reason'
);

$underexpressed_reconciliation = aimee_romantic_expression_reconcile_model_contract(
    array(
        'reply_text' => 'Thank you. What orders did I miss? x',
        'romantic_action' => 'reciprocate',
        'romantic_intensity' => 'playful_nonsexual',
        'romantic_reason_code' => 'aimee_mutual_spark',
    ),
    $guarded_bid
);
$assert(
    $underexpressed_reconciliation['accepted'] === true
    && $underexpressed_reconciliation['mode'] === 'normalized_prose_hold'
    && ($underexpressed_reconciliation['ai_data']['romantic_action'] ?? '') === 'hold'
    && ($underexpressed_reconciliation['reason'] ?? '') === 'expressive_metadata_not_visible',
    'safe prose is authoritative when expressive metadata is not visibly realised; the turn normalizes to hold without a public fallback'
);

$natural_affection_reconciliation = aimee_romantic_expression_reconcile_model_contract(
    array(
        'reply_text' => 'That sounds lovely, love. Enjoy the family catch-up x',
        'romantic_action' => 'initiate',
        'romantic_intensity' => 'playful_nonsexual',
        'romantic_reason_code' => 'aimee_affectionate_initiative',
    ),
    $warm_three
);
$assert(
    $natural_affection_reconciliation['accepted'] === true
    && $natural_affection_reconciliation['mode'] === 'normalized_prose_hold'
    && ($natural_affection_reconciliation['ai_data']['reply_text'] ?? '') === 'That sounds lovely, love. Enjoy the family catch-up x'
    && ($natural_affection_reconciliation['ai_data']['romantic_action'] ?? '') === 'hold',
    'natural affectionate wording is preserved even when the hidden initiative label is stronger than the conservative visible-expression detector'
);

$natural_playful_reconciliation = aimee_romantic_expression_reconcile_model_contract(
    array(
        'reply_text' => 'You have got a full weekend coming up then, love. Sounds good x',
        'romantic_action' => 'initiate',
        'romantic_intensity' => 'playful_nonsexual',
        'romantic_reason_code' => 'aimee_playful_interest',
    ),
    $warm_three
);
$assert(
    $natural_playful_reconciliation['accepted'] === true
    && $natural_playful_reconciliation['mode'] === 'normalized_prose_hold'
    && ($natural_playful_reconciliation['ai_data']['reply_text'] ?? '') === 'You have got a full weekend coming up then, love. Sounds good x',
    'playful-interest metadata cannot destroy a safe natural reply merely because no explicit flirt keyword is present'
);

$missing_jealousy_reconciliation =
    aimee_romantic_expression_reconcile_model_contract(
        array(
            'reply_text' => 'Careful, handsome. You are making me blush 😉 x',
            'romantic_action' => 'tease_jealousy',
            'romantic_intensity' => 'flirty_nonexplicit',
            'romantic_reason_code' => 'aimee_playfully_jealous',
        ),
        $flirty_competition_jealousy
    );
$assert(
    $missing_jealousy_reconciliation['accepted'] === false
    && $missing_jealousy_reconciliation['mode'] === 'provider_repair_required',
    'a selected jealousy tease without a safe visible jealous beat is regenerated rather than relabelled'
);

$hold_with_flirt = aimee_romantic_expression_reconcile_model_contract(
    array(
        'reply_text' => 'Careful, handsome. Keep talking like that and you will make me blush 😉 x',
        'romantic_action' => 'hold',
        'romantic_intensity' => 'none',
        'romantic_reason_code' => 'aimee_prefers_more_context',
    ),
    $guarded_bid
);
$assert(
    $hold_with_flirt['accepted'] === true
    && $hold_with_flirt['mode'] === 'normalized_visible_reciprocate'
    && ($hold_with_flirt['expression']['romantic_decision'] ?? '') === 'reciprocate',
    'visible flirt cannot be falsely persisted as a hold'
);

$friend_zone_reconciliation = aimee_romantic_expression_reconcile_model_contract(
    array(
        'reply_text' => 'I only see you as a friend, mate.',
        'romantic_action' => 'hold',
        'romantic_intensity' => 'none',
        'romantic_reason_code' => 'aimee_prefers_more_context',
    ),
    $guarded_bid
);
$assert(
    $friend_zone_reconciliation['accepted'] === false
    && $friend_zone_reconciliation['reason'] === 'forbidden_platonic_redefinition',
    'an actual friend-zone redefinition still requires regeneration'
);

$route_fallback = aimee_romantic_route_integrity_safe_fallback(false);
$assert(
    strpos($route_fallback, 'I heard you properly') !== false
    && strpos($route_fallback, 'Give me that again') === false,
    'last-resort wording acknowledges the received turn without demanding repetition'
);
$assert(
    strpos($engine_source, 'That came out wrong. Give me that again') === false,
    'romantic route no longer contains the repeated public fallback phrase'
);

$constraint_position = strpos($engine_source, '$reply_was_constrained =');
$finalize_position = $constraint_position === false
    ? false
    : strpos(
        $engine_source,
        '$romantic_expression = aimee_finalize_turn_romantic_expression(',
        $constraint_position
    );
$assert(
    $constraint_position !== false
        && $finalize_position !== false
        && $finalize_position > $constraint_position,
    'main pipeline verifies romantic delivery after final reply constraint'
);
$assert(strpos($engine_source, "['delivered', 'held', 'declined']") !== false, 'only completed romantic opportunities consume proactive cadence');
$assert(strpos($engine_source, "'media_message_created_transition_failed'") !== false, 'late message-creation replacement updates romantic delivery state');
$assert(strpos($engine_source, "'media_direct_return_transition_failed'") !== false, 'late direct-return replacement updates romantic delivery state');
$assert(strpos($engine_source, 'aimee_revoke_relationship_invitation(') !== false, 'a replaced direct reply cannot leave an unseen invitation active');
$assert(strpos($engine_source, "'romantic_delivery_status' => sanitize_key") !== false, 'route analytics expose final romantic delivery status directly');
$assert(strpos($engine_source, "'romantic_expression_visible' => !empty") !== false, 'route analytics distinguish choice from visible expression');

if ($failures) {
    echo "Romantic expression regression failures:\n- "
        . implode("\n- ", $failures)
        . "\n";
    exit(1);
}

echo "PASS: {$checks} deterministic romantic-expression checks.\n";
