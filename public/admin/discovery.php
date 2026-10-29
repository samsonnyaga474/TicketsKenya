<?php
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
tk_require_role('admin');

$pageTitle = 'Event discovery tracking | TicketsKenya';
$noindex = true;
$sourceConfig = json_decode((string)@file_get_contents(TK_ROOT . '/tools/discovery-sources.json'), true);
$history = json_decode((string)@file_get_contents(TK_DATA . '/discovery-sync-log.json'), true);
if (!is_array($history)) $history = [];
if (!is_array($sourceConfig)) $sourceConfig = ['sources'=>[]];
$discoveryEvents = array_values(array_filter(tk_repo_all('events'), fn($e) => ($e['listing_type'] ?? '') === 'discovery'));
$upcoming = array_values(array_filter($discoveryEvents, fn($e) => in_array($e['status'] ?? '', ['published','sold_out'], true) && strtotime((string)($e['starts_at'] ?? '')) >= time()));
require dirname(__DIR__, 2) . '/templates/header.php';
?>
<div class="section-inner">
  <div class="page-hero">
    <h1>Event discovery tracking</h1>
    <p>Review the configured public feeds, recent sync results and changes. This page reports sync activity; it does not run a feed sync from a browser request.</p>
  </div>
  <p style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem;">
    <a class="btn btn-outline" href="/admin/events.php">Manage events</a>
    <a class="btn btn-outline" href="/admin/">Dashboard</a>
  </p>
  <div class="tk-stats" style="margin-bottom:2rem;">
    <div class="tk-stat"><small>Discovery listings</small><strong><?= count($discoveryEvents) ?></strong><span>Imported or seeded</span></div>
    <div class="tk-stat"><small>Upcoming discovery listings</small><strong><?= count($upcoming) ?></strong><span>Published and future-dated</span></div>
    <div class="tk-stat"><small>Configured feeds</small><strong><?= count(array_filter($sourceConfig['sources'] ?? [], fn($s) => !empty($s['enabled']))) ?></strong><span>Public feeds enabled</span></div>
    <div class="tk-stat"><small>Last sync</small><strong style="font-size:1.2rem;"><?= !empty($history[0]['ran_at']) ? tk_e(date('j M H:i', strtotime($history[0]['ran_at']))) : 'Not run' ?></strong><span>Africa/Nairobi time</span></div>
  </div>

  <h2>Configured sources</h2>
  <?php if (empty($sourceConfig['sources'])): ?><p>No feeds are configured.</p><?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>Source</th><th>Feed URL</th><th>Format</th><th>Enabled</th><th>Notes</th></tr></thead><tbody>
    <?php foreach ($sourceConfig['sources'] as $source): ?>
      <tr><td><?= tk_e((string)($source['name'] ?? $source['id'] ?? 'Feed')) ?></td><td><a href="<?= tk_e((string)($source['url'] ?? '#')) ?>" target="_blank" rel="noopener noreferrer"><?= tk_e((string)($source['url'] ?? '')) ?></a></td><td><?= tk_e((string)($source['format'] ?? 'auto')) ?></td><td><?= !empty($source['enabled']) ? 'Enabled' : 'Disabled' ?></td><td><?= tk_e((string)($source['disabled_reason'] ?? '')) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>

  <h2 style="margin-top:2rem;">Recent sync history</h2>
  <?php if (!$history): ?><div class="empty-state"><p>No sync history exists yet. Run <code>php tools/sync-discovery.php</code> from the project root, then refresh this page.</p></div><?php else: ?>
    <?php foreach (array_slice($history, 0, 20) as $entry): $result = is_array($entry['result'] ?? null) ? $entry['result'] : []; ?>
      <article class="event-card" style="padding:1rem;margin:0 0 1rem;">
        <h3><?= tk_e(!empty($entry['ran_at']) ? date('D, j M Y H:i:s', strtotime($entry['ran_at'])) : 'Sync run') ?></h3>
        <p class="meta">Sources: <?= (int)($result['sources'] ?? 0) ?> · Created: <?= (int)($result['created'] ?? 0) ?> · Updated: <?= (int)($result['updated'] ?? 0) ?> · Unchanged: <?= (int)($result['unchanged'] ?? 0) ?> · Skipped: <?= (int)($result['skipped'] ?? 0) ?></p>
        <?php if (!empty($result['errors'])): ?><ul><?php foreach ($result['errors'] as $error): ?><li><?= tk_e((string)$error) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php if (!empty($result['changes'])): ?><details><summary>View <?= count($result['changes']) ?> recorded change(s)</summary><ul><?php foreach (array_slice($result['changes'], 0, 50) as $change): ?><li><strong><?= tk_e((string)($change['title'] ?? 'Event')) ?></strong>: <?= tk_e(implode(', ', (array)($change['fields'] ?? []))) ?> <span class="meta">(<?= tk_e((string)($change['source'] ?? 'feed')) ?>)</span></li><?php endforeach; ?></ul></details><?php endif; ?>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>
  <p class="meta">A feed failure does not mean there are no events. The synchronizer never deletes an event merely because it disappears from a feed and does not invent missing dates or prices.</p>
</div>
<?php require dirname(__DIR__, 2) . '/templates/footer.php'; ?>
