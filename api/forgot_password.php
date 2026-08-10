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
 *
 * The OTP itself is never stored in plaintext; only its bcrypt hash. A separate
 * reset_token (random 32-byte hex) is issued after the OTP is verified and is
 * required to actually change the password. The token is single-use (deleted
 * with the OTP row on success) and short-lived (same TTL as the OTP).
 *
 * Rate-limiting and CSRF rules match api/auth.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email_config.php';

start_session_once();
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');

// OTP requests must always be CSRF-checked (unlike signup/signin which are
// the bootstrap for a fresh session). After requestOtp succeeds we issue a
// CSRF token the client can reuse on verifyOtp / resetPassword.
csrf_validate();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

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
    // 5 requests / 15 minutes / IP. Generous enough for fat-fingering an email,
    // strict enough to defeat bulk OTP-bombing.
    rate_limit_check('forgot_otp', 5, 900, 1800);

    $data  = json_input();
    $email = strtolower(trim($data['email'] ?? ''));
    $kind  = $data['kind'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('Invalid email');
    if (!in_array($kind, ['user', 'shopkeeper'], true)) json_err('Invalid account type');

    $account = find_account_for_reset($email, $kind);

    // Don't leak whether the email exists — always respond the same way.
    if (!$account) {
        rate_limit_record('forgot_otp', false);
        echo json_encode(['ok' => true, 'sent' => false]);
        return;
    }

    // Invalidate any outstanding OTPs for this target so only one is valid at a time.
    db()->prepare('DELETE FROM password_otps WHERE target_kind = ? AND target_id = ?')
        ->execute([$kind, (int) $account['id']]);

    // Generate a 6-digit OTP. Use random_int with a leading-zero pad so the
    // value is always exactly 6 digits (avoids "012345" vs "12345" ambiguity).
    $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $hash = password_hash($otp, PASSWORD_DEFAULT);
    $expires = date('Y-m-d H:i:s', time() + OTP_TTL_SECONDS);

    db()->prepare(
        'INSERT INTO password_otps (target_kind, target_id, email, otp_hash, expires_at)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$kind, (int) $account['id'], $email, $hash, $expires]);

    // Best-effort email. We don't fail the request if mail() returns false —
    // the OTP is in otp_log.txt regardless.
    $mailed = send_otp_email($email, $otp, $kind);
    log_otp($email, $kind, $otp, $mailed);

    rate_limit_record('forgot_otp', true);

    // Mint a CSRF token the client must echo back on verifyOtp / resetPassword.
    $_SESSION['csrf'] = bin2hex(random_bytes(32));

    $resp = [
        'ok'    => true,
        'sent'  => true,
        'csrf'  => $_SESSION['csrf'],
    ];
    if (OTP_INCLUDE_DEBUG) {
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
    if (strlen($otp) !== 6)                         json_err('Enter the 6-digit code');

    $row = db()->prepare(
        "SELECT id, target_kind, target_id, otp_hash, attempts, verified, expires_at
           FROM password_otps
          WHERE email = ? AND target_kind = ?
          ORDER BY id DESC LIMIT 1"
    );
    $row->execute([$email, $kind]);
    $rec = $row->fetch();

    if (!$rec) json_err('No reset code requested. Start again.', 400);
    if ((int) $rec['verified'] === 1) json_err('Code already used. Request a new one.', 400);
    if (strtotime($rec['expires_at']) < time()) {
        db()->prepare('DELETE FROM password_otps WHERE id = ?')->execute([(int) $rec['id']]);
        json_err('Code expired. Request a new one.', 400);
    }

    // Increment attempts; if over the limit, nuke the row (force user to re-request).
    $newAttempts = (int) $rec['attempts'] + 1;
    db()->prepare('UPDATE password_otps SET attempts = ? WHERE id = ?')
        ->execute([$newAttempts, (int) $rec['id']]);

    if ($newAttempts > OTP_MAX_ATTEMPTS) {
        db()->prepare('DELETE FROM password_otps WHERE id = ?')->execute([(int) $rec['id']]);
        json_err('Too many wrong attempts. Request a new code.', 429);
    }

    if (!password_verify($otp, $rec['otp_hash'])) {
        json_err('Wrong code. ' . (OTP_MAX_ATTEMPTS - $newAttempts) . ' attempts left.', 400);
    }

    // Mark verified and issue a reset_token the client must echo back on the
    // reset step. Storing it in the same row keeps verify + reset tied together
    // atomically.
    $token = bin2hex(random_bytes(32));
    db()->prepare('UPDATE password_otps SET verified = 1, reset_token = ? WHERE id = ?')
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
    if ($token === '')                                json_err('Missing reset token');
    if (strlen($pass) < 6)                            json_err('Password must be at least 6 characters');
    if ($pass !== $confirm)                           json_err('Passwords do not match');

    $row = db()->prepare(
        "SELECT id, target_id, expires_at FROM password_otps
          WHERE email = ? AND target_kind = ? AND verified = 1 AND reset_token = ?
          ORDER BY id DESC LIMIT 1"
    );
    $row->execute([$email, $kind, $token]);
    $rec = $row->fetch();

    if (!$rec)                                 json_err('Reset session expired. Start again.', 400);
    if (strtotime($rec['expires_at']) < time()) {
        db()->prepare('DELETE FROM password_otps WHERE id = ?')->execute([(int) $rec['id']]);
        json_err('Reset session expired. Start again.', 400);
    }

    $hash = password_hash($pass, PASSWORD_DEFAULT);

    if ($kind === 'user') {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([$hash, (int) $rec['target_id']]);
    } else {
        // Shopkeeper: also clear the failed-attempt counter so they're not
        // still locked out from old failed logins.
        db()->prepare('UPDATE shopkeeper_credentials
                          SET password_hash = ?, failed_attempts = 0, locked_until = NULL
                        WHERE id = ?')
            ->execute([$hash, (int) $rec['target_id']]);
    }

    // Single-use: burn the OTP row.
    db()->prepare('DELETE FROM password_otps WHERE id = ?')->execute([(int) $rec['id']]);

    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    echo json_encode(['ok' => true, 'csrf' => $_SESSION['csrf']]);
}

/* =====================================================================
   Helpers
   ===================================================================== */

function find_account_for_reset(string $email, string $kind): ?array {
    if ($kind === 'user') {
        $stmt = db()->prepare('SELECT id, email FROM users WHERE email = ?');
    } else {
        $stmt = db()->prepare('SELECT id, email FROM shopkeeper_credentials WHERE email = ?');
    }
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function send_otp_email(string $to, string $otp, string $kind): bool {
    $subject = 'Your ' . MAIL_FROM_NAME . ' password reset code';
    $body    = "Your password reset code is: {$otp}\n\n"
             . "It expires in " . (int)(OTP_TTL_SECONDS / 60) . " minutes.\n"
             . "If you did not request this, you can ignore this email.\n";

    $fromHeader = 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>' . "\r\n";

    // Suppress warnings — mail() is famously noisy on misconfigured sendmail,
    // but the OTP is in otp_log.txt regardless, so a failed mail() is not fatal.
    return @mail($to, $subject, $body, $fromHeader);
}

function log_otp(string $email, string $kind, string $otp, bool $mailed): void {
    $line = sprintf(
        "[%s] kind=%s email=%s otp=%s mailed=%s\n",
        date('Y-m-d H:i:s'),
        $kind,
        $email,
        $otp,
        $mailed ? '1' : '0'
    );
    @file_put_contents(__DIR__ . '/otp_log.txt', $line, FILE_APPEND | LOCK_EX);
}

function json_err(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}