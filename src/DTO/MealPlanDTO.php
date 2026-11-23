<?php

declare(strict_types=1);

namespace App\DTO;

readonly class MealPlanDTO
{
    public function __construct(
        public int $userId,
        public string $startsOn,
        public string $endsOn,
        public array $meals,
    ) {
        foreach ($meals as $meal) {
            if (!$meal instanceof MealSelectionDTO) {
                throw new \InvalidArgumentException('Meals must be MealSelectionDTO objects');
            }
        }
    }

    public static function fromArray(array $data): self
    {
        if (!isset($data['user_id']) || filter_var($data['user_id'], FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException('A valid user_id is required');
        }
        if (!isset($data['starts_on'], $data['ends_on']) || !is_string($data['starts_on']) || !is_string($data['ends_on'])) {
            throw new \InvalidArgumentException('Plan dates are required');
        }
        if (!isset($data['meals']) || !is_array($data['meals'])) {
            throw new \InvalidArgumentException('Meals must be an array');
        }
        $meals = [];
        foreach ($data['meals'] as $meal) {
            if (!is_array($meal)) {
                throw new \InvalidArgumentException('Meal entries must be arrays');
            }
            $meals[] = MealSelectionDTO::fromArray($meal);
        }
        return new self((int) $data['user_id'], (string) $data['starts_on'], (string) $data['ends_on'], $meals);
    }

    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'starts_on' => $this->startsOn,
            'ends_on' => $this->endsOn,
            'meals' => array_map(static fn (MealSelectionDTO $meal): array => $meal->toArray(), $this->meals),
        ];
    }
}
