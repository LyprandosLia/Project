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
        return new self(
            (int) $data['user_id'],
            array_values(array_map('strval', $allergies)),
            $preferences,
            $maxPrep === null ? null : (int) $maxPrep,
            isset($data['fasting_start']) && is_string($data['fasting_start']) ? $data['fasting_start'] : null,
            isset($data['fasting_end']) && is_string($data['fasting_end']) ? $data['fasting_end'] : null,
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
