<?php
// index.php - Portal Publik Sistem Informasi Pengelolaan Bimbingan Skripsi
// Model Desain: Terinspirasi dari Bahasa Desain Modern Gojek (Human, Bold, Clean)
if (file_exists(__DIR__ . '/config/database.php')) {
    require_once __DIR__ . '/config/database.php';
}
if (file_exists(__DIR__ . '/config/session_helper.php')) {
    require_once __DIR__ . '/config/session_helper.php';
    init_app_session($pdo ?? null);
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
    
    <!-- Script Deteksi Mode Terang / Gelap Otomatis (Anti-Flicker) -->
    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
        })();
    </script>
    
    <!-- DESAIN SISTEM KHAS GOJEK (SELF-CONTAINED & ANTI CACHE) -->
    <style>
        :root {
            /* ========================================================
               1. TEMA TERANG (LIGHT MODE - GOJEK CLEAN RESMI)
            ======================================================== */
            --bg-base:           #F8FAFC;
            --bg-surface:        #FFFFFF;
            --bg-alt:            #F1F5F9;
            --bg-navbar:         rgba(255, 255, 255, 0.96);
            --brand-icon-color:  #FFFFFF;
            
            --nav-link-color:    #334155;
            --nav-link-hover:    #0F172A;
            --text-heading:      #0F172A;
            --text-body:         #475569;
            --text-muted-dark:   #64748B;
            --border-subtle:     #E2E8F0;
            
            --gojek-green:          #00AA13;
            --gojek-green-hover:    #00880C;
            --gojek-green-btn-text: #FFFFFF;
            --gojek-green-subtle:   rgba(0, 170, 19, 0.1);
            --gojek-green-border:   rgba(0, 170, 19, 0.25);
            --gojek-green-text:     #00880C;
            --gojek-green-glow:     rgba(0, 170, 19, 0.25);

            --hero-overlay:      linear-gradient(to right, rgba(248, 250, 252, 0.95) 0%, rgba(248, 250, 252, 0.84) 48%, rgba(248, 250, 252, 0.38) 100%);
            --hero-badge-bg:     rgba(0, 170, 19, 0.08);
            --hero-badge-color:  #00880C;
            --hero-badge-border: rgba(0, 170, 19, 0.25);

            --bubble-bg:         linear-gradient(145deg, #00880F 0%, #005F0B 60%, #004507 100%);
            --bubble-border:     #00AA13;
            --bubble-shadow:     0 25px 60px -12px rgba(0, 100, 20, 0.38), 0 0 0 1px rgba(255, 255, 255, 0.15) inset;
            --bubble-headline:   #FFFFFF;
            --bubble-badge-bg:   #FFFFFF;
            --bubble-badge-text: #00600A;

            --card-solusi-1-bg:     linear-gradient(180deg, #F0FDF4 0%, #FFFFFF 55%);
            --card-solusi-1-border: #BBF7D0;
            --card-solusi-1-title:  #0F172A;
            --card-solusi-1-desc:   #475569;
            --card-solusi-1-shadow: 0 10px 30px -5px rgba(0, 170, 19, 0.08);

            --card-solusi-2-bg:     linear-gradient(180deg, #F5F3FF 0%, #FFFFFF 55%);
            --card-solusi-2-border: #DDD6FE;
            --card-solusi-2-title:  #0F172A;
            --card-solusi-2-desc:   #475569;
            --card-solusi-2-shadow: 0 10px 30px -5px rgba(124, 58, 237, 0.08);

            --card-solusi-3-bg:     linear-gradient(180deg, #FFFBEB 0%, #FFFFFF 55%);
            --card-solusi-3-border: #FED7AA;
            --card-solusi-3-title:  #0F172A;
            --card-solusi-3-desc:   #475569;
            --card-solusi-3-shadow: 0 10px 30px -5px rgba(249, 115, 22, 0.08);

            --cta-bg:            linear-gradient(135deg, #00880F 0%, #00500A 100%);
            --cta-border:        #00AA13;
            --cta-btn-bg:        #FFFFFF;
            --cta-btn-text:      #00600A;
            --cta-shadow:        0 25px 50px -12px rgba(0, 90, 15, 0.3);

            --footer-bg:         #0B0F19;
            --toggle-bg:         #FFFFFF;
            --toggle-border:     #CBD5E1;
            --toggle-color:      #0F172A;
        }

        [data-theme="dark"] {
            /* ========================================================
               2. TEMA GELAP (DARK MODE - TECH MODERN EMERALD)
            ======================================================== */
            --bg-base:           #0A0E17;
            --bg-surface:        #131B2A;
            --bg-alt:            #0F1523;
            --bg-navbar:         rgba(10, 14, 23, 0.92);
            --brand-icon-color:  #0A0E17;

            --nav-link-color:    #94A3B8;
            --nav-link-hover:    #FFFFFF;
            --text-heading:      #FFFFFF;
            --text-body:         #CBD5E1;
            --text-muted-dark:   #94A3B8;
            --border-subtle:     rgba(255, 255, 255, 0.08);

            --gojek-green:          #00D056;
            --gojek-green-hover:    #25E572;
            --gojek-green-btn-text: #0A0E17;
            --gojek-green-subtle:   rgba(0, 208, 86, 0.15);
            --gojek-green-glow:     rgba(0, 208, 86, 0.35);

            --gojek-green-border:   rgba(0, 208, 86, 0.3);
            --gojek-green-text:     #00D056;

            --hero-overlay:      linear-gradient(to right, rgba(10, 14, 23, 0.94) 0%, rgba(10, 14, 23, 0.82) 50%, rgba(10, 14, 23, 0.5) 100%);
            --hero-badge-bg:     rgba(0, 208, 86, 0.12);
            --hero-badge-color:  #00D056;
            --hero-badge-border: rgba(0, 208, 86, 0.3);

            --bubble-bg:         linear-gradient(145deg, #093b1d 0%, #055026 50%, #032b14 100%);
            --bubble-border:     #00D056;
            --bubble-shadow:     0 25px 60px -12px rgba(0, 0, 0, 0.8), 0 0 35px rgba(0, 208, 86, 0.25);
            --bubble-headline:   #FFFFFF;
            --bubble-badge-bg:   #00D056;
            --bubble-badge-text: #0A0E17;

            --card-solusi-1-bg:     linear-gradient(165deg, #0e2a1b 0%, #071f13 100%);
            --card-solusi-1-border: rgba(0, 208, 86, 0.35);
            --card-solusi-1-title:  #FFFFFF;
            --card-solusi-1-desc:   #A7F3D0;
            --card-solusi-1-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.5);

            --card-solusi-2-bg:     linear-gradient(165deg, #13243f 0%, #0c182b 100%);
            --card-solusi-2-border: rgba(59, 130, 246, 0.35);
            --card-solusi-2-title:  #FFFFFF;
            --card-solusi-2-desc:   #BAE6FD;
            --card-solusi-2-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.5);

            --card-solusi-3-bg:     linear-gradient(165deg, #2e1d0d 0%, #1e1308 100%);
            --card-solusi-3-border: rgba(245, 158, 11, 0.35);
            --card-solusi-3-title:  #FFFFFF;
            --card-solusi-3-desc:   #FDE68A;
            --card-solusi-3-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.5);

            --cta-bg:            linear-gradient(135deg, #072f17 0%, #094722 50%, #062311 100%);
            --cta-border:        #00D056;
            --cta-btn-bg:        #00D056;
            --cta-btn-text:      #0A0E17;
            --cta-shadow:        0 25px 60px -12px rgba(0, 0, 0, 0.8), 0 0 35px rgba(0, 208, 86, 0.2);

            --footer-bg:         #06090F;
            --toggle-bg:         #131B2A;
            --toggle-border:     rgba(255, 255, 255, 0.15);
            --toggle-color:      #FACC15;
        }

        html {
            scroll-behavior: smooth;
            scroll-padding-top: 85px;
        }

        body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background-color: var(--bg-base) !important;
            color: var(--text-heading) !important;
            transition: background-color 0.3s ease, color 0.3s ease;
            -webkit-font-smoothing: antialiased;
        }

        /* 1. Navbar Adaptif */
        .navbar-gojek {
            background: var(--bg-navbar) !important;
            border-bottom: 1px solid var(--border-subtle) !important;
            backdrop-filter: blur(14px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06) !important;
            transition: background 0.3s ease, border-color 0.3s ease;
        }

        .navbar-gojek .nav-link {
            color: var(--nav-link-color) !important;
            font-weight: 600;
            font-size: 0.95rem;
            padding: 0.5rem 0.95rem !important;
            transition: all 0.2s ease;
        }

        .navbar-gojek .nav-link:hover {
            color: var(--nav-link-hover) !important;
        }

        .navbar-gojek .nav-link.active {
            color: var(--gojek-green) !important;
            font-weight: 800;
        }

        .btn-gojek-green {
            background: var(--gojek-green) !important;
            color: var(--gojek-green-btn-text) !important;
            font-weight: 800 !important;
            border-radius: 9999px !important;
            border: none !important;
            padding: 0.65rem 1.6rem !important;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1) !important;
            text-decoration: none !important;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 4px 18px var(--gojek-green-glow) !important;
        }

        .btn-gojek-green:hover {
            background: var(--gojek-green-hover) !important;
            color: var(--gojek-green-btn-text) !important;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 208, 86, 0.45) !important;
        }

        /* Tombol Switcher Mode Terang / Gelap */
        .btn-theme-toggle {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--toggle-bg);
            border: 1.5px solid var(--toggle-border);
            color: var(--toggle-color);
            cursor: pointer;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            font-size: 1.15rem;
            padding: 0;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }
        .btn-theme-toggle:hover {
            transform: scale(1.1);
            border-color: var(--gojek-green);
        }
        [data-theme="light"] .theme-icon-light { display: none; }
        [data-theme="light"] .theme-icon-dark  { display: inline-block; }
        [data-theme="dark"]  .theme-icon-light { display: inline-block; }
        [data-theme="dark"]  .theme-icon-dark  { display: none; }

        /* 2. Hero Section */
        .hero-gojek-container {
            position: relative;
            background-color: var(--bg-base);
            background-image: var(--hero-overlay),
                              url('https://images.unsplash.com/photo-1523240795612-9a054b0db644?auto=format&fit=crop&w=1600&q=80');
            background-size: cover;
            background-position: center 30%;
            color: var(--text-heading);
            overflow: hidden;
            border-bottom: 1px solid var(--border-subtle);
            transition: background-color 0.3s ease;
        }

        @media (min-width: 992px) {
            .hero-gojek-container {
                min-height: calc(100vh - 76px);
                display: flex;
                align-items: center;
            }
        }

        .hero-gojek-container > .container {
            position: relative;
            z-index: 2;
        }

        .hero-gojek-container::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 60px;
            background: linear-gradient(to bottom, transparent, var(--bg-alt));
            pointer-events: none;
            z-index: 1;
        }

        /* Hero Left Column Highlights */
        .hero-title-highlight {
            color: var(--gojek-green) !important;
            -webkit-text-fill-color: var(--gojek-green) !important;
            display: inline-block;
        }

        .hero-lead-text {
            color: var(--text-body);
            font-size: 1.05rem;
            line-height: 1.65;
            max-width: 520px;
            font-weight: 500;
        }

        .hero-trust-item {
            color: var(--text-muted-dark);
            font-size: 0.84rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }

        /* Gelembung Hero Khas Gojek */
        .bubble-card-gojek {
            background: var(--bubble-bg) !important;
            border-radius: 34px !important;
            padding: 3.2rem 2.8rem !important;
            color: #FFFFFF !important;
            box-shadow: var(--bubble-shadow) !important;
            border: 2px solid var(--bubble-border) !important;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .bubble-card-gojek::before {
            content: '';
            position: absolute;
            top: -25%;
            right: -25%;
            width: 280px;
            height: 280px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.12) 0%, transparent 70%);
            pointer-events: none;
        }

        .bubble-card-gojek h1,
        .bubble-card-gojek .bubble-headline,
        h1.bubble-headline {
            font-weight: 900 !important;
            line-height: 1.12 !important;
            letter-spacing: -0.035em !important;
            font-size: clamp(2.4rem, 4.6vw, 3.8rem) !important;
            color: #FFFFFF !important;
            -webkit-text-fill-color: #FFFFFF !important;
            text-shadow: 0 3px 18px rgba(0, 0, 0, 0.35) !important;
        }

        .bubble-chips-strip {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1.8rem;
            padding-top: 1.25rem;
            border-top: 1px solid rgba(255, 255, 255, 0.18);
            flex-wrap: wrap;
        }

        .bubble-chip {
            background: rgba(255, 255, 255, 0.16);
            border: 1px solid rgba(255, 255, 255, 0.22);
            backdrop-filter: blur(8px);
            border-radius: 9999px;
            padding: 0.35rem 0.85rem;
            color: #FFFFFF;
            font-size: 0.78rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }
        .bubble-chip i {
            color: #FFFFFF !important;
        }

        /* 3. Section Solusi Kami */
        #solusi {
            background-color: var(--bg-alt) !important;
            border-bottom: 1px solid var(--border-subtle) !important;
            transition: background-color 0.3s ease;
        }
        #solusi h2 {
            color: var(--text-heading) !important;
        }
        #solusi p.text-muted {
            color: var(--text-muted-dark) !important;
        }

        /* Kartu Solusi 3D Gojek */
        .card-gojek-solusi {
            border-radius: 28px;
            padding: 1.8rem 1.6rem;
            min-height: 380px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.35s ease, background-color 0.3s ease, border-color 0.3s ease;
            position: relative;
            overflow: hidden;
            text-align: left;
        }
        .card-gojek-solusi:hover {
            transform: translateY(-8px);
        }
        .card-gojek-solusi.bg-gojek-green-card {
            background: var(--card-solusi-1-bg) !important;
            border: 1.5px solid var(--card-solusi-1-border) !important;
            box-shadow: var(--card-solusi-1-shadow) !important;
        }
        .card-gojek-solusi.bg-gojek-green-card .card-title-gojek {
            color: var(--card-solusi-1-title) !important;
        }
        .card-gojek-solusi.bg-gojek-green-card .card-desc-gojek {
            color: var(--card-solusi-1-desc) !important;
        }
        .card-gojek-solusi.bg-gojek-purple-card {
            background: var(--card-solusi-2-bg) !important;
            border: 1.5px solid var(--card-solusi-2-border) !important;
            box-shadow: var(--card-solusi-2-shadow) !important;
        }
        .card-gojek-solusi.bg-gojek-purple-card .card-title-gojek {
            color: var(--card-solusi-2-title) !important;
        }
        .card-gojek-solusi.bg-gojek-purple-card .card-desc-gojek {
            color: var(--card-solusi-2-desc) !important;
        }
        .card-gojek-solusi.bg-gojek-orange-card {
            background: var(--card-solusi-3-bg) !important;
            border: 1.5px solid var(--card-solusi-3-border) !important;
            box-shadow: var(--card-solusi-3-shadow) !important;
        }
        .card-gojek-solusi.bg-gojek-orange-card .card-title-gojek {
            color: var(--card-solusi-3-title) !important;
        }
        .card-gojek-solusi.bg-gojek-orange-card .card-desc-gojek {
            color: var(--card-solusi-3-desc) !important;
        }

        .card-gojek-illustration {
            width: 100%;
            height: 160px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
        }
        .card-gojek-illustration svg {
            max-height: 155px;
            width: auto;
            max-width: 100%;
        }
        .card-gojek-solusi .card-title-gojek {
            font-size: 1.5rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            line-height: 1.25;
            margin-bottom: 0.4rem;
        }
        .card-gojek-solusi .card-desc-gojek {
            font-size: 0.95rem;
            font-weight: 500;
            line-height: 1.45;
            margin-bottom: 0;
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

        /* 3b. Section Alur & Portal (Roadmap Bimbingan & Akses Masuk) */
        #alur {
            background-color: var(--bg-base) !important;
            border-bottom: 1px solid var(--border-subtle) !important;
            transition: background-color 0.3s ease;
            scroll-margin-top: 0px;
        }
        #alur h2 {
            color: var(--text-heading) !important;
        }
        #alur p.text-muted {
            color: var(--text-muted-dark) !important;
        }

        .card-alur-gojek {
            background-color: var(--bg-surface) !important;
            border: 1.5px solid var(--border-subtle) !important;
            border-radius: 22px !important;
            padding: 1.35rem 1.25rem !important;
            position: relative;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.04);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 210px;
            height: 100%;
        }
        .card-alur-gojek:hover {
            transform: translateY(-5px);
            border-color: var(--gojek-green) !important;
            box-shadow: 0 16px 32px -8px var(--gojek-green-glow) !important;
        }
        .card-alur-gojek h5 {
            color: var(--text-heading) !important;
            font-size: 1.15rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            margin-top: 0.55rem;
            margin-bottom: 0.45rem;
        }
        .card-alur-gojek p {
            color: var(--text-muted-dark) !important;
            line-height: 1.6;
            font-size: 0.88rem;
            margin-bottom: 0;
        }

        .step-number-pill {
            font-size: 1.55rem;
            font-weight: 900;
            color: var(--gojek-green);
            line-height: 1;
            letter-spacing: -0.04em;
        }

        .step-icon-wrap {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            background: var(--gojek-green-subtle);
            color: var(--gojek-green);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            transition: all 0.25s ease;
        }
        .card-alur-gojek:hover .step-icon-wrap {
            background: var(--gojek-green);
            color: var(--gojek-green-btn-text);
            transform: scale(1.06);
        }

        /* Strip CTA Hasil Penggabungan */
        .cta-banner-merged {
            background: var(--cta-bg) !important;
            border: 2px solid var(--cta-border) !important;
            box-shadow: var(--cta-shadow) !important;
            color: #ffffff !important;
            border-radius: 24px !important;
            transition: all 0.3s ease;
        }
        .cta-banner-merged h3,
        [data-theme="light"] .cta-banner-merged h3,
        [data-theme="dark"] .cta-banner-merged h3 {
            color: #FFFFFF !important;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.25);
        }
        .cta-banner-merged p,
        [data-theme="light"] .cta-banner-merged p,
        [data-theme="dark"] .cta-banner-merged p {
            color: rgba(255, 255, 255, 0.92) !important;
        }
        .cta-badge-pill {
            background: rgba(255, 255, 255, 0.2) !important;
            border: 1px solid rgba(255, 255, 255, 0.35) !important;
            color: #FFFFFF !important;
            backdrop-filter: blur(6px);
        }
        .cta-badge-pill span {
            color: #FFFFFF !important;
            font-weight: 800 !important;
            letter-spacing: 0.05em !important;
        }
        .cta-banner-merged .btn-light {
            background: var(--cta-btn-bg) !important;
            color: var(--cta-btn-text) !important;
            border: none !important;
            font-weight: 800 !important;
            transition: all 0.25s ease;
        }
        .cta-banner-merged .btn-light:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.35) !important;
        }

        /* 4. Section Skala Kami */
        #skala {
            background-color: var(--bg-base) !important;
            border-bottom: 1px solid var(--border-subtle) !important;
            transition: background-color 0.3s ease;
        }
        #skala h2 {
            color: var(--text-heading) !important;
        }
        #skala .text-muted {
            color: var(--text-muted-dark) !important;
        }
        .card-skala-gojek {
            background: var(--bg-surface) !important;
            border-radius: 24px;
            padding: 2.2rem 1.6rem;
            border: 1px solid var(--border-subtle) !important;
            transition: all 0.25s ease;
            box-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.06) !important;
            height: 100%;
        }
        .card-skala-gojek:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 35px -10px var(--gojek-green-glow) !important;
            border-color: var(--gojek-green) !important;
        }
        .card-skala-gojek .stat-number-big {
            font-weight: 900;
            font-size: 3rem;
            line-height: 1;
            letter-spacing: -0.03em;
            color: var(--gojek-green) !important;
        }
        .card-skala-gojek h5 {
            color: var(--text-heading) !important;
        }
        .card-skala-gojek p {
            color: var(--text-muted-dark) !important;
        }

        /* 5. Section Dosen */
        #dosen {
            background-color: var(--bg-alt) !important;
            border-bottom: 1px solid var(--border-subtle) !important;
            transition: background-color 0.3s ease;
        }
        #dosen h2 {
            color: var(--text-heading) !important;
        }
        #dosen p.text-muted {
            color: var(--text-muted-dark) !important;
        }
        #dosen .card {
            background-color: var(--bg-surface) !important;
            border: 1px solid var(--border-subtle) !important;
            box-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.06) !important;
            border-radius: 20px !important;
            transition: all 0.25s ease;
        }
        #dosen .card:hover {
            border-color: var(--gojek-green) !important;
            transform: translateY(-4px);
        }
        #dosen .card h6 {
            color: var(--text-heading) !important;
        }
        #dosen .card .text-muted {
            color: var(--text-muted-dark) !important;
        }
        #dosen .card .rounded-circle {
            background: linear-gradient(135deg, var(--gojek-green) 0%, #059669 100%) !important;
            color: var(--brand-icon-color) !important;
            border: none !important;
        }
        #dosen .card .badge.bg-light {
            background-color: var(--bg-alt) !important;
            color: var(--text-body) !important;
            border: 1px solid var(--border-subtle) !important;
        }
        #dosen .btn-outline-dark {
            border-color: var(--gojek-green) !important;
            color: var(--gojek-green) !important;
        }
        #dosen .btn-outline-dark:hover {
            background-color: var(--gojek-green) !important;
            color: #FFFFFF !important;
        }

        /* 6. CTA Mulai Bimbingan (Merged inside Alur) */
        #mulai-bimbingan {
            scroll-margin-top: 90px;
        }

        /* 7. Footer & FAQ Terpadu */
        #faq {
            background-color: var(--footer-bg) !important;
            transition: background-color 0.3s ease;
        }
        .faq-dark-item {
            background: rgba(255, 255, 255, 0.04) !important;
            border: 1px solid rgba(255, 255, 255, 0.12) !important;
            border-radius: 18px !important;
            overflow: hidden;
            margin-bottom: 1rem;
            transition: all 0.25s ease;
        }
        .faq-dark-item:hover {
            border-color: rgba(0, 208, 86, 0.45) !important;
            background: rgba(255, 255, 255, 0.07) !important;
        }
        .faq-dark-btn {
            background-color: transparent !important;
            color: #ffffff !important;
            box-shadow: none !important;
            font-size: 1.02rem;
            font-weight: 700 !important;
            padding: 1.15rem 1.4rem !important;
        }
        .faq-dark-btn:not(.collapsed) {
            background-color: rgba(0, 208, 86, 0.12) !important;
            color: var(--gojek-green) !important;
        }
        .faq-dark-btn::after {
            filter: invert(1) brightness(2);
        }
        .faq-dark-body {
            background-color: transparent !important;
            color: rgba(255, 255, 255, 0.82) !important;
            border-top: 1px solid rgba(255, 255, 255, 0.08) !important;
            padding: 1.15rem 1.4rem !important;
            font-size: 0.95rem;
            line-height: 1.7;
        }
        .faq-dark-body code {
            color: var(--gojek-green) !important;
            background: rgba(0, 208, 86, 0.15) !important;
            padding: 2px 6px;
            border-radius: 6px;
        }

        /* 8. Global Badges */
        .badge.bg-success-subtle,
        .badge.bg-secondary-subtle,
        .badge.bg-dark {
            background-color: var(--gojek-green-subtle) !important;
            color: var(--gojek-green) !important;
            border: 1px solid var(--border-subtle) !important;
        }

        /* 9. Footer */
        footer {
            background-color: var(--footer-bg) !important;
            border-top: 1px solid rgba(255, 255, 255, 0.06) !important;
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
                font-size: 2.4rem !important;
            }
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">

<!-- 1. NAVBAR MINIMALIS GOJEK -->
<nav class="navbar navbar-expand-lg navbar-gojek sticky-top py-3">
    <div class="container">
        <!-- Brand Logo -->
        <a class="navbar-brand d-flex align-items-center gap-2 text-decoration-none" href="index.php">
            <div class="d-inline-flex align-items-center justify-content-center rounded-circle shadow-sm" style="width: 40px; height: 40px; background-color: var(--gojek-green) !important; color: var(--brand-icon-color) !important;">
                <i class="bi bi-mortarboard-fill fs-5"></i>
            </div>
            <div class="d-flex flex-column">
                <span class="fs-5 fw-extrabold lh-1 tracking-tight" style="color: var(--text-heading);">SIM Skripsi</span>
                <span class="small" style="font-size: 0.7rem; color: var(--text-muted-dark);">Bimbingan & Tugas Akhir</span>
            </div>
        </a>

        <!-- Hamburger Toggle Mobile/Tablet -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navGojek" aria-controls="navGojek" aria-expanded="false" aria-label="Toggle navigation" style="border-color: var(--border-subtle);">
            <i class="bi bi-list fs-3" style="color: var(--text-heading);"></i>
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
                    <a class="nav-link" href="#alur">Alur & Portal</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#faq">FAQ</a>
                </li>
                <li class="nav-item ms-lg-3 mt-2 mt-lg-0 d-flex align-items-center">
                    <!-- Tombol Ganti Tema Terang/Gelap -->
                    <button id="themeToggle" class="btn-theme-toggle" type="button" aria-label="Ganti Tema (Terang / Gelap)" title="Ganti Mode Tampilan (Terang / Gelap)">
                        <i class="bi bi-sun-fill theme-icon-light"></i>
                        <i class="bi bi-moon-stars-fill theme-icon-dark"></i>
                    </button>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- 2. HERO BANNER UTAMA (Desain Tech Academic Bersih & Gelembung Hijau Ikonik Gojek) -->
<section id="beranda" class="hero-gojek-container py-5">
    <div class="container py-4">
        <div class="row align-items-center g-5">
            <!-- Kolom Kiri: Pesan Kampus Tegas & Poin Solusi -->
            <div class="col-lg-6">
                <div class="pe-lg-3">
                    <div class="d-inline-flex align-items-center gap-2 px-3 py-1.5 rounded-pill small mb-3 shadow-sm" style="background: var(--hero-badge-bg); color: var(--hero-badge-color); border: 1px solid var(--hero-badge-border); font-size: 0.82rem; font-weight: 700;">
                        <i class="bi bi-patch-check-fill" style="color: var(--hero-badge-color);"></i>
                        <span>Sistem Bimbingan Skripsi Terpadu</span>
                    </div>

                    <h1 class="display-5 fw-extrabold mb-3 lh-sm" style="color: var(--text-heading); letter-spacing: -0.035em; font-weight: 900;">
                        Bimbingan Teratur,<br class="d-none d-md-inline">
                        Skripsi Tuntas,<br class="d-none d-md-inline">
                        <span class="hero-title-highlight">Wisuda Tepat Waktu.</span>
                    </h1>

                    <p class="hero-lead-text mb-4">
                        Atur jadwal bimbingan anti-bentrok, pantau revisi naskah, dan cetak kartu kendali sah.
                    </p>

                    <!-- Tombol Aksi Bersih & Rapi (Anchor Bawah Kolom Kiri) -->
                    <div class="d-flex flex-wrap align-items-center gap-3">
                        <a href="#solusi" class="btn-gojek-green shadow-sm">
                            <span>Jelajahi Solusi</span>
                            <i class="bi bi-arrow-down-short fs-5"></i>
                        </a>
                        <a href="login.php" class="btn fw-bold rounded-pill px-4 py-2" style="background: var(--bg-surface); color: var(--text-heading); border: 1.5px solid var(--border-subtle); box-shadow: 0 2px 10px rgba(0,0,0,0.04); text-decoration: none;">
                            <i class="bi bi-box-arrow-in-right me-1"></i> Masuk Portal
                        </a>
                    </div>

                </div>
            </div>

            <!-- Kolom Kanan: GELEMBUNG HIJAU GOJEK DENGAN SLOGAN BESAR -->
            <div class="col-lg-6">
                <div class="bubble-card-gojek">
                    <div class="d-flex align-items-center justify-content-between mb-4">
                        <span class="badge fw-extrabold px-3 py-1.5 rounded-pill shadow-sm" style="font-size: 0.74rem; letter-spacing: 0.05em; background: var(--bubble-badge-bg); color: var(--bubble-badge-text);">
                            <i class="bi bi-mortarboard-fill me-1"></i> KAMPUS TERPADU
                        </span>
                        <span class="small" style="color: rgba(255, 255, 255, 0.92); font-weight: 600;"><i class="bi bi-shield-fill-check me-1" style="color: #FFFFFF;"></i>TiDB Cloud Live</span>
                    </div>

                    <!-- SLOGAN BESAR & BOLD GAYA GOJEK (SEMUA HURUF PUTIH BERSIH) -->
                    <h1 class="bubble-headline mb-0" style="color: #FFFFFF !important; -webkit-text-fill-color: #FFFFFF !important;">
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
<section id="solusi" class="py-5 py-lg-6 border-bottom">
    <div class="container">
        <div class="text-center max-w-700 mx-auto mb-4 mb-lg-5 pb-lg-1">
            <span class="badge bg-success-subtle text-success fw-bold px-3 py-1.5 rounded-pill mb-2">SOLUSI TANTANGAN SKRIPSI</span>
            <h2 class="fw-extrabold display-6 mb-2">Menghadirkan Solusi untuk Menghadapi Hambatan Bimbingan</h2>
            <p class="text-muted mb-0">Skripsi sering terhambat karena jadwal dosen sulit dicocokkan atau revisi tercecer? Kami selesaikan secara sistematis.</p>
        </div>

        <div class="row g-3 g-lg-4">
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
<section id="skala" class="py-5 py-lg-6 border-bottom">
    <div class="container">
        <div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-4 mb-lg-5 pb-lg-1">
            <div>
                <span class="badge fw-bold px-3 py-1.5 rounded-pill mb-2">DATA SISTEM REALTIME</span>
                <h2 class="fw-extrabold display-6 mb-0" style="color: var(--text-heading);">Skala Kami</h2>
            </div>
            <div class="small" style="color: var(--text-muted-dark);">
                Terhubung langsung ke kluster basis data TiDB Serverless
            </div>
        </div>

        <div class="row g-3 g-lg-4 text-start">
            <div class="col-6 col-lg-3">
                <div class="card-skala-gojek">
                    <div class="stat-number-big mb-2"><?= $total_dosen; ?></div>
                    <h5 class="fw-bold mb-1" style="color: var(--text-heading);">Dosen Pembimbing</h5>
                    <p class="small mb-0" style="color: var(--text-muted-dark);">Terdaftar aktif dan bersertifikasi NIDN resmi di program studi.</p>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card-skala-gojek">
                    <div class="stat-number-big mb-2"><?= $total_mhs; ?></div>
                    <h5 class="fw-bold mb-1" style="color: var(--text-heading);">Mahasiswa Tugas Akhir</h5>
                    <p class="small mb-0" style="color: var(--text-muted-dark);">Terbagi pada 3 kelas paralel (Kelas TI-1A, TI-1B, dan TI-1C).</p>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card-skala-gojek">
                    <div class="stat-number-big mb-2">Maks 5</div>
                    <h5 class="fw-bold mb-1" style="color: var(--text-heading);">Batas Kuota / Dosen</h5>
                    <p class="small mb-0" style="color: var(--text-muted-dark);">Rasio ideal untuk menjamin mutu dan kedalaman konsultasi skripsi.</p>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card-skala-gojek">
                    <div class="stat-number-big mb-2">100%</div>
                    <h5 class="fw-bold mb-1" style="color: var(--text-heading);">Bebas Bentrok Jadwal</h5>
                    <p class="small mb-0" style="color: var(--text-muted-dark);">Validasi otomatis mencegah tumpang tindih waktu bimbingan mahasiswa.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- 6. SECTION DOSEN PEMBIMBING (Gaya Kartu Mitra Gojek) -->
<section id="dosen" class="py-5 py-lg-6 border-bottom">
    <div class="container">
        <div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-3 mb-md-4">
            <div>
                <span class="badge fw-bold px-3 py-1.5 rounded-pill mb-2">TENAGA PENDIDIK</span>
                <h2 class="fw-extrabold display-6 mb-1" style="color: var(--text-heading);">Dosen Pembimbing Akademik</h2>
                <p class="mb-0" style="color: var(--text-muted-dark);">Dosen yang aktif membimbing tugas akhir mahasiswa semester ini.</p>
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
                        <div class="card p-3 rounded-4 h-100 border shadow-sm" style="transition: transform 0.2s ease;">
                            <div class="d-flex align-items-center gap-3">
                                <div class="rounded-circle text-white d-flex align-items-center justify-content-center fw-bold fs-5 flex-shrink-0" style="width: 50px; height: 50px; background: linear-gradient(135deg, var(--gojek-green) 0%, #059669 100%); color: var(--brand-icon-color) !important;">
                                    <?= strtoupper(substr($d['nama_dosen'], 0, 1)); ?>
                                </div>
                                <div class="overflow-hidden">
                                    <h6 class="fw-bold text-truncate mb-0" style="color: var(--text-heading);" title="<?= htmlspecialchars($d['nama_dosen']); ?>">
                                        <?= htmlspecialchars($d['nama_dosen']); ?>
                                    </h6>
                                    <div class="small" style="font-size: 0.78rem; color: var(--text-muted-dark);">
                                        NIDN: <strong><?= htmlspecialchars($d['nidn'] ?? '-'); ?></strong>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 mt-1">
                                        <span class="badge bg-light border" style="font-size: 0.7rem;">
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

<!-- 6. SECTION ALUR & MULAI BIMBINGAN (Hasil Penggabungan Terpadu - Rapi & Pas di Layar) -->
<section id="alur" class="pt-3 pt-md-4 pb-4 border-bottom">
    <div class="container">
        <!-- Judul Atas Rekomendasi: Empat Langkah Pasti Menuju Wisuda Tepat Waktu -->
        <div class="text-center max-w-700 mx-auto mb-3">
            <span class="badge bg-success-subtle text-success fw-bold px-3 py-1 rounded-pill mb-2">#PASTIADAJALAN MENUJU SIDANG</span>
            <h2 class="fw-extrabold fs-2 mb-2">Empat Langkah Pasti Menuju Wisuda Tepat Waktu</h2>
            <p class="text-muted mb-0 fs-6" style="line-height: 1.7;">Alur bimbingan terstruktur dan transparan dari usulan judul hingga siap mendaftar sidang akademik.</p>
        </div>

        <!-- 4 Kotak Alur Ringkas & Rapi (Spacious Step Cards) -->
        <div class="row g-3 mb-3">
            <!-- Langkah 1: Pengajuan Judul -->
            <div class="col-md-6 col-lg-3">
                <div class="card-alur-gojek">
                    <div>
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <span class="step-number-pill">01</span>
                            <div class="step-icon-wrap">
                                <i class="bi bi-file-earmark-text-fill"></i>
                            </div>
                        </div>
                        <span class="badge bg-success-subtle text-success fw-semibold rounded-pill px-2.5 py-1 mb-2" style="font-size: 0.72rem;">Tahap 1</span>
                        <h5 class="fw-bold">Pengajuan Judul</h5>
                        <p>Usulkan topik skripsi dan unggah matriks proposal awal untuk validasi dosen pembimbing.</p>
                    </div>
                </div>
            </div>

            <!-- Langkah 2: Booking Jadwal -->
            <div class="col-md-6 col-lg-3">
                <div class="card-alur-gojek">
                    <div>
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <span class="step-number-pill">02</span>
                            <div class="step-icon-wrap">
                                <i class="bi bi-calendar-check-fill"></i>
                            </div>
                        </div>
                        <span class="badge bg-success-subtle text-success fw-semibold rounded-pill px-2.5 py-1 mb-2" style="font-size: 0.72rem;">Tahap 2</span>
                        <h5 class="fw-bold">Reservasi Jadwal</h5>
                        <p>Pilih slot waktu tatap muka atau daring dosen secara real-time anti-bentrok.</p>
                    </div>
                </div>
            </div>

            <!-- Langkah 3: Penyerahan Draf & Evaluasi -->
            <div class="col-md-6 col-lg-3">
                <div class="card-alur-gojek">
                    <div>
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <span class="step-number-pill">03</span>
                            <div class="step-icon-wrap">
                                <i class="bi bi-arrow-repeat"></i>
                            </div>
                        </div>
                        <span class="badge bg-success-subtle text-success fw-semibold rounded-pill px-2.5 py-1 mb-2" style="font-size: 0.72rem;">Tahap 3</span>
                        <h5 class="fw-bold">Bimbingan & Revisi</h5>
                        <p>Unggah berkas draf bab PDF (Bab 1 - 5), catat evaluasi pembimbing, dan pantau revisi.</p>
                    </div>
                </div>
            </div>

            <!-- Langkah 4: ACC & Cetak Kartu -->
            <div class="col-md-6 col-lg-3">
                <div class="card-alur-gojek">
                    <div>
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <span class="step-number-pill">04</span>
                            <div class="step-icon-wrap">
                                <i class="bi bi-award-fill"></i>
                            </div>
                        </div>
                        <span class="badge bg-success-subtle text-success fw-semibold rounded-pill px-2.5 py-1 mb-2" style="font-size: 0.72rem;">Tahap 4</span>
                        <h5 class="fw-bold">ACC & Cetak Kartu</h5>
                        <p>Setelah kuota terpenuhi dan disetujui, kartu kendali siap dicetak untuk daftar sidang.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- CTA Strip Hasil Penggabungan (Lega, Terpisah, & Elegan) -->
        <div id="mulai-bimbingan" class="cta-banner-merged mt-3 p-3.5 p-md-4 rounded-4 shadow-lg position-relative overflow-hidden">
            <div class="row align-items-center g-4 position-relative" style="z-index: 2;">
                <div class="col-lg-8 text-center text-lg-start">
                    <div class="cta-badge-pill d-inline-flex align-items-center gap-2 px-3 py-1.5 rounded-pill mb-3">
                        <i class="bi bi-lightning-charge-fill text-warning"></i>
                        <span class="fw-extrabold text-uppercase" style="font-size: 0.82rem;">Akses Portal Mandiri</span>
                    </div>
                    <h3 class="fw-extrabold mb-2" style="font-size: 1.65rem; letter-spacing: -0.02em;">Siap Memulai Langkah Pertama Skripsi Anda?</h3>
                    <p class="mb-0" style="font-size: 1rem; line-height: 1.6; max-width: 680px;">Masuk menggunakan akun akademik Anda untuk menjadwalkan sesi bimbingan pertama hari ini.</p>
                </div>
                <div class="col-lg-4 text-center text-lg-end">
                    <a href="login.php" class="btn btn-light fw-extrabold px-4 py-2.5 rounded-pill shadow d-inline-flex align-items-center gap-2 text-decoration-none" style="font-size: 1rem;">
                        <i class="bi bi-box-arrow-in-right fs-4"></i>
                        <span>Masuk ke Portal</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- 8. FOOTER HITAM GOJEK & FAQ TERPADU (Bagian Bawah Terpadu) -->
<footer id="faq" class="text-white pt-5 pb-4 mt-auto" style="background-color: var(--footer-bg) !important;">
    <div class="container">
        <!-- Bagian FAQ Terpadu di Bagian Paling Bawah -->
        <div class="pb-5 border-bottom border-secondary border-opacity-25">
            <div class="text-center max-w-700 mx-auto mb-4">
                <span class="badge bg-success-subtle text-success fw-bold px-3 py-1.5 rounded-pill mb-2">TANYA JAWAB</span>
                <h2 class="fw-extrabold display-6 text-white mb-2">Pertanyaan yang Kerap Ditanyakan</h2>
                <p class="text-white-50 mb-0">Panduan singkat seputar teknis bimbingan skripsi akademik.</p>
            </div>

            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="accordion accordion-flush" id="accordionGojek">
                        <div class="accordion-item faq-dark-item mb-3">
                            <h2 class="accordion-header" id="faqH1">
                                <button class="accordion-button collapsed faq-dark-btn" type="button" data-bs-toggle="collapse" data-bs-target="#faqC1">
                                    Bagaimana jika jadwal dosen tidak cocok dengan jadwal mahasiswa?
                                </button>
                            </h2>
                            <div id="faqC1" class="accordion-collapse collapse" data-bs-parent="#accordionGojek">
                                <div class="accordion-body faq-dark-body">
                                    Mahasiswa dapat berkoordinasi langsung dengan dosen pembimbing melalui fitur pesan/chat yang tersedia di dalam portal untuk menyepakati slot waktu tambahan.
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item faq-dark-item mb-3">
                            <h2 class="accordion-header" id="faqH2">
                                <button class="accordion-button collapsed faq-dark-btn" type="button" data-bs-toggle="collapse" data-bs-target="#faqC2">
                                    Berapa batas kapasitas file draf naskah skripsi yang diizinkan?
                                </button>
                            </h2>
                            <div id="faqC2" class="accordion-collapse collapse" data-bs-parent="#accordionGojek">
                                <div class="accordion-body faq-dark-body">
                                    Berkas draf naskah wajib berekstensi <code>.pdf</code> dengan ukuran maksimum <strong>5.0 Megabytes</strong> per unggahan demi menjaga efisiensi penyimpanan cloud.
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item faq-dark-item mb-3">
                            <h2 class="accordion-header" id="faqH3">
                                <button class="accordion-button collapsed faq-dark-btn" type="button" data-bs-toggle="collapse" data-bs-target="#faqC3">
                                    Kapan mahasiswa dinyatakan siap mendaftar sidang tugas akhir?
                                </button>
                            </h2>
                            <div id="faqC3" class="accordion-collapse collapse" data-bs-parent="#accordionGojek">
                                <div class="accordion-body faq-dark-body">
                                    Mahasiswa dapat mendaftar sidang setelah mengumpulkan sekurang-kurangnya 8 sesi bimbingan sah dan telah memperoleh pengesahan status ACC Sidang dari Dosen Pembimbing Utama.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Informasi Footer & Navigasi -->
        <div class="row g-4 pt-5 pb-4 border-bottom border-secondary border-opacity-25">
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
                    <li><a href="#alur" class="text-white-50 text-decoration-none">Alur & Portal</a></li>
                    <li><a href="#faq" class="text-white-50 text-decoration-none">FAQ (Tanya Jawab)</a></li>
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

<!-- Script Navigasi Interaktif & Switcher Mode Terang / Gelap -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    // 1. Switcher Mode Terang / Gelap (Light / Dark Mode Toggle)
    const themeToggleBtn = document.getElementById('themeToggle');
    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', function () {
            const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
            const targetTheme = currentTheme === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', targetTheme);
            localStorage.setItem('theme', targetTheme);
        });
    }

    // 2. Event Navigasi Interaktif
    const navLinks = document.querySelectorAll('.navbar-gojek .nav-link[href^="#"]');

    // Event Klik Navigasi (Teks langsung aktif putih tebal)
    navLinks.forEach(function (link) {
        link.addEventListener('click', function () {
            navLinks.forEach(l => l.classList.remove('active'));
            this.classList.add('active');
        });
    });

    // Otomatis Aktif saat di-Scroll (Intersection Observer)
    const trackedSections = document.querySelectorAll('section[id], footer[id]');
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
