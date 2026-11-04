<?php
// dosen/persetujuan.php - Lihat Jadwal & Persetujuan Bimbingan (Salsa)
// Fitur: Notifikasi, Setujui (Terima), Tolak, Reschedule (Jadwal Ulang), Unduh Draft
$page_title   = "Lihat Jadwal Bimbingan";
$current_page = "persetujuan";

require_once '../config/database.php';
require_once '../templates/header.php';
require_once '../templates/sidebar_dosen.php';

// Proteksi hak akses role dosen
if ($_SESSION['role'] !== 'dosen') {
    header("Location: ../login");
    exit;
}

// Ambil data dosen berdasarkan user_id aktif
$stmt_dosen = $pdo->prepare("SELECT * FROM dosen WHERE user_id = :uid LIMIT 1");
$stmt_dosen->execute([':uid' => $_SESSION['user_id']]);
$dosen = $stmt_dosen->fetch();
$dosen_id = $dosen['id'] ?? 0;

$success_msg = '';
$error_msg   = '';

// 1. PROSES AKSI: SETUJUI / TOLAK
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['proses_keputusan'])) {
    $id_pengajuan  = (int)($_POST['id_pengajuan'] ?? 0);
    $status_baru   = trim($_POST['status_baru'] ?? '');
    $catatan_dosen = trim($_POST['catatan_dosen'] ?? '');

    if ($id_pengajuan <= 0 || !in_array($status_baru, ['disetujui', 'ditolak'])) {
        $error_msg = "Permintaan keputusan tidak valid.";
    } else {
        try {
            $stmt_update = $pdo->prepare("
                UPDATE pengajuan_bimbingan 
                SET status = :status, 
                    catatan_dosen = :catatan 
                WHERE id = :id AND dosen_id = :did
            ");
            $stmt_update->execute([
                ':status'  => $status_baru,
                ':catatan' => !empty($catatan_dosen) ? $catatan_dosen : null,
                ':id'      => $id_pengajuan,
                ':did'     => $dosen_id
            ]);

            // Kirim notifikasi otomatis ke mahasiswa
            try {
                $st_m_user = $pdo->prepare("SELECT m.user_id, pb.bab_pembahasan, pb.tanggal_bimbingan FROM pengajuan_bimbingan pb JOIN mahasiswa m ON pb.mahasiswa_id = m.id WHERE pb.id = :pbid LIMIT 1");
                $st_m_user->execute([':pbid' => $id_pengajuan]);
                $m_u = $st_m_user->fetch();
                if ($m_u) {
                    $judul_n = ($status_baru === 'disetujui') ? 'Jadwal Bimbingan Disetujui' : 'Pengajuan Bimbingan Ditolak';
                    $tipe_n  = ($status_baru === 'disetujui') ? 'pembimbing' : 'revisi';
                    $pesan_n = ($status_baru === 'disetujui') 
                        ? "Permohonan bimbingan {$m_u['bab_pembahasan']} pada tanggal " . date('d/m/Y', strtotime($m_u['tanggal_bimbingan'])) . " telah DISETUJUI oleh dosen pembimbing."
                        : "Permohonan bimbingan {$m_u['bab_pembahasan']} ditolak. Catatan: " . (!empty($catatan_dosen) ? $catatan_dosen : 'Silakan ajukan jadwal baru.');
                    $st_notif = $pdo->prepare("INSERT INTO notifikasi (user_id, judul, pesan, tipe, link_tujuan) VALUES (:uid, :jdl, :psn, :tp, 'logbook.php')");
                    $st_notif->execute([':uid' => $m_u['user_id'], ':jdl' => $judul_n, ':psn' => $pesan_n, ':tp' => $tipe_n]);

                    // Notifikasi konfirmasi ke akun Dosen sendiri
                    if (!empty($_SESSION['user_id'])) {
                        $judul_d = ($status_baru === 'disetujui') ? 'Konfirmasi Bimbingan Disetujui' : 'Pengajuan Bimbingan Ditolak';
                        $pesan_d = ($status_baru === 'disetujui') 
                            ? "Anda telah menyetujui sesi bimbingan {$m_u['bab_pembahasan']} pada tanggal " . date('d/m/Y', strtotime($m_u['tanggal_bimbingan'])) . "."
                            : "Anda telah menolak pengajuan bimbingan {$m_u['bab_pembahasan']}.";
                        $pdo->prepare("INSERT INTO notifikasi (user_id, judul, pesan, tipe, link_tujuan) VALUES (:uid, :jdl, :psn, 'pembimbing', 'riwayat_bimbingan.php')")->execute([
                            ':uid' => $_SESSION['user_id'], ':jdl' => $judul_d, ':psn' => $pesan_d
                        ]);
                    }
                }
            } catch (Exception $ne) {}

            if ($status_baru === 'disetujui') {
                $success_msg = "Permohonan bimbingan berhasil <strong>DISETUJUI / DITERIMA</strong>.";
            } else {
                $success_msg = "Permohonan bimbingan telah <strong>DITOLAK</strong>.";
            }
        } catch (PDOException $e) {
            $error_msg = "Terjadi kesalahan saat memproses data: " . $e->getMessage();
        }
    }
}

// 2. PROSES AKSI: RESCHEDULE (JADWALKAN ULANG)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['proses_reschedule'])) {
    $id_pengajuan    = (int)($_POST['id_pengajuan'] ?? 0);
    $tanggal_baru    = trim($_POST['tanggal_baru'] ?? '');
    $jam_baru        = trim($_POST['jam_baru'] ?? '');
    $catatan_alasan  = trim($_POST['catatan_reschedule'] ?? '');

    if ($id_pengajuan <= 0 || empty($tanggal_baru) || empty($jam_baru)) {
        $error_msg = "Tanggal baru dan jam baru wajib diisi untuk menjadwalkan ulang.";
    } else {
        try {
            $tgl_format = date('d-m-Y', strtotime($tanggal_baru));
            $jam_format = substr($jam_baru, 0, 5);
            $catatan_final = "Dijadwalkan ulang oleh Dosen ke: {$tgl_format} pukul {$jam_format} WIB. ";
            if (!empty($catatan_alasan)) {
                $catatan_final .= "Catatan: " . $catatan_alasan;
            }

            // Update jadwal & set status menjadi disetujui
            $stmt_res = $pdo->prepare("
                UPDATE pengajuan_bimbingan 
                SET tanggal_bimbingan = :tgl, 
                    jam_bimbingan = :jam, 
                    status = 'disetujui', 
                    catatan_dosen = :catatan 
                WHERE id = :id AND dosen_id = :did
            ");
            $stmt_res->execute([
                ':tgl'     => $tanggal_baru,
                ':jam'     => $jam_baru,
                ':catatan' => $catatan_final,
                ':id'      => $id_pengajuan,
                ':did'     => $dosen_id
            ]);

            // Kirim notifikasi reschedule ke mahasiswa
            try {
                $st_m_user = $pdo->prepare("SELECT m.user_id, pb.bab_pembahasan FROM pengajuan_bimbingan pb JOIN mahasiswa m ON pb.mahasiswa_id = m.id WHERE pb.id = :pbid LIMIT 1");
                $st_m_user->execute([':pbid' => $id_pengajuan]);
                $m_u = $st_m_user->fetch();
                if ($m_u) {
                    $st_notif = $pdo->prepare("INSERT INTO notifikasi (user_id, judul, pesan, tipe, link_tujuan) VALUES (:uid, 'Jadwal Bimbingan Dijadwalkan Ulang', :psn, 'revisi', 'logbook.php')");
                    $st_notif->execute([
                        ':uid' => $m_u['user_id'],
                        ':psn' => "Jadwal bimbingan {$m_u['bab_pembahasan']} telah dijadwalkan ulang oleh Dosen ke tanggal {$tgl_format} pukul {$jam_format} WIB."
                    ]);

                    // Notifikasi konfirmasi ke akun Dosen sendiri
                    if (!empty($_SESSION['user_id'])) {
                        $pdo->prepare("INSERT INTO notifikasi (user_id, judul, pesan, tipe, link_tujuan) VALUES (:uid, 'Jadwal Bimbingan Dijadwalkan Ulang', :psn, 'pembimbing', 'riwayat_bimbingan.php')")->execute([
                            ':uid' => $_SESSION['user_id'],
                            ':psn' => "Anda telah berhasil menjadwalkan ulang bimbingan {$m_u['bab_pembahasan']} ke tanggal {$tgl_format} pukul {$jam_format} WIB."
                        ]);
                    }
                }
            } catch (Exception $ne) {}

            $success_msg = "Jadwal bimbingan berhasil <strong>DIJADWALKAN ULANG & DISETUJUI</strong> ke tanggal {$tgl_format} pukul {$jam_format} WIB!";
        } catch (PDOException $e) {
            $error_msg = "Gagal menjadwalkan ulang: " . $e->getMessage();
        }
    }
}

// Ambil filter status dari URL (default: menunggu)
$filter_status = $_GET['status'] ?? 'menunggu';
$allowed_filters = ['menunggu', 'semua', 'disetujui', 'ditolak', 'revisi'];
if (!in_array($filter_status, $allowed_filters)) {
    $filter_status = 'menunggu';
}

if ($filter_status === 'semua') {
    $stmt_pengajuan = $pdo->prepare("
        SELECT pb.*, m.nim, m.nama_lengkap, m.judul_skripsi, m.kelas, m.status_pembimbing 
        FROM pengajuan_bimbingan pb
        JOIN mahasiswa m ON pb.mahasiswa_id = m.id
        WHERE pb.dosen_id = :did
        ORDER BY pb.tanggal_bimbingan DESC, pb.jam_bimbingan DESC
    ");
    $stmt_pengajuan->execute([':did' => $dosen_id]);
} else {
    $stmt_pengajuan = $pdo->prepare("
        SELECT pb.*, m.nim, m.nama_lengkap, m.judul_skripsi, m.kelas, m.status_pembimbing 
        FROM pengajuan_bimbingan pb
        JOIN mahasiswa m ON pb.mahasiswa_id = m.id
        WHERE pb.dosen_id = :did AND pb.status = :st
        ORDER BY pb.tanggal_bimbingan ASC, pb.jam_bimbingan ASC
    ");
    $stmt_pengajuan->execute([':did' => $dosen_id, ':st' => $filter_status]);
}
$daftar_pengajuan = $stmt_pengajuan->fetchAll();

// Hitung jumlah antrean status 'menunggu' untuk badge notifikasi
$stmt_badge = $pdo->prepare("SELECT COUNT(*) FROM pengajuan_bimbingan WHERE dosen_id = :did AND status = 'menunggu'");
$stmt_badge->execute([':did' => $dosen_id]);
$jumlah_menunggu = $stmt_badge->fetchColumn();
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-2 pt-md-3 pb-2 mb-3 mb-md-4 border-bottom">
    <div>
        <h1 class="h3 fw-bold mb-1 fs-5 fs-md-3"><i class="bi bi-calendar-event text-primary me-2"></i>Lihat Jadwal & Persetujuan Bimbingan</h1>
        <p class="text-muted small mb-0 d-none d-sm-block">Tinjau jadwal dan permohonan bimbingan mahasiswa: Terima jadwal, atur ulang waktu pertemuan (reschedule), atau tolak dengan catatan revisi.</p>
    </div>
</div>

<!-- Banner Notifikasi Pengajuan Menunggu -->
<?php if ($jumlah_menunggu > 0 && $filter_status !== 'menunggu'): ?>
    <div class="alert alert-warning d-flex align-items-center justify-content-between shadow-sm py-2 px-3 mb-3 border-warning">
        <div class="d-flex align-items-center gap-2">
            <span class="status-dot pending"></span>
            <span>Terdapat <strong><?= $jumlah_menunggu; ?> permohonan bimbingan baru</strong> yang memerlukan respon Anda.</span>
        </div>
        <a href="persetujuan.php?status=menunggu" class="btn btn-warning btn-sm fw-bold px-3">Tinjau Sekarang &rarr;</a>
    </div>
<?php endif; ?>

<!-- Alert Status Pesan -->
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

<!-- Tab Filter Status Modern (Swipeable di HP) -->
<div class="filter-scroll-wrapper mb-3">
    <div class="d-flex gap-2 flex-nowrap align-items-center pb-1">
        <a href="persetujuan.php?status=menunggu" class="btn btn-sm rounded-pill <?= ($filter_status === 'menunggu') ? 'btn-primary shadow-sm text-white' : 'btn-outline-secondary'; ?>">
            <i class="bi bi-hourglass-split me-1"></i>Menunggu Konfirmasi
            <?php if ($jumlah_menunggu > 0): ?>
                <span class="badge bg-danger rounded-pill ms-1"><?= $jumlah_menunggu; ?></span>
            <?php endif; ?>
        </a>
        <a href="persetujuan.php?status=disetujui" class="btn btn-sm rounded-pill <?= ($filter_status === 'disetujui') ? 'btn-success shadow-sm text-white' : 'btn-outline-secondary'; ?>">
            <i class="bi bi-check-circle-fill me-1"></i>Telah Disetujui
        </a>
        <a href="persetujuan.php?status=ditolak" class="btn btn-sm rounded-pill <?= ($filter_status === 'ditolak') ? 'btn-danger shadow-sm text-white' : 'btn-outline-secondary'; ?>">
            <i class="bi bi-x-circle-fill me-1"></i>Ditolak
        </a>
        <a href="persetujuan.php?status=semua" class="btn btn-sm rounded-pill <?= ($filter_status === 'semua') ? 'btn-secondary shadow-sm text-white' : 'btn-outline-secondary'; ?>">
            <i class="bi bi-list-ul me-1"></i>Semua Riwayat
        </a>
    </div>
</div>

<!-- Card Tabel Pengajuan -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header py-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h6 class="mb-0 fw-bold text-dark">
                <i class="bi bi-inbox-fill text-primary me-2"></i>Daftar Pengajuan (<?= ucfirst($filter_status); ?>)
            </h6>
            <small class="text-muted d-none d-sm-block">Kelola keputusan konsultasi dan penyesuaian waktu bimbingan</small>
        </div>
        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1"><?= count($daftar_pengajuan); ?> Data Ditemukan</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-compact align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 35px;" class="text-center">No</th>
                        <th style="min-width: 170px;">Mahasiswa & Judul</th>
                        <th style="min-width: 130px;">Bab Pembahasan</th>
                        <th style="width: 110px;">Jadwal Diajukan</th>
                        <th style="width: 105px;" class="text-center">Draft Naskah</th>
                        <th style="width: 90px;" class="text-center">Status</th>
                        <th style="width: 220px;" class="text-center sticky-action-col">Keputusan / Aksi</th>
                    </tr>
                </thead>
            <tbody>
                <?php if (empty($daftar_pengajuan)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-clipboard-x fs-1 d-block mb-2 text-muted"></i>
                            Tidak ada permohonan bimbingan dengan status <strong><?= htmlspecialchars($filter_status); ?></strong>.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($daftar_pengajuan as $index => $row): ?>
                        <tr>
                            <td class="text-center fw-semibold text-muted"><?= $index + 1; ?></td>
                            <td>
                                <div class="d-flex align-items-start gap-2">
                                    <div class="avatar-circle avatar-sm avatar-primary mt-1 flex-shrink-0">
                                        <?= strtoupper(substr($row['nama_lengkap'], 0, 1)); ?>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="fw-bold text-dark text-truncate" style="max-width: 230px;"><?= htmlspecialchars($row['nama_lengkap']); ?></div>
                                        <div class="small text-muted d-flex align-items-center gap-1">
                                            <span>NIM: <?= htmlspecialchars($row['nim']); ?></span>
                                            <?php if (!empty($row['kelas'])): ?>
                                                <span class="badge bg-light text-primary border border-primary-subtle"><?= htmlspecialchars($row['kelas']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-truncate small text-secondary mt-1" style="max-width: 190px;" title="<?= htmlspecialchars($row['judul_skripsi'] ?: 'Belum ada judul'); ?>">
                                            <i class="bi bi-journal-text me-1 text-primary"></i><?= htmlspecialchars($row['judul_skripsi'] ?: 'Belum mengajukan judul skripsi'); ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                                    <?= htmlspecialchars($row['bab_pembahasan']); ?>
                                </span>
                                <?php 
                                $catatan_raw = trim($row['catatan_mahasiswa'] ?? '');
                                $is_duplikat_judul = (strpos($catatan_raw, 'Pengajuan Usulan Judul Skripsi:') !== false);
                                if (!empty($catatan_raw) && !$is_duplikat_judul): 
                                ?>
                                    <div class="small text-muted mt-1" style="max-width: 190px;" title="<?= htmlspecialchars($catatan_raw); ?>">
                                        <i class="bi bi-chat-left-text me-1 text-secondary"></i>"<?= htmlspecialchars($catatan_raw); ?>"
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-semibold text-dark text-nowrap">
                                    <i class="bi bi-calendar-event me-1 text-primary"></i>
                                    <?= date('d M Y', strtotime($row['tanggal_bimbingan'])); ?>
                                </div>
                                <div class="small text-muted text-nowrap mt-1">
                                    <i class="bi bi-clock me-1"></i>
                                    <?= htmlspecialchars(substr($row['jam_bimbingan'], 0, 5)); ?> WIB
                                </div>
                            </td>
                            <td class="text-center">
                                <div class="d-inline-flex flex-column align-items-center gap-1">
                                    <?php if (!empty($row['file_draft'])): 
                                        $pst_pdf_url = "../unduh_berkas.php?id=" . (int)$row['id'];
                                        $pst_pdf_title = htmlspecialchars($row['judul_skripsi'] ?: ($row['bab_pembahasan'] ?? 'Dokumen Bimbingan'), ENT_QUOTES);
                                    ?>
                                        <div class="btn-group btn-group-sm shadow-sm" role="group">
                                            <button type="button" class="btn btn-danger fw-semibold d-inline-flex align-items-center gap-1 px-2"
                                                    onclick="bukaPreviewPdf('<?= $pst_pdf_url; ?>', '<?= $pst_pdf_title; ?>')" title="Lihat Pratinjau Dokumen">
                                                <i class="bi bi-file-earmark-pdf-fill"></i> Preview
                                            </button>
                                            <a href="<?= $pst_pdf_url; ?>&download=1" 
                                               class="btn btn-outline-danger fw-semibold d-inline-flex align-items-center px-2" 
                                               target="_blank" download title="Unduh Berkas PDF">
                                                <i class="bi bi-download"></i>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($row['link_drive'])): ?>
                                        <a href="<?= htmlspecialchars($row['link_drive']); ?>" 
                                           class="btn btn-sm btn-outline-success fw-semibold shadow-sm d-inline-flex align-items-center gap-1 px-2 py-0 mt-1" 
                                           target="_blank" rel="noopener noreferrer" style="font-size: 0.75rem;">
                                            <i class="bi bi-google"></i> Drive
                                        </a>
                                    <?php endif; ?>
                                    <?php if (empty($row['file_draft']) && empty($row['link_drive'])): ?>
                                        <span class="badge bg-light text-muted border">Belum Ada Berkas</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="text-center">
                                <?php
                                $st = $row['status'];
                                if ($st === 'menunggu') {
                                    echo '<span class="badge bg-warning text-dark"><span class="status-dot pending"></span>Menunggu</span>';
                                } elseif ($st === 'disetujui') {
                                    echo '<span class="badge bg-success"><span class="status-dot active"></span>Disetujui</span>';
                                } elseif ($st === 'ditolak') {
                                    echo '<span class="badge bg-danger"><span class="status-dot rejected"></span>Ditolak</span>';
                                } elseif ($st === 'revisi') {
                                    echo '<span class="badge bg-info text-dark"><i class="bi bi-arrow-repeat me-1"></i>Revisi</span>';
                                } else {
                                    echo '<span class="badge bg-secondary">' . htmlspecialchars(ucfirst($st)) . '</span>';
                                }
                                ?>
                            </td>
                            <td class="text-center sticky-action-col">
                                <?php if ($row['status'] === 'menunggu'): ?>
                                    <div class="d-inline-flex align-items-center justify-content-center gap-1 flex-nowrap">
                                        <!-- Tombol 1: TERIMA / SETUJUI -->
                                        <form action="persetujuan.php?status=<?= $filter_status; ?>" method="POST" class="d-inline m-0" onsubmit="return confirm('Apakah Anda yakin ingin MENERIMA / MENYETUJUI jadwal bimbingan ini?');">
                                            <input type="hidden" name="proses_keputusan" value="1">
                                            <input type="hidden" name="id_pengajuan" value="<?= $row['id']; ?>">
                                            <input type="hidden" name="status_baru" value="disetujui">
                                            <button type="submit" class="btn btn-sm btn-success fw-semibold px-2 py-1 shadow-sm d-inline-flex align-items-center gap-1 text-nowrap" title="Terima Jadwal">
                                                <i class="bi bi-check-lg"></i> Terima
                                            </button>
                                        </form>

                                        <!-- Tombol 2: RESCHEDULE (Jadwalkan Ulang) -->
                                        <button type="button" 
                                                class="btn btn-sm btn-warning text-dark fw-semibold px-2 py-1 shadow-sm d-inline-flex align-items-center gap-1 text-nowrap" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#modalReschedule<?= $row['id']; ?>"
                                                title="Jadwalkan Ulang / Ganti Jam">
                                            <i class="bi bi-calendar2-range"></i> Reschedule
                                        </button>

                                        <!-- Tombol 3: TOLAK -->
                                        <button type="button" 
                                                class="btn btn-sm btn-outline-danger fw-semibold px-2 py-1 shadow-sm d-inline-flex align-items-center gap-1 text-nowrap" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#modalTolak<?= $row['id']; ?>"
                                                title="Tolak Permohonan">
                                            <i class="bi bi-x-lg"></i> Tolak
                                        </button>
                                    </div>

                                    <!-- MODAL RESCHEDULE -->
                                    <div class="modal fade" id="modalReschedule<?= $row['id']; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog text-start">
                                            <div class="modal-content">
                                                <form action="persetujuan.php?status=<?= $filter_status; ?>" method="POST">
                                                    <input type="hidden" name="proses_reschedule" value="1">
                                                    <input type="hidden" name="id_pengajuan" value="<?= $row['id']; ?>">
                                                    
                                                    <div class="modal-header border-bottom">
                                                        <h5 class="modal-title fw-bold">
                                                            <i class="bi bi-calendar2-range-fill text-warning me-2"></i>Jadwalkan Ulang (Reschedule)
                                                        </h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    <div class="modal-body p-4">
                                                        <div class="p-3 bg-light rounded-3 border mb-3 small">
                                                            <strong>Mahasiswa:</strong> <?= htmlspecialchars($row['nama_lengkap']); ?> (<?= htmlspecialchars($row['nim']); ?>)<br>
                                                            <strong>Jadwal Awal:</strong> <?= date('d M Y', strtotime($row['tanggal_bimbingan'])); ?> pukul <?= substr($row['jam_bimbingan'], 0, 5); ?> WIB
                                                        </div>

                                                        <div class="row g-3 mb-3">
                                                            <div class="col-6">
                                                                <label class="form-label fw-semibold">Tanggal Baru <span class="text-danger">*</span></label>
                                                                <input type="date" name="tanggal_baru" class="form-control" value="<?= $row['tanggal_bimbingan']; ?>" required>
                                                            </div>
                                                            <div class="col-6">
                                                                <label class="form-label fw-semibold">Jam Baru <span class="text-danger">*</span></label>
                                                                <input type="time" name="jam_baru" class="form-control" value="<?= substr($row['jam_bimbingan'], 0, 5); ?>" required>
                                                            </div>
                                                        </div>

                                                        <div class="mb-3">
                                                            <label class="form-label fw-semibold">Alasan Penyesuaian Jadwal:</label>
                                                            <textarea name="catatan_reschedule" class="form-control" rows="3" placeholder="Contoh: Jadwal digeser ke siang karena ada perkuliahan pagi..."></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                                        <button type="submit" class="btn btn-primary fw-semibold px-4">
                                                            <i class="bi bi-check-lg me-1"></i> Simpan & Setujui
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- MODAL TOLAK -->
                                    <div class="modal fade" id="modalTolak<?= $row['id']; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog text-start">
                                            <div class="modal-content">
                                                <form action="persetujuan.php?status=<?= $filter_status; ?>" method="POST">
                                                    <input type="hidden" name="proses_keputusan" value="1">
                                                    <input type="hidden" name="id_pengajuan" value="<?= $row['id']; ?>">
                                                    <input type="hidden" name="status_baru" value="ditolak">

                                                    <div class="modal-header border-bottom">
                                                        <h5 class="modal-title fw-bold text-danger">
                                                            <i class="bi bi-x-circle-fill me-2"></i>Tolak Permohonan Bimbingan
                                                        </h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    <div class="modal-body p-4">
                                                        <p class="mb-2">Anda akan menolak pengajuan bimbingan dari <strong><?= htmlspecialchars($row['nama_lengkap']); ?></strong>.</p>
                                                        <div class="mb-3">
                                                            <label class="form-label fw-semibold">Alasan Penolakan <span class="text-danger">*</span></label>
                                                            <textarea name="catatan_dosen" class="form-control" rows="3" placeholder="Contoh: Dokumen draft naskah belum lengkap..." required></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                                        <button type="submit" class="btn btn-danger px-4">Tolak Pengajuan</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                <?php else: ?>
                                    <button type="button" 
                                            class="btn btn-sm btn-outline-secondary fw-semibold px-2 py-1 shadow-sm d-inline-flex align-items-center gap-1 text-nowrap" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#modalDetail<?= $row['id']; ?>">
                                        <i class="bi bi-eye"></i> Detail Catatan
                                    </button>

                                    <!-- Modal Detail Catatan -->
                                    <div class="modal fade" id="modalDetail<?= $row['id']; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog text-start">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title fw-bold">Detail Pengajuan: <?= htmlspecialchars($row['nama_lengkap']); ?></h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <div class="modal-body p-4">
                                                    <p class="mb-2"><strong>NIM:</strong> <?= htmlspecialchars($row['nim']); ?></p>
                                                    <p class="mb-2"><strong>Judul Skripsi:</strong> <?= htmlspecialchars($row['judul_skripsi']); ?></p>
                                                    <p class="mb-2"><strong>Bab Pembahasan:</strong> <?= htmlspecialchars($row['bab_pembahasan']); ?></p>
                                                    <p class="mb-2"><strong>Jadwal:</strong> <?= date('d M Y', strtotime($row['tanggal_bimbingan'])); ?> pukul <?= substr($row['jam_bimbingan'], 0, 5); ?> WIB</p>
                                                    <p class="mb-2"><strong>Catatan Mahasiswa:</strong> <?= htmlspecialchars($row['catatan_mahasiswa'] ?? '-'); ?></p>
                                                    <div class="alert alert-secondary mt-3 mb-0 small">
                                                        <strong>Catatan Keputusan Dosen:</strong><br>
                                                        <?= !empty($row['catatan_dosen']) ? nl2br(htmlspecialchars($row['catatan_dosen'])) : '<em>Tidak ada catatan khusus.</em>'; ?>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
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
