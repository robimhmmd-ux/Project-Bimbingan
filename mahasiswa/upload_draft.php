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

// Dapatkan status progres & pengesahan judul skripsi dari helper
$p_info = get_progres_mahasiswa($pdo, $mhs_id);
$is_judul_acc     = $p_info ? $p_info['is_judul_acc'] : false;
$pengajuan_judul  = $p_info ? $p_info['pengajuan_judul'] : null;
$status_pj        = $pengajuan_judul['status'] ?? null;
$judul_terdaftar  = trim($mhs['judul_skripsi'] ?? '');
$has_judul_text   = (!empty($judul_terdaftar) && !in_array(strtolower($judul_terdaftar), ['belum ditentukan', 'judul belum diatur', 'judul belum ditentukan', '-', 'null']));
$has_judul        = $is_judul_acc; // Tahap 2 HANYA terbuka jika judul telah di-ACC resmi oleh dosen

// Parameter auto-select bab saat diklik dari dashboard progres
$param_bab = strtolower(trim($_GET['bab'] ?? ''));
$map_bab_options = [
    '1'      => 'Bab 1: Pendahuluan',
    'bab1'   => 'Bab 1: Pendahuluan',
    '2'      => 'Bab 2: Tinjauan Pustaka',
    'bab2'   => 'Bab 2: Tinjauan Pustaka',
    '3'      => 'Bab 3: Metodologi Penelitian',
    'bab3'   => 'Bab 3: Metodologi Penelitian',
    '4'      => 'Bab 4: Hasil dan Pembahasan',
    'bab4'   => 'Bab 4: Hasil dan Pembahasan',
    '5'      => 'Bab 5: Kesimpulan dan Saran',
    'bab5'   => 'Bab 5: Kesimpulan dan Saran',
    'sidang' => 'Naskah Skripsi Lengkap',
];
$auto_select_bab = $map_bab_options[$param_bab] ?? '';

// ============================================================
// 1. HANDLE PENGAJUAN / PEMBARUAN JUDUL SKRIPSI (TAHAP 1)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_ajukan_judul'])) {
    if (!$dosen_id || $status_pembimbing !== 'aktif') {
        $error_msg = "Anda belum dapat mengajukan judul karena SK Dosen Pembimbing belum aktif. Silakan lakukan konfirmasi SK di Dashboard terlebih dahulu.";
    } else {
        $new_judul     = trim($_POST['judul_skripsi'] ?? '');
        $catatan_judul = trim($_POST['catatan_judul'] ?? '');
        $metode_unggah = trim($_POST['metode_unggah_judul'] ?? 'none');
        $link_drive    = trim($_POST['link_drive_judul'] ?? '');
        $new_filename  = null;
        $file_draft_data = null;

        // Wajib melampirkan berkas dokumen proposal / usulan judul
        $ada_file_upload = (isset($_FILES['file_judul']) && $_FILES['file_judul']['error'] === UPLOAD_ERR_OK);
        $ada_link_drive  = !empty($link_drive);

        if (empty($new_judul)) {
            $error_msg = "Judul skripsi yang diajukan tidak boleh kosong.";
        } elseif (strlen($new_judul) < 8) {
            $error_msg = "Judul skripsi terlalu pendek. Harap masukkan usulan judul penelitian yang lengkap dan jelas.";
        } elseif (!$ada_file_upload && !$ada_link_drive) {
            $error_msg = "Anda wajib melampirkan berkas dokumen usulan / proposal judul skripsi (PDF/Word) atau tautan Google Drive agar dosen pembimbing dapat meninjau isinya.";
        } else {
            // Berkas pendukung matriks / proposal judul
            if ($ada_file_upload) {
                $ext = strtolower(pathinfo($_FILES['file_judul']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['pdf', 'doc', 'docx'])) {
                    $error_msg = "Berkas dokumen pendukung judul harus berformat PDF (.pdf) atau Word (.doc, .docx).";
                } elseif ($_FILES['file_judul']['size'] > 10 * 1024 * 1024) {
                    $error_msg = "Ukuran berkas dokumen judul maksimal 10 MB.";
                } else {
                    $file_raw = @file_get_contents($_FILES['file_judul']['tmp_name']);
                    if ($file_raw !== false) {
                        $new_filename = 'judul_' . $mhs_id . '_' . uniqid() . '.' . $ext;
                        $file_draft_data = base64_encode($file_raw);
                        $upload_dir = '../assets/uploads/';
                        if (!is_dir($upload_dir)) @mkdir($upload_dir, 0777, true);
                        @move_uploaded_file($_FILES['file_judul']['tmp_name'], $upload_dir . $new_filename);
                    } else {
                        $error_msg = "Gagal membaca berkas dokumen usulan judul.";
                    }
                }
            } elseif ($ada_link_drive) {
                if (!filter_var($link_drive, FILTER_VALIDATE_URL)) {
                    $error_msg = "Format tautan Google Drive / Docs tidak valid. Pastikan diawali https://";
                }
            }

            if (empty($error_msg)) {
                try {
                    $pdo->beginTransaction();

                    // Update judul di tabel data mahasiswa
                    $stmt_up_j = $pdo->prepare("UPDATE mahasiswa SET judul_skripsi = :j WHERE id = :mid");
                    $stmt_up_j->execute([':j' => $new_judul, ':mid' => $mhs_id]);

                    // Catat ke pengajuan_bimbingan agar masuk di riwayat bimbingan & evaluasi dosen
                    $catatan_full = 'Pengajuan Usulan Judul Skripsi: "' . $new_judul . '"';
                    if (!empty($catatan_judul)) {
                        $catatan_full .= "\n\nCatatan / Rumusan Masalah:\n" . $catatan_judul;
                    }

                    // Bersihkan berkas pendukung judul versi lama (maks 2 file terbaru)
                    if (!empty($new_filename) && function_exists('cleanup_old_draft_files')) {
                        cleanup_old_draft_files($pdo, $mhs_id, 'Pengajuan Judul Skripsi', 2);
                    }

                    $stmt_pb = $pdo->prepare("
                        INSERT INTO pengajuan_bimbingan 
                        (mahasiswa_id, dosen_id, tanggal_bimbingan, jam_bimbingan, bab_pembahasan, catatan_mahasiswa, file_draft, file_draft_data, link_drive, status) 
                        VALUES (:mid, :did, :tgl, :jam, 'Pengajuan Judul Skripsi', :catatan, :file_draft, :file_draft_data, :link_drive, 'menunggu')
                    ");
                    $stmt_pb->execute([
                        ':mid'             => $mhs_id,
                        ':did'             => $dosen_id,
                        ':tgl'             => date('Y-m-d'),
                        ':jam'             => date('H:i:s'),
                        ':catatan'         => $catatan_full,
                        ':file_draft'      => $new_filename,
                        ':file_draft_data' => $file_draft_data ?? null,
                        ':link_drive'      => (!empty($link_drive) ? $link_drive : null)
                    ]);

                    // Kirim notifikasi ke dosen pembimbing
                    if ($dosen_user_id) {
                        $nama_mhs = $mhs['nama_lengkap'] ?? 'Mahasiswa';
                        $stmt_n = $pdo->prepare("
                            INSERT INTO notifikasi (user_id, judul, pesan, tipe, link_tujuan) 
                            VALUES (:uid, 'Pengajuan Judul & Dokumen Skripsi', :pesan, 'pembimbing', 'riwayat_bimbingan.php')
                        ");
                        $stmt_n->execute([
                            ':uid'   => $dosen_user_id,
                            ':pesan' => "Mahasiswa {$nama_mhs} telah mengirimkan usulan judul skripsi dan dokumen pendukung: '{$new_judul}'. Silakan tinjau isi dokumen."
                        ]);
                    }

                    $pdo->commit();

                    $success_msg = "Usulan judul skripsi dan dokumen berhasil diajukan! Dosen pembimbing akan meninjau isi dokumen Anda. Penyerahan Bab 1 s.d. 5 akan otomatis terbuka setelah judul resmi di-ACC.";

                    // Refresh data mahasiswa & progres
                    $stmt_mhs->execute([':uid' => $_SESSION['user_id']]);
                    $mhs = $stmt_mhs->fetch();
                    $p_info = get_progres_mahasiswa($pdo, $mhs_id);
                    $is_judul_acc     = $p_info ? $p_info['is_judul_acc'] : false;
                    $pengajuan_judul  = $p_info ? $p_info['pengajuan_judul'] : null;
                    $status_pj        = $pengajuan_judul['status'] ?? null;
                    $judul_terdaftar  = trim($mhs['judul_skripsi'] ?? '');
                    $has_judul_text   = (!empty($judul_terdaftar) && !in_array(strtolower($judul_terdaftar), ['belum ditentukan', 'judul belum diatur', 'judul belum ditentukan', '-', 'null']));
                    $has_judul        = $is_judul_acc;
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $error_msg = "Gagal mencatat pengajuan judul: " . $e->getMessage();
                }
            }
        }
    }
}

// ============================================================
// 2. HANDLE PENYERAHAN NASKAH BAB SKRIPSI (TAHAP 2)
// ============================================================
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_upload_naskah'])) {
    if (!$dosen_id || $status_pembimbing !== 'aktif') {
        $error_msg = "Anda belum dapat mengunggah naskah karena SK Dosen Pembimbing belum aktif. Silakan lakukan konfirmasi SK di Dashboard terlebih dahulu.";
    } elseif (!$has_judul) {
        $error_msg = "Anda wajib mengajukan usulan judul skripsi terlebih dahulu sebelum dapat menyerahkan naskah bab pembahasan.";
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
                        $pdf_raw = file_get_contents($file_tmp);
                        if ($pdf_raw !== false) {
                            $new_filename = 'skripsi_' . $mhs_id . '_' . uniqid() . '.pdf';
                            $file_draft_data = base64_encode($pdf_raw);
                            $upload_dir = '../assets/uploads/';
                            if (!is_dir($upload_dir)) {
                                @mkdir($upload_dir, 0777, true);
                            }
                            @move_uploaded_file($file_tmp, $upload_dir . $new_filename);
                        } else {
                            $error_msg = "Gagal membaca berkas skripsi yang diunggah.";
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
                    // Bersihkan berkas draf revisi lama untuk bab ini (maks 2 file terbaru)
                    if (!empty($new_filename) && function_exists('cleanup_old_draft_files')) {
                        cleanup_old_draft_files($pdo, $mhs_id, $bab_pembahasan, 2);
                    }

                    $stmt_insert = $pdo->prepare("
                        INSERT INTO pengajuan_bimbingan 
                        (mahasiswa_id, dosen_id, tanggal_bimbingan, jam_bimbingan, bab_pembahasan, catatan_mahasiswa, file_draft, file_draft_data, link_drive, status) 
                        VALUES (:mid, :did, :tgl, :jam, :bab, :catatan, :file_draft, :file_draft_data, :link_drive, 'menunggu')
                    ");
                    $stmt_insert->execute([
                        ':mid'             => $mhs_id,
                        ':did'             => $dosen_id,
                        ':tgl'             => $tanggal_draft,
                        ':jam'             => $jam_draft,
                        ':bab'             => $bab_pembahasan,
                        ':catatan'         => $catatan_revisi,
                        ':file_draft'      => $new_filename,
                        ':file_draft_data' => $file_draft_data ?? null,
                        ':link_drive'      => (!empty($link_drive) ? $link_drive : null)
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

// Ambil semua daftar file draf, tautan drive, dan pengajuan judul mahasiswa ini
$stmt_drafts = $pdo->prepare("
    SELECT id, tanggal_bimbingan, jam_bimbingan, bab_pembahasan, catatan_mahasiswa, file_draft, link_drive, status, catatan_dosen, created_at 
    FROM pengajuan_bimbingan 
    WHERE mahasiswa_id = :mid AND (
        (file_draft IS NOT NULL AND file_draft != '') 
        OR (link_drive IS NOT NULL AND link_drive != '')
        OR bab_pembahasan = 'Pengajuan Judul Skripsi'
    )
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
    <div class="alert alert-success alert-dismissible fade show py-2.5 px-3 small shadow-sm rounded-3 border-0" role="alert">
        <i class="bi bi-check-circle-fill me-2 fs-6"></i><?= htmlspecialchars($success_msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!empty($error_msg)): ?>
    <div class="alert alert-danger alert-dismissible fade show py-2.5 px-3 small shadow-sm rounded-3 border-0" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2 fs-6"></i><?= htmlspecialchars($error_msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($status_pembimbing !== 'aktif'): ?>
    <div class="alert alert-warning shadow-sm mb-4 border-start border-warning border-4" role="alert">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-exclamation-triangle-fill fs-5 text-warning flex-shrink-0"></i>
            <div>
                <strong>Perhatian:</strong> SK Dosen Pembimbing Anda saat ini belum berstatus <strong>Aktif</strong>. Silakan kembali ke <a href="index.php" class="fw-bold text-dark text-decoration-underline">Dashboard Mahasiswa</a> untuk mengonfirmasi penerimaan SK terlebih dahulu.
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (!$has_judul): ?>
    <!-- ============================================================
         PEMBERITAHUAN ALUR WAJIB: AJUKAN JUDUL DULU SEBELUM BAB
    ============================================================ -->
    <div class="alert alert-warning border-0 shadow-sm d-flex align-items-start gap-3 p-3 rounded-3 mb-4 bg-warning bg-opacity-10 border-start border-warning border-4">
        <i class="bi bi-info-circle-fill fs-4 text-warning flex-shrink-0 mt-1"></i>
        <div>
            <h6 class="fw-bold text-dark mb-1">Tahap 1: Ajukan Judul Skripsi Terlebih Dahulu</h6>
            <p class="small text-muted mb-0">
                Sesuai alur bimbingan akademik, Anda wajib <strong>mengajukan usulan judul skripsi terlebih dahulu</strong> pada formulir di bawah ini. Setelah judul diajukan dan tercatat, formulir penyerahan Bab 1 s.d. Bab 5 akan otomatis terbuka.
            </p>
        </div>
    </div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <!-- ============================================================
         KOLOM KIRI: FORMULIR BERDASARKAN STATUS JUDUL
    ============================================================ -->
    <div class="col-lg-5">
        <?php if (!$is_judul_acc): ?>
            <!-- FORM TAHAP 1: PENGAJUAN JUDUL & UNGGAH DOKUMEN SKRIPSI -->
            <div class="card shadow-sm h-100 border-0" id="formTahapJudul">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="m-0 fw-bold text-dark">
                        <i class="bi bi-file-earmark-text text-primary me-2"></i>Tahap 1: Pengajuan Usulan Judul Skripsi
                    </h6>
                    <span class="badge bg-warning text-dark"><i class="bi bi-1-circle-fill me-1"></i>Langkah 1</span>
                </div>
                <div class="card-body p-4">
                    <?php if ($pengajuan_judul && $status_pj === 'menunggu'): ?>
                        <!-- KARTU STATUS: MENUNGGU PENINJAUAN DOKUMEN OLEH DOSEN -->
                        <div class="p-3 bg-light rounded-3 border mb-3">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="badge bg-warning text-dark px-2.5 py-1">
                                    <i class="bi bi-hourglass-split me-1"></i>Sedang Ditinjau Dosen
                                </span>
                                <small class="text-muted"><?= date('d/m/Y H:i', strtotime($pengajuan_judul['created_at'] ?? 'now')); ?></small>
                            </div>
                            <h6 class="fw-bold text-dark mb-2">"<?= htmlspecialchars($judul_terdaftar ?: $pengajuan_judul['catatan_mahasiswa']); ?>"</h6>
                            
                            <div class="d-flex flex-wrap gap-2 mb-2">
                                <?php if (!empty($pengajuan_judul['file_draft'])): ?>
                                    <a href="../assets/uploads/<?= htmlspecialchars($pengajuan_judul['file_draft']); ?>" target="_blank" download class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-file-earmark-pdf-fill me-1"></i>Unduh Dokumen Usulan Judul
                                    </a>
                                <?php endif; ?>
                                <?php if (!empty($pengajuan_judul['link_drive'])): ?>
                                    <a href="<?= htmlspecialchars($pengajuan_judul['link_drive']); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-success">
                                        <i class="bi bi-google me-1"></i>Tautan Google Drive
                                    </a>
                                <?php endif; ?>
                            </div>
                            <div class="alert alert-info py-2 px-3 small mb-0 rounded-2">
                                <i class="bi bi-info-circle me-1"></i>Dokumen usulan Anda telah terkirim. Harap menunggu Dosen Pembimbing meninjau dokumen dan memberikan keputusan ACC. Setelah di-ACC, formulir penyerahan Bab 1 akan otomatis terbuka.
                            </div>
                        </div>

                        <!-- Tombol buka form revisi jika ingin mengganti dokumen -->
                        <div class="text-center mb-2">
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="collapse" data-bs-target="#formAjukanUlangJudul">
                                <i class="bi bi-pencil-square me-1"></i>Perbarui Judul atau Dokumen
                            </button>
                        </div>
                    <?php elseif ($pengajuan_judul && $status_pj === 'revisi'): ?>
                        <!-- KARTU STATUS: PERLU REVISI -->
                        <div class="alert alert-danger p-3 rounded-3 mb-3 border-start border-danger border-4">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <i class="bi bi-exclamation-triangle-fill fs-5 text-danger"></i>
                                <strong class="text-danger">Catatan Revisi dari Dosen Pembimbing:</strong>
                            </div>
                            <p class="small mb-0 text-dark">
                                "<?= nl2br(htmlspecialchars($pengajuan_judul['catatan_dosen'] ?? 'Harap perbaiki usulan judul dan dokumen pendukung sesuai arahan.')); ?>"
                            </p>
                        </div>
                    <?php endif; ?>

                    <!-- Form Pengajuan Judul & Upload Dokumen -->
                    <div id="formAjukanUlangJudul" class="<?= ($pengajuan_judul && $status_pj === 'menunggu') ? 'collapse' : ''; ?>">
                        <form action="upload_draft.php" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="action_ajukan_judul" value="1">

                            <div class="mb-3">
                                <label for="judul_skripsi" class="form-label small fw-semibold text-secondary">
                                    Usulan Judul Skripsi <span class="text-danger">*</span>
                                </label>
                                <textarea class="form-control" id="judul_skripsi" name="judul_skripsi" rows="3" placeholder="Contoh: Rancang Bangun Sistem Informasi Bimbingan Skripsi Berbasis Web Menggunakan Metode Agile..." required <?= $status_pembimbing !== 'aktif' ? 'disabled' : ''; ?>><?= htmlspecialchars($judul_terdaftar !== 'Belum Ditentukan' ? $judul_terdaftar : ''); ?></textarea>
                                <div class="form-text small" style="font-size: 0.75rem;">
                                    Tuliskan rancangan judul skripsi yang jelas, spesifik, dan sesuai bidang keilmuan.
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="catatan_judul" class="form-label small fw-semibold text-secondary">
                                    Catatan / Rumusan Masalah Singkat <span class="text-muted small fw-normal">(Opsional)</span>
                                </label>
                                <textarea class="form-control" id="catatan_judul" name="catatan_judul" rows="2" placeholder="Uraikan fokus penelitian, latar belakang singkat, atau poin matriks..." <?= $status_pembimbing !== 'aktif' ? 'disabled' : ''; ?>></textarea>
                            </div>

                            <!-- Lampiran Dokumen Usulan Judul: Wajib PDF / Word / Drive -->
                            <div class="mb-3">
                                <label class="form-label small fw-semibold text-secondary d-block">
                                    Dokumen Usulan Judul / Matriks Skripsi <span class="text-danger">*</span>
                                </label>
                                <div class="d-flex gap-3 mb-2 flex-wrap">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="metode_unggah_judul" id="metode_j_file" value="file" checked onchange="toggleMetodeJudul()">
                                        <label class="form-check-label small fw-semibold" for="metode_j_file">
                                            <i class="bi bi-file-earmark-arrow-up text-danger me-1"></i>Unggah Berkas (PDF / Word)
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="metode_unggah_judul" id="metode_j_drive" value="drive" onchange="toggleMetodeJudul()">
                                        <label class="form-check-label small fw-semibold" for="metode_j_drive">
                                            <i class="bi bi-google text-primary me-1"></i>Link Google Drive / Docs
                                        </label>
                                    </div>
                                </div>

                                <div id="box_j_file" class="mb-2">
                                    <input type="file" class="form-control form-control-sm" name="file_judul" accept=".pdf,.doc,.docx" <?= $status_pembimbing !== 'aktif' ? 'disabled' : ''; ?>>
                                    <div class="form-text small" style="font-size: 0.72rem;">Format: <strong>PDF (.pdf)</strong> atau <strong>Word (.doc, .docx)</strong>. Maksimal 10 MB.</div>
                                </div>

                                <div id="box_j_drive" class="d-none mb-2">
                                    <input type="url" class="form-control form-control-sm" name="link_drive_judul" placeholder="https://docs.google.com/... atau https://drive.google.com/..." <?= $status_pembimbing !== 'aktif' ? 'disabled' : ''; ?>>
                                    <div class="form-text small" style="font-size: 0.72rem;">Pastikan setelan berbagi disetel agar dosen pembimbing dapat mengakses/membaca dokumen.</div>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold" <?= $status_pembimbing !== 'aktif' ? 'disabled' : ''; ?>>
                                <i class="bi bi-send-check me-1"></i>Kirim Dokumen & Usulan Judul
                            </button>
                        </form>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- FORM TAHAP 2: PENYERAHAN NASKAH BAB SKRIPSI (AKTIF KARENA JUDUL SUDAH ADA) -->
            <div class="card shadow-sm h-100 border-0">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="m-0 fw-bold text-dark">
                        <i class="bi bi-cloud-arrow-up text-primary me-2"></i>Form Penyerahan Naskah Baru
                    </h6>
                    <span class="badge bg-success-subtle text-success border border-success-subtle small px-2 py-1">
                        <i class="bi bi-2-circle-fill me-1"></i>Tahap 2: Bab Skripsi
                    </span>
                </div>
                <div class="card-body p-4">
                    <!-- Kartu Info Judul Skripsi Terdaftar & Opsi Ubah Judul -->
                    <div class="p-3 bg-light rounded-3 border mb-3">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                            <span class="badge bg-success small px-2 py-0.5" style="font-size: 0.7rem;">
                                <i class="bi bi-check-circle me-1"></i>Judul Terdaftar
                            </span>
                            <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size: 0.72rem;" data-bs-toggle="collapse" data-bs-target="#boxUbahJudul" title="Klik untuk mengajukan perubahan judul">
                                <i class="bi bi-pencil-square me-1"></i>Ubah Judul
                            </button>
                        </div>
                        <h6 class="fw-bold text-dark mb-1" style="font-size: 0.9rem;">"<?= htmlspecialchars($mhs['judul_skripsi']); ?>"</h6>
                        <small class="text-muted" style="font-size: 0.78rem;">Dosen Pembimbing: <strong><?= htmlspecialchars($mhs['nama_dosen'] ?? 'Belum Diplot'); ?></strong></small>

                        <!-- Collapse Form Ubah Judul -->
                        <div class="collapse <?= (isset($_GET['tahap']) && $_GET['tahap'] === 'judul') ? 'show' : ''; ?> mt-2 pt-2 border-top" id="boxUbahJudul">
                            <form action="upload_draft.php" method="POST">
                                <input type="hidden" name="action_ajukan_judul" value="1">
                                <label class="form-label small fw-semibold text-secondary mb-1">Revisi / Judul Baru:</label>
                                <textarea class="form-control form-control-sm mb-2" name="judul_skripsi" rows="2" required><?= htmlspecialchars($mhs['judul_skripsi']); ?></textarea>
                                <input type="text" class="form-control form-control-sm mb-2" name="catatan_judul" placeholder="Alasan perubahan judul (opsional)">
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="bi bi-check-lg me-1"></i>Simpan Perubahan Judul
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Form Penyerahan Bab -->
                    <form action="upload_draft.php" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action_upload_naskah" value="1">

                        <div class="mb-3">
                            <label for="bab_pembahasan" class="form-label small fw-semibold text-secondary">Bab yang Diajukan <span class="text-danger">*</span></label>
                            <select class="form-select" id="bab_pembahasan" name="bab_pembahasan" required <?= $status_pembimbing !== 'aktif' ? 'disabled' : ''; ?>>
                                <option value="" <?= empty($auto_select_bab) ? 'selected' : ''; ?> disabled>-- Pilih Bagian Skripsi --</option>
                                <option value="Bab 1: Pendahuluan" <?= $auto_select_bab === 'Bab 1: Pendahuluan' ? 'selected' : ''; ?>>Bab 1: Pendahuluan</option>
                                <option value="Bab 2: Tinjauan Pustaka" <?= $auto_select_bab === 'Bab 2: Tinjauan Pustaka' ? 'selected' : ''; ?>>Bab 2: Tinjauan Pustaka</option>
                                <option value="Bab 3: Metodologi Penelitian" <?= $auto_select_bab === 'Bab 3: Metodologi Penelitian' ? 'selected' : ''; ?>>Bab 3: Metodologi Penelitian</option>
                                <option value="Bab 4: Hasil dan Pembahasan" <?= $auto_select_bab === 'Bab 4: Hasil dan Pembahasan' ? 'selected' : ''; ?>>Bab 4: Hasil & Pembahasan</option>
                                <option value="Bab 5: Kesimpulan dan Saran" <?= $auto_select_bab === 'Bab 5: Kesimpulan dan Saran' ? 'selected' : ''; ?>>Bab 5: Kesimpulan & Saran</option>
                                <option value="Proposal Skripsi Lengkap" <?= $auto_select_bab === 'Proposal Skripsi Lengkap' ? 'selected' : ''; ?>>Proposal Skripsi Lengkap (Bab 1-3)</option>
                                <option value="Naskah Skripsi Lengkap" <?= $auto_select_bab === 'Naskah Skripsi Lengkap' ? 'selected' : ''; ?>>Naskah Skripsi Lengkap (Bab 1-5)</option>
                            </select>
                        </div>

                        <!-- Pilihan Metode Penyerahan (PDF atau Google Drive) -->
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
                                        <i class="bi bi-google text-primary me-1"></i>Link Google Drive
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

                        <!-- Input Tautan Google Drive -->
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
        <?php endif; ?>
    </div>

    <!-- ============================================================
         KOLOM KANAN: PANDUAN ATAU KARTU TERKUNCI (JIKA BELUM ADA JUDUL)
    ============================================================ -->
    <div class="col-lg-7">
        <?php if (!$has_judul): ?>
            <!-- KARTU TAHAP 2 TERKUNCI MENUNGGU JUDUL -->
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="m-0 fw-bold text-muted">
                        <i class="bi bi-lock-fill me-2 text-secondary"></i>Tahap 2: Penyerahan Naskah Bab Skripsi
                    </h6>
                    <span class="badge bg-secondary"><i class="bi bi-lock-fill me-1"></i>Terkunci</span>
                </div>
                <div class="card-body p-4 text-center py-5">
                    <div class="py-4">
                        <div class="bg-light d-inline-flex p-3 rounded-circle mb-3 border">
                            <i class="bi bi-journal-lock fs-1 text-primary"></i>
                        </div>
                        <h5 class="fw-bold text-dark mb-2">Ajukan Judul Skripsi Terlebih Dahulu</h5>
                        <p class="text-muted small mx-auto mb-4" style="max-width: 440px; line-height: 1.6;">
                            Fitur penyerahan draf <strong>Bab 1 (Pendahuluan)</strong> s.d. <strong>Bab 5 (Penutup)</strong> akan otomatis terbuka setelah Anda mengajukan rencana judul skripsi pada formulir di sebelah kiri.
                        </p>

                        <!-- Alur Langkah Sistematis -->
                        <div class="row g-2 text-start justify-content-center mx-auto" style="max-width: 500px;">
                            <div class="col-12">
                                <div class="p-3 rounded border bg-light d-flex align-items-center gap-3">
                                    <div class="badge bg-warning text-dark rounded-circle p-2 fs-6">1</div>
                                    <div>
                                        <strong class="d-block text-dark small">Langkah 1: Usulkan Judul Skripsi (Aktif)</strong>
                                        <span class="text-muted" style="font-size: 0.78rem;">Ketik usulan judul penelitian Anda pada formulir Tahap 1.</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="p-3 rounded border bg-white d-flex align-items-center gap-3 opacity-75">
                                    <div class="badge bg-secondary rounded-circle p-2 fs-6">2</div>
                                    <div>
                                        <strong class="d-block text-muted small">Langkah 2: Penyerahan Naskah Bab</strong>
                                        <span class="text-muted" style="font-size: 0.78rem;">Unggah berkas PDF atau Google Drive Bab 1 - Bab 5.</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="p-3 rounded border bg-white d-flex align-items-center gap-3 opacity-75">
                                    <div class="badge bg-secondary rounded-circle p-2 fs-6">3</div>
                                    <div>
                                        <strong class="d-block text-muted small">Langkah 3: Evaluasi & Ujian Sidang</strong>
                                        <span class="text-muted" style="font-size: 0.78rem;">Dosen meng-ACC naskah dan mendaftar sidang kelulusan.</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- PANDUAN PENYERAHAN NASKAH LENGKAP -->
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
                                <p class="small text-muted mb-0">Unggah file PDF ukuran maks 5 MB jika naskah telah siap untuk ditinjau langsung secara offline.</p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3 border rounded bg-light h-100">
                                <h6 class="fw-bold small text-dark"><i class="bi bi-google text-primary me-1"></i>Opsi Google Drive / Docs</h6>
                                <p class="small text-muted mb-0">Gunakan tautan Google Docs agar dosen pembimbing dapat langsung memberikan catatan komentar (*inline comments*) pada naskah.</p>
                            </div>
                        </div>
                    </div>

                    <h6 class="fw-bold small text-dark mb-2">Struktur Sistematika Penulisan Bab Skripsi:</h6>
                    <ul class="list-group list-group-numbered small mb-3">
                        <li class="list-group-item d-flex justify-content-between align-items-start">
                            <div class="ms-2 me-auto">
                                <div class="fw-bold">Bab 1: Pendahuluan</div>
                                Latar belakang, rumusan masalah, batasan masalah, tujuan, dan manfaat penelitian.
                            </div>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-start">
                            <div class="ms-2 me-auto">
                                <div class="fw-bold">Bab 2: Tinjauan Pustaka</div>
                                Kajian teori dasar, tinjauan penelitian terdahulu yang relevan, dan kerangka pemikiran.
                            </div>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-start">
                            <div class="ms-2 me-auto">
                                <div class="fw-bold">Bab 3: Metodologi Penelitian</div>
                                Analisis kebutuhan, perancangan sistem/arsitektur, alur penelitian, dan prosedur pengujian.
                            </div>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-start">
                            <div class="ms-2 me-auto">
                                <div class="fw-bold">Bab 4: Hasil & Pembahasan</div>
                                Implementasi aplikasi, pengujian fungsionalitas, hasil analisis data, dan pembahasan.
                            </div>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-start">
                            <div class="ms-2 me-auto">
                                <div class="fw-bold">Bab 5: Penutup</div>
                                Kesimpulan dari seluruh hasil penelitian serta saran untuk pengembangan sistem berikutnya.
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================================
     TABEL RIWAYAT DOKUMEN & USULAN JUDUL
============================================================ -->
<div class="card shadow-sm mb-4 border-0">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
        <h6 class="m-0 fw-bold text-dark">
            <i class="bi bi-folder-check text-primary me-2"></i>Daftar Dokumen & Pengajuan yang Pernah Diserahkan
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
                        <th style="width: 140px;">Tanggal & Waktu</th>
                        <th>Bab / Topik</th>
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
                                Belum ada berkas atau usulan yang diserahkan. Silakan isi formulir di atas.
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
                                    <?php if ($draft['bab_pembahasan'] === 'Pengajuan Judul Skripsi'): ?>
                                        <span class="badge bg-warning text-dark mb-1 d-inline-block">Usulan Judul</span><br>
                                        <span class="fw-bold text-dark small"><?= htmlspecialchars($draft['bab_pembahasan']); ?></span>
                                    <?php else: ?>
                                        <span class="fw-bold text-dark"><?= htmlspecialchars($draft['bab_pembahasan']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="small text-muted"><?= nl2br(htmlspecialchars($draft['catatan_mahasiswa'] ?? '-')); ?></span>
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
                                            <a href="../unduh_berkas.php?id=<?= $draft['id']; ?>" target="_blank" class="badge-pdf" title="Lihat Berkas PDF">
                                                <i class="bi bi-file-earmark-pdf"></i>PDF
                                            </a>
                                        <?php endif; ?>
                                        <?php if (!empty($draft['link_drive'])): ?>
                                            <a href="<?= htmlspecialchars($draft['link_drive']); ?>" target="_blank" class="badge-drive" title="Buka Link Google Drive">
                                                <i class="bi bi-google"></i>Drive
                                            </a>
                                        <?php endif; ?>
                                        <?php if (empty($draft['file_draft']) && empty($draft['link_drive'])): ?>
                                            <span class="text-muted small">-</span>
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

function toggleMetodeJudul() {
    const isFile = document.getElementById('metode_j_file').checked;
    const isDrive = document.getElementById('metode_j_drive').checked;
    const boxFile = document.getElementById('box_j_file');
    const boxDrive = document.getElementById('box_j_drive');

    if (isFile) {
        boxFile.classList.remove('d-none');
        boxDrive.classList.add('d-none');
    } else if (isDrive) {
        boxFile.classList.add('d-none');
        boxDrive.classList.remove('d-none');
    } else {
        boxFile.classList.add('d-none');
        boxDrive.classList.add('d-none');
    }
}
</script>

<?php
require_once '../templates/footer.php';
?>
