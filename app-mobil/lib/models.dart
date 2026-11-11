/// Modelos del backend PHP + réplicas de su lógica de cálculo.
library;

Map<String, dynamic> _map(dynamic v) =>
    (v as Map? ?? {}).cast<String, dynamic>();

List<dynamic> _list(dynamic v) => v is List ? v : [];

double _d(dynamic v, [double f = 0]) {
  if (v is num) return v.toDouble();
  return double.tryParse('$v') ?? f;
}

int _i(dynamic v, [int f = 0]) {
  if (v is num) return v.toInt();
  return int.tryParse('$v') ?? f;
}

// ---------- Recetas ----------

class Ingredient {
  Ingredient.fromMap(Map<String, dynamic> m)
      : name = '${m['name'] ?? ''}',
        quantity = _d(m['quantity']),
        unit = '${m['unit'] ?? ''}',
        note = m['note'] == null ? null : '${m['note']}';

  final String name;
  final double quantity;
  final String unit;
  final String? note;
}

class Recipe {
  Recipe.fromMap(Map<String, dynamic> m)
      : id = '${m['id'] ?? ''}',
        title = '${m['title'] ?? ''}',
        subtitle = '${m['subtitle'] ?? ''}',
        mealTypes =
            _list(m['mealTypes']).map((e) => '$e').toList(growable: false),
        prepTimeMinutes = _i(m['prepTimeMinutes']),
        prepCategory = '${m['prepCategory'] ?? ''}',
        calorieCategory = '${m['calorieCategory'] ?? ''}',
        calories = _i(m['calories']),
        protein = _d(m['protein']),
        carbs = _d(m['carbs']),
        fat = _d(m['fat']),
        fiber = _d(m['fiber']),
        imageEmoji = '${m['imageEmoji'] ?? '🍽️'}',
        tags = _list(m['tags']).map((e) => '$e').toList(growable: false),
        allergens =
            _list(m['allergens']).map((e) => '$e').toList(growable: false),
        ingredients = _list(m['ingredients'])
            .map((e) => Ingredient.fromMap(_map(e)))
            .toList(growable: false),
        instructions = _list(m['instructions']).map((e) => '$e').toList();

  final String id;
  final String title;
  final String subtitle;
  final List<String> mealTypes;
  final int prepTimeMinutes;
  final String prepCategory;
  final String calorieCategory;
  final int calories;
  final double protein;
  final double carbs;
  final double fat;
  final double fiber;
  final String imageEmoji;
  final List<String> tags;
  final List<String> allergens;
  final List<Ingredient> ingredients;
  final List<String> instructions;
}

// ---------- Plan semanal ----------

class PlanMeal {
  PlanMeal.fromMap(Map<String, dynamic> m)
      : mealType = '${m['mealType'] ?? ''}',
        label = '${m['label'] ?? ''}',
        timeSlot = '${m['timeSlot'] ?? ''}',
        recipeId = '${m['recipeId'] ?? ''}',
        slotId = '${m['slotId'] ?? ''}',
        completed = m['completed'] == true,
        recipe = Recipe.fromMap(_map(m['recipe']));

  final String mealType;
  final String label;
  final String timeSlot;
  final String recipeId;
  final String slotId;
  final bool completed;
  final Recipe recipe;
}

class PlanDay {
  PlanDay.fromMap(Map<String, dynamic> m)
      : dayNumber = _i(m['dayNumber'], 1),
        dayName = '${m['dayName'] ?? ''}',
        meals = _list(m['meals'])
            .map((e) => PlanMeal.fromMap(_map(e)))
            .toList(growable: false);

  final int dayNumber; // 1 = lunes … 7 = domingo
  final String dayName;
  final List<PlanMeal> meals;
}

// ---------- Hoy / recomendaciones ----------

class Rec {
  Rec.fromMap(Map<String, dynamic> m)
      : recipe = Recipe.fromMap(_map(m['recipe'])),
        score = _i(m['score']);

  final Recipe recipe;
  final int score;
}

// ---------- Compra ----------

class ShopItem {
  ShopItem.fromMap(Map<String, dynamic> m)
      : name = '${m['name'] ?? ''}',
        quantity = _d(m['quantity']),
        unit = '${m['unit'] ?? ''}',
        category = '${m['category'] ?? 'otros'}',
        note = m['note'] == null ? null : '${m['note']}';

  final String name;
  final double quantity;
  final String unit;
  final String category;
  final String? note;

  /// Cantidad legible: 500 g / 1.5 kg adaptado como en la web.
  String displayQty() {
    if (unit == 'g' && quantity >= 1000) {
      final kg = quantity / 1000;
      return '${kg == kg.roundToDouble() ? kg.toInt() : (kg * 10).round() / 10} kg';
    }
    if (unit == 'ml' && quantity >= 1000) {
      return '${(quantity / 1000 * 10).round() / 10} l';
    }
    final q = quantity == quantity.roundToDouble()
        ? '${quantity.toInt()}'
        : '$quantity';
    return unit.isEmpty ? q : '$q $unit';
  }
}

class ShopData {
  ShopData.fromMap(Map<String, dynamic> m)
      : groups = {
          for (final e in _map(m['groups']).entries)
            e.key: _list(e.value)
                .map((i) => ShopItem.fromMap(_map(i)))
                .toList(growable: false),
        },
        names = _map(m['names']).map((k, v) => MapEntry(k, '$v')),
        total = _i(m['total']);

  final Map<String, List<ShopItem>> groups;
  final Map<String, String> names;
  final int total;
}

// ---------- Peso ----------

class WeightPoint {
  WeightPoint.fromMap(Map<String, dynamic> m)
      : date = '${m['date'] ?? ''}',
        weight = _d(m['weight']),
        note = m['note'] == null ? null : '${m['note']}';

  final String date; // YYYY-MM-DD
  final double weight;
  final String? note;
}

// ---------- Lógica portada del PHP ----------

/// Objetivo de kcal por comida (Nutrition::mealCalorieTarget).
int mealCalorieTarget(String mealType, int daily) {
  switch (mealType) {
    case 'comida':
      return (daily * .35).round();
    case 'cena':
      return (daily * .28).round();
    case 'desayuno':
      return (daily * .22).round();
    case 'merienda':
      return (daily * .12).round();
    default:
      return (daily * .08).round();
  }
}

/// Totales del día {cal, p, c, f} (Nutrition::dayTotals).
Map<String, double> dayTotals(List<Recipe> recipes) {
  var cal = 0.0, p = 0.0, c = 0.0, f = 0.0;
  for (final r in recipes) {
    cal += r.calories;
    p += r.protein;
    c += r.carbs;
    f += r.fat;
  }
  return {'cal': cal, 'p': p, 'c': c, 'f': f};
}

double _round1(double v) => (v * 10).round() / 10;

/// Estadísticas de peso (réplica de Weight::stats, quirk del pct incluido).
Map<String, dynamic> weightStats(
    List<WeightPoint> history, double heightCm, double targetWeight) {
  if (history.isEmpty) return {};
  final sorted = [...history]..sort((a, b) => a.date.compareTo(b.date));
  final first = sorted.first.weight;
  final last = sorted.last.weight;
  final bmi = _round1(last / ((heightCm / 100) * (heightCm / 100)));
  final change = _round1(last - first);
  final denom = first - targetWeight;
  final pct = denom == 0
      ? 100
      : (change / denom * 100).round().clamp(0, 100);
  return {
    'initial': first,
    'current': last,
    'bmi': bmi,
    'target': targetWeight,
    'toGo': _round1(last - targetWeight),
    'change': change,
    'pct': pct,
    'count': sorted.length,
  };
}

/// Saludo simple por hora (versión reducida del de la web).
String greeting(DateTime now) {
  final h = now.hour;
  if (h >= 6 && h < 12) return 'Buenos días';
  if (h >= 12 && h < 21) return 'Buenas tardes';
  return 'Buenas noches';
}

const mealLabels = {
  'desayuno': 'Desayuno',
  'tentempie': 'Tentempié',
  'comida': 'Comida',
  'merienda': 'Merienda',
  'cena': 'Cena',
  'recena': 'Recena',
};

const dayNames = [
  '',
  'Lunes',
  'Martes',
  'Miércoles',
  'Jueves',
  'Viernes',
  'Sábado',
  'Domingo'
];
