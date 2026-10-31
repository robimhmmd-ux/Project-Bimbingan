<?php
// mahasiswa/upload_draft.php - Pengelolaan & Unggah Berkas Draf Skripsi (PDF)
$page_title   = "Upload Draf Skripsi";
$current_page = "upload";

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
    SELECT m.*, d.id AS dosen_id, d.nama_dosen 
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

// Handle Upload Draf Skripsi Baru
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$dosen_id) {
        $error_msg = "Anda belum memiliki dosen pembimbing. Silakan hubungi admin terlebih dahulu.";
    } else {
        $bab_pembahasan = trim($_POST['bab_pembahasan'] ?? '');
        $catatan_revisi = trim($_POST['catatan_revisi'] ?? '');
        $tanggal_draft  = date('Y-m-d');
        $jam_draft      = date('H:i:s');

        if (empty($bab_pembahasan)) {
            $error_msg = "Silakan tentukan Bab pembahasan skripsi yang diunggah.";
        } elseif (!isset($_FILES['file_skripsi']) || $_FILES['file_skripsi']['error'] !== UPLOAD_ERR_OK) {
            $error_msg = "File draf skripsi wajib dipilih dan harus berhasil diunggah.";
        } else {
            $file_tmp  = $_FILES['file_skripsi']['tmp_name'];
            $file_name = $_FILES['file_skripsi']['name'];
            $file_size = $_FILES['file_skripsi']['size'];
            $ext       = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            if ($ext !== 'pdf') {
                $error_msg = "Format berkas tidak valid. Hanya berkas PDF (.pdf) yang diperbolehkan.";
            } elseif ($file_size > 5 * 1024 * 1024) {
                $error_msg = "Ukuran berkas skripsi maksimal adalah 5 MB.";
            } else {
                $upload_dir = '../assets/uploads/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }

                $new_filename = 'skripsi_' . $mhs_id . '_' . uniqid() . '.pdf';

                if (move_uploaded_file($file_tmp, $upload_dir . $new_filename)) {
                    try {
                        // Masukkan ke pengajuan_bimbingan sebagai draft masuk
                        $stmt_insert = $pdo->prepare("
                            INSERT INTO pengajuan_bimbingan 
                            (mahasiswa_id, dosen_id, tanggal_bimbingan, jam_bimbingan, bab_pembahasan, catatan_mahasiswa, file_draft, status) 
                            VALUES (:mid, :did, :tgl, :jam, :bab, :catatan, :file_draft, 'menunggu')
                        ");
                        $stmt_insert->execute([
                            ':mid'        => $mhs_id,
                            ':did'        => $dosen_id,
                            ':tgl'        => $tanggal_draft,
                            ':jam'        => $jam_draft,
                            ':bab'        => $bab_pembahasan,
                            ':catatan'    => $catatan_revisi,
                            ':file_draft' => $new_filename
                        ]);

                        $success_msg = "Draf skripsi ({$bab_pembahasan}) berhasil diunggah! Berkas telah tersedia untuk diperiksa dosen pembimbing.";
                    } catch (PDOException $e) {
                        $error_msg = "Gagal mencatat unggahan draf ke database: " . $e->getMessage();
                    }
                } else {
                    $error_msg = "Gagal menyimpan berkas ke server. Pastikan folder uploads dapat ditulisi.";
                }
            }
        }
    }
}

// Ambil semua daftar file draf yang pernah diunggah oleh mahasiswa ini
$stmt_drafts = $pdo->prepare("
    SELECT id, tanggal_bimbingan, jam_bimbingan, bab_pembahasan, catatan_mahasiswa, file_draft, status, catatan_dosen, created_at 
    FROM pengajuan_bimbingan 
    WHERE mahasiswa_id = :mid AND file_draft IS NOT NULL AND file_draft != ''
    ORDER BY id DESC
");
$stmt_drafts->execute([':mid' => $mhs_id]);
$daftar_drafts = $stmt_drafts->fetchAll();
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2 mb-0">Upload Berkas Draf Skripsi</h1>
        <p class="text-muted small mb-0">Unggah naskah draf skripsi dalam format PDF untuk dievaluasi oleh dosen pembimbing.</p>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="logbook.php" class="btn btn-outline-secondary btn-sm me-2">
            <i class="bi bi-journal-text me-1"></i>Lihat Logbook
        </a>
        <a href="chat.php" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-chat-dots me-1"></i>Chat Dosen
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

<div class="row g-4 mb-4">
    <!-- Formulir Unggah Skripsi -->
    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white py-3 border-bottom">
                <h6 class="m-0 fw-bold text-dark">
                    <i class="bi bi-cloud-arrow-up text-primary me-2"></i>Form Unggah Berkas Baru
                </h6>
            </div>
            <div class="card-body p-4">
                <form action="upload_draft.php" method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Judul Skripsi Terdaftar</label>
                        <p class="fw-bold text-dark small mb-0 bg-light p-2 rounded border">
                            <?= htmlspecialchars($mhs['judul_skripsi'] ?? 'Judul Belum Diatur'); ?>
                        </p>
                    </div>

                    <div class="mb-3">
                        <label for="bab_pembahasan" class="form-label small fw-semibold text-secondary">Bab yang Diunggah <span class="text-danger">*</span></label>
                        <select class="form-select" id="bab_pembahasan" name="bab_pembahasan" required>
                            <option value="" selected disabled>-- Pilih Bagian Skripsi --</option>
                            <option value="Bab 1: Pendahuluan">Bab 1: Pendahuluan</option>
                            <option value="Bab 2: Tinjauan Pustaka">Bab 2: Tinjauan Pustaka</option>
                            <option value="Bab 3: Metodologi Penelitian">Bab 3: Metodologi Penelitian</option>
                            <option value="Bab 4: Hasil dan Pembahasan">Bab 4: Hasil & Pembahasan</option>
                            <option value="Bab 5: Kesimpulan dan Saran">Bab 5: Kesimpulan & Saran</option>
                            <option value="Proposal Skripsi Lengkap">Proposal Skripsi Lengkap (Bab 1-3)</option>
                            <option value="Naskah Skripsi Lengkap">Naskah Skripsi Lengkap (Bab 1-5)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="catatan_revisi" class="form-label small fw-semibold text-secondary">Catatan Perubahan / Versi Revisi</label>
                        <textarea class="form-control" id="catatan_revisi" name="catatan_revisi" rows="3" placeholder="Contoh: Revisi Bab 1 perbaikan latar belakang masalah sesuai arahan pembimbing..."></textarea>
                    </div>

                    <div class="mb-4">
                        <label for="file_skripsi" class="form-label small fw-semibold text-secondary">Pilih Berkas Skripsi (PDF) <span class="text-danger">*</span></label>
                        <input class="form-control" type="file" id="file_skripsi" name="file_skripsi" accept=".pdf" required>
                        <div class="form-text small" style="font-size: 0.75rem;">
                            <i class="bi bi-info-circle me-1"></i>Hanya menerima file berekstensi <strong>.pdf</strong> dengan ukuran maksimal <strong>5 MB</strong>.
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
                        <i class="bi bi-upload me-1"></i>Unggah Sekarang
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Informasi & Ketentuan Berkas -->
    <div class="col-lg-7">
        <div class="card shadow-sm h-100 border-0">
            <div class="card-header bg-white py-3 border-bottom">
                <h6 class="m-0 fw-bold text-dark">
                    <i class="bi bi-shield-check text-success me-2"></i>Standar Format Dokumen Skripsi
                </h6>
            </div>
            <div class="card-body">
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="p-3 border rounded bg-light">
                            <h6 class="fw-bold small text-dark"><i class="bi bi-filetype-pdf text-danger me-1"></i>Format Dokumen</h6>
                            <p class="small text-muted mb-0">Pastikan dokumen telah dikonversi ke format <strong>PDF</strong> standar tanpa proteksi kata sandi.</p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 border rounded bg-light">
                            <h6 class="fw-bold small text-dark"><i class="bi bi-hdd-network text-primary me-1"></i>Batas Ukuran</h6>
                            <p class="small text-muted mb-0">Ukuran maksimal per unggahan adalah <strong>5 MB</strong>. Kompresi gambar dalam dokumen bila melebihi kuota.</p>
                        </div>
                    </div>
                </div>

                <h6 class="fw-bold small text-dark mb-2">Struktur Sistematika Penulisan:</h6>
                <ul class="list-group list-group-numbered small mb-3">
                    <li class="list-group-item d-flex justify-content-between align-items-start">
                        <div class="ms-2 me-auto">
                            <div class="fw-bold">Bab 1: Pendahuluan</div>
                            Latar belakang, rumusan masalah, batasan, tujuan, dan manfaat penelitian.
                        </div>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-start">
                        <div class="ms-2 me-auto">
                            <div class="fw-bold">Bab 2: Tinjauan Pustaka</div>
                            Kajian teori dasar, penelitian terdahulu yang relevan, dan kerangka pemikiran.
                        </div>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-start">
                        <div class="ms-2 me-auto">
                            <div class="fw-bold">Bab 3: Metodologi Penelitian</div>
                            Analisis kebutuhan, perancangan sistem/arsitektur, dan prosedur pengujian.
                        </div>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-start">
                        <div class="ms-2 me-auto">
                            <div class="fw-bold">Bab 4: Hasil & Pembahasan</div>
                            Implementasi aplikasi/analisis data, hasil pengujian, dan pembahasan komprehensif.
                        </div>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-start">
                        <div class="ms-2 me-auto">
                            <div class="fw-bold">Bab 5: Penutup</div>
                            Kesimpulan hasil penelitian serta saran pengembangan sistem berikutnya.
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Tabel Riwayat Dokumen yang Diunggah -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="m-0 fw-bold text-dark">
            <i class="bi bi-folder-check text-primary me-2"></i>Daftar Dokumen Skripsi yang Pernah Diunggah
        </h6>
        <span class="badge bg-primary-subtle text-primary border border-primary-subtle small">
            <?= count($daftar_drafts); ?> Berkas Tersimpan
        </span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No</th>
                        <th>Waktu Unggah</th>
                        <th>Bab Pembahasan</th>
                        <th>Catatan Mahasiswa</th>
                        <th>Status Evaluasi</th>
                        <th>Catatan Dosen</th>
                        <th class="text-center">Berkas PDF</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($daftar_drafts)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">
                                <i class="bi bi-file-earmark-x fs-2 d-block mb-1"></i>
                                Belum ada berkas skripsi yang diunggah. Silakan unggah berkas melalui form di atas.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $no = 1; foreach ($daftar_drafts as $draft): ?>
                            <tr>
                                <td><?= $no++; ?></td>
                                <td>
                                    <strong><?= date('d M Y', strtotime($draft['tanggal_bimbingan'])); ?></strong><br>
                                    <small class="text-muted"><?= substr($draft['jam_bimbingan'], 0, 5); ?> WIB</small>
                                </td>
                                <td>
                                    <span class="fw-bold text-dark"><?= htmlspecialchars($draft['bab_pembahasan']); ?></span>
                                </td>
                                <td>
                                    <span class="small text-muted"><?= htmlspecialchars($draft['catatan_mahasiswa'] ?? '-'); ?></span>
                                </td>
                                <td>
                                    <?php
                                    $st = $draft['status'];
                                    $badge_class = 'secondary';
                                    if ($st === 'menunggu') $badge_class = 'warning text-dark';
                                    elseif ($st === 'disetujui') $badge_class = 'success';
                                    elseif ($st === 'ditolak') $badge_class = 'danger';
                                    elseif ($st === 'revisi') $badge_class = 'info text-dark';
                                    elseif ($st === 'selesai') $badge_class = 'primary';
                                    ?>
                                    <span class="badge bg-<?= $badge_class; ?>"><?= ucfirst($st); ?></span>
                                </td>
                                <td>
                                    <span class="small text-muted"><?= htmlspecialchars($draft['catatan_dosen'] ?? 'Belum ada catatan'); ?></span>
                                </td>
                                <td class="text-center">
                                    <a href="../assets/uploads/<?= htmlspecialchars($draft['file_draft']); ?>" target="_blank" class="btn btn-outline-danger btn-sm">
                                        <i class="bi bi-file-earmark-pdf me-1"></i>Buka PDF
                                    </a>
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

