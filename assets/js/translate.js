(() => {
  const status = document.getElementById('translate-status');
  if (!status) return;

  let timer;
  function unavailable() {
    clearTimeout(timer);
    status.textContent = status.dataset.unavailable;
  }

  window.googleTranslateElementInit = () => {
    try {
      const TranslateElement = window.google?.translate?.TranslateElement;
      if (!TranslateElement) {
        unavailable();
        return;
      }
      // JSON catalogs supply the current source language; Morse input stays untranslated.
      new TranslateElement({
        pageLanguage: document.documentElement.lang || 'en',
        autoDisplay: false
      }, 'google_translate_element');
      // A loaded script does not guarantee that Google enabled the widget for this site.
      const widget = document.getElementById('google_translate_element');
      const observer = new MutationObserver(() => {
        if (widget.querySelector('select')) {
          clearTimeout(timer);
          status.textContent = '';
          observer.disconnect();
        }
      });
      observer.observe(widget, { childList: true, subtree: true });
      if (widget.querySelector('select')) {
        clearTimeout(timer);
        status.textContent = '';
        observer.disconnect();
      }
      setTimeout(() => observer.disconnect(), 15000);
    } catch (error) {
      console.error('Google Translate initialization failed:', error);
      unavailable();
    }
  };

  if (!navigator.onLine) {
    unavailable();
    return;
  }
  status.textContent = status.dataset.loading;
  timer = setTimeout(unavailable, 12000);
  const script = document.createElement('script');
  script.src = 'https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit';
  script.async = true;
  script.onerror = unavailable;
  document.head.appendChild(script);
})();
