<?php
// mahasiswa/ajukan_jadwal.php - Form Reservasi Jadwal Bimbingan Skripsi
$page_title   = "Ajukan Jadwal Bimbingan";
$current_page = "ajukan";

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
    SELECT m.*, d.id AS dosen_id, d.user_id AS dosen_user_id, d.nama_dosen, d.email AS email_dosen, d.no_whatsapp, d.foto_profil AS foto_dosen 
    FROM mahasiswa m 
    LEFT JOIN dosen d ON m.dosen_pembimbing_id = d.id 
    WHERE m.user_id = :uid LIMIT 1
");
$stmt_mhs->execute([':uid' => $_SESSION['user_id']]);
$mhs = $stmt_mhs->fetch();

$mhs_id            = $mhs['id'] ?? 0;
$dosen_id          = $mhs['dosen_id'] ?? null;
$dosen_user_id     = $mhs['dosen_user_id'] ?? null;
$status_pembimbing = $mhs['status_pembimbing'] ?? 'belum_diplot';

$success_msg = '';
$error_msg   = '';

// Handle Pembatalan Pengajuan (Hanya jika status 'menunggu')
if (isset($_GET['action']) && $_GET['action'] === 'batal' && isset($_GET['id'])) {
    $cancel_id = (int)$_GET['id'];
    try {
        $stmt_cancel = $pdo->prepare("
            DELETE FROM pengajuan_bimbingan 
            WHERE id = :id AND mahasiswa_id = :mid AND status = 'menunggu'
        ");
        $stmt_cancel->execute([':id' => $cancel_id, ':mid' => $mhs_id]);
        if ($stmt_cancel->rowCount() > 0) {
            $success_msg = "Pengajuan bimbingan berhasil dibatalkan.";
        } else {
            $error_msg = "Gagal membatalkan pengajuan (mungkin sudah disetujui atau ditolak).";
        }
    } catch (PDOException $e) {
        $error_msg = "Kesalahan sistem: " . $e->getMessage();
    }
}

// Ambil jadwal ketersediaan dosen pembimbing
$jadwal_dosen = [];
if ($dosen_id) {
    $stmt_jd = $pdo->prepare("
        SELECT * FROM jadwal_dosen 
        WHERE dosen_id = :did AND status_aktif = 'aktif'
        ORDER BY FIELD(hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'), jam_mulai ASC
    ");
    $stmt_jd->execute([':did' => $dosen_id]);
    $jadwal_dosen = $stmt_jd->fetchAll();
}

// Handle Form Submit Pengajuan
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$dosen_id || $status_pembimbing !== 'aktif') {
        $error_msg = "Anda belum dapat mengajukan jadwal karena SK Dosen Pembimbing belum aktif. Silakan konfirmasi SK di Dashboard terlebih dahulu.";
    } else {
        $tanggal_bimbingan = trim($_POST['tanggal_bimbingan'] ?? '');
        $jam_bimbingan     = trim($_POST['jam_bimbingan'] ?? '');
        $bab_pembahasan    = trim($_POST['bab_pembahasan'] ?? '');
        $catatan_mhs       = trim($_POST['catatan_mahasiswa'] ?? '');
        $link_drive        = trim($_POST['link_drive'] ?? '');
        $file_draft_name   = null;

        // Validasi input wajib
        if (empty($tanggal_bimbingan) || empty($jam_bimbingan) || empty($bab_pembahasan)) {
            $error_msg = "Tanggal, Jam, dan Bab Pembahasan wajib diisi.";
        } elseif ($tanggal_bimbingan < date('Y-m-d')) {
            $error_msg = "Tanggal bimbingan tidak boleh di masa lalu.";
        } else {
            // Validasi format link Google Drive jika diisi
            if (!empty($link_drive) && !filter_var($link_drive, FILTER_VALIDATE_URL)) {
                $error_msg = "Format tautan link Google Drive tidak valid (harus diawali https://).";
            }

            // Validasi & Handle Upload File Draft (PDF only, max 5MB)
            if (empty($error_msg) && isset($_FILES['file_draft']) && $_FILES['file_draft']['error'] === UPLOAD_ERR_OK) {
                $file_tmp  = $_FILES['file_draft']['tmp_name'];
                $file_name = $_FILES['file_draft']['name'];
                $file_size = $_FILES['file_draft']['size'];
                $ext       = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                if ($ext !== 'pdf') {
                    $error_msg = "File draft skripsi harus berformat PDF (.pdf).";
                } elseif ($file_size > 5 * 1024 * 1024) {
                    $error_msg = "Ukuran file draft maksimal 5 MB.";
                } else {
                    $upload_dir = '../assets/uploads/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    $file_draft_name = 'draft_' . $mhs_id . '_' . uniqid() . '.pdf';
                    if (!move_uploaded_file($file_tmp, $upload_dir . $file_draft_name)) {
                        $error_msg = "Gagal mengunggah file draft skripsi ke server.";
                    }
                }
            }

            // Simpan ke database
            if (empty($error_msg)) {
                try {
                    $stmt_insert = $pdo->prepare("
                        INSERT INTO pengajuan_bimbingan 
                        (mahasiswa_id, dosen_id, tanggal_bimbingan, jam_bimbingan, bab_pembahasan, catatan_mahasiswa, file_draft, link_drive, status) 
                        VALUES (:mid, :did, :tgl, :jam, :bab, :catatan, :draft, :link_drive, 'menunggu')
                    ");
                    $stmt_insert->execute([
                        ':mid'        => $mhs_id,
                        ':did'        => $dosen_id,
                        ':tgl'        => $tanggal_bimbingan,
                        ':jam'        => $jam_bimbingan . ':00',
                        ':bab'        => $bab_pembahasan,
                        ':catatan'    => $catatan_mhs,
                        ':draft'      => $file_draft_name,
                        ':link_drive' => (!empty($link_drive) ? $link_drive : null)
                    ]);

                    // Kirim notifikasi ke dosen pembimbing
                    if ($dosen_user_id) {
                        $nama_mhs = $mhs['nama_lengkap'] ?? 'Mahasiswa';
                        $stmt_n = $pdo->prepare("
                            INSERT INTO notifikasi (user_id, judul, pesan, tipe, link_tujuan) 
                            VALUES (:uid, 'Pengajuan Bimbingan Baru', :pesan, 'info', 'persetujuan.php')
                        ");
                        $stmt_n->execute([
                            ':uid'   => $dosen_user_id,
                            ':pesan' => "Mahasiswa {$nama_mhs} mengajukan jadwal bimbingan pada tanggal {$tanggal_bimbingan} jam {$jam_bimbingan} WIB."
                        ]);
                    }

                    $success_msg = "Pengajuan jadwal bimbingan berhasil dikirim! Menunggu persetujuan dosen pembimbing.";
                } catch (PDOException $e) {
                    $error_msg = "Gagal mengajukan jadwal: " . $e->getMessage();
                }
            }
        }
    }
}

// Ambil riwayat pengajuan bimbingan milik mahasiswa
$stmt_history = $pdo->prepare("
    SELECT * FROM pengajuan_bimbingan 
    WHERE mahasiswa_id = :mid 
    ORDER BY id DESC
");
$stmt_history->execute([':mid' => $mhs_id]);
$riwayat_pengajuan = $stmt_history->fetchAll();

// Pre-fill jam jika ada GET parameter dari jadwal_dosen
$preset_jam = htmlspecialchars($_GET['jam'] ?? '');
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
                    <i class="bi bi-calendar-plus-fill me-1"></i>Reservasi Bimbingan
                </span>
            </div>
            <h2 class="fw-bold text-white mb-1">Ajukan Jadwal Bimbingan</h2>
            <p class="text-white-50 small mb-0">
                Reservasi sesi bimbingan tatap muka atau daring bersama dosen pembimbing secara terstruktur.
            </p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-shrink-0 flex-wrap">
            <a href="jadwal_dosen.php" class="btn btn-hero-action btn-hero-white">
                <i class="bi bi-calendar3"></i>Lihat Jadwal Dosen
            </a>
            <a href="chat.php" class="btn btn-hero-action btn-hero-emerald">
                <i class="bi bi-chat-dots-fill"></i>Chat Dosen
            </a>
            <a href="index.php" class="btn btn-hero-action btn-hero-outline">
                <i class="bi bi-speedometer2"></i>Dashboard
            </a>
        </div>
    </div>
</div>

<?php if (!empty($success_msg)): ?>
    <div class="alert alert-success alert-dismissible fade show py-2 small shadow-sm" role="alert">
        <i class="bi bi-check-circle-fill me-1"></i><?= htmlspecialchars($success_msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!empty($error_msg)): ?>
    <div class="alert alert-danger alert-dismissible fade show py-2 small shadow-sm" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-1"></i><?= htmlspecialchars($error_msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($status_pembimbing !== 'aktif'): ?>
    <div class="alert alert-warning shadow-sm mb-4" role="alert">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-exclamation-triangle-fill fs-5"></i>
            <div>
                <strong>Perhatian:</strong> Anda belum dapat mengajukan jadwal karena SK Dosen Pembimbing belum aktif. Silakan konfirmasi penerimaan SK di <a href="index.php" class="fw-bold text-dark text-decoration-underline">Dashboard</a> terlebih dahulu.
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (empty($dosen_id)): ?>
    <div class="card shadow-sm border-0 mb-4 bg-light text-center py-5">
        <div class="card-body">
            <i class="bi bi-person-x fs-1 text-warning mb-3 d-block"></i>
            <h5 class="fw-bold text-dark">Dosen Pembimbing Belum Ditetapkan</h5>
            <p class="text-muted small mb-3">Admin program studi belum memasangkan dosen pembimbing untuk Anda. Silakan hubungi bagian akademik.</p>
            <a href="index.php" class="btn btn-primary btn-sm">Kembali ke Dashboard</a>
        </div>
    </div>
<?php else: ?>

    <div class="row g-4 mb-4">
        <!-- Form Pengajuan Jadwal -->
        <div class="col-lg-7">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="m-0 fw-bold text-dark">
                        <i class="bi bi-calendar-event text-primary me-2"></i>Formulir Reservasi Jadwal
                    </h6>
                </div>
                <div class="card-body p-4">
                    <form action="ajukan_jadwal.php" method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label small fw-semibold text-secondary">Dosen Pembimbing</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-person-badge"></i></span>
                                <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($mhs['nama_dosen'] ?? ''); ?>" readonly>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="tanggal_bimbingan" class="form-label small fw-semibold text-secondary">Tanggal Bimbingan <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="tanggal_bimbingan" name="tanggal_bimbingan" min="<?= date('Y-m-d'); ?>" required <?= $status_pembimbing !== 'aktif' ? 'disabled' : ''; ?>>
                                <div class="form-text small" style="font-size: 0.75rem;">Pilih tanggal sesuai hari ketersediaan dosen.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="jam_bimbingan" class="form-label small fw-semibold text-secondary">Jam Bimbingan <span class="text-danger">*</span></label>
                                <input type="time" class="form-control" id="jam_bimbingan" name="jam_bimbingan" value="<?= $preset_jam; ?>" required <?= $status_pembimbing !== 'aktif' ? 'disabled' : ''; ?>>
                                <div class="form-text small" style="font-size: 0.75rem;">Format 24 jam (misal 09:30).</div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="bab_pembahasan" class="form-label small fw-semibold text-secondary">Bab / Topik Pembahasan <span class="text-danger">*</span></label>
                            <select class="form-select" id="bab_pembahasan" name="bab_pembahasan" required <?= $status_pembimbing !== 'aktif' ? 'disabled' : ''; ?>>
                                <option value="" selected disabled>-- Pilih Bab / Tahapan Bimbingan --</option>
                                <option value="Bab 1: Pendahuluan">Bab 1: Pendahuluan (Latar Belakang & Rumusan)</option>
                                <option value="Bab 2: Tinjauan Pustaka">Bab 2: Tinjauan Pustaka & Dasar Teori</option>
                                <option value="Bab 3: Metodologi Penelitian">Bab 3: Metodologi Penelitian & Desain Sistem</option>
                                <option value="Bab 4: Hasil dan Pembahasan">Bab 4: Hasil Pengujian & Pembahasan</option>
                                <option value="Bab 5: Kesimpulan dan Saran">Bab 5: Kesimpulan dan Rekomendasi</option>
                                <option value="Proposal Skripsi">Proposal Skripsi Lengkap</option>
                                <option value="Draf Skripsi Lengkap (Siap Sidang)">Draf Skripsi Lengkap (Pra-Sidang)</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="catatan_mahasiswa" class="form-label small fw-semibold text-secondary">Catatan / Rangkuman Pertanyaan untuk Dosen</label>
                            <textarea class="form-control" id="catatan_mahasiswa" name="catatan_mahasiswa" rows="3" placeholder="Tuliskan poin-poin yang sudah Anda kerjakan dan apa yang ingin dikonsultasikan..." <?= $status_pembimbing !== 'aktif' ? 'disabled' : ''; ?>></textarea>
                        </div>

                        <!-- Opsi Lampiran: Berkas PDF atau Tautan Google Drive (UPDATE ROBI) -->
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="file_draft" class="form-label small fw-semibold text-secondary">
                                    <i class="bi bi-file-earmark-pdf text-danger me-1"></i>Lampirkan Berkas PDF (Opsional)
                                </label>
                                <input class="form-control form-control-sm" type="file" id="file_draft" name="file_draft" accept=".pdf" <?= $status_pembimbing !== 'aktif' ? 'disabled' : ''; ?>>
                                <div class="form-text small" style="font-size: 0.75rem;">Maksimal 5 MB.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="link_drive" class="form-label small fw-semibold text-secondary">
                                    <i class="bi bi-google text-primary me-1"></i>Link Google Drive / Docs (Opsional)
                                </label>
                                <input type="url" class="form-control form-control-sm" id="link_drive" name="link_drive" placeholder="https://docs.google.com/..." <?= $status_pembimbing !== 'aktif' ? 'disabled' : ''; ?>>
                                <div class="form-text small" style="font-size: 0.75rem;">Pastikan akses link dapat dibuka.</div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold" <?= $status_pembimbing !== 'aktif' ? 'disabled' : ''; ?>>
                            <i class="bi bi-send-check me-1"></i>Kirim Pengajuan Jadwal
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Panel Informasi Jadwal Aktif Dosen -->
        <div class="col-lg-5">
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="m-0 fw-bold text-dark">
                        <i class="bi bi-clock-history text-success me-2"></i>Jadwal Aktif Dosen Pembimbing
                    </h6>
                </div>
                <div class="card-body p-3">
                    <p class="small text-muted mb-2">
                        Berikut slot ketersediaan <strong><?= htmlspecialchars($mhs['nama_dosen']); ?></strong>:
                    </p>

                    <?php if (empty($jadwal_dosen)): ?>
                        <div class="alert alert-warning small mb-0 py-2">
                            <i class="bi bi-info-circle me-1"></i>Belum ada jadwal tetap dari dosen. Anda dapat menghubungi via <a href="chat.php">Chat</a> untuk konfirmasi waktu senggang beliau.
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush small">
                            <?php foreach ($jadwal_dosen as $jd): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2">
                                    <div>
                                        <span class="badge bg-primary me-2"><?= htmlspecialchars($jd['hari']); ?></span>
                                        <span class="fw-semibold text-dark"><?= substr($jd['jam_mulai'], 0, 5); ?> - <?= substr($jd['jam_selesai'], 0, 5); ?> WIB</span>
                                    </div>
                                    <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2" onclick="setPresetJam('<?= substr($jd['jam_mulai'], 0, 5); ?>')">
                                        Pilih Jam
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="m-0 fw-bold text-dark">
                        <i class="bi bi-lightbulb text-warning me-2"></i>Tips Sesi Bimbingan Efektif
                    </h6>
                </div>
                <div class="card-body small text-muted">
                    <ul class="ps-3 mb-0">
                        <li class="mb-2">Pastikan membaca kembali revisi sebelumnya agar konsultasi berjalan efisien.</li>
                        <li class="mb-2">Sertakan berkas PDF atau tautan Google Docs terbaru.</li>
                        <li>Konfirmasi kehadiran tepat waktu sesuai waktu yang telah disepakati bersama.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Riwayat Pengajuan Bimbingan Mahasiswa -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
            <h6 class="m-0 fw-bold text-dark">
                <i class="bi bi-clock-history text-primary me-2"></i>Riwayat Pengajuan Jadwal Bimbingan
            </h6>
            <span class="badge bg-secondary"><?= count($riwayat_pengajuan); ?> Pengajuan</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-logbook table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 50px;">No</th>
                            <th style="width: 140px;">Jadwal Bimbingan</th>
                            <th>Bab Pembahasan</th>
                            <th>Berkas & Catatan</th>
                            <th style="width: 120px;">Status</th>
                            <th>Respon Dosen</th>
                            <th class="text-center" style="width: 90px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($riwayat_pengajuan)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="bi bi-calendar-x fs-2 d-block mb-1"></i>
                                    Belum ada pengajuan jadwal bimbingan.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $no = 1; foreach ($riwayat_pengajuan as $row): ?>
                                <tr>
                                    <td><?= $no++; ?></td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= date('d F Y', strtotime($row['tanggal_bimbingan'])); ?></div>
                                        <small class="text-muted"><i class="bi bi-clock me-1"></i><?= substr($row['jam_bimbingan'], 0, 5); ?> WIB</small>
                                    </td>
                                    <td>
                                        <span class="fw-semibold text-dark"><?= htmlspecialchars($row['bab_pembahasan']); ?></span>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-column gap-1">
                                            <?php if (!empty($row['catatan_mahasiswa'])): ?>
                                                <small class="text-muted text-truncate" style="max-width: 200px;">
                                                    <?= htmlspecialchars($row['catatan_mahasiswa']); ?>
                                                </small>
                                            <?php endif; ?>
                                            <div class="d-flex gap-1 mt-1 flex-wrap">
                                                <?php if (!empty($row['file_draft'])): ?>
                                                    <a href="../assets/uploads/<?= htmlspecialchars($row['file_draft']); ?>" target="_blank" class="badge-pdf">
                                                        <i class="bi bi-file-earmark-pdf"></i>PDF
                                                    </a>
                                                <?php endif; ?>
                                                <?php if (!empty($row['link_drive'])): ?>
                                                    <a href="<?= htmlspecialchars($row['link_drive']); ?>" target="_blank" class="badge-drive">
                                                        <i class="bi bi-google"></i>Drive
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?= $row['status']; ?>">
                                            <?= ucfirst($row['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= htmlspecialchars($row['catatan_dosen'] ?? 'Belum ada tanggapan'); ?></small>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($row['status'] === 'menunggu'): ?>
                                            <a href="ajukan_jadwal.php?action=batal&id=<?= $row['id']; ?>" class="btn btn-outline-danger btn-sm" onclick="return confirm('Apakah Anda yakin ingin membatalkan pengajuan bimbingan ini?')" title="Batalkan Pengajuan">
                                                <i class="bi bi-x-circle me-1"></i>Batal
                                            </a>
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

<?php endif; ?>

<script>
function setPresetJam(jam) {
    const jamInput = document.getElementById('jam_bimbingan');
    if (jamInput) {
        jamInput.value = jam;
        jamInput.focus();
    }
}
</script>

<?php
require_once '../templates/footer.php';
?>
