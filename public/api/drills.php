<?php
require_once __DIR__ . '/../../config/api.php';
require_once __DIR__ . '/../../config/drills.php';
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(drill_state($pdo, $user['id']), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
$action = input_text($_POST, 'action', 32);
// Lock the account row so tabs cannot advance or replace the same drill concurrently.
$pdo->beginTransaction();
try {
    $query = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
    $query->execute([$user['id']]);
    $state = drill_state($pdo, $user['id']);
    if ($action === 'start') {
        $session = drill_generate(drill_preferences($_POST));
        drill_save_session($pdo, $user['id'], $session);
    } elseif (in_array($action, ['submit', 'repeat', 'advance'], true)) {
        $id = input_id($_POST, 'session_id');
        if ($id !== $state['session_id'] || !$state['session']) {
            throw new InvalidArgumentException('This drill is no longer current. Reload to resume your latest session.');
        }
        $session = $state['session'];
        $summary = drill_summary($session);
        if ($action === 'submit') {
            $index = filter_var($_POST['index'] ?? null, FILTER_VALIDATE_INT);
            if ($summary['complete'] || $index === false || $index !== count($session['results'])) {
                throw new InvalidArgumentException('This line was already submitted or is out of order. Reload to resume.');
            }
            $duration = filter_var($_POST['duration_ms'] ?? null, FILTER_VALIDATE_INT);
            $session['results'][] = drill_score($session['lines'][$index], $_POST['input'] ?? null, $duration);
            $pdo->prepare('UPDATE drill_sessions SET payload = ? WHERE id = ? AND user_id = ?')
                ->execute([json_encode($session, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $id, $user['id']]);
        } else {
            if (!$summary['complete']) {
                throw new InvalidArgumentException('Finish all ten drills before repeating or advancing.');
            }
            $preferences = $session['preferences'];
            if ($action === 'advance') {
                if (!$summary['passed'] || $preferences['level'] >= 5) {
                    throw new InvalidArgumentException('Advancement requires 95% aggregate accuracy and a level below five.');
                }
                $preferences['level']++;
            }
            drill_save_session($pdo, $user['id'], drill_generate($preferences, $action === 'repeat' ? $summary['weak'] : []));
        }
    } else {
        throw new InvalidArgumentException('Unknown drill action.');
    }
    $response = drill_state($pdo, $user['id']);
    $pdo->commit();
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $pdo->rollBack();
    throw $error;
}
