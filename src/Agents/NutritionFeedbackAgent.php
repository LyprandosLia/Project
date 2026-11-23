<?php

declare(strict_types=1);

namespace App\Agents;

use App\DTO\MealPlanDTO;
use App\DTO\ScaledRecipeDTO;
use App\DTO\UserProfileDTO;
use App\DTO\ValidationResultDTO;
use App\Repository\UserRepository;

final class NutritionFeedbackAgent
{
    public function __construct(private UserRepository $users)
    {
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
        foreach ($scaledRecipes as $recipe) {
            foreach ($recipe->ingredients as $ingredient) {
                $name = strtolower($ingredient->name);
                foreach ($allergens as $allergen) {
                    if ($allergen !== '' && str_contains($name, $allergen)) {
                        $issues[] = sprintf('Recipe "%s" contains allergen "%s".', $recipe->name, $allergen);
                        $feedback[] = sprintf('Replace recipe "%s" or remove "%s".', $recipe->name, $allergen);
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
        if ($mostRepeated > 4) {
            $issues[] = 'One recipe is repeated too often across the week.';
            $feedback[] = 'Reduce the repeated recipe to no more than four meals.';
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

        $calories = 0;
        $protein = 0;
        foreach ($scaledRecipes as $recipe) {
            foreach ($recipe->ingredients as $ingredient) {
                $amount = (float) $ingredient->quantity->amount;
                $unit = $ingredient->quantity->unit;
                $grams = match ($unit) {
                    'kg' => $amount * 1000,
                    'l' => $amount * 1000,
                    'ml' => $amount,
                    default => $amount,
                };
                $name = strtolower($ingredient->name);
                $calorieDensity = str_contains($name, 'oil') ? 9 : (str_contains($name, 'rice') || str_contains($name, 'pasta') ? 3.6 : 1.5);
                $proteinDensity = str_contains($name, 'lentil') || str_contains($name, 'chickpea') ? 0.18 : 0.03;
                $calories += $grams * $calorieDensity;
                $protein += $grams * $proteinDensity;
            }
        }
        $dailyCalories = $plan->meals === [] ? 0 : (int) round($calories / 7);
        $dailyProtein = $plan->meals === [] ? 0 : round($protein / 7, 1);

        $calorieGoal = $profile->preferences['daily_calorie_goal'] ?? null;
        if (is_int($calorieGoal) && $calorieGoal > 0) {
            $dinnerTarget = $calorieGoal / 3;
            if ($dailyCalories > $dinnerTarget * 1.35) {
                $issues[] = 'Estimated calorie level is high relative to the daily calorie target.';
                $feedback[] = 'Reduce dinner servings or choose lower-calorie ingredients.';
            }
        }

        $proteinGoal = $profile->preferences['daily_protein_goal_g'] ?? null;
        if (is_float($proteinGoal) && $proteinGoal > 0 && $dailyProtein < $proteinGoal / 3 * 0.5) {
            $issues[] = 'Estimated protein is low for the configured daily protein target.';
            $feedback[] = 'Choose protein-rich recipes or increase servings of lentils or chickpeas.';
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
}
