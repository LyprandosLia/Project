<?php

declare(strict_types=1);

namespace App\Repository;

use App\DTO\RecipeDTO;
use App\DTO\RecipeIngredientDTO;
use App\DTO\Quantity;
use PDO;

final class RecipeRepository
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function findAll(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, slug, name, description, prep_minutes, base_servings, instructions
             FROM recipes ORDER BY name'
        );
        $stmt->execute();
        $recipes = [];
        foreach ($stmt->fetchAll() as $row) {
            $row['ingredients'] = $this->findIngredients((int) $row['id']);
            $recipes[] = RecipeDTO::fromArray($row);
        }
        return $recipes;
    }

    public function findById(int $recipeId): ?RecipeDTO
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, slug, name, description, prep_minutes, base_servings, instructions
             FROM recipes WHERE id = :recipe_id'
        );
        $stmt->execute([':recipe_id' => $recipeId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $row['ingredients'] = $this->findIngredients($recipeId);
        return RecipeDTO::fromArray($row);
    }

    public function findByIds(array $recipeIds): array
    {
        if ($recipeIds === []) {
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', $recipeIds)));
        $placeholders = [];
        $parameters = [];
        foreach ($ids as $index => $id) {
            $key = ':recipe_id_' . $index;
            $placeholders[] = $key;
            $parameters[$key] = $id;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, slug, name, description, prep_minutes, base_servings, instructions
             FROM recipes WHERE id IN (' . implode(', ', $placeholders) . ') ORDER BY name'
        );
        $stmt->execute($parameters);
        $recipes = [];
        foreach ($stmt->fetchAll() as $row) {
            $row['ingredients'] = $this->findIngredients((int) $row['id']);
            $recipes[] = RecipeDTO::fromArray($row);
        }
        return $recipes;
    }

    public function findIngredients(int $recipeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ri.ingredient_id, i.name, ri.quantity, ri.unit, ri.notes
             FROM recipe_ingredients ri
             INNER JOIN ingredients i ON i.id = ri.ingredient_id
             WHERE ri.recipe_id = :recipe_id
             ORDER BY i.name'
        );
        $stmt->execute([':recipe_id' => $recipeId]);
        $ingredients = [];
        foreach ($stmt->fetchAll() as $row) {
            $ingredients[] = RecipeIngredientDTO::fromArray([
                'ingredient_id' => (int) $row['ingredient_id'],
                'name' => $row['name'],
                'quantity' => [
                    'amount' => number_format((float) $row['quantity'], 3, '.', ''),
                    'unit' => $row['unit'],
                ],
                'notes' => $row['notes'],
            ]);
        }
        return $ingredients;
    }
}
