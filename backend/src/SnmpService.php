<?php
namespace Transdata\Nms;

class SnmpService
{
    private string $getBinary;
    private string $walkBinary;

    public function __construct(string $getBinary = '/usr/bin/snmpget', string $walkBinary = '/usr/bin/snmpwalk')
    {
        $this->getBinary = is_executable($getBinary) ? $getBinary : (is_executable('/usr/bin/snmpget') ? '/usr/bin/snmpget' : 'snmpget');
        $this->walkBinary = is_executable($walkBinary) ? $walkBinary : (is_executable('/usr/bin/snmpwalk') ? '/usr/bin/snmpwalk' : 'snmpwalk');
    }

    public function isNetSnmpInstalled(): bool
    {
        exec('which snmpget 2>&1', $out, $code);
        return $code === 0;
    }

    public function querySystemInfo(string $ip, string $community = 'public', string $version = 'v2c', int $timeout = 3, int $retries = 2): array
    {
        if (!$this->isNetSnmpInstalled()) {
            return [
                'success' => false,
                'status'  => 'ERROR',
                'error'   => 'Net-SNMP binary (snmpget) belum terinstal di server. Jalankan: sudo apt-get install -y snmp snmp-mibs-downloader'
            ];
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['success' => false, 'status' => 'ERROR', 'error' => 'Alamat IP tidak valid.'];
        }

        $verFlag = ($version === 'v1') ? '-v 1' : '-v 2c';

        // Query sysDescr (1.3.6.1.2.1.1.1.0) and sysUpTime (1.3.6.1.2.1.1.3.0)
        $cmd = sprintf(
            '%s %s -c %s -t %d -r %d -Oqv %s 1.3.6.1.2.1.1.1.0 1.3.6.1.2.1.1.3.0 2>&1',
            escapeshellcmd($this->getBinary),
            $verFlag,
            escapeshellarg($community),
            $timeout,
            $retries,
            escapeshellarg($ip)
        );

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || empty($output)) {
            $err = implode(' ', $output);
            return [
                'success' => false,
                'status'  => 'OFFLINE',
                'error'   => !empty($err) ? $err : 'SNMP Request Timeout / No Response dari host ' . $ip
            ];
        }

        $sysDescr = trim($output[0] ?? 'Tidak tersedia');
        $sysUpTime = trim($output[1] ?? 'Tidak tersedia');

        // Extract vendor/model jika ada dalam sysDescr
        $vendor = 'Tidak tersedia';
        $model = 'Tidak tersedia';

        if (stripos($sysDescr, 'MikroTik') !== false || stripos($sysDescr, 'RouterOS') !== false) {
            $vendor = 'MikroTik';
            if (preg_match('/RouterOS\s+([v0-9.]+)/i', $sysDescr, $m)) {
                $model = 'RouterOS ' . $m[1];
            }
        } elseif (stripos($sysDescr, 'Cisco') !== false) {
            $vendor = 'Cisco';
        } elseif (stripos($sysDescr, 'ZTE') !== false) {
            $vendor = 'ZTE';
        } elseif (stripos($sysDescr, 'Huawei') !== false) {
            $vendor = 'Huawei';
        } elseif (stripos($sysDescr, 'Linux') !== false) {
            $vendor = 'Linux Server';
        }

        return [
            'success'     => true,
            'status'      => 'ONLINE',
            'sys_descr'   => $sysDescr,
            'sys_uptime'  => $sysUpTime,
            'vendor'      => $vendor,
            'model'       => $model,
            'last_snmp'   => date('Y-m-d H:i:s')
        ];
    }

    public function walkInterfaces(string $ip, string $community = 'public', string $version = 'v2c', int $timeout = 4, int $retries = 2): array
    {
        if (!$this->isNetSnmpInstalled()) {
            return [
                'success' => false,
                'error'   => 'Net-SNMP binary (snmpwalk) belum terinstal di server.'
            ];
        }

        $verFlag = ($version === 'v1') ? '-v 1' : '-v 2c';

        // 1. Walk ifDescr (1.3.6.1.2.1.2.2.1.2)
        $ifDescrs = $this->snmpWalkOidTable($ip, $community, $verFlag, '1.3.6.1.2.1.2.2.1.2', $timeout, $retries);
        // 2. Walk ifName (1.3.6.1.2.1.31.1.1.1.1)
        $ifNames = $this->snmpWalkOidTable($ip, $community, $verFlag, '1.3.6.1.2.1.31.1.1.1.1', $timeout, $retries);
        // 3. Walk ifOperStatus (1.3.6.1.2.1.2.2.1.8)
        $ifStatuses = $this->snmpWalkOidTable($ip, $community, $verFlag, '1.3.6.1.2.1.2.2.1.8', $timeout, $retries);
        // 4. Walk ifHCInOctets (1.3.6.1.2.1.31.1.1.1.6)
        $inOctets = $this->snmpWalkOidTable($ip, $community, $verFlag, '1.3.6.1.2.1.31.1.1.1.6', $timeout, $retries);
        // 5. Walk ifHCOutOctets (1.3.6.1.2.1.31.1.1.1.10)
        $outOctets = $this->snmpWalkOidTable($ip, $community, $verFlag, '1.3.6.1.2.1.31.1.1.1.10', $timeout, $retries);

        $interfaces = [];
        $indices = array_unique(array_merge(
            array_keys($ifDescrs),
            array_keys($ifNames),
            array_keys($ifStatuses)
        ));
        sort($indices, SORT_NUMERIC);

        foreach ($indices as $idx) {
            $name = $ifNames[$idx] ?? ($ifDescrs[$idx] ?? 'ifIndex_' . $idx);
            $descr = $ifDescrs[$idx] ?? $name;
            $rawStatus = (int)($ifStatuses[$idx] ?? 0);

            // Standard ifOperStatus RFC 2863: 1=up, 2=down, 3=testing, 4=unknown, 5=dormant, 6=notPresent, 7=lowerLayerDown
            $operStatus = match ($rawStatus) {
                1 => 'up',
                2 => 'down',
                3 => 'testing',
                5 => 'dormant',
                6 => 'notPresent',
                7 => 'lowerLayerDown',
                default => 'unknown'
            };

            $in = isset($inOctets[$idx]) ? (float)$inOctets[$idx] : null;
            $out = isset($outOctets[$idx]) ? (float)$outOctets[$idx] : null;

            $interfaces[] = [
                'if_index'       => (int)$idx,
                'if_name'        => trim($name, '"'),
                'if_descr'       => trim($descr, '"'),
                'if_oper_status' => $operStatus,
                'in_octets'      => $in,
                'out_octets'     => $out,
                'sample_time'    => date('Y-m-d H:i:s')
            ];
        }

        return [
            'success'    => true,
            'count'      => count($interfaces),
            'interfaces' => $interfaces
        ];
    }

    private function snmpWalkOidTable(string $ip, string $community, string $verFlag, string $oid, int $timeout, int $retries): array
    {
        $cmd = sprintf(
            '%s %s -c %s -t %d -r %d -Oqn %s %s 2>/dev/null',
            escapeshellcmd($this->walkBinary),
            $verFlag,
            escapeshellarg($community),
            $timeout,
            $retries,
            escapeshellarg($ip),
            escapeshellarg($oid)
        );

        $lines = [];
        exec($cmd, $lines);
        $result = [];

        foreach ($lines as $line) {
            // Line format: .1.3.6.1.2.1.2.2.1.2.1 = STRING: ether1
            // or: .1.3.6.1.2.1.2.2.1.2.1 ether1
            $parts = preg_split('/\s+/', trim($line), 2);
            if (count($parts) >= 2) {
                $rawOid = $parts[0];
                $val = trim($parts[1]);

                // Get last number as ifIndex
                $subOids = explode('.', $rawOid);
                $index = end($subOids);

                if (is_numeric($index)) {
                    // Strip types like "STRING: ", "INTEGER: ", "Counter64: "
                    if (preg_match('/^[A-Za-z0-9_-]+:\s*(.+)$/', $val, $m)) {
                        $val = $m[1];
                    }
                    $result[(int)$index] = $val;
                }
            }
        }

        return $result;
    }

    /**
     * Hitung laju trafik bit per detik (bps) secara akurat.
     * rate bps = (selisih octets * 8) / selang detik
     * Memperhitungkan counter reset, reboot, counter wrap 64-bit, dan sampel pertama.
     */
    public static function calculateRateBps(?float $prevOctets, ?float $currOctets, ?int $prevTimeSec, ?int $currTimeSec): ?int
    {
        if ($prevOctets === null || $currOctets === null || $prevTimeSec === null || $currTimeSec === null) {
            return null; // Sampel pertama: belum ada pembanding
        }

        $deltaTime = $currTimeSec - $prevTimeSec;
        if ($deltaTime <= 0) {
            return null;
        }

        $deltaOctets = $currOctets - $prevOctets;

        // Counter reset / reboot perangkat
        if ($deltaOctets < 0) {
            // Jika 64-bit wrap (jarang terjadi dalam selang pendek, kecuali counter reset)
            $wrap64 = 18446744073709551615; // 2^64 - 1
            if ($deltaOctets < -1000000000) {
                // Device restart detected, reset sample
                return 0;
            }
            $deltaOctets += $wrap64;
        }

        $rateBps = ($deltaOctets * 8) / $deltaTime;
        return max(0, (int)round($rateBps));
    }

    /**
     * Query status interface PON pada OLT ZTE via IF-MIB.
     * Catatan: status interface PON bukan status individual ONT.
     */
    /**
     * Query status individual ONT dari tabel ZTE C320.
     * Mempertahankan seluruh suffix OID karena index ONT terdiri dari beberapa bagian.
     */
    public function queryZteOltOntStatus(string $oltIp, string $community = 'public', string $snOrPon = ''): array
    {
        $base = [
            'success' => false,
            'olt_status' => 'unknown',
            'pon_interface' => null,
            'raw_state' => null,
        ];

        if (!filter_var($oltIp, FILTER_VALIDATE_IP)) {
            return $base + ['message' => 'IP OLT tidak valid.'];
        }

        if (!$this->isNetSnmpInstalled()) {
            return $base + ['message' => 'Net-SNMP tidak tersedia di server.'];
        }

        $target = strtoupper(trim($snOrPon));
        if ($target === '') {
            return $base + ['message' => 'Serial ONT tidak diberikan.'];
        }

        $serialOid = '1.3.6.1.4.1.3902.1012.3.28.1.1.5';
        $nameOid   = '1.3.6.1.4.1.3902.1012.3.28.1.1.2';
        $phaseOid  = '1.3.6.1.4.1.3902.1012.3.28.2.1.4';

        $serials = $this->snmpWalkZteTable($oltIp, $community, $serialOid);
        $names   = $this->snmpWalkZteTable($oltIp, $community, $nameOid);
        $phases  = $this->snmpWalkZteTable($oltIp, $community, $phaseOid);

        if (!$serials || !$phases) {
            return $base + ['message' => 'Tabel serial atau Phase State tidak terbaca dari OLT.'];
        }

        $matches = [];
        foreach ($serials as $index => $serial) {
            if (strtoupper(trim($serial)) !== $target) {
                continue;
            }

            $phase = $phases[$index] ?? null;
            $matches[] = [
                'index' => $index,
                'name' => $names[$index] ?? null,
                'phase' => is_numeric($phase) ? (int)$phase : null,
            ];
        }

        if (!$matches) {
            return $base + ['message' => 'Serial ONT tidak ditemukan pada tabel SNMP ZTE.'];
        }

        $stateMap = [
            1 => 'LOS',
            3 => 'working',
            4 => 'DyingGasp',
            6 => 'OffLine',
        ];

        // Serial yang sama dapat muncul pada beberapa index. Jangan menebak
        // apabila hasilnya menunjukkan status yang saling bertentangan.
        $states = [];
        foreach ($matches as $match) {
            $state = $stateMap[$match['phase'] ?? -1] ?? 'unknown';
            $states[$state] = true;
        }

        if (count($states) > 1) {
            return $base + [
                'message' => 'Serial ditemukan pada beberapa index dengan status berbeda; hasil perlu dicocokkan dengan interface PON.',
                'matches' => $matches,
            ];
        }

        $match = $matches[0];
        $state = $stateMap[$match['phase'] ?? -1] ?? 'unknown';

        // Jangan menurunkan interface fisik dari suffix tabel vendor.
        // Indeks tabel ONT tidak terbukti sama dengan indeks IF-MIB.
        // Biarkan null sampai pemetaan port GPON yang valid tersedia.
        $ponInterface = null;

        return [
            'success' => $state !== 'unknown',
            'olt_status' => $state,
            'pon_interface' => $ponInterface,
            'ont_name' => $match['name'],
            'raw_state' => $match['phase'],
            'message' => count($matches) > 1
                ? 'Beberapa index ditemukan dengan status sama; hasil menggunakan baris pertama.'
                : 'Status ONT dan interface diambil dari tabel SNMP ZTE.',
            'matches' => $matches,
        ];
    }

    /**
     * Walk tabel vendor ZTE dengan suffix OID penuh sebagai key.
     */
    private function snmpWalkZteTable(string $ip, string $community, string $oid): array
    {
        $cmd = sprintf(
            '%s -v 2c -c %s -t 4 -r 1 -On %s %s 2>/dev/null',
            escapeshellcmd($this->walkBinary),
            escapeshellarg($community),
            escapeshellarg($ip),
            escapeshellarg($oid)
        );

        $lines = [];
        $exitCode = 0;
        exec($cmd, $lines, $exitCode);

        $result = [];
        $prefix = '.' . ltrim($oid, '.');

        foreach ($lines as $line) {
            if (!preg_match('/^(\.[0-9.]+)\s*=\s*(.*)$/', trim($line), $m)) {
                continue;
            }

            $fullOid = $m[1];
            if (strpos($fullOid, $prefix . '.') !== 0) {
                continue;
            }

            $index = substr($fullOid, strlen($prefix) + 1);
            if ($index === '' || !preg_match('/^\d+(?:\.\d+)*$/', $index)) {
                continue;
            }

            $raw = trim($m[2]);
            if (stripos($raw, 'Hex-STRING:') === 0) {
                preg_match_all('/\b[0-9A-Fa-f]{2}\b/', substr($raw, strlen('Hex-STRING:')), $hex);
                $bytes = strtoupper(implode('', $hex[0]));

                // Serial ONT ZTE: 4 byte vendor ASCII diikuti 4 byte heksadesimal.
                if (ltrim($oid, '.') === '1.3.6.1.4.1.3902.1012.3.28.1.1.5') {
                    $decodedPrefix = hex2bin(substr($bytes, 0, 8));
                    $value = ($decodedPrefix !== false ? $decodedPrefix : '') . substr($bytes, 8);
                } else {
                    $decoded = $bytes !== '' ? hex2bin($bytes) : '';
                    $value = $decoded === false ? '' : trim($decoded, "\0 \t\n\r\"");
                }

                $result[$index] = $value;
            } elseif (preg_match('/^(?:STRING|INTEGER|Gauge32|Counter32|Counter64|Timeticks|OID):\s*(.*)$/i', $raw, $v)) {
                $value = trim($v[1]);
                if (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') {
                    $value = substr($value, 1, -1);
                }
                $result[$index] = $value;
            } else {
                $result[$index] = trim($raw, "\" ");
            }
        }

        return $result;
    }

}
