<?php
/**
 * Standalone regressions for the operator-confirmed Ni relationship repair.
 *
 * Run with:
 *   node tests/run-php-wasm.mjs tests/ni-relationship-repair-1.8.9-regression.php
 */

$engine_path = dirname(__DIR__) . '/includes/engine.php';
$policy_path = dirname(__DIR__) . '/includes/relationship-policy.php';
$engine_source = file_get_contents($engine_path);

if (!is_string($engine_source) || !is_file($policy_path)) {
    fwrite(STDERR, "Unable to read Ni repair production sources.\n");
    exit(1);
}

function aimee_ni_test_extract_function($source, $name) {
    $tokens = token_get_all($source);
    $count = count($tokens);

    for ($index = 0; $index < $count; $index++) {
        if (!is_array($tokens[$index]) || $tokens[$index][0] !== T_FUNCTION) {
            continue;
        }

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

foreach (array(
    'aimee_relationship_clamp',
    'aimee_relationship_intimacy_score',
    'aimee_ni_bond_relationship_state_189',
    'aimee_ni_bond_inner_state_189',
    'aimee_ni_bond_state_is_restored_189',
    'aimee_ni_bond_repair_summary_189',
    'aimee_ni_bond_repair_is_complete_189',
    'aimee_repair_ni_bond_state_189',
) as $function_name) {
    eval(aimee_ni_test_extract_function($engine_source, $function_name));
}

require_once $policy_path;

$GLOBALS['aimee_ni_test_failures'] = 0;
$GLOBALS['aimee_ni_test_checks'] = 0;
$GLOBALS['aimee_ni_test_options'] = array();
$GLOBALS['aimee_ni_test_claims'] = array();
$GLOBALS['aimee_ni_test_releases'] = array();

function aimee_ni_test_assert($condition, $label) {
    $GLOBALS['aimee_ni_test_checks']++;
    if ($condition) {
        echo "PASS {$label}\n";
        return;
    }

    $GLOBALS['aimee_ni_test_failures']++;
    echo "FAIL {$label}\n";
}

if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return array_key_exists($name, $GLOBALS['aimee_ni_test_options'])
            ? $GLOBALS['aimee_ni_test_options'][$name]
            : $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null) {
        $GLOBALS['aimee_ni_test_options'][$name] = $value;
        return true;
    }
}
if (!function_exists('current_time')) {
    function current_time($type, $gmt = false) {
        return '2026-08-21 12:00:00';
    }
}
if (!function_exists('aimee_table')) {
    function aimee_table($name) {
        return (string) $name;
    }
}
if (!function_exists('aimee_relationship_dimensions_table')) {
    function aimee_relationship_dimensions_table() {
        return 'aimee_relationship_dimensions';
    }
}
if (!function_exists('aimee_relationship_dimensions_table_available')) {
    function aimee_relationship_dimensions_table_available() {
        return empty($GLOBALS['aimee_ni_test_dimensions_unavailable']);
    }
}
if (!function_exists('aimee_global_schema_claim_lock')) {
    function aimee_global_schema_claim_lock($name, $ttl = 0) {
        $GLOBALS['aimee_ni_test_claims'][] = array((string) $name, (int) $ttl);
        return !empty($GLOBALS['aimee_ni_test_lock_unavailable'])
            ? ''
            : 'ni-repair-lock';
    }
}
if (!function_exists('aimee_global_schema_release_lock')) {
    function aimee_global_schema_release_lock($claim) {
        $GLOBALS['aimee_ni_test_releases'][] = (string) $claim;
    }
}
if (!function_exists('aimee_load_relationship_state')) {
    function aimee_load_relationship_state($profile) {
        global $wpdb;
        return $wpdb->relationship;
    }
}
if (!function_exists('aimee_save_relationship_state')) {
    function aimee_save_relationship_state($user_id, $state) {
        global $wpdb;
        if (!empty($wpdb->relationship_save_failure)) return false;
        if ((int) $user_id !== 27) return false;

        $expected = (int) ($wpdb->relationship['state_version'] ?? 0) + 1;
        if ((int) ($state['state_version'] ?? -1) !== $expected) return false;

        $wpdb->relationship = $state;
        $wpdb->mutation_log[] = 'aimee_relationship_dimensions';
        return true;
    }
}
if (!function_exists('aimee_load_inner_state')) {
    function aimee_load_inner_state($user_id, $create = true) {
        global $wpdb;
        return $wpdb->inner;
    }
}
if (!function_exists('aimee_save_inner_state')) {
    function aimee_save_inner_state($user_id, array $state) {
        global $wpdb;
        if (!empty($wpdb->inner_save_failure)) return false;
        if ((int) $user_id !== 27) return false;

        $wpdb->inner = $state;
        $wpdb->mutation_log[] = 'aimee_inner_state';
        return true;
    }
}

class Aimee_Ni_Test_Wpdb {
    public $profile;
    public $relationship;
    public $inner;
    public $events;
    public $evidence_count = 1;
    public $start_failure = false;
    public $commit_failure = false;
    public $profile_update_failure = false;
    public $relationship_save_failure = false;
    public $inner_save_failure = false;
    public $event_update_failure = false;
    public $transaction_log = array();
    public $mutation_log = array();
    public $prepared_log = array();
    private $transaction_snapshot = null;

    public function __construct() {
        $this->profile = (object) array(
            'user_id' => 27,
            'first_name' => 'Ni',
            'age' => 47,
            'intimacy_score' => 37,
            'intimacy_stage' => 'flirty',
            'adult_assurance_status' => 'self_declared',
            'adult_verified_at' => null,
            'privacy_acknowledged_at' => null,
            'special_category_consent_at' => null,
            'special_category_consent_version' => null,
            'subscription_status' => 'active',
            'subscription_plan' => 'monthly',
            'billing_provider' => 'legacy_stripe',
            'trial_messages_used' => 17,
        );
        $this->relationship = array(
            'user_id' => 27,
            'trust' => 100,
            'affection' => 100,
            'chemistry' => 19,
            'safety' => 94,
            'reciprocity' => 84,
            'reliability' => 96,
            'frustration' => 10,
            'interaction_count' => 405,
            'meaningful_interaction_count' => 109,
            'session_count' => 39,
            'qualified_session_count' => 33,
            'last_qualified_session_number' => 39,
            'last_session_at' => '2026-08-21 04:18:13',
            'state_version' => 321,
            'last_message_fingerprint' => str_repeat('a', 64),
            'message_fingerprint_history' => array(str_repeat('b', 64)),
            'repeat_streak' => 0,
            'last_signal_signature' => str_repeat('c', 64),
            'signal_history' => array(array(
                'signals' => array('emotional_disclosure'),
                'context_fingerprint' => str_repeat('d', 64),
            )),
            'signal_repeat_streak' => 0,
            'last_interaction_at' => '2026-08-21 04:51:38',
        );
        $this->inner = array(
            'user_id' => 27,
            'valence' => -12,
            'energy' => 63,
            'social_appetite' => 44,
            'curiosity' => 67,
            'irritation' => 28,
            'vulnerability' => 39,
            'playfulness' => 31,
            'romantic_openness' => 15,
            'dominant_emotion' => 'guarded',
            'uncertainty_level' => 37,
            'unresolved_rupture' => 'Incorrect historical rupture marker.',
            'repair_status' => 'repairing',
            'low_effort_streak' => 3,
            'unanswered_bids' => 2,
            'last_user_message_at' => '2026-08-21 04:51:38',
            'next_proactive_at' => '2026-08-22 08:00:00',
            'proactive_cooldown_until' => '2026-08-21 20:00:00',
        );
        $this->events = array(
            array(
                'id' => 1,
                'user_id' => 27,
                'event_type' => 'relationship_rupture',
                'actor' => 'user',
                'summary' => 'False rupture one',
                'emotional_impact' => -20,
                'trust_impact' => -10,
                'unresolved' => 1,
                'resolved_at' => null,
                'occurred_at' => '2026-08-20 09:00:00',
            ),
            array(
                'id' => 2,
                'user_id' => 27,
                'event_type' => 'relationship_rupture',
                'actor' => 'user',
                'summary' => 'A settled historical rupture',
                'emotional_impact' => -10,
                'trust_impact' => -5,
                'unresolved' => 0,
                'resolved_at' => '2026-08-20 12:00:00',
                'occurred_at' => '2026-08-20 10:00:00',
            ),
            array(
                'id' => 3,
                'user_id' => 27,
                'event_type' => 'meaningful_disclosure',
                'actor' => 'user',
                'summary' => 'A genuine relationship event',
                'emotional_impact' => 4,
                'trust_impact' => 2,
                'unresolved' => 0,
                'resolved_at' => null,
                'occurred_at' => '2026-08-20 11:00:00',
            ),
            array(
                'id' => 4,
                'user_id' => 28,
                'event_type' => 'relationship_rupture',
                'actor' => 'user',
                'summary' => 'Another user event',
                'emotional_impact' => -3,
                'trust_impact' => -2,
                'unresolved' => 1,
                'resolved_at' => null,
                'occurred_at' => '2026-08-20 11:00:00',
            ),
            array(
                'id' => 5,
                'user_id' => 27,
                'event_type' => 'relationship_rupture',
                'actor' => 'user',
                'summary' => 'A genuine post-evidence rupture',
                'emotional_impact' => -3,
                'trust_impact' => -2,
                'unresolved' => 1,
                'resolved_at' => null,
                'occurred_at' => '2026-08-21 05:30:00',
            ),
        );
    }

    public function prepare($query) {
        $prepared = array(
            'query' => (string) $query,
            'args' => array_slice(func_get_args(), 1),
        );
        $this->prepared_log[] = $prepared;
        return $prepared;
    }

    private function prepared_query($prepared) {
        return is_array($prepared)
            ? (string) ($prepared['query'] ?? '')
            : (string) $prepared;
    }

    public function get_row($prepared) {
        $query = $this->prepared_query($prepared);
        if (strpos($query, 'aimee_user_profiles') !== false) {
            return $this->profile ? clone $this->profile : null;
        }
        if (strpos($query, 'aimee_relationship_dimensions') !== false) {
            return (object) array(
                'user_id' => 27,
                'state_version' => (int) ($this->relationship['state_version'] ?? 0),
            );
        }
        return null;
    }

    public function get_var($prepared) {
        $query = $this->prepared_query($prepared);
        if (strpos($query, 'aimee_relationship_decisions') !== false) {
            return $this->evidence_count;
        }
        if (
            strpos($query, 'aimee_relationship_events') !== false
            && strpos($query, "event_type = 'relationship_rupture'") !== false
        ) {
            $count = 0;
            foreach ($this->events as $event) {
                if (
                    (int) $event['user_id'] === 27
                    && $event['event_type'] === 'relationship_rupture'
                ) {
                    $count++;
                }
            }
            return $count;
        }
        return 0;
    }

    public function update($table, $data, $where, $formats = null, $where_formats = null) {
        if ((string) $table !== 'aimee_user_profiles') return false;
        if ($this->profile_update_failure) return false;
        if ((int) ($where['user_id'] ?? 0) !== 27 || !$this->profile) return false;

        foreach ((array) $data as $key => $value) $this->profile->$key = $value;
        $this->mutation_log[] = 'aimee_user_profiles';
        return 1;
    }

    public function query($prepared) {
        $query = $this->prepared_query($prepared);
        if ($query === 'START TRANSACTION') {
            $this->transaction_log[] = 'START TRANSACTION';
            if ($this->start_failure) return false;
            $this->transaction_snapshot = serialize(array(
                $this->profile,
                $this->relationship,
                $this->inner,
                $this->events,
                $this->mutation_log,
            ));
            return true;
        }
        if ($query === 'COMMIT') {
            $this->transaction_log[] = 'COMMIT';
            if ($this->commit_failure) return false;
            $this->transaction_snapshot = null;
            return true;
        }
        if ($query === 'ROLLBACK') {
            $this->transaction_log[] = 'ROLLBACK';
            if ($this->transaction_snapshot !== null) {
                $restored = unserialize($this->transaction_snapshot);
                $this->profile = $restored[0];
                $this->relationship = $restored[1];
                $this->inner = $restored[2];
                $this->events = $restored[3];
                $this->mutation_log = $restored[4];
            }
            $this->transaction_snapshot = null;
            return true;
        }
        if (
            strpos($query, 'UPDATE aimee_relationship_events') !== false
            && strpos($query, "event_type = 'relationship_rupture'") !== false
        ) {
            if ($this->event_update_failure) return false;
            $args = is_array($prepared) ? (array) ($prepared['args'] ?? array()) : array();
            $summary = (string) ($args[0] ?? '');
            $resolved_at = (string) ($args[1] ?? '');
            $target_user = (int) ($args[2] ?? 0);
            $cutoff = (string) ($args[3] ?? '');
            $changed = 0;
            foreach ($this->events as &$event) {
                if (
                    (int) $event['user_id'] !== $target_user
                    || $event['event_type'] !== 'relationship_rupture'
                    || (int) ($event['unresolved'] ?? 0) !== 1
                    || (string) ($event['occurred_at'] ?? '') > $cutoff
                ) {
                    continue;
                }
                $event['event_type'] = 'state_correction';
                $event['actor'] = 'system';
                $event['summary'] = $summary;
                $event['emotional_impact'] = 0;
                $event['trust_impact'] = 0;
                $event['unresolved'] = 0;
                $event['resolved_at'] = $resolved_at;
                $changed++;
            }
            unset($event);
            if ($changed > 0) $this->mutation_log[] = 'aimee_relationship_events';
            return $changed;
        }
        return 0;
    }
}

function aimee_ni_test_reset() {
    global $wpdb;
    $GLOBALS['aimee_ni_test_options'] = array();
    $GLOBALS['aimee_ni_test_claims'] = array();
    $GLOBALS['aimee_ni_test_releases'] = array();
    $GLOBALS['aimee_ni_test_dimensions_unavailable'] = false;
    $GLOBALS['aimee_ni_test_lock_unavailable'] = false;
    $wpdb = new Aimee_Ni_Test_Wpdb();
}

function aimee_ni_test_sensitive_profile_snapshot($profile) {
    $keys = array(
        'age',
        'adult_assurance_status',
        'adult_verified_at',
        'privacy_acknowledged_at',
        'special_category_consent_at',
        'special_category_consent_version',
        'subscription_status',
        'subscription_plan',
        'billing_provider',
        'trial_messages_used',
    );
    $snapshot = array();
    foreach ($keys as $key) $snapshot[$key] = $profile->$key ?? null;
    return $snapshot;
}

// Pure relationship transformation: maximum positive metrics and minimum harm
// while preserving the established production counters and durable histories.
$source_relationship = (new Aimee_Ni_Test_Wpdb())->relationship;
$relationship_after = aimee_ni_bond_relationship_state_189($source_relationship);
$max_fields = array('trust', 'affection', 'chemistry', 'safety', 'reciprocity', 'reliability');
foreach ($max_fields as $field) {
    aimee_ni_test_assert(
        (int) ($relationship_after[$field] ?? -1) === 100,
        "Ni relationship repair maximises {$field}"
    );
}
aimee_ni_test_assert(
    (int) $relationship_after['frustration'] === 0,
    'Ni relationship repair clears frustration'
);
aimee_ni_test_assert(
    (int) $relationship_after['state_version'] === 322,
    'Ni relationship repair advances the optimistic state version exactly once'
);

$preserved_relationship_fields = array(
    'interaction_count',
    'meaningful_interaction_count',
    'session_count',
    'qualified_session_count',
    'last_qualified_session_number',
    'last_session_at',
    'last_message_fingerprint',
    'message_fingerprint_history',
    'repeat_streak',
    'last_signal_signature',
    'signal_history',
    'signal_repeat_streak',
    'last_interaction_at',
);
foreach ($preserved_relationship_fields as $field) {
    aimee_ni_test_assert(
        $relationship_after[$field] === $source_relationship[$field],
        "Ni relationship repair preserves {$field}"
    );
}
aimee_ni_test_assert(
    aimee_relationship_intimacy_score($relationship_after) === 100,
    'maximum Ni dimensions deterministically calculate a score of 100'
);

$source_inner = (new Aimee_Ni_Test_Wpdb())->inner;
$inner_after = aimee_ni_bond_inner_state_189($source_inner);
aimee_ni_test_assert(
    $inner_after['unresolved_rupture'] === ''
        && $inner_after['repair_status'] === 'clear'
        && (int) $inner_after['irritation'] === 0,
    'Ni inner-state repair clears the false rupture and irritation'
);
aimee_ni_test_assert(
    (int) $inner_after['romantic_openness'] === 100
        && (int) $inner_after['low_effort_streak'] === 0
        && (int) $inner_after['unanswered_bids'] === 0,
    'Ni inner-state repair restores full romantic openness without inherited penalties'
);
foreach (array('energy', 'last_user_message_at', 'next_proactive_at', 'proactive_cooldown_until') as $field) {
    aimee_ni_test_assert(
        $inner_after[$field] === $source_inner[$field],
        "Ni inner-state repair preserves dynamic field {$field}"
    );
}
$restored_profile = (object) array(
    'user_id' => 27,
    'intimacy_score' => 100,
    'intimacy_stage' => 'bonded',
);
aimee_ni_test_assert(
    aimee_ni_bond_state_is_restored_189(
        $restored_profile,
        $relationship_after,
        $inner_after
    ),
    'restoration verifier accepts only the complete user-27 bonded state'
);
$wrong_user_profile = clone $restored_profile;
$wrong_user_profile->user_id = 28;
aimee_ni_test_assert(
    !aimee_ni_bond_state_is_restored_189(
        $wrong_user_profile,
        $relationship_after,
        $inner_after
    ),
    'restoration verifier rejects an adjacent user account'
);

// Maximum relationship state clears the rupture gate but cannot manufacture
// adult assurance, processing consent or current-turn consent.
$policy_state = array(
    'score' => 100,
    'chemistry' => 100,
    'trust' => 100,
    'safety' => 100,
    'frustration' => 0,
    'reciprocity' => 100,
    'reliability' => 100,
    'meaningful_interactions' => 109,
    'distinct_sessions' => 33,
);
$unconsented_route = aimee_relationship_policy_specialist_route_decision(
    $policy_state,
    array(
        'adult_account' => false,
        'adult_verified' => false,
        'special_category_consent' => false,
        'active_access' => true,
        'explicit_mutual_context' => true,
        'rupture_active' => false,
        'repair_status' => 'clear',
    )
);
aimee_ni_test_assert(
    empty($unconsented_route['eligible'])
        && in_array('adult_account_required', $unconsented_route['failed_gate_reasons'], true)
        && in_array('adult_verification_required', $unconsented_route['failed_gate_reasons'], true)
        && in_array('special_category_consent_required', $unconsented_route['failed_gate_reasons'], true),
    'maximum relationship state cannot bypass adult-assurance or consent gates'
);
aimee_ni_test_assert(
    !empty($unconsented_route['gates']['rupture_clear']['passed'])
        && !empty($unconsented_route['gates']['score']['passed'])
        && !empty($unconsented_route['gates']['chemistry']['passed']),
    'restored Ni state clears relationship gates without weakening independent gates'
);
$consented_route = aimee_relationship_policy_specialist_route_decision(
    $policy_state,
    array(
        'adult_account' => true,
        'adult_verified' => true,
        'special_category_consent' => true,
        'active_access' => true,
        'explicit_mutual_context' => true,
        'rupture_active' => false,
        'repair_status' => 'clear',
    )
);
aimee_ni_test_assert(
    !empty($consented_route['eligible'])
        && ($consented_route['route'] ?? '') === 'intimacy_specialist',
    'independently satisfied adult and consent gates remain functional after repair'
);

// Exact production evidence performs one atomic repair.
aimee_ni_test_reset();
$sensitive_before = aimee_ni_test_sensitive_profile_snapshot($wpdb->profile);
$relationship_before = $wpdb->relationship;
$inner_before = $wpdb->inner;
$settled_event_before = $wpdb->events[1];
$unrelated_event_before = $wpdb->events[2];
$other_user_event_before = $wpdb->events[3];
$post_evidence_event_before = $wpdb->events[4];
aimee_repair_ni_bond_state_189();
$repair_summary = get_option('aimee_global_ni_bond_repair_189', array());

aimee_ni_test_assert(
    ($repair_summary['status'] ?? '') === 'complete'
        && ($repair_summary['action'] ?? '') === 'operator_confirmed_bond_restored'
        && (int) ($repair_summary['user_id'] ?? 0) === 27
        && (int) ($repair_summary['score_after'] ?? 0) === 100
        && ($repair_summary['stage_after'] ?? '') === 'bonded',
    'exact Ni evidence writes an inspectable completed repair summary'
);
aimee_ni_test_assert(
    aimee_ni_bond_repair_is_complete_189(),
    'completed Ni repair marker passes its release-gate verifier'
);
aimee_ni_test_assert(
    (int) $wpdb->profile->intimacy_score === 100
        && $wpdb->profile->intimacy_stage === 'bonded'
        && aimee_relationship_intimacy_score($wpdb->relationship) === 100,
    'orchestrated repair commits profile and dimensions as 100/bonded'
);
aimee_ni_test_assert(
    (int) $wpdb->relationship['state_version']
        === (int) $relationship_before['state_version'] + 1,
    'orchestrated repair commits exactly one relationship-state version'
);
foreach ($preserved_relationship_fields as $field) {
    aimee_ni_test_assert(
        $wpdb->relationship[$field] === $relationship_before[$field],
        "orchestrated repair preserves production field {$field}"
    );
}
aimee_ni_test_assert(
    $wpdb->inner['unresolved_rupture'] === ''
        && $wpdb->inner['repair_status'] === 'clear'
        && (int) $wpdb->inner['romantic_openness'] === 100,
    'orchestrated repair clears the persisted rupture posture'
);
aimee_ni_test_assert(
    $wpdb->inner['energy'] === $inner_before['energy']
        && $wpdb->inner['next_proactive_at'] === $inner_before['next_proactive_at'],
    'orchestrated repair preserves dynamic inner-state timing and energy'
);
aimee_ni_test_assert(
    aimee_ni_test_sensitive_profile_snapshot($wpdb->profile) === $sensitive_before,
    'repair leaves adult assurance, consent, privacy, membership, billing and preview state unchanged'
);
aimee_ni_test_assert(
    $wpdb->events[0]['event_type'] === 'state_correction'
        && $wpdb->events[1]['event_type'] === 'relationship_rupture'
        && $wpdb->events[0]['actor'] === 'system'
        && (int) $wpdb->events[0]['unresolved'] === 0
        && (int) $wpdb->events[0]['emotional_impact'] === 0
        && (int) $wpdb->events[0]['trust_impact'] === 0,
    'only the unresolved pre-evidence Ni rupture is retained as a resolved correction row'
);
aimee_ni_test_assert(
    $wpdb->events[2] === $unrelated_event_before
        && $wpdb->events[1] === $settled_event_before
        && $wpdb->events[3] === $other_user_event_before
        && $wpdb->events[4] === $post_evidence_event_before,
    'repair leaves settled, genuine, post-evidence and other-user events untouched'
);
aimee_ni_test_assert(
    $wpdb->transaction_log === array('START TRANSACTION', 'COMMIT')
        && $GLOBALS['aimee_ni_test_releases'] === array('ni-repair-lock'),
    'successful repair commits transactionally and releases its bounded lock'
);
$exact_evidence_bound = false;
foreach ($wpdb->prepared_log as $prepared) {
    if (
        strpos((string) ($prepared['query'] ?? ''), 'aimee_relationship_decisions') !== false
        && strpos((string) ($prepared['query'] ?? ''), "policy_version = '2.2.0'") !== false
        && (array) ($prepared['args'] ?? array()) === array(
            27,
            '73c7a1bb-25dd-4a97-a136-75b6a51e6538',
            '2026-08-21 04:51:38',
        )
    ) {
        $exact_evidence_bound = true;
        break;
    }
}
aimee_ni_test_assert(
    $exact_evidence_bound,
    'orchestrator binds mutation to the exact reviewed user-27 decision evidence'
);

// The completion option makes ordinary re-entry a strict no-op.
$mutations_after_success = $wpdb->mutation_log;
$transactions_after_success = $wpdb->transaction_log;
aimee_repair_ni_bond_state_189();
aimee_ni_test_assert(
    $wpdb->mutation_log === $mutations_after_success
        && $wpdb->transaction_log === $transactions_after_success,
    'completed Ni repair is idempotent without reacquiring or mutating state'
);

// Losing only the option must verify the already-restored rows without another
// state-version bump or a second event rewrite.
unset($GLOBALS['aimee_ni_test_options']['aimee_global_ni_bond_repair_189']);
$version_before_recovery = (int) $wpdb->relationship['state_version'];
$mutations_before_recovery = $wpdb->mutation_log;
aimee_repair_ni_bond_state_189();
$recovered_summary = get_option('aimee_global_ni_bond_repair_189', array());
aimee_ni_test_assert(
    ($recovered_summary['action'] ?? '') === 'already_repaired_verified'
        && (int) $wpdb->relationship['state_version'] === $version_before_recovery
        && $wpdb->mutation_log === $mutations_before_recovery,
    'option-loss recovery verifies committed state without double-applying repair'
);

// Exact user and exact exported decision evidence are both mandatory.
aimee_ni_test_reset();
$wpdb->evidence_count = 0;
$before = serialize(array($wpdb->profile, $wpdb->relationship, $wpdb->inner, $wpdb->events));
aimee_repair_ni_bond_state_189();
aimee_ni_test_assert(
    serialize(array($wpdb->profile, $wpdb->relationship, $wpdb->inner, $wpdb->events)) === $before
        && get_option('aimee_global_ni_bond_repair_189', null) === null
        && $wpdb->transaction_log === array('START TRANSACTION', 'ROLLBACK'),
    'missing exact production decision evidence rolls back without mutation or completion'
);

aimee_ni_test_reset();
$wpdb->profile->user_id = 28;
$before = serialize(array($wpdb->profile, $wpdb->relationship, $wpdb->inner, $wpdb->events));
aimee_repair_ni_bond_state_189();
aimee_ni_test_assert(
    serialize(array($wpdb->profile, $wpdb->relationship, $wpdb->inner, $wpdb->events)) === $before
        && get_option('aimee_global_ni_bond_repair_189', null) === null
        && $wpdb->transaction_log === array('START TRANSACTION', 'ROLLBACK'),
    'an adjacent profile cannot inherit the user-27 site-specific repair'
);

// Failed optimistic persistence must roll back fully and remain retryable.
aimee_ni_test_reset();
$before = serialize(array($wpdb->profile, $wpdb->relationship, $wpdb->inner, $wpdb->events));
$wpdb->relationship_save_failure = true;
aimee_repair_ni_bond_state_189();
aimee_ni_test_assert(
    serialize(array($wpdb->profile, $wpdb->relationship, $wpdb->inner, $wpdb->events)) === $before
        && get_option('aimee_global_ni_bond_repair_189', null) === null
        && $wpdb->transaction_log === array('START TRANSACTION', 'ROLLBACK'),
    'relationship-state write failure rolls back and leaves the repair retryable'
);
$wpdb->relationship_save_failure = false;
aimee_repair_ni_bond_state_189();
aimee_ni_test_assert(
    aimee_ni_bond_repair_is_complete_189()
        && (int) $wpdb->relationship['state_version'] === 322,
    'Ni repair succeeds exactly once after relationship persistence recovers'
);

// A failed COMMIT is explicitly rolled back and can be retried from the exact
// original state rather than from a partially repaired in-memory image.
aimee_ni_test_reset();
$before = serialize(array($wpdb->profile, $wpdb->relationship, $wpdb->inner, $wpdb->events));
$wpdb->commit_failure = true;
aimee_repair_ni_bond_state_189();
aimee_ni_test_assert(
    serialize(array($wpdb->profile, $wpdb->relationship, $wpdb->inner, $wpdb->events)) === $before
        && get_option('aimee_global_ni_bond_repair_189', null) === null
        && $wpdb->transaction_log === array('START TRANSACTION', 'COMMIT', 'ROLLBACK'),
    'failed COMMIT restores every Ni table and leaves no false completion marker'
);
$wpdb->commit_failure = false;
aimee_repair_ni_bond_state_189();
aimee_ni_test_assert(
    aimee_ni_bond_repair_is_complete_189()
        && array_slice($wpdb->transaction_log, -2) === array('START TRANSACTION', 'COMMIT')
        && (int) $wpdb->relationship['state_version'] === 322,
    'fresh transaction retries successfully after COMMIT recovers'
);

$failures = (int) $GLOBALS['aimee_ni_test_failures'];
$checks = (int) $GLOBALS['aimee_ni_test_checks'];
if ($failures > 0) {
    echo "Ni relationship repair regression failures: {$failures}/{$checks}.\n";
    exit(1);
}

echo "PASS: {$checks} Ni relationship repair checks.\n";
exit(0);
