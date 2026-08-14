<?php
/**
 * Diagnostic utility script to verify database connectivity.
 * Run via CLI or Browser.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

// Uses absolute directory path (__DIR__) to ensure api/db.php is always found
$dbFile = __DIR__ . '/api/db.php';

if (!file_exists($dbFile)) {
    echo "✗ File not found: " . $dbFile . "\n";
    echo "Please check if api/db.php exists in your project repository.\n";
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