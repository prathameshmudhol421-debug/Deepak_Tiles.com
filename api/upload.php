<?php
/**
 * Image upload endpoint.
 *   POST /api/upload.php  (multipart/form-data, field "image")
 *   Accepts requests from any logged-in regular user OR the shopkeeper.
 *   Returns { ok, url } with a relative URL to the saved image.
 *
 * Security:
 *   - Requires either a logged-in user or a logged-in shopkeeper.
 *   - Requires a valid CSRF token (server-set cookie session).
 *   - Validates MIME type, file extension, AND that the file is a real image
 *     (via getimagesize). Real image validation catches uploaded PHP shells
 *     disguised as images.
 *   - Re-encodes the file's extension based on the verified MIME.
 *   - Stores under a random name; never trusts the original filename.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

start_session_once();
csrf_validate();

$user = $_SESSION['user']       ?? null;
$shop = $_SESSION['shopkeeper'] ?? null;
if (!$user && !$shop) {
    http_response_code(401);
    echo json_encode(['error' => 'Sign in required']);
    exit;
}

if (!isset($_FILES['image'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['image'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Upload error code ' . $file['error']]);
    exit;
}

if ($file['size'] > 5 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => 'Image too large (max 5 MB)']);
    exit;
}

if ($file['size'] <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty file']);
    exit;
}

$allowed = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];

// First gate: MIME type from the actual file content (not what the client claims).
$mime = mime_content_type($file['tmp_name']);
if (!isset($allowed[$mime])) {
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported image type: ' . $mime]);
    exit;
}

// Second gate: getimagesize — proves the file is a real image of the claimed type.
$info = @getimagesize($file['tmp_name']);
if (!$info) {
    http_response_code(400);
    echo json_encode(['error' => 'File is not a valid image']);
    exit;
}
if (!isset($allowed[$info['mime']])) {
    http_response_code(400);
    echo json_encode(['error' => 'Image type not allowed: ' . $info['mime']]);
    exit;
}

// Reject SVGs entirely — they can contain <script> and execute in the browser.
if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) === 'svg') {
    http_response_code(400);
    echo json_encode(['error' => 'SVG uploads are not allowed']);
    exit;
}

// Sanitize the user's original filename and ignore its extension — we set
// the extension based on the verified MIME so a polyglot file can't sneak
// in a .php extension.
$prefix = $user ? 'u' . $user['id'] : 'shop';
$name   = 'img_' . $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$info['mime']];

$uploadDir = __DIR__ . '/../uploads';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$dest = $uploadDir . '/' . $name;
if (!move_uploaded_file($file['tmp_name'], $dest)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save file']);
    exit;
}

// Double-check the saved file is still a real image (defense in depth).
$post = @getimagesize($dest);
if (!$post) {
    @unlink($dest);
    http_response_code(400);
    echo json_encode(['error' => 'Saved file failed validation']);
    exit;
}

// Ensure the file is not executable. Strip any execute bits.
@chmod($dest, 0644);

echo json_encode(['ok' => true, 'url' => 'uploads/' . $name]);
