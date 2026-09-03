<?php
// The database clock probe: a session running in local time must not shift
// stored timestamps. Pin it to zero for the transcript assertions below.
test_reset();
$GLOBALS['wpdb']->db_now = gmdate('Y-m-d H:i:s');
assert_same(0, aimee_engine_db_time_offset(), 'a UTC database reports no offset');

test_reset();
$GLOBALS['wpdb']->db_now = gmdate('Y-m-d H:i:s', time() + 3600);
assert_same(3600, aimee_engine_db_time_offset(), 'a database an hour ahead is measured');
$stored = gmdate('Y-m-d H:i:s', time() + 3600 - 2400);
assert_true(abs(aimee_engine_row_timestamp($stored) - (time() - 2400)) <= 2, 'a row stamped by that clock resolves to the real time');
assert_true(abs(strtotime(aimee_engine_row_utc_string($stored) . ' UTC') - (time() - 2400)) <= 2, 'and to a true-UTC string for Global');

test_reset();
$GLOBALS['wpdb']->db_now = gmdate('Y-m-d H:i:s', time() + 37);
assert_same(0, aimee_engine_db_time_offset(), 'request latency is not mistaken for a timezone');
test_reset();
$GLOBALS['wpdb']->db_now = null;
assert_same(0, aimee_engine_db_time_offset(), 'an unreadable clock falls back to UTC');
assert_same(0, aimee_engine_row_timestamp(''), 'empty timestamp');
assert_same('', aimee_engine_row_utc_string('0000-00-00 00:00:00'), 'zero timestamp');

test_reset();
$GLOBALS['wpdb']->db_now = gmdate('Y-m-d H:i:s');
$rows = [
    (object) ['sender' => 'aimee', 'message_text' => 'Hello, I am Aimee.', 'image_url' => null, 'created_at' => '2026-09-01 18:00:00'],
    (object) ['sender' => 'user', 'message_text' => 'Hi', 'image_url' => null, 'created_at' => '2026-09-01 18:01:00'],
    (object) ['sender' => 'user', 'message_text' => 'How are you?', 'image_url' => null, 'created_at' => '2026-09-01 18:01:30'],
    (object) ['sender' => 'aimee', 'message_text' => 'Good. Look at this.', 'image_url' => 'aimee-media:pub_day', 'created_at' => '2026-09-01 18:02:00'],
    (object) ['sender' => 'user', 'message_text' => 'Nice', 'image_url' => 'user-image:abc', 'created_at' => '2026-09-03 09:15:00'],
];
$turns = aimee_engine_transcript_messages($rows, ['photo_alts' => ['pub_day' => 'Aimee at the pub']]);

assert_same('user', $turns[0]['role'], 'transcript starts with a user turn');
assert_true(strpos($turns[0]['content'], 'Earlier conversation not shown') !== false, 'placeholder inserted before an opening assistant turn');
assert_same('assistant', $turns[1]['role'], 'assistant onboarding preserved');
assert_true(strpos($turns[1]['content'], '[Tue 1 Sep, 19:00] Hello') === 0, 'first message carries a timestamp in local time');
assert_same('user', $turns[2]['role'], 'consecutive user messages merged');
assert_true(strpos($turns[2]['content'], "Hi\n\nHow are you?") !== false, 'merged user messages keep both lines');
assert_true(strpos($turns[2]['content'], '[') === false, 'no timestamp within a short gap');
assert_true(strpos($turns[3]['content'], '[Aimee shared a photo: Aimee at the pub]') !== false, 'shared photo noted with its alt');
assert_true(strpos($turns[4]['content'], '[Thu 3 Sep, 10:15]') === 0, 'timestamp shown after a day change');
assert_true(strpos($turns[4]['content'], '[attached a photo]') !== false, 'user attachment noted');

// Character cap drops oldest turns and still starts with a user turn.
$long = [];
for ($i = 0; $i < 40; $i++) {
    $long[] = (object) ['sender' => $i % 2 ? 'aimee' : 'user', 'message_text' => str_repeat('x', 500) . $i, 'image_url' => null, 'created_at' => '2026-09-01 18:' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) . ':00'];
}
$capped = aimee_engine_transcript_messages($long, ['max_characters' => 5000]);
$total = 0; foreach ($capped as $t) $total += mb_strlen($t['content']);
assert_true($total <= 5000 + 600, 'transcript trimmed near the cap');
assert_same('user', $capped[0]['role'], 'trimmed transcript starts with user');

// History string for Global's regex helpers.
$string = aimee_engine_history_string($rows);
assert_true(strpos($string, "Aimee: Hello, I am Aimee.\nUser: Hi") === 0, 'history string uses User/Aimee labels');

// Facts and system blocks.
$facts = aimee_engine_facts_text(['  It is  late. ', '', 'He is a member.']);
assert_same("RIGHT NOW\n- It is late.\n- He is a member.", $facts, 'facts block formatting');
$blocks = aimee_engine_system_blocks('CARD', '', $facts);
assert_same(2, count($blocks), 'empty dossier omitted');
assert_same(['type' => 'ephemeral'], $blocks[0]['cache_control'], 'card carries the cache breakpoint');
assert_true(!isset($blocks[1]['cache_control']), 'facts are not cached');

// Reply cleaning.
assert_same('Hello you. x', aimee_engine_clean_reply("Aimee: Hello you. x"), 'strips speaker label');
assert_same('Hello you. x', aimee_engine_clean_reply("[21:10] Aimee: Hello you. x"), 'strips timestamp plus label');
assert_same("Look.\n\nThere.", aimee_engine_clean_reply("Look.\n\n\n\nThere. [[photo:pub_day]]"), 'collapses blank lines and removes stray photo token');
assert_same('Fine by me', aimee_engine_clean_reply('"Fine by me"'), 'unwraps a fully quoted reply');
assert_same('She said "no" and I said "fine"', aimee_engine_clean_reply('She said "no" and I said "fine"'), 'keeps inner quotes');

// Mood line.
assert_same('', aimee_engine_mood_line([]), 'no state, no mood line');
$mood = aimee_engine_mood_line(['dominant_emotion' => 'quietly pleased', 'emotion_cause' => 'he remembered', 'current_desire' => 'to keep this easy']);
assert_true(strpos($mood, 'quietly pleased (he remembered)') !== false && strpos($mood, 'to keep this easy') !== false, 'mood line built from inner state');

// Specialist system prompt.
$system = aimee_engine_specialist_system('CARD', 'DOSSIER', 'FACTS', 'notes here', ['lingerie_01' => 'Black lingerie [erotic]']);
assert_true(strpos($system, "AIMEE'S PRIVATE NOTES FOR THIS MOMENT\nnotes here") !== false, 'brief embedded');
assert_true(strpos($system, '[[photo:KEY]]') !== false && strpos($system, 'lingerie_01 = Black lingerie [erotic]') !== false, 'photo offer listed');
assert_true(strpos(aimee_engine_specialist_system('CARD', '', '', '', []), '[[photo') === false, 'no photo offer when none eligible');

// Default card has the essentials.
$card = aimee_engine_default_character_card();
assert_true(strpos($card, 'Engram Intelligence') !== false && strpos($card, 'mate') !== false && strpos($card, 'asterisks') !== false, 'default card mentions identity and voice facts');
assert_true(mb_strlen($card) < 4000, 'default card is short');
