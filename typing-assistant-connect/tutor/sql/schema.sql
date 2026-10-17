CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS lessons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(128) NOT NULL,
    description TEXT,
    level INT NOT NULL,
    pattern TEXT NOT NULL,
    expected_sequence TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS auth_limits (
    bucket CHAR(64) PRIMARY KEY,
    attempts INT NOT NULL,
    reset_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL,
    title VARCHAR(128) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX conversations_owner (owner_id),
    FOREIGN KEY (owner_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS conversation_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    role VARCHAR(16) NOT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX messages_conversation (conversation_id),
    FOREIGN KEY (conversation_id) REFERENCES conversations(id)
);

CREATE TABLE IF NOT EXISTS profile_imports (
    user_id INT NOT NULL,
    content_hash CHAR(64) NOT NULL,
    imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, content_hash),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS user_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    lesson_id INT NOT NULL,
    attempts INT DEFAULT 0,
    best_accuracy FLOAT DEFAULT 0,
    avg_speed FLOAT DEFAULT 0,
    last_attempt_at TIMESTAMP NULL,
    UNIQUE KEY uniq_user_lesson (user_id, lesson_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (lesson_id) REFERENCES lessons(id)
);

CREATE TABLE IF NOT EXISTS keystroke_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    lesson_id INT NOT NULL,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    duration_ms INT NOT NULL,
    accuracy FLOAT NOT NULL,
    raw_input TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (lesson_id) REFERENCES lessons(id)
);
