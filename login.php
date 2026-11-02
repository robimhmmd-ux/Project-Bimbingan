<?php
// login.php - Autentikasi Pengguna & Pendaftaran Akun Multi-Role
session_start();

if (isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'admin')         header("Location: admin/index.php");
    elseif ($_SESSION['role'] === 'dosen')     header("Location: dosen/index.php");
    elseif ($_SESSION['role'] === 'mahasiswa') header("Location: mahasiswa/index.php");
    exit;
}

$error_message = '';
$success_message = '';
$active_tab = (isset($_GET['tab']) && $_GET['tab'] === 'register') ? 'register' : 'login';
$login_username = '';

// Variabel form pendaftaran untuk preserve input jika terjadi error
$reg_role = 'mahasiswa';
$reg_nama = '';
$reg_username = '';
$reg_identifier = '';
$reg_kelas = 'TI-1A-2025';
$reg_email = '';
$reg_wa = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'config/database.php';
    $action = $_POST['action'] ?? 'login';

    if ($action === 'register') {
        $active_tab = 'register';
        $reg_role = trim($_POST['reg_role'] ?? 'mahasiswa');
        $reg_nama = trim($_POST['reg_nama'] ?? '');
        $reg_username = trim($_POST['reg_username'] ?? '');
        $reg_identifier = trim($_POST['reg_identifier'] ?? '');
        $reg_kelas = trim($_POST['reg_kelas'] ?? 'TI-1A-2025');
        $reg_email = trim($_POST['reg_email'] ?? '');
        $reg_wa = trim($_POST['reg_wa'] ?? '');
        $reg_password = $_POST['reg_password'] ?? '';
        $reg_password_confirm = $_POST['reg_password_confirm'] ?? '';

        // Validasi input pendaftaran
        if (!in_array($reg_role, ['mahasiswa', 'dosen'])) {
            $error_message = 'Pilihan peran pengguna tidak valid.';
        } elseif (empty($reg_nama)) {
            $error_message = 'Nama lengkap wajib diisi.';
        } elseif (empty($reg_username) || strlen($reg_username) < 3) {
            $error_message = 'Username minimal terdiri dari 3 karakter.';
        } elseif (preg_match('/\s/', $reg_username)) {
            $error_message = 'Username tidak boleh mengandung spasi.';
        } elseif (empty($reg_identifier)) {
            $error_message = ($reg_role === 'dosen' ? 'NIDN' : 'NIM') . ' wajib diisi.';
        } elseif ($reg_role === 'dosen' && (empty($reg_email) || !filter_var($reg_email, FILTER_VALIDATE_EMAIL))) {
            $error_message = 'Alamat email dosen tidak valid.';
        } elseif (empty($reg_password) || strlen($reg_password) < 6) {
            $error_message = 'Password minimal terdiri dari 6 karakter.';
        } elseif ($reg_password !== $reg_password_confirm) {
            $error_message = 'Konfirmasi password tidak cocok.';
        } else {
            try {
                // 1. Cek duplikasi username
                $stmtCheckUser = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(:u) LIMIT 1");
                $stmtCheckUser->execute([':u' => $reg_username]);
                if ($stmtCheckUser->fetch()) {
                    $error_message = 'Username "' . htmlspecialchars($reg_username) . '" sudah digunakan. Silakan pilih username lain.';
                } else {
                    if ($reg_role === 'mahasiswa') {
                        // 2. Cek duplikasi NIM
                        $stmtCheckNim = $pdo->prepare("SELECT id FROM mahasiswa WHERE nim = :nim LIMIT 1");
                        $stmtCheckNim->execute([':nim' => $reg_identifier]);
                        if ($stmtCheckNim->fetch()) {
                            $error_message = 'NIM ' . htmlspecialchars($reg_identifier) . ' sudah terdaftar dalam sistem.';
                        }
                    } else {
                        // 3. Cek duplikasi NIDN & Email Dosen
                        $stmtCheckNidn = $pdo->prepare("SELECT id FROM dosen WHERE nidn = :nidn LIMIT 1");
                        $stmtCheckNidn->execute([':nidn' => $reg_identifier]);
                        if ($stmtCheckNidn->fetch()) {
                            $error_message = 'NIDN ' . htmlspecialchars($reg_identifier) . ' sudah terdaftar dalam sistem.';
                        } else {
                            $stmtCheckEmail = $pdo->prepare("SELECT id FROM dosen WHERE LOWER(email) = LOWER(:email) LIMIT 1");
                            $stmtCheckEmail->execute([':email' => $reg_email]);
                            if ($stmtCheckEmail->fetch()) {
                                $error_message = 'Email ' . htmlspecialchars($reg_email) . ' sudah digunakan oleh dosen lain.';
                            }
                        }
                    }

                    // Jika tidak ada error, eksekusi transaksi pendaftaran
                    if (empty($error_message)) {
                        $pdo->beginTransaction();

                        $hash = password_hash($reg_password, PASSWORD_BCRYPT);
                        $stmtUser = $pdo->prepare("INSERT INTO users (username, password, password_plain, role) VALUES (:u, :p, :pp, :r)");
                        $stmtUser->execute([
                            ':u'  => $reg_username,
                            ':p'  => $hash,
                            ':pp' => $reg_password,
                            ':r'  => $reg_role
                        ]);
                        $newUserId = (int)$pdo->lastInsertId();

                        if ($reg_role === 'mahasiswa') {
                            $stmtMhs = $pdo->prepare("INSERT INTO mahasiswa 
                                (user_id, nim, nama_lengkap, kelas, judul_skripsi, status_pembimbing, status_kelulusan, foto_profil) 
                                VALUES (:uid, :nim, :nama, :kelas, 'Belum Ditentukan', 'belum_diplot', 'proses_bimbingan', 'default.png')");
                            $stmtMhs->execute([
                                ':uid'   => $newUserId,
                                ':nim'   => $reg_identifier,
                                ':nama'  => $reg_nama,
                                ':kelas' => $reg_kelas
                            ]);
                        } else {
                            $stmtDsn = $pdo->prepare("INSERT INTO dosen 
                                (user_id, nidn, nama_dosen, email, no_whatsapp, foto_profil, kuota_bimbingan) 
                                VALUES (:uid, :nidn, :nama, :email, :wa, 'default.png', 10)");
                            $stmtDsn->execute([
                                ':uid'   => $newUserId,
                                ':nidn'  => $reg_identifier,
                                ':nama'  => $reg_nama,
                                ':email' => $reg_email,
                                ':wa'    => !empty($reg_wa) ? $reg_wa : null
                            ]);
                        }

                        $pdo->commit();

                        $success_message = 'Pendaftaran berhasil! Akun Anda telah siap. Silakan masuk.';
                        $active_tab = 'login';
                        $login_username = $reg_username;

                        // Reset input form
                        $reg_nama = '';
                        $reg_username = '';
                        $reg_identifier = '';
                        $reg_email = '';
                        $reg_wa = '';
                    }
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error_message = 'Terjadi kesalahan sistem saat mendaftar: ' . $e->getMessage();
            }
        }
    } else {
        // Mode Login
        $active_tab = 'login';
        $login_username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if (!empty($login_username) && !empty($password)) {
            try {
                $stmt = $pdo->prepare("SELECT id, username, password, role FROM users WHERE username = :username LIMIT 1");
                $stmt->execute([':username' => $login_username]);
                $user = $stmt->fetch();
                if ($user && password_verify($password, $user['password'])) {
                    $_SESSION['user_id']  = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role']     = $user['role'];

                    // Load nama lengkap & foto profil ke session
                    try {
                        if ($user['role'] === 'admin') {
                            $stmt_adm = $pdo->prepare("SELECT nama_lengkap, foto_profil FROM admin_profil WHERE user_id = :uid LIMIT 1");
                            $stmt_adm->execute([':uid' => $user['id']]);
                            $adm_data = $stmt_adm->fetch();
                            if ($adm_data) {
                                $_SESSION['nama_lengkap'] = $adm_data['nama_lengkap'];
                                $_SESSION['foto_profil']  = $adm_data['foto_profil'];
                            }
                        } elseif ($user['role'] === 'dosen') {
                            $stmt_dsn = $pdo->prepare("SELECT nama_dosen, foto_profil FROM dosen WHERE user_id = :uid LIMIT 1");
                            $stmt_dsn->execute([':uid' => $user['id']]);
                            $dsn_data = $stmt_dsn->fetch();
                            if ($dsn_data) {
                                $_SESSION['nama_lengkap'] = $dsn_data['nama_dosen'];
                                $_SESSION['foto_profil']  = $dsn_data['foto_profil'];
                            }
                        } elseif ($user['role'] === 'mahasiswa') {
                            $stmt_mhs = $pdo->prepare("SELECT nama_lengkap, foto_profil FROM mahasiswa WHERE user_id = :uid LIMIT 1");
                            $stmt_mhs->execute([':uid' => $user['id']]);
                            $mhs_data = $stmt_mhs->fetch();
                            if ($mhs_data) {
                                $_SESSION['nama_lengkap'] = $mhs_data['nama_lengkap'];
                                $_SESSION['foto_profil']  = $mhs_data['foto_profil'];
                            }
                        }
                    } catch (Exception $e) {
                        // Abaikan jika query profil gagal
                    }

                    if ($user['role'] === 'admin')         header("Location: admin/index.php");
                    elseif ($user['role'] === 'dosen')     header("Location: dosen/index.php");
                    elseif ($user['role'] === 'mahasiswa') header("Location: mahasiswa/index.php");
                    exit;
                } else {
                    $error_message = 'Username atau password yang Anda masukkan salah.';
                }
            } catch (PDOException $e) {
                $error_message = 'Terjadi kesalahan sistem. Silakan coba lagi.';
            }
        } else {
            $error_message = 'Mohon isi username dan password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title id="pageTitle"><?= $active_tab === 'register' ? 'Daftar Akun' : 'Masuk' ?> — Bimbingan Skripsi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary:          #4338ca;
            --primary-hover:    #3730a3;
            --primary-gradient: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%);
            --panel-gradient:   linear-gradient(160deg, #0f172a 0%, #1e1b4b 45%, #312e81 100%);
            --surface:          #ffffff;
            --background:       #f8fafc;
            --text-main:        #0f172a;
            --text-muted:       #64748b;
            --border-color:     #e2e8f0;
            --border-focus:     #818cf8;
            --radius-pill:      9999px;
            --radius-lg:        1.25rem;

            /* Warna karakter wisudawan */
            --toga-black:  #1a1a2a;
            --toga-gold:   #f59e0b;
            --toga-red:    #dc2626;
            --toga-navy:   #1e2a4a;
            --skin:        #f5c5a3;
            --skin-dark:   #e8a882;
        }

        *, *::before, *::after { box-sizing: border-box; }

        body {
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
            background-color: var(--background);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            -webkit-font-smoothing: antialiased;
        }

        /* ======== WRAPPER ======== */
        .login-wrapper {
            display: flex;
            width: 100%;
            max-width: 950px;
            min-height: 580px;
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow:
                0 25px 60px -12px rgba(15, 23, 42, 0.30),
                0 8px 24px -4px rgba(67, 56, 202, 0.18);
        }

        /* ======== PANEL KIRI ======== */
        .login-visual {
            flex: 0 0 44%;
            background: var(--panel-gradient);
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
            padding: 2.25rem 1.5rem 2rem;
            overflow: hidden;
        }

        /* Dekorasi background */
        .login-visual::before {
            content: '';
            position: absolute;
            top: -100px; right: -100px;
            width: 320px; height: 320px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(99,102,241,0.12) 0%, transparent 65%);
        }
        .login-visual::after {
            content: '';
            position: absolute;
            bottom: -80px; left: -80px;
            width: 260px; height: 260px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(79,70,229,0.18) 0%, transparent 65%);
        }

        /* ======== LOGO AREA (TOPI + NAMA WEB DI SAMPINGNYA) ======== */
        .web-logo-area {
            position: relative;
            z-index: 3;
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: 1rem;
            width: 100%;
            justify-content: center;
            padding: 0 0.5rem;
        }

        .web-logo-area .site-name {
            font-size: 1.45rem;
            font-weight: 800;
            color: #ffffff;
            letter-spacing: -0.03em;
            line-height: 1.15;
            margin: 0;
            text-shadow: 0 2px 10px rgba(0,0,0,0.25);
        }

        .web-logo-area .site-tagline {
            font-size: 0.72rem;
            color: rgba(255,255,255,0.6);
            font-weight: 500;
            margin: 0.15rem 0 0;
            letter-spacing: 0.02em;
        }

        /* ======== KARAKTER WISUDAWAN ======== */
        .character-wrap {
            position: relative;
            z-index: 2;
            flex: 1;
            display: flex;
            align-items: flex-end;
            justify-content: center;
        }

        /* Animasi: float tubuh naik-turun */
        @keyframes float-body {
            0%, 100% { transform: translateY(0); }
            50%       { transform: translateY(-8px); }
        }

        /* Animasi: tangan kanan mengarahkan tongkat penunjuk */
        @keyframes point-arm {
            0%   { transform: rotate(0deg); }
            35%  { transform: rotate(-7deg); }
            70%  { transform: rotate(3deg); }
            100% { transform: rotate(0deg); }
        }

        /* Dekorasi bintang melayang */
        @keyframes sparkle {
            0%, 100% { opacity: 0.35; transform: scale(0.85) rotate(0deg); }
            50%       { opacity: 1;    transform: scale(1.2) rotate(20deg); }
        }

        .g-float   { animation: float-body 4.5s ease-in-out infinite; transform-origin: center bottom; }
        .g-arm     { animation: point-arm 3.5s ease-in-out infinite; transform-origin: 186px 148px; }
        .g-star-1  { animation: sparkle 3s ease-in-out infinite; }
        .g-star-2  { animation: sparkle 3s ease-in-out infinite 1.2s; }
        .g-star-3  { animation: sparkle 3s ease-in-out infinite 2.1s; }

        /* Badge Portal Bawah Panel Kiri */
        .visual-caption {
            position: relative;
            z-index: 3;
            text-align: center;
            padding-top: 0.5rem;
        }

        .portal-badge {
            display: inline-block;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: rgba(255,255,255,0.75);
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.12);
            padding: 0.35rem 1.1rem;
            border-radius: 9999px;
            backdrop-filter: blur(6px);
        }

        /* ======== PANEL KANAN (FORM) ======== */
        .login-form-panel {
            flex: 1;
            background: var(--surface);
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 2.25rem 2.25rem;
            max-height: 94vh;
            overflow-y: auto;
        }

        .login-form-panel::-webkit-scrollbar {
            width: 5px;
        }
        .login-form-panel::-webkit-scrollbar-thumb {
            background-color: #e2e8f0;
            border-radius: 9999px;
        }

        /* Auth Switcher Tabs */
        .auth-switcher {
            display: flex;
            background: #f1f5f9;
            padding: 0.3rem;
            border-radius: var(--radius-pill);
            gap: 0.35rem;
            border: 1px solid #e2e8f0;
            margin-bottom: 1.5rem;
        }
        .auth-switch-btn {
            flex: 1;
            border: none;
            background: transparent;
            padding: 0.58rem 1rem;
            font-size: 0.88rem;
            font-weight: 700;
            color: #64748b;
            border-radius: var(--radius-pill);
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
        }
        .auth-switch-btn:hover {
            color: var(--primary);
        }
        .auth-switch-btn.active {
            background: #ffffff;
            color: var(--primary);
            box-shadow: 0 3px 10px rgba(67, 56, 202, 0.14);
        }

        /* Role Selector Pill */
        .role-selector-pill {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
        }
        .role-option {
            cursor: pointer;
            margin: 0;
        }
        .role-option input[type="radio"] {
            display: none;
        }
        .role-box {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
            padding: 0.55rem 0.75rem;
            border-radius: var(--radius-pill);
            border: 1.5px solid var(--border-color);
            background: #ffffff;
            font-size: 0.82rem;
            font-weight: 700;
            color: #475569;
            transition: all 0.2s ease;
        }
        .role-box i {
            font-size: 0.95rem;
            color: #94a3b8;
            transition: color 0.2s ease;
        }
        .role-option input[type="radio"]:checked + .role-box {
            border-color: var(--primary);
            background: #eef2ff;
            color: var(--primary);
            box-shadow: 0 2px 8px rgba(79, 70, 229, 0.15);
        }
        .role-option input[type="radio"]:checked + .role-box i {
            color: var(--primary);
        }

        /* Form Select Pill */
        .form-select-custom {
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%2364748b' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 1.25rem center;
            background-size: 14px 10px;
            padding-right: 2.5rem !important;
            cursor: pointer;
        }

        /* Alert Success Custom */
        .alert-success-custom {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 0.75rem;
            color: #166534;
            font-size: 0.85rem;
            padding: 0.75rem 1rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            margin-bottom: 1.25rem;
            box-shadow: 0 2px 6px rgba(34, 197, 94, 0.08);
        }

        .login-form-panel .brand-row {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.75rem;
        }

        .brand-icon-box {
            width: 42px; height: 42px;
            border-radius: 0.75rem;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(79,70,229,0.35);
            flex-shrink: 0;
        }

        .login-form-panel h2 {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: -0.03em;
            margin-bottom: 0.3rem;
        }

        .subtitle { font-size: 0.875rem; color: var(--text-muted); margin-bottom: 1.5rem; }

        /* Input pill */
        .input-pill {
            border-radius: var(--radius-pill) !important;
            border: 1.5px solid var(--border-color);
            padding: 0.72rem 1.25rem 0.72rem 2.6rem !important;
            font-size: 0.9rem;
            color: var(--text-main);
            width: 100%;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .input-pill:focus {
            border-color: var(--border-focus);
            box-shadow: 0 0 0 3px rgba(99,102,241,0.15);
            outline: none;
        }
        .input-pill.error { border-color: #ef4444; }

        .input-icon-wrap { position: relative; }
        .input-icon-wrap .field-icon {
            position: absolute;
            left: 1rem;
            top: 50%; transform: translateY(-50%);
            color: #94a3b8;
            font-size: 0.95rem;
            pointer-events: none;
        }

        .btn-toggle-eye {
            position: absolute;
            right: 1.15rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 0;
            font-size: 1.05rem;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 5;
            transition: color 0.15s ease;
        }
        .btn-toggle-eye:hover {
            color: var(--primary);
        }

        .form-label-custom {
            font-size: 0.85rem; font-weight: 600; color: #334155;
            margin-bottom: 0.4rem; display: block;
        }

        /* Tombol login */
        .btn-login {
            background: var(--primary-gradient);
            border: none;
            border-radius: var(--radius-pill);
            color: #ffffff;
            font-weight: 700;
            font-size: 0.9rem;
            padding: 0.78rem 1.5rem;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
            box-shadow: 0 4px 16px rgba(79,70,229,0.38);
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
        }
        .btn-login:hover {
            background: linear-gradient(135deg, #4338ca 0%, #312e81 100%);
            box-shadow: 0 6px 20px rgba(79,70,229,0.48);
            transform: translateY(-2px);
            color: #ffffff;
        }
        .btn-login:active { transform: translateY(0); }

        /* Alert error */
        .alert-error {
            background: #fef2f2; border: 1px solid #fecaca;
            border-radius: 0.75rem; color: #991b1b;
            font-size: 0.85rem; padding: 0.75rem 1rem;
            display: flex; align-items: center; gap: 0.5rem;
            margin-bottom: 1.25rem;
        }

        /* Hint box */
        .hint-box {
            margin-top: 1.75rem;
            padding: 0.9rem 1.1rem;
            border: 1px solid var(--border-color);
            border-radius: 0.75rem;
            background: var(--background);
        }
        .hint-title {
            font-size: 0.7rem; font-weight: 700; color: #94a3b8;
            text-transform: uppercase; letter-spacing: 0.07em; margin-bottom: 0.5rem;
        }
        .hint-row { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem; }
        .role-badge {
            font-size: 0.68rem; font-weight: 700;
            padding: 0.15rem 0.55rem; border-radius: 9999px; white-space: nowrap;
        }
        .b-admin  { background: #ede9fe; color: #5b21b6; }
        .b-dosen  { background: #e0f2fe; color: #075985; }
        .b-mhs    { background: #d1fae5; color: #065f46; }
        .hint-cred { font-size: 0.775rem; color: #475569; font-family: 'Courier New', monospace; }

        .login-footer {
            text-align: center; font-size: 0.765rem; color: #94a3b8; margin-top: 1.5rem;
        }

        @media (max-width: 680px) {
            .login-visual { display: none; }
            .login-form-panel { padding: 2.5rem 1.75rem; }
        }
    </style>
</head>
<body>

<!-- Tombol Cepat Kembali ke Beranda -->
<a href="index.php" class="btn btn-sm btn-light border rounded-pill shadow-sm position-fixed top-0 start-0 m-3 d-inline-flex align-items-center gap-2 text-dark text-decoration-none" style="z-index: 1050; font-weight: 600; padding: 0.45rem 1rem;">
    <i class="bi bi-arrow-left"></i> Kembali ke Beranda
</a>

<div class="login-wrapper">

    <!-- ============================================================
         PANEL KIRI — LOGO + KARAKTER WISUDAWAN + POSE GURU
    ============================================================ -->
    <div class="login-visual">

        <!-- Logo Topi Toga + Nama Web di Sampingnya (Horizontal, Font Tebal & Elegan) -->
        <div class="web-logo-area">
            <!-- Logo Topi Toga (Elegan & Tajam) -->
            <svg viewBox="0 0 110 80" width="62" height="48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-label="Logo Toga">
                <!-- Silinder dasar toga -->
                <path d="M28 44 L28 62 C28 67 40 71 55 71 C70 71 82 67 82 62 L82 44 Z" fill="#141c2e"/>
                <!-- Lis pita emas silinder -->
                <path d="M28 58 C35 63 45 65 55 65 C65 65 75 63 82 58 L82 62 C82 67 70 71 55 71 C40 71 28 67 28 62 Z" fill="#d97706"/>
                <!-- Papan atas belah ketupat perspektif 3D -->
                <path d="M6 34 L55 18 L104 34 L55 48 Z" fill="#1e293b"/>
                <path d="M6 34 L55 48 L55 53 L6 39 Z" fill="#0f172a"/>
                <path d="M104 34 L55 48 L55 53 L104 39 Z" fill="#090d16"/>
                <!-- Highlight atas papan -->
                <polygon points="9,34 55,20 101,34 55,46" fill="#293548"/>
                <!-- Knob emas tengah -->
                <ellipse cx="55" cy="34" rx="5" ry="3.5" fill="#d97706"/>
                <ellipse cx="55" cy="34" rx="3.5" ry="2.2" fill="#f59e0b"/>
                <circle cx="55" cy="33.5" r="1.2" fill="#fef08a"/>
                <!-- Tali tassel menjulur ke kiri -->
                <path d="M51 34 Q32 32 20 38 Q13 42 10 50" stroke="#f59e0b" stroke-width="2.2" stroke-linecap="round" fill="none"/>
                <!-- Rumbai tassel emas di kiri -->
                <ellipse cx="9.5" cy="52" rx="3.5" ry="2" fill="#b45309"/>
                <path d="M7 53 L4 72 M9 53 L7 74 M11 53 L10 74 M13 53 L13 72" stroke="#f59e0b" stroke-width="1.8" stroke-linecap="round"/>
            </svg>

            <div>
                <h1 class="site-name">Bimbingan Skripsi</h1>
                <p class="site-tagline">Sistem Informasi Akademik</p>
            </div>
        </div><!-- /web-logo-area -->

        <!-- Karakter Wisudawan — Pose Guru Natural (Buku di Dada Kiri, Tongkat Teracung Kanan Atas, Faceless Pria) -->
        <div class="character-wrap">
            <svg viewBox="0 0 280 430" fill="none" xmlns="http://www.w3.org/2000/svg"
                 width="225" aria-hidden="true">

                <!-- Bayangan lantai lembut -->
                <ellipse cx="135" cy="414" rx="68" ry="7" fill="rgba(0,0,0,0.22)"/>

                <!-- ═══════════════════════════════════════
                     GRUP FLOAT — Seluruh tubuh naik-turun lembut
                ═══════════════════════════════════════ -->
                <g class="g-float">

                    <!-- ── KAKI & SEPATU FORMAL ── -->
                    <!-- Celana panjang formal navy gelap -->
                    <rect x="105" y="320" width="23" height="74" rx="5" fill="#1e293b"/>
                    <rect x="142" y="320" width="23" height="74" rx="5" fill="#1e293b"/>
                    <path d="M105 390 L128 390" stroke="#0f172a" stroke-width="2"/>
                    <path d="M142 390 L165 390" stroke="#0f172a" stroke-width="2"/>

                    <!-- Sepatu pantofel hitam formal kiri -->
                    <path d="M100 393 C100 387 115 387 125 387 C130 387 132 392 132 397 C132 403 125 406 103 406 C97 403 100 397 100 393 Z" fill="#090d16"/>
                    <path d="M98 402 C114 406 133 402 133 402 L133 405 C114 408 98 405 98 402 Z" fill="#020617"/>
                    <ellipse cx="114" cy="393" rx="6" ry="2" fill="#334155" opacity="0.6"/>

                    <!-- Sepatu pantofel hitam formal kanan -->
                    <path d="M138 397 C138 392 140 387 145 387 C155 387 170 387 170 393 C170 397 173 403 167 406 C146 406 138 403 138 397 Z" fill="#090d16"/>
                    <path d="M137 402 C155 402 172 406 172 402 L172 405 C155 405 137 408 137 402 Z" fill="#020617"/>
                    <ellipse cx="156" cy="393" rx="6" ry="2" fill="#334155" opacity="0.6"/>

                    <!-- ── JUBAH TOGA HITAM (AKADEMIK) ── -->
                    <!-- Siluet jubah jatuh elegan melebar ke bawah -->
                    <path d="M80 148 C66 188 58 248 64 340 Q135 352 206 340 C212 248 204 188 190 148 Z" fill="#0f172a"/>
                    <!-- Panel depan kiri jubah -->
                    <path d="M80 148 C66 188 60 258 66 340 L133 344 L133 152 Z" fill="#181f2f"/>
                    <!-- Panel depan kanan jubah -->
                    <path d="M190 148 C204 188 210 258 204 340 L137 344 L137 152 Z" fill="#131926"/>
                    <!-- Lis pita emas vertikal bukaan jubah wisuda -->
                    <rect x="133" y="162" width="4" height="182" fill="#d97706"/>
                    <rect x="130" y="176" width="2" height="168" fill="#f59e0b"/>
                    <rect x="138" y="176" width="2" height="168" fill="#f59e0b"/>
                    <!-- Lipatan kain & drape highlights -->
                    <path d="M90 172 C84 218 84 278 88 336" stroke="rgba(255,255,255,0.06)" stroke-width="2" fill="none"/>
                    <path d="M180 172 C186 218 186 278 182 336" stroke="rgba(255,255,255,0.06)" stroke-width="2" fill="none"/>

                    <!-- ── SAMIR / TERATAI WISUDA INDONESIA ──
                         Kuning emas dengan lis pita merah di pinggir, menutupi bahu & dada -->
                    <path d="M74 148 C74 140 100 134 135 134 C170 134 196 140 196 148 C196 168 182 194 157 204 L135 210 L113 204 C88 194 74 168 74 148 Z" fill="#f59e0b"/>
                    <!-- Lis merah di tepi luar samir -->
                    <path d="M78 152 C82 170 94 190 115 200 L135 205 L155 200 C176 190 188 170 192 152 C184 164 172 184 151 193 L135 197 L119 193 C98 184 86 164 78 152 Z" fill="#dc2626"/>
                    <!-- Lis merah di lingkar leher samir -->
                    <path d="M98 140 C110 146 122 148 135 148 C148 148 160 146 172 140 C160 144 148 145 135 145 C122 145 110 144 98 140 Z" fill="#dc2626"/>
                    <!-- Kerah kemeja putih di dalam leher -->
                    <path d="M116 130 L125 142 L135 135 L145 142 L154 130 Q135 134 116 130 Z" fill="#f8fafc"/>
                    <!-- Dasi biru gelap formal -->
                    <path d="M132 136 L138 136 L136 149 L134 149 Z" fill="#1e3a8a"/>

                    <!-- Gordon / Medali Emas Wisuda di ujung samir -->
                    <path d="M131 204 L131 213 L139 213 L139 204 Z" fill="#b45309"/>
                    <circle cx="135" cy="222" r="10" fill="#d97706"/>
                    <circle cx="135" cy="222" r="8" fill="#f59e0b"/>
                    <circle cx="135" cy="222" r="6" fill="#fbbf24"/>
                    <polygon points="135,217 137,221 141,221 138,223 139,227 135,225 131,227 132,223 129,221 133,221" fill="#b45309"/>

                    <!-- ── LENGAN KIRI & BUKU — POSE GURU NATURAL ──
                         Buku dipeluk erat di pinggang kiri, tangan kiri melingkari buku secara anatomis wajar -->
                    <!-- Lengan atas & bahu kiri jubah toga mengalir alami -->
                    <path d="M78 148 C68 165 58 185 60 216 C68 222 80 220 86 210 C86 195 86 175 90 156 Z" fill="#111827"/>
                    <!-- Lipatan kain lengan dalam jubah -->
                    <path d="M60 212 C68 216 76 214 82 208 C78 210 70 210 63 206 Z" fill="#030712"/>

                    <!-- BUKU TEBAL SKRIPSI HIJAU ZAMRUD (TERPELUK DI PINGGANG KIRI) -->
                    <!-- Bayangan buku pada tubuh -->
                    <rect x="46" y="168" width="46" height="64" rx="4" fill="rgba(0,0,0,0.25)"/>
                    <!-- Sampul hijau zamrud tebal -->
                    <rect x="44" y="166" width="46" height="64" rx="4" fill="#047857"/>
                    <!-- Punggung buku tebal (spine) -->
                    <rect x="44" y="166" width="10" height="64" rx="3" fill="#065f46"/>
                    <!-- Blok lembar kertas putih bersih di sisi kanan buku -->
                    <rect x="84" y="168" width="6" height="60" rx="1" fill="#f8fafc"/>
                    <line x1="86" y1="172" x2="86" y2="224" stroke="#cbd5e1" stroke-width="1"/>
                    <line x1="88" y1="172" x2="88" y2="224" stroke="#cbd5e1" stroke-width="1"/>
                    <!-- Pita pembatas emas menjuntai di bawah buku -->
                    <path d="M50 230 Q54 242 52 248 L56 246 L60 248 Q58 242 54 230 Z" fill="#f59e0b"/>
                    <!-- Tulisan timbul / ornamen emas di sampul buku -->
                    <rect x="58" y="178" width="20" height="16" rx="2" fill="#10b981" opacity="0.35"/>
                    <line x1="58" y1="200" x2="78" y2="200" stroke="rgba(255,255,255,0.4)" stroke-width="1.5"/>
                    <line x1="58" y1="206" x2="74" y2="206" stroke="rgba(255,255,255,0.3)" stroke-width="1.5"/>

                    <!-- LENGAN BAWAH & TANGAN KIRI NATURAL (MEMEGANG SISI & DEPAN BUKU) -->
                    <!-- Lengan bawah melengkung luwes dari siku ke depan buku -->
                    <path d="M68 214 C74 216 84 214 96 206" stroke="#f5c5a3" stroke-width="14" stroke-linecap="round" fill="none"/>
                    <!-- Pergelangan tangan -->
                    <ellipse cx="88" cy="207" rx="8" ry="9" fill="#f5c5a3"/>
                    <!-- Ibu jari menempel di sampul depan buku -->
                    <path d="M78 196 C82 195 86 198 87 202 C87 205 84 207 80 205 Z" fill="#f5c5a3"/>
                    <!-- 4 Jari melengkung alami menopang tepi kanan buku -->
                    <path d="M86 199 Q93 200 95 204 Q95 208 89 209" fill="none" stroke="#e6aa84" stroke-width="2.6" stroke-linecap="round"/>
                    <path d="M86 205 Q94 206 96 210 Q95 214 88 215" fill="none" stroke="#e6aa84" stroke-width="2.6" stroke-linecap="round"/>
                    <path d="M85 211 Q92 212 94 216 Q93 219 87 220" fill="none" stroke="#e6aa84" stroke-width="2.4" stroke-linecap="round"/>
                    <path d="M84 216 Q90 217 91 221 Q90 224 85 224" fill="none" stroke="#e6aa84" stroke-width="2" stroke-linecap="round"/>

                    <!-- ── LEHER & KEPALA PRIA (FACELESS / TANPA MUKA) ── -->
                    <!-- Leher kokoh -->
                    <path d="M125 120 L125 138 Q135 142 145 138 L145 120 Z" fill="#e6aa84"/>
                    <path d="M126 120 L126 136 Q135 140 144 136 L144 120 Z" fill="#f5c5a3"/>

                    <!-- Siluet kepala pria dengan rahang maskulin bersih -->
                    <path d="M112 84 C112 64 158 64 158 84 C158 104 148 124 135 128 C122 124 112 104 112 84 Z" fill="#f5c5a3"/>
                    <!-- Bayangan rahang bawah & dagu -->
                    <path d="M123 122 Q135 128 147 122 Q135 126 123 122 Z" fill="#e6aa84"/>

                    <!-- Telinga pria -->
                    <path d="M110 86 Q106 91 110 98 Q113 99 114 92 Z" fill="#f5c5a3"/>
                    <path d="M111 89 Q109 92 112 95" stroke="#e6aa84" stroke-width="1" fill="none"/>
                    <path d="M160 86 Q164 91 160 98 Q157 99 156 92 Z" fill="#f5c5a3"/>
                    <path d="M159 89 Q161 92 158 95" stroke="#e6aa84" stroke-width="1" fill="none"/>

                    <!-- ── RAMBUT PRIA RAPI & BERVOLUME (MALE HAIRCUT) ── -->
                    <!-- Rambut samping kiri & jambang -->
                    <path d="M112 78 L110 88 L114 92 L115 84 Z" fill="#18181b"/>
                    <!-- Rambut samping kanan & jambang -->
                    <path d="M158 78 L160 88 L156 92 L155 84 Z" fill="#18181b"/>
                    <!-- Poni rambut pria tersisir rapi di dahi di bawah topi toga -->
                    <path d="M112 76 C115 68 128 66 135 66 C142 66 155 68 158 76 C155 82 146 86 138 82 C132 78 126 84 118 82 C114 80 112 78 112 76 Z" fill="#18181b"/>
                    <!-- Layer helai rambut dahi untuk kedalaman visual -->
                    <path d="M122 74 Q128 82 134 78 Q129 74 122 74 Z" fill="#27272a"/>
                    <path d="M136 76 Q144 83 150 78 Q144 74 136 76 Z" fill="#27272a"/>

                    <!-- Faceless: wajah bersih halus dengan kilau modern art -->
                    <ellipse cx="135" cy="95" rx="13" ry="14" fill="rgba(255,255,255,0.09)"/>

                    <!-- ── TOPI TOGA DETAIL (MORTARBOARD SESUAI REFERENSI) ── -->
                    <!-- Silinder dasar topi toga -->
                    <path d="M115 64 L115 52 Q135 46 155 52 L155 64 Q135 58 115 64 Z" fill="#141c2e"/>
                    <path d="M114 61 Q135 55 156 61 L156 64 Q135 58 114 64 Z" fill="#d97706"/>
                    <!-- Sisi tebal 3D papan atas toga -->
                    <path d="M80 44 L135 56 L135 62 L80 50 Z" fill="#0f172a"/>
                    <path d="M190 44 L135 56 L135 62 L190 50 Z" fill="#090d16"/>
                    <!-- Permukaan datar atas papan toga (perspektif belah ketupat) -->
                    <polygon points="80,44 135,32 190,44 135,56" fill="#1e293b"/>
                    <polygon points="82,44 135,33 188,44 135,54" fill="#293548"/>
                    <!-- Knob / kancing emas tengah -->
                    <ellipse cx="135" cy="44" rx="6" ry="4" fill="#d97706"/>
                    <ellipse cx="135" cy="44" rx="4" ry="2.5" fill="#f59e0b"/>
                    <circle cx="135" cy="43.5" r="1.5" fill="#fef08a"/>
                    <!-- Tali tassel menjulur dari knob ke kiri -->
                    <path d="M131 44 Q106 42 92 46 Q84 50 80 58" stroke="#f59e0b" stroke-width="2.5" stroke-linecap="round" fill="none"/>
                    <!-- Cincin pengikat & rumbai tassel emas menjuntai di kiri -->
                    <ellipse cx="79" cy="60" rx="4" ry="2.5" fill="#b45309"/>
                    <ellipse cx="79" cy="60" rx="3" ry="1.8" fill="#f59e0b"/>
                    <path d="M76 61 L72 86 M78 61 L75 88 M80 61 L78 88 M82 61 L81 86" stroke="#f59e0b" stroke-width="2" stroke-linecap="round"/>
                    <path d="M77 61 L74 87 M79 61 L77 87" stroke="#d97706" stroke-width="1.5" stroke-linecap="round"/>

                    <!-- ── LENGAN KANAN & TONGKAT PENUNJUK — POSE GURU NATURAL ──
                         Bahu (188,148) -> Lengan atas jatuh wajar ke siku (210,188)
                         Siku menekuk luwes -> Lengan bawah naik ke (204,142)
                         Tangan menggenggam tongkat kayu penunjuk dengan jari-jari natural melingkar -->
                    <g class="g-arm">
                        <!-- Lengan atas jubah hitam toga dengan lengkungan kain alami -->
                        <path d="M188 148 C198 152 208 164 212 184 C214 192 210 200 202 200 C194 198 190 186 186 170 Z" fill="#131926"/>
                        <!-- Kain jubah lebar menjuntai di siku -->
                        <path d="M202 200 C206 208 214 210 220 206 C222 198 218 190 212 184 Z" fill="#0f172a"/>

                        <!-- Lengan bawah pria keluar dari jubah, naik luwes ke tangan -->
                        <path d="M208 186 C211 172 210 156 206 142" stroke="#f5c5a3" stroke-width="14" stroke-linecap="round" fill="none"/>

                        <!-- TONGKAT PENUNJUK KAYU GURU (TERLETAK DI DALAM GENGGAMAN) -->
                        <!-- Batang bawah tongkat (keluar dari bawah kepalan tangan) -->
                        <line x1="206" y1="152" x2="208" y2="166" stroke="#78350f" stroke-width="4.5" stroke-linecap="round"/>
                        <circle cx="208" cy="167" r="2.8" fill="#b45309"/>

                        <!-- Batang atas tongkat (keluar dari atas kepalan tangan, teracung ke kanan atas) -->
                        <line x1="203" y1="134" x2="250" y2="34" stroke="#92400e" stroke-width="3.2" stroke-linecap="round"/>
                        <line x1="204" y1="134" x2="251" y2="34" stroke="#b45309" stroke-width="1.6" stroke-linecap="round"/>
                        <!-- Ujung tongkat penunjuk emas berkilau -->
                        <circle cx="251" cy="33" r="3.5" fill="#f59e0b"/>
                        <circle cx="251" cy="33" r="2" fill="#fef08a"/>

                        <!-- GENGGAMAN TANGAN KANAN PRIA (NATURAL, MEMEGANG TONGKAT) -->
                        <!-- Telapak tangan dasar -->
                        <ellipse cx="205" cy="142" rx="8" ry="9" fill="#f5c5a3" transform="rotate(-8 205 142)"/>
                        <!-- 4 Jari melingkar di depan batang tongkat secara anatomis -->
                        <path d="M201 133 C206 131 211 132 212 136 C212 139 208 141 202 139" fill="#f5c5a3" stroke="#e6aa84" stroke-width="1.8"/>
                        <path d="M200 138 C206 136 211 137 213 141 C213 144 208 146 202 144" fill="#f5c5a3" stroke="#e6aa84" stroke-width="1.8"/>
                        <path d="M200 143 C206 141 211 142 212 146 C212 149 208 151 201 149" fill="#f5c5a3" stroke="#e6aa84" stroke-width="1.8"/>
                        <path d="M201 148 C206 146 210 147 211 150 C211 153 207 155 202 153" fill="#f5c5a3" stroke="#e6aa84" stroke-width="1.8"/>
                        <!-- Ibu jari mengunci genggaman di depan jari-jari -->
                        <path d="M198 139 C201 141 204 145 204 149 C202 150 199 148 198 144 Z" fill="#f5c5a3"/>
                        <path d="M198 140 C201 142 203 146 203 149" stroke="#e6aa84" stroke-width="1.2" fill="none"/>
                    </g><!-- /g-arm -->

                </g><!-- /g-float -->

                <!-- Dekorasi kilau bintang akademis -->
                <g class="g-star-1">
                    <path d="M256 20 L258 25 L263 26 L259 30 L260 35 L256 32 L252 35 L253 30 L249 26 L254 25 Z" fill="#fbbf24" opacity="0.9"/>
                </g>
                <g class="g-star-2">
                    <path d="M36 108 L37.5 113 L43 114 L39 117 L40 122 L36 119 L32 122 L33 117 L29 114 L34.5 113 Z" fill="#a5b4fc" opacity="0.8"/>
                </g>
                <circle class="g-star-3" cx="240" cy="75" r="3" fill="#818cf8" opacity="0.6"/>
                <circle class="g-star-3" cx="44" cy="150" r="2.5" fill="#cbd5e1" opacity="0.5"/>

            </svg>
        </div><!-- /character-wrap -->

        <div class="visual-caption">
            <span class="portal-badge">PORTAL AKADEMIK</span>
        </div>

    </div><!-- /login-visual -->


    <!-- ============================================================
         PANEL KANAN — FORM LOGIN & PENDAFTARAN
    ============================================================ -->
    <div class="login-form-panel">

        <!-- Tab Switcher Masuk / Daftar -->
        <div class="auth-switcher">
            <button type="button" class="auth-switch-btn <?= $active_tab === 'login' ? 'active' : '' ?>" id="tabBtnLogin" onclick="switchTab('login')">
                <i class="bi bi-box-arrow-in-right"></i> Masuk
            </button>
            <button type="button" class="auth-switch-btn <?= $active_tab === 'register' ? 'active' : '' ?>" id="tabBtnRegister" onclick="switchTab('register')">
                <i class="bi bi-person-plus-fill"></i> Daftar Akun
            </button>
        </div>

        <?php if (!empty($success_message)): ?>
        <div class="alert-success-custom">
            <i class="bi bi-check-circle-fill fs-5"></i>
            <div><?= htmlspecialchars($success_message) ?></div>
        </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
        <div class="alert-error">
            <i class="bi bi-exclamation-circle-fill fs-5"></i>
            <div><?= htmlspecialchars($error_message) ?></div>
        </div>
        <?php endif; ?>

        <!-- ================= PANE LOGIN ================= -->
        <div id="paneLogin" style="<?= $active_tab === 'login' ? '' : 'display: none;' ?>">
            <div class="mb-3">
                <h2>Selamat Datang</h2>
                <p class="subtitle">Masuk untuk mengakses dashboard bimbingan Anda.</p>
            </div>

            <form action="login.php" method="POST" novalidate>
                <input type="hidden" name="action" value="login">

                <div class="mb-3">
                    <label for="username" class="form-label-custom">Username</label>
                    <div class="input-icon-wrap">
                        <i class="bi bi-person field-icon"></i>
                        <input type="text"
                               class="input-pill <?= (!empty($error_message) && $active_tab === 'login') ? 'error' : '' ?>"
                               id="username" name="username"
                               placeholder="Masukkan NIM / ID / Username"
                               required autofocus autocomplete="username"
                               value="<?= htmlspecialchars($login_username ?? '') ?>">
                    </div>
                </div>

                <div class="mb-4">
                    <label for="password" class="form-label-custom">Password</label>
                    <div class="input-icon-wrap" style="position: relative;">
                        <i class="bi bi-lock field-icon"></i>
                        <input type="password"
                               class="input-pill <?= (!empty($error_message) && $active_tab === 'login') ? 'error' : '' ?>"
                               id="password" name="password"
                               placeholder="Masukkan password Anda"
                               required autocomplete="current-password"
                               style="padding-right: 2.8rem !important;">
                        <button type="button" class="btn-toggle-eye" id="btnToggleLoginPass" title="Lihat/Sembunyikan Kata Sandi">
                            <i class="bi bi-eye" id="loginEyeIcon"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-login mb-3">
                    <i class="bi bi-box-arrow-in-right"></i>
                    Masuk ke Portal
                </button>

                <div class="text-center text-muted small mb-2">
                    Belum memiliki akun?
                    <a href="javascript:void(0)" onclick="switchTab('register')" class="text-primary fw-bold text-decoration-none ms-1">
                        Daftar Sekarang <i class="bi bi-arrow-right-short"></i>
                    </a>
                </div>

                <div class="text-center pt-2 border-top">
                    <a href="index.php" class="text-muted text-decoration-none small d-inline-flex align-items-center gap-1" style="font-weight: 600; font-size: 0.85rem;">
                        <i class="bi bi-arrow-left"></i> Kembali ke Beranda Utama
                    </a>
                </div>
            </form>
        </div><!-- /paneLogin -->

        <!-- ================= PANE REGISTER ================= -->
        <div id="paneRegister" style="<?= $active_tab === 'register' ? '' : 'display: none;' ?>">
            <div class="mb-3">
                <h2>Pendaftaran Akun</h2>
                <p class="subtitle">Pilih peran dan lengkapi data untuk membuat akun bimbingan.</p>
            </div>

            <form action="login.php" method="POST" novalidate id="formRegister">
                <input type="hidden" name="action" value="register">

                <!-- Pilihan Peran -->
                <div class="mb-3">
                    <label class="form-label-custom">Pilih Peran Akun <span class="text-danger">*</span></label>
                    <div class="role-selector-pill">
                        <label class="role-option">
                            <input type="radio" name="reg_role" value="mahasiswa" <?= ($reg_role ?? 'mahasiswa') === 'mahasiswa' ? 'checked' : '' ?> onchange="handleRoleChange(this.value)">
                            <span class="role-box">
                                <i class="bi bi-mortarboard-fill"></i>
                                <span>Mahasiswa</span>
                            </span>
                        </label>
                        <label class="role-option">
                            <input type="radio" name="reg_role" value="dosen" <?= ($reg_role ?? '') === 'dosen' ? 'checked' : '' ?> onchange="handleRoleChange(this.value)">
                            <span class="role-box">
                                <i class="bi bi-person-video3"></i>
                                <span>Dosen Pembimbing</span>
                            </span>
                        </label>
                    </div>
                </div>

                <!-- Nama Lengkap -->
                <div class="mb-2">
                    <label for="reg_nama" class="form-label-custom">Nama Lengkap & Gelar <span class="text-danger">*</span></label>
                    <div class="input-icon-wrap">
                        <i class="bi bi-person-vcard field-icon"></i>
                        <input type="text" class="input-pill" id="reg_nama" name="reg_nama"
                               placeholder="Contoh: Muhammad Fauzan / Dr. Hendra, M.Kom"
                               value="<?= htmlspecialchars($reg_nama ?? '') ?>" required>
                    </div>
                </div>

                <div class="row g-2 mb-2">
                    <!-- NIM atau NIDN -->
                    <div class="col-6">
                        <label for="reg_identifier" class="form-label-custom" id="labelIdentifier">
                            <?= ($reg_role ?? 'mahasiswa') === 'dosen' ? 'NIDN' : 'NIM' ?> <span class="text-danger">*</span>
                        </label>
                        <div class="input-icon-wrap">
                            <i class="bi bi-card-text field-icon"></i>
                            <input type="text" class="input-pill" id="reg_identifier" name="reg_identifier"
                                   placeholder="<?= ($reg_role ?? 'mahasiswa') === 'dosen' ? 'Nomor Induk Dosen' : 'Nomor Induk Mahasiswa' ?>"
                                   value="<?= htmlspecialchars($reg_identifier ?? '') ?>" required>
                        </div>
                    </div>

                    <!-- Username -->
                    <div class="col-6">
                        <label for="reg_username" class="form-label-custom">Username <span class="text-danger">*</span></label>
                        <div class="input-icon-wrap">
                            <i class="bi bi-at field-icon"></i>
                            <input type="text" class="input-pill" id="reg_username" name="reg_username"
                                   placeholder="Username login"
                                   value="<?= htmlspecialchars($reg_username ?? '') ?>" required autocomplete="off">
                        </div>
                    </div>
                </div>

                <!-- Khusus Mahasiswa: Kelas -->
                <div id="mhsFields" style="<?= ($reg_role ?? 'mahasiswa') === 'mahasiswa' ? '' : 'display:none;' ?>" class="mb-2">
                    <label for="reg_kelas" class="form-label-custom">Pilihan Kelas <span class="text-danger">*</span></label>
                    <div class="input-icon-wrap">
                        <i class="bi bi-building field-icon"></i>
                        <select class="input-pill form-select-custom" id="reg_kelas" name="reg_kelas">
                            <option value="TI-1A-2025" <?= ($reg_kelas ?? '') === 'TI-1A-2025' ? 'selected' : '' ?>>TI-1A-2025</option>
                            <option value="TI-1B-2025" <?= ($reg_kelas ?? '') === 'TI-1B-2025' ? 'selected' : '' ?>>TI-1B-2025</option>
                            <option value="TI-1C-2025" <?= ($reg_kelas ?? '') === 'TI-1C-2025' ? 'selected' : '' ?>>TI-1C-2025</option>
                        </select>
                    </div>
                </div>

                <!-- Khusus Dosen: Email & No. WhatsApp -->
                <div id="dosenFields" style="<?= ($reg_role ?? 'mahasiswa') === 'dosen' ? '' : 'display:none;' ?>" class="row g-2 mb-2">
                    <div class="col-6">
                        <label for="reg_email" class="form-label-custom">Email Dosen <span class="text-danger">*</span></label>
                        <div class="input-icon-wrap">
                            <i class="bi bi-envelope field-icon"></i>
                            <input type="email" class="input-pill" id="reg_email" name="reg_email"
                                   placeholder="dosen@univ.ac.id"
                                   value="<?= htmlspecialchars($reg_email ?? '') ?>">
                        </div>
                    </div>
                    <div class="col-6">
                        <label for="reg_wa" class="form-label-custom">No. WhatsApp</label>
                        <div class="input-icon-wrap">
                            <i class="bi bi-whatsapp field-icon"></i>
                            <input type="text" class="input-pill" id="reg_wa" name="reg_wa"
                                   placeholder="081234567890"
                                   value="<?= htmlspecialchars($reg_wa ?? '') ?>">
                        </div>
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <!-- Password -->
                    <div class="col-6">
                        <label for="reg_password" class="form-label-custom">Password <span class="text-danger">*</span></label>
                        <div class="input-icon-wrap" style="position: relative;">
                            <i class="bi bi-lock field-icon"></i>
                            <input type="password" class="input-pill" id="reg_password" name="reg_password"
                                   placeholder="Min. 6 karakter" required autocomplete="new-password"
                                   style="padding-right: 2.8rem !important;">
                            <button type="button" class="btn-toggle-eye" onclick="togglePassField('reg_password', this)" title="Lihat/Sembunyikan">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                    <!-- Konfirmasi Password -->
                    <div class="col-6">
                        <label for="reg_password_confirm" class="form-label-custom">Konfirmasi <span class="text-danger">*</span></label>
                        <div class="input-icon-wrap" style="position: relative;">
                            <i class="bi bi-shield-lock field-icon"></i>
                            <input type="password" class="input-pill" id="reg_password_confirm" name="reg_password_confirm"
                                   placeholder="Ulangi password" required autocomplete="new-password"
                                   style="padding-right: 2.8rem !important;">
                            <button type="button" class="btn-toggle-eye" onclick="togglePassField('reg_password_confirm', this)" title="Lihat/Sembunyikan">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn-login mb-3">
                    <i class="bi bi-person-check-fill"></i>
                    Daftar Sekarang
                </button>

                <div class="text-center text-muted small mb-2">
                    Sudah memiliki akun?
                    <a href="javascript:void(0)" onclick="switchTab('login')" class="text-primary fw-bold text-decoration-none ms-1">
                        Masuk ke Portal <i class="bi bi-arrow-right-short"></i>
                    </a>
                </div>

                <div class="text-center pt-2 border-top">
                    <a href="index.php" class="text-muted text-decoration-none small d-inline-flex align-items-center gap-1" style="font-weight: 600; font-size: 0.85rem;">
                        <i class="bi bi-arrow-left"></i> Kembali ke Beranda Utama
                    </a>
                </div>
            </form>
        </div><!-- /paneRegister -->

    </div><!-- /login-form-panel -->

</div><!-- /login-wrapper -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function switchTab(tab) {
        const paneLogin = document.getElementById('paneLogin');
        const paneRegister = document.getElementById('paneRegister');
        const btnLogin = document.getElementById('tabBtnLogin');
        const btnRegister = document.getElementById('tabBtnRegister');
        const pageTitle = document.getElementById('pageTitle');

        if (tab === 'register') {
            paneLogin.style.display = 'none';
            paneRegister.style.display = 'block';
            btnLogin.classList.remove('active');
            btnRegister.classList.add('active');
            if (pageTitle) pageTitle.innerText = 'Daftar Akun — Bimbingan Skripsi';
            try { history.replaceState(null, '', '?tab=register'); } catch (e) {}
            const firstInput = document.getElementById('reg_nama');
            if (firstInput) firstInput.focus();
        } else {
            paneRegister.style.display = 'none';
            paneLogin.style.display = 'block';
            btnRegister.classList.remove('active');
            btnLogin.classList.add('active');
            if (pageTitle) pageTitle.innerText = 'Masuk — Bimbingan Skripsi';
            try { history.replaceState(null, '', 'login.php'); } catch (e) {}
            const firstInput = document.getElementById('username');
            if (firstInput) firstInput.focus();
        }
    }

    function handleRoleChange(role) {
        const mhsFields = document.getElementById('mhsFields');
        const dosenFields = document.getElementById('dosenFields');
        const labelIdentifier = document.getElementById('labelIdentifier');
        const inputIdentifier = document.getElementById('reg_identifier');

        if (role === 'dosen') {
            if (mhsFields) mhsFields.style.display = 'none';
            if (dosenFields) dosenFields.style.display = 'flex';
            if (labelIdentifier) labelIdentifier.innerHTML = 'NIDN <span class="text-danger">*</span>';
            if (inputIdentifier) inputIdentifier.placeholder = 'Nomor Induk Dosen';
        } else {
            if (mhsFields) mhsFields.style.display = 'block';
            if (dosenFields) dosenFields.style.display = 'none';
            if (labelIdentifier) labelIdentifier.innerHTML = 'NIM <span class="text-danger">*</span>';
            if (inputIdentifier) inputIdentifier.placeholder = 'Nomor Induk Mahasiswa';
        }
    }

    function togglePassField(inputId, btn) {
        const input = document.getElementById(inputId);
        if (!input) return;
        const icon = btn.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            if (icon) {
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            }
        } else {
            input.type = 'password';
            if (icon) {
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        }
    }

    document.getElementById("btnToggleLoginPass")?.addEventListener("click", function() {
        togglePassField("password", this);
    });

    // Auto-sync input NIM/NIDN ke Username jika username belum diisi manual
    const identifierInput = document.getElementById('reg_identifier');
    const usernameInput = document.getElementById('reg_username');
    if (identifierInput && usernameInput) {
        identifierInput.addEventListener('input', function() {
            if (!usernameInput.dataset.manual) {
                usernameInput.value = this.value.trim().toLowerCase().replace(/\s+/g, '');
            }
        });
        usernameInput.addEventListener('input', function() {
            if (this.value.trim().length > 0) {
                this.dataset.manual = 'true';
            } else {
                delete this.dataset.manual;
            }
        });
    }
</script>
</body>
</html>
