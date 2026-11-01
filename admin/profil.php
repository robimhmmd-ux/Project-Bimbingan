<?php
// admin/profil.php - Manajemen Profil, Nama Lengkap & Pengaturan Akun Administrator
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Proteksi akses role admin (wajib sebelum ada output HTML)
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

require_once '../config/database.php';

$user_id     = (int)$_SESSION['user_id'];
$success_msg = '';
$error_msg   = '';

// 1. Pastikan tabel admin_profil tersedia di database
$pdo->exec("
    CREATE TABLE IF NOT EXISTS admin_profil (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL UNIQUE,
        nama_lengkap VARCHAR(100) NOT NULL DEFAULT 'Administrator Utama',
        email VARCHAR(100) DEFAULT 'admin@univ.ac.id',
        no_whatsapp VARCHAR(20) DEFAULT '081234567890',
        jabatan VARCHAR(100) DEFAULT 'Staf Administrasi Akademik & Skripsi',
        foto_profil VARCHAR(255) DEFAULT 'default.png',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;
");

// 2. Ambil data profil admin saat ini
$stmt_admin = $pdo->prepare("
    SELECT u.id AS uid, u.username, u.password, u.created_at AS user_created_at,
           p.id AS pid, p.nama_lengkap, p.email, p.no_whatsapp, p.jabatan, p.foto_profil
    FROM users u
    LEFT JOIN admin_profil p ON u.id = p.user_id
    WHERE u.id = :uid LIMIT 1
");
$stmt_admin->execute([':uid' => $user_id]);
$admin = $stmt_admin->fetch(PDO::FETCH_ASSOC);

// Jika profil belum ada barisnya di admin_profil, lakukan auto-insert default
if ($admin && empty($admin['pid'])) {
    $stmt_init = $pdo->prepare("
        INSERT INTO admin_profil (user_id, nama_lengkap, email, no_whatsapp, jabatan, foto_profil)
        VALUES (:uid, 'Administrator Utama', 'admin@univ.ac.id', '081234567890', 'Staf Administrasi Akademik & Skripsi', 'default.png')
    ");
    $stmt_init->execute([':uid' => $user_id]);

    // Refresh fetch
    $stmt_admin->execute([':uid' => $user_id]);
    $admin = $stmt_admin->fetch(PDO::FETCH_ASSOC);
}

// 3. PROSES UPDATE (POST REQUEST)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    // A. UPDATE BIODATA, NAMA LENGKAP & FOTO PROFIL
    if ($action === 'update_profil') {
        $nama_lengkap = trim($_POST['nama_lengkap'] ?? '');
        $username     = trim($_POST['username'] ?? '');
        $email        = trim($_POST['email'] ?? '');
        $no_whatsapp  = trim($_POST['no_whatsapp'] ?? '');
        $jabatan      = trim($_POST['jabatan'] ?? '');
        $foto_lama    = $admin['foto_profil'] ?? 'default.png';
        $foto_baru    = $foto_lama;

        // Validasi input wajib
        if (empty($nama_lengkap) || empty($username)) {
            $error_msg = "Nama lengkap dan Username tidak boleh kosong.";
        } elseif (preg_match('/\s/', $username)) {
            $error_msg = "Username tidak boleh mengandung spasi.";
        } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_msg = "Format email tidak valid.";
        } else {
            // Cek apakah username diubah dan apakah sudah digunakan user lain
            if ($username !== $admin['username']) {
                $stmt_cek_u = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :u AND id != :uid");
                $stmt_cek_u->execute([':u' => $username, ':uid' => $user_id]);
                if ($stmt_cek_u->fetchColumn() > 0) {
                    $error_msg = "Username '@" . htmlspecialchars($username) . "' sudah digunakan oleh pengguna lain.";
                }
            }

            // Cek upload foto baru
            if (empty($error_msg) && isset($_FILES['foto_profil']) && $_FILES['foto_profil']['error'] === UPLOAD_ERR_OK) {
                $file_tmp  = $_FILES['foto_profil']['tmp_name'];
                $file_name = $_FILES['foto_profil']['name'];
                $file_size = $_FILES['foto_profil']['size'];
                $file_ext  = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                $allowed_exts = ['jpg', 'jpeg', 'png', 'webp'];
                $max_size     = 2 * 1024 * 1024; // 2MB

                if (!in_array($file_ext, $allowed_exts)) {
                    $error_msg = "Format foto hanya diperbolehkan JPG, JPEG, PNG, atau WEBP.";
                } elseif ($file_size > $max_size) {
                    $error_msg = "Ukuran file foto profil maksimal 2 MB.";
                } else {
                    $upload_dir = '../assets/uploads/profile/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }

                    $foto_baru = 'admin_' . $user_id . '_' . time() . '.' . $file_ext;
                    $target_dest = $upload_dir . $foto_baru;

                    if (!move_uploaded_file($file_tmp, $target_dest)) {
                        $error_msg = "Gagal mengunggah foto profil ke server.";
                        $foto_baru = $foto_lama;
                    } else {
                        // Hapus file foto lama jika bukan default.png
                        if ($foto_lama !== 'default.png' && file_exists($upload_dir . $foto_lama)) {
                            @unlink($upload_dir . $foto_lama);
                        }
                    }
                }
            }

            // Eksekusi pembaruan ke database
            if (empty($error_msg)) {
                try {
                    $pdo->beginTransaction();

                    // Update tabel users
                    $stmt_u = $pdo->prepare("UPDATE users SET username = :u WHERE id = :uid");
                    $stmt_u->execute([':u' => $username, ':uid' => $user_id]);

                    // Update tabel admin_profil
                    $stmt_p = $pdo->prepare("
                        UPDATE admin_profil 
                        SET nama_lengkap = :nama,
                            email = :email,
                            no_whatsapp = :wa,
                            jabatan = :jabatan,
                            foto_profil = :foto
                        WHERE user_id = :uid
                    ");
                    $stmt_p->execute([
                        ':nama'    => $nama_lengkap,
                        ':email'   => $email,
                        ':wa'      => $no_whatsapp,
                        ':jabatan' => $jabatan,
                        ':foto'    => $foto_baru,
                        ':uid'     => $user_id
                    ]);

                    $pdo->commit();

                    // Sinkronisasi session aktif
                    $_SESSION['username']     = $username;
                    $_SESSION['nama_lengkap'] = $nama_lengkap;
                    $_SESSION['foto_profil']  = $foto_baru;

                    $success_msg = "Profil dan nama administrator berhasil diperbarui!";

                    // Refresh data admin
                    $stmt_admin->execute([':uid' => $user_id]);
                    $admin = $stmt_admin->fetch(PDO::FETCH_ASSOC);

                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error_msg = "Terjadi kesalahan saat menyimpan profil: " . $e->getMessage();
                }
            }
        }
    }

    // B. UPDATE KATA SANDI / PASSWORD
    elseif ($action === 'update_password') {
        $password_lama = $_POST['password_lama'] ?? '';
        $password_baru = $_POST['password_baru'] ?? '';
        $konfirmasi    = $_POST['konfirmasi_password'] ?? '';

        if (empty($password_lama) || empty($password_baru) || empty($konfirmasi)) {
            $error_msg = "Semua bidang kata sandi wajib diisi.";
        } elseif (!password_verify($password_lama, $admin['password'])) {
            $error_msg = "Kata sandi saat ini tidak cocok.";
        } elseif (strlen($password_baru) < 6) {
            $error_msg = "Kata sandi baru minimal harus 6 karakter.";
        } elseif ($password_baru !== $konfirmasi) {
            $error_msg = "Konfirmasi kata sandi baru tidak cocok.";
        } else {
            try {
                $hash_baru = password_hash($password_baru, PASSWORD_BCRYPT);
                $stmt_pw   = $pdo->prepare("UPDATE users SET password = :p WHERE id = :uid");
                $stmt_pw->execute([':p' => $hash_baru, ':uid' => $user_id]);

                $success_msg = "Kata sandi akun administrator berhasil diperbarui!";
                
                // Refresh data
                $stmt_admin->execute([':uid' => $user_id]);
                $admin = $stmt_admin->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $error_msg = "Gagal mengubah kata sandi: " . $e->getMessage();
            }
        }
    }
}

$page_title   = "Profil & Pengaturan Akun Admin";
$current_page = "profil";

require_once '../templates/header.php';
require_once '../templates/sidebar_admin.php';

// Persiapkan inisial nama untuk avatar fallback
$nama_tampil = $admin['nama_lengkap'] ?? 'Administrator';
$words = explode(' ', trim($nama_tampil));
$initials = '';
foreach (array_slice($words, 0, 2) as $w) {
    $initials .= strtoupper(substr($w, 0, 1));
}
if (empty($initials)) $initials = 'AD';

$foto_path = '../assets/uploads/profile/' . ($admin['foto_profil'] ?? 'default.png');
$has_foto = (!empty($admin['foto_profil']) && $admin['foto_profil'] !== 'default.png' && file_exists($foto_path));
?>

<!-- Alert Feedback -->
<?php if (!empty($success_msg)): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4 border-0 shadow-sm" role="alert">
        <i class="bi bi-check-circle-fill me-2 fs-5"></i>
        <div><?= htmlspecialchars($success_msg); ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (!empty($error_msg)): ?>
    <div class="alert alert-danger alert-dismissible fade show mb-4 border-0 shadow-sm" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
        <div><?= htmlspecialchars($error_msg); ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Hero Banner Admin -->
<div class="hero-banner mb-4">
    <div class="hero-tag">
        <i class="bi bi-person-gear"></i>
        <span>Profil & Identitas Administrator</span>
    </div>
    <h2 class="h3 fw-bold mb-1 text-white">Pengaturan Akun & Biodata Admin</h2>
    <p class="mb-0 text-white-50" style="max-width: 680px; font-size: 0.95rem;">
        Kelola nama lengkap resmi, username login, kontak dinas, foto profil, serta kata sandi akun administrator sistem bimbingan skripsi.
    </p>
</div>

<!-- Layout Dua Kolom: Ringkasan Profil (Kiri) & Formulir Pengaturan (Kanan) -->
<div class="row g-4">
    <!-- Kolom Kiri: Kartu Ringkasan Profil & Status Akun -->
    <div class="col-lg-4">
        <!-- Kartu Identitas Profil -->
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
            <div class="card-header border-0 py-4 text-center text-white" style="background: linear-gradient(135deg, #1e1b4b 0%, #312e81 60%, #4338ca 100%);">
                <!-- Avatar Preview -->
                <div class="position-relative d-inline-block mb-3">
                    <?php if ($has_foto): ?>
                        <img src="<?= htmlspecialchars($foto_path); ?>?t=<?= time(); ?>" 
                             alt="Foto Profil Admin" 
                             id="avatarPreviewImg"
                             class="rounded-circle border border-4 border-white shadow" 
                             style="width: 110px; height: 110px; object-fit: cover;">
                    <?php else: ?>
                        <div id="avatarFallback" class="rounded-circle border border-4 border-white shadow d-flex align-items-center justify-content-center text-white fw-bold fs-2" 
                             style="width: 110px; height: 110px; background: linear-gradient(135deg, #6366f1 0%, #4338ca 100%);">
                            <?= htmlspecialchars($initials); ?>
                        </div>
                        <img src="" alt="Preview" id="avatarPreviewImg" class="rounded-circle border border-4 border-white shadow d-none" style="width: 110px; height: 110px; object-fit: cover;">
                    <?php endif; ?>
                    <span class="position-absolute bottom-0 end-0 p-2 bg-success border border-2 border-white rounded-circle" title="Akun Aktif">
                        <span class="visually-hidden">Aktif</span>
                    </span>
                </div>
                <h5 class="fw-bold mb-1 text-white text-truncate px-2" id="previewNamaHeader">
                    <?= htmlspecialchars($admin['nama_lengkap'] ?? 'Administrator'); ?>
                </h5>
                <div class="text-white-50 small mb-2">@<?= htmlspecialchars($admin['username'] ?? 'admin'); ?></div>
                <span class="badge bg-white bg-opacity-20 text-white rounded-pill px-3 py-1 fw-semibold small border border-white border-opacity-25">
                    <i class="bi bi-shield-check me-1"></i>Administrator Utama
                </span>
            </div>
            
            <div class="card-body p-4">
                <div class="d-flex align-items-center justify-content-between p-2 px-3 rounded-3 bg-light mb-3">
                    <span class="text-muted small">Status Akun</span>
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                        <span class="status-dot active"></span>Terverifikasi Aktif
                    </span>
                </div>

                <div class="list-group list-group-flush small">
                    <div class="list-group-item d-flex justify-content-between px-0 py-2 border-bottom">
                        <span class="text-muted"><i class="bi bi-briefcase me-2 text-primary"></i>Jabatan / Unit</span>
                        <span class="fw-semibold text-dark text-end"><?= htmlspecialchars($admin['jabatan'] ?? 'Staf Akademik'); ?></span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between px-0 py-2 border-bottom">
                        <span class="text-muted"><i class="bi bi-envelope me-2 text-info"></i>Email Resmi</span>
                        <span class="fw-semibold text-dark text-end"><?= htmlspecialchars($admin['email'] ?? '-'); ?></span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between px-0 py-2 border-bottom">
                        <span class="text-muted"><i class="bi bi-whatsapp me-2 text-success"></i>WhatsApp</span>
                        <span class="fw-semibold text-dark text-end"><?= htmlspecialchars($admin['no_whatsapp'] ?? '-'); ?></span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between px-0 py-2 border-bottom">
                        <span class="text-muted"><i class="bi bi-calendar-check me-2 text-warning"></i>Terdaftar Sejak</span>
                        <span class="fw-semibold text-dark text-end">
                            <?= !empty($admin['user_created_at']) ? date('d M Y', strtotime($admin['user_created_at'])) : '-'; ?>
                        </span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between px-0 py-2">
                        <span class="text-muted"><i class="bi bi-key me-2 text-purple"></i>Hak Akses</span>
                        <span class="badge bg-primary rounded-pill">Penuh (Full Access)</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Kartu Tips Keamanan -->
        <div class="card border-0 shadow-sm rounded-4 p-4 bg-white">
            <h6 class="fw-bold text-dark mb-2 d-flex align-items-center">
                <i class="bi bi-info-circle-fill text-primary me-2"></i>Panduan Pengaturan Akun
            </h6>
            <p class="text-muted small mb-2">
                Nama lengkap yang Anda masukkan akan langsung ditampilkan pada banner ucapan selamat datang di navbar atas dan kop surat laporan akademik.
            </p>
            <p class="text-muted small mb-0">
                Gunakan kombinasi huruf, angka, dan simbol untuk kata sandi baru demi menjaga keamanan sistem dan data master mahasiswa.
            </p>
        </div>
    </div>

    <!-- Kolom Kanan: Formulir Edit Profil & Ganti Kata Sandi -->
    <div class="col-lg-8">
        <!-- FORM 1: BIODATA & NAMA LENGKAP -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-bottom py-3 px-4 d-flex align-items-center justify-content-between">
                <div>
                    <h5 class="fw-bold text-dark mb-0">
                        <i class="bi bi-person-lines-fill text-primary me-2"></i>Biodata & Nama Administrator
                    </h5>
                    <small class="text-muted">Perbarui nama tampilan, kontak resmi, dan foto profil Anda</small>
                </div>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-1">Identitas</span>
            </div>
            <div class="card-body p-4">
                <form action="profil.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_profil">

                    <div class="row g-3">
                        <!-- Nama Lengkap & Gelar -->
                        <div class="col-md-12">
                            <label for="nama_lengkap" class="form-label fw-semibold small text-dark">
                                Nama Lengkap & Gelar Administrator <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-person-badge"></i></span>
                                <input type="text" class="form-control border-start-0" id="nama_lengkap" name="nama_lengkap" 
                                       value="<?= htmlspecialchars($admin['nama_lengkap'] ?? ''); ?>" 
                                       required placeholder="Contoh: Talita Nabila, S.Kom. atau Administrator Utama">
                            </div>
                            <div class="form-text small">Nama ini akan ditampilkan pada navbar sistem, kop laporan cetak, dan riwayat aktivitas.</div>
                        </div>

                        <!-- Username (Login ID) -->
                        <div class="col-md-6">
                            <label for="username" class="form-label fw-semibold small text-dark">
                                Username (ID Login) <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-at"></i></span>
                                <input type="text" class="form-control border-start-0" id="username" name="username" 
                                       value="<?= htmlspecialchars($admin['username'] ?? ''); ?>" 
                                       required placeholder="admin">
                            </div>
                            <div class="form-text small">Digunakan untuk masuk ke sistem (tanpa spasi).</div>
                        </div>

                        <!-- Unit / Jabatan -->
                        <div class="col-md-6">
                            <label for="jabatan" class="form-label fw-semibold small text-dark">
                                Unit Kerja / Jabatan
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-building"></i></span>
                                <input type="text" class="form-control border-start-0" id="jabatan" name="jabatan" 
                                       value="<?= htmlspecialchars($admin['jabatan'] ?? ''); ?>" 
                                       placeholder="Contoh: Bagian Administrasi Akademik & Skripsi">
                            </div>
                        </div>

                        <!-- Email Resmi -->
                        <div class="col-md-6">
                            <label for="email" class="form-label fw-semibold small text-dark">
                                Email Resmi / Dinas
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-envelope-at"></i></span>
                                <input type="email" class="form-control border-start-0" id="email" name="email" 
                                       value="<?= htmlspecialchars($admin['email'] ?? ''); ?>" 
                                       placeholder="admin@univ.ac.id">
                            </div>
                        </div>

                        <!-- No. WhatsApp / Telepon -->
                        <div class="col-md-6">
                            <label for="no_whatsapp" class="form-label fw-semibold small text-dark">
                                No. WhatsApp / Telepon
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-telephone"></i></span>
                                <input type="text" class="form-control border-start-0" id="no_whatsapp" name="no_whatsapp" 
                                       value="<?= htmlspecialchars($admin['no_whatsapp'] ?? ''); ?>" 
                                       placeholder="081234567890">
                            </div>
                        </div>

                        <!-- Upload Foto Profil Baru -->
                        <div class="col-md-12">
                            <label for="foto_profil" class="form-label fw-semibold small text-dark">
                                Unggah Foto Profil Baru (Opsional)
                            </label>
                            <input class="form-control" type="file" id="foto_profil" name="foto_profil" accept="image/png, image/jpeg, image/jpg, image/webp">
                            <div class="form-text small">
                                Format: JPG, PNG, atau WEBP. Ukuran file maksimal 2 MB. Disarankan foto formal rasio 1:1.
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 pt-3 border-top d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary px-4 d-inline-flex align-items-center gap-2">
                            <i class="bi bi-check2-circle fs-5"></i>
                            <span>Simpan Profil Administrator</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- FORM 2: KEAMANAN & UBAH KATA SANDI -->
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-bottom py-3 px-4 d-flex align-items-center justify-content-between">
                <div>
                    <h5 class="fw-bold text-dark mb-0">
                        <i class="bi bi-shield-lock text-danger me-2"></i>Keamanan & Kata Sandi
                    </h5>
                    <small class="text-muted">Perbarui kata sandi akun administrator untuk menjaga keamanan sistem</small>
                </div>
                <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-1">Privasi</span>
            </div>
            <div class="card-body p-4">
                <form action="profil.php" method="POST">
                    <input type="hidden" name="action" value="update_password">

                    <div class="row g-3">
                        <!-- Password Saat Ini -->
                        <div class="col-md-12">
                            <label for="password_lama" class="form-label fw-semibold small text-dark">
                                Kata Sandi Saat Ini <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-lock"></i></span>
                                <input type="password" class="form-control border-start-0 border-end-0" id="password_lama" name="password_lama" required placeholder="Masukkan kata sandi lama Anda">
                                <button class="btn btn-outline-secondary border-start-0 toggle-pw" type="button" data-target="password_lama">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Password Baru -->
                        <div class="col-md-6">
                            <label for="password_baru" class="form-label fw-semibold small text-dark">
                                Kata Sandi Baru <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-shield-plus"></i></span>
                                <input type="password" class="form-control border-start-0 border-end-0" id="password_baru" name="password_baru" required minlength="6" placeholder="Minimal 6 karakter">
                                <button class="btn btn-outline-secondary border-start-0 toggle-pw" type="button" data-target="password_baru">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Konfirmasi Password Baru -->
                        <div class="col-md-6">
                            <label for="konfirmasi_password" class="form-label fw-semibold small text-dark">
                                Konfirmasi Kata Sandi Baru <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-shield-check"></i></span>
                                <input type="password" class="form-control border-start-0 border-end-0" id="konfirmasi_password" name="konfirmasi_password" required minlength="6" placeholder="Ulangi kata sandi baru">
                                <button class="btn btn-outline-secondary border-start-0 toggle-pw" type="button" data-target="konfirmasi_password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 pt-3 border-top d-flex justify-content-end">
                        <button type="submit" class="btn btn-danger px-4 d-inline-flex align-items-center gap-2">
                            <i class="bi bi-key-fill fs-5"></i>
                            <span>Perbarui Kata Sandi</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript untuk Preview Foto Profil & Toggle Password Visibility -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    // 1. Preview Foto Profil Langsung Saat Dipilih
    const inputFoto = document.getElementById('foto_profil');
    const previewImg = document.getElementById('avatarPreviewImg');
    const fallbackBox = document.getElementById('avatarFallback');

    if (inputFoto && previewImg) {
        inputFoto.addEventListener('change', function (e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function (evt) {
                    previewImg.src = evt.target.result;
                    previewImg.classList.remove('d-none');
                    if (fallbackBox) {
                        fallbackBox.classList.add('d-none');
                    }
                };
                reader.readAsDataURL(file);
            }
        });
    }

    // 2. Real-time Preview Nama Lengkap di Kartu Kiri
    const inputNama = document.getElementById('nama_lengkap');
    const previewNamaHeader = document.getElementById('previewNamaHeader');
    if (inputNama && previewNamaHeader) {
        inputNama.addEventListener('input', function () {
            previewNamaHeader.textContent = this.value.trim() !== '' ? this.value : 'Administrator';
        });
    }

    // 3. Toggle Visibility Password
    document.querySelectorAll('.toggle-pw').forEach(function (button) {
        button.addEventListener('click', function () {
            const targetId = this.getAttribute('data-target');
            const targetInput = document.getElementById(targetId);
            const icon = this.querySelector('i');
            if (targetInput) {
                if (targetInput.type === 'password') {
                    targetInput.type = 'text';
                    icon.classList.remove('bi-eye');
                    icon.classList.add('bi-eye-slash');
                } else {
                    targetInput.type = 'password';
                    icon.classList.remove('bi-eye-slash');
                    icon.classList.add('bi-eye');
                }
            }
        });
    });
});
</script>

<?php require_once '../templates/footer.php'; ?>

