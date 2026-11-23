<?php

declare(strict_types=1);

namespace App\DTO;

readonly class UserProfileDTO
{
    public function __construct(
        public int $userId,
        public array $allergies,
        public array $preferences,
        public ?int $maxPrepMinutes = null,
        public ?string $fastingStart = null,
        public ?string $fastingEnd = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        if (!isset($data['user_id']) || filter_var($data['user_id'], FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException('A valid user_id is required');
        }
        $allergies = $data['allergies'] ?? [];
        $preferences = $data['preferences'] ?? [];
        if (!is_array($allergies) || !is_array($preferences)) {
            throw new \InvalidArgumentException('Allergies and preferences must be arrays');
        }
        foreach ($allergies as $allergy) {
            if (!is_string($allergy) || trim($allergy) === '') {
                throw new \InvalidArgumentException('Allergies must contain non-empty strings');
            }
        }
        $maxPrep = $data['max_prep_minutes'] ?? null;
        if ($maxPrep !== null && (filter_var($maxPrep, FILTER_VALIDATE_INT) === false || (int) $maxPrep < 1)) {
            throw new \InvalidArgumentException('max_prep_minutes must be a positive integer');
        }
        if ($maxPrep !== null && (int) $maxPrep > 600) {
            throw new \InvalidArgumentException('max_prep_minutes cannot exceed 600');
        }
        $calorieGoal = $preferences['daily_calorie_goal'] ?? null;
        if ($calorieGoal !== null
            && (filter_var($calorieGoal, FILTER_VALIDATE_INT) === false || (int) $calorieGoal < 500 || (int) $calorieGoal > 10000)
        ) {
            throw new \InvalidArgumentException('Daily calorie goal must be between 500 and 10000');
        }
        $proteinGoal = $preferences['daily_protein_goal_g'] ?? null;
        if ($proteinGoal !== null && (!is_numeric($proteinGoal) || (float) $proteinGoal <= 0 || (float) $proteinGoal > 1000)) {
            throw new \InvalidArgumentException('Daily protein goal must be greater than 0 and no more than 1000 grams');
        }
        $dietaryNotes = $preferences['dietary_notes'] ?? null;
        if ($dietaryNotes !== null && (!is_string($dietaryNotes) || strlen($dietaryNotes) > 2000)) {
            throw new \InvalidArgumentException('Dietary preferences must be text no longer than 2000 characters');
        }
        $fastingStart = $data['fasting_start'] ?? null;
        $fastingEnd = $data['fasting_end'] ?? null;
        foreach (['fasting_start' => $fastingStart, 'fasting_end' => $fastingEnd] as $field => $time) {
            if ($time !== null && (!is_string($time) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time))) {
                throw new \InvalidArgumentException(sprintf('%s must be a valid 24-hour time', $field));
            }
        }
        if (($fastingStart === null) !== ($fastingEnd === null)) {
            throw new \InvalidArgumentException('Set both fasting start and end times, or leave both blank');
        }
        return new self(
            (int) $data['user_id'],
            array_values(array_map('strval', $allergies)),
            [
                'daily_calorie_goal' => $calorieGoal === null ? null : (int) $calorieGoal,
                'daily_protein_goal_g' => $proteinGoal === null ? null : (float) $proteinGoal,
                'dietary_notes' => $dietaryNotes,
            ],
            $maxPrep === null ? null : (int) $maxPrep,
            $fastingStart,
            $fastingEnd,
        );
    }

    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'allergies' => $this->allergies,
            'preferences' => $this->preferences,
            'max_prep_minutes' => $this->maxPrepMinutes,
            'fasting_start' => $this->fastingStart,
            'fasting_end' => $this->fastingEnd,
        ];
    }
}
