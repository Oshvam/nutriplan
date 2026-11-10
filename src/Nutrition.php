<?php
namespace NutriPlan;

// Cálculos clínicos. Traducción directa de src/utils/nutritionCalculator.ts
// (Mifflin-St Jeor / Schofield, PAL, SEEDO). Los perfiles son arrays con las
// mismas claves que el JSON de la app original.
class Nutrition {
    public static function bmiClassification(float $bmi): array {
        if ($bmi < 18.5) {
            return ['label' => 'Bajo Peso / Peso Insuficiente', 'description' => 'IMC por debajo de 18.5 kg/m². Riesgo de depleción nutricional y pérdida de masa magra.', 'color' => 'text-amber-600 dark:text-amber-400'];
        } elseif ($bmi <= 24.9) {
            return ['label' => 'Normopeso (Peso Saludable)', 'description' => 'IMC 18.5 - 24.9 kg/m². Intervalo óptimo de equilibrio metabólico y bienestar.', 'color' => 'text-emerald-600 dark:text-emerald-400'];
        } elseif ($bmi <= 26.9) {
            return ['label' => 'Sobrepeso Grado I', 'description' => 'IMC 25.0 - 26.9 kg/m². Ligero incremento ponderal susceptible de corrección dietética.', 'color' => 'text-amber-500 dark:text-amber-400'];
        } elseif ($bmi <= 29.9) {
            return ['label' => 'Sobrepeso Grado II (Preobesidad)', 'description' => 'IMC 27.0 - 29.9 kg/m². Recomendado plan dietoterapéutico hipocalórico con actividad física.', 'color' => 'text-orange-600 dark:text-orange-400'];
        } elseif ($bmi <= 34.9) {
            return ['label' => 'Obesidad Tipo I', 'description' => 'IMC 30.0 - 34.9 kg/m². Requiere intervención nutricional estructurada y control de comorbilidades.', 'color' => 'text-rose-600 dark:text-rose-400'];
        } elseif ($bmi <= 39.9) {
            return ['label' => 'Obesidad Tipo II', 'description' => 'IMC 35.0 - 39.9 kg/m². Seguimiento clínico prioritario con objetivo de reducción del 8-10% del peso.', 'color' => 'text-rose-700 dark:text-rose-400'];
        } elseif ($bmi <= 49.9) {
            return ['label' => 'Obesidad Tipo III (Mórbida)', 'description' => 'IMC 40.0 - 49.9 kg/m². Tratamiento multidisciplinar intensivo.', 'color' => 'text-red-700 dark:text-red-400'];
        }
        return ['label' => 'Obesidad Tipo IV (Extrema)', 'description' => 'IMC ≥ 50.0 kg/m². Evaluación médica especializada.', 'color' => 'text-red-800 dark:text-red-400'];
    }

    public static function calculateNeeds(array $p): array {
        $heightM = $p['height'] / 100;
        $bmi = round($p['currentWeight'] / ($heightM * $heightM), 1);

        if (!empty($p['isChildOrTeen']) || $p['age'] < 18) {
            $bmr = $p['gender'] === 'hombre' ? 17.5 * $p['currentWeight'] + 651 : 12.2 * $p['currentWeight'] + 746;
        } else {
            $bmr = $p['gender'] === 'hombre'
                ? 10 * $p['currentWeight'] + 6.25 * $p['height'] - 5 * $p['age'] + 5
                : 10 * $p['currentWeight'] + 6.25 * $p['height'] - 5 * $p['age'] - 161;
        }

        $pal = ['sedentario' => 1.2, 'ligero' => 1.375, 'moderado' => 1.55, 'intenso' => 1.725, 'atleta' => 1.9][$p['activityLevel']] ?? 1.4;
        $tdee = (int)round($bmr * $pal);

        $targetCalories = $tdee;
        $deficitOrSurplus = 0;
        $weeklyExpectedChangeKg = 0.0;

        if ($p['goal'] === 'perder_peso') {
            $weeks = max(4, (int)($p['targetWeeks'] ?? 12));
            $rawDaily = (max(0, $p['currentWeight'] - $p['targetWeight']) / $weeks) * 7700 / 7;
            $deficitOrSurplus = -min(600, max(350, (int)round($rawDaily)));
            $targetCalories = max($p['gender'] === 'hombre' ? 1500 : 1250, $tdee + $deficitOrSurplus);
            $weeklyExpectedChangeKg = round($deficitOrSurplus * 7 / 7700, 2);
        } elseif ($p['goal'] === 'ganar_peso') {
            $weeks = max(4, (int)($p['targetWeeks'] ?? 12));
            $rawDaily = (max(0, $p['targetWeight'] - $p['currentWeight']) / $weeks) * 7000 / 7;
            $deficitOrSurplus = min(650, max(350, (int)round($rawDaily)));
            $targetCalories = $tdee + $deficitOrSurplus;
            $weeklyExpectedChangeKg = round($deficitOrSurplus * 7 / 7000, 2);
        }

        $proteinPerKg = $p['goal'] === 'perder_peso' ? 1.8 : ($p['goal'] === 'ganar_peso' ? 2.0 : 1.6);
        $proteinGrams = (int)round($p['currentWeight'] * $proteinPerKg);
        $proteinCalories = $proteinGrams * 4;
        $fatsCalories = (int)round($targetCalories * 0.28);
        $fatsGrams = (int)round($fatsCalories / 9);
        $carbsCalories = max(0, $targetCalories - ($proteinCalories + $fatsCalories));
        $carbsGrams = (int)round($carbsCalories / 4);

        $totalCal = $proteinGrams * 4 + $carbsGrams * 4 + $fatsGrams * 9;

        $diff = abs($p['currentWeight'] - $p['targetWeight']);
        $rate = abs($weeklyExpectedChangeKg) ?: 0.5;

        return [
            'bmr' => (int)round($bmr),
            'tdee' => $tdee,
            'targetCalories' => $targetCalories,
            'deficitOrSurplus' => $deficitOrSurplus,
            'macros' => [
                'proteinGrams' => $proteinGrams, 'carbsGrams' => $carbsGrams, 'fatsGrams' => $fatsGrams,
                'proteinCalories' => $proteinCalories, 'carbsCalories' => $carbsCalories, 'fatsCalories' => $fatsCalories,
                'proteinPct' => (int)round($proteinGrams * 4 / $totalCal * 100),
                'carbsPct' => (int)round($carbsGrams * 4 / $totalCal * 100),
                'fatsPct' => (int)round($fatsGrams * 9 / $totalCal * 100),
            ],
            'bmi' => $bmi,
            'bmiClassification' => self::bmiClassification($bmi),
            'weeklyExpectedChangeKg' => $weeklyExpectedChangeKg,
            'estimatedWeeksToGoal' => $diff > 0 ? (int)ceil($diff / $rate) : 0,
        ];
    }

    public static function greeting(?string $userName = null, ?int $hour = null): array {
        $hour ??= (int)date('G');
        $sfx = $userName ? ", $userName" : '';
        if ($hour >= 6 && $hour < 10) {
            return ['saludo' => "¡Buenos días$sfx!", 'momento' => 'mañana', 'proximaComida' => 'desayuno', 'proximaComidaNombre' => 'Desayuno', 'horarioRecomendado' => '07:30 - 09:30', 'consejoNutricional' => 'Inicia el día con hidratos de absorción lenta (como avena o pan integral) y un lácteo o infusión para estabilizar la glucosa.'];
        } elseif ($hour >= 10 && $hour < 13) {
            return ['saludo' => "¡Hola$sfx! Media mañana", 'momento' => 'media mañana', 'proximaComida' => 'tentempie', 'proximaComidaNombre' => 'Tentempié de Media Mañana', 'horarioRecomendado' => '10:30 - 12:00', 'consejoNutricional' => 'Tomar un tentempié evita la hipoglucemia reactiva y frena los picos de cortisol antes de la comida principal.'];
        } elseif ($hour >= 13 && $hour < 17) {
            return ['saludo' => "¡Buenas tardes$sfx! Hora de la comida", 'momento' => 'tarde', 'proximaComida' => 'comida', 'proximaComidaNombre' => 'Comida Principal', 'horarioRecomendado' => '14:00 - 16:00', 'consejoNutricional' => 'Asegura tu aporte de verduras y hortalizas ricas en fibra junto con una fuente magra de proteína (pollo, legumbres, pescado o ternera).'];
        } elseif ($hour >= 17 && $hour < 20) {
            return ['saludo' => "¡Buenas tardes$sfx! Momento de merendar", 'momento' => 'merienda', 'proximaComida' => 'merienda', 'proximaComidaNombre' => 'Merienda', 'horarioRecomendado' => '17:30 - 19:30', 'consejoNutricional' => 'Una merienda equilibrada (fruta de temporada, frutos secos o batido de avena) te mantendrá con energía hasta la cena.'];
        } elseif ($hour >= 20 && $hour < 23) {
            return ['saludo' => "¡Buenas noches$sfx! Hora de cenar", 'momento' => 'noche', 'proximaComida' => 'cena', 'proximaComidaNombre' => 'Cena', 'horarioRecomendado' => '21:00 - 21:30', 'consejoNutricional' => 'Cena al menos 2 horas antes de dormir para favorecer una digestión ligera y un descanso reparador.'];
        }
        return ['saludo' => "¡Buenas noches$sfx!", 'momento' => 'recena / descanso', 'proximaComida' => 'recena', 'proximaComidaNombre' => 'Recena', 'horarioRecomendado' => '23:00 - 01:00', 'consejoNutricional' => 'Un yogur natural o un lácteo ligero antes de acostarse previene el catabolismo nocturno y aporta saciedad.'];
    }

    public static function mealMeta(): array {
        return [
            'desayuno' => ['hora' => '08:30', 'label' => 'Desayuno'],
            'tentempie' => ['hora' => '11:00', 'label' => 'Tentempié (Media mañana)'],
            'comida' => ['hora' => '14:30', 'label' => 'Comida'],
            'merienda' => ['hora' => '17:30', 'label' => 'Merienda'],
            'cena' => ['hora' => '21:00', 'label' => 'Cena'],
            'recena' => ['hora' => '23:30', 'label' => 'Recena'],
        ];
    }

    /**
     * Plan semanal de 7 días rotando recetas seguras (sin alérgenos, según
     * preferencia y objetivo). $allRecipes: lista con mealTypes[], allergens[],
     * suitableForGoals[], tags[], id.
     */
    public static function generateWeeklyPlan(array $profile, array $allRecipes, int $offset = 0): array {
        $days = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
        $safe = array_values(array_filter($allRecipes, function ($r) use ($profile) {
            if (!empty($profile['allergies']) && array_intersect($r['allergens'] ?? [], $profile['allergies'])) return false;
            if (($profile['dietaryPreference'] ?? '') === 'vegetariano' && in_array('carne', $r['tags'] ?? [], true)) return false;
            return true;
        }));

        $forMeal = function (string $meal) use ($safe, $allRecipes, $profile): array {
            $m = array_values(array_filter($safe, fn($r) => in_array($meal, $r['mealTypes'] ?? [], true)
                && in_array($profile['goal'], $r['suitableForGoals'] ?? [], true)));
            if (!$m) $m = array_values(array_filter($safe, fn($r) => in_array($meal, $r['mealTypes'] ?? [], true)));
            if (!$m) $m = array_values(array_filter($allRecipes, fn($r) => in_array($meal, $r['mealTypes'] ?? [], true)));
            return $m;
        };
        $pools = [];
        foreach (array_keys(self::mealMeta()) as $meal) $pools[$meal] = $forMeal($meal);
        $meta = self::mealMeta();

        $plan = [];
        foreach ($days as $i => $dayName) {
            $meals = [];
            $pick = function (string $meal) use ($pools, $meta, $i, $offset) {
                if (empty($pools[$meal])) return null;
                $r = $pools[$meal][($i + $offset) % count($pools[$meal])];
                return ['mealType' => $meal, 'label' => $meta[$meal]['label'], 'timeSlot' => $meta[$meal]['hora'], 'recipeId' => $r['id']];
            };
            foreach (['desayuno', 'tentempie', 'comida'] as $meal) {
                if ($m = $pick($meal)) $meals[] = $m;
            }
            if (($profile['mealsPerDay'] ?? 5) >= 5 && ($m = $pick('merienda'))) $meals[] = $m;
            if ($m = $pick('cena')) $meals[] = $m;
            if ((($profile['mealsPerDay'] ?? 5) === 6 || ($profile['goal'] ?? '') === 'ganar_peso') && ($m = $pick('recena'))) $meals[] = $m;
            $plan[] = ['dayNumber' => $i + 1, 'dayName' => $dayName, 'meals' => $meals];
        }
        return $plan;
    }
}

// ponytail: autotest mínimo sin framework; falla si la clínica cambia
if (PHP_SAPI === 'cli' && realpath($_SERVER['argv'][0] ?? '') === __FILE__) {
    $fail = 0;
    $ok = function (bool $cond, string $name) use (&$fail) {
        if (!$cond) { $fail++; echo "FAIL: $name\n"; }
    };
    // Caso dorado: hombre 80kg/180cm/30a, sedentario, mantener
    $n = Nutrition::calculateNeeds([
        'currentWeight' => 80, 'targetWeight' => 80, 'height' => 180, 'age' => 30,
        'gender' => 'hombre', 'activityLevel' => 'sedentario', 'goal' => 'mantener_peso',
        'isChildOrTeen' => false, 'targetWeeks' => 12,
    ]);
    $ok($n['bmr'] === 1780, 'bmr');
    $ok($n['tdee'] === 2136, 'tdee');
    $ok($n['targetCalories'] === 2136, 'target');
    $ok($n['macros']['proteinGrams'] === 128 && $n['macros']['carbsGrams'] === 257 && $n['macros']['fatsGrams'] === 66, 'macros');
    $ok($n['macros']['proteinPct'] === 24 && $n['macros']['carbsPct'] === 48 && $n['macros']['fatsPct'] === 28, 'pct');
    $ok($n['bmi'] === 24.7 && str_contains($n['bmiClassification']['label'], 'Normopeso'), 'bmi');
    // Umbrales SEEDO
    foreach ([[18.4, 'Bajo Peso'], [18.5, 'Normopeso'], [25.0, 'Grado I'], [27.0, 'Grado II'], [30.0, 'Tipo I'], [35.0, 'Tipo II'], [40.0, 'Tipo III'], [50.0, 'Tipo IV']] as [$bmi, $label]) {
        $ok(str_contains(Nutrition::bmiClassification($bmi)['label'], $label), "seedo $bmi");
    }
    // Déficit acotado [350,600] y suelo 1500/1250
    $n2 = Nutrition::calculateNeeds([
        'currentWeight' => 90, 'targetWeight' => 70, 'height' => 170, 'age' => 40,
        'gender' => 'mujer', 'activityLevel' => 'moderado', 'goal' => 'perder_peso',
        'isChildOrTeen' => false, 'targetWeeks' => 12,
    ]);
    $ok($n2['deficitOrSurplus'] <= -350 && $n2['deficitOrSurplus'] >= -600, 'deficit bounds');
    $ok($n2['targetCalories'] >= 1250, 'suelo mujer');
    $ok(Nutrition::greeting('Ana', 8)['proximaComida'] === 'desayuno', 'greeting');
    $ok(Nutrition::greeting(null, 22)['proximaComida'] === 'cena', 'greeting noche');
    echo $fail === 0 ? "NUTRITION_OK\n" : "$fail FALLOS\n";
    exit($fail === 0 ? 0 : 1);
}
