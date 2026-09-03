<?php
$eligible = [
    'pub_day' => ['alt' => 'Aimee at the pub', 'description' => 'Relaxed pub scene.', 'content_rating' => 'safe'],
    'lingerie_01' => ['alt' => 'Black lingerie mirror selfie', 'content_rating' => 'erotic'],
];
$sent = ['pub_day' => '2026-08-30 20:00:00'];

$tool = aimee_engine_photo_tool($eligible, $sent);
assert_same('send_photo', $tool['name'], 'tool name');
assert_same(['pub_day', 'lingerie_01'], $tool['input_schema']['properties']['key']['enum'], 'enum is exactly the eligible keys');
assert_true(strpos($tool['description'], 'already shared with him on 30 Aug') !== false, 'previously shared photo flagged with date');
assert_true(strpos($tool['description'], '[erotic]') !== false, 'rating shown');
assert_same(null, aimee_engine_photo_tool([], []), 'no tool without eligible photos');

$labels = aimee_engine_photo_offer_labels($eligible, []);
assert_same('Aimee at the pub. Relaxed pub scene. [safe]', $labels['pub_day'], 'label with description');
assert_same('Black lingerie mirror selfie [erotic]', $labels['lingerie_01'], 'label without description');

$parsed = aimee_engine_extract_photo_token("Come here then.\n[[photo:lingerie_01]]");
assert_same('lingerie_01', $parsed['key'], 'token key parsed');
assert_same('Come here then.', $parsed['text'], 'token stripped');
$parsed = aimee_engine_extract_photo_token('[[ Photo : Pub_Day ]] Look.');
assert_same('pub_day', $parsed['key'], 'token tolerant of spacing and case');
assert_same('Look.', $parsed['text'], 'leading token stripped');
assert_same('', aimee_engine_extract_photo_token('No token here')['key'], 'no token, no key');
