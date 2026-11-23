<?php

declare(strict_types=1);

/** @var array $items */
/** @var array $savedMeals */
/** @var string $csrfToken */
/** @var string|null $error */
/** @var string|null $success */
/** @var App\DTO\OrchestrationResultDTO|null $generatedResult */

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
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
    <h2>Generate Weekly Plan</h2>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
      <input type="hidden" name="action" value="generate">
      <label>Start date
        <input type="date" name="starts_on" value="<?= $escape(date('Y-m-d')) ?>">
      </label>
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
      <table>
        <thead><tr><th>Date</th><th>Slot</th><th>Recipe ID</th><th>Servings</th></tr></thead>
        <tbody>
        <?php foreach ($plan->meals as $meal): ?>
          <tr>
            <td><?= $escape($meal->date) ?></td>
            <td><?= $escape($meal->slot) ?></td>
            <td><?= $escape((string) $meal->recipeId) ?></td>
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
        </tbody>
      </table>
      <p><strong>Estimated total: <?= $escape(number_format($generatedResult->shoppingList->estimatedTotal, 2)) ?> EUR</strong></p>
    </section>

    <section class="card">
      <h2>Human Approval Gate</h2>
      <?php if (!$generatedResult->validation->approved): ?>
        <p class="error">This plan exhausted the 3-attempt feedback limit. Review and revise the constraints or discard it.</p>
      <?php endif; ?>
      <form method="post" class="inline">
        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="action" value="approve">
        <button type="submit">Approve &amp; Save Plan</button>
      </form>
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
    <h2>Pantry &amp; Legacy Items</h2>
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
</body>
</html>
