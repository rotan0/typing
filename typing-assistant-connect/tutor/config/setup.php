<?php
require_once __DIR__ . '/web.php';

function setup_complete() {
    return is_file(__DIR__ . '/installed.lock');
}

function require_installation() {
    if (!setup_complete()) {
        header('Location: ' . app_url('installer.php'));
        exit;
    }
}

function require_local_setup() {
    if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
        http_response_code(403);
        exit('Setup and maintenance are available only from this computer.');
    }
}

function require_setup_access() {
    if (in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
        return;
    }
    $file = __DIR__ . '/setup.key';
    if (!is_file($file)) {
        http_response_code(403);
        exit('Remote setup is locked. The site owner must create config/setup.key with a SHA-256 setup-token hash; see README.');
    }
    $contents = file_get_contents($file);
    if ($contents === false) {
        error_log('Cannot read the remote setup authorization hash.');
        http_response_code(503);
        exit('Cannot read setup authorization. Check the server log.');
    }
    $expected = trim($contents);
    $token = $_POST['setup_token'] ?? '';
    if (is_string($token) && $token !== '' && preg_match('/^[a-f0-9]{64}$/D', $expected) && hash_equals($expected, hash('sha256', $token))) {
        session_regenerate_id(true);
        $_SESSION['setup_authorized'] = $expected;
        redirect_to('installer.php');
    }
    if (!is_string($_SESSION['setup_authorized'] ?? null) || !hash_equals($expected, $_SESSION['setup_authorized'])) {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Authorize setup</title><h1>Authorize site setup</h1>';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            echo '<p role="alert">Setup token was not accepted.</p>';
        }
        echo '<form method="post"><label>Setup token <input type="password" name="setup_token" required></label><button>Authorize</button></form></html>';
        exit;
    }
}

function csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function check_csrf() {
    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        throw new RuntimeException('Invalid form token. Reload the page and try again.');
    }
}

function save_database_settings(array $settings) {
    $path = __DIR__ . '/database.local.php';
    $temporary = tempnam(__DIR__, 'database-');
    if ($temporary === false) {
        throw new RuntimeException('Cannot create database configuration. Check config folder permissions.');
    }
    try {
        $contents = "<?php\nreturn " . var_export($settings, true) . ";\n";
        if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $path)) {
            throw new RuntimeException('Cannot save database configuration. Check config folder permissions.');
        }
    } finally {
        if (is_file($temporary) && !unlink($temporary)) {
            error_log('Could not remove temporary database configuration: ' . $temporary);
        }
    }
}
