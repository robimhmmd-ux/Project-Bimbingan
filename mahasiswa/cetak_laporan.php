<?php
// mahasiswa/cetak_laporan.php - Format Cetak Resmi Kartu Kendali & Laporan Progres Skripsi
require_once __DIR__ . '/../config/database.php';

// Proteksi akses
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'mahasiswa') {
    header("Location: ../login");
    exit;
}

// Ambil data mahasiswa dan dosen pembimbing
$stmt_mhs = $pdo->prepare("
    SELECT m.*, d.nama_dosen, d.nidn, d.email AS email_dosen, d.no_whatsapp 
    FROM mahasiswa m 
    LEFT JOIN dosen d ON m.dosen_pembimbing_id = d.id 
    WHERE m.user_id = :uid LIMIT 1
");
$stmt_mhs->execute([':uid' => $_SESSION['user_id']]);
$mhs = $stmt_mhs->fetch();

if (!$mhs) {
    die("Data mahasiswa tidak ditemukan.");
}

$mhs_id = $mhs['id'];

// Ambil seluruh rekap bimbingan & logbook
$stmt_log = $pdo->prepare("
    SELECT p.id, p.tanggal_bimbingan, p.jam_bimbingan, p.bab_pembahasan, p.catatan_mahasiswa, 
           p.status, p.catatan_dosen, p.nilai_progres,
           lb.tanggal_acc, lb.catatan_hasil, lb.status_bab
    FROM pengajuan_bimbingan p
    LEFT JOIN logbook_bimbingan lb ON p.id = lb.bimbingan_id
    WHERE p.mahasiswa_id = :mid
    ORDER BY p.tanggal_bimbingan ASC, p.jam_bimbingan ASC
");
$stmt_log->execute([':mid' => $mhs_id]);
$bimbingan_list = $stmt_log->fetchAll();

// Hitung total sesi dan persetujuan
$total_sesi = count($bimbingan_list);
$total_acc  = 0;
foreach ($bimbingan_list as $b) {
    if (in_array($b['status'], ['selesai', 'disetujui']) || in_array($b['status_bab'], ['acc_judul', 'acc_bab', 'acc_sidang'])) {
        $total_acc++;
    }
}

// Ambil data jadwal sidang skripsi jika ada (UPDATE ROBI: tabel sidang_skripsi)
$stmt_sidang = $pdo->prepare("
    SELECT s.*, 
           d_pem.nama_dosen AS dospem,
           d_p1.nama_dosen  AS penguji_1,
           d_p2.nama_dosen  AS penguji_2,
           d_p3.nama_dosen  AS penguji_3
    FROM sidang_skripsi s
    LEFT JOIN dosen d_pem ON s.dosen_pembimbing_id = d_pem.id
    LEFT JOIN dosen d_p1  ON s.penguji_1_id = d_p1.id
    LEFT JOIN dosen d_p2  ON s.penguji_2_id = d_p2.id
    LEFT JOIN dosen d_p3  ON s.penguji_3_id = d_p3.id
    WHERE s.mahasiswa_id = :mid
    ORDER BY s.id DESC LIMIT 1
");
$stmt_sidang->execute([':mid' => $mhs_id]);
$info_sidang = $stmt_sidang->fetch();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <!-- Favicon Web -->
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <link rel="shortcut icon" href="../favicon.ico">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kartu Kendali Bimbingan Skripsi - <?= htmlspecialchars($mhs['nama_lengkap'] ?? 'Mahasiswa'); ?></title>
    <!-- Bootstrap 5 CSS via CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
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

        .paper-sheet {
            background: #ffffff;
            width: 210mm;
            min-height: 297mm;
margin: 25px auto;
            padding: 15mm 20mm;
            box-shadow: none !important;
            border: 1px solid #000000;
            border-radius: 0 !important;
            color: #000000;
            font-family: 'Times New Roman', Times, serif;
        }

        .paper-sheet,
        .paper-sheet table,
        .paper-sheet th,
        .paper-sheet td,
        .paper-sheet p,
        .paper-sheet h1,
        .paper-sheet h2,
        .paper-sheet h3,
        .paper-sheet h4,
        .paper-sheet h5,
        .paper-sheet h6,
        .paper-sheet span,
        .paper-sheet div,
        .paper-sheet small {
            font-family: 'Times New Roman', Times, serif !important;
        }

        .kop-surat {
            border-bottom: 3px double #000000;
            padding-bottom: 10px;
            margin-bottom: 20px;
            text-align: center;
            color: #000000;
        }

        .table-laporan {
            border: 1px solid #000000 !important;
            border-collapse: collapse !important;
            width: 100%;
        }

        .table-laporan th, .table-laporan td {
            border: 1px solid #000000 !important;
            padding: 6px 8px;
font-size: 10pt;
            font-family: 'Times New Roman', Times, serif !important;
            vertical-align: middle;
            background: #ffffff !important;
            color: #000000 !important;
        }

        .table-laporan thead th {
            background-color: #ffffff !important;
            color: #000000 !important;
            text-align: center;
            font-weight: bold;
            border-bottom: 2px solid #000000 !important;
        }

        .table-sidang {
            border: 1px solid #000000 !important;
            border-collapse: collapse !important;
            width: 100%;
        }

        .table-sidang th, .table-sidang td {
            border: 1px solid #000000 !important;
            padding: 5px 8px;
font-size: 9.5pt;
            font-family: 'Times New Roman', Times, serif !important;
            vertical-align: middle;
            background: #ffffff !important;
            color: #000000 !important;
        }

        .ttd-box {
            margin-top: 35px;
            page-break-inside: avoid;
            color: #000000;
        }

        @page {
            size: A4 portrait;
            margin: 15mm 15mm 15mm 15mm;
        }

        @media (max-width: 767.98px) {
            .paper-sheet-container {
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch !important;
                padding: 10px 8px 30px !important;
            }
            .paper-sheet {
                min-width: 680px !important;
                width: 100% !important;
                max-width: none !important;
                margin: 10px auto !important;
                padding: 10mm 12mm !important;
            }
            .no-print .container {
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 0.75rem;
            }
            .no-print .container > div {
                display: flex;
                justify-content: space-between;
                align-items: center;
                width: 100%;
                gap: 0.5rem;
            }
            .no-print button, .no-print a {
                flex: 1 1 auto;
                text-align: center;
                justify-content: center;
            }
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
            .paper-sheet {
                box-shadow: none !important;
                border: none !important;
                margin: 0 !important;
                width: 100% !important;
                padding: 0 !important;
            }
        }
    </style>
</head>
<body>

<!-- Panel Kontrol Tombol Cetak (Tidak ikut tercetak) -->
<div class="no-print bg-dark text-white py-3 sticky-top">
    <div class="container d-flex justify-content-between align-items-center">
        <div>
            <i class="bi bi-file-earmark-pdf-fill text-danger fs-4 me-2"></i>
            <span class="fw-bold">Pratinjau Dokumen Cetak / Export PDF</span>
            <span class="border px-2 py-1 ms-2" style="font-size: 9pt;">Ukuran A4</span>
        </div>
        <div>
            <button onclick="window.print()" class="btn btn-primary btn-sm px-3 me-2">
                <i class="bi bi-printer-fill me-1"></i>Cetak Sekarang (Print / PDF)
            </button>
            <a href="logbook.php" class="btn btn-outline-light btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Kembali
            </a>
        </div>
    </div>
</div>

<div class="paper-sheet-container">
<div class="paper-sheet">
    <!-- Kop Surat Kampus -->
    <div class="kop-surat text-center">
<h5 class="fw-bold mb-0 text-uppercase" style="letter-spacing: 0.5px; font-size: 13pt;">KEMENTERIAN PENDIDIKAN TINGGI, SAINS, DAN TEKNOLOGI</h5>
        <h4 class="fw-bold mb-1 text-uppercase" style="font-size: 15pt;">UNIVERSITAS TEKNOLOGI DAN ILMU KOMPUTER</h4>
        <h6 class="mb-1 text-uppercase fw-semibold" style="font-size: 11pt;">FAKULTAS ILMU KOMPUTER - PROGRAM STUDI TEKNIK INFORMATIKA</h6>
        <p class="mb-0" style="font-size: 9.5pt;">
            Jl. Pendidikan No. 45 Kampus Terpadu | Telp: (021) 1234-5678 | Email: akademik@univ.ac.id | Laman: www.univ.ac.id
        </p>
    </div>

    <!-- Judul Dokumen -->
    <div class="text-center my-3">
        <h5 class="fw-bold text-uppercase mb-1" style="text-decoration: underline; font-size: 13pt;">KARTU KENDALI & LAPORAN PROGRES BIMBINGAN SKRIPSI</h5>
        <p class="mb-0" style="font-size: 10.5pt;">Tahun Akademik <?= date('Y'); ?> / <?= date('Y') + 1; ?></p>
    </div>

    <!-- Identitas Mahasiswa & Dosen -->
<table class="table table-borderless table-sm mb-3" style="font-size: 10.5pt; color: #000000; font-family: 'Times New Roman', Times, serif;">
        <tbody>
            <tr>
                <td style="width: 18%;"><strong>Nama Mahasiswa</strong></td>
                <td style="width: 2%;">:</td>
                <td style="width: 40%;"><?= htmlspecialchars($mhs['nama_lengkap']); ?></td>
                <td style="width: 18%;"><strong>Dosen Pembimbing</strong></td>
                <td style="width: 2%;">:</td>
                <td style="width: 20%;"><?= htmlspecialchars($mhs['nama_dosen'] ?? 'Belum Diplot'); ?></td>
            </tr>
            <tr>
                <td><strong>NIM</strong></td>
                <td>:</td>
                <td><?= htmlspecialchars($mhs['nim']); ?></td>
                <td><strong>NIDN Pembimbing</strong></td>
                <td>:</td>
                <td><?= htmlspecialchars($mhs['nidn'] ?? '-'); ?></td>
            </tr>
            <tr>
                <td><strong>Kelas</strong></td>
                <td>:</td>
                <td><?= htmlspecialchars($mhs['kelas'] ?? 'TI-1A-2025'); ?></td>
                <td><strong>Status SK</strong></td>
                <td>:</td>
                <td><?= ucfirst(str_replace('_', ' ', $mhs['status_pembimbing'] ?? 'Belum Diplot')); ?></td>
            </tr>
            <tr>
                <td><strong>Program Studi</strong></td>
                <td>:</td>
                <td>Teknik Informatika (S1)</td>
                <td><strong>Status Skripsi</strong></td>
                <td>:</td>
                <td class="text-capitalize"><?= str_replace('_', ' ', htmlspecialchars($mhs['status_kelulusan'] ?? 'Proses Bimbingan')); ?></td>
            </tr>
            <tr>
                <td class="align-top"><strong>Judul Skripsi</strong></td>
                <td class="align-top">:</td>
                <td colspan="4" class="fw-semibold fst-italic">
                    "<?= htmlspecialchars($mhs['judul_skripsi'] ?? 'Belum Ditentukan'); ?>"
                </td>
            </tr>
        </tbody>
    </table>

    <!-- ============================================================
         INFORMASI JADWAL SIDANG SKRIPSI (JIKA ADA - UPDATE ROBI)
    ============================================================ -->
    <?php if ($info_sidang): ?>
<div class="mb-3 p-2 border" style="border: 1px solid #000000 !important; background: transparent; font-size: 10pt; color: #000000; font-family: 'Times New Roman', Times, serif;">
            <div class="fw-bold text-uppercase border-bottom pb-1 mb-2 text-dark" style="border-bottom: 1px solid #000000 !important;">
                Penetapan Dewan Penguji & Jadwal Ujian Sidang Skripsi
            </div>
            <div class="row g-2">
                <div class="col-6">
                    <table class="table-borderless table-sm w-100" style="font-size: 9.5pt; color: #000000;">
                        <tr>
                            <td style="width: 35%;"><strong>Waktu Sidang</strong></td>
                            <td style="width: 5%;">:</td>
                            <td><?= !empty($info_sidang['tanggal_sidang']) ? date('d/m/Y', strtotime($info_sidang['tanggal_sidang'])) : 'Belum Ditentukan'; ?> (<?= substr($info_sidang['jam_sidang'] ?? '00:00', 0, 5); ?> WIB)</td>
                        </tr>
                        <tr>
                            <td><strong>Ruangan</strong></td>
                            <td>:</td>
                            <td><?= htmlspecialchars($info_sidang['ruangan'] ?? 'Ruang Sidang'); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Status Sidang</strong></td>
                            <td>:</td>
                            <td class="fw-bold text-uppercase"><?= htmlspecialchars($info_sidang['status_sidang'] ?? 'Pengajuan'); ?></td>
                        </tr>
                    </table>
                </div>
                <div class="col-6">
                    <table class="table-borderless table-sm w-100" style="font-size: 9.5pt; color: #000000;">
                        <tr>
                            <td style="width: 40%;"><strong>Ketua Penguji (1)</strong></td>
                            <td style="width: 5%;">:</td>
                            <td><?= htmlspecialchars($info_sidang['penguji_1'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Dosen Penguji 2</strong></td>
                            <td>:</td>
                            <td><?= htmlspecialchars($info_sidang['penguji_2'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Sekretaris (Penguji 3)</strong></td>
                            <td>:</td>
                            <td><?= htmlspecialchars($info_sidang['penguji_3'] ?? '-'); ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Tabel Rekapitulasi Catatan Bimbingan -->
    <table class="table table-laporan w-100 mb-3">
        <thead>
            <tr>
                <th style="width: 30px;">No</th>
                <th style="width: 85px;">Tanggal</th>
                <th style="width: 140px;">Bab / Materi Pembahasan</th>
                <th>Uraian Masukan & Catatan Pembimbing</th>
                <th style="width: 50px;">Nilai</th>
                <th style="width: 75px;">Status</th>
                <th style="width: 85px;">Paraf</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($bimbingan_list)): ?>
                <tr>
                    <td colspan="7" class="text-center py-4 fst-italic">
                        Belum ada catatan logbook bimbingan resmi yang terdaftar.
                    </td>
                </tr>
            <?php else: ?>
                <?php $no = 1; foreach ($bimbingan_list as $item): ?>
                    <tr>
                        <td class="text-center"><?= $no++; ?></td>
                        <td class="text-center">
                            <?= date('d/m/Y', strtotime($item['tanggal_bimbingan'])); ?><br>
                            <small><?= substr($item['jam_bimbingan'], 0, 5); ?> WIB</small>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars($item['bab_pembahasan']); ?></strong>
                        </td>
                        <td>
                            <?php if (!empty($item['catatan_hasil'])): ?>
                                <?= nl2br(htmlspecialchars($item['catatan_hasil'])); ?>
                            <?php elseif (!empty($item['catatan_dosen'])): ?>
                                <?= nl2br(htmlspecialchars($item['catatan_dosen'])); ?>
                            <?php else: ?>
                                <span class="fst-italic">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?= $item['nilai_progres'] > 0 ? $item['nilai_progres'] : '-'; ?>
                        </td>
                        <td class="text-center">
                            <?php
                            $st_bab = $item['status_bab'] ?? '';
                            if ($st_bab === 'acc_sidang' || $st_bab === 'acc_bab' || $st_bab === 'acc_judul' || in_array($item['status'], ['disetujui', 'selesai'])) {
                                echo '<span class="fw-bold">' . ($st_bab === 'acc_judul' ? 'ACC Judul' : 'ACC') . '</span>';
                            } elseif ($st_bab === 'revisi' || $item['status'] === 'revisi') {
                                echo '<span>Revisi</span>';
                            } else {
                                echo '<span>Proses</span>';
                            }
                            ?>
                        </td>
                        <td class="text-center align-middle">
                            <?php if (in_array($item['status'], ['selesai', 'disetujui']) || in_array($st_bab, ['acc_judul', 'acc_bab', 'acc_sidang'])): ?>
                                <span class="fw-bold">Tervalidasi</span>
                            <?php else: ?>
                                <span>-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Ringkasan Kelayakan -->
<div class="border p-2 mb-4" style="border: 1px solid #000000 !important; background: transparent; font-size: 9.5pt; color: #000000; font-family: 'Times New Roman', Times, serif;">
        <strong>Catatan Ketentuan Akademik:</strong>
        <ul class="mb-0 ps-3">
            <li>Mahasiswa dinyatakan berhak mendaftar Ujian Sidang Skripsi apabila telah menyelesaikan minimal <strong>8 (delapan) kali bimbingan</strong> dengan status <strong>ACC Sidang</strong>.</li>
            <li>Total sesi konsultasi yang telah terlaksana saat ini: <strong><?= $total_sesi; ?> sesi</strong> (ACC Selesai: <strong><?= $total_acc; ?> sesi</strong>).</li>
        </ul>
    </div>

    <!-- Lembar Tanda Tangan Pengesahan -->
<div class="row ttd-box text-center" style="font-size: 10.5pt; color: #000000; font-family: 'Times New Roman', Times, serif;">
        <div class="col-4">
            <p class="mb-1">Mahasiswa Yang Bersangkutan,</p>
            <div style="height: 60px;"></div>
            <p class="fw-bold mb-0" style="text-decoration: underline;"><?= htmlspecialchars($mhs['nama_lengkap']); ?></p>
            <span>NIM. <?= htmlspecialchars($mhs['nim']); ?></span>
        </div>

        <div class="col-4">
            <p class="mb-1">Mengetahui,<br>Ketua Program Studi</p>
            <div style="height: 50px;"></div>
            <p class="fw-bold mb-0" style="text-decoration: underline;">Dr. Ir. Wahyudi, M.Kom.</p>
            <span>NIP. 19780512 200501 1 002</span>
        </div>

        <div class="col-4">
            <p class="mb-1"><?= date('d F Y'); ?><br>Dosen Pembimbing,</p>
            <div style="height: 50px;"></div>
            <p class="fw-bold mb-0" style="text-decoration: underline;"><?= htmlspecialchars($mhs['nama_dosen'] ?? '( Belum Diplot )'); ?></p>
            <span>NIDN. <?= htmlspecialchars($mhs['nidn'] ?? '-'); ?></span>
        </div>
    </div>
</div>
</div>

<script>
// Auto print jika ada parameter url ?auto_print=1
const urlParams = new URLSearchParams(window.location.search);
if (urlParams.get('auto_print') === '1') {
    window.print();
}
</script>

</body>
</html>
