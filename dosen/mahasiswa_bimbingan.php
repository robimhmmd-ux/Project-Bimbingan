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

// 1. PROSES SIMPAN / ATUR CATATAN PROGRES MAHASISWA OLEH DOSEN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_catatan_progres'])) {
    $mahasiswa_id      = (int)($_POST['mahasiswa_id'] ?? 0);
    $bab_pembahasan    = trim($_POST['bab_pembahasan'] ?? '');
    $tanggal_bimbingan = trim($_POST['tanggal_bimbingan'] ?? date('Y-m-d'));
    $jam_bimbingan     = trim($_POST['jam_bimbingan'] ?? date('H:i:s'));
    $nilai_progres     = (int)($_POST['nilai_progres'] ?? 0);
    $keputusan_progres = trim($_POST['keputusan_progres'] ?? 'acc_bab'); // acc_bab, revisi, acc_sidang, selesai
    $status_kelulusan  = trim($_POST['status_kelulusan'] ?? 'proses_bimbingan');
    $catatan_dosen     = trim($_POST['catatan_dosen'] ?? '');

    // Batasi nilai progres (0 - 100)
    if ($nilai_progres < 0) $nilai_progres = 0;
    if ($nilai_progres > 100) $nilai_progres = 100;

    if ($mahasiswa_id <= 0) {
        $error_msg = "Data mahasiswa tidak valid.";
    } elseif (empty($bab_pembahasan)) {
        $error_msg = "Bab pembahasan wajib ditentukan.";
    } elseif (empty($catatan_dosen)) {
        $error_msg = "Catatan arahan / instruksi progres dari dosen wajib diisi.";
    } else {
        try {
            $pdo->beginTransaction();

            // Tentukan status sesi bimbingan
            $status_sesi = ($keputusan_progres === 'revisi') ? 'revisi' : 'selesai';

            // A. Simpan sesi bimbingan baru ke pengajuan_bimbingan
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
                ':bab'       => $bab_pembahasan,
                ':cat_mhs'   => 'Sesi bimbingan & pencatatan progres langsung bersama Dosen Pembimbing',
                ':st_sesi'   => $status_sesi,
                ':cat_dosen' => $catatan_dosen,
                ':nilai'     => $nilai_progres
            ]);
            $bimbingan_id = (int)$pdo->lastInsertId();

            // B. Simpan ke logbook_bimbingan resmi mahasiswa
            $status_bab_log = ($keputusan_progres === 'acc_sidang') ? 'acc_sidang' : (($keputusan_progres === 'acc_bab') ? 'acc_bab' : 'revisi');
            $stmt_lb = $pdo->prepare("
                INSERT INTO logbook_bimbingan (bimbingan_id, mahasiswa_id, tanggal_acc, catatan_hasil, status_bab)
                VALUES (:bid, :mid, :tgl_acc, :cat_hasil, :sbab)
            ");
            $stmt_lb->execute([
                ':bid'       => $bimbingan_id,
                ':mid'       => $mahasiswa_id,
                ':tgl_acc'   => $tanggal_bimbingan,
                ':cat_hasil' => $catatan_dosen,
                ':sbab'      => $status_bab_log
            ]);

            // C. Perbarui status kelulusan di tabel mahasiswa
            $final_status_kelulusan = ($keputusan_progres === 'acc_sidang') ? 'siap_sidang' : $status_kelulusan;
            $stmt_up_mhs = $pdo->prepare("
                UPDATE mahasiswa 
                SET status_kelulusan = :stk 
                WHERE id = :mid AND dosen_pembimbing_id = :did
            ");
            $stmt_up_mhs->execute([
                ':stk' => $final_status_kelulusan,
                ':mid' => $mahasiswa_id,
                ':did' => $dosen_id
            ]);

            $pdo->commit();
            $success_msg = "Catatan progres & arahan bimbingan berhasil disimpan! Progres <strong>{$nilai_progres}%</strong> untuk <strong>" . htmlspecialchars($bab_pembahasan) . "</strong> telah diterbitkan ke kartu kendali & dashboard mahasiswa.";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error_msg = "Gagal menyimpan catatan progres: " . $e->getMessage();
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
<div class="row g-3 mb-4">
    <!-- Total Mahasiswa -->
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 shadow-sm border-0 border-start border-primary border-4 h-100 bg-white">
            <h6 class="text-muted small text-uppercase fw-semibold mb-1">Mahasiswa Bimbingan</h6>
            <h3 class="fw-bold text-dark mb-1"><?= $total_mhs; ?> <span class="fs-6 text-muted fw-normal">/ <?= htmlspecialchars($dosen['kuota_bimbingan'] ?? '10'); ?> Kuota</span></h3>
            <p class="text-muted small mb-0">Terisi <?= round(($total_mhs / max(1, (int)($dosen['kuota_bimbingan'] ?? 10))) * 100); ?>% dari kapasitas</p>
        </div>
    </div>

    <!-- Dalam Proses Bimbingan -->
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 shadow-sm border-0 border-start border-warning border-4 h-100 bg-white">
            <h6 class="text-muted small text-uppercase fw-semibold mb-1">Proses Bimbingan</h6>
            <h3 class="fw-bold text-warning mb-1"><?= $count_proses; ?> <span class="fs-6 text-muted fw-normal">Mahasiswa</span></h3>
            <p class="text-muted small mb-0">Sedang tahap konsultasi naskah</p>
        </div>
    </div>

    <!-- Siap Sidang / Lulus -->
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 shadow-sm border-0 border-start border-success border-4 h-100 bg-white">
            <h6 class="text-muted small text-uppercase fw-semibold mb-1">Siap Sidang Skripsi</h6>
            <h3 class="fw-bold text-success mb-1"><?= $count_siap_sidang; ?> <span class="fs-6 text-muted fw-normal">Mahasiswa</span></h3>
            <p class="text-muted small mb-0">Naskah tuntas & ter-ACC</p>
        </div>
    </div>

    <!-- Rata-rata Progres -->
    <div class="col-sm-6 col-xl-3">
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
                        <th style="width: 40px;" class="text-center">No</th>
                        <th style="min-width: 200px;">Mahasiswa & Judul Skripsi</th>
                        <th style="min-width: 130px;">Progres Skripsi</th>
                        <th style="min-width: 170px;">Bab & Catatan Dosen Terakhir</th>
                        <th style="width: 90px;" class="text-center">Total Sesi</th>
                        <th style="width: 130px;" class="text-center">Status</th>
                        <th style="width: 220px;" class="text-center">Aksi / Tindakan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($daftar_mahasiswa)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="bi bi-people fs-1 d-block mb-2 text-muted"></i>
                                Tidak ada mahasiswa bimbingan yang sesuai dengan kriteria pencarian.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($daftar_mahasiswa as $idx => $mhs): ?>
                            <?php
                            $progres = (int)$mhs['progres_tertinggi'];
                            $bar_color = ($progres >= 80) ? 'bg-success' : (($progres >= 50) ? 'bg-info' : 'bg-warning');
                            
                            // Query riwayat bimbingan lengkap untuk modal mahasiswa ini
                            $stmt_hist = $pdo->prepare("
                                SELECT * FROM pengajuan_bimbingan 
                                WHERE mahasiswa_id = :mid AND dosen_id = :did 
                                ORDER BY tanggal_bimbingan DESC, jam_bimbingan DESC
                            ");
                            $stmt_hist->execute([':mid' => $mhs['id'], ':did' => $dosen_id]);
                            $hist_list = $stmt_hist->fetchAll();
                            ?>
                            <tr>
                                <td class="text-center fw-semibold text-muted"><?= $idx + 1; ?></td>
                                <td>
                                    <div class="d-flex align-items-start gap-2">
                                        <div class="avatar-circle avatar-sm avatar-primary mt-1">
                                            <?= strtoupper(substr($mhs['nama_lengkap'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($mhs['nama_lengkap']); ?></div>
                                            <small class="text-muted">NIM: <?= htmlspecialchars($mhs['nim']); ?></small>
                                            <div class="text-truncate small text-secondary mt-1" style="max-width: 240px;" title="<?= htmlspecialchars($mhs['judul_skripsi']); ?>">
                                                <i class="bi bi-mortarboard me-1 text-primary"></i><?= htmlspecialchars($mhs['judul_skripsi']); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <small class="fw-bold text-dark"><?= $progres; ?>%</small>
                                        <small class="text-muted" style="font-size: 0.75rem;">
                                            <?= ($progres >= 100) ? 'Tuntas' : (($progres >= 50) ? 'Lanjut' : 'Awal'); ?>
                                        </small>
                                    </div>
                                    <div class="progress" style="height: 8px;">
                                        <div class="progress-bar <?= $bar_color; ?>" role="progressbar" style="width: <?= $progres; ?>%;" aria-valuenow="<?= $progres; ?>" aria-valuemin="0" aria-valuemax="100"></div>
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
                                            <div class="text-truncate text-secondary small mt-1" style="max-width: 220px;" title="<?= htmlspecialchars($mhs['catatan_terakhir']); ?>">
                                                <i class="bi bi-chat-left-quote me-1 text-primary"></i><?= htmlspecialchars($mhs['catatan_terakhir']); ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted small fst-italic">Belum ada catatan progres</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="fw-bold text-dark"><?= (int)$mhs['total_sesi']; ?></span> <small class="text-muted">Sesi</small>
                                </td>
                                <td class="text-center">
                                    <?php
                                    $stk = $mhs['status_kelulusan'];
                                    if ($stk === 'siap_sidang') {
                                        echo '<span class="badge bg-success-subtle text-success border border-success-subtle"><i class="bi bi-check-circle me-1"></i>Siap Sidang</span>';
                                    } elseif ($stk === 'lulus') {
                                        echo '<span class="badge bg-primary"><i class="bi bi-mortarboard-fill me-1"></i>Lulus</span>';
                                    } else {
                                        echo '<span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>Proses</span>';
                                    }
                                    ?>
                                </td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center align-items-center gap-1">
                                        <!-- Tombol Utama: Atur Catatan Progres & Penilaian -->
                                        <button type="button" 
                                                class="btn btn-sm btn-primary" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#modalAturProgres<?= $mhs['id']; ?>"
                                                title="Atur Catatan Progres, Nilai, dan Status Mahasiswa">
                                            <i class="bi bi-pencil-square me-1"></i>Atur Progres
                                        </button>

                                        <!-- Tombol 2: Detail Timeline Riwayat Bimbingan -->
                                        <button type="button" 
                                                class="btn btn-sm btn-outline-secondary" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#modalRiwayatMhs<?= $mhs['id']; ?>"
                                                title="Lihat Timeline Riwayat & Logbook">
                                            <i class="bi bi-clock-history"></i>
                                        </button>

                                        <!-- Tombol 3: Chat Langsung -->
                                        <a href="chat.php?uid=<?= $mhs['mhs_user_id']; ?>" 
                                           class="btn btn-sm btn-outline-success" 
                                           title="Kirim Pesan Konsultasi">
                                            <i class="bi bi-chat-dots-fill"></i>
                                        </a>

                                        <!-- Tombol 4: Cetak Laporan Mahasiswa Ini -->
                                        <a href="cetak_laporan.php?mahasiswa_id=<?= $mhs['id']; ?>" 
                                           class="btn btn-sm btn-outline-secondary" 
                                           title="Cetak Lembar Rekapan Mahasiswa Ini">
                                            <i class="bi bi-printer"></i>
                                        </a>
                                    </div>

                                    <!-- MODAL 1: ATUR CATATAN PROGRES MAHASISWA OLEH DOSEN -->
                                    <div class="modal fade" id="modalAturProgres<?= $mhs['id']; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-lg text-start">
                                            <div class="modal-content">
                                                <form method="POST" action="mahasiswa_bimbingan.php">
                                                    <input type="hidden" name="simpan_catatan_progres" value="1">
                                                    <input type="hidden" name="mahasiswa_id" value="<?= $mhs['id']; ?>">

                                                    <div class="modal-header bg-primary text-white">
                                                        <h5 class="modal-title fw-bold">
                                                            <i class="bi bi-pencil-square me-2"></i>Atur Catatan Progres Skripsi
                                                        </h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>

                                                    <div class="modal-body p-4">
                                                        <!-- Ringkasan Mahasiswa -->
                                                        <div class="p-3 bg-light rounded border mb-4">
                                                            <div class="row g-2 small">
                                                                <div class="col-md-6">
                                                                    <span class="text-muted d-block">Mahasiswa:</span>
                                                                    <strong class="text-dark fs-6"><?= htmlspecialchars($mhs['nama_lengkap']); ?></strong>
                                                                    <span class="text-muted d-block">NIM: <?= htmlspecialchars($mhs['nim']); ?></span>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <span class="text-muted d-block">Capaian Progres Saat Ini:</span>
                                                                    <span class="badge bg-primary fs-6 px-3 py-1"><?= $progres; ?>% Selesai</span>
                                                                </div>
                                                                <div class="col-12 mt-2">
                                                                    <span class="text-muted d-block">Judul Skripsi:</span>
                                                                    <span class="fw-semibold text-dark"><?= htmlspecialchars($mhs['judul_skripsi']); ?></span>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <!-- Form Input Progres oleh Dosen -->
                                                        <div class="row g-3">
                                                            <!-- Bab Pembahasan -->
                                                            <div class="col-md-6">
                                                                <label class="form-label fw-bold small text-dark">
                                                                    <i class="bi bi-journal-bookmark text-primary me-1"></i>Bab Pembahasan *
                                                                </label>
                                                                <select name="bab_pembahasan" class="form-select" required>
                                                                    <option value="Bab 1: Pendahuluan" <?= ($mhs['bab_terakhir'] === 'Bab 1: Pendahuluan') ? 'selected' : ''; ?>>Bab 1: Pendahuluan</option>
                                                                    <option value="Bab 2: Tinjauan Pustaka" <?= ($mhs['bab_terakhir'] === 'Bab 2: Tinjauan Pustaka') ? 'selected' : ''; ?>>Bab 2: Tinjauan Pustaka</option>
                                                                    <option value="Bab 3: Metodologi Penelitian" <?= ($mhs['bab_terakhir'] === 'Bab 3: Metodologi Penelitian') ? 'selected' : ''; ?>>Bab 3: Metodologi Penelitian</option>
                                                                    <option value="Bab 4: Hasil & Pembahasan" <?= ($mhs['bab_terakhir'] === 'Bab 4: Hasil & Pembahasan') ? 'selected' : ''; ?>>Bab 4: Hasil & Pembahasan</option>
                                                                    <option value="Bab 5: Kesimpulan & Saran" <?= ($mhs['bab_terakhir'] === 'Bab 5: Kesimpulan & Saran') ? 'selected' : ''; ?>>Bab 5: Kesimpulan & Saran</option>
                                                                    <option value="Naskah Lengkap / Pra-Sidang" <?= ($mhs['bab_terakhir'] === 'Naskah Lengkap / Pra-Sidang') ? 'selected' : ''; ?>>Naskah Lengkap / Pra-Sidang</option>
                                                                </select>
                                                            </div>

                                                            <!-- Tanggal Bimbingan -->
                                                            <div class="col-md-6">
                                                                <label class="form-label fw-bold small text-dark">
                                                                    <i class="bi bi-calendar-event text-primary me-1"></i>Tanggal Sesi / Pengesahan *
                                                                </label>
                                                                <input type="date" name="tanggal_bimbingan" class="form-control" value="<?= date('Y-m-d'); ?>" required>
                                                            </div>

                                                            <!-- Status Keputusan / ACC Bab -->
                                                            <div class="col-md-6">
                                                                <label class="form-label fw-bold small text-dark">
                                                                    <i class="bi bi-check-circle text-primary me-1"></i>Keputusan Pengesahan Dosen *
                                                                </label>
                                                                <select name="keputusan_progres" class="form-select" required>
                                                                    <option value="acc_bab">ACC Bab (Lanjut Bab Berikutnya)</option>
                                                                    <option value="revisi">Perlu Revisi / Perbaikan Naskah</option>
                                                                    <option value="acc_sidang">ACC Siap Sidang Skripsi (Selesai Tuntas)</option>
                                                                    <option value="selesai">Selesai Konsultasi Rutin</option>
                                                                </select>
                                                            </div>

                                                            <!-- Status Kelulusan Mahasiswa -->
                                                            <div class="col-md-6">
                                                                <label class="form-label fw-bold small text-dark">
                                                                    <i class="bi bi-award text-primary me-1"></i>Status Kelulusan Akademik
                                                                </label>
                                                                <select name="status_kelulusan" class="form-select">
                                                                    <option value="proses_bimbingan" <?= ($mhs['status_kelulusan'] === 'proses_bimbingan') ? 'selected' : ''; ?>>Proses Bimbingan Aktif</option>
                                                                    <option value="siap_sidang" <?= ($mhs['status_kelulusan'] === 'siap_sidang') ? 'selected' : ''; ?>>Siap Sidang Skripsi</option>
                                                                    <option value="lulus" <?= ($mhs['status_kelulusan'] === 'lulus') ? 'selected' : ''; ?>>Lulus</option>
                                                                </select>
                                                            </div>

                                                            <!-- Nilai Capaian Progres (%) -->
                                                            <div class="col-12">
                                                                <label class="form-label fw-bold small text-dark d-flex justify-content-between align-items-center">
                                                                    <span><i class="bi bi-speedometer text-primary me-1"></i>Nilai Capaian Progres Skripsi:</span>
                                                                    <span class="badge bg-primary fs-6 px-3 py-1" id="valProgres<?= $mhs['id']; ?>"><?= max(20, $progres); ?>%</span>
                                                                </label>
                                                                <input type="range" class="form-range" min="0" max="100" step="5" 
                                                                       name="nilai_progres" 
                                                                       id="rangeProgres<?= $mhs['id']; ?>" 
                                                                       value="<?= max(20, $progres); ?>" 
                                                                       oninput="document.getElementById('valProgres<?= $mhs['id']; ?>').innerText = this.value + '%';">
                                                                <div class="d-flex justify-content-between text-muted" style="font-size: 0.75rem;">
                                                                    <span>0% (Awal)</span>
                                                                    <span>20% (Bab 1)</span>
                                                                    <span>40% (Bab 2)</span>
                                                                    <span>60% (Bab 3)</span>
                                                                    <span>80% (Bab 4)</span>
                                                                    <span>100% (Selesai/Sidang)</span>
                                                                </div>
                                                            </div>

                                                            <!-- Catatan & Umpan Balik Resmi Dosen -->
                                                            <div class="col-12">
                                                                <label class="form-label fw-bold small text-dark">
                                                                    <i class="bi bi-chat-left-text text-primary me-1"></i>Catatan Progres & Arahan Resmi Dosen *
                                                                </label>
                                                                <textarea name="catatan_dosen" class="form-control" rows="4" 
                                                                          placeholder="Tuliskan catatan kemajuan naskah, koreksi yang harus diperbaiki mahasiswa, atau instruksi untuk pengerjaan bab selanjutnya..." required></textarea>
                                                                <small class="text-muted">
                                                                    <i class="bi bi-info-circle me-1"></i>Catatan ini akan langsung tampil di dashboard progres dan logbook mahasiswa bersangkutan.
                                                                </small>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div class="modal-footer bg-light">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                                        <button type="submit" class="btn btn-primary fw-semibold">
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
                                                                            <td><strong><?= htmlspecialchars($h['bab_pembahasan']); ?></strong></td>
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
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
require_once '../templates/footer.php';
?>
