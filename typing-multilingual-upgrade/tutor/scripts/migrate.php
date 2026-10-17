<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command-line use only.');
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/migrations.php';
require_once __DIR__ . '/../config/lessons.php';
try {
    migrate_schema(db());
    install_published_lessons(db());
    echo "Account, lesson ownership and multilingual drill schema is ready. Existing users, lessons and progress are preserved.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Migration failed: " . $error->getMessage() . "\n");
    exit(1);
}
