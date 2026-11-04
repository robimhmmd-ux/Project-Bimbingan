<?php
// config/database.php - Koneksi TiDB Cloud via PDO dengan SSL & Auto-Retry Resilient

$host     = getenv('TIDB_HOST') ?: 'gateway01.ap-southeast-1.prod.aws.tidbcloud.com';
$port     = getenv('TIDB_PORT') ?: 4000;
$dbname   = getenv('TIDB_DBNAME') ?: 'db_bimbingan';
$username = getenv('TIDB_USER') ?: '2YxsU8MC4wahbzf.root';
$password = getenv('TIDB_PASSWORD') ?: 'x6Gt5Cf4Uf1jYLyr';

$max_retries = 3;
$retry_count = 0;
$pdo = null;
$last_exception = null;

while ($retry_count < $max_retries) {
    try {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 12, // Toleransi waktu respon cloud 12 detik
        ];

        // Deteksi SSL CA Bundle: Windows XAMPP vs Server Linux / Vercel
        $xampp_ca = 'C:\\xampp\\apache\\bin\\curl-ca-bundle.crt';
        if (file_exists($xampp_ca)) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $xampp_ca;
        } else {
            // Lingkungan Linux, Vercel, atau server dengan sertifikat default OS
            $options[PDO::MYSQL_ATTR_SSL_CA] = true;
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }

        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4", $username, $password, $options);
        break; // Berhasil terhubung!
    } catch (PDOException $e) {
        $last_exception = $e;
        $retry_count++;
        // Jika error timeout atau host tidak merespon (2002), jeda sebentar lalu coba lagi
        if ($retry_count < $max_retries) {
            usleep(800000); // jeda 0.8 detik
            continue;
        }
    }
}

if (!$pdo) {
    $err_msg = $last_exception ? $last_exception->getMessage() : 'Gagal terhubung ke database cloud.';
    die("<!DOCTYPE html>
    <html lang='id'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Koneksi Database TiDB Cloud Sedang Menghubungkan Ulang</title>
        <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css' rel='stylesheet'>
        <link href='https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css' rel='stylesheet'>
    </head>
    <body class='bg-light d-flex align-items-center justify-content-center min-vh-100 p-3'>
        <div class='card shadow-sm border-0 text-center p-4' style='max-width: 520px; border-radius: 16px;'>
            <div class='mb-3'>
                <div class='d-inline-flex align-items-center justify-content-center bg-warning bg-opacity-10 text-warning rounded-circle' style='width: 64px; height: 64px;'>
                    <i class='bi bi-cloud-slash fs-1 text-warning'></i>
                </div>
            </div>
            <h5 class='fw-bold text-dark mb-2'>Koneksi Database TiDB Cloud Terputus Sementara</h5>
            <p class='text-muted small mb-3'>
                Cluster TiDB Cloud kemungkinan sedang dalam proses <em>wake-up (cold start)</em> atau koneksi internet sedang mengalami jeda respon (timeout).
            </p>
            <div class='alert alert-secondary text-start py-2 px-3 small mb-3 font-monospace' style='font-size: 0.75rem; word-break: break-all;'>
                " . htmlspecialchars($err_msg) . "
            </div>
            <button onclick='window.location.reload()' class='btn btn-primary fw-semibold px-4 py-2 rounded-pill shadow-xs'>
                <i class='bi bi-arrow-clockwise me-1'></i>Coba Hubungkan Kembali
            </button>
        </div>
    </body>
    </html>");
}

// Muat fungsi pembantu progres dan deteksi judul
require_once __DIR__ . '/progres_helper.php';

// Muat fungsi pembantu kategori Program Studi (Prodi)
require_once __DIR__ . '/prodi_helper.php';

// Inisialisasi sesi aplikasi terpusat (TiDB Cloud) secara otomatis
if (file_exists(__DIR__ . '/session_helper.php')) {
    require_once __DIR__ . '/session_helper.php';
    init_app_session($pdo);
}
?>
