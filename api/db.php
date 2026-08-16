<?php
/**
 * Database connection (PDO) and shared helpers for Supabase PostgreSQL.
 *
 * SECURITY NOTE
 * -------------
 * The shopkeeper password is NOT stored in this file. It is stored as a
 * bcrypt hash in the `shopkeeper_credentials` table. To set or rotate the
 * shopkeeper password, run:
 *
 *     php api/setup_shopkeeper.php set <username> <new-password>
 *
 * See DEPLOY.md for full setup instructions.
 */

declare(strict_types=1);

/* Database credentials.
 *
 * Default to the local XAMPP/MySQL setup used in this workspace.
 * Override via environment variables for Supabase, Render, or another server.
 *
 * If DATABASE_URL is set (Supabase / Render / Heroku style), it takes
 * precedence and we parse it into DB_DRIVER / DB_HOST / DB_PORT / DB_NAME
 * / DB_USER / DB_PASS automatically.
 */
function parse_database_url(): array {
    $url = getenv('DATABASE_URL') ?: '';
    if ($url === '') return [];
    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) return [];
    $scheme = $parts['scheme'] ?? 'postgresql';
    $driver = ($scheme === 'postgresql' || $scheme === 'postgres') ? 'pgsql' : 'mysql';
    // Forward any libpq URL query options (sslmode, channel_binding, sslcert, …)
    // into the PDO DSN as `key=value` pairs. `endpoint` (Neon SNI fallback) is
    // only added if the runtime libpq is too old to support SNI.
    $dsnOptions = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $q);
        // Only forward DSN options that libpq reliably understands across
        // versions. channel_binding / application_name are not valid DSN keys
        // in older libpq (XAMPP ships libpq 11).
        foreach (['sslmode', 'sslrootcert', 'sslcert', 'sslkey', 'connect_timeout'] as $opt) {
            if (isset($q[$opt])) $dsnOptions[$opt] = (string) $q[$opt];
        }
    }
    if (!function_exists('libpq_version')) {
        // Old libpq without SNI — for Neon pooler we need to pass endpoint= explicitly.
        if ($driver === 'pgsql' && substr($parts['host'], -9) === '.neon.tech') {
            $first = explode('.', $parts['host'])[0];
            $endpoint = preg_replace('/-pooler$/', '', $first);
            if ($endpoint !== '' && strpos($endpoint, 'ep-') === 0) {
                $dsnOptions['endpoint'] = $endpoint;
            }
        }
    } else {
        $v = libpq_version();
        $major = (int) (explode('.', $v)[0] ?? 0);
        if ($major < 14 && $driver === 'pgsql' && substr($parts['host'], -9) === '.neon.tech') {
            $first = explode('.', $parts['host'])[0];
            $endpoint = preg_replace('/-pooler$/', '', $first);
            if ($endpoint !== '' && strpos($endpoint, 'ep-') === 0) {
                $dsnOptions['endpoint'] = $endpoint;
            }
        }
    }
    return [
        'driver' => $driver,
        'host'   => $parts['host'],
        'port'   => isset($parts['port']) ? (string)$parts['port'] : ($driver === 'pgsql' ? '5432' : '3306'),
        'name'   => isset($parts['path']) ? ltrim($parts['path'], '/') : ($driver === 'pgsql' ? 'postgres' : ''),
        'user'   => isset($parts['user']) ? urldecode($parts['user']) : '',
        'pass'   => isset($parts['pass']) ? urldecode($parts['pass']) : '',
        'options'=> $dsnOptions,
    ];
}

// Hardcoded Neon fallback so this works even if the Render env var is
// not set. If you rotate the Neon credentials, update this line too.
$NEON_FALLBACK_URL = 'postgresql://neondb_owner:npg_JGyzNDL51dph@ep-muddy-silence-b32psz9e-pooler.c-4.ap-southeast-1.aws.neon.tech/Deepak_db?sslmode=require';

$_dburl = parse_database_url();
if (empty($_dburl) || empty($_dburl['host'])) {
    putenv('DATABASE_URL=' . $NEON_FALLBACK_URL);
    $_dburl = parse_database_url();
}
$parsedDriver = $_dburl['driver'] ?? 'mysql';
$parsedHost   = $_dburl['host'] ?? '127.0.0.1';
$parsedPort   = $_dburl['port'] ?? (getenv('DB_DRIVER') === 'pgsql' || $parsedDriver === 'pgsql' ? '5432' : '3306');
// For local XAMPP/MySQL, default to the project database used in farm.sql.
$parsedName   = $_dburl['name'] ?? (($parsedDriver === 'pgsql') ? 'postgres' : 'farm_db');
$parsedUser   = $_dburl['user'] ?? 'root';
$parsedPass   = $_dburl['pass'] ?? '';
$parsedOpts   = $_dburl['options'] ?? [];

define('DB_DRIVER',     getenv('DB_DRIVER')     ?: $parsedDriver);
define('DB_HOST',       getenv('DB_HOST')       ?: $parsedHost);
define('DB_PORT',       getenv('DB_PORT')       ?: $parsedPort);
define('DB_NAME',       getenv('DB_NAME')       ?: $parsedName);
define('DB_USER',       getenv('DB_USER')       ?: $parsedUser);
define('DB_PASS',       getenv('DB_PASS')       ?: $parsedPass);
define('DB_DSN_OPTIONS',$parsedOpts);
define('SHOP_USERNAME', getenv('SHOP_USERNAME') ?: 'Deepak');

function db_table(string $name): string {
    return DB_DRIVER === 'pgsql' ? 'public.' . $name : $name;
}

unset($_dburl);

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $driver = DB_DRIVER;
    $host   = DB_HOST;
    $port   = DB_PORT;
    // Default to the project MySQL database when running locally.
    $dbname = DB_NAME ?: ($driver === 'pgsql' ? 'postgres' : 'farm_db');

    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        if ($driver === 'pgsql') {
            $dsn = 'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $dbname . ';sslmode=require';
            foreach (DB_DSN_OPTIONS as $k => $v) {
                $dsn .= ';' . $k . '=' . $v;
            }
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
        } else {
            $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $dbname . ';charset=utf8mb4';
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
        }
    } catch (PDOException $e) {
        if ($driver === 'mysql') {
            try {
                $adminDsn = 'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4';
                $admin = new PDO($adminDsn, DB_USER, DB_PASS, $opts);
                $admin->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $dbname) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $dbname . ';charset=utf8mb4';
                $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
            } catch (PDOException $retry) {
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Database connection failed: ' . $retry->getMessage()]);
                exit;
            }
        } else {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
            exit;
        }
    }

    try {
        ensure_schema($pdo);
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Schema bootstrap failed: ' . $e->getMessage()]);
        exit;
    }

    return $pdo;
}

/**
 * PostgreSQL schema bootstrap & migration checks.
 * Ensures initial default data exists safely in Supabase.
 */
function ensure_schema(PDO $pdo): void {
    $driver = DB_DRIVER;

    if ($driver === 'pgsql') {
        // Create ENUMs safely — wrapped in try-catch for PostgreSQL compatibility
        try {
            $pdo->exec("CREATE TYPE IF NOT EXISTS otp_target_kind AS ENUM ('user', 'shopkeeper')");
        } catch (PDOException) {
            // Type may already exist or schema permission issue — continue safely
        }
        
        try {
            $pdo->exec("CREATE TYPE IF NOT EXISTS reply_target_kind AS ENUM ('comment', 'rating')");
        } catch (PDOException) {
            // Type may already exist or schema permission issue — continue safely
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS public.users (
          id INT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
          name VARCHAR(100) NOT NULL,
          email VARCHAR(190) UNIQUE NOT NULL,
          password_hash VARCHAR(255) NOT NULL,
          avatar VARCHAR(255) DEFAULT NULL,
          created_at TIMESTAMPTZ DEFAULT NOW()
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS public.shop_profile (
          id INT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
          shop_name VARCHAR(120) NOT NULL DEFAULT 'Depak Tiles & Granite',
          owner_name VARCHAR(120) NOT NULL DEFAULT 'Depak',
          mobile VARCHAR(40) DEFAULT NULL,
          email VARCHAR(190) DEFAULT NULL,
          contact_note VARCHAR(255) DEFAULT NULL,
          location VARCHAR(255) DEFAULT NULL,
          about TEXT DEFAULT NULL,
          logo_path VARCHAR(255) DEFAULT NULL,
          updated_at TIMESTAMPTZ DEFAULT NOW()
        )");

        $pdo->exec("INSERT INTO public.shop_profile (id, shop_name, owner_name) 
                    VALUES (1, 'Depak Tiles & Granite', 'Depak') 
                    ON CONFLICT (id) DO NOTHING");

        $pdo->exec("CREATE TABLE IF NOT EXISTS public.shopkeeper_credentials (
          id INT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
          username VARCHAR(100) UNIQUE NOT NULL,
          password_hash VARCHAR(255) NOT NULL,
          email VARCHAR(190) DEFAULT NULL,
          failed_attempts INT NOT NULL DEFAULT 0,
          locked_until TIMESTAMPTZ DEFAULT NULL,
          last_changed TIMESTAMPTZ DEFAULT NOW()
        )");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_shopkeeper_email ON public.shopkeeper_credentials(email)");

        $pdo->exec("CREATE TABLE IF NOT EXISTS public.login_attempts (
          id INT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
          ip VARCHAR(45) NOT NULL,
          kind VARCHAR(20) NOT NULL,
          attempted_at TIMESTAMPTZ DEFAULT NOW(),
          success BOOLEAN DEFAULT FALSE
        )");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ip_kind ON public.login_attempts(ip, kind, attempted_at)");

        $pdo->exec("CREATE TABLE IF NOT EXISTS public.csrf_tokens (
          session_id VARCHAR(128) PRIMARY KEY,
          token VARCHAR(64) NOT NULL,
          created_at TIMESTAMPTZ DEFAULT NOW()
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS public.products (
          id INT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
          title VARCHAR(255) NOT NULL,
          body TEXT NOT NULL,
          price NUMERIC(10,2) DEFAULT 0,
          image_path VARCHAR(255) DEFAULT NULL,
          is_blog BOOLEAN DEFAULT FALSE,
          created_at TIMESTAMPTZ DEFAULT NOW(),
          updated_at TIMESTAMPTZ DEFAULT NOW()
        )");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_created ON public.products(created_at DESC)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_kind ON public.products(is_blog, created_at DESC)");

        $pdo->exec("CREATE TABLE IF NOT EXISTS public.comments (
          id INT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
          product_id INT NOT NULL,
          user_id INT NULL,
          guest_name VARCHAR(80) NULL,
          guest_email_hash CHAR(64) NULL,
          body TEXT NOT NULL,
          created_at TIMESTAMPTZ DEFAULT NOW()
        )");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_product ON public.comments(product_id)");

        $pdo->exec("CREATE TABLE IF NOT EXISTS public.likes (
          id INT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
          product_id INT NOT NULL,
          user_id INT NULL,
          guest_email_hash CHAR(64) NULL,
          created_at TIMESTAMPTZ DEFAULT NOW(),
          CONSTRAINT uniq_product_user UNIQUE (product_id, user_id),
          CONSTRAINT uniq_product_guest UNIQUE (product_id, guest_email_hash)
        )");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_guest ON public.likes(guest_email_hash, product_id)");

        $pdo->exec("CREATE TABLE IF NOT EXISTS public.shares (
          id INT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
          product_id INT NOT NULL,
          user_id INT NULL,
          guest_email_hash CHAR(64) NULL,
          created_at TIMESTAMPTZ DEFAULT NOW()
        )");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_product_user ON public.shares(product_id, guest_email_hash)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_created ON public.shares(user_id, created_at)");

        $pdo->exec("CREATE TABLE IF NOT EXISTS public.password_otps (
          id INT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
          target_kind otp_target_kind NOT NULL,
          target_id INT NOT NULL,
          email VARCHAR(190) NOT NULL,
          otp_hash VARCHAR(255) NOT NULL,
          reset_token VARCHAR(64) DEFAULT NULL,
          attempts INT NOT NULL DEFAULT 0,
          verified BOOLEAN DEFAULT FALSE,
          expires_at TIMESTAMPTZ NOT NULL,
          created_at TIMESTAMPTZ DEFAULT NOW()
        )");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_email_kind ON public.password_otps(email, target_kind)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_expires ON public.password_otps(expires_at)");

        $pdo->exec("CREATE TABLE IF NOT EXISTS public.ratings (
          id INT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
          product_id INT NULL,
          guest_name VARCHAR(80) NOT NULL,
          guest_email_hash CHAR(64) NOT NULL,
          stars SMALLINT NOT NULL,
          review_body TEXT NULL,
          created_at TIMESTAMPTZ DEFAULT NOW()
        )");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ratings_created ON public.ratings(created_at DESC)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ratings_product ON public.ratings(product_id, created_at DESC)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ratings_email ON public.ratings(guest_email_hash, created_at DESC)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ratings_stars ON public.ratings(stars)");

        $pdo->exec("CREATE TABLE IF NOT EXISTS public.review_replies (
          id INT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
          target_kind reply_target_kind NOT NULL,
          target_id INT NOT NULL,
          reply_body TEXT NOT NULL,
          reply_by VARCHAR(80) NOT NULL,
          created_at TIMESTAMPTZ DEFAULT NOW()
        )");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_target ON public.review_replies(target_kind, target_id, created_at)");

        // Auto-seed shopkeeper credentials on first deploy.
        // Username 'Deepak', password 'Deepak@123' (bcrypt hash below).
        // Safe to run on every boot: ON CONFLICT updates the row in place.
        $pdo->exec("INSERT INTO public.shopkeeper_credentials (username, password_hash, failed_attempts, locked_until)
                    VALUES ('Deepak', '\$2y$10\$sdJ19eUU1HjOqriN7ZhXxuFY6Jh99vBD5SS.lMJPtojMGAX2cz016', 0, NULL)
                    ON CONFLICT (username) DO UPDATE
                    SET password_hash = EXCLUDED.password_hash,
                        failed_attempts = 0,
                        locked_until = NULL");

        $pdo->exec("DROP TABLE IF EXISTS guest_identities");
        $pdo->exec("DROP TABLE IF EXISTS saves");
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(100) NOT NULL,
      email VARCHAR(190) NOT NULL UNIQUE,
      password_hash VARCHAR(255) NOT NULL,
      avatar VARCHAR(255) DEFAULT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_profile (
      id INT AUTO_INCREMENT PRIMARY KEY,
      shop_name VARCHAR(120) NOT NULL DEFAULT 'Depak Tiles & Granite',
      owner_name VARCHAR(120) NOT NULL DEFAULT 'Depak',
      mobile VARCHAR(40) DEFAULT NULL,
      email VARCHAR(190) DEFAULT NULL,
      contact_note VARCHAR(255) DEFAULT NULL,
      location VARCHAR(255) DEFAULT NULL,
      about TEXT DEFAULT NULL,
      logo_path VARCHAR(255) DEFAULT NULL,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("INSERT INTO shop_profile (id, shop_name, owner_name)
                VALUES (1, 'Depak Tiles & Granite', 'Depak')
                ON DUPLICATE KEY UPDATE id = id");

    $pdo->exec("CREATE TABLE IF NOT EXISTS shopkeeper_credentials (
      id INT AUTO_INCREMENT PRIMARY KEY,
      username VARCHAR(100) UNIQUE NOT NULL,
      password_hash VARCHAR(255) NOT NULL,
      email VARCHAR(190) DEFAULT NULL,
      failed_attempts INT NOT NULL DEFAULT 0,
      locked_until DATETIME DEFAULT NULL,
      last_changed TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
      id INT AUTO_INCREMENT PRIMARY KEY,
      ip VARCHAR(45) NOT NULL,
      kind VARCHAR(20) NOT NULL,
      attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      success TINYINT(1) DEFAULT 0
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS csrf_tokens (
      session_id VARCHAR(128) PRIMARY KEY,
      token VARCHAR(64) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
      id INT AUTO_INCREMENT PRIMARY KEY,
      title VARCHAR(255) NOT NULL,
      body LONGTEXT NOT NULL,
      price DECIMAL(10,2) DEFAULT 0,
      image_path VARCHAR(255) DEFAULT NULL,
      is_blog TINYINT(1) DEFAULT 0,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS comments (
      id INT AUTO_INCREMENT PRIMARY KEY,
      product_id INT NOT NULL,
      user_id INT NULL,
      guest_name VARCHAR(80) NULL,
      guest_email_hash CHAR(64) NULL,
      body TEXT NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS likes (
      id INT AUTO_INCREMENT PRIMARY KEY,
      product_id INT NOT NULL,
      user_id INT NULL,
      guest_email_hash CHAR(64) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_product_user (product_id, user_id),
      UNIQUE KEY uniq_product_guest (product_id, guest_email_hash)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS shares (
      id INT AUTO_INCREMENT PRIMARY KEY,
      product_id INT NOT NULL,
      user_id INT NULL,
      guest_email_hash CHAR(64) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS password_otps (
      id INT AUTO_INCREMENT PRIMARY KEY,
      target_kind ENUM('user', 'shopkeeper') NOT NULL,
      target_id INT NOT NULL,
      email VARCHAR(190) NOT NULL,
      otp_hash VARCHAR(255) NOT NULL,
      reset_token VARCHAR(64) DEFAULT NULL,
      attempts INT NOT NULL DEFAULT 0,
      verified TINYINT(1) DEFAULT 0,
      expires_at DATETIME NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ratings (
      id INT AUTO_INCREMENT PRIMARY KEY,
      product_id INT NULL,
      guest_name VARCHAR(80) NOT NULL,
      guest_email_hash CHAR(64) NOT NULL,
      stars TINYINT NOT NULL,
      review_body TEXT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS review_replies (
      id INT AUTO_INCREMENT PRIMARY KEY,
      target_kind ENUM('comment', 'rating') NOT NULL,
      target_id INT NOT NULL,
      reply_body TEXT NOT NULL,
      reply_by VARCHAR(80) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("DROP TABLE IF EXISTS guest_identities");
    $pdo->exec("DROP TABLE IF EXISTS saves");
}

/**
 * Checks if a column exists in PostgreSQL information schema.
 */
function table_has_column(PDO $pdo, string $table, string $col): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = ? AND column_name = ?");
    $stmt->execute([$table, $col]);
    return ((int)$stmt->fetchColumn()) > 0;
}

/**
 * Add a column to a table only if it doesn't exist (PostgreSQL syntax).
 */
function add_column_if_missing(PDO $pdo, string $table, string $col, string $definition): void {
    if (!table_has_column($pdo, $table, $col)) {
        $pdo->exec("ALTER TABLE \"$table\" ADD COLUMN $col $definition");
    }
}

/**
 * Profanity and link abuse filter.
 */
function profanity_check(string $body): ?string {
    $banned = [
        'fuck', 'shit', 'bitch', 'asshole', 'bastard', 'dick', 'piss',
        'cunt', 'slut', 'whore', 'nigger', 'faggot', ' retard ',
    ];
    $lc = ' ' . strtolower($body) . ' ';
    foreach ($banned as $w) {
        if (strpos($lc, $w) !== false) return 'Comment contains inappropriate language.';
    }
    // Prevent link spam
    $links = preg_match_all('#https?://#i', $body);
    if ($links > 1) return 'Too many links in the comment.';

    // Caps lock filter
    $letters = preg_replace('/[^A-Za-z]/', '', $body);
    $len = strlen($letters);
    if ($len >= 20) {
        $upper = preg_replace('/[^A-Z]/', '', $letters);
        if (strlen($upper) / $len > 0.85) return 'Please turn off caps lock.';
    }
    return null;
}

/**
 * Honeypot check for spam prevention.
 */
function honeypot_triggered(array $data): bool {
    $hp = trim((string)($data['website'] ?? ''));
    return $hp !== '';
}

/* ===== Session bootstrap ===== */
function start_session_once(): void {
    if (session_status() === PHP_SESSION_NONE) {
        // Detect HTTPS even when behind a reverse proxy (Render, Cloudflare, etc.).
        // Render sets X-Forwarded-Proto: https but $_SERVER['HTTPS'] is empty.
        $xfProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        $https   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || strtolower($xfProto) === 'https';
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('SPIDER_SESSID');
        session_start();
    }
}

/* ===== CSRF Protection ===== */
function csrf_token(): string {
    start_session_once();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_header(): void {
    header('X-CSRF-Token: ' . csrf_token());
}

function csrf_validate(): void {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) return;

    $action = $_GET['action'] ?? '';
    $csrfFree = in_array($action, ['signup', 'signin', 'shopLogin', 'csrf'], true);

    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($provided === '' && !empty($_POST['csrf'])) {
        $provided = (string) $_POST['csrf'];
    }
    if ($provided === '') {
        $body = json_input();
        $provided = (string)($body['csrf'] ?? '');
    }
    if ($csrfFree) return;

    $expected = $_SESSION['csrf'] ?? '';
    if ($expected === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'CSRF token missing or invalid']);
        exit;
    }
}

function raw_request_body(): string {
    static $cached = null;
    if ($cached !== null) return $cached;

    $raw = fopen('php://input', 'rb');
    if ($raw === false) {
        $cached = '';
        return $cached;
    }

    $buffer = '';
    while (!feof($raw)) {
        $chunk = fread($raw, 8192);
        if ($chunk === false || $chunk === '') break;
        $buffer .= $chunk;
    }
    fclose($raw);

    $cached = $buffer;
    return $cached;
}

/* ===== Rate limiting (PostgreSQL compatible) ===== */
function rate_limit_check(string $kind, int $maxAttempts, int $windowSeconds, int $lockoutSeconds): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $pdo = db();

    if (DB_DRIVER === 'pgsql') {
        $pdo->prepare("DELETE FROM public.login_attempts WHERE attempted_at < (NOW() - (? || ' SECOND')::INTERVAL)")
            ->execute([$windowSeconds * 3]);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM public.login_attempts WHERE ip = ? AND kind = ? AND success = FALSE AND attempted_at > (NOW() - (? || ' SECOND')::INTERVAL)");
        $stmt->execute([$ip, $kind, $windowSeconds]);
    } else {
        $pdo->prepare('DELETE FROM public.login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ? SECOND)')
            ->execute([$windowSeconds * 3]);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM public.login_attempts WHERE ip = ? AND kind = ? AND success = FALSE AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)');
        $stmt->execute([$ip, $kind, $windowSeconds]);
    }

    $fails = (int) $stmt->fetchColumn();

    if ($fails >= $maxAttempts) {
        http_response_code(429);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Too many attempts. Please try again later.']);
        exit;
    }
}

function rate_limit_record(string $kind, bool $success): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $stmt = db()->prepare('INSERT INTO public.login_attempts (ip, kind, success) VALUES (?, ?, ?)');
    $stmt->execute([$ip, $kind, $success]);
}

/* ===== Auth helpers ===== */
function current_user(): ?array {
    start_session_once();
    return $_SESSION['user'] ?? null;
}

function require_login(): array {
    $u = current_user();
    if (!$u) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Sign in required']);
        exit;
    }
    return $u;
}

function is_shopkeeper(): bool {
    start_session_once();
    return !empty($_SESSION['shopkeeper']);
}

function require_shopkeeper(): array {
    start_session_once();
    if (empty($_SESSION['shopkeeper'])) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Shopkeeper login required']);
        exit;
    }
    return $_SESSION['shopkeeper'];
}

function shop_profile(): array {
    static $cache = null;
    if ($cache === null) {
        try {
            $row = db()->query('SELECT * FROM ' . db_table('shop_profile') . ' WHERE id = 1')->fetch();
            if (!$row) {
                $cache = [
                    'id' => 1, 'shop_name' => 'Depak Tiles & Granite', 'owner_name' => 'Depak',
                    'mobile' => null, 'email' => null, 'contact_note' => null,
                    'location' => null, 'about' => null, 'logo_path' => null,
                ];
            } else {
                $cache = $row;
            }
        } catch (PDOException) {
            // Database not yet initialized — return defaults
            $cache = [
                'id' => 1, 'shop_name' => 'Depak Tiles & Granite', 'owner_name' => 'Depak',
                'mobile' => null, 'email' => null, 'contact_note' => null,
                'location' => null, 'about' => null, 'logo_path' => null,
            ];
        }
    }
    return $cache;
}

function refresh_shop_profile_cache(): array {
    $row = db()->query('SELECT * FROM ' . db_table('shop_profile') . ' WHERE id = 1')->fetch();
    return $row ?: shop_profile();
}

function json_input(): array {
    $raw = raw_request_body();
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : [];
}

/* ===== Shopkeeper credential lookup ===== */
function shopkeeper_credentials(): ?array {
    try {
        $stmt = db()->prepare('SELECT * FROM ' . db_table('shopkeeper_credentials') . ' WHERE username = ?');
        $stmt->execute([SHOP_USERNAME]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (PDOException $e) {
        // Database not yet initialized or schema incomplete
        return null;
    }
}

function shopkeeper_is_locked(array $creds): bool {
    if (empty($creds['locked_until'])) return false;
    return strtotime($creds['locked_until']) > time();
}