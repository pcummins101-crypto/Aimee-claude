<?php
defined('ABSPATH') || exit;
add_action('admin_menu', function () {
    add_options_page('Aimee Global', 'Aimee Global', 'manage_options', 'aimee-global', 'aimee_global_admin_page');
});
add_action('admin_init', function () {
    foreach (['uk','us'] as $m) foreach (['weekly_minor','monthly_minor','annual_minor','sms_minor'] as $k) register_setting('aimee_global', "aimee_global_{$m}_{$k}", ['type'=>'integer','sanitize_callback'=>'absint']);
    register_setting('aimee_global', 'aimee_global_default_market', ['sanitize_callback'=>function($v){return $v==='us'?'us':'uk';}]);
    register_setting('aimee_global', 'aimee_global_privacy_email', ['sanitize_callback'=>'sanitize_email']);
    register_setting('aimee_global', 'aimee_global_policy_status', ['sanitize_callback'=>'sanitize_text_field']);
});

/**
 * Return aggregate, latest-response-per-user totals for the 1.7.1 banner.
 * Individual identities and conversation content are omitted from the view.
 */
function aimee_global_release_feedback_summary($release = '1.7.1') {
    global $wpdb;

    $summary = [
        'release'      => $release,
        'total'        => 0,
        'feels_better' => 0,
        'needs_work'   => 0,
        'latest_at'    => '',
        'storage_ready'=> false,
    ];
    if (!function_exists('aimee_table')) return $summary;

    $event_name = 'aimee_171_feedback';
    $table = aimee_table('aimee_analytics_events');
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT feedback.user_id, feedback.properties, feedback.occurred_at
         FROM {$table} feedback
         INNER JOIN (
             SELECT user_id, MAX(id) AS latest_id
             FROM {$table}
             WHERE event_name = %s
             GROUP BY user_id
         ) latest ON latest.latest_id = feedback.id
         WHERE feedback.event_name = %s
         ORDER BY feedback.occurred_at DESC",
        $event_name,
        $event_name
    ));
    if (!is_array($rows)) return $summary;
    $summary['storage_ready'] = true;

    foreach ($rows as $row) {
        $properties = json_decode((string) ($row->properties ?? ''), true);
        if (!is_array($properties) || ($properties['release'] ?? '') !== $release) continue;
        $response = sanitize_key($properties['response'] ?? '');
        if (!in_array($response, ['feels_better', 'needs_work'], true)) continue;
        $summary[$response]++;
        $summary['total']++;
        if ($summary['latest_at'] === '' && !empty($row->occurred_at)) {
            $summary['latest_at'] = (string) $row->occurred_at;
        }
    }

    return $summary;
}

function aimee_global_admin_page() {
    if (!current_user_can('manage_options')) return;
    if (!empty($_POST['aimee_repair_pages']) && check_admin_referer('aimee_repair_pages')) { aimee_global_create_pages(); echo '<div class="notice notice-success"><p>Aimee pages created or repaired.</p></div>'; }
    $legacy = function_exists('aimee_table') && empty($GLOBALS['aimee_global_engine_loaded']);
    $ui_status = function_exists('aimee_global_legacy_chat_status') ? aimee_global_legacy_chat_status() : ['uk'=>'','us'=>''];
    $migration = function_exists('aimee_global_billing_migration_summary') ? aimee_global_billing_migration_summary() : [];
    $period_repair = function_exists('aimee_global_billing_period_repair_summary') ? aimee_global_billing_period_repair_summary() : [];
    $service_grace = function_exists('aimee_global_service_grace_summary') ? aimee_global_service_grace_summary() : [];
    $service_grace_policy = function_exists('aimee_global_service_grace_policy') ? aimee_global_service_grace_policy() : [];
    $service_grace_profiles = 0;
    if (function_exists('aimee_global_service_grace_policy')) {
        global $wpdb;
        $service_grace_profiles = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM aimee_user_profiles WHERE service_grace_code = %s",
            $service_grace_policy['id'] ?? ''
        ));
    }
    $release_feedback = aimee_global_release_feedback_summary('1.7.1');
    $release_feedback_has_responses = (int) $release_feedback['total'] > 0;
    $registration_failure = function_exists('aimee_registration_diagnostic_option_name')
        ? get_option(aimee_registration_diagnostic_option_name(), [])
        : [];
    if (!is_array($registration_failure)) $registration_failure = [];
    $ni_bond_repair = function_exists('aimee_ni_bond_repair_summary_189')
        ? aimee_ni_bond_repair_summary_189()
        : [];
    $public_media_status = function_exists('aimee_private_media_public_catalogue_status')
        ? aimee_private_media_public_catalogue_status()
        : ['enabled' => false, 'healthy' => false];
    $public_media_enabled = !empty($public_media_status['enabled']);
    // This page has just performed a live catalogue scan. The short-lived
    // migration record is an optimisation/finalisation receipt, not a reason
    // to label independently validated application images unavailable.
    $public_media_operational = !empty($public_media_status['operational']);
    $public_media_complete = !empty($public_media_status['healthy']);
    $public_media_skipped_keys = array_unique(array_filter(array_merge(
        (array) ($public_media_status['invalid_entries'] ?? []),
        (array) ($public_media_status['missing_files'] ?? []),
        (array) ($public_media_status['required_keys_missing'] ?? [])
    ), 'is_string'));
    $public_media_skipped = count($public_media_skipped_keys);
    if ($public_media_complete) {
        $public_media_readiness_html = '<span style="color:#067647">Ready</span>';
    } elseif ($public_media_operational) {
        $public_media_readiness_html = '<span style="color:#b54708">Available — '
            . intval($public_media_skipped) . ' unavailable item'
            . (intval($public_media_skipped) === 1 ? '' : 's') . ' skipped</span>';
    } else {
        $public_media_readiness_html = '<span style="color:#b42318">Not ready</span>';
    }
    $media_check = null;
    $media_check_user = isset($_POST['aimee_media_user_id']) ? absint($_POST['aimee_media_user_id']) : 100;
    $media_check_key = isset($_POST['aimee_media_key']) ? sanitize_key(wp_unslash($_POST['aimee_media_key'])) : 'black_lingerie_mirror_selfie_01';

    if (!empty($_POST['aimee_check_media']) && check_admin_referer('aimee_check_media')) {
        global $wpdb;
        if (
            function_exists('aimee_private_media_catalog')
            && function_exists('aimee_private_media_path')
            && function_exists('aimee_media_item_is_viewable')
        ) {
            $catalog = aimee_private_media_catalog();
            $profile = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM " . aimee_table('aimee_user_profiles') . " WHERE user_id = %d",
                $media_check_user
            ));
            $repair_attempted = false;
            if (isset($catalog[$media_check_key]) && function_exists('aimee_repair_private_media_asset')) {
                $repair_attempted = true;
                aimee_repair_private_media_asset($media_check_key, $catalog[$media_check_key]);
            }
            $path = isset($catalog[$media_check_key]) ? aimee_private_media_path($media_check_key) : null;
            $unlocked = function_exists('aimee_user_has_unlocked_media')
                ? aimee_user_has_unlocked_media($media_check_user, $media_check_key)
                : false;
            $active = function_exists('aimee_subscription_is_active')
                ? aimee_subscription_is_active($profile)
                : false;
            $viewable = $profile && isset($catalog[$media_check_key])
                ? aimee_media_item_is_viewable($media_check_user, $media_check_key, $profile)
                : false;
            $media_check = [
                'catalogue' => isset($catalog[$media_check_key]),
                'path' => $path,
                'repair_attempted' => $repair_attempted,
                'profile' => $profile,
                'active' => $active,
                'unlocked' => $unlocked,
                'viewable' => $viewable,
            ];
        }
    }
    ?>
    <div class="wrap"><h1>Aimee Global</h1><p>One British Aimee, two market journeys. Prices are stored in minor currency units, so 699 means £6.99 or $6.99.</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:18px;max-width:1100px">
    <div class="card"><h2>System status</h2><p><strong>Plugin build:</strong> <code><?php echo esc_html(AIMEE_GLOBAL_VERSION); ?></code><br><strong>Schema:</strong> <code><?php echo esc_html(AIMEE_GLOBAL_SCHEMA_VERSION); ?></code><br><strong>Engine:</strong> <?php echo $legacy ? 'Legacy theme engine detected' : 'Plugin engine ready'; ?></p><p><strong>Original UK UI:</strong> <?php echo !empty($ui_status['uk']) ? '<span style="color:#067647">Detected</span>' : '<span style="color:#b42318">Not found</span>'; ?><br><strong>Dedicated US UI source:</strong> <?php echo !empty($ui_status['us']) ? '<span style="color:#067647">Detected</span>' : 'Using an independent US market render of the canonical UI'; ?></p><p><strong>UK chat:</strong> <a href="<?php echo esc_url(aimee_global_route('chat','uk')); ?>">Open</a><br><strong>US chat:</strong> <a href="<?php echo esc_url(aimee_global_route('chat','us')); ?>">Open</a></p><hr><h3>Last signup diagnostic</h3><?php if (!empty($registration_failure['reference'])): ?><p><strong>Time:</strong> <?php echo esc_html((string) ($registration_failure['occurred_at'] ?? 'Unknown')); ?> UTC<br><strong>Reference:</strong> <code><?php echo esc_html((string) $registration_failure['reference']); ?></code><br><strong>Stage:</strong> <code><?php echo esc_html((string) ($registration_failure['stage'] ?? 'unknown')); ?></code><br><strong>Code:</strong> <code><?php echo esc_html((string) ($registration_failure['error_code'] ?? 'unspecified_operational_failure')); ?></code></p><?php else: ?><p><span style="color:#067647">No operational signup issue has been recorded.</span></p><?php endif; ?><p><small>This diagnostic never stores the Login ID, email address, mobile number, PIN, uploaded photo or raw database error.</small></p></div>
    <div class="card"><h2>Ni relationship restoration</h2><?php if (($ni_bond_repair['status'] ?? '') === 'complete'): ?><p><strong>Status:</strong> <span style="color:#067647">Complete</span><br><strong>User:</strong> <code><?php echo esc_html((int) ($ni_bond_repair['user_id'] ?? 27)); ?></code><br><strong>Relationship:</strong> <code><?php echo esc_html((int) ($ni_bond_repair['score_after'] ?? 100)); ?>/100</code>, <code><?php echo esc_html((string) ($ni_bond_repair['stage_after'] ?? 'bonded')); ?></code><br><strong>False rupture events corrected:</strong> <?php echo esc_html((int) ($ni_bond_repair['rupture_events_corrected'] ?? 0)); ?><br><strong>Completed:</strong> <?php echo esc_html((string) ($ni_bond_repair['completed_at'] ?? '')); ?> UTC</p><p><small>Trust, affection, chemistry, safety, reciprocity and reliability were restored to 100; frustration and the false rupture were cleared. Adult assurance, privacy consent, membership and current-turn consent were not changed.</small></p><?php else: ?><p><strong>Status:</strong> <span style="color:#b54708">Pending</span></p><p><small>The evidence-bound repair will retry automatically. If this remains pending, confirm the relationship decision and inner-life tables are healthy.</small></p><?php endif; ?></div>
    <div class="card"><h2>Checkout policy</h2><p><strong>New UK memberships:</strong> <span style="color:#067647">GoCardless only</span> (<?php echo function_exists('aimee_gocardless_mandate_scheme') && aimee_gocardless_mandate_scheme() === 'faster_payments' ? 'Faster Payments VRP mandate' : 'Bacs Direct Debit mandate'; ?>)<br><strong>New US memberships:</strong> <span style="color:#b42318">Unavailable</span><br><strong>New SMS bundles:</strong> <span style="color:#b42318">Unavailable</span><br><strong>Stripe:</strong> Legacy runoff only</p><p><small>Stripe credentials and webhooks are retained only to manage, reconcile and close payment records created before this cutover. They must not be used to create a new checkout.</small></p></div>
    <div class="card"><h2>August service grace</h2><p><strong>Policy:</strong> <code><?php echo esc_html((string) ($service_grace_policy['id'] ?? 'unavailable')); ?></code><br><strong>Profiles enrolled:</strong> <?php echo esc_html($service_grace_profiles); ?><br><strong>Access ends:</strong> <?php echo esc_html((string) ($service_grace_policy['ends_at_local'] ?? 'Unavailable')); ?><br><strong>Automatic payment scheduled:</strong> <span style="color:#067647">No</span></p><p>Full in-app access is complimentary through 31 August. The closed Stripe subscriptions remain historical only. After preserved access ends, eligible UK profiles may explicitly create a replacement membership through GoCardless. New US paid checkout is unavailable.</p><p><small>Trial counters, relationship state, adult assurance and media-consent safeguards are not changed. Carrier SMS is excluded from the complimentary grant, and no new SMS bundle checkout is offered.</small></p><?php if (!empty($service_grace['completed_at'])): ?><p><small>Last grant reconciliation: <?php echo esc_html((string) $service_grace['completed_at']); ?> UTC; rows updated: <?php echo esc_html((int) ($service_grace['profiles_granted'] ?? 0)); ?>.</small></p><?php endif; ?></div>
    <div class="card"><h2>Aimee 1.7.1 feedback</h2><p>Latest one-tap response from each signed-in user. No message text or conversation content is collected.</p><p><strong>Feels better:</strong> <?php echo esc_html((int) $release_feedback['feels_better']); ?><br><strong>Needs work:</strong> <?php echo esc_html((int) $release_feedback['needs_work']); ?><br><strong>Total responses:</strong> <?php echo esc_html((int) $release_feedback['total']); ?></p><?php if (empty($release_feedback['storage_ready'])): ?><p style="color:#b42318"><strong>Feedback storage is not available.</strong> Confirm that the plugin engine and analytics table are active.</p><?php elseif (!$release_feedback_has_responses): ?><p><em>No responses recorded yet. The feedback card is active.</em></p><?php endif; ?><?php if (!empty($release_feedback['latest_at'])): ?><p><small>Latest response: <?php echo esc_html((string) $release_feedback['latest_at']); ?> UTC</small></p><?php endif; ?></div>
    <div class="card"><h2>Catalogue media health</h2>
    <?php if ($public_media_enabled): ?>
        <p>
            <strong>Storage mode:</strong> <span style="color:#b54708">Operator-approved wp-content catalogue</span><br>
            <strong>Directory:</strong> <code><?php echo esc_html((string) ($public_media_status['directory'] ?? 'Unavailable')); ?></code><br>
            <strong>Manifest:</strong> <code><?php echo esc_html((string) ($public_media_status['catalog_path'] ?? 'Unavailable')); ?></code><br>
            <strong>Manifest records:</strong> <?php echo esc_html((int) ($public_media_status['manifest_entries'] ?? 0)); ?><br>
            <strong>Validated image files:</strong> <?php echo esc_html((int) ($public_media_status['files_ready'] ?? 0)); ?><br>
            <strong>Records with SHA-256:</strong> <?php echo esc_html((int) ($public_media_status['hashes_declared'] ?? 0)); ?><br>
            <strong>Readiness:</strong> <?php echo wp_kses_post($public_media_readiness_html); ?>
            <?php if (!$public_media_operational && !empty($public_media_status['error_code'])): ?>
                <br><strong>Failure:</strong> <code><?php echo esc_html((string) $public_media_status['error_code']); ?></code>
            <?php endif; ?>
        </p>
        <p><small>The plugin reads these files in place and does not migrate, chmod, rewrite or delete them. In-app images are streamed through Aimee’s authenticated controller, so a historic web-server deny rule does not break the gallery. If the server separately permits direct static URLs, those URLs may still be public. Declared SHA-256 values are enforced; legacy records without hashes still receive file-size, MIME, image-dimension, basename, root-containment and symlink checks.</small></p>
        <?php if (!empty($public_media_status['invalid_entries'])): ?>
            <p style="color:#b54708"><strong>Invalid manifest keys skipped:</strong> <?php echo esc_html(implode(', ', array_slice((array) $public_media_status['invalid_entries'], 0, 12))); ?></p>
        <?php endif; ?>
        <?php if (!empty($public_media_status['missing_files'])): ?>
            <p style="color:#b54708"><strong>Missing or invalid files skipped:</strong> <?php echo esc_html(implode(', ', array_slice((array) $public_media_status['missing_files'], 0, 12))); ?></p>
        <?php endif; ?>
        <?php if (!empty($public_media_status['required_keys_missing'])): ?>
            <p style="color:#b54708"><strong>Required keys missing:</strong> <?php echo esc_html(implode(', ', (array) $public_media_status['required_keys_missing'])); ?></p>
        <?php endif; ?>
    <?php else: ?>
        <p><strong>Storage mode:</strong> Protected private catalogue (default)</p>
    <?php endif; ?>
    <p>Check whether a catalogue key exists, its image file is present, and a particular user is entitled to use it in Aimee.</p>
    <form method="post" style="display:grid;gap:10px"><?php wp_nonce_field('aimee_check_media'); ?><input type="hidden" name="aimee_check_media" value="1"><label><strong>User ID</strong><br><input type="number" min="1" name="aimee_media_user_id" value="<?php echo esc_attr($media_check_user); ?>"></label><label><strong>Media key</strong><br><input class="regular-text" name="aimee_media_key" value="<?php echo esc_attr($media_check_key); ?>"></label><?php submit_button($public_media_enabled ? 'Check media' : 'Check and repair media','secondary','submit',false); ?></form>
    <?php if (is_array($media_check)): ?><hr><p><strong>Catalogue entry:</strong> <?php echo $media_check['catalogue'] ? '<span style="color:#067647">Found</span>' : '<span style="color:#b42318">Missing</span>'; ?><br><strong>Image file:</strong> <?php echo $media_check['path'] ? '<span style="color:#067647">Found</span><br><code>'.esc_html($media_check['path']).'</code>' : '<span style="color:#b42318">Missing</span>'; ?><br><strong>Profile:</strong> <?php echo $media_check['profile'] ? 'Found' : '<span style="color:#b42318">Missing</span>'; ?><br><strong>Membership active:</strong> <?php echo $media_check['active'] ? '<span style="color:#067647">Yes</span>' : '<span style="color:#b42318">No</span>'; ?><br><strong>Sent/unlocked row:</strong> <?php echo $media_check['unlocked'] ? '<span style="color:#067647">Yes</span>' : '<span style="color:#b42318">No</span>'; ?><br><strong>Viewable now:</strong> <?php echo $media_check['viewable'] ? '<span style="color:#067647">Yes</span>' : '<span style="color:#b42318">No</span>'; ?></p><?php if ($media_check['profile']): ?><p><small>Stored processor status: <code><?php echo esc_html((string) ($media_check['profile']->subscription_status ?? '')); ?></code>; stored processor period end: <code><?php echo esc_html((string) ($media_check['profile']->subscription_current_period_end ?? '')); ?></code></small></p><?php endif; ?><?php endif; ?></div>
    <div class="card"><h2>Important cutover note</h2><p>Before relying on UK GoCardless checkout, remove the old Aimee section from the active theme’s <code>functions.php</code>. The plugin deliberately avoids a fatal collision while you migrate.</p><p><small>Inventory and expire any open pre-cutover Stripe Checkout sessions. Keep legacy Stripe reconciliation and management available until those records are fully resolved.</small></p></div>
    <div class="card"><h2>Closed payment-account migration</h2><?php if (!empty($migration['completed_at'])): ?><p><strong>Archived profiles:</strong> <?php echo esc_html((int) ($migration['archived_profiles'] ?? 0)); ?><br><strong>Members asked to reconnect:</strong> <?php echo esc_html((int) ($migration['reactivation_profiles'] ?? $migration['profiles'] ?? 0)); ?><br><strong>Completed:</strong> <?php echo esc_html((string) $migration['completed_at']); ?> UTC</p><p>Former payment IDs are retained only in legacy audit fields and are never sent to the new account.</p><?php else: ?><p>The one-time migration has not yet completed.</p><?php endif; ?><?php if (!empty($period_repair['completed_at'])): ?><hr><p><strong>Paid-through dates repaired:</strong> <?php echo esc_html((int) ($period_repair['repaired_profiles'] ?? 0)); ?><br><strong>Open checkouts expired:</strong> <?php echo esc_html((int) ($period_repair['pending_sessions_expired'] ?? 0)); ?><br><strong>Completed checkouts adjusted:</strong> <?php echo esc_html((int) ($period_repair['completed_sessions_adjusted'] ?? 0)); ?></p><?php if (!empty($period_repair['manual_review_user_ids'])): ?><p style="color:#b42318"><strong>Manual review:</strong> user IDs <?php echo esc_html(implode(', ', array_map('intval', $period_repair['manual_review_user_ids']))); ?></p><?php endif; ?><?php endif; ?></div></div>
    <form method="post" action="options.php"><?php settings_fields('aimee_global'); ?>
    <table class="form-table"><tr><th>Default market</th><td><select name="aimee_global_default_market"><option value="uk" <?php selected(get_option('aimee_global_default_market','uk'),'uk'); ?>>United Kingdom</option><option value="us" <?php selected(get_option('aimee_global_default_market','uk'),'us'); ?>>United States</option></select></td></tr>
    <tr><th>Privacy email</th><td><input class="regular-text" type="email" name="aimee_global_privacy_email" value="<?php echo esc_attr(get_option('aimee_global_privacy_email','privacy@engramintelligence.com')); ?>"></td></tr>
    <tr><th>Policy status</th><td><input class="regular-text" name="aimee_global_policy_status" value="<?php echo esc_attr(get_option('aimee_global_policy_status','Draft v1.1')); ?>"></td></tr></table>
    <?php foreach (['uk'=>'UK / GBP (GoCardless membership checkout)','us'=>'US / USD reference prices (new paid checkout unavailable)'] as $m=>$label): ?><h2><?php echo esc_html($label); ?></h2><table class="form-table"><?php foreach (['weekly_minor'=>'Weekly','monthly_minor'=>'Monthly','annual_minor'=>'Annual','sms_minor'=>'SMS bundle (legacy reference; no new checkout)'] as $k=>$name): ?><tr><th><?php echo esc_html($name); ?></th><td><input type="number" min="100" name="aimee_global_<?php echo $m.'_'.$k; ?>" value="<?php echo esc_attr(get_option('aimee_global_'.$m.'_'.$k, $k==='weekly_minor'?699:($k==='monthly_minor'?1999:($k==='annual_minor'?14900:599)))); ?>"></td></tr><?php endforeach; ?></table><?php endforeach; ?>
    <?php submit_button(); ?></form>
    <form method="post"><?php wp_nonce_field('aimee_repair_pages'); ?><input type="hidden" name="aimee_repair_pages" value="1"><?php submit_button('Create or repair Aimee pages','secondary'); ?></form></div><?php
}
