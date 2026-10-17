'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

class Element {
  constructor(tag = '') {
    this.tag = tag;
    this.value = '';
    this.disabled = false;
    this.textContent = '';
    this.options = [];
    this.listeners = {};
    this.elements = {};
    this.dataset = { ready: 'Ready to ask', public: 'Published', private: 'Private' };
  }
  addEventListener(event, listener) { this.listeners[event] = listener; }
  appendChild(child) { this.options.push(child); }
  replaceChildren(...children) {
    this.options = children;
    if (this.tag === 'select') this.value = children[0]?.value || '';
  }
  reset() { Object.values(this.elements).forEach(element => { element.value = ''; }); }
  focus() { this.focused = true; }
}

async function fixture(available = true) {
  const nodes = new Map();
  for (const id of ['studio-status', 'conversation-list', 'owned-lessons', 'lesson-editor', 'publish', 'unpublish',
    'ask-assistant', 'assistant-model', 'use-assistant-text', 'messages', 'new-conversation', 'save-note',
    'assistant-chat', 'import-profile', 'sharing-status', 'assistant-status']) {
    nodes.set(id, new Element(['conversation-list', 'owned-lessons', 'assistant-model'].includes(id) ? 'select' : ''));
  }
  nodes.get('owned-lessons').options = [{ value: '', textContent: 'New lesson' }];
  for (const id of ['ask-assistant', 'assistant-model', 'use-assistant-text']) {
    nodes.get(id).disabled = true;
  }
  for (const name of ['name', 'description', 'level', 'expected_sequence']) {
    nodes.get('lesson-editor').elements[name] = new Element();
  }
  nodes.get('assistant-chat').elements = { question: new Element(), consent: { checked: false } };
  const newConversationButton = new Element();
  const calls = [];
  const messages = [];
  let fail = false;
  const document = {
    getElementById: id => nodes.get(id),
    createElement: tag => new Element(tag),
    querySelector: selector => selector === '#new-conversation button' ? newConversationButton :
      { content: selector.includes('user-id') ? '1' : selector.includes('csrf-token') ? 'test-csrf' : '/typing' }
  };
  const response = (status, data) => ({ ok: status === 200, status, json: async () => data });
  const fetcher = async (url, options) => {
    calls.push({ url, options });
    if (url.endsWith('/assistant.php')) {
      if (!options) return response(available ? 200 : 503,
        available ? { available: true, models: [{ id: 'approved-model', label: 'Approved model' }] } : { error: 'Not enabled.' });
      if (fail) return response(502, { error: 'Model transport failed.' });
      const fields = options.body;
      messages.push({ role: 'user', content: fields.get('question') });
      messages.push({ role: 'assistant', content: 'Try slow repetitions:\n```text\nSOS 123\n```' });
      return response(200, { content: messages[messages.length - 1].content });
    }
    if (url.includes('/conversations.php?id=')) return response(200, { messages });
    if (url.endsWith('/conversations.php')) return response(200, [{ id: '7', title: 'Design' }]);
    if (url.endsWith('/lessons.php')) return response(200, []);
    throw new Error('Unexpected request: ' + url);
  };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'studio.js'), 'utf8'),
    { document, fetch: fetcher, URLSearchParams, FormData, console });
  for (let turn = 0; turn < 3; turn++) await new Promise(resolve => setImmediate(resolve));
  const form = nodes.get('assistant-chat');
  return { nodes, calls, messages, fail: () => { fail = true; },
    send: () => form.listeners.submit({ preventDefault() {}, currentTarget: form }) };
}

test('assistant availability only sends metadata GET; enables approved model choice without exposing private content', async () => {
  const ui = await fixture();
  assert.equal(ui.nodes.get('ask-assistant').disabled, false);
  assert.equal(ui.nodes.get('assistant-model').value, 'approved-model');
  assert.equal(ui.nodes.get('assistant-status').textContent, 'Ready to ask');
  const calls = ui.calls.filter(call => call.url.endsWith('/assistant.php'));
  assert.equal(calls.length, 1);
  assert.equal(calls[0].options, undefined);
});

test('explicit ask sends only selected conversation/question/model/consent and clears consent after saving', async () => {
  const ui = await fixture();
  const form = ui.nodes.get('assistant-chat');
  form.elements.question.value = 'Design an SOS practice lesson.';
  form.elements.consent.checked = true;
  await ui.send();
  const body = ui.calls.find(call => call.options?.method === 'POST').options.body;
  assert.equal(body.get('conversation_id'), '7');
  assert.equal(body.get('question'), 'Design an SOS practice lesson.');
  assert.equal(body.get('consent'), '1');
  assert.equal(body.get('model'), 'approved-model');
  assert.equal(body.get('csrf_token'), 'test-csrf');
  assert.equal(form.elements.question.value, '');
  assert.equal(form.elements.consent.checked, false);
  assert.equal(ui.nodes.get('messages').options.length, 2);
  assert.equal(ui.nodes.get('use-assistant-text').disabled, false);
  ui.nodes.get('use-assistant-text').listeners.click();
  assert.equal(ui.nodes.get('lesson-editor').elements.expected_sequence.value, 'SOS 123');
  assert.equal(ui.nodes.get('owned-lessons').value, '');
  assert.equal(ui.calls.filter(call => call.url.endsWith('/lessons.php') && call.options?.method === 'POST').length, 0);
});

test('provider failure keeps the question and reports the error; no success-shaped empty response', async () => {
  const ui = await fixture();
  ui.fail();
  const form = ui.nodes.get('assistant-chat');
  form.elements.question.value = 'Keep this question for retry.';
  form.elements.consent.checked = true;
  await ui.send();
  assert.equal(form.elements.question.value, 'Keep this question for retry.');
  assert.equal(ui.nodes.get('studio-status').textContent, 'Model transport failed.');
  assert.equal(ui.nodes.get('ask-assistant').disabled, false);
  assert.equal(ui.messages.length, 0);
});

test('disconnected downloaded tutor keeps ask disabled and does not send inference', async () => {
  const ui = await fixture(false);
  assert.equal(ui.nodes.get('ask-assistant').disabled, true);
  await ui.send();
  assert.equal(ui.calls.filter(call => call.options?.method === 'POST').length, 0);
  assert.equal(ui.nodes.get('assistant-status').title, 'Not enabled.');
});
