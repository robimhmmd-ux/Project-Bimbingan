<?php
// index.php - Portal Publik Sistem Informasi Pengelolaan Bimbingan Skripsi
// Model Desain: Terinspirasi dari Bahasa Desain Modern Gojek (Human, Bold, Clean)
session_start();

// Jika pengguna sudah login, arahkan langsung ke dashboard sesuai peran
if (isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'admin') {
        header("Location: admin/index.php");
        exit;
    } elseif ($_SESSION['role'] === 'dosen') {
        header("Location: dosen/index.php");
        exit;
    } elseif ($_SESSION['role'] === 'mahasiswa') {
        header("Location: mahasiswa/index.php");
        exit;
    }
}

// Inisialisasi statistik default
$total_dosen = 9;
$total_mhs   = 36;
$total_sesi  = 0;
$list_dosen  = [];

// Tarik data riil dari basis data TiDB Cloud jika terhubung
if (file_exists('config/database.php')) {
    try {
        require_once 'config/database.php';
        if (isset($pdo)) {
            $total_dosen = (int)$pdo->query("SELECT COUNT(*) FROM dosen")->fetchColumn();
            $total_mhs   = (int)$pdo->query("SELECT COUNT(*) FROM mahasiswa")->fetchColumn();
            $total_sesi  = (int)$pdo->query("SELECT COUNT(*) FROM pengajuan_bimbingan")->fetchColumn();

            $stmt_dosen = $pdo->query("
                SELECT d.nama_dosen, d.nidn, d.kuota_bimbingan,
                       (SELECT COUNT(*) FROM mahasiswa m WHERE m.dosen_pembimbing_id = d.id) AS terisi
                FROM dosen d 
                ORDER BY d.nama_dosen ASC 
                LIMIT 6
            ");
            $list_dosen = $stmt_dosen->fetchAll();
        }
    } catch (Exception $e) {
        // Fallback anggun jika database offline sementara
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SIM Bimbingan Skripsi — #PastiAdaJalan Wisuda Tepat Waktu</title>
    <!-- Bootstrap 5 CSS via CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Google Fonts: Plus Jakarta Sans (Gaya Tipografi Tebal Khas Gojek) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <!-- Custom CSS dengan Cache Buster Wajib -->
    <link href="assets/css/style.css?v=<?= time(); ?>" rel="stylesheet">
    
    <!-- DESAIN SISTEM KHAS GOJEK (SELF-CONTAINED & ANTI CACHE) -->
    <style>
        :root {
            --gojek-green: #00aa13;
            --gojek-green-dark: #00880d;
            --gojek-green-subtle: #e6f7ea;
            --gojek-dark: #0b0f19;
            --gojek-bubble-bg: linear-gradient(145deg, #005024 0%, #003a19 100%);
        }

        html {
            scroll-behavior: smooth;
            scroll-padding-top: 85px;
        }

        body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background-color: #f8fafc;
            color: #0f172a;
            -webkit-font-smoothing: antialiased;
        }

        /* 1. Navbar Gojek Style */
        .navbar-gojek {
            background-color: var(--gojek-dark) !important;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(12px);
        }

        .navbar-gojek .nav-link {
            color: rgba(255, 255, 255, 0.65) !important;
            font-weight: 600;
            font-size: 0.95rem;
            padding: 0.5rem 0.95rem !important;
            transition: all 0.2s ease;
        }

        .navbar-gojek .nav-link:hover {
            color: #ffffff !important;
        }

        /* Status Aktif Navbar (Teks Putih Terang Bold Bersih) */
        .navbar-gojek .nav-link.active {
            color: #ffffff !important;
            font-weight: 800;
        }

        .btn-gojek-green {
            background-color: var(--gojek-green) !important;
            color: #ffffff !important;
            font-weight: 800 !important;
            border-radius: 9999px !important;
            border: none !important;
            padding: 0.65rem 1.6rem !important;
            transition: all 0.25s ease !important;
            text-decoration: none !important;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-gojek-green:hover {
            background-color: var(--gojek-green-dark) !important;
            color: #ffffff !important;
            transform: translateY(-2px);
            box-shadow: 0 10px 20px -4px rgba(0, 170, 19, 0.45) !important;
        }

        /* 2. Hero Section Gojek */
        .hero-gojek-container {
            position: relative;
            background-color: #0b0f19;
            background-image: linear-gradient(to right, rgba(11, 15, 25, 0.94) 0%, rgba(11, 15, 25, 0.82) 55%, rgba(11, 15, 25, 0.92) 100%),
                              url('https://images.unsplash.com/photo-1523240795612-9a054b0db644?auto=format&fit=crop&w=1600&q=80');
            background-size: cover;
            background-position: center;
            color: #ffffff;
            overflow: hidden;
        }

        /* Gelembung Hijau Ikonik Gojek */
        .bubble-card-gojek {
            background: var(--gojek-bubble-bg) !important;
            border-radius: 34px !important;
            padding: 3.2rem 2.8rem !important;
            color: #ffffff !important;
            box-shadow: 0 25px 50px -12px rgba(0, 45, 20, 0.75) !important;
            border: 1px solid rgba(255, 255, 255, 0.16) !important;
            position: relative;
        }

        .bubble-headline {
            font-weight: 900;
            line-height: 1.12;
            letter-spacing: -0.035em;
            font-size: clamp(2.4rem, 4.6vw, 3.8rem);
            color: #ffffff !important;
        }

        /* 3. Section Skala Kami (Gojek Metrics) */
        .card-skala-gojek {
            background: #ffffff;
            border-radius: 24px;
            padding: 2.2rem 1.6rem;
            border: 1px solid #e2e8f0;
            transition: all 0.25s ease;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02);
            height: 100%;
        }

        .card-skala-gojek:hover {
            transform: translateY(-5px);
            border-color: var(--gojek-green);
            box-shadow: 0 16px 28px -8px rgba(0, 170, 19, 0.15);
        }

        .stat-number-big {
            font-weight: 900;
            font-size: 3rem;
            line-height: 1;
            letter-spacing: -0.03em;
            color: #0f172a;
        }

        /* 4. Tiga Pilar Solusi Cards */
        .card-pilar-gojek {
            background: #ffffff;
            border-radius: 28px;
            border: 1px solid #e2e8f0;
            padding: 2.2rem 2rem;
            transition: all 0.25s ease;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.03);
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .card-pilar-gojek:hover {
            transform: translateY(-6px);
            box-shadow: 0 22px 35px -10px rgba(0, 0, 0, 0.09);
            border-color: #cbd5e1;
        }

        .pilar-icon-gojek {
            width: 60px;
            height: 60px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            margin-bottom: 1.4rem;
        }

        /* 5. Tiga Peran Bertumbuh Bersama */
        .card-peran-gojek {
            background: #ffffff;
            border-radius: 28px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            transition: all 0.25s ease;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .card-peran-gojek:hover {
            transform: translateY(-6px);
            box-shadow: 0 20px 35px -10px rgba(0, 0, 0, 0.08);
            border-color: var(--gojek-green);
        }

        .card-peran-header {
            padding: 2rem 2rem 1.25rem 2rem;
        }

        /* 6. CTA Card Gojek */
        .cta-banner-gojek {
            background: linear-gradient(135deg, #0b0f19 0%, #004d23 100%);
            border-radius: 36px;
            padding: 4rem 3rem;
            color: #ffffff;
            position: relative;
            overflow: hidden;
        }

        @media (max-width: 767.98px) {
            .bubble-card-gojek {
                padding: 2.2rem 1.6rem !important;
                border-radius: 24px !important;
            }
            .cta-banner-gojek {
                padding: 2.5rem 1.6rem;
                border-radius: 24px;
            }
            .stat-number-big {
                font-size: 2.4rem;
            }
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">

<!-- 1. NAVBAR MINIMALIS GOJEK -->
<nav class="navbar navbar-expand-lg navbar-dark navbar-gojek sticky-top py-3">
    <div class="container">
        <!-- Brand Logo -->
        <a class="navbar-brand d-flex align-items-center gap-2 text-white text-decoration-none" href="index.php">
            <div class="d-inline-flex align-items-center justify-content-center text-white rounded-circle shadow-sm" style="width: 40px; height: 40px; background-color: var(--gojek-green) !important;">
                <i class="bi bi-mortarboard-fill fs-5"></i>
            </div>
            <div class="d-flex flex-column">
                <span class="fs-5 fw-extrabold lh-1 tracking-tight text-white">SIM Skripsi</span>
                <span class="text-white-50 small" style="font-size: 0.7rem;">Bimbingan & Tugas Akhir</span>
            </div>
        </a>

        <!-- Hamburger Toggle Mobile/Tablet -->
        <button class="navbar-toggler border-white border-opacity-25" type="button" data-bs-toggle="collapse" data-bs-target="#navGojek" aria-controls="navGojek" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navGojek">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-lg-center gap-lg-2">
                <li class="nav-item">
                    <a class="nav-link active" href="#beranda">Beranda</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#solusi">Solusi Kami</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#skala">Skala Kami</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#dosen">Dosen</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#faq">FAQ</a>
                </li>
                <li class="nav-item ms-lg-3 mt-2 mt-lg-0">
                    <a href="#mulai-bimbingan" class="btn-gojek-green">
                        <i class="bi bi-arrow-down-circle"></i>
                        <span>Mulai Bimbingan</span>
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- 2. HERO BANNER UTAMA (Foto Mahasiswa + Gelembung Hijau Ikonik Gojek) -->
<section id="beranda" class="hero-gojek-container py-5 py-lg-6">
    <div class="container py-4 py-lg-5">
        <div class="row align-items-center g-5">
            <!-- Kolom Kiri: Pesan Kampus Tegas & Poin Solusi -->
            <div class="col-lg-6">
                <div class="pe-lg-3">
                    <div class="d-inline-flex align-items-center gap-2 px-3 py-1 rounded-pill bg-white bg-opacity-10 border border-white border-opacity-10 text-success small mb-3">
                        <i class="bi bi-patch-check-fill text-success"></i>
                        <span class="fw-bold text-white">Sistem Bimbingan Skripsi Terpadu</span>
                    </div>

                    <h2 class="display-6 fw-extrabold text-white mb-3 lh-sm">
                        Bimbingan Teratur, Skripsi Tuntas, Wisuda Tepat Waktu.
                    </h2>
                    <p class="text-white text-opacity-75 mb-4 fs-6" style="line-height: 1.8;">
                        Tak perlu lagi antre tanpa kepastian atau khawatir naskah revisian tercecer di chat. Akses ketersediaan jadwal dosen, serahkan draf bab PDF, dan pantau logbook kelulusan dalam satu pintu.
                    </p>

                    <!-- Poin Manfaat Ringkas -->
                    <div class="d-flex flex-column gap-3 mb-4">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 34px; height: 34px; background-color: rgba(0, 170, 19, 0.25); color: #00aa13;">
                                <i class="bi bi-calendar-check-fill"></i>
                            </div>
                            <span class="text-white fw-medium">Reservasi slot waktu dosen tanpa bentrok jadwal mahasiswa lain</span>
                        </div>
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 34px; height: 34px; background-color: rgba(0, 170, 19, 0.25); color: #00aa13;">
                                <i class="bi bi-file-earmark-pdf-fill"></i>
                            </div>
                            <span class="text-white fw-medium">Unggah berkas PDF Bab & arsip catatan evaluasi tersimpan rapi</span>
                        </div>
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 34px; height: 34px; background-color: rgba(0, 170, 19, 0.25); color: #00aa13;">
                                <i class="bi bi-printer-fill"></i>
                            </div>
                            <span class="text-white fw-medium">Format cetak kartu bimbingan fisik resmi siap tanda tangan dosen</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Kolom Kanan: GELEMBUNG HIJAU GOJEK DENGAN SLOGAN BESAR -->
            <div class="col-lg-6">
                <div class="bubble-card-gojek">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <span class="badge bg-white text-dark fw-bold px-3 py-1 rounded-pill" style="font-size: 0.72rem; letter-spacing: 0.05em;">
                            KAMPUS TERPADU
                        </span>
                        <span class="text-white-50 small"><i class="bi bi-shield-fill-check me-1 text-success"></i>TiDB Cloud Live</span>
                    </div>

                    <!-- SLOGAN BESAR & BOLD GAYA GOJEK -->
                    <h1 class="bubble-headline mb-0">
                        Dari Bab 1,<br>
                        Pasti Ada<br>
                        Jalan Wisuda.
                    </h1>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- 3. SECTION SOLUSI KAMI (Inspirasi Section 1 Gojek: Menghadirkan Solusi Tantangan Sehari-hari) -->
<section id="solusi" class="py-5 bg-white border-bottom">
    <div class="container py-4">
        <div class="text-center max-w-700 mx-auto mb-5">
            <span class="badge bg-success-subtle text-success fw-bold px-3 py-2 rounded-pill mb-2">SOLUSI TANTANGAN SKRIPSI</span>
            <h2 class="fw-extrabold text-dark display-6">Menghadirkan Solusi untuk Menghadapi Hambatan Bimbingan</h2>
            <p class="text-muted">Skripsi sering terhambat karena jadwal dosen sulit dicocokkan, revisi hilang di chat, atau logbook fisik tercecer? Kami menyelesaikannya secara sistematis.</p>
        </div>

        <div class="row g-4">
            <!-- Pilar 1: Jadwal Pasti -->
            <div class="col-md-4">
                <div class="card-pilar-gojek">
                    <div class="pilar-icon-gojek bg-success-subtle text-success" style="background-color: var(--gojek-green-subtle) !important; color: var(--gojek-green) !important;">
                        <i class="bi bi-calendar2-check-fill"></i>
                    </div>
                    <h4 class="fw-bold text-dark mb-0">Pasti Tanpa Drama</h4>
                </div>
            </div>

            <!-- Pilar 2: Revisi Terpusat -->
            <div class="col-md-4">
                <div class="card-pilar-gojek">
                    <div class="pilar-icon-gojek bg-primary-subtle text-primary">
                        <i class="bi bi-file-earmark-diff-fill"></i>
                    </div>
                    <h4 class="fw-bold text-dark mb-0">Riwayat Revisi Rapi</h4>
                </div>
            </div>

            <!-- Pilar 3: Kartu Kendali Otomatis -->
            <div class="col-md-4">
                <div class="card-pilar-gojek">
                    <div class="pilar-icon-gojek bg-warning-subtle text-warning">
                        <i class="bi bi-printer-fill"></i>
                    </div>
                    <h4 class="fw-bold text-dark mb-0">Kartu Kendali Cetak Sah</h4>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- 4. SECTION SKALA KAMI (Inspirasi Section Skala Gojek: Angka Pembuktian Riil) -->
<section id="skala" class="py-5 bg-light border-bottom">
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-5">
            <div>
                <span class="badge bg-dark text-white fw-bold px-3 py-2 rounded-pill mb-2">DATA SISTEM REALTIME</span>
                <h2 class="fw-extrabold text-dark display-6 mb-0">Skala Kami</h2>
            </div>
            <div class="text-muted small">
                Terhubung langsung ke kluster basis data TiDB Serverless
            </div>
        </div>

        <div class="row g-4 text-start">
            <div class="col-6 col-lg-3">
                <div class="card-skala-gojek">
                    <div class="stat-number-big mb-2"><?= $total_dosen; ?></div>
                    <h5 class="fw-bold text-dark mb-1">Dosen Pembimbing</h5>
                    <p class="text-muted small mb-0">Terdaftar aktif dan bersertifikasi NIDN resmi di program studi.</p>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card-skala-gojek">
                    <div class="stat-number-big mb-2"><?= $total_mhs; ?></div>
                    <h5 class="fw-bold text-dark mb-1">Mahasiswa Tugas Akhir</h5>
                    <p class="text-muted small mb-0">Terbagi pada 3 kelas paralel (Kelas TI-1A, TI-1B, dan TI-1C).</p>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card-skala-gojek">
                    <div class="stat-number-big mb-2">Maks 5</div>
                    <h5 class="fw-bold text-dark mb-1">Batas Kuota / Dosen</h5>
                    <p class="text-muted small mb-0">Rasio ideal untuk menjamin mutu dan kedalaman konsultasi skripsi.</p>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card-skala-gojek">
                    <div class="stat-number-big mb-2 text-success">100%</div>
                    <h5 class="fw-bold text-dark mb-1">Bebas Bentrok Jadwal</h5>
                    <p class="text-muted small mb-0">Validasi otomatis mencegah tumpang tindih waktu bimbingan mahasiswa.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- 6. SECTION DOSEN PEMBIMBING (Gaya Kartu Mitra Gojek) -->
<section id="dosen" class="py-5 bg-light border-bottom">
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-4">
            <div>
                <span class="badge bg-success-subtle text-success fw-bold px-3 py-2 rounded-pill mb-2">TENAGA PENDIDIK</span>
                <h2 class="fw-extrabold text-dark display-6 mb-1">Dosen Pembimbing Akademik</h2>
                <p class="text-muted mb-0">Dosen yang aktif membimbing tugas akhir mahasiswa semester ini.</p>
            </div>
            <div>
                <a href="login.php" class="btn btn-outline-dark rounded-pill px-4 py-2 small fw-bold">
                    <i class="bi bi-calendar3 me-1"></i>Lihat Jadwal Lengkap
                </a>
            </div>
        </div>

        <div class="row g-3">
            <?php if (!empty($list_dosen)): ?>
                <?php foreach ($list_dosen as $d): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card p-3 rounded-4 h-100 bg-white border shadow-sm" style="transition: transform 0.2s ease;">
                            <div class="d-flex align-items-center gap-3">
                                <div class="rounded-circle text-white d-flex align-items-center justify-content-center fw-bold fs-5 flex-shrink-0" style="width: 50px; height: 50px; background: linear-gradient(135deg, #00aa13 0%, #005024 100%);">
                                    <?= strtoupper(substr($d['nama_dosen'], 0, 1)); ?>
                                </div>
                                <div class="overflow-hidden">
                                    <h6 class="fw-bold text-dark text-truncate mb-0" title="<?= htmlspecialchars($d['nama_dosen']); ?>">
                                        <?= htmlspecialchars($d['nama_dosen']); ?>
                                    </h6>
                                    <div class="text-muted small" style="font-size: 0.78rem;">
                                        NIDN: <strong><?= htmlspecialchars($d['nidn'] ?? '-'); ?></strong>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 mt-1">
                                        <span class="badge bg-light text-dark border" style="font-size: 0.7rem;">
                                            Kuota: <?= (int)$d['terisi']; ?> / <?= (int)$d['kuota_bimbingan']; ?> Mahasiswa
                                        </span>
                                        <?php if ((int)$d['terisi'] >= (int)$d['kuota_bimbingan']): ?>
                                            <span class="badge bg-secondary text-white" style="font-size: 0.65rem;">Penuh</span>
                                        <?php else: ?>
                                            <span class="badge bg-success-subtle text-success" style="font-size: 0.65rem;">Tersedia</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- 7. CTA BANNER BESAR GOJEK (Inspirasi: "Belum punya aplikasi Gojek? Unduh sekarang") -->
<section id="mulai-bimbingan" class="py-5 bg-white border-bottom">
    <div class="container py-4">
        <div class="cta-banner-gojek">
            <div class="row align-items-center g-4">
                <div class="col-lg-8">
                    <span class="badge bg-white text-dark fw-bold px-3 py-1 rounded-pill mb-3" style="font-size: 0.75rem;">
                        #PASTIADAJALAN WISUDA
                    </span>
                    <h2 class="display-6 fw-extrabold text-white mb-3">
                        Siap Menuntaskan Skripsi Anda? Mulai Bimbingan Sekarang.
                    </h2>
                    <p class="text-white text-opacity-80 mb-0 fs-6" style="line-height: 1.8;">
                        Gunakan akun akademik Anda untuk masuk ke sistem, jadwalkan sesi bimbingan pertama, dan wujudkan kelulusan tepat waktu bersama dosen pembimbing Anda.
                    </p>
                </div>
                <div class="col-lg-4 text-lg-end">
                    <a href="login.php" class="btn btn-light text-dark fw-extrabold px-5 py-3 rounded-pill shadow-lg d-inline-flex align-items-center gap-2 fs-6">
                        <i class="bi bi-box-arrow-in-right text-success fs-5"></i>
                        <span>Masuk ke Portal</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- 8. FAQ (Pertanyaan yang Kerap Ditanyakan) -->
<section id="faq" class="py-5 bg-light border-bottom">
    <div class="container py-4">
        <div class="text-center max-w-700 mx-auto mb-5">
            <span class="badge bg-secondary-subtle text-secondary fw-bold px-3 py-2 rounded-pill mb-2">TANYA JAWAB</span>
            <h2 class="fw-extrabold text-dark display-6">Pertanyaan yang Kerap Ditanyakan</h2>
            <p class="text-muted">Panduan singkat seputar teknis bimbingan skripsi akademik.</p>
        </div>

        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="accordion" id="accordionGojek">
                    <div class="accordion-item border rounded-4 mb-3 overflow-hidden shadow-sm">
                        <h2 class="accordion-header" id="faqH1">
                            <button class="accordion-button collapsed fw-bold text-dark fs-6 p-4" type="button" data-bs-toggle="collapse" data-bs-target="#faqC1">
                                Bagaimana jika jadwal dosen tidak cocok dengan jadwal mahasiswa?
                            </button>
                        </h2>
                        <div id="faqC1" class="accordion-collapse collapse" data-bs-parent="#accordionGojek">
                            <div class="accordion-body text-muted p-4 pt-0" style="line-height: 1.8;">
                                Mahasiswa dapat berkoordinasi langsung dengan dosen pembimbing melalui fitur pesan/chat yang tersedia di dalam portal untuk menyepakati slot waktu tambahan.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item border rounded-4 mb-3 overflow-hidden shadow-sm">
                        <h2 class="accordion-header" id="faqH2">
                            <button class="accordion-button collapsed fw-bold text-dark fs-6 p-4" type="button" data-bs-toggle="collapse" data-bs-target="#faqC2">
                                Berapa batas kapasitas file draf naskah skripsi yang diizinkan?
                            </button>
                        </h2>
                        <div id="faqC2" class="accordion-collapse collapse" data-bs-parent="#accordionGojek">
                            <div class="accordion-body text-muted p-4 pt-0" style="line-height: 1.8;">
                                Berkas draf naskah wajib berekstensi <code>.pdf</code> dengan ukuran maksimum <strong>5.0 Megabytes</strong> per unggahan demi menjaga efisiensi penyimpanan cloud.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item border rounded-4 mb-3 overflow-hidden shadow-sm">
                        <h2 class="accordion-header" id="faqH3">
                            <button class="accordion-button collapsed fw-bold text-dark fs-6 p-4" type="button" data-bs-toggle="collapse" data-bs-target="#faqC3">
                                Kapan mahasiswa dinyatakan siap mendaftar sidang tugas akhir?
                            </button>
                        </h2>
                        <div id="faqC3" class="accordion-collapse collapse" data-bs-parent="#accordionGojek">
                            <div class="accordion-body text-muted p-4 pt-0" style="line-height: 1.8;">
                                Mahasiswa dapat mendaftar sidang setelah mengumpulkan sekurang-kurangnya 8 sesi bimbingan sah dan telah memperoleh pengesahan status ACC Sidang dari Dosen Pembimbing Utama.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- 9. FOOTER HITAM GOJEK -->
<footer class="text-white pt-5 pb-4 mt-auto" style="background-color: var(--gojek-dark) !important;">
    <div class="container">
        <div class="row g-4 pb-4 border-bottom border-secondary border-opacity-25">
            <div class="col-lg-5">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <div class="d-inline-flex align-items-center justify-content-center text-white rounded-circle" style="width: 40px; height: 40px; background-color: var(--gojek-green) !important;">
                        <i class="bi bi-mortarboard-fill fs-5"></i>
                    </div>
                    <h5 class="fw-bold mb-0 text-white">SIM Bimbingan Skripsi</h5>
                </div>
                <p class="text-white-50 small mb-3" style="line-height: 1.8;">
                    Sistem Informasi Manajemen Bimbingan Tugas Akhir & Skripsi. Mengintegrasikan proses reservasi jadwal, penyerahan naskah, validasi revisi, hingga pencatatan logbook kelulusan akademik secara transparan dan akuntabel.
                </p>
                <div class="small text-white-50">
                    <div><i class="bi bi-geo-alt me-2 text-success"></i>Gedung Fakultas Teknologi Informasi, Lantai 2</div>
                    <div><i class="bi bi-envelope me-2 text-success"></i>sekretariat.skripsi@kampus.ac.id</div>
                </div>
            </div>

            <div class="col-6 col-lg-3 offset-lg-1">
                <h6 class="fw-bold text-white mb-3 text-uppercase small" style="letter-spacing: 0.05em;">Menu Navigasi</h6>
                <ul class="list-unstyled small d-flex flex-column gap-2 mb-0">
                    <li><a href="#beranda" class="text-white-50 text-decoration-none">Beranda</a></li>
                    <li><a href="#solusi" class="text-white-50 text-decoration-none">Solusi Bimbingan</a></li>
                    <li><a href="#skala" class="text-white-50 text-decoration-none">Skala Kami</a></li>
                    <li><a href="#dosen" class="text-white-50 text-decoration-none">Daftar Dosen</a></li>
                    <li><a href="login.php" class="text-success text-decoration-none fw-bold">Portal Masuk</a></li>
                </ul>
            </div>

            <div class="col-6 col-lg-3">
                <h6 class="fw-bold text-white mb-3 text-uppercase small" style="letter-spacing: 0.05em;">Standar Arsitektur</h6>
                <ul class="list-unstyled small text-white-50 d-flex flex-column gap-2 mb-0">
                    <li><i class="bi bi-check2 text-success me-1"></i>PHP Native 8.x (Pure Core)</li>
                    <li><i class="bi bi-check2 text-success me-1"></i>TiDB Serverless Cloud (MySQL)</li>
                    <li><i class="bi bi-check2 text-success me-1"></i>Bootstrap 5 & Vanilla CSS</li>
                    <li><i class="bi bi-check2 text-success me-1"></i>PDO Prepared Statements + SSL</li>
                </ul>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 pt-3 small text-white-50">
            <div>
                &copy; <?= date('Y'); ?> <strong>Tim Pengembang Web Dasar</strong>. Hak Cipta Dilindungi.
            </div>
            <div>
                Koordinator: <strong>Yoga</strong> (Lead Frontend) & <strong>Robi</strong> (Koordinator Teknis)
            </div>
        </div>
    </div>
</footer>

<!-- Bootstrap 5 Bundle JS via CDN -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<!-- Script Navigasi Interaktif (Active Menu On Click & Scroll) -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const navLinks = document.querySelectorAll('.navbar-gojek .nav-link[href^="#"]');

    // Event Klik Navigasi (Teks langsung aktif putih tebal)
    navLinks.forEach(function (link) {
        link.addEventListener('click', function () {
            navLinks.forEach(l => l.classList.remove('active'));
            this.classList.add('active');
        });
    });

    // Otomatis Aktif saat di-Scroll (Intersection Observer)
    const trackedSections = document.querySelectorAll('section[id]');
    let lastSectionId = '';

    const scrollObserver = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                const currentId = entry.target.getAttribute('id');
                if (currentId && currentId !== lastSectionId) {
                    lastSectionId = currentId;
                    navLinks.forEach(function (link) {
                        if (link.getAttribute('href') === '#' + currentId) {
                            link.classList.add('active');
                        } else {
                            link.classList.remove('active');
                        }
                    });
                }
            }
        });
    }, {
        rootMargin: '-25% 0px -55% 0px',
        threshold: 0
    });

    trackedSections.forEach(function (sec) {
        scrollObserver.observe(sec);
    });
});
</script>
</body>
</html>
