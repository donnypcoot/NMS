<?php
/**
 * Transdata NMS - Production REST API Front Controller & Router
 * PHP 8.2+ / 8.3
 */

declare(strict_types=1);

namespace Transdata\Nms;

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Auth-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/PingService.php';
require_once __DIR__ . '/../src/SnmpService.php';
require_once __DIR__ . '/../src/CryptoService.php';
require_once __DIR__ . '/../src/MikrotikService.php';
require_once __DIR__ . '/../src/GenieAcsService.php';
require_once __DIR__ . '/../src/GisService.php';
require_once __DIR__ . '/../src/AuditLogger.php';

$configFile = __DIR__ . '/../config/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Konfigurasi belum dibuat. Salin config.example.php menjadi config.php.'
    ]);
    exit;
}
$config = require $configFile;

$jwtSecret = $config['app']['jwt_secret'] ?? '';
$encryptionKey = $config['app']['encryption_key'] ?? '';
if (!is_string($jwtSecret) || strlen($jwtSecret) < 32 ||
    !is_string($encryptionKey) || strlen($encryptionKey) < 32) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'JWT secret atau encryption key belum dikonfigurasi dengan aman.'
    ]);
    exit;
}
Database::setConfig($config);

// Helper Response
function jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}


/**
 * Membaca community SNMP lama (plaintext) maupun format terenkripsi.
 */
function resolveSnmpCommunity(?string $stored, CryptoService $crypto): string {
    $stored = (string)($stored ?? '');
    if ($stored === '') {
        return 'public';
    }

    try {
        $plain = $crypto->decrypt($stored);
        if ($plain !== '') {
            return $plain;
        }
    } catch (\Throwable $e) {
        // Nilai lama mungkin masih plaintext.
    }

    return $stored;
}

function errorResponse(string $message, int $status = 400, ?array $details = null): void {
    http_response_code($status);
    $payload = ['success' => false, 'error' => $message];
    if ($details !== null) $payload['details'] = $details;
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function requirePermission(
    Auth $auth,
    array $currentUser,
    string $permission
): void {
    if (
        empty($currentUser['role_id']) ||
        !$auth->hasPermission(
            (int)$currentUser['role_id'],
            $permission
        )
    ) {
        errorResponse(
            'Anda tidak memiliki izin untuk melakukan tindakan ini.',
            403
        );
    }
}

function getAuthorizationHeader(): ?string {
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return $_SERVER['HTTP_AUTHORIZATION'];
    }
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if (!empty($_SERVER['HTTP_X_AUTH_TOKEN'])) {
        return 'Bearer ' . $_SERVER['HTTP_X_AUTH_TOKEN'];
    }
    if (!empty($_GET['token'])) {
        return 'Bearer ' . $_GET['token'];
    }
    if (!empty($_COOKIE['transdata_token'])) {
        return 'Bearer ' . $_COOKIE['transdata_token'];
    }
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $key => $val) {
            if (strcasecmp($key, 'Authorization') === 0) {
                return $val;
            }
            if (strcasecmp($key, 'X-Auth-Token') === 0) {
                return 'Bearer ' . $val;
            }
        }
    }
    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        foreach ($headers as $key => $val) {
            if (strcasecmp($key, 'Authorization') === 0) {
                return $val;
            }
            if (strcasecmp($key, 'X-Auth-Token') === 0) {
                return 'Bearer ' . $val;
            }
        }
    }
    return null;
}

// Router parsing
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$method = strtoupper($_SERVER['REQUEST_METHOD']);
$path = preg_replace('#^/api#', '', $requestUri);
$path = rtrim($path, '/') ?: '/';
$input = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    $db = Database::getConnection();
    $auth = new Auth($db, $config);
    $audit = new AuditLogger($db);
    $pingSvc = new PingService($config['ping']['binary_path'] ?? '/bin/ping');
    $snmpSvc = new SnmpService($config['snmp']['binary_path'] ?? '/usr/bin/snmpget', $config['snmp']['walk_binary'] ?? '/usr/bin/snmpwalk');
    $gisSvc = new GisService($db);
    $genieAcsSvc = new GenieAcsService($config['genieacs']['nbi_url'] ?? 'http://127.0.0.1:7557');

    // Public Route: System Status & Diagnostic
    if ($path === '/system/status' && $method === 'GET') {
        $hasAdmin = $auth->hasAdminUser();
        $isSnmp = $snmpSvc->isNetSnmpInstalled();
        jsonResponse([
            'success'            => true,
            'system_name'        => 'Transdata NMS',
            'version'            => '1.0.0-PROD',
            'php_version'        => PHP_VERSION,
            'has_admin_user'     => $hasAdmin,
            'net_snmp_installed' => $isSnmp,
            'snmp_notice'        => $isSnmp ? 'Net-SNMP CLI aktif' : 'Net-SNMP belum terinstal di host. Jalankan: sudo apt-get install -y snmp',
            'server_time'        => date('Y-m-d H:i:s')
        ]);
    }

    // Public Route: Setup First Administrator
    if ($path === '/auth/setup-admin' && $method === 'POST') {
        if ($auth->hasAdminUser()) {
            errorResponse('Setup superadmin telah ditutup. Akun admin sudah tersedia.', 403);
        }
        $username = trim($input['username'] ?? '');
        $email = trim($input['email'] ?? '');
        $password = (string)($input['password'] ?? '');
        $fullName = trim($input['full_name'] ?? '');

        if (!$username || !$email || !$password || !$fullName) {
            errorResponse('Semua bidang (username, email, password, full_name) wajib diisi.', 400);
        }

        $user = $auth->createInitialAdmin($username, $email, $password, $fullName);
        $audit->log($user['id'], 'SETUP_FIRST_ADMIN', 'USER', (string)$user['id'], 'Superadmin pertama dibuat melalui setup wizard.');
        jsonResponse([
            'success' => true,
            'message' => 'Akun Superadmin Transdata NMS berhasil dibuat. Silakan login.',
            'user'    => $user
        ], 201);
    }

    // Public Route: Login
    if ($path === '/auth/login' && $method === 'POST') {
        $username = trim($input['username'] ?? '');
        $password = (string)($input['password'] ?? '');
        if (!$username || !$password) {
            errorResponse('Username dan password wajib diisi.', 400);
        }
        $authData = $auth->login($username, $password, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $audit->log($authData['user']['id'], 'USER_LOGIN', 'AUTH', (string)$authData['user']['id'], 'Login berhasil.');
        jsonResponse([
            'success' => true,
            'token'   => $authData['token'],
            'user'    => $authData['user']
        ]);
    }

    // JWT Verification for protected endpoints
    $authHeader = getAuthorizationHeader();
    if (!$authHeader) {
        errorResponse('Token otentikasi tidak ditemukan. Header Authorization, parameter ?token=, atau cookie sesi tidak terdeteksi.', 401);
    }
    $currentUser = $auth->validateToken($authHeader);
    if (!$currentUser) {
        errorResponse('Sesi tidak valid atau telah kedaluwarsa. Silakan login kembali.', 401);
    }

    // GET /auth/me
    if ($path === '/auth/me' && $method === 'GET') {
        $profile = $auth->getUserById((int)$currentUser['id']);
        if (!$profile) {
            errorResponse('Profil pengguna tidak ditemukan.', 404);
        }

        $permissionStmt = $db->prepare("
            SELECT p.code
            FROM role_permissions rp
            JOIN permissions p ON p.id = rp.permission_id
            WHERE rp.role_id = :role_id
            ORDER BY p.code
        ");
        $permissionStmt->execute([':role_id' => (int)$profile['role_id']]);
        $profile['permissions'] = array_column($permissionStmt->fetchAll(), 'code');

        jsonResponse(['success' => true, 'user' => $profile]);
    }

    // GET /dashboard/summary
    if ($path === '/dashboard/summary' && $method === 'GET') {
        $dTotal = (int)$db->query("SELECT COUNT(*) FROM devices")->fetchColumn();
        $dOnline = (int)$db->query("SELECT COUNT(*) FROM devices WHERE ping_status = 'ONLINE'")->fetchColumn();
        $dOffline = (int)$db->query("SELECT COUNT(*) FROM devices WHERE ping_status = 'OFFLINE'")->fetchColumn();
        $dUnknown = (int)$db->query("SELECT COUNT(*) FROM devices WHERE ping_status IN ('UNKNOWN', 'ERROR')")->fetchColumn();
        $alarmCnt = (int)$db->query("SELECT COUNT(*) FROM alarms WHERE status = 'ACTIVE'")->fetchColumn();

        $offlineDevices = $db->query("SELECT id, name, ip_address, device_type, ping_status, last_ping_time FROM devices WHERE ping_status = 'OFFLINE' ORDER BY last_ping_time DESC LIMIT 10")->fetchAll();
        $recentAlarms = $db->query("SELECT a.*, d.name as device_name FROM alarms a LEFT JOIN devices d ON a.device_id = d.id WHERE a.status = 'ACTIVE' ORDER BY a.created_at DESC LIMIT 5")->fetchAll();

        jsonResponse([
            'success' => true,
            'total_devices'   => $dTotal,
            'online_devices'  => $dOnline,
            'offline_devices' => $dOffline,
            'unknown_devices' => $dUnknown,
            'active_alarms'   => $alarmCnt,
            'offline_list'    => $offlineDevices,
            'recent_alarms'   => $recentAlarms,
            'timestamp'       => date('Y-m-d H:i:s')
        ]);
    }

    // GET /devices
    if ($path === '/devices' && $method === 'GET') {
        $sql = "
    SELECT
        id,
        name,
        ip_address,
        device_type,
        vendor,
        model,
        serial_number,
        location,
        snmp_version,
        snmp_port,
        monitoring_enabled,
        ping_status,
        ping_latency_ms,
        ping_packet_loss_pct,
        last_ping_time,
        snmp_status,
        snmp_uptime,
        last_snmp_time,
        mikrotik_api_port,
        mikrotik_username,
        mikrotik_use_ssl,
        CASE
            WHEN mikrotik_username IS NOT NULL
             AND mikrotik_username != ''
             AND mikrotik_password_encrypted IS NOT NULL
             AND mikrotik_password_encrypted != ''
            THEN 1
            ELSE 0
	END AS mikrotik_configured
    FROM devices
    ORDER BY name ASC
";
        $devices = $db->query($sql)->fetchAll();
        jsonResponse(['success' => true, 'count' => count($devices), 'devices' => $devices]);
    }

    // POST /devices
    if ($path === '/devices' && $method === 'POST') {
        $name = trim($input['name'] ?? '');
        $ip = trim($input['ip_address'] ?? '');
        if (!$name || !filter_var($ip, FILTER_VALIDATE_IP)) {
            errorResponse('Nama perangkat dan alamat IP valid wajib diisi.', 400);
        }

        $stmt = $db->prepare("
            INSERT INTO devices (name, ip_address, device_type, vendor, model, location, snmp_version, snmp_port, snmp_community_encrypted, monitoring_enabled)
            VALUES (:name, :ip, :type, :vendor, :model, :location, :snmp_ver, :snmp_port, :community, :mon)
        ");
        $stmt->execute([
            ':name'      => $name,
            ':ip'        => $ip,
            ':type'      => $input['device_type'] ?? 'ROUTER',
            ':vendor'    => $input['vendor'] ?? 'Tidak tersedia',
            ':model'     => $input['model'] ?? 'Tidak tersedia',
            ':location'  => $input['location'] ?? 'Tidak ditentukan',
            ':snmp_ver'  => $input['snmp_version'] ?? 'v2c',
            ':snmp_port' => (int)($input['snmp_port'] ?? 161),
            ':community' => (new CryptoService($config['app']['encryption_key']))->encrypt((string)($input['snmp_community'] ?? 'public')),
            ':mon'       => isset($input['monitoring_enabled']) ? ($input['monitoring_enabled'] ? 1 : 0) : 1
        ]);
        $devId = (int)$db->lastInsertId();
        $audit->log((int)$currentUser['id'], 'CREATE_DEVICE', 'DEVICE', (string)$devId, "Menambahkan perangkat $name ($ip)");

        jsonResponse(['success' => true, 'id' => $devId, 'message' => 'Perangkat berhasil ditambahkan.'], 201);
    }

    // POST /devices/{id}/ping
    if (preg_match('#^/devices/(\d+)/ping$#', $path, $m) && $method === 'POST') {
        $devId = (int)$m[1];
        $stmt = $db->prepare("SELECT * FROM devices WHERE id = :id");
        $stmt->execute([':id' => $devId]);
        $dev = $stmt->fetch();
        if (!$dev) errorResponse('Perangkat tidak ditemukan.', 404);

        $pingRes = $pingSvc->executePing($dev['ip_address'], (int)($input['count'] ?? 4));

        // Update database dengan hasil pemeriksaan nyata
        $up = $db->prepare("
            UPDATE devices SET
                ping_status = :status,
                ping_latency_ms = :latency,
                ping_packet_loss_pct = :loss,
                last_ping_time = NOW(),
                last_ping_output = :output
            WHERE id = :id
        ");
        $up->execute([
            ':status'  => $pingRes['status'],
            ':latency' => $pingRes['latency_ms'],
            ':loss'    => $pingRes['packet_loss'],
            ':output'  => $pingRes['output'],
            ':id'      => $devId
        ]);

        jsonResponse(['success' => true, 'ping' => $pingRes]);
    }

    // ==========================================
    // PAKET LAYANAN INTERNET (PACKAGES)
    // ==========================================

    // GET /packages
    if ($path === '/packages' && $method === 'GET') {
        $pkgs = $db->query("SELECT
    p.*,
    d.name AS mikrotik_device_name
FROM packages p
LEFT JOIN devices d
    ON d.id = p.mikrotik_device_id
ORDER BY p.price ASC")->fetchAll();
        jsonResponse(['success' => true, 'packages' => $pkgs]);
    }

    // POST /packages
    if ($path === '/packages' && $method === 'POST') {
       $name = trim($input['name'] ?? '');
$price = (float)($input['price'] ?? 0);
$bandwidth = trim($input['bandwidth'] ?? '');
$desc = trim($input['description'] ?? '');
$profile = trim($input['mikrotik_profile'] ?? '') ?: $name;

$mikrotikDeviceId = isset($input['mikrotik_device_id'])
    ? (int)$input['mikrotik_device_id']
    : 0;

if (!$name || $price <= 0 || !$bandwidth) {
    errorResponse(
        'Nama paket, tagihan (Rp > 0), dan kapasitas bandwidth wajib diisi.',
        400
    );
}

if (!$mikrotikDeviceId) {
    errorResponse(
        'Router MikroTik wajib dipilih.',
        400
    );
}
        $stmt = $db->prepare("
    INSERT INTO packages
    (
        name,
        price,
        bandwidth,
        description,
        mikrotik_profile,
        mikrotik_device_id
    )
    VALUES
    (
        :n,
        :p,
        :b,
        :d,
        :mp,
        :md
    )
");

$stmt->execute([
    ':n'  => $name,
    ':p'  => $price,
    ':b'  => $bandwidth,
    ':d'  => $desc,
    ':mp' => $profile,
    ':md' => $mikrotikDeviceId
]);
        $pkgId = (int)$db->lastInsertId();
        $audit->log((int)$currentUser['id'], 'CREATE_PACKAGE', 'PACKAGE', (string)$pkgId, "Membuat paket $name Rp $price ($bandwidth)");

        jsonResponse(['success' => true, 'id' => $pkgId, 'message' => 'Paket layanan berhasil ditambahkan.'], 201);
    }

    // PUT /devices/{id}
if (preg_match('#^/devices/(\d+)$#', $path, $m) && $method === 'PUT') {
    $deviceId = (int)$m[1];
    $mikrotikDeviceId = isset($input['mikrotik_device_id'])
    ? (int)$input['mikrotik_device_id']
    : null;
    $stmt = $db->prepare("SELECT * FROM devices WHERE id = :id");
    $stmt->execute([':id' => $deviceId]);
    $device = $stmt->fetch();

    if (!$device) {
        errorResponse('Perangkat tidak ditemukan.', 404);
    }

    $allowed = [
        'name',
        'device_type',
        'vendor',
        'model',
        'serial_number',
        'location',
        'snmp_version',
        'snmp_port',
        'snmp_community',
        'monitoring_enabled',
        'mikrotik_api_port',
        'mikrotik_username',
        'mikrotik_use_ssl'
    ];

    $updates = [];
    $params = [':id' => $deviceId];

    foreach ($allowed as $field) {
        if (!array_key_exists($field, $input)) {
            continue;
        }

        $column = $field === 'snmp_community'
            ? 'snmp_community_encrypted'
            : $field;

        /*
         * Password MikroTik diproses khusus di bawah,
         * sehingga tidak dimasukkan ke $allowed.
         */
        $updates[] = "$column = :$field";

        if ($field === 'monitoring_enabled' || $field === 'mikrotik_use_ssl') {
            $params[":$field"] = $input[$field] ? 1 : 0;
        } elseif ($field === 'snmp_port' || $field === 'mikrotik_api_port') {
            $params[":$field"] = (int)$input[$field];
        } elseif ($field === 'snmp_community') {
            $crypto = new CryptoService($config['app']['encryption_key']);
            $params[":$field"] = $crypto->encrypt((string)$input[$field]);
        } else {
            $params[":$field"] = $input[$field];
        }
    }

    /*
     * Password MikroTik.
     *
     * Jika password kosong, password lama dipertahankan.
     * Jika password diisi, password baru dienkripsi.
     */
    if (array_key_exists('mikrotik_password', $input)) {
        $password = (string)$input['mikrotik_password'];

        if ($password !== '') {
            $crypto = new CryptoService($config['app']['encryption_key']);

            $updates[] = 'mikrotik_password_encrypted = :mikrotik_password_encrypted';
            $params[':mikrotik_password_encrypted'] = $crypto->encrypt($password);
        }
    }

    if (empty($updates)) {
        errorResponse('Tidak ada data yang diperbarui.', 400);
    }

    $sql = "UPDATE devices SET " . implode(', ', $updates) . " WHERE id = :id";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $audit->log(
        (int)$currentUser['id'],
        'UPDATE_DEVICE',
        'DEVICE',
        (string)$deviceId,
        "Mengubah konfigurasi perangkat."
    );

    /*
     * Jangan pernah mengembalikan password MikroTik.
     */
    $stmt = $db->prepare("
        SELECT
            id,
            name,
            ip_address,
            device_type,
            vendor,
            model,
            serial_number,
            location,
            snmp_version,
            snmp_port,
            monitoring_enabled,
            ping_status,
            ping_latency_ms,
            ping_packet_loss_pct,
            last_ping_time,
            snmp_status,
            snmp_uptime,
            snmp_descr,
            last_snmp_time,
            last_snmp_error,
            mikrotik_api_port,
            mikrotik_username,
            mikrotik_use_ssl,
            CASE
                WHEN mikrotik_username IS NOT NULL
                 AND mikrotik_username != ''
                 AND mikrotik_password_encrypted IS NOT NULL
                 AND mikrotik_password_encrypted != ''
                THEN 1
                ELSE 0
            END AS mikrotik_configured,
            created_at
        FROM devices
        WHERE id = :id
    ");

    $stmt->execute([':id' => $deviceId]);
    $updatedDevice = $stmt->fetch();

    jsonResponse([
        'success' => true,
        'device' => $updatedDevice
    ]);
}

// PUT /packages/{id}
if (preg_match('#^/packages/(\d+)$#', $path, $m) && $method === 'PUT') {
    $pkgId = (int)$m[1];

    $name = trim($input['name'] ?? '');
    $price = (float)($input['price'] ?? 0);
    $bandwidth = trim($input['bandwidth'] ?? '');
    $desc = trim($input['description'] ?? '');
    $profile = trim($input['mikrotik_profile'] ?? '') ?: $name;
    $mikrotikDeviceId = isset($input['mikrotik_device_id'])
    ? (int)$input['mikrotik_device_id']
    : 0;

    if (!$name || $price < 0 || !$bandwidth) {
        errorResponse(
            'Nama paket, tagihan (Rp), dan kapasitas bandwidth wajib diisi.',
            400
        );
    }

    // Pastikan paket tersedia
    $check = $db->prepare("SELECT id FROM packages WHERE id = :id");
    $check->execute([':id' => $pkgId]);

    if (!$check->fetch()) {
        errorResponse('Paket layanan tidak ditemukan.', 404);
    }

    // Cek nama paket agar tidak bentrok dengan paket lain
    $duplicate = $db->prepare("
        SELECT id
        FROM packages
        WHERE name = :name
          AND id != :id
        LIMIT 1
    ");
    $duplicate->execute([
        ':name' => $name,
        ':id'   => $pkgId
    ]);

    if ($duplicate->fetch()) {
        errorResponse("Nama paket '$name' sudah digunakan.", 400);
    }

    $stmt = $db->prepare("
        UPDATE packages
        SET
            name = :name,
            price = :price,
            bandwidth = :bandwidth,
            description = :description,
            mikrotik_profile = :mikrotik_profile
        WHERE id = :id
    ");

    $stmt->execute([
        ':name'             => $name,
        ':price'            => $price,
        ':bandwidth'        => $bandwidth,
        ':description'      => $desc,
        ':mikrotik_profile' => $profile,
        ':id'               => $pkgId
    ]);

$audit->log(
    (int)$currentUser['id'],
    'UPDATE_PACKAGE',
    'PACKAGE',
    (string)$pkgId,
    "Mengubah paket $name Rp $price ($bandwidth), profile MikroTik: $profile"
);

    jsonResponse([
        'success' => true,
        'message' => 'Paket layanan berhasil diperbarui.'
    ]);
}
    // DELETE /packages/{id}
    if (preg_match('#^/packages/(\d+)$#', $path, $m) && $method === 'DELETE') {
        $pkgId = (int)$m[1];
        $cnt = (int)$db->prepare("SELECT COUNT(*) FROM customers WHERE package_id = :id")->execute([':id' => $pkgId]) ? $db->query("SELECT COUNT(*) FROM customers WHERE package_id = $pkgId")->fetchColumn() : 0;
        if ($cnt > 0) {
            errorResponse("Paket tidak dapat dihapus karena masih digunakan oleh $cnt pelanggan.", 400);
        }

        $db->prepare("DELETE FROM packages WHERE id = :id")->execute([':id' => $pkgId]);
        $audit->log((int)$currentUser['id'], 'DELETE_PACKAGE', 'PACKAGE', (string)$pkgId, "Menghapus paket ID $pkgId");
        jsonResponse(['success' => true, 'message' => 'Paket layanan berhasil dihapus.']);
    }

    // ==========================================
    // DATA PELANGGAN (CUSTOMERS)
    // ==========================================

    // GET /customers/payment-summary (ringkasan pelanggan untuk Finance)
    if ($path === '/customers/payment-summary' && $method === 'GET') {
        requirePermission($auth, $currentUser, 'customers.payment_view');

        $sql = "SELECT
                    c.id,
                    c.customer_number,
                    c.name,
                    p.name AS package_name,
                    p.price AS package_price,
                    p.bandwidth,
                    c.status,
                    c.isolir_reason,
                    c.isolir_at
                FROM customers c
                LEFT JOIN packages p ON c.package_id = p.id
                ORDER BY c.name ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute();
        $customers = $stmt->fetchAll();

        jsonResponse([
            'success' => true,
            'count' => count($customers),
            'customers' => $customers
        ]);
    }

    // GET /customers
    if ($path === '/customers' && $method === 'GET') {
        requirePermission($auth, $currentUser, 'customers.view');
        $q = trim($_GET['search'] ?? '');
        $sql = "SELECT c.*, p.name as package_name, p.price as package_price, p.bandwidth, p.mikrotik_profile,
                       o.name as odp_name, o.code as odp_code
                FROM customers c
                LEFT JOIN packages p ON c.package_id = p.id
                LEFT JOIN ftth_objects o ON c.odp_id = o.id
                WHERE 1=1";
        $params = [];
        if ($q !== '') {
            $sql .= " AND (c.name LIKE :q1 OR c.nik LIKE :q2 OR c.phone_number LIKE :q3 OR c.address LIKE :q4 OR c.pppoe_username LIKE :q5 OR c.ont_sn LIKE :q6)";
            $searchVal = "%$q%";
            $params[':q1'] = $searchVal;
            $params[':q2'] = $searchVal;
            $params[':q3'] = $searchVal;
            $params[':q4'] = $searchVal;
            $params[':q5'] = $searchVal;
            $params[':q6'] = $searchVal;
        }
        $sql .= " ORDER BY c.created_at DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $customers = $stmt->fetchAll();

        jsonResponse(['success' => true, 'count' => count($customers), 'customers' => $customers]);
    }

    // POST /customers
    if ($path === '/customers' && $method === 'POST') {
        requirePermission($auth, $currentUser, 'customers.create');
        $name = trim($input['name'] ?? '');
        $nik = preg_replace('/\D/', '', (string)($input['nik'] ?? ''));
        $address = trim($input['address'] ?? '');
        $phone = preg_replace('/[^\d+]/', '', (string)($input['phone_number'] ?? ''));
        $packageId = (int)($input['package_id'] ?? 0);

        if (!$name || !$nik || !$address || !$phone || !$packageId) {
            errorResponse('Nama, Nomor KTP / NIK, Alamat, Nomor WhatsApp, dan Paket Layanan wajib diisi.', 400);
        }

        $email = trim($input['email'] ?? '') ?: null;
        $odpId = !empty($input['odp_id']) ? $input['odp_id'] : null;
        $ontSn = trim($input['ont_sn'] ?? '') ?: null;
        $ontModel = trim($input['ont_model'] ?? '') ?: null;
        $pppoeUser = trim($input['pppoe_username'] ?? '') ?: ('td_' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $name)) . rand(10, 99));
        $pppoePass = trim($input['pppoe_password'] ?? '') ?: ('td@' . rand(1000, 9999));
        $lat = !empty($input['latitude']) ? (float)$input['latitude'] : null;
        $lng = !empty($input['longitude']) ? (float)$input['longitude'] : null;

        // Generate customer number
        $custNum = 'CUST-' . date('ym') . '-' . str_pad((string)rand(100, 9999), 4, '0', STR_PAD_LEFT);

        $stmt = $db->prepare("
            INSERT INTO customers (customer_number, name, nik, address, latitude, longitude, phone_number, email, package_id, odp_id, ont_sn, ont_model, pppoe_username, pppoe_password, status, notes)
            VALUES (:cn, :name, :nik, :addr, :lat, :lng, :phone, :email, :pkg, :odp, :sn, :model, :puser, :ppass, 'ACTIVE', :notes)
        ");
        $stmt->execute([
            ':cn'    => $custNum,
            ':name'  => $name,
            ':nik'   => $nik,
            ':addr'  => $address,
            ':lat'   => $lat,
            ':lng'   => $lng,
            ':phone' => $phone,
            ':email' => $email,
            ':pkg'   => $packageId,
            ':odp'   => $odpId,
            ':sn'    => $ontSn,
            ':model' => $ontModel,
            ':puser' => $pppoeUser,
            ':ppass' => $pppoePass,
            ':notes' => $input['notes'] ?? null
        ]);

        $custId = (int)$db->lastInsertId();

        // 1. Sinkronisasi node ke Map FTTH GIS jika koordinat diisi
        if ($lat && $lng) {
            $ontNodeId = 'ONT-' . $custId;
            $gisSvc->saveObject([
                'id'               => $ontNodeId,
                'object_type'      => 'PELANGGAN',
                'code'             => 'ONT-' . $custNum,
                'name'             => 'Pelanggan: ' . $name,
                'latitude'         => $lat,
                'longitude'        => $lng,
                'status'           => 'ACTIVE',
                'address'          => $address,
                'parent_object_id' => $odpId,
                'notes'            => "Paket: ID $packageId, SN: $ontSn, PPPoE: $pppoeUser"
            ]);

            // Jika ada ODP induk, tarik kabel dropcore otomatis dari ODP ke rumah pelanggan
            if ($odpId) {
                $odp = $gisSvc->getObjectById($odpId);
                if ($odp) {
                    $cableId = 'CBL-DROP-' . $custId;
                    $gisSvc->saveCable([
                        'id'               => $cableId,
                        'cable_code'       => 'DROP-' . $custNum,
                        'cable_name'       => 'Dropcore ke ' . $name,
                        'cable_type'       => 'DROPCORE',
                        'core_count'       => 1,
                        'start_object_id'  => $odpId,
                        'end_object_id'    => $ontNodeId,
                        'color_hex'        => '#f59e0b',
                        'status'           => 'ACTIVE',
                        'technician_notes' => "Tarikan kabel dropcore pelanggan $name dari $odp[name]",
                        'vertices'         => [
                            ['latitude' => (float)$odp['latitude'], 'longitude' => (float)$odp['longitude']],
                            ['latitude' => $lat, 'longitude' => $lng]
                        ]
                    ]);
                }
            }
        }

        $audit->log((int)$currentUser['id'], 'CREATE_CUSTOMER', 'CUSTOMER', (string)$custId, "Registrasi pelanggan baru $name ($custNum)");

        jsonResponse([
            'success'         => true,
            'id'              => $custId,
            'customer_number' => $custNum,
            'message'         => 'Registrasi pelanggan baru berhasil disimpan dan terintegrasi ke sistem.'
        ], 201);
    }

    // PUT /customers/{id}
    if (preg_match('#^/customers/(\d+)$#', $path, $m) && $method === 'PUT') {
        requirePermission($auth, $currentUser, 'customers.edit');
        $custId = (int)$m[1];
        $name = trim($input['name'] ?? '');
        $nik = preg_replace('/\D/', '', (string)($input['nik'] ?? ''));
        $address = trim($input['address'] ?? '');
        $phone = preg_replace('/[^\d+]/', '', (string)($input['phone_number'] ?? ''));
        $packageId = (int)($input['package_id'] ?? 0);

        if (!$name || !$nik || !$address || !$phone || !$packageId) {
            errorResponse('Nama, NIK, Alamat, No WhatsApp, dan Paket wajib diisi.', 400);
        }

        $email = trim($input['email'] ?? '') ?: null;
        $odpId = !empty($input['odp_id']) ? $input['odp_id'] : null;
        $ontSn = trim($input['ont_sn'] ?? '') ?: null;
        $ontModel = trim($input['ont_model'] ?? '') ?: null;
        $pppoeUser = trim($input['pppoe_username'] ?? '') ?: null;
        $pppoePass = trim($input['pppoe_password'] ?? '') ?: null;
        $lat = !empty($input['latitude']) ? (float)$input['latitude'] : null;
        $lng = !empty($input['longitude']) ? (float)$input['longitude'] : null;

        $stmt = $db->prepare("
            UPDATE customers SET
                name = :name, nik = :nik, address = :addr, latitude = :lat, longitude = :lng,
                phone_number = :phone, email = :email, package_id = :pkg, odp_id = :odp,
                ont_sn = :sn, ont_model = :model, pppoe_username = :puser, pppoe_password = :ppass,
                notes = :notes
            WHERE id = :id
        ");
        $stmt->execute([
            ':name'  => $name,
            ':nik'   => $nik,
            ':addr'  => $address,
            ':lat'   => $lat,
            ':lng'   => $lng,
            ':phone' => $phone,
            ':email' => $email,
            ':pkg'   => $packageId,
            ':odp'   => $odpId,
            ':sn'    => $ontSn,
            ':model' => $ontModel,
            ':puser' => $pppoeUser,
            ':ppass' => $pppoePass,
            ':notes' => $input['notes'] ?? null,
            ':id'    => $custId
        ]);

        $audit->log((int)$currentUser['id'], 'UPDATE_CUSTOMER', 'CUSTOMER', (string)$custId, "Memperbarui data pelanggan $name");
        jsonResponse(['success' => true, 'message' => 'Data pelanggan berhasil diperbarui.']);
    }

    // DELETE /customers/{id}
    if (preg_match('#^/customers/(\d+)$#', $path, $m) && $method === 'DELETE') {
        requirePermission($auth, $currentUser, 'customers.delete');
        $custId = (int)$m[1];
        $cust = $db->prepare("SELECT * FROM customers WHERE id = :id");
        $cust->execute([':id' => $custId]);
        $row = $cust->fetch();

        if ($row) {
            $db->prepare("DELETE FROM customers WHERE id = :id")->execute([':id' => $custId]);
            // Hapus node ONT terkait jika ada di Map FTTH
            try {
                $gisSvc->deleteObject('ONT-' . $custId);
            } catch (\Throwable $t) {}

            $audit->log((int)$currentUser['id'], 'DELETE_CUSTOMER', 'CUSTOMER', (string)$custId, "Menghapus pelanggan {$row['name']}");
        }

        jsonResponse(['success' => true, 'message' => 'Pelanggan berhasil dihapus.']);
    }
// POST /customers/{id}/isolir (MikroTik API Isolir)
if (preg_match('#^/customers/(\d+)/isolir$#', $path, $m) && $method === 'POST') {
    requirePermission($auth, $currentUser, 'customers.isolate');
    $custId = (int)$m[1];

    $custStmt = $db->prepare("
        SELECT c.*, p.name as package_name, p.mikrotik_profile
        FROM customers c
        JOIN packages p ON c.package_id = p.id
        WHERE c.id = :id
    ");
    $custStmt->execute([':id' => $custId]);
    $cust = $custStmt->fetch();

    if (!$cust) {
        errorResponse('Pelanggan tidak ditemukan.', 404);
    }

    if (empty($cust['pppoe_username'])) {
        errorResponse('Username PPPoE pelanggan belum tersedia.', 400);
    }

    // Cari router MikroTik yang sudah dikonfigurasi
    $rDev = $db->query("
        SELECT *
        FROM devices
        WHERE device_type = 'ROUTER'
          AND mikrotik_username IS NOT NULL
          AND mikrotik_username != ''
          AND mikrotik_password_encrypted IS NOT NULL
          AND mikrotik_password_encrypted != ''
        ORDER BY id ASC
        LIMIT 1
    ")->fetch();

    if (!$rDev) {
        errorResponse(
            'Belum ada router MikroTik yang dikonfigurasi. Silakan isi username dan password MikroTik terlebih dahulu.',
            400
        );
    }

    try {
        $crypto = new CryptoService($config['app']['encryption_key']);
$mikrotikPassword = $crypto->decrypt($rDev['mikrotik_password_encrypted']);

$mSvc = new MikrotikService(
    $rDev['ip_address'],
    $rDev['mikrotik_username'],
    $mikrotikPassword,
    (int)($rDev['mikrotik_api_port'] ?? 8728)
);

        $isoRes = $mSvc->isolirCustomer($cust['pppoe_username']);

        if (empty($isoRes['success'])) {
            errorResponse(
                $isoRes['error'] ?? 'Gagal melakukan isolir pada MikroTik.',
                502
            );
        }

        // Hanya update database jika MikroTik benar-benar berhasil
        $db->prepare("
            UPDATE customers
            SET status = 'ISOLIR',
                isolir_at = NOW(),
                isolir_reason = 'Tagihan belum dibayar'
            WHERE id = :id
        ")->execute([':id' => $custId]);

        $audit->log(
            (int)$currentUser['id'],
            'ISOLIR_CUSTOMER',
            'CUSTOMER',
            (string)$custId,
            "Mengisolir pelanggan {$cust['name']} ({$cust['pppoe_username']}) melalui MikroTik {$rDev['name']}"
        );

        jsonResponse([
            'success' => true,
            'message' => "Pelanggan '{$cust['name']}' berhasil diisolir. " .
                         ($isoRes['message'] ?? ''),
            'status' => 'ISOLIR'
        ]);

    } catch (\Throwable $e) {
        errorResponse(
            'Gagal menghubungkan atau mengeksekusi perintah MikroTik: ' . $e->getMessage(),
            502
        );
    }
}
// POST /customers/{id}/buka-isolir (MikroTik API)
if (preg_match('#^/customers/(\d+)/buka-isolir$#', $path, $m) && $method === 'POST') {
    requirePermission($auth, $currentUser, 'customers.unisolate');
    $custId = (int)$m[1];

    $custStmt = $db->prepare("
        SELECT c.*, p.name as package_name, p.mikrotik_profile
        FROM customers c
        JOIN packages p ON c.package_id = p.id
        WHERE c.id = :id
    ");
    $custStmt->execute([':id' => $custId]);
    $cust = $custStmt->fetch();

    if (!$cust) {
        errorResponse('Pelanggan tidak ditemukan.', 404);
    }

    if (empty($cust['pppoe_username'])) {
        errorResponse('Username PPPoE pelanggan belum tersedia.', 400);
    }

    $normalProfile = $cust['mikrotik_profile']
        ?: ($cust['package_name'] ?: 'default');

    // Cari router MikroTik yang sudah dikonfigurasi
    $rDev = $db->query("
        SELECT *
        FROM devices
        WHERE device_type = 'ROUTER'
          AND mikrotik_username IS NOT NULL
          AND mikrotik_username != ''
          AND mikrotik_password_encrypted IS NOT NULL
          AND mikrotik_password_encrypted != ''
        ORDER BY id ASC
        LIMIT 1
    ")->fetch();

    if (!$rDev) {
        errorResponse(
            'Belum ada router MikroTik yang dikonfigurasi.',
            400
        );
    }

    try {
        $crypto = new CryptoService($config['app']['encryption_key']);
$mikrotikPassword = $crypto->decrypt($rDev['mikrotik_password_encrypted']);

$mSvc = new MikrotikService(
    $rDev['ip_address'],
    $rDev['mikrotik_username'],
    $mikrotikPassword,
    (int)($rDev['mikrotik_api_port'] ?? 8728)
);

        $unRes = $mSvc->bukaIsolirCustomer(
            $cust['pppoe_username'],
            $normalProfile
        );

        if (empty($unRes['success'])) {
            errorResponse(
                $unRes['error'] ?? 'Gagal membuka isolir pada MikroTik.',
                502
            );
        }

        // Hanya update database jika MikroTik benar-benar berhasil
        $db->prepare("
            UPDATE customers
            SET status = 'ACTIVE',
                isolir_at = NULL,
                isolir_reason = NULL
            WHERE id = :id
        ")->execute([':id' => $custId]);

        $audit->log(
            (int)$currentUser['id'],
            'BUKA_ISOLIR_CUSTOMER',
            'CUSTOMER',
            (string)$custId,
            "Membuka isolir pelanggan {$cust['name']} ({$cust['pppoe_username']}) melalui MikroTik {$rDev['name']}"
        );

        jsonResponse([
            'success' => true,
            'message' => "Isolir pelanggan '{$cust['name']}' berhasil dibuka. " .
                         ($unRes['message'] ?? ''),
            'status' => 'ACTIVE'
        ]);

    } catch (\Throwable $e) {
        errorResponse(
            'Gagal menghubungkan atau mengeksekusi perintah MikroTik: ' . $e->getMessage(),
            502
        );
    }
}
    // POST /customers/{id}/send-wa-reminder (API WhatsApp Pengingat Tagihan)
    if (preg_match('#^/customers/(\d+)/send-wa-reminder$#', $path, $m) && $method === 'POST') {
        requirePermission($auth, $currentUser, 'customers.reminder');

        $custId = (int)$m[1];
        $custStmt = $db->prepare("SELECT c.*, p.name as package_name, p.price, p.bandwidth FROM customers c JOIN packages p ON c.package_id = p.id WHERE c.id = :id");
        $custStmt->execute([':id' => $custId]);
        $cust = $custStmt->fetch();
        if (!$cust) errorResponse('Pelanggan tidak ditemukan.', 404);

        // Standarisasi format nomor WhatsApp Indonesia (62xxx)
        $cleanPhone = preg_replace('/[^\d]/', '', $cust['phone_number']);
        if (str_starts_with($cleanPhone, '0')) {
            $cleanPhone = '62' . substr($cleanPhone, 1);
        }

        $formattedPrice = number_format((float)$cust['price'], 0, ',', '.');
        $dueDate = date('d F Y', strtotime('+3 days'));

        $msgText = "Halo Pelanggan Yth. *{$cust['name']}*,\n\n"
                 . "Berikut pemberitahuan tagihan internet Transdata periode ini:\n"
                 . "• No. Pelanggan : *{$cust['customer_number']}*\n"
                 . "• Paket Layanan : *{$cust['package_name']}* ({$cust['bandwidth']})\n"
                 . "• Total Tagihan : *Rp {$formattedPrice}*\n"
                 . "• Batas Waktu   : *{$dueDate}*\n"
                 . "• Status Sesi   : *{$cust['status']}*\n\n"
                 . "Pembayaran dapat ditransfer melalui:\n"
                 . "• BCA: 1234-5678-90 a/n PT TRANSDATA PRIMA\n"
                 . "• Mandiri: 9876-5432-10 a/n PT TRANSDATA PRIMA\n\n"
                 . "Setelah transfer, mohon kirimkan bukti bayar ke WhatsApp ini.\n"
                 . "Terima kasih atas kepercayaannya.\n_Transdata NOC Operations_";

        $waDirectUrl = "https://wa.me/{$cleanPhone}?text=" . rawurlencode($msgText);

        $audit->log((int)$currentUser['id'], 'SEND_WA_REMINDER', 'CUSTOMER', (string)$custId, "Kirim pengingat tagihan WA ke {$cust['name']} ($cleanPhone)");

        jsonResponse([
            'success'      => true,
            'message'      => "Pesan pengingat tagihan untuk {$cust['name']} siap dikirim.",
            'phone'        => $cleanPhone,
            'wa_url'       => $waDirectUrl,
            'message_text' => $msgText
        ]);
    }

    // ==========================================
    // ONT REALTIME TELEMETRY (GENIEACS + SNMP OLT + PING)
    // ==========================================

    // GET /ont/{sn}/realtime
    if (preg_match('#^/ont/([^/]+)/realtime$#', $path, $m) && $method === 'GET') {
        $sn = urldecode($m[1]);

        // 1. Ambil telemetri dari GenieACS NBI
        $acsTele = $genieAcsSvc->getOntRealtimeTelemetry($sn);

        if (empty($acsTele['success'])) {
            jsonResponse([
                'success' => false,
                'error' => $acsTele['error'] ?? 'Data ONT tidak berhasil diambil dari GenieACS.'
            ], 502);
        }

        // 2. Query status OLT hanya jika perangkat OLT terdaftar.
        $oltDev = $db->query(
            "SELECT * FROM devices WHERE device_type = 'OLT' LIMIT 1"
        )->fetch();

        $oltStatus = [
            'success' => false,
            'olt_status' => 'unknown',
            'pon_interface' => null
        ];

        if ($oltDev && !empty($oltDev['ip_address'])) {
            $oltIp = $oltDev['ip_address'];
            $crypto = new CryptoService($config['app']['encryption_key']);
            $oltComm = resolveSnmpCommunity($oltDev['snmp_community_encrypted'] ?? null, $crypto);

            $oltStatus = $snmpSvc->queryZteOltOntStatus(
                $oltIp,
                $oltComm,
                $sn
            );
        }

        // 3. Ping hanya jika GenieACS memberikan IP yang valid.
        $targetIp = $acsTele['ip_address'] ?? null;
        $pingResult = null;

        if (is_string($targetIp) && filter_var($targetIp, FILTER_VALIDATE_IP)) {
            $pingResult = $pingSvc->executePing($targetIp, 2);
        }

        // 4. Ambil uptime PPPoE dari router yang terhubung ke paket pelanggan.
        // Jangan memilih router pertama karena NMS mendukung banyak MikroTik.
        $pppoeUptime = null;

        try {
            $customerStmt = $db->prepare("
                SELECT c.pppoe_username, p.mikrotik_device_id
                FROM customers c
                LEFT JOIN packages p ON p.id = c.package_id
                WHERE TRIM(c.ont_sn) = :sn
                LIMIT 1
            ");
            $customerStmt->execute([
                ':sn' => trim((string)($acsTele['serial_number'] ?? $sn))
            ]);
            $customer = $customerStmt->fetch();

            if (
                !$customer ||
                empty($customer['pppoe_username']) ||
                empty($customer['mikrotik_device_id'])
            ) {
                // Data pelanggan, username, atau router paket belum tersedia.
                $pppoeUptime = null;
            } else {
                $routerStmt = $db->prepare("
                    SELECT id, name, ip_address, mikrotik_username,
                           mikrotik_password_encrypted, mikrotik_api_port
                    FROM devices
                    WHERE id = :id
                      AND device_type = 'ROUTER'
                      AND mikrotik_username IS NOT NULL
                      AND mikrotik_username != ''
                      AND mikrotik_password_encrypted IS NOT NULL
                      AND mikrotik_password_encrypted != ''
                    LIMIT 1
                ");
                $routerStmt->execute([
                    ':id' => (int)$customer['mikrotik_device_id']
                ]);
                $router = $routerStmt->fetch();

                if ($router && !empty($router['ip_address'])) {
                    $mtCrypto = new CryptoService($config['app']['encryption_key']);
                    $mtPassword = $mtCrypto->decrypt(
                        (string)$router['mikrotik_password_encrypted']
                    );

                    $mtService = new MikrotikService(
                        (string)$router['ip_address'],
                        (string)$router['mikrotik_username'],
                        $mtPassword,
                        (int)($router['mikrotik_api_port'] ?? 8728)
                    );

                    $activeResult = $mtService->getPppoeActive();

                    if (
                        !empty($activeResult['success']) &&
                        !empty($activeResult['data']) &&
                        is_array($activeResult['data'])
                    ) {
                        foreach ($activeResult['data'] as $session) {
                            if (
                                isset($session['name'], $session['uptime']) &&
                                (string)$session['name'] ===
                                    (string)$customer['pppoe_username'] &&
                                trim((string)$session['uptime']) !== ''
                            ) {
                                $pppoeUptime = (string)$session['uptime'];
                                break;
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Gangguan API MikroTik tidak menggagalkan telemetri ONT lainnya.
            $pppoeUptime = null;
        }

        jsonResponse([
            'success'              => true,
            'serial_number'        => $acsTele['serial_number'] ?? $sn,
            'model'                => $acsTele['model'] ?? 'N/A',
            'vendor'               => $acsTele['vendor'] ?? 'N/A',
            'hardware_version'     => $acsTele['hardware_version'] ?? 'N/A',
            'software_version'     => $acsTele['software_version'] ?? 'N/A',
            'power_rx_dbm'         => $acsTele['power_rx_dbm'] ?? 'N/A',
            'power_tx_dbm'         => $acsTele['power_tx_dbm'] ?? 'N/A',
            'temperature_c'        => $acsTele['temperature_c'] ?? 'N/A',
            'voltage_v'            => $acsTele['voltage_v'] ?? 'N/A',
            'uptime_ont'            => $acsTele['uptime_ont'] ?? null,
            'uptime_pppoe'          => $pppoeUptime,
            'wifi_ssid'            => $acsTele['wifi_ssid'] ?? 'N/A',
            'wifi_active_clients'  => $acsTele['wifi_active_clients'] ?? 'N/A',
            'olt_status'           => $oltStatus['olt_status'] ?? 'unknown',
            'pon_interface'        => $oltStatus['pon_interface'] ?? null,
            'ip_address'           => $targetIp ?? 'N/A',
            'mac_address'          => $acsTele['mac_address'] ?? 'N/A',
            'ping_latency_ms'      => $pingResult['latency_ms'] ?? null,
            'ping_packet_loss_pct' => $pingResult['packet_loss'] ?? null,
            'last_inform'           => $acsTele['last_inform'] ?? null,
            'timestamp'             => date('Y-m-d H:i:s')
        ]);
    }

    // ==========================================
    // FTTH GIS OBJECTS & CABLES
    // ==========================================

    // GET /gis/objects
    if ($path === '/gis/objects' && $method === 'GET') {
        $objects = $gisSvc->getAllObjects($_GET);
        jsonResponse(['success' => true, 'count' => count($objects), 'objects' => $objects]);
    }

    // POST /gis/objects
    if ($path === '/gis/objects' && $method === 'POST') {
        $res = $gisSvc->saveObject($input);
        $audit->log((int)$currentUser['id'], 'SAVE_GIS_OBJECT', 'GIS_OBJECT', (string)$res['id'], "Menyimpan objek GIS {$res['name']} ({$res['object_type']})");
        jsonResponse(['success' => true, 'object' => $res], 201);
    }

    // DELETE /gis/objects/{id}
    if (preg_match('#^/gis/objects/([^/]+)$#', $path, $m) && $method === 'DELETE') {
        $id = $m[1];
        $gisSvc->deleteObject($id);
        $audit->log((int)$currentUser['id'], 'DELETE_GIS_OBJECT', 'GIS_OBJECT', $id, "Menghapus objek GIS $id");
        jsonResponse(['success' => true, 'message' => 'Objek GIS berhasil dihapus.']);
    }

    // GET /gis/cables
    if ($path === '/gis/cables' && $method === 'GET') {
        $cables = $gisSvc->getAllCables();
        jsonResponse(['success' => true, 'count' => count($cables), 'cables' => $cables]);
    }

    // POST /gis/cables
    if ($path === '/gis/cables' && $method === 'POST') {
        $res = $gisSvc->saveCable($input);
        $audit->log((int)$currentUser['id'], 'SAVE_GIS_CABLE', 'GIS_CABLE', (string)$res['id'], "Menyimpan jalur kabel FTTH");
        jsonResponse(['success' => true, 'cable' => $res], 201);
    }

    // DELETE /gis/cables/{id}
if (preg_match('#^/gis/cables/([^/]+)$#', $path, $m) && $method === 'DELETE') {
    $id = $m[1];

    $stmt = $db->prepare("
        SELECT id, start_object_id, end_object_id
        FROM ftth_cables
        WHERE id = :id
    ");

    $stmt->execute([':id' => $id]);

    $cable = $stmt->fetch();

    if (!$cable) {
        errorResponse('Kabel tidak ditemukan.', 404);
    }

    $startObjectId = $cable['start_object_id'];
    $endObjectId   = $cable['end_object_id'];

    // Hapus vertex terlebih dahulu.
    $db->prepare("
        DELETE FROM ftth_cable_vertices
        WHERE cable_id = :id
    ")->execute([
        ':id' => $id
    ]);

    // Hapus kabel.
    $db->prepare("
        DELETE FROM ftth_cables
        WHERE id = :id
    ")->execute([
        ':id' => $id
    ]);

    // Recalculate port kedua endpoint.
    $recalculatePortUsage = function (?string $objectId) use ($db): void {
        if (!$objectId) {
            return;
        }

        $countStmt = $db->prepare("
            SELECT COUNT(*)
            FROM ftth_cables
            WHERE status = 'ACTIVE'
              AND (
                    start_object_id = :id_start
                    OR end_object_id = :id_end
              )
        ");

        $countStmt->execute([
            ':id_start' => $objectId,
            ':id_end'   => $objectId
        ]);

        $used = (int)$countStmt->fetchColumn();

        $updateStmt = $db->prepare("
            UPDATE ftth_objects
            SET ports_used = :used
            WHERE id = :id
        ");

        $updateStmt->execute([
            ':used' => $used,
            ':id'   => $objectId
        ]);
    };

    $recalculatePortUsage($startObjectId);
    $recalculatePortUsage($endObjectId);

    $audit->log(
        (int)$currentUser['id'],
        'DELETE_GIS_CABLE',
        'GIS_CABLE',
        $id,
        "Menghapus kabel FTTH $id"
    );

    jsonResponse([
        'success' => true,
        'message' => 'Kabel berhasil dihapus.'
    ]);
}

    // GET /gis/topology/trace/{id}
    if (preg_match('#^/gis/topology/trace/([^/]+)$#', $path, $m) && $method === 'GET') {
        $trace = $gisSvc->traceDownstreamTopology($m[1]);
        jsonResponse(['success' => true, 'trace' => $trace]);
    }

    // ==========================================
    // ALARMS & AUDIT LOGS
    // ==========================================

    // GET /alarms
    if ($path === '/alarms' && $method === 'GET') {
        $alarms = $db->query("SELECT a.*, d.name as device_name, d.ip_address as device_ip FROM alarms a LEFT JOIN devices d ON a.device_id = d.id ORDER BY a.created_at DESC LIMIT 100")->fetchAll();
        jsonResponse(['success' => true, 'alarms' => $alarms]);
    }

    // POST /alarms/{id}/acknowledge
    if (preg_match('#^/alarms/(\d+)/acknowledge$#', $path, $m) && $method === 'POST') {
        $aId = (int)$m[1];
        $db->prepare("UPDATE alarms SET status = 'ACKNOWLEDGED', acknowledged_by = :uid, acknowledged_at = NOW() WHERE id = :id")->execute([':uid' => (int)$currentUser['id'], ':id' => $aId]);
        jsonResponse(['success' => true, 'message' => 'Alarm berhasil di-acknowledge.']);
    }

    // GET /audit-logs
    if ($path === '/audit-logs' && $method === 'GET') {
        $logs = $audit->getRecentLogs(100);
        jsonResponse(['success' => true, 'logs' => $logs]);
    }

    // GET /settings & POST /settings
    if ($path === '/settings' && $method === 'GET') {
        $rows = $db->query("SELECT setting_key, setting_value, description FROM system_settings")->fetchAll();
        $settings = [];
        foreach ($rows as $r) {
            $settings[$r['setting_key']] = $r['setting_value'];
        }
        jsonResponse(['success' => true, 'settings' => $settings]);
    }

    if ($path === '/settings' && $method === 'POST') {
        $upStmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($input as $k => $v) {
            $upStmt->execute([':k' => $k, ':v' => (string)$v]);
        }
        $audit->log((int)$currentUser['id'], 'UPDATE_SETTINGS', 'SYSTEM', null, "Memperbarui konfigurasi sistem");
        jsonResponse(['success' => true, 'message' => 'Pengaturan sistem berhasil disimpan.']);
    }

// GET /devices/{id}
if (preg_match('#^/devices/(\d+)$#', $path, $m) && $method === 'GET') {
    $deviceId = (int)$m[1];

     $stmt = $db->prepare("
        SELECT
            id,
            name,
            ip_address,
            device_type,
            vendor,
            model,
            serial_number,
            location,
            snmp_version,
            snmp_port,
            monitoring_enabled,
            ping_status,
            ping_latency_ms,
            ping_packet_loss_pct,
            last_ping_time,
            snmp_status,
            snmp_uptime,
            snmp_descr,
            last_snmp_time,
            last_snmp_error,
            mikrotik_api_port,
            mikrotik_username,
            mikrotik_use_ssl,
            CASE
                WHEN mikrotik_username IS NOT NULL
                 AND mikrotik_username != ''
                 AND mikrotik_password_encrypted IS NOT NULL
                 AND mikrotik_password_encrypted != ''
                THEN 1
                ELSE 0
            END AS mikrotik_configured,
            created_at
        FROM devices
        WHERE id = :id
    ");
    $stmt->execute([':id' => $deviceId]);
    $device = $stmt->fetch();

    if (!$device) {
        errorResponse('Perangkat tidak ditemukan.', 404);
    }

    $ifStmt = $db->prepare("
        SELECT
            id,
            if_index,
            if_name,
            if_descr,
            if_oper_status,
            is_monitored,
            last_in_octets,
            last_out_octets,
            last_sample_time,
            current_rx_rate_bps,
            current_tx_rate_bps
        FROM device_interfaces
        WHERE device_id = :device_id
        ORDER BY if_index ASC
    ");
    $ifStmt->execute([':device_id' => $deviceId]);

    jsonResponse([
        'success' => true,
        'device' => $device,
        'interfaces' => $ifStmt->fetchAll()
    ]);
}

// DELETE /devices/{id}
if (preg_match('#^/devices/(\d+)$#', $path, $m) && $method === 'DELETE') {
    $deviceId = (int)$m[1];

    $stmt = $db->prepare("SELECT name, ip_address FROM devices WHERE id = :id");
    $stmt->execute([':id' => $deviceId]);
    $device = $stmt->fetch();

    if (!$device) {
        errorResponse('Perangkat tidak ditemukan.', 404);
    }

    $stmt = $db->prepare("DELETE FROM devices WHERE id = :id");
    $stmt->execute([':id' => $deviceId]);

    $audit->log(
        (int)$currentUser['id'],
        'DELETE_DEVICE',
        'DEVICE',
        (string)$deviceId,
        "Menghapus perangkat {$device['name']} ({$device['ip_address']})"
    );

    jsonResponse([
        'success' => true,
        'message' => "Perangkat {$device['name']} berhasil dihapus."
    ]);
}
// POST /devices/{id}/poll-snmp
if (preg_match('#^/devices/(\d+)/poll-snmp$#', $path, $m) && $method === 'POST') {
    $deviceId = (int)$m[1];

    $stmt = $db->prepare("SELECT * FROM devices WHERE id = :id");
    $stmt->execute([':id' => $deviceId]);
    $device = $stmt->fetch();

    if (!$device) {
        errorResponse('Perangkat tidak ditemukan.', 404);
    }

    $crypto = new CryptoService($config['app']['encryption_key']);
    $community = resolveSnmpCommunity($device['snmp_community_encrypted'] ?? null, $crypto);

    $snmpResult = $snmpSvc->querySystemInfo(
        $device['ip_address'],
        $community,
        $device['snmp_version'],
        (int)$device['snmp_timeout_sec'],
        (int)$device['snmp_retries']
    );

    $stmt = $db->prepare("
        UPDATE devices SET
            snmp_status = :status,
            snmp_uptime = :uptime,
            snmp_descr = :descr,
            last_snmp_time = NOW(),
            last_snmp_error = :error
        WHERE id = :id
    ");

    $stmt->execute([
        ':status' => $snmpResult['status'] ?? 'ERROR',
        ':uptime' => $snmpResult['sys_uptime'] ?? null,
        ':descr'  => $snmpResult['sys_descr'] ?? null,
        ':error'  => $snmpResult['error'] ?? null,
        ':id'     => $deviceId
    ]);

    jsonResponse([
        'success' => true,
        'snmp' => $snmpResult
    ]);
}
// GET /mikrotik/routers
if ($path === '/mikrotik/routers' && $method === 'GET') {
    $stmt = $db->query("
        SELECT
            id,
            name,
            ip_address,
            mikrotik_api_port,
            mikrotik_use_ssl
        FROM devices
        WHERE device_type = 'ROUTER'
          AND vendor = 'Mikrotik'
          AND mikrotik_username IS NOT NULL
          AND mikrotik_username != ''
          AND mikrotik_password_encrypted IS NOT NULL
          AND mikrotik_password_encrypted != ''
        ORDER BY name ASC
    ");

    $routers = $stmt->fetchAll();

    jsonResponse([
        'success' => true,
        'routers' => array_map(function ($router) {
            return [
                'id' => (int)$router['id'],
                'name' => $router['name'],
                'ip_address' => $router['ip_address'],
                'api_port' => (int)$router['mikrotik_api_port'],
                'use_ssl' => (bool)$router['mikrotik_use_ssl']
            ];
        }, $routers)
    ]);
}
// GET /mikrotik/profiles?device_id=2
if ($path === '/mikrotik/profiles' && $method === 'GET') {
    $deviceId = isset($_GET['device_id'])
        ? (int)$_GET['device_id']
        : 0;

    if ($deviceId <= 0) {
        errorResponse('device_id wajib diisi.', 400);
    }

    $stmt = $db->prepare("
        SELECT
            id,
            name,
            ip_address,
            mikrotik_api_port,
            mikrotik_username,
            mikrotik_password_encrypted,
            mikrotik_use_ssl
        FROM devices
        WHERE id = :id
          AND device_type = 'ROUTER'
          AND vendor = 'Mikrotik'
          AND mikrotik_username IS NOT NULL
          AND mikrotik_username != ''
          AND mikrotik_password_encrypted IS NOT NULL
          AND mikrotik_password_encrypted != ''
        LIMIT 1
    ");

    $stmt->execute([
        ':id' => $deviceId
    ]);

    $router = $stmt->fetch();

    if (!$router) {
        errorResponse(
            'Router MikroTik tidak ditemukan atau belum dikonfigurasi.',
            404
        );
    }

    try {
    $crypto = new CryptoService($config['app']['encryption_key']);

    $password = $crypto->decrypt(
        $router['mikrotik_password_encrypted']
    );

        $mSvc = new MikrotikService(
        $router['ip_address'],
        $router['mikrotik_username'],
        $password,
        (int)($router['mikrotik_api_port'] ?? 8728)
        );

        $res = $mSvc->getPppProfiles();

        if (!($res['success'] ?? false)) {
            errorResponse(
                $res['error'] ?? 'Gagal mengambil profile PPP MikroTik.',
                502
            );
        }

        $profiles = [];

        foreach ($res['data'] ?? [] as $profile) {
            if (!empty($profile['name'])) {
                $profiles[] = [
                    'name' => $profile['name']
                ];
            }
        }

        usort($profiles, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        jsonResponse([
            'success' => true,
            'router' => [
                'id' => (int)$router['id'],
                'name' => $router['name'],
                'ip_address' => $router['ip_address']
            ],
            'profiles' => $profiles
        ]);

    } catch (\Throwable $e) {
        errorResponse(
            'Gagal mengambil profile MikroTik: ' . $e->getMessage(),
            502
        );
    }
}
// POST /mikrotik/test-connection
if ($path === '/mikrotik/test-connection' && $method === 'POST') {
    $deviceId = (int)($input['device_id'] ?? 0);

    if ($deviceId <= 0) {
        errorResponse('Device ID MikroTik wajib diisi.', 400);
    }

    $stmt = $db->prepare("
        SELECT
            id,
            name,
            ip_address,
            mikrotik_api_port,
            mikrotik_username,
            mikrotik_password_encrypted,
            mikrotik_use_ssl
        FROM devices
        WHERE id = :id
          AND device_type = 'ROUTER'
        LIMIT 1
    ");

    $stmt->execute([':id' => $deviceId]);
    $device = $stmt->fetch();

    if (!$device) {
        errorResponse('Router MikroTik tidak ditemukan.', 404);
    }

    if (empty($device['ip_address'])) {
        errorResponse('IP address MikroTik belum dikonfigurasi.', 400);
    }

    if (empty($device['mikrotik_username'])) {
        errorResponse('Username MikroTik belum dikonfigurasi.', 400);
    }

    if (empty($device['mikrotik_password_encrypted'])) {
        errorResponse('Password MikroTik belum dikonfigurasi.', 400);
    }

    $port = (int)($device['mikrotik_api_port'] ?? 8728);

    try {
        /*
         * Decrypt password MikroTik dari database.
         */
        $crypto = new CryptoService($config['app']['encryption_key']);

        $mikrotikPassword = $crypto->decrypt(
            $device['mikrotik_password_encrypted']
        );

        /*
         * Buat service dengan credential asli.
         */
        $mikrotikSvc = new MikrotikService(
            $device['ip_address'],
            $device['mikrotik_username'],
            $mikrotikPassword,
            $port,
            5
        );

        /*
         * Test TCP terlebih dahulu.
         */
        $tcpResult = $mikrotikSvc->testConnection();

        if (empty($tcpResult['success'])) {
            jsonResponse([
                'success' => false,
                'status' => 'UNREACHABLE',
                'message' => $tcpResult['message'] ?? 'Port API MikroTik tidak dapat diakses.'
            ], 502);
        }

        /*
         * Test autentikasi RouterOS.
         *
         * /system/resource/print hanya digunakan sebagai
         * command ringan untuk memastikan login berhasil.
         */
        $authResult = $mikrotikSvc->executeCommand('/system/resource/print');

        if (empty($authResult['success'])) {
            jsonResponse([
                'success' => false,
                'status' => 'AUTH_FAILED',
                'host' => $device['ip_address'],
                'port' => $port,
                'message' => $authResult['error'] ?? 'Autentikasi RouterOS gagal.'
            ], 502);
        }

        jsonResponse([
            'success' => true,
            'status' => 'AUTHENTICATED',
            'host' => $device['ip_address'],
            'port' => $port,
            'username' => $device['mikrotik_username'],
            'latency_ms' => $tcpResult['latency_ms'] ?? null,
            'message' => 'Koneksi dan autentikasi MikroTik berhasil.'
        ]);

    } catch (\Throwable $e) {
        errorResponse(
            'Gagal menguji koneksi MikroTik: ' . $e->getMessage(),
            502
        );
    }
}
// GET /genieacs/devices
if ($path === '/genieacs/devices' && $method === 'GET') {
    $result = $genieAcsSvc->getDevices();

    if (!$result['success']) {
        jsonResponse($result, 502);
    }

    jsonResponse($result);
}
// GET /gis/objects
if ($path === '/gis/objects' && $method === 'GET') {
    $objects = $gisSvc->getAllObjects();

    jsonResponse([
        'success' => true,
        'count' => count($objects),
        'objects' => $objects
    ]);
}
// POST /gis/objects
if ($path === '/gis/objects' && $method === 'POST') {
    foreach (['object_type', 'code', 'name', 'latitude', 'longitude'] as $field) {
        if (!isset($input[$field]) || $input[$field] === '') {
            errorResponse("Field {$field} wajib diisi.", 400);
        }
    }

    $object = $gisSvc->saveObject($input);

    $audit->log(
        (int)$currentUser['id'],
        'SAVE_FTTH_OBJECT',
        'GIS_OBJECT',
        (string)$object['id'],
        "Menyimpan objek GIS {$object['code']}"
    );

    jsonResponse([
        'success' => true,
        'object' => $object
    ], 201);
}
// DELETE /gis/objects/{id}
if (preg_match('#^/gis/objects/([^/]+)$#', $path, $m) && $method === 'DELETE') {
    $id = $m[1];

    $object = $gisSvc->getObjectById($id);

    if (!$object) {
        errorResponse('Objek GIS tidak ditemukan.', 404);
    }

    $gisSvc->deleteObject($id);

    $audit->log(
        (int)$currentUser['id'],
        'DELETE_FTTH_OBJECT',
        'GIS_OBJECT',
        $id,
        "Menghapus objek GIS {$object['code']}"
    );

    jsonResponse([
        'success' => true,
        'message' => "Objek {$object['name']} berhasil dihapus."
    ]);
}
// GET /gis/cables
if ($path === '/gis/cables' && $method === 'GET') {
    $cables = $gisSvc->getAllCables();

    jsonResponse([
        'success' => true,
        'count' => count($cables),
        'cables' => $cables
    ]);
}
// POST /gis/cables
if ($path === '/gis/cables' && $method === 'POST') {
    if (
        empty($input['cable_code']) ||
        empty($input['cable_name'])
    ) {
        errorResponse('Kode kabel dan nama kabel wajib diisi.', 400);
    }

    $cable = $gisSvc->saveCable($input);

    $audit->log(
        (int)$currentUser['id'],
        'SAVE_FTTH_CABLE',
        'GIS_CABLE',
        (string)$cable['id'],
        "Menyimpan kabel FTTH {$input['cable_code']}"
    );

    jsonResponse([
        'success' => true,
        'cable' => $cable
    ], 201);
}
// DELETE /gis/cables/{id}
if (preg_match('#^/gis/cables/([^/]+)$#', $path, $m) && $method === 'DELETE') {
    $id = $m[1];

    $cables = $gisSvc->getAllCables();
    $found = null;

    foreach ($cables as $cable) {
        if ((string)$cable['id'] === $id) {
            $found = $cable;
            break;
        }
    }

    if (!$found) {
        errorResponse('Kabel tidak ditemukan.', 404);
    }

    $stmt = $db->prepare("DELETE FROM ftth_cables WHERE id = :id");
    $stmt->execute([':id' => $id]);

    $audit->log(
        (int)$currentUser['id'],
        'DELETE_FTTH_CABLE',
        'GIS_CABLE',
        $id,
        "Menghapus kabel FTTH {$found['cable_code']}"
    );

    jsonResponse([
        'success' => true,
        'message' => "Kabel {$found['cable_name']} berhasil dihapus."
    ]);
}
// GET /gis/topology/trace/{id}
if (preg_match('#^/gis/topology/trace/([^/]+)$#', $path, $m) && $method === 'GET') {
    $objectId = $m[1];

    $object = $gisSvc->getObjectById($objectId);

    if (!$object) {
        errorResponse('Objek tidak ditemukan.', 404);
    }

    $result = $gisSvc->traceDownstreamCustomers($objectId);

    jsonResponse([
        'success' => true,
        'target_object' => $object,
        'affected_nodes_count' => $result['affected_nodes'],
        'affected_subscribers_count' => $result['affected_subscribers'],
        'subscribers' => $result['subscribers']
    ]);
}
// GET /alarms
if ($path === '/alarms' && $method === 'GET') {
    $stmt = $db->query("
        SELECT
            a.*,
            d.name AS device_name,
            d.ip_address AS device_ip
        FROM alarms a
        LEFT JOIN devices d ON a.device_id = d.id
        ORDER BY a.created_at DESC
    ");

    jsonResponse([
        'success' => true,
        'alarms' => $stmt->fetchAll()
    ]);
}
// POST /alarms/{id}/acknowledge
if (preg_match('#^/alarms/(\d+)/acknowledge$#', $path, $m) && $method === 'POST') {
    $alarmId = (int)$m[1];

    $stmt = $db->prepare("SELECT * FROM alarms WHERE id = :id");
    $stmt->execute([':id' => $alarmId]);
    $alarm = $stmt->fetch();

    if (!$alarm) {
        errorResponse('Alarm tidak ditemukan.', 404);
    }

    $stmt = $db->prepare("
        UPDATE alarms SET
            status = 'ACKNOWLEDGED',
            acknowledged_by = :user_id,
            acknowledged_at = NOW()
        WHERE id = :id
    ");

    $stmt->execute([
        ':user_id' => (int)$currentUser['id'],
        ':id' => $alarmId
    ]);

    $stmt = $db->prepare("SELECT * FROM alarms WHERE id = :id");
    $stmt->execute([':id' => $alarmId]);

    $updated = $stmt->fetch();

    $audit->log(
        (int)$currentUser['id'],
        'ACKNOWLEDGE_ALARM',
        'ALARM',
        (string)$alarmId,
        'Alarm di-acknowledge.'
    );

    jsonResponse([
        'success' => true,
        'alarm' => $updated
    ]);
}
// GET /audit-logs
if ($path === '/audit-logs' && $method === 'GET') {
    $logs = $audit->getRecentLogs(100);

    jsonResponse([
        'success' => true,
        'logs' => $logs
    ]);
}
// GET /settings
if ($path === '/settings' && $method === 'GET') {
    $stmt = $db->query("
        SELECT setting_key, setting_value
        FROM system_settings
        ORDER BY setting_key ASC
    ");

    $settings = [];

    foreach ($stmt->fetchAll() as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    jsonResponse([
        'success' => true,
        'settings' => $settings
    ]);
}
// POST /settings
if ($path === '/settings' && $method === 'POST') {
    if (!is_array($input)) {
        errorResponse('Format settings tidak valid.', 400);
    }

    $stmt = $db->prepare("
        INSERT INTO system_settings
            (setting_key, setting_value)
        VALUES
            (:key, :value)
        ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value)
    ");

    foreach ($input as $key => $value) {
        if (!is_string($key)) {
            continue;
        }

        $stmt->execute([
            ':key' => $key,
            ':value' => is_scalar($value)
                ? (string)$value
                : json_encode($value)
        ]);
    }

    $stmt = $db->query("
        SELECT setting_key, setting_value
        FROM system_settings
        ORDER BY setting_key ASC
    ");

    $settings = [];

    foreach ($stmt->fetchAll() as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    $audit->log(
        (int)$currentUser['id'],
        'UPDATE_SETTINGS',
        'SYSTEM',
        null,
        'Pengaturan sistem diperbarui.'
    );

    jsonResponse([
        'success' => true,
        'settings' => $settings
    ]);
}


// USER MANAGEMENT API
// All endpoints require the users.manage permission.
if (preg_match('#^/users(?:/(\d+))?$#', $path, $userMatch)
    && in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true)) {
    requirePermission($auth, $currentUser, 'users.manage');

    $userId = isset($userMatch[1]) ? (int)$userMatch[1] : 0;

    $publicUserSelect = "
        SELECT u.id, u.username, u.email, u.full_name, u.role_id,
               r.name AS role_name, u.is_active, u.last_login,
               u.created_at, u.updated_at
        FROM users u
        JOIN roles r ON r.id = u.role_id
    ";

    if ($method === 'GET' && $userId === 0) {
        $users = $db->query($publicUserSelect . " ORDER BY u.id ASC")->fetchAll();
        $roles = $db->query("SELECT id, name, description FROM roles ORDER BY name ASC")->fetchAll();

        jsonResponse([
            'success' => true,
            'users' => $users,
            'roles' => $roles
        ]);
    }

    if ($method === 'POST' && $userId === 0) {
        if (!is_array($input)) {
            errorResponse('Format data pengguna tidak valid.', 400);
        }

        $username = trim((string)($input['username'] ?? ''));
        $email = trim((string)($input['email'] ?? ''));
        $fullName = trim((string)($input['full_name'] ?? ''));
        $password = (string)($input['password'] ?? '');
        $roleId = filter_var($input['role_id'] ?? null, FILTER_VALIDATE_INT);
        $isActive = filter_var($input['is_active'] ?? 1, FILTER_VALIDATE_INT);

        if ($username === '' || strlen($username) > 60
            || !preg_match('/^[A-Za-z0-9._-]+$/', $username)) {
            errorResponse('Username wajib diisi, maksimal 60 karakter, dan hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda hubung.', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) {
            errorResponse('Alamat email tidak valid.', 422);
        }
        if ($fullName === '' || strlen($fullName) > 100) {
            errorResponse('Nama lengkap wajib diisi dan maksimal 100 karakter.', 422);
        }
        if (strlen($password) < 12) {
            errorResponse('Password harus minimal 12 karakter.', 422);
        }
        if (!$roleId || !in_array($isActive, [0, 1], true)) {
            errorResponse('Role atau status akun tidak valid.', 422);
        }

        $roleStmt = $db->prepare("SELECT id FROM roles WHERE id = :id");
        $roleStmt->execute([':id' => $roleId]);
        if (!$roleStmt->fetchColumn()) {
            errorResponse('Role yang dipilih tidak ditemukan.', 422);
        }
        if ((int)$roleId === 1 && (int)$currentUser['role_id'] !== 1) {
            errorResponse('Hanya Superadmin yang boleh membuat akun Superadmin.', 403);
        }

        $dup = $db->prepare("SELECT username, email FROM users WHERE username = :username OR email = :email");
        $dup->execute([':username' => $username, ':email' => $email]);
        $existing = $dup->fetch();
        if ($existing) {
            errorResponse(
                $existing['username'] === $username
                    ? 'Username sudah digunakan.'
                    : 'Email sudah digunakan.',
                409
            );
        }

        $stmt = $db->prepare("
            INSERT INTO users (username, email, password_hash, full_name, role_id, is_active, created_at, updated_at)
            VALUES (:username, :email, :password_hash, :full_name, :role_id, :is_active, NOW(), NOW())
        ");
        $stmt->execute([
            ':username' => $username,
            ':email' => $email,
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':full_name' => $fullName,
            ':role_id' => $roleId,
            ':is_active' => $isActive
        ]);

        $newId = (int)$db->lastInsertId();
        $audit->log((int)$currentUser['id'], 'CREATE_USER', 'USER', (string)$newId, 'Membuat akun pengguna ' . $username . '.');

        $stmt = $db->prepare($publicUserSelect . " WHERE u.id = :id");
        $stmt->execute([':id' => $newId]);
        jsonResponse(['success' => true, 'user' => $stmt->fetch(), 'message' => 'Akun pengguna berhasil dibuat.'], 201);
    }

    if ($userId < 1) {
        errorResponse('ID pengguna tidak valid.', 400);
    }

    $find = $db->prepare($publicUserSelect . " WHERE u.id = :id");
    $find->execute([':id' => $userId]);
    $targetUser = $find->fetch();
    if (!$targetUser) {
        errorResponse('Pengguna tidak ditemukan.', 404);
    }

    if ($method === 'PUT') {
        if ((int)$targetUser['role_id'] === 1 && (int)$currentUser['role_id'] !== 1) {
            errorResponse('Hanya Superadmin yang boleh mengubah akun Superadmin.', 403);
        }
        if (!is_array($input)) {
            errorResponse('Format data pengguna tidak valid.', 400);
        }

        $username = trim((string)($input['username'] ?? $targetUser['username']));
        $email = trim((string)($input['email'] ?? $targetUser['email']));
        $fullName = trim((string)($input['full_name'] ?? $targetUser['full_name']));
        $password = (string)($input['password'] ?? '');
        $roleId = filter_var($input['role_id'] ?? $targetUser['role_id'], FILTER_VALIDATE_INT);
        $isActive = filter_var($input['is_active'] ?? $targetUser['is_active'], FILTER_VALIDATE_INT);

        if ($username === '' || strlen($username) > 60
            || !preg_match('/^[A-Za-z0-9._-]+$/', $username)) {
            errorResponse('Username tidak valid.', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) {
            errorResponse('Alamat email tidak valid.', 422);
        }
        if ($fullName === '' || strlen($fullName) > 100) {
            errorResponse('Nama lengkap tidak valid.', 422);
        }
        if (!$roleId || !in_array($isActive, [0, 1], true)) {
            errorResponse('Role atau status akun tidak valid.', 422);
        }
        if ($password !== '' && strlen($password) < 12) {
            errorResponse('Password baru harus minimal 12 karakter.', 422);
        }

        if ((int)$currentUser['id'] === $userId && $isActive !== 1) {
            errorResponse('Anda tidak dapat menonaktifkan akun sendiri.', 409);
        }

        $roleStmt = $db->prepare("SELECT id FROM roles WHERE id = :id");
        $roleStmt->execute([':id' => $roleId]);
        if (!$roleStmt->fetchColumn()) {
            errorResponse('Role yang dipilih tidak ditemukan.', 422);
        }

        $dup = $db->prepare("SELECT id, username, email FROM users WHERE (username = :username OR email = :email) AND id <> :id");
        $dup->execute([':username' => $username, ':email' => $email, ':id' => $userId]);
        $existing = $dup->fetch();
        if ($existing) {
            errorResponse(
                $existing['username'] === $username
                    ? 'Username sudah digunakan.'
                    : 'Email sudah digunakan.',
                409
            );
        }

        // Do not allow deactivating/demoting the last active Superadmin.
        $wasActiveSuperadmin = (int)$targetUser['role_id'] === 1 && (int)$targetUser['is_active'] === 1;
        $willRemainActiveSuperadmin = (int)$roleId === 1 && $isActive === 1;
        if ($wasActiveSuperadmin && !$willRemainActiveSuperadmin) {
            $countStmt = $db->query("SELECT COUNT(*) FROM users WHERE role_id = 1 AND is_active = 1");
            if ((int)$countStmt->fetchColumn() <= 1) {
                errorResponse('Akun ini adalah Superadmin aktif terakhir dan tidak dapat diturunkan role atau dinonaktifkan.', 409);
            }
        }

        $sql = "UPDATE users SET username = :username, email = :email, full_name = :full_name, role_id = :role_id, is_active = :is_active, updated_at = NOW()";
        $params = [
            ':username' => $username,
            ':email' => $email,
            ':full_name' => $fullName,
            ':role_id' => $roleId,
            ':is_active' => $isActive,
            ':id' => $userId
        ];
        if ($password !== '') {
            $sql .= ", password_hash = :password_hash";
            $params[':password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        $sql .= " WHERE id = :id";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $audit->log((int)$currentUser['id'], 'UPDATE_USER', 'USER', (string)$userId, 'Memperbarui akun pengguna ' . $username . '.');
        $find = $db->prepare($publicUserSelect . " WHERE u.id = :id");
        $find->execute([':id' => $userId]);
        jsonResponse(['success' => true, 'user' => $find->fetch(), 'message' => 'Akun pengguna berhasil diperbarui.']);
    }

    if ($method === 'DELETE') {
        if ((int)$targetUser['role_id'] === 1 && (int)$currentUser['role_id'] !== 1) {
            errorResponse('Hanya Superadmin yang boleh menonaktifkan akun Superadmin.', 403);
        }
        if ((int)$currentUser['id'] === $userId) {
            errorResponse('Anda tidak dapat menonaktifkan akun sendiri.', 409);
        }

        // Use soft delete: preserve audit references and historical ownership.
        if ((int)$targetUser['role_id'] === 1 && (int)$targetUser['is_active'] === 1) {
            $countStmt = $db->query("SELECT COUNT(*) FROM users WHERE role_id = 1 AND is_active = 1");
            if ((int)$countStmt->fetchColumn() <= 1) {
                errorResponse('Superadmin aktif terakhir tidak dapat dinonaktifkan.', 409);
            }
        }

        $stmt = $db->prepare("UPDATE users SET is_active = 0, updated_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => $userId]);
        $audit->log((int)$currentUser['id'], 'DEACTIVATE_USER', 'USER', (string)$userId, 'Menonaktifkan akun pengguna ' . $targetUser['username'] . '.');

        jsonResponse(['success' => true, 'message' => 'Akun pengguna dinonaktifkan. Data dan riwayat audit tetap disimpan.']);
    }
}

// ROLE LIST API (role editing is a separate permission)
if ($path === '/roles' && $method === 'GET') {
    $canManageUsers = $auth->hasPermission((int)$currentUser['role_id'], 'users.manage');
    $canManageRoles = $auth->hasPermission((int)$currentUser['role_id'], 'roles.manage');
    if (!$canManageUsers && !$canManageRoles) {
        errorResponse('Anda tidak memiliki izin untuk melihat daftar role.', 403);
    }

    $roles = $db->query("
        SELECT r.id, r.name, r.description,
               (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count
        FROM roles r
        ORDER BY r.id ASC
    ")->fetchAll();

    $permissions = $db->query("SELECT id, code, description FROM permissions ORDER BY code ASC")->fetchAll();
    $assignedStmt = $db->query("SELECT role_id, permission_id FROM role_permissions");
    $assigned = [];
    foreach ($assignedStmt->fetchAll() as $item) {
        $assigned[(int)$item['role_id']][] = (int)$item['permission_id'];
    }
    foreach ($roles as &$role) {
        $role['permissions'] = $assigned[(int)$role['id']] ?? [];
    }
    unset($role);

    jsonResponse(['success' => true, 'roles' => $roles, 'permissions' => $permissions]);
}

// POST /roles — create a custom role with selected permissions
if ($path === '/roles' && $method === 'POST') {
    requirePermission($auth, $currentUser, 'roles.manage');
    if (!is_array($input)) {
        errorResponse('Format role tidak valid.', 400);
    }

    $name = trim((string)($input['name'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));
    $permissionIds = $input['permissions'] ?? [];
    if ($name === '' || strlen($name) > 50) {
        errorResponse('Nama role wajib diisi dan maksimal 50 karakter.', 422);
    }
    if (!is_array($permissionIds)) {
        errorResponse('Daftar permission tidak valid.', 422);
    }

    $exists = $db->prepare("SELECT id FROM roles WHERE name = :name");
    $exists->execute([':name' => $name]);
    if ($exists->fetchColumn()) {
        errorResponse('Nama role sudah digunakan.', 409);
    }

    $allIds = array_values(array_unique(array_filter(array_map('intval', $permissionIds), static fn($v) => $v > 0)));
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("INSERT INTO roles (name, description) VALUES (:name, :description)");
        $stmt->execute([':name' => $name, ':description' => $description]);
        $newRoleId = (int)$db->lastInsertId();

        $assign = $db->prepare("INSERT INTO role_permissions (role_id, permission_id) SELECT :role_id, id FROM permissions WHERE id = :permission_id");
        foreach ($allIds as $permissionId) {
            $assign->execute([':role_id' => $newRoleId, ':permission_id' => $permissionId]);
        }
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    $audit->log((int)$currentUser['id'], 'CREATE_ROLE', 'ROLE', (string)$newRoleId, 'Membuat role ' . $name . '.');
    jsonResponse(['success' => true, 'id' => $newRoleId, 'message' => 'Role berhasil dibuat.'], 201);
}

// PUT /roles/{id} — built-in Superadmin role is immutable
if (preg_match('#^/roles/(\d+)$#', $path, $roleMatch) && $method === 'PUT') {
    requirePermission($auth, $currentUser, 'roles.manage');
    $roleId = (int)$roleMatch[1];
    if ($roleId === 1) {
        errorResponse('Role Superadmin bawaan tidak dapat diubah.', 409);
    }
    if (!is_array($input)) {
        errorResponse('Format role tidak valid.', 400);
    }

    $name = trim((string)($input['name'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));
    $permissionIds = $input['permissions'] ?? [];
    if ($name === '' || strlen($name) > 50 || !is_array($permissionIds)) {
        errorResponse('Nama atau permission role tidak valid.', 422);
    }

    $findRole = $db->prepare("SELECT id FROM roles WHERE id = :id");
    $findRole->execute([':id' => $roleId]);
    if (!$findRole->fetchColumn()) {
        errorResponse('Role tidak ditemukan.', 404);
    }

    $dup = $db->prepare("SELECT id FROM roles WHERE name = :name AND id <> :id");
    $dup->execute([':name' => $name, ':id' => $roleId]);
    if ($dup->fetchColumn()) {
        errorResponse('Nama role sudah digunakan.', 409);
    }

    $allIds = array_values(array_unique(array_filter(array_map('intval', $permissionIds), static fn($v) => $v > 0)));
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("UPDATE roles SET name = :name, description = :description WHERE id = :id");
        $stmt->execute([':name' => $name, ':description' => $description, ':id' => $roleId]);
        $db->prepare("DELETE FROM role_permissions WHERE role_id = :id")->execute([':id' => $roleId]);

        $assign = $db->prepare("INSERT INTO role_permissions (role_id, permission_id) SELECT :role_id, id FROM permissions WHERE id = :permission_id");
        foreach ($allIds as $permissionId) {
            $assign->execute([':role_id' => $roleId, ':permission_id' => $permissionId]);
        }
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    $audit->log((int)$currentUser['id'], 'UPDATE_ROLE', 'ROLE', (string)$roleId, 'Memperbarui role ' . $name . '.');
    jsonResponse(['success' => true, 'message' => 'Role berhasil diperbarui.']);
}

// DELETE /roles/{id} — custom roles only and only when unused
if (preg_match('#^/roles/(\d+)$#', $path, $roleMatch) && $method === 'DELETE') {
    requirePermission($auth, $currentUser, 'roles.manage');
    $roleId = (int)$roleMatch[1];
    if ($roleId <= 5) {
        errorResponse('Role bawaan sistem tidak dapat dihapus.', 409);
    }

    $findRole = $db->prepare("SELECT name FROM roles WHERE id = :id");
    $findRole->execute([':id' => $roleId]);
    $roleName = $findRole->fetchColumn();
    if (!$roleName) {
        errorResponse('Role tidak ditemukan.', 404);
    }

    $used = $db->prepare("SELECT COUNT(*) FROM users WHERE role_id = :id");
    $used->execute([':id' => $roleId]);
    if ((int)$used->fetchColumn() > 0) {
        errorResponse('Role masih digunakan oleh akun pengguna dan tidak dapat dihapus.', 409);
    }

    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM role_permissions WHERE role_id = :id")->execute([':id' => $roleId]);
        $db->prepare("DELETE FROM roles WHERE id = :id")->execute([':id' => $roleId]);
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    $audit->log((int)$currentUser['id'], 'DELETE_ROLE', 'ROLE', (string)$roleId, 'Menghapus role ' . $roleName . '.');
    jsonResponse(['success' => true, 'message' => 'Role berhasil dihapus.']);
}

// Default 404
    errorResponse("Endpoint '{$method} {$path}' tidak ditemukan.", 404);

} catch (\Throwable $e) {
    $statusCode = (int)$e->getCode();

    if ($statusCode < 400 || $statusCode > 599) {
        $statusCode = 500;
    }

    errorResponse($e->getMessage(), $statusCode);
}
