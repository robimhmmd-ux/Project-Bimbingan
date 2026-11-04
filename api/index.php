<?php
// api/index.php - Serverless Router & Clean URL Resolver for Vercel
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$rootDir = realpath(__DIR__ . '/..');

// 1. Redirect Canonical GET dari file.php ke Clean URL (menghilangkan .php dari URL)
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET' && str_ends_with($uri, '.php') && !str_starts_with($uri, '/api/') && $uri !== '/unduh_berkas.php') {
    $cleanPath = substr($uri, 0, -4);
    if (str_ends_with($cleanPath, '/index')) {
        $cleanPath = substr($cleanPath, 0, -5);
        if ($cleanPath === '') {
            $cleanPath = '/';
        }
    }
    $query = $_SERVER['QUERY_STRING'] ?? '';
    $redirectUrl = $cleanPath . ($query !== '' ? '?' . $query : '');
    header("Location: " . $redirectUrl, true, 301);
    exit;
}

// 2. Tangani root path & rute dinamis berkas
if ($uri === '/' || $uri === '' || empty($uri)) {
    $target = $rootDir . DIRECTORY_SEPARATOR . 'index.php';
} elseif (str_starts_with($uri, '/assets/uploads/')) {
    $_GET['file'] = basename($uri);
    $target = $rootDir . DIRECTORY_SEPARATOR . 'unduh_berkas.php';
} else {
    $target = realpath($rootDir . $uri);
}

// 3. Resolusi Clean URL: jika path tidak ada, cek apakah berkas dengan akhiran .php ada
if (!$target || !file_exists($target)) {
    $candidate = realpath($rootDir . $uri . '.php');
    if ($candidate && file_exists($candidate)) {
        $target = $candidate;
    }
}

// 4. Jika direktori (misal /dosen atau /mahasiswa), muat index.php di dalamnya
if ($target && is_dir($target)) {
    $target = realpath($target . DIRECTORY_SEPARATOR . 'index.php');
}

// 5. Pastikan target ada dan merupakan file PHP yang valid dalam root direktori
if ($target && file_exists($target) && str_ends_with($target, '.php') && str_starts_with($target, $rootDir)) {
    chdir(dirname($target));
    require $target;
    exit;
}

http_response_code(404);
echo "Halaman tidak ditemukan (404 Not Found)";
?>
