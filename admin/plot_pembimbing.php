<?php
// admin/plot_pembimbing.php - Plotting & Pemetaan Dosen Pembimbing Mahasiswa
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
// PROSES PENETAPAN / PEMBATALAN PLOTTING PEMBIMBING (POST)
// =========================================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    // Tetapkan atau Ganti Dosen Pembimbing
    if ($action === 'assign' || $action === 'reassign') {
        $mahasiswa_id = (int)($_POST['mahasiswa_id'] ?? 0);
        $dosen_id     = !empty($_POST['dosen_id']) ? (int)$_POST['dosen_id'] : null;

        if ($mahasiswa_id <= 0) {
            $error_msg = 'Mahasiswa yang dipilih tidak valid.';
        } elseif (empty($dosen_id)) {
            $error_msg = 'Silakan pilih salah satu dosen pembimbing.';
        } else {
            try {
                // Cek kuota dosen
                $stmtD = $pdo->prepare("
                    SELECT d.nama_dosen, d.kuota_bimbingan, COUNT(m.id) as terisi 
                    FROM dosen d 
                    LEFT JOIN mahasiswa m ON d.id = m.dosen_pembimbing_id 
                    WHERE d.id = :did 
                    GROUP BY d.id
                ");
                $stmtD->execute([':did' => $dosen_id]);
                $dosenInfo = $stmtD->fetch();

                if (!$dosenInfo) {
                    throw new Exception('Data dosen tidak ditemukan.');
                }

                if ($dosenInfo['terisi'] >= $dosenInfo['kuota_bimbingan']) {
                    throw new Exception("Kuota bimbingan untuk {$dosenInfo['nama_dosen']} sudah penuh ({$dosenInfo['kuota_bimbingan']} mahasiswa).");
                }

                // Update relasi mahasiswa ke dosen
                $stmtUp = $pdo->prepare("UPDATE mahasiswa SET dosen_pembimbing_id = :did WHERE id = :mid");
                $stmtUp->execute([':did' => $dosen_id, ':mid' => $mahasiswa_id]);

                $label = ($action === 'reassign') ? 'diperbarui / dialihkan' : 'ditetapkan';
                $success_msg = "Dosen pembimbing ({$dosenInfo['nama_dosen']}) berhasil {$label} untuk mahasiswa.";
            } catch (Exception $e) {
                $error_msg = "Gagal memproses pembimbing: " . $e->getMessage();
            }
        }
    }

    // Lepaskan / Reset Plotting Pembimbing
    elseif ($action === 'unassign') {
        $mahasiswa_id = (int)($_POST['mahasiswa_id'] ?? 0);
        if ($mahasiswa_id > 0) {
            try {
                $stmtReset = $pdo->prepare("UPDATE mahasiswa SET dosen_pembimbing_id = NULL WHERE id = :mid");
                $stmtReset->execute([':mid' => $mahasiswa_id]);
                $success_msg = "Plotting dosen pembimbing berhasil dilepas.";
            } catch (Exception $e) {
                $error_msg = "Gagal membatalkan plotting: " . $e->getMessage();
            }
        }
    }
}

// =========================================================================
// AMBIL DAFTAR DOSEN DENGAN INFO KUOTA
// =========================================================================
$queryDosen = "
    SELECT 
        d.id, 
        d.nama_dosen, 
        d.nidn,
        d.kuota_bimbingan, 
        COUNT(m.id) AS total_bimbingan,
        (d.kuota_bimbingan - COUNT(m.id)) AS sisa_kuota
    FROM dosen d
    LEFT JOIN mahasiswa m ON d.id = m.dosen_pembimbing_id
    GROUP BY d.id
    ORDER BY d.nama_dosen ASC
";
$dosen_list = $pdo->query($queryDosen)->fetchAll();

// =========================================================================
// AMBIL MAHASISWA YANG BELUM MEMILIKI PEMBIMBING (BELUM DIPLOT)
// =========================================================================
$queryBelum = "
    SELECT m.*, u.username 
    FROM mahasiswa m
    INNER JOIN users u ON m.user_id = u.id
    WHERE m.dosen_pembimbing_id IS NULL
    ORDER BY m.id DESC
";
$mhs_belum_plot = $pdo->query($queryBelum)->fetchAll();

// =========================================================================
// AMBIL MAHASISWA YANG SUDAH MEMILIKI PEMBIMBING (SUDAH DIPLOT)
// =========================================================================
$querySudah = "
    SELECT m.*, u.username, d.nama_dosen, d.id as id_dosen_aktif 
    FROM mahasiswa m
    INNER JOIN users u ON m.user_id = u.id
    INNER JOIN dosen d ON m.dosen_pembimbing_id = d.id
    ORDER BY m.nama_lengkap ASC
";
$mhs_sudah_plot = $pdo->query($querySudah)->fetchAll();

$page_title   = "Plotting Dosen Pembimbing";
$current_page = "plotting";

require_once '../templates/header.php';
require_once '../templates/sidebar_admin.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2 fw-bold text-dark mb-1">Mekanisme Plotting & Distribusi Pembimbing</h1>
        <p class="text-muted small mb-0">Petakan, tetapkan, atau alihkan dosen pembimbing tugas akhir bagi mahasiswa secara terkoordinasi.</p>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <a href="laporan_plotting.php" class="btn btn-outline-success">
            <i class="bi bi-printer me-1"></i> Rekap & Cetak Laporan
        </a>
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

<!-- Ringkasan Beban & Kuota Dosen -->
<div class="row g-3 mb-4">
    <?php foreach ($dosen_list as $dl): 
        $persen = $dl['kuota_bimbingan'] > 0 ? min(100, round(($dl['total_bimbingan'] / $dl['kuota_bimbingan']) * 100)) : 0;
        $is_full = ($dl['sisa_kuota'] <= 0);
    ?>
        <div class="col-md-6 col-xl-4">
            <div class="card h-100 shadow-sm border-0 <?= $is_full ? 'border-danger' : ''; ?>">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <h6 class="fw-bold text-dark mb-0"><?= htmlspecialchars($dl['nama_dosen']); ?></h6>
                            <small class="text-muted font-monospace">NIDN: <?= htmlspecialchars($dl['nidn']); ?></small>
                        </div>
                        <span class="badge <?= $is_full ? 'bg-danger' : 'bg-success'; ?>">
                            <?= $is_full ? 'Penuh' : "Sisa {$dl['sisa_kuota']}"; ?>
                        </span>
                    </div>
                    <div class="d-flex justify-content-between small text-muted mb-1">
                        <span>Bimbingan: <strong><?= $dl['total_bimbingan']; ?></strong> / <?= $dl['kuota_bimbingan']; ?></span>
                        <span><?= $persen; ?>%</span>
                    </div>
                    <div class="progress" style="height: 6px;">
                        <div class="progress-bar <?= $is_full ? 'bg-danger' : (($persen >= 75) ? 'bg-warning' : 'bg-primary'); ?>" 
                             style="width: <?= $persen; ?>%"></div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Bagian 1: Mahasiswa yang Belum Diplot -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-warning bg-opacity-25 py-3 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0 text-dark">
            <i class="bi bi-exclamation-circle-fill text-warning me-2"></i>
            Mahasiswa Menunggu Penetapan Pembimbing (<?= count($mhs_belum_plot); ?> Mahasiswa)
        </h6>
        <span class="badge bg-warning text-dark">Prioritas Penetapan</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3" style="width: 50px;">No</th>
                        <th>NIM & Nama Mahasiswa</th>
                        <th>Judul Skripsi</th>
                        <th style="min-width: 320px;">Pilih Dosen Pembimbing</th>
                        <th class="text-center" style="width: 130px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($mhs_belum_plot)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-success py-4">
                                <i class="bi bi-check-circle-fill fs-4 d-block mb-1"></i>
                                Semua mahasiswa yang terdaftar sudah memiliki dosen pembimbing!
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $no = 1;
                        foreach ($mhs_belum_plot as $m): 
                        ?>
                            <tr>
                                <td class="ps-3 text-muted"><?= $no++; ?></td>
                                <td>
                                    <div class="font-monospace fw-bold text-primary"><?= htmlspecialchars($m['nim']); ?></div>
                                    <div class="fw-semibold text-dark"><?= htmlspecialchars($m['nama_lengkap']); ?></div>
                                </td>
                                <td>
                                    <span class="text-muted small d-block" style="max-width: 350px;">
                                        <?= htmlspecialchars($m['judul_skripsi']); ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="POST" action="plot_pembimbing.php" id="form-plot-<?= $m['id']; ?>" class="d-flex align-items-center gap-2">
                                        <input type="hidden" name="action" value="assign">
                                        <input type="hidden" name="mahasiswa_id" value="<?= $m['id']; ?>">
                                        <select name="dosen_id" class="form-select form-select-sm" required>
                                            <option value="">-- Pilih Dosen Pembimbing --</option>
                                            <?php foreach ($dosen_list as $d): ?>
                                                <?php $disabled = ($d['sisa_kuota'] <= 0) ? 'disabled' : ''; ?>
                                                <option value="<?= $d['id']; ?>" <?= $disabled; ?>>
                                                    <?= htmlspecialchars($d['nama_dosen']); ?> 
                                                    (Terisi: <?= $d['total_bimbingan']; ?>/<?= $d['kuota_bimbingan']; ?> <?= ($d['sisa_kuota'] <= 0) ? '• Penuh' : "• Sisa {$d['sisa_kuota']}"; ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </td>
                                <td class="text-center">
                                    <button type="submit" form="form-plot-<?= $m['id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="bi bi-check-lg me-1"></i>Tetapkan
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Bagian 2: Mahasiswa yang Sudah Memiliki Pembimbing -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0 text-dark">
            <i class="bi bi-person-check-fill text-success me-2"></i>
            Daftar Mahasiswa yang Telah Diplot (<?= count($mhs_sudah_plot); ?> Mahasiswa)
        </h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3" style="width: 50px;">No</th>
                        <th>NIM & Nama Mahasiswa</th>
                        <th>Judul Skripsi</th>
                        <th>Dosen Pembimbing Aktif</th>
                        <th>Status Kelulusan</th>
                        <th class="text-center" style="width: 170px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($mhs_sudah_plot)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">Belum ada mahasiswa yang diplot ke dosen.</td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $no = 1;
                        foreach ($mhs_sudah_plot as $m): 
                        ?>
                            <tr>
                                <td class="ps-3 text-muted"><?= $no++; ?></td>
                                <td>
                                    <div class="font-monospace fw-bold text-primary"><?= htmlspecialchars($m['nim']); ?></div>
                                    <div class="fw-semibold text-dark"><?= htmlspecialchars($m['nama_lengkap']); ?></div>
                                </td>
                                <td>
                                    <small class="text-muted d-block text-truncate" style="max-width: 280px;">
                                        <?= htmlspecialchars($m['judul_skripsi']); ?>
                                    </small>
                                </td>
                                <td>
                                    <span class="fw-semibold text-success"><i class="bi bi-check-circle-fill me-1"></i><?= htmlspecialchars($m['nama_dosen']); ?></span>
                                </td>
                                <td>
                                    <?php if ($m['status_kelulusan'] === 'lulus'): ?>
                                        <span class="badge bg-success">Lulus</span>
                                    <?php elseif ($m['status_kelulusan'] === 'siap_sidang'): ?>
                                        <span class="badge bg-primary">Siap Sidang</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Proses Bimbingan</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm">
                                        <!-- Tombol Pindah Pembimbing -->
                                        <button type="button" class="btn btn-outline-primary btn-pindah" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#modalPindahPembimbing"
                                                data-mid="<?= $m['id']; ?>"
                                                data-nama="<?= htmlspecialchars($m['nama_lengkap']); ?>"
                                                data-nim="<?= htmlspecialchars($m['nim']); ?>"
                                                data-dosenaktif="<?= $m['id_dosen_aktif']; ?>"
                                                title="Pindah / Ganti Pembimbing">
                                            <i class="bi bi-arrow-left-right me-1"></i>Pindah
                                        </button>

                                        <!-- Tombol Lepas Pembimbing -->
                                        <form method="POST" action="plot_pembimbing.php" class="d-inline" onsubmit="return confirm('Apakah Anda yakin ingin melepas plotting pembimbing untuk mahasiswa ini?');">
                                            <input type="hidden" name="action" value="unassign">
                                            <input type="hidden" name="mahasiswa_id" value="<?= $m['id']; ?>">
                                            <button type="submit" class="btn btn-outline-danger" title="Lepaskan Dosen Pembimbing">
                                                <i class="bi bi-x-circle"></i>
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
<!-- MODAL PINDAH / GANTI PEMBIMBING -->
<!-- ========================================================================= -->
<div class="modal fade" id="modalPindahPembimbing" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="plot_pembimbing.php" class="modal-content">
            <input type="hidden" name="action" value="reassign">
            <input type="hidden" name="mahasiswa_id" id="pindah_mid">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-arrow-left-right text-primary me-2"></i>Alihkan Dosen Pembimbing</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="p-3 bg-light rounded border mb-3">
                    <div class="small text-muted">Mahasiswa:</div>
                    <div class="fw-bold text-dark fs-6" id="pindah_nama"></div>
                    <div class="small text-muted font-monospace" id="pindah_nim"></div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Pilih Dosen Pembimbing Baru <span class="text-danger">*</span></label>
                    <select name="dosen_id" id="pindah_dosen_select" class="form-select" required>
                        <option value="">-- Pilih Dosen Tujuan --</option>
                        <?php foreach ($dosen_list as $d): ?>
                            <?php $disabled = ($d['sisa_kuota'] <= 0) ? 'disabled' : ''; ?>
                            <option value="<?= $d['id']; ?>" <?= $disabled; ?>>
                                <?= htmlspecialchars($d['nama_dosen']); ?> 
                                (Terisi: <?= $d['total_bimbingan']; ?>/<?= $d['kuota_bimbingan']; ?> <?= ($d['sisa_kuota'] <= 0) ? '• Penuh' : "• Sisa {$d['sisa_kuota']}"; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Dosen yang kuotanya telah penuh dinonaktifkan secara otomatis.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>Simpan Pengalihan</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const pindahBtns = document.querySelectorAll('.btn-pindah');
    pindahBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('pindah_mid').value         = this.dataset.mid;
            document.getElementById('pindah_nama').textContent  = this.dataset.nama;
            document.getElementById('pindah_nim').textContent   = "NIM: " + this.dataset.nim;
        });
    });
});
</script>

<?php
require_once '../templates/footer.php';
?>
