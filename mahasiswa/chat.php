<?php
// mahasiswa/chat.php - Sistem Chat Internal Mahasiswa & Dosen Pembimbing Terintegrasi
$page_title   = "Chat Dosen Pembimbing";
$current_page = "chat";

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
    SELECT m.*, d.id AS dosen_id, d.user_id AS dosen_user_id, d.nidn, d.nama_dosen, 
           d.email AS email_dosen, d.no_whatsapp, d.foto_profil AS foto_dosen
    FROM mahasiswa m 
    LEFT JOIN dosen d ON m.dosen_pembimbing_id = d.id 
    WHERE m.user_id = :uid LIMIT 1
");
$stmt_mhs->execute([':uid' => $_SESSION['user_id']]);
$mhs = $stmt_mhs->fetch();

$dosen_user_id = $mhs['dosen_user_id'] ?? null;
$my_user_id    = $_SESSION['user_id'];
$success_msg   = '';
$error_msg     = '';

// Handle Pengiriman Pesan Baru (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'kirim_pesan') {
    if (!$dosen_user_id) {
        $error_msg = "Anda belum memiliki dosen pembimbing untuk diajak berkirim pesan.";
    } else {
        $pesan = trim($_POST['pesan'] ?? '');

        if (empty($pesan)) {
            $error_msg = "Pesan tidak boleh kosong.";
        } else {
            try {
                $stmt_insert = $pdo->prepare("
                    INSERT INTO pesan_chat (pengirim_id, penerima_id, pesan, status_baca) 
                    VALUES (:pengirim, :penerima, :pesan, 'belum_dibaca')
                ");
                $stmt_insert->execute([
                    ':pengirim' => $my_user_id,
                    ':penerima' => $dosen_user_id,
                    ':pesan'    => $pesan
                ]);

                // Notifikasi ke dosen
                $nama_mhs = $mhs['nama_lengkap'] ?? 'Mahasiswa';
                $stmt_n = $pdo->prepare("
                    INSERT INTO notifikasi (user_id, judul, pesan, tipe, link_tujuan) 
                    VALUES (:uid, 'Pesan Chat Baru', :pesan, 'info', 'chat.php?uid=' || :sender)
                ");
                $stmt_n->execute([
                    ':uid'    => $dosen_user_id,
                    ':pesan'  => "Mahasiswa {$nama_mhs} mengirimkan pesan bimbingan.",
                    ':sender' => $my_user_id
                ]);

                header("Location: chat.php?sent=1");
                exit;
            } catch (PDOException $e) {
                $error_msg = "Gagal mengirim pesan: " . $e->getMessage();
            }
        }
    }
}

// Ambil riwayat percakapan antara mahasiswa ini dan dosen pembimbing
$daftar_pesan = [];
if ($dosen_user_id) {
    try {
        // Tandai semua pesan dari dosen sebagai sudah dibaca
        $stmt_read = $pdo->prepare("
            UPDATE pesan_chat 
            SET status_baca = 'dibaca' 
            WHERE pengirim_id = :dosen_uid AND penerima_id = :my_uid AND status_baca = 'belum_dibaca'
        ");
        $stmt_read->execute([':dosen_uid' => $dosen_user_id, ':my_uid' => $my_user_id]);

        // Ambil pesan
        $stmt_chat = $pdo->prepare("
            SELECT * FROM pesan_chat 
            WHERE (pengirim_id = :my_uid1 AND penerima_id = :dosen_uid1)
               OR (pengirim_id = :dosen_uid2 AND penerima_id = :my_uid2)
            ORDER BY waktu_kirim ASC
        ");
        $stmt_chat->execute([
            ':my_uid1'    => $my_user_id,
            ':dosen_uid1' => $dosen_user_id,
            ':dosen_uid2' => $dosen_user_id,
            ':my_uid2'    => $my_user_id
        ]);
        $daftar_pesan = $stmt_chat->fetchAll();
    } catch (PDOException $e) {
        $error_msg = "Gagal memuat pesan: " . $e->getMessage();
    }
}

// WhatsApp URL untuk fallback darurat
$wa_url = '#';
if (!empty($mhs['no_whatsapp'])) {
    $clean_wa = preg_replace('/[^0-9]/', '', $mhs['no_whatsapp']);
    if (str_starts_with($clean_wa, '0')) {
        $clean_wa = '62' . substr($clean_wa, 1);
    }
    $wa_url = "https://wa.me/{$clean_wa}?text=" . urlencode("Halo Bapak/Ibu " . ($mhs['nama_dosen'] ?? 'Dosen') . ", saya " . ($mhs['nama_lengkap'] ?? 'Mahasiswa') . " ingin mengonfirmasi pesan bimbingan di portal web.");
}
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center pt-3 pb-2 mb-3 border-bottom gap-2">
    <div>
        <h1 class="h2 mb-0">Chat Bimbingan Skripsi</h1>
        <p class="text-muted small mb-0">Komunikasi internal terpusat antara Mahasiswa dan Dosen Pembimbing secara real-time.</p>
    </div>
    <div class="page-header-actions">
        <a href="jadwal_dosen.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-calendar3"></i>Jadwal Dosen
        </a>
        <a href="ajukan_jadwal.php" class="btn btn-primary btn-sm">
            <i class="bi bi-calendar-plus"></i>Ajukan Jadwal
        </a>
    </div>
</div>

<?php if (!empty($error_msg)): ?>
    <div class="alert alert-danger alert-dismissible fade show py-2 small shadow-sm" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-1"></i><?= htmlspecialchars($error_msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!$dosen_user_id): ?>
    <div class="card shadow-sm border-warning border-start border-4 p-4 text-center my-4">
        <i class="bi bi-chat-square-quote text-warning fs-1 mb-2"></i>
        <h5 class="fw-bold text-dark">Dosen Pembimbing Belum Diplot</h5>
        <p class="text-muted mb-3">Fitur chat internal hanya aktif jika Anda telah memiliki dosen pembimbing resmi.</p>
        <div>
            <a href="index.php" class="btn btn-outline-primary btn-sm">Kembali ke Dashboard</a>
        </div>
    </div>
<?php else: ?>

    <div class="row g-3">
        <!-- Area Chat Utama -->
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 d-flex flex-column" style="height: 650px;">
                <!-- Header Chat Box -->
                <div class="card-header bg-white py-3 px-4 d-flex justify-content-between align-items-center border-bottom">
                    <div class="d-flex align-items-center">
                        <div class="position-relative me-3">
                            <?php if (!empty($mhs['foto_dosen']) && file_exists('../assets/uploads/' . $mhs['foto_dosen'])): ?>
                                <img src="../assets/uploads/<?= htmlspecialchars($mhs['foto_dosen']); ?>" class="rounded-circle border" width="48" height="48" style="object-fit: cover;" alt="Dosen">
                            <?php else: ?>
                                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold" style="width: 48px; height: 48px; font-size: 1.2rem;">
                                    <?= strtoupper(substr($mhs['nama_dosen'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                            <span class="position-absolute bottom-0 end-0 bg-success border border-white rounded-circle" style="width: 12px; height: 12px;" title="Online"></span>
                        </div>
                        <div>
                            <h6 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($mhs['nama_dosen']); ?></h6>
                            <span class="text-muted small">
                                <i class="bi bi-person-badge me-1"></i>NIDN: <?= htmlspecialchars($mhs['nidn'] ?? '-'); ?>
                            </span>
                        </div>
                    </div>
                    <div>
                        <a href="chat.php" class="btn btn-outline-secondary btn-sm me-1" title="Segarkan Percakapan">
                            <i class="bi bi-arrow-clockwise"></i>
                        </a>
                        <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalKontakDosen">
                            <i class="bi bi-info-circle me-1"></i>Kontak Dosen
                        </button>
                    </div>
                </div>

                <!-- Body Chat (Pesan-Pesan) -->
                <div class="card-body p-3 overflow-y-auto flex-grow-1 bg-light" id="chatBoxContainer">
                    <?php if (empty($daftar_pesan)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-chat-heart fs-1 text-primary d-block mb-2"></i>
                            <h6 class="fw-bold">Belum Ada Percakapan</h6>
                            <p class="small text-muted mb-0">Mulai diskusi bimbingan skripsi Anda dengan menyapa Bapak/Ibu dosen di kolom bawah.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($daftar_pesan as $chat): 
                            $is_me = ($chat['pengirim_id'] == $my_user_id);
                        ?>
                            <div class="d-flex mb-3 <?= $is_me ? 'justify-content-end' : 'justify-content-start'; ?>">
                                <?php if (!$is_me): ?>
                                    <div class="me-2 align-self-end">
                                        <div class="bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center small fw-bold" style="width: 32px; height: 32px;">
                                            <?= strtoupper(substr($mhs['nama_dosen'] ?? 'D', 0, 1)); ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div style="max-width: 75%;">
                                    <div class="p-3 shadow-sm <?= $is_me ? 'chat-bubble-mhs' : 'chat-bubble-dosen'; ?>">
                                        <?php if (!$is_me): ?>
                                            <div class="fw-bold small text-primary mb-1"><?= htmlspecialchars($mhs['nama_dosen']); ?></div>
                                        <?php endif; ?>

                                        <?php if (!empty($chat['pesan'])): ?>
                                            <div class="text-break" style="white-space: pre-wrap;"><?= nl2br(htmlspecialchars($chat['pesan'])); ?></div>
                                        <?php endif; ?>

                                        <div class="d-flex justify-content-end align-items-center mt-1" style="font-size: 0.72rem; opacity: 0.85;">
                                            <span><?= date('H:i', strtotime($chat['waktu_kirim'])); ?></span>
                                            <?php if ($is_me): ?>
                                                <i class="bi <?= $chat['status_baca'] === 'dibaca' ? 'bi-check2-all text-info' : 'bi-check2'; ?> ms-1"></i>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Footer Form Kirim Pesan -->
                <div class="card-footer bg-white p-3 border-top">
                    <form action="chat.php" method="POST" id="formKirimChat">
                        <input type="hidden" name="action" value="kirim_pesan">
                        <div class="input-group">
                            <textarea name="pesan" id="inputPesan" class="form-control" placeholder="Ketik pesan bimbingan kepada dosen di sini..." rows="1" style="resize: none;" required></textarea>
                            <button type="submit" class="btn btn-primary px-3">
                                <i class="bi bi-send-fill me-1"></i>Kirim
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Info Card Samping Profil Dosen & Panduan Etika Chat -->
        <div class="col-lg-4">
            <!-- Profil Singkat Dosen -->
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="m-0 fw-bold text-dark">
                        <i class="bi bi-person-lines-fill text-primary me-2"></i>Dosen Pembimbing
                    </h6>
                </div>
                <div class="card-body p-3">
                    <div class="text-center mb-3">
                        <?php if (!empty($mhs['foto_dosen']) && file_exists('../assets/uploads/' . $mhs['foto_dosen'])): ?>
                            <img src="../assets/uploads/<?= htmlspecialchars($mhs['foto_dosen']); ?>" class="rounded-circle border mb-2 shadow-sm" width="70" height="70" style="object-fit: cover;" alt="Dosen">
                        <?php else: ?>
                            <div class="bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center fw-bold mb-2 shadow-sm" style="width: 70px; height: 70px; font-size: 1.8rem;">
                                <?= strtoupper(substr($mhs['nama_dosen'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                        <h6 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($mhs['nama_dosen']); ?></h6>
                        <span class="text-muted small">NIDN: <?= htmlspecialchars($mhs['nidn'] ?? '-'); ?></span>
                    </div>

                    <div class="list-group list-group-flush small">
                        <div class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center">
                            <span class="text-muted"><i class="bi bi-envelope me-1"></i>Email</span>
                            <span class="fw-semibold text-dark text-truncate" style="max-width: 170px;"><?= htmlspecialchars($mhs['email_dosen'] ?? '-'); ?></span>
                        </div>
                        <div class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center">
                            <span class="text-muted"><i class="bi bi-whatsapp me-1"></i>WhatsApp</span>
                            <?php if (!empty($mhs['no_whatsapp'])): ?>
                                <a href="<?= $wa_url; ?>" target="_blank" class="text-decoration-none fw-semibold text-success">
                                    <?= htmlspecialchars($mhs['no_whatsapp']); ?> <i class="bi bi-box-arrow-up-right small"></i>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Panduan Etika Chatting Akademik -->
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="m-0 fw-bold text-dark">
                        <i class="bi bi-card-checklist text-success me-2"></i>Tata Cara Menghubungi Dosen
                    </h6>
                </div>
                <div class="card-body p-3 small text-muted">
                    <ol class="ps-3 mb-0">
                        <li class="mb-2"><strong>Waktu Komunikasi:</strong> Kirimkan pesan pada hari dan jam kerja yang wajar (08:00 - 17:00 WIB).</li>
                        <li class="mb-2"><strong>Salam & Identitas:</strong> Awali dengan salam sopan, sebutkan Nama, NIM, dan topik skripsi secara ringkas.</li>
                        <li class="mb-2"><strong>Tujuan Jelas:</strong> Sampaikan keperluan konsultasi atau pertanyaan secara padat dan jelas.</li>
                        <li><strong>Ucapkan Terima Kasih:</strong> Akhiri percakapan dengan santun dan bersabar menunggu tanggapan dosen.</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Info Kontak Lengkap Dosen -->
    <div class="modal fade" id="modalKontakDosen" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-bottom">
                    <h6 class="modal-title fw-bold text-dark">Informasi Kontak Dosen Pembimbing</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-4">
                    <h5 class="fw-bold mb-1"><?= htmlspecialchars($mhs['nama_dosen']); ?></h5>
                    <p class="text-muted small mb-3">NIDN: <?= htmlspecialchars($mhs['nidn'] ?? '-'); ?></p>
                    <div class="d-flex justify-content-center gap-2">
                        <?php if (!empty($mhs['email_dosen'])): ?>
                            <a href="mailto:<?= htmlspecialchars($mhs['email_dosen']); ?>" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-envelope me-1"></i>Kirim Email
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($mhs['no_whatsapp'])): ?>
                            <a href="<?= $wa_url; ?>" target="_blank" class="btn btn-success btn-sm">
                                <i class="bi bi-whatsapp me-1"></i>Hubungi WhatsApp
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Auto scroll chat box ke bagian terbawah saat halaman terbuka
    document.addEventListener("DOMContentLoaded", function() {
        const container = document.getElementById("chatBoxContainer");
        if (container) {
            container.scrollTop = container.scrollHeight;
        }

        // Enter untuk kirim pesan, Shift+Enter untuk baris baru
        const inputPesan = document.getElementById("inputPesan");
        if (inputPesan) {
            inputPesan.addEventListener("keydown", function(e) {
                if (e.key === "Enter" && !e.shiftKey) {
                    e.preventDefault();
                    if (this.value.trim().length > 0) {
                        document.getElementById("formKirimChat").submit();
                    }
                }
            });
        }
    });
    </script>

<?php endif; ?>

<?php
require_once '../templates/footer.php';
?>
