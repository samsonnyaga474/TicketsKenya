<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$all = tk_events_query([]);                       // every eligible upcoming event
$total = count($all);
$strip = array_slice($all, 0, 16);                // nearest events for the carousel; the full list lives on /events/
$min = tk_event_min_prices();
$categoryCounts = [];
foreach ($all as $event) {
    $category = trim((string)($event['category'] ?? ''));
    if ($category !== '') $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
}
ksort($categoryCounts, SORT_NATURAL | SORT_FLAG_CASE);
$stats = [
    ['title' => 'Live Events Powered', 'text' => 'With infinite moments unlocked', 'value' => 2500, 'suffix' => '+', 'unit' => ''],
    ['title' => 'Tickets Sold', 'text' => 'Proving there’s value in the culture', 'value' => 520, 'suffix' => 'k+', 'unit' => ''],
    ['title' => 'Unique Fans', 'text' => 'Investing and showing up for the culture', 'value' => 350, 'suffix' => 'k+', 'unit' => ''],
    ['title' => 'Active Markets', 'text' => 'Running the show and driving the culture in KE, GH, TZ, RW, UG, SA', 'value' => 6, 'suffix' => '', 'unit' => 'countries'],
];

$pageTitle = 'TicketsKenya | Discover and book live events in Kenya';
$pageDescription = 'Browse upcoming concerts, parties, conferences and community events in Nairobi and across Kenya on TicketsKenya.';
$bodyClass = 'home';
$ogImage = '/assets/images/hero-bg.jpg';
$base = tk_canonical_base();
$jsonLd = [
    ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => TK_APP_NAME] + ($base ? ['url' => $base, 'logo' => $base . '/assets/images/logo.png'] : []),
    ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => TK_APP_NAME] + ($base ? ['url' => $base . '/', 'potentialAction' => ['@type' => 'SearchAction', 'target' => $base . '/events/?q={search_term_string}', 'query-input' => 'required name=search_term_string']] : []),
];
require dirname(__DIR__) . '/templates/header.php';

$culture = [
    ['culture-1.jpg', 'Crowd at a live music show', 'Live music'],
    ['culture-2.jpg', 'Festival lights over a crowd', 'Festivals'],
    ['culture-3.jpg', 'Dancers on a night out', 'Nightlife'],
    ['culture-4.jpg', 'Stage lighting at a concert', 'Stage and sound'],
    ['culture-5.jpg', 'People gathering at an outdoor event', 'Community'],
];
?>
<div class="tk-stack">

  <section class="tk-st tk-hero" aria-labelledby="heroH">
    <?php
      $heroVideo = TK_PUBLIC . '/assets/images/hero.mp4';
      $heroPoster = tk_asset('images/hero-bg.jpg');
    ?>
    <?php if (is_file($heroVideo)): ?>
      <video class="tk-hero-img" autoplay muted loop playsinline poster="<?= tk_e($heroPoster) ?>" aria-hidden="true">
        <source src="<?= tk_e(tk_asset('images/hero.mp4')) ?>" type="video/mp4">
      </video>
    <?php else: ?>
      <img class="tk-hero-img" src="<?= tk_e($heroPoster) ?>" alt="" width="1920" height="1372" fetchpriority="high">
    <?php endif; ?>
    <div class="tk-hero-inner">
      <p class="tk-hero-kicker">We are</p>
      <h1 id="heroH">The culture</h1>
      <p class="tk-hero-sub">Find what is on in Kenya this week, see the real date, venue and price, then go.</p>
      <div class="tk-hero-cta">
        <a href="/events/" class="btn btn-solid">Explore Events <span class="btn-arrow" aria-hidden="true">→</span></a>
        <a href="/organisers.php" class="btn btn-dark">Launch An Event</a>
      </div>
    </div>
  </section>

  <section class="tk-st tk-pad" aria-labelledby="upH">
    <div class="inner">
      <div class="tk-two">
        <h2 class="tk-h2" id="upH">Happening next</h2>
        <p class="tk-lead">The nearest upcoming events, soonest first.</p>
      </div>
      <?php if ($strip): ?>
        <div class="tk-marquee" data-marquee data-speed="38" role="region" aria-label="Upcoming events">
          <div class="tk-marquee-track">
            <?php foreach ($strip as $ev) tk_render_event_card($ev, $min, 'strip'); ?>
          </div>
        </div>
        <p style="margin-top:28px"><a class="btn btn-solid" href="/events/">See All Events</a></p>
      <?php else: ?>
        <div class="empty-state"><strong>No upcoming events right now</strong>New events appear here as soon as they are published.</div>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($categoryCounts): ?>
  <section class="tk-st tk-pad tk-deal-section" aria-labelledby="dealH">
    <div class="inner tk-deal-inner">
      <div>
        <h2 class="tk-h2" id="dealH">Let the cards pick your next plan</h2>
        <p class="tk-lead">Deal a category to find upcoming events. The card only reveals categories that have listings.</p>
      </div>
      <div class="tk-deal" data-category-deck data-categories="<?= tk_e(json_encode(array_map(fn($name, $count) => ['name' => $name, 'count' => $count, 'url' => '/events/?' . http_build_query(['category' => [$name]])], array_keys($categoryCounts), array_values($categoryCounts)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>">
        <div class="tk-playing-card" data-card aria-live="polite">
          <span class="tk-card-mark" aria-hidden="true">TK</span>
          <span class="tk-card-label" data-card-label>TicketsKenya</span>
          <span class="tk-card-count" data-card-count>Pick a card</span>
        </div>
        <button class="btn btn-solid" type="button" data-deal-card>Deal a card</button>
        <a class="btn btn-outline" data-card-link href="/events/" hidden>Browse this category</a>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <section class="tk-st tk-pad tk-lime" aria-labelledby="cultH">
    <div class="inner">
      <div class="tk-two">
        <h2 class="tk-h2" id="cultH">From the dance floor to the conference hall</h2>
        <p class="tk-lead">Music, nightlife, business, food, faith and community. If it is happening in Kenya, it belongs on one calendar.</p>
      </div>
      <div class="tk-marquee" data-marquee data-speed="30" aria-label="Event moods">
        <div class="tk-marquee-track">
          <?php foreach ($culture as [$img, $alt, $cap]): ?>
            <figure><img src="<?= tk_e(tk_asset('images/' . $img)) ?>" alt="<?= tk_e($alt) ?>" width="560" height="400" loading="lazy" decoding="async"><figcaption><?= tk_e($cap) ?></figcaption></figure>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </section>

  <section class="tk-st tk-pad tk-orange tk-st-last" aria-labelledby="statH">
    <div class="inner">
      <div class="tk-two">
        <h2 class="tk-h2 tk-proof-title" id="statH">Proof in the platform</h2>
        <p class="tk-lead" style="color:#fff">A commitment to quality, reflected in strong client relationships and a track record of returning collaborations.</p>
      </div>
      <div class="tk-stats">
        <?php foreach ($stats as $s): ?>
          <div class="tk-stat">
            <h3><?= tk_e($s['title']) ?></h3>
            <p><?= tk_e($s['text']) ?></p>
            <p class="tk-num">
              <span class="sr-only"><?= (int)$s['value'] . tk_e($s['suffix'] . ($s['unit'] !== '' ? ' ' . $s['unit'] : '')) ?></span>
              <span aria-hidden="true"><span data-count="<?= (int)$s['value'] ?>" data-suffix="<?= tk_e($s['suffix']) ?>"><?= (int)$s['value'] . tk_e($s['suffix']) ?></span><?php if ($s['unit'] !== ''): ?><small><?= tk_e($s['unit']) ?></small><?php endif; ?></span>
            </p>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

</div>
<?php require dirname(__DIR__) . '/templates/footer.php'; ?>
