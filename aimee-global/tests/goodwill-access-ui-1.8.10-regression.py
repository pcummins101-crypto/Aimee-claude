#!/usr/bin/env python3
"""Static regression for temporary goodwill access UI precedence in 1.8.10."""

from __future__ import annotations

import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
BOOTSTRAP = (ROOT / "aimee-global.php").read_text(encoding="utf-8")
ENGINE = (ROOT / "includes/engine.php").read_text(encoding="utf-8")
LEGACY_UI = (ROOT / "includes/legacy-ui.php").read_text(encoding="utf-8")
CHAT_TEST = (ROOT / "tests/chat-notice-regression.mjs").read_text(encoding="utf-8")
RUNNERS = "\n".join(
    (ROOT / path).read_text(encoding="utf-8")
    for path in ("tests/run-audit-suite.py", "tests/run-native-audit-suite.py")
)
PRICING = {
    path: (ROOT / path).read_text(encoding="utf-8")
    for path in (
        "templates/pricing-uk.php",
        "templates/pricing-us.php",
        "templates/shared/pricing.php",
    )
}

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


def js_function(source: str, name: str) -> str:
    marker = f"    function {name}("
    start = source.find(marker)
    if start < 0:
        return ""
    next_function = source.find("\n    function ", start + len(marker))
    return source[start : next_function if next_function >= 0 else len(source)]


check(
    "Version: 1.8.11" in BOOTSTRAP
    and "define('AIMEE_GLOBAL_VERSION', '1.8.11')" in BOOTSTRAP
    and "define('AIMEE_GLOBAL_SCHEMA_VERSION', '2026.08.20.3')" in BOOTSTRAP,
    "release is 1.8.11 with no schema change for the UI-only fix",
)

snapshot_start = ENGINE.find("function aimee_get_subscription_snapshot")
snapshot_end = ENGINE.find("\nfunction ", snapshot_start + 20)
snapshot = ENGINE[snapshot_start:snapshot_end]
check(
    "'access_active'" in snapshot
    and "'access_source'" in snapshot
    and "'goodwill_extension'" in snapshot
    and "'bonus_access_until'" in snapshot
    and "'new_subscription_required'" in snapshot,
    "subscription snapshot preserves separate access and billing facts",
)

apply_body = js_function(LEGACY_UI, "apply")
check(
    'state.subscription.access_source === "goodwill_extension"' in apply_body
    and "state.subscription.access_active" in apply_body
    and "state.required = !state.goodwill" in apply_body,
    "legacy UI recognizes authoritative goodwill access before reactivation",
)
check(
    apply_body.find("state.goodwill = Boolean")
    < apply_body.find("state.required = !state.goodwill")
    < apply_body.find("mountChatCard"),
    "goodwill precedence is established before any chat notice is mounted",
)
check(
    'classList.toggle("aimee-goodwill-access-active", state.goodwill)' in apply_body
    and "!state.goodwill" in apply_body,
    "legacy UI exposes and cleans up a distinct goodwill state",
)

chat_mount = js_function(LEGACY_UI, "mountChatCard")
check(
    "if (state.goodwill)" in chat_mount
    and 'getElementById("aimee-billing-migration-card")?.remove()' in chat_mount
    and chat_mount.find("if (state.goodwill)") < chat_mount.find("Create your new Aimee membership"),
    "goodwill suppresses the expired-August chat membership card",
)

goodwill_copy = js_function(LEGACY_UI, "goodwillCopy")
goodwill_plain = js_function(LEGACY_UI, "goodwillPlainCopy")
goodwill_date = js_function(LEGACY_UI, "goodwillDate")
check(
    "temporary full in-app access is active" in goodwill_copy
    and "bonus_access_until || subscription.access_until" in LEGACY_UI
    and "did not create a subscription or schedule a payment" in goodwill_plain
    and "No new checkout is needed to keep using Aimee in-app" in LEGACY_UI
    and "any separate billing notice still needs attention" in LEGACY_UI,
    "goodwill copy uses the access expiry and makes no SMS or payment promise",
)
check(
    "toLocaleString" in goodwill_date
    and 'hour:"2-digit"' in goodwill_date
    and 'minute:"2-digit"' in goodwill_date
    and 'timeZoneName:"short"' in goodwill_date,
    "legacy goodwill copy names the exact localized access cutoff, not just its date",
)

boundary = js_function(LEGACY_UI, "scheduleBoundaryRefresh")
check(
    "goodwill_extension" in boundary
    and "bonus_access_until" in boundary
    and "access_until" in boundary
    and "6 * 60 * 60 * 1000" in boundary,
    "open chat tabs recheck goodwill access at a bounded expiry interval",
)

controls = js_function(LEGACY_UI, "updatePlanControls")
checkout = js_function(LEGACY_UI, "startCheckout")
check(
    "state.goodwill" in controls
    and "Temporary access active" in controls
    and "controlUnavailable" in controls,
    "legacy plan controls remain disabled with accurate temporary-access text",
)
check(
    checkout.find("if (state.goodwill)") >= 0
    and checkout.find("if (state.goodwill)") < checkout.find('api("/subscription-checkout"'),
    "legacy checkout entry point blocks goodwill users before any provider request",
)

restore = js_function(LEGACY_UI, "restoreControlledUi")
check(
    "restoreText" in restore
    and "restoreHtml" in restore
    and "restoreAttribute" in restore
    and "restoreDisplay" in restore
    and 'classList.remove("aimee-goodwill-access-active")' in restore,
    "legacy UI can restore every element temporarily controlled by goodwill",
)
check(
    "aimeeBillingControlledText" in LEGACY_UI
    and "aimeeBillingControlledHtml" in LEGACY_UI
    and "aimeeBillingControlledDisabled" in LEGACY_UI
    and "element.dataset.aimeeBillingOriginalDisabled === \"1\"" in LEGACY_UI,
    "goodwill cleanup is compare-and-restore and preserves an original disabled state",
)
check(
    "if (!state.required && !state.grace && !state.goodwill && !state.reconciliation)" in apply_body
    and "restoreControlledUi();" in apply_body,
    "a later ordinary or managed status restores the pre-goodwill interface",
)
check(
    "if (wasGoodwill) renderManagedTransitionUi(state.subscription);" in apply_body
    and "isManageableBilling(subscription)" in js_function(LEGACY_UI, "renderManagedTransitionUi")
    and 'subscription.access_source === "managed_subscription"' in js_function(LEGACY_UI, "renderManagedTransitionUi")
    and "!authoritativeAccess" in js_function(LEGACY_UI, "renderManagedTransitionUi")
    and "membership active" in js_function(LEGACY_UI, "renderManagedTransitionUi"),
    "goodwill-to-managed transition renders the authoritative new membership state",
)

click_start = LEGACY_UI.find('document.addEventListener("click", function(event)')
click_end = LEGACY_UI.find("window.startMembershipCheckout", click_start)
click_capture = LEGACY_UI[click_start:click_end]
portal = LEGACY_UI[LEGACY_UI.find("var originalOpenPortal"):LEGACY_UI.find("function scheduleStatusRetry")]
check(
    "if (state.goodwill)" in click_capture
    and "isManageableBilling(state.subscription)" in click_capture
    and "[data-billing-action=portal]" in click_capture
    and click_capture.find("isManageableBilling(state.subscription)") < click_capture.find("event.preventDefault()"),
    "goodwill click capture leaves genuine billing-management controls usable",
)
check(
    "if (state.goodwill)" in portal
    and "isManageableBilling(state.subscription)" in portal
    and "originalOpenPortal.apply" in portal,
    "goodwill billing-portal wrapper delegates an existing manageable subscription",
)
check(
    "Temporary access · Manage billing" in js_function(LEGACY_UI, "mountSettingsCard")
    and "manage your existing billing record" in js_function(LEGACY_UI, "mountSettingsCard")
    and "Temporary access · Manage billing" in controls,
    "legacy goodwill controls name their billing-management action when available",
)

delayed = js_function(LEGACY_UI, "containsDelayedBillingUi") + js_function(LEGACY_UI, "observeDelayedChatMount")
check(
    "#settings-membership-detail" in delayed
    and "#membership-status-display" in delayed
    and ".membership-checkout-btn" in delayed
    and "updateModalCopy" in delayed
    and "mountSettingsCard" in delayed
    and "updatePlanControls" in delayed,
    "delayed SPA membership UI receives the active goodwill state",
)

for path, source in PRICING.items():
    goodwill = source.find("const goodwillActive = Boolean(")
    required = source.find("const newSubscriptionRequired = Boolean(")
    banner = source.find("if (goodwillActive)", required)
    ended = source.find("Your complimentary August access has ended", banner)
    check(
        goodwill >= 0
        and required >= 0
        and "subscription.access_active" in source[goodwill:required]
        and "subscription.access_source === 'goodwill_extension'" in source[goodwill:required],
        f"{path} derives goodwill from the authoritative access fields",
    )
    check(
        banner >= 0
        and ended > banner
        and "Temporary full in-app access is active" in source[banner:ended]
        and "bonus_access_until || subscription.access_until" in source,
        f"{path} shows temporary access before any expired-August branch",
    )
    if path == "templates/pricing-us.php":
        plan_state_safe = "if (goodwillActive) button.textContent = 'Temporary in-app access active'" in source
    else:
        plan_state_safe = (
            "if (goodwillActive && !billingManageable) button.textContent = 'Temporary in-app access active'" in source
            and "else if (goodwillActive && isCurrent) button.textContent = 'Current Plan · Manage'" in source
            and "else if (goodwillActive) button.textContent = 'Manage current membership'" in source
        )
    check(
        plan_state_safe,
        f"{path} does not present an active goodwill user with a new-checkout CTA",
    )
    check(
        "freeButton.setAttribute('href', appUrl)" in source
        and source.find("freeButton.setAttribute('href', appUrl)") < source.find("} else if (serviceGraceActive)"),
        f"{path} routes the active-access card back to Aimee instead of membership checkout",
    )
    check(
        "goodwillDate.toLocaleString" in source
        and "hour: '2-digit'" in source
        and "minute: '2-digit'" in source
        and "timeZoneName: 'short'" in source,
        f"{path} renders the exact localized goodwill cutoff",
    )
    style_reset = source.find("migrationBanner.style.borderColor = '#f2bdcb'")
    goodwill_style = source.find("if (goodwillActive)", style_reset)
    check(
        style_reset >= 0 and goodwill_style > style_reset,
        f"{path} resets temporary green banner styling when the access state changes",
    )

for path in ("templates/pricing-uk.php", "templates/shared/pricing.php"):
    source = PRICING[path]
    click_guard = source.find(
        "currentSubscription?.access_source === 'goodwill_extension'"
    )
    checkout_call = source.find("await startCheckout(button, plan)", click_guard)
    check(
        click_guard >= 0
        and checkout_call > click_guard
        and "did not create or schedule a payment" in source[click_guard:checkout_call]
        and "currentSubscription?.can_manage_billing" in source[click_guard:checkout_call]
        and source.find("await openBillingPortal(button, plan)", click_guard) < source.find("did not create or schedule a payment", click_guard),
        f"{path} preserves managed billing access and otherwise intercepts goodwill plan clicks",
    )
    check(
        "const billingManageable = Boolean(subscription.can_manage_billing)" in source
        and "const unavailable = goodwillActive ? !billingManageable" in source
        and "button.removeAttribute('aria-disabled')" in source,
        f"{path} keeps a goodwill user's genuine billing-management action operable",
    )

check(
    "function goodwillSnapshot" in CHAT_TEST
    and 'access_source: "goodwill_extension"' in CHAT_TEST
    and "new_subscription_required: true" in CHAT_TEST
    and "suppresses the expired-August chat card" in CHAT_TEST
    and "restores the genuine membership prompt" in CHAT_TEST,
    "runtime chat regression covers both goodwill precedence and expiry",
)
check(
    "goodwill preserves and clearly labels genuine billing management" in CHAT_TEST
    and "mutation observer updates header and modal UI" in CHAT_TEST
    and "goodwill-to-managed transition restores" in CHAT_TEST
    and "never called active without authoritative managed access" in CHAT_TEST
    and "routes a manageable goodwill action to billing settings without checkout" in CHAT_TEST,
    "runtime chat regression covers management, delayed mounts and state restoration",
)
check(
    RUNNERS.count("goodwill-access-ui-1.8.10-regression.py") == 2,
    "both canonical audit runners execute the goodwill UI regression",
)

print(f"\nGoodwill UI static regression: {passes} passed, {failures} failed.")
sys.exit(1 if failures else 0)
