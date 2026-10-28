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
    <!-- Custom CSS -->
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">

<!-- Topbar Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top shadow-sm">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="#">
            <i class="bi bi-mortarboard-fill me-2"></i>Bimbingan Skripsi
        </a>
        <div class="d-flex align-items-center text-white">
            <span class="me-3 small d-none d-md-inline">Halo, <strong><?= htmlspecialchars($_SESSION['username'] ?? 'User'); ?></strong> (<?= ucfirst($_SESSION['role'] ?? ''); ?>)</span>
            <a href="../logout.php" class="btn btn-outline-light btn-sm" onclick="return confirm('Yakin ingin logout?')">
                <i class="bi bi-box-arrow-right me-1"></i>Logout
            </a>
        </div>
    </div>
</nav>

<div class="container-fluid">
    <div class="row">
