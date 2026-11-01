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
<nav class="col-md-3 col-lg-2 d-md-block bg-white sidebar shadow-sm p-3 min-vh-100 border-end">
    <div class="position-sticky">
        <h6 class="sidebar-heading px-3 mt-2 mb-3 text-muted text-uppercase small fw-bold">Menu Mahasiswa</h6>
        <ul class="nav flex-column nav-pills">
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'dashboard') ? 'active' : 'text-dark'; ?>" href="index.php">
                    <i class="bi bi-speedometer2 me-2"></i>Dashboard Progres
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'jadwal_dosen') ? 'active' : 'text-dark'; ?>" href="jadwal_dosen.php">
                    <i class="bi bi-calendar3 me-2"></i>Jadwal Dosen
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link d-flex justify-content-between align-items-center <?= ($current_page == 'ajukan') ? 'active' : 'text-dark'; ?>" href="ajukan_jadwal.php">
                    <span><i class="bi bi-calendar-plus me-2"></i>Ajukan Bimbingan</span>
                    <?php if (!$is_sk_aktif): ?>
                        <i class="bi bi-lock-fill text-muted" title="Terkunci: Konfirmasi SK terlebih dahulu"></i>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link d-flex justify-content-between align-items-center <?= ($current_page == 'chat') ? 'active' : 'text-dark'; ?>" href="chat.php">
                    <span><i class="bi bi-chat-dots me-2"></i>Chat Dosen</span>
                    <?php if ($badge_chat_mhs > 0): ?>
                        <span class="badge bg-primary rounded-pill"><?= $badge_chat_mhs; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link d-flex justify-content-between align-items-center <?= ($current_page == 'upload') ? 'active' : 'text-dark'; ?>" href="upload_draft.php">
                    <span><i class="bi bi-cloud-arrow-up me-2"></i>Upload Skripsi</span>
                    <?php if (!$is_sk_aktif): ?>
                        <i class="bi bi-lock-fill text-muted" title="Terkunci: Konfirmasi SK terlebih dahulu"></i>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'logbook') ? 'active' : 'text-dark'; ?>" href="logbook.php">
                    <i class="bi bi-journal-check me-2"></i>Logbook & Laporan
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'sidang') ? 'active' : 'text-dark'; ?>" href="informasi_sidang.php">
                    <i class="bi bi-mortarboard-fill me-2"></i>Informasi Sidang
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'profil') ? 'active' : 'text-dark'; ?>" href="profil.php">
                    <i class="bi bi-person-circle me-2"></i>Profil & Data Diri
                </a>
            </li>
        </ul>
    </div>
</nav>
<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
