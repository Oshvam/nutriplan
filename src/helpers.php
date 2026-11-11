<?php
// Helpers de dominio y de vista (extraidos de public/index.php).

use NutriPlan\Db;
use NutriPlan\Nutrition;
use NutriPlan\Mistral;
use NutriPlan\Push;

// --- Helpers plan/recetas ---

/** Catálogo ligero (sin ingredientes ni pasos): suficiente para plan, catálogo y recomendaciones. */
function loadRecipes(): array {
    static $cache = null;
    if ($cache === null) {
        $json = @file_get_contents(__DIR__ . '/../data/recipes-index.json');
        if (!$json) $json = @file_get_contents(__DIR__ . '/../data/recipes.json');
        $cache = json_decode($json ?: '[]', true) ?: [];
    }
    return $cache;
}

/** Receta completa (con ingredientes y pasos) solo para la ficha /receta y la lista de la compra. */
function loadRecipeFull(string $id): ?array {
    static $byId = null;
    if ($byId === null) {
        $byId = [];
        $json = @file_get_contents(__DIR__ . '/../data/recipes.json');
        foreach ((json_decode($json ?: '[]', true) ?: []) as $r) {
            if (isset($r['id'])) $byId[$r['id']] = $r;
        }
    }
    return $byId[$id] ?? null;
}

/** Texto con los nombres de ingredientes (minúsculas) sin cargar la receta completa. */
function recipeIngredientText(array $r): string {
    if (isset($r['ing'])) return (string)$r['ing'];
    return mb_strtolower(implode(' ', array_map(fn($g) => (string)($g['name'] ?? ''), $r['ingredients'] ?? [])));
}

/** Categorías de ingredientes presentes (p. ej. para el filtro vegetariano). */
function recipeCategories(array $r): array {
    if (isset($r['cats'])) return (array)$r['cats'];
    $out = [];
    foreach (($r['ingredients'] ?? []) as $g) {
        if (!empty($g['category'])) $out[] = $g['category'];
    }
    return $out;
}

function recipeById(string $id): ?array {
    static $byId = null;
    if ($byId === null) {
        $byId = [];
        foreach (loadRecipes() as $r) $byId[$r['id']] = $r;
    }
    return $byId[$id] ?? null;
}

function getProfile(PDO $pdo, string $sub): ?array {
    $kv = Db::getAllKv($pdo, $sub);
    $p = json_decode($kv['nutriplan_profile'] ?? 'null', true);
    return is_array($p) ? $p : null;
}

/** Plan guardado (con completed/slotId/override) o generado al vuelo. */
function loadPlan(PDO $pdo, string $sub, array $profile): array {
    $kv = Db::getAllKv($pdo, $sub);
    $saved = json_decode($kv['nutriplan_weekly_plan'] ?? 'null', true);
    $plan = (is_array($saved) && $saved) ? $saved : Nutrition::generateWeeklyPlan($profile, loadRecipes());
    foreach ($plan as &$day) {
        foreach ($day['meals'] as &$m) {
            $m['slotId'] = $m['slotId'] ?? $m['mealType'];
            $m['completed'] = !empty($m['completed']);
        }
    }
    return $plan;
}

function savePlan(PDO $pdo, string $sub, array $plan): void {
    Db::putKv($pdo, $sub, 'nutriplan_weekly_plan', json_encode($plan, JSON_UNESCAPED_UNICODE));
}

/** Sustituye recipeId por objeto receta (override si se guardó uno). */
function resolveMeal(array $m): array {
    $m['recipe'] = $m['recipeOverride'] ?? recipeById($m['recipeId'] ?? '');
    return $m;
}

function dayTotals(array $meals): array {
    $t = ['cal' => 0, 'p' => 0, 'c' => 0, 'f' => 0];
    foreach ($meals as $m) {
        $r = $m['recipe'] ?? null;
        if (!$r) continue;
        $t['cal'] += $r['calories'] ?? 0;
        $t['p'] += $r['protein'] ?? 0;
        $t['c'] += $r['carbs'] ?? 0;
        $t['f'] += $r['fat'] ?? 0;
    }
    return $t;
}

/** Siguiente receta del mismo momento (rotación), excluyendo la actual. */
function swapRecipe(array $profile, string $mealType, ?string $currentId, int $dayIndex): ?array {
    $cands = array_values(array_filter(loadRecipes(), function ($r) use ($profile, $mealType, $currentId) {
        if (!in_array($mealType, $r['mealTypes'] ?? [], true)) return false;
        if ($r['id'] === $currentId) return false;
        if (!empty($profile['allergies']) && array_intersect($r['allergens'] ?? [], $profile['allergies'])) return false;
        if (($profile['dietaryPreference'] ?? '') === 'vegetariano' && in_array('carne', $r['tags'] ?? [], true)) return false;
        return true;
    }));
    if (!$cands) return null;
    $pos = 0;
    foreach ($cands as $i => $r) {
        // si la actual es personalizada/IA, rotar por día para variar
        if (recipeById((string)$currentId) && $r['id'] > (string)$currentId) { $pos = $i; break; }
        $pos = $i;
    }
    if (!recipeById((string)$currentId)) $pos = $dayIndex % count($cands);
    return $cands[$pos % count($cands)];
}

function mealCalorieTarget(string $mealType, int $daily): int {
    if ($mealType === 'comida') return (int)round($daily * 0.35);
    if ($mealType === 'cena') return (int)round($daily * 0.28);
    if ($mealType === 'desayuno') return (int)round($daily * 0.22);
    if ($mealType === 'merienda') return (int)round($daily * 0.12);
    return (int)round($daily * 0.08);
}

function aiFallbackRecipes(array $params): array {
    $mealType = $params['mealType'] ?? 'comida';
    $cal = (int)($params['targetCaloriesForMeal'] ?? 420);
    $craving = $params['cravingText'] ?? '';
    $goal = $params['userGoal'] ?? 'perder_peso';
    $mk = function (string $id, string $title, string $subtitle, string $emoji, array $ings, array $steps, array $extra = []) use ($cal, $mealType, $goal) {
        return array_merge([
            'id' => $id . '-' . time(), 'title' => $title, 'subtitle' => $subtitle,
            'mealTypes' => [$mealType], 'prepTimeMinutes' => 15, 'prepCategory' => 'rapido',
            'calorieCategory' => $cal < 350 ? 'baja' : ($cal <= 600 ? 'moderada' : 'alta'),
            'calories' => $cal,
            'protein' => (int)round($cal * 0.3 / 4), 'carbs' => (int)round($cal * 0.45 / 4),
            'fat' => (int)round($cal * 0.25 / 9), 'fiber' => 6, 'imageEmoji' => $emoji,
            'tags' => ['Recomendado por IA', 'mediterraneo', 'ajuste_calorico'],
            'allergens' => [], 'suitableForGoals' => [$goal],
            'ingredients' => $ings, 'instructions' => $steps,
        ], $extra);
    };
    return [
        $mk('ai-fallback-1', $craving ? "Salteado especial inspirado en \"$craving\"" : 'Bowl mediterráneo templado de pollo y aguacate',
            "Plato personalizado ajustado exactamente a $cal kcal", '🥑',
            [
                ['name' => 'Pechuga de pollo o pavo en dados', 'quantity' => (int)round($cal * 0.35), 'unit' => 'g', 'category' => 'carnes_pescados'],
                ['name' => 'Calabacín y pimiento rojo en dados', 'quantity' => 150, 'unit' => 'g', 'category' => 'verduras'],
                ['name' => 'Arroz integral o quinoa cocida', 'quantity' => (int)round($cal * 0.25), 'unit' => 'g', 'category' => 'cereales'],
                ['name' => 'Aceite de oliva virgen extra', 'quantity' => 8, 'unit' => 'g', 'category' => 'aceites_grasas'],
                ['name' => 'Aguacate en láminas', 'quantity' => 40, 'unit' => 'g', 'category' => 'frutas'],
            ],
            [
                'Dorar el pollo cortado en dados en una sartén con la mitad del aceite de oliva virgen extra.',
                'Añadir las verduras troceadas y saltear a fuego medio-alto durante 6 minutos.',
                'Incorporar el cereal integral ya cocido y mezclar con los jugos de la sartén.',
                'Servir coronando con las láminas de aguacate fresco y un toque de pimienta.',
            ],
            ['clinicalNotes' => "Formulado para aportar exactamente $cal kcal sin picos de insulina, con proteínas de alto valor biológico y fibra vegetal saciante."]),
        $mk('ai-fallback-2', 'Wok ligero de salmón fresco con verduras y semillas',
            'Ácidos grasos esenciales Omega 3 y alta digestibilidad', '🐲',
            [
                ['name' => 'Lomo de salmón fresco limpio', 'quantity' => 150, 'unit' => 'g', 'category' => 'carnes_pescados'],
                ['name' => 'Brócoli y zanahoria al vapor', 'quantity' => 140, 'unit' => 'g', 'category' => 'verduras'],
                ['name' => 'Patata o boniato asado en dados', 'quantity' => 120, 'unit' => 'g', 'category' => 'legumbres_frutos_secos'],
                ['name' => 'Aceite de oliva virgen extra', 'quantity' => 5, 'unit' => 'g', 'category' => 'aceites_grasas'],
            ],
            [
                'Asar el salmón en una plancha caliente durante 3 minutos por lado.',
                'Saltear brevemente el brócoli y la zanahoria con unas gotas de aceite.',
                'Servir el lomo de salmón acompañado de la patata asada y las verduras al dente.',
            ],
            ['tags' => ['Recomendado por IA', 'omega_3', 'cena_ligera'], 'allergens' => ['Pescado'],
             'clinicalNotes' => 'Pescado azul con verduras ricas en magnesio que favorecen la recuperación muscular y el descanso.']),
    ];
}

function aiSystemPrompt(array $p, ?int $targetKcal): string {
    $tc = $targetKcal ?? 450;
    $al = !empty($p['allergies']) ? implode(', ', $p['allergies']) : 'Ninguna';
    $cat = $tc < 350 ? 'baja' : ($tc <= 600 ? 'moderada' : 'alta');
    $prep = ($p['maxMinutes'] ?? 30) <= 15 ? 'express' : (($p['maxMinutes'] ?? 30) <= 30 ? 'rapido' : 'elaborado');
    return 'Eres un dietista-nutricionista clínico colegiado en España, experto en dietoterapia basada en la evidencia (criterios SEEDO, guías SENC y tratados de Krause). '
        . "Debes calcular los gramos exactos de cada ingrediente en crudo para 1 persona de modo que el plato aporte aproximadamente $tc kcal y respete todas las restricciones.\n\n"
        . 'Características: objetivo ' . ($p['userGoal'] ?? 'saludable') . '; edad ' . ($p['age'] ?? 30) . ' años; alergias a EXCLUir: ' . $al
        . '; preferencia: ' . ($p['dietaryPreference'] ?? 'mediterranea') . '; momento: ' . ($p['mealType'] ?? 'comida')
        . '; tiempo máximo: ' . ($p['maxMinutes'] ?? 30) . ' minutos; antojo: "' . ($p['cravingText'] ?? 'Sorpréndeme') . "\".\n\n"
        . 'Devuelve un JSON estrictamente válido con 1 o 2 recetas con este formato exacto: [{id, title, subtitle, mealTypes, prepTimeMinutes, '
        . "prepCategory: \"$prep\", calorieCategory: \"$cat\", calories: $tc, protein, carbs, fat, fiber, imageEmoji, tags, allergens, suitableForGoals, "
        . 'clinicalNotes, ingredients: [{name, quantity, unit, category, note?}], instructions: [pasos]}]. Sin texto extra ni markdown.';
}

function recipeExcluded(array $r, array $profile): bool {
    $al = array_filter(array_map(fn($a) => mb_strtolower(trim((string)$a)), $profile['allergies'] ?? []));
    if ($al) {
        foreach ($r['allergens'] ?? [] as $a) {
            if (in_array(mb_strtolower((string)$a), $al, true)) return true;
        }
    }
    if (($profile['dietaryPreference'] ?? '') === 'vegetariana') {
        if (in_array('carnes_pescados', recipeCategories($r), true)) return true;
    }
    return false;
}

function filterRecipes(array $q): array {
    $out = [];
    foreach (loadRecipes() as $r) {
        if (!empty($q['momento']) && !in_array($q['momento'], $r['mealTypes'] ?? [], true)) continue;
        $t = (int)($r['prepTimeMinutes'] ?? 30);
        $tf = $q['tiempo'] ?? '';
        if ($tf === 'express' && $t > 15) continue;
        if ($tf === 'rapido' && ($t <= 15 || $t > 30)) continue;
        if ($tf === 'elaborado' && $t <= 30) continue;
        $c = (float)($r['calories'] ?? 0);
        $kf = $q['kcal'] ?? '';
        if ($kf === 'baja' && $c >= 350) continue;
        if ($kf === 'moderada' && ($c < 350 || $c > 600)) continue;
        if ($kf === 'alta' && $c <= 600) continue;
        if (!empty($q['tag']) && !in_array($q['tag'], $r['tags'] ?? [], true)) continue;
        if (!empty($q['q'])) {
            $names = recipeIngredientText($r);
            $hay = mb_strtolower($r['title'] . ' ' . ($r['subtitle'] ?? '') . ' ' . implode(' ', $r['tags'] ?? []) . ' ' . $names);
            if (mb_strpos($hay, mb_strtolower($q['q'])) === false) continue;
        }
        $out[] = $r;
    }
    return $out;
}

/** Top 3 del catálogo por puntuación (port de WhatToEatToday). */
function recommendFor(array $profile, array $needs, string $mealType, ?string $ingredient, ?string $style, ?int $maxTime, int $limit = 3): array {
    $scored = [];
    foreach (loadRecipes() as $r) {
        if (!in_array($mealType, $r['mealTypes'] ?? [], true)) continue;
        if (recipeExcluded($r, $profile)) continue;
        $s = 40;
        $t = (int)($r['prepTimeMinutes'] ?? 30);
        $s += ($maxTime !== null && $t > $maxTime) ? -1.5 * ($t - $maxTime) : 25;
        if ($ingredient) {
            $found = mb_stripos(recipeIngredientText($r), $ingredient) !== false;
            if ($found) $s += 30;
            else {
                if (mb_stripos($r['title'] ?? '', $ingredient) !== false) $s += 25;
                elseif (mb_stripos(implode(' ', $r['tags'] ?? []), $ingredient) !== false) $s += 20;
            }
        }
        if ($style && mb_stripos(($r['title'] ?? '') . ' ' . implode(' ', $r['tags'] ?? []), $style) !== false) $s += 35;
        if (in_array($profile['goal'] ?? '', $r['suitableForGoals'] ?? [], true)) $s += 15;
        $scored[] = ['recipe' => $r, 'score' => (int)round($s)];
    }
    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($scored, 0, $limit);
}

/** KPIs + datos del gráfico SVG de peso. */
function weightStats(array $hist, array $profile): array {
    $n = count($hist);
    $first = $hist[0];
    $last = $hist[$n - 1];
    $h = ((float)($profile['height'] ?? 170)) / 100;
    $bmi = $h > 0 ? round($last['weight'] / ($h * $h), 1) : 0;
    $target = (float)($profile['targetWeight'] ?? $last['weight']);
    $change = round($last['weight'] - $first['weight'], 1);
    $den = (float)$first['weight'] - $target;
    $pct = $den != 0 ? max(0, min(100, round($change / $den * 100))) : 0;
    $W = 640; $H = 220; $pl = 34; $pr = 12; $pt = 14; $pb = 26;
    $ws = array_column($hist, 'weight');
    $mn = min(min($ws), $target) - 1;
    $mx = max(max($ws), $target) + 1;
    if ($mx - $mn < 2) { $mx += 1; $mn -= 1; }
    $pts = [];
    foreach ($hist as $i => $rec) {
        $x = $pl + ($W - $pl - $pr) * ($n > 1 ? $i / ($n - 1) : 0.5);
        $y = $pt + ($H - $pt - $pb) * (1 - ($rec['weight'] - $mn) / ($mx - $mn));
        $pts[] = ['x' => round($x, 1), 'y' => round($y, 1), 'date' => $rec['date'], 'weight' => $rec['weight']];
    }
    $line = implode(' ', array_map(fn($p) => $p['x'] . ',' . $p['y'], $pts));
    $base = $H - $pb;
    $area = $pl . ',' . $base . ' ' . $line . ' ' . ($W - $pr) . ',' . $base;
    $yt = $pt + ($H - $pt - $pb) * (1 - ($target - $mn) / ($mx - $mn));
    return [
        'initial' => $first['weight'], 'current' => $last['weight'], 'bmi' => $bmi,
        'target' => $target, 'toGo' => round($last['weight'] - $target, 1),
        'change' => $change, 'pct' => $pct, 'count' => $n,
        'svg' => ['w' => $W, 'h' => $H, 'line' => $line, 'area' => $area, 'targetY' => round($yt, 1), 'pts' => $pts, 'min' => $mn, 'max' => $mx],
    ];
}

function shoppingItems(array $meals): array {
    $names = ['lacteos' => 'Lácteos', 'cereales' => 'Cereales y tubérculos', 'carnes_pescados' => 'Carnes y pescados', 'verduras' => 'Verduras y hortalizas', 'frutas' => 'Frutas', 'legumbres_frutos_secos' => 'Legumbres y frutos secos', 'aceites_grasas' => 'Aceites y grasas', 'otros' => 'Otros'];
    $order = array_keys($names);
    $map = [];
    foreach ($meals as $m) {
        $r = $m['recipe'] ?? null;
        if (!$r) continue;
        foreach ($r['ingredients'] ?? [] as $g) {
            $key = mb_strtolower(trim((string)($g['name'] ?? ''))) . '|' . ($g['unit'] ?? '');
            if (!isset($map[$key])) $map[$key] = ['name' => $g['name'] ?? '', 'unit' => $g['unit'] ?? '', 'quantity' => 0, 'category' => $g['category'] ?? 'otros', 'note' => $g['note'] ?? ''];
            $map[$key]['quantity'] += (float)($g['quantity'] ?? 0);
        }
    }
    $groups = [];
    foreach ($map as $it) {
        $c = in_array($it['category'], $order, true) ? $it['category'] : 'otros';
        $groups[$c][] = $it;
    }
    uksort($groups, fn($a, $b) => array_search($a, $order) <=> array_search($b, $order));
    return ['groups' => $groups, 'names' => $names, 'total' => count($map)];
}

// --- Páginas ---

function needLogin($twig, $config, $me): array {
    if ($me) return [];
    echo $twig->render('login.twig', ['config' => $config, 'chrome' => false]);
    exit;
}

function goalLabel(?array $profile): string {
    return match ($profile['goal'] ?? 'perder_peso') {
        'mantener' => 'Mantener peso',
        'ganar_musculo' => 'Ganar músculo',
        default => 'Perder peso',
    };
}

function mistralView(array $ms): array {
    return ['configured' => $ms['configured'], 'model' => $ms['model'],
        'masked' => Mistral::maskKey($ms['apiKey'] ?? ''), 'models' => Mistral::MODELS];
}

