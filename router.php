<?php
/**
 * Router for the PHP built-in web server (`php -S 0.0.0.0:$PORT -t . router.php`).
 *
 * Render's PHP buildpack uses PHP's development server or nginx.
 * This router emulates Apache's DirectoryIndex and .htaccess routing.
 *
 * Features:
 *   1. Blocks execution of PHP scripts inside the /uploads/ directory.
 *   2. Serves static files directly.
 *   3. Routes root (/) to index.html.
 *   4. Routes /api/* requests to their respective PHP handlers.
 *   5. Fallback routing to index.html for Single Page Application (SPA) links.
 */

declare(strict_types=1);

$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = is_string($uri) ? $uri : '/';

// 1. Defense-in-depth: Block execution of any PHP file inside /uploads/
if (preg_match('#^/uploads/.+\.php$#i', $path)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

// 2. Serve existing static files as-is
$docRoot = __DIR__;
$candidate = $docRoot . $path;
if ($path !== '/' && is_file($candidate)) {
    return false; // Built-in PHP web server serves static file directly
}

// 3. DirectoryIndex: serve index.html for root requests
if ($path === '/' || $path === '') {
    require $docRoot . '/index.html';
    return true;
}

// 4. API Request router: /api/auth -> api/auth.php
if (preg_match('#^/api/(.+?)(?:/)?$#', $path, $m)) {
    $target = $docRoot . '/api/' . $m[1];
    
    // Append .php if omitted in URL path
    if (!str_ends_with($target, '.php') && is_file($target . '.php')) {
        $target .= '.php';
    }
    
    if (is_file($target)) {
        require $target;
        return true;
    }

    // 404 response for unknown API endpoints
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not found', 'path' => $path]);
    return true;
}

// 5. SPA Fallback: Route unknown client paths to index.html
if (is_file($docRoot . '/index.html')) {
    require $docRoot . '/index.html';
    return true;
}

http_response_code(404);
echo 'Not Found';
return true;