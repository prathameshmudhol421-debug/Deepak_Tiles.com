<?php
/**
 * Shopkeeper password setup CLI.
 *
 * Run from the project root:
 *     php api/setup_shopkeeper.php set <username> <new-password>
 *     php api/setup_shopkeeper.php set-email <username> <email>
 *
 * Examples:
 *     php api/setup_shopkeeper.php set SPIDER My$ecureP@ss42
 *     php api/setup_shopkeeper.php set-email SPIDER shop@example.com
 *     php api/setup_shopkeeper.php rotate
 *     php api/setup_shopkeeper.php status
 *
 * This is the *only* place that inserts the bcrypt hash. The plaintext
 * password is never written to disk or to source code.
 *
 * SECURITY: this script is intended to be run from the command line only.
 * It refuses to execute when invoked via HTTP.
 */

declare(strict_types=1);

// Strictly restrict execution to Command Line Interface (CLI)
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script must be run from the command line.";
    exit(1);
}

require_once __DIR__ . '/db.php';

$argv = $_SERVER['argv'] ?? [];
array_shift($argv); // strip script name
$cmd = $argv[0] ?? '';

switch ($cmd) {
    case 'set':
        $user = $argv[1] ?? '';
        $pass = $argv[2] ?? '';
        if ($user === '' || $pass === '') {
            fwrite(STDERR, "Usage: php api/setup_shopkeeper.php set <username> <password>\n");
            exit(2);
        }
        set_credentials($user, $pass);
        break;

    case 'set-email':
        $user  = $argv[1] ?? '';
        $email = $argv[2] ?? '';
        if ($user === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            fwrite(STDERR, "Usage: php api/setup_shopkeeper.php set-email <username> <email>\n");
            exit(2);
        }
        set_recovery_email($user, $email);
        break;

    case 'rotate':
        // Rotate the default shopkeeper password with a freshly generated random one.
        $defaultUser = defined('SHOP_USERNAME') ? SHOP_USERNAME : 'admin';
        $pass = bin2hex(random_bytes(12)); // 24 hex chars
        set_credentials($defaultUser, $pass, true);
        break;

    case 'status':
        status();
        break;

    default:
        echo "Usage:\n";
        echo "  php api/setup_shopkeeper.php set <username> <password>\n";
        echo "  php api/setup_shopkeeper.php set-email <username> <email>\n";
        echo "  php api/setup_shopkeeper.php rotate\n";
        echo "  php api/setup_shopkeeper.php status\n";
        exit(1);
}

function set_credentials(string $username, string $plain, bool $verbose = false): void {
    if (strlen($plain) < 8) {
        fwrite(STDERR, "Password must be at least 8 characters long.\n");
        exit(2);
    }

    $hash = password_hash($plain, PASSWORD_DEFAULT);
    $pdo  = db();

    // PostgreSQL / Supabase compatible UPSERT query
    $stmt = $pdo->prepare(
        'INSERT INTO public.shopkeeper_credentials (username, password_hash, failed_attempts, locked_until)
         VALUES (?, ?, 0, NULL)
         ON CONFLICT (username) DO UPDATE 
         SET password_hash = EXCLUDED.password_hash, 
             failed_attempts = 0, 
             locked_until = NULL'
    );
    $stmt->execute([$username, $hash]);

    echo "✓ Shopkeeper credentials saved for username: {$username}\n";
    if ($verbose) {
        echo "  New password (print this once, store it safely): {$plain}\n";
    } else {
        echo "  Password (not echoed) length: " . strlen($plain) . "\n";
    }
    echo "\nTo log in, use the username and password you provided.\n";
    echo "The plaintext password is never stored in the database.\n";
}

function status(): void {
    $pdo = db();
    $rows = $pdo->query('SELECT username, email, last_changed, failed_attempts, locked_until FROM public.shopkeeper_credentials')->fetchAll();
    if (!$rows) {
        echo "No shopkeeper credentials configured yet.\n";
        echo "Run: php api/setup_shopkeeper.php set Deepak yourpassword\n";
        return;
    }
    foreach ($rows as $r) {
        echo "Username:        {$r['username']}\n";
        echo "Recovery email:  " . ($r['email'] ?? '— (not set)') . "\n";
        echo "Last changed:    " . ($r['last_changed'] ?? '—') . "\n";
        echo "Failed attempts: {$r['failed_attempts']}\n";
        echo "Locked until:    " . ($r['locked_until'] ?? '—') . "\n";
        echo "----\n";
    }
}

function set_recovery_email(string $username, string $email): void {
    $pdo = db();
    $stmt = $pdo->prepare(
        'UPDATE public.shopkeeper_credentials SET email = ? WHERE username = ?'
    );
    $stmt->execute([$email, $username]);

    if ($stmt->rowCount() === 0) {
        fwrite(STDERR, "No shopkeeper with username '{$username}'. Set credentials first:\n");
        fwrite(STDERR, "  php api/setup_shopkeeper.php set {$username} <password>\n");
        exit(3);
    }

    echo "✓ Recovery email set for '{$username}': {$email}\n";
    echo "  You can now use the forgot-password flow to reset the password from the site.\n";
}