<?php

declare(strict_types=1);

namespace App\Repository;

use App\DTO\PantryItemDTO;
use App\DTO\Quantity;
use PDO;

final class PantryRepository
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function findQuantitiesByUser(int $userId): array
    {
        $pantry = [];
        foreach ($this->findItemsByUser($userId) as $item) {
            $pantry[$item->ingredientId] = [
                'name' => $item->name,
                'amount' => $item->quantity->amount,
                'unit' => $item->quantity->unit,
            ];
        }
        return $pantry;
    }

    public function findItemsByUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pi.ingredient_id, i.name, pi.quantity, pi.unit
             FROM pantry_inventory pi
             INNER JOIN ingredients i ON i.id = pi.ingredient_id
             WHERE pi.user_id = :user_id
             ORDER BY i.name'
        );
        $stmt->execute([':user_id' => $userId]);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = new PantryItemDTO(
                (int) $row['ingredient_id'],
                (string) $row['name'],
                Quantity::create(number_format((float) $row['quantity'], 3, '.', ''), (string) $row['unit']),
            );
        }
        return $items;
    }

    public function saveQuantity(int $userId, int $ingredientId, Quantity $quantity): void
    {
        if ($userId < 1 || $ingredientId < 1) {
            throw new \InvalidArgumentException('User and ingredient IDs must be positive');
        }

        $this->pdo->beginTransaction();
        try {
            $this->saveQuantityWithinTransaction($userId, $ingredientId, $quantity);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function saveNamedQuantity(int $userId, string $ingredientName, Quantity $quantity): void
    {
        $name = trim(preg_replace('/\s+/u', ' ', $ingredientName) ?? '');
        $characterCount = preg_match_all('/./us', $name);
        if ($userId < 1) {
            throw new \InvalidArgumentException('User ID must be positive.');
        }
        if ($name === '' || $characterCount === false || $characterCount > 160) {
            throw new \InvalidArgumentException('Enter an ingredient name of 1 to 160 characters.');
        }
        if ((float) $quantity->amount <= 0) {
            throw new \InvalidArgumentException('Custom pantry ingredients must have a quantity greater than 0.');
        }

        $this->pdo->beginTransaction();
        try {
            $ingredient = $this->pdo->prepare(
                'INSERT INTO ingredients (name, canonical_unit)
                 VALUES (:name, :canonical_unit)
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
            );
            $ingredient->execute([
                ':name' => $name,
                ':canonical_unit' => $quantity->unit,
            ]);
            $ingredientId = (int) $this->pdo->lastInsertId();
            if ($ingredientId < 1) {
                throw new \RuntimeException('Could not resolve the custom pantry ingredient.');
            }

            $this->saveQuantityWithinTransaction($userId, $ingredientId, $quantity);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function saveQuantityWithinTransaction(int $userId, int $ingredientId, Quantity $quantity): void
    {
        $find = $this->pdo->prepare(
            'SELECT id FROM pantry_inventory
             WHERE user_id = :user_id AND ingredient_id = :ingredient_id
             ORDER BY id
             FOR UPDATE'
        );
        $find->execute([':user_id' => $userId, ':ingredient_id' => $ingredientId]);
        $row = $find->fetch();
        if ((float) $quantity->amount === 0.0) {
            $delete = $this->pdo->prepare(
                'DELETE FROM pantry_inventory WHERE user_id = :user_id AND ingredient_id = :ingredient_id'
            );
            $delete->execute([':user_id' => $userId, ':ingredient_id' => $ingredientId]);
        } elseif ($row === false) {
            $insert = $this->pdo->prepare(
                'INSERT INTO pantry_inventory (user_id, ingredient_id, quantity, unit)
                 VALUES (:user_id, :ingredient_id, :quantity, :unit)'
            );
            $insert->execute([
                ':user_id' => $userId,
                ':ingredient_id' => $ingredientId,
                ':quantity' => $quantity->amount,
                ':unit' => $quantity->unit,
            ]);
        } else {
            $update = $this->pdo->prepare(
                'UPDATE pantry_inventory
                 SET quantity = :quantity, unit = :unit
                 WHERE user_id = :user_id AND ingredient_id = :ingredient_id'
            );
            $update->execute([
                ':quantity' => $quantity->amount,
                ':unit' => $quantity->unit,
                ':user_id' => $userId,
                ':ingredient_id' => $ingredientId,
            ]);
        }
    }
}
