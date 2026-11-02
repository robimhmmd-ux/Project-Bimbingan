<?php
// login.php - Autentikasi Pengguna Multi-Role
// Model Desain: Selaras dengan Bahasa Desain Modern Gojek pada Landing Page (Human, Bold, Clean)
session_start();

if (isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'admin')     header("Location: admin/index.php");
    elseif ($_SESSION['role'] === 'dosen') header("Location: dosen/index.php");
    elseif ($_SESSION['role'] === 'mahasiswa') header("Location: mahasiswa/index.php");
    exit;
}

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'config/database.php';
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($username) && !empty($password)) {
        try {
            $stmt = $pdo->prepare("SELECT id, username, password, role FROM users WHERE username = :username LIMIT 1");
            $stmt->execute([':username' => $username]);
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

                if ($user['role'] === 'admin') header("Location: admin/index.php");
                elseif ($user['role'] === 'dosen') header("Location: dosen/index.php");
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
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk ke Portal — SIM Skripsi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --gojek-green:       #00880D;
            --gojek-green-hover: #006b0a;
            --gojek-dark:        #0b0f19;
            --surface:           #ffffff;
            --background:        #f4f6f8;
            --text-main:         #0f172a;
            --text-muted:        #64748b;
            --border-color:      #e2e8f0;
            --border-focus:      #00880D;
            --radius-pill:       9999px;
            --radius-lg:         32px;
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

        /* ======== WRAPPER KARTU LOGIN ======== */
        .login-wrapper {
            display: flex;
            width: 100%;
            max-width: 960px;
            min-height: 590px;
            border-radius: var(--radius-lg);
            overflow: hidden;
            background: #ffffff;
            box-shadow:
                0 25px 60px -12px rgba(15, 23, 42, 0.18),
                0 10px 25px -5px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(226, 232, 240, 0.8);
        }

        /* ======== PANEL KIRI (GAYA LANDING PAGE GOJEK) ======== */
        .login-visual {
            flex: 0 0 48%;
            background-color: #0b0f19;
            background-image: linear-gradient(145deg, rgba(11, 15, 25, 0.92) 0%, rgba(0, 60, 25, 0.88) 100%), url('https://images.unsplash.com/photo-1523240795612-9a054b0db644?q=80&w=1200&auto=format&fit=crop');
            background-size: cover;
            background-position: center;
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 2.8rem 2.4rem;
            color: #ffffff;
        }

        .brand-gojek-header {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            position: relative;
            z-index: 2;
        }

        .brand-icon-gojek {
            width: 44px;
            height: 44px;
            background: var(--gojek-green);
            color: #ffffff;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            box-shadow: 0 4px 14px rgba(0, 136, 13, 0.4);
            flex-shrink: 0;
        }

        .brand-name-gojek {
            font-weight: 800;
            font-size: 1.25rem;
            letter-spacing: -0.02em;
            color: #ffffff;
            display: block;
            line-height: 1.15;
        }

        .brand-sub-gojek {
            font-size: 0.72rem;
            color: rgba(255, 255, 255, 0.65);
            font-weight: 600;
            letter-spacing: 0.03em;
            display: block;
        }

        .visual-content {
            position: relative;
            z-index: 2;
            margin: 2rem 0;
        }

        .badge-tagline-gojek {
            display: inline-block;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.12em;
            color: #74ce5e;
            background: rgba(116, 206, 94, 0.15);
            border: 1px solid rgba(116, 206, 94, 0.35);
            padding: 0.35rem 0.9rem;
            border-radius: 9999px;
            text-transform: uppercase;
        }

        .headline-gojek {
            font-size: 2.1rem;
            font-weight: 800;
            line-height: 1.15;
            letter-spacing: -0.03em;
            color: #ffffff;
        }

        .desc-gojek {
            font-size: 0.88rem;
            color: rgba(255, 255, 255, 0.75);
            line-height: 1.6;
        }

        .feature-pills-list {
            display: flex;
            flex-direction: column;
            gap: 0.7rem;
        }

        .feature-pill-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.12);
            padding: 0.6rem 1rem;
            border-radius: 16px;
            font-size: 0.82rem;
            font-weight: 600;
            color: #ffffff;
        }

        .visual-footer {
            position: relative;
            z-index: 2;
        }

        .badge-status-tidb {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.75rem;
            font-weight: 700;
            color: rgba(255, 255, 255, 0.75);
            background: rgba(0, 0, 0, 0.35);
            border: 1px solid rgba(255, 255, 255, 0.1);
            padding: 0.35rem 0.85rem;
            border-radius: 9999px;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            background-color: #22c55e;
            border-radius: 50%;
            display: inline-block;
            box-shadow: 0 0 8px #22c55e;
        }

        /* ======== PANEL KANAN (FORM LOGIN ALA GOJEK) ======== */
        .login-form-panel {
            flex: 1;
            background: var(--surface);
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 3.5rem 3rem;
        }

        .login-form-panel h2 {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: -0.03em;
            margin-bottom: 0.35rem;
        }

        .subtitle {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-bottom: 2rem;
        }

        .input-pill {
            border-radius: var(--radius-pill) !important;
            border: 1.5px solid var(--border-color);
            padding: 0.78rem 1.25rem 0.78rem 2.8rem !important;
            font-size: 0.92rem;
            color: var(--text-main);
            width: 100%;
            transition: all 0.2s ease;
            background: #ffffff;
        }
        .input-pill:focus {
            border-color: var(--border-focus);
            box-shadow: 0 0 0 3px rgba(0, 136, 13, 0.15);
            outline: none;
        }
        .input-pill.error { border-color: #ef4444; }

        .input-icon-wrap { position: relative; }
        .input-icon-wrap .field-icon {
            position: absolute;
            left: 1.1rem;
            top: 50%; transform: translateY(-50%);
            color: #94a3b8;
            font-size: 1.05rem;
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
            color: var(--gojek-green);
        }

        .form-label-custom {
            font-size: 0.85rem;
            font-weight: 700;
            color: #334155;
            margin-bottom: 0.45rem;
            display: block;
        }

        /* Tombol Login Gojek Hijau */
        .btn-login {
            background: var(--gojek-green);
            border: none;
            border-radius: var(--radius-pill);
            color: #ffffff;
            font-weight: 700;
            font-size: 0.95rem;
            padding: 0.82rem 1.5rem;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            box-shadow: 0 6px 18px rgba(0, 136, 13, 0.28);
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            cursor: pointer;
        }
        .btn-login:hover {
            background: var(--gojek-green-hover);
            box-shadow: 0 8px 24px rgba(0, 136, 13, 0.38);
            transform: translateY(-2px);
            color: #ffffff;
        }
        .btn-login:active { transform: translateY(0); }

        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 1rem;
            color: #991b1b;
            font-size: 0.85rem;
            padding: 0.8rem 1rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            margin-bottom: 1.5rem;
        }

        .btn-kembali-floating {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            color: #0f172a;
            font-weight: 700;
            font-size: 0.85rem;
            padding: 0.5rem 1.15rem;
            border-radius: var(--radius-pill);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-kembali-floating:hover {
            background: #0f172a;
            color: #ffffff;
            border-color: #0f172a;
            transform: translateX(-3px);
        }

        @media (max-width: 768px) {
            .login-visual { display: none; }
            .login-form-panel { padding: 2.5rem 1.75rem; }
        }
    </style>
</head>
<body>

<!-- Tombol Cepat Kembali ke Beranda -->
<a href="index.php" class="btn-kembali-floating position-fixed top-0 start-0 m-3" style="z-index: 1050;">
    <i class="bi bi-arrow-left"></i> Kembali ke Beranda
</a>

<div class="login-wrapper">

    <!-- ============================================================
         PANEL KIRI — GAYA LANDING PAGE GOJEK
    ============================================================ -->
    <div class="login-visual">

        <!-- Logo SIM Skripsi (Identik dengan Navbar Landing Page) -->
        <div class="brand-gojek-header">
            <div class="brand-icon-gojek">
                <i class="bi bi-mortarboard-fill"></i>
            </div>
            <div>
                <span class="brand-name-gojek">SIM Skripsi</span>
                <span class="brand-sub-gojek">Bimbingan & Tugas Akhir</span>
            </div>
        </div>

        <!-- Konten Tagline Utama Gojek -->
        <div class="visual-content">
            <span class="badge-tagline-gojek mb-3">
                #PASTIADAJALAN WISUDA
            </span>
            <h1 class="headline-gojek mb-3">
                Dari Bab 1,<br>Pasti Ada Jalan Wisuda.
            </h1>
            <p class="desc-gojek mb-4">
                Akses ketersediaan jadwal dosen, serahkan draf bab PDF, dan pantau logbook kelulusan dalam satu pintu.
            </p>

            <!-- 3 Poin Fitur Unggulan Ringkas -->
            <div class="feature-pills-list">
                <div class="feature-pill-item">
                    <i class="bi bi-calendar2-check-fill text-success fs-6"></i>
                    <span>Reservasi slot waktu anti-bentrok</span>
                </div>
                <div class="feature-pill-item">
                    <i class="bi bi-file-earmark-diff-fill text-primary fs-6"></i>
                    <span>Riwayat revisi draf PDF rapi</span>
                </div>
                <div class="feature-pill-item">
                    <i class="bi bi-printer-fill text-warning fs-6"></i>
                    <span>Cetak kartu kendali resmi otomatis</span>
                </div>
            </div>
        </div>

        <!-- Footer Visual: Live TiDB Status -->
        <div class="visual-footer">
            <span class="badge-status-tidb">
                <span class="status-dot"></span> TiDB Cloud Live • Kampus Terpadu
            </span>
        </div>

    </div><!-- /login-visual -->


    <!-- ============================================================
         PANEL KANAN — FORM LOGIN ALA GOJEK
    ============================================================ -->
    <div class="login-form-panel">

        <h2>Selamat Datang</h2>
        <p class="subtitle">Masuk untuk mengakses dashboard bimbingan Anda.</p>

        <?php if (!empty($error_message)): ?>
        <div class="alert-error">
            <i class="bi bi-exclamation-circle-fill"></i>
            <?= htmlspecialchars($error_message) ?>
        </div>
        <?php endif; ?>

        <form action="login.php" method="POST" novalidate>

            <div class="mb-4">
                <label for="username" class="form-label-custom">Username</label>
                <div class="input-icon-wrap">
                    <i class="bi bi-person field-icon"></i>
                    <input type="text"
                           class="input-pill <?= !empty($error_message) ? 'error' : '' ?>"
                           id="username" name="username"
                           placeholder="Masukkan NIM / ID"
                           required autofocus autocomplete="username"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                </div>
            </div>

            <div class="mb-4">
                <label for="password" class="form-label-custom">Password</label>
                <div class="input-icon-wrap" style="position: relative;">
                    <i class="bi bi-lock field-icon"></i>
                    <input type="password"
                           class="input-pill <?= !empty($error_message) ? 'error' : '' ?>"
                           id="password" name="password"
                           placeholder="Masukkan password"
                           required autocomplete="current-password"
                           style="padding-right: 2.8rem !important;">
                    <button type="button" class="btn-toggle-eye" id="btnToggleLoginPass" title="Lihat/Sembunyikan Kata Sandi">
                        <i class="bi bi-eye" id="loginEyeIcon"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-login">
                <i class="bi bi-box-arrow-in-right"></i>
                Masuk ke Portal
            </button>

            <div class="text-center mt-3 pt-1">
                <a href="index.php" class="text-muted text-decoration-none small d-inline-flex align-items-center gap-1" style="font-weight: 600; font-size: 0.85rem;">
                    <i class="bi bi-arrow-left"></i> Kembali ke Beranda Utama
                </a>
            </div>

        </form>

    </div><!-- /login-form-panel -->

</div><!-- /login-wrapper -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById("btnToggleLoginPass")?.addEventListener("click", function() {
    const input = document.getElementById("password");
    const icon = document.getElementById("loginEyeIcon");
    if (input.type === "password") {
        input.type = "text";
        icon.classList.remove("bi-eye");
        icon.classList.add("bi-eye-slash");
    } else {
        input.type = "password";
        icon.classList.remove("bi-eye-slash");
        icon.classList.add("bi-eye");
    }
});
</script>
</body>
</html>