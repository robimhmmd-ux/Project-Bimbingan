<!-- templates/sidebar_dosen.php -->
<?php
// Hitung notifikasi & ambil profil dosen untuk sidebar
$badge_menunggu = 0;
$badge_chat = 0;
$dosen_side = null;

if (isset($pdo) && isset($_SESSION['user_id'])) {
    try {
        $st_dosen_side = $pdo->prepare("SELECT id, nama_dosen, nidn, foto_profil FROM dosen WHERE user_id = :uid LIMIT 1");
        $st_dosen_side->execute([':uid' => $_SESSION['user_id']]);
        $dosen_side = $st_dosen_side->fetch();

        if ($dosen_side) {
            $sidebar_dosen_id = $dosen_side['id'];
            $st_cnt = $pdo->prepare("SELECT COUNT(*) FROM pengajuan_bimbingan WHERE dosen_id = :did AND status = 'menunggu'");
            $st_cnt->execute([':did' => $sidebar_dosen_id]);
            $badge_menunggu = (int)$st_cnt->fetchColumn();
        }

        $st_chat = $pdo->prepare("SELECT COUNT(*) FROM pesan_chat WHERE penerima_id = :uid AND status_baca = 'belum_dibaca'");
        $st_chat->execute([':uid' => $_SESSION['user_id']]);
        $badge_chat = (int)$st_chat->fetchColumn();
    } catch (Exception $e) {
        // Fallback
    }
}

$side_foto_path = '../assets/uploads/profile/' . ($dosen_side['foto_profil'] ?? '');
$side_has_foto  = !empty($dosen_side['foto_profil']) && $dosen_side['foto_profil'] !== 'default.png' && file_exists($side_foto_path);
?>
<nav class="col-md-3 col-lg-2 d-md-block bg-white sidebar shadow-sm p-3 min-vh-100">
    <div class="position-sticky">
        <!-- Mini Profil Dosen di Sidebar -->
        <div class="d-flex align-items-center p-2 mb-3 bg-light rounded-3 border">
            <div class="position-relative me-2 flex-shrink-0">
                <?php if ($side_has_foto): ?>
                    <img src="<?= htmlspecialchars($side_foto_path); ?>" 
                         alt="Foto Dosen" 
                         class="rounded-circle object-fit-cover shadow-sm" 
                         style="width: 42px; height: 42px;">
                <?php else: ?>
                    <div class="avatar-circle avatar-primary shadow-sm" style="width: 42px; height: 42px; font-size: 1.1rem;">
                        <i class="bi bi-person-fill"></i>
                    </div>
                <?php endif; ?>
                <span class="position-absolute bottom-0 end-0 p-1 bg-success border border-white rounded-circle"></span>
            </div>
            <div class="overflow-hidden flex-grow-1">
                <div class="fw-bold text-dark text-truncate small" title="<?= htmlspecialchars($dosen_side['nama_dosen'] ?? 'Dosen'); ?>">
                    <?= htmlspecialchars($dosen_side['nama_dosen'] ?? 'Dosen'); ?>
                </div>
                <div class="text-muted text-truncate" style="font-size: 0.72rem;">
                    NIDN: <?= htmlspecialchars($dosen_side['nidn'] ?? '-'); ?>
                </div>
            </div>
        </div>

        <h6 class="sidebar-heading px-2 mt-2 mb-2 text-muted text-uppercase small fw-bold">Navigasi Utama</h6>
        <ul class="nav flex-column nav-pills">
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'dashboard') ? 'active' : 'text-dark'; ?>" href="index.php">
                    <i class="bi bi-grid-1x2-fill me-2"></i>Dashboard
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'jadwal') ? 'active' : 'text-dark'; ?>" href="jadwal_saya.php">
                    <i class="bi bi-calendar3-range me-2"></i>Atur Ketersediaan
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link d-flex justify-content-between align-items-center <?= ($current_page == 'persetujuan') ? 'active' : 'text-dark'; ?>" href="persetujuan.php">
                    <span><i class="bi bi-inbox-fill me-2"></i>Pengajuan Bimbingan</span>
                    <?php if ($badge_menunggu > 0): ?>
                        <span class="badge bg-danger rounded-pill shadow-sm"><?= $badge_menunggu; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'riwayat') ? 'active' : 'text-dark'; ?>" href="riwayat_bimbingan.php">
                    <i class="bi bi-journal-check me-2"></i>Riwayat & Nilai
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'laporan') ? 'active' : 'text-dark'; ?>" href="cetak_laporan.php">
                    <i class="bi bi-printer-fill me-2"></i>Cetak Laporan
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link d-flex justify-content-between align-items-center <?= ($current_page == 'chat') ? 'active' : 'text-dark'; ?>" href="chat.php">
                    <span><i class="bi bi-chat-quote-fill me-2"></i>Konsultasi Chat</span>
                    <?php if ($badge_chat > 0): ?>
                        <span class="badge bg-primary rounded-pill shadow-sm"><?= $badge_chat; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            
            <li class="nav-item mt-3 pt-3 border-top">
                <a class="nav-link <?= ($current_page == 'profil') ? 'active' : 'text-dark'; ?>" href="profil.php">
                    <i class="bi bi-person-gear me-2"></i>Profil & Akun
                </a>
            </li>
        </ul>
    </div>
</nav>
<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
