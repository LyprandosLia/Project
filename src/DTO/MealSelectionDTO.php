<?php

declare(strict_types=1);

namespace App\DTO;

readonly class MealSelectionDTO
{
    public function __construct(
        public string $date,
        public string $slot,
        public int $recipeId,
        public int $servings,
    ) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || (new \DateTimeImmutable($date))->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Meal date must be a valid Y-m-d date');
        }
        if (!in_array($slot, ['breakfast', 'lunch', 'dinner', 'snack'], true)) {
            throw new \InvalidArgumentException('Invalid meal slot');
        }
        if ($recipeId < 1 || $servings < 1) {
            throw new \InvalidArgumentException('Recipe id and servings must be positive');
        }
    }

    public static function fromArray(array $data): self
    {
        foreach (['date', 'slot', 'recipe_id', 'servings'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new \InvalidArgumentException(sprintf('Missing meal selection field "%s"', $key));
            }
        }
        if (filter_var($data['recipe_id'], FILTER_VALIDATE_INT) === false
            || filter_var($data['servings'], FILTER_VALIDATE_INT) === false
        ) {
            throw new \InvalidArgumentException('recipe_id and servings must be integers');
        }
        return new self((string) $data['date'], (string) $data['slot'], (int) $data['recipe_id'], (int) $data['servings']);
    }

    public function toArray(): array
    {
        return ['date' => $this->date, 'slot' => $this->slot, 'recipe_id' => $this->recipeId, 'servings' => $this->servings];
    }
}
