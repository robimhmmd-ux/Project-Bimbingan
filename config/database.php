<?php
// config/database.php - Koneksi TiDB Cloud via PDO dengan SSL

$host     = getenv('TIDB_HOST') ?: 'gateway01.ap-southeast-1.prod.aws.tidbcloud.com';
$port     = getenv('TIDB_PORT') ?: 4000;
$dbname   = getenv('TIDB_DBNAME') ?: 'db_bimbingan';
$username = getenv('TIDB_USER') ?: '2YxsU8MC4wahbzf.root';
$password = getenv('TIDB_PASSWORD') ?: 'x6Gt5Cf4Uf1jYLyr';

try {
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
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
} catch (PDOException $e) {
    die("Koneksi Database TiDB Gagal: " . $e->getMessage());
}
?>
