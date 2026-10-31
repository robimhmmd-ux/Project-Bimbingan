<?php
// mahasiswa/index.php - Dashboard Mahasiswa & Pencatatan Progres Skripsi
$page_title   = "Dashboard Mahasiswa";
$current_page = "dashboard";

require_once '../config/database.php';
require_once '../templates/header.php';
require_once '../templates/sidebar_mahasiswa.php';

// Proteksi akses role mahasiswa
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'mahasiswa') {
    header("Location: ../login.php");
    exit;
}

// Ambil data profil mahasiswa beserta info dosen pembimbingnya
$stmt_mhs = $pdo->prepare("
    SELECT m.*, d.user_id AS dosen_user_id, d.nidn, d.nama_dosen, d.email AS email_dosen, d.no_whatsapp, d.foto_profil AS foto_dosen 
    FROM mahasiswa m 
    LEFT JOIN dosen d ON m.dosen_pembimbing_id = d.id 
    WHERE m.user_id = :uid LIMIT 1
");
$stmt_mhs->execute([':uid' => $_SESSION['user_id']]);
$mhs = $stmt_mhs->fetch();

$mhs_id = $mhs['id'] ?? 0;
$dosen_pembimbing_id = $mhs['dosen_pembimbing_id'] ?? null;

// Hitung statistik bimbingan
$total_bimbingan = 0;
$bimbingan_acc = 0;
$bimbingan_revisi = 0;
$bimbingan_menunggu = 0;
$rata_nilai = 0;

if ($mhs_id) {
    // Total pengajuan
    $stmt_stat = $pdo->prepare("
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN status IN ('disetujui', 'selesai') THEN 1 ELSE 0 END) AS acc,
            SUM(CASE WHEN status = 'revisi' THEN 1 ELSE 0 END) AS revisi,
            SUM(CASE WHEN status = 'menunggu' THEN 1 ELSE 0 END) AS menunggu,
            AVG(NULLIF(nilai_progres, 0)) AS avg_nilai
        FROM pengajuan_bimbingan 
        WHERE mahasiswa_id = :mid
    ");
    $stmt_stat->execute([':mid' => $mhs_id]);
    $stat = $stmt_stat->fetch();

    $total_bimbingan    = (int)($stat['total'] ?? 0);
    $bimbingan_acc      = (int)($stat['acc'] ?? 0);
    $bimbingan_revisi   = (int)($stat['revisi'] ?? 0);
    $bimbingan_menunggu = (int)($stat['menunggu'] ?? 0);
    $rata_nilai         = round((float)($stat['avg_nilai'] ?? 0));

    // Periksa status per bab (Bab 1 s/d Bab 5) dari pengajuan_bimbingan yang selesai/acc
    $stmt_bab = $pdo->prepare("
        SELECT bab_pembahasan, status, nilai_progres 
        FROM pengajuan_bimbingan 
        WHERE mahasiswa_id = :mid 
        ORDER BY id DESC
    ");
    $stmt_bab->execute([':mid' => $mhs_id]);
    $riwayat_semua = $stmt_bab->fetchAll();

    // Riwayat 5 bimbingan terbaru
    $stmt_recent = $pdo->prepare("
        SELECT * FROM pengajuan_bimbingan 
        WHERE mahasiswa_id = :mid 
        ORDER BY tanggal_bimbingan DESC, jam_bimbingan DESC 
        LIMIT 5
    ");
    $stmt_recent->execute([':mid' => $mhs_id]);
    $recent_bimbingan = $stmt_recent->fetchAll();
} else {
    $riwayat_semua = [];
    $recent_bimbingan = [];
}

// Analisis progres bab 1 - 5
$bab_list = [
    'Bab 1' => ['label' => 'Bab 1: Pendahuluan', 'status' => 'belum'],
    'Bab 2' => ['label' => 'Bab 2: Tinjauan Pustaka', 'status' => 'belum'],
    'Bab 3' => ['label' => 'Bab 3: Metodologi Penelitian', 'status' => 'belum'],
    'Bab 4' => ['label' => 'Bab 4: Hasil & Pembahasan', 'status' => 'belum'],
    'Bab 5' => ['label' => 'Bab 5: Kesimpulan & Saran', 'status' => 'belum'],
];

$bab_acc_count = 0;
foreach ($riwayat_semua as $row) {
    foreach ($bab_list as $key => $val) {
        if (stripos($row['bab_pembahasan'], $key) !== false) {
            if (in_array($row['status'], ['selesai', 'disetujui']) && $val['status'] !== 'acc') {
                $bab_list[$key]['status'] = 'acc';
                $bab_acc_count++;
            } elseif ($row['status'] === 'revisi' && $val['status'] === 'belum') {
                $bab_list[$key]['status'] = 'revisi';
            } elseif ($row['status'] === 'menunggu' && $val['status'] === 'belum') {
                $bab_list[$key]['status'] = 'proses';
            }
        }
    }
}

// Hitung estimasi progres keseluruhan (berdasarkan 5 Bab @ 20%)
$persen_progres = min(100, $bab_acc_count * 20);
if ($mhs && $mhs['status_kelulusan'] === 'lulus') {
    $persen_progres = 100;
} elseif ($mhs && $mhs['status_kelulusan'] === 'siap_sidang' && $persen_progres < 90) {
    $persen_progres = 90;
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2 mb-0">Dashboard Progres Mahasiswa</h1>
        <p class="text-muted small mb-0">Selamat datang kembali, <strong><?= htmlspecialchars($mhs['nama_lengkap'] ?? $_SESSION['username']); ?></strong>!</p>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="ajukan_jadwal.php" class="btn btn-primary btn-sm me-2">
            <i class="bi bi-calendar-plus me-1"></i>Ajukan Jadwal
        </a>
        <a href="chat.php" class="btn btn-outline-primary btn-sm me-2">
            <i class="bi bi-chat-dots me-1"></i>Chat Dosen
        </a>
        <a href="cetak_laporan.php" target="_blank" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-printer me-1"></i>Cetak Laporan
        </a>
    </div>
</div>

<!-- Kartu Informasi Skripsi & Dosen Pembimbing -->
<div class="row g-3 mb-4">
    <!-- Info Skripsi & Progres Bar -->
    <div class="col-lg-7">
        <div class="card p-3 shadow-sm border-start border-primary border-4 h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                    <i class="bi bi-journal-text me-1"></i>Skripsi Mahasiswa
                </span>
                <?php
                $status_kelulusan = $mhs['status_kelulusan'] ?? 'proses_bimbingan';
                $badge_class = 'bg-warning text-dark';
                $badge_label = 'Proses Bimbingan';
                if ($status_kelulusan === 'siap_sidang') {
                    $badge_class = 'bg-info text-white';
                    $badge_label = 'Siap Sidang';
                } elseif ($status_kelulusan === 'lulus') {
                    $badge_class = 'bg-success text-white';
                    $badge_label = 'Lulus';
                }
                ?>
                <span class="badge <?= $badge_class; ?>"><?= $badge_label; ?></span>
            </div>
            <h5 class="fw-bold text-dark mb-2"><?= htmlspecialchars($mhs['judul_skripsi'] ?? 'Judul Belum Diatur'); ?></h5>
            <p class="text-muted small mb-3">
                NIM: <strong class="text-dark"><?= htmlspecialchars($mhs['nim'] ?? '-'); ?></strong> | 
                Nama: <strong class="text-dark"><?= htmlspecialchars($mhs['nama_lengkap'] ?? '-'); ?></strong>
            </p>

            <!-- Bar Progres Skripsi -->
            <div class="mt-auto">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="small fw-semibold text-secondary">Estimasi Progres Skripsi</span>
                    <span class="small fw-bold text-primary"><?= $persen_progres; ?>%</span>
                </div>
                <div class="progress" style="height: 12px;">
                    <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" 
                         role="progressbar" 
                         style="width: <?= $persen_progres; ?>%;" 
                         aria-valuenow="<?= $persen_progres; ?>" 
                         aria-valuemin="0" 
                         aria-valuemax="100"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Info Dosen Pembimbing & Kontak -->
    <div class="col-lg-5">
        <div class="card p-3 shadow-sm border-start border-success border-4 h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                    <i class="bi bi-person-badge me-1"></i>Dosen Pembimbing
                </span>
                <?php if (!empty($mhs['nama_dosen'])): ?>
                    <a href="jadwal_dosen.php" class="small text-decoration-none text-success fw-semibold">
                        <i class="bi bi-calendar3 me-1"></i>Lihat Jadwal
                    </a>
                <?php endif; ?>
            </div>

            <?php if (!empty($mhs['nama_dosen'])): ?>
                <div class="d-flex align-items-center mb-3">
                    <div class="me-3">
                        <?php if (!empty($mhs['foto_dosen']) && file_exists('../assets/uploads/' . $mhs['foto_dosen'])): ?>
                            <img src="../assets/uploads/<?= htmlspecialchars($mhs['foto_dosen']); ?>" class="rounded-circle border" width="55" height="55" style="object-fit: cover;" alt="Dosen">
                        <?php else: ?>
                            <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center fw-bold" style="width: 55px; height: 55px; font-size: 1.3rem;">
                                <?= strtoupper(substr($mhs['nama_dosen'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h6 class="fw-bold text-dark mb-0"><?= htmlspecialchars($mhs['nama_dosen']); ?></h6>
                        <span class="text-muted small">NIDN: <?= htmlspecialchars($mhs['nidn'] ?? '-'); ?></span>
                    </div>
                </div>

                <div class="bg-light p-2 rounded small mb-3 border">
                    <div class="mb-1">
                        <i class="bi bi-envelope text-primary me-2"></i>
                        <a href="mailto:<?= htmlspecialchars($mhs['email_dosen'] ?? ''); ?>" class="text-decoration-none text-secondary">
                            <?= htmlspecialchars($mhs['email_dosen'] ?? '-'); ?>
                        </a>
                    </div>
                    <div>
                        <i class="bi bi-whatsapp text-success me-2"></i>
                        <?php if (!empty($mhs['no_whatsapp'])): 
                            $clean_wa = preg_replace('/[^0-9]/', '', $mhs['no_whatsapp']);
                            if (str_starts_with($clean_wa, '0')) {
                                $clean_wa = '62' . substr($clean_wa, 1);
                            }
                        ?>
                            <a href="https://wa.me/<?= $clean_wa; ?>" target="_blank" class="text-decoration-none text-secondary">
                                <?= htmlspecialchars($mhs['no_whatsapp']); ?> <i class="bi bi-box-arrow-up-right small text-muted"></i>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mt-auto d-flex gap-2">
                    <a href="chat.php" class="btn btn-outline-success btn-sm w-100">
                        <i class="bi bi-chat-dots-fill me-1"></i>Chat di Web
                    </a>
                    <a href="jadwal_dosen.php" class="btn btn-outline-secondary btn-sm w-100">
                        <i class="bi bi-eye me-1"></i>Profil & Jadwal
                    </a>
                </div>
            <?php else: ?>
                <div class="alert alert-warning small mb-0 py-2">
                    <i class="bi bi-exclamation-triangle me-1"></i>Anda belum di-plot dosen pembimbing oleh Admin. Silakan hubungi bagian akademik/admin.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Milestone Tahapan Skripsi (Bab 1 - Bab 5) -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <h6 class="m-0 fw-bold text-dark">
            <i class="bi bi-check2-circle text-primary me-2"></i>Pencatatan Progres Tahapan Bab Skripsi
        </h6>
    </div>
    <div class="card-body">
        <div class="row g-2 text-center">
            <?php foreach ($bab_list as $b_key => $b_data): ?>
                <div class="col-6 col-md">
                    <div class="p-3 border rounded h-100 d-flex flex-column justify-content-between 
                        <?= $b_data['status'] === 'acc' ? 'bg-success-subtle border-success' : ($b_data['status'] === 'revisi' ? 'bg-warning-subtle border-warning' : ($b_data['status'] === 'proses' ? 'bg-info-subtle border-info' : 'bg-light')); ?>">
                        <div>
                            <div class="mb-2">
                                <?php if ($b_data['status'] === 'acc'): ?>
                                    <i class="bi bi-check-circle-fill text-success fs-3"></i>
                                <?php elseif ($b_data['status'] === 'revisi'): ?>
                                    <i class="bi bi-arrow-repeat text-warning fs-3"></i>
                                <?php elseif ($b_data['status'] === 'proses'): ?>
                                    <i class="bi bi-hourglass-split text-info fs-3"></i>
                                <?php else: ?>
                                    <i class="bi bi-circle text-muted fs-3"></i>
                                <?php endif; ?>
                            </div>
                            <h6 class="fw-bold mb-1 small text-dark"><?= $b_key; ?></h6>
                            <p class="small text-muted mb-2" style="font-size: 0.75rem;"><?= htmlspecialchars($b_data['label']); ?></p>
                        </div>
                        <div>
                            <?php if ($b_data['status'] === 'acc'): ?>
                                <span class="badge bg-success">ACC Selesai</span>
                            <?php elseif ($b_data['status'] === 'revisi'): ?>
                                <span class="badge bg-warning text-dark">Perlu Revisi</span>
                            <?php elseif ($b_data['status'] === 'proses'): ?>
                                <span class="badge bg-info text-dark">Sedang Bimbingan</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Belum Mulai</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Statistik Ringkas Bimbingan -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 shadow-sm border-0 bg-white">
            <div class="d-flex align-items-center">
                <div class="bg-primary-subtle text-primary rounded p-3 me-3">
                    <i class="bi bi-journals fs-4"></i>
                </div>
                <div>
                    <span class="text-muted small">Total Bimbingan</span>
                    <h4 class="fw-bold mb-0"><?= $total_bimbingan; ?></h4>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 shadow-sm border-0 bg-white">
            <div class="d-flex align-items-center">
                <div class="bg-success-subtle text-success rounded p-3 me-3">
                    <i class="bi bi-check-circle-fill fs-4"></i>
                </div>
                <div>
                    <span class="text-muted small">Disetujui / Selesai</span>
                    <h4 class="fw-bold mb-0"><?= $bimbingan_acc; ?></h4>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 shadow-sm border-0 bg-white">
            <div class="d-flex align-items-center">
                <div class="bg-warning-subtle text-warning rounded p-3 me-3">
                    <i class="bi bi-clock-history fs-4"></i>
                </div>
                <div>
                    <span class="text-muted small">Menunggu / Proses</span>
                    <h4 class="fw-bold mb-0"><?= $bimbingan_menunggu; ?></h4>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 shadow-sm border-0 bg-white">
            <div class="d-flex align-items-center">
                <div class="bg-info-subtle text-info rounded p-3 me-3">
                    <i class="bi bi-star-fill fs-4"></i>
                </div>
                <div>
                    <span class="text-muted small">Rata-rata Nilai</span>
                    <h4 class="fw-bold mb-0"><?= $rata_nilai > 0 ? $rata_nilai . '/100' : '-'; ?></h4>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Riwayat Bimbingan Terkini & Akses Cepat -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="m-0 fw-bold text-dark">
            <i class="bi bi-list-task me-1 text-primary"></i>Riwayat Bimbingan Terakhir
        </h6>
        <a href="logbook.php" class="btn btn-outline-primary btn-sm">Lihat Semua di Logbook</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Tanggal & Jam</th>
                        <th>Bab Pembahasan</th>
                        <th>Draft Skripsi</th>
                        <th>Status</th>
                        <th>Catatan Dosen</th>
                        <th>Nilai</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_bimbingan)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">
                                <i class="bi bi-inbox fs-2 d-block mb-1"></i>
                                Belum ada riwayat pengajuan bimbingan. Silakan klik <a href="ajukan_jadwal.php">Ajukan Jadwal</a> untuk memulai.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recent_bimbingan as $item): ?>
                            <tr>
                                <td>
                                    <strong><?= date('d M Y', strtotime($item['tanggal_bimbingan'])); ?></strong><br>
                                    <small class="text-muted"><?= substr($item['jam_bimbingan'], 0, 5); ?> WIB</small>
                                </td>
                                <td>
                                    <span class="fw-semibold"><?= htmlspecialchars($item['bab_pembahasan']); ?></span>
                                    <?php if (!empty($item['catatan_mahasiswa'])): ?>
                                        <p class="text-muted small mb-0 text-truncate" style="max-width: 200px;">
                                            <?= htmlspecialchars($item['catatan_mahasiswa']); ?>
                                        </p>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($item['file_draft'])): ?>
                                        <a href="../assets/uploads/<?= htmlspecialchars($item['file_draft']); ?>" target="_blank" class="btn btn-outline-secondary btn-sm py-0 px-2">
                                            <i class="bi bi-file-earmark-pdf text-danger me-1"></i>Lihat PDF
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?= $item['status']; ?>">
                                        <?= ucfirst($item['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="small text-muted"><?= htmlspecialchars($item['catatan_dosen'] ?? 'Belum ada catatan'); ?></span>
                                </td>
                                <td>
                                    <?php if ($item['nilai_progres'] > 0): ?>
                                        <span class="badge bg-success"><?= $item['nilai_progres']; ?></span>
                                    <?php else: ?>
                                        <span class="text-muted small">-</span>
                                    <?php endif; ?>
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
