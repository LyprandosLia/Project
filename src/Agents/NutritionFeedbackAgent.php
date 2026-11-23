<?php

declare(strict_types=1);

namespace App\Agents;

use App\DTO\MealPlanDTO;
use App\DTO\ScaledRecipeDTO;
use App\DTO\UserProfileDTO;
use App\DTO\ValidationResultDTO;
use App\Repository\UserRepository;
use App\Service\CatalogApiClient;

final class NutritionFeedbackAgent
{
    public function __construct(
        private UserRepository $users,
        private CatalogApiClient $catalog,
    ) {
    }

    public function validate(
        UserProfileDTO $profile,
        MealPlanDTO $plan,
        array $scaledRecipes,
    ): ValidationResultDTO {
        foreach ($scaledRecipes as $recipe) {
            if (!$recipe instanceof ScaledRecipeDTO) {
                throw new \InvalidArgumentException('Scaled recipes must be ScaledRecipeDTO objects');
            }
        }

        $issues = [];
        $feedback = [];
        $allergens = array_map(static fn (string $value): string => strtolower(trim($value)), $profile->allergies);
        $restrictedIngredients = $this->restrictedIngredients($profile);
        foreach ($scaledRecipes as $recipe) {
            foreach ($recipe->ingredients as $ingredient) {
                $name = strtolower($ingredient->name);
                foreach ($allergens as $allergen) {
                    if ($allergen !== '' && str_contains($name, $allergen)) {
                        $issues[] = sprintf('Recipe "%s" contains allergen "%s".', $recipe->name, $allergen);
                        $feedback[] = sprintf('Replace recipe "%s" or remove "%s".', $recipe->name, $allergen);
                    }
                }
                foreach ($restrictedIngredients as $restrictedIngredient) {
                    if (str_contains($name, $restrictedIngredient)) {
                        $issues[] = sprintf('Recipe "%s" conflicts with dietary restriction "%s".', $recipe->name, $restrictedIngredient);
                        $feedback[] = sprintf('Replace recipe "%s" with one that respects the dietary restrictions.', $recipe->name);
                    }
                }
            }
        }

        $counts = [];
        foreach ($plan->meals as $meal) {
            $counts[$meal->recipeId] = ($counts[$meal->recipeId] ?? 0) + 1;
        }
        $uniqueRecipes = count($counts);
        $mostRepeated = $counts === [] ? 0 : max($counts);
        if ($uniqueRecipes < 3) {
            $issues[] = 'Ingredient variety is too low: fewer than three unique recipes.';
            $feedback[] = 'Increase ingredient variety by using at least three different recipes.';
        }
        $maxReasonableRepeats = max(4, (int) ceil(count($plan->meals) / max(1, $uniqueRecipes)) + 1);
        if ($mostRepeated > $maxReasonableRepeats) {
            $issues[] = 'One recipe is repeated too often across the week.';
            $feedback[] = 'Reduce repetitions and spread the selected meals across the available recipes.';
        }

        $ratings = $this->users->findRatingsByUser($profile->userId);
        $lowRatedIds = [];
        foreach ($ratings as $rating) {
            if ((int) $rating['rating'] <= 2) {
                $lowRatedIds[] = (int) $rating['recipe_id'];
            }
        }
        foreach ($plan->meals as $meal) {
            if (in_array($meal->recipeId, $lowRatedIds, true)) {
                $issues[] = sprintf('The plan includes a previously low-rated recipe (ID %d).', $meal->recipeId);
                $feedback[] = 'Prefer recipes not previously rated 1 or 2 stars.';
                break;
            }
        }

        $calories = 0.0;
        $protein = 0.0;
        $nutritionFailures = [];
        $nutritionCache = [];
        foreach ($scaledRecipes as $recipe) {
            foreach ($recipe->ingredients as $ingredient) {
                $cacheKey = strtolower($ingredient->name) . ':' . $ingredient->quantity->amount . ':' . $ingredient->quantity->unit;
                if (!array_key_exists($cacheKey, $nutritionCache)) {
                    try {
                        $nutritionCache[$cacheKey] = $this->catalog->getNutrition(
                            $ingredient->name,
                            $ingredient->quantity->amount,
                            $ingredient->quantity->unit,
                        );
                    } catch (\RuntimeException|\InvalidArgumentException $exception) {
                        $nutritionFailures[$ingredient->name] = $exception->getMessage();
                        $nutritionCache[$cacheKey] = null;
                    }
                }
                $nutrition = $nutritionCache[$cacheKey];
                if ($nutrition === null) {
                    continue;
                }
                if (!isset($nutrition['calories_kcal'], $nutrition['protein_g'])
                    || !is_numeric($nutrition['calories_kcal'])
                    || !is_numeric($nutrition['protein_g'])
                ) {
                    $nutritionFailures[$ingredient->name] = 'Nutrition response omitted calories or protein.';
                    continue;
                }
                $calories += (float) $nutrition['calories_kcal'];
                $protein += (float) $nutrition['protein_g'];
            }
        }
        $dailyCalories = $plan->meals === [] ? 0 : (int) round($calories / 7);
        $dailyProtein = $plan->meals === [] ? 0 : round($protein / 7, 1);
        if ($nutritionFailures !== []) {
            $issues[] = 'Nutrition data is unavailable for: ' . implode(', ', array_keys($nutritionFailures)) . '.';
            $feedback[] = 'Use recipes with supported nutrition data or restore access to the nutrition catalog.';
        }

        $calorieGoal = $profile->preferences['daily_calorie_goal'] ?? null;
        if (is_int($calorieGoal) && $calorieGoal > 0) {
            $dailyMealSlots = min(3, count(array_unique(array_map(
                static fn ($meal): string => $meal->slot,
                $plan->meals,
            ))));
            $calorieBudget = $calorieGoal * $dailyMealSlots / 3;
            if ($dailyCalories > $calorieBudget * 1.35) {
                $issues[] = 'Estimated calorie level is high relative to the daily calorie target.';
                $feedback[] = 'Reduce servings or choose lower-calorie ingredients for the selected meal slots.';
            }
        }

        $proteinGoal = $profile->preferences['daily_protein_goal_g'] ?? null;
        $dailyMealSlots = min(3, count(array_unique(array_map(
            static fn ($meal): string => $meal->slot,
            $plan->meals,
        ))));
        if (is_float($proteinGoal) && $proteinGoal > 0 && $dailyProtein < $proteinGoal * $dailyMealSlots / 3 * 0.5) {
            $issues[] = 'Estimated protein is low for the configured daily protein target.';
            $feedback[] = 'Choose protein-rich recipes or increase servings for the selected meal slots.';
        }

        $feedbackText = $feedback === [] ? 'Plan is consistent with the configured constraints and preferences.' : implode(' ', array_values(array_unique($feedback)));
        return new ValidationResultDTO(
            $issues === [],
            $issues,
            $feedbackText,
            [
                'estimated_daily_calories' => $dailyCalories,
                'estimated_daily_protein_g' => $dailyProtein,
                'unique_recipes' => $uniqueRecipes,
            ],
        );
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
}
