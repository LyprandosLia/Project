<?php

declare(strict_types=1);

$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require $composerAutoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'App\\';
        if (str_starts_with($class, $prefix)) {
            $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) {
                require $path;
            }
        }
    });
}

use App\Agents\MealPlannerAgent;
use App\Agents\NutritionFeedbackAgent;
use App\Agents\Orchestrator;
use App\Agents\RecipeSpecialistAgent;
use App\Agents\ShoppingOptimizerAgent;
use App\Repository\MealPlanRepository;
use App\Repository\PantryRepository;
use App\Repository\RecipeRepository;
use App\Repository\UserRepository;
use App\Service\CatalogApiClient;
use App\Service\LlmClient;

require __DIR__ . '/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_token'];

$siteUser = (string) (getenv('SITE_USERNAME') ?: '');
$sitePass = (string) (getenv('SITE_PASSWORD') ?: '');
$givenUser = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
$givenPass = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');

if ($siteUser === '' || $sitePass === '' || !hash_equals($siteUser, $givenUser) || !hash_equals($sitePass, $givenPass)) {
    header('WWW-Authenticate: Basic realm="Multi-Agent Food OS"');
    header('HTTP/1.1 401 Unauthorized');
    echo 'Authentication required.';
    exit;
}

$error = null;
$success = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);
$userId = 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $postedToken)) {
        $error = 'Invalid CSRF token. Please retry.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'add_item' && isset($_POST['name'])) {
                add_item((string) $_POST['name'], (string) ($_POST['note'] ?? ''));
                $success = 'Item added.';
            } elseif ($action === 'save_profile') {
                $calorieText = trim((string) ($_POST['daily_calorie_goal'] ?? ''));
                $calorieGoal = $calorieText === '' ? null : filter_var($calorieText, FILTER_VALIDATE_INT);
                if ($calorieText !== '' && $calorieGoal === false) {
                    throw new InvalidArgumentException('Daily calorie goal must be a whole number.');
                }

                $proteinText = trim((string) ($_POST['daily_protein_goal_g'] ?? ''));
                $proteinGoal = $proteinText === '' ? null : filter_var($proteinText, FILTER_VALIDATE_FLOAT);
                if ($proteinText !== '' && $proteinGoal === false) {
                    throw new InvalidArgumentException('Daily protein goal must be a number.');
                }

                $maxPrepText = trim((string) ($_POST['max_prep_minutes'] ?? ''));
                $maxPrepMinutes = $maxPrepText === '' ? null : filter_var($maxPrepText, FILTER_VALIDATE_INT);
                if ($maxPrepText !== '' && $maxPrepMinutes === false) {
                    throw new InvalidArgumentException('Maximum preparation time must be a whole number.');
                }

                $allergies = preg_split('/[,\r\n]+/', (string) ($_POST['allergies'] ?? '')) ?: [];
                $allergies = array_values(array_unique(array_filter(
                    array_map('trim', $allergies),
                    static fn (string $allergen): bool => $allergen !== '',
                )));
                $profile = \App\DTO\UserProfileDTO::fromArray([
                    'user_id' => $userId,
                    'allergies' => $allergies,
                    'preferences' => [
                        'daily_calorie_goal' => $calorieGoal,
                        'daily_protein_goal_g' => $proteinGoal,
                        'dietary_notes' => trim((string) ($_POST['dietary_notes'] ?? '')),
                    ],
                    'max_prep_minutes' => $maxPrepMinutes,
                    'fasting_start' => trim((string) ($_POST['fasting_start'] ?? '')) ?: null,
                    'fasting_end' => trim((string) ($_POST['fasting_end'] ?? '')) ?: null,
                ]);
                (new UserRepository(get_db()))->saveProfile($profile);
                $success = 'Your goals and food preferences have been saved.';
            } elseif ($action === 'save_pantry') {
                $ingredientId = filter_var($_POST['ingredient_id'] ?? null, FILTER_VALIDATE_INT);
                if ($ingredientId === false || $ingredientId === null || $ingredientId < 1) {
                    throw new InvalidArgumentException('Choose a valid pantry ingredient.');
                }
                $quantity = \App\DTO\Quantity::fromArray([
                    'amount' => trim((string) ($_POST['quantity'] ?? '')),
                    'unit' => trim((string) ($_POST['unit'] ?? '')),
                ]);
                (new PantryRepository(get_db()))->saveQuantity($userId, $ingredientId, $quantity);
                $success = (float) $quantity->amount === 0.0
                    ? 'Pantry stock removed.'
                    : 'Pantry stock saved.';
            } elseif ($action === 'add_custom_pantry') {
                $ingredientName = $_POST['ingredient_name'] ?? null;
                $quantityAmount = $_POST['quantity'] ?? null;
                $quantityUnit = $_POST['unit'] ?? null;
                if (!is_string($ingredientName) || !is_string($quantityAmount) || !is_string($quantityUnit)) {
                    throw new InvalidArgumentException('Enter an ingredient name, quantity, and unit.');
                }
                $quantity = \App\DTO\Quantity::fromArray([
                    'amount' => trim($quantityAmount),
                    'unit' => trim($quantityUnit),
                ]);
                (new PantryRepository(get_db()))->saveNamedQuantity(
                    $userId,
                    $ingredientName,
                    $quantity,
                );
                $success = 'Custom ingredient added to your pantry.';
            } elseif ($action === 'generate') {
                $pdo = get_db();
                $users = new UserRepository($pdo);
                $recipes = new RecipeRepository($pdo);
                $pantry = new PantryRepository($pdo);
                $llm = new LlmClient();
                $catalog = new CatalogApiClient();
                $profile = $users->findProfile($userId);
                if ($profile === null) {
                    throw new RuntimeException('User profile was not found.');
                }
                $orchestrator = new Orchestrator(
                    new MealPlannerAgent($users, $recipes, $llm),
                    new RecipeSpecialistAgent($recipes),
                    new ShoppingOptimizerAgent($pantry, $catalog),
                    new NutritionFeedbackAgent($users, $catalog),
                    $pantry,
                );
                if (!is_array($_POST['meal_slots'] ?? null)) {
                    throw new InvalidArgumentException('Choose at least one meal slot.');
                }
                $request = \App\DTO\MealPlanRequestDTO::fromArray([
                    'starts_on' => (string) ($_POST['starts_on'] ?? ''),
                    'meal_slots' => $_POST['meal_slots'],
                    'servings' => $_POST['servings'] ?? null,
                    'purchase_mode' => (string) ($_POST['purchase_mode'] ?? ''),
                ]);
                $_SESSION['generated_result'] = $orchestrator->run($profile, $request);
                $success = 'Weekly plan generated for review. Nothing has been saved.';
            } elseif ($action === 'approve') {
                $result = $_SESSION['generated_result'] ?? null;
                if (!$result instanceof \App\DTO\OrchestrationResultDTO) {
                    throw new RuntimeException('No generated plan is awaiting approval.');
                }
                if (!$result->validation->approved) {
                    throw new RuntimeException('A plan that failed nutrition validation cannot be saved. Revise your profile or discard it.');
                }
                $planId = (new MealPlanRepository(get_db()))->saveApproved($result->plan);
                unset($_SESSION['generated_result']);
                $success = sprintf('Plan approved and saved (meal plan #%d).', $planId);
            } elseif ($action === 'discard') {
                unset($_SESSION['generated_result']);
                $success = 'Generated plan discarded.';
            } elseif ($action === 'rate') {
                $recipeId = filter_input(INPUT_POST, 'recipe_id', FILTER_VALIDATE_INT);
                $rating = filter_input(INPUT_POST, 'rating', FILTER_VALIDATE_INT);
                if ($recipeId === false || $rating === false || $rating < 1 || $rating > 5) {
                    throw new InvalidArgumentException('Choose a valid 1 to 5 star rating.');
                }
                (new UserRepository(get_db()))->saveRating($userId, (int) $recipeId, (int) $rating);
                $success = 'Meal rating saved.';
            }
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
    if ($error === null) {
        if ($success !== null) {
            $_SESSION['flash_success'] = $success;
        }
        header('Location: /');
        exit;
    }
}

$pdo = get_db();
$users = new UserRepository($pdo);
$profile = $users->findProfile($userId);
if ($profile === null) {
    throw new RuntimeException('User profile was not found.');
}
$recipeRepository = new RecipeRepository($pdo);
$ingredientCatalog = $recipeRepository->findAllIngredients();
$pantryItems = (new PantryRepository($pdo))->findItemsByUser($userId);
$items = get_items();
$generatedResult = $_SESSION['generated_result'] ?? null;
$savedMeals = [];
try {
    $savedMeals = (new MealPlanRepository($pdo))->findSavedMeals($userId);
} catch (Throwable $exception) {
    $savedMeals = [];
}

require dirname(__DIR__) . '/views/dashboard.php';
