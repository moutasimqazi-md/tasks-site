<?php
// Copy to /home/<cpaneluser>/timeline_config.php (OUTSIDE public_html) and fill in the values.
// The real timeline_config.php is git-ignored: never commit credentials.
return [
    'db_host'  => 'localhost',
    'db_name'  => 'YOUR_DB_NAME',
    'db_user'  => 'YOUR_DB_USER',
    'db_pass'  => 'YOUR_DB_PASSWORD',

    'user_email' => 'yusuf.shaikh@moveneticsdigital.com',
    'user_name'  => 'Yusuf Shaikh',
    // Generate with make_hash.php (bcrypt), then paste here.
    'password_hash' => 'PASTE_HASH_FROM_make_hash.php',

    'session_secret' => 'RANDOM_64_HEX_CHARS',   // php -r 'echo bin2hex(random_bytes(32));'
    'log_file' => '/home/CPANELUSER/timeline_errors.log',
];
