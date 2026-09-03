#!/usr/bin/env node
/** Browser contract regression for Camera Roll album -> chat handoff. */

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const tests = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(tests, '..');
const albumsSource = fs.readFileSync(path.join(root, 'templates', 'shared', 'gallery-albums.php'), 'utf8');
const legacySource = fs.readFileSync(path.join(root, 'includes', 'legacy-ui.php'), 'utf8');
const fallbackSource = fs.readFileSync(path.join(root, 'templates', 'shared', 'chat-fallback.php'), 'utf8');

const failures = [];
let checks = 0;
function assert(condition, label) {
  checks += 1;
  if (!condition) failures.push(label);
}

function scriptById(source, id) {
  const match = source.match(new RegExp(`<script id="${id}">([\\s\\S]*?)<\\/script>`));
  return match ? match[1] : '';
}

function makeStorage(initial = {}) {
  const values = new Map(Object.entries(initial).map(([key, value]) => [key, String(value)]));
  return {
    getItem(key) { return values.has(String(key)) ? values.get(String(key)) : null; },
    setItem(key, value) { values.set(String(key), String(value)); },
    removeItem(key) { values.delete(String(key)); },
    snapshot() { return Object.fromEntries(values.entries()); },
  };
}

function listenerRegistry() {
  const listeners = new Map();
  return {
    listeners,
    add(name, callback) {
      const list = listeners.get(name) || [];
      list.push(callback);
      listeners.set(name, list);
    },
    emit(name, event) {
      for (const callback of listeners.get(name) || []) callback(event);
    },
  };
}

function makeNode(tagName = 'div') {
  return {
    tagName: String(tagName).toUpperCase(),
    id: '',
    className: '',
    textContent: '',
    type: '',
    value: '',
    style: {},
    dataset: {},
    children: [],
    attributes: new Map(),
    listeners: new Map(),
    parentNode: null,
    removed: false,
    appendChild(child) { this.children.push(child); child.parentNode = this; return child; },
    append(...children) { children.forEach((child) => this.appendChild(child)); },
    setAttribute(name, value) { this.attributes.set(String(name), String(value)); },
    getAttribute(name) { return this.attributes.get(String(name)) || null; },
    removeAttribute(name) { this.attributes.delete(String(name)); },
    addEventListener(name, callback) {
      const list = this.listeners.get(name) || [];
      list.push(callback);
      this.listeners.set(name, list);
    },
    dispatchEvent() { return true; },
    remove() { this.removed = true; if (this.parentNode && this.parentNode.onRemove) this.parentNode.onRemove(this); },
    querySelectorAll() { return []; },
    querySelector() { return null; },
    closest() { return null; },
    matches() { return false; },
  };
}

const STORAGE_KEY = 'aimeeGalleryQuestion:1';
const DEFAULT_QUESTION = 'What’s the story behind this photo?';
const GENERIC_CHIP = 'Asking Aimee about this Camera Roll photo';
const NOW = 1_800_000_000_000;

// -------------------------------------------------------------------------
// Gallery producer: the cross-page record is deliberately minimal.
// -------------------------------------------------------------------------
const galleryScript = scriptById(albumsSource, 'aimee-gallery-question-handoff');
assert(galleryScript !== '', 'gallery album handoff script is present');
assert(albumsSource.includes("$app_url . '#ask-aimee-about-photo'"), 'gallery action uses the generic ask-photo fragment');
assert(albumsSource.includes('data-aimee-ask-photo') && albumsSource.includes('data-media-key'), 'gallery action exposes only the catalogue key needed for handoff');
assert(!/location\.search|URLSearchParams|[?&](?:alt|title|description)=/.test(galleryScript), 'gallery handoff does not put catalogue metadata in the URL');

{
  const storage = makeStorage();
  const registry = listenerRegistry();
  const link = {
    getAttribute(name) { return name === 'data-media-key' ? 'summer_park_picnic_selfie_01' : ''; },
  };
  const document = {
    addEventListener(name, callback) { registry.add(name, callback); },
  };
  class FixedDate extends Date { static now() { return NOW; } }
  vm.runInContext(galleryScript, vm.createContext({
    window: { sessionStorage: storage },
    document,
    Date: FixedDate,
    JSON,
    String,
  }), { filename: 'aimee-gallery-question-handoff.js' });
  let prevented = false;
  registry.emit('click', {
    target: { closest(selector) { return selector === '[data-aimee-ask-photo]' ? link : null; } },
    preventDefault() { prevented = true; },
  });
  const stored = JSON.parse(storage.getItem(STORAGE_KEY));
  assert(!prevented, 'valid gallery handoff preserves normal link navigation');
  assert(JSON.stringify(Object.keys(stored).sort()) === JSON.stringify(['created_at', 'key']), 'gallery stores exactly key and created_at');
  assert(stored.key === 'summer_park_picnic_selfie_01' && stored.created_at === NOW, 'gallery stores the selected key with the click timestamp');
  assert(!Object.hasOwn(stored, 'alt') && !Object.hasOwn(stored, 'rating') && !Object.hasOwn(stored, 'url'), 'gallery stores no alt, rating, URL, or client-authored metadata');
}

function galleryImageFailureHarness({ complete = false, naturalWidth = 1 } = {}) {
  const storage = makeStorage();
  const registry = listenerRegistry();
  const state = { classes: new Set(), attributes: new Map(), error: null, once: false };
  const media = {
    classList: { add(name) { state.classes.add(String(name)); } },
    setAttribute(name, value) { state.attributes.set(String(name), String(value)); },
  };
  const image = {
    complete,
    naturalWidth,
    parentElement: media,
    addEventListener(name, callback, options = {}) {
      if (name === 'error') {
        state.error = callback;
        state.once = options?.once === true;
      }
    },
  };
  const document = {
    querySelectorAll(selector) { return selector === '[data-aimee-gallery-image]' ? [image] : []; },
    addEventListener(name, callback) { registry.add(name, callback); },
  };
  vm.runInContext(galleryScript, vm.createContext({
    window: { sessionStorage: storage },
    document,
    Date,
    JSON,
    String,
    Array,
  }), { filename: 'aimee-gallery-image-failure.js' });
  return { image, state };
}

{
  const harness = galleryImageFailureHarness();
  harness.state.error?.();
  assert(harness.state.once, 'gallery image error observer is one-shot');
  assert(harness.state.classes.has('is-unavailable'), 'gallery image error replaces the broken image presentation');
  assert(harness.state.attributes.get('role') === 'img' && harness.state.attributes.get('aria-label') === 'This photo is temporarily unavailable', 'gallery image error exposes an accessible unavailable state');
}

{
  const harness = galleryImageFailureHarness({ complete: true, naturalWidth: 0 });
  assert(harness.state.classes.has('is-unavailable'), 'gallery immediately catches an image failure already cached before script startup');
}

// -------------------------------------------------------------------------
// Signed-in legacy chat discovery: the Photos shortcut mounts in the header,
// remains idempotent through retries, and has a touch-sized accessible label.
// -------------------------------------------------------------------------
const discoveryScriptRaw = scriptById(legacySource, 'aimee-chat-gallery-discovery');
assert(discoveryScriptRaw !== '', 'legacy chat gallery discovery script is present');
{
  const discoveryScript = discoveryScriptRaw.replace(
    '__AIMEE_GALLERY_DISCOVERY_CONFIG__',
    JSON.stringify({ galleryUrl: 'https://example.test/camera-roll/' }),
  );
  const state = { link: null, appendCount: 0, observer: null };
  const header = makeNode('header');
  header.className = 'chat-header';
  header.appendChild = (child) => {
    state.link = child;
    state.appendCount += 1;
    child.parentNode = header;
    return child;
  };
  const chat = makeNode('main');
  chat.id = 'chat-interface';
  chat.querySelector = (selector) => selector.includes('.chat-header') ? header : null;
  const document = {
    readyState: 'complete',
    body: {},
    getElementById(id) {
      if (id === 'aimee-chat-gallery-link') return state.link;
      if (id === 'chat-interface') return chat;
      return null;
    },
    querySelector() { return null; },
    createElement(tag) { return makeNode(tag); },
    addEventListener() {},
  };
  class MutationObserver {
    constructor(callback) { state.observer = callback; }
    observe() {}
  }
  const window = {
    MutationObserver,
    setTimeout(callback) { callback(); return 1; },
  };
  vm.runInContext(discoveryScript, vm.createContext({
    window,
    document,
    MutationObserver,
    JSON,
  }), { filename: 'aimee-chat-gallery-discovery.js' });
  assert(state.appendCount === 1, 'legacy Photos shortcut mounts exactly once through immediate retries');
  assert(state.link?.href === 'https://example.test/camera-roll/' && state.link?.innerHTML.includes('<span>Photos</span>'), 'legacy Photos shortcut visibly labels the configured market gallery target');
  assert(state.link?.id === 'aimee-chat-gallery-link' && state.link?.getAttribute('aria-label') === 'Open Aimee’s photo gallery', 'legacy Photos shortcut has a stable id and descriptive accessible label');
  state.observer?.();
  assert(state.appendCount === 1, 'legacy Photos shortcut remains idempotent through chat DOM mutations');
}

// -------------------------------------------------------------------------
// Central legacy bridge harness. This is the stable boundary around the
// theme-owned UI, so these checks do not depend on historical theme closures.
// -------------------------------------------------------------------------
const legacyScriptRaw = scriptById(legacySource, 'aimee-security-privacy-bridge');
assert(legacyScriptRaw !== '', 'central legacy security bridge script is present');
const bridgeConfig = {
  profileEndpoint: 'https://example.test/wp-json/aimee/v1/profile',
  messageEndpoint: 'https://example.test/wp-json/aimee/v1/message',
  privacyUrl: 'https://example.test/privacy/',
};
const legacyScript = legacyScriptRaw.replace('__AIMEE_SECURITY_CONFIG__', JSON.stringify(bridgeConfig));
const freshReference = { key: 'bookshop_browse_01', created_at: NOW };

function centralHarness({ stored = null, draft = '', now = NOW, failMessage = false } = {}) {
  let clock = now;
  const storage = makeStorage(stored === null ? {} : { [STORAGE_KEY]: JSON.stringify(stored) });
  const registry = listenerRegistry();
  const requests = [];
  const state = { chip: null };

  const textarea = makeNode('textarea');
  textarea.id = 'text';
  textarea.value = draft;
  const composerHost = makeNode('div');
  composerHost.id = 'message-composer';
  const composerParent = makeNode('div');
  composerHost.parentNode = composerParent;
  composerParent.insertBefore = (node) => {
    node.parentNode = composerParent;
    if (node.id === 'aimee-gallery-question-context') state.chip = node;
  };
  composerParent.onRemove = (node) => { if (state.chip === node) state.chip = null; };
  textarea.closest = (selector) => selector.includes('onboarding') ? null : composerHost;

  const fileInput = makeNode('input');
  fileInput.type = 'file';
  fileInput.name = 'chat_image';
  fileInput.id = 'image-input';
  fileInput.accept = 'image/*';
  fileInput.files = [];
  fileInput.closest = () => null;
  const preview = makeNode('div');

  const document = {
    readyState: 'complete',
    documentElement: {},
    body: {},
    addEventListener(name, callback) { registry.add(name, callback); },
    getElementById(id) { return id === 'aimee-gallery-question-context' ? state.chip : null; },
    querySelector(selector) {
      if (selector.startsWith('#text,#message-input')) return textarea;
      return null;
    },
    querySelectorAll(selector) {
      if (selector === 'input[type="file"]') return [fileInput];
      if (selector.includes('#image-preview')) return [preview];
      return [];
    },
    createElement(tag) { return makeNode(tag); },
    createTextNode(text) { const node = makeNode('#text'); node.textContent = String(text); return node; },
  };

  class MutationObserver { observe() {} }
  class FixedDate extends Date { static now() { return clock; } }
  class BrowserEvent { constructor(type, options = {}) { this.type = type; this.bubbles = !!options.bubbles; } }

  const window = {
    location: { href: 'https://example.test/chat/#ask-aimee-about-photo' },
    sessionStorage: storage,
    Response,
    crypto: {
      randomUUID() { return '00000000-0000-4000-8000-000000000001'; },
      subtle: globalThis.crypto?.subtle,
    },
    async fetch(input, init = {}) {
      const record = {
        input: String(input),
        init: { ...init },
        storedAtDispatch: storage.getItem(STORAGE_KEY),
        chipAtDispatch: !!state.chip,
      };
      requests.push(record);
      const url = new URL(String(input), window.location.href);
      if (failMessage && url.origin === 'https://example.test' && url.pathname === '/wp-json/aimee/v1/message') {
        throw new Error('simulated network failure');
      }
      return { ok: true, status: 200, async json() { return { status: 'success' }; } };
    },
  };

  const context = vm.createContext({
    window,
    document,
    MutationObserver,
    Event: BrowserEvent,
    URL,
    Response,
    TextEncoder,
    Uint8Array,
    Array,
    String,
    Number,
    Boolean,
    Math,
    Date: FixedDate,
    Promise,
    JSON,
    Object,
  });
  vm.runInContext(legacyScript, context, { filename: 'aimee-security-privacy-bridge.js' });

  const voiceButton = makeNode('button');
  voiceButton.id = 'voice-btn';
  voiceButton.className = 'voice-note-button';
  voiceButton.setAttribute('aria-label', 'Record voice note');
  voiceButton.closest = (selector) => /voice|record|microphone|mic/i.test(String(selector)) ? voiceButton : null;
  voiceButton.matches = (selector) => /voice|record|microphone|mic/i.test(String(selector));

  return {
    storage,
    requests,
    textarea,
    fileInput,
    voiceButton,
    window,
    get chip() { return state.chip; },
    setNow(value) { clock = Number(value); },
    emit(name, target) {
      registry.emit(name, {
        target,
        preventDefault() {},
        stopImmediatePropagation() {},
      });
    },
  };
}

{
  const harness = centralHarness({
    stored: freshReference,
    draft: 'Please tell me about the selected photo.',
  });
  assert(!!harness.chip, 'central dispatch-expiry fixture mounts while the reference is fresh');
  harness.setNow(NOW + 10 * 60 * 1000 + 1);
  await harness.window.fetch(bridgeConfig.messageEndpoint, {
    method: 'POST',
    body: JSON.stringify({ message: harness.textarea.value }),
  });
  const request = harness.requests.at(-1);
  const body = JSON.parse(request.init.body);
  assert(!Object.hasOwn(body, 'referenced_media_key'), 'central bridge drops a newly stale reference at /message dispatch');
  assert(request.storedAtDispatch === null && !request.chipAtDispatch, 'central dispatch-time expiry clears storage and chip before network I/O');
}

{
  const harness = centralHarness({ stored: freshReference });
  assert(harness.chip && harness.chip.children[0].textContent === GENERIC_CHIP, 'central bridge mounts a generic context chip');
  assert(harness.textarea.value === DEFAULT_QUESTION, 'central bridge supplies the generic default question to an empty composer');
  assert(harness.window.location.href.endsWith('#ask-aimee-about-photo'), 'central bridge does not need client metadata in the fragment');
}

{
  const harness = centralHarness({ stored: freshReference, draft: 'Keep my carefully written draft.' });
  assert(harness.textarea.value === 'Keep my carefully written draft.', 'central bridge never overwrites an existing draft');
  assert(!!harness.chip, 'central bridge still shows context beside an existing draft');
}

for (const [offset, accepted, label] of [
  [-10 * 60 * 1000, true, 'central bridge accepts the exact ten-minute boundary'],
  [-10 * 60 * 1000 - 1, false, 'central bridge rejects a reference older than ten minutes'],
  [60 * 1000, true, 'central bridge accepts the exact future-skew boundary'],
  [60 * 1000 + 1, false, 'central bridge rejects excessive future clock skew'],
]) {
  const harness = centralHarness({ stored: { key: 'portrait', created_at: NOW + offset } });
  assert(Boolean(harness.chip) === accepted, label);
  assert((harness.storage.getItem(STORAGE_KEY) !== null) === accepted, `${label} and ${accepted ? 'retains' : 'clears'} storage`);
}

{
  const harness = centralHarness({ stored: freshReference });
  const cancel = harness.chip?.children[1];
  for (const callback of cancel?.listeners.get('click') || []) callback({ target: cancel });
  assert(!harness.chip && harness.storage.getItem(STORAGE_KEY) === null, 'central cancel clears the chip and stored reference');
  assert(harness.textarea.value === '', 'central cancel clears only its unchanged default question');
}

{
  const harness = centralHarness({ stored: freshReference });
  harness.fileInput.files = [{ name: 'different-upload.png' }];
  harness.emit('change', harness.fileInput);
  assert(!harness.chip && harness.storage.getItem(STORAGE_KEY) === null, 'central user upload cancels the gallery reference');
  assert(harness.textarea.value === '', 'central user upload removes the unchanged gallery default question');
}

{
  const harness = centralHarness({ stored: freshReference });
  // Support either delegated voice-button cancellation or cancellation at the
  // voice transport boundary; both are stable across historical theme UIs.
  harness.emit('click', harness.voiceButton);
  await harness.window.fetch('https://example.test/wp-json/aimee/v1/voice-note/send', {
    method: 'POST',
    body: new FormData(),
  });
  assert(!harness.chip && harness.storage.getItem(STORAGE_KEY) === null, 'central voice action cancels the gallery reference');
  assert(harness.textarea.value === '', 'central voice action removes the unchanged gallery default question');
}

{
  const harness = centralHarness({ stored: freshReference });
  await harness.window.fetch(bridgeConfig.profileEndpoint, {
    method: 'POST', body: JSON.stringify({ first_name: 'Test' }),
  });
  let body = JSON.parse(harness.requests.at(-1).init.body);
  assert(!Object.hasOwn(body, 'referenced_media_key'), 'central bridge never adds the key to /profile');
  assert(harness.storage.getItem(STORAGE_KEY) !== null, 'non-message endpoint does not consume the reference');

  await harness.window.fetch('https://example.test/wp-json/aimee/v1/message-extra', {
    method: 'POST', body: JSON.stringify({ message: 'not the exact route' }),
  });
  body = JSON.parse(harness.requests.at(-1).init.body);
  assert(!Object.hasOwn(body, 'referenced_media_key'), 'central bridge never adds the key to a message-like sibling route');
  assert(harness.storage.getItem(STORAGE_KEY) !== null, 'message-like sibling route does not consume the reference');

  await harness.window.fetch('https://attacker.example/wp-json/aimee/v1/message', {
    method: 'POST', body: JSON.stringify({ message: 'wrong origin' }),
  });
  body = JSON.parse(harness.requests.at(-1).init.body);
  assert(!Object.hasOwn(body, 'referenced_media_key'), 'central bridge never adds the key to another origin');
  assert(harness.storage.getItem(STORAGE_KEY) !== null, 'other-origin route does not consume the reference');

  await harness.window.fetch(`${bridgeConfig.messageEndpoint}?request=1`, {
    method: 'POST', body: JSON.stringify({ message: 'Tell me more.', referenced_media_key: 'client-forgery' }),
  });
  body = JSON.parse(harness.requests.at(-1).init.body);
  assert(body.referenced_media_key === freshReference.key, 'central exact /message replaces client input with the stored key');
  assert(harness.requests.at(-1).storedAtDispatch === null && !harness.requests.at(-1).chipAtDispatch, 'central bridge consumes atomically before /message dispatch');
}

{
  const harness = centralHarness({ stored: freshReference, failMessage: true });
  let failed = false;
  try {
    await harness.window.fetch(bridgeConfig.messageEndpoint, {
      method: 'POST', body: JSON.stringify({ message: DEFAULT_QUESTION }),
    });
  } catch (error) { failed = true; }
  assert(failed, 'central failure fixture reaches the transport failure');
  assert(harness.storage.getItem(STORAGE_KEY) === null && !harness.chip, 'central failed fetch does not restore the consumed reference');
  try {
    await harness.window.fetch(bridgeConfig.messageEndpoint, {
      method: 'POST', body: JSON.stringify({ message: 'Retry without selecting again.' }),
    });
  } catch (error) {}
  const retry = JSON.parse(harness.requests.at(-1).init.body);
  assert(!Object.hasOwn(retry, 'referenced_media_key'), 'central retry cannot reuse a consumed reference');
}

// -------------------------------------------------------------------------
// Bundled fallback: execute its actual handoff helpers and send function in a
// small harness, while separately pinning its upload/voice event wiring.
// -------------------------------------------------------------------------
const fallbackHelperStart = fallbackSource.indexOf("const galleryStorageKey='aimeeGalleryQuestion:1'");
const fallbackHelperEnd = fallbackSource.indexOf('async function api(', fallbackHelperStart);
const fallbackHelpers = fallbackHelperStart >= 0 && fallbackHelperEnd > fallbackHelperStart
  ? fallbackSource.slice(fallbackHelperStart, fallbackHelperEnd)
  : '';
const fallbackSend = fallbackSource.match(/async function sendMessage\(\)\{[^\n]+\}/)?.[0] || '';
assert(fallbackHelpers !== '' && fallbackSend !== '', 'bundled fallback handoff helpers and send function are extractable');

function fallbackHarness({ stored = null, draft = '', now = NOW, failMessage = false } = {}) {
  let clock = now;
  const storage = makeStorage(stored === null ? {} : { [STORAGE_KEY]: JSON.stringify(stored) });
  const requests = [];
  const text = makeNode('textarea');
  text.value = draft;
  const send = makeNode('button');
  const imageInput = makeNode('input');
  imageInput.value = '';
  const preview = makeNode('div');
  const contextHost = makeNode('div');
  let contextChip = null;
  contextHost.before = (node) => { contextChip = node; node.parentNode = { onRemove(removed) { if (removed === contextChip) contextChip = null; } }; };
  const paywall = { classList: { add() {} } };
  const nodes = {
    '#gallery-question-context': () => contextChip,
    '#message-composer': () => contextHost,
    '#typing': () => null,
    '#paywall': () => paywall,
  };
  const q = (selector) => nodes[selector] ? nodes[selector]() : null;
  const document = { createElement: (tag) => makeNode(tag) };
  class FixedDate extends Date { static now() { return clock; } }
  class BrowserEvent { constructor(type, options = {}) { this.type = type; this.bubbles = !!options.bubbles; } }

  const api = async (route, options = {}) => {
    requests.push({
      route,
      options: { ...options },
      storedAtDispatch: storage.getItem(STORAGE_KEY),
      chipAtDispatch: !!contextChip,
    });
    if (failMessage && route === '/message') throw new Error('simulated fallback failure');
    return { status: 'success', reply: '' };
  };
  const row = () => makeNode('div');
  const typing = () => {};
  const render = () => {};
  let image = null;
  let imageEventId = '';
  let sending = false;
  const market = 'uk';
  const clearImageSelection = () => { image = null; imageEventId = ''; imageInput.value = ''; preview.style.display = 'none'; };

  const script = `
    ${fallbackHelpers}
    ${fallbackSend}
    mountGalleryReference();
    window.__fallbackTest = {
      sendMessage: sendMessage,
      clearGalleryReference: clearGalleryReference,
      getReference: function(){ return galleryReference; },
      getChip: function(){ return q('#gallery-question-context'); }
    };
  `;
  const window = {};
  vm.runInContext(script, vm.createContext({
    window,
    document,
    sessionStorage: storage,
    Event: BrowserEvent,
    Date: FixedDate,
    Number,
    String,
    JSON,
    Object,
    q,
    text,
    send,
    imageInput,
    preview,
    api,
    row,
    typing,
    render,
    clearImageSelection,
    market,
    get image() { return image; },
    set image(value) { image = value; },
    get imageEventId() { return imageEventId; },
    set imageEventId(value) { imageEventId = value; },
    get sending() { return sending; },
    set sending(value) { sending = value; },
  }), { filename: 'aimee-chat-fallback-handoff.js' });

  return {
    window,
    storage,
    requests,
    text,
    get chip() { return contextChip; },
    setNow(value) { clock = Number(value); },
  };
}

{
  const harness = fallbackHarness({
    stored: freshReference,
    draft: 'Please tell me about the selected photo.',
  });
  assert(!!harness.chip, 'fallback dispatch-expiry fixture mounts while the reference is fresh');
  harness.setNow(NOW + 10 * 60 * 1000 + 1);
  await harness.window.__fallbackTest.sendMessage();
  const request = harness.requests.find((candidate) => candidate.route === '/message');
  const body = JSON.parse(request.options.body);
  assert(!Object.hasOwn(body, 'referenced_media_key'), 'fallback drops a newly stale reference at /message dispatch');
  assert(request.storedAtDispatch === null && !request.chipAtDispatch, 'fallback dispatch-time expiry clears storage and chip before network I/O');
}

{
  const harness = fallbackHarness({ stored: freshReference });
  assert(harness.chip && harness.chip.children[0].textContent === GENERIC_CHIP, 'fallback mounts the same generic context chip');
  assert(harness.text.value === DEFAULT_QUESTION, 'fallback supplies the same generic default question');
  await harness.window.__fallbackTest.sendMessage();
  const payload = JSON.parse(harness.requests.find((request) => request.route === '/message').options.body);
  assert(payload.referenced_media_key === freshReference.key, 'fallback adds only the selected key to its /message payload');
  const dispatched = harness.requests.find((request) => request.route === '/message');
  assert(dispatched.storedAtDispatch === null && !dispatched.chipAtDispatch, 'fallback consumes atomically before /message dispatch');
}

{
  const harness = fallbackHarness({ stored: freshReference, draft: 'Do not replace this draft.', failMessage: true });
  assert(harness.text.value === 'Do not replace this draft.', 'fallback never overwrites an existing draft');
  await harness.window.__fallbackTest.sendMessage();
  assert(harness.storage.getItem(STORAGE_KEY) === null && !harness.chip, 'fallback failed fetch does not restore the consumed reference');
  harness.text.value = 'Retry without selecting the photo again.';
  await harness.window.__fallbackTest.sendMessage();
  const messageRequests = harness.requests.filter((request) => request.route === '/message');
  const retry = JSON.parse(messageRequests.at(-1).options.body);
  assert(!Object.hasOwn(retry, 'referenced_media_key'), 'fallback retry cannot reuse a consumed reference');
}

for (const [offset, accepted, label] of [
  [-10 * 60 * 1000 - 1, false, 'fallback rejects a reference older than ten minutes'],
  [60 * 1000 + 1, false, 'fallback rejects excessive future clock skew'],
]) {
  const harness = fallbackHarness({ stored: { key: 'portrait', created_at: NOW + offset } });
  assert(Boolean(harness.chip) === accepted && (harness.storage.getItem(STORAGE_KEY) !== null) === accepted, label);
}

const fallbackUploadLine = fallbackSource.match(/imageInput\.onchange=\(\)=>\{[^\n]+\}/)?.[0] || '';
const fallbackVoiceLine = fallbackSource.match(/q\('#voice-btn'\)\.onclick=async\(\)=>\{[^\n]+\}/)?.[0] || '';
assert(fallbackUploadLine.indexOf('clearGalleryReference(true)') >= 0, 'fallback file upload explicitly clears gallery context');
assert(fallbackVoiceLine.indexOf('clearGalleryReference(true)') >= 0 && fallbackVoiceLine.indexOf('clearGalleryReference(true)') < fallbackVoiceLine.indexOf('getUserMedia'), 'fallback voice action clears gallery context before microphone work');
assert(fallbackSend.indexOf("await api('/message'") > fallbackSend.indexOf('if(referenceKey)clearGalleryReference(false)'), 'fallback consumes the reference before exact /message network I/O');
assert(!/catch\([^)]*\)\{[^}]*galleryReference\s*=|catch\([^)]*\)\{[^}]*sessionStorage\.setItem/.test(fallbackSend), 'fallback failure path never restores a consumed reference');

if (failures.length) {
  console.error('Gallery album handoff UI regression failures:\n- ' + failures.join('\n- '));
  process.exit(1);
}

console.log(`PASS: ${checks} gallery album handoff UI checks.`);
