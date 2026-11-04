<!-- templates/sidebar_mahasiswa.php -->
<?php
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
<nav class="col-md-3 col-lg-2 d-md-block bg-white sidebar shadow-sm p-3 border-end" id="sidebarMenu">
    <div class="position-sticky">
        <!-- Header Khusus Drawer Mobile -->
        <div class="d-md-none d-flex align-items-center justify-content-between pb-2 mb-3 border-bottom px-2">
            <div class="d-flex align-items-center gap-2">
                <span class="p-1 px-2 bg-primary bg-opacity-10 text-primary rounded-2">
                    <i class="bi bi-mortarboard-fill"></i>
                </span>
                <span class="fw-bold text-dark small">Portal Mahasiswa</span>
            </div>
            <button type="button" class="btn btn-sm btn-light border-0 rounded-circle" onclick="toggleSidebarMenu()" aria-label="Tutup Menu" style="width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;">
                <i class="bi bi-x-lg fs-6 text-dark"></i>
            </button>
        </div>
        <h6 class="sidebar-heading px-3 mt-1 mb-2 text-muted text-uppercase small fw-bold">Menu Mahasiswa</h6>
        <ul class="nav flex-column nav-pills">
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'dashboard') ? 'active' : 'text-dark'; ?>" href="index.php" title="Dashboard Progres">
                    <i class="bi bi-speedometer2 me-2"></i><span class="menu-text">Dashboard Progres</span>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'jadwal_bimbingan' || $current_page == 'jadwal_dosen') ? 'active' : 'text-dark'; ?>" href="jadwal_bimbingan.php" title="Jadwal Bimbingan">
                    <i class="bi bi-calendar-week me-2"></i><span class="menu-text">Jadwal Bimbingan</span>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link d-flex align-items-center <?= ($current_page == 'chat') ? 'active' : 'text-dark'; ?>" href="chat.php" title="Chat Dosen">
                    <i class="bi bi-chat-dots me-2"></i><span class="menu-text">Chat Dosen</span>
                    <?php if ($badge_chat_mhs > 0): ?>
                        <span class="badge bg-primary rounded-pill ms-auto"><?= $badge_chat_mhs; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link d-flex align-items-center <?= ($current_page == 'upload') ? 'active' : 'text-dark'; ?>" href="upload_draft.php" title="Upload Berkas">
                    <i class="bi bi-cloud-arrow-up me-2"></i><span class="menu-text">Upload Berkas</span>
                    <?php if (!$is_sk_aktif): ?>
                        <i class="bi bi-lock-fill text-muted ms-auto lock-icon" title="Terkunci: Konfirmasi SK terlebih dahulu"></i>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'logbook') ? 'active' : 'text-dark'; ?>" href="logbook.php" title="Logbook & Laporan">
                    <i class="bi bi-journal-check me-2"></i><span class="menu-text">Logbook & Laporan</span>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'sidang') ? 'active' : 'text-dark'; ?>" href="informasi_sidang.php" title="Informasi Sidang">
                    <i class="bi bi-mortarboard-fill me-2"></i><span class="menu-text">Informasi Sidang</span>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'profil') ? 'active' : 'text-dark'; ?>" href="profil.php" title="Profil & Data Diri">
                    <i class="bi bi-person-circle me-2"></i><span class="menu-text">Profil & Data Diri</span>
                </a>
            </li>
        </ul>
    </div>
</nav>
<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4 main-content-wrapper" id="mainContent">
