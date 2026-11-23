# Multi-Agent Food OS

The initial foundation uses PHP 8.3/Apache, MariaDB, and an internal
FastAPI service. The complete meal-planning pipeline includes Agent 1
through Agent 4, bounded feedback retries, and the human approval UI.

## Run locally

Install Docker Desktop or Docker Engine. Copy `.env.example` to `.env`,
then replace the example passwords with local values. The `web` service
does not publish a host port; access is exclusively through Traefik. For
a local deployment, Traefik must be running on the external `traefik`
network and configured to route the hostname in the Compose labels. Do
not expect `http://localhost:8080` to work.

```bash
cp .env.example .env
# Only if Traefik is not already running and the external network is absent:
docker network create traefik
docker compose config
docker compose up --build -d
```

The FastAPI and MariaDB services do not publish host ports. The API is
reachable only over the internal Compose network. Apache serves
`public/`; `public/index.php` delegates to the retained
`app/index.php`, which continues to load `app/db.php`. The initial schema
keeps the legacy `items` table required by that page.

## Catalog and nutrition API

The FastAPI service provides typed JSON lookup endpoints:

- `GET /catalog/prices/{ingredient}?quantity=500&unit=g`
- `POST /catalog/prices`
- `GET /catalog/nutrition/{ingredient}?serving_quantity=200&serving_unit=g`
- `POST /catalog/nutrition`
- `GET /health`

Price and nutrition responses include source and fetched-at freshness
metadata. For prices, the service tries the PosoKanei product search API
(`https://api.posokanei.gov.gr/products/search`) and falls back to the bundled
catalog estimates when the upstream request fails or has no comparable result.
Fallback prices are labeled **Approximate local estimate** in the shopping list;
they are not live retailer quotes. No supermarket API key is configured.
`CATALOG_PRICE_SOURCE_URL` may override the API base URL or full search URL.
Nutrition data uses the local catalog first and queries the keyless Open Food
Facts API only for ingredients not found there.

Run the standalone idempotent updater with:

```bash
docker compose run --rm catalog-api python -m app.updater
```

With no provider configured, it reports the bundled fixture as unchanged.
With a provider configured, it fetches each known ingredient, validates
the response shape, and atomically updates the fixture file only when data
changes.

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
- `services/catalog-api/` — Internal FastAPI catalog and nutrition service
- `Dockerfile`, `docker-compose.yml` — PHP, database, and API services
- `.env.example` — local environment template; never commit `.env`
- `.github/workflows/deploy.yml` — deployment workflow

## Weekly plan generation

Open the dashboard through its existing Traefik route. Save goals,
allergies, dietary preferences, fasting times, and prep limits; maintain the
pantry by selecting a catalog ingredient or adding your own named fridge item;
then select breakfast, lunch, and/or dinner, servings, and whether missing
ingredients may be purchased. Custom ingredients are saved to the pantry, but
can only be selected by plans when a saved recipe contains a matching
ingredient. **Generate Weekly Plan** runs the
meal planner, recipe scaler, shopping optimizer, and nutrition feedback
agent, with at most three plan attempts. Generated plans and their shopping
lists remain in the session until approved or discarded. A rejected plan
cannot be saved, and generation never writes to `meal_plans`.

Set `LLM_API_KEY` to enable provider-backed JSON responses. When it is
unset, the existing providers return the deterministic mock response,
so the dashboard remains usable without an external LLM.
