<?php
/**
 * Router for the PHP built-in web server (`php -S 0.0.0.0:$PORT -t . router.php`).
 *
 * Render's PHP buildpack uses nginx + try_files, which does NOT respect
 * Apache's .htaccess. We work around that by running PHP's own development
 * server in front of a tiny router script. This keeps the project portable:
 * the same router works on Render, on a developer's local PHP-S, and on any
 * other host that runs `php -S` with a router.
 *
 * What this router does:
 *   1. Serves real static files (CSS, JS, images, uploads/) directly.
 *   2. Forbids any .php file inside uploads/ from executing (defence in
 *      depth — uploads/.htaccess already does this on Apache).
 *   3. For any request that didn't match a real file, falls back to:
 *         - /                 → index.html  (DirectoryIndex equivalent)
 *         - /api/<anything>   → the matching PHP file
 *         - everything else   → index.html  (SPA deep-link support)
 */

declare(strict_types=1);

$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = is_string($uri) ? $uri : '/';

// 1. Block any attempt to execute PHP out of uploads/.
if (preg_match('#^/uploads/.+\.php$#i', $path)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

// 2. Serve existing static files as-is (CSS, JS, uploaded images, favicon,
//    uploads/.htaccess, etc.). PHP's built-in server already does this for
//    most files, but we add an explicit check for the project root files.
$docRoot = __DIR__;
$candidate = $docRoot . $path;
if ($path !== '/' && is_file($candidate)) {
    // Returning `false` tells the built-in server to serve the file itself.
    return false;
}

// 3. DirectoryIndex: serve index.html for the project root.
if ($path === '/' || $path === '') {
    require $docRoot . '/index.html';
    return true;
}

// 4. API requests: /api/foo.php → api/foo.php, /api/foo?x=1 → api/foo.php.
if (preg_match('#^/api/(.+?)(?:/)?$#', $path, $m)) {
    $target = $docRoot . '/api/' . $m[1];
    // If they asked for the file with no .php extension, append it.
    if (!str_ends_with($target, '.php') && is_file($target . '.php')) {
        $target .= '.php';
    }
    if (is_file($target)) {
        require $target;
        return true;
    }
    // Fall through to 404 (we don't want to SPA-fallback an unknown API
    // route — that would mask real bugs).
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not found', 'path' => $path]);
    return true;
}

// 5. SPA fallback for any other path — deep links like /rate, /shop, etc.
//    hit index.html and the client-side JS router takes over.
require $docRoot . '/index.html';
return true;
