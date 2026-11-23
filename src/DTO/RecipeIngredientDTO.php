<?php

declare(strict_types=1);

namespace App\DTO;

readonly class RecipeIngredientDTO
{
    public function __construct(
        public int $ingredientId,
        public string $name,
        public Quantity $quantity,
        public ?string $notes = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        if (!isset($data['ingredient_id']) || filter_var($data['ingredient_id'], FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException('A valid ingredient_id is required');
        }
        if (!isset($data['name']) || !is_string($data['name']) || trim($data['name']) === '') {
            throw new \InvalidArgumentException('A valid ingredient name is required');
        }
        if (!isset($data['quantity']) || !is_array($data['quantity'])) {
            throw new \InvalidArgumentException('A valid quantity is required');
        }
        return new self(
            (int) $data['ingredient_id'],
            trim($data['name']),
            Quantity::fromArray($data['quantity']),
            isset($data['notes']) && is_string($data['notes']) ? $data['notes'] : null,
        );
    }
}
