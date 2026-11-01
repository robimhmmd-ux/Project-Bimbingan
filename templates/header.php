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

// Ambil info dosen jika role dosen
$dosen_info = null;
if (isset($pdo) && $cur_role === 'dosen') {
    $stmt_d_info = $pdo->prepare("SELECT id, nama_dosen FROM dosen WHERE user_id = :uid LIMIT 1");
    $stmt_d_info->execute([':uid' => $cur_user_id]);
    $dosen_info = $stmt_d_info->fetch();
}

// Proses Cepat Ubah Username / Nama dari Modal Navbar
$quick_error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action_quick_edit_navbar'])) {
    $new_u    = trim($_POST['quick_username'] ?? '');
    $new_nama = trim($_POST['quick_nama_dosen'] ?? '');

    if (empty($new_u)) {
        $quick_error = "Username tidak boleh kosong.";
    } elseif (preg_match('/\s/', $new_u)) {
        $quick_error = "Username tidak boleh mengandung spasi.";
    } elseif (isset($pdo)) {
        // Cek apakah username sudah dipakai pengguna lain
        $stmt_cek = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :u AND id != :uid");
        $stmt_cek->execute([':u' => $new_u, ':uid' => $cur_user_id]);
        if ($stmt_cek->fetchColumn() > 0) {
            $quick_error = "Username '@{$new_u}' sudah digunakan.";
        } else {
            // Update username di tabel users
            $stmt_up_u = $pdo->prepare("UPDATE users SET username = :u WHERE id = :uid");
            $stmt_up_u->execute([':u' => $new_u, ':uid' => $cur_user_id]);
            $_SESSION['username'] = $new_u;

            // Jika dosen, update juga nama_dosen
            if (!empty($new_nama) && $cur_role === 'dosen') {
                $stmt_up_d = $pdo->prepare("UPDATE dosen SET nama_dosen = :nm WHERE user_id = :uid");
                $stmt_up_d->execute([':nm' => $new_nama, ':uid' => $cur_user_id]);
                $_SESSION['nama_lengkap'] = $new_nama;
            } elseif (!empty($new_nama) && $cur_role === 'admin') {
                $stmt_up_a = $pdo->prepare("UPDATE admin_profil SET nama_lengkap = :nm WHERE user_id = :uid");
                $stmt_up_a->execute([':nm' => $new_nama, ':uid' => $cur_user_id]);
                $_SESSION['nama_lengkap'] = $new_nama;
            }

            // Redirect bersih ke halaman yang sama
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
        }
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

        <!-- Right Side User & Status Pill (Bisa Langsung Diklik Untuk Edit Nama) -->
        <div class="d-flex align-items-center gap-3 text-white">
            <button type="button" 
                    class="btn btn-link text-white text-decoration-none p-0 d-inline-flex align-items-center gap-2 shadow-none" 
                    data-bs-toggle="modal" 
                    data-bs-target="#modalQuickEditNavbar" 
                    title="Klik untuk mengedit username atau nama Anda">
                <span class="small d-none d-md-inline text-white-50">Halo,</span>
                <span class="fw-bold text-white border-bottom border-white border-opacity-50 pb-0"><?= htmlspecialchars($_SESSION['nama_lengkap'] ?? $_SESSION['username'] ?? 'User'); ?></span>
                <span class="badge bg-white bg-opacity-25 text-white rounded-pill px-2 py-1 shadow-sm" style="font-size: 0.72rem;">
                    <i class="bi bi-pencil-fill me-1"></i>Edit
                </span>
            </button>
            <a href="profil.php" class="btn btn-sm btn-outline-light d-none d-sm-inline-flex align-items-center gap-1" title="Buka Profil & Pengaturan Akun">
                <i class="bi bi-person-gear"></i><span class="d-none d-md-inline">Profil</span>
            </a>
            <a href="../logout.php" class="btn btn-sm btn-outline-light px-3" onclick="return confirm('Apakah Anda yakin ingin keluar?')" title="Logout">
                <i class="bi bi-box-arrow-right me-1"></i>Logout
            </a>
        </div>
    </div>
</nav>

<!-- MODAL QUICK EDIT USERNAME & NAMA DOSEN / ADMIN LANGSUNG DARI NAVBAR -->
<div class="modal fade" id="modalQuickEditNavbar" tabindex="-1" aria-labelledby="modalQuickEditNavbarLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow border-0 text-dark">
            <form method="POST" action="">
                <input type="hidden" name="action_quick_edit_navbar" value="1">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold" id="modalQuickEditNavbarLabel">
                        <i class="bi bi-pencil-square me-2"></i>Edit Username / Nama Akun
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4 text-start">
                    <?php if (!empty($quick_error)): ?>
                        <div class="alert alert-danger py-2 small mb-3">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i><?= htmlspecialchars($quick_error); ?>
                        </div>
                    <?php endif; ?>

                    <p class="text-muted small mb-3">
                        Ubah tulisan username yang tampil pada ucapan <strong>Halo, ...</strong> di pojok kanan atas serta kredensial akun Anda:
                    </p>

                    <!-- Input Username -->
                    <div class="mb-3">
                        <label for="quick_username" class="form-label fw-bold small text-dark">
                            <i class="bi bi-person-badge text-primary me-1"></i>ID Login / Username (Tampil di Navbar) *
                        </label>
                        <div class="input-group">
                            <span class="input-group-text bg-light fw-bold text-muted">@</span>
                            <input type="text" name="quick_username" id="quick_username" class="form-control fw-bold text-primary" 
                                   value="<?= htmlspecialchars($_SESSION['username'] ?? ''); ?>" 
                                   placeholder="Contoh: dosen1" required autofocus>
                        </div>
                        <div class="form-text small text-muted">Tanpa spasi. Contoh: <code>salsa</code>, <code>dosen_hendra</code>, <code>admin</code>.</div>
                    </div>

                    <?php if ($cur_role === 'dosen'): ?>
                    <!-- Input Nama Lengkap Dosen -->
                    <div class="mb-3">
                        <label for="quick_nama_dosen" class="form-label fw-bold small text-dark">
                            <i class="bi bi-person-lines-fill text-primary me-1"></i>Nama Lengkap & Gelar Resmi Dosen
                        </label>
                        <input type="text" name="quick_nama_dosen" id="quick_nama_dosen" class="form-control" 
                                value="<?= htmlspecialchars($dosen_info['nama_dosen'] ?? $_SESSION['nama_lengkap'] ?? ''); ?>" 
                                placeholder="Contoh: Dr. Hendra Gunawan, M.T.">
                        <div class="form-text small text-muted">Nama resmi yang tampil di lembar laporan dan kartu bimbingan mahasiswa.</div>
                    </div>
                    <?php elseif ($cur_role === 'admin'): ?>
                    <!-- Input Nama Lengkap Admin -->
                    <div class="mb-3">
                        <label for="quick_nama_dosen" class="form-label fw-bold small text-dark">
                            <i class="bi bi-person-lines-fill text-primary me-1"></i>Nama Lengkap Administrator
                        </label>
                        <input type="text" name="quick_nama_dosen" id="quick_nama_dosen" class="form-control" 
                                value="<?= htmlspecialchars($_SESSION['nama_lengkap'] ?? 'Administrator'); ?>" 
                                placeholder="Contoh: Talita Nabila, S.Kom.">
                        <div class="form-text small text-muted">Nama resmi yang tampil pada navbar sistem dan kop surat laporan akademik.</div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm fw-semibold">
                        <i class="bi bi-check2-circle me-1"></i> Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="container-fluid">
    <div class="row">
