<?php
// Front controller. Todas las peticiones no estáticas pasan por aquí.

// Con `php -S` (php -S localhost:8001 -t public public/index.php) el router
// intercepta también los ficheros estáticos: devolver false los sirve tal cual.
if (PHP_SAPI === 'cli-server') {
    $reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    $reqFile = __DIR__ . $reqPath;
    if ($reqFile !== __FILE__ && is_file($reqFile)) {
        if (str_ends_with($reqPath, '.webmanifest')) {
            header('Content-Type: application/manifest+json');
            readfile($reqFile);
            exit;
        }
        return false;
    }
}

$config = require __DIR__ . '/../config.php';
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/helpers.php';

use NutriPlan\Auth;
use NutriPlan\Db;
use NutriPlan\Mistral;
use NutriPlan\Nutrition;
use NutriPlan\Push;
use NutriPlan\Updater;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

if (($config['APP_ENV'] ?? '') !== 'production') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

$twig = new Environment(new FilesystemLoader(__DIR__ . '/../templates'), [
    'cache' => __DIR__ . '/../var/cache/twig',
    'auto_reload' => true,
]);
$twig->addFunction(new \Twig\TwigFunction('meal_target', function (array $needs, string $mealType) {
    return mealCalorieTarget($mealType, (int)($needs['targetCalories'] ?? 0));
}));

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

// HTML siempre fresco; los estaticos versionados los cachea public/.htaccess.
if (!str_starts_with($path, '/api/')) {
    header('Cache-Control: no-store, must-revalidate');
}

function jsonOut($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonBody(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

try {
    $pdo = Db::pdo($config);
} catch (Throwable $e) {
    if (str_starts_with($path, '/api/')) jsonOut(['success' => false, 'error' => 'Sin conexión a la base de datos'], 500);
    http_response_code(500);
    echo 'Error de base de datos. Revisa .env (DB_*).';
    exit;
}

// --- API ---

if ($path === '/api/auth/config') {
    jsonOut(['clientId' => $config['GOOGLE_CLIENT_ID'] ?: null, 'googleEnabled' => (bool)$config['GOOGLE_CLIENT_ID']]);
}

if ($path === '/api/auth/google' && $method === 'POST') {
    $body = jsonBody();
    $idToken = $body['idToken'] ?? '';
    if (!is_string($idToken) || $idToken === '') jsonOut(['success' => false, 'error' => 'Falta el token de Google'], 400);
    try {
        $id = Auth::verifyGoogleIdToken($idToken, $config['GOOGLE_CLIENT_ID']);
    } catch (Throwable $e) {
        jsonOut(['success' => false, 'error' => $e->getMessage()], 401);
    }
    Auth::login($pdo, $id['sub'], $id['email'], $id['name'], $id['picture'], Auth::isSecure($config));
    $user = Db::getUser($pdo, $id['sub']);
    jsonOut(['success' => true, 'user' => ['name' => $user['name'] ?? null, 'email' => $user['email'] ?? null, 'picture' => $user['picture'] ?? null]]);
}

if ($path === '/api/auth/logout' && $method === 'POST') {
    Auth::logout($pdo);
    jsonOut(['success' => true]);
}

$me = Auth::currentSub($pdo);

if ($path === '/robots.txt') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\nDisallow: /\n";
    exit;
}

if ($path === '/api/update' && $method === 'GET') {
    if (!$me) jsonOut(['success' => false, 'error' => 'Sesión no iniciada'], 401);
    $lat = Updater::latest($config['GITHUB_REPO'] ?? '');
    if (isset($lat['error'])) jsonOut(['success' => false, 'error' => $lat['error']], 502);
    $cur = Updater::current();
    jsonOut(['success' => true, 'current' => $cur, 'latest' => $lat['tag'],
        'available' => version_compare($lat['tag'], $cur, '>'), 'notes' => $lat['notes']]);
}

if ($path === '/api/update' && $method === 'POST') {
    if (!$me) jsonOut(['success' => false, 'error' => 'Sesión no iniciada'], 401);
    jsonOut(Updater::run($pdo, $config['GITHUB_REPO'] ?? '', Updater::current()));
}

if ($path === '/api/me/sync' && $method === 'GET') {
    if (!$me) jsonOut(['success' => false, 'error' => 'Sesión no iniciada'], 401);
    $user = Db::getUser($pdo, $me);
    jsonOut(['success' => true,
        'user' => ['name' => $user['name'] ?? null, 'email' => $user['email'] ?? null, 'picture' => $user['picture'] ?? null],
        'data' => Db::getAllKv($pdo, $me),
    ]);
}

if ($path === '/api/me/sync' && $method === 'PUT') {
    if (!$me) jsonOut(['success' => false, 'error' => 'Sesión no iniciada'], 401);
    $body = jsonBody();
    if (!is_array($body['data'] ?? null)) jsonOut(['success' => false, 'error' => 'Faltan datos'], 400);
    foreach (['nutriplan_profile', 'nutriplan_weight_history', 'nutriplan_weekly_plan', 'nutriplan_custom_recipes'] as $key) {
        $value = $body['data'][$key] ?? null;
        if (!is_string($value)) continue;
        if (strlen($value) > 1000000) jsonOut(['success' => false, 'error' => "Dato demasiado grande: $key"], 400);
        Db::putKv($pdo, $me, $key, $value);
    }
    jsonOut(['success' => true]);
}


// --- API ajustes Mistral ---

if ($path === '/api/settings/mistral' && $method === 'GET') {
    $s = Mistral::getSettings();
    jsonOut(['configured' => $s['configured'], 'model' => $s['model'], 'models' => Mistral::MODELS, 'apiKeyMasked' => Mistral::maskKey($s['apiKey'])]);
}

if ($path === '/api/settings/mistral' && $method === 'POST') {
    $body = jsonBody();
    if (!empty($body['clear'])) {
        Mistral::clearApiKey();
        $s = Mistral::getSettings();
        jsonOut(['configured' => false, 'model' => $s['model'], 'models' => Mistral::MODELS, 'apiKeyMasked' => '']);
    }
    if (isset($body['apiKey']) && !is_string($body['apiKey'])) jsonOut(['success' => false, 'error' => 'Clave inválida'], 400);
    $saved = Mistral::saveSettings(array_filter([
        'apiKey' => $body['apiKey'] ?? null,
        'model' => $body['model'] ?? null,
    ], fn($v) => $v !== null));
    jsonOut(['success' => true, 'configured' => (bool)$saved['apiKey'], 'model' => $saved['model'],
        'models' => Mistral::MODELS, 'apiKeyMasked' => Mistral::maskKey($saved['apiKey'])]);
}

if ($path === '/api/settings/mistral/test' && $method === 'POST') {
    $body = jsonBody();
    try {
        $reply = Mistral::testConnection(
            isset($body['apiKey']) && $body['apiKey'] !== '' ? $body['apiKey'] : null,
            $body['model'] ?? null);
        jsonOut(['success' => true, 'reply' => $reply]);
    } catch (Throwable $e) {
        jsonOut(['success' => false, 'error' => $e->getMessage()]);
    }
}

// --- API recetas IA ---

if ($path === '/api/ai-recipe-recommendation' && $method === 'POST') {
    $body = jsonBody();
    $targetKcal = isset($body['targetCaloriesForMeal']) ? (int)$body['targetCaloriesForMeal'] : null;
    try {
        $s = Mistral::getSettings();
        if (!$s['configured']) {
            jsonOut(['success' => true, 'isAiGenerated' => false, 'recipes' => aiFallbackRecipes($body)]);
        }
        $text = Mistral::chat([
            ['role' => 'system', 'content' => aiSystemPrompt($body, $targetKcal)],
            ['role' => 'user', 'content' => 'Genera 2 recetas completas para la ingesta ' . ($body['mealType'] ?? '?')
                . " de $targetKcal kcal con el antojo o idea: \"" . ($body['cravingText'] ?? '') . '". Respeta que NO tenga alérgenos: '
                . implode(', ', $body['allergies'] ?? []) . '.'],
        ], true, 3000);
        $parsed = json_decode($text, true);
        if (!is_array($parsed)) $parsed = null;
        elseif (array_is_list($parsed)) { /* ok */ }
        else $parsed = [$parsed];
        if (!$parsed) $parsed = aiFallbackRecipes($body);
        $mealType = $body['mealType'] ?? 'comida';
        $final = array_map(function ($r) use ($mealType) {
            $r['id'] = $r['id'] ?? ('ai-' . time() . '-' . rand(100, 999));
            $r['imageEmoji'] = $r['imageEmoji'] ?? ($mealType === 'desayuno' ? '🥣' : ($mealType === 'cena' ? '🥗' : '🍲'));
            $tags = is_array($r['tags'] ?? null) ? $r['tags'] : [];
            $tags[] = 'Recomendado por IA';
            $r['tags'] = $tags;
            return $r;
        }, $parsed);
        jsonOut(['success' => true, 'isAiGenerated' => true, 'recipes' => $final]);
    } catch (Throwable $e) {
        jsonOut(['success' => true, 'isAiGenerated' => false, 'recipes' => aiFallbackRecipes($body), 'fallbackReason' => $e->getMessage()]);
    }
}

// --- API push ---

if ($path === '/api/push/public-key' && $method === 'GET') {
    try {
        jsonOut(['publicKey' => Push::getVapidPublicKey()]);
    } catch (Throwable $e) {
        jsonOut(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

if ($path === '/api/push/status' && $method === 'GET') {
    jsonOut(['success' => true, 'subscriptions' => Push::countSubscriptions()]);
}

if ($path === '/api/push/subscribe' && $method === 'POST') {
    $body = jsonBody();
    $sub = $body['subscription'] ?? $body;
    $endpoint = $sub['endpoint'] ?? '';
    if (!is_string($endpoint) || !str_starts_with($endpoint, 'https://')) jsonOut(['success' => false, 'error' => 'Suscripción inválida'], 400);
    if (!is_string($sub['keys']['p256dh'] ?? null) || !is_string($sub['keys']['auth'] ?? null)) {
        jsonOut(['success' => false, 'error' => 'Faltan claves p256dh/auth'], 400);
    }
    Push::saveSubscription($me, $endpoint, $sub['keys']['p256dh'], $sub['keys']['auth']);
    jsonOut(['success' => true]);
}

if ($path === '/api/push/unsubscribe' && $method === 'POST') {
    $body = jsonBody();
    if (empty($body['endpoint'])) jsonOut(['success' => false, 'error' => 'Falta endpoint'], 400);
    Push::removeSubscription($body['endpoint']);
    jsonOut(['success' => true]);
}

if ($path === '/api/push/test' && $method === 'POST') {
    $body = jsonBody();
    $payload = ['title' => 'NutriPlan · Push activadas', 'body' => 'Avisos funcionando en tu PWA. Sin servicios de terceros.', 'url' => '/', 'ts' => time()];
    try {
        if (!empty($body['endpoint'])) {
            $target = null;
            foreach (Push::listSubscriptions(null) as $s) {
                if ($s['endpoint'] === $body['endpoint']) { $target = $s; break; }
            }
            if (!$target) jsonOut(['success' => false, 'error' => 'Ese dispositivo no está suscrito'], 404);
            $r = Push::sendToSubscription($target, $payload);
            jsonOut(['success' => $r['ok'], 'status' => $r['status']]);
        }
        $targets = Push::listSubscriptions($me);
        if (!$targets) jsonOut(['success' => false, 'error' => 'No hay suscripciones'], 404);
        $r = Push::sendToSubscription($targets[0], $payload);
        jsonOut(['success' => $r['ok'], 'status' => $r['status']]);
    } catch (Throwable $e) {
        jsonOut(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

if ($path === '/api/push/send' && $method === 'POST') {
    if (!$me) jsonOut(['success' => false, 'error' => 'Sesión no iniciada'], 401);
    $body = jsonBody();
    if (!is_string($body['title'] ?? null) || trim($body['title']) === '' || !is_string($body['body'] ?? null) || trim($body['body']) === '') {
        jsonOut(['success' => false, 'error' => 'Faltan title/body'], 400);
    }
    $result = Push::broadcast(['title' => mb_substr(trim($body['title']), 0, 80),
        'body' => mb_substr(trim($body['body']), 0, 200),
        'url' => is_string($body['url'] ?? null) ? $body['url'] : '/', 'ts' => time()]);
    jsonOut(array_merge(['success' => true], $result));
}

// --- API plan semanal ---

if ($path === '/api/calc' && $method === 'GET') {
    $g = $_GET;
    $p = [
        'goal' => (string)($g['goal'] ?? 'perder_peso'),
        'targetWeeks' => max(4, (int)($g['targetWeeks'] ?? 12)),
        'gender' => ($g['gender'] ?? 'mujer') === 'hombre' ? 'hombre' : 'mujer',
        'age' => (float)($g['age'] ?? 30),
        'height' => (float)($g['height'] ?? 168) ?: 168,
        'currentWeight' => (float)($g['currentWeight'] ?? 72) ?: 72,
        'targetWeight' => (float)($g['targetWeight'] ?? 65) ?: 65,
        'isChildOrTeen' => !empty($g['isChildOrTeen']),
        'activityLevel' => (string)($g['activityLevel'] ?? 'moderado'),
    ];
    jsonOut(['success' => true, 'calc' => Nutrition::calculateNeeds($p)]);
}

if ($path === '/api/profile' && $method === 'GET') {
    if (!$me) jsonOut(['success' => false, 'error' => 'Sesión no iniciada'], 401);
    $profile = getProfile($pdo, $me);
    jsonOut(['success' => true, 'profile' => $profile,
        'needs' => $profile ? Nutrition::calculateNeeds($profile) : null]);
}

if ($path === '/api/profile' && $method === 'POST') {
    if (!$me) jsonOut(['success' => false, 'error' => 'Sesión no iniciada'], 401);
    $body = jsonBody();
    if (!is_array($body['profile'] ?? null)) jsonOut(['success' => false, 'error' => 'Falta profile'], 400);
    Db::putKv($pdo, $me, 'nutriplan_profile', json_encode($body['profile'], JSON_UNESCAPED_UNICODE));
    // el plan guardado puede quedar obsoleto -> se regenera
    Db::putKv($pdo, $me, 'nutriplan_regen_offset', '0');
    Db::putKv($pdo, $me, 'nutriplan_weekly_plan', json_encode(
        Nutrition::generateWeeklyPlan($body['profile'], loadRecipes()), JSON_UNESCAPED_UNICODE));
    jsonOut(['success' => true]);
}

if ($path === '/api/profile' && $method === 'PUT') {
    if (!$me) jsonOut(['success' => false, 'error' => 'Sesión no iniciada'], 401);
    $body = jsonBody();
    if (!is_array($body['profile'] ?? null)) jsonOut(['success' => false, 'error' => 'Falta profile'], 400);
    Db::putKv($pdo, $me, 'nutriplan_profile', json_encode($body['profile'], JSON_UNESCAPED_UNICODE));
    jsonOut(['success' => true]);
}

if ($path === '/api/plan' && $method === 'GET') {
    if (!$me) jsonOut(['success' => false, 'error' => 'Sesión no iniciada'], 401);
    $profile = getProfile($pdo, $me);
    if (!$profile) jsonOut(['success' => false, 'error' => 'Sin perfil'], 404);
    $plan = array_map(fn($d) => array_merge($d, ['meals' => array_map('resolveMeal', $d['meals'])]), loadPlan($pdo, $me, $profile));
    jsonOut(['success' => true, 'plan' => $plan, 'needs' => Nutrition::calculateNeeds($profile)]);
}

if ($path === '/api/plan/regenerate' && $method === 'POST') {
    if (!$me) jsonOut(['success' => false, 'error' => 'Sesión no iniciada'], 401);
    $profile = getProfile($pdo, $me);
    if (!$profile) jsonOut(['success' => false, 'error' => 'Sin perfil'], 404);
    $off = (int)(Db::getAllKv($pdo, $me)['nutriplan_regen_offset'] ?? 0) + 1;
    Db::putKv($pdo, $me, 'nutriplan_regen_offset', (string)$off);
    $plan = Nutrition::generateWeeklyPlan($profile, loadRecipes(), $off);
    savePlan($pdo, $me, $plan);
    jsonOut(['success' => true, 'plan' => $plan]);
}

if ($path === '/api/plan/toggle' && $method === 'POST') {
    if (!$me) jsonOut(['success' => false, 'error' => 'Sesión no iniciada'], 401);
    $profile = getProfile($pdo, $me);
    if (!$profile) jsonOut(['success' => false, 'error' => 'Sin perfil'], 404);
    $body = jsonBody();
    $plan = loadPlan($pdo, $me, $profile);
    foreach ($plan as &$day) {
        if ((int)($day['dayNumber'] ?? 0) !== (int)($body['dayNumber'] ?? 0)) continue;
        foreach ($day['meals'] as &$m) {
            if (($m['slotId'] ?? $m['mealType']) === ($body['slotId'] ?? null)) $m['completed'] = empty($m['completed']);
        }
    }
    savePlan($pdo, $me, $plan);
    jsonOut(['success' => true]);
}

if ($path === '/api/plan/swap' && $method === 'POST') {
    if (!$me) jsonOut(['success' => false, 'error' => 'Sesión no iniciada'], 401);
    $profile = getProfile($pdo, $me);
    if (!$profile) jsonOut(['success' => false, 'error' => 'Sin perfil'], 404);
    $body = jsonBody();
    $plan = loadPlan($pdo, $me, $profile);
    $new = null;
    foreach ($plan as &$day) {
        if ((int)($day['dayNumber'] ?? 0) !== (int)($body['dayNumber'] ?? 0)) continue;
        foreach ($day['meals'] as &$m) {
            if (($m['slotId'] ?? $m['mealType']) !== ($body['slotId'] ?? null)) continue;
            $new = swapRecipe($profile, $m['mealType'], $m['recipeOverride']['id'] ?? ($m['recipeId'] ?? null), (int)$day['dayNumber'] - 1);
            if ($new) {
                $m['recipeId'] = $new['id'];
                unset($m['recipeOverride']);
                $m['completed'] = false;
            }
        }
    }
    savePlan($pdo, $me, $plan);
    if (!$new) jsonOut(['success' => false, 'error' => 'No hay alternativa segura'], 404);
    jsonOut(['success' => true, 'recipe' => $new]);
}

if ($path === '/api/plan/apply' && $method === 'POST') {
    if (!$me) jsonOut(['success' => false, 'error' => 'Sesión no iniciada'], 401);
    $profile = getProfile($pdo, $me);
    if (!$profile) jsonOut(['success' => false, 'error' => 'Sin perfil'], 404);
    $body = jsonBody();
    if (!is_array($body['recipe'] ?? null) || empty($body['recipe']['title'])) jsonOut(['success' => false, 'error' => 'Receta inválida'], 400);
    $plan = loadPlan($pdo, $me, $profile);
    foreach ($plan as &$day) {
        if ((int)($day['dayNumber'] ?? 0) !== (int)($body['dayNumber'] ?? 0)) continue;
        foreach ($day['meals'] as &$m) {
            if (($m['slotId'] ?? $m['mealType']) !== ($body['slotId'] ?? null)) continue;
            $m['recipeOverride'] = $body['recipe'] + ['id' => $body['recipe']['id'] ?? ('custom-' . time())];
            $m['recipeId'] = $m['recipeOverride']['id'];
            $m['completed'] = false;
        }
    }
    savePlan($pdo, $me, $plan);
    jsonOut(['success' => true]);
}

// --- API peso ---

if ($path === '/api/weight' && $method === 'GET') {
    if (!$me) jsonOut(['success' => false, 'error' => 'Sesión no iniciada'], 401);
    $kv = Db::getAllKv($pdo, $me);
    jsonOut(['success' => true, 'history' => array_values(json_decode($kv['nutriplan_weight_history'] ?? '[]', true) ?: [])]);
}

if ($path === '/api/weight' && $method === 'POST') {
    if (!$me) jsonOut(['success' => false, 'error' => 'Sesión no iniciada'], 401);
    $body = jsonBody();
    $w = (float)($body['weight'] ?? 0);
    $date = substr((string)($body['date'] ?? date('Y-m-d')), 0, 10);
    if ($w < 25 || $w > 350 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        jsonOut(['success' => false, 'error' => 'Peso o fecha inválidos'], 400);
    }
    $kv = Db::getAllKv($pdo, $me);
    $hist = json_decode($kv['nutriplan_weight_history'] ?? '[]', true) ?: [];
    $hist = array_values(array_filter($hist, fn($r) => ($r['date'] ?? '') !== $date));
    $hist[] = ['date' => $date, 'weight' => $w, 'note' => substr((string)($body['note'] ?? ''), 0, 200)];
    usort($hist, fn($a, $b) => strcmp($a['date'], $b['date']));
    Db::putKv($pdo, $me, 'nutriplan_weight_history', json_encode($hist, JSON_UNESCAPED_UNICODE));
    jsonOut(['success' => true, 'history' => $hist]);
}

if ($path === '/api/weight' && $method === 'DELETE') {
    if (!$me) jsonOut(['success' => false, 'error' => 'Sesión no iniciada'], 401);
    $body = jsonBody();
    $kv = Db::getAllKv($pdo, $me);
    $hist = json_decode($kv['nutriplan_weight_history'] ?? '[]', true) ?: [];
    if (count($hist) <= 1) jsonOut(['success' => false, 'error' => 'Debe quedar al menos un registro'], 400);
    $hist = array_values(array_filter($hist, fn($r) => ($r['date'] ?? '') !== ($body['date'] ?? '')));
    Db::putKv($pdo, $me, 'nutriplan_weight_history', json_encode($hist, JSON_UNESCAPED_UNICODE));
    jsonOut(['success' => true, 'history' => $hist]);
}

// --- API recetas/lista para cliente nativo (mismos helpers que las páginas Twig) ---

if ($path === '/api/recipes' && $method === 'GET') {
    if (!$me) jsonOut(['success' => false, 'error' => 'Sesión no iniciada'], 401);
    $fq = ['q' => trim((string)($_GET['q'] ?? '')), 'tiempo' => (string)($_GET['tiempo'] ?? ''),
        'kcal' => (string)($_GET['kcal'] ?? ''), 'momento' => (string)($_GET['momento'] ?? ''),
        'tag' => (string)($_GET['tag'] ?? '')];
    $all = filterRecipes($fq);
    $n = max(12, min(600, (int)($_GET['n'] ?? 60)));
    $tags = [];
    foreach (loadRecipes() as $r) foreach ($r['tags'] ?? [] as $t) $tags[$t] = ($tags[$t] ?? 0) + 1;
    arsort($tags);
    jsonOut(['success' => true, 'total' => count($all), 'recipes' => array_slice($all, 0, $n),
        'tags' => array_slice(array_keys($tags), 0, 12)]);
}

if ($path === '/api/recipe' && $method === 'GET') {
    if (!$me) jsonOut(['success' => false, 'error' => 'Sesión no iniciada'], 401);
    $r = loadRecipeFull((string)($_GET['id'] ?? ''));
    if (!$r) jsonOut(['success' => false, 'error' => 'Receta no encontrada'], 404);
    jsonOut(['success' => true, 'recipe' => $r]);
}

if ($path === '/api/hoy' && $method === 'GET') {
    if (!$me) jsonOut(['success' => false, 'error' => 'Sesión no iniciada'], 401);
    $profile = getProfile($pdo, $me);
    if (!$profile) jsonOut(['success' => false, 'error' => 'Sin perfil'], 404);
    $needs = Nutrition::calculateNeeds($profile);
    $mealType = (string)($_GET['meal'] ?? 'comida');
    $maxTime = isset($_GET['tiempo']) && $_GET['tiempo'] !== '' ? (int)$_GET['tiempo'] : null;
    $ingredient = trim((string)($_GET['ing'] ?? ''));
    $style = trim((string)($_GET['estilo'] ?? ''));
    $craving = trim((string)($_GET['antojo'] ?? ''));
    $recs = recommendFor($profile, $needs, $mealType, $ingredient ?: $craving, $style ?: null, $maxTime);
    jsonOut(['success' => true, 'recs' => $recs, 'mealType' => $mealType,
        'target' => mealCalorieTarget($mealType, (int)($needs['targetCalories'] ?? 0))]);
}

if ($path === '/api/shopping' && $method === 'GET') {
    if (!$me) jsonOut(['success' => false, 'error' => 'Sesión no iniciada'], 401);
    $profile = getProfile($pdo, $me);
    if (!$profile) jsonOut(['success' => false, 'error' => 'Sin perfil'], 404);
    $plan = loadPlan($pdo, $me, $profile);
    $di = isset($_GET['dia']) ? max(0, min(6, (int)$_GET['dia'])) : (int)date('N') - 1;
    $meals = array_map('resolveMeal', $plan[$di]['meals'] ?? []);
    foreach ($meals as &$meal) {
        $full = loadRecipeFull((string)($meal['recipe']['id'] ?? ''));
        if ($full) $meal['recipe'] = $full;
    }
    unset($meal);
    jsonOut(['success' => true, 'day' => $plan[$di] ?? null, 'shop' => shoppingItems($meals)]);
}



if ($path === '/' || $path === '/index.php') {
    needLogin($twig, $config, $me);
    $user = Db::getUser($pdo, $me);
    $profile = getProfile($pdo, $me);
    if (!$profile) { header('Location: /ajustes'); exit; }
    $needs = Nutrition::calculateNeeds($profile);
    $base = ['config' => $config, 'user' => $user, 'profile' => $profile, 'needs' => $needs,
        'greeting' => Nutrition::greeting($user['name'] ?? null),
        'chrome' => true, 'goal_label' => goalLabel($profile), 'mistral' => mistralView(Mistral::getSettings())];
    $tab = $_GET['tab'] ?? 'plan';

    if ($tab === 'hoy') {
        $mealType = $_GET['meal'] ?? 'comida';
        $maxTime = isset($_GET['tiempo']) && $_GET['tiempo'] !== '' ? (int)$_GET['tiempo'] : null;
        $ingredient = $_GET['ing'] ?? null;
        $style = $_GET['estilo'] ?? null;
        $craving = $_GET['antojo'] ?? null;
        $recs = recommendFor($profile, $needs, $mealType, $ingredient ?: $craving, $style, $maxTime);
        echo $twig->render('hoy.twig', $base + [
            'tab' => 'hoy', 'mealType' => $mealType, 'maxTime' => $maxTime,
            'ingredient' => $ingredient, 'style' => $style, 'craving' => $craving,
            'recs' => $recs, 'target' => mealCalorieTarget($mealType, (int)($needs['targetCalories'] ?? 0)),
        ]);
        exit;
    }

    if ($tab === 'recetas') {
        $fq = ['q' => $_GET['q'] ?? '', 'tiempo' => $_GET['tiempo'] ?? '', 'kcal' => $_GET['kcal'] ?? '',
            'momento' => $_GET['momento'] ?? '', 'tag' => $_GET['tag'] ?? ''];
        $all = filterRecipes($fq);
        $n = max(12, min(600, (int)($_GET['n'] ?? 60)));
        $tags = [];
        foreach (loadRecipes() as $r) foreach ($r['tags'] ?? [] as $t) $tags[$t] = ($tags[$t] ?? 0) + 1;
        arsort($tags);
        echo $twig->render('recetas.twig', $base + [
            'tab' => 'recetas', 'fq' => $fq, 'total' => count($all),
            'recipes' => array_slice($all, 0, $n), 'n' => $n,
            'tags' => array_slice(array_keys($tags), 0, 12),
        ]);
        exit;
    }

    if ($tab === 'compra') {
        $plan = loadPlan($pdo, $me, $profile);
        $di = isset($_GET['dia']) ? max(0, min(6, (int)$_GET['dia'])) : (int)date('N') - 1;
        $meals = array_map('resolveMeal', $plan[$di]['meals'] ?? []);
        // El índice es ligero: hidratar ingredientes solo aquí.
        foreach ($meals as &$meal) {
            $full = loadRecipeFull((string)($meal['recipe']['id'] ?? ''));
            if ($full) $meal['recipe'] = $full;
        }
        unset($meal);
        echo $twig->render('compra.twig', $base + [
            'tab' => 'compra', 'plan' => $plan, 'di' => $di,
            'shop' => shoppingItems($meals),
        ]);
        exit;
    }

    // Plan semanal (por defecto)
    $plan = loadPlan($pdo, $me, $profile);
    $di = isset($_GET['dia']) ? max(0, min(6, (int)$_GET['dia'])) : (int)date('N') - 1;
    $vista = ($_GET['vista'] ?? 'dia') === 'matriz' ? 'matriz' : 'dia';
    $meals = array_map('resolveMeal', $plan[$di]['meals'] ?? []);
    $matrix = [];
    if ($vista === 'matriz') {
        foreach ($plan as $d) $matrix[] = ['day' => $d, 'meals' => array_map('resolveMeal', $d['meals'] ?? [])];
    }
    $dayAl = [];
    foreach ($meals as $m) foreach (($m['recipe']['allergens'] ?? []) as $a) $dayAl[$a] = true;
    echo $twig->render('plan.twig', $base + [
        'tab' => 'plan', 'plan' => $plan, 'di' => $di, 'vista' => $vista,
        'meals' => $meals, 'totals' => dayTotals($meals), 'matrix' => $matrix,
        'allergens' => array_keys($dayAl),
    ]);
    exit;
}

if ($path === '/perfil') {
    needLogin($twig, $config, $me);
    $user = Db::getUser($pdo, $me);
    $profile = getProfile($pdo, $me);
    if (!$profile) { header('Location: /ajustes'); exit; }
    $kv = Db::getAllKv($pdo, $me);
    $hist = array_values(json_decode($kv['nutriplan_weight_history'] ?? '[]', true) ?: []);
    $ms = Mistral::getSettings();
    echo $twig->render('perfil.twig', [
        'config' => $config, 'user' => $user, 'profile' => $profile,
        'needs' => Nutrition::calculateNeeds($profile),
        'greeting' => Nutrition::greeting($user['name'] ?? null),
        'chrome' => true, 'goal_label' => goalLabel($profile), 'mistral' => mistralView($ms),
        'tab' => 'perfil', 'hist' => $hist,
        'wstats' => $hist ? weightStats($hist, $profile) : null,
        'vapid' => Push::getVapidPublicKey(),
        'app_version' => Updater::current(),
    ]);
    exit;
}

if ($path === '/ajustes') {
    needLogin($twig, $config, $me);
    if ($method === 'POST') {
        // Guardado sin JS (los grupos de botones llegan en hidden inputs)
        $al = array_values(array_filter(array_map('trim', explode(',', (string)($_POST['allergies'] ?? '')))));
        $profile = [
            'goal' => (string)($_POST['goal'] ?? 'perder_peso'),
            'name' => trim((string)($_POST['name'] ?? '')),
            'targetWeeks' => max(4, (int)($_POST['targetWeeks'] ?? 12)),
            'gender' => ($_POST['gender'] ?? 'mujer') === 'hombre' ? 'hombre' : 'mujer',
            'age' => (float)($_POST['age'] ?? 30),
            'height' => (float)($_POST['height'] ?? 168),
            'currentWeight' => (float)($_POST['currentWeight'] ?? 0),
            'targetWeight' => (float)($_POST['targetWeight'] ?? 0),
            'isChildOrTeen' => !empty($_POST['isChildOrTeen']) || (float)($_POST['age'] ?? 30) < 18,
            'activityLevel' => (string)($_POST['activityLevel'] ?? 'moderado'),
            'mealsPerDay' => in_array((int)($_POST['mealsPerDay'] ?? 5), [4, 5, 6], true) ? (int)$_POST['mealsPerDay'] : 5,
            'dietaryPreference' => (string)($_POST['dietaryPreference'] ?? 'mediterranea'),
            'allergies' => $al,
            'dailyWaterTargetLiters' => min(4, max(1.5, (float)($_POST['dailyWaterTargetLiters'] ?? 2.5))),
        ];
        Db::putKv($pdo, $me, 'nutriplan_profile', json_encode($profile, JSON_UNESCAPED_UNICODE));
        Db::putKv($pdo, $me, 'nutriplan_regen_offset', '0');
        Db::putKv($pdo, $me, 'nutriplan_weekly_plan', json_encode(Nutrition::generateWeeklyPlan($profile, loadRecipes()), JSON_UNESCAPED_UNICODE));
        header('Location: /?tab=plan');
        exit;
    }
    $user = Db::getUser($pdo, $me);
    $saved = getProfile($pdo, $me);
    $profile = array_merge([
        'goal' => 'perder_peso', 'name' => '', 'targetWeeks' => 12,
        'gender' => 'mujer', 'age' => 30, 'height' => 168, 'currentWeight' => 72, 'targetWeight' => '',
        'isChildOrTeen' => false, 'activityLevel' => 'moderado', 'mealsPerDay' => 5,
        'dietaryPreference' => 'mediterranea', 'allergies' => [], 'dailyWaterTargetLiters' => 2.5,
    ], $saved ?? []);
    $calcP = $profile;
    foreach (['currentWeight' => 72, 'targetWeight' => 65, 'height' => 168, 'age' => 30] as $k => $v) {
        if (!is_numeric($calcP[$k]) || $calcP[$k] <= 0) $calcP[$k] = $v;
    }
    echo $twig->render('config.twig', [
        'config' => $config, 'user' => $user, 'profile' => $profile,
        'needs' => Nutrition::calculateNeeds($calcP),
        'chrome' => true, 'goal_label' => goalLabel($profile), 'mistral' => mistralView(Mistral::getSettings()),
        'tab' => 'config', 'isNew' => !$saved,
    ]);
    exit;
}

if ($path === '/receta') {
    needLogin($twig, $config, $me);
    $user = Db::getUser($pdo, $me);
    $profile = getProfile($pdo, $me);
    if (!$profile) { header('Location: /ajustes'); exit; }
    $r = loadRecipeFull((string)($_GET['id'] ?? ''));
    if (!$r) { http_response_code(404); echo 'Receta no encontrada'; exit; }
    $serv = (int)($_GET['r'] ?? 1);
    if (!in_array($serv, [1, 2, 4], true)) $serv = 1;
    echo $twig->render('receta.twig', [
        'config' => $config, 'user' => $user, 'profile' => $profile,
        'chrome' => true, 'goal_label' => goalLabel($profile), 'mistral' => mistralView(Mistral::getSettings()),
        'tab' => 'recetas', 'r' => $r, 'serv' => $serv,
        'dia' => $_GET['dia'] ?? null, 'slot' => $_GET['slot'] ?? null,
        'back' => $_GET['back'] ?? '/?tab=recetas',
    ]);
    exit;
}

if ($path === '/ia') {
    needLogin($twig, $config, $me);
    $user = Db::getUser($pdo, $me);
    $profile = getProfile($pdo, $me);
    if (!$profile) { header('Location: /ajustes'); exit; }
    echo $twig->render('ia.twig', [
        'config' => $config, 'user' => $user, 'profile' => $profile,
        'needs' => Nutrition::calculateNeeds($profile), 'tab' => 'plan',
        'chrome' => true, 'goal_label' => goalLabel($profile),
        'dia' => isset($_GET['dia']) ? (int)$_GET['dia'] : null,
        'slot' => $_GET['slot'] ?? null,
        'mistral' => mistralView(Mistral::getSettings()),
    ]);
    exit;
}

http_response_code(404);
echo 'No encontrado';
