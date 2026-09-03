<?php
require_once __DIR__ . '/../includes/turn.php';

// run_primary reuses a pre-fetched first result and makes no extra call.
test_reset();
$ctx = [
    'user_id' => 1, 'request_id' => 'r1', 'model' => 'claude-opus-5', 'effort' => 'low', 'max_tokens' => 300,
    'system_blocks' => [['type' => 'text', 'text' => 'card']], 'messages' => [['role' => 'user', 'content' => 'hi']],
    'tool' => null, 'eligible' => [], 'intimacy' => [], 'legacy_classification' => [],
    'initial_result' => aimee_engine_anthropic_normalise(200, json_encode(['model' => 'claude-opus-5', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Aimee: Morning you. x']]]), 1200),
];
$p = aimee_engine_run_primary($ctx);
assert_true($p['ok'], 'primary ok from initial result');
assert_same('Morning you. x', $p['reply'], 'reply cleaned');
assert_same(0, count($GLOBALS['aimee_test_http']), 'no network call when the first result is supplied');
assert_same(1, $p['calls'], 'counted as one call');

// Without an initial result it calls the API once.
test_reset();
$GLOBALS['aimee_test_http_response'] = ['code' => 200, 'body' => json_encode(['model' => 'claude-opus-5', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Hello.']]])];
unset($ctx['initial_result']);
$p = aimee_engine_run_primary($ctx);
assert_true($p['ok'] && $p['reply'] === 'Hello.', 'primary ok from live call');
assert_same(1, count($GLOBALS['aimee_test_http']), 'exactly one call');
$sent = json_decode($GLOBALS['aimee_test_http'][0]['args']['body'], true);
assert_same('low', $sent['output_config']['effort'], 'effort passed');
assert_true(!isset($sent['tools']), 'no tools when none eligible');

// A refusal comes back as a signal, not an error.
test_reset();
$GLOBALS['aimee_test_http_response'] = ['code' => 200, 'body' => json_encode(['stop_reason' => 'refusal', 'stop_details' => ['category' => 'sexual'], 'content' => []])];
$p = aimee_engine_run_primary($ctx);
assert_true(!$p['ok'] && $p['refusal_category'] === 'sexual' && $p['error_type'] === '', 'refusal surfaced');

// Parallel helper falls back to sequential calls here (no Requests class) and keeps order.
test_reset();
$GLOBALS['aimee_test_http_response'] = ['code' => 200, 'body' => json_encode(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'x']]])];
$results = aimee_engine_anthropic_request_multiple(['classifier' => ['model' => 'a'], 'primary' => ['model' => 'b']], 30);
assert_same(['classifier', 'primary'], array_keys($results), 'results keyed like the input');
assert_same(2, count($GLOBALS['aimee_test_http']), 'two calls in fallback mode');

// Classification from a raw result.
$c = aimee_engine_classification_from_result(['ok' => true, 'stop_reason' => 'end_turn', 'text' => '{"route":"erotic","tone":"flirty","confidence":0.8,"continuation":true}', 'model' => 'claude-haiku-4-5', 'latency_ms' => 400], 'go on', '');
assert_same('erotic', $c['route'], 'classifier result honoured');
assert_true($c['continuation'], 'continuation honoured');
$c = aimee_engine_classification_from_result(['ok' => false, 'error_type' => 'network_error'], 'hello there', '');
assert_same('deterministic_fallback', $c['source'], 'failed classifier falls back');
assert_same('network_error', $c['classifier_error'], 'error kept');

// Snapshot without Global present.
$snap = aimee_engine_intimacy_snapshot((object) ['intimacy_score' => 61], 'intimate');
assert_same(61, $snap['score'], 'snapshot score');
assert_same('intimate', $snap['stage'], 'snapshot stage');
assert_same(false, $snap['use_intimacy_model'], 'snapshot never routes to the specialist by itself');

// Facts wording for the stage.
assert_true(strpos(aimee_engine_stage_description('bonded'), 'partner-like') !== false, 'stage description');
