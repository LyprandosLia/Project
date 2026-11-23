<?php

declare(strict_types=1);

namespace App\Repository;

use App\DTO\UserProfileDTO;
use PDO;

final class UserRepository
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function findProfile(int $userId): ?UserProfileDTO
    {
        $userStmt = $this->pdo->prepare(
            'SELECT id FROM users WHERE id = :user_id'
        );
        $userStmt->execute([':user_id' => $userId]);
        if ($userStmt->fetch() === false) {
            return null;
        }

        $preferenceStmt = $this->pdo->prepare(
            'SELECT daily_calorie_goal, daily_protein_goal_g, max_prep_minutes,
                    fasting_start, fasting_end, dietary_notes
             FROM user_preferences WHERE user_id = :user_id'
        );
        $preferenceStmt->execute([':user_id' => $userId]);
        $preferenceRow = $preferenceStmt->fetch() ?: [];

        $allergyStmt = $this->pdo->prepare(
            'SELECT allergen FROM user_allergies WHERE user_id = :user_id ORDER BY allergen'
        );
        $allergyStmt->execute([':user_id' => $userId]);
        $allergies = [];
        foreach ($allergyStmt->fetchAll() as $row) {
            $allergies[] = (string) $row['allergen'];
        }

        $preferences = [
            'daily_calorie_goal' => isset($preferenceRow['daily_calorie_goal']) ? (int) $preferenceRow['daily_calorie_goal'] : null,
            'daily_protein_goal_g' => isset($preferenceRow['daily_protein_goal_g']) ? (float) $preferenceRow['daily_protein_goal_g'] : null,
            'dietary_notes' => $preferenceRow['dietary_notes'] ?? null,
        ];

        return UserProfileDTO::fromArray([
            'user_id' => $userId,
            'allergies' => $allergies,
            'preferences' => $preferences,
            'max_prep_minutes' => $preferenceRow['max_prep_minutes'] ?? null,
            'fasting_start' => $preferenceRow['fasting_start'] ?? null,
            'fasting_end' => $preferenceRow['fasting_end'] ?? null,
        ]);
    }

    public function findRecentRecipeIds(int $userId, int $limit = 7): array
    {
        if ($limit < 1) {
            $limit = 1;
        }
        $stmt = $this->pdo->prepare(
            'SELECT pm.recipe_id
             FROM planned_meals pm
             INNER JOIN meal_plans mp ON mp.id = pm.meal_plan_id
             WHERE mp.user_id = :user_id
             ORDER BY pm.meal_date DESC, pm.id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $ids = [];
        foreach ($stmt->fetchAll() as $row) {
            $ids[] = (int) $row['recipe_id'];
        }
        return $ids;
    }

    public function findRatingsByUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT recipe_id, rating, comment, created_at
             FROM ratings
             WHERE user_id = :user_id
             ORDER BY created_at DESC'
        );
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function saveRating(int $userId, int $recipeId, int $rating): void
    {
        if ($rating < 1 || $rating > 5) {
            throw new \InvalidArgumentException('Rating must be between 1 and 5');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO ratings (user_id, recipe_id, rating)
             VALUES (:user_id, :recipe_id, :rating)
             ON DUPLICATE KEY UPDATE rating = VALUES(rating), created_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':recipe_id' => $recipeId,
            ':rating' => $rating,
        ]);
    }
}
