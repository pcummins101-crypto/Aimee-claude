<?php
// Normalisation tolerates junk.
$c = aimee_engine_normalise_classification(['route' => 'EROTIC', 'tone' => 'silly', 'confidence' => 3, 'continuation' => 1]);
assert_same('erotic', $c['route'], 'route lowercased');
assert_same('neutral', $c['tone'], 'unknown tone falls back');
assert_same(1.0, $c['confidence'], 'confidence clamped');
assert_true($c['continuation'] && $c['consensual'] && $c['respectful'], 'missing booleans default to the kind reading');
assert_same('everyday', aimee_engine_normalise_classification(null)['route'], 'null input is everyday');

// Legacy intent mapping keeps Global's relationship maths meaningful.
assert_same('explicit_invitation', aimee_engine_classification_to_legacy(['route' => 'erotic'])['intent'], 'erotic maps to explicit_invitation');
assert_same('explicit_continuation', aimee_engine_classification_to_legacy(['route' => 'erotic', 'continuation' => true])['intent'], 'continuation maps to explicit_continuation');
assert_same('coercive_or_degrading', aimee_engine_classification_to_legacy(['route' => 'abusive'])['intent'], 'abusive maps to coercive');
assert_same('romantic_or_flirty', aimee_engine_classification_to_legacy(['route' => 'everyday', 'tone' => 'flirty'])['intent'], 'flirty tone maps to romantic');
assert_same('emotional_disclosure', aimee_engine_classification_to_legacy(['route' => 'everyday', 'tone' => 'vulnerable'])['intent'], 'vulnerable maps to emotional_disclosure');
assert_same('general', aimee_engine_classification_to_legacy(['route' => 'everyday', 'tone' => 'warm'])['intent'], 'warm maps to general');
assert_same('engine_v2_deterministic_fallback', aimee_engine_classification_to_legacy(['route' => 'everyday', 'source' => 'deterministic_fallback'])['source'], 'source is namespaced');

// Deterministic fallback is lenient but catches the obvious.
assert_same('erotic', aimee_engine_fallback_classification('Tell me what would you do to me tonight')['route'], 'explicit phrase → erotic');
assert_same('everyday', aimee_engine_fallback_classification('My mate called me a stupid cow at the pub, unbelievable')['route'], 'insult about a third party stays everyday');
assert_same('abusive', aimee_engine_fallback_classification('shut up you worthless thing')['route'], 'degrading → abusive');
$cont = aimee_engine_fallback_classification('go on', 'User: take your clothes off\nAimee: maybe');
assert_same('erotic', $cont['route'], 'short continuation of an explicit thread');
assert_true($cont['continuation'], 'continuation flagged');
assert_same('everyday', aimee_engine_fallback_classification('go on', 'User: what did you do today\nAimee: not much')['route'], 'short reply in a plain thread stays everyday');

// Classifier request shape and fallback on provider failure.
test_reset();
$GLOBALS['aimee_test_http_response'] = ['code' => 200, 'body' => json_encode([
    'model' => 'claude-haiku-4-5', 'stop_reason' => 'end_turn',
    'content' => [['type' => 'text', 'text' => '{"route":"everyday","tone":"warm","confidence":0.9,"directed_at_aimee":true,"continuation":false,"aimee_invited":false,"consensual":true,"respectful":true}']],
])];
$result = aimee_engine_classify('I missed you today', [], 'warm');
assert_same('everyday', $result['route'], 'classifier result parsed');
assert_same('warm', $result['tone'], 'tone parsed');
assert_same('classifier', $result['source'], 'source is classifier');
$sent = json_decode($GLOBALS['aimee_test_http'][0]['args']['body'], true);
assert_same('claude-haiku-4-5', $sent['model'], 'classifier uses configured model');
assert_same('json_schema', $sent['output_config']['format']['type'], 'classifier uses structured output');
assert_true(!isset($sent['output_config']['effort']), 'no effort sent to Haiku');
assert_true(strpos($sent['system'][0]['text'], 'Current relationship stage: warm') !== false, 'stage passed to classifier');

test_reset();
$GLOBALS['aimee_test_http_response'] = ['code' => 529, 'body' => '{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}'];
$result = aimee_engine_classify('touch yourself for me', [], 'intimate');
assert_same('deterministic_fallback', $result['source'], 'provider failure falls back deterministically');
assert_same('erotic', $result['route'], 'fallback still routes explicit text');
assert_same('overloaded_error', $result['classifier_error'], 'classifier error recorded');
