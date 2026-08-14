<?php
/**
 * Diagnostic utility script to verify database connectivity.
 * Run via CLI or Browser.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

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
    echo "✗ Database bootstrap file not found. Expected api/db.php in the project root.\n";
    echo "Checked roots:\n";
    foreach ($roots as $root) {
        echo "  - $root\n";
    }
    exit(1);
}

require_once $dbFile;

try {
    $pdo = db();
    echo "✓ Database connected successfully!\n";

    $result = $pdo->query('SELECT COUNT(*) as count FROM shopkeeper_credentials');
    if ($result) {
        $row = $result->fetch(PDO::FETCH_ASSOC);
        echo "✓ Shopkeeper accounts stored: " . ($row['count'] ?? 0) . "\n";
    }
} catch (Throwable $e) {
    echo "✗ Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}