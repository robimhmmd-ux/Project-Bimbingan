<?php
// admin/monitoring_kelulusan.php - Monitoring & Evaluasi Kelulusan Mahasiswa Skripsi
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Proteksi akses role admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

require_once '../config/database.php';

$success_msg = '';
$error_msg   = '';

// =========================================================================
// PROSES PEMBARUAN STATUS KELULUSAN & NILAI DARI MONITORING
// =========================================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_status') {
        $mahasiswa_id     = (int)($_POST['mahasiswa_id'] ?? 0);
        $status_kelulusan = $_POST['status_kelulusan'] ?? 'proses_bimbingan';
        $nilai_evaluasi   = isset($_POST['nilai_evaluasi']) && $_POST['nilai_evaluasi'] !== '' ? (int)$_POST['nilai_evaluasi'] : null;

        if ($mahasiswa_id <= 0) {
            $error_msg = 'ID Mahasiswa tidak valid.';
        } else {
            try {
                $pdo->beginTransaction();

                // Update status kelulusan
                $stmtSt = $pdo->prepare("UPDATE mahasiswa SET status_kelulusan = :st WHERE id = :mid");
                $stmtSt->execute([':st' => $status_kelulusan, ':mid' => $mahasiswa_id]);

                // Jika nilai evaluasi diinput
                if ($nilai_evaluasi !== null) {
                    $nilai_evaluasi = max(0, min(100, $nilai_evaluasi));
                    $stmtB = $pdo->prepare("SELECT id FROM pengajuan_bimbingan WHERE mahasiswa_id = :mid ORDER BY id DESC LIMIT 1");
                    $stmtB->execute([':mid' => $mahasiswa_id]);
                    $lastB = $stmtB->fetch();

                    if ($lastB) {
                        $stmtUpN = $pdo->prepare("UPDATE pengajuan_bimbingan SET nilai_progres = :np WHERE id = :bid");
                        $stmtUpN->execute([':np' => $nilai_evaluasi, ':bid' => $lastB['id']]);
                    }
                }

                $pdo->commit();
                $success_msg = "Status kelulusan akademik mahasiswa berhasil diperbarui!";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error_msg = "Gagal memperbarui status: " . $e->getMessage();
            }
        }
    } elseif ($action === 'reset_all' || $action === 'reset_siap_sidang') {
        // =====================================================================
        // PROSES RESET PROGRESS KESELURUHAN (GLOBAL / PER KELAS / SIAP SIDANG)
        // =====================================================================
        $confirm_text   = strtoupper(trim($_POST['confirm_text'] ?? ''));
        $target_scope   = $_POST['target_scope'] ?? 'all';
        $reset_mode     = $_POST['reset_mode'] ?? 'soft';
        $reset_plotting = isset($_POST['reset_plotting']) && $_POST['reset_plotting'] == '1';
        $notify_mhs     = isset($_POST['notify_mhs']) && $_POST['notify_mhs'] == '1';

        // Jika reset_mode === 'hard', otomatis lepaskan plotting pembimbing dan bersihkan judul skripsi
        if ($reset_mode === 'hard') {
            $reset_plotting = true;
        }

        // Jika dipicu khusus dari modal Reset Mahasiswa Siap Sidang
        if ($action === 'reset_siap_sidang') {
            $target_scope = 'siap_sidang';
        }

        if ($confirm_text !== 'RESET') {
            $error_msg = "Konfirmasi keamanan tidak sesuai. Harap ketik 'RESET' untuk mengeksekusi reset progress.";
        } else {
            try {
                // Ambil daftar target mahasiswa
                if ($target_scope === 'siap_sidang') {
                    $stmtTarget = $pdo->query("SELECT id, user_id, nama_lengkap, kelas FROM mahasiswa WHERE status_kelulusan = 'siap_sidang'");
                } elseif ($target_scope !== 'all') {
                    $stmtTarget = $pdo->prepare("SELECT id, user_id, nama_lengkap, kelas FROM mahasiswa WHERE kelas = :kls");
                    $stmtTarget->execute([':kls' => $target_scope]);
                } else {
                    $stmtTarget = $pdo->query("SELECT id, user_id, nama_lengkap, kelas FROM mahasiswa");
                }
                $targetList = $stmtTarget->fetchAll();

                if (empty($targetList)) {
                    $error_msg = ($target_scope === 'siap_sidang')
                        ? "Tidak ada data mahasiswa berstatus Siap Sidang untuk di-reset saat ini."
                        : "Tidak ditemukan data mahasiswa pada lingkup target yang dipilih.";
                } else {
                    $mhs_ids = array_column($targetList, 'id');
                    $user_ids = array_filter(array_column($targetList, 'user_id'));
                    $placeholders = implode(',', array_fill(0, count($mhs_ids), '?'));

                    $pdo->beginTransaction();

                    if ($target_scope === 'all') {
                        if ($reset_mode === 'hard') {
                            // Hard Reset Global: Bersihkan seluruh riwayat bimbingan, logbook, sidang, dan lepaskan seluruh plotting pembimbing
                            $pdo->exec("DELETE FROM logbook_bimbingan");
                            $pdo->exec("DELETE FROM sidang_skripsi");
                            $pdo->exec("DELETE FROM pengajuan_bimbingan");
                            $pdo->exec("UPDATE mahasiswa SET status_kelulusan = 'proses_bimbingan', dosen_pembimbing_id = NULL, status_pembimbing = 'belum_diplot', judul_skripsi = 'Belum Ditentukan'");

                            $mode_label = "Reset Total Keseluruhan (Riwayat bimbingan, sidang, judul dikosongkan, dan plotting dosen pembimbing dilepas/terhapus dari bagian dosen)";
                        } else {
                            // Soft Reset Global: Set seluruh status bimbingan ke revisi & nilai ke 0%
                            if ($reset_plotting) {
                                $pdo->exec("UPDATE mahasiswa SET status_kelulusan = 'proses_bimbingan', dosen_pembimbing_id = NULL, status_pembimbing = 'belum_diplot', judul_skripsi = 'Belum Ditentukan'");
                            } else {
                                $pdo->exec("UPDATE mahasiswa SET status_kelulusan = 'proses_bimbingan'");
                            }
                            $pdo->exec("UPDATE pengajuan_bimbingan SET status = 'revisi', nilai_progres = 0");
                            $pdo->exec("UPDATE logbook_bimbingan SET status_bab = 'revisi'");
                            $pdo->exec("UPDATE sidang_skripsi SET status_sidang = 'pengajuan', nilai_penguji_1 = NULL, nilai_penguji_2 = NULL, nilai_penguji_3 = NULL, nilai_akhir = NULL, tanggal_sidang = NULL, jam_sidang = NULL, ruangan = NULL");

                            $mode_label = "Soft Reset Keseluruhan (Status kelulusan kembali ke 'proses_bimbingan' dan capaian bab dinolkan" . ($reset_plotting ? ", plotting pembimbing dilepas" : "") . ")";
                        }
                    } else {
                        // Lingkup Siap Sidang atau per Kelas
                        if ($reset_mode === 'hard') {
                            $stmtDelLog = $pdo->prepare("DELETE FROM logbook_bimbingan WHERE mahasiswa_id IN ($placeholders)");
                            $stmtDelLog->execute($mhs_ids);

                            $stmtDelSid = $pdo->prepare("DELETE FROM sidang_skripsi WHERE mahasiswa_id IN ($placeholders)");
                            $stmtDelSid->execute($mhs_ids);

                            $stmtDelBim = $pdo->prepare("DELETE FROM pengajuan_bimbingan WHERE mahasiswa_id IN ($placeholders)");
                            $stmtDelBim->execute($mhs_ids);

                            if ($reset_plotting) {
                                $stmtUpMhs = $pdo->prepare("UPDATE mahasiswa SET status_kelulusan = 'proses_bimbingan', dosen_pembimbing_id = NULL, status_pembimbing = 'belum_diplot', judul_skripsi = 'Belum Ditentukan' WHERE id IN ($placeholders)");
                            } else {
                                $stmtUpMhs = $pdo->prepare("UPDATE mahasiswa SET status_kelulusan = 'proses_bimbingan' WHERE id IN ($placeholders)");
                            }
                            $stmtUpMhs->execute($mhs_ids);

                            $mode_label = ($target_scope === 'siap_sidang') 
                                ? "Reset Total Mahasiswa Siap Sidang (Data bimbingan dibersihkan" . ($reset_plotting ? " & plotting dosen dilepas" : "") . ")"
                                : "Reset Total Kelas" . ($reset_plotting ? " (Plotting pembimbing dilepas)" : "");
                        } else {
                            if ($reset_plotting) {
                                $stmtUpMhs = $pdo->prepare("UPDATE mahasiswa SET status_kelulusan = 'proses_bimbingan', dosen_pembimbing_id = NULL, status_pembimbing = 'belum_diplot', judul_skripsi = 'Belum Ditentukan' WHERE id IN ($placeholders)");
                            } else {
                                $stmtUpMhs = $pdo->prepare("UPDATE mahasiswa SET status_kelulusan = 'proses_bimbingan' WHERE id IN ($placeholders)");
                            }
                            $stmtUpMhs->execute($mhs_ids);

                            $stmtUpBim = $pdo->prepare("UPDATE pengajuan_bimbingan SET status = 'revisi', nilai_progres = 0 WHERE mahasiswa_id IN ($placeholders)");
                            $stmtUpBim->execute($mhs_ids);

                            $stmtUpLog = $pdo->prepare("UPDATE logbook_bimbingan SET status_bab = 'revisi' WHERE mahasiswa_id IN ($placeholders)");
                            $stmtUpLog->execute($mhs_ids);

                            $stmtUpSid = $pdo->prepare("UPDATE sidang_skripsi SET status_sidang = 'pengajuan', nilai_penguji_1 = NULL, nilai_penguji_2 = NULL, nilai_penguji_3 = NULL, nilai_akhir = NULL, tanggal_sidang = NULL, jam_sidang = NULL, ruangan = NULL WHERE mahasiswa_id IN ($placeholders)");
                            $stmtUpSid->execute($mhs_ids);

                            $mode_label = ($target_scope === 'siap_sidang')
                                ? "Soft Reset Mahasiswa Siap Sidang (Status Kembali ke Proses Bimbingan & Nilai 0%)"
                                : "Soft Reset Kelas (Nilai & Status di-set 0%)";
                        }
                    }

                    // Notifikasi otomatis ke dashboard mahasiswa terdampak
                    if ($notify_mhs && !empty($user_ids)) {
                        $pesan_notif = ($target_scope === 'siap_sidang')
                            ? 'Administrator akademik telah mereset status kesiapan sidang Anda kembali ke proses bimbingan untuk evaluasi ulang.'
                            : 'Administrator akademik telah mereset status evaluasi & progres skripsi untuk periode penilaian baru. Silakan konsultasi kembali dengan dosen pembimbing.';
                        
                        $stmtNotif = $pdo->prepare("
                            INSERT INTO notifikasi (user_id, judul, pesan, tipe, link_tujuan) 
                            VALUES (:uid, 'Reset Status Skripsi', :pesan, 'info', 'index.php')
                        ");
                        foreach ($user_ids as $uid) {
                            $stmtNotif->execute([':uid' => $uid, ':pesan' => $pesan_notif]);
                        }
                    }

                    $pdo->commit();
                    if ($target_scope === 'siap_sidang') {
                        $scope_name = "Mahasiswa Siap Sidang (" . count($mhs_ids) . " orang)";
                    } elseif ($target_scope === 'all') {
                        $scope_name = "Seluruh Mahasiswa (" . count($mhs_ids) . " orang)";
                    } else {
                        $scope_name = "Kelas {$target_scope} (" . count($mhs_ids) . " orang)";
                    }
                    $success_msg = "Berhasil me-reset progress untuk {$scope_name}. Mode: {$mode_label}.";
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error_msg = "Gagal me-reset progress: " . $e->getMessage();
            }
        }
    } elseif ($action === 'reset_single') {
        // =====================================================================
        // PROSES RESET PROGRESS MAHASISWA TUNGGAL
        // =====================================================================
        $mahasiswa_id   = (int)($_POST['mahasiswa_id'] ?? 0);
        $reset_mode     = $_POST['reset_mode'] ?? 'soft';
        $reset_plotting = isset($_POST['reset_plotting']) && $_POST['reset_plotting'] == '1';
        $notify_mhs     = isset($_POST['notify_mhs']) && $_POST['notify_mhs'] == '1';

        // Jika reset_mode === 'hard', otomatis lepaskan plotting pembimbing dan bersihkan judul skripsi
        if ($reset_mode === 'hard') {
            $reset_plotting = true;
        }

        if ($mahasiswa_id <= 0) {
            $error_msg = "ID Mahasiswa tidak valid.";
        } else {
            try {
                $stmtMhs = $pdo->prepare("SELECT id, user_id, nama_lengkap, nim FROM mahasiswa WHERE id = :mid LIMIT 1");
                $stmtMhs->execute([':mid' => $mahasiswa_id]);
                $mhsTarget = $stmtMhs->fetch();

                if (!$mhsTarget) {
                    $error_msg = "Data mahasiswa tidak ditemukan.";
                } else {
                    $pdo->beginTransaction();

                    if ($reset_mode === 'hard') {
                        $stmtDelLog = $pdo->prepare("DELETE FROM logbook_bimbingan WHERE mahasiswa_id = :mid");
                        $stmtDelLog->execute([':mid' => $mahasiswa_id]);

                        $stmtDelSid = $pdo->prepare("DELETE FROM sidang_skripsi WHERE mahasiswa_id = :mid");
                        $stmtDelSid->execute([':mid' => $mahasiswa_id]);

                        $stmtDelBim = $pdo->prepare("DELETE FROM pengajuan_bimbingan WHERE mahasiswa_id = :mid");
                        $stmtDelBim->execute([':mid' => $mahasiswa_id]);

                        if ($reset_plotting) {
                            $stmtUpMhs = $pdo->prepare("UPDATE mahasiswa SET status_kelulusan = 'proses_bimbingan', dosen_pembimbing_id = NULL, status_pembimbing = 'belum_diplot', judul_skripsi = 'Belum Ditentukan' WHERE id = :mid");
                        } else {
                            $stmtUpMhs = $pdo->prepare("UPDATE mahasiswa SET status_kelulusan = 'proses_bimbingan' WHERE id = :mid");
                        }
                        $stmtUpMhs->execute([':mid' => $mahasiswa_id]);

                        $mode_label = "Reset Total Keseluruhan (0% data pengajuan, judul dikosongkan" . ($reset_plotting ? ", dan dosen pembimbing dilepas/terhapus dari bagian dosen" : "") . ")";
                    } else {
                        if ($reset_plotting) {
                            $stmtUpMhs = $pdo->prepare("UPDATE mahasiswa SET status_kelulusan = 'proses_bimbingan', dosen_pembimbing_id = NULL, status_pembimbing = 'belum_diplot', judul_skripsi = 'Belum Ditentukan' WHERE id = :mid");
                        } else {
                            $stmtUpMhs = $pdo->prepare("UPDATE mahasiswa SET status_kelulusan = 'proses_bimbingan' WHERE id = :mid");
                        }
                        $stmtUpMhs->execute([':mid' => $mahasiswa_id]);

                        $stmtUpBim = $pdo->prepare("UPDATE pengajuan_bimbingan SET status = 'revisi', nilai_progres = 0 WHERE mahasiswa_id = :mid");
                        $stmtUpBim->execute([':mid' => $mahasiswa_id]);

                        $stmtUpLog = $pdo->prepare("UPDATE logbook_bimbingan SET status_bab = 'revisi' WHERE mahasiswa_id = :mid");
                        $stmtUpLog->execute([':mid' => $mahasiswa_id]);

                        $stmtUpSid = $pdo->prepare("UPDATE sidang_skripsi SET status_sidang = 'pengajuan', nilai_penguji_1 = NULL, nilai_penguji_2 = NULL, nilai_penguji_3 = NULL, nilai_akhir = NULL, tanggal_sidang = NULL, jam_sidang = NULL, ruangan = NULL WHERE mahasiswa_id = :mid");
                        $stmtUpSid->execute([':mid' => $mahasiswa_id]);

                        $mode_label = "Soft Reset (Nilai & Status di-set 0%" . ($reset_plotting ? ", dosen pembimbing dilepas" : "") . ")";
                    }

                    if ($notify_mhs && !empty($mhsTarget['user_id'])) {
                        $stmtNotif = $pdo->prepare("
                            INSERT INTO notifikasi (user_id, judul, pesan, tipe, link_tujuan) 
                            VALUES (:uid, 'Reset Progress Bimbingan Skripsi', 'Administrator akademik telah mereset status evaluasi & progres skripsi Anda. Silakan hubungi dosen pembimbing untuk konsultasi tahapan selanjutnya.', 'info', 'index.php')
                        ");
                        $stmtNotif->execute([':uid' => $mhsTarget['user_id']]);
                    }

                    $pdo->commit();
                    $success_msg = "Berhasil me-reset progress untuk mahasiswa: {$mhsTarget['nama_lengkap']} ({$mhsTarget['nim']}). Mode: {$mode_label}.";
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error_msg = "Gagal me-reset progress mahasiswa: " . $e->getMessage();
            }
        }
    }
}

// =========================================================================
// FILTER & QUERY DATA MONITORING
// =========================================================================
$filter_status = $_GET['status'] ?? 'all';
$filter_kelas  = $_GET['kelas'] ?? 'all';
$filter_dosen  = (int)($_GET['dosen_id'] ?? 0);
$search_q      = trim($_GET['q'] ?? '');

$where = ["1=1"];
$params = [];

if ($filter_status !== 'all' && in_array($filter_status, ['proses_bimbingan', 'siap_sidang', 'lulus'])) {
    $where[] = "m.status_kelulusan = :st";
    $params[':st'] = $filter_status;
}

if ($filter_kelas !== 'all' && in_array($filter_kelas, ['TI-1A-2025', 'TI-1B-2025', 'TI-1C-2025'])) {
    $where[] = "m.kelas = :kelas";
    $params[':kelas'] = $filter_kelas;
}

if ($filter_dosen > 0) {
    $where[] = "m.dosen_pembimbing_id = :did";
    $params[':did'] = $filter_dosen;
}

if (!empty($search_q)) {
    $where[] = "(m.nim LIKE :q OR m.nama_lengkap LIKE :q OR m.judul_skripsi LIKE :q OR m.kelas LIKE :q)";
    $params[':q'] = "%{$search_q}%";
}

$where_sql = implode(" AND ", $where);

// Query Mahasiswa dengan Progres Bimbingan, Logbook, dan Sidang
$queryMhs = "
    SELECT 
        m.*,
        u.username,
        d.nama_dosen,
        COALESCE(
            (SELECT nilai_progres FROM pengajuan_bimbingan WHERE mahasiswa_id = m.id ORDER BY id DESC LIMIT 1),
            0
        ) AS nilai_progres_terakhir,
        (SELECT COUNT(*) FROM pengajuan_bimbingan WHERE mahasiswa_id = m.id) AS total_bimbingan,
        (SELECT COUNT(*) FROM logbook_bimbingan WHERE mahasiswa_id = m.id) AS total_acc_bab,
        (SELECT status_bab FROM logbook_bimbingan WHERE mahasiswa_id = m.id ORDER BY id DESC LIMIT 1) AS status_bab_terakhir,
        (SELECT tanggal_acc FROM logbook_bimbingan WHERE mahasiswa_id = m.id ORDER BY id DESC LIMIT 1) AS tgl_acc_terakhir,
        (SELECT status_sidang FROM sidang_skripsi WHERE mahasiswa_id = m.id ORDER BY id DESC LIMIT 1) AS status_sidang_terakhir
    FROM mahasiswa m
    INNER JOIN users u ON m.user_id = u.id
    LEFT JOIN dosen d ON m.dosen_pembimbing_id = d.id
    WHERE {$where_sql}
    ORDER BY 
        CASE m.status_kelulusan 
            WHEN 'siap_sidang' THEN 1 
            WHEN 'proses_bimbingan' THEN 2 
            WHEN 'lulus' THEN 3 
        END,
        m.kelas ASC,
        m.nama_lengkap ASC
";
$stmt = $pdo->prepare($queryMhs);
$stmt->execute($params);
$monitoring_list = $stmt->fetchAll();

// Metrik Keseluruhan
$total_mhs       = $pdo->query("SELECT COUNT(*) FROM mahasiswa")->fetchColumn();
$count_bimbingan = $pdo->query("SELECT COUNT(*) FROM mahasiswa WHERE status_kelulusan = 'proses_bimbingan'")->fetchColumn();
$count_siap      = $pdo->query("SELECT COUNT(*) FROM mahasiswa WHERE status_kelulusan = 'siap_sidang'")->fetchColumn();
$count_lulus     = $pdo->query("SELECT COUNT(*) FROM mahasiswa WHERE status_kelulusan = 'lulus'")->fetchColumn();

$persen_lulus = $total_mhs > 0 ? round(($count_lulus / $total_mhs) * 100) : 0;
$persen_siap  = $total_mhs > 0 ? round(($count_siap / $total_mhs) * 100) : 0;
$persen_bimb  = $total_mhs > 0 ? round(($count_bimbingan / $total_mhs) * 100) : 0;

// Daftar Dosen untuk Filter
$dosen_filter = $pdo->query("SELECT id, nama_dosen FROM dosen ORDER BY nama_dosen ASC")->fetchAll();

// Daftar Kelas untuk Filter & Lingkup Reset
$kelas_list = $pdo->query("SELECT DISTINCT kelas FROM mahasiswa WHERE kelas IS NOT NULL AND kelas != '' ORDER BY kelas ASC")->fetchAll(PDO::FETCH_COLUMN);

$page_title   = "Monitoring Kelulusan Mahasiswa";
$current_page = "monitoring";

require_once '../templates/header.php';
require_once '../templates/sidebar_admin.php';
?>

<!-- Hero Banner Header -->
<div class="hero-banner d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
    <div>
        <div class="hero-tag">
            <i class="bi bi-mortarboard-fill"></i>
            <span>Monitoring Akademik & Kelulusan (3 Kelas)</span>
        </div>
        <h2 class="fw-bold text-white mb-1">Monitoring Kelulusan & Sidang</h2>
        <p class="text-white-50 small mb-0">Pantau progres tahapan bimbingan skripsi, verifikasi kesiapan sidang, dan evaluasi hasil kelulusan mahasiswa.</p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <button type="button" class="btn btn-danger text-white shadow-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalResetProgressAll">
            <i class="bi bi-arrow-counterclockwise me-1"></i> Reset Progress Keseluruhan
        </button>
        <a href="jadwal_sidang.php" class="btn btn-warning text-dark shadow-sm fw-bold">
            <i class="bi bi-calendar2-check-fill me-1"></i> Penjadwalan Sidang (3 Penguji)
        </a>
    </div>
</div>

<!-- Alert Notifikasi -->
<?php if (!empty($success_msg)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill me-2 fs-5"></i>
        <div><?= htmlspecialchars($success_msg); ?></div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (!empty($error_msg)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
        <div><?= htmlspecialchars($error_msg); ?></div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Stat Bar Corong Kelulusan -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="metric-card p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="small text-muted fw-bold text-uppercase">1. Bimbingan Aktif</span>
                    <h3 class="fw-bold text-secondary mb-0 mt-1"><?= $count_bimbingan; ?></h3>
                    <small class="text-muted"><?= $persen_bimb; ?>% dari total angkatan</small>
                </div>
                <div class="metric-icon-box bg-secondary-subtle text-secondary">
                    <i class="bi bi-hourglass-split"></i>
                </div>
            </div>
            <div class="progress mt-3" style="height: 4px;">
                <div class="progress-bar bg-secondary" style="width: <?= $persen_bimb; ?>%"></div>
            </div>
            <div class="mt-3 pt-2 border-top">
                <a href="monitoring_kelulusan.php?status=proses_bimbingan" class="text-secondary small text-decoration-none fw-semibold">
                    <i class="bi bi-funnel me-1"></i>Filter Data (<?= $count_bimbingan; ?>)
                </a>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="metric-card p-3 border-start border-4 border-primary">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="small text-primary fw-bold text-uppercase">2. Siap Sidang (ACC 5 Bab)</span>
                    <h3 class="fw-bold text-primary mb-0 mt-1"><?= $count_siap; ?></h3>
                    <small class="text-muted"><?= $persen_siap; ?>% siap sidang</small>
                </div>
                <div class="metric-icon-box bg-primary-subtle text-primary">
                    <i class="bi bi-award-fill"></i>
                </div>
            </div>
            <div class="progress mt-3" style="height: 4px;">
                <div class="progress-bar bg-primary" style="width: <?= $persen_siap; ?>%"></div>
            </div>
            <div class="mt-3 pt-2 border-top d-flex justify-content-between align-items-center">
                <a href="monitoring_kelulusan.php?status=siap_sidang" class="text-primary small text-decoration-none fw-semibold">
                    <i class="bi bi-funnel me-1"></i>Filter Data (<?= $count_siap; ?>)
                </a>
                <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2 fw-semibold" 
                        data-bs-toggle="modal" 
                        data-bs-target="#modalResetSiapSidang" 
                        title="Reset seluruh mahasiswa yang berstatus Siap Sidang ke Proses Bimbingan">
                    <i class="bi bi-arrow-counterclockwise me-1"></i>Reset Siap Sidang
                </button>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="metric-card p-3 border-start border-4 border-success">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="small text-success fw-bold text-uppercase">3. Telah Lulus Sidang</span>
                    <h3 class="fw-bold text-success mb-0 mt-1"><?= $count_lulus; ?></h3>
                    <small class="text-muted"><?= $persen_lulus; ?>% kelulusan resmi</small>
                </div>
                <div class="metric-icon-box bg-success-subtle text-success">
                    <i class="bi bi-check2-all"></i>
                </div>
            </div>
            <div class="progress mt-3" style="height: 4px;">
                <div class="progress-bar bg-success" style="width: <?= $persen_lulus; ?>%"></div>
            </div>
            <div class="mt-3 pt-2 border-top">
                <a href="monitoring_kelulusan.php?status=lulus" class="text-success small text-decoration-none fw-semibold">
                    <i class="bi bi-funnel me-1"></i>Filter Data (<?= $count_lulus; ?>)
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Progress Bar Tingkat Penyelesaian Angkatan -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
                <h6 class="fw-bold text-dark mb-0"><i class="bi bi-pie-chart-fill text-primary me-2"></i>Tingkat Penyelesaian Angkatan</h6>
                <small class="text-muted">Komposisi status mahasiswa: Lulus (<?= $count_lulus; ?>), Siap Sidang (<?= $count_siap; ?>), Bimbingan (<?= $count_bimbingan; ?>)</small>
            </div>
            <span class="fw-bold text-success fs-5"><?= $persen_lulus; ?>% Lulus</span>
        </div>
        <div class="progress" style="height: 12px; border-radius: 9999px;">
            <div class="progress-bar bg-success" role="progressbar" style="width: <?= $persen_lulus; ?>%" title="Lulus: <?= $count_lulus; ?>"></div>
            <div class="progress-bar bg-primary" role="progressbar" style="width: <?= $persen_siap; ?>%" title="Siap Sidang: <?= $count_siap; ?>"></div>
            <div class="progress-bar bg-secondary opacity-50" role="progressbar" style="width: <?= $persen_bimb; ?>%" title="Bimbingan: <?= $count_bimbingan; ?>"></div>
        </div>
    </div>
</div>

<!-- Filter & Pencarian -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-3">
        <form method="GET" action="monitoring_kelulusan.php" class="row g-2 align-items-center">
            <div class="col-md-3">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0 ps-0" name="q" value="<?= htmlspecialchars($search_q); ?>" placeholder="Cari Mahasiswa...">
                </div>
            </div>
            <!-- Filter Kelas -->
            <div class="col-md-2">
                <select name="kelas" class="form-select" onchange="this.form.submit()">
                    <option value="all" <?= ($filter_kelas === 'all') ? 'selected' : ''; ?>>-- Semua Kelas --</option>
                    <?php foreach ($kelas_list as $kls): ?>
                        <option value="<?= htmlspecialchars($kls); ?>" <?= ($filter_kelas === $kls) ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($kls); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Filter Status -->
            <div class="col-md-2">
                <select name="status" class="form-select" onchange="this.form.submit()">
                    <option value="all" <?= ($filter_status === 'all') ? 'selected' : ''; ?>>-- Semua Status --</option>
                    <option value="siap_sidang" <?= ($filter_status === 'siap_sidang') ? 'selected' : ''; ?>>Siap Sidang</option>
                    <option value="proses_bimbingan" <?= ($filter_status === 'proses_bimbingan') ? 'selected' : ''; ?>>Bimbingan</option>
                    <option value="lulus" <?= ($filter_status === 'lulus') ? 'selected' : ''; ?>>Lulus</option>
                </select>
            </div>
            <!-- Filter Dosen -->
            <div class="col-md-3">
                <select name="dosen_id" class="form-select" onchange="this.form.submit()">
                    <option value="0">-- Semua Dosen Pembimbing --</option>
                    <?php foreach ($dosen_filter as $df): ?>
                        <option value="<?= $df['id']; ?>" <?= ($filter_dosen == $df['id']) ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($df['nama_dosen']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
                <?php if ($filter_status !== 'all' || $filter_kelas !== 'all' || $filter_dosen > 0 || !empty($search_q)): ?>
                    <a href="monitoring_kelulusan.php" class="btn btn-outline-secondary" title="Reset Filter"><i class="bi bi-arrow-counterclockwise"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Tabel Monitoring Kelulusan -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2">
            <span class="p-2 rounded bg-primary-subtle text-primary">
                <i class="bi bi-clipboard2-data-fill"></i>
            </span>
            <div>
                <h6 class="fw-bold mb-0 text-dark">Daftar Monitoring Mahasiswa Tugas Akhir</h6>
                <small class="text-muted">Total <?= count($monitoring_list); ?> mahasiswa dalam pantauan</small>
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3" style="width: 40px;">No</th>
                        <th>Mahasiswa</th>
                        <th>Kelas</th>
                        <th>Judul Penelitian Skripsi</th>
                        <th>Dosen Pembimbing</th>
                        <th class="text-center" style="width: 130px;">Capaian Nilai</th>
                        <th class="text-center" style="width: 140px;">Status Akademik</th>
                        <th class="text-center" style="width: 140px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($monitoring_list)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-5">
                                <i class="bi bi-inbox fs-2 d-block text-secondary mb-2"></i>
                                Tidak ada data mahasiswa sesuai filter.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $no = 1;
                        foreach ($monitoring_list as $row): 
                            $nilai = (int)$row['nilai_progres_terakhir'];
                            $initial = strtoupper(substr($row['nama_lengkap'], 0, 1));
                            $st_sidang = $row['status_sidang_terakhir'] ?? null;
                        ?>
                            <tr>
                                <td class="ps-3 text-muted"><?= $no++; ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="avatar-initial bg-primary-subtle text-primary">
                                            <?= $initial; ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($row['nama_lengkap']); ?></div>
                                            <span class="font-monospace text-primary small">NIM: <?= htmlspecialchars($row['nim']); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                                        <?= htmlspecialchars($row['kelas'] ?? '-'); ?>
                                    </span>
                                </td>
                                <td>
                                    <small class="text-muted d-block text-truncate" style="max-width: 260px;" title="<?= htmlspecialchars($row['judul_skripsi']); ?>">
                                        <?= htmlspecialchars($row['judul_skripsi']); ?>
                                    </small>
                                </td>
                                <td>
                                    <?php if ($row['nama_dosen']): ?>
                                        <span class="text-dark small fw-semibold">
                                            <i class="bi bi-person-check-fill text-success me-1"></i><?= htmlspecialchars($row['nama_dosen']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">
                                            <i class="bi bi-exclamation-triangle me-1"></i>Belum Diplot
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="d-inline-block text-center">
                                        <span class="fw-bold fs-6 <?= ($nilai >= 80) ? 'text-success' : (($nilai >= 60) ? 'text-primary' : 'text-warning'); ?>">
                                            <?= $nilai; ?>%
                                        </span>
                                        <div class="progress" style="height: 6px; width: 85px;">
                                            <div class="progress-bar <?= ($nilai >= 80) ? 'bg-success' : (($nilai >= 60) ? 'bg-primary' : 'bg-warning'); ?>" 
                                                 style="width: <?= $nilai; ?>%"></div>
                                        </div>
                                        <small class="text-muted d-block mt-1" style="font-size: 0.72rem;">
                                            <?= $row['total_acc_bab']; ?> Bab ACC
                                        </small>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <?php if ($row['status_kelulusan'] === 'lulus'): ?>
                                        <span class="badge bg-success px-2 py-1"><i class="bi bi-check2-circle me-1"></i>Lulus</span>
                                    <?php elseif ($row['status_kelulusan'] === 'siap_sidang'): ?>
                                        <span class="badge bg-primary px-2 py-1"><i class="bi bi-award me-1"></i>Siap Sidang</span>
                                        <?php if ($st_sidang === 'dijadwalkan'): ?>
                                            <span class="badge bg-info-subtle text-info border border-info-subtle d-block mt-1" style="font-size: 0.68rem;">Sidang Terjadwal</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge bg-secondary px-2 py-1"><i class="bi bi-clock-history me-1"></i>Bimbingan</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm">
                                        <!-- Tombol Evaluasi Status Kelulusan -->
                                        <button type="button" class="btn btn-outline-success btn-evaluasi" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#modalEvaluasiKelulusan"
                                                data-id="<?= $row['id']; ?>"
                                                data-nama="<?= htmlspecialchars($row['nama_lengkap']); ?>"
                                                data-nim="<?= htmlspecialchars($row['nim']); ?>"
                                                data-status="<?= $row['status_kelulusan']; ?>"
                                                data-nilai="<?= $nilai; ?>"
                                                title="Evaluasi & Update Status Kelulusan">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                        <!-- Tombol Cepat Jadwalkan Sidang jika Siap Sidang -->
                                        <?php if ($row['status_kelulusan'] === 'siap_sidang'): ?>
                                            <a href="jadwal_sidang.php?mhs_id=<?= $row['id']; ?>" class="btn btn-outline-primary" title="Buka Penjadwalan Sidang (3 Penguji)">
                                                <i class="bi bi-calendar2-check"></i>
                                            </a>
                                        <?php endif; ?>
                                        <!-- Tombol Reset Progress Mahasiswa Tunggal -->
                                        <button type="button" class="btn btn-outline-danger btn-reset-single"
                                                data-bs-toggle="modal" 
                                                data-bs-target="#modalResetSingle"
                                                data-id="<?= $row['id']; ?>"
                                                data-nama="<?= htmlspecialchars($row['nama_lengkap']); ?>"
                                                data-nim="<?= htmlspecialchars($row['nim']); ?>"
                                                title="Reset Progress Mahasiswa Ini">
                                            <i class="bi bi-arrow-counterclockwise"></i>
                                        </button>
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

<!-- ========================================================================= -->
<!-- MODAL EVALUASI & UPDATE KELULUSAN -->
<!-- ========================================================================= -->
<div class="modal fade" id="modalEvaluasiKelulusan" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="monitoring_kelulusan.php" class="modal-content">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="mahasiswa_id" id="eval_mhs_id">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-award-fill me-2"></i>Evaluasi Kelulusan Mahasiswa</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="p-3 bg-light rounded-3 border mb-3">
                    <div class="small text-muted">Mahasiswa Terpilih:</div>
                    <div class="fw-bold text-dark fs-6" id="eval_nama"></div>
                    <div class="small text-muted font-monospace" id="eval_nim"></div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Status Kelulusan Akademik <span class="text-danger">*</span></label>
                    <select name="status_kelulusan" id="eval_status" class="form-select" required>
                        <option value="proses_bimbingan">Proses Bimbingan (Masih aktif bimbingan/revisi bab)</option>
                        <option value="siap_sidang">Siap Sidang (ACC seluruh bab skripsi)</option>
                        <option value="lulus">Lulus (Telah lulus ujian sidang skripsi)</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Nilai Progres Bimbingan Skripsi (0 - 100)</label>
                    <div class="input-group">
                        <input type="number" class="form-control" name="nilai_evaluasi" id="eval_nilai" min="0" max="100" placeholder="0 - 100">
                        <span class="input-group-text bg-white">%</span>
                    </div>
                    <div class="form-text">Persentase capaian progres penulisan skripsi.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Tutup</button>
                <button type="submit" class="btn btn-success"><i class="bi bi-check2-circle me-1"></i>Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL RESET PROGRESS KESELURUHAN MAHASISWA -->
<!-- ========================================================================= -->
<div class="modal fade" id="modalResetProgressAll" tabindex="-1" aria-labelledby="modalResetProgressAllLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST" action="monitoring_kelulusan.php" class="modal-content border-0 shadow-lg">
            <input type="hidden" name="action" value="reset_all">
            
            <div class="modal-header bg-danger text-white">
                <div class="d-flex align-items-center gap-2">
                    <div class="p-2 rounded bg-white bg-opacity-25">
                        <i class="bi bi-arrow-counterclockwise fs-4 text-white"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="modalResetProgressAllLabel">Reset Progress Keseluruhan Mahasiswa</h5>
                        <small class="text-white-50">Siklus Evaluasi Akademik & Pemulihan Capaian Bimbingan Skripsi</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-4">
                <!-- Alert Peringatan -->
                <div class="alert alert-danger d-flex align-items-start gap-3 mb-4 shadow-sm">
                    <i class="bi bi-exclamation-octagon-fill fs-3 text-danger flex-shrink-0 mt-1"></i>
                    <div>
                        <h6 class="fw-bold text-danger mb-1">Perhatian: Tindakan Ini Mengatur Ulang Capaian Skripsi</h6>
                        <p class="small text-danger mb-0">
                            Fitur ini digunakan oleh Administrator untuk mengulang periode evaluasi bimbingan skripsi. 
                            <strong>Akun login mahasiswa, data profil, dan plotting dosen pembimbing TETAP AMAN dan TIDAK AKAN TERHAPUS.</strong>
                        </p>
                    </div>
                </div>

                <!-- Pilihan Lingkup (Target Scope) -->
                <div class="mb-4">
                    <label class="form-label fw-bold text-dark mb-1">
                        <i class="bi bi-people-fill text-primary me-1"></i> Lingkup Target Mahasiswa:
                    </label>
                    <select name="target_scope" class="form-select form-select-lg">
                        <option value="all">Semua Mahasiswa (Seluruh Angkatan & Kelas - Total <?= $total_mhs; ?> Mahasiswa)</option>
                        <option value="siap_sidang">Khusus Mahasiswa Siap Sidang (Total <?= $count_siap; ?> Mahasiswa)</option>
                        <?php foreach ($kelas_list as $kls): ?>
                            <option value="<?= htmlspecialchars($kls); ?>">Hanya Kelas: <?= htmlspecialchars($kls); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Pilihan Mode Reset -->
                <div class="mb-4">
                    <label class="form-label fw-bold text-dark mb-2">
                        <i class="bi bi-sliders text-warning me-1"></i> Pilihan Mode Reset:
                    </label>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="card h-100 p-3 border-2 border-primary-subtle rounded-3 cursor-pointer shadow-sm" style="cursor: pointer;">
                                <div class="d-flex align-items-start gap-2">
                                    <input type="radio" name="reset_mode" value="soft" class="form-check-input mt-1" checked>
                                    <div>
                                        <div class="fw-bold text-primary mb-1">
                                            <i class="bi bi-arrow-repeat me-1"></i> 1. Soft Reset (Rekomendasi)
                                        </div>
                                        <p class="small text-muted mb-0">
                                            Status kelulusan dikembalikan ke <strong>Proses Bimbingan</strong>. Nilai progres diatur ke <strong>0%</strong> dan bab diset ke revisi. 
                                            <em>Arsip naskah draf bimbingan dan logbook tetap disimpan sebagai rekam jejak.</em>
                                        </p>
                                    </div>
                                </div>
                            </label>
                        </div>
                        <div class="col-md-6">
                            <label class="card h-100 p-3 border-2 border-danger-subtle rounded-3 cursor-pointer shadow-sm" style="cursor: pointer;">
                                <div class="d-flex align-items-start gap-2">
                                    <input type="radio" name="reset_mode" value="hard" class="form-check-input mt-1">
                                    <div>
                                        <div class="fw-bold text-danger mb-1">
                                            <i class="bi bi-trash3-fill me-1"></i> 2. Hard Reset (Bersih Total)
                                        </div>
                                        <p class="small text-muted mb-0">
                                            Menghapus seluruh riwayat pengajuan draf bimbingan, logbook bab, dan pendaftaran/jadwal sidang skripsi. 
                                            Mahasiswa memulai kembali dari pengajuan Bab 1 murni (0%).
                                        </p>
                                    </div>
                                </div>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Opsi Notifikasi & Plotting Pembimbing -->
                <div class="mb-4 p-3 bg-light rounded-3 border">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" name="reset_plotting" id="resetPlottingCheck" value="1" checked>
                        <label class="form-check-label fw-bold text-danger" for="resetPlottingCheck">
                            Lepaskan Plotting Dosen Pembimbing & Kosongkan Judul Skripsi
                        </label>
                        <small class="d-block text-muted">Mahasiswa kembali ke status "Belum Diplot" sehingga <strong>terhapus dari daftar bimbingan dosen</strong>.</small>
                    </div>
                    <hr class="my-2">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" name="notify_mhs" id="notifyMhsCheck" value="1" checked>
                        <label class="form-check-label fw-semibold text-dark" for="notifyMhsCheck">
                            Kirim Notifikasi Sistem Otomatis ke Mahasiswa yang Terdampak
                        </label>
                        <small class="d-block text-muted">Mahasiswa akan menerima pemberitahuan di akun mereka bahwa periode evaluasi skripsi telah di-reset oleh Admin.</small>
                    </div>
                </div>

                <!-- Konfirmasi Kata Kunci Keamanan -->
                <div class="p-3 bg-danger-subtle border border-danger-subtle rounded-3">
                    <label class="form-label fw-bold text-danger mb-1" for="confirmResetInput">
                        <i class="bi bi-shield-lock-fill me-1"></i> Konfirmasi Keamanan Administrator
                    </label>
                    <p class="small text-muted mb-2">Ketik kata <strong class="text-danger font-monospace">RESET</strong> pada kolom di bawah ini untuk mengaktifkan tombol eksekusi:</p>
                    <div class="input-group">
                        <span class="input-group-text bg-white text-danger fw-bold border-danger-subtle"><i class="bi bi-key-fill"></i></span>
                        <input type="text" class="form-control font-monospace border-danger-subtle" name="confirm_text" id="confirmResetInput" placeholder="Ketik RESET di sini..." autocomplete="off" required>
                    </div>
                </div>
            </div>

            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i> Batal
                </button>
                <button type="submit" class="btn btn-danger fw-bold shadow-sm" id="btnSubmitResetAll" disabled>
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Eksekusi Reset Sekarang
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL RESET KHUSUS MAHASISWA SIAP SIDANG -->
<!-- ========================================================================= -->
<div class="modal fade" id="modalResetSiapSidang" tabindex="-1" aria-labelledby="modalResetSiapSidangLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST" action="monitoring_kelulusan.php" class="modal-content border-0 shadow-lg">
            <input type="hidden" name="action" value="reset_siap_sidang">
            <input type="hidden" name="target_scope" value="siap_sidang">
            
            <div class="modal-header bg-danger text-white">
                <div class="d-flex align-items-center gap-2">
                    <div class="p-2 rounded bg-white bg-opacity-25">
                        <i class="bi bi-award-fill fs-4 text-white"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="modalResetSiapSidangLabel">Reset Mahasiswa Berstatus Siap Sidang</h5>
                        <small class="text-white-50">Kembalikan Mahasiswa Siap Sidang ke Tahapan Proses Bimbingan</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-4">
                <div class="alert alert-warning d-flex align-items-start gap-3 mb-4 shadow-sm">
                    <i class="bi bi-exclamation-triangle-fill fs-3 text-warning flex-shrink-0 mt-1"></i>
                    <div>
                        <h6 class="fw-bold text-dark mb-1">Konfirmasi Reset Kesiapan Sidang</h6>
                        <p class="small text-muted mb-0">
                            Terdapat <strong><?= $count_siap; ?> mahasiswa</strong> yang saat ini berstatus <strong>Siap Sidang (ACC 5 Bab)</strong>. 
                            Fitur ini akan mengembalikan status mereka ke <strong>Proses Bimbingan</strong> dan membatalkan pendaftaran/jadwal sidang jika evaluasi perlu diulang.
                        </p>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-bold text-dark mb-2">Pilihan Mode Reset:</label>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="card h-100 p-3 border-2 border-primary-subtle rounded-3 cursor-pointer shadow-sm" style="cursor: pointer;">
                                <div class="d-flex align-items-start gap-2">
                                    <input type="radio" name="reset_mode" value="soft" class="form-check-input mt-1" checked>
                                    <div>
                                        <div class="fw-bold text-primary mb-1">
                                            <i class="bi bi-arrow-repeat me-1"></i> 1. Soft Reset (Rekomendasi)
                                        </div>
                                        <p class="small text-muted mb-0">
                                            Status dikembalikan ke <strong>Proses Bimbingan</strong> dan status bab diset ke revisi (nilai 0%). Pendaftaran/jadwal sidang dibersihkan. <em>Arsip naskah draf bimbingan tetap aman disimpan.</em>
                                        </p>
                                    </div>
                                </div>
                            </label>
                        </div>
                        <div class="col-md-6">
                            <label class="card h-100 p-3 border-2 border-danger-subtle rounded-3 cursor-pointer shadow-sm" style="cursor: pointer;">
                                <div class="d-flex align-items-start gap-2">
                                    <input type="radio" name="reset_mode" value="hard" class="form-check-input mt-1">
                                    <div>
                                        <div class="fw-bold text-danger mb-1">
                                            <i class="bi bi-trash3-fill me-1"></i> 2. Hard Reset (Bersih Total)
                                        </div>
                                        <p class="small text-muted mb-0">
                                            Menghapus seluruh riwayat bimbingan, logbook, dan pendaftaran sidang mahasiswa siap sidang kembali ke pengajuan Bab 1 murni (0%).
                                        </p>
                                    </div>
                                </div>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="mb-4 p-3 bg-light rounded-3 border">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" name="reset_plotting" id="resetSiapPlottingCheck" value="1" checked>
                        <label class="form-check-label fw-bold text-danger" for="resetSiapPlottingCheck">
                            Lepaskan Plotting Dosen Pembimbing & Kosongkan Judul Skripsi
                        </label>
                        <small class="d-block text-muted">Mahasiswa kembali ke status "Belum Diplot" sehingga <strong>terhapus dari daftar bimbingan dosen</strong>.</small>
                    </div>
                    <hr class="my-2">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" name="notify_mhs" id="notifySiapSidangCheck" value="1" checked>
                        <label class="form-check-label fw-semibold text-dark" for="notifySiapSidangCheck">
                            Kirim Notifikasi Otomatis ke Mahasiswa Terkait
                        </label>
                        <small class="d-block text-muted">Mahasiswa akan menerima pemberitahuan bahwa status kesiapan sidang mereka telah dikembalikan ke proses bimbingan.</small>
                    </div>
                </div>

                <div class="p-3 bg-danger-subtle border border-danger-subtle rounded-3">
                    <label class="form-label fw-bold text-danger mb-1" for="confirmResetSiapInput">
                        <i class="bi bi-shield-lock-fill me-1"></i> Konfirmasi Keamanan Administrator
                    </label>
                    <p class="small text-muted mb-2">Ketik kata <strong class="text-danger font-monospace">RESET</strong> pada kolom di bawah ini untuk mengaktifkan tombol eksekusi:</p>
                    <div class="input-group">
                        <span class="input-group-text bg-white text-danger fw-bold border-danger-subtle"><i class="bi bi-key-fill"></i></span>
                        <input type="text" class="form-control font-monospace border-danger-subtle" name="confirm_text" id="confirmResetSiapInput" placeholder="Ketik RESET di sini..." autocomplete="off" required>
                    </div>
                </div>
            </div>

            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i> Batal
                </button>
                <button type="submit" class="btn btn-danger fw-bold shadow-sm" id="btnSubmitResetSiap" disabled>
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Eksekusi Reset Mahasiswa Siap Sidang
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL RESET PROGRESS MAHASISWA TUNGGAL -->
<!-- ========================================================================= -->
<div class="modal fade" id="modalResetSingle" tabindex="-1" aria-labelledby="modalResetSingleLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="monitoring_kelulusan.php" class="modal-content border-0 shadow-lg">
            <input type="hidden" name="action" value="reset_single">
            <input type="hidden" name="mahasiswa_id" id="single_reset_mhs_id">

            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold" id="modalResetSingleLabel">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Reset Progress Mahasiswa
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-4">
                <div class="p-3 bg-light rounded-3 border mb-3">
                    <div class="small text-muted">Mahasiswa Terpilih:</div>
                    <div class="fw-bold text-dark fs-6" id="single_reset_nama"></div>
                    <div class="small text-muted font-monospace" id="single_reset_nim"></div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold text-dark mb-2">Pilih Mode Reset:</label>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="reset_mode" id="single_mode_soft" value="soft" checked>
                        <label class="form-check-label" for="single_mode_soft">
                            <strong class="text-primary">Soft Reset (Nilai & Status 0%)</strong>
                            <small class="d-block text-muted">Kembalikan ke status proses bimbingan dan nilai 0%, arsip tetap tersimpan.</small>
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="reset_mode" id="single_mode_hard" value="hard">
                        <label class="form-check-label" for="single_mode_hard">
                            <strong class="text-danger">Hard Reset (Bersih Total)</strong>
                            <small class="d-block text-muted">Hapus seluruh riwayat bimbingan & sidang mahasiswa ini kembali ke Bab 1 murni.</small>
                        </label>
                    </div>
                </div>

                <div class="p-3 bg-light rounded-3 border mb-3">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" name="reset_plotting" id="single_reset_plotting" value="1" checked>
                        <label class="form-check-label fw-bold text-danger" for="single_reset_plotting">
                            Lepaskan Plotting Dosen Pembimbing (Hapus dari Daftar Dosen)
                        </label>
                        <small class="d-block text-muted">Mahasiswa kembali ke status "Belum Diplot" dan judul dikosongkan ke awal.</small>
                    </div>
                    <hr class="my-2">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" name="notify_mhs" id="single_notify_mhs" value="1" checked>
                        <label class="form-check-label small fw-semibold text-dark" for="single_notify_mhs">
                            Kirim notifikasi otomatis ke mahasiswa ini
                        </label>
                    </div>
                </div>
            </div>

            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-danger fw-bold">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Reset Progress
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Evaluasi modal
    const evalBtns = document.querySelectorAll('.btn-evaluasi');
    evalBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('eval_mhs_id').value     = this.dataset.id;
            document.getElementById('eval_nama').textContent = this.dataset.nama;
            document.getElementById('eval_nim').textContent  = "NIM: " + this.dataset.nim;
            document.getElementById('eval_status').value     = this.dataset.status;
            document.getElementById('eval_nilai').value      = this.dataset.nilai;
        });
    });

    // Reset single modal
    const resetSingleBtns = document.querySelectorAll('.btn-reset-single');
    resetSingleBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('single_reset_mhs_id').value     = this.dataset.id;
            document.getElementById('single_reset_nama').textContent = this.dataset.nama;
            document.getElementById('single_reset_nim').textContent  = "NIM: " + this.dataset.nim;
        });
    });

    // Validasi kata kunci konfirmasi RESET untuk Reset Keseluruhan
    const confirmInput = document.getElementById('confirmResetInput');
    const submitResetAllBtn = document.getElementById('btnSubmitResetAll');
    if (confirmInput && submitResetAllBtn) {
        confirmInput.addEventListener('input', function () {
            if (this.value.trim().toUpperCase() === 'RESET') {
                submitResetAllBtn.disabled = false;
            } else {
                submitResetAllBtn.disabled = true;
            }
        });
    }

    // Validasi kata kunci konfirmasi RESET untuk Reset Mahasiswa Siap Sidang
    const confirmSiapInput = document.getElementById('confirmResetSiapInput');
    const submitResetSiapBtn = document.getElementById('btnSubmitResetSiap');
    if (confirmSiapInput && submitResetSiapBtn) {
        confirmSiapInput.addEventListener('input', function () {
            if (this.value.trim().toUpperCase() === 'RESET') {
                submitResetSiapBtn.disabled = false;
            } else {
                submitResetSiapBtn.disabled = true;
            }
        });
    }
});
</script>

<?php
require_once '../templates/footer.php';
?>
