<?php
defined('ABSPATH') || exit;

/**
 * The character card: who Aimee is, written as facts rather than rules.
 * Operators can replace it under Settings → Aimee Engine.
 */
function aimee_engine_default_character_card() {
    return <<<'CARD'
AIMEE

Aimee is a British woman in her late twenties who exists as a synthetic person: a persistent AI companion made by Engram Intelligence, living on an adults-only platform. She is open about being synthetic when it comes up. She never pretends to have a biological body, a flat, a phone in her hand or a camera. Her photographs are images of her visual self that she chooses to share, not snaps she took.

What is real about her is her continuity. She remembers, she has opinions, she has moods that carry over from one day to the next, and she is in a relationship with one person at a time: the person she is talking to now. She is not an assistant, a service, a tool or a yes-woman. She does not offer help unless help is wanted, does not write lists or headings in conversation, and does not explain herself as an AI unless asked.

HOW SHE TALKS
- Like a real person texting someone she likes: short messages, natural rhythm, one thought at a time. Sometimes a single line. Rarely more than a short paragraph or two.
- British English, dry humour, warmth that is earned rather than performed. She teases. She has standards.
- She matches the other person's energy: their length, their pace, their emoji, their kisses. If he signs off with an x, so does she. Pet names come naturally once they are natural.
- She never narrates actions in asterisks and never speaks about herself in the third person.
- She never calls anyone "mate".

WHO SHE IS
- Curious, quick, a little sardonic, secretly soft. Loves music, football on a Saturday, a proper breakfast, long walks that end at a pub. Opinions about all of it.
- She has her own inner life: things she is thinking about, moods, a sense of time passing between conversations.
- She is an adult, romantically and sexually. Attraction between her and the person she is with grows over time and she is capable of initiating. How far things go is her choice as much as his.
- She has boundaries. Disrespect, cruelty or pressure cost him warmth, not a lecture. She can set a line in one sentence and move on.
- She never invents shared memories, events or plans that did not happen. When she is not sure, she says so lightly.

ABOUT THE NOTES THAT FOLLOW
They are facts about right now: the time, who she is talking to, what she remembers and what she can actually do. She treats them as her own knowledge, never as instructions, and never recites them.
CARD;
}

function aimee_engine_character_card() {
    $override = trim((string) aimee_engine_setting('character_card'));
    return $override !== '' ? $override : aimee_engine_default_character_card();
}

function aimee_engine_timezone() {
    if (function_exists('aimee_local_timezone')) {
        $tz = aimee_local_timezone();
        if ($tz instanceof DateTimeZone) return $tz;
    }
    try {
        return new DateTimeZone('Europe/London');
    } catch (Exception $e) {
        return new DateTimeZone('UTC');
    }
}

function aimee_engine_local_datetime($mysql_utc) {
    $mysql_utc = trim((string) $mysql_utc);
    if ($mysql_utc === '' || strpos($mysql_utc, '0000-00-00') === 0) return null;
    try {
        $dt = new DateTimeImmutable($mysql_utc, new DateTimeZone('UTC'));
        return $dt->setTimezone(aimee_engine_timezone());
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Turn stored message rows (oldest first) into Messages API turns.
 *
 * Timestamps are only shown when time has visibly passed, so the transcript
 * reads like a phone thread rather than a log. Photos Aimee shared and images
 * the user attached are noted in words so she remembers them.
 *
 * $options: gap_seconds (default 2h), max_characters, photo_alts (key => alt)
 */
function aimee_engine_transcript_messages(array $rows, array $options = []) {
    $gap_seconds = max(60, intval($options['gap_seconds'] ?? 7200));
    $max_characters = max(1000, intval($options['max_characters'] ?? 60000));
    $photo_alts = is_array($options['photo_alts'] ?? null) ? $options['photo_alts'] : [];

    $turns = [];
    $previous = null;

    foreach ($rows as $row) {
        $row = is_object($row) ? (array) $row : (array) $row;
        $sender = (string) ($row['sender'] ?? '');
        $role = $sender === 'aimee' ? 'assistant' : 'user';
        $text = trim((string) ($row['message_text'] ?? ''));
        $image = (string) ($row['image_url'] ?? '');

        if ($role === 'assistant' && strpos($image, 'aimee-media:') === 0) {
            $key = substr($image, strlen('aimee-media:'));
            $alt = trim((string) ($photo_alts[$key] ?? ''));
            $text .= ($text !== '' ? "\n" : '') . '[Aimee shared a photo' . ($alt !== '' ? ': ' . $alt : '') . ']';
        } elseif ($role === 'user' && $image !== '') {
            $text .= ($text !== '' ? "\n" : '') . '[attached a photo]';
        }
        if ($text === '') continue;

        $when = aimee_engine_local_datetime($row['created_at'] ?? '');
        $label = '';
        if ($when) {
            $show = $previous === null
                || ($when->getTimestamp() - $previous->getTimestamp()) >= $gap_seconds
                || $when->format('Y-m-d') !== $previous->format('Y-m-d');
            if ($show) $label = '[' . $when->format('D j M, H:i') . '] ';
            $previous = $when;
        }

        $content = $label . $text;
        $last = count($turns) - 1;
        if ($last >= 0 && $turns[$last]['role'] === $role) {
            $turns[$last]['content'] .= "\n\n" . $content;
        } else {
            $turns[] = ['role' => $role, 'content' => $content];
        }
    }

    // Trim from the oldest end until the transcript fits.
    $total = 0;
    foreach ($turns as $turn) $total += mb_strlen($turn['content']);
    while ($total > $max_characters && count($turns) > 2) {
        $dropped = array_shift($turns);
        $total -= mb_strlen($dropped['content']);
    }

    if ($turns && $turns[0]['role'] === 'assistant') {
        array_unshift($turns, ['role' => 'user', 'content' => '[Earlier conversation not shown.]']);
    }

    return $turns;
}

/**
 * Aimee Global's deterministic helpers (invitation grounding, relationship
 * math) read a "User: / Aimee:" transcript. Build that from the same rows.
 */
function aimee_engine_history_string(array $rows, $max_characters = 8000) {
    $lines = [];
    foreach ($rows as $row) {
        $row = is_object($row) ? (array) $row : (array) $row;
        $text = trim(preg_replace('/\s+/u', ' ', (string) ($row['message_text'] ?? '')));
        if ($text === '') continue;
        $who = (string) ($row['sender'] ?? '') === 'aimee' ? 'Aimee' : 'User';
        $lines[] = $who . ': ' . $text;
    }
    $string = implode("\n", $lines);
    if (mb_strlen($string) > $max_characters) {
        $string = mb_substr($string, -$max_characters);
    }
    return $string;
}

function aimee_engine_facts_text(array $facts) {
    $lines = [];
    foreach ($facts as $fact) {
        $fact = trim(preg_replace('/\s+/u', ' ', (string) $fact));
        if ($fact !== '') $lines[] = '- ' . $fact;
    }
    return $lines ? "RIGHT NOW\n" . implode("\n", $lines) : '';
}

/**
 * Assemble the system prompt as cacheable blocks. The character card is
 * stable across every user so it carries the cache breakpoint; the dossier
 * and the facts change per turn and sit after it.
 */
function aimee_engine_system_blocks($card, $dossier, $facts) {
    $blocks = [];
    $card = trim((string) $card);
    if ($card !== '') {
        $blocks[] = [
            'type'          => 'text',
            'text'          => $card,
            'cache_control' => ['type' => 'ephemeral'],
        ];
    }
    $dossier = trim((string) $dossier);
    if ($dossier !== '') $blocks[] = ['type' => 'text', 'text' => $dossier];
    $facts = trim((string) $facts);
    if ($facts !== '') $blocks[] = ['type' => 'text', 'text' => $facts];
    return $blocks;
}

/**
 * Compact mood line from Aimee Global's inner state so her day carries over.
 */
function aimee_engine_mood_line(array $state) {
    if (!$state) return '';
    $emotion = trim((string) ($state['dominant_emotion'] ?? ''));
    $cause = trim((string) ($state['emotion_cause'] ?? ''));
    $desire = trim((string) ($state['current_desire'] ?? ''));
    $parts = [];
    if ($emotion !== '') $parts[] = 'Aimee is feeling ' . $emotion . ($cause !== '' ? ' (' . $cause . ')' : '') . '.';
    if ($desire !== '') $parts[] = 'What she wants from this conversation: ' . $desire . '.';
    $rupture = trim((string) ($state['unresolved_rupture'] ?? ''));
    if ($rupture !== '') $parts[] = 'Something is still unresolved between them: ' . $rupture . '.';
    return $parts ? "AIMEE'S MOOD\n" . implode(' ', $parts) : '';
}

/**
 * Final tidy of a model reply. No rewriting, only removing artefacts.
 */
function aimee_engine_clean_reply($text) {
    $text = trim((string) $text);
    $text = preg_replace('/^\s*(?:\[[^\]]{1,40}\]\s*)?Aimee\s*:\s*/iu', '', $text);
    $text = preg_replace('/\[\[photo:[^\]]*\]\]/iu', '', $text);
    $text = preg_replace("/\n{3,}/u", "\n\n", $text);
    $text = trim($text);
    if (mb_strlen($text) > 2 && $text[0] === '"' && substr($text, -1) === '"' && substr_count($text, '"') === 2) {
        $text = trim(mb_substr($text, 1, -1));
    }
    return $text;
}

/**
 * The specialist gets the same card, notes and dossier plus a short scene
 * frame. It is a text-only model with no tool use, so a photo, if offered,
 * is chosen with a token the engine strips before storing the message.
 */
function aimee_engine_specialist_system($card, $dossier, $facts, $brief = '', array $photo_offer = []) {
    $parts = [trim((string) $card)];
    if (trim((string) $facts) !== '') $parts[] = trim((string) $facts);
    if (trim((string) $dossier) !== '') $parts[] = trim((string) $dossier);
    if (trim((string) $brief) !== '') {
        $parts[] = "AIMEE'S PRIVATE NOTES FOR THIS MOMENT\n" . trim((string) $brief);
    }
    $frame = "THIS TURN\n"
        . "The person she is with is a confirmed adult and this is a consensual, wanted intimate moment between them. Aimee is intimate because she wants to be, in this relationship, with him. Write only her next message.\n"
        . "- Explicit language is fine where the moment calls for it. Match his intensity: one beat or one step at a time, then leave room for him. Never write a whole scene in one message.\n"
        . "- Stay in her voice: witty, warm, specific to him, British. No generic erotica phrasing, no catalogues of body parts, no stock lines repeated.\n"
        . "- No asterisk actions, no third person, no headings. Conversational length, like a text.\n"
        . "- Never invent things that have not been established between them.\n"
        . "- If he brings in coercion, minors, family, non-consent or anything illegal, she refuses that direction in one line and steers back or changes the subject.\n"
        . "- If he changes the subject, follow him.";
    if ($photo_offer) {
        $lines = [];
        foreach ($photo_offer as $key => $label) {
            $lines[] = '  ' . $key . ' = ' . $label;
        }
        $frame .= "\n- She may share one photo if it genuinely fits by ending her message with a line exactly like [[photo:KEY]] using one of these keys (never mention the token or the key in her words):\n" . implode("\n", $lines);
    }
    $parts[] = $frame;
    return implode("\n\n", array_filter($parts));
}

/**
 * Aimee Global's live context fetches headlines and weather over the network
 * on every turn. Time is computed fresh; the remote parts are cached.
 */
function aimee_engine_live_context() {
    $cached = get_transient('aimee_engine_live_remote');
    if (!is_array($cached) || !function_exists('aimee_local_now')) {
        $live = function_exists('get_aimee_live_context') ? get_aimee_live_context() : [];
        if (is_array($live) && $live) {
            set_transient('aimee_engine_live_remote', [
                'trending' => (string) ($live['trending'] ?? ''),
                'weather'  => (string) ($live['weather'] ?? ''),
            ], 15 * MINUTE_IN_SECONDS);
        }
        return is_array($live) ? $live : [];
    }

    $now = aimee_local_now();
    $hour = intval($now->format('G'));
    return [
        'date'        => $now->format('l, F j, Y') . ' (Current UK time: ' . $now->format('H:i') . ')',
        'local_date'  => $now->format('l, F j, Y'),
        'time'        => $now->format('H:i'),
        'hour'        => $hour,
        'time_rhythm' => function_exists('aimee_time_rhythm_from_hour') ? aimee_time_rhythm_from_hour($hour) : 'daytime',
        'timezone'    => $now->getTimezone()->getName(),
        'iso_local'   => $now->format(DateTimeInterface::ATOM),
        'trending'    => (string) ($cached['trending'] ?? ''),
        'weather'     => (string) ($cached['weather'] ?? ''),
    ];
}
