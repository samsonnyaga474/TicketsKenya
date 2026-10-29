# START HERE — TicketsKenya (beginner guide)

Welcome. Follow these steps **in order**. Do not skip ahead.

This project is a Kenyan event-ticketing website. Payments use **Pesapal**. Until you add Pesapal credentials, the site still works for browsing events, creating accounts, and placing orders — it just will not charge money or issue paid tickets.

---

## Part A — Install the website on your computer

### What you need
- Windows 11
- XAMPP already installed (with PHP and Apache)
- This ZIP file

### Step 1 — Get the ZIP
1. Download the ZIP from the link provided in the chat.
2. Save it somewhere easy to find, for example your **Downloads** folder.

### Step 2 — Extract the ZIP into the correct place
1. Open **File Explorer**.
2. Go to: `C:\xampp\htdocs\`
3. If a folder named `TicketsKenya` already exists there and you want a clean install, rename the old one to `TicketsKenya-old` first.
4. Right-click the ZIP file → **Extract All…**
5. Choose the destination: `C:\xampp\htdocs\`
6. Click **Extract**.

### Step 3 — Confirm the folder is correct
After extracting you must have exactly this path:

```
C:\xampp\htdocs\TicketsKenya\
```

Inside that folder you should see at least:
- `public`
- `config`
- `includes`
- `START-HERE.md`
- `.env.example`

If you see something like `C:\xampp\htdocs\TicketsKenya\TicketsKenya\`, you extracted one level too deep. Move the inner folder up so the structure matches the path above.

### Step 4 — Start XAMPP
1. Open the **XAMPP Control Panel**.
2. Start **Apache**.
3. You do **not** need MySQL for this project.

### Step 5 — Open the site in your browser
**Easiest method (recommended for beginners) — PHP built-in server:**

1. Open **Command Prompt** (search for `cmd` in the Start menu).
2. Copy and paste this command, then press Enter:

```bat
cd /d C:\xampp\htdocs\TicketsKenya\public
```

3. Then copy and paste this command and press Enter:

```bat
C:\xampp\php\php.exe -S localhost:8080 router.php
```

4. Leave that window open.
5. Open your browser and go to:

```
http://localhost:8080
```

You should see the TicketsKenya homepage.

**Alternative (Apache VirtualHost):** point DocumentRoot to `C:/xampp/htdocs/TicketsKenya/public`. This is more advanced; use the built-in server first.

### Step 6 — Database (no MySQL needed)
This project uses **SQLite** automatically (a single file database).  
If SQLite is not available in PHP, it falls back to a simple JSON file store.

You do **not** need to create a MySQL database or run SQL install scripts by hand.  
The first time a page loads, the app creates the tables and demo data itself.

### Step 7 — Check that basic pages work
Open these in your browser (while the PHP server is running):

| Page | URL | What you should see |
|------|-----|---------------------|
| Home | http://localhost:8080/ | TicketsKenya homepage |
| Events | http://localhost:8080/events/ | List of events |
| Login | http://localhost:8080/auth/login.php | Login form |
| Register | http://localhost:8080/auth/register.php | Create account form |

Demo accounts (only when `TK_DEMO_MODE=true`, which is the default for local development):

- Customer: `demo.customer@ticketskenya.local` / `DemoPass123!`
- Admin: `demo.admin@ticketskenya.local` / `DemoPass123!`

**Never use demo accounts on a public website.**

### Step 8 — Common problems and fixes

| Problem | Fix |
|---------|-----|
| “Page not found” or blank white page | Confirm you ran the server from the `public` folder and the URL is `http://localhost:8080` |
| Port 8080 already in use | Stop the other program, or use `localhost:8081` and change the command to `-S localhost:8081 router.php` |
| “PHP is not recognized” | Use the full path: `C:\xampp\php\php.exe` as shown above |
| Folder looks nested (TicketsKenya\TicketsKenya) | Move the inner folder so only one `TicketsKenya` sits under `htdocs` |
| Payment button says “not configured” | Normal until you finish Part B |

When the homepage and login work, go to **Part B** in `SETUP-PESAPAL.md` (or the same section below).

---

## Part B — Pesapal (summary)

Payments stay **off** until you add credentials. That is intentional and safe.

Full step-by-step instructions (including how to get keys, register the IPN, and test) are in:

**`SETUP-PESAPAL.md`**

You must do these steps yourself because they need **your** Pesapal merchant account:
1. Get sandbox consumer key and consumer secret from Pesapal.
2. Put them in a private `.env` file (instructions in SETUP-PESAPAL.md).
3. Provide a public HTTPS address for the payment notification (IPN) URL.
4. Run the registration command to receive a notification ID.
5. Test with sandbox only — never real money until you are ready.

---

## Files that help you

| File | Purpose |
|------|---------|
| `START-HERE.md` | This file — install the website |
| `SETUP-PESAPAL.md` | Detailed Pesapal setup |
| `PESAPAL-INTEGRATION-REPORT.md` | What was built, tests, security notes |
| `.env.example` | Template for your private settings (copy to `.env`) |

---

## Security reminders (short)

- Never put real passwords or Pesapal keys inside the ZIP, screenshots, or public GitHub.
- The `.env` file lives in `C:\xampp\htdocs\TicketsKenya\.env` (project root), **not** inside `public`.
- Do not share demo passwords on a live site.
- Switch to live Pesapal only after sandbox tests pass and you explicitly choose to go live.
