<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command-line use only.');
}
require_once __DIR__ . '/../config/profiles.php';
$output = $argv[1] ?? dirname(__DIR__) . '/typing-deploy.zip';
$temporary = null;
$zip = null;
$opened = false;
$failed = false;
try {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Enable the PHP zip extension before packaging.');
    }
    if (file_exists($output)) {
        throw new RuntimeException('Output already exists. Choose a new filename; existing archives are never overwritten.');
    }
    $temporary = tempnam(dirname($output), 'typing-package-');
    if ($temporary === false) {
        throw new RuntimeException('Cannot create package in the output folder.');
    }
    $zip = new ZipArchive();
    if ($zip->open($temporary, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot open deployment ZIP.');
    }
    $opened = true;
    foreach (export_sources(dirname(__DIR__)) as $relative => $file) {
        if (!$zip->addFile($file, $relative)) {
            throw new RuntimeException('Cannot add deployment source: ' . $relative);
        }
    }
    if (!$zip->close()) {
        throw new RuntimeException('Cannot finalize deployment ZIP.');
    }
    $opened = false;
    if (!rename($temporary, $output)) {
        throw new RuntimeException('Cannot save deployment ZIP.');
    }
    $temporary = null;
    echo "Deployment package saved to $output\n";
    echo "Source only: no credentials, accounts, private profiles, runtime cache or live-host settings.\n";
} catch (Throwable $error) {
    if ($opened) {
        $zip->close();
    }
    fwrite(STDERR, "Packaging failed: " . $error->getMessage() . "\n");
    $failed = true;
} finally {
    if ($temporary !== null && is_file($temporary) && !unlink($temporary)) {
        error_log('Could not remove temporary deployment package.');
    }
    if ($failed) {
        exit(1);
    }
}
