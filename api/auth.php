<?php
/**
 * Auth endpoints.
 *
 * Visitors no longer need to sign up to interact with the site. They post
 * comments, likes and ratings anonymously (with a display name). Only the
 * shopkeeper needs an account.
 *
 *   GET   /api/auth.php?action=csrf            -> { csrf } (sets cookie/session)
 *   POST  /api/auth.php?action=shopLogin       -> shopkeeper login (hashed pw)
 *   POST  /api/auth.php?action=shopLogout
 *   GET   /api/auth.php?action=shopMe          -> current shopkeeper
 *   GET   /api/auth.php?action=shopProfile     -> public shop profile
 *   PUT   /api/auth.php?action=shopProfile     -> update shop profile (shopkeeper)
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

start_session_once();
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');

// CSRF check for state-changing requests. The shopkeeper login is allowed
// without a pre-existing token — the server issues one on success.
csrf_validate();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($action) {

        case 'csrf': {
            echo json_encode(['ok' => true, 'csrf' => csrf_token()]);
            break;
        }

        /* ---------- SHOPKEEPER LOGIN ----------
         *
         * The shopkeeper is the only privileged account on the site, and they
         * often sign in from the same IP as visitors (a shop PC). We deliberately
         * do NOT apply any rate limiting or account lockout here: a wrong
         * password returns 401, and the shopkeeper can try again immediately.
         * The bcrypt password hash is what protects the account. */
        case 'shopLogin': {
            $data = json_input();
            $u    = trim($data['username'] ?? '');
            $p    = $data['password'] ?? '';

            if ($u === '' || $p === '') json_err('Username and password are required');

            $creds = shopkeeper_credentials();
            if (!$creds) {
                json_err('Shopkeeper has not been set up yet. Run: php api/setup_shopkeeper.php set <username> <password>', 500);
            }

            $usernameOk = strcasecmp($u, $creds['username']) === 0;
            $passwordOk = password_verify($p, $creds['password_hash']);

            if (!$usernameOk || !$passwordOk) {
                // Wrong credentials. We do NOT lock the account or rate-limit the
                // IP — the shopkeeper can try again straight away. bcrypt slows
                // brute-force at the password layer; that's the only line of
                // defence we need here.
                json_err('Invalid shopkeeper username or password', 401);
            }

            // Successful login. Clear any leftover failure state so the row
            // stays tidy (the fields exist for backward compatibility with
            // older installs that used them, but they're no longer enforced).
            db()->prepare('UPDATE shopkeeper_credentials SET failed_attempts = 0, locked_until = NULL WHERE id = ?')
                ->execute([$creds['id']]);
            session_regenerate_id(true);
            $_SESSION['shopkeeper'] = [
                'username' => $creds['username'],
                'role'     => 'shopkeeper',
            ];
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            echo json_encode([
                'ok'         => true,
                'shopkeeper' => $_SESSION['shopkeeper'],
                'csrf'       => $_SESSION['csrf'],
                'profile'    => shop_profile(),
            ]);
            break;
        }

        /* ---------- SHOPKEEPER LOGOUT ---------- */
        case 'shopLogout': {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            }
            session_destroy();
            echo json_encode(['ok' => true]);
            break;
        }

        /* ---------- CURRENT SHOPKEEPER ---------- */
        case 'shopMe': {
            $sk = $_SESSION['shopkeeper'] ?? null;
            echo json_encode(['ok' => true, 'shopkeeper' => $sk, 'profile' => shop_profile(), 'csrf' => csrf_token()]);
            break;
        }

        /* ---------- PUBLIC SHOP PROFILE ---------- */
        case 'shopProfile': {
            if ($method === 'PUT') return update_shop_profile();
            echo json_encode(['ok' => true, 'profile' => shop_profile()]);
            break;
        }

        default:
            json_err('Unknown action', 400);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}

function update_shop_profile(): void {
    require_shopkeeper();
    csrf_validate();  // explicit guard — shopkeeper-only endpoint
    $data = json_input();

    $allowed = ['shop_name', 'owner_name', 'mobile', 'email', 'contact_note', 'location', 'about', 'logo_path'];
    $sets = [];
    $vals = [];
    foreach ($allowed as $k) {
        if (array_key_exists($k, $data)) {
            $sets[] = "$k = ?";
            $vals[] = $data[$k] !== null ? trim((string) $data[$k]) : null;
        }
    }
    if (!$sets) {
        echo json_encode(['ok' => true, 'profile' => shop_profile()]);
        return;
    }
    $vals[] = 1; // WHERE id = 1
    $sql = 'UPDATE shop_profile SET ' . implode(', ', $sets) . ' WHERE id = ?';
    $stmt = db()->prepare($sql);
    $stmt->execute($vals);

    echo json_encode(['ok' => true, 'profile' => refresh_shop_profile_cache()]);
}

function json_err(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}
