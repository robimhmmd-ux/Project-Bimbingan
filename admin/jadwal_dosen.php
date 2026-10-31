<?php
// admin/jadwal_dosen.php - Konfigurasi Jadwal & Kesibukan Harian Dosen
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
// PROSES AKSI FORM (POST): TAMBAH, EDIT, TOGGLE STATUS, HAPUS
// =========================================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. TAMBAH JADWAL BARU
    if ($action === 'create') {
        $dosen_id     = (int)($_POST['dosen_id'] ?? 0);
        $hari         = $_POST['hari'] ?? '';
        $jam_mulai    = trim($_POST['jam_mulai'] ?? '');
        $jam_selesai  = trim($_POST['jam_selesai'] ?? '');
        $status_aktif = $_POST['status_aktif'] ?? 'aktif';

        $valid_days = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

        if ($dosen_id <= 0 || !in_array($hari, $valid_days) || empty($jam_mulai) || empty($jam_selesai)) {
            $error_msg = 'Semua field wajib diisi dengan benar!';
        } elseif ($jam_selesai <= $jam_mulai) {
            $error_msg = 'Jam selesai bimbingan harus lebih besar dari jam mulai!';
        } else {
            try {
                // Cek kemungkinan bentrok dengan jadwal dosen yang sama di hari yang sama
                $stmtCek = $pdo->prepare("
                    SELECT id FROM jadwal_dosen 
                    WHERE dosen_id = :did AND hari = :hari 
                    AND (
                        (jam_mulai < :js AND jam_selesai > :jm)
                    ) LIMIT 1
                ");
                $stmtCek->execute([
                    ':did'  => $dosen_id,
                    ':hari' => $hari,
                    ':jm'   => $jam_mulai,
                    ':js'   => $jam_selesai
                ]);

                if ($stmtCek->fetch()) {
                    throw new Exception("Terdapat jadwal yang bertabrakan waktu pada hari {$hari} untuk dosen ini.");
                }

                $stmtIns = $pdo->prepare("
                    INSERT INTO jadwal_dosen (dosen_id, hari, jam_mulai, jam_selesai, status_aktif) 
                    VALUES (:did, :hari, :jm, :js, :st)
                ");
                $stmtIns->execute([
                    ':did'  => $dosen_id,
                    ':hari' => $hari,
                    ':jm'   => $jam_mulai,
                    ':js'   => $jam_selesai,
                    ':st'   => $status_aktif
                ]);

                $success_msg = "Jadwal bimbingan hari {$hari} ({$jam_mulai} - {$jam_selesai}) berhasil ditambahkan!";
            } catch (Exception $e) {
                $error_msg = "Gagal menambahkan jadwal: " . $e->getMessage();
            }
        }
    }

    // 2. EDIT JADWAL
    elseif ($action === 'update') {
        $jadwal_id    = (int)($_POST['jadwal_id'] ?? 0);
        $dosen_id     = (int)($_POST['dosen_id'] ?? 0);
        $hari         = $_POST['hari'] ?? '';
        $jam_mulai    = trim($_POST['jam_mulai'] ?? '');
        $jam_selesai  = trim($_POST['jam_selesai'] ?? '');
        $status_aktif = $_POST['status_aktif'] ?? 'aktif';

        $valid_days = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

        if ($jadwal_id <= 0 || $dosen_id <= 0 || !in_array($hari, $valid_days) || empty($jam_mulai) || empty($jam_selesai)) {
            $error_msg = 'Data edit tidak valid atau field kosong.';
        } elseif ($jam_selesai <= $jam_mulai) {
            $error_msg = 'Jam selesai harus lebih besar dari jam mulai!';
        } else {
            try {
                // Cek bentrok kecuali jadwal ini sendiri
                $stmtCek = $pdo->prepare("
                    SELECT id FROM jadwal_dosen 
                    WHERE dosen_id = :did AND hari = :hari AND id != :jid
                    AND (
                        (jam_mulai < :js AND jam_selesai > :jm)
                    ) LIMIT 1
                ");
                $stmtCek->execute([
                    ':did'  => $dosen_id,
                    ':hari' => $hari,
                    ':jid'  => $jadwal_id,
                    ':jm'   => $jam_mulai,
                    ':js'   => $jam_selesai
                ]);

                if ($stmtCek->fetch()) {
                    throw new Exception("Terdapat bentrok waktu dengan slot jadwal lain untuk dosen ini.");
                }

                $stmtUp = $pdo->prepare("
                    UPDATE jadwal_dosen 
                    SET dosen_id = :did, hari = :hari, jam_mulai = :jm, jam_selesai = :js, status_aktif = :st 
                    WHERE id = :jid
                ");
                $stmtUp->execute([
                    ':did'  => $dosen_id,
                    ':hari' => $hari,
                    ':jm'   => $jam_mulai,
                    ':js'   => $jam_selesai,
                    ':st'   => $status_aktif,
                    ':jid'  => $jadwal_id
                ]);

                $success_msg = "Jadwal bimbingan berhasil diperbarui!";
            } catch (Exception $e) {
                $error_msg = "Gagal memperbarui jadwal: " . $e->getMessage();
            }
        }
    }

    // 3. TOGGLE STATUS AKTIF / LIBUR CEPAT
    elseif ($action === 'toggle_status') {
        $jadwal_id = (int)($_POST['jadwal_id'] ?? 0);
        $status_baru = ($_POST['status_sekarang'] === 'aktif') ? 'libur' : 'aktif';

        if ($jadwal_id > 0) {
            try {
                $stmtTog = $pdo->prepare("UPDATE jadwal_dosen SET status_aktif = :st WHERE id = :jid");
                $stmtTog->execute([':st' => $status_baru, ':jid' => $jadwal_id]);
                $success_msg = "Status slot jadwal berhasil diubah menjadi: " . strtoupper($status_baru);
            } catch (Exception $e) {
                $error_msg = "Gagal mengubah status: " . $e->getMessage();
            }
        }
    }

    // 4. HAPUS JADWAL
    elseif ($action === 'delete') {
        $jadwal_id = (int)($_POST['jadwal_id'] ?? 0);
        if ($jadwal_id > 0) {
            try {
                $stmtDel = $pdo->prepare("DELETE FROM jadwal_dosen WHERE id = :jid");
                $stmtDel->execute([':jid' => $jadwal_id]);
                $success_msg = "Slot jadwal bimbingan berhasil dihapus.";
            } catch (Exception $e) {
                $error_msg = "Gagal menghapus jadwal: " . $e->getMessage();
            }
        }
    }
}

// =========================================================================
// AMBIL DATA & FILTER DOSEN
// =========================================================================
$filter_dosen_id = isset($_GET['dosen_id']) && $_GET['dosen_id'] !== '' ? (int)$_GET['dosen_id'] : 0;

// Daftar dosen untuk dropdown filter & modal
$dosen_list = $pdo->query("SELECT id, nidn, nama_dosen FROM dosen ORDER BY nama_dosen ASC")->fetchAll();

$where = ["1=1"];
$params = [];

if ($filter_dosen_id > 0) {
    $where[] = "j.dosen_id = :did";
    $params[':did'] = $filter_dosen_id;
}

$where_sql = implode(" AND ", $where);

// Ambil jadwal dosen
$queryJadwal = "
    SELECT 
        j.*,
        d.nama_dosen,
        d.nidn
    FROM jadwal_dosen j
    INNER JOIN dosen d ON j.dosen_id = d.id
    WHERE {$where_sql}
    ORDER BY 
        FIELD(j.hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'),
        j.jam_mulai ASC
";
$stmtJ = $pdo->prepare($queryJadwal);
$stmtJ->execute($params);
$jadwal_list = $stmtJ->fetchAll();

$page_title   = "Konfigurasi Jadwal Harian Dosen";
$current_page = "jadwal_dosen";

require_once '../templates/header.php';
require_once '../templates/sidebar_admin.php';
?>

<!-- Hero Banner Header -->
<div class="hero-banner d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
    <div>
        <div class="hero-tag">
            <i class="bi bi-calendar2-week-fill text-warning"></i>
            <span>Manajemen Ketersediaan Waktu</span>
        </div>
        <h2 class="fw-bold text-white mb-1">Konfigurasi Jadwal Harian Dosen</h2>
        <p class="text-white-50 small mb-0">Atur ketersediaan hari, jam, serta status aktif/libur bimbingan skripsi dosen pembimbing.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <button type="button" class="btn btn-light shadow-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalTambahJadwal">
            <i class="bi bi-calendar-plus-fill text-primary me-1"></i> Tambah Slot Jadwal
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

<!-- Filter Berdasarkan Dosen -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-3">
        <form method="GET" action="jadwal_dosen.php" class="row g-2 align-items-center">
            <div class="col-md-6">
                <label class="form-label small fw-semibold text-secondary mb-1">Pilih Dosen untuk Filter Jadwal:</label>
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-funnel text-muted"></i></span>
                    <select name="dosen_id" class="form-select border-start-0 ps-0" onchange="this.form.submit()">
                        <option value="0">-- Semua Dosen Pembimbing --</option>
                        <?php foreach ($dosen_list as $d): ?>
                            <option value="<?= $d['id']; ?>" <?= ($filter_dosen_id == $d['id']) ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($d['nama_dosen']); ?> (NIDN: <?= htmlspecialchars($d['nidn']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="col-md-3 pt-md-4">
                <?php if ($filter_dosen_id > 0): ?>
                    <a href="jadwal_dosen.php" class="btn btn-outline-secondary w-100"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset Filter</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Tabel Jadwal Dosen -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2">
            <span class="p-2 rounded bg-warning-subtle text-warning">
                <i class="bi bi-clock-history"></i>
            </span>
            <div>
                <h6 class="fw-bold mb-0 text-dark">Daftar Slot Waktu & Kesibukan Bimbingan</h6>
                <small class="text-muted">Total <?= count($jadwal_list); ?> slot jadwal terdaftar</small>
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3" style="width: 50px;">No</th>
                        <th>Dosen Pembimbing</th>
                        <th>Hari Bimbingan</th>
                        <th>Jam Mulai - Selesai</th>
                        <th class="text-center">Status Slot</th>
                        <th class="text-center" style="width: 180px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($jadwal_list)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-5">
                                <i class="bi bi-calendar-x fs-2 d-block text-secondary mb-2"></i>
                                Belum ada slot jadwal yang didaftarkan.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $no = 1;
                        foreach ($jadwal_list as $row): 
                            $is_aktif = ($row['status_aktif'] === 'aktif');
                            $initial  = strtoupper(substr($row['nama_dosen'], 0, 1));
                        ?>
                            <tr>
                                <td class="ps-3 text-muted"><?= $no++; ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="avatar-initial bg-warning-subtle text-warning">
                                            <?= $initial; ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($row['nama_dosen']); ?></div>
                                            <span class="text-muted font-monospace small">NIDN: <?= htmlspecialchars($row['nidn']); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border px-3 py-1 fs-6">
                                        <i class="bi bi-calendar-event me-1 text-primary"></i><?= htmlspecialchars($row['hari']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="font-monospace fw-semibold text-dark">
                                        <i class="bi bi-clock me-1 text-secondary"></i>
                                        <?= substr($row['jam_mulai'], 0, 5); ?> - <?= substr($row['jam_selesai'], 0, 5); ?> WIB
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php if ($is_aktif): ?>
                                        <span class="badge bg-success px-3 py-1"><i class="bi bi-check-circle me-1"></i>Aktif (Tersedia)</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger px-3 py-1"><i class="bi bi-dash-circle me-1"></i>Libur / Sibuk</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm">
                                        <!-- Tombol Cepat Toggle Status Aktif / Libur -->
                                        <form method="POST" action="jadwal_dosen.php" class="d-inline">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="jadwal_id" value="<?= $row['id']; ?>">
                                            <input type="hidden" name="status_sekarang" value="<?= $row['status_aktif']; ?>">
                                            <button type="submit" class="btn btn-outline-<?= $is_aktif ? 'warning' : 'success'; ?>" 
                                                    title="<?= $is_aktif ? 'Tandai Libur/Sibuk' : 'Aktifkan Slot'; ?>">
                                                <i class="bi bi-<?= $is_aktif ? 'pause-circle' : 'play-circle'; ?>"></i>
                                            </button>
                                        </form>

                                        <!-- Tombol Edit Jadwal -->
                                        <button type="button" class="btn btn-outline-primary btn-edit-jadwal" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#modalEditJadwal"
                                                data-id="<?= $row['id']; ?>"
                                                data-dosenid="<?= $row['dosen_id']; ?>"
                                                data-hari="<?= $row['hari']; ?>"
                                                data-jammulai="<?= substr($row['jam_mulai'], 0, 5); ?>"
                                                data-jamselesai="<?= substr($row['jam_selesai'], 0, 5); ?>"
                                                data-status="<?= $row['status_aktif']; ?>"
                                                title="Edit Slot Jadwal">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>

                                        <!-- Tombol Hapus Jadwal -->
                                        <form method="POST" action="jadwal_dosen.php" class="d-inline" onsubmit="return confirm('Apakah Anda yakin ingin menghapus slot jadwal ini?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="jadwal_id" value="<?= $row['id']; ?>">
                                            <button type="submit" class="btn btn-outline-danger" title="Hapus Jadwal">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
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
<!-- MODAL TAMBAH SLOT JADWAL -->
<!-- ========================================================================= -->
<div class="modal fade" id="modalTambahJadwal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="jadwal_dosen.php" class="modal-content">
            <input type="hidden" name="action" value="create">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-calendar-plus-fill me-2"></i>Tambah Slot Jadwal Dosen</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Pilih Dosen <span class="text-danger">*</span></label>
                    <select name="dosen_id" class="form-select" required>
                        <option value="">-- Pilih Dosen Pembimbing --</option>
                        <?php foreach ($dosen_list as $d): ?>
                            <option value="<?= $d['id']; ?>" <?= ($filter_dosen_id == $d['id']) ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($d['nama_dosen']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Hari Bimbingan <span class="text-danger">*</span></label>
                    <select name="hari" class="form-select" required>
                        <option value="Senin">Senin</option>
                        <option value="Selasa">Selasa</option>
                        <option value="Rabu">Rabu</option>
                        <option value="Kamis">Kamis</option>
                        <option value="Jumat">Jumat</option>
                        <option value="Sabtu">Sabtu</option>
                    </select>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Jam Mulai <span class="text-danger">*</span></label>
                        <input type="time" class="form-control" name="jam_mulai" required value="09:00">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Jam Selesai <span class="text-danger">*</span></label>
                        <input type="time" class="form-control" name="jam_selesai" required value="12:00">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Status Slot Bimbingan <span class="text-danger">*</span></label>
                    <select name="status_aktif" class="form-select" required>
                        <option value="aktif" selected>Aktif (Dapat dipilih mahasiswa untuk bimbingan)</option>
                        <option value="libur">Libur / Sibuk (Tidak menerima pengajuan)</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Simpan Jadwal</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL EDIT SLOT JADWAL -->
<!-- ========================================================================= -->
<div class="modal fade" id="modalEditJadwal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="jadwal_dosen.php" class="modal-content">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="jadwal_id" id="edit_jadwal_id">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Perbarui Slot Jadwal</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Dosen Pembimbing <span class="text-danger">*</span></label>
                    <select name="dosen_id" id="edit_jadwal_dosen_id" class="form-select" required>
                        <?php foreach ($dosen_list as $d): ?>
                            <option value="<?= $d['id']; ?>"><?= htmlspecialchars($d['nama_dosen']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Hari Bimbingan <span class="text-danger">*</span></label>
                    <select name="hari" id="edit_jadwal_hari" class="form-select" required>
                        <option value="Senin">Senin</option>
                        <option value="Selasa">Selasa</option>
                        <option value="Rabu">Rabu</option>
                        <option value="Kamis">Kamis</option>
                        <option value="Jumat">Jumat</option>
                        <option value="Sabtu">Sabtu</option>
                    </select>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Jam Mulai <span class="text-danger">*</span></label>
                        <input type="time" class="form-control" name="jam_mulai" id="edit_jadwal_jam_mulai" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Jam Selesai <span class="text-danger">*</span></label>
                        <input type="time" class="form-control" name="jam_selesai" id="edit_jadwal_jam_selesai" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Status Slot Bimbingan <span class="text-danger">*</span></label>
                    <select name="status_aktif" id="edit_jadwal_status" class="form-select" required>
                        <option value="aktif">Aktif (Dapat dipilih mahasiswa)</option>
                        <option value="libur">Libur / Sibuk</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const editBtns = document.querySelectorAll('.btn-edit-jadwal');
    editBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('edit_jadwal_id').value         = this.dataset.id;
            document.getElementById('edit_jadwal_dosen_id').value   = this.dataset.dosenid;
            document.getElementById('edit_jadwal_hari').value       = this.dataset.hari;
            document.getElementById('edit_jadwal_jam_mulai').value   = this.dataset.jammulai;
            document.getElementById('edit_jadwal_jam_selesai').value = this.dataset.jamselesai;
            document.getElementById('edit_jadwal_status').value     = this.dataset.status;
        });
    });
});
</script>

<?php
require_once '../templates/footer.php';
?>
