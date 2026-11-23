<?php

declare(strict_types=1);

namespace App\DTO;

readonly class OrchestrationResultDTO
{
    public function __construct(
        public MealPlanDTO $plan,
        public array $scaledRecipes,
        public ShoppingListDTO $shoppingList,
        public ValidationResultDTO $validation,
        public array $trace,
        public int $attempts,
    ) {
        foreach ($scaledRecipes as $recipe) {
            if (!$recipe instanceof ScaledRecipeDTO) {
                throw new \InvalidArgumentException('Scaled recipes must be ScaledRecipeDTO objects');
            }
        }
        foreach ($trace as $step) {
            if (!$step instanceof AgentStepLogDTO) {
                throw new \InvalidArgumentException('Trace entries must be AgentStepLogDTO objects');
            }
        }
    }

    public function toArray(): array
    {
        return [
            'plan' => $this->plan->toArray(),
            'scaled_recipes' => array_map(static fn (ScaledRecipeDTO $recipe): array => $recipe->toArray(), $this->scaledRecipes),
            'shopping_list' => $this->shoppingList->toArray(),
            'validation' => $this->validation->toArray(),
            'trace' => array_map(static fn (AgentStepLogDTO $step): array => $step->toArray(), $this->trace),
            'attempts' => $this->attempts,
        ];
    }
}
