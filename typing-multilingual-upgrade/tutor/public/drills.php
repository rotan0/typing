<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/drills.php';
$user = require_user();
$catalog = drill_catalog();
$ui = $catalog['languages'][$lang_code]['ui'];
?>
<!DOCTYPE html>
<html lang="<?=h($lang_code)?>" dir="<?=language_direction($lang_code)?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?=h(csrf_token())?>">
  <meta name="app-base" content="<?=h(app_base())?>">
  <title><?=h($ui['title'])?></title>
  <link rel="icon" type="image/gif" href="<?=h(app_url('assets/logo.gif'))?>">
  <link rel="stylesheet" href="<?=h(app_url('assets/css/style.css'))?>">
</head>
<body>
<?php require __DIR__ . '/../config/language_controls.php'; ?>
<main class="practice drill-engine">
  <header class="practice-header">
    <h1><?=h($ui['title'])?></h1>
    <nav>
      <a href="<?=h(app_url())?>"><?=h($ui['morse'])?></a>
      <a href="<?=h(app_url('studio.php'))?>"><?=h($ui['studio'])?></a>
      <a href="<?=h(app_url('account.php'))?>"><?=h($user['username'])?> / <?=h(t($strings, 'logout'))?></a>
    </nav>
  </header>
  <p><?=h($ui['help'])?></p>
  <p id="drill-status" role="alert"></p>
  <form id="drill-settings" class="drill-settings">
    <label><?=h($ui['language'])?>
      <select name="language" id="drill-language">
      <?php foreach ($catalog['languages'] as $code => $entry): ?>
        <option value="<?=h($code)?>" <?=$lang_code === $code ? 'selected' : ''?>><?=h($entry['name'])?></option>
      <?php endforeach; ?>
      </select>
    </label>
    <label><?=h($ui['layout'])?>
      <select name="layout" id="drill-layout">
      <?php foreach ($catalog['layouts'] as $id => $entry): ?>
        <option value="<?=h($id)?>" <?=$id === 'unrestricted' ? 'selected' : ''?>><?=h($entry['name'])?></option>
      <?php endforeach; ?>
      </select>
    </label>
    <label><?=h($ui['level'])?>
      <select name="level" id="drill-level"><?php for ($level = 1; $level <= 5; $level++): ?><option><?=$level?></option><?php endfor; ?></select>
    </label>
    <label class="drill-custom"><?=h($ui['custom'])?>
      <textarea name="custom_words" id="drill-custom" rows="2" maxlength="1000" spellcheck="false"></textarea>
    </label>
    <button id="drill-generate" disabled><?=h($ui['start'])?></button>
  </form>
  <p id="drill-keys" class="notranslate" translate="no"></p>
  <p id="drill-empty"><?=h($ui['empty'])?></p>
  <section id="drill-practice" hidden>
    <div class="drill-stats" aria-live="off">
      <p><?=h($ui['progress'])?> <strong id="drill-progress">1 / 10</strong></p>
      <p><?=h($ui['accuracy'])?> <strong id="drill-accuracy">—</strong></p>
      <p><?=h($ui['wpm'])?> <strong id="drill-wpm">—</strong></p>
    </div>
    <p id="drill-active-settings" class="notranslate" translate="no"></p>
    <ol id="drill-lines" class="notranslate drill-lines" translate="no"></ol>
    <p id="drill-hints" class="notranslate" translate="no"></p>
    <label for="drill-input"><?=h(t($strings, 'typing_area'))?></label>
    <textarea id="drill-input" class="notranslate" translate="no" rows="3" spellcheck="false" autocomplete="off" autocapitalize="off" autocorrect="off" maxlength="1000"></textarea>
    <div class="practice-footer">
      <button type="button" id="drill-submit"><?=h($ui['submit'])?></button>
      <span id="drill-feedback" role="status"></span>
    </div>
    <p><?=h($ui['saved'])?></p>
    <div id="drill-complete" hidden>
      <p id="drill-outcome" role="status"></p>
      <p id="drill-focus" class="notranslate" translate="no"></p>
      <button type="button" id="drill-repeat"><?=h($ui['repeat'])?></button>
      <button type="button" id="drill-next"><?=h($ui['next'])?></button>
    </div>
  </section>
  <section class="drill-coach">
    <h2><?=h($ui['tutor'])?></h2>
    <p><?=h($ui['offline'])?></p>
    <a href="<?=h(app_url('studio.php') . '?drill_coach=1')?>"><?=h($ui['coach'])?></a>
  </section>
  <h2><?=h($ui['history'])?></h2>
  <ol id="drill-history" class="notranslate" translate="no"></ol>
</main>
<script id="drill-catalog" type="application/json"><?=json_encode(['languages' => $catalog['languages'], 'layouts' => $catalog['layouts'], 'ui' => $ui], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)?></script>
<script src="<?=h(app_url('assets/js/drills.js'))?>" defer></script>
</body>
</html>
