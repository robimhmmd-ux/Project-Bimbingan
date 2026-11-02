<?php
// templates/header.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Proteksi login umum
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Pastikan koneksi database tersedia untuk sinkronisasi akun
if (!isset($pdo) && file_exists(__DIR__ . '/../config/database.php')) {
    require_once __DIR__ . '/../config/database.php';
}

$css_ver     = file_exists(__DIR__ . '/../assets/css/style.css') ? filemtime(__DIR__ . '/../assets/css/style.css') : time();
$cur_user_id = $_SESSION['user_id'];
$cur_role    = $_SESSION['role'] ?? '';

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
        header("Location: " . $clean_url);
        exit;
    }

    // Handle aksi klik notifikasi spesifik (tandai dibaca lalu redirect ke link tujuan)
    if (isset($_GET['open_nid']) && !empty($_GET['open_nid'])) {
        $nid = (int)$_GET['open_nid'];
        $dest = $_GET['dest'] ?? 'index.php';
        try {
            $pdo->prepare("UPDATE notifikasi SET is_read = 1 WHERE id = :nid AND user_id = :uid")->execute([':nid' => $nid, ':uid' => $cur_user_id]);
        } catch (PDOException $e) {}
        header("Location: " . $dest);
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

// Selalu sinkronkan username di session dengan database terkini
if (isset($pdo) && isset($_SESSION['user_id'])) {
    $stmt_sync_u = $pdo->prepare("SELECT username FROM users WHERE id = :uid LIMIT 1");
    $stmt_sync_u->execute([':uid' => $cur_user_id]);
    $live_u = $stmt_sync_u->fetchColumn();
    if ($live_u) {
        $_SESSION['username'] = $live_u;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($page_title) ? htmlspecialchars($page_title) : 'Sistem Bimbingan Skripsi'; ?></title>
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
    <style>
        /* =========================================================================
           JAMINAN MUTLAK: SIDEBAR TIDAK IKUT SCROLL & BISA DIBUKA-TUTUP (COLLAPSIBLE)
           ========================================================================= */
        :root {
            --topbar-h: 58px;
            --sidebar-w: 260px;
        }

        /* 1. Sidebar Diam di Tempat (Sticky di bawah navbar, scroll independen) */
        .sidebar, #sidebarMenu {
            position: sticky !important;
            top: var(--topbar-h, 58px) !important;
            height: calc(100vh - var(--topbar-h, 58px)) !important;
            max-height: calc(100vh - var(--topbar-h, 58px)) !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
            align-self: flex-start !important;
            background-color: #ffffff !important;
            border-right: 1px solid #e2e8f0 !important;
            z-index: 1020 !important;
            transition: all 0.2s ease !important;
        }

        .sidebar::-webkit-scrollbar {
            width: 4px;
        }
        .sidebar::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        /* 2. Desktop: Saat Sidebar Ditutup (Collapsed) */
        @media (min-width: 768px) {
            html.sidebar-collapsed .sidebar,
            body.sidebar-collapsed .sidebar,
            html.sidebar-collapsed #sidebarMenu,
            body.sidebar-collapsed #sidebarMenu {
                display: none !important;
                visibility: hidden !important;
                width: 0 !important;
                max-width: 0 !important;
                padding: 0 !important;
                margin: 0 !important;
                border: none !important;
            }

            html.sidebar-collapsed main,
            html.sidebar-collapsed .main-content-wrapper,
            html.sidebar-collapsed #mainContent,
            body.sidebar-collapsed main,
            body.sidebar-collapsed .main-content-wrapper,
            body.sidebar-collapsed #mainContent {
                flex: 0 0 100% !important;
                max-width: 100% !important;
                width: 100% !important;
                margin-left: 0 !important;
            }
        }

        /* 3. Mobile (< 768px): Drawer Menu */
        @media (max-width: 767.98px) {
            .sidebar, #sidebarMenu {
                display: none !important;
                position: fixed !important;
                top: var(--topbar-h, 58px) !important;
                left: 0 !important;
                bottom: 0 !important;
                width: var(--sidebar-w, 260px) !important;
                max-width: 85vw !important;
                height: calc(100vh - var(--topbar-h, 58px)) !important;
                z-index: 1045 !important;
            }

            body.sidebar-open-mobile .sidebar,
            body.sidebar-open-mobile #sidebarMenu {
                display: block !important;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4) !important;
            }

            .sidebar-mobile-backdrop {
                display: none;
                position: fixed;
                top: var(--topbar-h, 58px);
                left: 0; right: 0; bottom: 0;
                background: rgba(15, 23, 42, 0.55);
                backdrop-filter: blur(2px);
                z-index: 1040;
            }

            body.sidebar-open-mobile .sidebar-mobile-backdrop {
                display: block !important;
            }

            main, .main-content-wrapper, #mainContent {
                flex: 0 0 100% !important;
                max-width: 100% !important;
                width: 100% !important;
                margin-left: 0 !important;
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

        // Sinkronisasi ikon saat DOM siap
        document.addEventListener('DOMContentLoaded', function() {
            const isCollapsed = document.body.classList.contains('sidebar-collapsed') || document.documentElement.classList.contains('sidebar-collapsed');
            const icon = document.getElementById('sidebarToggleIcon');
            if (icon && window.innerWidth >= 768 && isCollapsed) {
                icon.className = 'bi bi-list fs-3 text-warning';
            }
        });
    </script>
</head>
<body class="bg-light">

<!-- Topbar Navbar (Solid High-Contrast Navy Blue) -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top shadow-sm py-2" style="background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%) !important;">
    <div class="container-fluid px-md-4">
        <!-- Tombol Buka/Tutup Sidebar & Brand Logo -->
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-outline-light border-0 p-1 px-2 btn-sidebar-toggle d-flex align-items-center justify-content-center shadow-none me-1" 
                    id="sidebarToggleBtn" 
                    type="button" 
                    onclick="toggleSidebarMenu()" 
                    aria-label="Toggle Sidebar" 
                    title="Buka / Tutup Sidebar"
                    style="background: rgba(255, 255, 255, 0.18); border-radius: 8px; cursor: pointer; min-width: 42px; min-height: 40px;">
                <i class="bi bi-list fs-3 text-white" id="sidebarToggleIcon"></i>
            </button>
            <a class="navbar-brand d-flex align-items-center gap-2 fw-bold text-white text-decoration-none m-0" href="#">
                <span class="p-1 px-2 bg-white bg-opacity-20 rounded-2 text-warning">
                    <i class="bi bi-mortarboard-fill"></i>
                </span>
                <div class="d-flex flex-column">
                    <span class="fs-6 lh-1">Bimbingan Skripsi</span>
                    <span class="text-white-50 small" style="font-size: 0.72rem; font-weight: normal;">Sistem Informasi Akademik</span>
                </div>
            </a>
        </div>

        <!-- Right Side User, Notifications & Status Pill -->
        <div class="d-flex align-items-center gap-3 text-white">
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
                <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-0" aria-labelledby="dropdownNotifikasi" style="min-width: 320px; max-width: 380px;">
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
                                    <div class="d-flex align-items-start gap-2">
                                        <i class="bi <?= $icon_n; ?> fs-5 mt-1 flex-shrink-0"></i>
                                        <div>
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
            <div class="d-inline-flex align-items-center gap-1 text-white">
                <span class="small d-none d-md-inline text-white-50">Halo,</span>
                <span class="fw-bold text-white"><?= htmlspecialchars($_SESSION['nama_lengkap'] ?? $_SESSION['username'] ?? 'User'); ?></span>
            </div>
            <a href="profil.php" class="btn btn-sm btn-outline-light d-none d-sm-inline-flex align-items-center gap-1" title="Buka Profil & Pengaturan Akun">
                <i class="bi bi-person-gear"></i><span class="d-none d-md-inline">Profil</span>
            </a>
            <a href="../logout.php" class="btn btn-sm btn-outline-light px-3" onclick="return confirm('Apakah Anda yakin ingin keluar?')" title="Logout">
                <i class="bi bi-box-arrow-right me-1"></i>Logout
            </a>
        </div>
    </div>
</nav>

<!-- Backdrop untuk mobile saat sidebar dibuka -->
<div class="sidebar-mobile-backdrop" id="sidebarBackdrop" onclick="toggleSidebarMenu()"></div>

<div class="container-fluid">
    <div class="row">
