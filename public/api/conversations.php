<?php
require_once __DIR__ . '/../../config/api.php';
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = input_text($_POST, 'action', 32);
    if ($action === 'create') {
        $title = input_text($_POST, 'title', 128);
        $pdo->prepare('INSERT INTO conversations (owner_id, title) VALUES (?, ?)')->execute([$user['id'], $title]);
        echo json_encode(['id' => (int) $pdo->lastInsertId()]);
    } elseif ($action === 'note') {
        $id = input_id($_POST, 'conversation_id');
        owned_row('conversations', $id, $user['id']);
        $content = input_text($_POST, 'content', 8000);
        $pdo->prepare("INSERT INTO conversation_messages (conversation_id, role, content) VALUES (?, 'user', ?)")->execute([$id, $content]);
        echo json_encode(['saved' => true]);
    } else {
        throw new InvalidArgumentException('Unknown conversation action.');
    }
    exit;
}
if (isset($_GET['id'])) {
    $conversation = owned_row('conversations', input_id($_GET, 'id'), $user['id']);
    $query = $pdo->prepare('SELECT role, content, created_at FROM conversation_messages WHERE conversation_id = ? ORDER BY id');
    $query->execute([$conversation['id']]);
    echo json_encode(['conversation' => $conversation, 'messages' => $query->fetchAll(PDO::FETCH_ASSOC)]);
} else {
    $query = $pdo->prepare('SELECT id, title, created_at FROM conversations WHERE owner_id = ? ORDER BY id DESC');
    $query->execute([$user['id']]);
    echo json_encode($query->fetchAll(PDO::FETCH_ASSOC));
}
