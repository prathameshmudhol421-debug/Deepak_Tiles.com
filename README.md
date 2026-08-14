---

### 4. Corrected `README.md`

Save this as `README.md` in your project root:

```markdown
# Deepak Tiles & Granite — Shop Web App

A lightweight single-page web app for product showcases, guest-friendly interactions (likes, comments, star ratings), and a shopkeeper admin dashboard.

* **Frontend**: `index.html` + `css/` + `js/`
* **Backend**: PHP 7.4+ (vanilla PHP, PDO)
* **Database**: MySQL / MariaDB / PostgreSQL

## Local Setup (XAMPP)

1. Copy the project directory to `C:\xampp\htdocs\FARM\`.
2. Start Apache and MySQL from XAMPP Control Panel.
3. Open `http://localhost/FARM/index.html`. Schema initializes on first request.
4. Set admin password via CLI:
   ```powershell
   C:\xampp\php\php.exe api\setup_shopkeeper.php set Deepak YourPasswordHere