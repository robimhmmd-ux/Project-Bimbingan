<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistem Bimbingan Skripsi & Laporan Progres</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>

<!-- Navbar Publik -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm py-3">
    <div class="container">
        <a class="navbar-brand fw-bold fs-4" href="#">
            <i class="bi bi-mortarboard-fill me-2"></i>SIM Bimbingan Skripsi
        </a>
        <a href="login.php" class="btn btn-light fw-semibold text-primary px-4 shadow-sm">
            <i class="bi bi-box-arrow-in-right me-1"></i>Portal Login
        </a>
    </div>
</nav>

<!-- Hero Section -->
<section class="py-5 bg-white border-bottom">
    <div class="container text-center py-5">
        <h1 class="display-5 fw-bold text-dark mb-3">Pengelolaan Jadwal & Progres Skripsi Terpadu</h1>
        <p class="lead text-muted col-lg-8 mx-auto mb-4">
            Platform modern untuk memfasilitasi mahasiswa dalam mengajukan jadwal bimbingan, mengunggah draft skripsi, serta memantau catatan revisi dosen pembimbing secara transparan.
        </p>
        <div class="d-grid gap-2 d-sm-flex justify-content-sm-center">
            <a href="login.php" class="btn btn-primary btn-lg px-4 gap-3 fw-semibold shadow-sm">
                Masuk ke Akun Anda
            </a>
            <a href="#fitur" class="btn btn-outline-secondary btn-lg px-4">
                Pelajari Alur
            </a>
        </div>
    </div>
</section>

<!-- Fitur Section -->
<section id="fitur" class="py-5 bg-light">
    <div class="container py-4">
        <div class="row text-center mb-5">
            <div class="col-lg-8 mx-auto">
                <h3 class="fw-bold">Alur Tiga Peran Terintegrasi</h3>
                <p class="text-muted">Dirancang khusus untuk memenuhi standar kebutuhan bimbingan akademik.</p>
            </div>
        </div>
        <div class="row g-4">
            <div class="col-md-4">
                <div class="card h-100 p-4 shadow-sm border-0 text-center">
                    <div class="text-primary mb-3"><i class="bi bi-person-workspace fs-1"></i></div>
                    <h5 class="fw-bold">Untuk Mahasiswa</h5>
                    <p class="text-muted small">Cek jadwal dosen pembimbing, ajukan waktu bimbingan tanpa bentrok, unggah draft skripsi, dan cetak kartu bimbingan resmi.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100 p-4 shadow-sm border-0 text-center">
                    <div class="text-success mb-3"><i class="bi bi-person-badge fs-1"></i></div>
                    <h5 class="fw-bold">Untuk Dosen</h5>
                    <p class="text-muted small">Atur jadwal ketersediaan harian, setujui atau minta jadwal ulang, unduh draft skripsi mahasiswa, serta berikan catatan revisi dan nilai.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100 p-4 shadow-sm border-0 text-center">
                    <div class="text-warning mb-3"><i class="bi bi-shield-lock fs-1"></i></div>
                    <h5 class="fw-bold">Untuk Kemahasiswaan</h5>
                    <p class="text-muted small">Kelola data master mahasiswa dan dosen, pasangkan (plot) dosen pembimbing, dan pantau rekapitulasi status kelulusan mahasiswa.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<footer class="py-4 bg-white border-top text-center text-muted small">
    <div class="container">
        <span>&copy; <?= date('Y'); ?> Tim Mahasiswa Pemrograman Web Dasar. Hak Cipta Dilindungi.</span>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
