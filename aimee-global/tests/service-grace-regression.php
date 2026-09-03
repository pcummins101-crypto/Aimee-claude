<?php
/**
 * Pure regression coverage for the August 2026 service-recovery entitlement.
 *
 * The entitlement is deliberately separate from billing. Every profile in the
 * enrolled cohort receives app membership access until the fixed London-time
 * boundary, but a closed-account Stripe ID can never become a valid managed
 * subscription and no relationship state is changed by these helpers.
 */

$failures = 0;
$passes = 0;

function grace_assert($condition, $label) {
    global $failures, $passes;
    if ($condition) {
        $passes++;
        echo "PASS {$label}\n";
        return;
    }

    $failures++;
    echo "FAIL {$label}\n";
}

function grace_same($expected, $actual, $label) {
    grace_assert(
        $expected === $actual,
        $label . ' (expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . ')'
    );
}

if (!defined('ABSPATH')) define('ABSPATH', dirname(__DIR__) . '/');
if (!function_exists('sanitize_key')) {
    function sanitize_key($value) {
        return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value));
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) { return $value; }
}
if (!function_exists('aimee_global_core_schema_health')) {
    function aimee_global_core_schema_health() { return true; }
}
if (!function_exists('aimee_gocardless_generation')) {
    function aimee_gocardless_generation() { return 'gocardless_2026_08_v1'; }
}

require dirname(__DIR__) . '/includes/service-grace.php';

$boundary = 1788217200; // 2026-09-01 00:00:00 Europe/London.
$before_boundary = $boundary - 1;
$after_boundary = $boundary + 1;

// -------------------------------------------------------------------------
// Policy boundary and deterministic enrollment.
// -------------------------------------------------------------------------

$policy = aimee_global_service_grace_policy();
grace_assert(is_array($policy) && !empty($policy), 'service-grace policy is explicit and inspectable');
grace_same($boundary, (int) ($policy['ends_at'] ?? 0), 'policy cutoff is midnight London on 1 September 2026');
grace_same('2026-08-31 23:00:00', $policy['ends_at_utc'] ?? null, 'policy exposes the exact UTC cutoff without local-time ambiguity');
grace_same('stripe_2026_09_v1', aimee_global_current_billing_generation(), 'replacement Stripe billing generation is explicit and inspectable');
grace_same('gocardless_2026_08_v1', aimee_gocardless_generation(), 'replacement GoCardless billing generation is explicit and inspectable');

$fields_before = aimee_global_service_grace_profile_fields($before_boundary);
$fields_again = aimee_global_service_grace_profile_fields($before_boundary);
$fields_at_boundary = aimee_global_service_grace_profile_fields($boundary);
$fields_after = aimee_global_service_grace_profile_fields($after_boundary);
$late_reconciliation_fields = aimee_global_service_grace_enrollment_fields($after_boundary);

grace_assert(is_array($fields_before) && !empty($fields_before), 'new profiles are enrolled immediately before the cutoff');
grace_same($fields_before, $fields_again, 'new-profile enrollment fields are deterministic');
grace_same([], $fields_at_boundary, 'a profile created exactly at the cutoff is not enrolled');
grace_same([], $fields_after, 'a profile created after the cutoff is not enrolled');
grace_assert(!empty($late_reconciliation_fields), 'late reconciliation can still cohort profiles created before the cutoff');

$forbidden_fields = [
    'subscription_status',
    'subscription_plan',
    'stripe_customer_id',
    'stripe_subscription_id',
    'stripe_checkout_session_id',
    'legacy_stripe_customer_id',
    'legacy_stripe_subscription_id',
    'legacy_stripe_checkout_session_id',
    'billing_migration_status',
    'billing_account_generation',
    'billing_checkout_intent_token',
    'billing_checkout_intent_provider',
    'billing_checkout_intent_plan',
    'billing_checkout_intent_market',
    'billing_checkout_intent_generation',
    'billing_checkout_intent_status',
    'billing_checkout_intent_payload',
    'billing_checkout_lock_until',
    'billing_checkout_lock_token',
    'account_deletion_started_at',
    'intimacy_score',
    'intimacy_stage',
    'trust',
    'chemistry',
    'affection',
    'safety',
];
grace_same(
    [],
    array_values(array_intersect(array_keys($fields_before), $forbidden_fields)),
    'grace enrollment manufactures neither billing nor relationship state'
);

$relationship_state = [
    'intimacy_score' => 58,
    'intimacy_stage' => 'intimate',
    'trust' => 47,
    'chemistry' => 61,
    'affection' => 63,
    'safety' => 88,
    'relationship_history_json' => '[{"turn":42,"mutual":true}]',
];
$enrolled_values = array_merge([
    'user_id' => 42,
    'subscription_status' => 'trial',
    'subscription_plan' => null,
    'stripe_customer_id' => null,
    'stripe_subscription_id' => null,
    'subscription_current_period_end' => null,
    'billing_migration_status' => 'none',
    'billing_account_generation' => null,
    'membership_bonus_access_until' => null,
], $relationship_state, $fields_before);
$enrolled = (object) $enrolled_values;

grace_same(true, aimee_global_service_grace_profile_is_enrolled($enrolled), 'generated profile fields identify the enrolled cohort');
grace_same(true, aimee_global_service_grace_is_active($enrolled, $before_boundary), 'service grace is active one second before the cutoff');
grace_same(false, aimee_global_service_grace_is_active($enrolled, $boundary), 'service grace expires exactly at the cutoff');
grace_same(false, aimee_global_service_grace_is_active($enrolled, $after_boundary), 'service grace remains expired after the cutoff');

$timezone_before = date_default_timezone_get();
date_default_timezone_set('America/Los_Angeles');
grace_same(true, aimee_global_service_grace_is_active($enrolled, $before_boundary), 'host timezone cannot move the London cutoff earlier');
grace_same(false, aimee_global_service_grace_is_active($enrolled, $boundary), 'host timezone cannot move the London cutoff later');
date_default_timezone_set($timezone_before);

// Missing profiles and profiles created after the cutoff receive no grant.
$not_enrolled = (object) array_merge([
    'user_id' => 99,
    'subscription_status' => 'trial',
], $fields_at_boundary);
grace_same(false, aimee_global_service_grace_profile_is_enrolled(null), 'missing profile is not enrolled');
grace_same(false, aimee_global_service_grace_is_active(null, $before_boundary), 'missing profile receives no service-grace access');
grace_same(false, aimee_global_service_grace_requires_new_subscription(null, $boundary), 'missing profile is not assigned a billing requirement');
grace_same(false, aimee_global_managed_subscription_is_active(null, $boundary), 'missing profile has no managed subscription');
grace_same(false, aimee_global_membership_access_is_active(null, $before_boundary), 'missing profile has no membership entitlement');
grace_same(0, aimee_global_service_grace_first_payment_timestamp(null, $before_boundary), 'missing profile has no proposed payment date');
grace_same(false, aimee_global_service_grace_profile_is_enrolled($not_enrolled), 'profile created at the cutoff is outside the cohort');
grace_same(false, aimee_global_service_grace_is_active($not_enrolled, $before_boundary), 'unenrolled profile cannot spoof grace by supplying an earlier clock');

// -------------------------------------------------------------------------
// Access is separate from a real replacement subscription.
// -------------------------------------------------------------------------

grace_same(true, aimee_global_membership_access_is_active($enrolled, $before_boundary), 'enrolled unpaid profile has membership access during August');
grace_same(false, aimee_global_membership_access_is_active($enrolled, $boundary), 'unpaid grace access ends at the cutoff');
grace_same(false, aimee_global_service_grace_requires_new_subscription($enrolled, $before_boundary), 'new-subscription requirement does not falsely claim payment is due during grace');
grace_same(true, aimee_global_service_grace_requires_new_subscription($enrolled, $boundary), 'enrolled unpaid profile still requires a new subscription at the cutoff');
grace_same($boundary, aimee_global_service_grace_first_payment_timestamp($enrolled, $before_boundary), 'opt-in checkout before the cutoff schedules first payment at the exact boundary');
grace_same(0, aimee_global_service_grace_first_payment_timestamp($enrolled, $boundary), 'checkout at the cutoff uses normal immediate billing rather than a past trial date');

$closed_complete = clone $enrolled;
$closed_complete->stripe_customer_id = 'cus_closed_complete_42';
$closed_complete->stripe_subscription_id = 'sub_closed_complete_42';
$closed_complete->subscription_status = 'active';
$closed_complete->subscription_current_period_end = '2026-10-01 00:00:00';
$closed_complete->billing_migration_status = 'complete';
grace_same(false, aimee_global_managed_subscription_is_active($closed_complete, $boundary), 'pre-campaign complete status and live IDs do not prove replacement-account provenance');
grace_same(true, aimee_global_service_grace_requires_new_subscription($closed_complete, $boundary), 'pre-campaign complete row still requires a new September subscription');
grace_same(false, aimee_global_sms_membership_is_active($closed_complete, $before_boundary), 'closed-account status cannot fund carrier SMS during August');

$wrong_generation = clone $closed_complete;
$wrong_generation->billing_account_generation = 'closed_pre_2026_09';
grace_same(false, aimee_global_managed_subscription_is_active($wrong_generation, $boundary), 'wrong billing generation fails closed');

$managed = clone $enrolled;
$managed->billing_provider = 'stripe';
$managed->stripe_customer_id = 'cus_new_account_42';
$managed->stripe_subscription_id = 'sub_new_account_42';
$managed->subscription_status = 'active';
$managed->subscription_current_period_end = '2026-10-01 00:00:00';
$managed->billing_migration_status = 'complete';
$managed->billing_account_generation = aimee_global_current_billing_generation();
grace_same(true, aimee_global_managed_subscription_is_active($managed, $boundary), 'active replacement-account subscription is managed');
grace_same(true, aimee_global_membership_access_is_active($managed, $boundary), 'replacement subscription preserves access after grace');
grace_same(false, aimee_global_service_grace_requires_new_subscription($managed, $boundary), 'valid replacement subscription satisfies the September requirement');
grace_same(false, aimee_global_service_grace_requires_new_subscription($managed, $after_boundary), 'managed member remains satisfied after the cutoff');
grace_same(false, aimee_global_sms_membership_is_active($managed, $boundary), 'managed billing alone does not prove SMS destination ownership');

$managed_gocardless = clone $enrolled;
$managed_gocardless->billing_provider = 'gocardless';
$managed_gocardless->gocardless_mandate_id = 'MD000CURRENT42';
$managed_gocardless->subscription_status = 'active';
$managed_gocardless->subscription_current_period_end = '2026-10-01 00:00:00';
$managed_gocardless->billing_migration_status = 'complete';
$managed_gocardless->billing_account_generation = aimee_gocardless_generation();
grace_same(true, aimee_global_managed_subscription_is_active($managed_gocardless, $boundary), 'active current-generation GoCardless membership is managed');
$wrong_gocardless_generation = clone $managed_gocardless;
$wrong_gocardless_generation->billing_account_generation = aimee_global_current_billing_generation();
grace_same(false, aimee_global_managed_subscription_is_active($wrong_gocardless_generation, $boundary), 'GoCardless cannot inherit the current Stripe generation');
$wrong_stripe_generation = clone $managed;
$wrong_stripe_generation->billing_account_generation = aimee_gocardless_generation();
grace_same(false, aimee_global_managed_subscription_is_active($wrong_stripe_generation, $boundary), 'Stripe cannot inherit the current GoCardless generation');

$verified_sms = clone $managed;
$verified_sms->phone_number = '447700900123';
$verified_sms->phone_verified_number = '447700900123';
$verified_sms->phone_verified_at = '2026-08-03 10:00:00';
$verified_sms->sms_timezone = 'Europe/London';
grace_same(true, aimee_global_sms_profile_is_verified($verified_sms), 'matching verified mobile and IANA timezone prove SMS readiness');
grace_same(true, aimee_global_sms_membership_is_active($verified_sms, $boundary), 'verified managed member may use carrier SMS');

$mismatched_sms = clone $verified_sms;
$mismatched_sms->phone_number = '447700900124';
grace_same(false, aimee_global_sms_profile_is_verified($mismatched_sms), 'editable contact mismatch revokes SMS verification');
grace_same(false, aimee_global_sms_membership_is_active($mismatched_sms, $boundary), 'mismatched mobile fails carrier SMS closed');

$timezone_missing_sms = clone $verified_sms;
$timezone_missing_sms->sms_timezone = '';
grace_same(false, aimee_global_sms_profile_is_verified($timezone_missing_sms), 'missing recipient timezone fails SMS verification closed');

$invalid_timezone_sms = clone $verified_sms;
$invalid_timezone_sms->sms_timezone = 'BST';
grace_same(false, aimee_global_sms_profile_is_verified($invalid_timezone_sms), 'timezone abbreviations cannot replace a recipient IANA timezone');

$grace_only_verified_sms = clone $enrolled;
$grace_only_verified_sms->phone_number = '447700900125';
$grace_only_verified_sms->phone_verified_number = '447700900125';
$grace_only_verified_sms->phone_verified_at = '2026-08-03 10:00:00';
$grace_only_verified_sms->sms_timezone = 'Europe/London';
grace_same(false, aimee_global_sms_membership_is_active($grace_only_verified_sms, $before_boundary), 'August in-app grant never funds carrier SMS even for a verified phone');

$legacy = clone $enrolled;
$legacy->subscription_status = 'active';
$legacy->subscription_current_period_end = '2026-10-01 00:00:00';
$legacy->legacy_stripe_customer_id = 'cus_closed_account_42';
$legacy->legacy_stripe_subscription_id = 'sub_closed_account_42';
$legacy->billing_migration_status = 'legacy_reactivation_required';
grace_same(false, aimee_global_managed_subscription_is_active($legacy, $boundary), 'closed-account legacy identifiers never count as a managed subscription');
grace_same(true, aimee_global_service_grace_requires_new_subscription($legacy, $boundary), 'legacy active status cannot waive the replacement-subscription requirement');
grace_same(false, aimee_global_membership_access_is_active($legacy, $boundary), 'legacy status alone cannot extend access beyond the grace cutoff');

$stale_live_id = clone $legacy;
$stale_live_id->stripe_customer_id = 'cus_stale_closed_account_42';
$stale_live_id->stripe_subscription_id = 'sub_stale_closed_account_42';
grace_same(false, aimee_global_managed_subscription_is_active($stale_live_id, $boundary), 'legacy-reactivation state quarantines stale IDs left in live columns');

foreach (['cancelled', 'canceled', 'past_due', 'unpaid', 'incomplete', 'paused'] as $invalid_status) {
    $invalid = clone $managed;
    $invalid->subscription_status = $invalid_status;
    grace_same(
        false,
        aimee_global_managed_subscription_is_active($invalid, $boundary),
        "{$invalid_status} replacement subscription does not count as active"
    );
    grace_same(
        true,
        aimee_global_service_grace_requires_new_subscription($invalid, $boundary),
        "{$invalid_status} member must create or repair a subscription"
    );
}

$expired_managed = clone $managed;
$expired_managed->subscription_current_period_end = '2026-08-31 22:59:59';
grace_same(false, aimee_global_managed_subscription_is_active($expired_managed, $boundary), 'replacement subscription must be current at the evaluated instant');

$missing_end_managed = clone $managed;
$missing_end_managed->subscription_current_period_end = null;
grace_same(false, aimee_global_managed_subscription_is_active($missing_end_managed, $boundary), 'replacement subscription with no verified period end fails closed');

$modern_period = aimee_global_stripe_subscription_period_bounds([
    'items' => ['data' => [
        ['current_period_start' => 1788217200, 'current_period_end' => 1790809200],
    ]],
]);
grace_same(
    ['start' => 1788217200, 'end' => 1790809200],
    $modern_period,
    'current Stripe item-level subscription periods are normalized'
);
grace_same(
    ['start' => 10, 'end' => 20],
    aimee_global_stripe_subscription_period_bounds([
        'current_period_start' => 10,
        'current_period_end' => 20,
        'items' => ['data' => [['current_period_start' => 30, 'current_period_end' => 40]]],
    ]),
    'classic top-level subscription periods remain authoritative when present'
);
grace_same(
    'sub_modern_invoice',
    aimee_global_stripe_invoice_subscription_id([
        'parent' => [
            'type' => 'subscription_details',
            'subscription_details' => ['subscription' => 'sub_modern_invoice'],
        ],
    ]),
    'current Stripe invoice parent resolves its subscription'
);
grace_same(
    'sub_classic_invoice',
    aimee_global_stripe_invoice_subscription_id(['subscription' => 'sub_classic_invoice']),
    'classic Stripe invoice subscription field remains supported'
);

$later_bonus = clone $enrolled;
$later_bonus->membership_bonus_access_until = '2026-09-15 00:00:00';
grace_same(true, aimee_global_membership_access_is_active($later_bonus, $boundary), 'a later individually granted bonus remains a valid access entitlement');
grace_same(1789430400, aimee_global_service_grace_first_payment_timestamp($later_bonus, $boundary), 'post-cutoff checkout preserves a later individual bonus before charging');
grace_same(0, aimee_global_goodwill_checkout_block_until($later_bonus, $boundary), 'goodwill beyond Stripe minimum can be deferred through checkout');

$near_bonus = clone $enrolled;
$near_bonus_end = $boundary + (24 * 60 * 60);
$near_bonus->membership_bonus_access_until = gmdate('Y-m-d H:i:s', $near_bonus_end);
grace_same(
    $near_bonus_end,
    aimee_global_goodwill_checkout_block_until($near_bonus, $boundary),
    'goodwill inside Stripe minimum blocks checkout instead of charging early'
);
grace_same(
    0,
    aimee_global_goodwill_checkout_block_until($near_bonus, $near_bonus_end),
    'expired goodwill does not block normal checkout'
);

foreach (['cancelled', 'canceled', 'incomplete_expired'] as $terminal_status) {
    grace_same(
        true,
        aimee_global_subscription_status_is_terminal($terminal_status),
        "{$terminal_status} is a terminal non-recurring subscription state"
    );
}
grace_same(
    false,
    aimee_global_subscription_status_is_terminal('past_due'),
    'past_due remains repairable rather than terminal'
);

// A different live identity in the current generation is never last-writer-wins.
$first_sync = clone $enrolled;
$first_sync->stripe_subscription_id = null;
$first_sync->billing_account_generation = null;
grace_same(
    false,
    aimee_global_subscription_identity_conflicts(
        $first_sync,
        'sub_first',
        aimee_global_current_billing_generation()
    ),
    'an empty profile permits its first verified subscription sync'
);

$same_identity = clone $managed;
grace_same(
    false,
    aimee_global_subscription_identity_conflicts(
        $same_identity,
        'sub_new_account_42',
        aimee_global_current_billing_generation()
    ),
    'the same current-generation subscription remains idempotently syncable'
);

$stale_identity = clone $managed;
$stale_identity->stripe_subscription_id = 'sub_closed_generation';
$stale_identity->billing_account_generation = 'closed_pre_2026_09';
grace_same(
    false,
    aimee_global_subscription_identity_conflicts(
        $stale_identity,
        'sub_replacement',
        aimee_global_current_billing_generation()
    ),
    'a stale-generation identity can be replaced by the first verified current subscription'
);

foreach (['active', 'trialing', 'incomplete', 'past_due', 'unpaid', 'paused'] as $live_status) {
    $live_identity = clone $managed;
    $live_identity->subscription_status = $live_status;
    grace_same(
        true,
        aimee_global_subscription_identity_conflicts(
            $live_identity,
            'sub_competing',
            aimee_global_current_billing_generation()
        ),
        "a different {$live_status} current-generation subscription requires reconciliation"
    );
}

foreach (['cancelled', 'canceled', 'incomplete_expired'] as $replaceable_status) {
    $terminal_identity = clone $managed;
    $terminal_identity->subscription_status = $replaceable_status;
    grace_same(
        false,
        aimee_global_subscription_identity_conflicts(
            $terminal_identity,
            'sub_after_terminal',
            aimee_global_current_billing_generation()
        ),
        "a {$replaceable_status} predecessor permits a new verified subscription"
    );
}
grace_same(
    true,
    aimee_global_subscription_identity_conflicts(
        $managed,
        '',
        aimee_global_current_billing_generation()
    ),
    'an empty incoming subscription identity fails closed'
);

// Every helper is observational: even object-valued input must remain byte-for-byte
// unchanged, including all relationship and consent-adjacent fields.
$before_helpers = serialize($enrolled);
aimee_global_service_grace_profile_is_enrolled($enrolled);
aimee_global_service_grace_is_active($enrolled, $before_boundary);
aimee_global_service_grace_requires_new_subscription($enrolled, $before_boundary);
aimee_global_managed_subscription_is_active($enrolled, $before_boundary);
aimee_global_membership_access_is_active($enrolled, $before_boundary);
aimee_global_service_grace_first_payment_timestamp($enrolled, $before_boundary);
aimee_global_goodwill_checkout_block_until($enrolled, $before_boundary);
aimee_global_subscription_status_is_terminal($enrolled->subscription_status);
aimee_global_subscription_identity_conflicts(
    $enrolled,
    'sub_observational_check',
    aimee_global_current_billing_generation()
);
grace_same($before_helpers, serialize($enrolled), 'service-grace helpers never mutate subscription or relationship state');

echo "\nService-grace regression: {$passes} passed, {$failures} failed.\n";
exit($failures === 0 ? 0 : 1);
