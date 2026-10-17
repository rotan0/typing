let audioCtx = null;

function initAudio() {
  if (!audioCtx) {
    audioCtx = new (window.AudioContext || window.webkitAudioContext)();
  }
}

function playTone(freq, durationMs) {
  initAudio();
  const osc = audioCtx.createOscillator();
  const gain = audioCtx.createGain();

  osc.type = 'sine';
  osc.frequency.value = freq;

  osc.connect(gain);
  gain.connect(audioCtx.destination);

  osc.start();
  gain.gain.setValueAtTime(1, audioCtx.currentTime);
  gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + durationMs / 1000);
  osc.stop(audioCtx.currentTime + durationMs / 1000);
}

function playKeySound(success) {
  if (success) {
    playTone(880, 80);
  } else {
    playTone(220, 200);
  }
}

