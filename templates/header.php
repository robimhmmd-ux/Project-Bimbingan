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

$css_ver = file_exists(__DIR__ . '/../assets/css/style.css') ? filemtime(__DIR__ . '/../assets/css/style.css') : time();
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
</head>
<body class="bg-light">

<!-- Topbar Navbar (Solid High-Contrast Navy Blue) -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top shadow-sm py-2" style="background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%) !important;">
    <div class="container-fluid px-md-4">
        <!-- Logo & Brand Title -->
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold text-white text-decoration-none" href="#">
            <span class="p-1 px-2 bg-white bg-opacity-20 rounded-2 text-warning">
                <i class="bi bi-mortarboard-fill"></i>
            </span>
            <div class="d-flex flex-column">
                <span class="fs-6 lh-1">Bimbingan Skripsi</span>
                <span class="text-white-50 small" style="font-size: 0.72rem; font-weight: normal;">Sistem Informasi Akademik</span>
            </div>
        </a>

        <!-- Right Side User & Status Pill -->
        <div class="d-flex align-items-center gap-3 text-white">
            <span class="small d-none d-md-inline text-white">
                Halo, <strong><?= htmlspecialchars($_SESSION['username'] ?? 'User'); ?></strong><?= (isset($_SESSION['role']) && $_SESSION['role'] !== 'mahasiswa') ? ' (' . ucfirst($_SESSION['role']) . ')' : ''; ?>
            </span>
            <a href="../logout.php" class="btn btn-sm btn-outline-light px-3" onclick="return confirm('Apakah Anda yakin ingin keluar?')" title="Logout">
                <i class="bi bi-box-arrow-right me-1"></i>Logout
            </a>
        </div>
    </div>
</nav>

<div class="container-fluid">
    <div class="row">
