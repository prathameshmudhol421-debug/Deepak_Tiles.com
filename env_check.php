<?php
header('Content-Type: text/plain; charset=utf-8');
echo "DATABASE_URL set: " . (getenv('DATABASE_URL') ? 'YES' : 'NO') . "\n";
echo "DATABASE_URL value: " . (getenv('DATABASE_URL') ?: '(empty)') . "\n\n";
echo "DB_DRIVER: " . (getenv('DB_DRIVER') ?: '(empty)') . "\n";
echo "DB_HOST:   " . (getenv('DB_HOST') ?: '(empty)') . "\n";
echo "DB_PORT:   " . (getenv('DB_PORT') ?: '(empty)') . "\n";
echo "DB_NAME:   " . (getenv('DB_NAME') ?: '(empty)') . "\n";
echo "DB_USER:   " . (getenv('DB_USER') ?: '(empty)') . "\n\n";
echo "All env vars with DB in name:\n";
foreach ($_ENV as $k => $v) {
    if (stripos($k, 'DB') !== false || stripos($k, 'DATABASE') !== false) {
        echo "  $k = $v\n";
    }
}
echo "\nAll env vars:\n";
foreach ($_ENV as $k => $v) {
    echo "  $k\n";
}
