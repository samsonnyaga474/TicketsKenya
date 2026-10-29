<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
http_response_code(404);
tk_page_open('Page not found | TicketsKenya', 'The page you asked for does not exist.', 'Page not found', 'The page may have moved or the link may be wrong.', ['noindex' => true]);
?>
<p><a href="/" class="btn btn-solid">Go home</a> <a href="/events/" class="btn btn-outline">Browse events</a></p>
<?php tk_page_close();
