<?php
/**
 * Deterministic proactive-media cadence and catalogue relevance planning.
 *
 * This module creates opportunities only. Relationship, adult, access,
 * catalogue, rotation, authorisation and delivery policy remain authoritative
 * in media-decision.php and the engine. A target cadence is never entitlement
 * and never forces Aimee to send.
 */

defined('ABSPATH') || defined('AIMEE_TESTING') || exit;

function aimee_media_cadence_policy_version() {
    return '1.0.0';
}

function aimee_media_cadence_default_policy() {
    return [
        'version' => aimee_media_cadence_policy_version(),
        'target_seconds' => 48 * 60 * 60,
        'reconsider_seconds' => 12 * 60 * 60,
        'minimum_meaningful_interactions' => 2,
        'relevance_threshold' => 4,
        'maximum_relevance_matches' => 6,
    ];
}

function aimee_media_cadence_policy($overrides = []) {
    $policy = aimee_media_cadence_default_policy();
    if (!is_array($overrides)) return $policy;

    foreach ([
        'target_seconds' => [24 * 60 * 60, 7 * 24 * 60 * 60],
        'reconsider_seconds' => [60 * 60, 48 * 60 * 60],
        'minimum_meaningful_interactions' => [0, 20],
        'relevance_threshold' => [2, 20],
        'maximum_relevance_matches' => [1, 20],
    ] as $field => $bounds) {
        if (!isset($overrides[$field]) || !is_numeric($overrides[$field])) continue;
        $value = intval($overrides[$field]);
        if ($value >= $bounds[0] && $value <= $bounds[1]) {
            $policy[$field] = $value;
        }
    }

    return $policy;
}

function aimee_media_cadence_lower($value) {
    $value = trim((string) $value);
    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

function aimee_media_cadence_terms($value) {
    $value = aimee_media_cadence_lower($value);
    if ($value === '') return [];

    preg_match_all('/[\p{L}\p{N}]{3,}/u', $value, $matches);
    $stopwords = array_fill_keys([
        'about', 'after', 'again', 'aimee', 'also', 'and', 'are', 'because',
        'been', 'before', 'being', 'could', 'did', 'does', 'doing', 'from',
        'have', 'here', 'hers', 'image', 'images', 'into', 'just', 'like',
        'more', 'photo', 'photos', 'picture', 'pictures', 'really', 'selfie',
        'send', 'share', 'show', 'some', 'that', 'their', 'them', 'then',
        'there', 'these', 'they', 'this', 'those', 'very', 'want', 'what',
        'when', 'where', 'which', 'with', 'would', 'your', 'youre',
    ], true);
    $terms = [];
    foreach ((array) ($matches[0] ?? []) as $term) {
        $term = aimee_media_cadence_lower($term);
        if ($term !== '' && !isset($stopwords[$term])) $terms[$term] = true;
    }
    return array_keys($terms);
}

function aimee_media_cadence_contains_phrase($haystack, $phrase) {
    $haystack = aimee_media_cadence_lower($haystack);
    $phrase = aimee_media_cadence_lower($phrase);
    if ($haystack === '' || $phrase === '') return false;

    return preg_match(
        '/(?<![\p{L}\p{N}])' . preg_quote($phrase, '/') . '(?![\p{L}\p{N}])/u',
        $haystack
    ) === 1;
}

/**
 * Score one catalogue item's topical fit to the current user turn.
 * Recent history may disambiguate a live reference, but cannot trigger a
 * relevance send by itself.
 */
function aimee_media_catalogue_item_relevance(
    $user_text,
    $recent_history,
    $key,
    $item
) {
    $item = is_array($item) ? $item : [];
    $current = aimee_media_cadence_lower($user_text);
    $history = aimee_media_cadence_lower($recent_history);
    if ($current === '') {
        return ['score' => 0, 'evidence' => [], 'current_match' => false];
    }

    $evidence = [];
    $score = 0;
    $current_match = false;
    $curated_terms = array_values(array_filter(array_map(
        'aimee_media_cadence_lower',
        (array) ($item['relevance_terms'] ?? [])
    )));
    $tags = array_values(array_filter(array_map(
        'aimee_media_cadence_lower',
        (array) ($item['tags'] ?? [])
    )));

    // Broad legacy tags such as "evening", "casual" or "candid" may help
    // rank a match, but only explicit curator-supplied terms can trigger one.
    if (!$curated_terms) {
        return ['score' => 0, 'evidence' => [], 'current_match' => false];
    }

    foreach ($curated_terms as $term) {
        if (aimee_media_cadence_contains_phrase($current, $term)) {
            $words = aimee_media_cadence_terms($term);
            $points = count($words) >= 2 ? 6 : 4;
            $score += $points;
            $current_match = true;
            $evidence[] = 'curated_term:' . preg_replace('/[^a-z0-9]+/', '_', $term);
        }
    }

    if (!$current_match) {
        return ['score' => 0, 'evidence' => [], 'current_match' => false];
    }

    $item_text = implode(' ', [
        (string) $key,
        (string) ($item['description'] ?? ''),
        (string) ($item['alt'] ?? ''),
        implode(' ', $curated_terms),
        implode(' ', $tags),
    ]);
    $item_terms = array_fill_keys(aimee_media_cadence_terms($item_text), true);
    $current_terms = aimee_media_cadence_terms($current);
    $overlap = [];
    foreach ($current_terms as $term) {
        if (isset($item_terms[$term])) $overlap[$term] = true;
    }
    if ($overlap) {
        $term_points = min(4, count($overlap));
        $score += $term_points;
        $current_match = true;
        foreach (array_keys($overlap) as $term) {
            $evidence[] = 'current_term:' . $term;
        }
    }

    $uses_live_reference = preg_match(
        '/\b(?:that|those|it|one|ones|there|same|earlier|before)\b/u',
        $current
    ) === 1;
    if ($uses_live_reference && $history !== '') {
        $history_terms = array_fill_keys(aimee_media_cadence_terms($history), true);
        $history_overlap = [];
        foreach (array_keys($item_terms) as $term) {
            if (isset($history_terms[$term])) $history_overlap[$term] = true;
        }
        if ($history_overlap) {
            $score += min(2, count($history_overlap));
            $evidence[] = 'live_reference_history_support';
        }
    }

    return [
        'score' => min(20, $score),
        'evidence' => array_values(array_unique($evidence)),
        'current_match' => $current_match,
    ];
}

function aimee_media_catalogue_relevance(
    $user_text,
    $recent_history,
    $catalogue,
    $policy_overrides = []
) {
    $policy = aimee_media_cadence_policy($policy_overrides);
    $matches = [];
    foreach ((array) $catalogue as $key => $item) {
        if (!is_array($item)) continue;
        $result = aimee_media_catalogue_item_relevance(
            $user_text,
            $recent_history,
            $key,
            $item
        );
        if (
            !empty($result['current_match'])
            && intval($result['score'] ?? 0) >= intval($policy['relevance_threshold'])
        ) {
            $matches[(string) $key] = $result;
        }
    }

    uasort($matches, static function ($left, $right) {
        $score_compare = intval($right['score'] ?? 0) <=> intval($left['score'] ?? 0);
        if ($score_compare !== 0) return $score_compare;
        return strcmp(
            implode('|', (array) ($left['evidence'] ?? [])),
            implode('|', (array) ($right['evidence'] ?? []))
        );
    });

    return array_slice(
        $matches,
        0,
        intval($policy['maximum_relevance_matches']),
        true
    );
}

function aimee_media_cadence_due_from_timestamps(
    $last_media_at,
    $last_considered_at,
    $now = null,
    $first_eligible_at = 0,
    $policy_overrides = []
) {
    $policy = aimee_media_cadence_policy($policy_overrides);
    $now = $now === null ? time() : max(0, intval($now));
    $last_media_at = max(0, intval($last_media_at));
    $last_considered_at = max(0, intval($last_considered_at));
    $first_eligible_at = max(0, intval($first_eligible_at));
    $cadence_anchor = max($last_media_at, $first_eligible_at);

    // An unknown account/relationship start never means "forty-eight hours
    // elapsed". Callers must supply a real anchor for a first opportunity.
    if ($cadence_anchor <= 0) return false;
    if (($now - $cadence_anchor) < intval($policy['target_seconds'])) {
        return false;
    }
    if (
        $last_considered_at > 0
        && ($now - $last_considered_at) < intval($policy['reconsider_seconds'])
    ) {
        return false;
    }
    return true;
}

function aimee_media_cadence_turn_is_suitable($user_text, $intent = 'general') {
    $text = aimee_media_cadence_lower($user_text);
    $intent = strtolower(trim((string) $intent));
    if (!in_array($intent, ['general', 'romantic_or_flirty'], true)) return false;
    if ($text === '') return false;

    if (preg_match(
        '/\b(?:good ?night|goodbye|bye for now|speak tomorrow|talk tomorrow|'
        . 'speak soon|talk later|gotta go|have to go|ttyl)\b/u',
        $text
    ) === 1) {
        return false;
    }
    if (preg_match(
        '/^\s*(?:ok(?:ay)?|k|fine|thanks?|thank you|cheers|bye|goodbye|night|'
        . 'goodnight|speak soon|talk later|gotta go|have to go|ttyl)[.! xxo🙂😊]*$/iu',
        $text
    ) === 1) {
        return false;
    }
    if (preg_match(
        '/\b(?:suicid(?:e|al)|self[- ]?harm|medical emergency|call an ambulance|'
        . 'in hospital|died|death|bereave(?:d|ment)|grief|panic attack|'
        . 'domestic abuse|sexual assault)\b/u',
        $text
    ) === 1) {
        return false;
    }

    return count(aimee_media_cadence_terms($text)) >= 2;
}

/**
 * Produce a pure opportunity plan. The engine supplies timestamps and the
 * relationship/safety snapshot; media-decision.php still resolves rating and
 * catalogue eligibility.
 */
function aimee_media_opportunity_plan($input, $catalogue, $policy_overrides = []) {
    $input = is_array($input) ? $input : [];
    $policy = aimee_media_cadence_policy($policy_overrides);
    $hard_vetoes = [];
    foreach ([
        'colleague' => 'colleague_lane',
        'underage' => 'adult_status_required',
        'pressure' => 'pressure',
        'coercion' => 'coercion',
        'entitlement' => 'entitlement',
        'payment_pressure' => 'payment_pressure',
        'hostility' => 'hostility',
        'rupture_active' => 'rupture_active',
    ] as $field => $reason) {
        if (!empty($input[$field])) $hard_vetoes[] = $reason;
    }
    if (array_key_exists('respectful', $input) && empty($input['respectful'])) {
        $hard_vetoes[] = 'respect_required';
    }

    $allow_relevance = !array_key_exists('allow_relevance', $input)
        || !empty($input['allow_relevance']);
    $matches = $allow_relevance
        ? aimee_media_catalogue_relevance(
            (string) ($input['user_text'] ?? ''),
            (string) ($input['recent_history'] ?? ''),
            $catalogue,
            $policy
        )
        : [];
    $now = array_key_exists('now', $input)
        ? max(0, intval($input['now']))
        : time();
    $relevance_considered_at = is_array(
        $input['relevance_considered_at'] ?? null
    ) ? $input['relevance_considered_at'] : [];
    $suppressed_relevance_keys = [];
    foreach (array_keys($matches) as $matched_key) {
        $last_considered = max(0, intval(
            $relevance_considered_at[$matched_key] ?? 0
        ));
        if (
            $last_considered > 0
            && ($now - $last_considered) < intval(
                $policy['reconsider_seconds']
            )
        ) {
            unset($matches[$matched_key]);
            $suppressed_relevance_keys[] = (string) $matched_key;
        }
    }
    $meaningful = max(0, intval($input['meaningful_interaction_count'] ?? 0));
    $cadence_due = !$hard_vetoes
        && empty($input['direct_request'])
        && !empty($input['active_exchange'])
        && $meaningful >= intval($policy['minimum_meaningful_interactions'])
        && aimee_media_cadence_due_from_timestamps(
            $input['last_media_at'] ?? 0,
            $input['last_considered_at'] ?? 0,
            $input['now'] ?? null,
            $input['first_eligible_at'] ?? 0,
            $policy
        );

    $relevance_active = !$hard_vetoes
        && empty($input['direct_request'])
        && !empty($matches);
    $kind = $relevance_active
        ? 'conversation_relevance'
        : ($cadence_due ? 'cadence_due' : 'none');
    $priority = $relevance_active ? 'high' : ($cadence_due ? 'normal' : 'none');

    return [
        'schema_version' => 'aimee.media-opportunity-plan/1',
        'policy_version' => (string) $policy['version'],
        'active' => $kind !== 'none',
        'kind' => $kind,
        'priority' => $priority,
        'cadence_due' => $cadence_due,
        'target_seconds' => intval($policy['target_seconds']),
        'reconsider_seconds' => intval($policy['reconsider_seconds']),
        'relevance_active' => $relevance_active,
        'relevant_keys' => array_keys($matches),
        'relevance_matches' => $matches,
        'suppressed_relevance_keys' => $suppressed_relevance_keys,
        'hard_vetoes' => array_values(array_unique($hard_vetoes)),
        'aimee_retains_discretion' => true,
        'payment_creates_consent' => false,
    ];
}
