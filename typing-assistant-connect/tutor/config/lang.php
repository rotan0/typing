<?php
function h($value) {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function language_catalog($code) {
    static $catalogs = [];
    if (!preg_match('/^[a-z]{2}$/D', $code)) {
        throw new InvalidArgumentException('Invalid language code.');
    }
    if (!isset($catalogs[$code])) {
        $path = __DIR__ . '/../lang/' . $code . '.json';
        if (!is_file($path)) {
            throw new RuntimeException('Language catalog not found: ' . $code);
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read language catalog: ' . $code);
        }
        $catalog = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (($catalog['code'] ?? null) !== $code || !is_string($catalog['name'] ?? null) ||
            !is_array($catalog['strings'] ?? null)) {
            throw new RuntimeException('Invalid language catalog: ' . $code);
        }
        foreach ($catalog['strings'] as $value) {
            if (!is_string($value)) {
                throw new RuntimeException('Invalid translation in catalog: ' . $code);
            }
        }
        $catalogs[$code] = $catalog;
    }
    return $catalogs[$code];
}

function available_languages() {
    static $languages = null;
    if ($languages === null) {
        $languages = [];
        foreach (glob(__DIR__ . '/../lang/*.json') as $file) {
            $code = basename($file, '.json');
            $languages[$code] = language_catalog($code)['name'];
        }
    }
    return $languages;
}

function selected_language() {
    $languages = available_languages();
    if (isset($_GET['lang'])) {
        if (!is_string($_GET['lang']) || !isset($languages[$_GET['lang']])) {
            http_response_code(400);
            exit('Unsupported language.');
        }
        $_SESSION['lang'] = $_GET['lang'];
    }
    $code = $_SESSION['lang'] ?? 'en';
    return is_string($code) && isset($languages[$code]) ? $code : 'en';
}

function language_direction($code) {
    return $code === 'ar' ? 'rtl' : 'ltr';
}

function load_lang($code = 'en') {
    $english = language_catalog('en')['strings'];
    if ($code === 'en') {
        return $english;
    }
    if (!is_string($code) || !isset(available_languages()[$code])) {
        error_log('Unsupported language requested; using English.');
        return $english;
    }
    $translated = language_catalog($code)['strings'];
    if (array_diff_key($english, $translated)) {
        error_log('Missing translations in ' . $code . '; using English for missing keys.');
    }
    return array_replace($english, $translated);
}

function t($strings, $key) {
    return $strings[$key] ?? $key;
}
