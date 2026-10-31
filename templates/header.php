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

$user_initial = strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1));
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
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-surface-app">

<!-- Modern Frosted Glass Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-app-navbar sticky-top shadow-sm py-2">
    <div class="container-fluid px-md-4">
        <!-- Logo & Brand Title -->
        <a class="navbar-brand d-flex align-items-center gap-2 text-decoration-none" href="#">
            <div class="brand-icon-box">
                <i class="bi bi-mortarboard-fill text-white fs-5"></i>
            </div>
            <div class="d-flex flex-column">
                <span class="fw-bold tracking-tight text-white fs-6 lh-sm">SIM-Bimbingan</span>
                <span class="text-white-50 small lh-1" style="font-size: 0.725rem;">Portal Skripsi Akademik</span>
            </div>
        </a>

        <!-- Right Side User & Status Pill -->
        <div class="d-flex align-items-center gap-3">
            <!-- Semester Badge -->
            <div class="d-none d-lg-flex align-items-center px-3 py-1 rounded-pill bg-white bg-opacity-10 text-white border border-white border-opacity-10 small">
                <i class="bi bi-calendar-check text-info me-2"></i>
                <span>T.A. <?= date('Y'); ?>/<?= date('Y')+1; ?> • Ganjil</span>
            </div>

            <!-- User Profile Chip -->
            <div class="user-profile-chip d-flex align-items-center gap-2 ps-2 pe-3 py-1 rounded-pill bg-white bg-opacity-10 border border-white border-opacity-10">
                <div class="avatar-circle">
                    <?= $user_initial; ?>
                </div>
                <div class="d-none d-sm-flex flex-column text-start">
                    <span class="text-white fw-bold small lh-1"><?= htmlspecialchars($_SESSION['username'] ?? 'User'); ?></span>
                    <span class="user-role-badge mt-1">
                        <span class="status-dot"></span>
                        <?= ucfirst($_SESSION['role'] ?? ''); ?>
                    </span>
                </div>
            </div>

            <!-- Logout Button -->
            <a href="../logout.php" class="btn btn-sm btn-logout" onclick="return confirm('Apakah Anda yakin ingin keluar dari sistem?')" title="Logout">
                <i class="bi bi-box-arrow-right"></i>
                <span class="d-none d-md-inline ms-1">Keluar</span>
            </a>
        </div>
    </div>
</nav>

<div class="container-fluid">
    <div class="row">
