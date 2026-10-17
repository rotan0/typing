<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command-line use only.');
}
require_once __DIR__ . '/../config/profiles.php';
$output = $argv[1] ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'typing-assistant-connect.zip';
$temporary = null;
$zip = null;
$opened = false;
$failed = false;
try {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Enable the PHP zip extension before packaging.');
    }
    if (file_exists($output)) {
        throw new RuntimeException('Output already exists; choose a new filename.');
    }
    $temporary = tempnam(dirname($output), 'typing-assistant-');
    if ($temporary === false) {
        throw new RuntimeException('Cannot create the package.');
    }
    $zip = new ZipArchive();
    if ($zip->open($temporary, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot open the package.');
    }
    $opened = true;
    foreach (export_sources(dirname(__DIR__)) as $relative => $file) {
        if (!$zip->addFile($file, 'tutor/' . $relative)) {
            throw new RuntimeException('Cannot add tutor source.');
        }
    }
    foreach (['gateway/server.cjs', 'gateway/models.example.json', 'gateway/typing-gateway.service',
        'gateway/nginx-location.conf', 'gateway/README.md', 'config/assistant.example.php',
        'tests/gateway.test.cjs', 'scripts/package_assistant.php'] as $relative) {
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($path) || is_link($path) || !$zip->addFile($path, $relative)) {
            throw new RuntimeException('Cannot add assistant install source: ' . $relative);
        }
    }
    if (!$zip->close()) {
        throw new RuntimeException('Cannot finalize the package.');
    }
    $opened = false;
    if (!rename($temporary, $output)) {
        throw new RuntimeException('Cannot save the package.');
    }
    $temporary = null;
    echo "Assistant install package saved to $output\nRead gateway/README.md before installing on the VPS and shared host.\n";
} catch (Throwable $error) {
    if ($opened) {
        $zip->close();
    }
    fwrite(STDERR, 'Assistant packaging failed: ' . $error->getMessage() . "\n");
    $failed = true;
} finally {
    if ($temporary !== null && is_file($temporary) && !unlink($temporary)) {
        error_log('Could not remove temporary assistant package.');
    }
}
if ($failed) {
    exit(1);
}
