<?php
// dosen/riwayat_bimbingan.php - Pemeriksaan Naskah, Evaluasi Revisi & Penilaian (Salsa)
// Fitur: Unduh Draft Skripsi PDF, Input Nilai Progres (0-100), Status ACC Bab / ACC Sidang / Revisi / Selesai
$page_title   = "Riwayat & Evaluasi Bimbingan";
$current_page = "riwayat";

require_once '../config/database.php';
require_once '../templates/header.php';
require_once '../templates/sidebar_dosen.php';

// Proteksi hak akses role dosen
if ($_SESSION['role'] !== 'dosen') {
    header("Location: ../login.php");
    exit;
}

// Ambil data dosen berdasarkan user_id aktif
$stmt_dosen = $pdo->prepare("SELECT * FROM dosen WHERE user_id = :uid LIMIT 1");
$stmt_dosen->execute([':uid' => $_SESSION['user_id']]);
$dosen = $stmt_dosen->fetch();
$dosen_id = $dosen['id'] ?? 0;

$success_msg = '';
$error_msg   = '';

// Proses Simpan Evaluasi, Nilai & Logbook (ACC / Revisi)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_evaluasi'])) {
    $id_pengajuan   = (int)($_POST['id_pengajuan'] ?? 0);
    $mahasiswa_id   = (int)($_POST['mahasiswa_id'] ?? 0);
    $nilai_progres  = (int)($_POST['nilai_progres'] ?? 0);
    $keputusan_acc  = trim($_POST['keputusan_acc'] ?? 'selesai'); // 'acc_bab', 'acc_sidang', 'revisi', 'selesai'
    $catatan_dosen  = trim($_POST['catatan_dosen'] ?? '');

    // Validasi range nilai
    if ($nilai_progres < 0) $nilai_progres = 0;
    if ($nilai_progres > 100) $nilai_progres = 100;

    if ($id_pengajuan <= 0 || $mahasiswa_id <= 0) {
        $error_msg = "Data bimbingan tidak valid.";
    } else {
        try {
            $pdo->beginTransaction();

            // Tentukan status sesi bimbingan
            $status_sesi = ($keputusan_acc === 'revisi') ? 'revisi' : 'selesai';

            // 1. Update tabel pengajuan_bimbingan
            $stmt_up = $pdo->prepare("
                UPDATE pengajuan_bimbingan 
                SET nilai_progres = :nilai, 
                    catatan_dosen = :catatan, 
                    status = :status 
                WHERE id = :id AND dosen_id = :did
            ");
            $stmt_up->execute([
                ':nilai'   => $nilai_progres,
                ':catatan' => $catatan_dosen,
                ':status'  => $status_sesi,
                ':id'      => $id_pengajuan,
                ':did'     => $dosen_id
            ]);

            // 2. Pencatatan ke logbook_bimbingan sesuai keputusan
            if (in_array($keputusan_acc, ['acc_bab', 'acc_sidang', 'revisi'])) {
                $status_log = ($keputusan_acc === 'acc_sidang') ? 'acc_sidang' : (($keputusan_acc === 'acc_bab') ? 'acc_bab' : 'revisi');
                
                $stmt_log = $pdo->prepare("
                    INSERT INTO logbook_bimbingan (bimbingan_id, mahasiswa_id, tanggal_acc, catatan_hasil, status_bab) 
                    VALUES (:bid, :mid, CURDATE(), :catatan, :sbab)
                ");
                $stmt_log->execute([
                    ':bid'     => $id_pengajuan,
                    ':mid'     => $mahasiswa_id,
                    ':catatan' => !empty($catatan_dosen) ? $catatan_dosen : 'Evaluasi dan catatan bimbingan disahkan.',
                    ':sbab'    => $status_log
                ]);

                // Jika di-ACC Sidang, update status kelulusan mahasiswa menjadi siap_sidang
                if ($keputusan_acc === 'acc_sidang') {
                    $stmt_mhs = $pdo->prepare("UPDATE mahasiswa SET status_kelulusan = 'siap_sidang' WHERE id = :mid");
                    $stmt_mhs->execute([':mid' => $mahasiswa_id]);
                }
            }

            $pdo->commit();

            $label_keputusan = ($keputusan_acc === 'acc_bab') ? 'ACC BAB' : (($keputusan_acc === 'acc_sidang') ? 'ACC SIDANG SKRIPSI' : (($keputusan_acc === 'revisi') ? 'PERLU REVISI' : 'SELESAI'));
            $success_msg = "Evaluasi berhasil disimpan: Status <strong>{$label_keputusan}</strong> dengan Nilai Progres <strong>{$nilai_progres}%</strong>.";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error_msg = "Gagal menyimpan evaluasi: " . $e->getMessage();
        }
    }
}

// Filter status di riwayat (default: semua aktif)
$filter = $_GET['filter'] ?? 'semua';
$where_status = "pb.status IN ('disetujui', 'selesai', 'revisi')";
if ($filter === 'disetujui') {
    $where_status = "pb.status = 'disetujui'";
} elseif ($filter === 'selesai') {
    $where_status = "pb.status = 'selesai'";
} elseif ($filter === 'revisi') {
    $where_status = "pb.status = 'revisi'";
}

$stmt_riwayat = $pdo->prepare("
    SELECT pb.*, m.nim, m.nama_lengkap, m.judul_skripsi, m.status_kelulusan
    FROM pengajuan_bimbingan pb
    JOIN mahasiswa m ON pb.mahasiswa_id = m.id
    WHERE pb.dosen_id = :did AND {$where_status}
    ORDER BY pb.tanggal_bimbingan DESC, pb.jam_bimbingan DESC
");
$stmt_riwayat->execute([':did' => $dosen_id]);
$riwayat_list = $stmt_riwayat->fetchAll();
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2 fw-bold">Pemeriksaan Naskah & Penilaian Bimbingan</h1>
        <p class="text-muted small mb-0">Unduh draft naskah PDF skripsi mahasiswa, berikan evaluasi perbaikan, tetapkan nilai progres, dan putuskan status pengesahan (ACC Bab / ACC Sidang / Revisi).</p>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="cetak_laporan.php" class="btn btn-sm btn-outline-primary me-2">
            <i class="bi bi-printer me-1"></i> Cetak Rekapan
        </a>
    </div>
</div>

<!-- Alert Notifikasi -->
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

<!-- Tab Filter Cepat -->
<div class="d-flex gap-2 mb-3">
    <a href="riwayat_bimbingan.php?filter=semua" class="btn btn-sm <?= ($filter === 'semua') ? 'btn-primary' : 'btn-outline-primary'; ?>">
        <i class="bi bi-collection me-1"></i>Semua Sesi Aktif
    </a>
    <a href="riwayat_bimbingan.php?filter=disetujui" class="btn btn-sm <?= ($filter === 'disetujui') ? 'btn-primary' : 'btn-outline-primary'; ?>">
        <i class="bi bi-calendar-check me-1"></i>Perlu Dievaluasi (Disetujui)
    </a>
    <a href="riwayat_bimbingan.php?filter=revisi" class="btn btn-sm <?= ($filter === 'revisi') ? 'btn-primary' : 'btn-outline-primary'; ?>">
        <i class="bi bi-exclamation-circle me-1"></i>Status Revisi
    </a>
    <a href="riwayat_bimbingan.php?filter=selesai" class="btn btn-sm <?= ($filter === 'selesai') ? 'btn-primary' : 'btn-outline-primary'; ?>">
        <i class="bi bi-check2-all me-1"></i>Selesai / ACC
    </a>
</div>

<!-- Tabel Riwayat & Evaluasi -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold text-dark">
            <i class="bi bi-journal-check me-2 text-primary"></i>Daftar Sesi Bimbingan & Naskah
        </h6>
        <span class="badge bg-secondary"><?= count($riwayat_list); ?> Sesi Terdata</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 45px;" class="text-center">No</th>
                        <th>Mahasiswa & Judul Skripsi</th>
                        <th>Bab Pembahasan</th>
                        <th>Jadwal Bimbingan</th>
                        <th>Draft Skripsi (PDF)</th>
                        <th>Progres Nilai</th>
                        <th>Status Sesi</th>
                        <th style="width: 180px;" class="text-center">Aksi & Evaluasi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($riwayat_list)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="bi bi-folder-x fs-1 d-block mb-2"></i>
                                Tidak ada data sesi bimbingan pada filter ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($riwayat_list as $index => $row): ?>
                            <tr>
                                <td class="text-center fw-semibold"><?= $index + 1; ?></td>
                                <td>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($row['nama_lengkap']); ?></div>
                                    <small class="text-muted">NIM: <?= htmlspecialchars($row['nim']); ?></small>
                                    <div class="text-truncate small text-secondary mt-1" style="max-width: 230px;" title="<?= htmlspecialchars($row['judul_skripsi']); ?>">
                                        <i class="bi bi-mortarboard me-1"></i><?= htmlspecialchars($row['judul_skripsi']); ?>
                                    </div>
                                    <?php if ($row['status_kelulusan'] === 'siap_sidang'): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.7rem;">Siap Sidang</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                                        <?= htmlspecialchars($row['bab_pembahasan']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="small fw-semibold">
                                        <i class="bi bi-calendar-check me-1 text-primary"></i>
                                        <?= date('d M Y', strtotime($row['tanggal_bimbingan'])); ?>
                                    </div>
                                    <small class="text-muted">
                                        <i class="bi bi-clock me-1"></i>
                                        <?= htmlspecialchars(substr($row['jam_bimbingan'], 0, 5)); ?> WIB
                                    </small>
                                </td>
                                <td>
                                    <?php if (!empty($row['file_draft'])): ?>
                                        <a href="../assets/uploads/<?= htmlspecialchars($row['file_draft']); ?>" 
                                           class="btn btn-sm btn-outline-danger fw-semibold" 
                                           target="_blank" download>
                                            <i class="bi bi-file-earmark-pdf-fill me-1"></i>Unduh Draft
                                        </a>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted border">Belum Ada File</span>
                                    <?php endif; ?>
                                </td>
                                <td style="min-width: 120px;">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <small class="fw-bold"><?= (int)$row['nilai_progres']; ?>%</small>
                                    </div>
                                    <div class="progress" style="height: 7px;">
                                        <div class="progress-bar <?= ($row['nilai_progres'] >= 80) ? 'bg-success' : (($row['nilai_progres'] >= 50) ? 'bg-info' : 'bg-warning'); ?>" 
                                             role="progressbar" 
                                             style="width: <?= (int)$row['nilai_progres']; ?>%;" 
                                             aria-valuenow="<?= (int)$row['nilai_progres']; ?>" 
                                             aria-valuemin="0" 
                                             aria-valuemax="100">
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                    $st = $row['status'];
                                    if ($st === 'disetujui') {
                                        echo '<span class="badge bg-success-subtle text-success border border-success-subtle">Disetujui</span>';
                                    } elseif ($st === 'selesai') {
                                        echo '<span class="badge bg-primary">Selesai</span>';
                                    } elseif ($st === 'revisi') {
                                        echo '<span class="badge bg-warning text-dark">Perlu Revisi</span>';
                                    } else {
                                        echo '<span class="badge bg-secondary">' . htmlspecialchars(ucfirst($st)) . '</span>';
                                    }
                                    ?>
                                </td>
                                <td class="text-center">
                                    <button type="button" 
                                            class="btn btn-sm btn-primary rounded fw-semibold" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#modalEvaluasi<?= $row['id']; ?>">
                                        <i class="bi bi-pencil-square me-1"></i>Evaluasi & Nilai
                                    </button>

                                    <!-- MODAL EVALUASI, NILAI & ACC -->
                                    <div class="modal fade" id="modalEvaluasi<?= $row['id']; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-lg text-start">
                                            <div class="modal-content">
                                                <form action="riwayat_bimbingan.php?filter=<?= $filter; ?>" method="POST">
                                                    <input type="hidden" name="simpan_evaluasi" value="1">
                                                    <input type="hidden" name="id_pengajuan" value="<?= $row['id']; ?>">
                                                    <input type="hidden" name="mahasiswa_id" value="<?= $row['mahasiswa_id']; ?>">

                                                    <div class="modal-header bg-primary text-white">
                                                        <h5 class="modal-title fw-bold">
                                                            <i class="bi bi-clipboard2-check me-2"></i>Evaluasi Bimbingan: <?= htmlspecialchars($row['nama_lengkap']); ?>
                                                        </h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>

                                                    <div class="modal-body p-4">
                                                        <!-- Ringkasan Info Naskah -->
                                                        <div class="row g-2 mb-3 p-3 bg-light rounded border">
                                                            <div class="col-md-6">
                                                                <small class="text-muted d-block">Mahasiswa / NIM</small>
                                                                <strong><?= htmlspecialchars($row['nama_lengkap']); ?></strong> (<?= htmlspecialchars($row['nim']); ?>)
                                                            </div>
                                                            <div class="col-md-6">
                                                                <small class="text-muted d-block">Bab yang Dibahas</small>
                                                                <span class="badge bg-primary"><?= htmlspecialchars($row['bab_pembahasan']); ?></span>
                                                            </div>
                                                            <div class="col-12 mt-2">
                                                                <small class="text-muted d-block">Judul Skripsi</small>
                                                                <span class="small"><?= htmlspecialchars($row['judul_skripsi']); ?></span>
                                                            </div>
                                                            <div class="col-12 mt-2">
                                                                <small class="text-muted d-block">Berkas Draft Skripsi</small>
                                                                <?php if (!empty($row['file_draft'])): ?>
                                                                    <a href="../assets/uploads/<?= htmlspecialchars($row['file_draft']); ?>" 
                                                                       class="btn btn-sm btn-outline-danger mt-1" 
                                                                       target="_blank" download>
                                                                        <i class="bi bi-file-earmark-pdf-fill me-1"></i>Unduh & Periksa Berkas (<?= htmlspecialchars($row['file_draft']); ?>)
                                                                    </a>
                                                                <?php else: ?>
                                                                    <span class="badge bg-secondary-subtle text-secondary">Mahasiswa belum mengunggah file naskah.</span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>

                                                        <!-- 1. Input Nilai Progres Bimbingan -->
                                                        <div class="mb-4">
                                                            <label for="nilai_progres_<?= $row['id']; ?>" class="form-label fw-bold">
                                                                Nilai Progres Bimbingan (0% - 100%): 
                                                                <span class="text-primary fs-5 fw-bold" id="labelNilai<?= $row['id']; ?>"><?= (int)$row['nilai_progres']; ?>%</span>
                                                            </label>
                                                            <input type="range" 
                                                                   class="form-range" 
                                                                   name="nilai_progres" 
                                                                   id="nilai_progres_<?= $row['id']; ?>" 
                                                                   min="0" max="100" step="5" 
                                                                   value="<?= (int)$row['nilai_progres']; ?>"
                                                                   oninput="document.getElementById('labelNilai<?= $row['id']; ?>').innerText = this.value + '%'">
                                                            <div class="d-flex justify-content-between small text-muted">
                                                                <span>0% (Tahap Awal)</span>
                                                                <span>50% (Pengembangan)</span>
                                                                <span>100% (Selesai Tuntas)</span>
                                                            </div>
                                                        </div>

                                                        <!-- 2. Keputusan ACC / Revisi / Selesai -->
                                                        <div class="mb-4">
                                                            <label class="form-label fw-bold">Keputusan Hasil Pemeriksaan Naskah <span class="text-danger">*</span></label>
                                                            <div class="row g-2">
                                                                <div class="col-md-6">
                                                                    <div class="form-check p-3 border rounded h-100 bg-white">
                                                                        <input class="form-check-input" type="radio" name="keputusan_acc" id="opt_acc_bab<?= $row['id']; ?>" value="acc_bab" required>
                                                                        <label class="form-check-label fw-semibold text-success" for="opt_acc_bab<?= $row['id']; ?>">
                                                                            <i class="bi bi-check-circle-fill me-1"></i> ACC Bab Ini
                                                                        </label>
                                                                        <small class="d-block text-muted">Naskah bab ini disetujui, mahasiswa boleh lanjut ke bab berikutnya.</small>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <div class="form-check p-3 border rounded h-100 bg-white">
                                                                        <input class="form-check-input" type="radio" name="keputusan_acc" id="opt_revisi<?= $row['id']; ?>" value="revisi" <?= ($row['status'] === 'revisi') ? 'checked' : ''; ?>>
                                                                        <label class="form-check-label fw-semibold text-warning" for="opt_revisi<?= $row['id']; ?>">
                                                                            <i class="bi bi-pencil-fill me-1"></i> Perlu Revisi Naskah
                                                                        </label>
                                                                        <small class="d-block text-muted">Mahasiswa wajib memperbaiki draft naskah berdasarkan catatan dosen.</small>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <div class="form-check p-3 border rounded h-100 bg-white">
                                                                        <input class="form-check-input" type="radio" name="keputusan_acc" id="opt_acc_sidang<?= $row['id']; ?>" value="acc_sidang">
                                                                        <label class="form-check-label fw-semibold text-primary" for="opt_acc_sidang<?= $row['id']; ?>">
                                                                            <i class="bi bi-mortarboard-fill me-1"></i> ACC Maju Sidang Skripsi
                                                                        </label>
                                                                        <small class="d-block text-muted">Seluruh draft naskah tuntas, mahasiswa dinyatakan Siap Sidang.</small>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <div class="form-check p-3 border rounded h-100 bg-white">
                                                                        <input class="form-check-input" type="radio" name="keputusan_acc" id="opt_selesai<?= $row['id']; ?>" value="selesai" <?= ($row['status'] === 'selesai' || empty($row['status'])) ? 'checked' : ''; ?>>
                                                                        <label class="form-check-label fw-semibold text-dark" for="opt_selesai<?= $row['id']; ?>">
                                                                            <i class="bi bi-check2-circle me-1"></i> Selesai Sesi Bimbingan
                                                                        </label>
                                                                        <small class="d-block text-muted">Sesi konsultasi ditutup tanpa pengesahan status baru.</small>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <!-- 3. Catatan Revisi & Lembar Umpan Balik -->
                                                        <div class="mb-3">
                                                            <label for="catatan_dosen_<?= $row['id']; ?>" class="form-label fw-bold">
                                                                Catatan Umpan Balik / Lembar Koreksi Revisi Dosen:
                                                            </label>
                                                            <textarea name="catatan_dosen" 
                                                                      id="catatan_dosen_<?= $row['id']; ?>" 
                                                                      class="form-control" 
                                                                      rows="4" 
                                                                      placeholder="Contoh: Koreksi penulisan kutipan di Bab 2, perbaiki diagram perancangan database pada Bab 3..."><?= htmlspecialchars($row['catatan_dosen'] ?? ''); ?></textarea>
                                                            <div class="form-text small">Catatan ini akan langsung terbaca oleh mahasiswa di dashboard dan halaman bimbingan mereka.</div>
                                                        </div>
                                                    </div>

                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                                        <button type="submit" class="btn btn-primary fw-semibold px-4">
                                                            <i class="bi bi-save me-1"></i> Simpan Evaluasi & Nilai
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
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

<?php
require_once '../templates/footer.php';
?>
