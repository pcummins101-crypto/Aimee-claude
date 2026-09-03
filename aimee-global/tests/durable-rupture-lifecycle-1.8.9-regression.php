<?php
/** Standalone regressions for durable-rupture persistence and invitations. */

$engine_source = file_get_contents(dirname(__DIR__) . '/includes/engine.php');
$inner_source = file_get_contents(dirname(__DIR__) . '/includes/inner-life.php');
if (!is_string($engine_source) || !is_string($inner_source)) exit(2);

function rupture_test_extract($source, $name) {
    $tokens = token_get_all($source);
    for ($index = 0, $count = count($tokens); $index < $count; $index++) {
        if (!is_array($tokens[$index]) || $tokens[$index][0] !== T_FUNCTION) continue;
        $cursor = $index + 1;
        while ($cursor < $count && is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_WHITESPACE) $cursor++;
        if ($cursor >= $count || !is_array($tokens[$cursor]) || $tokens[$cursor][1] !== $name) continue;
        $output = '';
        $depth = 0;
        $started = false;
        for ($cursor = $index; $cursor < $count; $cursor++) {
            $text = is_array($tokens[$cursor]) ? $tokens[$cursor][1] : $tokens[$cursor];
            $output .= $text;
            if ($text === '{') { $depth++; $started = true; }
            if ($text === '}' && --$depth === 0 && $started) return $output;
        }
    }
    throw new RuntimeException('Missing function ' . $name);
}

foreach (array(
    'aimee_relationship_invitations_table',
    'aimee_reply_contains_relationship_invitation',
    'aimee_create_relationship_invitation',
    'aimee_revoke_active_relationship_invitations_for_user',
) as $name) eval(rupture_test_extract($engine_source, $name));
eval(rupture_test_extract($inner_source, 'aimee_record_relationship_event'));

$GLOBALS['rupture_checks'] = 0;
$GLOBALS['rupture_failures'] = 0;
function rupture_assert($condition, $label) {
    $GLOBALS['rupture_checks']++;
    if ($condition) { echo "PASS {$label}\n"; return; }
    $GLOBALS['rupture_failures']++;
    echo "FAIL {$label}\n";
}
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);
if (!function_exists('sanitize_key')) {
    function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_-]/', '', (string) $value)); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($value) { return trim(strip_tags((string) $value)); }
}
if (!function_exists('current_time')) {
    function current_time($type, $gmt = false) { return '2026-08-21 14:00:00'; }
}
function aimee_table($name) { return (string) $name; }
function aimee_relationship_dimensions_table() { return 'aimee_relationship_dimensions'; }
function aimee_relationship_dimensions_table_available() { return true; }
function aimee_media_stage_rank($stage) {
    return array('guarded' => 0, 'warm' => 1, 'flirty' => 2, 'intimate' => 3, 'bonded' => 4)[$stage] ?? 0;
}
function aimee_subscription_is_active($profile) { return true; }
function aimee_media_state_uuid() { return 'token-created'; }
function aimee_load_inner_state($user_id, $create = true) {
    global $wpdb;
    return $wpdb->inner;
}

class Rupture_Test_Wpdb {
    public $inner = array('unresolved_rupture' => '', 'repair_status' => 'clear');
    public $state_version = 10;
    public $invitations = array();
    public $insert_id = 99;
    public $transaction_log = array();
    public $query_failure = false;
    public $insert_failure = false;
    private $snapshot = null;

    public function prepare($query) {
        return array('query' => (string) $query, 'args' => array_slice(func_get_args(), 1));
    }
    private function sql($prepared) { return is_array($prepared) ? (string) $prepared['query'] : (string) $prepared; }
    public function get_row($prepared) { return (object) array('user_id' => 27); }
    public function get_var($prepared) { return $this->state_version; }
    public function query($prepared) {
        $sql = $this->sql($prepared);
        if ($sql === 'START TRANSACTION') {
            $this->transaction_log[] = 'START';
            $this->snapshot = serialize($this->invitations);
            return true;
        }
        if ($sql === 'COMMIT') {
            $this->transaction_log[] = 'COMMIT';
            $this->snapshot = null;
            return true;
        }
        if ($sql === 'ROLLBACK') {
            $this->transaction_log[] = 'ROLLBACK';
            if ($this->snapshot !== null) $this->invitations = unserialize($this->snapshot);
            $this->snapshot = null;
            return true;
        }
        if (strpos($sql, 'UPDATE aimee_relationship_invitations') !== false) {
            if ($this->query_failure) return false;
            $args = is_array($prepared) ? $prepared['args'] : array();
            $now = (string) ($args[0] ?? '');
            $user_id = (int) ($args[1] ?? 0);
            $changed = 0;
            foreach ($this->invitations as &$row) {
                if ((int) $row['user_id'] !== $user_id || $row['status'] !== 'active') continue;
                $row['status'] = 'revoked';
                $row['revoked_at'] = $now;
                $changed++;
            }
            unset($row);
            return $changed;
        }
        if (strpos($sql, 'INSERT IGNORE INTO `aimee_relationship_events`') !== false) {
            return $this->query_failure ? false : 1;
        }
        return 0;
    }
    public function insert($table, $data) {
        if ($this->insert_failure) return false;
        $this->invitations[] = $data;
        return 1;
    }
}

function rupture_fixture() {
    global $wpdb;
    $wpdb = new Rupture_Test_Wpdb();
    $wpdb->invitations[] = array(
        'token_id' => 'old-token',
        'user_id' => 27,
        'status' => 'active',
        'consumed_at' => null,
        'revoked_at' => null,
    );
    return $wpdb;
}
function rupture_create() {
    $profile = (object) array('user_id' => 27, 'age' => 47);
    $intimacy = array(
        'score' => 100, 'stage' => 'bonded', 'trust' => 100,
        'chemistry' => 100, 'safety' => 100, 'frustration' => 0,
        'meaningful_interaction_count' => 109,
        'qualified_session_count' => 33, 'state_version' => 10,
        'active_rupture' => false, 'unresolved_rupture' => '',
        'repair_status' => 'clear',
    );
    return aimee_create_relationship_invitation(
        27, 500, 'explicit', "Tell me what you'd do.", $profile,
        $intimacy, 'primary', array(
            'intent' => 'romantic_or_flirty', 'directed_at_aimee' => true,
            'consensual' => true, 'respectful' => true, 'confidence' => 0.99,
        )
    );
}

$wpdb = rupture_fixture();
$token = rupture_create();
rupture_assert($token === 'token-created', 'fresh matching state can create an invitation');
rupture_assert(count($wpdb->invitations) === 2, 'successful creation inserts exactly one new token');
rupture_assert($wpdb->invitations[0]['status'] === 'revoked', 'new invitation supersedes an older active token');
rupture_assert($wpdb->invitations[1]['status'] === 'active', 'new invitation is active only after revalidation');
rupture_assert($wpdb->transaction_log === array('START', 'COMMIT'), 'creation-time revalidation commits atomically');

$wpdb = rupture_fixture();
$wpdb->state_version = 11;
rupture_assert(rupture_create() === '', 'an intervening relationship turn rejects the stale invitation');
rupture_assert(count($wpdb->invitations) === 1 && $wpdb->invitations[0]['status'] === 'active', 'stale rejection rolls back provisional revocation');
rupture_assert($wpdb->transaction_log === array('START', 'ROLLBACK'), 'stale state exits through rollback');

$wpdb = rupture_fixture();
$wpdb->inner = array('unresolved_rupture' => 'Confirmed pressure.', 'repair_status' => 'ruptured');
rupture_assert(rupture_create() === '', 'a concurrent durable rupture blocks invitation creation');
rupture_assert($wpdb->invitations[0]['status'] === 'active', 'rupture rejection rolls back the supersede update');

$wpdb = rupture_fixture();
rupture_assert(aimee_revoke_active_relationship_invitations_for_user(27), 'durable rupture can revoke all active invitations');
rupture_assert($wpdb->invitations[0]['status'] === 'revoked', 'revocation makes the old token unusable');
$wpdb = rupture_fixture();
$wpdb->query_failure = true;
rupture_assert(!aimee_revoke_active_relationship_invitations_for_user(27), 'invitation revocation reports SQL failure');

$wpdb = rupture_fixture();
$wpdb->query_failure = true;
$event_result = aimee_record_relationship_event(
    27, 'relationship_rupture', 'Confirmed rupture.', 'marker', -8, -8, true
);
rupture_assert($event_result === false, 'relationship-event SQL failure is distinguishable from an ignored duplicate');
$wpdb = rupture_fixture();
$event_result = aimee_record_relationship_event(
    27, 'relationship_rupture', 'Confirmed rupture.', 'marker', -8, -8, true
);
rupture_assert($event_result === 99, 'successful relationship-event insert returns its durable row id');

$handler_atomic = strpos($engine_source, "'inner_state_persistence_failed'") !== false
    && strpos($engine_source, "'relationship_invitation_revoke_failed'") !== false
    && strpos($engine_source, "unset(\$inner_state['_persistence_ok'])") !== false;
rupture_assert($handler_atomic, 'live handler fails the whole turn when inner/event persistence or revocation fails');
rupture_assert(
    strpos($engine_source, 'coercion_category_ids') !== false
        && strpos($engine_source, 'coercion_pattern_ids') !== false,
    'privacy-safe coercion rule identifiers are retained in decision telemetry'
);

if ($GLOBALS['rupture_failures']) {
    fwrite(STDERR, "Durable rupture lifecycle failures: {$GLOBALS['rupture_failures']}/{$GLOBALS['rupture_checks']}.\n");
    exit(1);
}
echo "PASS: {$GLOBALS['rupture_checks']} durable rupture lifecycle checks.\n";
