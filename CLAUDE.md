# GreatonMedical Portal — Claude Rules

## Source of truth

| What | Where |
|------|-------|
| Application PHP/CSS/JS | `src/` |
| DB migrations | `db/migrations/` |
| Docker build config | `docker/Dockerfile`, `docker/apache-config.conf` |

`docker-compose.yml` mounts **`./src`** → `/var/www/html` inside the container.

## FORBIDDEN paths — never edit these

- `docker/src/` — **does not exist, must not be created**
- `docker/db/migrations/` — **does not exist, must not be created**

The `docker/` folder contains ONLY build/infra files (Dockerfile, apache config, docker-compose, .env, init.sql). Never put application code there.

## Verify your edits land in the container

```bash
docker compose exec app cat /var/www/html/<file>.php | grep "<known_function>"
```

## Key facts

- PHP 8.2, MySQL, deployed to Cloudways
- Cache: file-based `/tmp/gm_portal_cache/`, 60s TTL
- Monday column IDs on CAPS board: `color_mksbwnby` (TASK STATUS), `color_mksb2tks` (PROJECT STATUS), `priority_Mjj26KQF`, `date4`, `link_mksbghas`
- One logical change per commit — don't bundle unrelated changes
- Ask before making product/UX decisions; make code/naming calls yourself
- Stop at the end of each Phase and wait for explicit test sign-off before moving on
