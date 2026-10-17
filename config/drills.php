<?php

function drill_characters($text) {
    if (!is_string($text) || !preg_match('//u', $text)) {
        throw new InvalidArgumentException('Practice text must be valid UTF-8.');
    }
    return preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
}

function drill_catalog() {
    static $catalog;
    if ($catalog !== null) {
        return $catalog;
    }
    $languages = [];
    foreach (['en', 'es', 'fr', 'de', 'it', 'pt', 'nl', 'pl', 'ru', 'ar', 'hi'] as $code) {
        $path = __DIR__ . '/../vocabulary/' . $code . '.json';
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Cannot read vocabulary: ' . $code);
        }
        $entry = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($entry) || !is_string($entry['name'] ?? null) ||
            !in_array($entry['direction'] ?? null, ['ltr', 'rtl'], true) ||
            !is_array($entry['words'] ?? null) || count($entry['words']) < 20 ||
            !is_array($entry['ui'] ?? null)) {
            throw new RuntimeException('Invalid bundled vocabulary: ' . $code);
        }
        foreach ($entry['words'] as $word) {
            if (!is_string($word) || !preg_match('/^[\p{L}\p{M}]+$/uD', $word) || count(drill_characters($word)) > 40) {
                throw new RuntimeException('Invalid vocabulary word: ' . $code);
            }
        }
        $languages[$code] = $entry;
    }
    $layouts = json_decode(file_get_contents(__DIR__ . '/../vocabulary/layouts.json'), true, 16, JSON_THROW_ON_ERROR);
    $catalog = ['languages' => $languages, 'layouts' => $layouts];
    return $catalog;
}

function drill_preferences($input) {
    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid drill preferences.');
    }
    $catalog = drill_catalog();
    $language = $input['language'] ?? null;
    $layout = $input['layout'] ?? null;
    $level = filter_var($input['level'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
    $custom = $input['custom_words'] ?? '';
    if (!is_string($language) || !isset($catalog['languages'][$language]) ||
        !is_string($layout) || !isset($catalog['layouts'][$layout]) || !$level ||
        !is_string($custom) || strlen($custom) > 4000 || !preg_match('/^[\p{L}\p{M} ]*$/uD', $custom)) {
        throw new InvalidArgumentException('Choose a supported language, layout and level (1-5). Custom vocabulary accepts letters, combining marks and spaces only (4000 bytes).');
    }
    $custom = trim(preg_replace('/ +/', ' ', $custom));
    foreach (explode(' ', $custom) as $word) {
        if (count(drill_characters($word)) > 40) {
            throw new InvalidArgumentException('Custom words must contain at most 40 Unicode characters.');
        }
    }
    return ['language' => $language, 'layout' => $layout, 'level' => $level, 'custom_words' => $custom];
}

function drill_allowed($preferences) {
    $layout = drill_catalog()['layouts'][$preferences['layout']];
    if ($preferences['layout'] === 'unrestricted') {
        return null;
    }
    return implode('', array_slice($layout['tiers'], 0, $preferences['level']));
}

function drill_pool($preferences) {
    $words = drill_catalog()['languages'][$preferences['language']]['words'];
    if ($preferences['custom_words'] !== '') {
        $words = array_merge($words, explode(' ', $preferences['custom_words']));
    }
    $allowed = drill_allowed($preferences);
    $keys = $allowed === null ? null : array_fill_keys(drill_characters($allowed), true);
    $maximum = [1 => 6, 2 => 9, 3 => 14, 4 => 24, 5 => 40][$preferences['level']];
    return array_values(array_unique(array_filter($words, function ($word) use ($keys, $maximum) {
        $characters = drill_characters($word);
        if (count($characters) > $maximum) {
            return false;
        }
        foreach ($characters as $character) {
            if ($keys !== null && !isset($keys[$character])) {
                return false;
            }
        }
        return true;
    })));
}

function drill_generate($preferences, $weak = []) {
    $preferences = drill_preferences($preferences);
    $weak = array_slice($weak, 0, 20, true);
    $pool = drill_pool($preferences);
    if (count($pool) < 3) {
        throw new InvalidArgumentException('Fewer than three words fit this language/layout/level. Choose a compatible layout, a higher level, unrestricted mode, or add matching custom words. Restrictions have not been relaxed.');
    }
    $focus = array_values(array_filter($pool, function ($word) use ($weak) {
        foreach (drill_characters($word) as $character) {
            if (isset($weak[$character])) {
                return true;
            }
        }
        return false;
    }));
    $lines = [];
    $word_count = 3 + $preferences['level'];
    for ($line = 0; $line < 10; $line++) {
        $words = [];
        for ($index = 0; $index < $word_count; $index++) {
            $source = $focus && $index % 2 === 0 ? $focus : $pool;
            $words[] = $source[random_int(0, count($source) - 1)];
        }
        $lines[] = implode(' ', $words);
    }
    return ['preferences' => $preferences, 'lines' => $lines, 'results' => [], 'focus' => array_keys($weak)];
}

function drill_score($expected, $input, $duration) {
    if (!is_string($input) || strlen($input) > 4000 || !preg_match('/^[\p{L}\p{M} ]*$/uD', $input) ||
        !is_int($duration) || $duration < 1 || $duration > 86400000) {
        throw new InvalidArgumentException('Submit letters, combining marks and spaces with a positive duration of at most 24 hours.');
    }
    $target = drill_characters($expected);
    $typed = drill_characters($input);
    $total = max(count($target), count($typed));
    $correct = 0;
    $weak = [];
    for ($index = 0; $index < $total; $index++) {
        if (($target[$index] ?? null) === ($typed[$index] ?? null)) {
            $correct++;
        } elseif (isset($target[$index]) && $target[$index] !== ' ') {
            $weak[$target[$index]] = ($weak[$target[$index]] ?? 0) + 1;
        }
    }
    return ['input' => $input, 'duration_ms' => $duration, 'correct' => $correct, 'total' => $total,
        'accuracy' => $total ? $correct / $total : 1,
        'wpm' => count($typed) * 12000 / $duration, 'weak' => $weak];
}

function drill_summary($session) {
    $correct = 0;
    $total = 0;
    $duration = 0;
    $typed = 0;
    $weak = [];
    foreach ($session['results'] as $result) {
        $correct += $result['correct'];
        $total += $result['total'];
        $duration += $result['duration_ms'];
        $typed += count(drill_characters($result['input']));
        foreach ($result['weak'] as $character => $count) {
            $weak[$character] = ($weak[$character] ?? 0) + $count;
        }
    }
    arsort($weak);
    $complete = count($session['results']) === 10;
    $accuracy = $total ? $correct / $total : 0;
    return ['complete' => $complete, 'passed' => $complete && $accuracy >= 0.95,
        'accuracy' => $accuracy, 'wpm' => $duration ? $typed * 12000 / $duration : 0,
        'weak' => $weak, 'completed_drills' => count($session['results'])];
}

function drill_validate_session($data) {
    if (!is_array($data)) {
        throw new InvalidArgumentException('Invalid saved drill session.');
    }
    $preferences = drill_preferences($data['preferences'] ?? null);
    if (!isset($data['lines'], $data['results']) || !is_array($data['lines']) || count($data['lines']) !== 10 ||
        !is_array($data['results']) || count($data['results']) > 10 ||
        array_keys($data['lines']) !== range(0, 9) || array_keys($data['results']) !== array_keys(array_values($data['results']))) {
        throw new InvalidArgumentException('Invalid drill lines or results.');
    }
    $pool = array_fill_keys(drill_pool($preferences), true);
    foreach ($data['lines'] as $line) {
        if (!is_string($line) || strlen($line) > 4000 || trim($line) !== $line) {
            throw new InvalidArgumentException('Invalid saved practice line.');
        }
        foreach (explode(' ', $line) as $word) {
            if (!isset($pool[$word])) {
                throw new InvalidArgumentException('Saved drill violates vocabulary or keyboard restrictions.');
            }
        }
    }
    $results = [];
    foreach (array_values($data['results']) as $index => $result) {
        if (!is_array($result)) {
            throw new InvalidArgumentException('Invalid saved drill result.');
        }
        $results[] = drill_score($data['lines'][$index], $result['input'] ?? null, $result['duration_ms'] ?? null);
    }
    $focus = $data['focus'] ?? [];
    if (!is_array($focus) || count($focus) > 100) {
        throw new InvalidArgumentException('Invalid drill focus.');
    }
    foreach ($focus as $character) {
        if (!is_string($character) || count(drill_characters($character)) !== 1) {
            throw new InvalidArgumentException('Invalid focus character.');
        }
    }
    return ['preferences' => $preferences, 'lines' => array_values($data['lines']), 'results' => $results, 'focus' => array_values($focus)];
}

function drill_state(PDO $pdo, $user_id) {
    $query = $pdo->prepare('SELECT preferences FROM drill_preferences WHERE user_id = ?');
    $query->execute([$user_id]);
    $preferences = $query->fetchColumn();
    $query = $pdo->prepare('SELECT id, payload FROM drill_sessions WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $query->execute([$user_id]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    $session = $row ? json_decode($row['payload'], true, 16, JSON_THROW_ON_ERROR) : null;
    $query = $pdo->prepare('SELECT id, payload, created_at FROM drill_sessions WHERE user_id = ? ORDER BY id DESC LIMIT 10');
    $query->execute([$user_id]);
    $history = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $payload = json_decode($item['payload'], true, 16, JSON_THROW_ON_ERROR);
        $history[] = ['id' => (int) $item['id'], 'preferences' => $payload['preferences'],
            'created_at' => $item['created_at'], 'summary' => drill_summary($payload)];
    }
    return ['preferences' => $preferences ? json_decode($preferences, true, 16, JSON_THROW_ON_ERROR) : null,
        'session' => $session, 'session_id' => $row ? (int) $row['id'] : null,
        'summary' => $session ? drill_summary($session) : null, 'history' => $history];
}

function drill_save_session(PDO $pdo, $user_id, $session) {
    $json = json_encode($session, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $pdo->prepare('INSERT INTO drill_preferences (user_id, preferences) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE preferences = VALUES(preferences)')
        ->execute([$user_id, json_encode($session['preferences'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    $pdo->prepare('INSERT INTO drill_sessions (user_id, payload) VALUES (?, ?)')->execute([$user_id, $json]);
    return (int) $pdo->lastInsertId();
}
