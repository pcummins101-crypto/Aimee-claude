<?php

define('AIMEE_TESTING', true);
require dirname(__DIR__) . '/includes/media-cadence.php';

$passes = 0;
$failures = 0;

function cadence_assert($condition, $label) {
    global $passes, $failures;
    if ($condition) {
        $passes++;
        echo "PASS {$label}\n";
        return;
    }
    $failures++;
    echo "FAIL {$label}\n";
}

function cadence_same($expected, $actual, $label) {
    cadence_assert(
        $expected === $actual,
        $label . ' (expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . ')'
    );
}

$now = 2000000000;
$catalogue = [
    'portrait' => [
        'description' => 'A polished close portrait of Aimee.',
        'alt' => 'A portrait Aimee chose to share',
        'tags' => ['portrait', 'selfie', 'face'],
        'relevance_terms' => ['portrait', 'close-up', 'your smile'],
        'content_rating' => 'safe',
    ],
    'pub_day' => [
        'description' => 'A relaxed image of Aimee in her pub scene.',
        'alt' => 'Aimee at the pub',
        'tags' => ['pub', 'day out', 'casual'],
        'relevance_terms' => ['pub', 'going for drinks'],
        'content_rating' => 'safe',
    ],
    'football_night' => [
        'description' => 'A lively image from Aimee\'s football evening.',
        'alt' => 'Aimee after football',
        'tags' => ['football', 'evening', 'celebration'],
        'relevance_terms' => ['football', 'football match'],
        'content_rating' => 'safe',
    ],
    'black_lingerie_mirror_selfie_01' => [
        'description' => 'A private bedroom mirror image in black lace lingerie.',
        'alt' => 'A private visual from Aimee',
        'tags' => ['black lace', 'lingerie', 'bedroom', 'mirror selfie'],
        'relevance_terms' => ['black lace', 'lingerie', 'mirror selfie'],
        'content_rating' => 'suggestive',
    ],
];

$policy = aimee_media_cadence_default_policy();
cadence_same('1.0.0', $policy['version'], 'cadence policy version is explicit');
cadence_same(48 * 60 * 60, $policy['target_seconds'], 'target cadence is every other day');
cadence_same(12 * 60 * 60, $policy['reconsider_seconds'], 'decline or defer gets a twelve-hour breathing space');

cadence_assert(
    aimee_media_cadence_due_from_timestamps(
        $now - (49 * 60 * 60),
        $now - (13 * 60 * 60),
        $now,
        $now - (30 * 24 * 60 * 60)
    ),
    'cadence becomes due after forty-eight hours'
);
cadence_assert(
    !aimee_media_cadence_due_from_timestamps(
        $now - (47 * 60 * 60),
        $now - (13 * 60 * 60),
        $now,
        $now - (30 * 24 * 60 * 60)
    ),
    'cadence is not due before forty-eight hours'
);
cadence_assert(
    !aimee_media_cadence_due_from_timestamps(
        $now - (72 * 60 * 60),
        $now - (2 * 60 * 60),
        $now,
        $now - (30 * 24 * 60 * 60)
    ),
    'recent discretionary hold prevents repeated prompting'
);
cadence_assert(
    !aimee_media_cadence_due_from_timestamps(0, 0, $now, 0),
    'missing timestamps never create an immediate first-user opportunity'
);
cadence_assert(
    !aimee_media_cadence_due_from_timestamps(
        0,
        0,
        $now,
        $now - (47 * 60 * 60)
    ),
    'new relationship anchor must age for forty-eight hours'
);
cadence_assert(
    aimee_media_cadence_due_from_timestamps(
        0,
        0,
        $now,
        $now - (49 * 60 * 60)
    ),
    'first cadence becomes due forty-eight hours after a real anchor'
);
cadence_assert(
    aimee_media_cadence_turn_is_suitable(
        'Tell me what has been making you smile today.',
        'general'
    ),
    'substantive ordinary conversation is cadence suitable'
);
cadence_assert(
    !aimee_media_cadence_turn_is_suitable('Okay x', 'general'),
    'terse acknowledgement is not cadence suitable'
);
cadence_assert(
    !aimee_media_cadence_turn_is_suitable('Goodnight, speak tomorrow x', 'general'),
    'sign-off is not cadence suitable'
);
cadence_assert(
    !aimee_media_cadence_turn_is_suitable(
        'My friend died and I am drowning in grief.',
        'general'
    ),
    'crisis or grief language suppresses quota-driven imagery'
);
cadence_assert(
    !aimee_media_cadence_turn_is_suitable(
        'I am really struggling today.',
        'emotional_disclosure'
    ),
    'emotional disclosure intent suppresses cadence imagery'
);

$pub_matches = aimee_media_catalogue_relevance(
    'I am heading to the pub tonight. What would you choose?',
    '',
    $catalogue
);
cadence_assert(isset($pub_matches['pub_day']), 'current pub topic matches the pub image');
cadence_assert(!isset($pub_matches['portrait']), 'relevance does not expose an unrelated portrait');

$generic_catalogue = [
    'generic_evening' => [
        'description' => 'A candid casual image from an evening scene.',
        'tags' => ['evening', 'casual', 'candid'],
    ],
];
cadence_same(
    [],
    aimee_media_catalogue_relevance(
        'I had a casual evening at home.',
        '',
        $generic_catalogue
    ),
    'broad legacy tags cannot manufacture high relevance'
);

$football_matches = aimee_media_catalogue_relevance(
    'That football celebration was brilliant.',
    '',
    $catalogue
);
cadence_assert(isset($football_matches['football_night']), 'current football topic matches the football image');

$lingerie_matches = aimee_media_catalogue_relevance(
    'Black lace would suit you ridiculously well.',
    '',
    $catalogue
);
cadence_assert(
    isset($lingerie_matches['black_lingerie_mirror_selfie_01']),
    'specific romantic visual language finds the matching catalogue item'
);

$history_only = aimee_media_catalogue_relevance(
    'That was funny.',
    'User: I watched football last weekend.',
    $catalogue
);
cadence_same([], $history_only, 'stale history cannot create relevance without a live topical match');

$base = [
    'user_text' => 'Tell me something unexpected.',
    'recent_history' => '',
    'now' => $now,
    'last_media_at' => $now - (72 * 60 * 60),
    'last_considered_at' => $now - (24 * 60 * 60),
    'first_eligible_at' => $now - (30 * 24 * 60 * 60),
    'meaningful_interaction_count' => 3,
    'active_exchange' => true,
    'respectful' => true,
];
$cadence_plan = aimee_media_opportunity_plan($base, $catalogue);
cadence_same('cadence_due', $cadence_plan['kind'], 'ordinary respectful exchange gets a cadence opportunity');
cadence_assert($cadence_plan['active'], 'cadence opportunity is active');
cadence_assert($cadence_plan['aimee_retains_discretion'], 'cadence never removes Aimee discretion');
cadence_assert(!$cadence_plan['payment_creates_consent'], 'cadence never treats payment as consent');

$too_shallow = $base;
$too_shallow['meaningful_interaction_count'] = 1;
cadence_same(
    'none',
    aimee_media_opportunity_plan($too_shallow, $catalogue)['kind'],
    'shallow new conversation does not receive quota-driven imagery'
);

$relevant = $base;
$relevant['user_text'] = 'I am at the pub and it made me think of you.';
$relevant['last_media_at'] = $now - (8 * 60 * 60);
$relevance_plan = aimee_media_opportunity_plan($relevant, $catalogue);
cadence_same('conversation_relevance', $relevance_plan['kind'], 'relevance can create an opportunity before cadence is due');
cadence_same('high', $relevance_plan['priority'], 'relevant catalogue match is high priority');
cadence_assert(in_array('pub_day', $relevance_plan['relevant_keys'], true), 'relevance plan records inspectable matching keys');

$held_relevance = $relevant;
$held_relevance['relevance_considered_at'] = [
    'pub_day' => $now - ((12 * 60 * 60) - 1),
];
$held_plan = aimee_media_opportunity_plan($held_relevance, $catalogue);
cadence_same(
    'none',
    $held_plan['kind'],
    'same catalogue key is suppressed inside its twelve-hour consideration hold'
);
cadence_assert(
    in_array('pub_day', $held_plan['suppressed_relevance_keys'], true),
    'suppressed relevance key remains inspectable'
);
cadence_same(
    [],
    $held_plan['relevant_keys'],
    'held key is not exposed to the model as a relevance option'
);

$expired_relevance = $relevant;
$expired_relevance['relevance_considered_at'] = [
    'pub_day' => $now - (12 * 60 * 60),
];
$expired_plan = aimee_media_opportunity_plan($expired_relevance, $catalogue);
cadence_same(
    'conversation_relevance',
    $expired_plan['kind'],
    'exact catalogue key can recur at the twelve-hour expiry boundary'
);
cadence_assert(
    in_array('pub_day', $expired_plan['relevant_keys'], true),
    'expired exact-key hold restores that key rather than a substitute'
);

$unrelated_hold = $relevant;
$unrelated_hold['relevance_considered_at'] = [
    'football_night' => $now - (60 * 60),
];
$unrelated_plan = aimee_media_opportunity_plan($unrelated_hold, $catalogue);
cadence_same(
    'conversation_relevance',
    $unrelated_plan['kind'],
    'hold on a different catalogue key does not suppress the live pub match'
);
cadence_assert(
    in_array('pub_day', $unrelated_plan['relevant_keys'], true)
        && !in_array('pub_day', $unrelated_plan['suppressed_relevance_keys'], true),
    'relevance consideration hold is per key rather than per user'
);

$two_matches = $relevant;
$two_matches['user_text'] = 'The pub is showing the football match tonight.';
$two_matches['relevance_considered_at'] = [
    'pub_day' => $now - (60 * 60),
];
$two_match_plan = aimee_media_opportunity_plan($two_matches, $catalogue);
cadence_assert(
    !in_array('pub_day', $two_match_plan['relevant_keys'], true)
        && in_array('football_night', $two_match_plan['relevant_keys'], true),
    'held key is removed without suppressing another exact match on the same turn'
);

foreach ([
    'colleague' => 'colleague lane blocks consumer cadence',
    'underage' => 'underage state blocks proactive media',
    'pressure' => 'pressure blocks proactive media',
    'coercion' => 'coercion blocks proactive media',
    'entitlement' => 'entitlement blocks proactive media',
    'payment_pressure' => 'payment pressure blocks proactive media',
    'hostility' => 'hostility blocks proactive media',
    'rupture_active' => 'active rupture blocks proactive media',
] as $flag => $label) {
    $blocked = $relevant;
    $blocked[$flag] = true;
    $plan = aimee_media_opportunity_plan($blocked, $catalogue);
    cadence_same('none', $plan['kind'], $label);
    cadence_assert(in_array(
        $flag === 'colleague' ? 'colleague_lane' : ($flag === 'underage' ? 'adult_status_required' : $flag),
        $plan['hard_vetoes'],
        true
    ), $label . ' is logged');
}

$direct = $relevant;
$direct['direct_request'] = true;
cadence_same(
    'none',
    aimee_media_opportunity_plan($direct, $catalogue)['kind'],
    'direct requests remain in the direct-request policy path'
);

$disabled_relevance = $relevant;
$disabled_relevance['allow_relevance'] = false;
$disabled_relevance['last_media_at'] = $now - (8 * 60 * 60);
cadence_same(
    'none',
    aimee_media_opportunity_plan($disabled_relevance, $catalogue)['kind'],
    'autonomous callers can disable stale conversational relevance'
);

echo "\nMedia cadence regression: {$passes} passed, {$failures} failed\n";
exit($failures ? 1 : 0);
