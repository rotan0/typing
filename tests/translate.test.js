const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'translate.js'), 'utf8');
function environment(online = true) {
  const status = {
    textContent: '',
    dataset: { loading: 'loading', unavailable: 'unavailable' }
  };
  const widget = { querySelector: () => null };
  const scripts = [];
  const timers = new Map();
  let nextTimer = 0;
  const context = {
    console,
    navigator: { onLine: online },
    document: {
      documentElement: { lang: 'ar' },
      head: { appendChild: script => scripts.push(script) },
      getElementById: id => id === 'translate-status' ? status : widget,
      createElement: () => ({})
    },
    setTimeout: fn => { timers.set(++nextTimer, fn); return nextTimer; },
    clearTimeout: id => timers.delete(id),
    MutationObserver: class { observe() {} disconnect() {} }
  };
  context.window = context;
  vm.runInNewContext(source, context);
  return { context, status, scripts, timers, widget };
}

const offline = environment(false);
assert.equal(offline.scripts.length, 0);
assert.equal(offline.status.textContent, 'unavailable');

const blocked = environment();
assert.equal(blocked.scripts.length, 1);
assert.equal(blocked.scripts[0].src, 'https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit');
blocked.scripts[0].onerror();
assert.equal(blocked.status.textContent, 'unavailable');

const timeout = environment();
[...timeout.timers.values()][0]();
assert.equal(timeout.status.textContent, 'unavailable');

const missing = environment();
missing.context.googleTranslateElementInit();
assert.equal(missing.status.textContent, 'unavailable');

const success = environment();
let options;
success.context.google = { translate: { TranslateElement: function (config, id) {
  options = config;
  assert.equal(id, 'google_translate_element');
  success.widget.querySelector = () => ({});
} } };
success.context.googleTranslateElementInit();
assert.equal(options.pageLanguage, 'ar');
assert.equal(options.autoDisplay, false);
assert.equal(success.status.textContent, '');

const restricted = environment();
restricted.context.google = { translate: { TranslateElement: function () {} } };
restricted.context.googleTranslateElementInit();
[...restricted.timers.values()][0]();
assert.equal(restricted.status.textContent, 'unavailable');
console.log('PASS: offline, script blocked, timeout, missing callback API, successful widget, and Google-restricted widget');
