<?php
/**
 * Diagnostic utility script to verify database connectivity.
 * Run via CLI or Browser.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

$possibleDbFiles = [
    __DIR__ . '/api/db.php',
    __DIR__ . '/FARM/api/db.php',
    dirname(__DIR__) . '/api/db.php',
    dirname(__DIR__) . '/FARM/api/db.php',
    '/var/www/html/api/db.php',
    '/var/www/html/FARM/api/db.php',
];

$dbFile = null;
foreach ($possibleDbFiles as $candidate) {
    if (is_file($candidate)) {
        $dbFile = $candidate;
        break;
    }
}

if ($dbFile === null) {
    echo "✗ Database bootstrap file not found. Expected api/db.php in the project root.\n";
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