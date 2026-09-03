<?php
/**
 * Standalone regression checks for the one-time 1.7.5 repair of the known
 * user-112 onboarding attribution error.
 *
 * Run with:
 *   node tests/run-php-wasm.mjs tests/profile-opening-repair-regression.php
 */

define('ABSPATH', dirname(__DIR__) . '/');

$engine_source = file_get_contents(
    dirname(__DIR__) . '/includes/engine.php'
);
if (!is_string($engine_source) || $engine_source === '') {
    fwrite(STDERR, "Unable to read engine source for opening repair tests.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/includes/profile-attribution.php';

function aimee_profile_opening_test_extract_function($source, $name) {
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
    'aimee_profile_attribution_limit_text',
    'aimee_user_profile_attribution_source',
    'aimee_profile_attribution_repaired_opening_175',
    'aimee_repair_profile_attribution_opening_175',
) as $helper_name) {
    eval(aimee_profile_opening_test_extract_function(
        $engine_source,
        $helper_name
    ));
}

$GLOBALS['aimee_profile_opening_test_options'] = array();

if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return array_key_exists(
            $name,
            $GLOBALS['aimee_profile_opening_test_options']
        )
            ? $GLOBALS['aimee_profile_opening_test_options'][$name]
            : $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null) {
        $GLOBALS['aimee_profile_opening_test_options'][$name] = $value;
        return true;
    }
}
if (!function_exists('current_time')) {
    function current_time($type, $gmt = false) {
        return '2026-08-17 15:30:00';
    }
}
if (!function_exists('aimee_table')) {
    function aimee_table($name) {
        return (string) $name;
    }
}
if (!function_exists('aimee_messages_primary_key')) {
    function aimee_messages_primary_key() {
        return 'message_id';
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value) {
        return trim(strip_tags((string) $value));
    }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($value) {
        return trim(strip_tags((string) $value));
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($value) {
        return preg_replace(
            '/[^a-z0-9_\-]/',
            '',
            strtolower((string) $value)
        );
    }
}

class Aimee_Profile_Opening_Test_Prepared_Query {
    public $query;
    public $args;

    public function __construct($query, array $args) {
        $this->query = (string) $query;
        $this->args = $args;
    }
}

class Aimee_Profile_Opening_Test_Wpdb {
    public $profile;
    public $opening;
    public $user_message_count = 0;
    public $cas_conflict = false;
    public $write_failure = false;
    public $commit_failure = false;
    public $query_log = array();
    public $update_log = array();
    public $insert_log = array();
    public $delete_log = array();
    public $transaction_log = array();
    private $transaction_opening_snapshot = null;

    public function __construct() {
        $this->profile = (object) array(
            'user_id' => 112,
            'first_name' => 'Paul',
            'age' => 43,
            'hobbies' => 'I run an electric motorcycle company called Avenrà. I have 6 kids (from 2 wives). I love cars and motorbikes. I also like going out for meals',
            'looking_for' => 'Get to know you and see where we go',
            'appearance_notes' => 'A bald man with a reddish beard wears a white collared shirt and seatbelt while taking a selfie inside a convertible car in a sunny parking lot with several vehicles and a storefront visible in the background.',
            'created_at' => '2026-08-17 12:27:09',
            'trial_message_limit' => 30,
            'trial_messages_used' => 0,
            'intimacy_score' => 8,
            'intimacy_stage' => 'guarded',
            'subscription_status' => 'trial',
            'subscription_plan' => null,
            'subscription_current_period_end' => null,
            'membership_started_at' => null,
            'service_grace_code' => 'august_2026_processor_recovery',
            'service_grace_access_until' => '2026-08-31 23:00:00',
        );
        $this->opening = (object) array(
            'message_id' => 9001,
            'user_id' => 112,
            'sender' => 'aimee',
            'message_text' => "Hiya Paul, I'm Aimee 👋 I spend my days elbow-deep in electric motorbike plans for my company Avenrà, so anything on two wheels gets me properly excited. Do you ride, or are you strictly four-wheels-and-a-seatbelt sort of person? x",
            'image_url' => null,
            'evaluator_directive' => 'onboarding_icebreaker_written_context',
            'created_at' => '2026-08-17 12:27:10',
            'is_sms' => 0,
        );
    }

    public function prepare($query) {
        return new Aimee_Profile_Opening_Test_Prepared_Query(
            $query,
            array_slice(func_get_args(), 1)
        );
    }

    private function query_text($query) {
        return $query instanceof Aimee_Profile_Opening_Test_Prepared_Query
            ? $query->query
            : (string) $query;
    }

    private function query_args($query) {
        return $query instanceof Aimee_Profile_Opening_Test_Prepared_Query
            ? $query->args
            : array();
    }

    public function get_row($query) {
        $sql = $this->query_text($query);
        $args = $this->query_args($query);
        $this->query_log[] = array('kind' => 'get_row', 'sql' => $sql);

        if (stripos($sql, 'aimee_user_profiles') !== false) {
            if (
                stripos($sql, 'user_id') !== false
                && (int) $this->profile->user_id !== 112
            ) {
                return null;
            }
            return $this->profile;
        }
        if (stripos($sql, 'aimee_messages') !== false) {
            if (!$this->opening) return null;
            if (
                stripos($sql, "sender = 'aimee'") !== false
                && (string) $this->opening->sender !== 'aimee'
            ) {
                return null;
            }
            if (
                stripos($sql, 'evaluator_directive') !== false
                && stripos(
                    $sql,
                    'onboarding_icebreaker_written_context'
                ) !== false
                && (string) $this->opening->evaluator_directive
                    !== 'onboarding_icebreaker_written_context'
            ) {
                return null;
            }
            if (
                stripos($sql, 'message_text = %s') !== false
                && !in_array(
                    (string) $this->opening->message_text,
                    $args,
                    true
                )
            ) {
                return null;
            }
            return $this->opening;
        }

        return null;
    }

    public function get_var($query) {
        $sql = $this->query_text($query);
        $this->query_log[] = array('kind' => 'get_var', 'sql' => $sql);

        if (
            stripos($sql, 'COUNT') !== false
            && stripos($sql, 'aimee_messages') !== false
        ) {
            return $this->user_message_count;
        }

        return null;
    }

    public function query($query) {
        $sql = trim($this->query_text($query));
        $args = $this->query_args($query);
        $this->query_log[] = array(
            'kind' => 'query',
            'sql' => $sql,
            'args' => $args,
        );

        if (preg_match('/^(?:START TRANSACTION|COMMIT|ROLLBACK)$/i', $sql)) {
            $operation = strtoupper($sql);
            $this->transaction_log[] = $operation;

            if ($operation === 'START TRANSACTION') {
                $this->transaction_opening_snapshot = $this->opening
                    ? clone $this->opening
                    : null;
                return true;
            }
            if ($operation === 'COMMIT') {
                if ($this->commit_failure) return false;
                $this->transaction_opening_snapshot = null;
                return true;
            }
            if ($operation === 'ROLLBACK') {
                $this->opening = $this->transaction_opening_snapshot
                    ? clone $this->transaction_opening_snapshot
                    : $this->opening;
                $this->transaction_opening_snapshot = null;
                return true;
            }
        }

        if (
            stripos($sql, 'UPDATE') === 0
            && stripos($sql, 'aimee_messages') !== false
        ) {
            if ($this->write_failure) return false;
            if ($this->cas_conflict || !$this->opening) return 0;

            $old_text = (string) $this->opening->message_text;
            $old_directive = (string) $this->opening->evaluator_directive;
            $has_message_id = in_array(
                (int) $this->opening->message_id,
                array_map('intval', $args),
                true
            );
            $has_user_id = in_array(
                (int) $this->opening->user_id,
                array_map('intval', $args),
                true
            );
            $has_old_text = in_array($old_text, $args, true);
            $has_old_directive = in_array($old_directive, $args, true);

            if (
                !$has_message_id
                || !$has_user_id
                || !$has_old_text
                || !$has_old_directive
            ) {
                return 0;
            }

            $new_text = '';
            $new_directive = '';
            foreach ($args as $arg) {
                if (!is_string($arg)) continue;
                if (
                    $arg !== $old_text
                    && stripos($arg, 'Avenrà') !== false
                    && preg_match('/\b(?:you run|your company)\b/i', $arg) === 1
                ) {
                    $new_text = $arg;
                }
                if (
                    $arg !== $old_directive
                    && stripos($arg, 'profile_attribution_repair') !== false
                ) {
                    $new_directive = $arg;
                }
            }
            if ($new_text === '' || $new_directive === '') return 0;

            $this->opening->message_text = $new_text;
            $this->opening->evaluator_directive = $new_directive;
            $this->update_log[] = array(
                'table' => 'aimee_messages',
                'message_id' => (int) $this->opening->message_id,
                'message_text' => $new_text,
                'evaluator_directive' => $new_directive,
            );
            return 1;
        }

        return true;
    }

    public function update($table, $data, $where) {
        $table = (string) $table;
        $data = (array) $data;
        $where = (array) $where;
        if ($table !== 'aimee_messages') {
            $this->update_log[] = array(
                'table' => $table,
                'data' => $data,
                'where' => $where,
            );
            return false;
        }
        if ($this->write_failure) return false;
        if ($this->cas_conflict || !$this->opening) return 0;

        $message_id = (int) ($where['message_id'] ?? $where['id'] ?? 0);
        $matches = $message_id === (int) $this->opening->message_id
            && (int) ($where['user_id'] ?? 0)
                === (int) $this->opening->user_id
            && (string) ($where['sender'] ?? '')
                === (string) $this->opening->sender
            && (string) ($where['message_text'] ?? '')
                === (string) $this->opening->message_text;
        if (!$matches) return 0;

        $new_text = (string) ($data['message_text'] ?? '');
        $new_directive = (string) ($data['evaluator_directive'] ?? '');
        if (
            preg_match('/\b(?:you run|your company)\b/i', $new_text) !== 1
            || stripos($new_text, 'Avenrà') === false
            || stripos($new_directive, 'profile_attribution_repair') === false
        ) {
            return 0;
        }

        $this->opening->message_text = $new_text;
        $this->opening->evaluator_directive = $new_directive;
        $this->update_log[] = array(
            'table' => $table,
            'message_id' => (int) $this->opening->message_id,
            'message_text' => $new_text,
            'evaluator_directive' => $new_directive,
            'where' => $where,
        );
        return 1;
    }

    public function insert($table, $data) {
        $this->insert_log[] = array(
            'table' => (string) $table,
            'data' => (array) $data,
        );
        return false;
    }

    public function delete($table, $where) {
        $this->delete_log[] = array(
            'table' => (string) $table,
            'where' => (array) $where,
        );
        return false;
    }
}

$failures = array();
$checks = 0;
$assert = static function ($condition, $label) use (&$failures, &$checks) {
    $checks++;
    if (!$condition) $failures[] = $label;
};

function aimee_profile_opening_test_reset_options() {
    $GLOBALS['aimee_profile_opening_test_options'] = array();
}

function aimee_profile_opening_test_profile_snapshot($profile) {
    return array(
        'trial_message_limit' => (int) $profile->trial_message_limit,
        'trial_messages_used' => (int) $profile->trial_messages_used,
        'intimacy_score' => (int) $profile->intimacy_score,
        'intimacy_stage' => (string) $profile->intimacy_stage,
        'subscription_status' => (string) $profile->subscription_status,
        'subscription_plan' => $profile->subscription_plan,
        'subscription_current_period_end' => $profile->subscription_current_period_end,
        'membership_started_at' => $profile->membership_started_at,
        'service_grace_code' => (string) $profile->service_grace_code,
        'service_grace_access_until' => (string) $profile->service_grace_access_until,
    );
}

$assert(
    aimee_profile_attribution_limit_text('  abcdef  ', 4) === 'abcd',
    'profile source limiter trims before applying its exact cap'
);
$assert(
    aimee_profile_attribution_limit_text('abcdef', 0) === ''
        && aimee_profile_attribution_limit_text('abcdef', -3) === '',
    'profile source limiter fails closed for zero or negative caps'
);
$assert(
    aimee_profile_attribution_limit_text('', 1200) === '',
    'profile source limiter keeps empty optional fields empty'
);

$replacement = aimee_profile_attribution_repaired_opening_175('Paul');
$assert(
    preg_match('/\b(?:you run|your company)\b/i', $replacement) === 1
        && strpos($replacement, 'Avenrà') !== false,
    'deterministic replacement attributes Avenrà to Paul'
);
$assert(
    stripos($replacement, 'my company') === false
        && stripos($replacement, 'I run') === false
        && stripos($replacement, 'I spend my days') === false,
    'deterministic replacement contains no borrowed first-person biography'
);
$assert(
    stripos($replacement, 'what made you create') === false
        && stripos($replacement, 'you created') === false
        && stripos($replacement, 'story behind Avenrà') !== false,
    'replacement asks an entailment-safe question without claiming Paul created Avenrà'
);
$replacement_review = aimee_profile_attribution_review_reply(
    $replacement,
    array(
        'age' => 43,
        'hobbies' => 'I run an electric motorcycle company called Avenrà. I have 6 kids (from 2 wives). I love cars and motorbikes. I also like going out for meals',
        'looking_for' => 'Get to know you and see where we go',
        'appearance_notes' => 'A bald man with a reddish beard in a convertible car.',
    ),
    'Paul'
);
$assert(
    !empty($replacement_review['accepted']),
    'replacement passes the same deterministic attribution reviewer'
);

// Exact production evidence repairs the existing row once.
aimee_profile_opening_test_reset_options();
$wpdb = new Aimee_Profile_Opening_Test_Wpdb();
$profile_before = aimee_profile_opening_test_profile_snapshot($wpdb->profile);
$opening_id_before = (int) $wpdb->opening->message_id;
$opening_created_before = (string) $wpdb->opening->created_at;
aimee_repair_profile_attribution_opening_175();

$summary = $GLOBALS['aimee_profile_opening_test_options'][
    'aimee_global_profile_attribution_opening_repair_175'
] ?? array();
$assert(
    count($wpdb->update_log) === 1
        && ($wpdb->update_log[0]['table'] ?? '') === 'aimee_messages',
    'exact contaminated opening is updated once and only in the messages table'
);
$assert(
    (int) $wpdb->opening->message_id === $opening_id_before
        && (string) $wpdb->opening->created_at === $opening_created_before,
    'repair preserves the original message identity and timestamp'
);
$assert(
    (string) $wpdb->opening->message_text === $replacement,
    'repair stores the deterministic user-attributed replacement'
);
$assert(
    strpos(
        (string) $wpdb->opening->evaluator_directive,
        'profile_attribution_repair'
    ) !== false,
    'repaired row carries an inspectable attribution directive'
);
$assert(
    aimee_profile_opening_test_profile_snapshot($wpdb->profile)
        === $profile_before,
    'repair changes no relationship, preview, membership or service-grace field'
);
$non_message_mutations = array_filter(
    $wpdb->query_log,
    static function ($entry) {
        $sql = ltrim((string) ($entry['sql'] ?? ''));
        if (preg_match('/^(?:UPDATE|INSERT|DELETE)\b/i', $sql) !== 1) {
            return false;
        }

        return stripos($sql, 'aimee_messages') === false;
    }
);
$assert(
    $non_message_mutations === array(),
    'repair performs no SQL mutation of profiles, relationship state or billing data'
);
$assert(
    $wpdb->insert_log === array() && $wpdb->delete_log === array(),
    'repair neither creates a second greeting nor deletes history'
);
$assert(
    ($summary['status'] ?? '') === 'complete'
        && (int) ($summary['user_id'] ?? 0) === 112
        && ($summary['action'] ?? '') === 'contaminated_opening_repaired'
        && (int) ($summary['message_id'] ?? 0) === $opening_id_before
        && !empty($summary['completed_at']),
    'successful repair records an inspectable completion summary'
);
$assert(
    !empty($summary['original_hash'])
        && !empty($summary['replacement_hash'])
        && $summary['original_hash'] !== $summary['replacement_hash']
        && strpos(
            json_encode($summary),
            'elbow-deep in electric motorbike plans'
        ) === false,
    'summary records hashes without retaining the contaminated private text'
);
$assert(
    in_array('START TRANSACTION', $wpdb->transaction_log, true)
        && in_array('COMMIT', $wpdb->transaction_log, true)
        && !in_array('ROLLBACK', $wpdb->transaction_log, true),
    'successful compare-and-swap repair commits transactionally'
);

$updates_after_success = count($wpdb->update_log);
aimee_repair_profile_attribution_opening_175();
$assert(
    count($wpdb->update_log) === $updates_after_success,
    'completed repair is idempotent'
);

// Every piece of evidence is required. Near matches must be left untouched.
$negative_cases = array(
    array('wrong user', static function ($db) {
        $db->profile->user_id = 113;
        $db->opening->user_id = 113;
    }),
    array('wrong profile creation', static function ($db) {
        $db->profile->created_at = '2026-08-17 12:27:10';
    }),
    array('changed profile biography', static function ($db) {
        $db->profile->hobbies = 'I restore classic cars.';
    }),
    array('user has spoken', static function ($db) {
        $db->user_message_count = 1;
    }),
    array('preview usage advanced', static function ($db) {
        $db->profile->trial_messages_used = 1;
    }),
    array('non-opening directive', static function ($db) {
        $db->opening->evaluator_directive = 'route=primary';
    }),
    array('user-authored row', static function ($db) {
        $db->opening->sender = 'user';
    }),
    array('opening text already corrected', static function ($db) {
        $db->opening->message_text = aimee_profile_attribution_repaired_opening_175(
            'Paul'
        );
    }),
);

foreach ($negative_cases as $case) {
    aimee_profile_opening_test_reset_options();
    $wpdb = new Aimee_Profile_Opening_Test_Wpdb();
    $case[1]($wpdb);
    $profile_before = aimee_profile_opening_test_profile_snapshot(
        $wpdb->profile
    );
    $opening_before = clone $wpdb->opening;
    aimee_repair_profile_attribution_opening_175();

    $assert(
        $wpdb->update_log === array()
            && $wpdb->insert_log === array()
            && $wpdb->delete_log === array(),
        $case[0] . ' cannot trigger a write'
    );
    $assert(
        (array) $wpdb->opening === (array) $opening_before,
        $case[0] . ' preserves the existing message'
    );
    $assert(
        aimee_profile_opening_test_profile_snapshot($wpdb->profile)
            === $profile_before,
        $case[0] . ' preserves relationship and billing state'
    );
}

// A concurrent change must lose the compare-and-swap and roll back without
// being marked complete, so a later request can safely retry.
aimee_profile_opening_test_reset_options();
$wpdb = new Aimee_Profile_Opening_Test_Wpdb();
$wpdb->cas_conflict = true;
$opening_before = clone $wpdb->opening;
aimee_repair_profile_attribution_opening_175();
$conflict_summary = $GLOBALS['aimee_profile_opening_test_options'][
    'aimee_global_profile_attribution_opening_repair_175'
] ?? array();
$assert(
    (array) $wpdb->opening === (array) $opening_before
        && $wpdb->update_log === array(),
    'compare-and-swap conflict cannot overwrite a concurrently changed row'
);
$assert(
    in_array('ROLLBACK', $wpdb->transaction_log, true)
        && !in_array('COMMIT', $wpdb->transaction_log, true),
    'compare-and-swap conflict rolls the repair transaction back'
);
$assert(
    ($conflict_summary['status'] ?? '') !== 'complete',
    'compare-and-swap conflict remains retryable rather than falsely complete'
);

// A database failure has the same retryable, non-mutating outcome.
aimee_profile_opening_test_reset_options();
$wpdb = new Aimee_Profile_Opening_Test_Wpdb();
$wpdb->write_failure = true;
$opening_before = clone $wpdb->opening;
aimee_repair_profile_attribution_opening_175();
$failure_summary = $GLOBALS['aimee_profile_opening_test_options'][
    'aimee_global_profile_attribution_opening_repair_175'
] ?? array();
$assert(
    (array) $wpdb->opening === (array) $opening_before
        && in_array('ROLLBACK', $wpdb->transaction_log, true),
    'database write failure rolls back without altering the opening'
);
$assert(
    ($failure_summary['status'] ?? '') !== 'complete',
    'database write failure does not suppress the next repair attempt'
);

// A failed COMMIT must explicitly roll back the in-memory row and leave the
// option incomplete. The same worker can then retry from the original evidence
// and commit the repair once the database recovers.
aimee_profile_opening_test_reset_options();
$wpdb = new Aimee_Profile_Opening_Test_Wpdb();
$wpdb->commit_failure = true;
$opening_before = clone $wpdb->opening;
$profile_before = aimee_profile_opening_test_profile_snapshot($wpdb->profile);
aimee_repair_profile_attribution_opening_175();
$commit_failure_summary = $GLOBALS['aimee_profile_opening_test_options'][
    'aimee_global_profile_attribution_opening_repair_175'
] ?? array();
$assert(
    (array) $wpdb->opening === (array) $opening_before,
    'failed COMMIT restores the original opening row'
);
$assert(
    $wpdb->transaction_log === array(
        'START TRANSACTION',
        'COMMIT',
        'ROLLBACK',
    ),
    'failed COMMIT is followed by an explicit rollback'
);
$assert(
    ($commit_failure_summary['status'] ?? '') !== 'complete',
    'failed COMMIT leaves the one-time repair retryable'
);
$assert(
    aimee_profile_opening_test_profile_snapshot($wpdb->profile)
        === $profile_before
        && $wpdb->insert_log === array()
        && $wpdb->delete_log === array(),
    'failed COMMIT mutates no relationship, billing or message topology state'
);

$wpdb->commit_failure = false;
aimee_repair_profile_attribution_opening_175();
$retry_summary = $GLOBALS['aimee_profile_opening_test_options'][
    'aimee_global_profile_attribution_opening_repair_175'
] ?? array();
$assert(
    (string) $wpdb->opening->message_text === $replacement
        && ($retry_summary['status'] ?? '') === 'complete'
        && ($retry_summary['action'] ?? '')
            === 'contaminated_opening_repaired',
    'repair succeeds on a later retry after COMMIT recovers'
);
$assert(
    array_slice($wpdb->transaction_log, -2) === array(
        'START TRANSACTION',
        'COMMIT',
    ),
    'successful retry starts and commits a fresh transaction'
);

if ($failures) {
    echo "Profile opening repair regression failures:\n- "
        . implode("\n- ", $failures)
        . "\n";
    exit(1);
}

echo "PASS: {$checks} profile-attribution stored-opening repair checks.\n";
