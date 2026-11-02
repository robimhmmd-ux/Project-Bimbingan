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

        /* 4. Gojek Authentic 3D Solusi Cards */
        .card-gojek-solusi {
            border-radius: 36px;
            padding: 2.5rem 2rem 2.5rem 2rem;
            min-height: 460px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.35s ease;
            position: relative;
            overflow: hidden;
            border: none;
            text-align: left;
        }
        .card-gojek-solusi:hover {
            transform: translateY(-8px);
            box-shadow: 0 24px 45px -10px rgba(0, 0, 0, 0.18);
        }
        .card-gojek-solusi.bg-gojek-green-card {
            background-color: #74CE5E;
        }
        .card-gojek-solusi.bg-gojek-purple-card {
            background-color: #D094F7;
        }
        .card-gojek-solusi.bg-gojek-orange-card {
            background-color: #FFA244;
        }
        .card-gojek-illustration {
            width: 100%;
            height: 210px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.5rem;
        }
        .card-gojek-solusi .card-title-gojek {
            font-size: 1.85rem;
            font-weight: 800;
            color: #000000;
            letter-spacing: -0.03em;
            line-height: 1.2;
            margin-bottom: 0.65rem;
        }
        .card-gojek-solusi .card-desc-gojek {
            font-size: 1.05rem;
            font-weight: 500;
            color: #111827;
            line-height: 1.45;
            margin-bottom: 0;
            opacity: 0.95;
        }
        @media (max-width: 768px) {
            .card-gojek-solusi {
                min-height: 380px;
                padding: 2rem 1.5rem;
                border-radius: 28px;
            }
            .card-gojek-illustration {
                height: 170px;
            }
            .card-gojek-solusi .card-title-gojek {
                font-size: 1.55rem;
            }
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

<!-- 3. SECTION SOLUSI KAMI (Inspirasi Bahasa Desain Kartu 3D Gojek) -->
<section id="solusi" class="py-5 bg-white border-bottom">
    <div class="container py-4">
        <div class="text-center max-w-700 mx-auto mb-5">
            <span class="badge bg-success-subtle text-success fw-bold px-3 py-2 rounded-pill mb-2">SOLUSI TANTANGAN SKRIPSI</span>
            <h2 class="fw-extrabold text-dark display-6 mb-2">Menghadirkan Solusi untuk Menghadapi Hambatan Bimbingan</h2>
            <p class="text-muted mb-0">Skripsi sering terhambat karena jadwal dosen sulit dicocokkan atau revisi tercecer? Kami selesaikan secara sistematis.</p>
        </div>

        <div class="row g-4">
            <!-- Kartu 1: Hijau Gojek (Pasti Tanpa Drama) -->
            <div class="col-md-4">
                <div class="card-gojek-solusi bg-gojek-green-card">
                    <div class="card-gojek-illustration">
                        <svg viewBox="0 0 320 210" width="100%" height="190" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <defs>
                                <filter id="shadowGojek1" x="-20%" y="-20%" width="140%" height="140%">
                                    <feDropShadow dx="0" dy="8" stdDeviation="6" flood-color="#000000" flood-opacity="0.25"/>
                                </filter>
                                <linearGradient id="phoneScreenGojek1" x1="0%" y1="0%" x2="100%" y2="100%">
                                    <stop offset="0%" stop-color="#60A5FA"/>
                                    <stop offset="100%" stop-color="#2563EB"/>
                                </linearGradient>
                                <linearGradient id="calGradGojek" x1="0%" y1="0%" x2="0%" y2="100%">
                                    <stop offset="0%" stop-color="#FFFFFF"/>
                                    <stop offset="100%" stop-color="#F1F5F9"/>
                                </linearGradient>
                            </defs>
                            <!-- Floating Sparkles / Pluses -->
                            <path d="M 45 55 L 50 60 L 45 65 L 40 60 Z" fill="#9333EA"/>
                            <path d="M 270 45 L 274 50 L 270 55 L 266 50 Z" fill="#FACC15"/>
                            <circle cx="285" cy="80" r="4" fill="#9333EA"/>
                            <circle cx="35" cy="95" r="3" fill="#FFFFFF"/>

                            <!-- Phone 3D Base -->
                            <g filter="url(#shadowGojek1)">
                                <path d="M 45 125 L 160 178 L 275 125 L 275 142 L 160 195 L 45 142 Z" fill="#E11D48"/>
                                <path d="M 160 178 L 275 125 L 275 142 L 160 195 Z" fill="#9F1239"/>
                                <path d="M 45 125 L 160 72 L 275 125 L 160 178 Z" fill="#FB7185"/>
                                <path d="M 52 125 L 160 76 L 268 125 L 160 174 Z" fill="url(#phoneScreenGojek1)"/>
                            </g>

                            <!-- Shadow on Screen -->
                            <ellipse cx="160" cy="132" rx="55" ry="18" fill="#0F172A" opacity="0.32"/>

                            <!-- 3D Calendar Object -->
                            <g transform="translate(110, 28)">
                                <rect x="0" y="8" width="100" height="92" rx="20" fill="#0F172A" opacity="0.2"/>
                                <rect x="5" y="10" width="95" height="86" rx="18" fill="#CBD5E1"/>
                                <rect x="0" y="5" width="95" height="86" rx="18" fill="url(#calGradGojek)"/>
                                <path d="M 0 22 C 0 12.6 7.6 5 17 5 L 78 5 C 87.4 5 95 12.6 95 22 L 95 32 L 0 32 Z" fill="#15803D"/>
                                <rect x="20" y="0" width="6" height="10" rx="3" fill="#334155"/>
                                <rect x="45" y="0" width="6" height="10" rx="3" fill="#334155"/>
                                <rect x="70" y="0" width="6" height="10" rx="3" fill="#334155"/>
                                <circle cx="47" cy="60" r="20" fill="#DCFCE7"/>
                                <path d="M 39 60 L 45 66 L 56 54" stroke="#16A34A" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round"/>
                                <g transform="translate(58, 64)">
                                    <circle cx="16" cy="16" r="14" fill="#FACC15"/>
                                    <path d="M 12 16 L 15 19 L 21 13" stroke="#854D0E" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                                </g>
                            </g>
                        </svg>
                    </div>
                    <div>
                        <h3 class="card-title-gojek">Pasti Tanpa Drama</h3>
                        <p class="card-desc-gojek">Jadwal bimbingan pasti & bebas bentrok langsung dari slot dosen.</p>
                    </div>
                </div>
            </div>

            <!-- Kartu 2: Ungu Gojek (Riwayat Revisi Rapi) -->
            <div class="col-md-4">
                <div class="card-gojek-solusi bg-gojek-purple-card">
                    <div class="card-gojek-illustration">
                        <svg viewBox="0 0 320 210" width="100%" height="190" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <defs>
                                <filter id="shadowGojek2" x="-20%" y="-20%" width="140%" height="140%">
                                    <feDropShadow dx="0" dy="8" stdDeviation="6" flood-color="#000000" flood-opacity="0.25"/>
                                </filter>
                                <linearGradient id="phoneScreenGojek2" x1="0%" y1="0%" x2="100%" y2="100%">
                                    <stop offset="0%" stop-color="#38BDF8"/>
                                    <stop offset="100%" stop-color="#0284C7"/>
                                </linearGradient>
                            </defs>
                            <path d="M 50 50 L 54 55 L 50 60 L 46 55 Z" fill="#FACC15"/>
                            <path d="M 265 65 L 270 70 L 265 75 L 260 70 Z" fill="#22C55E"/>
                            <circle cx="280" cy="45" r="4" fill="#FFFFFF"/>
                            <circle cx="40" cy="85" r="3" fill="#6366F1"/>

                            <!-- Phone 3D Base -->
                            <g filter="url(#shadowGojek2)">
                                <path d="M 45 125 L 160 178 L 275 125 L 275 142 L 160 195 L 45 142 Z" fill="#6366F1"/>
                                <path d="M 160 178 L 275 125 L 275 142 L 160 195 Z" fill="#4338CA"/>
                                <path d="M 45 125 L 160 72 L 275 125 L 160 178 Z" fill="#818CF8"/>
                                <path d="M 52 125 L 160 76 L 268 125 L 160 174 Z" fill="url(#phoneScreenGojek2)"/>
                            </g>

                            <ellipse cx="160" cy="132" rx="55" ry="18" fill="#0F172A" opacity="0.32"/>

                            <!-- 3D Document Stack -->
                            <g transform="translate(105, 23)">
                                <rect x="18" y="16" width="75" height="92" rx="10" fill="#94A3B8" opacity="0.45"/>
                                <rect x="10" y="8" width="75" height="92" rx="10" fill="#E2E8F0"/>
                                <rect x="0" y="0" width="78" height="92" rx="10" fill="#FFFFFF"/>
                                <path d="M 58 0 L 78 20 L 58 20 Z" fill="#CBD5E1"/>
                                <rect x="10" y="14" width="38" height="6" rx="3" fill="#3B82F6"/>
                                <rect x="10" y="28" width="58" height="4" rx="2" fill="#E2E8F0"/>
                                <rect x="10" y="38" width="48" height="4" rx="2" fill="#E2E8F0"/>
                                <rect x="10" y="48" width="54" height="4" rx="2" fill="#E2E8F0"/>
                                <rect x="10" y="58" width="40" height="4" rx="2" fill="#E2E8F0"/>
                                <g transform="translate(42, 54)">
                                    <rect x="0" y="0" width="42" height="26" rx="8" fill="#EF4444"/>
                                    <text x="7" y="17" fill="#FFFFFF" font-size="11" font-weight="bold" font-family="sans-serif">PDF</text>
                                </g>
                                <g transform="translate(-10, 52)">
                                    <circle cx="16" cy="16" r="14" fill="#22C55E"/>
                                    <path d="M 10 16 L 14 20 L 22 12" stroke="#FFFFFF" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                                </g>
                            </g>
                        </svg>
                    </div>
                    <div>
                        <h3 class="card-title-gojek">Riwayat Revisi Rapi</h3>
                        <p class="card-desc-gojek">Semua arsip naskah bab PDF tersimpan runut bab demi bab.</p>
                    </div>
                </div>
            </div>

            <!-- Kartu 3: Oranye Gojek (Kartu Kendali Cetak Sah) -->
            <div class="col-md-4">
                <div class="card-gojek-solusi bg-gojek-orange-card">
                    <div class="card-gojek-illustration">
                        <svg viewBox="0 0 320 210" width="100%" height="190" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <defs>
                                <filter id="shadowGojek3" x="-20%" y="-20%" width="140%" height="140%">
                                    <feDropShadow dx="0" dy="8" stdDeviation="6" flood-color="#000000" flood-opacity="0.25"/>
                                </filter>
                                <linearGradient id="phoneScreenGojek3" x1="0%" y1="0%" x2="100%" y2="100%">
                                    <stop offset="0%" stop-color="#4ADE80"/>
                                    <stop offset="100%" stop-color="#16A34A"/>
                                </linearGradient>
                            </defs>
                            <path d="M 45 55 L 50 60 L 45 65 L 40 60 Z" fill="#EF4444"/>
                            <path d="M 270 50 L 275 55 L 270 60 L 265 55 Z" fill="#FACC15"/>
                            <circle cx="280" cy="85" r="4" fill="#FFFFFF"/>
                            <circle cx="35" cy="80" r="3" fill="#F59E0B"/>

                            <!-- Phone 3D Base -->
                            <g filter="url(#shadowGojek3)">
                                <path d="M 45 125 L 160 178 L 275 125 L 275 142 L 160 195 L 45 142 Z" fill="#D97706"/>
                                <path d="M 160 178 L 275 125 L 275 142 L 160 195 Z" fill="#B45309"/>
                                <path d="M 45 125 L 160 72 L 275 125 L 160 178 Z" fill="#FBBF24"/>
                                <path d="M 52 125 L 160 76 L 268 125 L 160 174 Z" fill="url(#phoneScreenGojek3)"/>
                            </g>

                            <ellipse cx="160" cy="132" rx="55" ry="18" fill="#0F172A" opacity="0.32"/>

                            <!-- 3D Graduation Cap & Diploma -->
                            <g transform="translate(100, 18)">
                                <rect x="15" y="45" width="88" height="52" rx="10" fill="#FFFFFF"/>
                                <rect x="25" y="55" width="40" height="5" rx="2.5" fill="#F59E0B"/>
                                <rect x="25" y="65" width="55" height="3" rx="1.5" fill="#CBD5E1"/>
                                <rect x="25" y="73" width="45" height="3" rx="1.5" fill="#CBD5E1"/>
                                <circle cx="85" cy="72" r="10" fill="#F59E0B"/>
                                <path d="M 81 72 L 84 75 L 89 70" stroke="#FFFFFF" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>

                                <!-- 3D Cap -->
                                <g transform="translate(5, 0)">
                                    <path d="M 32 35 C 32 45 74 45 74 35 L 74 25 C 74 35 32 35 32 25 Z" fill="#1E293B"/>
                                    <path d="M 32 35 C 32 44 74 44 74 35" stroke="#334155" stroke-width="2"/>
                                    <path d="M 53 5 L 98 22 L 53 38 L 8 22 Z" fill="#0F172A"/>
                                    <path d="M 53 5 L 98 22 L 53 25 L 8 22 Z" fill="#1E293B"/>
                                    <circle cx="53" cy="22" r="4" fill="#F59E0B"/>
                                    <path d="M 53 22 Q 22 28 18 42" stroke="#FBBF24" stroke-width="3" fill="none" stroke-linecap="round"/>
                                    <circle cx="18" cy="44" r="4.5" fill="#F59E0B"/>
                                </g>
                            </g>
                        </svg>
                    </div>
                    <div>
                        <h3 class="card-title-gojek">Kartu Kendali Cetak Sah</h3>
                        <p class="card-desc-gojek">Logbook otomatis rapi dan sah untuk syarat daftar sidang.</p>
                    </div>
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
