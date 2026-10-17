<?php
require_once __DIR__ . '/lessons.php';
require_once __DIR__ . '/drills.php';

function rows_for_user(PDO $pdo, $sql, $id) {
    $query = $pdo->prepare($sql);
    $query->execute([$id]);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function export_profile(PDO $pdo, $user_id) {
    $pdo->beginTransaction();
    try {
        $lessons = rows_for_user($pdo, 'SELECT id, name, description, level, pattern, expected_sequence, owner_id, is_public
            FROM lessons WHERE owner_id = ? OR owner_id IS NULL ORDER BY id', $user_id);
        $allowed = [];
        foreach ($lessons as &$lesson) {
            $allowed[(int) $lesson['id']] = true;
            $lesson['kind'] = $lesson['owner_id'] === null ? 'default' : 'owned';
            $lesson['description'] = $lesson['description'] ?? '';
            $lesson['level'] = (int) $lesson['level'];
            unset($lesson['owner_id']);
        }
        unset($lesson);
        $progress = rows_for_user($pdo, 'SELECT lesson_id, attempts, best_accuracy, avg_speed, last_attempt_at FROM user_progress WHERE user_id = ? ORDER BY id', $user_id);
        $logs = rows_for_user($pdo, 'SELECT lesson_id, started_at, duration_ms, accuracy, raw_input FROM keystroke_log WHERE user_id = ? ORDER BY id', $user_id);
        $excluded = 0;
        $keep = function ($row) use ($allowed, &$excluded) {
            if (!isset($allowed[(int) $row['lesson_id']])) {
                $excluded++;
                return false;
            }
            return true;
        };
        $progress = array_values(array_filter($progress, $keep));
        $logs = array_values(array_filter($logs, $keep));
        $conversations = rows_for_user($pdo, 'SELECT id, title, created_at FROM conversations WHERE owner_id = ? ORDER BY id', $user_id);
        foreach ($conversations as &$conversation) {
            $conversation['messages'] = rows_for_user($pdo, 'SELECT role, content, created_at FROM conversation_messages WHERE conversation_id = ? ORDER BY id', $conversation['id']);
            unset($conversation['id']);
        }
        unset($conversation);
        $drill_preferences = rows_for_user($pdo, 'SELECT preferences FROM drill_preferences WHERE user_id = ?', $user_id);
        $drill_sessions = rows_for_user($pdo, 'SELECT payload, created_at FROM drill_sessions WHERE user_id = ? ORDER BY id', $user_id);
        $drills = ['preferences' => $drill_preferences ? json_decode($drill_preferences[0]['preferences'], true, 16, JSON_THROW_ON_ERROR) : null,
            'sessions' => array_map(function ($row) {
                return ['session' => json_decode($row['payload'], true, 16, JSON_THROW_ON_ERROR), 'created_at' => $row['created_at']];
            }, $drill_sessions)];
        $pdo->commit();
        return [
            'format' => 'typing-personal-profile', 'version' => 1,
            'lessons' => $lessons, 'conversations' => $conversations,
            'progress' => $progress, 'attempts' => $logs,
            'drills' => $drills,
            'excluded_other_authors_records' => $excluded,
        ];
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function profile_text($row, $key, $max, $allow_empty = false) {
    $value = $row[$key] ?? null;
    if (!is_string($value) || strlen($value) > $max || (!$allow_empty && trim($value) === '') || !preg_match('//u', $value)) {
        throw new InvalidArgumentException('Invalid profile field: ' . $key);
    }
    return $value;
}

function profile_number($row, $key, $maximum, $integer = false) {
    $value = $row[$key] ?? null;
    if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value) ||
        !is_finite((float) $value) || $value < 0 || $value > $maximum || ($integer && (float) $value != (int) $value)) {
        throw new InvalidArgumentException('Invalid profile number: ' . $key);
    }
    return $integer ? (int) $value : (float) $value;
}

function profile_date($row, $key, $nullable = false) {
    $value = $row[$key] ?? null;
    if ($value === null && $nullable) {
        return null;
    }
    if (!is_string($value)) {
        throw new InvalidArgumentException('Invalid profile date: ' . $key);
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
    if (!$date || $date->format('Y-m-d H:i:s') !== $value) {
        throw new InvalidArgumentException('Invalid profile date: ' . $key);
    }
    return $value;
}

function validate_profile($json) {
    if (!is_string($json) || strlen($json) > 4 * 1024 * 1024) {
        throw new InvalidArgumentException('Profile must be JSON no larger than 4 MiB.');
    }
    try {
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new InvalidArgumentException('Profile contains invalid JSON.');
    }
    if (!is_array($data) || ($data['format'] ?? '') !== 'typing-personal-profile' || ($data['version'] ?? null) !== 1) {
        throw new InvalidArgumentException('Unsupported personal profile format.');
    }
    foreach (['lessons' => 1000, 'conversations' => 500, 'progress' => 2000, 'attempts' => 10000] as $key => $limit) {
        if (!isset($data[$key]) || !is_array($data[$key]) || count($data[$key]) > $limit) {
            throw new InvalidArgumentException('Invalid profile collection: ' . $key);
        }
    }
    $validated = ['lessons' => [], 'conversations' => [], 'progress' => [], 'attempts' => []];
    $ids = [];
    foreach ($data['lessons'] as $row) {
        if (!is_array($row)) {
            throw new InvalidArgumentException('Invalid lesson record.');
        }
        $id = profile_number($row, 'id', 2147483647, true);
        $level = profile_number($row, 'level', 10, true);
        $text = profile_text($row, 'expected_sequence', 4000);
        $name = profile_text($row, 'name', 128);
        if (!$id || !$level || isset($ids[$id]) || !in_array($row['kind'] ?? '', ['owned', 'default'], true)) {
            throw new InvalidArgumentException('Invalid lesson identifier, kind or difficulty.');
        }
        $ids[$id] = true;
        $validated['lessons'][] = [
            'id' => $id, 'kind' => $row['kind'], 'name' => $name,
            'description' => profile_text($row, 'description', 8000, true),
            'level' => $level, 'expected_sequence' => strtoupper($text), 'pattern' => morse_pattern($text),
        ];
    }
    foreach ($data['conversations'] as $row) {
        if (!is_array($row) || !isset($row['messages']) || !is_array($row['messages']) || count($row['messages']) > 2000) {
            throw new InvalidArgumentException('Invalid conversation.');
        }
        $conversation = ['title' => profile_text($row, 'title', 128), 'created_at' => profile_date($row, 'created_at'), 'messages' => []];
        foreach ($row['messages'] as $message) {
            if (!is_array($message) || !in_array($message['role'] ?? null, ['user', 'assistant'], true)) {
                throw new InvalidArgumentException('Invalid conversation role.');
            }
            $conversation['messages'][] = [
                'role' => $message['role'], 'content' => profile_text($message, 'content', 8000),
                'created_at' => profile_date($message, 'created_at'),
            ];
        }
        $validated['conversations'][] = $conversation;
    }
    $progress_ids = [];
    foreach (['progress', 'attempts'] as $collection) {
        foreach ($data[$collection] as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('Invalid progress record.');
            }
            $id = profile_number($row, 'lesson_id', 2147483647, true);
            if (!isset($ids[$id])) {
                throw new InvalidArgumentException('Progress references a missing lesson.');
            }
            if ($collection === 'progress') {
                if (isset($progress_ids[$id])) {
                    throw new InvalidArgumentException('Duplicate lesson progress.');
                }
                $progress_ids[$id] = true;
                $validated[$collection][] = [
                    $id, profile_number($row, 'attempts', 2147483647, true),
                    profile_number($row, 'best_accuracy', 1), profile_number($row, 'avg_speed', 86400000),
                    profile_date($row, 'last_attempt_at', true),
                ];
            } else {
                $validated[$collection][] = [
                    $id, profile_date($row, 'started_at'), profile_number($row, 'duration_ms', 86400000, true),
                    profile_number($row, 'accuracy', 1), profile_text($row, 'raw_input', 4000, true),
                ];
            }
        }
    }
    if (isset($data['drills'])) {
        $drills = $data['drills'];
        if (!is_array($drills) || !array_key_exists('preferences', $drills) ||
            !isset($drills['sessions']) || !is_array($drills['sessions']) || count($drills['sessions']) > 1000) {
            throw new InvalidArgumentException('Invalid drill profile collection.');
        }
        $validated['drills'] = ['preferences' => $drills['preferences'] === null ? null : drill_preferences($drills['preferences']), 'sessions' => []];
        foreach ($drills['sessions'] as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('Invalid drill profile session.');
            }
            $validated['drills']['sessions'][] = ['session' => drill_validate_session($row['session'] ?? null), 'created_at' => profile_date($row, 'created_at')];
        }
    }
    return $validated;
}

function import_profile(PDO $pdo, $user_id, $json) {
    $profile = validate_profile($json);
    $hash = hash('sha256', json_encode($profile, JSON_THROW_ON_ERROR));
    $pdo->beginTransaction();
    try {
        // Lock the account so simultaneous restore requests cannot create duplicates.
        $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$user_id]);
        $existing = $pdo->prepare('SELECT content_hash FROM profile_imports WHERE user_id = ? AND content_hash = ?');
        $existing->execute([$user_id, $hash]);
        if ($existing->fetchColumn()) {
            throw new InvalidArgumentException('This profile has already been restored to your account.');
        }
        $map = [];
        foreach ($profile['lessons'] as $lesson) {
            $new_id = false;
            if ($lesson['kind'] === 'default') {
                $find = $pdo->prepare('SELECT id FROM lessons WHERE owner_id IS NULL AND name = ? AND expected_sequence = ? LIMIT 1');
                $find->execute([$lesson['name'], $lesson['expected_sequence']]);
                $new_id = $find->fetchColumn();
            }
            if (!$new_id) {
                $pdo->prepare('INSERT INTO lessons (name, description, level, pattern, expected_sequence, owner_id, is_public) VALUES (?, ?, ?, ?, ?, ?, 0)')
                    ->execute([$lesson['name'], $lesson['description'], $lesson['level'], $lesson['pattern'], $lesson['expected_sequence'], $user_id]);
                $new_id = $pdo->lastInsertId();
            }
            $map[$lesson['id']] = (int) $new_id;
        }
        foreach ($profile['conversations'] as $conversation) {
            $pdo->prepare('INSERT INTO conversations (owner_id, title, created_at) VALUES (?, ?, ?)')
                ->execute([$user_id, $conversation['title'], $conversation['created_at']]);
            $conversation_id = $pdo->lastInsertId();
            foreach ($conversation['messages'] as $message) {
                $pdo->prepare('INSERT INTO conversation_messages (conversation_id, role, content, created_at) VALUES (?, ?, ?, ?)')
                    ->execute([$conversation_id, $message['role'], $message['content'], $message['created_at']]);
            }
        }
        foreach ($profile['progress'] as [$old_id, $attempts, $accuracy, $speed, $last]) {
            $pdo->prepare('INSERT INTO user_progress (user_id, lesson_id, attempts, best_accuracy, avg_speed, last_attempt_at) VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE best_accuracy = GREATEST(best_accuracy, VALUES(best_accuracy)),
                avg_speed = IF(attempts + VALUES(attempts) = 0, 0, (avg_speed * attempts + VALUES(avg_speed) * VALUES(attempts)) / (attempts + VALUES(attempts))),
                attempts = attempts + VALUES(attempts),
                last_attempt_at = CASE WHEN last_attempt_at IS NULL THEN VALUES(last_attempt_at) WHEN VALUES(last_attempt_at) IS NULL THEN last_attempt_at ELSE GREATEST(last_attempt_at, VALUES(last_attempt_at)) END')
                ->execute([$user_id, $map[$old_id], $attempts, $accuracy, $speed, $last]);
        }
        foreach ($profile['attempts'] as [$old_id, $started, $duration, $accuracy, $input]) {
            $pdo->prepare('INSERT INTO keystroke_log (user_id, lesson_id, started_at, duration_ms, accuracy, raw_input) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$user_id, $map[$old_id], $started, $duration, $accuracy, $input]);
        }
        if (isset($profile['drills'])) {
            foreach ($profile['drills']['sessions'] as $row) {
                $pdo->prepare('INSERT INTO drill_sessions (user_id, payload, created_at) VALUES (?, ?, ?)')
                    ->execute([$user_id, json_encode($row['session'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $row['created_at']]);
            }
            if ($profile['drills']['preferences'] !== null) {
                $pdo->prepare('INSERT INTO drill_preferences (user_id, preferences) VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE preferences = VALUES(preferences)')
                    ->execute([$user_id, json_encode($profile['drills']['preferences'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            }
        }
        $pdo->prepare('INSERT INTO profile_imports (user_id, content_hash) VALUES (?, ?)')->execute([$user_id, $hash]);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function export_sources($root) {
    $files = [];
    foreach ([
        'config' => ['api.php', 'assistant.php', 'auth.php', 'bootstrap.php', 'config.php', 'lang.php', 'language_controls.php',
            'drills.php', 'lessons.php', 'migrations.php', 'profiles.php', 'setup.php', 'web.php'],
        'public' => ['account.php', 'dashboard.php', 'drills.php', 'export.php', 'health.php', 'index.php', 'installer.php', 'router.php', 'studio.php'],
        'public/api' => ['assistant.php', 'conversations.php', 'drills.php', 'lessons.php', 'profile.php', 'submit.php'],
        'scripts' => ['generate_lessons.php', 'migrate.php', 'package.php', 'setup.ps1'],
        'sql' => ['schema.sql'],
        'tests' => ['smoke.php', 'drills.php', 'integration.php', 'setup.test.ps1', 'app.test.cjs', 'drills.test.cjs', 'studio.test.cjs', 'translate.test.js'],
        'assets/js' => ['app.js', 'audio.js', 'drills.js', 'studio.js', 'translate.js'],
        'assets/css' => ['style.css'],
        'assets' => ['logo.gif'],
        'lang' => ['en.json', 'es.json', 'fr.json', 'de.json', 'it.json', 'pt.json', 'nl.json', 'pl.json', 'ru.json', 'ar.json', 'hi.json'],
        'vocabulary' => ['layouts.json', 'en.json', 'es.json', 'fr.json', 'de.json', 'it.json', 'pt.json', 'nl.json', 'pl.json', 'ru.json', 'ar.json', 'hi.json'],
        '' => ['README.md', '.gitignore', '.htaccess', 'install.bat', 'start.bat', 'clean_repo.bat', 'git_push_progress.bat'],
    ] as $directory => $names) {
        foreach ($names as $name) {
            $relative = ($directory === '' ? '' : $directory . '/') . $name;
            $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($path) || is_link($path)) {
                throw new RuntimeException('Required export source missing or linked: ' . $relative);
            }
            $files[$relative] = $path;
        }
    }
    return $files;
}

function create_personal_archive($root, array $profile) {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Personal ZIP export requires PHP ZipArchive. Enable the zip extension in the host PHP configuration.');
    }
    $json = json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    // Never produce a backup that our own importer cannot restore.
    validate_profile($json);
    $path = tempnam(sys_get_temp_dir(), 'typing-export-');
    if ($path === false) {
        throw new RuntimeException('Cannot create a temporary download.');
    }
    $zip = new ZipArchive();
    $opened = false;
    try {
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot open the ZIP download.');
        }
        $opened = true;
        foreach (export_sources($root) as $relative => $source) {
            if (!$zip->addFile($source, $relative)) {
                throw new RuntimeException('Cannot add source to the ZIP.');
            }
        }
        $published = [];
        foreach ($profile['lessons'] as $lesson) {
            if ($lesson['kind'] === 'owned' && (int) $lesson['is_public'] === 1) {
                $published[] = array_intersect_key($lesson, array_flip(['name', 'description', 'level', 'expected_sequence']));
            }
        }
        if (!$zip->addFromString('data/profile.json', $json) ||
            !$zip->addFromString('data/published-lessons.json', json_encode($published, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))) {
            throw new RuntimeException('Cannot add personal data to the ZIP.');
        }
        if (!$zip->close()) {
            throw new RuntimeException('Cannot finalize the ZIP download.');
        }
        $opened = false;
        return $path;
    } catch (Throwable $error) {
        if ($opened) {
            $zip->close();
        }
        if (!unlink($path)) {
            error_log('Could not remove failed export archive.');
        }
        throw $error;
    }
}
