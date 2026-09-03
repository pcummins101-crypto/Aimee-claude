<?php
/**
 * Executable integration regression for the live media-cadence wiring.
 *
 * The pure planner is exercised in media-cadence-regression.php. This file
 * extracts the production engine helpers and supplies small WordPress/MySQL
 * fakes so provenance, return truth and atomic claim behaviour are tested
 * without booting WordPress or calling a model provider.
 */

define('AIMEE_TESTING', true);
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);

$passes = 0;
$failures = 0;

function cadence_integration_assert($condition, $label) {
    global $passes, $failures;
    if ($condition) {
        $passes++;
        echo "PASS {$label}\n";
        return;
    }
    $failures++;
    echo "FAIL {$label}\n";
}

function cadence_integration_same($expected, $actual, $label) {
    cadence_integration_assert(
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
    function sanitize_text_field($value) {
        return trim(strip_tags((string) $value));
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr($value, $start, $length = null) {
        return $length === null
            ? substr((string) $value, $start)
            : substr((string) $value, $start, $length);
    }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($value) { return strtolower((string) $value); }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($value) { return json_encode($value); }
}
if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete($key, $group = '') { return true; }
}

$GLOBALS['cadence_integration_user_meta'] = [];

function get_user_meta($user_id, $key, $single = false) {
    $user_id = intval($user_id);
    return $GLOBALS['cadence_integration_user_meta'][$user_id][$key] ?? '';
}

function add_user_meta($user_id, $key, $value, $unique = false) {
    $user_id = intval($user_id);
    if (
        $unique
        && array_key_exists(
            $key,
            $GLOBALS['cadence_integration_user_meta'][$user_id] ?? []
        )
    ) {
        return false;
    }
    $GLOBALS['cadence_integration_user_meta'][$user_id][$key] = $value;
    return true;
}

function update_user_meta($user_id, $key, $value) {
    $GLOBALS['cadence_integration_user_meta'][intval($user_id)][$key] = $value;
    return true;
}

function aimee_media_decisions_table() { return 'aimee_media_decisions'; }

$GLOBALS['cadence_integration_store_result'] = 'stored-media-decision';
$GLOBALS['cadence_integration_stored_decision'] = null;

function aimee_media_decision_store($user_id, $decision, $request_id = '') {
    $GLOBALS['cadence_integration_stored_decision'] = $decision;
    return (string) $GLOBALS['cadence_integration_store_result'];
}

function aimee_media_decision_reason_text($reason_code) {
    return (string) $reason_code;
}

function aimee_media_decision_rating_rank($rating) {
    $ranks = [
        'safe' => 0,
        'flirty' => 1,
        'suggestive' => 2,
        'erotic' => 3,
        'explicit' => 4,
    ];
    return $ranks[(string) $rating] ?? -1;
}

/** Minimal prepared-query fake for cadence option claims and decision lookup. */
class AimeeCadenceIntegrationWpdb {
    public $options = 'wp_options';
    public $option_rows = [];
    public $decision_rows = [];
    public $fail_next_update = false;

    public function prepare($query, ...$args) {
        return ['query' => (string) $query, 'args' => $args];
    }

    public function query($prepared) {
        if (!is_array($prepared)) return false;
        $query = (string) ($prepared['query'] ?? '');
        $args = (array) ($prepared['args'] ?? []);

        if (strpos($query, 'INSERT IGNORE INTO') !== false) {
            $name = (string) ($args[0] ?? '');
            if ($name === '' || array_key_exists($name, $this->option_rows)) {
                return 0;
            }
            $this->option_rows[$name] = (string) ($args[1] ?? '');
            return 1;
        }

        if (strpos($query, 'UPDATE') !== false) {
            if ($this->fail_next_update) {
                $this->fail_next_update = false;
                return false;
            }
            $replacement = (string) ($args[0] ?? '');
            $name = (string) ($args[1] ?? '');
            $expected = (string) ($args[2] ?? '');
            if (
                !array_key_exists($name, $this->option_rows)
                || !hash_equals($this->option_rows[$name], $expected)
            ) {
                return 0;
            }
            $this->option_rows[$name] = $replacement;
            return 1;
        }

        if (strpos($query, 'DELETE FROM') !== false) {
            $name = (string) ($args[0] ?? '');
            $expected = (string) ($args[1] ?? '');
            if (
                !array_key_exists($name, $this->option_rows)
                || !hash_equals($this->option_rows[$name], $expected)
            ) {
                return 0;
            }
            unset($this->option_rows[$name]);
            return 1;
        }

        return false;
    }

    public function get_var($prepared) {
        if (!is_array($prepared)) return null;
        $name = (string) (($prepared['args'] ?? [])[0] ?? '');
        return $this->option_rows[$name] ?? null;
    }

    public function esc_like($value) {
        // Production escapes SQL wildcards. The in-memory fake compares the
        // prepared prefix directly and therefore needs no SQL escaping.
        return (string) $value;
    }

    public function get_results($prepared, $format = null) {
        if (!is_array($prepared)) return [];
        $query = (string) ($prepared['query'] ?? '');
        $args = (array) ($prepared['args'] ?? []);
        if (strpos($query, 'WHERE option_name LIKE') === false) return [];

        $pattern = (string) ($args[0] ?? '');
        $prefix = substr($pattern, -1) === '%'
            ? substr($pattern, 0, -1)
            : $pattern;
        $rows = [];
        foreach ($this->option_rows as $name => $value) {
            if (strpos((string) $name, $prefix) !== 0) continue;
            $rows[] = ['option_value' => (string) $value];
        }
        return $rows;
    }

    public function get_row($prepared, $format = null) {
        if (!is_array($prepared)) return null;
        $args = (array) ($prepared['args'] ?? []);
        // wpdb::prepare accepts either variadic arguments or one argument
        // array; production provenance lookup uses the latter form.
        if (count($args) === 1 && is_array($args[0])) {
            $args = array_values($args[0]);
        }
        $decision_id = (string) ($args[0] ?? '');
        $row = $this->decision_rows[$decision_id] ?? null;
        if (!is_array($row)) return null;
        if (isset($args[1]) && intval($row['user_id'] ?? 0) !== intval($args[1])) {
            return null;
        }
        return $row;
    }
}

/** Extract one named top-level function without loading the WordPress hooks. */
function cadence_integration_extract_function($source, $name) {
    $tokens = token_get_all($source);
    $count = count($tokens);
    for ($index = 0; $index < $count; $index++) {
        if (!is_array($tokens[$index]) || $tokens[$index][0] !== T_FUNCTION) {
            continue;
        }
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
$delivery_source = file_get_contents(dirname(__DIR__) . '/includes/media-delivery.php');
if ($engine_source === false || $delivery_source === false) {
    fwrite(STDERR, "Unable to read production media sources.\n");
    exit(2);
}

require dirname(__DIR__) . '/includes/media-cadence.php';

foreach ([
    'aimee_media_cadence_attempt_meta_key',
    'aimee_media_cadence_returned_meta_key',
    'aimee_media_cadence_considered_meta_key',
    'aimee_media_relevance_considered_meta_key',
    'aimee_media_relevance_considered_map',
    'aimee_mark_media_relevance_considered',
    'aimee_media_relevance_claim_ttl_seconds',
    'aimee_media_relevance_hold_seconds',
    'aimee_media_relevance_claim_option_name',
    'aimee_claim_media_relevance_keys',
    'aimee_commit_media_relevance_claims',
    'aimee_release_media_relevance_claims',
    'aimee_media_cadence_anchor_meta_key',
    'aimee_media_cadence_claim_ttl_seconds',
    'aimee_media_cadence_claim_option_name',
    'aimee_claim_media_cadence_opportunity',
    'aimee_release_media_cadence_claim',
    'aimee_media_cadence_anchor_timestamp',
    'aimee_mark_media_cadence_attempt',
    'aimee_mark_media_cadence_considered',
    'aimee_last_media_cadence_returned_timestamp',
    'aimee_media_decision_is_discretionary_opportunity',
    'aimee_mark_media_cadence_returned_for_delivery',
    'aimee_last_media_cadence_considered_timestamp',
    'aimee_persist_turn_media_decision',
] as $function_name) {
    eval(cadence_integration_extract_function($engine_source, $function_name));
}

$GLOBALS['wpdb'] = new AimeeCadenceIntegrationWpdb();
$wpdb = $GLOBALS['wpdb'];

// Unknown timestamps and a just-created eligibility anchor cannot make a new
// account immediately due.
$now = 2000000000;
cadence_integration_assert(
    !aimee_media_cadence_due_from_timestamps(0, 0, $now, 0),
    'zero delivery and zero eligibility timestamps fail cadence closed'
);

$user_id = 41;
cadence_integration_same(
    0,
    aimee_media_cadence_anchor_timestamp($user_id, 1),
    'one meaningful interaction does not create a cadence anchor'
);
$anchor = aimee_media_cadence_anchor_timestamp($user_id, 2);
cadence_integration_assert($anchor > 0, 'second meaningful interaction creates a real cadence anchor');
cadence_integration_same(
    $anchor,
    aimee_media_cadence_anchor_timestamp($user_id, 9),
    'cadence anchor remains stable as conversation history grows'
);

$planner_input = [
    'user_text' => 'Tell me what has been making you smile today.',
    'recent_history' => '',
    'last_media_at' => 0,
    'last_considered_at' => 0,
    'first_eligible_at' => $anchor,
    'meaningful_interaction_count' => 2,
    'active_exchange' => aimee_media_cadence_turn_is_suitable(
        'Tell me what has been making you smile today.',
        'general'
    ),
    'respectful' => true,
];
$planner_input['now'] = $anchor + (47 * 60 * 60);
cadence_integration_same(
    'none',
    aimee_media_opportunity_plan($planner_input, [])['kind'],
    'suitable live turn remains below cadence before forty-eight hours'
);
$planner_input['now'] = $anchor + (49 * 60 * 60);
cadence_integration_same(
    'cadence_due',
    aimee_media_opportunity_plan($planner_input, [])['kind'],
    'first suitable live turn after forty-eight hours becomes cadence due'
);
$planner_input['active_exchange'] = aimee_media_cadence_turn_is_suitable(
    'Goodnight, speak tomorrow x',
    'general'
);
cadence_integration_same(
    'none',
    aimee_media_opportunity_plan($planner_input, [])['kind'],
    'sign-off cannot consume the first suitable cadence opportunity'
);

// The live user-meta adapter records only named catalogue keys, preserves
// independent keys, and feeds the pure planner's exact-key hold.
$relevance_time = 2050000000;
cadence_integration_assert(
    aimee_mark_media_relevance_considered(
        $user_id,
        ['pub_day', 'football_night', '', 'not a valid key!'],
        $relevance_time
    ),
    'live relevance adapter persists considered catalogue keys'
);
$relevance_map = aimee_media_relevance_considered_map($user_id);
cadence_integration_same(
    $relevance_time,
    $relevance_map['pub_day'] ?? 0,
    'live relevance map retains the pub key timestamp'
);
cadence_integration_same(
    $relevance_time,
    $relevance_map['football_night'] ?? 0,
    'live relevance map retains an independent football-key timestamp'
);
cadence_integration_assert(
    !isset($relevance_map[''])
        && isset($relevance_map['notavalidkey']),
    'live relevance map drops empty keys and stores normalized non-empty keys'
);

$relevance_catalogue = [
    'pub_day' => [
        'description' => 'A relaxed photograph of Aimee at a pub.',
        'tags' => ['pub', 'casual'],
        'relevance_terms' => ['pub', 'going for drinks'],
    ],
];
$live_relevance_input = [
    'user_text' => 'I am at the pub and it made me think of you.',
    'recent_history' => '',
    'now' => $relevance_time + ((12 * 60 * 60) - 1),
    'last_media_at' => $relevance_time,
    'last_considered_at' => 0,
    'first_eligible_at' => $relevance_time - (30 * 24 * 60 * 60),
    'meaningful_interaction_count' => 3,
    'active_exchange' => true,
    'respectful' => true,
    'relevance_considered_at' => $relevance_map,
];
$live_held_plan = aimee_media_opportunity_plan(
    $live_relevance_input,
    $relevance_catalogue
);
cadence_integration_same(
    'none',
    $live_held_plan['kind'],
    'live stored exact-key consideration suppresses relevance for twelve hours'
);
$live_relevance_input['now'] = $relevance_time + (12 * 60 * 60);
$live_expired_plan = aimee_media_opportunity_plan(
    $live_relevance_input,
    $relevance_catalogue
);
cadence_integration_same(
    'conversation_relevance',
    $live_expired_plan['kind'],
    'live stored exact key becomes relevant again at hold expiry'
);
cadence_integration_same(
    ['pub_day'],
    $live_expired_plan['relevant_keys'],
    'hold expiry restores the exact catalogue key'
);

// The wp_options unique key must allow one owner, reject a concurrent owner,
// permit idempotent replay, and use exact-value CAS for expiry/release.
$claim_time = 2100000000;
cadence_integration_assert(
    aimee_claim_media_cadence_opportunity(51, 'request-a', $claim_time),
    'first cadence envelope atomically acquires the user claim'
);
cadence_integration_assert(
    !aimee_claim_media_cadence_opportunity(51, 'request-b', $claim_time + 1),
    'concurrent cadence envelope loses contention and fails closed'
);
cadence_integration_assert(
    aimee_claim_media_cadence_opportunity(51, 'request-a', $claim_time + 2),
    'same request may idempotently replay its cadence claim'
);
$expired_time = $claim_time + aimee_media_cadence_claim_ttl_seconds() + 1;
cadence_integration_assert(
    aimee_claim_media_cadence_opportunity(51, 'request-b', $expired_time),
    'new request may replace an expired claim with compare-and-swap'
);
cadence_integration_assert(
    !aimee_release_media_cadence_claim(51, 'request-a'),
    'stale owner cannot release a newer cadence claim'
);
cadence_integration_assert(
    aimee_release_media_cadence_claim(51, 'request-b'),
    'current owner can release its exact cadence claim'
);

// Conversation relevance uses one atomic claim per user and catalogue key.
// Partial contention must preserve uncontested keys instead of turning a
// simultaneous topic match into either duplicate sends or a global lock.
$relevance_claim_time = 2200000000;
$first_relevance_keys = aimee_claim_media_relevance_keys(
    71,
    ['pub_day', 'football_night', 'pub_day', ''],
    'relevance-a',
    $relevance_claim_time
);
sort($first_relevance_keys);
cadence_integration_same(
    ['football_night', 'pub_day'],
    $first_relevance_keys,
    'first relevance request atomically acquires each normalized exact key once'
);
$partial_relevance_keys = aimee_claim_media_relevance_keys(
    71,
    ['pub_day', 'black_lingerie'],
    'relevance-b',
    $relevance_claim_time + 1
);
cadence_integration_same(
    ['black_lingerie'],
    $partial_relevance_keys,
    'partial relevance contention retains only the independently acquired key'
);
$idempotent_relevance_keys = aimee_claim_media_relevance_keys(
    71,
    ['pub_day', 'football_night'],
    'relevance-a',
    $relevance_claim_time + 2
);
sort($idempotent_relevance_keys);
cadence_integration_same(
    ['football_night', 'pub_day'],
    $idempotent_relevance_keys,
    'same relevance request may idempotently replay its owned keys'
);
cadence_integration_same(
    ['pub_day'],
    aimee_claim_media_relevance_keys(
        72,
        ['pub_day'],
        'other-user',
        $relevance_claim_time + 2
    ),
    'same catalogue key remains independent for a different user'
);

$relevance_ttl = aimee_media_relevance_claim_ttl_seconds();
cadence_integration_same(
    [],
    aimee_claim_media_relevance_keys(
        71,
        ['pub_day'],
        'relevance-b',
        $relevance_claim_time + $relevance_ttl - 1
    ),
    'different request cannot take an in-flight key before the fifteen-minute TTL'
);
cadence_integration_same(
    ['pub_day'],
    aimee_claim_media_relevance_keys(
        71,
        ['pub_day'],
        'relevance-b',
        $relevance_claim_time + $relevance_ttl
    ),
    'expired relevance claim is replaced with exact-value compare-and-swap'
);
cadence_integration_same(
    [],
    aimee_release_media_relevance_claims(
        71,
        ['pub_day'],
        'relevance-a'
    ),
    'stale relevance owner cannot release a newer claim'
);
cadence_integration_same(
    ['pub_day'],
    aimee_claim_media_relevance_keys(
        71,
        ['pub_day'],
        'relevance-b',
        $relevance_claim_time + $relevance_ttl + 1
    ),
    'stale release attempt leaves the current relevance owner intact'
);
cadence_integration_same(
    ['pub_day'],
    aimee_release_media_relevance_claims(
        71,
        ['pub_day'],
        'relevance-b'
    ),
    'current owner can release an uncommitted relevance claim'
);
cadence_integration_same(
    ['pub_day'],
    aimee_claim_media_relevance_keys(
        71,
        ['pub_day'],
        'relevance-c',
        $relevance_claim_time + $relevance_ttl + 2
    ),
    'released uncommitted key is immediately claimable after persistence failure'
);

// A successful provider consideration converts the exact owned claim into a
// durable per-key hold. It is not released, and it becomes available again at
// the exact twelve-hour boundary.
$hold_user = 73;
$hold_start = 2300000000;
cadence_integration_same(
    ['pub_day'],
    aimee_claim_media_relevance_keys(
        $hold_user,
        ['pub_day'],
        'hold-owner',
        $hold_start
    ),
    'relevance key is acquired before provider exposure'
);
cadence_integration_same(
    ['pub_day'],
    aimee_commit_media_relevance_claims(
        $hold_user,
        ['pub_day'],
        'hold-owner',
        $hold_start + 5
    ),
    'owned relevance claim atomically commits to a per-key hold'
);
// Make the aggregate compatibility row stale: the option hold must remain the
// authoritative timestamp merged into the planner map.
$GLOBALS['cadence_integration_user_meta'][$hold_user][
    aimee_media_relevance_considered_meta_key()
] = ['pub_day' => $hold_start + 1];
$committed_map = aimee_media_relevance_considered_map($hold_user);
cadence_integration_same(
    $hold_start + 5,
    $committed_map['pub_day'] ?? 0,
    'planner map prefers the newer atomic per-key hold over stale aggregate meta'
);
cadence_integration_same(
    [],
    aimee_release_media_relevance_claims(
        $hold_user,
        ['pub_day'],
        'hold-owner'
    ),
    'failure-only release cannot delete a committed consideration hold'
);
cadence_integration_same(
    [],
    aimee_claim_media_relevance_keys(
        $hold_user,
        ['pub_day'],
        'hold-next',
        $hold_start + 5 + aimee_media_relevance_hold_seconds() - 1
    ),
    'committed exact key remains unavailable through the final second of its hold'
);
cadence_integration_same(
    ['pub_day'],
    aimee_claim_media_relevance_keys(
        $hold_user,
        ['pub_day'],
        'hold-next',
        $hold_start + 5 + aimee_media_relevance_hold_seconds()
    ),
    'committed exact key is claimable at the twelve-hour expiry boundary'
);

// A failed atomic commit must not release the in-flight claim. The short claim
// TTL provides bounded recovery without exposing the key concurrently.
$failed_commit_user = 74;
$failed_commit_at = 2400000000;
cadence_integration_same(
    ['football_night'],
    aimee_claim_media_relevance_keys(
        $failed_commit_user,
        ['football_night'],
        'commit-owner',
        $failed_commit_at
    ),
    'failed-commit fixture acquires its exact key'
);
$wpdb->fail_next_update = true;
cadence_integration_same(
    [],
    aimee_commit_media_relevance_claims(
        $failed_commit_user,
        ['football_night'],
        'commit-owner',
        $failed_commit_at + 5
    ),
    'failed exact-value commit reports no committed key'
);
cadence_integration_same(
    [],
    aimee_claim_media_relevance_keys(
        $failed_commit_user,
        ['football_night'],
        'commit-contender',
        $failed_commit_at + 6
    ),
    'failed commit leaves its active claim closed to a concurrent request'
);
cadence_integration_same(
    ['football_night'],
    aimee_claim_media_relevance_keys(
        $failed_commit_user,
        ['football_night'],
        'commit-contender',
        $failed_commit_at + $relevance_ttl
    ),
    'claim left by a failed commit recovers at the fifteen-minute TTL'
);

// Separate-key commits cannot overwrite each other through legacy aggregate
// user meta because each authoritative hold lives in its own option row.
$parallel_hold_user = 75;
cadence_integration_same(
    ['pub_day'],
    aimee_claim_media_relevance_keys(
        $parallel_hold_user,
        ['pub_day'],
        'parallel-pub',
        $hold_start
    ),
    'first parallel relevance key acquires independently'
);
cadence_integration_same(
    ['football_night'],
    aimee_claim_media_relevance_keys(
        $parallel_hold_user,
        ['football_night'],
        'parallel-football',
        $hold_start
    ),
    'second parallel relevance key acquires independently'
);
cadence_integration_same(
    ['pub_day'],
    aimee_commit_media_relevance_claims(
        $parallel_hold_user,
        ['pub_day'],
        'parallel-pub',
        $hold_start + 10
    ),
    'first parallel relevance key commits independently'
);
cadence_integration_same(
    ['football_night'],
    aimee_commit_media_relevance_claims(
        $parallel_hold_user,
        ['football_night'],
        'parallel-football',
        $hold_start + 11
    ),
    'second parallel relevance key commits independently'
);
$parallel_map = aimee_media_relevance_considered_map($parallel_hold_user);
cadence_integration_assert(
    ($parallel_map['pub_day'] ?? 0) === $hold_start + 10
        && ($parallel_map['football_night'] ?? 0) === $hold_start + 11,
    'atomic per-key holds preserve simultaneous different-key consideration truth'
);

// The live persistence adapter must narrow the decision envelope before it is
// stored and subsequently exposed to a provider. Contention on one key cannot
// leave that key, its metadata or its former maximum rating in the envelope.
$persist_user = 76;
cadence_integration_same(
    ['football_night'],
    aimee_claim_media_relevance_keys(
        $persist_user,
        ['football_night'],
        'persist-contender',
        time()
    ),
    'persistence filter fixture reserves the higher-rated key'
);
$partial_persist_decision = [
    'media_opportunity' => true,
    'proactive_allowed' => true,
    'opportunity_kind' => 'conversation_relevance',
    'opportunity_priority' => 'high',
    'relevant_keys' => ['pub_day', 'football_night'],
    'eligible_keys' => ['pub_day', 'football_night'],
    'eligible_items' => [
        'pub_day' => ['content_rating' => 'safe'],
        'football_night' => ['content_rating' => 'suggestive'],
    ],
    'maximum_rating' => 'suggestive',
    'policy_snapshot' => [],
];
$GLOBALS['cadence_integration_store_result'] = 'partial-persisted';
cadence_integration_same(
    'partial-persisted',
    aimee_persist_turn_media_decision(
        $persist_user,
        $partial_persist_decision,
        'persist-owner'
    ),
    'partially contended relevance envelope still persists its acquired key'
);
cadence_integration_same(
    ['pub_day'],
    $partial_persist_decision['eligible_keys'],
    'persistence removes a contended key before provider exposure'
);
cadence_integration_same(
    ['pub_day'],
    array_keys($partial_persist_decision['eligible_items']),
    'persistence removes contended item metadata before provider exposure'
);
cadence_integration_same(
    ['pub_day'],
    $partial_persist_decision['relevant_keys'],
    'persistence narrows relevance evidence to the exact acquired key'
);
cadence_integration_same(
    'safe',
    $partial_persist_decision['maximum_rating'],
    'maximum rating is recomputed from acquired item metadata'
);
cadence_integration_same(
    $partial_persist_decision['eligible_keys'],
    $GLOBALS['cadence_integration_stored_decision']['eligible_keys'] ?? null,
    'stored decision already contains the contention-filtered envelope'
);
cadence_integration_same(
    [],
    aimee_claim_media_relevance_keys(
        $persist_user,
        ['pub_day'],
        'persist-racer',
        time()
    ),
    'successfully persisted key remains claimed while its provider runs'
);

$fully_contended_user = 77;
cadence_integration_same(
    ['pub_day'],
    aimee_claim_media_relevance_keys(
        $fully_contended_user,
        ['pub_day'],
        'all-contender',
        time()
    ),
    'all-contention fixture reserves its only key'
);
$fully_contended_decision = [
    'media_opportunity' => true,
    'proactive_allowed' => true,
    'opportunity_kind' => 'conversation_relevance',
    'opportunity_priority' => 'high',
    'relevant_keys' => ['pub_day'],
    'eligible_keys' => ['pub_day'],
    'eligible_items' => ['pub_day' => ['content_rating' => 'safe']],
    'maximum_rating' => 'safe',
    'policy_snapshot' => [],
];
$GLOBALS['cadence_integration_store_result'] = 'contended-persisted';
aimee_persist_turn_media_decision(
    $fully_contended_user,
    $fully_contended_decision,
    'all-loser'
);
cadence_integration_assert(
    empty($fully_contended_decision['media_opportunity'])
        && empty($fully_contended_decision['proactive_allowed'])
        && $fully_contended_decision['eligible_keys'] === []
        && $fully_contended_decision['eligible_items'] === []
        && $fully_contended_decision['maximum_rating'] === 'none'
        && $fully_contended_decision['opportunity_kind'] === 'none'
        && $fully_contended_decision['reason_code'] === 'relevance_claim_active',
    'all-key contention strips every provider-visible opportunity field closed'
);

// A database failure after acquiring relevance claims must release only those
// uncommitted claims so a later request can retry immediately.
$failed_persist_user = 78;
$failed_persist_decision = [
    'media_opportunity' => true,
    'proactive_allowed' => true,
    'opportunity_kind' => 'conversation_relevance',
    'opportunity_priority' => 'high',
    'relevant_keys' => ['pub_day'],
    'eligible_keys' => ['pub_day'],
    'eligible_items' => ['pub_day' => ['content_rating' => 'safe']],
    'maximum_rating' => 'safe',
    'policy_snapshot' => [],
];
$GLOBALS['cadence_integration_store_result'] = '';
cadence_integration_same(
    '',
    aimee_persist_turn_media_decision(
        $failed_persist_user,
        $failed_persist_decision,
        'failed-persist-owner'
    ),
    'decision persistence failure returns no auditable decision id'
);
cadence_integration_assert(
    empty($failed_persist_decision['media_opportunity'])
        && $failed_persist_decision['eligible_keys'] === []
        && $failed_persist_decision['reason_code'] === 'decision_persistence_failed',
    'persistence failure leaves no provider-selectable relevance opportunity'
);
cadence_integration_same(
    ['pub_day'],
    aimee_claim_media_relevance_keys(
        $failed_persist_user,
        ['pub_day'],
        'failed-persist-retry',
        time()
    ),
    'persistence failure releases its exact uncommitted relevance key'
);
$GLOBALS['cadence_integration_store_result'] = 'stored-media-decision';

// Only successfully returned proactive choices satisfy the discretionary
// rhythm. Direct requests, resends and repair deliveries remain outside it.
$wpdb->decision_rows = [
    'direct-request' => [
        'user_id' => 61,
        'source' => 'direct',
        'reason_code' => 'eligible_direct_request',
    ],
    'direct-spoofed-reason' => [
        'user_id' => 61,
        'source' => 'direct',
        'reason_code' => 'eligible_cadence_due',
    ],
    'conversation-relevance' => [
        'user_id' => 61,
        'source' => 'proactive',
        'reason_code' => 'eligible_conversation_relevance',
    ],
    'cadence-due' => [
        'user_id' => 61,
        'source' => 'proactive',
        'reason_code' => 'eligible_cadence_due',
    ],
    'relationship-context' => [
        'user_id' => 61,
        'source' => 'proactive',
        'reason_code' => 'eligible_indirect_opportunity',
    ],
    'respectful-restraint' => [
        'user_id' => 61,
        'source' => 'proactive',
        'reason_code' => 'eligible_respectful_restraint',
    ],
    'intimate-context' => [
        'user_id' => 61,
        'source' => 'proactive',
        'reason_code' => 'eligible_intimate_route_consideration',
    ],
    'promise-repair' => [
        'user_id' => 61,
        'source' => 'proactive',
        'reason_code' => 'eligible_continuity_promise',
    ],
];

cadence_integration_assert(
    !aimee_mark_media_cadence_returned_for_delivery(
        'direct-request',
        61,
        $now + 10
    ),
    'direct requested image does not satisfy discretionary cadence'
);
cadence_integration_assert(
    !aimee_mark_media_cadence_returned_for_delivery(
        'direct-spoofed-reason',
        61,
        $now + 11
    ),
    'direct source cannot satisfy cadence even with a proactive reason code'
);
cadence_integration_same(
    0,
    aimee_last_media_cadence_returned_timestamp(61),
    'requested image leaves the discretionary-return timestamp untouched'
);

foreach ([
    'conversation-relevance',
    'cadence-due',
    'relationship-context',
    'respectful-restraint',
    'intimate-context',
] as $offset => $decision_id) {
    $returned_at = $now + 100 + $offset;
    cadence_integration_assert(
        aimee_mark_media_cadence_returned_for_delivery(
            $decision_id,
            61,
            $returned_at
        ),
        $decision_id . ' is a discretionary proactive return'
    );
    cadence_integration_same(
        $returned_at,
        aimee_last_media_cadence_returned_timestamp(61),
        $decision_id . ' advances the discretionary-return timestamp'
    );
}

$before_repair = aimee_last_media_cadence_returned_timestamp(61);
cadence_integration_assert(
    !aimee_mark_media_cadence_returned_for_delivery(
        'promise-repair',
        61,
        $before_repair + 100
    ),
    'promise or delivery repair does not manufacture a new discretionary send'
);
cadence_integration_same(
    $before_repair,
    aimee_last_media_cadence_returned_timestamp(61),
    'repair delivery leaves the discretionary-return timestamp untouched'
);

// Delivery transition is the sole caller: it records cadence only on the two
// successful API-return facts, never selection, message creation or failure.
$delivery_transition = cadence_integration_extract_function(
    $delivery_source,
    'aimee_media_delivery_transition'
);
$marker_position = strpos(
    $delivery_transition,
    'aimee_mark_media_cadence_returned_for_delivery'
);
$marker_guard = $marker_position === false
    ? ''
    : substr($delivery_transition, max(0, $marker_position - 650), 900);
cadence_integration_assert(
    $marker_position !== false
        && strpos($marker_guard, "'returned_by_direct_api'") !== false
        && strpos($marker_guard, "'returned_by_history_api'") !== false,
    'direct and history API return facts both update discretionary cadence'
);
cadence_integration_assert(
    strpos($marker_guard, '$affected > 0') !== false
        && strpos($marker_guard, '!$was_returned') !== false,
    'idempotent or rejected return transitions cannot refresh cadence twice'
);
cadence_integration_assert(
    preg_match_all(
        '/(?<![\'\"])aimee_mark_media_cadence_returned_for_delivery\s*\(/',
        $delivery_transition,
        $unused_marker_matches
    ) === 1,
    'failed, selected and message-created transitions have no cadence marker path'
);

$persist_source = cadence_integration_extract_function(
    $engine_source,
    'aimee_persist_turn_media_decision'
);
cadence_integration_assert(
    strpos($persist_source, 'aimee_claim_media_cadence_opportunity') !== false
        && strpos($persist_source, "=== 'cadence_due'") !== false
        && strpos($persist_source, "'cadence_claim_active'") !== false,
    'live persistence claims cadence before exposing a contested envelope'
);
cadence_integration_assert(
    strpos($persist_source, '$cadence_claim_acquired') !== false
        && strpos($persist_source, 'aimee_release_media_cadence_claim')
            > strpos($persist_source, 'aimee_media_decision_store'),
    'decision persistence failure releases the exact cadence claim owner'
);
cadence_integration_assert(
    strpos($persist_source, "['media_opportunity'] = false") !== false
        && strpos($persist_source, "['eligible_keys'] = []") !== false
        && strpos($persist_source, "['opportunity_kind'] = 'none'") !== false,
    'lost cadence contention strips the opportunity and eligible keys fail closed'
);
cadence_integration_assert(
    strpos($persist_source, 'aimee_claim_media_relevance_keys') !== false
        && strpos($persist_source, '$claim_candidates = array_values(array_intersect') !== false
        && strpos($persist_source, "\$decision['eligible_keys'] = array_values(array_intersect") !== false
        && strpos($persist_source, "\$decision['eligible_items'] = array_intersect_key") !== false
        && strpos($persist_source, "\$decision['maximum_rating'] = \$maximum_rating") !== false
        && strpos($persist_source, 'aimee_claim_media_relevance_keys')
            < strpos($persist_source, 'aimee_media_decision_store'),
    'live persistence filters every provider-visible relevance field before store'
);
cadence_integration_assert(
    strpos($persist_source, "if (!\$relevance_claimed_keys)") !== false
        && strpos($persist_source, "'relevance_claim_active'") !== false
        && strpos($persist_source, 'aimee_release_media_relevance_claims')
            > strpos($persist_source, 'aimee_media_decision_store'),
    'live persistence fails all-key contention closed and releases claims only on store failure'
);

$handler_source = cadence_integration_extract_function(
    $engine_source,
    'handle_aimee_message'
);
$cadence_mark_position = strpos(
    $handler_source,
    '$cadence_considered_marked = aimee_mark_media_cadence_considered'
);
$cadence_release_position = strpos(
    $handler_source,
    'aimee_release_media_cadence_claim'
);
cadence_integration_assert(
    $cadence_mark_position !== false
        && $cadence_release_position > $cadence_mark_position
        && strpos(
            substr(
                $handler_source,
                $cadence_mark_position,
                $cadence_release_position - $cadence_mark_position
            ),
            '&& $cadence_considered_marked'
        ) !== false,
    'live handler releases cadence claim only after considered-marker success'
);
$relevance_commit_position = strpos(
    $handler_source,
    'aimee_commit_media_relevance_claims'
);
cadence_integration_assert(
    $relevance_commit_position
        > strpos($handler_source, 'aimee_media_decision_apply_model_choice')
        && strpos(
            substr(
                $handler_source,
                max(0, $relevance_commit_position - 700),
                1050
            ),
            'array_intersect'
        ) !== false
        && strpos($handler_source, 'aimee_release_media_relevance_claims') === false,
    'live handler commits exact exposed relevance keys and leaves failed commits claimed to TTL'
);

echo "\nMedia cadence integration regression: {$passes} passed, {$failures} failed\n";
exit($failures ? 1 : 0);
