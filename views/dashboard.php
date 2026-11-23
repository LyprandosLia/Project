<?php

declare(strict_types=1);

/** @var array $items */
/** @var array $savedMeals */
/** @var array $ingredientCatalog */
/** @var array $pantryItems */
/** @var string $csrfToken */
/** @var string|null $error */
/** @var string|null $success */
/** @var App\DTO\UserProfileDTO $profile */
/** @var App\DTO\OrchestrationResultDTO|null $generatedResult */

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$supportedUnits = ['g', 'kg', 'ml', 'l', 'piece'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Multi-Agent Food OS</title>
  <style>
    body { margin: 0; background: #f6f7fb; color: #172033; font-family: system-ui, sans-serif; }
    main { max-width: 1100px; margin: 0 auto; padding: 24px 16px 48px; }
    h1,h2,h3 { margin-top: 0; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(280px,1fr)); gap: 16px; }
    .card { background: white; border: 1px solid #dde3ed; border-radius: 10px; padding: 16px; margin-bottom: 16px; }
    .trace { border-left: 4px solid #94a3b8; padding-left: 12px; margin: 10px 0; }
    .trace.approved { border-color: #16a34a; }
    .trace.rejected { border-color: #dc2626; }
    .status { padding: 3px 8px; border-radius: 999px; background: #eef2ff; font-size: 12px; }
    .error { color: #991b1b; background: #fee2e2; border: 1px solid #fecaca; padding: 10px; border-radius: 8px; }
    .success { color: #166534; background: #dcfce7; border: 1px solid #bbf7d0; padding: 10px; border-radius: 8px; }
    table { width: 100%; border-collapse: collapse; }
    th,td { text-align: left; border-bottom: 1px solid #e5e7eb; padding: 8px; vertical-align: top; }
    input,textarea,select,button { box-sizing: border-box; padding: 8px; }
    label { display: inline-flex; flex-direction: column; gap: 5px; margin: 6px 12px 6px 0; }
    .choices { display: flex; flex-wrap: wrap; gap: 12px; margin: 10px 0; }
    .choices label { flex-direction: row; align-items: center; }
    .hint { color: #475569; font-size: 14px; }
    .pantry-form { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
    button { border: 0; border-radius: 6px; background: #2563eb; color: white; cursor: pointer; }
    button.secondary { background: #64748b; }
    button.danger { background: #dc2626; }
    form.inline { display: inline; }
  </style>
</head>
<body>
<main>
  <h1>Multi-Agent Food OS</h1>
  <?php if ($error !== null): ?><p class="error"><?= $escape($error) ?></p><?php endif; ?>
  <?php if ($success !== null): ?><p class="success"><?= $escape($success) ?></p><?php endif; ?>

  <section class="card">
    <h2>Your goals and food preferences</h2>
    <p class="hint">These settings are saved to your user profile and used each time you generate a plan.</p>
    <p class="hint">Fasting checks use approximate meal times: breakfast 08:00, lunch 13:00, and dinner 19:00. Dietary restrictions are enforced for supported terms such as vegan, vegetarian, gluten-free, and dairy-free.</p>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
      <input type="hidden" name="action" value="save_profile">
      <div class="grid">
        <label>Daily calorie goal (kcal)
          <input type="number" name="daily_calorie_goal" min="500" max="10000" step="1"
                 value="<?= $escape((string) ($profile->preferences['daily_calorie_goal'] ?? '')) ?>">
        </label>
        <label>Daily protein goal (g)
          <input type="number" name="daily_protein_goal_g" min="1" max="1000" step="0.1"
                 value="<?= $escape((string) ($profile->preferences['daily_protein_goal_g'] ?? '')) ?>">
        </label>
        <label>Maximum preparation time (minutes)
          <input type="number" name="max_prep_minutes" min="1" max="600" step="1"
                 value="<?= $escape((string) ($profile->maxPrepMinutes ?? '')) ?>">
        </label>
        <label>Fasting starts
          <input type="time" name="fasting_start" value="<?= $escape($profile->fastingStart ?? '') ?>">
        </label>
        <label>Fasting ends
          <input type="time" name="fasting_end" value="<?= $escape($profile->fastingEnd ?? '') ?>">
        </label>
      </div>
      <label>Allergies (separate with commas or new lines)
        <textarea name="allergies" rows="3" placeholder="For example: peanuts, shellfish"><?= $escape(implode(', ', $profile->allergies)) ?></textarea>
      </label>
      <label>Dietary restrictions and preferences
        <textarea name="dietary_notes" rows="3" maxlength="2000"
                  placeholder="For example: vegan, vegetarian, gluten-free, dairy-free; other preferences"><?= $escape((string) ($profile->preferences['dietary_notes'] ?? '')) ?></textarea>
      </label>
      <button type="submit">Save Profile</button>
    </form>
  </section>

  <section class="card">
    <h2>Your Pantry</h2>
    <p class="hint">Keep quantities current. A quantity of 0 removes that ingredient. Pantry-only plans require enough stock for every selected meal.</p>
    <?php if ($pantryItems === []): ?>
      <p>Your pantry is empty. Add ingredients below before choosing pantry-only planning.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>Ingredient</th><th>Current stock</th><th>Update quantity and unit</th></tr></thead>
        <tbody>
        <?php foreach ($pantryItems as $pantryItem): ?>
          <tr>
            <td><?= $escape($pantryItem->name) ?></td>
            <td><?= $escape($pantryItem->quantity->amount) ?> <?= $escape($pantryItem->quantity->unit) ?></td>
            <td>
              <form method="post" class="pantry-form">
                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                <input type="hidden" name="action" value="save_pantry">
                <input type="hidden" name="ingredient_id" value="<?= $escape((string) $pantryItem->ingredientId) ?>">
                <input type="number" name="quantity" min="0" step="0.001" required
                       value="<?= $escape($pantryItem->quantity->amount) ?>" aria-label="Quantity for <?= $escape($pantryItem->name) ?>">
                <select name="unit" aria-label="Unit for <?= $escape($pantryItem->name) ?>">
                  <?php foreach ($supportedUnits as $unit): ?>
                    <option value="<?= $escape($unit) ?>" <?= $pantryItem->quantity->unit === $unit ? 'selected' : '' ?>><?= $escape($unit) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit">Update</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <?php if ($ingredientCatalog !== []): ?>
      <h3>Add a catalog ingredient</h3>
      <form method="post" class="pantry-form">
        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="action" value="save_pantry">
        <select name="ingredient_id" required aria-label="Ingredient" data-pantry-ingredient>
          <?php foreach ($ingredientCatalog as $ingredient): ?>
            <option value="<?= $escape((string) $ingredient['id']) ?>"
                    data-unit="<?= $escape($ingredient['canonical_unit']) ?>"><?= $escape($ingredient['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="number" name="quantity" min="0.001" step="0.001" required value="1" aria-label="Quantity">
        <select name="unit" aria-label="Unit" data-pantry-unit>
          <?php foreach ($supportedUnits as $unit): ?>
            <option value="<?= $escape($unit) ?>"><?= $escape($unit) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit">Add or Update Stock</button>
      </form>
      <p class="hint">Adding an ingredient already listed above replaces its saved quantity.</p>
    <?php endif; ?>
    <h3>Add your own fridge item</h3>
    <form method="post" class="pantry-form">
      <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
      <input type="hidden" name="action" value="add_custom_pantry">
      <input type="text" name="ingredient_name" maxlength="160" required
             placeholder="For example: bell peppers" aria-label="Custom ingredient name">
      <input type="number" name="quantity" min="0.001" step="0.001" required
             value="1" aria-label="Quantity">
      <select name="unit" aria-label="Unit">
        <?php foreach ($supportedUnits as $unit): ?>
          <option value="<?= $escape($unit) ?>"><?= $escape($unit) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit">Add to Pantry</button>
    </form>
    <p class="hint">If the name matches an existing ingredient, its pantry quantity is updated; otherwise a new ingredient is added. Weekly plans can use it when a saved recipe contains that ingredient.</p>
  </section>

  <section class="card">
    <h2>Generate Weekly Plan</h2>
    <p class="hint">Select the meal types you want for each day of the 7-day plan.</p>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
      <input type="hidden" name="action" value="generate">
      <label>Start date
        <input type="date" name="starts_on" value="<?= $escape(date('Y-m-d')) ?>">
      </label>
      <label>Servings per meal
        <input type="number" name="servings" min="1" max="20" value="2" required>
      </label>
      <div class="choices" role="group" aria-label="Meal types">
        <?php foreach (['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner'] as $slot => $label): ?>
          <label><input type="checkbox" name="meal_slots[]" value="<?= $escape($slot) ?>" <?= $slot === 'dinner' ? 'checked' : '' ?>> <?= $escape($label) ?></label>
        <?php endforeach; ?>
      </div>
      <fieldset>
        <legend>Shopping choice</legend>
        <div class="choices">
          <label><input type="radio" name="purchase_mode" value="pantry_only"> Use only current pantry quantities</label>
          <label><input type="radio" name="purchase_mode" value="allow_missing" checked> I can buy missing ingredients; make a shopping list</label>
        </div>
      </fieldset>
      <button type="submit">Generate Weekly Plan</button>
    </form>
  </section>

  <?php if ($generatedResult !== null): ?>
    <?php $plan = $generatedResult->plan; ?>
    <section class="card">
      <h2>Agent Execution Trace</h2>
      <?php foreach ($generatedResult->trace as $step): ?>
        <div class="trace <?= $escape($step->status) ?>">
          <strong><?= $escape($step->agent) ?></strong>
          <span class="status"><?= $escape($step->status) ?> · attempt <?= $escape((string) $step->attempt) ?></span>
          <p><?= $escape($step->action) ?> — <?= $escape($step->detail) ?></p>
          <small><?= $escape($step->durationMs) ?> ms<?php if ($step->eurTotal !== null): ?> · <?= $escape(number_format($step->eurTotal, 2)) ?> EUR<?php endif; ?></small>
        </div>
      <?php endforeach; ?>
    </section>

    <section class="card">
      <h2>Proposed Weekly Meal Plan</h2>
      <p><strong>Attempts:</strong> <?= $escape((string) $generatedResult->attempts) ?> ·
         <strong>Validation:</strong> <?= $generatedResult->validation->approved ? 'Approved' : 'Needs revision' ?></p>
      <?php if ($generatedResult->validation->issues !== []): ?>
        <ul class="error">
          <?php foreach ($generatedResult->validation->issues as $issue): ?><li><?= $escape($issue) ?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <table>
        <thead><tr><th>Date</th><th>Meal</th><th>Recipe</th><th>Servings</th></tr></thead>
        <tbody>
        <?php foreach ($plan->meals as $mealIndex => $meal): ?>
          <tr>
            <td><?= $escape($meal->date) ?></td>
            <td><?= $escape($meal->slot) ?></td>
            <td><?= $escape($generatedResult->scaledRecipes[$mealIndex]->name ?? ('Recipe #' . $meal->recipeId)) ?></td>
            <td><?= $escape((string) $meal->servings) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <h3>Scaled Ingredients</h3>
      <?php foreach ($generatedResult->scaledRecipes as $recipe): ?>
        <p><strong><?= $escape($recipe->name) ?></strong> (<?= $escape((string) $recipe->targetServings) ?> servings)</p>
        <ul>
          <?php foreach ($recipe->ingredients as $ingredient): ?>
            <li><?= $escape($ingredient->name) ?>: <?= $escape($ingredient->quantity->amount) ?> <?= $escape($ingredient->quantity->unit) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endforeach; ?>
      <p><strong>Nutritional estimate:</strong>
        <?= $escape((string) $generatedResult->validation->nutritionSummary['estimated_daily_calories']) ?> kcal/day,
        <?= $escape((string) $generatedResult->validation->nutritionSummary['estimated_daily_protein_g']) ?> g protein/day,
        <?= $escape((string) $generatedResult->validation->nutritionSummary['unique_recipes']) ?> unique recipes</p>
    </section>

    <section class="card">
      <h2>Optimized Shopping List</h2>
      <p class="hint">Prices are estimates when the live PosoKanei product search is unavailable. Check each item's source; approximate local catalog prices are labeled explicitly.</p>
      <table>
        <thead><tr><th>Item</th><th>Need</th><th>EUR</th><th>Source</th></tr></thead>
        <tbody>
        <?php foreach ($generatedResult->shoppingList->items as $item): ?>
          <tr>
            <td><?= $escape($item->name) ?></td>
            <td><?= $escape($item->quantity->amount) ?> <?= $escape($item->quantity->unit) ?></td>
            <td><?= $item->estimatedCost === null ? 'Unavailable' : $escape(number_format($item->estimatedCost, 2)) ?></td>
            <td><?= $escape($item->priceSource ?? 'Catalog unavailable') ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($generatedResult->shoppingList->items === []): ?>
          <tr><td colspan="4">No purchases are required for this plan.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
      <p><strong>Estimated total: <?= $escape(number_format($generatedResult->shoppingList->estimatedTotal, 2)) ?> EUR</strong></p>
    </section>

    <section class="card">
      <h2>Human Approval Gate</h2>
      <?php if (!$generatedResult->validation->approved): ?>
        <p class="error">This plan exhausted the 3-attempt feedback limit. Review and revise the constraints or discard it.</p>
      <?php else: ?>
        <form method="post" class="inline">
          <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
          <input type="hidden" name="action" value="approve">
          <button type="submit">Approve &amp; Save Plan</button>
        </form>
      <?php endif; ?>
      <form method="post" class="inline">
        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="action" value="discard">
        <button type="submit" class="danger">Discard</button>
      </form>
    </section>
  <?php endif; ?>

  <section class="card">
    <h2>Saved Meals &amp; Ratings</h2>
    <?php if ($savedMeals === []): ?>
      <p>No approved saved meals yet.</p>
    <?php else: ?>
      <?php foreach ($savedMeals as $meal): ?>
        <form method="post" class="inline">
          <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
          <input type="hidden" name="action" value="rate">
          <input type="hidden" name="recipe_id" value="<?= $escape((string) $meal['recipe_id']) ?>">
          <strong><?= $escape($meal['recipe_name']) ?></strong>
          <?= $escape($meal['meal_date']) ?>
          <select name="rating" aria-label="Rating">
            <?php for ($rating = 1; $rating <= 5; $rating++): ?>
              <option value="<?= $rating ?>" <?= ((int) ($meal['rating'] ?? 0) === $rating) ? 'selected' : '' ?>><?= $rating ?> star<?= $rating === 1 ? '' : 's' ?></option>
            <?php endfor; ?>
          </select>
          <button type="submit">Save Rating</button>
        </form><br>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Legacy Demo Items</h2>
    <?php foreach ($items as $item): ?>
      <p><strong><?= $escape($item['name']) ?></strong><?php if (!empty($item['note'])): ?> — <?= $escape($item['note']) ?><?php endif; ?></p>
    <?php endforeach; ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
      <input type="hidden" name="action" value="add_item">
      <input type="text" name="name" placeholder="Item name" required>
      <textarea name="note" placeholder="Note (optional)"></textarea>
      <button type="submit">Add item</button>
    </form>
  </section>
</main>
<script>
  const pantryIngredient = document.querySelector('[data-pantry-ingredient]');
  const pantryUnit = document.querySelector('[data-pantry-unit]');
  if (pantryIngredient && pantryUnit) {
    const syncPantryUnit = () => {
      const unit = pantryIngredient.selectedOptions[0]?.dataset.unit;
      if (unit && [...pantryUnit.options].some((option) => option.value === unit)) {
        pantryUnit.value = unit;
      }
    };
    pantryIngredient.addEventListener('change', syncPantryUnit);
    syncPantryUnit();
  }
</script>
</body>
</html>
