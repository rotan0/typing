<?php
require_once __DIR__ . '/../config/lang.php';
require_once __DIR__ . '/../config/lessons.php';
require_once __DIR__ . '/../config/setup.php';
require_once __DIR__ . '/../config/profiles.php';
require_once __DIR__ . '/../config/assistant.php';

function check($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS: $message\n";
}

$english = load_lang('en');
$languages = available_languages();
check(count($languages) === 11, 'English plus exactly ten additional languages');
foreach ($languages as $code => $name) {
    $catalog = language_catalog($code);
    check(!array_diff_key($english, $catalog['strings']) && !array_diff_key($catalog['strings'], $english), "$code has exactly the English translation keys");
    check(count(array_filter($catalog['strings'], function ($value) { return trim($value) === ''; })) === 0, "$code has no empty translations");
}
check(language_direction('ar') === 'rtl' && language_direction('hi') === 'ltr', 'Arabic direction is RTL');
$_GET['lang'] = 'hi';
check(selected_language() === 'hi' && $_SESSION['lang'] === 'hi', 'Language selection persists in session');
unset($_GET['lang']);
check(selected_language() === 'hi', 'Session language is reused');
check(h('<script>"&') === '&lt;script&gt;&quot;&amp;', 'Rendered strings are escaped');
check(load_lang('missing') === $english, 'Unknown catalog falls back to English with logging');
$rejected = false;
try { language_catalog('../config'); } catch (InvalidArgumentException $error) { $rejected = true; }
check($rejected, 'Language paths reject traversal');

$token = csrf_token();
$_POST['csrf_token'] = $token;
check_csrf();
check(strlen($token) === 64, 'CSRF token accepts the matching form');
foreach (['wrong', [], null] as $invalid) {
    $_POST['csrf_token'] = $invalid;
    $rejected = false;
    try { check_csrf(); } catch (RuntimeException $error) { $rejected = true; }
    check($rejected, 'Invalid CSRF input is rejected');
}

$lessons = default_lessons();
check(count($lessons) === 5 && $lessons[0][3] === '... --- ...', 'Five lessons and SOS Morse pattern match the original generator');
check($lessons[4][4] === '0123456789' && $lessons[4][2] === 3, 'Numeric lesson and difficulty are preserved');
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE lessons (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, description TEXT, level INTEGER, pattern TEXT, expected_sequence TEXT, owner_id INTEGER NULL)');
generate_lessons($pdo);
$before = $pdo->query('SELECT * FROM lessons ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
generate_lessons($pdo);
check($before === $pdo->query('SELECT * FROM lessons ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'Rebuilding lessons is idempotent and keeps IDs stable');
$pdo->exec("INSERT INTO lessons (name, expected_sequence) VALUES ('Custom', 'CUSTOM')");
$pdo->exec("UPDATE lessons SET pattern = 'incorrect' WHERE name = 'SOS'");
generate_lessons($pdo);
check((int) $pdo->query('SELECT COUNT(*) FROM lessons')->fetchColumn() === 6, 'Custom lessons are preserved');
check($pdo->query("SELECT pattern FROM lessons WHERE name = 'SOS'")->fetchColumn() === '... --- ...', 'Default lesson content is repaired without replacing IDs');
$pdo->exec("INSERT INTO lessons (name, expected_sequence, pattern, owner_id) VALUES ('SOS', 'SOS', 'PERSONAL', 1)");
generate_lessons($pdo);
check($pdo->query('SELECT pattern FROM lessons WHERE owner_id = 1')->fetchColumn() === 'PERSONAL', 'Default rebuild never edits a user-owned lesson with the same name');
check(morse_pattern('A 1') === '.- / .----', 'Personal lessons encode spaces and digits');
$_SERVER['SCRIPT_NAME'] = '/typing/public/api/lessons.php';
check(app_url('studio.php') === '/typing/studio.php', 'Subdirectory routes remove internal public/api paths');
$_SERVER['SCRIPT_NAME'] = '/api/lessons.php';
check(app_url() === '/', 'Root install base path is preserved');
$_SERVER['SCRIPT_NAME'] = '/public/installer.php';
$_SERVER['REQUEST_URI'] = '/typing/installer.php';
check(app_url('account.php') === '/typing/account.php', 'Original request preserves Apache alias mount path');
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['HTTPS'] = 'on';
$rejected = false;
try { assistant_gateway(); } catch (AssistantFailure $error) { $rejected = $error->status === 503; }
check($rejected, 'Downloaded/local tutor never enables hosted model access');
$_SERVER['HTTP_HOST'] = 'equal-page.free.nf';
$_SERVER['HTTPS'] = 'off';
$rejected = false;
try { assistant_gateway(); } catch (AssistantFailure $error) { $rejected = $error->status === 503; }
check($rejected, 'Assistant refuses a non-HTTPS deployment');
unset($_SERVER['HTTP_HOST'], $_SERVER['HTTPS']);
unset($_SERVER['REQUEST_URI']);
$profile = [
    'format' => 'typing-personal-profile', 'version' => 1,
    'lessons' => [['id' => 1, 'kind' => 'owned', 'name' => 'Practice', 'description' => '',
        'level' => 1, 'expected_sequence' => 'SOS', 'pattern' => 'untrusted']],
    'conversations' => [['title' => 'Goals', 'created_at' => '2026-10-16 12:00:00',
        'messages' => [['role' => 'assistant', 'content' => '<script>untrusted</script>', 'created_at' => '2026-10-16 12:00:00']]]],
    'progress' => [], 'attempts' => [],
];
$validated = validate_profile(json_encode($profile));
check($validated['lessons'][0]['pattern'] === '... --- ...', 'Imported Morse patterns are recomputed from validated text');
foreach (['system-role', 'bad-date', 'duplicate-id', 'invalid-morse', 'missing-reference'] as $case) {
    $bad = $profile;
    if ($case === 'system-role') { $bad['conversations'][0]['messages'][0]['role'] = 'system'; }
    if ($case === 'bad-date') { $bad['conversations'][0]['created_at'] = '2026-02-31 12:00:00'; }
    if ($case === 'duplicate-id') { $bad['lessons'][] = $bad['lessons'][0]; }
    if ($case === 'invalid-morse') { $bad['lessons'][0]['expected_sequence'] = '<script>'; }
    if ($case === 'missing-reference') { $bad['progress'][] = ['lesson_id' => 99]; }
    $rejected = false;
    try { validate_profile(json_encode($bad)); } catch (InvalidArgumentException $error) { $rejected = true; }
    check($rejected, 'Malformed profile rejected: ' . $case);
}
$pdo->exec("CREATE TRIGGER fail_update BEFORE UPDATE ON lessons BEGIN SELECT RAISE(ABORT, 'simulated failure'); END");
$rejected = false;
try { generate_lessons($pdo); } catch (PDOException $error) { $rejected = true; }
check($rejected && !$pdo->inTransaction(), 'Database failures propagate and roll back the lesson transaction');
echo "All PHP smoke checks passed.\n";
