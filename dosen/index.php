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

// Data 5 Pengajuan Bimbingan Terbaru
$stmt_terbaru = $pdo->prepare("
    SELECT pb.*, m.nim, m.nama_lengkap, m.kelas 
    FROM pengajuan_bimbingan pb
    JOIN mahasiswa m ON pb.mahasiswa_id = m.id
    WHERE pb.dosen_id = :did
    ORDER BY pb.created_at DESC 
    LIMIT 5
");
$stmt_terbaru->execute([':did' => $dosen_id]);
$pengajuan_terbaru = $stmt_terbaru->fetchAll();
?>

<!-- Header Halaman -->
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-2 pb-2 mb-4 border-bottom">
    <div>
        <h1 class="h2 fw-bold text-dark mb-1">Dashboard Dosen Pembimbing</h1>
        <p class="text-muted small mb-0">Selamat datang di portal pembimbing, <strong><?= htmlspecialchars($dosen['nama_dosen'] ?? 'Bapak/Ibu Dosen'); ?></strong>.</p>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <a href="mahasiswa_bimbingan.php" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-people me-1"></i> Mahasiswa Bimbingan
        </a>
        <a href="jadwal_saya.php" class="btn btn-sm btn-primary">
            <i class="bi bi-calendar-plus me-1"></i> Atur Ketersediaan
        </a>
    </div>
</div>

<!-- Alert Notifikasi Pengajuan Menunggu -->
<?php if ($count_menunggu > 0): ?>
    <div class="alert alert-warning d-flex align-items-center justify-content-between shadow-sm p-3 mb-4">
        <div>
            <i class="bi bi-bell-fill me-2 fs-5"></i>
            <span>Terdapat <strong><?= $count_menunggu; ?> permohonan bimbingan baru</strong> yang perlu Anda respon (Terima / Reschedule / Tolak).</span>
        </div>
        <a href="persetujuan.php" class="btn btn-warning btn-sm fw-bold px-3">Tinjau Sekarang &rarr;</a>
    </div>
<?php endif; ?>

<!-- 4 Kartu Metrik Bersih & Tegas -->
<div class="row g-3 mb-4">
    <!-- Profil & Kuota Bimbingan -->
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 shadow-sm border-0 border-start border-primary border-4 h-100 bg-white">
            <h6 class="text-muted small text-uppercase fw-semibold mb-1">Mahasiswa Bimbingan</h6>
            <h3 class="fw-bold text-dark mb-1"><?= $count_mhs; ?> <span class="fs-6 text-muted fw-normal">/ <?= htmlspecialchars($dosen['kuota_bimbingan'] ?? '10'); ?> Kuota</span></h3>
            <p class="text-muted small mb-2">NIDN: <?= htmlspecialchars($dosen['nidn'] ?? '-'); ?></p>
            <a href="mahasiswa_bimbingan.php" class="small text-decoration-none fw-bold text-primary">
                Cek Progres Mahasiswa &rarr;
            </a>
        </div>
    </div>

    <!-- Permintaan Menunggu Respon -->
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 shadow-sm border-0 border-start border-warning border-4 h-100 bg-white">
            <h6 class="text-muted small text-uppercase fw-semibold mb-1">Menunggu Respon</h6>
            <h3 class="fw-bold text-warning mb-1"><?= $count_menunggu; ?> <span class="fs-6 text-muted fw-normal">Permintaan</span></h3>
            <p class="text-muted small mb-2">Perlu ditindaklanjuti dosen</p>
            <a href="persetujuan.php" class="small text-decoration-none fw-bold text-warning">
                Tinjau Pengajuan &rarr;
            </a>
        </div>
    </div>

    <!-- Jadwal Bimbingan Disetujui -->
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 shadow-sm border-0 border-start border-success border-4 h-100 bg-white">
            <h6 class="text-muted small text-uppercase fw-semibold mb-1">Jadwal Disetujui</h6>
            <h3 class="fw-bold text-success mb-1"><?= $count_aktif; ?> <span class="fs-6 text-muted fw-normal">Sesi</span></h3>
            <p class="text-muted small mb-2">Siap untuk evaluasi naskah</p>
            <a href="riwayat_bimbingan.php" class="small text-decoration-none fw-bold text-success">
                Beri Nilai & ACC &rarr;
            </a>
        </div>
    </div>

    <!-- Slot Waktu Aktif -->
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 shadow-sm border-0 border-start border-info border-4 h-100 bg-white">
            <h6 class="text-muted small text-uppercase fw-semibold mb-1">Slot Waktu Aktif</h6>
            <h3 class="fw-bold text-info mb-1"><?= $count_jadwal; ?> <span class="fs-6 text-muted fw-normal">Slot</span></h3>
            <p class="text-muted small mb-2">Tampil pada jadwal mahasiswa</p>
            <a href="jadwal_saya.php" class="small text-decoration-none fw-bold text-info">
                Atur Slot Ketersediaan &rarr;
            </a>
        </div>
    </div>
</div>

<!-- Row Konten Utama -->
<div class="row g-4">
    <!-- Tabel Pengajuan Terbaru -->
    <div class="col-lg-8">
        <div class="card shadow-sm border-0 bg-white">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold text-dark">
                    <i class="bi bi-clock-history me-2 text-primary"></i>Aktivitas Pengajuan Bimbingan Terbaru
                </h6>
                <a href="persetujuan.php" class="btn btn-sm btn-outline-primary">Lihat Semua</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
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
                                        <i class="bi bi-inbox fs-2 d-block mb-1"></i>
                                        Belum ada permohonan bimbingan masuk dari mahasiswa.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($pengajuan_terbaru as $row): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($row['nama_lengkap']); ?></div>
                                            <div class="small text-muted">
                                                <span>NIM: <?= htmlspecialchars($row['nim']); ?></span>
                                                <?php if (!empty($row['kelas'])): ?>
                                                    <span class="badge bg-light text-primary border border-primary-subtle ms-1"><?= htmlspecialchars($row['kelas']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border"><?= htmlspecialchars($row['bab_pembahasan']); ?></span>
                                        </td>
                                        <td>
                                            <div class="small fw-semibold"><?= date('d M Y', strtotime($row['tanggal_bimbingan'])); ?></div>
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
    </div>

    <!-- Panel Menu Cepat -->
    <div class="col-lg-4">
        <div class="card shadow-sm border-0 mb-4 bg-white">
            <div class="card-header bg-white py-3 border-bottom">
                <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-lightning-charge me-2 text-warning"></i>Aksi Cepat Dosen</h6>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    <a href="mahasiswa_bimbingan.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3">
                        <div>
                            <div class="fw-bold"><i class="bi bi-people-fill text-primary me-2"></i>Mahasiswa Bimbingan & Progres</div>
                            <small class="text-muted">Cek capaian dan riwayat seluruh mahasiswa</small>
                        </div>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </a>
                    <a href="jadwal_saya.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3">
                        <div>
                            <div class="fw-bold"><i class="bi bi-calendar-plus text-primary me-2"></i>Atur Ketersediaan Waktu</div>
                            <small class="text-muted">Tentukan hari dan jam konsultasi mahasiswa</small>
                        </div>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </a>
                    <a href="persetujuan.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3">
                        <div>
                            <div class="fw-bold"><i class="bi bi-inbox text-warning me-2"></i>Persetujuan & Reschedule</div>
                            <small class="text-muted">Terima, tolak, atau atur ulang jadwal bimbingan</small>
                        </div>
                        <?php if ($count_menunggu > 0): ?>
                            <span class="badge bg-danger rounded-pill"><?= $count_menunggu; ?></span>
                        <?php else: ?>
                            <i class="bi bi-chevron-right text-muted"></i>
                        <?php endif; ?>
                    </a>
                    <a href="riwayat_bimbingan.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3">
                        <div>
                            <div class="fw-bold"><i class="bi bi-journal-check text-success me-2"></i>Evaluasi, Nilai & ACC</div>
                            <small class="text-muted">Unduh draft naskah & beri pengesahan bab</small>
                        </div>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </a>
                    <a href="sidang.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3">
                        <div>
                            <div class="fw-bold"><i class="bi bi-mortarboard-fill text-warning me-2"></i>Jadwal & Penguji Sidang</div>
                            <small class="text-muted">Tugas dewan penguji & penilaian sidang</small>
                        </div>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </a>
                    <a href="cetak_laporan.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3">
                        <div>
                            <div class="fw-bold"><i class="bi bi-printer text-info me-2"></i>Cetak Rekapitulasi Laporan</div>
                            <small class="text-muted">Ekspor lembar bimbingan fisik</small>
                        </div>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </a>
                    <a href="chat.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3">
                        <div>
                            <div class="fw-bold"><i class="bi bi-chat-dots text-primary me-2"></i>Konsultasi Chat Mahasiswa</div>
                            <small class="text-muted">Pesan langsung & konsultasi online</small>
                        </div>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </a>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0 bg-primary-subtle text-primary-emphasis p-3">
            <h6 class="fw-bold mb-1"><i class="bi bi-info-circle me-1"></i> Informasi Pembimbing</h6>
            <p class="small mb-0">Pastikan ketersediaan jadwal mingguan tetap diperbarui agar mahasiswa bimbingan dapat mengajukan permohonan bimbingan tanpa bentrok.</p>
        </div>
    </div>
</div>

<?php
require_once '../templates/footer.php';
?>
