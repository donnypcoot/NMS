cd /var/www/transdata-nms

cat > install-nms-rbac.sh <<'BASH'
#!/usr/bin/env bash
set -euo pipefail

APP="/var/www/transdata-nms"
STAMP="$(date +%Y%m%d_%H%M%S)"
BACKUP="$APP/_backup_before_rbac_$STAMP"

echo "=============================================="
echo " Transdata NMS - RBAC / SNMP / MikroTik / ACS"
echo "=============================================="
echo

if [ ! -d "$APP" ]; then
    echo "ERROR: $APP tidak ditemukan."
    exit 1
fi

cd "$APP"

echo "[1/6] Membuat backup..."
mkdir -p "$BACKUP"

for f in \
    backend/public/index.php \
    backend/src/Auth.php \
    backend/src/GenieAcsService.php \
    backend/src/SnmpService.php \
    backend/src/MikrotikService.php \
    src/App.tsx \
    src/components/Sidebar.tsx \
    src/services/api.ts \
    src/types/nms.ts
do
    if [ -f "$f" ]; then
        mkdir -p "$BACKUP/$(dirname "$f")"
        cp -a "$f" "$BACKUP/$f"
    fi
done

echo "Backup: $BACKUP"
echo

echo "[2/6] Memeriksa struktur project..."

required=(
    "backend/public/index.php"
    "backend/src/Auth.php"
    "backend/src/GenieAcsService.php"
    "backend/src/SnmpService.php"
    "backend/src/MikrotikService.php"
    "src/App.tsx"
    "src/components/Sidebar.tsx"
    "src/services/api.ts"
    "src/types/nms.ts"
    "database/schema.sql"
)

for f in "${required[@]}"; do
    if [ ! -f "$f" ]; then
        echo "ERROR: file tidak ditemukan: $f"
        echo "Tidak ada perubahan yang diterapkan."
        exit 1
    fi
done

echo "Semua file utama ditemukan."
echo

echo "[3/6] Memeriksa database RBAC..."

grep -q "CREATE TABLE.*roles" database/schema.sql || {
    echo "WARNING: schema roles tidak ditemukan di schema.sql"
}

grep -q "CREATE TABLE.*permissions" database/schema.sql || {
    echo "WARNING: schema permissions tidak ditemukan di schema.sql"
}

grep -q "CREATE TABLE.*role_permissions" database/schema.sql || {
    echo "WARNING: schema role_permissions tidak ditemukan di schema.sql"
}

echo

echo "[4/6] Membuat RbacService.php..."

cat > backend/src/RbacService.php <<'PHP'
<?php

class RbacService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function permissionsForUser(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT DISTINCT p.code
            FROM users u
            JOIN role_permissions rp ON rp.role_id = u.role_id
            JOIN permissions p ON p.id = rp.permission_id
            WHERE u.id = :id
              AND u.is_active = 1
            ORDER BY p.code
        ");

        $stmt->execute([
            ':id' => $userId
        ]);

        return array_values($stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function can(int $userId, string $permission): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM users u
            JOIN role_permissions rp ON rp.role_id = u.role_id
            JOIN permissions p ON p.id = rp.permission_id
            WHERE u.id = :uid
              AND u.is_active = 1
              AND p.code = :permission
        ");

        $stmt->execute([
            ':uid' => $userId,
            ':permission' => $permission
        ]);

        return ((int)$stmt->fetchColumn()) > 0;
    }

    public function require(int $userId, string $permission): void
    {
        if (!$this->can($userId, $permission)) {
            http_response_code(403);

            if (function_exists('errorResponse')) {
                errorResponse(
                    "Akses ditolak. Permission $permission diperlukan.",
                    403
                );
            }

            echo json_encode([
                'success' => false,
                'message' => "Akses ditolak. Permission $permission diperlukan."
            ]);

            exit;
        }
    }

    public function users(): array
    {
        $stmt = $this->db->query("
            SELECT
                u.id,
                u.username,
                u.email,
                u.full_name,
                u.role_id,
                r.name AS role_name,
                u.is_active,
                u.created_at,
                u.last_login
            FROM users u
            LEFT JOIN roles r ON r.id = u.role_id
            ORDER BY u.id ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function roles(): array
    {
        $stmt = $this->db->query("
            SELECT id,name,description
            FROM roles
            ORDER BY id ASC
        ");

        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($roles as &$role) {
            $p = $this->db->prepare("
                SELECT p.code
                FROM role_permissions rp
                JOIN permissions p ON p.id = rp.permission_id
                WHERE rp.role_id = :role_id
                ORDER BY p.code
            ");

            $p->execute([
                ':role_id' => $role['id']
            ]);

            $role['permissions'] = $p->fetchAll(PDO::FETCH_COLUMN);
        }

        return $roles;
    }

    public function permissions(): array
    {
        return $this->db
            ->query("
                SELECT id,code,description
                FROM permissions
                ORDER BY code
            ")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createUser(array $data): int
    {
        $username = trim($data['username'] ?? '');
        $email = trim($data['email'] ?? '');
        $fullName = trim($data['full_name'] ?? '');
        $password = $data['password'] ?? '';
        $roleId = (int)($data['role_id'] ?? 0);

        if (
            $username === '' ||
            $email === '' ||
            $fullName === '' ||
            $roleId <= 0 ||
            strlen($password) < 8
        ) {
            throw new Exception(
                'Username, email, nama lengkap, role dan password minimal 8 karakter wajib diisi.'
            );
        }

        $check = $this->db->prepare("
            SELECT id
            FROM users
            WHERE username = :username
               OR email = :email
            LIMIT 1
        ");

        $check->execute([
            ':username' => $username,
            ':email' => $email
        ]);

        if ($check->fetch()) {
            throw new Exception('Username atau email sudah digunakan.');
        }

        $stmt = $this->db->prepare("
            INSERT INTO users
            (
                username,
                email,
                password_hash,
                full_name,
                role_id,
                is_active
            )
            VALUES
            (
                :username,
                :email,
                :password,
                :full_name,
                :role_id,
                1
            )
        ");

        $stmt->execute([
            ':username' => $username,
            ':email' => $email,
            ':password' => password_hash($password, PASSWORD_DEFAULT),
            ':full_name' => $fullName,
            ':role_id' => $roleId
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function updateUser(
        int $id,
        array $data,
        int $currentUserId
    ): void {

        if ($id === $currentUserId && isset($data['is_active']) && !$data['is_active']) {
            throw new Exception(
                'Akun yang sedang digunakan tidak boleh dinonaktifkan.'
            );
        }

        $stmt = $this->db->prepare("
            SELECT *
            FROM users
            WHERE id = :id
        ");

        $stmt->execute([
            ':id' => $id
        ]);

        $old = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$old) {
            throw new Exception('User tidak ditemukan.');
        }

        $username = trim($data['username'] ?? $old['username']);
        $email = trim($data['email'] ?? $old['email']);
        $fullName = trim($data['full_name'] ?? $old['full_name']);
        $roleId = (int)($data['role_id'] ?? $old['role_id']);
        $active = isset($data['is_active'])
            ? ((int)$data['is_active'] ? 1 : 0)
            : (int)$old['is_active'];

        if (
            $username === '' ||
            $email === '' ||
            $fullName === '' ||
            $roleId <= 0
        ) {
            throw new Exception('Data user belum lengkap.');
        }

        $sql = "
            UPDATE users
            SET
                username = :username,
                email = :email,
                full_name = :full_name,
                role_id = :role_id,
                is_active = :is_active
        ";

        $params = [
            ':username' => $username,
            ':email' => $email,
            ':full_name' => $fullName,
            ':role_id' => $roleId,
            ':is_active' => $active,
            ':id' => $id
        ];

        if (!empty($data['password'])) {
            if (strlen($data['password']) < 8) {
                throw new Exception('Password minimal 8 karakter.');
            }

            $sql .= ",
                password_hash = :password
            ";

            $params[':password'] =
                password_hash($data['password'], PASSWORD_DEFAULT);
        }

        $sql .= " WHERE id = :id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    public function deleteUser(
        int $id,
        int $currentUserId
    ): void {

        if ($id === $currentUserId) {
            throw new Exception(
                'Anda tidak dapat menghapus akun sendiri.'
            );
        }

        $stmt = $this->db->prepare("
            SELECT
                u.id,
                u.is_active,
                r.name AS role_name
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE u.id = :id
        ");

        $stmt->execute([
            ':id' => $id
        ]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return;
        }

        if (
            $user['role_name'] === 'Superadmin' &&
            (int)$user['is_active'] === 1
        ) {
            $count = $this->db->query("
                SELECT COUNT(*)
                FROM users u
                JOIN roles r ON r.id = u.role_id
                WHERE r.name = 'Superadmin'
                  AND u.is_active = 1
            ")->fetchColumn();

            if ((int)$count <= 1) {
                throw new Exception(
                    'Minimal harus ada satu Superadmin aktif.'
                );
            }
        }

        $stmt = $this->db->prepare("
            DELETE FROM users
            WHERE id = :id
        ");

        $stmt->execute([
            ':id' => $id
        ]);
    }

    public function updateRolePermissions(
        int $roleId,
        array $permissions
    ): void {

        $stmt = $this->db->prepare("
            SELECT id
            FROM roles
            WHERE id = :id
        ");

        $stmt->execute([
            ':id' => $roleId
        ]);

        if (!$stmt->fetchColumn()) {
            throw new Exception('Role tidak ditemukan.');
        }

        $this->db->beginTransaction();

        try {

            $stmt = $this->db->prepare("
                DELETE FROM role_permissions
                WHERE role_id = :role_id
            ");

            $stmt->execute([
                ':role_id' => $roleId
            ]);

            $find = $this->db->prepare("
                SELECT id
                FROM permissions
                WHERE code = :code
            ");

            $insert = $this->db->prepare("
                INSERT INTO role_permissions
                (
                    role_id,
                    permission_id
                )
                VALUES
                (
                    :role_id,
                    :permission_id
                )
            ");

            foreach (array_unique($permissions) as $permission) {

                $find->execute([
                    ':code' => $permission
                ]);

                $permissionId = $find->fetchColumn();

                if (!$permissionId) {
                    continue;
                }

                $insert->execute([
                    ':role_id' => $roleId,
                    ':permission_id' => $permissionId
                ]);
            }

            $this->db->commit();

        } catch (Throwable $e) {

            $this->db->rollBack();

            throw $e;
        }
    }
}
PHP

echo "RbacService.php berhasil dibuat."
echo

echo "[5/6] Membuat migration RBAC..."

cat > database/rbac_migration.sql <<'SQL'
INSERT INTO permissions (code, description) VALUES
('nms.access','Akses NMS'),
('dashboard.view','Melihat dashboard'),
('customer.view','Melihat pelanggan'),
('customer.create','Membuat pelanggan'),
('customer.update','Mengubah pelanggan'),
('customer.delete','Menghapus pelanggan'),
('customer.isolir','Isolir/buka isolir pelanggan'),
('package.view','Melihat paket'),
('package.manage','Kelola paket'),
('device.view','Melihat perangkat'),
('device.manage','Kelola perangkat'),
('device.ping','Ping perangkat'),
('device.snmp','Polling SNMP'),
('mikrotik.view','Melihat MikroTik'),
('mikrotik.test','Tes koneksi MikroTik'),
('genieacs.view','Melihat GenieACS'),
('genieacs.manage','Kelola GenieACS'),
('gis.view','Melihat GIS'),
('gis.manage','Kelola GIS'),
('alarm.view','Melihat alarm'),
('alarm.manage','Kelola alarm'),
('audit.view','Melihat audit log'),
('settings.view','Melihat pengaturan'),
('settings.manage','Mengubah pengaturan'),
('user.view','Melihat user'),
('user.create','Membuat user'),
('user.update','Mengubah user'),
('user.delete','Menghapus user'),
('role.view','Melihat role'),
('role.manage','Mengubah permission role')
ON DUPLICATE KEY UPDATE
description = VALUES(description);

INSERT IGNORE INTO role_permissions(role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.name = 'Superadmin';

INSERT IGNORE INTO role_permissions(role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p
WHERE r.name = 'Network Engineer'
AND p.code IN (
'nms.access',
'dashboard.view',
'device.view',
'device.manage',
'device.ping',
'device.snmp',
'mikrotik.view',
'mikrotik.test',
'genieacs.view',
'genieacs.manage',
'gis.view',
'gis.manage',
'alarm.view',
'alarm.manage',
'audit.view'
);

INSERT IGNORE INTO role_permissions(role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p
WHERE r.name = 'NOC Operator'
AND p.code IN (
'nms.access',
'dashboard.view',
'customer.view',
'device.view',
'device.ping',
'device.snmp',
'mikrotik.view',
'mikrotik.test',
'genieacs.view',
'alarm.view',
'audit.view'
);

INSERT IGNORE INTO role_permissions(role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p
WHERE r.name = 'FTTH Field Technician'
AND p.code IN (
'nms.access',
'dashboard.view',
'customer.view',
'device.view',
'genieacs.view',
'gis.view',
'gis.manage',
'alarm.view',
'audit.view'
);
SQL

echo "Migration dibuat."
echo

echo "[6/6] Validasi PHP..."

php -l backend/src/RbacService.php

echo
echo "=============================================="
echo "PATCH DASAR BERHASIL DIBUAT"
echo "=============================================="
echo
echo "Backup:"
echo "  $BACKUP"
echo
echo "File baru:"
echo "  backend/src/RbacService.php"
echo "  database/rbac_migration.sql"
echo
echo "BELUM menjalankan perubahan index.php/frontend."
echo "Hal ini sengaja agar route yang sudah ada di project Anda"
echo "tidak tertimpa secara membabi buta."
echo
echo "Langkah berikutnya:"
echo
echo "  mysql ... < database/rbac_migration.sql"
echo
echo "Kemudian kita pasang route:"
echo "  /users"
echo "  /roles"
echo "  /roles/{id}/permissions"
echo "  /devices/{id}/poll-snmp"
echo "  /mikrotik/test-connection"
echo "  /genieacs/devices"
echo
echo "=============================================="

BASH
