-- ============================================================
-- Pantau Jalan — MySQL Schema v7
-- Jalankan di phpMyAdmin atau HeidiSQL (Laragon)
-- ============================================================

CREATE DATABASE IF NOT EXISTS pantau_jalan
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE pantau_jalan;

-- ── Tabel laporan ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS reports (
    id          CHAR(36)        NOT NULL,
    road_name   VARCHAR(120)    NOT NULL,
    description TEXT            NOT NULL,
    lat         DECIMAL(10,7)   NOT NULL,
    lng         DECIMAL(10,7)   NOT NULL,
    status      ENUM('pending','damaged','in_progress','fixed','reported','ditolak')
                                NOT NULL DEFAULT 'pending',
    reporter    VARCHAR(60)     NOT NULL DEFAULT 'Anonim',
    verified_by      VARCHAR(60)     NULL,
    rejection_reason TEXT            NULL COMMENT 'Alasan penolakan laporan',
    photo_urls  JSON            NULL COMMENT 'Array of photo URL strings',
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_status     (status),
    INDEX idx_created_at (created_at),
    INDEX idx_lat_lng    (lat, lng)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Tabel riwayat status ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS report_history (
    id          INT             NOT NULL AUTO_INCREMENT,
    report_id   CHAR(36)        NOT NULL,
    status      ENUM('pending','damaged','in_progress','fixed','reported','ditolak')
                                NOT NULL,
    actor       VARCHAR(60)     NOT NULL,
    role        ENUM('warga','petugas','admin')
                                NOT NULL DEFAULT 'warga',
    note        TEXT            NULL,
    timestamp   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_report_id (report_id),
    FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Tabel users (petugas & admin) ────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id          CHAR(36)        NOT NULL,
    username    VARCHAR(60)     NOT NULL,
    name        VARCHAR(100)    NOT NULL,
    password    VARCHAR(255)    NOT NULL COMMENT 'bcrypt hash',
    role        ENUM('admin','petugas','warga')
                                NOT NULL DEFAULT 'warga',
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Seed data: admin & petugas default ───────────────────────
-- Password: admin123   → hash bcrypt
-- Password: petugas123 → hash bcrypt
INSERT IGNORE INTO users (id, username, name, password, role) VALUES
(
    'a1b2c3d4-0000-4000-8000-000000000001',
    'admin',
    'Admin Pantau Jalan',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'admin'
),
(
    'a1b2c3d4-0000-4000-8000-000000000002',
    'petugas',
    'Petugas Lapangan',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'petugas'
);
-- ⚠️  Hash di atas = "password" (bukan admin123/petugas123)
-- Untuk generate hash sendiri, jalankan: php -r "echo password_hash('admin123', PASSWORD_BCRYPT, ['cost'=>12]);"

-- ── Seed data: contoh laporan ────────────────────────────────
INSERT IGNORE INTO reports (id, road_name, description, lat, lng, status, reporter, verified_by, photo_urls, created_at, updated_at) VALUES
(
    'r0000001-0000-4000-8000-000000000001',
    'Jl. Yos Sudarso',
    'Lubang lebar di sisi kiri jalan, rawan saat malam hari.',
    -2.2082, 113.9136,
    'damaged', 'Warga (Anonim)', 'Petugas Lapangan',
    '[]',
    DATE_SUB(NOW(), INTERVAL 5 DAY),
    DATE_SUB(NOW(), INTERVAL 3 DAY)
),
(
    'r0000002-0000-4000-8000-000000000002',
    'Jl. Ahmad Yani KM 3',
    'Aspal retak di jalur tengah sepanjang ± 5 meter.',
    -2.2134, 113.9201,
    'in_progress', 'Budi Santoso', 'Admin Pantau Jalan',
    '[]',
    DATE_SUB(NOW(), INTERVAL 10 DAY),
    DATE_SUB(NOW(), INTERVAL 1 DAY)
),
(
    'r0000003-0000-4000-8000-000000000003',
    'Jl. Lambung Mangkurat',
    'Jalan berlubang di depan toko Sumber Jaya. Kedalaman 15cm.',
    -2.2051, 113.9089,
    'fixed', 'Siti Rahmah', 'Petugas Lapangan',
    '[]',
    DATE_SUB(NOW(), INTERVAL 20 DAY),
    DATE_SUB(NOW(), INTERVAL 2 DAY)
);

INSERT IGNORE INTO report_history (report_id, status, actor, role, note, timestamp) VALUES
('r0000001-0000-4000-8000-000000000001', 'pending',  'Warga (Anonim)',      'warga',   'Laporan dikirim.',         DATE_SUB(NOW(), INTERVAL 5 DAY)),
('r0000001-0000-4000-8000-000000000001', 'damaged',  'Petugas Lapangan',    'petugas', 'Dikonfirmasi rusak parah.',DATE_SUB(NOW(), INTERVAL 3 DAY)),
('r0000002-0000-4000-8000-000000000002', 'pending',  'Budi Santoso',        'warga',   'Laporan dikirim.',         DATE_SUB(NOW(), INTERVAL 10 DAY)),
('r0000002-0000-4000-8000-000000000002', 'damaged',  'Admin Pantau Jalan',  'admin',   'Diverifikasi.',            DATE_SUB(NOW(), INTERVAL 8 DAY)),
('r0000002-0000-4000-8000-000000000002', 'in_progress','Admin Pantau Jalan','admin',   'Perbaikan dijadwalkan.',   DATE_SUB(NOW(), INTERVAL 1 DAY)),
('r0000003-0000-4000-8000-000000000003', 'pending',  'Siti Rahmah',         'warga',   'Laporan dikirim.',         DATE_SUB(NOW(), INTERVAL 20 DAY)),
('r0000003-0000-4000-8000-000000000003', 'damaged',  'Petugas Lapangan',    'petugas', 'Dikonfirmasi rusak.',      DATE_SUB(NOW(), INTERVAL 18 DAY)),
('r0000003-0000-4000-8000-000000000003', 'in_progress','Petugas Lapangan',  'petugas', 'Tim pengaspalan masuk.',   DATE_SUB(NOW(), INTERVAL 10 DAY)),
('r0000003-0000-4000-8000-000000000003', 'fixed',    'Petugas Lapangan',    'petugas', 'Perbaikan selesai 100%.', DATE_SUB(NOW(), INTERVAL 2 DAY));

-- ══════════════════════════════════════════
-- Reset password ke 'admin123'
-- Jalankan ini jika tidak bisa login
-- ══════════════════════════════════════════
UPDATE users SET password = '$2y$10$vI8aWBnW3fID.ZQ4/zo1G.q1lRps.9cGLcZEiGDMVr5yUP1KR6hl6' WHERE username = 'admin';
UPDATE users SET password = '$2y$10$vI8aWBnW3fID.ZQ4/zo1G.q1lRps.9cGLcZEiGDMVr5yUP1KR6hl6' WHERE username = 'petugas';

-- ══════════════════════════════════════════
-- UPDATE: Tambah fitur tolak laporan (jalankan jika database sudah ada)
-- ══════════════════════════════════════════
ALTER TABLE reports
    MODIFY COLUMN status ENUM('pending','damaged','in_progress','fixed','reported','ditolak') NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS rejection_reason TEXT NULL COMMENT 'Alasan penolakan laporan' AFTER verified_by;

ALTER TABLE report_history
    MODIFY COLUMN status ENUM('pending','damaged','in_progress','fixed','reported','ditolak') NOT NULL;
