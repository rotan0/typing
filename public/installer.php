<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/lang.php';
require_once __DIR__ . '/../config/setup.php';
require_once __DIR__ . '/../config/lessons.php';
require_once __DIR__ . '/../config/migrations.php';

start_app_session();
require_setup_access();
$lang_code = selected_language();
$strings = load_lang($lang_code);
$error = '';
$settings = $_SESSION['setup_database'] ?? database_settings();
$step = $_SESSION['setup_step'] ?? 1;
$checks = [
    'PHP >= 7.4 (' . PHP_VERSION . ')' => version_compare(PHP_VERSION, '7.4.0', '>='),
    'PDO MySQL extension' => extension_loaded('pdo_mysql'),
    'JSON extension' => extension_loaded('json'),
    'Session extension' => extension_loaded('session'),
    'ZIP extension (personal downloads)' => extension_loaded('zip'),
    'Writable configuration folder' => is_writable(__DIR__ . '/../config'),
];
$ready = !in_array(false, $checks, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && setup_complete()) {
    try {
        check_csrf();
        if (($_POST['action'] ?? '') !== 'upgrade') {
            throw new RuntimeException('Setup is locked. Only the database upgrade action is allowed.');
        }
        migrate_schema(db());
        install_published_lessons(db());
        $_SESSION['upgrade_complete'] = true;
        redirect_to('installer.php');
    } catch (Throwable $exception) {
        error_log('Database upgrade failed: ' . $exception->getMessage());
        $error = $exception->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !setup_complete()) {
    try {
        check_csrf();
        $action = $_POST['action'] ?? '';
        if ($action === 'checks' && $step === 1) {
            if (!$ready) {
                throw new RuntimeException('Resolve the failed checks using install.bat, then retry.');
            }
            $step = 2;
        } elseif ($action === 'database' && $step === 2) {
            $settings = [
                'host' => trim((string) ($_POST['host'] ?? '')),
                'port' => filter_var($_POST['port'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]),
                'name' => trim((string) ($_POST['name'] ?? '')),
                'user' => trim((string) ($_POST['user'] ?? '')),
                'password' => (string) ($_POST['password'] ?? ''),
            ];
            if (!preg_match('/^[a-zA-Z0-9._:-]+$/D', $settings['host']) ||
                !preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $settings['name']) ||
                !$settings['port'] || $settings['user'] === '') {
                throw new RuntimeException('Enter a valid host, port (1-65535), database name (letters, digits, underscores), and user.');
            }
            database_connection($settings, false);
            $_SESSION['setup_database'] = $settings;
            $step = 3;
        } elseif ($action === 'install' && $step === 3 && $ready) {
            $pdo = database_connection($settings, false);
            $exists = $pdo->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
            $exists->execute([$settings['name']]);
            if (!$exists->fetchColumn()) {
                $pdo->exec("CREATE DATABASE `{$settings['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }
            $pdo = database_connection($settings);
            migrate_schema($pdo);
            generate_lessons($pdo);
            install_published_lessons($pdo);
            save_database_settings($settings);
            if (file_put_contents(__DIR__ . '/../config/installed.lock', date(DATE_ATOM), LOCK_EX) === false) {
                throw new RuntimeException('Cannot lock completed setup. Check config folder permissions.');
            }
            unset($_SESSION['setup_database'], $_SESSION['setup_step']);
        } elseif ($action === 'back' && $step > 1) {
            $step--;
        } else {
            throw new RuntimeException('Invalid wizard action. Reload the page and try again.');
        }
        if (!setup_complete()) {
            $_SESSION['setup_step'] = $step;
        }
        header('Location: ' . app_url('installer.php'));
        exit;
    } catch (Throwable $exception) {
        error_log('Setup failed: ' . $exception->getMessage());
        $error = $exception->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="<?=h($lang_code)?>" dir="<?=language_direction($lang_code)?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h(t($strings, 'installer_title'))?></title>
  <link rel="icon" type="image/gif" href="<?=h(app_url('assets/logo.gif'))?>">
  <link rel="stylesheet" href="<?=h(app_url('assets/css/style.css'))?>">
</head>
<body>
<?php require __DIR__ . '/../config/language_controls.php'; ?>
<main class="wizard">
<h1><?=h(t($strings, 'installer_title'))?></h1>
<?php if ($error !== ''): ?><p role="alert" class="error"><?=h($error)?></p><?php endif; ?>
<?php if (setup_complete()): ?>
  <h2>Setup complete</h2>
  <p>Database configured, tables created, and five starter lessons installed. Setup is locked.</p>
  <?php if (!empty($_SESSION['upgrade_complete'])): ?><p role="status">Database upgrade completed.</p><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <button name="action" value="upgrade">Upgrade existing account/lesson database</button>
  </form>
  <a href="<?=h(app_url('account.php'))?>"><?=h(t($strings, 'register'))?> / <?=h(t($strings, 'login'))?></a>
<?php else: ?>
<p>Step <?=$step?> of 3: <?=['', 'System checks', 'Database connection', 'Install and finish'][$step]?></p>
<form method="post">
<input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
<?php if ($step === 1): ?>
  <ul>
  <?php foreach ($checks as $label => $passed): ?>
    <li class="<?=$passed ? 'success' : 'error'?>"><?=h($label)?>: <?=$passed ? 'PASS' : 'FAIL'?></li>
  <?php endforeach; ?>
  </ul>
  <p>No Python, Composer, npm, or third-party application packages are needed.
     If a runtime is missing, run the Windows setup launcher <code>install.bat</code>.</p>
  <button name="action" value="checks" <?=$ready ? '' : 'disabled'?>>Next</button>
<?php elseif ($step === 2): ?>
  <p>The wizard will test the server connection before creating anything.
     Fresh local XAMPP defaults are root with an empty password; use your existing credentials otherwise.</p>
  <?php foreach (['host' => 'Database host', 'port' => 'Database port', 'name' => 'Database name', 'user' => 'Database user', 'password' => 'Database password'] as $field => $label): ?>
  <label><?=h($label)?><input name="<?=$field?>" type="<?=$field === 'password' ? 'password' : ($field === 'port' ? 'number' : 'text')?>"
    value="<?=h((string) $settings[$field])?>" <?=$field === 'password' ? 'autocomplete="new-password"' : 'required'?>></label>
  <?php endforeach; ?>
  <button name="action" value="back" formnovalidate>Back</button>
  <button name="action" value="database">Test connection and continue</button>
<?php else: ?>
  <p>Ready to create database <strong><?=h($settings['name'])?></strong> on
     <strong><?=h($settings['host'])?>:<?=h((string) $settings['port'])?></strong>,
     create missing tables, and generate five lessons without deleting existing progress.</p>
  <p>Your database user must have permission to create tables and insert/update lessons.
     On shared hosting, create the database in the host control panel first.
     Credentials are saved outside the web root.</p>
  <button name="action" value="back">Back</button>
  <button name="action" value="install">Install and finish</button>
<?php endif; ?>
</form>
<?php endif; ?>
</main>
</body>
</html>
