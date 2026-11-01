<?php
// admin/data_mahasiswa.php - Manajemen Master Data Mahasiswa & Konfigurasi Kelulusan
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Proteksi akses role admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

require_once '../config/database.php';

$success_msg = '';
$error_msg   = '';

// =========================================================================
// PROSES AKSI FORM (POST): TAMBAH, EDIT, UPDATE KELULUSAN & NILAI, HAPUS
// =========================================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. TAMBAH MAHASISWA BARU
    if ($action === 'create') {
        $nim                  = trim($_POST['nim'] ?? '');
        $nama_lengkap         = trim($_POST['nama_lengkap'] ?? '');
        $kelas                = trim($_POST['kelas'] ?? 'TI-1A-2025');
        $judul_skripsi        = trim($_POST['judul_skripsi'] ?? 'Belum Ditentukan');
        $dosen_pembimbing_id  = !empty($_POST['dosen_pembimbing_id']) ? (int)$_POST['dosen_pembimbing_id'] : null;
        $status_kelulusan     = $_POST['status_kelulusan'] ?? 'proses_bimbingan';
        $username             = trim($_POST['username'] ?? '');
        $password             = trim($_POST['password'] ?? '');

        // Status pembimbing otomatis menunggu konfirmasi jika dospem dipilih
        $status_pembimbing    = !empty($dosen_pembimbing_id) ? 'menunggu_konfirmasi' : 'belum_diplot';

        if (empty($nim) || empty($nama_lengkap) || empty($username) || empty($password)) {
            $error_msg = 'Field NIM, Nama Lengkap, Username, dan Password wajib diisi!';
        } else {
            try {
                // Cek username unik
                $stmtCheckU = $pdo->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");
                $stmtCheckU->execute([':u' => $username]);
                if ($stmtCheckU->fetch()) {
                    throw new Exception("Username '{$username}' sudah digunakan oleh pengguna lain.");
                }

                // Cek NIM unik
                $stmtCheckN = $pdo->prepare("SELECT id FROM mahasiswa WHERE nim = :nim LIMIT 1");
                $stmtCheckN->execute([':nim' => $nim]);
                if ($stmtCheckN->fetch()) {
                    throw new Exception("NIM '{$nim}' sudah terdaftar dalam sistem.");
                }

                // Mulai Transaksi PDO
                $pdo->beginTransaction();

                // 1. Tambah user mahasiswa
                $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                $stmtUser = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (:u, :p, 'mahasiswa')");
                $stmtUser->execute([':u' => $username, ':p' => $hashed_password]);
                $user_id = $pdo->lastInsertId();

                // 2. Tambah profil mahasiswa
                $stmtMhs = $pdo->prepare("
                    INSERT INTO mahasiswa (user_id, nim, nama_lengkap, kelas, judul_skripsi, dosen_pembimbing_id, status_pembimbing, status_kelulusan)
                    VALUES (:uid, :nim, :nama, :kelas, :judul, :did, :spemb, :status)
                ");
                $stmtMhs->execute([
                    ':uid'    => $user_id,
                    ':nim'    => $nim,
                    ':nama'   => $nama_lengkap,
                    ':kelas'  => $kelas,
                    ':judul'  => !empty($judul_skripsi) ? $judul_skripsi : 'Belum Ditentukan',
                    ':did'    => $dosen_pembimbing_id,
                    ':spemb'  => $status_pembimbing,
                    ':status' => $status_kelulusan
                ]);

                // 3. Jika dospem langsung ditentukan, buatkan notifikasi ke mahasiswa
                if (!empty($dosen_pembimbing_id)) {
                    $stmtDsn = $pdo->prepare("SELECT nama_dosen FROM dosen WHERE id = :did LIMIT 1");
                    $stmtDsn->execute([':did' => $dosen_pembimbing_id]);
                    $nama_dosen = $stmtDsn->fetchColumn() ?: 'Dosen';

                    $stmtNotif = $pdo->prepare("
                        INSERT INTO notifikasi (user_id, judul, pesan, tipe, link_tujuan) 
                        VALUES (:uid, 'Dosen Pembimbing Ditetapkan', :pesan, 'pembimbing', 'index.php')
                    ");
                    $stmtNotif->execute([
                        ':uid'   => $user_id,
                        ':pesan' => "Anda telah ditetapkan dibimbing oleh {$nama_dosen}. Silakan konfirmasi di dashboard Anda."
                    ]);
                }

                $pdo->commit();
                $success_msg = "Data mahasiswa '{$nama_lengkap}' ({$kelas} - NIM: {$nim}) berhasil ditambahkan!";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error_msg = "Gagal menambahkan mahasiswa: " . $e->getMessage();
            }
        }
    }

    // 2. EDIT DATA MAHASISWA
    elseif ($action === 'update') {
        $mahasiswa_id        = (int)($_POST['mahasiswa_id'] ?? 0);
        $user_id             = (int)($_POST['user_id'] ?? 0);
        $nim                 = trim($_POST['nim'] ?? '');
        $nama_lengkap        = trim($_POST['nama_lengkap'] ?? '');
        $kelas               = trim($_POST['kelas'] ?? 'TI-1A-2025');
        $judul_skripsi       = trim($_POST['judul_skripsi'] ?? 'Belum Ditentukan');
        $dosen_pembimbing_id = !empty($_POST['dosen_pembimbing_id']) ? (int)$_POST['dosen_pembimbing_id'] : null;
        $status_kelulusan    = $_POST['status_kelulusan'] ?? 'proses_bimbingan';
        $username            = trim($_POST['username'] ?? '');
        $password            = trim($_POST['password'] ?? '');

        if ($mahasiswa_id <= 0 || $user_id <= 0 || empty($nim) || empty($nama_lengkap) || empty($username)) {
            $error_msg = 'Data edit tidak valid atau ada field wajib yang kosong.';
        } else {
            try {
                // Cek keunikan username
                $stmtCheckU = $pdo->prepare("SELECT id FROM users WHERE username = :u AND id != :uid LIMIT 1");
                $stmtCheckU->execute([':u' => $username, ':uid' => $user_id]);
                if ($stmtCheckU->fetch()) {
                    throw new Exception("Username '{$username}' sudah digunakan akun lain.");
                }

                // Cek keunikan NIM
                $stmtCheckN = $pdo->prepare("SELECT id FROM mahasiswa WHERE nim = :nim AND id != :mid LIMIT 1");
                $stmtCheckN->execute([':nim' => $nim, ':mid' => $mahasiswa_id]);
                if ($stmtCheckN->fetch()) {
                    throw new Exception("NIM '{$nim}' sudah terdaftar pada mahasiswa lain.");
                }

                $pdo->beginTransaction();

                // Update users
                if (!empty($password)) {
                    $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                    $stmtUpU = $pdo->prepare("UPDATE users SET username = :u, password = :p WHERE id = :uid");
                    $stmtUpU->execute([':u' => $username, ':p' => $hashed_password, ':uid' => $user_id]);
                } else {
                    $stmtUpU = $pdo->prepare("UPDATE users SET username = :u WHERE id = :uid");
                    $stmtUpU->execute([':u' => $username, ':uid' => $user_id]);
                }

                // Ambil data lama mahasiswa untuk cek apakah dospem berubah
                $stmtOld = $pdo->prepare("SELECT dosen_pembimbing_id, status_pembimbing FROM mahasiswa WHERE id = :mid");
                $stmtOld->execute([':mid' => $mahasiswa_id]);
                $oldMhs = $stmtOld->fetch();

                $spemb_baru = $oldMhs['status_pembimbing'] ?? 'belum_diplot';
                if ($dosen_pembimbing_id != $oldMhs['dosen_pembimbing_id']) {
                    $spemb_baru = !empty($dosen_pembimbing_id) ? 'menunggu_konfirmasi' : 'belum_diplot';
                }

                // Update mahasiswa
                $stmtUpM = $pdo->prepare("
                    UPDATE mahasiswa 
                    SET nim = :nim, nama_lengkap = :nama, kelas = :kelas, judul_skripsi = :judul, 
                        dosen_pembimbing_id = :did, status_pembimbing = :spemb, status_kelulusan = :status 
                    WHERE id = :mid
                ");
                $stmtUpM->execute([
                    ':nim'    => $nim,
                    ':nama'   => $nama_lengkap,
                    ':kelas'  => $kelas,
                    ':judul'  => !empty($judul_skripsi) ? $judul_skripsi : 'Belum Ditentukan',
                    ':did'    => $dosen_pembimbing_id,
                    ':spemb'  => $spemb_baru,
                    ':status' => $status_kelulusan,
                    ':mid'    => $mahasiswa_id
                ]);

                $pdo->commit();
                $success_msg = "Data mahasiswa '{$nama_lengkap}' berhasil diperbarui!";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error_msg = "Gagal memperbarui mahasiswa: " . $e->getMessage();
            }
        }
    }

    // 3. KONFIGURASI NILAI & STATUS KELULUSAN
    elseif ($action === 'update_kelulusan') {
        $mahasiswa_id     = (int)($_POST['mahasiswa_id'] ?? 0);
        $status_kelulusan = $_POST['status_kelulusan'] ?? 'proses_bimbingan';
        $nilai_evaluasi   = isset($_POST['nilai_evaluasi']) && $_POST['nilai_evaluasi'] !== '' ? (int)$_POST['nilai_evaluasi'] : null;

        if ($mahasiswa_id <= 0) {
            $error_msg = 'ID Mahasiswa tidak valid.';
        } else {
            try {
                $pdo->beginTransaction();

                // Update status_kelulusan mahasiswa
                $stmtKelulusan = $pdo->prepare("UPDATE mahasiswa SET status_kelulusan = :st WHERE id = :mid");
                $stmtKelulusan->execute([':st' => $status_kelulusan, ':mid' => $mahasiswa_id]);

                // Jika nilai evaluasi diberikan, simpan pembaruan nilai pada bimbingan terakhir
                if ($nilai_evaluasi !== null) {
                    $nilai_evaluasi = max(0, min(100, $nilai_evaluasi));
                    $stmtBimb = $pdo->prepare("
                        SELECT id FROM pengajuan_bimbingan 
                        WHERE mahasiswa_id = :mid 
                        ORDER BY id DESC LIMIT 1
                    ");
                    $stmtBimb->execute([':mid' => $mahasiswa_id]);
                    $lastBimb = $stmtBimb->fetch();

                    if ($lastBimb) {
                        $stmtUpNilai = $pdo->prepare("
                            UPDATE pengajuan_bimbingan 
                            SET nilai_progres = :np 
                            WHERE id = :bid
                        ");
                        $stmtUpNilai->execute([':np' => $nilai_evaluasi, ':bid' => $lastBimb['id']]);
                    }
                }

                $pdo->commit();
                $success_msg = "Status kelulusan & konfigurasi nilai berhasil diperbarui!";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error_msg = "Gagal memperbarui status kelulusan: " . $e->getMessage();
            }
        }
    }

    // 4. HAPUS MAHASISWA
    elseif ($action === 'delete') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        if ($user_id > 0) {
            try {
                $pdo->beginTransaction();
                // Hapus user otomatis cascade ke mahasiswa
                $stmtDel = $pdo->prepare("DELETE FROM users WHERE id = :uid AND role = 'mahasiswa'");
                $stmtDel->execute([':uid' => $user_id]);
                $pdo->commit();
                $success_msg = "Data mahasiswa dan seluruh riwayat bimbingannya berhasil dihapus.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error_msg = "Gagal menghapus mahasiswa: " . $e->getMessage();
            }
        }
    }
}

// =========================================================================
// FILTER & AMBIL DATA MAHASISWA
// =========================================================================
$filter_status = $_GET['status'] ?? 'all';
$filter_kelas  = $_GET['kelas'] ?? 'all';
$search_query  = trim($_GET['q'] ?? '');

$where = ["1=1"];
$params = [];

if ($filter_status !== 'all' && in_array($filter_status, ['proses_bimbingan', 'siap_sidang', 'lulus'])) {
    $where[] = "m.status_kelulusan = :st";
    $params[':st'] = $filter_status;
}

if ($filter_kelas !== 'all' && in_array($filter_kelas, ['TI-1A-2025', 'TI-1B-2025', 'TI-1C-2025'])) {
    $where[] = "m.kelas = :kelas";
    $params[':kelas'] = $filter_kelas;
}

if (!empty($search_query)) {
    $where[] = "(m.nim LIKE :q OR m.nama_lengkap LIKE :q OR m.judul_skripsi LIKE :q OR m.kelas LIKE :q)";
    $params[':q'] = "%{$search_query}%";
}

$where_clause = implode(" AND ", $where);

$queryMhs = "
    SELECT 
        m.*,
        u.username,
        d.nama_dosen,
        COALESCE(
            (SELECT nilai_progres FROM pengajuan_bimbingan WHERE mahasiswa_id = m.id ORDER BY id DESC LIMIT 1),
            0
        ) AS nilai_terakhir,
        (SELECT COUNT(*) FROM pengajuan_bimbingan WHERE mahasiswa_id = m.id) AS total_bimbingan,
        (SELECT COUNT(*) FROM logbook_bimbingan WHERE mahasiswa_id = m.id) AS total_acc
    FROM mahasiswa m
    INNER JOIN users u ON m.user_id = u.id
    LEFT JOIN dosen d ON m.dosen_pembimbing_id = d.id
    WHERE {$where_clause}
    ORDER BY m.kelas ASC, m.nama_lengkap ASC
";
$stmt = $pdo->prepare($queryMhs);
$stmt->execute($params);
$mahasiswa_list = $stmt->fetchAll();

// Metrik ringkasan
$total_all_mhs = $pdo->query("SELECT COUNT(*) FROM mahasiswa")->fetchColumn();
$count_bimb    = $pdo->query("SELECT COUNT(*) FROM mahasiswa WHERE status_kelulusan = 'proses_bimbingan'")->fetchColumn();
$count_siap    = $pdo->query("SELECT COUNT(*) FROM mahasiswa WHERE status_kelulusan = 'siap_sidang'")->fetchColumn();
$count_lulus   = $pdo->query("SELECT COUNT(*) FROM mahasiswa WHERE status_kelulusan = 'lulus'")->fetchColumn();

// Metrik per kelas
$count_1a = $pdo->query("SELECT COUNT(*) FROM mahasiswa WHERE kelas = 'TI-1A-2025'")->fetchColumn();
$count_1b = $pdo->query("SELECT COUNT(*) FROM mahasiswa WHERE kelas = 'TI-1B-2025'")->fetchColumn();
$count_1c = $pdo->query("SELECT COUNT(*) FROM mahasiswa WHERE kelas = 'TI-1C-2025'")->fetchColumn();

// Ambil daftar dosen untuk dropdown form
$dosen_dropdown = $pdo->query("SELECT id, nama_dosen, kuota_bimbingan FROM dosen ORDER BY nama_dosen ASC")->fetchAll();

$page_title   = "Kelola Data Mahasiswa & Kelulusan";
$current_page = "mahasiswa";

require_once '../templates/header.php';
require_once '../templates/sidebar_admin.php';
?>

<!-- Hero Banner Header -->
<div class="hero-banner d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
    <div>
        <div class="hero-tag">
            <i class="bi bi-mortarboard-fill"></i>
            <span>Direktori Mahasiswa Tugas Akhir (3 Kelas)</span>
        </div>
        <h2 class="fw-bold text-white mb-1">Manajemen Mahasiswa & Kelulusan</h2>
        <p class="text-white-50 small mb-0">Kelola master data mahasiswa 3 kelas (TI-1A, TI-1B, TI-1C), judul skripsi, status pembimbing, serta evaluasi kelulusan.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <button type="button" class="btn btn-light shadow-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalTambahMhs">
            <i class="bi bi-person-plus-fill text-primary me-1"></i> Tambah Mahasiswa Baru
        </button>
    </div>
</div>

<!-- Alert Notifikasi -->
<?php if (!empty($success_msg)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill me-2 fs-5"></i>
        <div><?= htmlspecialchars($success_msg); ?></div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (!empty($error_msg)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
        <div><?= htmlspecialchars($error_msg); ?></div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Stat Bar Status Kelulusan & Kelas -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="metric-card p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="small text-muted fw-bold text-uppercase">Total Mahasiswa</span>
                    <h3 class="fw-bold text-dark mb-0 mt-1"><?= $total_all_mhs; ?></h3>
                    <small class="text-muted" style="font-size: 0.72rem;">1A: <?= $count_1a; ?> | 1B: <?= $count_1b; ?> | 1C: <?= $count_1c; ?></small>
                </div>
                <div class="metric-icon-box bg-primary-subtle text-primary">
                    <i class="bi bi-people-fill"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="metric-card p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="small text-muted fw-bold text-uppercase">Proses Bimbingan</span>
                    <h3 class="fw-bold text-secondary mb-0 mt-1"><?= $count_bimb; ?></h3>
                    <small class="text-muted" style="font-size: 0.72rem;">Aktif konsultasi</small>
                </div>
                <div class="metric-icon-box bg-secondary-subtle text-secondary">
                    <i class="bi bi-clock-history"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="metric-card p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="small text-muted fw-bold text-uppercase">Siap Sidang</span>
                    <h3 class="fw-bold text-primary mb-0 mt-1"><?= $count_siap; ?></h3>
                    <small class="text-muted" style="font-size: 0.72rem;">Menunggu jadwal</small>
                </div>
                <div class="metric-icon-box bg-primary-subtle text-primary">
                    <i class="bi bi-award-fill"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="metric-card p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="small text-muted fw-bold text-uppercase">Telah Lulus</span>
                    <h3 class="fw-bold text-success mb-0 mt-1"><?= $count_lulus; ?></h3>
                    <small class="text-muted" style="font-size: 0.72rem;">Yudisium & Alumni</small>
                </div>
                <div class="metric-icon-box bg-success-subtle text-success">
                    <i class="bi bi-check2-all"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filter & Pencarian Modern dengan Filter Kelas -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-3">
        <form method="GET" action="data_mahasiswa.php" class="row g-2 align-items-center">
            <div class="col-md-4">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0 ps-0" name="q" value="<?= htmlspecialchars($search_query); ?>" placeholder="Cari NIM, Nama, atau Judul...">
                </div>
            </div>
            <!-- Filter Kelas -->
            <div class="col-md-3">
                <select name="kelas" class="form-select" onchange="this.form.submit()">
                    <option value="all" <?= ($filter_kelas === 'all') ? 'selected' : ''; ?>>-- Semua Kelas (3 Kelas) --</option>
                    <option value="TI-1A-2025" <?= ($filter_kelas === 'TI-1A-2025') ? 'selected' : ''; ?>>Kelas TI-1A-2025 (Pagi)</option>
                    <option value="TI-1B-2025" <?= ($filter_kelas === 'TI-1B-2025') ? 'selected' : ''; ?>>Kelas TI-1B-2025 (Pagi)</option>
                    <option value="TI-1C-2025" <?= ($filter_kelas === 'TI-1C-2025') ? 'selected' : ''; ?>>Kelas TI-1C-2025 (Malam)</option>
                </select>
            </div>
            <!-- Filter Status Kelulusan -->
            <div class="col-md-3">
                <select name="status" class="form-select" onchange="this.form.submit()">
                    <option value="all" <?= ($filter_status === 'all') ? 'selected' : ''; ?>>-- Semua Status Kelulusan --</option>
                    <option value="proses_bimbingan" <?= ($filter_status === 'proses_bimbingan') ? 'selected' : ''; ?>>Proses Bimbingan (Aktif)</option>
                    <option value="siap_sidang" <?= ($filter_status === 'siap_sidang') ? 'selected' : ''; ?>>Siap Sidang Munaqasyah</option>
                    <option value="lulus" <?= ($filter_status === 'lulus') ? 'selected' : ''; ?>>Telah Lulus</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
                <?php if ($filter_status !== 'all' || $filter_kelas !== 'all' || !empty($search_query)): ?>
                    <a href="data_mahasiswa.php" class="btn btn-outline-secondary" title="Reset Filter"><i class="bi bi-arrow-counterclockwise"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Tabel Mahasiswa -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2">
            <span class="p-2 rounded bg-info-subtle text-info">
                <i class="bi bi-mortarboard-fill"></i>
            </span>
            <div>
                <h6 class="fw-bold mb-0 text-dark">Daftar Mahasiswa Tugas Akhir</h6>
                <small class="text-muted">Total <?= count($mahasiswa_list); ?> mahasiswa ditampilkan</small>
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3" style="width: 50px;">No</th>
                        <th>Mahasiswa & Akun</th>
                        <th>Kelas</th>
                        <th>Judul Penelitian Skripsi</th>
                        <th>Dosen Pembimbing & Status SK</th>
                        <th class="text-center" style="width: 110px;">Progres</th>
                        <th class="text-center" style="width: 120px;">Status</th>
                        <th class="text-center" style="width: 150px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($mahasiswa_list)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-5">
                                <i class="bi bi-search fs-2 d-block text-secondary mb-2"></i>
                                Tidak ada data mahasiswa yang sesuai dengan filter pencarian.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $no = 1;
                        foreach ($mahasiswa_list as $row): 
                            $nilai = (int)$row['nilai_terakhir'];
                            $initial = strtoupper(substr($row['nama_lengkap'], 0, 1));
                            $kelas_clean = htmlspecialchars($row['kelas'] ?? 'TI-1A-2025');
                            $spemb = $row['status_pembimbing'] ?? 'belum_diplot';
                        ?>
                            <tr>
                                <td class="ps-3 text-muted"><?= $no++; ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="avatar-initial bg-primary-subtle text-primary">
                                            <?= $initial; ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($row['nama_lengkap']); ?></div>
                                            <div class="font-monospace text-primary small fw-semibold">NIM: <?= htmlspecialchars($row['nim']); ?></div>
                                            <small class="text-muted" style="font-size: 0.725rem;">user: <?= htmlspecialchars($row['username']); ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle fw-semibold">
                                        <i class="bi bi-building me-1"></i><?= $kelas_clean; ?>
                                    </span>
                                </td>
                                <td>
                                    <small class="text-dark fw-medium d-block text-truncate" style="max-width: 250px;" title="<?= htmlspecialchars($row['judul_skripsi']); ?>">
                                        <?= htmlspecialchars($row['judul_skripsi']); ?>
                                    </small>
                                </td>
                                <td>
                                    <?php if ($row['nama_dosen']): ?>
                                        <div class="text-dark small fw-semibold mb-1">
                                            <i class="bi bi-person-check-fill text-success me-1"></i><?= htmlspecialchars($row['nama_dosen']); ?>
                                        </div>
                                        <?php if ($spemb === 'aktif'): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.7rem;">
                                                <i class="bi bi-patch-check-fill me-1"></i>SK Aktif
                                            </span>
                                        <?php elseif ($spemb === 'menunggu_konfirmasi'): ?>
                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle" style="font-size: 0.7rem;">
                                                <i class="bi bi-clock-history me-1"></i>Menunggu Konfirmasi
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-info-subtle text-info border border-info-subtle" style="font-size: 0.7rem;">Terplot</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle">
                                            <i class="bi bi-exclamation-triangle me-1"></i>Belum Diplot
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="d-inline-block text-center">
                                        <span class="fw-bold fs-6 <?= ($nilai >= 80) ? 'text-success' : (($nilai >= 60) ? 'text-primary' : 'text-warning'); ?>">
                                            <?= $nilai; ?>%
                                        </span>
                                        <div class="progress" style="height: 6px; width: 70px;">
                                            <div class="progress-bar <?= ($nilai >= 80) ? 'bg-success' : (($nilai >= 60) ? 'bg-primary' : 'bg-warning'); ?>" 
                                                 style="width: <?= $nilai; ?>%"></div>
                                        </div>
                                        <small class="text-muted d-block" style="font-size: 0.68rem;"><?= $row['total_acc']; ?> Bab ACC</small>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <?php if ($row['status_kelulusan'] === 'lulus'): ?>
                                        <span class="badge bg-success"><i class="bi bi-check2-circle me-1"></i>Lulus</span>
                                    <?php elseif ($row['status_kelulusan'] === 'siap_sidang'): ?>
                                        <span class="badge bg-primary"><i class="bi bi-award me-1"></i>Siap Sidang</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><i class="bi bi-hourglass-split me-1"></i>Bimbingan</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm">
                                        <!-- Tombol Konfigurasi Nilai & Kelulusan -->
                                        <button type="button" class="btn btn-outline-success btn-kelulusan" 
                                                data-bs-toggle="modal" data-bs-target="#modalKelulusan"
                                                data-id="<?= $row['id']; ?>"
                                                data-nama="<?= htmlspecialchars($row['nama_lengkap']); ?>"
                                                data-nim="<?= htmlspecialchars($row['nim']); ?>"
                                                data-status="<?= $row['status_kelulusan']; ?>"
                                                data-nilai="<?= $nilai; ?>"
                                                title="Konfigurasi Nilai & Status Kelulusan">
                                            <i class="bi bi-award-fill"></i>
                                        </button>
                                        <!-- Tombol Edit Data Mahasiswa -->
                                        <button type="button" class="btn btn-outline-primary btn-edit" 
                                                data-bs-toggle="modal" data-bs-target="#modalEditMhs"
                                                data-id="<?= $row['id']; ?>"
                                                data-userid="<?= $row['user_id']; ?>"
                                                data-nim="<?= htmlspecialchars($row['nim']); ?>"
                                                data-nama="<?= htmlspecialchars($row['nama_lengkap']); ?>"
                                                data-kelas="<?= htmlspecialchars($row['kelas'] ?? 'TI-1A-2025'); ?>"
                                                data-judul="<?= htmlspecialchars($row['judul_skripsi']); ?>"
                                                data-dosenid="<?= $row['dosen_pembimbing_id']; ?>"
                                                data-status="<?= $row['status_kelulusan']; ?>"
                                                data-username="<?= htmlspecialchars($row['username']); ?>"
                                                title="Edit Data Mahasiswa">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                        <!-- Tombol Hapus Mahasiswa -->
                                        <button type="button" class="btn btn-outline-danger btn-delete" 
                                                data-bs-toggle="modal" data-bs-target="#modalHapusMhs"
                                                data-userid="<?= $row['user_id']; ?>"
                                                data-nama="<?= htmlspecialchars($row['nama_lengkap']); ?>"
                                                data-nim="<?= htmlspecialchars($row['nim']); ?>"
                                                title="Hapus Mahasiswa">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL TAMBAH MAHASISWA BARU -->
<!-- ========================================================================= -->
<div class="modal fade" id="modalTambahMhs" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" action="data_mahasiswa.php" class="modal-content">
            <input type="hidden" name="action" value="create">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-person-plus-fill me-2"></i>Tambah Mahasiswa Tugas Akhir Baru</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">NIM Mahasiswa <span class="text-danger">*</span></label>
                        <input type="text" class="form-control font-monospace" name="nim" placeholder="Contoh: 41257144" required>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small fw-semibold">Nama Lengkap Mahasiswa <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="nama_lengkap" placeholder="Nama mahasiswa sesuai KRS" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Kelas Mahasiswa <span class="text-danger">*</span></label>
                        <select name="kelas" class="form-select" required>
                            <option value="TI-1A-2025" selected>TI-1A-2025 (Pagi)</option>
                            <option value="TI-1B-2025">TI-1B-2025 (Pagi)</option>
                            <option value="TI-1C-2025">TI-1C-2025 (Malam)</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Judul Rencana / Proposal Skripsi</label>
                        <textarea class="form-control" name="judul_skripsi" rows="2" placeholder="Masukkan rencana topik penelitian skripsi..."></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Dosen Pembimbing</label>
                        <select name="dosen_pembimbing_id" class="form-select">
                            <option value="">-- Belum Diplot (Tetapkan Nanti) --</option>
                            <?php foreach ($dosen_dropdown as $d): ?>
                                <option value="<?= $d['id']; ?>"><?= htmlspecialchars($d['nama_dosen']); ?> (Kuota: <?= $d['kuota_bimbingan']; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text small">Jika dipilih, status pembimbing otomatis menjadi "Menunggu Konfirmasi".</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Status Awal Kelulusan <span class="text-danger">*</span></label>
                        <select name="status_kelulusan" class="form-select" required>
                            <option value="proses_bimbingan" selected>Proses Bimbingan</option>
                            <option value="siap_sidang">Siap Sidang</option>
                            <option value="lulus">Lulus</option>
                        </select>
                    </div>

                    <div class="col-12 mt-4">
                        <div class="p-3 bg-light rounded-3 border">
                            <h6 class="fw-bold text-dark mb-2"><i class="bi bi-key-fill text-primary me-1"></i>Informasi Akun Login Mahasiswa</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Username Login <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="username" placeholder="Gunakan NIM atau username tanpa spasi" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Password <span class="text-danger">*</span></label>
                                    <input type="password" class="form-control" name="password" placeholder="Minimal 6 karakter" required>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Simpan Data Mahasiswa</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL EDIT MAHASISWA -->
<!-- ========================================================================= -->
<div class="modal fade" id="modalEditMhs" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" action="data_mahasiswa.php" class="modal-content">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="mahasiswa_id" id="edit_mhs_id">
            <input type="hidden" name="user_id" id="edit_user_id">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Perbarui Data Mahasiswa</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">NIM <span class="text-danger">*</span></label>
                        <input type="text" class="form-control font-monospace" name="nim" id="edit_nim" required>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small fw-semibold">Nama Lengkap <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="nama_lengkap" id="edit_nama_lengkap" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Kelas <span class="text-danger">*</span></label>
                        <select name="kelas" id="edit_kelas" class="form-select" required>
                            <option value="TI-1A-2025">TI-1A-2025</option>
                            <option value="TI-1B-2025">TI-1B-2025</option>
                            <option value="TI-1C-2025">TI-1C-2025</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Judul Skripsi</label>
                        <textarea class="form-control" name="judul_skripsi" id="edit_judul_skripsi" rows="2"></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Dosen Pembimbing</label>
                        <select name="dosen_pembimbing_id" id="edit_dosen_id" class="form-select">
                            <option value="">-- Belum Diplot / Tanpa Pembimbing --</option>
                            <?php foreach ($dosen_dropdown as $d): ?>
                                <option value="<?= $d['id']; ?>"><?= htmlspecialchars($d['nama_dosen']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Status Kelulusan <span class="text-danger">*</span></label>
                        <select name="status_kelulusan" id="edit_status_kelulusan" class="form-select" required>
                            <option value="proses_bimbingan">Proses Bimbingan</option>
                            <option value="siap_sidang">Siap Sidang</option>
                            <option value="lulus">Lulus</option>
                        </select>
                    </div>

                    <div class="col-12 mt-4">
                        <div class="p-3 bg-light rounded-3 border">
                            <h6 class="fw-bold text-dark mb-2"><i class="bi bi-key-fill text-primary me-1"></i>Informasi Akun Login</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Username Login <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="username" id="edit_username" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Password Baru (Opsional)</label>
                                    <input type="password" class="form-control" name="password" placeholder="Kosongkan jika tidak diubah">
                                    <div class="form-text">Isi hanya bila ingin mereset password mahasiswa.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL KONFIGURASI NILAI & STATUS KELULUSAN MAHASISWA -->
<!-- ========================================================================= -->
<div class="modal fade" id="modalKelulusan" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="data_mahasiswa.php" class="modal-content">
            <input type="hidden" name="action" value="update_kelulusan">
            <input type="hidden" name="mahasiswa_id" id="kelulusan_mhs_id">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-award-fill me-2"></i>Konfigurasi Kelulusan & Nilai</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3 p-3 bg-light rounded-3 border">
                    <div class="small text-muted">Mahasiswa Terpilih:</div>
                    <div class="fw-bold text-dark fs-6" id="kelulusan_nama"></div>
                    <div class="small text-muted font-monospace" id="kelulusan_nim"></div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Status Kelulusan Akademik <span class="text-danger">*</span></label>
                    <select name="status_kelulusan" id="kelulusan_status" class="form-select" required>
                        <option value="proses_bimbingan">Proses Bimbingan (Masih aktif bimbingan/revisi)</option>
                        <option value="siap_sidang">Siap Sidang (ACC seluruh bab skripsi)</option>
                        <option value="lulus">Lulus (Telah lulus sidang munaqasyah)</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Konfigurasi Nilai Progres Bimbingan (0 - 100)</label>
                    <div class="input-group">
                        <input type="number" class="form-control" name="nilai_evaluasi" id="kelulusan_nilai" min="0" max="100" placeholder="0 - 100">
                        <span class="input-group-text bg-white">%</span>
                    </div>
                    <div class="form-text">Nilai persentase kemajuan bimbingan skripsi.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Tutup</button>
                <button type="submit" class="btn btn-success"><i class="bi bi-check2-circle me-1"></i>Simpan Konfigurasi</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL HAPUS MAHASISWA -->
<!-- ========================================================================= -->
<div class="modal fade" id="modalHapusMhs" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="data_mahasiswa.php" class="modal-content">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="user_id" id="delete_mhs_userid">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-exclamation-triangle-fill me-2"></i>Konfirmasi Hapus Mahasiswa</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <p class="mb-3">Apakah Anda yakin ingin menghapus data mahasiswa <strong id="delete_mhs_nama" class="text-danger"></strong> (<span id="delete_mhs_nim" class="font-monospace"></span>)?</p>
                <div class="alert alert-danger small mb-0">
                    <i class="bi bi-exclamation-octagon-fill me-1"></i>
                    Tindakan ini akan menghapus akun login dan seluruh berkas riwayat bimbingan serta logbook mahasiswa tersebut secara permanen.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Ya, Hapus Mahasiswa</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Populate Modal Edit
    const editBtns = document.querySelectorAll('.btn-edit');
    editBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('edit_mhs_id').value           = this.dataset.id;
            document.getElementById('edit_user_id').value          = this.dataset.userid;
            document.getElementById('edit_nim').value              = this.dataset.nim;
            document.getElementById('edit_nama_lengkap').value     = this.dataset.nama;
            document.getElementById('edit_kelas').value            = this.dataset.kelas || 'TI-1A-2025';
            document.getElementById('edit_judul_skripsi').value    = this.dataset.judul;
            document.getElementById('edit_dosen_id').value         = this.dataset.dosenid;
            document.getElementById('edit_status_kelulusan').value = this.dataset.status;
            document.getElementById('edit_username').value         = this.dataset.username;
        });
    });

    // Populate Modal Kelulusan
    const kelulusanBtns = document.querySelectorAll('.btn-kelulusan');
    kelulusanBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('kelulusan_mhs_id').value      = this.dataset.id;
            document.getElementById('kelulusan_nama').textContent  = this.dataset.nama;
            document.getElementById('kelulusan_nim').textContent   = "NIM: " + this.dataset.nim;
            document.getElementById('kelulusan_status').value      = this.dataset.status;
            document.getElementById('kelulusan_nilai').value       = this.dataset.nilai;
        });
    });

    // Populate Modal Hapus
    const deleteBtns = document.querySelectorAll('.btn-delete');
    deleteBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('delete_mhs_userid').value     = this.dataset.userid;
            document.getElementById('delete_mhs_nama').textContent = this.dataset.nama;
            document.getElementById('delete_mhs_nim').textContent  = this.dataset.nim;
        });
    });
});
</script>

<?php
require_once '../templates/footer.php';
?>
