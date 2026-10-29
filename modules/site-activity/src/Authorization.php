<?php
declare(strict_types=1);
namespace SiteActivity;

/** Server-side gate for every protected page and API. Hiding a link is never treated as security. */
final class Authorization
{
    /** Pages: not logged in -> login page; logged in but not owner -> plain 403 with no data. Returns the owner. */
    public static function ownerPage(): array
    {
        $u = Module::host()->currentUser();
        if (!$u) { header('Location: ' . Module::host()->loginUrl(), true, 302); exit; }
        if (!Module::host()->isOwner($u)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'You do not have access to this area.';
            exit;
        }
        return $u;
    }

    /** JSON APIs: 401 / 403 with a generic body. */
    public static function ownerApi(): array
    {
        $u = Module::host()->currentUser();
        if (!$u || !Module::host()->isOwner($u)) {
            http_response_code($u ? 403 : 401);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(['ok' => false, 'error' => $u ? 'forbidden' : 'login_required']);
            exit;
        }
        return $u;
    }

    /** State-changing requests: POST only, valid CSRF token. */
    public static function postWithCsrf(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit('Method not allowed'); }
        $t = $_POST['_csrf'] ?? null;
        if (!Module::host()->verifyCsrf(is_string($t) ? $t : null)) { http_response_code(403); exit('Invalid security token.'); }
    }
}
