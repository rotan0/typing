(() => {
  const base = document.querySelector('meta[name="app-base"]').content;
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const userId = Number(document.querySelector('meta[name="user-id"]').content);
  const status = document.getElementById('studio-status');
  const conversations = document.getElementById('conversation-list');
  const ownedLessons = document.getElementById('owned-lessons');
  const editor = document.getElementById('lesson-editor');
  const publish = document.getElementById('publish');
  const unpublish = document.getElementById('unpublish');
  const assistantButton = document.getElementById('ask-assistant');
  const modelSelect = document.getElementById('assistant-model');
  const useAssistantText = document.getElementById('use-assistant-text');
  let lessons = [];
  let assistantReady = false;
  let suggestedText = '';

  async function api(path, fields) {
    const options = fields ? { method: 'POST', body: new URLSearchParams({ ...fields, csrf_token: csrf }) } : {};
    const response = await fetch(`${base}/api/${path}`, options);
    const data = await response.json();
    if (!response.ok || data.error) throw new Error(data.error || `Request failed (${response.status}).`);
    return data;
  }
  function report(error) { status.textContent = error.message; }
  function option(value, label) {
    const element = document.createElement('option');
    element.value = value;
    element.textContent = label;
    return element;
  }
  function formAction(id, action) {
    document.getElementById(id).addEventListener('submit', async event => {
      event.preventDefault();
      const button = event.currentTarget.querySelector('button');
      button.disabled = true;
      status.textContent = '';
      try { await action(event.currentTarget); } catch (error) { report(error); }
      finally { button.disabled = false; }
    });
  }
  async function showMessages() {
    const list = document.getElementById('messages');
    list.replaceChildren();
    suggestedText = '';
    useAssistantText.disabled = true;
    if (!conversations.value) return;
    const data = await api(`conversations.php?id=${encodeURIComponent(conversations.value)}`);
    data.messages.forEach(message => {
      const item = document.createElement('li');
      item.textContent = `${message.role}: ${message.content}`;
      list.appendChild(item);
      if (message.role === 'assistant') {
        const match = message.content.match(/```(?:text)?\s*\n([A-Z0-9 \r\n]+)\n```/);
        suggestedText = match ? match[1].replace(/\s+/g, ' ').trim() : '';
      }
    });
    useAssistantText.disabled = !suggestedText || suggestedText.length > 4000;
  }
  async function refreshConversations(selected) {
    const rows = await api('conversations.php');
    conversations.replaceChildren(...rows.map(row => option(row.id, row.title)));
    if (selected) conversations.value = selected;
    await showMessages();
  }
  function editSelected() {
    const lesson = lessons.find(row => String(row.id) === ownedLessons.value);
    editor.reset();
    if (lesson) {
      ['name', 'description', 'level', 'expected_sequence'].forEach(key => { editor.elements[key].value = lesson[key]; });
    }
    publish.disabled = !lesson || Number(lesson.is_public) === 1;
    unpublish.disabled = !lesson || Number(lesson.is_public) === 0;
    const text = document.getElementById('sharing-status');
    text.textContent = lesson ? `${Number(lesson.is_public) ? text.dataset.public : text.dataset.private}: ${lesson.name}` : '';
  }
  async function refreshLessons(selected = '') {
    lessons = (await api('lessons.php')).filter(row => Number(row.owner_id) === userId);
    const first = ownedLessons.options[0];
    ownedLessons.replaceChildren(first, ...lessons.map(row => option(row.id, row.name)));
    ownedLessons.value = String(selected);
    editSelected();
  }
  conversations.addEventListener('change', () => showMessages().catch(report));
  ownedLessons.addEventListener('change', editSelected);
  formAction('new-conversation', async form => {
    const created = await api('conversations.php', { action: 'create', title: form.elements.title.value });
    form.reset();
    await refreshConversations(created.id);
  });
  formAction('save-note', async form => {
    if (!conversations.value) throw new Error('Create a design conversation first.');
    await api('conversations.php', { action: 'note', conversation_id: conversations.value, content: form.elements.content.value });
    form.reset();
    await showMessages();
  });
  document.getElementById('assistant-chat').addEventListener('submit', async event => {
    event.preventDefault();
    if (!assistantReady) return;
    const form = event.currentTarget;
    const conversationId = conversations.value;
    assistantButton.disabled = true;
    conversations.disabled = true;
    document.querySelector('#new-conversation button').disabled = true;
    status.textContent = '';
    try {
      if (!conversationId) throw new Error('Create a design conversation first.');
      await api('assistant.php', {
        model: modelSelect.value, conversation_id: conversationId,
        question: form.elements.question.value, consent: form.elements.consent.checked ? '1' : '0'
      });
      form.elements.question.value = '';
      form.elements.consent.checked = false;
      await showMessages();
    } catch (error) {
      report(error);
    } finally {
      conversations.disabled = false;
      document.querySelector('#new-conversation button').disabled = false;
      assistantButton.disabled = !assistantReady;
    }
  });
  useAssistantText.addEventListener('click', () => {
    if (!suggestedText || suggestedText.length > 4000) return;
    ownedLessons.value = '';
    editSelected();
    editor.elements.expected_sequence.value = suggestedText;
    editor.elements.name.focus();
  });
  formAction('lesson-editor', async form => {
    const fields = Object.fromEntries(new FormData(form));
    const saved = await api('lessons.php', { ...fields, action: 'save', id: ownedLessons.value });
    await refreshLessons(saved.id);
  });
  async function share(value) {
    publish.disabled = true;
    unpublish.disabled = true;
    try {
      await api('lessons.php', { action: 'publish', id: ownedLessons.value, is_public: value });
      await refreshLessons(ownedLessons.value);
    } catch (error) { report(error); editSelected(); }
  }
  publish.addEventListener('click', () => share('1'));
  unpublish.addEventListener('click', () => share('0'));
  formAction('import-profile', async form => {
    const file = form.elements.profile.files[0];
    if (file.size > 4 * 1024 * 1024) throw new Error('Profile JSON must be at most 4 MiB.');
    const result = await api('profile.php', { profile: await file.text() });
    await Promise.all([refreshLessons(), refreshConversations()]);
    status.textContent = result.message;
  });
  if (document.getElementById('import-bundled')) {
    formAction('import-bundled', async () => {
      const result = await api('profile.php', { bundled: '1' });
      await Promise.all([refreshLessons(), refreshConversations()]);
      status.textContent = result.message;
    });
  }
  // Availability requests contain no private conversation or profile data.
  fetch(`${base}/api/assistant.php`)
    .then(async response => {
      const data = await response.json();
      const panel = document.getElementById('assistant-status');
      if (!response.ok || !data.available) {
        panel.title = data.error || 'Assistant unavailable.';
        if (response.status !== 503) panel.textContent = data.error || `Assistant unavailable (${response.status}).`;
        return;
      }
      if (!Array.isArray(data.models) || !data.models.length) throw new Error('No typing models are available.');
      modelSelect.replaceChildren(...data.models.map(model => option(model.id, model.label)));
      modelSelect.disabled = false;
      assistantReady = true;
      assistantButton.disabled = false;
      panel.textContent = panel.dataset.ready;
    }).catch(report);
  Promise.all([refreshLessons(), refreshConversations()]).catch(report);
})();
