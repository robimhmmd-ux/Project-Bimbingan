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

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2 fw-bold text-dark mb-1">Dashboard Administrator</h1>
        <p class="text-muted small mb-0">Selamat datang di Panel Administrasi Sistem Bimbingan Skripsi.</p>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="plot_pembimbing.php" class="btn btn-sm btn-primary me-2">
            <i class="bi bi-diagram-3 me-1"></i> Plotting Pembimbing
            <?php if ($total_belum_plot > 0): ?>
                <span class="badge bg-danger ms-1"><?= $total_belum_plot; ?> Belum Diplot</span>
            <?php endif; ?>
        </a>
    </div>
</div>

<!-- Kartu Metrik Statistik -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm bg-primary text-white h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-white-50 small fw-semibold text-uppercase">Total Mahasiswa</span>
                    <h2 class="fw-bold mt-1 mb-0"><?= number_format($total_mhs); ?></h2>
                    <small class="text-white-50">Terdaftar di sistem</small>
                </div>
                <div class="bg-white bg-opacity-25 rounded-3 p-3">
                    <i class="bi bi-people-fill fs-2"></i>
                </div>
            </div>
            <div class="card-footer bg-transparent border-0 pt-0 pb-2">
                <a href="data_mahasiswa.php" class="text-white text-decoration-none small">Lihat detail <i class="bi bi-arrow-right"></i></a>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm bg-success text-white h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-white-50 small fw-semibold text-uppercase">Dosen Pembimbing</span>
                    <h2 class="fw-bold mt-1 mb-0"><?= number_format($total_dosen); ?></h2>
                    <small class="text-white-50">Aktif mengajar</small>
                </div>
                <div class="bg-white bg-opacity-25 rounded-3 p-3">
                    <i class="bi bi-person-badge-fill fs-2"></i>
                </div>
            </div>
            <div class="card-footer bg-transparent border-0 pt-0 pb-2">
                <a href="data_dosen.php" class="text-white text-decoration-none small">Lihat detail <i class="bi bi-arrow-right"></i></a>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm bg-warning text-dark h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-dark-50 small fw-semibold text-uppercase">Bimbingan Menunggu</span>
                    <h2 class="fw-bold mt-1 mb-0"><?= number_format($total_menunggu); ?></h2>
                    <small class="text-muted">Perlu respons dosen</small>
                </div>
                <div class="bg-dark bg-opacity-10 rounded-3 p-3">
                    <i class="bi bi-hourglass-split fs-2"></i>
                </div>
            </div>
            <div class="card-footer bg-transparent border-0 pt-0 pb-2">
                <span class="text-dark small">Antrean aktif bimbingan</span>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm bg-info text-white h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-white-50 small fw-semibold text-uppercase">Status Kelulusan</span>
                    <h4 class="fw-bold mt-1 mb-0"><?= $total_siap; ?> <small class="fs-6 fw-normal">Sidang</small> | <?= $total_lulus; ?> <small class="fs-6 fw-normal">Lulus</small></h4>
                    <small class="text-white-50"><?= $total_mhs - ($total_siap + $total_lulus); ?> Masih Bimbingan</small>
                </div>
                <div class="bg-white bg-opacity-25 rounded-3 p-3">
                    <i class="bi bi-mortarboard-fill fs-2"></i>
                </div>
            </div>
            <div class="card-footer bg-transparent border-0 pt-0 pb-2">
                <a href="data_mahasiswa.php" class="text-white text-decoration-none small">Kelola kelulusan <i class="bi bi-arrow-right"></i></a>
            </div>
        </div>
    </div>
</div>

<!-- Aksi Cepat & Data Mahasiswa Terbaru -->
<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark">
                    <i class="bi bi-person-lines-fill text-primary me-2"></i>Data Mahasiswa Terdaftar Terbaru
                </h6>
                <a href="data_mahasiswa.php" class="btn btn-sm btn-outline-primary">Semua Mahasiswa</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">NIM</th>
                                <th>Nama Lengkap</th>
                                <th>Dosen Pembimbing</th>
                                <th>Status Kelulusan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($mhs_terbaru)): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">Belum ada data mahasiswa.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($mhs_terbaru as $m): ?>
                                    <tr>
                                        <td class="ps-3 font-monospace fw-semibold text-primary"><?= htmlspecialchars($m['nim']); ?></td>
                                        <td>
                                            <div class="fw-bold"><?= htmlspecialchars($m['nama_lengkap']); ?></div>
                                            <small class="text-muted d-block text-truncate" style="max-width: 250px;">
                                                <?= htmlspecialchars($m['judul_skripsi'] ?: 'Judul belum ditentukan'); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?php if ($m['nama_dosen']): ?>
                                                <span class="text-dark"><i class="bi bi-check-circle-fill text-success me-1"></i><?= htmlspecialchars($m['nama_dosen']); ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>Belum Diplot</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($m['status_kelulusan'] === 'lulus'): ?>
                                                <span class="badge bg-success"><i class="bi bi-check2-all me-1"></i>Lulus</span>
                                            <?php elseif ($m['status_kelulusan'] === 'siap_sidang'): ?>
                                                <span class="badge bg-primary"><i class="bi bi-award me-1"></i>Siap Sidang</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary"><i class="bi bi-clock-history me-1"></i>Proses Bimbingan</span>
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

    <div class="col-lg-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-3">
                <h6 class="fw-bold mb-0 text-dark">
                    <i class="bi bi-lightning-charge-fill text-warning me-2"></i>Aksi Cepat Administrator
                </h6>
            </div>
            <div class="card-body d-flex flex-column gap-3">
                <a href="data_dosen.php" class="btn btn-outline-primary d-flex align-items-center justify-content-between p-3 text-start">
                    <div>
                        <div class="fw-bold"><i class="bi bi-person-plus me-2"></i>Kelola & Tambah Dosen</div>
                        <small class="text-muted">Atur data dosen dan kapasitas kuota bimbingan</small>
                    </div>
                    <i class="bi bi-chevron-right"></i>
                </a>

                <a href="data_mahasiswa.php" class="btn btn-outline-success d-flex align-items-center justify-content-between p-3 text-start">
                    <div>
                        <div class="fw-bold"><i class="bi bi-people me-2"></i>Kelola Mahasiswa & Nilai</div>
                        <small class="text-muted">Pendaftaran mahasiswa, edit data & status kelulusan</small>
                    </div>
                    <i class="bi bi-chevron-right"></i>
                </a>

                <a href="plot_pembimbing.php" class="btn btn-outline-info d-flex align-items-center justify-content-between p-3 text-start">
                    <div>
                        <div class="fw-bold"><i class="bi bi-diagram-3 me-2"></i>Plotting Dosen Pembimbing</div>
                        <small class="text-muted">Petakan mahasiswa ke dosen pembimbing yang tepat</small>
                    </div>
                    <i class="bi bi-chevron-right"></i>
                </a>

                <div class="p-3 bg-light rounded border mt-auto">
                    <div class="small fw-semibold text-secondary mb-1"><i class="bi bi-info-circle me-1"></i>Informasi Sistem</div>
                    <div class="small text-muted">
                        Database terhubung ke <strong>TiDB Serverless Cloud</strong>. Semua operasi CRUD memakai <em>Prepared Statements</em> dan <em>Transactions</em>.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once '../templates/footer.php';
?>
