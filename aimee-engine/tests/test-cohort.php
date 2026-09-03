<?php
// Allowlist mode: only listed users and per-user opt-ins.
assert_same('engine', aimee_engine_evaluate_cohort(12, 'allowlist', [12, 40], ''), 'allowlisted user goes to engine');
assert_same('legacy', aimee_engine_evaluate_cohort(13, 'allowlist', [12, 40], ''), 'unlisted user stays legacy');
assert_same('engine', aimee_engine_evaluate_cohort(13, 'allowlist', [], '1'), 'per-user opt-in wins in allowlist mode');
assert_same('legacy', aimee_engine_evaluate_cohort(12, 'allowlist', [12], '0'), 'per-user opt-out beats the allowlist');

// All mode: everyone unless opted out.
assert_same('engine', aimee_engine_evaluate_cohort(99, 'all', [], ''), 'all mode enrols everyone');
assert_same('legacy', aimee_engine_evaluate_cohort(99, 'all', [], '0'), 'opt-out respected in all mode');

// Defensive.
assert_same('legacy', aimee_engine_evaluate_cohort(0, 'all', [], '1'), 'no user id is always legacy');
assert_same('legacy', aimee_engine_evaluate_cohort(5, 'nonsense', [], ''), 'unknown mode behaves like allowlist');

// Settings sanitiser keeps the allowlist tidy and falls back on bad input.
test_reset();
$clean = aimee_engine_sanitize_settings([
    'enabled' => '1', 'cohort_mode' => 'weird', 'allowlist' => '3, 4,4 abc 5',
    'primary_model' => '', 'primary_effort' => 'max', 'history_messages' => '2', 'observer_mode' => 'bogus',
]);
assert_same(1, $clean['enabled'], 'enabled coerces to 1');
assert_same('allowlist', $clean['cohort_mode'], 'bad cohort mode falls back');
assert_same('3,4,5', $clean['allowlist'], 'allowlist deduped and numeric');
assert_same('claude-opus-5', $clean['primary_model'], 'empty model uses default');
assert_same('low', $clean['primary_effort'], 'unsupported effort falls back to low');
assert_same(6, $clean['history_messages'], 'history floor applied');
assert_same('async', $clean['observer_mode'], 'bad observer mode falls back');
assert_same('', $clean['specialist_models'], 'empty specialist list stays empty so Global config is inherited');
