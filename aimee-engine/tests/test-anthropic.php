<?php
// Request body shape.
$body = aimee_engine_anthropic_build_body('claude-opus-5', [['type' => 'text', 'text' => 'card', 'cache_control' => ['type' => 'ephemeral']], ['type' => 'text', 'text' => '']], [['role' => 'user', 'content' => 'hi']], ['max_tokens' => 800, 'effort' => 'low', 'tools' => [['name' => 'send_photo']]]);
assert_same('low', $body['output_config']['effort'], 'effort sent for Opus 5');
assert_same(1, count($body['system']), 'empty system blocks dropped');
assert_same(['type' => 'auto'], $body['tool_choice'], 'tool choice is auto, never forced');
assert_true(!isset($body['thinking']), 'thinking parameter never sent');

$body = aimee_engine_anthropic_build_body('claude-haiku-4-5', [], [['role' => 'user', 'content' => 'hi']], ['effort' => 'low', 'output_schema' => ['type' => 'object']]);
assert_true(!isset($body['output_config']['effort']), 'effort withheld from Haiku');
assert_same('json_schema', $body['output_config']['format']['type'], 'schema wired into output_config');
assert_true(!isset($body['tools']) && !isset($body['tool_choice']), 'no tools when none given');
assert_true(aimee_engine_model_supports_effort('claude-fable-5-1') && aimee_engine_model_supports_effort('claude-sonnet-5') && !aimee_engine_model_supports_effort('claude-sonnet-4-5'), 'effort support detection');

// Response normalisation.
$r = aimee_engine_anthropic_normalise(200, json_encode(['model' => 'claude-opus-5', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10], 'content' => [['type' => 'text', 'text' => 'Hello '], ['type' => 'text', 'text' => 'you.']]]));
assert_true($r['ok'], 'ok response');
assert_same('Hello you.', $r['text'], 'text blocks concatenated and trimmed');
assert_same(10, $r['usage']['input_tokens'], 'usage captured');

$r = aimee_engine_anthropic_normalise(200, json_encode(['stop_reason' => 'refusal', 'stop_details' => ['type' => 'refusal', 'category' => 'sexual'], 'content' => []]));
assert_true($r['ok'] && $r['stop_reason'] === 'refusal' && $r['refusal_category'] === 'sexual', 'refusal surfaces category');

$r = aimee_engine_anthropic_normalise(200, json_encode(['stop_reason' => 'tool_use', 'content' => [['type' => 'text', 'text' => 'Here'], ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'send_photo', 'input' => ['key' => 'pub_day']]]]));
assert_same(1, count($r['tool_uses']), 'tool use captured');
assert_same('pub_day', $r['tool_uses'][0]['input']['key'], 'tool input captured');
assert_same(2, count($r['content']), 'raw content kept for echoing back');

$r = aimee_engine_anthropic_normalise(400, '{"type":"error","error":{"type":"invalid_request_error","message":"bad"}}');
assert_true(!$r['ok'] && $r['error_type'] === 'invalid_request_error', 'http error normalised');
$r = aimee_engine_anthropic_normalise(200, 'not json');
assert_true(!$r['ok'] && $r['error_type'] === 'invalid_json', 'garbage body handled');

// Tolerant JSON extraction.
assert_same(['a' => 1], aimee_engine_extract_json('{"a":1}'), 'plain json');
assert_same(['a' => 1], aimee_engine_extract_json("Sure:\n```json\n{\"a\":1}\n```"), 'fenced json');
assert_same(['a' => 1], aimee_engine_extract_json('Here you go {"a":1} thanks'), 'embedded json');
assert_same(null, aimee_engine_extract_json('nothing here'), 'no json');

// Request wiring: key header, version header, JSON body, latency captured.
test_reset();
$GLOBALS['aimee_test_http_response'] = ['code' => 200, 'body' => json_encode(['model' => 'x', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'ok']]])];
$r = aimee_engine_anthropic_request(['model' => 'x', 'max_tokens' => 5, 'messages' => []]);
assert_true($r['ok'] && $r['text'] === 'ok', 'request round trip');
$call = $GLOBALS['aimee_test_http'][0];
assert_same('https://api.anthropic.com/v1/messages', $call['url'], 'messages endpoint');
assert_same('test-key', $call['args']['headers']['x-api-key'], 'api key header');
assert_same('2023-06-01', $call['args']['headers']['anthropic-version'], 'version header');
assert_same('x', json_decode($call['args']['body'], true)['model'], 'body is JSON');

// OpenRouter client.
$r = aimee_engine_openrouter_request([['role' => 'user', 'content' => 'hi']], ['a/b']);
assert_same('key_missing', $r['error_type'], 'openrouter missing key');
