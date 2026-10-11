<?php
namespace Transdata\Nms;

class GenieAcsService
{
    private string $nbiUrl;
    private int $timeout;

    public function __construct(string $nbiUrl = 'http://127.0.0.1:7557', int $timeout = 6)
    {
        $this->nbiUrl = rtrim($nbiUrl, '/');
        $this->timeout = $timeout;
    }

    /**
     * Ambil daftar perangkat CPE / ONT dari GenieACS NBI
     */
    public function getDevices(array $projection = []): array
    {
        $fields = !empty($projection) ? implode(',', $projection) : '_id,_lastInform,_registered,_deviceId._Manufacturer,_deviceId._Manufacturer,_deviceId._SerialNumber,VirtualParameters.pppoeUsername,DeviceID.ProductClass,DeviceID.Manufacturer,DeviceID.SerialNumber,InternetGatewayDevice.DeviceInfo.Manufacturer,InternetGatewayDevice.DeviceInfo.ModelName,InternetGatewayDevice.DeviceInfo.SerialNumber,InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress,InternetGatewayDevice.WANDevice.1.WANCommonInterfaceConfig.WANAccessType,Device.Optical.Interface.1.RxPower,Device.Optical.Interface.1.TxPower,InternetGatewayDevice.WANDevice.1.WANOponInterfaceConfig.RxPower,VirtualParameters.RX_power,VirtualParameters.TX_power';

        $url = $this->nbiUrl . '/devices/?projection=' . urlencode($fields);
        $response = $this->httpRequest('GET', $url);

        if (!$response['success']) {
            return [
                'success' => false,
                'error'   => 'Gagal menghubungi GenieACS NBI di ' . $this->nbiUrl . ': ' . $response['error'],
                'devices' => []
            ];
        }

        $rawDevices = json_decode($response['body'], true);
        if (!is_array($rawDevices)) {
            return [
                'success' => false,
                'error'   => 'Format respon GenieACS NBI tidak valid',
                'devices' => []
            ];
        }

        $formatted = [];
        foreach ($rawDevices as $dev) {
            $formatted[] = $this->parseOntRecord($dev);
        }

        return [
            'success'   => true,
            'count'     => count($formatted),
            'nbi_url'   => $this->nbiUrl,
            'devices'   => $formatted,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Parse data mentah GenieACS menjadi format ISP Transdata yang konsisten
     */
    private function parseOntRecord(array $raw): array
    {
        $id = $raw['_id'] ?? 'Unknown';
        $lastInform = $raw['_lastInform'] ?? null;

        // Ambil serial number dari beberapa alternatif parameter TR-069
        $serial = $this->extractParamValue($raw, [
            'InternetGatewayDevice.DeviceInfo.SerialNumber',
            'Device.DeviceInfo.SerialNumber',
            '_deviceId._SerialNumber'
        ]) ?: 'N/A';

        $manufacturer = $this->extractParamValue($raw, [
            'InternetGatewayDevice.DeviceInfo.Manufacturer',
            'Device.DeviceInfo.Manufacturer',
            '_deviceId._Manufacturer'
        ]) ?: 'N/A';

        $model = $this->extractParamValue($raw, [
            'InternetGatewayDevice.DeviceInfo.ModelName',
            'Device.DeviceInfo.ModelName',
            'InternetGatewayDevice.DeviceInfo.ProductClass'
        ]) ?: 'N/A';

        $wanIp = $this->extractParamValue($raw, [
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ExternalIPAddress',
            'Device.IP.Interface.1.IPv4Address.1.IPAddress',
            '_ip'
        ]) ?: 'N/A';

        // Optical RX Power (dBm) - vendor TR-098 / TR-181 differences
	$rxRaw = $this->extractParamValue($raw, [
	    'VirtualParameters.RX_power',
            'InternetGatewayDevice.WANDevice.1.WANOponInterfaceConfig.RxPower',
            'InternetGatewayDevice.WANDevice.1.WANCommonInterfaceConfig.RxPower',
            'Device.Optical.Interface.1.RxPower',
            'InternetGatewayDevice.X_ZTE_COM_PON.RxPower',
            'InternetGatewayDevice.X_HW_PON.RxPower'
        ]);

	$txRaw = $this->extractParamValue($raw, [
	    'VirtualParameters.TX_power',
            'InternetGatewayDevice.WANDevice.1.WANOponInterfaceConfig.TxPower',
            'InternetGatewayDevice.WANDevice.1.WANCommonInterfaceConfig.TxPower',
            'Device.Optical.Interface.1.TxPower',
            'InternetGatewayDevice.X_ZTE_COM_PON.TxPower',
            'InternetGatewayDevice.X_HW_PON.TxPower'
        ]);

        // Hitung status aktif berdasarkan lastInform (jika kurang dari 15 menit yang lalu)
        $isOnline = false;
        if ($lastInform) {
            $informTs = strtotime($lastInform);
            if ($informTs && (time() - $informTs) < 900) {
                $isOnline = true;
            }
        }

        return [
            'device_id'     => $id,
            'serial_number' => $serial,
            'manufacturer'  => $manufacturer,
            'model'         => $model,
            'wan_ip'        => $wanIp,
            'optical_rx'    => $rxRaw !== null ? (string)$rxRaw : 'N/A',
            'optical_tx'    => $txRaw !== null ? (string)$txRaw : 'N/A',
            'last_inform'   => $lastInform ?: 'Belum pernah',
            'is_online'     => $isOnline
        ];
    }

    private function extractParamValue(array $source, array $keys): ?string
    {
        foreach ($keys as $key) {
            $parts = explode('.', $key);
            $curr = $source;
            $found = true;
            foreach ($parts as $p) {
                if (isset($curr[$p])) {
                    $curr = $curr[$p];
                } else {
                    $found = false;
                    break;
                }
            }
            if ($found) {
                if (is_array($curr) && isset($curr['_value'])) {
                    return (string)$curr['_value'];
                }
                if (is_scalar($curr)) {
                    return (string)$curr;
                }
            }
        }
        return null;
    }

    /**
     * Jalankan task GenieACS aktual (Refresh Object atau Reboot)
     */
    public function createTask(string $deviceId, string $taskName, array $args = []): array
    {
        $url = sprintf('%s/devices/%s/tasks?timeout=%d', $this->nbiUrl, urlencode($deviceId), $this->timeout * 1000);
        $payload = ['name' => $taskName];
        if (!empty($args)) {
            $payload = array_merge($payload, $args);
        }

        $res = $this->httpRequest('POST', $url, json_encode($payload));
        if (!$res['success']) {
            return [
                'success' => false,
                'error'   => 'GenieACS Task Error: ' . $res['error']
            ];
        }

        return [
            'success'   => true,
            'device_id' => $deviceId,
            'task'      => $taskName,
            'status'    => 'QUEUED',
            'response'  => json_decode($res['body'], true)
        ];
    }

    /**
     * Ambil data telemetri komprehensif ONT real-time dari GenieACS berdasarkan Serial Number atau Device ID
     */
    public function getOntRealtimeTelemetry(string $snOrId): array
    {
        $queryObj = [
            '$or' => [
                ['VirtualParameters.getsn' => $snOrId],
                ['InternetGatewayDevice.DeviceInfo.SerialNumber' => $snOrId],
                ['Device.DeviceInfo.SerialNumber' => $snOrId],
                ['_deviceId._SerialNumber' => $snOrId],
                ['_id' => $snOrId]
            ]
        ];

        $url = $this->nbiUrl . '/devices/?query=' . urlencode(json_encode($queryObj));
        $res = $this->httpRequest('GET', $url);

        if (!$res['success']) {
            return [
                'success' => false,
                'error' => 'Koneksi ke GenieACS NBI gagal: ' . $res['error']
            ];
        }

        $items = json_decode($res['body'], true);
        if (!is_array($items) || empty($items) || !is_array($items[0] ?? null)) {
            return [
                'success' => false,
                'error' => "ONT dengan Serial Number / ID '$snOrId' belum terdaftar di GenieACS."
            ];
        }

        $raw = $items[0];

        $serial = $this->extractParamValue($raw, [
            'VirtualParameters.getsn',
            'InternetGatewayDevice.DeviceInfo.SerialNumber',
            'Device.DeviceInfo.SerialNumber',
            '_deviceId._SerialNumber'
        ]);

        $manufacturer = $this->extractParamValue($raw, [
            'InternetGatewayDevice.DeviceInfo.Manufacturer',
            'Device.DeviceInfo.Manufacturer',
            '_deviceId._Manufacturer'
        ]);

        $model = $this->extractParamValue($raw, [
            'InternetGatewayDevice.DeviceInfo.ModelName',
            'Device.DeviceInfo.ModelName',
            'InternetGatewayDevice.DeviceInfo.ProductClass',
            'Device.DeviceInfo.ProductClass'
        ]);

        $hardwareVersion = $this->extractParamValue($raw, [
            'InternetGatewayDevice.DeviceInfo.HardwareVersion',
            'Device.DeviceInfo.HardwareVersion'
        ]);

        $softwareVersion = $this->extractParamValue($raw, [
            'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
            'Device.DeviceInfo.SoftwareVersion'
        ]);

        $wanIp = $this->extractParamValue($raw, [
            'VirtualParameters.ip_pppoe',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ExternalIPAddress',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress',
            'Device.IP.Interface.1.IPv4Address.1.IPAddress',
            '_ip'
        ]);

        $mac = $this->extractParamValue($raw, [
            'InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.1.MACAddress',
            'Device.Ethernet.Interface.1.MACAddress'
        ]);

        $rxPower = $this->extractParamValue($raw, [
            'VirtualParameters.RX_power',
            'InternetGatewayDevice.WANDevice.1.WANOponInterfaceConfig.RxPower',
            'InternetGatewayDevice.WANDevice.1.WANCommonInterfaceConfig.RxPower',
            'Device.Optical.Interface.1.RxPower',
            'InternetGatewayDevice.X_ZTE_COM_PON.RxPower'
        ]);

        $txPower = $this->extractParamValue($raw, [
            'VirtualParameters.TX_power',
            'InternetGatewayDevice.WANDevice.1.WANOponInterfaceConfig.TxPower',
            'InternetGatewayDevice.WANDevice.1.WANCommonInterfaceConfig.TxPower',
            'Device.Optical.Interface.1.TxPower',
            'InternetGatewayDevice.X_ZTE_COM_PON.TxPower'
        ]);

        $temperature = $this->extractParamValue($raw, [
            'VirtualParameters.temperatur',
            'Device.DeviceInfo.TemperatureStatus.TemperatureSensor.1.Value',
            'InternetGatewayDevice.DeviceInfo.Temperature',
            'InternetGatewayDevice.X_ZTE_COM_PON.Temperature'
        ]);

        $voltage = $this->extractParamValue($raw, [
            'InternetGatewayDevice.WANDevice.1.WANOponInterfaceConfig.SupplyVoltage',
            'Device.Optical.Interface.1.Voltage',
            'InternetGatewayDevice.X_ZTE_COM_PON.Voltage'
        ]);

        $wifiSsid = $this->extractParamValue($raw, [
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
            'Device.WiFi.SSID.1.SSID'
        ]);

        $clientCount = $this->extractParamValue($raw, [
            'VirtualParameters.activedevices',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.TotalAssociations',
            'Device.WiFi.AccessPoint.1.AssociatedDeviceNumberOfEntries'
        ]);

        // Uptime sejak ONT terakhir menyala ulang, dalam detik.
        $uptimeOnt = $this->extractParamValue($raw, [
            'Device.DeviceInfo.UpTime',
            'InternetGatewayDevice.DeviceInfo.UpTime'
        ]);

        return [
            'success' => true,
            'serial_number' => $serial ?? 'N/A',
            'model' => $model ?? 'N/A',
            'vendor' => $manufacturer ?? 'N/A',
            'hardware_version' => $hardwareVersion ?? 'N/A',
            'software_version' => $softwareVersion ?? 'N/A',
            'power_rx_dbm' => $rxPower !== null && is_numeric($rxPower) ? (float)$rxPower : 'N/A',
            'power_tx_dbm' => $txPower !== null && is_numeric($txPower) ? (float)$txPower : 'N/A',
            'temperature_c' => $temperature !== null && is_numeric($temperature) ? (float)$temperature : 'N/A',
            'voltage_v' => $voltage !== null && is_numeric($voltage) ? (float)$voltage : 'N/A',
            'uptime_ont' => $uptimeOnt !== null && is_numeric($uptimeOnt)
                ? (int)$uptimeOnt
                : null,
            'wifi_ssid' => $wifiSsid ?? 'N/A',
            'wifi_active_clients' => $clientCount !== null && is_numeric($clientCount) ? (int)$clientCount : 'N/A',
            'ip_address' => $wanIp ?? 'N/A',
            'mac_address' => $mac ?? 'N/A',
            'last_inform' => $raw['_lastInform'] ?? null,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }

    private function httpRequest(string $method, string $url, ?string $body = null): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [
                'success'   => false,
                'http_code' => $httpCode,
                'error'     => $err ?: 'Network timeout / Host unreachable'
            ];
        }

        return [
            'success'   => ($httpCode >= 200 && $httpCode < 300),
            'http_code' => $httpCode,
            'body'      => $response,
            'error'     => $httpCode >= 400 ? "HTTP $httpCode: $response" : null
        ];
    }
}
