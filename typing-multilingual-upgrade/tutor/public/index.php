<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/drills.php';
$user = require_user();
?>
<!DOCTYPE html>
<html lang="<?=h($lang_code)?>" dir="<?=language_direction($lang_code)?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?=h(csrf_token())?>">
  <meta name="app-base" content="<?=h(app_base())?>">
  <title><?=h(t($strings, 'app_title'))?></title>
  <link rel="icon" type="image/gif" href="<?=h(app_url('assets/logo.gif'))?>">
  <link rel="stylesheet" href="<?=h(app_url('assets/css/style.css'))?>">
</head>
<body>
  <?php require __DIR__ . '/../config/language_controls.php'; ?>
  <main class="practice">
  <header class="practice-header">
  <h1><?=h(t($strings, 'app_title'))?></h1>
  <nav>
    <a href="<?=h(app_url('drills.php'))?>"><?=h(drill_catalog()['languages'][$lang_code]['ui']['title'])?></a>
    <a href="<?=h(app_url('studio.php'))?>"><?=h(t($strings, 'studio'))?></a>
    <a href="<?=h(app_url('account.php'))?>"><?=h($user['username'])?> / <?=h(t($strings, 'logout'))?></a>
  </nav>
  </header>
  <p id="app-status" role="alert"></p>

  <div id="lesson-select">
    <label for="lesson-list"><?=h(t($strings, 'lesson'))?>:</label>
    <select id="lesson-list" class="notranslate" translate="no"></select>
    <button id="start-lesson"><?=h(t($strings, 'start_lesson'))?></button>
  </div>
  <p id="lesson-text" class="notranslate" translate="no" dir="ltr"></p>

  <div id="keyboard" class="notranslate" translate="no" dir="ltr">
    <div class="row">
      <span data-key="Q">Q</span><span data-key="W">W</span><span data-key="E">E</span>
      <span data-key="R">R</span><span data-key="T">T</span><span data-key="Y">Y</span>
      <span data-key="U">U</span><span data-key="I">I</span><span data-key="O">O</span>
      <span data-key="P">P</span>
    </div>
    <div class="row">
      <span data-key="A">A</span><span data-key="S">S</span><span data-key="D">D</span>
      <span data-key="F">F</span><span data-key="G">G</span><span data-key="H">H</span>
      <span data-key="J">J</span><span data-key="K">K</span><span data-key="L">L</span>
    </div>
    <div class="row">
      <span data-key="Z">Z</span><span data-key="X">X</span><span data-key="C">C</span>
      <span data-key="V">V</span><span data-key="B">B</span><span data-key="N">N</span>
      <span data-key="M">M</span>
    </div>
  </div>

  <textarea id="typing-area" class="notranslate" translate="no" dir="ltr" aria-label="<?=h(t($strings, 'typing_area'))?>" rows="4" cols="50" spellcheck="false" autocomplete="off" autocapitalize="off" autocorrect="off"></textarea>
  <div class="practice-footer">
  <button id="submit-btn"><?=h(t($strings, 'submit'))?></button>

  <div id="result">
    <p><?=h(t($strings, 'accuracy'))?>: <span id="accuracy-val">-</span></p>
    <p><?=h(t($strings, 'speed'))?> (ms): <span id="speed-val">-</span></p>
  </div>
  </div>
  </main>

  <script src="<?=h(app_url('assets/js/audio.js'))?>"></script>
  <script src="<?=h(app_url('assets/js/app.js'))?>"></script>
</body>
</html>
