# Rollback and removal

**Switch off (reversible, keeps data):** Settings → untick *Tracking on*. The banner and script disappear, nothing new is stored, the site works as before.

**Remove the integration (code):** restore the original versions of the four edited files (they are tiny): in `templates/footer.php` delete the `Privacy settings` list item and the `tk_activity_footer()` line; in `public/events/view.php` delete the `tk_activity_checkout_started(...)` line and its `require_once`; in `public/admin/index.php` delete the Activity & Insights button; in `public/cookies.php` restore the original "What we do not set" text. Then delete `modules/site-activity/`, `public/activity/`, `public/admin/activity/`, `public/privacy-settings.php`, `public/assets/js/activity*.js`, `public/assets/css/activity.css`, `tools/activity-maintenance.php`.

**Remove the data:** `C:\xampp\php\php.exe tools\activity-maintenance.php uninstall --yes` (drops only tables starting with `activity_`). Orders, tickets, users, events and payment records are never touched. Take a copy of `data\ticketskenya.sqlite` first if you want a safety net.

The site does not need the module: with the activity tables absent or broken, pages, login and checkout keep working (verified in `test_resilience.py`).
