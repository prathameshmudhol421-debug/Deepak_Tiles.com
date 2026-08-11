<?php
$root = __DIR__;
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$uri = rawurldecode($uri);

if ($uri === '/' || $uri === '/index.html') {
    $target = $root . '/spider.html';
    if (is_file($target)) {
        readfile($target);
        exit;
    }
}

$path = $root . $uri;
if ($uri !== '/' && is_file($path)) {
    return false;
}

if ($uri !== '/' && is_dir($path)) {
    $indexPath = $path . '/index.html';
    if (is_file($indexPath)) {
        readfile($indexPath);
        exit;
    }
}

if (preg_match('#\.[^/]+$#', $uri) === 0) {
    $target = $root . '/spider.html';
    if (is_file($target)) {
        readfile($target);
        exit;
    }
}

return false;
