# Suggested patch for Aimee Global: report the real GoCardless create error

`includes/gocardless.php`, inside `aimee_gocardless_checkout()`, after the
Billing Request create. Aimee Global 1.8.10 discards the provider's response
when the create fails and reports every failure as "ambiguous":

```php
$created = aimee_gocardless_idempotent_create('billing_requests', 'billing_requests', $billing_request_payload, $idem);
$created_br = is_wp_error($created) ? [] : aimee_gocardless_unwrap($created, 'billing_requests');

$intent_matches = aimee_gocardless_list_billing_requests_for_intent($checkout_intent_token);
if (is_wp_error($intent_matches) || count($intent_matches) !== 1) {
    ... 'The bank checkout create result is ambiguous and will not be repeated.'
}
```

A definitive 4xx from GoCardless (a validation error, a scheme the creditor
is not enabled for, a bad purpose code) is not ambiguous: nothing was created
and the request can be corrected. Only a transport failure or a 5xx, where
the create may or may not have happened, is ambiguous. Suggested change:

```php
$created = aimee_gocardless_idempotent_create('billing_requests', 'billing_requests', $billing_request_payload, $idem);
if (is_wp_error($created)) {
    $api_status = aimee_gocardless_api_error_status($created);
    if ($api_status >= 400 && $api_status < 500 && $created->get_error_code() !== 'gc_idempotency_reconciliation_unknown') {
        // Definitive rejection: nothing exists at the provider. Reset the
        // intent so the next attempt can start cleanly, and say why.
        aimee_gocardless_profile_update_verified($user_id, [
            'billing_checkout_intent_status' => 'prepared',
        ], [
            'billing_checkout_lock_token' => $checkout_lock_token,
            'billing_checkout_intent_token' => $checkout_intent_token,
        ]);
        return new WP_REST_Response([
            'status'  => 'bank_checkout_rejected',
            'message' => 'The bank could not set up this membership: ' . $created->get_error_message(),
            'provider_status' => $api_status,
        ], 502);
    }
}
$created_br = is_wp_error($created) ? [] : aimee_gocardless_unwrap($created, 'billing_requests');
```

The existing reconciliation path below it then only handles the genuinely
ambiguous cases. Until this lands in an Aimee Global release, Aimee Engine's
settings page records the provider error under "Bank checkout diagnostics".
