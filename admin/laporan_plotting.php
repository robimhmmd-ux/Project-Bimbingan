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

<!-- STYLE KHUSUS FORMAT AKADEMIK CETAK LAPORAN RESMI -->
<style>
.academic-sheet, .academic-sheet * {
    font-family: 'Times New Roman', Times, 'Liberation Serif', serif !important;
    box-shadow: none !important;
    text-shadow: none !important;
}

.academic-sheet {
    background: #ffffff;
    border: 1px solid #000000;
    border-radius: 0;
    padding: 15mm 20mm;
    color: #000000;
    margin-bottom: 30px;
}

.kop-surat-admin {
    border-bottom: 3px double #000000;
    padding-bottom: 12px;
    margin-bottom: 20px;
    text-align: center;
    color: #000000;
}

.table-academic {
    border: 1px solid #000000 !important;
    border-collapse: collapse !important;
    width: 100% !important;
    color: #000000 !important;
}

.table-academic th, .table-academic td {
    border: 1px solid #000000 !important;
    padding: 6px 8px !important;
    font-size: 10pt !important;
    background: #ffffff !important;
    color: #000000 !important;
    vertical-align: middle !important;
}

.table-academic thead th {
    background-color: #ffffff !important;
    color: #000000 !important;
    text-align: center !important;
    font-weight: bold !important;
    border-bottom: 2px solid #000000 !important;
}

.no-print .card, .no-print .metric-card {
    box-shadow: none !important;
}

@page {
    size: A4 portrait;
    margin: 15mm 15mm 15mm 15mm;
}

@media print {
    /* Sembunyikan elemen navigasi dan tombol interaktif saat cetak */
    .sidebar, .navbar, .btn, .no-print, footer, form, .badge-filter, .hero-banner {
        display: none !important;
    }
    body {
        background-color: #ffffff !important;
        font-size: 11pt;
        color: #000000 !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    main {
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    .academic-sheet {
        border: none !important;
        box-shadow: none !important;
        padding: 0 !important;
        margin: 0 !important;
        width: 100% !important;
    }
    .page-break {
        page-break-after: always;
    }
}
</style>

<!-- Hero Banner Header (Non-Print) -->
<div class="hero-banner d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 no-print mb-4">
    <div>
        <div class="hero-tag">
            <i class="bi bi-printer-fill text-danger"></i>
            <span>Pelaporan & Pengesahan Resmi</span>
        </div>
        <h2 class="fw-bold text-white mb-1">Rekapitulasi Pembagian Pembimbing</h2>
        <p class="text-white-50 small mb-0">Format akademik resmi alokasi mahasiswa tugas akhir kepada masing-masing dosen pembimbing skripsi.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="plot_pembimbing.php" class="btn btn-outline-light">
            <i class="bi bi-diagram-3 me-1"></i> Atur Pembimbing
        </a>
        <button type="button" class="btn btn-light fw-bold" onclick="window.print()">
            <i class="bi bi-printer-fill text-primary me-1"></i> Cetak / Simpan PDF
        </button>
    </div>
</div>

<!-- Kartu Ringkasan Metrik (Non-Print) -->
<div class="row g-3 mb-4 no-print">
    <div class="col-md-3">
        <div class="metric-card p-3 border">
            <span class="small text-muted text-uppercase fw-bold">Total Mahasiswa</span>
            <h3 class="fw-bold text-dark mb-0 mt-1"><?= $total_mhs_all; ?></h3>
            <small class="text-muted">Terdaftar di jurusan</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric-card p-3 border">
            <span class="small text-muted text-uppercase fw-bold">Sudah Terplot</span>
            <h3 class="fw-bold text-success mb-0 mt-1"><?= $total_terplot; ?></h3>
            <small class="text-success"><?= $total_mhs_all > 0 ? round(($total_terplot / $total_mhs_all) * 100) : 0; ?>% terdistribusi</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric-card p-3 border">
            <span class="small text-muted text-uppercase fw-bold">Belum Terplot</span>
            <h3 class="fw-bold text-warning mb-0 mt-1"><?= $total_belum; ?></h3>
            <small class="text-muted">Perlu alokasi admin</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric-card p-3 border">
            <span class="small text-muted text-uppercase fw-bold">Total Kuota Jurusan</span>
            <h3 class="fw-bold text-info mb-0 mt-1"><?= $total_kapasitas; ?></h3>
            <small class="text-muted">Kapasitas seluruh dosen</small>
        </div>
    </div>
</div>

<!-- Filter Laporan (Non-Print) -->
<div class="card border mb-4 no-print">
    <div class="card-body p-3">
        <form method="GET" action="laporan_plotting.php" class="row g-2 align-items-center">
            <div class="col-md-5">
                <label class="form-label small fw-semibold text-secondary mb-1">Filter Dosen Pembimbing:</label>
                <select name="dosen_id" class="form-select form-select-sm" onchange="this.form.submit()">
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
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="all" <?= ($filter_status === 'all') ? 'selected' : ''; ?>>-- Semua Status --</option>
                    <option value="proses_bimbingan" <?= ($filter_status === 'proses_bimbingan') ? 'selected' : ''; ?>>Proses Bimbingan</option>
                    <option value="siap_sidang" <?= ($filter_status === 'siap_sidang') ? 'selected' : ''; ?>>Siap Sidang</option>
                    <option value="lulus" <?= ($filter_status === 'lulus') ? 'selected' : ''; ?>>Lulus</option>
                </select>
            </div>
            <div class="col-md-3 pt-md-4">
                <?php if ($filter_dosen_id > 0 || $filter_status !== 'all'): ?>
                    <a href="laporan_plotting.php" class="btn btn-outline-secondary btn-sm w-100">Reset Filter</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================
     LEMBAR DOKUMEN CETAK AKADEMIK RESMI (PRINT & ON-SCREEN SHEET)
============================================================ -->
<div class="academic-sheet">

    <!-- KOP SURAT RESMI AKADEMIK -->
    <div class="kop-surat-admin">
        <h5 class="fw-bold mb-0 text-uppercase" style="letter-spacing: 0.5px; font-size: 13pt;">KEMENTERIAN PENDIDIKAN TINGGI, SAINS, DAN TEKNOLOGI</h5>
        <h4 class="fw-bold mb-1 text-uppercase" style="font-size: 15pt;">UNIVERSITAS TEKNOLOGI DAN ILMU KOMPUTER</h4>
        <h6 class="mb-1 text-uppercase fw-semibold" style="font-size: 11pt;">FAKULTAS ILMU KOMPUTER - PROGRAM STUDI TEKNIK INFORMATIKA</h6>
        <p class="mb-0" style="font-size: 9.5pt;">
            Jl. Pendidikan No. 45 Kampus Terpadu | Telp: (021) 1234-5678 | Email: akademik@univ.ac.id | Laman: www.univ.ac.id
        </p>
    </div>

    <!-- JUDUL DOKUMEN LAPORAN -->
    <div class="text-center mb-4">
        <h5 class="fw-bold text-uppercase mb-1" style="text-decoration: underline; font-size: 13pt;">
            LAPORAN REKAPITULASI PEMBAGIAN DOSEN PEMBIMBING SKRIPSI
        </h5>
        <div style="font-size: 10pt;">
            Tahun Akademik <?= date('Y'); ?> / <?= date('Y')+1; ?> • Dicetak pada: <?= date('d F Y, H:i'); ?> WIB
        </div>
    </div>

    <!-- DAFTAR MAHASISWA PER DOSEN PEMBIMBING -->
    <?php if (empty($laporan_dosen)): ?>
        <div class="text-center py-5 fst-italic">Tidak ada data pembagian bimbingan sesuai filter.</div>
    <?php else: ?>
        <?php foreach ($laporan_dosen as $dosen): 
            $mhs_list = $mhs_by_dosen[$dosen['id']] ?? [];
            $bimbingan_count = count($mhs_list);
            $kuota = (int)$dosen['kuota_bimbingan'];
            $sisa  = $kuota - $bimbingan_count;
        ?>
            <div class="mb-4">
                <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom border-dark flex-wrap">
                    <div>
                        <span style="font-size: 11pt; font-weight: bold;">
                            Dosen Pembimbing: <?= htmlspecialchars($dosen['nama_dosen']); ?>
                        </span>
                        <span style="font-size: 10pt; margin-left: 10px;">
                            (NIDN: <?= htmlspecialchars($dosen['nidn']); ?> • Email: <?= htmlspecialchars($dosen['email']); ?>)
                        </span>
                    </div>
                    <div class="text-end" style="font-size: 10pt;">
                        Alokasi: <strong><?= $bimbingan_count; ?></strong> / <?= $kuota; ?> Mahasiswa
                        <span>(<?= ($sisa > 0) ? "Sisa {$sisa}" : 'Kuota Penuh'; ?>)</span>
                    </div>
                </div>

                <?php if (empty($mhs_list)): ?>
                    <div class="border p-2 mb-3 fst-italic" style="border: 1px solid #000 !important; font-size: 9.5pt;">
                        Belum ada mahasiswa yang dialokasikan ke dosen pembimbing ini.
                    </div>
                <?php else: ?>
                    <div class="table-responsive mb-3">
                        <table class="table table-academic mb-0">
                            <thead>
                                <tr>
                                    <th style="width: 35px;" class="text-center">No</th>
                                    <th style="width: 110px;" class="text-center">NIM</th>
                                    <th style="width: 200px;">Nama Mahasiswa</th>
                                    <th style="width: 80px;" class="text-center">Kelas</th>
                                    <th>Judul Penelitian Skripsi</th>
                                    <th style="width: 70px;" class="text-center">Progres</th>
                                    <th style="width: 110px;" class="text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $no = 1;
                                foreach ($mhs_list as $m): 
                                ?>
                                    <tr>
                                        <td class="text-center"><?= $no++; ?></td>
                                        <td class="text-center"><?= htmlspecialchars($m['nim']); ?></td>
                                        <td><?= htmlspecialchars($m['nama_lengkap']); ?></td>
                                        <td class="text-center"><?= htmlspecialchars($m['kelas'] ?? '-'); ?></td>
                                        <td><?= htmlspecialchars($m['judul_skripsi']); ?></td>
                                        <td class="text-center">
                                            <?= (int)$m['nilai_terakhir']; ?>%
                                        </td>
                                        <td class="text-center">
                                            <?php if ($m['status_kelulusan'] === 'lulus'): ?>
                                                <span class="fw-bold">Lulus</span>
                                            <?php elseif ($m['status_kelulusan'] === 'siap_sidang'): ?>
                                                <span class="fw-bold">Siap Sidang</span>
                                            <?php else: ?>
                                                <span>Bimbingan</span>
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

    <!-- Bagian Mahasiswa Belum Diplot (Hanya jika melihat semua dosen) -->
    <?php if (!empty($mhs_unassigned)): ?>
        <div class="page-break mt-4">
            <div class="border-bottom border-dark pb-1 mb-2">
                <h6 class="fw-bold mb-0 text-dark" style="font-size: 11pt;">
                    Lampiran: Daftar Mahasiswa Belum Memiliki Dosen Pembimbing (<?= count($mhs_unassigned); ?> Mahasiswa)
                </h6>
            </div>
            <div class="table-responsive">
                <table class="table table-academic mb-0">
                    <thead>
                        <tr>
                            <th style="width: 35px;" class="text-center">No</th>
                            <th style="width: 110px;" class="text-center">NIM</th>
                            <th style="width: 200px;">Nama Mahasiswa</th>
                            <th style="width: 80px;" class="text-center">Kelas</th>
                            <th>Rencana Judul Skripsi</th>
                            <th style="width: 110px;" class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $no = 1;
                        foreach ($mhs_unassigned as $mu): 
                        ?>
                            <tr>
                                <td class="text-center"><?= $no++; ?></td>
                                <td class="text-center"><?= htmlspecialchars($mu['nim']); ?></td>
                                <td><?= htmlspecialchars($mu['nama_lengkap']); ?></td>
                                <td class="text-center"><?= htmlspecialchars($mu['kelas'] ?? '-'); ?></td>
                                <td><?= htmlspecialchars($mu['judul_skripsi']); ?></td>
                                <td class="text-center">Belum Diplot</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- LEMBAR PENGESAHAN RESMI AKADEMIK -->
    <div class="mt-5 pt-3" style="page-break-inside: avoid; font-size: 10.5pt; color: #000000;">
        <div class="row text-center">
            <div class="col-6">
                <p class="mb-1">Mengetahui,</p>
                <p class="fw-bold text-uppercase">Koordinator Skripsi / Tugas Akhir</p>
                <div style="height: 60px;"></div>
                <p class="fw-bold text-decoration-underline mb-0">( ..................................................... )</p>
                <p>NIP. .................................................</p>
            </div>
            <div class="col-6">
                <p class="mb-1">Ditetapkan di: Jakarta, <?= date('d F Y'); ?></p>
                <p class="fw-bold text-uppercase">Ketua Program Studi</p>
                <div style="height: 60px;"></div>
                <p class="fw-bold text-decoration-underline mb-0">( ..................................................... )</p>
                <p>NIP. .................................................</p>
            </div>
        </div>
    </div>

</div>

<?php
require_once '../templates/footer.php';
?>
