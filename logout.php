<?php
// logout.php - Proses Keluar Sistem & Pembersihan Sesi Aman
require_once 'config/database.php';
if (file_exists('config/session_helper.php')) {
    require_once 'config/session_helper.php';
    init_app_session($pdo);
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION = [];
session_unset();
session_destroy();

header("Location: login.php");
exit;
