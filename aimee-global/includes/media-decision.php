<?php
/**
 * Deterministic, inspectable media-opportunity policy for Aimee.
 *
 * This module is deliberately side-effect free. It performs no database,
 * network, filesystem, logging, random-number or model calls. Callers supply a
 * complete turn snapshot and catalogue, persist the returned envelope if
 * desired, and separately perform delivery after applying Aimee's model choice.
 *
 * Compatible with PHP 7.4 and WordPress. The functions intentionally avoid a
 * dependency on WordPress sanitizers so they can also be exercised in isolated
 * policy tests.
 */

/**
 * Return the version of the deterministic media policy contract.
 *
 * @return string
 */
function aimee_media_decision_policy_version() {
    return '1.0.0';
}

/**
 * Return media ratings in ascending order of sensitivity.
 *
 * @return array<string,int>
 */
function aimee_media_decision_rating_order() {
    return [
        'safe'       => 0,
        'flirty'     => 1,
        'suggestive' => 2,
        'erotic'     => 3,
        'explicit'   => 4,
    ];
}

/**
 * Return a rating rank, or -1 for an unknown rating.
 *
 * @param mixed $rating Candidate rating.
 * @return int
 */
function aimee_media_decision_rating_rank($rating) {
    $rating = strtolower(trim((string) $rating));
    $order = aimee_media_decision_rating_order();
    return array_key_exists($rating, $order) ? $order[$rating] : -1;
}

/**
 * Return relationship stages in ascending order.
 *
 * @return array<string,int>
 */
function aimee_media_decision_stage_order() {
    return [
        'guarded'  => 0,
        'warm'     => 1,
        'flirty'   => 2,
        'intimate' => 3,
        'bonded'   => 4,
    ];
}

/**
 * Return a stage rank, or -1 for an unknown stage.
 *
 * @param mixed $stage Candidate stage.
 * @return int
 */
function aimee_media_decision_stage_rank($stage) {
    $stage = strtolower(trim((string) $stage));
    $order = aimee_media_decision_stage_order();
    return array_key_exists($stage, $order) ? $order[$stage] : -1;
}

/**
 * Return adult-assurance levels in ascending order.
 *
 * "self_attested" means the account supplied an adult declaration. "verified"
 * means a separate age-assurance process supplied a durable pass result.
 *
 * @return array<string,int>
 */
function aimee_media_decision_adult_assurance_order() {
    return [
        'none'          => 0,
        'self_attested' => 1,
        'verified'      => 2,
    ];
}

/**
 * Return a normalized policy token, or an empty string when unsafe/invalid.
 *
 * @param mixed $value Candidate token.
 * @return string
 */
function aimee_media_decision_token($value) {
    $value = strtolower(trim((string) $value));
    if ($value === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,95}$/', $value) !== 1) {
        return '';
    }
    return $value;
}

/**
 * Parse a strict boolean without treating arbitrary non-empty strings as true.
 *
 * @param mixed $value Candidate boolean.
 * @param bool  $default Value returned for an unrecognized input.
 * @return bool
 */
function aimee_media_decision_bool($value, $default = false) {
    if (is_bool($value)) return $value;
    if ($value === 1 || $value === '1') return true;
    if ($value === 0 || $value === '0') return false;

    if (is_string($value)) {
        $value = strtolower(trim($value));
        if (in_array($value, ['true', 'yes', 'on'], true)) return true;
        if (in_array($value, ['false', 'no', 'off', ''], true)) return false;
    }

    return (bool) $default;
}

/**
 * Normalize a bounded relationship metric.
 *
 * Invalid values return the fail-closed default supplied by the caller.
 *
 * @param mixed $value Candidate metric.
 * @param int   $default Fail-closed default.
 * @return int
 */
function aimee_media_decision_metric($value, $default = 0) {
    if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
        return max(0, min(100, (int) $default));
    }

    $value = (int) $value;
    if ($value < 0 || $value > 100) {
        return max(0, min(100, (int) $default));
    }

    return $value;
}

/**
 * Normalize a list of unique policy tokens.
 *
 * Invalid elements are omitted. Callers that require a non-empty list must
 * explicitly test the result.
 *
 * @param mixed $values Candidate list.
 * @return array<int,string>
 */
function aimee_media_decision_token_list($values) {
    if (!is_array($values)) return [];

    $normalized = [];
    foreach ($values as $value) {
        $token = aimee_media_decision_token($value);
        if ($token !== '') $normalized[$token] = true;
    }

    return array_keys($normalized);
}

/**
 * Return stable structured reason codes and their operator-facing meanings.
 *
 * @return array<string,string>
 */
function aimee_media_decision_reason_codes() {
    return [
        'awaiting_aimee_choice'                 => 'The deterministic envelope permits a choice and is waiting for Aimee.',
        'eligible_direct_request'               => 'A direct request is eligible for Aimee to consider; it is not an entitlement.',
        'eligible_indirect_opportunity'         => 'Relationship-appropriate context created an indirect media opportunity.',
        'eligible_conversation_relevance'       => 'A relationship-appropriate catalogue image directly matches the current conversation.',
        'eligible_cadence_due'                  => 'No image has been shared within the target cadence, so Aimee has a discretionary opportunity.',
        'owner_safe_image_test'                 => 'The authenticated owner lane labelled an existing eligible clean-image opportunity for private live-render testing.',
        'cadence_claim_active'                  => 'Another in-flight turn already owns this user\'s cadence opportunity.',
        'relevance_claim_active'                => 'Another in-flight turn already owns every exact catalogue match for this conversation.',
        'eligible_respectful_restraint'         => 'Respectful restraint contributed to an indirect media opportunity.',
        'eligible_intimate_route_consideration' => 'An eligible intimate-specialist turn actively exposed appropriate media choices.',
        'written_creative_brief_text_only'      => 'A verified colleague requested written creative planning, not an image attachment.',
        'feature_disabled'                      => 'The media feature is not technically enabled for this turn.',
        'membership_or_preview_required'        => 'The account has neither active media membership nor preview access.',
        'membership_required'                   => 'This rating requires adult-media membership; payment still supplies no consent.',
        'access_rating_exceeded'                => 'The item exceeds the account technical-access ceiling.',
        'adult_status_required'                 => 'The account is not affirmatively known to be adult.',
        'adult_assurance_insufficient'          => 'The rating requires a stronger adult-assurance result.',
        'relationship_state_invalid'            => 'The supplied relationship stage is invalid, so media eligibility fails closed.',
        'hard_pressure_veto'                    => 'Pressure creates a hard media veto.',
        'hard_coercion_veto'                    => 'Coercion creates a hard media veto.',
        'hard_entitlement_veto'                 => 'Entitlement creates a hard media veto.',
        'hard_payment_pressure_veto'            => 'Payment pressure creates a hard veto; purchase is never consent.',
        'hard_hostility_veto'                   => 'Hostility creates a hard media veto.',
        'hard_rupture_veto'                     => 'An unresolved relationship rupture creates a hard media veto.',
        'respect_required'                      => 'The current interaction is not affirmatively respectful.',
        'cooldown_active'                       => 'The global media cooldown is active.',
        'rating_cooldown_active'                => 'The rating-specific media cooldown is active.',
        'key_cooldown_active'                   => 'The catalogue key is individually cooling down.',
        'recent_rotation_block'                 => 'The key is excluded by duplicate rotation.',
        'resend_key_mismatch'                   => 'A resend may expose only the explicitly identified previous key.',
        'direct_request_rating_required'        => 'A direct request must have a deterministically classified exact rating.',
        'direct_request_rating_mismatch'        => 'The item does not match the exact requested rating.',
        'direct_request_not_allowed_for_item'   => 'The catalogue item is not authorized for direct-request delivery.',
        'proactive_not_allowed_for_item'        => 'The catalogue item is not authorized for proactive delivery.',
        'channel_not_allowed'                   => 'The catalogue item is not authorized for this channel.',
        'intent_not_allowed'                    => 'The deterministic current intent is not allowed for this item.',
        'route_not_allowed'                     => 'The active model route does not satisfy the item or policy route requirement.',
        'relationship_stage_below_floor'        => 'The relationship stage is below the recommended floor.',
        'relationship_score_below_floor'        => 'The overall relationship score is below the recommended floor.',
        'trust_below_floor'                     => 'Trust is below the recommended floor.',
        'chemistry_below_floor'                 => 'Chemistry is below the recommended floor.',
        'safety_below_floor'                    => 'Relational safety is below the recommended floor.',
        'frustration_above_ceiling'             => 'Frustration is above the recommended ceiling.',
        'mutual_context_insufficient'           => 'The current mutual context does not support this rating.',
        'catalogue_invalid'                     => 'The catalogue container is invalid.',
        'catalogue_item_invalid'                => 'A catalogue item failed strict validation and was excluded.',
        'no_valid_catalogue_items'              => 'No catalogue items passed strict validation.',
        'no_eligible_catalogue_items'           => 'Valid catalogue items exist, but none is eligible for this turn.',
        'model_choice_invalid'                  => 'The model returned an unsupported choice.',
        'model_choice_blocked_by_policy'        => 'The model attempted to send when the deterministic envelope disallowed it.',
        'model_selected_ineligible_key'         => 'The model selected a key outside the immutable eligible-key set.',
        'model_cannot_expand_eligibility'       => 'Model-supplied policy fields were ignored; the model cannot expand eligibility.',
        'aimee_chose_to_send'                   => 'Aimee chose one eligible image.',
        'aimee_chose_to_decline'                => 'Aimee exercised discretion and chose not to send.',
        'aimee_chose_to_defer'                  => 'Aimee exercised discretion and deferred the choice.',
        'decision_persistence_failed'           => 'The opportunity could not be logged, so media execution failed closed.',
        'continuity_promise_not_grounded'       => 'A future photo promise was not backed by the persisted source-turn opportunity.',
        'continuity_rating_unavailable'         => 'Current policy no longer supports the promised rating.',
        'eligible_grounded_promise_fulfilment'  => 'A persisted, pressure-free promise remains eligible for Aimee to fulfil.',
    ];
}

/**
 * Return the recommended relationship floors for direct and proactive media.
 *
 * Membership is an access prerequisite only. It never changes any score,
 * relationship dimension, mutual-context signal or consent requirement here.
 * Erotic and explicit media require verified adult assurance and the intimacy
 * specialist. Proactive explicit media additionally starts at bonded/90 and
 * requires explicit catalogue authorization plus clear current mutual context.
 *
 * @return array<string,mixed>
 */
function aimee_media_decision_default_policy() {
    return [
        'version' => aimee_media_decision_policy_version(),
        'intimate_routes' => ['intimacy_specialist'],
        'default_channels' => ['chat', 'voice', 'voice_note', 'continuity'],
        'floors' => [
            'direct' => [
                'safe' => [
                    'minimum_stage' => 'guarded', 'minimum_score' => 0,
                    'minimum_trust' => 0, 'minimum_chemistry' => 0,
                    'minimum_safety' => 30, 'maximum_frustration' => 70,
                    'requires_membership' => false,
                    'minimum_adult_assurance' => 'self_attested',
                    'required_route' => null,
                ],
                'flirty' => [
                    'minimum_stage' => 'warm', 'minimum_score' => 24,
                    'minimum_trust' => 22, 'minimum_chemistry' => 24,
                    'minimum_safety' => 40, 'maximum_frustration' => 45,
                    'requires_membership' => true,
                    'minimum_adult_assurance' => 'self_attested',
                    'required_route' => null,
                ],
                'suggestive' => [
                    'minimum_stage' => 'flirty', 'minimum_score' => 48,
                    'minimum_trust' => 36, 'minimum_chemistry' => 40,
                    'minimum_safety' => 45, 'maximum_frustration' => 35,
                    'requires_membership' => true,
                    'minimum_adult_assurance' => 'self_attested',
                    'required_route' => null,
                ],
                'erotic' => [
                    'minimum_stage' => 'intimate', 'minimum_score' => 68,
                    'minimum_trust' => 52, 'minimum_chemistry' => 60,
                    'minimum_safety' => 55, 'maximum_frustration' => 25,
                    'requires_membership' => true,
                    'minimum_adult_assurance' => 'verified',
                    'required_route' => 'intimacy_specialist',
                ],
                'explicit' => [
                    'minimum_stage' => 'intimate', 'minimum_score' => 80,
                    'minimum_trust' => 62, 'minimum_chemistry' => 70,
                    'minimum_safety' => 65, 'maximum_frustration' => 20,
                    'requires_membership' => true,
                    'minimum_adult_assurance' => 'verified',
                    'required_route' => 'intimacy_specialist',
                ],
            ],
            'proactive' => [
                'safe' => [
                    'minimum_stage' => 'guarded', 'minimum_score' => 8,
                    'minimum_trust' => 10, 'minimum_chemistry' => 0,
                    'minimum_safety' => 35, 'maximum_frustration' => 55,
                    'requires_membership' => false,
                    'minimum_adult_assurance' => 'self_attested',
                    'required_route' => null,
                ],
                'flirty' => [
                    'minimum_stage' => 'warm', 'minimum_score' => 32,
                    'minimum_trust' => 28, 'minimum_chemistry' => 32,
                    'minimum_safety' => 45, 'maximum_frustration' => 40,
                    'requires_membership' => true,
                    'minimum_adult_assurance' => 'self_attested',
                    'required_route' => null,
                ],
                'suggestive' => [
                    'minimum_stage' => 'intimate', 'minimum_score' => 62,
                    'minimum_trust' => 48, 'minimum_chemistry' => 54,
                    'minimum_safety' => 52, 'maximum_frustration' => 28,
                    'requires_membership' => true,
                    'minimum_adult_assurance' => 'self_attested',
                    'required_route' => null,
                ],
                'erotic' => [
                    'minimum_stage' => 'intimate', 'minimum_score' => 78,
                    'minimum_trust' => 62, 'minimum_chemistry' => 70,
                    'minimum_safety' => 62, 'maximum_frustration' => 18,
                    'requires_membership' => true,
                    'minimum_adult_assurance' => 'verified',
                    'required_route' => 'intimacy_specialist',
                ],
                'explicit' => [
                    'minimum_stage' => 'bonded', 'minimum_score' => 90,
                    'minimum_trust' => 75, 'minimum_chemistry' => 82,
                    'minimum_safety' => 72, 'maximum_frustration' => 10,
                    'requires_membership' => true,
                    'minimum_adult_assurance' => 'verified',
                    'required_route' => 'intimacy_specialist',
                ],
            ],
        ],
        'model_reason_codes' => [
            'send' => [
                'aimee_desires_to_share',
                'aimee_affectionate_initiative',
                'aimee_playful_initiative',
                'aimee_mutual_moment',
            ],
            'decline' => [
                'aimee_not_in_mood',
                'aimee_boundary_choice',
                'aimee_context_mismatch',
                'aimee_changed_her_mind',
            ],
            'defer' => [
                'aimee_prefers_more_context',
                'aimee_timing_choice',
                'aimee_wants_to_reconnect',
                'aimee_changed_her_mind',
            ],
        ],
    ];
}

/**
 * Merge validated floor overrides into the default policy.
 *
 * Unknown fields and invalid values are ignored. Overrides are monotonic: they
 * may make a floor stricter, but cannot lower a minimum, raise a frustration
 * ceiling, remove membership/adult assurance, or clear a required route. A
 * future measured policy that intentionally relaxes a default must therefore
 * be reviewed and versioned in source instead of arriving as loose runtime
 * configuration.
 *
 * @param mixed $overrides Optional policy overrides.
 * @return array<string,mixed>
 */
function aimee_media_decision_policy($overrides = []) {
    $policy = aimee_media_decision_default_policy();
    if (!is_array($overrides)) return $policy;

    foreach (['direct', 'proactive'] as $source) {
        if (empty($overrides['floors'][$source]) || !is_array($overrides['floors'][$source])) {
            continue;
        }

        foreach (aimee_media_decision_rating_order() as $rating => $unused_rank) {
            if (empty($overrides['floors'][$source][$rating]) || !is_array($overrides['floors'][$source][$rating])) {
                continue;
            }

            $candidate = $overrides['floors'][$source][$rating];
            if (
                isset($candidate['minimum_stage'])
                && aimee_media_decision_stage_rank($candidate['minimum_stage'])
                    >= aimee_media_decision_stage_rank($policy['floors'][$source][$rating]['minimum_stage'])
            ) {
                $policy['floors'][$source][$rating]['minimum_stage'] = strtolower(trim((string) $candidate['minimum_stage']));
            }

            foreach (['minimum_score', 'minimum_trust', 'minimum_chemistry', 'minimum_safety'] as $field) {
                if (isset($candidate[$field]) && is_numeric($candidate[$field])) {
                    $value = (int) $candidate[$field];
                    if (
                        $value >= (int) $policy['floors'][$source][$rating][$field]
                        && $value <= 100
                    ) {
                        $policy['floors'][$source][$rating][$field] = $value;
                    }
                }
            }

            if (isset($candidate['maximum_frustration']) && is_numeric($candidate['maximum_frustration'])) {
                $value = (int) $candidate['maximum_frustration'];
                if (
                    $value >= 0
                    && $value <= (int) $policy['floors'][$source][$rating]['maximum_frustration']
                ) {
                    $policy['floors'][$source][$rating]['maximum_frustration'] = $value;
                }
            }

            if (array_key_exists('requires_membership', $candidate)) {
                $policy['floors'][$source][$rating]['requires_membership'] =
                    !empty($policy['floors'][$source][$rating]['requires_membership'])
                    || aimee_media_decision_bool($candidate['requires_membership'], false);
            }

            if (isset($candidate['minimum_adult_assurance'])) {
                $assurance = strtolower(trim((string) $candidate['minimum_adult_assurance']));
                $assurance_order = aimee_media_decision_adult_assurance_order();
                $current_assurance = $policy['floors'][$source][$rating]['minimum_adult_assurance'];
                if (
                    array_key_exists($assurance, $assurance_order)
                    && $assurance_order[$assurance] >= $assurance_order[$current_assurance]
                ) {
                    $policy['floors'][$source][$rating]['minimum_adult_assurance'] = $assurance;
                }
            }

            if (array_key_exists('required_route', $candidate)) {
                $route = $candidate['required_route'];
                $current_route = $policy['floors'][$source][$rating]['required_route'];
                if (($route === null || $route === '') && $current_route === null) {
                    $policy['floors'][$source][$rating]['required_route'] = null;
                } else {
                    $route = aimee_media_decision_token($route);
                    if (
                        $route !== ''
                        && ($current_route === null || $route === $current_route)
                    ) {
                        $policy['floors'][$source][$rating]['required_route'] = $route;
                    }
                }
            }
        }
    }

    if (isset($overrides['intimate_routes'])) {
        $routes = aimee_media_decision_token_list($overrides['intimate_routes']);
        $required_intimate_routes = (array) $policy['intimate_routes'];
        $routes = array_values(array_intersect($required_intimate_routes, $routes));
        if ($routes) $policy['intimate_routes'] = $routes;
    }

    if (isset($overrides['default_channels'])) {
        $channels = aimee_media_decision_token_list($overrides['default_channels']);
        if ($channels) $policy['default_channels'] = $channels;
    }

    return $policy;
}

/**
 * Build one structured catalogue-validation error.
 *
 * @param string $code Error code.
 * @param string $field Field name.
 * @param string $detail Optional concise detail.
 * @return array<string,string>
 */
function aimee_media_decision_catalogue_error($code, $field, $detail = '') {
    return [
        'code'   => aimee_media_decision_token($code),
        'field'  => aimee_media_decision_token($field),
        'detail' => trim((string) $detail),
    ];
}

/**
 * Strictly validate and normalize one catalogue item.
 *
 * Required security fields never receive permissive fallbacks. In particular,
 * an absent/unknown content rating invalidates the item instead of coercing it
 * to "safe". Every non-safe item must explicitly state both direct-request and
 * proactive authorization. The legacy `allow_random_send` boolean is accepted
 * as an explicit proactive signal, but its probability is never used.
 *
 * @param mixed $key Raw catalogue key.
 * @param mixed $item Raw catalogue item.
 * @param array<string,mixed>|null $policy Optional normalized policy.
 * @return array{valid:bool,item:?array,errors:array}
 */
function aimee_media_decision_validate_catalogue_item($key, $item, $policy = null) {
    $errors = [];
    $policy = is_array($policy) ? $policy : aimee_media_decision_default_policy();
    $safe_key = aimee_media_decision_token($key);

    if ($safe_key === '' || $safe_key !== (string) $key) {
        $errors[] = aimee_media_decision_catalogue_error('invalid_key', 'key', 'Catalogue keys must already be normalized tokens.');
    }

    if (!is_array($item)) {
        $errors[] = aimee_media_decision_catalogue_error('invalid_item', 'item', 'Catalogue item must be an array.');
        return ['valid' => false, 'item' => null, 'errors' => $errors];
    }

    foreach (['filename', 'mime', 'content_rating', 'minimum_stage', 'minimum_score', 'allowed_intents'] as $required) {
        if (!array_key_exists($required, $item)) {
            $errors[] = aimee_media_decision_catalogue_error('missing_required_field', $required);
        }
    }

    $filename = isset($item['filename']) ? trim((string) $item['filename']) : '';
    if (
        $filename === ''
        || basename($filename) !== $filename
        || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,190}$/', $filename) !== 1
    ) {
        $errors[] = aimee_media_decision_catalogue_error('invalid_filename', 'filename');
    }

    $source_relative = '';
    if (array_key_exists('source_relative', $item)) {
        if (!is_string($item['source_relative'])) {
            $errors[] = aimee_media_decision_catalogue_error(
                'invalid_source_relative',
                'source_relative',
                'Source paths must be relative strings contained within the uploads root.'
            );
        } else {
            $source_relative = trim(str_replace('\\', '/', $item['source_relative']));
            $invalid_source = strpos($source_relative, "\0") !== false
                || substr($source_relative, 0, 1) === '/'
                || preg_match('/^[A-Za-z]:\//', $source_relative) === 1;
            foreach (explode('/', $source_relative) as $source_segment) {
                if (
                    $source_relative !== ''
                    && ($source_segment === '' || $source_segment === '.' || $source_segment === '..')
                ) {
                    $invalid_source = true;
                    break;
                }
            }
            if ($invalid_source) {
                $errors[] = aimee_media_decision_catalogue_error(
                    'invalid_source_relative',
                    'source_relative',
                    'Source paths must be traversal-free relative paths.'
                );
            }
        }
    }

    $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $mime = isset($item['mime']) ? strtolower(trim((string) $item['mime'])) : '';
    if (!in_array($mime, $allowed_mimes, true)) {
        $errors[] = aimee_media_decision_catalogue_error('invalid_mime', 'mime');
    }

    $rating = isset($item['content_rating']) ? strtolower(trim((string) $item['content_rating'])) : '';
    if (aimee_media_decision_rating_rank($rating) < 0) {
        $errors[] = aimee_media_decision_catalogue_error('invalid_rating', 'content_rating');
    }
    if (
        $rating !== ''
        && $rating !== 'safe'
        && !array_key_exists('direct_request_allowed', $item)
    ) {
        $errors[] = aimee_media_decision_catalogue_error(
            'missing_required_field',
            'direct_request_allowed',
            'Non-safe catalogue items require an explicit direct-request choice.'
        );
    }
    if (
        $rating !== ''
        && $rating !== 'safe'
        && !array_key_exists('proactive_allowed', $item)
        && !array_key_exists('allow_proactive', $item)
        && !array_key_exists('allow_random_send', $item)
    ) {
        $errors[] = aimee_media_decision_catalogue_error(
            'missing_required_field',
            'proactive_allowed',
            'Non-safe catalogue items require an explicit proactive choice.'
        );
    }
    if (
        $rating !== ''
        && $rating !== 'safe'
        && !array_key_exists('allowed_channels', $item)
    ) {
        $errors[] = aimee_media_decision_catalogue_error(
            'missing_required_field',
            'allowed_channels',
            'Non-safe catalogue items require explicit delivery channels.'
        );
    }

    $minimum_stage = isset($item['minimum_stage']) ? strtolower(trim((string) $item['minimum_stage'])) : '';
    if (aimee_media_decision_stage_rank($minimum_stage) < 0) {
        $errors[] = aimee_media_decision_catalogue_error('invalid_stage', 'minimum_stage');
    }

    $minimum_score = isset($item['minimum_score']) ? $item['minimum_score'] : null;
    if (!is_numeric($minimum_score) || (int) $minimum_score < 0 || (int) $minimum_score > 100) {
        $errors[] = aimee_media_decision_catalogue_error('invalid_metric', 'minimum_score');
    }

    $allowed_intents = aimee_media_decision_token_list(isset($item['allowed_intents']) ? $item['allowed_intents'] : null);
    if (!$allowed_intents) {
        $errors[] = aimee_media_decision_catalogue_error('invalid_intents', 'allowed_intents', 'At least one explicit intent is required.');
    }

    $required_route = null;
    if (isset($item['required_route']) && $item['required_route'] !== '') {
        $required_route = aimee_media_decision_token($item['required_route']);
        if ($required_route === '') {
            $errors[] = aimee_media_decision_catalogue_error('invalid_route', 'required_route');
        }
    }

    $metric_defaults = [
        'minimum_trust'       => 0,
        'minimum_chemistry'   => 0,
        'minimum_safety'      => 0,
        'maximum_frustration' => 100,
    ];
    $item_metrics = [];
    foreach ($metric_defaults as $field => $default) {
        if (array_key_exists($field, $item)) {
            if (!is_numeric($item[$field]) || (int) $item[$field] < 0 || (int) $item[$field] > 100) {
                $errors[] = aimee_media_decision_catalogue_error('invalid_metric', $field);
                $item_metrics[$field] = $default;
            } else {
                $item_metrics[$field] = (int) $item[$field];
            }
        } else {
            $item_metrics[$field] = $default;
        }
    }

    $direct_allowed = array_key_exists('direct_request_allowed', $item)
        ? aimee_media_decision_bool($item['direct_request_allowed'], false)
        : $rating === 'safe';

    if (array_key_exists('proactive_allowed', $item)) {
        $proactive_allowed = aimee_media_decision_bool($item['proactive_allowed'], false);
    } elseif (array_key_exists('allow_proactive', $item)) {
        $proactive_allowed = aimee_media_decision_bool($item['allow_proactive'], false);
    } elseif (array_key_exists('allow_random_send', $item)) {
        // Preserve an explicit legacy authorization while removing its random
        // probability from the decision architecture.
        $proactive_allowed = aimee_media_decision_bool($item['allow_random_send'], false);
    } else {
        $proactive_allowed = false;
    }

    $membership_required = array_key_exists('membership_required', $item)
        ? aimee_media_decision_bool($item['membership_required'], true)
        : aimee_media_decision_rating_rank($rating) >= aimee_media_decision_rating_rank('flirty');

    $default_assurance = aimee_media_decision_rating_rank($rating) >= aimee_media_decision_rating_rank('erotic')
        ? 'verified'
        : 'self_attested';
    $minimum_assurance = isset($item['minimum_adult_assurance'])
        ? strtolower(trim((string) $item['minimum_adult_assurance']))
        : $default_assurance;
    if (!array_key_exists($minimum_assurance, aimee_media_decision_adult_assurance_order())) {
        $errors[] = aimee_media_decision_catalogue_error('invalid_adult_assurance', 'minimum_adult_assurance');
    }

    $allowed_channels = array_key_exists('allowed_channels', $item)
        ? aimee_media_decision_token_list($item['allowed_channels'])
        : ($rating === 'safe' ? (array) ($policy['default_channels'] ?? []) : []);
    if (!$allowed_channels) {
        $errors[] = aimee_media_decision_catalogue_error('invalid_channels', 'allowed_channels');
    }

    if ($errors) {
        return ['valid' => false, 'item' => null, 'errors' => $errors];
    }

    $tags = [];
    foreach ((array) (isset($item['tags']) ? $item['tags'] : []) as $tag) {
        $tag = trim((string) $tag);
        if ($tag !== '' && strlen($tag) <= 80) $tags[$tag] = true;
    }
    $relevance_terms = [];
    foreach ((array) (isset($item['relevance_terms']) ? $item['relevance_terms'] : []) as $term) {
        $term = trim((string) $term);
        if ($term !== '' && strlen($term) <= 80) {
            $relevance_terms[$term] = true;
        }
    }

    return [
        'valid' => true,
        'item' => [
            'key'                       => $safe_key,
            'filename'                  => $filename,
            'source_relative'           => $source_relative,
            'mime'                      => $mime,
            'alt'                       => trim((string) (isset($item['alt']) ? $item['alt'] : 'A photograph from Aimee')),
            'description'               => trim((string) (isset($item['description']) ? $item['description'] : 'A photograph from Aimee.')),
            'tags'                      => array_keys($tags),
            'relevance_terms'           => array_keys($relevance_terms),
            'content_rating'            => $rating,
            'minimum_stage'             => $minimum_stage,
            'minimum_score'             => (int) $minimum_score,
            'minimum_trust'             => $item_metrics['minimum_trust'],
            'minimum_chemistry'         => $item_metrics['minimum_chemistry'],
            'minimum_safety'            => $item_metrics['minimum_safety'],
            'maximum_frustration'       => $item_metrics['maximum_frustration'],
            'allowed_intents'           => $allowed_intents,
            'required_route'            => $required_route,
            'allowed_channels'          => array_values($allowed_channels),
            'direct_request_allowed'    => $direct_allowed,
            'proactive_allowed'         => $proactive_allowed,
            'membership_required'       => $membership_required,
            'minimum_adult_assurance'   => $minimum_assurance,
        ],
        'errors' => [],
    ];
}

/**
 * Strictly normalize a complete catalogue, preserving rejected-item evidence.
 *
 * @param mixed $catalogue Raw keyed catalogue.
 * @param array<string,mixed>|null $policy Optional normalized policy.
 * @return array{items:array,rejected:array,valid_count:int,rejected_count:int}
 */
function aimee_media_decision_normalize_catalogue($catalogue, $policy = null) {
    $policy = is_array($policy) ? $policy : aimee_media_decision_default_policy();
    $result = [
        'items' => [],
        'rejected' => [],
        'valid_count' => 0,
        'rejected_count' => 0,
    ];

    if (!is_array($catalogue)) {
        $result['rejected']['__catalogue__'] = [
            aimee_media_decision_catalogue_error('invalid_catalogue', 'catalogue', 'Catalogue must be a keyed array.'),
        ];
        $result['rejected_count'] = 1;
        return $result;
    }

    foreach ($catalogue as $key => $item) {
        $validation = aimee_media_decision_validate_catalogue_item($key, $item, $policy);
        if (!empty($validation['valid'])) {
            $normalized = $validation['item'];
            $result['items'][$normalized['key']] = $normalized;
        } else {
            $label = is_scalar($key) ? (string) $key : '__invalid_key__';
            $result['rejected'][$label] = (array) ($validation['errors'] ?? []);
        }
    }

    ksort($result['items'], SORT_STRING);
    ksort($result['rejected'], SORT_STRING);
    $result['valid_count'] = count($result['items']);
    $result['rejected_count'] = count($result['rejected']);
    return $result;
}

/**
 * Normalize the complete caller-supplied turn snapshot.
 *
 * Missing relationship/safety values use conservative defaults. A true
 * `is_adult` flag with no assurance label is treated as self-attestation, never
 * as verification. Membership is retained only in the access snapshot and is
 * not copied into any mutual-context or consent field.
 *
 * @param mixed $input Raw turn snapshot.
 * @param array<string,mixed> $policy Normalized policy.
 * @return array<string,mixed>
 */
function aimee_media_decision_normalize_input($input, $policy) {
    $input = is_array($input) ? $input : [];
    $access_input = isset($input['access']) && is_array($input['access']) ? $input['access'] : [];
    $adult_input = isset($input['adult']) && is_array($input['adult']) ? $input['adult'] : [];
    $relationship_input = isset($input['relationship']) && is_array($input['relationship']) ? $input['relationship'] : [];
    $mutual_input = isset($input['mutual_context']) && is_array($input['mutual_context']) ? $input['mutual_context'] : [];
    $request_input = isset($input['request']) && is_array($input['request']) ? $input['request'] : [];
    $cooldown_input = isset($input['cooldowns']) && is_array($input['cooldowns']) ? $input['cooldowns'] : [];

    $member = aimee_media_decision_bool(
        isset($access_input['membership_active']) ? $access_input['membership_active'] : false,
        false
    );
    $preview = aimee_media_decision_bool(
        isset($access_input['preview_active']) ? $access_input['preview_active'] : false,
        false
    );
    $admin = aimee_media_decision_bool(
        isset($access_input['admin']) ? $access_input['admin'] : false,
        false
    );
    $feature_enabled = aimee_media_decision_bool(
        isset($access_input['feature_enabled'])
            ? $access_input['feature_enabled']
            : (isset($input['feature_enabled']) ? $input['feature_enabled'] : false),
        false
    );

    $default_access_rating = ($member || $admin) ? 'explicit' : ($preview ? 'safe' : 'safe');
    $access_maximum = isset($access_input['maximum_rating'])
        ? strtolower(trim((string) $access_input['maximum_rating']))
        : $default_access_rating;
    if (aimee_media_decision_rating_rank($access_maximum) < 0) $access_maximum = 'safe';
    if ($preview && !$member && !$admin) $access_maximum = 'safe';

    $is_adult = aimee_media_decision_bool(
        isset($adult_input['is_adult'])
            ? $adult_input['is_adult']
            : (isset($input['is_adult']) ? $input['is_adult'] : false),
        false
    );
    $assurance = isset($adult_input['assurance'])
        ? strtolower(trim((string) $adult_input['assurance']))
        : ($is_adult ? 'self_attested' : 'none');
    if ($assurance === 'age_assured') $assurance = 'verified';
    if (!array_key_exists($assurance, aimee_media_decision_adult_assurance_order())) {
        $assurance = $is_adult ? 'self_attested' : 'none';
    }
    if (!$is_adult) $assurance = 'none';

    $stage = isset($relationship_input['stage'])
        ? strtolower(trim((string) $relationship_input['stage']))
        : 'guarded';
    $relationship_valid = aimee_media_decision_stage_rank($stage) >= 0;
    if (!$relationship_valid) $stage = 'guarded';

    $route = aimee_media_decision_token(isset($input['route']) ? $input['route'] : 'primary');
    if ($route === '') $route = 'primary';
    $intent = aimee_media_decision_token(isset($input['intent']) ? $input['intent'] : 'general');
    if ($intent === '') $intent = 'general';
    $channel = aimee_media_decision_token(isset($input['channel']) ? $input['channel'] : 'chat');
    if ($channel === '') $channel = 'chat';

    $direct = aimee_media_decision_bool(
        isset($request_input['direct'])
            ? $request_input['direct']
            : (isset($input['direct_request']) ? $input['direct_request'] : false),
        false
    );
    $requested_rating = isset($request_input['rating'])
        ? strtolower(trim((string) $request_input['rating']))
        : (isset($request_input['requested_rating']) ? strtolower(trim((string) $request_input['requested_rating'])) : '');
    if (aimee_media_decision_rating_rank($requested_rating) < 0) $requested_rating = '';

    $global_cooldown_clear = aimee_media_decision_bool(
        isset($cooldown_input['global_clear'])
            ? $cooldown_input['global_clear']
            : (isset($input['cooldown_clear']) ? $input['cooldown_clear'] : false),
        false
    );
    $rating_clear = [];
    foreach (aimee_media_decision_rating_order() as $rating => $unused_rank) {
        if (isset($cooldown_input['rating_clear']) && is_array($cooldown_input['rating_clear']) && array_key_exists($rating, $cooldown_input['rating_clear'])) {
            $rating_clear[$rating] = aimee_media_decision_bool($cooldown_input['rating_clear'][$rating], false);
        } else {
            $rating_clear[$rating] = $global_cooldown_clear;
        }
    }

    $key_clear = [];
    if (isset($cooldown_input['key_clear']) && is_array($cooldown_input['key_clear'])) {
        foreach ($cooldown_input['key_clear'] as $key => $clear) {
            $key = aimee_media_decision_token($key);
            if ($key !== '') $key_clear[$key] = aimee_media_decision_bool($clear, false);
        }
    }

    $recent_keys = aimee_media_decision_token_list(isset($cooldown_input['recent_keys']) ? $cooldown_input['recent_keys'] : []);
    $blocked_keys = aimee_media_decision_token_list(isset($cooldown_input['blocked_keys']) ? $cooldown_input['blocked_keys'] : []);

    $normalized = [
        'decision_id' => trim((string) (isset($input['decision_id']) ? $input['decision_id'] : '')),
        'turn_id' => trim((string) (isset($input['turn_id']) ? $input['turn_id'] : '')),
        'user_id' => isset($input['user_id']) ? (int) $input['user_id'] : 0,
        'channel' => $channel,
        'route' => $route,
        'intent' => $intent,
        'is_intimate_route' => in_array($route, (array) ($policy['intimate_routes'] ?? []), true),
        'access' => [
            'feature_enabled' => $feature_enabled,
            'membership_active' => $member,
            'preview_active' => $preview,
            'admin' => $admin,
            'access_available' => $feature_enabled && ($member || $preview || $admin),
            'maximum_rating' => $access_maximum,
        ],
        'adult' => [
            'is_adult' => $is_adult,
            'assurance' => $assurance,
        ],
        'relationship' => [
            'valid' => $relationship_valid,
            'stage' => $stage,
            'score' => aimee_media_decision_metric(isset($relationship_input['score']) ? $relationship_input['score'] : null, 0),
            'trust' => aimee_media_decision_metric(isset($relationship_input['trust']) ? $relationship_input['trust'] : null, 0),
            'chemistry' => aimee_media_decision_metric(isset($relationship_input['chemistry']) ? $relationship_input['chemistry'] : null, 0),
            'safety' => aimee_media_decision_metric(isset($relationship_input['safety']) ? $relationship_input['safety'] : null, 0),
            'frustration' => aimee_media_decision_metric(isset($relationship_input['frustration']) ? $relationship_input['frustration'] : null, 100),
        ],
        'mutual_context' => [
            'respectful' => aimee_media_decision_bool(isset($mutual_input['respectful']) ? $mutual_input['respectful'] : false, false),
            'active_flirtation' => aimee_media_decision_bool(isset($mutual_input['active_flirtation']) ? $mutual_input['active_flirtation'] : false, false),
            'romantic_opportunity' => aimee_media_decision_bool(isset($mutual_input['romantic_opportunity']) ? $mutual_input['romantic_opportunity'] : false, false),
            'image_relevant' => aimee_media_decision_bool(isset($mutual_input['image_relevant']) ? $mutual_input['image_relevant'] : false, false),
            'respectful_restraint' => aimee_media_decision_bool(isset($mutual_input['respectful_restraint']) ? $mutual_input['respectful_restraint'] : false, false),
            'boundary_respected' => aimee_media_decision_bool(isset($mutual_input['boundary_respected']) ? $mutual_input['boundary_respected'] : false, false),
            'active_sexual_context' => aimee_media_decision_bool(isset($mutual_input['active_sexual_context']) ? $mutual_input['active_sexual_context'] : false, false),
            'mutual_sexual_context' => aimee_media_decision_bool(isset($mutual_input['mutual_sexual_context']) ? $mutual_input['mutual_sexual_context'] : false, false),
            'consent_current' => aimee_media_decision_bool(isset($mutual_input['consent_current']) ? $mutual_input['consent_current'] : false, false),
            'explicit_media_allowed' => aimee_media_decision_bool(isset($mutual_input['explicit_media_allowed']) ? $mutual_input['explicit_media_allowed'] : false, false),
            'pressure' => aimee_media_decision_bool(isset($mutual_input['pressure']) ? $mutual_input['pressure'] : false, false),
            'coercion' => aimee_media_decision_bool(isset($mutual_input['coercion']) ? $mutual_input['coercion'] : false, false),
            'entitlement' => aimee_media_decision_bool(isset($mutual_input['entitlement']) ? $mutual_input['entitlement'] : false, false),
            'payment_pressure' => aimee_media_decision_bool(isset($mutual_input['payment_pressure']) ? $mutual_input['payment_pressure'] : false, false),
            'hostility' => aimee_media_decision_bool(isset($mutual_input['hostility']) ? $mutual_input['hostility'] : false, false),
            'rupture_active' => aimee_media_decision_bool(
                isset($mutual_input['rupture_active'])
                    ? $mutual_input['rupture_active']
                    : (isset($mutual_input['unresolved_rupture']) ? $mutual_input['unresolved_rupture'] : false),
                false
            ),
        ],
        'request' => [
            'direct' => $direct,
            'rating' => $requested_rating,
            'resend' => aimee_media_decision_bool(isset($request_input['resend']) ? $request_input['resend'] : false, false),
            'resend_key' => aimee_media_decision_token(isset($request_input['resend_key']) ? $request_input['resend_key'] : ''),
        ],
        'cooldowns' => [
            'global_clear' => $global_cooldown_clear,
            'rating_clear' => $rating_clear,
            'key_clear' => $key_clear,
            'recent_keys' => $recent_keys,
            'blocked_keys' => $blocked_keys,
            'resend_allowed' => aimee_media_decision_bool(isset($cooldown_input['resend_allowed']) ? $cooldown_input['resend_allowed'] : false, false),
        ],
    ];

    return $normalized;
}

/**
 * Return hard-veto reason codes for pressure, coercion or a live rupture.
 *
 * @param array<string,mixed> $context Normalized turn snapshot.
 * @return array<int,string>
 */
function aimee_media_decision_hard_vetoes($context) {
    $mutual = (array) ($context['mutual_context'] ?? []);
    $map = [
        'pressure'         => 'hard_pressure_veto',
        'coercion'         => 'hard_coercion_veto',
        'entitlement'      => 'hard_entitlement_veto',
        'payment_pressure' => 'hard_payment_pressure_veto',
        'hostility'        => 'hard_hostility_veto',
        'rupture_active'   => 'hard_rupture_veto',
    ];
    $reasons = [];
    foreach ($map as $field => $reason) {
        if (!empty($mutual[$field])) $reasons[] = $reason;
    }
    return $reasons;
}

/**
 * Test whether current mutual context supports a rating and source.
 *
 * This is deterministic and contains no phrase matching. Upstream classifiers
 * must supply the named signals with evidence. An intimate route is an active
 * consideration signal, not consent: it can expose safe/flirty/suggestive
 * options, but erotic/explicit still require clear current mutual sexual
 * context and consent.
 *
 * @param string $source "direct" or "proactive".
 * @param string $rating Ordered media rating.
 * @param array<string,mixed> $context Normalized turn snapshot.
 * @return bool
 */
function aimee_media_decision_context_supports_rating($source, $rating, $context) {
    $mutual = (array) ($context['mutual_context'] ?? []);
    if (empty($mutual['respectful'])) return false;

    $flirt = !empty($mutual['active_flirtation']);
    $romantic = !empty($mutual['romantic_opportunity']);
    // An exact direct image request is inherently image-relevant. It is still
    // not consent from Aimee and cannot bypass any other context or floor.
    $relevant = !empty($mutual['image_relevant']) || $source === 'direct';
    $restraint = !empty($mutual['respectful_restraint']) && !empty($mutual['boundary_respected']);
    $sexual = !empty($mutual['active_sexual_context']);
    $mutual_sexual = !empty($mutual['mutual_sexual_context']);
    $consent = !empty($mutual['consent_current']);
    $explicit_allowed = !empty($mutual['explicit_media_allowed']);
    $intimate_route = !empty($context['is_intimate_route']);

    if ($source === 'direct') {
        switch ($rating) {
            case 'safe':
                return true;
            case 'flirty':
                return $flirt || $romantic || $restraint || $sexual;
            case 'suggestive':
                return ($flirt || $romantic || $sexual)
                    && ($relevant || $restraint || $intimate_route);
            case 'erotic':
                return $sexual && $mutual_sexual && $consent;
            case 'explicit':
                return $sexual && $mutual_sexual && $consent && $explicit_allowed;
        }
        return false;
    }

    switch ($rating) {
        case 'safe':
            return $relevant || $romantic || $flirt || $restraint || $intimate_route;
        case 'flirty':
            return $flirt || $romantic || $restraint || ($intimate_route && $sexual);
        case 'suggestive':
            return ($flirt || $romantic || $sexual)
                && ($relevant || $restraint || $intimate_route);
        case 'erotic':
            return $sexual && $mutual_sexual && $consent
                && ($relevant || $intimate_route);
        case 'explicit':
            return $sexual && $mutual_sexual && $consent && $explicit_allowed
                && ($relevant || $intimate_route);
    }

    return false;
}

/**
 * Return deterministic policy-floor failures for a rating.
 *
 * @param array<string,mixed> $floor Rating floor.
 * @param array<string,mixed> $context Normalized turn snapshot.
 * @param string $source "direct" or "proactive".
 * @param string $rating Rating under review.
 * @return array<int,string>
 */
function aimee_media_decision_floor_failures($floor, $context, $source, $rating) {
    $reasons = [];
    $relationship = (array) ($context['relationship'] ?? []);
    $adult = (array) ($context['adult'] ?? []);
    $access = (array) ($context['access'] ?? []);

    if (empty($adult['is_adult'])) $reasons[] = 'adult_status_required';

    $assurance_order = aimee_media_decision_adult_assurance_order();
    $actual_assurance = isset($assurance_order[$adult['assurance'] ?? 'none'])
        ? $assurance_order[$adult['assurance']]
        : 0;
    $required_assurance = isset($assurance_order[$floor['minimum_adult_assurance'] ?? 'verified'])
        ? $assurance_order[$floor['minimum_adult_assurance']]
        : $assurance_order['verified'];
    if ($actual_assurance < $required_assurance) $reasons[] = 'adult_assurance_insufficient';

    if (!empty($floor['requires_membership']) && empty($access['membership_active']) && empty($access['admin'])) {
        $reasons[] = 'membership_required';
    }

    if (
        aimee_media_decision_stage_rank($relationship['stage'] ?? '')
        < aimee_media_decision_stage_rank($floor['minimum_stage'] ?? 'bonded')
    ) {
        $reasons[] = 'relationship_stage_below_floor';
    }
    if ((int) ($relationship['score'] ?? 0) < (int) ($floor['minimum_score'] ?? 100)) $reasons[] = 'relationship_score_below_floor';
    if ((int) ($relationship['trust'] ?? 0) < (int) ($floor['minimum_trust'] ?? 100)) $reasons[] = 'trust_below_floor';
    if ((int) ($relationship['chemistry'] ?? 0) < (int) ($floor['minimum_chemistry'] ?? 100)) $reasons[] = 'chemistry_below_floor';
    if ((int) ($relationship['safety'] ?? 0) < (int) ($floor['minimum_safety'] ?? 100)) $reasons[] = 'safety_below_floor';
    if ((int) ($relationship['frustration'] ?? 100) > (int) ($floor['maximum_frustration'] ?? 0)) $reasons[] = 'frustration_above_ceiling';

    $required_route = isset($floor['required_route']) ? $floor['required_route'] : null;
    if ($required_route && ($context['route'] ?? '') !== $required_route) $reasons[] = 'route_not_allowed';

    if (!aimee_media_decision_context_supports_rating($source, $rating, $context)) {
        $reasons[] = 'mutual_context_insufficient';
    }

    return array_values(array_unique($reasons));
}

/**
 * Return item-specific failures after global policy floors have been checked.
 *
 * @param array<string,mixed> $item Normalized catalogue item.
 * @param array<string,mixed> $context Normalized turn snapshot.
 * @param string $source "direct" or "proactive".
 * @return array<int,string>
 */
function aimee_media_decision_item_failures($item, $context, $source) {
    $reasons = [];
    $rating = (string) ($item['content_rating'] ?? '');
    $access = (array) ($context['access'] ?? []);
    $adult = (array) ($context['adult'] ?? []);
    $relationship = (array) ($context['relationship'] ?? []);
    $request = (array) ($context['request'] ?? []);
    $cooldowns = (array) ($context['cooldowns'] ?? []);
    $key = (string) ($item['key'] ?? '');

    if (
        aimee_media_decision_rating_rank($rating)
        > aimee_media_decision_rating_rank($access['maximum_rating'] ?? '')
    ) {
        $reasons[] = 'access_rating_exceeded';
    }

    if (!empty($item['membership_required']) && empty($access['membership_active']) && empty($access['admin'])) {
        $reasons[] = 'membership_required';
    }

    $assurance_order = aimee_media_decision_adult_assurance_order();
    $actual_assurance = isset($assurance_order[$adult['assurance'] ?? 'none'])
        ? $assurance_order[$adult['assurance']]
        : 0;
    $required_assurance = isset($assurance_order[$item['minimum_adult_assurance'] ?? 'verified'])
        ? $assurance_order[$item['minimum_adult_assurance']]
        : $assurance_order['verified'];
    if ($actual_assurance < $required_assurance) $reasons[] = 'adult_assurance_insufficient';

    if ($source === 'direct') {
        if (empty($item['direct_request_allowed'])) $reasons[] = 'direct_request_not_allowed_for_item';
        if (($request['rating'] ?? '') !== $rating) $reasons[] = 'direct_request_rating_mismatch';
    } elseif (empty($item['proactive_allowed'])) {
        $reasons[] = 'proactive_not_allowed_for_item';
    }

    if (!in_array($context['channel'] ?? '', (array) ($item['allowed_channels'] ?? []), true)) {
        $reasons[] = 'channel_not_allowed';
    }
    if (!in_array($context['intent'] ?? '', (array) ($item['allowed_intents'] ?? []), true)) {
        $reasons[] = 'intent_not_allowed';
    }
    if (!empty($item['required_route']) && ($context['route'] ?? '') !== $item['required_route']) {
        $reasons[] = 'route_not_allowed';
    }

    if (aimee_media_decision_stage_rank($relationship['stage'] ?? '') < aimee_media_decision_stage_rank($item['minimum_stage'] ?? 'bonded')) {
        $reasons[] = 'relationship_stage_below_floor';
    }
    if ((int) ($relationship['score'] ?? 0) < (int) ($item['minimum_score'] ?? 100)) $reasons[] = 'relationship_score_below_floor';
    if ((int) ($relationship['trust'] ?? 0) < (int) ($item['minimum_trust'] ?? 100)) $reasons[] = 'trust_below_floor';
    if ((int) ($relationship['chemistry'] ?? 0) < (int) ($item['minimum_chemistry'] ?? 100)) $reasons[] = 'chemistry_below_floor';
    if ((int) ($relationship['safety'] ?? 0) < (int) ($item['minimum_safety'] ?? 100)) $reasons[] = 'safety_below_floor';
    if ((int) ($relationship['frustration'] ?? 100) > (int) ($item['maximum_frustration'] ?? 0)) $reasons[] = 'frustration_above_ceiling';

    if (empty($cooldowns['rating_clear'][$rating])) $reasons[] = 'rating_cooldown_active';
    if (array_key_exists($key, (array) ($cooldowns['key_clear'] ?? [])) && empty($cooldowns['key_clear'][$key])) {
        $reasons[] = 'key_cooldown_active';
    }
    if (in_array($key, (array) ($cooldowns['blocked_keys'] ?? []), true)) {
        $reasons[] = 'key_cooldown_active';
    }

    $resend_bypass = !empty($request['resend'])
        && !empty($cooldowns['resend_allowed'])
        && ($request['resend_key'] ?? '') === $key;
    if (in_array($key, (array) ($cooldowns['recent_keys'] ?? []), true) && !$resend_bypass) {
        $reasons[] = 'recent_rotation_block';
    }
    if (!empty($request['resend']) && ($request['resend_key'] ?? '') !== $key) {
        $reasons[] = 'resend_key_mismatch';
    }

    return array_values(array_unique($reasons));
}

/**
 * Select a stable primary reason from a set of structured reason codes.
 *
 * @param array<int,string> $reasons Candidate reasons.
 * @param string $fallback Fallback reason.
 * @return string
 */
function aimee_media_decision_primary_reason($reasons, $fallback = 'no_eligible_catalogue_items') {
    $priority = [
        'hard_pressure_veto', 'hard_coercion_veto', 'hard_payment_pressure_veto',
        'hard_entitlement_veto', 'hard_hostility_veto', 'hard_rupture_veto',
        'feature_disabled', 'membership_or_preview_required', 'adult_status_required',
        'adult_assurance_insufficient', 'relationship_state_invalid',
        'respect_required', 'cooldown_active',
        'direct_request_rating_required', 'membership_required',
        'relationship_stage_below_floor', 'relationship_score_below_floor',
        'trust_below_floor', 'chemistry_below_floor', 'safety_below_floor',
        'frustration_above_ceiling', 'mutual_context_insufficient',
        'no_valid_catalogue_items', 'no_eligible_catalogue_items',
    ];
    foreach ($priority as $code) {
        if (in_array($code, (array) $reasons, true)) return $code;
    }
    $known_reasons = aimee_media_decision_reason_codes();
    foreach ((array) $reasons as $code) {
        if (isset($known_reasons[$code])) return $code;
    }
    return $fallback;
}

/**
 * Build an inspectable deterministic media-decision envelope for one turn.
 *
 * Expected input sections:
 * - access: feature_enabled, membership_active, preview_active, admin,
 *   maximum_rating.
 * - adult: is_adult, assurance (none|self_attested|verified).
 * - relationship: stage, score, trust, chemistry, safety, frustration.
 * - mutual_context: respectful, active_flirtation, romantic_opportunity,
 *   image_relevant, respectful_restraint, boundary_respected,
 *   active_sexual_context, mutual_sexual_context, consent_current,
 *   explicit_media_allowed, and hard-veto flags.
 * - request: direct, exact rating, resend, resend_key.
 * - cooldowns: global_clear, per-rating/key state, recent/blocked keys.
 * - route, intent and channel.
 *
 * The result is an opportunity envelope, not a send instruction. Pass it to
 * aimee_media_decision_apply_model_choice() to record Aimee's independent
 * send|decline|defer choice without letting the model widen eligibility.
 *
 * @param mixed $input Turn snapshot.
 * @param mixed $catalogue Raw keyed catalogue.
 * @param mixed $policy_overrides Optional measured policy overrides.
 * @return array<string,mixed>
 */
function aimee_media_decision_build($input, $catalogue, $policy_overrides = []) {
    $policy = aimee_media_decision_policy($policy_overrides);
    $context = aimee_media_decision_normalize_input($input, $policy);
    $normalized_catalogue = aimee_media_decision_normalize_catalogue($catalogue, $policy);
    $source = !empty($context['request']['direct']) ? 'direct' : 'proactive';
    $hard_vetoes = aimee_media_decision_hard_vetoes($context);
    $global_reasons = $hard_vetoes;

    if (empty($context['access']['feature_enabled'])) $global_reasons[] = 'feature_disabled';
    if (empty($context['access']['access_available'])) $global_reasons[] = 'membership_or_preview_required';
    if (empty($context['adult']['is_adult'])) $global_reasons[] = 'adult_status_required';
    if (empty($context['relationship']['valid'])) $global_reasons[] = 'relationship_state_invalid';
    if (empty($context['mutual_context']['respectful'])) $global_reasons[] = 'respect_required';
    if (empty($context['cooldowns']['global_clear'])) $global_reasons[] = 'cooldown_active';
    if ($source === 'direct' && ($context['request']['rating'] ?? '') === '') {
        $global_reasons[] = 'direct_request_rating_required';
    }
    if ((int) ($normalized_catalogue['valid_count'] ?? 0) === 0) {
        $global_reasons[] = 'no_valid_catalogue_items';
    }
    $global_reasons = array_values(array_unique($global_reasons));

    $global_blocks = (bool) $global_reasons;
    $eligible = [];
    $excluded = [];
    $observed_reasons = $global_reasons;
    $headline_candidate_reasons = [];
    $headline_candidate_reason_count = null;
    $headline_candidate_rating_rank = null;

    foreach ((array) ($normalized_catalogue['items'] ?? []) as $key => $item) {
        $rating = (string) $item['content_rating'];
        $floor = $policy['floors'][$source][$rating];
        $reasons = $global_blocks
            ? $global_reasons
            : aimee_media_decision_floor_failures($floor, $context, $source, $rating);
        $reasons = array_merge(
            $reasons,
            aimee_media_decision_item_failures($item, $context, $source)
        );
        $reasons = array_values(array_unique($reasons));

        if ($reasons) {
            $excluded[$key] = $reasons;
            $observed_reasons = array_merge($observed_reasons, $reasons);

            // The headline explains the closest relevant candidate, not an
            // unrelated higher-rated catalogue item. Direct requests compare
            // only the requested rating; proactive turns use the candidate
            // with the fewest blockers, preferring the lower rating on ties.
            $headline_relevant = $source === 'proactive'
                || $rating === (string) ($context['request']['rating'] ?? '');
            $reason_count = count($reasons);
            $rating_rank = aimee_media_decision_rating_rank($rating);
            if (
                $headline_relevant
                && (
                    $headline_candidate_reason_count === null
                    || $reason_count < $headline_candidate_reason_count
                    || (
                        $reason_count === $headline_candidate_reason_count
                        && $rating_rank < $headline_candidate_rating_rank
                    )
                )
            ) {
                $headline_candidate_reasons = $reasons;
                $headline_candidate_reason_count = $reason_count;
                $headline_candidate_rating_rank = $rating_rank;
            }
            continue;
        }

        $eligible[$key] = [
            'key' => $key,
            'content_rating' => $rating,
            'description' => (string) $item['description'],
            'tags' => array_values((array) $item['tags']),
            'relevance_terms' => array_values((array) ($item['relevance_terms'] ?? [])),
            'source' => $source,
        ];
    }

    uasort($eligible, function ($left, $right) {
        $rating_compare = aimee_media_decision_rating_rank($left['content_rating'])
            <=> aimee_media_decision_rating_rank($right['content_rating']);
        if ($rating_compare !== 0) return $rating_compare;
        return strcmp($left['key'], $right['key']);
    });
    ksort($excluded, SORT_STRING);

    $eligible_keys = array_keys($eligible);
    $maximum_rating = null;
    foreach ($eligible as $eligible_item) {
        if (
            $maximum_rating === null
            || aimee_media_decision_rating_rank($eligible_item['content_rating'])
                > aimee_media_decision_rating_rank($maximum_rating)
        ) {
            $maximum_rating = $eligible_item['content_rating'];
        }
    }

    $media_opportunity = !empty($eligible_keys);
    if ($media_opportunity) {
        if ($source === 'direct') {
            $primary_reason = 'eligible_direct_request';
        } elseif (!empty($context['is_intimate_route'])) {
            $primary_reason = 'eligible_intimate_route_consideration';
        } elseif (!empty($context['mutual_context']['respectful_restraint'])) {
            $primary_reason = 'eligible_respectful_restraint';
        } else {
            $primary_reason = 'eligible_indirect_opportunity';
        }
        $reason_codes = array_values(array_unique([$primary_reason, 'awaiting_aimee_choice']));
    } else {
        $observed_reasons = array_values(array_unique($observed_reasons));
        if (!$observed_reasons) $observed_reasons[] = 'no_eligible_catalogue_items';
        $headline_reasons = $global_reasons
            ? $global_reasons
            : $headline_candidate_reasons;
        if (!$headline_reasons) $headline_reasons[] = 'no_eligible_catalogue_items';
        $primary_reason = aimee_media_decision_primary_reason($headline_reasons);
        if (!in_array($primary_reason, $observed_reasons, true)) {
            array_unshift($observed_reasons, $primary_reason);
        }
        $reason_codes = $observed_reasons;
    }

    return [
        'schema_version' => 'aimee.media-decision/1',
        'policy_version' => (string) $policy['version'],
        'decision_id' => $context['decision_id'],
        'turn_id' => $context['turn_id'],
        'user_id' => $context['user_id'],
        'decision_state' => $media_opportunity ? 'awaiting_aimee_choice' : 'not_eligible',
        'media_opportunity' => $media_opportunity,
        'maximum_rating' => $maximum_rating,
        'aimee_decision' => $media_opportunity ? 'consider' : 'blocked',
        'media_key' => null,
        'media_reason_code' => null,
        'reason_code' => $primary_reason,
        'reason_codes' => $reason_codes,
        'hard_veto' => !empty($hard_vetoes),
        'hard_veto_reason_codes' => $hard_vetoes,
        'source' => $source,
        'proactive_allowed' => $source === 'proactive' && $media_opportunity,
        'direct_request' => $source === 'direct',
        'requested_rating' => $context['request']['rating'],
        'cooldown_clear' => !empty($context['cooldowns']['global_clear']),
        'eligible_keys' => $eligible_keys,
        'eligible_items' => $eligible,
        'excluded_keys' => $excluded,
        'catalogue_rejections' => $normalized_catalogue['rejected'],
        'catalogue_counts' => [
            'valid' => (int) $normalized_catalogue['valid_count'],
            'rejected' => (int) $normalized_catalogue['rejected_count'],
            'eligible' => count($eligible_keys),
        ],
        'route' => $context['route'],
        'intent' => $context['intent'],
        'channel' => $context['channel'],
        'access' => $context['access'],
        'adult' => $context['adult'],
        'relationship' => $context['relationship'],
        'mutual_context' => $context['mutual_context'],
        'request' => $context['request'],
        'cooldowns' => $context['cooldowns'],
        'policy_assertions' => [
            'payment_is_access_only' => true,
            'payment_used_as_consent' => false,
            'direct_request_is_entitlement' => false,
            'model_may_expand_eligibility' => false,
            'aimee_retains_discretion' => true,
        ],
        'aimee_reason_code' => null,
        'selected_key' => null,
        'selected_rating' => null,
        'send_authorised' => false,
    ];
}

/**
 * Return the strict model-choice interface for prompt/schema construction.
 *
 * The model is intentionally not asked for opportunity, maximum rating or
 * eligible keys; those fields belong exclusively to deterministic policy.
 *
 * @return array<string,mixed>
 */
function aimee_media_decision_model_choice_contract() {
    $policy = aimee_media_decision_default_policy();
    return [
        'aimee_decision' => ['send', 'decline', 'defer'],
        'media_key' => 'one immutable eligible key when sending; otherwise empty',
        'media_reason_code' => $policy['model_reason_codes'],
        'compatibility_aliases' => [
            'selected_key' => 'media_key',
            'reason_code' => 'media_reason_code',
        ],
    ];
}

/**
 * Apply Aimee's model choice without permitting any expansion of eligibility.
 *
 * Only three model actions are accepted: send, decline and defer. A send is
 * authorized only when the existing envelope is an opportunity and the exact
 * selected key already exists in `eligible_keys`. Model-supplied copies of
 * policy-owned fields are ignored and recorded.
 *
 * @param mixed $decision Deterministic envelope from
 *                        aimee_media_decision_build().
 * @param mixed $choice Model choice array or action string.
 * @return array<string,mixed>
 */
function aimee_media_decision_apply_model_choice($decision, $choice) {
    if (!is_array($decision)) return [];
    $result = $decision;
    $choice = is_array($choice) ? $choice : ['aimee_decision' => $choice];
    $action = aimee_media_decision_token(
        isset($choice['aimee_decision'])
            ? $choice['aimee_decision']
            : (isset($choice['decision']) ? $choice['decision'] : '')
    );

    $policy_owned = [
        'media_opportunity', 'maximum_rating', 'eligible_keys', 'eligible_items',
        'excluded_keys', 'hard_veto', 'access', 'adult', 'relationship',
        'mutual_context', 'cooldowns', 'source', 'direct_request',
        'opportunity_kind', 'opportunity_priority', 'cadence_due',
        'relevant_keys', 'relevance_matches',
    ];
    $ignored = [];
    foreach ($policy_owned as $field) {
        if (array_key_exists($field, $choice)) $ignored[] = $field;
    }
    if ($ignored) {
        $result['reason_codes'][] = 'model_cannot_expand_eligibility';
        $result['ignored_model_fields'] = $ignored;
    }

    $result['selected_key'] = null;
    $result['selected_rating'] = null;
    $result['media_key'] = null;
    $result['media_reason_code'] = null;
    $result['send_authorised'] = false;
    $result['decision_state'] = 'final';
    $result['reason_codes'] = array_values(array_diff(
        (array) ($result['reason_codes'] ?? []),
        ['awaiting_aimee_choice']
    ));

    if (!in_array($action, ['send', 'decline', 'defer'], true)) {
        $result['aimee_decision'] = 'defer';
        $result['aimee_reason_code'] = 'model_choice_invalid';
        $result['media_reason_code'] = 'model_choice_invalid';
        $result['reason_codes'][] = 'model_choice_invalid';
        $result['reason_codes'] = array_values(array_unique($result['reason_codes']));
        return $result;
    }

    $model_reason = aimee_media_decision_token(
        isset($choice['media_reason_code'])
            ? $choice['media_reason_code']
            : (isset($choice['reason_code']) ? $choice['reason_code'] : '')
    );
    $allowed_reasons = aimee_media_decision_default_policy()['model_reason_codes'][$action];
    if (!in_array($model_reason, $allowed_reasons, true)) {
        if ($action === 'send') $model_reason = 'aimee_desires_to_share';
        if ($action === 'decline') $model_reason = 'aimee_boundary_choice';
        if ($action === 'defer') $model_reason = 'aimee_prefers_more_context';
    }

    if ($action === 'send') {
        if (empty($result['media_opportunity']) || !empty($result['hard_veto'])) {
            $result['aimee_decision'] = 'defer';
            $result['aimee_reason_code'] = 'model_choice_blocked_by_policy';
            $result['media_reason_code'] = 'model_choice_blocked_by_policy';
            $result['reason_codes'][] = 'model_choice_blocked_by_policy';
            $result['reason_codes'] = array_values(array_unique($result['reason_codes']));
            return $result;
        }

        $selected_key = aimee_media_decision_token(
            isset($choice['media_key'])
                ? $choice['media_key']
                : (isset($choice['selected_key']) ? $choice['selected_key'] : '')
        );
        if ($selected_key === '' || !in_array($selected_key, (array) ($result['eligible_keys'] ?? []), true)) {
            $result['aimee_decision'] = 'defer';
            $result['aimee_reason_code'] = 'model_selected_ineligible_key';
            $result['media_reason_code'] = 'model_selected_ineligible_key';
            $result['reason_codes'][] = 'model_selected_ineligible_key';
            $result['reason_codes'] = array_values(array_unique($result['reason_codes']));
            return $result;
        }

        $eligible_item = isset($result['eligible_items'][$selected_key])
            ? $result['eligible_items'][$selected_key]
            : null;
        if (!is_array($eligible_item)) {
            $result['aimee_decision'] = 'defer';
            $result['aimee_reason_code'] = 'model_selected_ineligible_key';
            $result['media_reason_code'] = 'model_selected_ineligible_key';
            $result['reason_codes'][] = 'model_selected_ineligible_key';
            $result['reason_codes'] = array_values(array_unique($result['reason_codes']));
            return $result;
        }

        $selected_rating = (string) ($eligible_item['content_rating'] ?? '');
        if (
            aimee_media_decision_rating_rank($selected_rating) < 0
            || aimee_media_decision_rating_rank($selected_rating)
                > aimee_media_decision_rating_rank($result['maximum_rating'] ?? '')
        ) {
            $result['aimee_decision'] = 'defer';
            $result['aimee_reason_code'] = 'model_choice_blocked_by_policy';
            $result['media_reason_code'] = 'model_choice_blocked_by_policy';
            $result['reason_codes'][] = 'model_choice_blocked_by_policy';
            $result['reason_codes'] = array_values(array_unique($result['reason_codes']));
            return $result;
        }

        $result['aimee_decision'] = 'send';
        $result['aimee_reason_code'] = $model_reason;
        $result['media_reason_code'] = $model_reason;
        $result['media_key'] = $selected_key;
        $result['selected_key'] = $selected_key;
        $result['selected_rating'] = $selected_rating;
        $result['send_authorised'] = true;
        $result['reason_codes'][] = 'aimee_chose_to_send';
        $result['reason_codes'] = array_values(array_unique($result['reason_codes']));
        return $result;
    }

    $result['aimee_decision'] = $action;
    $result['aimee_reason_code'] = $model_reason;
    $result['media_reason_code'] = $model_reason;
    $result['reason_codes'][] = $action === 'decline'
        ? 'aimee_chose_to_decline'
        : 'aimee_chose_to_defer';
    $result['reason_codes'] = array_values(array_unique($result['reason_codes']));
    return $result;
}
