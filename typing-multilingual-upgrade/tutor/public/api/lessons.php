<?php
require_once __DIR__ . '/../../config/api.php';
require_once __DIR__ . '/../../config/lessons.php';
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = input_text($_POST, 'action', 32);
    if ($action === 'save') {
        $name = input_text($_POST, 'name', 128);
        $description = input_text($_POST, 'description', 8000, false);
        $text = input_text($_POST, 'expected_sequence', 4000);
        if (!preg_match('/^[A-Za-z0-9 ]+$/D', $text)) {
            throw new InvalidArgumentException('Morse lessons support A-Z, digits and spaces only.');
        }
        $text = strtoupper($text);
        $level = filter_var($_POST['level'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]]);
        if (!$level) {
            throw new InvalidArgumentException('Choose a difficulty from 1 to 10.');
        }
        $morse = morse_pattern($text);
        if (!empty($_POST['id'])) {
            $id = input_id($_POST, 'id');
            owned_row('lessons', $id, $user['id']);
            $pdo->prepare('UPDATE lessons SET name = ?, description = ?, level = ?, pattern = ?, expected_sequence = ? WHERE id = ? AND owner_id = ?')
                ->execute([$name, $description, $level, $morse, $text, $id, $user['id']]);
        } else {
            $pdo->prepare('INSERT INTO lessons (name, description, level, pattern, expected_sequence, owner_id) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$name, $description, $level, $morse, $text, $user['id']]);
            $id = (int) $pdo->lastInsertId();
        }
        echo json_encode(['id' => $id]);
    } elseif ($action === 'publish') {
        $id = input_id($_POST, 'id');
        owned_row('lessons', $id, $user['id']);
        if (!in_array($_POST['is_public'] ?? null, ['0', '1'], true)) {
            throw new InvalidArgumentException('Choose private or published.');
        }
        $pdo->prepare('UPDATE lessons SET is_public = ? WHERE id = ? AND owner_id = ?')
            ->execute([$_POST['is_public'], $id, $user['id']]);
        echo json_encode(['saved' => true]);
    } else {
        throw new InvalidArgumentException('Unknown lesson action.');
    }
    exit;
}
$query = $pdo->prepare('SELECT id, name, description, level, pattern, expected_sequence, owner_id, is_public
    FROM lessons WHERE owner_id IS NULL OR owner_id = ? OR is_public = 1 ORDER BY level, id');
$query->execute([$user['id']]);
echo json_encode($query->fetchAll(PDO::FETCH_ASSOC));
