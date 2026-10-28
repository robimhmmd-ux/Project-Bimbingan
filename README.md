# Sistem Informasi Bimbingan Skripsi & Laporan Progres Mahasiswa
> Proyek Akhir Mata Kuliah: **Pemrograman Web Dasar**  
> Teknologi: **PHP Native (PDO) + TiDB Serverless (MySQL Cloud) + Bootstrap 5 CDN**  
> Struktur Tim: **Yoga (Ketua Kelompok)** & **Robi (Koordinator Teknis & Database)**

---

## 🚀 Panduan Memulai Proyek untuk Tim (Yoga, Robi, Qonita, Salsa, Talita)

### 1. Pertama Kali Mengambil Kode (Clone - Hanya Dilakukan 1x)
Buka terminal / Command Prompt di laptop masing-masing:
```bash
git clone https://github.com/robimhmmd-ux/Project-Bimbingan.git
cd Project-Bimbingan
```

### 2. Aturan Kerja Harian (SOP Wajib)
Sebelum mulai koding di laptop masing-masing:
```bash
# 1. Selalu tarik kode terbaru dari GitHub teman
git pull origin main

# 2. Buka folder kerjaan Anda masing-masing:
# - Yoga   : Ketua Kelompok & Lead Frontend (folder templates/ dan assets/)
# - Robi   : Koordinator Teknis & Database (config/, login.php, index.php)
# - Qonita : Modul Administrator (folder admin/)
# - Salsa  : Modul Dosen Pembimbing (folder dosen/)
# - Talita : Modul Mahasiswa (folder mahasiswa/)

# 3. Setelah selesai koding, simpan dan upload:
git add .
git commit -m "feat(modul): update fitur oleh nama"
git push origin main
```

---

## 🗄️ Database TiDB Cloud
Koneksi database sudah diatur di `config/database.php` terhubung ke cluster TiDB Serverless.  
Data awal pengujian:
- **Admin**: `username: admin` | `password: password123`
- **Dosen**: `username: dosen1` | `password: password123`
- **Mahasiswa**: `username: mhs1` | `password: password123`

---

## 🤖 Menggunakan Antigravity IDE
Saat meminta bantuan kodingan ke Antigravity AI:
Sematkan berkas `KONTEKS_PROYEK_ANTIGRAVITY.md` agar AI tetap berada di koridor PHP Native dan skema tabel resmi.
