<?php
// Comprobaciones del núcleo (sin BD): cálculo nutricional, plan y helpers puros.
// Uso: php tests/run.php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/helpers.php';

$fallos = 0;
$total = 0;
function ok(string $name, bool $cond): void {
    global $fallos, $total;
    $total++;
    if (!$cond) { $fallos++; echo "FALLO  $name\n"; }
}
function eq(string $name, $esperado, $real): void {
    global $fallos, $total;
    $total++;
    if ($esperado != $real) {
        $fallos++;
        echo "FALLO  $name (esperado " . json_encode($esperado) . ", real " . json_encode($real) . ")\n";
    }
}

// --- 1. Cálculo nutricional (mujer 72 kg / 168 cm / 30 a, perder peso) ---
$profile = [
    'name' => 'Test', 'goal' => 'perder_peso', 'gender' => 'mujer', 'age' => 30,
    'height' => 168, 'currentWeight' => 72, 'targetWeight' => 64, 'targetWeeks' => 12,
    'activityLevel' => 'moderado', 'mealsPerDay' => 5, 'dietaryPreference' => 'mediterranea',
    'allergies' => [], 'isChildOrTeen' => false,
];
$needs = NutriPlan\Nutrition::calculateNeeds($profile);
ok('bmr positivo', $needs['bmr'] > 1000);
ok('deficit en perder_peso', $needs['targetCalories'] < $needs['tdee']);
ok('deficit acotado', $needs['deficitOrSurplus'] <= -350 && $needs['deficitOrSurplus'] >= -600);
eq('imc 72/1.68^2', 25.5, round((float)$needs['bmi'], 1));
$pct = $needs['macros']['proteinPct'] + $needs['macros']['carbsPct'] + $needs['macros']['fatsPct'];
ok('macros suman ~100%', $pct >= 98 && $pct <= 102);
ok('proteína en rango clínico', $needs['macros']['proteinGrams'] >= 1.6 * 72 * 0.9);

// --- 2. Reparto calórico por ingesta ---
eq('comida 35%', 700, mealCalorieTarget('comida', 2000));
eq('cena 28%', 560, mealCalorieTarget('cena', 2000));
eq('desayuno 22%', 440, mealCalorieTarget('desayuno', 2000));
eq('merienda 12%', 240, mealCalorieTarget('merienda', 2000));

// --- 3. Lista de la compra consolida por nombre+unidad ---
$meals = [
    ['recipe' => ['id' => 'a', 'ingredients' => [
        ['name' => 'Tomate', 'unit' => 'g', 'quantity' => 100, 'category' => 'verduras'],
        ['name' => 'Tomate', 'unit' => 'g', 'quantity' => 50, 'category' => 'verduras'],
    ]]],
    ['recipe' => ['id' => 'b', 'ingredients' => [
        ['name' => 'Tomate', 'unit' => 'ud', 'quantity' => 2, 'category' => 'verduras'],
        ['name' => 'Tomate', 'unit' => 'g', 'quantity' => 25, 'category' => 'verduras'],
    ]]],
];
$shop = shoppingItems($meals);
eq('dos claves (g y ud)', 2, $shop['total']);
eq('suma 150 g', 175.0, round((float)$shop['groups']['verduras'][0]['quantity'], 1));
eq('unidad ud separada', 2.0, round((float)$shop['groups']['verduras'][1]['quantity'], 1));

// --- 4. Estadísticas de peso ---
$hist = [
    ['date' => '2026-11-01', 'weight' => 72.0],
    ['date' => '2026-11-08', 'weight' => 71.2],
    ['date' => '2026-11-15', 'weight' => 70.6],
];
$ws = weightStats($hist, $profile);
eq('peso inicial', 72.0, (float)$ws['initial']);
eq('peso actual', 70.6, (float)$ws['current']);
eq('objetivo', 64.0, (float)$ws['target']);
eq('restante', 6.6, round((float)$ws['toGo'], 1));
ok('serie con puntos', count($ws['svg']['pts']) === 3);
ok('svg con ancho', (int)$ws['svg']['w'] > 0);

// --- 5. Catálogo: búsqueda por ingrediente y filtro de tiempo ---
$pollos = filterRecipes(['q' => 'pollo']);
ok('busca por ingrediente (pollo)', count($pollos) > 0);
$todosConPollo = true;
foreach ($pollos as $r) {
    $hay = mb_strtolower($r['title'] . ' ' . ($r['subtitle'] ?? '') . ' ' . implode(' ', $r['tags'] ?? []) . ' ' . recipeIngredientText($r));
    if (mb_strpos($hay, 'pollo') === false) { $todosConPollo = false; break; }
}
ok('todos los resultados contienen pollo', $todosConPollo);
$express = filterRecipes(['tiempo' => 'express']);
$expressOk = true;
foreach ($express as $r) { if ((int)$r['prepTimeMinutes'] > 15) { $expressOk = false; break; } }
ok('filtro express <= 15 min', count($express) > 0 && $expressOk);

// --- 6. Exclusión por alergia y dieta vegetariana ---
$alergico = array_merge($profile, ['allergies' => ['Gluten']]);
$conGluten = null;
foreach (loadRecipes() as $r) {
    if (in_array('Gluten', $r['allergens'] ?? [], true)) { $conGluten = $r; break; }
}
ok('existe receta con gluten en el catálogo', $conGluten !== null);
ok('alergia excluye la receta', $conGluten !== null && recipeExcluded($conGluten, $alergico));

$vegetariano = array_merge($profile, ['dietaryPreference' => 'vegetariana']);
$conCarne = null;
foreach (loadRecipes() as $r) {
    if (in_array('carnes_pescados', recipeCategories($r), true)) { $conCarne = $r; break; }
}
ok('existe receta con carne/pescado', $conCarne !== null);
ok('dieta vegetariana excluye carne/pescado', $conCarne !== null && recipeExcluded($conCarne, $vegetariano));

// --- 7. Plan semanal determinista y completo ---
$plan = NutriPlan\Nutrition::generateWeeklyPlan($profile, loadRecipes());
eq('7 días', 7, count($plan));
ok('cada día tiene ingestas', count($plan[0]['meals']) >= 3);
$ids = array_map(fn($d) => $d['dayName'], $plan);
eq('nombres de día', 'Lunes', $ids[0]);
$plan2 = NutriPlan\Nutrition::generateWeeklyPlan($profile, loadRecipes(), 3);
ok('offset cambia la rotación', $plan[0]['meals'][0]['recipeId'] !== $plan2[0]['meals'][0]['recipeId']);

echo $fallos === 0 ? "OK $total comprobaciones\n" : "\n$fallos de $total comprobaciones fallaron\n";
exit($fallos === 0 ? 0 : 1);
