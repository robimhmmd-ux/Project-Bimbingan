<?php
// dosen/profil.php - Edit Akun, Biodata Profil & Foto Profil Dosen (Salsa)
$page_title   = "Profil & Pengaturan Akun";
$current_page = "profil";

require_once '../config/database.php';
require_once '../templates/header.php';
require_once '../templates/sidebar_dosen.php';

// Proteksi akses role dosen
if ($_SESSION['role'] !== 'dosen') {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$success_msg = '';
$error_msg   = '';

// Ambil data dosen & user aktif
$stmt_dosen = $pdo->prepare("SELECT d.*, u.username FROM dosen d JOIN users u ON d.user_id = u.id WHERE d.user_id = :uid LIMIT 1");
$stmt_dosen->execute([':uid' => $user_id]);
$dosen = $stmt_dosen->fetch();
$dosen_id = $dosen['id'] ?? 0;

// 1. PROSES UPDATE BIODATA & FOTO PROFIL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profil'])) {
    $nama_dosen   = trim($_POST['nama_dosen'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $no_whatsapp  = trim($_POST['no_whatsapp'] ?? '');
    $kuota        = (int)($_POST['kuota_bimbingan'] ?? 10);

    if (empty($nama_dosen) || empty($email)) {
        $error_msg = "Nama lengkap dan email tidak boleh kosong.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_msg = "Format email tidak valid.";
    } else {
        $foto_nama_baru = $dosen['foto_profil'] ?? 'default.png';

        // Cek apakah ada upload foto baru
        if (isset($_FILES['foto_profil']) && $_FILES['foto_profil']['error'] === UPLOAD_ERR_OK) {
            $file_tmp  = $_FILES['foto_profil']['tmp_name'];
            $file_name = $_FILES['foto_profil']['name'];
            $file_size = $_FILES['foto_profil']['size'];
            $file_ext  = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            $allowed_ext = ['jpg', 'jpeg', 'png', 'webp'];
            $max_size    = 2 * 1024 * 1024; // 2MB

            if (!in_array($file_ext, $allowed_ext)) {
                $error_msg = "Format foto hanya diperbolehkan JPG, JPEG, PNG, atau WEBP.";
            } elseif ($file_size > $max_size) {
                $error_msg = "Ukuran foto maksimal 2 MB.";
            } else {
                $upload_dir = '../assets/uploads/profile/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }

                $foto_nama_baru = 'dosen_' . $user_id . '_' . time() . '.' . $file_ext;
                $target_file = $upload_dir . $foto_nama_baru;

                if (!move_uploaded_file($file_tmp, $target_file)) {
                    $error_msg = "Gagal mengunggah foto profil.";
                }
            }
        }

        if (empty($error_msg)) {
            try {
                $stmt_up = $pdo->prepare("
                    UPDATE dosen 
                    SET nama_dosen = :nama, 
                        email = :email, 
                        no_whatsapp = :wa, 
                        kuota_bimbingan = :kuota,
                        foto_profil = :foto 
                    WHERE user_id = :uid
                ");
                $stmt_up->execute([
                    ':nama'  => $nama_dosen,
                    ':email' => $email,
                    ':wa'    => $no_whatsapp,
                    ':kuota' => $kuota,
                    ':foto'  => $foto_nama_baru,
                    ':uid'   => $user_id
                ]);

                $success_msg = "Biodata profil dosen berhasil diperbarui!";
                
                // Refresh data
                $stmt_dosen->execute([':uid' => $user_id]);
                $dosen = $stmt_dosen->fetch();
            } catch (PDOException $e) {
                $error_msg = "Terjadi kesalahan saat menyimpan profil: " . $e->getMessage();
            }
        }
    }
}

// 2. PROSES GANTI PASSWORD AKUN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_password'])) {
    $pass_lama = $_POST['pass_lama'] ?? '';
    $pass_baru = $_POST['pass_baru'] ?? '';
    $pass_konf = $_POST['pass_konf'] ?? '';

    if (empty($pass_lama) || empty($pass_baru) || empty($pass_konf)) {
        $error_msg = "Semua kolom password wajib diisi.";
    } elseif (strlen($pass_baru) < 6) {
        $error_msg = "Password baru minimal 6 karakter.";
    } elseif ($pass_baru !== $pass_konf) {
        $error_msg = "Konfirmasi password baru tidak cocok.";
    } else {
        // Cek password lama di database
        $stmt_user = $pdo->prepare("SELECT password FROM users WHERE id = :uid LIMIT 1");
        $stmt_user->execute([':uid' => $user_id]);
        $curr_user = $stmt_user->fetch();

        if ($curr_user && password_verify($pass_lama, $curr_user['password'])) {
            try {
                $hash_baru = password_hash($pass_baru, PASSWORD_BCRYPT);
                $stmt_pass = $pdo->prepare("UPDATE users SET password = :pwd WHERE id = :uid");
                $stmt_pass->execute([':pwd' => $hash_baru, ':uid' => $user_id]);
                $success_msg = "Kata sandi akun Anda berhasil diperbarui!";
            } catch (PDOException $e) {
                $error_msg = "Gagal memperbarui password: " . $e->getMessage();
            }
        } else {
            $error_msg = "Password saat ini (lama) yang Anda masukkan salah.";
        }
    }
}

// Tentukan path foto profil
$foto_path = '../assets/uploads/profile/' . ($dosen['foto_profil'] ?? '');
$has_foto  = !empty($dosen['foto_profil']) && $dosen['foto_profil'] !== 'default.png' && file_exists($foto_path);
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2 fw-bold">Profil & Pengaturan Akun</h1>
        <p class="text-muted small mb-0">Kelola informasi biodata akademik, perbarui foto profil, dan ubah kata sandi akun dosen Anda.</p>
    </div>
</div>

<!-- Notifikasi Alert -->
<?php if (!empty($success_msg)): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($success_msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (!empty($error_msg)): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error_msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- Kartu Info Foto Profil -->
    <div class="col-lg-4">
        <div class="card shadow-sm border-0 text-center p-4">
            <div class="mb-3 position-relative d-inline-block">
                <?php if ($has_foto): ?>
                    <img src="<?= htmlspecialchars($foto_path); ?>" 
                         alt="Foto Profil Dosen" 
                         class="rounded-circle img-thumbnail shadow-sm object-fit-cover" 
                         style="width: 150px; height: 150px;">
                <?php else: ?>
                    <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center mx-auto shadow-sm" 
                         style="width: 140px; height: 140px; font-size: 3.5rem;">
                        <i class="bi bi-person-fill"></i>
                    </div>
                <?php endif; ?>
            </div>
            
            <h5 class="fw-bold mb-1"><?= htmlspecialchars($dosen['nama_dosen'] ?? 'Nama Dosen'); ?></h5>
            <p class="text-muted small mb-2">NIDN: <?= htmlspecialchars($dosen['nidn'] ?? '-'); ?></p>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-1 mb-3">
                Dosen Pembimbing Skripsi
            </span>

            <div class="list-group list-group-flush text-start small border-top pt-2">
                <div class="list-group-item d-flex justify-content-between px-0">
                    <span class="text-muted"><i class="bi bi-person me-1"></i> Username:</span>
                    <strong><?= htmlspecialchars($dosen['username'] ?? '-'); ?></strong>
                </div>
                <div class="list-group-item d-flex justify-content-between px-0">
                    <span class="text-muted"><i class="bi bi-envelope me-1"></i> Email:</span>
                    <span><?= htmlspecialchars($dosen['email'] ?? '-'); ?></span>
                </div>
                <div class="list-group-item d-flex justify-content-between px-0">
                    <span class="text-muted"><i class="bi bi-whatsapp me-1"></i> WhatsApp:</span>
                    <span><?= htmlspecialchars($dosen['no_whatsapp'] ?? '-'); ?></span>
                </div>
                <div class="list-group-item d-flex justify-content-between px-0">
                    <span class="text-muted"><i class="bi bi-people me-1"></i> Kuota Bimbingan:</span>
                    <strong><?= htmlspecialchars($dosen['kuota_bimbingan'] ?? '10'); ?> Mahasiswa</strong>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab Formulir Edit Profil & Ganti Password -->
    <div class="col-lg-8">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 border-bottom">
                <ul class="nav nav-tabs card-header-tabs" id="profileTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active fw-bold" id="biodata-tab" data-bs-toggle="tab" data-bs-target="#biodata-pane" type="button" role="tab">
                            <i class="bi bi-person-lines-fill me-1"></i> Edit Biodata & Foto
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link fw-bold" id="password-tab" data-bs-toggle="tab" data-bs-target="#password-pane" type="button" role="tab">
                            <i class="bi bi-key me-1"></i> Ganti Password Akun
                        </button>
                    </li>
                </ul>
            </div>
            <div class="card-body p-4">
                <div class="tab-content" id="profileTabContent">
                    <!-- Tab 1: Form Biodata -->
                    <div class="tab-pane fade show active" id="biodata-pane" role="tabpanel">
                        <form action="profil.php" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="update_profil" value="1">

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">NIDN (Nomor Induk Dosen)</label>
                                    <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($dosen['nidn'] ?? ''); ?>" readonly>
                                    <div class="form-text small">NIDN dikelola oleh Administrator sistem.</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="nama_dosen" class="form-label fw-semibold">Nama Lengkap & Gelar <span class="text-danger">*</span></label>
                                    <input type="text" name="nama_dosen" id="nama_dosen" class="form-control" value="<?= htmlspecialchars($dosen['nama_dosen'] ?? ''); ?>" required>
                                </div>

                                <div class="col-md-6">
                                    <label for="email" class="form-label fw-semibold">Alamat Email <span class="text-danger">*</span></label>
                                    <input type="email" name="email" id="email" class="form-control" value="<?= htmlspecialchars($dosen['email'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="no_whatsapp" class="form-label fw-semibold">Nomor WhatsApp Aktif</label>
                                    <input type="text" name="no_whatsapp" id="no_whatsapp" class="form-control" value="<?= htmlspecialchars($dosen['no_whatsapp'] ?? ''); ?>" placeholder="08xxxxxxxxxx">
                                </div>

                                <div class="col-md-6">
                                    <label for="kuota_bimbingan" class="form-label fw-semibold">Kapasitas Kuota Mahasiswa</label>
                                    <input type="number" name="kuota_bimbingan" id="kuota_bimbingan" class="form-control" min="1" max="50" value="<?= (int)($dosen['kuota_bimbingan'] ?? 10); ?>">
                                </div>

                                <div class="col-md-6">
                                    <label for="foto_profil" class="form-label fw-semibold">Unggah Foto Profil Baru</label>
                                    <input type="file" name="foto_profil" id="foto_profil" class="form-control" accept="image/png, image/jpeg, image/jpg, image/webp">
                                    <div class="form-text small">Format: JPG, PNG, WEBP (Maksimal 2 MB).</div>
                                </div>
                            </div>

                            <div class="mt-4 pt-2 border-top text-end">
                                <button type="submit" class="btn btn-primary px-4 fw-semibold">
                                    <i class="bi bi-save me-1"></i> Simpan Perubahan Biodata
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Tab 2: Form Ganti Password -->
                    <div class="tab-pane fade" id="password-pane" role="tabpanel">
                        <form action="profil.php" method="POST">
                            <input type="hidden" name="update_password" value="1">

                            <div class="mb-3">
                                <label for="pass_lama" class="form-label fw-semibold">Password Saat Ini (Lama) <span class="text-danger">*</span></label>
                                <input type="password" name="pass_lama" id="pass_lama" class="form-control" placeholder="Masukkan password saat ini..." required>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label for="pass_baru" class="form-label fw-semibold">Password Baru <span class="text-danger">*</span></label>
                                    <input type="password" name="pass_baru" id="pass_baru" class="form-control" minlength="6" placeholder="Minimal 6 karakter..." required>
                                </div>
                                <div class="col-md-6">
                                    <label for="pass_konf" class="form-label fw-semibold">Konfirmasi Password Baru <span class="text-danger">*</span></label>
                                    <input type="password" name="pass_konf" id="pass_konf" class="form-control" minlength="6" placeholder="Ulangi password baru..." required>
                                </div>
                            </div>

                            <div class="mt-4 pt-2 border-top text-end">
                                <button type="submit" class="btn btn-warning px-4 fw-semibold">
                                    <i class="bi bi-key-fill me-1"></i> Perbarui Kata Sandi
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once '../templates/footer.php';
?>

