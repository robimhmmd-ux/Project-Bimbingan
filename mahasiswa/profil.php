<?php
// mahasiswa/profil.php - Manajemen Profil & Pengaturan Akun Mahasiswa
require_once __DIR__ . '/../config/database.php';

// Proteksi akses role mahasiswa
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'mahasiswa') {
    header("Location: ../login.php");
    exit;
}
if (file_exists(__DIR__ . '/../config/avatar_helper.php')) {
    require_once __DIR__ . '/../config/avatar_helper.php';
}

$my_user_id = $_SESSION['user_id'];
$success_msg = '';
$error_msg   = '';

// Ambil data profil mahasiswa saat ini beserta info dosen pembimbing
$stmt_mhs = $pdo->prepare("
    SELECT m.*, u.username, 
           d.id AS dosen_id, d.user_id AS dosen_user_id, d.nama_dosen, d.nidn, d.email AS email_dosen, 
           d.no_whatsapp AS no_wa_dosen, d.foto_profil AS foto_dosen, d.kuota_bimbingan,
           u_dos.username AS username_dosen
    FROM mahasiswa m 
    JOIN users u ON m.user_id = u.id 
    LEFT JOIN dosen d ON m.dosen_pembimbing_id = d.id 
    LEFT JOIN users u_dos ON d.user_id = u_dos.id
    WHERE m.user_id = :uid LIMIT 1
");
$stmt_mhs->execute([':uid' => $my_user_id]);
$mhs = $stmt_mhs->fetch();

if (!$mhs) {
    die("Data profil mahasiswa tidak ditemukan di sistem.");
}

$mhs_id = $mhs['id'];

// Path Foto Dosen Pembimbing Resmi
$foto_dosen_src = null;
$foto_dosen_src = function_exists('get_avatar_url') ? get_avatar_url($mhs['foto_dosen'] ?? null) : null;

// Jadwal Dosen Pembimbing untuk Modal Profil Dosen
$jadwal_dosen_list = [];
if (!empty($mhs['dosen_id'])) {
    try {
        $st_jd = $pdo->prepare("
            SELECT hari, jam_mulai, jam_selesai, status_aktif 
            FROM jadwal_dosen 
            WHERE dosen_id = :did AND status_aktif = 'aktif'
            ORDER BY FIELD(hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'), jam_mulai ASC
        ");
        $st_jd->execute([':did' => $mhs['dosen_id']]);
        $jadwal_dosen_list = $st_jd->fetchAll();
    } catch (PDOException $e) {
        $jadwal_dosen_list = [];
    }
}

// ============================================================
// HANDLE SUBMIT FORM (POST)
// ============================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    // 0. AJAX INSTANT SAVE FOTO PROFIL SETELAH CROP / PENYESUAIAN
    if ($action === 'ajax_simpan_foto') {
        header('Content-Type: application/json; charset=utf-8');
        $cropped_data = $_POST['cropped_photo'] ?? '';

        if (empty($cropped_data) || strpos($cropped_data, 'data:image/') !== 0) {
            echo json_encode(['status' => 'error', 'message' => 'Data gambar tidak valid atau kosong.']);
            exit;
        }

        if (!preg_match('/^data:image\/(\w+);base64,/', $cropped_data)) {
            echo json_encode(['status' => 'error', 'message' => 'Format gambar base64 tidak valid.']);
            exit;
        }

        $img_raw = substr($cropped_data, strpos($cropped_data, ',') + 1);
        $decoded_img = base64_decode($img_raw);
        if ($decoded_img === false) {
            echo json_encode(['status' => 'error', 'message' => 'Gagal menguraikan gambar base64.']);
            exit;
        }

        $upload_dir = __DIR__ . '/../assets/uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $foto_name = 'profil_' . $mhs_id . '_' . uniqid() . '.jpg';
        $target_file = $upload_dir . $foto_name;

        if (!file_put_contents($target_file, $decoded_img)) {
            echo json_encode(['status' => 'error', 'message' => 'Gagal menulis file foto ke server.']);
            exit;
        }

        // Hapus foto profil lama jika ada file fisiknya dan bukan default.png
        $foto_lama = $mhs['foto_profil'];
        if (!empty($foto_lama) && $foto_lama !== 'default.png') {
            $path_lama = $upload_dir . basename($foto_lama);
            if (file_exists($path_lama)) {
                @unlink($path_lama);
            }
        }

        // Simpan langsung ke database
        try {
            $stmt_u = $pdo->prepare("UPDATE mahasiswa SET foto_profil = :foto WHERE id = :mid");
            $stmt_u->execute([':foto' => $foto_name, ':mid' => $mhs_id]);

            // Perbarui session aktif
            $_SESSION['foto_profil'] = $foto_name;

            echo json_encode([
                'status'    => 'success',
                'message'   => 'Foto profil berhasil disimpan dan diperbarui secara permanen!',
                'foto_nama' => $foto_name,
                'foto_url'  => '../assets/uploads/' . $foto_name . '?v=' . time()
            ]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Gagal memperbarui database: ' . $e->getMessage()]);
            exit;
        }
    }

    // 1. UPDATE DATA AKUN, BIODATA & FOTO PROFIL
    if ($action === 'update_profil') {
        $username      = trim($_POST['username'] ?? '');
        $nama_lengkap  = trim($_POST['nama_lengkap'] ?? '');
        $nim           = trim($_POST['nim'] ?? '');
        $kelas         = trim($_POST['kelas'] ?? 'TI-1A-2025');
        $judul_skripsi = trim($_POST['judul_skripsi'] ?? '');
        $foto_baru     = $mhs['foto_profil'];

        // Validasi input wajib
        if (empty($nama_lengkap) || empty($nim) || empty($username)) {
            $error_msg = "Nama lengkap, NIM, dan Username tidak boleh kosong.";
        } elseif (preg_match('/\s/', $username)) {
            $error_msg = "Username tidak boleh mengandung spasi.";
        } else {
            // Cek apakah username diubah dan apakah sudah dipakai user lain
            if ($username !== $mhs['username']) {
                $stmt_cek_u = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :u AND id != :uid");
                $stmt_cek_u->execute([':u' => $username, ':uid' => $my_user_id]);
                if ($stmt_cek_u->fetchColumn() > 0) {
                    $error_msg = "Username '@{$username}' sudah digunakan oleh pengguna lain. Silakan pilih username lain.";
                }
            }

            // Cek apakah NIM diubah dan apakah sudah dipakai mahasiswa lain
            if (empty($error_msg) && $nim !== $mhs['nim']) {
                $stmt_cek_nim = $pdo->prepare("SELECT COUNT(*) FROM mahasiswa WHERE nim = :nim AND id != :mid");
                $stmt_cek_nim->execute([':nim' => $nim, ':mid' => $mhs_id]);
                if ($stmt_cek_nim->fetchColumn() > 0) {
                    $error_msg = "NIM '{$nim}' sudah terdaftar untuk mahasiswa lain.";
                }
            }

            // A. Proses Foto yang Telah Disesuaikan / Di-Crop (Base64 Data URI - Cloud & Serverless Safe)
            if (empty($error_msg) && !empty($_POST['cropped_photo']) && str_starts_with($_POST['cropped_photo'], 'data:image/')) {
                $foto_baru = $_POST['cropped_photo'];
            }
            // B. Fallback Upload File Biasa jika tidak melalui crop modal
            elseif (empty($error_msg) && isset($_FILES['foto_profil']) && $_FILES['foto_profil']['error'] === UPLOAD_ERR_OK) {
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
                    $mime_type = ($ext === 'png') ? 'image/png' : (($ext === 'webp') ? 'image/webp' : 'image/jpeg');
                    $raw_bytes = @file_get_contents($file_tmp);
                    if ($raw_bytes !== false) {
                        $foto_baru = 'data:' . $mime_type . ';base64,' . base64_encode($raw_bytes);
                    } else {
                        $error_msg = "Gagal memproses file foto profil.";
                    }
                }
            }

            // Eksekusi update jika seluruh validasi lolos
            if (empty($error_msg)) {
                try {
                    $pdo->beginTransaction();

                    // Update data di tabel users (username)
                    $stmt_u = $pdo->prepare("UPDATE users SET username = :u WHERE id = :uid");
                    $stmt_u->execute([':u' => $username, ':uid' => $my_user_id]);

                    // Hapus berkas foto lama jika mengunggah foto profil baru
                    if (!empty($foto_baru) && $foto_baru !== $mhs['foto_profil'] && function_exists('delete_old_profile_photo')) {
                        delete_old_profile_photo($mhs['foto_profil'] ?? null);
                    }

                    // Update data di tabel mahasiswa
                    $stmt_m = $pdo->prepare("
                        UPDATE mahasiswa 
                        SET nama_lengkap = :nama, 
                            nim = :nim, 
                            kelas = :kelas, 
                            judul_skripsi = :judul, 
                            foto_profil = :foto 
                        WHERE id = :mid
                    ");
                    $stmt_m->execute([
                        ':nama'  => $nama_lengkap,
                        ':nim'   => $nim,
                        ':kelas' => $kelas,
                        ':judul' => $judul_skripsi,
                        ':foto'  => $foto_baru,
                        ':mid'   => $mhs_id
                    ]);

                    $pdo->commit();

                    // Perbarui session
                    $_SESSION['username']     = $username;
                    $_SESSION['nama_lengkap'] = $nama_lengkap;
                    $_SESSION['foto_profil']  = $foto_baru;
                    $success_msg = "Data akun dan foto profil Anda berhasil diperbarui!";

                    // Refresh data mhs
                    $stmt_mhs->execute([':uid' => $my_user_id]);
                    $mhs = $stmt_mhs->fetch();
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error_msg = "Gagal memperbarui profil: " . $e->getMessage();
                }
            }
        }
    }

    // 2. GANTI PASSWORD AKUN
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
                $error_msg = "Password saat ini (lama) yang Anda masukkan salah.";
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

// Inisialisasi Tampilan Halaman
$page_title   = "Profil & Pengaturan Akun";
$current_page = "profil";

require_once '../templates/header.php';
require_once '../templates/sidebar_mahasiswa.php';
?>

<!-- ============================================================
     HERO BANNER MAHASISWA (NAVY SAPPHIRE)
============================================================ -->
<div class="student-hero-banner">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 position-relative" style="z-index: 2;">
        <div class="pe-md-3">
            <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                <span class="badge-kelas">
                    <i class="bi bi-mortarboard-fill"></i><?= htmlspecialchars($mhs['kelas'] ?? 'TI-1A-2025'); ?>
                </span>
                <span class="badge bg-white bg-opacity-25 text-white border border-white border-opacity-25 px-2.5 py-1 small rounded-pill">
                    <i class="bi bi-person-gear me-1"></i>Data Diri & Akun
                </span>
            </div>
            <h2 class="fw-bold text-white mb-1">Profil & Pengaturan Akun</h2>
            <p class="text-white-50 small mb-0">
                Kelola dan perbarui identitas akun login, NIM, kelas akademik, judul skripsi, serta keamanan kata sandi Anda.
            </p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-shrink-0 flex-wrap">
            <a href="index.php" class="btn btn-hero-action btn-hero-outline">
                <i class="bi bi-speedometer2"></i>Ke Dashboard
            </a>
        </div>
    </div>
</div>

<?php if (!empty($success_msg)): ?>
    <div class="alert alert-success alert-dismissible fade show py-2 small shadow-sm" role="alert">
        <i class="bi bi-check-circle-fill me-1"></i><?= htmlspecialchars($success_msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!empty($error_msg)): ?>
    <div class="alert alert-danger alert-dismissible fade show py-2 small shadow-sm" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-1"></i><?= htmlspecialchars($error_msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <!-- ============================================================
         KARTU TAMPILAN PROFIL IDENTITAS AKADEMIK (BERSIH & RAPI)
    ============================================================ -->
    <div class="col-lg-4">
        <div class="card shadow-sm h-100 border-0">
            <div class="card-header bg-white py-3 border-bottom text-center">
                <h6 class="m-0 fw-bold text-dark">
                    <i class="bi bi-person-bounding-box text-primary me-2"></i>Kartu Identitas Akademik
                </h6>
            </div>
            <div class="card-body text-center p-4">
                <!-- Avatar Profil Mahasiswa Bersih dengan Gradient Border -->
                <div class="mb-3 position-relative d-inline-block">
                    <div class="student-avatar-frame">
                        <div class="student-avatar-inner">
                            <?php 
                            $prev_url = function_exists('get_avatar_url') ? get_avatar_url($mhs['foto_profil'] ?? '') : null;
                            if ($prev_url): ?>
                                <img id="fotoProfilPreview" src="<?= htmlspecialchars($prev_url); ?>" alt="Foto Profil" style="width:100%; height:100%; object-fit:cover;">
                            <?php else: ?>
                                <div id="fotoProfilPreview" class="avatar-placeholder bg-primary text-white d-flex align-items-center justify-content-center fw-bold" style="width:100%; height:100%;">
                                    <?= strtoupper(substr($mhs['nama_lengkap'] ?? 'M', 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Identitas Nama & NIM -->
                <h5 class="fw-bold text-dark mb-1"><?= htmlspecialchars($mhs['nama_lengkap']); ?></h5>
                <p class="text-muted small mb-3">NIM: <strong><?= htmlspecialchars($mhs['nim']); ?></strong></p>

                <!-- Badges: Kelas & Status (Sejajar, Rata, 1 Baris, Tidak Terpotong) -->
                <div class="d-flex justify-content-center align-items-center gap-2 mb-4 flex-nowrap">
                    <span class="badge-kelas">
                        <i class="bi bi-mortarboard-fill"></i><?= htmlspecialchars($mhs['kelas'] ?? 'TI-1A-2025'); ?>
                    </span>
                    <?php
                    $sk = $mhs['status_kelulusan'] ?? 'proses_bimbingan';
                    $sk_icon = 'bi-hourglass-split';
                    if ($sk === 'siap_sidang') $sk_icon = 'bi-check2-all';
                    elseif ($sk === 'lulus') $sk_icon = 'bi-patch-check-fill';
                    ?>
                    <span class="badge-status-mhs badge-status-<?= $sk; ?>">
                        <i class="bi <?= $sk_icon; ?>"></i><?= ucwords(str_replace('_', ' ', $sk)); ?>
                    </span>
                </div>

                <!-- Informasi Biodata Akademik Singkat -->
                <div class="bg-light p-3 rounded text-start border small mb-3">
                    <div class="mb-2">
                        <span class="text-muted d-block">ID Login / Username:</span>
                        <span class="fw-semibold text-dark"><i class="bi bi-at text-primary"></i><?= htmlspecialchars($mhs['username']); ?></span>
                    </div>
                    <div class="mb-2">
                        <span class="text-muted d-block">Kelas Mahasiswa:</span>
                        <span class="fw-semibold text-primary"><?= htmlspecialchars($mhs['kelas'] ?? 'TI-1A-2025'); ?></span>
                    </div>
                    <div class="mb-2">
                        <span class="text-muted d-block mb-1">Dosen Pembimbing:</span>
                        <?php if (!empty($mhs['nama_dosen'])): ?>
                            <div class="d-flex align-items-center gap-2 p-2 bg-white rounded-3 border" style="cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 12px rgba(0,0,0,0.06)';" onmouseout="this.style.transform='none'; this.style.boxShadow='none';" data-bs-toggle="modal" data-bs-target="#modalProfilDosen" title="Klik untuk melihat profil lengkap dosen">
                                <?php if ($foto_dosen_src): ?>
                                    <img src="<?= $foto_dosen_src; ?>" class="rounded-circle border shadow-xs flex-shrink-0" width="36" height="36" style="object-fit: cover; width: 36px; height: 36px;" alt="Dosen Pembimbing">
                                <?php else: ?>
                                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center fw-bold flex-shrink-0 shadow-xs" style="width: 36px; height: 36px; font-size: 0.95rem;">
                                        <?= strtoupper(substr($mhs['nama_dosen'], 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                                <div class="min-w-0 flex-grow-1">
                                    <div class="fw-bold text-dark small text-truncate" title="<?= htmlspecialchars($mhs['nama_dosen']); ?>">
                                        <?= htmlspecialchars($mhs['nama_dosen']); ?>
                                    </div>
                                    <div class="text-muted" style="font-size: 0.72rem;">
                                        NIDN: <?= htmlspecialchars($mhs['nidn'] ?? '-'); ?>
                                    </div>
                                </div>
                                <i class="bi bi-chevron-right text-muted small ms-auto"></i>
                            </div>
                        <?php else: ?>
                            <span class="badge bg-secondary"><i class="bi bi-dash-circle me-1"></i>Belum Diplot</span>
                        <?php endif; ?>
                    </div>
                    <div class="mb-2">
                        <span class="text-muted d-block">Status Penetapan SK:</span>
                        <?php
                        $sp = $mhs['status_pembimbing'] ?? 'belum_diplot';
                        if ($sp === 'aktif') {
                            echo '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Aktif & Dikonfirmasi</span>';
                        } elseif ($sp === 'menunggu_konfirmasi') {
                            echo '<span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i>Menunggu Konfirmasi SK</span>';
                        } else {
                            echo '<span class="badge bg-secondary"><i class="bi bi-dash-circle me-1"></i>Belum Diplot</span>';
                        }
                        ?>
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

    <!-- ============================================================
         FORMULIR EDIT PENGATURAN AKUN & GANTI PASSWORD (KANAN)
    ============================================================ -->
    <div class="col-lg-8">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 border-bottom">
                <ul class="nav nav-pills card-header-pills flex-nowrap overflow-x-auto pb-1" id="profilTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active fw-bold btn-sm px-3" id="akun-tab" data-bs-toggle="tab" data-bs-target="#tab-akun" type="button" role="tab">
                            <i class="bi bi-person-gear me-1"></i> Pengaturan Akun & Biodata
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link fw-bold btn-sm px-3 ms-2" id="password-tab" data-bs-toggle="tab" data-bs-target="#tab-password" type="button" role="tab">
                            <i class="bi bi-shield-lock-fill me-1"></i> Ganti Password
                        </button>
                    </li>
                </ul>
            </div>
            <div class="card-body p-4">
                <div class="tab-content" id="profilTabContent">
                    <!-- Tab 1: Edit Akun & Biodata -->
                    <div class="tab-pane fade show active" id="tab-akun" role="tabpanel">
                        <form action="profil.php" method="POST" enctype="multipart/form-data" id="formUpdateProfil">
                            <input type="hidden" name="action" value="update_profil">

                            <div class="alert alert-light border small text-muted mb-4">
                                <i class="bi bi-info-circle text-primary me-1"></i>
                                Anda dapat mengedit seluruh data akun login, NIM, nama lengkap, kelas, judul tugas akhir, dan foto profil.
                            </div>

                            <!-- Baris Username & NIM -->
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label for="username" class="form-label small fw-semibold text-secondary">
                                        Username / ID Login <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light"><i class="bi bi-at"></i></span>
                                        <input type="text" class="form-control" id="username" name="username" value="<?= htmlspecialchars($mhs['username']); ?>" placeholder="Username login" required>
                                    </div>
                                    <div class="form-text small" style="font-size: 0.75rem;">Digunakan saat masuk ke portal sistem bimbingan.</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="nim" class="form-label small fw-semibold text-secondary">
                                        Nomor Induk Mahasiswa (NIM) <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="nim" name="nim" value="<?= htmlspecialchars($mhs['nim']); ?>" placeholder="Contoh: 41257158" required>
                                    <div class="form-text small" style="font-size: 0.75rem;">NIM resmi mahasiswa.</div>
                                </div>
                            </div>

                            <!-- Baris Nama Lengkap & Kelas -->
                            <div class="row g-3 mb-3">
                                <div class="col-md-7">
                                    <label for="nama_lengkap" class="form-label small fw-semibold text-secondary">
                                        Nama Lengkap <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="nama_lengkap" name="nama_lengkap" value="<?= htmlspecialchars($mhs['nama_lengkap']); ?>" placeholder="Nama lengkap Anda" required>
                                </div>
                                <div class="col-md-5">
                                    <label for="kelas" class="form-label small fw-semibold text-secondary">
                                        Kelas Akademik <span class="text-danger">*</span>
                                    </label>
                                    <?php $current_kelas = $mhs['kelas'] ?? 'TI-1A-2025'; ?>
                                    <select class="form-select" id="kelas" name="kelas" required>
                                        <option value="TI-1A-2025" <?= $current_kelas === 'TI-1A-2025' ? 'selected' : ''; ?>>TI-1A-2025</option>
                                        <option value="TI-1B-2025" <?= $current_kelas === 'TI-1B-2025' ? 'selected' : ''; ?>>TI-1B-2025</option>
                                        <option value="TI-1C-2025" <?= $current_kelas === 'TI-1C-2025' ? 'selected' : ''; ?>>TI-1C-2025</option>
                                    </select>
                                    <div class="form-text small" style="font-size: 0.75rem;">Pilih rombel / kelas perkuliahan Anda.</div>
                                </div>
                            </div>

                            <!-- Judul Skripsi -->
                            <div class="mb-3">
                                <label for="judul_skripsi" class="form-label small fw-semibold text-secondary">
                                    Judul Tugas Akhir / Skripsi
                                </label>
                                <textarea class="form-control" id="judul_skripsi" name="judul_skripsi" rows="3" placeholder="Masukkan judul skripsi lengkap..."><?= htmlspecialchars($mhs['judul_skripsi'] ?? ''); ?></textarea>
                                <div class="form-text small" style="font-size: 0.75rem;">
                                    Pastikan judul selalu diperbarui sesuai dengan kesepakatan bersama dosen pembimbing.
                                </div>
                            </div>

                            <!-- Upload & Penyesuaian Foto Profil Baru Sebelum Simpan -->
                            <div class="mb-4">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label for="foto_profil" class="form-label small fw-semibold text-secondary mb-0">
                                        Unggah Foto Profil Baru
                                    </label>
                                    <?php if (!empty($mhs['foto_profil']) && $mhs['foto_profil'] !== 'default.png'): ?>
                                        <button type="button" class="btn btn-sm btn-outline-primary py-0.5 px-2.5 rounded-pill small fw-semibold" id="btnAdjustExistingPhoto" style="font-size: 0.74rem;">
                                            <i class="bi bi-crop me-1"></i>Sesuaikan Foto Saat Ini
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <div class="input-group">
                                    <input class="form-control" type="file" id="foto_profil" name="foto_profil" accept="image/jpeg,image/png,image/jpg,image/webp">
                                    <button class="btn btn-outline-primary" type="button" id="btnBukaCropModal" style="display: none;">
                                        <i class="bi bi-crop me-1"></i>Sesuaikan Foto
                                    </button>
                                </div>
                                <div class="form-text small" style="font-size: 0.75rem;">
                                    Format: JPG, PNG, WEBP (maksimal 2 MB). Setelah memilih file foto, jendela penyesuaian akan terbuka otomatis sehingga Anda dapat mengatur posisi tengah wajah dan zoom in / out sebelum disimpan.
                                </div>

                                <!-- Notifikasi Foto Telah Disesuaikan -->
                                <div id="cropPreviewAlert" class="alert alert-success py-2 px-3 small mt-2 d-none align-items-center justify-content-between rounded-3">
                                    <div class="d-flex align-items-center">
                                        <i class="bi bi-check-circle-fill me-2 fs-6 text-success"></i>
                                        <span><strong>Foto profil berhasil disimpan!</strong> Foto tetap ada saat halaman di-refresh dan muncul di pojok kanan atas sebelah ikon lonceng.</span>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-success py-0 px-2 rounded-pill ms-2 text-nowrap" id="btnReAdjust" style="font-size: 0.72rem;">
                                        <i class="bi bi-sliders me-1"></i>Atur Ulang
                                    </button>
                                </div>

                                <!-- Hidden field base64 gambar yang telah disesuaikan -->
                                <input type="hidden" name="cropped_photo" id="croppedPhotoInput" value="">
                            </div>

                            <button type="submit" class="btn btn-primary px-4 fw-semibold shadow-sm">
                                <i class="bi bi-save me-1"></i>Simpan Perubahan Akun & Profil
                            </button>
                        </form>
                    </div>

                    <!-- Tab 2: Form Ganti Password -->
                    <div class="tab-pane fade" id="tab-password" role="tabpanel">
                        <form action="profil.php" method="POST">
                            <input type="hidden" name="action" value="ganti_password">

                            <div class="alert alert-light border small text-muted mb-4">
                                <i class="bi bi-shield-check text-success me-1"></i>
                                Untuk keamanan, gunakan kombinasi huruf dan angka minimal 6 karakter.
                            </div>

                            <div class="mb-3">
                                <label for="password_lama" class="form-label small fw-semibold text-secondary">Password Saat Ini <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" id="password_lama" name="password_lama" placeholder="Masukkan password lama..." required>
                            </div>

                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label for="password_baru" class="form-label small fw-semibold text-secondary">Password Baru <span class="text-danger">*</span></label>
                                    <input type="password" class="form-control" id="password_baru" name="password_baru" placeholder="Minimal 6 karakter" required minlength="6">
                                </div>
                                <div class="col-md-6">
                                    <label for="konfirmasi_password" class="form-label small fw-semibold text-secondary">Ulangi Password Baru <span class="text-danger">*</span></label>
                                    <input type="password" class="form-control" id="konfirmasi_password" name="konfirmasi_password" placeholder="Ketik ulang password baru" required minlength="6">
                                </div>
                            </div>

                            <button type="submit" class="btn btn-warning text-dark px-4 fw-bold shadow-sm">
                                <i class="bi bi-key me-1"></i>Perbarui Password
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     MODAL SESUAIKAN FOTO PROFIL SEBELUM DISIMPAN
============================================================ -->
<div class="modal fade" id="modalSesuaikanFoto" tabindex="-1" aria-labelledby="modalSesuaikanFotoLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow border-0 rounded-4">
            <div class="modal-header border-bottom py-3 px-4">
                <h6 class="modal-title fw-bold text-dark m-0" id="modalSesuaikanFotoLabel">
                    <i class="bi bi-crop text-primary me-2"></i>Sesuaikan Foto Profil
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 text-center">
                <p class="text-muted small mb-3">
                    <i class="bi bi-arrows-move me-1 text-primary"></i><strong>Geser foto</strong> untuk memposisikan wajah di tengah lingkaran, lalu gunakan slider untuk <strong>zoom in / out</strong>.
                </p>

                <!-- Area Viewport Crop Interaktif -->
                <div class="crop-viewport-wrapper position-relative mx-auto mb-3" id="cropViewport" title="Geser foto dengan mouse atau touch untuk mengatur posisi">
                    <img id="cropImage" src="" alt="Crop Source">
                    <!-- Lingkaran Guide Panduan Masker -->
                    <div class="crop-mask-overlay"></div>
                    <div class="crop-circle-ring"></div>
                </div>

                <!-- Kontrol Zoom Slider & Tombol -->
                <div class="d-flex align-items-center justify-content-center gap-2 mb-3 px-3">
                    <button type="button" class="btn btn-sm btn-light border rounded-circle p-0 d-flex align-items-center justify-content-center shadow-xs" id="btnModalZoomOut" style="width: 32px; height: 32px;" title="Zoom Out (-)">
                        <i class="bi bi-zoom-out text-secondary"></i>
                    </button>
                    <input type="range" class="form-range flex-grow-1 custom-slider" id="modalSliderZoom" min="0.5" max="3.0" step="0.02" value="1.0" title="Geser untuk zoom in / out">
                    <button type="button" class="btn btn-sm btn-light border rounded-circle p-0 d-flex align-items-center justify-content-center shadow-xs" id="btnModalZoomIn" style="width: 32px; height: 32px;" title="Zoom In (+)">
                        <i class="bi bi-zoom-in text-secondary"></i>
                    </button>
                    <span class="badge bg-light text-dark border px-2 py-1 fw-bold rounded-pill" id="badgeModalZoom" style="min-width: 48px; font-size: 0.75rem;">100%</span>
                </div>

                <!-- Tombol Aksi Bantuan: Reset & Putar -->
                <div class="d-flex justify-content-center gap-2 mb-1">
                    <button type="button" class="btn btn-sm btn-outline-secondary px-3 py-1 rounded-pill" id="btnModalResetPos" style="font-size: 0.75rem;">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Reset Posisi
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary px-3 py-1 rounded-pill" id="btnModalRotate" style="font-size: 0.75rem;">
                        <i class="bi bi-arrow-repeat me-1"></i>Putar 90°
                    </button>
                </div>
            </div>
            <div class="modal-footer border-top py-2.5 px-4 d-flex justify-content-between">
                <button type="button" class="btn btn-light border px-3" data-bs-dismiss="modal">
                    Batal
                </button>
                <button type="button" class="btn btn-primary px-4 fw-semibold shadow-xs" id="btnTerapkanCrop">
                    <i class="bi bi-check2-circle me-1"></i>Terapkan & Simpan Foto
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     JAVASCRIPT: INTERACTIVE PHOTO ADJUSTER & CROPPER
============================================================ -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const inputFotoProfil        = document.getElementById('foto_profil');
    const btnBukaCropModal        = document.getElementById('btnBukaCropModal');
    const btnAdjustExistingPhoto  = document.getElementById('btnAdjustExistingPhoto');
    const modalSesuaikanFotoEl    = document.getElementById('modalSesuaikanFoto');
    const modalSesuaikanFoto      = new bootstrap.Modal(modalSesuaikanFotoEl);
    const cropViewport            = document.getElementById('cropViewport');
    const cropImage               = document.getElementById('cropImage');
    const modalSliderZoom         = document.getElementById('modalSliderZoom');
    const badgeModalZoom          = document.getElementById('badgeModalZoom');
    const btnModalZoomIn          = document.getElementById('btnModalZoomIn');
    const btnModalZoomOut         = document.getElementById('btnModalZoomOut');
    const btnModalResetPos        = document.getElementById('btnModalResetPos');
    const btnModalRotate          = document.getElementById('btnModalRotate');
    const btnTerapkanCrop         = document.getElementById('btnTerapkanCrop');
    const croppedPhotoInput       = document.getElementById('croppedPhotoInput');
    const cropPreviewAlert        = document.getElementById('cropPreviewAlert');
    const btnReAdjust             = document.getElementById('btnReAdjust');
    const fotoProfilPreview       = document.getElementById('fotoProfilPreview');

    // State Transformasi
    let naturalW     = 0;
    let naturalH     = 0;
    let baseScale    = 1.0;
    let userZoom     = 1.0;
    let posX         = 0;
    let posY         = 0;
    let rotationDeg  = 0;
    let isDragging   = false;
    let startDragX   = 0;
    let startDragY   = 0;
    let lastPosX     = 0;
    let lastPosY     = 0;
    let activeDataUrl = '';

    // Perbarui Transformasi Gambar
    function updateTransform() {
        const curScale = baseScale * userZoom;
        cropImage.style.transform = 'translate(' + posX + 'px, ' + posY + 'px) rotate(' + rotationDeg + 'deg) scale(' + curScale + ')';
        if (badgeModalZoom) {
            badgeModalZoom.textContent = Math.round(userZoom * 100) + '%';
        }
        if (modalSliderZoom) {
            modalSliderZoom.value = userZoom;
        }
    }

    // Set Nilai Zoom
    function setZoom(val) {
        userZoom = Math.min(3.0, Math.max(0.5, parseFloat(val)));
        updateTransform();
    }

    // Reset ke Posisi Tengah
    function resetPosition() {
        posX = 0;
        posY = 0;
        rotationDeg = 0;
        userZoom = 1.0;
        updateTransform();
    }

    // Memuat Gambar ke dalam Viewport Modal
    function loadImageIntoCrop(src) {
        activeDataUrl = src;
        cropImage.crossOrigin = 'anonymous';
        cropImage.onload = function() {
            naturalW = cropImage.naturalWidth || 300;
            naturalH = cropImage.naturalHeight || 300;

            // Diameter crop guide = 240px
            baseScale = Math.max(240 / naturalW, 240 / naturalH);
            cropImage.style.width = naturalW + 'px';
            cropImage.style.height = naturalH + 'px';
            cropImage.style.marginTop = (-naturalH / 2) + 'px';
            cropImage.style.marginLeft = (-naturalW / 2) + 'px';

            resetPosition();
            modalSesuaikanFoto.show();
        };
        cropImage.src = src;
    }

    // 1. Event: Memilih File Foto Baru
    if (inputFotoProfil) {
        inputFotoProfil.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(evt) {
                    if (btnBukaCropModal) btnBukaCropModal.style.display = 'inline-block';
                    loadImageIntoCrop(evt.target.result);
                };
                reader.readAsDataURL(file);
            }
        });
    }

    // 2. Event: Tombol Atur Foto yang Dipilih
    if (btnBukaCropModal) {
        btnBukaCropModal.addEventListener('click', function() {
            if (activeDataUrl) {
                modalSesuaikanFoto.show();
            }
        });
    }

    // 3. Event: Sesuaikan Foto Saat Ini
    if (btnAdjustExistingPhoto) {
        btnAdjustExistingPhoto.addEventListener('click', function() {
            const curImg = fotoProfilPreview;
            if (curImg && curImg.tagName === 'IMG' && curImg.src) {
                loadImageIntoCrop(curImg.src);
            }
        });
    }

    // 4. Drag / Geser Foto pada Viewport (Mouse & Touch)
    function onDragStart(e) {
        isDragging = true;
        const pt = e.touches ? e.touches[0] : e;
        startDragX = pt.clientX;
        startDragY = pt.clientY;
        lastPosX = posX;
        lastPosY = posY;
        cropViewport.classList.add('is-dragging');
    }

    function onDragMove(e) {
        if (!isDragging) return;
        const pt = e.touches ? e.touches[0] : e;
        const dx = pt.clientX - startDragX;
        const dy = pt.clientY - startDragY;
        posX = lastPosX + dx;
        posY = lastPosY + dy;
        updateTransform();
    }

    function onDragEnd() {
        isDragging = false;
        cropViewport.classList.remove('is-dragging');
    }

    if (cropViewport) {
        cropViewport.addEventListener('mousedown', onDragStart);
        window.addEventListener('mousemove', onDragMove);
        window.addEventListener('mouseup', onDragEnd);

        cropViewport.addEventListener('touchstart', onDragStart, { passive: true });
        window.addEventListener('touchmove', onDragMove, { passive: true });
        window.addEventListener('touchend', onDragEnd);

        // Zoom dengan Scroll Mouse pada Viewport
        cropViewport.addEventListener('wheel', function(e) {
            e.preventDefault();
            const delta = e.deltaY < 0 ? 0.05 : -0.05;
            setZoom(userZoom + delta);
        }, { passive: false });
    }

    // Kontrol Slider & Tombol Zoom
    if (modalSliderZoom) {
        modalSliderZoom.addEventListener('input', function() {
            setZoom(this.value);
        });
    }
    if (btnModalZoomIn) {
        btnModalZoomIn.addEventListener('click', function() {
            setZoom(userZoom + 0.1);
        });
    }
    if (btnModalZoomOut) {
        btnModalZoomOut.addEventListener('click', function() {
            setZoom(userZoom - 0.1);
        });
    }
    if (btnModalResetPos) {
        btnModalResetPos.addEventListener('click', function() {
            resetPosition();
        });
    }
    if (btnModalRotate) {
        btnModalRotate.addEventListener('click', function() {
            rotationDeg = (rotationDeg + 90) % 360;
            updateTransform();
        });
    }
    if (btnReAdjust) {
        btnReAdjust.addEventListener('click', function() {
            if (activeDataUrl) {
                modalSesuaikanFoto.show();
            }
        });
    }

    // 5. Terapkan Foto Hasil Crop & Zoom dan Langsung Simpan Permanen ke Server
    if (btnTerapkanCrop) {
        btnTerapkanCrop.addEventListener('click', function() {
            const outputSize = 500; // Resolusi tinggi 500x500px
            const cropDiameter = 240; // Diameter circle guide
            const ratio = outputSize / cropDiameter;

            const canvas = document.createElement('canvas');
            canvas.width = outputSize;
            canvas.height = outputSize;
            const ctx = canvas.getContext('2d');

            ctx.imageSmoothingEnabled = true;
            ctx.imageSmoothingQuality = 'high';

            // Background putih bersih
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, outputSize, outputSize);

            // Transformasi kanvas sesuai posisi, rotasi dan skala pengguna
            ctx.save();
            ctx.translate(outputSize / 2, outputSize / 2);
            ctx.rotate((rotationDeg * Math.PI) / 180);
            ctx.translate(posX * ratio, posY * ratio);

            const drawScale = baseScale * userZoom * ratio;
            ctx.scale(drawScale, drawScale);
            ctx.drawImage(cropImage, -naturalW / 2, -naturalH / 2, naturalW, naturalH);
            ctx.restore();

            const finalDataUrl = canvas.toDataURL('image/jpeg', 0.92);

            // Simpan juga ke input hidden formulir
            if (croppedPhotoInput) {
                croppedPhotoInput.value = finalDataUrl;
            }

            // Tampilkan status loading pada tombol modal
            const originalBtnHtml = btnTerapkanCrop.innerHTML;
            btnTerapkanCrop.disabled = true;
            btnTerapkanCrop.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Menyimpan Foto...';

            // Simpan langsung ke backend via AJAX agar foto tersimpan di server & database
            const formData = new FormData();
            formData.append('action', 'ajax_simpan_foto');
            formData.append('cropped_photo', finalDataUrl);

            fetch('profil.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                btnTerapkanCrop.disabled = false;
                btnTerapkanCrop.innerHTML = originalBtnHtml;

                if (data.status === 'success') {
                    const freshUrl = data.foto_url;

                    // 1. Perbarui tampilan preview di kartu kiri secara instan
                    if (fotoProfilPreview) {
                        if (fotoProfilPreview.tagName === 'IMG') {
                            fotoProfilPreview.src = freshUrl;
                        } else {
                            const newImg = document.createElement('img');
                            newImg.id = 'fotoProfilPreview';
                            newImg.src = freshUrl;
                            newImg.alt = 'Foto Profil';
                            fotoProfilPreview.parentNode.replaceChild(newImg, fotoProfilPreview);
                        }
                    }

                    // 2. Perbarui foto profil di header navbar kanan (sebelah ikon lonceng)
                    const headerPhoto = document.getElementById('headerUserPhoto');
                    if (headerPhoto) {
                        if (headerPhoto.tagName === 'IMG') {
                            headerPhoto.src = freshUrl;
                        } else {
                            const newHeaderImg = document.createElement('img');
                            newHeaderImg.id = 'headerUserPhoto';
                            newHeaderImg.src = freshUrl;
                            newHeaderImg.alt = 'Foto Profil';
                            newHeaderImg.className = 'rounded-circle border border-2 border-white shadow-sm flex-shrink-0';
                            newHeaderImg.style.width = '34px';
                            newHeaderImg.style.height = '34px';
                            newHeaderImg.style.objectFit = 'cover';
                            newHeaderImg.style.backgroundColor = '#ffffff';
                            headerPhoto.parentNode.replaceChild(newHeaderImg, headerPhoto);
                        }
                    }

                    // 3. Tampilkan tombol "Sesuaikan Foto Saat Ini" jika sebelumnya tersembunyi
                    if (btnAdjustExistingPhoto) {
                        btnAdjustExistingPhoto.classList.remove('d-none');
                    }

                    // 4. Tampilkan notifikasi keberhasilan di formulir
                    if (cropPreviewAlert) {
                        cropPreviewAlert.classList.remove('d-none');
                        cropPreviewAlert.classList.add('d-flex');
                    }

                    // 5. Tutup modal penyesuaian foto
                    modalSesuaikanFoto.hide();
                } else {
                    alert('Gagal menyimpan foto: ' + (data.message || 'Terjadi kendala sistem.'));
                }
            })
            .catch(err => {
                btnTerapkanCrop.disabled = false;
                btnTerapkanCrop.innerHTML = originalBtnHtml;
                console.error(err);

                // Fallback tampilan lokal jika fetch error
                if (fotoProfilPreview) {
                    if (fotoProfilPreview.tagName === 'IMG') {
                        fotoProfilPreview.src = finalDataUrl;
                    } else {
                        const newImg = document.createElement('img');
                        newImg.id = 'fotoProfilPreview';
                        newImg.src = finalDataUrl;
                        newImg.alt = 'Foto Profil';
                        fotoProfilPreview.parentNode.replaceChild(newImg, fotoProfilPreview);
                    }
                }
                modalSesuaikanFoto.hide();
                alert('Foto disiapkan di formulir. Silakan klik tombol "Simpan Perubahan Akun & Profil" di bawah untuk menyimpan permanen.');
            });
        });
    }
});
</script>

    </div>
</div>

<?php
$clean_wa_dosen = "";
if (!empty($mhs["no_wa_dosen"])) {
    $clean_wa_dosen = preg_replace("/[^0-9]/", "", $mhs["no_wa_dosen"]);
    if (str_starts_with($clean_wa_dosen, "0")) {
        $clean_wa_dosen = "62" . substr($clean_wa_dosen, 1);
    }
}
?>
<!-- ============================================================
     MODAL LIHAT PROFIL DOSEN PEMBIMBING
============================================================ -->
<div class="modal fade" id="modalProfilDosen" tabindex="-1" aria-labelledby="modalProfilDosenLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header text-white border-0 py-3" style="background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 60%, #1e40af 100%);">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-person-vcard-fill text-info fs-5"></i>
                    <h5 class="modal-title fw-bold text-white mb-0" id="modalProfilDosenLabel">Profil Dosen Pembimbing</h5>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <div class="modal-body p-4">
                <!-- Foto & Identitas Utama Dosen -->
                <div class="text-center mb-4">
                    <div class="mb-3 position-relative d-inline-block mx-auto">
                        <?php if ($foto_dosen_src): ?>
                            <img src="<?= $foto_dosen_src; ?>" 
                                 alt="Foto Profil Dosen" 
                                 class="rounded-circle img-thumbnail shadow object-fit-cover" 
                                 style="width: 125px; height: 125px;">
                        <?php else: ?>
                            <div class="avatar-circle avatar-primary shadow mx-auto d-flex align-items-center justify-content-center text-primary rounded-circle" 
                                 style="width: 125px; height: 125px; font-size: 3.2rem;">
                                <i class="bi bi-person-fill"></i>
                            </div>
                        <?php endif; ?>
                        <span class="position-absolute bottom-0 end-0 p-2 bg-success border border-3 border-white rounded-circle shadow-sm" title="Status Pembimbing Aktif"></span>
                    </div>

                    <h5 class="fw-bold mb-1 text-dark"><?= htmlspecialchars($mhs['nama_dosen'] ?? 'Dosen Pembimbing'); ?></h5>
                    <p class="text-muted small mb-2">NIDN: <?= htmlspecialchars($mhs['nidn'] ?? '-'); ?></p>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-1.5 rounded-pill">
                        <i class="bi bi-award-fill me-1"></i>Dosen Pembimbing Utama Skripsi
                    </span>
                </div>

                <!-- Detail Biodata Profil Dosen -->
                <div class="list-group list-group-flush text-start small border rounded-3 p-3 bg-light mb-3">
                    <div class="list-group-item d-flex justify-content-between px-0 py-2 bg-transparent border-bottom">
                        <span class="text-muted"><i class="bi bi-person-badge me-2 text-primary"></i>ID Login / Akun:</span>
                        <strong class="text-primary">@<?= htmlspecialchars($mhs['username_dosen'] ?? 'dosen'); ?></strong>
                    </div>
                    <div class="list-group-item d-flex justify-content-between px-0 py-2 bg-transparent border-bottom">
                        <span class="text-muted"><i class="bi bi-envelope me-2 text-primary"></i>Email Resmi:</span>
                        <a href="mailto:<?= htmlspecialchars($mhs['email_dosen'] ?? ''); ?>" class="text-decoration-none fw-semibold text-secondary">
                            <?= htmlspecialchars($mhs['email_dosen'] ?? '-'); ?>
                        </a>
                    </div>
                    <div class="list-group-item d-flex justify-content-between px-0 py-2 bg-transparent border-bottom">
                        <span class="text-muted"><i class="bi bi-whatsapp me-2 text-success"></i>Nomor WhatsApp:</span>
                        <?php if (!empty($clean_wa_dosen)): ?>
                            <a href="https://wa.me/<?= $clean_wa_dosen; ?>" target="_blank" class="text-decoration-none fw-semibold text-success">
                                <?= htmlspecialchars($mhs['no_wa_dosen']); ?> <i class="bi bi-box-arrow-up-right small"></i>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </div>
                    <div class="list-group-item d-flex justify-content-between px-0 py-2 bg-transparent">
                        <span class="text-muted"><i class="bi bi-people me-2 text-primary"></i>Kapasitas Bimbingan:</span>
                        <strong><?= htmlspecialchars($mhs['kuota_bimbingan'] ?? '5'); ?> Mahasiswa</strong>
                    </div>
                </div>

                <!-- Jadwal Ketersediaan Bimbingan Dosen -->
                <div class="p-3 border rounded-3 bg-white">
                    <h6 class="fw-bold text-dark mb-2 small d-flex align-items-center">
                        <i class="bi bi-calendar-check text-primary me-2"></i>Jadwal Konsultasi Dosen
                    </h6>
                    <?php if (!empty($jadwal_dosen_list)): ?>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($jadwal_dosen_list as $jd): ?>
                                <span class="badge bg-light text-dark border px-2.5 py-1.5 small">
                                    <i class="bi bi-clock-fill text-primary me-1"></i>
                                    <strong><?= htmlspecialchars($jd['hari']); ?>:</strong> <?= date('H:i', strtotime($jd['jam_mulai'])); ?> - <?= date('H:i', strtotime($jd['jam_selesai'])); ?> WIB
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted small mb-0">
                            <i class="bi bi-info-circle me-1"></i>Jadwal konsultasi tatap muka / daring dapat disepakati secara fleksibel melalui fitur Chat atau WhatsApp.
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="modal-footer bg-light border-top d-flex justify-content-between">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
                <div class="d-flex gap-2">
                    <a href="jadwal_bimbingan.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-calendar-week me-1"></i>Jadwal Bimbingan
                    </a>
                    <?php if (!empty($clean_wa_dosen)): ?>
                        <a href="https://wa.me/<?= $clean_wa_dosen; ?>" target="_blank" class="btn btn-success btn-sm">
                            <i class="bi bi-whatsapp me-1"></i>WhatsApp
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once '../templates/footer.php';
?>
