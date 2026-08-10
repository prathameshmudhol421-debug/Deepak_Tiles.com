<?php
/**
 * Product + Blog endpoints.
 *   GET    /api/blogs.php                    -> list all products (public)
 *   GET    /api/blogs.php?kind=blog          -> list only blog posts (public)
 *   GET    /api/blogs.php?id=N               -> one product (public)
 *   GET    /api/blogs.php?kind=blog&mine=1   -> the shopkeeper's blog list
 *   POST   /api/blogs.php                    -> create (shopkeeper only);
 *                                              pass `kind: "blog"` for a blog
 *   PUT    /api/blogs.php?id=N              -> update (shopkeeper only)
 *   DELETE /api/blogs.php?id=N              -> delete (shopkeeper only)
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
start_session_once();
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
csrf_validate();

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$action = $_GET['action'] ?? '';
$kind   = $_GET['kind'] ?? '';
$isBlog = ($kind === 'blog');
$data   = json_input();

try {
    switch ($method) {
        case 'GET':
            if ($id > 0)            return get_one($id, $isBlog);
            if ($isBlog)            return get_blogs();
            return get_all();

        case 'POST':   return create($data);
        case 'PUT':    return update($id, $data);
        case 'DELETE': return destroy($id);

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}

/* ===================================================== */
function get_all(): void {
    $sql = 'SELECT id, title, body, price, image_path, is_blog, created_at, updated_at FROM products WHERE is_blog = 0 ORDER BY created_at DESC LIMIT 200';
    $rows = db()->query($sql)->fetchAll();
    echo json_encode(['ok' => true, 'products' => $rows]);
}

function get_blogs(): void {
    $sql = 'SELECT id, title, body, price, image_path, is_blog, created_at, updated_at
            FROM products WHERE is_blog = 1
            ORDER BY created_at DESC, id DESC LIMIT 200';
    $rows = db()->query($sql)->fetchAll();
    echo json_encode(['ok' => true, 'blogs' => $rows]);
}

function get_one(int $id, bool $isBlog): void {
    $where = $isBlog ? 'AND is_blog = 1' : 'AND is_blog = 0';
    $stmt = db()->prepare("SELECT id, title, body, price, image_path, is_blog, created_at, updated_at FROM products WHERE id = ? $where");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'Not found']); return; }
    echo json_encode(['ok' => true, 'product' => $row]);
}

function get_mine(): void { get_all(); }

function create(array $data): void {
    require_shopkeeper();
    $title  = trim($data['title'] ?? '');
    $body   = trim($data['body']  ?? '');
    $price  = isset($data['price']) && $data['price'] !== '' ? (float) $data['price'] : 0.0;
    $img    = trim($data['image_path'] ?? '') ?: null;
    $kind   = ($data['kind'] ?? '') === 'blog' ? 1 : 0;

    if ($title === '') json_err('Title is required');
    if ($body  === '') json_err('Description is required');

    $stmt = db()->prepare('INSERT INTO products (title, body, price, image_path, is_blog) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$title, $body, $price, $img, $kind]);

    echo json_encode(['ok' => true, 'id' => (int) db()->lastInsertId()]);
}

function update(int $id, array $data): void {
    if ($id <= 0) json_err('Invalid id');
    require_shopkeeper();

    $stmt = db()->prepare('SELECT id, is_blog FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) json_err('Not found', 404);

    $title = trim($data['title'] ?? '');
    $body  = trim($data['body']  ?? '');
    $price = isset($data['price']) && $data['price'] !== '' ? (float) $data['price'] : 0.0;
    $img   = array_key_exists('image_path', $data) ? trim($data['image_path']) : null;
    if ($title === '') json_err('Title is required');
    if ($body  === '') json_err('Description is required');

    $stmt = db()->prepare('UPDATE products SET title = ?, body = ?, price = ?, image_path = ? WHERE id = ?');
    $stmt->execute([$title, $body, $price, $img, $id]);

    echo json_encode(['ok' => true]);
}

function destroy(int $id): void {
    if ($id <= 0) json_err('Invalid id');
    require_shopkeeper();

    $stmt = db()->prepare('SELECT id FROM products WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) json_err('Not found', 404);

    $stmt = db()->prepare('DELETE FROM products WHERE id = ?');
    $stmt->execute([$id]);

    echo json_encode(['ok' => true]);
}

function json_err(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}
