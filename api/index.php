<?php
// api/index.php - Serverless Router for Vercel
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$rootDir = realpath(__DIR__ . '/..');

// Handle root path
if ($uri === '/' || $uri === '' || empty($uri)) {
    $target = $rootDir . DIRECTORY_SEPARATOR . 'index.php';
} else {
    $target = realpath($rootDir . $uri);
}

// If directory, look for index.php inside it
if ($target && is_dir($target)) {
    $target = realpath($target . DIRECTORY_SEPARATOR . 'index.php');
}

// Ensure target exists and is a PHP file within project root (security check)
if ($target && file_exists($target) && str_ends_with($target, '.php') && str_starts_with($target, $rootDir)) {
    chdir(dirname($target));
    require $target;
    exit;
}

http_response_code(404);
echo "Halaman tidak ditemukan (404 Not Found)";
?>