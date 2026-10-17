<?php
class AssistantFailure extends RuntimeException {
    public $status;
    public function __construct($message, $status = 503) {
        parent::__construct($message);
        $this->status = $status;
    }
}

function assistant_gateway() {
    $config_file = __DIR__ . '/assistant.local.php';
    // Never include deployment-specific configuration in personal downloads.
    if (!is_file($config_file) || strtolower($_SERVER['HTTP_HOST'] ?? '') !== 'equal-page.free.nf' ||
        app_base() !== '/typing' || empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
        throw new AssistantFailure('Online assistant is enabled only on the configured HTTPS hosted tutor. Downloaded copies keep conversations but do not access hosted models.');
    }
    $config = require $config_file;
    $url = 'https://equal-page.com/free.chat/typing-gateway';
    if (!is_array($config) || ($config['enabled'] ?? false) !== true || ($config['gateway'] ?? null) !== $url) {
        throw new AssistantFailure('Online assistant gateway is not configured. Ask the site owner to finish the gateway installation.');
    }
    return $url;
}

function assistant_request($gateway, $path, $payload = null) {
    if (!extension_loaded('curl')) {
        throw new AssistantFailure('The hosting PHP cURL extension is required for the assistant.');
    }
    $curl = curl_init($gateway . $path);
    if ($curl === false) {
        throw new AssistantFailure('Cannot initialize the model connection.');
    }
    $body = '';
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
        CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$body) {
            if (strlen($body) + strlen($chunk) > 65536) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    if ($payload !== null) {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
    }
    try {
        if (curl_exec($curl) === false) {
            error_log('Typing assistant transport failed; cURL code ' . curl_errno($curl));
            throw new AssistantFailure('The model gateway could not be reached or timed out. Your notes are unchanged.', 502);
        }
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($status !== 200) {
            error_log('Typing assistant gateway returned HTTP ' . $status);
            $messages = [
                429 => 'The assistant is busy or at its hourly limit. Please try again later.',
                504 => 'The model timed out. Try a faster model or a shorter question.',
            ];
            throw new AssistantFailure($messages[$status] ?? 'The model gateway returned an error. Ask the site owner to check the gateway service.', $status === 429 ? 429 : 502);
        }
        try {
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new AssistantFailure('The gateway returned invalid JSON.', 502);
        }
        if (!is_array($data)) {
            throw new AssistantFailure('The gateway returned an invalid response.', 502);
        }
        return $data;
    } finally {
        curl_close($curl);
    }
}

function assistant_models($gateway) {
    $data = assistant_request($gateway, '/models');
    if (!isset($data['models']) || !is_array($data['models']) || count($data['models']) < 1 || count($data['models']) > 30) {
        throw new AssistantFailure('No approved typing models are configured.', 503);
    }
    $models = [];
    foreach ($data['models'] as $model) {
        if (!is_array($model) || !is_string($model['id'] ?? null) || !preg_match('/^[\w.:-]{1,100}$/D', $model['id']) ||
            !is_string($model['label'] ?? null) || trim($model['label']) === '' || strlen($model['label']) > 600 ||
            !in_array($model['type'] ?? null, ['ollama', 'openai', 'anthropic'], true) || isset($models[$model['id']])) {
            throw new AssistantFailure('The gateway model list is invalid.', 502);
        }
        $models[$model['id']] = ['id' => $model['id'], 'label' => $model['label'], 'type' => $model['type']];
    }
    return array_values($models);
}

function assistant_allowance(PDO $pdo, $user_id, $address) {
    foreach (['user:' . $user_id, 'ip:' . $address] as $bucket) {
        $key = hash('sha256', 'typing-assistant|' . $bucket);
        $pdo->prepare('INSERT INTO auth_limits (bucket, attempts, reset_at) VALUES (?, 1, DATE_ADD(NOW(), INTERVAL 1 HOUR))
            ON DUPLICATE KEY UPDATE attempts = IF(reset_at < NOW(), 1, attempts + 1),
            reset_at = IF(reset_at < NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR), reset_at)')->execute([$key]);
        $query = $pdo->prepare('SELECT attempts FROM auth_limits WHERE bucket = ?');
        $query->execute([$key]);
        if ((int) $query->fetchColumn() > 30) {
            throw new AssistantFailure('Assistant limit reached: at most 30 requests per account and IP per hour.', 429);
        }
    }
}

function assistant_messages(PDO $pdo, $conversation_id, $question) {
    $query = $pdo->prepare('SELECT role, content FROM conversation_messages WHERE conversation_id = ? ORDER BY id DESC LIMIT 11');
    $query->execute([$conversation_id]);
    $history = $query->fetchAll(PDO::FETCH_ASSOC);
    $messages = [['role' => 'user', 'content' => $question]];
    $bytes = strlen($question);
    foreach ($history as $message) {
        if (!in_array($message['role'], ['user', 'assistant'], true) || $bytes + strlen($message['content']) > 24000) {
            break;
        }
        array_unshift($messages, $message);
        $bytes += strlen($message['content']);
    }
    // Some hosted APIs require the conversation to begin with a user turn.
    while (count($messages) > 1 && $messages[0]['role'] !== 'user') {
        array_shift($messages);
    }
    return $messages;
}

function assistant_reply(PDO $pdo, $user_id, $conversation_id, $model, $question, callable $request) {
    $lock = 'typing-assistant-' . substr(hash('sha256', $pdo->query('SELECT DATABASE()')->fetchColumn() . ':' . $user_id), 0, 40);
    $query = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $query->execute([$lock]);
    if ((int) $query->fetchColumn() !== 1) {
        throw new AssistantFailure('Another assistant request for your account is still running.', 429);
    }
    try {
        $owner = $pdo->prepare('SELECT id FROM conversations WHERE id = ? AND owner_id = ?');
        $owner->execute([$conversation_id, $user_id]);
        if (!$owner->fetchColumn()) {
            throw new AssistantFailure('Conversation not found.', 404);
        }
        $messages = assistant_messages($pdo, $conversation_id, $question);
        $data = $request(['model' => $model, 'messages' => $messages]);
        $content = $data['content'] ?? null;
        if (!is_string($content) || trim($content) === '' || strlen($content) > 8000 ||
            !preg_match('//u', $content) || ($data['model'] ?? null) !== $model) {
            throw new AssistantFailure('The model returned an invalid reply. No conversation changes were saved.', 502);
        }
        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare('INSERT INTO conversation_messages (conversation_id, role, content) VALUES (?, ?, ?)');
            $insert->execute([$conversation_id, 'user', $question]);
            $insert->execute([$conversation_id, 'assistant', $content]);
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
        return ['content' => $content, 'model' => $model];
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
    }
}
