<?php
// mahasiswa/index.php - Dashboard Mahasiswa & Pencatatan Progres Skripsi Terpadu
$page_title   = "Dashboard Mahasiswa";
$current_page = "dashboard";

require_once '../config/database.php';
require_once '../templates/header.php';
require_once '../templates/sidebar_mahasiswa.php';

// Proteksi akses role mahasiswa
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'mahasiswa') {
    header("Location: ../login.php");
    exit;
}

$success_msg = '';
$error_msg   = '';

// Ambil data profil mahasiswa beserta info dosen pembimbingnya
$stmt_mhs = $pdo->prepare("
    SELECT m.*, d.user_id AS dosen_user_id, d.nidn, d.nama_dosen, d.email AS email_dosen, d.no_whatsapp, d.foto_profil AS foto_dosen 
    FROM mahasiswa m 
    LEFT JOIN dosen d ON m.dosen_pembimbing_id = d.id 
    WHERE m.user_id = :uid LIMIT 1
");
$stmt_mhs->execute([':uid' => $_SESSION['user_id']]);
$mhs = $stmt_mhs->fetch();

$mhs_id = $mhs['id'] ?? 0;
$dosen_pembimbing_id = $mhs['dosen_pembimbing_id'] ?? null;
$status_pembimbing = $mhs['status_pembimbing'] ?? 'belum_diplot';

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

            $success_msg = "Selamat! Penerimaan SK Dosen Pembimbing berhasil dikonfirmasi. Anda kini dapat mengajukan jadwal bimbingan dan mengunggah draf skripsi.";
            
            // Refresh data mhs
            $stmt_mhs->execute([':uid' => $_SESSION['user_id']]);
            $mhs = $stmt_mhs->fetch();
            $status_pembimbing = $mhs['status_pembimbing'] ?? 'aktif';
        } catch (PDOException $e) {
            $error_msg = "Gagal mengonfirmasi SK: " . $e->getMessage();
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

// Ambil Notifikasi Aktif Mahasiswa (UPDATE Robi: tabel notifikasi)
$daftar_notif = [];
if (isset($_SESSION['user_id'])) {
    try {
        $stmt_n = $pdo->prepare("
            SELECT * FROM notifikasi 
            WHERE user_id = :uid 
            ORDER BY id DESC LIMIT 5
        ");
        $stmt_n->execute([':uid' => $_SESSION['user_id']]);
        $daftar_notif = $stmt_n->fetchAll();
    } catch (PDOException $e) {
        $daftar_notif = [];
    }
}

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

// Analisis progres bab 1 - 5
$bab_list = [
    'Bab 1' => ['label' => 'Bab 1: Pendahuluan', 'status' => 'belum'],
    'Bab 2' => ['label' => 'Bab 2: Tinjauan Pustaka', 'status' => 'belum'],
    'Bab 3' => ['label' => 'Bab 3: Metodologi Penelitian', 'status' => 'belum'],
    'Bab 4' => ['label' => 'Bab 4: Hasil & Pembahasan', 'status' => 'belum'],
    'Bab 5' => ['label' => 'Bab 5: Kesimpulan & Saran', 'status' => 'belum'],
];

$bab_acc_count = 0;
foreach ($riwayat_semua as $row) {
    foreach ($bab_list as $key => $val) {
        if (stripos($row['bab_pembahasan'], $key) !== false) {
            if (in_array($row['status'], ['selesai', 'disetujui']) && $val['status'] !== 'acc') {
                $bab_list[$key]['status'] = 'acc';
                $bab_acc_count++;
            } elseif ($row['status'] === 'revisi' && $val['status'] === 'belum') {
                $bab_list[$key]['status'] = 'revisi';
            } elseif ($row['status'] === 'menunggu' && $val['status'] === 'belum') {
                $bab_list[$key]['status'] = 'proses';
            }
        }
    }
}

// Hitung estimasi progres keseluruhan (berdasarkan 5 Bab @ 20%)
$persen_progres = min(100, $bab_acc_count * 20);
if ($mhs && $mhs['status_kelulusan'] === 'lulus') {
    $persen_progres = 100;
} elseif ($mhs && $mhs['status_kelulusan'] === 'siap_sidang' && $persen_progres < 90) {
    $persen_progres = 90;
}
?>

<!-- ============================================================
     HERO BANNER MAHASISWA MODERN & BERWARNA
============================================================ -->
<div class="student-hero-banner">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 position-relative" style="z-index: 2;">
        <div class="pe-md-3">
            <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                <span class="badge-kelas">
                    <i class="bi bi-mortarboard-fill"></i><?= htmlspecialchars($mhs['kelas'] ?? 'TI-1A-2025'); ?>
                </span>
                <span class="badge bg-white bg-opacity-25 text-white border border-white border-opacity-25 px-2 py-1 small rounded-pill">
                    <i class="bi bi-person-fill me-1"></i>Portal Mahasiswa
                </span>
            </div>
            <h2 class="fw-bold text-white mb-1">Selamat Datang Kembali, <?= htmlspecialchars($mhs['nama_lengkap'] ?? $_SESSION['username']); ?>!</h2>
            <p class="text-white-50 small mb-0">
                NIM: <strong class="text-white"><?= htmlspecialchars($mhs['nim'] ?? '-'); ?></strong> &bull; Pantau progres bimbingan, jadwal konsultasi, dan kesiapan ujian sidang tugas akhir Anda.
            </p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-shrink-0 flex-nowrap">
            <?php if ($status_pembimbing === 'aktif'): ?>
                <a href="ajukan_jadwal.php" class="btn btn-hero-action btn-hero-blue">
                    <i class="bi bi-calendar-plus"></i>Ajukan Jadwal
                </a>
                <a href="upload_draft.php" class="btn btn-hero-action btn-hero-white">
                    <i class="bi bi-cloud-arrow-up"></i>Upload Draft
                </a>
                <a href="chat.php" class="btn btn-hero-action btn-hero-emerald">
                    <i class="bi bi-chat-dots-fill"></i>Chat Dosen
                </a>
            <?php else: ?>
                <button class="btn btn-hero-action btn-hero-disabled" title="Konfirmasi SK terlebih dahulu di banner bawah">
                    <i class="bi bi-lock-fill"></i>Ajukan Jadwal
                </button>
            <?php endif; ?>
            <a href="cetak_laporan.php" target="_blank" class="btn btn-hero-action btn-hero-outline">
                <i class="bi bi-printer-fill"></i>Cetak Laporan
            </a>
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
                $icon_class = 'bi-info-circle-fill text-primary';
                $badge_class = 'bg-primary-subtle text-primary';
                if ($notif['tipe'] === 'pembimbing') {
                    $icon_class = 'bi-award-fill text-warning';
                    $badge_class = 'bg-warning-subtle text-warning-emphasis';
                } elseif ($notif['tipe'] === 'sidang') {
                    $icon_class = 'bi-mortarboard-fill text-primary';
                    $badge_class = 'bg-primary-subtle text-primary';
                } elseif ($notif['tipe'] === 'revisi') {
                    $icon_class = 'bi-arrow-repeat text-danger';
                    $badge_class = 'bg-danger-subtle text-danger';
                } elseif ($notif['tipe'] === 'sukses') {
                    $icon_class = 'bi-check-circle-fill text-success';
                    $badge_class = 'bg-success-subtle text-success';
                }
                $notif_link = !empty($notif['link_tujuan']) ? '?open_nid=' . $notif['id'] . '&dest=' . urlencode($notif['link_tujuan']) : '#';
            ?>
                <a href="<?= $notif_link; ?>" 
                   class="list-group-item list-group-item-action p-3 d-flex align-items-center justify-content-between <?= empty($notif['is_read']) ? 'bg-light bg-opacity-75' : ''; ?>">
                    <div class="d-flex align-items-center gap-3">
                        <div class="fs-4 <?= $icon_class; ?>">
                            <i class="bi <?= $icon_class; ?>"></i>
                        </div>
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                                <span class="fw-bold text-dark small"><?= htmlspecialchars($notif['judul']); ?></span>
                                <span class="badge <?= $badge_class; ?> small" style="font-size: 0.68rem;"><?= ucfirst($notif['tipe']); ?></span>
                                <?php if (empty($notif['is_read'])): ?>
                                    <span class="badge bg-danger rounded-pill" style="font-size: 0.6rem;">Baru</span>
                                <?php endif; ?>
                            </div>
                            <p class="mb-0 text-muted small"><?= htmlspecialchars($notif['pesan']); ?></p>
                        </div>
                    </div>
                    <small class="text-muted flex-shrink-0 ms-3" style="font-size: 0.75rem;">
                        <i class="bi bi-clock me-1"></i><?= date('d M Y, H:i', strtotime($notif['created_at'])); ?>
                    </small>
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
            <p class="text-muted small mb-3">
                NIM: <strong class="text-dark"><?= htmlspecialchars($mhs['nim'] ?? '-'); ?></strong> | 
                Nama: <strong class="text-dark"><?= htmlspecialchars($mhs['nama_lengkap'] ?? '-'); ?></strong> |
                Kelas: <strong class="text-dark"><?= htmlspecialchars($mhs['kelas'] ?? 'TI-1A-2025'); ?></strong>
            </p>

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

            <?php if (!empty($mhs['nama_dosen'])): ?>
                <div class="d-flex align-items-center mb-3">
                    <div class="me-3">
                        <?php if (!empty($mhs['foto_dosen']) && file_exists('../assets/uploads/' . $mhs['foto_dosen'])): ?>
                            <img src="../assets/uploads/<?= htmlspecialchars($mhs['foto_dosen']); ?>" class="rounded-circle border" width="55" height="55" style="object-fit: cover;" alt="Dosen">
                        <?php else: ?>
                            <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center fw-bold" style="width: 55px; height: 55px; font-size: 1.3rem;">
                                <?= strtoupper(substr($mhs['nama_dosen'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h6 class="fw-bold text-dark mb-0"><?= htmlspecialchars($mhs['nama_dosen']); ?></h6>
                        <span class="text-muted small">NIDN: <?= htmlspecialchars($mhs['nidn'] ?? '-'); ?></span>
                    </div>
                </div>

                <div class="bg-light p-2 rounded small mb-3 border">
                    <div class="mb-1">
                        <i class="bi bi-envelope text-primary me-2"></i>
                        <a href="mailto:<?= htmlspecialchars($mhs['email_dosen'] ?? ''); ?>" class="text-decoration-none text-secondary">
                            <?= htmlspecialchars($mhs['email_dosen'] ?? '-'); ?>
                        </a>
                    </div>
                    <div>
                        <i class="bi bi-whatsapp text-success me-2"></i>
                        <?php if (!empty($mhs['no_whatsapp'])): 
                            $clean_wa = preg_replace('/[^0-9]/', '', $mhs['no_whatsapp']);
                            if (str_starts_with($clean_wa, '0')) {
                                $clean_wa = '62' . substr($clean_wa, 1);
                            }
                        ?>
                            <a href="https://wa.me/<?= $clean_wa; ?>" target="_blank" class="text-decoration-none text-secondary">
                                <?= htmlspecialchars($mhs['no_whatsapp']); ?> <i class="bi bi-box-arrow-up-right small text-muted"></i>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mt-auto d-flex gap-2">
                    <a href="chat.php" class="btn btn-outline-success btn-sm w-100">
                        <i class="bi bi-chat-dots-fill me-1"></i>Chat di Web
                    </a>
                    <a href="jadwal_dosen.php" class="btn btn-outline-secondary btn-sm w-100">
                        <i class="bi bi-eye me-1"></i>Profil & Jadwal
                    </a>
                </div>
            <?php else: ?>
                <div class="alert alert-warning small mb-0 py-2">
                    <i class="bi bi-exclamation-triangle me-1"></i>Anda belum di-plot dosen pembimbing oleh Admin. Silakan hubungi bagian akademik/admin.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Milestone Tahapan Skripsi (Bab 1 - Bab 5) -->
<div class="card shadow-sm mb-4 border-0">
    <div class="card-header bg-white py-3 border-bottom">
        <h6 class="m-0 fw-bold text-dark">
            <i class="bi bi-check2-circle text-primary me-2"></i>Pencatatan Progres Tahapan Bab Skripsi
        </h6>
    </div>
    <div class="card-body">
        <div class="row g-3 text-center">
            <?php foreach ($bab_list as $b_key => $b_data): ?>
                <div class="col-6 col-md">
                    <div class="bab-step-card status-<?= $b_data['status']; ?> h-100 d-flex flex-column justify-content-between">
                        <div>
                            <div class="bab-icon-circle">
                                <?php if ($b_data['status'] === 'acc'): ?>
                                    <i class="bi bi-check2-circle"></i>
                                <?php elseif ($b_data['status'] === 'revisi'): ?>
                                    <i class="bi bi-arrow-repeat"></i>
                                <?php elseif ($b_data['status'] === 'proses'): ?>
                                    <i class="bi bi-hourglass-split"></i>
                                <?php else: ?>
                                    <i class="bi bi-dash-circle"></i>
                                <?php endif; ?>
                            </div>
                            <h6 class="fw-bold mb-1 small text-dark"><?= $b_key; ?></h6>
                            <p class="small text-muted mb-2" style="font-size: 0.75rem;"><?= htmlspecialchars($b_data['label']); ?></p>
                        </div>
                        <div>
                            <?php if ($b_data['status'] === 'acc'): ?>
                                <span class="badge bg-success">ACC Selesai</span>
                            <?php elseif ($b_data['status'] === 'revisi'): ?>
                                <span class="badge bg-warning text-dark">Perlu Revisi</span>
                            <?php elseif ($b_data['status'] === 'proses'): ?>
                                <span class="badge bg-info text-dark">Sedang Bimbingan</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Belum Mulai</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Statistik Ringkas Bimbingan (Modern & Berwarna) -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
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
    <div class="col-sm-6 col-xl-3">
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
    <div class="col-sm-6 col-xl-3">
        <div class="student-stat-card stat-amber">
            <div class="d-flex align-items-center">
                <div class="stat-icon-pill bg-gradient-amber me-3">
                    <i class="bi bi-clock-history"></i>
                </div>
                <div>
                    <span class="text-muted small fw-semibold d-block">Menunggu / Proses</span>
                    <h3 class="fw-bold mb-0 text-dark"><?= $bimbingan_menunggu; ?></h3>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
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
                                Belum ada riwayat pengajuan bimbingan. Silakan klik <a href="ajukan_jadwal.php">Ajukan Jadwal</a> untuk memulai.
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
                                            <a href="../assets/uploads/<?= htmlspecialchars($item['file_draft']); ?>" target="_blank" class="btn btn-outline-danger btn-sm py-0 px-2" style="font-size: 0.75rem;">
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

<?php
require_once '../templates/footer.php';
?>
