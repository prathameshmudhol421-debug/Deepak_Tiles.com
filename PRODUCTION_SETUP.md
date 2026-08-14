# Depak Tiles & Granite — Production Setup Checklist

**Status**: Ready to deploy ✅

> **Looking for the Render (PaaS) walkthrough?** See [`DEPLOY.md`](DEPLOY.md). It covers the Render-specific path: provisioning an external DB, wiring env vars, and bootstrapping the schema.
>
> **The rest of this document** covers traditional paths: shared hosting (Bluehost / Hostinger / GoDaddy), VPS (DigitalOcean, Linode, AWS Lightsail), or any LAMP stack you control.

## Current Local Setup ✅
- Database: Connected to `farm_db` on MariaDB/MySQL (configured in `api/db.php`)
- Shopkeeper account: Set via `php api/setup_shopkeeper.php set Deepak <password>`
- PHP API: All endpoints available
- Frontend: Single-page app at `index.html`

## Production Deployment Steps

### Step 1: Set Up Domain & Hosting
Choose one:
- **Shared Hosting** (cPanel / FTP, pre-installed MySQL, Let's Encrypt SSL)
- **VPS / Cloud** (DigitalOcean / AWS Lightsail, full root access)

### Step 2: Configure HTTPS
- **Shared hosting**: Enable "AutoSSL" or Let's Encrypt via control panel.
- **VPS**: Use Certbot:
  ```bash
  sudo apt install certbot python3-certbot-apache
  sudo certbot certonly --apache -d yourdomain.com