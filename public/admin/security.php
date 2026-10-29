<?php
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
$admin = tk_require_role('admin');
tk_security_prune();
$cutoff = gmdate('Y-m-d H:i:s', time() - 86400);
$db = tk_db();
if ($db) {
    $counts = $db->prepare('SELECT event_kind, COUNT(*) AS n FROM security_events WHERE occurred_at >= ? GROUP BY event_kind ORDER BY n DESC');
    $counts->execute([$cutoff]);
    $summary = $counts->fetchAll(PDO::FETCH_ASSOC);
    $recent = $db->query('SELECT event_kind, ip_prefix, occurred_at FROM security_events ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
} else {
    $events = tk_json_load()['security_events'] ?? [];
    $recent = array_slice(array_reverse($events), 0, 100);
    $tally = [];
    foreach ($events as $event) if (strcmp((string)$event['occurred_at'], $cutoff) >= 0) $tally[$event['event_kind']] = ($tally[$event['event_kind']] ?? 0) + 1;
    arsort($tally);
    $summary = array_map(fn($kind, $n) => ['event_kind' => $kind, 'n' => $n], array_keys($tally), array_values($tally));
}
$labels = [
    'login_failed' => 'Failed login', 'login_succeeded' => 'Successful login',
    'login_rate_limited' => 'Login rate limit', 'login_csrf_rejected' => 'Login CSRF rejected',
    'activity_rate_limited' => 'Activity endpoint rate limit', 'suspicious_request' => 'Suspicious request',
];
$pageTitle = 'Security activity | TicketsKenya';
$noindex = true;
require dirname(__DIR__, 2) . '/templates/header.php';
?>
<section class="pg-hero"><div class="pg-hero-inner"><h1>Security activity</h1><p>Recent authentication and request signals for administrators.</p></div></section>
<main class="pg-wrap">
  <div class="callout"><strong>Privacy:</strong> this feed does not store submitted email addresses or full URLs. Network addresses are shortened to IPv4 /24 or IPv6 /48 prefixes and records expire after 30 days. Full login-attempt data used for rate limiting is pruned after 24 hours. This is an alerting aid, not a complete intrusion-detection system.</div>
  <section class="panel" aria-labelledby="security-summary-title"><h2 id="security-summary-title">Last 24 hours</h2>
    <?php if (!$summary): ?><p>No security events recorded in the last 24 hours.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Signal</th><th>Count</th></tr></thead><tbody><?php foreach ($summary as $row): ?><tr><td><?= tk_e($labels[$row['event_kind']] ?? 'Other') ?></td><td><?= (int)$row['n'] ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  </section>
  <section class="panel" aria-labelledby="security-recent-title"><h2 id="security-recent-title">Recent signals</h2>
    <?php if (!$recent): ?><p>No security activity recorded yet.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Time (local)</th><th>Signal</th><th>Network prefix</th></tr></thead><tbody><?php foreach ($recent as $row): ?><tr><td><?= tk_e(date('j M Y H:i', strtotime((string)$row['occurred_at'] . ' UTC'))) ?></td><td><?= tk_e($labels[$row['event_kind']] ?? 'Other') ?></td><td><?= tk_e($row['ip_prefix'] ?: 'Unavailable') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  </section>
</main>
<?php require dirname(__DIR__, 2) . '/templates/footer.php'; ?>
