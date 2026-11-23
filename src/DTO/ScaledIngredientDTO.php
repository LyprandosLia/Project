<?php

declare(strict_types=1);

namespace App\DTO;

readonly class ScaledIngredientDTO
{
    public function __construct(
        public int $ingredientId,
        public string $name,
        public Quantity $quantity,
    ) {
    }

    public static function fromArray(array $data): self
    {
        if (!isset($data['ingredient_id']) || filter_var($data['ingredient_id'], FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException('A valid ingredient_id is required');
        }
        if (!isset($data['name'], $data['quantity']) || !is_string($data['name']) || !is_array($data['quantity'])) {
            throw new \InvalidArgumentException('Scaled ingredient name and quantity are required');
        }
        return new self((int) $data['ingredient_id'], trim($data['name']), Quantity::fromArray($data['quantity']));
    }

    public function toArray(): array
    {
        return [
            'ingredient_id' => $this->ingredientId,
            'name' => $this->name,
            'quantity' => $this->quantity->toArray(),
        ];
    }
}
