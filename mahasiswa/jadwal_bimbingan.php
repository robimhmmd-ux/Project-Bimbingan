<?php
// mahasiswa/jadwal_bimbingan.php - Halaman Lihat Jadwal Bimbingan & Ketersediaan Dosen
$page_title   = "Jadwal Bimbingan Skripsi";
$current_page = "jadwal_bimbingan";

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
           d.email AS email_dosen, d.no_whatsapp, d.foto_profil AS foto_dosen, d.kuota_bimbingan,
           u_dos.username AS username_dosen
    FROM mahasiswa m 
    LEFT JOIN dosen d ON m.dosen_pembimbing_id = d.id 
    LEFT JOIN users u_dos ON d.user_id = u_dos.id
    WHERE m.user_id = :uid LIMIT 1
");
$stmt_mhs->execute([':uid' => $_SESSION['user_id']]);
$mhs = $stmt_mhs->fetch();

$mhs_id               = $mhs['id'] ?? 0;
$dosen_id             = $mhs['dosen_id'] ?? null;
$status_pembimbing    = $mhs['status_pembimbing'] ?? 'belum_diplot';
$jadwal_dosen_list    = [];
$sesi_bimbingan_list  = [];
$total_mhs_bimbingan  = 0;
$count_disetujui      = 0;
$count_menunggu       = 0;
$count_selesai        = 0;

if ($dosen_id) {
    // 1. Ambil jadwal ketersediaan waktu dosen pembimbing
    $stmt_jadwal = $pdo->prepare("
        SELECT * FROM jadwal_dosen 
        WHERE dosen_id = :did 
        ORDER BY (tanggal IS NULL), tanggal ASC, FIELD(hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'), jam_mulai ASC
    ");
    $stmt_jadwal->execute([':did' => $dosen_id]);
    $jadwal_dosen_list = $stmt_jadwal->fetchAll();

    // 2. Hitung total mahasiswa bimbingan dosen ini
    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM mahasiswa WHERE dosen_pembimbing_id = :did");
    $stmt_count->execute([':did' => $dosen_id]);
    $total_mhs_bimbingan = (int)$stmt_count->fetchColumn();

    // 3. Ambil agenda & riwayat sesi bimbingan mahasiswa
    if ($mhs_id > 0) {
        $stmt_sesi = $pdo->prepare("
            SELECT * FROM pengajuan_bimbingan 
            WHERE mahasiswa_id = :mid 
            ORDER BY tanggal_bimbingan DESC, jam_bimbingan DESC, id DESC
        ");
        $stmt_sesi->execute([':mid' => $mhs_id]);
        $sesi_bimbingan_list = $stmt_sesi->fetchAll();

        foreach ($sesi_bimbingan_list as $s) {
            if ($s['status'] === 'disetujui') $count_disetujui++;
            elseif ($s['status'] === 'menunggu') $count_menunggu++;
            elseif ($s['status'] === 'selesai') $count_selesai++;
        }
    }
}

// Konversi nomor WA untuk URL wa.me
$wa_url = '#';
if (!empty($mhs['no_whatsapp'])) {
    $clean_wa = preg_replace('/[^0-9]/', '', $mhs['no_whatsapp']);
    if (str_starts_with($clean_wa, '0')) {
        $clean_wa = '62' . substr($clean_wa, 1);
    }
    $wa_url = "https://wa.me/{$clean_wa}?text=" . urlencode("Halo Bapak/Ibu " . ($mhs['nama_dosen'] ?? 'Dosen') . ", saya " . ($mhs['nama_lengkap'] ?? 'Mahasiswa') . " (NIM: " . ($mhs['nim'] ?? '') . ") ingin menanyakan perihal jadwal bimbingan skripsi.");
}

// Helper nama hari Indonesia
function format_indo_date($date_str) {
    if (empty($date_str)) return '-';
    $timestamp = strtotime($date_str);
    $hari_arr  = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $bulan_arr = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $hari  = $hari_arr[date('w', $timestamp)];
    $tgl   = date('j', $timestamp);
    $bulan = $bulan_arr[(int)date('n', $timestamp)];
    $tahun = date('Y', $timestamp);
    return "{$hari}, {$tgl} {$bulan} {$tahun}";
}
?>

<!-- ============================================================
     HERO BANNER MAHASISWA (NAVY SAPPHIRE)
============================================================ -->
<div class="student-hero-banner">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 position-relative" style="z-index: 2;">
        <div class="pe-md-3">
            <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                <span class="badge-kelas">
                    <i class="bi bi-mortarboard-fill"></i><?= htmlspecialchars($mhs['kelas'] ?? 'TI-1A-2025'); ?>
                </span>
                <span class="badge bg-white bg-opacity-25 text-white border border-white border-opacity-25 px-2.5 py-1 small rounded-pill">
                    <i class="bi bi-calendar-week-fill me-1"></i>Jadwal Bimbingan Skripsi
                </span>
            </div>
            <h2 class="fw-bold text-white mb-1">Jadwal Bimbingan Skripsi</h2>
            <p class="text-white-50 small mb-0">
                Pantau jadwal ketersediaan dosen pembimbing serta agenda dan riwayat sesi bimbingan Anda.
            </p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-shrink-0 flex-wrap">
            <a href="chat.php" class="btn btn-hero-action btn-hero-white">
                <i class="bi bi-chat-dots-fill"></i>Chat Dosen
            </a>
            <a href="upload_draft.php" class="btn btn-hero-action btn-hero-blue">
                <i class="bi bi-cloud-arrow-up"></i>Upload Berkas
            </a>
            <a href="index.php" class="btn btn-hero-action btn-hero-outline">
                <i class="bi bi-speedometer2"></i>Dashboard
            </a>
        </div>
    </div>
</div>

<?php if (!$dosen_id): ?>
    <!-- KONDISI: Dosen Pembimbing Belum Diplot -->
    <div class="card shadow-sm border-warning border-start border-4 p-4 text-center my-4">
        <i class="bi bi-person-x text-warning fs-1 mb-2"></i>
        <h5 class="fw-bold text-dark">Dosen Pembimbing Belum Ditetapkan</h5>
        <p class="text-muted mb-3">
            Admin akademik belum memplot dosen pembimbing untuk akun Anda. Silakan hubungi bagian akademik atau pantau pengumuman plotting dosen di Dashboard.
        </p>
        <div>
            <a href="index.php" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Kembali ke Dashboard
            </a>
        </div>
    </div>
<?php else: ?>

    <!-- RINGKASAN METRIK BIMBINGAN -->
    <div class="row g-2 g-md-3 mb-4">
        <div class="col-6 col-md-6 col-lg-3">
            <div class="card shadow-sm border-0 border-start border-primary border-4 p-2.5 p-sm-3 bg-white h-100">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small fw-semibold d-block">Slot Waktu</span>
                        <h4 class="fw-bold text-dark mb-0 fs-5 fs-sm-4"><?= count($jadwal_dosen_list); ?> Sesi</h4>
                    </div>
                    <div class="bg-primary-subtle text-primary rounded-circle p-2 fs-5 fs-sm-4">
                        <i class="bi bi-calendar-event"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-6 col-lg-3">
            <div class="card shadow-sm border-0 border-start border-success border-4 p-2.5 p-sm-3 bg-white h-100">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small fw-semibold d-block">Sesi Disetujui</span>
                        <h4 class="fw-bold text-success mb-0 fs-5 fs-sm-4"><?= $count_disetujui; ?> Sesi</h4>
                    </div>
                    <div class="bg-success-subtle text-success rounded-circle p-2 fs-5 fs-sm-4">
                        <i class="bi bi-calendar-check"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-6 col-lg-3">
            <div class="card shadow-sm border-0 border-start border-warning border-4 p-2.5 p-sm-3 bg-white h-100">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small fw-semibold d-block">Menunggu</span>
                        <h4 class="fw-bold text-warning mb-0 fs-5 fs-sm-4"><?= $count_menunggu; ?> Berkas</h4>
                    </div>
                    <div class="bg-warning-subtle text-warning rounded-circle p-2 fs-5 fs-sm-4">
                        <i class="bi bi-hourglass-split"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-6 col-lg-3">
            <div class="card shadow-sm border-0 border-start border-info border-4 p-2.5 p-sm-3 bg-white h-100">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small fw-semibold d-block">Selesai</span>
                        <h4 class="fw-bold text-info mb-0 fs-5 fs-sm-4"><?= $count_selesai; ?> Sesi</h4>
                    </div>
                    <div class="bg-info-subtle text-info rounded-circle p-2 fs-5 fs-sm-4">
                        <i class="bi bi-check2-all"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ROW 1: PROFIL DOSEN PEMBIMBING & JADWAL KETERSEDIAAN MINGGUAN -->
    <div class="row g-4 mb-4">
        <!-- Kartu Profil & Kontak Dosen Pembimbing -->
        <div class="col-lg-4">
            <div class="card shadow-sm h-100 border-0">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="m-0 fw-bold text-dark">
                        <i class="bi bi-person-vcard text-primary me-2"></i>Dosen Pembimbing
                    </h6>
                </div>
                <div class="card-body text-center p-4">
                    <?php 
                    $foto_dosen_src = function_exists('get_avatar_url') ? get_avatar_url($mhs['foto_dosen'] ?? null) : null;
                    ?>
                    <div class="mb-3">
                        <div class="position-relative d-inline-block">
                            <a href="javascript:void(0)" data-bs-toggle="modal" data-bs-target="#modalProfilDosen" title="Klik untuk melihat profil dosen">
                                <?php if ($foto_dosen_src): ?>
                                    <img src="<?= $foto_dosen_src; ?>" class="rounded-circle img-thumbnail shadow-sm object-fit-cover" width="115" height="115" style="cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.06)'" onmouseout="this.style.transform='scale(1)'" alt="Foto Profil Dosen">
                                <?php else: ?>
                                    <div class="avatar-circle avatar-primary shadow mx-auto d-flex align-items-center justify-content-center text-primary rounded-circle" style="width: 110px; height: 110px; font-size: 2.8rem; cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.06)'" onmouseout="this.style.transform='scale(1)'">
                                        <i class="bi bi-person-fill"></i>
                                    </div>
                                <?php endif; ?>
                            </a>
                            <span class="position-absolute bottom-0 end-0 p-2 bg-success border border-3 border-white rounded-circle shadow-sm" title="Dosen Aktif"></span>
                        </div>
                        <div class="mt-2">
                            <a href="javascript:void(0)" class="badge bg-primary-subtle text-primary border border-primary-subtle text-decoration-none px-3 py-1.5" data-bs-toggle="modal" data-bs-target="#modalProfilDosen" style="font-size: 0.75rem;">
                                <i class="bi bi-person-vcard me-1"></i>Lihat Profil Lengkap Dosen
                            </a>
                        </div>
                    </div>

                    <h5 class="fw-bold text-dark mb-1"><?= htmlspecialchars($mhs['nama_dosen'] ?? 'Dosen Pembimbing'); ?></h5>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-1 mb-3">
                        Dosen Pembimbing Utama
                    </span>

                    <ul class="list-group list-group-flush text-start small mb-4">
                        <li class="list-group-item d-flex justify-content-between px-0 py-2">
                            <span class="text-muted"><i class="bi bi-card-text me-2"></i>NIDN</span>
                            <span class="fw-semibold text-dark"><?= htmlspecialchars($mhs['nidn'] ?? '-'); ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0 py-2">
                            <span class="text-muted"><i class="bi bi-shield-check me-2"></i>Status SK</span>
                            <?php if ($status_pembimbing === 'aktif'): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle">
                                    <i class="bi bi-check-circle me-1"></i>SK Aktif
                                </span>
                            <?php else: ?>
                                <span class="badge bg-warning-subtle text-warning border border-warning-subtle">
                                    <i class="bi bi-clock me-1"></i>Belum Dikonfirmasi
                                </span>
                            <?php endif; ?>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0 py-2">
                            <span class="text-muted"><i class="bi bi-people me-2"></i>Kuota Bimbingan</span>
                            <span class="fw-semibold text-dark"><?= $total_mhs_bimbingan; ?> / <?= htmlspecialchars($mhs['kuota_bimbingan'] ?? '10'); ?> Mahasiswa</span>
                        </li>
                    </ul>

                    <!-- Informasi Kontak Resmi Dosen -->
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
                                <?php if (!empty($mhs['email_dosen'])): ?>
                                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($mhs['email_dosen']); ?>'); alert('Email berhasil disalin!');" title="Salin Email">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div>
                            <label class="text-muted small d-block mb-1">WhatsApp / Telepon:</label>
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
                            <i class="bi bi-chat-dots-fill me-1"></i>Kirim Pesan di Aplikasi
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Kartu Tabel Jadwal Ketersediaan Dosen -->
        <div class="col-lg-8">
            <div class="card shadow-sm h-100 border-0">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
                    <h6 class="m-0 fw-bold text-dark">
                        <i class="bi bi-calendar-week text-primary me-2"></i>Jadwal Ketersediaan Waktu Dosen
                    </h6>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle small">
                        <?= count($jadwal_dosen_list); ?> Sesi Rutin
                    </span>
                </div>
                <div class="card-body">
                    <div class="alert alert-info py-2 small mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        Jadwal di bawah ini merupakan waktu ketersediaan rutin dosen pembimbing untuk melayani bimbingan skripsi. Pastikan Anda berkonsultasi atau hadir pada rentang waktu aktif berikut.
                    </div>

                    <?php if (empty($jadwal_dosen_list)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-calendar-x fs-1 d-block mb-2 text-secondary"></i>
                            <h6 class="fw-bold">Belum Ada Jadwal Ketersediaan</h6>
                            <p class="small mb-3">Dosen pembimbing Anda belum memasukkan jadwal ketersediaan bimbingan mingguan.</p>
                            <a href="chat.php" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-chat-dots me-1"></i>Tanyakan Waktu Bimbingan via Chat
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Tanggal & Hari</th>
                                        <th>Jam Bimbingan</th>
                                        <th>Status Ketersediaan</th>
                                        <th class="text-end">Aksi / Tindak Lanjut</th>
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
                                    foreach ($jadwal_dosen_list as $row): 
                                        $color = $hari_color[$row['hari']] ?? 'secondary';
                                        $is_aktif = ($row['status_aktif'] === 'aktif');
                                    ?>
                                        <tr>
                                            <td>
                                                <?php if (!empty($row['tanggal'])): ?>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <span class="badge bg-primary text-white px-2.5 py-1.5 shadow-sm">
                                                            <i class="bi bi-calendar-check me-1"></i><?= date('d M Y', strtotime($row['tanggal'])); ?>
                                                        </span>
                                                        <span class="badge bg-light text-dark border">
                                                            Hari <?= htmlspecialchars($row['hari']); ?>
                                                        </span>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="badge bg-<?= $color; ?> px-2.5 py-1.5 fs-6">
                                                        <i class="bi bi-repeat me-1"></i>Rutin <?= htmlspecialchars($row['hari']); ?>
                                                    </span>
                                                <?php endif; ?>
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
                                                    <a href="chat.php" class="btn btn-outline-primary btn-sm">
                                                        <i class="bi bi-chat-dots me-1"></i>Konfirmasi via Chat
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

    <!-- ROW 2: AGENDA & JADWAL SESI BIMBINGAN MAHASISWA -->
    <div class="card shadow-sm mb-4 border-0">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
            <div>
                <h6 class="m-0 fw-bold text-dark">
                    <i class="bi bi-calendar-check text-primary me-2"></i>Agenda & Riwayat Jadwal Sesi Bimbingan Anda
                </h6>
                <small class="text-muted">Daftar sesi bimbingan, naskah bab yang diserahkan, serta catatan instruksi dari dosen pembimbing.</small>
            </div>
            <a href="upload_draft.php" class="btn btn-primary btn-sm">
                <i class="bi bi-cloud-arrow-up me-1"></i>Upload Naskah / Bab
            </a>
        </div>
        <div class="card-body p-0">
            <?php if (empty($sesi_bimbingan_list)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-calendar2-range fs-1 d-block mb-2 text-secondary"></i>
                    <h6 class="fw-bold text-dark">Belum Ada Agenda Sesi Bimbingan</h6>
                    <p class="small mb-3 text-muted">
                        Anda belum memiliki agenda bimbingan yang tercatat. Silakan serahkan usulan judul atau draf bab skripsi melalui menu Upload Berkas.
                    </p>
                    <a href="upload_draft.php" class="btn btn-primary btn-sm">
                        <i class="bi bi-cloud-arrow-up me-1"></i>Ke Menu Upload Berkas
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 20%;">Tanggal & Jam Bimbingan</th>
                                <th style="width: 22%;">Bab / Topik Pembahasan</th>
                                <th style="width: 18%;">Berkas / Draft Skripsi</th>
                                <th style="width: 15%;">Status Sesi</th>
                                <th style="width: 25%;">Catatan & Arahan Dosen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sesi_bimbingan_list as $sesi): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold text-dark">
                                            <i class="bi bi-calendar-event me-1 text-primary"></i>
                                            <?= format_indo_date($sesi['tanggal_bimbingan']); ?>
                                        </div>
                                        <small class="text-muted">
                                            <i class="bi bi-clock me-1"></i>
                                            <?= substr($sesi['jam_bimbingan'], 0, 5); ?> WIB
                                        </small>
                                    </td>
                                    <td>
                                        <span class="fw-bold text-dark d-block">
                                            <?= htmlspecialchars($sesi['bab_pembahasan']); ?>
                                        </span>
                                        <?php if (!empty($sesi['catatan_mahasiswa'])): ?>
                                            <small class="text-muted d-block text-truncate" style="max-width: 240px;" title="<?= htmlspecialchars($sesi['catatan_mahasiswa']); ?>">
                                                <i class="bi bi-chat-quote me-1"></i><?= htmlspecialchars($sesi['catatan_mahasiswa']); ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($sesi['file_draft'])): ?>
                                            <a href="../unduh_berkas.php?id=<?= $sesi['id']; ?>" target="_blank" class="btn btn-outline-danger btn-sm py-1 px-2 mb-1 d-inline-flex align-items-center">
                                                <i class="bi bi-file-earmark-pdf me-1"></i>Lihat PDF
                                            </a>
                                        <?php endif; ?>
                                        <?php if (!empty($sesi['link_drive'])): ?>
                                            <a href="<?= htmlspecialchars($sesi['link_drive']); ?>" target="_blank" class="btn btn-outline-primary btn-sm py-1 px-2 mb-1 d-inline-flex align-items-center">
                                                <i class="bi bi-google me-1"></i>Google Drive
                                            </a>
                                        <?php endif; ?>
                                        <?php if (empty($sesi['file_draft']) && empty($sesi['link_drive'])): ?>
                                            <span class="text-muted small">- Tidak ada berkas -</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $st = $sesi['status'];
                                        if ($st === 'disetujui'): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1.5">
                                                <i class="bi bi-check-circle me-1"></i>Disetujui / Terjadwal
                                            </span>
                                        <?php elseif ($st === 'menunggu'): ?>
                                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2.5 py-1.5">
                                                <i class="bi bi-clock-history me-1"></i>Menunggu Review
                                            </span>
                                        <?php elseif ($st === 'revisi'): ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2.5 py-1.5">
                                                <i class="bi bi-exclamation-triangle me-1"></i>Perlu Revisi
                                            </span>
                                        <?php elseif ($st === 'selesai'): ?>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1.5">
                                                <i class="bi bi-check2-all me-1"></i>Selesai / Terlaksana
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary border px-2.5 py-1.5">
                                                <?= htmlspecialchars(ucfirst($st)); ?>
                                            </span>
                                        <?php endif; ?>

                                        <?php if (!empty($sesi['nilai_progres']) && (int)$sesi['nilai_progres'] > 0): ?>
                                            <div class="mt-1">
                                                <span class="badge bg-info-subtle text-info small">
                                                    Nilai: <?= (int)$sesi['nilai_progres']; ?> / 100
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($sesi['catatan_dosen'])): ?>
                                            <div class="p-2 bg-light rounded small border">
                                                <i class="bi bi-chat-left-text text-primary me-1"></i>
                                                <?= nl2br(htmlspecialchars($sesi['catatan_dosen'])); ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted small"><em>Belum ada catatan dari dosen</em></span>
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

    <!-- ROW 3: PANDUAN ALUR BIMBINGAN -->
    <div class="card shadow-sm border-0 mb-4 bg-light">
        <div class="card-body p-4">
            <h6 class="fw-bold text-dark mb-3">
                <i class="bi bi-info-circle-fill text-primary me-2"></i>Panduan & Alur Bimbingan Skripsi
            </h6>
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="bg-white p-3 rounded h-100 border">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge bg-primary rounded-circle px-2 py-1">1</span>
                            <span class="fw-bold small text-dark">Ajukan Judul Skripsi</span>
                        </div>
                        <p class="small text-muted mb-0">
                            Masuk ke menu <strong>Upload Berkas</strong> dan ajukan usulan judul skripsi terlebih dahulu agar rencana topik disetujui.
                        </p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="bg-white p-3 rounded h-100 border">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge bg-primary rounded-circle px-2 py-1">2</span>
                            <span class="fw-bold small text-dark">Cek Jadwal Dosen</span>
                        </div>
                        <p class="small text-muted mb-0">
                            Perhatikan tabel <strong>Ketersediaan Waktu Dosen</strong> di atas untuk mengetahui hari dan jam aktif bimbingan.
                        </p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="bg-white p-3 rounded h-100 border">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge bg-primary rounded-circle px-2 py-1">3</span>
                            <span class="fw-bold small text-dark">Serahkan Naskah Bab</span>
                        </div>
                        <p class="small text-muted mb-0">
                            Unggah draf bab (Bab 1 s.d. Bab 5) via PDF atau Google Docs di menu Upload Berkas sebelum sesi konsultasi dimulai.
                        </p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="bg-white p-3 rounded h-100 border">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge bg-primary rounded-circle px-2 py-1">4</span>
                            <span class="fw-bold small text-dark">Evaluasi di Logbook</span>
                        </div>
                        <p class="small text-muted mb-0">
                            Pantau catatan arahan dan persetujuan bab dosen yang otomatis tercatat ke <strong>Logbook & Laporan</strong> Anda.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php endif; ?>


<!-- ============================================================
     MODAL LIHAT PROFIL DOSEN PEMBIMBING (SESUAI PROFIL DOSEN)
============================================================ -->
<div class="modal fade" id="modalProfilDosen" tabindex="-1" aria-labelledby="modalProfilDosenLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header text-white border-0 py-3" style="background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 60%, #1e40af 100%);">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-person-vcard-fill text-info fs-5"></i>
                    <h5 class="modal-title fw-bold text-white mb-0" id="modalProfilDosenLabel">Profil Dosen Pembimbing</h5>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <div class="modal-body p-4">
                <!-- Foto & Identitas Utama Dosen -->
                <div class="text-center mb-4">
                    <div class="mb-3 position-relative d-inline-block mx-auto">
                        <?php if ($foto_dosen_src): ?>
                            <img src="<?= $foto_dosen_src; ?>" 
                                 alt="Foto Profil Dosen" 
                                 class="rounded-circle img-thumbnail shadow object-fit-cover" 
                                 style="width: 125px; height: 125px;">
                        <?php else: ?>
                            <div class="avatar-circle avatar-primary shadow mx-auto d-flex align-items-center justify-content-center text-primary rounded-circle" 
                                 style="width: 125px; height: 125px; font-size: 3.2rem;">
                                <i class="bi bi-person-fill"></i>
                            </div>
                        <?php endif; ?>
                        <span class="position-absolute bottom-0 end-0 p-2 bg-success border border-3 border-white rounded-circle shadow-sm" title="Status Pembimbing Aktif"></span>
                    </div>

                    <h5 class="fw-bold mb-1 text-dark"><?= htmlspecialchars($mhs['nama_dosen'] ?? 'Dosen Pembimbing'); ?></h5>
                    <p class="text-muted small mb-2">NIDN: <?= htmlspecialchars($mhs['nidn'] ?? '-'); ?></p>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-1.5 rounded-pill">
                        <i class="bi bi-award-fill me-1"></i>Dosen Pembimbing Utama Skripsi
                    </span>
                </div>

                <!-- Detail Biodata Profil Dosen (Sesuai profil dosen itu sendiri) -->
                <div class="list-group list-group-flush text-start small border rounded-3 p-3 bg-light mb-3">
                    <div class="list-group-item d-flex justify-content-between px-0 py-2 bg-transparent border-bottom">
                        <span class="text-muted"><i class="bi bi-person-badge me-2 text-primary"></i>ID Login / Akun:</span>
                        <strong class="text-primary">@<?= htmlspecialchars($mhs['username_dosen'] ?? 'dosen'); ?></strong>
                    </div>
                    <div class="list-group-item d-flex justify-content-between px-0 py-2 bg-transparent border-bottom">
                        <span class="text-muted"><i class="bi bi-envelope me-2 text-primary"></i>Email Resmi:</span>
                        <a href="mailto:<?= htmlspecialchars($mhs['email_dosen'] ?? ''); ?>" class="text-decoration-none fw-semibold text-secondary">
                            <?= htmlspecialchars($mhs['email_dosen'] ?? '-'); ?>
                        </a>
                    </div>
                    <div class="list-group-item d-flex justify-content-between px-0 py-2 bg-transparent border-bottom">
                        <span class="text-muted"><i class="bi bi-whatsapp me-2 text-success"></i>Nomor WhatsApp:</span>
                        <?php if (!empty($mhs['no_whatsapp'])): 
                            $clean_wa = preg_replace('/[^0-9]/', '', $mhs['no_whatsapp']);
                            if (str_starts_with($clean_wa, '0')) {
                                $clean_wa = '62' . substr($clean_wa, 1);
                            }
                        ?>
                            <a href="https://wa.me/<?= $clean_wa; ?>" target="_blank" class="text-decoration-none fw-semibold text-success">
                                <?= htmlspecialchars($mhs['no_whatsapp']); ?> <i class="bi bi-box-arrow-up-right small"></i>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </div>
                    <div class="list-group-item d-flex justify-content-between px-0 py-2 bg-transparent">
                        <span class="text-muted"><i class="bi bi-people me-2 text-primary"></i>Kapasitas Bimbingan:</span>
                        <strong><?= htmlspecialchars($mhs['kuota_bimbingan'] ?? '5'); ?> Mahasiswa</strong>
                    </div>
                </div>

                <!-- Jadwal Ketersediaan Bimbingan Dosen -->
                <div class="p-3 border rounded-3 bg-white">
                    <h6 class="fw-bold text-dark mb-2 small d-flex align-items-center">
                        <i class="bi bi-calendar-check text-primary me-2"></i>Jadwal Konsultasi Dosen
                    </h6>
                    <?php if (!empty($jadwal_dosen_list)): ?>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($jadwal_dosen_list as $jd): ?>
                                <span class="badge bg-light text-dark border px-2.5 py-1.5 small">
                                    <i class="bi bi-clock-fill text-primary me-1"></i>
                                    <strong><?= htmlspecialchars($jd['hari']); ?>:</strong> <?= date('H:i', strtotime($jd['jam_mulai'])); ?> - <?= date('H:i', strtotime($jd['jam_selesai'])); ?> WIB
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted small mb-0">
                            <i class="bi bi-info-circle me-1"></i>Jadwal konsultasi tatap muka / daring dapat disepakati secara fleksibel melalui fitur Chat atau WhatsApp.
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="modal-footer bg-light border-top d-flex justify-content-between">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
                <div class="d-flex gap-2">
                    <a href="chat.php" class="btn btn-primary btn-sm">
                        <i class="bi bi-chat-dots-fill me-1"></i>Kirim Pesan Chat
                    </a>
                    <?php if (!empty($mhs['no_whatsapp'])): ?>
                        <a href="https://wa.me/<?= $clean_wa; ?>" target="_blank" class="btn btn-success btn-sm">
                            <i class="bi bi-whatsapp me-1"></i>WhatsApp
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var modalTrigger = document.querySelectorAll('[data-bs-target="#modalLihatFotoDosen"]');
    modalTrigger.forEach(function(el) {
        el.setAttribute('data-bs-target', '#modalProfilDosen');
    });
});
</script>

<?php
require_once '../templates/footer.php';
?>

