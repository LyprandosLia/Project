<?php

declare(strict_types=1);

namespace App\DTO;

readonly class ShoppingListDTO
{
    public function __construct(
        public int $userId,
        public array $items,
        public float $estimatedTotal,
        public string $currency = 'EUR',
    ) {
        if ($estimatedTotal < 0) {
            throw new \InvalidArgumentException('Estimated total cannot be negative');
        }
        foreach ($items as $item) {
            if (!$item instanceof ShoppingItemDTO) {
                throw new \InvalidArgumentException('Items must be ShoppingItemDTO objects');
            }
        }
    }

    public static function fromArray(array $data): self
    {
        if (!isset($data['user_id']) || filter_var($data['user_id'], FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException('A valid user_id is required');
        }
        if (!isset($data['items']) || !is_array($data['items'])) {
            throw new \InvalidArgumentException('Shopping items must be an array');
        }
        $items = [];
        foreach ($data['items'] as $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('Shopping item entries must be arrays');
            }
            $items[] = ShoppingItemDTO::fromArray($item);
        }
        $total = $data['estimated_total'] ?? 0.0;
        if (!is_numeric($total)) {
            throw new \InvalidArgumentException('estimated_total must be numeric');
        }
        return new self(
            (int) $data['user_id'],
            $items,
            (float) $total,
            isset($data['currency']) && is_string($data['currency']) ? $data['currency'] : 'EUR',
        );
    }

    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'items' => array_map(static fn (ShoppingItemDTO $item): array => $item->toArray(), $this->items),
            'estimated_total' => $this->estimatedTotal,
            'currency' => $this->currency,
        ];
    }
}
