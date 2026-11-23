<?php

declare(strict_types=1);

namespace App\DTO;

readonly class Quantity
{
    private function __construct(public string $amount, public string $unit)
    {
    }

    public static function fromArray(array $data): self
    {
        $amount = self::requiredString($data, 'amount');
        $unit = self::requiredString($data, 'unit');
        if (!preg_match('/^-?\d+(?:\.\d{1,3})?$/', $amount)) {
            throw new \InvalidArgumentException('Quantity amount must be a decimal string');
        }
        if (!in_array($unit, ['g', 'kg', 'ml', 'l', 'piece'], true)) {
            throw new \InvalidArgumentException('Unsupported quantity unit');
        }
        if ((float) $amount < 0) {
            throw new \InvalidArgumentException('Quantity amount cannot be negative');
        }
        return new self($amount, $unit);
    }

    public static function create(string $amount, string $unit): self
    {
        return self::fromArray(['amount' => $amount, 'unit' => $unit]);
    }

    public function toArray(): array
    {
        return ['amount' => $this->amount, 'unit' => $this->unit];
    }

    private static function requiredString(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key])) {
            throw new \InvalidArgumentException(sprintf('Missing or invalid "%s"', $key));
        }
        $value = trim($data[$key]);
        if ($value === '') {
            throw new \InvalidArgumentException(sprintf('"%s" cannot be empty', $key));
        }
        return $value;
    }
}
