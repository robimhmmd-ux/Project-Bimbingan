<!-- templates/sidebar_dosen.php -->
<nav class="col-md-3 col-lg-2 d-md-block bg-white sidebar shadow-sm p-3 min-vh-100">
    <div class="position-sticky">
        <h6 class="sidebar-heading px-3 mt-2 mb-3 text-muted text-uppercase small">Menu Dosen</h6>
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
                <a class="nav-link <?= ($current_page == 'persetujuan') ? 'active' : 'text-dark'; ?>" href="persetujuan.php">
                    <i class="bi bi-inbox me-2"></i>Pengajuan Bimbingan
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'riwayat') ? 'active' : 'text-dark'; ?>" href="riwayat_bimbingan.php">
                    <i class="bi bi-archive me-2"></i>Riwayat & Nilai
                </a>
            </li>
        </ul>
    </div>
</nav>
<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
