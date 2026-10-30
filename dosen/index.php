<?php
// dosen/index.php - Dashboard Dosen Pembimbing (Salsa)
$page_title   = "Dashboard Dosen";
$current_page = "dashboard";

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

// Metrik 1: Permintaan Menunggu Konfirmasi
$stmt_menunggu = $pdo->prepare("SELECT COUNT(*) FROM pengajuan_bimbingan WHERE dosen_id = :did AND status = 'menunggu'");
$stmt_menunggu->execute([':did' => $dosen_id]);
$count_menunggu = (int)$stmt_menunggu->fetchColumn();

// Metrik 2: Bimbingan Berjalan / Disetujui
$stmt_aktif = $pdo->prepare("SELECT COUNT(*) FROM pengajuan_bimbingan WHERE dosen_id = :did AND status = 'disetujui'");
$stmt_aktif->execute([':did' => $dosen_id]);
$count_aktif = (int)$stmt_aktif->fetchColumn();

// Metrik 3: Mahasiswa Bimbingan yang Di-plot ke Dosen Ini
$stmt_mhs = $pdo->prepare("SELECT COUNT(*) FROM mahasiswa WHERE dosen_pembimbing_id = :did");
$stmt_mhs->execute([':did' => $dosen_id]);
$count_mhs = (int)$stmt_mhs->fetchColumn();

// Metrik 4: Slot Jadwal Aktif
$stmt_jadwal = $pdo->prepare("SELECT COUNT(*) FROM jadwal_dosen WHERE dosen_id = :did AND status_aktif = 'aktif'");
$stmt_jadwal->execute([':did' => $dosen_id]);
$count_jadwal = (int)$stmt_jadwal->fetchColumn();

// Metrik 5: Pesan Belum Dibaca
$stmt_unread = $pdo->prepare("SELECT COUNT(*) FROM pesan_chat WHERE penerima_id = :uid AND status_baca = 'belum_dibaca'");
$stmt_unread->execute([':uid' => $user_id]);
$count_unread = (int)$stmt_unread->fetchColumn();

// Data 5 Pengajuan Bimbingan Terbaru
$stmt_terbaru = $pdo->prepare("
    SELECT pb.*, m.nim, m.nama_lengkap 
    FROM pengajuan_bimbingan pb
    JOIN mahasiswa m ON pb.mahasiswa_id = m.id
    WHERE pb.dosen_id = :did
    ORDER BY pb.created_at DESC 
    LIMIT 5
");
$stmt_terbaru->execute([':did' => $dosen_id]);
$pengajuan_terbaru = $stmt_terbaru->fetchAll();

// Cek foto profil
$foto_path = '../assets/uploads/profile/' . ($dosen['foto_profil'] ?? '');
$has_foto  = !empty($dosen['foto_profil']) && $dosen['foto_profil'] !== 'default.png' && file_exists($foto_path);
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div class="d-flex align-items-center gap-3">
        <?php if ($has_foto): ?>
            <img src="<?= htmlspecialchars($foto_path); ?>" 
                 alt="Foto Dosen" 
                 class="rounded-circle img-thumbnail shadow-sm object-fit-cover" 
                 style="width: 55px; height: 55px;">
        <?php else: ?>
            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center shadow-sm" 
                 style="width: 55px; height: 55px; font-size: 1.8rem;">
                <i class="bi bi-person-fill"></i>
            </div>
        <?php endif; ?>
        <div>
            <h1 class="h3 fw-bold mb-0">Dashboard Dosen Pembimbing</h1>
            <p class="text-muted small mb-0">Selamat datang, <strong><?= htmlspecialchars($dosen['nama_dosen'] ?? 'Bapak/Ibu Dosen'); ?></strong> (NIDN: <?= htmlspecialchars($dosen['nidn'] ?? '-'); ?>).</p>
        </div>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <a href="profil.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-person-gear me-1"></i> Edit Profil
        </a>
        <a href="jadwal_saya.php" class="btn btn-sm btn-primary">
            <i class="bi bi-calendar-plus me-1"></i> Atur Jadwal
        </a>
    </div>
</div>

<!-- Banner Notifikasi Pengajuan Menunggu -->
<?php if ($count_menunggu > 0): ?>
    <div class="alert alert-warning d-flex align-items-center justify-content-between shadow-sm py-2 px-3 mb-4">
        <div>
            <i class="bi bi-bell-fill me-2 fs-5"></i>
            <span>Terdapat <strong><?= $count_menunggu; ?> permohonan bimbingan baru</strong> yang perlu Anda konfirmasi (Terima / Reschedule / Tolak).</span>
        </div>
        <a href="persetujuan.php" class="btn btn-warning btn-sm fw-bold">Tinjau Pengajuan &rarr;</a>
    </div>
<?php endif; ?>

<!-- Kartu Ringkasan Metrik -->
<div class="row g-3 mb-4">
    <!-- Mahasiswa Bimbingan -->
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100 shadow-sm border-0 border-start border-primary border-4 p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="text-muted small fw-semibold">Mahasiswa Bimbingan</span>
                    <h3 class="fw-bold my-1 text-primary"><?= $count_mhs; ?> <span class="fs-6 text-muted fw-normal">/ <?= htmlspecialchars($dosen['kuota_bimbingan'] ?? '10'); ?> Kuota</span></h3>
                    <a href="riwayat_bimbingan.php" class="small text-decoration-none fw-semibold">Lihat Mahasiswa &rarr;</a>
                </div>
                <div class="bg-primary-subtle text-primary p-3 rounded-circle">
                    <i class="bi bi-people-fill fs-4"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Permintaan Menunggu -->
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100 shadow-sm border-0 border-start border-warning border-4 p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="text-muted small fw-semibold">Menunggu Konfirmasi</span>
                    <h3 class="fw-bold my-1 text-warning"><?= $count_menunggu; ?></h3>
                    <a href="persetujuan.php" class="small text-decoration-none fw-semibold">Tinjau & Reschedule &rarr;</a>
                </div>
                <div class="bg-warning-subtle text-warning p-3 rounded-circle">
                    <i class="bi bi-hourglass-split fs-4"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Bimbingan Disetujui -->
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100 shadow-sm border-0 border-start border-success border-4 p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="text-muted small fw-semibold">Jadwal Disetujui</span>
                    <h3 class="fw-bold my-1 text-success"><?= $count_aktif; ?></h3>
                    <a href="riwayat_bimbingan.php" class="small text-decoration-none fw-semibold">Beri Nilai & ACC &rarr;</a>
                </div>
                <div class="bg-success-subtle text-success p-3 rounded-circle">
                    <i class="bi bi-check-circle-fill fs-4"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Slot Waktu Aktif -->
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100 shadow-sm border-0 border-start border-info border-4 p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="text-muted small fw-semibold">Slot Waktu Aktif</span>
                    <h3 class="fw-bold my-1 text-info"><?= $count_jadwal; ?></h3>
                    <a href="jadwal_saya.php" class="small text-decoration-none fw-semibold">Atur Ketersediaan &rarr;</a>
                </div>
                <div class="bg-info-subtle text-info p-3 rounded-circle">
                    <i class="bi bi-calendar-check fs-4"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Row Konten Utama -->
<div class="row g-4">
    <!-- Tabel Pengajuan Terbaru -->
    <div class="col-lg-8">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold text-dark">
                    <i class="bi bi-clock-history me-2 text-primary"></i>Aktivitas Pengajuan Bimbingan Terbaru
                </h6>
                <a href="persetujuan.php" class="btn btn-sm btn-outline-primary">Lihat Semua</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Mahasiswa</th>
                                <th>Bab</th>
                                <th>Jadwal</th>
                                <th>Status</th>
                                <th class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pengajuan_terbaru)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">
                                        <i class="bi bi-inbox fs-2 d-block mb-1"></i>
                                        Belum ada permohonan bimbingan masuk.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($pengajuan_terbaru as $row): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold text-dark"><?= htmlspecialchars($row['nama_lengkap']); ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($row['nim']); ?></small>
                                        </td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($row['bab_pembahasan']); ?></span></td>
                                        <td>
                                            <small class="d-block"><?= date('d M Y', strtotime($row['tanggal_bimbingan'])); ?></small>
                                            <small class="text-muted"><?= substr($row['jam_bimbingan'], 0, 5); ?> WIB</small>
                                        </td>
                                        <td>
                                            <?php
                                            $st = $row['status'];
                                            if ($st === 'menunggu') echo '<span class="badge bg-warning text-dark">Menunggu</span>';
                                            elseif ($st === 'disetujui') echo '<span class="badge bg-success">Disetujui</span>';
                                            elseif ($st === 'ditolak') echo '<span class="badge bg-danger">Ditolak</span>';
                                            elseif ($st === 'revisi') echo '<span class="badge bg-info text-dark">Revisi</span>';
                                            else echo '<span class="badge bg-secondary">' . ucfirst($st) . '</span>';
                                            ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($row['status'] === 'menunggu'): ?>
                                                <a href="persetujuan.php" class="btn btn-sm btn-primary">Tinjau</a>
                                            <?php else: ?>
                                                <a href="riwayat_bimbingan.php" class="btn btn-sm btn-outline-secondary">Detail</a>
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
    </div>

    <!-- Pintasan Menu & Fitur Lengkap Modul Dosen -->
    <div class="col-lg-4">
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white py-3 border-bottom">
                <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-grid-fill me-2 text-primary"></i>Menu Layanan Dosen</h6>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    <a href="jadwal_saya.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3">
                        <div>
                            <div class="fw-bold"><i class="bi bi-calendar-plus text-primary me-2"></i>Atur Ketersediaan Waktu</div>
                            <small class="text-muted">Tentukan hari dan jam konsultasi mahasiswa</small>
                        </div>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </a>
                    <a href="persetujuan.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3">
                        <div>
                            <div class="fw-bold"><i class="bi bi-inbox text-warning me-2"></i>Persetujuan & Reschedule</div>
                            <small class="text-muted">Terima, tolak, atau atur ulang jadwal bimbingan</small>
                        </div>
                        <?php if ($count_menunggu > 0): ?>
                            <span class="badge bg-danger rounded-pill"><?= $count_menunggu; ?></span>
                        <?php else: ?>
                            <i class="bi bi-chevron-right text-muted"></i>
                        <?php endif; ?>
                    </a>
                    <a href="riwayat_bimbingan.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3">
                        <div>
                            <div class="fw-bold"><i class="bi bi-journal-text text-success me-2"></i>Evaluasi, Nilai & ACC</div>
                            <small class="text-muted">Unduh draft naskah & beri pengesahan bab</small>
                        </div>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </a>
                    <a href="cetak_laporan.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3">
                        <div>
                            <div class="fw-bold"><i class="bi bi-printer text-info me-2"></i>Cetak Rekapitulasi Laporan</div>
                            <small class="text-muted">Ekspor atau cetak lembar bimbingan resmi</small>
                        </div>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </a>
                    <a href="chat.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3">
                        <div>
                            <div class="fw-bold"><i class="bi bi-chat-dots text-primary me-2"></i>Konsultasi Chat Mahasiswa</div>
                            <small class="text-muted">Kirim pesan langsung ke mahasiswa bimbingan</small>
                        </div>
                        <?php if ($count_unread > 0): ?>
                            <span class="badge bg-primary rounded-pill"><?= $count_unread; ?> baru</span>
                        <?php else: ?>
                            <i class="bi bi-chevron-right text-muted"></i>
                        <?php endif; ?>
                    </a>
                    <a href="profil.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3">
                        <div>
                            <div class="fw-bold"><i class="bi bi-person-gear text-secondary me-2"></i>Profil & Ganti Password</div>
                            <small class="text-muted">Perbarui foto profil dan data kontak</small>
                        </div>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once '../templates/footer.php';
?>
