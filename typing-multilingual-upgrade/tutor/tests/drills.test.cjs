'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

class Element {
  constructor() {
    this.value = '';
    this.textContent = '';
    this.listeners = {};
    this.children = [];
    this.style = {};
    this.hidden = false;
    this.disabled = false;
    this.selectionStart = 0;
  }
  addEventListener(event, listener) { this.listeners[event] = listener; }
  appendChild(child) { child.parentElement = this; this.children.push(child); }
  replaceChildren(...children) { this.children = []; children.forEach(child => this.appendChild(child)); }
  setAttribute(name, value) { this[name] = value; }
  querySelector() { return this.children.find(child => child['aria-current'] === 'step') || null; }
  getBoundingClientRect() { return { left: 0, right: 15, top: 0, height: 24 }; }
  scrollIntoView() {}
  focus() { this.focused = true; }
}

async function fixture(language = 'en') {
  const ui = require('../vocabulary/en.json').ui;
  const nodes = new Map();
  for (const id of ['drill-catalog', 'drill-settings', 'drill-input', 'drill-status', 'drill-submit',
    'drill-generate', 'drill-repeat', 'drill-next', 'drill-lines', 'drill-keys', 'drill-custom',
    'drill-empty', 'drill-practice', 'drill-history', 'drill-active-settings', 'drill-hints',
    'drill-progress', 'drill-complete', 'drill-outcome', 'drill-focus', 'drill-feedback',
    'drill-accuracy', 'drill-wpm']) nodes.set(id, new Element());
  nodes.get('drill-catalog').textContent = JSON.stringify({
    ui, languages: { en: require('../vocabulary/en.json'), hi: require('../vocabulary/hi.json'), ar: require('../vocabulary/ar.json') },
    layouts: require('../vocabulary/layouts.json')
  });
  const form = nodes.get('drill-settings');
  form.elements = Object.fromEntries(['language', 'layout', 'level', 'custom_words'].map(key => [key, new Element()]));
  Object.entries({ language, layout: 'unrestricted', level: '1', custom_words: '' })
    .forEach(([key, value]) => { form.elements[key].value = value; });
  const sample = { en: 'sad lad', hi: 'पानी हाथ', ar: 'سلام بيت' }[language];
  const session = { preferences: { language, layout: 'unrestricted', level: 1, custom_words: '' },
    lines: Array(10).fill(sample), results: [], focus: [] };
  const state = { preferences: session.preferences, session_id: 1, session,
    summary: { complete: false, passed: false, accuracy: 0, wpm: 0, weak: {}, completed_drills: 0 }, history: [] };
  let clock = 1000;
  let fail = false;
  const calls = [];
  const ticks = [];
  const document = {
    getElementById: id => nodes.get(id),
    querySelector: selector => ({ content: selector.includes('app-base') ? '/typing' : 'test-csrf' }),
    createElement: () => new Element()
  };
  const fetch = async (url, options) => {
    calls.push({ url, options });
    if (fail) return { ok: false, status: 500, json: async () => ({ error: 'Save failed. Retry.' }) };
    if (options?.body?.get('action') === 'submit') {
      session.results.push({ input: options.body.get('input'), duration_ms: 1000, accuracy: 1, wpm: 30 });
      state.summary.completed_drills++;
      state.summary.complete = state.summary.completed_drills === 10;
      state.summary.passed = state.summary.complete;
      state.summary.accuracy = 1;
      state.summary.wpm = 30;
    }
    return { ok: true, json: async () => JSON.parse(JSON.stringify(state)) };
  };
  class FormData {
    constructor(target) { this.entries = Object.entries(target.elements).map(([key, element]) => [key, element.value]); }
    [Symbol.iterator]() { return this.entries[Symbol.iterator](); }
  }
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'drills.js'), 'utf8'), {
    document, window: new Element(), fetch, URLSearchParams, FormData,
    performance: { now: () => clock }, setInterval: callback => ticks.push(callback)
  });
  const flush = () => new Promise(resolve => setImmediate(resolve));
  await flush();
  const input = nodes.get('drill-input');
  const type = (value, event = {}) => {
    input.value = value;
    input.selectionStart = value.length;
    input.listeners.input(event);
  };
  return { nodes, calls, ticks, type, flush, session, ui,
    clock: value => { clock = value; }, fail: value => { fail = value; } };
}

test('resumes saved native-script session without requesting AI or changing the practice direction', async () => {
  const ui = await fixture('ar');
  assert.equal(ui.nodes.get('drill-input').dir, 'rtl');
  assert.equal(ui.nodes.get('drill-input').lang, 'ar');
  assert.equal(ui.nodes.get('drill-practice').hidden, false);
  assert.equal(ui.calls.length, 1);
  assert.equal(ui.calls[0].url, '/typing/api/drills.php');
});

test('live accuracy counts Unicode code points and speed updates while idle', async () => {
  const ui = await fixture('hi');
  ui.type('पानी');
  ui.clock(61000);
  ui.ticks[0]();
  assert.equal(ui.nodes.get('drill-accuracy').textContent, '100.0%');
  assert.equal(ui.nodes.get('drill-wpm').textContent, '0.8');
  ui.clock(121000);
  ui.ticks[0]();
  assert.equal(ui.nodes.get('drill-wpm').textContent, '0.4');
});

test('IME composition is not scored or submitted before compositionend', async () => {
  const ui = await fixture('hi');
  const input = ui.nodes.get('drill-input');
  input.listeners.compositionstart();
  ui.type('प', { isComposing: true });
  ui.nodes.get('drill-submit').listeners.click();
  assert.equal(ui.calls.length, 1);
  assert.equal(ui.nodes.get('drill-status').textContent, ui.ui.composition);
  input.value = 'पानी';
  input.listeners.compositionend();
  assert.equal(ui.nodes.get('drill-accuracy').textContent, '100.0%');
});

test('server save includes session, ordered index and CSRF; next drill clears only after success', async () => {
  const ui = await fixture();
  ui.type('sad lad');
  ui.clock(2000);
  ui.nodes.get('drill-submit').listeners.click();
  await ui.flush();
  const fields = ui.calls[1].options.body;
  assert.equal(fields.get('session_id'), '1');
  assert.equal(fields.get('index'), '0');
  assert.equal(fields.get('input'), 'sad lad');
  assert.equal(fields.get('duration_ms'), '1000');
  assert.equal(fields.get('csrf_token'), 'test-csrf');
  assert.equal(ui.nodes.get('drill-progress').textContent, '2 / 10');
  assert.equal(ui.nodes.get('drill-input').value, '');
});

test('failed save preserves typed input, shows the error and permits retry', async () => {
  const ui = await fixture();
  ui.fail(true);
  ui.type('sad lad');
  ui.nodes.get('drill-submit').listeners.click();
  await ui.flush();
  assert.equal(ui.nodes.get('drill-input').value, 'sad lad');
  assert.equal(ui.nodes.get('drill-status').textContent, 'Save failed. Retry.');
  assert.equal(ui.nodes.get('drill-submit').disabled, false);
  ui.fail(false);
  ui.nodes.get('drill-submit').listeners.click();
  await ui.flush();
  assert.equal(ui.nodes.get('drill-progress').textContent, '2 / 10');
});

test('ten successful drill submissions offer advancement, show aggregate stats and prevent further typing', async () => {
  const ui = await fixture();
  for (let index = 0; index < 10; index++) {
    ui.type('sad lad');
    ui.nodes.get('drill-submit').listeners.click();
    await ui.flush();
  }
  assert.equal(ui.nodes.get('drill-complete').hidden, false);
  assert.equal(ui.nodes.get('drill-next').hidden, false);
  assert.equal(ui.nodes.get('drill-input').disabled, true);
  assert.equal(ui.nodes.get('drill-outcome').textContent, ui.ui.passed);
  assert.equal(ui.nodes.get('drill-accuracy').textContent, '100.0%');
});
