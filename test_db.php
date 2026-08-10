<?php
include 'api/db.php';
try {
    $pdo = db();
    echo "✓ Database connected!\n";
    $result = $pdo->query('SELECT COUNT(*) as count FROM shopkeeper_credentials');
    if ($result) {
        $row = $result->fetch();
        echo "✓ Shopkeeper accounts stored: " . $row['count'] . "\n";
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
