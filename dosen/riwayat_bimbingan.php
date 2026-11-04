<?php
// dosen/riwayat_bimbingan.php - Pemeriksaan & Evaluasi Naskah Skripsi Mahasiswa
require_once __DIR__ . '/../config/database.php';

// Validasi otentikasi role dosen
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'dosen') {
    header("Location: ../login");
    exit;
}

$user_id = $_SESSION['user_id'];
$stmt_dosen = $pdo->prepare("SELECT * FROM dosen WHERE user_id = :uid LIMIT 1");
$stmt_dosen->execute([':uid' => $user_id]);
$dosen = $stmt_dosen->fetch();
$dosen_id = $dosen['id'] ?? 0;

$success_msg = '';
$error_msg   = '';

// Proses Simpan Evaluasi (Alur Berurutan: ACC / Revisi Otomatis)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_evaluasi'])) {
    $id_pengajuan   = (int)($_POST['id_pengajuan'] ?? 0);
    $mahasiswa_id   = (int)($_POST['mahasiswa_id'] ?? 0);
    $keputusan_aksi = trim($_POST['keputusan_aksi'] ?? ''); // 'acc' atau 'revisi'
    $catatan_dosen  = trim($_POST['catatan_dosen'] ?? '');
    $judul_skripsi  = trim($_POST['judul_skripsi'] ?? '');

    // Validasi Catatan Dosen Wajib Diisi
    if (empty($catatan_dosen)) {
        $error_msg = "Catatan dan arahan resmi dosen wajib diisi untuk setiap hasil evaluasi (ACC maupun Revisi).";
    } elseif ($id_pengajuan <= 0 || $mahasiswa_id <= 0 || !in_array($keputusan_aksi, ['acc', 'revisi'])) {
        $error_msg = "Data evaluasi tidak valid. Silakan pilih keputusan ACC atau Revisi.";
    } else {
        // Ambil status progres & alur saat ini
        $info_progres = get_progres_mahasiswa($pdo, $mahasiswa_id);
        if (!$info_progres) {
            $error_msg = "Data mahasiswa tidak ditemukan.";
        } else {
            $current_level = $info_progres['current_level'];
            $tahap_current = $info_progres['tahap_saat_ini'];

            // Tahap 1 (Judul): Ambil judul dari pengajuan mahasiswa, bukan dari input dosen
            $judul_skripsi = '';
            if ($current_level === 1) {
                // Ambil judul yang diajukan mahasiswa dari catatan pengajuan terbaru
                $st_pj_judul = $pdo->prepare("
                    SELECT catatan_mahasiswa, file_draft, link_drive FROM pengajuan_bimbingan 
                    WHERE mahasiswa_id = :mid AND (bab_pembahasan LIKE '%Judul%') 
                    ORDER BY id DESC LIMIT 1
                ");
                $st_pj_judul->execute([':mid' => $mahasiswa_id]);
                $pj_judul = $st_pj_judul->fetch();
                
                // Judul bisa dari field mahasiswa.judul_skripsi (yang diisi mahasiswa via form) 
                $mhs_data = $pdo->prepare("SELECT judul_skripsi FROM mahasiswa WHERE id = :mid LIMIT 1");
                $mhs_data->execute([':mid' => $mahasiswa_id]);
                $mhs_row = $mhs_data->fetch();
                $judul_mhs = trim($mhs_row['judul_skripsi'] ?? '');
                
                if (!empty($judul_mhs) && !in_array(strtolower($judul_mhs), ['belum ditentukan', '-', 'null'])) {
                    $judul_skripsi = $judul_mhs;
                } elseif ($pj_judul && preg_match('/Usulan Judul[^:]*:(.+)/i', $pj_judul['catatan_mahasiswa'] ?? '', $mm)) {
                    $judul_skripsi = trim($mm[1]);
                }
                
                if ($keputusan_aksi === 'acc' && empty($judul_skripsi)) {
                    $error_msg = "Mahasiswa belum mengajukan judul skripsi. Tidak dapat melakukan ACC!";
                }
            }
            
            if (empty($error_msg)) {
                try {
                    $pdo->beginTransaction();

                    if ($keputusan_aksi === 'acc') {
                        // KEPUTUSAN: ACC TAHAP SAAT INI
                        if ($current_level === 1) {
                            // Tahap 1: ACC Judul Skripsi -> Progres naik ke 15%
                            $progres_baru   = 15;
                            $status_log     = 'acc_judul';
                            $status_sesi    = 'selesai';
                            $nama_bab_resmi = 'Pengajuan & ACC Judul Skripsi';

                            // Simpan judul resmi ke tabel mahasiswa
                            $st_j = $pdo->prepare("UPDATE mahasiswa SET judul_skripsi = :j WHERE id = :mid");
                            $st_j->execute([':j' => $judul_skripsi, ':mid' => $mahasiswa_id]);

                            $judul_notif = "Selamat! Judul Skripsi Anda Telah Di-ACC";
                            $pesan_notif = "Dosen pembimbing telah mengesahkan (ACC) judul skripsi Anda: '{$judul_skripsi}'. Silakan lanjutkan bimbingan Bab 1: Pendahuluan.";
                            $tipe_notif  = "pembimbing";

                        } elseif ($current_level >= 2 && $current_level <= 6) {
                            // Tahap 2 s.d 6: ACC Bab 1 s.d Bab 5 -> Progres naik otomatis
                            $progres_baru   = $info_progres['progres_jika_acc'];
                            $status_log     = 'acc_bab';
                            $status_sesi    = 'selesai';
                            $nama_bab_resmi = $tahap_current['nama_bab'];

                            $judul_notif = "Selamat! {$tahap_current['label']} Telah Di-ACC";
                            $pesan_notif = "Dosen pembimbing telah mengesahkan (ACC) {$tahap_current['label']}. Capaian progres skripsi Anda kini {$progres_baru}%. Silakan cek logbook bimbingan.";
                            $tipe_notif  = "sukses";

                        } else {
                            // Tahap 7: ACC Siap Sidang Skripsi -> Progres 100%
                            $progres_baru   = 100;
                            $status_log     = 'acc_sidang';
                            $status_sesi    = 'selesai';
                            $nama_bab_resmi = 'Naskah Lengkap & Pra-Sidang';

                            // Perbarui status mahasiswa menjadi siap sidang
                            $st_sidang = $pdo->prepare("UPDATE mahasiswa SET status_kelulusan = 'siap_sidang' WHERE id = :mid");
                            $st_sidang->execute([':mid' => $mahasiswa_id]);

                            $judul_notif = "Selamat! Anda Di-ACC Siap Sidang Skripsi";
                            $pesan_notif = "Seluruh naskah skripsi tuntas dan telah disahkan (ACC Sidang, 100%). Anda siap untuk menjadwalkan ujian sidang sarjana.";
                            $tipe_notif  = "sidang";
                        }

                        // 1. Update pengajuan_bimbingan
                        $stmt_up = $pdo->prepare("
                            UPDATE pengajuan_bimbingan 
                            SET nilai_progres = :nilai, 
                                catatan_dosen = :catatan, 
                                bab_pembahasan = :bab,
                                status = :status 
                            WHERE id = :id AND dosen_id = :did
                        ");
                        $stmt_up->execute([
                            ':nilai'   => $progres_baru,
                            ':catatan' => $catatan_dosen,
                            ':bab'     => $nama_bab_resmi,
                            ':status'  => $status_sesi,
                            ':id'      => $id_pengajuan,
                            ':did'     => $dosen_id
                        ]);

                        // 2. Insert ke logbook_bimbingan resmi
                        $stmt_log = $pdo->prepare("
                            INSERT INTO logbook_bimbingan (bimbingan_id, mahasiswa_id, tanggal_acc, catatan_hasil, status_bab) 
                            VALUES (:bid, :mid, CURDATE(), :catatan, :sbab)
                        ");
                        $stmt_log->execute([
                            ':bid'     => $id_pengajuan,
                            ':mid'     => $mahasiswa_id,
                            ':catatan' => $catatan_dosen,
                            ':sbab'    => $status_log
                        ]);

                        $label_keputusan = "ACC " . ($tahap_current['singkat'] ?? '');

                    } else {
                        // KEPUTUSAN: PERLU REVISI (Progres tidak naik)
                        $progres_tetap  = $info_progres['progres_persen'];
                        $nama_bab_sesi  = $tahap_current['nama_bab'] ?? 'Naskah Bimbingan';

                        // 1. Update pengajuan_bimbingan status revisi
                        $stmt_up = $pdo->prepare("
                            UPDATE pengajuan_bimbingan 
                            SET catatan_dosen = :catatan, 
                                status = 'revisi' 
                            WHERE id = :id AND dosen_id = :did
                        ");
                        $stmt_up->execute([
                            ':catatan' => $catatan_dosen,
                            ':id'      => $id_pengajuan,
                            ':did'     => $dosen_id
                        ]);

                        // 2. Insert catatan revisi ke logbook
                        $stmt_log = $pdo->prepare("
                            INSERT INTO logbook_bimbingan (bimbingan_id, mahasiswa_id, tanggal_acc, catatan_hasil, status_bab) 
                            VALUES (:bid, :mid, CURDATE(), :catatan, 'revisi')
                        ");
                        $stmt_log->execute([
                            ':bid'     => $id_pengajuan,
                            ':mid'     => $mahasiswa_id,
                            ':catatan' => "Catatan Revisi: " . $catatan_dosen,
                        ]);

                        $judul_notif     = "Catatan Revisi dari Pembimbing";
                        $pesan_notif     = "Dosen pembimbing memberikan catatan revisi pada " . ($tahap_current['label'] ?? 'naskah') . ": '{$catatan_dosen}'. Silakan perbaiki naskah Anda.";
                        $tipe_notif      = "revisi";
                        $label_keputusan = "PERLU REVISI";
                        $progres_baru    = $progres_tetap;
                    }

                    $pdo->commit();

                    // Kirim notifikasi ke mahasiswa
                    try {
                        $st_m_u = $pdo->prepare("SELECT user_id FROM mahasiswa WHERE id = :mid LIMIT 1");
                        $st_m_u->execute([':mid' => $mahasiswa_id]);
                        $m_uid = $st_m_u->fetchColumn();
                        if ($m_uid) {
                            $st_n = $pdo->prepare("INSERT INTO notifikasi (user_id, judul, pesan, tipe, link_tujuan) VALUES (:uid, :jdl, :psn, :tp, 'logbook.php')");
                            $st_n->execute([':uid' => $m_uid, ':jdl' => $judul_notif, ':psn' => $pesan_notif, ':tp' => $tipe_notif]);
                        }
                    } catch (Exception $ne) {}

                    $success_msg = "Evaluasi berhasil disimpan: Keputusan <strong>{$label_keputusan}</strong>. Progres skripsi mahasiswa kini <strong>{$progres_baru}%</strong>.";

                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $error_msg = "Gagal menyimpan evaluasi: " . $e->getMessage();
                }
            }
        }
    }
}

// Filter status di riwayat (default: semua aktif)
$filter = $_GET['filter'] ?? 'semua';
$where_status = "pb.status IN ('disetujui', 'selesai', 'revisi')";
if ($filter === 'disetujui') {
    $where_status = "pb.status = 'disetujui'";
} elseif ($filter === 'selesai') {
    $where_status = "pb.status = 'selesai'";
} elseif ($filter === 'revisi') {
    $where_status = "pb.status = 'revisi'";
}

$stmt_riwayat = $pdo->prepare("
    SELECT pb.*, m.nim, m.nama_lengkap, m.judul_skripsi, m.status_kelulusan, m.kelas
    FROM pengajuan_bimbingan pb
    JOIN mahasiswa m ON pb.mahasiswa_id = m.id
    WHERE pb.dosen_id = :did AND {$where_status}
    ORDER BY pb.tanggal_bimbingan DESC, pb.jam_bimbingan DESC
");
$stmt_riwayat->execute([':did' => $dosen_id]);
$riwayat_list = $stmt_riwayat->fetchAll();

$page_title   = "Riwayat & Evaluasi Bimbingan";
$current_page = "riwayat";

require_once '../templates/header.php';
require_once '../templates/sidebar_dosen.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h3 class="fw-bold mb-1 text-dark">
            <i class="bi bi-journal-check text-primary me-2"></i>Pemeriksaan, Evaluasi & Nilai Bimbingan
        </h3>
        <p class="text-muted mb-0 small">
            Tinjau draf naskah mahasiswa, berikan catatan koreksi terarah, dan sahkan progres bertahap (Mulai dari ACC Judul hingga Sidang).
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="cek_judul.php" class="btn btn-outline-primary btn-sm rounded shadow-sm">
            <i class="bi bi-search me-1"></i> Cek Kemiripan Judul
        </a>
        <a href="cetak_laporan.php" class="btn btn-outline-secondary btn-sm rounded shadow-sm">
            <i class="bi bi-printer me-1"></i> Cetak Rekapan
        </a>
    </div>
</div>

<?php if (!empty($success_msg)): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
        <i class="bi bi-check-circle-fill me-2 fs-5 align-middle text-success"></i>
        <?= $success_msg; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (!empty($error_msg)): ?>
    <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2 fs-5 align-middle text-danger"></i>
        <?= $error_msg; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Tabs Filter Status (Swipeable di HP) -->
<div class="filter-scroll-wrapper mb-3">
    <div class="d-flex gap-2 flex-nowrap align-items-center pb-1">
        <a href="riwayat_bimbingan.php?filter=semua" class="btn btn-sm <?= ($filter === 'semua') ? 'btn-primary' : 'btn-outline-secondary'; ?> rounded-pill px-3">
            Semua Sesi Aktif
        </a>
        <a href="riwayat_bimbingan.php?filter=disetujui" class="btn btn-sm <?= ($filter === 'disetujui') ? 'btn-primary' : 'btn-outline-secondary'; ?> rounded-pill px-3">
            Jadwal Disetujui
        </a>
        <a href="riwayat_bimbingan.php?filter=revisi" class="btn btn-sm <?= ($filter === 'revisi') ? 'btn-primary' : 'btn-outline-secondary'; ?> rounded-pill px-3">
            Perlu Revisi
        </a>
        <a href="riwayat_bimbingan.php?filter=selesai" class="btn btn-sm <?= ($filter === 'selesai') ? 'btn-primary' : 'btn-outline-secondary'; ?> rounded-pill px-3">
            Selesai / Ter-ACC
        </a>
    </div>
</div>

<!-- Card Tabel Riwayat -->
<div class="card border-0 shadow-sm rounded-4">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="fw-bold mb-0 text-dark">
            <i class="bi bi-card-checklist text-primary me-2"></i>Daftar Sesi Bimbingan Masuk & Naskah Mahasiswa
        </h5>
        <span class="badge bg-light text-dark border"><?= count($riwayat_list); ?> Sesi Terdata</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($riwayat_list)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-calendar-x fs-1 d-block mb-2 text-secondary"></i>
                Tidak ada riwayat bimbingan pada filter ini.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th class="text-center" style="width: 50px;">NO</th>
                            <th>MAHASISWA</th>
                            <th>JUDUL & BAB PEMBAHASAN</th>
                            <th>JADWAL / TANGGAL</th>
                            <th>BERKAS DRAFT</th>
                            <th style="width: 140px;">PROGRES ALUR</th>
                            <th style="width: 100px;">STATUS</th>
                            <th class="text-center" style="width: 130px;">AKSI & EVALUASI</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $no = 1; foreach ($riwayat_list as $row): ?>
                            <?php 
                            // Hitung status progres pipeline mahasiswa
                            $p_info = get_progres_mahasiswa($pdo, $row['mahasiswa_id']); 
                            $progres_persen = $p_info['progres_persen'];
                            $tahap_saat_ini = $p_info['tahap_saat_ini'];
                            ?>
                            <tr>
                                <td class="text-center text-muted fw-semibold"><?= $no++; ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="avatar-circle bg-primary-subtle text-primary fw-bold" style="width: 34px; height: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                            <?= strtoupper(substr($row['nama_lengkap'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <strong class="text-dark d-block"><?= htmlspecialchars($row['nama_lengkap']); ?></strong>
                                            <span class="text-muted" style="font-size: 0.75rem;">NIM: <?= htmlspecialchars($row['nim']); ?> (<?= htmlspecialchars($row['kelas'] ?? 'TI'); ?>)</span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle mb-1">
                                        <?= htmlspecialchars($row['bab_pembahasan']); ?>
                                    </span>
                                    <div class="text-dark small text-truncate" style="max-width: 260px;" title="<?= htmlspecialchars($row['judul_skripsi'] ?? ''); ?>">
                                        <i class="bi bi-bookmark text-muted me-1"></i>
                                        <?= (!empty($row['judul_skripsi']) && $row['judul_skripsi'] !== 'Belum Ditentukan') ? htmlspecialchars($row['judul_skripsi']) : '<span class="text-warning-emphasis fst-italic">Judul Belum Di-ACC</span>'; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= date('d M Y', strtotime($row['tanggal_bimbingan'])); ?></div>
                                    <small class="text-muted"><i class="bi bi-clock me-1"></i><?= substr($row['jam_bimbingan'], 0, 5); ?> WIB</small>
                                </td>
                                <td>
                                    <div class="d-flex flex-column gap-1">
                                        <?php if (!empty($row['file_draft'])): ?>
                                            <a href="../unduh_berkas.php?id=<?= $row['id']; ?>" target="_blank" class="btn btn-xs btn-outline-danger py-0 px-2" style="font-size: 0.75rem;" download>
                                                <i class="bi bi-file-earmark-pdf-fill me-1"></i>PDF
                                            </a>
                                        <?php endif; ?>
                                        <?php if (!empty($row['link_drive'])): ?>
                                            <a href="<?= htmlspecialchars($row['link_drive']); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-xs btn-outline-success py-0 px-2" style="font-size: 0.75rem;">
                                                <i class="bi bi-google me-1"></i>Drive
                                            </a>
                                        <?php endif; ?>
                                        <?php if (empty($row['file_draft']) && empty($row['link_drive'])): ?>
                                            <span class="text-muted" style="font-size: 0.75rem;">Tidak ada draf</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <small class="fw-bold text-dark"><?= $progres_persen; ?>%</small>
                                        <span class="badge bg-light text-primary border" style="font-size: 0.7rem;">
                                            <?= htmlspecialchars($tahap_saat_ini['singkat'] ?? 'Tuntas'); ?>
                                        </span>
                                    </div>
                                    <div class="progress" style="height: 6px;">
                                        <div class="progress-bar <?= ($progres_persen >= 75) ? 'bg-success' : (($progres_persen >= 30) ? 'bg-info' : 'bg-warning'); ?>" 
                                             role="progressbar" 
                                             style="width: <?= $progres_persen; ?>%;">
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                    $st = $row['status'];
                                    if ($st === 'disetujui') {
                                        echo '<span class="badge bg-success-subtle text-success border border-success-subtle"><span class="status-dot active"></span>Disetujui</span>';
                                    } elseif ($st === 'selesai') {
                                        echo '<span class="badge bg-primary">Selesai</span>';
                                    } elseif ($st === 'revisi') {
                                        echo '<span class="badge bg-warning text-dark"><span class="status-dot pending"></span>Perlu Revisi</span>';
                                    } else {
                                        echo '<span class="badge bg-secondary">' . htmlspecialchars(ucfirst($st)) . '</span>';
                                    }
                                    ?>
                                </td>
                                <td class="text-center">
                                    <button type="button" 
                                            class="btn btn-sm btn-primary rounded fw-semibold shadow-sm px-2" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#modalEvaluasi<?= $row['id']; ?>">
                                        <i class="bi bi-pencil-square me-1"></i>Evaluasi
                                    </button>

                                    <!-- MODAL EVALUASI & NILAI BERTAHAP OTOMATIS -->
                                    <div class="modal fade" id="modalEvaluasi<?= $row['id']; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-lg text-start">
                                            <div class="modal-content border-0 shadow rounded-4">
                                                <form action="riwayat_bimbingan.php?filter=<?= $filter; ?>" method="POST">
                                                    <input type="hidden" name="simpan_evaluasi" value="1">
                                                    <input type="hidden" name="id_pengajuan" value="<?= $row['id']; ?>">
                                                    <input type="hidden" name="mahasiswa_id" value="<?= $row['mahasiswa_id']; ?>">

                                                    <div class="modal-header bg-primary text-white rounded-top-4">
                                                        <h5 class="modal-title fw-bold">
                                                            <i class="bi bi-clipboard2-check me-2"></i>Evaluasi Naskah: <?= htmlspecialchars($row['nama_lengkap']); ?>
                                                        </h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>

                                                    <div class="modal-body p-4">
                                                        <!-- 1. Ringkasan Info Mahasiswa & Naskah -->
                                                        <div class="row g-2 mb-3 p-3 bg-light rounded-3 border">
                                                            <div class="col-md-6">
                                                                <small class="text-muted d-block">Mahasiswa / NIM</small>
                                                                <strong class="text-dark"><?= htmlspecialchars($row['nama_lengkap']); ?></strong> (<?= htmlspecialchars($row['nim']); ?>)
                                                            </div>
                                                            <div class="col-md-6">
                                                                <small class="text-muted d-block">Progres Skripsi Saat Ini</small>
                                                                <span class="badge bg-primary fs-6 px-3 py-1"><?= $progres_persen; ?>% Selesai</span>
                                                            </div>
                                                            <div class="col-12 mt-2">
                                                                <small class="text-muted d-block">Berkas Draft Mahasiswa</small>
                                                                <div class="d-flex flex-wrap gap-2 mt-1">
                                                                    <?php if (!empty($row['file_draft'])): ?>
                                                                        <a href="../unduh_berkas.php?id=<?= $row['id']; ?>" 
                                                                           class="btn btn-sm btn-outline-danger shadow-sm" 
                                                                           target="_blank" download>
                                                                            <i class="bi bi-file-earmark-pdf-fill me-1"></i>Unduh Berkas PDF
                                                                        </a>
                                                                    <?php endif; ?>
                                                                    <?php if (!empty($row['link_drive'])): ?>
                                                                        <a href="<?= htmlspecialchars($row['link_drive']); ?>" 
                                                                           class="btn btn-sm btn-outline-success shadow-sm" 
                                                                           target="_blank" rel="noopener noreferrer">
                                                                            <i class="bi bi-google me-1"></i>Buka Tautan Google Drive
                                                                        </a>
                                                                    <?php endif; ?>
                                                                    <?php if (empty($row['file_draft']) && empty($row['link_drive'])): ?>
                                                                        <span class="text-muted small fst-italic">Mahasiswa belum melampirkan berkas fisik/tautan Drive.</span>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <!-- 2. Pengesahan / Cek Judul Skripsi -->
                                                        <div class="mb-3 p-3 bg-white rounded-3 border shadow-sm">
                                                            <div class="d-flex justify-content-between align-items-center mb-1 flex-wrap gap-2">
                                                                <label class="form-label fw-bold small text-dark mb-0">
                                                                    <i class="bi bi-bookmark-star-fill text-warning me-1"></i>Judul Skripsi Mahasiswa:
                                                                </label>
                                                                <div class="d-flex gap-2 align-items-center">
                                                                    <?php if ($p_info['has_judul']): ?>
                                                                        <span class="badge bg-success-subtle text-success border border-success-subtle"><i class="bi bi-check-circle me-1"></i>Judul Resmi Terdaftar</span>
                                                                    <?php else: ?>
                                                                        <span class="badge bg-warning-subtle text-dark border border-warning-subtle"><i class="bi bi-exclamation-circle me-1"></i>Wajib ACC Judul Dahulu</span>
                                                                    <?php endif; ?>

                                                                    <!-- Tombol Cek Kemiripan Judul ke Halaman Baru -->
                                                                    <a href="cek_judul.php?q=<?= urlencode(!empty($row['judul_skripsi']) && $row['judul_skripsi'] !== 'Belum Ditentukan' ? $row['judul_skripsi'] : $row['catatan_mahasiswa']); ?>" 
                                                                       target="_blank" 
                                                                       class="btn btn-xs btn-outline-primary btn-sm py-1 px-2" 
                                                                       title="Buka Alat Deteksi Duplikasi Judul">
                                                                        <i class="bi bi-shield-check me-1"></i>Cek Kemiripan Judul
                                                                    </a>
                                                                </div>
                                                            </div>

                                                            <?php if ($p_info['current_level'] === 1): ?>
                                                                <!-- Tampilan READ-ONLY judul yang diajukan mahasiswa -->
                                                                <?php if ($p_info['has_submitted_judul'] || $p_info['has_judul']): ?>
                                                                    <div class="p-2 bg-info bg-opacity-10 rounded border border-info-subtle text-dark fw-semibold small mt-1">
                                                                        <i class="bi bi-send-check text-info me-1"></i>
                                                                        Judul Diajukan: "<?= htmlspecialchars($p_info['judul_diajukan'] ?: ($row['judul_skripsi'] !== 'Belum Ditentukan' ? $row['judul_skripsi'] : 'Lihat dokumen terlampir')); ?>"
                                                                    </div>
                                                                    <?php if ($p_info['pengajuan_judul']): ?>
                                                                        <?php if (!empty($p_info['pengajuan_judul']['catatan_mahasiswa'])): ?>
                                                                            <div class="mt-2 p-2 bg-light rounded small border">
                                                                                <i class="bi bi-chat-left-text text-muted me-1"></i><strong>Catatan Mahasiswa:</strong><br>
                                                                                <?= nl2br(htmlspecialchars($p_info['pengajuan_judul']['catatan_mahasiswa'])); ?>
                                                                            </div>
                                                                        <?php endif; ?>
                                                                        <div class="d-flex flex-wrap gap-2 mt-2">
                                                                            <?php if (!empty($p_info['pengajuan_judul']['file_draft'])): 
                                                                                $pdf_judul_rw_url = "../unduh_berkas.php?id=" . (int)$p_info['pengajuan_judul']['id'];
                                                                                $pdf_judul_rw_name = htmlspecialchars($p_info['judul_diajukan'] ?: 'Dokumen Usulan Judul Skripsi', ENT_QUOTES);
                                                                            ?>
                                                                                <button type="button" class="btn btn-sm btn-danger shadow-sm d-inline-flex align-items-center gap-1"
                                                                                        onclick="bukaPreviewPdf('<?= $pdf_judul_rw_url; ?>', '<?= $pdf_judul_rw_name; ?>')">
                                                                                    <i class="bi bi-eye-fill"></i> Preview Dokumen Judul
                                                                                </button>
                                                                                <a href="<?= $pdf_judul_rw_url; ?>&download=1" 
                                                                                   class="btn btn-sm btn-outline-danger shadow-sm d-inline-flex align-items-center gap-1" 
                                                                                   target="_blank" download>
                                                                                    <i class="bi bi-download"></i> Unduh PDF
                                                                                </a>
                                                                            <?php endif; ?>
                                                                            <?php if (!empty($p_info['pengajuan_judul']['link_drive'])): ?>
                                                                                <a href="<?= htmlspecialchars($p_info['pengajuan_judul']['link_drive']); ?>" 
                                                                                   class="btn btn-sm btn-outline-success shadow-sm" target="_blank" rel="noopener noreferrer">
                                                                                    <i class="bi bi-google me-1"></i>Buka Dokumen di Google Drive
                                                                                </a>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                    <small class="text-muted d-block mt-2">
                                                                        <i class="bi bi-info-circle me-1 text-primary"></i>Judul diajukan oleh mahasiswa. Tinjau dokumen terlampir, lalu berikan keputusan ACC atau Revisi.
                                                                    </small>
                                                                <?php else: ?>
                                                                    <div class="alert alert-warning small mt-2 mb-0 py-2">
                                                                        <i class="bi bi-exclamation-triangle me-1"></i>Mahasiswa belum mengajukan judul skripsi. Minta mahasiswa untuk mengirimkan judul melalui menu <strong>Upload Draf Skripsi</strong> atau <strong>Pengajuan Progres</strong>.
                                                                    </div>
                                                                <?php endif; ?>
                                                            <?php else: ?>
                                                                <!-- Tampilan judul jika sudah pernah di-ACC sebelumnya -->
                                                                <div class="p-2 bg-light rounded text-dark fw-semibold small mt-1">
                                                                    <?= htmlspecialchars($row['judul_skripsi']); ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>

                                                        <!-- 3. Alur Tahapan Skripsi (Pipeline Stepper) -->
                                                        <div class="mb-4">
                                                            <label class="form-label fw-bold small text-dark mb-2">
                                                                <i class="bi bi-signpost-split text-primary me-1"></i>Alur Tahapan Progres Skripsi (Otomatis & Terkunci):
                                                            </label>
                                                            <div class="p-3 bg-light rounded-3 border">
                                                                <div class="row g-2 text-center">
                                                                    <?php foreach ($p_info['tahapan_ui'] as $lvl => $t): ?>
                                                                        <div class="col-6 col-md">
                                                                            <div class="p-2 rounded border <?= ($t['status_ui'] === 'selesai') ? 'bg-success-subtle border-success text-success' : (($t['status_ui'] === 'aktif') ? 'bg-primary text-white border-primary shadow-sm' : 'bg-white text-muted border-secondary-subtle'); ?> h-100 d-flex flex-column justify-content-between">
                                                                                <div style="font-size: 0.72rem;" class="fw-bold mb-1">
                                                                                    <?= $t['singkat']; ?>
                                                                                </div>
                                                                                <div>
                                                                                    <?php if ($t['status_ui'] === 'selesai'): ?>
                                                                                        <span class="badge bg-success" style="font-size: 0.65rem;"><i class="bi bi-check2"></i> ACC</span>
                                                                                    <?php elseif ($t['status_ui'] === 'aktif'): ?>
                                                                                        <span class="badge bg-warning text-dark fw-bold" style="font-size: 0.65rem;">Tahap Ini</span>
                                                                                    <?php else: ?>
                                                                                        <span class="badge bg-secondary-subtle text-muted" style="font-size: 0.65rem;"><i class="bi bi-lock-fill"></i> Kunci</span>
                                                                                    <?php endif; ?>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                                <div class="mt-2 text-muted" style="font-size: 0.75rem;">
                                                                    <i class="bi bi-shield-lock me-1"></i>Tahapan masa depan terkunci secara otomatis. Dosen tidak dapat melompati bab sebelum tahap saat ini di-ACC.
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <!-- 4. Pilihan Keputusan Dosen (HANYA 2 PILIHAN: ACC ATAU REVISI) -->
                                                        <div class="mb-4">
                                                            <label class="form-label fw-bold small text-dark mb-2">
                                                                <i class="bi bi-check2-circle text-primary me-1"></i>Keputusan Hasil Evaluasi Naskah: <span class="text-danger">*</span>
                                                            </label>
                                                            <div class="row g-3">
                                                                <!-- KARTU 1: ACC TAHAP SAAT INI -->
                                                                <div class="col-md-6">
                                                                    <label class="d-block w-100 h-100">
                                                                        <input class="d-none form-check-input" 
                                                                               type="radio" 
                                                                               name="keputusan_aksi" 
                                                                               value="acc" 
                                                                               required 
                                                                               checked>
                                                                        <div class="decision-card h-100 p-3 rounded-3 border">
                                                                            <div class="fw-bold text-success mb-1 fs-6">
                                                                                <i class="bi bi-check-circle-fill me-1"></i> ACC <?= htmlspecialchars($tahap_saat_ini['singkat'] ?? 'Tahap Ini'); ?>
                                                                            </div>
                                                                            <small class="text-dark d-block mb-1">
                                                                                Mengesahkan <strong><?= htmlspecialchars($tahap_saat_ini['label'] ?? ''); ?></strong>.
                                                                            </small>
                                                                            <span class="badge bg-success-subtle text-success border border-success-subtle">
                                                                                Progres Naik: <?= $progres_persen; ?>% &rarr; <?= $p_info['progres_jika_acc']; ?>%
                                                                            </span>
                                                                        </div>
                                                                    </label>
                                                                </div>

                                                                <!-- KARTU 2: PERLU REVISI -->
                                                                <div class="col-md-6">
                                                                    <label class="d-block w-100 h-100">
                                                                        <input class="d-none form-check-input" 
                                                                               type="radio" 
                                                                               name="keputusan_aksi" 
                                                                               value="revisi" 
                                                                               <?= ($row['status'] === 'revisi') ? 'checked' : ''; ?>>
                                                                        <div class="decision-card h-100 p-3 rounded-3 border">
                                                                            <div class="fw-bold text-warning-emphasis mb-1 fs-6">
                                                                                <i class="bi bi-exclamation-triangle-fill me-1 text-warning"></i> Perlu Revisi Naskah
                                                                            </div>
                                                                            <small class="text-dark d-block mb-1">
                                                                                Mahasiswa wajib memperbaiki naskah sesuai catatan dosen.
                                                                            </small>
                                                                            <span class="badge bg-warning-subtle text-dark border border-warning-subtle">
                                                                                Progres Tetap: <?= $progres_persen; ?>%
                                                                            </span>
                                                                        </div>
                                                                    </label>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <!-- 5. Catatan Dosen (WAJIB DIISI) -->
                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold small text-dark d-flex justify-content-between align-items-center">
                                                                <span><i class="bi bi-chat-left-text text-primary me-1"></i>Catatan & Lembar Koreksi Dosen: <span class="text-danger">* (Wajib Diisi)</span></span>
                                                                <small class="text-muted fw-normal">Akan diteruskan ke notifikasi & logbook mahasiswa</small>
                                                            </label>
                                                            <textarea name="catatan_dosen" 
                                                                      class="form-control" 
                                                                      rows="3" 
                                                                      placeholder="Tuliskan arahan resmi, catatan perbaikan jika revisi, atau instruksi langkah selanjutnya jika di-ACC..." 
                                                                      required><?= htmlspecialchars($row['catatan_dosen'] ?? ''); ?></textarea>
                                                        </div>
                                                    </div>

                                                    <div class="modal-footer bg-light rounded-bottom-4">
                                                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
                                                        <button type="submit" class="btn btn-primary btn-sm fw-semibold px-4 shadow-sm">
                                                            <i class="bi bi-check2-circle me-1"></i> Simpan & Perbarui Progres Mahasiswa
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.decision-card {
    cursor: pointer;
    border: 2px solid #e2e8f0;
    transition: all 0.2s ease;
    background: #ffffff;
}
.decision-card:hover {
    border-color: #3b82f6;
    background-color: #f8fafc;
}
input[type="radio"]:checked + .decision-card {
    border-color: #2563eb !important;
    background-color: #eff6ff !important;
    box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.25) !important;
}
</style>

<?php
require_once '../templates/footer.php';
?>
