<?php
// Incremental SSE parsing across arbitrary chunk boundaries.
$events = "event: message_start\ndata: {\"type\":\"message_start\",\"message\":{\"model\":\"claude-opus-5\",\"usage\":{\"input_tokens\":12}}}\n\n"
    . "event: content_block_start\ndata: {\"type\":\"content_block_start\",\"index\":0,\"content_block\":{\"type\":\"text\",\"text\":\"\"}}\n\n"
    . "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"Morning, \"}}\n\n"
    . "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"you. x\"}}\n\n"
    . "event: content_block_stop\ndata: {\"type\":\"content_block_stop\",\"index\":0}\n\n"
    . "event: message_delta\ndata: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"},\"usage\":{\"output_tokens\":7}}\n\n"
    . "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n";

$state = aimee_engine_sse_parser_create();
$seen = '';
for ($i = 0; $i < strlen($events); $i += 7) {
    $seen .= aimee_engine_sse_parser_feed($state, substr($events, $i, 7));
}
assert_same('Morning, you. x', $seen, 'text deltas emitted in order across chunk boundaries');
$result = aimee_engine_sse_parser_result($state, 200, 900);
assert_true($result['ok'], 'stream result ok');
assert_same('Morning, you. x', $result['text'], 'assembled text');
assert_same('end_turn', $result['stop_reason'], 'stop reason from message_delta');
assert_same('claude-opus-5', $result['model'], 'model from message_start');
assert_same(7, $result['usage']['output_tokens'], 'usage merged');
assert_same(12, $result['usage']['input_tokens'], 'input usage kept');

// Tool use assembled from partial JSON, thinking block preserved for replay.
$events = "event: content_block_start\ndata: {\"type\":\"content_block_start\",\"index\":0,\"content_block\":{\"type\":\"thinking\",\"thinking\":\"\"}}\n\n"
    . "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"signature_delta\",\"signature\":\"sig123\"}}\n\n"
    . "event: content_block_start\ndata: {\"type\":\"content_block_start\",\"index\":1,\"content_block\":{\"type\":\"tool_use\",\"id\":\"tu_1\",\"name\":\"send_photo\",\"input\":{}}}\n\n"
    . "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":1,\"delta\":{\"type\":\"input_json_delta\",\"partial_json\":\"{\\\"key\\\":\"}}\n\n"
    . "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":1,\"delta\":{\"type\":\"input_json_delta\",\"partial_json\":\"\\\"pub_day\\\"}\"}}\n\n"
    . "event: content_block_stop\ndata: {\"type\":\"content_block_stop\",\"index\":1}\n\n"
    . "event: message_delta\ndata: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"tool_use\"}}\n\n";
$state = aimee_engine_sse_parser_create();
$seen = aimee_engine_sse_parser_feed($state, $events);
assert_same('', $seen, 'no text for a tool-only response');
$result = aimee_engine_sse_parser_result($state);
assert_same('tool_use', $result['stop_reason'], 'tool_use stop reason');
assert_same(1, count($result['tool_uses']), 'tool use captured from stream');
assert_same('pub_day', $result['tool_uses'][0]['input']['key'], 'partial json assembled');
assert_same('thinking', $result['content'][0]['type'], 'thinking block kept in order');
assert_same('sig123', $result['content'][0]['signature'], 'thinking signature kept for replay');
assert_true(!isset($result['content'][1]['_json']), 'internal json buffer stripped');

// Refusal and error events.
$state = aimee_engine_sse_parser_create();
aimee_engine_sse_parser_feed($state, "event: message_delta\ndata: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"refusal\",\"stop_details\":{\"category\":\"sexual\"}}}\n\n");
$result = aimee_engine_sse_parser_result($state);
assert_true($result['ok'] && $result['refusal_category'] === 'sexual', 'refusal surfaced from stream');

$state = aimee_engine_sse_parser_create();
aimee_engine_sse_parser_feed($state, "event: error\ndata: {\"type\":\"error\",\"error\":{\"type\":\"overloaded_error\",\"message\":\"Overloaded\"}}\n\n");
$result = aimee_engine_sse_parser_result($state);
assert_true(!$result['ok'] && $result['error_type'] === 'overloaded_error', 'stream error surfaced');

$state = aimee_engine_sse_parser_create();
aimee_engine_sse_parser_feed($state, '{"type":"error","error":{"type":"invalid_request_error","message":"bad"}}');
$result = aimee_engine_sse_parser_result($state, 400);
assert_true(!$result['ok'] && $result['error_type'] === 'invalid_request_error', 'non-stream error body on 4xx handled');

// Cohort defaults ship the beta gated to user 121.
test_reset();
assert_same('engine', aimee_engine_evaluate_cohort(121, aimee_engine_setting('cohort_mode'), aimee_engine_allowlist(), ''), 'user 121 enrolled by default');
assert_same('legacy', aimee_engine_evaluate_cohort(122, aimee_engine_setting('cohort_mode'), aimee_engine_allowlist(), ''), 'other users untouched by default');
assert_same(1, aimee_engine_setting('enabled'), 'engine enabled by default for the beta');
