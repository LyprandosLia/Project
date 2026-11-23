<?php

declare(strict_types=1);

namespace App\DTO;

readonly class ScaledRecipeDTO
{
    public function __construct(
        public int $recipeId,
        public string $name,
        public int $baseServings,
        public int $targetServings,
        public array $ingredients,
        public ?string $alternative = null,
    ) {
        foreach ($ingredients as $ingredient) {
            if (!$ingredient instanceof ScaledIngredientDTO) {
                throw new \InvalidArgumentException('Ingredients must be ScaledIngredientDTO objects');
            }
        }
    }

    public static function fromArray(array $data): self
    {
        foreach (['recipe_id', 'name', 'base_servings', 'target_servings', 'ingredients'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new \InvalidArgumentException(sprintf('Missing scaled recipe field "%s"', $key));
            }
        }
        if (!is_array($data['ingredients'])) {
            throw new \InvalidArgumentException('Scaled ingredients must be an array');
        }
        $ingredients = [];
        foreach ($data['ingredients'] as $ingredient) {
            if (!is_array($ingredient)) {
                throw new \InvalidArgumentException('Scaled ingredient entries must be arrays');
            }
            $ingredients[] = ScaledIngredientDTO::fromArray($ingredient);
        }
        return new self(
            (int) $data['recipe_id'],
            (string) $data['name'],
            (int) $data['base_servings'],
            (int) $data['target_servings'],
            $ingredients,
            isset($data['alternative']) && is_string($data['alternative']) ? $data['alternative'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'recipe_id' => $this->recipeId,
            'name' => $this->name,
            'base_servings' => $this->baseServings,
            'target_servings' => $this->targetServings,
            'ingredients' => array_map(static fn (ScaledIngredientDTO $item): array => $item->toArray(), $this->ingredients),
            'alternative' => $this->alternative,
        ];
    }
}
