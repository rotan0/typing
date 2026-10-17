<?php
require_once __DIR__ . '/../../config/api.php';
require_once __DIR__ . '/../../config/assistant.php';
try {
    $gateway = assistant_gateway();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode(['available' => true, 'models' => assistant_models($gateway)]);
        exit;
    }
    if (($_POST['consent'] ?? '') !== '1') {
        throw new InvalidArgumentException('Confirm that this private conversation can be sent to the selected model provider.');
    }
    $id = input_id($_POST, 'conversation_id');
    owned_row('conversations', $id, $user['id']);
    $model = input_text($_POST, 'model', 100);
    $question = input_text($_POST, 'question', 4000);
    if (!in_array($model, array_column(assistant_models($gateway), 'id'), true)) {
        throw new InvalidArgumentException('Choose an available typing model.');
    }
    assistant_allowance(db(), $user['id'], $_SERVER['REMOTE_ADDR'] ?? '');
    // Release the session cookie lock while waiting for inference.
    session_write_close();
    $reply = assistant_reply(db(), $user['id'], $id, $model, $question, function ($payload) use ($gateway) {
        return assistant_request($gateway, '/chat', $payload);
    });
    echo json_encode($reply);
} catch (AssistantFailure $error) {
    error_log('Typing assistant request failed with status ' . $error->status);
    http_response_code($error->status);
    echo json_encode(['available' => false, 'models' => [], 'error' => $error->getMessage()]);
}
