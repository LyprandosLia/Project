<?php

declare(strict_types=1);

namespace App\DTO;

readonly class ValidationResultDTO
{
    public function __construct(
        public bool $approved,
        public array $issues,
        public string $feedback,
        public array $nutritionSummary = [],
    ) {
        foreach ($issues as $issue) {
            if (!is_string($issue)) {
                throw new \InvalidArgumentException('Validation issues must be strings');
            }
        }
    }

    public function toArray(): array
    {
        return [
            'approved' => $this->approved,
            'issues' => $this->issues,
            'feedback' => $this->feedback,
            'nutrition_summary' => $this->nutritionSummary,
        ];
    }
}
