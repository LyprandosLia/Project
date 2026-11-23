# Multi-Agent Food OS Implementation Status

This repository has moved beyond the original PHP/MariaDB demo and now
contains the web application, catalog API, agent pipeline, and review UI.
`ARCHITECTURE.md` is the current source of truth for file inventory, data
flow, schema, deployment, and environment configuration.

## Delivered phases

### Phase 1 — Container foundation, Composer, and database schema

Implemented: the PHP 8.3/Apache, MariaDB 11, and internal FastAPI services;
Composer PSR-4 autoloading; the `public/` entry point; Traefik routing; and
`database/schema.sql` with development seeds. Existing database volumes are
not automatically migrated.

### Phase 2 — FastAPI catalog and nutrition service

Implemented: typed price/nutrition endpoints, unit conversion, fixture
fallback, source/freshness metadata, a 24-hour price cache, an updater, and
Open Food Facts lookup for nutrition items absent from local fixtures.
PosoKanei lookup uses its product-search API host and falls back to local
prices labeled **Approximate local estimate**. The live response shape and
normalized-price semantics still need production verification.

### Phase 3 — Data access, DTOs, LLM boundary, and Agents 1–3

Implemented: typed request/profile/pantry/meal/shopping DTOs; PDO
repositories; a structured-JSON LLM boundary with deterministic mock mode;
recipe filtering and meal schedule validation; recipe scaling; and pantry-aware
shopping-list aggregation and pricing.

### Phase 4 — Agent 4, orchestration, and review/approval UI

Implemented: nutrition and dietary validation; a maximum of three total
planning attempts; feedback passed back to the planner; profile and pantry
editing; meal-slot, serving, and shopping-mode inputs; session-held plan
review; and an approval gate that prevents rejected or unapproved generated
plans from being written to `meal_plans`.

## Current behavior and verification still needed

- The demo controller currently operates on user ID 1.
- Dietary-note enforcement recognizes supported terms (vegan, vegetarian,
  gluten-free, dairy-free); arbitrary free-form preferences are not guaranteed
  to be interpreted.
- Fasting checks use approximate times: breakfast 08:00, lunch 13:00, dinner
  19:00.
- The PosoKanei response shape and price-unit conversion should be checked
  against a verified live response. Mocked provider tests and approximate local
  prices are available without upstream access.
- Docker/MariaDB-backed end-to-end checks require Docker and an initialized
  database. Existing data volumes should be backed up before applying schema
  updates manually.
- Deployment currently configures database and site-auth secrets; configure
  `LLM_API_KEY`, `LLM_BASE_URL`, and `LLM_MODEL` in production if provider-backed
  planner responses are wanted.
