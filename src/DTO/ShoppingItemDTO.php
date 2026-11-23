<?php

declare(strict_types=1);

namespace App\DTO;

readonly class ShoppingItemDTO
{
    public function __construct(
        public int $ingredientId,
        public string $name,
        public Quantity $quantity,
        public ?float $estimatedCost = null,
        public ?string $currency = null,
        public ?string $priceSource = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        if (!isset($data['ingredient_id']) || filter_var($data['ingredient_id'], FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException('A valid ingredient_id is required');
        }
        if (!isset($data['name'], $data['quantity']) || !is_string($data['name']) || !is_array($data['quantity'])) {
            throw new \InvalidArgumentException('Shopping item name and quantity are required');
        }
        $cost = $data['estimated_cost'] ?? null;
        if ($cost !== null && (!is_numeric($cost) || (float) $cost < 0)) {
            throw new \InvalidArgumentException('estimated_cost must be a non-negative number');
        }
        return new self(
            (int) $data['ingredient_id'],
            trim($data['name']),
            Quantity::fromArray($data['quantity']),
            $cost === null ? null : (float) $cost,
            isset($data['currency']) && is_string($data['currency']) ? $data['currency'] : null,
            isset($data['price_source']) && is_string($data['price_source']) ? $data['price_source'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'ingredient_id' => $this->ingredientId,
            'name' => $this->name,
            'quantity' => $this->quantity->toArray(),
            'estimated_cost' => $this->estimatedCost,
            'currency' => $this->currency,
            'price_source' => $this->priceSource,
        ];
    }
}
