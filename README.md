# Multi-Agent Food OS

The initial foundation uses PHP 8.3/Apache, MariaDB, and an internal
FastAPI service. The meal-planning agents and user-facing application are
planned in [PLAN.md](./PLAN.md).

## Run locally

Install Docker Desktop or Docker Engine. Copy `.env.example` to `.env`,
then replace the example passwords with local values. The `web` container
is available at `http://localhost:8080` through a loopback-only binding.
If the external `traefik` Docker network does not already exist, create
it once before starting Compose.

```bash
cp .env.example .env
docker network create traefik
docker compose config
docker compose up --build -d
```

The FastAPI and MariaDB services do not publish host ports. The API is
reachable only over the internal Compose network. Apache serves
`public/`; `public/index.php` delegates to the retained
`app/index.php`, which continues to load `app/db.php`. The initial schema
keeps the legacy `items` table required by that page.

## Database initialization and existing data

MariaDB runs `database/schema.sql` only when initializing an empty data
directory. It creates the application tables and seeds a development
user, recipes, pantry items, and the legacy demo rows. Existing `./data`
databases are not automatically changed. Back up an existing database
before applying the additive schema manually:

```bash
docker compose exec -T db sh -c 'mariadb -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' < database/schema.sql
```

Do not delete `./data` to apply schema changes unless intentionally
discarding that database.

## Deployment

The existing GitHub Actions workflow deploys pushes to `main`. Configure
its SSH and application/database secrets in repository settings. Keep
local and production `.env` files out of version control. The `web`
service name and Traefik routing labels are retained.

## Repository layout

- `public/` — Apache document root and web entry point
- `app/` — retained legacy PHP page and PDO helper
- `src/` — Composer PSR-4 namespace root for application code
- `database/schema.sql` — MariaDB schema and development seed data
- `services/catalog-api/` — FastAPI health-service foundation
- `Dockerfile`, `docker-compose.yml` — PHP, database, and API services
- `.env.example` — local environment template; never commit `.env`
- `.github/workflows/deploy.yml` — deployment workflow
