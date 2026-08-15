<?php
/**
 * Forgot-password flow with email OTP.
 *
 *   POST /api/forgot_password.php?action=requestOtp    { email, kind: 'user'|'shopkeeper' }
 *   POST /api/forgot_password.php?action=verifyOtp     { email, kind, otp }
 *   POST /api/forgot_password.php?action=resetPassword { email, kind, reset_token, password, confirm }
 *
 * kind:
 *   'user'        — locate by users.email
 *   'shopkeeper'  — locate by shopkeeper_credentials.email
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email_config.php';

/* ===== Global JSON Error Helper ===== */
if (!function_exists('json_err')) {
    function json_err(string $msg, int $code = 400): void {
        http_response_code($code);
        echo json_encode(['error' => $msg]);
        exit;
    }
}

start_session_once();
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');

// CSRF check for state-changing requests
csrf_validate();

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'requestOtp':    action_request_otp();    break;
        case 'verifyOtp':     action_verify_otp();     break;
        case 'resetPassword': action_reset_password(); break;
        default:
            json_err('Unknown action', 400);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}

/* =====================================================================
   Handlers
   ===================================================================== */

function action_request_otp(): void {
    rate_limit_check('forgot_otp', 5, 900);

    $data  = json_input();
    $email = strtolower(trim($data['email'] ?? ''));
    $kind  = $data['kind'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('Invalid email');
    if (!in_array($kind, ['user', 'shopkeeper'], true)) json_err('Invalid account type');

    $account = find_account_for_reset($email, $kind);

    // Prevent account enumeration — return identical output if account doesn't exist
    if (!$account) {
        echo json_encode(['ok' => true, 'sent' => false]);
        return;
    }

    // Invalidate existing OTPs for this user/shopkeeper
    db()->prepare('DELETE FROM ' . db_table('password_otps') . ' WHERE target_kind = ? AND target_id = ?')
        ->execute([$kind, (int) $account['id']]);

    // Generate a secure 6-digit OTP
    $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $hash = password_hash($otp, PASSWORD_DEFAULT);
    $expires = date('Y-m-d H:i:s', time() + OTP_TTL_SECONDS);

    db()->prepare(
        'INSERT INTO ' . db_table('password_otps') . ' (target_kind, target_id, email, otp_hash, expires_at)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$kind, (int) $account['id'], $email, $hash, $expires]);

    // Send email or fallback to local log
    $mailed = send_otp_email($email, $otp);

    // Refresh CSRF token for subsequent verification steps
    $_SESSION['csrf'] = bin2hex(random_bytes(32));

    $resp = [
        'ok'   => true,
        'sent' => true,
        'csrf' => $_SESSION['csrf'],
    ];
    
    if (defined('OTP_INCLUDE_DEBUG') && OTP_INCLUDE_DEBUG) {
        $resp['debug_otp'] = $otp;
        $resp['debug_log'] = 'api/otp_log.txt';
    }
    
    echo json_encode($resp);
}

function action_verify_otp(): void {
    $data  = json_input();
    $email = strtolower(trim($data['email'] ?? ''));
    $kind  = $data['kind'] ?? '';
    $otp   = preg_replace('/\D+/', '', (string) ($data['otp'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('Invalid email');
    if (!in_array($kind, ['user', 'shopkeeper'], true)) json_err('Invalid account type');
    if (strlen($otp) !== 6) json_err('Enter the 6-digit code');

    $stmt = db()->prepare(
        "SELECT id, target_kind, target_id, otp_hash, attempts, verified, expires_at
           FROM " . db_table('password_otps') . "
          WHERE email = ? AND target_kind = ?
          ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$email, $kind]);
    $rec = $stmt->fetch();

    if (!$rec) json_err('No reset code requested. Start again.', 400);
    if ((bool) $rec['verified']) json_err('Code already used. Request a new one.', 400);
    
    if (strtotime($rec['expires_at']) < time()) {
        db()->prepare('DELETE FROM public.password_otps WHERE id = ?')->execute([(int) $rec['id']]);
        json_err('Code expired. Request a new one.', 400);
    }

    // Increment attempt counter
    $newAttempts = (int) $rec['attempts'] + 1;
    db()->prepare('UPDATE ' . db_table('password_otps') . ' SET attempts = ? WHERE id = ?')
        ->execute([$newAttempts, (int) $rec['id']]);

    if ($newAttempts > OTP_MAX_ATTEMPTS) {
        db()->prepare('DELETE FROM ' . db_table('password_otps') . ' WHERE id = ?')->execute([(int) $rec['id']]);
        json_err('Too many wrong attempts. Request a new code.', 429);
    }

    if (!password_verify($otp, $rec['otp_hash'])) {
        json_err('Wrong code. ' . (OTP_MAX_ATTEMPTS - $newAttempts) . ' attempts left.', 400);
    }

    // Mark code verified and issue single-use reset token
    $token = bin2hex(random_bytes(32));
    db()->prepare('UPDATE ' . db_table('password_otps') . ' SET verified = TRUE, reset_token = ? WHERE id = ?')
        ->execute([$token, (int) $rec['id']]);

    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    echo json_encode([
        'ok'          => true,
        'reset_token' => $token,
        'csrf'        => $_SESSION['csrf'],
    ]);
}

function action_reset_password(): void {
    $data    = json_input();
    $email   = strtolower(trim($data['email'] ?? ''));
    $kind    = $data['kind'] ?? '';
    $token   = (string) ($data['reset_token'] ?? '');
    $pass    = (string) ($data['password'] ?? '');
    $confirm = (string) ($data['confirm']  ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL))   json_err('Invalid email');
    if (!in_array($kind, ['user', 'shopkeeper'], true)) json_err('Invalid account type');
    if ($token === '')                               json_err('Missing reset token');
    if (strlen($pass) < 6)                            json_err('Password must be at least 6 characters');
    if ($pass !== $confirm)                          json_err('Passwords do not match');

    $stmt = db()->prepare(
        "SELECT id, target_id, expires_at FROM " . db_table('password_otps') . "
          WHERE email = ? AND target_kind = ? AND (verified = TRUE OR verified = '1') AND reset_token = ?
          ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$email, $kind, $token]);
    $rec = $stmt->fetch();

    if (!$rec) json_err('Reset session expired. Start again.', 400);
    
    if (strtotime($rec['expires_at']) < time()) {
        db()->prepare('DELETE FROM public.password_otps WHERE id = ?')->execute([(int) $rec['id']]);
        json_err('Reset session expired. Start again.', 400);
    }

    $hash = password_hash($pass, PASSWORD_DEFAULT);

    if ($kind === 'user') {
        db()->prepare('UPDATE ' . db_table('users') . ' SET password_hash = ? WHERE id = ?')
            ->execute([$hash, (int) $rec['target_id']]);
    } else {
        db()->prepare('UPDATE ' . db_table('shopkeeper_credentials') . '
                          SET password_hash = ?, failed_attempts = 0, locked_until = NULL
                        WHERE id = ?')
            ->execute([$hash, (int) $rec['target_id']]);
    }

    // Burn OTP row after successful password reset
    db()->prepare('DELETE FROM ' . db_table('password_otps') . ' WHERE id = ?')->execute([(int) $rec['id']]);

    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    echo json_encode(['ok' => true, 'csrf' => $_SESSION['csrf']]);
}

/* =====================================================================
   Helpers
   ===================================================================== */

function find_account_for_reset(string $email, string $kind): ?array {
    if ($kind === 'user') {
        $stmt = db()->prepare('SELECT id, email FROM ' . db_table('users') . ' WHERE email = ?');
    } else {
        $stmt = db()->prepare('SELECT id, email FROM ' . db_table('shopkeeper_credentials') . ' WHERE email = ?');
    }
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function rate_limit_check(string $key, int $maxRequests, int $windowSeconds): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $sessionKey = "rate_{$key}_{$ip}";
    
    $now = time();
    $history = $_SESSION[$sessionKey] ?? [];
    
    // Filter out requests outside time window
    $history = array_filter($history, fn($ts) => ($now - $ts) < $windowSeconds);
    
    if (count($history) >= $maxRequests) {
        json_err('Too many requests. Please try again later.', 429);
    }
    
    $history[] = $now;
    $_SESSION[$sessionKey] = $history;
}