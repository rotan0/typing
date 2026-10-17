<?php
require_once __DIR__ . '/web.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/setup.php';

function current_user() {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    $query = db()->prepare('SELECT id, username FROM users WHERE id = ?');
    $query->execute([$_SESSION['user_id']]);
    return $query->fetch(PDO::FETCH_ASSOC) ?: null;
}

function require_user($api = false) {
    $user = current_user();
    if (!$user) {
        if ($api) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Please log in.']);
            exit;
        }
        redirect_to('account.php');
    }
    return $user;
}

function sign_in($id) {
    session_regenerate_id(true);
    $_SESSION = ['user_id' => (int) $id];
    csrf_token();
}

function account_action($action, $username, $password) {
    if (!is_string($username) || !preg_match('/^[A-Za-z0-9_]{3,64}$/D', $username)) {
        throw new InvalidArgumentException('Username must contain 3-64 letters, digits or underscores.');
    }
    $minimum = $action === 'register' ? 12 : 1;
    if (!is_string($password) || strlen($password) < $minimum || strlen($password) > 72) {
        throw new InvalidArgumentException('Password must be ' . $minimum . '-72 bytes.');
    }
    $pdo = db();
    // Database-backed limits cannot be bypassed by clearing the session cookie.
    $key = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'local') . '|accounts');
    $pdo->prepare('INSERT INTO auth_limits (bucket, attempts, reset_at) VALUES (?, 1, DATE_ADD(NOW(), INTERVAL 15 MINUTE))
        ON DUPLICATE KEY UPDATE attempts = IF(reset_at < NOW(), 1, attempts + 1),
        reset_at = IF(reset_at < NOW(), DATE_ADD(NOW(), INTERVAL 15 MINUTE), reset_at)')->execute([$key]);
    $limit = $pdo->prepare('SELECT attempts FROM auth_limits WHERE bucket = ?');
    $limit->execute([$key]);
    if ((int) $limit->fetchColumn() > 20) {
        throw new InvalidArgumentException('Too many account attempts. Wait 15 minutes before trying again.');
    }
    if ($action === 'register') {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false) {
            throw new RuntimeException('Password hashing failed.');
        }
        try {
            $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)')->execute([$username, $hash]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                throw new InvalidArgumentException('That username is unavailable.');
            }
            throw $error;
        }
        sign_in($pdo->lastInsertId());
    } elseif ($action === 'login') {
        $query = $pdo->prepare('SELECT id, password_hash FROM users WHERE username = ?');
        $query->execute([$username]);
        $user = $query->fetch(PDO::FETCH_ASSOC);
        $hash = $user ? $user['password_hash'] : '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        if (!password_verify($password, $hash) || !$user) {
            throw new InvalidArgumentException('Invalid username or password.');
        }
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        sign_in($user['id']);
    } else {
        throw new InvalidArgumentException('Unknown account action.');
    }
}
