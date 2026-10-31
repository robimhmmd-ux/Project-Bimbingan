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
    SELECT m.*, d.id AS dosen_id, d.nama_dosen, d.email AS email_dosen, d.no_whatsapp, d.foto_profil AS foto_dosen 
    FROM mahasiswa m 
    LEFT JOIN dosen d ON m.dosen_pembimbing_id = d.id 
    WHERE m.user_id = :uid LIMIT 1
");
$stmt_mhs->execute([':uid' => $_SESSION['user_id']]);
$mhs = $stmt_mhs->fetch();

$mhs_id   = $mhs['id'] ?? 0;
$dosen_id = $mhs['dosen_id'] ?? null;

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
    if (!$dosen_id) {
        $error_msg = "Anda belum memiliki dosen pembimbing. Silakan hubungi admin terlebih dahulu.";
    } else {
        $tanggal_bimbingan = trim($_POST['tanggal_bimbingan'] ?? '');
        $jam_bimbingan     = trim($_POST['jam_bimbingan'] ?? '');
        $bab_pembahasan    = trim($_POST['bab_pembahasan'] ?? '');
        $catatan_mhs       = trim($_POST['catatan_mahasiswa'] ?? '');
        $file_draft_name   = null;

        // Validasi input wajib
        if (empty($tanggal_bimbingan) || empty($jam_bimbingan) || empty($bab_pembahasan)) {
            $error_msg = "Tanggal, Jam, dan Bab Pembahasan wajib diisi.";
        } elseif ($tanggal_bimbingan < date('Y-m-d')) {
            $error_msg = "Tanggal bimbingan tidak boleh di masa lalu.";
        } else {
            // Validasi hari dalam bahasa Indonesia
            $hari_indonesia = [
                'Sunday'    => 'Minggu',
                'Monday'    => 'Senin',
                'Tuesday'   => 'Selasa',
                'Wednesday' => 'Rabu',
                'Thursday'  => 'Kamis',
                'Friday'    => 'Jumat',
                'Saturday'  => 'Sabtu'
            ];
            $timestamp = strtotime($tanggal_bimbingan);
            $nama_hari_en = date('l', $timestamp);
            $nama_hari_id = $hari_indonesia[$nama_hari_en] ?? '';

            // Cek anti bentrok: apakah sudah ada bimbingan dosen di tanggal dan jam yang sama dengan status disetujui
            $stmt_bentrok = $pdo->prepare("
                SELECT COUNT(*) FROM pengajuan_bimbingan 
                WHERE dosen_id = :did AND tanggal_bimbingan = :tgl AND jam_bimbingan = :jam AND status = 'disetujui'
            ");
            $stmt_bentrok->execute([
                ':did' => $dosen_id,
                ':tgl' => $tanggal_bimbingan,
                ':jam' => $jam_bimbingan . ':00'
            ]);
            $is_bentrok = (int)$stmt_bentrok->fetchColumn();

            if ($is_bentrok > 0) {
                $error_msg = "Jadwal pada tanggal dan jam tersebut sudah terisi oleh mahasiswa lain. Silakan pilih waktu lain.";
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
                        (mahasiswa_id, dosen_id, tanggal_bimbingan, jam_bimbingan, bab_pembahasan, catatan_mahasiswa, file_draft, status) 
                        VALUES (:mid, :did, :tgl, :jam, :bab, :catatan, :draft, 'menunggu')
                    ");
                    $stmt_insert->execute([
                        ':mid'     => $mhs_id,
                        ':did'     => $dosen_id,
                        ':tgl'     => $tanggal_bimbingan,
                        ':jam'     => $jam_bimbingan . ':00',
                        ':bab'     => $bab_pembahasan,
                        ':catatan' => $catatan_mhs,
                        ':draft'   => $file_draft_name
                    ]);

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

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2 mb-0">Ajukan Jadwal Bimbingan</h1>
        <p class="text-muted small mb-0">Reservasi jadwal bimbingan dengan dosen pembimbing Anda secara online dan terstruktur.</p>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="jadwal_dosen.php" class="btn btn-outline-secondary btn-sm me-2">
            <i class="bi bi-calendar3 me-1"></i>Lihat Jadwal Dosen
        </a>
        <a href="chat.php" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-chat-dots-fill me-1"></i>Chat Dosen
        </a>
    </div>
</div>

<?php if (!empty($success_msg)): ?>
    <div class="alert alert-success alert-dismissible fade show py-2 small" role="alert">
        <i class="bi bi-check-circle-fill me-1"></i><?= htmlspecialchars($success_msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!empty($error_msg)): ?>
    <div class="alert alert-danger alert-dismissible fade show py-2 small" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-1"></i><?= htmlspecialchars($error_msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!$dosen_id): ?>
    <div class="card shadow-sm border-warning border-start border-4 p-4 text-center my-4">
        <i class="bi bi-exclamation-circle text-warning fs-1 mb-2"></i>
        <h5 class="fw-bold">Belum Ada Dosen Pembimbing</h5>
        <p class="text-muted mb-3">Anda belum dapat mengajukan jadwal bimbingan karena belum memiliki dosen pembimbing yang ditugaskan oleh admin.</p>
        <a href="index.php" class="btn btn-outline-primary btn-sm">Kembali ke Dashboard</a>
    </div>
<?php else: ?>

    <div class="row g-4 mb-4">
        <!-- Form Pengajuan -->
        <div class="col-lg-7">
            <div class="card shadow-sm">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="m-0 fw-bold text-dark">
                        <i class="bi bi-pencil-square text-primary me-2"></i>Formulir Reservasi Bimbingan
                    </h6>
                </div>
                <div class="card-body p-4">
                    <form action="ajukan_jadwal.php" method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label small fw-semibold text-secondary">Dosen Pembimbing</label>
                            <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($mhs['nama_dosen']); ?>" readonly>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="tanggal_bimbingan" class="form-label small fw-semibold text-secondary">Tanggal Bimbingan <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="tanggal_bimbingan" name="tanggal_bimbingan" min="<?= date('Y-m-d'); ?>" required>
                                <div class="form-text small" style="font-size: 0.75rem;">Pilih tanggal sesuai hari aktif dosen.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="jam_bimbingan" class="form-label small fw-semibold text-secondary">Jam Bimbingan <span class="text-danger">*</span></label>
                                <input type="time" class="form-control" id="jam_bimbingan" name="jam_bimbingan" value="<?= $preset_jam; ?>" required>
                                <div class="form-text small" style="font-size: 0.75rem;">Format 24 jam (misal 09:30).</div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="bab_pembahasan" class="form-label small fw-semibold text-secondary">Bab / Topik Pembahasan <span class="text-danger">*</span></label>
                            <select class="form-select" id="bab_pembahasan" name="bab_pembahasan" required>
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
                            <textarea class="form-control" id="catatan_mahasiswa" name="catatan_mahasiswa" rows="3" placeholder="Tuliskan poin-poin yang sudah Anda kerjakan dan apa yang ingin dikonsultasikan..."></textarea>
                        </div>

                        <div class="mb-4">
                            <label for="file_draft" class="form-label small fw-semibold text-secondary">
                                Upload File Draf Skripsi (PDF, Maks 5MB)
                            </label>
                            <input class="form-control" type="file" id="file_draft" name="file_draft" accept=".pdf">
                            <div class="form-text small" style="font-size: 0.75rem;">
                                Opsional namun sangat dianjurkan agar dosen dapat membaca draf Anda sebelum sesi bimbingan.
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
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

            <!-- Kartu Petunjuk Prosedur -->
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="m-0 fw-bold text-dark">
                        <i class="bi bi-shield-check text-primary me-2"></i>Alur Persetujuan
                    </h6>
                </div>
                <div class="card-body small text-muted">
                    <div class="d-flex mb-2">
                        <div class="me-2 text-primary fw-bold">1.</div>
                        <div>Pengajuan berstatus <span class="badge bg-warning text-dark">Menunggu</span> setelah dikirim.</div>
                    </div>
                    <div class="d-flex mb-2">
                        <div class="me-2 text-primary fw-bold">2.</div>
                        <div>Dosen pembimbing akan menyetujui, menjadwalkan ulang, atau memberi catatan.</div>
                    </div>
                    <div class="d-flex mb-2">
                        <div class="me-2 text-primary fw-bold">3.</div>
                        <div>Setelah disetujui (<span class="badge bg-success">Disetujui</span>), datang bimbingan tepat waktu sesuai jadwal.</div>
                    </div>
                    <div class="d-flex">
                        <div class="me-2 text-primary fw-bold">4.</div>
                        <div>Hasil evaluasi dan nilai progres akan tercatat di logbook setelah bimbingan selesai.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabel Riwayat Pengajuan Bimbingan -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h6 class="m-0 fw-bold text-dark">
                <i class="bi bi-list-check text-primary me-2"></i>Daftar Riwayat Pengajuan Bimbingan Anda
            </h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>No</th>
                            <th>Tanggal & Waktu</th>
                            <th>Bab Pembahasan</th>
                            <th>Draf Berkas</th>
                            <th>Status Pengajuan</th>
                            <th>Catatan Dosen</th>
                            <th class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($riwayat_pengajuan)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">
                                    <i class="bi bi-inbox fs-2 d-block mb-1"></i>
                                    Belum ada pengajuan bimbingan yang dikirim.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $no = 1; foreach ($riwayat_pengajuan as $row): ?>
                                <tr>
                                    <td><?= $no++; ?></td>
                                    <td>
                                        <strong><?= date('d M Y', strtotime($row['tanggal_bimbingan'])); ?></strong><br>
                                        <small class="text-muted"><i class="bi bi-clock me-1"></i><?= substr($row['jam_bimbingan'], 0, 5); ?> WIB</small>
                                    </td>
                                    <td>
                                        <span class="fw-semibold text-dark"><?= htmlspecialchars($row['bab_pembahasan']); ?></span>
                                        <?php if (!empty($row['catatan_mahasiswa'])): ?>
                                            <p class="text-muted small mb-0 text-truncate" style="max-width: 200px;" title="<?= htmlspecialchars($row['catatan_mahasiswa']); ?>">
                                                <?= htmlspecialchars($row['catatan_mahasiswa']); ?>
                                            </p>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['file_draft'])): ?>
                                            <a href="../assets/uploads/<?= htmlspecialchars($row['file_draft']); ?>" target="_blank" class="btn btn-outline-secondary btn-sm py-0 px-2">
                                                <i class="bi bi-file-earmark-pdf text-danger me-1"></i>Lihat PDF
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small">Tidak ada</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $st = $row['status'];
                                        $badge_bg = 'secondary';
                                        if ($st === 'menunggu') $badge_bg = 'warning text-dark';
                                        elseif ($st === 'disetujui') $badge_bg = 'success';
                                        elseif ($st === 'ditolak') $badge_bg = 'danger';
                                        elseif ($st === 'revisi') $badge_bg = 'info text-dark';
                                        elseif ($st === 'selesai') $badge_bg = 'primary';
                                        ?>
                                        <span class="badge bg-<?= $badge_bg; ?>"><?= ucfirst($st); ?></span>
                                    </td>
                                    <td>
                                        <span class="small text-muted"><?= htmlspecialchars($row['catatan_dosen'] ?? '-'); ?></span>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($row['status'] === 'menunggu'): ?>
                                            <a href="ajukan_jadwal.php?action=batal&id=<?= $row['id']; ?>" class="btn btn-outline-danger btn-sm py-0 px-2" onclick="return confirm('Yakin ingin membatalkan pengajuan jadwal ini?')">
                                                <i class="bi bi-x-circle me-1"></i>Batalkan
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

    <script>
    function setPresetJam(jam) {
        document.getElementById('jam_bimbingan').value = jam;
    }
    </script>

<?php endif; ?>

<?php
require_once '../templates/footer.php';
?>

