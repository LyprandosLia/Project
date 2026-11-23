<?php

declare(strict_types=1);

namespace App\Agents;

use App\DTO\ScaledRecipeDTO;
use App\DTO\ShoppingItemDTO;
use App\DTO\ShoppingListDTO;
use App\DTO\Quantity;
use App\Repository\PantryRepository;
use App\Service\CatalogApiClient;

final class ShoppingOptimizerAgent
{
    public function __construct(
        private PantryRepository $pantry,
        private CatalogApiClient $catalog,
    ) {
    }

    public function optimize(int $userId, array $scaledRecipes): ShoppingListDTO
    {
        foreach ($scaledRecipes as $recipe) {
            if (!$recipe instanceof ScaledRecipeDTO) {
                throw new \InvalidArgumentException('Scaled recipes must be ScaledRecipeDTO objects');
            }
        }
        $pantry = $this->pantry->findQuantitiesByUser($userId);
        $aggregated = [];
        foreach ($scaledRecipes as $recipe) {
            foreach ($recipe->ingredients as $ingredient) {
                $unit = $ingredient->quantity->unit;
                $baseUnit = $this->baseUnit($unit);
                $amount = $this->toBase($ingredient->quantity->amount, $unit);
                $key = $ingredient->ingredientId . ':' . $baseUnit;
                if (!isset($aggregated[$key])) {
                    $aggregated[$key] = [
                        'ingredient_id' => $ingredient->ingredientId,
                        'name' => $ingredient->name,
                        'amount' => 0,
                        'unit' => $baseUnit,
                    ];
                }
                $aggregated[$key]['amount'] += $amount;
            }
        }

        $items = [];
        $total = 0.0;
        foreach ($aggregated as $item) {
            $needed = $item['amount'];
            if (isset($pantry[$item['ingredient_id']])) {
                $stock = $pantry[$item['ingredient_id']];
                $stockBaseUnit = $this->baseUnit($stock['unit']);
                if ($stockBaseUnit === $item['unit']) {
                    $needed -= $this->toBase($stock['amount'], $stock['unit']);
                }
            }
            $needed = max(0, $needed);
            if ($needed === 0) {
                continue;
            }
            $quantity = Quantity::create($this->thousandthsToDecimal($needed), $item['unit']);
            $cost = null;
            $currency = null;
            $source = null;
            try {
                $price = $this->catalog->getPrice($item['name'], $quantity->amount, $quantity->unit);
                if (isset($price['price']) && is_numeric($price['price'])) {
                    $cost = round((float) $price['price'], 2);
                    $total += $cost;
                }
                $currency = isset($price['currency']) && is_string($price['currency']) ? $price['currency'] : null;
                $source = isset($price['source']) && is_string($price['source']) ? $price['source'] : null;
            } catch (\Throwable $exception) {
                $cost = null;
            }
            $items[] = new ShoppingItemDTO(
                $item['ingredient_id'],
                $item['name'],
                $quantity,
                $cost,
                $currency,
                $source,
            );
        }
        return new ShoppingListDTO($userId, $items, round($total, 2), 'EUR');
    }

    private function baseUnit(string $unit): string
    {
        return match ($unit) {
            'kg' => 'g',
            'l' => 'ml',
            default => $unit,
        };
    }

    private function toBase(string $amount, string $unit): int
    {
        $thousandths = $this->decimalToThousandths($amount);
        return match ($unit) {
            'kg' => $thousandths * 1000,
            'l' => $thousandths * 1000,
            default => $thousandths,
        };
    }

    private function decimalToThousandths(string $value): int
    {
        $parts = explode('.', $value, 2);
        $whole = (int) $parts[0];
        $fraction = isset($parts[1]) ? str_pad(substr($parts[1], 0, 3), 3, '0') : '000';
        return $whole * 1000 + (int) $fraction;
    }

    private function thousandthsToDecimal(int $value): string
    {
        $whole = intdiv($value, 1000);
        $fraction = $value % 1000;
        if ($fraction === 0) {
            return (string) $whole;
        }
        return $whole . '.' . str_pad((string) $fraction, 3, '0', STR_PAD_LEFT);
    }
}
