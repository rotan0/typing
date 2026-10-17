<?php
function migrate_schema(PDO $pdo) {
    $schema = file_get_contents(__DIR__ . '/../sql/schema.sql');
    if ($schema === false) {
        throw new RuntimeException('Cannot read database schema.');
    }
    foreach (explode(';', $schema) as $statement) {
        if (trim($statement) !== '') {
            $pdo->exec($statement);
        }
    }
    $column = $pdo->query("SHOW COLUMNS FROM lessons LIKE 'owner_id'")->fetch();
    if (!$column) {
        $pdo->exec('ALTER TABLE lessons ADD owner_id INT NULL, ADD is_public TINYINT(1) NOT NULL DEFAULT 0,
            ADD INDEX lessons_owner (owner_id), ADD CONSTRAINT lessons_owner_fk FOREIGN KEY (owner_id) REFERENCES users(id)');
    }
}
