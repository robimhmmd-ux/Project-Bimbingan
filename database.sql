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
    kuota_bimbingan INT DEFAULT 10,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 3. TABEL MAHASISWA
CREATE TABLE IF NOT EXISTS mahasiswa (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    nim VARCHAR(20) NOT NULL UNIQUE,
    nama_lengkap VARCHAR(100) NOT NULL,
    judul_skripsi VARCHAR(255) DEFAULT 'Belum Ditentukan',
    dosen_pembimbing_id INT NULL,
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

-- -------------------------------------------------------------
-- DATA DUMMY AWAL (SEEDER)
-- Password default semua user: password123
-- -------------------------------------------------------------
INSERT INTO users (id, username, password, role) VALUES
(1, 'admin', '$2y$10$7iNS8F5KQGTbVKHI9Acx5unQphy3bA4sjkSDA16B8LjO3mPULtq.i', 'admin'),
(2, 'dosen1', '$2y$10$7iNS8F5KQGTbVKHI9Acx5unQphy3bA4sjkSDA16B8LjO3mPULtq.i', 'dosen'),
(3, 'dosen2', '$2y$10$7iNS8F5KQGTbVKHI9Acx5unQphy3bA4sjkSDA16B8LjO3mPULtq.i', 'dosen'),
(4, 'mhs1',   '$2y$10$7iNS8F5KQGTbVKHI9Acx5unQphy3bA4sjkSDA16B8LjO3mPULtq.i', 'mahasiswa'),
(5, 'mhs2',   '$2y$10$7iNS8F5KQGTbVKHI9Acx5unQphy3bA4sjkSDA16B8LjO3mPULtq.i', 'mahasiswa');

INSERT INTO dosen (id, user_id, nidn, nama_dosen, email, no_whatsapp, kuota_bimbingan) VALUES
(1, 2, '0412058001', 'Dr. Hendra Gunawan, M.T.', 'hendra@univ.ac.id', '081234567890', 10),
(2, 3, '0420088502', 'Siti Rahmawati, M.Kom.', 'siti.rahma@univ.ac.id', '081298765432', 8);

INSERT INTO mahasiswa (id, user_id, nim, nama_lengkap, judul_skripsi, dosen_pembimbing_id) VALUES
(1, 4, '202401001', 'Budi Santoso', 'Rancang Bangun Sistem Antrean Rumah Sakit Berbasis Web', 1),
(2, 5, '202401002', 'Anisa Putri', 'Implementasi Algoritma C4.5 untuk Prediksi Kelulusan Mahasiswa', 1);

INSERT INTO jadwal_dosen (dosen_id, hari, jam_mulai, jam_selesai, status_aktif) VALUES
(1, 'Senin', '09:00:00', '12:00:00', 'aktif'),
(1, 'Kamis', '13:00:00', '16:00:00', 'aktif'),
(2, 'Selasa', '10:00:00', '14:00:00', 'aktif');


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
