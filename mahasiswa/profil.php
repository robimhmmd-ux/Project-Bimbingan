<?php
// mahasiswa/profil.php - Manajemen Profil & Data Diri Mahasiswa
$page_title   = "Profil Mahasiswa";
$current_page = "profil";

require_once '../config/database.php';
require_once '../templates/header.php';
require_once '../templates/sidebar_mahasiswa.php';

// Proteksi akses role mahasiswa
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'mahasiswa') {
    header("Location: ../login.php");
    exit;
}

$my_user_id = $_SESSION['user_id'];
$success_msg = '';
$error_msg   = '';

// Ambil data profil mahasiswa saat ini
$stmt_mhs = $pdo->prepare("
    SELECT m.*, u.username, d.nama_dosen, d.nidn 
    FROM mahasiswa m 
    JOIN users u ON m.user_id = u.id 
    LEFT JOIN dosen d ON m.dosen_pembimbing_id = d.id 
    WHERE m.user_id = :uid LIMIT 1
");
$stmt_mhs->execute([':uid' => $my_user_id]);
$mhs = $stmt_mhs->fetch();

if (!$mhs) {
    die("Data profil mahasiswa tidak ditemukan di sistem.");
}

$mhs_id = $mhs['id'];

// Handle Update Profil & Foto (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Update Data Diri & Foto Profil
    if ($action === 'update_profil') {
        $nama_lengkap = trim($_POST['nama_lengkap'] ?? '');
        $nim          = trim($_POST['nim'] ?? '');
        $judul_skripsi = trim($_POST['judul_skripsi'] ?? '');
        $foto_baru    = $mhs['foto_profil'];

        if (empty($nama_lengkap) || empty($nim)) {
            $error_msg = "Nama lengkap dan NIM wajib diisi.";
        } else {
            // Cek jika ada unggahan foto profil baru
            if (isset($_FILES['foto_profil']) && $_FILES['foto_profil']['error'] === UPLOAD_ERR_OK) {
                $file_tmp  = $_FILES['foto_profil']['tmp_name'];
                $file_name = $_FILES['foto_profil']['name'];
                $file_size = $_FILES['foto_profil']['size'];
                $ext       = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                $allowed_exts = ['jpg', 'jpeg', 'png', 'webp'];
                if (!in_array($ext, $allowed_exts)) {
                    $error_msg = "Format foto profil harus JPG, JPEG, PNG, atau WEBP.";
                } elseif ($file_size > 2 * 1024 * 1024) {
                    $error_msg = "Ukuran file foto profil maksimal 2 MB.";
                } else {
                    $upload_dir = '../assets/uploads/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    $foto_name = 'profil_' . $mhs_id . '_' . uniqid() . '.' . $ext;
                    if (move_uploaded_file($file_tmp, $upload_dir . $foto_name)) {
                        $foto_baru = $foto_name;
                    } else {
                        $error_msg = "Gagal mengunggah foto profil ke server.";
                    }
                }
            }

            if (empty($error_msg)) {
                try {
                    $stmt_update = $pdo->prepare("
                        UPDATE mahasiswa 
                        SET nama_lengkap = :nama, nim = :nim, judul_skripsi = :judul, foto_profil = :foto 
                        WHERE id = :mid
                    ");
                    $stmt_update->execute([
                        ':nama'  => $nama_lengkap,
                        ':nim'   => $nim,
                        ':judul' => $judul_skripsi,
                        ':foto'  => $foto_baru,
                        ':mid'   => $mhs_id
                    ]);

                    $success_msg = "Data profil Anda berhasil diperbarui!";
                    
                    // Refresh data mhs
                    $stmt_mhs->execute([':uid' => $my_user_id]);
                    $mhs = $stmt_mhs->fetch();
                } catch (PDOException $e) {
                    $error_msg = "Gagal memperbarui profil: " . $e->getMessage();
                }
            }
        }
    }

    // 2. Ganti Password Akun
    elseif ($action === 'ganti_password') {
        $password_lama = trim($_POST['password_lama'] ?? '');
        $password_baru = trim($_POST['password_baru'] ?? '');
        $konfirmasi_pw = trim($_POST['konfirmasi_password'] ?? '');

        if (empty($password_lama) || empty($password_baru) || empty($konfirmasi_pw)) {
            $error_msg = "Semua bidang password wajib diisi.";
        } elseif (strlen($password_baru) < 6) {
            $error_msg = "Password baru minimal terdiri dari 6 karakter.";
        } elseif ($password_baru !== $konfirmasi_pw) {
            $error_msg = "Konfirmasi password baru tidak cocok.";
        } else {
            // Verifikasi password lama
            $stmt_u = $pdo->prepare("SELECT password FROM users WHERE id = :uid LIMIT 1");
            $stmt_u->execute([':uid' => $my_user_id]);
            $current_hash = $stmt_u->fetchColumn();

            if (!password_verify($password_lama, $current_hash)) {
                $error_msg = "Password lama yang Anda masukkan salah.";
            } else {
                try {
                    $hash_baru = password_hash($password_baru, PASSWORD_BCRYPT);
                    $stmt_pw = $pdo->prepare("UPDATE users SET password = :pw WHERE id = :uid");
                    $stmt_pw->execute([':pw' => $hash_baru, ':uid' => $my_user_id]);
                    $success_msg = "Password akun berhasil diubah. Silakan gunakan password baru pada sesi masuk berikutnya.";
                } catch (PDOException $e) {
                    $error_msg = "Gagal mengganti password: " . $e->getMessage();
                }
            }
        }
    }
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2 mb-0">Profil & Data Diri Mahasiswa</h1>
        <p class="text-muted small mb-0">Kelola informasi pribadi, judul tugas akhir, dan foto profil akun Anda.</p>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="index.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-speedometer2 me-1"></i>Ke Dashboard
        </a>
    </div>
</div>

<?php if (!empty($success_msg)): ?>
    <div class="alert alert-success alert-dismissible fade show py-2 small" role="alert">
        <i class="bi bi-check-circle-fill me-1"></i><?= htmlspecialchars($success_msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!empty($error_msg)): ?>
    <div class="alert alert-danger alert-dismissible fade show py-2 small" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-1"></i><?= htmlspecialchars($error_msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <!-- Kartu Tampilan Profil Samping -->
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white py-3 border-bottom text-center">
                <h6 class="m-0 fw-bold text-dark">
                    <i class="bi bi-person-bounding-box text-primary me-2"></i>Kartu Identitas
                </h6>
            </div>
            <div class="card-body text-center p-4">
                <div class="mb-3 position-relative d-inline-block">
                    <?php if (!empty($mhs['foto_profil']) && $mhs['foto_profil'] !== 'default.png' && file_exists('../assets/uploads/' . $mhs['foto_profil'])): ?>
                        <img src="../assets/uploads/<?= htmlspecialchars($mhs['foto_profil']); ?>" class="rounded-circle img-thumbnail shadow-sm" width="130" height="130" style="object-fit: cover;" alt="Foto Profil">
                    <?php else: ?>
                        <div class="bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center fw-bold shadow-sm" style="width: 120px; height: 120px; font-size: 3rem;">
                            <?= strtoupper(substr($mhs['nama_lengkap'] ?? 'M', 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <h5 class="fw-bold text-dark mb-1"><?= htmlspecialchars($mhs['nama_lengkap']); ?></h5>
                <p class="text-muted small mb-2">NIM: <strong><?= htmlspecialchars($mhs['nim']); ?></strong></p>

                <div class="mb-3">
                    <?php
                    $sk = $mhs['status_kelulusan'] ?? 'proses_bimbingan';
                    $bg_sk = 'warning text-dark';
                    if ($sk === 'siap_sidang') $bg_sk = 'info text-white';
                    elseif ($sk === 'lulus') $bg_sk = 'success';
                    ?>
                    <span class="badge bg-<?= $bg_sk; ?> px-3 py-1">
                        <?= ucfirst(str_replace('_', ' ', $sk)); ?>
                    </span>
                </div>

                <div class="bg-light p-3 rounded text-start border small mb-3">
                    <div class="mb-2">
                        <span class="text-muted d-block">Username Login:</span>
                        <span class="fw-semibold text-dark">@<?= htmlspecialchars($mhs['username']); ?></span>
                    </div>
                    <div class="mb-2">
                        <span class="text-muted d-block">Dosen Pembimbing:</span>
                        <span class="fw-semibold text-dark"><?= htmlspecialchars($mhs['nama_dosen'] ?? 'Belum Diplot'); ?></span>
                    </div>
                    <div>
                        <span class="text-muted d-block">Terdaftar Sejak:</span>
                        <span class="text-secondary"><?= date('d F Y', strtotime($mhs['created_at'])); ?></span>
                    </div>
                </div>

                <div class="border-top pt-3">
                    <span class="small text-muted d-block mb-1">Judul Skripsi Saat Ini:</span>
                    <p class="small fw-semibold text-dark fst-italic mb-0">
                        "<?= htmlspecialchars($mhs['judul_skripsi'] ?? 'Belum Ditentukan'); ?>"
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Formulir Edit Data Diri & Ganti Password -->
    <div class="col-lg-8">
        <!-- Form Data Diri & Foto -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white py-3 border-bottom">
                <h6 class="m-0 fw-bold text-dark">
                    <i class="bi bi-pencil-square text-primary me-2"></i>Perbarui Data Diri & Foto Profil
                </h6>
            </div>
            <div class="card-body p-4">
                <form action="profil.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_profil">

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="nama_lengkap" class="form-label small fw-semibold text-secondary">Nama Lengkap <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="nama_lengkap" name="nama_lengkap" value="<?= htmlspecialchars($mhs['nama_lengkap']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="nim" class="form-label small fw-semibold text-secondary">Nomor Induk Mahasiswa (NIM) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="nim" name="nim" value="<?= htmlspecialchars($mhs['nim']); ?>" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="judul_skripsi" class="form-label small fw-semibold text-secondary">Judul Tugas Akhir / Skripsi</label>
                        <textarea class="form-control" id="judul_skripsi" name="judul_skripsi" rows="3" placeholder="Masukkan judul skripsi lengkap..."><?= htmlspecialchars($mhs['judul_skripsi'] ?? ''); ?></textarea>
                        <div class="form-text small" style="font-size: 0.75rem;">
                            Pastikan judul selalu diperbarui sesuai dengan kesepakatan bersama dosen pembimbing.
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="foto_profil" class="form-label small fw-semibold text-secondary">Unggah Foto Profil Baru</label>
                        <input class="form-control" type="file" id="foto_profil" name="foto_profil" accept="image/jpeg,image/png,image/jpg,image/webp">
                        <div class="form-text small" style="font-size: 0.75rem;">
                            Format file didukung: JPG, PNG, WEBP. Ukuran maksimal <strong>2 MB</strong>.
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary px-4 fw-semibold">
                        <i class="bi bi-save me-1"></i>Simpan Perubahan Profil
                    </button>
                </form>
            </div>
        </div>

        <!-- Form Ganti Password -->
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 border-bottom">
                <h6 class="m-0 fw-bold text-dark">
                    <i class="bi bi-key-fill text-warning me-2"></i>Keamanan Akun (Ganti Password)
                </h6>
            </div>
            <div class="card-body p-4">
                <form action="profil.php" method="POST">
                    <input type="hidden" name="action" value="ganti_password">

                    <div class="mb-3">
                        <label for="password_lama" class="form-label small fw-semibold text-secondary">Password Lama <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="password_lama" name="password_lama" placeholder="Masukkan password saat ini" required>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="password_baru" class="form-label small fw-semibold text-secondary">Password Baru <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="password_baru" name="password_baru" placeholder="Minimal 6 karakter" required minlength="6">
                        </div>
                        <div class="col-md-6">
                            <label for="konfirmasi_password" class="form-label small fw-semibold text-secondary">Ulangi Password Baru <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="konfirmasi_password" name="konfirmasi_password" placeholder="Ulangi password baru" required minlength="6">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-outline-warning text-dark px-4 fw-semibold">
                        <i class="bi bi-shield-lock me-1"></i>Perbarui Password
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
require_once '../templates/footer.php';
?>

