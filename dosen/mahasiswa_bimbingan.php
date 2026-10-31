<?php
// dosen/mahasiswa_bimbingan.php - Monitoring Mahasiswa Bimbingan & Progres Skripsi (Salsa)
$page_title   = "Mahasiswa Bimbingan & Progres";
$current_page = "mahasiswa_bimbingan";

require_once '../config/database.php';
require_once '../templates/header.php';
require_once '../templates/sidebar_dosen.php';

// Proteksi akses role dosen
if ($_SESSION['role'] !== 'dosen') {
    header("Location: ../login.php");
    exit;
}

// Ambil data dosen berdasarkan user_id aktif
$user_id = $_SESSION['user_id'];
$stmt_dosen = $pdo->prepare("SELECT * FROM dosen WHERE user_id = :uid LIMIT 1");
$stmt_dosen->execute([':uid' => $user_id]);
$dosen = $stmt_dosen->fetch();
$dosen_id = $dosen['id'] ?? 0;

// Filter & Pencarian
$search = trim($_GET['search'] ?? '');
$filter_status = trim($_GET['status'] ?? 'semua');

// Susun Query Mahasiswa Bimbingan
$sql = "
    SELECT m.*, u.id AS mhs_user_id,
           (SELECT COALESCE(MAX(nilai_progres), 0) FROM pengajuan_bimbingan WHERE mahasiswa_id = m.id AND dosen_id = :did1) AS progres_tertinggi,
           (SELECT COUNT(*) FROM pengajuan_bimbingan WHERE mahasiswa_id = m.id AND dosen_id = :did2) AS total_sesi,
           (SELECT bab_pembahasan FROM pengajuan_bimbingan WHERE mahasiswa_id = m.id AND dosen_id = :did3 ORDER BY tanggal_bimbingan DESC, id DESC LIMIT 1) AS bab_terakhir,
           (SELECT tanggal_bimbingan FROM pengajuan_bimbingan WHERE mahasiswa_id = m.id AND dosen_id = :did4 ORDER BY tanggal_bimbingan DESC, id DESC LIMIT 1) AS tgl_terakhir,
           (SELECT status_bab FROM logbook_bimbingan WHERE mahasiswa_id = m.id ORDER BY id DESC LIMIT 1) AS status_acc_terakhir
    FROM mahasiswa m
    JOIN users u ON m.user_id = u.id
    WHERE m.dosen_pembimbing_id = :did_main
";

$params = [
    ':did1'     => $dosen_id,
    ':did2'     => $dosen_id,
    ':did3'     => $dosen_id,
    ':did4'     => $dosen_id,
    ':did_main' => $dosen_id
];

if (!empty($search)) {
    $sql .= " AND (m.nama_lengkap LIKE :keyword1 OR m.nim LIKE :keyword2 OR m.judul_skripsi LIKE :keyword3)";
    $params[':keyword1'] = "%{$search}%";
    $params[':keyword2'] = "%{$search}%";
    $params[':keyword3'] = "%{$search}%";
}

if ($filter_status !== 'semua' && in_array($filter_status, ['proses_bimbingan', 'siap_sidang', 'lulus'])) {
    $sql .= " AND m.status_kelulusan = :st_kelulusan";
    $params[':st_kelulusan'] = $filter_status;
}

$sql .= " ORDER BY m.nama_lengkap ASC";

$stmt_mhs = $pdo->prepare($sql);
$stmt_mhs->execute($params);
$daftar_mahasiswa = $stmt_mhs->fetchAll();

// Hitung Statistik
$total_mhs = count($daftar_mahasiswa);
$count_siap_sidang = 0;
$count_proses = 0;
$sum_progres = 0;

foreach ($daftar_mahasiswa as $row) {
    if ($row['status_kelulusan'] === 'siap_sidang' || $row['status_kelulusan'] === 'lulus') {
        $count_siap_sidang++;
    } else {
        $count_proses++;
    }
    $sum_progres += (int)$row['progres_tertinggi'];
}
$avg_progres = ($total_mhs > 0) ? round($sum_progres / $total_mhs, 1) : 0;
?>

<!-- Header Halaman -->
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-2 pb-2 mb-4 border-bottom">
    <div>
        <h1 class="h2 fw-bold text-dark mb-1">Daftar Mahasiswa Bimbingan & Progres</h1>
        <p class="text-muted small mb-0">Pantau capaian penulisan skripsi, progres persentase, dan riwayat bimbingan seluruh mahasiswa asuhan Anda.</p>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <a href="cetak_laporan.php" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-printer me-1"></i> Cetak Semua Rekap
        </a>
        <a href="chat.php" class="btn btn-sm btn-primary">
            <i class="bi bi-chat-dots me-1"></i> Buka Chat Konsultasi
        </a>
    </div>
</div>

<!-- 4 Kartu Metrik Ringkasan Progres -->
<div class="row g-3 mb-4">
    <!-- Total Mahasiswa -->
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 shadow-sm border-0 border-start border-primary border-4 h-100 bg-white">
            <h6 class="text-muted small text-uppercase fw-semibold mb-1">Mahasiswa Bimbingan</h6>
            <h3 class="fw-bold text-dark mb-1"><?= $total_mhs; ?> <span class="fs-6 text-muted fw-normal">/ <?= htmlspecialchars($dosen['kuota_bimbingan'] ?? '10'); ?> Kuota</span></h3>
            <p class="text-muted small mb-0">Terisi <?= round(($total_mhs / max(1, (int)($dosen['kuota_bimbingan'] ?? 10))) * 100); ?>% dari total kapasitas</p>
        </div>
    </div>

    <!-- Dalam Proses Bimbingan -->
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 shadow-sm border-0 border-start border-warning border-4 h-100 bg-white">
            <h6 class="text-muted small text-uppercase fw-semibold mb-1">Proses Bimbingan</h6>
            <h3 class="fw-bold text-warning mb-1"><?= $count_proses; ?> <span class="fs-6 text-muted fw-normal">Mahasiswa</span></h3>
            <p class="text-muted small mb-0">Sedang tahap konsultasi naskah</p>
        </div>
    </div>

    <!-- Siap Sidang / Lulus -->
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 shadow-sm border-0 border-start border-success border-4 h-100 bg-white">
            <h6 class="text-muted small text-uppercase fw-semibold mb-1">Siap Sidang Skripsi</h6>
            <h3 class="fw-bold text-success mb-1"><?= $count_siap_sidang; ?> <span class="fs-6 text-muted fw-normal">Mahasiswa</span></h3>
            <p class="text-muted small mb-0">Naskah tuntas & ter-ACC</p>
        </div>
    </div>

    <!-- Rata-rata Progres -->
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 shadow-sm border-0 border-start border-info border-4 h-100 bg-white">
            <h6 class="text-muted small text-uppercase fw-semibold mb-1">Rata-rata Capaian</h6>
            <h3 class="fw-bold text-info mb-1"><?= $avg_progres; ?>%</h3>
            <div class="progress mt-1" style="height: 6px;">
                <div class="progress-bar bg-info" role="progressbar" style="width: <?= $avg_progres; ?>%;" aria-valuenow="<?= $avg_progres; ?>" aria-valuemin="0" aria-valuemax="100"></div>
            </div>
        </div>
    </div>
</div>

<!-- Card Pencarian & Filter -->
<div class="card shadow-sm border-0 mb-4 bg-white">
    <div class="card-body p-3">
        <form action="mahasiswa_bimbingan.php" method="GET" class="row g-2 align-items-center">
            <div class="col-md-5">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" class="form-control border-start-0" placeholder="Cari Nama, NIM, atau Judul Skripsi..." value="<?= htmlspecialchars($search); ?>">
                </div>
            </div>
            <div class="col-md-4">
                <select name="status" class="form-select">
                    <option value="semua" <?= ($filter_status === 'semua') ? 'selected' : ''; ?>>-- Semua Status Akademik --</option>
                    <option value="proses_bimbingan" <?= ($filter_status === 'proses_bimbingan') ? 'selected' : ''; ?>>Proses Bimbingan</option>
                    <option value="siap_sidang" <?= ($filter_status === 'siap_sidang') ? 'selected' : ''; ?>>Siap Sidang Skripsi</option>
                    <option value="lulus" <?= ($filter_status === 'lulus') ? 'selected' : ''; ?>>Lulus</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary w-100 fw-semibold">
                    <i class="bi bi-filter me-1"></i> Terapkan
                </button>
                <?php if (!empty($search) || $filter_status !== 'semua'): ?>
                    <a href="mahasiswa_bimbingan.php" class="btn btn-outline-secondary" title="Reset Filter">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Tabel Daftar Mahasiswa & Progres -->
<div class="card shadow-sm border-0 bg-white">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold text-dark">
            <i class="bi bi-person-lines-fill text-primary me-2"></i>Daftar Mahasiswa Bimbingan Anda
        </h6>
        <span class="badge bg-secondary"><?= count($daftar_mahasiswa); ?> Mahasiswa Ditemukan</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 45px;" class="text-center">No</th>
                        <th>Mahasiswa & Judul Skripsi</th>
                        <th style="min-width: 150px;">Progres Skripsi</th>
                        <th>Bab Terakhir</th>
                        <th>Total Bimbingan</th>
                        <th>Status Akademik</th>
                        <th style="width: 220px;" class="text-center">Aksi / Tindakan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($daftar_mahasiswa)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="bi bi-people fs-1 d-block mb-2 text-muted"></i>
                                Tidak ada mahasiswa bimbingan yang sesuai dengan kriteria pencarian.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($daftar_mahasiswa as $idx => $mhs): ?>
                            <?php
                            $progres = (int)$mhs['progres_tertinggi'];
                            $bar_color = ($progres >= 80) ? 'bg-success' : (($progres >= 50) ? 'bg-info' : 'bg-warning');
                            
                            // Query riwayat bimbingan lengkap untuk modal mahasiswa ini
                            $stmt_hist = $pdo->prepare("
                                SELECT * FROM pengajuan_bimbingan 
                                WHERE mahasiswa_id = :mid AND dosen_id = :did 
                                ORDER BY tanggal_bimbingan DESC, jam_bimbingan DESC
                            ");
                            $stmt_hist->execute([':mid' => $mhs['id'], ':did' => $dosen_id]);
                            $hist_list = $stmt_hist->fetchAll();
                            ?>
                            <tr>
                                <td class="text-center fw-semibold text-muted"><?= $idx + 1; ?></td>
                                <td>
                                    <div class="d-flex align-items-start gap-2">
                                        <div class="avatar-circle avatar-sm avatar-primary mt-1">
                                            <?= strtoupper(substr($mhs['nama_lengkap'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($mhs['nama_lengkap']); ?></div>
                                            <small class="text-muted">NIM: <?= htmlspecialchars($mhs['nim']); ?></small>
                                            <div class="text-truncate small text-secondary mt-1" style="max-width: 260px;" title="<?= htmlspecialchars($mhs['judul_skripsi']); ?>">
                                                <i class="bi bi-mortarboard me-1 text-primary"></i><?= htmlspecialchars($mhs['judul_skripsi']); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <small class="fw-bold text-dark"><?= $progres; ?>%</small>
                                        <small class="text-muted" style="font-size: 0.75rem;">
                                            <?= ($progres >= 100) ? 'Tuntas' : (($progres >= 50) ? 'Lanjut' : 'Awal'); ?>
                                        </small>
                                    </div>
                                    <div class="progress" style="height: 8px;">
                                        <div class="progress-bar <?= $bar_color; ?>" role="progressbar" style="width: <?= $progres; ?>%;" aria-valuenow="<?= $progres; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($mhs['bab_terakhir'])): ?>
                                        <span class="badge bg-light text-dark border"><?= htmlspecialchars($mhs['bab_terakhir']); ?></span>
                                        <?php if (!empty($mhs['tgl_terakhir'])): ?>
                                            <small class="d-block text-muted" style="font-size: 0.72rem;">
                                                <?= date('d/m/Y', strtotime($mhs['tgl_terakhir'])); ?>
                                            </small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted small fst-italic">Belum bimbingan</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="fw-bold text-dark"><?= (int)$mhs['total_sesi']; ?></span> <small class="text-muted">Sesi</small>
                                </td>
                                <td>
                                    <?php
                                    $stk = $mhs['status_kelulusan'];
                                    if ($stk === 'siap_sidang') {
                                        echo '<span class="badge bg-success-subtle text-success border border-success-subtle"><i class="bi bi-check-circle me-1"></i>Siap Sidang</span>';
                                    } elseif ($stk === 'lulus') {
                                        echo '<span class="badge bg-primary"><i class="bi bi-mortarboard-fill me-1"></i>Lulus</span>';
                                    } else {
                                        echo '<span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>Proses Bimbingan</span>';
                                    }
                                    ?>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <!-- Tombol 1: Detail & Timeline Riwayat Bimbingan -->
                                        <button type="button" 
                                                class="btn btn-outline-primary" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#modalRiwayatMhs<?= $mhs['id']; ?>"
                                                title="Lihat Timeline Riwayat & Logbook">
                                            <i class="bi bi-clock-history"></i> Riwayat
                                        </button>

                                        <!-- Tombol 2: Chat Langsung -->
                                        <a href="chat.php?uid=<?= $mhs['mhs_user_id']; ?>" 
                                           class="btn btn-outline-success" 
                                           title="Kirim Pesan Konsultasi">
                                            <i class="bi bi-chat-dots-fill"></i>
                                        </a>

                                        <!-- Tombol 3: Cetak Laporan Mahasiswa Ini -->
                                        <a href="cetak_laporan.php?mahasiswa_id=<?= $mhs['id']; ?>" 
                                           class="btn btn-outline-secondary" 
                                           title="Cetak Lembar Rekapan Mahasiswa Ini">
                                            <i class="bi bi-printer"></i>
                                        </a>
                                    </div>

                                    <!-- MODAL DETAIL RIWAYAT & TIMELINE MAHASISWA -->
                                    <div class="modal fade" id="modalRiwayatMhs<?= $mhs['id']; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-lg text-start">
                                            <div class="modal-content">
                                                <div class="modal-header bg-primary text-white">
                                                    <h5 class="modal-title fw-bold">
                                                        <i class="bi bi-person-badge me-2"></i>Riwayat Bimbingan: <?= htmlspecialchars($mhs['nama_lengkap']); ?>
                                                    </h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <div class="modal-body p-4">
                                                    <div class="p-3 bg-light rounded border mb-4">
                                                        <div class="row g-2 small">
                                                            <div class="col-md-6">
                                                                <span class="text-muted d-block">NIM:</span>
                                                                <strong><?= htmlspecialchars($mhs['nim']); ?></strong>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <span class="text-muted d-block">Status Kelulusan:</span>
                                                                <strong class="text-uppercase"><?= str_replace('_', ' ', $mhs['status_kelulusan']); ?></strong>
                                                            </div>
                                                            <div class="col-12 mt-2">
                                                                <span class="text-muted d-block">Judul Skripsi:</span>
                                                                <span class="fw-semibold text-dark"><?= htmlspecialchars($mhs['judul_skripsi']); ?></span>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <h6 class="fw-bold mb-3 text-dark"><i class="bi bi-list-check text-primary me-2"></i>Daftar Sesi Konsultasi & Catatan Umpan Balik</h6>

                                                    <?php if (empty($hist_list)): ?>
                                                        <div class="text-center py-4 text-muted small">
                                                            <i class="bi bi-calendar-x fs-2 d-block mb-1"></i>
                                                            Mahasiswa ini belum memiliki riwayat bimbingan.
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="table-responsive">
                                                            <table class="table table-sm table-bordered align-middle small mb-0">
                                                                <thead class="table-light text-center">
                                                                    <tr>
                                                                        <th>Tanggal</th>
                                                                        <th>Bab</th>
                                                                        <th>Catatan Dosen</th>
                                                                        <th style="width: 70px;">Nilai</th>
                                                                        <th style="width: 90px;">Status</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    <?php foreach ($hist_list as $h): ?>
                                                                        <tr>
                                                                            <td class="text-center">
                                                                                <?= date('d/m/Y', strtotime($h['tanggal_bimbingan'])); ?><br>
                                                                                <small class="text-muted"><?= substr($h['jam_bimbingan'], 0, 5); ?> WIB</small>
                                                                            </td>
                                                                            <td><strong><?= htmlspecialchars($h['bab_pembahasan']); ?></strong></td>
                                                                            <td>
                                                                                <?= !empty($h['catatan_dosen']) ? nl2br(htmlspecialchars($h['catatan_dosen'])) : '<span class="text-muted fst-italic">Belum ada catatan.</span>'; ?>
                                                                            </td>
                                                                            <td class="text-center fw-bold text-primary"><?= (int)$h['nilai_progres']; ?>%</td>
                                                                            <td class="text-center">
                                                                                <?php
                                                                                $sh = $h['status'];
                                                                                if ($sh === 'disetujui') echo '<span class="badge bg-success">Disetujui</span>';
                                                                                elseif ($sh === 'selesai') echo '<span class="badge bg-primary">Selesai</span>';
                                                                                elseif ($sh === 'revisi') echo '<span class="badge bg-warning text-dark">Revisi</span>';
                                                                                else echo '<span class="badge bg-secondary">' . ucfirst($sh) . '</span>';
                                                                                ?>
                                                                            </td>
                                                                        </tr>
                                                                    <?php endforeach; ?>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="modal-footer">
                                                    <a href="riwayat_bimbingan.php" class="btn btn-sm btn-primary">
                                                        <i class="bi bi-pencil-square me-1"></i> Beri Evaluasi Naskah
                                                    </a>
                                                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Tutup</button>
                                                </div>
                                            </div>
                                        </div>
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

<?php
require_once '../templates/footer.php';
?>

