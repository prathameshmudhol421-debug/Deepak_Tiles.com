<?php
/**
 * Supabase Connection Diagnostic Tool
 * 
 * Run this to test your Supabase connection:
 *   php test_db_connection.php
 * 
 * Or set DATABASE_URL first:
 *   export DATABASE_URL="postgresql://user:pass@host:5432/postgres"
 *   php test_db_connection.php
 */

echo "=== Supabase Connection Diagnostic ===\n\n";

// Check if DATABASE_URL is set
$dbUrl = getenv('DATABASE_URL');
if (!$dbUrl) {
    echo "❌ DATABASE_URL not set\n\n";
    echo "Set it with:\n";
    echo "  export DATABASE_URL=\"postgresql://postgres:PASSWORD@aws-0-region.pooler.supabase.com:5432/postgres\"\n";
    echo "  php test_db_connection.php\n\n";
    exit(1);
}

echo "✅ DATABASE_URL is set\n";
echo "   URL: " . preg_replace('/:[^:]*@/', ':****@', $dbUrl) . "\n\n";

// Parse the connection string
$parts = parse_url($dbUrl);
echo "📋 Parsed Connection Details:\n";
echo "   Scheme:   " . ($parts['scheme'] ?? 'MISSING') . "\n";
echo "   User:     " . ($parts['user'] ?? 'MISSING') . "\n";
echo "   Host:     " . ($parts['host'] ?? 'MISSING') . "\n";
echo "   Port:     " . ($parts['port'] ?? 'DEFAULT') . "\n";
echo "   Database: " . ltrim($parts['path'] ?? '', '/') . "\n\n";

// Validate format
$valid = true;
if (($parts['scheme'] ?? '') !== 'postgresql' && ($parts['scheme'] ?? '') !== 'postgres') {
    echo "⚠️  WARNING: Scheme should be 'postgresql' or 'postgres', got: " . ($parts['scheme'] ?? 'MISSING') . "\n";
}
if (empty($parts['host'])) {
    echo "❌ ERROR: No host specified\n";
    $valid = false;
}
if (empty($parts['user'])) {
    echo "❌ ERROR: No username specified\n";
    $valid = false;
}
if (empty($parts['pass'])) {
    echo "⚠️  WARNING: No password (might be intentional)\n";
}
if (empty($parts['path']) || trim($parts['path'], '/') === '') {
    echo "⚠️  WARNING: No database name specified, will use 'postgres'\n";
}

if (!$valid) {
    echo "\n❌ Invalid connection string format\n";
    exit(1);
}

echo "\n🔌 Attempting connection...\n\n";

// Build PDO connection
$scheme = $parts['scheme'] === 'postgres' ? 'pgsql' : 'pgsql';
$host   = $parts['host'];
$port   = $parts['port'] ?? '5432';
$dbname = ltrim($parts['path'] ?? '', '/') ?: 'postgres';
$user   = $parts['user'];
$pass   = $parts['pass'] ?? '';

$dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "✅ CONNECTION SUCCESSFUL!\n\n";
    
    // Test with a query
    echo "🧪 Running test query...\n";
    $result = $pdo->query("SELECT version()")->fetch();
    echo "   PostgreSQL Version: " . $result['version'] . "\n\n";
    
    // Check if tables exist
    echo "📊 Checking for application tables...\n";
    $stmt = $pdo->query("
        SELECT table_name FROM information_schema.tables 
        WHERE table_schema = 'public' 
        ORDER BY table_name
    ");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($tables)) {
        echo "   ⚠️  No tables found (schema not yet initialized)\n";
        echo "   Tables will be created automatically on first API call.\n";
    } else {
        echo "   Found " . count($tables) . " tables:\n";
        foreach ($tables as $table) {
            echo "      • " . $table . "\n";
        }
    }
    
    echo "\n✅ All checks passed! Your Supabase connection is working.\n";
    
} catch (PDOException $e) {
    echo "❌ CONNECTION FAILED\n\n";
    echo "Error Message: " . $e->getMessage() . "\n";
    echo "Error Code:    " . $e->getCode() . "\n\n";
    
    // Suggest common fixes
    echo "🔍 Troubleshooting Suggestions:\n\n";
    
    $msg = $e->getMessage();
    
    if (strpos($msg, 'SQLSTATE[08006]') !== false || strpos($msg, 'could not connect') !== false) {
        echo "   Problem: Cannot connect to server\n";
        echo "   Fixes:\n";
        echo "   1. Check host is correct: $host\n";
        echo "   2. Check port is correct: $port\n";
        echo "   3. Verify Supabase project is running (check dashboard)\n";
        echo "   4. Try: telnet $host $port\n";
    }
    
    if (strpos($msg, 'SQLSTATE[28P01]') !== false || strpos($msg, 'password') !== false) {
        echo "   Problem: Authentication failed\n";
        echo "   Fixes:\n";
        echo "   1. Check username is correct: $user\n";
        echo "   2. Check password is correct\n";
        echo "   3. URL-encode special chars: @ → %40, : → %3A\n";
    }
    
    if (strpos($msg, 'SQLSTATE[3D000]') !== false || strpos($msg, 'database') !== false) {
        echo "   Problem: Database doesn't exist\n";
        echo "   Fixes:\n";
        echo "   1. Database name is: $dbname\n";
        echo "   2. For Supabase, use 'postgres' as database name\n";
        echo "   3. Check Supabase dashboard: Settings → Database\n";
    }
    
    if (strpos($msg, 'SSL') !== false) {
        echo "   Problem: SSL/TLS certificate issue\n";
        echo "   Fixes:\n";
        echo "   1. Current sslmode: require\n";
        echo "   2. Supabase requires SSL; sslmode=require is correct\n";
        echo "   3. Try adding: ;sslcert=/path/to/ca-certificate.crt\n";
    }
    
    echo "\n";
    exit(1);
}
?>
