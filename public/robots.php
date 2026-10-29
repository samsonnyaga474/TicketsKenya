<?php
// Dynamic robots.txt so the Sitemap line uses the configured production URL (never a hard-coded domain).
require_once dirname(__DIR__) . '/includes/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
$base = tk_canonical_base() ?: ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
echo "User-agent: *\nAllow: /\nDisallow: /admin/\nDisallow: /auth/\nDisallow: /tickets/\nDisallow: /payments/\nDisallow: /events/?*\n\nSitemap: {$base}/sitemap.xml\n";
