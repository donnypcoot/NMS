<?php
/**
 * Transdata NMS - Production Configuration Template
 * Salin file ini ke config.php dan sesuaikan nilai dengan server Anda.
 * JANGAN PERNAH commit kredensial asli ke repository publik.
 */

return [
    'db' => [
        'host'     => getenv('DB_HOST') ?: '127.0.0.1',
        'port'     => getenv('DB_PORT') ?: 3306,
        'database' => getenv('DB_NAME') ?: 'GANTI_NAMA_DATABASE',
        'username' => getenv('DB_USER') ?: 'GANTI_USER_DATABASE',
        'password' => getenv('DB_PASS') ?: 'GANTI_PASSWORD_DATABASE',
        'charset'  => 'utf8mb4'
    ],
    'app' => [
        'name'            => 'Transdata NMS',
        'env'             => getenv('APP_ENV') ?: 'production',
        'url'             => getenv('APP_URL') ?: 'https://nms.transdata.net.id',
        'jwt_secret'      => getenv('JWT_SECRET') ?: '',
        'session_lifetime'=> 86400, // 24 jam dalam detik
        'encryption_key'  => getenv('APP_ENC_KEY') ?: ''
    ],
    'snmp' => [
        'binary_path'    => '/usr/bin/snmpget',
        'walk_binary'    => '/usr/bin/snmpwalk',
        'default_community' => 'public',
        'default_version'   => 'v2c',
        'timeout_sec'       => 3,
        'retries'           => 2
    ],
    'ping' => [
        'binary_path' => '/bin/ping',
        'default_count' => 4,
        'timeout_sec'   => 5
    ],
    'genieacs' => [
        'nbi_url'     => getenv('GENIEACS_NBI_URL') ?: 'http://127.0.0.1:7557',
        'timeout_sec' => 5
    ]
];
