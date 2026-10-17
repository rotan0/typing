<?php
// The built-in server exposes only public pages and the two asset directories.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
if (strpos($path, '/assets/') === 0) {
    $root = realpath(__DIR__ . '/../assets');
    $file = realpath(__DIR__ . '/..' . $path);
    if ($file === false || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) {
        http_response_code(404);
        exit('Asset not found.');
    }
    $types = ['css' => 'text/css', 'js' => 'application/javascript'];
    $extension = pathinfo($file, PATHINFO_EXTENSION);
    if (!isset($types[$extension])) {
        http_response_code(404);
        exit('Unsupported asset.');
    }
    header('Content-Type: ' . $types[$extension] . '; charset=utf-8');
    readfile($file);
    return true;
}
return false;
