<?php
// admin/profil.php - Manajemen Profil, Nama Lengkap & Manajemen Akun Administrator
require_once __DIR__ . '/../config/database.php';

// Proteksi akses role admin (wajib sebelum ada output HTML)
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login");
    exit;
}
if (file_exists(__DIR__ . '/../config/avatar_helper.php')) {
    require_once __DIR__ . '/../config/avatar_helper.php';
}

$user_id     = (int)$_SESSION['user_id'];
$success_msg = '';
$error_msg   = '';

// 1. Pastikan tabel admin_profil tersedia di database
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_profil (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL UNIQUE,
            nama_lengkap VARCHAR(100) NOT NULL DEFAULT 'Administrator Utama',
            email VARCHAR(100) DEFAULT 'admin@univ.ac.id',
            no_whatsapp VARCHAR(20) DEFAULT '081234567890',
            foto_profil VARCHAR(255) DEFAULT 'default.png',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB;
    ");
} catch (Exception $e) {}

// 2. Ambil data profil admin yang sedang login
$stmt_admin = $pdo->prepare("
    SELECT u.id AS uid, u.username, u.password, u.created_at AS user_created_at,
           p.id AS pid, p.nama_lengkap, p.email, p.no_whatsapp, p.foto_profil
    FROM users u
    LEFT JOIN admin_profil p ON u.id = p.user_id
    WHERE u.id = :uid LIMIT 1
");
$stmt_admin->execute([':uid' => $user_id]);
$admin = $stmt_admin->fetch(PDO::FETCH_ASSOC);

// Jika profil belum ada barisnya di admin_profil, lakukan auto-insert default
if ($admin && empty($admin['pid'])) {
    try {
        $stmt_init = $pdo->prepare("
            INSERT INTO admin_profil (user_id, nama_lengkap, email, no_whatsapp, foto_profil)
            VALUES (:uid, 'Administrator Utama', 'admin@univ.ac.id', '081234567890', 'default.png')
        ");
        $stmt_init->execute([':uid' => $user_id]);

        // Refresh fetch
        $stmt_admin->execute([':uid' => $user_id]);
        $admin = $stmt_admin->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// 3. PROSES AKSI FORM (POST REQUEST)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    // =========================================================================
    // A. UPDATE BIODATA, NAMA LENGKAP & FOTO PROFIL ADMIN SENDIRI
    // =========================================================================
    if ($action === 'update_profil') {
        $nama_lengkap = trim($_POST['nama_lengkap'] ?? '');
        $username     = trim($_POST['username'] ?? '');
        $email        = trim($_POST['email'] ?? '');
        $no_whatsapp  = trim($_POST['no_whatsapp'] ?? '');
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
                $stmt_cek_u = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(username) = LOWER(:u) AND id != :uid");
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
                    $mime_type = ($file_ext === 'png') ? 'image/png' : (($file_ext === 'webp') ? 'image/webp' : 'image/jpeg');
                    $raw_bytes = @file_get_contents($file_tmp);
                    if ($raw_bytes !== false) {
                        $foto_baru = 'data:' . $mime_type . ';base64,' . base64_encode($raw_bytes);
                    } else {
                        $error_msg = "Gagal memproses berkas foto profil.";
                        $foto_baru = $foto_lama;
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

                    // Hapus berkas foto lama jika mengunggah foto profil baru
                    if (!empty($foto_baru) && $foto_baru !== $admin['foto_profil'] && function_exists('delete_old_profile_photo')) {
                        delete_old_profile_photo($admin['foto_profil'] ?? null);
                    }

                    // Update tabel admin_profil
                    $stmt_p = $pdo->prepare("
                        UPDATE admin_profil 
                        SET nama_lengkap = :nama,
                            email = :email,
                            no_whatsapp = :wa,
                            foto_profil = :foto
                        WHERE user_id = :uid
                    ");
                    $stmt_p->execute([
                        ':nama'    => $nama_lengkap,
                        ':email'   => $email,
                        ':wa'      => $no_whatsapp,
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

    // =========================================================================
    // B. UPDATE KATA SANDI / PASSWORD
    // =========================================================================
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
                $stmt_pw   = $pdo->prepare("UPDATE users SET password = :p, password_plain = :pp WHERE id = :uid");
                $stmt_pw->execute([':p' => $hash_baru, ':pp' => $password_baru, ':uid' => $user_id]);

                $success_msg = "Kata sandi akun administrator berhasil diperbarui!";
                
                // Refresh data
                $stmt_admin->execute([':uid' => $user_id]);
                $admin = $stmt_admin->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $error_msg = "Gagal mengubah kata sandi: " . $e->getMessage();
            }
        }
    }

    // =========================================================================
    // C. TAMBAH AKUN ADMINISTRATOR LAIN
    // =========================================================================
    elseif ($action === 'create_admin') {
        $new_nama     = trim($_POST['nama_lengkap'] ?? '');
        $new_username = trim($_POST['username'] ?? '');
        $new_email    = trim($_POST['email'] ?? '');
        $new_wa       = trim($_POST['no_whatsapp'] ?? '');
        $new_pass     = $_POST['password'] ?? '';
        $new_pass_c   = $_POST['konfirmasi_password'] ?? '';

        if (empty($new_nama) || empty($new_username)) {
            $error_msg = "Nama lengkap dan Username administrator baru wajib diisi.";
        } elseif (strlen($new_username) < 3) {
            $error_msg = "Username minimal harus terdiri dari 3 karakter.";
        } elseif (preg_match('/\s/', $new_username)) {
            $error_msg = "Username tidak boleh mengandung spasi.";
        } elseif (!empty($new_email) && !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $error_msg = "Format email tidak valid.";
        } elseif (empty($new_pass) || strlen($new_pass) < 6) {
            $error_msg = "Kata sandi minimal harus 6 karakter.";
        } elseif ($new_pass !== $new_pass_c) {
            $error_msg = "Konfirmasi kata sandi baru tidak cocok.";
        } else {
            // Cek keunikan username
            $st_cek = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(username) = LOWER(:u)");
            $st_cek->execute([':u' => $new_username]);
            if ($st_cek->fetchColumn() > 0) {
                $error_msg = "Username '@" . htmlspecialchars($new_username) . "' sudah terdaftar. Silakan pilih username lain.";
            } else {
                try {
                    $pdo->beginTransaction();

                    $hash = password_hash($new_pass, PASSWORD_BCRYPT);
                    $st_u = $pdo->prepare("INSERT INTO users (username, password, password_plain, role) VALUES (:u, :p, :pp, 'admin')");
                    $st_u->execute([
                        ':u'  => $new_username,
                        ':p'  => $hash,
                        ':pp' => $new_pass
                    ]);
                    $new_uid = (int)$pdo->lastInsertId();

                    $st_p = $pdo->prepare("INSERT INTO admin_profil (user_id, nama_lengkap, email, no_whatsapp, foto_profil) VALUES (:uid, :nama, :email, :wa, 'default.png')");
                    $st_p->execute([
                        ':uid'   => $new_uid,
                        ':nama'  => $new_nama,
                        ':email' => !empty($new_email) ? $new_email : null,
                        ':wa'    => !empty($new_wa) ? $new_wa : null
                    ]);

                    $pdo->commit();
                    $success_msg = "Akun administrator baru '" . htmlspecialchars($new_nama) . "' (@" . htmlspecialchars($new_username) . ") berhasil dibuat!";
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error_msg = "Gagal membuat akun admin: " . $e->getMessage();
                }
            }
        }
    }

    // =========================================================================
    // D. HAPUS AKUN ADMINISTRATOR LAIN
    // =========================================================================
    elseif ($action === 'delete_admin') {
        $del_uid = (int)($_POST['target_user_id'] ?? 0);
        if ($del_uid <= 0) {
            $error_msg = "ID administrator tidak valid.";
        } elseif ($del_uid === $user_id) {
            $error_msg = "Anda tidak dapat menghapus akun administrator Anda sendiri yang sedang aktif digunakan.";
        } else {
            // Pastikan jumlah admin tersisa lebih dari 1
            $st_cnt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
            $cnt_admin = (int)$st_cnt->fetchColumn();
            if ($cnt_admin <= 1) {
                $error_msg = "Sistem harus memiliki sekurang-kurangnya satu akun administrator aktif.";
            } else {
                try {
                    $pdo->beginTransaction();
                    $st_get = $pdo->prepare("SELECT username FROM users WHERE id = :uid AND role = 'admin' LIMIT 1");
                    $st_get->execute([':uid' => $del_uid]);
                    $del_name = $st_get->fetchColumn();

                    if ($del_name) {
                        $pdo->prepare("DELETE FROM admin_profil WHERE user_id = :uid")->execute([':uid' => $del_uid]);
                        $pdo->prepare("DELETE FROM users WHERE id = :uid AND role = 'admin'")->execute([':uid' => $del_uid]);
                        $pdo->commit();
                        $success_msg = "Akun administrator '@" . htmlspecialchars($del_name) . "' berhasil dihapus dari sistem.";
                    } else {
                        $pdo->rollBack();
                        $error_msg = "Akun administrator yang dipilih tidak ditemukan.";
                    }
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error_msg = "Gagal menghapus administrator: " . $e->getMessage();
                }
            }
        }
    }
}

// 4. Ambil seluruh daftar administrator sistem
$stmt_all_admins = $pdo->query("
    SELECT u.id, u.username, u.password_plain, u.created_at,
           p.nama_lengkap, p.email, p.no_whatsapp, p.foto_profil
    FROM users u
    LEFT JOIN admin_profil p ON u.id = p.user_id
    WHERE u.role = 'admin'
    ORDER BY u.id ASC
");
$all_admins = $stmt_all_admins->fetchAll(PDO::FETCH_ASSOC);

$page_title   = "Profil & Kelola Administrator";
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

$foto_url = function_exists('get_avatar_url') ? get_avatar_url($admin['foto_profil'] ?? '') : null;
$has_foto = !empty($foto_url);
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
<div class="hero-banner mb-4 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
    <div>
        <div class="hero-tag">
            <i class="bi bi-person-gear"></i>
            <span>Profil & Manajemen Administrator</span>
        </div>
        <h2 class="h3 fw-bold mb-1 text-white">Pengaturan Akun & Manajemen Admin</h2>
        <p class="mb-0 text-white-50" style="max-width: 680px; font-size: 0.95rem;">
            Kelola nama lengkap resmi, username login, kontak, foto profil, serta penambahan akun administrator sistem lainnya.
        </p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <button type="button" class="btn btn-light text-primary fw-bold shadow-sm d-inline-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#modalTambahAdmin">
            <i class="bi bi-person-plus-fill fs-5"></i>
            <span>Tambah Admin Baru</span>
        </button>
    </div>
</div>

<!-- Layout Dua Kolom: Ringkasan Profil (Kiri) & Formulir Pengaturan (Kanan) -->
<div class="row g-4 mb-4">
    <!-- Kolom Kiri: Kartu Ringkasan Profil & Status Akun -->
    <div class="col-lg-4">
        <!-- Kartu Identitas Profil -->
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
            <div class="card-header border-0 py-4 text-center text-white" style="background: linear-gradient(135deg, #1e1b4b 0%, #312e81 60%, #4338ca 100%);">
                <!-- Avatar Preview -->
                <div class="position-relative d-inline-block mb-3">
                    <?php if ($has_foto): ?>
                        <img src="<?= htmlspecialchars($foto_url); ?>" 
                             alt="Foto Profil Admin" 
                             id="avatarPreviewImg"
                             class="rounded-circle border border-4 border-white shadow" 
                             style="width: 110px; height: 110px; min-width: 110px; min-height: 110px; object-fit: cover !important; aspect-ratio: 1 / 1 !important; flex-shrink: 0 !important;">
                    <?php else: ?>
                        <div id="avatarFallback" class="rounded-circle border border-4 border-white shadow d-flex align-items-center justify-content-center text-white fw-bold fs-2" 
                             style="width: 110px; height: 110px; background: linear-gradient(135deg, #6366f1 0%, #4338ca 100%);">
                            <?= htmlspecialchars($initials); ?>
                        </div>
                        <img src="" alt="Preview" id="avatarPreviewImg" class="rounded-circle border border-4 border-white shadow d-none" style="width: 110px; height: 110px; min-width: 110px; min-height: 110px; object-fit: cover !important; aspect-ratio: 1 / 1 !important; flex-shrink: 0 !important;">
                    <?php endif; ?>
                    <span class="position-absolute bottom-0 end-0 p-2 bg-success border border-2 border-white rounded-circle" title="Akun Aktif">
                        <span class="visually-hidden">Aktif</span>
                    </span>
                </div>
                <h5 class="fw-bold mb-1 text-white text-truncate px-2" id="previewNamaHeader">
                    <?= htmlspecialchars($admin['nama_lengkap'] ?? 'Administrator'); ?>
                </h5>
                <div class="text-white-50 small mb-2">@<?= htmlspecialchars($admin['username'] ?? 'admin'); ?></div>
                <span class="badge rounded-pill px-3 py-1 shadow-sm" style="background-color: #ffffff !important; color: #1e1b4b !important; font-weight: 700 !important; font-size: 0.825rem !important; display: inline-flex; align-items: center; gap: 0.35rem; border: 1px solid rgba(255, 255, 255, 0.9);">
                    <i class="bi bi-shield-fill-check text-primary fs-6"></i>
                    <span>Administrator Utama</span>
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
                        <span class="text-muted"><i class="bi bi-person-badge me-2 text-primary"></i>Peran Sistem</span>
                        <span class="fw-semibold text-dark text-end">Administrator</span>
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

        <!-- Kartu Ringkasan Admin -->
        <div class="card border-0 shadow-sm rounded-4 p-4 bg-white">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <h6 class="fw-bold text-dark mb-0 d-flex align-items-center">
                    <i class="bi bi-people-fill text-primary me-2"></i>Total Administrator
                </h6>
                <span class="badge bg-primary-subtle text-primary fw-bold"><?= count($all_admins); ?> Akun</span>
            </div>
            <p class="text-muted small mb-3">
                Seluruh administrator memiliki hak akses penuh untuk mengelola master data mahasiswa, jadwal dosen, plotting bimbingan, serta kelulusan sidang.
            </p>
            <button type="button" class="btn btn-outline-primary btn-sm w-100 fw-semibold" data-bs-toggle="modal" data-bs-target="#modalTambahAdmin">
                <i class="bi bi-person-plus-fill me-1"></i> Tambah Akun Admin Baru
            </button>
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
                        <div class="col-md-6">
                            <label for="nama_lengkap" class="form-label fw-semibold small text-dark">
                                Nama Lengkap Administrator <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-person-badge"></i></span>
                                <input type="text" class="form-control border-start-0" id="nama_lengkap" name="nama_lengkap" 
                                       value="<?= htmlspecialchars($admin['nama_lengkap'] ?? ''); ?>" 
                                       required placeholder="Contoh: Oscar atau Administrator Utama">
                            </div>
                            <div class="form-text small">Nama ini ditampilkan pada navbar sistem dan kop laporan cetak.</div>
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

<!-- ============================================================
     TABEL DAFTAR SEMUA ADMINISTRATOR SISTEM
============================================================ -->
<div class="card border-0 shadow-sm rounded-4 mb-4" id="kelola-admin">
    <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <span class="p-2 rounded bg-primary-subtle text-primary">
                <i class="bi bi-shield-lock-fill fs-5"></i>
            </span>
            <div>
                <h5 class="fw-bold text-dark mb-0">Daftar Administrator Sistem</h5>
                <small class="text-muted">Total <?= count($all_admins); ?> akun admin dengan kewenangan penuh</small>
            </div>
        </div>
        <button type="button" class="btn btn-primary btn-sm px-3 fw-semibold d-inline-flex align-items-center gap-1.5" data-bs-toggle="modal" data-bs-target="#modalTambahAdmin">
            <i class="bi bi-person-plus-fill"></i>
            <span>Tambah Admin Baru</span>
        </button>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3" style="width: 50px;">No</th>
                        <th>Administrator</th>
                        <th>Username Login</th>
                        <th>Kontak Resmi</th>
                        <th>Terdaftar Sejak</th>
                        <th class="text-center" style="width: 140px;">Status / Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $no_adm = 1;
                    foreach ($all_admins as $adm_row): 
                        $is_current = ((int)$adm_row['id'] === $user_id);
                        $adm_avatar = function_exists('get_avatar_url') ? get_avatar_url($adm_row['foto_profil'] ?? '') : null;
                        $adm_initial = strtoupper(substr($adm_row['nama_lengkap'] ?? $adm_row['username'], 0, 1));
                    ?>
                        <tr>
                            <td class="ps-3 text-muted"><?= $no_adm++; ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-2.5">
                                    <?php if ($adm_avatar): ?>
                                        <img src="<?= htmlspecialchars($adm_avatar); ?>" 
                                             class="rounded-circle border shadow-xs object-fit-cover flex-shrink-0" 
                                             width="40" height="40" alt="Avatar">
                                    <?php else: ?>
                                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center fw-bold shadow-xs flex-shrink-0" 
                                             style="width: 40px; height: 40px; font-size: 0.95rem;">
                                            <?= $adm_initial; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <div class="fw-bold text-dark mb-0">
                                            <?= htmlspecialchars($adm_row['nama_lengkap'] ?? 'Admin'); ?>
                                            <?php if ($is_current): ?>
                                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle ms-1" style="font-size: 0.68rem;">Anda</span>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted">Hak Akses: Administrator</small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="font-monospace fw-semibold text-primary">@<?= htmlspecialchars($adm_row['username']); ?></span>
                            </td>
                            <td>
                                <div class="small">
                                    <?php if (!empty($adm_row['email'])): ?>
                                        <div><i class="bi bi-envelope text-muted me-1"></i><?= htmlspecialchars($adm_row['email']); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($adm_row['no_whatsapp'])): ?>
                                        <div><i class="bi bi-whatsapp text-success me-1"></i><?= htmlspecialchars($adm_row['no_whatsapp']); ?></div>
                                    <?php endif; ?>
                                    <?php if (empty($adm_row['email']) && empty($adm_row['no_whatsapp'])): ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <small class="text-muted">
                                    <?= !empty($adm_row['created_at']) ? date('d M Y, H:i', strtotime($adm_row['created_at'])) : '-'; ?>
                                </small>
                            </td>
                            <td class="text-center">
                                <?php if ($is_current): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                        <i class="bi bi-check-circle me-1"></i>Aktif Digunakan
                                    </span>
                                <?php else: ?>
                                    <form action="profil.php" method="POST" class="d-inline" onsubmit="return confirm('Apakah Anda yakin ingin menghapus akun administrator @<?= htmlspecialchars($adm_row['username']); ?>? Tindakan ini tidak dapat dibatalkan.');">
                                        <input type="hidden" name="action" value="delete_admin">
                                        <input type="hidden" name="target_user_id" value="<?= (int)$adm_row['id']; ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm px-2 py-1" title="Hapus Administrator">
                                            <i class="bi bi-trash-fill me-1"></i>Hapus
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ============================================================
     MODAL TAMBAH ADMINISTRATOR BARU
============================================================ -->
<div class="modal fade" id="modalTambahAdmin" tabindex="-1" aria-labelledby="modalTambahAdminLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-primary text-white py-3 px-4">
                <div class="d-flex align-items-center gap-2">
                    <span class="p-1 px-2 bg-white bg-opacity-20 rounded-2 text-white">
                        <i class="bi bi-person-plus-fill"></i>
                    </span>
                    <h5 class="modal-title fw-bold" id="modalTambahAdminLabel">Tambah Administrator Baru</h5>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="profil.php" method="POST">
                <input type="hidden" name="action" value="create_admin">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <!-- Nama Lengkap -->
                        <div class="col-12">
                            <label for="new_admin_nama" class="form-label fw-semibold small text-dark">
                                Nama Lengkap Admin <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-person"></i></span>
                                <input type="text" class="form-control border-start-0" id="new_admin_nama" name="nama_lengkap" 
                                       required placeholder="Contoh: Oscar Admin atau Admin 2">
                            </div>
                        </div>

                        <!-- Username -->
                        <div class="col-12">
                            <label for="new_admin_username" class="form-label fw-semibold small text-dark">
                                Username (Login ID) <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-at"></i></span>
                                <input type="text" class="form-control border-start-0" id="new_admin_username" name="username" 
                                       required minlength="3" placeholder="Contoh: admin2 (tanpa spasi)">
                            </div>
                            <div class="form-text small">Minimal 3 karakter tanpa spasi.</div>
                        </div>

                        <!-- Email Resmi -->
                        <div class="col-md-6">
                            <label for="new_admin_email" class="form-label fw-semibold small text-dark">
                                Email Resmi (Opsional)
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-envelope"></i></span>
                                <input type="email" class="form-control border-start-0" id="new_admin_email" name="email" 
                                       placeholder="admin2@univ.ac.id">
                            </div>
                        </div>

                        <!-- No. WhatsApp -->
                        <div class="col-md-6">
                            <label for="new_admin_wa" class="form-label fw-semibold small text-dark">
                                No. WhatsApp (Opsional)
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-whatsapp"></i></span>
                                <input type="text" class="form-control border-start-0" id="new_admin_wa" name="no_whatsapp" 
                                       placeholder="081234567890">
                            </div>
                        </div>

                        <!-- Password -->
                        <div class="col-12">
                            <label for="new_admin_password" class="form-label fw-semibold small text-dark">
                                Kata Sandi <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-lock"></i></span>
                                <input type="password" class="form-control border-start-0 border-end-0" id="new_admin_password" name="password" 
                                       required minlength="6" placeholder="Minimal 6 karakter">
                                <button class="btn btn-outline-secondary border-start-0 toggle-pw" type="button" data-target="new_admin_password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Konfirmasi Password -->
                        <div class="col-12">
                            <label for="new_admin_password_confirm" class="form-label fw-semibold small text-dark">
                                Konfirmasi Kata Sandi <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-shield-check"></i></span>
                                <input type="password" class="form-control border-start-0 border-end-0" id="new_admin_password_confirm" name="konfirmasi_password" 
                                       required minlength="6" placeholder="Ulangi kata sandi">
                                <button class="btn btn-outline-secondary border-start-0 toggle-pw" type="button" data-target="new_admin_password_confirm">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light px-4 py-3 border-top">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary d-inline-flex align-items-center gap-1.5 px-4">
                        <i class="bi bi-check2-circle"></i>
                        <span>Buat Akun Administrator</span>
                    </button>
                </div>
            </form>
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
