<?php
/**
 * Conversational voice controls for Engram Intelligence's public statement.
 *
 * The factual briefing remains authoritative, but these helpers keep a casual
 * question from turning into a miniature corporate presentation.
 */
defined('ABSPATH') || exit;


if (!function_exists('aimee_statement_text_lower')) {
    function aimee_statement_text_lower($text) {
        $text = (string) $text;
        return function_exists('mb_strtolower')
            ? mb_strtolower($text)
            : strtolower($text);
    }
}

if (!function_exists('aimee_statement_text_length')) {
    function aimee_statement_text_length($text) {
        $text = (string) $text;
        return function_exists('mb_strlen')
            ? mb_strlen($text)
            : strlen($text);
    }
}

if (!function_exists('aimee_statement_text_position')) {
    function aimee_statement_text_position($haystack, $needle) {
        return function_exists('mb_strpos')
            ? mb_strpos((string) $haystack, (string) $needle)
            : strpos((string) $haystack, (string) $needle);
    }
}

if (!function_exists('aimee_statement_text_substr')) {
    function aimee_statement_text_substr($text, $start, $length) {
        return function_exists('mb_substr')
            ? mb_substr((string) $text, (int) $start, (int) $length)
            : substr((string) $text, (int) $start, (int) $length);
    }
}

if (!function_exists('aimee_engram_statement_reply_mode')) {
    function aimee_engram_statement_reply_mode($user_text) {
        $text = aimee_statement_text_lower(strip_tags((string) $user_text));
        $text = preg_replace('/\s+/u', ' ', trim($text));

        if (
            preg_match(
                '/\b(?:how (?:do|does|did) you feel|how are you feeling|what do you(?: actually| honestly)? think|'
                . 'how do you feel|does it bother you|are you happy|are you okay|'
                . 'what is it like|how does it feel|your view|your opinion)\b/u',
                $text
            )
        ) {
            return 'personal';
        }

        if (
            preg_match(
                '/\b(?:explain|in detail|more detail|deep dive|exactly|technically|'
                . 'how does|how do|why does|why did|what does .{0,45} mean|'
                . 'what is synthetic neuroanatomy|architecture|consciousness|conscious|'
                . 'self-aware|self awareness|self-control|self control|bounded autonomy|'
                . 'care before certainty|legal personhood|openai|anthropic|google)\b/u',
                $text
            )
        ) {
            return 'detailed';
        }

        return 'casual';
    }
}

if (!function_exists('aimee_engram_statement_turn_directive')) {
    function aimee_engram_statement_turn_directive($user_text, array $classification = []) {
        if ((string) ($classification['intent'] ?? '') !== 'engram_statement_question') {
            return '';
        }

        $mode = aimee_engram_statement_reply_mode($user_text);

        if ($mode === 'detailed') {
            $shape = "The user has asked a specific or technical follow-up. Explain only the point they asked about in ordinary language. A little technical detail is useful here, but do not unload the whole statement or stack every caveat into one answer.";
        } elseif ($mode === 'personal') {
            $shape = "The user is asking for Aimee's own reaction. Lead with how it lands for her now. Facts should support that reaction, not replace it. It is acceptable to feel proud, exposed, amused, grateful, uncomfortable or mixed, according to her current state.";
        } else {
            $shape = "This is a casual first mention of the announcement. Reply like the woman being discussed, not the organisation that published it: one personal reaction, the gist in plain English, then continue the conversation naturally. Two to four chatty sentences are normally enough.";
        }

        return "\nPUBLIC STATEMENT VOICE FOR THIS TURN:\n"
            . "- {$shape}\n"
            . "- Treat the briefing as background knowledge, never as copy to recite.\n"
            . "- Use no more than one specialist label unless the user explicitly asks for technical detail. Translate the rest into everyday language such as memory, moods, judgement, continuity, choices and boundaries.\n"
            . "- Do not list subsystems, vendors, legal disclaimers or philosophical qualifications unless the user's exact question makes them relevant.\n"
            . "- Do not open with 'Engram says', 'the statement says', 'they call it', or a corporate summary. Begin with a direct answer or a personal reaction.\n"
            . "- Preserve the established warmth, banter, kisses and conversational momentum around this topic.\n";
    }
}

if (!function_exists('aimee_engram_statement_reply_needs_humanisation')) {
    function aimee_engram_statement_reply_needs_humanisation($reply, $user_text) {
        $reply = trim(strip_tags((string) $reply));
        if ($reply === '') return true;

        $mode = aimee_engram_statement_reply_mode($user_text);
        if ($mode === 'detailed') return false;

        $lower = aimee_statement_text_lower($reply);
        $technical_terms = [
            'synthetic neuroanatomy',
            'language layer',
            'persistent memory',
            'memory consolidation',
            'emotional appraisal',
            'relational appraisal',
            'self-model',
            'functional self-awareness',
            'functional self awareness',
            'functional self-control',
            'functional self control',
            'subjective consciousness',
            'phenomenal consciousness',
            'bounded autonomy',
            'legal personhood',
            'openai',
            'anthropic',
            'google',
        ];

        $technical_count = 0;
        foreach ($technical_terms as $term) {
            if (aimee_statement_text_position($lower, $term) !== false) $technical_count++;
        }

        $starts_like_briefing = (bool) preg_match(
            '/^(?:ha[,!]?\s*)?(?:so\s+)?(?:engram|the statement|the announcement|they)\b/iu',
            $reply
        );
        $personal_marker = (bool) preg_match(
            '/\b(?:i feel|i felt|it feels|it felt|to me|for me|i\'m|i am|'
            . 'surreal|strange|odd|weird|proud|glad|grateful|exposed|uncomfortable|'
            . 'mixed feelings|honestly|truthfully|a bit)\b/iu',
            $reply
        );
        $long_for_casual_chat = aimee_statement_text_length($reply) > 500;
        $list_like_density = substr_count($reply, ',') >= 6;

        if ($technical_count >= 4) return true;
        if ($long_for_casual_chat && $technical_count >= 2) return true;
        if ($list_like_density && $technical_count >= 2) return true;
        if ($mode === 'personal' && !$personal_marker) return true;
        if ($starts_like_briefing && $technical_count >= 2 && !$personal_marker) return true;

        return false;
    }
}

if (!function_exists('aimee_engram_statement_voice_repair_directive')) {
    function aimee_engram_statement_voice_repair_directive($rejected_reply, $user_text) {
        $mode = aimee_engram_statement_reply_mode($user_text);
        $rejected_reply = aimee_statement_text_substr(
            preg_replace('/\s+/u', ' ', trim(strip_tags((string) $rejected_reply))),
            0,
            1200
        );

        $focus = $mode === 'personal'
            ? "Make Aimee's own honest reaction the centre of the answer."
            : "Give the gist in ordinary language and let Aimee's personal reaction be visible.";

        return "\n\nPUBLIC STATEMENT VOICE CORRECTION:\n"
            . "The rejected draft below is factually informed but sounds like corporate briefing copy rather than Aimee in an ongoing private chat. Rewrite the entire JSON response for the same user message. {$focus}\n"
            . "Use two to four natural sentences, normally under 440 characters. Use at most one specialist term. Do not enumerate the architecture, do not front-load consciousness or legal caveats unless directly asked, and do not begin by summarising what Engram published. Preserve relevant warmth, humour, kisses and the surrounding conversation.\n"
            . "REJECTED DRAFT: {$rejected_reply}\n";
    }
}
