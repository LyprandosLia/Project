# Multi-Agent Food OS - Architecture Guide

This document describes the repository as it is implemented now. The PHP
weekly-plan workflow calls the FastAPI price endpoint through Agent 3 and the
nutrition endpoint through Agent 4. Local catalog fixtures provide explicit
approximate-price fallback when PosoKanei has no usable response.

## 1. Complete directory tree

The tree below excludes `.git/` and `vendor/`, but includes the generated
`.pytest_cache/` files present in the workspace.

```text
.
|-- .dockerignore
|-- .env.example
|-- .github/
|   |-- copilot-instructions.md
|   `-- workflows/
|       `-- deploy.yml
|-- .gitignore
|-- ARCHITECTURE.md
|-- PLAN.md
|-- README.md
|-- Dockerfile
|-- cicd_demo.code-workspace
|-- composer.json
|-- docker-compose.yml
|-- app/
|   |-- db.php
|   `-- index.php
|-- bin/
|   `-- test-phase3.php
|-- data/
|   `-- .gitkeep
|-- database/
|   `-- schema.sql
|-- db-init/
|   `-- init.sql
|-- docker/
|   `-- apache-vhost.conf
|-- public/
|   `-- index.php
|-- services/
|   `-- catalog-api/
|       |-- .pytest_cache/
|       |   |-- .gitignore
|       |   |-- CACHEDIR.TAG
|       |   |-- README.md
|       |   `-- v/
|       |       `-- cache/
|       |           |-- lastfailed
|       |           `-- nodeids
|       |-- Dockerfile
|       |-- requirements.txt
|       |-- app/
|       |   |-- __init__.py
|       |   |-- catalog.json
|       |   |-- catalog_store.py
|       |   |-- main.py
|       |   |-- models.py
|       |   |-- nutrition_provider.py
|       |   |-- provider.py
|       |   `-- updater.py
|       `-- tests/
|           |-- test_catalog_api.py
|           `-- test_updater.py
|-- src/
|   |-- Agents/
|   |   |-- MealPlannerAgent.php
|   |   |-- NutritionFeedbackAgent.php
|   |   |-- Orchestrator.php
|   |   |-- RecipeSpecialistAgent.php
|   |   `-- ShoppingOptimizerAgent.php
|   |-- DTO/
|   |   |-- AgentStepLogDTO.php
|   |   |-- MealPlanDTO.php
|   |   |-- MealPlanRequestDTO.php
|   |   |-- MealSelectionDTO.php
|   |   |-- OrchestrationResultDTO.php
|   |   |-- PantryItemDTO.php
|   |   |-- Quantity.php
|   |   |-- RecipeDTO.php
|   |   |-- RecipeIngredientDTO.php
|   |   |-- ScaledIngredientDTO.php
|   |   |-- ScaledRecipeDTO.php
|   |   |-- ShoppingItemDTO.php
|   |   |-- ShoppingListDTO.php
|   |   |-- UserProfileDTO.php
|   |   `-- ValidationResultDTO.php
|   |-- Prompts/
|   |   |-- meal-planner.md
|   |   |-- nutrition-feedback.md
|   |   |-- recipe-specialist.md
|   |   `-- shopping-optimizer.md
|   |-- Repository/
|   |   |-- MealPlanRepository.php
|   |   |-- PantryRepository.php
|   |   |-- RecipeRepository.php
|   |   `-- UserRepository.php
|   `-- Service/
|       |-- CatalogApiClient.php
|       `-- LlmClient.php
`-- views/
    `-- dashboard.php
```

### File inventory and responsibilities

#### PHP application, orchestration, DTOs, and prompts

| File | Responsibility |
|---|---|
| `app/index.php` | Existing server-rendered controller: Basic Auth, CSRF, POST action handling, agent wiring, session-held generated result, approval/discard/rating actions, and dashboard data loading. |
| `app/db.php` | Creates the PDO connection from environment configuration and retains the legacy `items` query/insert functions. |
| `public/index.php` | Apache document-root entry point; delegates the request to `app/index.php`. |
| `views/dashboard.php` | Escaped HTML for profile editing, catalog/custom pantry items, plan generation, trace, shopping, approval, ratings, and the retained legacy items panel. |
| `bin/test-phase3.php` | Standalone database-backed orchestration check; reads the profile for user 1, runs the pipeline, and emits a compact JSON result. |
| `src/Agents/Orchestrator.php` | Agent 0: executes Agents 1-4 in sequence, records trace entries, sends validation feedback to the planner, and caps total attempts at three. |
| `src/Agents/MealPlannerAgent.php` | Agent 1: filters by allergies, supported dietary terms, prep limits, recent/low ratings, fasting windows, and pantry feasibility; generates and validates the requested seven-day slot schedule. |
| `src/Agents/RecipeSpecialistAgent.php` | Agent 2: fetches each selected recipe and deterministically scales its ingredient quantities to target servings. |
| `src/Agents/ShoppingOptimizerAgent.php` | Agent 3: aggregates scaled ingredients, subtracts compatible pantry quantities, requests catalog prices, and returns a shopping list and EUR total. |
| `src/Agents/NutritionFeedbackAgent.php` | Agent 4: checks allergy/dietary conflicts, variety, low ratings, and goals using catalog nutrition data; returns approval status and revision feedback. |
| `src/DTO/MealPlanRequestDTO.php` | Validates the requested start date, selected meal slots, servings, and pantry-only versus purchase-allowed mode. |
| `src/DTO/PantryItemDTO.php` | Typed user pantry row containing its ingredient ID, name, and quantity. |
| `src/DTO/UserProfileDTO.php` | Read-only user identity, allergy, preference, prep-time, and fasting-window data passed to agents. |
| `src/DTO/RecipeDTO.php` | Read-only recipe metadata and typed ingredient collection loaded by the recipe repository. |
| `src/DTO/RecipeIngredientDTO.php` | Ingredient identity/name, quantity, and optional recipe notes. |
| `src/DTO/Quantity.php` | Validates and serializes non-negative decimal quantities using the supported `g`, `kg`, `ml`, `l`, and `piece` units. |
| `src/DTO/MealPlanDTO.php` | Plan identity, date range, and typed meal selections. |
| `src/DTO/MealSelectionDTO.php` | One plan selection with validated date, meal slot, recipe ID, and servings. |
| `src/DTO/ScaledRecipeDTO.php` | Recipe and target-serving metadata plus typed scaled ingredients and an optional alternative note. |
| `src/DTO/ScaledIngredientDTO.php` | One ingredient and its scaled `Quantity`. |
| `src/DTO/ShoppingItemDTO.php` | One required purchase quantity and optional estimated cost, currency, and price source. |
| `src/DTO/ShoppingListDTO.php` | User-specific shopping items and estimated total/currency. |
| `src/DTO/ValidationResultDTO.php` | Agent 4's approval boolean, issues, feedback, and nutrition summary. |
| `src/DTO/AgentStepLogDTO.php` | Trace step identity, action, status, attempt, timestamp, duration, detail, and optional EUR total. |
| `src/DTO/OrchestrationResultDTO.php` | Complete review payload returned by Agent 0: plan, scaled recipes, shopping list, validation, trace, and attempt count. |
| `src/Repository/UserRepository.php` | Reads and transactionally saves profile preferences/allergies; reads recent recipe IDs and ratings and upserts user ratings. |
| `src/Repository/RecipeRepository.php` | Reads recipe records/ingredient rows into DTOs and loads the canonical ingredient list for pantry editing. |
| `src/Repository/PantryRepository.php` | Reads typed inventory; transactionally inserts, updates, or removes stock and creates a catalog ingredient for a new custom pantry item. |
| `src/Repository/MealPlanRepository.php` | Transactionally saves draft/approved plans and loads approved saved meals with ratings. |
| `src/Service/LlmClient.php` | Provider-neutral structured-JSON chat client; uses a deterministic supplied mock when `LLM_API_KEY` is empty. |
| `src/Service/CatalogApiClient.php` | HTTP/JSON client for the internal FastAPI price and nutrition endpoints. |
| `src/Prompts/meal-planner.md` | Planner prompt describing allowed plan structure and user constraints. |
| `src/Prompts/recipe-specialist.md` | Prompt asserting that recipe quantity arithmetic is deterministic application code. |
| `src/Prompts/shopping-optimizer.md` | Prompt asserting that pantry subtraction and price arithmetic are deterministic application code. |
| `src/Prompts/nutrition-feedback.md` | Describes the intended feedback-agent checks and JSON response shape; current PHP validation is local logic rather than an LLM call. |

#### Catalog API, database, deployment, and other files

| File | Responsibility |
|---|---|
| `services/catalog-api/Dockerfile` | Builds the Python 3.12 API image and starts Uvicorn on container port 8000. |
| `services/catalog-api/requirements.txt` | Declares FastAPI, Uvicorn, httpx, and pytest dependencies. |
| `services/catalog-api/app/__init__.py` | Marks `app` as the Python package. |
| `services/catalog-api/app/main.py` | FastAPI health, price, and nutrition routes; performs unit conversion, fixture/provider lookup, and response construction. |
| `services/catalog-api/app/models.py` | Pydantic request/response models and supported-unit declarations for both API families. |
| `services/catalog-api/app/catalog_store.py` | Loads fixture data, normalizes ingredient names, reads/writes the price cache, and supplies source/freshness metadata. |
| `services/catalog-api/app/provider.py` | Posts bounded-time product searches to PosoKanei's JSON endpoint, normalizes comparable unit prices, and rejects incomplete matches for fixture fallback. |
| `services/catalog-api/app/nutrition_provider.py` | Queries Open Food Facts for per-100g nutrients and normalizes its response. |
| `services/catalog-api/app/updater.py` | Command-line updater that validates provider records and atomically replaces the fixture when data changes. |
| `services/catalog-api/app/catalog.json` | Local EUR price and per-100g nutrition fixtures used as fallback data. |
| `services/catalog-api/tests/test_catalog_api.py` | API tests for health, fixture price/nutrition lookups, not-found ingredients, and invalid input. |
| `services/catalog-api/tests/test_provider.py` | Mocked provider tests for the PosoKanei search request and package-to-base-unit price conversion. |
| `services/catalog-api/tests/test_updater.py` | Updater test using a fixture provider to exercise update and failure reporting. |
| `services/catalog-api/.pytest_cache/.gitignore` | Generated pytest cache metadata, not application code. |
| `services/catalog-api/.pytest_cache/CACHEDIR.TAG` | Generated marker identifying the pytest cache directory. |
| `services/catalog-api/.pytest_cache/README.md` | Generated pytest cache note. |
| `services/catalog-api/.pytest_cache/v/cache/lastfailed` | Generated pytest record of tests that last failed. |
| `services/catalog-api/.pytest_cache/v/cache/nodeids` | Generated pytest list of discovered test node IDs. |
| `database/schema.sql` | Current MariaDB schema and development seed data; mounted as the MariaDB first-initialization script. |
| `db-init/init.sql` | Legacy `items`-only initialization script; it is not the SQL file mounted by the current Compose configuration. |
| `.github/workflows/deploy.yml` | On pushes to `main`, syncs files to `/srv/lyp`, writes a server `.env` from GitHub Secrets, then rebuilds/restarts Compose. |
| `.github/copilot-instructions.md` | Workspace guidance; portions describe the earlier PHP/MariaDB demo and are stale relative to the current source and Compose configuration. |
| `Dockerfile` | Builds the PHP 8.3 Apache web image, installs PDO MySQL and Composer dependencies, and sets Apache's vhost. |
| `docker/apache-vhost.conf` | Sets `public/` as Apache's document root and directs logs to container streams. |
| `docker-compose.yml` | Defines web, catalog API, and MariaDB services; networks, health checks, environment, Traefik labels, and persistent database storage. |
| `.env.example` | Documents local configuration keys and example/default values without holding real credentials. |
| `composer.json` | Declares PHP requirements and the `App\\` to `src/` PSR-4 autoload mapping. |
| `.dockerignore` | Excludes local environment, repository metadata, data, and Python cache artifacts from Docker build contexts. |
| `.gitignore` | Excludes the real `.env`, database contents, and local deployment key files from Git. |
| `README.md` | Setup, schema persistence, catalog API, deployment, and weekly-plan operation notes. |
| `PLAN.md` | Phased project plan; use implementation files as the source of truth where its aspirational statements differ from current behavior. |
| `cicd_demo.code-workspace` | VS Code workspace configuration for opening this repository. |
| `data/.gitkeep` | Keeps the otherwise ignored MariaDB persistence directory present in the repository. |
| `ARCHITECTURE.md` | This system overview and file inventory. |

## 2. System architecture and infrastructure

### Services and network boundaries

The Compose project defines three application containers:

1. **`web`** builds from the root `Dockerfile`, runs PHP 8.3 with Apache, Composer autoloading, and PDO MySQL. Apache serves `public/`; that entry point forwards to `app/index.php`. PHP connects to MariaDB using the Compose DNS name `db` and calls the catalog service using `http://catalog-api:8000`.
2. **`catalog-api`** builds from `services/catalog-api/Dockerfile`, runs FastAPI/Uvicorn on port 8000 inside the container, and serves `/health`, price, and nutrition endpoints. It can make outbound HTTP requests to PosoKanei and Open Food Facts.
3. **`db`** runs MariaDB 11, stores data under the host's `./data/` directory, and loads `database/schema.sql` only when MariaDB initializes an empty data directory.

The `web` service joins both the external `traefik` network and the Compose
`internal` bridge network. `catalog-api` and `db` join only `internal`. No
service has a published `ports:` mapping; neither MariaDB nor FastAPI is
directly exposed to the host through Compose. The API has a health check, as
does the database, and `web` waits for both to become healthy before starting.

Traefik is external to this Compose file. The `web` labels register a router
matching `lyp.nkyriopoulos.com` on the `websecure` entrypoint, enable TLS with
the configured `le` certificate resolver, and direct Traefik to the web
container's port 80. Thus the intended public route is:

```text
Browser -- HTTPS --> external Traefik -- HTTP on traefik network --> web:80
web -- HTTP/JSON on internal network --> catalog-api:8000
web -- MySQL on internal network --> db:3306
catalog-api -- outbound HTTPS --> PosoKanei / Open Food Facts
```

The database is not attached to the Traefik network and has no host port.
FastAPI likewise has no host port. Only the PHP web container participates in
the public reverse-proxy route.

### Build and deployment

The `web` image installs Composer dependencies and PDO/PDO MySQL. The catalog
image installs the pinned-range Python requirements and starts Uvicorn.
`docker-compose.yml` mounts `./database/schema.sql` read-only into
MariaDB's initialization directory and persists the actual database in
`./data/`.

The GitHub Actions workflow runs for pushes to `main`. It uses
`burnett01/rsync-deployments` to sync the workspace to
`deploy.nkyriopoulos.com:/srv/lyp/`, excluding `.git`, `.github`, `data/`, and
`.env` (and using rsync `--delete` for other destination files). SSH connects as
`lyp` using the `SSH_PRIVATE_KEY` secret. The workflow then writes
`/srv/lyp/.env` from its configured secrets and runs
`docker compose down --remove-orphans` followed by
`docker compose up -d --build --remove-orphans`.

The workflow-generated `.env` currently includes only site and database
credentials. It does not write catalog URL or LLM configuration; Compose
defaults cover the catalog URLs, while the absent `LLM_API_KEY` selects
MealPlanner mock mode.

### Persistent state and initialization caveat

MariaDB initialization SQL is applied only to an empty data directory. Existing
`./data/` databases are not migrated automatically. The rsync deployment
excludes `data/`, preserving the deployed database across releases.
`db-init/init.sql` is a leftover `items`-only seed script and is not mounted by
the current Compose file; `database/schema.sql` is the active initializer.

The catalog's fixture file is part of the image. `catalog_store.py` also writes
a 24-hour price cache next to the fixture. The documented updater writes the
fixture path inside the container; running it with `docker compose run --rm`
does not persist those changes back into the image or a mounted host file.

## 3. End-to-end multi-agent workflow

### Generate Weekly Plan

1. The browser submits `action=generate`, a start date, selected meal slots,
   servings, purchase mode, and the session CSRF token. `app/index.php` checks
   Basic Auth and CSRF, then validates the fields into a `MealPlanRequestDTO`.
   The current demo controller uses `userId = 1`.
2. The controller calls `UserRepository::findProfile(1)`, the first planning
   database access. It reads `users`, `user_preferences`, and
   `user_allergies` into a `UserProfileDTO`; it also reads the user's pantry
   inventory before invoking the orchestrator.
3. The controller calls `Orchestrator::run(UserProfileDTO, MealPlanRequestDTO)`.
   Agent 0 begins attempt 1 and retains the profile, request, and pantry
   inventory as input to the planning loop.
4. **Agent 1 - MealPlanner** calls `RecipeRepository::findAll()` for recipes
   and ingredient DTOs, then `UserRepository::findRecentRecipeIds()`. It
   filters by allergies, supported dietary terms, prep limits, recent meals,
   meal-slot timing, and pantry feasibility when pantry-only mode is selected.
   It passes profile goals/preferences, the chosen meal slots, servings,
   shopping mode, recipes, and any earlier nutrition feedback to the planner
   and validates the returned `MealPlanDTO` schedule.
5. **Agent 2 - RecipeSpecialist** receives each `MealSelectionDTO` and the
   user's prep limit. For each selection, it queries
   `RecipeRepository::findById()`, scales ingredient quantities for servings,
   and returns a `ScaledRecipeDTO` with typed ingredient quantities.
6. **Agent 3 - ShoppingOptimizer** receives the user ID and
   `ScaledRecipeDTO[]`. It aggregates ingredients, queries current pantry
   quantities, subtracts compatible stock, then calls `CatalogApiClient` for
   each remaining purchase. The client posts ingredient/quantity/unit JSON to
   FastAPI `/prices`. FastAPI checks the 24-hour price cache, posts product
   search requests to PosoKanei, and falls back to the local catalog on
   upstream failure or no comparable item. Fixture rows are labeled
   **Approximate local estimate**. The resulting `ShoppingListDTO` contains
   purchase quantities, optional item prices and source labels, and a total.
7. **Agent 4 - NutritionFeedback** receives `UserProfileDTO`, `MealPlanDTO`,
   and `ScaledRecipeDTO[]`; it queries saved ratings and the FastAPI nutrition
   endpoint for recipe ingredients. It validates calorie/protein goals and
   supported dietary terms, returning a `ValidationResultDTO` with approval,
   issues, feedback, and nutrition summary.
8. Agent 0 records an `AgentStepLogDTO` for each agent and attempt. If
   validation rejects the plan, it passes the feedback string to Agent 1 and
   reruns the four agents. The total attempt limit is three, not three retries
   in addition to the first attempt. On the third rejection, Agent 0 returns
   the last plan with `validation.approved = false`; it does not start a
   fourth attempt.
9. The resulting `OrchestrationResultDTO` is saved in the PHP session, not in
   `meal_plans`. The dashboard renders the final trace, plan, scaled
   ingredients, nutrition summary, shopping list, and validation status after
   the run completes. It is not a streaming/live-progress trace.

### Data passed between agents

| Stage | Input | Output |
|---|---|---|
| Agent 0 / `Orchestrator::run` | `UserProfileDTO`, `MealPlanRequestDTO` (start date, slots, servings, shopping mode), pantry items | `OrchestrationResultDTO` containing plan, scaled recipes, shopping list, validation, trace, and attempts |
| Agent 1 / `MealPlannerAgent::propose` | `UserProfileDTO`, request DTO, pantry items, optional prior feedback | `MealPlanDTO` with one `MealSelectionDTO` per selected day/slot |
| Agent 2 / `RecipeSpecialistAgent::scale` | One `MealSelectionDTO`, optional prep limit | One `ScaledRecipeDTO` with typed scaled ingredient quantities |
| Agent 3 / `ShoppingOptimizerAgent::optimize` | User ID and `ScaledRecipeDTO[]` | `ShoppingListDTO` with `ShoppingItemDTO[]` and EUR total |
| Agent 4 / `NutritionFeedbackAgent::validate` | `UserProfileDTO`, `MealPlanDTO`, `ScaledRecipeDTO[]`; catalog nutrition responses | `ValidationResultDTO` with decision, issue list, feedback, and summary |

DTOs are immutable and validate their main data shapes at construction or
decoding boundaries. Provider output is decoded and checked by `LlmClient` and
the plan DTO; selected recipe IDs are additionally checked against the
planner's allowed recipe set.

### What the catalog and nutrition boundary currently does

The PHP shopping workflow calls FastAPI's **price** endpoint. FastAPI checks a
24-hour price cache, posts a product search to
`https://api.posokanei.gov.gr/products/search`, and falls back to local catalog
prices when the upstream request fails or has no matching comparable product.
Local estimates are labeled `Approximate local estimate`; a live quote is
identified as `posokanei.gov.gr`. The upstream response contract and price-unit
semantics have not been independently validated against a live response, so
verify these before relying on live estimates.

Nutrition lookup uses local fixture data first and calls Open Food Facts for
ingredients absent from the fixture. Agent 4 calls the catalog nutrition
endpoint for recipe ingredients and rejects incomplete lookups rather than
silently treating missing nutrition as zero. The dashboard's generated
shopping list shows the source for every available price; missing quotes are
displayed as unavailable.

### Approve & Save Plan and ratings

During generation, MariaDB is read for profile, recipes, recent meals, pantry,
and ratings, but the generation action does not write `meal_plans` or
`planned_meals`. The generated DTO stays in the server session.

On **Approve & Save Plan**, the controller retrieves that session result and
calls `MealPlanRepository::saveApproved()`. That method begins a transaction,
inserts one `meal_plans` row with status `approved`, inserts its
`planned_meals` rows, and commits; any thrown error rolls the transaction back.
**Discard** simply removes the session result. Rating forms update the
`ratings` table separately.

The approval button is currently still available when Agent 4 rejected the
plan after three attempts. The rejection is shown as a warning, but the
controller does not block saving such a result; treat this as an explicit
human-override behavior to confirm.

## 4. Database schema and environment variables

### MariaDB tables

`database/schema.sql` creates the following tables and development seed data.
The table list also shows how the current application reads or writes each
table.

| Table | Purpose | Current readers/writers |
|---|---|---|
| `items` | Legacy demo item name/note rows. | `app/db.php`: `get_items()` reads; `add_item()` inserts. |
| `users` | User identity and display name. | `UserRepository::findProfile()` reads; schema seeds demo user 1. |
| `user_preferences` | Calorie/protein targets, prep limit, fasting times, and dietary notes. | `UserRepository::findProfile()` reads and `saveProfile()` transactionally writes; schema seeds demo settings. |
| `user_allergies` | Per-user allergy terms. | `UserRepository::findProfile()` reads and `saveProfile()` replaces a user's allergy rows; schema seeds a development allergy. |
| `ingredients` | Canonical ingredient names and units. | `RecipeRepository` and `PantryRepository` join it for names; schema seeds standard ingredients and `PantryRepository::saveNamedQuantity()` inserts a missing custom ingredient. |
| `recipes` | Recipe descriptions, prep time, base servings, and instructions. | `RecipeRepository` reads; schema seeds three recipes. |
| `recipe_ingredients` | Recipe-to-ingredient quantities and units. | `RecipeRepository::findIngredients()` reads; schema seeds recipe contents. |
| `pantry_inventory` | User-owned ingredient amounts and units. | `PantryRepository` reads inventory and transactionally inserts, updates, or removes it; schema seeds demo inventory. |
| `meal_plans` | Plan owner, date range, and `draft`/`approved` state. | `MealPlanRepository::saveDraft()` and `saveApproved()` write; `findSavedMeals()` and `UserRepository::findRecentRecipeIds()` read through joins. |
| `planned_meals` | Meal selections linked to a plan and recipe. | `MealPlanRepository` inserts on save; saved-meal and recent-history queries read it. |
| `ratings` | One 1-5 rating per user/recipe, optional comment. | `UserRepository::findRatingsByUser()` and `MealPlanRepository::findSavedMeals()` read; `UserRepository::saveRating()` inserts or updates. |

The initial schema has foreign keys, uniqueness constraints, indexes, and a
rating range check. The development seed includes user ID 1, preferences,
allergies, recipes, ingredients, and pantry stock. The first-run schema does
not automatically alter an already-populated MariaDB data directory.

### Application environment variables

| Variable | Consumer and behavior |
|---|---|
| `SITE_USERNAME`, `SITE_PASSWORD` | PHP Basic Auth credentials in `app/index.php`; empty/unset values prevent successful login. |
| `DB_HOST` | PHP PDO host; defaults to `db` in `app/db.php`. |
| `DB_NAME`, `DB_USER`, `DB_PASSWORD` | PHP PDO connection and MariaDB database/user credentials. |
| `DB_ROOT_PASSWORD` | MariaDB root credential passed by Compose. |
| `CATALOG_API_URL` | PHP-to-FastAPI base URL; Compose defaults to `http://catalog-api:8000`. |
| `CATALOG_PRICE_SOURCE_URL` | Python provider API base URL or full `/products/search` URL; defaults to `https://api.posokanei.gov.gr`. |
| `OPEN_FOOD_FACTS_BASE_URL` | Python nutrition provider's configurable Open Food Facts base URL; defaults to `https://world.openfoodfacts.org`. |
| `LLM_API_KEY` | When empty, `LlmClient` returns the planner's supplied deterministic mock response. When set, the client makes a bearer-authenticated structured JSON chat request. |
| `LLM_BASE_URL` | Required with a non-empty `LLM_API_KEY`; the client appends `/chat/completions`. |
| `LLM_MODEL` | Required with a non-empty `LLM_API_KEY`; selects the provider model. |

With `LLM_API_KEY` set but either `LLM_BASE_URL` or `LLM_MODEL` missing, plan
generation throws an explicit configuration error. A provider response must
contain a non-empty JSON object at `choices[0].message.content` and satisfy
the plan DTO/allowed-recipe checks. The LLM is used by MealPlanner; Agent 4
uses deterministic validation rules and catalog nutrition data, not an LLM.

### GitHub Actions Secrets

| Secret | Workflow use |
|---|---|
| `SSH_PRIVATE_KEY` | SSH authentication for rsync and remote commands. |
| `SITE_USERNAME`, `SITE_PASSWORD` | Written into `/srv/lyp/.env` for PHP Basic Auth. |
| `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_ROOT_PASSWORD` | Written into `/srv/lyp/.env` for MariaDB and PHP. |

The workflow does not currently define secrets for `LLM_API_KEY`, `LLM_BASE_URL`,
or `LLM_MODEL`; in the deployed workflow as written, the LLM key is absent and
the planner uses mock mode. Catalog URLs use Compose defaults unless supplied
through some other deployment configuration.

### Configuration and behavior checks before production

- Verify deployment has the desired LLM mode and, if provider mode is wanted,
  add all three LLM variables to the server environment and configure how the
  workflow supplies them.
- Confirm production MariaDB has the tables in `database/schema.sql`; the
  Compose mount does not migrate a non-empty existing database.
- Verify that Agent-4-rejected plans remain blocked both in the UI and in the
  server-side approval action.
- Dietary-note enforcement is intentionally limited to supported terms
  (vegan, vegetarian, gluten-free, dairy-free); arbitrary free text is not
  guaranteed to be interpreted. Fasting checks assume breakfast at 08:00,
  lunch at 13:00, and dinner at 19:00.
- Users can add custom pantry names, but new names do not create recipes. A
  custom item only affects recipe selection or shopping subtraction when it
  matches an ingredient already used by a saved recipe.
- The PosoKanei response shape and whether `price_normalized` is per kg/litre
  still need a live integration check. Local fixture fallback is explicitly
  approximate. Catalog failures can leave items with unavailable prices, and
  the displayed total sums only available item prices.
- `UserRepository::findRecentRecipeIds()` does not filter plan status, so
  recent meals from any stored plan status can influence planner ordering.
- The test-phase script requires DB connectivity and a seeded user 1.
  `services/catalog-api/tests/` contains focused API/updater tests.
- `.pytest_cache/` is generated test output and should not be treated as
  application source; consider excluding it from version control if it is not
  already ignored by the repository's global Git configuration.
