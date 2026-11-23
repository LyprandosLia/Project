<?php

declare(strict_types=1);

namespace App\Agents;

use App\DTO\MealPlanDTO;
use App\DTO\MealSelectionDTO;
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

    public function propose(UserProfileDTO $profile, string $startsOn, ?string $feedback = null): MealPlanDTO
    {
        $start = new \DateTimeImmutable($startsOn);
        $end = $start->modify('+6 days');
        $allRecipes = $this->recipes->findAll();
        $allowed = [];
        $allergens = array_map(static fn (string $value): string => strtolower(trim($value)), $profile->allergies);
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
                $allowed[] = $recipe;
            }
        }
        if ($allowed === []) {
            throw new \RuntimeException('No recipes satisfy the user\'s allergy and prep-time constraints');
        }

        $recent = array_fill_keys($this->users->findRecentRecipeIds($profile->userId), true);
        usort($allowed, static function ($left, $right) use ($recent): int {
            $leftRecent = isset($recent[$left->id]) ? 1 : 0;
            $rightRecent = isset($recent[$right->id]) ? 1 : 0;
            if ($leftRecent !== $rightRecent) {
                return $leftRecent <=> $rightRecent;
            }
            return $left->name <=> $right->name;
        });

        $meals = [];
        for ($day = 0; $day < 7; $day++) {
            $index = $day % count($allowed);
            if ($feedback !== null && str_contains(strtolower($feedback), 'variety')) {
                $index = ($day * 2) % count($allowed);
            }
            if ($feedback !== null && str_contains(strtolower($feedback), 'repeated')) {
                $index = ($day + 1) % count($allowed);
            }
            $recipe = $allowed[$index];
            $meals[] = [
                'date' => $start->modify(sprintf('+%d days', $day))->format('Y-m-d'),
                'slot' => 'dinner',
                'recipe_id' => $recipe->id,
                'servings' => 2,
            ];
        }
        $mock = [
            'user_id' => $profile->userId,
            'starts_on' => $start->format('Y-m-d'),
            'ends_on' => $end->format('Y-m-d'),
            'meals' => $meals,
        ];
        $promptPath = dirname(__DIR__) . '/Prompts/meal-planner.md';
        $prompt = is_file($promptPath) ? (string) file_get_contents($promptPath) : '';
        $decoded = $this->llm->generateStructuredJson($prompt, [
            'profile' => $profile->toArray(),
            'revision_feedback' => $feedback,
            'allowed_recipes' => array_map(static fn ($recipe): array => [
                'id' => $recipe->id,
                'name' => $recipe->name,
                'prep_minutes' => $recipe->prepMinutes,
            ], $allowed),
            'recent_recipe_ids' => array_keys($recent),
            'starts_on' => $start->format('Y-m-d'),
        ], $mock);

        $plan = MealPlanDTO::fromArray($decoded);
        $allowedIds = array_fill_keys(array_map(static fn ($recipe): int => $recipe->id, $allowed), true);
        foreach ($plan->meals as $meal) {
            if (!isset($allowedIds[$meal->recipeId])) {
                throw new \UnexpectedValueException('LLM meal plan selected a prohibited recipe');
            }
        }
        return $plan;
    }
}
