<?php
require_once __DIR__ . '/../../config/api.php';
require_once __DIR__ . '/../../config/profiles.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Use POST to restore a profile.']);
    exit;
}
if (($_POST['bundled'] ?? '') === '1') {
    // Bundled personal data is only restorable from the downloaded local application.
    if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Bundled profile restore is available only on the local downloaded tutor.']);
        exit;
    }
    $file = __DIR__ . '/../../data/profile.json';
    if (!is_file($file) || filesize($file) > 4 * 1024 * 1024) {
        throw new InvalidArgumentException('Bundled personal profile is missing or too large.');
    }
    $json = file_get_contents($file);
    if ($json === false) {
        throw new RuntimeException('Cannot read the bundled personal profile.');
    }
} else {
    $json = $_POST['profile'] ?? null;
}
import_profile(db(), $user['id'], $json);
echo json_encode(['message' => 'Personal profile restored. Lessons are private until you explicitly publish them here.']);
