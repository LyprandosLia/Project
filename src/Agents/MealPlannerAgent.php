<?php

declare(strict_types=1);

namespace App\Agents;

use App\DTO\MealPlanDTO;
use App\DTO\MealPlanRequestDTO;
use App\DTO\MealSelectionDTO;
use App\DTO\PantryItemDTO;
use App\DTO\RecipeDTO;
use App\DTO\UserProfileDTO;
use App\Repository\RecipeRepository;
use App\Repository\UserRepository;
use App\Service\LlmClient;

final class MealPlannerAgent
{
    public function __construct(
        private UserRepository $users,
        private RecipeRepository $recipes,
        private LlmClient $llm,
    ) {
    }

    public function propose(
        UserProfileDTO $profile,
        MealPlanRequestDTO $request,
        ?string $feedback,
        array $pantryItems,
    ): MealPlanDTO
    {
        foreach ($pantryItems as $pantryItem) {
            if (!$pantryItem instanceof PantryItemDTO) {
                throw new \InvalidArgumentException('Pantry inventory must contain PantryItemDTO objects');
            }
        }

        $start = new \DateTimeImmutable($request->startsOn);
        $end = $start->modify('+6 days');
        $allRecipes = $this->recipes->findAll();
        $allowed = [];
        $allergens = array_map(static fn (string $value): string => strtolower(trim($value)), $profile->allergies);
        $restrictedIngredients = $this->restrictedIngredients($profile);
        foreach ($allRecipes as $recipe) {
            if ($profile->maxPrepMinutes !== null && $recipe->prepMinutes > $profile->maxPrepMinutes) {
                continue;
            }
            $unsafe = false;
            foreach ($recipe->ingredients as $ingredient) {
                $ingredientName = strtolower($ingredient->name);
                foreach ($allergens as $allergen) {
                    if ($allergen !== '' && str_contains($ingredientName, $allergen)) {
                        $unsafe = true;
                    }
                }
            }
            if (!$unsafe) {
                foreach ($recipe->ingredients as $ingredient) {
                    $name = strtolower($ingredient->name);
                    foreach ($restrictedIngredients as $restrictedIngredient) {
                        if (str_contains($name, $restrictedIngredient)) {
                            $unsafe = true;
                            break 2;
                        }
                    }
                }
            }
            if (!$unsafe) {
                $allowed[] = $recipe;
            }
        }
        if ($allowed === []) {
            throw new \RuntimeException('No recipes satisfy the saved allergy, dietary, and preparation-time constraints.');
        }

        $recent = array_fill_keys($this->users->findRecentRecipeIds($profile->userId), true);
        $lowRated = [];
        foreach ($this->users->findRatingsByUser($profile->userId) as $rating) {
            if ((int) $rating['rating'] <= 2) {
                $lowRated[(int) $rating['recipe_id']] = true;
            }
        }
        $remainingPantry = $this->normalizePantry($pantryItems);
        usort($allowed, function (RecipeDTO $left, RecipeDTO $right) use ($recent, $lowRated, $remainingPantry, $request): int {
            $leftLowRated = isset($lowRated[$left->id]) ? 1 : 0;
            $rightLowRated = isset($lowRated[$right->id]) ? 1 : 0;
            if ($leftLowRated !== $rightLowRated) {
                return $leftLowRated <=> $rightLowRated;
            }
            if ($request->allowsPurchases()) {
                $leftCoverage = $this->pantryCoverage($left, $request->servings, $remainingPantry);
                $rightCoverage = $this->pantryCoverage($right, $request->servings, $remainingPantry);
                if ($leftCoverage !== $rightCoverage) {
                    return $rightCoverage <=> $leftCoverage;
                }
            }
            $leftRecent = isset($recent[$left->id]) ? 1 : 0;
            $rightRecent = isset($recent[$right->id]) ? 1 : 0;
            if ($leftRecent !== $rightRecent) {
                return $leftRecent <=> $rightRecent;
            }
            return $left->name <=> $right->name;
        });

        $this->assertMealSlotsOutsideFastingWindow($profile, $request->mealSlots);
        $mockMeals = [];
        $mealIndex = 0;
        for ($day = 0; $day < 7; $day++) {
            foreach ($request->mealSlots as $slot) {
                $index = $mealIndex % count($allowed);
                if ($feedback !== null && str_contains(strtolower($feedback), 'variety')) {
                    $index = ($mealIndex * 2) % count($allowed);
                }
                if ($feedback !== null && str_contains(strtolower($feedback), 'repeated')) {
                    $index = ($mealIndex + 1) % count($allowed);
                }

                if ($request->purchaseMode === MealPlanRequestDTO::PURCHASE_PANTRY_ONLY) {
                    $recipe = $this->findPantryFit($allowed, $request->servings, $remainingPantry, $index);
                    if ($recipe === null) {
                        throw new \RuntimeException(sprintf(
                            'Your pantry does not contain enough compatible ingredients for the selected %s plan. Update pantry quantities or allow missing ingredients to be purchased.',
                            $slot,
                        ));
                    }
                    $this->consumeRecipe($recipe, $request->servings, $remainingPantry);
                } else {
                    $recipe = $allowed[$index];
                }

                $mockMeals[] = [
                    'date' => $start->modify(sprintf('+%d days', $day))->format('Y-m-d'),
                    'slot' => $slot,
                    'recipe_id' => $recipe->id,
                    'servings' => $request->servings,
                ];
                $mealIndex++;
            }
        }
        $mock = [
            'user_id' => $profile->userId,
            'starts_on' => $start->format('Y-m-d'),
            'ends_on' => $end->format('Y-m-d'),
            'meals' => $mockMeals,
        ];
        $promptPath = dirname(__DIR__) . '/Prompts/meal-planner.md';
        $prompt = is_file($promptPath) ? (string) file_get_contents($promptPath) : '';
        $decoded = $this->llm->generateStructuredJson($prompt, [
            'profile' => $profile->toArray(),
            'revision_feedback' => $feedback,
            'requested_meal_slots' => $request->mealSlots,
            'servings_per_meal' => $request->servings,
            'shopping_mode' => $request->purchaseMode,
            'pantry_inventory' => array_map(
                static fn (PantryItemDTO $item): array => $item->toArray(),
                $pantryItems,
            ),
            'allowed_recipes' => array_map(static fn ($recipe): array => [
                'id' => $recipe->id,
                'name' => $recipe->name,
                'prep_minutes' => $recipe->prepMinutes,
            ], $allowed),
            'recent_recipe_ids' => array_keys($recent),
            'low_rated_recipe_ids' => array_keys($lowRated),
            'starts_on' => $request->startsOn,
        ], $mock);

        $plan = MealPlanDTO::fromArray($decoded);
        $allowedIds = array_fill_keys(array_map(static fn ($recipe): int => $recipe->id, $allowed), true);
        foreach ($plan->meals as $meal) {
            if (!isset($allowedIds[$meal->recipeId])) {
                throw new \UnexpectedValueException('LLM meal plan selected a prohibited recipe');
            }
        }
        $this->validateSchedule($plan, $request, $profile->userId, $end->format('Y-m-d'));
        if ($request->purchaseMode === MealPlanRequestDTO::PURCHASE_PANTRY_ONLY) {
            $recipesById = [];
            foreach ($allowed as $recipe) {
                $recipesById[$recipe->id] = $recipe;
            }
            $remainingPantry = $this->normalizePantry($pantryItems);
            foreach ($plan->meals as $meal) {
                $recipe = $recipesById[$meal->recipeId];
                if (!$this->fitsPantry($recipe, $meal->servings, $remainingPantry)) {
                    throw new \UnexpectedValueException(sprintf(
                        'The planner selected "%s", but the pantry does not contain enough ingredients for the complete plan.',
                        $recipe->name,
                    ));
                }
                $this->consumeRecipe($recipe, $meal->servings, $remainingPantry);
            }
        }
        return $plan;
    }

    private function validateSchedule(
        MealPlanDTO $plan,
        MealPlanRequestDTO $request,
        int $userId,
        string $expectedEnd,
    ): void {
        if ($plan->userId !== $userId || $plan->startsOn !== $request->startsOn || $plan->endsOn !== $expectedEnd) {
            throw new \UnexpectedValueException('The planner returned a different user or date range than requested');
        }

        $expected = [];
        $start = new \DateTimeImmutable($request->startsOn);
        for ($day = 0; $day < 7; $day++) {
            $date = $start->modify(sprintf('+%d days', $day))->format('Y-m-d');
            foreach ($request->mealSlots as $slot) {
                $expected[$date . ':' . $slot] = true;
            }
        }
        $actual = [];
        foreach ($plan->meals as $meal) {
            $key = $meal->date . ':' . $meal->slot;
            if (!isset($expected[$key]) || isset($actual[$key])) {
                throw new \UnexpectedValueException('The planner returned an unrequested or duplicate meal slot');
            }
            if ($meal->servings !== $request->servings) {
                throw new \UnexpectedValueException('The planner returned a serving count different from the requested value');
            }
            $actual[$key] = true;
        }
        if (count($actual) !== count($expected)) {
            throw new \UnexpectedValueException('The planner did not fill every selected meal slot for the week');
        }
    }

    private function restrictedIngredients(UserProfileDTO $profile): array
    {
        $notes = strtolower((string) ($profile->preferences['dietary_notes'] ?? ''));
        $restricted = [];
        if (str_contains($notes, 'vegan')) {
            $restricted = array_merge($restricted, ['meat', 'chicken', 'beef', 'pork', 'fish', 'seafood', 'milk', 'cheese', 'yogurt', 'egg', 'honey']);
        } elseif (str_contains($notes, 'vegetarian')) {
            $restricted = array_merge($restricted, ['meat', 'chicken', 'beef', 'pork', 'fish', 'seafood']);
        }
        if (str_contains($notes, 'dairy-free') || str_contains($notes, 'dairy free')) {
            $restricted = array_merge($restricted, ['milk', 'cheese', 'yogurt', 'butter', 'cream']);
        }
        if (str_contains($notes, 'gluten-free') || str_contains($notes, 'gluten free')) {
            $restricted = array_merge($restricted, ['wheat', 'barley', 'rye', 'gluten']);
        }
        return array_values(array_unique($restricted));
    }

    private function assertMealSlotsOutsideFastingWindow(UserProfileDTO $profile, array $mealSlots): void
    {
        if ($profile->fastingStart === null || $profile->fastingEnd === null) {
            return;
        }
        $mealTimes = ['breakfast' => '08:00', 'lunch' => '13:00', 'dinner' => '19:00'];
        $start = $this->minutesAfterMidnight($profile->fastingStart);
        $end = $this->minutesAfterMidnight($profile->fastingEnd);
        foreach ($mealSlots as $slot) {
            $time = $this->minutesAfterMidnight($mealTimes[$slot]);
            $isFasting = $start < $end
                ? $time >= $start && $time < $end
                : $time >= $start || $time < $end;
            if ($isFasting) {
                throw new \InvalidArgumentException(sprintf(
                    'The selected %s slot (approximately %s) conflicts with the saved fasting window.',
                    $slot,
                    $mealTimes[$slot],
                ));
            }
        }
    }

    private function minutesAfterMidnight(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));
        return $hours * 60 + $minutes;
    }

    private function normalizePantry(array $pantryItems): array
    {
        $remaining = [];
        foreach ($pantryItems as $item) {
            if (!$item instanceof PantryItemDTO) {
                throw new \InvalidArgumentException('Pantry inventory must contain PantryItemDTO objects');
            }
            $unit = $this->baseUnit($item->quantity->unit);
            $remaining[$item->ingredientId] = [
                'unit' => $unit,
                'amount' => $this->toBaseThousandths($item->quantity->amount, $item->quantity->unit),
            ];
        }
        return $remaining;
    }

    private function pantryCoverage(RecipeDTO $recipe, int $servings, array $remainingPantry): int
    {
        $covered = 0;
        foreach ($recipe->ingredients as $ingredient) {
            $stock = $remainingPantry[$ingredient->ingredientId] ?? null;
            if ($stock === null || $stock['unit'] !== $this->baseUnit($ingredient->quantity->unit)) {
                continue;
            }
            if ($stock['amount'] >= $this->scaledIngredientAmount($ingredient->quantity->amount, $ingredient->quantity->unit, $servings, $recipe->baseServings)) {
                $covered++;
            }
        }
        return $covered;
    }

    private function findPantryFit(array $recipes, int $servings, array $remainingPantry, int $startIndex): ?RecipeDTO
    {
        $count = count($recipes);
        for ($offset = 0; $offset < $count; $offset++) {
            $recipe = $recipes[($startIndex + $offset) % $count];
            if ($this->fitsPantry($recipe, $servings, $remainingPantry)) {
                return $recipe;
            }
        }
        return null;
    }

    private function fitsPantry(RecipeDTO $recipe, int $servings, array $remainingPantry): bool
    {
        foreach ($recipe->ingredients as $ingredient) {
            $stock = $remainingPantry[$ingredient->ingredientId] ?? null;
            if ($stock === null || $stock['unit'] !== $this->baseUnit($ingredient->quantity->unit)
                || $stock['amount'] < $this->scaledIngredientAmount(
                    $ingredient->quantity->amount,
                    $ingredient->quantity->unit,
                    $servings,
                    $recipe->baseServings,
                )
            ) {
                return false;
            }
        }
        return true;
    }

    private function consumeRecipe(RecipeDTO $recipe, int $servings, array &$remainingPantry): void
    {
        foreach ($recipe->ingredients as $ingredient) {
            $remainingPantry[$ingredient->ingredientId]['amount'] -= $this->scaledIngredientAmount(
                $ingredient->quantity->amount,
                $ingredient->quantity->unit,
                $servings,
                $recipe->baseServings,
            );
        }
    }

    private function scaledIngredientAmount(string $amount, string $unit, int $servings, int $baseServings): int
    {
        $thousandths = $this->decimalToThousandths($amount);
        $scaled = intdiv($thousandths * $servings + intdiv($baseServings, 2), $baseServings);
        return in_array($unit, ['kg', 'l'], true) ? $scaled * 1000 : $scaled;
    }

    private function toBaseThousandths(string $amount, string $unit): int
    {
        $thousandths = $this->decimalToThousandths($amount);
        return in_array($unit, ['kg', 'l'], true) ? $thousandths * 1000 : $thousandths;
    }

    private function baseUnit(string $unit): string
    {
        return match ($unit) {
            'kg' => 'g',
            'l' => 'ml',
            default => $unit,
        };
    }

    private function decimalToThousandths(string $value): int
    {
        $parts = explode('.', $value, 2);
        $whole = (int) $parts[0];
        $fraction = isset($parts[1]) ? str_pad(substr($parts[1], 0, 3), 3, '0') : '000';
        return $whole * 1000 + (int) $fraction;
    }
}
