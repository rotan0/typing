let currentLessonId = null;
let startTime = null;
let lessons = [];
const typingArea = document.getElementById('typing-area');
const lessonList = document.getElementById('lesson-list');
const startLessonBtn = document.getElementById('start-lesson');
const submitBtn = document.getElementById('submit-btn');
const accuracyVal = document.getElementById('accuracy-val');
const speedVal = document.getElementById('speed-val');
const status = document.getElementById('app-status');
const base = document.querySelector('meta[name="app-base"]').content;
const csrf = document.querySelector('meta[name="csrf-token"]').content;

function highlightKey(key) {
  const el = document.querySelector(`[data-key="${key}"]`);
  if (!el) return;
  el.classList.add('active');
  setTimeout(() => el.classList.remove('active'), 100);
}

typingArea.addEventListener('keydown', (event) => {
  if (!currentLessonId || event.ctrlKey || event.metaKey || event.altKey) return;
  const key = event.key.toUpperCase();
  if (/^[A-Z0-9 ]$/.test(key)) {
    highlightKey(key);
    playKeySound(true);
  }
});
typingArea.addEventListener('input', () => {
  if (currentLessonId && !startTime) startTime = performance.now();
});

async function api(path, options) {
  const response = await fetch(`${base}/api/${path}`, options);
  const data = await response.json();
  if (!response.ok || data.error) throw new Error(data.error || `Request failed (${response.status}).`);
  return data;
}

async function loadLessons() {
  try {
    lessons = await api('lessons.php');
    lessonList.replaceChildren();
    lessons.forEach(lesson => {
      const option = document.createElement('option');
      option.value = lesson.id;
      option.textContent = `[L${lesson.level}] ${lesson.name}`;
      lessonList.appendChild(option);
    });
  } catch (error) {
    status.textContent = error.message;
  }
}

startLessonBtn.addEventListener('click', () => {
  const lesson = lessons.find(item => Number(item.id) === Number(lessonList.value));
  if (!lesson) return;
  currentLessonId = Number(lesson.id);
  document.getElementById('lesson-text').textContent = lesson.expected_sequence;
  typingArea.value = '';
  startTime = null;
  status.textContent = '';
  typingArea.focus();
});

submitBtn.addEventListener('click', async () => {
  if (!currentLessonId) return;
  submitBtn.disabled = true;
  try {
    const data = await api('submit.php', {
      method: 'POST',
      body: new URLSearchParams({
        csrf_token: csrf,
        lesson_id: currentLessonId,
        raw_input: typingArea.value,
        duration_ms: startTime ? Math.round(performance.now() - startTime) : 0
      })
    });
    accuracyVal.textContent = (data.accuracy * 100).toFixed(1) + '%';
    speedVal.textContent = data.duration_ms;
    status.textContent = '';
    playKeySound(data.accuracy >= 0.9);
  } catch (error) {
    status.textContent = error.message;
  } finally {
    submitBtn.disabled = false;
  }
});
loadLessons();
