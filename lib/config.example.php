<?php
if (!defined('BEAT')) { http_response_code(404); exit; }
// Copy to config.php and fill in your database credentials.
return [
    'db_dsn'  => 'pgsql:host=127.0.0.1;port=5432;dbname=beat',
    'db_user' => 'beat_user',
    'db_pass' => 'change-me',
    'session_days' => 60,
];
