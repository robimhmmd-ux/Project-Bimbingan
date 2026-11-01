<?php
// admin/jadwal_sidang.php - Penjadwalan Sidang Skripsi & Penetapan 3 Dosen Penguji
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
// PROSES AKSI FORM (POST): JADWALKAN SIDANG, UPDATE JADWAL, NILAI, BATALKAN
// =========================================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. JADWALKAN SIDANG SKRIPSI BARU
    if ($action === 'create_schedule') {
        $mahasiswa_id   = (int)($_POST['mahasiswa_id'] ?? 0);
        $penguji_1_id   = !empty($_POST['penguji_1_id']) ? (int)$_POST['penguji_1_id'] : null;
        $penguji_2_id   = !empty($_POST['penguji_2_id']) ? (int)$_POST['penguji_2_id'] : null;
        $penguji_3_id   = !empty($_POST['penguji_3_id']) ? (int)$_POST['penguji_3_id'] : null;
        $tanggal_sidang = trim($_POST['tanggal_sidang'] ?? '');
        $jam_sidang     = trim($_POST['jam_sidang'] ?? '');
        $ruangan        = trim($_POST['ruangan'] ?? '');
        $judul_final    = trim($_POST['judul_final'] ?? '');
        $ketentuan      = trim($_POST['ketentuan_sidang'] ?? '');

        if ($mahasiswa_id <= 0 || empty($tanggal_sidang) || empty($jam_sidang) || empty($ruangan)) {
            $error_msg = 'Mahasiswa, Tanggal Sidang, Jam Sidang, dan Ruangan wajib diisi!';
        } elseif (empty($penguji_1_id) || empty($penguji_2_id) || empty($penguji_3_id)) {
            $error_msg = 'Wajib memilih lengkap 3 Dosen Penguji (Ketua Penguji, Penguji 2, dan Sekretaris/Penguji 3)!';
        } elseif ($penguji_1_id === $penguji_2_id || $penguji_1_id === $penguji_3_id || $penguji_2_id === $penguji_3_id) {
            $error_msg = 'Dosen penguji tidak boleh ada yang sama! Pilihlah 3 dosen yang berbeda.';
        } else {
            try {
                // Ambil data mahasiswa dan dosen pembimbingnya
                $stmtM = $pdo->prepare("
                    SELECT m.*, u.id AS mhs_user_id, d.id AS dospem_id, d.nama_dosen AS nama_dospem 
                    FROM mahasiswa m
                    JOIN users u ON m.user_id = u.id
                    LEFT JOIN dosen d ON m.dosen_pembimbing_id = d.id
                    WHERE m.id = :mid LIMIT 1
                ");
                $stmtM->execute([':mid' => $mahasiswa_id]);
                $mhs = $stmtM->fetch();

                if (!$mhs) {
                    throw new Exception('Data mahasiswa tidak ditemukan.');
                }
                if (empty($mhs['dosen_pembimbing_id'])) {
                    throw new Exception('Mahasiswa ini belum memiliki Dosen Pembimbing. Tetapkan pembimbing terlebih dahulu.');
                }

                $dospem_id = (int)$mhs['dosen_pembimbing_id'];

                // Dosen pembimbing tidak boleh merangkap sebagai salah satu dari 3 penguji
                if ($dospem_id === $penguji_1_id || $dospem_id === $penguji_2_id || $dospem_id === $penguji_3_id) {
                    throw new Exception("Dosen Pembimbing ({$mhs['nama_dospem']}) tidak boleh merangkap sebagai Dosen Penguji Sidang.");
                }

                if (empty($judul_final)) {
                    $judul_final = $mhs['judul_skripsi'];
                }

                $pdo->beginTransaction();

                // 1. Simpan ke tabel sidang_skripsi
                $stmtSidang = $pdo->prepare("
                    INSERT INTO sidang_skripsi (
                        mahasiswa_id, dosen_pembimbing_id, penguji_1_id, penguji_2_id, penguji_3_id,
                        tanggal_sidang, jam_sidang, ruangan, judul_final, status_sidang, ketentuan_sidang
                    ) VALUES (
                        :mid, :pem_id, :p1, :p2, :p3,
                        :tgl, :jam, :ruang, :judul, 'dijadwalkan', :ketentuan
                    )
                ");
                $stmtSidang->execute([
                    ':mid'       => $mahasiswa_id,
                    ':pem_id'    => $dospem_id,
                    ':p1'        => $penguji_1_id,
                    ':p2'        => $penguji_2_id,
                    ':p3'        => $penguji_3_id,
                    ':tgl'       => $tanggal_sidang,
                    ':jam'       => $jam_sidang,
                    ':ruang'     => $ruangan,
                    ':judul'     => $judul_final,
                    ':ketentuan' => $ketentuan
                ]);

                // 2. Pastikan status kelulusan mahasiswa adalah siap_sidang
                $stmtUpM = $pdo->prepare("UPDATE mahasiswa SET status_kelulusan = 'siap_sidang' WHERE id = :mid");
                $stmtUpM->execute([':mid' => $mahasiswa_id]);

                // 3. Kirim notifikasi ke Mahasiswa
                $tgl_formatted = date('d M Y', strtotime($tanggal_sidang));
                $pesan_mhs = "Jadwal Sidang Skripsi Anda telah ditetapkan pada tanggal {$tgl_formatted} pukul {$jam_sidang} WIB di {$ruangan}. Harap periksa rincian dewan penguji dan tata tertib di dashboard Anda.";
                
                $stmtNotifMhs = $pdo->prepare("
                    INSERT INTO notifikasi (user_id, judul, pesan, tipe, link_tujuan) 
                    VALUES (:uid, 'Jadwal Sidang Skripsi Ditetapkan', :pesan, 'sidang', 'index.php')
                ");
                $stmtNotifMhs->execute([
                    ':uid'   => $mhs['mhs_user_id'],
                    ':pesan' => $pesan_mhs
                ]);

                // 4. Kirim notifikasi ke 3 Dosen Penguji
                $penguji_ids = [$penguji_1_id, $penguji_2_id, $penguji_3_id];
                $roles_penguji = ['Ketua Penguji', 'Penguji 2', 'Sekretaris Sidang'];

                foreach ($penguji_ids as $idx => $pid) {
                    $stmtDsnUser = $pdo->prepare("SELECT user_id, nama_dosen FROM dosen WHERE id = :did LIMIT 1");
                    $stmtDsnUser->execute([':did' => $pid]);
                    $dsnUser = $stmtDsnUser->fetch();

                    if ($dsnUser) {
                        $pesan_dosen = "Anda ditugaskan sebagai {$roles_penguji[$idx]} Sidang Skripsi untuk mahasiswa {$mhs['nama_lengkap']} (NIM: {$mhs['nim']}) pada tanggal {$tgl_formatted} pukul {$jam_sidang} WIB di {$ruangan}.";
                        $stmtNotifDsn = $pdo->prepare("
                            INSERT INTO notifikasi (user_id, judul, pesan, tipe, link_tujuan) 
                            VALUES (:uid, 'Penugasan Penguji Sidang Skripsi', :pesan, 'sidang', 'index.php')
                        ");
                        $stmtNotifDsn->execute([
                            ':uid'   => $dsnUser['user_id'],
                            ':pesan' => $pesan_dosen
                        ]);
                    }
                }

                $pdo->commit();
                $success_msg = "Jadwal sidang skripsi untuk {$mhs['nama_lengkap']} berhasil diterbitkan dan notifikasi telah dikirimkan ke mahasiswa serta ketiga dewan penguji!";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error_msg = "Gagal menjadwalkan sidang: " . $e->getMessage();
            }
        }
    }

    // 2. INPUT / EVALUASI HASIL NILAI SIDANG
    elseif ($action === 'input_nilai') {
        $sidang_id      = (int)($_POST['sidang_id'] ?? 0);
        $status_sidang  = $_POST['status_sidang'] ?? 'lulus';
        $nilai_p1       = isset($_POST['nilai_penguji_1']) ? (float)$_POST['nilai_penguji_1'] : null;
        $nilai_p2       = isset($_POST['nilai_penguji_2']) ? (float)$_POST['nilai_penguji_2'] : null;
        $nilai_p3       = isset($_POST['nilai_penguji_3']) ? (float)$_POST['nilai_penguji_3'] : null;
        $catatan_sidang = trim($_POST['catatan_sidang'] ?? '');

        if ($sidang_id <= 0) {
            $error_msg = 'ID Sidang tidak valid.';
        } else {
            try {
                // Hitung nilai akhir rata-rata jika ketiga nilai diinput
                $nilai_akhir = null;
                if ($nilai_p1 !== null && $nilai_p2 !== null && $nilai_p3 !== null) {
                    $nilai_akhir = round(($nilai_p1 + $nilai_p2 + $nilai_p3) / 3, 2);
                }

                $pdo->beginTransaction();

                // Ambil info sidang & mahasiswa
                $stmtInfo = $pdo->prepare("
                    SELECT s.mahasiswa_id, m.user_id, m.nama_lengkap 
                    FROM sidang_skripsi s
                    JOIN mahasiswa m ON s.mahasiswa_id = m.id
                    WHERE s.id = :sid LIMIT 1
                ");
                $stmtInfo->execute([':sid' => $sidang_id]);
                $sidangRow = $stmtInfo->fetch();

                if (!$sidangRow) {
                    throw new Exception('Data sidang tidak ditemukan.');
                }

                // Update sidang_skripsi
                $stmtUpSidang = $pdo->prepare("
                    UPDATE sidang_skripsi 
                    SET status_sidang = :st,
                        nilai_penguji_1 = :np1,
                        nilai_penguji_2 = :np2,
                        nilai_penguji_3 = :np3,
                        nilai_akhir = :na,
                        catatan_sidang = :catatan
                    WHERE id = :sid
                ");
                $stmtUpSidang->execute([
                    ':st'      => $status_sidang,
                    ':np1'     => $nilai_p1,
                    ':np2'     => $nilai_p2,
                    ':np3'     => $nilai_p3,
                    ':na'      => $nilai_akhir,
                    ':catatan' => $catatan_sidang,
                    ':sid'     => $sidang_id
                ]);

                // Jika status kelulusan adalah 'lulus', sinkronkan status kelulusan di tabel mahasiswa
                if ($status_sidang === 'lulus') {
                    $stmtUpMhs = $pdo->prepare("UPDATE mahasiswa SET status_kelulusan = 'lulus' WHERE id = :mid");
                    $stmtUpMhs->execute([':mid' => $sidangRow['mahasiswa_id']]);

                    // Kirim notifikasi ucapan kelulusan
                    $stmtNotif = $pdo->prepare("
                        INSERT INTO notifikasi (user_id, judul, pesan, tipe, link_tujuan) 
                        VALUES (:uid, 'Selamat! Anda Dinyatakan Lulus Sidang', :pesan, 'sukses', 'index.php')
                    ");
                    $stmtNotif->execute([
                        ':uid'   => $sidangRow['user_id'],
                        ':pesan' => "Selamat kepada {$sidangRow['nama_lengkap']}, Anda dinyatakan LULUS Sidang Skripsi dengan nilai akhir {$nilai_akhir}. Silakan selesaikan pengurusan berkas yudisium."
                    ]);
                } elseif ($status_sidang === 'revisi') {
                    $stmtNotif = $pdo->prepare("
                        INSERT INTO notifikasi (user_id, judul, pesan, tipe, link_tujuan) 
                        VALUES (:uid, 'Hasil Sidang: Perlu Revisi', :pesan, 'revisi', 'index.php')
                    ");
                    $stmtNotif->execute([
                        ':uid'   => $sidangRow['user_id'],
                        ':pesan' => "Hasil sidang skripsi Anda memerlukan perbaikan naskah (Revisi). Nilai akhir: {$nilai_akhir}. Catatan: {$catatan_sidang}."
                    ]);
                }

                $pdo->commit();
                $success_msg = "Nilai evaluasi sidang dan keputusan akademik berhasil disimpan!";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error_msg = "Gagal menyimpan nilai sidang: " . $e->getMessage();
            }
        }
    }

    // 3. BATALKAN / HAPUS JADWAL SIDANG
    elseif ($action === 'delete_schedule') {
        $sidang_id = (int)($_POST['sidang_id'] ?? 0);
        if ($sidang_id > 0) {
            try {
                $stmtDel = $pdo->prepare("DELETE FROM sidang_skripsi WHERE id = :sid");
                $stmtDel->execute([':sid' => $sidang_id]);
                $success_msg = "Jadwal sidang skripsi berhasil dibatalkan / dihapus dari sistem.";
            } catch (Exception $e) {
                $error_msg = "Gagal membatalkan sidang: " . $e->getMessage();
            }
        }
    }
}

// =========================================================================
// QUERY DATA MASTER & ANTREAN
// =========================================================================

// Daftar Dosen untuk Dropdown Penguji (9 Dosen Aktif)
$dosen_list = $pdo->query("SELECT id, nama_dosen, nidn FROM dosen ORDER BY nama_dosen ASC")->fetchAll();

// Daftar Mahasiswa Siap Sidang yang Belum Terjadwal Sidang Aktif
$querySiap = "
    SELECT m.*, d.nama_dosen AS nama_dospem 
    FROM mahasiswa m
    INNER JOIN dosen d ON m.dosen_pembimbing_id = d.id
    WHERE m.status_kelulusan = 'siap_sidang' 
      AND m.id NOT IN (
          SELECT mahasiswa_id FROM sidang_skripsi 
          WHERE status_sidang IN ('pengajuan', 'dijadwalkan')
      )
    ORDER BY m.kelas ASC, m.nama_lengkap ASC
";
$mhs_siap_sidang = $pdo->query($querySiap)->fetchAll();

// Filter Daftar Sidang Terjadwal
$filter_status_sidang = $_GET['status_sidang'] ?? 'all';
$search_sidang        = trim($_GET['q'] ?? '');

$where_sidang = ["1=1"];
$params_sidang = [];

if ($filter_status_sidang !== 'all') {
    $where_sidang[] = "s.status_sidang = :st_sidang";
    $params_sidang[':st_sidang'] = $filter_status_sidang;
}

if (!empty($search_sidang)) {
    $where_sidang[] = "(m.nim LIKE :q OR m.nama_lengkap LIKE :q OR s.ruangan LIKE :q OR s.judul_final LIKE :q)";
    $params_sidang[':q'] = "%{$search_sidang}%";
}

$where_sidang_clause = implode(" AND ", $where_sidang);

// Query Seluruh Sesi Sidang Skripsi dengan Dospem & 3 Penguji
$querySidangList = "
    SELECT 
        s.*,
        m.nim,
        m.nama_lengkap,
        m.kelas,
        d_pem.nama_dosen AS dospem,
        d_p1.nama_dosen  AS penguji_1,
        d_p2.nama_dosen  AS penguji_2,
        d_p3.nama_dosen  AS penguji_3
    FROM sidang_skripsi s
    INNER JOIN mahasiswa m ON s.mahasiswa_id = m.id
    LEFT JOIN dosen d_pem ON s.dosen_pembimbing_id = d_pem.id
    LEFT JOIN dosen d_p1  ON s.penguji_1_id = d_p1.id
    LEFT JOIN dosen d_p2  ON s.penguji_2_id = d_p2.id
    LEFT JOIN dosen d_p3  ON s.penguji_3_id = d_p3.id
    WHERE {$where_sidang_clause}
    ORDER BY s.tanggal_sidang DESC, s.jam_sidang DESC
";
$stmtSidangList = $pdo->prepare($querySidangList);
$stmtSidangList->execute($params_sidang);
$sidang_list = $stmtSidangList->fetchAll();

// Metrik Sidang
$total_sidang       = $pdo->query("SELECT COUNT(*) FROM sidang_skripsi")->fetchColumn();
$count_dijadwalkan  = $pdo->query("SELECT COUNT(*) FROM sidang_skripsi WHERE status_sidang = 'dijadwalkan'")->fetchColumn();
$count_sidang_lulus = $pdo->query("SELECT COUNT(*) FROM sidang_skripsi WHERE status_sidang = 'lulus'")->fetchColumn();
$count_antrean_siap = count($mhs_siap_sidang);

$page_title   = "Penjadwalan Sidang Skripsi (3 Penguji)";
$current_page = "sidang";

require_once '../templates/header.php';
require_once '../templates/sidebar_admin.php';
?>

<!-- Hero Banner Header -->
<div class="hero-banner d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
    <div>
        <div class="hero-tag">
            <i class="bi bi-mortarboard-fill"></i>
            <span>Penjadwalan Sidang Skripsi & 3 Dewan Penguji</span>
        </div>
        <h2 class="fw-bold text-white mb-1">Manajemen Sidang Munaqasyah Skripsi</h2>
        <p class="text-white-50 small mb-0">Verifikasi mahasiswa siap sidang, tetapkan jadwal & ruangan, tunjuk 3 dewan penguji, serta catat evaluasi nilai kelulusan.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <button type="button" class="btn btn-light shadow-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalJadwalBaru">
            <i class="bi bi-calendar-plus-fill text-primary me-1"></i> Terbitkan Jadwal Sidang
        </button>
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

<!-- Stat Bar Metrik Sidang -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="metric-card p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="small text-muted fw-bold text-uppercase">Antrean Siap Sidang</span>
                    <h3 class="fw-bold text-warning-emphasis mb-0 mt-1"><?= $count_antrean_siap; ?></h3>
                    <small class="text-muted" style="font-size: 0.72rem;">Menunggu penetapan</small>
                </div>
                <div class="metric-icon-box bg-warning-subtle text-warning">
                    <i class="bi bi-hourglass-split"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="metric-card p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="small text-muted fw-bold text-uppercase">Jadwal Mendatang</span>
                    <h3 class="fw-bold text-primary mb-0 mt-1"><?= $count_dijadwalkan; ?></h3>
                    <small class="text-muted" style="font-size: 0.72rem;">Status dijadwalkan</small>
                </div>
                <div class="metric-icon-box bg-primary-subtle text-primary">
                    <i class="bi bi-calendar-event-fill"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="metric-card p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="small text-muted fw-bold text-uppercase">Lulus Sidang</span>
                    <h3 class="fw-bold text-success mb-0 mt-1"><?= $count_sidang_lulus; ?></h3>
                    <small class="text-muted" style="font-size: 0.72rem;">Telah lulus munaqasyah</small>
                </div>
                <div class="metric-icon-box bg-success-subtle text-success">
                    <i class="bi bi-patch-check-fill"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="metric-card p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="small text-muted fw-bold text-uppercase">Total Sesi Sidang</span>
                    <h3 class="fw-bold text-dark mb-0 mt-1"><?= $total_sidang; ?></h3>
                    <small class="text-muted" style="font-size: 0.72rem;">Riwayat komprehensif</small>
                </div>
                <div class="metric-icon-box bg-info-subtle text-info">
                    <i class="bi bi-mortarboard-fill"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Antrean Mahasiswa Siap Sidang (Perlu Dijadwalkan) -->
<?php if (!empty($mhs_siap_sidang)): ?>
    <div class="card shadow-sm border-0 mb-4 border-start border-4 border-warning">
        <div class="card-header bg-warning bg-opacity-10 py-3 d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <span class="p-2 rounded bg-warning text-dark">
                    <i class="bi bi-bell-fill"></i>
                </span>
                <div>
                    <h6 class="fw-bold mb-0 text-dark">Antrean Mahasiswa Siap Sidang (ACC Bab 1 - 5)</h6>
                    <small class="text-muted">Terdapat <?= count($mhs_siap_sidang); ?> mahasiswa yang telah ACC seluruh bab dan siap diterbitkan jadwal sidangnya</small>
                </div>
            </div>
            <span class="badge bg-warning text-dark px-3 py-1">Prioritas Penjadwalan</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Mahasiswa</th>
                            <th>Kelas</th>
                            <th>Judul Skripsi</th>
                            <th>Dosen Pembimbing</th>
                            <th class="text-center" style="width: 170px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mhs_siap_sidang as $ms): ?>
                            <tr>
                                <td class="ps-3">
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($ms['nama_lengkap']); ?></div>
                                    <span class="font-monospace text-primary small">NIM: <?= htmlspecialchars($ms['nim']); ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                                        <?= htmlspecialchars($ms['kelas'] ?? '-'); ?>
                                    </span>
                                </td>
                                <td>
                                    <small class="text-dark fw-medium d-block text-truncate" style="max-width: 320px;" title="<?= htmlspecialchars($ms['judul_skripsi']); ?>">
                                        <?= htmlspecialchars($ms['judul_skripsi']); ?>
                                    </small>
                                </td>
                                <td>
                                    <span class="text-success fw-semibold small">
                                        <i class="bi bi-person-check-fill me-1"></i><?= htmlspecialchars($ms['nama_dospem']); ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-primary btn-quick-jadwal"
                                            data-bs-toggle="modal" data-bs-target="#modalJadwalBaru"
                                            data-mid="<?= $ms['id']; ?>"
                                            data-nama="<?= htmlspecialchars($ms['nama_lengkap']); ?>"
                                            data-nim="<?= htmlspecialchars($ms['nim']); ?>"
                                            data-judul="<?= htmlspecialchars($ms['judul_skripsi']); ?>"
                                            data-dospemid="<?= $ms['dosen_pembimbing_id']; ?>">
                                        <i class="bi bi-calendar-plus me-1"></i>Jadwalkan Sidang
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Filter & Pencarian Sidang Terjadwal -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-3">
        <form method="GET" action="jadwal_sidang.php" class="row g-2 align-items-center">
            <div class="col-md-6">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0 ps-0" name="q" value="<?= htmlspecialchars($search_sidang); ?>" placeholder="Cari Mahasiswa, NIM, Ruangan, atau Judul...">
                </div>
            </div>
            <div class="col-md-4">
                <select name="status_sidang" class="form-select" onchange="this.form.submit()">
                    <option value="all" <?= ($filter_status_sidang === 'all') ? 'selected' : ''; ?>>-- Semua Status Sidang --</option>
                    <option value="dijadwalkan" <?= ($filter_status_sidang === 'dijadwalkan') ? 'selected' : ''; ?>>Dijadwalkan (Mendatang)</option>
                    <option value="lulus" <?= ($filter_status_sidang === 'lulus') ? 'selected' : ''; ?>>Lulus</option>
                    <option value="revisi" <?= ($filter_status_sidang === 'revisi') ? 'selected' : ''; ?>>Perlu Revisi</option>
                    <option value="tidak_lulus" <?= ($filter_status_sidang === 'tidak_lulus') ? 'selected' : ''; ?>>Tidak Lulus</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
                <?php if ($filter_status_sidang !== 'all' || !empty($search_sidang)): ?>
                    <a href="jadwal_sidang.php" class="btn btn-outline-secondary" title="Reset"><i class="bi bi-arrow-counterclockwise"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Tabel Sesi Sidang Skripsi Terjadwal -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2">
            <span class="p-2 rounded bg-primary-subtle text-primary">
                <i class="bi bi-calendar2-week-fill"></i>
            </span>
            <div>
                <h6 class="fw-bold mb-0 text-dark">Daftar Sidang Skripsi & Dewan Penguji</h6>
                <small class="text-muted">Total <?= count($sidang_list); ?> sesi sidang skripsi terdaftar</small>
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3" style="width: 50px;">No</th>
                        <th>Mahasiswa & Judul</th>
                        <th>Waktu & Ruangan</th>
                        <th>Dosen Pembimbing</th>
                        <th>3 Dewan Penguji</th>
                        <th class="text-center" style="width: 110px;">Nilai Akhir</th>
                        <th class="text-center" style="width: 120px;">Status</th>
                        <th class="text-center" style="width: 140px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sidang_list)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-5">
                                <i class="bi bi-calendar-x fs-2 d-block text-secondary mb-2"></i>
                                Belum ada data sidang skripsi yang dijadwalkan.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $no = 1;
                        foreach ($sidang_list as $row): 
                            $tgl_tampil = !empty($row['tanggal_sidang']) ? date('d M Y', strtotime($row['tanggal_sidang'])) : '-';
                            $jam_tampil = !empty($row['jam_sidang']) ? substr($row['jam_sidang'], 0, 5) . ' WIB' : '-';
                            $status_st  = $row['status_sidang'] ?? 'dijadwalkan';
                        ?>
                            <tr>
                                <td class="ps-3 text-muted"><?= $no++; ?></td>
                                <td>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($row['nama_lengkap']); ?></div>
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="font-monospace text-primary small">NIM: <?= htmlspecialchars($row['nim']); ?></span>
                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle" style="font-size: 0.68rem;"><?= htmlspecialchars($row['kelas'] ?? '-'); ?></span>
                                    </div>
                                    <small class="text-muted d-block text-truncate" style="max-width: 250px;" title="<?= htmlspecialchars($row['judul_final'] ?? ''); ?>">
                                        <?= htmlspecialchars($row['judul_final'] ?? '-'); ?>
                                    </small>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark"><i class="bi bi-calendar-check me-1 text-primary"></i><?= $tgl_tampil; ?></div>
                                    <div class="small text-muted"><i class="bi bi-clock me-1 text-info"></i><?= $jam_tampil; ?></div>
                                    <span class="badge bg-light text-dark border mt-1">
                                        <i class="bi bi-geo-alt-fill text-danger me-1"></i><?= htmlspecialchars($row['ruangan'] ?? 'Ruang Sidang'); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="text-dark small fw-semibold">
                                        <i class="bi bi-person-check-fill text-success me-1"></i><?= htmlspecialchars($row['dospem'] ?? '-'); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="small mb-1">
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle me-1">P1</span>
                                        <strong class="text-dark"><?= htmlspecialchars($row['penguji_1'] ?? '-'); ?></strong>
                                    </div>
                                    <div class="small mb-1">
                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle me-1">P2</span>
                                        <span class="text-muted"><?= htmlspecialchars($row['penguji_2'] ?? '-'); ?></span>
                                    </div>
                                    <div class="small">
                                        <span class="badge bg-info-subtle text-info border border-info-subtle me-1">P3</span>
                                        <span class="text-muted"><?= htmlspecialchars($row['penguji_3'] ?? '-'); ?></span>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <?php if ($row['nilai_akhir'] !== null): ?>
                                        <span class="fw-bold fs-6 <?= ($row['nilai_akhir'] >= 80) ? 'text-success' : (($row['nilai_akhir'] >= 65) ? 'text-primary' : 'text-danger'); ?>">
                                            <?= number_format((float)$row['nilai_akhir'], 1); ?>
                                        </span>
                                        <div class="text-muted" style="font-size: 0.68rem;">
                                            P1: <?= $row['nilai_penguji_1'] ?? '-'; ?> | P2: <?= $row['nilai_penguji_2'] ?? '-'; ?> | P3: <?= $row['nilai_penguji_3'] ?? '-'; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted small">Belum Dinilai</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($status_st === 'lulus'): ?>
                                        <span class="badge bg-success"><i class="bi bi-patch-check-fill me-1"></i>Lulus</span>
                                    <?php elseif ($status_st === 'revisi'): ?>
                                        <span class="badge bg-warning text-dark"><i class="bi bi-pencil me-1"></i>Revisi</span>
                                    <?php elseif ($status_st === 'tidak_lulus'): ?>
                                        <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Tidak Lulus</span>
                                    <?php else: ?>
                                        <span class="badge bg-primary"><i class="bi bi-calendar-event me-1"></i>Dijadwalkan</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm">
                                        <!-- Tombol Input / Edit Nilai Sidang -->
                                        <button type="button" class="btn btn-outline-success btn-nilai" 
                                                data-bs-toggle="modal" data-bs-target="#modalNilaiSidang"
                                                data-sid="<?= $row['id']; ?>"
                                                data-nama="<?= htmlspecialchars($row['nama_lengkap']); ?>"
                                                data-nim="<?= htmlspecialchars($row['nim']); ?>"
                                                data-p1="<?= htmlspecialchars($row['penguji_1'] ?? 'Penguji 1'); ?>"
                                                data-p2="<?= htmlspecialchars($row['penguji_2'] ?? 'Penguji 2'); ?>"
                                                data-p3="<?= htmlspecialchars($row['penguji_3'] ?? 'Penguji 3'); ?>"
                                                data-np1="<?= $row['nilai_penguji_1'] ?? ''; ?>"
                                                data-np2="<?= $row['nilai_penguji_2'] ?? ''; ?>"
                                                data-np3="<?= $row['nilai_penguji_3'] ?? ''; ?>"
                                                data-status="<?= $status_st; ?>"
                                                data-catatan="<?= htmlspecialchars($row['catatan_sidang'] ?? ''); ?>"
                                                title="Input / Evaluasi Nilai Sidang">
                                            <i class="bi bi-award-fill"></i>
                                        </button>
                                        <!-- Tombol Batalkan / Hapus Sidang -->
                                        <form method="POST" action="jadwal_sidang.php" class="d-inline" onsubmit="return confirm('Apakah Anda yakin ingin membatalkan jadwal sidang skripsi ini?');">
                                            <input type="hidden" name="action" value="delete_schedule">
                                            <input type="hidden" name="sidang_id" value="<?= $row['id']; ?>">
                                            <button type="submit" class="btn btn-outline-danger" title="Batalkan Jadwal Sidang">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
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
<!-- MODAL TERBITKAN JADWAL SIDANG BARU (3 PENGUJI) -->
<!-- ========================================================================= -->
<div class="modal fade" id="modalJadwalBaru" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" action="jadwal_sidang.php" class="modal-content">
            <input type="hidden" name="action" value="create_schedule">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-calendar-plus me-2"></i>Terbitkan Jadwal Sidang Skripsi (3 Penguji)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-3">
                    <!-- Pilih Mahasiswa -->
                    <div class="col-md-12">
                        <label class="form-label small fw-semibold">Pilih Mahasiswa Siap Sidang <span class="text-danger">*</span></label>
                        <select name="mahasiswa_id" id="modal_select_mhs" class="form-select" required>
                            <option value="">-- Pilih Mahasiswa yang Memenuhi Syarat Sidang --</option>
                            <?php 
                            // Tampilkan mahasiswa siap sidang atau seluruh mahasiswa yang telah memiliki pembimbing
                            $all_mhs_with_dospem = $pdo->query("
                                SELECT m.id, m.nim, m.nama_lengkap, m.kelas, m.judul_skripsi, m.dosen_pembimbing_id, d.nama_dosen AS dospem 
                                FROM mahasiswa m
                                INNER JOIN dosen d ON m.dosen_pembimbing_id = d.id
                                ORDER BY m.nama_lengkap ASC
                            ")->fetchAll();
                            foreach ($all_mhs_with_dospem as $am): 
                            ?>
                                <option value="<?= $am['id']; ?>" 
                                        data-judul="<?= htmlspecialchars($am['judul_skripsi']); ?>"
                                        data-dospemid="<?= $am['dosen_pembimbing_id']; ?>"
                                        data-dospemname="<?= htmlspecialchars($am['dospem']); ?>">
                                    <?= htmlspecialchars($am['nama_lengkap']); ?> (<?= $am['nim']; ?> - <?= $am['kelas']; ?>) • Dospem: <?= htmlspecialchars($am['dospem']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Judul Final Skripsi -->
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Judul Final Naskah Skripsi</label>
                        <textarea class="form-control" name="judul_final" id="modal_judul_final" rows="2" placeholder="Judul skripsi final yang disidangkan..."></textarea>
                    </div>

                    <!-- Tanggal & Jam Sidang -->
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Tanggal Sidang <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="tanggal_sidang" value="<?= date('Y-m-d', strtotime('+3 days')); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Jam Mulai Sidang <span class="text-danger">*</span></label>
                        <input type="time" class="form-control" name="jam_sidang" value="09:00" required>
                    </div>

                    <!-- Ruangan Sidang -->
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Ruangan Sidang Munaqasyah <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="ruangan" placeholder="Contoh: Ruang Sidang Utama Gd. Rektorat Lt. 2 / Lab Komputer 1" required>
                    </div>

                    <!-- Dewan Penguji 3 Orang -->
                    <div class="col-12 mt-4">
                        <div class="p-3 bg-light rounded-3 border">
                            <h6 class="fw-bold text-dark mb-1"><i class="bi bi-people-fill text-primary me-2"></i>Penetapan 3 Dosen Penguji Sidang</h6>
                            <p class="text-muted small mb-3">Pilihlah 3 dosen penguji yang berbeda. Dosen Pembimbing tidak boleh merangkap sebagai dosen penguji.</p>
                            
                            <div class="row g-3">
                                <!-- Penguji 1 (Ketua Penguji) -->
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold text-primary">
                                        <i class="bi bi-1-circle-fill me-1"></i>Penguji 1 (Ketua) <span class="text-danger">*</span>
                                    </label>
                                    <select name="penguji_1_id" class="form-select form-select-sm penguji-select" required>
                                        <option value="">-- Pilih Ketua Penguji --</option>
                                        <?php foreach ($dosen_list as $dl): ?>
                                            <option value="<?= $dl['id']; ?>"><?= htmlspecialchars($dl['nama_dosen']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <!-- Penguji 2 -->
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold text-secondary">
                                        <i class="bi bi-2-circle-fill me-1"></i>Penguji 2 <span class="text-danger">*</span>
                                    </label>
                                    <select name="penguji_2_id" class="form-select form-select-sm penguji-select" required>
                                        <option value="">-- Pilih Penguji 2 --</option>
                                        <?php foreach ($dosen_list as $dl): ?>
                                            <option value="<?= $dl['id']; ?>"><?= htmlspecialchars($dl['nama_dosen']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <!-- Penguji 3 (Sekretaris) -->
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold text-info">
                                        <i class="bi bi-3-circle-fill me-1"></i>Penguji 3 (Sekretaris) <span class="text-danger">*</span>
                                    </label>
                                    <select name="penguji_3_id" class="form-select form-select-sm penguji-select" required>
                                        <option value="">-- Pilih Penguji 3 --</option>
                                        <?php foreach ($dosen_list as $dl): ?>
                                            <option value="<?= $dl['id']; ?>"><?= htmlspecialchars($dl['nama_dosen']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Ketentuan & Tata Tertib Sidang -->
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Ketentuan & Tata Tertib Sidang</label>
                        <textarea class="form-control" name="ketentuan_sidang" rows="3">1. Pakaian formal: Kemeja putih, dasi hitam, celana/rok hitam, dan jas almamater resmi.
2. Membawa 3 eksemplar naskah skripsi jilid softcover untuk dewan penguji.
3. Menyiapkan slide presentasi (durasi presentasi maksimal 15 menit).
4. Hadir di ruang sidang 30 menit sebelum jadwal dimulai.</textarea>
                        <div class="form-text small">Instruksi ini akan otomatis ditampilkan pada kartu pengumuman sidang mahasiswa di dashboard mereka.</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-calendar-check me-1"></i>Terbitkan & Kirim Notifikasi</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL INPUT / EVALUASI NILAI SIDANG SKRIPSI -->
<!-- ========================================================================= -->
<div class="modal fade" id="modalNilaiSidang" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="jadwal_sidang.php" class="modal-content">
            <input type="hidden" name="action" value="input_nilai">
            <input type="hidden" name="sidang_id" id="nilai_sidang_id">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-award-fill me-2"></i>Evaluasi Hasil & Nilai Sidang</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="p-3 bg-light rounded-3 border mb-3">
                    <div class="small text-muted">Mahasiswa Teruji:</div>
                    <div class="fw-bold text-dark fs-6" id="nilai_mhs_nama"></div>
                    <div class="small text-muted font-monospace" id="nilai_mhs_nim"></div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Keputusan Hasil Sidang <span class="text-danger">*</span></label>
                    <select name="status_sidang" id="nilai_status_sidang" class="form-select" required>
                        <option value="lulus">LULUS (Sidang Berhasil, Mahasiswa Dinyatakan Lulus)</option>
                        <option value="revisi">REVISI (Lulus Bersyarat dengan Perbaikan Naskah)</option>
                        <option value="tidak_lulus">TIDAK LULUS (Wajib Menempuh Sidang Ulang)</option>
                    </select>
                </div>

                <div class="p-3 bg-light rounded-3 border mb-3">
                    <label class="form-label small fw-bold text-dark mb-2">Nilai Dewan Penguji (0 - 100)</label>
                    <div class="row g-2 mb-2">
                        <div class="col-4">
                            <label class="form-label small text-muted text-truncate d-block" id="label_p1">Penguji 1</label>
                            <input type="number" step="0.1" min="0" max="100" class="form-control form-control-sm hitung-nilai" name="nilai_penguji_1" id="nilai_p1_input" placeholder="0-100" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label small text-muted text-truncate d-block" id="label_p2">Penguji 2</label>
                            <input type="number" step="0.1" min="0" max="100" class="form-control form-control-sm hitung-nilai" name="nilai_penguji_2" id="nilai_p2_input" placeholder="0-100" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label small text-muted text-truncate d-block" id="label_p3">Penguji 3</label>
                            <input type="number" step="0.1" min="0" max="100" class="form-control form-control-sm hitung-nilai" name="nilai_penguji_3" id="nilai_p3_input" placeholder="0-100" required>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                        <span class="small fw-semibold text-dark">Estimasi Nilai Akhir (Rata-rata):</span>
                        <span class="fw-bold fs-6 text-primary" id="preview_nilai_akhir">0.0</span>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Catatan Dewan Penguji & Catatan Revisi</label>
                    <textarea class="form-control" name="catatan_sidang" id="catatan_sidang_input" rows="3" placeholder="Masukkan catatan perbaikan naskah atau apresiasi dewan penguji..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Tutup</button>
                <button type="submit" class="btn btn-success"><i class="bi bi-check2-circle me-1"></i>Simpan Keputusan & Nilai</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Quick button from Antrean Siap Sidang to Modal
    const quickBtns = document.querySelectorAll('.btn-quick-jadwal');
    const selectMhs = document.getElementById('modal_select_mhs');
    const judulFinal = document.getElementById('modal_judul_final');

    quickBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            const mid = this.dataset.mid;
            const judul = this.dataset.judul;
            if (selectMhs) {
                selectMhs.value = mid;
            }
            if (judulFinal) {
                judulFinal.value = judul;
            }
        });
    });

    if (selectMhs && judulFinal) {
        selectMhs.addEventListener('change', function () {
            const selectedOpt = this.options[this.selectedIndex];
            if (selectedOpt) {
                const j = selectedOpt.getAttribute('data-judul');
                if (j) {
                    judulFinal.value = j;
                }
            }
        });
    }

    // Modal Input Nilai Sidang
    const nilaiBtns = document.querySelectorAll('.btn-nilai');
    nilaiBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('nilai_sidang_id').value        = this.dataset.sid;
            document.getElementById('nilai_mhs_nama').textContent   = this.dataset.nama;
            document.getElementById('nilai_mhs_nim').textContent    = "NIM: " + this.dataset.nim;
            document.getElementById('label_p1').textContent         = this.dataset.p1;
            document.getElementById('label_p2').textContent         = this.dataset.p2;
            document.getElementById('label_p3').textContent         = this.dataset.p3;
            document.getElementById('nilai_p1_input').value         = this.dataset.np1;
            document.getElementById('nilai_p2_input').value         = this.dataset.np2;
            document.getElementById('nilai_p3_input').value         = this.dataset.np3;
            document.getElementById('nilai_status_sidang').value    = this.dataset.status || 'lulus';
            document.getElementById('catatan_sidang_input').value   = this.dataset.catatan || '';
            hitungRataRata();
        });
    });

    // Real-time perhitungan rata-rata nilai sidang
    function hitungRataRata() {
        const v1 = parseFloat(document.getElementById('nilai_p1_input').value) || 0;
        const v2 = parseFloat(document.getElementById('nilai_p2_input').value) || 0;
        const v3 = parseFloat(document.getElementById('nilai_p3_input').value) || 0;
        const avg = ((v1 + v2 + v3) / 3).toFixed(2);
        const preview = document.getElementById('preview_nilai_akhir');
        if (preview) {
            preview.textContent = avg;
        }
    }

    document.querySelectorAll('.hitung-nilai').forEach(input => {
        input.addEventListener('input', hitungRataRata);
    });
});
</script>

<?php
require_once '../templates/footer.php';
?>
