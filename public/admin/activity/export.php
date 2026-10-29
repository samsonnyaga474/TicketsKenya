<?php
/** Owner-only CSV download. POST + CSRF, exports must be enabled, every export is audited. */
require_once dirname(__DIR__, 3) . '/modules/site-activity/adapters/admin-page.php';
use SiteActivity\{Analytics, Authorization, Config, Export, Repository};

$owner = tk_activity_admin_guard(true);
Authorization::postWithCsrf();
if (!Config::bool('exports_enabled')) { http_response_code(403); exit('Exports are switched off in settings.'); }
Analytics::$includeTest = (($_POST['include_test'] ?? '') === '1');
$r = Analytics::range($_POST);
try { $b = Export::build((string)($_POST['type'] ?? ''), $r, ($_POST['include_queries'] ?? '') === '1'); }
catch (InvalidArgumentException $e) { http_response_code(400); exit('Unknown export.'); }
Repository::audit('export', ['type' => $_POST['type'], 'from' => $r['from_date'], 'to' => $r['to_date'], 'rows' => count($b['rows'])], $owner);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $b['filename']) . '"');
header('Cache-Control: no-store');
echo "\xEF\xBB\xBF" . Export::line($b['header']);
foreach ($b['rows'] as $row) echo Export::line($row);
exit;
