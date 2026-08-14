<?php
/**
 * Database Configuration & Helper Functions for the FARM Project.
 *
 * Copy this file to api/db.php and update your credentials.
 * Do NOT commit api/db.php to version control.
 *
 * Recommended Environment Variables (e.g. Render/Supabase):
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, DB_DRIVER
 */

declare(strict_types=1);

// Database connection parameters
define('DB_DRIVER',  getenv('DB_DRIVER') ?: 'pgsql'); // 'pgsql' for PostgreSQL/Supabase, 'mysql' for MySQL
define('DB_HOST',    getenv('DB_HOST')   ?: '127.0.0.1');
define('DB_PORT',    getenv('DB_PORT')   ?: (DB_DRIVER === 'pgsql' ? '5432' : '3306'));
define('DB_NAME',    getenv('DB_NAME')   ?: 'farm_db');
define('DB_USER',    getenv('DB_USER')   ?: 'postgres');
define('DB_PASS',    getenv('DB_PASS')   ?: '');

// Shopkeeper default identifier
define('SHOP_USERNAME', getenv('SHOP_USERNAME') ?: 'Deepak');

/**
 * Returns a shared PDO database connection instance.
 */
function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $driver = DB_DRIVER;
    $host   = DB_HOST;
    $port   = DB_PORT;
    $dbname = DB_NAME;

    if ($driver === 'pgsql') {
        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
    } else {
        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    }

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database connection error: ' . $e->getMessage()]);
        exit;
    }

    return $pdo;
}

/* =====================================================
 * Shared Session & Security Helpers
 * ===================================================== */

function start_session_once(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function json_input(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_validate(): void {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
        $headers = getallheaders();
        $token = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? $_GET['csrf'] ?? '';
        
        // Skip validation only for login endpoint where token is freshly generated
        $action = $_GET['action'] ?? '';
        if ($action === 'shopLogin') {
            return;
        }

        if (!$token || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid or missing CSRF token']);
            exit;
        }
    }
}

function require_shopkeeper(): void {
    if (empty($_SESSION['shopkeeper'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized. Shopkeeper login required.']);
        exit;
    }
}

function shopkeeper_credentials(): ?array {
    $stmt = db()->prepare('SELECT id, username, password_hash FROM public.shopkeeper_credentials LIMIT 1');
    $stmt->execute();
    $row = $stmt->fetch();
    return $row ?: null;
}

function shop_profile(): array {
    static $profile = null;
    if ($profile !== null) {
        return $profile;
    }
    
    $stmt = db()->prepare('SELECT * FROM public.shop_profile WHERE id = 1');
    $stmt->execute();
    $profile = $stmt->fetch() ?: [];
    return $profile;
}

function refresh_shop_profile_cache(): array {
    $stmt = db()->prepare('SELECT * FROM public.shop_profile WHERE id = 1');
    $stmt->execute();
    return $stmt->fetch() ?: [];
}