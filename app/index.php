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
$success = null;
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
            } elseif ($action === 'generate') {
                $pdo = get_db();
                $users = new UserRepository($pdo);
                $recipes = new RecipeRepository($pdo);
                $pantry = new PantryRepository($pdo);
                $llm = new LlmClient();
                $profile = $users->findProfile($userId);
                if ($profile === null) {
                    throw new RuntimeException('User profile was not found.');
                }
                $orchestrator = new Orchestrator(
                    new MealPlannerAgent($users, $recipes, $llm),
                    new RecipeSpecialistAgent($recipes),
                    new ShoppingOptimizerAgent($pantry, new CatalogApiClient()),
                    new NutritionFeedbackAgent($users),
                );
                $startsOn = (string) ($_POST['starts_on'] ?? date('Y-m-d'));
                $startsOn = (new DateTimeImmutable($startsOn))->format('Y-m-d');
                $_SESSION['generated_result'] = $orchestrator->run($profile, $startsOn);
                $success = 'Weekly plan generated for review. Nothing has been saved.';
            } elseif ($action === 'approve') {
                $result = $_SESSION['generated_result'] ?? null;
                if (!$result instanceof \App\DTO\OrchestrationResultDTO) {
                    throw new RuntimeException('No generated plan is awaiting approval.');
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
        header('Location: /');
        exit;
    }
}

$items = get_items();
$generatedResult = $_SESSION['generated_result'] ?? null;
$savedMeals = [];
try {
    $savedMeals = (new MealPlanRepository(get_db()))->findSavedMeals($userId);
} catch (Throwable $exception) {
    $savedMeals = [];
}

require dirname(__DIR__) . '/views/dashboard.php';
