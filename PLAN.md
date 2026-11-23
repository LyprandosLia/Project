# Multi-Agent Food OS Implementation Plan

## Workspace assessment

The repository is currently a PHP 8.3/Apache and MariaDB demo, not yet the meal-planning application. `Dockerfile` builds from `php:8.3-apache`; `docker-compose.yml` defines `web` and `db` services, with Traefik labels, an internal database network, and a persistent `./data` mount. The PHP page and PDO helper are in `app/`. MariaDB's current first-run schema and three demo rows are in `db-init/init.sql`. `.env.example` exists, but there is no Composer setup, `public/`, `src/`, or FastAPI service yet.

The existing Compose service names and Traefik routing should be preserved where practical. The current database volume is persistent: MariaDB initialization scripts run only on a fresh data directory, so the new schema must not be applied by deleting or silently replacing existing database data.

## Phase 1 — Container foundation, Composer, and database schema

1. **Agree on the service boundary and preserve deployment behavior.**
   - Keep the existing PHP/Apache and MariaDB services and Traefik routing; add an internal-only FastAPI service.
   - Keep MariaDB off the public/Traefik network. Make the catalog API reachable to PHP over the internal Compose network, not through a public port.
   - Set Apache's document root to `public/`, retaining the existing deployment entry point and documenting the local startup flow.
   - Keep `app/index.php` and `app/db.php` unchanged; `public/index.php` delegates to the existing index, and the schema retains its `items` table so the existing page continues to work.
2. **Add the PHP project skeleton and Composer autoloading.**
   - Define Composer's `App\\` PSR-4 mapping to `src/`.
   - Configure the PHP image and Compose build to include the Composer autoloader and PHP PDO/MySQL support.
   - Add only a minimal web/health entry point at this stage; the application UI and agent logic belong to Phase 4 and Phase 3 respectively.
3. **Configure environment variables without adding secrets.**
   - Update `.env.example` for the database, PHP-to-catalog API URL, and later LLM configuration.
   - Keep `.env` local and untracked; document copying the example and replacing local credentials. Do not put real API keys or production passwords in the repository.
4. **Create the initial relational schema and sample data.**
   - Add `database/schema.sql` with keys, foreign keys, useful uniqueness constraints, and indexes for users/preferences/allergies, ingredients, recipes and recipe ingredients, pantry inventory, meal plans/planned meals, and ratings.
   - Seed a clearly identified development user, a small recipe/ingredient set, and pantry items that can exercise ingredient reuse and pantry subtraction.
   - Point fresh MariaDB initialization at this schema. Document that existing `./data` volumes are not reinitialized and require an explicit, backed-up migration; never instruct users to delete persistent data as a migration step.
5. **Make the API service runnable without implementing catalog features yet.**
   - Add a small FastAPI app exposing a health endpoint so all three services can be built and started and PHP-to-API networking can be checked.
   - Defer price/nutrition contracts, catalog persistence, and updater behavior to Phase 2.
6. **Verify the foundation.**
   - Validate Compose configuration and build all three images.
   - Start the stack and check the PHP page, FastAPI health response, MariaDB readiness, and seeded rows.
   - Confirm PHP can resolve/reach the API by service name, and confirm neither MariaDB nor FastAPI is published publicly.

### Exact new files planned for Phase 1

- `.dockerignore`
- `docker/apache-vhost.conf`
- `composer.json`
- `database/schema.sql`
- `public/index.php`
- `services/catalog-api/Dockerfile`
- `services/catalog-api/requirements.txt`
- `services/catalog-api/app/main.py`

### Existing files planned for update in Phase 1

- `Dockerfile` — Composer/autoload and Apache document-root configuration.
- `docker-compose.yml` — add the catalog service and wire internal networking, environment, and health/dependency checks while preserving applicable deployment routing.
- `.env.example` — document the new service configuration; no real `.env` will be committed.
- `README.md` — replace demo-only startup/schema notes with the new setup and safe existing-volume guidance.

No application files will be changed until this plan is approved. The real `.env` is a local, ignored copy of `.env.example`, not a tracked deliverable.

## Phase 2 — FastAPI catalog and nutrition service

1. Define typed request/response models and stable JSON contracts for supermarket price lookup and nutrition lookup; include units, currency/source, and freshness metadata where applicable.
2. Implement the price and nutrition endpoints with input validation, clear not-found/upstream-error behavior, and tests for success and invalid/missing data.
3. Add a background catalog updater that can be run independently, updates data idempotently, reports failures, and does not block API startup. Keep external supermarket integration configurable rather than embedding credentials or assuming a specific provider.
4. Add focused API tests using local fixtures or mocked upstream responses, plus a service-level check from the PHP container.
5. Verify the API contract and updater behavior without requiring live supermarket credentials.

## Phase 3 — Data access, DTOs, LLM boundary, and Agents 1–3

1. Add typed, immutable DTOs for user constraints, plan requests, meal selections, scaled recipes, shopping items, and agent handoffs. Validate decoded external/LLM data at the boundary; do not pass loose associative arrays between agents.
2. Implement MariaDB PDO repositories for users/preferences/allergies, recipe and ingredient data, pantry, plans, and ratings. Use prepared statements, native prepares, exception mode, and explicit return types.
3. Add a provider-neutral LLM HTTP client with configuration from environment, timeouts, explicit errors, and strict structured-output decoding. Keep deterministic calculations out of the LLM.
4. Implement Agent 1 (MealPlanner): apply profile, allergy, fasting, and recent-history constraints; prefer ingredient reuse; return dated meal selections and target servings.
5. Implement Agent 2 (RecipeSpecialist): fetch recipe data, deterministically scale ingredient quantities, validate supported units, enforce prep-time limits, and return explicit alternatives where available.
6. Implement Agent 3 (ShoppingOptimizer): aggregate scaled ingredients, subtract pantry stock with unit-aware arithmetic, query the catalog API, and return item/quantity/unit/estimated-cost results.
7. Add focused unit and repository/service integration tests for constraints, scaling and rounding, unsupported unit combinations, pantry subtraction, aggregation, and catalog failures. Verify money/quantity arithmetic is deterministic.

## Phase 4 — Agent 4, orchestration, and review/approval UI

1. Implement Agent 4 (NutritionFeedback): compare plan nutrition to configured goals, enforce dietary rules, flag repetition, and use ratings to produce explicit preference-update suggestions rather than silently changing the profile.
2. Implement Agent 0 (Orchestrator): parse the request type, load profile and history, pass typed DTOs through Agents 1–4 in sequence, and return a reviewable result.
3. Add a bounded repair loop: allow at most three repair attempts when Agent 4 reports correctable issues; stop with a clear validation result if issues remain. Test zero, successful, and exhausted-retry paths, including that no fourth retry occurs.
4. Build the server-rendered PHP views for the weekly plan board, pantry management, shopping list, and final plan review/approval. Require explicit user approval before treating a generated plan as accepted.
5. Add request/response validation, output escaping, authorization/session protections, and error states appropriate to the existing PHP deployment.
6. Run focused end-to-end checks for weekly-plan generation, single-recipe scaling, shopping-list generation, pantry subtraction, nutrition feedback, and approval persistence; then document operational setup and known provider/configuration requirements.

## Approval gate

This task creates only this plan. Phase 1 will begin only after the user approves the plan; any requested changes to scope or the Phase 1 file list should be settled before implementation.
