<?php
// mahasiswa/informasi_sidang.php - Halaman Progres, Pendaftaran, & Informasi Sidang Skripsi (UPDATE ROBI)
$page_title   = "Informasi Sidang Skripsi";
$current_page = "sidang";

require_once '../config/database.php';
require_once '../templates/header.php';
require_once '../templates/sidebar_mahasiswa.php';

// Proteksi akses role mahasiswa
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'mahasiswa') {
    header("Location: ../login.php");
    exit;
}

$my_user_id  = $_SESSION['user_id'];
$success_msg = '';
$error_msg   = '';

// Ambil info mahasiswa & dosen pembimbing
$stmt_mhs = $pdo->prepare("
    SELECT m.*, d.id AS dosen_id, d.nama_dosen, d.nidn, d.email AS email_dosen, d.no_whatsapp 
    FROM mahasiswa m 
    LEFT JOIN dosen d ON m.dosen_pembimbing_id = d.id 
    WHERE m.user_id = :uid LIMIT 1
");
$stmt_mhs->execute([':uid' => $my_user_id]);
$mhs = $stmt_mhs->fetch();

$mhs_id   = $mhs['id'] ?? 0;
$dosen_id = $mhs['dosen_id'] ?? null;

// Cek kelayakan bimbingan (Bab 1 - 5 ACC dan total sesi)
$total_sesi = 0;
$total_acc  = 0;
if ($mhs_id) {
    $stmt_stat = $pdo->prepare("
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN status IN ('disetujui', 'selesai') THEN 1 ELSE 0 END) AS acc
        FROM pengajuan_bimbingan 
        WHERE mahasiswa_id = :mid
    ");
    $stmt_stat->execute([':mid' => $mhs_id]);
    $st = $stmt_stat->fetch();
    $total_sesi = (int)($st['total'] ?? 0);
    $total_acc  = (int)($st['acc'] ?? 0);
}

// Ambil data sidang skripsi mahasiswa dari tabel sidang_skripsi (UPDATE ROBI)
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

// Handle Pendaftaran Sidang Mandiri Mahasiswa (Jika belum ada sidang aktif)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'daftar_sidang') {
    if (!$dosen_id) {
        $error_msg = "Anda belum memiliki dosen pembimbing terdaftar.";
    } elseif ($info_sidang && in_array($info_sidang['status_sidang'], ['pengajuan', 'dijadwalkan'])) {
        $error_msg = "Anda sudah memiliki pengajuan sidang yang sedang diproses.";
    } else {
        $judul_final = trim($_POST['judul_final'] ?? ($mhs['judul_skripsi'] ?? ''));

        if (empty($judul_final)) {
            $error_msg = "Judul final skripsi wajib diisi.";
        } else {
            try {
                $stmt_daftar = $pdo->prepare("
                    INSERT INTO sidang_skripsi (mahasiswa_id, dosen_pembimbing_id, judul_final, status_sidang) 
                    VALUES (:mid, :did, :judul, 'pengajuan')
                ");
                $stmt_daftar->execute([
                    ':mid'   => $mhs_id,
                    ':did'   => $dosen_id,
                    ':judul' => $judul_final
                ]);

                // Update judul final di mahasiswa juga
                $pdo->prepare("UPDATE mahasiswa SET judul_skripsi = :j WHERE id = :mid")->execute([':j' => $judul_final, ':mid' => $mhs_id]);

                // Notifikasi ke user mahasiswa
                $stmt_notif = $pdo->prepare("
                    INSERT INTO notifikasi (user_id, judul, pesan, tipe, link_tujuan) 
                    VALUES (:uid, 'Pendaftaran Sidang Berhasil', 'Pengajuan ujian sidang skripsi Anda telah diajukan dan sedang menunggu verifikasi serta penjadwalan oleh Admin.', 'sidang', 'informasi_sidang.php')
                ");
                $stmt_notif->execute([':uid' => $my_user_id]);

                $success_msg = "Pendaftaran sidang skripsi berhasil diajukan! Admin program studi akan memverifikasi dan menetapkan 3 dosen penguji serta waktu ujian Anda.";

                // Refresh info sidang
                $stmt_sidang->execute([':mid' => $mhs_id]);
                $info_sidang = $stmt_sidang->fetch();
            } catch (PDOException $e) {
                $error_msg = "Gagal mendaftar sidang: " . $e->getMessage();
            }
        }
    }
}

$status_kelulusan = $mhs['status_kelulusan'] ?? 'proses_bimbingan';
$is_eligible      = ($status_kelulusan === 'siap_sidang' || $total_acc >= 5);
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <h1 class="h2 mb-0">Halaman Progres & Informasi Sidang</h1>
            <span class="badge-kelas"><i class="bi bi-mortarboard-fill"></i><?= htmlspecialchars($mhs['kelas'] ?? 'TI-1A-2025'); ?></span>
        </div>
        <p class="text-muted small mb-0">Pusat informasi kelayakan, jadwal resmi, susunan dewan penguji, dan tata tertib ujian sidang skripsi.</p>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="index.php" class="btn btn-outline-secondary btn-sm me-2">
            <i class="bi bi-speedometer2 me-1"></i>Dashboard
        </a>
        <a href="cetak_laporan.php" target="_blank" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-printer me-1"></i>Cetak Kartu Bimbingan
        </a>
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
     1. KARTU STATUS KELAYAKAN SIDANG SKRIPSI
============================================================ -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-4 bg-white rounded">
        <div class="row align-items-center">
            <div class="col-lg-8">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                        <i class="bi bi-shield-check me-1"></i>Verifikasi Kelayakan Akademik
                    </span>
                    <?php if ($status_kelulusan === 'lulus'): ?>
                        <span class="badge-sidang"><i class="bi bi-check-circle-fill me-1"></i>Lulus Skripsi</span>
                    <?php elseif ($status_kelulusan === 'siap_sidang'): ?>
                        <span class="badge-sidang-indigo"><i class="bi bi-star-fill me-1"></i>Siap Menempuh Sidang</span>
                    <?php else: ?>
                        <span class="badge-konfirmasi"><i class="bi bi-hourglass-split me-1"></i>Dalam Proses Bimbingan</span>
                    <?php endif; ?>
                </div>
                <h5 class="fw-bold text-dark mb-1"><?= htmlspecialchars($mhs['judul_skripsi'] ?? 'Judul Belum Diatur'); ?></h5>
                <p class="text-muted small mb-0">
                    Mahasiswa: <strong><?= htmlspecialchars($mhs['nama_lengkap'] ?? '-'); ?></strong> (NIM: <?= htmlspecialchars($mhs['nim'] ?? '-'); ?>) | 
                    Pembimbing: <strong><?= htmlspecialchars($mhs['nama_dosen'] ?? 'Belum Diplot'); ?></strong>
                </p>
            </div>
            <div class="col-lg-4 text-lg-end mt-3 mt-lg-0">
                <div class="d-inline-flex gap-2">
                    <div class="p-2 border rounded text-center bg-light" style="min-width: 90px;">
                        <span class="d-block text-muted small" style="font-size: 0.7rem;">TOTAL KONSULTASI</span>
                        <h5 class="fw-bold mb-0 text-primary"><?= $total_sesi; ?> Sesi</h5>
                    </div>
                    <div class="p-2 border rounded text-center bg-light" style="min-width: 90px;">
                        <span class="d-block text-muted small" style="font-size: 0.7rem;">TERVALIDASI ACC</span>
                        <h5 class="fw-bold mb-0 text-success"><?= $total_acc; ?> Bab</h5>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     2. PENGUMUMAN RESMI JADWAL SIDANG (JIKA SUDAH DIJADWALKAN / TERDAFTAR)
============================================================ -->
<?php if ($info_sidang): ?>
    <div class="card sidang-card-wrapper shadow-sm border-0 mb-4 overflow-hidden">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
            <div class="d-flex align-items-center gap-2">
                <div class="bg-indigo-subtle text-primary p-2 rounded">
                    <i class="bi bi-mortarboard-fill fs-5"></i>
                </div>
                <div>
                    <h6 class="m-0 fw-bold text-dark">Pengumuman Resmi Jadwal Ujian Sidang Skripsi</h6>
                    <small class="text-muted">Nomor Registrasi Sidang: #SID-<?= str_pad($info_sidang['id'], 4, '0', STR_PAD_LEFT); ?></small>
                </div>
            </div>
            <div>
                <?php
                $st_sidang = $info_sidang['status_sidang'] ?? 'pengajuan';
                if ($st_sidang === 'dijadwalkan') {
                    echo '<span class="badge-sidang-indigo px-3 py-2 fs-6"><i class="bi bi-calendar-check me-1"></i>Jadwal Telah Ditetapkan</span>';
                } elseif ($st_sidang === 'lulus') {
                    echo '<span class="badge-sidang px-3 py-2 fs-6"><i class="bi bi-trophy-fill me-1"></i>Lulus Ujian Sidang</span>';
                } elseif ($st_sidang === 'revisi') {
                    echo '<span class="badge-konfirmasi px-3 py-2 fs-6"><i class="bi bi-arrow-repeat me-1"></i>Perlu Revisi Naskah</span>';
                } elseif ($st_sidang === 'tidak_lulus') {
                    echo '<span class="badge bg-danger px-3 py-2 fs-6">Tidak Lulus</span>';
                } else {
                    echo '<span class="badge-konfirmasi px-3 py-2 fs-6"><i class="bi bi-clock me-1"></i>Menunggu Penjadwalan Admin</span>';
                }
                ?>
            </div>
        </div>
        <div class="card-body p-4 bg-light bg-opacity-25">
            <div class="row g-4">
                <!-- Waktu, Tempat, dan Judul Final -->
                <div class="col-lg-6">
                    <div class="p-3 bg-white rounded border shadow-sm h-100">
                        <span class="text-muted small text-uppercase fw-bold d-block mb-1">Judul Final Naskah Skripsi:</span>
                        <h6 class="fw-bold text-dark mb-3">"<?= htmlspecialchars($info_sidang['judul_final'] ?? $mhs['judul_skripsi']); ?>"</h6>

                        <div class="row g-2 pt-2 border-top">
                            <div class="col-6">
                                <span class="text-muted small d-block">Tanggal Pelaksanaan:</span>
                                <strong class="text-dark small">
                                    <i class="bi bi-calendar-event text-primary me-1"></i>
                                    <?= !empty($info_sidang['tanggal_sidang']) ? date('l, d F Y', strtotime($info_sidang['tanggal_sidang'])) : 'Belum Ditetapkan'; ?>
                                </strong>
                            </div>
                            <div class="col-6">
                                <span class="text-muted small d-block">Waktu Sidang:</span>
                                <strong class="text-dark small">
                                    <i class="bi bi-clock text-primary me-1"></i>
                                    <?= !empty($info_sidang['jam_sidang']) ? substr($info_sidang['jam_sidang'], 0, 5) . ' WIB' : 'Menunggu Konfirmasi'; ?>
                                </strong>
                            </div>
                            <div class="col-12 mt-2">
                                <span class="text-muted small d-block">Lokasi Ruangan:</span>
                                <span class="badge bg-secondary px-3 py-2">
                                    <i class="bi bi-geo-alt-fill me-1"></i><?= htmlspecialchars($info_sidang['ruangan'] ?? 'Ruang Sidang Utama Akademik'); ?>
                                </span>
                            </div>
                        </div>

                        <!-- Nilai Akhir jika sudah dinilai -->
                        <?php if ($info_sidang['nilai_akhir'] !== null): ?>
                            <div class="mt-3 sidang-score-card">
                                <span class="small fw-semibold text-success d-block mb-1">HASIL EVALUASI SIDANG SKRIPSI:</span>
                                <h3 class="fw-bold text-success mb-1"><?= number_format((float)$info_sidang['nilai_akhir'], 2); ?> / 100</h3>
                                <div class="d-flex justify-content-center gap-3 small text-muted mt-2">
                                    <span>P1: <strong><?= $info_sidang['nilai_penguji_1'] ?? '-'; ?></strong></span>
                                    <span>P2: <strong><?= $info_sidang['nilai_penguji_2'] ?? '-'; ?></strong></span>
                                    <span>P3: <strong><?= $info_sidang['nilai_penguji_3'] ?? '-'; ?></strong></span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Dewan Penguji 1, 2, 3 dan Pembimbing -->
                <div class="col-lg-6">
                    <div class="p-3 bg-white rounded border shadow-sm h-100">
                        <span class="text-muted small text-uppercase fw-bold d-block mb-3">
                            <i class="bi bi-people-fill text-primary me-1"></i>Susunan Dewan Penguji & Pembimbing:
                        </span>
                        
                        <div class="d-flex flex-column gap-2 mb-3">
                            <div class="penguji-chip">
                                <div class="penguji-avatar ketua">
                                    <i class="bi bi-star-fill"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <span class="text-warning-emphasis d-block" style="font-size: 0.72rem; text-transform: uppercase; font-weight: 700;">Ketua Penguji (Penguji 1)</span>
                                    <strong class="text-dark"><?= htmlspecialchars($info_sidang['penguji_1'] ?? 'Ditetapkan Admin'); ?></strong>
                                </div>
                            </div>
                            <div class="penguji-chip">
                                <div class="penguji-avatar" style="background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);">
                                    <i class="bi bi-person-fill"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <span class="text-primary d-block" style="font-size: 0.72rem; text-transform: uppercase; font-weight: 700;">Dosen Penguji 2</span>
                                    <strong class="text-dark"><?= htmlspecialchars($info_sidang['penguji_2'] ?? 'Ditetapkan Admin'); ?></strong>
                                </div>
                            </div>
                            <div class="penguji-chip">
                                <div class="penguji-avatar" style="background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);">
                                    <i class="bi bi-person-fill"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <span class="text-purple d-block" style="font-size: 0.72rem; text-transform: uppercase; font-weight: 700;">Sekretaris Sidang (Penguji 3)</span>
                                    <strong class="text-dark"><?= htmlspecialchars($info_sidang['penguji_3'] ?? 'Ditetapkan Admin'); ?></strong>
                                </div>
                            </div>
                            <div class="penguji-chip">
                                <div class="penguji-avatar pembimbing">
                                    <i class="bi bi-person-badge-fill"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <span class="text-success d-block" style="font-size: 0.72rem; text-transform: uppercase; font-weight: 700;">Dosen Pembimbing</span>
                                    <strong class="text-dark"><?= htmlspecialchars($info_sidang['dospem'] ?? $mhs['nama_dosen']); ?></strong>
                                </div>
                            </div>
                        </div>

                        <!-- Tata Tertib & Ketentuan -->
                        <div class="bg-light p-3 rounded small border">
                            <span class="fw-bold text-dark d-block mb-1">
                                <i class="bi bi-card-checklist text-primary me-1"></i>Tata Tertib & Ketentuan Sidang:
                            </span>
                            <p class="mb-0 text-muted" style="font-size: 0.825rem; line-height: 1.4;">
                                <?= !empty($info_sidang['ketentuan_sidang']) ? nl2br(htmlspecialchars($info_sidang['ketentuan_sidang'])) : '1. Pakaian formal: Kemeja putih lengan panjang, celana/rok hitam, dasi, dan almamater.<br>2. Menyerahkan naskah skripsi jilid softcover rangkap 3 kepada dewan penguji 15 menit sebelum sidang.<br>3. Menyiapkan slide presentasi materi (maksimal 15 menit).'; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>

    <!-- Jika Belum Terdaftar di Sidang Skripsi -->
    <div class="row g-4 mb-4">
        <!-- Form Pengajuan Pendaftaran Sidang -->
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="m-0 fw-bold text-dark">
                        <i class="bi bi-pencil-square text-primary me-2"></i>Formulir Pendaftaran Ujian Sidang
                    </h6>
                </div>
                <div class="card-body p-4">
                    <?php if ($is_eligible): ?>
                        <div class="alert alert-success small mb-3">
                            <i class="bi bi-check-circle-fill me-1"></i>
                            Selamat! Anda telah memenuhi syarat kelayakan bimbingan skripsi dan dapat mendaftarkan diri untuk jadwal sidang komprehensif.
                        </div>
                        <form action="informasi_sidang.php" method="POST">
                            <input type="hidden" name="action" value="daftar_sidang">

                            <div class="mb-3">
                                <label class="form-label small fw-semibold text-secondary">Dosen Pembimbing Utama</label>
                                <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($mhs['nama_dosen'] ?? '-'); ?>" readonly>
                            </div>

                            <div class="mb-4">
                                <label for="judul_final" class="form-label small fw-semibold text-secondary">
                                    Konfirmasi Judul Final Skripsi <span class="text-danger">*</span>
                                </label>
                                <textarea class="form-control" id="judul_final" name="judul_final" rows="3" required><?= htmlspecialchars($mhs['judul_skripsi'] ?? ''); ?></textarea>
                                <div class="form-text small" style="font-size: 0.75rem;">
                                    Pastikan judul final telah disetujui sepenuhnya oleh dosen pembimbing.
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold" onclick="return confirm('Apakah Anda yakin ingin mendaftarkan sidang skripsi dengan judul ini?')">
                                <i class="bi bi-mortarboard-fill me-1"></i>Ajukan Pendaftaran Sidang Skripsi
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="bi bi-hourglass-split text-warning fs-1 d-block mb-3"></i>
                            <h6 class="fw-bold text-dark">Belum Memenuhi Syarat Sidang</h6>
                            <p class="small text-muted mb-3">
                                Untuk mendaftar ujian sidang skripsi, Anda wajib menyelesaikan seluruh bab pembahasan (Bab 1 s.d. Bab 5) dengan status <strong>ACC Sidang</strong> dari dosen pembimbing.
                            </p>
                            <a href="upload_draft.php" class="btn btn-outline-primary btn-sm me-2">
                                <i class="bi bi-cloud-arrow-up me-1"></i>Unggah Draf Skripsi
                            </a>
                            <a href="logbook.php" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-journal-text me-1"></i>Cek Logbook
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Panduan Syarat Berkas Administrasi Sidang -->
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="m-0 fw-bold text-dark">
                        <i class="bi bi-card-checklist text-success me-2"></i>Persyaratan Pendaftaran Sidang Skripsi
                    </h6>
                </div>
                <div class="card-body p-4">
                    <ul class="list-group list-group-flush small">
                        <li class="list-group-item px-0 py-2 d-flex align-items-center">
                            <i class="bi bi-check-circle-fill text-success fs-5 me-3"></i>
                            <div>
                                <strong>Logbook Bimbingan Minimal:</strong> Telah melaksanakan konsultasi minimal 8 kali bimbingan resmi dan disetujui pembimbing.
                            </div>
                        </li>
                        <li class="list-group-item px-0 py-2 d-flex align-items-center">
                            <i class="bi bi-check-circle-fill text-success fs-5 me-3"></i>
                            <div>
                                <strong>Naskah Skripsi Lengkap:</strong> Seluruh Bab 1 sampai Bab 5 telah mendapatkan status ACC Selesai.
                            </div>
                        </li>
                        <li class="list-group-item px-0 py-2 d-flex align-items-center">
                            <i class="bi bi-check-circle-fill text-success fs-5 me-3"></i>
                            <div>
                                <strong>Persetujuan Dosen:</strong> Rekomendasi status <em>Siap Sidang</em> telah diaktifkan oleh dosen pembimbing di sistem.
                            </div>
                        </li>
                        <li class="list-group-item px-0 py-2 d-flex align-items-center">
                            <i class="bi bi-check-circle-fill text-success fs-5 me-3"></i>
                            <div>
                                <strong>Dewan Penguji Resmi:</strong> Admin program studi akan menunjuk 3 Dosen Penguji (Ketua Penguji, Penguji 2, dan Sekretaris).
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

<?php endif; ?>

<?php
require_once '../templates/footer.php';
?>

