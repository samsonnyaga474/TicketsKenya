<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
header('Content-Type: application/xml; charset=utf-8');
$base = tk_canonical_base();
if (!$base) {
    // Dev fallback only: never echo an unvalidated Host header.
    $host = preg_replace('/[^A-Za-z0-9.:\-]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
    $base = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($host !== '' ? $host : 'localhost'));
}
$static = ['/' => 'daily', '/events/' => 'daily', '/about.php' => 'monthly', '/help.php' => 'monthly', '/contact.php' => 'yearly', '/organisers.php' => 'monthly', '/pricing.php' => 'monthly', '/privacy.php' => 'yearly', '/terms.php' => 'yearly', '/refunds.php' => 'yearly', '/cookies.php' => 'yearly'];
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($static as $path => $freq) echo '  <url><loc>' . htmlspecialchars($base . $path, ENT_XML1) . '</loc><changefreq>' . $freq . '</changefreq></url>' . "\n";
foreach (tk_events_query([]) as $e) {   // same eligibility rules as the public listing
    $mod = !empty($e['updated_at']) && strtotime((string)$e['updated_at']) ? '<lastmod>' . date('c', strtotime((string)$e['updated_at'])) . '</lastmod>' : '';
    echo '  <url><loc>' . htmlspecialchars($base . '/events/' . rawurlencode($e['slug']), ENT_XML1) . '</loc>' . $mod . '</url>' . "\n";
}
echo '</urlset>' . "\n";
