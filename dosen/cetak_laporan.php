<?php
// dosen/cetak_laporan.php - Rekapitulasi & Cetak Laporan Progres Bimbingan Skripsi (Salsa)
$page_title   = "Laporan Progres Bimbingan Skripsi";
$current_page = "laporan";

require_once '../config/database.php';
require_once '../config/session_helper.php';

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

// Ambil filter mahasiswa dari request
$filter_mhs = isset($_GET['mahasiswa_id']) ? (int)$_GET['mahasiswa_id'] : 0;

// Ambil seluruh daftar mahasiswa bimbingan dosen ini
$stmt_mhs_list = $pdo->prepare("
    SELECT m.*, u.username 
    FROM mahasiswa m 
    LEFT JOIN users u ON m.user_id = u.id
    WHERE m.dosen_pembimbing_id = :did 
    ORDER BY m.nama_lengkap ASC
");
$stmt_mhs_list->execute([':did' => $dosen_id]);
$daftar_mahasiswa = $stmt_mhs_list->fetchAll();

// Ambil rekap total sesi bimbingan sekaligus dalam 1 query hemat koneksi
$sesi_map = [];
try {
    $st_cnt_all = $pdo->prepare("SELECT mahasiswa_id, COUNT(*) AS total FROM pengajuan_bimbingan WHERE dosen_id = :did GROUP BY mahasiswa_id");
    $st_cnt_all->execute([':did' => $dosen_id]);
    $sesi_map = $st_cnt_all->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {}

// Olah data progres setiap mahasiswa
$list_progres_mhs = [];
$total_mhs = count($daftar_mahasiswa);
$sum_progres = 0;
$count_siap_sidang = 0;

foreach ($daftar_mahasiswa as $m) {
    $p_info = null;
    try {
        $p_info = get_progres_mahasiswa($pdo, $m['id']);
    } catch (Throwable $pe) {
        $p_info = null;
    }

    $total_sesi     = (int)($sesi_map[$m['id']] ?? 0);
    $progres_persen = $p_info['progres_persen'] ?? 0;
    $tahap_label    = $p_info['tahap_saat_ini']['label'] ?? 'Pengajuan & ACC Judul';
    $tahap_singkat  = $p_info['tahap_saat_ini']['singkat'] ?? 'ACC Judul';
    $judul_tampil   = !empty($p_info['judul_resmi']) ? $p_info['judul_resmi'] : (!empty($p_info['judul_diajukan']) ? $p_info['judul_diajukan'] : (!empty($m['judul_skripsi']) && $m['judul_skripsi'] !== 'Belum Ditentukan' ? $m['judul_skripsi'] : 'Belum Ditentukan'));

    if (($m['status_kelulusan'] ?? '') === 'siap_sidang' || ($m['status_kelulusan'] ?? '') === 'lulus') {
        $count_siap_sidang++;
    }
    $sum_progres += $progres_persen;

    $list_progres_mhs[] = [
        'id'               => $m['id'],
        'nim'              => $m['nim'],
        'nama_lengkap'     => $m['nama_lengkap'],
        'kelas'            => $m['kelas'] ?? 'TI',
        'judul_skripsi'    => $judul_tampil,
        'is_judul_acc'     => $p_info['is_judul_acc'] ?? false,
        'progres'          => $progres_persen,
        'tahap_label'      => $tahap_label,
        'tahap_singkat'    => $tahap_singkat,
        'tahapan_ui'       => $p_info['tahapan_ui'] ?? [],
        'status_kelulusan' => $m['status_kelulusan'] ?? 'proses_bimbingan',
        'total_sesi'       => $total_sesi
    ];
}

$avg_progres = ($total_mhs > 0) ? round($sum_progres / $total_mhs, 1) : 0;

// Jika filter mahasiswa tertentu aktif
$info_mhs_terpilih = null;
$riwayat_sesi_terpilih = [];
if ($filter_mhs > 0) {
    foreach ($list_progres_mhs as $pm) {
        if ($pm['id'] == $filter_mhs) {
            $info_mhs_terpilih = $pm;
            break;
        }
    }

    if ($info_mhs_terpilih) {
        $st_rwy = $pdo->prepare("
            SELECT * FROM pengajuan_bimbingan 
            WHERE mahasiswa_id = :mid AND dosen_id = :did 
            ORDER BY tanggal_bimbingan ASC, jam_bimbingan ASC
        ");
        $st_rwy->execute([':mid' => $filter_mhs, ':did' => $dosen_id]);
        $riwayat_sesi_terpilih = $st_rwy->fetchAll();
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
    <title>Laporan Progres Bimbingan Skripsi - <?= htmlspecialchars($dosen['nama_dosen'] ?? 'Dosen'); ?></title>
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
            text-align: center;
            font-weight: bold;
        }

        @media (max-width: 767.98px) {
            .report-sheet-container {
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch !important;
                padding-bottom: 25px;
            }
            .report-sheet {
                min-width: 680px !important;
                margin: 10px auto !important;
                padding: 10mm 12mm !important;
            }
            .no-print .card-body {
                padding: 0.85rem !important;
            }
            .no-print .d-flex.justify-content-between {
                flex-direction: column !important;
                align-items: stretch !important;
            }
            .no-print form {
                flex-direction: column !important;
                align-items: stretch !important;
            }
            .no-print select, .no-print button, .no-print a {
                width: 100% !important;
            }
        }

        @media print {
            body {
                background: none;
                margin: 0;
                padding: 0;
                color: #000000;
            }
            .no-print {
                display: none !important;
            }
            .report-sheet {
                border: none !important;
                padding: 0;
                margin: 0;
                max-width: 100%;
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
                    <select name="mahasiswa_id" class="form-select form-select-sm" style="min-width: 260px;">
                        <option value="0">-- Seluruh Mahasiswa (Laporan Progres) --</option>
                        <?php foreach ($daftar_mahasiswa as $m): ?>
                            <option value="<?= $m['id']; ?>" <?= ($filter_mhs == $m['id']) ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($m['nim']); ?> - <?= htmlspecialchars($m['nama_lengkap']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" class="btn btn-secondary btn-sm">
                        <i class="bi bi-filter me-1"></i> Tampilkan Laporan
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
<div class="container report-sheet-container">
    <div class="report-sheet">
        <!-- Kop Surat Akademik -->
        <div class="kop-surat text-center position-relative">
            <h4 class="fw-bold mb-1 text-uppercase" style="font-size: 14pt;">PROGRAM STUDI TEKNIK INFORMATIKA</h4>
            <h5 class="fw-bold mb-1 text-uppercase" style="font-size: 12pt;">FAKULTAS ILMU KOMPUTER & SAINS</h5>
            <p class="small mb-0" style="font-size: 9pt;">Sistem Informasi Pengelolaan & Pemantauan Bimbingan Skripsi Terintegrasi</p>
        </div>

        <div class="text-center mb-4">
            <h5 class="fw-bold text-uppercase text-decoration-underline mb-1" style="font-size: 12pt;">LEMBAR LAPORAN PROGRES BIMBINGAN SKRIPSI</h5>
            <p class="small mb-0" style="font-size: 9pt;">Dicetak pada: <?= date('d F Y, H:i'); ?> WIB</p>
        </div>

        <!-- Identitas Dosen & Ringkasan Laporan -->
        <div class="row g-2 mb-4" style="font-size: 10pt;">
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
                            <td class="fw-bold">Capaian Progres</td>
                            <td>: <strong><?= $info_mhs_terpilih['progres']; ?>% Selesai</strong> (<?= htmlspecialchars($info_mhs_terpilih['tahap_label']); ?>)</td>
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
                            <td class="fw-bold">Total Mahasiswa</td>
                            <td>: <?= $total_mhs; ?> Mahasiswa Terdata</td>
                        </tr>
                        <tr>
                            <td class="fw-bold">Rata-rata Progres</td>
                            <td>: <?= $avg_progres; ?>% (<?= $count_siap_sidang; ?> Siap Sidang / Lulus)</td>
                        </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <?php if ($info_mhs_terpilih): ?>
            <!-- ========================================================
                 TAMPILAN 1: LAPORAN PROGRES MAHASISWA INDIVIDUAL
                 ======================================================== -->
            
            <!-- Ringkasan Judul Skripsi Mahasiswa -->
            <div class="mb-3 p-3 border">
                <span class="small fw-bold d-block text-uppercase mb-1">Judul Skripsi Mahasiswa:</span>
                <h6 class="fw-bold mb-1" style="font-size: 11pt;">"<?= htmlspecialchars($info_mhs_terpilih['judul_skripsi']); ?>"</h6>
                <small>
                    Tahap Pengerjaan: <strong><?= htmlspecialchars($info_mhs_terpilih['tahap_label']); ?></strong> &bull; Total Sesi Bimbingan: <strong><?= $info_mhs_terpilih['total_sesi']; ?> Sesi</strong>
                </small>
            </div>

            <!-- Tabel Tahapan Alur Progres Skripsi -->
            <div class="mb-4">
                <h6 class="fw-bold mb-2" style="font-size: 10.5pt;">1. Capaian Alur Tahapan Skripsi (Pipeline Berurutan):</h6>
                <table class="table table-bordered table-sm align-middle">
                    <thead>
                        <tr>
                            <th style="width: 45px;">Tahap</th>
                            <th>Nama Tahapan Skripsi</th>
                            <th style="width: 110px;">Target Capaian</th>
                            <th style="width: 160px;">Status Pengesahan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($info_mhs_terpilih['tahapan_ui'] as $lvl => $t): ?>
                            <tr>
                                <td class="text-center fw-bold"><?= $lvl; ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($t['label']); ?></strong>
                                    <small class="d-block text-muted"><?= htmlspecialchars($t['deskripsi'] ?? ''); ?></small>
                                </td>
                                <td class="text-center fw-bold"><?= (int)$t['progres']; ?>%</td>
                                <td class="text-center">
                                    <?php if ($t['status_ui'] === 'selesai'): ?>
                                        <span class="fw-bold">&#10003; Telah Di-ACC</span>
                                    <?php elseif ($t['status_ui'] === 'aktif'): ?>
                                        <span class="fw-bold">&#9658; Sedang Dikerjakan</span>
                                    <?php else: ?>
                                        <span>Terkunci</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Tabel Riwayat Sesi Bimbingan Mahasiswa -->
            <div class="mb-4">
                <h6 class="fw-bold mb-2" style="font-size: 10.5pt;">2. Riwayat Pertemuan & Catatan Evaluasi Dosen:</h6>
                <table class="table table-bordered table-sm align-middle">
                    <thead>
                        <tr>
                            <th style="width: 35px;">No</th>
                            <th style="width: 95px;">Tanggal</th>
                            <th style="width: 65px;">Waktu</th>
                            <th>Bagian / Bab Pembahasan</th>
                            <th>Catatan Arahan & Koreksi Dosen Pembimbing</th>
                            <th style="width: 65px;">Progres</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($riwayat_sesi_terpilih)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-3 fst-italic">
                                    Belum ada sesi bimbingan atau catatan evaluasi yang tercatat untuk mahasiswa ini.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($riwayat_sesi_terpilih as $idx => $r): ?>
                                <tr>
                                    <td class="text-center"><?= $idx + 1; ?></td>
                                    <td class="text-center"><?= date('d/m/Y', strtotime($r['tanggal_bimbingan'])); ?></td>
                                    <td class="text-center"><?= substr($r['jam_bimbingan'], 0, 5); ?> WIB</td>
                                    <td>
                                        <strong><?= htmlspecialchars($r['bab_pembahasan']); ?></strong>
                                        <?php if (!empty($r['catatan_mahasiswa'])): ?>
                                            <small class="d-block mt-1 fst-italic">"<?= htmlspecialchars($r['catatan_mahasiswa']); ?>"</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= !empty($r['catatan_dosen']) ? nl2br(htmlspecialchars($r['catatan_dosen'])) : '<span class="fst-italic">-</span>'; ?>
                                    </td>
                                    <td class="text-center fw-bold">
                                        <?= (int)$r['nilai_progres']; ?>%
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>
            <!-- ========================================================
                 TAMPILAN 2: LAPORAN PROGRES SELURUH MAHASISWA BIMBINGAN
                 ======================================================== -->
            <div class="mb-4">
                <table class="table table-bordered table-sm align-middle">
                    <thead>
                        <tr>
                            <th style="width: 35px;">No</th>
                            <th style="width: 95px;">NIM</th>
                            <th>Nama Mahasiswa</th>
                            <th>Judul Skripsi</th>
                            <th style="width: 150px;">Tahap Saat Ini</th>
                            <th style="width: 75px;">Progres</th>
                            <th style="width: 110px;">Status</th>
                            <th style="width: 55px;">Sesi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($list_progres_mhs)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 fst-italic">
                                    Belum ada mahasiswa bimbingan yang terdaftar pada sistem pembimbing.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($list_progres_mhs as $idx => $pm): ?>
                                <tr>
                                    <td class="text-center"><?= $idx + 1; ?></td>
                                    <td class="text-center fw-semibold"><?= htmlspecialchars($pm['nim']); ?></td>
                                    <td>
                                        <div class="fw-bold"><?= htmlspecialchars($pm['nama_lengkap']); ?></div>
                                        <small class="text-muted">Kelas: <?= htmlspecialchars($pm['kelas']); ?></small>
                                    </td>
                                    <td>
                                        <?php if (!empty($pm['judul_skripsi']) && $pm['judul_skripsi'] !== 'Belum Ditentukan'): ?>
                                            <?= htmlspecialchars($pm['judul_skripsi']); ?>
                                            <?php if (!$pm['is_judul_acc']): ?>
                                                <small class="d-block fst-italic">(Menunggu ACC Judul)</small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="fst-italic text-muted">Belum Ditentukan</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center fw-semibold">
                                        <?= htmlspecialchars($pm['tahap_label']); ?>
                                    </td>
                                    <td class="text-center fw-bold">
                                        <?= (int)$pm['progres']; ?>%
                                    </td>
                                    <td class="text-center">
                                        <?php
                                        $stk = $pm['status_kelulusan'];
                                        if ($stk === 'siap_sidang') echo 'Siap Sidang';
                                        elseif ($stk === 'lulus') echo '<strong>Lulus</strong>';
                                        else echo 'Proses';
                                        ?>
                                    </td>
                                    <td class="text-center fw-semibold"><?= $pm['total_sesi']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- Kolom Pengesahan Tanda Tangan Resmi -->
        <div class="row mt-5 pt-3">
            <div class="col-7">
                <div class="small" style="font-size: 9pt;">
                    <p class="mb-1"><strong>Keterangan Catatan:</strong></p>
                    <ul class="ps-3 mb-0">
                        <li>Dokumen ini merupakan laporan resmi pemantauan progres bimbingan tugas akhir skripsi akademik.</li>
                        <li>Dapat dilampirkan bersama Kartu Kendali Bimbingan Mahasiswa saat pendaftaran sidang sarjana.</li>
                    </ul>
                </div>
            </div>
            <div class="col-5 text-end small" style="font-size: 10pt;">
                <p class="mb-0">Ditetapkan di: Kampus</p>
                <p class="mb-5">Pada tanggal: <?= date('d F Y'); ?></p>
                
                <div style="margin-top: 55px;">
                    <p class="fw-bold text-decoration-underline mb-0"><?= htmlspecialchars($dosen['nama_dosen'] ?? 'Nama Dosen Pembimbing'); ?></p>
                    <p class="mb-0">NIDN: <?= htmlspecialchars($dosen['nidn'] ?? '-'); ?></p>
                    <p>Dosen Pembimbing Skripsi</p>
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
