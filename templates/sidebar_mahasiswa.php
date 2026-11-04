<?php
// templates/sidebar_mahasiswa.php
// Hitung notifikasi unread chat dan status pembimbing mahasiswa
$badge_chat_mhs = 0;
$status_pembimbing_mhs = 'belum_diplot';

if (isset($pdo) && isset($_SESSION['user_id'])) {
    try {
        $st_c = $pdo->prepare("SELECT COUNT(*) FROM pesan_chat WHERE penerima_id = :uid AND status_baca = 'belum_dibaca'");
        $st_c->execute([':uid' => $_SESSION['user_id']]);
        $badge_chat_mhs = (int)$st_c->fetchColumn();

        $st_sp = $pdo->prepare("SELECT status_pembimbing FROM mahasiswa WHERE user_id = :uid LIMIT 1");
        $st_sp->execute([':uid' => $_SESSION['user_id']]);
        $status_pembimbing_mhs = $st_sp->fetchColumn() ?: 'belum_diplot';
    } catch (Exception $e) {
        $badge_chat_mhs = 0;
        $status_pembimbing_mhs = 'belum_diplot';
    }
}
$is_sk_aktif = ($status_pembimbing_mhs === 'aktif');
?>
<nav class="col-md-3 col-lg-2 d-md-block sidebar shadow-sm p-3 border-end" id="sidebarMenu">
    <div class="position-sticky">

        <!-- Mobile Drawer Header: Khusus Smartphone saat drawer dibuka -->
        <div class="d-md-none p-3 mb-3 rounded-3 bg-light border d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2 overflow-hidden">
                <span class="p-1 px-2 bg-indigo text-white rounded-circle flex-shrink-0" style="font-size: 0.85rem; background-color: #4f46e5;">
                    <i class="bi bi-mortarboard-fill"></i>
                </span>
                <div class="overflow-hidden">
                    <div class="fw-bold text-dark text-truncate small"><?= htmlspecialchars($_SESSION['nama_lengkap'] ?? 'Mahasiswa'); ?></div>
                    <span class="badge bg-primary-subtle text-primary" style="font-size: 0.65rem;">Mahasiswa Skripsi</span>
                </div>
            </div>
            <button type="button" class="btn-close btn-sm shadow-none" onclick="toggleSidebarMenu()" aria-label="Tutup Menu"></button>
        </div>


        <h6 class="sidebar-heading px-3 mt-2 mb-3 text-muted text-uppercase small fw-bold">Menu Mahasiswa</h6>
        <ul class="nav flex-column nav-pills">
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'dashboard') ? 'active' : ''; ?>" href="index.php" title="Dashboard Progres">
                    <i class="bi bi-speedometer2 me-2"></i><span class="menu-text">Dashboard Progres</span>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'jadwal_bimbingan' || $current_page == 'jadwal_dosen') ? 'active' : ''; ?>" href="jadwal_bimbingan.php" title="Jadwal Bimbingan">
                    <i class="bi bi-calendar-week me-2"></i><span class="menu-text">Jadwal Bimbingan</span>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link d-flex align-items-center <?= ($current_page == 'chat') ? 'active' : ''; ?>" href="chat.php" title="Chat Dosen">
                    <i class="bi bi-chat-dots me-2"></i><span class="menu-text">Chat Dosen</span>
                    <?php if ($badge_chat_mhs > 0): ?>
                        <span class="badge bg-primary rounded-pill ms-auto"><?= $badge_chat_mhs; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link d-flex align-items-center <?= ($current_page == 'upload') ? 'active' : ''; ?>" href="upload_draft.php" title="Upload Berkas">
                    <i class="bi bi-cloud-arrow-up me-2"></i><span class="menu-text">Upload Berkas</span>
                    <?php if (!$is_sk_aktif): ?>
                        <i class="bi bi-lock-fill text-muted ms-auto lock-icon" title="Terkunci: Konfirmasi SK terlebih dahulu"></i>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'logbook') ? 'active' : ''; ?>" href="logbook.php" title="Logbook & Laporan">
                    <i class="bi bi-journal-check me-2"></i><span class="menu-text">Logbook & Laporan</span>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'sidang') ? 'active' : ''; ?>" href="informasi_sidang.php" title="Informasi Sidang">
                    <i class="bi bi-mortarboard-fill me-2"></i><span class="menu-text">Informasi Sidang</span>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'profil') ? 'active' : ''; ?>" href="profil.php" title="Profil & Data Diri">
                    <i class="bi bi-person-circle me-2"></i><span class="menu-text">Profil & Data Diri</span>
                </a>
            </li>
        </ul>
    </div>
</nav>
<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4 main-content-wrapper" id="mainContent">
