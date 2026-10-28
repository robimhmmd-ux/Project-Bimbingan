<?php
// login.php - Autentikasi Pengguna Multi-Role
session_start();

// Jika sudah login, redirect sesuai role
if (isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'admin') header("Location: admin/index.php");
    elseif ($_SESSION['role'] === 'dosen') header("Location: dosen/index.php");
    elseif ($_SESSION['role'] === 'mahasiswa') header("Location: mahasiswa/index.php");
    exit;
}

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'config/database.php';
    
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    if (!empty($username) && !empty($password)) {
        try {
            $stmt = $pdo->prepare("SELECT id, username, password, role FROM users WHERE username = :username LIMIT 1");
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id']  = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role']     = $user['role'];
                
                // Arahkan ke dashboard masing-masing
                if ($user['role'] === 'admin') {
                    header("Location: admin/index.php");
                } elseif ($user['role'] === 'dosen') {
                    header("Location: dosen/index.php");
                } elseif ($user['role'] === 'mahasiswa') {
                    header("Location: mahasiswa/index.php");
                }
                exit;
            } else {
                $error_message = 'Username atau password yang Anda masukkan salah.';
            }
        } catch (PDOException $e) {
            $error_message = 'Terjadi kesalahan sistem saat login: ' . $e->getMessage();
        }
    } else {
        $error_message = 'Mohon isi username dan password.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Sistem Bimbingan Skripsi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center min-vh-100">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-5 col-lg-4">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <div class="text-center mb-4">
                        <i class="bi bi-mortarboard-fill text-primary" style="font-size: 3rem;"></i>
                        <h4 class="fw-bold mt-2">Masuk ke Portal</h4>
                        <p class="text-muted small">Sistem Bimbingan & Progres Skripsi</p>
                    </div>

                    <?php if (!empty($error_message)): ?>
                        <div class="alert alert-danger py-2 small" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i><?= htmlspecialchars($error_message); ?>
                        </div>
                    <?php endif; ?>

                    <form action="login.php" method="POST">
                        <div class="mb-3">
                            <label for="username" class="form-label small fw-semibold">Username</label>
                            <input type="text" class="form-control" id="username" name="username" placeholder="Masukkan username" required autofocus>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label small fw-semibold">Password</label>
                            <input type="password" class="form-control" id="password" name="password" placeholder="Masukkan password" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
                            <i class="bi bi-box-arrow-in-right me-1"></i>Masuk
                        </button>
                    </form>

                    <div class="mt-4 p-3 bg-light rounded small text-muted border">
                        <strong>Akun Uji Coba:</strong><br>
                        - Admin: <code>admin</code> / <code>password123</code><br>
                        - Dosen: <code>dosen1</code> / <code>password123</code><br>
                        - Mahasiswa: <code>mhs1</code> / <code>password123</code>
                    </div>
                </div>
            </div>
            <p class="text-center text-muted small mt-3">&copy; <?= date('Y'); ?> Tim Pemrograman Web Dasar</p>
        </div>
    </div>
</div>
</body>
</html>
