<?php
// admin/monitoring_kelulusan.php - Monitoring & Evaluasi Kelulusan Mahasiswa Skripsi
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
// PROSES PEMBARUAN STATUS KELULUSAN & NILAI DARI MONITORING
// =========================================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_status') {
        $mahasiswa_id     = (int)($_POST['mahasiswa_id'] ?? 0);
        $status_kelulusan = $_POST['status_kelulusan'] ?? 'proses_bimbingan';
        $nilai_evaluasi   = isset($_POST['nilai_evaluasi']) && $_POST['nilai_evaluasi'] !== '' ? (int)$_POST['nilai_evaluasi'] : null;

        if ($mahasiswa_id <= 0) {
            $error_msg = 'ID Mahasiswa tidak valid.';
        } else {
            try {
                $pdo->beginTransaction();

                // Update status kelulusan
                $stmtSt = $pdo->prepare("UPDATE mahasiswa SET status_kelulusan = :st WHERE id = :mid");
                $stmtSt->execute([':st' => $status_kelulusan, ':mid' => $mahasiswa_id]);

                // Jika nilai evaluasi diinput
                if ($nilai_evaluasi !== null) {
                    $nilai_evaluasi = max(0, min(100, $nilai_evaluasi));
                    $stmtB = $pdo->prepare("SELECT id FROM pengajuan_bimbingan WHERE mahasiswa_id = :mid ORDER BY id DESC LIMIT 1");
                    $stmtB->execute([':mid' => $mahasiswa_id]);
                    $lastB = $stmtB->fetch();

                    if ($lastB) {
                        $stmtUpN = $pdo->prepare("UPDATE pengajuan_bimbingan SET nilai_progres = :np WHERE id = :bid");
                        $stmtUpN->execute([':np' => $nilai_evaluasi, ':bid' => $lastB['id']]);
                    }
                }

                $pdo->commit();
                $success_msg = "Status kelulusan akademik mahasiswa berhasil diperbarui!";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error_msg = "Gagal memperbarui status: " . $e->getMessage();
            }
        }
    }
}

// =========================================================================
// FILTER & QUERY DATA MONITORING
// =========================================================================
$filter_status = $_GET['status'] ?? 'all';
$filter_dosen  = (int)($_GET['dosen_id'] ?? 0);
$search_q      = trim($_GET['q'] ?? '');

$where = ["1=1"];
$params = [];

if ($filter_status !== 'all' && in_array($filter_status, ['proses_bimbingan', 'siap_sidang', 'lulus'])) {
    $where[] = "m.status_kelulusan = :st";
    $params[':st'] = $filter_status;
}

if ($filter_dosen > 0) {
    $where[] = "m.dosen_pembimbing_id = :did";
    $params[':did'] = $filter_dosen;
}

if (!empty($search_q)) {
    $where[] = "(m.nim LIKE :q OR m.nama_lengkap LIKE :q OR m.judul_skripsi LIKE :q)";
    $params[':q'] = "%{$search_q}%";
}

$where_sql = implode(" AND ", $where);

// Query Mahasiswa dengan Progres Bimbingan dan Logbook
$queryMhs = "
    SELECT 
        m.*,
        u.username,
        d.nama_dosen,
        COALESCE(
            (SELECT nilai_progres FROM pengajuan_bimbingan WHERE mahasiswa_id = m.id ORDER BY id DESC LIMIT 1),
            0
        ) AS nilai_progres_terakhir,
        (SELECT COUNT(*) FROM pengajuan_bimbingan WHERE mahasiswa_id = m.id) AS total_bimbingan,
        (SELECT COUNT(*) FROM logbook_bimbingan WHERE mahasiswa_id = m.id) AS total_acc_bab,
        (SELECT status_bab FROM logbook_bimbingan WHERE mahasiswa_id = m.id ORDER BY id DESC LIMIT 1) AS status_bab_terakhir,
        (SELECT tanggal_acc FROM logbook_bimbingan WHERE mahasiswa_id = m.id ORDER BY id DESC LIMIT 1) AS tgl_acc_terakhir
    FROM mahasiswa m
    INNER JOIN users u ON m.user_id = u.id
    LEFT JOIN dosen d ON m.dosen_pembimbing_id = d.id
    WHERE {$where_sql}
    ORDER BY 
        CASE m.status_kelulusan 
            WHEN 'siap_sidang' THEN 1 
            WHEN 'proses_bimbingan' THEN 2 
            WHEN 'lulus' THEN 3 
        END,
        m.id DESC
";
$stmt = $pdo->prepare($queryMhs);
$stmt->execute($params);
$monitoring_list = $stmt->fetchAll();

// Metrik Keseluruhan
$total_mhs       = $pdo->query("SELECT COUNT(*) FROM mahasiswa")->fetchColumn();
$count_bimbingan = $pdo->query("SELECT COUNT(*) FROM mahasiswa WHERE status_kelulusan = 'proses_bimbingan'")->fetchColumn();
$count_siap      = $pdo->query("SELECT COUNT(*) FROM mahasiswa WHERE status_kelulusan = 'siap_sidang'")->fetchColumn();
$count_lulus     = $pdo->query("SELECT COUNT(*) FROM mahasiswa WHERE status_kelulusan = 'lulus'")->fetchColumn();

$persen_lulus = $total_mhs > 0 ? round(($count_lulus / $total_mhs) * 100) : 0;
$persen_siap  = $total_mhs > 0 ? round(($count_siap / $total_mhs) * 100) : 0;
$persen_bimb  = $total_mhs > 0 ? round(($count_bimbingan / $total_mhs) * 100) : 0;

// Daftar Dosen untuk Filter
$dosen_filter = $pdo->query("SELECT id, nama_dosen FROM dosen ORDER BY nama_dosen ASC")->fetchAll();

$page_title   = "Monitoring Kelulusan Mahasiswa";
$current_page = "monitoring";

require_once '../templates/header.php';
require_once '../templates/sidebar_admin.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2 fw-bold text-dark mb-1">Monitoring & Evaluasi Kelulusan Mahasiswa</h1>
        <p class="text-muted small mb-0">Pantau kemajuan skripsi, capaian nilai progres, kesiapan sidang munaqasyah, hingga kelulusan final.</p>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <a href="data_mahasiswa.php" class="btn btn-outline-primary">
            <i class="bi bi-people me-1"></i> Data Mahasiswa
        </a>
        <button type="button" class="btn btn-success" onclick="window.print()">
            <i class="bi bi-printer-fill me-1"></i> Cetak Rekap Kelulusan
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

<!-- Pipeline Metrik Kelulusan -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card shadow-sm border-0 bg-secondary bg-opacity-10 border-start border-secondary border-4 p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="text-muted small fw-semibold text-uppercase">Tahap Bimbingan</span>
                    <h3 class="fw-bold text-dark mb-0 mt-1"><?= $count_bimbingan; ?> <small class="fs-6 fw-normal text-muted">(<?= $persen_bimb; ?>%)</small></h3>
                    <small class="text-muted">Masih aktif penulisan & revisi</small>
                </div>
                <div class="bg-secondary bg-opacity-25 rounded-circle p-3 text-secondary">
                    <i class="bi bi-book fs-3"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card shadow-sm border-0 bg-primary bg-opacity-10 border-start border-primary border-4 p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="text-primary small fw-semibold text-uppercase">Siap Sidang Munaqasyah</span>
                    <h3 class="fw-bold text-primary mb-0 mt-1"><?= $count_siap; ?> <small class="fs-6 fw-normal text-muted">(<?= $persen_siap; ?>%)</small></h3>
                    <small class="text-muted">ACC Bab lengkap dari dosen</small>
                </div>
                <div class="bg-primary bg-opacity-25 rounded-circle p-3 text-primary">
                    <i class="bi bi-award-fill fs-3"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card shadow-sm border-0 bg-success bg-opacity-10 border-start border-success border-4 p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="text-success small fw-semibold text-uppercase">Telah Dinyatakan Lulus</span>
                    <h3 class="fw-bold text-success mb-0 mt-1"><?= $count_lulus; ?> <small class="fs-6 fw-normal text-muted">(<?= $persen_lulus; ?>%)</small></h3>
                    <small class="text-muted">Tuntas yudisium & revisi final</small>
                </div>
                <div class="bg-success bg-opacity-25 rounded-circle p-3 text-success">
                    <i class="bi bi-check2-all fs-3"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Progress Bar Akumulasi Tingkat Kelulusan -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-3">
        <div class="d-flex justify-content-between small fw-semibold text-muted mb-2">
            <span>Tingkat Kemajuan Kelulusan Mahasiswa Angkatan</span>
            <span>Total: <?= $total_mhs; ?> Mahasiswa</span>
        </div>
        <div class="progress" style="height: 12px;">
            <div class="progress-bar bg-success" role="progressbar" style="width: <?= $persen_lulus; ?>%" title="Lulus: <?= $count_lulus; ?>"></div>
            <div class="progress-bar bg-primary" role="progressbar" style="width: <?= $persen_siap; ?>%" title="Siap Sidang: <?= $count_siap; ?>"></div>
            <div class="progress-bar bg-secondary" role="progressbar" style="width: <?= $persen_bimb; ?>%" title="Bimbingan: <?= $count_bimbingan; ?>"></div>
        </div>
        <div class="d-flex gap-4 mt-2 small text-muted">
            <div><span class="badge bg-success me-1">■</span> Lulus (<?= $count_lulus; ?>)</div>
            <div><span class="badge bg-primary me-1">■</span> Siap Sidang (<?= $count_siap; ?>)</div>
            <div><span class="badge bg-secondary me-1">■</span> Proses Bimbingan (<?= $count_bimbingan; ?>)</div>
        </div>
    </div>
</div>

<!-- Filter & Pencarian -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-3">
        <form method="GET" action="monitoring_kelulusan.php" class="row g-2 align-items-center">
            <div class="col-md-4">
                <input type="text" class="form-control" name="q" value="<?= htmlspecialchars($search_q); ?>" placeholder="Cari NIM, Nama, atau Judul...">
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select" onchange="this.form.submit()">
                    <option value="all" <?= ($filter_status === 'all') ? 'selected' : ''; ?>>-- Semua Status --</option>
                    <option value="siap_sidang" <?= ($filter_status === 'siap_sidang') ? 'selected' : ''; ?>>Siap Sidang</option>
                    <option value="proses_bimbingan" <?= ($filter_status === 'proses_bimbingan') ? 'selected' : ''; ?>>Proses Bimbingan</option>
                    <option value="lulus" <?= ($filter_status === 'lulus') ? 'selected' : ''; ?>>Lulus</option>
                </select>
            </div>
            <div class="col-md-3">
                <select name="dosen_id" class="form-select" onchange="this.form.submit()">
                    <option value="0">-- Semua Dosen Pembimbing --</option>
                    <?php foreach ($dosen_filter as $df): ?>
                        <option value="<?= $df['id']; ?>" <?= ($filter_dosen == $df['id']) ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($df['nama_dosen']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-primary w-100">Filter</button>
                <?php if ($filter_status !== 'all' || $filter_dosen > 0 || !empty($search_q)): ?>
                    <a href="monitoring_kelulusan.php" class="btn btn-outline-secondary" title="Reset Filter"><i class="bi bi-arrow-counterclockwise"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Tabel Monitoring Kelulusan -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0 text-dark">
            <i class="bi bi-clipboard2-data-fill text-primary me-2"></i>
            Daftar Monitoring Mahasiswa Tugas Akhir (<?= count($monitoring_list); ?> Mahasiswa)
        </h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3" style="width: 40px;">No</th>
                        <th style="width: 130px;">NIM</th>
                        <th>Mahasiswa & Judul Skripsi</th>
                        <th>Dosen Pembimbing</th>
                        <th class="text-center" style="width: 140px;">Nilai & Capaian</th>
                        <th class="text-center" style="width: 130px;">Status Kelulusan</th>
                        <th class="text-center" style="width: 120px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($monitoring_list)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">Tidak ada data mahasiswa sesuai filter.</td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $no = 1;
                        foreach ($monitoring_list as $row): 
                            $nilai = (int)$row['nilai_progres_terakhir'];
                        ?>
                            <tr>
                                <td class="ps-3 text-muted"><?= $no++; ?></td>
                                <td class="font-monospace fw-bold text-primary"><?= htmlspecialchars($row['nim']); ?></td>
                                <td>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($row['nama_lengkap']); ?></div>
                                    <small class="text-muted d-block text-truncate" style="max-width: 320px;" title="<?= htmlspecialchars($row['judul_skripsi']); ?>">
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
                                        <div class="progress" style="height: 5px; width: 85px;">
                                            <div class="progress-bar <?= ($nilai >= 80) ? 'bg-success' : (($nilai >= 60) ? 'bg-primary' : 'bg-warning'); ?>" 
                                                 style="width: <?= $nilai; ?>%"></div>
                                        </div>
                                        <small class="text-muted d-block mt-1" style="font-size: 0.75rem;">
                                            <?= $row['total_acc_bab']; ?> Bab ACC / <?= $row['total_bimbingan']; ?> Bimb
                                        </small>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <?php if ($row['status_kelulusan'] === 'lulus'): ?>
                                        <span class="badge bg-success px-2 py-1"><i class="bi bi-check2-circle me-1"></i>Lulus</span>
                                    <?php elseif ($row['status_kelulusan'] === 'siap_sidang'): ?>
                                        <span class="badge bg-primary px-2 py-1"><i class="bi bi-award me-1"></i>Siap Sidang</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary px-2 py-1"><i class="bi bi-clock-history me-1"></i>Bimbingan</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-outline-success btn-evaluasi" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#modalEvaluasiKelulusan"
                                            data-id="<?= $row['id']; ?>"
                                            data-nama="<?= htmlspecialchars($row['nama_lengkap']); ?>"
                                            data-nim="<?= htmlspecialchars($row['nim']); ?>"
                                            data-status="<?= $row['status_kelulusan']; ?>"
                                            data-nilai="<?= $nilai; ?>"
                                            title="Evaluasi & Update Status Kelulusan">
                                        <i class="bi bi-pencil-square me-1"></i>Evaluasi
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

<!-- ========================================================================= -->
<!-- MODAL EVALUASI & UPDATE KELULUSAN -->
<!-- ========================================================================= -->
<div class="modal fade" id="modalEvaluasiKelulusan" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="monitoring_kelulusan.php" class="modal-content">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="mahasiswa_id" id="eval_mhs_id">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-award me-2"></i>Evaluasi Status Kelulusan Mahasiswa</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="p-3 bg-light rounded border mb-3">
                    <div class="small text-muted">Mahasiswa Terpilih:</div>
                    <div class="fw-bold text-dark fs-6" id="eval_nama"></div>
                    <div class="small text-muted font-monospace" id="eval_nim"></div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Status Kelulusan Akademik <span class="text-danger">*</span></label>
                    <select name="status_kelulusan" id="eval_status" class="form-select" required>
                        <option value="proses_bimbingan">Proses Bimbingan (Masih dalam tahap bimbingan/revisi)</option>
                        <option value="siap_sidang">Siap Sidang (Telah disetujui dosen untuk sidang munaqasyah)</option>
                        <option value="lulus">Lulus (Telah lulus ujian sidang & menyelesaikan revisi akhir)</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Nilai Progres / Evaluasi (0 - 100)</label>
                    <div class="input-group">
                        <input type="number" class="form-control" name="nilai_evaluasi" id="eval_nilai" min="0" max="100">
                        <span class="input-group-text">%</span>
                    </div>
                    <div class="form-text">Nilai persentase kemajuan bimbingan skripsi.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Simpan Pembaruan</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const evalBtns = document.querySelectorAll('.btn-evaluasi');
    evalBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('eval_mhs_id').value     = this.dataset.id;
            document.getElementById('eval_nama').textContent = this.dataset.nama;
            document.getElementById('eval_nim').textContent  = "NIM: " + this.dataset.nim;
            document.getElementById('eval_status').value     = this.dataset.status;
            document.getElementById('eval_nilai').value      = this.dataset.nilai;
        });
    });
});
</script>

<?php
require_once '../templates/footer.php';
?>

