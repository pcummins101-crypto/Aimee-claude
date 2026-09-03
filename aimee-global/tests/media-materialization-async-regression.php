<?php
/** Offline regression for Global's provider-neutral asynchronous image bridge. */

$passes = 0;
$failures = 0;

function async_media_assert($condition, $label) {
    global $passes, $failures;
    if ($condition) {
        $passes++;
        echo "PASS {$label}\n";
        return;
    }

    $failures++;
    echo "FAIL {$label}\n";
}

function async_media_same($expected, $actual, $label) {
    async_media_assert(
        $expected === $actual,
        $label . ' (expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . ')'
    );
}

if (!defined('ABSPATH')) define('ABSPATH', dirname(__DIR__) . '/');
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
if (!defined('AIMEE_OWNER_USER_ID')) define('AIMEE_OWNER_USER_ID', 112);

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
        return '2026-08-18 12:00:00';
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($value) {
        return json_encode($value);
    }
}

$root = sys_get_temp_dir() . '/aimee-async-media-' . getmypid();
if (!is_dir($root)) mkdir($root, 0700, true);
$static_path = $root . '/static.png';
$valid_png = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAQAAAAEAAQMAAABmvDolAAAAIGNIUk0AAHomAACAhAAA+gAAAIDoAAB1MAAA6mAAADqYAAAXcJy6UTwAAAAGUExURTNmmf////ENxh0AAAABYktHRAH/Ai3eAAAAB3RJTUUH6ggREhUjKa4JBwAAAB9JREFUaN7twQENAAAAwqD3T20ON6AAAAAAAAAAAL4NIQAAAX8ZnKcAAAAASUVORK5CYII=',
    true
);
file_put_contents($static_path, $valid_png);

$GLOBALS['async_root'] = $root;
$GLOBALS['async_static_path'] = $static_path;
$GLOBALS['async_deliveries'] = [];
$GLOBALS['async_decisions'] = [];
$GLOBALS['async_messages'] = [];
$GLOBALS['async_assets'] = [];
$GLOBALS['async_bind_calls'] = [];
$GLOBALS['async_transitions'] = [];
$GLOBALS['async_guard_calls'] = [
    'profile' => 0,
    'identity' => 0,
    'jealousy' => 0,
];
$GLOBALS['async_access_enabled'] = true;

function async_media_add_fixture($suffix, $user_id = 112) {
    $delivery_id = 'delivery-' . $suffix;
    $decision_id = 'decision-' . $suffix;
    $media_key = 'safe_day';
    $generated_path = $GLOBALS['async_root'] . '/generated-' . $suffix . '.png';
    copy($GLOBALS['async_static_path'], $generated_path);

    $GLOBALS['async_deliveries'][$delivery_id] = [
        'delivery_id' => $delivery_id,
        'decision_id' => $decision_id,
        'user_id' => $user_id,
        'media_key' => $media_key,
        'current_state' => 'authorised',
        'catalogue_resolved_at' => '2026-08-18 11:59:58',
        'authorised_at' => '2026-08-18 11:59:59',
        'file_resolved_at' => null,
        'message_created_at' => null,
        'message_id' => null,
        'returned_by_direct_api_at' => null,
        'returned_by_history_api_at' => null,
        'failed_at' => null,
        'error_code' => null,
        'resolved_asset_source' => null,
        'resolved_asset_job_id' => null,
        'resolved_asset_sha256' => null,
        'resolved_asset_mime' => null,
    ];
    $GLOBALS['async_decisions'][$decision_id] = [
        'decision_id' => $decision_id,
        'user_id' => $user_id,
        'media_opportunity' => 1,
        'aimee_decision' => 'send',
        'selected_key' => $media_key,
        'eligible_keys_json' => json_encode([$media_key]),
        'reason_code' => 'owner_safe_image_test',
        'requested_rating' => 'safe',
        'direct_request' => 1,
        'proactive_allowed' => 0,
        'cooldown_clear' => 1,
        'pressure_detected' => 0,
        'policy_snapshot_json' => json_encode([
            'hard_veto_reason_codes' => [],
        ]),
        'model_route' => 'primary',
    ];
    $GLOBALS['async_assets'][$delivery_id] = [
        'path' => $generated_path,
        'mime' => 'image/png',
        'alt' => 'Generated visual-world portrait',
        'content_rating' => 'safe',
        'source' => 'provider_specific_value_is_ignored',
        'delivery_id' => $delivery_id,
        'media_key' => $media_key,
        'user_id' => $user_id,
        'job_id' => 700 + count($GLOBALS['async_assets']),
        'sha256' => hash_file('sha256', $generated_path),
    ];

    return $delivery_id;
}

class AimeeAsyncMaterializationDb {
    public $insert_id = 0;
    public $in_transaction = false;
    public $fail_next_message_insert = false;
    public $queries = [];
    private $snapshot = null;

    public function prepare($query) {
        $args = array_slice(func_get_args(), 1);
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        return ['sql' => (string) $query, 'args' => array_values($args)];
    }

    public function query($prepared) {
        $sql = is_array($prepared)
            ? (string) ($prepared['sql'] ?? '')
            : (string) $prepared;
        $this->queries[] = $sql;
        if ($sql === 'START TRANSACTION') {
            $this->in_transaction = true;
            $this->snapshot = [
                'deliveries' => $GLOBALS['async_deliveries'],
                'messages' => $GLOBALS['async_messages'],
                'insert_id' => $this->insert_id,
            ];
            return 1;
        }
        if ($sql === 'ROLLBACK') {
            if (is_array($this->snapshot)) {
                $GLOBALS['async_deliveries'] = $this->snapshot['deliveries'];
                $GLOBALS['async_messages'] = $this->snapshot['messages'];
                $this->insert_id = $this->snapshot['insert_id'];
            }
            $this->snapshot = null;
            $this->in_transaction = false;
            return 1;
        }
        if ($sql === 'COMMIT') {
            $this->snapshot = null;
            $this->in_transaction = false;
            return 1;
        }

        return 1;
    }

    public function get_row($prepared, $output = null) {
        $sql = is_array($prepared)
            ? (string) ($prepared['sql'] ?? '')
            : (string) $prepared;
        $args = is_array($prepared) ? (array) ($prepared['args'] ?? []) : [];

        if (strpos($sql, 'aimee_media_decisions') !== false) {
            $row = $GLOBALS['async_decisions'][(string) ($args[0] ?? '')] ?? null;
            if (!is_array($row) || intval($row['user_id']) !== intval($args[1] ?? 0)) {
                return null;
            }
            return $output === ARRAY_A ? $row : (object) $row;
        }
        if (strpos($sql, 'aimee_user_profiles') !== false) {
            $user_id = intval($args[0] ?? 0);
            if ($user_id <= 0) return null;
            return (object) [
                'user_id' => $user_id,
                'first_name' => 'Paul',
                'age' => 38,
            ];
        }
        if (strpos($sql, 'aimee_media_deliveries') !== false) {
            $row = $GLOBALS['async_deliveries'][(string) ($args[0] ?? '')] ?? null;
            return is_array($row) ? $row : null;
        }
        if (strpos($sql, 'WHERE media_delivery_id = %s') !== false) {
            $delivery_id = (string) ($args[0] ?? '');
            foreach ($GLOBALS['async_messages'] as $message) {
                if ((string) ($message['media_delivery_id'] ?? '') === $delivery_id) {
                    return [
                        'message_id' => intval($message['message_id'] ?? 0),
                        'sender' => (string) ($message['sender'] ?? ''),
                        'image_url' => $message['image_url'] ?? null,
                    ];
                }
            }
        }

        return null;
    }

    public function get_var($prepared) {
        $args = is_array($prepared) ? (array) ($prepared['args'] ?? []) : [];
        $delivery_id = (string) ($args[0] ?? '');
        $directive = (string) ($args[2] ?? '');
        foreach ($GLOBALS['async_messages'] as $message) {
            if (
                (string) ($message['media_delivery_id'] ?? '') === $delivery_id
                && (string) ($message['evaluator_directive'] ?? '') === $directive
            ) {
                return intval($message['message_id'] ?? 0);
            }
        }

        return null;
    }

    public function insert($table, $values) {
        if ($this->fail_next_message_insert) {
            $this->fail_next_message_insert = false;
            $this->insert_id = 0;
            return false;
        }
        $this->insert_id++;
        $values['message_id'] = $this->insert_id;
        $GLOBALS['async_messages'][] = $values;
        return 1;
    }
}

$GLOBALS['wpdb'] = new AimeeAsyncMaterializationDb();

function aimee_table($name) {
    return (string) $name;
}
function aimee_media_decisions_table() {
    return 'aimee_media_decisions';
}
function aimee_media_deliveries_table() {
    return 'aimee_media_deliveries';
}
function aimee_messages_primary_key() {
    return 'message_id';
}
function aimee_media_materialization_schema_ready() {
    return true;
}
function aimee_private_media_dir() {
    return $GLOBALS['async_root'];
}
function aimee_private_media_catalog() {
    return [
        'safe_day' => [
            'mime' => 'image/png',
            'alt' => 'Static safe portrait',
            'content_rating' => 'safe',
        ],
    ];
}
function aimee_private_media_static_path($key) {
    return $key === 'safe_day' ? $GLOBALS['async_static_path'] : null;
}
function aimee_media_delivery_find($delivery_id, $user_id = 0) {
    $row = $GLOBALS['async_deliveries'][(string) $delivery_id] ?? null;
    if (!is_array($row)) return null;
    if ($user_id && intval($row['user_id']) !== intval($user_id)) return null;
    return $row;
}
function aimee_media_delivery_bind_resolved_asset($delivery_id, array $asset) {
    global $wpdb;
    $GLOBALS['async_bind_calls'][] = [
        'delivery_id' => (string) $delivery_id,
        'inside_transaction' => !empty($wpdb->in_transaction),
    ];
    if (empty($wpdb->in_transaction)) return false;
    if (!isset($GLOBALS['async_deliveries'][$delivery_id])) return false;
    $row =& $GLOBALS['async_deliveries'][$delivery_id];
    if (!empty($row['file_resolved_at'])) {
        return (string) $row['resolved_asset_source']
                === (string) ($asset['source'] ?? '')
            && intval($row['resolved_asset_job_id'])
                === intval($asset['job_id'] ?? 0)
            && hash_equals(
                (string) $row['resolved_asset_sha256'],
                (string) ($asset['sha256'] ?? '')
            )
            && hash_equals(
                (string) $row['resolved_asset_mime'],
                (string) ($asset['mime'] ?? '')
            );
    }
    $row['file_resolved_at'] = current_time('mysql', true);
    $row['resolved_asset_source'] = (string) ($asset['source'] ?? '');
    $row['resolved_asset_job_id'] = intval($asset['job_id'] ?? 0);
    $row['resolved_asset_sha256'] = (string) ($asset['sha256'] ?? '');
    $row['resolved_asset_mime'] = (string) ($asset['mime'] ?? '');
    $row['current_state'] = 'file_resolved';
    return true;
}
function aimee_media_delivery_transition($delivery_id, $state, array $details = []) {
    global $wpdb;
    $GLOBALS['async_transitions'][] = [
        'delivery_id' => (string) $delivery_id,
        'state' => (string) $state,
        'inside_transaction' => !empty($wpdb->in_transaction),
    ];
    if (!isset($GLOBALS['async_deliveries'][$delivery_id])) return false;
    $row =& $GLOBALS['async_deliveries'][$delivery_id];
    if ($state === 'message_created') {
        if (empty($wpdb->in_transaction) || empty($row['file_resolved_at'])) {
            return false;
        }
        $row['message_created_at'] = current_time('mysql', true);
        $row['message_id'] = intval($details['message_id'] ?? 0);
        $row['current_state'] = 'message_created';
        return $row['message_id'] > 0;
    }
    if ($state === 'failed') {
        if (empty($wpdb->in_transaction) || !empty($row['message_created_at'])) {
            return false;
        }
        $row['failed_at'] = current_time('mysql', true);
        $row['error_code'] = sanitize_key((string) (
            $details['error_code'] ?? 'materialization_failed'
        ));
        $row['current_state'] = 'failed';
        return true;
    }
    return false;
}
function aimee_configured_identity_user_id($constant_name) {
    return $constant_name === 'AIMEE_OWNER_USER_ID' ? 112 : 0;
}
function aimee_is_admin_user($profile) {
    return !empty($GLOBALS['async_access_enabled'])
        && intval($profile->user_id ?? 0) === 112;
}
function aimee_subscription_is_active($profile) {
    return false;
}
function aimee_free_preview_is_active($profile) {
    return false;
}
function aimee_adult_assurance_state($profile) {
    return 'verified';
}
function aimee_load_inner_state($user_id, $lock = false) {
    return ['repair_status' => 'clear', 'unresolved_rupture' => false];
}
function aimee_user_profile_attribution_source($profile) {
    return [];
}
function aimee_is_owner_user($profile) {
    return intval($profile->user_id ?? 0) === 112;
}
function aimee_profile_attribution_aimee_context($mode) {
    return ['reality_mode' => $mode];
}
function aimee_profile_attribution_review_reply(
    $message,
    $source,
    $name,
    $context
) {
    $GLOBALS['async_guard_calls']['profile']++;
    return ['blocked' => false, 'reply' => (string) $message];
}
function aimee_synthetic_identity_review_reply(
    $message,
    $user_text,
    $classification,
    $context
) {
    $GLOBALS['async_guard_calls']['identity']++;
    return ['repaired' => false, 'reply' => (string) $message];
}
function aimee_playful_jealousy_review_reply($message, $expression, $source) {
    $GLOBALS['async_guard_calls']['jealousy']++;
    return ['repaired' => false, 'reply' => (string) $message];
}
function apply_filters($hook, $value) {
    $args = func_get_args();
    if ($hook === 'aimee_private_media_delivery_asset') {
        $delivery_id = (string) ($args[3] ?? '');
        return $GLOBALS['async_assets'][$delivery_id] ?? $value;
    }
    return $value;
}

require dirname(__DIR__) . '/includes/media-materialization.php';

$internal = aimee_media_materialization_sanitize_result([
    'status' => 'pending',
    'job_id' => 991,
    'model' => 'gpt-5.6',
    'provider' => 'openai',
    'secret' => 'must-not-survive',
]);
async_media_assert(
    ($internal['status'] ?? '') === 'pending'
        && intval($internal['job_id'] ?? 0) === 991
        && !array_key_exists('secret', $internal),
    'internal sidecar status is bounded while retaining the telemetry job ID'
);
$public = aimee_media_materialization_public_result($internal);
async_media_assert(
    ($public['status'] ?? '') === 'pending'
        && !array_key_exists('job_id', $public)
        && ($public['provider'] ?? '') === 'openai',
    'client-facing materialization status never exposes the sidecar job ID'
);
async_media_same(
    'unavailable',
    aimee_media_materialization_sanitize_result([
        'status' => 'pending',
    ])['status'],
    'a pending result without a positive job identity fails back to unavailable'
);

$draft = [
    'reply_text' => 'Invisible model prose',
    'equity_change' => 5,
    'inquiry_change' => -5,
    'fantasy_change' => 5,
    'archive_current_context' => true,
    'memory_operation' => 'upsert',
    'memory_to_save' => 'invented fact',
    'opinion_topic' => 'invented topic',
    'opinion_stance' => 'invented stance',
    'opinion_strength' => 100,
    'intimacy_invitation' => 'explicit',
    'chosen_action' => 'invisible action',
];
$neutral = aimee_media_materialization_neutral_pending_contract(
    $draft,
    (object) ['user_id' => 112, 'first_name' => 'Paul']
);
$pending_copy = "I’m creating that visual for you now — give me a moment and it’ll appear here when it’s ready. x";
async_media_assert(
    $neutral['reply_text'] === $pending_copy
        && $neutral['equity_change'] === 0
        && $neutral['inquiry_change'] === 0
        && $neutral['fantasy_change'] === 0
        && $neutral['archive_current_context'] === false
        && $neutral['memory_operation'] === 'none'
        && $neutral['memory_to_save'] === null
        && $neutral['opinion_topic'] === null
        && $neutral['opinion_strength'] === 0
        && $neutral['intimacy_invitation'] === 'none'
        && $neutral['chosen_action'] === null,
    'pending acknowledgement replaces every hidden reply-derived persistence contract'
);
async_media_assert(
    $GLOBALS['async_guard_calls']['profile'] > 0
        && $GLOBALS['async_guard_calls']['identity'] >= 2
        && $GLOBALS['async_guard_calls']['jealousy'] > 0,
    'deterministic pending copy still passes profile, jealousy and final identity guards'
);

$complete_id = async_media_add_fixture('complete');
async_media_assert(
    aimee_complete_pending_media_materialization($complete_id) === true,
    'ready derivative completes successfully'
);
$complete_messages = array_values(array_filter(
    $GLOBALS['async_messages'],
    function ($message) use ($complete_id) {
        return (string) ($message['media_delivery_id'] ?? '') === $complete_id;
    }
));
async_media_assert(
    count($complete_messages) === 1
        && $complete_messages[0]['sender'] === 'aimee'
        && $complete_messages[0]['image_url'] === 'aimee-media:safe_day'
        && $complete_messages[0]['message_text']
            === 'There — I created this visual-world portrait for you. x'
        && $complete_messages[0]['evaluator_directive']
            === 'async_media_materialization_complete',
    'completion inserts one truthful guarded Aimee image message'
);
async_media_assert(
    !empty($GLOBALS['async_deliveries'][$complete_id]['file_resolved_at'])
        && !empty($GLOBALS['async_deliveries'][$complete_id]['message_created_at'])
        && $GLOBALS['async_deliveries'][$complete_id]['resolved_asset_source']
            === 'delivery_materialization'
        && !empty($GLOBALS['async_bind_calls'][0]['inside_transaction'])
        && !empty($GLOBALS['async_transitions'][0]['inside_transaction']),
    'immutable bind and message_created transition occur inside one transaction'
);
$bind_count = count($GLOBALS['async_bind_calls']);
$transition_count = count($GLOBALS['async_transitions']);
$GLOBALS['async_access_enabled'] = false;
async_media_assert(
    aimee_complete_pending_media_materialization($complete_id) === true
        && count($GLOBALS['async_messages']) === 1
        && count($GLOBALS['async_bind_calls']) === $bind_count
        && count($GLOBALS['async_transitions']) === $transition_count,
    'completed replay verifies durable facts despite later access lapse and creates no duplicate'
);
$GLOBALS['async_access_enabled'] = true;

$rollback_id = async_media_add_fixture('rollback');
$GLOBALS['wpdb']->fail_next_message_insert = true;
async_media_assert(
    aimee_complete_pending_media_materialization($rollback_id) === false,
    'message insert failure fails completion closed'
);
async_media_assert(
    empty($GLOBALS['async_deliveries'][$rollback_id]['file_resolved_at'])
        && empty($GLOBALS['async_deliveries'][$rollback_id]['resolved_asset_source'])
        && count(array_filter(
            $GLOBALS['async_messages'],
            function ($message) use ($rollback_id) {
                return (string) ($message['media_delivery_id'] ?? '')
                    === $rollback_id;
            }
        )) === 0,
    'rolled-back message failure leaves no durable resolved asset or partial message'
);
async_media_assert(
    aimee_complete_pending_media_materialization($rollback_id) === true,
    'rolled-back completion remains safely retryable'
);

$failure_id = async_media_add_fixture('failure');
async_media_assert(
    aimee_fail_pending_media_materialization(
        $failure_id,
        'provider_terminal_failure'
    ) === true,
    'terminal materialization failure is recorded'
);
$failure_messages = array_values(array_filter(
    $GLOBALS['async_messages'],
    function ($message) use ($failure_id) {
        return (string) ($message['media_delivery_id'] ?? '') === $failure_id;
    }
));
async_media_assert(
    count($failure_messages) === 1
        && $failure_messages[0]['image_url'] === null
        && $failure_messages[0]['evaluator_directive']
            === 'async_media_materialization_failed'
        && strpos($failure_messages[0]['message_text'], "won't pretend") !== false
        && !empty($GLOBALS['async_deliveries'][$failure_id]['failed_at']),
    'terminal failure inserts one honest text-only note'
);
$message_count = count($GLOBALS['async_messages']);
$failure_transition_count = count($GLOBALS['async_transitions']);
async_media_assert(
    aimee_fail_pending_media_materialization(
        $failure_id,
        'different_retry_code'
    ) === true
        && count($GLOBALS['async_messages']) === $message_count
        && count($GLOBALS['async_transitions']) === $failure_transition_count,
    'terminal failure replay is idempotent and cannot duplicate its note'
);

$other_user_id = async_media_add_fixture('other-user', 113);
async_media_assert(
    aimee_complete_pending_media_materialization($other_user_id) === false
        && aimee_fail_pending_media_materialization(
            $other_user_id,
            'provider_terminal_failure'
        ) === false,
    'completion and failure callbacks remain closed outside exact owner user 112'
);

$materialization_source = file_get_contents(
    dirname(__DIR__) . '/includes/media-materialization.php'
);
$engine_source = file_get_contents(dirname(__DIR__) . '/includes/engine.php');
$complete_start = strpos(
    $materialization_source,
    'function aimee_complete_pending_media_materialization'
);
$complete_end = strpos(
    $materialization_source,
    'function aimee_fail_pending_media_materialization',
    $complete_start
);
$complete_source = substr(
    $materialization_source,
    $complete_start,
    $complete_end - $complete_start
);
async_media_assert(
    strpos($complete_source, "query('START TRANSACTION')")
        < strpos($complete_source, 'aimee_media_delivery_bind_resolved_asset')
        && strpos($complete_source, 'FOR UPDATE')
            < strpos($complete_source, 'aimee_media_delivery_bind_resolved_asset'),
    'source ordering keeps binding after transaction start and delivery row lock'
);
async_media_assert(
    strpos($complete_source, '$completed_binding_valid =')
        < strpos(
            $complete_source,
            '$trusted = aimee_media_materialization_authorised_context('
        ),
    'fully completed replay is verified before fresh gates required only for new creation'
);
async_media_assert(
    strpos($engine_source, '$pending_media_delivery_id = $media_delivery_id;')
        !== false
        && strpos($engine_source, '$media_key = \'\';') !== false
        && strpos($engine_source, '$media_delivery_id = \'\';') !== false
        && strpos(
            $engine_source,
            "'media_delivery_id'   => \$media_delivery_id ?: null"
        ) !== false,
    'pending interim message carries neither an image key nor delivery ID'
);
async_media_assert(
    substr_count(
        $engine_source,
        'aimee_media_materialization_neutral_pending_contract('
    ) >= 2
        && strpos(
            $engine_source,
            "'media_materialization_pending_deterministic'"
        ) !== false,
    'pending copy and neutral contract are reinstated after downstream rewrites'
);
async_media_assert(
    strpos(
        $engine_source,
        '$issued_invitation_token = $media_materialization_pending'
    ) !== false
        && strpos(
            $engine_source,
            'if ($aimee_message_id && !$media_materialization_pending)'
        ) !== false
        && strpos(
            $engine_source,
            '$memory_result = $media_materialization_pending'
        ) !== false
        && strpos(
            $engine_source,
            '!$media_materialization_pending'
                . "\n            && !\$gallery_discussion_only"
                . "\n            && aimee_turn_may_need_continuity"
        ) !== false,
    'pending model draft cannot create invitation, memory, opinion, metacognition or continuity work'
);
async_media_assert(
    strpos($engine_source, "'pending_interim_message_insert_failed'") !== false
        && strpos(
            $engine_source,
            "aimee_media_delivery_transition(\n"
                . '                $pending_media_delivery_id'
        ) !== false,
    'failed interim message persistence terminally invalidates its pending delivery'
);
async_media_assert(
    strpos(
        $engine_source,
        'aimee_media_materialization_public_result('
    ) !== false
        && strpos(
            $engine_source,
            "'media_materialization_job_id'"
        ) !== false,
    'job identity remains telemetry-only while the client uses public status'
);
async_media_assert(
    strpos(
        $engine_source,
        '$stored_media_reference'
            . "\n        && isset(\$eligible_media[\$media_key])"
    ) !== false
        && strpos(
            $engine_source,
            '$media_delivery_id !== \'\''
                . "\n        && \$media_payload"
        ) !== false,
    'pending acknowledgement cannot consume image preview or media cadence paths'
);

foreach (glob($root . '/*.png') as $path) @unlink($path);
@rmdir($root);

echo "RESULT {$passes} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
