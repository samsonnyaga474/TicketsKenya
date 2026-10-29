# Using the module in another PHP project

Requirements: PHP 8.1+, `pdo_sqlite` (the SQL in `src/Schema.php` is SQLite; a MySQL port is **not** implemented), a database connection, an authentication system, a CSRF token system. Optional: nothing else. No Composer packages.

1. Copy `modules/site-activity/src/`, `public/assets/js/activity*.js`, `public/assets/css/activity.css` (restyle with your own tokens) and the `public/activity/*.php` endpoints.
2. Write an adapter implementing `SiteActivity\HostAdapter` (copy `adapters/ticketskenya.php` and replace its internals):
   `pdo()`, `currentUser()`, `isOwner($user)`, `verifyCsrf()/csrfToken()`, `describePath($path)` (map your public URLs to `path_group`, a content id and a category), `eventLabels($ids)`, `paidOrders()`, `ordersCreatedBetween()`, `paidCustomerUserIds()`, `basePath()`, `loginUrl()`. Everything shop-specific lives there; if your site sells nothing, return empty arrays.
3. Boot once per request: `Module::boot($adapter, $configArray); if (!Module::installed()) Module::install();` (see `tk_activity_boot()`).
4. Print the banner + tracker once in your global footer (see `tk_activity_footer()`); the script URL, endpoints and CSRF token travel as `data-` attributes so no inline script is needed (works with `script-src 'self'`).
5. After your real order/booking is created call `Conversions::checkoutStarted()`; to record sales run `Conversions::syncPurchases()` (dashboard pages do it automatically; schedule `tools/activity-maintenance.php sync` if you want it without visiting).
6. Copy `public/admin/activity/*.php` and `adapters/admin-page.php`, pointing the page frame at your header/footer; protect with `Authorization::ownerPage()`.
7. Copy `config/site-activity.example.php` if you want different starting values. Choose a unique `project_id` per site.
8. Run `tests/test_activity.py` against a disposable copy.

Project-specific rules (owner definition, URL patterns, order status words) belong only in the adapter. Do not add shop logic to `src/`.
