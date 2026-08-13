# Deepak Tiles & Granite — Shop Web App

A small single-page shop website: products, blog posts, guest-friendly
likes / comments / shares / star ratings, and a shopkeeper dashboard with
a private engagement feed and reply workflow.

* Frontend: `index.html` + `css/` + `js/`
* Backend: PHP 7.4+ (no framework), PDO + MySQL/MariaDB
* Storage: MySQL for data; the `uploads/` directory for product images

## Run locally on XAMPP

1. Copy the folder to `C:\xampp\htdocs\FARM\`.
2. Start Apache and MySQL from the XAMPP control panel.
3. Open `http://localhost/FARM/index.html`. The schema is created
   automatically on first request — no need to import `farm.sql`.
4. Set the shopkeeper password from a terminal:

   ```powershell
   C:\xampp\php\php.exe api\setup_shopkeeper.php set Deepak YourPasswordHere
   ```

5. Sign in on the site with username `Deepak` and that password.

See [DEPLOY.md](DEPLOY.md) for the production deployment walkthrough and
[PRODUCTION_SETUP.md](PRODUCTION_SETUP.md) for shared/VPS hosting.

## Deploy on Render

The project ships with a `render.yaml` that wires it to Render's PHP
buildpack. Quick path:

1. Provision a free MySQL database somewhere (Render does **not** include
   MySQL — use [Aiven](https://aiven.io/mysql) or [PlanetScale](https://planetscale.com/)).
   Note the host, port, username and password Aiven gives you.
2. Push this repo to GitHub.
3. In Render → **New → Web Service → Connect repo → Environment: PHP** →
   leave the Build Command as `true` and the Start Command as
   `php -S 0.0.0.0:$PORT -t . router.php`.
4. Add env vars: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`.
5. Deploy, then open the Render "Shell" tab and run:

   ```
   php api/setup_shopkeeper.php set Deepak 'YourStrongPassword!'
   ```

Full step-by-step with screenshots-of-the-mind is in
[DEPLOY.md](DEPLOY.md).

## Known limitations on Render free tier

* **Ephemeral disk.** Files uploaded to `uploads/` are wiped on every
  redeploy / instance restart. The site keeps working — products,
  comments, ratings, likes all survive — but uploaded images will
  disappear. The fix is an S3-backed uploader; for now, re-upload after
  each redeploy or upgrade to a paid plan with persistent disk.
* **No MySQL on Render.** You must bring your own database. The free
  Aiven MySQL is enough for a small shop.

## File map

```
index.html              ← single-page app (homepage)
css/style.css            ← all styles
js/script.js             ← all client logic
api/auth.php             ← shopkeeper login
api/blogs.php            ← products + blog CRUD
api/upload.php           ← image uploads
api/interactions.php     ← guest comments / likes / shares / ratings / replies
api/db.php               ← PDO + helpers (sessions, CSRF, rate limit)
api/setup_shopkeeper.php ← CLI to set / rotate shopkeeper password
farm.sql                 ← optional manual schema import
router.php               ← PHP-S router for Render (mimics .htaccess)
render.yaml              ← Render infrastructure-as-code
uploads/                 ← uploaded images (writable, no PHP execution)
```
