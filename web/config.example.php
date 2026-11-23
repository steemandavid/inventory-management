<?php
/*
 * Copy to config.php ON THE SERVER (and locally for development). config.php is in
 * .gitignore and blocked by .htaccess. It only returns an array and prints nothing.
 */

// On LWS the database is shared with verba (one schema, §4.2): reuse its credentials so
// there is only one copy of the database password.
$verba = require __DIR__ . '/../verba/api/v1/config.php';

return [
    'db_host'     => $verba['db_host'],
    'db_name'     => $verba['db_naam'],
    'db_user'     => $verba['db_gebruiker'],
    'db_password' => $verba['db_wachtwoord'],

    // Random secret for hashing IP addresses (openssl rand -hex 32). Never change it.
    'pepper'      => '',

    // One-time token for setup.php (openssl rand -hex 24). Remove after setup.
    'setup_token' => '',

    // Header holding the real visitor IP if a proxy sits in front ('' = REMOTE_ADDR).
    'ip_header'   => '',

    'base_path'   => '/inventory/',
    'dev'         => false,
];
