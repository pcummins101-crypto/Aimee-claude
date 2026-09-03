<?php
defined('ABSPATH') || exit;

/**
 * Billing migration introduced in Aimee Global 1.3.0.
 *
 * The former payment account has been closed. Every customer, subscription
 * and checkout-session identifier present when this update is first installed
 * therefore belongs to that closed account. The migration archives those
 * identifiers, removes them from active billing fields and preserves each
 * genuine subscriber's paid-through access locally.
 */

function aimee_global_billing_migration_option_name() {
    return 'aimee_global_legacy_stripe_migration_130';
}

function aimee_global_billing_period_repair_option_name() {
    return 'aimee_global_legacy_period_repair_133';
}

function aimee_global_closed_stripe_generation() {
    return 'legacy_stripe_closed_2026_08';
}

function aimee_global_billing_migration_retry_error($code, $message, array $context = []) {
    $summary = array_merge([
        'status' => 'retry',
        'retry_after' => time() + (15 * MINUTE_IN_SECONDS),
        'last_error_code' => sanitize_key((string) $code),
        'last_attempt_at' => current_time('mysql', true),
    ], $context);
    update_option(aimee_global_billing_migration_option_name(), $summary, false);
    return new WP_Error($code, $message, $summary);
}

function aimee_global_billing_migration_states() {
    return [
        'legacy_reactivation_required',
        'checkout_pending',
    ];
}

function aimee_global_billing_reactivation_required($profile) {
    if (!$profile) return false;
    $status = sanitize_key((string) ($profile->billing_migration_status ?? ''));
    if (in_array($status, aimee_global_billing_migration_states(), true)) return true;

    if (
        function_exists('aimee_global_service_grace_profile_is_enrolled')
        && function_exists('aimee_global_service_grace_end_timestamp')
        && function_exists('aimee_global_profile_has_current_billing_generation')
        && aimee_global_service_grace_profile_is_enrolled($profile)
        && time() >= aimee_global_service_grace_end_timestamp($profile)
        && !aimee_global_profile_has_current_billing_generation($profile)
    ) {
        return true;
    }

    return false;
}

function aimee_global_billing_can_manage($profile) {
    if (!$profile
        || aimee_global_billing_reactivation_required($profile)
        || !function_exists('aimee_global_profile_has_current_billing_generation')
        || !aimee_global_profile_has_current_billing_generation($profile)
    ) return false;

    $provider = strtolower(trim((string) ($profile->billing_provider ?? '')));
    if ($provider === 'gocardless') {
        return !empty($profile->gocardless_mandate_id)
            && empty($profile->subscription_cancel_at_period_end);
    }
    if ($provider === 'stripe') {
        return !empty($profile->stripe_customer_id)
            && !empty($profile->stripe_subscription_id);
    }
    return false;
}

function aimee_global_billing_access_end_timestamp($profile) {
    if (!$profile) return 0;

    $latest = 0;
    foreach (['legacy_membership_end', 'subscription_current_period_end', 'membership_bonus_access_until', 'service_grace_access_until'] as $field) {
        $value = trim((string) ($profile->{$field} ?? ''));
        if ($value === '') continue;
        $timestamp = strtotime($value . ' UTC');
        if ($timestamp) $latest = max($latest, (int) $timestamp);
    }

    return $latest;
}

function aimee_global_billing_migration_grace_days() {
    return max(1, (int) apply_filters('aimee_global_billing_migration_grace_days', 7));
}

/**
 * Add calendar months without overflowing dates such as 31 January into March.
 */
function aimee_global_add_months_clamped(DateTimeImmutable $date, $months) {
    $months = max(1, (int) $months);
    $year = (int) $date->format('Y');
    $month = (int) $date->format('n');
    $day = (int) $date->format('j');

    $absolute_month = (($year * 12) + ($month - 1)) + $months;
    $target_year = intdiv($absolute_month, 12);
    $target_month = ($absolute_month % 12) + 1;
    // DateTime is a WordPress/PHP baseline dependency; ext-calendar is not.
    $last_day = (int) $date->setDate($target_year, $target_month, 1)->format('t');

    return $date->setDate($target_year, $target_month, min($day, $last_day));
}

/**
 * Reconstruct the paid billing period from the plan and original membership
 * anchor. The returned end is the first renewal boundary strictly after the
 * payment-account cutover.
 */
function aimee_global_infer_legacy_cycle_bounds($membership_started_at, $cutover_at, $plan) {
    $membership_started_at = trim((string) $membership_started_at);
    $cutover_at = trim((string) $cutover_at);
    $plan = sanitize_key((string) $plan);

    if ($membership_started_at === '' || $cutover_at === '' || !in_array($plan, ['weekly', 'monthly', 'annual'], true)) {
        return null;
    }

    try {
        $utc = new DateTimeZone('UTC');
        $anchor = new DateTimeImmutable($membership_started_at, $utc);
        $cutover = new DateTimeImmutable($cutover_at, $utc);
    } catch (Exception $exception) {
        return null;
    }

    $period_start = $anchor;
    $period_end = $anchor;
    $guard = 0;

    while ($period_end <= $cutover && $guard < 2600) {
        $period_start = $period_end;

        if ($plan === 'weekly') {
            $period_end = $period_end->add(new DateInterval('P7D'));
        } elseif ($plan === 'monthly') {
            $period_end = aimee_global_add_months_clamped($period_end, 1);
        } else {
            $period_end = aimee_global_add_months_clamped($period_end, 12);
        }

        $guard++;
    }

    if ($guard >= 2600 || $period_end <= $cutover) return null;

    return [
        'start' => $period_start->format('Y-m-d H:i:s'),
        'end'   => $period_end->format('Y-m-d H:i:s'),
    ];
}

/**
 * Small Stripe helper used only to repair a replacement Checkout Session that
 * was opened while the incorrect seven-day fallback date was present.
 */
function aimee_global_repair_stripe_request($method, $path, array $body = []) {
    if (!defined('STRIPE_SECRET_KEY') || trim((string) STRIPE_SECRET_KEY) === '') {
        return new WP_Error('aimee_repair_missing_key', 'The payment-account secret key is unavailable.');
    }

    $url = 'https://api.stripe.com/v1/' . ltrim($path, '/');
    $args = [
        'method'  => strtoupper($method),
        'timeout' => 30,
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode(trim((string) STRIPE_SECRET_KEY) . ':'),
        ],
    ];

    if (!empty($body)) {
        $args['body'] = $body;
    }

    $response = wp_remote_request($url, $args);
    if (is_wp_error($response)) return $response;

    $status = (int) wp_remote_retrieve_response_code($response);
    $decoded = json_decode(wp_remote_retrieve_body($response), true);

    if ($status < 200 || $status >= 300) {
        $message = is_array($decoded) && !empty($decoded['error']['message'])
            ? sanitize_text_field($decoded['error']['message'])
            : 'The payment account rejected the repair request.';
        return new WP_Error('aimee_repair_payment_error', $message, ['status' => $status]);
    }

    return is_array($decoded) ? $decoded : [];
}

/**
 * Repair the 1.3.0 fallback-date defect. When the former webhook had not saved
 * a period end, 1.3.0 gave every affected member the same seven-day grace date.
 * The plan and original membership anchor let us reconstruct the real paid
 * period instead.
 */
function aimee_global_repair_legacy_periods_133() {
    global $wpdb;

    $option_name = aimee_global_billing_period_repair_option_name();
    $existing = get_option($option_name, null);
    if (is_array($existing) && !empty($existing['completed_at'])) return $existing;

    $table = 'aimee_user_profiles';
    if (
        !function_exists('aimee_global_schema_table_exists')
        || !aimee_global_schema_table_exists($table)
    ) {
        return new WP_Error(
            'aimee_period_repair_schema_unavailable',
            'The profile table is unavailable; the period repair remains retryable.'
        );
    }

    $wpdb->last_error = '';
    $profiles = $wpdb->get_results(
        "SELECT user_id, subscription_plan, membership_started_at,
                subscription_current_period_end, stripe_checkout_session_id,
                legacy_original_period_end, legacy_membership_end,
                billing_migration_status, billing_migration_started_at
         FROM `{$table}`
         WHERE legacy_stripe_subscription_id IS NOT NULL
           AND legacy_stripe_subscription_id <> ''
           AND billing_migration_status IN ('legacy_reactivation_required', 'checkout_pending')
           AND legacy_original_period_end IS NULL
           AND membership_started_at IS NOT NULL"
    );
    if ($wpdb->last_error !== '' || $profiles === null) {
        return new WP_Error(
            'aimee_period_repair_select_failed',
            'The legacy periods could not be read; the repair remains retryable.'
        );
    }

    $repaired = 0;
    $pending_sessions_expired = 0;
    $completed_sessions_adjusted = 0;
    $manual_review = [];

    foreach ((array) $profiles as $profile) {
        $cutover_ts = strtotime((string) $profile->billing_migration_started_at . ' UTC');
        $legacy_end_ts = strtotime((string) $profile->legacy_membership_end . ' UTC');
        if (!$cutover_ts || !$legacy_end_ts) continue;

        $expected_grace_ts = $cutover_ts + (aimee_global_billing_migration_grace_days() * DAY_IN_SECONDS);
        if (abs($legacy_end_ts - $expected_grace_ts) > 120) continue;

        $bounds = aimee_global_infer_legacy_cycle_bounds(
            $profile->membership_started_at,
            $profile->billing_migration_started_at,
            $profile->subscription_plan
        );
        if (!$bounds) continue;

        $new_status = 'legacy_reactivation_required';
        $checkout_session_id = trim((string) ($profile->stripe_checkout_session_id ?? ''));
        $clear_checkout = true;
        $new_customer_id = null;
        $new_subscription_id = null;
        $migration_completed_at = null;

        if ($checkout_session_id !== '' && sanitize_key((string) $profile->billing_migration_status) === 'checkout_pending') {
            $session = aimee_global_repair_stripe_request(
                'GET',
                'checkout/sessions/' . rawurlencode($checkout_session_id) . '?expand[]=subscription'
            );

            if (is_wp_error($session)) {
                $manual_review[] = (int) $profile->user_id;
                $clear_checkout = false;
                $new_status = 'checkout_pending';
            } else {
                $session_status = sanitize_key((string) ($session['status'] ?? ''));

                if ($session_status === 'open') {
                    $expired = aimee_global_repair_stripe_request(
                        'POST',
                        'checkout/sessions/' . rawurlencode($checkout_session_id) . '/expire'
                    );
                    if (!is_wp_error($expired)) {
                        $pending_sessions_expired++;
                    } else {
                        $manual_review[] = (int) $profile->user_id;
                        $clear_checkout = false;
                        $new_status = 'checkout_pending';
                    }
                } elseif ($session_status === 'expired') {
                    $clear_checkout = true;
                } elseif ($session_status === 'complete') {
                    $subscription = $session['subscription'] ?? null;
                    $subscription_id = is_array($subscription)
                        ? sanitize_text_field((string) ($subscription['id'] ?? ''))
                        : sanitize_text_field((string) $subscription);

                    if ($subscription_id !== '') {
                        $new_end_ts = strtotime($bounds['end'] . ' UTC');
                        $adjusted = aimee_global_repair_stripe_request(
                            'POST',
                            'subscriptions/' . rawurlencode($subscription_id),
                            [
                                'trial_end' => (string) $new_end_ts,
                                'proration_behavior' => 'none',
                            ]
                        );

                        if (!is_wp_error($adjusted)) {
                            $new_status = 'complete';
                            $clear_checkout = false;
                            $new_subscription_id = $subscription_id;
                            $new_customer_id = sanitize_text_field((string) ($session['customer'] ?? ''));
                            $migration_completed_at = current_time('mysql', true);
                            $completed_sessions_adjusted++;
                        } else {
                            $manual_review[] = (int) $profile->user_id;
                            $clear_checkout = false;
                            $new_status = 'checkout_pending';
                        }
                    } else {
                        $manual_review[] = (int) $profile->user_id;
                        $clear_checkout = false;
                        $new_status = 'checkout_pending';
                    }
                } else {
                    $manual_review[] = (int) $profile->user_id;
                    $clear_checkout = false;
                    $new_status = 'checkout_pending';
                }
            }
        }

        $update = [
            'subscription_current_period_start' => $bounds['start'],
            'subscription_current_period_end'   => $bounds['end'],
            'legacy_membership_end'             => $bounds['end'],
            'sms_allowance_period_start'        => $bounds['start'],
            'sms_allowance_period_end'          => $bounds['end'],
            'billing_migration_status'          => $new_status,
            'subscription_cancel_at_period_end' => 1,
        ];
        $formats = ['%s', '%s', '%s', '%s', '%s', '%s', '%d'];

        if ($clear_checkout) {
            $update['stripe_checkout_session_id'] = null;
            $formats[] = null;
        }

        if ($new_subscription_id !== null) {
            $update['stripe_subscription_id'] = $new_subscription_id;
            $formats[] = '%s';
            $update['stripe_customer_id'] = $new_customer_id !== '' ? $new_customer_id : null;
            $formats[] = $new_customer_id !== '' ? '%s' : null;
            $update['subscription_status'] = 'trialing';
            $formats[] = '%s';
            $update['billing_migration_completed_at'] = $migration_completed_at;
            $formats[] = '%s';
        }

        $updated = $wpdb->update(
            $table,
            $update,
            ['user_id' => (int) $profile->user_id],
            $formats,
            ['%d']
        );

        if ($updated === false) {
            $manual_review[] = (int) $profile->user_id;
        } else {
            $repaired++;
        }
    }

    $result = [
        'repaired_profiles'          => $repaired,
        'pending_sessions_expired'   => $pending_sessions_expired,
        'completed_sessions_adjusted'=> $completed_sessions_adjusted,
        'manual_review_user_ids'     => array_values(array_unique(array_map('intval', $manual_review))),
    ];
    if ($result['manual_review_user_ids']) {
        $result['status'] = 'retry';
        $result['retry_after'] = time() + (15 * MINUTE_IN_SECONDS);
    } else {
        $result['completed_at'] = current_time('mysql', true);
    }
    update_option($option_name, $result, false);

    return $result;
}

/**
 * Archive every pre-cutover payment identifier. Only rows that contain a real
 * subscription ID and still appear current locally enter reactivation mode.
 * Abandoned checkouts and old cancelled subscriptions are safely detached
 * without receiving paid access or a migration card.
 */
function aimee_global_migrate_legacy_stripe_profiles() {
    global $wpdb;

    $option_name = aimee_global_billing_migration_option_name();
    $existing = get_option($option_name, null);
    if (is_array($existing) && !empty($existing['completed_at'])) {
        return $existing;
    }

    if (is_array($existing) && intval($existing['retry_after'] ?? 0) > time()) {
        return new WP_Error(
            'aimee_billing_migration_backoff',
            'The billing migration is waiting for its safe retry window.',
            $existing
        );
    }

    $lock = function_exists('aimee_global_schema_claim_lock')
        ? aimee_global_schema_claim_lock('legacy_stripe_migration_130', 300)
        : '';
    if ($lock === '') {
        return new WP_Error(
            'aimee_billing_migration_locked',
            'Another request is already applying the billing migration.'
        );
    }

    // Re-check the durable completion marker after acquiring the atomic lock.
    $existing = get_option($option_name, null);
    if (is_array($existing) && !empty($existing['completed_at'])) {
        aimee_global_schema_release_lock($lock);
        return $existing;
    }

    $table = 'aimee_user_profiles';
    if (
        !function_exists('aimee_global_core_schema_health')
        || !aimee_global_core_schema_health(true)
    ) {
        $error = aimee_global_billing_migration_retry_error(
            'aimee_billing_migration_schema_unavailable',
            'The complete InnoDB billing schema is not available; no payment identifiers were changed.'
        );
        aimee_global_schema_release_lock($lock);
        return $error;
    }

    $now = time();
    $started_at = gmdate('Y-m-d H:i:s', $now);
    $grace_end = gmdate(
        'Y-m-d H:i:s',
        $now + (aimee_global_billing_migration_grace_days() * DAY_IN_SECONDS)
    );
    $archived = 0;
    $reactivation = 0;
    $current_profiles = 0;
    $ambiguous_profiles = [];

    if ($wpdb->query('START TRANSACTION') === false) {
        $error = aimee_global_billing_migration_retry_error(
            'aimee_billing_migration_transaction_failed',
            'The billing migration could not start a database transaction.'
        );
        aimee_global_schema_release_lock($lock);
        return $error;
    }

    $wpdb->last_error = '';
    $profiles = $wpdb->get_results(
        "SELECT user_id, subscription_status, subscription_plan,
                membership_started_at, stripe_customer_id,
                stripe_subscription_id, stripe_checkout_session_id,
                subscription_current_period_end, billing_provider,
                billing_account_generation, billing_migration_status
         FROM `{$table}`
         WHERE (stripe_customer_id IS NOT NULL AND stripe_customer_id <> '')
            OR (stripe_subscription_id IS NOT NULL AND stripe_subscription_id <> '')
            OR (stripe_checkout_session_id IS NOT NULL AND stripe_checkout_session_id <> '')
         FOR UPDATE"
    );
    if ($wpdb->last_error !== '' || $profiles === null) {
        $wpdb->query('ROLLBACK');
        $error = aimee_global_billing_migration_retry_error(
            'aimee_billing_migration_select_failed',
            'The legacy billing rows could not be locked and read safely.'
        );
        aimee_global_schema_release_lock($lock);
        return $error;
    }

    $current_stripe_generation = function_exists('aimee_global_current_billing_generation')
        ? (string) aimee_global_current_billing_generation()
        : 'stripe_2026_09_v1';
    $current_gocardless_generation = function_exists('aimee_gocardless_generation')
        ? (string) aimee_gocardless_generation()
        : '';
    $closed_generation = aimee_global_closed_stripe_generation();

    foreach ((array) $profiles as $profile) {
        $generation = trim((string) ($profile->billing_account_generation ?? ''));
        $provider = sanitize_key((string) ($profile->billing_provider ?? ''));
        $migration_state = sanitize_key((string) ($profile->billing_migration_status ?? 'none'));

        $is_current_generation = $generation !== '' && (
            ($provider === 'stripe'
                && hash_equals($current_stripe_generation, $generation))
            || ($provider === 'gocardless'
                && $current_gocardless_generation !== ''
                && hash_equals($current_gocardless_generation, $generation))
        );
        if ($is_current_generation) {
            $current_profiles++;
            continue;
        }

        $closed_provenance = ($generation === '' || hash_equals($closed_generation, $generation))
            && in_array($provider, ['', 'stripe'], true)
            && in_array($migration_state, [
                'none',
                'legacy_reactivation_required',
                'legacy_closed',
            ], true);
        if (!$closed_provenance) {
            $ambiguous_profiles[] = (int) $profile->user_id;
            continue;
        }

        $original_end = trim((string) ($profile->subscription_current_period_end ?? ''));
        $original_end_ts = $original_end !== '' ? strtotime($original_end . ' UTC') : 0;
        $stored_status = sanitize_key((string) ($profile->subscription_status ?? 'inactive'));
        $has_subscription_id = trim((string) ($profile->stripe_subscription_id ?? '')) !== '';
        $looks_current = in_array($stored_status, ['active', 'trialing', 'past_due', 'unpaid'], true)
            || ($original_end_ts && $original_end_ts > $now);
        $requires_reactivation = $has_subscription_id && $looks_current;

        if ($requires_reactivation) {
            $inferred = (!$original_end_ts || $original_end_ts <= $now)
                ? aimee_global_infer_legacy_cycle_bounds(
                    $profile->membership_started_at ?? '',
                    $started_at,
                    $profile->subscription_plan ?? ''
                )
                : null;

            if ($original_end_ts && $original_end_ts > $now) {
                $effective_end = gmdate('Y-m-d H:i:s', $original_end_ts);
            } elseif ($inferred && strtotime($inferred['end'] . ' UTC') > $now) {
                $effective_end = $inferred['end'];
            } else {
                $effective_end = $grace_end;
            }

            $migration_status = 'legacy_reactivation_required';
            $new_subscription_status = 'active';
            $new_period_end = $effective_end;
            $cancel_at_period_end = 1;
            $legacy_membership_end = $effective_end;
        } else {
            $migration_status = 'legacy_closed';
            $new_subscription_status = in_array($stored_status, ['trial', 'inactive', 'cancelled', 'canceled'], true)
                ? $stored_status
                : 'inactive';
            $new_period_end = ($original_end_ts && $original_end_ts > $now)
                ? gmdate('Y-m-d H:i:s', $original_end_ts)
                : null;
            $cancel_at_period_end = 0;
            $legacy_membership_end = null;
        }

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}`
             SET legacy_stripe_customer_id = NULLIF(%s, ''),
                 legacy_stripe_subscription_id = NULLIF(%s, ''),
                 legacy_stripe_checkout_session_id = NULLIF(%s, ''),
                 legacy_original_period_end = NULLIF(%s, ''),
                 legacy_membership_end = NULLIF(%s, ''),
                 billing_migration_status = %s,
                 billing_migration_started_at = %s,
                 billing_migration_completed_at = NULL,
                 billing_account_generation = %s,
                 stripe_customer_id = NULL,
                 stripe_subscription_id = NULL,
                 stripe_checkout_session_id = NULL,
                 subscription_status = %s,
                 subscription_current_period_end = NULLIF(%s, ''),
                 subscription_cancel_at_period_end = %d
             WHERE user_id = %d",
            (string) ($profile->stripe_customer_id ?? ''),
            (string) ($profile->stripe_subscription_id ?? ''),
            (string) ($profile->stripe_checkout_session_id ?? ''),
            $original_end,
            (string) ($legacy_membership_end ?? ''),
            $migration_status,
            $started_at,
            $closed_generation,
            $new_subscription_status,
            (string) ($new_period_end ?? ''),
            $cancel_at_period_end,
            (int) $profile->user_id
        ));

        if ($updated !== 1) {
            $wpdb->query('ROLLBACK');
            $error = aimee_global_billing_migration_retry_error(
                'aimee_billing_migration_failed',
                'The legacy payment-account migration could not be completed.'
            );
            aimee_global_schema_release_lock($lock);
            return $error;
        }

        $archived++;
        if ($requires_reactivation) $reactivation++;
    }

    if ($wpdb->query('COMMIT') === false) {
        $wpdb->query('ROLLBACK');
        $error = aimee_global_billing_migration_retry_error(
            'aimee_billing_migration_commit_failed',
            'The legacy payment-account migration could not be committed.'
        );
        aimee_global_schema_release_lock($lock);
        return $error;
    }

    if ($ambiguous_profiles) {
        $error = aimee_global_billing_migration_retry_error(
            'aimee_billing_migration_manual_review',
            'Some payment identifiers had no demonstrable closed-account provenance and were left untouched.',
            [
                'archived_profiles' => $archived,
                'reactivation_profiles' => $reactivation,
                'current_generation_profiles' => $current_profiles,
                'manual_review_user_ids' => array_values(array_unique($ambiguous_profiles)),
            ]
        );
        aimee_global_schema_release_lock($lock);
        return $error;
    }

    $result = [
        'completed_at'          => current_time('mysql', true),
        'profiles'              => $reactivation,
        'archived_profiles'     => $archived,
        'reactivation_profiles' => $reactivation,
        'current_generation_profiles' => $current_profiles,
        'closed_generation'     => $closed_generation,
        'grace_days'            => aimee_global_billing_migration_grace_days(),
    ];
    update_option($option_name, $result, false);
    aimee_global_schema_release_lock($lock);

    return $result;
}

function aimee_global_billing_migration_summary() {
    $summary = get_option(aimee_global_billing_migration_option_name(), []);
    return is_array($summary) ? $summary : [];
}

function aimee_global_billing_period_repair_summary() {
    $summary = get_option(aimee_global_billing_period_repair_option_name(), []);
    return is_array($summary) ? $summary : [];
}
