<?php
function app_base() {
    $directory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $directory = preg_replace('~/(?:public/)?api$~', '', $directory);
    $directory = preg_replace('~/public$~', '', $directory);
    // Apache aliases can strip the mount prefix from SCRIPT_NAME after rewriting.
    $request_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (is_string($request_path) && preg_match('~^(.*?)/(?:public/)?(?:api/)?[a-z]+\.php$~D', $request_path, $match)) {
        $directory = $match[1];
    } elseif (is_string($request_path) && substr($request_path, -1) === '/') {
        $directory = rtrim($request_path, '/');
    }
    return $directory === '/' || $directory === '.' || $directory === '' ? '' : '/' . trim($directory, '/');
}

function app_url($path = '/') {
    return app_base() . '/' . ltrim($path, '/');
}

function redirect_to($path) {
    header('Location: ' . app_url($path));
    exit;
}

function start_app_session() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('typing_' . substr(hash('sha256', realpath(__DIR__ . '/..') . app_base()), 0, 16));
    session_start([
        'use_strict_mode' => true,
        'cookie_httponly' => true,
        'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'cookie_samesite' => 'Lax',
        'cookie_path' => app_url(),
    ]);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}
