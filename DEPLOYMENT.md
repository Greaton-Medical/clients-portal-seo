# Deployment Guide

## Spinning up a new brand instance

Estimated time: ~10 minutes.

### Prerequisites
- Git, Docker + Docker Compose (local) or Cloudways PHP 8.2 app (production)
- A MySQL database (Docker handles this automatically locally)
- Your brand hex colors and logo (initials work fine without a logo file)

---

### 1. Clone the repo

```bash
git clone <repo-url> my-agency-portal
cd my-agency-portal
```

---

### 2. Create the database

**Local (Docker):** the database is created automatically in step 5.

**Cloudways / production:** create a new MySQL database in your Cloudways app panel. Note the DB name, user, and password.

---

### 3. Fill in `.env`

```bash
cp .env.example .env
```

Edit `.env` with your brand values:

| Variable | Description | Example |
|---|---|---|
| `AGENCY_NAME` | Short agency name (used in UI text) | `MyAgency` |
| `APP_NAME` | Full portal title (browser tab) | `MyAgency Client Portal` |
| `APP_URL` | Production domain | `https://clients.myagency.com` |
| `BRAND_PRIMARY_COLOR` | Primary hex color | `#A434FF` |
| `BRAND_SECONDARY_COLOR` | Dark/secondary hex | `#080808` |
| `BRAND_PRIMARY_LIGHT` | Light tint hex (~12% primary on white) | `#f5e8ff` |
| `BRAND_LOGO_INITIALS` | 1–3 chars shown when no logo is set | `MA` |
| `BRAND_LOGO_PATH` | External URL **or** web-root-relative path | `https://cdn.myagency.com/logo.svg` |
| `FAVICON_URL` | Browser favicon URL | `https://myagency.com/favicon.png` |
| `DB_NAME` | Database name | `myagency_portal` |
| `DB_USER` / `DB_PASS` | DB credentials | — |
| `MONDAY_API_TOKEN` | monday.com token (leave empty + `MOCK_MONDAY=true` for now) | — |
| `MONDAY_DOMAIN` | monday.com subdomain | `myagency` |

**To use a local logo file instead of a URL:**
1. Drop the file into `src/assets/brand/` (e.g. `logo.svg`)
2. Set `BRAND_LOGO_PATH=/assets/brand/logo.svg`
3. The portal shows the `<img>` only if the file exists; otherwise falls back to initials automatically.

---

### 4. Set the admin password

The default seed creates admin / `Gs!9mXp2#kLv8wQr`. Change it immediately after first login via the admin panel, or update the hash in `db/init.sql` before running the seed:

```bash
php -r "echo password_hash('YourNewPassword', PASSWORD_BCRYPT, ['cost' => 12]);"
```

Replace the hash in the `INSERT INTO admins` line of `db/init.sql`.

---

### 5. Start locally

```bash
docker compose up -d --build
```

- Portal: http://localhost:8080
- Admin: http://localhost:8080/admin/login.php
- phpMyAdmin: http://localhost:8081

The database is seeded from `db/init.sql` on first run. To reseed from scratch:

```bash
docker compose down -v && docker compose up -d --build
```

---

### 6. Deploy to Cloudways (production)

**SFTP mapping:** upload the **contents** of `src/` directly into `public_html/` on the server. Do **not** upload the `src/` folder itself — the files inside it map to the web root.

```
local:  src/index.php        → server: public_html/index.php
local:  src/admin/           → server: public_html/admin/
local:  src/assets/          → server: public_html/assets/
```

Steps:
1. In Cloudways, set all `.env` variables as **Environment Variables** in the app settings (do not upload `.env` to the server).
2. SFTP the contents of `src/` into `public_html/`.
3. Run `db/init.sql` against your production database (once, on first deploy).
4. Point your domain to the Cloudways app.
5. Set `APP_ENV=production` in environment variables to enable secure cookies and disable error display.

---

### 7. Verify

- [ ] Login page shows your brand name, colors, and logo (or initials)
- [ ] Admin panel at `/admin/login.php` works with your admin credentials
- [ ] Admin shows correct branding
- [ ] Create a test client and user; log in and confirm client accent color applies
- [ ] `grep -ri "greatonmedical" src/ db/` returns zero hits
- [ ] Mock submission flow works end-to-end with `MOCK_MONDAY=true`

---

### Swapping brand colors later

Edit two lines in `.env` (or Cloudways env vars) and redeploy/reload:

```
BRAND_PRIMARY_COLOR=#NewHex
BRAND_SECONDARY_COLOR=#NewDarkHex
```

No code changes needed.
