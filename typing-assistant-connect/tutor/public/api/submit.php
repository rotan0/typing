<?php
require_once __DIR__ . '/../../config/api.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Use POST to submit an attempt.']);
    exit;
}
$user_id = $user['id'];
$lesson_id = input_id($_POST, 'lesson_id');
$raw_input = $_POST['raw_input'] ?? '';
if (!is_string($raw_input) || strlen($raw_input) > 4000 || !preg_match('/^[A-Za-z0-9 ]*$/D', $raw_input)) {
    throw new InvalidArgumentException('Input must contain only A-Z, digits and spaces (up to 4000 bytes).');
}
$duration_ms = filter_var($_POST['duration_ms'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 86400000]]);
if ($duration_ms === false) {
    throw new InvalidArgumentException('Invalid duration.');
}

$pdo = db();
$stmt = $pdo->prepare("SELECT expected_sequence FROM lessons WHERE id = ? AND (owner_id IS NULL OR owner_id = ? OR is_public = 1)");
$stmt->execute([$lesson_id, $user_id]);
$lesson = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lesson) {
    http_response_code(404);
    echo json_encode(["error" => "Lesson not found"]);
    exit;
}

$expected = $lesson['expected_sequence'];

function compute_accuracy($expected, $input) {
    $expected = strtoupper($expected);
    $input = strtoupper($input);
    $len = max(strlen($expected), strlen($input));
    if ($len === 0) return 1.0;
    $matches = 0;
    for ($i = 0; $i < $len; $i++) {
        $e = $expected[$i] ?? '';
        $i_ch = $input[$i] ?? '';
        if ($e === $i_ch) $matches++;
    }
    return $matches / $len;
}

$accuracy = compute_accuracy($expected, $raw_input);

$pdo->beginTransaction();
try {
$logStmt = $pdo->prepare("
    INSERT INTO keystroke_log (user_id, lesson_id, duration_ms, accuracy, raw_input)
    VALUES (?, ?, ?, ?, ?)
");
$logStmt->execute([$user_id, $lesson_id, $duration_ms, $accuracy, $raw_input]);

$avg_speed = $duration_ms;
$progressStmt = $pdo->prepare("
    INSERT INTO user_progress (user_id, lesson_id, attempts, best_accuracy, avg_speed, last_attempt_at)
    VALUES (?, ?, 1, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE
        best_accuracy = GREATEST(best_accuracy, VALUES(best_accuracy)),
        avg_speed = (avg_speed * attempts + VALUES(avg_speed)) / (attempts + 1),
        attempts = attempts + 1,
        last_attempt_at = NOW()
");
$progressStmt->execute([$user_id, $lesson_id, $accuracy, $avg_speed]);
$pdo->commit();
} catch (Throwable $error) {
    $pdo->rollBack();
    throw $error;
}

echo json_encode([
    "accuracy" => $accuracy,
    "duration_ms" => $duration_ms
]);
