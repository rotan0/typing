<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command-line use only.');
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/lessons.php';

try {
    generate_lessons(db());
    echo "Lessons generated.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Lesson generation failed: " . $error->getMessage() . "\n");
    exit(1);
}
