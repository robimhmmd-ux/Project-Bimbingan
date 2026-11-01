<?php
// mahasiswa/upload_draft.php - Pengelolaan & Unggah Berkas Draf Skripsi (PDF / Google Drive)
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
    SELECT m.*, d.id AS dosen_id, d.nama_dosen, d.user_id AS dosen_user_id 
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

// Handle Upload Draf Skripsi Baru (PDF / Google Drive Link)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$dosen_id || $status_pembimbing !== 'aktif') {
        $error_msg = "Anda belum dapat mengunggah naskah karena SK Dosen Pembimbing belum aktif. Silakan lakukan konfirmasi SK di Dashboard terlebih dahulu.";
    } else {
        $metode_unggah  = trim($_POST['metode_unggah'] ?? 'file');
        $bab_pembahasan = trim($_POST['bab_pembahasan'] ?? '');
        $catatan_revisi = trim($_POST['catatan_revisi'] ?? '');
        $link_drive     = trim($_POST['link_drive'] ?? '');
        $tanggal_draft  = date('Y-m-d');
        $jam_draft      = date('H:i:s');
        $new_filename   = null;

        if (empty($bab_pembahasan)) {
            $error_msg = "Silakan tentukan Bab pembahasan skripsi yang diajukan.";
        } else {
            // Metode 1: Unggah Berkas PDF Lokal
            if ($metode_unggah === 'file') {
                if (!isset($_FILES['file_skripsi']) || $_FILES['file_skripsi']['error'] !== UPLOAD_ERR_OK) {
                    $error_msg = "File berkas PDF wajib dipilih dan harus berhasil diunggah.";
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
                        if (!move_uploaded_file($file_tmp, $upload_dir . $new_filename)) {
                            $error_msg = "Gagal menyimpan berkas ke server lokal.";
                        }
                    }
                }
            } 
            // Metode 2: Tautan Link Google Drive / Docs
            elseif ($metode_unggah === 'drive') {
                if (empty($link_drive)) {
                    $error_msg = "Silakan masukkan tautan link Google Drive / Google Docs naskah skripsi Anda.";
                } elseif (!filter_var($link_drive, FILTER_VALIDATE_URL)) {
                    $error_msg = "Format URL Google Drive tidak valid. Pastikan diawali dengan https://";
                }
            } else {
                $error_msg = "Metode penyerahan naskah tidak valid.";
            }

            // Jika validasi lolos, catat ke database
            if (empty($error_msg)) {
                try {
                    $stmt_insert = $pdo->prepare("
                        INSERT INTO pengajuan_bimbingan 
                        (mahasiswa_id, dosen_id, tanggal_bimbingan, jam_bimbingan, bab_pembahasan, catatan_mahasiswa, file_draft, link_drive, status) 
                        VALUES (:mid, :did, :tgl, :jam, :bab, :catatan, :file_draft, :link_drive, 'menunggu')
                    ");
                    $stmt_insert->execute([
                        ':mid'        => $mhs_id,
                        ':did'        => $dosen_id,
                        ':tgl'        => $tanggal_draft,
                        ':jam'        => $jam_draft,
                        ':bab'        => $bab_pembahasan,
                        ':catatan'    => $catatan_revisi,
                        ':file_draft' => $new_filename,
                        ':link_drive' => (!empty($link_drive) ? $link_drive : null)
                    ]);

                    // Tambahkan notifikasi ke dosen pembimbing jika user id dosen ada
                    if ($dosen_user_id) {
                        $nama_mhs = $mhs['nama_lengkap'] ?? 'Mahasiswa';
                        $stmt_n = $pdo->prepare("
                            INSERT INTO notifikasi (user_id, judul, pesan, tipe, link_tujuan) 
                            VALUES (:uid, 'Draf Naskah Baru Masuk', :pesan, 'info', 'persetujuan.php')
                        ");
                        $stmt_n->execute([
                            ':uid'   => $dosen_user_id,
                            ':pesan' => "Mahasiswa {$nama_mhs} telah mengunggah naskah evaluasi ({$bab_pembahasan})."
                        ]);
                    }

                    $success_msg = "Naskah skripsi ({$bab_pembahasan}) berhasil diserahkan! Berkas kini siap dievaluasi oleh dosen pembimbing.";
                } catch (PDOException $e) {
                    $error_msg = "Gagal mencatat naskah ke database: " . $e->getMessage();
                }
            }
        }
    }
}

// Ambil semua daftar file draf & tautan drive yang pernah diunggah oleh mahasiswa ini
$stmt_drafts = $pdo->prepare("
    SELECT id, tanggal_bimbingan, jam_bimbingan, bab_pembahasan, catatan_mahasiswa, file_draft, link_drive, status, catatan_dosen, created_at 
    FROM pengajuan_bimbingan 
    WHERE mahasiswa_id = :mid AND ((file_draft IS NOT NULL AND file_draft != '') OR (link_drive IS NOT NULL AND link_drive != ''))
    ORDER BY id DESC
");
$stmt_drafts->execute([':mid' => $mhs_id]);
$daftar_drafts = $stmt_drafts->fetchAll();
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
                    <i class="bi bi-file-earmark-arrow-up-fill me-1"></i>Evaluasi Naskah
                </span>
            </div>
            <h2 class="fw-bold text-white mb-1">Upload & Penyerahan Draf Skripsi</h2>
            <p class="text-white-50 small mb-0">
                Serahkan naskah bab skripsi via PDF atau tautan Google Drive untuk dievaluasi oleh dosen pembimbing.
            </p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-shrink-0 flex-wrap">
            <a href="logbook.php" class="btn btn-hero-action btn-hero-white">
                <i class="bi bi-journal-text"></i>Logbook
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
                <strong>Perhatian:</strong> SK Dosen Pembimbing Anda saat ini belum berstatus <strong>Aktif</strong>. Silakan kembali ke <a href="index.php" class="fw-bold text-dark text-decoration-underline">Dashboard Mahasiswa</a> untuk mengonfirmasi penerimaan SK sebelum dapat menyerahkan draf skripsi.
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <!-- Formulir Unggah Skripsi -->
    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white py-3 border-bottom">
                <h6 class="m-0 fw-bold text-dark">
                    <i class="bi bi-cloud-arrow-up text-primary me-2"></i>Form Penyerahan Naskah Baru
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
                        <label for="bab_pembahasan" class="form-label small fw-semibold text-secondary">Bab yang Diajukan <span class="text-danger">*</span></label>
                        <select class="form-select" id="bab_pembahasan" name="bab_pembahasan" required <?= $status_pembimbing !== 'aktif' ? 'disabled' : ''; ?>>
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

                    <!-- Pilihan Metode Penyerahan (UPDATE ROBI: PDF atau Google Drive) -->
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary d-block">Pilihan Metode Penyerahan <span class="text-danger">*</span></label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="metode_unggah" id="metode_file" value="file" checked onchange="toggleMetode()" <?= $status_pembimbing !== 'aktif' ? 'disabled' : ''; ?>>
                                <label class="form-check-label small fw-semibold" for="metode_file">
                                    <i class="bi bi-file-earmark-pdf text-danger me-1"></i>Unggah Berkas PDF
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="metode_unggah" id="metode_drive" value="drive" onchange="toggleMetode()" <?= $status_pembimbing !== 'aktif' ? 'disabled' : ''; ?>>
                                <label class="form-check-label small fw-semibold" for="metode_drive">
                                    <i class="bi bi-google text-primary me-1"></i>Link Google Drive / Docs
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Input Berkas PDF -->
                    <div class="mb-3" id="input_file_box">
                        <div class="upload-option-card">
                            <label for="file_skripsi" class="form-label small fw-semibold text-secondary mb-1">
                                <i class="bi bi-file-earmark-arrow-up text-danger me-1"></i>Pilih Berkas Naskah (PDF) <span class="text-danger">*</span>
                            </label>
                            <input class="form-control" type="file" id="file_skripsi" name="file_skripsi" accept=".pdf" <?= $status_pembimbing !== 'aktif' ? 'disabled' : ''; ?>>
                            <div class="form-text small mt-2" style="font-size: 0.75rem;">
                                <i class="bi bi-info-circle me-1"></i>Maksimal <strong>5 MB</strong> dalam format <strong>.pdf</strong>.
                            </div>
                        </div>
                    </div>

                    <!-- Input Tautan Google Drive (UPDATE ROBI) -->
                    <div class="mb-3 d-none" id="input_drive_box">
                        <div class="upload-option-card">
                            <label for="link_drive" class="form-label small fw-semibold text-secondary mb-1">
                                <i class="bi bi-cloud-check text-primary me-1"></i>Tautan Link Google Drive / Docs <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="bi bi-link-45deg"></i></span>
                                <input type="url" class="form-control" id="link_drive" name="link_drive" placeholder="https://docs.google.com/... atau https://drive.google.com/..." <?= $status_pembimbing !== 'aktif' ? 'disabled' : ''; ?>>
                            </div>
                            <div class="form-text small mt-2" style="font-size: 0.75rem;">
                                <i class="bi bi-shield-lock me-1"></i>Pastikan izin akses dokumen diatur ke <em>"Siapa saja yang memiliki link dapat melihat/mengomentari"</em>.
                            </div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="catatan_revisi" class="form-label small fw-semibold text-secondary">Catatan Perubahan / Versi Revisi</label>
                        <textarea class="form-control" id="catatan_revisi" name="catatan_revisi" rows="3" placeholder="Contoh: Revisi Bab 1 perbaikan latar belakang masalah sesuai arahan pembimbing..." <?= $status_pembimbing !== 'aktif' ? 'disabled' : ''; ?>></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold" <?= $status_pembimbing !== 'aktif' ? 'disabled' : ''; ?>>
                        <i class="bi bi-send-check me-1"></i>Serahkan Naskah Sekarang
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
                    <i class="bi bi-shield-check text-success me-2"></i>Panduan & Standar Penyerahan Naskah
                </h6>
            </div>
            <div class="card-body">
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="p-3 border rounded bg-light h-100">
                            <h6 class="fw-bold small text-dark"><i class="bi bi-filetype-pdf text-danger me-1"></i>Opsi Berkas PDF</h6>
                            <p class="small text-muted mb-0">Unggah file PDF ukuran maks 5 MB jika naskah telah final dan siap untuk direview langsung secara offline.</p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 border rounded bg-light h-100">
                            <h6 class="fw-bold small text-dark"><i class="bi bi-google text-primary me-1"></i>Opsi Google Drive / Docs</h6>
                            <p class="small text-muted mb-0">Gunakan link Google Docs agar dosen pembimbing dapat langsung memberikan catatan komentar (*inline comments*) pada naskah.</p>
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
            <i class="bi bi-folder-check text-primary me-2"></i>Daftar Dokumen Skripsi yang Pernah Diserahkan
        </h6>
        <span class="badge bg-primary-subtle text-primary border border-primary-subtle small">
            <?= count($daftar_drafts); ?> Berkas Tersimpan
        </span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-logbook table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 50px;">No</th>
                        <th style="width: 140px;">Waktu Unggah</th>
                        <th>Bab Pembahasan</th>
                        <th>Catatan Mahasiswa</th>
                        <th style="width: 130px;">Status Evaluasi</th>
                        <th>Catatan Dosen</th>
                        <th class="text-center" style="width: 140px;">Akses Berkas</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($daftar_drafts)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="bi bi-file-earmark-x fs-2 d-block mb-2"></i>
                                Belum ada berkas skripsi yang diserahkan. Silakan unggah naskah melalui formulir di atas.
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
                                    <div class="d-flex justify-content-center gap-1 flex-wrap">
                                        <?php if (!empty($draft['file_draft'])): ?>
                                            <a href="../assets/uploads/<?= htmlspecialchars($draft['file_draft']); ?>" target="_blank" class="badge-pdf" title="Lihat Berkas PDF">
                                                <i class="bi bi-file-earmark-pdf"></i>PDF
                                            </a>
                                        <?php endif; ?>
                                        <?php if (!empty($draft['link_drive'])): ?>
                                            <a href="<?= htmlspecialchars($draft['link_drive']); ?>" target="_blank" class="badge-drive" title="Buka Link Google Drive">
                                                <i class="bi bi-google"></i>Drive
                                            </a>
                                        <?php endif; ?>
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

<script>
function toggleMetode() {
    const isDrive = document.getElementById('metode_drive').checked;
    const boxFile = document.getElementById('input_file_box');
    const boxDrive = document.getElementById('input_drive_box');
    const inputFile = document.getElementById('file_skripsi');
    const inputDrive = document.getElementById('link_drive');

    if (isDrive) {
        boxFile.classList.add('d-none');
        boxDrive.classList.remove('d-none');
        inputFile.removeAttribute('required');
        inputDrive.setAttribute('required', 'required');
    } else {
        boxFile.classList.remove('d-none');
        boxDrive.classList.add('d-none');
        inputFile.setAttribute('required', 'required');
        inputDrive.removeAttribute('required');
    }
}
</script>

<?php
require_once '../templates/footer.php';
?>
