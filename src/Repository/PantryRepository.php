<?php

declare(strict_types=1);

namespace App\Repository;

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
        $stmt = $this->pdo->prepare(
            'SELECT pi.ingredient_id, i.name, pi.quantity, pi.unit
             FROM pantry_inventory pi
             INNER JOIN ingredients i ON i.id = pi.ingredient_id
             WHERE pi.user_id = :user_id
             ORDER BY i.name'
        );
        $stmt->execute([':user_id' => $userId]);
        $pantry = [];
        foreach ($stmt->fetchAll() as $row) {
            $pantry[(int) $row['ingredient_id']] = [
                'name' => (string) $row['name'],
                'amount' => number_format((float) $row['quantity'], 3, '.', ''),
                'unit' => (string) $row['unit'],
            ];
        }
        return $pantry;
    }
}
