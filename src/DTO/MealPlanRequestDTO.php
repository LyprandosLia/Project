<?php

declare(strict_types=1);

namespace App\DTO;

readonly class MealPlanRequestDTO
{
    public const PURCHASE_PANTRY_ONLY = 'pantry_only';
    public const PURCHASE_ALLOW_MISSING = 'allow_missing';

    private const MEAL_SLOTS = ['breakfast', 'lunch', 'dinner'];
    private const PURCHASE_MODES = [self::PURCHASE_PANTRY_ONLY, self::PURCHASE_ALLOW_MISSING];

    public function __construct(
        public string $startsOn,
        public array $mealSlots,
        public int $servings,
        public string $purchaseMode,
    ) {
        if (!self::isValidDate($startsOn)) {
            throw new \InvalidArgumentException('Start date must be a valid Y-m-d date');
        }
        if ($mealSlots === [] || count(array_unique($mealSlots)) !== count($mealSlots)) {
            throw new \InvalidArgumentException('Choose at least one unique meal slot');
        }
        foreach ($mealSlots as $slot) {
            if (!is_string($slot) || !in_array($slot, self::MEAL_SLOTS, true)) {
                throw new \InvalidArgumentException('Invalid meal slot');
            }
        }
        if ($servings < 1 || $servings > 20) {
            throw new \InvalidArgumentException('Servings must be between 1 and 20');
        }
        if (!in_array($purchaseMode, self::PURCHASE_MODES, true)) {
            throw new \InvalidArgumentException('Choose whether missing ingredients may be purchased');
        }
    }

    public static function fromArray(array $data): self
    {
        $startsOn = $data['starts_on'] ?? null;
        $mealSlots = $data['meal_slots'] ?? null;
        $servings = $data['servings'] ?? null;
        $purchaseMode = $data['purchase_mode'] ?? null;

        if (!is_string($startsOn) || !is_array($mealSlots)
            || filter_var($servings, FILTER_VALIDATE_INT) === false
            || !is_string($purchaseMode)
        ) {
            throw new \InvalidArgumentException('Complete the start date, meal slots, servings, and shopping choice');
        }

        return new self($startsOn, array_values($mealSlots), (int) $servings, $purchaseMode);
    }

    public function allowsPurchases(): bool
    {
        return $this->purchaseMode === self::PURCHASE_ALLOW_MISSING;
    }

    public function toArray(): array
    {
        return [
            'starts_on' => $this->startsOn,
            'meal_slots' => $this->mealSlots,
            'servings' => $this->servings,
            'purchase_mode' => $this->purchaseMode,
        ];
    }

    private static function isValidDate(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
