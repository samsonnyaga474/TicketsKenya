<?php
/**
 * Shared page header. Pages may set before including:
 *   $pageTitle, $pageDescription, $bodyClass, $noindex (bool), $canonicalPath, $ogImage, $jsonLd (array of schema.org objects)
 */
$user = tk_user();
$pageTitle = $pageTitle ?? TK_APP_NAME;
$pageDescription = $pageDescription ?? 'Discover live events across Kenya on TicketsKenya.';
$bodyClass = $bodyClass ?? '';
$noindex = $noindex ?? false;
$jsonLd = $jsonLd ?? [];
$reqPath = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$canonicalPath = $canonicalPath ?? $reqPath;
$base = tk_canonical_base();
$navItems = [
    ['/events/', 'Events'], ['/organisers.php', 'Organisers'], ['/pricing.php', 'Pricing'],
    ['/about.php', 'About'], ['/help.php', 'Help'], ['/contact.php', 'Contact'],
];
$isActive = fn(string $href) => rtrim($href, '/') === rtrim($reqPath, '/') || ($href === '/events/' && str_starts_with($reqPath, '/events'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="theme-color" content="#000000" />
  <title><?= tk_e($pageTitle) ?></title>
  <meta name="description" content="<?= tk_e($pageDescription) ?>" />
  <meta name="robots" content="<?= $noindex ? 'noindex, nofollow' : 'index, follow' ?>" />
<?php if (!$noindex && $base): ?>
  <link rel="canonical" href="<?= tk_e($base . $canonicalPath) ?>" />
  <meta property="og:url" content="<?= tk_e($base . $canonicalPath) ?>" />
<?php endif; ?>
  <meta property="og:type" content="website" />
  <meta property="og:site_name" content="<?= tk_e(TK_APP_NAME) ?>" />
  <meta property="og:title" content="<?= tk_e($pageTitle) ?>" />
  <meta property="og:description" content="<?= tk_e($pageDescription) ?>" />
<?php if (!empty($ogImage) && $base): ?>
  <meta property="og:image" content="<?= tk_e($base . $ogImage) ?>" />
<?php endif; ?>
  <link rel="icon" type="image/png" sizes="32x32" href="<?= tk_e(tk_asset('images/favicon-32.png')) ?>" />
  <link rel="apple-touch-icon" href="<?= tk_e(tk_asset('images/apple-touch-icon.png')) ?>" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Host+Grotesk:wght@300;400;500;600;700;800&amp;display=swap" rel="stylesheet" />
<?php if (str_contains($bodyClass, 'contact-page')): ?>
  <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&amp;family=Space+Grotesk:wght@400;500;600;700&amp;display=swap" rel="stylesheet" />
<?php endif; ?>
  <link rel="stylesheet" href="<?= tk_e(tk_asset('css/main.css')) ?>" />
  <link rel="stylesheet" href="<?= tk_e(tk_asset('css/site.css')) ?>" />
<?php foreach ($jsonLd as $block): ?>
  <script type="application/ld+json"><?= json_encode($block, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endforeach; ?>
</head>
<body class="<?= tk_e($bodyClass) ?>">
  <a class="skip-link" href="#main">Skip to content</a>
  <header class="header" id="siteHeader">
    <div class="header-inner">
      <a href="<?= tk_e(tk_base_path()) ?>/" class="logo" aria-label="TicketsKenya home">
        <img src="<?= tk_e(tk_asset('images/logo.png')) ?>" alt="" width="42" height="42" />
        <span>TicketsKenya</span>
      </a>

      <nav class="nav" aria-label="Main">
        <?php foreach ($navItems as [$href, $label]): ?>
          <a href="<?= tk_e($href) ?>"<?= $isActive($href) ? ' aria-current="page"' : '' ?>><?= tk_e($label) ?></a>
        <?php endforeach; ?>
        <?php if ($user): ?>
          <a href="/tickets/"<?= $isActive('/tickets/') ? ' aria-current="page"' : '' ?>>My Tickets</a>
          <?php if (in_array($user['role'], ['organiser', 'admin'], true)): ?><a href="/admin/events.php">Manage events</a><?php endif; ?>
        <?php endif; ?>
      </nav>

      <form class="hdr-search" action="/events/" method="get" role="search">
        <label class="sr-only" for="hdrQ">Search events</label>
        <input id="hdrQ" type="search" name="q" placeholder="Search events" autocomplete="off" />
      </form>

      <div class="ctas">
        <?php if ($user): ?>
          <span class="user-chip"><?= tk_e($user['full_name']) ?></span>
          <form method="post" action="/auth/logout.php" class="logout-form"><?= tk_csrf_field() ?><button type="submit" class="btn btn-outline">Log out</button></form>
        <?php else: ?>
          <a href="/auth/login.php" class="btn btn-outline">Log in</a>
          <a href="/organisers.php" class="btn btn-outline">Launch An Event</a>
          <a href="/events/" class="btn btn-solid">Explore Events</a>
        <?php endif; ?>
      </div>

      <button class="mobile-menu-btn" aria-label="Open menu" aria-expanded="false" aria-controls="mobileNav" id="menuBtn" type="button">
        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
      </button>
    </div>
    <div id="mobileNav" class="mobile-nav" role="dialog" aria-modal="true" aria-label="Menu" hidden>
      <button type="button" class="drawer-close" id="menuClose" aria-label="Close menu">&times;</button>
      <form class="mobile-search" action="/events/" method="get" role="search">
        <label class="sr-only" for="mobQ">Search events</label>
        <input id="mobQ" type="search" name="q" placeholder="Search events" />
      </form>
      <?php foreach ($navItems as [$href, $label]): ?><a href="<?= tk_e($href) ?>"><?= tk_e($label) ?></a><?php endforeach; ?>
      <?php if ($user): ?>
        <a href="/tickets/">My Tickets</a>
        <?php if (in_array($user['role'], ['organiser', 'admin'], true)): ?><a href="/admin/events.php">Manage events</a><?php endif; ?>
        <div class="mobile-ctas"><form method="post" action="/auth/logout.php" class="logout-form"><?= tk_csrf_field() ?><button type="submit" class="btn btn-outline">Log out</button></form></div>
      <?php else: ?>
        <div class="mobile-ctas">
          <a href="/auth/login.php" class="btn btn-outline">Log in</a>
          <a href="/organisers.php" class="btn btn-outline">Launch An Event</a>
          <a href="/events/" class="btn btn-solid">Explore Events</a>
        </div>
      <?php endif; ?>
    </div>
  </header>

  <?php
  $err = tk_flash('error');
  $ok  = tk_flash('success');
  if ($err || $ok):
  ?>
  <div class="flash-wrap">
    <?php if ($err): ?><div class="flash flash-error" role="alert"><strong>Problem:</strong> <?= tk_e($err) ?></div><?php endif; ?>
    <?php if ($ok):  ?><div class="flash flash-success" role="status"><strong>Done:</strong> <?= tk_e($ok) ?></div><?php endif; ?>
  </div>
  <?php endif; ?>

  <main id="main">
