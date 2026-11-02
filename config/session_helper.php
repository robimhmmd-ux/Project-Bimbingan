<?php
// config/session_helper.php - Manajemen Sesi Terpusat & Persisten untuk Serverless & Cloud (TiDB Cloud)

class TiDBSessionHandler implements SessionHandlerInterface {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function open(string $path, string $name): bool {
        return true;
    }

    public function close(): bool {
        return true;
    }

    public function read(string $id): string|false {
        try {
            $stmt = $this->pdo->prepare("SELECT data FROM user_sessions WHERE id = :id AND last_activity > :exp LIMIT 1");
            $stmt->execute([
                ':id'  => $id,
                ':exp' => time() - (86400 * 30) // Berlaku 30 hari
            ]);
            $data = $stmt->fetchColumn();
            return ($data !== false) ? (string)$data : '';
        } catch (Exception $e) {
            return '';
        }
    }

    public function write(string $id, string $data): bool {
        try {
            $user_id = $_SESSION['user_id'] ?? null;
            $role    = $_SESSION['role'] ?? null;
            $now     = time();

            $stmt = $this->pdo->prepare("
                INSERT INTO user_sessions (id, user_id, role, data, last_activity) 
                VALUES (:id, :uid, :role, :data, :act)
                ON DUPLICATE KEY UPDATE 
                    user_id = VALUES(user_id),
                    role = VALUES(role),
                    data = VALUES(data),
                    last_activity = VALUES(last_activity)
            ");
            return $stmt->execute([
                ':id'   => $id,
                ':uid'  => $user_id,
                ':role' => $role,
                ':data' => $data,
                ':act'  => $now
            ]);
        } catch (Exception $e) {
            return false;
        }
    }

    public function destroy(string $id): bool {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM user_sessions WHERE id = :id");
            return $stmt->execute([':id' => $id]);
        } catch (Exception $e) {
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM user_sessions WHERE last_activity < :exp");
            $stmt->execute([':exp' => time() - $max_lifetime]);
            return $stmt->rowCount();
        } catch (Exception $e) {
            return false;
        }
    }
}

/**
 * Inisialisasi sesi aplikasi secara aman, persisten, dan bebas dari kendala serverless Vercel.
 */
function init_app_session(?PDO $pdo = null): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $is_secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    
    session_set_cookie_params([
        'lifetime' => 86400 * 30, // 30 hari
        'path'     => '/',
        'domain'   => '',
        'secure'   => $is_secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    // Pasang session handler database jika koneksi PDO tersedia
    if ($pdo instanceof PDO) {
        $handler = new TiDBSessionHandler($pdo);
        session_set_save_handler($handler, true);
    }

    session_start();
}
