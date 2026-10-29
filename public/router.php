<?php
/**
 * Router for the PHP built-in server (local development):
 *   php -S localhost:8080 router.php
 * On Apache/XAMPP the same rules live in .htaccess.
 */
$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = __DIR__ . $path;

// Never serve dotfiles or anything outside public/.
if (preg_match('#/\.#', $path) || str_contains($path, '..')) { http_response_code(404); require __DIR__ . '/404.php'; return true; }

if ($path === '/sitemap.xml') { require __DIR__ . '/sitemap.php'; return true; }
if ($path === '/robots.txt')  { require __DIR__ . '/robots.php'; return true; }

if ($path !== '/' && is_file($file)) {
    return false; // static asset or real PHP file
}
if (is_dir($file) && is_file(rtrim($file, '/') . '/index.php')) {
    require rtrim($file, '/') . '/index.php';
    return true;
}
// Clean event URLs: /events/<slug>
if (preg_match('#^/events/([a-z0-9][a-z0-9-]{0,120})/?$#', $path, $m)) {
    $_GET['slug'] = $m[1];
    define('TK_CLEAN_ROUTE', true);
    require __DIR__ . '/events/view.php';
    return true;
}
http_response_code(404);
require __DIR__ . '/404.php';
return true;
