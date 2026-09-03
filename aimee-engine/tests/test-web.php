<?php
// Web tools follow the setting and the market.
test_reset();
$tools = aimee_engine_web_tools('uk');
assert_same(2, count($tools), 'search and fetch declared by default');
assert_same('web_search_20260209', $tools[0]['type'], 'dynamic-filtering search version');
assert_same('GB', $tools[0]['user_location']['country'], 'UK location for the UK market');
assert_same(3, $tools[0]['max_uses'], 'default search cap');
assert_same('web_fetch_20260209', $tools[1]['type'], 'fetch declared');
assert_same('US', aimee_engine_web_tools('us')[0]['user_location']['country'], 'US location for the US market');
update_option('aimee_engine_settings', ['web_tools' => 0]); aimee_engine_reset_settings_cache();
assert_same([], aimee_engine_web_tools('uk'), 'web tools off when disabled');
test_reset();

// Primary body carries photo tool and web tools together; web tools alone when no photo.
$ctx = ['model' => 'claude-opus-5', 'effort' => 'low', 'max_tokens' => 300, 'system_blocks' => [], 'messages' => [['role' => 'user', 'content' => 'hi']], 'tool' => ['name' => 'send_photo'], 'web_tools' => aimee_engine_web_tools('uk')];
$body = aimee_engine_primary_body($ctx);
assert_same(['send_photo', 'web_search', 'web_fetch'], array_map(function ($t) { return $t['name']; }, $body['tools']), 'photo tool first, then web tools');
$ctx['tool'] = null;
assert_same(2, count(aimee_engine_primary_body($ctx)['tools']), 'web tools remain without a photo tool');

// Streamed server tool blocks are kept whole and reported.
$events = "event: content_block_start\ndata: {\"type\":\"content_block_start\",\"index\":0,\"content_block\":{\"type\":\"server_tool_use\",\"id\":\"srvtoolu_1\",\"name\":\"web_search\",\"input\":{}}}\n\n"
    . "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"input_json_delta\",\"partial_json\":\"{\\\"query\\\":\\\"arsenal score\\\"}\"}}\n\n"
    . "event: content_block_stop\ndata: {\"type\":\"content_block_stop\",\"index\":0}\n\n"
    . "event: content_block_start\ndata: {\"type\":\"content_block_start\",\"index\":1,\"content_block\":{\"type\":\"web_search_tool_result\",\"tool_use_id\":\"srvtoolu_1\",\"content\":[{\"type\":\"web_search_result\",\"url\":\"https://example.org\",\"title\":\"Result\"}]}}\n\n"
    . "event: content_block_stop\ndata: {\"type\":\"content_block_stop\",\"index\":1}\n\n"
    . "event: content_block_start\ndata: {\"type\":\"content_block_start\",\"index\":2,\"content_block\":{\"type\":\"text\",\"text\":\"\"}}\n\n"
    . "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":2,\"delta\":{\"type\":\"text_delta\",\"text\":\"Two nil, and you know it.\"}}\n\n"
    . "event: message_delta\ndata: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"}}\n\n";
$state = aimee_engine_sse_parser_create();
$seen_blocks = [];
$state['on_block'] = function ($type, $block) use (&$seen_blocks) { $seen_blocks[] = $type . ':' . ($block['name'] ?? ''); };
$text = aimee_engine_sse_parser_feed($state, $events);
assert_same('Two nil, and you know it.', $text, 'text after a search streams normally');
assert_same(['server_tool_use:web_search', 'web_search_tool_result:', 'text:'], $seen_blocks, 'block hook reports server tool use, its result and text');
$result = aimee_engine_sse_parser_result($state);
assert_same('arsenal score', $result['content'][0]['input']['query'], 'server tool input assembled from partial json');
assert_same('https://example.org', $result['content'][1]['content'][0]['url'], 'search result block kept whole for replay');
assert_same(0, count($result['tool_uses']), 'server tools are not client tool calls');

// Status emitter maps block types to header states.
$emitted = [];
$emit = aimee_engine_on_block_emitter(function ($event, $data) use (&$emitted) { $emitted[] = $data['state']; });
$emit('server_tool_use', ['name' => 'web_search']); $emit('server_tool_use', ['name' => 'web_fetch']); $emit('text', []);
assert_same(['searching', 'reading', 'writing'], $emitted, 'searching, reading and writing states');

// pause_turn resumes with the assistant content and no extra user turn.
test_reset();
$responses = [
    ['code' => 200, 'body' => json_encode(['model' => 'claude-opus-5', 'stop_reason' => 'pause_turn', 'content' => [['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search', 'input' => ['query' => 'x']]]])],
    ['code' => 200, 'body' => json_encode(['model' => 'claude-opus-5', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Found it.']]])],
];
$GLOBALS['aimee_test_http_response'] = $responses[0];
$GLOBALS['aimee_test_http_sequence'] = $responses;
require_once __DIR__ . '/../includes/turn.php';
$ctx = ['user_id' => 1, 'request_id' => 'r', 'model' => 'claude-opus-5', 'effort' => 'low', 'max_tokens' => 300, 'system_blocks' => [], 'messages' => [['role' => 'user', 'content' => 'score?']], 'tool' => null, 'web_tools' => aimee_engine_web_tools('uk'), 'eligible' => [], 'intimacy' => [], 'legacy_classification' => []];
$p = aimee_engine_run_primary($ctx);
assert_true($p['ok'] && $p['reply'] === 'Found it.', 'paused turn resumed to a reply');
assert_same(2, $p['calls'], 'two calls for a paused turn');
$second = json_decode($GLOBALS['aimee_test_http'][1]['args']['body'], true);
assert_same('assistant', end($second['messages'])['role'], 'resume request ends with the assistant turn, no extra user message');
