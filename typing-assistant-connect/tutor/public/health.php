<?php
header('Content-Type: application/json');
echo json_encode([
    'application' => 'morse-typing',
    'installation' => hash('sha256', strtolower(realpath(__DIR__ . '/..'))),
]);
