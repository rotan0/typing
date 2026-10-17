<?php
function morse_pattern($text) {
    static $map = null;
    if ($map === null) {
        $patterns = ['.-', '-...', '-.-.', '-..', '.', '..-.', '--.', '....', '..', '.---',
            '-.-', '.-..', '--', '-.', '---', '.--.', '--.-', '.-.', '...', '-', '..-',
            '...-', '.--', '-..-', '-.--', '--..', '-----', '.----', '..---', '...--',
            '....-', '.....', '-....', '--...', '---..', '----.'];
        $map = array_combine(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), $patterns);
        $map[' '] = '/';
    }
    $tokens = [];
    foreach (str_split(strtoupper($text)) as $letter) {
        if (!isset($map[$letter])) {
            throw new InvalidArgumentException('Morse lessons support A-Z, digits and spaces only.');
        }
        $tokens[] = $map[$letter];
    }
    return implode(' ', $tokens);
}

function default_lessons() {
    $lessons = [];
    foreach ([
        ['SOS', 'SOS', 1], ['CQ', 'CQ', 1], ['HELLO', 'HELLO', 2],
        ['A-Z', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 3], ['NUMBERS', '0123456789', 3],
    ] as [$name, $text, $level]) {
        $lessons[] = [$name, "Morse typing for $text", $level, morse_pattern($text), $text];
    }
    return $lessons;
}

function generate_lessons(PDO $pdo) {
    $pdo->beginTransaction();
    try {
        // Keep lesson IDs stable and never overwrite a user-owned lesson.
        $find = $pdo->prepare('SELECT id FROM lessons WHERE name = ? AND expected_sequence = ? AND owner_id IS NULL ORDER BY id LIMIT 1');
        $insert = $pdo->prepare('INSERT INTO lessons (name, description, level, pattern, expected_sequence) VALUES (?, ?, ?, ?, ?)');
        $update = $pdo->prepare('UPDATE lessons SET description = ?, level = ?, pattern = ? WHERE id = ?');
        foreach (default_lessons() as $lesson) {
            $find->execute([$lesson[0], $lesson[4]]);
            $id = $find->fetchColumn();
            if ($id === false) {
                $insert->execute($lesson);
            } else {
                $update->execute([$lesson[1], $lesson[2], $lesson[3], $id]);
            }
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function install_published_lessons(PDO $pdo) {
    $file = __DIR__ . '/../data/published-lessons.json';
    if (!is_file($file)) {
        return;
    }
    if (filesize($file) > 4 * 1024 * 1024) {
        throw new RuntimeException('Published lesson collection exceeds 4 MiB.');
    }
    $contents = file_get_contents($file);
    if ($contents === false) {
        throw new RuntimeException('Cannot read published lessons.');
    }
    $lessons = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($lessons) || count($lessons) > 1000) {
        throw new RuntimeException('Invalid published lesson collection.');
    }
    $pdo->beginTransaction();
    try {
        foreach ($lessons as $lesson) {
            if (!is_array($lesson) || !is_string($lesson['name'] ?? null) || trim($lesson['name']) === '' || strlen($lesson['name']) > 128 ||
                !is_string($lesson['description'] ?? null) || strlen($lesson['description']) > 8000 ||
                !is_string($lesson['expected_sequence'] ?? null) || trim($lesson['expected_sequence']) === '' || strlen($lesson['expected_sequence']) > 4000 ||
                !is_int($lesson['level'] ?? null) || $lesson['level'] < 1 || $lesson['level'] > 10) {
                throw new RuntimeException('Invalid published lesson.');
            }
            $text = strtoupper($lesson['expected_sequence']);
            $pattern = morse_pattern($text);
            $find = $pdo->prepare('SELECT id FROM lessons WHERE owner_id IS NULL AND name = ? AND expected_sequence = ? LIMIT 1');
            $find->execute([$lesson['name'], $text]);
            if (!$find->fetchColumn()) {
                $pdo->prepare('INSERT INTO lessons (name, description, level, pattern, expected_sequence) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$lesson['name'], $lesson['description'], $lesson['level'], $pattern, $text]);
            }
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}
