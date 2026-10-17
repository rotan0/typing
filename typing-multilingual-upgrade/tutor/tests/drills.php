<?php
require_once __DIR__ . '/../config/drills.php';

function drill_check($condition, $message) {
    static $checks = 0;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
    return $checks;
}
function drill_reject(callable $action, $message) {
    try {
        $action();
    } catch (InvalidArgumentException $error) {
        drill_check(true, $message);
        return;
    }
    throw new RuntimeException($message);
}
$catalog = drill_catalog();
drill_check(count($catalog['languages']) === 11, 'All eleven language vocabularies are bundled');
$keys = array_keys($catalog['languages']['en']['ui']);
foreach ($catalog['languages'] as $code => $language) {
    drill_check(array_keys($language['ui']) === $keys && !in_array('', $language['ui'], true), "$code has a complete localized drill interface");
    $unique = [];
    for ($level = 1; $level <= 5; $level++) {
        $preferences = ['language' => $code, 'layout' => 'unrestricted', 'level' => $level, 'custom_words' => ''];
        for ($batch = 0; $batch < 30; $batch++) {
            $session = drill_generate($preferences);
            drill_check(count($session['lines']) === 10 && $session === drill_validate_session($session), "$code level $level batch $batch has ten valid restricted lines");
            foreach ($session['lines'] as $line) {
                $unique[$line] = true;
                drill_check(count(explode(' ', $line)) === 3 + $level, 'Difficulty controls line length');
            }
        }
    }
    drill_check(count($unique) >= 1000, "$code generates at least 1000 distinct practice lines");
}
foreach (['qwerty' => 'en', 'azerty' => 'fr', 'qwertz' => 'de', 'arabic101' => 'ar', 'inscript' => 'hi'] as $layout => $language) {
    for ($level = 1; $level <= 5; $level++) {
        $preferences = ['language' => $language, 'layout' => $layout, 'level' => $level];
        $session = drill_generate($preferences);
        $allowed = array_fill_keys(drill_characters(drill_allowed($session['preferences']) . ' '), true);
        foreach ($session['lines'] as $line) {
            drill_check(!array_diff_key(array_fill_keys(drill_characters($line), true), $allowed), "$layout level $level never leaks a disallowed character");
        }
    }
}
drill_reject(function () { drill_generate(['language' => 'ru', 'layout' => 'qwerty', 'level' => 1]); }, 'Incompatible layout fails explicitly; no silent unrestricted fallback');
drill_reject(function () { drill_generate(['language' => '../config', 'layout' => 'qwerty', 'level' => 1]); }, 'Language traversal is rejected');
drill_reject(function () { drill_generate(['language' => 'en', 'layout' => 'qwerty', 'level' => 6]); }, 'Out of range difficulty is rejected');
drill_reject(function () { drill_generate(['language' => 'en', 'layout' => 'unrestricted', 'level' => 1, 'custom_words' => '<script>']); }, 'Custom vocabulary cannot contain markup');
$custom = drill_preferences(['language' => 'en', 'layout' => 'qwerty', 'level' => 1, 'custom_words' => 'gaga vélo']);
drill_check(in_array('gaga', drill_pool($custom), true) && !in_array('vélo', drill_pool($custom), true), 'Custom words participate only when they satisfy the same key restrictions');
foreach (['سلام', 'पानी', 'école', 'зелёный'] as $word) {
    $result = drill_score($word, $word, 60000);
    drill_check($result['accuracy'] === 1 && abs($result['wpm'] - count(drill_characters($word)) / 5) < 0.000001, 'Unicode scoring uses code points, not UTF-8 bytes');
}
$result = drill_score('salad', 'Salads', 1000);
drill_check($result['correct'] === 4 && $result['total'] === 6 && isset($result['weak']['s']), 'Case, overtyping and missed target characters affect score');
drill_check(drill_score('salad', 'sal', 1000)['accuracy'] === 0.6, 'Missing target suffix is counted');
drill_reject(function () { drill_score('sad', 'sad', 0); }, 'Zero duration is rejected');
$session = drill_generate(['language' => 'en', 'layout' => 'unrestricted', 'level' => 1]);
$session['lines'] = array_fill(0, 10, str_repeat('a', 20));
$session['results'] = array_fill(0, 10, drill_score(str_repeat('a', 20), str_repeat('a', 19) . 'b', 10000));
drill_check(drill_summary($session)['passed'] === true && drill_summary($session)['accuracy'] === 0.95, 'Exactly 95 percent across ten drills passes');
$session['results'][0] = drill_score(str_repeat('a', 20), str_repeat('a', 18) . 'bb', 10000);
drill_check(drill_summary($session)['passed'] === false, 'Below 95 percent fails without rounding up');
array_pop($session['results']);
drill_check(drill_summary($session)['complete'] === false && drill_summary($session)['passed'] === false, 'Nine drills cannot unlock the next level');
$focused = drill_generate(['language' => 'en', 'layout' => 'qwerty', 'level' => 1], ['f' => 5]);
foreach ($focused['lines'] as $line) {
    foreach (explode(' ', $line) as $index => $word) {
        if ($index % 2 === 0) {
            drill_check(strpos($word, 'f') !== false, 'Adaptive repeat emphasizes the weak character in alternating words');
        }
    }
}
$bad = $focused;
$bad['lines'][0] = 'hello';
drill_reject(function () use ($bad) { drill_validate_session($bad); }, 'Imported sessions cannot bypass keyboard restrictions');
$focused['results'][] = drill_score($focused['lines'][0], $focused['lines'][0], 10000);
$focused['results'][0]['accuracy'] = 0;
drill_check(drill_validate_session($focused)['results'][0]['accuracy'] === 1, 'Profile results are recomputed rather than trusting supplied accuracy');
echo drill_check(true, 'Finished') . " multilingual drill engine checks passed.\n";
