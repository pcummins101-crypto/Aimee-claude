import fs from "node:fs";
import path from "node:path";
import process from "node:process";
import vm from "node:vm";
import { fileURLToPath } from "node:url";

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.dirname(here);
const source = fs.readFileSync(path.join(root, "includes", "legacy-ui.php"), "utf8");

let passed = 0;
let failed = 0;
function check(condition, label) {
    if (condition) {
        passed += 1;
        console.log(`PASS ${label}`);
        return;
    }
    failed += 1;
    console.error(`FAIL ${label}`);
}

function functionSource(name) {
    const start = source.indexOf(`    function ${name}(`);
    if (start < 0) throw new Error(`Missing JavaScript function: ${name}`);
    const next = source.indexOf("\n    function ", start + 5);
    return source.slice(start, next < 0 ? source.length : next);
}

const scripts = [...source.matchAll(/<script[^>]*>([\s\S]*?)<\/script>/g)].map((match) => match[1]);
const billingMatch = source.match(/<script id="aimee-billing-migration-ui">([\s\S]*?)<\/script>/);
if (!billingMatch) throw new Error("Billing migration script was not found");
const billingScript = billingMatch[1];

for (const script of scripts) {
    try {
        new Function(script);
        check(true, "inline legacy UI script parses as JavaScript");
    } catch (error) {
        check(false, `inline legacy UI script parses as JavaScript (${error.message})`);
    }
}

function neutralizeRenderedPhp(script, { loggedIn = false } = {}) {
    return script
        .replace(/<\?php\s+echo\s+\$is_logged_in\s*\?\s*'true'\s*:\s*'false';\s*\?>/g, loggedIn ? "true" : "false")
        .replace(/<\?php\s+echo\s+\$checkout_market_supported\s*\?\s*'true'\s*:\s*'false';\s*\?>/g, "true")
        .replace(/<\?php\s+echo\s+wp_json_encode\(\$app_url\);\s*\?>/g, '"https://example.test/chat/"')
        .replace(/<\?php\s+echo\s+wp_json_encode\(\$rest_nonce\);\s*\?>/g, '"test-nonce"')
        .replace(/<\?php\s+echo\s+esc_js\(\$aimee_market\);\s*\?>/g, "uk")
        .replace(/<\?php\s+echo\s+esc_js\(\$nonce\);\s*\?>/g, "test-nonce")
        .replace(/<\?php\s+echo\s+esc_js\(rest_url\('[^']+'\)\);\s*\?>/g, "https://example.test/wp-json/aimee/v1")
        .replace(/<\?php\s+echo\s+\(int\)\s*\$uid;\s*\?>/g, "42");
}

for (const relativePath of [
    "templates/pricing-uk.php",
    "templates/pricing-us.php",
    "templates/shared/pricing.php",
    "templates/shared/chat-fallback.php",
]) {
    const template = fs.readFileSync(path.join(root, relativePath), "utf8");
    const executableScripts = [...template.matchAll(/<script([^>]*)>([\s\S]*?)<\/script>/g)]
        .filter((match) => !/application\/ld\+json/i.test(match[1]))
        .map((match) => neutralizeRenderedPhp(match[2]));
    for (const script of executableScripts) {
        try {
            new Function(script);
            check(true, `${relativePath} rendered inline script parses as JavaScript`);
        } catch (error) {
            check(false, `${relativePath} rendered inline script parses as JavaScript (${error.message})`);
        }
    }
}

const graceCopy = functionSource("graceCopy");
const graceTiming = functionSource("graceTimingCopy");
check(
    graceCopy.includes("through <strong>31 August 2026</strong>")
        && graceCopy.includes("00:00 on 1 September 2026 (UK time)")
        && !graceCopy.includes("formatDate")
        && !graceCopy.includes("service_grace_until"),
    "grace copy uses one fixed UK cutoff in every browser timezone",
);
check(
    graceTiming.includes("00:00 on 1 September 2026 (UK time)")
        && graceTiming.includes("need to create a new subscription"),
    "September action copy names the exact opt-in boundary",
);
check(
    functionSource("refreshStatus").includes("scheduleStatusRetry")
        && functionSource("scheduleStatusRetry").includes("60000")
        && billingScript.includes('document.addEventListener("visibilitychange"')
        && billingScript.includes('window.addEventListener("online"')
        && billingScript.includes('window.addEventListener("pageshow"'),
    "status refresh has bounded retry, visibility, online and page-show recovery",
);
check(
    functionSource("observeDelayedChatMount").includes("MutationObserver")
        && functionSource("observeDelayedChatMount").includes("mountChatCard"),
    "delayed chat composers are observed and mounted",
);
check(
    functionSource("apply").includes("payment_scheduled")
        && functionSource("apply").includes("state.reconciliation")
        && functionSource("reconciliationCopy").includes("cannot describe August as complimentary")
        && functionSource("reconciliationTimingCopy").includes("Do not create another subscription")
        && functionSource("mountChatCard").includes("!state.reconciliation && sessionValue"),
    "scheduled-payment conflicts enter a fail-safe reconciliation mode",
);

class FakeClassList {
    constructor() {
        this.values = new Set();
    }
    add(name) {
        this.values.add(name);
    }
    remove(name) {
        this.values.delete(name);
    }
    toggle(name, force) {
        if (force === undefined) force = !this.values.has(name);
        if (force) this.values.add(name);
        else this.values.delete(name);
        return Boolean(force);
    }
    contains(name) {
        return this.values.has(name);
    }
}

class FakeStorage {
    constructor() {
        this.values = new Map();
    }
    getItem(key) {
        return this.values.has(key) ? this.values.get(key) : null;
    }
    setItem(key, value) {
        this.values.set(key, String(value));
    }
}

class FakeHeaders {
    constructor() {
        this.values = new Map();
    }
    set(key, value) {
        this.values.set(String(key).toLowerCase(), String(value));
    }
    has(key) {
        return this.values.has(String(key).toLowerCase());
    }
}

class FakeElement {
    constructor(tagName, document) {
        this.tagName = String(tagName || "div").toUpperCase();
        this.nodeType = 1;
        this.document = document;
        this._id = "";
        this._connected = false;
        this.parentElement = null;
        this.children = [];
        this.dataset = {};
        this.classList = new FakeClassList();
        this.className = "";
        this.innerHTML = "";
        this.textContent = "";
        this.attributes = new Map();
        this.listeners = new Map();
        this.style = {};
    }
    set id(value) {
        this._id = String(value || "");
        if (this._connected && this._id) this.document.elements.set(this._id, this);
    }
    get id() {
        return this._id;
    }
    setAttribute(name, value) {
        this.attributes.set(name, String(value));
    }
    getAttribute(name) {
        return this.attributes.has(name) ? this.attributes.get(name) : null;
    }
    removeAttribute(name) {
        this.attributes.delete(name);
    }
    addEventListener(name, callback) {
        if (!this.listeners.has(name)) this.listeners.set(name, []);
        this.listeners.get(name).push(callback);
    }
    async emit(name, event) {
        for (const callback of this.listeners.get(name) || []) await callback(event);
    }
    appendChild(child) {
        child.parentElement = this;
        this.children.push(child);
        if (this._connected) this.document.connect(child);
        return child;
    }
    querySelector(selector) {
        return this.querySelectorAll(selector)[0] || null;
    }
    querySelectorAll(selector) {
        const matches = [];
        const visit = (node) => {
            for (const child of node.children) {
                if (child.matches(selector)) matches.push(child);
                visit(child);
            }
        };
        visit(this);
        return matches;
    }
    closest(selector) {
        let node = this;
        while (node) {
            if (node.matches(selector)) return node;
            node = node.parentElement;
        }
        return null;
    }
    matches(selector) {
        return String(selector || "").split(",").some((raw) => {
            const part = raw.trim();
            if (!part) return false;
            if (part === "button" || part === "a") return this.tagName === part.toUpperCase();
            if (part === "button,a") return ["BUTTON", "A"].includes(this.tagName);
            if (/^[a-z][a-z0-9-]*$/i.test(part)) return this.tagName === part.toUpperCase();
            if (part.startsWith("#")) return this.id === part.slice(1);
            if (part === "[data-plan]") return Object.prototype.hasOwnProperty.call(this.dataset, "plan");
            const dataAttribute = part.match(/^\[data-([a-z0-9-]+)(?:="([^"]*)")?\]$/i);
            if (dataAttribute) {
                const key = dataAttribute[1].replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
                if (!Object.prototype.hasOwnProperty.call(this.dataset, key)) return false;
                return dataAttribute[2] === undefined || String(this.dataset[key]) === dataAttribute[2];
            }
            const billingAction = part.match(/^\[data-billing-action=(cancel|portal)\]$/);
            if (billingAction) return this.dataset.billingAction === billingAction[1];
            if (part.startsWith(".")) {
                const names = part.slice(1).split(".");
                const classNames = new Set([
                    ...String(this.className || "").split(/\s+/).filter(Boolean),
                    ...this.classList.values,
                ]);
                return names.every((name) => classNames.has(name));
            }
            return false;
        });
    }
    insertAdjacentElement(position, element) {
        void position;
        this.document.connect(element);
        return element;
    }
    remove() {
        this.document.disconnect(this);
    }
}

class FakeDocument {
    constructor() {
        this.elements = new Map();
        this.allElements = new Set();
        this.listeners = new Map();
        this.readyState = "complete";
        this.visibilityState = "visible";
        this.body = new FakeElement("body", this);
        this.connect(this.body);
    }
    connect(element) {
        element._connected = true;
        this.allElements.add(element);
        if (element.id) this.elements.set(element.id, element);
        for (const child of element.children) this.connect(child);
        return element;
    }
    disconnect(element) {
        if (element.id && this.elements.get(element.id) === element) this.elements.delete(element.id);
        element._connected = false;
        this.allElements.delete(element);
        for (const child of element.children) this.disconnect(child);
    }
    getElementById(id) {
        return this.elements.get(id) || null;
    }
    querySelector(selector) {
        return this.querySelectorAll(selector)[0] || null;
    }
    querySelectorAll(selector) {
        return [...this.allElements].filter((element) => element.matches(selector));
    }
    createElement(tagName) {
        return new FakeElement(tagName, this);
    }
    addEventListener(name, callback) {
        if (!this.listeners.has(name)) this.listeners.set(name, []);
        this.listeners.get(name).push(callback);
    }
    emit(name) {
        for (const callback of this.listeners.get(name) || []) callback();
    }
    emitClick(target) {
        const event = {
            target,
            defaultPrevented: false,
            propagationStopped: false,
            immediatePropagationStopped: false,
            preventDefault() { this.defaultPrevented = true; },
            stopPropagation() { this.propagationStopped = true; },
            stopImmediatePropagation() { this.immediatePropagationStopped = true; },
        };
        for (const callback of this.listeners.get("click") || []) callback(event);
        return event;
    }
}

class FixedDate extends Date {
    static now() {
        return Date.parse("2026-08-03T12:00:00Z");
    }
}

function graceSnapshot(overrides = {}) {
    return {
        service_grace_active: true,
        service_grace_until: "2026-08-31T23:00:00+00:00",
        service_grace_code: "august_2026_processor_recovery",
        payment_scheduled: false,
        next_payment_at: null,
        billing_current_period_end: null,
        new_subscription_required: false,
        requires_reactivation: true,
        can_manage_billing: false,
        legacy_access_active: false,
        ...overrides,
    };
}

function goodwillSnapshot(overrides = {}) {
    return {
        status: "active",
        access_active: true,
        access_source: "goodwill_extension",
        access_level: "full_in_app",
        access_until: "2026-10-03T00:00:00+00:00",
        bonus_access_until: "2026-10-03T00:00:00+00:00",
        service_grace_active: false,
        service_grace_until: "2026-08-31T23:00:00+00:00",
        payment_scheduled: false,
        new_subscription_required: true,
        requires_reactivation: true,
        can_manage_billing: false,
        checkout_available: false,
        checkout_opens_at: "2026-10-03T00:00:00+00:00",
        ...overrides,
    };
}

function successResponse(subscription) {
    return {
        ok: true,
        json: () => Promise.resolve({ subscription }),
    };
}

function createHarness(fetchQueue, {
    composer = true,
    feedback = false,
    market = "uk",
    membershipUi = false,
    planInitiallyDisabled = false,
} = {}) {
    const document = new FakeDocument();
    if (composer) {
        const input = document.createElement("div");
        input.id = "message-composer";
        document.connect(input);
    }
    if (feedback) {
        const release = document.createElement("aside");
        release.id = "aimee-release-feedback-chat";
        document.connect(release);
    }
    if (membershipUi) {
        const header = document.createElement("button");
        header.id = "membership-status-display";
        header.innerHTML = "Manage membership";
        document.connect(header);

        const statusCard = document.createElement("section");
        statusCard.id = "test-membership-card";
        statusCard.className = "membership-status-card";
        const label = document.createElement("strong");
        label.id = "settings-membership-label";
        label.textContent = "No active membership";
        const detail = document.createElement("p");
        detail.id = "settings-membership-detail";
        detail.innerHTML = "Choose a membership when you are ready.";
        const open = document.createElement("button");
        open.id = "test-open-membership";
        open.className = "open-membership-btn";
        open.textContent = "View memberships";
        statusCard.appendChild(label);
        statusCard.appendChild(detail);
        statusCard.appendChild(open);
        document.connect(statusCard);

        const plan = document.createElement("button");
        plan.id = "test-plan-button";
        plan.className = "membership-checkout-btn";
        plan.dataset.plan = "monthly";
        plan.textContent = "Choose Monthly";
        plan.disabled = planInitiallyDisabled;
        document.connect(plan);

        const manage = document.createElement("button");
        manage.id = "manage-membership-btn";
        manage.textContent = "Manage billing";
        manage.style.display = "inline-flex";
        document.connect(manage);
    }

    const timers = new Map();
    let timerId = 0;
    const observers = [];
    const windowListeners = new Map();
    const warnings = [];
    const alerts = [];
    const portalCalls = [];
    let fetchCalls = 0;

    class FakeMutationObserver {
        constructor(callback) {
            this.callback = callback;
            observers.push(this);
        }
        observe() {}
        disconnect() {}
    }

    function setTimer(callback, delay) {
        timerId += 1;
        timers.set(timerId, { callback, delay: Number(delay), cleared: false });
        return timerId;
    }
    function clearTimer(id) {
        if (timers.has(id)) timers.get(id).cleared = true;
    }
    function addWindowListener(name, callback) {
        if (!windowListeners.has(name)) windowListeners.set(name, []);
        windowListeners.get(name).push(callback);
    }

    const window = {
        MutationObserver: FakeMutationObserver,
        setTimeout: setTimer,
        clearTimeout: clearTimer,
        addEventListener: addWindowListener,
        alert: (message) => alerts.push(message),
        openBillingPortal: (...items) => portalCalls.push(items),
        location: { assign() {} },
    };
    const fetch = () => {
        fetchCalls += 1;
        const next = fetchQueue.shift();
        if (next instanceof Error) return Promise.reject(next);
        if (!next) return Promise.reject(new Error("Unexpected fetch"));
        return Promise.resolve(successResponse(next));
    };
    const context = {
        window,
        document,
        MutationObserver: FakeMutationObserver,
        Headers: FakeHeaders,
        fetch,
        sessionStorage: new FakeStorage(),
        Date: FixedDate,
        console: { warn: (...items) => warnings.push(items) },
    };
    const config = {
        apiBase: "https://example.test/wp-json/aimee/v1",
        nonce: "test-nonce",
        market,
        checkoutMarketSupported: market === "uk",
        pricingUrl: market === "us"
            ? "https://example.test/pricing-us/"
            : "https://example.test/pricing/",
    };
    const executable = billingScript.replace("__AIMEE_BILLING_CONFIG__", JSON.stringify(config));
    vm.runInNewContext(executable, context, { filename: "aimee-billing-migration-ui.js" });

    return {
        document,
        window,
        observers,
        timers,
        warnings,
        alerts,
        portalCalls,
        fetchCalls: () => fetchCalls,
        connectComposer() {
            const input = document.createElement("div");
            input.id = "message-composer";
            document.connect(input);
            for (const observer of observers) observer.callback([], observer);
        },
        connectMembershipHeader() {
            const header = document.createElement("button");
            header.id = "membership-status-display";
            header.innerHTML = "Manage membership";
            document.connect(header);
            for (const observer of observers) {
                observer.callback([{ addedNodes: [header] }], observer);
            }
            return header;
        },
        connectMembershipModal() {
            const modal = document.createElement("section");
            modal.id = "test-membership-modal";
            const title = document.createElement("h2");
            title.id = "membership-title";
            title.textContent = "Create your new Aimee membership";
            const copy = document.createElement("p");
            copy.id = "membership-modal-copy";
            copy.textContent = "Your complimentary August access has ended.";
            modal.appendChild(title);
            modal.appendChild(copy);
            document.connect(modal);
            for (const observer of observers) {
                observer.callback([{ addedNodes: [modal] }], observer);
            }
            return { modal, title, copy };
        },
        runTimerWithDelay(delay) {
            for (const [id, timer] of timers) {
                if (!timer.cleared && timer.delay === delay) {
                    timer.cleared = true;
                    timer.callback();
                    return id;
                }
            }
            return 0;
        },
        emitWindow(name) {
            for (const callback of windowListeners.get(name) || []) callback();
        },
    };
}

async function createPricingHarness(relativePath, subscription) {
    const document = new FakeDocument();
    const connect = (tag, { id = "", className = "", dataset = {} } = {}) => {
        const element = document.createElement(tag);
        element.id = id;
        element.className = className;
        Object.assign(element.dataset, dataset);
        document.connect(element);
        return element;
    };

    const tierGrid = connect("div", { className: "tier-grid" });
    const freeCard = connect("section", { dataset: { planCard: "free" } });
    const freeButton = connect("a", { className: "free-preview-action" });
    freeButton.textContent = "Start Free Preview";
    freeButton.setAttribute("href", "#membership-options");
    const planCards = {};
    const planButtons = {};
    for (const plan of ["weekly", "monthly", "annual"]) {
        planCards[plan] = connect("section", { dataset: { planCard: plan } });
        planButtons[plan] = connect("a", { className: "membership-action", dataset: { plan } });
        planButtons[plan].textContent = `Choose ${plan}`;
    }
    connect("button", { id: "hamburger-menu" });
    connect("div", { id: "mobile-menu" });
    connect("div", { id: "sticky-cta" });
    connect("section", { id: "hero" });
    connect("nav");

    const requests = [];
    const warnings = [];
    const alerts = [];
    const windowListeners = new Map();
    let timerId = 0;
    const window = {
        location: { href: "" },
        scrollY: 0,
        setTimeout() { timerId += 1; return timerId; },
        clearTimeout() {},
        addEventListener(name, callback) {
            if (!windowListeners.has(name)) windowListeners.set(name, []);
            windowListeners.get(name).push(callback);
        },
    };
    const fetch = (url, options = {}) => {
        requests.push({ url: String(url), options });
        if (String(url).includes("/subscription-status")) {
            return Promise.resolve({ ok: true, json: () => Promise.resolve({ subscription }) });
        }
        if (String(url).includes("/billing-portal")) {
            return Promise.resolve({ ok: true, json: () => Promise.resolve({ portal_url: "https://example.test/billing" }) });
        }
        return Promise.resolve({ ok: false, json: () => Promise.resolve({ message: "Unexpected request" }) });
    };
    class FakeIntersectionObserver {
        observe() {}
        unobserve() {}
    }

    const template = fs.readFileSync(path.join(root, relativePath), "utf8");
    const scriptMatch = [...template.matchAll(/<script([^>]*)>([\s\S]*?)<\/script>/g)]
        .find((match) => !/application\/ld\+json/i.test(match[1]) && match[2].includes("updatePlanButtons"));
    if (!scriptMatch) throw new Error(`Pricing script not found: ${relativePath}`);
    const context = {
        window,
        document,
        Headers: FakeHeaders,
        fetch,
        alert: (message) => alerts.push(message),
        console: { warn: (...items) => warnings.push(items) },
        IntersectionObserver: FakeIntersectionObserver,
        Date: FixedDate,
    };
    vm.runInNewContext(neutralizeRenderedPhp(scriptMatch[2], { loggedIn: true }), context, { filename: relativePath });
    document.emit("DOMContentLoaded");
    await settle();
    return { document, window, tierGrid, freeCard, freeButton, planCards, planButtons, requests, warnings, alerts };
}

async function settle() {
    await Promise.resolve();
    await Promise.resolve();
    await new Promise((resolve) => setImmediate(resolve));
}

const retryHarness = createHarness(
    [new Error("temporary status outage"), graceSnapshot()],
    { composer: true, feedback: true },
);
await settle();
check(
    retryHarness.document.getElementById("aimee-service-grace-card") === null
        && retryHarness.runTimerWithDelay(1000) !== 0,
    "a transient first status failure schedules the first bounded retry",
);
await settle();
check(
    retryHarness.fetchCalls() === 2
        && retryHarness.document.getElementById("aimee-service-grace-card") !== null,
    "successful retry mounts the complimentary chat card",
);
check(
    retryHarness.document.getElementById("aimee-release-feedback-chat") !== null,
    "complimentary grace preserves the Aimee 1.7.1 feedback banner",
);

const usHarness = createHarness([graceSnapshot()], { composer: true, market: "us" });
await settle();
const usGraceCard = usHarness.document.getElementById("aimee-service-grace-card");
check(
    usGraceCard !== null
        && usGraceCard.innerHTML.includes("00:00 on 1 September 2026 (UK time)"),
    "US chat renders the same unambiguous UK service cutoff",
);

const delayedHarness = createHarness([graceSnapshot()], { composer: false });
await settle();
check(
    delayedHarness.document.getElementById("aimee-service-grace-card") === null,
    "notice waits safely when the chat composer is not mounted yet",
);
delayedHarness.connectComposer();
check(
    delayedHarness.document.getElementById("aimee-service-grace-card") !== null,
    "mutation observer mounts the notice when a delayed composer appears",
);

const reconciliationHarness = createHarness(
    [graceSnapshot({
        payment_scheduled: true,
        next_payment_at: "2026-08-15T12:00:00+00:00",
        can_manage_billing: true,
        requires_reactivation: false,
    })],
    { composer: true, feedback: true },
);
await settle();
const reconciliationCard = reconciliationHarness.document.getElementById("aimee-billing-reconciliation-card");
check(
    reconciliationCard !== null
        && reconciliationHarness.document.getElementById("aimee-service-grace-card") === null
        && reconciliationCard.innerHTML.includes("cannot describe August as complimentary"),
    "scheduled payment produces a reconciliation warning instead of a free-August claim",
);
check(
    reconciliationHarness.document.getElementById("aimee-release-feedback-chat") === null,
    "urgent billing reconciliation suppresses competing feedback without changing grace coexistence",
);

const goodwillHarness = createHarness(
    [goodwillSnapshot()],
    { composer: true, feedback: true },
);
await settle();
check(
    goodwillHarness.window.__aimeeBillingMigration.goodwill === true
        && goodwillHarness.window.__aimeeBillingMigration.required === false
        && goodwillHarness.document.getElementById("aimee-billing-migration-card") === null,
    "active goodwill overrides future reactivation flags and suppresses the expired-August chat card",
);
check(
    goodwillHarness.document.body.classList.contains("aimee-goodwill-access-active")
        && !goodwillHarness.document.body.classList.contains("aimee-billing-reactivation-required")
        && goodwillHarness.document.getElementById("aimee-release-feedback-chat") !== null,
    "active goodwill exposes its own UI state without suppressing unrelated chat content",
);
check(
    [...goodwillHarness.timers.values()].some(timer => !timer.cleared && timer.delay === 6 * 60 * 60 * 1000)
        && functionSource("scheduleBoundaryRefresh").includes("bonus_access_until")
        && functionSource("scheduleBoundaryRefresh").includes("access_until"),
    "goodwill schedules bounded status refreshes against its access expiry",
);

const goodwillManagedHarness = createHarness(
    [goodwillSnapshot({ can_manage_billing: true })],
    { composer: true, membershipUi: true },
);
await settle();
const goodwillManageButton = goodwillManagedHarness.document.getElementById("manage-membership-btn");
const goodwillPlanButton = goodwillManagedHarness.document.getElementById("test-plan-button");
const goodwillManageHeader = goodwillManagedHarness.document.getElementById("membership-status-display");
const goodwillOpenMembership = goodwillManagedHarness.document.getElementById("test-open-membership");
const manageClick = goodwillManagedHarness.document.emitClick(goodwillManageButton);
const planClick = goodwillManagedHarness.document.emitClick(goodwillPlanButton);
goodwillManagedHarness.window.openBillingPortal("settings", goodwillManageButton);
check(
    manageClick.defaultPrevented === false
        && goodwillManagedHarness.portalCalls.length === 1
        && goodwillManagedHarness.alerts.length === 1
        && goodwillManageHeader.innerHTML.includes("Temporary access · Manage billing")
        && goodwillOpenMembership.textContent === "Temporary access · Manage billing"
        && goodwillOpenMembership.getAttribute("aria-label").includes("manage your existing billing record"),
    "goodwill preserves and clearly labels genuine billing management while intercepting plan checkout",
);
check(
    planClick.defaultPrevented === true
        && planClick.immediatePropagationStopped === true
        && goodwillPlanButton.disabled === true
        && goodwillPlanButton.textContent === "Temporary access active",
    "goodwill blocks plan controls before historical checkout handlers can run",
);

for (const pricingPath of ["templates/pricing-uk.php", "templates/shared/pricing.php"]) {
    const pricingHarness = await createPricingHarness(pricingPath, goodwillSnapshot({
        plan: "monthly",
        billing_status: "past_due",
        can_manage_billing: true,
    }));
    const monthly = pricingHarness.planButtons.monthly;
    const weekly = pricingHarness.planButtons.weekly;
    check(
        monthly.textContent === "Current Plan · Manage"
            && monthly.getAttribute("aria-disabled") === null
            && monthly.classList.contains("current-plan")
            && weekly.textContent === "Manage current membership"
            && weekly.getAttribute("aria-disabled") === null,
        `${pricingPath} keeps manageable billing operable and accurately labelled during goodwill`,
    );
    const event = {
        defaultPrevented: false,
        preventDefault() { this.defaultPrevented = true; },
    };
    await monthly.emit("click", event);
    await settle();
    check(
        event.defaultPrevented === true
            && pricingHarness.requests.some((request) => request.url.includes("/billing-portal"))
            && !pricingHarness.requests.some((request) => request.url.includes("/subscription-checkout"))
            && pricingHarness.window.location.href === "https://example.test/billing",
        `${pricingPath} routes a manageable goodwill action to billing settings without checkout`,
    );
}

const delayedGoodwillUiHarness = createHarness([goodwillSnapshot()], { composer: true });
await settle();
const delayedMembershipHeader = delayedGoodwillUiHarness.connectMembershipHeader();
const delayedMembershipModal = delayedGoodwillUiHarness.connectMembershipModal();
check(
    delayedMembershipHeader.innerHTML.includes("Temporary access active")
        && delayedMembershipHeader.dataset.aimeeBillingOriginalHtml === "Manage membership"
        && delayedMembershipModal.title.textContent === "Temporary access active"
        && delayedMembershipModal.copy.textContent.includes("temporary full in-app access is active")
        && !delayedMembershipModal.copy.textContent.includes("complimentary August access has ended"),
    "mutation observer updates header and modal UI mounted after the goodwill status response",
);

const goodwillManagedTransitionHarness = createHarness([
    goodwillSnapshot(),
    {
        status: "active",
        billing_status: "active",
        plan: "monthly",
        access_active: true,
        access_source: "managed_subscription",
        access_level: "full_in_app",
        access_until: "2026-11-03T00:00:00+00:00",
        bonus_access_until: null,
        service_grace_active: false,
        payment_scheduled: false,
        new_subscription_required: false,
        requires_reactivation: false,
        can_manage_billing: true,
        checkout_available: true,
    },
], { composer: true, membershipUi: true });
await settle();
const transitionHeader = goodwillManagedTransitionHarness.document.getElementById("membership-status-display");
const transitionCard = goodwillManagedTransitionHarness.document.getElementById("test-membership-card");
const transitionLabel = goodwillManagedTransitionHarness.document.getElementById("settings-membership-label");
const transitionDetail = goodwillManagedTransitionHarness.document.getElementById("settings-membership-detail");
const transitionOpen = goodwillManagedTransitionHarness.document.getElementById("test-open-membership");
const transitionPlan = goodwillManagedTransitionHarness.document.getElementById("test-plan-button");
const transitionManage = goodwillManagedTransitionHarness.document.getElementById("manage-membership-btn");
goodwillManagedTransitionHarness.document.emit("visibilitychange");
await settle();
check(
    goodwillManagedTransitionHarness.window.__aimeeBillingMigration.goodwill === false
        && transitionHeader.innerHTML.includes("Manage membership")
        && !transitionHeader.innerHTML.includes("Temporary access")
        && transitionLabel.textContent === "Monthly membership active"
        && transitionDetail.innerHTML.includes("managed membership is active")
        && transitionOpen.textContent === "Manage membership",
    "a normal managed status renders authoritative membership copy after goodwill",
);
check(
    !transitionCard.classList.contains("aimee-billing-migration-active")
        && !transitionCard.classList.contains("aimee-goodwill-access-active")
        && goodwillManagedTransitionHarness.document.getElementById("aimee-settings-billing-migration") === null
        && transitionPlan.textContent === "Choose Monthly"
        && transitionPlan.disabled === false
        && transitionPlan.getAttribute("aria-disabled") === null
        && transitionManage.style.display === "inline-flex"
        && transitionManage.getAttribute("aria-hidden") === null,
    "goodwill-to-managed transition restores classes, controls and billing-management visibility",
);

const staleManagedTransitionHarness = createHarness([
    goodwillSnapshot(),
    {
        status: "active",
        billing_status: "active",
        plan: "monthly",
        access_active: false,
        access_source: "none",
        access_level: "none",
        access_until: "2026-08-01T00:00:00+00:00",
        bonus_access_until: null,
        service_grace_active: false,
        payment_scheduled: false,
        new_subscription_required: false,
        requires_reactivation: false,
        can_manage_billing: true,
        checkout_available: false,
    },
], { composer: true, membershipUi: true });
await settle();
staleManagedTransitionHarness.document.emit("visibilitychange");
await settle();
check(
    staleManagedTransitionHarness.document.getElementById("settings-membership-label").textContent === "Membership needs attention"
        && staleManagedTransitionHarness.document.getElementById("settings-membership-detail").innerHTML.includes("billing record needs attention")
        && !staleManagedTransitionHarness.document.getElementById("settings-membership-detail").innerHTML.includes("membership is active"),
    "a manageable billing record is never called active without authoritative managed access",
);

const disabledPlanHarness = createHarness([
    goodwillSnapshot(),
    {
        service_grace_active: false,
        payment_scheduled: false,
        new_subscription_required: false,
        requires_reactivation: false,
        can_manage_billing: false,
    },
], { composer: true, membershipUi: true, planInitiallyDisabled: true });
await settle();
disabledPlanHarness.document.emit("visibilitychange");
await settle();
check(
    disabledPlanHarness.document.getElementById("test-plan-button").disabled === true,
    "goodwill cleanup preserves a plan control that the host application had already disabled",
);

const hostRefreshHarness = createHarness([
    goodwillSnapshot(),
    {
        service_grace_active: false,
        payment_scheduled: false,
        new_subscription_required: false,
        requires_reactivation: false,
        can_manage_billing: false,
    },
], { composer: true, membershipUi: true });
await settle();
const hostOwnedLabel = hostRefreshHarness.document.getElementById("settings-membership-label");
hostOwnedLabel.textContent = "Host application refreshed this membership";
hostRefreshHarness.document.emit("visibilitychange");
await settle();
check(
    hostOwnedLabel.textContent === "Host application refreshed this membership",
    "goodwill cleanup does not overwrite membership text independently refreshed by the host application",
);

const goodwillExpiryHarness = createHarness([
    goodwillSnapshot(),
    goodwillSnapshot({
        status: "subscription_required",
        access_active: false,
        access_source: "none",
        access_level: "none",
        access_until: null,
        bonus_access_until: "2026-08-03T11:59:59+00:00",
        checkout_available: true,
        checkout_opens_at: null,
    }),
]);
await settle();
goodwillExpiryHarness.document.emit("visibilitychange");
await settle();
const postGoodwillCard = goodwillExpiryHarness.document.getElementById("aimee-billing-migration-card");
check(
    goodwillExpiryHarness.window.__aimeeBillingMigration.goodwill === false
        && goodwillExpiryHarness.window.__aimeeBillingMigration.required === true
        && !goodwillExpiryHarness.document.body.classList.contains("aimee-goodwill-access-active")
        && postGoodwillCard !== null
        && postGoodwillCard.innerHTML.includes("Create your new Aimee membership"),
    "a refreshed expired goodwill grant cleans up its state and restores the genuine membership prompt",
);

const staleClassHarness = createHarness([
    graceSnapshot(),
    {
        service_grace_active: false,
        payment_scheduled: false,
        new_subscription_required: false,
        requires_reactivation: false,
    },
]);
await settle();
check(
    staleClassHarness.document.body.classList.contains("aimee-service-grace-active"),
    "active grace sets its body state class",
);
staleClassHarness.document.emit("visibilitychange");
await settle();
check(
    !staleClassHarness.document.body.classList.contains("aimee-service-grace-active")
        && !staleClassHarness.document.body.classList.contains("aimee-billing-reactivation-required")
        && !staleClassHarness.document.body.classList.contains("aimee-billing-reconciliation-required"),
    "visibility refresh clears stale body classes when no notice remains",
);

console.log(`\nChat-notice regression: ${passed} passed, ${failed} failed.`);
if (failed) process.exit(1);
