<?php
/**
 * Diagnostic utility script to verify database connectivity.
 * Run via CLI or Browser.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

$roots = [];
foreach ([
    __DIR__,
    getcwd(),
    $_SERVER['DOCUMENT_ROOT'] ?? '',
    dirname($_SERVER['SCRIPT_FILENAME'] ?? __DIR__),
    dirname(__DIR__),
    '/var/www/html',
    '/var/www',
    'C:/xampp/htdocs',
    'C:/xampp/htdocs/FARM',
] as $root) {
    if ($root === '' || $root === false) {
        continue;
    }

    $normalized = str_replace('\\', '/', rtrim($root, DIRECTORY_SEPARATOR));
    if ($normalized !== '') {
        $roots[] = $normalized;
    }

    $parent = $normalized;
    for ($i = 0; $i < 8; $i++) {
        $next = dirname($parent);
        if ($next === $parent) {
            break;
        }
        $roots[] = $next;
        $parent = $next;
    }
}

$roots = array_values(array_unique($roots));
$possibleDbFiles = [];
foreach ($roots as $root) {
    foreach ([$root . '/api/db.php', $root . '/db.php', $root . '/FARM/api/db.php', $root . '/FARM/db.php'] as $candidate) {
        $possibleDbFiles[] = str_replace('\\', '/', $candidate);
    }
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