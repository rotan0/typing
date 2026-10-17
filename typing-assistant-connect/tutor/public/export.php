<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/profiles.php';
$user = require_user();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Use the personal download button in the lesson studio.');
}
$file = null;
try {
    check_csrf();
    $profile = export_profile(db(), $user['id']);
    $file = create_personal_archive(dirname(__DIR__), $profile);
    session_write_close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="my-personal-typing-tutor.zip"');
    header('Content-Length: ' . filesize($file));
    if (readfile($file) === false) {
        error_log('Personal tutor download was interrupted.');
    }
} catch (Throwable $error) {
    error_log('Personal export failed: ' . $error->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $error instanceof InvalidArgumentException || $error instanceof RuntimeException && !($error instanceof PDOException)
        ? $error->getMessage() : 'Export failed. Check the server log.';
} finally {
    if ($file !== null && is_file($file) && !unlink($file)) {
        error_log('Could not remove a temporary personal download.');
    }
}
