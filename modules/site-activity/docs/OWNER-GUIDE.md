# Owner dashboard guide

Open **Manage events → Activity & Insights** (`/admin/activity/`). Only the owner sees the link, and every page and API re-checks the owner on the server (others get 403, visitors are sent to login). Owner = the first admin account, or admin emails listed in `TK_ACTIVITY_OWNER_EMAILS`.

| Page | Use it to |
|---|---|
| Overview | period picker (today, yesterday, 7 d, 30 d, custom); visitors, sessions, page views, checkout starts, verified purchases, revenue, conversion; activity-over-time chart (hourly for one day, daily otherwise), top pages, buttons/links, sources, searches, categories, devices; funnel; orders picture; revenue check; tracking health |
| Live | active sessions (activity in last N minutes) and latest actions, auto-refreshing |
| Journeys | entry/exit pages, page-to-page moves, common paths, funnel |
| Event interest | per event: views, previous period, card clicks, checkouts, purchases, rates, verified revenue; categories, cities, searches |
| Campaigns | utm_ campaigns with sessions → checkouts → purchases → revenue; landing pages |
| Audiences | save segments (definition + size estimate only; nothing is sent) |
| Activity log | paginated, filterable raw actions (shortened visitor ids, no IPs) |
| Exports & data | CSV exports, run retention, erase analytics for an account, audit log |
| Settings | tracking switch, consent mode, categories, retention, IP mode, proxies, limits |

## How to read the numbers
* **Visitor** = one browser, not a person. **Session** = a run of activity; a new one starts after 30 min idle. **Active now** = activity in the last 5 min (shorter than the session timeout, so "not yet expired" is not shown as "active").
* **Checkout start** = a real order was created. **Purchase** = the server verified the order paid. **Conversion rate** = sessions with a purchase / sessions. Payments are switched off in this build, so purchases stay 0 until a payment layer marks orders paid.
* **Abandonment estimate** = an order older than 24 h that is still unpaid. It says nothing about *why*.
* The funnel counts tracked sessions only; the *Orders in this period* panel counts the orders table regardless of consent, and shows how many orders had no tracked session.
* **Attribution** shows the campaign seen by the session (or the same browser's last tagged session within 30 days) before an order. It is not proof of cause.
* Test traffic (rows flagged with the test token) is excluded unless `?include_test=1`.
