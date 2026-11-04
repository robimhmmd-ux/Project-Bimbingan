<?php
// unduh_berkas.php - Streaming & Pengunduhan Berkas Draft Skripsi (TiDB Cloud & Local Storage)
require_once __DIR__ . '/config/database.php';

// Verifikasi sesi login pengguna (Admin, Dosen, atau Mahasiswa)
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$filename = isset($_GET['file']) ? trim($_GET['file']) : '';

if ($id <= 0 && empty($filename)) {
    http_response_code(400);
    die("Parameter berkas tidak valid.");
}

/**
 * Generator dokumen PDF resmi dinamis ketika berkas fisik belum sinkron ke cloud.
 */
function generate_notice_pdf(string $filename, string $mhs_nama = '', string $bab = ''): string {
    $lines = [
        "SISTEM INFORMASI BIMBINGAN SKRIPSI",
        "Pemberitahuan Berkas Dokumen",
        "--------------------------------------------------------------------------------",
        "Nama Berkas : " . $filename,
        "Mahasiswa   : " . ($mhs_nama ?: "Mahasiswa Bimbingan"),
        "Tahap/Bab   : " . ($bab ?: "Naskah Skripsi"),
        "--------------------------------------------------------------------------------",
        "",
        "CATATAN PENTING:",
        "Berkas dokumen ini tercatat diunggah sebelum integrasi penyimpanan",
        "cloud TiDB aktif, sehingga data biner berkas fisik belum tersimpan di server.",
        "",
        "TINDAKAN YANG DIPERLUKAN:",
        "1. Mahasiswa dapat mengunggah ulang berkas naskah PDF terbaru melalui menu",
        "   'Upload Draf Skripsi' atau 'Pengajuan Progres'.",
        "2. Setelah diunggah ulang, dokumen akan otomatis tersimpan permanen di cloud",
        "   dan dapat langsung dipratinjau (preview) maupun diunduh oleh dosen pembimbing.",
    ];

    $stream_content = "";
    $y = 760;
    foreach ($lines as $line) {
        $font_size = 10;
        if (str_contains($line, "SISTEM INFORMASI")) {
            $font_size = 14;
        } elseif (str_contains($line, "Pemberitahuan")) {
            $font_size = 12;
        } elseif (str_contains($line, "CATATAN") || str_contains($line, "TINDAKAN")) {
            $font_size = 11;
        }
        $escaped = addcslashes($line, "()\\");
        $stream_content .= "BT /F1 $font_size Tf 50 $y Td ($escaped) Tj ET\n";
        $y -= ($font_size >= 12 ? 22 : 16);
    }

    $stream_len = strlen($stream_content);

    $obj1 = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
    $obj2 = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
    $obj3 = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n";
    $obj4 = "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
    $obj5 = "5 0 obj\n<< /Length $stream_len >>\nstream\n" . $stream_content . "endstream\nendobj\n";

    $header = "%PDF-1.4\n";
    $offset1 = strlen($header);
    $offset2 = $offset1 + strlen($obj1);
    $offset3 = $offset2 + strlen($obj2);
    $offset4 = $offset3 + strlen($obj3);
    $offset5 = $offset4 + strlen($obj4);
    $xref_offset = $offset5 + strlen($obj5);

    $xref = sprintf(
        "xref\n0 6\n0000000000 65535 f \n%010d 00000 n \n%010d 00000 n \n%010d 00000 n \n%010d 00000 n \n%010d 00000 n \n",
        $offset1, $offset2, $offset3, $offset4, $offset5
    );

    $trailer = "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$xref_offset\n%%EOF";

    return $header . $obj1 . $obj2 . $obj3 . $obj4 . $obj5 . $xref . $trailer;
}

try {
    $row = null;
    if ($id > 0) {
        $stmt = $pdo->prepare("
            SELECT p.id, p.mahasiswa_id, p.file_draft, p.file_draft_data, p.bab_pembahasan, m.nama_lengkap, m.nim 
            FROM pengajuan_bimbingan p 
            LEFT JOIN mahasiswa m ON p.mahasiswa_id = m.id 
            WHERE p.id = :id LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && !empty($filename)) {
        $clean_file = basename($filename);
        $stmt = $pdo->prepare("
            SELECT p.id, p.mahasiswa_id, p.file_draft, p.file_draft_data, p.bab_pembahasan, m.nama_lengkap, m.nim 
            FROM pengajuan_bimbingan p 
            LEFT JOIN mahasiswa m ON p.mahasiswa_id = m.id 
            WHERE p.file_draft = :f LIMIT 1
        ");
        $stmt->execute([':f' => $clean_file]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    $disposition = (isset($_GET['download']) && $_GET['download'] === '1') ? 'attachment' : 'inline';
    $binary = false;
    $display_name = !empty($row['file_draft']) ? basename($row['file_draft']) : ($filename ?: 'dokumen_bimbingan.pdf');

    // 1. Data biner / Base64 dari TiDB Cloud
    if ($row && !empty($row['file_draft_data'])) {
        $data = $row['file_draft_data'];
        if (preg_match('/^data:[^;]+;base64,(.+)$/s', $data, $matches)) {
            $data = $matches[1];
        } elseif (str_starts_with($data, 'data:application/pdf;base64,')) {
            $data = substr($data, 28);
        }
        $decoded = base64_decode($data, true);
        if ($decoded !== false && strlen($decoded) > 0) {
            $binary = $decoded;
        }
    }

    // 2. Fallback: Berkas fisik di direktori assets/uploads/
    if (!$binary) {
        $target_file = !empty($row['file_draft']) ? $row['file_draft'] : $filename;
        if (!empty($target_file)) {
            $local_path = __DIR__ . '/assets/uploads/' . basename($target_file);
            if (file_exists($local_path)) {
                $file_contents = @file_get_contents($local_path);
                if ($file_contents !== false && strlen($file_contents) > 0) {
                    $binary = $file_contents;
                }
            }
        }
    }

    // 3. Fallback Khusus: Jika data biner kosong (naskah lama sebelum cloud sync), hasilkan dokumen PDF resmi
    if (!$binary) {
        $mhs_name = $row['nama_lengkap'] ?? 'Mahasiswa Bimbingan';
        $bab_name = $row['bab_pembahasan'] ?? 'Naskah Skripsi';
        $binary = generate_notice_pdf($display_name, $mhs_name, $bab_name);
        if (!str_ends_with(strtolower($display_name), '.pdf')) {
            $display_name .= '.pdf';
        }
    }

    // Tentukan Content-Type berdasarkan ekstensi berkas
    $ext = strtolower(pathinfo($display_name, PATHINFO_EXTENSION));
    $content_type = match ($ext) {
        'pdf'   => 'application/pdf',
        'docx'  => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'doc'   => 'application/msword',
        default => 'application/pdf',
    };

    // Bersihkan buffer output sebelum streaming berkas
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: ' . $content_type);
    header('X-Frame-Options: SAMEORIGIN');
    header('Content-Disposition: ' . $disposition . '; filename="' . $display_name . '"');
    header('Content-Length: ' . strlen($binary));
    header('Cache-Control: private, max-age=86400');
    header('Pragma: private');
    echo $binary;
    exit;

} catch (PDOException $e) {
    http_response_code(500);
    die("Terjadi kesalahan sistem saat memproses berkas.");
}
