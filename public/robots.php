<?php
// Dynamic robots.txt so the Sitemap line uses the configured production URL (never a hard-coded domain).
require_once dirname(__DIR__) . '/includes/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
$base = tk_canonical_base();
if (!$base) {
    // Dev fallback only: never echo an unvalidated Host header.
    $host = preg_replace('/[^A-Za-z0-9.:\-]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
    $base = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($host !== '' ? $host : 'localhost'));
}
echo "User-agent: *\nAllow: /\nDisallow: /admin/\nDisallow: /auth/\nDisallow: /tickets/\nDisallow: /payments/\nDisallow: /events/?*\n\nSitemap: {$base}/sitemap.xml\n";
