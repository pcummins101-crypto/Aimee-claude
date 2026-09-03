#!/usr/bin/env node
/** Runtime regression for the theme-supplied chat image/security bridge. */

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const tests = path.dirname(fileURLToPath(import.meta.url));
const source = fs.readFileSync(path.join(tests, '..', 'includes', 'legacy-ui.php'), 'utf8');
const fallbackSource = fs.readFileSync(path.join(tests, '..', 'templates', 'shared', 'chat-fallback.php'), 'utf8');
const scriptMatch = source.match(/<script id="aimee-security-privacy-bridge">([\s\S]*?)<\/script>/);
if (!scriptMatch) {
  console.error('FAIL: security/privacy bridge script was not found');
  process.exit(1);
}

const config = {
  profileEndpoint: 'https://example.test/wp-json/aimee/v1/profile',
  messageEndpoint: 'https://example.test/wp-json/aimee/v1/message',
  privacyUrl: 'https://example.test/privacy/',
};
const script = scriptMatch[1].replace('__AIMEE_SECURITY_CONFIG__', JSON.stringify(config));
const listeners = new Map();
const requests = [];
let uuid = 0;

const fileInput = {
  type: 'file',
  name: 'chat_image',
  id: 'image-input',
  accept: 'image/*',
  files: [{ name: 'same.png' }],
  value: 'C:\\fakepath\\same.png',
  attributes: new Map(),
  closest() { return null; },
  setAttribute(name, value) { this.attributes.set(name, String(value)); },
  removeAttribute(name) { this.attributes.delete(name); },
};
const preview = {
  tagName: 'DIV',
  style: { display: 'block' },
  removeAttribute() {},
  querySelectorAll() { return []; },
};

const document = {
  readyState: 'complete',
  documentElement: {},
  body: {},
  addEventListener(name, callback) { listeners.set(name, callback); },
  getElementById() { return null; },
  querySelector() { return null; },
  querySelectorAll(selector) {
    if (selector === 'input[type="file"]') return [fileInput];
    if (selector.includes('#image-preview')) return [preview];
    return [];
  },
  createElement() { throw new Error('No onboarding form should be created in this fixture.'); },
};

class MutationObserver {
  constructor(callback) { this.callback = callback; }
  observe() {}
}

const window = {
  location: { href: 'https://example.test/chat/' },
  sessionStorage: {
    getItem() { return null; },
    setItem() {},
    removeItem() {},
  },
  Response,
  crypto: {
    randomUUID() { uuid += 1; return `00000000-0000-4000-8000-${String(uuid).padStart(12, '0')}`; },
    subtle: {
      async digest(_algorithm, bytes) {
        let first = 2166136261;
        for (const byte of bytes) first = Math.imul(first ^ byte, 16777619) >>> 0;
        const digest = new Uint8Array(32);
        for (let index = 0; index < digest.length; index++) digest[index] = (first >>> ((index % 4) * 8)) & 255;
        return digest.buffer;
      },
    },
  },
  async fetch(input, init) {
    requests.push({ input: String(input), init: { ...init } });
    return { ok: true, status: 200, async json() { return { status: 'success' }; } };
  },
};

const context = vm.createContext({
  window,
  document,
  MutationObserver,
  URL,
  Response,
  TextEncoder,
  Uint8Array,
  Array,
  String,
  Math,
  Date,
  Promise,
  JSON,
});
vm.runInContext(script, context, { filename: 'aimee-security-privacy-bridge.js' });

const failures = [];
let checks = 0;
function assert(condition, label) {
  checks += 1;
  if (!condition) failures.push(label);
}

const passcodeInput = {
  value: '012346',
  validityMessage: null,
  matches(selector) { return selector === '[data-aimee-passcode]'; },
  setCustomValidity(message) { this.validityMessage = String(message); },
};
const validatePasscodeInput = listeners.get('input');
assert(typeof validatePasscodeInput === 'function', 'bridge registers new-registration passcode validation');
validatePasscodeInput({ target: passcodeInput });
assert(passcodeInput.validityMessage === '' && passcodeInput.value === '012346', 'six ASCII digits with a leading zero remain valid and unchanged');
for (const invalidPasscode of ['12345', '1234567', '12a456', '１２３４５６']) {
  passcodeInput.value = invalidPasscode;
  validatePasscodeInput({ target: passcodeInput });
  assert(passcodeInput.validityMessage !== '', `registration rejects non-six-ASCII-digit value ${invalidPasscode}`);
}

function makePasswordInput({ name, autocomplete = '', value, insideOnboarding = false }) {
  const attributes = new Map([['type', 'password']]);
  return {
    type: 'password',
    name,
    autocomplete,
    value,
    placeholder: '',
    attributes,
    form: null,
    setAttribute(attribute, setting) { attributes.set(String(attribute), String(setting)); },
    removeAttribute(attribute) { attributes.delete(String(attribute)); },
    getAttribute(attribute) { return attributes.get(String(attribute)) ?? null; },
    matches(selector) {
      return selector === '[data-aimee-passcode]' && attributes.has('data-aimee-passcode');
    },
    closest(selector) {
      if (selector === 'form') return this.form;
      if (insideOnboarding && String(selector).includes('#onboarding-screen')) return { id: 'onboarding-screen' };
      return null;
    },
  };
}

function makePasswordForm({ password, registrationMarker = false, completeProfile = false, nativeSpecialChoice = false }) {
  const fields = new Map();
  if (completeProfile) {
    fields.set('first_name', { name: 'first_name' });
    fields.set('age', { name: 'age' });
    fields.set('phone_number', { name: 'phone_number' });
  }
  const specialChoice = nativeSpecialChoice ? { name: 'special_category_consent', checked: false } : null;
  const form = {
    insertedChoiceGroups: 0,
    getAttribute(name) { return name === 'data-aimee-registration' && registrationMarker ? '1' : null; },
    querySelector(selector) {
      if (selector === '.aimee-required-consents,input[name="special_category_consent"]') return specialChoice;
      if (selector === '.aimee-required-consents') return null;
      if (selector === 'input[name="special_category_consent"]') return specialChoice;
      if (selector === 'input[name="first_name"]') return fields.get('first_name') || null;
      if (selector === 'input[name="age"]') return fields.get('age') || null;
      if (selector === 'input[name="phone_number"]') return fields.get('phone_number') || null;
      if (selector === 'input[name="passcode"],input[autocomplete="new-password"]') {
        return password.name === 'passcode' || password.autocomplete === 'new-password' ? password : null;
      }
      if (selector === 'button[type="submit"],input[type="submit"]') {
        return { parentNode: { insertBefore: () => { form.insertedChoiceGroups += 1; } } };
      }
      return null;
    },
  };
  password.form = form;
  return form;
}

const historicalSignIn = makePasswordInput({
  name: 'legacy_password',
  value: 'correct horse battery staple',
  insideOnboarding: true,
});
const nativeRegistration = makePasswordInput({
  name: 'passcode',
  value: '012346',
  insideOnboarding: true,
});
const historicalSignInForm = makePasswordForm({ password: historicalSignIn });
const nativeRegistrationForm = makePasswordForm({
  password: nativeRegistration,
  registrationMarker: true,
  completeProfile: true,
  nativeSpecialChoice: true,
});
const passwordFixtureListeners = new Map();
let passwordFixtureCreatedElements = 0;
const passwordFixtureDocument = {
  readyState: 'complete',
  documentElement: {},
  body: {},
  addEventListener(name, callback) { passwordFixtureListeners.set(name, callback); },
  getElementById() { return null; },
  querySelector() { return null; },
  querySelectorAll(selector) {
    if (selector === 'input[type="password"]') return [historicalSignIn, nativeRegistration];
    if (selector === 'form') return [historicalSignInForm, nativeRegistrationForm];
    return [];
  },
  createElement(tag) {
    passwordFixtureCreatedElements += 1;
    return {
      tagName: String(tag).toUpperCase(),
      children: [],
      appendChild(child) { this.children.push(child); return child; },
      setAttribute() {},
    };
  },
  createTextNode(text) { return { textContent: String(text) }; },
};
const passwordFixtureWindow = {
  location: { href: 'https://example.test/chat/' },
  sessionStorage: { getItem() { return null; }, removeItem() {} },
  fetch: async () => ({ ok: true, status: 200, async json() { return {}; } }),
  Response,
  crypto: { randomUUID() { return '00000000-0000-4000-8000-000000000099'; } },
};
const passwordFixtureContext = vm.createContext({
  window: passwordFixtureWindow,
  document: passwordFixtureDocument,
  MutationObserver,
  URL,
  Response,
  TextEncoder,
  Uint8Array,
  Array,
  String,
  Math,
  Date,
  Promise,
  JSON,
  Event,
});
vm.runInContext(script, passwordFixtureContext, { filename: 'aimee-password-form-classification.js' });

assert(Boolean(historicalSignIn.closest('#onboarding-screen,[data-onboarding]')), 'fixture places the historical sign-in inside a broad onboarding container');
for (const attribute of ['pattern', 'inputmode', 'minlength', 'maxlength', 'data-aimee-passcode']) {
  assert(!historicalSignIn.attributes.has(attribute), `unannotated historical sign-in remains free of ${attribute}`);
}
assert(historicalSignIn.autocomplete === '' && historicalSignIn.value === 'correct horse battery staple', 'historical sign-in credential stays opaque and unchanged');
for (const [attribute, expected] of Object.entries({
  pattern: '[0-9]{6}',
  inputmode: 'numeric',
  minlength: '6',
  maxlength: '6',
  'data-aimee-passcode': '1',
})) {
  assert(nativeRegistration.attributes.get(attribute) === expected, `real registration receives ${attribute}=${expected}`);
}
assert(nativeRegistration.autocomplete === 'new-password' && nativeRegistration.value === '012346', 'real registration is hardened without changing its leading-zero passcode');
assert(nativeRegistrationForm.insertedChoiceGroups === 0 && passwordFixtureCreatedElements === 0, 'native optional consent prevents a duplicate legacy privacy-choice group');

const payload = 'data:image/png;base64,c2FtZS1pbWFnZS1ieXRlcw==';
const change = listeners.get('change');
assert(typeof change === 'function', 'bridge registers a file-selection change listener');

change({ target: fileInput });
await window.fetch(config.messageEndpoint, {
  method: 'POST',
  body: JSON.stringify({ message: 'First look', image: payload }),
});
const first = JSON.parse(requests.at(-1).init.body);
assert(typeof first.image_event_id === 'string' && first.image_event_id !== '', 'fresh selection receives an image event ID');
assert(fileInput.value === '', 'composer file input is cleared immediately after transport');

fileInput.value = 'C:\\fakepath\\same.png';
fileInput.files = [{ name: 'same.png' }];
change({ target: fileInput });
await window.fetch(config.messageEndpoint, {
  method: 'POST',
  body: JSON.stringify({ message: 'Look again', image: payload }),
});
const repeat = JSON.parse(requests.at(-1).init.body);
assert(repeat.image_event_id !== first.image_event_id, 'same-file reselection receives a distinct event ID');
assert(repeat.image === payload, 'intentional same-file reselection keeps current bytes');

const beforeStaleText = requests.length;
await window.fetch(config.messageEndpoint, {
  method: 'POST',
  body: JSON.stringify({ message: 'Now tell me something else', image: payload }),
});
const staleText = JSON.parse(requests.at(-1).init.body);
assert(requests.length === beforeStaleText + 1, 'text survives a stale legacy image payload');
assert(!Object.hasOwn(staleText, 'image') && !Object.hasOwn(staleText, 'image_event_id'), 'stale bytes and stale event ID are removed before network transport');

const beforeImageOnly = requests.length;
const ignored = await window.fetch(config.messageEndpoint, {
  method: 'POST',
  body: JSON.stringify({ message: '', image: payload }),
});
const ignoredBody = await ignored.json();
assert(requests.length === beforeImageOnly, 'image-only stale replay makes no network request');
assert(ignoredBody.status === 'duplicate_image_ignored', 'image-only stale replay receives deterministic ignored status');

const privacyScript = source.match(/<script id="aimee-privacy-consent-ui">([\s\S]*?)<\/script>/)?.[1] || '';
assert(privacyScript.includes('/privacy-consent'), 'theme-supplied UI calls the authenticated privacy-consent endpoint');
assert(privacyScript.includes('X-WP-Nonce'), 'theme-supplied privacy choices send the WordPress REST nonce');
assert(privacyScript.includes('special_category_consent:special.checked'), 'theme-supplied UI sends an explicit consent or withdrawal boolean');
assert(!privacyScript.includes('privacy_acknowledged:') && !privacyScript.includes('data-aimee-privacy-ack'), 'theme-supplied privacy settings do not request an acknowledgement');
assert(!privacyScript.includes('aimee-privacy-floating') && !privacyScript.includes('mountRequiredPanel'), 'theme-supplied UI cannot mount a floating privacy gate');
assert(privacyScript.includes('privacy notice') && privacyScript.includes('config.privacyUrl'), 'theme-supplied settings visibly link the privacy notice');
assert(source.indexOf('window.AIMEE_PRIVACY_CONFIG=') < source.indexOf('<script id="aimee-privacy-consent-ui">'), 'privacy UI config is defined before the executable bridge');
assert(fallbackSource.includes("api('/privacy-consent'"), 'fallback settings persist privacy choices through the dedicated endpoint');
assert(fallbackSource.includes("f.has('special_category_consent')"), 'fallback settings send explicit withdrawal when consent is unticked');
assert(!fallbackSource.includes('privacyGateRequired') && !fallbackSource.includes('privacyResult.privacy_acknowledged'), 'fallback settings never block on privacy acknowledgement');
assert(!fallbackSource.includes('name="privacy_acknowledged"') && fallbackSource.toLowerCase().includes('privacy notice'), 'fallback removes acknowledgement controls while keeping the notice visible');

if (failures.length) {
  console.error('Security/privacy UI regression failures:\n- ' + failures.join('\n- '));
  process.exit(1);
}

console.log(`PASS: ${checks} security/privacy UI bridge checks.`);
