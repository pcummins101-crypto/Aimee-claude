#!/usr/bin/env python3
"""Focused regression for the 1.8.4 GoCardless-only checkout cutover.

New payment creation is deliberately narrower than legacy billing lifecycle
support: UK membership checkout may enter GoCardless, while US membership and
all new SMS-bundle checkout fail closed.  Stripe status, webhook, cancellation,
portal, deletion and pre-cutover intent reconciliation remain available solely
to drain provider state that already exists.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
BOOTSTRAP = (ROOT / "aimee-global.php").read_text(encoding="utf-8")
ENGINE = (ROOT / "includes/engine.php").read_text(encoding="utf-8")
GOCARDLESS = (ROOT / "includes/gocardless.php").read_text(encoding="utf-8")
PRICING_US = (ROOT / "templates/pricing-us.php").read_text(encoding="utf-8")
LANDING_US = (ROOT / "templates/landing-us.php").read_text(encoding="utf-8")
FAQ_US = (ROOT / "templates/faq-us.php").read_text(encoding="utf-8")
LEGACY_UI = (ROOT / "includes/legacy-ui.php").read_text(encoding="utf-8")
ALL_PHP = "\n".join(
    path.read_text(encoding="utf-8")
    for path in sorted(ROOT.rglob("*.php"))
    if "tests" not in path.parts
)

passes = 0
failures = 0


def check(condition: bool, label: str) -> None:
    global passes, failures
    if condition:
        passes += 1
        print(f"PASS {label}")
    else:
        failures += 1
        print(f"FAIL {label}")


def function_body(source: str, name: str) -> str:
    """Return a named PHP function body using balanced braces and strings."""

    match = re.search(rf"(?m)^function\s+{re.escape(name)}\s*\(", source)
    if not match:
        return ""
    brace = source.find("{", match.end())
    if brace < 0:
        return ""
    depth = 0
    quote = ""
    comment = ""
    escaped = False
    offset = brace
    while offset < len(source):
        char = source[offset]
        following = source[offset + 1] if offset + 1 < len(source) else ""
        if comment == "line":
            if char in "\r\n":
                comment = ""
            offset += 1
            continue
        if comment == "block":
            if char == "*" and following == "/":
                comment = ""
                offset += 2
            else:
                offset += 1
            continue
        if quote:
            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == quote:
                quote = ""
            offset += 1
            continue
        if char == "/" and following == "/":
            comment = "line"
            offset += 2
            continue
        if char == "/" and following == "*":
            comment = "block"
            offset += 2
            continue
        if char == "#":
            comment = "line"
            offset += 1
            continue
        if char in "'\"":
            quote = char
        elif char == "{":
            depth += 1
        elif char == "}":
            depth -= 1
            if depth == 0:
                return source[brace + 1 : offset]
        offset += 1
    return ""


market_supported = function_body(
    ENGINE, "aimee_new_membership_checkout_market_supported"
)
membership_checkout = function_body(ENGINE, "handle_aimee_subscription_checkout")
sms_checkout = function_body(ENGINE, "handle_aimee_sms_bundle_checkout")
snapshot = function_body(ENGINE, "aimee_get_subscription_snapshot")
retire_stripe = function_body(
    ENGINE, "aimee_membership_retire_stripe_before_bank_checkout"
)
stripe_status = function_body(ENGINE, "handle_aimee_subscription_status")
stripe_cancel = function_body(ENGINE, "handle_aimee_subscription_cancel")
stripe_portal = function_body(ENGINE, "handle_aimee_billing_portal")
stripe_webhook = function_body(ENGINE, "handle_aimee_stripe_webhook")
stripe_sync = function_body(ENGINE, "aimee_sync_subscription_from_stripe")
delete_account = function_body(ENGINE, "aimee_api_delete_account")
sms_status = function_body(ENGINE, "handle_aimee_sms_bundle_status")
sms_retire = function_body(
    ENGINE, "aimee_sms_bundle_retire_pending_sessions_for_user"
)
sms_resolve = function_body(ENGINE, "aimee_sms_bundle_resolve_pending_session")
stripe_reconcile = function_body(ENGINE, "aimee_stripe_reconcile_checkout_intent")
gc_checkout = function_body(GOCARDLESS, "aimee_gocardless_checkout")


check(
    "Version: 1.8.11" in BOOTSTRAP
    and "define('AIMEE_GLOBAL_VERSION', '1.8.11')" in BOOTSTRAP
    and "define('AIMEE_GLOBAL_SCHEMA_VERSION', '2026.08.20.3')" in BOOTSTRAP,
    "current release is 1.8.11 without an unnecessary schema bump",
)
check(
    "sanitize_key((string) $market) === 'uk'" in market_supported
    and "'us'" not in market_supported,
    "new membership checkout support is explicitly UK-only",
)

auth = membership_checkout.find("if (!$user_id)")
dispatch = membership_checkout.find(
    "if (aimee_new_membership_checkout_market_supported($checkout_market))"
)
gc_return = membership_checkout.find("return aimee_gocardless_checkout($request)")
market_block = membership_checkout.find("'status' => 'bank_checkout_market_unavailable'")
legacy_database = membership_checkout.find("global $wpdb")
legacy_stripe_identity = membership_checkout.find(
    "aimee_validate_replacement_stripe_account"
)
check(
    0 <= auth < dispatch < gc_return < market_block < legacy_database
    and "aimee_gocardless_ready()" in membership_checkout[dispatch:gc_return]
    and "'provider' => 'gocardless'" in membership_checkout[market_block:legacy_database]
    and "'checkout_available' => false" in membership_checkout[market_block:legacy_database],
    "authenticated UK membership checkout dispatches only to ready GoCardless",
)
check(
    market_block < legacy_database
    and market_block < legacy_stripe_identity
    and "], 409)" in membership_checkout[market_block:legacy_database]
    and "No payment was created" in membership_checkout[market_block:legacy_database],
    "unsupported markets return before frozen Stripe creation code is reachable",
)

sms_auth = sms_checkout.find("if (!$user_id)")
sms_disabled = sms_checkout.find("'status' => 'sms_bundle_checkout_unavailable'")
sms_rate_limit = sms_checkout.find("aimee_rate_limit('sms_bundle_checkout_")
sms_resolver = sms_checkout.find("aimee_sms_bundle_resolve_pending_session")
check(
    0 <= sms_auth < sms_disabled < sms_rate_limit < sms_resolver
    and "'checkout_available' => false" in sms_checkout[sms_disabled:sms_rate_limit]
    and "], 410)" in sms_checkout[sms_disabled:sms_rate_limit],
    "new SMS-bundle checkout returns 410 before any provider reconciliation or create",
)

sms_ui = ENGINE[ENGINE.find("// 9. SMS ALLOWANCE"):ENGINE.find("// 10. CONTINUITY")]
check(
    "Additional SMS bundles are not currently available through GoCardless." in sms_ui
    and "sms-bundle-checkout" not in sms_ui
    and "checkoutUrl" not in sms_ui
    and "buyBundle" not in sms_ui
    and "Add more texts" not in sms_ui
    and "verifyReturn" in sms_ui,
    "SMS UI shows balances and legacy return verification without a purchase control",
)

check(
    "GoCardless-only checkout" in PRICING_US
    and PRICING_US.count("US bank checkout unavailable") >= 3
    and "no Stripe or other card checkout will be opened" in PRICING_US
    and "/subscription-checkout" not in PRICING_US
    and 'class="btn-store btn-dark membership-action"' not in PRICING_US
    and 'class="btn-store btn-light membership-action"' not in PRICING_US,
    "US pricing exposes preview but no actionable paid-checkout control",
)
check(
    "New US paid checkout is currently unavailable" in LANDING_US
    and "no Stripe checkout is offered" in LANDING_US
    and "Paid memberships renew until cancelled" not in LANDING_US
    and "The reference US prices are" in FAQ_US
    and "no Stripe alternative is offered" in FAQ_US,
    "US landing and FAQ surfaces do not advertise a purchasable Stripe membership",
)
check(
    "var originalStartCheckout" not in LEGACY_UI
    and "window.startMembershipCheckout = function(plan, source, button)" in LEGACY_UI
    and 'return startCheckout(plan, source || "legacy-ui", button);' in LEGACY_UI
    and "if (requestedPlan) return startCheckout(requestedPlan" in LEGACY_UI,
    "legacy global checkout entry points cannot fall back to an old Stripe implementation",
)

stripe_checkout_create = re.compile(
    r"aimee_stripe_request\s*\(\s*['\"]POST['\"]\s*,\s*"
    r"['\"]checkout/sessions['\"]\s*,",
    re.MULTILINE,
)
check(
    stripe_checkout_create.search(ALL_PHP) is None,
    "plugin contains zero direct Stripe Checkout-session creation calls",
)
check(
    "legacy_stripe_sms_manual_reconciliation_required" in sms_resolve
    and "no new card checkout was created" in sms_resolve
    and "aimee_stripe_request(" in sms_resolve
    and "'GET'" in sms_resolve
    and "'POST'" not in sms_resolve,
    "ambiguous pre-cutover SMS placeholders require manual reconciliation without replay",
)
check(
    "stripe_checkout_creation_disabled" in stripe_reconcile
    and "legacy_stripe_checkout_manual_reconciliation_required" in stripe_reconcile
    and "['requesting', 'request_unknown']" in stripe_reconcile
    and "'POST'" not in stripe_reconcile,
    "ambiguous pre-cutover membership intents require manual reconciliation without replay",
)

check(
    "$checkout_block_until_ts = function_exists('aimee_global_service_grace_first_payment_timestamp')" in snapshot
    and "$checkout_available = aimee_new_membership_checkout_market_supported($market)" in snapshot
    and "&& !$is_admin" in snapshot
    and "&& !$managed_active" in snapshot
    and "&& $checkout_block_until_ts <= $now" in snapshot
    and "'checkout_available'           => $checkout_available" in snapshot
    and "'checkout_opens_at'" in snapshot
    and "'checkout_provider'            => aimee_new_membership_checkout_market_supported($market)" in snapshot
    and "? 'gocardless'" in snapshot,
    "subscription snapshots match the UK GoCardless preserved-access gate",
)

fair_access = gc_checkout.find("$fair_first_payment_at")
access_response = gc_checkout.find("'status'=>'existing_access_active'", fair_access)
open_payment = gc_checkout.find("$open_payment", access_response)
retire_existing_stripe = gc_checkout.find(
    "aimee_membership_retire_stripe_before_bank_checkout", open_payment
)
first_provider_create = gc_checkout.find("aimee_gocardless_idempotent_create")
check(
    0 <= fair_access < access_response < open_payment < retire_existing_stripe < first_provider_create
    and "'checkout_opens_at'=>gmdate('c', $fair_first_payment_at)" in gc_checkout
    and "'charge_today'=>false" in gc_checkout
    and "'payment_scheduled'=>false" in gc_checkout,
    "GoCardless checkout cannot charge before preserved access ends",
)
check(
    "if ($market !== 'uk')" in gc_checkout
    and "$currency = 'GBP'" in gc_checkout
    and "aimee_membership_retire_stripe_before_bank_checkout($profile)" in gc_checkout
    and "provider_transition_blocked" in gc_checkout
    and "'session_id'" not in gc_checkout,
    "GoCardless creation is UK/GBP and fail-closed across a Stripe-to-bank transition",
)

terminal_verify = retire_stripe.find("aimee_stripe_subscription_is_verified_terminal")
clear_authority = retire_stripe.find("'stripe_checkout_session_id' => null")
fresh_verify = retire_stripe.find("$fresh = aimee_membership_billing_profile")
check(
    "aimee_expire_stripe_checkout_session_verified" in retire_stripe
    and 0 <= terminal_verify < clear_authority < fresh_verify
    and "stripe_authority_clear_unverified" in retire_stripe,
    "legacy Stripe authority is terminal-proved and re-read as cleared before bank checkout",
)

check(
    "$requested_exact_stored_stripe_session" in stripe_status
    and "hash_equals($stored_routing_stripe_session, $requested_session_id)" in stripe_status
    and "$routing_provider === 'gocardless' && !$requested_exact_stored_stripe_session" in stripe_status
    and "aimee_gocardless_subscription_status" in stripe_status
    and "aimee_stripe_request" in stripe_status
    and "aimee_gocardless_cancel($request)" in stripe_cancel
    and "aimee_membership_billing_provider($profile) !== 'stripe'" in stripe_cancel
    and "aimee_stripe_request" in stripe_cancel
    and "aimee_gocardless_portal($request)" in stripe_portal
    and "billing_portal/sessions" in stripe_portal
    and "Stripe-Signature" in stripe_webhook,
    "provider-routed status, cancel, portal and Stripe webhook runoff remain available",
)
check(
    "aimee_expire_stripe_checkout_session_verified" in delete_account
    and "aimee_cancel_stripe_subscription_verified" in delete_account
    and "aimee_gocardless_retire_user_billing_for_deletion" in delete_account,
    "account deletion still terminal-proves both legacy Stripe and GoCardless authority",
)
check(
    "$stored_provider === 'gocardless'" in stripe_sync
    and "$exact_transition_intent" in stripe_sync
    and "aimee_gocardless_retire_user_billing_for_deletion" in stripe_sync
    and "subscription_provider_transition_blocked" in stripe_sync,
    "late Stripe events cannot silently reclaim a GoCardless-owned profile",
)
check(
    "aimee_sms_bundle_session_matches_purchase" in sms_status
    and "aimee_fulfill_sms_bundle_session" in sms_status
    and "aimee_sms_bundle_resolve_pending_session" in sms_retire
    and "aimee_expire_stripe_checkout_session_verified" in sms_retire,
    "pre-cutover SMS sessions retain exact reconciliation and retirement paths",
)


if failures:
    print(
        f"\nGOCARDLESS-ONLY CHECKOUT RESULT: {passes} checks passed, "
        f"{failures} failed"
    )
    sys.exit(1)

print(f"\nGOCARDLESS-ONLY CHECKOUT RESULT: {passes} checks passed, 0 failed")
