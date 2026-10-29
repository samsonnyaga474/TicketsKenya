<?php
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
// Logging out changes state, so it needs a POST with a valid CSRF token (a plain link or image tag cannot log someone out).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && tk_verify_csrf(is_string($_POST[TK_CSRF_TOKEN_NAME] ?? null) ? $_POST[TK_CSRF_TOKEN_NAME] : null)) {
    tk_logout();
}
tk_redirect('/');
