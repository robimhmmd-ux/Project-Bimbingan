-- =====================================================================
-- DATABASE: SISTEM INFORMASI BIMBINGAN SKRIPSI (PEMROGRAMAN WEB DASAR)
-- HOST TARGET: TiDB Serverless / MySQL 8.x
-- =====================================================================

-- 1. TABEL PENGGUNA (USERS / AKUN)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'dosen', 'mahasiswa') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. TABEL DOSEN
CREATE TABLE IF NOT EXISTS dosen (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    nidn VARCHAR(20) NOT NULL UNIQUE,
    nama_dosen VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    no_whatsapp VARCHAR(20),
    foto_profil VARCHAR(255) DEFAULT 'default.png',
    kuota_bimbingan INT DEFAULT 5,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 3. TABEL MAHASISWA
CREATE TABLE IF NOT EXISTS mahasiswa (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    nim VARCHAR(20) NOT NULL UNIQUE,
    nama_lengkap VARCHAR(100) NOT NULL,
    kelas VARCHAR(20) DEFAULT 'TI-1A-2025',
    judul_skripsi VARCHAR(255) DEFAULT 'Belum Ditentukan',
    dosen_pembimbing_id INT NULL,
    status_pembimbing ENUM('belum_diplot', 'menunggu_konfirmasi', 'aktif') DEFAULT 'belum_diplot',
    foto_profil VARCHAR(255) DEFAULT 'default.png',
    status_kelulusan ENUM('proses_bimbingan', 'siap_sidang', 'lulus') DEFAULT 'proses_bimbingan',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (dosen_pembimbing_id) REFERENCES dosen(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 4. TABEL JADWAL KETERSEDIAAN DOSEN
CREATE TABLE IF NOT EXISTS jadwal_dosen (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dosen_id INT NOT NULL,
    hari ENUM('Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu') NOT NULL,
    jam_mulai TIME NOT NULL,
    jam_selesai TIME NOT NULL,
    status_aktif ENUM('aktif', 'libur') DEFAULT 'aktif',
    FOREIGN KEY (dosen_id) REFERENCES dosen(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 5. TABEL PENGAJUAN BIMBINGAN
CREATE TABLE IF NOT EXISTS pengajuan_bimbingan (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mahasiswa_id INT NOT NULL,
    dosen_id INT NOT NULL,
    tanggal_bimbingan DATE NOT NULL,
    jam_bimbingan TIME NOT NULL,
    bab_pembahasan VARCHAR(100) NOT NULL,
    catatan_mahasiswa TEXT,
    file_draft VARCHAR(255) NULL,
    link_drive VARCHAR(255) NULL,
    status ENUM('menunggu', 'disetujui', 'ditolak', 'revisi', 'selesai') DEFAULT 'menunggu',
    catatan_dosen TEXT NULL,
    nilai_progres INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (mahasiswa_id) REFERENCES mahasiswa(id) ON DELETE CASCADE,
    FOREIGN KEY (dosen_id) REFERENCES dosen(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 6. TABEL LOGBOOK / REKAPITULASI PROGRES
CREATE TABLE IF NOT EXISTS logbook_bimbingan (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bimbingan_id INT NOT NULL,
    mahasiswa_id INT NOT NULL,
    tanggal_acc DATE NOT NULL,
    catatan_hasil TEXT NOT NULL,
    status_bab ENUM('revisi', 'acc_bab', 'acc_sidang') NOT NULL,
    FOREIGN KEY (bimbingan_id) REFERENCES pengajuan_bimbingan(id) ON DELETE CASCADE,
    FOREIGN KEY (mahasiswa_id) REFERENCES mahasiswa(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 7. TABEL PESAN CHAT (KOMUNIKASI INTERNAL MAHASISWA & DOSEN)
CREATE TABLE IF NOT EXISTS pesan_chat (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pengirim_user_id INT NOT NULL,
    penerima_user_id INT NOT NULL,
    pesan TEXT NOT NULL,
    lampiran VARCHAR(255) NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pengirim_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (penerima_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 8. TABEL SIDANG SKRIPSI (PENDAFTARAN, JADWAL & 3 DOSEN PENGUJI)
CREATE TABLE IF NOT EXISTS sidang_skripsi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mahasiswa_id INT NOT NULL,
    dosen_pembimbing_id INT NOT NULL,
    penguji_1_id INT NULL,
    penguji_2_id INT NULL,
    penguji_3_id INT NULL,
    tanggal_sidang DATE NULL,
    jam_sidang TIME NULL,
    ruangan VARCHAR(50) NULL,
    judul_final VARCHAR(255) NULL,
    status_sidang ENUM('pengajuan', 'dijadwalkan', 'lulus', 'revisi', 'tidak_lulus') DEFAULT 'pengajuan',
    nilai_penguji_1 DECIMAL(5,2) NULL,
    nilai_penguji_2 DECIMAL(5,2) NULL,
    nilai_penguji_3 DECIMAL(5,2) NULL,
    nilai_akhir DECIMAL(5,2) NULL,
    catatan_sidang TEXT NULL,
    ketentuan_sidang TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (mahasiswa_id) REFERENCES mahasiswa(id) ON DELETE CASCADE,
    FOREIGN KEY (dosen_pembimbing_id) REFERENCES dosen(id) ON DELETE CASCADE,
    FOREIGN KEY (penguji_1_id) REFERENCES dosen(id) ON DELETE SET NULL,
    FOREIGN KEY (penguji_2_id) REFERENCES dosen(id) ON DELETE SET NULL,
    FOREIGN KEY (penguji_3_id) REFERENCES dosen(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 9. TABEL NOTIFIKASI PENGGUNA
CREATE TABLE IF NOT EXISTS notifikasi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    judul VARCHAR(100) NOT NULL,
    pesan TEXT NOT NULL,
    tipe ENUM('info', 'pembimbing', 'sidang', 'revisi', 'sukses') DEFAULT 'info',
    link_tujuan VARCHAR(255) NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- DATA DUMMY AWAL (SEEDER)
-- Akun Default:
-- - Admin: username: admin / password: password123
-- - Dosen: username: faqih, budi, yuni, agus, dewi, ridwan, fitri, dosen1, dosen2
--          password: password123
-- - Mahasiswa: username: <NIM Masing-Masing> / password: <NIM Masing-Masing>
-- -------------------------------------------------------------
INSERT INTO users (id, username, password, role) VALUES
(1, 'admin', '$2y$10$7iNS8F5KQGTbVKHI9Acx5unQphy3bA4sjkSDA16B8LjO3mPULtq.i', 'admin'),
(2, 'dosen1', '$2y$10$7iNS8F5KQGTbVKHI9Acx5unQphy3bA4sjkSDA16B8LjO3mPULtq.i', 'dosen'),
(3, 'dosen2', '$2y$10$7iNS8F5KQGTbVKHI9Acx5unQphy3bA4sjkSDA16B8LjO3mPULtq.i', 'dosen')
ON DUPLICATE KEY UPDATE role=VALUES(role);

INSERT INTO dosen (id, user_id, nidn, nama_dosen, email, no_whatsapp, kuota_bimbingan) VALUES
(1, 2, '0412058001', 'Dr. Hendra Gunawan, M.T.', 'hendra@univ.ac.id', '081234567890', 5),
(2, 3, '0420088502', 'Siti Rahmawati, M.Kom.', 'siti.rahma@univ.ac.id', '081298765432', 5)
ON DUPLICATE KEY UPDATE nama_dosen=VALUES(nama_dosen);

INSERT INTO jadwal_dosen (dosen_id, hari, jam_mulai, jam_selesai, status_aktif) VALUES
(1, 'Senin', '09:00:00', '12:00:00', 'aktif'),
(1, 'Kamis', '13:00:00', '16:00:00', 'aktif'),
(2, 'Selasa', '10:00:00', '14:00:00', 'aktif');
