<?php
/** Offline account-erasure regression with the Live Image Beta plugin absent. */

$passes = 0;
$failures = 0;

function erasure_assert($condition, $label) {
    global $passes, $failures;
    if ($condition) {
        $passes++;
        echo "PASS {$label}\n";
        return;
    }
    $failures++;
    echo "FAIL {$label}\n";
}

if (!defined('ABSPATH')) define('ABSPATH', dirname(__DIR__) . '/');
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
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
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($value) {
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
if (!function_exists('current_time')) {
    function current_time($type, $gmt = false) {
        return (string) ($GLOBALS['erasure_now'] ?? '2026-08-18 13:00:00');
    }
}
if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        $GLOBALS['erasure_hooks'][] = [
            'hook' => (string) $hook,
            'callback' => (string) $callback,
            'priority' => intval($priority),
            'accepted_args' => intval($accepted_args),
        ];
    }
}
if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook, $args = []) {
        foreach ((array) ($GLOBALS['erasure_cron_events'] ?? []) as $event) {
            if (
                ($event['hook'] ?? '') === (string) $hook
                && ($event['args'] ?? []) === array_values((array) $args)
            ) {
                return intval($event['timestamp'] ?? 0);
            }
        }
        return false;
    }
}
if (!class_exists('WP_Error')) {
    class WP_Error {}
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($value) {
        return $value instanceof WP_Error;
    }
}
if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event($timestamp, $hook, $args = []) {
        if (!empty($GLOBALS['erasure_schedule_error'])) {
            return new WP_Error();
        }
        $GLOBALS['erasure_cron_events'][] = [
            'timestamp' => intval($timestamp),
            'hook' => (string) $hook,
            'args' => array_values((array) $args),
        ];
        return true;
    }
}
if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return array_key_exists((string) $name, $GLOBALS['erasure_options'])
            ? $GLOBALS['erasure_options'][(string) $name]
            : $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null) {
        $GLOBALS['erasure_options'][(string) $name] = $value;
        return true;
    }
}
if (!function_exists('delete_option')) {
    function delete_option($name) {
        unset($GLOBALS['erasure_options'][(string) $name]);
        return true;
    }
}
if (!function_exists('current_user_can')) {
    function current_user_can($capability) {
        return $capability === 'manage_options';
    }
}
if (!function_exists('esc_html')) {
    function esc_html($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$private_root = sys_get_temp_dir() . '/aimee-erasure-' . getmypid();
$output_dir = $private_root . '/live-output';
if (!is_dir($output_dir)) mkdir($output_dir, 0700, true);
$outside_path = sys_get_temp_dir() . '/aimee-erasure-outside-' . getmypid() . '.png';
file_put_contents($outside_path, 'outside-must-survive');
define('AIMEE_PRIVATE_MEDIA_DIR', $private_root);
define('AIMEE_LIVE_IMAGE_BETA_OUTPUT_DIR', $output_dir);

$GLOBALS['erasure_hooks'] = [];
$GLOBALS['erasure_table_exists'] = false;
$GLOBALS['erasure_columns'] = [
    'id',
    'user_id',
    'active_user_id',
    'status',
    'private_file_token',
    'updated_at',
    'outcome',
    'error_code',
    'lease_token',
    'lease_expires_at',
    'global_slot',
    'next_poll_at',
    'pending_exposure_token',
    'pending_exposure_expires_at',
    'failure_notify_lease_token',
    'failure_notify_lease_expires_at',
    'handoff_lease_token',
    'handoff_lease_expires_at',
];
$GLOBALS['erasure_rows'] = [];
$GLOBALS['erasure_operations'] = [];
$GLOBALS['erasure_delete_before_absent'] = 0;
$GLOBALS['erasure_cron_events'] = [];
$GLOBALS['erasure_schedule_error'] = false;
$GLOBALS['erasure_options'] = [];
$GLOBALS['erasure_engine'] = 'InnoDB';
$GLOBALS['erasure_fail_delete_once'] = false;
$GLOBALS['erasure_fail_lock_read_once'] = false;
$GLOBALS['erasure_now'] = '2026-08-18 13:00:00';

class AimeeErasureDb {
    public $prefix = 'wp_';
    public $last_error = '';

    public function prepare($query) {
        $args = array_slice(func_get_args(), 1);
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        return ['sql' => (string) $query, 'args' => array_values($args)];
    }

    public function esc_like($value) {
        return addcslashes((string) $value, '_%\\');
    }

    public function get_var($prepared) {
        $sql = is_array($prepared)
            ? (string) ($prepared['sql'] ?? '')
            : (string) $prepared;
        $args = is_array($prepared) ? (array) ($prepared['args'] ?? []) : [];
        if (strpos($sql, 'SHOW TABLES LIKE') !== false) {
            return !empty($GLOBALS['erasure_table_exists'])
                ? 'wp_aimee_live_image_beta_jobs'
                : null;
        }
        if (strpos($sql, 'SELECT COUNT(*)') !== false) {
            $user_id = intval($args[0] ?? 0);
            $count = 0;
            foreach ($GLOBALS['erasure_rows'] as $row) {
                if (intval($row['user_id'] ?? 0) === $user_id) $count++;
            }
            return (string) $count;
        }
        return null;
    }

    public function get_col($query, $column = 0) {
        return (array) $GLOBALS['erasure_columns'];
    }

    public function get_row($prepared, $output = null) {
        $sql = is_array($prepared)
            ? (string) ($prepared['sql'] ?? '')
            : (string) $prepared;
        if (strpos($sql, 'SHOW TABLE STATUS') !== false) {
            return [
                'Name' => 'wp_aimee_live_image_beta_jobs',
                'Engine' => (string) ($GLOBALS['erasure_engine'] ?? ''),
            ];
        }
        return null;
    }

    public function get_results($prepared, $output = null) {
        $sql = is_array($prepared)
            ? (string) ($prepared['sql'] ?? '')
            : (string) $prepared;
        $args = is_array($prepared) ? (array) ($prepared['args'] ?? []) : [];
        $user_id = intval($args[0] ?? 0);
        $locking = strpos($sql, 'FOR UPDATE') !== false;
        if ($locking && !empty($GLOBALS['erasure_fail_lock_read_once'])) {
            $GLOBALS['erasure_fail_lock_read_once'] = false;
            return null;
        }
        $last_id = $locking ? 0 : intval($args[1] ?? 0);
        if ($locking) $GLOBALS['erasure_operations'][] = 'lock';
        $rows = [];
        foreach ($GLOBALS['erasure_rows'] as $row) {
            if (
                intval($row['user_id'] ?? 0) === $user_id
                && intval($row['id'] ?? 0) > $last_id
            ) {
                $rows[] = [
                    'id' => intval($row['id']),
                    'status' => (string) ($row['status'] ?? ''),
                    'lease_expires_at' =>
                        (string) ($row['lease_expires_at'] ?? ''),
                    'private_file_token' =>
                        array_key_exists('private_file_token', $row)
                            ? $row['private_file_token']
                            : null,
                ];
            }
        }
        usort($rows, function ($left, $right) {
            return intval($left['id']) <=> intval($right['id']);
        });
        return $locking ? $rows : array_slice($rows, 0, 100);
    }

    public function query($prepared) {
        $sql = is_array($prepared)
            ? (string) ($prepared['sql'] ?? '')
            : (string) $prepared;
        $args = is_array($prepared) ? (array) ($prepared['args'] ?? []) : [];
        if (
            $sql === 'START TRANSACTION'
            || $sql === 'COMMIT'
            || $sql === 'ROLLBACK'
        ) {
            return 1;
        }
        if (strpos($sql, 'SET status = %s') !== false) {
            $user_id = intval($args[count($args) - 1] ?? 0);
            $GLOBALS['erasure_operations'][] = 'tombstone';
            $changed = 0;
            foreach ($GLOBALS['erasure_rows'] as &$row) {
                if (intval($row['user_id'] ?? 0) !== $user_id) continue;
                $row['status'] = 'deleting';
                $row['active_user_id'] = null;
                $row['updated_at'] = '2026-08-18 13:00:00';
                $row['outcome'] = 'deleting';
                $row['error_code'] = (string) (
                    $args[3] ?? 'account_deletion_pending'
                );
                $row['lease_token'] = '';
                $row['global_slot'] = 0;
                $row['next_poll_at'] = null;
                $row['pending_exposure_token'] = '';
                $row['pending_exposure_expires_at'] = null;
                $row['failure_notify_lease_token'] = '';
                $row['failure_notify_lease_expires_at'] = null;
                $row['handoff_lease_token'] = '';
                $row['handoff_lease_expires_at'] = null;
                $changed++;
            }
            unset($row);
            return $changed;
        }
        if (strpos($sql, 'SET error_code = %s') !== false) {
            $error_code = (string) ($args[0] ?? '');
            $row_id = intval($args[2] ?? 0);
            $user_id = intval($args[3] ?? 0);
            foreach ($GLOBALS['erasure_rows'] as &$row) {
                if (
                    intval($row['id'] ?? 0) === $row_id
                    && intval($row['user_id'] ?? 0) === $user_id
                    && (string) ($row['status'] ?? '') === 'deleting'
                ) {
                    $row['error_code'] = $error_code;
                    unset($row);
                    return 1;
                }
            }
            unset($row);
            return 0;
        }
        return false;
    }

    public function delete($table, $where, $formats = null) {
        if (!empty($GLOBALS['erasure_fail_delete_once'])) {
            $GLOBALS['erasure_fail_delete_once'] = false;
            return false;
        }
        $row_id = intval($where['id'] ?? 0);
        $user_id = intval($where['user_id'] ?? 0);
        $status = (string) ($where['status'] ?? '');
        $where_token = array_key_exists('private_file_token', $where)
            ? $where['private_file_token']
            : null;
        foreach ($GLOBALS['erasure_rows'] as $index => $row) {
            if (
                intval($row['id'] ?? 0) !== $row_id
                || intval($row['user_id'] ?? 0) !== $user_id
                || (string) ($row['status'] ?? '') !== $status
                || ($row['private_file_token'] ?? null) !== $where_token
            ) {
                continue;
            }
            $token = (string) ($row['private_file_token'] ?? '');
            if (preg_match('/^[a-f0-9]{64}$/D', $token)) {
                $candidate = AIMEE_LIVE_IMAGE_BETA_OUTPUT_DIR
                    . DIRECTORY_SEPARATOR . $token . '.png';
                if (file_exists($candidate) || is_link($candidate)) {
                    $GLOBALS['erasure_delete_before_absent']++;
                }
            }
            $GLOBALS['erasure_operations'][] = 'delete:' . $row_id;
            unset($GLOBALS['erasure_rows'][$index]);
            $GLOBALS['erasure_rows'] = array_values(
                $GLOBALS['erasure_rows']
            );
            return 1;
        }
        return 0;
    }
}

$GLOBALS['wpdb'] = new AimeeErasureDb();

require dirname(__DIR__) . '/includes/media-materialization.php';

function erasure_fire_registered_hook($hook, $user_id) {
    $result = null;
    foreach ((array) ($GLOBALS['erasure_hooks'] ?? []) as $registration) {
        if (($registration['hook'] ?? '') !== (string) $hook) continue;
        $result = call_user_func($registration['callback'], intval($user_id));
    }
    return $result;
}

function erasure_consume_cleanup_retry($user_id) {
    $remaining = [];
    foreach ((array) ($GLOBALS['erasure_cron_events'] ?? []) as $event) {
        if (
            ($event['hook'] ?? '')
                === 'aimee_global_retry_live_image_beta_cleanup'
            && ($event['args'] ?? []) === [intval($user_id)]
        ) {
            continue;
        }
        $remaining[] = $event;
    }
    $GLOBALS['erasure_cron_events'] = $remaining;
    return aimee_global_retry_live_image_beta_cleanup($user_id);
}

function erasure_row($row_id) {
    foreach ((array) ($GLOBALS['erasure_rows'] ?? []) as $row) {
        if (intval($row['id'] ?? 0) === intval($row_id)) return $row;
    }
    return null;
}

erasure_assert(
    function_exists('aimee_global_cleanup_live_image_beta_user_data')
        && !function_exists('aimee_live_image_beta_delete_user_data'),
    'Global erasure backstop works without loading the Beta plugin'
);
$registered_hooks = array_column($GLOBALS['erasure_hooks'], 'hook');
erasure_assert(
    in_array('delete_user', $registered_hooks, true)
        && in_array('wpmu_delete_user', $registered_hooks, true)
        && in_array(
            'aimee_global_retry_live_image_beta_cleanup',
            $registered_hooks,
            true
        )
        && in_array('admin_notices', $registered_hooks, true),
    'account deletion and persistent cleanup-retry hooks remain registered while Beta is absent'
);

$absent = aimee_global_cleanup_live_image_beta_user_data(112);
erasure_assert(
    !empty($absent['complete']) && ($absent['status'] ?? '') === 'absent',
    'missing beta job table is a safe no-op without a fatal error'
);

$GLOBALS['erasure_table_exists'] = true;
$complete_columns = $GLOBALS['erasure_columns'];
$GLOBALS['erasure_columns'] = array_values(array_diff(
    $complete_columns,
    ['private_file_token']
));
$partial = aimee_global_cleanup_live_image_beta_user_data(112);
erasure_assert(
    empty($partial['complete'])
        && ($partial['status'] ?? '') === 'schema_unavailable'
        && !empty($partial['retry_scheduled'])
        && !$GLOBALS['erasure_operations'],
    'partial beta schema fails closed before path work and leaves a durable recovery retry'
);
$GLOBALS['erasure_columns'] = $complete_columns;
$GLOBALS['erasure_cron_events'] = [];
$GLOBALS['erasure_options'] = [];

$token_file = str_repeat('a', 64);
$token_absent = str_repeat('b', 64);
$token_link = str_repeat('c', 64);
$token_other = str_repeat('d', 64);
$token_retry = str_repeat('e', 64);
$token_draining = str_repeat('9', 64);
$file_path = $output_dir . DIRECTORY_SEPARATOR . $token_file . '.png';
$link_path = $output_dir . DIRECTORY_SEPARATOR . $token_link . '.png';
$other_path = $output_dir . DIRECTORY_SEPARATOR . $token_other . '.png';
$draining_path = $output_dir . DIRECTORY_SEPARATOR . $token_draining . '.png';
file_put_contents($file_path, 'generated-private-file');
file_put_contents($other_path, 'another-user-private-file');
$symlink_supported = function_exists('symlink')
    && @symlink($outside_path, $link_path);

$base_row = [
    'active_user_id' => 112,
    'status' => 'claimed',
    'updated_at' => '2026-08-18 12:00:00',
    'outcome' => '',
    'error_code' => '',
    'lease_token' => str_repeat('f', 64),
    'lease_expires_at' => '2026-08-18 12:30:00',
    'global_slot' => 1,
    'next_poll_at' => '2026-08-18 14:00:00',
    'pending_exposure_token' => str_repeat('1', 64),
    'pending_exposure_expires_at' => '2026-08-18 14:00:00',
    'failure_notify_lease_token' => str_repeat('2', 64),
    'failure_notify_lease_expires_at' => '2026-08-18 14:00:00',
    'handoff_lease_token' => str_repeat('3', 64),
    'handoff_lease_expires_at' => '2026-08-18 14:00:00',
];
$GLOBALS['erasure_rows'] = [
    array_merge($base_row, [
        'id' => 1,
        'user_id' => 112,
        'private_file_token' => $token_file,
    ]),
    array_merge($base_row, [
        'id' => 2,
        'user_id' => 112,
        'status' => 'submitted',
        'private_file_token' => $token_absent,
    ]),
    array_merge($base_row, [
        'id' => 3,
        'user_id' => 112,
        'status' => 'ready',
        'private_file_token' => '../not-a-token',
    ]),
    array_merge($base_row, [
        'id' => 4,
        'user_id' => 112,
        'status' => 'handed_off',
        'private_file_token' => $symlink_supported ? $token_link : '',
    ]),
    array_merge($base_row, [
        'id' => 5,
        'user_id' => 113,
        'active_user_id' => 113,
        'private_file_token' => $token_other,
    ]),
    array_merge($base_row, [
        'id' => 6,
        'user_id' => 112,
        'status' => 'polling',
        'lease_expires_at' => '2026-08-18 13:05:00',
        'private_file_token' => $token_draining,
    ]),
];
$GLOBALS['erasure_operations'] = [];

$cleanup = aimee_global_cleanup_live_image_beta_user_data(112);
erasure_assert(
    empty($cleanup['complete'])
        && ($cleanup['status'] ?? '') === 'incomplete'
        && intval($cleanup['rows_retained'] ?? 0) === 2,
    'malformed-token provenance and one live worker drain are retained while other rows continue'
);
erasure_assert(
    !file_exists($file_path)
        && !is_link($file_path)
        && (!is_link($link_path))
        && file_exists($outside_path)
        && file_exists($other_path),
    'cleanup removes only contained token entries, unlinks a symlink entry rather than its target, and leaves another user untouched'
);
erasure_assert(
    array_search('lock', $GLOBALS['erasure_operations'], true)
        < array_search('tombstone', $GLOBALS['erasure_operations'], true)
        && array_search('tombstone', $GLOBALS['erasure_operations'], true)
            < array_search('delete:1', $GLOBALS['erasure_operations'], true)
        && $GLOBALS['erasure_delete_before_absent'] === 0,
    'worker state is locked before the atomic tombstone and rows delete only after files are absent'
);
$retained = null;
$other = null;
$draining = null;
foreach ($GLOBALS['erasure_rows'] as $row) {
    if (intval($row['id'] ?? 0) === 3) $retained = $row;
    if (intval($row['id'] ?? 0) === 5) $other = $row;
    if (intval($row['id'] ?? 0) === 6) $draining = $row;
}
erasure_assert(
    is_array($retained)
        && $retained['status'] === 'deleting'
        && $retained['active_user_id'] === null
        && $retained['lease_token'] === ''
        && $retained['lease_expires_at'] === '2026-08-18 12:30:00'
        && $retained['error_code'] === 'account_delete_token_invalid'
        && strlen($retained['error_code']) <= 48,
    'retained row is an inactive deleting tombstone with one bounded non-sensitive reason'
);
erasure_assert(
    is_array($draining)
        && $draining['status'] === 'deleting'
        && $draining['lease_token'] === ''
        && $draining['lease_expires_at'] === '2026-08-18 13:05:00'
        && $draining['error_code'] === 'account_delete_worker_drain'
        && !in_array('delete:6', $GLOBALS['erasure_operations'], true)
        && !file_exists($draining_path),
    'pre-tombstone polling lease is preserved and its absent final path is not mistaken for completed erasure'
);
$scheduled = $GLOBALS['erasure_cron_events'][0] ?? null;
$now_timestamp = strtotime('2026-08-18 13:00:00 UTC');
erasure_assert(
    is_array($scheduled)
        && ($scheduled['hook'] ?? '')
            === 'aimee_global_retry_live_image_beta_cleanup'
        && ($scheduled['args'] ?? []) === [112]
        && intval($scheduled['timestamp'] ?? 0) >= $now_timestamp + 15
        && intval($scheduled['timestamp'] ?? 0) <= $now_timestamp + 900,
    'live-worker retention schedules one bounded provider-independent cleanup retry'
);
$GLOBALS['erasure_cron_events'][] = [
    'timestamp' => $now_timestamp + 86400,
    'hook' => 'aimee_global_retry_live_image_beta_cleanup',
    'args' => [998],
];
$bounded_rearm = aimee_global_schedule_live_image_beta_cleanup_retry(998);
$bounded_rearm_found = false;
foreach ($GLOBALS['erasure_cron_events'] as $event) {
    if (
        ($event['args'] ?? []) === [998]
        && intval($event['timestamp'] ?? 0) > $now_timestamp
        && intval($event['timestamp'] ?? 0) <= $now_timestamp + 900
    ) {
        $bounded_rearm_found = true;
    }
}
erasure_assert(
    $bounded_rearm && $bounded_rearm_found,
    'an unbounded stale cron event cannot suppress a fresh bounded durable retry'
);
$GLOBALS['erasure_schedule_error'] = true;
$failed_schedule_result =
    aimee_global_live_image_beta_cleanup_incomplete(
        ['complete' => false, 'reason_code' => ''],
        999,
        'cleanup_database_unavailable'
    );
$failed_schedule_issues = get_option(
    'aimee_global_live_image_cleanup_issues',
    []
);
erasure_assert(
    empty($failed_schedule_result['retry_scheduled'])
        && ($failed_schedule_result['reason_code'] ?? '')
            === 'cleanup_retry_schedule_failed'
        && ($failed_schedule_issues['999']['reason_code'] ?? '')
            === 'cleanup_database_unavailable'
        && empty($failed_schedule_issues['999']['retry_scheduled']),
    'a cron error preserves the underlying operator reason and cannot masquerade as a successful retry'
);
$GLOBALS['erasure_schedule_error'] = false;
erasure_assert(
    is_array($other)
        && $other['status'] === 'claimed'
        && intval($other['active_user_id']) === 113,
    'tombstone and row cleanup remain scoped to the deleted user'
);

foreach ($GLOBALS['erasure_rows'] as &$row) {
    if (intval($row['id'] ?? 0) === 3) {
        $row['private_file_token'] = $token_retry;
    }
}
unset($row);
$GLOBALS['erasure_now'] = '2026-08-18 13:06:00';
file_put_contents(
    $draining_path,
    'worker-renamed-this-file-after-the-account-tombstone'
);
$retry = aimee_global_cleanup_live_image_beta_user_data(112);
erasure_assert(
    !empty($retry['complete'])
        && ($retry['status'] ?? '') === 'complete'
        && intval($retry['rows_retained'] ?? -1) === 0
        && !file_exists($draining_path),
    'after lease expiry the retry removes a late worker rename and the repaired tombstone'
);

// A deactivation-terminalized status cannot erase the durable lease barrier.
$token_failed_drain = str_repeat('6', 64);
$failed_drain_path = $output_dir . DIRECTORY_SEPARATOR
    . $token_failed_drain . '.png';
$GLOBALS['erasure_rows'] = [array_merge($base_row, [
    'id' => 20,
    'user_id' => 120,
    'active_user_id' => null,
    'status' => 'failed',
    'lease_expires_at' => '2026-08-18 13:10:00',
    'private_file_token' => $token_failed_drain,
])];
$GLOBALS['erasure_now'] = '2026-08-18 13:06:00';
$GLOBALS['erasure_cron_events'] = [];
$GLOBALS['erasure_options'] = [];
$failed_drain = erasure_fire_registered_hook('delete_user', 120);
$failed_drain_row = erasure_row(20);
file_put_contents($failed_drain_path, 'late-rename-after-terminalization');
$GLOBALS['erasure_now'] = '2026-08-18 13:11:00';
$failed_drain_retry = erasure_consume_cleanup_retry(120);
erasure_assert(
    empty($failed_drain['complete'])
        && !empty($failed_drain['retry_scheduled'])
        && is_array($failed_drain_row)
        && $failed_drain_row['error_code']
            === 'account_delete_worker_drain'
        && !empty($failed_drain_retry['complete'])
        && !file_exists($failed_drain_path)
        && erasure_row(20) === null,
    'any future preserved lease drains a deactivation-failed job and its late rename before provenance deletion'
);

// Non-transactional table engines may be tombstoned but never physically
// cleaned until an operator restores the required InnoDB semantics.
$GLOBALS['erasure_rows'] = [array_merge($base_row, [
    'id' => 21,
    'user_id' => 121,
    'status' => 'ready',
    'private_file_token' => null,
])];
$GLOBALS['erasure_engine'] = 'MyISAM';
$GLOBALS['erasure_cron_events'] = [];
$GLOBALS['erasure_options'] = [];
$engine_failure = erasure_fire_registered_hook('wpmu_delete_user', 121);
$engine_row = erasure_row(21);
$engine_issues = get_option('aimee_global_live_image_cleanup_issues', []);
ob_start();
aimee_global_live_image_beta_cleanup_admin_notice();
$engine_notice = ob_get_clean();
$GLOBALS['erasure_engine'] = 'InnoDB';
$engine_recovery = erasure_consume_cleanup_retry(121);
erasure_assert(
    ($engine_failure['status'] ?? '') === 'engine_unavailable'
        && !empty($engine_failure['retry_scheduled'])
        && is_array($engine_row)
        && $engine_row['status'] === 'deleting'
        && $engine_row['error_code'] === 'account_delete_engine_invalid'
        && strpos($engine_notice, 'cleanup_engine_unavailable') !== false
        && !empty($engine_issues['121']['retry_scheduled'])
        && !empty($engine_recovery['complete'])
        && erasure_row(21) === null,
    'non-InnoDB cleanup fails closed with a durable native-hook retry and operator-visible recovery reason'
);

// Missing output configuration is recoverable after the sidecar has gone.
$token_output_retry = str_repeat('7', 64);
$output_retry_path = $output_dir . DIRECTORY_SEPARATOR
    . $token_output_retry . '.png';
$offline_output = $output_dir . '-temporarily-unavailable';
$GLOBALS['erasure_rows'] = [array_merge($base_row, [
    'id' => 22,
    'user_id' => 122,
    'status' => 'ready',
    'private_file_token' => $token_output_retry,
])];
$GLOBALS['erasure_cron_events'] = [];
$GLOBALS['erasure_options'] = [];
@rename($output_dir, $offline_output);
$output_failure = erasure_fire_registered_hook('delete_user', 122);
@rename($offline_output, $output_dir);
file_put_contents($output_retry_path, 'restored-output-file');
$output_recovery = erasure_consume_cleanup_retry(122);
erasure_assert(
    empty($output_failure['complete'])
        && !empty($output_failure['retry_scheduled'])
        && ($output_failure['reason_code'] ?? '')
            === 'account_delete_output_invalid'
        && !empty($output_recovery['complete'])
        && !file_exists($output_retry_path)
        && erasure_row(22) === null,
    'beta-absent missing output fails to a durable retry and recovers without losing its token provenance'
);

// An invalid token remains bounded database provenance and is retried after an
// operator repairs the row; it can never become a filesystem path.
$GLOBALS['erasure_rows'] = [array_merge($base_row, [
    'id' => 23,
    'user_id' => 123,
    'status' => 'ready',
    'private_file_token' => '../invalid-token',
])];
$GLOBALS['erasure_cron_events'] = [];
$GLOBALS['erasure_options'] = [];
$token_failure = erasure_fire_registered_hook('delete_user', 123);
foreach ($GLOBALS['erasure_rows'] as &$repair_row) {
    if (intval($repair_row['id'] ?? 0) === 23) {
        $repair_row['private_file_token'] = '';
    }
}
unset($repair_row);
$token_recovery = erasure_consume_cleanup_retry(123);
erasure_assert(
    ($token_failure['reason_code'] ?? '')
        === 'account_delete_token_invalid'
        && !empty($token_failure['retry_scheduled'])
        && !empty($token_recovery['complete'])
        && erasure_row(23) === null,
    'invalid token cleanup leaves a native-hook retry and safely recovers after provenance repair'
);

// A non-file token entry forces the transient unlink branch without requiring
// platform permission tricks, then succeeds when storage becomes healthy.
$token_unlink_retry = str_repeat('8', 64);
$unlink_retry_path = $output_dir . DIRECTORY_SEPARATOR
    . $token_unlink_retry . '.png';
@mkdir($unlink_retry_path, 0700);
$GLOBALS['erasure_rows'] = [array_merge($base_row, [
    'id' => 24,
    'user_id' => 124,
    'status' => 'ready',
    'private_file_token' => $token_unlink_retry,
])];
$GLOBALS['erasure_cron_events'] = [];
$GLOBALS['erasure_options'] = [];
$unlink_failure = erasure_fire_registered_hook('delete_user', 124);
@rmdir($unlink_retry_path);
file_put_contents($unlink_retry_path, 'retryable-private-file');
$unlink_recovery = erasure_consume_cleanup_retry(124);
erasure_assert(
    ($unlink_failure['reason_code'] ?? '')
        === 'account_delete_unlink_failed'
        && !empty($unlink_failure['retry_scheduled'])
        && !empty($unlink_recovery['complete'])
        && !file_exists($unlink_retry_path),
    'transient unlink failure retains a durable retry and removes the contained entry after recovery'
);

// Row-CAS and transaction-read failures also remain retryable after Beta is
// absent; neither can turn an uncertain cleanup into a success response.
$GLOBALS['erasure_rows'] = [array_merge($base_row, [
    'id' => 25,
    'user_id' => 125,
    'status' => 'ready',
    'private_file_token' => '',
])];
$GLOBALS['erasure_cron_events'] = [];
$GLOBALS['erasure_options'] = [];
$GLOBALS['erasure_fail_delete_once'] = true;
$delete_failure = erasure_fire_registered_hook('delete_user', 125);
$delete_recovery = erasure_consume_cleanup_retry(125);
erasure_assert(
    ($delete_failure['reason_code'] ?? '')
        === 'account_delete_row_delete_failed'
        && !empty($delete_failure['retry_scheduled'])
        && !empty($delete_recovery['complete']),
    'transient row deletion failure leaves a durable native-hook retry through recovery'
);

$GLOBALS['erasure_rows'] = [array_merge($base_row, [
    'id' => 26,
    'user_id' => 126,
    'status' => 'ready',
    'private_file_token' => '',
])];
$GLOBALS['erasure_cron_events'] = [];
$GLOBALS['erasure_options'] = [];
$GLOBALS['erasure_fail_lock_read_once'] = true;
$database_failure = erasure_fire_registered_hook('delete_user', 126);
$database_recovery = erasure_consume_cleanup_retry(126);
erasure_assert(
    ($database_failure['reason_code'] ?? '') === 'cleanup_tombstone_failed'
        && !empty($database_failure['retry_scheduled'])
        && !empty($database_recovery['complete']),
    'transient database barrier failure leaves operator provenance and a durable recovery retry'
);

$valid_output = aimee_global_live_image_beta_cleanup_output_dir(
    $output_dir,
    $private_root
);
erasure_assert(
    is_array($valid_output)
        && realpath($valid_output['path']) === realpath($output_dir),
    'cleanup accepts the canonical configured beta output strictly inside private media'
);
erasure_assert(
    aimee_global_live_image_beta_cleanup_output_dir(
        dirname($private_root),
        $private_root
    ) === null
        && aimee_global_live_image_beta_cleanup_output_dir(
            $private_root,
            $private_root
        ) === null
        && aimee_global_live_image_beta_cleanup_output_dir(
            'https://example.invalid/output',
            $private_root
        ) === null,
    'cleanup rejects outside, root-equal and non-local configured output paths'
);

$materialization_source = file_get_contents(
    dirname(__DIR__) . '/includes/media-materialization.php'
);
$engine_source = file_get_contents(dirname(__DIR__) . '/includes/engine.php');
$cleanup_start = strpos(
    $materialization_source,
    'function aimee_global_cleanup_live_image_beta_user_data'
);
$cleanup_source = substr($materialization_source, $cleanup_start);
erasure_assert(
    strpos($cleanup_source, "'deleting'")
        < strpos($cleanup_source, '@unlink(')
        && strpos($cleanup_source, '@unlink(')
            < strpos($cleanup_source, '$wpdb->delete('),
    'production source orders tombstone, contained unlink and row deletion'
);
erasure_assert(
    strpos(
        $engine_source,
        'aimee_global_cleanup_live_image_beta_user_data($current_user_id)'
    ) !== false
        && strpos($engine_source, "'account_media_cleanup_pending'") !== false
        && strpos(
            $engine_source,
            'aimee_global_cleanup_live_image_beta_user_data($current_user_id)'
        ) < strpos($engine_source, '$tables = ['),
    'Aimee account deletion blocks before local erasure when generated cleanup remains incomplete'
);

@unlink($file_path);
@unlink($link_path);
@unlink($other_path);
@unlink($draining_path);
@unlink($outside_path);
@rmdir($output_dir);
@rmdir($private_root);

echo "RESULT {$passes} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
