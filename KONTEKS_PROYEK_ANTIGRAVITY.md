# KONTEKS ARSITEKTUR & TEMPLATE RESMI PROYEK
# Sistem Bimbingan Skripsi & Laporan Progres Mahasiswa
# (Mata Kuliah: Pemrograman Web Dasar - PHP Native & TiDB Cloud)

Dokumen ini berfungsi sebagai Single Source of Truth (SSOT) konteks proyek untuk Antigravity IDE.
Saat anggota kelompok berkonsultasi dengan Antigravity AI, sematkan (attach) file ini agar AI memahami seluruh konteks proyek secara utuh.

---

## 1. IDENTITAS & PRINSIP ARSITEKTUR
- **Mata Kuliah**: Pemrograman Web Dasar
- **Bahasa Pemrograman**: PHP Native (PHP 8.x) murni. (TIDAK MENGGUNAKAN FRAMEWORK Laravel/CI/Symfony).
- **Database**: TiDB Serverless (MySQL Compatible) dengan koneksi PDO Prepared Statements + SSL.
- **Frontend / Styling**: HTML5 Standar, Bootstrap 5 (via CDN), Bootstrap Icons, dan Vanilla CSS.
- **Arsitektur Halaman**: Modularitas menggunakan `require_once` (`templates/header.php`, `templates/sidebar_*.php`, `templates/footer.php`).
- **Prinsip Logika**: Humble, bersih, mudah dibaca, dan mudah dipertanggungjawabkan di hadapan dosen penguji.

---

## 2. PETA FOLDER & HAK AKSES KERJA (ROLE BOUNDARIES)
Setiap anggota hanya boleh mengedit file di folder tanggung jawabnya:

```text
proyek-bimbingan/
├── assets/                  # CSS, JS, dan direktori file upload (PDF draft skripsi)
│   ├── css/style.css
│   └── uploads/
├── config/
│   └── database.php         # Koneksi database TiDB via PDO SSL (Rob)
├── templates/               # Kerangka modular bersama (Diatur oleh Yoga & Robi)
│   ├── header.php           # Tag <head>, Bootstrap 5 CDN, Top Navbar
│   ├── sidebar_admin.php    # Navigasi khusus Admin
│   ├── sidebar_dosen.php    # Navigasi khusus Dosen
│   ├── sidebar_mahasiswa.php# Navigasi khusus Mahasiswa
│   └── footer.php           # Footer, JS Bootstrap CDN, penutup tag
├── admin/                   # [WILAYAH KERJA TALITA - Modul Administrator]
│   ├── index.php            # Dashboard ringkasan admin
│   ├── data_mahasiswa.php   # CRUD data mahasiswa
│   ├── data_dosen.php       # CRUD data dosen
│   └── plot_pembimbing.php  # Pasangkan mahasiswa ke dosen pembimbing
├── dosen/                   # [WILAYAH KERJA QONITA - Modul Dosen Pembimbing]
│   ├── index.php            # Dashboard ringkasan dosen
│   ├── jadwal_saya.php      # Kelola ketersediaan hari/jam bimbingan
│   ├── persetujuan.php      # Daftar bimbingan masuk (Setujui / Tolak / Reschedule)
│   └── riwayat_bimbingan.php# Rekapan bimbingan, unduh draft skripsi, beri catatan & nilai
├── mahasiswa/               # [WILAYAH KERJA SALSA - Modul Mahasiswa]
│   ├── index.php            # Dashboard progres mahasiswa & info dosen
│   ├── ajukan_jadwal.php    # Form reservasi jadwal bimbingan (validasi anti-bentrok)
│   ├── upload_draft.php     # Form upload draft skripsi (PDF)
│   └── logbook.php          # Tabel riwayat bimbingan & cetak kartu bimbingan
├── index.php                # Landing page publik (Robi)
├── login.php                # Form login serbaguna untuk 3 role (Robi)
├── logout.php               # Hapus session & redirect ke login (Robi)
├── database.sql             # Skema DDL 6 Tabel & Seeder Dummy awal
├── vercel.json              # Konfigurasi deploy ke Vercel
└── README.md                # Panduan teknis & SOP Git tim
```

---

## 3. SKEMA DATABASE RESMI (TIDB / MYSQL)
Semua anggota wajib menggunakan tabel dan nama kolom berikut tanpa mengubah struktur:

1. **`users`**:
   - Kolom: `id` (INT PK), `username` (VARCHAR 50 UNIQUE), `password` (VARCHAR 255 BCRYPT), `role` (ENUM: 'admin', 'dosen', 'mahasiswa'), `created_at`.
2. **`dosen`**:
   - Kolom: `id` (INT PK), `user_id` (INT FK), `nidn` (VARCHAR 20 UNIQUE), `nama_dosen` (VARCHAR 100), `email` (VARCHAR 100), `no_whatsapp` (VARCHAR 20), `foto_profil` (VARCHAR 255), `kuota_bimbingan` (INT).
3. **`mahasiswa`**:
   - Kolom: `id` (INT PK), `user_id` (INT FK), `nim` (VARCHAR 20 UNIQUE), `nama_lengkap` (VARCHAR 100), `judul_skripsi` (VARCHAR 255), `dosen_pembimbing_id` (INT FK NULL), `foto_profil` (VARCHAR 255), `status_kelulusan` (ENUM: 'proses_bimbingan', 'siap_sidang', 'lulus').
4. **`jadwal_dosen`**:
   - Kolom: `id` (INT PK), `dosen_id` (INT FK), `hari` (ENUM: 'Senin' s.d. 'Sabtu'), `jam_mulai` (TIME), `jam_selesai` (TIME), `status_aktif` (ENUM: 'aktif', 'libur').
5. **`pengajuan_bimbingan`**:
   - Kolom: `id` (INT PK), `mahasiswa_id` (INT FK), `dosen_id` (INT FK), `tanggal_bimbingan` (DATE), `jam_bimbingan` (TIME), `bab_pembahasan` (VARCHAR 100), `catatan_mahasiswa` (TEXT), `file_draft` (VARCHAR 255), `status` (ENUM: 'menunggu', 'disetujui', 'ditolak', 'revisi', 'selesai'), `catatan_dosen` (TEXT), `nilai_progres` (INT 0-100).
6. **`logbook_bimbingan`**:
   - Kolom: `id` (INT PK), `bimbingan_id` (INT FK), `mahasiswa_id` (INT FK), `tanggal_acc` (DATE), `catatan_hasil` (TEXT), `status_bab` (ENUM: 'revisi', 'acc_bab', 'acc_sidang').

---

## 4. CONTOH IMPLEMENTASI HALAMAN (MODULAR PHP)
Setiap halaman kerja di `admin/`, `dosen/`, atau `mahasiswa/` wajib mengikuti pola struktur ini:

```php
<?php
// Contoh file: mahasiswa/index.php
$page_title = "Dashboard Mahasiswa";
$current_page = "dashboard";

require_once '../config/database.php';
require_once '../templates/header.php';
require_once '../templates/sidebar_mahasiswa.php';

// Proteksi hak akses role mahasiswa
if ($_SESSION['role'] !== 'mahasiswa') {
    header("Location: ../login.php");
    exit;
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Dashboard Mahasiswa</h1>
</div>

<div class="row">
    <!-- Konten Halaman Anda di Sini -->
</div>

<?php
require_once '../templates/footer.php';
?>
```

---

## 5. INSTRUKSI UNTUK ANTIGRAVITY AI SAAT CODING
1. JANGAN PERNAH menyarankan instalasi npm, node_modules, webpack, vite, tailwind-cli, atau framework MVC kompleks.
2. Selalu gunakan query PDO dengan parameter binding (`$stmt->prepare()` dan `$stmt->execute([':param' => $val])`).
3. Selalu validasi upload file: batasi hanya ekstensi `.pdf`, ukuran maksimal 5MB, dan sanitasi nama file menggunakan `uniqid()`.
4. Pertahankan styling sederhana berbasis class Bootstrap 5 (`card`, `table`, `btn`, `alert`, `badge`).
