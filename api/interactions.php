<?php
/**
 * Interaction endpoints — guest-friendly comments, likes, shares, plus a new
 * ratings / reviews system.
 *
 * Identity model
 * --------------
 * Visitors don't have accounts. To post they provide a display name; an
 * optional email is hashed (SHA-256) and only the hash is stored.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/* ===== Global JSON Error Helper ===== */
if (!function_exists('json_err')) {
    function json_err(string $msg, int $code = 400): void {
        http_response_code($code);
        echo json_encode(['error' => $msg]);
        exit;
    }
}

/* ===== Safe Default Profanity Check ===== */
if (!function_exists('profanity_check')) {
    function profanity_check(string $text): ?string {
        // Plug in custom profanity filter rules here if needed
        return null;
    }
}

/* ===== Safe Default Shopkeeper Auth Checks ===== */
if (!function_exists('require_shopkeeper')) {
    function require_shopkeeper(): void {
        if (empty($_SESSION['shopkeeper_id'])) {
            json_err('Unauthorized access', 401);
        }
    }
}

if (!function_exists('shop_profile')) {
    function shop_profile(): array {
        return ['shop_name' => $_SESSION['shop_name'] ?? 'Shop Keeper'];
    }
}

start_session_once();
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
csrf_validate();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$data   = json_input();

try {
    switch ($action) {
        case 'comments':      list_comments((int) ($_GET['product_id'] ?? 0)); break;
        case 'comment':       if ($method !== 'POST') bad_method(); add_comment($data); break;
        case 'like':          if ($method !== 'POST') bad_method(); toggle_like($data); break;
        case 'share':         if ($method !== 'POST') bad_method(); record_share($data); break;
        case 'counts':        counts((int) ($_GET['product_id'] ?? 0), $data); break;
        case 'liked':         liked((int) ($_GET['product_id'] ?? 0), $_GET['email'] ?? ''); break;

        case 'ratings':       list_ratings_public((int) ($_GET['product_id'] ?? -1), $data); break;
        case 'rate':          if ($method !== 'POST') bad_method(); submit_rating($data); break;
        case 'myReviews':     my_reviews($_GET['email'] ?? ''); break;
        case 'shopReviews':   require_shopkeeper(); shop_reviews(); break;
        case 'reply':         require_shopkeeper(); add_reply($data); break;
        case 'deleteReply':   require_shopkeeper(); delete_reply($data); break;
        case 'deleteComment': require_shopkeeper(); delete_comment($data); break;

        default: json_err('Unknown action', 400);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}

/* =====================================================
 *  Helpers
 * ===================================================== */

function guest_name(array $data): string {
    $n = trim((string) ($data['name'] ?? $data['guest_name'] ?? ''));
    if (mb_strlen($n) > 80) $n = mb_substr($n, 0, 80);
    return $n;
}

function guest_email(array $data): string {
    $e = strtolower(trim((string) ($data['email'] ?? $data['guest_email'] ?? '')));
    return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
}

function guest_email_hash(array $data): string {
    $e = guest_email($data);
    return $e === '' ? '' : hash('sha256', $e);
}

/* =====================================================
 *  Comments
 * ===================================================== */

function list_comments(int $productId): void {
    if ($productId <= 0) json_err('product_id required');

    $stmt = db()->prepare("
        SELECT c.id, c.body, c.created_at,
               COALESCE(NULLIF(c.guest_name, ''), 'Guest') AS display_name,
               c.guest_email_hash
        FROM " . db_table('comments') . " c
        WHERE c.product_id = ?
        ORDER BY c.created_at DESC
        LIMIT 500
    ");
    $stmt->execute([$productId]);
    $comments = $stmt->fetchAll();

    if ($comments) {
        $ids = array_column($comments, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $r = db()->prepare("
            SELECT id, target_id, reply_body AS body, reply_by, created_at
            FROM " . db_table('review_replies') . "
            WHERE target_kind = 'comment' AND target_id IN ($placeholders)
            ORDER BY created_at ASC
        ");
        $r->execute($ids);
        $byTarget = [];
        foreach ($r->fetchAll() as $rep) {
            $byTarget[(int) $rep['target_id']][] = [
                'id'         => (int) $rep['id'],
                'body'       => $rep['body'],
                'reply_by'   => $rep['reply_by'],
                'created_at' => $rep['created_at'],
            ];
        }
        foreach ($comments as &$c) {
            $c['id']      = (int) $c['id'];
            $c['replies'] = $byTarget[(int) $c['id']] ?? [];
        }
    }

    echo json_encode(['ok' => true, 'comments' => $comments]);
}

function add_comment(array $data): void {
    $pid  = (int) ($data['product_id'] ?? 0);
    $body = trim((string) ($data['body'] ?? ''));
    $name = guest_name($data);
    $hash = guest_email_hash($data);

    if ($pid <= 0)                          json_err('product_id required');
    if ($body === '')                       json_err('Comment cannot be empty');
    if ($name === '')                       json_err('Please enter your name');
    if (mb_strlen($body) > 1000)            json_err('Comment is too long (max 1000 chars)');
    if (($reason = profanity_check($body))) json_err($reason);

    $pdo = db();
    $exists = $pdo->prepare('SELECT 1 FROM public.products WHERE id = ?');
    $exists->execute([$pid]);
    if (!$exists->fetchColumn()) json_err('Product not found', 404);

    $stmt = $pdo->prepare(
        'INSERT INTO ' . db_table('comments') . ' (product_id, user_id, body, guest_name, guest_email_hash)
         VALUES (?, NULL, ?, ?, ?)'
    );
    $stmt->execute([$pid, $body, $name, $hash ?: null]);

    $id = (int) $pdo->lastInsertId();
    echo json_encode([
        'ok' => true,
        'id' => $id,
        'comment' => [
            'id'           => $id,
            'body'         => $body,
            'created_at'   => date('Y-m-d H:i:s'),
            'display_name' => $name,
            'replies'      => [],
        ],
    ]);
}

/* =====================================================
 *  Likes / Shares / Counts
 * ===================================================== */

function toggle_like(array $data): void {
    $pid = (int) ($data['product_id'] ?? 0);
    if ($pid <= 0) json_err('product_id required');

    $hash = guest_email_hash($data);
    if ($hash === '') json_err('Email is required to like (used only for your identity; never shared).');

    $pdo = db();
    $exists = $pdo->prepare('SELECT 1 FROM ' . db_table('products') . ' WHERE id = ?');
    $exists->execute([$pid]);
    if (!$exists->fetchColumn()) json_err('Product not found', 404);

    $sel = $pdo->prepare('SELECT id FROM ' . db_table('likes') . ' WHERE product_id = ? AND guest_email_hash = ?');
    $sel->execute([$pid, $hash]);
    if ($row = $sel->fetch()) {
        $pdo->prepare('DELETE FROM ' . db_table('likes') . ' WHERE id = ?')->execute([$row['id']]);
        $liked = false;
    } else {
        $pdo->prepare('INSERT INTO ' . db_table('likes') . ' (product_id, user_id, guest_email_hash) VALUES (?, NULL, ?)')
            ->execute([$pid, $hash]);
        $liked = true;
    }
    
    $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM ' . db_table('likes') . ' WHERE product_id = ?');
    $cntStmt->execute([$pid]);
    $count = (int) $cntStmt->fetchColumn();

    echo json_encode(['ok' => true, 'liked' => $liked, 'likes' => $count]);
}

function record_share(array $data): void {
    $pid = (int) ($data['product_id'] ?? 0);
    if ($pid <= 0) json_err('product_id required');

    $hash = guest_email_hash($data);

    $pdo = db();
    $exists = $pdo->prepare('SELECT 1 FROM public.products WHERE id = ?');
    $exists->execute([$pid]);
    if (!$exists->fetchColumn()) json_err('Product not found', 404);

    $pdo->prepare('INSERT INTO public.shares (product_id, user_id, guest_email_hash) VALUES (?, NULL, ?)')
        ->execute([$pid, $hash ?: null]);

    $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM public.shares WHERE product_id = ?');
    $cntStmt->execute([$pid]);
    $total = (int) $cntStmt->fetchColumn();

    echo json_encode(['ok' => true, 'shares' => $total]);
}

function counts(int $productId, array $data): void {
    if ($productId <= 0) json_err('product_id required');
    $pdo = db();

    $lStmt = $pdo->prepare('SELECT COUNT(*) FROM public.likes WHERE product_id = ?');
    $lStmt->execute([$productId]);
    $likes = (int) $lStmt->fetchColumn();

    $cStmt = $pdo->prepare('SELECT COUNT(*) FROM public.comments WHERE product_id = ?');
    $cStmt->execute([$productId]);
    $comments = (int) $cStmt->fetchColumn();

    $sStmt = $pdo->prepare('SELECT COUNT(*) FROM public.shares WHERE product_id = ?');
    $sStmt->execute([$productId]);
    $shares = (int) $sStmt->fetchColumn();

    $liked = false;
    $hash = guest_email_hash($data);
    if ($hash !== '') {
        $s = $pdo->prepare('SELECT 1 FROM public.likes WHERE product_id = ? AND guest_email_hash = ?');
        $s->execute([$productId, $hash]);
        $liked = (bool) $s->fetchColumn();
    }

    echo json_encode([
        'ok'       => true,
        'likes'    => $likes,
        'comments' => $comments,
        'shares'   => $shares,
        'liked'    => $liked,
    ]);
}

function liked(int $productId, string $email): void {
    if ($productId <= 0) json_err('product_id required');
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { 
        echo json_encode(['ok' => true, 'liked' => false]); 
        return; 
    }
    $hash = hash('sha256', $email);
    $s = db()->prepare('SELECT 1 FROM public.likes WHERE product_id = ? AND guest_email_hash = ?');
    $s->execute([$productId, $hash]);
    echo json_encode(['ok' => true, 'liked' => (bool) $s->fetchColumn()]);
}

/* =====================================================
 *  Ratings
 * ===================================================== */

function rating_summary(?int $productId): array {
    $pdo = db();
    if ($productId === null) {
        $stmt = $pdo->query('SELECT COUNT(*) AS n, COALESCE(AVG(stars),0) AS avg FROM public.ratings WHERE product_id IS NULL');
    } else {
        $stmt = $pdo->prepare('SELECT COUNT(*) AS n, COALESCE(AVG(stars),0) AS avg FROM public.ratings WHERE product_id = ?');
        $stmt->execute([$productId]);
    }
    $row = $stmt->fetch();
    return [
        'count'   => (int) ($row['n']   ?? 0),
        'average' => round((float) ($row['avg'] ?? 0), 2),
    ];
}

function list_ratings_public(int $productId, array $data): void {
    $scope = ($productId <= 0) ? null : $productId;
    $summary = rating_summary($scope);
    $reqHash = guest_email_hash(['email' => $_GET['email'] ?? '']);

    $own = null;
    if ($reqHash !== '') {
        if ($scope === null) {
            $stmt = db()->prepare('SELECT id, stars, review_body, created_at FROM public.ratings WHERE product_id IS NULL AND guest_email_hash = ? ORDER BY created_at DESC LIMIT 1');
            $stmt->execute([$reqHash]);
        } else {
            $stmt = db()->prepare('SELECT id, stars, review_body, created_at FROM public.ratings WHERE product_id = ? AND guest_email_hash = ? ORDER BY created_at DESC LIMIT 1');
            $stmt->execute([$scope, $reqHash]);
        }
        $own = $stmt->fetch() ?: null;
        if ($own) {
            $own['id']    = (int) $own['id'];
            $own['stars'] = (int) $own['stars'];
            $own['replies'] = fetch_replies('rating', (int) $own['id']);
        }
    }

    echo json_encode([
        'ok'      => true,
        'summary' => $summary,
        'scope'   => $scope === null ? 'shop' : 'product',
        'own'     => $own,
    ]);
}

function submit_rating(array $data): void {
    $name  = guest_name($data);
    $email = guest_email($data);
    $hash  = guest_email_hash($data);
    $stars = (int) ($data['stars'] ?? 0);
    $body  = trim((string) ($data['review_body'] ?? $data['body'] ?? ''));
    $pid   = isset($data['product_id']) && $data['product_id'] !== ''
                ? (int) $data['product_id']
                : null;

    if ($name === '')                            json_err('Please enter your name');
    if ($email === '')                           json_err('Email is required to verify your review');
    if ($stars < 1 || $stars > 5)                json_err('Please pick a rating between 1 and 5 stars');
    if (mb_strlen($name) > 80)                   json_err('Name is too long');
    if (mb_strlen($body) > 2000)                 json_err('Review is too long (max 2000 chars)');
    if ($body !== '' && ($reason = profanity_check($body))) json_err($reason);

    $pdo = db();
    if ($pid !== null) {
        $exists = $pdo->prepare('SELECT 1 FROM public.products WHERE id = ?');
        $exists->execute([$pid]);
        if (!$exists->fetchColumn()) json_err('Product not found', 404);
    }

    if ($pid === null) {
        $sel = $pdo->prepare('SELECT id FROM public.ratings WHERE product_id IS NULL AND guest_email_hash = ?');
        $sel->execute([$hash]);
    } else {
        $sel = $pdo->prepare('SELECT id FROM public.ratings WHERE product_id = ? AND guest_email_hash = ?');
        $sel->execute([$pid, $hash]);
    }
    $existing = $sel->fetch();

    if ($existing) {
        $id = (int) $existing['id'];
        $pdo->prepare('UPDATE public.ratings SET stars = ?, review_body = ?, guest_name = ? WHERE id = ?')
            ->execute([$stars, $body !== '' ? $body : null, $name, $id]);
        $action = 'updated';
    } else {
        $pdo->prepare('INSERT INTO public.ratings (product_id, guest_name, guest_email_hash, stars, review_body) VALUES (?, ?, ?, ?, ?)')
            ->execute([$pid, $name, $hash, $stars, $body !== '' ? $body : null]);
        $id = (int) $pdo->lastInsertId();
        $action = 'created';
    }

    echo json_encode([
        'ok'      => true,
        'action'  => $action,
        'id'      => $id,
        'summary' => rating_summary($pid),
        'own'     => [
            'id'          => $id,
            'stars'       => $stars,
            'review_body' => $body !== '' ? $body : null,
            'created_at'  => date('Y-m-d H:i:s'),
            'replies'     => fetch_replies('rating', $id),
        ],
    ]);
}

function my_reviews(string $email): void {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('Valid email required');
    $hash = hash('sha256', $email);

    $stmt = db()->prepare("
        SELECT r.id, r.product_id, r.stars, r.review_body, r.created_at,
               p.title AS product_title, p.image_path AS product_image
        FROM public.ratings r
        LEFT JOIN public.products p ON p.id = r.product_id
        WHERE r.guest_email_hash = ?
        ORDER BY r.created_at DESC
        LIMIT 500
    ");
    $stmt->execute([$hash]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['id']         = (int) $row['id'];
        $row['stars']      = (int) $row['stars'];
        $row['product_id'] = $row['product_id'] !== null ? (int) $row['product_id'] : null;
        $row['replies']    = fetch_replies('rating', (int) $row['id']);
    }

    echo json_encode(['ok' => true, 'reviews' => $rows]);
}

/* =====================================================
 *  Shopkeeper: review feed + replies
 * ===================================================== */

function shop_reviews(): void {
    $pdo = db();

    $stmt = $pdo->query("
        SELECT r.id, r.product_id, r.stars, r.review_body, r.created_at,
               r.guest_name, r.guest_email_hash,
               p.title AS product_title
        FROM public.ratings r
        LEFT JOIN public.products p ON p.id = r.product_id
        ORDER BY r.created_at DESC
        LIMIT 500
    ");
    $ratings = $stmt->fetchAll();

    $cstmt = $pdo->query("
        SELECT c.id, c.product_id, c.body, c.created_at,
               COALESCE(NULLIF(c.guest_name, ''), 'Guest') AS guest_name,
               c.guest_email_hash,
               p.title AS product_title
        FROM public.comments c
        LEFT JOIN public.products p ON p.id = c.product_id
        ORDER BY c.created_at DESC
        LIMIT 500
    ");
    $comments = $cstmt->fetchAll();

    foreach ($ratings as &$r) {
        $r['id']         = (int) $r['id'];
        $r['stars']      = (int) $r['stars'];
        $r['product_id'] = $r['product_id'] !== null ? (int) $r['product_id'] : null;
        $r['replies']    = fetch_replies('rating', (int) $r['id']);
    }
    foreach ($comments as &$c) {
        $c['id']         = (int) $c['id'];
        $c['product_id'] = $c['product_id'] !== null ? (int) $c['product_id'] : null;
        $c['replies']    = fetch_replies('comment', (int) $c['id']);
    }

    echo json_encode([
        'ok'       => true,
        'ratings'  => $ratings,
        'comments' => $comments,
        'summary'  => rating_summary(null),
    ]);
}

function fetch_replies(string $kind, int $targetId): array {
    $stmt = db()->prepare(
        'SELECT id, reply_body AS body, reply_by, created_at
         FROM public.review_replies
         WHERE target_kind = ? AND target_id = ?
         ORDER BY created_at ASC'
    );
    $stmt->execute([$kind, $targetId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
    }
    return $rows;
}

function add_reply(array $data): void {
    $kind = (string) ($data['target_kind'] ?? '');
    $tid  = (int)    ($data['target_id'] ?? 0);
    $body = trim((string) ($data['body'] ?? ''));

    if (!in_array($kind, ['comment', 'rating'], true)) json_err('target_kind must be comment or rating');
    if ($tid <= 0)                                     json_err('target_id required');
    if ($body === '')                                   json_err('Reply cannot be empty');
    if (mb_strlen($body) > 2000)                        json_err('Reply is too long (max 2000 chars)');

    $table = $kind === 'comment' ? 'public.comments' : 'public.ratings';
    $exists = db()->prepare("SELECT 1 FROM {$table} WHERE id = ?");
    $exists->execute([$tid]);
    if (!$exists->fetchColumn()) json_err(ucfirst($kind) . ' not found', 404);

    $profile = shop_profile();
    $by = (string) ($profile['shop_name'] ?? 'Shop');

    db()->prepare(
        'INSERT INTO public.review_replies (target_kind, target_id, reply_body, reply_by)
         VALUES (?, ?, ?, ?)'
    )->execute([$kind, $tid, $body, $by]);

    $id = (int) db()->lastInsertId();
    echo json_encode([
        'ok'    => true,
        'id'    => $id,
        'reply' => [
            'id'         => $id,
            'body'       => $body,
            'reply_by'   => $by,
            'created_at' => date('Y-m-d H:i:s'),
        ],
    ]);
}

function delete_reply(array $data): void {
    $id = (int) ($data['reply_id'] ?? 0);
    if ($id <= 0) json_err('reply_id required');
    db()->prepare('DELETE FROM public.review_replies WHERE id = ?')->execute([$id]);
    echo json_encode(['ok' => true]);
}

function delete_comment(array $data): void {
    $id = (int) ($data['comment_id'] ?? 0);
    if ($id <= 0) json_err('comment_id required');
    db()->prepare('DELETE FROM public.comments WHERE id = ?')->execute([$id]);
    
    // Clean up associated replies
    db()->prepare("DELETE FROM public.review_replies WHERE target_kind = 'comment' AND target_id = ?")
        ->execute([$id]);
    echo json_encode(['ok' => true]);
}

/* ===================================================== */

function bad_method(): void {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}