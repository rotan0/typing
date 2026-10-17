<header class="site-header">
<a class="brand" href="<?=h(app_url())?>">
  <img src="<?=h(app_url('assets/logo.gif'))?>" alt="" width="48" height="48">
  <span><?=h(t($strings, 'app_title'))?></span>
</a>
<aside class="language-controls" aria-label="<?=h(t($strings, 'language'))?>">
  <form method="get" class="notranslate" translate="no">
    <label for="language"><?=h(t($strings, 'language'))?></label>
    <select id="language" name="lang">
      <?php foreach (available_languages() as $code => $name): ?>
      <option value="<?=h($code)?>" <?=$code === $lang_code ? 'selected' : ''?>><?=h($name)?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit"><?=h(t($strings, 'apply_language'))?></button>
  </form>
  <section aria-label="<?=h(t($strings, 'translate_backup'))?>">
    <strong><?=h(t($strings, 'translate_backup'))?></strong>
    <div id="google_translate_element"></div>
    <a href="https://translate.google.com/" target="_blank" rel="noopener noreferrer">Google Translate</a>
    <p id="translate-status" role="status"
       data-loading="<?=h(t($strings, 'translate_loading'))?>"
       data-unavailable="<?=h(t($strings, 'translate_unavailable'))?>"><?=h(t($strings, 'translate_loading'))?></p>
    <noscript><?=h(t($strings, 'translate_unavailable'))?></noscript>
  </section>
</aside>
</header>
<script src="<?=h(app_url('assets/js/translate.js'))?>" defer></script>
