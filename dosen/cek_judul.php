<?php
// dosen/cek_judul.php - Pencarian & Deteksi Kemiripan Judul Skripsi Mahasiswa
require_once __DIR__ . '/../config/database.php';

// Validasi otentikasi role dosen
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'dosen') {
    header("Location: ../login");
    exit;
}

$user_id = $_SESSION['user_id'];
$stmt_dosen = $pdo->prepare("SELECT * FROM dosen WHERE user_id = :uid LIMIT 1");
$stmt_dosen->execute([':uid' => $user_id]);
$dosen = $stmt_dosen->fetch();
$dosen_id = $dosen['id'] ?? 0;

$page_title   = "Cek Kemiripan Judul Skripsi";
$current_page = "cek_judul";

// Parameter pencarian & deteksi
$input_q       = trim($_GET['q'] ?? '');
$search_repo   = trim($_GET['search_repo'] ?? '');
$filter_status = trim($_GET['status'] ?? 'semua');

// 1. Eksekusi Analisis Kemiripan jika ada parameter q
$hasil_analisis = [];
$skor_tertinggi = 0;
$rekomendasi    = null;

if (!empty($input_q)) {
    $hasil_analisis = cari_duplikasi_judul($pdo, $input_q);
    if (!empty($hasil_analisis)) {
        $skor_tertinggi = $hasil_analisis[0]['skor'];
        if ($skor_tertinggi >= 70) {
            $rekomendasi = [
                'status'  => 'danger',
                'badge'   => 'Indikasi Duplikasi Tinggi',
                'pesan'   => 'PERINGATAN: Judul yang diuji memiliki kemiripan sangat tinggi (' . $skor_tertinggi . '%) dengan skripsi yang sudah terdaftar. Dosen pembimbing disarankan untuk meminta mahasiswa MEREVISI judul atau mengganti objek/metode penelitian agar tidak terjadi plagiasi karya ilmiah.'
            ];
        } elseif ($skor_tertinggi >= 40) {
            $rekomendasi = [
                'status'  => 'warning',
                'badge'   => 'Potensi Kemiripan Topik / Metode',
                'pesan'   => 'PERHATIAN: Ditemukan kemiripan sedang (' . $skor_tertinggi . '%). Silakan tinjau perbedaan batasan masalah, kontribusi penelitian, atau studi kasus antara mahasiswa bersangkutan dengan skripsi pembanding.'
            ];
        } else {
            $rekomendasi = [
                'status'  => 'success',
                'badge'   => 'Judul Orisinal & Aman',
                'pesan'   => 'AMAN: Judul ini memiliki tingkat keunikan tinggi (kemiripan tertinggi hanya ' . $skor_tertinggi . '%). Judul layak untuk disetujui (ACC).'
            ];
        }
    } else {
        $rekomendasi = [
            'status'  => 'success',
            'badge'   => 'Judul Sangat Unik',
            'pesan'   => 'Belum ada data skripsi terdaftar yang mirip. Judul orisinal dan dapat disetujui.'
        ];
    }
}

// 2. Query Repositori Seluruh Judul Skripsi Terdaftar
$sql_repo = "
    SELECT m.id, m.nim, m.nama_lengkap, m.judul_skripsi, m.status_kelulusan, m.kelas,
           d.nama_dosen AS pembimbing
    FROM mahasiswa m
    LEFT JOIN dosen d ON m.dosen_pembimbing_id = d.id
    WHERE m.judul_skripsi IS NOT NULL 
      AND m.judul_skripsi != '' 
      AND m.judul_skripsi != 'Belum Ditentukan'
";
$params_repo = [];

if (!empty($search_repo)) {
    $sql_repo .= " AND (m.judul_skripsi LIKE :sr1 OR m.nama_lengkap LIKE :sr2 OR m.nim LIKE :sr3)";
    $params_repo[':sr1'] = "%{$search_repo}%";
    $params_repo[':sr2'] = "%{$search_repo}%";
    $params_repo[':sr3'] = "%{$search_repo}%";
}

if ($filter_status !== 'semua' && in_array($filter_status, ['proses_bimbingan', 'siap_sidang', 'lulus'])) {
    $sql_repo .= " AND m.status_kelulusan = :st_k";
    $params_repo[':st_k'] = $filter_status;
}

$sql_repo .= " ORDER BY m.id DESC";
$stmt_repo = $pdo->prepare($sql_repo);
$stmt_repo->execute($params_repo);
$repo_list = $stmt_repo->fetchAll();

// Metrik Judul
$total_judul_terdaftar = count($repo_list);

require_once '../templates/header.php';
require_once '../templates/sidebar_dosen.php';
?>

<div class="container-fluid px-0">
    <!-- Header Halaman -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h3 class="fw-bold mb-1 text-dark">
                <i class="bi bi-shield-check text-primary me-2"></i>Pencarian & Deteksi Kesamaan Judul Skripsi
            </h3>
            <p class="text-muted mb-0 small">
                Uji orisinalitas judul skripsi mahasiswa, deteksi duplikasi topik, dan telusuri arsip skripsi akademik.
            </p>
        </div>
        <div>
            <a href="riwayat_bimbingan.php" class="btn btn-outline-secondary btn-sm rounded shadow-sm">
                <i class="bi bi-arrow-left me-1"></i> Kembali ke Riwayat & Evaluasi
            </a>
        </div>
    </div>

    <!-- KARTU 1: ALAT DETEKSI KEMIRIPAN JUDUL -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <h5 class="fw-bold text-dark mb-2">
                <i class="bi bi-search-heart text-danger me-2"></i>Uji Kemiripan & Deteksi Duplikasi Judul
            </h5>
            <p class="text-muted small mb-3">
                Ketik atau tempelkan draf judul skripsi mahasiswa untuk membandingkannya secara otomatis dengan seluruh skripsi yang telah terdaftar di database.
            </p>

            <form action="cek_judul.php" method="GET" class="mb-2">
                <div class="input-group input-group-lg shadow-sm rounded-3 overflow-hidden">
                    <span class="input-group-text bg-white border-end-0 text-muted">
                        <i class="bi bi-pencil-square text-primary fs-5"></i>
                    </span>
                    <input type="text" 
                           name="q" 
                           class="form-control border-start-0 ps-0" 
                           placeholder="Contoh: Rancang Bangun Sistem Informasi Antrean Rumah Sakit Berbasis Web..." 
                           value="<?= htmlspecialchars($input_q); ?>" 
                           required>
                    <button class="btn btn-primary px-4 fw-semibold" type="submit">
                        <i class="bi bi-shield-shaded me-1"></i> Periksa Kemiripan
                    </button>
                    <?php if (!empty($input_q)): ?>
                        <a href="cek_judul.php" class="btn btn-outline-secondary px-3" title="Reset Pencarian">
                            <i class="bi bi-x-lg"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if (!empty($input_q)): ?>
                <div class="mt-4">
                    <!-- Banner Hasil Rekomendasi Dosen -->
                    <?php if ($rekomendasi): ?>
                        <div class="alert alert-<?= $rekomendasi['status']; ?> border-0 shadow-sm rounded-3 p-3 mb-4">
                            <div class="d-flex align-items-start gap-3">
                                <div class="fs-2">
                                    <?php if ($rekomendasi['status'] === 'danger'): ?>
                                        <i class="bi bi-exclamation-octagon-fill text-danger"></i>
                                    <?php elseif ($rekomendasi['status'] === 'warning'): ?>
                                        <i class="bi bi-exclamation-triangle-fill text-warning"></i>
                                    <?php else: ?>
                                        <i class="bi bi-check-circle-fill text-success"></i>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="badge bg-<?= $rekomendasi['status']; ?> text-uppercase px-2 py-1">
                                            <?= $rekomendasi['badge']; ?>
                                        </span>
                                        <span class="fw-bold text-dark">Skor Kemiripan Tertinggi: <?= $skor_tertinggi; ?>%</span>
                                    </div>
                                    <div class="small text-dark mb-0">
                                        <?= $rekomendasi['pesan']; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Daftar Hasil Perbandingan -->
                    <h6 class="fw-bold text-dark mb-3">
                        <i class="bi bi-bar-chart-steps text-primary me-2"></i>Peringkat Kemiripan dengan Judul Terdaftar:
                    </h6>

                    <?php if (empty($hasil_analisis)): ?>
                        <div class="text-center py-4 text-muted bg-light rounded-3">
                            <i class="bi bi-inbox fs-2 d-block mb-1"></i>
                            Tidak ada data skripsi pembanding di repositori.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle border mb-0 small">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 140px;">Tingkat Kemiripan</th>
                                        <th>Judul Pembanding Terdaftar</th>
                                        <th>Mahasiswa & NIM</th>
                                        <th>Pembimbing</th>
                                        <th class="text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($hasil_analisis, 0, 10) as $res): ?>
                                        <?php
                                        $bar_color = ($res['skor'] >= 70) ? 'bg-danger' : (($res['skor'] >= 40) ? 'bg-warning' : 'bg-success');
                                        $text_badge = ($res['skor'] >= 70) ? 'text-danger fw-bold' : (($res['skor'] >= 40) ? 'text-warning-emphasis fw-bold' : 'text-success fw-bold');
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex justify-content-between align-items-center mb-1">
                                                    <span class="<?= $text_badge; ?>"><?= $res['skor']; ?>%</span>
                                                    <small class="text-muted" style="font-size: 0.7rem;">
                                                        <?= ($res['skor'] >= 70) ? 'Tinggi' : (($res['skor'] >= 40) ? 'Sedang' : 'Rendah'); ?>
                                                    </small>
                                                </div>
                                                <div class="progress" style="height: 6px;">
                                                    <div class="progress-bar <?= $bar_color; ?>" role="progressbar" style="width: <?= $res['skor']; ?>%;"></div>
                                                </div>
                                                <?php if (!empty($res['kata_kunci_sama'])): ?>
                                                    <div class="mt-1" style="font-size: 0.68rem;">
                                                        <span class="text-muted">Kata kunci sama:</span>
                                                        <span class="badge bg-light text-dark border"><?= implode(', ', array_slice($res['kata_kunci_sama'], 0, 3)); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="fw-semibold text-dark"><?= htmlspecialchars($res['judul_skripsi']); ?></span>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($res['nama_lengkap']); ?></strong><br>
                                                <small class="text-muted">NIM: <?= htmlspecialchars($res['nim']); ?></small>
                                            </td>
                                            <td>
                                                <small class="text-muted"><?= htmlspecialchars($res['pembimbing']); ?></small>
                                            </td>
                                            <td class="text-center">
                                                <?php
                                                $stk = $res['status_kelulusan'];
                                                if ($stk === 'siap_sidang') echo '<span class="badge bg-info text-dark">Siap Sidang</span>';
                                                elseif ($stk === 'lulus') echo '<span class="badge bg-success">Lulus</span>';
                                                else echo '<span class="badge bg-secondary">Bimbingan Aktif</span>';
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- KARTU 2: REPOSITORI & KATALOG SELURUH JUDUL SKRIPSI -->
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="bi bi-journal-album text-primary me-2"></i>Katalog Repositori Judul Skripsi Terdaftar
                </h5>
                <small class="text-muted">Total <?= count($repo_list); ?> judul skripsi resmi tersimpan dalam sistem.</small>
            </div>
            <!-- Form Filter Repositori -->
            <form action="cek_judul.php" method="GET" class="d-flex align-items-center gap-2 flex-wrap">
                <?php if (!empty($input_q)): ?>
                    <input type="hidden" name="q" value="<?= htmlspecialchars($input_q); ?>">
                <?php endif; ?>
                <div class="input-group input-group-sm" style="width: 240px;">
                    <input type="text" name="search_repo" class="form-control" placeholder="Cari nama, nim, judul..." value="<?= htmlspecialchars($search_repo); ?>">
                    <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
                </div>
                <select name="status" class="form-select form-select-sm" style="width: 150px;" onchange="this.form.submit()">
                    <option value="semua" <?= ($filter_status === 'semua') ? 'selected' : ''; ?>>Semua Status</option>
                    <option value="proses_bimbingan" <?= ($filter_status === 'proses_bimbingan') ? 'selected' : ''; ?>>Bimbingan Aktif</option>
                    <option value="siap_sidang" <?= ($filter_status === 'siap_sidang') ? 'selected' : ''; ?>>Siap Sidang</option>
                    <option value="lulus" <?= ($filter_status === 'lulus') ? 'selected' : ''; ?>>Lulus</option>
                </select>
            </form>
        </div>

        <div class="card-body p-0">
            <?php if (empty($repo_list)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-folder-x fs-1 d-block mb-2 text-secondary"></i>
                    Belum ada data judul skripsi yang sesuai dengan kriteria pencarian.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 50px;" class="text-center">No</th>
                                <th>Judul Skripsi</th>
                                <th>Mahasiswa</th>
                                <th>Pembimbing</th>
                                <th class="text-center" style="width: 130px;">Status</th>
                                <th class="text-center" style="width: 130px;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $no = 1; foreach ($repo_list as $row): ?>
                                <tr>
                                    <td class="text-center text-muted fw-semibold"><?= $no++; ?></td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($row['judul_skripsi']); ?></div>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($row['nama_lengkap']); ?></strong><br>
                                        <span class="text-muted" style="font-size: 0.75rem;">NIM: <?= htmlspecialchars($row['nim']); ?> (<?= htmlspecialchars($row['kelas'] ?? 'TI'); ?>)</span>
                                    </td>
                                    <td>
                                        <span class="text-muted"><?= htmlspecialchars($row['pembimbing'] ?? 'Belum Ditentukan'); ?></span>
                                    </td>
                                    <td class="text-center">
                                        <?php
                                        $stk = $row['status_kelulusan'];
                                        if ($stk === 'siap_sidang') echo '<span class="badge bg-info text-dark">Siap Sidang</span>';
                                        elseif ($stk === 'lulus') echo '<span class="badge bg-success">Lulus</span>';
                                        else echo '<span class="badge bg-secondary">Bimbingan</span>';
                                        ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="cek_judul.php?q=<?= urlencode($row['judul_skripsi']); ?>" class="btn btn-xs btn-outline-primary btn-sm py-1 px-2" title="Uji Kemiripan Judul Ini">
                                            <i class="bi bi-shield-check me-1"></i>Uji Judul
                                        </a>
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

<?php
require_once '../templates/footer.php';
?>

