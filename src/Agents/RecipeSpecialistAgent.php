<?php

declare(strict_types=1);

namespace App\Agents;

use App\DTO\MealSelectionDTO;
use App\DTO\Quantity;
use App\DTO\ScaledIngredientDTO;
use App\DTO\ScaledRecipeDTO;
use App\Repository\RecipeRepository;

final class RecipeSpecialistAgent
{
    public function __construct(private RecipeRepository $recipes)
    {
    }

    public function scale(MealSelectionDTO $selection, ?int $maxPrepMinutes = null): ScaledRecipeDTO
    {
        $recipe = $this->recipes->findById($selection->recipeId);
        if ($recipe === null) {
            throw new \RuntimeException('Recipe not found: ' . $selection->recipeId);
        }
        $alternative = null;
        if ($maxPrepMinutes !== null && $recipe->prepMinutes > $maxPrepMinutes) {
            $alternative = sprintf('Recipe requires %d minutes; the limit is %d minutes.', $recipe->prepMinutes, $maxPrepMinutes);
        }

        $scaled = [];
        foreach ($recipe->ingredients as $ingredient) {
            if (!in_array($ingredient->quantity->unit, ['g', 'kg', 'ml', 'l', 'piece'], true)) {
                throw new \UnexpectedValueException('Unsupported recipe quantity unit: ' . $ingredient->quantity->unit);
            }
            $amount = $this->decimalToThousandths($ingredient->quantity->amount);
            $numerator = $amount * $selection->servings;
            $scaledThousandths = intdiv($numerator + intdiv($recipe->baseServings, 2), $recipe->baseServings);
            $scaled[] = new ScaledIngredientDTO(
                $ingredient->ingredientId,
                $ingredient->name,
                Quantity::create($this->thousandthsToDecimal($scaledThousandths), $ingredient->quantity->unit),
            );
        }
        return new ScaledRecipeDTO(
            $recipe->id,
            $recipe->name,
            $recipe->baseServings,
            $selection->servings,
            $scaled,
            $alternative,
        );
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
        return $whole . '.' . str_pad(rtrim(str_pad((string) $fraction, 3, '0', STR_PAD_LEFT), '0'), 3, '0');
    }
}
