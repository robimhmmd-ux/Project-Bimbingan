<?php
// admin/data_dosen.php - Manajemen Master Data Dosen Pembimbing
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
// PROSES AKSI FORM (POST): TAMBAH, EDIT, HAPUS
// =========================================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. TAMBAH DOSEN BARU
    if ($action === 'create') {
        $nidn            = trim($_POST['nidn'] ?? '');
        $nama_dosen      = trim($_POST['nama_dosen'] ?? '');
        $email           = trim($_POST['email'] ?? '');
        $no_whatsapp     = trim($_POST['no_whatsapp'] ?? '');
        $kuota_bimbingan = (int)($_POST['kuota_bimbingan'] ?? 10);
        $username        = trim($_POST['username'] ?? '');
        $password        = trim($_POST['password'] ?? '');

        if (empty($nidn) || empty($nama_dosen) || empty($email) || empty($username) || empty($password)) {
            $error_msg = 'Semua field wajib (NIDN, Nama, Email, Username, Password) harus diisi!';
        } elseif ($kuota_bimbingan < 1) {
            $error_msg = 'Kuota bimbingan minimal 1 mahasiswa.';
        } else {
            try {
                // Cek keunikan username di users
                $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");
                $stmtCheck->execute([':u' => $username]);
                if ($stmtCheck->fetch()) {
                    throw new Exception("Username '{$username}' sudah digunakan oleh pengguna lain.");
                }

                // Cek keunikan NIDN di dosen
                $stmtCheckNidn = $pdo->prepare("SELECT id FROM dosen WHERE nidn = :n LIMIT 1");
                $stmtCheckNidn->execute([':n' => $nidn]);
                if ($stmtCheckNidn->fetch()) {
                    throw new Exception("NIDN '{$nidn}' sudah terdaftar dalam sistem.");
                }

                // Mulai Transaksi PDO
                $pdo->beginTransaction();

                // 1. Tambah user baru (role: dosen)
                $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                $stmtUser = $pdo->prepare("
                    INSERT INTO users (username, password, role) 
                    VALUES (:username, :password, 'dosen')
                ");
                $stmtUser->execute([
                    ':username' => $username,
                    ':password' => $hashed_password
                ]);
                $user_id = $pdo->lastInsertId();

                // 2. Tambah profil dosen
                $stmtDosen = $pdo->prepare("
                    INSERT INTO dosen (user_id, nidn, nama_dosen, email, no_whatsapp, kuota_bimbingan) 
                    VALUES (:user_id, :nidn, :nama_dosen, :email, :no_whatsapp, :kuota_bimbingan)
                ");
                $stmtDosen->execute([
                    ':user_id'         => $user_id,
                    ':nidn'            => $nidn,
                    ':nama_dosen'      => $nama_dosen,
                    ':email'           => $email,
                    ':no_whatsapp'     => $no_whatsapp,
                    ':kuota_bimbingan' => $kuota_bimbingan
                ]);

                $pdo->commit();
                $success_msg = "Data dosen '{$nama_dosen}' dan akun login berhasil ditambahkan!";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error_msg = "Gagal menambahkan dosen: " . $e->getMessage();
            }
        }
    }

    // 2. EDIT DOSEN
    elseif ($action === 'update') {
        $dosen_id        = (int)($_POST['dosen_id'] ?? 0);
        $user_id         = (int)($_POST['user_id'] ?? 0);
        $nidn            = trim($_POST['nidn'] ?? '');
        $nama_dosen      = trim($_POST['nama_dosen'] ?? '');
        $email           = trim($_POST['email'] ?? '');
        $no_whatsapp     = trim($_POST['no_whatsapp'] ?? '');
        $kuota_bimbingan = (int)($_POST['kuota_bimbingan'] ?? 10);
        $username        = trim($_POST['username'] ?? '');
        $password        = trim($_POST['password'] ?? '');

        if ($dosen_id <= 0 || $user_id <= 0 || empty($nidn) || empty($nama_dosen) || empty($email) || empty($username)) {
            $error_msg = 'Data edit tidak valid atau ada field wajib yang kosong.';
        } elseif ($kuota_bimbingan < 1) {
            $error_msg = 'Kuota bimbingan minimal 1 mahasiswa.';
        } else {
            try {
                // Cek username unik kecuali untuk user_id ini
                $stmtCheckU = $pdo->prepare("SELECT id FROM users WHERE username = :u AND id != :uid LIMIT 1");
                $stmtCheckU->execute([':u' => $username, ':uid' => $user_id]);
                if ($stmtCheckU->fetch()) {
                    throw new Exception("Username '{$username}' sudah digunakan oleh pengguna lain.");
                }

                // Cek NIDN unik kecuali untuk dosen_id ini
                $stmtCheckN = $pdo->prepare("SELECT id FROM dosen WHERE nidn = :n AND id != :did LIMIT 1");
                $stmtCheckN->execute([':n' => $nidn, ':did' => $dosen_id]);
                if ($stmtCheckN->fetch()) {
                    throw new Exception("NIDN '{$nidn}' sudah digunakan oleh dosen lain.");
                }

                $pdo->beginTransaction();

                // Update users
                if (!empty($password)) {
                    $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                    $stmtUpUser = $pdo->prepare("UPDATE users SET username = :u, password = :p WHERE id = :uid");
                    $stmtUpUser->execute([':u' => $username, ':p' => $hashed_password, ':uid' => $user_id]);
                } else {
                    $stmtUpUser = $pdo->prepare("UPDATE users SET username = :u WHERE id = :uid");
                    $stmtUpUser->execute([':u' => $username, ':uid' => $user_id]);
                }

                // Update dosen
                $stmtUpDosen = $pdo->prepare("
                    UPDATE dosen 
                    SET nidn = :nidn, nama_dosen = :nama, email = :email, no_whatsapp = :wa, kuota_bimbingan = :kuota
                    WHERE id = :did
                ");
                $stmtUpDosen->execute([
                    ':nidn'  => $nidn,
                    ':nama'  => $nama_dosen,
                    ':email' => $email,
                    ':wa'    => $no_whatsapp,
                    ':kuota' => $kuota_bimbingan,
                    ':did'   => $dosen_id
                ]);

                $pdo->commit();
                $success_msg = "Data dosen '{$nama_dosen}' berhasil diperbarui!";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error_msg = "Gagal memperbarui dosen: " . $e->getMessage();
            }
        }
    }

    // 3. HAPUS DOSEN
    elseif ($action === 'delete') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        if ($user_id > 0) {
            try {
                $pdo->beginTransaction();
                // Menghapus user akan otomatis menghapus record di dosen karena ON DELETE CASCADE
                $stmtDel = $pdo->prepare("DELETE FROM users WHERE id = :uid AND role = 'dosen'");
                $stmtDel->execute([':uid' => $user_id]);
                $pdo->commit();
                $success_msg = "Data dosen dan akun terkait berhasil dihapus dari sistem.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error_msg = "Gagal menghapus dosen: " . $e->getMessage();
            }
        }
    }
}

// =========================================================================
// AMBIL DATA DOSEN DENGAN STATISTIK BEBAN BIMBINGAN
// =========================================================================
$query = "
    SELECT 
        d.*,
        u.username,
        COUNT(m.id) AS total_mahasiswa
    FROM dosen d
    INNER JOIN users u ON d.user_id = u.id
    LEFT JOIN mahasiswa m ON d.id = m.dosen_pembimbing_id
    GROUP BY d.id, u.username
    ORDER BY d.nama_dosen ASC
";
$dosen_list = $pdo->query($query)->fetchAll();

$page_title   = "Kelola Data Dosen";
$current_page = "dosen";

require_once '../templates/header.php';
require_once '../templates/sidebar_admin.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2 fw-bold text-dark mb-1">Manajemen Data Master Dosen</h1>
        <p class="text-muted small mb-0">Kelola informasi dosen pembimbing, akun sistem, dan kapasitas kuota bimbingan.</p>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalTambahDosen">
            <i class="bi bi-person-plus-fill me-1"></i> Tambah Dosen Baru
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

<!-- Tabel Daftar Dosen -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0 text-dark">
            <i class="bi bi-people-fill text-primary me-2"></i>Daftar Dosen Pembimbing (<?= count($dosen_list); ?> Dosen)
        </h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3" style="width: 50px;">No</th>
                        <th>NIDN</th>
                        <th>Nama Dosen & Kontak</th>
                        <th>Akun Login</th>
                        <th class="text-center">Kapasitas Bimbingan</th>
                        <th class="text-center" style="width: 170px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($dosen_list)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">Belum ada data dosen tersimpan.</td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $no = 1;
                        foreach ($dosen_list as $row): 
                            $bimbingan = (int)$row['total_mahasiswa'];
                            $kuota     = (int)$row['kuota_bimbingan'];
                            $sisa      = $kuota - $bimbingan;
                            $persen    = $kuota > 0 ? min(100, round(($bimbingan / $kuota) * 100)) : 0;
                        ?>
                            <tr>
                                <td class="ps-3 text-muted"><?= $no++; ?></td>
                                <td class="font-monospace fw-semibold"><?= htmlspecialchars($row['nidn']); ?></td>
                                <td>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($row['nama_dosen']); ?></div>
                                    <div class="small text-muted">
                                        <i class="bi bi-envelope me-1"></i><?= htmlspecialchars($row['email']); ?>
                                        <?php if (!empty($row['no_whatsapp'])): ?>
                                            <span class="mx-1">•</span>
                                            <i class="bi bi-whatsapp text-success me-1"></i><?= htmlspecialchars($row['no_whatsapp']); ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border">
                                        <i class="bi bi-person me-1"></i><?= htmlspecialchars($row['username']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="d-flex justify-content-between align-items-center small mb-1">
                                        <span>Terisi: <strong><?= $bimbingan; ?></strong> / <?= $kuota; ?></span>
                                        <span class="badge <?= ($sisa > 0) ? 'bg-success' : 'bg-danger'; ?>">
                                            <?= ($sisa > 0) ? "Sisa {$sisa}" : 'Penuh'; ?>
                                        </span>
                                    </div>
                                    <div class="progress" style="height: 6px;">
                                        <div class="progress-bar <?= ($persen >= 100) ? 'bg-danger' : (($persen >= 75) ? 'bg-warning' : 'bg-primary'); ?>" 
                                             role="progressbar" style="width: <?= $persen; ?>%"></div>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm">
                                        <a href="jadwal_dosen.php?dosen_id=<?= $row['id']; ?>" class="btn btn-outline-info" title="Atur Jadwal Dosen">
                                            <i class="bi bi-calendar-week"></i>
                                        </a>
                                        <button type="button" class="btn btn-outline-primary btn-edit" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#modalEditDosen"
                                                data-id="<?= $row['id']; ?>"
                                                data-userid="<?= $row['user_id']; ?>"
                                                data-nidn="<?= htmlspecialchars($row['nidn']); ?>"
                                                data-nama="<?= htmlspecialchars($row['nama_dosen']); ?>"
                                                data-email="<?= htmlspecialchars($row['email']); ?>"
                                                data-wa="<?= htmlspecialchars($row['no_whatsapp']); ?>"
                                                data-kuota="<?= $row['kuota_bimbingan']; ?>"
                                                data-username="<?= htmlspecialchars($row['username']); ?>"
                                                title="Edit Dosen">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-danger btn-delete" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#modalHapusDosen"
                                                data-userid="<?= $row['user_id']; ?>"
                                                data-nama="<?= htmlspecialchars($row['nama_dosen']); ?>"
                                                title="Hapus Dosen">
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
<!-- MODAL TAMBAH DOSEN -->
<!-- ========================================================================= -->
<div class="modal fade" id="modalTambahDosen" tabindex="-1" aria-labelledby="modalTambahDosenLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" action="data_dosen.php" class="modal-content">
            <input type="hidden" name="action" value="create">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="modalTambahDosenLabel"><i class="bi bi-person-plus text-primary me-2"></i>Tambah Dosen Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">NIDN <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="nidn" placeholder="Nomor Induk Dosen Nasional" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Nama Lengkap & Gelar <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="nama_dosen" placeholder="Contoh: Dr. Budi Santoso, M.Kom." required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Email Akademik <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" name="email" placeholder="nama@univ.ac.id" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Nomor WhatsApp</label>
                        <input type="text" class="form-control" name="no_whatsapp" placeholder="08xxxxxxxxxx">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label small fw-semibold">Kuota Bimbingan Maksimal <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="kuota_bimbingan" value="10" min="1" max="50" required>
                        <div class="form-text">Batas maksimal kuota mahasiswa yang dapat dibimbing oleh dosen ini.</div>
                    </div>

                    <hr class="my-3">
                    <h6 class="fw-bold text-secondary mb-0"><i class="bi bi-key me-1"></i>Informasi Akun Login Dosen</h6>

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
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Simpan Data Dosen</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL EDIT DOSEN -->
<!-- ========================================================================= -->
<div class="modal fade" id="modalEditDosen" tabindex="-1" aria-labelledby="modalEditDosenLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" action="data_dosen.php" class="modal-content">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="dosen_id" id="edit_dosen_id">
            <input type="hidden" name="user_id" id="edit_user_id">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="modalEditDosenLabel"><i class="bi bi-pencil-square text-primary me-2"></i>Perbarui Data Dosen</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">NIDN <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="nidn" id="edit_nidn" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Nama Lengkap & Gelar <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="nama_dosen" id="edit_nama_dosen" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Email Akademik <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" name="email" id="edit_email" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Nomor WhatsApp</label>
                        <input type="text" class="form-control" name="no_whatsapp" id="edit_no_whatsapp">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label small fw-semibold">Kuota Bimbingan Maksimal <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="kuota_bimbingan" id="edit_kuota_bimbingan" min="1" max="50" required>
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
                        <div class="form-text">Isi hanya jika ingin mereset kata sandi dosen.</div>
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
<!-- MODAL HAPUS DOSEN -->
<!-- ========================================================================= -->
<div class="modal fade" id="modalHapusDosen" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="data_dosen.php" class="modal-content">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="user_id" id="delete_user_id">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Konfirmasi Hapus</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Apakah Anda yakin ingin menghapus data dosen <strong id="delete_nama_dosen"></strong>?</p>
                <div class="alert alert-warning small mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    Menghapus dosen akan menonaktifkan akun login terkait dan melepaskan plotting mahasiswa yang dibimbing (status plotting mahasiswa kembali kosong).
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Ya, Hapus Dosen</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Populate Modal Edit Dosen
    const editBtns = document.querySelectorAll('.btn-edit');
    editBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('edit_dosen_id').value        = this.dataset.id;
            document.getElementById('edit_user_id').value         = this.dataset.userid;
            document.getElementById('edit_nidn').value            = this.dataset.nidn;
            document.getElementById('edit_nama_dosen').value      = this.dataset.nama;
            document.getElementById('edit_email').value           = this.dataset.email;
            document.getElementById('edit_no_whatsapp').value     = this.dataset.wa;
            document.getElementById('edit_kuota_bimbingan').value = this.dataset.kuota;
            document.getElementById('edit_username').value        = this.dataset.username;
        });
    });

    // Populate Modal Hapus Dosen
    const deleteBtns = document.querySelectorAll('.btn-delete');
    deleteBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('delete_user_id').value        = this.dataset.userid;
            document.getElementById('delete_nama_dosen').textContent = this.dataset.nama;
        });
    });
});
</script>

<?php
require_once '../templates/footer.php';
?>
