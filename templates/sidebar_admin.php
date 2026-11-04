<!-- templates/sidebar_admin.php -->
<nav class="col-md-3 col-lg-2 d-md-block sidebar shadow-sm p-3 border-end" id="sidebarMenu">
    <div class="position-sticky d-flex flex-column h-100">

        <!-- Mobile Drawer Header: Khusus Smartphone saat drawer dibuka -->
        <div class="d-md-none p-3 mb-3 rounded-3 bg-light border d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2 overflow-hidden">
                <span class="p-1 px-2 bg-primary text-white rounded-circle flex-shrink-0" style="font-size: 0.85rem;">
                    <i class="bi bi-shield-lock-fill"></i>
                </span>
                <div class="overflow-hidden">
                    <div class="fw-bold text-dark text-truncate small"><?= htmlspecialchars($_SESSION['nama_lengkap'] ?? 'Admin'); ?></div>
                    <span class="badge bg-primary-subtle text-primary" style="font-size: 0.65rem;">Administrator</span>
                </div>
            </div>
            <button type="button" class="btn-close btn-sm shadow-none" onclick="toggleSidebarMenu()" aria-label="Tutup Menu"></button>
        </div>


        <!-- Section: Dashboard -->
        <div class="sidebar-category mb-2">
            <span class="sidebar-heading px-3 text-uppercase">Utama</span>
            <ul class="nav flex-column nav-pills mt-1">
                <li class="nav-item mb-1">
                    <a class="nav-link <?= ($current_page == 'dashboard') ? 'active' : ''; ?>" href="./">
                        <i class="bi bi-grid-1x2-fill me-2 text-primary"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
            </ul>
        </div>

        <!-- Section: Master Data Akademik -->
        <div class="sidebar-category mb-2">
            <span class="sidebar-heading px-3 text-uppercase">Master Data & Jadwal</span>
            <ul class="nav flex-column nav-pills mt-1">
                <li class="nav-item mb-1">
                    <a class="nav-link <?= ($current_page == 'dosen') ? 'active' : ''; ?>" href="data_dosen">
                        <i class="bi bi-person-badge-fill me-2 text-success"></i>
                        <span>Data Dosen</span>
                    </a>
                </li>
                <li class="nav-item mb-1">
                    <a class="nav-link <?= ($current_page == 'jadwal_dosen') ? 'active' : ''; ?>" href="jadwal_dosen">
                        <i class="bi bi-calendar2-week-fill me-2 text-warning"></i>
                        <span>Jadwal Bimbingan Dosen</span>
                    </a>
                </li>
                <li class="nav-item mb-1">
                    <a class="nav-link <?= ($current_page == 'mahasiswa') ? 'active' : ''; ?>" href="data_mahasiswa">
                        <i class="bi bi-people-fill me-2 text-info"></i>
                        <span>Data Mahasiswa</span>
                    </a>
                </li>
            </ul>
        </div>

        <!-- Section: Layanan Skripsi & Laporan -->
        <div class="sidebar-category mb-3">
            <span class="sidebar-heading px-3 text-uppercase">Layanan & Rekapitulasi</span>
            <ul class="nav flex-column nav-pills mt-1">
                <li class="nav-item mb-1">
                    <a class="nav-link <?= ($current_page == 'plotting') ? 'active' : ''; ?>" href="plot_pembimbing">
                        <i class="bi bi-diagram-3-fill me-2 text-primary"></i>
                        <span>Atur Pembimbing</span>
                    </a>
                </li>
                <li class="nav-item mb-1">
                    <a class="nav-link <?= ($current_page == 'jadwal_bimbingan') ? 'active' : ''; ?>" href="jadwal_bimbingan">
                        <i class="bi bi-calendar2-range-fill me-2 text-info"></i>
                        <span>Jadwal Bimbingan</span>
                    </a>
                </li>
                <li class="nav-item mb-1">
                    <a class="nav-link <?= ($current_page == 'monitoring') ? 'active' : ''; ?>" href="monitoring_kelulusan">
                        <i class="bi bi-mortarboard-fill me-2 text-purple"></i>
                        <span>Monitoring Kelulusan</span>
                    </a>
                </li>
                <li class="nav-item mb-1">
                    <a class="nav-link <?= ($current_page == 'sidang') ? 'active' : ''; ?>" href="jadwal_sidang">
                        <i class="bi bi-calendar2-check-fill me-2 text-warning"></i>
                        <span>Jadwal Sidang Skripsi</span>
                    </a>
                </li>
                <li class="nav-item mb-1">
                    <a class="nav-link <?= ($current_page == 'laporan') ? 'active' : ''; ?>" href="laporan_plotting">
                        <i class="bi bi-printer-fill me-2 text-danger"></i>
                        <span>Rekap & Cetak Laporan</span>
                    </a>
                </li>
            </ul>
        </div>

        <!-- Section: Pengaturan Akun -->
        <div class="sidebar-category mb-3">
            <span class="sidebar-heading px-3 text-uppercase">Pengaturan</span>
            <ul class="nav flex-column nav-pills mt-1">
                <li class="nav-item mb-1">
                    <a class="nav-link <?= ($current_page == 'profil') ? 'active' : ''; ?>" href="profil">
                        <i class="bi bi-person-gear me-2 text-secondary"></i>
                        <span>Profil & Kelola Admin</span>
                    </a>
                </li>
            </ul>
        </div>

        <!-- System Status Box (Bottom of sidebar) -->
        <div class="mt-auto pt-3">
            <div class="sidebar-system-card p-3 rounded-3 border">
                <div class="d-flex align-items-center justify-content-between mb-1">
                    <span class="fw-bold small text-dark"><i class="bi bi-shield-check text-success me-1"></i>Sistem Terhubung</span>
                    <span class="badge bg-success-subtle text-success border border-success-subtle">Online</span>
                </div>
                <div class="text-muted small" style="font-size: 0.725rem;">
                    <div>Database: <strong>TiDB Serverless</strong></div>
                    <div>Engine: <strong>PHP 8.2 Native</strong></div>
                </div>
            </div>
        </div>
    </div>
</nav>
<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4 main-content-wrapper" id="mainContent">
