# Deploying to Render

This is the step-by-step for putting the FARM shop live on
[Render](https://render.com) using the free tier.

The project is configured to deploy via `render.yaml` (PHP buildpack,
start command `php -S 0.0.0.0:$PORT -t . router.php`). Everything below
can also be done through the Render dashboard without the YAML file.

---

## 1. Get a free MySQL database

Render's free Web Services do **not** include a MySQL server. Use any
managed MySQL provider — these have a free tier:

* **Aiven MySQL** — https://aiven.io/mysql (recommended; the most
  generous free tier and the easiest to set up)
* **PlanetScale** — https://planetscale.com (free Hobby tier, but the
  schema-creation queries need the `utf8mb4_0900_ai_ci` collation
  instead of `utf8mb4_unicode_ci`; tweak `api/db.php:ensure_schema()` if
  you go this route)
* **Railway** — https://railway.app ($5 trial credit; no permanent free
  MySQL)

### Aiven step-by-step

1. Sign up at https://aiven.io (GitHub login works).
2. Click **Create service → MySQL**.
3. Pick the **Free** plan, the closest region to your Render region
   (latency matters), and a name like `farm-db`.
4. After ~2 minutes, Aiven shows your service. Open it and copy from
   the "Connection information" panel:
   * **Host** (`mysql-xxxxx.a.aivencloud.com`)
   * **Port** (`12345` etc — Aiven uses a non-default port)
   * **User** (`avnadmin`)
   * **Password** (click "Show" to reveal)
5. Under **Settings → Allowed IP addresses**, add `0.0.0.0/0` so Render
   can reach it. (For a production deploy you'd lock this down to
   Render's outbound IPs, but they rotate, so `0.0.0.0/0` is the
   pragmatic free-tier choice.)
6. Open Aiven's **Service URI** link to launch phpMyAdmin, or use any
   MySQL client, and run:

   ```sql
   CREATE DATABASE IF NOT EXISTS farm_db
     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

   The `ensure_schema()` function in `api/db.php` would also create the
   database automatically, but most managed MySQL providers require you
   to create the database yourself before PDO can `USE` it.

---

## 2. Push the repo to GitHub

The repo should already be on GitHub from the earlier deploy attempt.
Push the new files we just added (`render.yaml`, `router.php`, the
emptied `package.json`, the env-aware `api/db.php`, the new `DEPLOY.md`
and `README.md`) to your branch.

```bash
cd C:\xampp\htdocs\FARM
git add .
git commit -m "Make project deployable on Render (PHP buildpack + env vars)"
git push origin main
```

---

## 3. Create the Web Service on Render

1. Sign in at https://render.com.
2. Click **New + → Web Service → Connect a repository**, pick your
   `FARM` repo.
3. Render will inspect the repo. Confirm:
   * **Environment**: `PHP`
   * **Region**: same region as your Aiven DB if possible
   * **Branch**: `main` (or whichever)
   * **Root Directory**: leave blank (project root)
   * **Build Command**: `true` (or paste: `composer install --no-dev --no-interaction --optimize-autoloader 2>/dev/null || echo skip`)
   * **Start Command**: `php -S 0.0.0.0:$PORT -t . router.php`
   * **Plan**: `Free`
4. Scroll down to **Environment Variables** and add:
   * `DB_HOST`     → the Aiven host
   * `DB_PORT`     → the Aiven port (e.g. `12345`)
   * `DB_NAME`     → `farm_db`
   * `DB_USER`     → the Aiven user (e.g. `avnadmin`)
   * `DB_PASS`     → the Aiven password
   * `APP_BASE`    → `/`
5. Click **Create Web Service**. Render builds and deploys in ~2–3
   minutes. Watch the logs for `Booting server on 0.0.0.0:10000`.

> **Why `package.json` is empty.** Earlier this project shipped a
> `package.json` whose start script ran `php -S localhost:8000`. Render
> saw that file and tried to launch the project as a Node.js app, but
> the Node runtime has no PHP binary → `MODULE_NOT_FOUND` and a crash.
> The file is now `{}` so Render's auto-detection falls through to the
> PHP buildpack we configured via `render.yaml` / the Start Command.

---

## 4. Bootstrap the schema

The first request to the API triggers `api/db.php:db()`, which calls
`ensure_schema()`. That function runs `CREATE TABLE IF NOT EXISTS` for
every table the API uses, so the schema is ready as soon as the API is
hit once.

To verify, open this URL in your browser:

```
https://<your-service>.onrender.com/api/auth.php?action=csrf
```

You should see a JSON response like:

```json
{"ok":true,"csrf":"a1b2c3d4..."}
```

If you get `500 Database connection failed`, double-check the four
`DB_*` env vars and that Aiven's `0.0.0.0/0` rule is in place.

---

## 5. Set the shopkeeper password

The shopkeeper account must be created **once** from the command line
(it's the only place where the bcrypt hash is written).

1. In Render, open your service → **Shell** tab.
2. Run:

   ```
   php api/setup_shopkeeper.php set Deepak 'YourStrongPassword!'
   ```

3. Open `https://<your-service>.onrender.com/`, click **Shopkeeper**,
   sign in with `Deepak` / your new password.

---

## 6. Smoke-test the deployment

* **Homepage loads**: `https://<your-service>.onrender.com/` shows the
  SPA (proves `router.php`'s `DirectoryIndex` emulation works).
* **Shopkeeper dashboard**: create a product with an image, verify the
  image appears in the public profile.
* **Guest interactions**: comment, like, rate as a guest (no login).
* **Engagement tab**: see the comment + rating appear in the shopkeeper
  dashboard, reply to it, confirm the reply shows up.

---

## Known limitations on the Render free tier

1. **Ephemeral disk** — the `uploads/` directory is wiped on every
   redeploy and on instance restarts (Render free plans sleep after
   inactivity and lose local disk when they wake). The database and
   all metadata survive; only the uploaded image binaries are lost.
   - Workaround today: re-upload after a redeploy.
   - Proper fix: swap the `move_uploaded_file()` call in
     `api/upload.php` for an S3 PUT when `S3_BUCKET` is set, and serve
     images from S3 / Cloudflare R2. Add this in a follow-up.

2. **Sleep on free plan** — Render free Web Services idle-sleep after
   15 minutes of no traffic. The first request after sleep takes
   ~30 seconds to wake up. This is fine for a small shop site but
   noticeable.

3. **No MySQL on Render** — that's why step 1 sends you to Aiven.

4. **HTTPS** — Render provides `*.onrender.com` TLS automatically. If
   you add a custom domain, Render issues a Let's Encrypt cert with one
   click.

---

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| Build fails with "Cannot find package.json" | Confirm you pushed the emptied `package.json` (it should be `{}`, not missing). |
| Build fails with `composer: command not found` | Change the Build Command to `true` (composer is optional, see step 3). |
| Start fails with "Address already in use" | Render sets `$PORT` for you — don't hardcode a port in the start command. |
| `500 Database connection failed` | Re-check the four `DB_*` env vars and that Aiven's `0.0.0.0/0` IP rule is in place. |
| Homepage shows the Apache directory listing instead of the SPA | `DirectoryIndex` is Apache-only. The PHP router (`router.php`) handles this on Render — make sure the Start Command matches step 3. |
| Images disappear after a redeploy | Ephemeral disk (see limitations above). |
| CSRF "token missing or invalid" | Refresh the page once — the SPA fetches a fresh token on boot. |

---

## Upgrading later

When the shop outgrows the free tier:

* **Persistent disk**: Render paid plans support a mounted disk. Move
  the `uploads/` directory there. (Or — preferred — switch to S3.)
* **No idle sleep**: paid plans don't sleep.
* **Custom domain + HTTPS**: free on Render, one-click in the
  dashboard.
