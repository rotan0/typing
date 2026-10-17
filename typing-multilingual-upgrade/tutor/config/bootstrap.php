<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lang.php';
require_installation();
start_app_session();
$lang_code = selected_language();
$strings = load_lang($lang_code);
