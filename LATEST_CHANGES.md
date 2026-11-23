# Latest Changes

This note summarizes the current Multi-Agent Food OS implementation changes.
For the full system map and database/environment reference, see
[ARCHITECTURE.md](./ARCHITECTURE.md).

## What changed

### Personalized planning and pantry controls

- The dashboard now lets the demo user save calorie and protein targets,
  allergies, dietary notes, fasting windows, and maximum preparation time.
- Users can add, update, and remove pantry quantities. The add form selects
  the ingredient's canonical unit by default.
- Weekly generation accepts a start date, one or more meal slots (breakfast,
  lunch, and/or dinner), servings, and a pantry-only or purchase-allowed mode.
- The result page shows the proposed meals, scaled ingredients, nutrition
  summary, agent trace, shopping list, price source, and approval/discard
  actions.
- A generated plan remains in the session until approved or discarded.
  Generation does not write to `meal_plans`; plans rejected by Agent 4 are
  blocked from saving.

### Agent flow

1. `MealPlanRequestDTO` validates the date, selected slots, servings, and
   purchase mode; `UserProfileDTO` carries the user's saved constraints.
2. Agent 0 loads pantry inventory and runs a maximum of three total attempts.
3. Agent 1 filters recipes for allergies, supported dietary terms, prep
   limits, recent/low ratings, fasting-window conflicts, and pantry
   feasibility when pantry-only mode is selected. It validates that the
   requested seven-day schedule and servings are returned.
4. Agent 2 scales recipe ingredient quantities for the requested servings.
5. Agent 3 aggregates ingredients, subtracts compatible pantry quantities,
   and prices remaining purchases through the catalog API.
6. Agent 4 uses catalog nutrition data to check goals and supported dietary
   terms. Its feedback is passed to the planner on another attempt when needed.
7. Only a validated plan can pass the human approval gate and be persisted.

Fasting checks assume breakfast at 08:00, lunch at 13:00, and dinner at
19:00. Dietary-note enforcement recognizes supported terms such as vegan,
vegetarian, gluten-free, and dairy-free; arbitrary free-form preferences are
not guaranteed to be interpreted.

### Price-source behavior

- Price lookup now posts product-search requests to
  `https://api.posokanei.gov.gr/products/search`, uses a 24-hour price cache,
  and falls back to local catalog entries if no usable comparable product is
  available.
- Local fixture prices are labeled **Approximate local estimate** in the UI;
  unavailable prices are not included in the estimated total.
- `CATALOG_PRICE_SOURCE_URL` remains the configuration variable; its default
  changed to `https://api.posokanei.gov.gr`. No new environment variable was
  added.
- The actual live API response shape and normalized-price unit semantics have
  not been verified against a live response. Do not treat mocked tests as
  proof of a live quote.

## Files changed

### Added

| File | Responsibility |
|---|---|
| `src/DTO/MealPlanRequestDTO.php` | Validates weekly plan input: date, meal slots, servings, and shopping mode. |
| `src/DTO/PantryItemDTO.php` | Represents a typed pantry inventory item. |
| `services/catalog-api/tests/test_provider.py` | Tests PosoKanei request construction and package-to-base-unit price conversion with mocked responses. |

### Updated

| File | Summary |
|---|---|
| `.env.example` | Points the configurable price source at the PosoKanei API host. |
| `docker-compose.yml` | Uses the API host as the catalog service's default price source. |
| `services/catalog-api/app/provider.py` | Searches PosoKanei's JSON endpoint, parses comparable prices/units, and rejects incomplete matches so fallback can run. |
| `services/catalog-api/app/catalog.json` | Labels bundled prices as approximate local estimates. |
| `services/catalog-api/tests/test_catalog_api.py` | Exercises deterministic fixture fallback without relying on upstream availability. |
| `app/index.php` | Handles profile/pantry saves, validates generation inputs, wires the updated agents, and enforces the approval gate. |
| `views/dashboard.php` | Adds profile, pantry, meal-slot, serving, shopping-choice, shopping-list, and estimate-source UI. |
| `src/DTO/UserProfileDTO.php` | Validates profile goals, preferences, allergies, prep limits, and fasting values. |
| `src/Repository/UserRepository.php` | Saves preferences and allergies transactionally and loads normalized profile values. |
| `src/Repository/PantryRepository.php` | Reads typed pantry items and transactionally adds, updates, or removes stock. |
| `src/Repository/RecipeRepository.php` | Provides the canonical ingredient list for pantry editing and serializes recipe ingredients. |
| `src/Agents/MealPlannerAgent.php` | Applies profile and pantry constraints; validates schedule, servings, and pantry-only feasibility. |
| `src/Agents/Orchestrator.php` | Passes the typed request and pantry through a maximum-three-attempt agent loop. |
| `src/Agents/NutritionFeedbackAgent.php` | Uses catalog nutrition data and deterministic dietary, repetition, rating, and target checks. |
| `bin/test-phase3.php` | Uses the updated request and agent interfaces. |
| `src/Prompts/meal-planner.md` | Documents the expanded planner inputs and expected schedule constraints. |
| `src/Prompts/nutrition-feedback.md` | Documents nutrition-feedback expectations. |
| `src/Prompts/shopping-optimizer.md` | Clarifies deterministic pantry subtraction and price arithmetic. |
| `README.md` | Documents the planning UI, price fallback, and current agent workflow. |
| `ARCHITECTURE.md` | Updates file inventory, workflow, schema responsibilities, price-source behavior, and limitations. |
| `PLAN.md` | Replaces the obsolete pre-implementation plan status with current delivery and remaining verification needs. |
| `.github/copilot-instructions.md` | Replaces stale demo-only guidance with the current architecture and validation practices. |

## Validation and remaining checks

- Catalog tests: `10 passed`; one upstream Starlette/httpx deprecation warning.
- PHP syntax checks: passed for the PHP source files.
- `MealPlanRequestDTO` smoke test and `git diff --check`: passed.
- Docker/MariaDB-backed integration and live PosoKanei response validation were
  not performed. Verify those before relying on database behavior or live
  supermarket prices in production.
