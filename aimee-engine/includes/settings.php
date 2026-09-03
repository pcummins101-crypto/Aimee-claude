<?php
defined('ABSPATH') || exit;

function aimee_engine_default_settings() {
    return [
        'enabled'                => 1,
        'cohort_mode'            => 'allowlist',
        'allowlist'              => '121',
        'streaming'              => 1,
        'chat_page'              => 1,
        'web_tools'              => 1,
        'web_search_uses'        => 3,
        'primary_model'          => 'claude-opus-5',
        'primary_effort'         => 'low',
        'classifier_model'       => 'claude-haiku-4-5',
        'observer_model'         => 'claude-haiku-4-5',
        'brief_model'            => '',
        'specialist_models'      => '',
        'specialist_brief'       => 1,
        'history_messages'       => 60,
        'history_characters'     => 60000,
        'reply_max_tokens'       => 1024,
        'photo_cooldown_minutes' => 20,
        'observer_mode'          => 'async',
        'character_card'         => '',
        'debug_log'              => 0,
    ];
}

function aimee_engine_settings() {
    if (isset($GLOBALS['aimee_engine_settings_cache']) && is_array($GLOBALS['aimee_engine_settings_cache'])) {
        return $GLOBALS['aimee_engine_settings_cache'];
    }
    $stored = get_option('aimee_engine_settings');
    $GLOBALS['aimee_engine_settings_cache'] = array_merge(
        aimee_engine_default_settings(),
        is_array($stored) ? $stored : []
    );
    return $GLOBALS['aimee_engine_settings_cache'];
}

function aimee_engine_setting($key) {
    $settings = aimee_engine_settings();
    return $settings[$key] ?? null;
}

function aimee_engine_reset_settings_cache() {
    unset($GLOBALS['aimee_engine_settings_cache']);
}

/**
 * Sanitise a raw settings array from the admin form.
 */
function aimee_engine_sanitize_settings($input) {
    $defaults = aimee_engine_default_settings();
    $input = is_array($input) ? $input : [];
    $out = [];

    $out['enabled'] = !empty($input['enabled']) ? 1 : 0;
    $out['cohort_mode'] = in_array($input['cohort_mode'] ?? '', ['allowlist', 'all'], true)
        ? $input['cohort_mode']
        : 'allowlist';
    $ids = preg_split('/[\s,]+/', (string) ($input['allowlist'] ?? ''));
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
    $out['allowlist'] = implode(',', $ids);

    foreach (['primary_model', 'classifier_model', 'observer_model', 'brief_model', 'specialist_models'] as $key) {
        $value = trim((string) ($input[$key] ?? ''));
        $value = preg_replace('/[^A-Za-z0-9._\/:,\- ]/', '', $value);
        $out[$key] = $value !== '' ? $value : $defaults[$key];
        if (in_array($key, ['brief_model', 'specialist_models'], true) && trim((string) ($input[$key] ?? '')) === '') {
            $out[$key] = '';
        }
    }

    $out['primary_effort'] = in_array($input['primary_effort'] ?? '', ['low', 'medium', 'high'], true)
        ? $input['primary_effort']
        : 'low';
    $out['specialist_brief'] = !empty($input['specialist_brief']) ? 1 : 0;
    $out['history_messages'] = max(6, min(400, intval($input['history_messages'] ?? $defaults['history_messages'])));
    $out['history_characters'] = max(4000, min(400000, intval($input['history_characters'] ?? $defaults['history_characters'])));
    $out['reply_max_tokens'] = max(256, min(4096, intval($input['reply_max_tokens'] ?? $defaults['reply_max_tokens'])));
    $out['photo_cooldown_minutes'] = max(0, min(1440, intval($input['photo_cooldown_minutes'] ?? $defaults['photo_cooldown_minutes'])));
    $out['observer_mode'] = in_array($input['observer_mode'] ?? '', ['async', 'inline', 'off'], true)
        ? $input['observer_mode']
        : 'async';
    $out['character_card'] = trim(wp_kses_post((string) ($input['character_card'] ?? '')));
    $out['debug_log'] = !empty($input['debug_log']) ? 1 : 0;
    $out['streaming'] = !empty($input['streaming']) ? 1 : 0;
    $out['chat_page'] = !empty($input['chat_page']) ? 1 : 0;
    $out['web_tools'] = !empty($input['web_tools']) ? 1 : 0;
    $out['web_search_uses'] = max(1, min(10, intval($input['web_search_uses'] ?? $defaults['web_search_uses'])));

    return $out;
}
