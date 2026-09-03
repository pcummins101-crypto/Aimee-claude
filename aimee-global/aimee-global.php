<?php
/**
 * Plugin Name: Aimee Global
 * Plugin URI: https://aimee-ai.com
 * Description: Aimee's UK and US companion platform with persistent inner life, self-observation, self-control, relational memory, chat, voice, membership, PWA, privacy, gallery and governance.
 * Version: 1.8.11
 * Author: Engram Intelligence
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Text Domain: aimee-global
 */

defined('ABSPATH') || exit;

define('AIMEE_GLOBAL_VERSION', '1.8.11');
define('AIMEE_GLOBAL_SCHEMA_VERSION', '2026.08.20.3');
define('AIMEE_GLOBAL_FILE', __FILE__);
define('AIMEE_GLOBAL_DIR', plugin_dir_path(__FILE__));
define('AIMEE_GLOBAL_URL', plugin_dir_url(__FILE__));

/**
 * Unicode-sensitive policy checks and private-media byte validation require
 * these PHP capabilities. Fail closed before registering routes or workers;
 * byte-oriented fallbacks would change security decisions for non-ASCII text.
 */
function aimee_global_runtime_requirements_missing() {
    $missing = [];
    if (!extension_loaded('mbstring')) $missing[] = 'mbstring';
    if (!class_exists('finfo')) $missing[] = 'fileinfo';
    if (!function_exists('getimagesize') || !function_exists('getimagesizefromstring')) {
        $missing[] = 'PHP image functions';
    }
    return array_values(array_unique($missing));
}

function aimee_global_runtime_requirements_ready() {
    return aimee_global_runtime_requirements_missing() === [];
}

if (!aimee_global_runtime_requirements_ready()) {
    $GLOBALS['aimee_global_runtime_requirements_missing'] =
        aimee_global_runtime_requirements_missing();

    register_activation_hook(__FILE__, function () {
        $missing = implode(', ', aimee_global_runtime_requirements_missing());
        if (function_exists('deactivate_plugins')) {
            deactivate_plugins(plugin_basename(__FILE__));
        }
        wp_die(
            esc_html('Aimee Global was not activated. Enable these required PHP capabilities first: ' . $missing . '.'),
            esc_html__('Aimee Global requirements not met', 'aimee-global'),
            ['back_link' => true]
        );
    });

    add_action('admin_notices', function () {
        if (!current_user_can('activate_plugins')) return;
        $missing = implode(', ', aimee_global_runtime_requirements_missing());
        echo '<div class="notice notice-error"><p>'
            . esc_html('Aimee Global is disabled until these required PHP capabilities are enabled: ' . $missing . '.')
            . '</p></div>';
    });

    // Nothing below is safe to register on an incomplete runtime.
    return;
}

// Privileged identities are deployment configuration, never portable package
// defaults. AIMEE_GEORGIA_USER_ID must be explicitly bound in wp-config.php.

require_once AIMEE_GLOBAL_DIR . 'includes/market.php';
require_once AIMEE_GLOBAL_DIR . 'includes/schema.php';
require_once AIMEE_GLOBAL_DIR . 'includes/user-image-events.php';
require_once AIMEE_GLOBAL_DIR . 'includes/security-privacy.php';
require_once AIMEE_GLOBAL_DIR . 'includes/relationship-policy.php';
require_once AIMEE_GLOBAL_DIR . 'includes/romantic-expression.php';
require_once AIMEE_GLOBAL_DIR . 'includes/media-decision.php';
require_once AIMEE_GLOBAL_DIR . 'includes/media-cadence.php';
require_once AIMEE_GLOBAL_DIR . 'includes/media-delivery.php';
require_once AIMEE_GLOBAL_DIR . 'includes/media-materialization.php';
require_once AIMEE_GLOBAL_DIR . 'includes/consciousness-voice.php';
require_once AIMEE_GLOBAL_DIR . 'includes/synthetic-identity.php';
require_once AIMEE_GLOBAL_DIR . 'includes/profile-attribution.php';
require_once AIMEE_GLOBAL_DIR . 'includes/inner-life.php';
require_once AIMEE_GLOBAL_DIR . 'includes/billing-migration.php';
require_once AIMEE_GLOBAL_DIR . 'includes/service-grace.php';
require_once AIMEE_GLOBAL_DIR . 'includes/gocardless.php';
require_once AIMEE_GLOBAL_DIR . 'includes/legacy-ui.php';
require_once AIMEE_GLOBAL_DIR . 'includes/templates.php';
require_once AIMEE_GLOBAL_DIR . 'includes/admin.php';

register_activation_hook(__FILE__, 'aimee_global_activate');
register_deactivation_hook(__FILE__, 'aimee_global_deactivate');

function aimee_global_install_and_verify_schema() {
    $core_ready = aimee_global_install_core_schema();
    $inner_ready = $core_ready ? aimee_global_install_inner_life_schema() : false;

    return $core_ready
        && $inner_ready
        && aimee_global_core_schema_health(true)
        && aimee_global_inner_life_schema_health(true);
}

function aimee_global_record_upgrade_failure($stage, $result = null) {
    $message = is_wp_error($result)
        ? $result->get_error_message()
        : 'The local upgrade stage did not complete successfully.';
    update_option('aimee_global_upgrade_failure', [
        'release' => AIMEE_GLOBAL_VERSION,
        'schema' => AIMEE_GLOBAL_SCHEMA_VERSION,
        'stage' => sanitize_key((string) $stage),
        'message' => sanitize_text_field((string) $message),
        'failed_at' => current_time('mysql', true),
    ], false);
}

function aimee_global_activate() {
    if (!aimee_global_install_and_verify_schema()) {
        aimee_global_record_upgrade_failure('schema');
        flush_rewrite_rules();
        return;
    }

    $migration_result = aimee_global_migrate_legacy_stripe_profiles();
    if (is_wp_error($migration_result)) {
        aimee_global_record_upgrade_failure('billing_migration', $migration_result);
        flush_rewrite_rules();
        return;
    }

    $service_grace_result = aimee_global_grant_august_2026_service_grace();
    if (is_wp_error($service_grace_result)) {
        aimee_global_record_upgrade_failure('service_grace', $service_grace_result);
        flush_rewrite_rules();
        return;
    }

    $page_result = aimee_global_create_pages();
    if (is_wp_error($page_result)) {
        aimee_global_record_upgrade_failure('managed_pages', $page_result);
        flush_rewrite_rules();
        return;
    }

    // Auxiliary runtime tables are installed only after the bundled engine is
    // loaded. The init finalizer below is the sole writer of the global schema
    // and release markers, after all three schema domains pass health checks.
    flush_rewrite_rules();
}

/**
 * WordPress does not run activation hooks during an in-place plugin update.
 * Apply schema changes and the one-time closed-account billing migration as
 * soon as the upgraded plugin is loaded.
 */
function aimee_global_maybe_upgrade() {
    $installed = (string) get_option('aimee_global_version', '0');
    $installed_schema = (string) get_option('aimee_global_schema_version', '0');
    $needs_version_upgrade = version_compare($installed, AIMEE_GLOBAL_VERSION, '<');
    $schema_healthy = aimee_global_core_schema_health()
        && aimee_global_inner_life_schema_health();
    $needs_schema_upgrade = version_compare($installed_schema, AIMEE_GLOBAL_SCHEMA_VERSION, '<')
        || !$schema_healthy;
    $migration_summary = get_option(aimee_global_billing_migration_option_name(), null);
    $needs_billing_migration = !is_array($migration_summary) || empty($migration_summary['completed_at']);
    $service_grace_summary = get_option(aimee_global_service_grace_option_name(), null);
    $needs_service_grace = !is_array($service_grace_summary) || empty($service_grace_summary['completed_at']);

    if (
        !$needs_version_upgrade
        && !$needs_schema_upgrade
        && !$needs_billing_migration
        && !$needs_service_grace
    ) return;

    // Schema lifecycle is deliberately independent of the public plugin
    // release. Health is checked even when stored version options are current,
    // so a partially applied or externally altered MariaDB schema self-heals.
    if ($needs_schema_upgrade) {
        if (!aimee_global_install_and_verify_schema()) {
            aimee_global_record_upgrade_failure('schema');
            return;
        }
    }

    if ($needs_billing_migration) {
        $migration_result = aimee_global_migrate_legacy_stripe_profiles();
        if (is_wp_error($migration_result)) {
            aimee_global_record_upgrade_failure('billing_migration', $migration_result);
            return;
        }
    }

    // The historical period-repair helper can make remote Stripe changes and
    // is therefore operator-only in this closed-account release. Activation
    // and upgrades perform no automatic Stripe API mutation.

    if ($needs_service_grace || $needs_schema_upgrade) {
        $service_grace_result = aimee_global_grant_august_2026_service_grace();
        if (is_wp_error($service_grace_result)) {
            aimee_global_record_upgrade_failure('service_grace', $service_grace_result);
            return;
        }
    }

    if ($needs_version_upgrade) {
        // In-place updates do not run the activation hook. Repairing the
        // managed page set here ensures newly bundled public pages exist on
        // the first request after an upgrade.
        delete_transient('aimee_global_legacy_chat_uk');
        delete_transient('aimee_global_legacy_chat_us');
        $page_result = aimee_global_create_pages();
        if (is_wp_error($page_result)) {
            aimee_global_record_upgrade_failure('managed_pages', $page_result);
            return;
        }
    }
}
add_action('plugins_loaded', 'aimee_global_maybe_upgrade', 20);

function aimee_global_all_schema_health($refresh = false) {
    return aimee_global_core_schema_health($refresh)
        && aimee_global_inner_life_schema_health($refresh)
        && function_exists('aimee_engine_runtime_schema_is_healthy')
        && aimee_engine_runtime_schema_is_healthy($refresh);
}

/**
 * Commit release markers only after the engine's init-priority-10 installer
 * has repaired and verified every auxiliary runtime table. A legacy theme
 * engine, partial dbDelta, failed migration or page failure therefore leaves
 * the previous installed version intact and retryable.
 */
function aimee_global_finalize_upgrade_if_healthy() {
    $installed = (string) get_option('aimee_global_version', '0');
    $installed_schema = (string) get_option('aimee_global_schema_version', '0');
    $needs_version_upgrade = version_compare($installed, AIMEE_GLOBAL_VERSION, '<');
    $needs_schema_upgrade = version_compare($installed_schema, AIMEE_GLOBAL_SCHEMA_VERSION, '<');
    $recorded_failure = get_option('aimee_global_upgrade_failure', null);

    if (!$needs_version_upgrade && !$needs_schema_upgrade && !is_array($recorded_failure)) {
        return;
    }

    if (!aimee_global_all_schema_health(true)) {
        aimee_global_record_upgrade_failure('runtime_schema');
        return;
    }

    // Legacy builds placed onboarding photos in public uploads. Do not mark
    // this release current until every recognized owner-bound legacy asset is
    // durably moved behind the authenticated private-media endpoint and its
    // public copy is verified absent.
    if (
        !function_exists('aimee_profile_media_maybe_migrate_legacy')
        || !function_exists('aimee_profile_media_migration_is_complete')
        || !aimee_profile_media_maybe_migrate_legacy(true)
        || !aimee_profile_media_migration_is_complete()
    ) {
        aimee_global_record_upgrade_failure('profile_media_migration');
        return;
    }

    if (
        !function_exists('aimee_seed_private_media_library')
        || !function_exists('aimee_private_media_library_is_private')
        || !aimee_seed_private_media_library()
        || !aimee_private_media_library_is_private()
    ) {
        aimee_global_record_upgrade_failure('private_catalogue_migration');
        return;
    }

    if (
        !function_exists('aimee_voice_note_migrate_legacy_storage')
        || !function_exists('aimee_voice_note_legacy_storage_is_clear')
        || !aimee_voice_note_migrate_legacy_storage()
        || !aimee_voice_note_legacy_storage_is_clear()
    ) {
        aimee_global_record_upgrade_failure('voice_note_storage_migration');
        return;
    }

    $migration_summary = get_option(aimee_global_billing_migration_option_name(), null);
    if (!is_array($migration_summary) || empty($migration_summary['completed_at'])) {
        aimee_global_record_upgrade_failure('billing_migration');
        return;
    }
    $service_grace_summary = get_option(aimee_global_service_grace_option_name(), null);
    if (!is_array($service_grace_summary) || empty($service_grace_summary['completed_at'])) {
        aimee_global_record_upgrade_failure('service_grace');
        return;
    }

    if ($needs_version_upgrade) {
        delete_transient('aimee_global_legacy_chat_uk');
        delete_transient('aimee_global_legacy_chat_us');
        $page_result = aimee_global_create_pages();
        if (is_wp_error($page_result)) {
            aimee_global_record_upgrade_failure('managed_pages', $page_result);
            return;
        }
    }

    if ($needs_schema_upgrade) {
        update_option('aimee_global_schema_version', AIMEE_GLOBAL_SCHEMA_VERSION);
    }
    if ($needs_version_upgrade) {
        update_option('aimee_global_version', AIMEE_GLOBAL_VERSION);
        flush_rewrite_rules(false);
    }
    delete_option('aimee_global_upgrade_failure');
}
add_action('init', 'aimee_global_finalize_upgrade_if_healthy', 20);

function aimee_global_deactivate() {
    foreach ([
        'aimee_autonomous_cron_hook',
        'aimee_autonomous_pulse',
        'aimee_continuity_cron_hook',
        'aimee_continuity_pulse',
        'aimee_rem_sleep_cycle',
        'aimee_voice_note_cleanup_hook',
        'aimee_push_dispatch_hook',
        'aimee_gocardless_renewal_hook',
    ] as $hook) {
        wp_clear_scheduled_hook($hook);
    }
    flush_rewrite_rules();
}

/**
 * Load the bundled engine after the active theme has loaded. This avoids
 * redeclaration fatals during a staged migration from a theme functions.php.
 */
add_action('after_setup_theme', function () {
    if (function_exists('aimee_table')) {
        $GLOBALS['aimee_global_legacy_engine_detected'] = true;
        return;
    }
    require_once AIMEE_GLOBAL_DIR . 'includes/engine.php';
}, 2);

/**
 * Do not let a theme-owned legacy engine answer the audited conversational
 * endpoints. Loading both engines would cause redeclaration fatals, while
 * allowing the legacy callbacks to continue would bypass the relationship,
 * media-decision and delivery-state controls bundled with this release.
 */
function aimee_global_legacy_engine_blocks_rest_route($route) {
    $route = '/' . trim((string) $route, '/');
    return strpos($route, '/aimee/v1/') === 0;
}

function aimee_global_fail_legacy_engine_rest_paths_closed($result, $server, $request) {
    if (empty($GLOBALS['aimee_global_legacy_engine_detected'])) return $result;
    if (!is_object($request) || !method_exists($request, 'get_route')) return $result;
    if (!aimee_global_legacy_engine_blocks_rest_route($request->get_route())) return $result;

    return new WP_Error(
        'aimee_legacy_engine_migration_required',
        __('Aimee message and voice services are unavailable until an administrator completes the legacy theme-engine migration.', 'aimee-global'),
        [
            'status' => 503,
            'migration_required' => true,
        ]
    );
}
add_filter(
    'rest_pre_dispatch',
    'aimee_global_fail_legacy_engine_rest_paths_closed',
    PHP_INT_MAX,
    3
);

/**
 * A legacy theme can register private-media admin-post handlers and gallery
 * template redirects outside REST. Stop those exact bundled surfaces before
 * the theme callbacks run; otherwise old phone-based identity rules could
 * bypass this plugin's immutable account and media-delivery controls.
 */
function aimee_global_fail_legacy_private_media_closed() {
    if (empty($GLOBALS['aimee_global_legacy_engine_detected'])) return;

    wp_die(
        esc_html__('Private Aimee media is unavailable until the legacy theme-engine migration is complete.', 'aimee-global'),
        esc_html__('Aimee migration required', 'aimee-global'),
        ['response' => 503]
    );
}
add_action(
    'admin_post_aimee_private_media',
    'aimee_global_fail_legacy_private_media_closed',
    PHP_INT_MIN
);
add_action(
    'admin_post_nopriv_aimee_private_media',
    'aimee_global_fail_legacy_private_media_closed',
    PHP_INT_MIN
);

function aimee_global_fail_legacy_camera_roll_closed() {
    if (empty($GLOBALS['aimee_global_legacy_engine_detected'])) return;

    $template = function_exists('get_page_template_slug')
        ? basename((string) get_page_template_slug())
        : '';
    $legacy_gallery = function_exists('is_page')
        && is_page(['camera-roll', 'camera-roll-us']);
    $legacy_gallery = $legacy_gallery || in_array($template, [
        'aimee-global-gallery-uk.php',
        'aimee-global-gallery-us.php',
        'aimee-global-gallery-vip.php',
    ], true);
    if (!$legacy_gallery) return;

    wp_die(
        esc_html__('The Aimee camera roll is unavailable until the legacy theme-engine migration is complete.', 'aimee-global'),
        esc_html__('Aimee migration required', 'aimee-global'),
        ['response' => 503]
    );
}
add_action(
    'template_redirect',
    'aimee_global_fail_legacy_camera_roll_closed',
    PHP_INT_MIN
);

/**
 * A theme-owned legacy engine may also have registered autonomous/push hooks
 * before the plugin detects it. With the audited engine unavailable, suppress
 * every known bundled Aimee outbound worker and WordPress-routed FireText
 * request. This is a temporary fail-closed migration state, not a second
 * runtime mode.
 */
function aimee_global_suppress_legacy_engine_workers() {
    if (empty($GLOBALS['aimee_global_legacy_engine_detected'])) return;

    foreach ([
        'aimee_autonomous_cron_hook',
        'aimee_autonomous_pulse',
        'aimee_continuity_cron_hook',
        'aimee_continuity_pulse',
        'aimee_push_dispatch_hook',
        'aimee_gocardless_renewal_hook',
    ] as $hook) {
        remove_all_actions($hook);
    }
}
add_action('init', 'aimee_global_suppress_legacy_engine_workers', PHP_INT_MAX);

function aimee_global_block_legacy_firetext_requests($preempt, $args, $url) {
    if (empty($GLOBALS['aimee_global_legacy_engine_detected'])) return $preempt;
    if (stripos((string) $url, 'firetext.co.uk/api/sendsms') === false) return $preempt;

    return new WP_Error(
        'aimee_legacy_engine_sms_blocked',
        __('Carrier SMS is disabled until the legacy Aimee theme engine is removed.', 'aimee-global')
    );
}
add_filter(
    'pre_http_request',
    'aimee_global_block_legacy_firetext_requests',
    PHP_INT_MAX,
    3
);

add_action('admin_notices', function () {
    if (empty($GLOBALS['aimee_global_legacy_engine_detected']) || !current_user_can('manage_options')) return;
    echo '<div class="notice notice-error"><p><strong>Aimee Global migration required:</strong> the old Aimee engine is still present in the active theme. All known bundled Aimee REST, autonomous, private-media and WordPress-routed carrier-SMS paths are blocked so the theme engine cannot bypass relationship, media, access, billing or verified-identity controls. Remove the old Aimee block from <code>functions.php</code>; see <code>migration/REMOVE-AIMEE-FROM-THEME.md</code> inside the plugin.</p></div>';
});

add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    if (function_exists('aimee_global_core_schema_health') && aimee_global_core_schema_health()) return;

    echo '<div class="notice notice-error"><p><strong>Aimee Global schema verification failed:</strong> verified-phone uniqueness, inbound replay protection or outbound SMS audit storage is unavailable. Carrier SMS is disabled and the schema upgrade will retry until every database invariant is repaired.</p></div>';
});


add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    $summary = aimee_global_billing_migration_summary();
    if (empty($summary['completed_at']) || empty($summary['reactivation_profiles'])) return;
    if (get_option('aimee_global_migration_notice_130_shown')) return;

    $count = (int) $summary['reactivation_profiles'];
    echo '<div class="notice notice-info is-dismissible"><p><strong>Aimee billing migration complete:</strong> ' . esc_html($count) . ' existing member' . ($count === 1 ? '' : 's') . ' moved into reactivation mode. Their former payment IDs are archived, current access is preserved, and the chat UI will ask them to reconnect through the dedicated Aimee payment account.</p></div>';
    update_option('aimee_global_migration_notice_130_shown', current_time('mysql', true), false);
});

add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    $summary = aimee_global_billing_period_repair_summary();
    if (empty($summary['completed_at']) || empty($summary['repaired_profiles'])) return;
    if (get_option('aimee_global_period_repair_notice_133_shown')) return;

    $count = (int) $summary['repaired_profiles'];
    echo '<div class="notice notice-success is-dismissible"><p><strong>Aimee membership dates repaired:</strong> ' . esc_html($count) . ' legacy member' . ($count === 1 ? '' : 's') . ' had the temporary seven-day fallback replaced with the correct weekly or monthly paid-through date. Any open replacement checkout created against the wrong date was expired and can be started again safely.</p></div>';
    update_option('aimee_global_period_repair_notice_133_shown', current_time('mysql', true), false);
});

add_filter('aimee_privacy_contact_email', function ($email) {
    $saved = sanitize_email(get_option('aimee_global_privacy_email', ''));
    return $saved ?: $email;
});

add_filter('aimee_policy_document_status', function ($status) {
    return get_option('aimee_global_policy_status', $status);
});
