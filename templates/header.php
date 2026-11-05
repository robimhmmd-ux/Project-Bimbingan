<?php
if (!ob_get_level()) {
    ob_start();
}
// templates/header.php
if (!isset($pdo) && file_exists(__DIR__ . '/../config/database.php')) {
    require_once __DIR__ . '/../config/database.php';
}
if (file_exists(__DIR__ . '/../config/session_helper.php')) {
    require_once __DIR__ . '/../config/session_helper.php';
    init_app_session($pdo ?? null);
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Proteksi login umum
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login");
    exit;
}

if (file_exists(__DIR__ . '/../config/avatar_helper.php')) {
    require_once __DIR__ . '/../config/avatar_helper.php';
}
if (file_exists(__DIR__ . '/../config/storage_helper.php')) {
    require_once __DIR__ . '/../config/storage_helper.php';
}

$css_ver     = file_exists(__DIR__ . '/../assets/css/style.css') ? filemtime(__DIR__ . '/../assets/css/style.css') : time();
$cur_user_id = (int)($_SESSION['user_id'] ?? 0);
$cur_role    = $_SESSION['role'] ?? '';

// Sinkronisasi otomatis data profil & foto profil dari database (agar selalu segar & akurat di header)
if (isset($pdo) && $cur_user_id > 0) {
    try {
        if ($cur_role === 'admin') {
            $stmt_sync = $pdo->prepare("SELECT nama_lengkap, foto_profil FROM admin_profil WHERE user_id = :uid LIMIT 1");
            $stmt_sync->execute([':uid' => $cur_user_id]);
            $sync_data = $stmt_sync->fetch(PDO::FETCH_ASSOC);
            if ($sync_data) {
                if (!empty($sync_data['nama_lengkap'])) $_SESSION['nama_lengkap'] = $sync_data['nama_lengkap'];
                if (!empty($sync_data['foto_profil']))  $_SESSION['foto_profil']  = $sync_data['foto_profil'];
            }
        } elseif ($cur_role === 'dosen') {
            $stmt_sync = $pdo->prepare("SELECT nama_dosen, foto_profil FROM dosen WHERE user_id = :uid LIMIT 1");
            $stmt_sync->execute([':uid' => $cur_user_id]);
            $sync_data = $stmt_sync->fetch(PDO::FETCH_ASSOC);
            if ($sync_data) {
                if (!empty($sync_data['nama_dosen']))   $_SESSION['nama_lengkap'] = $sync_data['nama_dosen'];
                if (!empty($sync_data['foto_profil']))  $_SESSION['foto_profil']  = $sync_data['foto_profil'];
            }
        } elseif ($cur_role === 'mahasiswa') {
            $stmt_sync = $pdo->prepare("SELECT nama_lengkap, foto_profil FROM mahasiswa WHERE user_id = :uid LIMIT 1");
            $stmt_sync->execute([':uid' => $cur_user_id]);
            $sync_data = $stmt_sync->fetch(PDO::FETCH_ASSOC);
            if ($sync_data) {
                if (!empty($sync_data['nama_lengkap'])) $_SESSION['nama_lengkap'] = $sync_data['nama_lengkap'];
                if (!empty($sync_data['foto_profil']))  $_SESSION['foto_profil']  = $sync_data['foto_profil'];
            }
        }
    } catch (PDOException $e) {}
}

// Ambil data notifikasi aktif untuk pengguna saat ini (UPDATE Robi: tabel notifikasi)
$notif_unread_count = 0;
$notif_list = [];
if (isset($pdo) && isset($_SESSION['user_id'])) {
    // Handle aksi tandai semua notifikasi dibaca
    if (isset($_GET['mark_all_read']) && $_GET['mark_all_read'] == '1') {
        try {
            $pdo->prepare("UPDATE notifikasi SET is_read = 1 WHERE user_id = :uid")->execute([':uid' => $cur_user_id]);
        } catch (PDOException $e) {}
        $clean_url = strtok($_SERVER['REQUEST_URI'], '?');
        if (!headers_sent()) {
            header("Location: " . $clean_url);
        } else {
            echo "<script>window.location.href='" . $clean_url . "';</script>";
        }
        exit;
    }

    // Handle aksi klik notifikasi spesifik (tandai dibaca lalu redirect ke link tujuan)
    if (isset($_GET['open_nid']) && !empty($_GET['open_nid'])) {
        $nid = (int)$_GET['open_nid'];
        $dest = trim($_GET['dest'] ?? 'index.php');
        try {
            $pdo->prepare("UPDATE notifikasi SET is_read = 1 WHERE id = :nid AND user_id = :uid")->execute([':nid' => $nid, ':uid' => $cur_user_id]);
        } catch (PDOException $e) {}
        if (empty($dest)) {
            $dest = 'index.php';
        }
        if (!headers_sent()) {
            header("Location: " . $dest);
        } else {
            echo "<script>window.location.href='" . $dest . "';</script>";
        }
        exit;
    }

    try {
        $st_notif_cnt = $pdo->prepare("SELECT COUNT(*) FROM notifikasi WHERE user_id = :uid AND is_read = 0");
        $st_notif_cnt->execute([':uid' => $cur_user_id]);
        $notif_unread_count = (int)$st_notif_cnt->fetchColumn();

        $st_notif_list = $pdo->prepare("SELECT * FROM notifikasi WHERE user_id = :uid ORDER BY id DESC LIMIT 5");
        $st_notif_list->execute([':uid' => $cur_user_id]);
        $notif_list = $st_notif_list->fetchAll();
    } catch (PDOException $e) {
        $notif_unread_count = 0;
        $notif_list = [];
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($page_title) ? htmlspecialchars($page_title) : 'Sistem Bimbingan Skripsi'; ?></title>
    <!-- Favicon Web -->
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <link rel="shortcut icon" href="../favicon.ico">
    <!-- Bootstrap 5 CSS via CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Google Fonts Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Custom CSS with Cache Buster -->
    <link href="../assets/css/style.css?v=<?= $css_ver; ?>" rel="stylesheet">
    <!-- SweetAlert2 Modern Alerts & Modals via CDN -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Script Deteksi & Pengalih Tema Terang / Gelap (Anti-Flicker & Instant) -->
    <script>
        (function() {
            var savedTheme = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
        })();

        function toggleAppTheme(e) {
            if (e) {
                if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
                if (typeof e.stopPropagation === 'function') e.stopPropagation();
                if (typeof e.preventDefault === 'function') e.preventDefault();
            }
            var html = document.documentElement;
            var cur = html.getAttribute('data-theme') || 'light';
            var next = (cur === 'dark') ? 'light' : 'dark';
            html.setAttribute('data-theme', next);
            try {
                localStorage.setItem('theme', next);
            } catch (err) {}
        }
    </script>

    <style>
        /* =========================================================================
           JAMINAN MUTLAK: TOPBAR TETAP FIX DI ATAS & SIDEBAR TIDAK IKUT SCROLL
           ========================================================================= */
        :root {
            --navbar-height: 60px;
            --sidebar-width: 250px;
            --sidebar-collapsed-width: 72px;
        }

        /* 1. Body wajib diberi padding-top presisi sama dengan tinggi navbar */
        body {
            padding-top: var(--navbar-height, 60px) !important;
            overflow-x: hidden;
        }

        /* 2. Topbar Navbar: Fixed di Atas (TIDAK BISA TER-SCROLL SAMA SEKALI) */
        #topNavbar.navbar,
        .navbar.fixed-top {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
            width: 100% !important;
            height: var(--navbar-height, 60px) !important;
            min-height: var(--navbar-height, 60px) !important;
            max-height: var(--navbar-height, 60px) !important;
            z-index: 1060 !important;
            background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%) !important;
            border-bottom: 1px solid rgba(255, 255, 255, 0.15) !important;
            box-shadow: 0 4px 15px rgba(15, 23, 42, 0.2) !important;
            padding-top: 0 !important;
            padding-bottom: 0 !important;
            display: flex !important;
            align-items: center !important;
            margin: 0 !important;
        }

        /* Modal Stacking & Overlay: Pastikan modal dan backdrop selalu berada di atas navbar & sidebar */
        .modal {
            z-index: 1065 !important;
        }
        .modal-backdrop {
            z-index: 1060 !important;
        }

        /* 3. Sidebar Desktop (>= 768px): Stationary (Fixed di Kiri di Bawah Navbar) */
        @media (min-width: 768px) {
            .sidebar,
            #sidebarMenu {
                position: fixed !important;
                top: var(--navbar-height, 60px) !important;
                left: 0 !important;
                bottom: 0 !important;
                width: var(--sidebar-width, 250px) !important;
                height: calc(100vh - var(--navbar-height, 60px)) !important;
                max-height: calc(100vh - var(--navbar-height, 60px)) !important;
                z-index: 1020 !important;
                background-color: var(--surface, #ffffff) !important;
                border-right: 1px solid var(--border-color, #e2e8f0) !important;
                border-top: none !important;
                overflow-y: auto !important;
                overflow-x: hidden !important;
                padding: 1.25rem 0.85rem !important;
                margin: 0 !important;
                transition: width 0.22s cubic-bezier(0.4, 0, 0.2, 1), padding 0.22s ease !important;
            }

            .sidebar::-webkit-scrollbar {
                width: 4px;
            }
            .sidebar::-webkit-scrollbar-thumb {
                background: #cbd5e1;
                border-radius: 4px;
            }

            /* Area Konten Utama Desktop Berada Pas di Kanan Sidebar */
            main,
            .main-content-wrapper,
            #mainContent {
                margin-left: var(--sidebar-width, 250px) !important;
                width: calc(100% - var(--sidebar-width, 250px)) !important;
                max-width: calc(100% - var(--sidebar-width, 250px)) !important;
                min-height: calc(100vh - var(--navbar-height, 60px)) !important;
                padding: 1.5rem 2rem !important;
                transition: margin-left 0.22s cubic-bezier(0.4, 0, 0.2, 1), width 0.22s cubic-bezier(0.4, 0, 0.2, 1) !important;
            }

            /* Mode Mini-Sidebar (Collapsed): Ikon Menu Tetap Tampil, Teks Disembunyikan */
            html.sidebar-collapsed .sidebar,
            body.sidebar-collapsed .sidebar,
            html.sidebar-collapsed #sidebarMenu,
            body.sidebar-collapsed #sidebarMenu {
                display: block !important;
                visibility: visible !important;
                width: var(--sidebar-collapsed-width, 72px) !important;
                max-width: var(--sidebar-collapsed-width, 72px) !important;
                padding: 1.25rem 0.4rem !important;
                overflow-x: hidden !important;
            }

            html.sidebar-collapsed .sidebar .sidebar-heading,
            body.sidebar-collapsed .sidebar .sidebar-heading,
            html.sidebar-collapsed .sidebar .menu-text,
            body.sidebar-collapsed .sidebar .menu-text,
            html.sidebar-collapsed .sidebar .badge,
            body.sidebar-collapsed .sidebar .badge,
            html.sidebar-collapsed .sidebar .sidebar-category > span,
            body.sidebar-collapsed .sidebar .sidebar-category > span,
            html.sidebar-collapsed .sidebar .sidebar-system-card,
            body.sidebar-collapsed .sidebar .sidebar-system-card,
            html.sidebar-collapsed .sidebar .lock-icon,
            body.sidebar-collapsed .sidebar .lock-icon {
                display: none !important;
            }

            html.sidebar-collapsed .sidebar .nav-link,
            body.sidebar-collapsed .sidebar .nav-link {
                display: flex !important;
                justify-content: center !important;
                align-items: center !important;
                padding: 0.75rem 0 !important;
                width: 48px !important;
                height: 48px !important;
                margin: 0 auto 6px auto !important;
                text-align: center !important;
                border-radius: 10px !important;
                font-size: 0 !important;
            }

            html.sidebar-collapsed .sidebar .nav-link i,
            body.sidebar-collapsed .sidebar .nav-link i {
                margin-right: 0 !important;
                font-size: 1.35rem !important;
            }

            html.sidebar-collapsed main,
            html.sidebar-collapsed .main-content-wrapper,
            html.sidebar-collapsed #mainContent,
            body.sidebar-collapsed main,
            body.sidebar-collapsed .main-content-wrapper,
            body.sidebar-collapsed #mainContent {
                margin-left: var(--sidebar-collapsed-width, 72px) !important;
                width: calc(100% - var(--sidebar-collapsed-width, 72px)) !important;
                max-width: calc(100% - var(--sidebar-collapsed-width, 72px)) !important;
            }
        }

        /* 4. Mobile (< 768px): Drawer Menu Melayang & Optimalisasi Layar HP */
        @media (max-width: 767.98px) {
            .sidebar,
            #sidebarMenu {
                display: block !important;
                position: fixed !important;
                top: var(--navbar-height, 60px) !important;
                left: 0 !important;
                bottom: 0 !important;
width: 275px !important;
                max-width: 85vw !important;
                height: calc(100vh - var(--navbar-height, 60px)) !important;
                z-index: 1070 !important;
                transform: translateX(-100%) !important;
                transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1) !important;
                box-shadow: none !important;
background-color: #ffffff !important;
                overflow-y: auto !important;
                -webkit-overflow-scrolling: touch !important;
            }

            body.sidebar-open-mobile .sidebar,
            body.sidebar-mobile-open .sidebar,
            body.sidebar-open-mobile #sidebarMenu,
            body.sidebar-mobile-open #sidebarMenu {
                transform: translateX(0) !important;
                box-shadow: 0 12px 40px rgba(15, 23, 42, 0.45) !important;
            }

            .sidebar-mobile-backdrop {
                display: none;
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                right: 0 !important;
                bottom: 0 !important;
background: rgba(15, 23, 42, 0.6) !important;
                backdrop-filter: blur(3px) !important;
                -webkit-backdrop-filter: blur(3px) !important;
                z-index: 1065 !important;
            }

            body.sidebar-open-mobile .sidebar-mobile-backdrop,
            body.sidebar-mobile-open .sidebar-mobile-backdrop {
                display: block !important;
            }

            main,
            .main-content-wrapper,
            #mainContent {
                margin-left: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
padding: 1rem 0.75rem 2.5rem 0.75rem !important;
            }

            #topNavbar .dropdown-menu {
                position: fixed !important;
                top: calc(var(--navbar-height, 60px) + 6px) !important;
                left: 10px !important;
                right: 10px !important;
                width: auto !important;
                max-width: calc(100vw - 20px) !important;
                min-width: 0 !important;
                border-radius: 14px !important;
            }
        }

        /* Tombol Toggle Sidebar */
        .btn-sidebar-toggle {
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-sidebar-toggle:hover {
            background: rgba(255, 255, 255, 0.28) !important;
            transform: scale(1.06);
        }
        .navbar-user-pill:hover {
            background: rgba(255, 255, 255, 0.2) !important;
            border-color: rgba(255, 255, 255, 0.4) !important;
            transform: scale(1.02);
        }

        /* =========================================================================
           DUKUNGAN TEMA GELAP (DARK MODE) TOPBAR, SIDEBAR & MAIN CONTENT
           ========================================================================= */
        [data-theme="dark"] .sidebar,
        [data-theme="dark"] #sidebarMenu {
            background-color: #131b2a !important;
            border-right: 1px solid rgba(255, 255, 255, 0.08) !important;
        }

        [data-theme="dark"] .sidebar::-webkit-scrollbar-thumb {
            background: #334155 !important;
        }

        [data-theme="dark"] body {
            background-color: #0a0e17 !important;
            color: #f1f5f9 !important;
        }

        [data-theme="dark"] main,
        [data-theme="dark"] .main-content-wrapper,
        [data-theme="dark"] #mainContent {
            background-color: #0a0e17 !important;
            color: #f1f5f9 !important;
        }

        [data-theme="dark"] .card,
        [data-theme="dark"] .bg-white {
            background-color: #131b2a !important;
            color: #f1f5f9 !important;
            border-color: rgba(255, 255, 255, 0.08) !important;
        }

        /* Sidebar Dark Mode Nav-Links & Icons */
        [data-theme="dark"] #sidebarMenu .nav-link,
        [data-theme="dark"] .sidebar .nav-link,
        [data-theme="dark"] .sidebar .nav-link span,
        [data-theme="dark"] .sidebar .nav-link .menu-text {
            color: #cbd5e1 !important;
        }
        [data-theme="dark"] #sidebarMenu .nav-link i,
        [data-theme="dark"] .sidebar .nav-link i {
            color: #94a3b8 !important;
        }
        [data-theme="dark"] #sidebarMenu .nav-link:hover,
        [data-theme="dark"] .sidebar .nav-link:hover {
            background-color: rgba(255, 255, 255, 0.08) !important;
            color: #ffffff !important;
        }
        [data-theme="dark"] #sidebarMenu .nav-link:hover i,
        [data-theme="dark"] #sidebarMenu .nav-link:hover span,
        [data-theme="dark"] #sidebarMenu .nav-link:hover .menu-text {
            color: #ffffff !important;
        }
        [data-theme="dark"] #sidebarMenu .nav-link.active,
        [data-theme="dark"] .sidebar .nav-link.active,
        [data-theme="dark"] .sidebar .nav-pills .nav-link.active {
            background: #00D056 !important;
            color: #0A0E17 !important;
            font-weight: 800 !important;
        }
        [data-theme="dark"] #sidebarMenu .nav-link.active i,
        [data-theme="dark"] #sidebarMenu .nav-link.active span,
        [data-theme="dark"] #sidebarMenu .nav-link.active .menu-text {
            color: #0A0E17 !important;
        }
    </style>
    <script>
        // State awal instan sebelum render
        if (window.innerWidth >= 768 && localStorage.getItem('sidebar_collapsed') === 'true') {
            document.documentElement.classList.add('sidebar-collapsed');
        }

        // FUNGSI UTAMA TOGGLE SIDEBAR (Tunggal & Handal)
        window.toggleSidebarMenu = function() {
            const isMobile = window.innerWidth < 768;
            const html = document.documentElement;
            const body = document.body;
            const icon = document.getElementById('sidebarToggleIcon');

            if (isMobile) {
                const isOpen = body.classList.toggle('sidebar-open-mobile');
                body.classList.toggle('sidebar-mobile-open', isOpen);
                if (icon) {
                    icon.className = isOpen ? 'bi bi-x-lg fs-4 text-white' : 'bi bi-list fs-3 text-white';
                }
            } else {
                const isCollapsed = body.classList.toggle('sidebar-collapsed');
                html.classList.toggle('sidebar-collapsed', isCollapsed);
                localStorage.setItem('sidebar_collapsed', isCollapsed ? 'true' : 'false');

                if (icon) {
                    icon.className = isCollapsed ? 'bi bi-list fs-3 text-warning' : 'bi bi-list fs-3 text-white';
                }
            }
        };

        // Sinkronisasi ikon & event listener penutupan otomatis drawer mobile saat link diklik
        document.addEventListener('DOMContentLoaded', function() {
            const isCollapsed = document.body.classList.contains('sidebar-collapsed') || document.documentElement.classList.contains('sidebar-collapsed');
            const icon = document.getElementById('sidebarToggleIcon');
            if (icon && window.innerWidth >= 768 && isCollapsed) {
                icon.className = 'bi bi-list fs-3 text-warning';
            }

            // Auto-close drawer saat klik nav-link pada smartphone
            document.querySelectorAll('#sidebarMenu a.nav-link').forEach(function(link) {
                link.addEventListener('click', function() {
                    if (window.innerWidth < 768 && document.body.classList.contains('sidebar-open-mobile')) {
                        window.toggleSidebarMenu();
                    }
                });
            });
        });
    </script>
</head>
<body>

<!-- Topbar Navbar (Fixed Top - Solid High-Contrast Navy Blue) -->
<nav class="navbar navbar-expand navbar-dark fixed-top shadow-sm py-0" id="topNavbar" style="background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%) !important; height: 58px; min-height: 58px; max-height: 58px; z-index: 1060 !important; border-bottom: 1px solid rgba(255, 255, 255, 0.15) !important;">
    <div class="container-fluid px-2 px-sm-3 px-md-4 d-flex align-items-center justify-content-between flex-nowrap" style="height: 100%;">
        <!-- Tombol Buka/Tutup Sidebar & Brand Logo -->
        <div class="d-flex align-items-center gap-1 gap-sm-2 flex-shrink-1 overflow-hidden">
            <button class="btn btn-outline-light border-0 p-1 btn-sidebar-toggle d-flex align-items-center justify-content-center shadow-none flex-shrink-0" 
                    id="sidebarToggleBtn" 
                    type="button" 
                    onclick="toggleSidebarMenu()" 
                    aria-label="Toggle Sidebar" 
                    title="Buka / Tutup Sidebar"
                    style="background: rgba(255, 255, 255, 0.18); border-radius: 8px; cursor: pointer; width: 38px; height: 38px; min-width: 38px;">
                <i class="bi bi-list fs-4 text-white" id="sidebarToggleIcon"></i>
            </button>
            <a class="navbar-brand d-flex align-items-center gap-1 gap-sm-2 fw-bold text-white text-decoration-none m-0 overflow-hidden" href="index.php">
                <span class="brand-icon-academic d-inline-flex align-items-center justify-content-center text-white flex-shrink-0" style="width: 32px; height: 32px; border-radius: 8px; background: linear-gradient(135deg, #00AA13 0%, #00880C 100%); font-size: 0.95rem;">
                    <i class="bi bi-mortarboard-fill"></i>
                </span>
                <div class="d-flex flex-column overflow-hidden">
                    <span class="fs-6 lh-1 brand-text-title text-nowrap">Bimbingan Skripsi</span>
                    <span class="text-white-50 small d-none d-md-inline" style="font-size: 0.7rem; font-weight: normal;">Sistem Informasi Akademik</span>
                </div>
            </a>
        </div>

        <!-- Right Side User, Notifications & Status Pill -->
        <div class="d-flex align-items-center gap-1 gap-sm-2 text-white flex-shrink-0">
            <!-- Dropdown Notifikasi Sistem (UPDATE Robi: tabel notifikasi) -->
            <div class="dropdown">
                <button class="btn btn-link text-white position-relative p-1 text-decoration-none shadow-none" 
                        type="button" 
                        id="dropdownNotifikasi" 
                        data-bs-toggle="dropdown" 
                        aria-expanded="false" 
                        title="Notifikasi & Pemberitahuan Sistem">
                    <i class="bi bi-bell-fill fs-5"></i>
                    <?php if ($notif_unread_count > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger border border-light" style="font-size: 0.62rem; padding: 0.25em 0.5em;">
                            <?= $notif_unread_count > 9 ? '9+' : $notif_unread_count; ?>
                        </span>
                    <?php endif; ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-0" aria-labelledby="dropdownNotifikasi" style="width: min(340px, 92vw); max-width: 380px;">
                    <li class="p-3 bg-light border-bottom d-flex justify-content-between align-items-center">
                        <span class="fw-bold text-dark small"><i class="bi bi-bell me-1 text-primary"></i>Pemberitahuan Sistem</span>
                        <?php if ($notif_unread_count > 0): ?>
                            <a href="?mark_all_read=1" class="text-decoration-none small text-primary fw-semibold" style="font-size: 0.72rem;">
                                <i class="bi bi-check2-all me-1"></i>Tandai Dibaca
                            </a>
                        <?php endif; ?>
                    </li>
                    <?php if (empty($notif_list)): ?>
                        <li class="p-4 text-center text-muted small">
                            <i class="bi bi-bell-slash fs-3 d-block mb-1 text-secondary opacity-50"></i>
                            Tidak ada notifikasi baru.
                        </li>
                    <?php else: ?>
                        <?php foreach ($notif_list as $n): 
                            $icon_n = 'bi-info-circle text-primary';
                            if ($n['tipe'] === 'pembimbing') $icon_n = 'bi-award-fill text-warning';
                            elseif ($n['tipe'] === 'sidang') $icon_n = 'bi-mortarboard-fill text-info';
                            elseif ($n['tipe'] === 'revisi') $icon_n = 'bi-arrow-repeat text-danger';
                            elseif ($n['tipe'] === 'sukses') $icon_n = 'bi-check-circle-fill text-success';
                            $target_link = '?open_nid=' . $n['id'] . '&dest=' . urlencode($n['link_tujuan'] ?? 'index.php');
                        ?>
                            <li>
                                <a class="dropdown-item p-3 border-bottom <?= empty($n['is_read']) ? 'bg-light' : ''; ?> text-wrap" href="<?= $target_link; ?>">
                                    <div class="d-flex align-items-start gap-3">
                                        <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 bg-light mt-1" style="width: 32px; height: 32px;">
                                            <i class="bi <?= $icon_n; ?> fs-6"></i>
                                        </div>
                                        <div class="flex-grow-1 min-w-0">
                                            <div class="d-flex align-items-center justify-content-between gap-2">
                                                <strong class="small text-dark"><?= htmlspecialchars($n['judul']); ?></strong>
                                                <small class="text-muted" style="font-size: 0.68rem;"><?= date('d/m H:i', strtotime($n['created_at'])); ?></small>
                                            </div>
                                            <p class="mb-0 text-muted small" style="font-size: 0.78rem; line-height: 1.3;">
                                                <?= htmlspecialchars($n['pesan']); ?>
                                            </p>
                                        </div>
                                    </div>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
            <a href="profil" class="text-decoration-none d-inline-flex align-items-center gap-2 text-white p-1 rounded-pill navbar-user-pill flex-shrink-0" style="background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.22); transition: all 0.2s ease;" title="Buka Profil & Pengaturan Akun">
                <?php if (function_exists('render_avatar')): ?>
                    <span class="d-inline-flex rounded-circle p-0 flex-shrink-0" style="box-shadow: 0 0 0 2px rgba(255,255,255,0.4); width: 30px; height: 30px; min-width: 30px; min-height: 30px; aspect-ratio: 1 / 1; overflow: hidden;">
                        <?= render_avatar($_SESSION['foto_profil'] ?? '', $_SESSION['nama_lengkap'] ?? $_SESSION['username'] ?? 'User', 30, 'border-2 border-white shadow-sm', ($cur_role === 'admin' ? 'warning' : 'primary')); ?>
                    </span>
                <?php endif; ?>
                <div class="d-none d-lg-block text-truncate pe-1" style="max-width: 180px;">
                    <span class="fw-bold text-white small"><?= ($cur_role === 'admin' && !str_starts_with(strtolower(trim($_SESSION['nama_lengkap'] ?? $_SESSION['username'] ?? '')), 'admin') ? 'Admin ' : '') . htmlspecialchars($_SESSION['nama_lengkap'] ?? $_SESSION['username'] ?? 'User'); ?></span>
                </div>
            </a>
            <button type="button" class="btn btn-sm btn-outline-light px-2 px-sm-3 btn-logout-navbar d-none d-sm-inline-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#modalLogoutConfirm" title="Keluar dari Sistem">
                <i class="bi bi-box-arrow-right"></i><span class="d-none d-md-inline">Logout</span>
            </button>
            <!-- Switcher Mode Terang / Gelap (Light / Dark Mode Toggle) -->
            <button id="themeToggle" class="btn-theme-toggle ms-1" type="button" onclick="toggleAppTheme(event)" aria-label="Ganti Tema (Terang / Gelap)" title="Ganti Mode Tampilan (Terang / Gelap)">
                <i class="bi bi-sun-fill theme-icon-light"></i>
                <i class="bi bi-moon-stars-fill theme-icon-dark"></i>
            </button>
        </div>
    </div>
</nav>

<!-- MODAL MODERN KONFIRMASI LOGOUT -->
<div class="modal fade modal-modern-confirm" id="modalLogoutConfirm" tabindex="-1" aria-labelledby="modalLogoutConfirmLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 420px;">
        <div class="modal-content shadow-lg border-0 text-center p-4">
            <div class="modal-body p-0">
                <div class="confirm-icon-box logout-icon-box mx-auto mb-3">
                    <i class="bi bi-box-arrow-right fs-1"></i>
                </div>
                <h5 class="fw-bold mb-2 confirm-title" id="modalLogoutConfirmLabel">Konfirmasi Keluar Akun</h5>
                <p class="confirm-desc mb-4 px-2">
                    Apakah Anda yakin ingin mengakhiri sesi saat ini? Seluruh progres bimbingan dan perubahan data Anda telah tersimpan dengan aman di sistem.
                </p>
                <div class="d-flex justify-content-center gap-2">
                    <button type="button" class="btn btn-confirm-cancel px-4 py-2" data-bs-dismiss="modal">
                        Batal
                    </button>
                    <a href="../logout" class="btn btn-confirm-logout px-4 py-2">
                        <i class="bi bi-box-arrow-right me-1"></i> Ya, Keluar
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL UNIVERSAL KONFIRMASI MODERN (FALLBACK OFFLINE) -->
<div class="modal fade modal-modern-confirm" id="modalModernConfirm" tabindex="-1" aria-labelledby="modalModernConfirmLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 420px;">
        <div class="modal-content shadow-lg border-0 text-center p-4">
            <div class="modal-body p-0">
                <div class="confirm-icon-box default-icon-box mx-auto mb-3" id="modernConfirmIconBox">
                    <i class="bi bi-question-circle fs-1" id="modernConfirmIcon"></i>
                </div>
                <h5 class="fw-bold mb-2 confirm-title" id="modalModernConfirmLabel">Konfirmasi Tindakan</h5>
                <p class="confirm-desc mb-4 px-2" id="modernConfirmText">
                    Apakah Anda yakin ingin melanjutkan tindakan ini?
                </p>
                <div class="d-flex justify-content-center gap-2">
                    <button type="button" class="btn btn-confirm-cancel px-4 py-2" data-bs-dismiss="modal" id="modernConfirmCancelBtn">
                        Batal
                    </button>
                    <button type="button" class="btn btn-confirm-action px-4 py-2" id="modernConfirmOkBtn">
                        <i class="bi bi-check2-circle me-1"></i> Ya, Lanjutkan
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Backdrop untuk mobile saat sidebar dibuka -->
<div class="sidebar-mobile-backdrop" id="sidebarBackdrop" onclick="toggleSidebarMenu()"></div>
<!--  -->
<div class="container-fluid">
    <div class="row">
