<!-- templates/sidebar_mahasiswa.php -->
<nav class="col-md-3 col-lg-2 d-md-block bg-white sidebar shadow-sm p-3 min-vh-100">
    <div class="position-sticky">
        <h6 class="sidebar-heading px-3 mt-2 mb-3 text-muted text-uppercase small">Menu Mahasiswa</h6>
        <ul class="nav flex-column nav-pills">
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'dashboard') ? 'active' : 'text-dark'; ?>" href="index.php">
                    <i class="bi bi-speedometer2 me-2"></i>Dashboard
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'ajukan') ? 'active' : 'text-dark'; ?>" href="ajukan_jadwal.php">
                    <i class="bi bi-calendar-plus me-2"></i>Ajukan Jadwal
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'upload') ? 'active' : 'text-dark'; ?>" href="upload_draft.php">
                    <i class="bi bi-cloud-arrow-up me-2"></i>Upload Draft
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'logbook') ? 'active' : 'text-dark'; ?>" href="logbook.php">
                    <i class="bi bi-journal-check me-2"></i>Logbook & Laporan
                </a>
            </li>
        </ul>
    </div>
</nav>
<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
