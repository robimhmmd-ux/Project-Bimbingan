<?php
// admin/index.php - Dashboard Administrator
$page_title   = "Dashboard Admin";
$current_page = "dashboard";

require_once '../config/database.php';
require_once '../templates/header.php';
require_once '../templates/sidebar_admin.php';

// Proteksi akses role admin
if ($_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Dashboard Administrator</h1>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card bg-primary text-white p-3 shadow-sm">
            <h6>Total Mahasiswa</h6>
            <h3 class="fw-bold mb-0">
                <?php
                $stmt = $pdo->query("SELECT COUNT(*) FROM mahasiswa");
                echo $stmt->fetchColumn();
                ?>
            </h3>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card bg-success text-white p-3 shadow-sm">
            <h6>Total Dosen Pembimbing</h6>
            <h3 class="fw-bold mb-0">
                <?php
                $stmt = $pdo->query("SELECT COUNT(*) FROM dosen");
                echo $stmt->fetchColumn();
                ?>
            </h3>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card bg-warning text-dark p-3 shadow-sm">
            <h6>Bimbingan Menunggu</h6>
            <h3 class="fw-bold mb-0">
                <?php
                $stmt = $pdo->query("SELECT COUNT(*) FROM pengajuan_bimbingan WHERE status = 'menunggu'");
                echo $stmt->fetchColumn();
                ?>
            </h3>
        </div>
    </div>
</div>

<div class="card shadow-sm p-4">
    <h5>Selamat Datang, Administrator</h5>
    <p class="text-muted mb-0">Gunakan menu di sebelah kiri untuk mengelola data dosen, data mahasiswa, dan memasangkan pembimbing skripsi.</p>
</div>

<?php
require_once '../templates/footer.php';
?>
