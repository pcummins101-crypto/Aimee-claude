<?php
defined('ABSPATH') || exit;

add_action('admin_menu', function () {
    add_options_page('Aimee Engine', 'Aimee Engine', 'manage_options', 'aimee-engine', 'aimee_engine_admin_page');
});

add_action('admin_init', function () {
    register_setting('aimee_engine', 'aimee_engine_settings', [
        'type'              => 'array',
        'sanitize_callback' => 'aimee_engine_sanitize_settings',
        'default'           => aimee_engine_default_settings(),
    ]);
});

add_action('update_option_aimee_engine_settings', 'aimee_engine_reset_settings_cache');

function aimee_engine_admin_field($key, $label, $type = 'text', $help = '', $choices = []) {
    $settings = aimee_engine_settings();
    $value = $settings[$key] ?? '';
    $id = 'aimee_engine_' . $key;
    $name = 'aimee_engine_settings[' . $key . ']';
    echo '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td>';
    if ($type === 'checkbox') {
        echo '<label><input type="checkbox" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="1" ' . checked(!empty($value), true, false) . '> ' . esc_html($help) . '</label>';
        $help = '';
    } elseif ($type === 'select') {
        echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '">';
        foreach ($choices as $choice => $choice_label) {
            echo '<option value="' . esc_attr($choice) . '" ' . selected((string) $value, (string) $choice, false) . '>' . esc_html($choice_label) . '</option>';
        }
        echo '</select>';
    } elseif ($type === 'textarea') {
        echo '<textarea id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" rows="14" class="large-text code">' . esc_textarea((string) $value) . '</textarea>';
    } elseif ($type === 'number') {
        echo '<input type="number" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '" class="small-text">';
    } else {
        echo '<input type="text" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '" class="regular-text">';
    }
    if ($help !== '') echo '<p class="description">' . esc_html($help) . '</p>';
    echo '</td></tr>';
}

function aimee_engine_admin_page() {
    if (!current_user_can('manage_options')) return;
    $missing = aimee_engine_dependencies_missing();
    $anthropic = defined('ANTHROPIC_API_KEY') && trim((string) ANTHROPIC_API_KEY) !== '';
    $openrouter = defined('OPENROUTER_API_KEY') && trim((string) OPENROUTER_API_KEY) !== '';
    ?>
    <div class="wrap">
        <h1>Aimee Engine <small style="font-weight:normal;color:#666">v<?php echo esc_html(AIMEE_ENGINE_VERSION); ?></small></h1>
        <p>A prompt-light conversation engine that runs alongside Aimee Global. It takes over <code>POST /aimee/v1/message</code> for enrolled users only; every other page, endpoint and channel (SMS, voice, gallery, billing) stays with Aimee Global.</p>

        <h2>Status</h2>
        <table class="widefat striped" style="max-width:900px">
            <tbody>
            <tr><td>Aimee Global functions</td><td><?php echo $missing ? '<span style="color:#b32d2e">Missing: ' . esc_html(implode(', ', $missing)) . '</span> (Aimee Global loads its engine lazily; this is normal on some admin screens. The engine checks again on every chat request.)' : '<span style="color:#1a7f37">All present</span>'; ?></td></tr>
            <tr><td>Anthropic key</td><td><?php echo $anthropic ? '<span style="color:#1a7f37">Configured</span>' : '<span style="color:#b32d2e">Missing (ANTHROPIC_API_KEY in wp-config.php)</span>'; ?></td></tr>
            <tr><td>OpenRouter key (explicit specialist)</td><td><?php echo $openrouter ? '<span style="color:#1a7f37">Configured</span>' : '<span style="color:#b32d2e">Missing (OPENROUTER_API_KEY). Explicit turns will stay on the primary model and be steered rather than written.</span>'; ?></td></tr>
            <tr><td>Specialist models in use</td><td><code><?php echo esc_html(implode(', ', aimee_engine_specialist_models())); ?></code></td></tr>
            <tr><td>Engine active for chat</td><td><?php echo (aimee_engine_setting('enabled') && $anthropic && !$missing) ? '<strong style="color:#1a7f37">Yes, for enrolled users</strong>' : 'No'; ?></td></tr>
            </tbody>
        </table>

        <form method="post" action="options.php">
            <?php settings_fields('aimee_engine'); ?>
            <h2>Rollout</h2>
            <table class="form-table" role="presentation">
                <?php
                aimee_engine_admin_field('enabled', 'Enable engine', 'checkbox', 'Switch the new engine on. Nothing changes for users who are not enrolled.');
                aimee_engine_admin_field('cohort_mode', 'Who is enrolled', 'select', 'Per-user overrides on the user profile screen win over this setting.', [
                    'allowlist' => 'Only the allowlist and per-user opt-ins',
                    'all'       => 'Everyone except per-user opt-outs',
                ]);
                aimee_engine_admin_field('allowlist', 'Allowlist user IDs', 'text', 'Comma-separated WordPress user IDs to route through the new engine.');
                aimee_engine_admin_field('chat_page', 'Serve the engine chat page', 'checkbox', 'Enrolled users get Aimee Engine\'s own chat page (streaming replies, mobile menu, membership panel). Untick to keep the theme app and only swap the reply engine.');
                aimee_engine_admin_field('streaming', 'Stream replies', 'checkbox', 'Send her words as they are written. Needs PHP cURL; falls back to a single reply automatically.');
                ?>
            </table>

            <h2>Models</h2>
            <table class="form-table" role="presentation">
                <?php
                aimee_engine_admin_field('primary_model', 'Primary conversation model', 'text', 'Claude model ID. Default claude-opus-5.');
                aimee_engine_admin_field('primary_effort', 'Primary effort', 'select', 'Low is the fast conversational setting. Raise it if replies feel thin.', ['low' => 'low', 'medium' => 'medium', 'high' => 'high']);
                aimee_engine_admin_field('classifier_model', 'Classifier model', 'text', 'Routes each message: everyday, erotic, abusive, unsafe. Default claude-haiku-4-5.');
                aimee_engine_admin_field('observer_model', 'Observer model', 'text', 'Writes memory, opinions and self-observation after each turn. Default claude-haiku-4-5.');
                aimee_engine_admin_field('brief_model', 'Brief model', 'text', 'Writes Aimee\'s private notes for the explicit specialist. Leave empty to use the primary model.');
                aimee_engine_admin_field('specialist_models', 'Explicit specialist models', 'text', 'Comma-separated OpenRouter model IDs tried in order. Leave empty to inherit Aimee Global\'s AIMEE_INTIMACY_MODEL configuration.');
                aimee_engine_admin_field('specialist_brief', 'Brief the specialist', 'checkbox', 'Before an explicit turn, have Claude write short private notes (mood, callbacks, pet names) so the specialist keeps her continuity. Adds one fast call.');
                ?>
            </table>

            <h2>Conversation</h2>
            <table class="form-table" role="presentation">
                <?php
                aimee_engine_admin_field('history_messages', 'History messages', 'number', 'How many stored messages to send as the transcript (6 to 400).');
                aimee_engine_admin_field('history_characters', 'History character cap', 'number', 'Oldest messages are dropped once the transcript exceeds this many characters.');
                aimee_engine_admin_field('reply_max_tokens', 'Reply max tokens', 'number', 'Hard ceiling for one reply. 1024 is plenty for a text-message voice.');
                aimee_engine_admin_field('photo_cooldown_minutes', 'Photo cooldown (minutes)', 'number', 'After a photo is shared, no photo is offered again for this long. 0 disables.');
                aimee_engine_admin_field('observer_mode', 'Observer mode', 'select', 'Async uses WP-Cron a few seconds after the turn. Inline runs before the reply is returned (slower, but deterministic for testing).', ['async' => 'async', 'inline' => 'inline', 'off' => 'off']);
                aimee_engine_admin_field('character_card', 'Character card override', 'textarea', 'Leave empty to use the built-in card. This is who she is, written as facts, not a list of rules.');
                aimee_engine_admin_field('debug_log', 'Keep recent turn telemetry', 'checkbox', 'Store the last 40 turns (routes, models, timings; never message text) for the table below.');
                ?>
            </table>
            <?php submit_button('Save settings'); ?>
        </form>

        <h2>Bank checkout diagnostics</h2>
        <p>GoCardless errors captured from Aimee Global's checkout, status, cancel and portal calls. Aimee Global reports a failed create as "ambiguous"; the provider's actual reason is recorded here. Tokens and bank details are never stored.</p>
        <?php $gc = aimee_engine_gc_diagnostics(); if ($gc): ?>
        <table class="widefat striped" style="max-width:1100px">
            <thead><tr><th>When (UTC)</th><th>User</th><th>Request</th><th>Status</th><th>Type</th><th>Message</th><th>Field errors</th></tr></thead>
            <tbody>
            <?php foreach ($gc as $row): ?>
                <tr>
                    <td><?php echo esc_html($row['at'] ?? ''); ?></td>
                    <td><?php echo intval($row['user_id'] ?? 0); ?></td>
                    <td><code><?php echo esc_html(($row['method'] ?? '') . ' ' . ($row['path'] ?? '')); ?></code></td>
                    <td><?php echo intval($row['status'] ?? 0) ?: 'none'; ?></td>
                    <td><?php echo esc_html($row['type'] ?? ''); ?></td>
                    <td><?php echo esc_html($row['message'] ?? ''); ?></td>
                    <td><?php echo esc_html(implode('; ', (array) ($row['fields'] ?? []))); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px">
            <input type="hidden" name="action" value="aimee_engine_clear_gc_diagnostics">
            <?php wp_nonce_field('aimee_engine_clear_gc'); ?>
            <?php submit_button('Clear diagnostics', 'secondary', 'submit', false); ?>
        </form>
        <?php else: ?>
        <p><em>No GoCardless errors recorded since activation. Try the checkout again and refresh this page.</em></p>
        <?php endif; ?>

        <h2>Built-in character card</h2>
        <details><summary>Show</summary><pre style="white-space:pre-wrap;background:#fff;padding:12px;border:1px solid #ddd;max-width:900px"><?php echo esc_html(aimee_engine_default_character_card()); ?></pre></details>

        <?php $recent = aimee_engine_recent_turns(); if ($recent): ?>
        <h2>Recent turns</h2>
        <table class="widefat striped">
            <thead><tr><th>When (UTC)</th><th>User</th><th>Route</th><th>Classifier</th><th>Model</th><th>Refusal</th><th>Photo</th><th>Stage</th><th>Classifier ms</th><th>Generate ms</th><th>Other ms</th><th>Total ms</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $turn): ?>
                <tr>
                    <td><?php echo esc_html($turn['at'] ?? ''); ?></td>
                    <td><?php echo intval($turn['user_id'] ?? 0); ?></td>
                    <td><?php echo esc_html($turn['route'] ?? ''); ?></td>
                    <td><?php echo esc_html(($turn['classifier_route'] ?? '') . ' / ' . ($turn['classifier_tone'] ?? '')); ?></td>
                    <td><?php echo esc_html($turn['actual_model'] ?? ''); ?></td>
                    <td><?php echo esc_html($turn['refusal_category'] ?? ''); ?></td>
                    <td><?php echo esc_html(($turn['photo_key'] ?? '') ?: (intval($turn['photo_offered'] ?? 0) ? 'offered ' . intval($turn['photo_offered']) : '')); ?></td>
                    <td><?php echo esc_html(($turn['stage'] ?? '') . ' ' . intval($turn['score'] ?? 0)); ?></td>
                    <?php $t = is_array($turn['timings'] ?? null) ? $turn['timings'] : []; $total = intval($turn['total_ms'] ?? 0); ?>
                    <td><?php echo intval($t['classify_ms'] ?? 0); ?></td>
                    <td><?php echo intval($t['generate_ms'] ?? 0); ?></td>
                    <td><?php echo max(0, $total - intval($t['classify_ms'] ?? 0) - intval($t['generate_ms'] ?? 0)); ?></td>
                    <td><?php echo $total; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Per-user enrolment on the WordPress user profile screen.
 */
function aimee_engine_user_profile_field($user) {
    if (!current_user_can('manage_options')) return;
    $flag = aimee_engine_user_flag($user->ID);
    ?>
    <h2>Aimee Engine</h2>
    <table class="form-table" role="presentation">
        <tr>
            <th><label for="aimee_engine_v2">Chat engine</label></th>
            <td>
                <select name="aimee_engine_v2" id="aimee_engine_v2">
                    <option value="" <?php selected($flag, ''); ?>>Follow the global rollout setting</option>
                    <option value="1" <?php selected($flag, '1'); ?>>Always use Aimee Engine (new)</option>
                    <option value="0" <?php selected($flag, '0'); ?>>Always use Aimee Global (legacy)</option>
                </select>
                <p class="description">Only affects in-app chat. SMS and voice always use Aimee Global.</p>
            </td>
        </tr>
    </table>
    <?php
}
add_action('show_user_profile', 'aimee_engine_user_profile_field');
add_action('edit_user_profile', 'aimee_engine_user_profile_field');

function aimee_engine_user_profile_save($user_id) {
    if (!current_user_can('manage_options')) return;
    if (!isset($_POST['aimee_engine_v2'])) return;
    $flag = sanitize_text_field(wp_unslash($_POST['aimee_engine_v2']));
    if (in_array($flag, ['0', '1'], true)) {
        update_user_meta($user_id, 'aimee_engine_v2', $flag);
    } else {
        delete_user_meta($user_id, 'aimee_engine_v2');
    }
}
add_action('personal_options_update', 'aimee_engine_user_profile_save');
add_action('edit_user_profile_update', 'aimee_engine_user_profile_save');
