<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/drills.php';
$user = require_user();
$coaching_question = '';
if (($_GET['drill_coach'] ?? '') === '1') {
    $drill = drill_state(db(), $user['id']);
    if ($drill['session']) {
        $preferences = $drill['session']['preferences'];
        $coaching_question = 'Help me practice touch typing while learning ' . drill_catalog()['languages'][$preferences['language']]['name'] .
            '. Explain in ' . $lang_code . '. Layout: ' . $preferences['layout'] . '; level: ' . $preferences['level'] .
            '. Completed drills: ' . $drill['summary']['completed_drills'] . '/10; aggregate accuracy: ' .
            round($drill['summary']['accuracy'] * 100, 1) . '%; normalized WPM: ' . round($drill['summary']['wpm'], 1) .
            '. Characters to improve: ' . implode(' ', array_slice(array_keys($drill['summary']['weak']), 0, 20)) .
            '. Explain technique and the meaning of these practice words: ' . $drill['session']['lines'][0] .
            '. Suggest a short learning plan. Do not save or publish anything.';
    }
}
?>
<!DOCTYPE html>
<html lang="<?=h($lang_code)?>" dir="<?=language_direction($lang_code)?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?=h(csrf_token())?>">
  <meta name="app-base" content="<?=h(app_base())?>">
  <meta name="user-id" content="<?=(int) $user['id']?>">
  <title><?=h(t($strings, 'studio'))?></title>
  <link rel="icon" type="image/gif" href="<?=h(app_url('assets/logo.gif'))?>">
  <link rel="stylesheet" href="<?=h(app_url('assets/css/style.css'))?>">
</head>
<body>
<?php require __DIR__ . '/../config/language_controls.php'; ?>
<main class="studio">
<h1><?=h(t($strings, 'studio'))?></h1>
<nav>
  <a href="<?=h(app_url('drills.php'))?>"><?=h(drill_catalog()['languages'][$lang_code]['ui']['title'])?></a>
  <a href="<?=h(app_url())?>"><?=h(t($strings, 'start_lesson'))?></a>
  <a href="<?=h(app_url('account.php'))?>"><?=h($user['username'])?> / <?=h(t($strings, 'logout'))?></a>
</nav>
<p id="studio-status" role="status"></p>
<section>
  <h2><?=h(t($strings, 'design_chat'))?></h2>
  <p id="assistant-status" role="status" data-ready="<?=h(t($strings, 'assistant_ready'))?>"><?=h(t($strings, 'assistant_unavailable'))?></p>
  <p><?=h(t($strings, 'private_chat_help'))?></p>
  <label for="conversation-list"><?=h(t($strings, 'design_chat'))?></label>
  <select id="conversation-list" class="notranslate" translate="no"></select>
  <form id="new-conversation">
    <label><?=h(t($strings, 'title'))?><input name="title" maxlength="128" class="notranslate" translate="no" required></label>
    <button><?=h(t($strings, 'new_design'))?></button>
  </form>
  <ol id="messages" class="notranslate" translate="no"></ol>
  <form id="save-note">
    <label><?=h(t($strings, 'design_note'))?><textarea name="content" maxlength="8000" rows="4" class="notranslate" translate="no" required></textarea></label>
    <button><?=h(t($strings, 'save_note'))?></button>
  </form>
  <form id="assistant-chat">
    <label><?=h(t($strings, 'assistant_model'))?><select id="assistant-model" name="model" class="notranslate" translate="no" required disabled></select></label>
    <label><?=h(t($strings, 'assistant_question'))?><textarea name="question" maxlength="4000" rows="3" class="notranslate" translate="no" required><?=h($coaching_question)?></textarea></label>
    <label><input type="checkbox" name="consent" value="1" required> <?=h(t($strings, 'assistant_consent'))?></label>
    <button id="ask-assistant" disabled><?=h(t($strings, 'ask_assistant'))?></button>
  </form>
  <button id="use-assistant-text" type="button" disabled><?=h(t($strings, 'use_assistant_text'))?></button>
</section>
<section>
  <h2><?=h(t($strings, 'my_lessons'))?></h2>
  <label for="owned-lessons"><?=h(t($strings, 'my_lessons'))?></label>
  <select id="owned-lessons" class="notranslate" translate="no"><option value=""><?=h(t($strings, 'new_lesson'))?></option></select>
  <form id="lesson-editor">
    <label><?=h(t($strings, 'title'))?><input name="name" maxlength="128" class="notranslate" translate="no" required></label>
    <label><?=h(t($strings, 'description'))?><textarea name="description" maxlength="8000" rows="3" class="notranslate" translate="no"></textarea></label>
    <label><?=h(t($strings, 'difficulty'))?><input type="number" name="level" min="1" max="10" value="1" required></label>
    <label><?=h(t($strings, 'practice_text'))?><textarea name="expected_sequence" class="notranslate" translate="no" dir="ltr" rows="4" maxlength="4000" required></textarea></label>
    <p><?=h(t($strings, 'morse_text_help'))?></p>
    <button><?=h(t($strings, 'save_lesson'))?></button>
  </form>
  <p id="sharing-status" class="notranslate" translate="no" data-private="<?=h(t($strings, 'lesson_private'))?>" data-public="<?=h(t($strings, 'lesson_published'))?>"></p>
  <p><?=h(t($strings, 'publish_help'))?></p>
  <button id="publish" type="button" disabled><?=h(t($strings, 'publish_lesson'))?></button>
  <button id="unpublish" type="button" disabled><?=h(t($strings, 'make_private'))?></button>
</section>
<section>
  <h2><?=h(t($strings, 'personal_download'))?></h2>
  <p><?=h(t($strings, 'download_help'))?></p>
  <form action="<?=h(app_url('export.php'))?>" method="post">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <button><?=h(t($strings, 'download_zip'))?></button>
  </form>
  <form id="import-profile">
    <label><?=h(t($strings, 'import_profile'))?><input type="file" name="profile" accept=".json,application/json" required></label>
    <button><?=h(t($strings, 'import_profile'))?></button>
  </form>
  <?php if (is_file(__DIR__ . '/../data/profile.json') && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)): ?>
  <form id="import-bundled"><button><?=h(t($strings, 'restore_bundled'))?></button></form>
  <?php endif; ?>
</section>
</main>
<script src="<?=h(app_url('assets/js/studio.js'))?>" defer></script>
</body>
</html>
