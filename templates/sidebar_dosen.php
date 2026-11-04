<?php
// templates/sidebar_dosen.php
// Hitung notifikasi untuk menu dosen jika koneksi pdo tersedia
$badge_menunggu = 0;
$badge_chat     = 0;
$badge_sidang   = 0;

if (isset($pdo) && isset($_SESSION['user_id'])) {
    try {
        $st_did = $pdo->prepare("SELECT id FROM dosen WHERE user_id = :uid LIMIT 1");
        $st_did->execute([':uid' => $_SESSION['user_id']]);
        $sidebar_dosen_id = $st_did->fetchColumn();

        if ($sidebar_dosen_id) {
            $st_cnt = $pdo->prepare("SELECT COUNT(*) FROM pengajuan_bimbingan WHERE dosen_id = :did AND status = 'menunggu'");
            $st_cnt->execute([':did' => $sidebar_dosen_id]);
            $badge_menunggu = (int)$st_cnt->fetchColumn();

            // Hitung jadwal sidang mendatang (sebagai pembimbing atau penguji 1, 2, 3)
            $st_sdg = $pdo->prepare("
                SELECT COUNT(*) FROM sidang_skripsi 
                WHERE (dosen_pembimbing_id = :did1 OR penguji_1_id = :did2 OR penguji_2_id = :did3 OR penguji_3_id = :did4) 
                AND status_sidang IN ('pengajuan', 'dijadwalkan')
            ");
            $st_sdg->execute([
                ':did1' => $sidebar_dosen_id,
                ':did2' => $sidebar_dosen_id,
                ':did3' => $sidebar_dosen_id,
                ':did4' => $sidebar_dosen_id
            ]);
            $badge_sidang = (int)$st_sdg->fetchColumn();
        }

        $st_chat = $pdo->prepare("SELECT COUNT(*) FROM pesan_chat WHERE penerima_id = :uid AND status_baca = 'belum_dibaca'");
        $st_chat->execute([':uid' => $_SESSION['user_id']]);
        $badge_chat = (int)$st_chat->fetchColumn();
    } catch (Exception $e) {
        // Fallback
    }
}
?>
<nav class="col-md-3 col-lg-2 d-md-block sidebar shadow-sm p-3 border-end" id="sidebarMenu">
    <div class="position-sticky">

        <!-- Mobile Drawer Header: Khusus Smartphone saat drawer dibuka -->
        <div class="d-md-none p-3 mb-3 rounded-3 bg-light border d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2 overflow-hidden">
                <span class="p-1 px-2 bg-success text-white rounded-circle flex-shrink-0" style="font-size: 0.85rem;">
                    <i class="bi bi-person-badge-fill"></i>
                </span>
                <div class="overflow-hidden">
                    <div class="fw-bold text-dark text-truncate small"><?= htmlspecialchars($_SESSION['nama_lengkap'] ?? 'Dosen'); ?></div>
                    <span class="badge bg-success-subtle text-success" style="font-size: 0.65rem;">Dosen Pembimbing</span>
                </div>
            </div>
            <button type="button" class="btn-close btn-sm shadow-none" onclick="toggleSidebarMenu()" aria-label="Tutup Menu"></button>
        </div>


        <h6 class="sidebar-heading px-3 mt-2 mb-3 text-muted text-uppercase small fw-bold">Menu Dosen</h6>
        <ul class="nav flex-column nav-pills">
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'dashboard') ? 'active' : ''; ?>" href="./" title="Dashboard">
                    <i class="bi bi-speedometer2 me-2"></i><span class="menu-text">Dashboard</span>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'mahasiswa_bimbingan') ? 'active' : ''; ?>" href="mahasiswa_bimbingan" title="Mahasiswa Bimbingan">
                    <i class="bi bi-people-fill me-2"></i><span class="menu-text">Mahasiswa Bimbingan</span>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link d-flex align-items-center <?= ($current_page == 'persetujuan') ? 'active' : ''; ?>" href="persetujuan" title="Lihat Jadwal & Pengajuan">
                    <i class="bi bi-calendar-event me-2"></i><span class="menu-text">Lihat Jadwal</span>
                    <?php if ($badge_menunggu > 0): ?>
                        <span class="badge bg-danger rounded-pill ms-auto"><?= $badge_menunggu; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'cek_judul') ? 'active' : ''; ?>" href="cek_judul" title="Cek Judul Skripsi">
                    <i class="bi bi-search me-2"></i><span class="menu-text">Cek Judul Skripsi</span>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link d-flex align-items-center <?= ($current_page == 'sidang') ? 'active' : ''; ?>" href="sidang" title="Jadwal & Penguji Sidang">
                    <i class="bi bi-mortarboard-fill me-2 text-warning"></i><span class="menu-text">Jadwal & Penguji Sidang</span>
                    <?php if ($badge_sidang > 0): ?>
                        <span class="badge bg-success rounded-pill ms-auto"><?= $badge_sidang; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'laporan') ? 'active' : ''; ?>" href="cetak_laporan" title="Cetak Laporan">
                    <i class="bi bi-printer me-2"></i><span class="menu-text">Cetak Laporan</span>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link d-flex align-items-center <?= ($current_page == 'chat') ? 'active' : ''; ?>" href="chat" title="Konsultasi Chat">
                    <i class="bi bi-chat-dots me-2"></i><span class="menu-text">Konsultasi Chat</span>
                    <?php if ($badge_chat > 0): ?>
                        <span class="badge bg-primary rounded-pill ms-auto"><?= $badge_chat; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            
            <li class="nav-item mt-3 pt-3 border-top">
                <a class="nav-link <?= ($current_page == 'profil') ? 'active' : ''; ?>" href="profil" title="Profil & Akun">
                    <i class="bi bi-person-gear me-2"></i><span class="menu-text">Profil & Akun</span>
                </a>
            </li>
        </ul>
    </div>
</nav>
<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4 main-content-wrapper" id="mainContent">
