(() => {
  'use strict';
  const catalog = JSON.parse(document.getElementById('drill-catalog').textContent);
  const ui = catalog.ui;
  const base = document.querySelector('meta[name="app-base"]').content;
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const form = document.getElementById('drill-settings');
  const input = document.getElementById('drill-input');
  const status = document.getElementById('drill-status');
  const submit = document.getElementById('drill-submit');
  const generate = document.getElementById('drill-generate');
  const repeat = document.getElementById('drill-repeat');
  const next = document.getElementById('drill-next');
  const lines = document.getElementById('drill-lines');
  let state = null;
  let started = null;
  let composing = false;
  let busy = false;
  let currentCharacters = [];
  let caret = null;

  async function api(fields) {
    const response = await fetch(`${base}/api/drills.php`, fields ?
      { method: 'POST', body: new URLSearchParams({ ...fields, csrf_token: csrf }) } : {});
    const data = await response.json();
    if (!response.ok || data.error) throw new Error(data.error || `Request failed (${response.status}).`);
    return data;
  }
  function keys() {
    const allowed = catalog.layouts[form.elements.layout.value].tiers.slice(0, Number(form.elements.level.value)).join('');
    const text = document.getElementById('drill-keys');
    text.textContent = `${ui.keys}: ${allowed || catalog.layouts.unrestricted.name}`;
    text.dir = catalog.languages[form.elements.language.value].direction;
    document.getElementById('drill-custom').dir = text.dir;
  }
  function positionCaret() {
    if (!caret || !currentCharacters.length) return;
    // Textarea selection offsets use UTF-16, whereas scoring uses Unicode code points.
    const index = Math.min(Array.from(input.value.slice(0, input.selectionStart)).length, currentCharacters.length);
    const span = currentCharacters[Math.min(index, currentCharacters.length - 1)];
    const bounds = span.getBoundingClientRect();
    const container = caret.parentElement.getBoundingClientRect();
    const rtl = input.dir === 'rtl';
    const atEnd = index === currentCharacters.length;
    const x = rtl ? (atEnd ? bounds.left : bounds.right) : (atEnd ? bounds.right : bounds.left);
    caret.style.height = `${bounds.height}px`;
    caret.style.transform = `translate(${x - container.left}px, ${bounds.top - container.top}px)`;
  }
  function live() {
    if (!state?.session || state.summary.complete || composing) return;
    const target = Array.from(state.session.lines[state.session.results.length]);
    const typed = Array.from(input.value);
    let correct = 0;
    typed.forEach((character, index) => { if (character === target[index]) correct++; });
    currentCharacters.forEach((span, index) => {
      span.className = index >= typed.length ? '' : (span.textContent === typed[index] ? 'correct' : 'incorrect');
    });
    const duration = started === null ? 0 : Math.max(1, performance.now() - started);
    document.getElementById('drill-accuracy').textContent = typed.length ? `${(correct / typed.length * 100).toFixed(1)}%` : '—';
    document.getElementById('drill-wpm').textContent = duration ? (typed.length * 12000 / duration).toFixed(1) : '—';
    positionCaret();
  }
  function render() {
    const session = state.session;
    document.getElementById('drill-empty').hidden = !!session;
    document.getElementById('drill-practice').hidden = !session;
    document.getElementById('drill-history').replaceChildren(...state.history.map(item => {
      const li = document.createElement('li');
      li.textContent = `${item.created_at} · ${catalog.languages[item.preferences.language].name} · ${catalog.layouts[item.preferences.layout].name} · ${ui.level} ${item.preferences.level} · ${item.summary.completed_drills}/10 · ${(item.summary.accuracy * 100).toFixed(1)}% · ${item.summary.wpm.toFixed(1)} ${ui.wpm}`;
      return li;
    }));
    if (!session) return;
    const index = session.results.length;
    const direction = catalog.languages[session.preferences.language].direction;
    document.getElementById('drill-active-settings').textContent = `${catalog.languages[session.preferences.language].name} · ${catalog.layouts[session.preferences.layout].name} · ${ui.level} ${session.preferences.level}`;
    input.dir = direction;
    input.lang = session.preferences.language;
    lines.dir = direction;
    lines.lang = session.preferences.language;
    currentCharacters = [];
    caret = null;
    lines.replaceChildren(...session.lines.map((line, number) => {
      const li = document.createElement('li');
      li.className = number < index ? 'drill-done' : number === index ? 'drill-current' : 'drill-pending';
      if (number === index) {
        li.setAttribute('aria-current', 'step');
        currentCharacters = Array.from(line, character => {
          const span = document.createElement('span');
          span.textContent = character;
          li.appendChild(span);
          return span;
        });
        caret = document.createElement('span');
        caret.className = 'typing-caret';
        caret.setAttribute('aria-hidden', 'true');
        li.appendChild(caret);
      } else {
        li.textContent = line;
        if (number < index) li.textContent += ` · ${(session.results[number].accuracy * 100).toFixed(1)}%`;
      }
      return li;
    }));
    const hints = [...new Set((session.lines[Math.min(index, 9)]).split(' '))]
      .filter(word => catalog.languages[session.preferences.language].glosses[word])
      .map(word => `${word} = ${catalog.languages[session.preferences.language].glosses[word]}`);
    document.getElementById('drill-hints').textContent = hints.length ? `${ui.meaning}: ${hints.join(' · ')}` : '';
    document.getElementById('drill-progress').textContent = `${Math.min(index + 1, 10)} / 10`;
    document.getElementById('drill-complete').hidden = !state.summary.complete;
    document.getElementById('drill-outcome').textContent = state.summary.passed ?
      (session.preferences.level === 5 ? ui.finished : ui.passed) : ui.retry;
    document.getElementById('drill-focus').textContent = `${ui.focus}: ${Object.keys(state.summary.weak).slice(0, 20).join(' ')}`;
    next.hidden = !state.summary.passed || session.preferences.level === 5;
    const previous = session.results[index - 1];
    document.getElementById('drill-feedback').textContent = previous ? `${ui.accuracy}: ${(previous.accuracy * 100).toFixed(1)}% · ${previous.wpm.toFixed(1)} ${ui.wpm}` : ui.ready;
    document.getElementById('drill-accuracy').textContent = state.summary.complete ? `${(state.summary.accuracy * 100).toFixed(1)}%` : '—';
    document.getElementById('drill-wpm').textContent = state.summary.complete ? state.summary.wpm.toFixed(1) : '—';
    input.value = '';
    started = null;
    input.disabled = state.summary.complete;
    submit.disabled = state.summary.complete;
    positionCaret();
  }
  async function perform(fields, focus = true) {
    if (busy) return;
    busy = true;
    [submit, generate, repeat, next].forEach(button => { button.disabled = true; });
    input.disabled = true;
    status.textContent = ui.busy;
    try {
      const result = await api(fields);
      state = result;
      if (result.preferences) {
        Object.entries(result.preferences).forEach(([key, value]) => { form.elements[key].value = value; });
        keys();
      }
      render();
      lines.querySelector('[aria-current="step"]')?.scrollIntoView({ block: 'nearest' });
      status.textContent = '';
      if (focus && state.session && !state.summary.complete) input.focus();
    } catch (error) {
      // Keep the current input and timer intact so a failed save can be retried.
      status.textContent = error.message;
    } finally {
      busy = false;
      generate.disabled = false;
      repeat.disabled = false;
      next.disabled = false;
      input.disabled = !!state?.summary?.complete || !state?.session;
      submit.disabled = input.disabled;
      positionCaret();
    }
  }
  form.addEventListener('change', keys);
  form.addEventListener('submit', event => {
    event.preventDefault();
    if (composing) { status.textContent = ui.composition; return; }
    perform({ action: 'start', ...Object.fromEntries(new FormData(form)) });
  });
  input.addEventListener('compositionstart', () => {
    composing = true;
    if (started === null) started = performance.now();
  });
  input.addEventListener('compositionend', () => {
    composing = false;
    live();
  });
  input.addEventListener('input', event => {
    if (started === null) started = performance.now();
    if (!event.isComposing) live();
  });
  ['click', 'select', 'keyup'].forEach(event => input.addEventListener(event, positionCaret));
  window.addEventListener('resize', positionCaret);
  submit.addEventListener('click', () => {
    if (composing) { status.textContent = ui.composition; return; }
    if (!state?.session) return;
    if (started === null) { status.textContent = ui.ready; return; }
    perform({ action: 'submit', session_id: state.session_id, index: state.session.results.length,
      input: input.value, duration_ms: Math.max(1, Math.round(performance.now() - started)) });
  });
  repeat.addEventListener('click', () => perform({ action: 'repeat', session_id: state.session_id }));
  next.addEventListener('click', () => perform({ action: 'advance', session_id: state.session_id }));
  setInterval(() => { if (started !== null && !busy && !state?.summary?.complete) live(); }, 250);
  keys();
  perform(null, false);
})();
