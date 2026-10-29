# TicketsKenya implementation notes — 2026-10-24

## Schema fix (critical for your XAMPP error)
- Ticket stock uses `quantity_total` + `quantity_sold` (not `quantity_available`).
- Import and ensure tools updated.
- Checkout increments `quantity_sold`.
- Migration adds `subtotal_kes`, `fee_kes`, `currency` on orders when missing.

## Ownership
- All events are **owned**; sales stay on TicketsKenya.
- Support email: ticketskenyasupport@gmail.com

## Design components (PHP/CSS/JS, no React required)
- **Tactile buttons**: physical shadow + press on `.btn-solid` / `.btn-tactile`.
- **Ticket component**: `.tk-ticket` body + yellow stub for issued tickets.
- **Receipt printer**: order page stages from real status only (`processing` / `printing` / `complete` / `failed`). Never invents paid success.
- **Hero video**: uses `public/assets/images/hero.mp4` if present; otherwise poster image `hero-bg.jpg`.
- **Contact**: character counter, double-submit guard, support email shown.
- **Event cards**: hover lift + image zoom; reduced-motion respected.

## Payments
- Still fail-closed. Orders stay pending until provider verification is enabled with real credentials.

## How to run
```bat
cd C:\xampp\htdocs\TicketsKenya
C:\xampp\php\php.exe tools\import-discovery.php
C:\xampp\php\php.exe tools\ensure-owned-inventory.php
cd public
C:\xampp\php\php.exe -S localhost:8080 router.php
```

Optional: place a compressed MP4 at `public/assets/images/hero.mp4` for the hero video background.
