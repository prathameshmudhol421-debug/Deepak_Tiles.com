<?php
/**
 * ONE-TIME shopkeeper password setter for Render (no SSH available).
 *
 * Guarded by a server-side secret (SETUP_TOKEN env var). Anyone calling
 * this endpoint without the token is rejected.
 *
 * USAGE (from any browser or curl, after deploying):
 *
 *   POST https://deepak-tiles-com-1.onrender.com/api/set_shopkeeper_password.php
 *     Headers: X-Setup-Token: <your SETUP_TOKEN>
 *     Body (JSON): { "username": "Deepak", "password": "YourNewPassword!" }
 *
 *   OR via GET (less safe, but convenient for one-off use):
 *     https://deepak-tiles-com-1.onrender.com/api/set_shopkeeper_password.php?token=<SETUP_TOKEN>&u=Deepak&p=YourNewPassword!
 *
 * AFTER the password is set:
 *   1. Verify you can log in via /api/auth.php?action=shopLogin
 *   2. DELETE this file from the repo and push so it cannot be reused.
 *
 * SECURITY: this file MUST be deleted once the password is set.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

$token = getenv('SETUP_TOKEN') ?: '';
if ($token === '') {
    http_response_code(500);
    echo json_encode(['error' => 'SETUP_TOKEN env var is not configured on the server.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $provided = $_GET['token'] ?? '';
    $username = (string)($_GET['u'] ?? '');
    $password = (string)($_GET['p'] ?? '');
} else {
    $provided = $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '';
    $body     = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
    $username = (string)($body['username'] ?? '');
    $password = (string)($body['password'] ?? '');
}

if (!hash_equals($token, (string)$provided)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: invalid setup token.']);
    exit;
}

if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['error' => 'username and password are required.']);
    exit;
}
if (strlen($password) < 8) {
    http_response_code(400);
    echo json_encode(['error' => 'password must be at least 8 characters.']);
    exit;
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$pdo  = db();

try {
    $stmt = $pdo->prepare(
        'INSERT INTO public.shopkeeper_credentials (username, password_hash, failed_attempts, locked_until)
         VALUES (?, ?, 0, NULL)
         ON CONFLICT (username) DO UPDATE
         SET password_hash = EXCLUDED.password_hash,
             failed_attempts = 0,
             locked_until = NULL'
    );
    $stmt->execute([$username, $hash]);

    echo json_encode([
        'ok'       => true,
        'username' => $username,
        'message'  => 'Shopkeeper password saved. DELETE api/set_shopkeeper_password.php NOW.',
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save password: ' . $e->getMessage()]);
}
