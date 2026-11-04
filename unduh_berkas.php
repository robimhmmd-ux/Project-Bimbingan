<?php
// unduh_berkas.php - Streaming & Pengunduhan Berkas Draft Skripsi (TiDB Cloud / Local Storage)
require_once __DIR__ . '/config/database.php';

// Verifikasi sesi login pengguna (Admin, Dosen, atau Mahasiswa)
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$filename = isset($_GET['file']) ? trim($_GET['file']) : '';

if ($id <= 0 && empty($filename)) {
    http_response_code(400);
    die("Parameter berkas tidak valid.");
}

try {
    $row = null;
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT id, file_draft, file_draft_data FROM pengajuan_bimbingan WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && !empty($filename)) {
        $clean_file = basename($filename);
        $stmt = $pdo->prepare("SELECT id, file_draft, file_draft_data FROM pengajuan_bimbingan WHERE file_draft = :f LIMIT 1");
        $stmt->execute([':f' => $clean_file]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    $disposition = isset($_GET['download']) && $_GET['download'] === '1' ? 'attachment' : 'inline';

    // 1. Data biner / Base64 dari TiDB Cloud
    if ($row && !empty($row['file_draft_data'])) {
        $data = $row['file_draft_data'];
        if (str_starts_with($data, 'data:application/pdf;base64,')) {
            $data = substr($data, strlen('data:application/pdf;base64,'));
        }
        $binary = base64_decode($data);
        if ($binary !== false && strlen($binary) > 0) {
            $display_name = !empty($row['file_draft']) ? basename($row['file_draft']) : 'dokumen_bimbingan.pdf';
            header('Content-Type: application/pdf');
            header('X-Frame-Options: SAMEORIGIN');
            header('Content-Disposition: ' . $disposition . '; filename="' . $display_name . '"');
            header('Content-Length: ' . strlen($binary));
            header('Cache-Control: private, max-age=86400');
            header('Pragma: private');
            echo $binary;
            exit;
        }
    }

    // 2. Fallback: Berkas fisik di direktori assets/uploads/
    $target_file = !empty($row['file_draft']) ? $row['file_draft'] : $filename;
    if (!empty($target_file)) {
        $local_path = __DIR__ . '/assets/uploads/' . basename($target_file);
        if (file_exists($local_path)) {
            $display_name = basename($target_file);
            header('Content-Type: application/pdf');
            header('X-Frame-Options: SAMEORIGIN');
            header('Content-Disposition: ' . $disposition . '; filename="' . $display_name . '"');
            header('Content-Length: ' . filesize($local_path));
            header('Cache-Control: private, max-age=86400');
            header('Pragma: private');
            readfile($local_path);
            exit;
        }
    }

    // Berkas tidak ditemukan
    http_response_code(404);
    echo '<!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Berkas Tidak Ditemukan</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light d-flex align-items-center justify-content-center" style="min-height: 100vh;">
        <div class="card shadow-sm p-4 text-center" style="max-width: 450px;">
            <div class="text-danger mb-3" style="font-size: 3rem;">📄</div>
            <h5 class="fw-bold text-dark">Berkas Dokumen Tidak Ditemukan</h5>
            <p class="text-secondary small">Dokumen bimbingan ini belum tersedia atau berkas fisik belum diunggah secara lengkap ke database.</p>
            <div>
                <button onclick="window.close()" class="btn btn-secondary btn-sm me-2">Tutup Tab</button>
                <a href="javascript:history.back()" class="btn btn-primary btn-sm">Kembali</a>
            </div>
        </div>
    </body>
    </html>';
    exit;

} catch (PDOException $e) {
    http_response_code(500);
    die("Terjadi kesalahan sistem saat memproses berkas.");
}

