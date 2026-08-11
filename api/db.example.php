<?php
/**
 * Example DB config for the FARM project.
 * Copy this file to api/db.php and fill in real credentials on your machine.
 * Do NOT commit api/db.php to version control — it's listed in .gitignore.
 *
 * Production / Render: prefer setting DB_HOST / DB_PORT / DB_NAME / DB_USER /
 * DB_PASS as environment variables (Render dashboard → Environment). The
 * constants below are local-development fallbacks used when no env var is
 * present. See DEPLOY.md for the Render walkthrough.
 */

declare(strict_types=1);

define('DB_HOST',    getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT',    getenv('DB_PORT') ?: '3307');
define('DB_NAME',    getenv('DB_NAME') ?: 'farm_db');
define('DB_USER',    getenv('DB_USER') ?: 'root');
define('DB_PASS',    getenv('DB_PASS') ?: ''); // set your local DB password if any
define('DB_CHARSET', 'utf8mb4');

// Shopkeeper example identifier (not a secret)
define('SHOP_USERNAME', 'Deepak');

// After copying: php -r "copy('api/db.example.php','api/db.php');" and edit api/db.php
// Or for production: set the DB_* env vars and skip the copy entirely.
