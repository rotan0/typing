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
    this.selectionStart = 0;
    this.listeners = {};
    this.children = [];
    this.style = {};
    this.className = '';
  }
  addEventListener(event, listener) { this.listeners[event] = listener; }
  appendChild(child) { this.children.push(child); }
  replaceChildren(...children) { this.children = children; }
  setAttribute(name, value) { this[name] = value; }
  focus() { this.focused = true; }
  getBoundingClientRect() { return { left: 10, right: 26, top: 20, height: 30 }; }
}

async function fixture() {
  const nodes = new Map(['typing-area', 'lesson-list', 'start-lesson', 'submit-btn',
    'accuracy-val', 'speed-val', 'app-status', 'lesson-text'].map(id => [id, new Element()]));
  const calls = [];
  const window = new Element();
  const document = {
    getElementById: id => nodes.get(id),
    createElement: () => new Element(),
    querySelector: selector => ({ content: selector.includes('app-base') ? '/typing' : 'test-csrf' })
  };
  const fetch = async (url, options) => {
    calls.push({ url, options });
    return { ok: true, json: async () => options ? { accuracy: 1, duration_ms: 1500 } :
      [{ id: 7, name: 'SOS', level: 1, expected_sequence: 'SOS 123' }] };
  };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'app.js'), 'utf8'),
    { document, window, fetch, URLSearchParams, performance: { now: () => 2500 }, playKeySound() {}, setTimeout });
  await new Promise(resolve => setImmediate(resolve));
  nodes.get('lesson-list').value = '7';
  const start = () => nodes.get('start-lesson').listeners.click();
  const type = (text, selection = text.length) => {
    nodes.get('typing-area').value = text;
    nodes.get('typing-area').selectionStart = selection;
    nodes.get('typing-area').listeners.input();
  };
  return { nodes, calls, start, type, window };
}

test('starting a lesson renders literal characters, a decorative caret and resets old results', async () => {
  const ui = await fixture();
  ui.nodes.get('accuracy-val').textContent = '100%';
  ui.start();
  const children = ui.nodes.get('lesson-text').children;
  assert.equal(children.slice(0, -1).map(node => node.textContent).join(''), 'SOS 123');
  assert.equal(children.at(-1)['aria-hidden'], 'true');
  assert.equal(children.at(-1).style.transform, 'translate(0px, 0px)');
  assert.equal(ui.nodes.get('accuracy-val').textContent, '-');
  assert.equal(ui.nodes.get('typing-area').focused, true);
});

test('feedback matches case-insensitive scoring and clears correctly after deletion and restart', async () => {
  const ui = await fixture();
  ui.start();
  ui.type('sox ');
  const characters = ui.nodes.get('lesson-text').children;
  assert.deepEqual(characters.slice(0, 5).map(node => node.className),
    ['correct', 'correct', 'incorrect', 'correct', '']);
  ui.type('s');
  assert.equal(characters[2].className, '');
  ui.start();
  assert.equal(ui.nodes.get('typing-area').value, '');
  assert.equal(ui.nodes.get('lesson-text').children[0].className, '');
});

test('caret follows selection, completion, overtyping and viewport resizing', async () => {
  const ui = await fixture();
  ui.start();
  const caret = ui.nodes.get('lesson-text').children.at(-1);
  ui.type('SOS 123');
  assert.equal(caret.style.transform, 'translate(16px, 0px)');
  ui.type('SOS 123 EXTRA');
  assert.equal(caret.style.transform, 'translate(16px, 0px)');
  ui.nodes.get('typing-area').selectionStart = 1;
  ui.nodes.get('typing-area').listeners.select();
  assert.equal(caret.style.transform, 'translate(0px, 0px)');
  ui.window.listeners.resize();
  assert.equal(caret.style.height, '30px');
});

test('the styled practice keeps the original submission shape and server results', async () => {
  const ui = await fixture();
  ui.start();
  ui.type('sos 123');
  await ui.nodes.get('submit-btn').listeners.click();
  const request = ui.calls.find(call => call.options);
  assert.equal(request.url, '/typing/api/submit.php');
  assert.equal(request.options.body.get('raw_input'), 'sos 123');
  assert.equal(request.options.body.get('lesson_id'), '7');
  assert.equal(request.options.body.get('csrf_token'), 'test-csrf');
  assert.equal(ui.nodes.get('accuracy-val').textContent, '100.0%');
  assert.equal(ui.nodes.get('speed-val').textContent, 1500);
  assert.equal(ui.nodes.get('submit-btn').disabled, false);
});
