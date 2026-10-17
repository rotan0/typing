<?php
if (PHP_SAPI !== 'cli') {
    exit('Command-line use only.');
}

function verify($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS: $message\n";
}

function request($path, $post = null) {
    global $base, $curl;
    curl_setopt_array($curl, [
        CURLOPT_URL => $base . $path,
        CURLOPT_POST => $post !== null,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_COOKIEFILE => '',
    ]);
    if ($post !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($curl);
    if ($body === false) {
        throw new RuntimeException(curl_error($curl));
    }
    return [curl_getinfo($curl, CURLINFO_HTTP_CODE), $body];
}

function form_token($body) {
    if (!preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $body, $match)) {
        throw new RuntimeException('CSRF token missing from wizard.');
    }
    return $match[1];
}

$source = dirname(__DIR__);
require_once $source . '/config/profiles.php';
require_once $source . '/config/assistant.php';
$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typing-integration-' . bin2hex(random_bytes(8));
$database = 'typing_test_' . bin2hex(random_bytes(8));
$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$port = getenv('TEST_DB_PORT') ?: '3306';
$user = getenv('TEST_DB_USER') ?: 'root';
$password = getenv('TEST_DB_PASSWORD') ?: '';
$admin = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server = null;
$curl = curl_init();
$success = false;
try {
    mkdir($temporary);
    foreach (export_sources($source) as $relative => $file) {
            $destination = $temporary . DIRECTORY_SEPARATOR . $relative;
            if (!is_dir(dirname($destination))) {
                mkdir(dirname($destination), 0777, true);
            }
            copy($file, $destination);
    }
    $socket = stream_socket_server('tcp://127.0.0.1:0', $error_number, $error_message);
    if ($socket === false) {
        throw new RuntimeException($error_message);
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $base = 'http://' . $address;
    $log = $temporary . DIRECTORY_SEPARATOR . 'server.log';
    $command = [PHP_BINARY];
    if (php_ini_loaded_file()) {
        $command = array_merge($command, ['-c', php_ini_loaded_file()]);
    }
    $ini_contents = php_ini_loaded_file() ? file_get_contents(php_ini_loaded_file()) : '';
    if (!preg_match('/^\s*extension\s*=\s*"?(?:php_)?zip(?:\.dll)?"?\s*$/m', $ini_contents)) {
        $command = array_merge($command, ['-d', 'extension=zip']);
    }
    $command = array_merge($command, ['-S', $address, '-t', $temporary . DIRECTORY_SEPARATOR . 'public', $temporary . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'router.php']);
    $apache = getenv('TEST_APACHE_ROOT');
    if ($apache) {
        $apache = str_replace('\\', '/', $apache);
        $directory = str_replace('\\', '/', $temporary);
        $php_directory = str_replace('\\', '/', dirname(PHP_BINARY));
        mkdir($temporary . '/apache-php');
        $apache_ini = $ini_contents . "\nextension_dir=\"$php_directory/ext\"\n";
        if (!preg_match('/^\s*extension\s*=\s*"?(?:php_)?zip(?:\.dll)?"?\s*$/m', $ini_contents)) {
            $apache_ini .= "extension=zip\n";
        }
        file_put_contents($temporary . '/apache-php/php.ini', $apache_ini);
        $configuration = "ServerRoot \"$apache\"\nServerName 127.0.0.1\nListen $address\n";
        $configuration .= "PidFile \"$directory/apache.pid\"\nErrorLog \"$directory/server.log\"\n";
        foreach (['authz_core', 'authz_host', 'alias', 'dir', 'mime', 'rewrite'] as $module) {
            $configuration .= "LoadModule {$module}_module \"$apache/modules/mod_$module.so\"\n";
        }
        foreach (['php8ts.dll', 'libpq.dll', 'libsqlite3.dll'] as $library) {
            $configuration .= "LoadFile \"$php_directory/$library\"\n";
        }
        $configuration .= "LoadModule php_module \"$php_directory/php8apache2_4.dll\"\nPHPIniDir \"$directory/apache-php\"\n";
        $configuration .= "TypesConfig \"$apache/conf/mime.types\"\nDocumentRoot \"$directory/public\"\n";
        $configuration .= "<FilesMatch \"\\.php$\">\nSetHandler application/x-httpd-php\n</FilesMatch>\n";
        $configuration .= "Alias /typing \"$directory\"\n<Directory \"$directory\">\nAllowOverride All\nRequire all granted\n</Directory>\n";
        file_put_contents($temporary . '/apache-test.conf', $configuration);
        $command = [$apache . '/bin/httpd.exe', '-f', $temporary . '/apache-test.conf', '-X'];
        $base .= '/typing';
    }
    $server = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $temporary);
    if (!is_resource($server)) {
        throw new RuntimeException('Could not start isolated PHP server.');
    }
    fclose($pipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        usleep(100000);
        try {
            [$status, $body] = request('/health.php');
            $ready = $status === 200;
            if ($ready) { break; }
        } catch (RuntimeException $error) {
            if (!proc_get_status($server)['running']) { throw $error; }
        }
    }
    verify($ready, 'Isolated application server becomes responsive');
    if ($apache) {
        [$status, $body] = request('/installer.php');
        verify(strpos($body, 'href="/typing/assets/css/style.css"') !== false, 'Real Apache /typing installer renders subdirectory asset URLs; HTTP ' . $status . ', hrefs: ' .
            implode(', ', preg_match_all('/href="([^"]+)"/', $body, $links) ? $links[1] : [substr(strip_tags($body), 0, 300)]));
    }
    [$status, $body] = request('/');
    verify($status === 200 && strpos($body, 'Step 1 of 3') !== false, 'Unconfigured tutor redirects to wizard');
    [$status, $body] = request('/installer.php', ['action' => 'checks', 'csrf_token' => 'invalid']);
    verify(strpos($body, 'Invalid form token') !== false && strpos($body, 'Step 1 of 3') !== false, 'Wizard rejects CSRF failures without advancing');
    $token = form_token($body);
    [$status, $body] = request('/installer.php', ['action' => 'checks', 'csrf_token' => $token]);
    verify(strpos($body, 'Step 2 of 3') !== false, 'Requirements step advances to database form');
    [$status, $body] = request('/installer.php', [
        'action' => 'database', 'csrf_token' => $token,
        'host' => $host, 'port' => $port, 'name' => 'invalid-name', 'user' => $user, 'password' => $password,
    ]);
    verify(strpos($body, 'Step 2 of 3') !== false && strpos($body, 'Enter a valid host') !== false, 'Invalid database identifier is rejected');
    [$status, $body] = request('/installer.php', [
        'action' => 'database', 'csrf_token' => $token,
        'host' => $host, 'port' => $port, 'name' => $database, 'user' => $user, 'password' => $password,
    ]);
    verify(strpos($body, 'Step 3 of 3') !== false, 'Connection is tested before creating the database');
    [$status, $body] = request('/installer.php', ['action' => 'install', 'csrf_token' => $token]);
    verify(strpos($body, 'Setup complete') !== false && is_file($temporary . '/config/installed.lock'), 'Wizard creates schema, settings and completion lock');
    [$status, $body] = request('/api/lessons.php');
    verify($status === 401, 'Lesson API requires a real account; no demo auto-login');
    [$status, $body] = request('/account.php');
    [$status, $body] = request('/account.php', ['csrf_token' => form_token($body), 'action' => 'register', 'username' => 'alice', 'password' => 'Alice-password-123']);
    verify($status === 200 && strpos($body, 'Personal lesson studio') !== false, 'Registration creates an account and opens the studio');
    $token = form_token($body);
    if ($apache) {
        verify(strpos($body, 'content="/typing"') !== false && strpos($body, 'action="/typing/export.php"') !== false, 'Studio API base and export forms stay under /typing');
    }
    [$status, $body] = request('/api/lessons.php');
    $lessons = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    verify($status === 200 && count($lessons) === 5, 'MySQL contains all five starter lessons');
    [$status, $body] = request('/?lang=ar');
    verify($status === 200 && strpos($body, 'dir="rtl"') !== false && strpos($body, 'منطقة الكتابة') !== false, 'Tutor renders Arabic with RTL layout');
    verify(strpos($body, 'google_translate_element') !== false && strpos($body, 'https://translate.google.com/') !== false, 'Google backup widget container and permanent link are present');
    [$status, $body] = request('/api/submit.php', ['csrf_token' => $token, 'lesson_id' => $lessons[0]['id'], 'raw_input' => 'SOS', 'duration_ms' => 1200]);
    $result = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    verify($status === 200 && (float) $result['accuracy'] === 1.0 && $result['duration_ms'] === 1200, 'Existing lesson submission and progress work after installation');
    [$status, $body] = request('/dashboard.php');
    [$status, $body] = request('/dashboard.php', ['action' => 'rebuild_lessons', 'csrf_token' => form_token($body)]);
    verify($status === 200 && strpos($body, 'تم إنشاء الدروس') !== false, 'Maintenance rebuild uses native PHP and selected locale');
    [$status, $body] = request('/api/lessons.php');
    verify(json_decode($body, true) === $lessons, 'Maintenance preserves existing lesson IDs and avoids duplicates');
    verify((int) $admin->query("SELECT attempts FROM `$database`.user_progress LIMIT 1")->fetchColumn() === 1, 'Lesson rebuild preserves saved progress');
    [$status, $body] = request('/api/lessons.php', [
        'csrf_token' => $token, 'action' => 'save', 'name' => 'Alice private lesson',
        'description' => 'ALICE_PRIVATE_DESCRIPTION', 'level' => 2, 'expected_sequence' => 'HELLO 123',
    ]);
    $alice_lesson = json_decode($body, true, 512, JSON_THROW_ON_ERROR)['id'] ?? null;
    verify($status === 200 && $alice_lesson, 'Authenticated user can save a private custom lesson');
    [$status, $body] = request('/api/conversations.php', ['csrf_token' => $token, 'action' => 'create', 'title' => 'Alice design']);
    $alice_conversation = json_decode($body, true, 512, JSON_THROW_ON_ERROR)['id'] ?? null;
    verify($status === 200 && $alice_conversation, 'Private design conversation persists');
    [$status] = request('/api/conversations.php', [
        'csrf_token' => $token, 'action' => 'note', 'conversation_id' => $alice_conversation,
        'content' => 'ALICE_PRIVATE_NOTE <script>alert(1)</script>',
    ]);
    verify($status === 200, 'Design note is saved as text, not executed');
    [$status, $body] = request('/api/conversations.php?id=' . $alice_conversation);
    verify(strpos($body, 'ALICE_PRIVATE_NOTE') !== false, 'Saved conversation reloads from the database');
    [$status, $body] = request('/api/assistant.php');
    verify($status === 503 && json_decode($body, true)['available'] === false, 'Unconfigured assistant is explicitly unavailable without remote model calls');
    $assistant_pdo = new PDO("mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $alice_id = $assistant_pdo->query("SELECT id FROM users WHERE username = 'alice'")->fetchColumn();
    $assistant_result = assistant_reply($assistant_pdo, $alice_id, $alice_conversation, 'approved-local', 'Design a short SOS lesson.',
        function ($payload) {
            verify($payload['messages'][count($payload['messages']) - 1]['content'] === 'Design a short SOS lesson.', 'Assistant context ends with the current learner question');
            verify(count($payload['messages']) <= 12, 'Assistant sends a bounded private conversation context');
            return ['content' => "Practice this slowly:\n```text\nSOS 123\n```", 'model' => 'approved-local'];
        });
    verify(strpos($assistant_result['content'], 'SOS 123') !== false, 'Assistant reply accepts the configured model response');
    [$status, $body] = request('/api/conversations.php?id=' . $alice_conversation);
    $messages = json_decode($body, true)['messages'];
    verify(count($messages) === 3 && $messages[2]['role'] === 'assistant', 'User question and assistant reply persist together in the private conversation');
    $rejected = false;
    try {
        assistant_reply($assistant_pdo, $alice_id, $alice_conversation, 'approved-local', 'Failed question', function () {
            throw new AssistantFailure('Simulated upstream error.', 502);
        });
    } catch (AssistantFailure $error) { $rejected = $error->status === 502; }
    verify($rejected && (int) $assistant_pdo->query('SELECT COUNT(*) FROM conversation_messages')->fetchColumn() === 3, 'Provider failure does not save a fake reply or partial question');
    $lock = 'typing-assistant-' . substr(hash('sha256', $database . ':' . $alice_id), 0, 40);
    $admin->prepare('SELECT GET_LOCK(?, 0)')->execute([$lock]);
    $rejected = false;
    try {
        assistant_reply($assistant_pdo, $alice_id, $alice_conversation, 'approved-local', 'Parallel question', function () { throw new LogicException('Must not call model'); });
    } catch (AssistantFailure $error) { $rejected = $error->status === 429; }
    $admin->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
    verify($rejected, 'Concurrent assistant requests for the same account are rejected before inference');
    $rejected = false;
    try {
        assistant_reply($assistant_pdo, $alice_id + 999, $alice_conversation, 'approved-local', 'Read another user conversation', function () {
            throw new LogicException('Unauthorized model call must never execute.');
        });
    } catch (AssistantFailure $error) { $rejected = $error->status === 404; }
    verify($rejected, 'Assistant refuses another account conversation before constructing model context');
    $rejected = false;
    try {
        assistant_reply($assistant_pdo, $alice_id, $alice_conversation, 'approved-local', 'Bad reply', function () {
            return ['content' => '', 'model' => 'approved-local'];
        });
    } catch (AssistantFailure $error) { $rejected = $error->status === 502; }
    verify($rejected && (int) $assistant_pdo->query('SELECT COUNT(*) FROM conversation_messages')->fetchColumn() === 3, 'Invalid model replies are rejected without modifying private history');
    for ($attempt = 0; $attempt < 30; $attempt++) {
        assistant_allowance($assistant_pdo, $alice_id, '198.51.100.2');
    }
    $rejected = false;
    try { assistant_allowance($assistant_pdo, $alice_id, '198.51.100.2'); }
    catch (AssistantFailure $error) { $rejected = $error->status === 429; }
    verify($rejected, 'Generation requests stop at the database-backed 30/account/hour threshold');
    [$status] = request('/api/lessons.php', ['csrf_token' => 'invalid', 'action' => 'save']);
    verify($status === 403, 'Lesson modifications require the current CSRF token');
    [$status, $body] = request('/account.php', ['csrf_token' => $token, 'action' => 'logout']);
    [$status, $body] = request('/account.php', ['csrf_token' => form_token($body), 'action' => 'register', 'username' => 'bob', 'password' => 'Bob-password-1234']);
    $bob_token = form_token($body);
    [$status, $body] = request('/api/lessons.php');
    verify(strpos($body, 'Alice private lesson') === false, 'Second account cannot list another user private lessons');
    [$status] = request('/api/submit.php', ['csrf_token' => $bob_token, 'lesson_id' => $alice_lesson, 'raw_input' => 'HELLO 123', 'duration_ms' => 100]);
    verify($status === 404, 'Second account cannot practice another user private lesson by ID');
    [$status] = request('/api/lessons.php', ['csrf_token' => $bob_token, 'action' => 'publish', 'id' => $alice_lesson, 'is_public' => '1']);
    verify($status === 404, 'Second account cannot publish another user lesson');
    [$status] = request('/api/conversations.php?id=' . $alice_conversation);
    verify($status === 404, 'Second account cannot read another user conversation');
    [$status] = request('/api/conversations.php', ['csrf_token' => $bob_token, 'action' => 'note', 'conversation_id' => $alice_conversation, 'content' => 'forged']);
    verify($status === 404, 'Second account cannot append to another user conversation');
    [$status, $body] = request('/api/lessons.php', [
        'csrf_token' => $bob_token, 'action' => 'save', 'name' => 'Bob private lesson',
        'description' => 'BOB_PRIVATE_MARKER', 'level' => 1, 'expected_sequence' => 'BOB',
    ]);
    verify($status === 200, 'Second account has independent lesson storage');
    [$status, $body] = request('/account.php', ['csrf_token' => $bob_token, 'action' => 'logout']);
    [$status, $body] = request('/account.php', ['csrf_token' => form_token($body), 'action' => 'login', 'username' => 'alice', 'password' => 'Alice-password-123']);
    $token = form_token($body);
    [$status, $body] = request('/api/conversations.php?id=' . $alice_conversation);
    verify($status === 200 && strpos($body, 'ALICE_PRIVATE_NOTE') !== false, 'Personal data persists after logout and login');
    [$status] = request('/api/lessons.php', ['csrf_token' => $token, 'action' => 'publish', 'id' => $alice_lesson, 'is_public' => '1']);
    verify($status === 200, 'Owner explicitly publishes a lesson');
    $alice_curl = $curl;
    $curl = curl_init();
    [$status, $body] = request('/account.php');
    [$status, $body] = request('/account.php', ['csrf_token' => form_token($body), 'action' => 'login', 'username' => 'bob', 'password' => 'Bob-password-1234']);
    $viewer_token = form_token($body);
    [$status, $body] = request('/api/lessons.php');
    verify(strpos($body, 'Alice private lesson') !== false, 'Explicitly published lesson is visible to another registered user');
    [$status] = request('/api/conversations.php?id=' . $alice_conversation);
    verify($status === 404, 'Publishing a lesson never publishes its owner conversations');
    curl_close($curl);
    $curl = $alice_curl;
    [$status, $body] = request('/export.php', ['csrf_token' => $token]);
    verify($status === 200 && substr($body, 0, 2) === 'PK', 'Personal source download is a real ZIP');
    $archive_file = $temporary . '/download.zip';
    file_put_contents($archive_file, $body);
    $archive = new ZipArchive();
    verify($archive->open($archive_file) === true, 'Personal ZIP is readable');
    $profile_json = $archive->getFromName('data/profile.json');
    $published_json = $archive->getFromName('data/published-lessons.json');
    verify(strpos($profile_json, 'ALICE_PRIVATE_NOTE') !== false && strpos($profile_json, 'BOB_PRIVATE_MARKER') === false, 'Download includes only the current account personal data');
    verify(strpos($published_json, 'Alice private lesson') !== false && strpos($published_json, 'ALICE_PRIVATE_NOTE') === false, 'Git-shareable lessons exclude private conversations');
    foreach (['config/database.local.php', 'config/installed.lock', 'config/setup.key', 'config/token', 'config/assistant.local.php',
        'gateway/server.cjs', 'gateway/models.json', 'gateway/providers.env', '.runtime/runtime.json', 'free.chat/.freechat-state.json', 'config/free.chat.zip'] as $forbidden) {
        verify($archive->locateName($forbidden) === false, 'Archive excludes ' . $forbidden);
    }
    verify(strpos($profile_json, 'password_hash') === false && strpos($profile_json, 'Alice-password') === false, 'Profile contains no password hashes or credentials');
    verify(strpos($archive->getFromName('.gitignore'), '/data/*') !== false, 'Private profile is Git-ignored in downloaded source');
    $portable = $temporary . '/portable';
    mkdir($portable);
    verify($archive->extractTo($portable), 'Personal ZIP extracts into a complete runnable tutor directory');
    $archive->close();
    $profile = json_decode($profile_json, true, 32, JSON_THROW_ON_ERROR);
    validate_profile($profile_json);
    [$status, $body] = request('/api/profile.php', ['csrf_token' => $token, 'profile' => '{"format":"typing-personal-profile","version":1}']);
    verify($status === 422, 'Invalid profile is rejected before database writes');
    [$status, $body] = request('/api/profile.php', ['csrf_token' => $token, 'profile' => $profile_json]);
    verify($status === 200, 'Personal profile restore maps lessons, chats and progress into the current account');
    [$status] = request('/api/profile.php', ['csrf_token' => $token, 'profile' => $profile_json]);
    verify($status === 422, 'Repeated profile restore is rejected instead of duplicating data');
    $before_failure = (int) $admin->query("SELECT COUNT(*) FROM `$database`.lessons")->fetchColumn();
    $broken_profile = $profile;
    $broken_profile['conversations'][0]['title'] = 'Trigger a rollback';
    $admin->exec("CREATE TRIGGER `$database`.fail_import BEFORE INSERT ON `$database`.conversations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Simulated import failure'");
    [$status] = request('/api/profile.php', ['csrf_token' => $token, 'profile' => json_encode($broken_profile)]);
    verify($status === 500 && (int) $admin->query("SELECT COUNT(*) FROM `$database`.lessons")->fetchColumn() === $before_failure, 'Import database failure rolls back lessons and conversations together');
    $admin->exec("DROP TRIGGER `$database`.fail_import");
    [$status, $body] = request('/api/lessons.php');
    $restored = array_filter(json_decode($body, true), function ($row) use ($alice_lesson) {
        return $row['name'] === 'Alice private lesson' && (int) $row['id'] !== (int) $alice_lesson;
    });
    verify(count($restored) === 1 && (int) array_values($restored)[0]['is_public'] === 0, 'Restored lessons are private even if originally published');
    [$status] = request('/api/lessons.php', ['csrf_token' => $token, 'action' => 'publish', 'id' => $alice_lesson, 'is_public' => '0']);
    verify($status === 200, 'Owner can unpublish the original lesson');
    [$status, $body] = request('/installer.php', ['action' => 'install', 'csrf_token' => $token]);
    verify(strpos($body, 'Setup complete') !== false, 'Completed installer stays locked');
    [$status, $body] = request('/installer.php', ['action' => 'upgrade', 'csrf_token' => $token]);
    verify($status === 200 && strpos($body, 'Database upgrade completed') !== false, 'Existing installation migration is repeatable through the protected installer');
    [$status] = request('/config/database.local.php');
    verify(in_array($status, [403, 404], true), 'Saved database credentials cannot be served');
    [$status] = request('/data/profile.json');
    verify(in_array($status, [403, 404], true), 'Personal profile JSON is not publicly served');
    [$status, $body] = request('/assets/js/translate.js');
    verify($status === 200 && strpos($body, 'googleTranslateElementInit') !== false, 'Local JS assets are served by the public router');
    [$status] = request('/assets/%2e%2e/config/database.local.php');
    verify(in_array($status, [403, 404], true), 'Asset route rejects traversal outside assets');
    [$status] = request('/?lang[]=ar');
    verify($status === 400, 'Malformed language input returns an explicit error');
    $admin->exec("UPDATE `$database`.auth_limits SET attempts = 20");
    [$status, $body] = request('/account.php', [
        'csrf_token' => $token, 'action' => 'login', 'username' => 'alice', 'password' => 'Incorrect-password-123',
    ]);
    verify(strpos($body, 'Too many account attempts') !== false, 'Account attempt limit is enforced from persistent database state');

    // Switch the isolated server to the extracted download and run its real first-use flow.
    proc_terminate($server);
    proc_close($server);
    $server = null;
    $base = 'http://' . $address;
    $command = [PHP_BINARY, '-c', php_ini_loaded_file(), '-d', 'extension=zip',
        '-S', $address, '-t', $portable . '/public', $portable . '/public/router.php'];
    $server = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $portable);
    if (!is_resource($server)) { throw new RuntimeException('Cannot launch extracted tutor.'); }
    fclose($pipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        usleep(100000);
        try {
            [$status] = request('/health.php');
            if ($status === 200) { $ready = true; break; }
        } catch (RuntimeException $error) {
            if (!proc_get_status($server)['running']) { throw $error; }
        }
    }
    verify($ready, 'Extracted download starts without the original installation credentials');
    [$status, $body] = request('/installer.php');
    $portable_token = form_token($body);
    verify(strpos($body, 'Step 1 of 3') !== false, 'Downloaded copy requires its own database setup');
    request('/installer.php', ['csrf_token' => $portable_token, 'action' => 'checks']);
    $portable_database = $database . '_copy';
    [$status, $body] = request('/installer.php', [
        'csrf_token' => $portable_token, 'action' => 'database', 'host' => $host, 'port' => $port,
        'name' => $portable_database, 'user' => $user, 'password' => $password,
    ]);
    verify(strpos($body, 'Step 3 of 3') !== false, 'Downloaded copy accepts new local database settings');
    [$status, $body] = request('/installer.php', ['csrf_token' => $portable_token, 'action' => 'install']);
    verify(strpos($body, 'Setup complete') !== false, 'Downloaded source installs successfully with its published lesson seed');
    [$status, $body] = request('/account.php');
    [$status, $body] = request('/account.php', [
        'csrf_token' => form_token($body), 'action' => 'register', 'username' => 'portable_owner', 'password' => 'New-local-password-123',
    ]);
    $portable_token = form_token($body);
    verify(strpos($body, 'import-bundled') !== false, 'New account is offered local private-profile restore');
    [$status, $body] = request('/api/profile.php', ['csrf_token' => $portable_token, 'bundled' => '1']);
    verify($status === 200, 'Downloaded profile is restored into the new local account without old credentials');
    [$status, $body] = request('/api/conversations.php');
    verify($status === 200 && strpos($body, 'Alice design') !== false, 'Personal conversation titles survive download and local restore');
    $restored_conversation = json_decode($body, true)[0]['id'];
    [$status, $body] = request('/api/conversations.php?id=' . $restored_conversation);
    verify(strpos($body, 'ALICE_PRIVATE_NOTE') !== false, 'Private conversation messages survive full source-download roundtrip');
    verify((int) $admin->query("SELECT attempts FROM `$portable_database`.user_progress LIMIT 1")->fetchColumn() === 1, 'Personal practice progress survives the source-download roundtrip');
    [$status, $body] = request('/api/assistant.php');
    verify($status === 503 && json_decode($body, true)['models'] === [], 'Downloaded copy has no active hosted model access');
    [$status, $body] = request('/api/lessons.php');
    $personal = array_filter(json_decode($body, true), function ($row) { return $row['name'] === 'Alice private lesson' && $row['owner_id'] !== null; });
    verify(count($personal) === 1 && (int) array_values($personal)[0]['is_public'] === 0, 'Restored personal lesson belongs to the new local owner and remains private');
    [$status] = request('/data/profile.json');
    verify($status === 404, 'Actual bundled private profile is outside the downloaded app web root');
    $pause = filter_var(getenv('TEST_BROWSER_SECONDS'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 300]]);
    if ($pause) {
        echo "BROWSER_TEST_URL=$base\n";
        flush();
        sleep($pause);
    }
    $success = true;
} finally {
    curl_close($curl);
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $admin->exec("DROP DATABASE IF EXISTS `$database`");
    if (isset($portable_database)) {
        $admin->exec("DROP DATABASE IF EXISTS `$portable_database`");
    }
    if (!$success && isset($log) && is_file($log)) {
        fwrite(STDERR, file_get_contents($log));
    }
    if (is_dir($temporary)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($temporary);
    }
}
echo "All isolated MySQL/browser-flow integration checks passed.\n";
