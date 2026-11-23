<?php

declare(strict_types=1);

namespace App\DTO;

readonly class RecipeDTO
{
    public function __construct(
        public int $id,
        public string $slug,
        public string $name,
        public string $description,
        public int $prepMinutes,
        public int $baseServings,
        public string $instructions,
        public array $ingredients,
    ) {
        foreach ($ingredients as $ingredient) {
            if (!$ingredient instanceof RecipeIngredientDTO) {
                throw new \InvalidArgumentException('Recipe ingredients must be RecipeIngredientDTO objects');
            }
        }
    }

    public static function fromArray(array $data): self
    {
        foreach (['id', 'slug', 'name', 'prep_minutes', 'base_servings', 'instructions'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new \InvalidArgumentException(sprintf('Missing recipe field "%s"', $key));
            }
        }
        if (filter_var($data['id'], FILTER_VALIDATE_INT) === false
            || filter_var($data['prep_minutes'], FILTER_VALIDATE_INT) === false
            || filter_var($data['base_servings'], FILTER_VALIDATE_INT) === false
        ) {
            throw new \InvalidArgumentException('Recipe id, prep time and servings must be integers');
        }
        if (!is_array($data['ingredients'] ?? null)) {
            throw new \InvalidArgumentException('Recipe ingredients must be an array');
        }
        $ingredients = [];
        foreach ($data['ingredients'] as $ingredient) {
            if (!is_array($ingredient)) {
                throw new \InvalidArgumentException('Recipe ingredient entries must be arrays');
            }
            $ingredients[] = RecipeIngredientDTO::fromArray($ingredient);
        }
        return new self(
            (int) $data['id'],
            (string) $data['slug'],
            (string) $data['name'],
            isset($data['description']) && is_string($data['description']) ? $data['description'] : '',
            (int) $data['prep_minutes'],
            (int) $data['base_servings'],
            (string) $data['instructions'],
            $ingredients,
        );
    }
}
