<?php
defined('ABSPATH') || exit;

/**
 * Locate the latest full Aimee application template already present in the
 * active theme. The original application remains the visual source of truth.
 */
function aimee_global_find_legacy_chat_template($prefer_us = false) {
    $cache_key = $prefer_us ? 'aimee_global_legacy_chat_us' : 'aimee_global_legacy_chat_uk';
    $cached = get_transient($cache_key);
    if (is_string($cached) && $cached !== '' && is_readable($cached)) {
        return $cached;
    }

    $roots = array_unique(array_filter([
        get_stylesheet_directory(),
        get_template_directory(),
    ]));

    $best = '';
    $best_score = -PHP_INT_MAX;
    $best_is_us = false;

    foreach ($roots as $root) {
        if (!is_dir($root)) continue;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
        } catch (UnexpectedValueException $e) {
            continue;
        }

        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
            if ($iterator->getDepth() > 3) continue;

            $path = $file->getPathname();
            if (strpos(wp_normalize_path($path), wp_normalize_path(AIMEE_GLOBAL_DIR)) === 0) continue;

            $name = strtolower($file->getFilename());
            if (strpos($name, 'aimee') === false && strpos($name, 'amy') === false) continue;

            $head = @file_get_contents($path, false, null, 0, 90000);
            if (!is_string($head) || $head === '') continue;

            $looks_like_app = (
                stripos($head, 'Template Name: Aimee App Prototype') !== false ||
                (stripos($head, 'id="chat-interface"') !== false && stripos($head, 'class="aimee-wrapper"') !== false)
            );
            if (!$looks_like_app) continue;

            $score = 0;
            if (stripos($head, 'id="home-screen"') !== false) $score += 30;
            if (stripos($head, 'id="edit-profile-screen"') !== false) $score += 30;
            if (stripos($head, 'id="gallery-screen"') !== false) $score += 20;
            if (stripos($head, 'voice-note') !== false) $score += 80;
            if (stripos($name, 'voice-notes-new-badge-mobile-wrap-fix') !== false) $score += 180;
            elseif (stripos($name, 'voice-notes-new-badge') !== false) $score += 150;
            elseif (stripos($name, 'voice-notes') !== false) $score += 120;
            elseif (stripos($name, 'memberships') !== false) $score += 70;
            if (stripos($name, 'faster-voice') !== false || stripos($name, 'faster voice') !== false) $score += 45;
            if (stripos($name, 'claudia') !== false) $score -= 500;

            $is_us_name = preg_match('/(^|[-_ ])us([-_ .]|$)|chat-us|usa/i', $name) === 1;
            if (!$prefer_us && $is_us_name) {
                continue;
            }
            if ($prefer_us) {
                $score += $is_us_name ? 300 : 0;
            } else {
                $score += $is_us_name ? -120 : 40;
            }

            $score += min(50, max(0, (int) (($file->getMTime() - 1700000000) / 1000000)));

            if ($score > $best_score) {
                $best_score = $score;
                $best = $path;
                $best_is_us = $is_us_name;
            }
        }
    }

    if ($prefer_us && !$best_is_us) return '';
    if ($best !== '') set_transient($cache_key, $best, DAY_IN_SECONDS);
    return $best;
}

function aimee_global_legacy_chat_status() {
    return [
        'uk' => aimee_global_find_legacy_chat_template(false),
        'us' => aimee_global_find_legacy_chat_template(true),
    ];
}

/** Authenticated privacy choices for theme-supplied legacy application UIs. */
function aimee_global_chat_privacy_consent_markup($market) {
    if (!is_user_logged_in()) return '';
    $market = $market === 'us' ? 'us' : 'uk';
    $config = wp_json_encode([
        'apiBase' => esc_url_raw(rest_url('aimee/v1')),
        'nonce' => wp_create_nonce('wp_rest'),
        'privacyUrl' => function_exists('aimee_global_route')
            ? aimee_global_route('privacy', $market)
            : home_url($market === 'us' ? '/privacy-us/' : '/privacy/'),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return '<script>window.AIMEE_PRIVACY_CONFIG=' . $config . ';</script>' . <<<'AIMEE_PRIVACY_HTML'
<style id="aimee-privacy-consent-style">
#aimee-privacy-consent-panel{margin:14px 0;padding:14px;border:1px solid rgba(136,19,55,.2);border-radius:15px;background:#fff8fa;color:#4b1628;font:500 12px/1.5 Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
#aimee-privacy-consent-panel strong{display:block;margin-bottom:4px;font-size:14px}#aimee-privacy-consent-panel label{display:flex;gap:9px;align-items:flex-start;margin:10px 0}#aimee-privacy-consent-panel input{margin-top:3px}#aimee-privacy-consent-panel button{border:0;border-radius:999px;padding:9px 14px;background:#18181b;color:#fff;font-weight:750;cursor:pointer}#aimee-privacy-consent-status{margin-left:9px}
</style>
<script id="aimee-privacy-consent-ui">
(function(config){
    "use strict";
    if (!config || !config.nonce || document.getElementById("aimee-privacy-consent-panel")) return;
    var privacyState = null;
    var settingsObserver = null;

    function api(method, body){
        return fetch(config.apiBase.replace(/\/$/, "") + "/privacy-consent", {
            method: method,
            credentials: "same-origin",
            headers: {"Accept":"application/json","Content-Type":"application/json","X-WP-Nonce":config.nonce},
            body: body ? JSON.stringify(body) : undefined
        }).then(function(response){
            return response.json().catch(function(){ return {}; }).then(function(data){
                if (!response.ok) throw new Error(data.message || "Privacy settings could not be saved.");
                return data;
            });
        });
    }

    function isOnboardingNode(node){
        return !!(node && node.closest && node.closest(
            "#onboarding-screen,#onboarding-view-wrapper,#onboard,.onboarding-screen,.onboarding-view,[data-onboarding]"
        ));
    }

    function settingsHost(){
        var direct = document.querySelector(
            "#edit-profile-screen form,#settings-modal form,#edit-profile-modal form," +
            "#account-settings form,#profile-settings form,.settings-screen form,.settings-container form," +
            "#edit-profile-screen .settings-container,#settings-modal .settings-container"
        );
        if (direct && !isOnboardingNode(direct)) return direct;

        var settingsField = document.querySelector(
            "#edit-sms-opt-in,input[name=\"sms_opt_in\"],input[name=\"sms_override\"]," +
            "#edit-profile-screen input[name=\"phone_number\"],.settings-screen input[name=\"phone_number\"]"
        );
        if (!settingsField || isOnboardingNode(settingsField)) return null;
        return settingsField.closest("form,.settings-container,.settings-screen,#edit-profile-screen") || null;
    }

    function buildPanel(){
        var panel = document.createElement("section");
        panel.id = "aimee-privacy-consent-panel";
        panel.setAttribute("data-aimee-privacy-mode", "settings");
        panel.innerHTML = '<strong>Privacy choices</strong>' +
            '<div>Read the <a target="_blank" rel="noopener">privacy notice</a> at any time. No acknowledgement is required to use ordinary chat.</div>' +
            '<label><input type="checkbox" data-aimee-special-consent><span><b>Optional:</b> I explicitly consent to processing sensitive information I choose to share. Unticking and saving withdraws consent immediately and disables specialist adult processing. Ordinary chat remains available.</span></label>' +
            '<button type="button" data-aimee-consent-save>Save privacy choices</button><span id="aimee-privacy-consent-status" role="status"></span>';
        panel.querySelector("a").href = config.privacyUrl;

        var special = panel.querySelector("[data-aimee-special-consent]");
        var save = panel.querySelector("[data-aimee-consent-save]");
        var status = panel.querySelector("#aimee-privacy-consent-status");
        special.checked = Boolean(privacyState && privacyState.special_category_consent);
        save.addEventListener("click", function(){
            save.disabled = true; status.textContent = "Saving…";
            api("POST", {special_category_consent:special.checked})
                .then(function(data){
                    privacyState = data;
                    special.checked = Boolean(data.special_category_consent);
                    status.textContent = "Saved";
                })
                .catch(function(error){ status.textContent = error.message; })
                .then(function(){ save.disabled = false; });
        });
        return panel;
    }

    function mountSettingsPanel(){
        var host = settingsHost();
        if (!host || document.getElementById("aimee-privacy-consent-panel")) return;
        host.appendChild(buildPanel());
    }

    function watchForSettings(){
        if (settingsObserver || typeof MutationObserver !== "function") return;
        settingsObserver = new MutationObserver(function(records){
            var hasAddition = records.some(function(record){ return record.addedNodes && record.addedNodes.length; });
            if (hasAddition) mountSettingsPanel();
        });
        settingsObserver.observe(document.documentElement, {childList:true,subtree:true});

        document.addEventListener("click", function(event){
            var trigger = event.target && event.target.closest
                ? event.target.closest("#settings,[data-settings],[data-open-settings],button[id*=\"setting\"],a[href*=\"setting\"]")
                : null;
            if (trigger) setTimeout(mountSettingsPanel, 0);
        }, true);
    }

    function mount(){
        api("GET").then(function(data){
            privacyState = data;
            mountSettingsPanel();
            watchForSettings();
        }).catch(function(error){
            // Privacy preference loading must never block ordinary chat. Keep
            // the optional control available in settings so it can be retried.
            privacyState = {special_category_consent:false};
            mountSettingsPanel();
            var status = document.getElementById("aimee-privacy-consent-status");
            if (status) status.textContent = error.message;
            watchForSettings();
        });
    }
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", mount);
    else mount();
})(window.AIMEE_PRIVACY_CONFIG);
</script>
AIMEE_PRIVACY_HTML;
}

/**
 * Inject the closed-account subscription reconnection experience into the
 * original Aimee application without changing its visual source template.
 */
function aimee_global_chat_billing_migration_markup($market) {
    if (!is_user_logged_in()) return '';

    $market = $market === 'us' ? 'us' : 'uk';
    $config = [
        'apiBase'                  => esc_url_raw(rest_url('aimee/v1')),
        'nonce'                    => wp_create_nonce('wp_rest'),
        'market'                   => $market,
        'checkoutMarketSupported' => $market === 'uk',
        'pricingUrl'               => function_exists('aimee_global_route')
            ? aimee_global_route('pricing', $market)
            : home_url($market === 'us' ? '/pricing-us/' : '/pricing/'),
    ];

    $json = wp_json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $markup = <<<'AIMEE_BILLING_HTML'
<style id="aimee-billing-migration-style">
#aimee-billing-migration-card,#aimee-service-grace-card,#aimee-billing-reconciliation-card{position:relative;width:calc(100% - 20px);margin:0 10px 8px;padding:14px 42px 14px 15px;border:1px solid rgba(225,29,72,.24);border-radius:17px;background:linear-gradient(135deg,#fff6f8 0%,#fff 72%);box-shadow:0 10px 28px rgba(91,15,43,.09);color:#4b1628;font:500 12px/1.5 Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;flex-shrink:0}
#aimee-service-grace-card{border-color:rgba(5,150,105,.28);background:linear-gradient(135deg,#ecfdf5 0%,#fff 72%);color:#064e3b}
#aimee-billing-reconciliation-card{border-color:rgba(217,119,6,.35);background:linear-gradient(135deg,#fffbeb 0%,#fff 72%);color:#78350f}
#aimee-billing-migration-card strong,#aimee-service-grace-card strong,#aimee-billing-reconciliation-card strong{display:block;margin-bottom:3px;color:#881337;font-size:13px;font-weight:800}
#aimee-service-grace-card strong{color:#065f46}
#aimee-billing-reconciliation-card strong{color:#92400e}
#aimee-billing-migration-card p,#aimee-service-grace-card p,#aimee-billing-reconciliation-card p{margin:0;color:#6b2940;font-size:12px;line-height:1.5}
#aimee-service-grace-card p{color:#166534}
#aimee-billing-reconciliation-card p{color:#92400e}
.aimee-billing-migration-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
.aimee-billing-migration-action{appearance:none;border:0;border-radius:999px;padding:9px 13px;font:750 11px/1 Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}
.aimee-billing-migration-action.primary{background:#18181b;color:#fff}
.aimee-billing-migration-action.secondary{background:#fff;color:#6b1735;border:1px solid #efc4d1}
.aimee-billing-migration-action[disabled],.aimee-billing-migration-action[aria-disabled="true"]{cursor:not-allowed;opacity:.58}
.aimee-billing-migration-dismiss{position:absolute;top:8px;right:9px;width:28px;height:28px;padding:0;border:0;border-radius:50%;background:transparent;color:#8b5d6d;font-size:20px;line-height:28px;cursor:pointer}
#aimee-settings-billing-migration{margin-top:14px;padding:15px;border:1px solid #efc4d1;border-radius:16px;background:#fff7f9;color:#621c35;font:500 12px/1.55 Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
#aimee-settings-billing-migration strong{display:block;margin-bottom:4px;color:#881337;font-size:14px}
#aimee-settings-billing-migration.aimee-goodwill-access-active{border-color:#a7f3d0;background:#f0fdf4;color:#166534}
#aimee-settings-billing-migration.aimee-goodwill-access-active strong{color:#065f46}
#aimee-settings-billing-migration .aimee-billing-migration-actions{margin-top:11px}
.membership-status-card.aimee-billing-migration-active{border-color:#efc4d1!important;background:linear-gradient(145deg,#fff7f9,#fff)!important}
.membership-status-card.aimee-goodwill-access-active{border-color:#a7f3d0!important;background:linear-gradient(145deg,#f0fdf4,#fff)!important}
@media(max-width:640px){#aimee-billing-migration-card,#aimee-service-grace-card,#aimee-billing-reconciliation-card{padding:12px 38px 12px 13px;margin-bottom:6px}.aimee-billing-migration-actions{gap:6px}.aimee-billing-migration-action{padding:9px 11px}}
</style>
<script id="aimee-billing-migration-ui">
(function(config){
    "use strict";
    if (!config || !config.nonce) return;

    var state = {
        subscription: null,
        required: false,
        grace: false,
        goodwill: false,
        reconciliation: false,
        boundaryTimer: null,
        retryTimer: null,
        retryAttempt: 0,
        refreshInFlight: null,
        mountObserver: null,
        checkoutAvailable: Boolean(config.checkoutMarketSupported)
    };
    window.__aimeeBillingMigration = state;

    function api(path, options){
        options = options || {};
        var headers = new Headers(options.headers || {});
        headers.set("Accept", "application/json");
        headers.set("X-WP-Nonce", config.nonce);
        if (options.body && !headers.has("Content-Type")) headers.set("Content-Type", "application/json");
        return fetch(config.apiBase.replace(/\/$/, "") + path, Object.assign({credentials:"same-origin"}, options, {headers:headers}))
            .then(function(response){
                return response.json().catch(function(){ return {}; }).then(function(data){
                    if (!response.ok) {
                        var error = new Error(data.message || "A secure billing connection could not be established.");
                        error.data = data;
                        throw error;
                    }
                    return data;
                });
            });
    }

    function planName(plan){
        return ({weekly:"Weekly",monthly:"Monthly",annual:"Annual"})[String(plan || "").toLowerCase()] || "Monthly";
    }

    function isManageableBilling(subscription){
        var status = String(subscription.billing_status || subscription.status || "").toLowerCase();
        return Boolean(
            subscription.can_manage_billing
            && ["active","trialing","past_due","unpaid","paused"].indexOf(status) >= 0
        );
    }

    function sessionValue(key){
        try { return window.sessionStorage ? window.sessionStorage.getItem(key) : sessionStorage.getItem(key); }
        catch (error) { return null; }
    }

    function rememberSessionValue(key, value){
        try {
            if (window.sessionStorage) window.sessionStorage.setItem(key, value);
            else sessionStorage.setItem(key, value);
        } catch (error) {}
    }

    function formatDate(value){
        if (!value) return "";
        var date = new Date(value);
        if (Number.isNaN(date.getTime())) return "";
        return date.toLocaleDateString(config.market === "us" ? "en-US" : "en-GB", {day:"numeric",month:"long",year:"numeric"});
    }

    function accessCopy(subscription){
        if (!config.checkoutMarketSupported) {
            var usDate = formatDate(subscription.legacy_access_until || subscription.current_period_end);
            if (subscription.legacy_access_active && usDate) {
                return "Your existing " + planName(subscription.plan) + " access remains active until <strong>" + usDate + "</strong>. New paid membership checkout is not currently available for US profiles.";
            }
            return "New paid membership checkout is not currently available for US profiles. No card or Stripe checkout alternative is offered.";
        }
        if (subscription.new_subscription_required) {
            return "Your complimentary August access has ended. You may create a new UK membership through GoCardless; no subscription or payment was created automatically.";
        }
        var date = formatDate(subscription.legacy_access_until || subscription.current_period_end);
        if (subscription.legacy_access_active && date) {
            return "Your existing " + planName(subscription.plan) + " access remains active until <strong>" + date + "</strong>. Your previous subscription was linked to our former payment account, which is now closed, so it cannot renew or take another payment.";
        }
        return "Your previous subscription was linked to our former payment account, which is now closed, so it cannot renew or take another payment. Reconnect securely to continue your membership.";
    }

    function timingCopy(subscription){
        if (!config.checkoutMarketSupported) {
            return "Existing legacy billing records can still be reviewed or closed where available, but they cannot be used to create a new membership.";
        }
        if (subscription.new_subscription_required) {
            return "Choose the plan that suits you. New UK membership checkout is provided only through GoCardless, and no payment is created until you explicitly continue there.";
        }
        var dateValue = subscription.legacy_access_until || "";
        var date = formatDate(dateValue);
        var timestamp = dateValue ? new Date(dateValue).getTime() : 0;
        var canDelay = timestamp && timestamp >= (Date.now() + (48 * 60 * 60 * 1000) + (5 * 60 * 1000));
        if (subscription.legacy_access_active && date && canDelay) {
            return "Your preserved access remains available through <strong>" + date + "</strong>. GoCardless checkout will not open before that access ends, and no replacement payment is scheduled automatically.";
        }
        return "Your previous subscription cannot take another payment. When checkout is available, a new UK membership can be created only by explicitly completing the GoCardless flow.";
    }

    function graceCopy(subscription){
        return "As a thank-you for your patience while we rebuild our payment flow, full in-app access is complimentary through <strong>31 August 2026</strong>, ending at <strong>00:00 on 1 September 2026 (UK time)</strong>. Any subscription held in our former Stripe account cannot renew, and no replacement subscription or payment has been created automatically.";
    }

    function graceTimingCopy(){
        if (!config.checkoutMarketSupported) {
            return "After the complimentary period, new paid membership checkout remains unavailable for US profiles. No replacement subscription or payment will be created automatically.";
        }
        return "From <strong>00:00 on 1 September 2026 (UK time)</strong>, anyone who wants to continue with member access will need to create a new subscription through GoCardless. Until then, keep enjoying Aimee on us. <strong>With thanks — Engram Intelligence.</strong>";
    }

    function goodwillDate(subscription){
        var value = subscription.bonus_access_until || subscription.access_until;
        if (!value) return "";
        var date = new Date(value);
        if (Number.isNaN(date.getTime())) return "";
        return date.toLocaleString(config.market === "us" ? "en-US" : "en-GB", {
            day:"numeric",month:"long",year:"numeric",hour:"2-digit",minute:"2-digit",timeZoneName:"short"
        });
    }

    function goodwillCopy(subscription){
        var date = goodwillDate(subscription);
        return "Your temporary full in-app access is active" + (date ? " until <strong>" + date + "</strong>" : "") + " while we resolve the payment issue. You can continue using Aimee normally.";
    }

    function goodwillTimingCopy(){
        return "This temporary access grant did not create a subscription or schedule a payment. No new checkout is needed to keep using Aimee in-app while this access remains active; any separate billing notice still needs attention.";
    }

    function goodwillPlainCopy(subscription){
        var date = goodwillDate(subscription);
        return "Your temporary full in-app access is active" + (date ? " until " + date : "") + " while we resolve the payment issue. You can continue using Aimee normally. This access grant did not create a subscription or schedule a payment.";
    }

    function reconciliationCopy(subscription){
        var paymentDate = formatDate(subscription.next_payment_at || subscription.billing_current_period_end);
        return "We cannot describe August as complimentary for this account yet because its current billing record shows a scheduled payment" + (paymentDate ? " on <strong>" + paymentDate + "</strong>" : "") + ". We have not assumed that payment is cancelled.";
    }

    function reconciliationTimingCopy(){
        return "Please review the billing record or contact Engram Intelligence before relying on complimentary access. <strong>Do not create another subscription while this is being checked.</strong>";
    }

    function scheduleBoundaryRefresh(subscription){
        if (state.boundaryTimer) window.clearTimeout(state.boundaryTimer);
        state.boundaryTimer = null;
        var boundaryValue = subscription.service_grace_active
            ? subscription.service_grace_until
            : (subscription.access_active && subscription.access_source === "goodwill_extension"
                ? (subscription.bonus_access_until || subscription.access_until)
                : "");
        if (!boundaryValue) return;
        var boundary = new Date(boundaryValue).getTime();
        if (!boundary || Number.isNaN(boundary)) return;
        var remaining = boundary - Date.now();
        var delay = Math.max(1000, Math.min(remaining + 1000, 6 * 60 * 60 * 1000));
        state.boundaryTimer = window.setTimeout(function(){ refreshStatus("service-grace-boundary"); }, delay);
    }

    function setBusy(button, busy){
        if (!button) return;
        if (busy) {
            button.dataset.originalText = button.textContent;
            button.disabled = true;
            button.textContent = "Opening secure checkout…";
        } else {
            button.disabled = false;
            if (button.dataset.originalText) button.textContent = button.dataset.originalText;
        }
    }

    function startCheckout(plan, source, button){
        if (state.reconciliation) {
            var reviewFirst = "A scheduled payment is still shown for this account. Review billing or contact Engram Intelligence before creating another subscription.";
            if (typeof window.notifyUser === "function") window.notifyUser(reviewFirst, "error");
            else window.alert(reviewFirst);
            return Promise.resolve();
        }
        if (state.grace) {
            var paused = "August access is complimentary through 31 August 2026, ending at 00:00 on 1 September 2026 UK time. UK GoCardless checkout opens then, and no payment is scheduled automatically.";
            if (typeof window.notifyUser === "function") window.notifyUser(paused);
            else window.alert(paused);
            return Promise.resolve();
        }
        if (state.goodwill) {
            var goodwillMessage = goodwillPlainCopy(state.subscription || {});
            if (typeof window.notifyUser === "function") window.notifyUser(goodwillMessage);
            else window.alert(goodwillMessage);
            return Promise.resolve();
        }
        if (!config.checkoutMarketSupported) {
            var unavailable = "New membership checkout is currently available for UK profiles through GoCardless only. No payment was created.";
            if (typeof window.notifyUser === "function") window.notifyUser(unavailable, "error");
            else window.alert(unavailable);
            return Promise.resolve();
        }
        if (!state.checkoutAvailable) {
            var accessActive = "GoCardless checkout is not available while preserved or managed membership access remains active. No payment was created.";
            if (typeof window.notifyUser === "function") window.notifyUser(accessActive);
            else window.alert(accessActive);
            return Promise.resolve();
        }
        plan = ["weekly","monthly","annual"].indexOf(plan) >= 0 ? plan : (state.subscription && state.subscription.plan) || "monthly";
        setBusy(button, true);
        return api("/subscription-checkout", {
            method:"POST",
            body:JSON.stringify({plan:plan,source:source || "billing-migration",market:config.market})
        }).then(function(data){
            if (data.checkout_url) {
                window.location.assign(data.checkout_url);
                return;
            }
            throw new Error(data.message || "Secure checkout could not be opened.");
        }).catch(function(error){
            setBusy(button, false);
            if (typeof window.notifyUser === "function") window.notifyUser(error.message, "error");
            else window.alert(error.message);
        });
    }

    function createActions(subscription, context){
        var actions = document.createElement("div");
        actions.className = "aimee-billing-migration-actions";

        var keep = document.createElement("button");
        keep.type = "button";
        keep.className = "aimee-billing-migration-action primary";
        if (!state.checkoutAvailable) {
            keep.disabled = true;
            keep.setAttribute("aria-disabled", "true");
            keep.textContent = config.checkoutMarketSupported
                ? "Checkout opens after existing access"
                : "US checkout unavailable";
        } else {
            keep.textContent = subscription.new_subscription_required
                ? "Start a " + planName(subscription.plan) + " membership"
                : "Keep my " + planName(subscription.plan) + " membership";
            keep.addEventListener("click", function(){ startCheckout(subscription.plan || "monthly", context, keep); });
        }

        var choose = document.createElement("a");
        choose.className = "aimee-billing-migration-action secondary";
        choose.href = config.pricingUrl;
        choose.textContent = config.checkoutMarketSupported ? "Choose another plan" : "View checkout availability";

        actions.appendChild(keep);
        actions.appendChild(choose);
        return actions;
    }

    function createGraceActions(){
        var actions = document.createElement("div");
        actions.className = "aimee-billing-migration-actions";

        var dismiss = document.createElement("button");
        dismiss.type = "button";
        dismiss.className = "aimee-billing-migration-action primary";
        dismiss.textContent = "Thanks — got it";
        dismiss.addEventListener("click", function(){
            rememberSessionValue("aimeeServiceGraceDismissed:" + config.market, "1");
            document.getElementById("aimee-service-grace-card")?.remove();
            document.getElementById("aimee-settings-billing-migration")?.remove();
        });

        var plans = document.createElement("a");
        plans.className = "aimee-billing-migration-action secondary";
        plans.href = config.pricingUrl;
        plans.textContent = "View September plans";

        actions.appendChild(dismiss);
        actions.appendChild(plans);
        return actions;
    }

    function createReconciliationActions(subscription){
        var actions = document.createElement("div");
        actions.className = "aimee-billing-migration-actions";

        if (subscription.can_manage_billing) {
            var review = document.createElement("button");
            review.type = "button";
            review.className = "aimee-billing-migration-action primary";
            review.textContent = "Review billing";
            review.addEventListener("click", function(){
                if (typeof window.openBillingPortal === "function") {
                    window.openBillingPortal("service-grace-reconciliation", review);
                    return;
                }
                window.alert("Billing management is temporarily unavailable. Please contact Engram Intelligence before making another payment.");
            });
            actions.appendChild(review);
        }

        var retry = document.createElement("button");
        retry.type = "button";
        retry.className = "aimee-billing-migration-action secondary";
        retry.textContent = "Recheck status";
        retry.addEventListener("click", function(){ refreshStatus("manual-recheck"); });
        actions.appendChild(retry);
        return actions;
    }

    function removeNoticeCards(){
        document.getElementById("aimee-billing-migration-card")?.remove();
        document.getElementById("aimee-service-grace-card")?.remove();
        document.getElementById("aimee-billing-reconciliation-card")?.remove();
        document.getElementById("aimee-settings-billing-migration")?.remove();
    }

    var originalAttributeMissing = "__aimee_billing_attribute_missing__";

    function ownsDatasetValue(element, key){
        return Boolean(element && element.dataset && Object.prototype.hasOwnProperty.call(element.dataset, key));
    }

    function rememberText(element){
        if (!element || ownsDatasetValue(element, "aimeeBillingOriginalText")) return;
        element.dataset.aimeeBillingOriginalText = element.textContent;
    }

    function setControlledText(element, value){
        if (!element) return;
        rememberText(element);
        element.textContent = value;
        element.dataset.aimeeBillingControlledText = element.textContent;
    }

    function restoreText(element){
        if (!ownsDatasetValue(element, "aimeeBillingOriginalText")) return;
        if (
            !ownsDatasetValue(element, "aimeeBillingControlledText")
            || element.textContent === element.dataset.aimeeBillingControlledText
        ) element.textContent = element.dataset.aimeeBillingOriginalText;
        delete element.dataset.aimeeBillingOriginalText;
        delete element.dataset.aimeeBillingControlledText;
    }

    function rememberHtml(element){
        if (!element || ownsDatasetValue(element, "aimeeBillingOriginalHtml")) return;
        element.dataset.aimeeBillingOriginalHtml = element.innerHTML;
    }

    function setControlledHtml(element, value){
        if (!element) return;
        rememberHtml(element);
        element.innerHTML = value;
        element.dataset.aimeeBillingControlledHtml = element.innerHTML;
    }

    function restoreHtml(element){
        if (!ownsDatasetValue(element, "aimeeBillingOriginalHtml")) return;
        if (
            !ownsDatasetValue(element, "aimeeBillingControlledHtml")
            || element.innerHTML === element.dataset.aimeeBillingControlledHtml
        ) element.innerHTML = element.dataset.aimeeBillingOriginalHtml;
        delete element.dataset.aimeeBillingOriginalHtml;
        delete element.dataset.aimeeBillingControlledHtml;
    }

    function rememberAttribute(element, attribute, key){
        if (!element || ownsDatasetValue(element, key)) return;
        var value = element.getAttribute(attribute);
        element.dataset[key] = value === null ? originalAttributeMissing : value;
    }

    function setControlledAttribute(element, attribute, key, value){
        if (!element) return;
        rememberAttribute(element, attribute, key);
        element.setAttribute(attribute, value);
        element.dataset[key + "Controlled"] = String(value);
    }

    function restoreAttribute(element, attribute, key){
        if (!ownsDatasetValue(element, key)) return;
        var value = element.dataset[key];
        var controlledKey = key + "Controlled";
        var canRestore = !ownsDatasetValue(element, controlledKey)
            || element.getAttribute(attribute) === element.dataset[controlledKey];
        if (canRestore) {
            if (value === originalAttributeMissing) element.removeAttribute(attribute);
            else element.setAttribute(attribute, value);
        }
        delete element.dataset[key];
        delete element.dataset[controlledKey];
    }

    function rememberDisplay(element){
        if (!element || ownsDatasetValue(element, "aimeeBillingOriginalDisplay")) return;
        element.dataset.aimeeBillingOriginalDisplay = element.style.display || "";
    }

    function setControlledDisplay(element, value){
        if (!element) return;
        rememberDisplay(element);
        element.style.display = value;
        element.dataset.aimeeBillingControlledDisplay = element.style.display;
    }

    function restoreDisplay(element){
        if (!ownsDatasetValue(element, "aimeeBillingOriginalDisplay")) return;
        if (
            !ownsDatasetValue(element, "aimeeBillingControlledDisplay")
            || element.style.display === element.dataset.aimeeBillingControlledDisplay
        ) element.style.display = element.dataset.aimeeBillingOriginalDisplay;
        delete element.dataset.aimeeBillingOriginalDisplay;
        delete element.dataset.aimeeBillingControlledDisplay;
    }

    function setControlledDisabled(element, value){
        if (!element || !element.matches("button")) return;
        if (!ownsDatasetValue(element, "aimeeBillingOriginalDisabled")) {
            element.dataset.aimeeBillingOriginalDisabled = element.disabled ? "1" : "0";
        }
        element.disabled = Boolean(value);
        element.dataset.aimeeBillingControlledDisabled = element.disabled ? "1" : "0";
    }

    function restoreDisabled(element){
        if (!ownsDatasetValue(element, "aimeeBillingOriginalDisabled")) return;
        var current = element.disabled ? "1" : "0";
        if (
            !ownsDatasetValue(element, "aimeeBillingControlledDisabled")
            || current === element.dataset.aimeeBillingControlledDisabled
        ) element.disabled = element.dataset.aimeeBillingOriginalDisabled === "1";
        delete element.dataset.aimeeBillingOriginalDisabled;
        delete element.dataset.aimeeBillingControlledDisabled;
    }

    function restoreControlledUi(){
        removeNoticeCards();
        document.querySelectorAll(".membership-status-card.aimee-billing-migration-active,.membership-status-card.aimee-goodwill-access-active").forEach(function(card){
            card.classList.remove("aimee-billing-migration-active");
            card.classList.remove("aimee-goodwill-access-active");
        });

        restoreText(document.getElementById("settings-membership-label"));
        restoreHtml(document.getElementById("settings-membership-detail"));
        restoreHtml(document.getElementById("membership-status-display"));
        restoreText(document.getElementById("membership-title"));
        restoreText(document.getElementById("membership-modal-copy"));

        document.querySelectorAll(".membership-checkout-btn,[data-plan]").forEach(function(button){
            var action = button.querySelector(".membership-plan-action");
            restoreText(action || button);
            restoreAttribute(button, "aria-disabled", "aimeeBillingOriginalAriaDisabled");
            restoreDisabled(button);
        });

        document.querySelectorAll(".open-membership-btn").forEach(function(button){
            restoreText(button);
            restoreAttribute(button, "aria-label", "aimeeBillingOriginalAriaLabel");
        });

        document.querySelectorAll("#cancel-membership-btn,#manage-membership-btn,[data-billing-action=cancel],[data-billing-action=portal]").forEach(function(button){
            restoreDisplay(button);
            restoreAttribute(button, "aria-hidden", "aimeeBillingOriginalAriaHidden");
        });
    }

    function updateModalCopy(subscription){
        var modalTitle = document.getElementById("membership-title");
        var modalCopy = document.getElementById("membership-modal-copy");
        if (modalTitle) setControlledText(modalTitle, state.reconciliation
            ? "Billing verification needed"
            : (state.grace
                ? "August is on us"
                : (state.goodwill
                    ? "Temporary access active"
                    : (!config.checkoutMarketSupported
                        ? "US paid checkout unavailable"
                        : (subscription.new_subscription_required ? "Create your new Aimee membership" : "Reconnect your Aimee membership")))));
        if (modalCopy) setControlledText(modalCopy, state.reconciliation
            ? "A scheduled payment is still present in this account's billing record, so we cannot yet describe August as complimentary. Review billing or contact Engram Intelligence before making another subscription."
            : (state.grace
                ? "With thanks from Engram Intelligence, full in-app Aimee access is complimentary through 31 August 2026, ending at 00:00 on 1 September 2026 UK time. No replacement subscription or payment has been created automatically."
                : (state.goodwill
                    ? goodwillPlainCopy(subscription)
                    : (!config.checkoutMarketSupported
                        ? "New paid membership checkout is not currently available for US profiles. Existing legacy billing can still be managed where available, but no card or Stripe checkout can create a new membership."
                        : "New UK membership checkout is provided only through GoCardless. Your previous subscription was linked to our former payment account, which cannot be used to create a new checkout."))));
    }

    function renderManagedTransitionUi(subscription){
        if (!isManageableBilling(subscription)) return;
        var status = String(subscription.billing_status || subscription.status || "").toLowerCase();
        var authoritativeAccess = Boolean(
            subscription.access_active
            && subscription.access_source === "managed_subscription"
        );
        var needsAttention = !authoritativeAccess || ["past_due","unpaid","paused"].indexOf(status) >= 0;
        var rawPlan = String(subscription.plan || "").toLowerCase();
        var knownPlan = ["weekly","monthly","annual"].indexOf(rawPlan) >= 0;
        var planLabel = knownPlan ? planName(rawPlan) : "";
        var accessDate = formatDate(subscription.billing_current_period_end || subscription.current_period_end || subscription.access_until);
        var label = document.getElementById("settings-membership-label");
        var detail = document.getElementById("settings-membership-detail");
        var header = document.getElementById("membership-status-display");
        var modalTitle = document.getElementById("membership-title");
        var modalCopy = document.getElementById("membership-modal-copy");

        if (label) label.textContent = needsAttention
            ? "Membership needs attention"
            : (planLabel ? planLabel + " membership active" : "Membership active");
        if (detail) detail.innerHTML = needsAttention
            ? "Your billing record needs attention. Open membership settings to review it."
            : "Your managed membership is active" + (accessDate ? " until <strong>" + accessDate + "</strong>" : "") + ". You can review billing in membership settings.";
        if (header) header.innerHTML = "Manage membership <span aria-hidden=\"true\" style=\"font-size:13px;line-height:1\">›</span>";
        if (modalTitle) modalTitle.textContent = needsAttention ? "Review your membership" : "Manage your membership";
        if (modalCopy) modalCopy.textContent = needsAttention
            ? "Your billing record needs attention. Open billing settings to review it."
            : "Review your current membership and billing settings.";

        document.querySelectorAll(".open-membership-btn").forEach(function(button){
            button.textContent = needsAttention ? "Review billing" : "Manage membership";
            button.setAttribute("aria-label", needsAttention ? "Review membership billing" : "Manage your current membership");
        });
    }

    function mountChatCard(subscription){
        if (state.goodwill) {
            document.getElementById("aimee-billing-migration-card")?.remove();
            return;
        }
        var composer = document.getElementById("message-composer")
            || document.querySelector("#chat-interface .chat-input-area")
            || document.querySelector("#chat-interface .input-area")
            || document.querySelector("#chat-interface .composer,.conversation-view .composer,main.chat .composer");
        var cardId = state.reconciliation
            ? "aimee-billing-reconciliation-card"
            : (state.grace ? "aimee-service-grace-card" : "aimee-billing-migration-card");
        if (!composer || document.getElementById(cardId)) return;
        var dismissedKey = state.grace
            ? "aimeeServiceGraceDismissed:" + config.market
            : "aimeeBillingMigrationDismissed:" + config.market;
        if (!state.reconciliation && sessionValue(dismissedKey) === "1") return;

        // The service thank-you can coexist with the compact release-feedback
        // prompt. The urgent post-grace billing card keeps the existing notice
        // priority so several calls to action never compete at once.
        if (!state.grace) {
            document.getElementById("aimee-release-feedback-chat")?.remove();
            document.getElementById("aimee-public-statement-chat")?.remove();
        }

        var card = document.createElement("aside");
        card.id = cardId;
        card.setAttribute("role", "status");
        var dismissMarkup = !state.reconciliation && (state.grace || subscription.legacy_access_active)
            ? "<button type=\"button\" class=\"aimee-billing-migration-dismiss\" aria-label=\"Remind me later\">×</button>"
            : "";
        if (state.reconciliation) {
            card.innerHTML = "<strong>We need to verify your August billing</strong><p>" + reconciliationCopy(subscription) + "</p><p style=\"margin-top:5px\">" + reconciliationTimingCopy() + "</p>";
            card.appendChild(createReconciliationActions(subscription));
        } else if (state.grace) {
            card.innerHTML = dismissMarkup + "<strong>A thank-you from Engram Intelligence</strong><p>" + graceCopy(subscription) + "</p><p style=\"margin-top:5px\">" + graceTimingCopy() + "</p>";
            card.appendChild(createGraceActions());
        } else {
            var title = subscription.new_subscription_required
                ? "Create your new Aimee membership"
                : "Your membership needs a quick update";
            card.innerHTML = dismissMarkup + "<strong>" + title + "</strong><p>" + accessCopy(subscription) + "</p><p style=\"margin-top:5px\">" + timingCopy(subscription) + "</p>";
            card.appendChild(createActions(subscription, "chat-migration-card"));
        }
        var dismissButton = card.querySelector(".aimee-billing-migration-dismiss");
        if (dismissButton) dismissButton.addEventListener("click", function(){
            rememberSessionValue(dismissedKey, "1");
            card.remove();
        });
        var anchor = composer.closest ? (composer.closest(".chat-input-area") || composer) : composer;
        anchor.insertAdjacentElement("beforebegin", card);
    }

    function mountSettingsCard(subscription){
        var detail = document.getElementById("settings-membership-detail");
        var statusCard = detail ? detail.closest(".membership-status-card") : document.querySelector(".membership-status-card");
        if (statusCard) {
            statusCard.classList.add("aimee-billing-migration-active");
            statusCard.classList.toggle("aimee-goodwill-access-active", state.goodwill);
        }

        var label = document.getElementById("settings-membership-label");
        if (label) setControlledText(label, state.reconciliation
            ? "Billing verification needed"
            : (state.grace
                ? "Complimentary August access"
                : (state.goodwill
                    ? "Temporary access active"
                    : (subscription.new_subscription_required ? "New subscription required" : "Membership needs reconnecting"))));
        if (detail) {
            setControlledHtml(detail, state.reconciliation
                ? reconciliationCopy(subscription)
                : (state.grace
                    ? graceCopy(subscription)
                    : (state.goodwill ? goodwillCopy(subscription) : accessCopy(subscription))));
        }

        var graceDismissed = state.grace
            && sessionValue("aimeeServiceGraceDismissed:" + config.market) === "1";
        if (graceDismissed) document.getElementById("aimee-settings-billing-migration")?.remove();

        if (!graceDismissed && !document.getElementById("aimee-settings-billing-migration")) {
            var panel = document.createElement("div");
            panel.id = "aimee-settings-billing-migration";
            panel.classList.toggle("aimee-goodwill-access-active", state.goodwill);
            panel.innerHTML = state.reconciliation
                ? "<strong>Billing verification needed</strong><span>" + reconciliationTimingCopy() + "</span>"
                : (state.grace
                    ? "<strong>August is on us</strong><span>" + graceTimingCopy() + "</span>"
                    : (state.goodwill
                        ? "<strong>Full access is active</strong><span>" + goodwillTimingCopy() + "</span>"
                        : "<strong>Reconnect without a double charge</strong><span>" + timingCopy(subscription) + "</span>"));
            if (!state.goodwill) {
                panel.appendChild(state.reconciliation
                    ? createReconciliationActions(subscription)
                    : (state.grace
                        ? createGraceActions()
                        : createActions(subscription, "settings-migration-card")));
            }
            if (statusCard) statusCard.appendChild(panel);
            else if (detail) detail.insertAdjacentElement("afterend", panel);
        }

        document.querySelectorAll(".open-membership-btn").forEach(function(button){
            setControlledText(button, state.reconciliation
                ? "Review billing"
                : (state.grace
                    ? "New subscriptions open 1 September (UK time)"
                    : (state.goodwill
                        ? (isManageableBilling(subscription) ? "Temporary access · Manage billing" : "Temporary access active")
                        : (!config.checkoutMarketSupported ? "US checkout unavailable" : "Reconnect membership"))));
            setControlledAttribute(button, "aria-label", "aimeeBillingOriginalAriaLabel", state.reconciliation
                ? "Review the scheduled payment before making another subscription"
                : (state.grace
                    ? "New Aimee subscriptions open at midnight UK time on 1 September 2026"
                    : (state.goodwill
                        ? (isManageableBilling(subscription)
                            ? "Temporary in-app access is active; manage your existing billing record"
                            : goodwillPlainCopy(subscription))
                        : (!config.checkoutMarketSupported
                            ? "New paid membership checkout is unavailable for US profiles"
                            : "Reconnect your Aimee membership through GoCardless"))));
        });

        document.querySelectorAll("#cancel-membership-btn,#manage-membership-btn,[data-billing-action=cancel],[data-billing-action=portal]").forEach(function(button){
            if (!subscription.can_manage_billing) {
                setControlledDisplay(button, "none");
                setControlledAttribute(button, "aria-hidden", "aimeeBillingOriginalAriaHidden", "true");
            } else {
                restoreDisplay(button);
                restoreAttribute(button, "aria-hidden", "aimeeBillingOriginalAriaHidden");
            }
        });
    }

    function updatePlanControls(subscription){
        var currentPlan = String(subscription.plan || "").toLowerCase();
        document.querySelectorAll(".membership-checkout-btn,[data-plan]").forEach(function(button){
            var plan = String(button.dataset.plan || "").toLowerCase();
            if (["weekly","monthly","annual"].indexOf(plan) < 0) return;
            var action = button.querySelector(".membership-plan-action");
            var label = action || (button.matches("button,a") ? button : null);
            var controlUnavailable = !state.checkoutAvailable || state.reconciliation || state.grace || state.goodwill;
            var text = state.reconciliation
                ? "Unavailable while billing is verified"
                : (state.grace
                    ? planName(plan) + " available 1 September (UK time)"
                    : (state.goodwill
                        ? "Temporary access active"
                        : (!config.checkoutMarketSupported
                            ? "US checkout unavailable"
                            : (!state.checkoutAvailable
                                ? "Checkout opens after existing access"
                                : (plan === currentPlan ? "Reconnect " + planName(plan) : "Continue with " + planName(plan))))));
            if (label) setControlledText(label, text);
            setControlledAttribute(button, "aria-disabled", "aimeeBillingOriginalAriaDisabled", controlUnavailable ? "true" : "false");
            if (button.matches("button")) {
                if (controlUnavailable) setControlledDisabled(button, true);
                else restoreDisabled(button);
            }
        });

        var header = document.getElementById("membership-status-display");
        if (header) {
            setControlledHtml(header, (state.reconciliation
                ? "Billing verification needed"
                : (state.grace
                    ? "Complimentary August access"
                    : (state.goodwill
                        ? (isManageableBilling(subscription) ? "Temporary access · Manage billing" : "Temporary access active")
                        : "Reconnect membership"))) + " <span aria-hidden=\"true\" style=\"font-size:13px;line-height:1\">›</span>");
        }
    }

    function apply(subscription){
        var wasGrace = state.grace;
        var wasGoodwill = state.goodwill;
        var wasReconciliation = state.reconciliation;
        var wasRequired = state.required;
        state.subscription = subscription || {};
        state.reconciliation = Boolean(
            state.subscription.service_grace_active
            && state.subscription.payment_scheduled
        );
        state.grace = Boolean(state.subscription.service_grace_active) && !state.reconciliation;
        state.goodwill = Boolean(
            state.subscription.access_active
            && state.subscription.access_source === "goodwill_extension"
        );
        state.required = !state.goodwill && Boolean(
            state.subscription.requires_reactivation
            || state.subscription.new_subscription_required
        );
        state.checkoutAvailable = Boolean(
            config.checkoutMarketSupported
            && state.subscription.checkout_available
        );
        if (
            wasGrace !== state.grace
            || wasGoodwill !== state.goodwill
            || wasReconciliation !== state.reconciliation
            || wasRequired !== state.required
        ) {
            removeNoticeCards();
        }
        scheduleBoundaryRefresh(state.subscription);
        if (document.body) {
            document.body.classList.toggle("aimee-billing-reactivation-required", state.required && !state.grace && !state.reconciliation);
            document.body.classList.toggle("aimee-service-grace-active", state.grace);
            document.body.classList.toggle("aimee-goodwill-access-active", state.goodwill);
            document.body.classList.toggle("aimee-billing-reconciliation-required", state.reconciliation);
        }
        if (!state.required && !state.grace && !state.goodwill && !state.reconciliation) {
            restoreControlledUi();
            if (wasGoodwill) renderManagedTransitionUi(state.subscription);
            return;
        }
        if (state.goodwill) {
            document.getElementById("aimee-billing-migration-card")?.remove();
            document.getElementById("aimee-service-grace-card")?.remove();
            document.getElementById("aimee-billing-reconciliation-card")?.remove();
        } else if (state.reconciliation) {
            document.getElementById("aimee-billing-migration-card")?.remove();
            document.getElementById("aimee-service-grace-card")?.remove();
        } else if (state.grace) {
            document.getElementById("aimee-billing-migration-card")?.remove();
            document.getElementById("aimee-billing-reconciliation-card")?.remove();
        } else {
            document.getElementById("aimee-service-grace-card")?.remove();
            document.getElementById("aimee-billing-reconciliation-card")?.remove();
        }
        updateModalCopy(state.subscription);
        mountChatCard(state.subscription);
        mountSettingsCard(state.subscription);
        updatePlanControls(state.subscription);
        [350, 1200, 2600].forEach(function(delay){
            window.setTimeout(function(){
                if (!state.required && !state.grace && !state.goodwill && !state.reconciliation) return;
                updateModalCopy(state.subscription);
                mountChatCard(state.subscription);
                mountSettingsCard(state.subscription);
                updatePlanControls(state.subscription);
            }, delay);
        });
    }

    // Capture billing clicks before the historical application can route a new
    // checkout to an unsupported market or a retired payment path.
    document.addEventListener("click", function(event){
        var target = event.target.closest(".membership-checkout-btn,[data-plan],.open-membership-btn,#membership-status-display,#cancel-membership-btn,#manage-membership-btn,[data-billing-action]");
        if (!target) return;

        if (state.goodwill) {
            if (
                state.subscription
                && isManageableBilling(state.subscription)
                && target.matches(".open-membership-btn,#membership-status-display,#cancel-membership-btn,#manage-membership-btn,[data-billing-action=cancel],[data-billing-action=portal]")
            ) return;
            event.preventDefault();
            event.stopPropagation();
            if (typeof event.stopImmediatePropagation === "function") event.stopImmediatePropagation();
            var goodwillMessage = goodwillPlainCopy(state.subscription || {});
            if (typeof window.notifyUser === "function") window.notifyUser(goodwillMessage);
            else window.alert(goodwillMessage);
            return;
        }

        if (
            !config.checkoutMarketSupported
            && target.matches(".membership-checkout-btn,[data-plan]")
        ) {
            event.preventDefault();
            event.stopPropagation();
            if (typeof event.stopImmediatePropagation === "function") event.stopImmediatePropagation();
            var marketMessage = "New membership checkout is currently available for UK profiles through GoCardless only. No payment was created.";
            if (typeof window.notifyUser === "function") window.notifyUser(marketMessage, "error");
            else window.alert(marketMessage);
            return;
        }

        if (!state.required && !state.grace && !state.reconciliation) return;

        if (
            (state.grace || state.reconciliation)
            && state.subscription
            && state.subscription.can_manage_billing
            && target.matches("#cancel-membership-btn,#manage-membership-btn,[data-billing-action=cancel],[data-billing-action=portal]")
        ) return;

        var plan = String(target.dataset.plan || "").toLowerCase();
        event.preventDefault();
        event.stopPropagation();
        if (typeof event.stopImmediatePropagation === "function") event.stopImmediatePropagation();

        if (state.reconciliation) {
            var reconciliationMessage = "A scheduled payment is still shown for this account. Review billing or contact Engram Intelligence before creating another subscription.";
            if (typeof window.notifyUser === "function") window.notifyUser(reconciliationMessage, "error");
            else window.alert(reconciliationMessage);
        } else if (state.grace) {
            var graceMessage = "Full in-app access is complimentary through 31 August 2026, ending at 00:00 on 1 September 2026 UK time. UK GoCardless checkout opens then; no payment is scheduled automatically.";
            if (typeof window.notifyUser === "function") window.notifyUser(graceMessage);
            else window.alert(graceMessage);
        } else if (["weekly","monthly","annual"].indexOf(plan) >= 0) {
            startCheckout(plan, "legacy-ui-plan", target);
        } else if (target.matches("#cancel-membership-btn,[data-billing-action=cancel]")) {
            var message = "Your previous subscription was linked to our former payment account, which is now closed, so it cannot renew or charge you again. There is nothing left to cancel. Reconnect only if you would like Aimee to continue.";
            if (typeof window.notifyUser === "function") window.notifyUser(message);
            else window.alert(message);
        } else {
            window.location.assign(config.pricingUrl);
        }
    }, true);

    window.startMembershipCheckout = function(plan, source, button){
        return startCheckout(plan, source || "legacy-ui", button);
    };

    var originalOpenPortal = window.openBillingPortal;
    window.openBillingPortal = function(source, button, requestedPlan){
        // A requested plan is a new-checkout/plan-change action, never legacy
        // Stripe management. Route it through the GoCardless-only gate.
        if (requestedPlan) return startCheckout(requestedPlan, source || "legacy-ui-portal", button);
        if (state.goodwill) {
            if (state.subscription && isManageableBilling(state.subscription) && typeof originalOpenPortal === "function") {
                return originalOpenPortal.apply(this, arguments);
            }
            var goodwillMessage = goodwillPlainCopy(state.subscription || {});
            if (typeof window.notifyUser === "function") window.notifyUser(goodwillMessage);
            else window.alert(goodwillMessage);
            return;
        }
        if ((state.grace || state.reconciliation) && state.subscription && state.subscription.can_manage_billing && typeof originalOpenPortal === "function") {
            return originalOpenPortal.apply(this, arguments);
        }
        if (state.reconciliation) {
            window.alert("Billing management is temporarily unavailable. Please contact Engram Intelligence before making another payment.");
            return;
        }
        if (state.required || state.grace) {
            if (requestedPlan) return startCheckout(requestedPlan, source || "legacy-ui-portal", button);
            window.location.assign(config.pricingUrl);
            return;
        }
        if (typeof originalOpenPortal === "function") return originalOpenPortal.apply(this, arguments);
    };

    function scheduleStatusRetry(){
        if (state.retryTimer) window.clearTimeout(state.retryTimer);
        var delays = [1000, 2500, 5000, 15000, 30000, 60000];
        var delay = delays[Math.min(state.retryAttempt, delays.length - 1)];
        state.retryAttempt += 1;
        state.retryTimer = window.setTimeout(function(){
            state.retryTimer = null;
            refreshStatus("retry");
        }, delay);
    }

    function refreshStatus(reason){
        if (state.refreshInFlight) return state.refreshInFlight;
        if (state.retryTimer) {
            window.clearTimeout(state.retryTimer);
            state.retryTimer = null;
        }
        state.refreshInFlight = api("/subscription-status", {method:"GET"})
            .then(function(data){
                state.retryAttempt = 0;
                apply(data.subscription || {});
                return data;
            })
            .catch(function(error){
                console.warn("Aimee billing status unavailable (" + (reason || "refresh") + "):", error);
                scheduleStatusRetry();
                return null;
            })
            .finally(function(){ state.refreshInFlight = null; });
        return state.refreshInFlight;
    }

    function containsDelayedBillingUi(node){
        if (!node || node.nodeType !== 1) return false;
        var selector = "#settings-membership-detail,.membership-status-card,.open-membership-btn,#membership-status-display,.membership-checkout-btn,[data-plan],#membership-title,#membership-modal-copy";
        if (node.matches && node.matches(selector)) return true;
        return Boolean(node.querySelector && node.querySelector(selector));
    }

    function observeDelayedChatMount(){
        if (state.mountObserver || !document.body || !window.MutationObserver) return;
        state.mountObserver = new MutationObserver(function(mutations){
            if (!state.subscription || (!state.required && !state.grace && !state.goodwill && !state.reconciliation)) return;
            mountChatCard(state.subscription);
            var billingUiAdded = Array.prototype.some.call(mutations || [], function(mutation){
                return Array.prototype.some.call(mutation.addedNodes || [], containsDelayedBillingUi);
            });
            if (!billingUiAdded) return;
            updateModalCopy(state.subscription);
            mountSettingsCard(state.subscription);
            updatePlanControls(state.subscription);
        });
        state.mountObserver.observe(document.body, {childList:true,subtree:true});
    }

    function boot(){
        observeDelayedChatMount();
        refreshStatus("boot");
    }

    document.addEventListener("visibilitychange", function(){
        if (document.visibilityState === "visible") refreshStatus("visibility");
    });
    window.addEventListener("online", function(){ refreshStatus("online"); });
    window.addEventListener("pageshow", function(){ refreshStatus("pageshow"); });
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
    else boot();
})(__AIMEE_BILLING_CONFIG__);
</script>
AIMEE_BILLING_HTML;

    return str_replace('__AIMEE_BILLING_CONFIG__', $json, $markup);

}

/**
 * Observe private image rendering independently of whichever historical chat
 * template is active. Server return, browser render and client acknowledgement
 * remain separate milestones; none of them claims that a person saw the file.
 */
function aimee_global_media_delivery_markup() {
    if (!is_user_logged_in()) return '';

    $config = [
        'ackUrl' => esc_url_raw(rest_url('aimee/v1/media-delivery/ack')),
        'nonce' => wp_create_nonce('wp_rest'),
        'version' => AIMEE_GLOBAL_VERSION,
        'retryLimit' => 4,
    ];
    $json = wp_json_encode(
        $config,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );

    $markup = <<<'AIMEE_MEDIA_DELIVERY_HTML'
<script id="aimee-media-delivery-observer">
(function(config){
    "use strict";
    if(!config||!config.ackUrl||!config.nonce)return;

    var storageKey="aimeeMediaClientInstance";
    var clientId="";
    try{
        clientId=sessionStorage.getItem(storageKey)||"";
        if(!clientId){
            clientId=(window.crypto&&crypto.randomUUID)
                ?crypto.randomUUID()
                :"client-"+Date.now()+"-"+Math.random().toString(16).slice(2);
            sessionStorage.setItem(storageKey,clientId);
        }
    }catch(error){clientId="client-ephemeral";}

    function deliveryIdFor(image){
        if(!image)return"";
        var direct=image.getAttribute("data-delivery-id")||"";
        if(direct)return direct;
        try{
            var url=new URL(
                image.getAttribute("src")||image.currentSrc||image.src||"",
                window.location.href
            );
            if(url.searchParams.get("action")!=="aimee_private_media")return"";
            return url.searchParams.get("delivery_id")||"";
        }catch(error){return"";}
    }

    var confirmedStorageKey="aimeeMediaDeliveryConfirmed:"+(config.version||"unknown");
    var confirmed=Object.create(null);
    var pending=Object.create(null);
    try{
        var storedConfirmed=JSON.parse(sessionStorage.getItem(confirmedStorageKey)||"{}");
        if(storedConfirmed&&typeof storedConfirmed==="object")confirmed=storedConfirmed;
    }catch(error){confirmed=Object.create(null);}

    function factKey(deliveryId,state){return deliveryId+":"+state;}
    function persistConfirmed(){
        try{sessionStorage.setItem(confirmedStorageKey,JSON.stringify(confirmed));}
        catch(error){}
    }
    function wait(milliseconds){
        return new Promise(function(resolve){window.setTimeout(resolve,milliseconds);});
    }
    function shouldRetry(error){
        var status=Number(error&&error.status||0);
        return status===0||status===409||status===429||status>=500;
    }
    function postAcknowledgement(deliveryId,state,errorCode,attempt){
        return fetch(config.ackUrl,{
            method:"POST",
            credentials:"same-origin",
            headers:{
                "Accept":"application/json",
                "Content-Type":"application/json",
                "X-WP-Nonce":config.nonce
            },
            body:JSON.stringify({
                delivery_id:deliveryId,
                state:state,
                error_code:errorCode||"",
                client_instance_id:clientId,
                client_version:config.version||""
            })
        }).then(function(response){
            return response.text().then(function(raw){
                var body=null;
                try{body=raw?JSON.parse(raw):null;}catch(error){}
                if(!response.ok||!body||body.status!=="success"){
                    var failure=new Error("Media acknowledgement rejected");
                    failure.status=response.status||0;
                    throw failure;
                }
                return body;
            });
        }).catch(function(error){
            var retryLimit=Math.max(1,Number(config.retryLimit||4));
            if(attempt+1>=retryLimit||!shouldRetry(error))throw error;
            var delays=[250,700,1600,3000];
            return wait(delays[Math.min(attempt,delays.length-1)])
                .then(function(){
                    return postAcknowledgement(deliveryId,state,errorCode,attempt+1);
                });
        });
    }
    function acknowledge(deliveryId,state,errorCode){
        if(!deliveryId)return Promise.reject(new Error("Missing delivery ID"));
        var key=factKey(deliveryId,state);
        if(confirmed[key])return Promise.resolve({status:"success",cached:true});
        if(pending[key])return pending[key];

        pending[key]=postAcknowledgement(deliveryId,state,errorCode,0)
            .then(function(result){
                confirmed[key]=true;
                persistConfirmed();
                delete pending[key];
                return result;
            },function(error){
                delete pending[key];
                throw error;
            });
        return pending[key];
    }

    function bindingIsCurrent(image,deliveryId,token){
        return image&&image.isConnected
            &&deliveryIdFor(image)===deliveryId
            &&image.dataset.aimeeMediaBindingToken===token;
    }
    function recordRendered(image,deliveryId,token){
        return acknowledge(deliveryId,"rendered_by_client").then(function(result){
            if(bindingIsCurrent(image,deliveryId,token)){
                image.dataset.aimeeMediaRendered="1";
            }
            return result;
        });
    }
    function recordAcknowledged(image,deliveryId,token){
        return recordRendered(image,deliveryId,token)
            .then(function(){
                return acknowledge(deliveryId,"acknowledged_by_client");
            })
            .then(function(result){
                if(bindingIsCurrent(image,deliveryId,token)){
                    image.dataset.aimeeMediaAcknowledged="1";
                }
                return result;
            });
    }

    var fallbackImages=[];
    function visibleRatio(image){
        if(!image||!image.isConnected)return 0;
        var rect=image.getBoundingClientRect();
        if(rect.width<=0||rect.height<=0)return 0;
        var width=Math.max(0,Math.min(rect.right,window.innerWidth)-Math.max(rect.left,0));
        var height=Math.max(0,Math.min(rect.bottom,window.innerHeight)-Math.max(rect.top,0));
        return (width*height)/(rect.width*rect.height);
    }
    function tryFallbackAcknowledgements(){
        fallbackImages=fallbackImages.filter(function(item){
            var image=item.image;
            if(!bindingIsCurrent(image,item.deliveryId,item.token))return false;
            if(image.dataset.aimeeMediaAcknowledged==="1")return false;
            if(visibleRatio(image)<0.25)return true;
            recordAcknowledged(image,item.deliveryId,item.token).catch(function(){});
            return true;
        });
    }

    var observer="IntersectionObserver" in window
        ?new IntersectionObserver(function(entries){
            entries.forEach(function(entry){
                if(!entry.isIntersecting||entry.intersectionRatio<0.25)return;
                var image=entry.target;
                var deliveryId=deliveryIdFor(image);
                var token=image.dataset.aimeeMediaBindingToken||"";
                if(!deliveryId||image.dataset.aimeeMediaAcknowledged==="1")return;
                recordAcknowledged(image,deliveryId,token).then(function(){
                    observer.unobserve(image);
                }).catch(function(){});
            });
        },{threshold:[0.25]})
        :null;

    function observeForAcknowledgement(image,deliveryId,token){
        if(!bindingIsCurrent(image,deliveryId,token))return;
        if(observer){
            observer.observe(image);
        }else{
            fallbackImages.push({image:image,deliveryId:deliveryId,token:token});
            tryFallbackAcknowledgements();
        }
    }

    function bind(image){
        if(!(image instanceof HTMLImageElement))return;
        var deliveryId=deliveryIdFor(image);
        if(!deliveryId)return;
        var source=image.getAttribute("src")||image.currentSrc||image.src||"";
        if(
            image.dataset.aimeeMediaBoundDelivery===deliveryId
            &&image.dataset.aimeeMediaBoundSource===source
        )return;

        var token=deliveryId+":"+Date.now()+":"+Math.random().toString(16).slice(2);
        image.dataset.aimeeMediaBoundDelivery=deliveryId;
        image.dataset.aimeeMediaBoundSource=source;
        image.dataset.aimeeMediaBindingToken=token;
        delete image.dataset.aimeeMediaRendered;
        delete image.dataset.aimeeMediaAcknowledged;
        delete image.dataset.aimeeMediaRenderFailed;

        function rendered(){
            if(!bindingIsCurrent(image,deliveryId,token))return;
            recordRendered(image,deliveryId,token).then(function(){
                observeForAcknowledgement(image,deliveryId,token);
            }).catch(function(){});
        }
        function renderFailed(){
            if(!bindingIsCurrent(image,deliveryId,token))return;
            acknowledge(deliveryId,"render_failed","client_image_error")
                .then(function(){
                    if(bindingIsCurrent(image,deliveryId,token)){
                        image.dataset.aimeeMediaRenderFailed="1";
                    }
                }).catch(function(){});
        }

        image.addEventListener("load",rendered,{once:true});
        image.addEventListener("error",renderFailed,{once:true});
        if(image.complete){
            if(image.naturalWidth>0)rendered();
            else if(image.currentSrc||image.src)renderFailed();
        }
    }

    function scan(root){
        if(root&&root.matches&&root.matches("img"))bind(root);
        if(root&&root.querySelectorAll)root.querySelectorAll("img").forEach(bind);
    }
    function boot(){
        scan(document);
        var mutationObserver=new MutationObserver(function(records){
            records.forEach(function(record){
                if(record.type==="attributes"){
                    bind(record.target);
                    return;
                }
                record.addedNodes.forEach(function(node){
                    if(node.nodeType===1)scan(node);
                });
            });
        });
        mutationObserver.observe(document.documentElement,{
            childList:true,
            subtree:true,
            attributes:true,
            attributeFilter:["src","srcset","data-delivery-id"]
        });
        if(!observer){
            window.addEventListener("scroll",tryFallbackAcknowledgements,{passive:true});
            window.addEventListener("resize",tryFallbackAcknowledgements);
        }
    }
    if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",boot);
    else boot();
})(__AIMEE_MEDIA_DELIVERY_CONFIG__);
</script>
AIMEE_MEDIA_DELIVERY_HTML;

    return str_replace('__AIMEE_MEDIA_DELIVERY_CONFIG__', $json, $markup);
}

/**
 * Invite signed-in users to give one-tap feedback on the 1.7.1 relationship
 * update. Feedback bypasses the conversation endpoint, cannot affect intimacy
 * scoring, and contains no free text or chat excerpts.
 */
function aimee_global_chat_release_feedback_markup($market) {
    if (!is_user_logged_in()) return '';

    $market = $market === 'us' ? 'us' : 'uk';
    $config = [
        'analyticsUrl' => esc_url_raw(rest_url('aimee/v1/analytics')),
        'nonce'        => wp_create_nonce('wp_rest'),
        'market'       => $market,
        'storageKey'   => 'aimeeReleaseFeedbackChatResolved:1.7.1:' . $market,
    ];
    $json = wp_json_encode(
        $config,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );

    $markup = <<<'AIMEE_RELEASE_FEEDBACK_HTML'
<style id="aimee-release-feedback-chat-style">
#aimee-release-feedback-chat{
    position:relative;
    z-index:9;
    display:flex;
    flex:0 0 auto;
    grid-column:1/-1;
    align-self:stretch;
    align-items:center;
    gap:14px;
    width:calc(100% - 20px);
    margin:8px 10px;
    padding:11px 42px 11px 14px;
    border:1px solid rgba(225,29,72,.22);
    border-radius:16px;
    background:linear-gradient(135deg,#fff3f6 0%,#fff 72%);
    box-shadow:0 9px 25px rgba(91,15,43,.09);
    color:#4b1628;
    font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
}
#aimee-release-feedback-chat[hidden]{display:none!important}
.aimee-release-feedback-chat__body{min-width:0;flex:1}
.aimee-release-feedback-chat__eyebrow{
    display:block;
    margin-bottom:2px;
    color:#9f1239;
    font-size:9px;
    font-weight:850;
    line-height:1.2;
    letter-spacing:.1em;
    text-transform:uppercase;
}
.aimee-release-feedback-chat__title{
    display:block;
    color:#4b1628;
    font-size:13px;
    font-weight:800;
    line-height:1.35;
}
.aimee-release-feedback-chat__copy{
    display:block;
    margin-top:2px;
    color:#713147;
    font-size:11px;
    font-weight:550;
    line-height:1.4;
}
.aimee-release-feedback-chat__feedback{
    display:flex;
    flex:0 0 auto;
    flex-wrap:wrap;
    align-items:center;
    justify-content:flex-end;
    gap:6px;
}
.aimee-release-feedback-chat__prompt{
    width:100%;
    color:#713147;
    font-size:9px;
    font-weight:800;
    line-height:1.2;
    text-align:right;
    text-transform:uppercase;
    letter-spacing:.05em;
}
.aimee-release-feedback-chat__choice{
    appearance:none;
    padding:7px 10px;
    border:1px solid #efc4d1;
    border-radius:999px;
    background:#fff;
    color:#7f1d3d;
    font:800 10px/1 Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
    cursor:pointer;
}
.aimee-release-feedback-chat__choice:hover,
.aimee-release-feedback-chat__choice:focus-visible{
    border-color:#be123c;
    background:#fff1f4;
    color:#881337;
    outline:none;
}
.aimee-release-feedback-chat__choice:disabled{cursor:wait;opacity:.58}
.aimee-release-feedback-chat__status{
    width:100%;
    min-height:13px;
    color:#713147;
    font-size:10px;
    font-weight:650;
    line-height:1.3;
    text-align:right;
}
.aimee-release-feedback-chat__status[data-state="error"]{color:#b42318}
.aimee-release-feedback-chat__dismiss{
    position:absolute;
    top:50%;
    right:7px;
    width:28px;
    height:28px;
    padding:0;
    transform:translateY(-50%);
    border:0;
    border-radius:50%;
    background:transparent;
    color:#8b5d6d;
    font:400 19px/28px Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
    cursor:pointer;
}
.aimee-release-feedback-chat__dismiss:hover,
.aimee-release-feedback-chat__dismiss:focus-visible{background:rgba(159,18,57,.08);color:#701a35;outline:none}
@media(max-width:720px){
    #aimee-release-feedback-chat{align-items:flex-start;flex-direction:column;gap:8px;margin:6px 8px;width:calc(100% - 16px);padding:10px 38px 10px 11px;border-radius:14px}
    .aimee-release-feedback-chat__feedback{justify-content:flex-start}
    .aimee-release-feedback-chat__prompt,.aimee-release-feedback-chat__status{text-align:left}
}
</style>
<script id="aimee-release-feedback-chat-ui">
(function(config){
    "use strict";
    if(!config||!config.analyticsUrl||!config.nonce)return;

    function resolved(){
        try{return window.localStorage.getItem(config.storageKey)!==null;}
        catch(error){return false;}
    }

    function rememberResolution(value){
        try{window.localStorage.setItem(config.storageKey,value);}
        catch(error){}
    }

    function billingNoticeActive(){
        return Boolean(document.getElementById("aimee-billing-migration-card")
            ||document.getElementById("aimee-billing-reconciliation-card")
            ||(window.__aimeeBillingMigration
                &&(window.__aimeeBillingMigration.reconciliation
                    ||(window.__aimeeBillingMigration.required
                        &&!window.__aimeeBillingMigration.grace))));
    }

    function locateChat(){
        var chat=document.getElementById("chat-interface")
            ||document.querySelector(".chat-interface,.conversation-view,main.chat");
        var messages=document.getElementById("messages")
            ||document.querySelector("#chat-messages,.chat-messages,.messages");
        if(!chat&&messages)chat=messages.parentElement;
        if(!chat)return null;
        var header=chat.querySelector(".app-header,.chat-header,.chat-head,#chat-header,.conversation-header,.chat-topbar,header");
        return {chat:chat,header:header};
    }

    function setPending(notice,pending){
        notice.setAttribute("aria-busy",pending?"true":"false");
        notice.querySelectorAll(".aimee-release-feedback-chat__choice").forEach(function(button){
            button.disabled=pending;
        });
    }

    function submitFeedback(notice,response){
        var status=notice.querySelector(".aimee-release-feedback-chat__status");
        setPending(notice,true);
        status.removeAttribute("data-state");
        status.textContent="Sending…";
        fetch(config.analyticsUrl,{
            method:"POST",
            credentials:"same-origin",
            headers:{"Content-Type":"application/json","X-WP-Nonce":config.nonce},
            body:JSON.stringify({
                event_name:"aimee_171_feedback",
                properties:{
                    release:"1.7.1",
                    response:response,
                    market:config.market,
                    surface:"chat_release_banner"
                }
            })
        }).then(function(result){
            return result.json().catch(function(){return {};}).then(function(data){
                if(!result.ok||data.status!=="success")throw new Error("Feedback was not recorded");
                return data;
            });
        }).then(function(){
            rememberResolution("response:"+response);
            notice.querySelector(".aimee-release-feedback-chat__feedback").innerHTML=
                '<span class="aimee-release-feedback-chat__status" role="status">Thank you — that helps us make Aimee better.</span>';
            notice.removeAttribute("aria-busy");
        }).catch(function(){
            setPending(notice,false);
            status.dataset.state="error";
            status.textContent="That didn’t send. Please try again.";
        });
    }

    function buildNotice(){
        var notice=document.createElement("aside");
        notice.id="aimee-release-feedback-chat";
        notice.setAttribute("aria-label","Aimee 1.7.1 release feedback");
        notice.innerHTML=
            '<span class="aimee-release-feedback-chat__body">'+
                '<span class="aimee-release-feedback-chat__eyebrow">Product update</span>'+
                '<strong class="aimee-release-feedback-chat__title">Aimee 1.7.1 is now live</strong>'+
                '<span class="aimee-release-feedback-chat__copy">She can respond more naturally to warmth, attentiveness and genuine interest.</span>'+
            '</span>'+
            '<span class="aimee-release-feedback-chat__feedback">'+
                '<span class="aimee-release-feedback-chat__prompt">Have you noticed a difference?</span>'+
                '<button class="aimee-release-feedback-chat__choice" type="button" data-response="feels_better">Feels better</button>'+
                '<button class="aimee-release-feedback-chat__choice" type="button" data-response="needs_work">Needs work</button>'+
                '<span class="aimee-release-feedback-chat__status" role="status" aria-live="polite"></span>'+
            '</span>'+
            '<button class="aimee-release-feedback-chat__dismiss" type="button" aria-label="Dismiss Aimee 1.7.1 update">×</button>';

        notice.querySelectorAll(".aimee-release-feedback-chat__choice").forEach(function(button){
            button.addEventListener("click",function(){submitFeedback(notice,button.dataset.response);});
        });
        notice.querySelector(".aimee-release-feedback-chat__dismiss").addEventListener("click",function(){
            rememberResolution("dismissed");
            notice.remove();
        });
        return notice;
    }

    function mount(){
        if(resolved()||billingNoticeActive()||document.getElementById("aimee-release-feedback-chat"))return;
        var location=locateChat();
        if(!location)return;
        document.getElementById("aimee-public-statement-chat")?.remove();
        var notice=buildNotice();
        if(location.header&&location.header.parentNode){
            location.header.insertAdjacentElement("afterend",notice);
        }else{
            location.chat.insertBefore(notice,location.chat.firstChild);
        }
        window.__aimeeReleaseFeedbackNoticeWasShown=true;
    }

    function boot(){
        mount();
        [250,900,1800,3200].forEach(function(delay){window.setTimeout(mount,delay);});
        if(document.body&&window.MutationObserver){
            new MutationObserver(function(){
                if(!billingNoticeActive())return;
                var notice=document.getElementById("aimee-release-feedback-chat");
                if(notice)notice.remove();
            }).observe(document.body,{childList:true,subtree:true});
        }
    }

    if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",boot);
    else boot();
})(__AIMEE_RELEASE_FEEDBACK_CONFIG__);
</script>
AIMEE_RELEASE_FEEDBACK_HTML;

    return str_replace('__AIMEE_RELEASE_FEEDBACK_CONFIG__', $json, $markup);
}

/**
 * Surface Engram Intelligence's current public statement inside the preserved
 * application UI. The historical template varies between installations, so
 * the notice mounts against several known chat-header shapes and then leaves
 * the underlying application untouched.
 */
function aimee_global_chat_press_release_markup($market) {
    if (!is_user_logged_in()) return '';

    $market = $market === 'us' ? 'us' : 'uk';
    $statement_url = function_exists('aimee_global_public_statement_url')
        ? aimee_global_public_statement_url()
        : home_url('/synthetic-neuroanatomy/');
    $config = [
        'statementUrl' => esc_url_raw($statement_url),
        'storageKey'   => 'aimeePublicStatementChatDismissed:1.4.7:' . $market,
    ];
    $json = wp_json_encode(
        $config,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );

    $markup = <<<'AIMEE_STATEMENT_HTML'
<style id="aimee-public-statement-chat-style">
#aimee-public-statement-chat{
    position:relative;
    z-index:8;
    display:flex;
    flex:0 0 auto;
    grid-column:1/-1;
    align-self:stretch;
    align-items:center;
    gap:12px;
    width:calc(100% - 20px);
    margin:8px 10px;
    padding:10px 42px 10px 13px;
    border:1px solid rgba(225,29,72,.2);
    border-radius:15px;
    background:linear-gradient(135deg,#fff5f7 0%,#fff 76%);
    box-shadow:0 8px 24px rgba(91,15,43,.08);
    color:#4b1628;
    font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
}
#aimee-public-statement-chat[hidden]{display:none!important}
.aimee-public-statement-chat__mark{
    display:grid;
    flex:0 0 34px;
    width:34px;
    height:34px;
    place-items:center;
    border-radius:11px;
    background:#18181b;
    color:#fff;
    font-size:15px;
    font-weight:850;
    letter-spacing:-.04em;
}
.aimee-public-statement-chat__body{
    min-width:0;
    flex:1;
}
.aimee-public-statement-chat__eyebrow{
    display:block;
    margin-bottom:2px;
    color:#9f1239;
    font-size:9px;
    font-weight:850;
    line-height:1.2;
    letter-spacing:.1em;
    text-transform:uppercase;
}
.aimee-public-statement-chat__title{
    display:block;
    color:#4b1628;
    font-size:12px;
    font-weight:760;
    line-height:1.35;
}
.aimee-public-statement-chat__link{
    flex:0 0 auto;
    border-bottom:1px solid rgba(159,18,57,.32);
    color:#9f1239;
    font-size:11px;
    font-weight:800;
    line-height:1.3;
    text-decoration:none;
}
.aimee-public-statement-chat__link:hover,
.aimee-public-statement-chat__link:focus-visible{
    border-color:#9f1239;
    color:#701a35;
    outline:none;
}
.aimee-public-statement-chat__dismiss{
    position:absolute;
    top:50%;
    right:7px;
    width:28px;
    height:28px;
    padding:0;
    transform:translateY(-50%);
    border:0;
    border-radius:50%;
    background:transparent;
    color:#8b5d6d;
    font:400 19px/28px Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
    cursor:pointer;
}
.aimee-public-statement-chat__dismiss:hover,
.aimee-public-statement-chat__dismiss:focus-visible{
    background:rgba(159,18,57,.08);
    color:#701a35;
    outline:none;
}
@media(max-width:640px){
    #aimee-public-statement-chat{
        gap:9px;
        margin:6px 8px;
        width:calc(100% - 16px);
        padding:9px 37px 9px 10px;
        border-radius:13px;
    }
    .aimee-public-statement-chat__mark{display:none}
    .aimee-public-statement-chat__eyebrow{font-size:8px}
    .aimee-public-statement-chat__title{font-size:11px}
    .aimee-public-statement-chat__link{font-size:10px}
}
@media(max-width:390px){
    #aimee-public-statement-chat{align-items:flex-start;flex-direction:column;gap:5px}
    .aimee-public-statement-chat__link{width:max-content}
}
</style>
<script id="aimee-public-statement-chat-ui">
(function(config){
    "use strict";
    if(!config||!config.statementUrl)return;

    function dismissed(){
        try{return window.localStorage.getItem(config.storageKey)==="1";}
        catch(error){return false;}
    }

    function rememberDismissal(){
        try{window.localStorage.setItem(config.storageKey,"1");}
        catch(error){}
    }

    function locateChat(){
        var chat=document.getElementById("chat-interface")
            ||document.querySelector(".chat-interface,.conversation-view,main.chat");
        var messages=document.getElementById("messages")
            ||document.querySelector("#chat-messages,.chat-messages,.messages");
        if(!chat&&messages)chat=messages.parentElement;
        if(!chat)return null;

        var header=chat.querySelector(".app-header,.chat-header,.chat-head,#chat-header,.conversation-header,.chat-topbar,header");
        return {chat:chat,header:header};
    }

    function buildNotice(){
        var notice=document.createElement("aside");
        notice.id="aimee-public-statement-chat";
        notice.setAttribute("aria-label","Engram Intelligence public statement");
        notice.innerHTML=
            '<span class="aimee-public-statement-chat__mark" aria-hidden="true">EI</span>'+
            '<span class="aimee-public-statement-chat__body">'+
                '<span class="aimee-public-statement-chat__eyebrow">Engram Intelligence · Public statement</span>'+
                '<strong class="aimee-public-statement-chat__title">How Aimee works—and why care should come before certainty.</strong>'+
            '</span>'+
            '<a class="aimee-public-statement-chat__link" target="_blank" rel="noopener">Read it <span aria-hidden="true">↗</span></a>'+
            '<button class="aimee-public-statement-chat__dismiss" type="button" aria-label="Dismiss public statement notice">×</button>';

        var link=notice.querySelector(".aimee-public-statement-chat__link");
        link.href=config.statementUrl;
        notice.querySelector(".aimee-public-statement-chat__dismiss").addEventListener("click",function(){
            rememberDismissal();
            notice.remove();
        });
        return notice;
    }

    function mount(){
        if(dismissed()
            ||window.__aimeeReleaseFeedbackNoticeWasShown
            ||document.getElementById("aimee-release-feedback-chat")
            ||document.getElementById("aimee-billing-migration-card")
            ||document.getElementById("aimee-billing-reconciliation-card")
            ||document.getElementById("aimee-service-grace-card")
            ||(window.__aimeeBillingMigration
                &&(window.__aimeeBillingMigration.required
                    ||window.__aimeeBillingMigration.reconciliation))
            ||document.getElementById("aimee-public-statement-chat"))return;
        var location=locateChat();
        if(!location)return;
        var notice=buildNotice();
        if(location.header&&location.header.parentNode){
            location.header.insertAdjacentElement("afterend",notice);
        }else{
            location.chat.insertBefore(notice,location.chat.firstChild);
        }
    }

    function boot(){
        mount();
        [250,900,1800,3200].forEach(function(delay){
            window.setTimeout(mount,delay);
        });
    }

    if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",boot);
    else boot();
})(__AIMEE_STATEMENT_CONFIG__);
</script>
AIMEE_STATEMENT_HTML;

    return str_replace('__AIMEE_STATEMENT_CONFIG__', $json, $markup);
}

/** Keep Aimee's Camera Roll permanently discoverable inside either chat UI. */
function aimee_global_chat_gallery_discovery_markup($market) {
    if (!is_user_logged_in()) return '';

    $market = $market === 'us' ? 'us' : 'uk';
    $gallery_url = function_exists('aimee_global_route')
        ? aimee_global_route('gallery', $market)
        : home_url($market === 'us' ? '/camera-roll-us/' : '/camera-roll/');
    $config = wp_json_encode([
        'galleryUrl' => esc_url_raw($gallery_url),
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $markup = <<<'AIMEE_GALLERY_DISCOVERY_HTML'
<style id="aimee-chat-gallery-discovery-style">
#aimee-chat-gallery-link{
    display:inline-flex;
    min-width:44px;
    min-height:44px;
    margin-inline-start:auto;
    padding:9px 13px;
    align-items:center;
    justify-content:center;
    gap:7px;
    border:1px solid rgba(24,24,27,.1);
    border-radius:999px;
    background:#fff;
    box-shadow:0 6px 18px rgba(24,24,27,.12);
    color:#27272a;
    font:800 12px/1 Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
    text-decoration:none;
    white-space:nowrap;
}
#aimee-chat-gallery-link:hover{background:#fff1f4;color:#9f1239}
#aimee-chat-gallery-link:focus-visible{outline:3px solid rgba(255,255,255,.8);outline-offset:2px}
#aimee-chat-gallery-link svg{width:18px;height:18px;flex:0 0 auto}
#aimee-chat-gallery-link.aimee-chat-gallery-link--fallback{margin:8px 10px 0;align-self:flex-end}
@media(max-width:430px){#aimee-chat-gallery-link{padding-inline:11px}}
</style>
<script id="aimee-chat-gallery-discovery">
(function(config){
    "use strict";
    if(!config||!config.galleryUrl)return;

    function locateChat(){
        var chat=document.getElementById("chat-interface")
            ||document.querySelector(".chat-interface,.conversation-view,main.chat");
        var messages=document.getElementById("messages")
            ||document.querySelector("#chat-messages,.chat-messages,.messages");
        if(!chat&&messages)chat=messages.parentElement;
        if(!chat)return null;
        var header=chat.querySelector(".app-header,.chat-header,.chat-head,#chat-header,.conversation-header,.chat-topbar,header");
        return {chat:chat,header:header};
    }

    function buildLink(){
        var link=document.createElement("a");
        link.id="aimee-chat-gallery-link";
        link.href=config.galleryUrl;
        link.setAttribute("aria-label","Open Aimee’s photo gallery");
        link.innerHTML='<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 7.5A2.5 2.5 0 0 1 6.5 5h2l1.2-1.5h4.6L15.5 5h2A2.5 2.5 0 0 1 20 7.5v9a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 16.5v-9Z" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="3.2" stroke="currentColor" stroke-width="1.8"/></svg><span>Photos</span>';
        return link;
    }

    function mount(){
        if(document.getElementById("aimee-chat-gallery-link"))return;
        var location=locateChat();
        if(!location)return;
        var link=buildLink();
        if(location.header){
            location.header.appendChild(link);
        }else{
            link.classList.add("aimee-chat-gallery-link--fallback");
            location.chat.insertBefore(link,location.chat.firstChild);
        }
    }

    function boot(){
        mount();
        [250,900,1800,3200].forEach(function(delay){window.setTimeout(mount,delay);});
        if(document.body&&window.MutationObserver){
            new MutationObserver(mount).observe(document.body,{childList:true,subtree:true});
        }
    }
    if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",boot);
    else boot();
})(__AIMEE_GALLERY_DISCOVERY_CONFIG__);
</script>
AIMEE_GALLERY_DISCOVERY_HTML;

    return str_replace('__AIMEE_GALLERY_DISCOVERY_CONFIG__', $config, $markup);
}

/**
 * Keep the historical onboarding application usable on short and magnified
 * mobile viewports. The onboarding shell owns scrolling so content enlarged
 * by browser or accessibility zoom cannot escape a nested flex scroll range.
 */
function aimee_global_chat_mobile_onboarding_scroll_markup() {
    return <<<'AIMEE_MOBILE_SCROLL_HTML'
<style id="aimee-mobile-onboarding-scroll-fix">
#onboarding-view-wrapper{
    height:calc(100vh - 24px)!important;
    max-height:calc(100vh - 24px)!important;
    overflow-x:hidden!important;
    overflow-y:auto!important;
    -webkit-overflow-scrolling:touch;
    overscroll-behavior-y:contain;
    touch-action:pan-y;
    scroll-padding-block:24px 48px;
    scroll-padding-block:24px calc(48px + env(safe-area-inset-bottom));
}
@supports (height:100dvh){
    #onboarding-view-wrapper{
        height:calc(100dvh - 24px)!important;
        max-height:calc(100dvh - 24px)!important;
    }
}
#onboarding-screen{
    flex:0 0 auto;
    width:100%;
    height:auto!important;
    min-height:100%;
    overflow:visible!important;
    padding-bottom:48px!important;
    padding-bottom:calc(48px + env(safe-area-inset-bottom))!important;
}
@media (max-width:760px), (max-height:760px){
    #onboarding-screen{
        padding:28px 20px 32px!important;
        padding:28px 20px calc(32px + env(safe-area-inset-bottom))!important;
        scroll-padding-block:20px calc(32px + env(safe-area-inset-bottom));
    }
    #onboarding-screen > div:first-child{
        margin-bottom:20px!important;
    }
    #onboarding-screen .onboarding-step.active{
        flex:0 0 auto;
        min-height:0;
        justify-content:flex-start;
        padding-top:28px;
        padding-bottom:16px;
    }
}
</style>
<script id="aimee-mobile-onboarding-scroll-behaviour">
(function(){
    "use strict";
    function boot(){
        var shell = document.getElementById("onboarding-view-wrapper");
        if (!shell) return;

        document.addEventListener("click", function(event){
            var target = event.target && event.target.closest
                ? event.target.closest("#btn-start-application, .next-step")
                : null;
            if (!target) return;
            window.requestAnimationFrame(function(){ shell.scrollTop = 0; });
        });
    }

    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
    else boot();
})();
</script>
AIMEE_MOBILE_SCROLL_HTML;
}

/**
 * Apply security-critical browser behaviour to a theme-owned historical UI.
 * The bridge works at the fetch boundary rather than depending on legacy
 * variable names, so stale closure state cannot resend old image bytes.
 */
function aimee_global_chat_security_bridge_markup($market) {
    $config = wp_json_encode([
        'profileEndpoint' => rest_url('aimee/v1/profile'),
        'messageEndpoint' => rest_url('aimee/v1/message'),
        'voiceNoteEndpoint' => rest_url('aimee/v1/voice-note/send'),
        'voiceTurnEndpoint' => rest_url('aimee/v1/voice/turn'),
        'privacyUrl' => function_exists('aimee_global_route')
            ? aimee_global_route('privacy', $market)
            : home_url($market === 'us' ? '/privacy-us/' : '/privacy/'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $markup = <<<'AIMEE_SECURITY_BRIDGE'
<style id="aimee-security-privacy-bridge-style">
.aimee-required-consents{display:grid;gap:10px;margin:16px 0;text-align:left}
.aimee-required-consent{display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border:1px solid #e4e4e7;border-radius:14px;background:#fafafa;color:#3f3f46;font:500 12px/1.5 Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.aimee-required-consent input{flex:0 0 auto;margin-top:3px}.aimee-required-consent strong{display:block;margin-bottom:2px;color:#18181b;font-size:13px}.aimee-required-consent a{color:#9f1239}
.aimee-privacy-notice-link{margin:0;padding:0 2px;color:#52525b;font:500 12px/1.5 Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.aimee-privacy-notice-link a{color:#9f1239}
#aimee-gallery-question-context{display:flex;align-items:center;gap:10px;margin:8px 10px;padding:10px 12px;border:1px solid rgba(225,29,72,.2);border-radius:14px;background:#fff8fa;color:#4b1628;font:650 12px/1.4 Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
#aimee-gallery-question-context span{flex:1;min-width:0}#aimee-gallery-question-context button{flex:0 0 auto;border:0;border-radius:999px;padding:7px 10px;background:#18181b;color:#fff;font:750 11px/1 Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;cursor:pointer}
</style>
<script id="aimee-security-privacy-bridge">
(function(config){
    "use strict";
    if (!config || window.__aimeeSecurityPrivacyBridge) return;
    window.__aimeeSecurityPrivacyBridge = true;

    var pendingImageEventId = "";
    var lastSentImageEventId = "";
    var lastSentImageFingerprint = "";
    var originalFetch = window.fetch.bind(window);
    var galleryStorageKey = "aimeeGalleryQuestion:1";
    var galleryReference = null;
    var galleryReferenceMaxAge = 10 * 60 * 1000;
    var galleryDefaultQuestion = "What’s the story behind this photo?";

    function clearGalleryReference(clearDefaultQuestion){
        galleryReference = null;
        try { window.sessionStorage.removeItem(galleryStorageKey); } catch (error) {}
        var chip = document.getElementById("aimee-gallery-question-context");
        if (chip) chip.remove();
        if (clearDefaultQuestion) {
            var composer = galleryComposer();
            if (composer && String(composer.textarea.value || "") === galleryDefaultQuestion) {
                composer.textarea.value = "";
                composer.textarea.dispatchEvent(new Event("input", {bubbles:true}));
            }
        }
    }

    function galleryReferenceIsFresh(reference){
        var key = reference && typeof reference.key === "string"
            ? reference.key
            : "";
        var createdAt = reference ? Number(reference.created_at || 0) : 0;
        var now = Date.now();
        return /^[a-z0-9_-]{1,191}$/.test(key)
            && Number.isFinite(createdAt)
            && createdAt <= now + 60000
            && createdAt >= now - galleryReferenceMaxAge;
    }

    function readGalleryReference(){
        var parsed = null;
        try { parsed = JSON.parse(window.sessionStorage.getItem(galleryStorageKey) || "null"); }
        catch (error) { parsed = null; }
        var candidate = {
            key: parsed && typeof parsed.key === "string" ? parsed.key : "",
            created_at: parsed ? Number(parsed.created_at || 0) : 0
        };
        if (!galleryReferenceIsFresh(candidate)) {
            clearGalleryReference();
            return null;
        }
        return candidate;
    }

    function galleryComposer(){
        var textarea = document.querySelector(
            "#text,#message-input,#chat-input,textarea[name=\"message\"]," +
            "#chat-interface textarea,.conversation-view textarea,.composer textarea"
        );
        if (!textarea || isOnboardingNode(textarea)) return null;
        var host = textarea.closest
            ? textarea.closest("#message-composer,.chat-input-area,.composer,.input-area,form")
            : null;
        return {textarea:textarea,host:host || textarea.parentElement};
    }

    function mountGalleryReference(){
        if (!galleryReference) galleryReference = readGalleryReference();
        if (!galleryReference || document.getElementById("aimee-gallery-question-context")) return;
        var composer = galleryComposer();
        if (!composer || !composer.host) return;
        if (!String(composer.textarea.value || "").trim()) {
            composer.textarea.value = galleryDefaultQuestion;
            composer.textarea.dispatchEvent(new Event("input", {bubbles:true}));
        }
        var chip = document.createElement("div");
        chip.id = "aimee-gallery-question-context";
        chip.setAttribute("role", "status");
        var label = document.createElement("span");
        label.textContent = "Asking Aimee about this Camera Roll photo";
        var cancel = document.createElement("button");
        cancel.type = "button";
        cancel.textContent = "Cancel";
        cancel.addEventListener("click", function(){ clearGalleryReference(true); });
        chip.appendChild(label);
        chip.appendChild(cancel);
        composer.host.parentNode.insertBefore(chip, composer.host);
    }

    function newImageEventId(){
        return window.crypto && typeof window.crypto.randomUUID === "function"
            ? window.crypto.randomUUID()
            : "img-" + Date.now().toString(36) + "-" + Math.random().toString(36).slice(2);
    }

    function endpointMatches(url, endpoint){
        try {
            var left = new URL(String(url), window.location.href);
            var right = new URL(String(endpoint), window.location.href);
            return left.origin === right.origin && left.pathname.replace(/\/$/, "") === right.pathname.replace(/\/$/, "");
        } catch (error) { return false; }
    }

    function isOnboardingNode(node){
        return !!(node && node.closest && node.closest(
            "#onboarding-screen,#onboarding-view-wrapper,#onboard,.onboarding-screen,.onboarding-view,[data-onboarding]"
        ));
    }

    function isChatImageInput(input){
        if (!input || input.type !== "file" || isOnboardingNode(input)) return false;
        var name = String(input.name || "").toLowerCase();
        var id = String(input.id || "").toLowerCase();
        if (/profile|avatar|portrait/.test(name + " " + id)) return false;
        return /image/.test(String(input.accept || "").toLowerCase())
            || /image|photo|picture|attachment|upload/.test(name + " " + id);
    }

    function clearChatImageComposer(){
        Array.prototype.forEach.call(document.querySelectorAll('input[type="file"]'), function(input){
            if (isChatImageInput(input)) input.value = "";
        });
        Array.prototype.forEach.call(document.querySelectorAll(
            "#image-preview,#selected-image-preview,#preview,.image-preview,.attachment-preview,[data-image-preview]"
        ), function(preview){
            preview.removeAttribute("data-image-event-id");
            if (preview.tagName === "IMG") preview.removeAttribute("src");
            Array.prototype.forEach.call(preview.querySelectorAll ? preview.querySelectorAll("img") : [], function(image){
                image.removeAttribute("src");
            });
            preview.style.display = "none";
        });
    }

    document.addEventListener("change", function(event){
        var input = event.target;
        if (!isChatImageInput(input)) return;
        if (input.files && input.files.length) {
            // One turn cannot truthfully refer to a Camera Roll image and a
            // different user upload. Choosing a file cancels the handoff.
            clearGalleryReference(true);
            pendingImageEventId = newImageEventId();
            input.setAttribute("data-aimee-image-event-id", pendingImageEventId);
        } else {
            pendingImageEventId = "";
            input.removeAttribute("data-aimee-image-event-id");
        }
    }, true);

    document.addEventListener("click", function(event){
        var voiceControl = event.target && event.target.closest
            ? event.target.closest(
                "#voice-btn,#voice-button,#record-voice,#start-voice-call," +
                "button[data-voice-record],button[data-voice-turn],button.voice-record-button"
            )
            : null;
        if (voiceControl) clearGalleryReference(true);
    }, true);

    function checkboxValue(name){
        var checkbox = document.querySelector('input[name="' + name + '"]');
        return !!(checkbox && checkbox.checked);
    }

    function jsonBody(init){
        if (!init || typeof init.body !== "string") return null;
        try {
            var parsed = JSON.parse(init.body);
            return parsed && typeof parsed === "object" ? parsed : null;
        } catch (error) { return null; }
    }

    async function imageFingerprint(value){
        value = String(value || "");
        if (window.crypto && window.crypto.subtle && typeof TextEncoder === "function") {
            try {
                var bytes = new TextEncoder().encode(value);
                var digest = await window.crypto.subtle.digest("SHA-256", bytes);
                return value.length + ":" + Array.prototype.map.call(
                    new Uint8Array(digest),
                    function(byte){ return byte.toString(16).padStart(2, "0"); }
                ).join("");
            } catch (error) {
                // Continue into the bounded legacy-context fallback below.
            }
        }
        // Legacy/insecure-context fallback retains only bounded samples and two
        // rolling hashes, never the base64 image itself.
        var first = 2166136261;
        var second = 5381;
        for (var index = 0; index < value.length; index++) {
            var code = value.charCodeAt(index);
            first = Math.imul(first ^ code, 16777619) >>> 0;
            second = (Math.imul(second, 33) ^ code) >>> 0;
        }
        return value.length + ":" + first.toString(16) + ":" + second.toString(16)
            + ":" + value.slice(0, 32) + ":" + value.slice(-32);
    }

    window.fetch = async function(input, init){
        var url = typeof input === "string" || input instanceof URL ? String(input) : String(input && input.url || "");
        if (
            endpointMatches(url, config.voiceNoteEndpoint)
            || endpointMatches(url, config.voiceTurnEndpoint)
        ) {
            clearGalleryReference(true);
            return originalFetch(input, init);
        }
        var body = jsonBody(init);
        if (!body) return originalFetch(input, init);

        if (endpointMatches(url, config.profileEndpoint)) {
            body.special_category_consent = checkboxValue("special_category_consent");
            init = Object.assign({}, init, {body: JSON.stringify(body)});
            return originalFetch(input, init);
        }

        if (!endpointMatches(url, config.messageEndpoint)) {
            return originalFetch(input, init);
        }

        var image = typeof body.image === "string" ? body.image : "";
        var message = String(body.message || body.message_text || "").trim();
        delete body.referenced_media_key;
        if (galleryReference && !galleryReferenceIsFresh(galleryReference)) {
            clearGalleryReference(true);
        }
        if (image && galleryReference) clearGalleryReference(true);
        var referenceKey = galleryReference && !image
            ? String(galleryReference.key || "")
            : "";
        if (referenceKey) {
            body.referenced_media_key = referenceKey;
            // Consume before network I/O so double submissions cannot reuse a
            // catalogue reference. The user can select the photo again after
            // a failed request.
            clearGalleryReference(false);
        }
        if (image) {
            var currentImageFingerprint = await imageFingerprint(image);
            var suppliedEventId = typeof body.image_event_id === "string" ? body.image_event_id : "";
            if (suppliedEventId) {
                lastSentImageEventId = suppliedEventId;
                lastSentImageFingerprint = currentImageFingerprint;
                pendingImageEventId = "";
            } else if (pendingImageEventId) {
                body.image_event_id = pendingImageEventId;
                lastSentImageEventId = pendingImageEventId;
                lastSentImageFingerprint = currentImageFingerprint;
                pendingImageEventId = "";
            } else if (lastSentImageFingerprint && currentImageFingerprint === lastSentImageFingerprint) {
                // A legacy closure retained bytes after the visible composer was
                // cleared. Do not put those bytes on the network again.
                delete body.image;
                delete body.image_event_id;
                clearChatImageComposer();
                if (!message && typeof window.Response === "function") {
                    return Promise.resolve(new Response(JSON.stringify({
                        status: "duplicate_image_ignored",
                        duplicate_ignored: true,
                        reply: "",
                        reply_text: ""
                    }), {status: 200, headers:{"Content-Type":"application/json"}}));
                }
            } else {
                body.image_event_id = newImageEventId();
                lastSentImageEventId = body.image_event_id;
                lastSentImageFingerprint = currentImageFingerprint;
            }
            clearChatImageComposer();
        }

        init = Object.assign({}, init, {body: JSON.stringify(body)});
        return originalFetch(input, init);
    };

    function makeConsent(name, title, text, includeLink, required){
        var label = document.createElement("label");
        label.className = "aimee-required-consent";
        var checkbox = document.createElement("input");
        checkbox.type = "checkbox";
        checkbox.name = name;
        checkbox.value = "1";
        checkbox.required = Boolean(required);
        var copy = document.createElement("span");
        var strong = document.createElement("strong");
        strong.textContent = title;
        copy.appendChild(strong);
        copy.appendChild(document.createTextNode(text + " "));
        if (includeLink) {
            var link = document.createElement("a");
            link.href = config.privacyUrl;
            link.target = "_blank";
            link.rel = "noopener";
            link.textContent = "Read the privacy notice";
            copy.appendChild(link);
        }
        label.appendChild(checkbox);
        label.appendChild(copy);
        return label;
    }

    function makePrivacyNotice(){
        var copy = document.createElement("p");
        copy.className = "aimee-privacy-notice-link";
        copy.appendChild(document.createTextNode("You can read Aimee’s "));
        var link = document.createElement("a");
        link.href = config.privacyUrl;
        link.target = "_blank";
        link.rel = "noopener";
        link.textContent = "privacy notice";
        copy.appendChild(link);
        copy.appendChild(document.createTextNode(" now or at any time from chat settings. No acknowledgement is required to continue."));
        return copy;
    }

    function isRegistrationForm(form){
        if (!form || !form.querySelector) return false;
        if (form.getAttribute && form.getAttribute("data-aimee-registration") === "1") return true;
        return !!(
            form.querySelector('input[name="first_name"]')
            && form.querySelector('input[name="age"]')
            && form.querySelector('input[name="phone_number"]')
            && form.querySelector('input[name="passcode"],input[autocomplete="new-password"]')
        );
    }

    function hardenPasswords(root){
        Array.prototype.forEach.call((root || document).querySelectorAll('input[type="password"]'), function(input){
            var form = input.closest ? input.closest("form") : null;
            var profileForm = isRegistrationForm(form);
            var registration = input.autocomplete === "new-password"
                || profileForm;
            if (registration && input.autocomplete !== "current-password") {
                input.removeAttribute("data-aimee-passphrase");
                input.setAttribute("pattern", "[0-9]{6}");
                input.setAttribute("inputmode", "numeric");
                input.setAttribute("minlength", "6");
                input.setAttribute("maxlength", "6");
                input.setAttribute("data-aimee-passcode", "1");
                input.autocomplete = "new-password";
                input.placeholder = "Choose six numbers";
            } else {
                // Authentication remains format-free so accounts created
                // during the passphrase release continue to sign in.
                input.removeAttribute("pattern");
                input.removeAttribute("inputmode");
                input.removeAttribute("minlength");
                input.removeAttribute("maxlength");
                input.removeAttribute("data-aimee-passcode");
                input.removeAttribute("data-aimee-passphrase");
            }
        });
    }

    function validatePasscode(input, report){
        if (!input) return true;
        var message = /^[0-9]{6}$/.test(String(input.value || ""))
            ? ""
            : "Choose a six-digit passcode.";
        input.setCustomValidity(message);
        if (message && report && typeof input.reportValidity === "function") input.reportValidity();
        return !message;
    }

    document.addEventListener("input", function(event){
        var input = event.target;
        if (input && input.matches && input.matches('[data-aimee-passcode]')) {
            validatePasscode(input, false);
        }
    }, true);
    document.addEventListener("submit", function(event){
        var form = event.target;
        var input = form && form.querySelector ? form.querySelector('[data-aimee-passcode]') : null;
        if (input && !validatePasscode(input, true)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);

    function mountConsents(root){
        hardenPasswords(root || document);
        Array.prototype.forEach.call((root || document).querySelectorAll("form"), function(form){
            if (form.querySelector('.aimee-required-consents,input[name="special_category_consent"]')) return;
            var password = form.querySelector('input[name="passcode"],input[autocomplete="new-password"]');
            var profileForm = isRegistrationForm(form);
            if (!password || !profileForm || password.autocomplete === "current-password") return;
            var submit = form.querySelector('button[type="submit"],input[type="submit"]');
            if (!submit) return;
            var box = document.createElement("div");
            box.className = "aimee-required-consents";
            box.appendChild(makePrivacyNotice());
            box.appendChild(makeConsent(
                "special_category_consent",
                "Optional sensitive-information consent",
                "I explicitly consent to processing sensitive information I choose to share, including health, sexual-life or sexual-orientation information, for specialist personalisation. Ordinary chat remains available if I leave this unticked, and I can change it later in settings.",
                false,
                false
            ));
            submit.parentNode.insertBefore(box, submit);
        });
    }

    function boot(){
        mountConsents(document);
        galleryReference = readGalleryReference();
        mountGalleryReference();
        if (typeof MutationObserver === "function") {
            new MutationObserver(function(records){
                records.forEach(function(record){
                    Array.prototype.forEach.call(record.addedNodes || [], function(node){
                        if (node && node.querySelectorAll) {
                            mountConsents(node);
                            mountGalleryReference();
                        }
                    });
                });
            }).observe(document.documentElement, {childList:true, subtree:true});
        }
    }
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
    else boot();
})(__AIMEE_SECURITY_CONFIG__);
</script>
AIMEE_SECURITY_BRIDGE;

    return str_replace('__AIMEE_SECURITY_CONFIG__', $config, $markup);
}

/**
 * Apply market-only copy changes without altering the original application\'s
 * structure, styling, controls or JavaScript behaviour.
 */
function aimee_global_transform_legacy_chat_html($html, $market) {
    $market = $market === 'us' ? 'us' : 'uk';
    $html = (string) $html;
    $html = str_replace('“Avenrà”', '“AIMEE AI”', $html);
    $html = str_replace('"Avenrà"', '"AIMEE AI"', $html);
    $html = strtr($html, [
        'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no'
            => 'width=device-width, initial-scale=1.0, viewport-fit=cover',
        'width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no'
            => 'width=device-width,initial-scale=1.0,viewport-fit=cover',
    ]);

    $preview_replacements = [
        'Start Chatting (Free £5 Credit)' => 'Start Chatting (30 Free Replies)',
        'Start Chatting (Free $5 Credit)' => 'Start Chatting (30 Free Replies)',
        'Free £5 Credit' => '30 Free Replies',
        'Free $5 Credit' => '30 Free Replies',
        'complimentary £5.00 connection credit' => '30 complimentary replies from Aimee',
        'complimentary $5.00 connection credit' => '30 complimentary replies from Aimee',
        'complimentary £5 credit' => '30 complimentary replies from Aimee',
        'complimentary $5 credit' => '30 complimentary replies from Aimee',
        '£5.00 connection credit' => '30 complimentary replies',
        '$5.00 connection credit' => '30 complimentary replies',
        '£5 credit' => '30 free replies',
        '$5 credit' => '30 free replies',
        'Start Free Trial' => 'Start Free Preview',
    ];
    $html = strtr($html, $preview_replacements);

    // The historical application loads conversation history once during page
    // startup, then reveals the already-rendered chat when the user taps Chat.
    // A message inserted by autonomous delivery, an administrator or another
    // device after startup can therefore remain in MySQL without appearing in
    // the open client. Refresh history every time the chat view is opened.
    $chat_open_original = "if (btnNavToChat) btnNavToChat.addEventListener('click', () => { showAuthView(viewChat); trackEvent('chat_opened', {}); });";
    $chat_open_synced = <<<'AIMEE_CHAT_OPEN_SYNC'
let aimeeHistorySyncBusy = false;
let aimeeHistoryFingerprint = '';

function aimeeChatIsVisible() {
    return !!(viewChat && viewChat.offsetParent !== null);
}

function aimeeHistorySignature(messages) {
    if (!Array.isArray(messages)) return '';
    return JSON.stringify(messages.map(entry => [
        entry.message_id || 0,
        entry.sender || '',
        entry.created_at || '',
        entry.message_text || '',
        entry.media && (entry.media.key || entry.media.url || entry.media.src) || '',
        entry.voice_note && entry.voice_note.token || ''
    ]));
}

function aimeeRefreshVisibleHistory(force = false) {
    if (aimeeHistorySyncBusy) return Promise.resolve();
    if (!force && !aimeeChatIsVisible()) return Promise.resolve();
    if (document.getElementById('aimee-typing-bubble')) return Promise.resolve();

    aimeeHistorySyncBusy = true;

    return apiFetch(`/history?_t=${Date.now()}`, { method: 'GET' })
        .then(data => {
            if (data.subscription) updateMembershipDisplay(data.subscription);
            if (data.status !== 'success' || !Array.isArray(data.messages)) return;

            const nextFingerprint = aimeeHistorySignature(data.messages);
            if (!force && nextFingerprint === aimeeHistoryFingerprint) return;

            const nearBottom = chatWindow
                ? (chatWindow.scrollHeight - chatWindow.scrollTop - chatWindow.clientHeight) < 160
                : true;

            chatWindow.querySelectorAll('.message').forEach(message => {
                if (message.id !== 'aimee-typing-bubble') message.remove();
            });

            const emptyPrompt = document.getElementById('empty-chat-prompt');
            if (emptyPrompt) {
                emptyPrompt.style.display = data.messages.length > 0
                    ? 'none'
                    : 'flex';
            }

            data.messages.forEach(appendHistoryEntry);
            aimeeHistoryFingerprint = nextFingerprint;

            if (nearBottom && chatWindow) {
                window.requestAnimationFrame(() => {
                    chatWindow.scrollTop = chatWindow.scrollHeight;
                });
            }
        })
        .catch(error => console.error('Chat history sync failed:', error))
        .finally(() => { aimeeHistorySyncBusy = false; });
}

if (btnNavToChat) btnNavToChat.addEventListener('click', () => {
    showAuthView(viewChat);
    trackEvent('chat_opened', {});
    aimeeRefreshVisibleHistory(true);
});

// Keep an already-open chat current. This is intentionally lightweight and
// pauses while Aimee is typing so it cannot interrupt an in-flight turn.
window.setInterval(() => {
    aimeeRefreshVisibleHistory(false);
}, 8000);

document.addEventListener('visibilitychange', () => {
    if (!document.hidden) aimeeRefreshVisibleHistory(false);
});
AIMEE_CHAT_OPEN_SYNC;

    if (
        strpos($html, $chat_open_original) !== false
        && strpos($html, 'Chat open sync failed:') === false
    ) {
        $html = str_replace($chat_open_original, $chat_open_synced, $html);
    }

    // Teach the preserved historical chat UI about the dedicated billing
    // migration response without changing its layout or core application code.
    $html = str_replace(
        [
            "['subscription_required', 'trial_ended', 'insufficient_funds']",
            '["subscription_required", "trial_ended", "insufficient_funds"]',
        ],
        [
            "['subscription_required', 'trial_ended', 'billing_reactivation_required', 'insufficient_funds']",
            '["subscription_required", "trial_ended", "billing_reactivation_required", "insufficient_funds"]',
        ],
        $html
    );

    $injected = '';

    if (
        stripos($html, 'id="onboarding-view-wrapper"') !== false &&
        stripos($html, 'id="onboarding-screen"') !== false &&
        stripos($html, 'id="aimee-mobile-onboarding-scroll-fix"') === false
    ) {
        $injected .= aimee_global_chat_mobile_onboarding_scroll_markup();
    }

    if ($market === 'us') {
        $replacements = [
            '£6.99' => '$6.99',
            '£19.99' => '$19.99',
            '£149.00' => '$149.00',
            '£12.42' => '$12.42',
            'https://aimee-ai.com/camera-roll' => home_url('/camera-roll-us/'),
            'Mobile Number (e.g. 07...)' => 'US mobile number, email address or username',
            'UK mobile number or username' => 'US mobile number, email address or username',
            '07… or choose a username' => '+1 212 555 0123, email, or username',
            'Text messages from Aimee' => 'Text messages from Aimee’s UK number',
            'Allow Aimee to contact you by SMS when it feels natural.' => 'Allow Aimee to contact you by SMS from her UK +44 number when it feels natural.',
            'Aimee will only start an SMS conversation between these hours.' => 'Aimee will only start an SMS conversation between these hours. Her messages come from a UK +44 number.',
            'Let Aimee message outside your preferred hours when she has something to say.' => 'Let Aimee message outside your preferred hours when she has something to say.',
            'Please enter a valid UK mobile number' => 'Please enter a valid US mobile number, including area code',
        ];
        $html = strtr($html, $replacements);
        $html = str_replace(
            [
                home_url('/chat/'),
                home_url('/pricing/'),
                home_url('/faq/'),
                home_url('/technology/'),
                home_url('/privacy/'),
                home_url('/camera-roll/'),
            ],
            [
                home_url('/chat-us/'),
                home_url('/pricing-us/'),
                home_url('/faq-us/'),
                home_url('/technology-us/'),
                home_url('/privacy-us/'),
                home_url('/camera-roll-us/'),
            ],
            $html
        );

        $injected .= <<<'HTML'
<style id="aimee-us-market-style">
.aimee-us-sms-notice{margin:10px 0 4px;padding:12px 14px;border:1px solid #f4c7d2;border-radius:14px;background:#fff5f7;color:#7f1d3d;font:600 12px/1.55 Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.aimee-us-sms-notice strong{display:block;margin-bottom:2px;color:#9f1239}
.aimee-us-checkout-unavailable{opacity:.58!important;cursor:not-allowed!important}
</style>
<script id="aimee-us-market-ui">
(function(){
    var warning = 'Aimee uses a UK +44 number. Your mobile provider may treat texts to or from it as international and charge them outside any included SMS allowance. Check your plan before opting in.';
    function addNotice(afterNode){
        if (!afterNode || document.querySelector('.aimee-us-sms-notice')) return;
        var notice = document.createElement('div');
        notice.className = 'aimee-us-sms-notice';
        notice.innerHTML = '<strong>International text charges may apply</strong>' + warning;
        afterNode.insertAdjacentElement('afterend', notice);
    }
    function applyUSMarketUI(){
        document.documentElement.lang = 'en-US';
        document.body.classList.add('aimee-market-us');
        document.querySelectorAll('.membership-checkout-btn,[data-plan]').forEach(function(button){
            button.dataset.market = 'us';
            button.classList.add('aimee-us-checkout-unavailable');
            button.setAttribute('aria-disabled', 'true');
            button.setAttribute('title', 'New paid membership checkout is unavailable for US profiles.');
            if (button.matches('button')) button.disabled = true;
            var action = button.querySelector('.membership-plan-action');
            if (action) action.textContent = 'US checkout unavailable';
            else if (button.matches('button,a')) button.textContent = 'US checkout unavailable';
        });

        var sms = document.getElementById('edit-sms-opt-in');
        if (sms) {
            var row = sms.closest('.setting-row,.settings-row,.field,.form-group') || sms.parentElement;
            if (row) {
                row.style.display = '';
                addNotice(row);
            }
            if (sms.disabled) {
                sms.title = 'Carrier SMS remains off until this mobile number is securely verified.';
            }
        }

        var routing = document.getElementById('routing-options-container');
        if (routing) routing.style.display = '';

        document.querySelectorAll('input[name="phone_number"],input[id*="phone" i],input[id*="mobile" i]').forEach(function(input){
            input.removeAttribute('pattern');
            if (!input.placeholder || /07|UK mobile/i.test(input.placeholder)) {
                input.placeholder = '+1 212 555 0123, email, or username';
            }
            var field = input.closest('.field,.form-group,.setting-row,.settings-row');
            if (field && !field.querySelector('.aimee-us-sms-notice')) addNotice(field);
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', applyUSMarketUI);
    else applyUSMarketUI();
})();
</script>
HTML;
    }

    if (strpos($html, 'data-aimee-privacy-consent-settings="1"') === false) {
        $injected .= aimee_global_chat_privacy_consent_markup($market);
    }
    $injected .= aimee_global_chat_security_bridge_markup($market);
    $injected .= aimee_global_chat_gallery_discovery_markup($market);
    $injected .= aimee_global_chat_release_feedback_markup($market);
    $injected .= aimee_global_chat_press_release_markup($market);
    $injected .= aimee_global_chat_billing_migration_markup($market);
    $injected .= aimee_global_media_delivery_markup();

    if ($injected !== '') {
        if (stripos($html, '</body>') !== false) {
            $html = preg_replace('/<\/body>/i', $injected . '</body>', $html, 1);
        } else {
            $html .= $injected;
        }
    }

    return $html;
}

function aimee_global_render_legacy_chat($market) {
    $market = $market === 'us' ? 'us' : 'uk';
    aimee_global_set_market($market);

    // Both market routes deliberately begin with the canonical UK visual
    // application so onboarding, chat, settings and voice notes remain the
    // same design. /chat-us/ is still a separate WordPress page and receives
    // its own routes, currency, copy and telecom disclosure after rendering.
    $source = aimee_global_find_legacy_chat_template(false);

    if (!$source || !is_readable($source)) {
        $aimee_market = $market;
        $fallback = AIMEE_GLOBAL_DIR . 'templates/shared/chat-fallback.php';
        if (is_readable($fallback)) {
            ob_start();
            include $fallback;
            $fallback_html = ob_get_clean();
            echo aimee_global_transform_legacy_chat_html($fallback_html, $market); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            return;
        }
        status_header(503);
        echo '<div style="font-family:system-ui;padding:40px;text-align:center"><h1>Aimee is briefly unavailable</h1><p>Please try again shortly.</p></div>';
        return;
    }

    ob_start();
    include $source;
    $html = ob_get_clean();
    echo aimee_global_transform_legacy_chat_html($html, $market); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
