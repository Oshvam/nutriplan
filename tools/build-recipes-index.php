<?php
// Genera data/recipes-index.json (catálogo ligero) a partir de data/recipes.json.
// Uso: php tools/build-recipes-index.php
$root = dirname(__DIR__);
$src = $root . '/data/recipes.json';
$all = json_decode((string) file_get_contents($src), true);
if (!is_array($all)) {
    fwrite(STDERR, "recipes.json ilegible\n");
    exit(1);
}
// Campos que necesitan plan, catálogo y recomendaciones. Sin ingredientes ni pasos,
// pero con un resumen de ingredientes (texto de nombres y categorías) para buscar,
// filtrar dietas y puntuar recomendaciones sin cargar la receta completa.
$keep = ['id','title','subtitle','mealTypes','prepTimeMinutes','prepCategory','calorieCategory',
         'calories','protein','carbs','fat','fiber','imageEmoji','tags','allergens','suitableForGoals'];
$out = [];
foreach ($all as $r) {
    $row = [];
    foreach ($keep as $k) {
        if (array_key_exists($k, $r)) $row[$k] = $r[$k];
    }
    $names = [];
    $cats = [];
    foreach (($r['ingredients'] ?? []) as $g) {
        $n = mb_strtolower(trim((string)($g['name'] ?? '')));
        if ($n !== '') $names[] = $n;
        $c = (string)($g['category'] ?? '');
        if ($c !== '') $cats[$c] = true;
    }
    $row['ing'] = implode(' ', array_values(array_unique($names)));
    $row['cats'] = array_keys($cats);
    $out[] = $row;
}
$json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($root . '/data/recipes-index.json', $json);
printf("INDEX_OK recetas=%d bytes=%d\n", count($out), strlen($json));
