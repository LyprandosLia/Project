# Copilot Instructions

## Workspace Reality
- This repository contains the Multi-Agent Food OS: a PHP 8.3/Apache web application, MariaDB 11 database, and internal FastAPI catalog API.
- Compose services are `web`, `catalog-api`, and `db`. The web container is routed through Traefik at `https://lyp.nkyriopoulos.com`; the API and database have no published host ports.
- Apache serves `public/`; `public/index.php` delegates to `app/index.php`. Application classes use Composer's `App\` to `src/` PSR-4 mapping. The server-rendered dashboard is in `views/`.
- `database/schema.sql` initializes an empty MariaDB data directory and seeds development data. It does not migrate an already-populated `./data/` directory.
- GitHub Actions deploys pushes to `main` to `/srv/lyp/`. Never commit `.env`, credentials, generated price caches, or database files.

## PHP, DTO, and Database Rules
- Every PHP file must begin with `declare(strict_types=1);`, use an `App\...` namespace for application classes, follow existing formatting, and declare explicit parameter and return types.
- Structured data between the application and agents should use the existing typed DTOs. Keep SQL in repository classes, use named PDO parameters, native prepares, and exception mode.
- Read secrets and configuration from the environment. Keep LLM JSON behind strict decoding and validation; quantity scaling, pantry subtraction, and cost summation remain deterministic application code.
- The dashboard collects calorie/protein goals, allergies, dietary notes, fasting windows, prep limits, pantry quantities (including custom named ingredients), selected breakfast/lunch/dinner slots, servings, and shopping mode. Profile and pantry writes must be validated and transactional. Custom ingredient entries do not create recipes.
- Generation is session-only until explicit human approval. A plan rejected by nutrition validation must not be persisted. The orchestrator runs at most three total attempts, including the first.
- Keep each agent role distinct: MealPlanner selects a valid schedule; RecipeSpecialist scales recipe quantities; ShoppingOptimizer subtracts pantry and estimates remaining purchases; NutritionFeedback validates the plan and returns revision feedback.

## FastAPI Boundary
- `services/catalog-api/` owns external price/nutrition HTTP calls and exposes typed JSON routes to PHP over the Compose network.
- Price lookup uses `POST /products/search` on the PosoKanei API host (`https://api.posokanei.gov.gr`) with bounded timeouts and a 24-hour local cache. If there is no usable upstream match, use bundled fixture prices and label them `Approximate local estimate`; do not present a fixture as a live quote.
- The exact live product response and normalized-price units still require validation. Cover parsing and unit conversion with mocked responses; do not make tests depend on PosoKanei availability.
- Nutrition lookup checks bundled per-100g data first and calls Open Food Facts only for ingredients missing from that fixture. Do not silently treat unavailable nutrition as zero.

## Verification
- Lint every modified PHP file with `php -l`.
- Run catalog tests from `services/catalog-api/` with `python -m pytest tests`.
- Run focused tests for changed behavior. Docker/database-backed checks require Docker and an initialized development database; report when those are unavailable rather than claiming integration validation.
