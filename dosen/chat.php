<?php
// dosen/chat.php - Aplikasi Pesan Chat Dosen & Mahasiswa (Salsa)
$page_title   = "Pesan & Konsultasi Online";
$current_page = "chat";

require_once '../config/database.php';
require_once '../templates/header.php';
require_once '../templates/sidebar_dosen.php';

// Proteksi akses role dosen
if ($_SESSION['role'] !== 'dosen') {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Ambil identitas dosen aktif
$stmt_dosen = $pdo->prepare("SELECT * FROM dosen WHERE user_id = :uid LIMIT 1");
$stmt_dosen->execute([':uid' => $user_id]);
$dosen = $stmt_dosen->fetch();
$dosen_id = $dosen['id'] ?? 0;

// Ambil daftar mahasiswa bimbingan dosen ini
$stmt_mhs = $pdo->prepare("
    SELECT m.*, u.id AS mhs_user_id 
    FROM mahasiswa m 
    JOIN users u ON m.user_id = u.id 
    WHERE m.dosen_pembimbing_id = :did 
    ORDER BY m.nama_lengkap ASC
");
$stmt_mhs->execute([':did' => $dosen_id]);
$daftar_mahasiswa = $stmt_mhs->fetchAll();

// Tentukan mahasiswa yang sedang diajak chat (aktif)
$active_mhs_user_id = isset($_GET['uid']) ? (int)$_GET['uid'] : 0;
if ($active_mhs_user_id === 0 && !empty($daftar_mahasiswa)) {
    $active_mhs_user_id = (int)$daftar_mahasiswa[0]['mhs_user_id'];
}

// Cari detail mahasiswa aktif
$active_mhs = null;
foreach ($daftar_mahasiswa as $m) {
    if ($m['mhs_user_id'] == $active_mhs_user_id) {
        $active_mhs = $m;
        break;
    }
}

// Proses Kirim Pesan Baru
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kirim_pesan'])) {
    $penerima_id = (int)($_POST['penerima_id'] ?? 0);
    $isi_pesan   = trim($_POST['pesan'] ?? '');

    if (!empty($isi_pesan) && $penerima_id > 0) {
        try {
            $stmt_send = $pdo->prepare("
                INSERT INTO pesan_chat (pengirim_id, penerima_id, pesan, status_baca) 
                VALUES (:pengirim, :penerima, :pesan, 'belum_dibaca')
            ");
            $stmt_send->execute([
                ':pengirim' => $user_id,
                ':penerima' => $penerima_id,
                ':pesan'    => $isi_pesan
            ]);

            // Redirect agar tidak re-post formulir saat refresh
            header("Location: chat.php?uid=" . $penerima_id);
            exit;
        } catch (PDOException $e) {
            $chat_error = "Gagal mengirim pesan: " . $e->getMessage();
        }
    }
}

// Jika ada mahasiswa aktif, tandai semua pesan masuk dari mahasiswa tersebut sebagai 'dibaca'
if ($active_mhs_user_id > 0) {
    try {
        $stmt_read = $pdo->prepare("
            UPDATE pesan_chat 
            SET status_baca = 'dibaca' 
            WHERE pengirim_id = :mid AND penerima_id = :uid AND status_baca = 'belum_dibaca'
        ");
        $stmt_read->execute([':mid' => $active_mhs_user_id, ':uid' => $user_id]);
    } catch (PDOException $e) {
        // abaikan error minor
    }
}

// Ambil riwayat percakapan antara dosen dan mahasiswa aktif
$pesan_list = [];
if ($active_mhs_user_id > 0) {
    $stmt_chat = $pdo->prepare("
        SELECT * FROM pesan_chat 
        WHERE (pengirim_id = :uid1 AND penerima_id = :mid1) 
           OR (pengirim_id = :mid2 AND penerima_id = :uid2) 
        ORDER BY waktu_kirim ASC
    ");
    $stmt_chat->execute([
        ':uid1' => $user_id,
        ':mid1' => $active_mhs_user_id,
        ':mid2' => $active_mhs_user_id,
        ':uid2' => $user_id
    ]);
    $pesan_list = $stmt_chat->fetchAll();
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
    <div>
        <h1 class="h3 fw-bold mb-1">Pesan & Konsultasi Online</h1>
        <p class="text-muted small mb-0">Komunikasi akademik terpusat dengan mahasiswa tugas akhir bimbingan Anda secara real-time.</p>
    </div>
</div>

<div class="chat-window-box">
    <div class="row g-0">
        <!-- Kolom Kiri: Daftar Kontak Mahasiswa -->
        <div class="col-md-4 col-lg-4 border-end bg-light d-flex flex-column" style="height: 640px;">
            <div class="p-3 border-bottom bg-white d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-chat-dots-fill text-primary me-2"></i>Mahasiswa Bimbingan</h6>
                    <small class="text-muted"><?= count($daftar_mahasiswa); ?> Mahasiswa Terdaftar</small>
                </div>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Online</span>
            </div>

            <div class="overflow-auto flex-grow-1 list-group list-group-flush">
                <?php if (empty($daftar_mahasiswa)): ?>
                    <div class="text-center p-5 text-muted small">
                        <i class="bi bi-people fs-1 d-block mb-2 text-muted"></i>
                        Belum ada mahasiswa yang di-plot ke bimbingan Anda.
                    </div>
                <?php else: ?>
                    <?php foreach ($daftar_mahasiswa as $mhs): ?>
                        <?php
                        // Cek pesan terakhir & unread count
                        $stmt_last = $pdo->prepare("
                            SELECT pesan, waktu_kirim FROM pesan_chat 
                            WHERE (pengirim_id = :uid1 AND penerima_id = :mid1) 
                               OR (pengirim_id = :mid2 AND penerima_id = :uid2) 
                            ORDER BY waktu_kirim DESC LIMIT 1
                        ");
                        $stmt_last->execute([
                            ':uid1' => $user_id,
                            ':mid1' => $mhs['mhs_user_id'],
                            ':mid2' => $mhs['mhs_user_id'],
                            ':uid2' => $user_id
                        ]);
                        $last_msg = $stmt_last->fetch();

                        $stmt_unread = $pdo->prepare("
                            SELECT COUNT(*) FROM pesan_chat 
                            WHERE pengirim_id = :mid AND penerima_id = :uid AND status_baca = 'belum_dibaca'
                        ");
                        $stmt_unread->execute([':mid' => $mhs['mhs_user_id'], ':uid' => $user_id]);
                        $unread_count = (int)$stmt_unread->fetchColumn();

                        $is_active = ($mhs['mhs_user_id'] == $active_mhs_user_id);
                        ?>
                        <a href="chat.php?uid=<?= $mhs['mhs_user_id']; ?>" 
                           class="list-group-item list-group-item-action p-3 border-bottom <?= $is_active ? 'active shadow-sm' : ''; ?>"
                           style="<?= $is_active ? 'background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%) !important; border-color: transparent !important;' : ''; ?>">
                            <div class="d-flex align-items-center">
                                <div class="position-relative me-3 flex-shrink-0">
                                    <div class="avatar-circle avatar-md <?= $is_active ? 'bg-white text-primary' : 'avatar-primary'; ?> shadow-sm">
                                        <?= strtoupper(substr($mhs['nama_lengkap'], 0, 1)); ?>
                                    </div>
                                    <span class="position-absolute bottom-0 end-0 p-1 bg-success border border-white rounded-circle"></span>
                                </div>
                                <div class="flex-grow-1 overflow-hidden">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <h6 class="mb-0 fw-bold text-truncate <?= $is_active ? 'text-white' : 'text-dark'; ?>">
                                            <?= htmlspecialchars($mhs['nama_lengkap']); ?>
                                        </h6>
                                        <?php if (!empty($last_msg)): ?>
                                            <small class="<?= $is_active ? 'text-white-50' : 'text-muted'; ?>" style="font-size: 0.72rem;">
                                                <?= date('H:i', strtotime($last_msg['waktu_kirim'])); ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                    <small class="d-block text-truncate <?= $is_active ? 'text-white-50' : 'text-muted'; ?>" style="font-size: 0.78rem;">
                                        NIM: <?= htmlspecialchars($mhs['nim']); ?>
                                    </small>
                                    <small class="d-block text-truncate <?= $is_active ? 'text-white text-opacity-75' : 'text-secondary'; ?>" style="font-size: 0.8rem;">
                                        <?= !empty($last_msg) ? htmlspecialchars($last_msg['pesan']) : '<em>Mulai percakapan...</em>'; ?>
                                    </small>
                                </div>
                                <?php if ($unread_count > 0 && !$is_active): ?>
                                    <span class="badge bg-danger rounded-pill ms-2 shadow-sm"><?= $unread_count; ?></span>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Kolom Kanan: Area Ruang Obrolan -->
        <div class="col-md-8 col-lg-8 d-flex flex-column bg-white" style="height: 640px;">
            <?php if ($active_mhs): ?>
                <!-- Header Chat Aktif -->
                <div class="p-3 border-bottom d-flex align-items-center justify-content-between bg-white shadow-sm">
                    <div class="d-flex align-items-center">
                        <div class="position-relative me-3">
                            <div class="avatar-circle avatar-md avatar-primary shadow-sm">
                                <?= strtoupper(substr($active_mhs['nama_lengkap'], 0, 1)); ?>
                            </div>
                            <span class="position-absolute bottom-0 end-0 p-1 bg-success border border-white rounded-circle"></span>
                        </div>
                        <div>
                            <h6 class="mb-0 fw-bold text-dark"><?= htmlspecialchars($active_mhs['nama_lengkap']); ?></h6>
                            <small class="text-muted">NIM: <?= htmlspecialchars($active_mhs['nim']); ?> &bull; <span class="text-truncate d-inline-block align-bottom" style="max-width: 250px;"><?= htmlspecialchars($active_mhs['judul_skripsi']); ?></span></small>
                        </div>
                    </div>
                    <div>
                        <a href="riwayat_bimbingan.php" class="btn btn-outline-secondary btn-sm" title="Lihat Rekap Bimbingan">
                            <i class="bi bi-clock-history me-1"></i>Riwayat
                        </a>
                    </div>
                </div>

                <!-- Isi Percakapan (Messages Stream) -->
                <div class="p-4 overflow-auto flex-grow-1 bg-light bg-opacity-50" id="chatContainer" style="scroll-behavior: smooth;">
                    <?php if (empty($pesan_list)): ?>
                        <div class="text-center py-5 text-muted">
                            <div class="avatar-circle avatar-lg avatar-primary mx-auto mb-3">
                                <i class="bi bi-chat-dots"></i>
                            </div>
                            <h6 class="fw-bold text-dark mb-1">Belum Ada Riwayat Pesan</h6>
                            <p class="small text-muted mb-0">Tulis instruksi atau catatan konsultasi pada formulir di bawah untuk memulai obrolan dengan mahasiswa ini.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($pesan_list as $chat): ?>
                            <?php $is_me = ($chat['pengirim_id'] == $user_id); ?>
                            <div class="d-flex mb-3 <?= $is_me ? 'justify-content-end' : 'justify-content-start'; ?>">
                                <div class="<?= $is_me ? 'chat-bubble-me' : 'chat-bubble-peer'; ?>">
                                    <p class="mb-1" style="white-space: pre-wrap; word-break: break-word; font-size: 0.92rem;"><?= htmlspecialchars($chat['pesan']); ?></p>
                                    <div class="d-flex justify-content-end align-items-center gap-1" style="font-size: 0.7rem;">
                                        <span class="<?= $is_me ? 'text-white-50' : 'text-muted'; ?>">
                                            <?= date('H:i', strtotime($chat['waktu_kirim'])); ?>
                                        </span>
                                        <?php if ($is_me): ?>
                                            <i class="bi <?= ($chat['status_baca'] === 'dibaca') ? 'bi-check-all text-white' : 'bi-check text-white-50'; ?>"></i>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Input Pengiriman Pesan -->
                <div class="p-3 border-top bg-white">
                    <form action="chat.php?uid=<?= $active_mhs_user_id; ?>" method="POST" id="formChat" class="d-flex gap-2">
                        <input type="hidden" name="kirim_pesan" value="1">
                        <input type="hidden" name="penerima_id" value="<?= $active_mhs_user_id; ?>">

                        <input type="text" 
                               name="pesan" 
                               id="inputPesan" 
                               class="form-control rounded-pill px-4" 
                               placeholder="Tulis pesan atau instruksi konsultasi di sini..." 
                               autocomplete="off" 
                               required autofocus>
                        
                        <button type="submit" class="btn btn-primary rounded-pill px-4 shadow-sm">
                            <i class="bi bi-send-fill me-1"></i> Kirim
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <div class="d-flex flex-column align-items-center justify-content-center h-100 text-muted p-5">
                    <i class="bi bi-chat-left-dots fs-1 mb-2 text-primary"></i>
                    <h5 class="fw-bold text-dark">Ruang Konsultasi Belum Dipilih</h5>
                    <p class="small text-muted text-center" style="max-width: 320px;">Pilih salah satu mahasiswa di panel kiri untuk membuka jendela obrolan konsultasi bimbingan.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    // Auto-scroll ke pesan terbawah saat halaman selesai dimuat
    window.addEventListener('DOMContentLoaded', function() {
        const chatContainer = document.getElementById('chatContainer');
        if (chatContainer) {
            chatContainer.scrollTop = chatContainer.scrollHeight;
        }
    });
</script>

<?php
require_once '../templates/footer.php';
?>
