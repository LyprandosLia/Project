<?php

declare(strict_types=1);

namespace App\Repository;

use App\DTO\MealPlanDTO;
use PDO;

final class MealPlanRepository
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function saveDraft(MealPlanDTO $plan): int
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO meal_plans (user_id, starts_on, ends_on, status)
                 VALUES (:user_id, :starts_on, :ends_on, :status)'
            );
            $stmt->execute([
                ':user_id' => $plan->userId,
                ':starts_on' => $plan->startsOn,
                ':ends_on' => $plan->endsOn,
                ':status' => 'draft',
            ]);
            $planId = (int) $this->pdo->lastInsertId();

            $mealStmt = $this->pdo->prepare(
                'INSERT INTO planned_meals (meal_plan_id, recipe_id, meal_date, meal_slot, servings)
                 VALUES (:meal_plan_id, :recipe_id, :meal_date, :meal_slot, :servings)'
            );
            foreach ($plan->meals as $meal) {
                $mealStmt->execute([
                    ':meal_plan_id' => $planId,
                    ':recipe_id' => $meal->recipeId,
                    ':meal_date' => $meal->date,
                    ':meal_slot' => $meal->slot,
                    ':servings' => $meal->servings,
                ]);
            }
            $this->pdo->commit();
            return $planId;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function saveApproved(MealPlanDTO $plan): int
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO meal_plans (user_id, starts_on, ends_on, status)
                 VALUES (:user_id, :starts_on, :ends_on, :status)'
            );
            $stmt->execute([
                ':user_id' => $plan->userId,
                ':starts_on' => $plan->startsOn,
                ':ends_on' => $plan->endsOn,
                ':status' => 'approved',
            ]);
            $planId = (int) $this->pdo->lastInsertId();

            $mealStmt = $this->pdo->prepare(
                'INSERT INTO planned_meals (meal_plan_id, recipe_id, meal_date, meal_slot, servings)
                 VALUES (:meal_plan_id, :recipe_id, :meal_date, :meal_slot, :servings)'
            );
            foreach ($plan->meals as $meal) {
                $mealStmt->execute([
                    ':meal_plan_id' => $planId,
                    ':recipe_id' => $meal->recipeId,
                    ':meal_date' => $meal->date,
                    ':meal_slot' => $meal->slot,
                    ':servings' => $meal->servings,
                ]);
            }
            $this->pdo->commit();
            return $planId;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function findSavedMeals(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pm.id AS planned_meal_id, pm.meal_date, pm.meal_slot, pm.servings,
                    r.id AS recipe_id, r.name AS recipe_name,
                    rt.rating
             FROM planned_meals pm
             INNER JOIN meal_plans mp ON mp.id = pm.meal_plan_id
             INNER JOIN recipes r ON r.id = pm.recipe_id
             LEFT JOIN ratings rt ON rt.user_id = mp.user_id AND rt.recipe_id = r.id
             WHERE mp.user_id = :user_id AND mp.status = :status
             ORDER BY pm.meal_date DESC, pm.id DESC'
        );
        $stmt->execute([':user_id' => $userId, ':status' => 'approved']);
        return $stmt->fetchAll();
    }
}
