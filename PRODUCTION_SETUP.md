# Depak Tiles & Granite — Production Setup Checklist

**Status**: Ready to deploy ✅

> **Looking for the Render (PaaS) walkthrough?** See
> [`DEPLOY.md`](DEPLOY.md). It covers the Render-specific path:
> provisioning a free external MySQL (Render does not include one),
> wiring env vars, and bootstrapping the schema on first request.
>
> **The rest of this document** covers the more traditional paths:
> shared hosting (Bluehost / Hostinger / GoDaddy), VPS (DigitalOcean,
> Linode, AWS Lightsail), or any LAMP stack you control. If you're
> deploying to one of those, keep reading.

## Current Local Setup ✅
- Database: Connected to `farm_db` on MariaDB (port is whatever
  `api/db.php` `DB_PORT` says — currently `3307` on this machine)
- Shopkeeper account: `Deepak` (set with `php api\setup_shopkeeper.php set Deepak …`)
- PHP API: All endpoints available
- Frontend: Single-page app at `spider.html` (also reachable at `/` via
  `DirectoryIndex`)

## Production Deployment Steps

### Step 1: Set Up Your Domain & Hosting
Choose one:
- **Shared Hosting** (Bluehost, GoDaddy, Hostinger)
  - They provide cPanel/FTP
  - MySQL is pre-installed
  - Usually includes Let's Encrypt SSL (free)

- **VPS/Cloud** (DigitalOcean, Linode, AWS Lightsail)
  - You control the server
  - More control over PHP settings
  - Need to install MySQL yourself

### Step 2: Get HTTPS Certificate
- **Shared hosting**: Usually automatic with cPanel "AutoSSL"
- **VPS**: Use Let's Encrypt (free)
  ```bash
  sudo apt install certbot python3-certbot-apache
  sudo certbot certonly --apache -d yourdomain.com
  ```

### Step 3: Update Database Credentials (IMPORTANT)
**File**: `api/db.php` lines 21-25

**Current** (XAMPP localhost):
```php
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3307');
define('DB_NAME', 'farm_db');
define('DB_USER', 'root');           // ⚠️ NEVER use root in production
define('DB_PASS', '');               // ⚠️ NEVER use empty password
```

**Production** (shared hosting example):
```php
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');           // Standard MySQL port
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_db_user');   // Create a new user!
define('DB_PASS', 'YourStrongPassword123!');
```

**How to create a dedicated DB user** (via cPanel / SSH):
```sql
-- SSH / phpMyAdmin Console:
CREATE USER 'depak_user'@'localhost' IDENTIFIED BY 'YourStrongPassword123!';
CREATE DATABASE depak_shop;
GRANT ALL PRIVILEGES ON depak_shop.* TO 'depak_user'@'localhost';
FLUSH PRIVILEGES;

-- Import schema (if not auto-created):
mysql -u depak_user -p depak_shop < farm.sql
```

### Step 4: Configure Production PHP Settings
**File**: Your hosting provider's `php.ini`

```ini
expose_php = Off
display_errors = Off
log_errors = On
error_log = /var/log/php-errors.log
max_upload_size = 50M
post_max_size = 50M
```

Restart Apache/PHP:
```bash
sudo systemctl restart apache2
# or via cPanel control panel
```

### Step 5: Set File Permissions
```bash
# SSH into your server:
chmod 755 /path/to/spider
chmod 755 /path/to/spider/uploads
chmod 644 /path/to/spider/.htaccess
# uploads/ must be writable by web server but NOT executable
# (Apache user is usually www-data or apache)
chown -R www-data:www-data /path/to/spider/uploads
chmod 775 /path/to/spider/uploads
```

### Step 6: Update .htaccess (Enable HTTPS)
**File**: `c:\xampp\htdocs\FARM\.htaccess`

The HTTPS redirect for non-localhost is already in place. To force HTTPS
everywhere (including `localhost` overrides), remove or comment the two
`RewriteCond %{HTTP_HOST} !^localhost` lines.

### Step 7: Rotate Shopkeeper Password Before Launch
The default dev password is `Deepak@123`. **Change it** before going live:

```bash
ssh user@yourserver.com
cd /path/to/spider
php api/setup_shopkeeper.php set Deepak 'AReal-Strong-Pass-2026!'
```

### Step 8: Protect Setup Script
Option A (Shared hosting - move outside document root):
```bash
mv api/setup_shopkeeper.php ../private/setup_shopkeeper.php
```

Option B (VPS - restrict via .htaccess):
Add to `.htaccess`:
```apache
<FilesMatch "setup_shopkeeper.php">
    Require all denied
</FilesMatch>
```

### Step 9: Add Branding
Replace `uploads/og-default.png` with your 1200×630 brand image for social media previews.

### Step 10: Database Backup Script
Create a daily backup (via cron job):

```bash
# SSH into your server, create backup script:
nano /home/user/backup_depak.sh
```

Add this:
```bash
#!/bin/bash
DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/home/user/backups"
mkdir -p $BACKUP_DIR

mysqldump -u depak_user -p'YourPassword' depak_shop > $BACKUP_DIR/farm_$DATE.sql
# Keep only last 30 backups
find $BACKUP_DIR -name "farm_*.sql" -mtime +30 -delete
```

Make it executable and add to crontab:
```bash
chmod +x /home/user/backup_depak.sh
crontab -e
# Add: 0 2 * * * /home/user/backup_depak.sh  (runs daily at 2 AM)
```

---

## Testing Before Going Live

1. **Test locally** (already done ✅)
2. **Upload to staging** (get a test subdomain like `test.yourdomain.com`)
3. **Verify HTTPS works** with an SSL checker: https://www.ssllabs.com/
4. **Test all features**:
   - Can you log in as Deepak?
   - Can you add products?
   - Can guests comment/rate without signing up?
   - Can you upload images?
   - Do the engagement metrics work?
5. **Check security headers**: https://securityheaders.com/

---

## Post-Launch Monitoring

- Monitor PHP error log: `/var/log/php-errors.log`
- Check disk space for `uploads/` and database backups
- Rotate Deepak's password monthly:
  ```bash
  php api/setup_shopkeeper.php rotate
  ```
- Review engagement stats regularly in the dashboard

---

## Troubleshooting

| Issue | Solution |
|-------|----------|
| "Cannot connect to database" | Check DB_USER/DB_PASS in `api/db.php` |
| "CSRF token missing" | Verify `.htaccess` is being read (`DirectoryIndex` should work) |
| "Images not uploading" | Check `uploads/` is writable: `ls -la uploads/` |
| "500 errors" | Enable PHP error logging and check `/var/log/php-errors.log` |
| "HTTPS not redirecting" | Verify mod_rewrite is enabled: `apache2ctl -M \| grep rewrite` |

---

## Final Checklist Before Going Live

- [ ] Domain registered & DNS pointing to hosting
- [ ] SSL certificate installed (HTTPS working)
- [ ] Database user created (not using `root`)
- [ ] `api/db.php` updated with production credentials
- [ ] `php.ini` configured for production
- [ ] `uploads/` directory has correct permissions
- [ ] `.htaccess` HTTPS redirect enabled
- [ ] `api/setup_shopkeeper.php` moved/restricted
- [ ] `uploads/og-default.png` replaced with brand image
- [ ] Database backup automation in place
- [ ] All features tested in staging
- [ ] Security headers verified (securityheaders.com)

🚀 **You're ready to deploy!**