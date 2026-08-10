# Depak Tiles & Granite — Setup & Deployment Guide

A small tiles-and-granite shop web app: products, blogs, guest-friendly likes /
comments / shares, a public star-rating + review system, and a shopkeeper
dashboard with a private engagement feed + reply workflow. Built as a PHP
API + single-page HTML/JS front-end.

```
spider.html       ← single-page app (the homepage)
css/style.css     ← all styles
js/script.js      ← all client logic
api/auth.php      ← shopkeeper login (visitors do NOT need accounts)
api/blogs.php     ← products + blog CRUD
api/upload.php    ← image uploads
api/interactions.php ← guest comments / likes / shares / ratings / replies
api/db.php        ← PDO + helpers (sessions, CSRF, rate limit)
api/setup_shopkeeper.php ← CLI to set / rotate shopkeeper password
farm.sql          ← optional manual schema import
uploads/          ← uploaded images (writable, no PHP execution)
```

## 1. First-time setup (XAMPP / Apache)

1. Copy the project folder into `C:\xampp\htdocs\FARM\` (or any path under
   `htdocs`).
2. Start Apache and MySQL from the XAMPP control panel.
3. Open phpMyAdmin and import `farm.sql` (or skip this — `api/db.php` will
   create the schema on first request).
4. Set (or rotate) the shopkeeper password. The default username is
   `Deepak` (defined in `api/db.php` as `SHOP_USERNAME`):

   ```powershell
   cd C:\xampp\htdocs\FARM
   C:\xampp\php\php.exe api\setup_shopkeeper.php set Deepak Deepak@123
   ```

   Or pick any other password you like — the plaintext is bcrypt-hashed and
   never written to disk or to the database.

5. Visit `http://localhost/FARM/spider.html` (or just
   `http://localhost/FARM/` thanks to the `DirectoryIndex` in `.htaccess`).

Visitors do **not** need to sign up to do anything on the site — see
section 5 ("Guest interactions and ratings") below.

## 2. Storing your data permanently

Everything that should "live forever" lives in MySQL:

| What's stored                       | Table                                |
|-------------------------------------|--------------------------------------|
| Legacy regular user accounts        | `users` (kept for backward compat; no longer required) |
| Shopkeeper password                 | `shopkeeper_credentials` (bcrypt hash) |
| Shop details                        | `shop_profile` (single row — id 1)   |
| Products                            | `products` (`is_blog = 0`)           |
| Blog posts                          | `products` (`is_blog = 1`)           |
| Comments (guest-friendly)           | `comments` (+ `guest_name`, `guest_email_hash`) |
| Likes (guest-friendly)              | `likes` (+ `guest_email_hash`)        |
| Shares                              | `shares` (+ `guest_email_hash`)       |
| Star ratings / reviews              | `ratings` (`product_id` NULL = shop-wide) |
| Shopkeeper replies                  | `review_replies` (`target_kind` = `comment` or `rating`) |
| Uploaded images                     | `uploads/` directory + path in `products.image_path` |

The schema is auto-created on first API call, so you don't need to import
`farm.sql` manually. If you want to seed the database with sample data,
import `farm.sql` after step 4.

### Backups

To back up the entire site, copy the project folder AND dump the database:

```powershell
C:\xampp\mysql\bin\mysqldump.exe -u root -p farm_db > farm_backup.sql
```

Restoring is just importing the SQL file and copying the folder back.

## 3. Rotating the shopkeeper password

```powershell
C:\xampp\php\php.exe api\setup_shopkeeper.php set Deepak NewPassword456!
```

Or generate a random one (printed once on the terminal):

```powershell
C:\xampp\php\php.exe api\setup_shopkeeper.php rotate
```

View current status:

```powershell
C:\xampp\php\php.exe api\setup_shopkeeper.php status
```

## 4. Security features included

- **No plaintext credentials anywhere.** The shopkeeper password is stored
  as a bcrypt hash. The login form on the website contains no placeholder
  hint. View source on the public page — there is no credential visible.
- **CSRF protection.** Every POST/PUT/DELETE checks the `X-CSRF-Token`
  header. The JavaScript SPA fetches a token on boot and refreshes it after
  login.
- **Shopkeeper login is never locked out.** Wrong passwords return `401`
  immediately and the shopkeeper can try again right away — no IP
  rate-limit, no account lockout. The shopkeeper usually signs in from the
  same network/IP as the shop's visitors, so locking the IP would block
  them too. Brute-force protection is delegated to bcrypt's slow hash.
- **Session security.** `HttpOnly`, `SameSite=Lax`, `Secure` (when on HTTPS),
  custom session cookie name (`SPIDER_SESSID` — internal, safe to rename),
  and `session_regenerate_id` on every login.
- **Image upload validation.** MIME check + `getimagesize` re-validation +
  extension whitelist + content-type-driven extension. SVGs are rejected.
- **Security headers.** `X-Content-Type-Options: nosniff`,
  `Referrer-Policy: same-origin`, `X-Frame-Options: DENY`.

## 5. Guest interactions and ratings

Visitors do not need to sign up or log in to like, comment on, share, or
rate anything on the site. To leave any form of feedback they only have to
provide a **display name** and an **email**.

- The browser keeps the name + email in `localStorage` (key
  `spider_guest_v1`) so the visitor types them once and never again.
- The server hashes the email with SHA-256 and stores **only the hash**.
  The raw email never reaches the database.
- The hash is used as the visitor's identity for matching likes to the
  same visitor, so the like counter doesn't double when the same visitor
  hits "Like" twice.
- A hidden honeypot field on the rating and comment forms rejects basic
  bots.

### Privacy model for reviews

The public site **never** exposes individual review bodies or reviewer
names to other visitors. Only the aggregate (count + average) is shown on
the public shop profile.

A review body is shown only to:

1. **The visitor who wrote it** — they see it (and any reply from the
   shop) by opening the "My Reviews" page and entering the email they
   used when they rated. The server hashes that email, matches it against
   the stored hash, and returns only the matching rows.
2. **The shop owner** — via the **Engagement** tab in the shopkeeper
   dashboard.

Anything else (likes, share counts, comments) is public as before.

### Shopkeeper workflow: the Engagement tab

After signing in, the shopkeeper dashboard has a fourth tab called
**Engagement**. It shows three quick metric tiles at the top:

- Shop rating (average star value + review count, shop-wide)
- Total comments across all products
- Total likes across all products

Below the tiles is a unified feed that lists every rating and every
comment, newest first. Each item has:

- A `Reply` text box. Submitting sends a professional reply attributed to
  the shop name; it appears inline under the visitor's feedback.
- For comments, a `Delete` button that removes the comment and any
  associated replies (use it for spam).

There's also a filter dropdown at the top right of the tab so you can
show only ratings or only comments.

## 6. Publishing the site

For a public deployment:

1. **HTTPS only.** Use Let's Encrypt or your hosting provider's free TLS.
   The `Secure` cookie flag will then take effect automatically.
2. **Production PHP settings.** In `php.ini`:
   - `expose_php = Off`
   - `display_errors = Off`
   - `log_errors = On`
3. **Database credentials.** Edit `api/db.php` `DB_USER` / `DB_PASS` to a
   dedicated MySQL user, not `root`.
4. **File permissions.** `uploads/` must be writable by the web server user
   but not executable. The included `.htaccess` already blocks PHP execution
   inside `uploads/`.
5. **Don't expose `api/setup_shopkeeper.php` to the web.** It already
   refuses HTTP requests, but if you serve Apache with restrictive config
   you can move it outside the document root.
6. **Add a real OG image.** Replace `uploads/og-default.png` with a 1200×630
   brand image for nice social previews.

### Optional Apache hardening (top-level `.htaccess`)

The included `.htaccess` already sets:

```apache
DirectoryIndex spider.html

<IfModule mod_headers.c>
  Header always set X-Frame-Options "DENY"
  Header always set X-Content-Type-Options "nosniff"
  Header always set Referrer-Policy "strict-origin-when-cross-origin"
  Header always set Permissions-Policy "geolocation=(), microphone=(), camera=()"
</IfModule>

<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteCond %{HTTP_HOST} !^localhost
  RewriteCond %{HTTP_HOST} !^127\.0\.0\.1
  RewriteCond %{HTTPS} !=on
  RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
</IfModule>
```

The HTTPS redirect is skipped for `localhost` / `127.0.0.1` so dev works
over plain HTTP.

## 7. Troubleshooting

- **"Shopkeeper has not been set up yet"** — run
  `php api\setup_shopkeeper.php set Deepak <your-password>`.
- **"CSRF token missing or invalid"** — refresh the page and log in again.
- **"Invalid shopkeeper username or password"** — the shopkeeper login is
  never rate-limited or locked out, so this is always a real credential
  mismatch. Re-enter the password, or reset it from the CLI:
  `php api\setup_shopkeeper.php set Deepak <new-password>`.
- **Database connection refused** — confirm MariaDB is listening on the
  port in `api/db.php` (`DB_PORT`). Verify with
  `netstat -ano | Select-String ":3306|:3307"`.
- **Images not loading** — check `uploads/` is writable and that the
  `uploads/.htaccess` file is intact.
- **500 errors** — enable PHP error logging, check Apache's `error.log`.

## 8. Customization

- **Shop name & branding**: shopkeeper dashboard → "Shop details" tab.
  This updates the navbar, hero, footer, page title, and Open Graph tags.
  The first-time default is "Depak Tiles & Granite" (set in
  `api/db.php` → `ensure_schema`).
- **Login username**: change `SHOP_USERNAME` in `api/db.php` and rotate
  the password with `php api\setup_shopkeeper.php set <user> <pw>`.
- **Colors**: edit the CSS variables at the top of `css/style.css`:
  ```css
  :root {
    --shop-accent: #c8102e;
    --shop-accent-dark: #a30d24;
  }
  ```
- **Products per page**: edit the `LIMIT 200` in `api/blogs.php` functions.
- **Rate-limit thresholds**: edit `rate_limit_check()` calls in `api/auth.php`.