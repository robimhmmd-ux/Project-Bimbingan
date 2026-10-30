<?php
// dosen/cetak_laporan.php - Rekapan & Cetak Laporan Hasil Bimbingan Skripsi (Salsa)
$page_title   = "Rekapitulasi & Cetak Laporan Bimbingan";
$current_page = "laporan";

require_once '../config/database.php';

// Cek autentikasi dosen
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
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
        body {
            background-color: #f8f9fa;
            color: #212529;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .report-sheet {
            background: #ffffff;
            box-shadow: 0 0 10px rgba(0,0,0,0.08);
            border-radius: 8px;
            padding: 40px;
            margin-top: 25px;
            margin-bottom: 40px;
        }
        .kop-surat {
            border-bottom: 3px double #000;
            padding-bottom: 15px;
            margin-bottom: 25px;
        }
        @media print {
            body {
                background-color: #ffffff;
                margin: 0;
                padding: 0;
            }
            .no-print {
                display: none !important;
            }
            .report-sheet {
                box-shadow: none;
                padding: 0;
                margin: 0;
                border-radius: 0;
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
    <div class="card shadow-sm border-0">
        <div class="card-body p-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="d-flex align-items-center gap-2">
                    <a href="index.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-left me-1"></i> Kembali ke Dashboard
                    </a>
                    <a href="riwayat_bimbingan.php" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-journal-text me-1"></i> Menu Riwayat & Nilai
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
            <h4 class="fw-bold mb-1 text-uppercase">PROGRAM STUDI TEKNIK INFORMATIKA</h4>
            <h5 class="fw-semibold mb-1 text-uppercase">FAKULTAS ILMU KOMPUTER & SAINS</h5>
            <p class="small mb-0 text-muted">Sistem Informasi Pengelolaan & Pemantauan Bimbingan Skripsi Terintegrasi</p>
        </div>

        <div class="text-center mb-4">
            <h5 class="fw-bold text-uppercase text-decoration-underline mb-1">LEMBAR REKAPITULASI HASIL BIMBINGAN SKRIPSI</h5>
            <p class="small text-muted mb-0">Dicetak pada: <?= date('d F Y, H:i'); ?> WIB</p>
        </div>

        <!-- Identitas Dosen & Mahasiswa -->
        <div class="row g-2 mb-4 small">
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
                <thead class="table-light text-center small fw-bold">
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
                        <th style="width: 80px;">Status</th>
                    </tr>
                </thead>
                <tbody class="small">
                    <?php if (empty($data_rekap)): ?>
                        <tr>
                            <td colspan="<?= (!$info_mhs_terpilih) ? '8' : '7'; ?>" class="text-center py-4 text-muted">
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
                                        <small class="text-muted"><?= htmlspecialchars($r['nim']); ?></small>
                                    </td>
                                <?php endif; ?>
                                <td class="text-center"><?= date('d/m/Y', strtotime($r['tanggal_bimbingan'])); ?></td>
                                <td class="text-center"><?= substr($r['jam_bimbingan'], 0, 5); ?></td>
                                <td>
                                    <span class="fw-semibold"><?= htmlspecialchars($r['bab_pembahasan']); ?></span>
                                </td>
                                <td>
                                    <?= !empty($r['catatan_dosen']) ? nl2br(htmlspecialchars($r['catatan_dosen'])) : '<span class="text-muted fst-italic">-</span>'; ?>
                                </td>
                                <td class="text-center fw-bold">
                                    <?= (int)$r['nilai_progres']; ?>%
                                </td>
                                <td class="text-center">
                                    <?php
                                    $s = $r['status'];
                                    if ($s === 'disetujui') echo 'Disetujui';
                                    elseif ($s === 'selesai') echo '<strong>Selesai</strong>';
                                    elseif ($s === 'revisi') echo 'Revisi';
                                    elseif ($s === 'ditolak') echo 'Ditolak';
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
        <div class="row mt-5 pt-3">
            <div class="col-7">
                <div class="small text-muted">
                    <p class="mb-1"><strong>Keterangan Catatan:</strong></p>
                    <ul class="ps-3 mb-0">
                        <li>Dokumen ini merupakan rekapan sah hasil proses bimbingan skripsi akademik.</li>
                        <li>Dapat dilampirkan bersama Kartu Bimbingan Mahasiswa saat pendaftaran sidang tugas akhir.</li>
                    </ul>
                </div>
            </div>
            <div class="col-5 text-end small">
                <p class="mb-0">Ditetapkan di: Kampus</p>
                <p class="mb-5">Pada tanggal: <?= date('d F Y'); ?></p>
                
                <div style="margin-top: 55px;">
                    <p class="fw-bold text-decoration-underline mb-0"><?= htmlspecialchars($dosen['nama_dosen'] ?? 'Nama Dosen Pembimbing'); ?></p>
                    <p class="mb-0">NIDN: <?= htmlspecialchars($dosen['nidn'] ?? '-'); ?></p>
                    <p class="text-muted">Dosen Pembimbing Skripsi</p>
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

