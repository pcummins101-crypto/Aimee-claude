<?php
defined('ABSPATH') || exit;

/**
 * Fixed service-recovery entitlement for the August 2026 processor outage.
 *
 * This policy grants product access only. It is not a Stripe subscription,
 * payment mandate, relationship signal, age check or consent signal.
 */
function aimee_global_service_grace_policy() {
    static $policy = null;
    if (is_array($policy)) return $policy;

    try {
        $london = new DateTimeZone('Europe/London');
        $starts = new DateTimeImmutable('2026-08-01 00:00:00', $london);
        $ends = new DateTimeImmutable('2026-09-01 00:00:00', $london);
    } catch (Exception $exception) {
        // The IANA Europe/London zone is part of every supported PHP build.
        // Fail closed if the runtime is nevertheless incomplete.
        return [
            'id' => 'august_2026_processor_recovery',
            'starts_at' => 0,
            'ends_at' => 0,
            'starts_at_utc' => null,
            'ends_at_utc' => null,
            'ends_at_local' => null,
        ];
    }

    $policy = [
        'id' => 'august_2026_processor_recovery',
        'starts_at' => $starts->getTimestamp(),
        'ends_at' => $ends->getTimestamp(),
        'starts_at_utc' => gmdate('Y-m-d H:i:s', $starts->getTimestamp()),
        'ends_at_utc' => gmdate('Y-m-d H:i:s', $ends->getTimestamp()),
        'ends_at_local' => $ends->format('Y-m-d H:i:s T'),
    ];

    return $policy;
}

function aimee_global_service_grace_profile_value($profile, $field, $default = null) {
    if (is_object($profile) && isset($profile->{$field})) return $profile->{$field};
    if (is_array($profile) && array_key_exists($field, $profile)) return $profile[$field];
    return $default;
}

/**
 * Provenance token for subscriptions created in the replacement Stripe
 * account after the August 2026 recovery period. A local `active` label or a
 * Stripe-looking ID without this exact server-owned marker is historical only.
 */
function aimee_global_current_billing_generation() {
    return 'stripe_2026_09_v1';
}

function aimee_global_profile_has_current_billing_generation($profile) {
    if (!$profile) return false;
    $provider = sanitize_key((string) aimee_global_service_grace_profile_value(
        $profile,
        'billing_provider',
        ''
    ));
    $stored = trim((string) aimee_global_service_grace_profile_value(
        $profile,
        'billing_account_generation',
        ''
    ));
    if ($stored === '') return false;
    if ($provider === 'stripe') {
        return hash_equals(aimee_global_current_billing_generation(), $stored);
    }
    if ($provider === 'gocardless' && function_exists('aimee_gocardless_generation')) {
        return hash_equals(aimee_gocardless_generation(), $stored);
    }
    return false;
}

function aimee_global_service_grace_timestamp($value) {
    if (is_int($value) || (is_string($value) && ctype_digit($value))) {
        return max(0, (int) $value);
    }

    $value = trim((string) $value);
    if ($value === '') return 0;
    $timestamp = strtotime($value . (preg_match('/(?:Z|[+\-]\d\d:?\d\d|\bUTC)$/i', $value) ? '' : ' UTC'));
    return $timestamp ? (int) $timestamp : 0;
}

/**
 * Normalize subscription periods across classic Stripe objects and current
 * API versions where the period lives on subscription items. The earliest
 * item end is the fail-closed access boundary for a mixed-interval object.
 */
function aimee_global_stripe_subscription_period_bounds($subscription) {
    if (!is_array($subscription)) return ['start' => 0, 'end' => 0];

    $start = max(0, (int) ($subscription['current_period_start'] ?? 0));
    $end = max(0, (int) ($subscription['current_period_end'] ?? 0));
    $item_starts = [];
    $item_ends = [];
    $items = $subscription['items']['data'] ?? [];
    foreach (is_array($items) ? $items : [] as $item) {
        if (!is_array($item)) continue;
        $item_start = max(0, (int) ($item['current_period_start'] ?? 0));
        $item_end = max(0, (int) ($item['current_period_end'] ?? 0));
        if ($item_start > 0) $item_starts[] = $item_start;
        if ($item_end > 0) $item_ends[] = $item_end;
    }

    if (!$start && $item_starts) $start = max($item_starts);
    if (!$end && $item_ends) $end = min($item_ends);
    return ['start' => $start, 'end' => $end];
}

function aimee_global_stripe_invoice_subscription_id($invoice) {
    if (!is_array($invoice)) return '';
    $subscription = $invoice['subscription'] ?? '';
    if (is_array($subscription)) $subscription = $subscription['id'] ?? '';
    if (is_string($subscription) && trim($subscription) !== '') return trim($subscription);

    $parent = is_array($invoice['parent'] ?? null) ? $invoice['parent'] : [];
    $details = is_array($parent['subscription_details'] ?? null)
        ? $parent['subscription_details']
        : [];
    $subscription = $details['subscription'] ?? '';
    if (is_array($subscription)) $subscription = $subscription['id'] ?? '';
    return is_string($subscription) ? trim($subscription) : '';
}

function aimee_global_service_grace_enrollment_fields($granted_at_ts = null) {
    $policy = aimee_global_service_grace_policy();
    $granted_at_ts = $granted_at_ts === null ? time() : (int) $granted_at_ts;
    if (empty($policy['ends_at']) || empty($policy['ends_at_utc'])) return [];

    return [
        'service_grace_code' => $policy['id'],
        'service_grace_granted_at' => gmdate('Y-m-d H:i:s', $granted_at_ts),
        'service_grace_access_until' => $policy['ends_at_utc'],
    ];
}

/**
 * Columns assigned to an Aimee profile created before the fixed cutoff.
 */
function aimee_global_service_grace_profile_fields($now_ts = null) {
    $policy = aimee_global_service_grace_policy();
    $now_ts = $now_ts === null ? time() : (int) $now_ts;
    if (empty($policy['ends_at']) || $now_ts >= (int) $policy['ends_at']) return [];
    return aimee_global_service_grace_enrollment_fields($now_ts);
}

function aimee_global_service_grace_profile_is_enrolled($profile) {
    if (!$profile || (int) aimee_global_service_grace_profile_value($profile, 'user_id', 0) < 1) {
        return false;
    }

    $policy = aimee_global_service_grace_policy();
    $code = trim((string) aimee_global_service_grace_profile_value($profile, 'service_grace_code', ''));
    $stored_end = aimee_global_service_grace_timestamp(
        aimee_global_service_grace_profile_value($profile, 'service_grace_access_until', '')
    );

    return $code === $policy['id']
        && $stored_end > 0
        && $stored_end <= (int) $policy['ends_at'];
}

function aimee_global_service_grace_end_timestamp($profile = null) {
    $policy = aimee_global_service_grace_policy();
    if ($profile === null) return (int) $policy['ends_at'];
    if (!aimee_global_service_grace_profile_is_enrolled($profile)) return 0;

    $stored_end = aimee_global_service_grace_timestamp(
        aimee_global_service_grace_profile_value($profile, 'service_grace_access_until', '')
    );
    return min($stored_end, (int) $policy['ends_at']);
}

function aimee_global_service_grace_is_active($profile, $now_ts = null) {
    if (!aimee_global_service_grace_profile_is_enrolled($profile)) return false;

    $policy = aimee_global_service_grace_policy();
    $now_ts = $now_ts === null ? time() : (int) $now_ts;
    $end_ts = aimee_global_service_grace_end_timestamp($profile);

    return $now_ts >= (int) $policy['starts_at']
        && $end_ts > 0
        && $now_ts < $end_ts;
}

/**
 * A profile in this campaign needs a replacement subscription at the cutoff.
 * A separate goodwill extension can still keep product access open, but it
 * never becomes a payment mandate and does not erase this billing fact.
 */
function aimee_global_service_grace_requires_new_subscription($profile, $now_ts = null) {
    if (!aimee_global_service_grace_profile_is_enrolled($profile)) return false;
    $now_ts = $now_ts === null ? time() : (int) $now_ts;
    if ($now_ts < aimee_global_service_grace_end_timestamp($profile)) return false;
    return !aimee_global_managed_subscription_is_active($profile, $now_ts);
}

function aimee_global_goodwill_access_is_active($profile, $now_ts = null) {
    if (!$profile) return false;
    $now_ts = $now_ts === null ? time() : (int) $now_ts;
    $bonus_end = aimee_global_service_grace_timestamp(
        aimee_global_service_grace_profile_value($profile, 'membership_bonus_access_until', '')
    );
    return $bonus_end > $now_ts;
}

/**
 * Stripe accepts an explicit Checkout trial end only when it is at least 48
 * hours in the future. Keep a small clock-skew buffer around that processor
 * boundary so existing goodwill is never silently charged early.
 */
function aimee_global_goodwill_checkout_block_until($profile, $now_ts = null) {
    if (!$profile) return 0;
    $now_ts = $now_ts === null ? time() : (int) $now_ts;
    if (!aimee_global_goodwill_access_is_active($profile, $now_ts)) return 0;

    $bonus_end = aimee_global_service_grace_timestamp(
        aimee_global_service_grace_profile_value($profile, 'membership_bonus_access_until', '')
    );
    $minimum_deferred_payment = $now_ts + (48 * 60 * 60) + (5 * 60);

    return $bonus_end > $now_ts && $bonus_end < $minimum_deferred_payment
        ? $bonus_end
        : 0;
}

/**
 * Terminal subscription records cannot renew or be repaired in place. They
 * must never block a replacement Checkout Session or trigger a remote cancel.
 */
function aimee_global_subscription_status_is_terminal($status) {
    return in_array(
        strtolower(trim((string) $status)),
        ['cancelled', 'canceled', 'incomplete_expired'],
        true
    );
}

/**
 * Detect a second live subscription identity for the same billing generation.
 *
 * An empty identity is the first sync. A stale account generation, the same
 * subscription, or a terminal predecessor may be replaced. Every other
 * different current-generation identity requires operator reconciliation so
 * one live Stripe subscription can never be silently orphaned locally.
 */
function aimee_global_subscription_identity_conflicts($profile, $incoming_subscription_id, $incoming_generation) {
    if (!$profile) return false;

    $incoming_subscription_id = trim((string) $incoming_subscription_id);
    $incoming_generation = trim((string) $incoming_generation);
    $stored_subscription_id = trim((string) aimee_global_service_grace_profile_value(
        $profile,
        'stripe_subscription_id',
        ''
    ));
    $stored_generation = trim((string) aimee_global_service_grace_profile_value(
        $profile,
        'billing_account_generation',
        ''
    ));
    $stored_status = aimee_global_service_grace_profile_value(
        $profile,
        'subscription_status',
        'inactive'
    );

    if ($incoming_subscription_id === '' || $incoming_generation === '') return true;
    if ($stored_subscription_id === '') return false;
    if (hash_equals($stored_subscription_id, $incoming_subscription_id)) return false;
    if ($stored_generation === '' || !hash_equals($incoming_generation, $stored_generation)) return false;

    return !aimee_global_subscription_status_is_terminal($stored_status);
}

/**
 * A real, post-migration subscription in the currently configured account.
 * Local access, a legacy identifier or a historical `active` label is not
 * enough to prove that Stripe can manage or renew it.
 */
function aimee_global_managed_subscription_is_active($profile, $now_ts = null) {
    if (!$profile) return false;
    if (!aimee_global_profile_has_current_billing_generation($profile)) return false;

    $migration_status = strtolower(trim((string) aimee_global_service_grace_profile_value(
        $profile,
        'billing_migration_status',
        'none'
    )));
    if ($migration_status !== 'complete') return false;

    $provider = strtolower(trim((string) aimee_global_service_grace_profile_value($profile, 'billing_provider', '')));
    if ($provider === 'gocardless') {
        $mandate_id = trim((string) aimee_global_service_grace_profile_value($profile, 'gocardless_mandate_id', ''));
        if ($mandate_id === '') return false;
    } elseif ($provider === 'stripe') {
        $subscription_id = trim((string) aimee_global_service_grace_profile_value($profile, 'stripe_subscription_id', ''));
        if ($subscription_id === '') return false;
    } else {
        return false;
    }

    $status = strtolower(trim((string) aimee_global_service_grace_profile_value(
        $profile,
        'subscription_status',
        'inactive'
    )));
    if (!in_array($status, ['active', 'trialing'], true)) return false;

    $end_ts = aimee_global_service_grace_timestamp(
        aimee_global_service_grace_profile_value($profile, 'subscription_current_period_end', '')
    );
    if (!$end_ts) return false;

    $now_ts = $now_ts === null ? time() : (int) $now_ts;
    return $end_ts > $now_ts;
}

/**
 * Effective in-product membership access. The campaign cutoff invalidates
 * stale closed-account `active` labels, while real new subscriptions and
 * independent goodwill extensions continue normally.
 */
function aimee_global_membership_access_is_active($profile, $now_ts = null) {
    if (!$profile) return false;
    $now_ts = $now_ts === null ? time() : (int) $now_ts;

    if (aimee_global_goodwill_access_is_active($profile, $now_ts)) return true;
    if (aimee_global_service_grace_is_active($profile, $now_ts)) return true;
    if (aimee_global_managed_subscription_is_active($profile, $now_ts)) return true;

    // The configured processor account is the only recurring-billing source
    // after this release. Unproven local status and identifiers never grant
    // product access, even for a profile created after the one-off campaign.
    return false;
}

/**
 * Carrier SMS is deliberately outside the complimentary in-app entitlement.
 */
function aimee_global_sms_membership_is_active($profile, $now_ts = null) {
    if (
        !function_exists('aimee_global_core_schema_health')
        || !aimee_global_core_schema_health()
    ) return false;
    if (!aimee_global_sms_profile_is_verified($profile)) return false;

    if (
        function_exists('aimee_is_admin_user')
        && aimee_is_admin_user($profile)
    ) {
        return true;
    }

    return aimee_global_managed_subscription_is_active($profile, $now_ts);
}

/**
 * Carrier SMS needs server-owned proof that the destination belongs to this
 * profile and an IANA timezone for recipient-local safe hours. A stored phone
 * number or prior checkbox alone is not proof of either condition.
 */
function aimee_global_sms_timezone_is_valid($timezone) {
    $timezone = trim((string) $timezone);
    if ($timezone === '' || strlen($timezone) > 64) return false;

    static $identifiers = null;
    if (!is_array($identifiers)) {
        $identifiers = DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC);
        $identifiers[] = 'UTC';
    }

    return in_array($timezone, $identifiers, true);
}

function aimee_global_sms_normalized_number($number) {
    if (function_exists('aimee_format_mobile')) {
        $formatted = aimee_format_mobile((string) $number);
        return $formatted ? (string) $formatted : '';
    }

    $digits = preg_replace('/[^0-9]/', '', (string) $number);
    return preg_match('/^[1-9][0-9]{7,14}$/', $digits) ? $digits : '';
}

function aimee_global_sms_profile_is_verified($profile) {
    if (!$profile) return false;

    $stored = aimee_global_sms_normalized_number(
        aimee_global_service_grace_profile_value($profile, 'phone_number', '')
    );
    $verified = aimee_global_sms_normalized_number(
        aimee_global_service_grace_profile_value($profile, 'phone_verified_number', '')
    );
    $verified_at = trim((string) aimee_global_service_grace_profile_value(
        $profile,
        'phone_verified_at',
        ''
    ));
    $timezone = aimee_global_service_grace_profile_value(
        $profile,
        'sms_timezone',
        ''
    );

    return $stored !== ''
        && $verified !== ''
        && hash_equals($stored, $verified)
        && aimee_global_service_grace_timestamp($verified_at) > 0
        && aimee_global_sms_timezone_is_valid($timezone);
}

/**
 * Earliest fair first-payment timestamp for an explicitly chosen new plan.
 * Checkout itself is paused while the August grant is live.
 */
function aimee_global_service_grace_first_payment_timestamp($profile, $now_ts = null) {
    if (!$profile) return 0;
    $now_ts = $now_ts === null ? time() : (int) $now_ts;

    if (aimee_global_service_grace_is_active($profile, $now_ts)) {
        return aimee_global_service_grace_end_timestamp($profile);
    }

    $bonus_end = aimee_global_service_grace_timestamp(
        aimee_global_service_grace_profile_value($profile, 'membership_bonus_access_until', '')
    );
    if ($bonus_end > $now_ts) return $bonus_end;

    if (aimee_global_service_grace_profile_is_enrolled($profile)) {
        return 0;
    }

    $latest = 0;
    foreach (['legacy_membership_end', 'subscription_current_period_end'] as $field) {
        $latest = max($latest, aimee_global_service_grace_timestamp(
            aimee_global_service_grace_profile_value($profile, $field, '')
        ));
    }
    return $latest > $now_ts ? $latest : 0;
}

function aimee_global_service_grace_checkout_is_paused($profile, $now_ts = null) {
    return aimee_global_service_grace_is_active($profile, $now_ts);
}

function aimee_global_service_grace_option_name() {
    return 'aimee_global_august_2026_service_grace';
}

/**
 * Idempotently enrol every current Aimee profile without altering billing,
 * trial, relationship, adult-assurance or consent fields.
 */
function aimee_global_grant_august_2026_service_grace() {
    global $wpdb;

    $fields = aimee_global_service_grace_enrollment_fields();
    $option_name = aimee_global_service_grace_option_name();
    if (!$fields) {
        $result = [
            'completed_at' => function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'),
            'profiles_granted' => 0,
            'policy_id' => aimee_global_service_grace_policy()['id'],
            'access_until' => aimee_global_service_grace_policy()['ends_at_utc'],
            'note' => 'The fixed service-grace policy could not be resolved; no profiles were changed.',
        ];
        if (function_exists('update_option')) update_option($option_name, $result, false);
        return $result;
    }

    $table = 'aimee_user_profiles';
    $exists = $wpdb->get_var($wpdb->prepare(
        'SHOW TABLES LIKE %s',
        $wpdb->esc_like($table)
    ));
    if ($exists !== $table) {
        $result = [
            'completed_at' => current_time('mysql', true),
            'profiles_granted' => 0,
            'policy_id' => $fields['service_grace_code'],
            'access_until' => $fields['service_grace_access_until'],
            'note' => 'Profile table was not present.',
        ];
        update_option($option_name, $result, false);
        return $result;
    }

    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE `{$table}`
         SET service_grace_code = %s,
             service_grace_granted_at = COALESCE(service_grace_granted_at, %s),
             service_grace_access_until = %s
         WHERE created_at < %s
           AND (
                service_grace_code IS NULL
             OR service_grace_code <> %s
             OR service_grace_access_until IS NULL
             OR service_grace_access_until <> %s
           )",
        $fields['service_grace_code'],
        $fields['service_grace_granted_at'],
        $fields['service_grace_access_until'],
        $fields['service_grace_access_until'],
        $fields['service_grace_code'],
        $fields['service_grace_access_until']
    ));

    if ($updated === false) {
        return new WP_Error(
            'aimee_service_grace_failed',
            'The August service-recovery entitlement could not be applied.'
        );
    }

    $result = [
        'completed_at' => current_time('mysql', true),
        'profiles_granted' => max(0, (int) $updated),
        'policy_id' => $fields['service_grace_code'],
        'access_until' => $fields['service_grace_access_until'],
        'automatic_payment_scheduled' => false,
        'subscription_fields_changed' => false,
    ];
    update_option($option_name, $result, false);
    return $result;
}

function aimee_global_service_grace_summary() {
    $summary = get_option(aimee_global_service_grace_option_name(), []);
    return is_array($summary) ? $summary : [];
}
