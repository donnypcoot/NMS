<?php
/**
 * Transdata NMS - Background Polling Daemon & Retention Worker
 * Jalankan via CLI atau systemd daemon: php /var/www/transdata-nms/backend/cli/poller.php
 */

declare(strict_types=1);

namespace Transdata\Nms;

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/PingService.php';
require_once __DIR__ . '/../src/SnmpService.php';
require_once __DIR__ . '/../src/CryptoService.php';

$configFile = __DIR__ . '/../config/config.php';
if (!file_exists($configFile)) {
    fwrite(STDERR, "ERROR: Buat backend/config/config.php dari config.example.php terlebih dahulu.\n");
    exit(1);
}
$config = require $configFile;

$jwtSecret = $config['app']['jwt_secret'] ?? '';
$encryptionKey = $config['app']['encryption_key'] ?? '';
if (!is_string($jwtSecret) || strlen($jwtSecret) < 32 ||
    !is_string($encryptionKey) || strlen($encryptionKey) < 32) {
    fwrite(STDERR, "ERROR: JWT secret atau encryption key belum dikonfigurasi dengan aman.\n");
    exit(1);
}
Database::setConfig($config);


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

echo "[" . date('Y-m-d H:i:s') . "] Memulai Transdata NMS Poller Daemon...\n";

try {
    $db = Database::getConnection();
    $pingSvc = new PingService($config['ping']['binary_path'] ?? '/bin/ping');
    $snmpSvc = new SnmpService($config['snmp']['binary_path'] ?? '/usr/bin/snmpget', $config['snmp']['walk_binary'] ?? '/usr/bin/snmpwalk');

    // 1. Ambil perangkat yang aktif dimonitor
    $stmt = $db->query("SELECT * FROM devices WHERE monitoring_enabled = 1");
    $devices = $stmt->fetchAll();

    echo sprintf("[%s] Menemukan %d perangkat untuk dipolling.\n", date('Y-m-d H:i:s'), count($devices));

    foreach ($devices as $dev) {
        $devId = (int)$dev['id'];
        $ip = $dev['ip_address'];
        echo "Polling {$dev['name']} ($ip)... ";

        // 1. ICMP Ping nyata
        $ping = $pingSvc->executePing($ip, 3);
        $pingStatus = $ping['status'];

        $up = $db->prepare("
            UPDATE devices SET
                ping_status = :p_status,
                ping_latency_ms = :latency,
                ping_packet_loss_pct = :loss,
                last_ping_time = NOW(),
                last_ping_output = :p_out
            WHERE id = :id
        ");
        $up->execute([
            ':p_status' => $pingStatus,
            ':latency'  => $ping['latency_ms'],
            ':loss'     => $ping['packet_loss'],
            ':p_out'    => $ping['output'],
            ':id'       => $devId
        ]);

        // 2. Jika ICMP online dan Net-SNMP ada, lakukan query SNMP
        if ($pingStatus === 'ONLINE' && $snmpSvc->isNetSnmpInstalled()) {
            $crypto = new CryptoService($config['app']['encryption_key']);
            $community = resolveSnmpCommunity($dev['snmp_community_encrypted'] ?? null, $crypto);
            $ver = $dev['snmp_version'] ?: 'v2c';
            $snmpRes = $snmpSvc->querySystemInfo($ip, $community, $ver);

            $snmpUp = $db->prepare("
                UPDATE devices SET
                    snmp_status = :s_status,
                    snmp_uptime = :uptime,
                    snmp_descr = :descr,
                    vendor = CASE WHEN vendor = 'Tidak tersedia' THEN :vendor ELSE vendor END,
                    model = CASE WHEN model = 'Tidak tersedia' THEN :model ELSE model END,
                    last_snmp_time = NOW(),
                    last_snmp_error = :err
                WHERE id = :id
            ");
            $snmpUp->execute([
                ':s_status' => $snmpRes['status'],
                ':uptime'   => $snmpRes['sys_uptime'] ?? null,
                ':descr'    => $snmpRes['sys_descr'] ?? null,
                ':vendor'   => $snmpRes['vendor'] ?? 'Tidak tersedia',
                ':model'    => $snmpRes['model'] ?? 'Tidak tersedia',
                ':err'      => $snmpRes['error'] ?? null,
                ':id'       => $devId
            ]);
        }

        echo "Selesai (Ping: $pingStatus)\n";
    }

    // 3. Kebijakan Retensi Histori Trafik (Hapus data lebih tua dari retention days)
    $retentionDays = 30;
    $db->exec("DELETE FROM interface_traffic_samples WHERE sample_time < DATE_SUB(NOW(), INTERVAL " . (int)$retentionDays . " DAY)");

    echo "[" . date('Y-m-d H:i:s') . "] Siklus polling dan pembersihan retensi selesai.\n";

} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
