<?php
// dosen/jadwal_saya.php - Manajemen Ketersediaan Waktu Bimbingan Dosen (Salsa)
// Fitur: Mengatur jadwal bimbingan mingguan (hari, jam mulai, jam selesai, status aktif/libur) agar mahasiswa dapat melihat slot waktu yang tersedia saat mengajukan bimbingan.
$page_title   = "Atur Ketersediaan Waktu";
$current_page = "jadwal";

require_once '../config/database.php';
require_once '../templates/header.php';
require_once '../templates/sidebar_dosen.php';

// Proteksi akses role dosen
if ($_SESSION['role'] !== 'dosen') {
    header("Location: ../login.php");
    exit;
}

// Ambil data dosen berdasarkan user_id aktif
$stmt_dosen = $pdo->prepare("SELECT * FROM dosen WHERE user_id = :uid LIMIT 1");
$stmt_dosen->execute([':uid' => $_SESSION['user_id']]);
$dosen = $stmt_dosen->fetch();
$dosen_id = $dosen['id'] ?? 0;

$success_msg = '';
$error_msg   = '';

// 1. PROSES HAPUS SLOT JADWAL
if (isset($_GET['aksi']) && $_GET['aksi'] === 'hapus' && !empty($_GET['id'])) {
    $id_hapus = (int)$_GET['id'];
    try {
        $stmt_del = $pdo->prepare("DELETE FROM jadwal_dosen WHERE id = :id AND dosen_id = :did");
        $stmt_del->execute([':id' => $id_hapus, ':did' => $dosen_id]);
        if ($stmt_del->rowCount() > 0) {
            $success_msg = "Slot ketersediaan berhasil dihapus dari sistem.";
        } else {
            $error_msg = "Gagal menghapus slot atau data tidak ditemukan.";
        }
    } catch (PDOException $e) {
        $error_msg = "Terjadi kesalahan saat menghapus: " . $e->getMessage();
    }
}

// 2. PROSES TOGGLE STATUS AKTIF / LIBUR
if (isset($_GET['aksi']) && $_GET['aksi'] === 'toggle' && !empty($_GET['id'])) {
    $id_toggle = (int)$_GET['id'];
    try {
        $stmt_cur = $pdo->prepare("SELECT status_aktif, hari FROM jadwal_dosen WHERE id = :id AND dosen_id = :did");
        $stmt_cur->execute([':id' => $id_toggle, ':did' => $dosen_id]);
        $curr = $stmt_cur->fetch();
        if ($curr) {
            $new_status = ($curr['status_aktif'] === 'aktif') ? 'libur' : 'aktif';
            $stmt_up = $pdo->prepare("UPDATE jadwal_dosen SET status_aktif = :st WHERE id = :id AND dosen_id = :did");
            $stmt_up->execute([':st' => $new_status, ':id' => $id_toggle, ':did' => $dosen_id]);
            $success_msg = "Status slot hari " . htmlspecialchars($curr['hari']) . " diubah menjadi: <strong>" . strtoupper($new_status) . "</strong>.";
        }
    } catch (PDOException $e) {
        $error_msg = "Terjadi kesalahan saat mengubah status: " . $e->getMessage();
    }
}

// 3. PROSES TAMBAH SLOT JADWAL BARU
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tambah_jadwal'])) {
    $hari         = trim($_POST['hari'] ?? '');
    $jam_mulai    = trim($_POST['jam_mulai'] ?? '');
    $jam_selesai  = trim($_POST['jam_selesai'] ?? '');
    $status_aktif = trim($_POST['status_aktif'] ?? 'aktif');

    $daftar_hari = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

    if (!in_array($hari, $daftar_hari)) {
        $error_msg = "Pilihan hari tidak valid.";
    } elseif (empty($jam_mulai) || empty($jam_selesai)) {
        $error_msg = "Jam mulai dan jam selesai wajib ditentukan.";
    } elseif ($jam_mulai >= $jam_selesai) {
        $error_msg = "Jam mulai harus lebih awal dari jam selesai.";
    } else {
        try {
            // Cek apakah slot hari dan jam bentrok dengan jadwal yang sudah ada
            $stmt_check = $pdo->prepare("
                SELECT COUNT(*) FROM jadwal_dosen 
                WHERE dosen_id = :did AND hari = :hari 
                  AND (jam_mulai < :js AND jam_selesai > :jm)
            ");
            $stmt_check->execute([
                ':did'  => $dosen_id,
                ':hari' => $hari,
                ':js'   => $jam_selesai,
                ':jm'   => $jam_mulai
            ]);
            
            if ($stmt_check->fetchColumn() > 0) {
                $error_msg = "Slot waktu pada hari {$hari} tersebut bertabrakan (bentrok) dengan jadwal yang sudah ada.";
            } else {
                $stmt_insert = $pdo->prepare("
                    INSERT INTO jadwal_dosen (dosen_id, hari, jam_mulai, jam_selesai, status_aktif) 
                    VALUES (:did, :hari, :jam_mulai, :jam_selesai, :status_aktif)
                ");
                $stmt_insert->execute([
                    ':did'          => $dosen_id,
                    ':hari'         => $hari,
                    ':jam_mulai'    => $jam_mulai,
                    ':jam_selesai'  => $jam_selesai,
                    ':status_aktif' => $status_aktif
                ]);
                $success_msg = "Slot ketersediaan baru hari <strong>{$hari} ({$jam_mulai} - {$jam_selesai} WIB)</strong> berhasil ditambahkan dan dapat dilihat oleh mahasiswa!";
            }
        } catch (PDOException $e) {
            $error_msg = "Gagal menyimpan jadwal: " . $e->getMessage();
        }
    }
}

// Ambil Semua Slot Jadwal Milik Dosen Ini
$stmt_list = $pdo->prepare("
    SELECT * FROM jadwal_dosen 
    WHERE dosen_id = :did 
    ORDER BY FIELD(hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'), jam_mulai ASC
");
$stmt_list->execute([':did' => $dosen_id]);
$jadwal_list = $stmt_list->fetchAll();
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
    <div>
        <h1 class="h3 fw-bold mb-1">Atur Ketersediaan Waktu Bimbingan</h1>
        <p class="text-muted small mb-0">Tentukan hari dan jam operasional bimbingan Anda. Slot aktif akan otomatis tampil pada pilihan mahasiswa saat mengajukan jadwal.</p>
    </div>
</div>

<!-- Alert Notifikasi -->
<?php if (!empty($success_msg)): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i><?= $success_msg; ?>
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
    <!-- Formulir Tambah Slot Ketersediaan -->
    <div class="col-lg-4">
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white py-3 border-bottom">
                <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-calendar-plus-fill me-2 text-primary"></i>Tambah Slot Waktu</h6>
            </div>
            <div class="card-body p-4">
                <form action="jadwal_saya.php" method="POST">
                    <input type="hidden" name="tambah_jadwal" value="1">
                    
                    <div class="mb-3">
                        <label for="hari" class="form-label fw-semibold">Pilih Hari Bimbingan <span class="text-danger">*</span></label>
                        <select name="hari" id="hari" class="form-select" required>
                            <option value="">-- Pilih Hari --</option>
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
                            <label for="jam_mulai" class="form-label fw-semibold">Jam Mulai <span class="text-danger">*</span></label>
                            <input type="time" name="jam_mulai" id="jam_mulai" class="form-control" required>
                        </div>
                        <div class="col-6">
                            <label for="jam_selesai" class="form-label fw-semibold">Jam Selesai <span class="text-danger">*</span></label>
                            <input type="time" name="jam_selesai" id="jam_selesai" class="form-control" required>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="status_aktif" class="form-label fw-semibold">Status Ketersediaan</label>
                        <select name="status_aktif" id="status_aktif" class="form-select">
                            <option value="aktif">Aktif (Dapat dipilih Mahasiswa)</option>
                            <option value="libur">Libur (Sementara Tidak Tersedia)</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
                        <i class="bi bi-plus-circle me-1"></i> Terbitkan Slot Jadwal
                    </button>
                </form>
            </div>
        </div>

        <!-- Kartu Edukasi Alur Mahasiswa -->
        <div class="card shadow-sm border-0 bg-primary-subtle text-primary-emphasis p-3">
            <h6 class="fw-bold mb-2"><i class="bi bi-lightbulb-fill me-1 text-warning"></i> Info Integrasi Mahasiswa</h6>
            <p class="small mb-0">Saat mahasiswa bimbingan Anda membuka menu <strong>Ajukan Jadwal</strong>, mereka hanya dapat memilih slot operasional yang bertanda status <strong>Aktif</strong>.</p>
        </div>
    </div>

    <!-- Tabel Daftar Slot Ketersediaan -->
    <div class="col-lg-8">
        <div class="table-card-wrapper">
            <div class="p-3 border-bottom bg-white d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-calendar-check-fill me-2 text-primary"></i>Daftar Slot Ketersediaan Bimbingan</h6>
                    <small class="text-muted">Total slot operasional ketersediaan Anda</small>
                </div>
                <span class="badge bg-primary rounded-pill"><?= count($jadwal_list); ?> Slot</span>
            </div>
            <div class="table-responsive">
                <table class="table table-modern table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width: 50px;" class="text-center">No</th>
                            <th>Hari</th>
                            <th>Rentang Waktu Operasional</th>
                            <th>Status Akses</th>
                            <th style="width: 180px;" class="text-center">Aksi / Tindakan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($jadwal_list)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-5 text-muted">
                                    <i class="bi bi-calendar-x fs-1 d-block mb-2 text-muted"></i>
                                    Belum ada jadwal ketersediaan bimbingan yang diatur. Silakan tambahkan melalui form di samping.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($jadwal_list as $index => $row): ?>
                                <tr>
                                    <td class="text-center fw-semibold text-muted"><?= $index + 1; ?></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="avatar-circle avatar-sm avatar-primary">
                                                <i class="bi bi-calendar-day"></i>
                                            </div>
                                            <span class="fw-bold text-dark"><?= htmlspecialchars($row['hari']); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-primary">
                                            <i class="bi bi-clock me-1"></i>
                                            <?= htmlspecialchars(substr($row['jam_mulai'], 0, 5)); ?> - <?= htmlspecialchars(substr($row['jam_selesai'], 0, 5)); ?> WIB
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($row['status_aktif'] === 'aktif'): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1">
                                                <span class="status-dot active"></span> Aktif
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-3 py-1">
                                                <span class="status-dot rejected"></span> Libur
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="jadwal_saya.php?aksi=toggle&id=<?= $row['id']; ?>" 
                                               class="btn btn-outline-secondary" 
                                               title="Ubah Status Aktif / Libur">
                                                <i class="bi bi-arrow-repeat me-1"></i><?= ($row['status_aktif'] === 'aktif') ? 'Liburkan' : 'Aktifkan'; ?>
                                            </a>
                                            <a href="jadwal_saya.php?aksi=hapus&id=<?= $row['id']; ?>" 
                                               class="btn btn-outline-danger" 
                                               onclick="return confirm('Apakah Anda yakin ingin menghapus slot hari <?= htmlspecialchars($row['hari']); ?> ini?');"
                                               title="Hapus Slot">
                                                <i class="bi bi-trash"></i>
                                            </a>
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
</div>

<?php
require_once '../templates/footer.php';
?>
