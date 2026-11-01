<?php
// mahasiswa/jadwal_dosen.php - Melihat Jadwal & Profil Kontak Dosen Pembimbing
$page_title   = "Jadwal & Profil Dosen";
$current_page = "jadwal_dosen";

require_once '../config/database.php';
require_once '../templates/header.php';
require_once '../templates/sidebar_mahasiswa.php';

// Proteksi akses role mahasiswa
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'mahasiswa') {
    header("Location: ../login.php");
    exit;
}

// Ambil info mahasiswa & dosen pembimbing
$stmt_mhs = $pdo->prepare("
    SELECT m.*, d.id AS dosen_id, d.user_id AS dosen_user_id, d.nidn, d.nama_dosen, 
           d.email AS email_dosen, d.no_whatsapp, d.foto_profil AS foto_dosen, d.kuota_bimbingan
    FROM mahasiswa m 
    LEFT JOIN dosen d ON m.dosen_pembimbing_id = d.id 
    WHERE m.user_id = :uid LIMIT 1
");
$stmt_mhs->execute([':uid' => $_SESSION['user_id']]);
$mhs = $stmt_mhs->fetch();

$dosen_id = $mhs['dosen_id'] ?? null;
$jadwal_list = [];
$total_mhs_bimbingan = 0;

if ($dosen_id) {
    // Ambil jadwal ketersediaan dosen pembimbing
    $stmt_jadwal = $pdo->prepare("
        SELECT * FROM jadwal_dosen 
        WHERE dosen_id = :did 
        ORDER BY FIELD(hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'), jam_mulai ASC
    ");
    $stmt_jadwal->execute([':did' => $dosen_id]);
    $jadwal_list = $stmt_jadwal->fetchAll();

    // Hitung berapa mahasiswa yang dibimbing dosen ini
    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM mahasiswa WHERE dosen_pembimbing_id = :did");
    $stmt_count->execute([':did' => $dosen_id]);
    $total_mhs_bimbingan = (int)$stmt_count->fetchColumn();
}

// Konversi nomor WA untuk URL wa.me
$wa_url = '#';
if (!empty($mhs['no_whatsapp'])) {
    $clean_wa = preg_replace('/[^0-9]/', '', $mhs['no_whatsapp']);
    if (str_starts_with($clean_wa, '0')) {
        $clean_wa = '62' . substr($clean_wa, 1);
    }
    $wa_url = "https://wa.me/{$clean_wa}?text=" . urlencode("Halo Bapak/Ibu " . ($mhs['nama_dosen'] ?? 'Dosen') . ", saya " . ($mhs['nama_lengkap'] ?? 'Mahasiswa') . " (NIM: " . ($mhs['nim'] ?? '') . ") ingin menanyakan perihal bimbingan skripsi.");
}
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center pt-3 pb-2 mb-3 border-bottom gap-2">
    <div>
        <h1 class="h2 mb-0">Jadwal & Profil Dosen Pembimbing</h1>
        <p class="text-muted small mb-0">Lihat ketersediaan hari bimbingan serta kontak resmi dosen pembimbing Anda.</p>
    </div>
    <div class="page-header-actions">
        <a href="chat.php" class="btn btn-primary btn-sm">
            <i class="bi bi-chat-dots-fill me-1"></i>Chat Dosen di Web
        </a>
        <a href="ajukan_jadwal.php" class="btn btn-success btn-sm">
            <i class="bi bi-calendar-plus me-1"></i>Ajukan Bimbingan
        </a>
    </div>
</div>

<?php if (!$dosen_id): ?>
    <div class="card shadow-sm border-warning border-start border-4 p-4 text-center my-4">
        <i class="bi bi-person-x text-warning fs-1 mb-2"></i>
        <h5 class="fw-bold text-dark">Dosen Pembimbing Belum Ditetapkan</h5>
        <p class="text-muted mb-3">Admin akademik belum memplot dosen pembimbing untuk akun Anda. Silakan hubungi bagian akademik atau tunggu proses plotting selesai.</p>
        <div>
            <a href="index.php" class="btn btn-outline-primary btn-sm">Kembali ke Dashboard</a>
        </div>
    </div>
<?php else: ?>

    <div class="row g-4 mb-4">
        <!-- Kartu Profil Dosen -->
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="m-0 fw-bold text-dark">
                        <i class="bi bi-person-vcard text-primary me-2"></i>Profil Dosen Pembimbing
                    </h6>
                </div>
                <div class="card-body text-center p-4">
                    <div class="mb-3">
                        <?php if (!empty($mhs['foto_dosen']) && file_exists('../assets/uploads/' . $mhs['foto_dosen'])): ?>
                            <img src="../assets/uploads/<?= htmlspecialchars($mhs['foto_dosen']); ?>" class="rounded-circle img-thumbnail shadow-sm" width="110" height="110" style="object-fit: cover;" alt="Foto Dosen">
                        <?php else: ?>
                            <div class="bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center fw-bold shadow-sm" style="width: 100px; height: 100px; font-size: 2.5rem;">
                                <?= strtoupper(substr($mhs['nama_dosen'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <h5 class="fw-bold text-dark mb-1"><?= htmlspecialchars($mhs['nama_dosen']); ?></h5>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-1 mb-3">
                        Dosen Pembimbing Utama
                    </span>

                    <ul class="list-group list-group-flush text-start small mb-4">
                        <li class="list-group-item d-flex justify-content-between px-0 py-2">
                            <span class="text-muted"><i class="bi bi-card-text me-2"></i>NIDN</span>
                            <span class="fw-semibold text-dark"><?= htmlspecialchars($mhs['nidn'] ?? '-'); ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0 py-2">
                            <span class="text-muted"><i class="bi bi-people me-2"></i>Kuota Bimbingan</span>
                            <span class="fw-semibold text-dark"><?= $total_mhs_bimbingan; ?> / <?= htmlspecialchars($mhs['kuota_bimbingan'] ?? '10'); ?> Mahasiswa</span>
                        </li>
                    </ul>

                    <!-- Informasi Kontak Dosen (Email & WhatsApp) -->
                    <div class="bg-light p-3 rounded text-start border mb-3">
                        <h6 class="fw-bold text-dark mb-2 small text-uppercase">
                            <i class="bi bi-telephone-inbound me-1 text-success"></i>Kontak Resmi Dosen
                        </h6>
                        <div class="mb-2">
                            <label class="text-muted small d-block mb-1">Email Resmi:</label>
                            <div class="d-flex align-items-center justify-content-between">
                                <a href="mailto:<?= htmlspecialchars($mhs['email_dosen'] ?? ''); ?>" class="text-decoration-none fw-semibold small text-primary text-truncate me-2">
                                    <i class="bi bi-envelope me-1"></i><?= htmlspecialchars($mhs['email_dosen'] ?? '-'); ?>
                                </a>
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($mhs['email_dosen'] ?? ''); ?>'); alert('Email berhasil disalin!');" title="Salin Email">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                        </div>

                        <div>
                            <label class="text-muted small d-block mb-1">WhatsApp / No. HP:</label>
                            <?php if (!empty($mhs['no_whatsapp'])): ?>
                                <div class="d-flex align-items-center justify-content-between">
                                    <span class="fw-semibold small text-dark">
                                        <i class="bi bi-whatsapp text-success me-1"></i><?= htmlspecialchars($mhs['no_whatsapp']); ?>
                                    </span>
                                    <a href="<?= $wa_url; ?>" target="_blank" class="btn btn-success btn-sm py-0 px-2" title="Chat via WhatsApp">
                                        <i class="bi bi-box-arrow-up-right me-1"></i>Buka WA
                                    </a>
                                </div>
                            <?php else: ?>
                                <span class="text-muted small">Nomor WhatsApp belum dicantumkan</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="d-grid gap-2">
                        <a href="chat.php" class="btn btn-primary btn-sm py-2">
                            <i class="bi bi-chat-dots-fill me-1"></i>Mulai Chat di Aplikasi
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Kartu Tabel Jadwal Ketersediaan Dosen -->
        <div class="col-lg-8">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
                    <h6 class="m-0 fw-bold text-dark">
                        <i class="bi bi-calendar-week text-primary me-2"></i>Jadwal Ketersediaan Bimbingan
                    </h6>
                    <span class="badge bg-info-subtle text-info border border-info-subtle small">
                        <?= count($jadwal_list); ?> Sesi Terdaftar
                    </span>
                </div>
                <div class="card-body">
                    <div class="alert alert-info py-2 small mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        Pilihlah waktu pengajuan bimbingan yang sesuai dengan hari dan jam aktif dosen di bawah ini agar pengajuan Anda segera disetujui.
                    </div>

                    <?php if (empty($jadwal_list)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-calendar-x fs-1 d-block mb-2"></i>
                            <h6 class="fw-bold">Belum Ada Jadwal Ketersediaan</h6>
                            <p class="small mb-3">Dosen pembimbing Anda belum mengatur slot jadwal bimbingan mingguan.</p>
                            <a href="chat.php" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-chat-dots me-1"></i>Tanyakan Waktu Bimbingan via Chat
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Hari</th>
                                        <th>Jam Bimbingan</th>
                                        <th>Status Ketersediaan</th>
                                        <th class="text-end">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $hari_color = [
                                        'Senin' => 'primary',
                                        'Selasa' => 'success',
                                        'Rabu' => 'info',
                                        'Kamis' => 'warning',
                                        'Jumat' => 'danger',
                                        'Sabtu' => 'secondary'
                                    ];
                                    foreach ($jadwal_list as $row): 
                                        $color = $hari_color[$row['hari']] ?? 'secondary';
                                        $is_aktif = ($row['status_aktif'] === 'aktif');
                                    ?>
                                        <tr>
                                            <td>
                                                <span class="badge bg-<?= $color; ?> px-2 py-1 fs-6">
                                                    <?= htmlspecialchars($row['hari']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <i class="bi bi-clock me-1 text-muted"></i>
                                                <strong><?= substr($row['jam_mulai'], 0, 5); ?></strong> s/d <strong><?= substr($row['jam_selesai'], 0, 5); ?></strong> WIB
                                            </td>
                                            <td>
                                                <?php if ($is_aktif): ?>
                                                    <span class="badge bg-success-subtle text-success border border-success-subtle">
                                                        <i class="bi bi-check-circle me-1"></i>Aktif / Siap Bimbingan
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle">
                                                        <i class="bi bi-x-circle me-1"></i>Sedang Libur / Tidak Tersedia
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <?php if ($is_aktif): ?>
                                                    <a href="ajukan_jadwal.php?hari=<?= urlencode($row['hari']); ?>&jam=<?= substr($row['jam_mulai'], 0, 5); ?>" class="btn btn-outline-primary btn-sm">
                                                        <i class="bi bi-calendar-plus me-1"></i>Ajukan Sesi Ini
                                                    </a>
                                                <?php else: ?>
                                                    <button class="btn btn-secondary btn-sm" disabled>Libur</button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<?php endif; ?>

<?php
require_once '../templates/footer.php';
?>

