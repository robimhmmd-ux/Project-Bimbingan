# PANDUAN INTEGRASI & DOKUMENTASI RESMI (UPDATE DATABASE & ALUR SISTEM)
> **Dokumen Resmi Arsitektur Database & Alur Kerja Terintegrasi**  
> **Target Pengguna**: Yoga (Lead Frontend), Qonita (Admin), Salsa (Dosen), Talita (Mahasiswa)  
> **Status Database Cloud**: Telah Diterapkan & Aktif di Cluster TiDB Serverless  

Dokumen ini adalah acuan resmi agar setiap anggota tim dapat menyesuaikan modul masing-masing tanpa mengubah struktur database. Database sudah dimutakhirkan langsung di cloud dan data akun seluruh kelas telah di-*seed*.

---

## 1. REKAPITULASI AKUN & KREDENSIAL LOGIN TERBARU

### A. Akun Administrator
* **ID Login**: `admin`
* **Password**: `password123`
* **Peran**: Mengelola data master, kuota dospem (maks 5 mhs/dosen), dan penjadwalan sidang (3 penguji).

### B. Akun Dosen Pembimbing & Penguji (9 Dosen)
Seluruh akun dosen menggunakan ID login nama pendek dan password default `password123`. Sesuai standar, **ID Login (`username`) bersifat permanen**, sedangkan **Nama Lengkap & Gelar (`nama_dosen`)** dapat diperbarui secara mandiri oleh dosen di menu profil.

| No | ID Login (`username`) | Password | Nama Lengkap & Gelar (`nama_dosen`) | NIDN | Kuota Maksimal |
|:--:|:---------------------:|:--------:|:-----------------------------------|:----:|:--------------:|
| 1 | `faqih` | `password123` | Ahmad Faqih, M.Sc. | 0415068801 | 5 Mahasiswa |
| 2 | `budi` | `password123` | Budi Darmawan, M.Kom. | 0419088702 | 5 Mahasiswa |
| 3 | `yuni` | `password123` | Yuni Astuti, S.T., M.Eng. | 0422038903 | 5 Mahasiswa |
| 4 | `agus` | `password123` | Dr. Agus Prasetyo, M.Cs. | 0405118404 | 5 Mahasiswa |
| 5 | `dewi` | `password123` | Dewi Kusuma, M.T. | 0418049005 | 5 Mahasiswa |
| 6 | `ridwan` | `password123` | Ridwan Hakim, M.Kom. | 0429128606 | 5 Mahasiswa |
| 7 | `fitri` | `password123` | Fitri Handayani, S.Kom., M.Kom. | 0414079107 | 5 Mahasiswa |
| 8 | `dosen1` | `password123` | Dr. Hendra Gunawan, M.T. | 0412058001 | 5 Mahasiswa |
| 9 | `dosen2` | `password123` | Siti Rahmawati, M.Kom. | 0420088502 | 5 Mahasiswa |

*(Setiap dosen telah dilengkapi jadwal ketersediaan bimbingan aktif di tabel `jadwal_dosen`).*

---

### C. Akun Mahasiswa (33 Mahasiswa - 3 Kelas)
* **ID Login (`username`)**: **NIM Masing-Masing**
* **Password**: **NIM Masing-Masing**

#### 📌 Kelas TI-1A-2025 (11 Mahasiswa)
1. `41257244` / `41257244` — Dhini Rahmawati
2. `41257239` / `41257239` — Haydar Rahman Al Ghifari
3. `41257172` / `41257172` — Khaerul Umam
4. `41257144` / `41257144` — Nasywa Gusni Kholifatul Mahwa
5. `41257145` / `41257145` — Niara Aprilia
6. `41257146` / `41257146` — Nihlah Auliya
7. `41257147` / `41257147` — Nizar Firmansyah
8. `41257148` / `41257148` — Novi Fitriani
9. `41257149` / `41257149` — Pria Budi Santoso
10. `41257150` / `41257150` — Putra Muhamad Rizky
11. `41257151` / `41257151` — Qonita Goniyun Ulum

#### 📌 Kelas TI-1B-2025 (11 Mahasiswa)
12. `41257240` / `41257240` — Rafi Erlangga Dwi Tama
13. `41257152` / `41257152` — Rauzzan Muhamad Fikri
14. `41257153` / `41257153` — Reyhandika Nanda Saputra
15. `41257154` / `41257154` — Rian Afriyansyah
16. `41257155` / `41257155` — Rijal
17. `41257156` / `41257156` — Robi Muhamad Wahyudi
18. `41257157` / `41257157` — Sairo
19. `41257158` / `41257158` — Salsa Nabila
20. `41257159` / `41257159` — Satria Fibi Saputra
21. `41257160` / `41257160` — Siti Masripatul Hawa
22. `41257241` / `41257241` — Siti Nur Fadillah

#### 📌 Kelas TI-1C-2025 (11 Mahasiswa)
23. `41257171` / `41257171` — Soleh
24. `41257161` / `41257161` — Sulistina Juliyanti
25. `41257163` / `41257163` — Syamsul Hadi
26. `41257242` / `41257242` — Talita Nabila
27. `41257165` / `41257165` — Ubai Datul Hamida
28. `41257166` / `41257166` — Virandha Tiara Safana
29. `41257167` / `41257167` — Wichal Aditiya Purnama
30. `41257168` / `41257168` — Winarti
31. `41257243` / `41257243` — Yogafratama Fahdi Sardi
32. `41257169` / `41257169` — Yurdika Fauzan Aziz Alviant
33. `41257170` / `41257170` — Dede Kuswara

---

## 2. STRUKTUR & PEMBARUAN SKEMA DATABASE (9 TABEL)

Database kini memiliki **9 tabel aktif**. Berikut rincian perubahan kolom dan tabel baru yang wajib diketahui:

### A. Tabel `mahasiswa` (Ada 2 Kolom Baru)
* **`kelas`** (`VARCHAR(20)`): Menyimpan kode kelas (contoh: `'TI-1A-2025'`).
* **`status_pembimbing`** (`ENUM('belum_diplot', 'menunggu_konfirmasi', 'aktif')`):
  * `belum_diplot`: Dosen pembimbing belum ditentukan admin.
  * `menunggu_konfirmasi`: Admin sudah menetapkan dospem, notifikasi dikirim ke mahasiswa.
  * `aktif`: Mahasiswa telah menekan tombol konfirmasi penerimaan SK.

### B. Tabel `pengajuan_bimbingan` (Ada 1 Kolom Baru)
* **`link_drive`** (`VARCHAR(255) NULL`): Menyimpan link Google Drive / Docs alternatif jika file tidak diunggah ke server lokal.

### C. Tabel Baru: `sidang_skripsi` (Pendaftaran, Jadwal & 3 Penguji)
Digunakan untuk menampung data sidang komprehensif / pendadaran mahasiswa:
```sql
CREATE TABLE IF NOT EXISTS sidang_skripsi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mahasiswa_id INT NOT NULL,
    dosen_pembimbing_id INT NOT NULL,
    penguji_1_id INT NULL,          -- Dosen Penguji 1 (Ketua Penguji)
    penguji_2_id INT NULL,          -- Dosen Penguji 2
    penguji_3_id INT NULL,          -- Dosen Penguji 3 (Sekretaris)
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
    ketentuan_sidang TEXT NULL,     -- Info dress code, syarat berkas cetak, PPT, dll.
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (mahasiswa_id) REFERENCES mahasiswa(id) ON DELETE CASCADE,
    FOREIGN KEY (dosen_pembimbing_id) REFERENCES dosen(id) ON DELETE CASCADE,
    FOREIGN KEY (penguji_1_id) REFERENCES dosen(id) ON DELETE SET NULL,
    FOREIGN KEY (penguji_2_id) REFERENCES dosen(id) ON DELETE SET NULL,
    FOREIGN KEY (penguji_3_id) REFERENCES dosen(id) ON DELETE SET NULL
);
```

### D. Tabel Baru: `notifikasi`
Menampung pemberitahuan otomatis ke mahasiswa atau dosen:
```sql
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
);
```

---

## 3. PANDUAN KERJA SETIAP ANGGOTA TIM

### 📌 1. Wilayah Admin (Qonita) - Folder `admin/`
1. **Filter Kelas pada Data Mahasiswa**: Tampilkan kolom `kelas` di tabel `data_mahasiswa.php`.
2. **Plotting Pembimbing (`plot_pembimbing.php`)**:
   - Batasi kuota setiap dosen maksimal **5 mahasiswa bimbingan aktif**.
   - Saat memasangkan dosen, update status:
     ```sql
     UPDATE mahasiswa 
     SET dosen_pembimbing_id = :dosen_id, status_pembimbing = 'menunggu_konfirmasi' 
     WHERE id = :mahasiswa_id;
     ```
   - Buat notifikasi otomatis ke user mahasiswa:
     ```sql
     INSERT INTO notifikasi (user_id, judul, pesan, tipe, link_tujuan) 
     VALUES (:mhs_user_id, 'Dosen Pembimbing Ditetapkan', 'Anda telah ditetapkan dibimbing oleh Dosen. Silakan konfirmasi di dashboard.', 'pembimbing', 'index.php');
     ```
3. **Penjadwalan Sidang Skripsi (Fitur Baru di Admin)**:
   - Admin memverifikasi mahasiswa yang berstatus `siap_sidang`.
   - Admin menetapkan: Tanggal, Jam, Ruangan, Ketentuan Sidang (dress code formal, rangkap naskah 3), serta memilih **3 Dosen Penguji** (`penguji_1_id`, `penguji_2_id`, `penguji_3_id`).
   - Masukkan ke tabel `sidang_skripsi` dan beri notifikasi ke mahasiswa & ketiga dosen penguji.

---

### 📌 2. Wilayah Dosen (Salsa) - Folder `dosen/`
1. **Pengaturan Profil (`profil.php`)**:
   - ID login (`users.username`) ditampilkan *readonly*.
   - Nama lengkap & gelar (`dosen.nama_dosen`) dapat diedit kapan saja oleh dosen.
2. **Review Pengajuan Bimbingan (`persetujuan.php`)**:
   - Jika mahasiswa melampirkan file PDF, sediakan tombol **Unduh Draft**.
   - Jika mahasiswa melampirkan link Google Drive (`pengajuan_bimbingan.link_drive`), tampilkan tombol **Buka di Google Drive** (`target="_blank"`).
3. **Pemberian Rekomendasi Sidang (`riwayat_bimbingan.php`)**:
   - Jika seluruh bab (Bab 1 s.d. 5) telah selesai dan logbook mencapai batas minimum, dosen dapat mengubah status mahasiswa menjadi `siap_sidang`.

---

### 📌 3. Wilayah Mahasiswa (Talita) - Folder `mahasiswa/`
1. **Notifikasi & Konfirmasi SK Pembimbing (`index.php`)**:
   - Jika `status_pembimbing == 'menunggu_konfirmasi'`, tampilkan banner / kartu pengumuman dosen pembimbing disertai tombol:
     **`[Konfirmasi Penerimaan SK & Siap Bimbingan]`**
   - Saat tombol diklik:
     ```sql
     UPDATE mahasiswa SET status_pembimbing = 'aktif' WHERE id = :mhs_id;
     ```
   - Setelah aktif, seluruh menu ajukan jadwal dan upload draft terbuka.
2. **Upload Draft / Link GDrive (`upload_draft.php`)**:
   - Sediakan 2 pilihan input: Unggah Berkas PDF (maks 5MB) **ATAU** Masukkan Tautan Link Google Drive / Docs.
3. **Halaman Progres & Informasi Sidang**:
   - Jika data mahasiswa sudah terdaftar di `sidang_skripsi` dengan status `'dijadwalkan'`, tampilkan kartu pengumuman sidang lengkap:
     * Tanggal & Jam Sidang
     * Ruangan Sidang
     * Dewan Penguji: Penguji 1, Penguji 2, dan Penguji 3
     * Tata Tertib & Ketentuan (Dress code, membawa slide presentasi, dll).

---

### 📌 4. Wilayah Frontend & Styling (Yoga) - Folder `templates/` & `assets/`
1. Sediakan badge CSS untuk status baru:
   - `.badge-sidang`: Hijau zamrud / Indigo untuk status jadwal sidang.
   - `.badge-konfirmasi`: Kuning hangat untuk status menunggu konfirmasi SK.
2. Pastikan tabel jadwal sidang di halaman cetak laporan (`cetak_laporan.php`) memiliki format siap cetak (`@media print`) yang bersih dan rapi.

---

## 4. CONTOH QUERY PDO SIAP PAKAI

```php
// Mengambil data jadwal sidang aktif mahasiswa
$stmt_sidang = $pdo->prepare("
    SELECT s.*, 
           d_pem.nama_dosen AS dospem,
           d_p1.nama_dosen  AS penguji_1,
           d_p2.nama_dosen  AS penguji_2,
           d_p3.nama_dosen  AS penguji_3
    FROM sidang_skripsi s
    LEFT JOIN dosen d_pem ON s.dosen_pembimbing_id = d_pem.id
    LEFT JOIN dosen d_p1  ON s.penguji_1_id = d_p1.id
    LEFT JOIN dosen d_p2  ON s.penguji_2_id = d_p2.id
    LEFT JOIN dosen d_p3  ON s.penguji_3_id = d_p3.id
    WHERE s.mahasiswa_id = :mid
    ORDER BY s.id DESC LIMIT 1
");
$stmt_sidang->execute([':mid' => $mahasiswa_id]);
$info_sidang = $stmt_sidang->fetch();
```

---
*Dokumen ini disusun untuk memastikan keselarasan kerja tim. Silakan lakukan `git pull origin main` secara berkala sebelum memulai pekerjaan.*
