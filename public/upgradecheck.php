<?php
// Temporary upload diagnostic: remove from the host after troubleshooting.
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
ini_set('display_errors', '0');
$failures = 0;
function report_check($passed, $label) {
    global $failures;
    echo ($passed ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$passed) {
        $failures++;
    }
}
echo "Typing upgrade file checker\n";
echo "No credentials, sessions or database connections are loaded.\n\n";
$root = dirname(__DIR__);
if (PHP_SAPI === 'cli' && isset($argv[1])) {
    $root = $argv[1];
}
report_check(version_compare(PHP_VERSION, '7.4.0', '>='), 'PHP 7.4 or newer');
foreach (array('pdo_mysql', 'json', 'session') as $extension) {
    report_check(extension_loaded($extension), 'Required extension: ' . $extension);
}
foreach (array(
    'config/bootstrap.php', 'config/config.php', 'config/auth.php',
    'config/web.php', 'config/setup.php', 'config/lang.php',
    'config/language_controls.php', 'config/drills.php',
    'public/index.php', 'public/studio.php', 'public/drills.php',
    'public/api/drills.php', 'assets/js/drills.js', 'assets/logo.gif'
) as $relative) {
    report_check(is_file($root . '/' . $relative) && is_readable($root . '/' . $relative), 'Readable source: ' . $relative);
}
foreach (array('en', 'es', 'fr', 'de', 'it', 'pt', 'nl', 'pl', 'ru', 'ar', 'hi') as $code) {
    foreach (array('lang', 'vocabulary') as $directory) {
        $relative = $directory . '/' . $code . '.json';
        $path = $root . '/' . $relative;
        if (!is_file($path) || !is_readable($path)) {
            report_check(false, 'Missing or unreadable: ' . $relative);
            continue;
        }
        $contents = file_get_contents($path);
        $data = $contents === false ? null : json_decode($contents, true);
        $valid = is_array($data);
        if ($valid && $directory === 'lang') {
            $valid = isset($data['code'], $data['name'], $data['strings']) &&
                $data['code'] === $code && is_array($data['strings']);
        } elseif ($valid) {
            $valid = isset($data['name'], $data['direction'], $data['words'], $data['ui']) &&
                is_string($data['name']) &&
                in_array($data['direction'], array('ltr', 'rtl'), true) &&
                is_array($data['words']) && count($data['words']) >= 20 &&
                is_array($data['ui']) && isset($data['ui']['title']);
            if ($valid) {
                foreach ($data['words'] as $word) {
                    if (!is_string($word) || !preg_match('/^[\p{L}\p{M}]+$/uD', $word) ||
                        count(preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY)) > 40) {
                        $valid = false;
                        break;
                    }
                }
            }
        }
        report_check($valid, 'Valid catalog: ' . $relative);
    }
}
$layoutPath = $root . '/vocabulary/layouts.json';
$layouts = is_file($layoutPath) && is_readable($layoutPath) ?
    json_decode(file_get_contents($layoutPath), true) : null;
$validLayouts = is_array($layouts);
foreach (array('qwerty', 'azerty', 'qwertz', 'arabic101', 'inscript', 'unrestricted') as $id) {
    if (!isset($layouts[$id]['name'], $layouts[$id]['tiers']) ||
        !is_array($layouts[$id]['tiers']) || count($layouts[$id]['tiers']) !== 5) {
        $validLayouts = false;
    }
}
report_check($validLayouts, 'Valid vocabulary/layouts.json');
echo "\n";
echo $failures ? "Upload/runtime checks failed: $failures\n" :
    "All file/runtime checks passed. This does not test database access or execute application PHP.\n";
echo "Remove this checker from the host after troubleshooting.\n";
