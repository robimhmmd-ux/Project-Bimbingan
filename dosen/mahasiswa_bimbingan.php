<style>
/* CSS Sticky Column untuk Kolom Aksi agar tidak terpotong */
.sticky-action-col {
    position: sticky !important;
    right: 0 !important;
    background: #ffffff !important;
    z-index: 2 !important;
    box-shadow: -4px 0 10px rgba(0, 0, 0, 0.06) !important;
}
thead th.sticky-action-col {
    background: #f8fafc !important;
    z-index: 3 !important;
}
[data-theme="dark"] .sticky-action-col {
    background: #111827 !important;
    box-shadow: -6px 0 14px rgba(0, 0, 0, 0.45) !important;
}
[data-theme="dark"] thead th.sticky-action-col {
    background: #1f2937 !important;
}
</style>
<?php
// dosen/mahasiswa_bimbingan.php - Monitoring & Pengelolaan Catatan Progres Mahasiswa (Salsa)
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

$success_msg = '';
$error_msg   = '';

// 1. PROSES SIMPAN / ATUR CATATAN PROGRES MAHASISWA OLEH DOSEN (ALUR BERURUTAN OTOMATIS)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_catatan_progres'])) {
    $mahasiswa_id      = (int)($_POST['mahasiswa_id'] ?? 0);
    $keputusan_aksi    = trim($_POST['keputusan_aksi'] ?? ''); // 'acc' atau 'revisi'
    $tanggal_bimbingan = trim($_POST['tanggal_bimbingan'] ?? date('Y-m-d'));
    $jam_bimbingan     = trim($_POST['jam_bimbingan'] ?? date('H:i:s'));
    $catatan_dosen     = trim($_POST['catatan_dosen'] ?? '');
    $judul_skripsi     = trim($_POST['judul_skripsi'] ?? '');

    if ($mahasiswa_id <= 0 || !in_array($keputusan_aksi, ['acc', 'revisi'])) {
        $error_msg = "Data mahasiswa atau keputusan evaluasi tidak valid.";
    } elseif (empty($catatan_dosen)) {
        $error_msg = "Catatan arahan / instruksi progres dari dosen wajib diisi untuk setiap hasil evaluasi.";
    } else {
        $info_progres = get_progres_mahasiswa($pdo, $mahasiswa_id);
        if (!$info_progres) {
            $error_msg = "Data mahasiswa tidak ditemukan.";
        } else {
            $current_level = $info_progres['current_level'];
            $tahap_current = $info_progres['tahap_saat_ini'];

            // Tahap 1 (Judul): Ambil judul resmi dari pengajuan mahasiswa, bukan dari ketikan dosen
            $judul_skripsi = '';
            if ($current_level === 1) {
                $st_pj_judul = $pdo->prepare("
                    SELECT catatan_mahasiswa, file_draft, link_drive FROM pengajuan_bimbingan 
                    WHERE mahasiswa_id = :mid AND (bab_pembahasan LIKE '%Judul%') 
                    ORDER BY id DESC LIMIT 1
                ");
                $st_pj_judul->execute([':mid' => $mahasiswa_id]);
                $pj_judul = $st_pj_judul->fetch();

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
                        if ($current_level === 1) {
                            $progres_baru   = 15;
                            $status_log     = 'acc_judul';
                            $status_sesi    = 'selesai';
                            $nama_bab_resmi = 'Pengajuan & ACC Judul Skripsi';

                            $st_j = $pdo->prepare("UPDATE mahasiswa SET judul_skripsi = :j WHERE id = :mid AND dosen_pembimbing_id = :did");
                            $st_j->execute([':j' => $judul_skripsi, ':mid' => $mahasiswa_id, ':did' => $dosen_id]);

                            $judul_n = "Selamat! Judul Skripsi Anda Telah Di-ACC";
                            $pesan_n = "Dosen pembimbing telah mengesahkan judul skripsi: '{$judul_skripsi}'. Silakan lanjutkan menyusun Bab 1: Pendahuluan.";
                            $tipe_n  = "pembimbing";

                        } elseif ($current_level >= 2 && $current_level <= 6) {
                            $progres_baru   = $info_progres['progres_jika_acc'];
                            $status_log     = 'acc_bab';
                            $status_sesi    = 'selesai';
                            $nama_bab_resmi = $tahap_current['nama_bab'];

                            $judul_n = "Selamat! {$tahap_current['label']} Telah Di-ACC";
                            $pesan_n = "Dosen pembimbing telah mengesahkan {$tahap_current['label']}. Capaian progres Anda kini {$progres_baru}%.";
                            $tipe_n  = "sukses";

                        } else {
                            $progres_baru   = 100;
                            $status_log     = 'acc_sidang';
                            $status_sesi    = 'selesai';
                            $nama_bab_resmi = 'Naskah Lengkap / Pra-Sidang';

                            $st_sid = $pdo->prepare("UPDATE mahasiswa SET status_kelulusan = 'siap_sidang' WHERE id = :mid AND dosen_pembimbing_id = :did");
                            $st_sid->execute([':mid' => $mahasiswa_id, ':did' => $dosen_id]);

                            $judul_n = "Selamat! Anda Di-ACC Siap Sidang Skripsi";
                            $pesan_n = "Seluruh naskah skripsi tuntas dan disahkan (ACC Sidang, 100%). Anda siap mendaftar ujian sidang sarjana.";
                            $tipe_n  = "sidang";
                        }

                        $stmt_ins = $pdo->prepare("
                            INSERT INTO pengajuan_bimbingan 
                            (mahasiswa_id, dosen_id, tanggal_bimbingan, jam_bimbingan, bab_pembahasan, catatan_mahasiswa, file_draft, status, catatan_dosen, nilai_progres)
                            VALUES 
                            (:mid, :did, :tgl, :jam, :bab, :cat_mhs, NULL, :st_sesi, :cat_dosen, :nilai)
                        ");
                        $stmt_ins->execute([
                            ':mid'       => $mahasiswa_id,
                            ':did'       => $dosen_id,
                            ':tgl'       => $tanggal_bimbingan,
                            ':jam'       => $jam_bimbingan,
                            ':bab'       => $nama_bab_resmi,
                            ':cat_mhs'   => 'Sesi evaluasi & pengesahan progres langsung bersama Dosen Pembimbing',
                            ':st_sesi'   => $status_sesi,
                            ':cat_dosen' => $catatan_dosen,
                            ':nilai'     => $progres_baru
                        ]);
                        $bimbingan_id = (int)$pdo->lastInsertId();

                        $stmt_lb = $pdo->prepare("
                            INSERT INTO logbook_bimbingan (bimbingan_id, mahasiswa_id, tanggal_acc, catatan_hasil, status_bab)
                            VALUES (:bid, :mid, :tgl_acc, :cat_hasil, :sbab)
                        ");
                        $stmt_lb->execute([
                            ':bid'       => $bimbingan_id,
                            ':mid'       => $mahasiswa_id,
                            ':tgl_acc'   => $tanggal_bimbingan,
                            ':cat_hasil' => $catatan_dosen,
                            ':sbab'      => $status_log
                        ]);

                        $label_keputusan = "ACC " . ($tahap_current['singkat'] ?? '');

                    } else {
                        // KEPUTUSAN REVISI
                        $progres_tetap  = $info_progres['progres_persen'];
                        $nama_bab_sesi  = $tahap_current['nama_bab'] ?? 'Naskah Bimbingan';

                        $stmt_ins = $pdo->prepare("
                            INSERT INTO pengajuan_bimbingan 
                            (mahasiswa_id, dosen_id, tanggal_bimbingan, jam_bimbingan, bab_pembahasan, catatan_mahasiswa, file_draft, status, catatan_dosen, nilai_progres)
                            VALUES 
                            (:mid, :did, :tgl, :jam, :bab, :cat_mhs, NULL, 'revisi', :cat_dosen, :nilai)
                        ");
                        $stmt_ins->execute([
                            ':mid'       => $mahasiswa_id,
                            ':did'       => $dosen_id,
                            ':tgl'       => $tanggal_bimbingan,
                            ':jam'       => $jam_bimbingan,
                            ':bab'       => $nama_bab_sesi,
                            ':cat_mhs'   => 'Sesi evaluasi bimbingan bersama Dosen Pembimbing',
                            ':cat_dosen' => $catatan_dosen,
                            ':nilai'     => $progres_tetap
                        ]);
                        $bimbingan_id = (int)$pdo->lastInsertId();

                        $stmt_lb = $pdo->prepare("
                            INSERT INTO logbook_bimbingan (bimbingan_id, mahasiswa_id, tanggal_acc, catatan_hasil, status_bab)
                            VALUES (:bid, :mid, :tgl_acc, :cat_hasil, 'revisi')
                        ");
                        $stmt_lb->execute([
                            ':bid'       => $bimbingan_id,
                            ':mid'       => $mahasiswa_id,
                            ':tgl_acc'   => $tanggal_bimbingan,
                            ':cat_hasil' => "Catatan Revisi: " . $catatan_dosen,
                        ]);

                        $judul_n         = "Catatan Revisi dari Pembimbing";
                        $pesan_n         = "Dosen pembimbing memberikan catatan revisi pada " . ($tahap_current['label'] ?? 'naskah') . ": '{$catatan_dosen}'. Silakan perbaiki naskah Anda.";
                        $tipe_n          = "revisi";
                        $label_keputusan = "PERLU REVISI";
                        $progres_baru    = $progres_tetap;
                    }

                    $pdo->commit();

                    try {
                        $st_u_mhs = $pdo->prepare("SELECT user_id FROM mahasiswa WHERE id = :mid LIMIT 1");
                        $st_u_mhs->execute([':mid' => $mahasiswa_id]);
                        $m_uid = $st_u_mhs->fetchColumn();
                        if ($m_uid) {
                            $st_not = $pdo->prepare("INSERT INTO notifikasi (user_id, judul, pesan, tipe, link_tujuan) VALUES (:uid, :jdl, :psn, :tp, 'logbook.php')");
                            $st_not->execute([':uid' => $m_uid, ':jdl' => $judul_n, ':psn' => $pesan_n, ':tp' => $tipe_n]);
                        }
                    } catch (Exception $ne) {}

                    $success_msg = "Catatan bimbingan berhasil disimpan: Keputusan <strong>{$label_keputusan}</strong>. Progres mahasiswa kini <strong>{$progres_baru}%</strong>.";

                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $error_msg = "Gagal menyimpan catatan progres: " . $e->getMessage();
                }
            }
        }
    }
}
// 2. PROSES UPDATE CATATAN SESI BIMBINGAN LAMA OLEH DOSEN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_catatan_sesi'])) {
    $bimbingan_id   = (int)($_POST['bimbingan_id'] ?? 0);
    $mahasiswa_id   = (int)($_POST['mahasiswa_id'] ?? 0);
    $nilai_progres  = (int)($_POST['nilai_progres'] ?? 0);
    $catatan_dosen  = trim($_POST['catatan_dosen'] ?? '');
    $status_sesi    = trim($_POST['status_sesi'] ?? 'selesai');

    if ($nilai_progres < 0) $nilai_progres = 0;
    if ($nilai_progres > 100) $nilai_progres = 100;

    if ($bimbingan_id <= 0 || empty($catatan_dosen)) {
        $error_msg = "Data sesi atau catatan dosen tidak boleh kosong.";
    } else {
        try {
            $pdo->beginTransaction();
            $stmt_up = $pdo->prepare("
                UPDATE pengajuan_bimbingan 
                SET catatan_dosen = :cat, nilai_progres = :nilai, status = :st 
                WHERE id = :bid AND dosen_id = :did
            ");
            $stmt_up->execute([
                ':cat'   => $catatan_dosen,
                ':nilai' => $nilai_progres,
                ':st'    => $status_sesi,
                ':bid'   => $bimbingan_id,
                ':did'   => $dosen_id
            ]);

            $stmt_up_lb = $pdo->prepare("
                UPDATE logbook_bimbingan 
                SET catatan_hasil = :cat 
                WHERE bimbingan_id = :bid AND mahasiswa_id = :mid
            ");
            $stmt_up_lb->execute([
                ':cat' => $catatan_dosen,
                ':bid' => $bimbingan_id,
                ':mid' => $mahasiswa_id
            ]);

            $pdo->commit();
            $success_msg = "Catatan sesi bimbingan berhasil diperbarui.";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error_msg = "Gagal memperbarui catatan: " . $e->getMessage();
        }
    }
}

// 2. PROSES RESET / LEPAS BIMBINGAN MAHASISWA OLEH DOSEN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aksi_dosen_reset_mhs'])) {
    $mahasiswa_id = (int)($_POST['mahasiswa_id'] ?? 0);
    $tipe_reset   = trim($_POST['tipe_reset'] ?? 'lepas_dan_reset'); // 'lepas_dan_reset' atau 'reset_progres_saja'

    if ($mahasiswa_id > 0) {
        try {
            $pdo->beginTransaction();

            // Verifikasi mahasiswa ini benar dibimbing oleh dosen aktif
            $stmtCek = $pdo->prepare("SELECT id, user_id, nama_lengkap, nim FROM mahasiswa WHERE id = :mid AND dosen_pembimbing_id = :did LIMIT 1");
            $stmtCek->execute([':mid' => $mahasiswa_id, ':did' => $dosen_id]);
            $mhsBim = $stmtCek->fetch();

            if (!$mhsBim) {
                throw new Exception("Mahasiswa tidak ditemukan atau bukan mahasiswa bimbingan Anda.");
            }

            // Hapus logbook & pengajuan bimbingan dosen ini
            $stDelLog = $pdo->prepare("DELETE FROM logbook_bimbingan WHERE mahasiswa_id = :mid");
            $stDelLog->execute([':mid' => $mahasiswa_id]);

            $stDelBim = $pdo->prepare("DELETE FROM pengajuan_bimbingan WHERE mahasiswa_id = :mid AND dosen_id = :did");
            $stDelBim->execute([':mid' => $mahasiswa_id, ':did' => $dosen_id]);

            $stDelSid = $pdo->prepare("DELETE FROM sidang_skripsi WHERE mahasiswa_id = :mid");
            $stDelSid->execute([':mid' => $mahasiswa_id]);

            if ($tipe_reset === 'lepas_dan_reset') {
                // Lepaskan dari dosen pembimbing & kosongkan judul
                $stUpMhs = $pdo->prepare("
                    UPDATE mahasiswa 
                    SET dosen_pembimbing_id = NULL, 
                        status_pembimbing = 'belum_diplot', 
                        judul_skripsi = 'Belum Ditentukan', 
                        status_kelulusan = 'proses_bimbingan' 
                    WHERE id = :mid
                ");
                $stUpMhs->execute([':mid' => $mahasiswa_id]);
                $msg_aksi = "Mahasiswa {$mhsBim['nama_lengkap']} ({$mhsBim['nim']}) berhasil di-reset total dan dilepas dari daftar bimbingan Anda.";
            } else {
                // Reset progres saja (tetap dibimbing)
                $stUpMhs = $pdo->prepare("UPDATE mahasiswa SET status_kelulusan = 'proses_bimbingan', judul_skripsi = 'Belum Ditentukan' WHERE id = :mid");
                $stUpMhs->execute([':mid' => $mahasiswa_id]);
                $msg_aksi = "Capaian progres bimbingan {$mhsBim['nama_lengkap']} berhasil di-reset ke 0%.";
            }

            // Notifikasi ke mahasiswa
            if (!empty($mhsBim['user_id'])) {
                $pesan_n = ($tipe_reset === 'lepas_dan_reset')
                    ? "Dosen pembimbing telah me-reset dan melepas alokasi bimbingan Anda. Silakan hubungi koordinator skripsi / admin jurusan untuk penjadwalan pembimbing baru."
                    : "Dosen pembimbing telah me-reset capaian progres bimbingan Anda kembali ke awal (0%).";
                
                $stNotif = $pdo->prepare("
                    INSERT INTO notifikasi (user_id, judul, pesan, tipe, link_tujuan) 
                    VALUES (:uid, 'Reset Bimbingan Skripsi', :pesan, 'info', 'index.php')
                ");
                $stNotif->execute([':uid' => $mhsBim['user_id'], ':pesan' => $pesan_n]);
            }

            $pdo->commit();
            $success_msg = $msg_aksi;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_msg = "Gagal me-reset mahasiswa: " . $e->getMessage();
        }
    }
}

// Filter & Pencarian
$search = trim($_GET['search'] ?? '');
$filter_status = trim($_GET['status'] ?? 'semua');

// Susun Query Mahasiswa Bimbingan beserta Catatan & Progres Terakhir
$sql = "
    SELECT m.*, u.id AS mhs_user_id,
           (SELECT COALESCE(MAX(nilai_progres), 0) FROM pengajuan_bimbingan WHERE mahasiswa_id = m.id AND dosen_id = :did1) AS progres_tertinggi,
           (SELECT COUNT(*) FROM pengajuan_bimbingan WHERE mahasiswa_id = m.id AND dosen_id = :did2) AS total_sesi,
           (SELECT bab_pembahasan FROM pengajuan_bimbingan WHERE mahasiswa_id = m.id AND dosen_id = :did3 ORDER BY tanggal_bimbingan DESC, id DESC LIMIT 1) AS bab_terakhir,
           (SELECT tanggal_bimbingan FROM pengajuan_bimbingan WHERE mahasiswa_id = m.id AND dosen_id = :did4 ORDER BY tanggal_bimbingan DESC, id DESC LIMIT 1) AS tgl_terakhir,
           (SELECT catatan_dosen FROM pengajuan_bimbingan WHERE mahasiswa_id = m.id AND dosen_id = :did5 ORDER BY tanggal_bimbingan DESC, id DESC LIMIT 1) AS catatan_terakhir,
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
    ':did5'     => $dosen_id,
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

// Hitung Statistik Ringkas
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
        <p class="text-muted small mb-0">Pantau capaian skripsi, atur catatan progres bimbingan, berikan nilai, dan tetapkan status pengesahan bab.</p>
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

<!-- Alert Notifikasi -->
<?php if (!empty($success_msg)): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i><?= $success_msg; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (!empty($error_msg)): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error_msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- 4 Kartu Metrik Ringkasan Progres -->
<div class="row g-2 g-md-3 mb-4">
    <!-- Total Mahasiswa -->
    <div class="col-6 col-xl-3">
        <div class="card p-3 shadow-sm border-0 border-start border-primary border-4 h-100 bg-white">
            <h6 class="text-muted small text-uppercase fw-semibold mb-1">Mahasiswa Bimbingan</h6>
            <h3 class="fw-bold text-dark mb-1"><?= $total_mhs; ?> <span class="fs-6 text-muted fw-normal">/ <?= htmlspecialchars($dosen['kuota_bimbingan'] ?? '10'); ?> Kuota</span></h3>
            <p class="text-muted small mb-0">Terisi <?= round(($total_mhs / max(1, (int)($dosen['kuota_bimbingan'] ?? 10))) * 100); ?>% dari kapasitas</p>
        </div>
    </div>

    <!-- Dalam Proses Bimbingan -->
    <div class="col-6 col-xl-3">
        <div class="card p-3 shadow-sm border-0 border-start border-warning border-4 h-100 bg-white">
            <h6 class="text-muted small text-uppercase fw-semibold mb-1">Proses Bimbingan</h6>
            <h3 class="fw-bold text-warning mb-1"><?= $count_proses; ?> <span class="fs-6 text-muted fw-normal">Mahasiswa</span></h3>
            <p class="text-muted small mb-0">Sedang tahap konsultasi naskah</p>
        </div>
    </div>

    <!-- Siap Sidang / Lulus -->
    <div class="col-6 col-xl-3">
        <div class="card p-3 shadow-sm border-0 border-start border-success border-4 h-100 bg-white">
            <h6 class="text-muted small text-uppercase fw-semibold mb-1">Siap Sidang Skripsi</h6>
            <h3 class="fw-bold text-success mb-1"><?= $count_siap_sidang; ?> <span class="fs-6 text-muted fw-normal">Mahasiswa</span></h3>
            <p class="text-muted small mb-0">Naskah tuntas & ter-ACC</p>
        </div>
    </div>

    <!-- Rata-rata Progres -->
    <div class="col-6 col-xl-3">
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
<div class="card shadow-sm border-0 bg-white mb-4">
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
                        <th style="width: 35px;" class="text-center">No</th>
                        <th style="min-width: 170px;">Mahasiswa & Judul</th>
                        <th style="width: 115px;">Progres & Sesi</th>
                        <th style="min-width: 160px;">Bab & Catatan Terakhir</th>
                        <th style="width: 95px;" class="text-center">Status</th>
                        <th style="width: 115px;" class="text-center sticky-action-col">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($daftar_mahasiswa)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="bi bi-people fs-1 d-block mb-2 text-muted"></i>
                                Tidak ada mahasiswa bimbingan yang sesuai dengan kriteria pencarian.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($daftar_mahasiswa as $idx => $mhs): ?>
                            <?php
                            $p_info = get_progres_mahasiswa($pdo, $mhs['id']);
                            $progres = $p_info['progres_persen'];
                            $tahap_current = $p_info['tahap_saat_ini'];
                            $bar_color = ($progres >= 75) ? 'bg-success' : (($progres >= 30) ? 'bg-info' : 'bg-warning');
                            
                            ?>
                            <tr>
                                <td class="text-center fw-semibold text-muted"><?= $idx + 1; ?></td>
                                <td>
                                    <div class="d-flex align-items-start gap-2">
                                        <?= function_exists('render_avatar') ? render_avatar($mhs['foto_profil'] ?? '', $mhs['nama_lengkap'] ?? 'M', 38, 'mt-1') : '<div class="avatar-circle avatar-sm avatar-primary mt-1">' . strtoupper(substr($mhs['nama_lengkap'], 0, 1)) . '</div>'; ?>
                                        <div>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($mhs['nama_lengkap']); ?></div>
                                            <div class="small text-muted d-flex flex-wrap align-items-center gap-1 mt-1">
                                            <span>NIM: <?= htmlspecialchars($mhs['nim']); ?></span>
                                            <?php if (!empty($mhs['kelas'])): ?>
                                                <span class="badge bg-light text-primary border border-primary-subtle"><?= htmlspecialchars($mhs['kelas']); ?></span>
                                            <?php endif; ?>
                                            <?php if (($mhs['status_pembimbing'] ?? '') === 'menunggu_konfirmasi'): ?>
                                                <span class="badge bg-warning-subtle text-dark border border-warning-subtle" title="Menunggu mahasiswa konfirmasi SK">SK: Menunggu</span>
                                            <?php elseif (($mhs['status_pembimbing'] ?? '') === 'aktif'): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle" title="SK Bimbingan Aktif">SK: Aktif</span>
                                            <?php endif; ?>
                                        </div>
                                            <div class="text-truncate small text-secondary mt-1" style="max-width: 240px;" title="<?= htmlspecialchars($mhs['judul_skripsi']); ?>">
                                                <i class="bi bi-mortarboard me-1 text-primary"></i><?= htmlspecialchars($mhs['judul_skripsi']); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <small class="fw-bold text-dark"><?= $progres; ?>%</small>
                                        <small class="text-muted" style="font-size: 0.72rem;">
                                            <?= ($progres >= 100) ? 'Tuntas' : (($progres >= 50) ? 'Lanjut' : 'Awal'); ?>
                                        </small>
                                    </div>
                                    <div class="progress" style="height: 6px;">
                                        <div class="progress-bar <?= $bar_color; ?>" role="progressbar" style="width: <?= $progres; ?>%;" aria-valuenow="<?= $progres; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                    <div class="small text-muted mt-1" style="font-size: 0.72rem;">
                                        <i class="bi bi-calendar-check me-1 text-primary"></i><span class="fw-semibold text-dark"><?= (int)$mhs['total_sesi']; ?></span> Sesi
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($mhs['bab_terakhir'])): ?>
                                        <span class="badge bg-light text-dark border"><?= htmlspecialchars($mhs['bab_terakhir']); ?></span>
                                        <?php if (!empty($mhs['tgl_terakhir'])): ?>
                                            <small class="text-muted ms-1" style="font-size: 0.72rem;">
                                                (<?= date('d/m/Y', strtotime($mhs['tgl_terakhir'])); ?>)
                                            </small>
                                        <?php endif; ?>
                                        <?php if (!empty($mhs['catatan_terakhir'])): ?>
                                            <div class="text-truncate text-secondary small mt-1" style="max-width: 180px;" title="<?= htmlspecialchars($mhs['catatan_terakhir']); ?>">
                                                <i class="bi bi-chat-left-quote me-1 text-primary"></i><?= htmlspecialchars($mhs['catatan_terakhir']); ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted small fst-italic">Belum ada catatan progres</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php
                                    $stk = $mhs['status_kelulusan'];
                                    if ($stk === 'siap_sidang') {
                                        echo '<span class="badge bg-success-subtle text-success border border-success-subtle"><i class="bi bi-check-circle me-1"></i>Siap Sidang</span>';
                                    } elseif ($stk === 'lulus') {
                                        echo '<span class="badge bg-primary"><i class="bi bi-mortarboard-fill me-1"></i>Lulus</span>';
                                    } else {
                                        echo '<span class="badge bg-warning-subtle text-warning border border-warning-subtle"><i class="bi bi-hourglass-split me-1"></i>Proses</span>';
                                    }
                                    ?>
                                </td>
                                <td class="text-center sticky-action-col">
                                    <div class="d-flex justify-content-center align-items-center gap-1">
                                        <!-- Tombol Utama: Atur Catatan Progres & Penilaian -->
                                        <button type="button" 
                                                class="btn btn-sm btn-primary py-1 px-2 d-inline-flex align-items-center gap-1" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#modalAturProgres<?= $mhs['id']; ?>"
                                                title="Atur Catatan Progres, Nilai, dan Status Mahasiswa">
                                            <i class="bi bi-pencil-square"></i>
                                            <span class="small fw-semibold">Atur</span>
                                        </button>

                                        <!-- Tombol 2: Detail Timeline Riwayat Bimbingan -->
                                        <button type="button" 
                                                class="btn btn-sm btn-outline-secondary py-1 px-2" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#modalRiwayatMhs<?= $mhs['id']; ?>"
                                                title="Lihat Timeline Riwayat & Logbook">
                                            <i class="bi bi-clock-history"></i>
                                        </button>

                                        <!-- Dropdown Menu Tindakan Lainnya -->
                                        <div class="dropdown d-inline-block">
                                            <button class="btn btn-sm btn-outline-secondary py-1 px-1.5" 
                                                    type="button" 
                                                    data-bs-toggle="dropdown" 
                                                    aria-expanded="false" 
                                                    title="Menu Lainnya">
                                                <i class="bi bi-three-dots-vertical"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 py-2" style="font-size: 0.85rem;">
                                                <li>
                                                    <a class="dropdown-item py-1.5 d-flex align-items-center gap-2" href="chat.php?uid=<?= $mhs['mhs_user_id']; ?>">
                                                        <i class="bi bi-chat-dots-fill text-success"></i>
                                                        <span>Kirim Pesan Chat</span>
                                                    </a>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item py-1.5 d-flex align-items-center gap-2" href="cetak_laporan.php?mahasiswa_id=<?= $mhs['id']; ?>" target="_blank">
                                                        <i class="bi bi-printer text-primary"></i>
                                                        <span>Cetak Lembar Rekapan</span>
                                                    </a>
                                                </li>
                                                <li><hr class="dropdown-divider my-1"></li>
                                                <li>
                                                    <button type="button" class="dropdown-item py-1.5 d-flex align-items-center gap-2 text-danger" data-bs-toggle="modal" data-bs-target="#modalResetDosen<?= $mhs['id']; ?>">
                                                        <i class="bi bi-arrow-counterclockwise"></i>
                                                        <span>Lepas dari Bimbingan</span>
                                                    </button>
                                                </li>
                                            </ul>
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

<!-- =========================================================================
     MODAL SECTIONS (DITEMPATKAN DI LUAR TABLE & KONTENER AGAR TIDAK TERJEBAK STACKING CONTEXT)
     ========================================================================= -->
<?php if (!empty($daftar_mahasiswa)): ?>
    <?php foreach ($daftar_mahasiswa as $mhs): ?>
        <?php
        $p_info = get_progres_mahasiswa($pdo, $mhs['id']);
        $progres = $p_info['progres_persen'];
        $tahap_current = $p_info['tahap_saat_ini'];
        $bar_color = ($progres >= 75) ? 'bg-success' : (($progres >= 30) ? 'bg-info' : 'bg-warning');
        
        // Query riwayat bimbingan untuk Modal 2
        $stmt_hist = $pdo->prepare("
            SELECT * FROM pengajuan_bimbingan 
            WHERE mahasiswa_id = :mid AND dosen_id = :did 
            ORDER BY tanggal_bimbingan DESC, jam_bimbingan DESC
        ");
        $stmt_hist->execute([':mid' => $mhs['id'], ':did' => $dosen_id]);
        $hist_list = $stmt_hist->fetchAll();
        ?>
                                    <!-- MODAL 1: ATUR CATATAN PROGRES MAHASISWA OLEH DOSEN (ALUR BERURUTAN OTOMATIS) -->
                                    <div class="modal fade" id="modalAturProgres<?= $mhs['id']; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-lg text-start">
                                            <div class="modal-content border-0 shadow rounded-4">
                                                <form method="POST" action="mahasiswa_bimbingan.php">
                                                    <input type="hidden" name="simpan_catatan_progres" value="1">
                                                    <input type="hidden" name="mahasiswa_id" value="<?= $mhs['id']; ?>">

                                                    <div class="modal-header bg-primary text-white rounded-top-4">
                                                        <h5 class="modal-title fw-bold">
                                                            <i class="bi bi-pencil-square me-2"></i>Atur Catatan & Progres Skripsi: <?= htmlspecialchars($mhs['nama_lengkap']); ?>
                                                        </h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>

                                                    <div class="modal-body p-4">
                                                        <!-- 1. Ringkasan Info Mahasiswa -->
                                                        <div class="p-3 bg-light rounded border mb-3">
                                                            <div class="row g-2 small">
                                                                <div class="col-md-6">
                                                                    <span class="text-muted d-block">Mahasiswa:</span>
                                                                    <strong class="text-dark fs-6"><?= htmlspecialchars($mhs['nama_lengkap']); ?></strong>
                                                                    <span class="text-muted d-block">NIM: <?= htmlspecialchars($mhs['nim']); ?> (<?= htmlspecialchars($mhs['kelas'] ?? 'TI'); ?>)</span>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <span class="text-muted d-block">Capaian Progres Saat Ini:</span>
                                                                    <span class="badge bg-primary fs-6 px-3 py-1"><?= $progres; ?>% Selesai</span>
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

                                                                    <a href="cek_judul.php?q=<?= urlencode(!empty($mhs['judul_skripsi']) && $mhs['judul_skripsi'] !== 'Belum Ditentukan' ? $mhs['judul_skripsi'] : ''); ?>" 
                                                                       target="_blank" 
                                                                       class="btn btn-xs btn-outline-primary btn-sm py-1 px-2" 
                                                                       title="Buka Alat Deteksi Duplikasi Judul">
                                                                        <i class="bi bi-shield-check me-1"></i>Cek Kemiripan Judul
                                                                    </a>
                                                                </div>
                                                            </div>

                                                            <?php if ($p_info['current_level'] === 1): ?>
                                                                <!-- Tampilan READ-ONLY judul & dokumen yang diajukan mahasiswa -->
                                                                <?php if ($p_info['has_submitted_judul'] || $p_info['has_judul']): ?>
                                                                    <div class="p-2 bg-info bg-opacity-10 rounded border border-info-subtle text-dark fw-semibold small mt-1">
                                                                        <i class="bi bi-send-check text-info me-1"></i>
                                                                        Judul Diajukan: "<?= htmlspecialchars($p_info['judul_diajukan'] ?: ($mhs['judul_skripsi'] !== 'Belum Ditentukan' ? $mhs['judul_skripsi'] : 'Lihat dokumen terlampir')); ?>"
                                                                    </div>
                                                                    <?php if ($p_info['pengajuan_judul']): ?>
                                                                        <?php if (!empty($p_info['pengajuan_judul']['catatan_mahasiswa'])): ?>
                                                                            <div class="mt-2 p-2 bg-light rounded small border">
                                                                                <i class="bi bi-chat-left-text text-muted me-1"></i><strong>Catatan / Ringkasan Mahasiswa:</strong><br>
                                                                                <?= nl2br(htmlspecialchars($p_info['pengajuan_judul']['catatan_mahasiswa'])); ?>
                                                                            </div>
                                                                        <?php endif; ?>
                                                                        <div class="d-flex flex-wrap gap-2 mt-2">
                                                                            <?php if (!empty($p_info['pengajuan_judul']['file_draft'])): ?>
                                                                                <a href="../unduh_berkas.php?id=<?= $p_info['pengajuan_judul']['id']; ?>" 
                                                                                   class="btn btn-sm btn-outline-danger shadow-sm" target="_blank" download>
                                                                                    <i class="bi bi-file-earmark-pdf-fill me-1"></i>Unduh Dokumen Judul
                                                                                </a>
                                                                            <?php endif; ?>
                                                                            <?php if (!empty($p_info['pengajuan_judul']['link_drive'])): ?>
                                                                                <a href="<?= htmlspecialchars($p_info['pengajuan_judul']['link_drive']); ?>" 
                                                                                   class="btn btn-sm btn-outline-success shadow-sm" target="_blank" rel="noopener noreferrer">
                                                                                    <i class="bi bi-google me-1"></i>Buka Dokumen Google Drive
                                                                                </a>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                    <small class="text-muted d-block mt-2">
                                                                        <i class="bi bi-info-circle me-1 text-primary"></i>Judul dikirimkan oleh mahasiswa. Tinjau dokumen naskah usulan di atas, lalu tentukan keputusan ACC atau Revisi.
                                                                    </small>
                                                                <?php else: ?>
                                                                    <div class="alert alert-warning small mt-2 mb-0 py-2">
                                                                        <i class="bi bi-exclamation-triangle me-1"></i>Mahasiswa belum mengajukan judul skripsi beserta dokumen usulan. Mahasiswa harus mengirimkan usulan judul terlebih dahulu melalui portal mahasiswa.
                                                                    </div>
                                                                <?php endif; ?>
                                                            <?php else: ?>
                                                                <!-- Tampilan judul jika sudah pernah di-ACC sebelumnya -->
                                                                <div class="p-2 bg-light rounded text-dark fw-semibold small mt-1">
                                                                    <?= htmlspecialchars($mhs['judul_skripsi']); ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>

                                                        <!-- 3. Alur Tahapan Progres Skripsi (Otomatis & Terkunci) -->
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
                                                                    <i class="bi bi-shield-lock me-1"></i>Tahapan masa depan terkunci. Dosen tidak dapat melompati bab sebelum tahap saat ini di-ACC.
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <!-- 4. Pilihan Keputusan Dosen (HANYA 2 PILIHAN: ACC ATAU REVISI) -->
                                                        <div class="mb-4">
                                                            <label class="form-label fw-bold small text-dark mb-2">
                                                                <i class="bi bi-check2-circle text-primary me-1"></i>Keputusan Pengesahan Dosen: <span class="text-danger">*</span>
                                                            </label>
                                                            <div class="row g-3">
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
                                                                                <i class="bi bi-check-circle-fill me-1"></i> ACC <?= htmlspecialchars($tahap_current['singkat'] ?? 'Tahap Ini'); ?>
                                                                            </div>
                                                                            <small class="text-dark d-block mb-1">
                                                                                Mengesahkan <strong><?= htmlspecialchars($tahap_current['label'] ?? ''); ?></strong>.
                                                                            </small>
                                                                            <span class="badge bg-success-subtle text-success border border-success-subtle">
                                                                                Progres Naik: <?= $progres; ?>% &rarr; <?= $p_info['progres_jika_acc']; ?>%
                                                                            </span>
                                                                        </div>
                                                                    </label>
                                                                </div>

                                                                <div class="col-md-6">
                                                                    <label class="d-block w-100 h-100">
                                                                        <input class="d-none form-check-input" 
                                                                               type="radio" 
                                                                               name="keputusan_aksi" 
                                                                               value="revisi">
                                                                        <div class="decision-card h-100 p-3 rounded-3 border">
                                                                            <div class="fw-bold text-warning-emphasis mb-1 fs-6">
                                                                                <i class="bi bi-exclamation-triangle-fill me-1 text-warning"></i> Perlu Revisi Naskah
                                                                            </div>
                                                                            <small class="text-dark d-block mb-1">
                                                                                Mahasiswa wajib memperbaiki naskah sesuai catatan dosen.
                                                                            </small>
                                                                            <span class="badge bg-warning-subtle text-dark border border-warning-subtle">
                                                                                Progres Tetap: <?= $progres; ?>%
                                                                            </span>
                                                                        </div>
                                                                    </label>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <!-- Tanggal Sesi -->
                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold small text-dark">
                                                                <i class="bi bi-calendar-event text-primary me-1"></i>Tanggal Sesi / Bimbingan
                                                            </label>
                                                            <input type="date" name="tanggal_bimbingan" class="form-control form-control-sm" value="<?= date('Y-m-d'); ?>" required>
                                                        </div>

                                                        <!-- 5. Catatan Dosen (WAJIB DIISI) -->
                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold small text-dark d-flex justify-content-between align-items-center">
                                                                <span><i class="bi bi-chat-left-text text-primary me-1"></i>Catatan Progres & Arahan Resmi Dosen: <span class="text-danger">* (Wajib Diisi)</span></span>
                                                                <small class="text-muted fw-normal">Akan diterbitkan ke logbook & notifikasi mahasiswa</small>
                                                            </label>
                                                            <textarea name="catatan_dosen" 
                                                                      class="form-control" 
                                                                      rows="3" 
                                                                      placeholder="Tuliskan catatan kemajuan naskah, koreksi yang harus diperbaiki mahasiswa, atau instruksi langkah selanjutnya..." 
                                                                      required></textarea>
                                                        </div>
                                                    </div>

                                                    <div class="modal-footer bg-light rounded-bottom-4">
                                                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                                                        <button type="submit" class="btn btn-primary btn-sm fw-semibold px-4 shadow-sm">
                                                            <i class="bi bi-check2-circle me-1"></i> Simpan & Terbitkan Catatan
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- MODAL 2: DETAIL TIMELINE RIWAYAT & EDIT CATATAN SESI -->
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

                                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                                        <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-list-check text-primary me-2"></i>Daftar Sesi Konsultasi & Catatan Dosen</h6>
                                                        <button type="button" class="btn btn-xs btn-outline-primary btn-sm" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#modalAturProgres<?= $mhs['id']; ?>">
                                                            <i class="bi bi-plus-circle me-1"></i>Tambah Catatan Baru
                                                        </button>
                                                    </div>

                                                    <?php if (empty($hist_list)): ?>
                                                        <div class="text-center py-4 text-muted small">
                                                            <i class="bi bi-calendar-x fs-2 d-block mb-1"></i>
                                                            Mahasiswa ini belum memiliki riwayat bimbingan. Silakan gunakan tombol <strong>Atur Progres</strong> untuk menambahkan catatan pertama.
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
                                                                        <th style="width: 80px;">Status</th>
                                                                        <th style="width: 50px;">Aksi</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    <?php foreach ($hist_list as $h): ?>
                                                                        <tr>
                                                                            <td class="text-center">
                                                                                <?= date('d/m/Y', strtotime($h['tanggal_bimbingan'])); ?><br>
                                                                                <small class="text-muted"><?= substr($h['jam_bimbingan'], 0, 5); ?> WIB</small>
                                                                            </td>
                                                                            <td>
                                                                                <strong><?= htmlspecialchars($h['bab_pembahasan']); ?></strong>
                                                                                <div class="mt-1 d-flex flex-wrap gap-1">
                                                                                    <?php if (!empty($h['file_draft'])): ?>
                                                                                        <a href="../unduh_berkas.php?id=<?= $h['id']; ?>" target="_blank" class="badge bg-danger-subtle text-danger border border-danger-subtle text-decoration-none" title="Unduh PDF"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
                                                                                    <?php endif; ?>
                                                                                    <?php if (!empty($h['link_drive'])): ?>
                                                                                        <a href="<?= htmlspecialchars($h['link_drive']); ?>" target="_blank" rel="noopener noreferrer" class="badge bg-success-subtle text-success border border-success-subtle text-decoration-none" title="Buka Google Drive"><i class="bi bi-google me-1"></i>Drive</a>
                                                                                    <?php endif; ?>
                                                                                </div>
                                                                            </td>
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
                                                                            <td class="text-center">
                                                                                <button class="btn btn-xs btn-outline-primary py-0 px-1" type="button" data-bs-toggle="collapse" data-bs-target="#editSesi<?= $h['id']; ?>" title="Edit Catatan Ini">
                                                                                    <i class="bi bi-pencil"></i>
                                                                                </button>
                                                                            </td>
                                                                        </tr>
                                                                        <tr class="collapse bg-light" id="editSesi<?= $h['id']; ?>">
                                                                            <td colspan="6" class="p-3">
                                                                                <form method="POST" action="mahasiswa_bimbingan.php">
                                                                                    <input type="hidden" name="update_catatan_sesi" value="1">
                                                                                    <input type="hidden" name="bimbingan_id" value="<?= $h['id']; ?>">
                                                                                    <input type="hidden" name="mahasiswa_id" value="<?= $mhs['id']; ?>">
                                                                                    <div class="fw-bold mb-2 small text-dark"><i class="bi bi-pencil-square text-primary me-1"></i>Perbarui Catatan Sesi (<?= htmlspecialchars($h['bab_pembahasan']); ?>)</div>
                                                                                    <div class="mb-2">
                                                                                        <textarea name="catatan_dosen" class="form-control form-control-sm" rows="2" required><?= htmlspecialchars($h['catatan_dosen']); ?></textarea>
                                                                                    </div>
                                                                                    <div class="row g-2 align-items-center">
                                                                                        <div class="col-md-4">
                                                                                            <label class="form-label small mb-0">Nilai (%):</label>
                                                                                            <input type="number" name="nilai_progres" class="form-control form-control-sm" min="0" max="100" value="<?= (int)$h['nilai_progres']; ?>" required>
                                                                                        </div>
                                                                                        <div class="col-md-4">
                                                                                            <label class="form-label small mb-0">Status:</label>
                                                                                            <select name="status_sesi" class="form-select form-select-sm">
                                                                                                <option value="selesai" <?= ($h['status'] === 'selesai') ? 'selected' : ''; ?>>Selesai</option>
                                                                                                <option value="revisi" <?= ($h['status'] === 'revisi') ? 'selected' : ''; ?>>Revisi</option>
                                                                                                <option value="disetujui" <?= ($h['status'] === 'disetujui') ? 'selected' : ''; ?>>Disetujui</option>
                                                                                            </select>
                                                                                        </div>
                                                                                        <div class="col-md-4 text-end mt-3">
                                                                                            <button type="submit" class="btn btn-sm btn-primary">Simpan</button>
                                                                                        </div>
                                                                                    </div>
                                                                                </form>
                                                                            </td>
                                                                        </tr>
                                                                    <?php endforeach; ?>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Tutup</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- MODAL RESET MAHASISWA OLEH DOSEN -->
                                    <div class="modal fade" id="modalResetDosen<?= $mhs['id']; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-dialog-centered text-start">
                                            <form method="POST" action="mahasiswa_bimbingan.php" class="modal-content border-0 shadow">
                                                <input type="hidden" name="aksi_dosen_reset_mhs" value="1">
                                                <input type="hidden" name="mahasiswa_id" value="<?= $mhs['id']; ?>">

                                                <div class="modal-header bg-danger text-white">
                                                    <h5 class="modal-title fw-bold">
                                                        <i class="bi bi-exclamation-triangle-fill me-2"></i>Reset Bimbingan Mahasiswa
                                                    </h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>

                                                <div class="modal-body p-4">
                                                    <div class="p-3 bg-light rounded-3 border mb-3">
                                                        <div class="small text-muted">Mahasiswa Bimbingan:</div>
                                                        <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($mhs['nama_lengkap']); ?></div>
                                                        <div class="small text-muted">NIM: <?= htmlspecialchars($mhs['nim']); ?> • Kelas: <?= htmlspecialchars($mhs['kelas'] ?? '-'); ?></div>
                                                    </div>

                                                    <div class="mb-3">
                                                        <label class="form-label fw-bold text-dark mb-2">Pilih Aksi Reset:</label>
                                                        <div class="form-check mb-2">
                                                            <input class="form-check-input" type="radio" name="tipe_reset" id="reset_lepas_<?= $mhs['id']; ?>" value="lepas_dan_reset" checked>
                                                            <label class="form-check-label" for="reset_lepas_<?= $mhs['id']; ?>">
                                                                <strong class="text-danger">Reset Total & Lepaskan Mahasiswa</strong>
                                                                <small class="d-block text-muted">Mahasiswa akan <strong>terhapus dari daftar bimbingan Anda</strong> dan kembali ke status belum diplot.</small>
                                                            </label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="tipe_reset" id="reset_progres_<?= $mhs['id']; ?>" value="reset_progres_saja">
                                                            <label class="form-check-label" for="reset_progres_<?= $mhs['id']; ?>">
                                                                <strong class="text-primary">Reset Capaian Progres Saja (0%)</strong>
                                                                <small class="d-block text-muted">Mahasiswa tetap dibimbing oleh Anda, tetapi seluruh catatan progres dibersihkan ke 0%.</small>
                                                            </label>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="modal-footer bg-light">
                                                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                                                    <button type="submit" class="btn btn-danger fw-bold">
                                                        <i class="bi bi-arrow-counterclockwise me-1"></i> Konfirmasi Reset
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php
require_once '../templates/footer.php';
?>
