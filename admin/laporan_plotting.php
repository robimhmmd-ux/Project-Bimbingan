<?php
// admin/laporan_plotting.php - Rekapitulasi & Cetak Laporan Pembagian Bimbingan
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Proteksi akses role admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

require_once '../config/database.php';

// Filter parameter
$filter_dosen_id = isset($_GET['dosen_id']) && $_GET['dosen_id'] !== '' ? (int)$_GET['dosen_id'] : 0;
$filter_status   = $_GET['status'] ?? 'all';

// Ambil daftar dosen untuk filter dropdown
$dosen_filter_list = $pdo->query("SELECT id, nidn, nama_dosen, kuota_bimbingan FROM dosen ORDER BY nama_dosen ASC")->fetchAll();

// Ambil data dosen dengan mahasiswanya
$where_dosen = ["1=1"];
$params_dosen = [];

if ($filter_dosen_id > 0) {
    $where_dosen[] = "d.id = :did";
    $params_dosen[':did'] = $filter_dosen_id;
}

$where_dosen_sql = implode(" AND ", $where_dosen);

$queryDosen = "
    SELECT 
        d.id, 
        d.nidn, 
        d.nama_dosen, 
        d.email, 
        d.no_whatsapp, 
        d.kuota_bimbingan,
        COUNT(m.id) AS total_bimbingan
    FROM dosen d
    LEFT JOIN mahasiswa m ON d.id = m.dosen_pembimbing_id
    WHERE {$where_dosen_sql}
    GROUP BY d.id
    ORDER BY d.nama_dosen ASC
";
$stmtD = $pdo->prepare($queryDosen);
$stmtD->execute($params_dosen);
$laporan_dosen = $stmtD->fetchAll();

// Ambil seluruh mahasiswa yang terplot ke masing-masing dosen
$where_mhs = ["m.dosen_pembimbing_id IS NOT NULL"];
$params_mhs = [];

if ($filter_dosen_id > 0) {
    $where_mhs[] = "m.dosen_pembimbing_id = :did";
    $params_mhs[':did'] = $filter_dosen_id;
}

if ($filter_status !== 'all' && in_array($filter_status, ['proses_bimbingan', 'siap_sidang', 'lulus'])) {
    $where_mhs[] = "m.status_kelulusan = :st";
    $params_mhs[':st'] = $filter_status;
}

$where_mhs_sql = implode(" AND ", $where_mhs);

$queryMhs = "
    SELECT 
        m.*,
        COALESCE(
            (SELECT nilai_progres FROM pengajuan_bimbingan WHERE mahasiswa_id = m.id ORDER BY id DESC LIMIT 1),
            0
        ) AS nilai_terakhir,
        (SELECT COUNT(*) FROM logbook_bimbingan WHERE mahasiswa_id = m.id) AS total_acc
    FROM mahasiswa m
    WHERE {$where_mhs_sql}
    ORDER BY m.dosen_pembimbing_id, m.nim ASC
";
$stmtM = $pdo->prepare($queryMhs);
$stmtM->execute($params_mhs);
$all_mhs = $stmtM->fetchAll();

// Kelompokkan mahasiswa berdasarkan dosen_pembimbing_id
$mhs_by_dosen = [];
foreach ($all_mhs as $m) {
    $mhs_by_dosen[$m['dosen_pembimbing_id']][] = $m;
}

// Ambil juga mahasiswa yang belum diplot (hanya jika melihat semua dosen)
$mhs_unassigned = [];
if ($filter_dosen_id === 0) {
    $mhs_unassigned = $pdo->query("SELECT * FROM mahasiswa WHERE dosen_pembimbing_id IS NULL ORDER BY nim ASC")->fetchAll();
}

// Statistik Keseluruhan
$total_mhs_all   = $pdo->query("SELECT COUNT(*) FROM mahasiswa")->fetchColumn();
$total_terplot   = $pdo->query("SELECT COUNT(*) FROM mahasiswa WHERE dosen_pembimbing_id IS NOT NULL")->fetchColumn();
$total_belum     = $total_mhs_all - $total_terplot;
$total_kapasitas = $pdo->query("SELECT SUM(kuota_bimbingan) FROM dosen")->fetchColumn() ?: 0;

$page_title   = "Rekap & Cetak Laporan Pembagian Bimbingan";
$current_page = "laporan";

require_once '../templates/header.php';
require_once '../templates/sidebar_admin.php';
?>

<!-- STYLE KHUSUS CETAK LAPORAN RESMI -->
<style>
@media print {
    /* Sembunyikan elemen navigasi dan tombol interaktif saat cetak */
    .sidebar, .navbar, .btn, .no-print, footer, form, .badge-filter, .hero-banner {
        display: none !important;
    }
    body {
        background-color: #ffffff !important;
        font-size: 11pt;
        color: #000000 !important;
    }
    main {
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
        padding: 0 !important;
    }
    .print-header {
        display: block !important;
    }
    .table-print {
        border: 1px solid #000 !important;
        width: 100% !important;
    }
    .table-print th, .table-print td {
        border: 1px solid #333 !important;
        padding: 6px 8px !important;
    }
    .table-print thead {
        background-color: #f0f0f0 !important;
        -webkit-print-color-adjust: exact;
    }
    .page-break {
        page-break-after: always;
    }
}

.print-header {
    display: none;
}
</style>

<!-- KOP SURAT RESMI (HANYA MUNCUL DI CETAK) -->
<div class="print-header mb-4 text-center">
    <div style="font-size: 13pt; font-weight: bold; text-transform: uppercase; letter-spacing: 1px;">
        KEMENTERIAN PENDIDIKAN, KEBUDAYAAN, RISET, DAN TEKNOLOGI
    </div>
    <div style="font-size: 15pt; font-weight: bold; text-transform: uppercase;">
        FAKULTAS ILMU KOMPUTER & SAINS DATA
    </div>
    <div style="font-size: 11pt; font-style: italic;">
        Sistem Informasi Bimbingan Skripsi & Laporan Progres Akademik
    </div>
    <div style="font-size: 9pt; color: #555;">
        Jl. Kampus Terpadu No. 1, Gedung Akademik Lt. 3 • Laman: https://univ.ac.id
    </div>
    <hr style="border-top: 3px double #000; margin-top: 8px; margin-bottom: 20px;">
    <h5 class="fw-bold text-uppercase mb-1">LAPORAN REKAPITULASI PEMBAGIAN DOSEN PEMBIMBING SKRIPSI</h5>
    <div class="small text-muted mb-3">Tahun Akademik <?= date('Y'); ?> / <?= date('Y')+1; ?> • Dicetak pada: <?= date('d F Y, H:i'); ?> WIB</div>
</div>

<!-- Hero Banner Header (Non-Print) -->
<div class="hero-banner d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 no-print">
    <div>
        <div class="d-inline-flex align-items-center gap-2 px-3 py-1 rounded-pill bg-white bg-opacity-15 text-white small mb-2 border border-white border-opacity-10">
            <i class="bi bi-printer-fill text-danger"></i>
            <span>Pelaporan & Pengesahan Resmi</span>
        </div>
        <h2 class="fw-bold text-white mb-1">Rekapitulasi Pembagian Pembimbing</h2>
        <p class="text-white-50 small mb-0">Laporan resmi alokasi mahasiswa tugas akhir kepada masing-masing dosen pembimbing skripsi.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="plot_pembimbing.php" class="btn btn-outline-light">
            <i class="bi bi-diagram-3 me-1"></i> Atur Pembimbing
        </a>
        <button type="button" class="btn btn-light shadow-sm fw-bold" onclick="window.print()">
            <i class="bi bi-printer-fill text-primary me-1"></i> Cetak / Simpan PDF
        </button>
    </div>
</div>

<!-- Kartu Ringkasan Metrik (Non-Print) -->
<div class="row g-3 mb-4 no-print">
    <div class="col-md-3">
        <div class="metric-card p-3">
            <span class="small text-muted text-uppercase fw-bold">Total Mahasiswa</span>
            <h3 class="fw-bold text-dark mb-0 mt-1"><?= $total_mhs_all; ?></h3>
            <small class="text-muted">Terdaftar di jurusan</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric-card p-3">
            <span class="small text-muted text-uppercase fw-bold">Sudah Terplot</span>
            <h3 class="fw-bold text-success mb-0 mt-1"><?= $total_terplot; ?></h3>
            <small class="text-success"><?= $total_mhs_all > 0 ? round(($total_terplot / $total_mhs_all) * 100) : 0; ?>% terdistribusi</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric-card p-3">
            <span class="small text-muted text-uppercase fw-bold">Belum Terplot</span>
            <h3 class="fw-bold text-warning mb-0 mt-1"><?= $total_belum; ?></h3>
            <small class="text-muted">Perlu alokasi admin</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric-card p-3">
            <span class="small text-muted text-uppercase fw-bold">Total Kuota Jurusan</span>
            <h3 class="fw-bold text-info mb-0 mt-1"><?= $total_kapasitas; ?></h3>
            <small class="text-muted">Kapasitas seluruh dosen</small>
        </div>
    </div>
</div>

<!-- Filter Laporan (Non-Print) -->
<div class="card shadow-sm border-0 mb-4 no-print">
    <div class="card-body p-3">
        <form method="GET" action="laporan_plotting.php" class="row g-2 align-items-center">
            <div class="col-md-5">
                <label class="form-label small fw-semibold text-secondary mb-1">Filter Dosen Pembimbing:</label>
                <select name="dosen_id" class="form-select" onchange="this.form.submit()">
                    <option value="0">-- Semua Dosen Pembimbing --</option>
                    <?php foreach ($dosen_filter_list as $d): ?>
                        <option value="<?= $d['id']; ?>" <?= ($filter_dosen_id == $d['id']) ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($d['nama_dosen']); ?> (NIDN: <?= htmlspecialchars($d['nidn']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-semibold text-secondary mb-1">Status Kelulusan Mahasiswa:</label>
                <select name="status" class="form-select" onchange="this.form.submit()">
                    <option value="all" <?= ($filter_status === 'all') ? 'selected' : ''; ?>>-- Semua Status --</option>
                    <option value="proses_bimbingan" <?= ($filter_status === 'proses_bimbingan') ? 'selected' : ''; ?>>Proses Bimbingan</option>
                    <option value="siap_sidang" <?= ($filter_status === 'siap_sidang') ? 'selected' : ''; ?>>Siap Sidang</option>
                    <option value="lulus" <?= ($filter_status === 'lulus') ? 'selected' : ''; ?>>Lulus</option>
                </select>
            </div>
            <div class="col-md-3 pt-md-4">
                <?php if ($filter_dosen_id > 0 || $filter_status !== 'all'): ?>
                    <a href="laporan_plotting.php" class="btn btn-outline-secondary w-100">Reset Filter</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- KONTEN LAPORAN: DAFTAR MAHASISWA PER DOSEN PEMBIMBING -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-0">
        <?php if (empty($laporan_dosen)): ?>
            <div class="text-center py-5 text-muted">Tidak ada data pembagian bimbingan sesuai filter.</div>
        <?php else: ?>
            <?php foreach ($laporan_dosen as $dosen): 
                $mhs_list = $mhs_by_dosen[$dosen['id']] ?? [];
                $bimbingan_count = count($mhs_list);
                $kuota = (int)$dosen['kuota_bimbingan'];
                $sisa  = $kuota - $bimbingan_count;
                $initial = strtoupper(substr($dosen['nama_dosen'], 0, 1));
            ?>
                <div class="p-4 border-bottom">
                    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
                        <div class="d-flex align-items-center gap-3">
                            <div class="avatar-initial bg-primary-subtle text-primary fs-5" style="width: 44px; height: 44px;">
                                <?= $initial; ?>
                            </div>
                            <div>
                                <h5 class="fw-bold text-dark mb-0">
                                    <?= htmlspecialchars($dosen['nama_dosen']); ?>
                                </h5>
                                <div class="small text-muted">
                                    <span class="font-monospace">NIDN: <?= htmlspecialchars($dosen['nidn']); ?></span>
                                    <span class="mx-2">•</span>
                                    <span><i class="bi bi-envelope me-1"></i><?= htmlspecialchars($dosen['email']); ?></span>
                                    <?php if (!empty($dosen['no_whatsapp'])): ?>
                                        <span class="mx-2">•</span>
                                        <span><i class="bi bi-whatsapp me-1 text-success"></i><?= htmlspecialchars($dosen['no_whatsapp']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="text-end mt-2 mt-md-0">
                            <span class="badge bg-light text-dark border px-3 py-2 fs-6">
                                Beban Bimbingan: <strong><?= $bimbingan_count; ?></strong> / <?= $kuota; ?> Mahasiswa
                                <span class="ms-1 <?= ($sisa > 0) ? 'text-success' : 'text-danger'; ?>">
                                    (<?= ($sisa > 0) ? "Sisa {$sisa}" : 'Kuota Penuh'; ?>)
                                </span>
                            </span>
                        </div>
                    </div>

                    <?php if (empty($mhs_list)): ?>
                        <div class="alert alert-light border small text-muted mb-0 py-2">
                            <i class="bi bi-info-circle me-1"></i>Belum ada mahasiswa yang dialokasikan ke dosen ini.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle mb-0 table-print">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 40px;" class="text-center">No</th>
                                        <th style="width: 120px;">NIM</th>
                                        <th style="width: 220px;">Nama Mahasiswa</th>
                                        <th>Judul Penelitian Skripsi</th>
                                        <th style="width: 90px;" class="text-center">Progres</th>
                                        <th style="width: 130px;" class="text-center">Status Kelulusan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $no = 1;
                                    foreach ($mhs_list as $m): 
                                    ?>
                                        <tr>
                                            <td class="text-center"><?= $no++; ?></td>
                                            <td class="font-monospace fw-bold text-primary"><?= htmlspecialchars($m['nim']); ?></td>
                                            <td class="fw-semibold text-dark"><?= htmlspecialchars($m['nama_lengkap']); ?></td>
                                            <td><?= htmlspecialchars($m['judul_skripsi']); ?></td>
                                            <td class="text-center font-monospace fw-bold">
                                                <?= (int)$m['nilai_terakhir']; ?>%
                                            </td>
                                            <td class="text-center">
                                                <?php if ($m['status_kelulusan'] === 'lulus'): ?>
                                                    <span class="badge bg-success">Lulus</span>
                                                <?php elseif ($m['status_kelulusan'] === 'siap_sidang'): ?>
                                                    <span class="badge bg-primary">Siap Sidang</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Bimbingan</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Bagian Mahasiswa Belum Diplot (Hanya jika melihat semua dosen) -->
<?php if (!empty($mhs_unassigned)): ?>
    <div class="card shadow-sm border-0 mb-4 page-break">
        <div class="card-header bg-warning bg-opacity-25 py-3">
            <h6 class="fw-bold mb-0 text-dark">
                <i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>
                Lampiran: Daftar Mahasiswa Belum Memiliki Dosen Pembimbing (<?= count($mhs_unassigned); ?> Mahasiswa)
            </h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0 table-print">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 40px;" class="text-center">No</th>
                            <th style="width: 130px;">NIM</th>
                            <th style="width: 220px;">Nama Mahasiswa</th>
                            <th>Rencana Judul Skripsi</th>
                            <th style="width: 140px;" class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $no = 1;
                        foreach ($mhs_unassigned as $mu): 
                        ?>
                            <tr>
                                <td class="text-center"><?= $no++; ?></td>
                                <td class="font-monospace fw-bold"><?= htmlspecialchars($mu['nim']); ?></td>
                                <td class="fw-semibold"><?= htmlspecialchars($mu['nama_lengkap']); ?></td>
                                <td><?= htmlspecialchars($mu['judul_skripsi']); ?></td>
                                <td class="text-center text-danger fw-semibold">Belum Diplot</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- LEMBAR PENGESAHAN RESMI (MUNCUL DI HASIL CETAK) -->
<div class="print-signature mt-5 pt-3" style="page-break-inside: avoid;">
    <div class="row text-center">
        <div class="col-6">
            <p class="mb-1">Mengetahui,</p>
            <p class="fw-bold text-uppercase">Koordinator Skripsi / Tugas Akhir</p>
            <div style="height: 70px;"></div>
            <p class="fw-bold text-decoration-underline mb-0">( ..................................................... )</p>
            <p class="small text-muted">NIP. .................................................</p>
        </div>
        <div class="col-6">
            <p class="mb-1">Ditetapkan di: Jakarta, <?= date('d F Y'); ?></p>
            <p class="fw-bold text-uppercase">Ketua Program Studi</p>
            <div style="height: 70px;"></div>
            <p class="fw-bold text-decoration-underline mb-0">( ..................................................... )</p>
            <p class="small text-muted">NIP. .................................................</p>
        </div>
    </div>
</div>

<?php
require_once '../templates/footer.php';
?>
