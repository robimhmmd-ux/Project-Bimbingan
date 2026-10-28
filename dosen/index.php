<?php
// dosen/index.php - Dashboard Dosen
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

// Ambil data dosen berdasarkan user_id
$stmt_dosen = $pdo->prepare("SELECT * FROM dosen WHERE user_id = :uid LIMIT 1");
$stmt_dosen->execute([':uid' => $_SESSION['user_id']]);
$dosen = $stmt_dosen->fetch();
$dosen_id = $dosen['id'] ?? 0;
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Dashboard Dosen Pembimbing</h1>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card p-3 shadow-sm border-start border-primary border-4">
            <h6 class="text-muted">Profil Dosen</h6>
            <h4 class="fw-bold mb-1"><?= htmlspecialchars($dosen['nama_dosen'] ?? 'Nama Dosen'); ?></h4>
            <p class="mb-0 text-muted small">NIDN: <?= htmlspecialchars($dosen['nidn'] ?? '-'); ?> | Kuota: <?= htmlspecialchars($dosen['kuota_bimbingan'] ?? '10'); ?> Mahasiswa</p>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card p-3 shadow-sm border-start border-warning border-4">
            <h6 class="text-muted">Permintaan Bimbingan Masuk</h6>
            <h4 class="fw-bold mb-1">
                <?php
                $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM pengajuan_bimbingan WHERE dosen_id = :did AND status = 'menunggu'");
                $stmt_count->execute([':did' => $dosen_id]);
                echo $stmt_count->fetchColumn();
                ?> Permintaan
            </h4>
            <p class="mb-0 text-muted small">Perlu ditindaklanjuti (Setujui / Tolak / Revisi)</p>
        </div>
    </div>
</div>

<div class="card shadow-sm p-4">
    <h5>Selamat Datang di Portal Pembimbing</h5>
    <p class="text-muted mb-0">Silakan atur ketersediaan waktu bimbingan Anda di menu <strong>Atur Ketersediaan</strong> agar mahasiswa bimbingan dapat mengajukan jadwal.</p>
</div>

<?php
require_once '../templates/footer.php';
?>
