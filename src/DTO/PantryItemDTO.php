<?php

declare(strict_types=1);

namespace App\DTO;

readonly class PantryItemDTO
{
    public function __construct(
        public int $ingredientId,
        public string $name,
        public Quantity $quantity,
    ) {
        if ($ingredientId < 1 || trim($name) === '') {
            throw new \InvalidArgumentException('Pantry item requires an ingredient ID and name');
        }
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
