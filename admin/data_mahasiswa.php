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
        $judul_skripsi        = trim($_POST['judul_skripsi'] ?? 'Belum Ditentukan');
        $dosen_pembimbing_id  = !empty($_POST['dosen_pembimbing_id']) ? (int)$_POST['dosen_pembimbing_id'] : null;
        $status_kelulusan     = $_POST['status_kelulusan'] ?? 'proses_bimbingan';
        $username             = trim($_POST['username'] ?? '');
        $password             = trim($_POST['password'] ?? '');

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
                    INSERT INTO mahasiswa (user_id, nim, nama_lengkap, judul_skripsi, dosen_pembimbing_id, status_kelulusan)
                    VALUES (:uid, :nim, :nama, :judul, :did, :status)
                ");
                $stmtMhs->execute([
                    ':uid'    => $user_id,
                    ':nim'    => $nim,
                    ':nama'   => $nama_lengkap,
                    ':judul'  => !empty($judul_skripsi) ? $judul_skripsi : 'Belum Ditentukan',
                    ':did'    => $dosen_pembimbing_id,
                    ':status' => $status_kelulusan
                ]);

                $pdo->commit();
                $success_msg = "Data mahasiswa '{$nama_lengkap}' (NIM: {$nim}) berhasil ditambahkan!";
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

                // Update mahasiswa
                $stmtUpM = $pdo->prepare("
                    UPDATE mahasiswa 
                    SET nim = :nim, nama_lengkap = :nama, judul_skripsi = :judul, 
                        dosen_pembimbing_id = :did, status_kelulusan = :status 
                    WHERE id = :mid
                ");
                $stmtUpM->execute([
                    ':nim'    => $nim,
                    ':nama'   => $nama_lengkap,
                    ':judul'  => !empty($judul_skripsi) ? $judul_skripsi : 'Belum Ditentukan',
                    ':did'    => $dosen_pembimbing_id,
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

                // Jika nilai evaluasi diberikan, simpan pembaruan nilai pada bimbingan terakhir atau buat entri nilai
                if ($nilai_evaluasi !== null) {
                    $nilai_evaluasi = max(0, min(100, $nilai_evaluasi));
                    // Cari pengajuan bimbingan terakhir
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
$search_query  = trim($_GET['q'] ?? '');

$where = ["1=1"];
$params = [];

if ($filter_status !== 'all' && in_array($filter_status, ['proses_bimbingan', 'siap_sidang', 'lulus'])) {
    $where[] = "m.status_kelulusan = :st";
    $params[':st'] = $filter_status;
}

if (!empty($search_query)) {
    $where[] = "(m.nim LIKE :q OR m.nama_lengkap LIKE :q OR m.judul_skripsi LIKE :q)";
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
    ORDER BY m.id DESC
";
$stmt = $pdo->prepare($queryMhs);
$stmt->execute($params);
$mahasiswa_list = $stmt->fetchAll();

// Ambil daftar dosen untuk dropdown form
$dosen_dropdown = $pdo->query("SELECT id, nama_dosen, kuota_bimbingan FROM dosen ORDER BY nama_dosen ASC")->fetchAll();

$page_title   = "Kelola Data Mahasiswa & Kelulusan";
$current_page = "mahasiswa";

require_once '../templates/header.php';
require_once '../templates/sidebar_admin.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2 fw-bold text-dark mb-1">Manajemen Data Mahasiswa & Kelulusan</h1>
        <p class="text-muted small mb-0">Kelola master data mahasiswa, pembimbing skripsi, dan evaluasi konfigurasi nilai kelulusan.</p>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalTambahMhs">
            <i class="bi bi-person-plus-fill me-1"></i> Tambah Mahasiswa Baru
        </button>
    </div>
</div>

<!-- Alert Notifikasi -->
<?php if (!empty($success_msg)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($success_msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (!empty($error_msg)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error_msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Filter & Pencarian -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-3">
        <form method="GET" action="data_mahasiswa.php" class="row g-2 align-items-center">
            <div class="col-md-5">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" class="form-control" name="q" value="<?= htmlspecialchars($search_query); ?>" placeholder="Cari NIM, Nama, atau Judul Skripsi...">
                </div>
            </div>
            <div class="col-md-4">
                <select name="status" class="form-select" onchange="this.form.submit()">
                    <option value="all" <?= ($filter_status === 'all') ? 'selected' : ''; ?>>-- Semua Status Kelulusan --</option>
                    <option value="proses_bimbingan" <?= ($filter_status === 'proses_bimbingan') ? 'selected' : ''; ?>>Proses Bimbingan</option>
                    <option value="siap_sidang" <?= ($filter_status === 'siap_sidang') ? 'selected' : ''; ?>>Siap Sidang</option>
                    <option value="lulus" <?= ($filter_status === 'lulus') ? 'selected' : ''; ?>>Lulus</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-outline-primary w-100">Filter</button>
                <?php if ($filter_status !== 'all' || !empty($search_query)): ?>
                    <a href="data_mahasiswa.php" class="btn btn-outline-secondary" title="Reset Filter"><i class="bi bi-arrow-counterclockwise"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Tabel Mahasiswa -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0 text-dark">
            <i class="bi bi-mortarboard-fill text-primary me-2"></i>Daftar Mahasiswa Tugas Akhir (<?= count($mahasiswa_list); ?> Mahasiswa)
        </h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3" style="width: 50px;">No</th>
                        <th>NIM & Akun</th>
                        <th>Nama & Judul Skripsi</th>
                        <th>Dosen Pembimbing</th>
                        <th class="text-center">Nilai Progres</th>
                        <th class="text-center">Status Kelulusan</th>
                        <th class="text-center" style="width: 150px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($mahasiswa_list)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">Tidak ada data mahasiswa yang sesuai filter.</td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $no = 1;
                        foreach ($mahasiswa_list as $row): 
                            $nilai = (int)$row['nilai_terakhir'];
                        ?>
                            <tr>
                                <td class="ps-3 text-muted"><?= $no++; ?></td>
                                <td>
                                    <div class="font-monospace fw-bold text-primary"><?= htmlspecialchars($row['nim']); ?></div>
                                    <small class="badge bg-light text-muted border">user: <?= htmlspecialchars($row['username']); ?></small>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($row['nama_lengkap']); ?></div>
                                    <small class="text-muted d-block" style="max-width: 280px;">
                                        <?= htmlspecialchars($row['judul_skripsi']); ?>
                                    </small>
                                </td>
                                <td>
                                    <?php if ($row['nama_dosen']): ?>
                                        <span class="text-dark small fw-semibold"><i class="bi bi-person-check-fill text-success me-1"></i><?= htmlspecialchars($row['nama_dosen']); ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>Belum Diplot</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="d-inline-block text-center">
                                        <span class="fw-bold fs-6 <?= ($nilai >= 80) ? 'text-success' : (($nilai >= 60) ? 'text-primary' : 'text-warning'); ?>">
                                            <?= $nilai; ?>%
                                        </span>
                                        <div class="progress" style="height: 5px; width: 70px;">
                                            <div class="progress-bar <?= ($nilai >= 80) ? 'bg-success' : (($nilai >= 60) ? 'bg-primary' : 'bg-warning'); ?>" 
                                                 style="width: <?= $nilai; ?>%"></div>
                                        </div>
                                        <small class="text-muted" style="font-size: 0.75rem;"><?= $row['total_bimbingan']; ?> Bimb | <?= $row['total_acc']; ?> ACC</small>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <?php if ($row['status_kelulusan'] === 'lulus'): ?>
                                        <span class="badge bg-success px-2 py-1"><i class="bi bi-check2-circle me-1"></i>Lulus</span>
                                    <?php elseif ($row['status_kelulusan'] === 'siap_sidang'): ?>
                                        <span class="badge bg-primary px-2 py-1"><i class="bi bi-award me-1"></i>Siap Sidang</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary px-2 py-1"><i class="bi bi-clock-history me-1"></i>Proses Bimbingan</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm">
                                        <!-- Tombol Konfigurasi Nilai & Kelulusan -->
                                        <button type="button" class="btn btn-outline-success btn-kelulusan" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#modalKelulusan"
                                                data-id="<?= $row['id']; ?>"
                                                data-nama="<?= htmlspecialchars($row['nama_lengkap']); ?>"
                                                data-nim="<?= htmlspecialchars($row['nim']); ?>"
                                                data-status="<?= $row['status_kelulusan']; ?>"
                                                data-nilai="<?= $nilai; ?>"
                                                title="Konfigurasi Nilai & Kelulusan">
                                            <i class="bi bi-award-fill"></i>
                                        </button>
                                        <!-- Tombol Edit Mahasiswa -->
                                        <button type="button" class="btn btn-outline-primary btn-edit" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#modalEditMhs"
                                                data-id="<?= $row['id']; ?>"
                                                data-userid="<?= $row['user_id']; ?>"
                                                data-nim="<?= htmlspecialchars($row['nim']); ?>"
                                                data-nama="<?= htmlspecialchars($row['nama_lengkap']); ?>"
                                                data-judul="<?= htmlspecialchars($row['judul_skripsi']); ?>"
                                                data-dosenid="<?= $row['dosen_pembimbing_id'] ?? ''; ?>"
                                                data-status="<?= $row['status_kelulusan']; ?>"
                                                data-username="<?= htmlspecialchars($row['username']); ?>"
                                                title="Edit Data Mahasiswa">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                        <!-- Tombol Hapus Mahasiswa -->
                                        <button type="button" class="btn btn-outline-danger btn-delete" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#modalHapusMhs"
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
<!-- MODAL TAMBAH MAHASISWA -->
<!-- ========================================================================= -->
<div class="modal fade" id="modalTambahMhs" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" action="data_mahasiswa.php" class="modal-content">
            <input type="hidden" name="action" value="create">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-person-plus text-primary me-2"></i>Tambah Mahasiswa Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Nomor Induk Mahasiswa (NIM) <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="nim" placeholder="Contoh: 202401003" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Nama Lengkap Mahasiswa <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="nama_lengkap" placeholder="Nama mahasiswa sesuai KRS" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Judul Rencana / Proposal Skripsi</label>
                        <textarea class="form-control" name="judul_skripsi" rows="2" placeholder="Masukkan judul penelitian skripsi..."></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Dosen Pembimbing</label>
                        <select name="dosen_pembimbing_id" class="form-select">
                            <option value="">-- Belum Diplot (Tetapkan Nanti) --</option>
                            <?php foreach ($dosen_dropdown as $d): ?>
                                <option value="<?= $d['id']; ?>"><?= htmlspecialchars($d['nama_dosen']); ?> (Kuota: <?= $d['kuota_bimbingan']; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Status Awal Kelulusan <span class="text-danger">*</span></label>
                        <select name="status_kelulusan" class="form-select" required>
                            <option value="proses_bimbingan" selected>Proses Bimbingan</option>
                            <option value="siap_sidang">Siap Sidang</option>
                            <option value="lulus">Lulus</option>
                        </select>
                    </div>

                    <hr class="my-3">
                    <h6 class="fw-bold text-secondary mb-0"><i class="bi bi-key me-1"></i>Informasi Akun Login Mahasiswa</h6>

                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Username Login <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="username" placeholder="Username untuk masuk sistem" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" name="password" placeholder="Minimal 6 karakter" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
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
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square text-primary me-2"></i>Perbarui Data Mahasiswa</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">NIM <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="nim" id="edit_nim" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Nama Lengkap <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="nama_lengkap" id="edit_nama_lengkap" required>
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

                    <hr class="my-3">
                    <h6 class="fw-bold text-secondary mb-0"><i class="bi bi-key me-1"></i>Informasi Akun Login</h6>

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
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL KONFIGURASI NILAI & STATUS KELULUSAN MAHASISWA (FITUR 3) -->
<!-- ========================================================================= -->
<div class="modal fade" id="modalKelulusan" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="data_mahasiswa.php" class="modal-content">
            <input type="hidden" name="action" value="update_kelulusan">
            <input type="hidden" name="mahasiswa_id" id="kelulusan_mhs_id">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-award-fill me-2"></i>Konfigurasi Nilai & Status Kelulusan</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3 p-3 bg-light rounded border">
                    <div class="small text-muted">Mahasiswa Terpilih:</div>
                    <div class="fw-bold text-dark fs-6" id="kelulusan_nama"></div>
                    <div class="small text-muted font-monospace" id="kelulusan_nim"></div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Status Kelulusan Akademik <span class="text-danger">*</span></label>
                    <select name="status_kelulusan" id="kelulusan_status" class="form-select" required>
                        <option value="proses_bimbingan">Proses Bimbingan (Masih aktif revisi)</option>
                        <option value="siap_sidang">Siap Sidang (ACC seluruh bab bimbingan)</option>
                        <option value="lulus">Lulus (Telah menyelesaikan sidang & revisi final)</option>
                    </select>
                    <div class="form-text">Pembaruan status ini akan tercermin langsung pada portal mahasiswa dan dosen.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Konfigurasi Nilai Progres Bimbingan (0 - 100)</label>
                    <div class="input-group">
                        <input type="number" class="form-control" name="nilai_evaluasi" id="kelulusan_nilai" min="0" max="100" placeholder="0 - 100">
                        <span class="input-group-text">%</span>
                    </div>
                    <div class="form-text">Nilai akumulasi kemajuan/evaluasi skripsi mahasiswa.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
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
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Konfirmasi Hapus</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Apakah Anda yakin ingin menghapus data mahasiswa <strong id="delete_mhs_nama"></strong> (<span id="delete_mhs_nim" class="font-monospace"></span>)?</p>
                <div class="alert alert-danger small mb-0">
                    <i class="bi bi-exclamation-octagon me-1"></i>
                    Tindakan ini akan menghapus akun login dan seluruh berkas riwayat bimbingan serta logbook mahasiswa tersebut secara permanen.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
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
            document.getElementById('edit_judul_skripsi').value    = this.dataset.judul;
            document.getElementById('edit_dosen_id').value         = this.dataset.dosenid;
            document.getElementById('edit_status_kelulusan').value = this.dataset.status;
            document.getElementById('edit_username').value         = this.dataset.username;
        });
    });

    // Populate Modal Kelulusan (Fitur 3)
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
