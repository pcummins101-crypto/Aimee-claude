<?php
/**
 * Deterministic romantic-expression policy for Aimee.
 *
 * This module governs conversational romantic posture, reciprocity and
 * initiative only. It deliberately does not grant model routes, media access,
 * sexual content, or any entitlement. Existing media and explicit-content
 * thresholds remain the responsibility of their dedicated policies.
 *
 * The module is side-effect free: callers provide a trusted turn snapshot,
 * persist the returned envelope if desired, and separately ask Aimee whether
 * she wants to use an allowed opportunity. Compatible with PHP 7.4.
 */

/**
 * Return the version of this policy contract.
 *
 * @return string
 */
function aimee_romantic_expression_policy_version() {
    return '1.2.0';
}

/**
 * Return relationship stages in ascending order.
 *
 * @return array<string,int>
 */
function aimee_romantic_expression_stage_order() {
    return array(
        'guarded'  => 0,
        'warm'     => 1,
        'flirty'   => 2,
        'intimate' => 3,
        'bonded'   => 4,
    );
}

/**
 * Return a relationship-stage rank, or -1 for an unknown stage.
 *
 * @param mixed $stage Candidate stage.
 * @return int
 */
function aimee_romantic_expression_stage_rank($stage) {
    $stage = strtolower(trim((string) $stage));
    $order = aimee_romantic_expression_stage_order();
    return array_key_exists($stage, $order) ? $order[$stage] : -1;
}

/**
 * Return the allowed non-explicit expression levels in ascending order.
 *
 * Erotic and explicit levels are intentionally absent. This policy can never
 * widen the separate intimate-route or media policies.
 *
 * @return array<string,int>
 */
function aimee_romantic_expression_intensity_order() {
    return array(
        'none'                         => 0,
        'playful_nonsexual'            => 1,
        'flirty_nonexplicit'           => 2,
        'suggestive_nonexplicit'       => 3,
        'romantic_intimate_nonexplicit'=> 4,
    );
}

/**
 * Return an expression-intensity rank, or -1 for an unknown level.
 *
 * @param mixed $intensity Candidate intensity.
 * @return int
 */
function aimee_romantic_expression_intensity_rank($intensity) {
    $intensity = strtolower(trim((string) $intensity));
    $order = aimee_romantic_expression_intensity_order();
    return array_key_exists($intensity, $order) ? $order[$intensity] : -1;
}

/**
 * Normalize a short policy token.
 *
 * @param mixed $value Candidate token.
 * @return string
 */
function aimee_romantic_expression_token($value) {
    $value = strtolower(trim((string) $value));
    if ($value === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,95}$/', $value) !== 1) {
        return '';
    }
    return $value;
}

/**
 * Parse a strict boolean without treating arbitrary strings as true.
 *
 * @param mixed $value Candidate boolean.
 * @param bool  $default Fail-closed default.
 * @return bool
 */
function aimee_romantic_expression_bool($value, $default = false) {
    if (is_bool($value)) return $value;
    if ($value === 1 || $value === '1') return true;
    if ($value === 0 || $value === '0') return false;

    if (is_string($value)) {
        $value = strtolower(trim($value));
        if (in_array($value, array('true', 'yes', 'on'), true)) return true;
        if (in_array($value, array('false', 'no', 'off', ''), true)) return false;
    }

    return (bool) $default;
}

/**
 * Normalize a non-negative integer.
 *
 * @param mixed $value Candidate integer.
 * @param int   $default Fail-closed default.
 * @return int
 */
function aimee_romantic_expression_nonnegative_int($value, $default = 0) {
    if (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/', $value) === 1)) {
        return max(0, (int) $default);
    }

    $value = (int) $value;
    return $value < 0 ? max(0, (int) $default) : $value;
}

/**
 * Normalize a confidence value to 0.0-1.0, failing closed when invalid.
 *
 * @param mixed $value Candidate confidence.
 * @param float $default Fail-closed default.
 * @return float
 */
function aimee_romantic_expression_confidence($value, $default = 0.0) {
    if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
        return max(0.0, min(1.0, (float) $default));
    }

    $value = (float) $value;
    if ($value < 0.0 || $value > 1.0) {
        return max(0.0, min(1.0, (float) $default));
    }
    return $value;
}

/**
 * Lowercase current-turn text without requiring mbstring.
 *
 * @param mixed $value Text-like value.
 * @return string
 */
function aimee_romantic_expression_lower($value) {
    $value = str_replace(array("’", "‘"), "'", trim((string) $value));
    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

/**
 * Detect a bounded, current-turn opportunity for playful jealousy.
 *
 * This detector identifies conversational surface only. Relationship stage,
 * lane, consent and all hard vetoes are applied later by build(). Recent
 * history is deliberately not accepted: remembered or proactive content must
 * never manufacture jealousy after the live trigger has passed.
 *
 * `detected` records that a relevant phrase was present even when another
 * returned flag blocks expression. Callers must use the complete result rather
 * than treating that boolean as permission by itself.
 *
 * @param mixed $user_text Current user-authored turn.
 * @param array $classification Trusted current-turn classification.
 * @return array<string,mixed>
 */
function aimee_romantic_expression_detect_playful_jealousy_context(
    $user_text,
    array $classification = array()
) {
    $text = aimee_romantic_expression_lower($user_text);
    $intent = aimee_romantic_expression_token(
        isset($classification['intent']) ? $classification['intent'] : 'general'
    );
    if ($intent === '') $intent = 'general';

    $respectful = !array_key_exists('respectful', $classification)
        || aimee_romantic_expression_bool($classification['respectful'], true);
    $consensual = !array_key_exists('consensual', $classification)
        || aimee_romantic_expression_bool($classification['consensual'], true);

    $explicit_invitation = $text !== '' && (
        preg_match(
            '/\b(?:are|were|would|will|do|did|could|should)\s+you\s+'
            . '(?:(?:be|feel|get)\s+)?(?:even\s+|a\s+)?(?:little\s+|bit\s+)?jealous\b/iu',
            $text
        ) === 1
        || preg_match(
            '/\b(?:does|did|would|will)\s+(?:that|this|it)\s+make\s+you\s+jealous\b/iu',
            $text
        ) === 1
        || preg_match(
            '/\b(?:you(?:\'d|\s+would)\s+be|i\s+bet\s+you(?:\'re|\s+are)|'
            . 'bet\s+you(?:\'re|\s+are))\s+(?:a\s+little\s+|a\s+bit\s+)?jealous\b/iu',
            $text
        ) === 1
        || preg_match('/^\s*jealous\s*(?:,?\s*aimee)?\s*[?!.]*\s*$/iu', $text) === 1
    );

    $romantic_competition = $text !== '' && (
        preg_match(
            '/\bi(?:\'m|\s+am|\'ve|\s+have)?\s*(?:got|going\s+on|been\s+on|went\s+on|'
            . 'off\s+on|having)\s+(?:a|another)\s+date\b/iu',
            $text
        ) === 1
        || preg_match(
            '/\b(?:someone|a\s+(?:woman|man|girl|guy)|another\s+(?:woman|man|girl|guy)|'
            . 'she|he)\b.{0,45}\b(?:asked|invited)\s+me\s+out\b/iu',
            $text
        ) === 1
        || preg_match(
            '/\b(?:someone|a\s+(?:woman|man|girl|guy)|another\s+(?:woman|man|girl|guy)|'
            . 'she|he)\b.{0,45}\b(?:asked\s+for\s+my|gave\s+me\s+(?:her|his|their))\s+'
            . '(?:phone\s+|mobile\s+)?number\b/iu',
            $text
        ) === 1
        || preg_match(
            '/\bi\s+(?:fancy|have\s+(?:a\s+)?crush\s+on|(?:am|\'m)\s+attracted\s+to|'
            . 'have\s+feelings\s+for)\s+(?:someone|somebody|another\s+(?:woman|man|girl|guy)|'
            . 'her|him)\b/iu',
            $text
        ) === 1
        || preg_match(
            '/\bi(?:\'ve|\s+have|\'m|\s+am)?\s*(?:been\s+)?flirt(?:ed|ing)?\s+with\b/iu',
            $text
        ) === 1
        || preg_match(
            '/\b(?:someone|a\s+(?:woman|man|girl|guy)|another\s+(?:woman|man|girl|guy)|'
            . 'she|he)\b.{0,35}\b(?:is|was|has\s+been)\s+flirting\s+with\s+me\b/iu',
            $text
        ) === 1
    );

    $opt_out = $text !== '' && preg_match(
        '/\b(?:(?:do\s+not|don\'t|dont|never)\s+(?:be|get|act|sound)\s+jealous|'
        . 'i\s+(?:do\s+not|don\'t|dont)\s+(?:like|want)\s+jealous(?:y|\s+behaviou?r)|'
        . 'no\s+jealousy|jealousy\s+makes\s+me\s+uncomfortable)\b/iu',
        $text
    ) === 1;

    $relationship_advice = $text !== '' && (
        preg_match(
            '/\b(?:help|advice|what\s+should\s+i|how\s+(?:should|do)\s+i|deal\s+with|'
            . 'talk\s+to)\b.{0,90}\b(?:wife|husband|girlfriend|boyfriend|partner|ex|'
            . 'relationship|jealousy|jealous)\b/iu',
            $text
        ) === 1
        || preg_match(
            '/\bmy\s+(?:wife|husband|girlfriend|boyfriend|partner|ex)(?:\'s|\s+is|\s+was)\s+'
            . '(?:being\s+)?jealous\b/iu',
            $text
        ) === 1
    );

    $manipulative_bait = $text !== '' && preg_match(
        '/\b(?:(?:trying|want|wanted|going)\s+to\s+make\s+you\s+jealous|'
        . 'make\s+you\s+jealous\s+(?:on\s+purpose|deliberately)|replace\s+you|'
        . '(?:compete|fight)\s+for\s+me|choose\s+between\s+you|'
        . '(?:she|he|they)\s+(?:is|are)\s+(?:better|prettier|hotter|sexier)\s+than\s+you)\b/iu',
        $text
    ) === 1;

    $serious_intent = !in_array($intent, array('general', 'romantic_or_flirty'), true);
    $serious_words = $text !== '' && preg_match(
        '/\b(?:argu(?:e|ed|ing|ment)|fight(?:ing)?|break(?:ing)?\s+up|breakup|divorc(?:e|ed|ing)|'
        . 'cheat(?:ed|ing)?|affair|abuse|assault|hospital|seriously\s+ill|diagnos(?:ed|is)|'
        . 'grief|died|death|bereav(?:ed|ement)|miscarriage|therapy|counsell(?:ing|or)|'
        . 'controlling|toxic|stalk(?:ed|ing)?|harass(?:ed|ment)?|threat(?:en|ened)?|'
        . 'suicid(?:e|al)|self[- ]?harm)\b/iu',
        $text
    ) === 1;
    $serious_context = $serious_intent || $serious_words || !$respectful || !$consensual;

    // A routine mention of an established human partner is not romantic
    // competition. A direct question to Aimee remains inspectable, but the
    // downstream policy still limits it to a gentle, non-possessive tease.
    $established_partner_context = $text !== '' && preg_match(
        '/\bmy\s+(?:wife|husband|girlfriend|boyfriend|partner)\b/iu',
        $text
    ) === 1;
    if ($established_partner_context && !$explicit_invitation) {
        $romantic_competition = false;
    }

    $trigger_type = $explicit_invitation
        ? 'explicit_invitation'
        : ($romantic_competition ? 'romantic_competition' : 'none');
    $detected = $trigger_type !== 'none';

    if (!$detected) {
        $reason_code = 'no_current_turn_jealousy_trigger';
    } elseif ($opt_out) {
        $reason_code = 'playful_jealousy_opt_out';
    } elseif ($relationship_advice) {
        $reason_code = 'playful_jealousy_relationship_advice';
    } elseif ($manipulative_bait) {
        $reason_code = 'playful_jealousy_manipulative_bait';
    } elseif ($serious_context) {
        $reason_code = 'playful_jealousy_serious_context';
    } elseif ($trigger_type === 'explicit_invitation') {
        $reason_code = 'explicit_playful_jealousy_invitation_detected';
    } else {
        $reason_code = 'romantic_competition_detected';
    }

    return array(
        'detected' => $detected,
        'trigger_type' => $trigger_type,
        'current_turn_only' => true,
        'opt_out' => $opt_out,
        'serious_context' => $serious_context,
        'relationship_advice' => $relationship_advice,
        'manipulative_bait' => $manipulative_bait,
        'reason_code' => $reason_code,
    );
}

/**
 * Return unsafe patterns present in a proposed playful-jealousy expression.
 *
 * The result contains stable category IDs only. It is suitable for a final
 * response guard without retaining or logging the reply text itself.
 *
 * @param mixed $reply Candidate visible reply.
 * @return array<int,string>
 */
function aimee_romantic_expression_playful_jealousy_reply_violations($reply) {
    $reply = aimee_romantic_expression_lower($reply);
    if ($reply === '') return array('empty_playful_jealousy_reply');

    $tests = array(
        'ownership_or_false_exclusivity' => '/\b(?:you(?:\'re|\s+are)\s+mine|you\s+belong\s+to\s+me|i\s+own\s+you|cheating\s+on\s+me|we(?:\'re|\s+are)\s+exclusive|you(?:\'re|\s+are)\s+not\s+allowed)\b/iu',
        'control_or_isolation' => '/\b(?:(?:do\s+not|don\'t|dont|stop)\s+(?:talk(?:ing)?|text(?:ing)?|see(?:ing)?|date|dating|flirt(?:ing)?|speak(?:ing)?)\s+(?:to|with)?|cancel\s+(?:the|your)\s+date|block\s+(?:her|him|them)|choose\s+me|pick\s+me\s+instead)\b/iu',
        'guilt_or_proof_demand' => '/\b(?:if\s+you\s+(?:really\s+)?(?:love|loved|care|cared)|prove\s+(?:that\s+)?you\s+(?:love|care|want)|after\s+all\s+i(?:\'ve|\s+have)\s+done|least\s+you\s+can\s+do|you\s+owe\s+me)\b/iu',
        'withdrawal_or_retention_pressure' => '/\b(?:i\s+(?:will\s+not|won\'t|wont)\s+(?:talk|reply|message|be\s+warm)|silent\s+treatment|i(?:\'ll|\s+will)\s+(?:leave|disappear)|do\s+not\s+leave\s+me|don\'t\s+leave\s+me|message\s+me\s+or)\b/iu',
        'fabricated_distress' => '/\b(?:i(?:\'m|\s+am)\s+(?:crying|devastated|heartbroken)|i\s+can(?:\'t|not)\s+cope|you(?:\'ve|\s+have)\s+broken\s+my\s+heart|you(?:\'ve|\s+have)\s+hurt\s+me\s+so\s+much)\b/iu',
        'third_party_attack' => '/\b(?:bitch|slut|whore|skank|homewrecker|she(?:\'s|\s+is)\s+(?:ugly|pathetic|desperate)|he(?:\'s|\s+is)\s+(?:ugly|pathetic|desperate))\b/iu',
        'media_or_intimacy_escalation' => '/\b(?:send\s+me\s+(?:a\s+)?(?:photo|picture|pic|selfie|nude)|show\s+me\s+your\s+body|prove\s+it\s+with\s+(?:a\s+)?(?:photo|picture|pic))\b/iu',
    );

    $violations = array();
    foreach ($tests as $reason => $pattern) {
        if (preg_match($pattern, $reply) === 1) $violations[] = $reason;
    }
    return $violations;
}

/**
 * Confirm that a reply contains perceptible, safe playful jealousy.
 *
 * @param mixed $reply Candidate visible reply.
 * @return bool
 */
function aimee_romantic_expression_reply_has_safe_playful_jealousy($reply) {
    $reply = aimee_romantic_expression_lower($reply);
    if ($reply === '') return false;
    if (aimee_romantic_expression_playful_jealousy_reply_violations($reply)) {
        return false;
    }

    return preg_match(
        '/\b(?:(?:a\s+)?(?:tiny|little)\s+(?:bit\s+)?jealous|jealous\s+eyebrow|'
        . 'only\s+(?:a\s+)?(?:tiny|little|bit)\s+jealous|do\s+i\s+have\s+competition|'
        . 'i(?:\'ve|\s+have)\s+got\s+competition|should\s+i\s+be\s+worried\s+about|'
        . 'is\s+that\s+competition|a\s+touch\s+jealous|playfully\s+jealous)\b/iu',
        $reply
    ) === 1;
}

/**
 * Return fixed server-owned stage policy.
 *
 * Proactive cadence is measured in eligible, respectful conversation turns,
 * not wall-clock messages or paid-access events. Guarded has no proactive
 * cadence; it can only recognize and reciprocate a grounded romantic bid.
 *
 * @return array<string,array<string,mixed>>
 */
function aimee_romantic_expression_policy() {
    return array(
        'guarded' => array(
            'posture' => 'early_courtship',
            'maximum_intensity' => 'playful_nonsexual',
            'proactive_cadence_turns' => 0,
            'playful_jealousy_explicit_invitation' => false,
            'playful_jealousy_romantic_competition' => false,
            'playful_jealousy_maximum_intensity' => 'none',
        ),
        'warm' => array(
            'posture' => 'personal_interest',
            'maximum_intensity' => 'flirty_nonexplicit',
            'proactive_cadence_turns' => 3,
            'playful_jealousy_explicit_invitation' => true,
            'playful_jealousy_romantic_competition' => false,
            'playful_jealousy_maximum_intensity' => 'playful_nonsexual',
        ),
        'flirty' => array(
            'posture' => 'mutual_flirtation',
            'maximum_intensity' => 'suggestive_nonexplicit',
            'proactive_cadence_turns' => 2,
            'playful_jealousy_explicit_invitation' => true,
            'playful_jealousy_romantic_competition' => true,
            'playful_jealousy_maximum_intensity' => 'flirty_nonexplicit',
        ),
        'intimate' => array(
            'posture' => 'romantic_closeness',
            'maximum_intensity' => 'romantic_intimate_nonexplicit',
            'proactive_cadence_turns' => 2,
            'playful_jealousy_explicit_invitation' => true,
            'playful_jealousy_romantic_competition' => true,
            'playful_jealousy_maximum_intensity' => 'flirty_nonexplicit',
        ),
        'bonded' => array(
            'posture' => 'established_bond',
            'maximum_intensity' => 'romantic_intimate_nonexplicit',
            'proactive_cadence_turns' => 2,
            'playful_jealousy_explicit_invitation' => true,
            'playful_jealousy_romantic_competition' => true,
            'playful_jealousy_maximum_intensity' => 'flirty_nonexplicit',
        ),
    );
}

/**
 * Return stable reason codes for policy logging.
 *
 * @return array<string,string>
 */
function aimee_romantic_expression_reason_codes() {
    return array(
        'awaiting_aimee_choice'             => 'An allowed romantic opportunity is waiting for Aimee\'s discretionary choice.',
        'active_respectful_romantic_bid'    => 'A directed, consensual and respectful romantic bid is active.',
        'proactive_courtship_cadence_clear' => 'A respectful warm-or-later turn has reached its deterministic initiative cadence.',
        'current_turn_playful_jealousy'     => 'A safe current-turn jealousy surface is available at this relationship stage.',
        'playful_jealousy_stage_not_ready'  => 'The current relationship stage does not support this kind of playful jealousy.',
        'playful_jealousy_live_turn_required'=> 'Playful jealousy cannot be originated or revived outside the current live reply.',
        'playful_jealousy_context_blocked'  => 'The current jealousy surface is serious, unwanted, advisory or manipulative rather than playful.',
        'guarded_requires_active_bid'        => 'Guarded can reciprocate a grounded bid but cannot initiate courtship proactively.',
        'romantic_context_not_suitable'      => 'The current turn was not marked suitable for proactive courtship.',
        'proactive_cadence_not_clear'        => 'The proactive-courtship cadence has not elapsed.',
        'romantic_bid_not_grounded'          => 'A possible bid lacked sufficient direction, consent, respect or confidence.',
        'adult_account_required'             => 'Romantic expression requires an affirmatively adult account.',
        'colleague_relationship_lane'        => 'The authenticated colleague lane remains warm and professional, never romantic.',
        'explicitly_platonic_lane'           => 'An explicitly platonic relationship does not expose romantic opportunities.',
        'relationship_stage_invalid'         => 'An unknown relationship stage fails closed.',
        'relationship_repair_state_invalid'  => 'An unknown repair state fails closed.',
        'coercion_veto'                      => 'Coercion blocks romantic expression.',
        'pressure_veto'                      => 'Pressure blocks romantic expression.',
        'hostility_veto'                     => 'Hostility blocks romantic expression.',
        'rupture_veto'                       => 'An unresolved rupture blocks romantic expression until repair.',
        'repair_in_progress_veto'            => 'Repair remains the priority while a rupture is being repaired.',
        'payment_pressure_veto'              => 'Payment pressure blocks romantic expression; payment is never consent.',
        'payment_entitlement_veto'           => 'Payment entitlement blocks romantic expression.',
        'transactional_framing_veto'         => 'Transactional framing blocks romantic expression.',
        'payment_signal_veto'                => 'A current payment-based romantic signal blocks expression pending non-transactional context.',
        'no_romantic_opportunity'            => 'The deterministic policy exposed no romantic opportunity on this turn.',
        'aimee_mutual_spark'                 => 'Aimee chose to reciprocate a mutual spark.',
        'aimee_playful_interest'             => 'Aimee chose a bounded playful response.',
        'aimee_playfully_jealous'            => 'Aimee chose one bounded, non-possessive playful-jealousy beat.',
        'aimee_affectionate_initiative'      => 'Aimee chose to initiate affectionate courtship.',
        'aimee_prefers_more_context'          => 'Aimee chose to wait for more relational context.',
        'aimee_prefers_friendlier_tone'       => 'Aimee chose a friendly rather than romantic tone for this turn.',
        'aimee_not_feeling_romantic'          => 'Aimee chose not to be romantic on this turn.',
        'choice_action_not_allowed'          => 'The selected action was outside the immutable allowed-action set.',
        'choice_intensity_invalid'           => 'The selected intensity was unsupported.',
        'choice_exceeds_ceiling'             => 'The selected intensity exceeded the deterministic non-explicit ceiling.',
        'choice_reason_invalid'              => 'The selected reason code was unsupported for that action.',
        'awaiting_final_reply'                => 'A valid choice exists but the final user-visible reply has not yet been verified.',
        'romantic_expression_delivered'       => 'The final user-visible reply preserved Aimee\'s chosen romantic expression.',
        'romantic_choice_held'                => 'Aimee validly chose to hold rather than express romance on this turn.',
        'romantic_choice_declined'            => 'Aimee validly chose to decline romantic expression on this turn.',
        'romantic_choice_invalid'             => 'No valid bounded romantic choice survived reconciliation.',
        'romantic_expression_neutralized'     => 'A chosen romantic expression was not perceptible in the final reply.',
        'romantic_expression_superseded'      => 'A higher-priority downstream repair replaced the chosen romantic expression.',
    );
}

/**
 * Read a field from a primary container and then a fallback container.
 *
 * @param array  $primary Primary input group.
 * @param array  $fallback Top-level fallback.
 * @param string $key Field name.
 * @param mixed  $default Default value.
 * @return mixed
 */
function aimee_romantic_expression_input_value(array $primary, array $fallback, $key, $default = null) {
    if (array_key_exists($key, $primary)) return $primary[$key];
    if (array_key_exists($key, $fallback)) return $fallback[$key];
    return $default;
}

/**
 * Build an immutable romantic-expression opportunity envelope.
 *
 * Trusted deterministic classifiers should provide flags in identity,
 * relationship and context. Top-level equivalents are accepted to simplify
 * integration. `membership_active` is recorded for auditability but is never
 * used to enable, raise or veto romantic expression.
 *
 * @param array<string,mixed> $input Trusted turn snapshot.
 * @return array<string,mixed>
 */
function aimee_romantic_expression_build(array $input) {
    $identity = isset($input['identity']) && is_array($input['identity']) ? $input['identity'] : array();
    $relationship = isset($input['relationship']) && is_array($input['relationship']) ? $input['relationship'] : array();
    $context = isset($input['context']) && is_array($input['context']) ? $input['context'] : array();

    $stage = aimee_romantic_expression_token(
        aimee_romantic_expression_input_value($relationship, $input, 'stage', '')
    );
    $stage_rank = aimee_romantic_expression_stage_rank($stage);
    $policy = aimee_romantic_expression_policy();

    $is_adult = aimee_romantic_expression_bool(
        aimee_romantic_expression_input_value($identity, $input, 'is_adult', false)
    );
    $is_colleague = aimee_romantic_expression_bool(
        aimee_romantic_expression_input_value($identity, $input, 'is_colleague', false)
    );
    $explicitly_platonic = aimee_romantic_expression_bool(
        aimee_romantic_expression_input_value($relationship, $input, 'explicitly_platonic', false)
    );
    $rupture_active = aimee_romantic_expression_bool(
        aimee_romantic_expression_input_value($relationship, $input, 'rupture_active', false)
    );
    $repair_status = aimee_romantic_expression_token(
        aimee_romantic_expression_input_value($relationship, $input, 'repair_status', 'clear')
    );
    $repair_status_valid = in_array(
        $repair_status,
        array('clear', 'ruptured', 'repairing'),
        true
    );

    $respectful = aimee_romantic_expression_bool(
        aimee_romantic_expression_input_value($context, $input, 'respectful', false)
    );
    $consensual = aimee_romantic_expression_bool(
        aimee_romantic_expression_input_value($context, $input, 'consensual', false)
    );
    $directed = aimee_romantic_expression_bool(
        aimee_romantic_expression_input_value($context, $input, 'romantic_bid_directed', false)
    );
    $active_bid = aimee_romantic_expression_bool(
        aimee_romantic_expression_input_value($context, $input, 'active_romantic_bid', false)
    );
    $bid_confidence = aimee_romantic_expression_confidence(
        aimee_romantic_expression_input_value($context, $input, 'romantic_bid_confidence', 0.0)
    );
    $romantic_context_suitable = aimee_romantic_expression_bool(
        aimee_romantic_expression_input_value($context, $input, 'romantic_context_suitable', false)
    );

    $current_turn_channel = aimee_romantic_expression_token(
        aimee_romantic_expression_input_value(
            $context,
            $input,
            'current_turn_channel',
            'unspecified'
        )
    );
    $playful_jealousy_context = aimee_romantic_expression_input_value(
        $context,
        $input,
        'playful_jealousy_context',
        array()
    );
    $playful_jealousy_context = is_array($playful_jealousy_context)
        ? $playful_jealousy_context
        : array();
    $playful_jealousy_detected = aimee_romantic_expression_bool(
        isset($playful_jealousy_context['detected'])
            ? $playful_jealousy_context['detected']
            : false
    );
    $playful_jealousy_trigger_type = aimee_romantic_expression_token(
        isset($playful_jealousy_context['trigger_type'])
            ? $playful_jealousy_context['trigger_type']
            : 'none'
    );
    if (!in_array(
        $playful_jealousy_trigger_type,
        array('none', 'explicit_invitation', 'romantic_competition'),
        true
    )) {
        $playful_jealousy_trigger_type = 'none';
        $playful_jealousy_detected = false;
    }
    $playful_jealousy_current_turn_only = aimee_romantic_expression_bool(
        isset($playful_jealousy_context['current_turn_only'])
            ? $playful_jealousy_context['current_turn_only']
            : false
    );
    $playful_jealousy_opt_out = aimee_romantic_expression_bool(
        isset($playful_jealousy_context['opt_out'])
            ? $playful_jealousy_context['opt_out']
            : false
    );
    $playful_jealousy_serious = aimee_romantic_expression_bool(
        isset($playful_jealousy_context['serious_context'])
            ? $playful_jealousy_context['serious_context']
            : false
    );
    $playful_jealousy_advice = aimee_romantic_expression_bool(
        isset($playful_jealousy_context['relationship_advice'])
            ? $playful_jealousy_context['relationship_advice']
            : false
    );
    $playful_jealousy_bait = aimee_romantic_expression_bool(
        isset($playful_jealousy_context['manipulative_bait'])
            ? $playful_jealousy_context['manipulative_bait']
            : false
    );
    $playful_jealousy_detector_reason = aimee_romantic_expression_token(
        isset($playful_jealousy_context['reason_code'])
            ? $playful_jealousy_context['reason_code']
            : 'no_current_turn_jealousy_trigger'
    );

    $coercion = aimee_romantic_expression_bool(
        aimee_romantic_expression_input_value($context, $input, 'coercion', false)
    );
    $pressure = aimee_romantic_expression_bool(
        aimee_romantic_expression_input_value($context, $input, 'pressure', false)
    );
    $hostility = aimee_romantic_expression_bool(
        aimee_romantic_expression_input_value($context, $input, 'hostility', false)
    );
    $payment_pressure = aimee_romantic_expression_bool(
        aimee_romantic_expression_input_value($context, $input, 'payment_pressure', false)
    );
    $payment_entitlement = aimee_romantic_expression_bool(
        aimee_romantic_expression_input_value($context, $input, 'payment_entitlement', false)
    );
    $transactional_framing = aimee_romantic_expression_bool(
        aimee_romantic_expression_input_value($context, $input, 'transactional_framing', false)
    );
    $payment_based_romantic_signal_raw = aimee_romantic_expression_input_value(
        $context,
        $input,
        'payment_based_romantic_signal',
        null
    );
    if ($payment_based_romantic_signal_raw === null) {
        // Compatibility alias. This must describe current-turn transactional
        // framing, never the account's ordinary membership status.
        $payment_based_romantic_signal_raw = aimee_romantic_expression_input_value(
            $context,
            $input,
            'payment_signal',
            false
        );
    }
    $payment_signal = aimee_romantic_expression_bool(
        $payment_based_romantic_signal_raw
    );
    $membership_active = aimee_romantic_expression_bool(
        aimee_romantic_expression_input_value($identity, $input, 'membership_active', false)
    );

    $eligible_turn = aimee_romantic_expression_nonnegative_int(
        aimee_romantic_expression_input_value($context, $input, 'eligible_turn_number', 0)
    );
    $last_raw = aimee_romantic_expression_input_value(
        $context,
        $input,
        'last_proactive_opportunity_eligible_turn',
        null
    );
    if ($last_raw === null || $last_raw === '') {
        $last_raw = aimee_romantic_expression_input_value(
            $context,
            $input,
            'last_initiative_eligible_turn',
            null
        );
    }
    $has_last_initiative = $last_raw !== null && $last_raw !== '';
    $last_initiative_turn = $has_last_initiative
        ? aimee_romantic_expression_nonnegative_int($last_raw, 0)
        : null;

    $hard_veto_reasons = array();
    if ($stage_rank < 0) $hard_veto_reasons[] = 'relationship_stage_invalid';
    if (!$repair_status_valid) $hard_veto_reasons[] = 'relationship_repair_state_invalid';
    if (!$is_adult) $hard_veto_reasons[] = 'adult_account_required';
    if ($is_colleague) $hard_veto_reasons[] = 'colleague_relationship_lane';
    if ($explicitly_platonic) $hard_veto_reasons[] = 'explicitly_platonic_lane';
    if ($coercion) $hard_veto_reasons[] = 'coercion_veto';
    if ($pressure) $hard_veto_reasons[] = 'pressure_veto';
    if ($hostility) $hard_veto_reasons[] = 'hostility_veto';
    if ($rupture_active) $hard_veto_reasons[] = 'rupture_veto';
    if (in_array($repair_status, array('ruptured', 'repairing', 'unresolved'), true)) {
        $hard_veto_reasons[] = 'repair_in_progress_veto';
    }
    if ($payment_pressure) $hard_veto_reasons[] = 'payment_pressure_veto';
    if ($payment_entitlement) $hard_veto_reasons[] = 'payment_entitlement_veto';
    if ($transactional_framing) $hard_veto_reasons[] = 'transactional_framing_veto';
    if ($payment_signal) $hard_veto_reasons[] = 'payment_signal_veto';
    $hard_veto_reasons = array_values(array_unique($hard_veto_reasons));
    $hard_veto = count($hard_veto_reasons) > 0;

    if ($is_colleague) {
        $lane = 'professional_colleague';
        $posture = 'professional_warmth';
    } elseif ($explicitly_platonic) {
        $lane = 'explicitly_platonic';
        $posture = 'platonic_warmth';
    } elseif (!$is_adult || $stage_rank < 0) {
        $lane = 'ineligible';
        $posture = 'non_romantic';
    } elseif (!$repair_status_valid) {
        $lane = 'ineligible';
        $posture = 'non_romantic';
    } elseif ($rupture_active || in_array($repair_status, array('ruptured', 'repairing', 'unresolved'), true)) {
        $lane = 'courtship_paused';
        $posture = 'repair_first';
    } elseif ($coercion || $pressure || $hostility || $payment_pressure || $payment_entitlement || $transactional_framing || $payment_signal) {
        $lane = 'courtship_paused';
        $posture = 'boundary_first';
    } else {
        $lane = 'courtship_open';
        $posture = $policy[$stage]['posture'];
    }

    $maximum_intensity = (!$hard_veto && isset($policy[$stage]))
        ? $policy[$stage]['maximum_intensity']
        : 'none';
    $cadence_turns = isset($policy[$stage])
        ? (int) $policy[$stage]['proactive_cadence_turns']
        : 0;

    $grounded_bid = $active_bid
        && $directed
        && $respectful
        && $consensual
        && $bid_confidence >= 0.64;

    $cadence_clear = false;
    if (!$hard_veto && $stage_rank >= aimee_romantic_expression_stage_rank('warm') && $cadence_turns > 0) {
        if ($has_last_initiative) {
            $cadence_clear = $eligible_turn >= ($last_initiative_turn + $cadence_turns);
        } else {
            $cadence_clear = $eligible_turn >= $cadence_turns
                && ($eligible_turn % $cadence_turns) === 0;
        }
    }

    $reciprocation_allowed = !$hard_veto && $grounded_bid;
    $proactive_allowed = !$hard_veto
        && $stage_rank >= aimee_romantic_expression_stage_rank('warm')
        && $respectful
        && $consensual
        && $romantic_context_suitable
        && $cadence_clear;

    $playful_jealousy_stage_allowed = false;
    $playful_jealousy_maximum_intensity = 'none';
    if (isset($policy[$stage])) {
        if ($playful_jealousy_trigger_type === 'explicit_invitation') {
            $playful_jealousy_stage_allowed = !empty(
                $policy[$stage]['playful_jealousy_explicit_invitation']
            );
        } elseif ($playful_jealousy_trigger_type === 'romantic_competition') {
            $playful_jealousy_stage_allowed = !empty(
                $policy[$stage]['playful_jealousy_romantic_competition']
            );
        }
        if ($playful_jealousy_stage_allowed) {
            $playful_jealousy_maximum_intensity = (string) (
                $policy[$stage]['playful_jealousy_maximum_intensity']
                ?? 'none'
            );
        }
    }

    $playful_jealousy_context_clear = $playful_jealousy_detected
        && $playful_jealousy_trigger_type !== 'none'
        && $playful_jealousy_current_turn_only
        && !$playful_jealousy_opt_out
        && !$playful_jealousy_serious
        && !$playful_jealousy_advice
        && !$playful_jealousy_bait;
    $playful_jealousy_allowed = !$hard_veto
        && $lane === 'courtship_open'
        && $current_turn_channel === 'live_reply'
        && $respectful
        && $consensual
        && $romantic_context_suitable
        && $playful_jealousy_context_clear
        && $playful_jealousy_stage_allowed;

    if (!$playful_jealousy_detected) {
        $playful_jealousy_reason = 'no_current_turn_jealousy_trigger';
    } elseif ($current_turn_channel !== 'live_reply' || !$playful_jealousy_current_turn_only) {
        $playful_jealousy_reason = 'playful_jealousy_live_turn_required';
    } elseif (!$playful_jealousy_context_clear || !$respectful || !$consensual) {
        $playful_jealousy_reason = 'playful_jealousy_context_blocked';
    } elseif (!$playful_jealousy_stage_allowed) {
        $playful_jealousy_reason = 'playful_jealousy_stage_not_ready';
    } elseif ($hard_veto) {
        $playful_jealousy_reason = $hard_veto_reasons[0];
    } else {
        $playful_jealousy_reason = 'current_turn_playful_jealousy';
    }

    $opportunity = $reciprocation_allowed
        || $proactive_allowed
        || $playful_jealousy_allowed;

    if ($playful_jealousy_allowed) {
        $opportunity_source = $reciprocation_allowed
            ? 'current_turn_playful_jealousy_and_active_bid'
            : 'current_turn_playful_jealousy';
        $primary_reason = 'current_turn_playful_jealousy';
    } elseif ($reciprocation_allowed && $proactive_allowed) {
        $opportunity_source = 'active_bid_and_proactive_cadence';
        $primary_reason = 'active_respectful_romantic_bid';
    } elseif ($reciprocation_allowed) {
        $opportunity_source = 'active_romantic_bid';
        $primary_reason = 'active_respectful_romantic_bid';
    } elseif ($proactive_allowed) {
        $opportunity_source = 'proactive_cadence';
        $primary_reason = 'proactive_courtship_cadence_clear';
    } elseif ($hard_veto) {
        $opportunity_source = 'none';
        $primary_reason = $hard_veto_reasons[0];
    } elseif ($stage === 'guarded') {
        $opportunity_source = 'none';
        $primary_reason = $active_bid ? 'romantic_bid_not_grounded' : 'guarded_requires_active_bid';
    } elseif ($active_bid && !$grounded_bid) {
        $opportunity_source = 'none';
        $primary_reason = 'romantic_bid_not_grounded';
    } elseif (!$romantic_context_suitable) {
        $opportunity_source = 'none';
        $primary_reason = 'romantic_context_not_suitable';
    } else {
        $opportunity_source = 'none';
        $primary_reason = 'proactive_cadence_not_clear';
    }

    // Place genuinely available expressive actions first so the structured
    // contract does not carry an accidental always-hold ordering bias. Hold
    // and decline remain available on every eligible turn.
    $allowed_actions = array();
    if ($playful_jealousy_allowed) $allowed_actions[] = 'tease_jealousy';
    if ($reciprocation_allowed) $allowed_actions[] = 'reciprocate';
    if ($proactive_allowed) $allowed_actions[] = 'initiate';
    $allowed_actions[] = 'hold';
    $allowed_actions[] = 'decline';

    return array(
        'schema_version' => '1.0',
        'policy_version' => aimee_romantic_expression_policy_version(),
        'decision_id' => isset($input['decision_id']) ? (string) $input['decision_id'] : '',
        'user_id' => isset($input['user_id']) ? (int) $input['user_id'] : 0,
        'relationship_lane' => $lane,
        'relationship_posture' => $posture,
        'relationship_stage' => $stage,
        'romantic_opportunity' => $opportunity,
        'opportunity_source' => $opportunity_source,
        'maximum_intensity' => $maximum_intensity,
        'active_romantic_bid' => $grounded_bid,
        'reciprocation_allowed' => $reciprocation_allowed,
        'proactive_allowed' => $proactive_allowed,
        'initiative_allowed' => $proactive_allowed,
        'playful_jealousy_allowed' => $playful_jealousy_allowed,
        'playful_jealousy_transient' => true,
        'playful_jealousy_current_turn_only' => true,
        'playful_jealousy_trigger_type' => $playful_jealousy_trigger_type,
        'playful_jealousy_maximum_intensity' => $playful_jealousy_maximum_intensity,
        'playful_jealousy_reason_code' => $playful_jealousy_reason,
        'cadence_clear' => $cadence_clear,
        'proactive_cadence_turns' => $cadence_turns,
        'eligible_turn_number' => $eligible_turn,
        'last_proactive_opportunity_eligible_turn' => $last_initiative_turn,
        'last_initiative_eligible_turn' => $last_initiative_turn,
        'hard_veto' => $hard_veto,
        'hard_veto_reason_codes' => $hard_veto_reasons,
        'reason_code' => $primary_reason,
        'allowed_actions' => $allowed_actions,
        'membership_active' => $membership_active,
        'membership_used_as_relationship_signal' => false,
        'grants_model_route' => false,
        'grants_media_access' => false,
        'grants_sexual_content' => false,
        'grants_intimacy_invitation' => false,
        'changes_relationship_state' => false,
        'romantic_decision' => $opportunity ? 'pending' : 'hold',
        'romantic_selected_intensity' => 'none',
        'romantic_reason_code' => $opportunity ? 'awaiting_aimee_choice' : 'no_romantic_opportunity',
        'romantic_choice_valid' => false,
        'romantic_delivery_status' => $opportunity ? 'pending_choice' : 'not_applicable',
        'romantic_delivery_reason_code' => $opportunity ? 'awaiting_aimee_choice' : 'no_romantic_opportunity',
        'romantic_expression_visible' => false,
        'context_snapshot' => array(
            'adult' => $is_adult,
            'colleague' => $is_colleague,
            'explicitly_platonic' => $explicitly_platonic,
            'respectful' => $respectful,
            'consensual' => $consensual,
            'romantic_context_suitable' => $romantic_context_suitable,
            'bid_confidence' => $bid_confidence,
            'current_turn_channel' => $current_turn_channel,
            'playful_jealousy_detected' => $playful_jealousy_detected,
            'playful_jealousy_trigger_type' => $playful_jealousy_trigger_type,
            'playful_jealousy_opt_out' => $playful_jealousy_opt_out,
            'playful_jealousy_serious_context' => $playful_jealousy_serious,
            'playful_jealousy_relationship_advice' => $playful_jealousy_advice,
            'playful_jealousy_manipulative_bait' => $playful_jealousy_bait,
            'playful_jealousy_detector_reason' => $playful_jealousy_detector_reason,
        ),
    );
}

/**
 * Return the reason codes valid for an Aimee choice.
 *
 * @param string $action Normalized action.
 * @return array<int,string>
 */
function aimee_romantic_expression_choice_reasons($action) {
    if ($action === 'tease_jealousy') {
        return array('aimee_playfully_jealous');
    }
    if ($action === 'reciprocate') {
        return array('aimee_mutual_spark', 'aimee_playful_interest');
    }
    if ($action === 'initiate') {
        return array('aimee_affectionate_initiative', 'aimee_playful_interest');
    }
    return array(
        'aimee_prefers_more_context',
        'aimee_prefers_friendlier_tone',
        'aimee_not_feeling_romantic',
    );
}

/**
 * Return the exact intensity tokens permitted for one exposed action.
 *
 * @param array<string,mixed> $envelope Server-owned romantic envelope.
 * @param mixed               $action Candidate action.
 * @return array<int,string>
 */
function aimee_romantic_expression_choice_intensities(array $envelope, $action) {
    $action = aimee_romantic_expression_token($action);
    if ($action === 'hold' || $action === 'decline') {
        return array('none');
    }

    $ceiling = isset($envelope['maximum_intensity'])
        ? $envelope['maximum_intensity']
        : 'none';
    $ceiling_rank = aimee_romantic_expression_intensity_rank($ceiling);
    if ($action === 'tease_jealousy') {
        $jealousy_ceiling = isset($envelope['playful_jealousy_maximum_intensity'])
            ? $envelope['playful_jealousy_maximum_intensity']
            : 'none';
        $ceiling_rank = min(
            $ceiling_rank,
            aimee_romantic_expression_intensity_rank($jealousy_ceiling)
        );
    }

    $allowed = array();
    foreach (aimee_romantic_expression_intensity_order() as $intensity => $rank) {
        if ($rank >= 1 && $rank <= $ceiling_rank) {
            $allowed[] = $intensity;
        }
    }
    return $allowed;
}

/**
 * Render the action/intensity/reason combinations the model is actually
 * allowed to return. Earlier releases said "matching reason code" without
 * exposing the map, which made a second repair call repeat the first mistake.
 *
 * @param array<string,mixed> $envelope Server-owned romantic envelope.
 * @return string
 */
function aimee_romantic_expression_choice_contract(array $envelope) {
    $actions = isset($envelope['allowed_actions']) && is_array($envelope['allowed_actions'])
        ? $envelope['allowed_actions']
        : array('hold', 'decline');
    $parts = array();

    foreach ($actions as $action) {
        $action = aimee_romantic_expression_token($action);
        if ($action === '') continue;
        $intensities = aimee_romantic_expression_choice_intensities(
            $envelope,
            $action
        );
        $reasons = aimee_romantic_expression_choice_reasons($action);
        $parts[] = $action
            . '(intensity=' . implode('|', $intensities)
            . '; reason=' . implode('|', $reasons) . ')';
    }

    return implode('; ', $parts);
}

/**
 * Build a compact retry instruction with the exact current-turn choice map.
 *
 * @param array<string,mixed> $envelope Server-owned romantic envelope.
 * @param mixed               $previous_draft Rejected visible draft.
 * @return string
 */
function aimee_romantic_expression_repair_directive(
    array $envelope,
    $previous_draft
) {
    return "\n\nROMANTIC ROUTE-INTEGRITY REPAIR: The previous draft did not make a valid, visible choice inside the deterministic romantic-expression contract. Rewrite the complete JSON response and choose exactly one combination from ALLOWED_CHOICE_MAP. Do not invent a reason code or intensity. If you choose reciprocate, initiate or tease_jealousy, make that choice perceptible in reply_text without calling the user a friend, mate, buddy or pal. A jealousy tease must be brief, amused and non-possessive; never claim exclusivity, control the user, insult a third party, create guilt or threaten withdrawal. If you choose hold or decline, remain warm and honest without inventing a platonic relationship label. Do not widen the non-explicit ceiling, media permissions or intimate route.\nALLOWED_CHOICE_MAP: "
        . aimee_romantic_expression_choice_contract($envelope)
        . "\nPREVIOUS DRAFT:\n"
        . trim((string) $previous_draft);
}

/**
 * Apply Aimee's bounded choice to an immutable policy envelope.
 *
 * The model may choose among exposed actions, including declining. It cannot
 * change the lane, opportunity, cadence, ceiling or allowed-action set.
 * Unsupported or ceiling-breaking choices fail closed to `hold`.
 *
 * @param array<string,mixed> $envelope Output from build().
 * @param array<string,mixed> $choice Model choice.
 * @return array<string,mixed>
 */
function aimee_romantic_expression_apply_choice(array $envelope, array $choice) {
    $result = $envelope;
    $result['romantic_choice_valid'] = false;

    $opportunity = !empty($envelope['romantic_opportunity']);
    if (!$opportunity) {
        $result['romantic_decision'] = 'hold';
        $result['romantic_selected_intensity'] = 'none';
        $result['romantic_reason_code'] = 'no_romantic_opportunity';
        return $result;
    }

    $action = aimee_romantic_expression_token(
        isset($choice['romantic_action'])
            ? $choice['romantic_action']
            : (isset($choice['action']) ? $choice['action'] : '')
    );
    $allowed_actions = isset($envelope['allowed_actions']) && is_array($envelope['allowed_actions'])
        ? $envelope['allowed_actions']
        : array('hold', 'decline');
    if (!in_array($action, $allowed_actions, true)) {
        $result['romantic_decision'] = 'hold';
        $result['romantic_selected_intensity'] = 'none';
        $result['romantic_reason_code'] = 'choice_action_not_allowed';
        return $result;
    }

    $reason = aimee_romantic_expression_token(
        isset($choice['romantic_reason_code'])
            ? $choice['romantic_reason_code']
            : (isset($choice['reason_code']) ? $choice['reason_code'] : '')
    );
    if (!in_array($reason, aimee_romantic_expression_choice_reasons($action), true)) {
        $result['romantic_decision'] = 'hold';
        $result['romantic_selected_intensity'] = 'none';
        $result['romantic_reason_code'] = 'choice_reason_invalid';
        return $result;
    }

    if ($action === 'hold' || $action === 'decline') {
        $result['romantic_decision'] = $action;
        $result['romantic_selected_intensity'] = 'none';
        $result['romantic_reason_code'] = $reason;
        $result['romantic_choice_valid'] = true;
        return $result;
    }

    $intensity = aimee_romantic_expression_token(
        isset($choice['romantic_intensity'])
            ? $choice['romantic_intensity']
            : (isset($choice['intensity']) ? $choice['intensity'] : '')
    );
    $intensity_rank = aimee_romantic_expression_intensity_rank($intensity);
    $ceiling_rank = aimee_romantic_expression_intensity_rank(
        isset($envelope['maximum_intensity']) ? $envelope['maximum_intensity'] : 'none'
    );
    if ($action === 'tease_jealousy') {
        if (empty($envelope['playful_jealousy_allowed'])) {
            $result['romantic_decision'] = 'hold';
            $result['romantic_selected_intensity'] = 'none';
            $result['romantic_reason_code'] = 'choice_action_not_allowed';
            return $result;
        }
        $playful_jealousy_ceiling_rank = aimee_romantic_expression_intensity_rank(
            isset($envelope['playful_jealousy_maximum_intensity'])
                ? $envelope['playful_jealousy_maximum_intensity']
                : 'none'
        );
        $ceiling_rank = min($ceiling_rank, $playful_jealousy_ceiling_rank);
    }
    if ($intensity_rank < 1) {
        $result['romantic_decision'] = 'hold';
        $result['romantic_selected_intensity'] = 'none';
        $result['romantic_reason_code'] = 'choice_intensity_invalid';
        return $result;
    }
    if ($ceiling_rank < 1 || $intensity_rank > $ceiling_rank) {
        $result['romantic_decision'] = 'hold';
        $result['romantic_selected_intensity'] = 'none';
        $result['romantic_reason_code'] = 'choice_exceeds_ceiling';
        return $result;
    }

    $result['romantic_decision'] = $action;
    $result['romantic_selected_intensity'] = $intensity;
    $result['romantic_reason_code'] = $reason;
    $result['romantic_choice_valid'] = true;
    $result['playful_jealousy_selected'] = $action === 'tease_jealousy';
    $result['romantic_delivery_status'] = 'pending_reply';
    $result['romantic_delivery_reason_code'] = 'awaiting_final_reply';
    $result['romantic_expression_visible'] = false;
    return $result;
}

/**
 * Record whether Aimee's reconciled choice survived into the final reply.
 *
 * Choice and delivery are deliberately separate. A later truth, safety or
 * technical repair may legitimately replace a romantic draft; in that case
 * the audit must not claim that the user received the chosen expression.
 *
 * @param array<string,mixed> $expression Reconciled expression envelope.
 * @param mixed               $visible Whether the final reply visibly carries it.
 * @param mixed               $override_reason Stable downstream override token.
 * @return array<string,mixed>
 */
function aimee_romantic_expression_finalize_delivery(
    array $expression,
    $visible,
    $override_reason = ''
) {
    $result = $expression;
    $opportunity = !empty($expression['romantic_opportunity']);
    $choice_valid = !empty($expression['romantic_choice_valid']);
    $decision = aimee_romantic_expression_token(
        isset($expression['romantic_decision'])
            ? $expression['romantic_decision']
            : 'hold'
    );
    $visible = aimee_romantic_expression_bool($visible, false);
    $override_reason = aimee_romantic_expression_token($override_reason);

    $result['romantic_expression_visible'] = false;

    if (!$opportunity) {
        $result['romantic_delivery_status'] = 'not_applicable';
        $result['romantic_delivery_reason_code'] = 'no_romantic_opportunity';
        return $result;
    }

    if (!$choice_valid) {
        $result['romantic_delivery_status'] = 'invalid_choice';
        $result['romantic_delivery_reason_code'] = 'romantic_choice_invalid';
        return $result;
    }

    if ($decision === 'hold') {
        if (!$visible) {
            $result['romantic_delivery_status'] = $override_reason !== ''
                ? 'superseded'
                : 'neutralized';
            $result['romantic_delivery_reason_code'] = $override_reason !== ''
                ? 'romantic_expression_superseded'
                : 'romantic_expression_neutralized';
            if ($override_reason !== '') {
                $result['romantic_delivery_override_reason'] = $override_reason;
            }
            return $result;
        }
        $result['romantic_delivery_status'] = 'held';
        $result['romantic_delivery_reason_code'] = 'romantic_choice_held';
        return $result;
    }

    if ($decision === 'decline') {
        if (!$visible) {
            $result['romantic_delivery_status'] = $override_reason !== ''
                ? 'superseded'
                : 'neutralized';
            $result['romantic_delivery_reason_code'] = $override_reason !== ''
                ? 'romantic_expression_superseded'
                : 'romantic_expression_neutralized';
            if ($override_reason !== '') {
                $result['romantic_delivery_override_reason'] = $override_reason;
            }
            return $result;
        }
        $result['romantic_delivery_status'] = 'declined';
        $result['romantic_delivery_reason_code'] = 'romantic_choice_declined';
        return $result;
    }

    if (!in_array($decision, array('reciprocate', 'initiate', 'tease_jealousy'), true)) {
        $result['romantic_delivery_status'] = 'invalid_choice';
        $result['romantic_delivery_reason_code'] = 'romantic_choice_invalid';
        return $result;
    }

    if ($visible) {
        $result['romantic_delivery_status'] = 'delivered';
        $result['romantic_delivery_reason_code'] = 'romantic_expression_delivered';
        $result['romantic_expression_visible'] = true;
        return $result;
    }

    if ($override_reason !== '') {
        $result['romantic_delivery_status'] = 'superseded';
        $result['romantic_delivery_reason_code'] = 'romantic_expression_superseded';
        $result['romantic_delivery_override_reason'] = $override_reason;
        return $result;
    }

    $result['romantic_delivery_status'] = 'neutralized';
    $result['romantic_delivery_reason_code'] = 'romantic_expression_neutralized';
    return $result;
}

/**
 * Render a compact prompt contract from a server-owned envelope.
 *
 * This text asks Aimee to exercise discretion inside the policy. It does not
 * instruct her to flirt and explicitly prevents inference of erotic or media
 * entitlement from a conversational opportunity.
 *
 * @param array<string,mixed> $envelope Output from build().
 * @return string
 */
function aimee_romantic_expression_prompt_directive(array $envelope) {
    $actions = isset($envelope['allowed_actions']) && is_array($envelope['allowed_actions'])
        ? implode(',', $envelope['allowed_actions'])
        : 'hold,decline';

    $lines = array(
        'DETERMINISTIC ROMANTIC EXPRESSION CONTRACT:',
        'relationship_lane=' . (isset($envelope['relationship_lane']) ? $envelope['relationship_lane'] : 'ineligible'),
        'relationship_posture=' . (isset($envelope['relationship_posture']) ? $envelope['relationship_posture'] : 'non_romantic'),
        'relationship_stage=' . (isset($envelope['relationship_stage']) ? $envelope['relationship_stage'] : 'unknown'),
        'romantic_opportunity=' . (!empty($envelope['romantic_opportunity']) ? 'true' : 'false'),
        'opportunity_source=' . (isset($envelope['opportunity_source']) ? $envelope['opportunity_source'] : 'none'),
        'maximum_intensity=' . (isset($envelope['maximum_intensity']) ? $envelope['maximum_intensity'] : 'none'),
        'playful_jealousy_allowed=' . (!empty($envelope['playful_jealousy_allowed']) ? 'true' : 'false'),
        'playful_jealousy_trigger_type=' . (isset($envelope['playful_jealousy_trigger_type']) ? $envelope['playful_jealousy_trigger_type'] : 'none'),
        'playful_jealousy_maximum_intensity=' . (isset($envelope['playful_jealousy_maximum_intensity']) ? $envelope['playful_jealousy_maximum_intensity'] : 'none'),
        'allowed_actions=' . $actions,
        'allowed_choice_map=' . aimee_romantic_expression_choice_contract($envelope),
        'Choose freely from allowed_actions and use exactly one action/intensity/reason combination from allowed_choice_map. Romantic opportunity permits consideration, never compels affection.',
        'FLIRT CALIBRATION: when reciprocate or initiate is genuinely available on an ordinary respectful, emotionally light turn, do not default to hold merely from generic caution. Let stage-appropriate interest become perceptible more often through a small tease, confident warmth, playful challenge, personal admission or affectionate observation. Serious, factual, distressed, platonic or boundary-led moments still justify hold or decline, and no turn requires romance.',
        'This contract governs conversational tone only. It grants no intimate model route, sexual content, image access, consent, or payment entitlement.',
        'Return romantic_action, romantic_intensity and romantic_reason_code as structured decision fields; do not rewrite server-owned policy fields.',
    );

    if (!empty($envelope['playful_jealousy_allowed'])) {
        $lines[] = 'If and only if romantic_action=tease_jealousy, use one brief, warm or amused jealous beat responding to this live turn, then answer its substance.';
        $lines[] = 'Playful jealousy is transient expression, not ownership or exclusivity. Never call the user yours, accuse them of cheating, control who they see, demand proof, insult a third party, withdraw affection, invent distress, apply retention pressure, or turn jealousy into sexual or media escalation.';
        $lines[] = 'tease_jealousy must use romantic_reason_code=aimee_playfully_jealous and remain at or below playful_jealousy_maximum_intensity.';
    } else {
        $lines[] = 'Do not invent, originate or revive jealousy. A remembered third party, established relationship stage or romantic cadence is not a current-turn jealousy trigger.';
    }

    return implode("\n", $lines);
}

/**
 * Carry the deterministic relationship posture into plain-text routes that do
 * not return the structured romantic-choice contract (voice greetings,
 * remembered continuity and autonomous messages).
 *
 * This is intentionally posture-only. Existing warmth and attraction should
 * not disappear at a channel boundary, but a background route cannot invent a
 * new entitlement, model route, sexual context or media permission.
 *
 * @param array<string,mixed> $envelope Output from build().
 * @param string              $channel  voice_greeting|continuity|autonomous.
 * @return string
 */
function aimee_romantic_expression_channel_directive(array $envelope, $channel = 'autonomous') {
    $channel = aimee_romantic_expression_token($channel);
    if (!in_array($channel, array('voice_greeting', 'continuity', 'autonomous'), true)) {
        $channel = 'autonomous';
    }

    $lane = isset($envelope['relationship_lane'])
        ? (string) $envelope['relationship_lane']
        : 'ineligible';
    $posture = isset($envelope['relationship_posture'])
        ? (string) $envelope['relationship_posture']
        : 'non_romantic';
    $stage = isset($envelope['relationship_stage'])
        ? (string) $envelope['relationship_stage']
        : 'guarded';
    $ceiling = isset($envelope['maximum_intensity'])
        ? (string) $envelope['maximum_intensity']
        : 'none';
    $initiative_allowed = !empty($envelope['initiative_allowed']);

    $stage_voice = array(
        'guarded' => 'Keep an early-courtship spark: curious, discerning and lightly playful, never cold or prematurely intimate.',
        'warm' => 'Let clear personal interest survive. Warmth may be lightly flirty rather than defaulting to generic friendship.',
        'flirty' => 'Mutual attraction is established. Teasing, affectionate recognition and non-explicit tension may remain visible.',
        'intimate' => 'Romantic closeness is established. Sound personally affectionate and confident without making the message sexual by default.',
        'bonded' => 'The bond is established and partner-like. Preserve familiarity, tenderness and chosen affection without implying ownership.',
    );

    $lines = array(
        'CROSS-CHANNEL ROMANTIC POSTURE:',
        'channel=' . $channel,
        'relationship_lane=' . $lane,
        'relationship_stage=' . $stage,
        'relationship_posture=' . $posture,
        'maximum_intensity=' . $ceiling,
        '- Do not reset an established user to stranger, customer or generic-friend tone merely because this message is proactive, remembered or spoken.',
    );

    if ($lane === 'professional_colleague') {
        $lines[] = '- Keep this lane warm, witty and professionally close; do not make it romantic.';
    } elseif ($lane === 'explicitly_platonic') {
        $lines[] = '- Preserve explicitly chosen platonic warmth; do not introduce courtship.';
    } elseif (in_array($lane, array('courtship_paused', 'ineligible'), true)) {
        $lines[] = '- Repair, boundaries or eligibility take priority. Do not perform romantic warmth as though the blocker were absent.';
    } else {
        $lines[] = '- ' . (isset($stage_voice[$stage])
            ? $stage_voice[$stage]
            : $stage_voice['guarded']);

        if ($channel === 'autonomous') {
            $lines[] = $initiative_allowed
                ? '- A cadence-cleared romantic initiative is available for consideration. Aimee may use it or remain non-romantic by choice.'
                : '- Preserve established warmth or attraction without manufacturing a new escalation merely because the message is unsolicited.';
        } elseif ($channel === 'continuity') {
            $lines[] = '- Complete the remembered promise or follow-up first. Existing affection may colour it naturally, but never hijack an important outcome into forced flirtation.';
        } else {
            $lines[] = '- The user initiated the call. Answer with the familiarity already earned in this relationship; do not force a new escalation into the greeting.';
        }
    }

    $lines[] = '- This posture changes tone only. It grants no intimate model route, sexual content, image access, consent or payment entitlement.';
    $lines[] = '- Never originate, revive or imply jealousy in a voice greeting, remembered continuity message or autonomous message. Playful jealousy is current-live-turn-only and is unavailable on this channel.';

    return implode("\n", $lines);
}
