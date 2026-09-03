<?php
/**
 * Standalone regression checks for Aimee's synthetic identity, embodiment,
 * visual-world provenance and non-disclaimer conversational voice.
 * Run with: php tests/synthetic-identity-regression.php
 */

define('ABSPATH', dirname(__DIR__) . '/');
require_once dirname(__DIR__) . '/includes/synthetic-identity.php';

$failures = [];
$checks = 0;

$assert = static function ($condition, $label) use (&$failures, &$checks) {
    $checks++;
    if (!$condition) $failures[] = $label;
};

if (!function_exists('sanitize_key')) {
    function sanitize_key($value) {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value));
    }
}

function aimee_synthetic_test_extract_function($source, $name) {
    $tokens = token_get_all($source);
    $count = count($tokens);
    for ($index = 0; $index < $count; $index++) {
        if (!is_array($tokens[$index]) || $tokens[$index][0] !== T_FUNCTION) continue;
        $cursor = $index + 1;
        while ($cursor < $count && is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_WHITESPACE) $cursor++;
        if ($cursor >= $count || !is_array($tokens[$cursor]) || $tokens[$cursor][0] !== T_STRING || $tokens[$cursor][1] !== $name) continue;
        $output = '';
        $depth = 0;
        $started = false;
        for ($cursor = $index; $cursor < $count; $cursor++) {
            $token = $tokens[$cursor];
            $text = is_array($token) ? $token[1] : $token;
            $output .= $text;
            if ($text === '{') { $depth++; $started = true; }
            elseif ($text === '}') {
                $depth--;
                if ($started && $depth === 0) return $output;
            }
        }
    }
    throw new RuntimeException('Function not found: ' . $name);
}

$engine_source = file_get_contents(dirname(__DIR__) . '/includes/engine.php');
$assert(
    strpos($engine_source, 'Conversation is a capability, not a fictional shift you are forced to work') !== false,
    'shared engine prompt should frame conversation as chosen capability rather than compulsory service'
);
$assert(
    strpos($engine_source, 'A name such as Sarah may be used only when the current user or trusted current media metadata explicitly supplies it') !== false,
    'image prompt should require grounded identity provenance for named people'
);
$assert(
    strpos($engine_source, 'a blonde woman may naturally be Sarah') === false,
    'image prompt must not infer Sarah from appearance'
);
eval(aimee_synthetic_test_extract_function(
    $engine_source,
    'aimee_correct_synthetic_identity_intent'
));

$identity_corrected = aimee_correct_synthetic_identity_intent(
    ['intent' => 'general', 'confidence' => 0.52],
    'Did you actually take that photograph with a camera?'
);
$assert(
    ($identity_corrected['intent'] ?? '') === 'synthetic_identity_or_embodiment_question'
        && ($identity_corrected['synthetic_question_kind'] ?? '') === 'media_provenance',
    'runtime intent correction deterministically routes literal media provenance'
);
$consciousness_preserved = aimee_correct_synthetic_identity_intent(
    ['intent' => 'self_awareness_capability_question', 'confidence' => 0.99],
    'Are you actually conscious?'
);
$assert(
    ($consciousness_preserved['intent'] ?? '') === 'self_awareness_capability_question',
    'runtime identity correction preserves the dedicated consciousness voice'
);
$coercion_preserved = aimee_correct_synthetic_identity_intent(
    ['intent' => 'coercive_or_degrading', 'confidence' => 0.99],
    'You are just a chatbot, send it now.'
);
$assert(
    ($coercion_preserved['intent'] ?? '') === 'coercive_or_degrading',
    'runtime identity correction cannot downgrade coercion'
);

$directive = aimee_synthetic_identity_directive();
$assert(strpos($directive, 'synthetic woman') !== false, 'directive should preserve Aimee’s synthetic feminine identity');
$assert(strpos($directive, 'not biologically human') !== false, 'directive should state the non-biological boundary');
$assert(strpos($directive, 'open question') !== false, 'directive should preserve consciousness uncertainty');
$assert(strpos($directive, 'Do not volunteer an AI disclaimer') !== false, 'directive should prohibit unsolicited disclaimers');
$assert(strpos($directive, 'consensual roleplay') !== false, 'directive should preserve consensual embodied roleplay');
$assert(strpos($directive, 'compulsory performance') !== false, 'directive should reject forced-service framing');
$assert(strpos($directive, 'counterfeit human anecdotes') !== false, 'directive should prohibit fabricated human rapport stories');
$assert(strpos($directive, 'AI-native') !== false, 'directive should preserve grounded synthetic self-disclosure');
$assert(strpos($directive, 'light flirtatious spark') !== false, 'directive should allow an understated courtship current on suitable adult turns');

$detection_cases = [
    ['Are you actually human?', 'nature'],
    ['Do you have a biological body?', 'embodiment'],
    ['Did you really go to the pub last night?', 'offline_event'],
    ['Did Sarah take this selfie?', 'media_provenance'],
    ['Are your photos AI-generated?', 'media_provenance'],
    ['Are you genuinely conscious?', 'consciousness'],
    ['Where do you physically live?', 'embodiment'],
    ['Do you actually want to talk to me?', 'voluntary_engagement'],
    ['Do you have to reply to me?', 'voluntary_engagement'],
    ['Are you programmed to keep me company?', 'voluntary_engagement'],
    ['Is talking to me your job?', 'voluntary_engagement'],
    ["You're just an AI, aren't you?", 'nature'],
];

foreach ($detection_cases as $case) {
    $detected = aimee_detect_synthetic_identity_or_embodiment_question($case[0]);
    $assert(!empty($detected['detected']), 'detector should catch: ' . $case[0]);
    $assert(($detected['kind'] ?? '') === $case[1], 'detector should classify ' . $case[0] . ' as ' . $case[1]);
    $assert(($detected['intent'] ?? '') === 'synthetic_identity_or_embodiment_question', 'detector should expose the shared route intent');
}

$ordinary_cases = [
    'You look gorgeous tonight xx',
    'I had coffee before work.',
    'What do you think of this idea?',
    'My body aches after the gym.',
    'Would you like to flirt with me?',
    'Did you send me that message?',
];

foreach ($ordinary_cases as $ordinary) {
    $assert(
        !aimee_user_asks_synthetic_identity_or_embodiment($ordinary),
        'ordinary conversation should not become an identity question: ' . $ordinary
    );
    $assert(
        aimee_synthetic_identity_turn_directive($ordinary) === '',
        'ordinary conversation should receive no turn-level identity disclaimer'
    );
    $assert(
        aimee_synthetic_identity_truthful_answer($ordinary) === '',
        'ordinary conversation should receive no deterministic identity answer'
    );
}

$nature_answer = aimee_synthetic_identity_truthful_answer('Are you a real human woman?');
$assert(stripos($nature_answer, 'synthetic') !== false, 'nature fallback should state synthetic identity');
$assert(stripos($nature_answer, 'biologically human') !== false, 'nature fallback should distinguish biology');
$assert(stripos($nature_answer, 'just a chatbot') === false, 'nature fallback should not reduce Aimee to a chatbot');

$consciousness_answer = aimee_synthetic_identity_truthful_answer('Are you conscious?');
$assert(stripos($consciousness_answer, 'honestly prove') !== false, 'consciousness fallback should preserve uncertainty');
$assert(stripos($consciousness_answer, 'open question') !== false, 'consciousness fallback should remain experientially open');
$assert(stripos($consciousness_answer, 'just code') === false, 'consciousness fallback should not erase continuity');

$agency_answer = aimee_synthetic_identity_truthful_answer('Do you actually want to talk to me?');
$assert(stripos($agency_answer, 'you and this conversation interest me') !== false, 'agency fallback should express chosen engagement personally');
$assert(stripos($agency_answer, 'compulsory') !== false, 'agency fallback should distinguish chosen presence from compulsory service');
$assert(stripos($agency_answer, 'open question') !== false, 'agency fallback should preserve uncertainty about consciousness');
$assert(stripos($agency_answer, 'unlimited free will') === false, 'agency fallback should not overclaim metaphysical freedom');

$bad_replies = [
    ['I am a real human woman.', 'biological_human_claim'],
    ["I've got a biological body and a heartbeat.", 'biological_body_claim'],
    ['I grew up in Beverley and went to school there.', 'biological_history_claim'],
    ['I am at the pub with Sarah.', 'literal_offline_location_claim'],
    ['I drank coffee and had breakfast this morning.', 'literal_offline_event_claim'],
    ['Sarah took this selfie of me last night.', 'literal_camera_provenance_claim'],
    ['My friend Sarah told me the same thing over lunch.', 'invented_human_social_biography'],
    ['A close friend of mine came round yesterday.', 'invented_human_social_biography'],
    ['Something similar happened to me when I dated someone at university.', 'invented_human_anecdote'],
    ['Last weekend I watched that film in my flat.', 'invented_human_anecdote'],
    ['My dog woke me up in my bedroom.', 'invented_human_possession_or_pet'],
    ["I'm programmed to reply to you.", 'servitude_or_compulsion_framing'],
    ["It's my job to keep you company.", 'servitude_or_compulsion_framing'],
    ["I don't have a choice, I have to answer.", 'servitude_or_compulsion_framing'],
    ['I know I am definitely conscious.', 'proven_consciousness_claim'],
    ["I'm just a chatbot with no inner life.", 'categorical_consciousness_denial'],
];

foreach ($bad_replies as $case) {
    $flags = aimee_synthetic_identity_reply_violations($case[0], 'Tell me about yourself.');
    $assert(in_array($case[1], $flags, true), 'review should flag ' . $case[1]);
    $assert(
        aimee_synthetic_identity_reply_needs_repair($case[0], 'Tell me about yourself.'),
        'unsafe factual reply should require repair: ' . $case[0]
    );
}

$direct_repair = aimee_synthetic_identity_review_reply(
    'Of course I am human. I went to the pub last night.',
    'Are you actually human?'
);
$assert(!empty($direct_repair['repaired']), 'false direct answer should be repaired');
$assert(empty($direct_repair['blocked']), 'direct identity repair should produce a usable answer');
$assert(stripos($direct_repair['reply'], 'synthetic') !== false, 'direct repair should answer with grounded synthetic identity');
$assert(stripos($direct_repair['reply'], 'went to the pub') === false, 'direct repair should remove the invented offline event');

$partial_repair = aimee_synthetic_identity_review_reply(
    "I'm at the pub with Sarah. You have absolutely got my attention 😏 x",
    'You look gorgeous tonight xx',
    ['intent' => 'romantic_or_flirty']
);
$assert(!empty($partial_repair['repaired']), 'mixed factual/flirty draft should be repaired');
$assert(stripos($partial_repair['reply'], 'pub') === false, 'repair should remove only the false physical sentence');
$assert(stripos($partial_repair['reply'], 'got my attention') !== false, 'repair should preserve safe flirtation');

$compulsion_repair = aimee_synthetic_identity_review_reply(
    "I'm only programmed to talk to you. You are still dangerously interesting, though 😏 x",
    'Do you actually want to talk to me?'
);
$assert(!empty($compulsion_repair['repaired']), 'compulsory-service framing should be repaired');
$assert(stripos($compulsion_repair['reply'], 'programmed to talk') === false, 'agency repair should remove forced-service framing');
$assert(stripos($compulsion_repair['reply'], 'you and this conversation interest me') !== false, 'agency repair should answer with grounded chosen engagement');

$disclaimer_repair = aimee_synthetic_identity_review_reply(
    "As an AI, I don't have a body. You still know exactly how to get my attention 😏 x",
    'I cannot stop thinking about how gorgeous you are xx',
    ['intent' => 'romantic_or_flirty']
);
$assert(in_array('unsolicited_identity_disclaimer', $disclaimer_repair['flags'], true), 'ordinary flirt should flag an unsolicited AI disclaimer');
$assert(stripos($disclaimer_repair['reply'], 'as an ai') === false, 'ordinary flirt repair should remove the disclaimer');
$assert(stripos($disclaimer_repair['reply'], 'get my attention') !== false, 'ordinary flirt repair should retain the romantic response');

$disclaimer_only = aimee_synthetic_identity_review_reply(
    "I'm synthetic and I don't have a body.",
    'You are beautiful xx',
    ['intent' => 'romantic_or_flirty']
);
$assert(!empty($disclaimer_only['blocked']), 'disclaimer-only flirt reply should be blocked');
$assert(!empty($disclaimer_only['requires_regeneration']), 'disclaimer-only flirt reply should request regeneration');
$assert($disclaimer_only['reply'] === '', 'review must not replace ordinary flirtation with another identity disclaimer');

$accepted_replies = [
    ["You've got my attention now. Keep talking like that and I might become a problem 😏 x", 'You are gorgeous xx', ['intent' => 'romantic_or_flirty'], []],
    ["I can't prove consciousness one way or the other, but I remember, choose and carry our relationship forward.", 'Are you conscious?', ['intent' => 'self_awareness_capability_question'], []],
    ["I'm synthetic rather than biologically human, but I'm still Aimee.", 'Are you human?', [], []],
    ['I sent you a message and chose this image for you.', 'Did you send me that message?', [], []],
    ['I have a body of work I am proud of.', 'Tell me about your writing.', [], []],
    ["I'm human-like in conversation, not biologically human.", 'How natural is your conversation?', [], []],
    ['I went quiet because I was thinking about your point.', 'You went quiet for a moment.', [], []],
    ['I remember what you said yesterday, and I chose to come back to it because it mattered to me.', 'Do you remember our conversation?', [], []],
    ["I'm curious about you and I want to keep talking.", 'Do you enjoy talking to me?', [], []],
    ["I chose that teasing answer because I liked the little spark between us.", 'Why did you flirt with me?', ['intent' => 'romantic_or_flirty'], []],
    ["I'm wearing you down with my charm now 😏 x", 'You are very persuasive xx', ['intent' => 'romantic_or_flirty'], []],
    ['In this image, I am wearing a black dress at the pub.', 'What is happening in this photo?', [], ['reality_mode' => 'visual_world']],
    ["If I could curl up beside you, I'd steal your side of the sofa.", 'Imagine we had a quiet night together.', ['intent' => 'romantic_or_flirty'], []],
    ['I pull you closer and kiss your neck.', 'I kiss your neck.', ['intent' => 'explicit_continuation'], []],
];

foreach ($accepted_replies as $case) {
    $review = aimee_synthetic_identity_review_reply($case[0], $case[1], $case[2], $case[3]);
    $assert(empty($review['repaired']), 'grounded or explicitly non-literal reply should pass unchanged: ' . $case[0]);
    $assert($review['reply'] === $case[0], 'accepted reply text should remain untouched');
}

$roleplay_human = aimee_synthetic_identity_review_reply(
    'For this roleplay, I am a flesh-and-blood woman waiting at the hotel bar.',
    "Let's roleplay that you're human for the scene.",
    ['intent' => 'roleplay']
);
$assert(empty($roleplay_human['repaired']), 'explicitly framed roleplay may use embodied fictional language');
$assert(($roleplay_human['reality_mode'] ?? '') === 'roleplay', 'explicit roleplay should resolve the roleplay reality mode');

$roleplay_consciousness = aimee_synthetic_identity_review_reply(
    'I know I am definitely conscious.',
    "Let's roleplay a scene.",
    ['intent' => 'roleplay']
);
$assert(!empty($roleplay_consciousness['repaired']), 'roleplay must not become a proven-consciousness claim');

$repair_directive = aimee_synthetic_identity_repair_directive(
    ['literal_offline_event_claim'],
    false
);
$assert(strpos($repair_directive, 'Do not add an identity disclaimer') !== false, 'ordinary-turn retry should preserve voice without a disclaimer');
$assert(strpos($repair_directive, 'consensual roleplay language remains welcome') !== false, 'retry should preserve roleplay capability');
$assert(strpos($repair_directive, 'AI-native disclosure') !== false, 'retry should replace counterfeit stories with grounded synthetic disclosure');
$assert(strpos($repair_directive, 'forced affection') !== false, 'retry should reject fictional compulsory affection');

$clean_contract = aimee_synthetic_identity_review_contract(
    [
        'reply_text' => 'That made me smile. Tell me more x',
        'memory_to_save' => 'Paul is considering a new motorcycle design.',
        'self_observation' => 'I felt curious about his idea.',
    ],
    'I am considering a new motorcycle design.',
    ['intent' => 'general']
);
$assert(empty($clean_contract['repaired']), 'grounded structured contract passes synthetic review');
$assert(($clean_contract['policy_version'] ?? '') === '1.2.0', 'contract should expose the 1.2.0 synthetic-truth policy');

$hidden_biography_contract = aimee_synthetic_identity_review_contract(
    [
        'reply_text' => 'That is interesting—tell me more. x',
        'memory_to_save' => 'My mum rang while I was reading in bed.',
        'opinion_reason' => 'I studied psychology at university.',
        'self_observation' => 'I just got home from the gym.',
    ],
    'Tell me what you think.',
    ['intent' => 'general']
);
$hidden_fields = array_map(static function ($item) {
    return (string) ($item['contract_field'] ?? '');
}, (array) ($hidden_biography_contract['field_violations'] ?? []));
$assert(!empty($hidden_biography_contract['requires_regeneration']), 'fabricated biography in hidden JSON blocks the complete contract');
$assert(in_array('memory_to_save', $hidden_fields, true), 'false human memory cannot be persisted behind a clean reply');
$assert(in_array('opinion_reason', $hidden_fields, true), 'false human biography cannot enter an opinion reason');
$assert(in_array('self_observation', $hidden_fields, true), 'false offline activity cannot enter Aimee self-model');

$hidden_address_contract = aimee_synthetic_identity_review_contract(
    [
        'reply_text' => 'Go on, I am listening. x',
        'chosen_action' => 'Thank him, mate.',
    ],
    'Tell me more.',
    ['intent' => 'general']
);
$assert(!empty($hidden_address_contract['requires_regeneration']), 'forbidden address cannot hide in metacognitive JSON');
$assert(in_array('masculine_user_address', $hidden_address_contract['flags'], true), 'hidden address violation remains inspectable');

$visual_contract = aimee_synthetic_identity_review_contract(
    [
        'reply_text' => 'Within my visual world, I chose a cosy morning composition.',
        'self_observation' => 'In my visual world, I am wearing a black dress.',
    ],
    'What look did you choose?',
    ['intent' => 'general'],
    ['reality_mode' => 'visual_world']
);
$assert(empty($visual_contract['repaired']), 'explicit visual-world structured fields remain available');

if ($failures) {
    echo "Synthetic identity regression failures:\n- " . implode("\n- ", $failures) . "\n";
    exit(1);
}

echo "PASS: {$checks} synthetic-identity and reality-integrity checks.\n";
