<?php
/** Shared inner-page layout: compact hero that the content slides over (car-window effect), then a content wrapper. */
declare(strict_types=1);

function tk_page_open(string $title, string $description, string $h1, string $lead = '', array $opts = []): void
{
    global $pageTitle, $pageDescription, $noindex, $bodyClass, $jsonLd, $canonicalPath, $ogImage;
    $pageTitle = $title; $pageDescription = $description;
    $noindex = $opts['noindex'] ?? false; $bodyClass = $opts['bodyClass'] ?? 'inner';
    if (isset($opts['jsonLd'])) $jsonLd = $opts['jsonLd'];
    require TK_ROOT . '/templates/header.php';
    echo '<div class="tk-stack"><section class="tk-st pg-hero" aria-labelledby="pgH"><div class="pg-hero-inner"><h1 id="pgH">' . tk_e($h1) . '</h1>';
    if ($lead !== '') echo '<p>' . tk_e($lead) . '</p>';
    echo '</div></section><section class="tk-st tk-st-last pg-body"><div class="pg-wrap">';
}

function tk_page_close(): void
{
    echo '</div></section></div>';
    require TK_ROOT . '/templates/footer.php';
}

/** Breadcrumb JSON-LD from [[name, path], ...]; only emitted when a production URL is configured. */
function tk_breadcrumb_ld(array $crumbs): array
{
    $base = tk_canonical_base();
    if (!$base) return [];
    $items = [];
    foreach ($crumbs as $i => [$name, $path]) $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => $base . $path];
    return [['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items]];
}
