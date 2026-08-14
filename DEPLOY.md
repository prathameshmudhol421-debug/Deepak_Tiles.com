# Deploying to Render

This is the step-by-step for putting the FARM shop live on
[Render](https://render.com) using the free tier.

The project is configured to deploy via `render.yaml` (PHP buildpack,
start command `php -S 0.0.0.0:$PORT -t . router.php`). Everything below
can also be done through the Render dashboard without the YAML file.

---

## 1. Get a Database (MySQL or PostgreSQL)

Render's free Web Services do **not** include a managed database server. Use any
managed database provider — these have a free tier:

* **Supabase (PostgreSQL)** — https://supabase.com (Recommended for PostgreSQL)
* **Aiven (MySQL / PostgreSQL)** — https://aiven.io (Recommended for MySQL)
* **Neon (PostgreSQL)** — https://neon.tech (Generous free PostgreSQL tier)

### Aiven / Supabase Setup

1. Create a service on your chosen provider.
2. Under **Allowed IP addresses / Network Access**, add `0.0.0.0/0` so Render can connect.
3. Obtain your connection parameters:
   * **Host** (`xxxxxx.supabase.co` or `mysql-xxxxx.aivencloud.com`)
   * **Port** (`5432` for Postgres, `3306` / custom for Aiven)
   * **User** (`postgres`, `avnadmin`, or custom)
   * **Password** 
   * **Database Name** (`farm_db` or `postgres`)

---

## 2. Push the Repo to GitHub

Ensure all updated files (`render.yaml`, `router.php`, `package.json`, `api/db.php`, `api/interactions.php`, etc.) are committed and pushed:

```bash
git add .
git commit -m "Configure deployment and updated API endpoints"
git push origin main