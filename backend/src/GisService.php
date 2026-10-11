<?php
namespace Transdata\Nms;

use PDO;

class GisService
{
    private PDO $db;
        /**
     * Hitung ulang jumlah port yang digunakan berdasarkan kabel ACTIVE.
     * Setiap kabel ACTIVE yang terhubung ke object menggunakan 1 port.
     */
    private function recalculateObjectPortUsage(string $objectId): void
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM ftth_cables
            WHERE status = 'ACTIVE'
              AND (
                    start_object_id = :id_start
                    OR end_object_id = :id_end
              )
        ");

        $stmt->execute([
            ':id_start' => $objectId,
            ':id_end'   => $objectId
        ]);

        $used = (int)$stmt->fetchColumn();

        $update = $this->db->prepare("
            UPDATE ftth_objects
            SET ports_used = :ports_used
            WHERE id = :id
        ");

        $update->execute([
            ':ports_used' => $used,
            ':id' => $objectId
        ]);
    }

    /**
     * Hitung ulang port untuk beberapa object sekaligus.
     */
    private function recalculateObjectPorts(array $objectIds): void
    {
        $uniqueIds = array_values(array_unique(array_filter($objectIds)));

        foreach ($uniqueIds as $objectId) {
            $this->recalculateObjectPortUsage((string)$objectId);
        }
    }

    /**
     * Tambahkan informasi kapasitas port ke object.
     */
    private function enrichObjectPortInfo(array $object): array
    {
        $capacity = (int)($object['port_capacity'] ?? 0);
        $used = (int)($object['ports_used'] ?? 0);

        $available = max(0, $capacity - $used);

        if ($capacity <= 0) {
            $portStatus = 'NO_CAPACITY';
        } elseif ($used >= $capacity) {
            $portStatus = 'FULL';
        } elseif ($used > 0) {
            $portStatus = 'USED';
        } else {
            $portStatus = 'AVAILABLE';
        }

        $object['port_capacity'] = $capacity;
        $object['ports_used'] = $used;
        $object['ports_available'] = $available;
        $object['port_status'] = $portStatus;

        return $object;
    }
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function getAllObjects(array $filters = []): array
    {
        $sql = "SELECT o.*, p.name as parent_name FROM ftth_objects o LEFT JOIN ftth_objects p ON o.parent_object_id = p.id WHERE 1=1";
        $params = [];

        if (!empty($filters['object_type'])) {
            $sql .= " AND o.object_type = :type";
            $params[':type'] = $filters['object_type'];
        }

        if (!empty($filters['status'])) {
            $sql .= " AND o.status = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (o.name LIKE :q1 OR o.code LIKE :q2 OR o.address LIKE :q3)";
            $searchVal = '%' . $filters['search'] . '%';
            $params[':q1'] = $searchVal;
            $params[':q2'] = $searchVal;
            $params[':q3'] = $searchVal;
        }

        $sql .= " ORDER BY o.created_at ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $objects = $stmt->fetchAll();

        // Include splitters for each object
        foreach ($objects as &$obj) {
            $spStmt = $this->db->prepare("SELECT * FROM ftth_splitters WHERE parent_object_id = :id");
            $spStmt->execute([':id' => $obj['id']]);
	    $obj['splitters'] = $spStmt->fetchAll();
	    $obj = $this->enrichObjectPortInfo($obj);
        }

        return $objects;
    }

public function saveObject(array $data): array
{
    $id = $data['id'] ?? $this->generateUuid();
    $isUpdate = !empty($data['id']);

    // Jika update, pastikan object memang ada.
    if ($isUpdate) {
        $check = $this->db->prepare("
            SELECT id
            FROM ftth_objects
            WHERE id = :id
        ");
        $check->execute([':id' => $id]);

        if (!$check->fetch()) {
            throw new \RuntimeException(
                'Objek FTTH yang akan diedit tidak ditemukan.',
                404
            );
        }
    }

    $latitude = (float)$data['latitude'];
    $longitude = (float)$data['longitude'];

    if ($isUpdate) {
        $sql = "
            UPDATE ftth_objects SET
                object_type = :object_type,
                code = :code,
                serial_number = :serial_number,
                name = :name,
                latitude = :latitude,
                longitude = :longitude,
                status = :status,
                address = :address,
                port_capacity = :port_capacity,
                parent_object_id = :parent_object_id,
                notes = :notes
            WHERE id = :id
        ";
    } else {
        $sql = "
            INSERT INTO ftth_objects
            (
                id,
                object_type,
                code,
                serial_number,
                name,
                latitude,
                longitude,
                status,
                address,
                port_capacity,
                ports_used,
                parent_object_id,
                notes
            )
            VALUES
            (
                :id,
                :object_type,
                :code,
                :serial_number,
                :name,
                :latitude,
                :longitude,
                :status,
                :address,
                :port_capacity,
                0,
                :parent_object_id,
                :notes
            )
        ";
    }

    $cmd = $this->db->prepare($sql);

    $cmd->execute([
        ':id'               => $id,
        ':object_type'      => $data['object_type'],
        ':code'             => $data['code'],
        ':serial_number'    => (($data['object_type'] ?? '') === 'PELANGGAN'
            && trim((string)($data['serial_number'] ?? '')) !== '')
            ? trim((string)$data['serial_number'])
            : null,
        ':name'             => $data['name'],
        ':latitude'         => $latitude,
        ':longitude'        => $longitude,
        ':status'           => $data['status'] ?? 'ACTIVE',
        ':address'          => $data['address'] ?? null,
        ':port_capacity'    => max(0, (int)($data['port_capacity'] ?? 0)),
        ':parent_object_id' => !empty($data['parent_object_id'])
            ? $data['parent_object_id']
            : null,
        ':notes'            => $data['notes'] ?? null
    ]);

    /*
     * Jika posisi object berubah, sesuaikan endpoint
     * seluruh kabel yang terhubung dengan object ini.
     */
    if ($isUpdate) {
        $cableStmt = $this->db->prepare("
            SELECT id, start_object_id, end_object_id
            FROM ftth_cables
            WHERE start_object_id = :start_id
               OR end_object_id = :end_id
        ");

        $cableStmt->execute([
            ':start_id' => $id,
            ':end_id'   => $id
        ]);

        $connectedCables = $cableStmt->fetchAll();

        foreach ($connectedCables as $cable) {

            // Jika object adalah titik awal kabel,
            // vertex pertama harus mengikuti posisi object.
            if ($cable['start_object_id'] === $id) {
                $updateStart = $this->db->prepare("
                    UPDATE ftth_cable_vertices
                    SET latitude = :lat,
                        longitude = :lng
                    WHERE cable_id = :cable_id
                      AND vertex_order = 1
                ");

                $updateStart->execute([
                    ':lat'      => $latitude,
                    ':lng'      => $longitude,
                    ':cable_id' => $cable['id']
                ]);
            }

            // Jika object adalah titik akhir kabel,
            // vertex terakhir harus mengikuti posisi object.
            if ($cable['end_object_id'] === $id) {
                $lastVertexStmt = $this->db->prepare("
                    SELECT MAX(vertex_order)
                    FROM ftth_cable_vertices
                    WHERE cable_id = :cable_id
                ");

                $lastVertexStmt->execute([
                    ':cable_id' => $cable['id']
                ]);

                $lastOrder = (int)$lastVertexStmt->fetchColumn();

                if ($lastOrder > 0) {
                    $updateEnd = $this->db->prepare("
                        UPDATE ftth_cable_vertices
                        SET latitude = :lat,
                            longitude = :lng
                        WHERE cable_id = :cable_id
                          AND vertex_order = :vertex_order
                    ");

                    $updateEnd->execute([
                        ':lat'         => $latitude,
                        ':lng'         => $longitude,
                        ':cable_id'    => $cable['id'],
                        ':vertex_order'=> $lastOrder
                    ]);
                }
            }
        }
    }

    // Selalu hitung ulang penggunaan port dari kabel ACTIVE.
    $this->recalculateObjectPortUsage($id);

    return $this->getObjectById($id);
}


public function deleteObject(string $id): bool
{
    // Pastikan object ada.
    $objectStmt = $this->db->prepare("
        SELECT id, code
        FROM ftth_objects
        WHERE id = :id
    ");
    $objectStmt->execute([':id' => $id]);

    $object = $objectStmt->fetch();

    if (!$object) {
        throw new \RuntimeException(
            'Objek FTTH tidak ditemukan.',
            404
        );
    }

    // Tidak boleh menghapus object yang masih mempunyai child.
    $childStmt = $this->db->prepare("
        SELECT COUNT(*)
        FROM ftth_objects
        WHERE parent_object_id = :id
    ");
    $childStmt->execute([':id' => $id]);

    if ((int)$childStmt->fetchColumn() > 0) {
        throw new \RuntimeException(
            'Tidak dapat menghapus objek: masih terdapat sub-node jaringan di bawah objek ini.',
            400
        );
    }

    // Tidak boleh menghapus object yang masih terhubung kabel ACTIVE.
    $cableStmt = $this->db->prepare("
        SELECT COUNT(*)
        FROM ftth_cables
        WHERE status = 'ACTIVE'
          AND (
                start_object_id = :start_id
                OR end_object_id = :end_id
          )
    ");

    $cableStmt->execute([
        ':start_id' => $id,
        ':end_id'   => $id
    ]);

    $connectedCables = (int)$cableStmt->fetchColumn();

    if ($connectedCables > 0) {
        throw new \RuntimeException(
            "Tidak dapat menghapus objek {$object['code']}: masih terhubung dengan {$connectedCables} kabel ACTIVE. Hapus atau putuskan koneksi kabel terlebih dahulu.",
            400
        );
    }

    $del = $this->db->prepare("
        DELETE FROM ftth_objects
        WHERE id = :id
    ");

    return $del->execute([':id' => $id]);
}
    public function getObjectById(string $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM ftth_objects WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $obj = $stmt->fetch();
        if (!$obj) return null;

        $sp = $this->db->prepare("SELECT * FROM ftth_splitters WHERE parent_object_id = :id");
        $sp->execute([':id' => $id]);
        $obj['splitters'] = $sp->fetchAll();

return $this->enrichObjectPortInfo($obj);
    }

    public function getAllCables(): array
    {
        $stmt = $this->db->query("
            SELECT c.*,
                   s.name as start_object_name, s.code as start_object_code,
                   e.name as end_object_name, e.code as end_object_code
            FROM ftth_cables c
            LEFT JOIN ftth_objects s ON c.start_object_id = s.id
            LEFT JOIN ftth_objects e ON c.end_object_id = e.id
            ORDER BY c.created_at ASC
        ");
        $cables = $stmt->fetchAll();

        foreach ($cables as &$cable) {
            $vStmt = $this->db->prepare("SELECT vertex_order, latitude, longitude FROM ftth_cable_vertices WHERE cable_id = :cid ORDER BY vertex_order ASC");
            $vStmt->execute([':cid' => $cable['id']]);
            $cable['vertices'] = $vStmt->fetchAll();
        }

        return $cables;
    }

    public function saveCable(array $data): array
    {
        $id = $data['id'] ?? $this->generateUuid();
        $vertices = $data['vertices'] ?? [];

        // Start dan End Object wajib agar topology kabel selalu lengkap.
        $startObjectId = trim((string)($data['start_object_id'] ?? ''));
        $endObjectId = trim((string)($data['end_object_id'] ?? ''));

        if ($startObjectId === '' || $endObjectId === '') {
            throw new \RuntimeException(
                'Titik awal dan titik akhir kabel wajib dipilih.',
                400
            );
        }

        if ($startObjectId === $endObjectId) {
            throw new \RuntimeException(
                'Titik awal dan titik akhir kabel tidak boleh sama.',
                400
            );
        }

        // Ambil object endpoint sekaligus koordinatnya.
        $objectStmt = $this->db->prepare("
            SELECT id, object_type, code, latitude, longitude
            FROM ftth_objects
            WHERE id IN (:start_id, :end_id)
        ");

        $objectStmt->execute([
            ':start_id' => $startObjectId,
            ':end_id'   => $endObjectId
        ]);

        $endpointObjects = [];

        foreach ($objectStmt->fetchAll() as $object) {
            $endpointObjects[$object['id']] = $object;
        }

        if (
            !isset($endpointObjects[$startObjectId]) ||
            !isset($endpointObjects[$endObjectId])
        ) {
            throw new \RuntimeException(
                'Objek awal atau objek akhir kabel tidak ditemukan.',
                400
            );
        }

        // Jalur kabel minimal mempunyai titik awal dan titik akhir.
        if (count($vertices) < 2) {
            throw new \RuntimeException(
                'Jalur kabel minimal harus memiliki 2 titik.',
                400
            );
        }

        // Snap titik pertama tepat ke koordinat object awal.
        $vertices[0]['latitude'] =
            (float)$endpointObjects[$startObjectId]['latitude'];

        $vertices[0]['longitude'] =
            (float)$endpointObjects[$startObjectId]['longitude'];

        // Snap titik terakhir tepat ke koordinat object akhir.
        $lastIndex = count($vertices) - 1;

        $vertices[$lastIndex]['latitude'] =
            (float)$endpointObjects[$endObjectId]['latitude'];

        $vertices[$lastIndex]['longitude'] =
            (float)$endpointObjects[$endObjectId]['longitude'];

        // Ambil endpoint kabel lama jika ini UPDATE.
        $stmt = $this->db->prepare("
            SELECT id, start_object_id, end_object_id
            FROM ftth_cables
            WHERE id = :id
        ");

        $stmt->execute([
            ':id' => $id
        ]);

        $existingCable = $stmt->fetch();
        $exists = (bool)$existingCable;

        $oldStartObjectId = $existingCable['start_object_id'] ?? null;
        $oldEndObjectId = $existingCable['end_object_id'] ?? null;

        // Hitung panjang berdasarkan vertex yang sudah di-snap.
        $calculatedMeters =
            $this->calculatePolylineLengthMeters($vertices);

        if ($exists) {
            $sql = "UPDATE ftth_cables SET
                cable_code = :code,
                cable_name = :name,
                cable_type = :cable_type,
                core_count = :core_count,
                calculated_length_m = :calc_len,
                actual_installed_length_m = :act_len,
                start_object_id = :start_id,
                end_object_id = :end_id,
                color_hex = :color_hex,
                status = :status,
                technician_notes = :notes
                WHERE id = :id";
        } else {
            $sql = "INSERT INTO ftth_cables
                (
                    id,
                    cable_code,
                    cable_name,
                    cable_type,
                    core_count,
                    calculated_length_m,
                    actual_installed_length_m,
                    start_object_id,
                    end_object_id,
                    color_hex,
                    status,
                    technician_notes
                )
                VALUES
                (
                    :id,
                    :code,
                    :name,
                    :cable_type,
                    :core_count,
                    :calc_len,
                    :act_len,
                    :start_id,
                    :end_id,
                    :color_hex,
                    :status,
                    :notes
                )";
        }

        $cmd = $this->db->prepare($sql);

        $cmd->execute([
            ':id'         => $id,
            ':code'       => $data['cable_code'],
            ':name'       => $data['cable_name'],
            ':cable_type' => $data['cable_type'] ?? 'DISTRIBUTION',
            ':core_count' => (int)($data['core_count'] ?? 12),
            ':calc_len'   => $calculatedMeters,
            ':act_len'    => !empty($data['actual_installed_length_m'])
                ? (float)$data['actual_installed_length_m']
                : null,
            ':start_id'   => $startObjectId,
            ':end_id'     => $endObjectId,
            ':color_hex'  => $data['color_hex'] ?? '#3b82f6',
            ':status'     => $data['status'] ?? 'ACTIVE',
            ':notes'      => $data['technician_notes'] ?? null
        ]);

        // Simpan vertex jalur kabel.
        $this->db->prepare("
            DELETE FROM ftth_cable_vertices
            WHERE cable_id = :id
        ")->execute([
            ':id' => $id
        ]);

        $vInsert = $this->db->prepare("
            INSERT INTO ftth_cable_vertices
            (
                cable_id,
                vertex_order,
                latitude,
                longitude
            )
            VALUES
            (
                :cid,
                :vorder,
                :lat,
                :lng
            )
        ");

        foreach ($vertices as $idx => $v) {
            $vInsert->execute([
                ':cid'    => $id,
                ':vorder' => $idx + 1,
                ':lat'    => (float)$v['latitude'],
                ':lng'    => (float)$v['longitude']
            ]);
        }

        // Hitung ulang port object lama dan object baru.
        $this->recalculateObjectPorts([
            $oldStartObjectId,
            $oldEndObjectId,
            $startObjectId,
            $endObjectId
        ]);

        return [
            'id'                  => $id,
            'calculated_length_m' => $calculatedMeters,
            'vertices_count'      => count($vertices)
        ];
    }

    public function calculatePolylineLengthMeters(array $vertices): float
    {
        if (count($vertices) < 2) return 0.00;

        $earthRadiusM = 6371000.0;
        $totalDistance = 0.00;

        for ($i = 0; $i < count($vertices) - 1; $i++) {
            $lat1 = deg2rad((float)$vertices[$i]['latitude']);
            $lng1 = deg2rad((float)$vertices[$i]['longitude']);
            $lat2 = deg2rad((float)$vertices[$i + 1]['latitude']);
            $lng2 = deg2rad((float)$vertices[$i + 1]['longitude']);

            $dLat = $lat2 - $lat1;
            $dLng = $lng2 - $lng1;

            $a = sin($dLat / 2) * sin($dLat / 2) +
                 cos($lat1) * cos($lat2) * sin($dLng / 2) * sin($dLng / 2);
            $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
            $segmentDist = $earthRadiusM * $c;

            $totalDistance += $segmentDist;
        }

        return round($totalDistance, 2);
    }

    /**
     * Analisis Dampak Kerusakan Jaringan (Impact Analysis)
     * Mengembalikan daftar pelanggan yang terdampak jika objek/kabel mengalami gangguan
     */
    public function traceDownstreamCustomers(string $objectId): array
    {
        // Temukan seluruh sub-node turunan secara rekursif
        $downstreamIds = [$objectId];
        $queue = [$objectId];

        while (!empty($queue)) {
            $currId = array_shift($queue);
            $stmt = $this->db->prepare("SELECT id FROM ftth_objects WHERE parent_object_id = :pid");
            $stmt->execute([':pid' => $currId]);
            $children = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($children as $cid) {
                if (!in_array($cid, $downstreamIds)) {
                    $downstreamIds[] = $cid;
                    $queue[] = $cid;
                }
            }
        }

        // Ambil data pelanggan yang terhubung ke node-node ini
        $inClause = implode(',', array_fill(0, count($downstreamIds), '?'));
        $cStmt = $this->db->prepare("
            SELECT c.*, o.name as odp_name, o.code as odp_code
            FROM ftth_customers c
            JOIN ftth_objects o ON c.connected_odp_id = o.id
            WHERE c.connected_odp_id IN ($inClause)
        ");
        $cStmt->execute($downstreamIds);
        $customers = $cStmt->fetchAll();

        return [
            'target_object_id'    => $objectId,
            'affected_nodes'      => count($downstreamIds),
            'affected_subscribers'=> count($customers),
            'subscribers'         => $customers
        ];
    }

    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
