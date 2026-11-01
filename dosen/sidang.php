<?php
// dosen/sidang.php - Jadwal & Penilaian Sidang Skripsi (Salsa - Integrasi Update Robi)
$page_title   = "Jadwal & Penilaian Sidang Skripsi";
$current_page = "sidang";

require_once '../config/database.php';
require_once '../templates/header.php';
require_once '../templates/sidebar_dosen.php';

// Proteksi akses role dosen
if ($_SESSION['role'] !== 'dosen') {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$stmt_dosen = $pdo->prepare("SELECT * FROM dosen WHERE user_id = :uid LIMIT 1");
$stmt_dosen->execute([':uid' => $user_id]);
$dosen = $stmt_dosen->fetch();
$dosen_id = $dosen['id'] ?? 0;

$success_msg = '';
$error_msg   = '';

// Proses Input / Perbarui Nilai Sidang oleh Dosen Penguji
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_nilai_sidang'])) {
    $sidang_id      = (int)($_POST['sidang_id'] ?? 0);
    $nilai_penguji  = (float)($_POST['nilai_penguji'] ?? 0);
    $catatan_sidang = trim($_POST['catatan_sidang'] ?? '');
    $status_sidang  = trim($_POST['status_sidang'] ?? 'lulus');

    if ($nilai_penguji < 0) $nilai_penguji = 0;
    if ($nilai_penguji > 100) $nilai_penguji = 100;

    if ($sidang_id <= 0) {
        $error_msg = "Data sidang skripsi tidak valid.";
    } else {
        try {
            // Ambil data sidang untuk verifikasi posisi penguji
            $stmt_cek = $pdo->prepare("SELECT * FROM sidang_skripsi WHERE id = :sid LIMIT 1");
            $stmt_cek->execute([':sid' => $sidang_id]);
            $sidang_data = $stmt_cek->fetch();

            if (!$sidang_data) {
                $error_msg = "Sidang skripsi tidak ditemukan.";
            } else {
                $pdo->beginTransaction();

                // Tentukan kolom nilai mana yang diisi dosen ini
                $kolom_nilai = '';
                if ($dosen_id == $sidang_data['penguji_1_id']) {
                    $kolom_nilai = 'nilai_penguji_1';
                    $sidang_data['nilai_penguji_1'] = $nilai_penguji;
                } elseif ($dosen_id == $sidang_data['penguji_2_id']) {
                    $kolom_nilai = 'nilai_penguji_2';
                    $sidang_data['nilai_penguji_2'] = $nilai_penguji;
                } elseif ($dosen_id == $sidang_data['penguji_3_id']) {
                    $kolom_nilai = 'nilai_penguji_3';
                    $sidang_data['nilai_penguji_3'] = $nilai_penguji;
                }

                if (empty($kolom_nilai)) {
                    $pdo->rollBack();
                    $error_msg = "Anda tidak terdaftar sebagai dosen penguji pada sidang ini.";
                } else {
                    // Hitung nilai akhir rata-rata dari nilai yang sudah masuk
                    $scores = [];
                    if (!is_null($sidang_data['nilai_penguji_1']) && $sidang_data['nilai_penguji_1'] > 0) $scores[] = (float)$sidang_data['nilai_penguji_1'];
                    if (!is_null($sidang_data['nilai_penguji_2']) && $sidang_data['nilai_penguji_2'] > 0) $scores[] = (float)$sidang_data['nilai_penguji_2'];
                    if (!is_null($sidang_data['nilai_penguji_3']) && $sidang_data['nilai_penguji_3'] > 0) $scores[] = (float)$sidang_data['nilai_penguji_3'];
                    
                    $nilai_akhir = count($scores) > 0 ? round(array_sum($scores) / count($scores), 2) : $nilai_penguji;

                    // Update nilai dan catatan sidang
                    $sql_up = "UPDATE sidang_skripsi 
                               SET {$kolom_nilai} = :nil, 
                                   nilai_akhir = :nak, 
                                   status_sidang = :st_sdg, 
                                   catatan_sidang = :cat 
                               WHERE id = :sid";
                    $stmt_up_sdg = $pdo->prepare($sql_up);
                    $stmt_up_sdg->execute([
                        ':nil'    => $nilai_penguji,
                        ':nak'    => $nilai_akhir,
                        ':st_sdg' => $status_sidang,
                        ':cat'    => $catatan_sidang,
                        ':sid'    => $sidang_id
                    ]);

                    // Jika status sidang lulus, perbarui status kelulusan di tabel mahasiswa
                    if ($status_sidang === 'lulus') {
                        $stmt_up_m = $pdo->prepare("UPDATE mahasiswa SET status_kelulusan = 'lulus' WHERE id = :mid");
                        $stmt_up_m->execute([':mid' => $sidang_data['mahasiswa_id']]);
                    }

                    // Kirim notifikasi ke mahasiswa
                    try {
                        $st_m_u = $pdo->prepare("SELECT user_id FROM mahasiswa WHERE id = :mid LIMIT 1");
                        $st_m_u->execute([':mid' => $sidang_data['mahasiswa_id']]);
                        $m_uid = $st_m_u->fetchColumn();
                        if ($m_uid) {
                            $st_not = $pdo->prepare("INSERT INTO notifikasi (user_id, judul, pesan, tipe, link_tujuan) VALUES (:uid, :jdl, :psn, 'sidang', 'informasi_sidang.php')");
                            $st_not->execute([
                                ':uid' => $m_uid,
                                ':jdl' => 'Pembaruan Nilai Sidang Skripsi',
                                ':psn' => "Dewan penguji telah menginput evaluasi sidang Anda dengan nilai {$nilai_penguji} (Status: " . ucfirst($status_sidang) . ")."
                            ]);
                        }
                    } catch (Exception $ne) {}

                    $pdo->commit();
                    $success_msg = "Nilai ujian sidang ({$nilai_penguji}) berhasil disimpan. Nilai rata-rata dewan penguji saat ini: <strong>{$nilai_akhir}</strong>.";
                }
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_msg = "Gagal menyimpan nilai sidang: " . $e->getMessage();
        }
    }
}

// 1. Ambil Mahasiswa yang Diuji (Sebagai Dewan Penguji)
$sql_penguji = "
    SELECT s.*, 
           m.nim, m.nama_lengkap, m.kelas, m.judul_skripsi,
           dp.nama_dosen AS nama_pembimbing,
           p1.nama_dosen AS nama_penguji_1,
           p2.nama_dosen AS nama_penguji_2,
           p3.nama_dosen AS nama_penguji_3
    FROM sidang_skripsi s
    JOIN mahasiswa m ON s.mahasiswa_id = m.id
    LEFT JOIN dosen dp ON s.dosen_pembimbing_id = dp.id
    LEFT JOIN dosen p1 ON s.penguji_1_id = p1.id
    LEFT JOIN dosen p2 ON s.penguji_2_id = p2.id
    LEFT JOIN dosen p3 ON s.penguji_3_id = p3.id
    WHERE s.penguji_1_id = :did1 OR s.penguji_2_id = :did2 OR s.penguji_3_id = :did3
    ORDER BY s.tanggal_sidang ASC, s.jam_sidang ASC
";
$stmt_p = $pdo->prepare($sql_penguji);
$stmt_p->execute([
    ':did1' => $dosen_id,
    ':did2' => $dosen_id,
    ':did3' => $dosen_id
]);
$daftar_diuji = $stmt_p->fetchAll();

// 2. Ambil Mahasiswa Bimbingan yang Dijadwalkan Sidang (Sebagai Pembimbing)
$sql_bimbingan_sidang = "
    SELECT s.*, 
           m.nim, m.nama_lengkap, m.kelas, m.judul_skripsi,
           p1.nama_dosen AS nama_penguji_1,
           p2.nama_dosen AS nama_penguji_2,
           p3.nama_dosen AS nama_penguji_3
    FROM sidang_skripsi s
    JOIN mahasiswa m ON s.mahasiswa_id = m.id
    LEFT JOIN dosen p1 ON s.penguji_1_id = p1.id
    LEFT JOIN dosen p2 ON s.penguji_2_id = p2.id
    LEFT JOIN dosen p3 ON s.penguji_3_id = p3.id
    WHERE s.dosen_pembimbing_id = :did_pem
    ORDER BY s.tanggal_sidang DESC, s.id DESC
";
$stmt_b = $pdo->prepare($sql_bimbingan_sidang);
$stmt_b->execute([':did_pem' => $dosen_id]);
$daftar_bimbingan_sidang = $stmt_b->fetchAll();

$tab_active = $_GET['tab'] ?? 'penguji';
?>

<!-- Header Halaman -->
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
    <div>
        <h1 class="h3 fw-bold mb-1">Jadwal & Penilaian Sidang Skripsi</h1>
        <p class="text-muted small mb-0">Pantau tugas sebagai Dewan Penguji sidang, jadwal ujian mahasiswa bimbingan, input nilai ujian, dan ketentuan sidang.</p>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="mahasiswa_bimbingan.php" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-people me-1"></i> Data Bimbingan
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

<!-- Tabs Navigasi: Tugas Penguji vs Mahasiswa Bimbingan -->
<ul class="nav nav-pills mb-4 gap-2" id="sidangTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <a class="nav-link <?= ($tab_active === 'penguji') ? 'active fw-bold' : 'text-dark bg-white border'; ?>" 
           href="sidang.php?tab=penguji">
            <i class="bi bi-award-fill me-2"></i>Tugas Dewan Penguji
            <span class="badge bg-danger rounded-pill ms-2"><?= count($daftar_diuji); ?></span>
        </a>
    </li>
    <li class="nav-item" role="presentation">
        <a class="nav-link <?= ($tab_active === 'bimbingan') ? 'active fw-bold' : 'text-dark bg-white border'; ?>" 
           href="sidang.php?tab=bimbingan">
            <i class="bi bi-mortarboard-fill me-2"></i>Mahasiswa Bimbingan Sidang
            <span class="badge bg-secondary rounded-pill ms-2"><?= count($daftar_bimbingan_sidang); ?></span>
        </a>
    </li>
</ul>

<?php if ($tab_active === 'penguji'): ?>
    <!-- TAB 1: TUGAS SEBAGAI DEWAN PENGUJI -->
    <div class="card shadow-sm border-0 mb-4 bg-white">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold text-dark">
                <i class="bi bi-person-video3 text-primary me-2"></i>Daftar Sidang: Anda Sebagai Dewan Penguji
            </h6>
            <span class="badge bg-primary"><?= count($daftar_diuji); ?> Jadwal Ujian</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 45px;" class="text-center">No</th>
                            <th style="min-width: 200px;">Mahasiswa & Judul Final</th>
                            <th>Posisi Penguji</th>
                            <th>Jadwal & Ruangan</th>
                            <th>Pembimbing</th>
                            <th class="text-center">Nilai Ujian</th>
                            <th class="text-center">Status</th>
                            <th style="width: 140px;" class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($daftar_diuji)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">
                                    <i class="bi bi-mortarboard fs-1 d-block mb-2 text-muted"></i>
                                    Belum ada penugasan dewan penguji sidang untuk Anda.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($daftar_diuji as $i => $row): ?>
                                <?php
                                $posisi = '';
                                $my_score = null;
                                if ($dosen_id == $row['penguji_1_id']) {
                                    $posisi = '<span class="badge bg-primary text-white">Ketua Penguji (Penguji 1)</span>';
                                    $my_score = $row['nilai_penguji_1'];
                                } elseif ($dosen_id == $row['penguji_2_id']) {
                                    $posisi = '<span class="badge bg-info text-dark">Penguji 2</span>';
                                    $my_score = $row['nilai_penguji_2'];
                                } elseif ($dosen_id == $row['penguji_3_id']) {
                                    $posisi = '<span class="badge bg-secondary text-white">Sekretaris (Penguji 3)</span>';
                                    $my_score = $row['nilai_penguji_3'];
                                }
                                ?>
                                <tr>
                                    <td class="text-center fw-semibold text-muted"><?= $i + 1; ?></td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($row['nama_lengkap']); ?></div>
                                        <div class="small text-muted">
                                            <span>NIM: <?= htmlspecialchars($row['nim']); ?></span>
                                            <?php if (!empty($row['kelas'])): ?>
                                                <span class="badge bg-light text-primary border border-primary-subtle ms-1"><?= htmlspecialchars($row['kelas']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="small text-secondary mt-1 text-truncate" style="max-width: 250px;" title="<?= htmlspecialchars($row['judul_final'] ?: $row['judul_skripsi']); ?>">
                                            <i class="bi bi-journal-text me-1 text-primary"></i><?= htmlspecialchars($row['judul_final'] ?: $row['judul_skripsi']); ?>
                                        </div>
                                    </td>
                                    <td><?= $posisi; ?></td>
                                    <td>
                                        <div class="fw-semibold text-dark">
                                            <i class="bi bi-calendar-event text-primary me-1"></i>
                                            <?= !empty($row['tanggal_sidang']) ? date('d M Y', strtotime($row['tanggal_sidang'])) : 'Belum Dijadwalkan'; ?>
                                        </div>
                                        <div class="small text-muted">
                                            <i class="bi bi-clock me-1"></i><?= substr($row['jam_sidang'] ?? '--:--', 0, 5); ?> WIB
                                        </div>
                                        <div class="small text-primary fw-semibold mt-1">
                                            <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($row['ruangan'] ?? 'Ruang Belum Diplot'); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="small fw-semibold text-dark"><?= htmlspecialchars($row['nama_pembimbing'] ?? '-'); ?></span>
                                    </td>
                                    <td class="text-center">
                                        <?php if (!is_null($my_score) && $my_score > 0): ?>
                                            <span class="badge bg-success fs-6 px-2 py-1"><?= $my_score; ?></span>
                                            <small class="d-block text-muted" style="font-size: 0.72rem;">Nilai Anda</small>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Belum Dinilai</span>
                                        <?php endif; ?>
                                        <?php if (!is_null($row['nilai_akhir']) && $row['nilai_akhir'] > 0): ?>
                                            <small class="d-block text-primary fw-bold mt-1">Akhir: <?= $row['nilai_akhir']; ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php
                                        $st = $row['status_sidang'];
                                        if ($st === 'lulus') echo '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Lulus</span>';
                                        elseif ($st === 'revisi') echo '<span class="badge bg-warning text-dark"><i class="bi bi-arrow-repeat me-1"></i>Revisi</span>';
                                        elseif ($st === 'dijadwalkan') echo '<span class="badge bg-info text-dark"><i class="bi bi-calendar-check me-1"></i>Dijadwalkan</span>';
                                        elseif ($st === 'tidak_lulus') echo '<span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Tidak Lulus</span>';
                                        else echo '<span class="badge bg-secondary">' . ucfirst($st) . '</span>';
                                        ?>
                                    </td>
                                    <td class="text-center">
                                        <button type="button" 
                                                class="btn btn-sm btn-primary fw-semibold" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#modalNilaiSidang<?= $row['id']; ?>"
                                                title="Input atau Perbarui Nilai Penguji">
                                            <i class="bi bi-pencil-square me-1"></i>Beri Nilai
                                        </button>

                                        <!-- MODAL INPUT / PERBARUI NILAI SIDANG -->
                                        <div class="modal fade" id="modalNilaiSidang<?= $row['id']; ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog modal-lg text-start">
                                                <div class="modal-content">
                                                    <form method="POST" action="sidang.php?tab=penguji">
                                                        <input type="hidden" name="simpan_nilai_sidang" value="1">
                                                        <input type="hidden" name="sidang_id" value="<?= $row['id']; ?>">

                                                        <div class="modal-header bg-primary text-white">
                                                            <h5 class="modal-title fw-bold">
                                                                <i class="bi bi-award me-2"></i>Penilaian Sidang Skripsi: <?= htmlspecialchars($row['nama_lengkap']); ?>
                                                            </h5>
                                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body p-4">
                                                            <div class="p-3 bg-light rounded border mb-3 small">
                                                                <div class="row g-2">
                                                                    <div class="col-md-6">
                                                                        <span class="text-muted d-block">Mahasiswa:</span>
                                                                        <strong><?= htmlspecialchars($row['nama_lengkap']); ?></strong> (<?= htmlspecialchars($row['nim']); ?>)
                                                                    </div>
                                                                    <div class="col-md-6">
                                                                        <span class="text-muted d-block">Peran Anda:</span>
                                                                        <?= $posisi; ?>
                                                                    </div>
                                                                    <div class="col-12 mt-2">
                                                                        <span class="text-muted d-block">Judul Final Skripsi:</span>
                                                                        <span class="fw-semibold text-dark"><?= htmlspecialchars($row['judul_final'] ?: $row['judul_skripsi']); ?></span>
                                                                    </div>
                                                                    <?php if (!empty($row['ketentuan_sidang'])): ?>
                                                                        <div class="col-12 mt-2">
                                                                            <span class="text-muted d-block">Ketentuan Sidang:</span>
                                                                            <span class="text-secondary"><?= htmlspecialchars($row['ketentuan_sidang']); ?></span>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>

                                                            <div class="row g-3">
                                                                <div class="col-md-6">
                                                                    <label class="form-label fw-bold small text-dark">
                                                                        <i class="bi bi-speedometer text-primary me-1"></i>Nilai Ujian Anda (0 - 100) *
                                                                    </label>
                                                                    <input type="number" step="0.5" min="0" max="100" name="nilai_penguji" class="form-control fw-bold fs-5 text-primary" 
                                                                           value="<?= !is_null($my_score) ? $my_score : '80'; ?>" required>
                                                                    <div class="form-text small">Masukkan nilai sidang dari aspek penyajian dan penguasaan materi.</div>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label class="form-label fw-bold small text-dark">
                                                                        <i class="bi bi-check-circle text-primary me-1"></i>Keputusan Status Sidang *
                                                                    </label>
                                                                    <select name="status_sidang" class="form-select" required>
                                                                        <option value="lulus" <?= ($row['status_sidang'] === 'lulus') ? 'selected' : ''; ?>>Lulus Ujian Sidang</option>
                                                                        <option value="revisi" <?= ($row['status_sidang'] === 'revisi') ? 'selected' : ''; ?>>Lulus dengan Revisi</option>
                                                                        <option value="dijadwalkan" <?= ($row['status_sidang'] === 'dijadwalkan') ? 'selected' : ''; ?>>Sedang / Dijadwalkan</option>
                                                                        <option value="tidak_lulus" <?= ($row['status_sidang'] === 'tidak_lulus') ? 'selected' : ''; ?>>Tidak Lulus (Ujian Ulang)</option>
                                                                    </select>
                                                                </div>
                                                                <div class="col-12">
                                                                    <label class="form-label fw-bold small text-dark">
                                                                        <i class="bi bi-chat-left-text text-primary me-1"></i>Catatan Masukan / Koreksi Penguji
                                                                    </label>
                                                                    <textarea name="catatan_sidang" class="form-control" rows="3" placeholder="Tuliskan catatan perbaikan atau feedback untuk mahasiswa..."><?= htmlspecialchars($row['catatan_sidang'] ?? ''); ?></textarea>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer bg-light">
                                                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                                                            <button type="submit" class="btn btn-primary btn-sm fw-semibold">
                                                                <i class="bi bi-save me-1"></i> Simpan Nilai Sidang
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
<?php else: ?>
    <!-- TAB 2: MAHASISWA BIMBINGAN YANG DIJADWALKAN SIDANG -->
    <div class="card shadow-sm border-0 mb-4 bg-white">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold text-dark">
                <i class="bi bi-mortarboard-fill text-primary me-2"></i>Mahasiswa Bimbingan Anda yang Masuk Tahap Sidang
            </h6>
            <span class="badge bg-secondary"><?= count($daftar_bimbingan_sidang); ?> Mahasiswa</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 45px;" class="text-center">No</th>
                            <th>Mahasiswa & Judul Final</th>
                            <th>Jadwal & Ruangan</th>
                            <th>Dewan Penguji</th>
                            <th class="text-center">Nilai Akhir</th>
                            <th class="text-center">Status Sidang</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($daftar_bimbingan_sidang)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <i class="bi bi-clipboard-check fs-1 d-block mb-2 text-muted"></i>
                                    Belum ada mahasiswa bimbingan Anda yang dijadwalkan sidang skripsi.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($daftar_bimbingan_sidang as $i => $row): ?>
                                <tr>
                                    <td class="text-center fw-semibold text-muted"><?= $i + 1; ?></td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($row['nama_lengkap']); ?></div>
                                        <div class="small text-muted">
                                            <span>NIM: <?= htmlspecialchars($row['nim']); ?></span>
                                            <?php if (!empty($row['kelas'])): ?>
                                                <span class="badge bg-light text-primary border border-primary-subtle ms-1"><?= htmlspecialchars($row['kelas']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="small text-secondary mt-1 text-truncate" style="max-width: 250px;" title="<?= htmlspecialchars($row['judul_final'] ?: $row['judul_skripsi']); ?>">
                                            <i class="bi bi-journal-text me-1 text-primary"></i><?= htmlspecialchars($row['judul_final'] ?: $row['judul_skripsi']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark">
                                            <i class="bi bi-calendar-event text-primary me-1"></i>
                                            <?= !empty($row['tanggal_sidang']) ? date('d M Y', strtotime($row['tanggal_sidang'])) : 'Belum Dijadwalkan'; ?>
                                        </div>
                                        <div class="small text-muted">
                                            <i class="bi bi-clock me-1"></i><?= substr($row['jam_sidang'] ?? '--:--', 0, 5); ?> WIB
                                        </div>
                                        <div class="small text-primary fw-semibold mt-1">
                                            <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($row['ruangan'] ?? 'Ruang Belum Diplot'); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="small mb-1"><strong>Ketua:</strong> <?= htmlspecialchars($row['nama_penguji_1'] ?? 'Belum Diplot'); ?></div>
                                        <div class="small mb-1"><strong>Penguji 2:</strong> <?= htmlspecialchars($row['nama_penguji_2'] ?? 'Belum Diplot'); ?></div>
                                        <div class="small"><strong>Sekretaris:</strong> <?= htmlspecialchars($row['nama_penguji_3'] ?? 'Belum Diplot'); ?></div>
                                    </td>
                                    <td class="text-center">
                                        <?php if (!is_null($row['nilai_akhir']) && $row['nilai_akhir'] > 0): ?>
                                            <span class="badge bg-primary fs-6 px-3 py-1"><?= $row['nilai_akhir']; ?></span>
                                        <?php else: ?>
                                            <span class="text-muted small fst-italic">Menunggu Ujian</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php
                                        $st = $row['status_sidang'];
                                        if ($st === 'lulus') echo '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Lulus</span>';
                                        elseif ($st === 'revisi') echo '<span class="badge bg-warning text-dark"><i class="bi bi-arrow-repeat me-1"></i>Revisi</span>';
                                        elseif ($st === 'dijadwalkan') echo '<span class="badge bg-info text-dark"><i class="bi bi-calendar-check me-1"></i>Dijadwalkan</span>';
                                        elseif ($st === 'tidak_lulus') echo '<span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Tidak Lulus</span>';
                                        else echo '<span class="badge bg-secondary">' . ucfirst($st) . '</span>';
                                        ?>
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

<?php
require_once '../templates/footer.php';
?>

