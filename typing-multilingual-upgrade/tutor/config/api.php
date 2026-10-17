<?php
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
set_exception_handler(function (Throwable $error) {
    $invalid = $error instanceof InvalidArgumentException;
    error_log('Typing API failed: ' . $error->getMessage());
    http_response_code($invalid ? 422 : 500);
    echo json_encode(['error' => $invalid ? $error->getMessage() : 'The request could not be completed. Check the server log.']);
});
$user = require_user(true);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        check_csrf();
    } catch (RuntimeException $error) {
        http_response_code(403);
        echo json_encode(['error' => $error->getMessage()]);
        exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

function input_text($input, $key, $maximum, $required = true) {
    $value = $input[$key] ?? '';
    if (!is_string($value) || strlen($value) > $maximum || ($required && trim($value) === '') ||
        !preg_match('//u', $value)) {
        throw new InvalidArgumentException('Invalid ' . $key . '.');
    }
    return trim($value);
}

function input_id($input, $key) {
    $id = filter_var($input[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) {
        throw new InvalidArgumentException('Invalid ' . $key . '.');
    }
    return $id;
}

function owned_row($table, $id, $user_id) {
    if (!in_array($table, ['lessons', 'conversations'], true)) {
        throw new LogicException('Invalid ownership table.');
    }
    $query = db()->prepare("SELECT * FROM $table WHERE id = ? AND owner_id = ?");
    $query->execute([$id, $user_id]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Item not found.']);
        exit;
    }
    return $row;
}
