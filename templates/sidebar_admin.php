<!-- templates/sidebar_admin.php -->
<nav class="col-md-3 col-lg-2 d-md-block bg-white sidebar shadow-sm p-3 min-vh-100">
    <div class="position-sticky">
        <h6 class="sidebar-heading px-3 mt-2 mb-3 text-muted text-uppercase small">Menu Administrator</h6>
        <ul class="nav flex-column nav-pills">
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'dashboard') ? 'active' : 'text-dark'; ?>" href="index.php">
                    <i class="bi bi-speedometer2 me-2"></i>Dashboard
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'dosen') ? 'active' : 'text-dark'; ?>" href="data_dosen.php">
                    <i class="bi bi-person-badge me-2"></i>Data Dosen
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'mahasiswa') ? 'active' : 'text-dark'; ?>" href="data_mahasiswa.php">
                    <i class="bi bi-people me-2"></i>Data Mahasiswa
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link <?= ($current_page == 'plotting') ? 'active' : 'text-dark'; ?>" href="plot_pembimbing.php">
                    <i class="bi bi-diagram-3 me-2"></i>Plot Pembimbing
                </a>
            </li>
        </ul>
    </div>
</nav>
<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
