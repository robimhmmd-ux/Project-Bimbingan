<?php
// mahasiswa/chat.php - Sistem Chat Internal Mahasiswa & Dosen Pembimbing
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

// Pastikan tabel pesan_chat tersedia (Self-healing DDL)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pesan_chat (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pengirim_user_id INT NOT NULL,
            penerima_user_id INT NOT NULL,
            pesan TEXT NOT NULL,
            lampiran VARCHAR(255) NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (pengirim_user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (penerima_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB;
    ");
} catch (PDOException $e) {
    // Abaikan jika tabel sudah ada atau privilege terbatas
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
        $nama_file_lampiran = null;

        // Cek jika ada file lampiran
        if (isset($_FILES['lampiran']) && $_FILES['lampiran']['error'] === UPLOAD_ERR_OK) {
            $file_tmp   = $_FILES['lampiran']['tmp_name'];
            $file_name  = $_FILES['lampiran']['name'];
            $file_size  = $_FILES['lampiran']['size'];
            $ext        = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            $allowed_exts = ['pdf', 'doc', 'docx', 'png', 'jpg', 'jpeg', 'zip'];
            if (!in_array($ext, $allowed_exts)) {
                $error_msg = "Format lampiran tidak didukung. Harap gunakan PDF, Word, Gambar, atau ZIP.";
            } elseif ($file_size > 5 * 1024 * 1024) {
                $error_msg = "Ukuran file lampiran maksimal 5 MB.";
            } else {
                $upload_dir = '../assets/uploads/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                $nama_file_lampiran = 'chat_' . uniqid() . '.' . $ext;
                if (!move_uploaded_file($file_tmp, $upload_dir . $nama_file_lampiran)) {
                    $nama_file_lampiran = null;
                    $error_msg = "Gagal mengunggah file lampiran.";
                }
            }
        }

        if (empty($pesan) && empty($nama_file_lampiran)) {
            $error_msg = "Pesan atau file lampiran tidak boleh kosong.";
        }

        if (empty($error_msg)) {
            try {
                $stmt_insert = $pdo->prepare("
                    INSERT INTO pesan_chat (pengirim_user_id, penerima_user_id, pesan, lampiran, is_read) 
                    VALUES (:pengirim, :penerima, :pesan, :lampiran, 0)
                ");
                $stmt_insert->execute([
                    ':pengirim' => $my_user_id,
                    ':penerima' => $dosen_user_id,
                    ':pesan'    => $pesan,
                    ':lampiran' => $nama_file_lampiran
                ]);

                // Redirect untuk mencegah form resubmission
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
            SET is_read = 1 
            WHERE penerima_user_id = :my_uid AND pengirim_user_id = :dosen_uid AND is_read = 0
        ");
        $stmt_read->execute([':my_uid' => $my_user_id, ':dosen_uid' => $dosen_user_id]);

        // Ambil pesan
        $stmt_chat = $pdo->prepare("
            SELECT * FROM pesan_chat 
            WHERE (pengirim_user_id = :my_uid AND penerima_user_id = :dosen_uid)
               OR (pengirim_user_id = :dosen_uid AND penerima_user_id = :my_uid)
            ORDER BY created_at ASC
        ");
        $stmt_chat->execute([':my_uid' => $my_user_id, ':dosen_uid' => $dosen_user_id]);
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

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2 mb-0">Chat Bimbingan Skripsi</h1>
        <p class="text-muted small mb-0">Komunikasi internal terpusat antara Mahasiswa dan Dosen Pembimbing tanpa perlu beralih ke WhatsApp.</p>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="jadwal_dosen.php" class="btn btn-outline-secondary btn-sm me-2">
            <i class="bi bi-calendar3 me-1"></i>Jadwal Dosen
        </a>
        <a href="ajukan_jadwal.php" class="btn btn-primary btn-sm">
            <i class="bi bi-calendar-plus me-1"></i>Ajukan Jadwal
        </a>
    </div>
</div>

<?php if (!empty($error_msg)): ?>
    <div class="alert alert-danger alert-dismissible fade show py-2 small" role="alert">
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
                        <!-- Tombol Segarkan Chat & Info Kontak -->
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
                            $is_me = ($chat['pengirim_user_id'] == $my_user_id);
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
                                    <div class="p-3 rounded-3 shadow-sm <?= $is_me ? 'bg-primary text-white' : 'bg-white text-dark border'; ?>">
                                        <?php if (!$is_me): ?>
                                            <div class="fw-bold small text-primary mb-1"><?= htmlspecialchars($mhs['nama_dosen']); ?></div>
                                        <?php endif; ?>

                                        <?php if (!empty($chat['pesan'])): ?>
                                            <div class="text-break" style="white-space: pre-wrap;"><?= nl2br(htmlspecialchars($chat['pesan'])); ?></div>
                                        <?php endif; ?>

                                        <!-- Jika ada file lampiran -->
                                        <?php if (!empty($chat['lampiran'])): 
                                            $lampiran_file = htmlspecialchars($chat['lampiran']);
                                            $ext = strtolower(pathinfo($lampiran_file, PATHINFO_EXTENSION));
                                        ?>
                                            <div class="mt-2 pt-2 border-top <?= $is_me ? 'border-light border-opacity-25' : 'border-secondary border-opacity-25'; ?>">
                                                <a href="../assets/uploads/<?= $lampiran_file; ?>" target="_blank" class="btn btn-sm <?= $is_me ? 'btn-light text-primary' : 'btn-outline-primary'; ?> py-1 px-2 d-inline-flex align-items-center text-truncate" style="max-width: 100%;">
                                                    <i class="bi bi-paperclip me-1 fs-6"></i>
                                                    <span class="text-truncate"><?= $lampiran_file; ?></span>
                                                    <i class="bi bi-box-arrow-up-right ms-2 small"></i>
                                                </a>
                                            </div>
                                        <?php endif; ?>

                                        <div class="d-flex justify-content-end align-items-center mt-1" style="font-size: 0.72rem; opacity: 0.85;">
                                            <span><?= date('H:i', strtotime($chat['created_at'])); ?></span>
                                            <?php if ($is_me): ?>
                                                <i class="bi <?= $chat['is_read'] ? 'bi-check2-all text-info' : 'bi-check2'; ?> ms-1"></i>
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
                    <form action="chat.php" method="POST" enctype="multipart/form-data" id="formKirimChat">
                        <input type="hidden" name="action" value="kirim_pesan">
                        <div class="input-group">
                            <label class="btn btn-outline-secondary" for="inputLampiran" title="Lampirkan Dokumen (PDF, Word, Gambar)">
                                <i class="bi bi-paperclip fs-5"></i>
                                <input type="file" name="lampiran" id="inputLampiran" class="d-none" onchange="previewFile(this)">
                            </label>
                            <textarea name="pesan" id="inputPesan" class="form-control" placeholder="Ketik pesan bimbingan kepada dosen di sini..." rows="1" style="resize: none;" required></textarea>
                            <button type="submit" class="btn btn-primary px-3">
                                <i class="bi bi-send-fill me-1"></i>Kirim
                            </button>
                        </div>
                        <div id="filePreviewNotice" class="small text-muted mt-2 d-none">
                            <i class="bi bi-file-earmark-check text-success me-1"></i>
                            File terlampir: <strong id="fileNameText" class="text-dark"></strong>
                            <a href="javascript:void(0)" onclick="clearFile()" class="text-danger ms-2 text-decoration-none">Batal</a>
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
                        <i class="bi bi-person-lines-fill text-primary me-2"></i>Informasi Kontak Dosen
                    </h6>
                </div>
                <div class="card-body">
                    <div class="d-flex align-items-center mb-3">
                        <div class="me-3">
                            <?php if (!empty($mhs['foto_dosen']) && file_exists('../assets/uploads/' . $mhs['foto_dosen'])): ?>
                                <img src="../assets/uploads/<?= htmlspecialchars($mhs['foto_dosen']); ?>" class="rounded-circle border" width="50" height="50" style="object-fit: cover;" alt="Dosen">
                            <?php else: ?>
                                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold" style="width: 50px; height: 50px; font-size: 1.3rem;">
                                    <?= strtoupper(substr($mhs['nama_dosen'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <h6 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($mhs['nama_dosen']); ?></h6>
                            <span class="text-muted small">NIDN: <?= htmlspecialchars($mhs['nidn'] ?? '-'); ?></span>
                        </div>
                    </div>

                    <div class="list-group list-group-flush small">
                        <div class="list-group-item px-0 py-2">
                            <span class="text-muted d-block"><i class="bi bi-envelope me-1 text-primary"></i>Email Resmi:</span>
                            <a href="mailto:<?= htmlspecialchars($mhs['email_dosen'] ?? ''); ?>" class="fw-semibold text-dark text-decoration-none">
                                <?= htmlspecialchars($mhs['email_dosen'] ?? '-'); ?>
                            </a>
                        </div>
                        <div class="list-group-item px-0 py-2">
                            <span class="text-muted d-block"><i class="bi bi-whatsapp me-1 text-success"></i>WhatsApp Darurat:</span>
                            <?php if (!empty($mhs['no_whatsapp'])): ?>
                                <a href="<?= $wa_url; ?>" target="_blank" class="fw-semibold text-success text-decoration-none">
                                    <?= htmlspecialchars($mhs['no_whatsapp']); ?> <i class="bi bi-box-arrow-up-right small"></i>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">Tidak dicantumkan</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="alert alert-secondary py-2 small mt-3 mb-0">
                        <i class="bi bi-shield-check me-1"></i>Semua riwayat obrolan bimbingan tersimpan aman di database kampus untuk dokumentasi akademik.
                    </div>
                </div>
            </div>

            <!-- Panduan Etika Chat Dosen -->
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="m-0 fw-bold text-dark">
                        <i class="bi bi-card-checklist text-success me-2"></i>Etika Mengirim Pesan
                    </h6>
                </div>
                <div class="card-body small text-muted">
                    <ol class="ps-3 mb-0">
                        <li class="mb-1">Gunakan salam pembuka yang santun (Selamat pagi/siang Bapak/Ibu).</li>
                        <li class="mb-1">Sebutkan identitas diri: Nama, NIM, dan topik pembahasan skripsi.</li>
                        <li class="mb-1">Tuliskan maksud konsultasi secara singkat, padat, dan jelas.</li>
                        <li class="mb-1">Sertakan lampiran draf bab jika diperlukan koreksi dokumen.</li>
                        <li>Hindari mengirim pesan di luar jam kerja akademik (malam hari/akhir pekan).</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Informasi Kontak Dosen -->
    <div class="modal fade" id="modalKontakDosen" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">
                        <i class="bi bi-person-vcard text-primary me-2"></i>Profil & Kontak Dosen
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-4">
                    <div class="mb-3">
                        <?php if (!empty($mhs['foto_dosen']) && file_exists('../assets/uploads/' . $mhs['foto_dosen'])): ?>
                            <img src="../assets/uploads/<?= htmlspecialchars($mhs['foto_dosen']); ?>" class="rounded-circle img-thumbnail" width="90" height="90" style="object-fit: cover;" alt="Dosen">
                        <?php else: ?>
                            <div class="bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center fw-bold" style="width: 80px; height: 80px; font-size: 2rem;">
                                <?= strtoupper(substr($mhs['nama_dosen'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <h5 class="fw-bold text-dark mb-1"><?= htmlspecialchars($mhs['nama_dosen']); ?></h5>
                    <p class="text-muted small mb-3">NIDN: <?= htmlspecialchars($mhs['nidn'] ?? '-'); ?></p>

                    <div class="card bg-light p-3 border text-start small mb-3">
                        <div class="mb-2">
                            <strong><i class="bi bi-envelope text-primary me-2"></i>Email:</strong><br>
                            <a href="mailto:<?= htmlspecialchars($mhs['email_dosen'] ?? ''); ?>" class="text-decoration-none">
                                <?= htmlspecialchars($mhs['email_dosen'] ?? '-'); ?>
                            </a>
                        </div>
                        <div>
                            <strong><i class="bi bi-whatsapp text-success me-2"></i>WhatsApp:</strong><br>
                            <?php if (!empty($mhs['no_whatsapp'])): ?>
                                <a href="<?= $wa_url; ?>" target="_blank" class="text-decoration-none text-success fw-bold">
                                    <?= htmlspecialchars($mhs['no_whatsapp']); ?> <i class="bi bi-box-arrow-up-right small"></i>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <p class="small text-muted mb-0">Catatan: Disarankan tetap menggunakan portal chat ini sebagai kanal utama bimbingan skripsi agar memiliki rekap logbook yang valid.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
                    <?php if (!empty($mhs['no_whatsapp'])): ?>
                        <a href="<?= $wa_url; ?>" target="_blank" class="btn btn-success btn-sm">
                            <i class="bi bi-whatsapp me-1"></i>Hubungi via WhatsApp
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Script Auto Scroll Chat Box & File Preview -->
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const chatContainer = document.getElementById("chatBoxContainer");
        if (chatContainer) {
            chatContainer.scrollTop = chatContainer.scrollHeight;
        }

        // Auto expand textarea
        const inputPesan = document.getElementById("inputPesan");
        if (inputPesan) {
            inputPesan.addEventListener("keydown", function(e) {
                if (e.key === "Enter" && !e.shiftKey) {
                    e.preventDefault();
                    document.getElementById("formKirimChat").submit();
                }
            });
        }
    });

    function previewFile(input) {
        if (input.files && input.files[0]) {
            const fileName = input.files[0].name;
            document.getElementById("fileNameText").innerText = fileName;
            document.getElementById("filePreviewNotice").classList.remove("d-none");
            document.getElementById("inputPesan").removeAttribute("required");
        }
    }

    function clearFile() {
        const input = document.getElementById("inputLampiran");
        input.value = "";
        document.getElementById("filePreviewNotice").classList.add("d-none");
        document.getElementById("inputPesan").setAttribute("required", "required");
    }
    </script>

<?php endif; ?>

<?php
require_once '../templates/footer.php';
?>

