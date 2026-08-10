<?php
/**
 * Quick diagnostic: prints the current shop profile row + login instructions.
 * Useful for confirming the rebranding took effect.
 */
include 'api/db.php';
try {
    $pdo = db();
    $row = $pdo->query('SELECT shop_name, owner_name, mobile, email, location FROM shop_profile WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    echo "Shop name : " . ($row['shop_name']  ?? 'Not set') . "\n";
    echo "Owner     : " . ($row['owner_name'] ?? 'Not set') . "\n";
    echo "Mobile    : " . ($row['mobile']     ?? 'Not set') . "\n";
    echo "Email     : " . ($row['email']      ?? 'Not set') . "\n";
    echo "Location  : " . ($row['location']   ?? 'Not set') . "\n";
    echo "\nLogin at http://localhost/FARM/spider.html\n";
    echo "  Username: Deepak\n";
    echo "  Password: Deepak@123\n";
    echo "  (rotate with: php api\\setup_shopkeeper.php set Deepak <new-password>)\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}