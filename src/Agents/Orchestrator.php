<?php

declare(strict_types=1);

namespace App\Agents;

use App\DTO\AgentStepLogDTO;
use App\DTO\MealPlanRequestDTO;
use App\DTO\OrchestrationResultDTO;
use App\DTO\UserProfileDTO;
use App\Repository\PantryRepository;

final class Orchestrator
{
    public function __construct(
        private MealPlannerAgent $mealPlanner,
        private RecipeSpecialistAgent $recipeSpecialist,
        private ShoppingOptimizerAgent $shoppingOptimizer,
        private NutritionFeedbackAgent $nutritionFeedback,
        private PantryRepository $pantry,
    ) {
    }

    public function run(UserProfileDTO $profile, MealPlanRequestDTO $request): OrchestrationResultDTO
    {
        $trace = [];
        $feedback = null;
        $attempts = 0;
        $plan = null;
        $scaledRecipes = [];
        $shoppingList = null;
        $validation = null;
        $pantryItems = $this->pantry->findItemsByUser($profile->userId);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $attempts = $attempt;

            $stepStart = hrtime(true);
            $startedAt = (new \DateTimeImmutable())->format(DATE_ATOM);
            $plan = $this->mealPlanner->propose($profile, $request, $feedback, $pantryItems);
            $trace[] = $this->step(
                'Agent 1 - MealPlanner',
                'Proposed ' . implode(', ', $request->mealSlots) . ' meals',
                $startedAt,
                $stepStart,
                'success',
                $attempt,
                count($plan->meals) . ' meals for ' . $request->servings . ' servings',
            );

            $stepStart = hrtime(true);
            $startedAt = (new \DateTimeImmutable())->format(DATE_ATOM);
            $scaledRecipes = [];
            foreach ($plan->meals as $meal) {
                $scaledRecipes[] = $this->recipeSpecialist->scale($meal, $profile->maxPrepMinutes);
            }
            $trace[] = $this->step(
                'Agent 2 - RecipeSpecialist',
                'Scaled recipe ingredients',
                $startedAt,
                $stepStart,
                'success',
                $attempt,
                count($scaledRecipes) . ' recipes scaled',
            );

            $stepStart = hrtime(true);
            $startedAt = (new \DateTimeImmutable())->format(DATE_ATOM);
            $shoppingList = $this->shoppingOptimizer->optimize($profile->userId, $scaledRecipes);
            $trace[] = $this->step(
                'Agent 3 - ShoppingOptimizer',
                'Aggregated and optimized shopping list',
                $startedAt,
                $stepStart,
                'success',
                $attempt,
                count($shoppingList->items) . ' purchase items',
                $shoppingList->estimatedTotal,
            );

            $stepStart = hrtime(true);
            $startedAt = (new \DateTimeImmutable())->format(DATE_ATOM);
            $validation = $this->nutritionFeedback->validate($profile, $plan, $scaledRecipes);
            $trace[] = $this->step(
                'Agent 4 - NutritionFeedback',
                'Validated targets and preferences',
                $startedAt,
                $stepStart,
                $validation->approved ? 'approved' : 'rejected',
                $attempt,
                $validation->approved ? 'Plan approved' : $validation->feedback,
                $shoppingList->estimatedTotal,
            );

            if ($validation->approved) {
                break;
            }
            $feedback = $validation->feedback;
        }

        if ($plan === null || $shoppingList === null || $validation === null) {
            throw new \RuntimeException('Orchestrator produced no plan');
        }
        return new OrchestrationResultDTO(
            $plan,
            $scaledRecipes,
            $shoppingList,
            $validation,
            $trace,
            $attempts,
        );
    }

    private function step(
        string $agent,
        string $action,
        string $startedAt,
        int $stepStart,
        string $status,
        int $attempt,
        string $detail,
        ?float $eurTotal = null,
    ): AgentStepLogDTO {
        $durationMs = (int) round((hrtime(true) - $stepStart) / 1_000_000);
        return new AgentStepLogDTO(
            $agent,
            $action,
            $status,
            $attempt,
            $startedAt,
            $durationMs,
            $detail,
            $eurTotal,
        );
    }
}
