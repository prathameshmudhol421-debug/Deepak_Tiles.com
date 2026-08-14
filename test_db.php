<?php
/**
 * Diagnostic utility script to verify database connectivity.
 * Run via CLI: php test_db.php
 * Or via browser: https://your-app.onrender.com/test_db.php
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/api/db.php';

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