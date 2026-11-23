# Copilot Instructions

## Workspace Reality
- This repository is a small PHP + MariaDB deployment demo, not yet the full Multi-Agent Food OS.
- PHP runs in the `lyp-app` container from `php:8.3-apache`; Apache serves `/var/www/html`, which is mounted from `./app/`. Only `pdo` and `pdo_mysql` are installed explicitly in the Dockerfile.
- MariaDB 11 runs in the `lyp-db` container on Docker network `internal`. The PHP host is `db` (the Compose service name), database `db1`, and port `3306` unless Compose or the environment overrides it. The database has no published host port and is not exposed through Traefik.
- Compose loads `.env` for `lyp-app`. Required values are `SITE_USERNAME`, `SITE_PASSWORD`, `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD`; MariaDB also receives `DB_ROOT_PASSWORD`. `.env.example` documents their defaults. Never commit `.env`.
- Database persistence is `./data/`; first-run schema and seed SQL is `./db-init/init.sql`, which creates `items` and inserts three rows. It is mounted into MariaDB's initialization directory.
- Traefik routes `lyp-app` to `https://lyp.nkyriopoulos.com` on the external `traefik` network. Deployment uses GitHub Actions to rsync to `/srv/lyp/` and then runs `docker compose up -d --build --remove-orphans web`.
- Do not publish host ports (`ports:`) on `web`, `db`, or `catalog-api` in `docker-compose.yml`; external access is handled exclusively via Traefik labels.
- Current source is `app/index.php` and `app/db.php`; there is currently no `composer.json`, `vendor/`, `src/`, `views/`, `services/catalog-api/`, or FastAPI application. Do not claim or require tools/paths that do not exist until they are actually added.

## PHP, DTO, and Database Rules
- Every PHP file must begin with `declare(strict_types=1);`, use a valid `App\...` namespace when using the application architecture, follow PSR-12, and declare explicit parameter types, return types, and typed properties.
- For the multi-agent architecture, Composer/PSR-4 must map `App\` to `src/`, UI stays server-rendered under `views/`, and agents live under `src/Agents/` with prompt templates under `src/Agents/Prompts/`.
- Pass structured data between agents only through readonly DTO classes such as `readonly class MealPlanDTO`; never pass loose associative arrays between agents.
- All SQL belongs in repository classes under `src/Repository/`, with PDO exception mode and native prepares:
  `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`,
  `PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC`,
  `PDO::ATTR_EMULATE_PREPARES => false`.
- Always use PDO prepared statements with named parameters (`:param`). Never concatenate user or environment values into SQL or use positional placeholders for application SQL.
- Read credentials and API/LLM secrets from `$_ENV` or `getenv()`; never hardcode or commit secrets.
- Keep LLM JSON responses behind strict DTO decoding and validation. Quantity scaling, pantry subtraction, cost summation, and other arithmetic must be deterministic PHP.
- Separate orchestration agents (`Orchestrator`, `MealPlanner`, `RecipeSpecialist`, `ShoppingOptimizer`, `NutritionFeedback`) and enforce a maximum 3 repair iterations when the nutrition/feedback agent requests a fix.

## FastAPI Boundary
- Any external catalog/nutrition HTTP service belongs in `services/catalog-api/` and must use typed request/response models plus deterministic JSON contracts.
- The primary Greek supermarket price source is the keyless `https://posokanei.gov.gr/` (e-Καταναλωτής) website. Use `httpx`, realistic browser headers, bounded timeouts, 24-hour local caching, and seeded Greek fallback prices. Do not require supermarket API keys or credentials.
- Nutrition lookups use the keyless Open Food Facts API at `https://world.openfoodfacts.org/`, with a local per-100g fallback table for basic raw ingredients. No nutrition API key is required.
- PHP consumes FastAPI over HTTP/JSON only after the service is explicitly requested and implemented; do not invent service dependencies or endpoints in this demo.

## Verification
- Run `php -l <file>` for every PHP file created or modified.
- Use `docker compose build && docker compose up -d` to verify container changes, and inspect the `lyp-app`/`lyp-db` logs when needed.
- Run `./vendor/bin/phpunit` only if PHPUnit/vendor exists, and `pytest` only if `services/catalog-api/` exists.
