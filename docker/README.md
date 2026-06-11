# GreatonMedical Client Portal

Multi-tenant client portal for GreatonMedical agency. Each client (CAPS Medical, Test Client, etc.) has their own users who can submit content requests through a branded form. Submissions are pushed to monday.com via the GraphQL API and tracked in a local dashboard.

**Phase 1 (current):** Login, request form, submit to monday, basic dashboard listing your submissions.

**Phase 2 (next):** Live status sync from monday.com on dashboard.

**Phase 3:** Admin panel for managing clients and users through UI.

---

## Prerequisites

- Docker Desktop (running)
- Free ports: `8080`, `8081`, `3307`

You do **NOT** need a monday.com API token to test locally — mock mode is on by default.

---

## Setup (first time)

### 1. Extract the zip into a folder of your choice

### 2. Create your `.env` file

```bash
cp .env.example .env
```

Default `.env` is already configured for local development with mock mode enabled. You don't need to change anything to start.

### 3. Start Docker

```bash
docker compose up -d --build
```

First build pulls images and compiles PHP container (~2-3 min). Subsequent starts take ~10 seconds.

### 4. Verify it's running

- **Portal:** http://localhost:8080
- **phpMyAdmin** (database inspector): http://localhost:8081
  - Server: `db`, User: `root`, Password: `root_pass`

---

## Test accounts

Two clients with one user each, to verify multi-tenant isolation:

| Username    | Password  | Client            | Accent color |
|-------------|-----------|-------------------|--------------|
| `test_user` | `test1234`| Test Client Inc.  | Teal         |

---

## Mock mode (no monday.com required)

By default `MOCK_MONDAY=true` in `.env`. This means:

- Submitting a form does NOT call the real monday.com API
- A fake monday item ID is generated (timestamp-based)
- Everything else works normally — DB writes, dashboard, status messages
- Submissions are tagged `mock` in the dashboard
- A yellow banner appears at the top of every page reminding you mock is active

You can see the simulated payload that *would have been sent* to monday in the logs:

```bash
docker compose logs app | grep "MOCK MONDAY"
```

### Switching to real monday.com (still local)

When you're ready to test against the real API:

1. Get your monday API token: monday.com → avatar (top right) → Developers → My Access Tokens → Show
2. Edit `.env`:
   ```
   MOCK_MONDAY=false
   MONDAY_API_TOKEN=your_actual_token_here
   ```
3. Restart: `docker compose restart app`
4. Submit a request` → check your real CAPS monday board

⚠️ Note: `test_user` will fail in real mode because Test Client uses dummy monday IDs. Real submissions only work for CAPS until you create real boards/groups for other clients.

---

## Daily commands

```bash
# Start
docker compose up -d

# Stop
docker compose down

# View app logs (live tail)
docker compose logs -f app

# Restart only app container
# (NOT needed for PHP changes — src/ is a live-mounted volume; just refresh browser)
docker compose restart app

# Wipe database and re-run init.sql (resets to seed data)
docker compose down -v
docker compose up -d --build

# Run a one-off PHP command (e.g. generate a password hash)
docker compose exec app php -r "echo password_hash('mypassword', PASSWORD_BCRYPT) . PHP_EOL;"

# Open MySQL CLI inside the container
docker compose exec db mysql -uroot -proot_pass gm_portal
```

---

## Project structure

```
greatonmedical-portal/
├── docker-compose.yml         # Orchestration (app + db + phpmyadmin)
├── .env                       # YOUR secrets (never commit!)
├── .env.example               # Template
├── README.md                  # This file
├── DEPLOYMENT.md              # Production deployment guide
├── docker/
│   ├── Dockerfile             # PHP 8.2 + Apache image
│   └── apache-config.conf
├── db/
│   └── init.sql               # Schema + seed data (runs on first DB start)
└── src/                       # Live-mounted volume — edit and refresh
    ├── index.php              # Login page
    ├── dashboard.php          # User's submissions list
    ├── new-request.php        # Content request form
    ├── submit.php             # Form handler → calls monday API
    ├── logout.php
    ├── includes/
    │   ├── config.php         # Env vars, constants
    │   ├── db.php             # PDO connection
    │   ├── auth.php           # Session, login, CSRF, rate limiting
    │   ├── monday.php         # Monday GraphQL API helper (with mock mode)
    │   ├── header.php         # Shared template
    │   └── footer.php
    └── assets/
        ├── css/style.css
        └── js/form.js         # Conditional field logic
```

---

## Adding new clients/users (Phase 1 — manual via phpMyAdmin)

A proper admin panel comes in Phase 3. For now:

### Add a new client

1. Open http://localhost:8081
2. Database `gm_portal` → table `clients` → Insert
3. Required fields: `name`, `slug` (unique, lowercase-no-spaces), `monday_board_id`, `monday_group_id`, `accent_color`

### Add a new user under a client

1. Generate password hash:
   ```bash
   docker compose exec app php -r "echo password_hash('chosen_password', PASSWORD_BCRYPT) . PHP_EOL;"
   ```
2. phpMyAdmin → `users` → Insert
3. Required: `client_id` (from clients table), `username`, `email`, `password_hash` (from step 1), `full_name`

---

## Troubleshooting

**"Database connection failed"**
Wait 10-15 seconds after `docker compose up` — MySQL needs time on first start. Refresh the page.

**"Failed to create request on monday.com"**
You're not in mock mode but token is missing or wrong. Check `.env` and `docker compose logs app | grep -i monday`.

**Don't see a task in monday after submit (real mode)**
Verify `monday_board_id` and `monday_group_id` in the `clients` table match your actual board. The CAPS values come from the original CAPS form source code.

**Ports 8080/8081/3307 already in use**
Edit `docker-compose.yml` and change the left side of the port mappings (e.g. `8090:80`).

**Login says "Too many failed attempts"**
Rate limiter triggered (5 failed attempts in 15 min). Wait 15 minutes, or wipe `login_attempts` table:
```bash
docker compose exec db mysql -uroot -proot_pass gm_portal -e "TRUNCATE login_attempts;"
```

**Changes to PHP files don't show**
The `src/` folder is mounted as a live volume — just save the file and refresh the browser. No restart needed. (Only restart if you edit `Dockerfile` or `docker-compose.yml`.)

---

## What's next

When local testing feels solid, see `DEPLOYMENT.md` for the production deployment guide (Cloudways setup, domain, HTTPS, environment hardening).
