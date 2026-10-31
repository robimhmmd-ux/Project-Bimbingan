<?php
// dosen/index.php - Dashboard Dosen Pembimbing (Salsa)
$page_title   = "Dashboard Dosen";
$current_page = "dashboard";

require_once '../config/database.php';
require_once '../templates/header.php';
require_once '../templates/sidebar_dosen.php';

// Proteksi akses role dosen
if ($_SESSION['role'] !== 'dosen') {
    header("Location: ../login.php");
    exit;
}

// Ambil data dosen berdasarkan user_id aktif
$user_id = $_SESSION['user_id'];
$stmt_dosen = $pdo->prepare("SELECT * FROM dosen WHERE user_id = :uid LIMIT 1");
$stmt_dosen->execute([':uid' => $user_id]);
$dosen = $stmt_dosen->fetch();
$dosen_id = $dosen['id'] ?? 0;

// Metrik 1: Permintaan Menunggu Konfirmasi
$stmt_menunggu = $pdo->prepare("SELECT COUNT(*) FROM pengajuan_bimbingan WHERE dosen_id = :did AND status = 'menunggu'");
$stmt_menunggu->execute([':did' => $dosen_id]);
$count_menunggu = (int)$stmt_menunggu->fetchColumn();

// Metrik 2: Bimbingan Berjalan / Disetujui
$stmt_aktif = $pdo->prepare("SELECT COUNT(*) FROM pengajuan_bimbingan WHERE dosen_id = :did AND status = 'disetujui'");
$stmt_aktif->execute([':did' => $dosen_id]);
$count_aktif = (int)$stmt_aktif->fetchColumn();

// Metrik 3: Mahasiswa Bimbingan yang Di-plot ke Dosen Ini
$stmt_mhs = $pdo->prepare("SELECT COUNT(*) FROM mahasiswa WHERE dosen_pembimbing_id = :did");
$stmt_mhs->execute([':did' => $dosen_id]);
$count_mhs = (int)$stmt_mhs->fetchColumn();

// Metrik 4: Slot Jadwal Aktif
$stmt_jadwal = $pdo->prepare("SELECT COUNT(*) FROM jadwal_dosen WHERE dosen_id = :did AND status_aktif = 'aktif'");
$stmt_jadwal->execute([':did' => $dosen_id]);
$count_jadwal = (int)$stmt_jadwal->fetchColumn();

// Metrik 5: Pesan Belum Dibaca
$stmt_unread = $pdo->prepare("SELECT COUNT(*) FROM pesan_chat WHERE penerima_id = :uid AND status_baca = 'belum_dibaca'");
$stmt_unread->execute([':uid' => $user_id]);
$count_unread = (int)$stmt_unread->fetchColumn();

// Data 5 Pengajuan Bimbingan Terbaru
$stmt_terbaru = $pdo->prepare("
    SELECT pb.*, m.nim, m.nama_lengkap 
    FROM pengajuan_bimbingan pb
    JOIN mahasiswa m ON pb.mahasiswa_id = m.id
    WHERE pb.dosen_id = :did
    ORDER BY pb.created_at DESC 
    LIMIT 5
");
$stmt_terbaru->execute([':did' => $dosen_id]);
$pengajuan_terbaru = $stmt_terbaru->fetchAll();

// Cek foto profil
$foto_path = '../assets/uploads/profile/' . ($dosen['foto_profil'] ?? '');
$has_foto  = !empty($dosen['foto_profil']) && $dosen['foto_profil'] !== 'default.png' && file_exists($foto_path);
?>

<!-- Hero Banner Dashboard -->
<div class="hero-banner d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div class="d-flex align-items-center gap-3">
        <div class="position-relative">
            <?php if ($has_foto): ?>
                <img src="<?= htmlspecialchars($foto_path); ?>" 
                     alt="Foto Profil Dosen" 
                     class="rounded-circle img-thumbnail shadow border-2 border-white object-fit-cover" 
                     style="width: 70px; height: 70px;">
            <?php else: ?>
                <div class="avatar-circle avatar-primary shadow border border-2 border-white" style="width: 70px; height: 70px; font-size: 2rem;">
                    <i class="bi bi-person-fill text-white"></i>
                </div>
            <?php endif; ?>
            <span class="position-absolute bottom-0 end-0 p-1 bg-success border border-white rounded-circle"></span>
        </div>
        <div>
            <span class="badge bg-white bg-opacity-25 text-white mb-1 px-3 py-1 fw-semibold text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">
                Portal Dosen Pembimbing
            </span>
            <h2 class="fw-bold mb-1 text-white"><?= htmlspecialchars($dosen['nama_dosen'] ?? 'Bapak/Ibu Dosen'); ?></h2>
            <p class="mb-0 text-white text-opacity-75 small">
                NIDN: <strong><?= htmlspecialchars($dosen['nidn'] ?? '-'); ?></strong> &bull; Kuota: <strong><?= $count_mhs; ?> / <?= htmlspecialchars($dosen['kuota_bimbingan'] ?? '10'); ?> Mahasiswa</strong>
            </p>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="profil.php" class="btn btn-sm btn-outline-light px-3 py-2">
            <i class="bi bi-person-gear me-1"></i> Edit Profil
        </a>
        <a href="jadwal_saya.php" class="btn btn-sm btn-light text-primary px-3 py-2 fw-bold shadow-sm">
            <i class="bi bi-calendar-plus me-1"></i> Atur Ketersediaan
        </a>
    </div>
</div>

<!-- Alert Notifikasi Pengajuan Menunggu -->
<?php if ($count_menunggu > 0): ?>
    <div class="alert alert-warning d-flex align-items-center justify-content-between shadow-sm p-3 mb-4 border-warning">
        <div class="d-flex align-items-center gap-2">
            <span class="status-dot pending"></span>
            <div>
                <strong>Perhatian:</strong> Terdapat <strong><?= $count_menunggu; ?> permohonan bimbingan baru</strong> dari mahasiswa yang memerlukan konfirmasi Anda (Terima / Reschedule / Tolak).
            </div>
        </div>
        <a href="persetujuan.php" class="btn btn-warning btn-sm fw-bold px-3">
            Tinjau Permohonan &rarr;
        </a>
    </div>
<?php endif; ?>

<!-- 4 Kartu Metrik Modern -->
<div class="row g-3 mb-4">
    <!-- Mahasiswa Bimbingan -->
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card-modern p-3">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.04em;">Bimbingan Aktif</span>
                    <h3 class="fw-bold my-1 text-dark"><?= $count_mhs; ?> <span class="fs-6 text-muted fw-normal">/ <?= htmlspecialchars($dosen['kuota_bimbingan'] ?? '10'); ?></span></h3>
                    <a href="riwayat_bimbingan.php" class="small text-primary text-decoration-none fw-semibold">
                        Daftar Mahasiswa &rarr;
                    </a>
                </div>
                <div class="stat-icon-wrapper bg-primary-subtle text-primary">
                    <i class="bi bi-people-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Menunggu Konfirmasi -->
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card-modern p-3">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.04em;">Menunggu Respon</span>
                    <h3 class="fw-bold my-1 text-warning"><?= $count_menunggu; ?></h3>
                    <a href="persetujuan.php" class="small text-warning text-decoration-none fw-semibold">
                        Konfirmasi Jadwal &rarr;
                    </a>
                </div>
                <div class="stat-icon-wrapper bg-warning-subtle text-warning">
                    <i class="bi bi-hourglass-split"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Jadwal Disetujui -->
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card-modern p-3">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.04em;">Jadwal Disetujui</span>
                    <h3 class="fw-bold my-1 text-success"><?= $count_aktif; ?></h3>
                    <a href="riwayat_bimbingan.php" class="small text-success text-decoration-none fw-semibold">
                        Beri Nilai & ACC &rarr;
                    </a>
                </div>
                <div class="stat-icon-wrapper bg-success-subtle text-success">
                    <i class="bi bi-calendar-check-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Slot Waktu Aktif -->
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card-modern p-3">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.04em;">Slot Operasional</span>
                    <h3 class="fw-bold my-1 text-info"><?= $count_jadwal; ?> Slot</h3>
                    <a href="jadwal_saya.php" class="small text-info text-decoration-none fw-semibold">
                        Kelola Slot &rarr;
                    </a>
                </div>
                <div class="stat-icon-wrapper bg-info-subtle text-info">
                    <i class="bi bi-clock-history"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Row Konten Utama -->
<div class="row g-4">
    <!-- Tabel Pengajuan Terbaru -->
    <div class="col-lg-8">
        <div class="table-card-wrapper">
            <div class="p-3 border-bottom bg-white d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="mb-0 fw-bold text-dark">
                        <i class="bi bi-activity text-primary me-2"></i>Aktivitas Pengajuan Bimbingan Terbaru
                    </h6>
                    <small class="text-muted">Pantau status reservasi jadwal bimbingan skripsi terbaru</small>
                </div>
                <a href="persetujuan.php" class="btn btn-sm btn-outline-primary">Lihat Semua</a>
            </div>
            <div class="table-responsive">
                <table class="table table-modern table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Mahasiswa</th>
                            <th>Bab</th>
                            <th>Jadwal Bimbingan</th>
                            <th>Status</th>
                            <th class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pengajuan_terbaru)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-5 text-muted">
                                    <i class="bi bi-inbox fs-1 d-block mb-2 text-muted"></i>
                                    Belum ada pengajuan bimbingan dari mahasiswa.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pengajuan_terbaru as $row): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="avatar-circle avatar-sm avatar-primary">
                                                <?= strtoupper(substr($row['nama_lengkap'], 0, 1)); ?>
                                            </div>
                                            <div>
                                                <div class="fw-semibold text-dark"><?= htmlspecialchars($row['nama_lengkap']); ?></div>
                                                <small class="text-muted"><?= htmlspecialchars($row['nim']); ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border"><?= htmlspecialchars($row['bab_pembahasan']); ?></span>
                                    </td>
                                    <td>
                                        <div class="small fw-semibold text-dark">
                                            <?= date('d M Y', strtotime($row['tanggal_bimbingan'])); ?>
                                        </div>
                                        <small class="text-muted"><?= substr($row['jam_bimbingan'], 0, 5); ?> WIB</small>
                                    </td>
                                    <td>
                                        <?php
                                        $st = $row['status'];
                                        if ($st === 'menunggu') echo '<span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>Menunggu</span>';
                                        elseif ($st === 'disetujui') echo '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Disetujui</span>';
                                        elseif ($st === 'ditolak') echo '<span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Ditolak</span>';
                                        elseif ($st === 'revisi') echo '<span class="badge bg-info text-dark"><i class="bi bi-arrow-repeat me-1"></i>Revisi</span>';
                                        else echo '<span class="badge bg-secondary">' . ucfirst($st) . '</span>';
                                        ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($row['status'] === 'menunggu'): ?>
                                            <a href="persetujuan.php" class="btn btn-sm btn-primary">Tinjau</a>
                                        <?php else: ?>
                                            <a href="riwayat_bimbingan.php" class="btn btn-sm btn-outline-secondary">Detail</a>
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

    <!-- Panel Menu Cepat & Konsultasi -->
    <div class="col-lg-4">
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white py-3 border-bottom">
                <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-lightning-charge-fill me-2 text-warning"></i>Aksi Cepat Dosen</h6>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    <a href="jadwal_saya.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="avatar-circle avatar-sm avatar-primary">
                                <i class="bi bi-calendar3"></i>
                            </div>
                            <div>
                                <div class="fw-bold text-dark">Atur Ketersediaan Waktu</div>
                                <small class="text-muted">Kelola slot konsultasi mingguan</small>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right text-muted small"></i>
                    </a>
                    <a href="persetujuan.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="avatar-circle avatar-sm avatar-warning">
                                <i class="bi bi-inbox"></i>
                            </div>
                            <div>
                                <div class="fw-bold text-dark">Persetujuan & Reschedule</div>
                                <small class="text-muted">Terima atau jadwalkan ulang</small>
                            </div>
                        </div>
                        <?php if ($count_menunggu > 0): ?>
                            <span class="badge bg-danger rounded-pill"><?= $count_menunggu; ?></span>
                        <?php else: ?>
                            <i class="bi bi-chevron-right text-muted small"></i>
                        <?php endif; ?>
                    </a>
                    <a href="riwayat_bimbingan.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="avatar-circle avatar-sm avatar-success">
                                <i class="bi bi-journal-check"></i>
                            </div>
                            <div>
                                <div class="fw-bold text-dark">Evaluasi, Nilai & ACC</div>
                                <small class="text-muted">Unduh draft & beri pengesahan</small>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right text-muted small"></i>
                    </a>
                    <a href="cetak_laporan.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="avatar-circle avatar-sm bg-info-subtle text-info">
                                <i class="bi bi-printer"></i>
                            </div>
                            <div>
                                <div class="fw-bold text-dark">Cetak Rekapitulasi Laporan</div>
                                <small class="text-muted">Ekspor lembar bimbingan fisik</small>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right text-muted small"></i>
                    </a>
                    <a href="chat.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="avatar-circle avatar-sm avatar-primary">
                                <i class="bi bi-chat-quote"></i>
                            </div>
                            <div>
                                <div class="fw-bold text-dark">Konsultasi Chat Mahasiswa</div>
                                <small class="text-muted">Pesan langsung & bimbingan online</small>
                            </div>
                        </div>
                        <?php if ($count_unread > 0): ?>
                            <span class="badge bg-primary rounded-pill"><?= $count_unread; ?></span>
                        <?php else: ?>
                            <i class="bi bi-chevron-right text-muted small"></i>
                        <?php endif; ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once '../templates/footer.php';
?>
