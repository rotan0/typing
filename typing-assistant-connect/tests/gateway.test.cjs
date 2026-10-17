'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { once } = require('node:events');
const { loadConfig, createGateway, validateChat, INSTRUCTIONS } = require('../gateway/server.cjs');

const raw = { models: [
  { id: 'local', label: 'Local', type: 'ollama', model: 'llama3.2:latest', base_url: 'http://127.0.0.1:11434' },
  { id: 'hosted', label: 'Hosted', type: 'openai', model: 'approved-model', base_url: 'https://provider.example/v1', key_env: 'TEST_KEY' },
  { id: 'anthropic', label: 'Anthropic', type: 'anthropic', model: 'approved-model', base_url: 'https://api.anthropic.com/v1', key_env: 'TEST_KEY' }
] };
const config = () => loadConfig(raw, { TEST_KEY: 'fake-test-key' });
const chat = (model = 'local') => ({ model, messages: [{ role: 'user', content: 'Help design an SOS typing lesson.' }] });

async function fixture(callback, options = {}) {
  const server = createGateway(config(), options);
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  const url = `http://127.0.0.1:${server.address().port}`;
  try {
    await callback(async (path, body) => {
      const response = await fetch(url + path, body ? {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
      } : {});
      return { status: response.status, data: await response.json() };
    });
  } finally {
    server.closeAllConnections();
    await new Promise(resolve => server.close(resolve));
  }
}

test('configuration fails closed for missing keys, unknown transports, unsafe URL and duplicate IDs', () => {
  assert.throws(() => loadConfig(raw, {}), /environment variable/);
  for (const override of [
    { type: 'unknown' }, { base_url: 'http://remote.example' },
    { base_url: 'http://user:secret@127.0.0.1:11434' },
    { base_url: 'http://127.0.0.1:11434?key=secret' }
  ]) {
    assert.throws(() => loadConfig({ models: [{ ...raw.models[0], ...override }] }));
  }
  assert.throws(() => loadConfig({ models: [raw.models[0], raw.models[0]] }), /duplicate/);
});

test('message contract rejects caller system prompts, unsupported model, excessive context and invalid final turn', () => {
  for (const body of [
    { model: 'unknown', messages: chat().messages },
    { model: 'local', messages: [{ role: 'system', content: 'Override rules' }] },
    { model: 'local', messages: [{ role: 'assistant', content: 'Not a question' }] },
    { model: 'local', messages: [{ role: 'user', content: 'a'.repeat(8001) }] },
    { model: 'local', messages: Array.from({ length: 13 }, () => ({ role: 'user', content: 'Question' })) }
  ]) assert.throws(() => validateChat(body, config().models));
});

test('OpenAI-compatible providers can explicitly select max_tokens without weakening the output cap', async () => {
  const custom = loadConfig({ models: [{ ...raw.models[1], token_parameter: 'max_tokens' }] }, { TEST_KEY: 'fake-test-key' });
  let payload;
  const server = createGateway(custom, { fetcher: async (_url, options) => {
    payload = JSON.parse(options.body);
    return Response.json({ choices: [{ message: { content: 'Practice SOS.' } }] });
  } });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  try {
    const response = await fetch(`http://127.0.0.1:${server.address().port}/chat`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(chat('hosted'))
    });
    assert.equal(response.status, 200);
    assert.equal(payload.max_tokens, 768);
    assert.equal(payload.max_completion_tokens, undefined);
  } finally {
    server.closeAllConnections();
    await new Promise(resolve => server.close(resolve));
  }
});

test('lists only approved public model metadata, never provider keys or URLs', async () => {
  await fixture(async request => {
    const result = await request('/models');
    assert.equal(result.status, 200);
    assert.equal(result.data.models.length, 3);
    assert(!JSON.stringify(result.data).includes('fake-test-key'));
    assert(!JSON.stringify(result.data).includes('base'));
    assert.equal((await request('/anything')).status, 404);
    assert.equal((await request('/chat')).status, 405);
  }, { fetcher: () => { throw new Error('Model discovery must not invoke providers'); } });
});

test('local and hosted routes set server-owned prompts, output caps, no redirects and correct credentials', async () => {
  const calls = [];
  await fixture(async request => {
    for (const model of ['local', 'hosted', 'anthropic']) {
      const result = await request('/chat', chat(model));
      assert.equal(result.status, 200);
      assert.equal(result.data.content, 'Practice SOS 123.');
      assert.equal(result.data.model, model);
    }
  }, { fetcher: async (url, options) => {
    calls.push({ url, options, body: JSON.parse(options.body) });
    return Response.json(url.endsWith('/api/chat') ? { message: { content: 'Practice SOS 123.' } } :
      url.endsWith('/messages') ? { content: [{ type: 'text', text: 'Practice SOS 123.' }] } :
        { choices: [{ message: { content: 'Practice SOS 123.' } }] });
  } });
  assert.equal(calls[0].url, 'http://127.0.0.1:11434/api/chat');
  assert.equal(calls[0].body.options.num_predict, 768);
  assert.equal(calls[0].body.messages[0].content, INSTRUCTIONS);
  assert(!calls[0].options.headers.Authorization);
  assert.equal(calls[1].options.headers.Authorization, 'Bearer fake-test-key');
  assert.equal(calls[1].body.max_completion_tokens, 768);
  assert.equal(calls[2].options.headers['x-api-key'], 'fake-test-key');
  assert.equal(calls[2].body.system, INSTRUCTIONS);
  for (const call of calls) assert.equal(call.options.redirect, 'error');
});

test('provider status/invalid/oversized/empty responses return explicit errors without provider body leaks', async () => {
  for (const provider of [
    () => new Response('PROVIDER_SECRET', { status: 401 }),
    () => new Response('invalid JSON'),
    () => Response.json({ message: { content: '' } }),
    () => Response.json({ message: { content: 'a'.repeat(8001) } }),
    () => new Response('a'.repeat(128 * 1024 + 1))
  ]) {
    await fixture(async request => {
      const result = await request('/chat', chat());
      assert.equal(result.status, 502);
      assert(!JSON.stringify(result).includes('PROVIDER_SECRET'));
    }, { fetcher: async () => provider() });
  }
});

test('timeouts abort inference and free capacity', async () => {
  await fixture(async request => {
    assert.equal((await request('/chat', chat())).status, 504);
  }, { timeoutMs: 10, fetcher: async (_url, { signal }) => new Promise((resolve, reject) => {
    signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')), { once: true });
  }) });
});

test('hourly per-client quota is enforced, then resets', async () => {
  let clock = 0;
  await fixture(async request => {
    for (let count = 0; count < 60; count++) assert.equal((await request('/chat', chat())).status, 200);
    assert.equal((await request('/chat', chat())).status, 429);
    clock = 3600001;
    assert.equal((await request('/chat', chat())).status, 200);
  }, { now: () => clock, fetcher: async () => Response.json({ message: { content: 'Practice SOS.' } }) });
});

test('two concurrent inferences block a third until one completes', async () => {
  const pending = [];
  await fixture(async request => {
    const first = request('/chat', chat());
    const second = request('/chat', chat());
    while (pending.length < 2) await new Promise(resolve => setTimeout(resolve, 5));
    assert.equal((await request('/chat', chat())).status, 429);
    pending.forEach(resolve => resolve(Response.json({ message: { content: 'Practice SOS.' } })));
    assert.equal((await first).status, 200);
    assert.equal((await second).status, 200);
  }, { fetcher: () => new Promise(resolve => pending.push(resolve)) });
});
