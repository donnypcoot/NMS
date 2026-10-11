START TRANSACTION;

-- Role Finance: tambahkan hanya jika belum tersedia.
INSERT INTO roles (name, description)
VALUES (
    'Finance',
    'Akses informasi pembayaran pelanggan dan tindakan isolir/buka isolir'
)
ON DUPLICATE KEY UPDATE
    description = VALUES(description);

-- Daftar permission
INSERT INTO permissions (code, description) VALUES
('dashboard.view', 'Melihat dashboard'),
('users.manage', 'Mengelola akun pengguna'),
('roles.manage', 'Mengelola role dan permission'),
('customers.view', 'Melihat data pelanggan operasional'),
('customers.create', 'Mendaftarkan pelanggan'),
('customers.edit', 'Mengubah data utama pelanggan'),
('customers.delete', 'Menghapus data pelanggan'),
('customers.payment_view', 'Melihat informasi pembayaran pelanggan'),
('customers.isolate', 'Melakukan isolir pelanggan'),
('customers.unisolate', 'Membuka isolir pelanggan'),
('customers.reminder', 'Mengirim pengingat pembayaran'),
('packages.view', 'Melihat paket layanan'),
('packages.manage', 'Mengelola paket layanan'),
('devices.view', 'Melihat perangkat jaringan'),
('devices.manage', 'Mengelola perangkat jaringan'),
('mikrotik.view', 'Melihat informasi MikroTik'),
('mikrotik.manage', 'Mengelola koneksi dan konfigurasi MikroTik'),
('genieacs.view', 'Melihat informasi GenieACS dan ONT'),
('genieacs.manage', 'Mengelola operasi GenieACS dan ONT'),
('gis.view', 'Melihat peta GIS FTTH'),
('gis.manage', 'Mengelola objek infrastruktur GIS FTTH'),
('alarms.view', 'Melihat alarm dan insiden'),
('alarms.manage', 'Mengelola alarm dan insiden'),
('audit.view', 'Melihat audit log'),
('settings.view', 'Melihat pengaturan sistem'),
('settings.manage', 'Mengubah pengaturan sistem'),
('network.monitor', 'Melakukan monitoring jaringan'),
('troubleshooting.manage', 'Menjalankan tindakan troubleshooting')
ON DUPLICATE KEY UPDATE
    description = VALUES(description);

-- Superadmin mendapatkan seluruh permission yang terdaftar.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.name = 'Superadmin';

-- Network Engineer: perangkat dan operasional jaringan.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code IN (
    'dashboard.view',
    'devices.view', 'devices.manage',
    'mikrotik.view', 'mikrotik.manage',
    'genieacs.view', 'genieacs.manage',
    'gis.view',
    'alarms.view', 'alarms.manage',
    'network.monitor',
    'troubleshooting.manage'
)
WHERE r.name = 'Network Engineer';

-- NOC Operator: monitoring dan pengelolaan pelanggan operasional.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code IN (
    'dashboard.view',
    'customers.view', 'customers.create', 'customers.edit',
    'packages.view',
    'devices.view',
    'mikrotik.view',
    'genieacs.view',
    'gis.view',
    'alarms.view', 'alarms.manage',
    'network.monitor',
    'troubleshooting.manage'
)
WHERE r.name = 'NOC Operator';

-- FTTH Field Technician: GIS dan inspeksi lapangan.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code IN (
    'dashboard.view',
    'gis.view', 'gis.manage',
    'devices.view',
    'genieacs.view',
    'alarms.view',
    'network.monitor',
    'troubleshooting.manage'
)
WHERE r.name = 'FTTH Field Technician';

-- Finance: hanya permission pembayaran dan isolir.
-- Tidak diberikan customers.view karena endpoint saat ini
-- mengembalikan kolom sensitif pelanggan secara lengkap.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code IN (
    'dashboard.view',
    'customers.payment_view',
    'customers.isolate',
    'customers.unisolate',
    'customers.reminder'
)
WHERE r.name = 'Finance';

COMMIT;
