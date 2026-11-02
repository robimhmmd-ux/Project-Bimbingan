<?php
// config/storage_helper.php - Manajemen Retensi Penyimpanan Berkas & Foto Profil

/**
 * Hapus berkas draf lama jika jumlah berkas bimbingan melebihi batas retensi.
 * Aturan: Saat mengirim berkas ke-3 (revisi ke-2), berkas pertama (01) otomatis dibersihkan dari DB.
 * Hanya menyimpan maksimal $keep_count draf terbaru per mahasiswa & bab pembahasan.
 *
 * @param PDO $pdo Koneksi database PDO
 * @param int $mahasiswa_id ID Mahasiswa
 * @param string $bab_pembahasan Bab / Tahap Bimbingan
 * @param int $keep_count Maksimal berkas yang dipertahankan (default: 2)
 * @return int Jumlah berkas lama yang dibersihkan
 */
function cleanup_old_draft_files(PDO $pdo, int $mahasiswa_id, string $bab_pembahasan, int $keep_count = 2): int {
    if ($mahasiswa_id <= 0 || empty($bab_pembahasan)) {
        return 0;
    }

    try {
        // Cari riwayat pengajuan bimbingan sebelumnya yang masih menyimpan berkas PDF
        $stmt = $pdo->prepare("
            SELECT id, file_draft 
            FROM pengajuan_bimbingan 
            WHERE mahasiswa_id = :mid 
              AND bab_pembahasan = :bab 
              AND (file_draft IS NOT NULL OR file_draft_data IS NOT NULL)
            ORDER BY id ASC
        ");
        $stmt->execute([
            ':mid' => $mahasiswa_id,
            ':bab' => $bab_pembahasan
        ]);
        $existing = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total_existing = count($existing);

        // Jika setelah penambahan 1 file baru totalnya akan melebihi keep_count
        if ($total_existing >= $keep_count) {
            $purge_count = $total_existing - ($keep_count - 1);
            $to_purge = array_slice($existing, 0, $purge_count);

            $stmt_clean = $pdo->prepare("
                UPDATE pengajuan_bimbingan 
                SET file_draft = NULL, 
                    file_draft_data = NULL 
                WHERE id = :id
            ");

            $purged = 0;
            foreach ($to_purge as $row) {
                // Hapus file fisik lokal jika ada
                if (!empty($row['file_draft'])) {
                    $file_name = basename($row['file_draft']);
                    $path = __DIR__ . '/../assets/uploads/' . $file_name;
                    if (file_exists($path)) {
                        @unlink($path);
                    }
                }

                // Bersihkan data biner dari TiDB Cloud
                $stmt_clean->execute([':id' => $row['id']]);
                $purged++;
            }

            return $purged;
        }
    } catch (Exception $e) {
        // Abaikan kegagalan pembersihan agar proses upload utama tetap berjalan
    }

    return 0;
}

/**
 * Hapus foto profil lama saat pengguna mengunggah foto profil baru.
 * Menghapus berkas fisik lokal lama jika ada di server.
 *
 * @param string|null $old_foto Nilai foto profil lama
 * @return bool True jika berkas fisik lama berhasil dihapus atau bukan berkas lokal
 */
function delete_old_profile_photo(?string $old_foto): bool {
    if (empty($old_foto)) {
        return false;
    }

    // Abaikan default avatar dan Base64 Data URI
    if ($old_foto === 'default.png' || str_starts_with($old_foto, 'data:image/')) {
        return true;
    }

    $clean_name = basename($old_foto);
    $paths = [
        __DIR__ . '/../assets/uploads/' . $clean_name,
        __DIR__ . '/../assets/uploads/profile/' . $clean_name
    ];

    $deleted = false;
    foreach ($paths as $p) {
        if (file_exists($p)) {
            @unlink($p);
            $deleted = true;
        }
    }

    return $deleted;
}

