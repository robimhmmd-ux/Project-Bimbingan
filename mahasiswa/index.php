<?php
// mahasiswa/index.php - Dashboard Mahasiswa
$page_title   = "Dashboard Mahasiswa";
$current_page = "dashboard";

require_once '../config/database.php';
require_once '../templates/header.php';
require_once '../templates/sidebar_mahasiswa.php';

// Proteksi akses role mahasiswa
if ($_SESSION['role'] !== 'mahasiswa') {
    header("Location: ../login.php");
    exit;
}

// Ambil data mahasiswa beserta info dosen pembimbingnya
$stmt_mhs = $pdo->prepare("
    SELECT m.*, d.nama_dosen, d.email AS email_dosen, d.no_whatsapp 
    FROM mahasiswa m 
    LEFT JOIN dosen d ON m.dosen_pembimbing_id = d.id 
    WHERE m.user_id = :uid LIMIT 1
");
$stmt_mhs->execute([':uid' => $_SESSION['user_id']]);
$mhs = $stmt_mhs->fetch();
$mhs_id = $mhs['id'] ?? 0;
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Dashboard Mahasiswa</h1>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-7">
        <div class="card p-3 shadow-sm border-start border-primary border-4 h-100">
            <h6 class="text-muted">Informasi Skripsi</h6>
            <h5 class="fw-bold text-dark"><?= htmlspecialchars($mhs['judul_skripsi'] ?? 'Judul Belum Diatur'); ?></h5>
            <p class="mb-0 text-muted small">
                NIM: <strong><?= htmlspecialchars($mhs['nim'] ?? '-'); ?></strong> | 
                Nama: <strong><?= htmlspecialchars($mhs['nama_lengkap'] ?? '-'); ?></strong>
            </p>
        </div>
    </div>
    <div class="col-md-5">
        <div class="card p-3 shadow-sm border-start border-success border-4 h-100">
            <h6 class="text-muted">Dosen Pembimbing</h6>
            <h5 class="fw-bold text-dark"><?= htmlspecialchars($mhs['nama_dosen'] ?? 'Belum Di-plot Admin'); ?></h5>
            <?php if (!empty($mhs['nama_dosen'])): ?>
                <p class="mb-0 text-muted small">
                    <i class="bi bi-envelope me-1"></i><?= htmlspecialchars($mhs['email_dosen'] ?? '-'); ?><br>
                    <i class="bi bi-whatsapp me-1 text-success"></i><?= htmlspecialchars($mhs['no_whatsapp'] ?? '-'); ?>
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card shadow-sm p-4">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h5 class="mb-1">Jadwalkan Bimbingan Baru</h5>
            <p class="text-muted mb-0 small">Pastikan draft bab skripsi sudah siap sebelum mengajukan jadwal.</p>
        </div>
        <a href="ajukan_jadwal.php" class="btn btn-primary">
            <i class="bi bi-calendar-plus me-1"></i>Ajukan Sekarang
        </a>
    </div>
</div>

<?php
require_once '../templates/footer.php';
?>
