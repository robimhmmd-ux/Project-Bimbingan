<?php
// mahasiswa/index.php - Dashboard Mahasiswa & Pencatatan Progres Skripsi Terpadu
$page_title   = "Dashboard Mahasiswa";
$current_page = "dashboard";

require_once '../config/database.php';
require_once '../templates/header.php';
require_once '../templates/sidebar_mahasiswa.php';

// Proteksi akses role mahasiswa
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'mahasiswa') {
    header("Location: ../login");
    exit;
}

$success_msg = '';
$error_msg   = '';

// Ambil data profil mahasiswa beserta info dosen pembimbingnya
$stmt_mhs = $pdo->prepare("
    SELECT m.*, d.id AS dosen_id, d.user_id AS dosen_user_id, d.nidn, d.nama_dosen, 
           d.email AS email_dosen, d.no_whatsapp, d.foto_profil AS foto_dosen, 
           d.kuota_bimbingan, u_dos.username AS username_dosen
    FROM mahasiswa m 
    LEFT JOIN dosen d ON m.dosen_pembimbing_id = d.id 
    LEFT JOIN users u_dos ON d.user_id = u_dos.id
    WHERE m.user_id = :uid LIMIT 1
");
$stmt_mhs->execute([':uid' => $_SESSION['user_id']]);
$mhs = $stmt_mhs->fetch();

$mhs_id = $mhs['id'] ?? 0;
$dosen_pembimbing_id = $mhs['dosen_pembimbing_id'] ?? null;
$status_pembimbing = $mhs['status_pembimbing'] ?? 'belum_diplot';

// Ambil jadwal ketersediaan konsultasi dosen pembimbing
$jadwal_dosen_list = [];
if (!empty($dosen_pembimbing_id)) {
    try {
        $st_jd = $pdo->prepare("
            SELECT hari, jam_mulai, jam_selesai, status_aktif 
            FROM jadwal_dosen 
            WHERE dosen_id = :did AND status_aktif = 'aktif'
            ORDER BY FIELD(hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu')
        ");
        $st_jd->execute([':did' => $dosen_pembimbing_id]);
        $jadwal_dosen_list = $st_jd->fetchAll();
    } catch (PDOException $e) {
        $jadwal_dosen_list = [];
    }
}

// Handle Konfirmasi Penerimaan SK Dosen Pembimbing (UPDATE Robi)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'konfirmasi_sk') {
    if ($mhs_id && $status_pembimbing === 'menunggu_konfirmasi') {
        try {
            $stmt_konfir = $pdo->prepare("UPDATE mahasiswa SET status_pembimbing = 'aktif' WHERE id = :mid");
            $stmt_konfir->execute([':mid' => $mhs_id]);

            // Catat notifikasi otomatis
            $stmt_notif = $pdo->prepare("
                INSERT INTO notifikasi (user_id, judul, pesan, tipe, link_tujuan) 
                VALUES (:uid, 'SK Dosen Pembimbing Diterima', 'Anda telah mengonfirmasi penerimaan SK pembimbing. Seluruh fitur pengajuan bimbingan kini aktif.', 'sukses', 'index.php')
            ");
            $stmt_notif->execute([':uid' => $_SESSION['user_id']]);

            $success_msg = "Selamat! Penerimaan SK Dosen Pembimbing berhasil dikonfirmasi. Anda kini dapat melihat jadwal bimbingan dan mengunggah draf skripsi.";
            
            // Refresh data mhs
            $stmt_mhs->execute([':uid' => $_SESSION['user_id']]);
            $mhs = $stmt_mhs->fetch();
            $status_pembimbing = $mhs['status_pembimbing'] ?? 'aktif';
        } catch (PDOException $e) {
            $error_msg = "Gagal mengonfirmasi SK: " . $e->getMessage();
        }
    }
}

// Handle Pengajuan Progres Mahasiswa (Judul s.d. ACC Sidang)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_ajukan_progres'])) {
    if (!$dosen_pembimbing_id || $status_pembimbing !== 'aktif') {
        $error_msg = "Anda belum dapat mengajukan progres karena SK Dosen Pembimbing belum aktif. Silakan konfirmasi SK terlebih dahulu.";
    } else {
        $tahap_key      = trim($_POST['tahap_key'] ?? '');
        $catatan_mhs    = trim($_POST['catatan_mahasiswa'] ?? '');
        $link_drive     = trim($_POST['link_drive'] ?? '');
        $judul_diajukan = trim($_POST['judul_skripsi_baru'] ?? '');
        $tanggal_bg     = trim($_POST['tanggal_bimbingan'] ?? date('Y-m-d'));
        $jam_bg         = trim($_POST['jam_bimbingan'] ?? date('H:i:s'));
        $file_draft_name   = null;
        $file_draft_base64 = null;

        $valid_tahap = [
            'judul'  => 'Pengajuan Judul Skripsi',
            'bab1'   => 'Bab 1: Pendahuluan',
            'bab2'   => 'Bab 2: Tinjauan Pustaka',
            'bab3'   => 'Bab 3: Metodologi Penelitian',
            'bab4'   => 'Bab 4: Hasil & Pembahasan',
            'bab5'   => 'Bab 5: Kesimpulan & Saran',
            'sidang' => 'ACC Kelayakan Sidang Skripsi'
        ];

        if (!array_key_exists($tahap_key, $valid_tahap)) {
            $error_msg = "Tahap progres yang dipilih tidak valid.";
        } elseif ($tahap_key === 'judul' && empty($judul_diajukan)) {
            $error_msg = "Rencana judul skripsi yang diajukan tidak boleh kosong.";
        } elseif (empty($catatan_mhs)) {
            $error_msg = "Catatan atau ringkasan progres wajib diisi.";
        } else {
            $nama_tahap = $valid_tahap[$tahap_key];

            // Validasi format link Google Drive jika diisi
            if (!empty($link_drive) && !filter_var($link_drive, FILTER_VALIDATE_URL)) {
                $error_msg = "Format tautan Google Drive / Docs tidak valid (harus diawali https://).";
            }

            // Handle file upload PDF
            if (empty($error_msg) && isset($_FILES['file_draft']) && $_FILES['file_draft']['error'] === UPLOAD_ERR_OK) {
                $f_tmp  = $_FILES['file_draft']['tmp_name'];
                $f_name = $_FILES['file_draft']['name'];
                $f_size = $_FILES['file_draft']['size'];
                $f_ext  = strtolower(pathinfo($f_name, PATHINFO_EXTENSION));

                $allowed_exts = ($tahap_key === 'judul') ? ['pdf', 'doc', 'docx'] : ['pdf'];
                if (!in_array($f_ext, $allowed_exts)) {
                    $error_msg = ($tahap_key === 'judul') 
                        ? "Berkas dokumen usulan judul harus berformat PDF (.pdf) atau Word (.doc, .docx)." 
                        : "Berkas lampiran draf skripsi harus berformat PDF (.pdf).";
                } elseif ($f_size > 10 * 1024 * 1024) {
                    $error_msg = "Ukuran file berkas PDF maksimal 5 MB.";
                } else {
                    $raw_pdf = file_get_contents($f_tmp);
                    if ($raw_pdf !== false) {
                        $clean_base = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($f_name, PATHINFO_FILENAME));
                        $new_name   = 'progres_' . ($mhs['nim'] ?? 'mhs') . '_' . $tahap_key . '_' . time() . '.' . $f_ext;
                        $file_draft_name   = $new_name;
                        $file_draft_base64 = base64_encode($raw_pdf);

                        // Coba simpan salinan lokal jika direktori dapat ditulisi
                        $upload_dir = '../assets/uploads/';
                        if (!is_dir($upload_dir)) {
                            @mkdir($upload_dir, 0777, true);
                        }
                        @move_uploaded_file($f_tmp, $upload_dir . $new_name);
                    } else {
                        $error_msg = "Gagal membaca berkas PDF yang diunggah.";
                    }
                }
            }

            if (empty($error_msg)) {
                try {
                    $pdo->beginTransaction();

                    // Update judul jika mengajukan judul
                    if ($tahap_key === 'judul' && !empty($judul_diajukan)) {
                        $st_uj = $pdo->prepare("UPDATE mahasiswa SET judul_skripsi = :jdl WHERE id = :mid");
                        $st_uj->execute([':jdl' => $judul_diajukan, ':mid' => $mhs_id]);
                        $catatan_mhs = "Rencana Judul: " . $judul_diajukan . ($catatan_mhs ? " | Catatan: " . $catatan_mhs : "");
                    }

                    // Bersihkan berkas draf revisi lama jika melebihi kuota retensi (maks 2 file terbaru)
                    if (!empty($file_draft_name) && function_exists('cleanup_old_draft_files')) {
                        cleanup_old_draft_files($pdo, $mhs_id, $nama_tahap, 2);
                    }

                    // Simpan ke pengajuan_bimbingan
                    $st_in = $pdo->prepare("
                        INSERT INTO pengajuan_bimbingan 
                        (mahasiswa_id, dosen_id, tanggal_bimbingan, jam_bimbingan, bab_pembahasan, catatan_mahasiswa, file_draft, file_draft_data, link_drive, status, nilai_progres, created_at)
                        VALUES (:mid, :did, :tgl, :jam, :bab, :cat, :fld, :fld_data, :lnk, 'menunggu', 0, NOW())
                    ");
                    $st_in->execute([
                        ':mid'      => $mhs_id,
                        ':did'      => $dosen_pembimbing_id,
                        ':tgl'      => $tanggal_bg,
                        ':jam'      => $jam_bg,
                        ':bab'      => $nama_tahap,
                        ':cat'      => $catatan_mhs,
                        ':fld'      => $file_draft_name,
                        ':fld_data' => $file_draft_base64,
                        ':lnk'      => !empty($link_drive) ? $link_drive : null
                    ]);

                    // Kirim notifikasi ke dosen
                    if (!empty($mhs['dosen_user_id'])) {
                        $nama_m = $mhs['nama_lengkap'] ?? 'Mahasiswa';
                        $nim_m  = $mhs['nim'] ?? '';
                        $st_nd = $pdo->prepare("
                            INSERT INTO notifikasi (user_id, judul, pesan, tipe, link_tujuan)
                            VALUES (:uid, :jdl, :psn, 'pembimbing', 'riwayat_bimbingan.php')
                        ");
                        $st_nd->execute([
                            ':uid' => $mhs['dosen_user_id'],
                            ':jdl' => 'Pengajuan Progres Baru',
                            ':psn' => "Mahasiswa {$nama_m} ({$nim_m}) mengajukan permohonan evaluasi progres: '{$nama_tahap}'. Silakan berikan keputusan ACC."
                        ]);
                    }

                    // Kirim notifikasi ke mahasiswa sendiri
                    if (!empty($_SESSION['user_id'])) {
                        $st_nm = $pdo->prepare("
                            INSERT INTO notifikasi (user_id, judul, pesan, tipe, link_tujuan)
                            VALUES (:uid, :jdl, :psn, 'info', 'index.php')
                        ");
                        $st_nm->execute([
                            ':uid' => $_SESSION['user_id'],
                            ':jdl' => 'Pengajuan Progres Terkirim',
                            ':psn' => "Permohonan progres '{$nama_tahap}' berhasil diajukan ke Dosen Pembimbing. Menunggu keputusan evaluasi (ACC)."
                        ]);
                    }

                    $pdo->commit();

                    $success_msg = "Pengajuan progres '{$nama_tahap}' berhasil dikirim ke Dosen Pembimbing! Silakan pantau status evaluasi dan ACC di halaman ini.";

                    // Refresh data profil mahasiswa
                    $stmt_mhs->execute([':uid' => $_SESSION['user_id']]);
                    $mhs = $stmt_mhs->fetch();

                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error_msg = "Gagal memproses pengajuan progres: " . $e->getMessage();
                }
            }
        }
    }
}


// Ambil Informasi Jadwal Sidang Skripsi Mahasiswa (UPDATE Robi: tabel sidang_skripsi)
$info_sidang = null;
if ($mhs_id) {
    try {
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
        $stmt_sidang->execute([':mid' => $mhs_id]);
        $info_sidang = $stmt_sidang->fetch();
    } catch (PDOException $e) {
        $info_sidang = null;
    }
}

// Ambil Notifikasi Aktif Mahasiswa (Gunakan cache dari header.php agar hemat query)
$daftar_notif = $notif_list ?? [];

// Hitung statistik bimbingan
$total_bimbingan    = 0;
$bimbingan_acc      = 0;
$bimbingan_revisi   = 0;
$bimbingan_menunggu = 0;
$rata_nilai         = 0;

if ($mhs_id) {
    // Total pengajuan
    $stmt_stat = $pdo->prepare("
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN status IN ('disetujui', 'selesai') THEN 1 ELSE 0 END) AS acc,
            SUM(CASE WHEN status = 'revisi' THEN 1 ELSE 0 END) AS revisi,
            SUM(CASE WHEN status = 'menunggu' THEN 1 ELSE 0 END) AS menunggu,
            AVG(NULLIF(nilai_progres, 0)) AS avg_nilai
        FROM pengajuan_bimbingan 
        WHERE mahasiswa_id = :mid
    ");
    $stmt_stat->execute([':mid' => $mhs_id]);
    $stat = $stmt_stat->fetch();

    $total_bimbingan    = (int)($stat['total'] ?? 0);
    $bimbingan_acc      = (int)($stat['acc'] ?? 0);
    $bimbingan_revisi   = (int)($stat['revisi'] ?? 0);
    $bimbingan_menunggu = (int)($stat['menunggu'] ?? 0);
    $rata_nilai         = round((float)($stat['avg_nilai'] ?? 0));

    // Periksa status per bab (Bab 1 s/d Bab 5)
    $stmt_bab = $pdo->prepare("
        SELECT bab_pembahasan, status, nilai_progres 
        FROM pengajuan_bimbingan 
        WHERE mahasiswa_id = :mid 
        ORDER BY id DESC
    ");
    $stmt_bab->execute([':mid' => $mhs_id]);
    $riwayat_semua = $stmt_bab->fetchAll();

    // Riwayat 5 bimbingan terbaru
    $stmt_recent = $pdo->prepare("
        SELECT * FROM pengajuan_bimbingan 
        WHERE mahasiswa_id = :mid 
        ORDER BY tanggal_bimbingan DESC, jam_bimbingan DESC 
        LIMIT 5
    ");
    $stmt_recent->execute([':mid' => $mhs_id]);
    $recent_bimbingan = $stmt_recent->fetchAll();
} else {
    $riwayat_semua = [];
    $recent_bimbingan = [];
}

// Analisis progres bertahap dari Pengajuan Judul sampai Selesai / Siap Sidang
$tahapan_progres = [
    'judul' => [
        'nama'   => 'Judul Skripsi',
        'label'  => 'Pengajuan Judul',
        'status' => 'belum',
        'icon'   => 'bi-bookmark-check'
    ],
    'bab1' => [
        'nama'   => 'Bab 1',
        'label'  => 'Bab 1: Pendahuluan',
        'status' => 'belum',
        'icon'   => 'bi-file-earmark-text'
    ],
    'bab2' => [
        'nama'   => 'Bab 2',
        'label'  => 'Bab 2: Tinjauan Pustaka',
        'status' => 'belum',
        'icon'   => 'bi-journal-richtext'
    ],
    'bab3' => [
        'nama'   => 'Bab 3',
        'label'  => 'Bab 3: Metodologi Penelitian',
        'status' => 'belum',
        'icon'   => 'bi-gear-wide-connected'
    ],
    'bab4' => [
        'nama'   => 'Bab 4',
        'label'  => 'Bab 4: Hasil & Pembahasan',
        'status' => 'belum',
        'icon'   => 'bi-bar-chart-steps'
    ],
    'bab5' => [
        'nama'   => 'Bab 5',
        'label'  => 'Bab 5: Kesimpulan & Saran',
        'status' => 'belum',
        'icon'   => 'bi-award'
    ],
    'sidang' => [
        'nama'   => 'Sidang Skripsi',
        'label'  => 'ACC Kelayakan Sidang',
        'status' => 'belum',
        'icon'   => 'bi-mortarboard'
    ],
];

// Cek status pengesahan judul skripsi dari helper resmi (hanya 'acc' jika sudah di-ACC dosen di logbook)
$info_progres_dash = get_progres_mahasiswa($pdo, $mhs_id);
if ($info_progres_dash && $info_progres_dash['is_judul_acc']) {
    $tahapan_progres['judul']['status'] = 'acc';
}

// Analisis pengajuan dari riwayat bimbingan
$map_bab_str = [
    'bab1' => 'Bab 1',
    'bab2' => 'Bab 2',
    'bab3' => 'Bab 3',
    'bab4' => 'Bab 4',
    'bab5' => 'Bab 5',
];

foreach ($riwayat_semua as $row) {
    // 1. Cek Judul
    if (stripos($row['bab_pembahasan'], 'Judul') !== false) {
        if (in_array($row['status'], ['selesai', 'disetujui'])) {
            $tahapan_progres['judul']['status'] = 'acc';
        } elseif ($row['status'] === 'revisi' && $tahapan_progres['judul']['status'] !== 'acc') {
            $tahapan_progres['judul']['status'] = 'revisi';
        } elseif ($row['status'] === 'menunggu' && $tahapan_progres['judul']['status'] !== 'acc') {
            $tahapan_progres['judul']['status'] = 'proses';
        }
    }

    // 2. Cek Bab 1 s.d. 5
    foreach ($map_bab_str as $b_key => $b_str) {
        if (stripos($row['bab_pembahasan'], $b_str) !== false) {
            if (in_array($row['status'], ['selesai', 'disetujui']) && $tahapan_progres[$b_key]['status'] !== 'acc') {
                $tahapan_progres[$b_key]['status'] = 'acc';
            } elseif ($row['status'] === 'revisi' && $tahapan_progres[$b_key]['status'] === 'belum') {
                $tahapan_progres[$b_key]['status'] = 'revisi';
            } elseif ($row['status'] === 'menunggu' && $tahapan_progres[$b_key]['status'] === 'belum') {
                $tahapan_progres[$b_key]['status'] = 'proses';
            }
        }
    }

    // 3. Cek Sidang
    if (stripos($row['bab_pembahasan'], 'Sidang') !== false) {
        if (in_array($row['status'], ['selesai', 'disetujui'])) {
            $tahapan_progres['sidang']['status'] = 'acc';
        } elseif ($row['status'] === 'menunggu' && $tahapan_progres['sidang']['status'] !== 'acc') {
            $tahapan_progres['sidang']['status'] = 'proses';
        }
    }
}

// Cek kelulusan di tabel mahasiswa
if (($mhs['status_kelulusan'] ?? '') === 'lulus') {
    $tahapan_progres['sidang']['status'] = 'acc';
} elseif (($mhs['status_kelulusan'] ?? '') === 'siap_sidang') {
    $tahapan_progres['sidang']['status'] = 'acc';
}

// Tentukan tahap yang aktif / harus diajukan berikutnya secara berurutan
$tahap_berikutnya = null;
foreach ($tahapan_progres as $k_stp => $v_stp) {
    if ($v_stp['status'] !== 'acc') {
        $tahap_berikutnya = $k_stp;
        break;
    }
}
if (!$tahap_berikutnya) {
    $tahap_berikutnya = 'sidang';
}

// Hitung persentase progres akumulatif
$total_tahap_acc = 0;
foreach ($tahapan_progres as $tp) {
    if ($tp['status'] === 'acc') $total_tahap_acc++;
}
$persen_progres = (int)round(($total_tahap_acc / count($tahapan_progres)) * 100);

// Tentukan tahap yang aktif / harus diajukan berikutnya secara berurutan
$tahap_berikutnya = null;
foreach ($tahapan_progres as $k_stp => $v_stp) {
    if ($v_stp['status'] !== 'acc') {
        $tahap_berikutnya = $k_stp;
        break;
    }
}
if (!$tahap_berikutnya) {
    $tahap_berikutnya = 'sidang';
}

// Hitung persentase progres akumulatif
$total_tahap_acc = 0;
foreach ($tahapan_progres as $tp) {
    if ($tp['status'] === 'acc') $total_tahap_acc++;
}
$persen_progres = (int)round(($total_tahap_acc / count($tahapan_progres)) * 100);
if (($mhs['status_kelulusan'] ?? '') === 'lulus') {
    $persen_progres = 100;
} elseif (($mhs['status_kelulusan'] ?? '') === 'siap_sidang' && $persen_progres < 90) {
    $persen_progres = 90;
}

// Backward compatibility untuk bab_list
$bab_list = [
    'Bab 1' => ['label' => 'Bab 1: Pendahuluan', 'status' => $tahapan_progres['bab1']['status']],
    'Bab 2' => ['label' => 'Bab 2: Tinjauan Pustaka', 'status' => $tahapan_progres['bab2']['status']],
    'Bab 3' => ['label' => 'Bab 3: Metodologi Penelitian', 'status' => $tahapan_progres['bab3']['status']],
    'Bab 4' => ['label' => 'Bab 4: Hasil & Pembahasan', 'status' => $tahapan_progres['bab4']['status']],
    'Bab 5' => ['label' => 'Bab 5: Kesimpulan & Saran', 'status' => $tahapan_progres['bab5']['status']],
];
?>

<!-- ============================================================
     HERO BANNER MAHASISWA MODERN & BERWARNA
============================================================ -->
<div class="student-hero-banner">
    <div class="row align-items-center g-4 position-relative" style="z-index: 2;">
        <!-- Sisi Kiri: Sapaan & Identitas Mahasiswa (Luas & Rapi) -->
        <div class="col-lg-6 col-xl-7">
            <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                <span class="badge-kelas">
                    <i class="bi bi-mortarboard-fill"></i><?= htmlspecialchars($mhs['kelas'] ?? 'TI-1A-2025'); ?>
                </span>
                <span class="badge bg-white bg-opacity-25 text-white border border-white border-opacity-25 px-2.5 py-1 small rounded-pill">
                    <i class="bi bi-bookmark-star-fill me-1"></i><?= htmlspecialchars($mhs['prodi'] ?? 'Teknik Informatika'); ?>
                </span>
                <span class="badge bg-white bg-opacity-25 text-white border border-white border-opacity-25 px-2.5 py-1 small rounded-pill">
                    <i class="bi bi-person-fill me-1"></i>Portal Mahasiswa
                </span>
            </div>
            <h2 class="fw-bold text-white mb-2" style="font-size: 1.65rem; line-height: 1.3;">
                Selamat Datang Kembali, <?= htmlspecialchars($mhs['nama_lengkap'] ?? $_SESSION['username']); ?>!
            </h2>
            <p class="text-white-50 small mb-0" style="line-height: 1.6; max-width: 580px;">
                NIM: <strong class="text-white"><?= htmlspecialchars($mhs['nim'] ?? '-'); ?></strong> &bull; Pantau progres bimbingan, jadwal konsultasi, dan kesiapan ujian sidang tugas akhir Anda.
            </p>
        </div>

        <!-- Sisi Kanan: Tombol Aksi Cepat Tertata Rapi & Tidak Terpotong -->
        <div class="col-lg-6 col-xl-5">
            <div class="d-flex flex-wrap gap-2 justify-content-lg-end align-items-center hero-action-buttons">
                <?php if ($status_pembimbing === 'aktif'): ?>
                    <button type="button" class="btn btn-hero-action btn-hero-emerald shadow-sm" data-bs-toggle="modal" data-bs-target="#modalAjukanProgres">
                        <i class="bi bi-send-check-fill"></i>Pengajuan Progres
                    </button>
                    <a href="upload_draft.php" class="btn btn-hero-action btn-hero-white">
                        <i class="bi bi-cloud-arrow-up"></i>Upload Berkas
                    </a>
                    <a href="jadwal_bimbingan.php" class="btn btn-hero-action btn-hero-blue">
                        <i class="bi bi-calendar-week"></i>Jadwal Bimbingan
                    </a>
                    <a href="chat.php" class="btn btn-hero-action btn-hero-outline">
                        <i class="bi bi-chat-dots-fill"></i>Chat Dosen
                    </a>
                    <a href="cetak_laporan.php" target="_blank" class="btn btn-hero-action btn-hero-outline">
                        <i class="bi bi-printer-fill"></i>Cetak Laporan
                    </a>
                <?php else: ?>
                    <button class="btn btn-hero-action btn-hero-disabled" title="Konfirmasi SK terlebih dahulu di banner bawah">
                        <i class="bi bi-lock-fill"></i>Pengajuan Progres
                    </button>
                    <button class="btn btn-hero-action btn-hero-disabled" title="Konfirmasi SK terlebih dahulu di banner bawah">
                        <i class="bi bi-lock-fill"></i>Jadwal Bimbingan
                    </button>
                    <a href="cetak_laporan.php" target="_blank" class="btn btn-hero-action btn-hero-outline">
                        <i class="bi bi-printer-fill"></i>Cetak Laporan
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($success_msg)): ?>
    <div class="alert alert-success alert-dismissible fade show py-2 small shadow-sm" role="alert">
        <i class="bi bi-check-circle-fill me-1"></i><?= htmlspecialchars($success_msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!empty($error_msg)): ?>
    <div class="alert alert-danger alert-dismissible fade show py-2 small shadow-sm" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-1"></i><?= htmlspecialchars($error_msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- ============================================================
     BANNER STATUS SK PEMBIMBING (UPDATE ROBI)
============================================================ -->
<?php if ($status_pembimbing === 'menunggu_konfirmasi'): ?>
    <div class="card sk-banner-card mb-4 border-0">
        <div class="card-body p-4">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="sk-seal-icon">
                        <i class="bi bi-award-fill"></i>
                    </div>
                    <div>
                        <span class="badge-konfirmasi mb-1 d-inline-block"><i class="bi bi-patch-check-fill me-1"></i>PENETAPAN RESMI ADMIN</span>
                        <h5 class="fw-bold text-dark mb-1">Surat Keputusan (SK) Dosen Pembimbing Telah Terbit!</h5>
                        <p class="text-muted small mb-0">
                            Admin Program Studi telah menetapkan <strong><?= htmlspecialchars($mhs['nama_dosen'] ?? 'Dosen'); ?></strong> (NIDN: <?= htmlspecialchars($mhs['nidn'] ?? '-'); ?>) sebagai dosen pembimbing tugas akhir Anda. Silakan konfirmasi penerimaan SK untuk membuka fitur pengajuan bimbingan & upload draf.
                        </p>
                    </div>
                </div>
                <form action="index.php" method="POST" class="flex-shrink-0">
                    <input type="hidden" name="action" value="konfirmasi_sk">
                    <button type="submit" class="btn-konfirmasi-sk" onclick="return confirm('Apakah Anda menyetujui dan siap melaksanakan bimbingan dengan dosen pembimbing yang telah ditetapkan?')">
                        <i class="bi bi-check2-circle fs-5"></i>Konfirmasi Penerimaan SK & Siap Bimbingan
                    </button>
                </form>
            </div>
        </div>
    </div>
<?php elseif ($status_pembimbing === 'belum_diplot'): ?>
    <div class="alert alert-info d-flex align-items-center gap-2 mb-4 shadow-sm" role="alert">
        <i class="bi bi-info-circle-fill fs-5"></i>
        <div>
            <strong>Status Dosen Pembimbing:</strong> Admin program studi belum menetapkan dosen pembimbing untuk Anda. Anda akan menerima notifikasi otomatis ketika SK penetapan pembimbing telah diterbitkan.
        </div>
    </div>
<?php endif; ?>

<!-- ============================================================
     KARTU PENGUMUMAN JADWAL SIDANG SKRIPSI (UPDATE ROBI: sidang_skripsi)
============================================================ -->
<?php if ($info_sidang): ?>
    <div class="card sidang-card-wrapper shadow-sm mb-4 border-0 overflow-hidden">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
            <div class="d-flex align-items-center gap-2">
                <div class="bg-indigo-subtle text-primary p-2 rounded">
                    <i class="bi bi-mortarboard fs-5"></i>
                </div>
                <div>
                    <h6 class="m-0 fw-bold text-dark">Informasi Jadwal Sidang Skripsi</h6>
                    <small class="text-muted">Dewan Penguji & Ketentuan Sidang Komprehensif</small>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <?php
                $st_sidang = $info_sidang['status_sidang'] ?? 'pengajuan';
                if ($st_sidang === 'dijadwalkan') {
                    echo '<span class="badge-sidang-indigo"><i class="bi bi-calendar-check me-1"></i>Jadwal Ditetapkan</span>';
                } elseif ($st_sidang === 'lulus') {
                    echo '<span class="badge-sidang"><i class="bi bi-trophy-fill me-1"></i>Lulus Sidang</span>';
                } elseif ($st_sidang === 'revisi') {
                    echo '<span class="badge-konfirmasi"><i class="bi bi-arrow-repeat me-1"></i>Revisi Sidang</span>';
                } elseif ($st_sidang === 'tidak_lulus') {
                    echo '<span class="badge bg-danger">Tidak Lulus</span>';
                } else {
                    echo '<span class="badge-konfirmasi"><i class="bi bi-clock me-1"></i>Menunggu Penjadwalan</span>';
                }
                ?>
                <a href="informasi_sidang.php" class="btn btn-outline-primary btn-sm ms-2">
                    <i class="bi bi-arrow-right-circle me-1"></i>Detail Sidang
                </a>
            </div>
        </div>
        <div class="card-body p-4 bg-light bg-opacity-25">
            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="p-3 bg-white rounded border shadow-sm h-100">
                        <span class="text-muted small text-uppercase fw-bold d-block mb-1">Judul Skripsi Final:</span>
                        <h6 class="fw-bold text-dark mb-3">"<?= htmlspecialchars($info_sidang['judul_final'] ?? $mhs['judul_skripsi']); ?>"</h6>

                        <div class="row g-2 pt-2 border-top">
                            <div class="col-6">
                                <span class="text-muted small d-block">Waktu Pelaksanaan:</span>
                                <strong class="text-dark small">
                                    <i class="bi bi-calendar-event text-primary me-1"></i>
                                    <?= !empty($info_sidang['tanggal_sidang']) ? date('d M Y', strtotime($info_sidang['tanggal_sidang'])) : 'Menunggu Jadwal'; ?>
                                </strong>
                            </div>
                            <div class="col-6">
                                <span class="text-muted small d-block">Jam Sidang:</span>
                                <strong class="text-dark small">
                                    <i class="bi bi-clock text-primary me-1"></i>
                                    <?= !empty($info_sidang['jam_sidang']) ? substr($info_sidang['jam_sidang'], 0, 5) . ' WIB' : '-'; ?>
                                </strong>
                            </div>
                            <div class="col-12 mt-2">
                                <span class="text-muted small d-block">Ruangan Sidang:</span>
                                <span class="badge bg-secondary px-2 py-1">
                                    <i class="bi bi-geo-alt-fill me-1"></i><?= htmlspecialchars($info_sidang['ruangan'] ?? 'Ruang Sidang Utama'); ?>
                                </span>
                            </div>
                        </div>

                        <?php if ($info_sidang['nilai_akhir'] !== null): ?>
                            <div class="mt-3 p-2 bg-success bg-opacity-10 border border-success rounded text-success text-center">
                                <span class="small fw-semibold d-block">Nilai Akhir Sidang:</span>
                                <span class="fs-4 fw-bold"><?= number_format((float)$info_sidang['nilai_akhir'], 2); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="p-3 bg-white rounded border shadow-sm h-100">
                        <span class="text-muted small text-uppercase fw-bold d-block mb-2">
                            <i class="bi bi-people-fill text-primary me-1"></i>Dewan Penguji & Pembimbing:
                        </span>
                        
                        <div class="d-flex flex-column gap-2 mb-3">
                            <div class="penguji-chip">
                                <div class="penguji-avatar ketua">
                                    <i class="bi bi-star-fill"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <span class="text-warning-emphasis d-block" style="font-size: 0.72rem; text-transform: uppercase; font-weight: 700;">Ketua Penguji (Penguji 1)</span>
                                    <strong class="text-dark small"><?= htmlspecialchars($info_sidang['penguji_1'] ?? 'Akan Ditetapkan'); ?></strong>
                                </div>
                            </div>
                            <div class="penguji-chip">
                                <div class="penguji-avatar" style="background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);">
                                    <i class="bi bi-person-fill"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <span class="text-primary d-block" style="font-size: 0.72rem; text-transform: uppercase; font-weight: 700;">Dosen Penguji 2</span>
                                    <strong class="text-dark small"><?= htmlspecialchars($info_sidang['penguji_2'] ?? 'Akan Ditetapkan'); ?></strong>
                                </div>
                            </div>
                            <div class="penguji-chip">
                                <div class="penguji-avatar" style="background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);">
                                    <i class="bi bi-person-fill"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <span class="text-purple d-block" style="font-size: 0.72rem; text-transform: uppercase; font-weight: 700;">Sekretaris (Penguji 3)</span>
                                    <strong class="text-dark small"><?= htmlspecialchars($info_sidang['penguji_3'] ?? 'Akan Ditetapkan'); ?></strong>
                                </div>
                            </div>
                            <div class="penguji-chip">
                                <div class="penguji-avatar pembimbing">
                                    <i class="bi bi-person-badge-fill"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <span class="text-success d-block" style="font-size: 0.72rem; text-transform: uppercase; font-weight: 700;">Dosen Pembimbing</span>
                                    <strong class="text-dark small"><?= htmlspecialchars($info_sidang['dospem'] ?? $mhs['nama_dosen']); ?></strong>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($info_sidang['ketentuan_sidang'])): ?>
                            <div class="bg-light p-2 rounded small border">
                                <span class="fw-bold text-dark d-block mb-1"><i class="bi bi-info-circle me-1 text-info"></i>Tata Tertib / Ketentuan:</span>
                                <p class="mb-0 text-muted" style="font-size: 0.8rem;"><?= nl2br(htmlspecialchars($info_sidang['ketentuan_sidang'])); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
    <!-- Akses Cepat Informasi Sidang -->
    <div class="card shadow-sm border-0 mb-4 bg-white p-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex align-items-center gap-3">
                <div class="bg-indigo-subtle text-primary p-2 rounded-3 fs-4">
                    <i class="bi bi-mortarboard-fill"></i>
                </div>
                <div>
                    <h6 class="fw-bold text-dark mb-0">Informasi & Pendaftaran Ujian Sidang Skripsi</h6>
                    <small class="text-muted">Pantau kelayakan bimbingan Bab 1 - 5 dan ajukan pendaftaran sidang secara online.</small>
                </div>
            </div>
            <a href="informasi_sidang.php" class="btn btn-outline-primary btn-sm px-3">
                <i class="bi bi-arrow-right-circle me-1"></i>Buka Halaman Sidang
            </a>
        </div>
    </div>
<?php endif; ?>

<!-- ============================================================
     KARTU NOTIFIKASI & PEMBERITAHUAN TERBARU (UPDATE ROBI)
============================================================ -->
<?php if (!empty($daftar_notif)): ?>
    <div class="card shadow-sm mb-4 border-0">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
            <h6 class="m-0 fw-bold text-dark">
                <i class="bi bi-bell-fill text-primary me-2"></i>Pemberitahuan & Notifikasi Sistem
            </h6>
            <a href="?mark_all_read=1" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;">
                <i class="bi bi-check2-all me-1"></i>Tandai Semua Dibaca
            </a>
        </div>
        <div class="list-group list-group-flush">
            <?php foreach ($daftar_notif as $notif): 
                $icon_name   = 'bi-info-circle-fill';
                $color_class = 'text-primary';
                $bg_icon     = 'bg-primary bg-opacity-10';
                $badge_class = 'bg-primary-subtle text-primary border border-primary-subtle';

                if ($notif['tipe'] === 'pembimbing') {
                    $icon_name   = 'bi-award-fill';
                    $color_class = 'text-warning';
                    $bg_icon     = 'bg-warning bg-opacity-10';
                    $badge_class = 'bg-warning-subtle text-warning-emphasis border border-warning-subtle';
                } elseif ($notif['tipe'] === 'sidang') {
                    $icon_name   = 'bi-mortarboard-fill';
                    $color_class = 'text-primary';
                    $bg_icon     = 'bg-primary bg-opacity-10';
                    $badge_class = 'bg-primary-subtle text-primary border border-primary-subtle';
                } elseif ($notif['tipe'] === 'revisi') {
                    $icon_name   = 'bi-arrow-repeat';
                    $color_class = 'text-danger';
                    $bg_icon     = 'bg-danger bg-opacity-10';
                    $badge_class = 'bg-danger-subtle text-danger border border-danger-subtle';
                } elseif ($notif['tipe'] === 'sukses') {
                    $icon_name   = 'bi-check-circle-fill';
                    $color_class = 'text-success';
                    $bg_icon     = 'bg-success bg-opacity-10';
                    $badge_class = 'bg-success-subtle text-success border border-success-subtle';
                }
                $notif_link = !empty($notif['link_tujuan']) ? '?open_nid=' . $notif['id'] . '&dest=' . urlencode($notif['link_tujuan']) : '#';
                $is_unread  = empty($notif['is_read']);
            ?>
                <a href="<?= $notif_link; ?>" 
                   class="list-group-item list-group-item-action p-3 <?= $is_unread ? 'bg-light bg-opacity-75 border-start border-4 border-primary' : ''; ?>"
                   style="transition: all 0.2s ease;">
                    <div class="d-flex align-items-start gap-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 <?= $bg_icon; ?> mt-1 shadow-xs" style="width: 42px; height: 42px;">
                            <i class="bi <?= $icon_name; ?> fs-5 <?= $color_class; ?>"></i>
                        </div>
                        <div class="flex-grow-1 min-w-0">
                            <div class="d-flex align-items-center justify-content-between gap-2 mb-1 flex-wrap">
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <span class="fw-bold text-dark" style="font-size: 0.95rem;"><?= htmlspecialchars($notif['judul']); ?></span>
                                    <span class="badge <?= $badge_class; ?> rounded-pill px-2.5 py-1" style="font-size: 0.68rem; font-weight: 600; white-space: nowrap;">
                                        <?= ucfirst($notif['tipe']); ?>
                                    </span>
                                    <?php if ($is_unread): ?>
                                        <span class="badge bg-danger rounded-pill px-2 py-0.5" style="font-size: 0.6rem;">Baru</span>
                                    <?php endif; ?>
                                </div>
                                <small class="text-muted flex-shrink-0 ms-auto" style="font-size: 0.74rem; white-space: nowrap;">
                                    <i class="bi bi-clock me-1"></i><?= date('d M Y, H:i', strtotime($notif['created_at'])); ?>
                                </small>
                            </div>
                            <p class="mb-0 text-secondary" style="line-height: 1.5; font-size: 0.85rem; word-break: break-word;">
                                <?= htmlspecialchars($notif['pesan']); ?>
                            </p>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Kartu Informasi Skripsi & Dosen Pembimbing -->
<div class="row g-3 mb-4">
    <!-- Info Skripsi & Progres Bar -->
    <div class="col-lg-7">
        <div class="card p-3 shadow-sm border-start border-primary border-4 h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                    <i class="bi bi-journal-text me-1"></i>Skripsi Mahasiswa
                </span>
                <?php
                $status_kelulusan = $mhs['status_kelulusan'] ?? 'proses_bimbingan';
                $badge_class = 'bg-warning text-dark';
                $badge_label = 'Proses Bimbingan';
                if ($status_kelulusan === 'siap_sidang') {
                    $badge_class = 'bg-info text-white';
                    $badge_label = 'Siap Sidang';
                } elseif ($status_kelulusan === 'lulus') {
                    $badge_class = 'bg-success text-white';
                    $badge_label = 'Lulus';
                }
                ?>
                <span class="badge <?= $badge_class; ?>"><?= $badge_label; ?></span>
            </div>
            <h5 class="fw-bold text-dark mb-2"><?= htmlspecialchars($mhs['judul_skripsi'] ?? 'Judul Belum Diatur'); ?></h5>
            <div class="d-flex align-items-center gap-3 mb-3">
                <a href="profil.php" title="Kelola Profil & Foto Mahasiswa">
                    <?= function_exists('render_avatar') ? render_avatar($mhs['foto_profil'] ?? '', $mhs['nama_lengkap'] ?? 'M', 50) : ''; ?>
                </a>
                <div>
                    <div class="fw-bold text-dark"><?= htmlspecialchars($mhs['nama_lengkap'] ?? '-'); ?></div>
                    <div class="text-muted small">
                        NIM: <strong class="text-dark"><?= htmlspecialchars($mhs['nim'] ?? '-'); ?></strong> |
                        Kelas: <strong class="text-dark"><?= htmlspecialchars($mhs['kelas'] ?? 'TI-1A-2025'); ?></strong>
                    </div>
                </div>
            </div>

            <!-- Bar Progres Skripsi -->
            <div class="mt-auto">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="small fw-semibold text-secondary">Estimasi Progres Skripsi</span>
                    <span class="small fw-bold text-primary"><?= $persen_progres; ?>%</span>
                </div>
                <div class="progress progress-skripsi-vibrant">
                    <div class="progress-bar progress-bar-striped progress-bar-animated progress-skripsi-bar" 
                         role="progressbar" 
                         style="width: <?= $persen_progres; ?>%;" 
                         aria-valuenow="<?= $persen_progres; ?>" 
                         aria-valuemin="0" 
                         aria-valuemax="100"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Info Dosen Pembimbing & Kontak -->
    <div class="col-lg-5">
        <div class="card p-3 shadow-sm border-start border-success border-4 h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                    <i class="bi bi-person-badge me-1"></i>Dosen Pembimbing
                </span>
                <?php if ($status_pembimbing === 'aktif'): ?>
                    <span class="badge bg-success small"><i class="bi bi-check-circle me-1"></i>SK Aktif</span>
                <?php elseif ($status_pembimbing === 'menunggu_konfirmasi'): ?>
                    <span class="badge bg-warning text-dark small"><i class="bi bi-clock me-1"></i>Menunggu Konfirmasi</span>
                <?php endif; ?>
            </div>

            <?php 
            $foto_dosen_src = function_exists('get_avatar_url') ? get_avatar_url($mhs['foto_dosen'] ?? '') : null;
            ?>
            <?php if (!empty($mhs['nama_dosen'])): ?>
                <div class="d-flex align-items-center mb-3">
                    <div class="me-3 position-relative flex-shrink-0">
                        <a href="javascript:void(0)" class="text-decoration-none d-inline-block" data-bs-toggle="modal" data-bs-target="#modalProfilDosen" title="Klik untuk melihat profil lengkap dosen">
                            <?php if ($foto_dosen_src): ?>
                                <img src="<?= $foto_dosen_src; ?>" class="rounded-circle border shadow-sm img-thumbnail" width="58" height="58" style="object-fit: cover; cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.08)'" onmouseout="this.style.transform='scale(1)'" alt="Foto Profil Dosen">
                            <?php else: ?>
                                <div class="rounded-circle shadow-sm d-flex align-items-center justify-content-center text-white fw-bold text-decoration-none" style="width: 58px; height: 58px; font-size: 1.5rem; background: #059669; cursor: pointer; transition: transform 0.2s; user-select: none;" onmouseover="this.style.transform='scale(1.08)'" onmouseout="this.style.transform='scale(1)'">
                                    <?= strtoupper(substr($mhs['nama_dosen'] ?? 'D', 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                        </a>
                        <span class="position-absolute bottom-0 end-0 p-1.5 bg-success border border-2 border-white rounded-circle shadow-sm" title="Dosen Aktif"></span>
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <h6 class="fw-bold text-dark mb-1 text-truncate" title="<?= htmlspecialchars($mhs['nama_dosen']); ?>">
                            <?= htmlspecialchars($mhs['nama_dosen']); ?>
                        </h6>
                        <span class="text-muted small d-block">
                            NIDN: <?= htmlspecialchars($mhs['nidn'] ?? '-'); ?>
                        </span>
                    </div>
                </div>

                <div class="bg-light p-3 rounded-3 small mb-3 border">
                    <div class="mb-2 d-flex align-items-center justify-content-between">
                        <span class="text-muted d-flex align-items-center"><i class="bi bi-envelope text-primary me-2"></i>Email:</span>
                        <a href="mailto:<?= htmlspecialchars($mhs['email_dosen'] ?? ''); ?>" class="text-decoration-none text-dark fw-semibold text-truncate" style="max-width: 175px;" title="<?= htmlspecialchars($mhs['email_dosen'] ?? '-'); ?>">
                            <?= htmlspecialchars($mhs['email_dosen'] ?? '-'); ?>
                        </a>
                    </div>
                    <div class="d-flex align-items-center justify-content-between">
                        <span class="text-muted d-flex align-items-center"><i class="bi bi-whatsapp text-success me-2"></i>WhatsApp:</span>
                        <?php if (!empty($mhs['no_whatsapp'])): 
                            $clean_wa = preg_replace('/[^0-9]/', '', $mhs['no_whatsapp']);
                            if (str_starts_with($clean_wa, '0')) {
                                $clean_wa = '62' . substr($clean_wa, 1);
                            }
                        ?>
                            <a href="https://wa.me/<?= $clean_wa; ?>" target="_blank" class="text-decoration-none text-dark fw-semibold d-inline-flex align-items-center gap-1">
                                <?= htmlspecialchars($mhs['no_whatsapp']); ?> <i class="bi bi-box-arrow-up-right small text-muted"></i>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mt-auto d-flex flex-column gap-2">
                    <div class="row g-2">
                        <div class="col-6">
                            <a href="chat.php" class="btn btn-outline-success btn-sm w-100 d-flex align-items-center justify-content-center gap-1 py-1.5 fw-semibold shadow-xs">
                                <i class="bi bi-chat-dots-fill"></i>Chat di Web
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="jadwal_bimbingan.php" class="btn btn-outline-secondary btn-sm w-100 d-flex align-items-center justify-content-center gap-1 py-1.5 fw-semibold shadow-xs">
                                <i class="bi bi-calendar-week"></i>Jadwal
                            </a>
                        </div>
                    </div>
                    <button type="button" class="btn btn-primary btn-sm w-100 d-flex align-items-center justify-content-center gap-1.5 py-1.5 fw-semibold shadow-xs" data-bs-toggle="modal" data-bs-target="#modalProfilDosen">
                        <i class="bi bi-person-vcard-fill"></i>Lihat Profil Lengkap Dosen
                    </button>
                </div>
            <?php else: ?>
                <div class="alert alert-warning small mb-0 py-2">
                    <i class="bi bi-exclamation-triangle me-1"></i>Anda belum di-plot dosen pembimbing oleh Admin. Silakan hubungi bagian akademik/admin.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Milestone Tahapan Skripsi (Pengajuan Judul s.d. ACC Sidang) -->
<div class="card shadow-sm mb-4 border-0">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h6 class="m-0 fw-bold text-dark">
                <i class="bi bi-check2-circle text-primary me-2"></i>Pencatatan Progres Tahapan Skripsi
            </h6>
            <small class="text-muted">Alur pengajuan ACC progres secara bertahap mulai dari pengajuan judul sampai siap sidang.</small>
        </div>
        <?php if ($status_pembimbing === 'aktif'): ?>
            <button type="button" class="btn btn-primary btn-sm px-3 shadow-sm d-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#modalAjukanProgres">
                <i class="bi bi-send-check"></i>Pengajuan Progres
            </button>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <div class="row row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-xl-7 g-3 text-center">
            <?php 
            $t_keys_arr = array_keys($tahapan_progres);
            foreach ($tahapan_progres as $t_key => $t_data): 
                $cur_idx = array_search($t_key, $t_keys_arr);
                $is_step_unlocked = false;
                if ($t_key === 'judul') {
                    $is_step_unlocked = true;
                } else {
                    $prev_key = $t_keys_arr[$cur_idx - 1];
                    if ($tahapan_progres[$prev_key]['status'] === 'acc') {
                        $is_step_unlocked = true;
                    }
                }
            ?>
                <div class="col">
                    <?php
                    // URL link upload berkas sesuai tahapan
                    if ($t_key === 'judul') {
                        $target_upload_url = 'upload_draft.php?tahap=judul';
                    } elseif (preg_match('/^bab(\d+)$/', $t_key, $m_b)) {
                        $target_upload_url = 'upload_draft.php?bab=' . $m_b[1] . '#formUploadBab';
                    } else {
                        $target_upload_url = 'upload_draft.php?bab=sidang#formUploadBab';
                    }
                    ?>
                    <a href="<?= $target_upload_url; ?>" class="bab-step-card status-<?= $t_data['status']; ?> h-100 d-flex flex-column justify-content-between text-decoration-none" title="Klik untuk membuka progres upload berkas <?= htmlspecialchars($t_data['nama']); ?>" style="cursor: pointer; color: inherit;">
                        <div>
                            <div class="bab-icon-circle" title="Klik untuk upload berkas <?= htmlspecialchars($t_data['nama']); ?>">
                                <?php if ($t_data['status'] === 'acc'): ?>
                                    <i class="bi bi-check2-circle"></i>
                                <?php elseif ($t_data['status'] === 'revisi'): ?>
                                    <i class="bi bi-arrow-repeat"></i>
                                <?php elseif ($t_data['status'] === 'proses'): ?>
                                    <i class="bi bi-hourglass-split"></i>
                                <?php elseif ($is_step_unlocked): ?>
                                    <i class="bi bi-plus-circle"></i>
                                <?php else: ?>
                                    <i class="bi bi-dash-circle"></i>
                                <?php endif; ?>
                            </div>
                            <h6 class="fw-bold mb-1 small text-dark bab-card-title"><?= htmlspecialchars($t_data['nama']); ?></h6>
                            <div class="small text-muted mb-2 bab-card-desc"><?= htmlspecialchars($t_data['label']); ?></div>
                        </div>
                        <div class="bab-card-badge-wrapper">
                            <?php if ($t_data['status'] === 'acc'): ?>
                                <span class="badge bg-success">ACC Selesai</span>
                            <?php elseif ($t_data['status'] === 'revisi'): ?>
                                <span class="badge bg-warning">Perlu Revisi</span>
                            <?php elseif ($t_data['status'] === 'proses'): ?>
                                <span class="badge bg-info">Sedang Diproses</span>
                            <?php else: ?>
                                <?php if ($is_step_unlocked): ?>
                                    <span class="badge bg-primary">Siap Diajukan</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Terkunci</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Statistik Ringkas Bimbingan (Modern & Berwarna) -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-6 col-xl-3">
        <div class="student-stat-card stat-blue">
            <div class="d-flex align-items-center">
                <div class="stat-icon-pill bg-gradient-blue me-3">
                    <i class="bi bi-journals"></i>
                </div>
                <div>
                    <span class="text-muted small fw-semibold d-block">Total Bimbingan</span>
                    <h3 class="fw-bold mb-0 text-dark"><?= $total_bimbingan; ?></h3>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-6 col-xl-3">
        <div class="student-stat-card stat-emerald">
            <div class="d-flex align-items-center">
                <div class="stat-icon-pill bg-gradient-emerald me-3">
                    <i class="bi bi-check-circle-fill"></i>
                </div>
                <div>
                    <span class="text-muted small fw-semibold d-block">Disetujui / ACC</span>
                    <h3 class="fw-bold mb-0 text-dark"><?= $bimbingan_acc; ?></h3>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-6 col-xl-3">
        <div class="student-stat-card stat-amber">
            <div class="d-flex align-items-center">
                <div class="stat-icon-pill bg-gradient-amber me-3">
                    <i class="bi bi-clock-history"></i>
                </div>
                <div>
                    <span class="text-muted small fw-semibold d-block text-nowrap">Proses</span>
                    <h3 class="fw-bold mb-0 text-dark"><?= $bimbingan_menunggu; ?></h3>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-6 col-xl-3">
        <div class="student-stat-card stat-purple">
            <div class="d-flex align-items-center">
                <div class="stat-icon-pill bg-gradient-purple me-3">
                    <i class="bi bi-star-fill"></i>
                </div>
                <div>
                    <span class="text-muted small fw-semibold d-block">Rata-rata Nilai</span>
                    <h3 class="fw-bold mb-0 text-dark"><?= $rata_nilai > 0 ? $rata_nilai . '<span class="fs-6 text-muted fw-normal">/100</span>' : '-'; ?></h3>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Riwayat Bimbingan Terkini & Akses Cepat -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="m-0 fw-bold text-dark">
            <i class="bi bi-list-task me-1 text-primary"></i>Riwayat Bimbingan Terakhir
        </h6>
        <a href="logbook.php" class="btn btn-outline-primary btn-sm">Lihat Semua di Logbook</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Tanggal & Jam</th>
                        <th>Bab Pembahasan</th>
                        <th>Draft Skripsi</th>
                        <th>Status</th>
                        <th>Catatan Dosen</th>
                        <th>Nilai</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_bimbingan)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">
                                <i class="bi bi-inbox fs-2 d-block mb-1"></i>
                                Belum ada riwayat bimbingan. Silakan serahkan naskah Anda di menu <a href="upload_draft.php" class="fw-semibold">Upload Berkas</a> atau cek <a href="jadwal_bimbingan.php" class="fw-semibold">Jadwal Bimbingan</a>.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recent_bimbingan as $item): ?>
                            <tr>
                                <td>
                                    <strong><?= date('d M Y', strtotime($item['tanggal_bimbingan'])); ?></strong><br>
                                    <small class="text-muted"><?= substr($item['jam_bimbingan'], 0, 5); ?> WIB</small>
                                </td>
                                <td>
                                    <span class="fw-semibold"><?= htmlspecialchars($item['bab_pembahasan']); ?></span>
                                    <?php if (!empty($item['catatan_mahasiswa'])): ?>
                                        <p class="text-muted small mb-0 text-truncate" style="max-width: 200px;">
                                            <?= htmlspecialchars($item['catatan_mahasiswa']); ?>
                                        </p>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex flex-column gap-1">
                                        <?php if (!empty($item['file_draft'])): ?>
                                            <a href="../unduh_berkas.php?id=<?= $item['id']; ?>" target="_blank" class="btn btn-outline-danger btn-sm py-0 px-2" style="font-size: 0.75rem;">
                                                <i class="bi bi-file-earmark-pdf me-1"></i>PDF
                                            </a>
                                        <?php endif; ?>
                                        <?php if (!empty($item['link_drive'])): ?>
                                            <a href="<?= htmlspecialchars($item['link_drive']); ?>" target="_blank" class="btn btn-outline-primary btn-sm py-0 px-2" style="font-size: 0.75rem;">
                                                <i class="bi bi-link-45deg me-1"></i>Google Drive
                                            </a>
                                        <?php endif; ?>
                                        <?php if (empty($item['file_draft']) && empty($item['link_drive'])): ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-<?= $item['status']; ?>">
                                        <?= ucfirst($item['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="small text-muted"><?= htmlspecialchars($item['catatan_dosen'] ?? 'Belum ada catatan'); ?></span>
                                </td>
                                <td>
                                    <?php if ($item['nilai_progres'] > 0): ?>
                                        <span class="badge bg-success"><?= $item['nilai_progres']; ?></span>
                                    <?php else: ?>
                                        <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


<!-- ============================================================
     MODAL PENGAJUAN ACC PROGRES SKRIPSI
============================================================ -->
<div class="modal fade" id="modalAjukanProgres" tabindex="-1" aria-labelledby="modalAjukanProgresLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-gradient-navy text-white" style="background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 60%, #1e40af 100%);">
                <div>
                    <h5 class="modal-title fw-bold text-white mb-0" id="modalAjukanProgresLabel">
                        <i class="bi bi-send-check text-info me-2"></i>Pengajuan ACC Progres Skripsi
                    </h5>
                    <small class="text-white-50">Ajukan permohonan evaluasi dan persetujuan (ACC) secara bertahap kepada Dosen Pembimbing</small>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <form action="index.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action_ajukan_progres" value="1">
                
                <div class="modal-body p-4">
                    <!-- Status Tahap Saat Ini -->
                    <div class="alert alert-primary bg-primary bg-opacity-10 border-primary border-opacity-25 rounded-3 p-3 mb-3">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="badge bg-primary px-2.5 py-1">Tahap Saat Ini</span>
                            <span class="fw-bold text-primary"><?= htmlspecialchars($tahapan_progres[$tahap_berikutnya]['nama'] ?? 'Tahapan Skripsi'); ?></span>
                        </div>
                        <p class="small text-muted mb-0">
                            Sesuai ketentuan akademik, pengajuan progres harus dilakukan secara bertahap mulai dari <strong>Pengajuan Judul</strong> hingga <strong>ACC Sidang</strong>.
                        </p>
                    </div>

                    <!-- Dropdown Pilihan Tahap -->
                    <div class="mb-3">
                        <label for="selectTahapProgres" class="form-label small fw-semibold text-secondary">
                            Pilih Tahapan Progres yang Diajukan <span class="text-danger">*</span>
                        </label>
                        <select name="tahap_key" id="selectTahapProgres" class="form-select" onchange="toggleTahapFields()" required>
                            <?php 
                            $t_keys = array_keys($tahapan_progres);
                            foreach ($tahapan_progres as $k_step => $info_step):
                                $idx = array_search($k_step, $t_keys);
                                $is_unlocked = false;
                                if ($k_step === 'judul') {
                                    $is_unlocked = true;
                                } else {
                                    $prev_k = $t_keys[$idx - 1];
                                    if ($tahapan_progres[$prev_k]['status'] === 'acc') {
                                        $is_unlocked = true;
                                    }
                                }

                                $status_badge_text = '';
                                if ($info_step['status'] === 'acc') {
                                    $status_badge_text = ' [Sudah di-ACC ✓]';
                                } elseif ($info_step['status'] === 'revisi') {
                                    $status_badge_text = ' [Perlu Revisi - Ajukan Ulang ⚠️]';
                                } elseif ($info_step['status'] === 'proses') {
                                    $status_badge_text = ' [Sedang Menunggu Review ⏳]';
                                } elseif ($is_unlocked) {
                                    $status_badge_text = ' [Siap Diajukan ⭐]';
                                } else {
                                    $status_badge_text = ' [Terkunci 🔒 - Selesaikan tahap sebelumnya]';
                                }

                                $is_selected = ($k_step === $tahap_berikutnya);
                                $is_disabled = (!$is_unlocked && $info_step['status'] !== 'acc');
                            ?>
                                <option value="<?= $k_step; ?>" <?= $is_selected ? 'selected' : ''; ?> <?= $is_disabled ? 'disabled class="text-muted"' : ''; ?>>
                                    Tahap <?= ($idx + 1); ?>: <?= htmlspecialchars($info_step['nama']); ?> - <?= htmlspecialchars($info_step['label']) . $status_badge_text; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted d-block mt-1">Tahap yang belum terbuka (terkunci) akan otomatis dapat dipilih setelah tahap sebelumnya di-ACC oleh Dosen.</small>
                    </div>

                    <!-- Input Khusus Pengajuan Judul (Muncul jika pilih Judul) -->
                    <div id="boxJudulSkripsiModal" class="mb-3 p-3 bg-light rounded border" style="display: none;">
                        <label for="inputJudulBaru" class="form-label small fw-semibold text-secondary">
                            Rencana Judul Skripsi yang Diajukan <span class="text-danger">*</span>
                        </label>
                        <textarea name="judul_skripsi_baru" id="inputJudulBaru" rows="2" class="form-control" placeholder="Tuliskan rancangan judul skripsi yang lengkap dan jelas..."><?= htmlspecialchars(($mhs['judul_skripsi'] !== 'Belum Ditentukan') ? $mhs['judul_skripsi'] : ''); ?></textarea>
                        <small class="text-muted d-block mt-1">Judul yang diajukan akan otomatis tercatat sebagai judul skripsi Anda di dashboard.</small>
                    </div>

                    <!-- Catatan / Uraian Progres -->
                    <div class="mb-3">
                        <label for="catatanProgres" class="form-label small fw-semibold text-secondary">
                            Catatan / Ringkasan Uraian Progres <span class="text-danger">*</span>
                        </label>
                        <textarea name="catatan_mahasiswa" id="catatanProgres" rows="3" class="form-control" placeholder="Jelaskan progres bab/naskah yang dikerjakan, poin revisi yang telah diperbaiki, atau hal yang ingin dikonsultasikan..." required></textarea>
                    </div>

                    <!-- Pilihan Berkas / Tautan Naskah -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="file_draft_modal" class="form-label small fw-semibold text-secondary">
                                <i class="bi bi-file-earmark-arrow-up text-danger me-1"></i>Unggah Dokumen / Naskah
                            </label>
                            <input type="file" name="file_draft" id="file_draft_modal" class="form-control form-control-sm" accept=".pdf,.doc,.docx">
                            <small class="text-muted" style="font-size: 0.72rem;">Judul: Dokumen PDF/Word. Bab: PDF maks 10MB.</small>
                        </div>
                        <div class="col-md-6">
                            <label for="link_drive_modal" class="form-label small fw-semibold text-secondary">
                                <i class="bi bi-google text-primary me-1"></i>Tautan Google Drive / Docs
                            </label>
                            <input type="url" name="link_drive" id="link_drive_modal" class="form-control form-control-sm" placeholder="https://docs.google.com/...">
                            <small class="text-muted" style="font-size: 0.72rem;">Pastikan izin tautan disetel agar dapat dibaca/dikomentari.</small>
                        </div>
                    </div>

                    <!-- Tanggal & Jam Konsultasi Diminta -->
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="tanggal_bimbingan_modal" class="form-label small fw-semibold text-secondary">
                                <i class="bi bi-calendar-event me-1"></i>Tanggal Pengajuan / Bimbingan <span class="text-danger">*</span>
                            </label>
                            <input type="date" name="tanggal_bimbingan" id="tanggal_bimbingan_modal" class="form-control form-control-sm" value="<?= date('Y-m-d'); ?>" min="<?= date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="jam_bimbingan_modal" class="form-label small fw-semibold text-secondary">
                                <i class="bi bi-clock me-1"></i>Jam Bimbingan <span class="text-danger">*</span>
                            </label>
                            <input type="time" name="jam_bimbingan" id="jam_bimbingan_modal" class="form-control form-control-sm" value="<?= date('H:i'); ?>" required>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light py-2 px-4">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm px-4">
                        <i class="bi bi-send-check me-1"></i>Kirim Pengajuan Progres
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleTahapFields() {
    var sel = document.getElementById('selectTahapProgres');
    var boxJudul = document.getElementById('boxJudulSkripsiModal');
    var inpJudul = document.getElementById('inputJudulBaru');
    if (sel && boxJudul) {
        if (sel.value === 'judul') {
            boxJudul.style.display = 'block';
            if (inpJudul) inpJudul.required = true;
        } else {
            boxJudul.style.display = 'none';
            if (inpJudul) inpJudul.required = false;
        }
    }
}
document.addEventListener('DOMContentLoaded', function() {
    toggleTahapFields();
});
</script>


<!-- ============================================================
     MODAL LIHAT PROFIL DOSEN PEMBIMBING (SESUAI PROFIL DOSEN)
============================================================ -->
<div class="modal fade" id="modalProfilDosen" tabindex="-1" aria-labelledby="modalProfilDosenLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header text-white border-0 py-3" style="background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 60%, #1e40af 100%);">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-person-vcard-fill text-info fs-5"></i>
                    <h5 class="modal-title fw-bold text-white mb-0" id="modalProfilDosenLabel">Profil Dosen Pembimbing</h5>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <div class="modal-body p-4">
                <!-- Foto & Identitas Utama Dosen -->
                <div class="text-center mb-4">
                    <div class="mb-3 position-relative d-inline-block mx-auto">
                        <?php if ($foto_dosen_src): ?>
                            <img src="<?= $foto_dosen_src; ?>" 
                                 alt="Foto Profil Dosen" 
                                 class="rounded-circle img-thumbnail shadow object-fit-cover" 
                                 style="width: 125px; height: 125px;">
                        <?php else: ?>
                            <div class="avatar-circle avatar-primary shadow mx-auto d-flex align-items-center justify-content-center text-primary rounded-circle" 
                                 style="width: 125px; height: 125px; font-size: 3.2rem;">
                                <i class="bi bi-person-fill"></i>
                            </div>
                        <?php endif; ?>
                        <span class="position-absolute bottom-0 end-0 p-2 bg-success border border-3 border-white rounded-circle shadow-sm" title="Status Pembimbing Aktif"></span>
                    </div>

                    <h5 class="fw-bold mb-1 text-dark"><?= htmlspecialchars($mhs['nama_dosen'] ?? 'Dosen Pembimbing'); ?></h5>
                    <p class="text-muted small mb-2">NIDN: <?= htmlspecialchars($mhs['nidn'] ?? '-'); ?></p>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-1.5 rounded-pill">
                        <i class="bi bi-award-fill me-1"></i>Dosen Pembimbing Utama Skripsi
                    </span>
                </div>

                <!-- Detail Biodata Profil Dosen (Sesuai profil dosen itu sendiri) -->
                <div class="list-group list-group-flush text-start small border rounded-3 p-3 bg-light mb-3">
                    <div class="list-group-item d-flex justify-content-between px-0 py-2 bg-transparent border-bottom">
                        <span class="text-muted"><i class="bi bi-envelope me-2 text-primary"></i>Email Resmi:</span>
                        <a href="mailto:<?= htmlspecialchars($mhs['email_dosen'] ?? ''); ?>" class="text-decoration-none fw-semibold text-secondary">
                            <?= htmlspecialchars($mhs['email_dosen'] ?? '-'); ?>
                        </a>
                    </div>
                    <div class="list-group-item d-flex justify-content-between px-0 py-2 bg-transparent border-bottom">
                        <span class="text-muted"><i class="bi bi-whatsapp me-2 text-success"></i>Nomor WhatsApp:</span>
                        <?php if (!empty($mhs['no_whatsapp'])): 
                            $clean_wa = preg_replace('/[^0-9]/', '', $mhs['no_whatsapp']);
                            if (str_starts_with($clean_wa, '0')) {
                                $clean_wa = '62' . substr($clean_wa, 1);
                            }
                        ?>
                            <a href="https://wa.me/<?= $clean_wa; ?>" target="_blank" class="text-decoration-none fw-semibold text-success">
                                <?= htmlspecialchars($mhs['no_whatsapp']); ?> <i class="bi bi-box-arrow-up-right small"></i>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </div>
                    <div class="list-group-item d-flex justify-content-between px-0 py-2 bg-transparent">
                        <span class="text-muted"><i class="bi bi-people me-2 text-primary"></i>Kapasitas Bimbingan:</span>
                        <strong><?= htmlspecialchars($mhs['kuota_bimbingan'] ?? '5'); ?> Mahasiswa</strong>
                    </div>
                </div>

                <!-- Jadwal Ketersediaan Bimbingan Dosen -->
                <div class="p-3 border rounded-3 bg-white">
                    <h6 class="fw-bold text-dark mb-2 small d-flex align-items-center">
                        <i class="bi bi-calendar-check text-primary me-2"></i>Jadwal Konsultasi Dosen
                    </h6>
                    <?php if (!empty($jadwal_dosen_list)): ?>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($jadwal_dosen_list as $jd): ?>
                                <span class="badge bg-light text-dark border px-2.5 py-1.5 small">
                                    <i class="bi bi-clock-fill text-primary me-1"></i>
                                    <strong><?= htmlspecialchars($jd['hari']); ?>:</strong> <?= date('H:i', strtotime($jd['jam_mulai'])); ?> - <?= date('H:i', strtotime($jd['jam_selesai'])); ?> WIB
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted small mb-0">
                            <i class="bi bi-info-circle me-1"></i>Jadwal konsultasi tatap muka / daring dapat disepakati secara fleksibel melalui fitur Chat atau WhatsApp.
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="modal-footer bg-light border-top d-flex justify-content-between">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
                <div class="d-flex gap-2">
                    <a href="chat.php" class="btn btn-primary btn-sm">
                        <i class="bi bi-chat-dots-fill me-1"></i>Kirim Pesan Chat
                    </a>
                    <?php if (!empty($mhs['no_whatsapp'])): ?>
                        <a href="https://wa.me/<?= $clean_wa; ?>" target="_blank" class="btn btn-success btn-sm">
                            <i class="bi bi-whatsapp me-1"></i>WhatsApp
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Fallback Alias modalLihatFotoDosen -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    var modalTrigger = document.querySelectorAll('[data-bs-target="#modalLihatFotoDosen"]');
    modalTrigger.forEach(function(el) {
        el.setAttribute('data-bs-target', '#modalProfilDosen');
    });
});
</script>

<?php
require_once '../templates/footer.php';
?>
