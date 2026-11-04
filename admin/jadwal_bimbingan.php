<?php
// admin/jadwal_bimbingan.php - Penjadwalan & Manajemen Sesi Bimbingan Mahasiswa & Dosen
require_once __DIR__ . '/../config/database.php';

// Proteksi akses role admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login");
    exit;
}

$success_msg = '';
$error_msg   = '';

// =========================================================================
// 1. PROSES AKSI FORM (POST): BUAT JADWAL, RESCHEDULE, SELESAI, HAPUS
// =========================================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    // A. BUAT JADWAL BIMBINGAN BARU
    if ($action === 'create') {
        $mahasiswa_id      = (int)($_POST['mahasiswa_id'] ?? 0);
        $dosen_id          = (int)($_POST['dosen_id'] ?? 0);
        $tanggal_bimbingan = trim($_POST['tanggal_bimbingan'] ?? '');
        $jam_bimbingan     = trim($_POST['jam_bimbingan'] ?? '');
        $bab_pembahasan    = trim($_POST['bab_pembahasan'] ?? '');
        $metode_lokasi     = trim($_POST['metode_lokasi'] ?? 'Tatap Muka di Ruang Dosen');
        $catatan_admin     = trim($_POST['catatan_admin'] ?? '');
        $status_awal       = in_array($_POST['status'] ?? '', ['disetujui', 'menunggu']) ? $_POST['status'] : 'disetujui';

        if ($mahasiswa_id <= 0 || $dosen_id <= 0 || empty($tanggal_bimbingan) || empty($jam_bimbingan) || empty($bab_pembahasan)) {
            $error_msg = "Mahasiswa, Dosen Pembimbing, Tanggal, Jam, dan Topik Bimbingan wajib diisi!";
        } else {
            try {
                // Ambil data detail mahasiswa & dosen
                $stmtM = $pdo->prepare("SELECT user_id, nim, nama_lengkap, kelas FROM mahasiswa WHERE id = :mid LIMIT 1");
                $stmtM->execute([':mid' => $mahasiswa_id]);
                $mhsData = $stmtM->fetch();

                $stmtD = $pdo->prepare("SELECT user_id, nama_dosen, email FROM dosen WHERE id = :did LIMIT 1");
                $stmtD->execute([':did' => $dosen_id]);
                $dsnData = $stmtD->fetch();

                if (!$mhsData || !$dsnData) {
                    throw new Exception("Data mahasiswa atau dosen tidak ditemukan.");
                }

                $pdo->beginTransaction();

                // Format catatan gabungan
                $catatan_full = "Lokasi/Media: " . $metode_lokasi;
                if (!empty($catatan_admin)) {
                    $catatan_full .= " | Catatan: " . $catatan_admin;
                }

                // 1. Simpan ke tabel pengajuan_bimbingan
                $stmtIns = $pdo->prepare("
                    INSERT INTO pengajuan_bimbingan 
                    (mahasiswa_id, dosen_id, tanggal_bimbingan, jam_bimbingan, bab_pembahasan, catatan_mahasiswa, status, created_at)
                    VALUES (:mid, :did, :tgl, :jam, :bab, :catatan, :st, NOW())
                ");
                $stmtIns->execute([
                    ':mid'     => $mahasiswa_id,
                    ':did'     => $dosen_id,
                    ':tgl'     => $tanggal_bimbingan,
                    ':jam'     => strlen($jam_bimbingan) === 5 ? ($jam_bimbingan . ':00') : $jam_bimbingan,
                    ':bab'     => $bab_pembahasan,
                    ':catatan' => $catatan_full,
                    ':st'      => $status_awal
                ]);
                $bimbingan_id = $pdo->lastInsertId();

                $tgl_indo = date('d/m/Y', strtotime($tanggal_bimbingan));
                $jam_indo = substr($jam_bimbingan, 0, 5);

                // 2. Kirim Notifikasi ke MAHASISWA
                $stmtNotifM = $pdo->prepare("
                    INSERT INTO notifikasi (user_id, judul, pesan, tipe, link_tujuan)
                    VALUES (:uid, :judul, :pesan, 'pembimbing', 'logbook.php')
                ");
                $stmtNotifM->execute([
                    ':uid'   => $mhsData['user_id'],
                    ':judul' => 'Jadwal Bimbingan Ditetapkan',
                    ':pesan' => "Administrator telah menjadwalkan sesi bimbingan '{$bab_pembahasan}' bersama dosen pembimbing {$dsnData['nama_dosen']} pada tanggal {$tgl_indo} pukul {$jam_indo} WIB ({$metode_lokasi})."
                ]);

                // 3. Kirim Notifikasi ke DOSEN
                $stmtNotifD = $pdo->prepare("
                    INSERT INTO notifikasi (user_id, judul, pesan, tipe, link_tujuan)
                    VALUES (:uid, :judul, :pesan, 'pembimbing', 'riwayat_bimbingan.php')
                ");
                $stmtNotifD->execute([
                    ':uid'   => $dsnData['user_id'],
                    ':judul' => 'Jadwal Bimbingan Mahasiswa Ditetapkan',
                    ':pesan' => "Sesi bimbingan '{$bab_pembahasan}' bersama mahasiswa {$mhsData['nama_lengkap']} ({$mhsData['nim']} - {$mhsData['kelas']}) telah dijadwalkan pada tanggal {$tgl_indo} pukul {$jam_indo} WIB ({$metode_lokasi})."
                ]);

                $pdo->commit();
                $success_msg = "Jadwal bimbingan berhasil dibuat! Notifikasi otomatis telah dikirimkan ke Mahasiswa ({$mhsData['nama_lengkap']}) dan Dosen ({$dsnData['nama_dosen']}).";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error_msg = "Gagal membuat jadwal bimbingan: " . $e->getMessage();
            }
        }
    }

    // B. RESCHEDULE JADWAL (UBAH TANGGAL/JAM/LOKASI)
    elseif ($action === 'reschedule') {
        $id_bimbingan      = (int)($_POST['id_bimbingan'] ?? 0);
        $tanggal_bimbingan = trim($_POST['tanggal_bimbingan'] ?? '');
        $jam_bimbingan     = trim($_POST['jam_bimbingan'] ?? '');
        $bab_pembahasan    = trim($_POST['bab_pembahasan'] ?? '');
        $metode_lokasi     = trim($_POST['metode_lokasi'] ?? '');
        $catatan_admin     = trim($_POST['catatan_admin'] ?? '');

        if ($id_bimbingan <= 0 || empty($tanggal_bimbingan) || empty($jam_bimbingan)) {
            $error_msg = "ID Bimbingan, Tanggal, dan Jam baru wajib diisi.";
        } else {
            try {
                $stmtCek = $pdo->prepare("
                    SELECT pb.*, m.user_id AS mhs_uid, m.nama_lengkap AS mhs_nama, m.nim, m.kelas,
                           d.user_id AS dsn_uid, d.nama_dosen
                    FROM pengajuan_bimbingan pb
                    JOIN mahasiswa m ON pb.mahasiswa_id = m.id
                    JOIN dosen d ON pb.dosen_id = d.id
                    WHERE pb.id = :id LIMIT 1
                ");
                $stmtCek->execute([':id' => $id_bimbingan]);
                $bData = $stmtCek->fetch();

                if (!$bData) {
                    throw new Exception("Data sesi bimbingan tidak ditemukan.");
                }

                $pdo->beginTransaction();

                $catatan_update = "Lokasi/Media: " . $metode_lokasi;
                if (!empty($catatan_admin)) {
                    $catatan_update .= " | Catatan: " . $catatan_admin;
                }

                $stmtUp = $pdo->prepare("
                    UPDATE pengajuan_bimbingan 
                    SET tanggal_bimbingan = :tgl,
                        jam_bimbingan = :jam,
                        bab_pembahasan = :bab,
                        catatan_mahasiswa = :catatan,
                        status = 'disetujui'
                    WHERE id = :id
                ");
                $stmtUp->execute([
                    ':tgl'     => $tanggal_bimbingan,
                    ':jam'     => strlen($jam_bimbingan) === 5 ? ($jam_bimbingan . ':00') : $jam_bimbingan,
                    ':bab'     => !empty($bab_pembahasan) ? $bab_pembahasan : $bData['bab_pembahasan'],
                    ':catatan' => $catatan_update,
                    ':id'      => $id_bimbingan
                ]);

                $tgl_indo = date('d/m/Y', strtotime($tanggal_bimbingan));
                $jam_indo = substr($jam_bimbingan, 0, 5);

                // Notifikasi pembaruan ke Mahasiswa
                $pdo->prepare("
                    INSERT INTO notifikasi (user_id, judul, pesan, tipe, link_tujuan)
                    VALUES (:uid, 'Perubahan Jadwal Bimbingan', :pesan, 'pembimbing', 'logbook.php')
                ")->execute([
                    ':uid'   => $bData['mhs_uid'],
                    ':pesan' => "Administrator telah memperbarui jadwal bimbingan Anda bersama {$bData['nama_dosen']} menjadi tanggal {$tgl_indo} pukul {$jam_indo} WIB ({$metode_lokasi})."
                ]);

                // Notifikasi pembaruan ke Dosen
                $pdo->prepare("
                    INSERT INTO notifikasi (user_id, judul, pesan, tipe, link_tujuan)
                    VALUES (:uid, 'Perubahan Jadwal Bimbingan', :pesan, 'pembimbing', 'riwayat_bimbingan.php')
                ")->execute([
                    ':uid'   => $bData['dsn_uid'],
                    ':pesan' => "Administrator telah memperbarui jadwal bimbingan mahasiswa {$bData['mhs_nama']} ({$bData['nim']}) menjadi tanggal {$tgl_indo} pukul {$jam_indo} WIB ({$metode_lokasi})."
                ]);

                $pdo->commit();
                $success_msg = "Jadwal bimbingan berhasil diperbarui dan notifikasi telah dikirimkan ke kedua pihak!";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error_msg = "Gagal memperbarui jadwal: " . $e->getMessage();
            }
        }
    }

    // C. TANDAI SELESAI
    elseif ($action === 'complete') {
        $id_bimbingan = (int)($_POST['id_bimbingan'] ?? 0);
        if ($id_bimbingan > 0) {
            try {
                $stmtCek = $pdo->prepare("
                    SELECT pb.*, m.user_id AS mhs_uid, m.nama_lengkap AS mhs_nama, d.user_id AS dsn_uid, d.nama_dosen
                    FROM pengajuan_bimbingan pb
                    JOIN mahasiswa m ON pb.mahasiswa_id = m.id
                    JOIN dosen d ON pb.dosen_id = d.id
                    WHERE pb.id = :id LIMIT 1
                ");
                $stmtCek->execute([':id' => $id_bimbingan]);
                $bData = $stmtCek->fetch();

                if ($bData) {
                    $pdo->beginTransaction();
                    $pdo->prepare("UPDATE pengajuan_bimbingan SET status = 'selesai' WHERE id = :id")->execute([':id' => $id_bimbingan]);

                    // Kirim notifikasi selesai ke mahasiswa
                    $pdo->prepare("
                        INSERT INTO notifikasi (user_id, judul, pesan, tipe, link_tujuan)
                        VALUES (:uid, 'Sesi Bimbingan Selesai', :pesan, 'sukses', 'logbook.php')
                    ")->execute([
                        ':uid'   => $bData['mhs_uid'],
                        ':pesan' => "Sesi bimbingan '{$bData['bab_pembahasan']}' bersama {$bData['nama_dosen']} telah ditandai selesai."
                    ]);

                    $pdo->commit();
                    $success_msg = "Status sesi bimbingan berhasil ditandai 'Selesai'.";
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error_msg = "Gagal mengubah status: " . $e->getMessage();
            }
        }
    }

    // D. HAPUS SESI BIMBINGAN
    elseif ($action === 'delete') {
        $id_bimbingan = (int)($_POST['id_bimbingan'] ?? 0);
        if ($id_bimbingan > 0) {
            try {
                $pdo->prepare("DELETE FROM pengajuan_bimbingan WHERE id = :id")->execute([':id' => $id_bimbingan]);
                $success_msg = "Data sesi bimbingan berhasil dihapus dari sistem.";
            } catch (Exception $e) {
                $error_msg = "Gagal menghapus sesi: " . $e->getMessage();
            }
        }
    }
}

// =========================================================================
// 2. QUERY DAFTAR MAHASISWA YANG MEMILIKI DOSEN PEMBIMBING (UNTUK MODAL)
// =========================================================================
$mhs_eligible = $pdo->query("
    SELECT m.id AS mhs_id, m.user_id AS mhs_uid, m.nim, m.nama_lengkap AS mhs_nama, m.kelas, m.judul_skripsi,
           d.id AS dosen_id, d.user_id AS dsn_uid, d.nama_dosen, m.status_pembimbing
    FROM mahasiswa m
    INNER JOIN dosen d ON m.dosen_pembimbing_id = d.id
    ORDER BY m.kelas ASC, m.nama_lengkap ASC
")->fetchAll();

// =========================================================================
// 3. FILTER & DAFTAR SESI BIMBINGAN
// =========================================================================
$filter_status = trim($_GET['status'] ?? '');
$filter_kelas  = trim($_GET['kelas'] ?? '');
$filter_dosen  = (int)($_GET['dosen_id'] ?? 0);
$search_query  = trim($_GET['q'] ?? '');

$where = ["1=1"];
$params = [];

if (!empty($filter_status)) {
    $where[] = "pb.status = :status";
    $params[':status'] = $filter_status;
}
if (!empty($filter_kelas)) {
    $where[] = "m.kelas = :kelas";
    $params[':kelas'] = $filter_kelas;
}
if ($filter_dosen > 0) {
    $where[] = "pb.dosen_id = :did";
    $params[':did'] = $filter_dosen;
}
if (!empty($search_query)) {
    $search_tokens = array_filter(explode(' ', preg_replace('/\s+/', ' ', $search_query)), function($t) {
        return strlen(trim($t)) > 0;
    });
    if (!empty($search_tokens)) {
        $token_clauses = [];
        $t_idx = 0;
        foreach ($search_tokens as $token) {
            $t_idx++;
            $k_nama  = ":q_nama_{$t_idx}";
            $k_nim   = ":q_nim_{$t_idx}";
            $k_dosen = ":q_dsn_{$t_idx}";
            $k_bab   = ":q_bab_{$t_idx}";

            $token_clauses[] = "(m.nama_lengkap LIKE {$k_nama} OR m.nim LIKE {$k_nim} OR d.nama_dosen LIKE {$k_dosen} OR pb.bab_pembahasan LIKE {$k_bab})";
            
            $val = "%{$token}%";
            $params[$k_nama]  = $val;
            $params[$k_nim]   = $val;
            $params[$k_dosen] = $val;
            $params[$k_bab]   = $val;
        }
        $where[] = "(" . implode(" AND ", $token_clauses) . ")";
    }

}

$where_sql = implode(" AND ", $where);

$query_list = "
    SELECT pb.*, 
           m.nim, m.nama_lengkap AS mhs_nama, m.kelas, m.judul_skripsi, m.foto_profil AS mhs_foto,
           d.nama_dosen, d.nidn, d.foto_profil AS dsn_foto
    FROM pengajuan_bimbingan pb
    INNER JOIN mahasiswa m ON pb.mahasiswa_id = m.id
    INNER JOIN dosen d ON pb.dosen_id = d.id
    WHERE {$where_sql}
    ORDER BY pb.tanggal_bimbingan DESC, pb.jam_bimbingan DESC
";
$stmt_list = $pdo->prepare($query_list);
$stmt_list->execute($params);
$bimbingan_list = $stmt_list->fetchAll();

// Statistik Cepat
$total_all       = (int)$pdo->query("SELECT COUNT(*) FROM pengajuan_bimbingan")->fetchColumn();
$total_disetujui = (int)$pdo->query("SELECT COUNT(*) FROM pengajuan_bimbingan WHERE status = 'disetujui'")->fetchColumn();
$total_menunggu  = (int)$pdo->query("SELECT COUNT(*) FROM pengajuan_bimbingan WHERE status = 'menunggu'")->fetchColumn();
$total_selesai   = (int)$pdo->query("SELECT COUNT(*) FROM pengajuan_bimbingan WHERE status = 'selesai'")->fetchColumn();

// Daftar Dosen untuk Filter
$dosen_filter_list = $pdo->query("SELECT id, nama_dosen FROM dosen ORDER BY nama_dosen ASC")->fetchAll();

$page_title   = "Jadwal Bimbingan Skripsi";
$current_page = "jadwal_bimbingan";

require_once '../templates/header.php';
require_once '../templates/sidebar_admin.php';
?>

<!-- Hero Banner Header -->
<div class="hero-banner d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
    <div>
        <div class="hero-tag">
            <i class="bi bi-calendar2-range-fill text-warning"></i>
            <span>Layanan Akademik Skripsi</span>
        </div>
        <h2 class="fw-bold text-white mb-1">Penjadwalan Bimbingan Mahasiswa & Dosen</h2>
        <p class="text-white-50 small mb-0">Atur jadwal pertemuan bimbingan resmi dan kirimkan notifikasi otomatis ke akun mahasiswa & dosen pembimbing.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <button type="button" class="btn btn-light shadow-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalTambahBimbingan">
            <i class="bi bi-calendar-plus-fill text-primary me-1"></i> Buat Jadwal Bimbingan
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

<!-- Metrik Statistik Ringkas -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="metric-card p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="small text-muted fw-bold text-uppercase">Total Sesi</span>
                    <h3 class="fw-bold text-dark mb-0 mt-1"><?= $total_all; ?></h3>
                </div>
                <div class="metric-icon-box bg-primary-subtle text-primary">
                    <i class="bi bi-journals"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="metric-card p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="small text-muted fw-bold text-uppercase">Terjadwal (Disetujui)</span>
                    <h3 class="fw-bold text-success mb-0 mt-1"><?= $total_disetujui; ?></h3>
                </div>
                <div class="metric-icon-box bg-success-subtle text-success">
                    <i class="bi bi-calendar-check"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="metric-card p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="small text-muted fw-bold text-uppercase">Menunggu</span>
                    <h3 class="fw-bold text-warning mb-0 mt-1"><?= $total_menunggu; ?></h3>
                </div>
                <div class="metric-icon-box bg-warning-subtle text-warning">
                    <i class="bi bi-clock-history"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="metric-card p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="small text-muted fw-bold text-uppercase">Telah Selesai</span>
                    <h3 class="fw-bold text-info mb-0 mt-1"><?= $total_selesai; ?></h3>
                </div>
                <div class="metric-icon-box bg-info-subtle text-info">
                    <i class="bi bi-check2-all"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filter & Search Toolbar -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-3">
        <form method="GET" action="jadwal_bimbingan.php" class="row g-2 align-items-center">
            <div class="col-md-3">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="q" class="form-control" placeholder="Cari nama, NIM, topik..." value="<?= htmlspecialchars($search_query); ?>">
                </div>
            </div>
            <div class="col-md-2">
                <select name="status" class="form-select" onchange="this.form.submit()">
                    <option value="">-- Semua Status --</option>
                    <option value="disetujui" <?= ($filter_status === 'disetujui') ? 'selected' : ''; ?>>Disetujui (Terjadwal)</option>
                    <option value="menunggu" <?= ($filter_status === 'menunggu') ? 'selected' : ''; ?>>Menunggu</option>
                    <option value="selesai" <?= ($filter_status === 'selesai') ? 'selected' : ''; ?>>Selesai</option>
                    <option value="ditolak" <?= ($filter_status === 'ditolak') ? 'selected' : ''; ?>>Ditolak</option>
                </select>
            </div>
            <div class="col-md-2">
                <select name="kelas" class="form-select" onchange="this.form.submit()">
                    <option value="">-- Semua Kelas --</option>
                    <option value="TI-1A-2025" <?= ($filter_kelas === 'TI-1A-2025') ? 'selected' : ''; ?>>TI-1A-2025</option>
                    <option value="TI-1B-2025" <?= ($filter_kelas === 'TI-1B-2025') ? 'selected' : ''; ?>>TI-1B-2025</option>
                    <option value="TI-1C-2025" <?= ($filter_kelas === 'TI-1C-2025') ? 'selected' : ''; ?>>TI-1C-2025</option>
                </select>
            </div>
            <div class="col-md-3">
                <select name="dosen_id" class="form-select" onchange="this.form.submit()">
                    <option value="0">-- Semua Dosen Pembimbing --</option>
                    <?php foreach ($dosen_filter_list as $df): ?>
                        <option value="<?= $df['id']; ?>" <?= ($filter_dosen === (int)$df['id']) ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($df['nama_dosen']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-filter me-1"></i>Filter</button>
                <?php if (!empty($filter_status) || !empty($filter_kelas) || $filter_dosen > 0 || !empty($search_query)): ?>
                    <a href="jadwal_bimbingan.php" class="btn btn-outline-secondary" title="Reset Filter"><i class="bi bi-arrow-counterclockwise"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Tabel Sesi Bimbingan -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2">
            <span class="p-2 rounded bg-primary-subtle text-primary">
                <i class="bi bi-calendar2-range"></i>
            </span>
            <div>
                <h6 class="fw-bold mb-0 text-dark">Daftar Jadwal Sesi Pertemuan Bimbingan</h6>
                <small class="text-muted">Total <?= count($bimbingan_list); ?> sesi ditemukan</small>
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3" style="width: 50px;">No</th>
                        <th>Mahasiswa</th>
                        <th>Dosen Pembimbing</th>
                        <th>Waktu & Tanggal</th>
                        <th>Topik / Bab & Lokasi</th>
                        <th class="text-center">Status Sesi</th>
                        <th class="text-center" style="width: 150px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bimbingan_list)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-5">
                                <i class="bi bi-calendar-x fs-2 d-block text-secondary mb-2"></i>
                                Belum ada jadwal bimbingan yang sesuai dengan filter pencarian.<br>
                                <button type="button" class="btn btn-sm btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#modalTambahBimbingan">
                                    <i class="bi bi-plus-circle me-1"></i> Buat Jadwal Bimbingan Sekarang
                                </button>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $no = 1;
                        foreach ($bimbingan_list as $row): 
                            $status_badge = [
                                'disetujui' => 'bg-success',
                                'menunggu'  => 'bg-warning text-dark',
                                'selesai'   => 'bg-info text-dark',
                                'ditolak'   => 'bg-danger',
                                'revisi'    => 'bg-secondary'
                            ];
                            $st_badge = $status_badge[$row['status']] ?? 'bg-secondary';
                            $hari_indo = [
                                'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa',
                                'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'
                            ][date('l', strtotime($row['tanggal_bimbingan']))] ?? '';
                        ?>
                            <tr>
                                <td class="ps-3 text-muted"><?= $no++; ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="avatar-initial bg-primary-subtle text-primary">
                                            <?= strtoupper(substr($row['mhs_nama'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($row['mhs_nama']); ?></div>
                                            <div class="font-monospace text-primary small">NIM: <?= htmlspecialchars($row['nim']); ?></div>
                                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle" style="font-size: 0.68rem;">
                                                <?= htmlspecialchars($row['kelas']); ?>
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= htmlspecialchars($row['nama_dosen']); ?></div>
                                    <div class="text-muted small font-monospace">NIDN: <?= htmlspecialchars($row['nidn']); ?></div>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark">
                                        <i class="bi bi-calendar-event me-1 text-primary"></i>
                                        <?= $hari_indo; ?>, <?= date('d/m/Y', strtotime($row['tanggal_bimbingan'])); ?>
                                    </div>
                                    <div class="text-muted font-monospace small">
                                        <i class="bi bi-clock me-1 text-secondary"></i>
                                        <?= substr($row['jam_bimbingan'], 0, 5); ?> WIB
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-bold text-primary"><?= htmlspecialchars($row['bab_pembahasan']); ?></div>
                                    <div class="small text-muted" style="max-width: 280px;">
                                        <?= htmlspecialchars($row['catatan_mahasiswa'] ?: 'Tidak ada catatan lokasi'); ?>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <span class="badge <?= $st_badge; ?> px-3 py-1 text-uppercase" style="font-size: 0.72rem;">
                                        <?= htmlspecialchars($row['status']); ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm">
                                        <!-- Tombol Reschedule / Edit -->
                                        <button type="button" class="btn btn-outline-primary btn-reschedule"
                                                data-bs-toggle="modal" data-bs-target="#modalReschedule"
                                                data-id="<?= $row['id']; ?>"
                                                data-mhs="<?= htmlspecialchars($row['mhs_nama']); ?>"
                                                data-nim="<?= htmlspecialchars($row['nim']); ?>"
                                                data-dosen="<?= htmlspecialchars($row['nama_dosen']); ?>"
                                                data-tgl="<?= $row['tanggal_bimbingan']; ?>"
                                                data-jam="<?= substr($row['jam_bimbingan'], 0, 5); ?>"
                                                data-bab="<?= htmlspecialchars($row['bab_pembahasan']); ?>"
                                                data-catatan="<?= htmlspecialchars($row['catatan_mahasiswa']); ?>"
                                                title="Ubah Jadwal / Reschedule">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>

                                        <!-- Tombol Tandai Selesai jika disetujui -->
                                        <?php if ($row['status'] === 'disetujui'): ?>
                                            <form method="POST" action="jadwal_bimbingan.php" class="d-inline" onsubmit="return confirm('Tandai sesi bimbingan ini sebagai SELESAI?');">
                                                <input type="hidden" name="action" value="complete">
                                                <input type="hidden" name="id_bimbingan" value="<?= $row['id']; ?>">
                                                <button type="submit" class="btn btn-outline-success" title="Tandai Selesai">
                                                    <i class="bi bi-check-circle"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <!-- Tombol Hapus Jadwal -->
                                        <form method="POST" action="jadwal_bimbingan.php" class="d-inline" onsubmit="return confirm('Apakah Anda yakin ingin menghapus jadwal bimbingan ini?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id_bimbingan" value="<?= $row['id']; ?>">
                                            <button type="submit" class="btn btn-outline-danger" title="Hapus Sesi">
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
<!-- MODAL TAMBAH JADWAL BIMBINGAN BARU -->
<!-- ========================================================================= -->
<div class="modal fade" id="modalTambahBimbingan" tabindex="-1" aria-labelledby="modalTambahBimbinganLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" action="jadwal_bimbingan.php" class="modal-content shadow">
            <input type="hidden" name="action" value="create">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold" id="modalTambahBimbinganLabel">
                    <i class="bi bi-calendar-plus-fill me-2"></i>Jadwalkan Sesi Bimbingan Baru
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="alert alert-info border-info-subtle small mb-3">
                    <i class="bi bi-bell-fill text-primary me-1"></i>
                    <strong>Pemberitahuan Otomatis:</strong> Setelah disimpan, sistem akan secara otomatis mengirimkan <strong>notifikasi masuk</strong> ke dashboard <strong>Mahasiswa</strong> dan <strong>Dosen Pembimbing</strong> yang bersangkutan.
                </div>

                <div class="row g-3">
                    <!-- Pilih Mahasiswa -->
                    <div class="col-md-12">
                        <label for="selectMahasiswa" class="form-label small fw-semibold">Pilih Mahasiswa Bimbingan <span class="text-danger">*</span></label>
                        <select name="mahasiswa_id" id="selectMahasiswa" class="form-select" required onchange="handleSelectMhs(this)">
                            <option value="">-- Pilih Mahasiswa (Hanya yang memiliki dosen pembimbing) --</option>
                            <?php foreach ($mhs_eligible as $me): ?>
                                <option value="<?= $me['mhs_id']; ?>"
                                        data-dosenid="<?= $me['dosen_id']; ?>"
                                        data-dosennama="<?= htmlspecialchars($me['nama_dosen']); ?>"
                                        data-judul="<?= htmlspecialchars($me['judul_skripsi']); ?>"
                                        data-kelas="<?= htmlspecialchars($me['kelas']); ?>">
                                    <?= htmlspecialchars($me['nim']); ?> - <?= htmlspecialchars($me['mhs_nama']); ?> (<?= htmlspecialchars($me['kelas']); ?>) — Dosen: <?= htmlspecialchars($me['nama_dosen']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Dosen Pembimbing (Terisi Otomatis) -->
                    <div class="col-md-12">
                        <label class="form-label small fw-semibold">Dosen Pembimbing Terpilih</label>
                        <input type="hidden" name="dosen_id" id="inputDosenId" value="">
                        <input type="text" id="displayNamaDosen" class="form-control bg-light fw-bold text-primary" placeholder="Akan terisi otomatis saat mahasiswa dipilih" readonly>
                    </div>

                    <!-- Tanggal & Jam Bimbingan -->
                    <div class="col-md-6">
                        <label for="inputTanggal" class="form-label small fw-semibold">Tanggal Bimbingan <span class="text-danger">*</span></label>
                        <input type="date" name="tanggal_bimbingan" id="inputTanggal" class="form-control" required value="<?= date('Y-m-d'); ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="inputJam" class="form-label small fw-semibold">Jam Mulai <span class="text-danger">*</span></label>
                        <input type="time" name="jam_bimbingan" id="inputJam" class="form-control" required value="09:00">
                    </div>

                    <!-- Bab Pembahasan -->
                    <div class="col-md-6">
                        <label for="inputBab" class="form-label small fw-semibold">Bab / Topik Bimbingan <span class="text-danger">*</span></label>
                        <select name="bab_pembahasan" id="inputBab" class="form-select" required>
                            <option value="Bab 1 - Pendahuluan">Bab 1 - Pendahuluan</option>
                            <option value="Bab 2 - Tinjauan Pustaka">Bab 2 - Tinjauan Pustaka</option>
                            <option value="Bab 3 - Metodologi Penelitian">Bab 3 - Metodologi Penelitian</option>
                            <option value="Bab 4 - Hasil dan Pembahasan">Bab 4 - Hasil dan Pembahasan</option>
                            <option value="Bab 5 - Kesimpulan dan Saran">Bab 5 - Kesimpulan dan Saran</option>
                            <option value="Konsultasi Judul & Proposal">Konsultasi Judul & Proposal</option>
                            <option value="Revisi Draft Lengkap Skripsi">Revisi Draft Lengkap Skripsi</option>
                            <option value="Persiapan Pra-Sidang Skripsi">Persiapan Pra-Sidang Skripsi</option>
                        </select>
                    </div>

                    <!-- Lokasi / Media Pertemuan -->
                    <div class="col-md-6">
                        <label for="inputLokasi" class="form-label small fw-semibold">Metode / Ruangan Pertemuan <span class="text-danger">*</span></label>
                        <select name="metode_lokasi" id="inputLokasi" class="form-select" required>
                            <option value="Tatap Muka di Ruang Dosen">Tatap Muka di Ruang Dosen</option>
                            <option value="Tatap Muka di Laboratorium TI">Tatap Muka di Laboratorium TI</option>
                            <option value="Tatap Muka di Ruang Jurusan">Tatap Muka di Ruang Jurusan</option>
                            <option value="Daring via Google Meet / Zoom">Daring via Google Meet / Zoom</option>
                        </select>
                    </div>

                    <!-- Catatan Tambahan -->
                    <div class="col-12">
                        <label for="catatanAdmin" class="form-label small fw-semibold">Catatan / Instruksi Sesi <span class="text-muted fw-normal">(Opsional)</span></label>
                        <textarea name="catatan_admin" id="catatanAdmin" class="form-control" rows="2" placeholder="Contoh: Harap mahasiswa membawa printout bab 1 dan tabel use case."></textarea>
                    </div>

                    <!-- Status Penetapan -->
                    <div class="col-md-12">
                        <label class="form-label small fw-semibold">Status Awal Jadwal</label>
                        <select name="status" class="form-select">
                            <option value="disetujui" selected>Disetujui (Langsung Terjadwal & Aktif)</option>
                            <option value="menunggu">Menunggu Konfirmasi Tambahan</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary fw-semibold">
                    <i class="bi bi-send-check-fill me-1"></i> Simpan & Kirim Notifikasi
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL RESCHEDULE / UBAH JADWAL BIMBINGAN -->
<!-- ========================================================================= -->
<div class="modal fade" id="modalReschedule" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" action="jadwal_bimbingan.php" class="modal-content shadow">
            <input type="hidden" name="action" value="reschedule">
            <input type="hidden" name="id_bimbingan" id="reschedule_id">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-pencil-square me-2"></i>Ubah / Reschedule Jadwal Bimbingan
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="alert alert-warning-subtle border border-warning-subtle small mb-3">
                    <i class="bi bi-info-circle-fill text-warning me-1"></i>
                    Perubahan jadwal akan <strong>otomatis memperbarui notifikasi</strong> pada akun Mahasiswa dan Dosen terkait.
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Mahasiswa</label>
                        <input type="text" id="reschedule_mhs" class="form-control bg-light" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Dosen Pembimbing</label>
                        <input type="text" id="reschedule_dosen" class="form-control bg-light" readonly>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Tanggal Baru <span class="text-danger">*</span></label>
                        <input type="date" name="tanggal_bimbingan" id="reschedule_tgl" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Jam Baru <span class="text-danger">*</span></label>
                        <input type="time" name="jam_bimbingan" id="reschedule_jam" class="form-control" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Bab / Topik Bimbingan</label>
                        <input type="text" name="bab_pembahasan" id="reschedule_bab" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Lokasi / Ruangan Pertemuan</label>
                        <input type="text" name="metode_lokasi" id="reschedule_lokasi" class="form-control" placeholder="Contoh: Ruang Dosen Lt. 2">
                    </div>

                    <div class="col-12">
                        <label class="form-label small fw-semibold">Catatan / Alasan Perubahan</label>
                        <textarea name="catatan_admin" id="reschedule_catatan" class="form-control" rows="2" placeholder="Catatan perubahan jadwal..."></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-warning fw-bold text-dark">
                    <i class="bi bi-check-circle me-1"></i> Simpan Perubahan Jadwal
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function handleSelectMhs(select) {
    const selectedOption = select.options[select.selectedIndex];
    const dosenId = selectedOption.getAttribute('data-dosenid') || '';
    const dosenNama = selectedOption.getAttribute('data-dosennama') || '';

    document.getElementById('inputDosenId').value = dosenId;
    document.getElementById('displayNamaDosen').value = dosenNama ? dosenNama : 'Dosen belum ditentukan';
}

document.addEventListener('DOMContentLoaded', function() {
    // Populate Modal Reschedule
    const rescheduleBtns = document.querySelectorAll('.btn-reschedule');
    rescheduleBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('reschedule_id').value      = this.dataset.id;
            document.getElementById('reschedule_mhs').value     = this.dataset.mhs + " (" + this.dataset.nim + ")";
            document.getElementById('reschedule_dosen').value   = this.dataset.dosen;
            document.getElementById('reschedule_tgl').value     = this.dataset.tgl;
            document.getElementById('reschedule_jam').value     = this.dataset.jam;
            document.getElementById('reschedule_bab').value     = this.dataset.bab;
            document.getElementById('reschedule_lokasi').value  = "Tatap Muka di Ruang Dosen";
            document.getElementById('reschedule_catatan').value = this.dataset.catatan || '';
        });
    });
});
</script>

<?php require_once '../templates/footer.php'; ?>

