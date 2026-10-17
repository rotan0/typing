<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/lessons.php';
require_local_setup();
$user = require_user();
$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        check_csrf();
        if (($_POST['action'] ?? '') !== 'rebuild_lessons') {
            throw new RuntimeException('Invalid maintenance action.');
        }
        generate_lessons(db());
        $message = t($strings, 'lessons_generated');
    } catch (Throwable $exception) {
        error_log('Lesson rebuild failed: ' . $exception->getMessage());
        $error = $exception->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="<?=h($lang_code)?>" dir="<?=language_direction($lang_code)?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h(t($strings, 'dashboard'))?></title>
  <link rel="icon" type="image/gif" href="<?=h(app_url('assets/logo.gif'))?>">
  <link rel="stylesheet" href="<?=h(app_url('assets/css/style.css'))?>">
</head>
<body>
<?php require __DIR__ . '/../config/language_controls.php'; ?>
<h1><?=h(t($strings, 'maintenance'))?></h1>
<a href="<?=h(app_url())?>"><?=h(t($strings, 'app_title'))?></a>
<?php if ($message !== ''): ?><p role="status"><?=h($message)?></p><?php endif; ?>
<?php if ($error !== ''): ?><p role="alert" class="error"><?=h($error)?></p><?php endif; ?>
<form method="post">
  <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
  <button name="action" value="rebuild_lessons"><?=h(t($strings, 'rebuild_lessons'))?></button>
</form>
</body>
</html>
