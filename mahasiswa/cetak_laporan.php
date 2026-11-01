<?php
// mahasiswa/cetak_laporan.php - Format Cetak Resmi Kartu Kendali & Laporan Progres Skripsi
session_start();

// Proteksi akses
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'mahasiswa') {
    header("Location: ../login.php");
    exit;
}

require_once '../config/database.php';

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
    if (in_array($b['status'], ['selesai', 'disetujui']) || $b['status_bab'] === 'acc_bab' || $b['status_bab'] === 'acc_sidang') {
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kartu Kendali Bimbingan Skripsi - <?= htmlspecialchars($mhs['nama_lengkap'] ?? 'Mahasiswa'); ?></title>
    <!-- Bootstrap 5 CSS via CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background-color: #f1f5f9;
            color: #1e293b;
            font-family: 'Times New Roman', Times, serif;
        }

        .paper-sheet {
            background: #ffffff;
            width: 210mm;
            min-height: 297mm;
            margin: 20px auto;
            padding: 20mm 20mm;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            border-radius: 4px;
        }

        .kop-surat {
            border-bottom: 3px double #000;
            padding-bottom: 12px;
            margin-bottom: 20px;
        }

        .table-laporan th, .table-laporan td {
            border: 1px solid #334155 !important;
            padding: 6px 8px;
            font-size: 9pt;
            font-family: Arial, sans-serif;
            vertical-align: middle;
        }

        .table-laporan thead th {
            background-color: #f8fafc !important;
            text-align: center;
        }

        .table-sidang th, .table-sidang td {
            border: 1px solid #334155 !important;
            padding: 5px 8px;
            font-size: 9pt;
            font-family: Arial, sans-serif;
            vertical-align: middle;
        }

        .ttd-box {
            margin-top: 35px;
            page-break-inside: avoid;
        }

        @media print {
            body {
                background: none;
                margin: 0;
                padding: 0;
            }
            .no-print {
                display: none !important;
            }
            .paper-sheet {
                box-shadow: none;
                margin: 0;
                width: 100%;
                padding: 10mm 15mm;
            }
        }
    </style>
</head>
<body>

<!-- Panel Kontrol Tombol Cetak (Tidak ikut tercetak) -->
<div class="no-print bg-dark text-white py-3 sticky-top shadow-sm">
    <div class="container d-flex justify-content-between align-items-center">
        <div>
            <i class="bi bi-file-earmark-pdf-fill text-danger fs-4 me-2"></i>
            <span class="fw-bold">Pratinjau Dokumen Cetak / Export PDF</span>
            <span class="badge bg-secondary ms-2">Ukuran A4</span>
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

<div class="paper-sheet">
    <!-- Kop Surat Kampus -->
    <div class="kop-surat text-center">
        <h5 class="fw-bold mb-0 text-uppercase" style="letter-spacing: 1px;">KEMENTERIAN PENDIDIKAN TINGGI, SAINS, DAN TEKNOLOGI</h5>
        <h4 class="fw-bold mb-1 text-uppercase">UNIVERSITAS TEKNOLOGI DAN ILMU KOMPUTER</h4>
        <h6 class="mb-0 text-uppercase fw-semibold">FAKULTAS ILMU KOMPUTER - PROGRAM STUDI TEKNIK INFORMATIKA</h6>
        <p class="small mb-0 text-muted" style="font-family: Arial, sans-serif; font-size: 8.5pt;">
            Jl. Pendidikan No. 45 Kampus Terpadu | Telp: (021) 1234-5678 | Email: akademik@univ.ac.id | Laman: www.univ.ac.id
        </p>
    </div>

    <!-- Judul Dokumen -->
    <div class="text-center my-3">
        <h5 class="fw-bold text-uppercase mb-1" style="text-decoration: underline;">KARTU KENDALI & LAPORAN PROGRES BIMBINGAN SKRIPSI</h5>
        <p class="small text-muted mb-0">Tahun Akademik <?= date('Y'); ?> / <?= date('Y') + 1; ?></p>
    </div>

    <!-- Identitas Mahasiswa & Dosen -->
    <table class="table table-borderless table-sm mb-3" style="font-size: 10pt; font-family: Arial, sans-serif;">
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
        <div class="mb-3 p-2 border bg-light" style="font-family: Arial, sans-serif; font-size: 9pt;">
            <div class="fw-bold text-uppercase border-bottom pb-1 mb-2 text-dark">
                <i class="bi bi-mortarboard-fill me-1"></i>Penetapan Dewan Penguji & Jadwal Ujian Sidang Skripsi
            </div>
            <div class="row g-2">
                <div class="col-6">
                    <table class="table-borderless table-sm w-100" style="font-size: 8.5pt;">
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
                    <table class="table-borderless table-sm w-100" style="font-size: 8.5pt;">
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
                <th style="width: 80px;">Tanggal</th>
                <th style="width: 140px;">Bab / Materi Pembahasan</th>
                <th>Uraian Masukan & Catatan Pembimbing</th>
                <th style="width: 45px;">Nilai</th>
                <th style="width: 65px;">Status</th>
                <th style="width: 75px;">Paraf</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($bimbingan_list)): ?>
                <tr>
                    <td colspan="7" class="text-center py-4 text-muted fst-italic">
                        Belum ada catatan logbook bimbingan resmi yang terdaftar.
                    </td>
                </tr>
            <?php else: ?>
                <?php $no = 1; foreach ($bimbingan_list as $item): ?>
                    <tr>
                        <td class="text-center"><?= $no++; ?></td>
                        <td class="text-center">
                            <?= date('d/m/Y', strtotime($item['tanggal_bimbingan'])); ?><br>
                            <small class="text-muted"><?= substr($item['jam_bimbingan'], 0, 5); ?> WIB</small>
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
                                <span class="text-muted fst-italic">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?= $item['nilai_progres'] > 0 ? $item['nilai_progres'] : '-'; ?>
                        </td>
                        <td class="text-center">
                            <?php
                            $st_bab = $item['status_bab'] ?? '';
                            if ($st_bab === 'acc_sidang' || $st_bab === 'acc_bab' || in_array($item['status'], ['disetujui', 'selesai'])) {
                                echo '<span class="text-success fw-bold">ACC</span>';
                            } elseif ($st_bab === 'revisi' || $item['status'] === 'revisi') {
                                echo '<span class="text-warning fw-bold">Revisi</span>';
                            } else {
                                echo '<span class="text-muted">Proses</span>';
                            }
                            ?>
                        </td>
                        <td class="text-center align-middle">
                            <?php if (in_array($item['status'], ['selesai', 'disetujui']) || in_array($st_bab, ['acc_bab', 'acc_sidang'])): ?>
                                <span class="badge bg-success py-1 px-2" style="font-size: 8pt;">Tervalidasi</span>
                            <?php else: ?>
                                <span class="text-muted small">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Ringkasan Kelayakan -->
    <div class="border p-2 mb-4 small bg-light" style="font-family: Arial, sans-serif; font-size: 9pt;">
        <strong>Catatan Ketentuan Akademik:</strong>
        <ul class="mb-0 ps-3">
            <li>Mahasiswa dinyatakan berhak mendaftar Ujian Sidang Skripsi apabila telah menyelesaikan minimal <strong>8 (delapan) kali bimbingan</strong> dengan status <strong>ACC Sidang</strong>.</li>
            <li>Total sesi konsultasi yang telah terlaksana saat ini: <strong><?= $total_sesi; ?> sesi</strong> (ACC Selesai: <strong><?= $total_acc; ?> sesi</strong>).</li>
        </ul>
    </div>

    <!-- Lembar Tanda Tangan Pengesahan -->
    <div class="row ttd-box text-center" style="font-family: Arial, sans-serif; font-size: 10pt;">
        <div class="col-4">
            <p class="mb-1">Mahasiswa Yang Bersangkutan,</p>
            <div style="height: 60px;"></div>
            <p class="fw-bold mb-0" style="text-decoration: underline;"><?= htmlspecialchars($mhs['nama_lengkap']); ?></p>
            <span class="text-muted">NIM. <?= htmlspecialchars($mhs['nim']); ?></span>
        </div>

        <div class="col-4">
            <p class="mb-1">Mengetahui,<br>Ketua Program Studi</p>
            <div style="height: 50px;"></div>
            <p class="fw-bold mb-0" style="text-decoration: underline;">Dr. Ir. Wahyudi, M.Kom.</p>
            <span class="text-muted">NIP. 19780512 200501 1 002</span>
        </div>

        <div class="col-4">
            <p class="mb-1"><?= date('d F Y'); ?><br>Dosen Pembimbing,</p>
            <div style="height: 50px;"></div>
            <p class="fw-bold mb-0" style="text-decoration: underline;"><?= htmlspecialchars($mhs['nama_dosen'] ?? '( Belum Diplot )'); ?></p>
            <span class="text-muted">NIDN. <?= htmlspecialchars($mhs['nidn'] ?? '-'); ?></span>
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
