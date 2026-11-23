<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (str_starts_with($class, $prefix)) {
        $relative = substr($class, strlen($prefix));
        $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

use App\Agents\MealPlannerAgent;
use App\Agents\RecipeSpecialistAgent;
use App\Agents\ShoppingOptimizerAgent;
use App\Repository\MealPlanRepository;
use App\Repository\PantryRepository;
use App\Repository\RecipeRepository;
use App\Repository\UserRepository;
use App\Service\CatalogApiClient;
use App\Service\LlmClient;

$host = getenv('DB_HOST') ?: 'db';
$database = getenv('DB_NAME');
$username = getenv('DB_USER');
$password = getenv('DB_PASSWORD');

if (!$database || !$username || !$password) {
    fwrite(STDERR, "Skipped database-backed Phase 3 verification: DB_NAME, DB_USER and DB_PASSWORD are required.\n");
    exit(2);
}

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $database),
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );

    $users = new UserRepository($pdo);
    $recipes = new RecipeRepository($pdo);
    $pantry = new PantryRepository($pdo);
    $llm = new LlmClient();
    $catalog = new CatalogApiClient();

    $profile = $users->findProfile(1);
    if ($profile === null) {
        throw new RuntimeException('Verification user 1 was not found');
    }
    $mealPlanner = new MealPlannerAgent($users, $recipes, $llm);
    $plan = $mealPlanner->propose($profile, date('Y-m-d'));

    $recipeSpecialist = new RecipeSpecialistAgent($recipes);
    $scaledRecipes = [];
    foreach ($plan->meals as $meal) {
        $scaledRecipes[] = $recipeSpecialist->scale($meal, $profile->maxPrepMinutes);
    }

    $shoppingOptimizer = new ShoppingOptimizerAgent($pantry, $catalog);
    $shoppingList = $shoppingOptimizer->optimize($profile->userId, $scaledRecipes);

    echo json_encode([
        'dry_run' => true,
        'llm_mode' => $llm->isDryRun() ? 'mock' : 'provider',
        'plan_meals' => count($plan->meals),
        'scaled_recipes' => count($scaledRecipes),
        'shopping_items' => count($shoppingList->items),
        'estimated_total' => $shoppingList->estimatedTotal,
        'currency' => $shoppingList->currency,
        'live_schedule_write_performed' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Phase 3 verification failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
