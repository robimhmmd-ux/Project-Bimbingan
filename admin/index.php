<?php
// admin/index.php - Dashboard Administrator
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Proteksi akses role admin (dilakukan sebelum output HTML)
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$page_title   = "Dashboard Admin";
$current_page = "dashboard";

require_once '../config/database.php';
require_once '../templates/header.php';
require_once '../templates/sidebar_admin.php';

// Ambil metrik statistik
$total_mhs       = $pdo->query("SELECT COUNT(*) FROM mahasiswa")->fetchColumn();
$total_dosen     = $pdo->query("SELECT COUNT(*) FROM dosen")->fetchColumn();
$total_menunggu  = $pdo->query("SELECT COUNT(*) FROM pengajuan_bimbingan WHERE status = 'menunggu'")->fetchColumn();
$total_siap      = $pdo->query("SELECT COUNT(*) FROM mahasiswa WHERE status_kelulusan = 'siap_sidang'")->fetchColumn();
$total_lulus     = $pdo->query("SELECT COUNT(*) FROM mahasiswa WHERE status_kelulusan = 'lulus'")->fetchColumn();
$total_belum_plot= $pdo->query("SELECT COUNT(*) FROM mahasiswa WHERE dosen_pembimbing_id IS NULL")->fetchColumn();

// Ambil daftar mahasiswa terbaru
$mhs_terbaru = $pdo->query("
    SELECT m.*, d.nama_dosen 
    FROM mahasiswa m 
    LEFT JOIN dosen d ON m.dosen_pembimbing_id = d.id 
    ORDER BY m.id DESC LIMIT 5
")->fetchAll();
?>

<!-- Hero Banner Header -->
<div class="hero-banner d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
    <div>
        <div class="d-inline-flex align-items-center gap-2 px-3 py-1 rounded-pill bg-white bg-opacity-15 text-white small mb-2 border border-white border-opacity-10">
            <i class="bi bi-stars text-warning"></i>
            <span>Executive Command Center</span>
        </div>
        <h2 class="fw-bold text-white mb-1">Selamat Datang, <?= htmlspecialchars($_SESSION['username'] ?? 'Administrator'); ?>! 👋</h2>
        <p class="text-white-50 small mb-0">Kelola master data akademik, jadwal dosen, plotting bimbingan, dan evaluasi kelulusan skripsi.</p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <a href="plot_pembimbing.php" class="btn btn-light shadow-sm fw-bold">
            <i class="bi bi-diagram-3-fill text-primary me-1"></i> Plotting Pembimbing
            <?php if ($total_belum_plot > 0): ?>
                <span class="badge bg-danger ms-1"><?= $total_belum_plot; ?> Belum Diplot</span>
            <?php endif; ?>
        </a>
        <a href="laporan_plotting.php" class="btn btn-outline-light">
            <i class="bi bi-printer me-1"></i> Cetak Laporan
        </a>
    </div>
</div>

<!-- Kartu Metrik Statistik Modern -->
<div class="row g-3 mb-4">
    <!-- Card 1: Total Mahasiswa -->
    <div class="col-sm-6 col-xl-3">
        <div class="metric-card h-100 d-flex flex-column justify-content-between">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.05em;">Total Mahasiswa</span>
                    <h2 class="fw-bold text-dark mt-2 mb-0"><?= number_format($total_mhs); ?></h2>
                    <small class="text-muted">Terdaftar dalam sistem</small>
                </div>
                <div class="metric-icon-box bg-primary-subtle text-primary">
                    <i class="bi bi-people-fill"></i>
                </div>
            </div>
            <div class="pt-3 mt-3 border-top d-flex justify-content-between align-items-center">
                <a href="data_mahasiswa.php" class="text-primary text-decoration-none small fw-semibold">
                    Kelola data <i class="bi bi-arrow-right ms-1"></i>
                </a>
                <span class="badge bg-light text-muted border">Aktif</span>
            </div>
        </div>
    </div>

    <!-- Card 2: Dosen Pembimbing -->
    <div class="col-sm-6 col-xl-3">
        <div class="metric-card h-100 d-flex flex-column justify-content-between">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.05em;">Dosen Pembimbing</span>
                    <h2 class="fw-bold text-dark mt-2 mb-0"><?= number_format($total_dosen); ?></h2>
                    <small class="text-muted">Dosen aktif membimbing</small>
                </div>
                <div class="metric-icon-box bg-success-subtle text-success">
                    <i class="bi bi-person-badge-fill"></i>
                </div>
            </div>
            <div class="pt-3 mt-3 border-top d-flex justify-content-between align-items-center">
                <a href="data_dosen.php" class="text-success text-decoration-none small fw-semibold">
                    Kelola dosen <i class="bi bi-arrow-right ms-1"></i>
                </a>
                <span class="badge bg-success-subtle text-success border border-success-subtle">Kapasitas OK</span>
            </div>
        </div>
    </div>

    <!-- Card 3: Bimbingan Menunggu -->
    <div class="col-sm-6 col-xl-3">
        <div class="metric-card h-100 d-flex flex-column justify-content-between">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.05em;">Bimbingan Menunggu</span>
                    <h2 class="fw-bold text-dark mt-2 mb-0"><?= number_format($total_menunggu); ?></h2>
                    <small class="text-muted">Antrean aktif bimbingan</small>
                </div>
                <div class="metric-icon-box bg-warning-subtle text-warning">
                    <i class="bi bi-hourglass-split"></i>
                </div>
            </div>
            <div class="pt-3 mt-3 border-top d-flex justify-content-between align-items-center">
                <span class="small text-muted">Perlu respons dosen</span>
                <span class="badge bg-warning-subtle text-warning border border-warning-subtle">Pending</span>
            </div>
        </div>
    </div>

    <!-- Card 4: Status Kelulusan -->
    <div class="col-sm-6 col-xl-3">
        <div class="metric-card h-100 d-flex flex-column justify-content-between">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.05em;">Status Kelulusan</span>
                    <h3 class="fw-bold text-dark mt-2 mb-0"><?= $total_siap; ?> <small class="fs-6 fw-normal text-muted">Sidang</small> | <?= $total_lulus; ?> <small class="fs-6 fw-normal text-muted">Lulus</small></h3>
                    <small class="text-muted"><?= $total_mhs - ($total_siap + $total_lulus); ?> Masih Bimbingan</small>
                </div>
                <div class="metric-icon-box bg-info-subtle text-info">
                    <i class="bi bi-mortarboard-fill"></i>
                </div>
            </div>
            <div class="pt-3 mt-3 border-top d-flex justify-content-between align-items-center">
                <a href="monitoring_kelulusan.php" class="text-info text-decoration-none small fw-semibold">
                    Monitoring <i class="bi bi-arrow-right ms-1"></i>
                </a>
                <span class="badge bg-info-subtle text-info border border-info-subtle">Angkatan</span>
            </div>
        </div>
    </div>
</div>

<!-- Aksi Cepat & Data Mahasiswa Terbaru -->
<div class="row g-4 mb-4">
    <!-- Tabel Mahasiswa Terdaftar Terbaru -->
    <div class="col-lg-8">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2">
                    <span class="p-2 rounded bg-primary-subtle text-primary">
                        <i class="bi bi-person-lines-fill"></i>
                    </span>
                    <div>
                        <h6 class="fw-bold mb-0 text-dark">Data Mahasiswa Terdaftar Terbaru</h6>
                        <small class="text-muted">5 mahasiswa paling baru didaftarkan ke sistem</small>
                    </div>
                </div>
                <a href="data_mahasiswa.php" class="btn btn-sm btn-outline-primary">Lihat Semua</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Mahasiswa</th>
                                <th>Judul Penelitian Skripsi</th>
                                <th>Dosen Pembimbing</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($mhs_terbaru)): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-5">
                                        <i class="bi bi-inbox fs-2 d-block text-secondary mb-2"></i>
                                        Belum ada data mahasiswa terdaftar.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($mhs_terbaru as $m): 
                                    $initial = strtoupper(substr($m['nama_lengkap'], 0, 1));
                                ?>
                                    <tr>
                                        <td class="ps-3">
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="avatar-initial bg-primary-subtle text-primary">
                                                    <?= $initial; ?>
                                                </div>
                                                <div>
                                                    <div class="fw-bold text-dark"><?= htmlspecialchars($m['nama_lengkap']); ?></div>
                                                    <span class="font-monospace text-muted small">NIM: <?= htmlspecialchars($m['nim']); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="text-muted small d-block text-truncate" style="max-width: 250px;" title="<?= htmlspecialchars($m['judul_skripsi']); ?>">
                                                <?= htmlspecialchars($m['judul_skripsi'] ?: 'Judul belum ditentukan'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($m['nama_dosen']): ?>
                                                <span class="text-dark small fw-semibold">
                                                    <i class="bi bi-check-circle-fill text-success me-1"></i><?= htmlspecialchars($m['nama_dosen']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">
                                                    <i class="bi bi-exclamation-triangle me-1"></i>Belum Diplot
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($m['status_kelulusan'] === 'lulus'): ?>
                                                <span class="badge bg-success"><i class="bi bi-check2-all me-1"></i>Lulus</span>
                                            <?php elseif ($m['status_kelulusan'] === 'siap_sidang'): ?>
                                                <span class="badge bg-primary"><i class="bi bi-award me-1"></i>Siap Sidang</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary"><i class="bi bi-clock-history me-1"></i>Bimbingan</span>
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

    <!-- Panel Pintasan Aksi Cepat -->
    <div class="col-lg-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-3">
                <div class="d-flex align-items-center gap-2">
                    <span class="p-2 rounded bg-warning-subtle text-warning">
                        <i class="bi bi-lightning-charge-fill"></i>
                    </span>
                    <div>
                        <h6 class="fw-bold mb-0 text-dark">Pintasan Layanan Akademik</h6>
                        <small class="text-muted">Aksi langsung untuk administrator</small>
                    </div>
                </div>
            </div>
            <div class="card-body d-flex flex-column gap-2 p-3">
                <!-- Action 1 -->
                <a href="data_dosen.php" class="btn btn-outline-primary d-flex align-items-center justify-content-between p-3 text-start border bg-light bg-opacity-50">
                    <div class="d-flex align-items-center gap-3">
                        <div class="p-2 rounded-3 bg-primary-subtle text-primary">
                            <i class="bi bi-person-badge fs-5"></i>
                        </div>
                        <div>
                            <div class="fw-bold text-dark">Kelola & Kuota Dosen</div>
                            <small class="text-muted">Master dosen & kapasitas mahasiswa</small>
                        </div>
                    </div>
                    <i class="bi bi-chevron-right text-muted"></i>
                </a>

                <!-- Action 2 -->
                <a href="jadwal_dosen.php" class="btn btn-outline-warning d-flex align-items-center justify-content-between p-3 text-start border bg-light bg-opacity-50">
                    <div class="d-flex align-items-center gap-3">
                        <div class="p-2 rounded-3 bg-warning-subtle text-warning">
                            <i class="bi bi-calendar2-week fs-5"></i>
                        </div>
                        <div>
                            <div class="fw-bold text-dark">Jadwal Harian Dosen</div>
                            <small class="text-muted">Atur kesibukan & slot hari bimbingan</small>
                        </div>
                    </div>
                    <i class="bi bi-chevron-right text-muted"></i>
                </a>

                <!-- Action 3 -->
                <a href="data_mahasiswa.php" class="btn btn-outline-info d-flex align-items-center justify-content-between p-3 text-start border bg-light bg-opacity-50">
                    <div class="d-flex align-items-center gap-3">
                        <div class="p-2 rounded-3 bg-info-subtle text-info">
                            <i class="bi bi-people fs-5"></i>
                        </div>
                        <div>
                            <div class="fw-bold text-dark">Data Mahasiswa & Nilai</div>
                            <small class="text-muted">Pendaftaran, judul skripsi & kelulusan</small>
                        </div>
                    </div>
                    <i class="bi bi-chevron-right text-muted"></i>
                </a>

                <!-- Action 4 -->
                <a href="plot_pembimbing.php" class="btn btn-outline-success d-flex align-items-center justify-content-between p-3 text-start border bg-light bg-opacity-50">
                    <div class="d-flex align-items-center gap-3">
                        <div class="p-2 rounded-3 bg-success-subtle text-success">
                            <i class="bi bi-diagram-3 fs-5"></i>
                        </div>
                        <div>
                            <div class="fw-bold text-dark">Plotting Pembimbing</div>
                            <small class="text-muted">Petakan mahasiswa ke dosen pembimbing</small>
                        </div>
                    </div>
                    <i class="bi bi-chevron-right text-muted"></i>
                </a>
            </div>
        </div>
    </div>
</div>

<?php
require_once '../templates/footer.php';
?>
