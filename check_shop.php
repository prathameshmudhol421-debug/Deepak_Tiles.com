<?php
/**
 * Quick diagnostic: prints the current shop profile row + login instructions.
 * Useful for confirming the rebranding took effect.
 */
declare(strict_types=1);

$roots = array_filter([
    __DIR__,
    dirname(__DIR__),
    getcwd(),
    $_SERVER['DOCUMENT_ROOT'] ?? '',
    dirname($_SERVER['SCRIPT_FILENAME'] ?? __DIR__),
    '/var/www/html',
    '/var/www/html/FARM',
    'C:/xampp/htdocs/FARM',
], static fn($v) => $v !== '' && $v !== false);

$roots = array_values(array_unique(array_map(static fn($v) => rtrim($v, DIRECTORY_SEPARATOR), $roots)));

$possibleDbFiles = [];
foreach ($roots as $root) {
    $possibleDbFiles[] = $root . '/api/db.php';
    $possibleDbFiles[] = $root . '/db.php';
    $possibleDbFiles[] = $root . '/FARM/api/db.php';
    $possibleDbFiles[] = $root . '/FARM/db.php';
}

$dbFile = null;
foreach ($possibleDbFiles as $candidate) {
    if (is_file($candidate)) {
        $dbFile = $candidate;
        break;
    }
}

if ($dbFile === null) {
    fwrite(STDERR, "Database bootstrap file not found. Expected api/db.php in the project root.\n");
    fwrite(STDERR, "Checked roots:\n");
    foreach ($roots as $root) {
        fwrite(STDERR, "  - $root\n");
    }
    exit(1);
}

require_once $dbFile;

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

try {
    $pdo = db();
    
    // PostgreSQL / Supabase schema-qualified query
    $stmt = $pdo->query('SELECT shop_name, owner_name, mobile, email, location FROM public.shop_profile LIMIT 1');
    $row  = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;

    echo "=== Shop Profile Diagnostic ===\n\n";
    echo "Shop name : " . ($row['shop_name']  ?? 'Not set') . "\n";
    echo "Owner     : " . ($row['owner_name'] ?? 'Not set') . "\n";
    echo "Mobile    : " . ($row['mobile']     ?? 'Not set') . "\n";
    echo "Email     : " . ($row['email']      ?? 'Not set') . "\n";
    echo "Location  : " . ($row['location']   ?? 'Not set') . "\n";

    echo "\n=== Shopkeeper Credentials Info ===\n";
    
    // Check actual shopkeeper username set in DB
    $uStmt = $pdo->query('SELECT username FROM public.shopkeeper_credentials LIMIT 1');
    $userRow = $uStmt ? $uStmt->fetch(PDO::FETCH_ASSOC) : null;
    $username = $userRow['username'] ?? 'Deepak';

    echo "Configured Username: {$username}\n";
    echo "To set/update password, run from project root:\n";
    echo "  php api/setup_shopkeeper.php set {$username} <your-password>\n";

} catch (Throwable $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
}