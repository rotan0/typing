<?php
require_once __DIR__ . '/../config/bootstrap.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        check_csrf();
        if (($_POST['action'] ?? '') === 'logout') {
            $_SESSION = [];
            session_regenerate_id(true);
            redirect_to('account.php');
        }
        account_action($_POST['action'] ?? '', $_POST['username'] ?? null, $_POST['password'] ?? null);
        redirect_to('studio.php');
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Account request failed: ' . $exception->getMessage());
        $error = $exception->getMessage() === 'Invalid form token. Reload the page and try again.'
            ? $exception->getMessage() : 'Account request failed. Ask the site administrator to check the server log and run the database migration.';
    }
}
$user = current_user();
?>
<!DOCTYPE html>
<html lang="<?=h($lang_code)?>" dir="<?=language_direction($lang_code)?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h(t($strings, 'login'))?></title>
  <link rel="icon" type="image/gif" href="<?=h(app_url('assets/logo.gif'))?>">
  <link rel="stylesheet" href="<?=h(app_url('assets/css/style.css'))?>">
</head>
<body>
<?php require __DIR__ . '/../config/language_controls.php'; ?>
<main class="wizard">
<h1><?=h(t($strings, 'app_title'))?></h1>
<?php if ($error): ?><p role="alert" class="error"><?=h($error)?></p><?php endif; ?>
<?php if ($user): ?>
<p><?=h($user['username'])?></p>
<a href="<?=h(app_url())?>"><?=h(t($strings, 'start_lesson'))?></a>
<a href="<?=h(app_url('studio.php'))?>"><?=h(t($strings, 'studio'))?></a>
<form method="post">
  <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
  <button name="action" value="logout"><?=h(t($strings, 'logout'))?></button>
</form>
<?php else: ?>
<p><?=h(t($strings, 'account_help'))?></p>
<form method="post">
  <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
  <label><?=h(t($strings, 'username'))?><input name="username" required minlength="3" maxlength="64" pattern="[A-Za-z0-9_]+" autocomplete="username"></label>
  <label><?=h(t($strings, 'password'))?><input type="password" name="password" required minlength="12" maxlength="72" autocomplete="current-password"></label>
  <button name="action" value="login"><?=h(t($strings, 'login'))?></button>
  <button name="action" value="register"><?=h(t($strings, 'register'))?></button>
</form>
<?php endif; ?>
</main>
</body>
</html>
