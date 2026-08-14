# Supabase Database Connection Troubleshooting

## Common Issues & Solutions

### **Issue 1: Incorrect DATABASE_URL Format**

**Problem:** Supabase connection string not parsed correctly

**Supabase Connection String Format:**
```
postgresql://postgres.XXXXX:PASSWORD@aws-0-region.pooler.supabase.com:5432/postgres
```

**Key Parts:**
- Scheme: `postgresql://` (NOT `postgres://`)
- User: `postgres.XXXXX` (includes project reference)
- Password: Your database password (URL-encode special chars: `@` → `%40`, `:` → `%3A`)
- Host: `aws-0-region.pooler.supabase.com` (Supabase pooler host)
- Port: `5432` (standard PostgreSQL)
- Database: `postgres` (default Supabase database)

---

### **Issue 2: Special Characters in Password Not URL-Encoded**

**Example Problem:**
```
DATABASE_URL=postgresql://postgres.abc123:P@ssw0rd:123@aws-0-us-east.pooler.supabase.com:5432/postgres
                                          ↑        ↑ BREAKS PARSING!
```

**Solution - URL-Encode Special Characters:**
```
PASSWORD: P@ssw0rd:123
ENCODED:  P%40ssw0rd%3A123

CORRECT: postgresql://postgres.abc123:P%40ssw0rd%3A123@aws-0-us-east.pooler.supabase.com:5432/postgres
```

**URL Encoding Reference:**
| Character | Encoding |
|-----------|----------|
| `@` | `%40` |
| `:` | `%3A` |
| `#` | `%23` |
| `?` | `%3F` |
| `&` | `%26` |
| `/` | `%2F` |
| `%` | `%25` |

---

### **Issue 3: Database Name Defaults to 'public'**

**Current Code Bug:**
```php
$parsedName   = $_dburl['name'] ?? 'public';
```

**Problem:** If DATABASE_URL is `postgresql://user:pass@host/postgres`, the database name is extracted as `postgres`, but it defaults to `public` if parsing fails.

**Fix - Check Actual Database Name:**
1. Log into Supabase dashboard
2. Settings → Database
3. Note the actual database name (usually `postgres`)
4. Ensure your DATABASE_URL includes it: `...@host:5432/postgres`

---

### **Issue 4: SSL Certificate Verification**

**Current Code:**
```php
$dsn = 'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $dbname . ';sslmode=require';
```

**Potential Issue:** `sslmode=require` works, but Supabase also supports:
- `sslmode=require` ✅ (Current - enforces SSL)
- `sslmode=verify-full` ⚠️ (More strict, verifies certificate chain)

**To add certificate verification (recommended for production):**
```php
$dsn = 'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $dbname 
       . ';sslmode=require;sslcert=/path/to/ca-certificate.crt';
```

---

### **Issue 5: Connection Pooler vs Direct Connection**

Supabase offers two host options:

| Type | Host | Port | Use Case |
|------|------|------|----------|
| **Pooler** | `*.pooler.supabase.com` | `5432` | ✅ Recommended for web apps |
| **Direct** | `*.supabase.co` | `5432` | For pgAdmin/native clients |

**Your current code should use:** `*.pooler.supabase.com`

---

## Debugging Steps

### **Step 1: Test Connection String**

Create a test file `test_supabase_connection.php`:

```php
<?php
// Get your DATABASE_URL from Supabase dashboard
$dbUrl = 'postgresql://postgres.abc123:YOUR_PASSWORD@aws-0-region.pooler.supabase.com:5432/postgres';

// Parse it
$parts = parse_url($dbUrl);
print_r([
    'scheme'   => $parts['scheme'] ?? null,
    'user'     => $parts['user'] ?? null,
    'pass'     => $parts['pass'] ?? null,
    'host'     => $parts['host'] ?? null,
    'port'     => $parts['port'] ?? null,
    'path'     => $parts['path'] ?? null,
    'database' => ltrim($parts['path'] ?? '', '/'),
]);

// Try connection
try {
    $dsn = 'pgsql:host=' . $parts['host'] . ';port=' . $parts['port'] 
         . ';dbname=' . ltrim($parts['path'], '/') . ';sslmode=require';
    $pdo = new PDO($dsn, $parts['user'], $parts['pass']);
    echo "✅ CONNECTION SUCCESS!\n";
    print_r($pdo->query("SELECT version()")->fetch());
} catch (PDOException $e) {
    echo "❌ CONNECTION FAILED:\n";
    echo $e->getMessage() . "\n";
    echo "Error Code: " . $e->getCode() . "\n";
}
?>
```

Run it:
```bash
php test_supabase_connection.php
```

### **Step 2: Check Environment Variables on Render**

On Render.com dashboard:
1. Your Web Service
2. Settings → Environment
3. Verify `DATABASE_URL` is set correctly
4. Check no typos in password

### **Step 3: Verify Supabase Credentials**

1. Go to supabase.com → Your Project
2. Settings → Database → Connection Info
3. Copy the "URI" connection string
4. Ensure it follows format: `postgresql://user:pass@host:5432/postgres`

---

## Fixed Database Connection Code

Here's an improved version with better error reporting:

```php
<?php
function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $driver = DB_DRIVER;
    $host   = DB_HOST;
    $port   = DB_PORT;
    $dbname = DB_NAME ?: 'postgres';  // ✅ Default to 'postgres' for Supabase
    $user   = DB_USER;
    $pass   = DB_PASS;

    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        if ($driver === 'pgsql') {
            $dsn = 'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $dbname . ';sslmode=require';
            $pdo = new PDO($dsn, $user, $pass, $opts);
            
            // ✅ Test connection with simple query
            $pdo->query("SELECT 1");
            
        } else {
            $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $dbname . ';charset=utf8mb4';
            $pdo = new PDO($dsn, $user, $pass, $opts);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        
        // ✅ Enhanced error message for debugging
        $errorMsg = $e->getMessage();
        $details = [
            'error' => 'Database connection failed',
            'details' => $errorMsg,
            'driver' => $driver,
            'host' => $host,
            'port' => $port,
            'database' => $dbname,
        ];
        
        // Only show connection details in development
        if (getenv('APP_ENV') === 'local') {
            echo json_encode($details);
        } else {
            echo json_encode(['error' => 'Database connection failed']);
        }
        exit;
    }

    try {
        ensure_schema($pdo);
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Schema bootstrap failed: ' . $e->getMessage()]);
        exit;
    }

    return $pdo;
}
?>
```

---

## Environment Variables for Render

Set these in Render.com dashboard:

```
DATABASE_URL=postgresql://postgres.abc123:YOUR_PASSWORD@aws-0-region.pooler.supabase.com:5432/postgres
DB_DRIVER=pgsql
APP_ENV=production
OTP_INCLUDE_DEBUG=false
SETUP_TOKEN=your-secure-random-token
```

---

## Common Error Messages & Fixes

| Error | Cause | Solution |
|-------|-------|----------|
| `SQLSTATE[08006]` | Cannot connect to server | Check host/port, SSL support, firewall |
| `SQLSTATE[28P01]` | Invalid username/password | Check DB_USER and DB_PASS in DATABASE_URL |
| `SQLSTATE[3D000]` | Database does not exist | Database name wrong; use `postgres` for Supabase |
| `SQLSTATE[42P01]` | Table does not exist | Schema bootstrap hasn't run; check permissions |
| `SSL certificate problem` | SSL verification failed | Use `sslmode=require` (not `verify-full`) |

---

## Supabase-Specific Checklist

- [ ] Using `pooler.supabase.com` host (NOT `supabase.co`)
- [ ] Database name is `postgres` (default Supabase database)
- [ ] Password URL-encoded if contains special characters
- [ ] `sslmode=require` is set
- [ ] User is `postgres` or your custom role
- [ ] Environment variable `DATABASE_URL` is set on Render
- [ ] Supabase project has "Database" section enabled
- [ ] Firewall rules allow connections (usually open by default)
