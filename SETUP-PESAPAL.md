# SETUP-PESAPAL.md — Complete beginner guide

Do **Part A** in `START-HERE.md` first so the website runs on your computer.  
Then follow this document for payments.

---

## Important ideas in plain language

1. **Hosted payment page** (what you already used)  
   Example: `https://store.pesapal.com/ticketskenya`  
   Customers type an amount and pay. Pesapal records the money. Your website does **not** know which order that payment belongs to.

2. **API integration** (what TicketsKenya needs)  
   Your website creates an order, sends the exact amount and order number to Pesapal, redirects the customer to pay, then **checks with Pesapal’s servers** before issuing a ticket. This is the secure way.

3. **Sandbox**  
   Test mode. Fake or test money. Use this until everything works.

4. **Live**  
   Real money. Turn this on only after sandbox tests pass and you decide to go live.

5. **IPN (Instant Payment Notification)**  
   Pesapal calls a special page on your website when a payment status changes. That page must be reachable on the **public internet over HTTPS**. Your home `localhost` is not public, so IPN will not work until you use a tunnel or real hosting.

---

## B1 — Open your Pesapal merchant account

1. Open your browser.
2. Go to the Pesapal merchant / developer area you used when you created the payment page (often https://www.pesapal.com or the dashboard link from your welcome email).
3. Log in with the same account that owns the “TicketsKenya” payment page.

If you cannot log in, use Pesapal’s support / password reset. Do not share your password in this chat.

---

## B2 — Get sandbox API credentials

Pesapal sends **consumer key** and **consumer secret** for API access. They are different from the hosted payment page password.

### Where to look
1. In the Pesapal dashboard, look for sections named similar to:
   - Developer
   - API
   - Integration
   - API Keys
   - Sandbox / Demo credentials
2. Check the email Pesapal sent when the merchant account was approved — keys are often in that email.

### If you only see the hosted payment page and no API keys
- Contact Pesapal support and ask for **API 3.0 sandbox consumer key and consumer secret** for your merchant account.
- Tell them you need JSON API 3.0 for an e-commerce integration (not only the hosted store page).

### Sandbox vs live
| | Sandbox | Live |
|--|---------|------|
| Money | Test only | Real KES |
| Keys | Separate sandbox key/secret | Separate live key/secret |
| When to use | Now, while testing | Only after tests pass |

**Never put live keys into the website while you are still testing.**

---

## B3 — Create your private `.env` file (safe place for secrets)

Your keys must **not** sit inside the `public` folder or inside the ZIP that other people can download.

### Exact steps on Windows

1. Open **File Explorer**.
2. Go to: `C:\xampp\htdocs\TicketsKenya\`
3. Find the file named `.env.example`
4. Copy it:
   - Right-click `.env.example` → **Copy**
   - Right-click empty space in the same folder → **Paste**
   - Rename the copy to exactly: `.env`  
     (If Windows asks about changing the extension, confirm yes.)

   If you cannot see files that start with a dot:
   - In File Explorer open the **View** tab → enable **Hidden items**.
   - Or open **Command Prompt** and run:

```bat
cd /d C:\xampp\htdocs\TicketsKenya
copy .env.example .env
```

5. Open `.env` with **Notepad** (right-click → Open with → Notepad).
6. Fill in only the values you have. Example shape (use **your** real sandbox values, never share them):

```
TK_ENV=development
TK_APP_URL=http://localhost:8080
TK_DEMO_MODE=true

PESAPAL_ENV=sandbox
PESAPAL_CONSUMER_KEY=paste_your_sandbox_key_here
PESAPAL_CONSUMER_SECRET=paste_your_sandbox_secret_here
PESAPAL_NOTIFICATION_ID=
```

7. Save the file and close Notepad.

### How the website reads `.env`
The project loads `C:\xampp\htdocs\TicketsKenya\.env` automatically when a page runs.  
It does **not** load secrets from the public web folder.  
Apache is configured to block download of `.env` files if they ever appear under `public`.

If keys are missing, the order page shows a clear message that payments are not configured. It will **not** crash with a fatal error for missing keys when you only browse the site.

---

## B4 — Public HTTPS address for IPN (required for full testing)

Pesapal must call:

```
https://YOUR-PUBLIC-DOMAIN/payments/webhook.php
```

`http://localhost:8080` is **not** public. Pesapal’s servers cannot reach it.

### Options for testing

**Option 1 — Temporary tunnel (good for learning)**  
Tools such as Cloudflare Tunnel or ngrok create a temporary public HTTPS URL that points at your local port 8080.

High-level idea (you still follow the tunnel tool’s own install guide):
1. Install the tunnel tool.
2. Point it at `localhost:8080`.
3. It gives you a URL like `https://something.trycloudflare.com`
4. Use that URL as `TK_APP_URL` and for IPN registration.

**Option 2 — Real hosting**  
Upload the project to a host that gives you HTTPS (for example a VPS or shared hosting with PHP and the document root set to the `public` folder). Then use your real domain.

Until you have a public HTTPS URL, you can still:
- Run the website locally
- Create orders
- See that the Pay button appears once keys are in `.env`
- But the full “pay → callback → ticket” loop needs the public URL

---

## B5 — Register the IPN and get the notification ID

You only need to do this once per environment (sandbox and later live).

### Preconditions
- `.env` has valid `PESAPAL_CONSUMER_KEY` and `PESAPAL_CONSUMER_SECRET`
- You have a public HTTPS URL that reaches your site

### Exact Windows commands

1. Open **Command Prompt**.
2. Go to the project folder:

```bat
cd /d C:\xampp\htdocs\TicketsKenya
```

3. Run the registration tool (replace the URL with **your** public HTTPS URL):

```bat
C:\xampp\php\php.exe tools\register-pesapal-ipn.php https://YOUR-PUBLIC-DOMAIN/payments/webhook.php
```

### What success looks like
The tool prints JSON that includes a line similar to:

```
"ipn_id": "84740ab4-3cd9-47da-8a4f-dd1db53494b5"
```

and a message:

```
SUCCESS. Set this in your environment:
PESAPAL_NOTIFICATION_ID=84740ab4-3cd9-47da-8a4f-dd1db53494b5
```

### What to do with that value
1. Open `C:\xampp\htdocs\TicketsKenya\.env` in Notepad again.
2. Set:

```
PESAPAL_NOTIFICATION_ID=84740ab4-3cd9-47da-8a4f-dd1db53494b5
```

(use the real id you received)

3. Also set your public URL:

```
TK_APP_URL=https://YOUR-PUBLIC-DOMAIN
```

4. Save the file.
5. Restart the PHP server (close the Command Prompt window that is running the server, then start it again with the same `php -S` command).

### If the command fails
| Message | Meaning | What to do |
|---------|---------|------------|
| Set PESAPAL_CONSUMER_KEY… | Keys missing from `.env` | Fix `.env`, save, try again |
| Pesapal authentication failed | Wrong key/secret or wrong environment | Check dashboard email / support for correct sandbox keys |
| Network error | No internet or Pesapal down | Check internet, try later |
| Usage: php tools\… | You forgot the HTTPS URL | Add the full `https://…/payments/webhook.php` argument |

---

## B6 — Test the full payment path (sandbox only)

1. Make sure the PHP server is running.
2. Open `http://localhost:8080` (or your tunnel URL).
3. Log in as the demo customer (or your own account).
4. Open an event that sells tickets on TicketsKenya (**owned** inventory, not a discovery-only listing).
5. Choose quantity → create order.
6. On the order page you should see **Pay KES … with Pesapal**.
7. Click it. You should be redirected to Pesapal’s payment page.
8. Complete a **sandbox** payment (not real money).
9. You should return to your order page.
10. After a short wait (and after IPN arrives), the order should show as paid and ticket codes should appear.

### Extra checks
- Refresh the order page or open:  
  `http://localhost:8080/payments/status.php?order_id=YOUR_ORDER_ID`
- Confirm a second refresh does **not** create extra tickets.
- Confirm a failed or cancelled payment does **not** show tickets.

---

## B7 — Move to live mode (only when you are ready)

1. Get **live** consumer key and secret from Pesapal (separate from sandbox).
2. Register a **live** IPN URL the same way (live base is used when `PESAPAL_ENV=live`).
3. In `.env` set:

```
PESAPAL_ENV=live
PESAPAL_CONSUMER_KEY=your_live_key
PESAPAL_CONSUMER_SECRET=your_live_secret
PESAPAL_NOTIFICATION_ID=your_live_ipn_id
TK_ENV=production
TK_DEMO_MODE=false
TK_APP_URL=https://your-real-domain
```

4. Restart Apache / PHP.
5. Do a very small real test only when you accept the risk of real money movement.

---

## Checklist (mark as you go)

| Step | Status |
|------|--------|
| Website extracted to `C:\xampp\htdocs\TicketsKenya\` | Not started |
| Homepage opens at http://localhost:8080 | Not started |
| Login works | Not started |
| Sandbox consumer key + secret obtained from Pesapal | Blocked by Pesapal/account |
| `.env` created and filled (no secrets shared) | Not started |
| Public HTTPS URL available for IPN | Blocked by deployment |
| IPN registered; `PESAPAL_NOTIFICATION_ID` set | Blocked by deployment |
| Sandbox payment completed and ticket issued | Blocked by Pesapal + deployment |
| Failed / duplicate notifications do not issue extra tickets | Blocked by Pesapal + deployment |
| Live mode enabled | Not started (do this last) |

---

## Official Pesapal documentation

- Authentication: https://developer.pesapal.com/how-to-integrate/e-commerce/api-30-json/authentication  
- Register IPN: https://developer.pesapal.com/how-to-integrate/e-commerce/api-30-json/registeripnurl  
- Submit order: https://developer.pesapal.com/how-to-integrate/e-commerce/api-30-json/submitorderrequest  
- Get status: https://developer.pesapal.com/how-to-integrate/e-commerce/api-30-json/gettransactionstatus  

If anything in the Pesapal dashboard looks different from these instructions, trust the official dashboard and the docs above, and use the values they give you.
