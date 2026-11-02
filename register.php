<?php
// register.php - Halaman Registrasi Akun Baru (Mahasiswa / Dosen)
// Merujuk langsung ke sistem autentikasi terpadu dengan tab pendaftaran aktif
$_GET['tab'] = 'register';
require_once __DIR__ . '/login.php';

