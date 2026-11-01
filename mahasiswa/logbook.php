<?php
// mahasiswa/logbook.php - Rekapitulasi Catatan & Logbook Progres Bimbingan Skripsi
$page_title   = "Logbook & Progres Skripsi";
$current_page = "logbook";

require_once '../config/database.php';
require_once '../templates/header.php';
require_once '../templates/sidebar_mahasiswa.php';

// Proteksi akses role mahasiswa
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'mahasiswa') {
    header("Location: ../login.php");
    exit;
}

// Ambil info mahasiswa & dosen pembimbing
$stmt_mhs = $pdo->prepare("
    SELECT m.*, d.nama_dosen, d.nidn, d.email AS email_dosen, d.no_whatsapp 
    FROM mahasiswa m 
    LEFT JOIN dosen d ON m.dosen_pembimbing_id = d.id 
    WHERE m.user_id = :uid LIMIT 1
");
$stmt_mhs->execute([':uid' => $_SESSION['user_id']]);
$mhs = $stmt_mhs->fetch();

$mhs_id = $mhs['id'] ?? 0;

// Filter kategori bab
$filter_bab = trim($_GET['bab'] ?? 'semua');

// Query logbook bimbingan (termasuk link_drive dari UPDATE Robi)
$query_logbook = "
    SELECT p.id, p.tanggal_bimbingan, p.jam_bimbingan, p.bab_pembahasan, p.catatan_mahasiswa, 
           p.file_draft, p.link_drive, p.status, p.catatan_dosen, p.nilai_progres,
           lb.tanggal_acc, lb.catatan_hasil, lb.status_bab
    FROM pengajuan_bimbingan p
    LEFT JOIN logbook_bimbingan lb ON p.id = lb.bimbingan_id
    WHERE p.mahasiswa_id = :mid
";

$params = [':mid' => $mhs_id];

if ($filter_bab !== 'semua' && !empty($filter_bab)) {
    $query_logbook .= " AND p.bab_pembahasan LIKE :bab";
    $params[':bab'] = '%' . $filter_bab . '%';
}

$query_logbook .= " ORDER BY p.tanggal_bimbingan DESC, p.jam_bimbingan DESC";

$stmt_lb = $pdo->prepare($query_logbook);
$stmt_lb->execute($params);
$logbook_list = $stmt_lb->fetchAll();

// Hitung statistik
$total_sesi = count($logbook_list);
$total_acc  = 0;
$total_revisi = 0;
foreach ($logbook_list as $item) {
    if (in_array($item['status'], ['selesai', 'disetujui']) || $item['status_bab'] === 'acc_bab' || $item['status_bab'] === 'acc_sidang') {
        $total_acc++;
    } elseif ($item['status'] === 'revisi' || $item['status_bab'] === 'revisi') {
        $total_revisi++;
    }
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2 mb-0">Logbook Bimbingan Skripsi</h1>
        <p class="text-muted small mb-0">Catatan resmi kartu kendali konsultasi dan evaluasi draf skripsi oleh dosen pembimbing.</p>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="index.php" class="btn btn-outline-secondary btn-sm me-2">
            <i class="bi bi-speedometer2 me-1"></i>Dashboard
        </a>
        <a href="ajukan_jadwal.php" class="btn btn-primary btn-sm me-2">
            <i class="bi bi-calendar-plus me-1"></i>Ajukan Sesi Baru
        </a>
        <a href="cetak_laporan.php" target="_blank" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-printer me-1"></i>Cetak Kartu Bimbingan
        </a>
    </div>
</div>

<!-- Kartu Informasi Judul & Status Skripsi -->
<div class="card shadow-sm mb-4 border-0">
    <div class="card-body p-4 bg-white rounded">
        <div class="row align-items-center">
            <div class="col-lg-8">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                        <i class="bi bi-mortarboard me-1"></i>Skripsi Mahasiswa
                    </span>
                    <span class="badge bg-primary rounded-pill px-2 py-1 small"><?= htmlspecialchars($mhs['kelas'] ?? 'TI-1A-2025'); ?></span>
                </div>
                <h5 class="fw-bold text-dark mb-1"><?= htmlspecialchars($mhs['judul_skripsi'] ?? 'Judul Belum Diatur'); ?></h5>
                <p class="text-muted small mb-0">
                    NIM: <strong><?= htmlspecialchars($mhs['nim'] ?? '-'); ?></strong> | 
                    Nama: <strong><?= htmlspecialchars($mhs['nama_lengkap'] ?? '-'); ?></strong> | 
                    Pembimbing: <strong><?= htmlspecialchars($mhs['nama_dosen'] ?? 'Belum Diplot'); ?></strong>
                </p>
            </div>
            <div class="col-lg-4 text-lg-end mt-3 mt-lg-0">
                <div class="d-inline-flex gap-2">
                    <div class="p-2 border rounded text-center bg-light" style="min-width: 90px;">
                        <span class="d-block text-muted small" style="font-size: 0.7rem;">TOTAL SESI</span>
                        <h5 class="fw-bold mb-0 text-primary"><?= $total_sesi; ?></h5>
                    </div>
                    <div class="p-2 border rounded text-center bg-light" style="min-width: 90px;">
                        <span class="d-block text-muted small" style="font-size: 0.7rem;">DI-ACC</span>
                        <h5 class="fw-bold mb-0 text-success"><?= $total_acc; ?></h5>
                    </div>
                    <div class="p-2 border rounded text-center bg-light" style="min-width: 90px;">
                        <span class="d-block text-muted small" style="font-size: 0.7rem;">REVISI</span>
                        <h5 class="fw-bold mb-0 text-warning"><?= $total_revisi; ?></h5>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filter dan Pencarian -->
<div class="card shadow-sm mb-4 border-0">
    <div class="card-body p-3">
        <form action="logbook.php" method="GET" class="row g-2 align-items-center">
            <div class="col-auto">
                <label class="col-form-label small fw-semibold text-secondary">
                    <i class="bi bi-funnel me-1"></i>Filter Bab:
                </label>
            </div>
            <div class="col-auto">
                <select name="bab" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="semua" <?= $filter_bab === 'semua' ? 'selected' : ''; ?>>Semua Bab Pembahasan</option>
                    <option value="Bab 1" <?= $filter_bab === 'Bab 1' ? 'selected' : ''; ?>>Bab 1 (Pendahuluan)</option>
                    <option value="Bab 2" <?= $filter_bab === 'Bab 2' ? 'selected' : ''; ?>>Bab 2 (Tinjauan Pustaka)</option>
                    <option value="Bab 3" <?= $filter_bab === 'Bab 3' ? 'selected' : ''; ?>>Bab 3 (Metodologi)</option>
                    <option value="Bab 4" <?= $filter_bab === 'Bab 4' ? 'selected' : ''; ?>>Bab 4 (Hasil & Pembahasan)</option>
                    <option value="Bab 5" <?= $filter_bab === 'Bab 5' ? 'selected' : ''; ?>>Bab 5 (Kesimpulan)</option>
                </select>
            </div>
            <?php if ($filter_bab !== 'semua'): ?>
                <div class="col-auto">
                    <a href="logbook.php" class="btn btn-outline-secondary btn-sm">Reset Filter</a>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Tabel Logbook Lengkap -->
<div class="card shadow-sm mb-4 border-0">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
        <h6 class="m-0 fw-bold text-dark">
            <i class="bi bi-journal-bookmark-fill text-primary me-2"></i>Catatan Kartu Kendali Bimbingan
        </h6>
        <span class="badge bg-secondary small"><?= count($logbook_list); ?> Catatan</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 50px;">No</th>
                        <th style="width: 140px;">Tanggal & Waktu</th>
                        <th>Materi / Bab</th>
                        <th>Uraian Konsultasi & Masukan Dosen</th>
                        <th style="width: 100px;">Nilai</th>
                        <th style="width: 120px;">Status ACC</th>
                        <th style="width: 120px;" class="text-center">Berkas Draf</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logbook_list)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="bi bi-journal-x fs-1 d-block mb-2"></i>
                                Belum ada catatan logbook bimbingan pada filter ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $no = 1; foreach ($logbook_list as $row): ?>
                            <tr>
                                <td><?= $no++; ?></td>
                                <td>
                                    <strong><?= date('d/m/Y', strtotime($row['tanggal_bimbingan'])); ?></strong><br>
                                    <small class="text-muted"><?= substr($row['jam_bimbingan'], 0, 5); ?> WIB</small>
                                </td>
                                <td>
                                    <span class="fw-bold text-dark"><?= htmlspecialchars($row['bab_pembahasan']); ?></span>
                                    <?php if (!empty($row['catatan_mahasiswa'])): ?>
                                        <p class="text-muted small mb-0 mt-1">
                                            <em>Mahasiswa:</em> <?= htmlspecialchars($row['catatan_mahasiswa']); ?>
                                        </p>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['catatan_hasil'])): ?>
                                        <div class="small text-dark fw-semibold mb-1">
                                            <?= nl2br(htmlspecialchars($row['catatan_hasil'])); ?>
                                        </div>
                                    <?php elseif (!empty($row['catatan_dosen'])): ?>
                                        <div class="small text-dark mb-1">
                                            <?= nl2br(htmlspecialchars($row['catatan_dosen'])); ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted small"><em>Belum ada catatan masukan dari pembimbing.</em></span>
                                    <?php endif; ?>

                                    <?php if (!empty($row['tanggal_acc'])): ?>
                                        <div class="text-success small" style="font-size: 0.75rem;">
                                            <i class="bi bi-check2-circle me-1"></i>Di-ACC pada: <?= date('d M Y', strtotime($row['tanggal_acc'])); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($row['nilai_progres'] > 0): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle fs-6">
                                            <?= $row['nilai_progres']; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $st_bab = $row['status_bab'] ?? '';
                                    if ($st_bab === 'acc_sidang') {
                                        echo '<span class="badge bg-success"><i class="bi bi-mortarboard-fill me-1"></i>ACC Sidang</span>';
                                    } elseif ($st_bab === 'acc_bab' || in_array($row['status'], ['disetujui', 'selesai'])) {
                                        echo '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>ACC Bab</span>';
                                    } elseif ($st_bab === 'revisi' || $row['status'] === 'revisi') {
                                        echo '<span class="badge bg-warning text-dark"><i class="bi bi-arrow-repeat me-1"></i>Revisi</span>';
                                    } elseif ($row['status'] === 'menunggu') {
                                        echo '<span class="badge bg-secondary"><i class="bi bi-clock me-1"></i>Menunggu</span>';
                                    } else {
                                        echo '<span class="badge bg-light text-dark border">Belum Selesai</span>';
                                    }
                                    ?>
                                </td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-1">
                                        <?php if (!empty($row['file_draft'])): ?>
                                            <a href="../assets/uploads/<?= htmlspecialchars($row['file_draft']); ?>" target="_blank" class="btn btn-outline-danger btn-sm py-0 px-2" style="font-size: 0.75rem;" title="Lihat PDF">
                                                <i class="bi bi-file-earmark-pdf"></i> PDF
                                            </a>
                                        <?php endif; ?>
                                        <?php if (!empty($row['link_drive'])): ?>
                                            <a href="<?= htmlspecialchars($row['link_drive']); ?>" target="_blank" class="btn btn-outline-primary btn-sm py-0 px-2" style="font-size: 0.75rem;" title="Buka Google Drive">
                                                <i class="bi bi-google"></i> Drive
                                            </a>
                                        <?php endif; ?>
                                        <?php if (empty($row['file_draft']) && empty($row['link_drive'])): ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
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
