<?php
/**
 * Example DB config for the FARM project.
 * Copy this file to api/db.php and fill in real credentials on your machine.
 * Do NOT commit api/db.php to version control — it's listed in .gitignore.
 */

declare(strict_types=1);

// Default local development values — adjust as needed.
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3307');
define('DB_NAME', 'farm_db');
define('DB_USER', 'root');
define('DB_PASS', ''); // set your local DB password if any
define('DB_CHARSET', 'utf8mb4');

// Shopkeeper example identifier (not a secret)
define('SHOP_USERNAME', 'Deepak');

// After copying: php -r "copy('api/db.example.php','api/db.php');" and edit api/db.php
