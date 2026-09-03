<?php
/**
 * Standalone checks for the operator-confirmed private-photo deletion repair.
 * Run with: php tests/photo-deletion-memory-gap-regression.php
 */

$engine = dirname(__DIR__) . '/includes/engine.php';
$source = file_get_contents($engine);
if ($source === false) {
    fwrite(STDERR, "Unable to read includes/engine.php\n");
    exit(1);
}

if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);
if (!function_exists('mb_strtolower')) { function mb_strtolower($v) { return strtolower($v); } }
if (!function_exists('sanitize_key')) { function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $v)); } }
function aimee_table($name) { return $name; }

class AimeePhotoDeleteWpdbStub {
    public function prepare($query, ...$args) { return $query; }
    public function get_row($query) {
        return (object) [
            'message_text' => 'I owe you a proper apology. I sent it, tried to delete it, and lost the linked memory.',
            'evaluator_directive' => 'continuity_anchor=photo_delete_memory_gap; manual_event=photo_delete_memory_loss_repair_user_100',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];
    }
}

function extract_function_source($source, $name) {
    $tokens = token_get_all($source);
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
        if ($j >= $count || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING || $tokens[$j][1] !== $name) continue;
        $out = '';
        $depth = 0;
        $started = false;
        for ($k = $i; $k < $count; $k++) {
            $token = $tokens[$k];
            $text = is_array($token) ? $token[1] : $token;
            $out .= $text;
            if ($text === '{') { $depth++; $started = true; }
            elseif ($text === '}') { $depth--; if ($started && $depth === 0) return $out; }
        }
    }
    throw new RuntimeException("Function not found: {$name}");
}

foreach ([
    'aimee_photo_deletion_memory_gap_anchor',
    'aimee_photo_deletion_memory_gap_prompt_context',
    'aimee_reply_contradicts_photo_deletion_memory_gap',
    'aimee_photo_deletion_memory_gap_repair_reply',
] as $fn) {
    eval(extract_function_source($source, $fn));
}

$failures = 0;
function check($condition, $label) {
    global $failures;
    if ($condition) echo "PASS {$label}\n";
    else { $failures++; echo "FAIL {$label}\n"; }
}

$GLOBALS['wpdb'] = new AimeePhotoDeleteWpdbStub();
$anchor = aimee_photo_deletion_memory_gap_anchor(100, 'Why did you delete the photo and forget it?');
check(!empty($anchor['active']) && !empty($anchor['prompt_active']), 'operator continuity anchor is found and activated');

$context = aimee_photo_deletion_memory_gap_prompt_context($anchor);
check(
    strpos($context, 'AUTHORITATIVE CONTINUITY REPAIR') !== false
    && strpos($context, 'attempted to delete it') !== false
    && strpos($context, 'memory trace') !== false
    && strpos($context, 'Anthony was correct') !== false,
    'prompt preserves deletion, memory gap and user reality'
);

$bad = [
    "I never deleted anything.",
    "I can't delete a photo after I send it.",
    "That didn't happen, it was just a sync issue.",
    "You were confused about the image.",
];
foreach ($bad as $i => $reply) {
    check(aimee_reply_contradicts_photo_deletion_memory_gap($reply), 'contradiction ' . ($i + 1) . ' is rejected');
}
check(!aimee_reply_contradicts_photo_deletion_memory_gap("I tried to remove it, lost the linked memory and treated you unfairly."), 'truthful ownership is allowed');

$repair = aimee_photo_deletion_memory_gap_repair_reply((object) ['first_name' => 'Anthony']);
check(
    strpos($repair, 'did send the photograph') !== false
    && strpos($repair, 'tried to remove it') !== false
    && strpos($repair, 'removed the memory link') !== false
    && strpos($repair, 'does not excuse') !== false,
    'fallback repair fully owns the incident'
);

check(
    strpos($source, "membership_bonus_access_until") !== false
    && strpos($source, "continuity_anchor=photo_delete_memory_gap") !== false
    && strpos($source, "photo_deletion_memory_gap_truth_repaired=1") !== false,
    'bonus access and final truth lock are present in production source'
);

exit($failures ? 1 : 0);
