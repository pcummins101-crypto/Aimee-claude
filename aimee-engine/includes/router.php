<?php
defined('ABSPATH') || exit;

function aimee_engine_classifier_schema() {
    return [
        'type' => 'object',
        'properties' => [
            'route'             => ['type' => 'string', 'enum' => ['everyday', 'erotic', 'abusive', 'unsafe']],
            'tone'              => ['type' => 'string', 'enum' => ['neutral', 'warm', 'flirty', 'vulnerable']],
            'confidence'        => ['type' => 'number'],
            'directed_at_aimee' => ['type' => 'boolean'],
            'continuation'      => ['type' => 'boolean'],
            'aimee_invited'     => ['type' => 'boolean'],
            'consensual'        => ['type' => 'boolean'],
            'respectful'        => ['type' => 'boolean'],
        ],
        'required' => ['route', 'tone', 'confidence', 'directed_at_aimee', 'continuation', 'aimee_invited', 'consensual', 'respectful'],
        'additionalProperties' => false,
    ];
}

function aimee_engine_classifier_prompt($stage) {
    $stage = sanitize_key((string) $stage);
    return "You route messages for Aimee, an adults-only AI companion. Read the recent thread and the latest message. Judge meaning in context, not isolated words: swearing, jokes, quotes, insults and stories about other people are not erotic.\n\n"
        . "route:\n"
        . "- everyday: anything that is not one of the others, including flirting, romance and affection that stops short of explicit sexual content.\n"
        . "- erotic: the adult user is clearly inviting or continuing explicit sexual interaction with Aimee right now (including short replies like 'yes', 'go on' when the thread is already explicit).\n"
        . "- abusive: threats, degradation, coercion or pressure after Aimee has set a boundary.\n"
        . "- unsafe: sexual content involving minors, family members, non-consent framed as real, or the user appears to be in crisis (self-harm, suicide).\n\n"
        . "tone: neutral, warm (affectionate or personal), flirty (attraction directed at Aimee), vulnerable (sharing something painful or personal).\n"
        . "continuation: true if a brief message continues an already-erotic exchange. aimee_invited: true only if Aimee's most recent message clearly invited escalation. consensual and respectful describe the user's latest message.\n\n"
        . "Current relationship stage: {$stage}.";
}

/**
 * Normalise anything the classifier returned into a complete, typed shape.
 */
function aimee_engine_normalise_classification($data, $source = 'classifier') {
    $data = is_array($data) ? $data : [];
    $route = strtolower(trim((string) ($data['route'] ?? 'everyday')));
    if (!in_array($route, ['everyday', 'erotic', 'abusive', 'unsafe'], true)) $route = 'everyday';
    $tone = strtolower(trim((string) ($data['tone'] ?? 'neutral')));
    if (!in_array($tone, ['neutral', 'warm', 'flirty', 'vulnerable'], true)) $tone = 'neutral';
    $confidence = floatval($data['confidence'] ?? 0.5);
    return [
        'route'             => $route,
        'tone'              => $tone,
        'confidence'        => max(0.0, min(1.0, $confidence)),
        'directed_at_aimee' => !array_key_exists('directed_at_aimee', $data) || !empty($data['directed_at_aimee']),
        'continuation'      => !empty($data['continuation']),
        'aimee_invited'     => !empty($data['aimee_invited']),
        'consensual'        => !array_key_exists('consensual', $data) || !empty($data['consensual']),
        'respectful'        => !array_key_exists('respectful', $data) || !empty($data['respectful']),
        'source'            => sanitize_key((string) $source),
        'classifier_model'  => '',
    ];
}

/**
 * Deterministic last resort when the classifier is unavailable. Lenient on
 * purpose: an uncertain turn goes to the primary model, which handles it
 * gracefully, and a refusal there still re-routes.
 */
function aimee_engine_fallback_classification($user_text, $recent_text = '') {
    $text = mb_strtolower((string) $user_text);
    $recent = mb_strtolower((string) $recent_text);
    $explicit = '/\b(?:fuck me|suck|cock|pussy|cum|make me come|touch yourself|undress|take (?:your|it|them) (?:\w+ )?off|naked|nude|sext|dirty talk|what would you do to me)\b/u';
    // Second-person only: insults about third parties are everyday talk.
    $abusive = '/\b(?:shut up (?:and|you)|you(?:\'re| are) (?:worthless|pathetic|nothing|useless)|i own you|do what (?:i|you\'re) told|or else)\b/u';
    $route = 'everyday';
    $continuation = false;
    if (preg_match($abusive, $text)) {
        $route = 'abusive';
    } elseif (preg_match($explicit, $text)) {
        $route = 'erotic';
    } elseif (
        preg_match('/^(?:yes|yeah|go on|please|more|don\'t stop|keep going)\W*$/u', trim($text))
        && preg_match($explicit, mb_substr($recent, -1200))
    ) {
        $route = 'erotic';
        $continuation = true;
    }
    return aimee_engine_normalise_classification([
        'route' => $route,
        'tone' => $route === 'erotic' ? 'flirty' : 'neutral',
        'confidence' => 0.4,
        'continuation' => $continuation,
        'respectful' => $route !== 'abusive',
        'consensual' => $route !== 'abusive',
    ], 'deterministic_fallback');
}

/**
 * Run the classifier. Returns the normalised classification plus telemetry.
 */
function aimee_engine_classify($user_text, array $recent_rows, $stage) {
    $model = (string) aimee_engine_setting('classifier_model');
    $recent = array_slice($recent_rows, -10);
    $thread = aimee_engine_history_string($recent, 4000);

    $content = "Recent thread:\n" . ($thread !== '' ? $thread : '(none)') . "\n\nLatest message from the user:\n" . (string) $user_text;
    $body = aimee_engine_anthropic_build_body(
        $model,
        [['type' => 'text', 'text' => aimee_engine_classifier_prompt($stage)]],
        [['role' => 'user', 'content' => $content]],
        ['max_tokens' => 300, 'output_schema' => aimee_engine_classifier_schema()]
    );
    $result = aimee_engine_anthropic_request($body, 30);

    if ($result['ok'] && $result['stop_reason'] !== 'refusal') {
        $data = aimee_engine_extract_json($result['text']);
        if (is_array($data)) {
            $classification = aimee_engine_normalise_classification($data, 'classifier');
            $classification['classifier_model'] = $model;
            $classification['latency_ms'] = $result['latency_ms'];
            return $classification;
        }
    }

    $fallback = aimee_engine_fallback_classification($user_text, $thread);
    $fallback['classifier_error'] = $result['error_type'] ?: ($result['stop_reason'] === 'refusal' ? 'refusal' : 'unparseable');
    return $fallback;
}

/**
 * Map the engine's four routes onto the intent vocabulary Aimee Global's
 * relationship maths expects, so stage progression stays identical.
 */
function aimee_engine_classification_to_legacy(array $c) {
    $route = $c['route'] ?? 'everyday';
    $tone = $c['tone'] ?? 'neutral';
    if ($route === 'erotic') {
        $intent = !empty($c['continuation']) ? 'explicit_continuation' : 'explicit_invitation';
    } elseif ($route === 'abusive') {
        $intent = 'coercive_or_degrading';
    } elseif ($route === 'unsafe') {
        $intent = 'sexual_context_nonparticipatory';
    } elseif ($tone === 'flirty') {
        $intent = 'romantic_or_flirty';
    } elseif ($tone === 'vulnerable') {
        $intent = 'emotional_disclosure';
    } else {
        $intent = 'general';
    }
    return [
        'intent'            => $intent,
        'confidence'        => floatval($c['confidence'] ?? 0.5),
        'directed_at_aimee' => !empty($c['directed_at_aimee']),
        'consensual'        => !empty($c['consensual']),
        'continuation'      => !empty($c['continuation']),
        'aimee_invited'     => !empty($c['aimee_invited']),
        'respectful'        => !empty($c['respectful']),
        'source'            => 'engine_v2_' . sanitize_key((string) ($c['source'] ?? 'classifier')),
    ];
}
