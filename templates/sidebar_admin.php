<!-- templates/sidebar_admin.php -->
<nav class="col-md-3 col-lg-2 d-md-block bg-white sidebar shadow-sm p-3 min-vh-100 border-end">
    <div class="position-sticky d-flex flex-column h-100">
        <!-- Section: Dashboard -->
        <div class="sidebar-category mb-2">
            <span class="sidebar-heading px-3 text-uppercase">Utama</span>
            <ul class="nav flex-column nav-pills mt-1">
                <li class="nav-item mb-1">
                    <a class="nav-link <?= ($current_page == 'dashboard') ? 'active' : ''; ?>" href="index.php">
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
                    <a class="nav-link <?= ($current_page == 'dosen') ? 'active' : ''; ?>" href="data_dosen.php">
                        <i class="bi bi-person-badge-fill me-2 text-success"></i>
                        <span>Data Dosen</span>
                    </a>
                </li>
                <li class="nav-item mb-1">
                    <a class="nav-link <?= ($current_page == 'jadwal_dosen') ? 'active' : ''; ?>" href="jadwal_dosen.php">
                        <i class="bi bi-calendar2-week-fill me-2 text-warning"></i>
                        <span>Jadwal Harian Dosen</span>
                    </a>
                </li>
                <li class="nav-item mb-1">
                    <a class="nav-link <?= ($current_page == 'mahasiswa') ? 'active' : ''; ?>" href="data_mahasiswa.php">
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
                    <a class="nav-link <?= ($current_page == 'plotting') ? 'active' : ''; ?>" href="plot_pembimbing.php">
                        <i class="bi bi-diagram-3-fill me-2 text-primary"></i>
                        <span>Atur Pembimbing</span>
                    </a>
                </li>
                <li class="nav-item mb-1">
                    <a class="nav-link <?= ($current_page == 'monitoring') ? 'active' : ''; ?>" href="monitoring_kelulusan.php">
                        <i class="bi bi-mortarboard-fill me-2 text-purple"></i>
                        <span>Monitoring Kelulusan</span>
                    </a>
                </li>
                <li class="nav-item mb-1">
                    <a class="nav-link <?= ($current_page == 'laporan') ? 'active' : ''; ?>" href="laporan_plotting.php">
                        <i class="bi bi-printer-fill me-2 text-danger"></i>
                        <span>Rekap & Cetak Laporan</span>
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
<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4 main-content-wrapper">
