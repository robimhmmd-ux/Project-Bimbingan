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
           d.email AS email_dosen, d.no_whatsapp, d.foto_profil AS foto_dosen, d.kuota_bimbingan,
           u_dos.username AS username_dosen
    FROM mahasiswa m 
    LEFT JOIN dosen d ON m.dosen_pembimbing_id = d.id 
    LEFT JOIN users u_dos ON d.user_id = u_dos.id
    WHERE m.user_id = :uid LIMIT 1
");
$stmt_mhs->execute([':uid' => $_SESSION['user_id']]);
$mhs = $stmt_mhs->fetch();

$dosen_user_id = $mhs['dosen_user_id'] ?? null;
$my_user_id    = $_SESSION['user_id'];

// Ambil jadwal ketersediaan konsultasi dosen pembimbing
$jadwal_dosen_list = [];
if (!empty($mhs['dosen_id'])) {
    try {
        $st_jd = $pdo->prepare("
            SELECT hari, jam_mulai, jam_selesai, status_aktif 
            FROM jadwal_dosen 
            WHERE dosen_id = :did AND status_aktif = 'aktif'
            ORDER BY FIELD(hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu')
        ");
        $st_jd->execute([':did' => $mhs['dosen_id']]);
        $jadwal_dosen_list = $st_jd->fetchAll();
    } catch (PDOException $e) {
        $jadwal_dosen_list = [];
    }
}
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

                // Notifikasi ke dosen (UPDATE Robi)
                $nama_mhs = $mhs['nama_lengkap'] ?? 'Mahasiswa';
                $link_chat = 'chat.php?uid=' . $my_user_id;
                $stmt_n = $pdo->prepare("
                    INSERT INTO notifikasi (user_id, judul, pesan, tipe, link_tujuan) 
                    VALUES (:uid, 'Pesan Chat Baru', :pesan, 'info', :link)
                ");
                $stmt_n->execute([
                    ':uid'   => $dosen_user_id,
                    ':pesan' => "Mahasiswa {$nama_mhs} mengirimkan pesan bimbingan.",
                    ':link'  => $link_chat
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
                    <i class="bi bi-chat-dots-fill me-1"></i>Komunikasi Terpusat
                </span>
            </div>
            <h2 class="fw-bold text-white mb-1">Chat Bimbingan Skripsi</h2>
            <p class="text-white-50 small mb-0">
                Komunikasi internal terpusat antara Mahasiswa dan Dosen Pembimbing secara real-time.
            </p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-shrink-0 flex-wrap">
            <a href="jadwal_bimbingan.php" class="btn btn-hero-action btn-hero-white">
                <i class="bi bi-calendar-week"></i>Jadwal Bimbingan
            </a>
            <a href="upload_draft.php" class="btn btn-hero-action btn-hero-blue">
                <i class="bi bi-cloud-arrow-up"></i>Upload Berkas
            </a>
            <a href="index.php" class="btn btn-hero-action btn-hero-outline">
                <i class="bi bi-speedometer2"></i>Dashboard
            </a>
        </div>
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
                        <?php 
            $foto_dosen_src = null;
            if (!empty($mhs['foto_dosen']) && $mhs['foto_dosen'] !== 'default.png') {
                $fname_dosen = basename($mhs['foto_dosen']);
                if (file_exists(__DIR__ . '/../assets/uploads/profile/' . $fname_dosen)) {
                    $foto_dosen_src = '../assets/uploads/profile/' . htmlspecialchars($fname_dosen);
                } elseif (file_exists(__DIR__ . '/../assets/uploads/' . $fname_dosen)) {
                    $foto_dosen_src = '../assets/uploads/' . htmlspecialchars($fname_dosen);
                }
            }
            ?>
            <div class="position-relative me-3">
                <a href="javascript:void(0)" data-bs-toggle="modal" data-bs-target="#modalProfilDosen" title="Klik untuk melihat profil dosen">
                    <?php if ($foto_dosen_src): ?>
                        <img src="<?= $foto_dosen_src; ?>" class="rounded-circle border shadow-sm object-fit-cover" width="50" height="50" style="cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.08)'" onmouseout="this.style.transform='scale(1)'" alt="Foto Profil Dosen">
                    <?php else: ?>
                        <div class="avatar-circle avatar-primary shadow-sm rounded-circle d-flex align-items-center justify-content-center text-primary" style="width: 50px; height: 50px; font-size: 1.4rem; cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.08)'" onmouseout="this.style.transform='scale(1)'">
                            <i class="bi bi-person-fill"></i>
                        </div>
                    <?php endif; ?>
                </a>
                <span class="position-absolute bottom-0 end-0 bg-success border border-white rounded-circle" style="width: 12px; height: 12px;" title="Dosen Aktif"></span>
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
                        <div class="position-relative d-inline-block mb-2">
                            <a href="javascript:void(0)" data-bs-toggle="modal" data-bs-target="#modalProfilDosen" title="Klik untuk melihat profil dosen">
                                <?php if ($foto_dosen_src): ?>
                                    <img src="<?= $foto_dosen_src; ?>" class="rounded-circle border shadow-sm img-thumbnail object-fit-cover" width="80" height="80" style="cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.06)'" onmouseout="this.style.transform='scale(1)'" alt="Foto Profil Dosen">
                                <?php else: ?>
                                    <div class="avatar-circle avatar-primary shadow-sm rounded-circle d-flex align-items-center justify-content-center text-primary mx-auto" style="width: 80px; height: 80px; font-size: 2.2rem; cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.06)'" onmouseout="this.style.transform='scale(1)'">
                                        <i class="bi bi-person-fill"></i>
                                    </div>
                                <?php endif; ?>
                            </a>
                            <span class="position-absolute bottom-0 end-0 p-1.5 bg-success border border-2 border-white rounded-circle shadow-sm" title="Dosen Aktif"></span>
                        </div>
                        <h6 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($mhs['nama_dosen']); ?></h6>
                        <span class="text-muted small d-block mb-2">NIDN: <?= htmlspecialchars($mhs['nidn'] ?? '-'); ?></span>
                        <button type="button" class="btn btn-outline-primary btn-sm py-1 px-3 rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#modalProfilDosen" style="font-size: 0.75rem;">
                            <i class="bi bi-person-vcard me-1"></i>Lihat Profil Lengkap
                        </button>
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


<!-- ============================================================
     MODAL LIHAT PROFIL DOSEN PEMBIMBING (SESUAI PROFIL DOSEN)
============================================================ -->
<div class="modal fade" id="modalProfilDosen" tabindex="-1" aria-labelledby="modalProfilDosenLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header text-white border-0 py-3" style="background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 60%, #1e40af 100%);">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-person-vcard-fill text-info fs-5"></i>
                    <h5 class="modal-title fw-bold text-white mb-0" id="modalProfilDosenLabel">Profil Dosen Pembimbing</h5>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <div class="modal-body p-4">
                <!-- Foto & Identitas Utama Dosen -->
                <div class="text-center mb-4">
                    <div class="mb-3 position-relative d-inline-block mx-auto">
                        <?php if ($foto_dosen_src): ?>
                            <img src="<?= $foto_dosen_src; ?>" 
                                 alt="Foto Profil Dosen" 
                                 class="rounded-circle img-thumbnail shadow object-fit-cover" 
                                 style="width: 125px; height: 125px;">
                        <?php else: ?>
                            <div class="avatar-circle avatar-primary shadow mx-auto d-flex align-items-center justify-content-center text-primary rounded-circle" 
                                 style="width: 125px; height: 125px; font-size: 3.2rem;">
                                <i class="bi bi-person-fill"></i>
                            </div>
                        <?php endif; ?>
                        <span class="position-absolute bottom-0 end-0 p-2 bg-success border border-3 border-white rounded-circle shadow-sm" title="Status Pembimbing Aktif"></span>
                    </div>

                    <h5 class="fw-bold mb-1 text-dark"><?= htmlspecialchars($mhs['nama_dosen'] ?? 'Dosen Pembimbing'); ?></h5>
                    <p class="text-muted small mb-2">NIDN: <?= htmlspecialchars($mhs['nidn'] ?? '-'); ?></p>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-1.5 rounded-pill">
                        <i class="bi bi-award-fill me-1"></i>Dosen Pembimbing Utama Skripsi
                    </span>
                </div>

                <!-- Detail Biodata Profil Dosen (Sesuai profil dosen itu sendiri) -->
                <div class="list-group list-group-flush text-start small border rounded-3 p-3 bg-light mb-3">
                    <div class="list-group-item d-flex justify-content-between px-0 py-2 bg-transparent border-bottom">
                        <span class="text-muted"><i class="bi bi-person-badge me-2 text-primary"></i>ID Login / Akun:</span>
                        <strong class="text-primary">@<?= htmlspecialchars($mhs['username_dosen'] ?? 'dosen'); ?></strong>
                    </div>
                    <div class="list-group-item d-flex justify-content-between px-0 py-2 bg-transparent border-bottom">
                        <span class="text-muted"><i class="bi bi-envelope me-2 text-primary"></i>Email Resmi:</span>
                        <a href="mailto:<?= htmlspecialchars($mhs['email_dosen'] ?? ''); ?>" class="text-decoration-none fw-semibold text-secondary">
                            <?= htmlspecialchars($mhs['email_dosen'] ?? '-'); ?>
                        </a>
                    </div>
                    <div class="list-group-item d-flex justify-content-between px-0 py-2 bg-transparent border-bottom">
                        <span class="text-muted"><i class="bi bi-whatsapp me-2 text-success"></i>Nomor WhatsApp:</span>
                        <?php if (!empty($mhs['no_whatsapp'])): 
                            $clean_wa = preg_replace('/[^0-9]/', '', $mhs['no_whatsapp']);
                            if (str_starts_with($clean_wa, '0')) {
                                $clean_wa = '62' . substr($clean_wa, 1);
                            }
                        ?>
                            <a href="https://wa.me/<?= $clean_wa; ?>" target="_blank" class="text-decoration-none fw-semibold text-success">
                                <?= htmlspecialchars($mhs['no_whatsapp']); ?> <i class="bi bi-box-arrow-up-right small"></i>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </div>
                    <div class="list-group-item d-flex justify-content-between px-0 py-2 bg-transparent">
                        <span class="text-muted"><i class="bi bi-people me-2 text-primary"></i>Kapasitas Bimbingan:</span>
                        <strong><?= htmlspecialchars($mhs['kuota_bimbingan'] ?? '5'); ?> Mahasiswa</strong>
                    </div>
                </div>

                <!-- Jadwal Ketersediaan Bimbingan Dosen -->
                <div class="p-3 border rounded-3 bg-white">
                    <h6 class="fw-bold text-dark mb-2 small d-flex align-items-center">
                        <i class="bi bi-calendar-check text-primary me-2"></i>Jadwal Konsultasi Dosen
                    </h6>
                    <?php if (!empty($jadwal_dosen_list)): ?>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($jadwal_dosen_list as $jd): ?>
                                <span class="badge bg-light text-dark border px-2.5 py-1.5 small">
                                    <i class="bi bi-clock-fill text-primary me-1"></i>
                                    <strong><?= htmlspecialchars($jd['hari']); ?>:</strong> <?= date('H:i', strtotime($jd['jam_mulai'])); ?> - <?= date('H:i', strtotime($jd['jam_selesai'])); ?> WIB
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted small mb-0">
                            <i class="bi bi-info-circle me-1"></i>Jadwal konsultasi tatap muka / daring dapat disepakati secara fleksibel melalui fitur Chat atau WhatsApp.
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="modal-footer bg-light border-top d-flex justify-content-between">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
                <div class="d-flex gap-2">
                    <a href="jadwal_bimbingan.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-calendar-week me-1"></i>Jadwal Bimbingan
                    </a>
                    <?php if (!empty($mhs['no_whatsapp'])): ?>
                        <a href="https://wa.me/<?= $clean_wa; ?>" target="_blank" class="btn btn-success btn-sm">
                            <i class="bi bi-whatsapp me-1"></i>WhatsApp
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var modalTrigger = document.querySelectorAll('[data-bs-target="#modalLihatFotoDosen"]');
    modalTrigger.forEach(function(el) {
        el.setAttribute('data-bs-target', '#modalProfilDosen');
    });
});
</script>

<?php
require_once '../templates/footer.php';
?>
