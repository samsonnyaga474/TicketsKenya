<?php
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

/* ---- read and sanitise filters (GET only, nothing here changes data) ---- */
$known = tk_known_categories();
$cats = array_values(array_intersect($known, array_map('strval', (array)($_GET['category'] ?? []))));
$when = in_array($_GET['when'] ?? '', ['today', 'tomorrow', 'weekend'], true) ? $_GET['when'] : '';
$price = in_array($_GET['price'] ?? '', ['free', 'paid'], true) ? $_GET['price'] : '';
$sort = ($_GET['sort'] ?? '') === 'title' ? 'title' : 'date';
$dateOk = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false;
$from = $dateOk($_GET['from'] ?? null) ? $_GET['from'] : '';
$to = $dateOk($_GET['to'] ?? null) ? $_GET['to'] : '';
$q = tk_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$cityAll = [];
foreach (tk_events_query([]) as $e) if (($e['city'] ?? '') !== '') $cityAll[$e['city']] = true;
ksort($cityAll);
$city = isset($cityAll[$_GET['city'] ?? '']) ? $_GET['city'] : '';

$filters = ['q' => $q, 'city' => $city, 'category' => $cats, 'when' => $when, 'from' => $from, 'to' => $to, 'price' => $price, 'sort' => $sort === 'date' ? '' : $sort];
$events = tk_events_query($filters + ['sort' => $sort]);
$total = count($events);
$pages = max(1, (int)ceil($total / TK_EVENTS_PER_PAGE));
$page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
$slice = array_slice($events, ($page - 1) * TK_EVENTS_PER_PAGE, TK_EVENTS_PER_PAGE);
$groups = tk_events_group_by_date($slice);
$min = tk_event_min_prices();
$hasFilter = $q !== '' || $city !== '' || $cats || $when || $from || $to || $price;

$pageTitle = ($hasFilter ? 'Event search results' : 'Explore upcoming events in Kenya') . ' | TicketsKenya';
$pageDescription = 'Browse ' . $total . ' upcoming event' . ($total === 1 ? '' : 's') . ' in Kenya, grouped by date. Filter by category, date, city and price.';
// Only the clean, unfiltered first page is indexed; filtered or deep pages would be duplicate content.
$noindex = $hasFilter || $page > 1;
$canonicalPath = '/events/';
$bodyClass = 'events-page';
require dirname(__DIR__, 2) . '/templates/header.php';
$qsBase = $filters;
?>
<div class="tk-stack">
<section class="tk-st tk-st-last tk-pad" style="min-height:70vh" aria-labelledby="evH">
  <div class="inner">
    <div class="page-hero" style="padding-top:8px">
      <h1 id="evH">Explore events</h1>
      <p>Every upcoming event on TicketsKenya, soonest first and grouped by day.</p>
    </div>

    <form class="ev-toolbar" method="get" action="/events/" role="search" id="evForm">
      <label>Search
        <input type="search" name="q" value="<?= tk_e($q) ?>" placeholder="Event, venue or place" maxlength="80">
      </label>
      <label>City
        <select name="city">
          <option value="">All cities</option>
          <?php foreach (array_keys($cityAll) as $c): ?><option value="<?= tk_e($c) ?>"<?= $c === $city ? ' selected' : '' ?>><?= tk_e($c) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Sort by
        <select name="sort">
          <option value="date"<?= $sort === 'date' ? ' selected' : '' ?>>Date (soonest)</option>
          <option value="title"<?= $sort === 'title' ? ' selected' : '' ?>>Title (A to Z)</option>
        </select>
      </label>
      <div style="display:flex;gap:8px">
        <button class="btn btn-solid" type="submit">Search</button>
        <button class="btn btn-outline" type="button" id="openFilters" hidden aria-haspopup="dialog">Filters<?= $cats || $price || $from || $to ? ' (on)' : '' ?></button>
      </div>
      <?php // keep dialog filters when the toolbar is submitted ?>
      <?php foreach ($cats as $c): ?><input type="hidden" name="category[]" value="<?= tk_e($c) ?>"><?php endforeach; ?>
      <?php foreach (['when' => $when, 'price' => $price, 'from' => $from, 'to' => $to] as $k => $v): if ($v !== ''): ?><input type="hidden" name="<?= $k ?>" value="<?= tk_e($v) ?>"><?php endif; endforeach; ?>
    </form>

    <div class="quick" aria-label="Quick date filters">
      <?php foreach (['' => 'All dates', 'today' => 'Today', 'tomorrow' => 'Tomorrow', 'weekend' => 'This weekend'] as $k => $label): ?>
        <a class="chip<?= $when === $k ? ' on' : '' ?>" href="/events/<?= tk_e(tk_qs($qsBase, ['when' => $k, 'page' => ''])) ?>"<?= $when === $k ? ' aria-current="true"' : '' ?>><?= tk_e($label) ?></a>
      <?php endforeach; ?>
      <?php foreach (['free' => 'Free', 'paid' => 'Paid'] as $k => $label): ?>
        <a class="chip<?= $price === $k ? ' on' : '' ?>" href="/events/<?= tk_e(tk_qs($qsBase, ['price' => $price === $k ? '' : $k, 'page' => ''])) ?>"<?= $price === $k ? ' aria-current="true"' : '' ?>><?= tk_e($label) ?></a>
      <?php endforeach; ?>
      <?php if ($hasFilter): ?><a class="chip" href="/events/">Clear all</a><?php endif; ?>
    </div>

    <dialog class="filters-dlg" id="filtersDlg" aria-labelledby="fdTitle">
      <form class="dlg-form" method="get" action="/events/">
        <div class="dlg-head"><h2 id="fdTitle">Filters</h2><button type="button" class="link-btn" data-close-dlg aria-label="Close filters">Close</button></div>
        <?php foreach (['q' => $q, 'city' => $city, 'sort' => $sort === 'date' ? '' : $sort] as $k => $v): if ($v !== ''): ?><input type="hidden" name="<?= $k ?>" value="<?= tk_e($v) ?>"><?php endif; endforeach; ?>
        <fieldset><legend>Category</legend>
          <div class="opt-grid">
            <?php foreach ($known as $c): ?>
              <label class="opt"><input type="checkbox" name="category[]" value="<?= tk_e($c) ?>"<?= in_array($c, $cats, true) ? ' checked' : '' ?>><?= tk_e($c) ?></label>
            <?php endforeach; ?>
          </div>
        </fieldset>
        <fieldset><legend>Date</legend>
          <div class="opt-grid">
            <?php foreach (['' => 'Any day', 'today' => 'Today', 'tomorrow' => 'Tomorrow', 'weekend' => 'This weekend'] as $k => $label): ?>
              <label class="opt"><input type="radio" name="when" value="<?= $k ?>"<?= $when === $k ? ' checked' : '' ?>><?= tk_e($label) ?></label>
            <?php endforeach; ?>
          </div>
          <div class="opt-grid" style="margin-top:12px">
            <label class="f">From<input type="date" name="from" value="<?= tk_e($from) ?>"></label>
            <label class="f">To<input type="date" name="to" value="<?= tk_e($to) ?>"></label>
          </div>
        </fieldset>
        <fieldset><legend>Price (confirmed prices only)</legend>
          <div class="opt-grid">
            <?php foreach (['' => 'Any', 'free' => 'Free', 'paid' => 'Paid'] as $k => $label): ?>
              <label class="opt"><input type="radio" name="price" value="<?= $k ?>"<?= $price === $k ? ' checked' : '' ?>><?= tk_e($label) ?></label>
            <?php endforeach; ?>
          </div>
        </fieldset>
        <div class="dlg-foot"><button type="button" class="link-btn" id="clearFilters">Clear all</button><button type="submit" class="btn btn-solid">View events</button></div>
      </form>
    </dialog>

    <p class="result-count" role="status">
      <?php if ($total === 0): ?>No events match.<?php else: ?>Showing <?= (int)(($page - 1) * TK_EVENTS_PER_PAGE + 1) ?> to <?= (int)(($page - 1) * TK_EVENTS_PER_PAGE + count($slice)) ?> of <?= (int)$total ?> event<?= $total === 1 ? '' : 's' ?><?php endif; ?>
    </p>

    <?php if (!$groups): ?>
      <div class="empty-state"><strong>Nothing found</strong><?= $hasFilter ? 'Try removing a filter or searching for something broader.' : 'No upcoming events are published yet. Check back soon.' ?>
        <?php if ($hasFilter): ?><p style="margin-top:14px"><a class="btn btn-solid" href="/events/">Clear filters</a></p><?php endif; ?></div>
    <?php endif; ?>

    <?php foreach ($groups as $ymd => $list): ?>
      <section class="day-group" aria-labelledby="d-<?= tk_e($ymd) ?>">
        <h2 class="day-head" id="d-<?= tk_e($ymd) ?>"><?= tk_e(tk_date_heading($ymd)) ?> <span><?= count($list) ?> event<?= count($list) === 1 ? '' : 's' ?></span></h2>
        <div class="ev-grid">
          <?php foreach ($list as $ev) tk_render_event_card($ev, $min); ?>
        </div>
      </section>
    <?php endforeach; ?>

    <?php if ($pages > 1): ?>
      <nav class="pager" aria-label="Pagination">
        <?php if ($page > 1): ?><a href="/events/<?= tk_e(tk_qs($qsBase, ['page' => $page - 1 > 1 ? $page - 1 : ''])) ?>" rel="prev">Previous</a><?php endif; ?>
        <?php for ($i = 1; $i <= $pages; $i++): ?>
          <?php if ($i === $page): ?><span aria-current="page"><?= $i ?></span>
          <?php else: ?><a href="/events/<?= tk_e(tk_qs($qsBase, ['page' => $i > 1 ? $i : ''])) ?>" aria-label="Page <?= $i ?>"><?= $i ?></a><?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $pages): ?><a href="/events/<?= tk_e(tk_qs($qsBase, ['page' => $page + 1])) ?>" rel="next">Next</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  </div>
</section>
</div>
<?php require dirname(__DIR__, 2) . '/templates/footer.php'; ?>
