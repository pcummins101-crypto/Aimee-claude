#!/usr/bin/env node
/** Runtime regression for the 1.8.7 optional, settings-only privacy choices. */

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const tests = path.dirname(fileURLToPath(import.meta.url));
const legacySource = fs.readFileSync(path.join(tests, '..', 'includes', 'legacy-ui.php'), 'utf8');
const fallbackSource = fs.readFileSync(path.join(tests, '..', 'templates', 'shared', 'chat-fallback.php'), 'utf8');
const scriptMatch = legacySource.match(/<script id="aimee-privacy-consent-ui">([\s\S]*?)<\/script>/);

if (!scriptMatch) {
  console.error('FAIL: legacy privacy-choice script was not found');
  process.exit(1);
}

const script = scriptMatch[1];
const failures = [];
let checks = 0;

function assert(condition, label) {
  checks += 1;
  if (!condition) failures.push(label);
}

class ClassList {
  constructor() { this.values = new Set(); }
  add(value) { this.values.add(value); }
  contains(value) { return this.values.has(value); }
}

class Control {
  constructor(kind) {
    this.kind = kind;
    this.checked = false;
    this.disabled = false;
    this.textContent = '';
    this.listeners = new Map();
    this.focused = false;
  }
  addEventListener(name, callback) { this.listeners.set(name, callback); }
  click() {
    const callback = this.listeners.get('click');
    if (callback) callback({ target: this });
  }
  focus() { this.focused = true; }
}

class Panel {
  constructor(document) {
    this.ownerDocument = document;
    this.id = '';
    this.parentNode = null;
    this.attributes = new Map();
    this.classList = new ClassList();
    this.controls = {
      anchor: new Control('anchor'),
      special: new Control('special'),
      save: new Control('save'),
      status: new Control('status'),
    };
  }
  set innerHTML(value) { this.html = String(value); }
  setAttribute(name, value) { this.attributes.set(name, String(value)); }
  getAttribute(name) { return this.attributes.get(name) || null; }
  querySelector(selector) {
    if (selector === 'a') return this.controls.anchor;
    if (selector === '[data-aimee-special-consent]') return this.controls.special;
    if (selector === '[data-aimee-consent-save]') return this.controls.save;
    if (selector === '#aimee-privacy-consent-status') return this.controls.status;
    return null;
  }
  remove() {
    if (!this.parentNode) return;
    this.parentNode.children = this.parentNode.children.filter(child => child !== this);
    this.parentNode = null;
  }
}

class Host {
  constructor() { this.children = []; }
  appendChild(child) { child.parentNode = this; this.children.push(child); return child; }
  closest(selector) { return /onboard/i.test(String(selector)) ? null : this; }
}

function makeFixture(initialResponse, { withSettings = false, postResponse = null } = {}) {
  const body = new Host();
  const settings = withSettings ? new Host() : null;
  const requests = [];
  const documentListeners = new Map();

  const document = {
    readyState: 'complete',
    body,
    documentElement: {},
    createElement(tag) {
      if (tag !== 'section') throw new Error(`Unexpected element: ${tag}`);
      return new Panel(document);
    },
    getElementById(id) {
      const all = [...body.children, ...(settings ? settings.children : [])];
      const panel = all.find(child => child.id === 'aimee-privacy-consent-panel') || null;
      if (id === 'aimee-privacy-consent-panel') return panel;
      if (id === 'aimee-privacy-consent-status') return panel ? panel.controls.status : null;
      return null;
    },
    querySelector(selector) {
      if (settings && selector.includes('#edit-profile-screen form')) return settings;
      return null;
    },
    addEventListener(name, callback) { documentListeners.set(name, callback); },
  };

  class MutationObserver {
    constructor(callback) { this.callback = callback; }
    observe() {}
  }

  const responses = [initialResponse];
  if (postResponse) responses.push(postResponse);
  const window = {
    AIMEE_PRIVACY_CONFIG: {
      apiBase: 'https://example.test/wp-json/aimee/v1',
      nonce: 'nonce',
      privacyUrl: 'https://example.test/privacy/',
    },
    fetch: async (url, init = {}) => {
      requests.push({ url: String(url), init: { ...init } });
      const data = responses.shift();
      return { ok: true, async json() { return data; } };
    },
  };

  const context = vm.createContext({
    window,
    document,
    fetch: window.fetch,
    MutationObserver,
    URL,
    Error,
    Boolean,
    JSON,
    Promise,
    setTimeout(callback) { callback(); return 1; },
  });
  vm.runInContext(script, context, { filename: 'aimee-privacy-consent-ui.js' });
  return { body, settings, document, requests };
}

async function settle() {
  for (let index = 0; index < 8; index += 1) await Promise.resolve();
}

const ordinaryChat = makeFixture({
  privacy_acknowledged: false,
  special_category_consent: false,
});
await settle();
assert(ordinaryChat.requests.length === 1 && ordinaryChat.requests[0].init.method === 'GET', 'reload reads optional privacy-choice state');
assert(ordinaryChat.body.children.length === 0, 'an unacknowledged profile never receives a floating privacy gate');
assert(ordinaryChat.document.getElementById('aimee-privacy-consent-panel') === null, 'ordinary chat stays available without mounting privacy settings into the page body');

const settingsSave = makeFixture(
  { privacy_acknowledged: false, special_category_consent: true },
  {
    withSettings: true,
    postResponse: { status: 'saved', privacy_acknowledged: false, special_category_consent: false },
  },
);
await settle();
const settingsPanel = settingsSave.document.getElementById('aimee-privacy-consent-panel');
assert(settingsPanel && settingsSave.settings.children.includes(settingsPanel), 'privacy choices are exposed inside settings even without a historical acknowledgement');
assert(!settingsPanel.classList.contains('aimee-privacy-floating'), 'the settings panel is never converted into a floating gate');
assert(settingsPanel.controls.anchor.href === 'https://example.test/privacy/', 'settings keeps the privacy notice visibly linked');
settingsPanel.controls.special.checked = false;
settingsPanel.controls.save.click();
await settle();
const withdrawn = JSON.parse(settingsSave.requests[1].init.body);
assert(!Object.hasOwn(withdrawn, 'privacy_acknowledged'), 'settings does not request or submit a privacy acknowledgement');
assert(withdrawn.special_category_consent === false, 'settings can withdraw specialist consent explicitly');
assert(settingsSave.document.getElementById('aimee-privacy-consent-panel') === settingsPanel, 'settings withdrawal remains a non-blocking settings control');
assert(settingsPanel.controls.status.textContent === 'Saved', 'settings confirms a successful withdrawal');

assert(!/name="privacy_acknowledged"/.test(fallbackSource), 'fallback has no privacy-acknowledgement checkbox');
assert(!/name="special_category_consent"[^>]*\brequired\b/.test(fallbackSource), 'fallback onboarding leaves specialist consent optional');
assert(/<form\b(?=[^>]*\bid="join")(?=[^>]*\bdata-aimee-native-privacy-choices="1")[^>]*>/.test(fallbackSource), 'fallback marks its native privacy choice so bridges can avoid duplication');
assert(legacySource.includes('"special_category_consent"') && legacySource.includes('"Optional sensitive-information consent"'), 'legacy onboarding leaves specialist consent optional');
assert(legacySource.includes('.aimee-required-consents,input[name="special_category_consent"]'), 'legacy bridge skips a form that already contains the optional consent input');
assert(legacySource.includes('Read the privacy notice') && fallbackSource.toLowerCase().includes('privacy notice'), 'both chat UIs keep the privacy notice visible');
assert(!legacySource.includes('aimee-privacy-floating') && !legacySource.includes('mountRequiredPanel'), 'legacy UI has no floating acknowledgement gate');
assert(!fallbackSource.includes('privacyGateRequired') && !fallbackSource.includes('privacyResult.privacy_acknowledged'), 'fallback settings have no acknowledgement gate or verification requirement');

if (failures.length) {
  console.error('Privacy-choice dismissal regression failures:\n- ' + failures.join('\n- '));
  process.exit(1);
}

console.log(`PASS: ${checks} privacy-choice dismissal assertions.`);
