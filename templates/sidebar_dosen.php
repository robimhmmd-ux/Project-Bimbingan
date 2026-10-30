<!-- templates/sidebar_dosen.php -->
<?php
// Hitung notifikasi untuk menu dosen jika koneksi pdo tersedia
$badge_menunggu = 0;
$badge_chat = 0;

if (isset($pdo) && isset($_SESSION['user_id'])) {
    try {
        $st_did = $pdo->prepare("SELECT id FROM dosen WHERE user_id = :uid LIMIT 1");
        $st_did->execute([':uid' => $_SESSION['user_id']]);
        $sidebar_dosen_id = $st_did->fetchColumn();

        if ($sidebar_dosen_id) {
            $st_cnt = $pdo->prepare("SELECT COUNT(*) FROM pengajuan_bimbingan WHERE dosen_id = :did AND status = 'menunggu'");
            $st_cnt->execute([':did' => $sidebar_dosen_id]);
            $badge_menunggu = (int)$st_cnt->fetchColumn();
        }

        $st_chat = $pdo->prepare("SELECT COUNT(*) FROM pesan_chat WHERE penerima_id = :uid AND status_baca = 'belum_dibaca'");
        $st_chat->execute([':uid' => $_SESSION['user_id']]);
        $badge_chat = (int)$st_chat->fetchColumn();
    } catch (Exception $e) {
        // Fallback jika belum siap
    }
}
?>
<nav class="col-md-3 col-lg-2 d-md-block bg-white sidebar shadow-sm p-3 min-vh-100">
    <div class="position-sticky">
        <h6 class="sidebar-heading px-3 mt-2 mb-3 text-muted text-uppercase small fw-bold">Menu Dosen</h6>
        <ul class="nav flex-column nav-pills">
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'dashboard') ? 'active' : 'text-dark'; ?>" href="index.php">
                    <i class="bi bi-speedometer2 me-2"></i>Dashboard
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'jadwal') ? 'active' : 'text-dark'; ?>" href="jadwal_saya.php">
                    <i class="bi bi-calendar3 me-2"></i>Atur Ketersediaan
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link d-flex justify-content-between align-items-center <?= ($current_page == 'persetujuan') ? 'active' : 'text-dark'; ?>" href="persetujuan.php">
                    <span><i class="bi bi-inbox me-2"></i>Pengajuan Bimbingan</span>
                    <?php if ($badge_menunggu > 0): ?>
                        <span class="badge bg-danger rounded-pill"><?= $badge_menunggu; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'riwayat') ? 'active' : 'text-dark'; ?>" href="riwayat_bimbingan.php">
                    <i class="bi bi-archive me-2"></i>Riwayat & Nilai
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'laporan') ? 'active' : 'text-dark'; ?>" href="cetak_laporan.php">
                    <i class="bi bi-printer me-2"></i>Cetak Laporan
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link d-flex justify-content-between align-items-center <?= ($current_page == 'chat') ? 'active' : 'text-dark'; ?>" href="chat.php">
                    <span><i class="bi bi-chat-dots me-2"></i>Konsultasi Chat</span>
                    <?php if ($badge_chat > 0): ?>
                        <span class="badge bg-primary rounded-pill"><?= $badge_chat; ?></span>
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
