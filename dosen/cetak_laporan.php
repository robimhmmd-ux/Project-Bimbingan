<?php
// dosen/cetak_laporan.php - Rekapan & Cetak Laporan Hasil Bimbingan Skripsi (Salsa)
$page_title   = "Rekapitulasi & Cetak Laporan Bimbingan";
$current_page = "laporan";

require_once __DIR__ . '/../config/database.php';

// Cek autentikasi dosen
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'dosen') {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Ambil data dosen aktif
$stmt_dosen = $pdo->prepare("SELECT * FROM dosen WHERE user_id = :uid LIMIT 1");
$stmt_dosen->execute([':uid' => $user_id]);
$dosen = $stmt_dosen->fetch();
$dosen_id = $dosen['id'] ?? 0;

// Ambil filter dari request
$filter_mhs = isset($_GET['mahasiswa_id']) ? (int)$_GET['mahasiswa_id'] : 0;
$filter_status = trim($_GET['status'] ?? 'semua');

// Ambil daftar mahasiswa bimbingan dosen ini untuk dropdown
$stmt_mhs_list = $pdo->prepare("SELECT id, nim, nama_lengkap, judul_skripsi, status_kelulusan FROM mahasiswa WHERE dosen_pembimbing_id = :did ORDER BY nama_lengkap ASC");
$stmt_mhs_list->execute([':did' => $dosen_id]);
$daftar_mahasiswa = $stmt_mhs_list->fetchAll();

// Susun Query Rekapan Bimbingan
$query = "
    SELECT pb.*, m.nim, m.nama_lengkap, m.judul_skripsi, m.status_kelulusan
    FROM pengajuan_bimbingan pb
    JOIN mahasiswa m ON pb.mahasiswa_id = m.id
    WHERE pb.dosen_id = :did
";
$params = [':did' => $dosen_id];

if ($filter_mhs > 0) {
    $query .= " AND pb.mahasiswa_id = :mid";
    $params[':mid'] = $filter_mhs;
}

if ($filter_status !== 'semua' && !empty($filter_status)) {
    $query .= " AND pb.status = :st";
    $params[':st'] = $filter_status;
}

$query .= " ORDER BY pb.tanggal_bimbingan DESC, pb.jam_bimbingan DESC";

$stmt_rekap = $pdo->prepare($query);
$stmt_rekap->execute($params);
$data_rekap = $stmt_rekap->fetchAll();

// Hitung Statistik
$total_sesi = count($data_rekap);
$total_selesai = 0;
$total_acc_sidang = 0;
$sum_progres = 0;

foreach ($data_rekap as $row) {
    if ($row['status'] === 'selesai' || $row['status'] === 'disetujui') $total_selesai++;
    $sum_progres += (int)$row['nilai_progres'];
}
$avg_progres = ($total_sesi > 0) ? round($sum_progres / $total_sesi, 1) : 0;

// Informasi mahasiswa terpilih jika filter aktif
$info_mhs_terpilih = null;
if ($filter_mhs > 0) {
    foreach ($daftar_mahasiswa as $m) {
        if ($m['id'] == $filter_mhs) {
            $info_mhs_terpilih = $m;
            break;
        }
    }
}

// Mode print preview otomatis jika parameter print=true
$auto_print = isset($_GET['print']) && $_GET['print'] === 'true';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Hasil Bimbingan Skripsi - <?= htmlspecialchars($dosen['nama_dosen'] ?? 'Dosen'); ?></title>
    <!-- Bootstrap 5 CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
* {
            box-shadow: none !important;
            text-shadow: none !important;
            font-family: 'Times New Roman', Times, 'Liberation Serif', serif;
        }

        body {
            background-color: #f1f5f9;
            color: #000000;
            font-family: 'Times New Roman', Times, 'Liberation Serif', serif;
            margin: 0;
            padding: 0;
        }

        .report-sheet {
            background: #ffffff;
border: 1px solid #000000;
            border-radius: 0;
            box-shadow: none !important;
            padding: 15mm 20mm;
            max-width: 210mm;
            margin: 25px auto 40px auto;
            color: #000000;
        }
        .report-sheet,
        .report-sheet table,
        .report-sheet th,
        .report-sheet td,
        .report-sheet p,
        .report-sheet h1,
        .report-sheet h2,
        .report-sheet h3,
        .report-sheet h4,
        .report-sheet h5,
        .report-sheet h6,
        .report-sheet span,
        .report-sheet div,
        .report-sheet small {
            font-family: 'Times New Roman', Times, serif !important;
        }

        .kop-surat {
            border-bottom: 3px double #000000;
            padding-bottom: 12px;
            margin-bottom: 20px;
            text-align: center;
            color: #000000;
        }

        .table-bordered {
            border: 1px solid #000000 !important;
            border-collapse: collapse !important;
            width: 100%;
        }

        .table-bordered th, .table-bordered td {
            border: 1px solid #000000 !important;
            color: #000000 !important;
            background-color: #ffffff !important;
            font-size: 10pt;
            vertical-align: middle;
            padding: 6px 8px;
        }

        .table-bordered thead th {
            border-bottom: 2px solid #000000 !important;
            background-color: #ffffff !important;
            color: #000000 !important;
            font-weight: bold;
            text-align: center;
        }

        @page {
            size: A4 portrait;
            margin: 15mm 15mm 15mm 15mm;
        }

        @media print {
            body {
background: #ffffff !important;
                margin: 0 !important;
                padding: 0 !important;
                color: #000000 !important;
                font-family: 'Times New Roman', Times, serif !important;
            }
            .no-print {
                display: none !important;
            }
            .report-sheet {
box-shadow: none !important;
                border: none !important;
                margin: 0 !important;
                padding: 0 !important;
                max-width: 100% !important;
                width: 100% !important;
                border-radius: 0;
                font-family: 'Times New Roman', Times, serif !important;
            }
            .table-bordered th, .table-bordered td {
                border-color: #000000 !important;
            }
            a {
                text-decoration: none;
                color: #000000;
            }
        }
    </style>
</head>
<body>

<!-- Panel Kontrol & Filter (Hanya tampil di layar, tidak tercetak) -->
<div class="container my-3 no-print">
    <div class="card border">
        <div class="card-body p-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="d-flex align-items-center gap-2">
                    <a href="index.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-left me-1"></i> Kembali ke Dashboard
                    </a>
                    <a href="mahasiswa_bimbingan.php" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-people-fill me-1"></i> Mahasiswa Bimbingan
                    </a>
                </div>

                <form action="cetak_laporan.php" method="GET" class="d-flex flex-wrap gap-2 align-items-center">
                    <select name="mahasiswa_id" class="form-select form-select-sm" style="min-width: 200px;">
                        <option value="0">-- Semua Mahasiswa Bimbingan --</option>
                        <?php foreach ($daftar_mahasiswa as $m): ?>
                            <option value="<?= $m['id']; ?>" <?= ($filter_mhs == $m['id']) ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($m['nim']); ?> - <?= htmlspecialchars($m['nama_lengkap']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="status" class="form-select form-select-sm">
                        <option value="semua" <?= ($filter_status === 'semua') ? 'selected' : ''; ?>>Semua Status</option>
                        <option value="disetujui" <?= ($filter_status === 'disetujui') ? 'selected' : ''; ?>>Disetujui</option>
                        <option value="selesai" <?= ($filter_status === 'selesai') ? 'selected' : ''; ?>>Selesai</option>
                        <option value="revisi" <?= ($filter_status === 'revisi') ? 'selected' : ''; ?>>Revisi</option>
                        <option value="menunggu" <?= ($filter_status === 'menunggu') ? 'selected' : ''; ?>>Menunggu</option>
                    </select>

                    <button type="submit" class="btn btn-secondary btn-sm">
                        <i class="bi bi-filter me-1"></i> Filter
                    </button>
                    
                    <button type="button" onclick="window.print()" class="btn btn-primary btn-sm fw-semibold">
                        <i class="bi bi-printer-fill me-1"></i> Cetak / Simpan PDF
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Lembar Laporan Resmi (Print-Ready) -->
<div class="container">
    <div class="report-sheet">
        <!-- Kop Surat Akademik -->
        <div class="kop-surat text-center position-relative">
            <h5 class="fw-bold mb-0 text-uppercase" style="letter-spacing: 0.5px; font-size: 13pt;">KEMENTERIAN PENDIDIKAN TINGGI, SAINS, DAN TEKNOLOGI</h5>
            <h4 class="fw-bold mb-1 text-uppercase" style="font-size: 15pt;">UNIVERSITAS TEKNOLOGI DAN ILMU KOMPUTER</h4>
            <h6 class="mb-1 text-uppercase fw-semibold" style="font-size: 11pt;">FAKULTAS ILMU KOMPUTER - PROGRAM STUDI TEKNIK INFORMATIKA</h6>
            <p class="mb-0" style="font-size: 9.5pt;">
                Jl. Pendidikan No. 45 Kampus Terpadu | Telp: (021) 1234-5678 | Email: akademik@univ.ac.id | Laman: www.univ.ac.id
            </p>
        </div>

        <div class="text-center mb-4">
            <h5 class="fw-bold text-uppercase text-decoration-underline mb-1" style="font-size: 13pt;">LEMBAR REKAPITULASI HASIL BIMBINGAN SKRIPSI</h5>
            <p class="mb-0" style="font-size: 10pt;">Dicetak pada: <?= date('d F Y, H:i'); ?> WIB</p>
        </div>

        <!-- Identitas Dosen & Mahasiswa -->
        <div class="row g-2 mb-4" style="font-size: 10.5pt; color: #000000;">
            <div class="col-6">
                <table class="table table-sm table-borderless mb-0">
                    <tr>
                        <td style="width: 150px;" class="fw-bold">Dosen Pembimbing</td>
                        <td>: <?= htmlspecialchars($dosen['nama_dosen'] ?? '-'); ?></td>
                    </tr>
                    <tr>
                        <td class="fw-bold">NIDN</td>
                        <td>: <?= htmlspecialchars($dosen['nidn'] ?? '-'); ?></td>
                    </tr>
                    <tr>
                        <td class="fw-bold">Email Dosen</td>
                        <td>: <?= htmlspecialchars($dosen['email'] ?? '-'); ?></td>
                    </tr>
                </table>
            </div>
            <div class="col-6">
                <table class="table table-sm table-borderless mb-0">
                    <?php if ($info_mhs_terpilih): ?>
                        <tr>
                            <td style="width: 150px;" class="fw-bold">Mahasiswa</td>
                            <td>: <?= htmlspecialchars($info_mhs_terpilih['nama_lengkap']); ?> (<?= htmlspecialchars($info_mhs_terpilih['nim']); ?>)</td>
                        </tr>
                        <tr>
                            <td class="fw-bold">Judul Skripsi</td>
                            <td>: <?= htmlspecialchars($info_mhs_terpilih['judul_skripsi']); ?></td>
                        </tr>
                        <tr>
                            <td class="fw-bold">Status Akademik</td>
                            <td>: <span class="text-uppercase fw-bold"><?= str_replace('_', ' ', $info_mhs_terpilih['status_kelulusan']); ?></span></td>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <td style="width: 150px;" class="fw-bold">Cakupan Data</td>
                            <td>: Seluruh Mahasiswa Bimbingan Aktif</td>
                        </tr>
                        <tr>
                            <td class="fw-bold">Total Sesi Bimbingan</td>
                            <td>: <?= $total_sesi; ?> Sesi Terdata</td>
                        </tr>
                        <tr>
                            <td class="fw-bold">Rata-rata Progres</td>
                            <td>: <?= $avg_progres; ?>%</td>
                        </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <!-- Tabel Rekapan Data -->
        <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle">
                <thead>
                    <tr>
                        <th style="width: 35px;">No</th>
                        <?php if (!$info_mhs_terpilih): ?>
                            <th>Nama Mahasiswa / NIM</th>
                        <?php endif; ?>
                        <th style="width: 95px;">Tanggal</th>
                        <th style="width: 65px;">Waktu</th>
                        <th>Bab Pembahasan</th>
                        <th>Catatan Revisi / Umpan Balik Dosen</th>
                        <th style="width: 60px;">Nilai</th>
                        <th style="width: 85px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($data_rekap)): ?>
                        <tr>
                            <td colspan="<?= (!$info_mhs_terpilih) ? '8' : '7'; ?>" class="text-center py-4 fst-italic">
                                Tidak ada data rekapitulasi bimbingan yang ditemukan.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($data_rekap as $idx => $r): ?>
                            <tr>
                                <td class="text-center"><?= $idx + 1; ?></td>
                                <?php if (!$info_mhs_terpilih): ?>
                                    <td>
                                        <div class="fw-bold"><?= htmlspecialchars($r['nama_lengkap']); ?></div>
                                        <div>NIM: <?= htmlspecialchars($r['nim']); ?></div>
                                    </td>
                                <?php endif; ?>
                                <td class="text-center"><?= date('d/m/Y', strtotime($r['tanggal_bimbingan'])); ?></td>
                                <td class="text-center"><?= substr($r['jam_bimbingan'], 0, 5); ?> WIB</td>
                                <td>
                                    <strong><?= htmlspecialchars($r['bab_pembahasan']); ?></strong>
                                </td>
                                <td>
                                    <?= !empty($r['catatan_dosen']) ? nl2br(htmlspecialchars($r['catatan_dosen'])) : '<span class="fst-italic">-</span>'; ?>
                                </td>
                                <td class="text-center fw-bold">
                                    <?= (int)$r['nilai_progres']; ?>%
                                </td>
                                <td class="text-center">
                                    <?php
                                    $s = $r['status'];
                                    if ($s === 'disetujui') echo '<span class="fw-bold">Disetujui</span>';
                                    elseif ($s === 'selesai') echo '<span class="fw-bold">Selesai</span>';
                                    elseif ($s === 'revisi') echo '<span>Revisi</span>';
                                    elseif ($s === 'ditolak') echo '<span>Ditolak</span>';
                                    else echo ucfirst($s);
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Kolom Pengesahan Tanda Tangan -->
        <div class="row mt-5 pt-3" style="font-size: 10.5pt; color: #000000; page-break-inside: avoid;">
            <div class="col-7">
                <div class="border p-2" style="border: 1px solid #000000 !important; font-size: 9.5pt;">
                    <p class="mb-1 fw-bold">Catatan Ketentuan Akademik:</p>
                    <ul class="ps-3 mb-0">
                        <li>Dokumen ini merupakan rekapan sah hasil proses konsultasi dan bimbingan skripsi mahasiswa.</li>
                        <li>Dapat dilampirkan bersama Kartu Bimbingan Mahasiswa saat pendaftaran sidang tugas akhir.</li>
                    </ul>
                </div>
            </div>
            <div class="col-5 text-end">
                <p class="mb-0">Ditetapkan di: Kampus</p>
                <p class="mb-5">Pada tanggal: <?= date('d F Y'); ?></p>
                
                <div style="margin-top: 50px;">
                    <p class="fw-bold text-decoration-underline mb-0"><?= htmlspecialchars($dosen['nama_dosen'] ?? 'Nama Dosen Pembimbing'); ?></p>
                    <p class="mb-0">NIDN: <?= htmlspecialchars($dosen['nidn'] ?? '-'); ?></p>
                    <p class="mb-0">Dosen Pembimbing Skripsi</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($auto_print): ?>
<script>
    window.addEventListener('load', function() {
        window.print();
    });
</script>
<?php endif; ?>
</body>
</html>
