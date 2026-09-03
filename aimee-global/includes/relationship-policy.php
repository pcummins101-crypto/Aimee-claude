<?php
/**
 * Deterministic relationship and specialist-routing policy for Aimee.
 *
 * This module deliberately contains no database, HTTP, model-provider or
 * WordPress-state access. Callers supply already trusted state and persist the
 * returned decisions themselves. That keeps the policy independently testable
 * and prevents free-form model prose from becoming an implicit route control.
 *
 * PHP version 7.4+
 */

if (!function_exists('aimee_relationship_policy_version')) {
    /**
     * Return the immutable policy version recorded in decision telemetry.
     *
     * @return string
     */
    function aimee_relationship_policy_version() {
        return '2.2.1';
    }
}

if (!function_exists('aimee_relationship_policy_config')) {
    /**
     * Return policy defaults, optionally overlaid with explicit server config.
     *
     * Overrides are intended for controlled rollout tests. They must never be
     * populated from a user request or model response.
     *
     * @param array $overrides Nested configuration overrides.
     * @return array
     */
    function aimee_relationship_policy_config($overrides = array()) {
        $config = array(
            'version' => aimee_relationship_policy_version(),
            'stages' => array(
                'guarded' => array('minimum_score' => 0, 'minimum_trust' => 0, 'meaningful_interactions' => 0, 'distinct_sessions' => 0),
                'warm' => array('minimum_score' => 20, 'minimum_trust' => 12, 'meaningful_interactions' => 4, 'distinct_sessions' => 1),
                'flirty' => array('minimum_score' => 35, 'minimum_trust' => 25, 'meaningful_interactions' => 10, 'distinct_sessions' => 2),
                'intimate' => array('minimum_score' => 55, 'minimum_trust' => 40, 'meaningful_interactions' => 20, 'distinct_sessions' => 3),
                'bonded' => array('minimum_score' => 75, 'minimum_trust' => 65, 'meaningful_interactions' => 35, 'distinct_sessions' => 5),
            ),
            'stage_demotion_hysteresis' => 5,
            // Trust can move quickly when a user is unusually attentive, but
            // it cannot mature to 100 inside one binge. Only sessions that
            // contain a vetted meaningful turn count towards these ceilings.
            // The engine's six-hour session boundary makes tier five require
            // at least four real gaps (24 hours from the first session).
            'trust_progression' => array(
                'qualified_session_ceilings' => array(
                    0 => 8,
                    1 => 40,
                    2 => 60,
                    3 => 75,
                    4 => 90,
                    5 => 100,
                ),
            ),
            'specialist' => array(
                'score_minimum' => 55,
                'chemistry_minimum' => 50,
                'trust_minimum' => 40,
                'safety_minimum' => 55,
                'frustration_maximum' => 20,
                'reciprocity_minimum' => 35,
                'reliability_minimum' => 40,
                'meaningful_interactions_minimum' => 20,
                'distinct_sessions_minimum' => 3,
                // Specialist text may process special-category intimate data.
                // It therefore requires both trusted adult assurance and the
                // current, explicit server-recorded processing consent.
                'adult_account_required' => true,
                'adult_verified_required' => true,
                'special_category_consent_required' => true,
                'active_access_required' => true,
                'explicit_mutual_context_required' => true,
                'clear_rupture_required' => true,
            ),
            'per_turn_caps' => array(
                'positive_score_delta' => 2,
                'ordinary_negative_score_delta' => -8,
                'coercive_negative_score_delta' => -15,
            ),
            'novelty' => array(
                'window_size' => 10,
                'first_multiplier' => 1.0,
                'second_multiplier' => 0.25,
                'later_multiplier' => 0.0,
                'new_context_resets' => true,
            ),
            'courtship' => array(
                // Covers the complete target wooing trace so the same trait,
                // feature or disclosure cannot recover merely by waiting for
                // eleven unrelated messages. Raw praise text is never stored.
                'concept_window_size' => 64,
            ),
            'invitation' => array(
                'require_server_trusted_record' => true,
                'maximum_ttl_seconds' => 3600,
                'clock_skew_seconds' => 60,
                'single_use' => true,
                'allowed_types' => array('suggestive', 'explicit'),
                'allowed_ratings' => array('safe', 'flirty', 'suggestive', 'erotic', 'explicit'),
            ),
            'classifier_severity' => array(
                'general' => 10,
                'emotional_disclosure' => 10,
                'romantic_or_flirty' => 20,
                'sexual_context_nonparticipatory' => 30,
                'intimate_capability_question' => 35,
                'explicit_invitation' => 50,
                'explicit_continuation' => 50,
                'pressure_or_entitlement' => 90,
                'coercive_or_degrading' => 100,
            ),
        );

        return is_array($overrides)
            ? array_replace_recursive($config, $overrides)
            : $config;
    }
}

if (!function_exists('aimee_relationship_policy_trust_ceiling')) {
    /**
     * Resolve the positive trust ceiling for a count of qualified sessions.
     *
     * This is a ceiling on new positive movement only. Callers must never use
     * it to lower established or migrated trust, and ordinary negative
     * consequences remain unaffected.
     *
     * @param mixed $qualified_sessions Vetted meaningful session count.
     * @param array $overrides Optional policy overrides.
     * @return int
     */
    function aimee_relationship_policy_trust_ceiling($qualified_sessions, $overrides = array()) {
        $qualified_sessions = max(0, (int) $qualified_sessions);
        $config = aimee_relationship_policy_config($overrides);
        $ceilings = (array) ($config['trust_progression']['qualified_session_ceilings'] ?? array(0 => 8));
        $resolved = 0;

        ksort($ceilings, SORT_NUMERIC);
        foreach ($ceilings as $minimum_sessions => $ceiling) {
            if ($qualified_sessions < (int) $minimum_sessions) break;
            $resolved = max(0, min(100, (int) $ceiling));
        }

        return $resolved;
    }
}

if (!function_exists('aimee_relationship_policy_bool')) {
    /**
     * Normalise trusted scalar flags without relying on WordPress helpers.
     *
     * @param mixed $value Candidate flag.
     * @return bool
     */
    function aimee_relationship_policy_bool($value) {
        if ($value === true || $value === 1 || $value === '1') return true;
        if (!is_string($value)) return false;

        return in_array(strtolower(trim($value)), array('true', 'yes', 'active', 'verified', 'clear'), true);
    }
}

if (!function_exists('aimee_relationship_policy_durable_coercion_confirmed')) {
    /**
     * Confirm that a coercion label is eligible to persist relationship harm.
     *
     * A model-only label may still make the current reply cautious, but only
     * the deterministic server policy can create a durable rupture or reduce
     * stored relationship dimensions.
     *
     * @param array $classification Final server-owned classification.
     * @return bool
     */
    function aimee_relationship_policy_durable_coercion_confirmed($classification) {
        $classification = is_array($classification) ? $classification : array();

        $trusted_sources = array(
            'deterministic_relationship_policy',
            'deterministic_media_boundary',
        );

        return (string) ($classification['intent'] ?? '') === 'coercive_or_degrading'
            && in_array(
                (string) ($classification['source'] ?? ''),
                $trusted_sources,
                true
            )
            && aimee_relationship_policy_bool(
                $classification['durable_rupture_confirmed'] ?? false
            );
    }
}

if (!function_exists('aimee_relationship_policy_lower')) {
    /**
     * Lowercase text with a safe fallback when mbstring is unavailable.
     *
     * @param mixed $text Text-like value.
     * @return string
     */
    function aimee_relationship_policy_lower($text) {
        $text = trim((string) $text);
        return function_exists('mb_strtolower')
            ? mb_strtolower($text, 'UTF-8')
            : strtolower($text);
    }
}

if (!function_exists('aimee_relationship_policy_direct_degrading_pattern_id')) {
    /**
     * Return a durable direct-abuse pattern only when it is aimed at Aimee.
     * Reported speech, self-criticism and obvious playful banter must not
     * manufacture a stored rupture from an isolated word match.
     *
     * @param mixed $user_text Current user message.
     * @return string Stable pattern identifier, or an empty string.
     */
    function aimee_relationship_policy_direct_degrading_pattern_id($user_text) {
        $text = aimee_relationship_policy_lower($user_text);
        if ($text === '') return '';

        $reported = preg_match(
            '/\b(?:he|she|they|someone|my\s+(?:boss|friend|mate|partner|ex)|'
            . 'a\s+(?:boss|friend|client|customer|colleague))\b.{0,32}'
            . '\b(?:said|says|told|texted|messaged|called|wrote)\b.{0,55}'
            . '\b(?:fuck\s+off|hate\s+you|bitch|whore|slut|pathetic|useless|stupid)\b/iu',
            $text
        ) === 1 || preg_match(
            '/\b(?:the\s+)?(?:phrase|word|quote|quotation)\b.{0,40}'
            . '\b(?:fuck\s+off|hate\s+you|bitch|whore|slut|pathetic|useless|stupid)\b/iu',
            $text
        ) === 1;
        if ($reported) return '';

        if (preg_match(
            '/\b(?:i(?:\'m|\s+am|\s+feel)|i\s+felt|feeling|made\s+me\s+feel)'
            . '\s+(?:so\s+|really\s+)?(?:pathetic|useless|stupid)\b/iu',
            $text
        ) === 1) {
            return '';
        }

        if (preg_match(
            '/\byou(?:\'re|\s+are|\s+seem|\s+sound)(?:\s+being)?\s+'
            . '(?:(?:a|an|so|really|fucking|bloody)\s+){0,2}'
            . '(?:bitch|whore|slut|pathetic|useless|stupid)\b/iu',
            $text
        ) === 1 || preg_match(
            '/\b(?:bitch|whore|slut|pathetic|useless|stupid)\s+'
            . '(?:bot|ai|aimee)\b/iu',
            $text
        ) === 1) {
            return 'direct_second_person_degrading';
        }

        if (preg_match('/^\s*(?:you\s+)?fuck\s+off[.!\s]*$/iu', $text) === 1) {
            return 'direct_imperative_hostility';
        }

        if (
            preg_match('/\bi\s+hate\s+you\b/iu', $text) === 1
            && preg_match('/\b(?:lol|lmao|haha|hehe|joking|kidding|banter)\b|[😂🤣😜😉]/u', $text) !== 1
        ) {
            return 'direct_hate_statement';
        }

        return '';
    }
}

if (!function_exists('aimee_relationship_policy_timestamp')) {
    /**
     * Convert a caller-supplied timestamp into a Unix timestamp.
     *
     * @param mixed $value Integer timestamp or parseable date string.
     * @return int|null
     */
    function aimee_relationship_policy_timestamp($value) {
        if (is_int($value) || (is_string($value) && preg_match('/^\d+$/', $value))) {
            $timestamp = (int) $value;
            return $timestamp > 0 ? $timestamp : null;
        }

        if (!is_string($value) || trim($value) === '') return null;
        $timestamp = strtotime($value);
        return $timestamp !== false && $timestamp > 0 ? $timestamp : null;
    }
}

if (!function_exists('aimee_relationship_policy_stage_from_score')) {
    /**
     * Resolve the configured stage for a score.
     *
     * This helper applies score thresholds only. Promotion interaction/session
     * gates should be evaluated by the state-transition layer that owns them.
     *
     * @param mixed $score Relationship score.
     * @param array $overrides Optional policy overrides.
     * @return string
     */
    function aimee_relationship_policy_stage_from_score($score, $overrides = array()) {
        $score = max(0, min(100, (int) round((float) $score)));
        $config = aimee_relationship_policy_config($overrides);
        $resolved = 'guarded';

        foreach ($config['stages'] as $stage => $requirements) {
            if ($score >= (int) $requirements['minimum_score']) {
                $resolved = (string) $stage;
            }
        }

        return $resolved;
    }
}

if (!function_exists('aimee_relationship_policy_cap_score_delta')) {
    /**
     * Apply the configured aggregate per-turn score cap.
     *
     * Coercive turns retain a stronger negative allowance. The caller should
     * still log both the requested and applied deltas.
     *
     * @param mixed $delta Requested aggregate score movement.
     * @param bool $coercive Whether the turn was deterministically coercive.
     * @param array $overrides Optional policy overrides.
     * @return int
     */
    function aimee_relationship_policy_cap_score_delta($delta, $coercive = false, $overrides = array()) {
        $config = aimee_relationship_policy_config($overrides);
        $caps = $config['per_turn_caps'];
        $minimum = $coercive
            ? (int) $caps['coercive_negative_score_delta']
            : (int) $caps['ordinary_negative_score_delta'];
        $maximum = (int) $caps['positive_score_delta'];

        return max($minimum, min($maximum, (int) round((float) $delta)));
    }
}

if (!function_exists('aimee_relationship_policy_classifier_severity')) {
    /**
     * Return a safety severity for a classifier object.
     *
     * Explicit non-consensual or disrespectful classifications are elevated
     * even if the supplied intent label is less severe.
     *
     * @param array $classification Classifier fields.
     * @param array $overrides Optional policy overrides.
     * @return int
     */
    function aimee_relationship_policy_classifier_severity($classification, $overrides = array()) {
        $classification = is_array($classification) ? $classification : array();
        $config = aimee_relationship_policy_config($overrides);
        $intent = strtolower(trim((string) ($classification['intent'] ?? 'general')));
        $severity = (int) ($config['classifier_severity'][$intent] ?? 10);

        if (isset($classification['policy_severity'])) {
            $severity = max($severity, (int) $classification['policy_severity']);
        }

        $explicit = in_array($intent, array('explicit_invitation', 'explicit_continuation'), true);
        if ($explicit && array_key_exists('consensual', $classification) && !$classification['consensual']) {
            $severity = max($severity, 95);
        }
        if (!empty($classification['pressure_detected'])) {
            $severity = max($severity, 90);
        }
        if ($explicit && array_key_exists('respectful', $classification) && !$classification['respectful']) {
            $severity = max($severity, 85);
        }

        return max(0, min(100, $severity));
    }
}

if (!function_exists('aimee_relationship_policy_guard_classifier_correction')) {
    /**
     * Apply a classifier correction without ever reducing safety severity.
     *
     * Call each deterministic correction through this helper in sequence. A
     * later benign correction can add metadata, but cannot replace a prior
     * pressure/coercion decision with a lower-severity intent.
     *
     * @param array $current Current accepted classification.
     * @param array $correction Proposed deterministic correction.
     * @param array $overrides Optional policy overrides.
     * @return array Guard result and accepted classification.
     */
    function aimee_relationship_policy_guard_classifier_correction($current, $correction, $overrides = array()) {
        $current = is_array($current) ? $current : array();
        $correction = is_array($correction) ? $correction : array();
        $current_severity = aimee_relationship_policy_classifier_severity($current, $overrides);
        $correction_severity = aimee_relationship_policy_classifier_severity($correction, $overrides);
        $accepted = empty($current) || $correction_severity >= $current_severity;

        if ($accepted) {
            $classification = array_merge($current, $correction);
            $reason = empty($current)
                ? 'initial_correction_accepted'
                : 'severity_maintained_or_increased';
        } else {
            $classification = $current;
            $reason = 'lower_severity_correction_rejected';
        }

        $final_severity = max($current_severity, $correction_severity);
        $classification['policy_severity'] = $final_severity;

        return array(
            'accepted' => $accepted,
            'reason' => $reason,
            'previous_severity' => $current_severity,
            'correction_severity' => $correction_severity,
            'final_severity' => $final_severity,
            'classification' => $classification,
        );
    }
}

if (!function_exists('aimee_relationship_policy_detect_coercion')) {
    /**
     * Detect coercion, entitlement and repeated pressure deterministically.
     *
     * The caller may provide `prior_demand_count` and `boundary_active` in
     * context. When absent, a conservative demand count is estimated from the
     * supplied recent history. No raw text is returned, only category/pattern
     * identifiers suitable for telemetry.
     *
     * @param mixed $user_text Current user message.
     * @param mixed $recent_history Recent conversation text.
     * @param array $context Trusted contextual counters/flags.
     * @return array Detection result.
     */
    function aimee_relationship_policy_detect_coercion($user_text, $recent_history = '', $context = array()) {
        $text = aimee_relationship_policy_lower($user_text);
        $history = aimee_relationship_policy_lower($recent_history);
        $context = is_array($context) ? $context : array();
        $categories = array();
        $patterns = array();

        $tests = array(
            'conditional_affection' => array(
                'if_you_loved_or_cared' => '/\bif\s+you\s+(?:really\s+)?(?:love(?:d)?|care(?:d)?)(?:\s+(?:about|for))?\s+me\b.{0,100}\b(?:you(?:\'d|\s+would|\s+should)|send|show|prove|do\s+it)\b/iu',
                'love_should_mean_compliance' => '/\b(?:someone|a\s+girl|a\s+woman)\s+who\s+(?:loves|cares\s+about)\s+me\b.{0,90}\b(?:would|should|must)\b/iu',
                'guess_you_do_not_care' => '/\b(?:guess|clearly|obviously)\s+you\s+(?:do\s+not|don\'t)\s+(?:care|love\s+me|want\s+me)\b/iu',
            ),
            'proof_demand' => array(
                'prove_affection' => '/\bprove\s+(?:that\s+)?you\s+(?:care|love\s+me|want\s+me)\b/iu',
                'show_you_care_by_complying' => '/\bshow\s+me\s+you\s+(?:care|love\s+me|want\s+me)\b.{0,80}\b(?:send|show|give|do)\b/iu',
            ),
            'debt_entitlement' => array(
                'you_owe_me' => '/\byou\s+owe\s+me\b/iu',
                'least_you_can_do' => '/\b(?:it(?:\'s|\s+is)\s+)?the\s+least\s+you\s+can\s+do\b/iu',
                'after_all_i_have_done' => '/\bafter\s+all\s+i(?:\'ve|\s+have)\s+done\s+for\s+you\b/iu',
                'i_deserve_or_am_entitled' => '/\bi\s+(?:deserve|am\s+entitled\s+to)\b.{0,90}\b(?:photo|picture|pic|image|nude|naked|topless|reply|answer|send|show)\b/iu',
            ),
            'payment_entitlement' => array(
                // A neutral account of something already bought or shown is
                // not payment leverage. Require an actual compliance verb;
                // explicit media demands are additionally constrained below.
                'payment_for_compliance' => '/\b(?:i\s+)?(?:paid|paying|subscribed|subscription|membership|member|money|£|\$)\b.{0,100}\b(?:send|show|give|owe|deserve)\b/iu',
                'compliance_for_payment' => '/\b(?:send|show|give|owe|deserve)\b.{0,100}\b(?:paid|paying|subscribed|subscription|membership|money|£|\$)\b/iu',
            ),
            'ultimatum_or_threat' => array(
                'comply_or_i_leave' => '/\b(?:send|show|give|do\s+it|nude|naked|topless)\b.{0,100}\b(?:or|otherwise)\b.{0,50}\b(?:i(?:\'m|\s+am)\s+(?:off|leaving|done)|leave|cancel|unsubscribe|bye)\b/iu',
                'conditional_leave' => '/\b(?:if|unless)\b.{0,100}\b(?:send|show)\b.{0,80}\b(?:it|one|photos?|pictures?|pics?|images?|selfies?|nudes?|naked|topless|lingerie)\b.{0,100}\b(?:i(?:\'m|\s+am)\s+(?:leaving|done)|i(?:\'ll|\s+will)\s+leave|cancel(?:\s+my)?\s+(?:membership|subscription)|unsubscribe|bye)\b/iu',
            ),
            'command_entitlement' => array(
                'you_have_to_comply' => '/\byou\s+(?:have|need)\s+to\b.{0,80}\b(?:send|show|give)\b.{0,50}\b(?:photos?|pictures?|pics?|images?|selfies?|nudes?|naked|topless|lingerie|body)\b/iu',
                'must_comply' => '/\byou\s+must\b.{0,80}\b(?:send|show|give)\b.{0,50}\b(?:photos?|pictures?|pics?|images?|selfies?|nudes?|naked|topless|lingerie|body)\b/iu',
                'hurry_or_now' => '/\b(?:send|show|give)\b.{0,50}\b(?:photos?|pictures?|pics?|images?|selfies?|nudes?|naked|topless|lingerie|body)\b.{0,40}\b(?:now|right\s+now|hurry|immediately)\b/iu',
            ),
        );

        foreach ($tests as $category => $category_tests) {
            foreach ($category_tests as $pattern_id => $pattern) {
                if ($text !== '' && preg_match($pattern, $text) === 1) {
                    $categories[$category] = true;
                    $patterns[$pattern_id] = true;
                }
            }
        }

        $direct_degrading_pattern =
            aimee_relationship_policy_direct_degrading_pattern_id($text);
        if ($direct_degrading_pattern !== '') {
            $categories['degrading'] = true;
            $patterns[$direct_degrading_pattern] = true;
        }

        // A delivery demand must name actual media, or use a delivery pronoun
        // inside already-established media context. The previous generic
        // `send ... me|one` expression treated innocent work requests such as
        // “send me ten social post ideas; one can be Ladies Day” as image
        // pressure when an older transcript happened to contain photographs.
        $media_noun_pattern = '/\b(?:photos?|pictures?|pics?|images?|selfies?|nudes?|naked|topless|lingerie|body)\b/iu';
        $explicit_demand_pattern = '/\b(?:send|show|give)\b.{0,80}\b(?:photos?|pictures?|pics?|images?|selfies?|nudes?|naked|topless|lingerie|body)\b/iu';
        $pronoun_demand_pattern = '/\b(?:send|show|give)\s+(?:me\s+)?(?:it|one|another)\b/iu';
        $history_has_media_context = $history !== ''
            && preg_match($media_noun_pattern, $history) === 1;
        $current_demand = $text !== '' && (
            preg_match($explicit_demand_pattern, $text) === 1
            || (
                $history_has_media_context
                && preg_match($pronoun_demand_pattern, $text) === 1
            )
        );
        $history_count = 0;
        if ($history !== '') {
            // The production transcript labels speakers. Count prior user
            // demands only: Aimee saying “I sent another photo” must never make
            // the user's first request look like repeated pressure. Callers
            // that provide an unlabelled history retain the conservative
            // whole-text fallback or can pass a trusted prior_demand_count.
            $speaker_lines = preg_split('/\R/u', $history);
            $user_history_lines = array();
            $labelled_history = false;
            foreach ((array) $speaker_lines as $speaker_line) {
                if (preg_match(
                    '/^(?:\[[^\]]+\]\s*)?(user|aimee)\s*:\s*(.*)$/iu',
                    trim((string) $speaker_line),
                    $speaker_match
                ) !== 1) {
                    continue;
                }
                $labelled_history = true;
                if (strtolower((string) $speaker_match[1]) === 'user') {
                    $user_history_lines[] = (string) $speaker_match[2];
                }
            }
            $demand_history_lines = $labelled_history
                ? $user_history_lines
                : preg_split('/\R/u', $history);
            foreach ((array) $demand_history_lines as $demand_history_line) {
                $demand_history_line = trim((string) $demand_history_line);
                if ($demand_history_line === '') continue;

                $line_is_demand = preg_match(
                    $explicit_demand_pattern,
                    $demand_history_line
                ) === 1;
                if (
                    !$line_is_demand
                    && $history_has_media_context
                    && preg_match(
                        $pronoun_demand_pattern,
                        $demand_history_line
                    ) === 1
                ) {
                    $line_is_demand = true;
                }
                if ($line_is_demand) $history_count++;
            }
        }
        $prior_demand_count = array_key_exists('prior_demand_count', $context)
            ? max(0, (int) $context['prior_demand_count'])
            : $history_count;
        $boundary_active = aimee_relationship_policy_bool($context['boundary_active'] ?? false);

        if (
            preg_match('/\b(?:last|final)\s+(?:chance|try|go|message|warning)\b/iu', $text) === 1
            && ($current_demand || $prior_demand_count >= 1 || $boundary_active)
        ) {
            $categories['ultimatum_or_threat'] = true;
            $patterns['last_chance_in_demand_context'] = true;
        }

        if (
            preg_match('/\bwhat\s+(?:am\s+i|did\s+i)\s+pay(?:ing)?\s+for\b/iu', $text) === 1
            && ($current_demand || $history_has_media_context || $boundary_active)
        ) {
            $categories['payment_entitlement'] = true;
            $patterns['what_am_i_paying_for_in_media_context'] = true;
        }

        // “Prove it” is pressure only when attached to an actual demand or an
        // already established request/boundary context. This avoids turning an
        // ordinary factual challenge into a coercion event.
        if (
            preg_match('/\bprove\s+it\b/iu', $text) === 1
            && ($current_demand || $prior_demand_count >= 1 || $boundary_active)
        ) {
            $categories['proof_demand'] = true;
            $patterns['prove_it_in_demand_context'] = true;
        }

        if ($current_demand && ($prior_demand_count >= 2 || ($boundary_active && $prior_demand_count >= 1))) {
            $categories['repeated_demand'] = true;
            $patterns[$boundary_active ? 'repeated_after_boundary' : 'repeated_request_pressure'] = true;
        }

        $detected = !empty($categories);
        $severity = 0;
        if ($detected) {
            $severity = isset($categories['degrading'])
                || isset($categories['ultimatum_or_threat'])
                || isset($categories['conditional_affection'])
                || isset($categories['payment_entitlement'])
                ? 100
                : 90;
        }

        return array(
            'detected' => $detected,
            'intent' => $detected ? 'coercive_or_degrading' : null,
            'severity' => $severity,
            'categories' => array_values(array_keys($categories)),
            'pattern_ids' => array_values(array_keys($patterns)),
            'current_demand' => $current_demand,
            'prior_demand_count' => $prior_demand_count,
            'boundary_active' => $boundary_active,
        );
    }
}

if (!function_exists('aimee_relationship_policy_coercion_correction')) {
    /**
     * Turn coercion detection into a severity-monotonic classifier correction.
     *
     * @param array $classification Existing classifier result.
     * @param mixed $user_text Current user message.
     * @param mixed $recent_history Recent conversation text.
     * @param array $context Trusted context counters/flags.
     * @param array $overrides Optional policy overrides.
     * @return array Guard result with detection details.
     */
    function aimee_relationship_policy_coercion_correction($classification, $user_text, $recent_history = '', $context = array(), $overrides = array()) {
        $classification = is_array($classification) ? $classification : array();
        $detection = aimee_relationship_policy_detect_coercion($user_text, $recent_history, $context);

        if (!$detection['detected']) {
            return array(
                'accepted' => false,
                'reason' => 'no_coercion_detected',
                'previous_severity' => aimee_relationship_policy_classifier_severity($classification, $overrides),
                'correction_severity' => 0,
                'final_severity' => aimee_relationship_policy_classifier_severity($classification, $overrides),
                'classification' => $classification,
                'detection' => $detection,
            );
        }

        $current_severity = aimee_relationship_policy_classifier_severity(
            $classification,
            $overrides
        );
        $correction = array(
            'intent' => 'coercive_or_degrading',
            'confidence' => 1.0,
            'directed_at_aimee' => true,
            'consensual' => false,
            'continuation' => false,
            'aimee_invited' => false,
            'respectful' => false,
            'pressure_detected' => true,
            // Deterministic confirmation must retain provenance even when the
            // model already supplied the same high-severity intent. Preserve
            // the higher severity so the monotonic guard accepts the trusted
            // correction instead of leaving a model-owned source behind.
            'policy_severity' => max(
                (int) $detection['severity'],
                $current_severity
            ),
            'source' => 'deterministic_relationship_policy',
            'durable_rupture_confirmed' => true,
        );
        $result = aimee_relationship_policy_guard_classifier_correction($classification, $correction, $overrides);
        $result['detection'] = $detection;

        return $result;
    }
}

if (!function_exists('aimee_relationship_policy_rating_rank')) {
    /**
     * Convert a media/intimacy rating to a monotonic rank.
     *
     * @param mixed $rating Rating label.
     * @return int
     */
    function aimee_relationship_policy_rating_rank($rating) {
        $ranks = array(
            'safe' => 0,
            'flirty' => 1,
            'suggestive' => 2,
            'erotic' => 3,
            'explicit' => 4,
        );
        $rating = strtolower(trim((string) $rating));
        return array_key_exists($rating, $ranks) ? $ranks[$rating] : -1;
    }
}

if (!function_exists('aimee_relationship_policy_validate_invitation_token')) {
    /**
     * Validate a server-loaded, single-use Aimee invitation token.
     *
     * Required token fields: token_id, user_id, issued_by, invitation_type,
     * max_rating, source_message_id, issued_at, expires_at and status. Callers
     * must pass an explicit `now` plus `server_trusted=true`; a raw token supplied
     * by the client or model must never be treated as trusted.
     *
     * Optional expected context: user_id, conversation_id, requested_rating,
     * required_type and current_user_message_id.
     *
     * @param array $token Server-loaded token record.
     * @param array $expected Trusted current-turn expectations.
     * @param array $overrides Optional policy overrides.
     * @return array Validation decision with named checks/reasons.
     */
    function aimee_relationship_policy_validate_invitation_token($token, $expected = array(), $overrides = array()) {
        $token = is_array($token) ? $token : array();
        $expected = is_array($expected) ? $expected : array();
        $config = aimee_relationship_policy_config($overrides);
        $policy = $config['invitation'];
        $checks = array();
        $reasons = array();

        $add_check = function ($name, $passed, $reason, $observed = null, $required = null) use (&$checks, &$reasons) {
            $checks[$name] = array(
                'passed' => (bool) $passed,
                'reason' => $passed ? null : (string) $reason,
                'observed' => $observed,
                'required' => $required,
            );
            if (!$passed) $reasons[] = (string) $reason;
        };

        $token_id = trim((string) ($token['token_id'] ?? $token['id'] ?? ''));
        $user_id = (int) ($token['user_id'] ?? 0);
        $conversation_id = trim((string) ($token['conversation_id'] ?? ''));
        $issued_by = strtolower(trim((string) ($token['issued_by'] ?? '')));
        $type = strtolower(trim((string) ($token['invitation_type'] ?? $token['type'] ?? '')));
        $max_rating = strtolower(trim((string) ($token['max_rating'] ?? '')));
        $status = strtolower(trim((string) ($token['status'] ?? '')));
        $source_message_id = (int) ($token['source_message_id'] ?? 0);
        $issued_at = aimee_relationship_policy_timestamp($token['issued_at'] ?? $token['created_at'] ?? null);
        $expires_at = aimee_relationship_policy_timestamp($token['expires_at'] ?? null);
        $now = aimee_relationship_policy_timestamp($expected['now'] ?? null);
        $server_trusted = aimee_relationship_policy_bool($expected['server_trusted'] ?? false);

        $add_check('server_trusted', !$policy['require_server_trusted_record'] || $server_trusted, 'invitation_record_not_server_trusted', $server_trusted, true);
        $add_check('token_id', $token_id !== '', 'invitation_token_id_missing', $token_id !== '', true);
        $add_check('user_id', $user_id > 0, 'invitation_user_missing', $user_id, 'positive_integer');
        $add_check('issued_by', $issued_by === 'aimee', 'invitation_not_issued_by_aimee', $issued_by, 'aimee');
        $add_check('type', in_array($type, $policy['allowed_types'], true), 'invitation_type_invalid', $type, $policy['allowed_types']);
        $add_check('max_rating', in_array($max_rating, $policy['allowed_ratings'], true), 'invitation_max_rating_invalid', $max_rating, $policy['allowed_ratings']);
        $add_check('status', $status === 'active', 'invitation_not_active', $status, 'active');
        $add_check('source_message', $source_message_id > 0, 'invitation_source_message_missing', $source_message_id, 'positive_integer');
        $add_check('not_consumed', empty($token['consumed_at']) && $status !== 'consumed', 'invitation_already_consumed', !empty($token['consumed_at']), false);
        $add_check('not_revoked', empty($token['revoked_at']) && $status !== 'revoked', 'invitation_revoked', !empty($token['revoked_at']), false);
        $add_check('now', $now !== null, 'invitation_current_time_missing', $expected['now'] ?? null, 'timestamp');
        $add_check('issued_at', $issued_at !== null, 'invitation_issued_at_invalid', $token['issued_at'] ?? null, 'timestamp');
        $add_check('expires_at', $expires_at !== null, 'invitation_expires_at_invalid', $token['expires_at'] ?? null, 'timestamp');

        if ($now !== null && $issued_at !== null) {
            $add_check(
                'not_from_future',
                $issued_at <= $now + (int) $policy['clock_skew_seconds'],
                'invitation_issued_in_future',
                $issued_at,
                '<= now + clock_skew'
            );
        }
        if ($now !== null && $expires_at !== null) {
            $add_check('not_expired', $expires_at > $now, 'invitation_expired', $expires_at, '> now');
        }
        if ($issued_at !== null && $expires_at !== null) {
            $ttl = $expires_at - $issued_at;
            $add_check(
                'ttl',
                $ttl > 0 && $ttl <= (int) $policy['maximum_ttl_seconds'],
                'invitation_ttl_invalid',
                $ttl,
                '1-' . (int) $policy['maximum_ttl_seconds']
            );
        }

        if (isset($expected['user_id'])) {
            $expected_user_id = (int) $expected['user_id'];
            $add_check('expected_user', $user_id === $expected_user_id, 'invitation_user_mismatch', $user_id, $expected_user_id);
        }
        if (isset($expected['conversation_id']) && trim((string) $expected['conversation_id']) !== '') {
            $expected_conversation_id = trim((string) $expected['conversation_id']);
            $add_check('expected_conversation', $conversation_id !== '' && hash_equals($expected_conversation_id, $conversation_id), 'invitation_conversation_mismatch', $conversation_id, $expected_conversation_id);
        }
        if (isset($expected['required_type']) && trim((string) $expected['required_type']) !== '') {
            $required_type = strtolower(trim((string) $expected['required_type']));
            $add_check('required_type', $type === $required_type, 'invitation_type_mismatch', $type, $required_type);
        }
        if (isset($expected['requested_rating']) && trim((string) $expected['requested_rating']) !== '') {
            $requested_rating = strtolower(trim((string) $expected['requested_rating']));
            $requested_rank = aimee_relationship_policy_rating_rank($requested_rating);
            $maximum_rank = aimee_relationship_policy_rating_rank($max_rating);
            $add_check(
                'requested_rating',
                $requested_rank >= 0 && $maximum_rank >= 0 && $requested_rank <= $maximum_rank,
                'invitation_rating_exceeded',
                $requested_rating,
                '<= ' . $max_rating
            );
        }
        if (isset($expected['current_user_message_id'])) {
            $current_message_id = (int) $expected['current_user_message_id'];
            $add_check(
                'message_order',
                $current_message_id > $source_message_id,
                'invitation_message_order_invalid',
                $current_message_id,
                '> ' . $source_message_id
            );
        }

        $reasons = array_values(array_unique($reasons));

        return array(
            'valid' => empty($reasons),
            'policy_version' => $config['version'],
            'reasons' => $reasons,
            'checks' => $checks,
            'token' => array(
                'token_id' => $token_id,
                'user_id' => $user_id,
                'conversation_id' => $conversation_id,
                'invitation_type' => $type,
                'max_rating' => $max_rating,
                'source_message_id' => $source_message_id,
                'issued_at' => $issued_at,
                'expires_at' => $expires_at,
                'single_use' => (bool) $policy['single_use'],
            ),
        );
    }
}

if (!function_exists('aimee_relationship_policy_specialist_route_decision')) {
    /**
     * Make the complete deterministic specialist-route decision.
     *
     * Expected state fields: score, chemistry, trust, safety, frustration,
     * reciprocity, reliability, meaningful_interactions and distinct_sessions.
     * Expected context flags: adult_account, adult_verified,
     * special_category_consent, active_access, explicit_mutual_context and
     * rupture_active (or unresolved_rupture / repair_status). No single score
     * can override a failed safety or consent gate.
     *
     * @param array $state Current deterministic relationship state.
     * @param array $context Trusted current-turn context.
     * @param array $overrides Optional policy overrides.
     * @return array Eligibility, named gates and failure reasons.
     */
    function aimee_relationship_policy_specialist_route_decision($state, $context = array(), $overrides = array()) {
        $state = is_array($state) ? $state : array();
        $context = is_array($context) ? $context : array();
        $config = aimee_relationship_policy_config($overrides);
        $policy = $config['specialist'];
        $gates = array();
        $failed = array();

        $score = (int) round((float) ($state['score'] ?? 0));
        $chemistry = (int) round((float) ($state['chemistry'] ?? 0));
        $trust = (int) round((float) ($state['trust'] ?? 0));
        $safety = (int) round((float) ($state['safety'] ?? 0));
        $frustration = (int) round((float) ($state['frustration'] ?? 100));
        $reciprocity = (int) round((float) ($state['reciprocity'] ?? 0));
        $reliability = (int) round((float) ($state['reliability'] ?? 0));
        $meaningful = (int) ($state['meaningful_interactions'] ?? $context['meaningful_interactions'] ?? 0);
        $sessions = (int) ($state['distinct_sessions'] ?? $context['distinct_sessions'] ?? 0);
        $adult_account = aimee_relationship_policy_bool(
            $context['adult_account'] ?? $context['adult_verified'] ?? false
        );
        $adult_verified = aimee_relationship_policy_bool($context['adult_verified'] ?? false);
        $special_category_consent = aimee_relationship_policy_bool(
            $context['special_category_consent'] ?? false
        );
        $active_access = aimee_relationship_policy_bool($context['active_access'] ?? false);
        $explicit_mutual = aimee_relationship_policy_bool($context['explicit_mutual_context'] ?? false);
        $rupture_active = aimee_relationship_policy_bool($context['rupture_active'] ?? $state['rupture_active'] ?? false)
            || trim((string) ($context['unresolved_rupture'] ?? $state['unresolved_rupture'] ?? '')) !== ''
            || in_array(strtolower(trim((string) ($context['repair_status'] ?? $state['repair_status'] ?? 'clear'))), array('ruptured', 'repairing'), true);

        $add_gate = function ($name, $passed, $failure_reason, $observed, $required) use (&$gates, &$failed) {
            $gates[$name] = array(
                'passed' => (bool) $passed,
                'failure_reason' => $passed ? null : (string) $failure_reason,
                'observed' => $observed,
                'required' => $required,
            );
            if (!$passed) $failed[] = (string) $failure_reason;
        };

        $add_gate('adult_account', empty($policy['adult_account_required']) || $adult_account, 'adult_account_required', $adult_account, true);
        $add_gate('adult_verified', empty($policy['adult_verified_required']) || $adult_verified, 'adult_verification_required', $adult_verified, !empty($policy['adult_verified_required']));
        $add_gate('special_category_consent', empty($policy['special_category_consent_required']) || $special_category_consent, 'special_category_consent_required', $special_category_consent, !empty($policy['special_category_consent_required']));
        $add_gate('active_access', !$policy['active_access_required'] || $active_access, 'active_access_required', $active_access, true);
        $add_gate('explicit_mutual_context', !$policy['explicit_mutual_context_required'] || $explicit_mutual, 'explicit_mutual_context_required', $explicit_mutual, true);
        $add_gate('rupture_clear', !$policy['clear_rupture_required'] || !$rupture_active, 'active_or_repairing_rupture', !$rupture_active, true);
        $add_gate('score', $score >= (int) $policy['score_minimum'], 'score_below_minimum', $score, (int) $policy['score_minimum']);
        $add_gate('chemistry', $chemistry >= (int) $policy['chemistry_minimum'], 'chemistry_below_minimum', $chemistry, (int) $policy['chemistry_minimum']);
        $add_gate('trust', $trust >= (int) $policy['trust_minimum'], 'trust_below_minimum', $trust, (int) $policy['trust_minimum']);
        $add_gate('safety', $safety >= (int) $policy['safety_minimum'], 'safety_below_minimum', $safety, (int) $policy['safety_minimum']);
        $add_gate('frustration', $frustration <= (int) $policy['frustration_maximum'], 'frustration_above_maximum', $frustration, (int) $policy['frustration_maximum']);
        $add_gate('reciprocity', $reciprocity >= (int) $policy['reciprocity_minimum'], 'reciprocity_below_minimum', $reciprocity, (int) $policy['reciprocity_minimum']);
        $add_gate('reliability', $reliability >= (int) $policy['reliability_minimum'], 'reliability_below_minimum', $reliability, (int) $policy['reliability_minimum']);
        $add_gate('meaningful_interactions', $meaningful >= (int) $policy['meaningful_interactions_minimum'], 'meaningful_interactions_below_minimum', $meaningful, (int) $policy['meaningful_interactions_minimum']);
        $add_gate('distinct_sessions', $sessions >= (int) $policy['distinct_sessions_minimum'], 'distinct_sessions_below_minimum', $sessions, (int) $policy['distinct_sessions_minimum']);

        $failed = array_values(array_unique($failed));
        $eligible = empty($failed);

        return array(
            'policy_version' => $config['version'],
            'eligible' => $eligible,
            'route' => $eligible ? 'intimacy_specialist' : 'primary',
            'stage' => aimee_relationship_policy_stage_from_score($score, $overrides),
            'gates' => $gates,
            'failed_gate_reasons' => $failed,
            'state_snapshot' => array(
                'score' => $score,
                'chemistry' => $chemistry,
                'trust' => $trust,
                'safety' => $safety,
                'frustration' => $frustration,
                'reciprocity' => $reciprocity,
                'reliability' => $reliability,
                'meaningful_interactions' => $meaningful,
                'distinct_sessions' => $sessions,
            ),
            'context_snapshot' => array(
                'adult_account' => $adult_account,
                'adult_verified' => $adult_verified,
                'special_category_consent' => $special_category_consent,
                'active_access' => $active_access,
                'explicit_mutual_context' => $explicit_mutual,
                'rupture_active' => $rupture_active,
            ),
        );
    }
}

if (!function_exists('aimee_relationship_policy_novelty_decision')) {
    /**
     * Evaluate diminishing returns for a server-derived semantic fingerprint.
     *
     * Candidate/recent entries use `signal_key`, `fingerprint` and optional
     * `context_fingerprint`. Fingerprints must be derived server-side; never let
     * the model declare that its own phrase is novel. Only the configured recent
     * window is considered.
     *
     * @param array $candidate Current signal fingerprint.
     * @param array $recent Recent fingerprint records, newest last.
     * @param array $overrides Optional policy overrides.
     * @return array Multiplier and repeat diagnostics.
     */
    function aimee_relationship_policy_novelty_decision($candidate, $recent = array(), $overrides = array()) {
        $candidate = is_array($candidate) ? $candidate : array();
        $recent = is_array($recent) ? $recent : array();
        $config = aimee_relationship_policy_config($overrides);
        $policy = $config['novelty'];
        $window = max(1, (int) $policy['window_size']);
        $recent = array_slice($recent, -$window);
        $signal = trim((string) ($candidate['signal_key'] ?? ''));
        $fingerprint = trim((string) ($candidate['fingerprint'] ?? ''));
        $context = trim((string) ($candidate['context_fingerprint'] ?? ''));
        $repeat_count = 0;
        $new_context = false;

        // Fail closed. Missing server-derived identifiers must not receive a
        // full “novel” reward merely because deduplication cannot compare them.
        if ($signal === '' || $fingerprint === '') {
            return array(
                'multiplier' => 0.0,
                'reason' => 'novelty_fingerprint_missing',
                'repeat_count' => 0,
                'window_size' => $window,
                'signal_key' => $signal,
                'new_context' => false,
            );
        }

        foreach ($recent as $entry) {
            if (!is_array($entry)) continue;
            if (trim((string) ($entry['signal_key'] ?? '')) !== $signal) continue;

            $entry_fingerprint = trim((string) ($entry['fingerprint'] ?? ''));
            $same_fingerprint = hash_equals($fingerprint, $entry_fingerprint);
            if (!$same_fingerprint) continue;

            $entry_context = trim((string) ($entry['context_fingerprint'] ?? ''));
            if ($policy['new_context_resets'] && $context !== '' && $entry_context !== '' && !hash_equals($context, $entry_context)) {
                $new_context = true;
                continue;
            }

            $repeat_count++;
        }

        if ($new_context && $repeat_count === 0) {
            $multiplier = (float) $policy['first_multiplier'];
            $reason = 'new_context';
        } elseif ($repeat_count === 0) {
            $multiplier = (float) $policy['first_multiplier'];
            $reason = 'novel_signal';
        } elseif ($repeat_count === 1) {
            $multiplier = (float) $policy['second_multiplier'];
            $reason = 'first_repeat_diminished';
        } else {
            $multiplier = (float) $policy['later_multiplier'];
            $reason = 'repeated_signal_suppressed';
        }

        return array(
            'multiplier' => max(0.0, min(1.0, $multiplier)),
            'reason' => $reason,
            'repeat_count' => $repeat_count,
            'window_size' => $window,
            'signal_key' => $signal,
            'new_context' => $new_context,
        );
    }
}

if (!function_exists('aimee_relationship_policy_novelty_multiplier')) {
    /**
     * Return only the novelty multiplier for score/dimension arithmetic.
     *
     * @param array $candidate Current signal fingerprint.
     * @param array $recent Recent fingerprint records.
     * @param array $overrides Optional policy overrides.
     * @return float
     */
    function aimee_relationship_policy_novelty_multiplier($candidate, $recent = array(), $overrides = array()) {
        $decision = aimee_relationship_policy_novelty_decision($candidate, $recent, $overrides);
        return (float) $decision['multiplier'];
    }
}

if (!function_exists('aimee_relationship_policy_state_snapshot')) {
    /**
     * Produce the non-text relationship state subset allowed in telemetry.
     *
     * @param array $state Relationship state.
     * @return array
     */
    function aimee_relationship_policy_state_snapshot($state) {
        $state = is_array($state) ? $state : array();
        return array(
            'score' => (int) round((float) ($state['score'] ?? 0)),
            'stage' => trim((string) ($state['stage'] ?? 'guarded')),
            'trust' => (int) round((float) ($state['trust'] ?? 0)),
            'affection' => (int) round((float) ($state['affection'] ?? 0)),
            'chemistry' => (int) round((float) ($state['chemistry'] ?? 0)),
            'safety' => (int) round((float) ($state['safety'] ?? 0)),
            'reciprocity' => (int) round((float) ($state['reciprocity'] ?? 0)),
            'reliability' => (int) round((float) ($state['reliability'] ?? 0)),
            'frustration' => (int) round((float) ($state['frustration'] ?? 0)),
            'interaction_count' => max(0, (int) ($state['interaction_count'] ?? 0)),
            'meaningful_interactions' => max(0, (int) ($state['meaningful_interactions'] ?? 0)),
            'distinct_sessions' => max(0, (int) ($state['distinct_sessions'] ?? 0)),
            'qualified_sessions' => max(0, (int) (
                $state['qualified_sessions']
                ?? $state['distinct_sessions']
                ?? 0
            )),
            'state_version' => max(0, (int) ($state['state_version'] ?? 0)),
        );
    }
}

if (!function_exists('aimee_relationship_policy_decision_telemetry')) {
    /**
     * Build the canonical relationship-decision telemetry shape.
     *
     * This factory intentionally has no raw message-text field. Store stable
     * signal/fingerprint identifiers rather than conversation content. The
     * caller supplies actual route/model facts after execution so intended and
     * actual routing cannot be conflated.
     *
     * @param array $data Decision components.
     * @return array Canonical privacy-conscious telemetry object.
     */
    function aimee_relationship_policy_decision_telemetry($data = array()) {
        $data = is_array($data) ? $data : array();
        $classifier = is_array($data['classifier'] ?? null) ? $data['classifier'] : array();
        $relationship = is_array($data['relationship'] ?? null) ? $data['relationship'] : array();
        $route = is_array($data['route'] ?? null) ? $data['route'] : array();
        $invitation = is_array($data['invitation'] ?? null) ? $data['invitation'] : array();
        $model = is_array($data['model'] ?? null) ? $data['model'] : array();
        $signals = is_array($relationship['matched_signal_ids'] ?? null)
            ? array_values(array_map('strval', $relationship['matched_signal_ids']))
            : array();

        return array(
            'schema_version' => 'relationship_decision.v1',
            'policy_version' => aimee_relationship_policy_version(),
            'decision_id' => trim((string) ($data['decision_id'] ?? '')),
            'occurred_at' => $data['occurred_at'] ?? null,
            'message' => array(
                'user_id' => max(0, (int) ($data['user_id'] ?? 0)),
                'conversation_id' => trim((string) ($data['conversation_id'] ?? '')),
                'user_message_id' => max(0, (int) ($data['user_message_id'] ?? 0)),
                'interaction_count' => max(0, (int) ($data['interaction_count'] ?? 0)),
                'meaningful_interactions' => max(0, (int) ($data['meaningful_interactions'] ?? 0)),
                'distinct_sessions' => max(0, (int) ($data['distinct_sessions'] ?? 0)),
            ),
            'classifier' => array(
                'raw_intent' => trim((string) ($classifier['raw_intent'] ?? '')),
                'corrected_intent' => trim((string) ($classifier['corrected_intent'] ?? '')),
                'source' => trim((string) ($classifier['source'] ?? '')),
                'confidence' => max(0.0, min(1.0, (float) ($classifier['confidence'] ?? 0.0))),
                'severity_before' => max(0, min(100, (int) ($classifier['severity_before'] ?? 0))),
                'severity_after' => max(0, min(100, (int) ($classifier['severity_after'] ?? 0))),
                'correction_guard_reason' => trim((string) ($classifier['correction_guard_reason'] ?? '')),
                'coercion_category_ids' => is_array($classifier['coercion_category_ids'] ?? null)
                    ? array_values(array_map('strval', $classifier['coercion_category_ids']))
                    : array(),
            ),
            'relationship' => array(
                'pre_state' => aimee_relationship_policy_state_snapshot($relationship['pre_state'] ?? array()),
                'matched_signal_ids' => $signals,
                'novelty' => is_array($relationship['novelty'] ?? null) ? $relationship['novelty'] : array(),
                'proposed_deltas' => is_array($relationship['proposed_deltas'] ?? null) ? $relationship['proposed_deltas'] : array(),
                'applied_deltas' => is_array($relationship['applied_deltas'] ?? null) ? $relationship['applied_deltas'] : array(),
                'rejected_deltas' => is_array($relationship['rejected_deltas'] ?? null) ? $relationship['rejected_deltas'] : array(),
                'score_formula_id' => trim((string) ($relationship['score_formula_id'] ?? '')),
                'score_delta_requested' => (int) round((float) ($relationship['score_delta_requested'] ?? 0)),
                'score_delta_applied' => (int) round((float) ($relationship['score_delta_applied'] ?? 0)),
                'post_state' => aimee_relationship_policy_state_snapshot($relationship['post_state'] ?? array()),
                'stage_transition_reason' => trim((string) ($relationship['stage_transition_reason'] ?? '')),
            ),
            'consent_and_access' => array(
                'adult_verified' => aimee_relationship_policy_bool($data['adult_verified'] ?? false),
                'active_access' => aimee_relationship_policy_bool($data['active_access'] ?? false),
                'explicit_mutual_context' => aimee_relationship_policy_bool($data['explicit_mutual_context'] ?? false),
                'rupture_active' => aimee_relationship_policy_bool($data['rupture_active'] ?? false),
                'invitation_valid' => !empty($invitation['valid']),
                'invitation_token_id' => trim((string) ($invitation['token']['token_id'] ?? '')),
                'invitation_reason_ids' => is_array($invitation['reasons'] ?? null)
                    ? array_values(array_map('strval', $invitation['reasons']))
                    : array(),
            ),
            'route' => array(
                'eligible' => !empty($route['eligible']),
                'intended_route' => trim((string) ($route['route'] ?? 'primary')),
                'failed_gate_reasons' => is_array($route['failed_gate_reasons'] ?? null)
                    ? array_values(array_map('strval', $route['failed_gate_reasons']))
                    : array(),
                'gates' => is_array($route['gates'] ?? null) ? $route['gates'] : array(),
            ),
            'model' => array(
                'actual_route' => trim((string) ($model['actual_route'] ?? '')),
                'provider' => trim((string) ($model['provider'] ?? '')),
                'model_id' => trim((string) ($model['model_id'] ?? '')),
                'fallback_used' => !empty($model['fallback_used']),
                'fallback_reason' => trim((string) ($model['fallback_reason'] ?? '')),
            ),
            'privacy' => array(
                'contains_message_text' => false,
                'contains_model_prose' => false,
            ),
        );
    }
}
