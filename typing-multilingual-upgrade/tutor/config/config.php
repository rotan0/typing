<?php
function database_settings() {
    $file = __DIR__ . '/database.local.php';
    return file_exists($file) ? require $file : [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'morse_typing',
        'user' => 'root',
        'password' => '',
    ];
}

function database_connection(array $settings, $with_database = true) {
    $dsn = "mysql:host={$settings['host']};port={$settings['port']};charset=utf8mb4";
    if ($with_database) {
        $dsn .= ";dbname={$settings['name']}";
    }
    return new PDO($dsn, $settings['user'], $settings['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function db() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = database_connection(database_settings());
    }
    return $pdo;
}
